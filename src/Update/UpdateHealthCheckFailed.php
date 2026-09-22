<?php

namespace HScript\Update;

use RuntimeException;

/** Contains only fixed check identifiers, never SQL, credentials or exception text. */
final class UpdateHealthCheckFailed extends RuntimeException
{
	public function __construct(private array $failedChecks)
	{
		parent::__construct('Post-update health check failed: ' . implode(', ', $failedChecks));
	}

	public function failedChecks(): array { return $this->failedChecks; }
}
