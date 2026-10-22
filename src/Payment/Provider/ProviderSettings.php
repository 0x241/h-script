<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;
use RuntimeException;

/** Provider credentials and accounting choices; networks retain existing gateway IDs. */
final class ProviderSettings
{
    public static function fields(string $kind): array
    {
        return match($kind) {
            'CCAPI'=>['v3_key'=>['API-ключ v3',true]],
            'VOLET'=>['email'=>['E-mail аккаунта',false],'sci_name'=>['Название SCI',false],
                'sci_secret'=>['Пароль SCI',true],'api_name'=>['Название API',false],'api_secret'=>['Пароль API',true]],
            'PAYKASSA'=>['merchant_id'=>['Merchant / SCI ID',false],'merchant_secret'=>['Пароль SCI',true],
                'api_id'=>['API ID',false],'api_secret'=>['Пароль API',true]],
            'STRIPE'=>['secret_key'=>['Secret API key',true],'webhook_secret'=>['Webhook signing secret',true]],
            default=>throw new RuntimeException('provider_unsupported'),
        };
    }
    public static function choices(string $kind): array
    {
        if ($kind==='STRIPE') {
            $choices=[];
            foreach (['USD','EUR','GBP','CAD','AUD','CHF','JPY','NZD','SGD','HKD','ILS','RON','MXN','PLN','BRL','THB'] as $fiat)
                $choices[$fiat]=['gateway'=>'ST','currency'=>$fiat,'name'=>$fiat,'scale'=>$fiat==='JPY'?0:2];
            return $choices;
        }
        $accepted=match($kind) {
            'CCAPI'=>array_values(array_diff(ProviderCatalog::GROUPS['CCAPI'][1],['CCT','CCTU'])),
            'PAYKASSA'=>['PKB','PKE','PKL','PKTU','PKTC'],
            'VOLET'=>ProviderCatalog::GROUPS['VOLET'][1],
            default=>throw new RuntimeException('provider_unsupported'),
        };
        $definitions=(new PaymentManager(new Connection()))->definitions();$choices=[];
        foreach ($accepted as $id) {
            $d=$definitions[$id];$choices[$d[1]]=['gateway'=>$id,'currency'=>$d[1],'name'=>$d[0],'scale'=>$kind==='VOLET' && in_array($d[1],['USD','EUR'],true)?2:6];
        }
        return $choices;
    }
    public static function credentials(string $kind,array $input,array $old=[]): array
    {
        $result=[];
        foreach (self::fields($kind) as $field=>[$label,$secret]) {
            $v=$input[$field]??'';
            if (!is_string($v)) throw new RuntimeException('provider_input_invalid');
            if ($secret && $v==='') $v=$old[$field]??'';
            if ($v==='' || strlen($v)>2048 || !preg_match('//u',$v) || preg_match('/[\x00-\x1f\x7f]/',$v))
                throw new RuntimeException('provider_input_invalid');
            $result[$field]=$v;
        }
        foreach (['merchant_id','api_id'] as $field)
            if (isset($result[$field]) && !preg_match('/^[1-9][0-9]{0,14}$/D',$result[$field])) throw new RuntimeException('provider_input_invalid');
        if ($kind==='CCAPI' && preg_match('/\s/',$result['v3_key'])) throw new RuntimeException('provider_input_invalid');
        if ($kind==='VOLET' && (filter_var($result['email'],FILTER_VALIDATE_EMAIL)===false || mb_strlen($result['sci_name'],'UTF-8')>50))
            throw new RuntimeException('provider_input_invalid');
        if ($kind==='STRIPE' && (!preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9]{8,}$/D',$result['secret_key'])
            || !preg_match('/^whsec_[A-Za-z0-9]{8,}$/D',$result['webhook_secret']))) throw new RuntimeException('provider_input_invalid');
        return $result;
    }
    public static function environment(string $kind,array $credentials): string
    {
        return $kind==='STRIPE' && preg_match('/^(?:sk|rk)_test_/',$credentials['secret_key']) ? 'sandbox' : 'live';
    }
    public static function configuration(string $kind,array $c,array $localApi=[]): array
    {
        return match($kind) {
            'CCAPI'=>['sci'=>['protocol'=>'3','v3_key'=>$c['v3_key']],'api'=>['protocol'=>'3','v3_key'=>$c['v3_key']]],
            'VOLET'=>['sci'=>['name'=>$c['email'],'sci'=>$c['sci_name'],'key'=>$c['sci_secret']],
                'api'=>['name'=>$c['email'],'api'=>$c['api_name'],'apipass'=>$c['api_secret'],'acc'=>$localApi['acc']??'']],
            'PAYKASSA'=>['sci'=>['id'=>$c['merchant_id'],'key'=>$c['merchant_secret']],
                'api'=>['id'=>$c['api_id'],'apipass'=>$c['api_secret'],'shop_id'=>$c['merchant_id']]],
            'STRIPE'=>['sci'=>['secret_key'=>$c['secret_key'],'webhook_secret'=>$c['webhook_secret'],'account_id'=>$c['account_id']??''],
                'api'=>['secret_key'=>$c['secret_key']]],
            default=>throw new RuntimeException('provider_unsupported'),
        };
    }
}
