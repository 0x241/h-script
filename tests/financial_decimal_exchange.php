<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';

use HScript\Finance\Decimal;
use HScript\Finance\FinancialReconciler;
use HScript\Finance\Money;

$exchangeEvents=[];
function onDecimalExchangeEvent($event, $uid, $params): void {
    global $exchangeEvents;
    if ($event==='Oper' && ($params['oper'] ?? '')==='EX') $exchangeEvents[]=$params;
}

financialReset();
$_currs[2]['cEXIn']='10';
financialAssert((string)opCalcEx(1,2,'99')==='55.00','base source and inverse signed target adjustment');
$_cfg['Bal_RateEUR']='0.333333333333333333'; $_cfg['Bal_RateBTC']='0.3';
$_currs[2]['cEXIn']='0'; $_currs[3]['cNumDec']=2;
financialAssert((string)opCalcExSum(true,2,'1')==='0.33','source intermediate retains original currency scale');
financialAssert((string)opCalcEx(2,3,'1')==='1.10','do not combine two conversions and lose intermediate rounding');
$_currs[2]['cEXOut']='-10'; $_currs[3]['cEXIn']='-10';
financialAssert((string)opCalcEx(2,3,'1')==='0.91','negative corrections with positive factors remain valid');
$_cfg['Bal_RateEUR']='30000'; $_cfg['Bal_RateBTC']='100';
$_currs[2]['cEXOut']='0'; $_currs[3]['cEXIn']='0';
financialAssert((string)opCalcExSum(true,2,'1000')==='30000000.00','unstored base intermediate can exceed storage bound');
financialAssert((string)opCalcEx(2,3,'1000')==='300000.00','final target fits storage despite large intermediate');
$_cfg['Bal_RateEUR']=$_cfg['Bal_RateBTC']='1'.str_repeat('0',35);
financialAssert((string)opCalcEx(2,3,'9999999')==='9999999.00','generated 43-digit intermediate is not reparsed as bounded external input');
financialAssert(strlen((string)opCalcExSum(true,2,'9999999'))>36,'wide exact intermediate remains typed Decimal');

foreach (['0','-1','malformed',NAN,INF] as $bad) {
    $_cfg['Bal_RateEUR']=$bad;
    financialAssert(opCalcEx(1,2,'1')==='ex_rate_not_set','invalid rate refused');
}
$_cfg['Bal_RateEUR']='1';
foreach (['100','101','malformed'] as $bad) {
    $_currs[2]['cEXIn']=$bad;
    financialAssert(opCalcEx(1,2,'1')==='ex_rate_not_set','nonpositive or invalid inverse factor refused');
}
$_currs[2]['cEXIn']='0';
foreach (['-100','-101','malformed'] as $bad) {
    $_currs[2]['cEXOut']=$bad;
    financialAssert(opCalcEx(2,1,'1')==='ex_rate_not_set','nonpositive or invalid direct factor refused');
}
$_currs[2]['cEXOut']='0';
foreach (['0','-1','malformed',NAN] as $bad)
    financialAssert(opCalcEx(1,2,$bad)==='ex_sum_wrong','invalid or nonpositive amount refused');
financialAssert(opCalcEx(1,999,'1')==='psys2_wrong','unknown target refused');
financialAssert(opCalcEx(999,1,'1')==='psys_wrong','unknown source refused');
financialAssert(opCalcEx(1,2,'0.004')==='ex_sum_wrong','rounded zero final quote refused');
financialAssert(opCalcEx(1,2,'0.005') instanceof Money,'positive first target minor unit accepted');

financialReset();
$_currs[1]['cNumDec']=6;
$_cfg['Bal_RateBTC']='3';
financialAssert(opCalcEx(1,3,'1')==='sum_precision','eight-place quote cannot be silently truncated to storage six');
$_cfg['Bal_RateBTC']='0.1';
financialAssert(opCalcEx(1,3,'1000000')==='sum_overflow','final target overflow refused');

financialReset();
$_cfg['Bal_RateEUR']='1'; $_currs[2]['cNumDec']=3;
opChangeBalance(1,2,'0.125',0,0);
$_onload=['DecimalExchange'=>1];
$id=opOperCreate(1,'EX',2,'0.125',['cid2'=>1]);
financialAssert(is_int($id),'exchange to base created');
$params=strToArray((string)financialValue("SELECT oParams FROM Opers WHERE oID=$id"));
financialAssert($params['sum2']==='0.13' && is_string($params['sum2']),'saved quote equals actual target rounding and is a string');
financialAssert(opExchangeQuoteDisplay(['oParams'=>$params,'ocID'=>2])['amount']==='0.13','quote presentation uses target fixed decimal');
$_cfg['Bal_RateEUR']='500'; $_currs[2]['cEXOut']='50';
financialAssert(opOperConfirm(1,$id)===true && opOperComplete(1,$id)===true,'later rate changes do not replace saved quote');
financialWallet(1,2,'0.000000'); financialWallet(1,1,'0.130000');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oOper='EXIN'")==='0.130000','child uses original normalized quote');
financialAssert(($exchangeEvents[0]['course'] ?? null)==='1.0400','event course serialized as exact four-place string');
financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()['exchange_conservation'].') f')===0,'quote conservation after target normalization');
$before=financialFingerprint();
financialAssert(opOperComplete(1,$id)===true && financialFingerprint()===$before,'completed quote replay creates no second effect');

financialReset();
opChangeBalance(1,1,'100',0,0); $_currs[1]['cEXComis']='1'; $_currs[2]['cEXIn']='10';
$id=opOperCreate(1,'EX',1,'100',['cid2'=>2]);
financialAssert(opOperConfirm(1,$id)===true,'exchange reserve');
$before=financialFingerprint(); $db->rejectExchangeChild=true;
financialAssert(is_string(opOperComplete(1,$id)),'child failure propagated');
financialAssert(financialFingerprint()===$before,'child failure rolls back source debit, receipt, history and child');
$db->rejectExchangeChild=false;
financialAssert(opOperComplete(1,$id)===true,'same quote succeeds after child failure');
financialWallet(1,1,'0.000000'); financialWallet(1,2,'55.000000');
financialAssert(financialValue("SELECT oComis FROM Opers WHERE oID=$id")==='1.000000','source fee retained separately from quote');

foreach ([['3','1','sum_precision'],['0.1','1000000','sum_overflow'],['1000','1','ex_sum_wrong']] as [$rate,$amount,$error]) {
    financialReset(); $_currs[1]['cNumDec']=6; $_cfg['Bal_RateBTC']=$rate;
    if ($error==='ex_sum_wrong') $_currs[3]['cNumDec']=2;
    opChangeBalance(1,1,$amount,0,0); $before=financialFingerprint();
    financialAssert(opOperCreate(1,'EX',1,$amount,['cid2'=>3])===$error,'bad final quote refused before source insert');
    financialAssert(financialFingerprint()===$before,'bad quote leaves full financial projection unchanged');
}
echo json_encode(['status'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
