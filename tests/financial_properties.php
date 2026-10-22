<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Finance\{Decimal,FinancialOperationException,IdempotencyStore,IdempotencyRequest,IdempotencyResult,IdempotencyConflict};

$cases=json_decode(file_get_contents(($argv[1]??getenv('H_SCRIPT_FINANCIAL_PROPERTY_FILE'))?:__DIR__.'/fixtures/financial_property_cases.json'),true,64,JSON_THROW_ON_ERROR);
if (($cases['format']??'')!=='hscript-financial-properties-v1') throw new RuntimeException('Unsupported property fixture');
$context=[];$scenarios=0;
set_exception_handler(static function(Throwable $e) use (&$context): void {
    echo json_encode(['status'=>'failed','reason'=>$e->getMessage(),'replay'=>$context],JSON_THROW_ON_ERROR)."\n";
    exit(1);
});

foreach ($cases['sequences'] as $case) {
    $context=['group'=>'sequence','seed'=>$case['seed'],'scale'=>$case['scale'],'prefix'=>[]];
    financialReset();propertyScale($case['scale']);
    $_cfg['Bal_RateEUR']=$case['rate'];
    foreach ($case['scales'] as $cid=>$scale) {
        $values=['cNumDec'=>$scale,'cEXIn'=>$case['corrections']['in'],'cEXOut'=>$case['corrections']['out']];
        foreach ($case['policies'][$cid] as $kind=>$policy) {
            $values['c'.$kind.'Comis']=$policy['percent'];
            $values['c'.$kind.'ComisMin']=$policy['min'];$values['c'.$kind.'ComisMax']=$policy['max'];
        }
        $db->update('Currs',$values,'','cID=?d',[$cid]);
    }
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    foreach ([1,2,3] as $uid) foreach ([1,2] as $cid) financialAssert(opChangeBalance($uid,$cid,'5000',0,0)===true,'seed model wallet');
    propertyReconcile(6);$priorAudit=6;$store=new IdempotencyStore($db);
    foreach ($case['steps'] as $step) {
        $context['prefix'][]=array_intersect_key($step,array_flip(['index','kind','operation','uid','cid','target','raw']));
        propertyReconcile($priorAudit);$before=propertyFingerprint();
        $request=array_intersect_key($step,array_flip(['kind','uid','cid','target','raw']));$hash=IdempotencyRequest::fingerprint($request);$key='step-'.$step['index'];
        $work=static function() use ($step): IdempotencyResult {
            $params=['uid2'=>$step['target'],'cid2'=>$step['target']];
            $id=FinancialOperationException::check(opOperCreate($step['uid'],$step['operation'],$step['cid'],$step['raw'],$params,'property',false,true));
            $receipt=['date'=>time(),'batch'=>'property-source-'.$id,'sum'=>$step['raw']];
            FinancialOperationException::check(opOperConfirm($step['uid'],$id,$step['operation']==='CASHIN'?$receipt:[],true));
            FinancialOperationException::check($step['kind']==='cancel'
                ? opOperCancel($step['uid'],$id)
                : opOperComplete($step['uid'],$id,in_array($step['operation'],['CASHIN','CASHOUT'],true)?$receipt:[],true));
            return new IdempotencyResult(['id'=>$id],201,$id);
        };
        try {
            $result=$store->execute('fixture:property',$key,$step['uid'],$hash,$work);
            financialAssert($step['error']===null,'oracle expects successful operation');
            $row=$db->fetch1Row($db->select('Opers','oSum,oComis,oState,oParams','oID=?d',[$result->operationId]));
            financialAssert($row['oSum']===$step['amount'] && $row['oComis']===$step['fee'],'independent gross/fee exact SQL6');
            financialAssert((int)$row['oState']===($step['kind']==='cancel'?5:3),'correct terminal state');
            if ($step['operation']==='EX') financialAssert(Decimal::of(strToArray($row['oParams'])['sum2'])->compareTo(Decimal::of($step['quote']))===0,'independent exchange quote');
            $after=propertyFingerprint();propertyReconcile($step['audit_count']);
            $replay=$store->execute('fixture:property',$key,$step['uid'],$hash,static function(){throw new LogicException('Committed property work repeated');});
            financialAssert($replay->replayed && $replay->data===$result->data && $replay->status===$result->status && $replay->operationId===$result->operationId,'original committed response retained');
            financialAssert(propertyFingerprint()===$after,'replay changes no table');propertyReconcile($step['audit_count']);
            try { $store->execute('fixture:property',$key,$step['uid'],IdempotencyRequest::fingerprint($request+['conflict'=>true]),$work);throw new LogicException('Conflicting property request accepted'); }
            catch (IdempotencyConflict) { financialAssert(propertyFingerprint()===$after,'conflict changes no table'); }
            propertyReconcile($step['audit_count']);
        } catch (FinancialOperationException $e) {
            financialAssert($e->getMessage()===$step['error'],'oracle predicts refusal code: '.$e->getMessage());
            financialAssert(propertyFingerprint()===$before,'rejected operation changes no table');propertyReconcile($priorAudit);
        }
        foreach ($step['balances'] as $pair=>$balance) {
            [$uid,$cid]=array_map('intval',explode(':',$pair));financialWallet($uid,$cid,$balance);
        }
        foreach ($step['user_totals'] as $uid=>$total) financialAssert(financialValue('SELECT uBal FROM Users WHERE uID='.(int)$uid)===$total,'independent nominal user total');
        foreach ($step['ledger'] as $cid=>$ledger) {
            $expected=Decimal::of('15000')->plus(Decimal::of($ledger['external_in']))->minus(Decimal::of($ledger['external_out']))
                ->minus(Decimal::of($ledger['fees']))->plus(Decimal::of($ledger['exchange_in']))->minus(Decimal::of($ledger['exchange_out']));
            financialAssert(Decimal::of(financialValue('SELECT SUM(wBal+wLock+wOut) FROM Wallets WHERE wcID='.(int)$cid))->compareTo($expected)===0,'per-currency conservation with declared sources/sinks');
        }
        financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===$step['operation_count'],'exact source/child operation count');
        $priorAudit=$step['audit_count'];$scenarios++;
    }
    // A balanced unauthorized transfer evades uBal equality; the independent model must catch it.
    $original=propertyFingerprint();$pdo->beginTransaction();
    $pdo->exec('UPDATE Wallets SET wBal=wBal-1 WHERE wuID=1 AND wcID=1');$pdo->exec('UPDATE Wallets SET wBal=wBal+1 WHERE wuID=2 AND wcID=1');
    $pdo->exec('UPDATE Users SET uBal=uBal-1 WHERE uID=1');$pdo->exec('UPDATE Users SET uBal=uBal+1 WHERE uID=2');
    financialAssert(financialValue('SELECT wBal FROM Wallets WHERE wuID=1 AND wcID=1')!==$step['balances']['1:1'],'oracle detects balanced unauthorised money move');
    $pdo->rollBack();financialAssert(propertyFingerprint()===$original,'corruption probe rolls back');propertyReconcile($priorAudit);
}

