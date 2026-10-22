<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

use HScript\Database\Connection;
use HScript\Finance\CallbackValidationException;
use HScript\Finance\GatewaySettlement;
use HScript\Payment\CallbackCapabilities;
use HScript\Payment\CallbackResult;
use HScript\Payment\PaymentManager;

$checks = 0;
function policyAssert(bool $condition, string $name): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    $checks++;
}
function policyBlockedSettlement(string $id, string $asset, bool $outbound): void
{
    try {
        // Unopened connection proves the guard precedes a database claim or
        // cached response. These are deliberately fabricated verifier results.
        GatewaySettlement::settle(new Connection(), $id, 1, [
            'correct'=>true, 'authenticated'=>true, 'outcome'=>'verified', 'wait'=>false, 'batch'=>'synthetic-policy-batch',
            'tag'=>'42', 'sum'=>'100', 'curr'=>$asset, 'outbound'=>$outbound,
        ]);
    } catch (CallbackValidationException $exception) {
        policyAssert($exception->reason === 'automatic_callback_unsupported', $id.' unexpected capability rejection');
        return;
    }
    throw new RuntimeException($id.' fabricated callback reached settlement');
}

$inventory = json_decode(file_get_contents(__DIR__.'/fixtures/gateway_contract_profiles.json'), true, 32, JSON_THROW_ON_ERROR);
$manager = new PaymentManager(new Connection());
$definitions = $manager->definitions();
policyAssert(array_keys($inventory['gateways']) === array_keys($definitions), 'Independent inventory must cover the exact registered ID set');
policyAssert($inventory['live_provider_capture'] === false, 'Inventory must not claim merchant captures');
policyAssert(str_contains($inventory['source_network_policy'], 'never replaces'), 'Network provenance cannot replace cryptographic authentication');
$disabled = ['ACR','PKAU','PKAR','EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT'];
foreach ($inventory['gateways'] as $id=>$policy) {
    $profile = $inventory['profiles'][$policy['profile']];
    policyAssert(in_array($id, $profile['ids'], true), $id.' missing documented profile');
    policyAssert(CallbackCapabilities::allows($id, 'in') === $policy['automatic_in'], $id.' callback capability differs from independent inventory');
    policyAssert(!CallbackCapabilities::allows($id, 'out'), $id.' exposes an unreviewed outbound callback');
    policyAssert(!CallbackCapabilities::allows($id, 'invalid'), $id.' accepts an unknown direction');
    foreach (['', '   '] as $empty) {
        $config = ['id'=>'synthetic-policy-merchant', 'key'=>$empty, 'apipass'=>$empty, 'protocol'=>'3', 'v3_key'=>$empty, 'timezone'=>'UTC'];
        $managed = $manager->handleCallback($id, [], $config);
        $direct = $manager->gateway($id)->handleCallback(['_config'=>$config]);
        policyAssert(isset($direct['outcome']), $id.' direct adapter has no explicit outcome');
        policyAssert(!$managed['correct'] && empty($direct['correct']), $id.' accepts empty authentication configuration');
        if (!$policy['automatic_in']) {
            policyAssert($managed['outcome'] === 'unsupported' && $direct['outcome'] === 'unsupported', $id.' unreviewed callback is not explicitly unsupported');
        }
    }
    policyBlockedSettlement($id, (string)$definitions[$id][1], true);
    if (!$policy['automatic_in']) {
        foreach ([['_raw_body'=>'{}'], ['amount'=>['nested']], ['type'=>'out','date'=>'0','sign'=>str_repeat('a',40)]] as $request) {
            $config = ['key'=>'synthetic-policy-key', 'apipass'=>'synthetic-policy-key'];
            $managed = $manager->handleCallback($id, $request, $config);
            $direct = $manager->gateway($id)->handleCallback($request + ['_config'=>$config]);
            policyAssert($managed['outcome'] === 'unsupported' && $direct['outcome'] === 'unsupported', $id.' automatic classification depends on untrusted payload');
            policyAssert(!$managed['correct'] && empty($direct['correct']), $id.' unsupported payload authenticates');
        }
        policyBlockedSettlement($id, (string)$definitions[$id][1], false);
    }
    if (in_array($id, $disabled, true)) {
        policyAssert((int)$definitions[$id][2] === 0 && (int)$definitions[$id][3] === 0, $id.' advertises disabled automatic operations');
    }
}
policyAssert(!CallbackCapabilities::allows('UNKNOWN'), 'Unknown gateway has automatic authority');
policyAssert($manager->handleCallback('UNKNOWN', [], [])['outcome'] === 'unsupported', 'Unknown manager callback is not unsupported');
policyBlockedSettlement('UNKNOWN', 'USD', false);
foreach ([['authenticated'=>false], ['correct'=>false], ['outcome'=>'rejected'],
    ['outcome'=>'unsupported'], ['outcome'=>'pending','wait'=>false], ['outcome'=>'verified','wait'=>true]] as $change) {
    try {
        GatewaySettlement::settle(new Connection(), 'CP', 1, array_replace([
            'authenticated'=>true, 'correct'=>true, 'outcome'=>'verified', 'wait'=>false,
            'batch'=>'synthetic-invalid-authority', 'tag'=>'42', 'sum'=>'100', 'curr'=>'BTC',
        ], $change));
        throw new LogicException('Invalid verification authority accepted');
    } catch (InvalidArgumentException) {
        policyAssert(true, 'Invalid authority rejected before opening a database');
    }
}

