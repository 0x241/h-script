<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Payment\CcapiV3Client;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\JsonCallbackPayload;
use HScript\Payment\StripeClient;
use HScript\Payment\VoletMerchantClient;
use stdClass;
use Throwable;

/** A connection probe never creates an invoice, payout or remote configuration. */
final class ProviderProbe
{
    public static function validate(string $kind,array $credentials,array $currencies,array $wallets): array
    {
        try {
            if ($kind==='CCAPI') {
                $choices=ProviderSettings::choices($kind);
                // One key covers every chain. One read authenticates the connection
                // within the provider's 10 requests / 2 seconds budget.
                $asset=$currencies[0];
                CcapiV3Client::request(CryptoCurrencyApi::NATIVE[$choices[$asset]['gateway']][0]??'ethereum','balance',['protocol'=>'3','v3_key'=>$credentials['v3_key']]);
            } elseif ($kind==='PAYKASSA') {
                $body=http_build_query(['func'=>'api_get_shop_balance','shop_id'=>$credentials['merchant_id'],
                    'api_id'=>$credentials['api_id'],'api_key'=>$credentials['api_secret'],'test'=>'0','domain'=>''],'','&',PHP_QUERY_RFC3986);
                $r=JsonCallbackPayload::object(ProviderHttp::request('https://paykassa.app/api/0.9/index.php',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$body));
                if (($r->error??null)!==false || !isset($r->data) || (!$r->data instanceof stdClass && !is_array($r->data))) throw new ProviderException('provider_unavailable');
            } elseif ($kind==='VOLET') {
                $config=ProviderSettings::configuration($kind,$credentials)['api'];
                $balances=VoletMerchantClient::request($config,'getBalances',[]);
                foreach ($wallets as $asset=>$wallet) if (!isset($balances[$wallet])) throw new ProviderException('wallet_unavailable');
            } elseif ($kind==='STRIPE') {
                $account=StripeClient::request($credentials['secret_key'],'account');
                if (($account['object']??null)!=='account' || !is_string($account['id']??null) || !preg_match('/^acct_[A-Za-z0-9]{1,80}$/D',$account['id'])) throw new ProviderException('provider_unavailable');
                // Test accounts can be configured before completing live onboarding.
                if (ProviderSettings::environment($kind,$credentials)==='live' && ($account['charges_enabled']??null)!==true) throw new ProviderException('merchant_restricted');
                StripeClient::request($credentials['secret_key'],'balance');
                return ['account_id'=>$account['id']];
            } else throw new ProviderException('provider_unsupported');
            return [];
        } catch (ProviderException $e) { throw $e; }
        catch (Throwable) { throw new ProviderException('provider_unavailable'); }
    }
}
