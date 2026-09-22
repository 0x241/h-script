<?php

namespace HScript\Security;

use HScript\Backup\BackupService;
use HScript\Backup\BackupSettings;
use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;
use HScript\Observability\HealthService;
use HScript\Recovery\RecoverySettings;
use HScript\Template\View;
use HScript\Update\RecoveryPreflight;
use HScript\Update\ReleaseManifest;
use HScript\Update\RuntimeEnvironment;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateClassification;
use HScript\Update\UpdateCompatibility;
use RuntimeException;

final class ProductionPreflight
{
	public function __construct(
		private string $projectRoot,
		private bool $https,
		private bool $docker,
		private string $language = 'en'
	) {
		$root = realpath($projectRoot);
		if ($root === false || !is_dir($root)) throw new RuntimeException('Project root could not be resolved');
		$this->projectRoot = rtrim($root, '/');
	}

	private function t(string $key): string
	{
		$translated = View::_t($key, array(), $this->language);
		return $translated === $key ? View::_t($key, array(), 'en') : $translated;
	}

	public function inspect(): array
	{
		$production = strtolower(trim((string)(getenv('APP_ENV') ?: ''))) === 'production';
		$checks = array();
		$this->add($checks, 'php', 'PHP', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION, array('backup', 'update'));
		foreach (array('json', 'hash', 'pdo', 'pdo_mysql') as $extension)
			$this->add($checks, 'ext_' . $extension, 'PHP: ' . $extension, extension_loaded($extension), extension_loaded($extension) ? $this->t('configurator.preflight.available') : $this->t('configurator.preflight.extension_missing'), array('backup', 'update'));

		$config = $this->projectRoot . '/_config.php';
		$this->add($checks, 'config_readable', '_config.php', is_file($config) && is_readable($config), $this->t('configurator.preflight.config_readable'), array('backup', 'update'), $config);
		$mode = is_file($config) ? (fileperms($config) & 0777) : 0;
		$configProtected = !$production || (($mode & 0002) === 0 && ($mode & 0004) === 0);
		$this->add($checks, 'config_permissions', $this->t('configurator.preflight.config_permissions'), $configProtected, $production ? sprintf($this->t('configurator.preflight.permissions_production'), $mode) : sprintf($this->t('configurator.preflight.permissions_local'), $mode), array(), $config, 'warning');

		$cfgPath = $this->projectRoot . '/.cfg';
		$backupPath = trim((string)(getenv('BACKUP_STORAGE_PATH') ?: '')) ?: $this->projectRoot . '/backup';
		$updatePath = trim((string)(getenv('UPDATE_WORK_PATH') ?: '')) ?: $cfgPath . '/update';
		$this->directoryCheck($checks, 'runtime', '.cfg runtime', $cfgPath, array('update'));
		$this->directoryCheck($checks, 'uploads', $this->t('configurator.preflight.uploads'), $this->projectRoot . '/upload', array(), 'warning');
		$this->directoryCheck($checks, 'backup', $this->t('configurator.preflight.backup'), $backupPath, array('backup', 'update'));
		$this->directoryCheck($checks, 'staging', $this->t('configurator.preflight.staging'), $updatePath, array('update'));
		if (!$this->docker)
			$this->add($checks, 'code_writable', $this->t('configurator.preflight.code'), is_writable($this->projectRoot), $this->t('configurator.preflight.code_writable'), array('update'), $this->projectRoot);
		else
			$this->add($checks, 'code_image', $this->t('configurator.preflight.code'), true, $this->t('configurator.preflight.code_image'), array(), $this->projectRoot);

		$pass = $this->projectRoot . '/module/_config/pass';
		$this->add($checks, 'configurator_password', $this->t('configurator.preflight.password'), is_file($pass) && trim((string)@file_get_contents($pass)) !== '', $this->t('configurator.preflight.password_required'), array('backup', 'update'), $pass);
		$autoInstall = in_array(strtolower(trim((string)(getenv('APP_AUTO_INSTALL') ?: '0'))), array('1', 'true', 'yes', 'on'), true);
		$this->add($checks, 'auto_install', 'APP_AUTO_INSTALL', !$production || !$autoInstall, $autoInstall ? $this->t('configurator.preflight.disable_auto_install') : $this->t('configurator.preflight.disabled'), $production ? array('backup', 'update') : array(), null, 'warning');
		$debug = in_array(strtolower(trim((string)(getenv('APP_DEBUG') ?: '0'))), array('1', 'true', 'yes', 'on'), true);
		$this->add($checks, 'debug', 'APP_DEBUG', !$production || !$debug, $debug ? $this->t('configurator.preflight.debug_enabled') : $this->t('configurator.preflight.disabled'), array(), null, 'warning');
		$this->add($checks, 'https', 'HTTPS', !$production || $this->https, $this->https ? $this->t('configurator.preflight.https_detected') : ($production ? $this->t('configurator.preflight.https_required') : $this->t('configurator.preflight.https_local')), $production ? array('backup', 'update') : array(), null, 'warning');
		$allowlist = trim((string)(getenv('CONFIGURATOR_ALLOWED_CIDRS') ?: ''));
		$this->add($checks, 'cidr', 'CIDR allowlist', $allowlist !== '', $allowlist !== '' ? $this->t('configurator.preflight.cidr_set') : $this->t('configurator.preflight.cidr_unset'), array(), null, 'warning');

		return array(
			'production' => $production,
			'checks' => $checks,
			'blockers' => array_values(array_filter($checks, static fn(array $check): bool => !$check['passed'] && $check['blocks'])),
			'warnings' => array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'warning')),
		);
	}

	public function forOperation(string $operation): array
	{
		return array_values(array_filter(
			$this->inspect()['checks'],
			static fn(array $check): bool => !$check['passed'] && in_array($operation, $check['blocks'], true)
		));
	}

	public function assertAllows(string $operation): void
	{
		$blocked = $this->forOperation($operation);
		if ($blocked)
			throw new RuntimeException('Production preflight failed: ' . implode(', ', array_column($blocked, 'label')));
	}

	/** Shared by CLI and Configurator immediately before an update can go live. */
	public function assertRelease(ReleaseManifest $manifest, Connection $database, array $config, string $domain): void
	{
		$this->assertAllows('update');
		UpdateCompatibility::fromArray($manifest->compatibility())->assertRuntime(RuntimeEnvironment::inspect($database));
		$queue = (new HealthService($this->projectRoot))->queueCheck($database);
		if ($queue['status'] !== 'ok' || $queue['processing'] !== 0)
			throw new RuntimeException('Update preflight requires a healthy queue with no processing jobs; pause workers and drain them');
		$production = strtolower(trim((string)getenv('APP_ENV'))) === 'production';
		if ($production)
		{
			$credentials = DatabaseCredentials::fromConfig($config, $domain);
			if ($credentials->password() === '' || SecretCipher::encrypt('preflight', 'update-preflight') === null)
				throw new RuntimeException('Update preflight requires database credentials and APP_DATA_KEY');
			if (in_array(strtolower((string)getenv('REQUIRE_TURNSTILE')), array('1', 'true', 'yes', 'on'), true)
				&& (trim((string)($config['Turnstile_SiteKey'] ?? '')) === '' || trim((string)($config['Turnstile_SecretKey'] ?? '')) === ''))
				throw new RuntimeException('Update preflight requires configured Turnstile keys');
		}
		// Code-only updates do not start or require a new SQL backup/restore drill.
		if (!UpdateClassification::requiresBackup($manifest->classification())) return;
		if (!$production && !in_array(strtolower((string)getenv('RECOVERY_ENABLED')), array('1', 'true', 'yes', 'on'), true)) return;
		$backupSettings = BackupSettings::fromEnvironment($this->projectRoot);
		$settings = RecoverySettings::fromEnvironment($this->projectRoot, $backupSettings->directory());
		$targets = array('cms');
		if (in_array(strtolower((string)($config['telemetry_collector_enabled'] ?? '0')), array('1', 'true', 'yes', 'on'), true))
			$targets = array('cms', 'collector', 'service_tokens');
		$schema = (new SchemaStateRepository($database))->currentVersion();
		if ($schema === null) throw new RuntimeException('Source schema is unknown');
		RecoveryPreflight::assertReady($settings, BackupService::fromConfig($database, $config, $domain, $this->projectRoot), $schema, $targets);
	}

	private function directoryCheck(array &$checks, string $id, string $label, string $path, array $blocks, string $failure = 'error'): void
	{
		// Docker intentionally exposes persistent directories through top-level
		// symlinks. What matters here is the resolved directory and its rights.
		$exists = is_dir($path);
		$ready = $exists && is_readable($path) && is_writable($path);
		$detail = $ready ? $this->t('configurator.preflight.directory_ready') : $this->t('configurator.preflight.directory_unavailable');
		if (!$exists && !file_exists($path) && !is_link($path))
		{
			$parent = dirname($path);
			while ($parent !== dirname($parent) && !file_exists($parent)) $parent = dirname($parent);
			if (is_dir($parent) && is_writable($parent))
			{
				$ready = true;
				$detail = $this->t('configurator.preflight.directory_create');
			}
		}
		$this->add($checks, $id, $label, $ready, $detail, $blocks, $path, $failure);
	}

	private function add(
		array &$checks,
		string $id,
		string $label,
		bool $passed,
		string $detail,
		array $blocks,
		?string $path = null,
		string $failure = 'error'
	): void {
		$checks[] = array(
			'id' => $id,
			'label' => $label,
			'passed' => $passed,
			'status' => $passed ? 'ok' : $failure,
			'detail' => $detail,
			'blocks' => $blocks,
			'path' => $path,
		);
	}
}
