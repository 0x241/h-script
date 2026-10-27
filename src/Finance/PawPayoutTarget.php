<?php

declare(strict_types=1);
namespace HScript\Finance;

use Brick\Math\BigInteger;

/** Explicit provider assets: a symbol alone never identifies a payout network. */
final class PawPayoutTarget
{
    public const ASSETS = [
        'usdt_tron' => ['symbol'=>'USDT','chain'=>'tron','label'=>'USDT · TRON (TRC-20)'],
        'usdc_eth' => ['symbol'=>'USDC','chain'=>'eth','label'=>'USDC · Ethereum (ERC-20)'],
    ];

    public static function valid(string $asset, mixed $address): bool
    {
        if (!is_string($address)) return false;
        if ($asset === 'usdc_eth') return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/D', $address) && !preg_match('/^0x0{40}$/D', $address);
        if ($asset !== 'usdt_tron' || !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $address)) return false;
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $value = BigInteger::zero();
        foreach (str_split($address) as $digit) $value = $value->multipliedBy(58)->plus(strpos($alphabet, $digit));
        $hex = $value->toBase(16);
        if (strlen($hex) !== 50 || !str_starts_with($hex, '41')) return false;
        $bytes = hex2bin($hex);
        return hash_equals(substr(hash('sha256', hash('sha256', substr($bytes,0,21), true), true),0,4), substr($bytes,21));
    }

    public static function select(mixed $asset, array $wallet): array
    {
        if (!is_string($asset) || !isset(self::ASSETS[$asset]) || !self::valid($asset,$wallet[$asset]??null))
            throw new FinancialOperationException('paw_payout_destination_required');
        return ['payout_asset'=>$asset,'acc'=>$wallet[$asset]];
    }

    public static function catalog(array $assets, string $asset): array
    {
        if (!array_is_list($assets) || count($assets)>1000 || !isset(self::ASSETS[$asset])) throw new FinancialOperationException('paw_payout_asset_unavailable');
        $found = [];
        foreach ($assets as $row) if (is_array($row) && ($row['asset']??null)===$asset) $found[]=$row;
        $expected=self::ASSETS[$asset];
        if (count($found)!==1 || ($found[0]['enabled']??null)!==true || ($found[0]['chain']??null)!==$expected['chain']
            || ($found[0]['symbol']??null)!==$expected['symbol'] || ($found[0]['type']??null)!=='token')
            throw new FinancialOperationException('paw_payout_asset_unavailable');
        return ['asset'=>$asset,'chain'=>$expected['chain'],'symbol'=>$expected['symbol'],'type'=>'token'];
    }
}
