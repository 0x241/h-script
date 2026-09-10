<?php

namespace HScript\Update;

use HScript\Database\Connection;

final class UpdateRetention
{
	private UpdateSettings $settings;
	private UpdateRunRepository $runs;

	public function __construct(Connection $database, UpdateSettings $settings)
	{
		$this->settings = $settings;
		$this->runs = new UpdateRunRepository($database);
	}

	public function prune(string $keepPreparedId): array
	{
		$paths = glob($this->settings->preparedDirectory() . '/*.json');
		if ($paths === false) return array();
		$records = array();
		$repository = new PreparedUpdateRepository($this->settings);
		foreach ($paths as $path)
		{
			try { $records[] = $repository->get(basename($path, '.json')); }
			catch (\Throwable) {}
		}
		usort($records, static fn(array $left, array $right): int => strcmp($right['updated_at'], $left['updated_at']));
		$deleted = array();
		$kept = 0;
		foreach ($records as $record)
		{
			$run = $record['run_id'] !== '' ? $this->runs->get($record['run_id']) : null;
			$protected = $record['id'] === $keepPreparedId
				|| ($run !== null && !UpdateRunState::terminal((string)$run['urState']));
			if ($protected || $kept < $this->settings->retentionCount())
			{
				$kept++;
				continue;
			}
			$this->deleteFile($record['archive'], $this->settings->packagesDirectory());
			$this->deleteTree($record['staging'], $this->settings->stagingDirectory());
			$this->deleteTree($this->settings->conflictsDirectory() . '/' . $record['id'], $this->settings->conflictsDirectory());
			if ($record['run_id'] !== '')
			{
				$this->deleteTree($this->settings->previousDirectory() . '/' . $record['run_id'], $this->settings->previousDirectory());
				$this->deleteFile($this->settings->runsDirectory() . '/' . $record['run_id'] . '.journal.json', $this->settings->runsDirectory());
			}
			$this->deleteFile($this->settings->preparedDirectory() . '/' . $record['id'] . '.json', $this->settings->preparedDirectory());
			$deleted[] = $record['id'];
		}
		return $deleted;
	}

	private function deleteFile(string $path, string $parent): void
	{
		if (dirname($path) === $parent && is_file($path) && !is_link($path)) unlink($path);
	}

	private function deleteTree(string $path, string $parent): void
	{
		if (dirname($path) !== $parent || !is_dir($path) || is_link($path)) return;
		foreach ((array)scandir($path) as $item)
			if ($item !== '.' && $item !== '..') $this->deleteTreeItem($path . '/' . $item);
		rmdir($path);
	}

	private function deleteTreeItem(string $path): void
	{
		if (is_link($path) || is_file($path)) { unlink($path); return; }
		if (!is_dir($path)) return;
		foreach ((array)scandir($path) as $item)
			if ($item !== '.' && $item !== '..') $this->deleteTreeItem($path . '/' . $item);
		rmdir($path);
	}
}
