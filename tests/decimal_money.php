<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

use Brick\Math\RoundingMode;
use HScript\Finance\CommissionPolicy;
use HScript\Finance\CurrencyScale;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\Money;
use HScript\Finance\Percentage;
use HScript\Finance\Rate;
use HScript\Finance\StoredAmount;

$checks = 0;
function decimalCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function decimalReject(callable $work, string $message): void {
    try { $work(); } catch (InvalidArgumentException | OverflowException | Brick\Math\Exception\MathException) {
        decimalCheck(true, $message); return;
    }
    throw new RuntimeException($message);
}

foreach ([0.1, INF, NAN, true, null, [], new stdClass(), '1e-8', '1,23', '+1', ' 1', '.5', '1.', str_repeat('1', 65), str_repeat('1',37), '0.1234567890123456789'] as $bad)
    decimalReject(static fn()=>Decimal::of($bad), 'strict decimal input');
decimalCheck((string)Decimal::of('0.1')->plus(Decimal::of('0.2')) === '0.3', 'decimal addition exact');
decimalCheck((string)Decimal::of('999999999999.000001')->minus(Decimal::of('999999999999')) === '0.000001', 'cancellation keeps tiny remainder');
decimalCheck(Decimal::of(2)->compareTo(Decimal::of(10)) < 0, 'comparison is numeric');
decimalCheck((string)Decimal::of('10000000000000.000001')->plus(Decimal::of('0.000001')) === '10000000000000.000002', 'SQL aggregates retain wider precision than one stored amount');
decimalCheck(Decimal::of('1.00000000')->isInteger() && !Decimal::of('-1.00000001')->isInteger(), 'integer semantics');
foreach (range(0, 8) as $scale) {
    $unit = $scale ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
    $money = Money::of($unit, 'asset:1', $scale, RoundingMode::Unnecessary);
    decimalCheck((string)$money->plus($money)->minus($money) === $unit, 'minor-unit conservation scale' . $scale);
    decimalCheck(json_encode(['money'=>$money], JSON_THROW_ON_ERROR) === '{"money":"'.$unit.'"}', 'JSON decimal string scale'.$scale);
    decimalCheck(CurrencyScale::resolve(['cNumDec'=>$scale], ['UI_NumDec'=>$scale]) === $scale, 'explicit scale/fallback'.$scale);
}
decimalCheck((string)Money::of('-1.005', 'USD', 2, RoundingMode::HalfUp) === '-1.01', 'negative tie away from zero');
decimalReject(static fn()=>Money::of('1.001','USD',2,RoundingMode::Unnecessary), 'no implicit rounding');
decimalReject(static fn()=>Money::of(1,'USD',2,RoundingMode::Unnecessary)->plus(Money::of(1,'EUR',2,RoundingMode::Unnecessary)), 'mixed currency rejected');
decimalReject(static fn()=>Money::of(1,'USD',2,RoundingMode::Unnecessary)->plus(Money::of(1,'USD',3,RoundingMode::Unnecessary)), 'mixed scale rejected');
foreach ([-1, 9] as $scale) decimalReject(static fn()=>Money::of(1,'USD',$scale,RoundingMode::HalfUp), 'invalid money scale');
decimalReject(static fn()=>Money::of(1,'unsafe currency',2,RoundingMode::HalfUp), 'invalid currency identity');
decimalReject(static fn()=>Money::of('999999999999','USD',0,RoundingMode::HalfUp)->plus(Money::of(1,'USD',0,RoundingMode::HalfUp)), 'money range overflow');
foreach ([0, '-0.1', 0.1, '1e-5'] as $bad) decimalReject(static fn()=>Rate::of($bad), 'positive decimal rate input');
decimalCheck((string)Rate::of('3')->divide(Decimal::of(1), 8, RoundingMode::HalfUp) === '0.33333333', 'explicit division precision');
decimalCheck((string)Percentage::of('0.00000001')->apply(Decimal::of(100), 8, RoundingMode::HalfUp) === '0.00000001', 'percent precision independent of currency');
decimalCheck(StoredAmount::exact(Decimal::of('9999999.999999')) === '9999999.999999', 'storage maximum inclusive');
decimalCheck(StoredAmount::exact(Decimal::of('0.00000100')) === '0.000001', 'eight-place trailing zero representable');
foreach (['0.00000001','-0.00000001','10000000','-10000000'] as $bad)
    decimalReject(static fn()=>StoredAmount::exact(Decimal::of($bad)), 'storage never rounds/truncates');
decimalReject(static fn()=>StoredAmount::exact(Money::of('9999999.9999995','USD',6,RoundingMode::HalfUp)->decimal()), 'overflow checked after rounding');
decimalCheck((string)DecimalInput::read('-1e-6') === '-0.000001', 'bounded explicit legacy ingress');
decimalCheck((string)DecimalInput::read(0.1) === '0.1', 'legacy float conversion explicit, no arithmetic');
decimalReject(static fn()=>DecimalInput::read('1e999999'), 'bounded exponent ingress');

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_commission_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['cases'] as $case) {
    $actual=CommissionPolicy::calculate(Decimal::of($case['amount']), Percentage::of($case['percent']),
        Decimal::of($case['min']),Decimal::of($case['max']),'fixture:1',$case['scale']);
    decimalCheck((string)$actual === $case['expected'], $case['id']);
}
echo json_encode(['status'=>'passed','checks'=>$checks,'commission_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
