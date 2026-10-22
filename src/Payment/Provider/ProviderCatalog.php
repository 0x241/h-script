<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;
use RuntimeException;

/** Installed code is executable authority; the database controls availability. */
final class ProviderCatalog
{
    public const GROUPS=[
        'VOLET'=>['Volet',['AC','VEUR','VUST','VUSC','VBTC','VETH','VSOL','VTRX','VXRP','VTON','VLTC']],
        'PAYKASSA'=>['PayKassa',['PKB','PKE','PKL','PKAU','PKAR','PKTU','PKTC']],
        'CCAPI'=>['CryptoCurrencyAPI.net',['CCAB','CCAL','CCAD','CCAG','CCAH','CCAE','CCAM','CCAN','CCAT','CCAX','CCAO','CCAS','CCTU','CCTC','CCT']],
        'PAW'=>['PAW Payments',['PAW']],
        'STRIPE'=>['Stripe',['ST']],
        'COINPAY'=>['CoinPayments',['CP','CPE','CPL','CPR']],
        'WEBMONEY'=>['WebMoney',['WM']],
        'YOOMONEY'=>['YooMoney',['YM','YMC']],
        'BANKWIRE'=>['BankWire',['BW']],
        'SBERBANK'=>['SberBank',['SB']],
    ];
    public const SHARED=['CCAPI','VOLET','PAYKASSA','STRIPE'];
    public function __construct(private Connection $database) {}
    public static function kind(string $gateway): ?string
    {
        foreach (self::GROUPS as $kind=>[$name,$gateways]) if (in_array($gateway,$gateways,true)) return $kind;
        return null;
    }
    public function available(): array
    {
        $rows=$this->database->link->query('SELECT pgCode FROM PaymentGatewayCatalog WHERE pgAvailable=1 ORDER BY pgCode')->fetchAll(\PDO::FETCH_COLUMN);
        $installed=(new PaymentManager($this->database))->definitions();$result=[];
        foreach ($rows as $kind) {
            if (!isset(self::GROUPS[$kind])) continue;
            [$name,$gateways]=self::GROUPS[$kind];
            $gateways=array_values(array_filter($gateways,static fn(string $id): bool=>isset($installed[$id])));
            if ($gateways) $result[$kind]=['name'=>$name,'gateways'=>$gateways];
        }
        return $result;
    }
    /** An existing shared profile is managed at its canonical connection page. */
    public function connectable(): array
    {
        $available=$this->available();
        $slots=$this->database->link->query('SELECT ppSlot FROM PaymentProviders WHERE ppSlot IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
        foreach($slots as $slot)unset($available[$slot]);
        return $available;
    }
    public function requireAvailable(string $kind): void
    {
        if (!isset($this->available()[$kind])) throw new RuntimeException('provider_unavailable');
    }
}
