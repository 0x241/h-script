<?php
declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Recovery\RecoveryDrillVerifier;
use HScript\Telemetry\TelemetryServiceTokenRepository;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') throw new RuntimeException('Disposable database required');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'),getenv('DB_NAME'),getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
$localPath = $root . '/_config.local.php';
$localBefore = is_file($localPath) ? file_get_contents($localPath) : null;
$owners = array();
$started = microtime(true);
$repository = new TelemetryServiceTokenRepository($db);
function incidentAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function incidentCli(array $arguments): array
{
	$process = proc_open(array_merge(array(PHP_BINARY,dirname(__DIR__) . '/bin/api-token.php','stop-collector-consumer'),$arguments),
		array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,dirname(__DIR__),getenv());
	if (!is_resource($process)) throw new RuntimeException('CLI unavailable');
	fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
	$error=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
	incidentAssert($error === '', 'CLI exposed raw failure details');
	return array($exit,json_decode($output,true,32,JSON_THROW_ON_ERROR));
}
try
{
	foreach (array('affected','unaffected') as $name)
		$owners[] = (int)$db->insert('Users',array('uLogin'=>'incident_' . $name,'uMail'=>'incident_' . $name . '@fixture.invalid','uPass'=>hashPassword('synthetic-incident-only'),'uLevel'=>10,'uState'=>1));
	[$affected,$unaffected] = $owners;
	$old = $repository->issue($affected,'rotation old');
	$rotated = $repository->reissue($old['id'],$affected,'rotation replacement');
	incidentAssert($repository->authenticate($old['token'],'192.0.2.1') === null,'Old secret survived rotation');
	incidentAssert($repository->authenticate($rotated['token'],'192.0.2.1') !== null,'Replacement secret rejected');
	$paused = $repository->issue($affected,'paused token');
	$repository->update($paused['id'],'paused token',0,false);
	(new RecoveryDrillVerifier(''))->verifyApplicationState($db);
	$other = $repository->issue($unaffected,'unaffected token');
	$db->query('RENAME TABLE TelemetryServiceTokens TO IncidentHiddenTokens');
	try
	{
		$failed = false;
		try { $repository->stopConsumer($affected); } catch (RuntimeException) { $failed = true; }
		incidentAssert($failed, 'Missing token registry accepted');
		incidentAssert((int)$db->fetch1($db->query('SELECT uState FROM Users WHERE uID=?d',array($affected))) === 1,'Failed stop did not roll back account state');
	}
	finally { $db->query('RENAME TABLE IncidentHiddenTokens TO TelemetryServiceTokens'); }
	incidentAssert(incidentCli(array((string)$affected,'--confirm-consumer=' . $affected))[0] === 1,'Non-collector emergency stop accepted');
	file_put_contents($localPath,"<?php \$_cfg['telemetry_collector_enabled']='1'; \$_cfg['telemetry_collector_domain']='fixture.invalid';");
	incidentAssert(incidentCli(array((string)$affected,'--confirm-consumer=' . $unaffected))[0] === 1,'Wrong confirmation accepted');
	incidentAssert(incidentCli(array('1','--confirm-consumer=1'))[0] === 1,'Administrator disabled as consumer');
	incidentAssert($repository->authenticate($rotated['token'],'192.0.2.1') !== null,'Rejected stop changed state');
	[$exit,$result] = incidentCli(array((string)$affected,'--confirm-consumer=' . $affected));
	incidentAssert($exit === 0 && $result === array('status'=>'stopped','consumer_id'=>$affected),'Stop result incorrect');
	incidentAssert($repository->authenticate($rotated['token'],'192.0.2.1') === null,'Stopped consumer authenticated');
	incidentAssert((int)$db->fetch1($db->query('SELECT COUNT(*) FROM TelemetryServiceTokens WHERE tstuID=?d AND tstState<>0',array($affected))) === 0,'Paused/active token survived stop');
	incidentAssert($repository->authenticate($other['token'],'192.0.2.2') !== null,'Other consumer affected');
	incidentAssert(incidentCli(array((string)$affected,'--confirm-consumer=' . $affected))[0] === 0,'Stop is not idempotent');
	$rejected = false;
	try { $repository->issue($affected,'must reject'); } catch (InvalidArgumentException) { $rejected=true; }
	incidentAssert($rejected,'Blocked consumer issued a token');
	// Model an issuance that was already in flight: even an active row cannot
	// authenticate after the owner was disabled. Do not re-enable incident owners.
	$db->update('TelemetryServiceTokens',array('tstState'=>1),'','tstID=?d',array($rotated['id']));
	incidentAssert($repository->authenticate($rotated['token'],'192.0.2.1') === null,'In-flight token bypassed disabled owner');
	$repository->stopConsumer($affected);
	echo json_encode(array('drill'=>'consumer_rotation_and_stop','environment'=>'disposable','status'=>'passed',
		'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'duration_ms'=>(int)round((microtime(true)-$started)*1000)),JSON_THROW_ON_ERROR) . PHP_EOL;
}
finally
{
	foreach ($owners as $id) { $db->delete('TelemetryServiceTokens','tstuID=?d',array($id)); $db->delete('Users','uID=?d',array($id)); }
	if ($localBefore !== null) file_put_contents($localPath,$localBefore); elseif (is_file($localPath)) unlink($localPath);
}
