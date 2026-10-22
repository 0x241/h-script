<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\DepositSummary;
use HScript\Payment\{GatewayAmount,GatewayBalance};
use HScript\Telemetry\{PublicStats,TelemetryAmount,TelemetryPayloadValidator,TelemetryValidationException};
$checks=0;
function closureAssert(bool $value,string $message): void { global $checks;if (!$value) throw new RuntimeException($message);$checks++; }
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_closure_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($cases['gateway'] as $case) {
    $actual=!empty($case['checkout']) ? GatewayAmount::checkout($case['value'],'USD',$case['scale']) : GatewayAmount::exact($case['value'],'TEST',$case['scale']);
    closureAssert($actual===$case['expected'],'Python transport oracle');
}
foreach ([0,'0','-1','1e-6','1,5',' 1','1000000000000',0.5,[],null,true,INF,NAN,'1.001'] as $bad) {
    try { GatewayAmount::exact($bad,'USD',2);throw new LogicException('Invalid wire amount accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
foreach (['0.004','999999999999.995'] as $bad) {
    try { GatewayAmount::checkout($bad,'USD',2);throw new LogicException('Zero/overflow checkout accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
foreach ($cases['summary'] as $case) {
    $actual=DepositSummary::project($case['sum'],$case['plan']);
    foreach ($actual as $field=>$value) closureAssert(\Brick\Math\BigDecimal::of($value)->isEqualTo($case['expected'][$field]),'Independent simple preview oracle');
}
closureAssert(DepositSummary::project('-1',['pPerc'=>'10','pNPer'=>2])['summary_return']==='0.00','Nonpositive simple preview');
closureAssert(DepositSummary::project('1,005',['pPerc'=>null])['summary_sum']==='1.005','Comma/SQLNULL preview');
foreach ($cases['public'] as $case) {
    closureAssert(TelemetryAmount::read($case['value'])===$case['expected'],'Python wide24-place public amount');
    $stats=PublicStats::fromDepositStats(['zin'=>$case['value'],'zin2'=>['USD'=>$case['value']]], [1=>['cCurrID'=>'USD']]);
    closureAssert($stats['cash_in_base']===$case['expected'] && $stats['cash_in_by_currency']['USD']===$case['expected'],'Public scalar/map monetary strings');
}
foreach ($cases['landing'] as $case) closureAssert(TelemetryAmount::landing($case['value'])===$case['expected'],'Python exact landing near-integer threshold');
foreach ([null,[],NAN,INF,'1e999999','1,2',str_repeat('9',129)] as $bad) closureAssert(TelemetryAmount::read($bad)==='0.00000000','Bounded public read fallback');
foreach (['0.00000000','1000000000000000.00000000','999999999999999.99999999'] as $value) closureAssert(TelemetryAmount::wire($value)===$value,'Schema2 exact accepted range');
foreach (['1000000000000000.00000001','01.00000000','1','1.0','-0.00000000','1e0',1,1.0,null] as $value) {
    try { TelemetryAmount::wire($value);throw new LogicException('Invalid schema2 amount accepted'); }catch (InvalidArgumentException) { $checks++; }
}
closureAssert(GatewayBalance::read('123456789012.123456')==='123456789012.123456','Exact SOAP provider balance');
closureAssert(GatewayBalance::read(12.5)==='12.5','Explicit finite legacy JSON balance ingress');
// A real previous image produced these validated JSON bytes/hashes, not this implementation.
$goldens=json_decode(file_get_contents(__DIR__.'/fixtures/telemetry_v1_goldens.json'),true,64,JSON_THROW_ON_ERROR);
$validator=new TelemetryPayloadValidator();
foreach ($goldens['cases'] as $case) {
    $validated=$validator->report($case['input'],$goldens['now']);
    $json=json_encode($validated,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    closureAssert($json===$case['validated_json'] && hash('sha256',$json)===$case['hash'],'Old installed v1 validated bytes/hash preserved');
}
echo json_encode(['status'=>'passed','checks'=>$checks,'gateway_vectors'=>count($cases['gateway']),'summary_vectors'=>count($cases['summary']),'public_vectors'=>count($cases['public'])],JSON_THROW_ON_ERROR)."\n";
