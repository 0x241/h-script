<?php

declare(strict_types=1);

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\CallbackResult;

/** Shared-profile administration is available; native finance awaits review. */
final class PawPayments extends AbstractGateway
{
    public function processDeposit(array $params): array
    {
        return ['result' => 'paw_native_integration_unavailable', 'error' => 'paw_native_integration_unavailable'];
    }

    public function processWithdrawal(array $params): array
    {
        return ['result' => 'paw_native_integration_unavailable'];
    }

    public function getBalance(array $config): array
    {
        // Read-only connectivity validation belongs to the guarded save POST.
        return ['result' => 'paw_native_integration_unavailable', 'sum' => -1];
    }

    public function handleCallback(array $request): array
    {
        return CallbackResult::failure('unsupported', 'automatic_callback_unsupported');
    }

    public function validateConfig(array $config): bool
    {
        return false;
    }
}
