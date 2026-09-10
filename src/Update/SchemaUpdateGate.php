<?php

namespace HScript\Update;

use HScript\Application;
use HScript\Database\Connection;
use RuntimeException;

/** Blocks normal Docker traffic while the image and database schema disagree. */
final class SchemaUpdateGate
{
	private string $marker;
	private const NON_BLOCKING_REASONS = array(
		'schema_metadata_missing',
		'application_metadata_missing',
	);

	public function __construct(private Connection $database, string $projectRoot)
	{
		$this->marker = rtrim($projectRoot, '/') . '/.cfg/schema-update-required.json';
	}

	public function refresh(): ?array
	{
		$state = new SchemaStateRepository($this->database);
		$currentSchema = $state->currentVersion();
		if ($currentSchema === null)
		{
			if (!$this->populatedConfiguration())
			{
				$this->clear();
				return null;
			}
			return $this->publish('schema_metadata_missing', null, $state->installedApplicationVersion());
		}

		$currentApplication = $state->installedApplicationVersion();
		if ($currentApplication === null)
			return $this->publish('application_metadata_missing', $currentSchema, null);
		if ($currentSchema === Application::schemaVersion())
		{
			if ($currentApplication === Application::version())
			{
				$this->clear();
				return null;
			}
			try { UpdateCompatibility::fromRoot(dirname(__DIR__, 2))->assertSource($currentApplication, $currentSchema); }
			catch (RuntimeException) { return $this->publish('unsupported_source_version', $currentSchema, $currentApplication); }
			return $this->publish('application_update_required', $currentSchema, $currentApplication);
		}

		$reason = 'schema_update_required';
		try
		{
			UpdateCompatibility::fromRoot(dirname(__DIR__, 2))->assertSource($currentApplication, $currentSchema);
		}
		catch (RuntimeException)
		{
			$reason = 'unsupported_source_version';
		}
		return $this->publish($reason, $currentSchema, $currentApplication);
	}

	public function clear(): void
	{
		if (is_link($this->marker)) throw new RuntimeException('Schema update marker cannot be a symbolic link');
		if (is_file($this->marker) && !unlink($this->marker))
			throw new RuntimeException('Schema update marker could not be removed');
	}

	/** Missing lifecycle metadata requires onboarding, but does not prove an unsafe schema mismatch. */
	public static function requiresTrafficGate(string $projectRoot): bool
	{
		$marker = rtrim($projectRoot, '/') . '/.cfg/schema-update-required.json';
		if (!is_file($marker)) return false;
		if (is_link($marker) || !is_readable($marker)) return true;
		try { $data = json_decode((string)file_get_contents($marker), true, 16, JSON_THROW_ON_ERROR); }
		catch (\Throwable) { return true; }
		$reason = is_array($data) ? ($data['reason'] ?? null) : null;
		return !is_string($reason) || !in_array($reason, self::NON_BLOCKING_REASONS, true);
	}

	private function publish(string $reason, ?string $schema, ?string $application): array
	{
		$data = array(
			'format' => 1,
			'reason' => $reason,
			'installed_application_version' => $application,
			'image_application_version' => Application::version(),
			'installed_schema_version' => $schema,
			'image_schema_version' => Application::schemaVersion(),
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
		);
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$temporary = $this->marker . '.' . bin2hex(random_bytes(8)) . '.part';
		if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json))
			throw new RuntimeException('Schema update marker could not be written');
		// Docker refreshes this marker as root while PHP-FPM reads it as www-data.
		// It contains only lifecycle versions and a reason code; HTTP access to
		// the surrounding .cfg directory remains denied separately.
		chmod($temporary, 0644);
		if (!rename($temporary, $this->marker))
		{
			unlink($temporary);
			throw new RuntimeException('Schema update marker could not be published');
		}
		return $data;
	}

	private function populatedConfiguration(): bool
	{
		$exists = (bool)$this->database->fetch1($this->database->query(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
			array('Cfg')
		));
		return $exists && $this->database->count('Cfg') > 0;
	}
}
