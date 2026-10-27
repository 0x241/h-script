<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\{Connection,TransactionScope};
use HScript\Security\SecretCipher;
use PDO;

/** Account fields stay in their original currency's encrypted dictionary. */
final class LegacyAccountSettings
{
    public static function definition(string $gateway): ?array
    {
        return match($gateway) {
            'WM'=>['type'=>'pay','column'=>'cParams','variant'=>0,'fields'=>[
                'WMID'=>['label'=>'WMID','secret'=>false]]],
            'YM'=>['type'=>'api','column'=>'cParamsAPI','variant'=>3,'fields'=>[
                'id'=>['label'=>'gateway.field.client_id','secret'=>false],
                'secretpass'=>['label'=>'gateway.field.client_secret','secret'=>true],
                'apipass'=>['label'=>'gateway.field.access_token','secret'=>true]]],
            default=>null,
        };
    }
    public static function currencyFields(string $gateway,string $type,array $fields): array
    {
        if($gateway==='YMC'&&$type==='api')return [];
        $definition=self::definition($gateway);
        return $definition && $definition['type']===$type ? array_diff_key($fields,$definition['fields']) : $fields;
    }
    public static function editCurrencyParams(string $gateway,string $type,array $fields,array $old,array $input,string $prefix): array|string
    {
        $editable=self::currencyFields($gateway,$type,$fields);
        if(!$editable)return $old;
        // Legacy cleanup expects scalar editable fields. Account-owned fields
        // and extension metadata must survive without entering that cleanup.
        $edited=\opEditToCurrParams($editable,array_intersect_key($old,$editable),$input,$prefix);
        return is_array($edited)?array_replace(array_diff_key($old,$editable),$edited):$edited;
    }
    public static function revision(array $row): string
    {
        return hash('sha256',json_encode(array_intersect_key($row,array_flip(['cID','cCID','cProviderID','cMTS','cParams','cParamsSCI','cParamsAPI'])),JSON_THROW_ON_ERROR));
    }
    private static function context(array $row,array $definition): string
    {
        return 'array:'.$definition['variant'].':'.$row['cID'].$row['cCID'].\stampToTime($row['cMTS']);
    }
    private static function read(array $row,array $definition): array
    {
        $payload=(string)$row[$definition['column']];
        if($payload==='')return [];
        if(SecretCipher::isEncrypted($payload)) {
            $json=SecretCipher::decrypt($payload,self::context($row,$definition));
            if($json===null)throw new ProviderException('account_configuration_unreadable');
            try{$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new ProviderException('account_configuration_unreadable');}
        }else{
            $key=$row['cID'].$row['cCID'].\stampToTime($row['cMTS']);
            $plain=\decode1($payload,$key,true,ord($key)%8);
            $data=json_decode($plain,true);
            if(!is_array($data)){
                set_error_handler(static function():never{throw new ProviderException('account_configuration_unreadable');});
                try{$data=unserialize($plain,['allowed_classes'=>false,'max_depth'=>16]);}
                catch(\Throwable){throw new ProviderException('account_configuration_unreadable');}
                finally{restore_error_handler();}
            }
        }
        if(!is_array($data))throw new ProviderException('account_configuration_unreadable');
        $nodes=0;
        $validate=static function(mixed $value,int $depth=0)use(&$validate,&$nodes):void{
            if(++$nodes>4096||$depth>16||is_object($value)||is_resource($value))throw new ProviderException('account_configuration_unreadable');
            if(is_array($value))foreach($value as$key=>$child){
                if(\ReflectionReference::fromArrayElement($value,$key)!==null)throw new ProviderException('account_configuration_unreadable');
                $validate($child,$depth+1);
            }
        };
        $validate($data);
        foreach($definition['fields']as$key=>$field)if(array_key_exists($key,$data)&&!is_string($data[$key]))throw new ProviderException('account_configuration_unreadable');
        return $data;
    }
    public static function view(array $row): ?array
    {
        $definition=self::definition($row['cCID']);if(!$definition)return null;
        $data=self::read($row,$definition);$fields=[];
        foreach($definition['fields']as$key=>$field)$fields[$key]=$field+[
            'value'=>$field['secret']?'':(string)($data[$key]??''),'saved'=>!empty($data[$key])];
        return ['id'=>(int)$row['cID'],'gateway'=>$row['cCID'],'revision'=>self::revision($row),'fields'=>$fields];
    }
    public static function save(Connection $db,int $level,string $kind,array $input): void
    {
        if($level<90)throw new ProviderException('forbidden');
        if(!in_array($kind,['WEBMONEY','YOOMONEY'],true))throw new ProviderException('provider_unsupported');
        if($db->link->inTransaction())throw new ProviderException('configuration_busy');
        foreach(array_keys($input)as$field)if(!is_string($field)||!in_array($field,['currency_id','revision','account_fields','form_complete','legacy_account_save_btn','__Cert'],true))throw new ProviderException('input_invalid');
        if(($input['form_complete']??null)!=='1'||!is_string($input['currency_id']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$input['currency_id'])
            ||!is_string($input['revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['revision'])||!is_array($input['account_fields']??null))throw new ProviderException('input_invalid');
        TransactionScope::run($db->link,static function()use($db,$kind,$input):void{
            LegacyProviderService::lock($db,$kind==='WEBMONEY'?'WM':'YM');
            $q=$db->link->prepare('SELECT * FROM Currs WHERE cID=? FOR UPDATE');$q->execute([$input['currency_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||($row['cArchivedAt']??null)!==null||$row['cProviderID']!==null||ProviderCatalog::kind($row['cCID'])!==$kind||!($definition=self::definition($row['cCID'])))throw new ProviderException('input_invalid');
            if(!hash_equals(self::revision($row),$input['revision']))throw new ProviderException('configuration_stale');
            if(array_diff_key($input['account_fields'],$definition['fields'])||array_diff_key($definition['fields'],$input['account_fields']))throw new ProviderException('input_invalid');
            $data=self::read($row,$definition);$new=$data;
            foreach($definition['fields']as$key=>$field){
                $value=$input['account_fields'][$key];
                if(!is_string($value)||strlen($value)>2048||!preg_match('//u',$value)||preg_match('/[\x00-\x1f\x7f]/',$value))throw new ProviderException('input_invalid');
                if($key==='WMID'&&$value!==''&&!preg_match('/^[0-9]{12}$/D',$value))throw new ProviderException('input_invalid');
                if($field['secret']&&$value==='')continue;
                if($value==='')unset($new[$key]);else$new[$key]=$value;
            }
            if($new===$data)return;
            $encrypted=SecretCipher::encrypt(json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),self::context($row,$definition));
            if($encrypted===null)throw new ProviderException('account_encryption_unavailable');
            // Keep cMTS/context and unrelated ciphertext byte-identical. No mode,
            // receiver, signature key, wallet, operation or financial job is changed.
            $q=$db->link->prepare('UPDATE Currs SET '.$definition['column'].'=? WHERE cID=?');
            if(!$q->execute([$encrypted,$row['cID']]))throw new ProviderException('account_save_failed');
        });
    }
}
