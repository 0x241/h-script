<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use Brick\Math\BigDecimal;
use HScript\Finance\{DecimalOutput,WalletPortfolio};
function aggregateFingerprint(): string
{
    global $pdo;
    $all=['financial'=>financialFingerprint()];
    foreach (['Cfg','IdempotencyKeys','Jobs','AddInfo','PaymentProviders','PaymentProviderCredentials'] as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);$all[$table]=$rows;
    }
    return hash('sha256',serialize($all));
}
financialReset();
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_aggregate_cases.json'),true,32,JSON_THROW_ON_ERROR);
$db->insert('Currs',['cID'=>4,'cCurrID'=>'USD','cName'=>'Disabled historical USD','cDisabled'=>1,'cNumDec'=>6]);
$_vcurrs=$fixture['currencies'];
$insert=$pdo->prepare('INSERT INTO Opers (ouID,ocID,oOper,oState,oSum,oComis,oMemo,oCTS) VALUES (1,?,?,?,?,?,?,?)');
foreach ($fixture['operations'] as $row) $insert->execute(array_values($row));
$base=$_cfg;
foreach ($fixture['statistics'] as $case) {
    $_cfg=$base+$case['config']; // Fixture rates must replace financialReset defaults.
    $_cfg=array_replace($_cfg,$case['config']);
    foreach (['Bal_RateEUR','Bal_RateUSD','Bal_RateBTC'] as $key) if (!array_key_exists($key,$case['config'])) unset($_cfg[$key]);
    $before=aggregateFingerprint();$actual=depoGetStat();
    foreach ($case['expected'] as $name=>$value) {
        if (is_array($value)) {
            financialAssert(array_keys($actual[$name])===array_keys($value),'Only nonempty currency groups are included');
            foreach ($value as $currency=>$expected) {
                financialAssert(is_string($actual[$name][$currency]) && BigDecimal::of($actual[$name][$currency])->isEqualTo($expected),'Python currency SQL predicate/offset oracle');
            }
        } else {
            financialAssert(is_string($actual[$name]) && BigDecimal::of($actual[$name])->isEqualTo($value),'Python valued aggregate and nominal referral/manual GIVE oracle');
            financialAssert(!str_contains(strtolower($actual[$name]),'e'),'Aggregate serialization has no scientific notation');
        }
    }
    financialAssert(json_decode(json_encode($actual,JSON_THROW_ON_ERROR),true)['zin']===$actual['zin'],'Exact aggregate JSON type and value');
    financialAssert(aggregateFingerprint()===$before,'Read does not rewrite any financial/configuration/history/receipt/queue column');
    financialAssert($actual['lastinop']['oSum']==='0.000001' && $actual['lastoutop']['oSum']==='0','Nullable latest operation display has zero interpretation without rewrite');
}
$_cfg=$base+['Depo_S8'=>'1e-18','Depo_S9'=>'exact last in','Depo_S11'=>'9007199254740993.000001','Depo_S12'=>'exact last out'];
$before=aggregateFingerprint();$actual=depoGetStat();
financialAssert($actual['lastinop']['oSum']==='0.000000000000000001' && $actual['lastinop']['uLogin']==='exact last in','Positive exact configured last input');
financialAssert($actual['lastoutop']['oSum']==='9007199254740993.000001','Configured last output beyond binary precision');
financialAssert(aggregateFingerprint()===$before && financialValue("SELECT oSum FROM Opers WHERE oOper='CASHOUT' ORDER BY oCTS DESC LIMIT 1")===null,'SQL NULL remains SQL NULL');
// SQL-loaded enabled currencies preserve original buckets, disabled filtering and exact grouping.
$db->update('Currs',['cDisabled'=>0],'','cID IN (1,2,3)');
$pdo->exec("INSERT INTO Wallets (wuID,wcID,wBal,wLock,wOut) VALUES (1,1,9999999.999999,0.000001,0.000002),(1,2,0.000001,0,0),(1,3,-0.000001,0,0),(1,4,4,0,0)");
$before=aggregateFingerprint();
$rows=$db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID AND wuID=1','*','cDisabled=0',null,'cID'),false,'cID');
$portfolio=WalletPortfolio::project($rows);
financialAssert($portfolio['primary']['balance']==='9999999.999999' && $portfolio['primary']['cCurr']==='USD','Real SQL portfolio exact balance and ID-first grouping');
financialAssert(!isset($portfolio['currencies'][4]) && $portfolio['currencies'][3]['alloc_pct']==='0.0','Enabled-only portfolio and nonpositive allocation');
financialAssert(aggregateFingerprint()===$before,'Portfolio does not normalize or rewrite wallets');
$pdo->exec('DELETE FROM Opers');$_cfg=$base;$_vcurrs=['USD'=>[1],'NONE'=>[]];
$before=aggregateFingerprint();$actual=depoGetStat();
financialAssert($actual['zin']==='0' && $actual['zin2']['USD']==='0' && $actual['lastinop']===[] && $actual['lastoutop']===[],'Empty SQL sums and missing last operations');
financialAssert(aggregateFingerprint()===$before,'Empty statistics are read-only');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'statistics_python_vectors'=>count($fixture['statistics']),'seeded_operations'=>count($fixture['operations'])],JSON_THROW_ON_ERROR)."\n";
