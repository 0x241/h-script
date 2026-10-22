<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Finance\Decimal;
use HScript\Finance\DepositPolicy;
use HScript\Finance\Rate;
use HScript\Finance\StoredAmount;
use Brick\Math\RoundingMode;

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_deposit_cases.json'),true,32,JSON_THROW_ON_ERROR);
$checks=0;
foreach ($fixture['cases'] as $case) {
    try {
        $amounts=DepositPolicy::accrual(...array_merge(array_map(Decimal::of(...),array_map(fn($k)=>$case[$k],
            ['principal','compounded','previousProfit','percent','returnPercent','compoundPercent'])),[$case['terminal'],$case['scale']]));
        if ($case['expected']===null) throw new RuntimeException('Unsupported precision accepted: '.$case['id']);
        foreach ($amounts as $key=>$value) {
            if (StoredAmount::exact($value)!==$case['expected'][$key]) throw new RuntimeException($case['id'].':'.$key);
            $checks++;
        }
        $serialized=json_decode(json_encode($amounts,JSON_THROW_ON_ERROR),true);
        foreach ($serialized as $v) if (!is_string($v) || str_contains(strtolower($v),'e')) throw new RuntimeException('Decimal serialization');
        $checks++;
    } catch (InvalidArgumentException $error) {
        if ($case['expected']!==null || $error->getMessage()!=='amount_storage_precision') throw $error;
        $checks++;
    }
}
foreach ($fixture['caps'] as $case) {
    $minimum=Rate::of($case['rate'])->divide(Decimal::of($case['minimum']),min(6,$case['scale']),RoundingMode::Ceiling);
    $cap=DepositPolicy::compoundCap(Decimal::of($case['principal']),Decimal::of($case['compounded']),$minimum);
    if (StoredAmount::exact($cap)!==$case['expected']) throw new RuntimeException('Native compound cap');
    $checks++;
}
foreach ([['-1','0','0','1','100','0'],['1','2','0','1','100','0'],['1','0','0','-1','100','0'],['1','0','0','1','-1','0'],['1','0','0','1','100','100.01'],['9999999','0','0','100','100','0']] as $values) {
    try { DepositPolicy::accrual(...array_merge(array_map(Decimal::of(...),$values),[true,2])); }
    catch (InvalidArgumentException|OverflowException) { $checks++; continue; }
    throw new RuntimeException('Invalid/overflow accrual accepted');
}
echo json_encode(['status'=>'passed','checks'=>$checks,'deposit_oracle_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
