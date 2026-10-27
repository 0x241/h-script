<?php
declare(strict_types=1);
namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/** NEW catalog preparation. Financial execution awaits separately verified receipts. */
final class CoinPaymentsNew extends AbstractGateway
{
    public function validateConfig(array $config): bool { return false; }
    public function processDeposit(array $params): array { return ['error'=>'automatic_payment_unsupported']; }
    public function processWithdrawal(array $params): array { return ['result'=>'automatic_payment_unsupported']; }
}
