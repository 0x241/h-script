<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Observability\NdjsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';
if (getenv('H_SCRIPT_NDJSON_DISK_TEST') !== '1' || !is_dir('/full')) throw new RuntimeException('Use the isolated disk-full runner');
$path = '/full/evidence.ndjson';
$original = json_encode(array('id' => 1, 'pad' => str_repeat('x', 4040))) . "\n";
if (!NdjsonWriter::append($path, $original, 65536, 3)) throw new RuntimeException('Disk fixture bootstrap failed');
$fill = fopen('/full/filler', 'wb');
set_error_handler(static fn(): bool => true);
try
{
	while (fwrite($fill, str_repeat('f', 4096)) === 4096) {}
	fflush($fill);
}
finally { fclose($fill); restore_error_handler(); }
$line = json_encode(array('id' => 2, 'pad' => str_repeat('y', 8192))) . "\n";
if (NdjsonWriter::append($path, $line, 65536, 3)) throw new RuntimeException('Full filesystem unexpectedly accepted the append');
if (file_get_contents($path) !== $original) throw new RuntimeException('Disk-full rollback damaged previous evidence');
unlink('/full/filler');
if (!NdjsonWriter::append($path, $line, 65536, 3)) throw new RuntimeException('Writer did not recover after space was freed');
foreach (file($path) as $record) json_decode($record, true, 16, JSON_THROW_ON_ERROR);
echo "Actual tmpfs exhaustion: failed append preserved complete records and recovered after space was freed.\n";
