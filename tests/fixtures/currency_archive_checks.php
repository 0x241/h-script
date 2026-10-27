<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('H_SCRIPT_FINANCIAL_TEST')!=='1'||getenv('DB_NAME')!=='fixture')exit(64);
function financialArchivedReplay(string$gateway,int$cid,array$verified):void
{
 global$db,$pdo,$_currs;
 HScript\Payment\Provider\CurrencyArchiveSchema::ensure($db);
 $original=$db->fetch1Row($db->select('Currs','*','cID=?d',[$cid]));
 HScript\Database\TransactionScope::run($pdo,static function()use($db,$cid):void{$row=$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=?d FOR UPDATE',[$cid]));HScript\Payment\Provider\CurrencyArchive::archive($db,$row);});
 $snapshot=static function()use($pdo):string{$out=[];foreach(['Currs','Users','Wallets','Opers','Hist','Jobs','IdempotencyKeys']as$t)$out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($out));};
 $before=$snapshot();$result=HScript\Finance\GatewaySettlement::settle($db,$gateway,$cid,$verified);financialAssert($result->replayed&&$result->data['settled']&&$snapshot()===$before,'archived authenticated replay is strictly read-only '.$gateway);
 $q=$pdo->prepare("SELECT * FROM IdempotencyKeys WHERE ikNamespace=? AND ikOperationID=?");$q->execute(['gateway:'.strtolower($gateway).':currency:'.$cid.':settled',(int)$verified['tag']]);$proof=$q->fetch(PDO::FETCH_ASSOC);financialAssert((bool)$proof,'permanent completed proof exists '.$gateway);
 $pdo->prepare('DELETE FROM IdempotencyKeys WHERE ikID=?')->execute([$proof['ikID']]);$before=$snapshot();
 try{HScript\Finance\GatewaySettlement::settle($db,$gateway,$cid,$verified);throw new LogicException('Archive backfilled receipt');}catch(HScript\Finance\CallbackValidationException$e){financialAssert($e->reason==='archived_callback_reconciliation_required'&&$snapshot()===$before,'missing archived receipt cannot be created '.$gateway);}
 $keys=array_keys($proof);$pdo->prepare('INSERT INTO IdempotencyKeys('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($proof));
 HScript\Database\TransactionScope::run($pdo,static function()use($db,$cid):void{HScript\Payment\Provider\CurrencyArchive::restore($db,$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=?d FOR UPDATE',[$cid])));});
 $fields=['cDisabled','cHidden','cProviderSelected'];foreach(HScript\Payment\Provider\CurrencyArchiveSchema::MODES as$mode)$fields[]='c'.$mode.'Mode';
 $pdo->prepare('UPDATE Currs SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).' WHERE cID=?')->execute([...array_map(static fn($f)=>$original[$f],$fields),$cid]);
 $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
}