foreach ($cases['deposits'] as $case) {
    $context=['group'=>'deposit','seed'=>$case['seed'],'scale'=>$case['scale'],'prefix'=>[]];
    financialReset();propertyScale($case['scale']);$plan=$case['plan'];
    $_currs[1]['cGIVEComis']=$plan['give_fee'];$_currs[1]['cTAKEComis']=$plan['take_fee'];
    $db->update('Plans',['pMin'=>'1','pMax'=>'9999999','pPerc'=>$plan['percent'],'pBonus'=>$plan['bonus'],'pReturn'=>$plan['return'],
        'pNPer'=>$plan['periods'],'pClComis'=>$plan['close_fee']],'','pID=1');
    financialAssert(opChangeBalance(1,1,'1000',0,0)===true,'deposit model seed');propertyReconcile(1);
    $did=null;$priorAudit=1;$issuance=Decimal::of(0);$burned=Decimal::of(0);
    foreach ($case['steps'] as $step) {
        $context['prefix'][]=$step;propertyReconcile($priorAudit);
        $stale=$step['kind']==='charge'?financialDepo($did):null;
        $result=match($step['kind']) {
            'create'=>opDepoCreate(1,1,$step['amount'],$plan['compound'],1),
            'add'=>opDepoAdd(1,1,$step['amount'],$did),
            'sub'=>opDepoSub(1,1,$step['amount'],$did),
            'charge'=>opDepoCharge($stale),
        };
        if ($step['kind']==='create') { financialAssert(is_int($result),'generated deposit opens');$did=$result; }
        else financialAssert($result===true,'generated deposit transition succeeds: '.json_encode($result));
        financialWallet(1,1,$step['available'],$step['locked']);
        $actual=financialDepo($did);
        foreach (['dZD'=>'principal','dZC'=>'compounded','dZP'=>'profit'] as $field=>$expected) financialAssert($actual[$field]===$step[$expected],'independent deposit '.$field);
        financialAssert((int)$actual['dState']===($step['terminal']?2:1),'generated deposit state');
        $issuance=$issuance->plus(Decimal::of($step['earned']));$burned=$burned->plus(Decimal::of($step['burn']));
        financialAssert(Decimal::of(financialValue('SELECT uBal FROM Users WHERE uID=1'))->compareTo(Decimal::of(1000)->plus($issuance)->minus($burned))===0,'deposit conservation includes issuance/fee/contractual principal return');
        propertyReconcile($step['audit_count']);
        if ($stale) {
            $after=propertyFingerprint();financialAssert(opDepoCharge($stale)===true && propertyFingerprint()===$after,'same accrual boundary replays without effects');propertyReconcile($step['audit_count']);
        }
        $priorAudit=$step['audit_count'];$scenarios++;
    }
}

