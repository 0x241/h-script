<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use Brick\Math\RoundingMode;
use HScript\Finance\{Decimal,DecimalOutput,Money};

$checks=0;
function outputAssert(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks++; }
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_output_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['cases'] as $case) {
    outputAssert(DecimalOutput::display($case['value'],$case['scale'])===$case['expected'],'Python display oracle');
    outputAssert(is_string(json_decode(json_encode(DecimalOutput::display($case['value'],$case['scale'])),true)),'String display wire type');
}
foreach (['',null,false,true,[],new stdClass(),'NaN','INF','1,5','1/2','<script>',str_repeat('9',129),'1e999999999','1e36','1e-19',INF,NAN] as $bad)
    outputAssert(DecimalOutput::display($bad,2)==='0.00','Bounded invalid display fallback');
outputAssert(DecimalOutput::display(12.5,2)==='12.50','Explicit legacy float ingress');
outputAssert(DecimalOutput::display(Decimal::of('1.005'),2)==='1.01','Typed decimal display');
outputAssert(DecimalOutput::display(Money::of('1.005','USD',3,RoundingMode::HalfUp),2)==='1.01','Typed money display');
foreach (['0.000001','9999999.999999','-9999999.999999','19999999.999998'] as $stored)
    outputAssert(DecimalOutput::stored($stored)===$stored,'Existing six-place data preserved');
outputAssert(DecimalOutput::stored(Decimal::of('9999999.999999')->plus(Decimal::of('9999999.999999')))==='19999999.999998','Wide read total');
outputAssert(DecimalOutput::stored(Decimal::of('0.000001')->minus(Decimal::of('0.000002')))==='-0.000001','Negative stored net');
try { DecimalOutput::stored('0.0000001'); throw new LogicException('Precision silently lost'); }
catch (Brick\Math\Exception\RoundingNecessaryException) { $checks++; }
foreach ([-1,9] as $scale) {
    try { DecimalOutput::display(1,$scale); throw new LogicException('Invalid scale accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
$_cfg=['UI_NumDec'=>2];$_currs=[1=>['cNumDec'=>6,'cCurr'=>'{!en!}<img> USD{!ru!}руб.']];$_GS=['lang'=>'en'];
require dirname(__DIR__).'/module/udf.php';
outputAssert(_z('9999999.999999',1)==='9999999.999999','Shared display preserves exact six places');
outputAssert(_z('1.005001',999)==='1.01','Inherited display scale');
outputAssert(_z('1',1,2)==='<b>1.000000</b> <small>&lt;img&gt; USD</small>','Localized escaped currency and bold');
$_GS['lang']='ru';outputAssert(_z('1',1,1)==='1.000000 <small>руб.</small>','Currency locale');
$_currs[1]['cNumDec']='99';outputAssert(_z('1.005',1)==='1.01','Bounded invalid display scale fallback');
echo json_encode(['status'=>'passed','checks'=>$checks,'output_python_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
