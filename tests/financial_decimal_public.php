<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Telemetry\{InstallationRepository,TelemetryPayloadValidator,DnsResolverInterface};
foreach (['Installations','InstallationReports','InstallationIpHistory','InstallationDomainEvents'] as $table) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$_dbstru[$table].') ENGINE=InnoDB');$pdo->exec('DELETE FROM '.$table);
}
financialReset();
$dns=new class implements DnsResolverInterface {
    public function resolve(string $domain,string $ip): array { throw new RuntimeException('Unexpected DNS I/O'); }
};
$repository=new InstallationRepository($db,$dns);$validator=new TelemetryPayloadValidator();$now=time();$sequence=intdiv($now,86400);
$stats=['worked_days'=>1,'users_total'=>1,'users_online'=>0,'active_deposits'=>1,'closed_deposits'=>0,
    'base_currency'=>'USD','cash_in_base'=>'999999999999999.99999999','cash_out_base'=>'0.00000001',
    'referral_paid_base'=>'0.00000001','reinvested_base'=>'0.00000001',
    'cash_in_by_currency'=>['USD'=>'999999999999999.99999999'],'cash_out_by_currency'=>['USD'=>'0.00000001']];
for ($i=1;$i<=12;$i++) {
    $id=sprintf('123e4567-e89b-42d3-a456-%012d',$i);$token='synthetic-'.$i;
    $db->insert('Installations',['tiID'=>$i,'tiPublicID'=>$id,'tiDomain'=>'public-'.$i.'.example.org','tiVersion'=>'1.0.8','tiInstalledAt'=>$now-86400,
        'tiRegisteredAt'=>$now-100+$i,'tiLastSeenAt'=>$now,'tiStatsConsent'=>1,'tiState'=>1,'tiTokenHash'=>hash('sha256',$token),
        'tiLastIP'=>'192.0.2.1','tiDnsStatus'=>'matched','tiDnsAddresses'=>'["192.0.2.1"]','tiDnsCheckedAt'=>$now,'tiDnsExpiresAt'=>$now+3600]);
    $payload=$validator->report(['schema_version'=>2,'installation_id'=>$id,'domain'=>'public-'.$i.'.example.org','version'=>'1.0.8',
        'reported_at'=>$now,'sequence'=>$sequence,'stats_consent'=>true,'public_stats'=>$stats],$now);
    financialAssert($repository->report($payload,$token,'192.0.2.1')['state']==='accepted','Actual schema2 report persisted');
    $before=$pdo->query('SELECT * FROM InstallationReports WHERE tirInstallationID='.$i)->fetch(PDO::FETCH_ASSOC);
    financialAssert($repository->report($payload,$token,'192.0.2.1')['state']==='duplicate','Same schema2 report exact retry');
    financialAssert($pdo->query('SELECT * FROM InstallationReports WHERE tirInstallationID='.$i)->fetch(PDO::FETCH_ASSOC)===$before,'Repeated public receipt remains exact');
}
function publicReadFingerprint(): string {
    global $pdo;$all=[];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
    }ksort($all);return hash('sha256',serialize($all));
}
$before=publicReadFingerprint();
$public=(new ReflectionMethod(InstallationRepository::class,'publicAggregate'))->invoke($repository,$now);
financialAssert($public['base_totals_by_currency']['USD']===['cash_in'=>'11999999999999999.99999988','cash_out'=>'0.00000012','referral_paid'=>'0.00000012','reinvested'=>'0.00000012'],'Actual SQL JSON string sums remain exact above binary integer range');
financialAssert($public['cash_in_by_currency']['USD']==='11999999999999999.99999988' && $public['cash_out_by_currency']['USD']==='0.00000012','Native currency JSON_TABLE exact8 totals');
financialAssert($repository->publicMetrics()['processed']==='11999999999999999.99999988','Wide aggregate public metric not capped at individual limit');
financialAssert(publicReadFingerprint()===$before,'Read-only collector never changes any SQL rows');
// Consent, eligibility and canonical-domain ownership continue excluding disallowed data.
$db->update('Installations',['tiStatsConsent'=>0],'','tiID=12');
$db->update('Installations',['tiDnsStatus'=>'mismatch'],'','tiID=11');
$db->update('Installations',['tiDomain'=>'www.public-1.example.org'],'','tiID=10');
$public=(new ReflectionMethod(InstallationRepository::class,'publicAggregate'))->invoke($repository,$now);
financialAssert($public['installations_sharing']===9 && $public['base_totals_by_currency']['USD']['cash_in']==='8999999999999999.99999991','Unchanged consent/DNS/canonical owner eligibility');
// A previous installed image generated this numeric validation/hash; duplicate must not conflict.
$goldens=json_decode(file_get_contents(__DIR__.'/fixtures/telemetry_v1_goldens.json'),true,64,JSON_THROW_ON_ERROR);
$g=$goldens['cases'][0];$payload=$validator->report($g['input'],$goldens['now']);$id=99;$token='synthetic-old';
$db->insert('Installations',['tiID'=>$id,'tiPublicID'=>$payload['installation_id'],'tiDomain'=>$payload['domain'],'tiVersion'=>$payload['version'],
    'tiTokenHash'=>hash('sha256',$token),'tiStatsConsent'=>1,'tiState'=>1,'tiLastSequence'=>$payload['sequence'],'tiLastReportedAt'=>$payload['reported_at'],
    'tiLastIP'=>'192.0.2.1','tiDnsStatus'=>'matched','tiDnsExpiresAt'=>$now+3600]);
$db->insert('InstallationReports',['tirInstallationID'=>$id,'tirDay'=>gmdate('Y-m-d',$payload['reported_at']),'tirSequence'=>$payload['sequence'],
    'tirReportedAt'=>$payload['reported_at'],'tirVersion'=>$payload['version'],'tirStats'=>json_encode($payload['public_stats'],JSON_THROW_ON_ERROR),'tirPayloadHash'=>$g['hash']]);
$before=$pdo->query('SELECT * FROM InstallationReports WHERE tirInstallationID=99')->fetch(PDO::FETCH_ASSOC);
financialAssert($repository->report($payload,$token,'192.0.2.1')['state']==='duplicate','Real installed old schema1 numeric hash accepted');
financialAssert($pdo->query('SELECT * FROM InstallationReports WHERE tirInstallationID=99')->fetch(PDO::FETCH_ASSOC)===$before,'Historical schema1 JSON/hash receipt never rewritten');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'schema2_reports'=>12,'real_v1_golden'=>true],JSON_THROW_ON_ERROR)."\n";
