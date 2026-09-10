<?php

namespace HScript\Update;

use JsonException;
use RuntimeException;

final class PreparedUpdateRepository
{
	private UpdateSettings $settings;

	public function __construct(UpdateSettings $settings)
	{
		$this->settings = $settings;
	}

	public function publish(array $record): array
	{
		$this->validate($record);
		$path = $this->path($record['id']);
		if (file_exists($path))
			throw new RuntimeException('Prepared update already exists');
		$this->writeAtomic($path, $record, true);
		return $record;
	}

	public function get(string $id): array
	{
		$this->assertId($id);
		$path = $this->path($id);
		if (!is_file($path) || is_link($path))
			throw new RuntimeException('Prepared update was not found');
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception)
		{
			throw new RuntimeException('Prepared update metadata is invalid', 0, $exception);
		}
		$this->validate($data);
		return $data;
	}

	public function attachRun(string $id, string $runId, string $backupId = ''): array
	{
		$record = $this->get($id);
		if ($record['run_id'] !== '' && $record['run_id'] !== $runId)
			throw new RuntimeException('Prepared update already belongs to another run');
		$record['run_id'] = $runId;
		$record['backup_id'] = $backupId;
		$record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->writeAtomic($this->path($id), $record, false);
		return $record;
	}

	public function findByRun(string $runId): array
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $runId))
			throw new RuntimeException('Update run ID is invalid');
		$paths = glob($this->settings->preparedDirectory() . '/*.json');
		if ($paths === false)
			throw new RuntimeException('Prepared update directory could not be listed');
		foreach ($paths as $path)
		{
			$id = basename($path, '.json');
			try
			{
				$record = $this->get($id);
				if ($record['run_id'] === $runId)
					return $record;
			}
			catch (RuntimeException)
			{
				continue;
			}
		}
		throw new RuntimeException('Prepared update for this run was not found');
	}

	private function validate(mixed $record): void
	{
		$expected = array(
			'activation_plan', 'activation_plan_sha256', 'archive', 'archive_sha256', 'backup_id', 'conflicts', 'created_at', 'file_choices', 'id',
			'manifest', 'manifest_checksum', 'run_id', 'source', 'staging', 'updated_at',
		);
		if (!is_array($record) || array_is_list($record))
			throw new RuntimeException('Prepared update metadata is invalid');
		$actual = array_keys($record);
		sort($actual);
		sort($expected);
		if ($actual !== $expected)
			throw new RuntimeException('Prepared update metadata fields are invalid');
		$this->assertId($record['id']);
		foreach (array('activation_plan_sha256', 'archive_sha256', 'manifest_checksum') as $field)
			if (!is_string($record[$field]) || !preg_match('/^[a-f0-9]{64}$/', $record[$field]))
				throw new RuntimeException('Prepared update checksum is invalid');
		if (!in_array($record['source'], array('manual', 'github', 'bundled'), true))
			throw new RuntimeException('Prepared update source is invalid');
		if ($record['source'] === 'bundled')
		{
			if ($record['archive'] !== '' || $record['staging'] !== $this->settings->projectRoot())
				throw new RuntimeException('Bundled update paths are unsafe');
		}
		else
		{
			if ($record['archive'] !== $this->settings->packagesDirectory() . '/' . $record['id'] . '.tar.gz')
				throw new RuntimeException('Prepared update archive path is unsafe');
			if ($record['staging'] !== $this->settings->stagingDirectory() . '/' . $record['id'])
				throw new RuntimeException('Prepared update staging path is unsafe');
		}
		if (!is_array($record['manifest']) || !is_array($record['activation_plan']) || !is_array($record['conflicts']) || !is_array($record['file_choices']))
			throw new RuntimeException('Prepared update data is invalid');
		$planJson = json_encode($record['activation_plan'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if (!hash_equals($record['activation_plan_sha256'], hash('sha256', $planJson)))
			throw new RuntimeException('Prepared activation plan checksum is invalid');
		$manifest = ReleaseManifest::fromArray($record['manifest']);
		if (!hash_equals($manifest->activationPlanChecksum(), $record['activation_plan_sha256']))
			throw new RuntimeException('Prepared activation plan does not match release metadata');
		foreach (array('created_at', 'updated_at') as $field)
			if (!is_string($record[$field]) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record[$field]))
				throw new RuntimeException('Prepared update timestamp is invalid');
		foreach (array('run_id', 'backup_id') as $field)
			if (!is_string($record[$field]) || ($record[$field] !== '' && !preg_match('/^[a-f0-9]{32}$/', $record[$field])))
				throw new RuntimeException('Prepared update reference is invalid');
	}

	public function saveFileChoices(string $id, array $choices): array
	{
		$record = $this->get($id);
		$record['file_choices'] = $choices;
		$record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->writeAtomic($this->path($id), $record, false);
		return $record;
	}

	private function writeAtomic(string $path, array $data, bool $exclusive): void
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$temporary = $path . '.' . bin2hex(random_bytes(8)) . '.part';
		$stream = fopen($temporary, 'xb');
		if ($stream === false)
			throw new RuntimeException('Prepared update metadata could not be created');
		try
		{
			$offset = 0;
			$length = strlen($json);
			while ($offset < $length)
			{
				$written = fwrite($stream, substr($json, $offset));
				if ($written === false || $written === 0)
					throw new RuntimeException('Prepared update metadata could not be written');
				$offset += $written;
			}
			if (!fflush($stream))
				throw new RuntimeException('Prepared update metadata could not be flushed');
		}
		finally
		{
			fclose($stream);
		}
		chmod($temporary, 0600);
		if (($exclusive && file_exists($path)) || !rename($temporary, $path))
		{
			unlink($temporary);
			throw new RuntimeException('Prepared update metadata could not be published');
		}
	}

	private function path(string $id): string
	{
		$this->assertId($id);
		return $this->settings->preparedDirectory() . '/' . $id . '.json';
	}

	private function assertId(mixed $id): void
	{
		if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id))
			throw new RuntimeException('Prepared update ID is invalid');
	}
}