foreach ($cases['referrals'] as $case) {
    $context=['group'=>'referral','seed'=>$case['seed'],'scale'=>$case['scale'],'source'=>$case['source']];
    financialReset();propertyScale($case['scale']);[$first,$last]=$case['chain'];
    $db->update('Users',['uRef'=>$first],'','uID=1');$db->update('Users',['uRef'=>$last],'','uID=?d',[$first]);$db->update('Users',['uRef'=>0],'','uID=?d',[$last]);
    foreach ($case['active'] as $uid=>$active) $db->update('Users',['uState'=>$active],'','uID=?d',[$uid]);
    foreach ($case['overrides'] as $uid=>$rates) $db->update('AddInfo',['aPerc'=>implode(HS2_NL,$rates)],'','auID=?d',[$uid]);
    propertyReconcile(0);
    financialAssert(_refPayByLevels(1,1,$case['source'],$case['rates'],0,'aPerc')===true,'generated valid acyclic referral chain');
    foreach ($case['rewards'] as $uid=>$reward) {
        $actual=financialValue('SELECT wBal FROM Wallets WHERE wuID='.(int)$uid.' AND wcID=1');
        financialAssert(($actual===false?'0.000000':$actual)===$reward,'independent referral reward per recipient');
    }
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='REF'")===$case['audit_count'],'exact referral operation count');
    propertyReconcile($case['audit_count']);$scenarios++;
}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'seeds'=>$cases['seeds'],'scenarios'=>$scenarios,
    'sequences'=>count($cases['sequences']),'deposit_plans'=>count($cases['deposits']),'referral_trees'=>count($cases['referrals']),
    'known_audit_limitation'=>'F01','scope'=>'generated sequential state machine; concurrent mixed operations remain separate'],JSON_THROW_ON_ERROR)."\n";
