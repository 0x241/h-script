<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Payment\Provider\{LegacyAccountSettings as A,ProviderException};
putenv('APP_DATA_KEY=synthetic-account-settings-key');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
financialReset();
$snapshot=static function()use($pdo):string{$data=[];foreach(['Users','Wallets','Opers','Hist','Deps','Jobs','Cfg','PaymentProviders','PaymentProviderCredentials','IdempotencyKeys']as$t){$rows=array_map('serialize',$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$data[$t]=$rows;}return hash('sha256',serialize($data));};
$read=static function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM Currs WHERE cID=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);};
$reject=static function(callable $fn,string $reason):void{try{$fn();throw new LogicException('Expected '.$reason);}catch(ProviderException $e){financialAssert($e->reason===$reason,'reject '.$reason);}};
$ids=[];
foreach(['WM'=>'USD','YM'=>'RUB','YMC'=>'RUB']as$gateway=>$fiat){
    $id=$db->insert('Currs',['cCID'=>$gateway,'cCurrID'=>$fiat,'cName'=>'Synthetic '.$gateway,'cDisabled'=>1]);$ids[$gateway]=$id;
    $time=time()-30;$key=$id.$gateway.$time;
    $db->update('Currs',['cMTS'=>timeToStamp($time),'cParams'=>encodeArrayToStr(['WMID'=>'123456789012','acc'=>$gateway==='WM'?'Z123456789012':'410111222333444','preserved'=>'pay'],$key),
        'cParamsSCI'=>encodeArrayToStr(['key'=>'synthetic-original-receipt-key','timezone'=>'UTC','acc'=>'410111222333444'],$key,2),
        'cParamsAPI'=>encodeArrayToStr(['id'=>'original-client','secretpass'=>'synthetic-client-secret','apipass'=>'synthetic-access-token','preserved'=>'api'],$key,3)],'','cID=?d',[$id]);
}
$before=$snapshot();$original=array_map($read,$ids);
$input=static fn(array $row,array $values):array=>['currency_id'=>(string)$row['cID'],'revision'=>A::revision($row),'form_complete'=>'1','account_fields'=>$values];
$wm=$read($ids['WM']);$request=$input($wm,['WMID'=>'987654321012']);
$reject(static fn()=>A::save($db,89,'WEBMONEY',$request),'forbidden');
foreach([$request+['assets'=>['USD']],array_replace($request,['form_complete'=>'0']),array_replace($request,['account_fields'=>[]]),array_replace($request,['account_fields'=>['WMID'=>'123']]),array_replace($request,['account_fields'=>['WMID'=>'987654321012','acc'=>'different']]),$request+['legacy_add_btn'=>'1']]as$bad)$reject(static fn()=>A::save($db,90,'WEBMONEY',$bad),'input_invalid');
$reject(static fn()=>A::save($db,90,'YOOMONEY',$request),'input_invalid');
financialAssert($original===array_map($read,$ids)&&$snapshot()===$before,'all invalid account requests leave everything exact');
A::save($db,90,'WEBMONEY',$request);$saved=$read($ids['WM']);
financialAssert(array_diff_assoc($saved,$wm)===['cParams'=>$saved['cParams']],'WM modifies target cipher only');
opDecodeCurrParams($saved,$pay,$sci,$api);financialAssert($pay['WMID']==='987654321012'&&$pay['acc']==='Z123456789012'&&$pay['preserved']==='pay','WMID change preserves wallet and unknown fields');
$reject(static fn()=>A::save($db,90,'WEBMONEY',$request),'configuration_stale');
$current=A::view($saved);financialAssert($current['fields']['WMID']['value']==='987654321012','WMID on common view');
$ym=$read($ids['YM']);$request=$input($ym,['id'=>'updated-client','secretpass'=>'','apipass'=>'']);A::save($db,90,'YOOMONEY',$request);$saved=$read($ids['YM']);
financialAssert(array_diff_assoc($saved,$ym)===['cParamsAPI'=>$saved['cParamsAPI']],'YM only API cipher changes, receiver/SCI/stamp exact');
opDecodeCurrParams($saved,$pay,$sci,$api);financialAssert($api===['id'=>'updated-client','secretpass'=>'synthetic-client-secret','apipass'=>'synthetic-access-token','preserved'=>'api'],'blank secrets retain original values');
$public=A::view($saved);financialAssert($public['fields']['apipass']['value']===''&&$public['fields']['apipass']['saved']&&$public['fields']['secretpass']['value']===''&&!str_contains(json_encode($public),'synthetic-access-token'),'stored secrets never returned');
financialAssert(A::definition('YMC')===null&&A::view($read($ids['YMC']))===null,'card variant has no unsupported API form');
financialAssert(A::currencyFields('YMC','api',['id'=>[],'apipass'=>[]])===[]&&A::editCurrencyParams('YMC','api',['id'=>[],'apipass'=>[]],['extension'=>['nested'=>'preserve']],[],'PAPI')===['extension'=>['nested'=>'preserve']],'unsupported card API hidden and old dictionary retained');
financialAssert(A::currencyFields('WM','pay',['WMID'=>[], 'acc'=>[]])===['acc'=>[]]&&A::currencyFields('YM','api',['id'=>[],'secretpass'=>[],'apipass'=>[]])===[],'moved fields excluded from currency GET and edits');
$nested=['id'=>'client','extension'=>['nested'=>'preserve']];
set_error_handler(static function(int $level,string $message):never{throw new RuntimeException($message);});
try{
    financialAssert(A::editCurrencyParams('WM','api',[],['extension'=>['nested'=>'preserve']],[],'PAPI')===['extension'=>['nested'=>'preserve']],'empty adapter fields skip legacy cleanup');
    financialAssert(A::editCurrencyParams('YM','api',['id'=>[],'secretpass'=>[],'apipass'=>[]],$nested,[],'PAPI')===$nested,'fully moved API bypasses scalar cleanup');
    financialAssert(A::editCurrencyParams('WM','pay',['WMID'=>[],'acc'=>['Account','Z\\d{12}']],['WMID'=>'123456789012','acc'=>'Z123456789012','extension'=>['nested'=>'preserve']],['acc'=>'Z987654321012'],'P')===['WMID'=>'123456789012','extension'=>['nested'=>'preserve'],'acc'=>'Z987654321012'],'partial wallet edit preserves untouched extension and WMID');
}finally{restore_error_handler();}
$unchanged=$read($ids['YM']);A::save($db,90,'YOOMONEY',$input($unchanged,['id'=>'updated-client','secretpass'=>'','apipass'=>'']));financialAssert($unchanged===$read($ids['YM']),'exact noop keeps all cipher bytes');
putenv('APP_DATA_KEY');$reject(static fn()=>A::save($db,90,'WEBMONEY',$input($read($ids['WM']),['WMID'=>'111111111111'])),'account_configuration_unreadable');putenv('APP_DATA_KEY=synthetic-account-settings-key');
financialAssert($snapshot()===$before,'settings never alter financial or provider history');
$ym=$read($ids['YM']);$db->update('Currs',['cParamsSCI'=>'v2:unreadable-unrelated-receipt-cipher'],'','cID=?d',[$ids['YM']]);$broken=$read($ids['YM']);
A::save($db,90,'YOOMONEY',$input($broken,['id'=>'next-client','secretpass'=>'','apipass'=>'']));financialAssert($read($ids['YM'])['cParamsSCI']===$broken['cParamsSCI'],'unrelated malformed cipher is untouched');
$db->update('Currs',['cParamsAPI'=>'v2:broken-target'],'','cID=?d',[$ids['YM']]);$broken=$read($ids['YM']);$reject(static fn()=>A::save($db,90,'YOOMONEY',$input($broken,['id'=>'bad','secretpass'=>'','apipass'=>''])),'account_configuration_unreadable');financialAssert($read($ids['YM'])===$broken,'malformed target refuses without writes');
$db->update('Currs',['cParamsSCI'=>$ym['cParamsSCI'],'cParamsAPI'=>$ym['cParamsAPI']],'','cID=?d',[$ids['YM']]);
$wm=$read($ids['WM']);$key=$wm['cID'].$wm['cCID'].stampToTime($wm['cMTS']);$db->update('Currs',['cParams'=>encode1(json_encode(['WMID'=>'123456789012','acc'=>'Z123456789012']),$key,true,ord($key)%8)],'','cID=?d',[$ids['WM']]);
$legacy=$read($ids['WM']);putenv('APP_DATA_KEY');$reject(static fn()=>A::save($db,90,'WEBMONEY',$input($legacy,['WMID'=>'222222222222'])),'account_encryption_unavailable');financialAssert($read($ids['WM'])===$legacy,'no legacy encryption fallback');putenv('APP_DATA_KEY=synthetic-account-settings-key');
A::save($db,90,'WEBMONEY',$input($legacy,['WMID'=>'222222222222']));financialAssert(str_starts_with($read($ids['WM'])['cParams'],'v2:'),'legacy account target upgraded without rewriting context');
// Invalid legacy dictionaries, object graphs and non-string known fields fail closed.
$valid=$read($ids['WM']);$key=$valid['cID'].$valid['cCID'].stampToTime($valid['cMTS']);
$alias='123456789012';$aliases=['WMID'=>&$alias,'extension'=>&$alias];
foreach([serialize($aliases),serialize(['WMID'=>'123456789012']).'trailing',serialize(['WMID'=>['not-a-string']]),serialize(['unknown'=>new stdClass()]),json_encode(['WMID'=>[]])]as$plain){
    $db->update('Currs',['cParams'=>encode1($plain,$key,true,ord($key)%8)],'','cID=?d',[$ids['WM']]);$bad=$read($ids['WM']);
    $reject(static fn()=>A::save($db,90,'WEBMONEY',$input($bad,['WMID'=>'123456789012'])),'account_configuration_unreadable');financialAssert($read($ids['WM'])===$bad,'malformed legacy target unchanged');
}
$db->update('Currs',['cParams'=>$valid['cParams']],'','cID=?d',[$ids['WM']]);
$wm=$read($ids['WM']);$pdo->exec("CREATE TRIGGER legacy_account_fault BEFORE UPDATE ON Currs FOR EACH ROW BEGIN IF OLD.cCID='WM' AND NEW.cParams<>OLD.cParams THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic account update failure'; END IF; END");
try{A::save($db,90,'WEBMONEY',$input($wm,['WMID'=>'333333333333']));throw new LogicException('Expected SQL failure');}catch(PDOException){financialAssert($read($ids['WM'])===$wm&&$snapshot()===$before,'real update failure rolls back with no financial writes');}finally{$pdo->exec('DROP TRIGGER legacy_account_fault');}
echo 'Legacy account SQL: '.$assertions." checks passed\n";
