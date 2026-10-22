<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

use HScript\Finance\Decimal;
use HScript\Finance\ExchangePolicy;
use HScript\Finance\Percentage;
use HScript\Finance\Rate;

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_exchange_cases.json'),true,32,JSON_THROW_ON_ERROR);
$checks=0;
foreach ($fixture['cases'] as $case) {
    $result=ExchangePolicy::convert(Decimal::of($case['amount']),Rate::of($case['rate']),
        Percentage::of($case['adjustment']),$case['out'],$case['scale']);
    if ((string)$result!==$case['expected']) throw new RuntimeException($case['id']);
    if (json_encode(['amount'=>$result],JSON_THROW_ON_ERROR)!=='{"amount":"'.$case['expected'].'"}')
        throw new RuntimeException('Fixed JSON string: '.$case['id']);
    $checks+=2;
}
foreach ([[true,'-100'],[true,'-101'],[false,'100'],[false,'101']] as [$out,$adjustment]) {
    try { ExchangePolicy::convert(Decimal::of(1),Rate::of(1),Percentage::of($adjustment),$out,2); }
    catch (InvalidArgumentException $e) {
        if ($e->getMessage()!=='exchange_factor_invalid') throw $e;
        $checks++; continue;
    }
    throw new RuntimeException('Nonpositive factor accepted');
}
echo json_encode(['status'=>'passed','checks'=>$checks,'exchange_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
