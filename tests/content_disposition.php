<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\ContentDisposition;

require dirname(__DIR__) . '/vendor/autoload.php';

$header = ContentDisposition::attachment("../private/отчёт\"\r\nX-Injected: yes.sql");
if (str_contains($header, "\r") || str_contains($header, "\n")
	|| str_contains($header, 'private/') || str_contains($header, 'X-Injected:')
	|| !str_starts_with($header, 'attachment; filename="')
	|| !str_contains($header, "filename*=UTF-8''"))
	throw new RuntimeException('Unsafe Content-Disposition header');

$backup = ContentDisposition::attachment('backup-20260928.sql');
if ($backup !== "attachment; filename=\"backup-20260928.sql\"; filename*=UTF-8''backup-20260928.sql")
	throw new RuntimeException('Valid backup filename changed');

echo "Content-Disposition tests passed.\n";
