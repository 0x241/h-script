<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

interface PawValidationClient
{
    public function getBalance(): array;
    public function validateSandbox(): void;
    public function getRates(string $base): array;
}
