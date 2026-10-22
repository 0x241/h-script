<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{Decimal,PlanInput,StoredPercentage,RequestDecimal};
$checks=0;
function planAssert(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_plan_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['valid'] as $case) planAssert(PlanInput::normalize($case['input'])===$case['expected'],'Independent Python exact plan storage/signed business rules');
foreach ($fixture['invalid'] as $case) {
    try { PlanInput::normalize($case['input']); throw new LogicException('Invalid plan accepted'); }
    catch (InvalidArgumentException $e) { planAssert($e->getMessage()===$case['error'],'Independent field precision/range/business refusal'); }
}
$base=$fixture['valid'][0]['input'];$base['pPerc']='1';$base['pReturn']='100';
foreach (['pBonus','pMinAdd','pCompndMin','pCompndMax','pClComis','pReturn'] as $field) {
    $input=$base;unset($input[$field]);$input['pCompndMin']='0';
    planAssert(Decimal::of(PlanInput::normalize($input)[$field])->isZero(),'Missing optional field remains zero');
}
foreach (['2','2.0','2e0','2.00000000','2,00'] as $alias)
    planAssert(RequestDecimal::normalize($alias,true)==='2' && StoredPercentage::exact(Decimal::of(RequestDecimal::normalize($alias,true)))==='2.00','Storage precision does not change old request fingerprints');
foreach (['2.005','99999.991','100000'] as $bad) {
    try { StoredPercentage::exact(Decimal::of(RequestDecimal::normalize($bad,true))); throw new LogicException('Manual percentage truncated'); }
    catch (InvalidArgumentException|OverflowException) { $checks++; }
}
foreach (['','-0.00','-1',[],true] as $bad) {
    try { RequestDecimal::normalize($bad,true); throw new LogicException('Manual input grammar changed'); }
    catch (InvalidArgumentException) { $checks++; }
}
planAssert(StoredPercentage::exact(Decimal::of('-99999.99'))==='-99999.99','Signed storage lower boundary');
echo json_encode(['status'=>'passed','checks'=>$checks,'valid_python_vectors'=>count($fixture['valid']),'invalid_python_vectors'=>count($fixture['invalid'])],JSON_THROW_ON_ERROR)."\n";
