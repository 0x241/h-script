<?php

namespace HScript\Update;

use InvalidArgumentException;

/** Resolves an explicit shared-hosting target for the same verified CLI engine. */
final class UpdateCliContext
{
	public static function resolve(array $arguments, string $engineRoot): array
	{
		$root = realpath($engineRoot);
		$external = null;
		$filtered = array();
		foreach ($arguments as $argument)
		{
			if (!str_starts_with($argument, '--project-root=')) { $filtered[] = $argument; continue; }
			if ($external !== null) throw new InvalidArgumentException('Project root option is duplicated');
			$external = substr($argument, strlen('--project-root='));
		}
		if ($external === null) return array($root, $filtered, false);
		$command = $filtered[1] ?? '';
		if (PHP_SAPI !== 'cli' || trim((string)getenv('APP_RELEASE_VERSION')) !== ''
			|| !in_array($command, array('status', 'prepare', 'apply', 'resume', 'cancel', 'rollback-code', 'reconcile'), true))
			throw new InvalidArgumentException('External engine is only available for the shared-hosting update lifecycle');
		$target = str_starts_with($external, '/') ? realpath($external) : false;
		if ($target === false || !is_dir($target) || $target === '/' || $target === $root
			|| str_starts_with($target . '/', $root . '/') || str_starts_with($root . '/', $target . '/'))
			throw new InvalidArgumentException('Engine and target must be separate absolute project directories');
		foreach (array('VERSION', 'SCHEMA_VERSION') as $file)
		{
			if (!is_file($target . '/' . $file) || is_link($target . '/' . $file))
				throw new InvalidArgumentException('Target version metadata is missing or linked');
			SchemaVersion::requireValid(trim((string)file_get_contents($target . '/' . $file)));
		}
		return array($target, $filtered, true);
	}
}
