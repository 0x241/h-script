<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

/** Only fixed, safe reasons cross the administration boundary. */
final class PawProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
