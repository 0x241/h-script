<?php

declare(strict_types=1);

namespace HScript\Payment;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use HScript\Payment\Gateways\CryptoCurrencyApi;

/** Presentation of an already validated saved checkout; no provider requests. */
final class NativePaymentDetails
{
    public static function present(string $gateway, array $form): array
    {
        [$chain, $asset] = CryptoCurrencyApi::NATIVE[$gateway] ?? ['', ''];
        if(isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$gateway])) {
            foreach(\HScript\Payment\Routing\PaymentRoutes::catalog($gateway) as $route)if(($form['payment_network']??null)===$route['network'])[$chain,$asset]=[$route['network'],$route['asset']];
        }
        if($gateway==='CCT' && is_string($form['payment_asset']??null) && isset(\HScript\Payment\Routing\TokenCatalog::NETWORKS[$form['payment_network']??'']))[$chain,$asset]=[$form['payment_network'],$form['payment_asset']];
        $address = $form['payment_address'] ?? null;
        if (($form['formkind'] ?? '') !== 'native_address' || $asset === ''
            || ($form['payment_asset'] ?? '') !== $asset || !is_string($address)
            || CcapiV3Client::addressIdentity($chain, $address) === null) return [];
        $network = match ($chain) {
            'bitcoincash' => 'Bitcoin Cash', 'bsc' => 'BNB Smart Chain',
            'ethereum' => 'Ethereum (ERC-20)', 'tron' => 'TRON', 'ripple' => 'XRP Ledger', 'ton' => 'TON',
            default => ucfirst($chain),
        };
        // Address-only QR works across native wallets without guessing a URI
        // scheme, chain ID, amount unit, or an unsupported destination tag.
        // Endroid 5 is shared with TOTP and supports PHP 8.2. Its nullable
        // parameter declarations emit PHP 8.4 deprecations during autoload.
        $reporting = error_reporting();
        try {
            error_reporting($reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
            $qr = QrCode::create($address)->setSize(208)->setMargin(16);
            $image = (new SvgWriter())->write($qr)->getDataUri();
        } catch (\Throwable) {
            error_log('Native payment QR generation failed');
            $image = '';
        } finally {
            error_reporting($reporting);
        }
        return ['network' => $network, 'qr' => $image,'contract'=>$form['payment_contract']??''];
    }
}
