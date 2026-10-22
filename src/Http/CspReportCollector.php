<?php

namespace HScript\Http;

use HScript\Observability\NdjsonWriter;
use Throwable;

/** Accepts bounded CSP reports without retaining URLs, query strings, or IPs. */
final class CspReportCollector
{
	private const MAX_BODY_BYTES = 8192;
	private const MAX_BUCKETS = 1024;
	private const PER_IP_PER_MINUTE = 30;
	private const TOTAL_PER_MINUTE = 600;

	public static function handle(array $server, string $body, string $directory): int
	{
		if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') return 405;
		$type = strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''), 2)[0]));
		if (!in_array($type, array('application/csp-report', 'application/reports+json', 'application/json'), true)) return 415;
		if (strlen($body) > self::MAX_BODY_BYTES) return 413;
		$records = self::records($body);
		if ($records === null) return 400;
		if (!self::consumeRate($directory, ClientIp::resolve($server))) return 429;
		foreach ($records as $record)
		{
			$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			$maximum = filter_var(getenv('CSP_LOG_MAX_BYTES'), FILTER_VALIDATE_INT);
			$maximum = $maximum === false ? 20971520 : max(1048576, min((int)$maximum, 104857600));
			if (!NdjsonWriter::append($directory . '/csp-violations.ndjson', $line, $maximum)) return 503;
		}
		return 204;
	}

	/** @return ?list<array<string, int|string>> */
	public static function records(string $body): ?array
	{
		try { $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
		catch (Throwable) { return null; }
		$reports = isset($payload['csp-report']) ? array($payload['csp-report']) : $payload;
		if (!is_array($reports) || !array_is_list($reports) || count($reports) < 1 || count($reports) > 5)
			return null;
		$result = array();
		foreach ($reports as $report)
		{
			if (!is_array($report)) return null;
			if (isset($report['body']))
			{
				if (($report['type'] ?? '') !== 'csp-violation' || !is_array($report['body'])) return null;
				$report = $report['body'];
			}
			foreach (array(
				'effective-directive', 'effectiveDirective', 'disposition', 'blocked-uri', 'blockedURL',
				'document-uri', 'documentURL', 'source-file', 'sourceFile', 'line-number', 'lineNumber',
				'status-code', 'statusCode'
			) as $field)
				if (isset($report[$field]) && !is_scalar($report[$field])) return null;
			$directive = (string)($report['effective-directive'] ?? $report['effectiveDirective'] ?? '');
			if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $directive)) return null;
			$disposition = (string)($report['disposition'] ?? 'report');
			if (!in_array($disposition, array('report', 'enforce'), true)) return null;
			$result[] = array(
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
				'directive' => $directive,
				'disposition' => $disposition,
				'blocked_origin' => self::origin((string)($report['blocked-uri'] ?? $report['blockedURL'] ?? '')),
				'document_surface' => self::documentSurface((string)($report['document-uri'] ?? $report['documentURL'] ?? '')),
				'source_kind' => self::sourceKind((string)($report['source-file'] ?? $report['sourceFile'] ?? '')),
				'source_asset' => self::sourceAsset((string)($report['source-file'] ?? $report['sourceFile'] ?? '')),
				'line' => max(0, min(1000000, (int)($report['line-number'] ?? $report['lineNumber'] ?? 0))),
				'status' => max(0, min(599, (int)($report['status-code'] ?? $report['statusCode'] ?? 0))),
			);
		}
		return $result;
	}

	private static function origin(string $url): string
	{
		if (in_array($url, array('inline', 'eval', 'data', 'blob', 'self'), true)) return $url;
		$parts = parse_url($url);
		if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), array('http', 'https'), true)
			|| empty($parts['host'])) return 'other';
		$host = strtolower((string)$parts['host']);
		if (!preg_match('/^[a-z0-9.-]{1,253}$/D', $host)) return 'other';
		return strtolower((string)$parts['scheme']) . '://' . $host;
	}

	private static function documentSurface(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path)) return 'unknown';
		$segments = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
		if (isset($segments[0]) && in_array($segments[0], array('ru', 'en', 'de', 'es', 'fr'), true)) array_shift($segments);
		$first = $segments[0] ?? '';
		return match ($first) {
			'_cfg' => 'configurator',
			'admin' => 'administration',
			'login', 'registration', 'resetpass' => 'authentication',
			'account', 'cabinet' => 'account',
			'api' => 'api',
			default => 'public',
		};
	}

	private static function sourceAsset(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && strlen($path) <= 160 && preg_match('~^/static/[A-Za-z0-9/_.-]+$~D', $path)
			? $path : '';
	}

	private static function sourceKind(string $url): string
	{
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return match ($scheme) {
			'http', 'https' => 'web',
			'chrome-extension', 'moz-extension', 'safari-web-extension' => 'browser-extension',
			'' => 'unknown',
			default => 'other',
		};
	}

	private static function consumeRate(string $directory, string $ip): bool
	{
		if (!is_dir($directory) || !is_writable($directory)) return false;
		$path = $directory . '/csp-rate.json';
		if (is_link($path)) return false;
		$previousMask = umask(0077);
		$stream = fopen($path, 'c+');
		umask($previousMask);
		if ($stream === false) return false;
		try
		{
			if (!flock($stream, LOCK_EX)) return false;
			$raw = stream_get_contents($stream);
			$data = $raw === '' ? null : json_decode((string)$raw, true);
			$minute = intdiv(time(), 60);
			if (!is_array($data) || ($data['minute'] ?? null) !== $minute)
				$data = array('minute' => $minute, 'total' => 0, 'ips' => array());
			$key = hash('sha256', $ip);
			if (!isset($data['ips'][$key]) && count($data['ips']) >= self::MAX_BUCKETS) return false;
			$data['total'] = min(self::TOTAL_PER_MINUTE + 1, (int)$data['total'] + 1);
			$data['ips'][$key] = min(self::PER_IP_PER_MINUTE + 1, (int)($data['ips'][$key] ?? 0) + 1);
			$allowed = $data['total'] <= self::TOTAL_PER_MINUTE && $data['ips'][$key] <= self::PER_IP_PER_MINUTE;
			$json = json_encode($data, JSON_THROW_ON_ERROR);
			rewind($stream);
			if (!ftruncate($stream, 0) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) return false;
			chmod($path, 0600);
			return $allowed;
		}
		catch (Throwable) { return false; }
		finally { flock($stream, LOCK_UN); fclose($stream); }
	}
}
