<?php

declare(strict_types=1);

use HScript\Backup\BackupManifest;
use HScript\Backup\BackupRepository;
use HScript\Backup\BackupSettings;
use HScript\Backup\BackupStreamWriter;
use HScript\Backup\BackupVerifier;
use HScript\Backup\DatabaseCredentials;
use HScript\Update\ConfiguratorCsrf;

require dirname(__DIR__) . '/vendor/autoload.php';

function backupAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

function backupRejects(callable $callback, string $message): void
{
	set_error_handler(static fn(): bool => true);
	try
	{
		$callback();
	}
	catch (Throwable)
	{
		return;
	}
	finally { restore_error_handler(); }
	throw new RuntimeException($message);
}

function backupRemoveTree(string $directory): void
{
	if (!is_dir($directory))
		return;
	foreach ((array)scandir($directory) as $item)
	{
		if ($item === '.' || $item === '..')
			continue;
		$path = $directory . '/' . $item;
		if (is_dir($path) && !is_link($path)) backupRemoveTree($path); else unlink($path);
	}
	rmdir($directory);
}

$temporaryDirectory = sys_get_temp_dir() . '/hscript-backup-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700))
	throw new RuntimeException('Backup test directory could not be created');

try
{
	$credentials = new DatabaseCredentials('database:3307', 'hscript_test', 'tester', 'secret');
	backupAssert($credentials->host() === 'database' && $credentials->port() === 3307, 'Database host parsing failed');
	backupAssert(!str_contains(json_encode($credentials), 'secret'), 'Database password was serialized');

	$archiveResults = array();
	foreach (array('plain', 'gzip') as $compression)
	{
		$id = $compression === 'plain' ? str_repeat('a', 32) : str_repeat('b', 32);
		$path = $temporaryDirectory . '/archive-' . $compression;
		$writer = new BackupStreamWriter($path, $compression, 1048576);
		$writer->write("CREATE TABLE `Cfg` (`id` int);\n");
		$writer->write("CREATE TABLE `Users` (`id` int);\n");
		$writer->write('-- H-Script backup complete:' . $id . "\n");
		$result = $writer->close();
		$archiveResults[$compression] = $result;
		$verification = (new BackupVerifier())->verify(
			$path,
			$compression,
			$result['sha256'],
			$result['uncompressed_size'],
			array('Cfg', 'Users'),
			$id
		);
		backupAssert($verification['footer_present'], 'Backup footer was not verified');
		backupAssert((fileperms($path) & 0777) === 0600, 'Backup is readable by group/other users');
		$bytes = file_get_contents($path);
		backupRejects(static fn() => new BackupStreamWriter($path, $compression, 1048576), 'Existing backup was overwritten');
		backupAssert(file_get_contents($path) === $bytes, 'Rejected overwrite changed backup bytes');
		$link = $path . '-link';
		symlink($path, $link);
		backupRejects(static fn() => new BackupStreamWriter($link, $compression, 1048576), 'Backup writer followed a symlink');
		backupAssert(file_get_contents($path) === $bytes, 'Symlink target was modified');
		unlink($link);
	}

	$plainPath = $temporaryDirectory . '/archive-plain';
	backupRejects(
		static fn() => (new BackupVerifier())->verify(
			$plainPath,
			'plain',
			str_repeat('0', 64),
			filesize($plainPath),
			array('Cfg', 'Users'),
			str_repeat('a', 32)
		),
		'Backup checksum mismatch was accepted'
	);
	$gzipPath = $temporaryDirectory . '/archive-gzip';
	$corruptGzipPath = $temporaryDirectory . '/corrupt.gz';
	$gzipData = (string)file_get_contents($gzipPath);
	file_put_contents($corruptGzipPath, substr($gzipData, 0, max(1, strlen($gzipData) - 8)));
	backupRejects(
		static fn() => (new BackupVerifier())->verify(
			$corruptGzipPath,
			'gzip',
			hash_file('sha256', $corruptGzipPath),
			$archiveResults['gzip']['uncompressed_size'],
			array('Cfg', 'Users'),
			str_repeat('b', 32)
		),
		'Corrupted gzip backup was accepted'
	);

	$incompletePath = $temporaryDirectory . '/incomplete.sql';
	file_put_contents($incompletePath, "CREATE TABLE `Cfg` (`id` int);\nCREATE TABLE `Users` (`id` int);\n");
	backupRejects(
		static fn() => (new BackupVerifier())->verify(
			$incompletePath,
			'plain',
			hash_file('sha256', $incompletePath),
			filesize($incompletePath),
			array('Cfg', 'Users'),
			str_repeat('c', 32)
		),
		'Incomplete backup was accepted'
	);

	$limitedPath = $temporaryDirectory . '/limited.sql';
	$limitedWriter = new BackupStreamWriter($limitedPath, 'plain', 8);
	backupRejects(static fn() => $limitedWriter->write(str_repeat('x', 9)), 'Backup size limit was ignored');
	unset($limitedWriter);

	$manifestData = array(
		'format' => 1,
		'id' => str_repeat('d', 32),
		'created_at' => '2026-09-07T00:00:00Z',
		'verified_at' => '2026-09-07T00:00:01Z',
		'database_sha256' => str_repeat('1', 64),
		'application_version' => '1.0.2',
		'schema_version' => '1.0.0',
		'archive' => 'h-script-20260907-000000-' . str_repeat('d', 32) . '.sql.gz',
		'compression' => 'gzip',
		'adapter' => 'mysqldump',
		'location' => 'external',
		'uncompressed_size' => 100,
		'stored_size' => 50,
		'sha256' => str_repeat('2', 64),
		'table_count' => 2,
		'tables' => array('Cfg', 'Users'),
		'status' => 'verified',
		'verification' => array(
			'footer_present' => true,
			'archive_readable' => true,
			'table_count_match' => true,
			'checksum_match' => true,
			'core_tables_present' => true,
		),
	);
	backupAssert(BackupManifest::fromArray($manifestData)->id() === str_repeat('d', 32), 'Valid backup manifest was rejected');
	$manifestData['archive'] = '../backup.sql.gz';
	backupRejects(static fn() => BackupManifest::fromArray($manifestData), 'Backup manifest path traversal was accepted');

	$repositoryDirectory = $temporaryDirectory . '/repository';
	mkdir($repositoryDirectory, 0700);
	$repository = new BackupRepository(new BackupSettings($repositoryDirectory, 'external'));
	$repositoryId = str_repeat('e', 32);
	$repositoryArchive = 'h-script-20260907-000000-' . $repositoryId . '.sql';
	$repositoryTemporary = $repositoryDirectory . '/.' . $repositoryId . '.part';
	$repositoryWriter = new BackupStreamWriter($repositoryTemporary, 'plain', 1048576);
	$repositoryWriter->write("CREATE TABLE `Cfg` (`id` int);\nCREATE TABLE `Users` (`id` int);\n-- H-Script backup complete:" . $repositoryId . "\n");
	$repositorySizes = $repositoryWriter->close();
	$repositoryManifest = BackupManifest::fromArray(array(
		'format' => 1,
		'id' => $repositoryId,
		'created_at' => '2026-09-07T00:00:00Z',
		'verified_at' => '2026-09-07T00:00:01Z',
		'database_sha256' => str_repeat('1', 64),
		'application_version' => '1.0.2',
		'schema_version' => '1.0.0',
		'archive' => $repositoryArchive,
		'compression' => 'plain',
		'adapter' => 'php-stream',
		'location' => 'external',
		'uncompressed_size' => $repositorySizes['uncompressed_size'],
		'stored_size' => $repositorySizes['stored_size'],
		'sha256' => $repositorySizes['sha256'],
		'table_count' => 2,
		'tables' => array('Cfg', 'Users'),
		'status' => 'verified',
		'verification' => array(
			'archive_readable' => true,
			'checksum_match' => true,
			'core_tables_present' => true,
			'footer_present' => true,
			'table_count_match' => true,
		),
	));
	$repository->publish($repositoryManifest, $repositoryTemporary);
	backupAssert($repository->find($repositoryId)->id() === $repositoryId, 'Published backup could not be found');
	backupAssert((fileperms($repositoryDirectory . '/' . $repositoryArchive) & 0777) === 0600, 'Published backup permissions are too broad');
	$publishedHash = hash_file('sha256', $repositoryDirectory . '/' . $repositoryArchive);
	backupRejects(static fn() => $repository->publish($repositoryManifest, $repositoryTemporary), 'Existing manifest was overwritten');
	backupAssert(hash_file('sha256', $repositoryDirectory . '/' . $repositoryArchive) === $publishedHash, 'Rejected publish changed archive');
	$wrongId = str_repeat('9', 32);
	copy($repositoryDirectory . '/' . $repositoryId . '.manifest.json', $repositoryDirectory . '/' . $wrongId . '.manifest.json');
	backupRejects(static fn() => $repository->find($wrongId), 'Manifest ID/filename mismatch accepted');
	unlink($repositoryDirectory . '/' . $wrongId . '.manifest.json');
	$manifestLink = $repositoryDirectory . '/manifest-link';
	symlink($repositoryDirectory . '/' . $repositoryId . '.manifest.json', $manifestLink);
	backupRejects(static fn() => BackupManifest::fromFile($manifestLink), 'Symlink manifest accepted');
	unlink($manifestLink);
	backupAssert(dirname($repository->archivePath($repositoryManifest)) === $repositoryDirectory, 'Backup escaped its storage directory');
	backupRejects(static fn() => $repository->find('../outside'), 'Repository accepted path traversal as a backup ID');

	$retainedId = str_repeat('f', 32);
	$retainedArchive = 'h-script-20260907-000001-' . $retainedId . '.sql';
	$retainedTemporary = $repositoryDirectory . '/.' . $retainedId . '.part';
	$retainedWriter = new BackupStreamWriter($retainedTemporary, 'plain', 1048576);
	$retainedWriter->write("CREATE TABLE `Cfg` (`id` int);\nCREATE TABLE `Users` (`id` int);\n-- H-Script backup complete:" . $retainedId . "\n");
	$retainedSizes = $retainedWriter->close();
	$retainedData = $repositoryManifest->toArray();
	$retainedData['id'] = $retainedId;
	$retainedData['archive'] = $retainedArchive;
	$retainedData['created_at'] = '2026-09-07T00:00:01Z';
	$retainedData['verified_at'] = '2026-09-07T00:00:02Z';
	$retainedData['uncompressed_size'] = $retainedSizes['uncompressed_size'];
	$retainedData['stored_size'] = $retainedSizes['stored_size'];
	$retainedData['sha256'] = $retainedSizes['sha256'];
	$retainedManifest = BackupManifest::fromArray($retainedData);
	$retentionRepository = new BackupRepository(new BackupSettings(
		$repositoryDirectory, 'external', 2147483648, 134217728, 1, 3650
	));
	$retentionRepository->publish($retainedManifest, $retainedTemporary);
	backupAssert($retentionRepository->prune(true, $retainedId) === array(), 'Active-update retention deleted a backup');
	backupAssert(count($retentionRepository->list()) === 2, 'Active-update retention changed the backup list');
	backupAssert($retentionRepository->prune(false, $retainedId) === array($repositoryId), 'Retention did not remove the older backup');
	backupAssert(count($retentionRepository->list()) === 1, 'Retention count limit was not enforced');

	unlink($repositoryDirectory . '/' . $retainedArchive);
	symlink($plainPath, $repositoryDirectory . '/' . $retainedArchive);
	backupRejects(static fn() => $repository->archivePath($retainedManifest), 'Repository accepted a symlinked archive');
	unlink($repositoryDirectory . '/' . $retainedArchive);
	unlink($repositoryDirectory . '/' . $retainedId . '.manifest.json');

	$projectDirectory = $temporaryDirectory . '/project';
	$protectedDirectory = $projectDirectory . '/protected-backups';
	mkdir($protectedDirectory, 0700, true);
	file_put_contents($protectedDirectory . '/.htaccess', "Require all denied\n");
	putenv('BACKUP_STORAGE_PATH=' . $protectedDirectory);
	backupAssert(
		BackupSettings::fromEnvironment($projectDirectory)->location() === 'document-root-protected',
		'Configured document-root storage was incorrectly marked external'
	);
	putenv('BACKUP_STORAGE_PATH');
	backupAssert((fileperms($protectedDirectory) & 0777) === 0700, 'Backup directory is accessible to other users');
	putenv('BACKUP_STORAGE_PATH=' . $projectDirectory);
	backupRejects(static fn() => BackupSettings::fromEnvironment($projectDirectory), 'Project root accepted as backup storage');
	putenv('BACKUP_STORAGE_PATH=' . $protectedDirectory);
	file_put_contents($protectedDirectory . '/.htaccess', "Require all granted\n");
	backupRejects(static fn() => BackupSettings::fromEnvironment($projectDirectory), 'Non-denying htaccess accepted');
	putenv('BACKUP_STORAGE_PATH');

	$privateJson = $temporaryDirectory . '/private.json';
	$protectedFile = $temporaryDirectory . '/unchanged.txt';
	file_put_contents($protectedFile, 'unchanged');
	symlink($protectedFile, $privateJson . '.part');
	\HScript\Backup\PrivateJsonFile::write($privateJson, array('safe' => true));
	backupAssert(file_get_contents($protectedFile) === 'unchanged', 'Metadata writer followed predictable temp link');
	backupAssert((fileperms($privateJson) & 0777) === 0600, 'Private metadata permissions are not 0600');
	\HScript\Backup\PrivateJsonFile::write($privateJson, array('safe' => false));
	backupAssert(json_decode(file_get_contents($privateJson), true) === array('safe' => false), 'Atomic metadata update failed');
	unlink($privateJson);
	symlink($protectedFile, $privateJson);
	backupRejects(static fn() => \HScript\Backup\PrivateJsonFile::write($privateJson, array()), 'Metadata writer accepted destination symlink');
	backupAssert(file_get_contents($protectedFile) === 'unchanged', 'Metadata destination target was modified');

	if (session_status() !== PHP_SESSION_ACTIVE)
		session_start();
	$token = ConfiguratorCsrf::token();
	backupRejects(static fn() => ConfiguratorCsrf::consume(str_repeat('0', 64)), 'Invalid CSRF token was accepted');
	ConfiguratorCsrf::consume($token);
	backupAssert(ConfiguratorCsrf::token() !== $token, 'Consumed CSRF token was reused');

	echo "Backup component tests passed.\n";
}
finally
{
	putenv('BACKUP_STORAGE_PATH');
	backupRemoveTree($temporaryDirectory);
}
