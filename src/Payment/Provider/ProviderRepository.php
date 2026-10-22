<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Security\SecretCipher;
use PDO;
use RuntimeException;

/** Shared immutable credentials stay out of Currs and out of the catalog cache. */
final class ProviderRepository
{
    public readonly PDO $pdo;
    public function __construct(Connection $database) { $this->pdo=$database->link; }
    public function find(int $id,bool $lock=false): ?array
    {
        return $this->one('SELECT * FROM PaymentProviders WHERE ppID=?'.($lock?' FOR UPDATE':''),[$id]);
    }
    public function current(string $kind): ?array { return $this->one('SELECT * FROM PaymentProviders WHERE ppSlot=?',[$kind]); }
    public function connections(int $id,bool $lock=false): array
    {
        $s=$this->pdo->prepare('SELECT * FROM Currs WHERE cProviderID=? ORDER BY cID'.($lock?' FOR UPDATE':''));
        $s->execute([$id]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function context(string $kind,string $publicId,int $version): string
    {
        return 'payment-provider:'.$kind.':'.$publicId.':key:'.$version.':api-key';
    }
    public function credentials(array $profile,int $version): array
    {
        if (!in_array($profile['ppKind'],ProviderCatalog::SHARED,true) || $version<1) throw new RuntimeException('provider_credentials_unavailable');
        $row=$this->one('SELECT * FROM PaymentProviderCredentials WHERE ppcProviderID=? AND ppcVersion=?',[(int)$profile['ppID'],$version]);
        if (!$row || $row['ppcEnvironment']!==$profile['ppEnvironment']) throw new RuntimeException('provider_credentials_unavailable');
        $raw=SecretCipher::decrypt($row['ppcEncryptedKey'],self::context($profile['ppKind'],$profile['ppPublicID'],$version));
        if ($raw===null) throw new RuntimeException('provider_credentials_unavailable');
        try { $credentials=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('provider_credentials_unavailable'); }
        if (!is_array($credentials) || array_is_list($credentials)) throw new RuntimeException('provider_credentials_unavailable');
        return $credentials;
    }
    public function configuration(array $currency,array $localApi=[]): array
    {
        $profile=$this->find((int)$currency['cProviderID']);
        if (!$profile || ProviderCatalog::kind($currency['cCID'])!==$profile['ppKind']) throw new RuntimeException('provider_currency_mismatch');
        $credentials=$this->credentials($profile,(int)($currency['cProviderKeyVersion']??0));
        return ProviderSettings::configuration($profile['ppKind'],$credentials,$localApi)+[
            'profile_id'=>(int)$profile['ppID'],'public_id'=>$profile['ppPublicID'],'key_version'=>(int)$currency['cProviderKeyVersion'],
            'environment'=>$profile['ppEnvironment']];
    }
    private function one(string $sql,array $args): ?array
    {
        $q=$this->pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
}
