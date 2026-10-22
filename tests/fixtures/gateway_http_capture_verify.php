<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('APP_ENV') !== 'development'
    || getenv('APP_DOMAIN') !== 'fixture.invalid' || getenv('DB_NAME') !== 'fixture') exit(64);
require dirname(__DIR__,2).'/vendor/autoload.php';
$phase=$argv[1] ?? '';
if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$phase)) exit(64);
$projection=json_decode(file_get_contents('/tmp/gateway-http-projection-'.$phase.'.json'),true,64,JSON_THROW_ON_ERROR);
$output=[];
foreach (file('/tmp/gateway-http-captured-wire.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $record=json_decode($line,true,32,JSON_THROW_ON_ERROR);
    if (($record['origin'] ?? '')!=='captured_synthetic_http' || ($record['live_provider_capture'] ?? true)!==false
        || !is_string($record['correlation_id'] ?? null)
        || !HScript\Observability\CorrelationContext::valid($record['correlation_id'])
        || !hash_equals(hash('sha256',$record['body']),$record['body_sha256']))
        throw new RuntimeException('Synthetic HTTP wire provenance/digest mismatch');
    $events=[];
    foreach ($projection['callback_audit'] as $event) {
        if (($event['resource_id'] ?? '')!=='callback:'.$record['body_sha256']) continue;
        if ($record['correlation_id']!==($event['correlation_id'] ?? '')) continue;
        $events[]=$event['context'];
    }
    if ($events===[]) throw new RuntimeException('HTTP request body did not match its server audit fingerprint: '
        .$record['body_sha256'].' / '.$record['correlation_id'].' / status '.$record['response_status']);
    $record['server_audit']=$events;
    $output[]=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
if ($output===[]) throw new RuntimeException('Synthetic callback wire capture empty');
$file='/tmp/gateway-http-captured-verified.jsonl';
file_put_contents($file,implode("\n",$output)."\n",LOCK_EX);
chmod($file,0600);
echo 'Actual HTTP body fingerprints and normalized server outcomes verified: '.count($output)." captures.\n";
