<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{Decimal,DecimalOutput,DepositForecast};
use HScript\Template\View;

$checks = 0;
function forecastAssert(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/decimal_forecast_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['forecasts'] as $case) {
    $result = DepositForecast::calculate(Decimal::of($case['amount']),Decimal::of($case['bonus']),Decimal::of($case['percent']),
        Decimal::of($case['returnPercent']),Decimal::of($case['compound']),$case['periods']);
    forecastAssert($result === $case['expected'],'Python forecast oracle');
    foreach ($result as $money) forecastAssert(is_string($money) && preg_match('/^-?\d+\.\d{2}$/D',$money)===1,'Fixed decimal money wire type');
}
foreach ([' 1,005 '=>'1.005','.5'=>'0.5','5.'=>'5','+5e-2'=>'0.05','1e-18'=>'0.000000000000000001'] as $input=>$expected)
    forecastAssert((string)DepositForecast::input($input)===$expected,'Exact numeric ingress');
foreach (['',null,false,[],new stdClass(),INF,NAN,'NaN','1e99999999',str_repeat('9',129),'1e36','1e-19'] as $input) {
    try { DepositForecast::input($input); throw new LogicException('Malformed/overflow input accepted'); }
    catch (InvalidArgumentException|Brick\Math\Exception\MathException) { $checks++; }
}
foreach ([-1,DepositForecast::MAX_PERIODS+1] as $periods) {
    try { DepositForecast::calculate(Decimal::of(1),Decimal::of(0),Decimal::of(1),Decimal::of(100),Decimal::of(0),$periods); throw new LogicException('Unbounded period work'); }
    catch (InvalidArgumentException) { $checks++; }
}
foreach (['-0.01','100.000000000000000001'] as $compound) {
    try { DepositForecast::calculate(Decimal::of(1),Decimal::of(0),Decimal::of(1),Decimal::of(100),Decimal::of($compound),1); throw new LogicException('Compound outside supported range'); }
    catch (InvalidArgumentException) { $checks++; }
}
try { DepositForecast::calculate(Decimal::of('999999999999999999999999999999999999'),Decimal::of(0),Decimal::of(100),Decimal::of(100),Decimal::of(100),2); throw new LogicException('Forecast overflow truncated'); }
catch (OverflowException) { $checks++; }
forecastAssert(DepositForecast::calculate(Decimal::of(1),Decimal::of(0),Decimal::of(0),Decimal::of(100),Decimal::of(0),DepositForecast::MAX_PERIODS)['total_return_sum']==='1.00','Maximum bounded work');
forecastAssert(DecimalOutput::grouped('999999999999999999999999999999999999.995',2)==='1 000 000 000 000 000 000 000 000 000 000 000 000.00','Wide grouping never uses float');
forecastAssert(DecimalOutput::grouped('-1234.005',2)==='-1 234.01','Negative HALF_UP grouping');
forecastAssert(DecimalOutput::grouped('1234.005',2,'')==='1234.01','Exact HTML input value');
$_GS=['lang'=>'en','lang_dir'=>'','mode'=>'']; $_cfg=[];
View::tplRegisterLegacyPlugins();
$property = (new ReflectionClass(View::class))->getProperty('environment');
$environment = $property->getValue(); $environment->setCache(false);
foreach (['depo/calc.box.twig','depo/calc.result.twig'] as $template) $environment->load($template);
forecastAssert($environment->createTemplate('{{ amount|decimal_format(2, " ") }}')->render(['amount'=>'9999999.999999'])==='10 000 000.00','Actual Twig decimal formatting');
forecastAssert(!str_contains($environment->createTemplate('{{ amount|decimal_format(2, "<img>") }}')->render(['amount'=>'1234']),'<img>'),'Twig grouping output escaped');
echo json_encode(['status'=>'passed','checks'=>$checks,'forecast_python_vectors'=>count($fixture['forecasts'])],JSON_THROW_ON_ERROR)."\n";
