<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{Decimal,DecimalOutput,ReadAmount,WalletPortfolio};
$checks=0;
function aggregateAssert(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_aggregate_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['portfolios'] as $case) {
    aggregateAssert(WalletPortfolio::project($case['input'])===$case['expected'],'Independent Python portfolio grouping/order/exact totals/signed ratios');
}
foreach ($fixture['displays'] as $case) {
    aggregateAssert(DecimalOutput::display($case['value'],$case['scale'])===$case['expected'],'Python 24-place valued sum display oracle');
}
foreach ([null,false,true,[],new stdClass(),'', 'invalid','1,2','1e9999999','1e36','1e-19',INF,NAN] as $bad)
    aggregateAssert(ReadAmount::stored($bad)==='0.000000','Bounded missing/malformed read fallback');
foreach (['+1e-18'=>'0.000000000000000001',' .5 '=>'0.5','5.'=>'5','-1.25e2'=>'-125'] as $input=>$expected)
    aggregateAssert((string)ReadAmount::decimal($input)===$expected,'Exact scientific legacy read ingress');
aggregateAssert((string)ReadAmount::decimal(12.5)==='12.5','Explicit finite float ingress adapter');
aggregateAssert(ReadAmount::stored('19999999.999998')==='19999999.999998','Aggregate exceeds individual row range without truncation');
try { ReadAmount::stored('0.0000001'); throw new LogicException('Stored aggregate silently rounded'); }
catch (Brick\Math\Exception\RoundingNecessaryException) { $checks++; }
aggregateAssert(DecimalOutput::display('1000000000000000000000000000000000000.005',2)==='1000000000000000000000000000000000000.01','Wide fixed aggregate display');
aggregateAssert(DecimalOutput::display('1.005000000000000000000001',2)==='1.01','No premature rounding of valued aggregates');
aggregateAssert(DecimalOutput::display('1.004999999999999999999999',2)==='1.00','Avoid double rounding at display boundary');
foreach ([str_repeat('9',81),'0.'.str_repeat('0',36).'1','1e36','1e-19'] as $bad)
    aggregateAssert(DecimalOutput::display($bad,2)==='0.00','Computed display and scientific expansion stay bounded');
aggregateAssert(WalletPortfolio::project([])===['currencies'=>[],'groups'=>[],'primary'=>false],'Empty enabled currency portfolio');
$rows=[1=>['cCurrID'=>'ZZZ','wBal'=>'9007199254740993.000001'],2=>['cCurrID'=>'AAA','wBal'=>'9007199254740993.000002']];
aggregateAssert(WalletPortfolio::project($rows)['groups'][0]['cCurr']==='AAA','Adjacent balances above binary integer precision sort exactly');
$rows[1]['wBal']='9007199254740993.000002';
aggregateAssert(WalletPortfolio::project($rows)['groups'][0]['cCurr']==='AAA','True monetary tie sorts by currency code');
echo json_encode(['status'=>'passed','checks'=>$checks,'portfolio_python_vectors'=>count($fixture['portfolios']),'valued_display_python_vectors'=>count($fixture['displays'])],JSON_THROW_ON_ERROR)."\n";
