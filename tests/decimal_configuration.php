<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{CurrencyInput,CalendarInput,AdministrativeDecimal,Decimal};
$checks=0;
function configurationAssert(bool $ok,string $name): void { global $checks;if (!$ok) throw new RuntimeException($name);$checks++; }
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_configuration_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['valid'] as $c) configurationAssert(CurrencyInput::normalize($c['input'])===$c['expected'],'Independent signed currency storage oracle');
foreach ($fixture['invalid'] as $c) {
    try { CurrencyInput::normalize($c['input']);throw new LogicException('Invalid configuration accepted'); }
    catch (InvalidArgumentException $e) { configurationAssert($e->getMessage()===$c['error'],'Explicit precision/range/refusal'); }
}
foreach ($fixture['calendar'] as $c) configurationAssert(CalendarInput::percentage($c['input'])===$c['expected'],'Python nonnegative calendar oracle, no artificial 100 cap');
foreach (['-0.01','0.001','100000','1e9999999',[],true,1.5] as $bad) {
    try { CalendarInput::percentage($bad);throw new LogicException('Calendar invalid accepted'); }
    catch (InvalidArgumentException $e) { configurationAssert($e->getMessage()==='perc_wrong','Calendar validation error'); }
}
foreach ([1.25,INF,NAN,[],true] as $bad) {
    try { AdministrativeDecimal::read($bad);throw new LogicException('Implicit float adapter'); }
    catch (InvalidArgumentException) { $checks++; }
}
configurationAssert(AdministrativeDecimal::stored(AdministrativeDecimal::read('1.2300000000000000000'),7,2)==='1.23','Trailing zero precision is exact');
configurationAssert(CurrencyInput::normalize(['cBalMin'=>'not-saved','cBal'=>'read-only'])['cBalMin']==='not-saved','Unrelated non-saved fields not interpreted as monetary writes');
echo json_encode(['status'=>'passed','checks'=>$checks,'currency_python_vectors'=>count($fixture['valid']),'currency_refusals'=>count($fixture['invalid']),'calendar_python_vectors'=>count($fixture['calendar'])],JSON_THROW_ON_ERROR)."\n";
