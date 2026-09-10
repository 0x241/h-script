<?php

namespace HScript\Backup;

interface DatabaseBackupAdapter
{
	public function name(): string;
	public function available(): bool;

	/** @param callable(string):void $write */
	public function dump(DatabaseCredentials $credentials, array $tables, callable $write, string $footerMarker): void;
}
