<?php

declare(strict_types=1);

namespace HScript\Finance;

/** Credentialed order confirmation must match the originally created invoice. */
final class PaykassaContract
{
    public const SYSTEMS = ['PKB'=>'bitcoin','PKE'=>'ethereum','PKL'=>'litecoin','PKTU'=>'routes','PKTC'=>'routes'];

    public static function validate(array $operation, array $verified, ?array $form, array $sci, int $scale, string $expected): array
    {
        if ($form === null) throw new CallbackValidationException('callback_invoice_unavailable');
        foreach (['merchant_id','amount','currency','system','address','tag','invoice','hash'] as $field)
            if (!is_string($form['paykassa_'.$field] ?? null))
                throw new CallbackValidationException('callback_invoice_unavailable');
        if (CallbackAmount::atScale($form['paykassa_amount'],$scale) !== $expected)
            throw new CallbackValidationException('callback_invoice_amount_mismatch');
        $route=null;
        if(isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$operation['cCID']])) {
            $route=\HScript\Payment\Routing\PaymentRoutes::assertSnapshot($operation['cCID'],$form['payment_route']??null);
            if(($verified['payment_route_hash']??null)!==\HScript\Payment\Routing\PaymentRoutes::hash($route) || ($verified['paykassa_system']??null)!==\HScript\Payment\Routing\PaymentRoutes::paykassaSystem($route))throw new CallbackValidationException('payment_route_mismatch');
        }
        if ($form['paykassa_currency'] !== $operation['cCurrID']
            || $form['paykassa_system'] !== ($route!==null?\HScript\Payment\Routing\PaymentRoutes::paykassaSystem($route):self::SYSTEMS[$operation['cCID']]))
            throw new CallbackValidationException('callback_invoice_currency_mismatch');
        $merchant = $form['paykassa_merchant_id'];
        if ($merchant === '' || !is_string($sci['id'] ?? null) || !hash_equals($merchant,$sci['id'])
            || !is_string($verified['merchant_id'] ?? null) || !hash_equals($merchant,$verified['merchant_id']))
            throw new CallbackValidationException('callback_merchant_mismatch');
        if ($form['paykassa_invoice'] === '' || $form['paykassa_invoice'] !== ($verified['batch'] ?? null)
            || $form['paykassa_invoice'] !== ($form['psysorder'] ?? null))
            throw new CallbackValidationException('callback_invoice_identity_mismatch');
        if ($form['paykassa_address'] === '' || !is_string($verified['accto'] ?? null)
            || !hash_equals($form['paykassa_address'],$verified['accto'])
            || !is_string($verified['destination_tag'] ?? null) || !hash_equals($form['paykassa_tag'],$verified['destination_tag']))
            throw new CallbackValidationException('callback_destination_mismatch');
        $hash = $form['paykassa_hash'];
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash) || !is_string($form['hash'] ?? null)
            || !hash_equals($hash,hash('sha256',$form['hash'])) || !is_string($verified['provider_invoice_hash'] ?? null)
            || !hash_equals($hash,$verified['provider_invoice_hash']))
            throw new CallbackValidationException('callback_invoice_identity_mismatch');
        return ['payment_route_hash'=>$route!==null?\HScript\Payment\Routing\PaymentRoutes::hash($route):null,'destination'=>$form['paykassa_address'],'merchant_hash'=>hash('sha256',$merchant),
            'provider_invoice_hash'=>$hash,'destination_tag_hash'=>hash('sha256',$form['paykassa_tag'])];
    }
}
