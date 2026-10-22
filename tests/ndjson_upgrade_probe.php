<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Observability\NdjsonHistory;
use HScript\Observability\NdjsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';
if (getenv('H_SCRIPT_NDJSON_UPGRADE_TEST') !== '1' || !is_dir('/evidence')) throw new RuntimeException('Use the isolated Compose upgrade runner');
$path = '/evidence/csp-violations.ndjson';
$phase = $argv[1] ?? '';
$read = static function () use ($path): array {
	$ids = array();
	$stats = NdjsonHistory::read($path, static function (array $record) use (&$ids): void { $ids[] = $record['id']; });
	$hashes = array();
	for ($index = 7; $index >= 0; $index--)
	{
		$file = $index === 0 ? $path : $path . '.' . $index;
		if (is_file($file)) $hashes[(string)$index] = hash_file('sha256', $file);
	}
	return array('ids' => $ids, 'hashes' => $hashes, 'lock_inode' => fileinode($path . '.lock'), 'stats' => $stats);
};
if ($phase === 'before')
{
	$now = time();
	for ($id = 1; $id <= 10; $id++)
	{
		$line = json_encode(array('timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now - (10 - $id) * 86400), 'id' => $id)) . "\n";
		if (!NdjsonWriter::append($path, $line, 120, 7)) throw new RuntimeException('Upgrade fixture append failed');
	}
	$state = $read();
	if ($state['ids'] !== range(1, 10) || $state['stats']['files'] < 3 || $state['stats']['status'] !== 'ok') throw new RuntimeException('Before-upgrade sequence/window is invalid');
	file_put_contents('/evidence/upgrade-state.json', json_encode($state, JSON_THROW_ON_ERROR));
	chmod('/evidence/upgrade-state.json', 0600);
	echo "Before image replacement: 10 records across " . $state['stats']['files'] . " files; observation window exceeds seven days.\n";
}
elseif ($phase === 'after')
{
	$before = json_decode(file_get_contents('/evidence/upgrade-state.json'), true, 16, JSON_THROW_ON_ERROR);
	$after = $read();
	if ($after['ids'] !== $before['ids'] || $after['hashes'] !== $before['hashes'] || $after['lock_inode'] !== $before['lock_inode'] || $after['stats']['status'] !== 'ok') throw new RuntimeException('Image replacement reset or changed history');
	$line = json_encode(array('timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => 11)) . "\n";
	if (!NdjsonWriter::append($path, $line, 120, 7)) throw new RuntimeException('Replacement writer failed');
	$after = $read();
	if ($after['ids'] !== range(1, 11) || $after['stats']['status'] !== 'ok') throw new RuntimeException('Replacement writer did not continue the sequence/window');
	echo "Compose image replacement: retained hashes/lock/sequence/window survived; record 11 appended normally.\n";
}
else throw new RuntimeException('Invalid upgrade phase');
