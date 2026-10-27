<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Security\SecretCipher;
use PDO;

/** Credentials are deliberately never joined into the cached/public Currs catalog. */
final class PawProviderRepository
{
    public readonly PDO $pdo;
    public function __construct(public readonly Connection $database) { $this->pdo = $database->link; }
    public function current(): ?array
    {
        return $this->one("SELECT * FROM PaymentProviders WHERE ppSlot='PAW' AND ppKind='PAW'");
    }
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one("SELECT * FROM PaymentProviders WHERE ppID=? AND ppKind='PAW'" . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }
    public function connections(int $id, bool $lock = false): array
    {
        $s=$this->pdo->prepare('SELECT * FROM Currs WHERE cProviderID=? ORDER BY cID' . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Key versions are retained for immutable invoice intent; no deletion API exists. */
    public function key(int $id, int $version): string
    {
        $profile=$this->find($id);
        $credential=$this->one('SELECT * FROM PaymentProviderCredentials WHERE ppcProviderID=? AND ppcVersion=?', [$id,$version]);
        if (!$profile || !$credential || $credential['ppcEnvironment'] !== $profile['ppEnvironment'])
            throw new PawProviderException('credential_unavailable');
        $key=SecretCipher::decrypt($credential['ppcEncryptedKey'], self::context($profile['ppPublicID'],$version));
        if ($key === null || $key === '') throw new PawProviderException('credential_unavailable');
        return $key;
    }
    /** New checkouts use a validated profile; retained callbacks use key() directly. */
    public function configuration(array $currency): array
    {
        $p=$this->find((int)$currency['cProviderID']);
        if(!$p)
            throw new PawProviderException('configuration_changed');
        return ['_provider_enabled'=>$p['ppState']==='enabled' && $p['ppValidationState']==='valid' && (int)$currency['cProviderSelected']===1,'key'=>$this->key((int)$p['ppID'],(int)$p['ppCurrentKeyVersion']),
            '_provider_id'=>(int)$p['ppID'],'_provider_public'=>$p['ppPublicID'],
            '_provider_version'=>(int)$p['ppCurrentKeyVersion'],'_provider_environment'=>$p['ppEnvironment']];
    }
    public static function context(string $publicId, int $version): string
    {
        return 'payment-provider:PAW:' . $publicId . ':key:' . $version . ':api-key';
    }
    public function view(?array $profile): array
    {
        if (!$profile) return ['id'=>0,'revision'=>0,'state'=>'new','has_key'=>false,'environment'=>'','validated_at'=>null,'connections'=>[]];
        return ['id'=>(int)$profile['ppID'],'revision'=>(int)$profile['ppRevision'],'state'=>$profile['ppState'],
            'has_key'=>(int)$profile['ppCurrentKeyVersion']>0,'environment'=>$profile['ppEnvironment'],
            'validated_at'=>$profile['ppValidatedAt'] === null ? null : (int)$profile['ppValidatedAt'],
            'connections'=>$this->connections((int)$profile['ppID'])];
    }
    private function one(string $sql, array $values=[]): ?array
    {
        $s=$this->pdo->prepare($sql);$s->execute($values);$row=$s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
