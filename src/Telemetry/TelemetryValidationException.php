<?php

namespace HScript\Telemetry;

use InvalidArgumentException;

final class TelemetryValidationException extends InvalidArgumentException
{
	public function __construct(
		private string $errorCode,
		string $message,
		private string $field = ''
	) {
		parent::__construct($message);
	}

	public function errorCode(): string { return $this->errorCode; }
	public function field(): string { return $this->field; }
}
