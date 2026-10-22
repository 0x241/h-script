<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Finance\{Decimal,ReferralPolicy,StoredAmount};

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_referral_cases.json'),true,32,JSON_THROW_ON_ERROR);
$checks=0;
foreach ($fixture['cases'] as $case) {
    try {
        $reward=ReferralPolicy::payout(Decimal::of($case['source']),Decimal::of($case['percent']),$case['scale']);
        if ($case['error'] || StoredAmount::exact($reward)!==$case['expected']) throw new RuntimeException('Referral oracle: '.$case['id']);
        $checks++;
        $wire=json_decode(json_encode($reward,JSON_THROW_ON_ERROR),true);
        if (!is_string($wire) || str_contains(strtolower($wire),'e')) throw new RuntimeException('Referral wire amount');
        $checks++;
    } catch (OverflowException $error) {
        if ($case['error']!=='sum_overflow') throw $error;
        $checks++;
    } catch (InvalidArgumentException $error) {
        if ($case['error']!=='sum_precision' || $error->getMessage()!=='amount_storage_precision') throw $error;
        $checks++;
    }
}
foreach ($fixture['tiers'] as $case) {
    $tier=ReferralPolicy::tier(Decimal::of($case['metric']),$case['entries']);
    if ((string)$tier['percent']!==$case['percent'] || $tier['level']!==$case['level']) throw new RuntimeException('Referral threshold');
    $checks++;
}
foreach ([[' 5,50 ','5.50'],['+1','1'],['1e-2','0.01'],['-1','-1'],
    ['.5','0.5'],['5.','5'],['-.5','-0.5'],['+.5','0.5'],[',5','0.5'],['5,','5'],
    ['.5e-1','0.05'],['5.e-2','0.05'],[' +,5e+1 ','5'],['1e-9','0.000000001'],
    ['1234567891e-9','1.234567891'],['1e-18','0.000000000000000001'],
    ['1e35','100000000000000000000000000000000000']] as [$input,$expected]) {
    if ((string)ReferralPolicy::percentage($input)!==$expected) throw new RuntimeException('Referral config normalization');
    $checks++;
}
foreach (['', '.', '+.', '1.e', 'NaN', '1..2', '1e999', '1e-19', '1e36', '1e55', str_repeat('0',129), [], 1.1] as $bad) {
    try { ReferralPolicy::percentage($bad); }
    catch (InvalidArgumentException $error) { if($error->getMessage()!=='ref_config_wrong') throw $error; $checks++;continue; }
    throw new RuntimeException('Malformed referral percent accepted');
}
foreach ([['0'],['-1-5'],['0-bad'],[3],['0-5','100-6','bad']] as $bad) {
    try { ReferralPolicy::tier(Decimal::of(0),$bad); }
    catch (InvalidArgumentException) { $checks++;continue; }
    throw new RuntimeException('Malformed referral tier accepted');
}
// Existing order and duplicate thresholds are deliberate, without implicit sorting.
if ((string)ReferralPolicy::tier(Decimal::of(100),['0-1','0-2','200-3','50-4'])['percent']!=='2') throw new RuntimeException('Tier ordering changed');
$checks++;
if ((string)ReferralPolicy::tier(Decimal::of('0.01'),['0-1','1e-2-5'])['percent']!=='5') throw new RuntimeException('Scientific threshold delimiter');
$checks++;
foreach ([['.5-5','0.5','5'],['0-5.','0','5'],['.5e-1-+.5','0.05','0.5'],['0--.5','0','-0.5'],[',5-5,','0.5','5'],
    ['1e-9-1234567891e-9','0.000000001','1.234567891']] as [$entry,$metric,$percent]) {
    if ((string)ReferralPolicy::tier(Decimal::of($metric),[$entry])['percent']!==$percent) throw new RuntimeException('PHP numeric referral tier');
    $checks++;
}
foreach ([['-1','1'],['1','-1']] as [$source,$percent]) {
    try { ReferralPolicy::payout(Decimal::of($source),Decimal::of($percent),2); }
    catch (InvalidArgumentException) { $checks++;continue; }
    throw new RuntimeException('Negative referral accepted');
}
echo json_encode(['status'=>'passed','checks'=>$checks,'referral_oracle_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
