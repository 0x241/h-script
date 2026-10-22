<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\{Decimal,DecimalOutput,IdempotencyRequest,IdempotencyResult,IdempotencyStore};
financialReset();
$pdo->exec("INSERT INTO Wallets(wuID,wcID,wBal,wLock,wOut) VALUES(1,1,9999999.999999,9999999.999998,0.000001)");
$row=$pdo->query('SELECT wBal,wLock,wOut FROM Wallets')->fetch(PDO::FETCH_ASSOC);
financialAssert(DecimalOutput::stored($row['wBal'])==='9999999.999999','SQL old bucket preserved');
financialAssert(DecimalOutput::stored(Decimal::of($row['wBal'])->plus(Decimal::of($row['wLock']))->plus(Decimal::of($row['wOut'])))==='19999999.999998','SQL wide total exact');
$fingerprint=IdempotencyRequest::fingerprint(['fixture'=>'decimal-output']);
$store=new IdempotencyStore($db);
$legacy=['amount'=>25.0,'fee'=>0.000001,'net_amount'=>24.999999];
$old=$store->execute('fixture:output','numeric-old',1,$fingerprint,fn()=>new IdempotencyResult($legacy,201));
$before=financialFingerprint();$snapshot=financialValue("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='fixture:output'");
$_currs[1]['cNumDec']=8;
$replay=(new IdempotencyStore($db))->execute('fixture:output','numeric-old',1,$fingerprint,function(){ throw new LogicException('Old receipt recomputed'); });
financialAssert($replay->replayed && $replay->data===$old->data,'Old numeric receipt exact');
financialAssert(financialFingerprint()===$before && financialValue("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='fixture:output'")===$snapshot,'Read/replay no financial/snapshot rewrite');
$new=['amount'=>DecimalOutput::stored('25.000001'),'fee'=>DecimalOutput::stored('0.000001'),'net_amount'=>DecimalOutput::stored(Decimal::of('25.000001')->minus(Decimal::of('0.000001')))];
$result=$store->execute('fixture:output','string-new',1,$fingerprint,fn()=>new IdempotencyResult($new,201));
$replay=(new IdempotencyStore($db))->execute('fixture:output','string-new',1,$fingerprint,function(){ throw new LogicException('New receipt recomputed'); });
financialAssert($replay->replayed && $replay->data===$result->data && $result->data===['amount'=>'25.000001','fee'=>'0.000001','net_amount'=>'25.000000'],'New string receipt exact');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'synthetic_read_fixture'=>'wide existing buckets, not a reconciled economic state'],JSON_THROW_ON_ERROR)."\n";
