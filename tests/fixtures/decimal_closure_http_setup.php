<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('DB_NAME')!=='fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$db->replace('Cfg',['Module'=>'Sys','Prop'=>'AdminMail','Val'=>'']);
$db->update('Plans',['pPerc'=>'1.00','pNPer'=>100],'','pID=1');
$db->replace('Cfg',['Module'=>'Telemetry','Prop'=>'PublicMetrics','Val'=>'{"processed":"9007199254740993.01","platforms":5}']);
$now=time();
for ($i=1;$i<=10;$i++) {
    $publicID=sprintf('123e4567-e89b-42d3-a456-%012d',$i);
    $id=$db->insert('Installations',['tiPublicID'=>$publicID,'tiDomain'=>'public-'.$i.'.example.org','tiVersion'=>'1.0.8','tiInstalledAt'=>$now-86400,
        'tiRegisteredAt'=>$now-100+$i,'tiLastSeenAt'=>$now,'tiStatsConsent'=>1,'tiState'=>1,'tiTokenHash'=>hash('sha256','synthetic-token-'.$i),
        'tiLastSequence'=>intdiv($now,86400),'tiLastReportedAt'=>$now,'tiLastIP'=>'192.0.2.1','tiDnsStatus'=>'matched','tiDnsAddresses'=>'["192.0.2.1"]',
        'tiDnsCheckedAt'=>$now,'tiDnsExpiresAt'=>$now+86400]);
    $stats=['worked_days'=>1,'users_total'=>1,'users_online'=>0,'active_deposits'=>0,'closed_deposits'=>0,
        'base_currency'=>'USD','cash_in_base'=>'900719925474099.30100000','cash_out_base'=>'123456789012.12345678',
        'referral_paid_base'=>'0.00000001','reinvested_base'=>'0.00000001','cash_in_by_currency'=>['USD'=>'900719925474099.30100000'],
        'cash_out_by_currency'=>['USD'=>'123456789012.12345678']];
    $db->insert('InstallationReports',['tirInstallationID'=>$id,'tirDay'=>gmdate('Y-m-d',$now),'tirSequence'=>intdiv($now,86400),'tirReportedAt'=>$now,
        'tirVersion'=>'1.0.8','tirStats'=>json_encode($stats,JSON_THROW_ON_ERROR),'tirPayloadHash'=>hash('sha256','synthetic-report-'.$i)]);
}
// A local collector is enabled only in this disposable installed application.
file_put_contents(dirname(__DIR__,2).'/_config.local.php',"<?php \$_cfg['telemetry_collector_enabled']='1';\$_cfg['telemetry_collector_domain']='fixture.invalid';\$_cfg['telemetry_ingestion_enabled']='0';");
echo "Synthetic exact read/public HTTP fixtures ready.\n";
