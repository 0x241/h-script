<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
