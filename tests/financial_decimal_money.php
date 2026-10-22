<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';

use HScript\Finance\Money;
use HScript\Finance\Decimal;

financialReset();
$_currs[1]['cTRComis']='1';
financialAssert((string)opCalcComis(1,'TR','123.499950',false)==='1.24','preserve two-stage commission rounding');
$_currs[1]['cTRComisMin']='2'; $_currs[1]['cTRComisMax']='1';
financialAssert((string)opCalcComis(1,'TR','10',false)==='2.00','legacy min precedence when min exceeds max');
$_currs[1]['cTRComis']='-10'; $_currs[1]['cTRComisMin']='0'; $_currs[1]['cTRComisMax']='0';
financialAssert((string)opCalcComis(1,'TR','10',false)==='0.00','legacy negative configured percent clamped to minimum');
$_currs[1]['cTRComis']='0.10';
financialAssert(opCalcComis(1,'TR','0.01',false) instanceof Money,'zero fee remains successful typed value');
$_currs[1]['cTRComis']='malformed'; $before=financialFingerprint();
financialAssert(opOperCreate(1,'TR',1,'10',['uid2'=>2])==='sum_wrong','malformed fee policy refused');
financialAssert(financialFingerprint()===$before,'malformed fee policy creates no operation/effects');
financialReset();
$info=opOperCreateInfo(1,'BONUS',1,'1.000001',['comis'=>'1']);
financialAssert(is_int($info),'informational receipt recorded');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$info")==='1.000001','informational receipt retains existing six-place semantics');
financialAssert(financialValue("SELECT oComis FROM Opers WHERE oID=$info")==='0.010000','informational commission still rounds to currency scale');

financialReset(); opChangeBalance(1,1,'100.00',0,0);
$_currs[1]['cTRComis']='10'; $_currs[1]['cTRComisMax']='3';
$id=opOperCreate(1,'TR',1,'100',['uid2'=>2,'comis'=>'2'],'',true);
financialAssert(is_int($id),'supplemental fee transfer created');
financialAssert(financialValue("SELECT oComis FROM Opers WHERE oID=$id")==='5.000000','supplemental commission added after clamp');
financialWallet(1,1,'0.000000'); financialWallet(2,1,'95.000000');
financialAssert(financialValue('SELECT SUM(uBal) FROM Users')==='95.000000','pair plus exact fees conserves stored money');
$before=financialFingerprint();
financialAssert(opOperComplete(1,$id)===true && financialFingerprint()===$before,'typed fee transfer exact replay no changes');

financialReset();
foreach (['0.00000001','9999999.99999999'] as $bad) {
    $before=financialFingerprint();
    financialAssert(opOperCreate(1,'CASHIN',3,$bad,[],'',false,true)==='sum_precision','operation rejects unavailable storage precision');
    financialAssert(financialFingerprint()===$before,'precision rejection no operation/financial effects');
}
$before=financialFingerprint();
financialAssert(opOperCreate(1,'CASHIN',1,'9999999.9999995',[],'',false,true)==='sum_overflow','operation rounded overflow before insert');
financialAssert(financialFingerprint()===$before,'overflow rejection no effects');
financialAssert(opChangeBalance(1,3,'0.00000100',0,0)===true,'six-place amount on eight-place currency allowed');
financialWallet(1,3,'0.000001');
financialAssert(opChangeBalance(1,3,'0.00000001',0,0)==='bal_precision','bucket eighth-place delta refused');
financialWallet(1,3,'0.000001');

financialReset();
$_currs[1]['cNumDec']=6;
financialAssert(opChangeBalance(1,1,'9999999.999999',0,0)===true,'maximum balance exact');
financialAssert(opChangeBalance(1,1,'-9999999.999998',0,0)===true,'large subtraction keeps small exact remainder');
financialWallet(1,1,'0.000001');
financialAssert(opChangeBalance(1,1,'-0.000001',0,0)===true,'last minor unit withdrawn exactly');
financialWallet(1,1,'0.000000');

foreach ([['9999999.999999','0.000001'],['0','10000000']] as [$initial,$delta]) {
    financialReset(); $_currs[1]['cNumDec']=6;
    if ($initial!=='0') opChangeBalance(1,1,$initial,0,0);
    $before=financialFingerprint();
    financialAssert(opChangeBalance(1,1,$delta,0,0)==='bal_overflow','bucket/user overflow refused');
    financialAssert(financialFingerprint()===$before,'overflow preserves wallet, user and history');
}
financialReset(); $_currs[1]['cNumDec']=6;
opChangeBalance(1,1,'9999999.999999',0,0); $before=financialFingerprint();
financialAssert(opChangeBalance(1,2,'0.01',0,0)==='bal_overflow','nominal user aggregate overflow refused');
financialAssert(financialFingerprint()===$before,'aggregate overflow does not create second wallet');
financialReset(); $_currs[1]['cNumDec']=2;
$db->insert('Wallets',['wuID'=>1,'wcID'=>1,'wBal'=>'1.000001']); $db->update('Users',['uBal'=>'1.000001'],'','uID=?d',[1]);
financialAssert(opChangeBalance(1,1,'0.01',0,0)===true,'existing sub-currency stored money retained');
financialWallet(1,1,'1.010001');
foreach ([INF,NAN,'malformed',[],true] as $bad) {
    $before=financialFingerprint();
    financialAssert(opChangeBalance(1,1,$bad,0,0)==='bal_wrong','invalid delta refused');
    financialAssert(financialFingerprint()===$before,'invalid delta no effects');
}
echo json_encode(['status'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
