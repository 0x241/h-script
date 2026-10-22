<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('APP_ENV') !== 'development'
    || getenv('APP_DOMAIN') !== 'fixture.invalid' || getenv('DB_NAME') !== 'fixture') exit(64);
$phase=$argv[1] ?? '';
if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$phase)) exit(64);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$projection=['Users'=>$db->link->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
foreach (['Currs','Opers','Wallets','Hist','Deps','IdempotencyKeys','Jobs'] as $table)
    $projection[$table]=$db->link->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
$audit=[];
$path=dirname(__DIR__,2).'/logs/observability.ndjson';
$files=[];
for ($rotation=HScript\Observability\NdjsonWriter::rotationCount();$rotation>=0;$rotation--) {
    $file=$path.($rotation ? '.'.$rotation : '');
    if (is_file($file)) $files[]=$file;
}
foreach (glob(dirname($path).'/fixture-callback-*.ndjson') ?: [] as $file) {
    if (is_link($file) || !is_file($file)
        || !preg_match('/^fixture-callback-[a-f0-9]{32}\.ndjson$/D',basename($file))
        || (fileperms($file) & 0777)!==0600) throw new RuntimeException('Invalid private callback audit file');
    $files[]=$file;
}
foreach ($files as $file) {
    foreach (file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $record=json_decode($line,true,32,JSON_THROW_ON_ERROR);
        if (($record['event_code'] ?? '')==='gateway_callback') $audit[]=$record;
    }
}
$capture=['version'=>1,'origin'=>'captured_synthetic_http','live_provider_capture'=>false,
    'phase'=>$phase,'captured_at'=>gmdate('c'),'projection'=>$projection,
    'projection_sha256'=>hash('sha256',json_encode($projection,JSON_THROW_ON_ERROR)),
    'callback_audit'=>$audit];
$file='/tmp/gateway-http-projection-'.$phase.'.json';
file_put_contents($file,json_encode($capture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
chmod($file,0600);
echo 'Private synthetic HTTP projection captured ('.$phase.').'."\n";
