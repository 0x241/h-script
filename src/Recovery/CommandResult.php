<?php

namespace HScript\Recovery;

final class CommandResult
{
	public function __construct(
		private int $exitCode,
		private string $stdout,
		private string $stderr
	) {}

	public function exitCode(): int { return $this->exitCode; }
	public function stdout(): string { return $this->stdout; }
	public function stderr(): string { return $this->stderr; }
}
