<?php

namespace HScript\Update;

interface ReleaseActivator
{
	public function preflight(ReleaseManifest $manifest, array $activationPlan): array;
	public function activate(string $runId, string $preparedId, ReleaseManifest $manifest, string $stagingRoot, array $fileChoices, array $activationPlan): array;
	public function rollback(string $runId): array;
	public function prunePrevious(int $keep): array;
	public function status(string $runId): ?array;
	public function activeRoot(): string;
}
