<?php

namespace HScript\Http;

use HScript\Observability\NdjsonHistory;
use HScript\Observability\NdjsonWriter;

/** Re-validates untrusted stored fields before producing a public-safe aggregate. */
final class CspReportSummary
{
	public static function summarize(string $path, ?int $now = null): array
	{
		$counts = array();
		$history = NdjsonHistory::read($path, static function (array $record) use (&$counts): void {
			$surface = self::choice($record['document_surface'] ?? null, array('public', 'configurator', 'administration', 'authentication', 'account', 'api'), 'unknown');
			$directive = self::choice($record['directive'] ?? null, array('base-uri', 'child-src', 'connect-src', 'default-src', 'font-src', 'form-action', 'frame-ancestors', 'frame-src', 'img-src', 'manifest-src', 'media-src', 'object-src', 'script-src', 'script-src-attr', 'script-src-elem', 'style-src', 'style-src-attr', 'style-src-elem', 'worker-src', 'sandbox', 'upgrade-insecure-requests'), 'unknown');
			$sourceKind = self::choice($record['source_kind'] ?? null, array('web', 'browser-extension', 'unknown', 'other'), 'unknown');
			$disposition = self::choice($record['disposition'] ?? null, array('report', 'enforce'), 'unknown');
			$fields = array($surface, $directive, self::origin($record['blocked_origin'] ?? null), $sourceKind, self::assetClass($record['source_asset'] ?? null), $disposition);
			$key = json_encode($fields, JSON_THROW_ON_ERROR);
			if (!isset($counts[$key]) && count($counts) >= 4095)
				$key = json_encode(array('unknown', 'unknown', 'other', 'unknown', 'unknown', 'unknown'), JSON_THROW_ON_ERROR);
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}, $now);
		arsort($counts, SORT_NUMERIC);
		$groups = array();
		foreach ($counts as $key => $count)
			$groups[] = array('count' => $count, 'fields' => json_decode($key, true, 8, JSON_THROW_ON_ERROR));
		$maximum = filter_var(getenv('CSP_LOG_MAX_BYTES'), FILTER_VALIDATE_INT);
		$maximum = $maximum === false ? 20971520 : max(1048576, min((int)$maximum, 104857600));
		$rotations = NdjsonWriter::rotationCount();
		$projected = $history['peak_daily_bytes'] * NdjsonHistory::retentionDays() * 4;
		return array(
			'format' => 1,
			'history' => $history,
			'capacity' => array(
				'file_bytes' => $maximum,
				'rotated_files' => $rotations,
				'total_byte_ceiling' => ($rotations + 1) * $maximum,
				'planning_margin' => 4,
				'projected_window_bytes' => $projected,
				'recommended_rotated_files' => max(1, (int)ceil($projected / $maximum)),
				'projected_capacity' => $rotations * $maximum >= $projected ? 'sufficient' : 'insufficient',
			),
			'groups' => $groups,
		);
	}

	private static function choice(mixed $value, array $values, string $fallback): string
	{
		return is_string($value) && in_array($value, $values, true) ? $value : $fallback;
	}

	private static function origin(mixed $value): string
	{
		if (!is_string($value)) return 'other';
		if (in_array($value, array('inline', 'eval', 'data', 'blob', 'self', 'other'), true)) return $value;
		// Only a plain DNS origin is admitted: no address literal, userinfo, port, path or query.
		return strlen($value) <= 261 && preg_match('~^https?://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~D', $value) ? $value : 'other';
	}

	private static function assetClass(mixed $value): string
	{
		if (!is_string($value) || !preg_match('~^/static/[A-Za-z0-9/_.-]+$~D', $value)) return 'unknown';
		return match (strtolower(pathinfo($value, PATHINFO_EXTENSION))) {
			'js' => 'application-javascript',
			'css' => 'application-stylesheet',
			'png', 'jpg', 'jpeg', 'webp', 'svg', 'gif', 'ico' => 'application-image',
			'woff', 'woff2', 'ttf', 'otf' => 'application-font',
			default => 'application-other',
		};
	}
}