$vectors = json_decode(file_get_contents(__DIR__.'/fixtures/phase3_callback_cases.json'), true, 32, JSON_THROW_ON_ERROR);
policyAssert($vectors['live_provider_capture'] === false && $vectors['live_credentials'] === false, 'Fixture provenance must be honest');
$names = [];
foreach ($vectors['cases'] as $case) {
    policyAssert(!isset($names[$case['name']]), 'Duplicate frozen wire case name');
    $names[$case['name']] = true;
    policyAssert(in_array($case['origin'], ['synthetic_protocol','published_example'], true), 'Unknown fixture provenance');
    policyAssert(hash('sha256', $case['body']) === $case['body_sha256'], $case['name'].' wire bytes changed');
    $request = ['_raw_body'=>$case['body'], '_server'=>array_change_key_case(array_combine(
        array_map(static fn(string $header): string=>'HTTP_'.str_replace('-', '_', $header), array_keys($case['headers'])),
        array_values($case['headers'])
    ), CASE_UPPER)];
    if (in_array($case['gateway'],PaymentManager::DEPRECATED_IDS,true)) { policyAssert(!$manager->has($case['gateway']) && $manager->handleCallback($case['gateway'],$request,$case['config'])['outcome']==='unsupported','Retired captured fixture cannot execute');continue; }
    foreach (['manager'=>$manager->handleCallback($case['gateway'], $request, $case['config']),
        'direct'=>CallbackResult::normalize($manager->gateway($case['gateway'])->handleCallback($request + ['_config'=>$case['config']]))] as $path=>$result) {
        policyAssert($result['outcome'] === $case['expected']['outcome'], $case['name'].' '.$path.' outcome: '.$result['outcome']);
        if (isset($case['expected']['authenticated']))
            policyAssert($result['authenticated'] === $case['expected']['authenticated'], $case['name'].' '.$path.' authentication outcome');
        policyAssert($result['correct'] === in_array($result['outcome'], ['pending','verified'], true), $case['name'].' '.$path.' invalid automatic authority');
        policyAssert(!array_intersect_key($result, array_flip(['key','secret','sign','HMAC','LMI_HASH','LMI_HASH2','_raw_body'])), $case['name'].' '.$path.' leaks authentication material');
    }
}

// Actual isolated requests are evidence of the committed HTTP path, rather
// than fabricated token authority for providers that need an original invoice.
$captured=json_decode(file_get_contents(__DIR__.'/fixtures/phase3_captured_callbacks.json'),true,64,JSON_THROW_ON_ERROR);
policyAssert($captured['origin']==='captured_synthetic_http' && $captured['live_provider_capture']===false
    && $captured['live_credentials']===false,'Captured fixture provenance must be synthetic only');
foreach ($captured['runtime_image'] as $digest)
    policyAssert(preg_match('/^sha256:[a-f0-9]{64}$/D',$digest)===1,'Captured runtime identity malformed');
$states=[]; $observations=0; $captureNames=[];
foreach ($captured['cases'] as $case) {
    policyAssert(!isset($captureNames[$case['name']]),'Duplicate captured case');
    $captureNames[$case['name']]=true;
    policyAssert($case['origin']==='captured_synthetic_http' && $case['live_provider_capture']===false,'Captured case origin changed');
    policyAssert(hash('sha256',$case['body'])===$case['body_sha256'],'Captured raw HTTP body changed');
    policyAssert($case['method']==='POST' && str_starts_with($case['route'],'/balance/status'),'Captured route outside callback scope');
    policyAssert(!array_diff(array_map('strtolower',array_keys($case['headers'])),['content-type','hmac']),
        'Captured fixture contains authorization/cookie/client identity headers');
    policyAssert(!str_contains($case['body'],'hs_'.str_repeat('1',64)),'Bearer fixture token leaked into captured callback');
    policyAssert(in_array($case['response_status'],[200,400,401,404,409,413,422,503],true),'Unexpected captured HTTP outcome');
    policyAssert($case['server_audit']!==[] && $case['observations']!==[],'Captured case lacks server evidence');
    foreach ($case['server_audit'] as $event) {
        policyAssert(count($event)===3 && !array_diff(array_keys($event),['gateway','state','reason']),
            'Captured server audit contains provider/credential/client fields');
        policyAssert(in_array($event['state'],$inventory['outcomes'],true),'Captured state outside normalized contract');
        policyAssert(preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$event['reason'])===1,'Captured reason is not a fixed code');
        $states[$event['state']]=true;
    }
    foreach ($case['observations'] as $observation) {
        policyAssert(HScript\Observability\CorrelationContext::valid($observation['correlation_id']),
            'Captured observation lacks exact server correlation');
        policyAssert(preg_match('/^[a-f0-9]{64}$/D',$observation['projection_sha256'])===1,'Captured SQL projection digest invalid');
        $matched=false;
        foreach ($captured['runs'] as $run)
            if ($run['name']===$observation['run'] && ($run['phases'][$observation['phase']] ?? null)===$observation['projection_sha256']) $matched=true;
        policyAssert($matched,'Captured observation has no verified SQL phase');
        $observations++;
    }
}
policyAssert($observations===$captured['captured_requests'],'Captured request count inconsistent');
foreach ($inventory['outcomes'] as $outcome) policyAssert(isset($states[$outcome]),'Missing captured callback outcome '.$outcome);
echo "Independent gateway capability and frozen wire vectors passed: $checks checks, ".count($definitions)." IDs, ".count($vectors['cases']).
    ' raw cases, '.count($captured['cases']).' captured cases from '.$observations." HTTP requests.\n";
