<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Cache\{CatalogCache,RedisCache};

financialReset();
$catalogCache = new CatalogCache(new RedisCache(enabled:false));
$_GS['lang']='en'; $_GET=$_POST=[];
$_cfg['Depo__Compnd']=['0','33.330000000000000001','33.33','100'];
$db->update('Plans',['pName'=>'{!en!}<img> exact plan{!ru!}Точный план','pMin'=>'1.005001','pMax'=>'9999999.999999','pBonus'=>'1.23','pPerc'=>'1.23','pNPer'=>7,'pReturn'=>'50'], '', 'pID=1');
$before = financialFingerprint();
$_GET=['sum'=>'1234,567891','cmpd'=>'33.33','plan'=>'-1'];
depoPrepareCalc(); $page=FinancialFixtureView::$pages;
financialAssert($page['calc_sum_value']==='1234.567891' && $page['calc_cmpd_value']==='33.33','Comma amount and exact compound selection');
financialAssert($page['calc_selected_plan']===0 && $page['calc_plan_rows'][0]['min']==='1.005001','Plan fallback and stored principal precision');
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_forecast_cases.json'),true,32,JSON_THROW_ON_ERROR);
$case=array_values(array_filter($fixture['forecasts'],fn($c)=>$c['amount']==='1234.567891' && $c['bonus']==='1.23' && $c['compound']==='33.33' && $c['periods']===7))[0];
financialAssert(array_diff_key($page['calc_result'],['plan'=>true])===$case['expected'],'Actual SQL plan controller Python oracle');
financialAssert(!array_key_exists('calc_plans',$page),'Unused inline numeric script removed');
financialAssert(financialFingerprint()===$before,'Calculator read does not write financial tables');
$_GET=['sum'=>'1e99999999','cmpd'=>'33.330000000000000001'];
depoPrepareCalc();$page=FinancialFixtureView::$pages;
financialAssert($page['calc_sum_value']==='1.005001' && $page['calc_cmpd_value']==='33.330000000000000001','Invalid amount fallback and compound distinction beyond binary precision');
$_cfg['Depo__Compnd']=['bad','-1','101',[],null];$_GET=['sum'=>[],'cmpd'=>'101'];
depoPrepareCalc();$page=FinancialFixtureView::$pages;
financialAssert($page['calc_compnd']===[] && $page['calc_cmpd_value']==='0' && $page['calc_sum_value']==='1.005001','Malformed config/request bounded fallback');
$_GET=[];$db->update('Plans',['pNPer'=>10001],'','pID=1');$before=financialFingerprint();
depoPrepareCalc();
financialAssert(FinancialFixtureView::$pages['calc_error']===true && FinancialFixtureView::$pages['calc_result']===[],'Period bound makes forecast unavailable');
financialAssert(financialFingerprint()===$before,'Rejected forecast has no writes');
$db->update('Plans',['pHidden'=>1],'','pID=1');depoPrepareCalc();
financialAssert(FinancialFixtureView::$pages['calc_result']===[] && FinancialFixtureView::$pages['calc_plan_rows']===[],'No visible plans');
$pdo->exec('UPDATE Plans SET pHidden=0,pNPer=7,pMin=NULL,pMax=NULL,pPerc=NULL,pBonus=NULL,pReturn=NULL WHERE pID=1');
$before=financialFingerprint();depoPrepareCalc();$page=FinancialFixtureView::$pages;
financialAssert($page['calc_sum_value']==='10' && $page['calc_result']['total_return_sum']==='0.00','Nullable historical plan money retains zero interpretation');
financialAssert(financialFingerprint()===$before && financialValue('SELECT pMin FROM Plans WHERE pID=1')===null,'Nullable plan values are not rewritten');

// Actual SQL rows, exact stored precision and one consistent clock per list.
financialReset(); $now=1600000000;
foreach ($fixture['profits'] as $index=>$case) {
    $pid=$db->insert('Plans',['pName'=>'Read fixture','pPer'=>$case['hours'],'pPerc'=>$case['percent'],'pNPer'=>7]);
    $did=$db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>$pid,'dState'=>1,'dZD'=>$case['principal'],
        'dCTS'=>timeToStamp($now-$case['elapsed']), 'dLTS'=>timeToStamp($now-$case['elapsed']),
        'dNTS'=>timeToStamp($now-$case['elapsed']+$case['hours']*3600)]);
    $list=[financialDepo($did)]; $_cfg['UI_NumDec']=$case['scale'];$_currs[1]['cNumDec']=$case['scale'];
    $before=financialFingerprint();opDepoListPrepare($list,$now);
    financialAssert($list[0]['CurrentProfit']===$case['current'],'Python current profit from exact stored SQL principal');
    financialAssert($list[0]['ProfitPerHour']===$case['hourly'],'Python hourly profit at receiving scale');
    financialAssert(financialFingerprint()===$before,'List projection makes no financial writes');
}
$db->update('Plans',['pPer'=>0],'','pID='.$pid);$list=[financialDepo($did)];opDepoListPrepare($list,$now);
financialAssert($list[0]['ProfitPerHour']==='0.00000000','Malformed historical zero period has bounded hourly fallback');
$pdo->exec("UPDATE Plans SET pPer=24,pPerc=NULL WHERE pID=$pid");$pdo->exec("UPDATE Deps SET dZD=NULL WHERE dID=$did");
$before=financialFingerprint();$list=[financialDepo($did)];opDepoListPrepare($list,$now);
financialAssert($list[0]['CurrentProfit']==='0.000000' && $list[0]['ProfitPerHour']==='0.00000000','Nullable historical list money retains zero interpretation');
financialAssert(financialFingerprint()===$before && financialValue('SELECT dZD FROM Deps WHERE dID='.$did)===null,'Nullable principal is not rewritten');
$db->update('Deps',['dState'=>2],'','dID='.$did);$list=[financialDepo($did)];opDepoListPrepare($list,$now);
financialAssert(!isset($list[0]['CurrentProfit']) && $list[0]['PeriodProgress']===100,'Closed deposit does not project active profit');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'list_python_vectors'=>count($fixture['profits']),
    'fixture_scope'=>'synthetic read projections; no settlement or reconciliation claim'],JSON_THROW_ON_ERROR)."\n";
