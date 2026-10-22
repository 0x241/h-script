<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use Closure;
use Brick\Math\Exception\MathException;
use HScript\Database\TransactionScope;
use HScript\Finance\AdministrativeDecimal;
use HScript\Finance\CurrencyInput;
use HScript\Security\SecretCipher;
use InvalidArgumentException;
use OverflowException;
use PDOException;

/** Phase 2 configures PAW; it never enables a financial gateway or makes a payment. */
final class PawProviderService
{
    public const FIATS=['USD','EUR','GBP','CAD','AUD','CHF','JPY','NZD','SGD','HKD','NGN','KRW','ILS','RON','ARS','INR','IDR','MXN','MYR','TRY','PLN','BRL','THB'];
    private Closure $clients;
    private Closure $invalidate;
    public function __construct(private PawProviderRepository $repository, ?Closure $clients=null, ?Closure $invalidate=null)
    {
        $this->clients=$clients ?? static fn(string $key,int $timeout): PawValidationClient => new PawApiClient($key,$timeout);
        $this->invalidate=$invalidate ?? static function(): void {};
    }
    public function save(int $actorLevel, array $input): array
    {
        $this->authorize($actorLevel);
        $id=self::number($input['profile_id']??null);
        $revision=self::number($input['revision']??null);
        $fiats=self::fiats($input['fiats']??null);
        $enabled=$input['enabled']??null;
        if (!in_array($enabled,['0','1'],true)) throw new PawProviderException('input_invalid');
        $profile=$id>0 ? $this->repository->find($id) : null;
        if (($id>0 && (!$profile || $profile['ppState']==='retired')) || ($profile && (int)$profile['ppRevision']!==$revision)
            || (!$profile && $revision!==0)) throw new PawProviderException('configuration_changed');
        if (!$profile && $this->repository->current()) throw new PawProviderException('configuration_changed');
        $newKey=$input['api_key']??null;
        if (!is_string($newKey)) throw new PawProviderException('invalid_api_key');
        $oldKey=$profile ? $this->repository->key($id,(int)$profile['ppCurrentKeyVersion']) : '';
        $key=$newKey === '' ? $oldKey : $newKey;
        if (strlen($key)<8 || strlen($key)>4096 || preg_match('/[\x00-\x20\x7f]/',$key)) throw new PawProviderException('invalid_api_key');
        $environment=str_starts_with($key,'sk_sandbox_') ? 'sandbox' : 'live';
        if ($profile && $profile['ppEnvironment']!==$environment) throw new PawProviderException('environment_changed');
        if ($this->repository->pdo->inTransaction()) throw new PawProviderException('configuration_busy');
        if (SecretCipher::encrypt('probe','payment-provider-probe')===null) throw new PawProviderException('encryption_unavailable');
        $deadline=microtime(true)+30;
        $client=$this->client($key,$deadline);
        if ($environment==='sandbox') $client->validateSandbox();
        else self::balance($client->getBalance());
        foreach ($fiats as $fiat) self::rates($this->client($key,$deadline)->getRates($fiat),$fiat);
        $pdo=$this->repository->pdo;
        try {
            $result=TransactionScope::run($pdo,function() use ($pdo,$id,$revision,$profile,$key,$oldKey,$environment,$enabled,$fiats): array {
                $now=time();
                if ($id>0) {
                    $locked=$this->repository->find($id,true);
                    if (!$locked || $locked['ppState']==='retired' || (int)$locked['ppRevision']!==$revision
                        || $locked['ppEnvironment']!==$environment) throw new PawProviderException('configuration_changed');
                } else {
                    $publicId=bin2hex(random_bytes(16));
                    $s=$pdo->prepare("INSERT INTO PaymentProviders(ppPublicID,ppKind,ppSlot,ppEnvironment,ppState,ppCreatedAt,ppUpdatedAt) VALUES(?,'PAW','PAW',?,'disabled',?,?)");
                    $s->execute([$publicId,$environment,$now,$now]);
                    $locked=$this->repository->find((int)$pdo->lastInsertId(),true);
                }
                $pid=(int)$locked['ppID'];$version=(int)$locked['ppCurrentKeyVersion'];
                if ($version===0 || !hash_equals($oldKey,$key)) {
                    $version++;
                    $cipher=SecretCipher::encrypt($key,PawProviderRepository::context($locked['ppPublicID'],$version));
                    if ($cipher===null) throw new PawProviderException('encryption_unavailable');
                    $s=$pdo->prepare('UPDATE PaymentProviderCredentials SET ppcRetiredAt=? WHERE ppcProviderID=? AND ppcVersion=?');
                    $s->execute([$now,$pid,$version-1]);
                    $s=$pdo->prepare('INSERT INTO PaymentProviderCredentials(ppcProviderID,ppcVersion,ppcEnvironment,ppcEncryptedKey,ppcCreatedAt) VALUES(?,?,?,?,?)');
                    $s->execute([$pid,$version,$environment,$cipher,$now]);
                }
                $existing=$this->repository->connections($pid,true);
                $byCode=array_column($existing,null,'cCurrID');
                $s=$pdo->prepare('UPDATE Currs SET cProviderSelected=0,cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cProviderID=?');$s->execute([$pid]);
                foreach ($fiats as $fiat) {
                    if (isset($byCode[$fiat])) {
                        $s=$pdo->prepare('UPDATE Currs SET cProviderSelected=1,cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cID=? AND cProviderID=?');
                        $s->execute([(int)$byCode[$fiat]['cID'],$pid]);
                    } else {
                        $s=$pdo->prepare("INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cProviderID,cProviderSelected,cDisabled,cCASHINMode,cCASHOUTMode) VALUES('PAW',?,?,?,2,?,1,1,0,0)");
                        $s->execute([$fiat,'PAW Payments '.$fiat,$fiat,$pid]);
                    }
                }
                $s=$pdo->prepare("UPDATE PaymentProviders SET ppState=?,ppCurrentKeyVersion=?,ppValidationState='valid',ppValidatedAt=?,ppUpdatedAt=?,ppRevision=ppRevision+1 WHERE ppID=?");
                $s->execute([$enabled==='1' ? 'enabled' : 'disabled',$version,$now,$now,$pid]);
                return $this->repository->view($this->repository->find($pid));
            });
        } catch (PDOException $error) {
            if ($error->getCode()==='23000') throw new PawProviderException('configuration_changed');
            throw $error;
        }
        ($this->invalidate)();
        return $result;
    }
    public function disable(int $actorLevel, int $id, int $revision, bool $retire=false): void
    {
        $this->authorize($actorLevel);
        if ($id<=0 || $revision<=0) throw new PawProviderException('input_invalid');
        $pdo=$this->repository->pdo;
        TransactionScope::run($pdo,function() use ($pdo,$id,$revision,$retire): void {
            $p=$this->repository->find($id,true);
            if (!$p || $p['ppState']==='retired' || (int)$p['ppRevision']!==$revision) throw new PawProviderException('configuration_changed');
            $this->repository->connections($id,true);
            $s=$pdo->prepare("UPDATE PaymentProviders SET ppState=?,ppSlot=?,ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?");
            $s->execute([$retire ? 'retired' : 'disabled',$retire ? null : 'PAW',time(),$id]);
            $s=$pdo->prepare('UPDATE Currs SET cDisabled=1,cCASHINMode=0,cCASHOUTMode=0' . ($retire ? ',cProviderSelected=0' : '') . ' WHERE cProviderID=?');$s->execute([$id]);
        });
        ($this->invalidate)();
    }
    /** Ordinary currency policies; provider identity, credentials and native-off gate are immutable here. */
    public function saveCurrency(int $actorLevel, int $currencyId, array $input): void
    {
        $this->authorize($actorLevel);
        $id=self::number($input['profile_id']??null);
        $revision=self::number($input['revision']??null);
        if ($currencyId<=0 || $id<=0 || $revision<=0 || self::number($input['cID']??null)!==$currencyId)
            throw new PawProviderException('input_invalid');
        foreach (['cDisabled'=>'1','cCASHINMode'=>'0','cCASHOUTMode'=>'0'] as $field=>$fixed)
            if (($input[$field]??null)!==$fixed) throw new PawProviderException('input_invalid');
        $values=[];
        foreach (['cName'=>120,'cCurr'=>60] as $field=>$max) {
            $v=$input[$field]??null;
            if (!is_string($v) || trim($v)==='' || mb_strlen($v,'UTF-8')>$max || preg_match('/[\x00-\x1f\x7f]/',$v))
                throw new PawProviderException('input_invalid');
            $values[$field]=$v;
        }
        foreach (['cHidden','cCASHINInt','cCASHOUTInt','cEXInt','cTRInt'] as $field) {
            $v=$input[$field]??'0';
            if (!in_array($v,['0','1'],true)) throw new PawProviderException('input_invalid');
            $values[$field]=$v;
        }
        foreach (['cNumDec'=>6,'cCASHOUTLimitPer'=>2147483647,'cEXMode'=>2,'cTRMode'=>2,
            'cBUYMode'=>2,'cSELLMode'=>2,'cBUY2Mode'=>2,'cSELL2Mode'=>2,'cGIVEMode'=>2,'cTAKEMode'=>2] as $field=>$max) {
            $v=self::number($input[$field]??null);
            if ($v>$max) throw new PawProviderException('input_invalid');
            $values[$field]=$v;
        }
        // CurrencyInput returns the complete, bounded monetary whitelist only
        // when given an empty base; no untrusted column names reach SQL.
        $money=[];
        foreach (CurrencyInput::normalize([]) as $field=>$zero) $money[$field]=$input[$field]??null;
        try {
            $values+=CurrencyInput::normalize($money);
            $values['cBalMin']=AdministrativeDecimal::stored(AdministrativeDecimal::read($input['cBalMin']??null),13,6);
        } catch (InvalidArgumentException|OverflowException|MathException $e) { throw new PawProviderException('currency_decimal_invalid'); }
        $pdo=$this->repository->pdo;
        if ($pdo->inTransaction()) throw new PawProviderException('configuration_busy');
        TransactionScope::run($pdo,function() use ($pdo,$currencyId,$id,$revision,$values): void {
            $profile=$this->repository->find($id,true);
            if (!$profile || $profile['ppState']==='retired' || (int)$profile['ppRevision']!==$revision)
                throw new PawProviderException('configuration_changed');
            $connections=$this->repository->connections($id,true);
            if (!in_array($currencyId,array_map(static fn(array $c): int=>(int)$c['cID'],$connections),true))
                throw new PawProviderException('configuration_changed');
            $columns=array_map(static fn(string $field): string=>$field.'=?',array_keys($values));
            $s=$pdo->prepare('UPDATE Currs SET '.implode(',',$columns).',cMTS=?,cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cID=? AND cProviderID=?');
            $s->execute([...array_values($values),gmdate('YmdHis'),$currencyId,$id]);
            $s=$pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');
            $s->execute([time(),$id]);
        });
        ($this->invalidate)();
    }
    public static function number(mixed $v): int
    {
        if (!is_string($v) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$v) || (int)$v>4294967295)
            throw new PawProviderException('input_invalid');
        return (int)$v;
    }
    private function authorize(int $level): void { if ($level<90) throw new PawProviderException('forbidden'); }
    private function client(string $key,float $deadline): PawValidationClient
    {
        $left=(int)floor($deadline-microtime(true));
        if ($left<1) throw new PawProviderException('provider_unavailable');
        return ($this->clients)($key,min(5,$left));
    }
    private static function fiats(mixed $fiats): array
    {
        if (!is_array($fiats) || !array_is_list($fiats) || count($fiats)<1 || count($fiats)>count(self::FIATS)) throw new PawProviderException('fiat_unsupported');
        foreach ($fiats as $v) if (!is_string($v) || !in_array($v,self::FIATS,true)) throw new PawProviderException('fiat_unsupported');
        if (count(array_unique($fiats))!==count($fiats)) throw new PawProviderException('input_invalid');
        sort($fiats,SORT_STRING);return $fiats;
    }
    private static function balance(array $balance): void
    {
        $keys=array_keys($balance);sort($keys);
        if ($keys!==['available','total_income','total_payouts']) throw new PawProviderException('provider_unavailable');
        foreach ($balance as $v) if ((!is_int($v) && !is_float($v)) || (is_float($v) && !is_finite($v)) || $v<0) throw new PawProviderException('provider_unavailable');
    }
    private static function rates(array $response,string $base): void
    {
        if (($response['base']??null)!==$base || !is_array($response['rates']??null) || count($response)!==2 || count($response['rates'])<1 || count($response['rates'])>1000) throw new PawProviderException('provider_unavailable');
        $usable=false;
        foreach ($response['rates'] as $symbol=>$price) {
            if (!is_string($symbol) || !preg_match('/^[a-z0-9_-]{1,64}$/D',$symbol)) throw new PawProviderException('provider_unavailable');
            if ($price===null) continue;
            if (!is_string($price) || !preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,18})?$/D',$price) || !preg_match('/[1-9]/',$price)) throw new PawProviderException('provider_unavailable');
            $usable=true;
        }
        if (!$usable) throw new PawProviderException('provider_unavailable');
    }
}
