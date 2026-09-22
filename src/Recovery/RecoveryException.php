<?php

namespace HScript\Recovery;

use RuntimeException;
use Throwable;

final class RecoveryException extends RuntimeException
{
	private string $errorCode;

	public function __construct(string $errorCode, string $message, ?Throwable $previous = null)
	{
		if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $errorCode))
			$errorCode = 'recovery_failed';
		$this->errorCode = $errorCode;
		parent::__construct($message, 0, $previous);
	}

	public function errorCode(): string
	{
		return $this->errorCode;
	}
}
