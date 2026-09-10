<?php

namespace HScript\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Builds the managed-file list from an already checksum-verified release tree. */
final class ReleaseInventory
{
	public static function preserved(string $path): bool
	{
		if (in_array($path, array('_config.php', '_config.local.php', '.env', 'module/_config/pass', 'resources/release-baseline.json'), true)) return true;
		if (str_starts_with($path, '.env.')) return true;
		foreach (array(
			'.cfg/', 'upload/', 'logs/', 'backup/', 'compile/', 'tpl_c/', 'cache/', 'tmp/', 'runtime/', 'tpl/themes/',
			'module/local/', 'src/Local/', 'static/local/',
		) as $prefix)
			if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) return true;
		return false;
	}

	/** @param array<string,string> $sourceBaseline */
	public static function managed(string $releaseRoot, array $sourceBaseline = array()): array
	{
		$result = array();
		foreach (self::files($releaseRoot) as $path => $absolute)
		{
			if (self::preserved($path)) continue;
			$twig = str_starts_with($path, 'tpl/') && str_ends_with($path, '.twig');
			$result[] = array(
				'path' => $path,
				'sha256' => self::hash($absolute, $path),
				'source_sha256' => $sourceBaseline[$path] ?? null,
				'size' => filesize($absolute),
				'class' => $twig ? 'customizable' : 'core_strict',
			);
		}
		usort($result, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
		return $result;
	}

	/**
	 * Builds the official integrity scope. User themes stay outside the update
	 * activation plan, but their official files still need a neutral baseline so
	 * local edits and additions can be shown as customizations.
	 */
	public static function integrityBaseline(string $releaseRoot): array
	{
		$result = array();
		foreach (self::files($releaseRoot) as $path => $absolute)
		{
			if ($path === 'resources/release-baseline.json') continue;
			$theme = str_starts_with($path, 'tpl/themes/');
			if (self::preserved($path) && !$theme) continue;
			$twig = str_starts_with($path, 'tpl/') && str_ends_with(strtolower($path), '.twig');
			$result[] = array(
				'path' => $path,
				'sha256' => self::hash($absolute, $path),
				'size' => filesize($absolute),
				'class' => ($twig || $theme) ? 'customizable' : 'core_strict',
			);
		}
		usort($result, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
		return $result;
	}

	/** @return array<string,string> */
	private static function files(string $root): array
	{
		$root = rtrim($root, '/');
		if (!is_dir($root) || is_link($root)) throw new RuntimeException('Release tree is invalid');
		$result = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $item)
		{
			$absolute = $item->getPathname();
			$path = substr($absolute, strlen($root) + 1);
			if ($item->isLink() || !$item->isFile()) throw new RuntimeException('Release tree contains a link or special file: ' . $path);
			$result[$path] = $absolute;
		}
		ksort($result, SORT_STRING);
		return $result;
	}

	private static function hash(string $absolute, string $path): string
	{
		$hash = hash_file('sha256', $absolute);
		if (!is_string($hash)) throw new RuntimeException('Release file could not be read: ' . $path);
		return $hash;
	}

	public static function requireSafePath(mixed $path): string
	{
		$path = is_string($path) ? trim($path) : '';
		$segments = explode('/', $path);
		if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '\\')
			|| str_contains($path, "\0") || in_array('', $segments, true) || in_array('.', $segments, true)
			|| in_array('..', $segments, true) || !preg_match('/^[A-Za-z0-9._\/-]+$/', $path))
			throw new RuntimeException('Release path is unsafe');
		return $path;
	}
}
