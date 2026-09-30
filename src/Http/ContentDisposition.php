<?php

namespace HScript\Http;

/** Builds a download header without accepting path or header syntax from a name. */
final class ContentDisposition
{
	public static function attachment(string $filename): string
	{
		$filename = str_replace('\\', '/', $filename);
		$filename = basename($filename);
		$filename = (string)preg_replace('/[\x00-\x1f\x7f]/', '', $filename);
		if (!preg_match('//u', $filename))
			$filename = '';
		$filename = mb_strcut($filename, 0, 180, 'UTF-8');
		$filename = trim($filename, " .\t");
		if ($filename === '' || $filename === '..')
			$filename = 'download';
		$ascii = (string)preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
		$ascii = trim($ascii, '._-') ?: 'download';
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
	}
}
