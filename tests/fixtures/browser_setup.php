<?php
// Only the disposable fixture database may configure these browser prerequisites.
if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) exit(1);
// Keep brute-force protection enabled, allowing one bad attempt followed by a
// correct password. The installer default (1) intentionally requires e-mail recovery.
// This network has no mail/captcha provider; IP-change e-mail confirmation is
// excluded here. Password, CSRF and session authorization remain real.
foreach (array('Account' => array('LoginCaptcha' => 0), 'Sec' => array('BFC' => 3, 'IP' => 0), 'API' => array('Enabled' => 1), 'Sys' => array('NeedReConfig' => 0)) as $module => $properties)
	foreach ($properties as $property => $value) $db->replace('Cfg', array('Module' => $module, 'Prop' => $property, 'Val' => $value));
$db->query("UPDATE AddInfo JOIN Users ON auID=uID SET aIPSec=0 WHERE uLogin='admin'");
// Verify the existing global ingestion switch through the real HTTP endpoints.
file_put_contents(dirname(__DIR__, 2) . '/_config.local.php', "<?php \$_cfg['telemetry_collector_enabled']='1'; \$_cfg['telemetry_collector_domain']='fixture.invalid'; \$_cfg['telemetry_ingestion_enabled']='0';");
// Real files prove server denial, not just a missing-file response.
foreach (array('sql', 'sql.gz', 'json', 'txt', 'css', 'php') as $suffix)
	file_put_contents(dirname(__DIR__, 2) . '/backup/probe.' . $suffix, 'SYNTHETIC_BACKUP_SECRET');
echo "Disposable browser fixture configured.\n";
