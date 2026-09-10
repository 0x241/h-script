<?php

declare(strict_types=1);

use HScript\Security\ConfiguratorSecurity;

require dirname(__DIR__) . '/vendor/autoload.php';

function configuratorSecurityAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function configuratorSecurityRejects(callable $callback, string $message): void
{
	try { $callback(); }
	catch (Throwable) { return; }
	throw new RuntimeException($message);
}

function configuratorSecurityRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') configuratorSecurityRemove($path . '/' . $item);
	rmdir($path);
}

$root = sys_get_temp_dir() . '/hscript-configurator-security-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$previousAllowlist = getenv('CONFIGURATOR_ALLOWED_CIDRS');
$previousTrustedProxies = getenv('TRUSTED_PROXY_CIDRS');
try
{
	$security = new ConfiguratorSecurity($root);
	putenv('TRUSTED_PROXY_CIDRS=127.0.0.1/32,10.0.0.0/24');
	$resolved = $security->clientIp(array(
		'REMOTE_ADDR' => '127.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '203.0.113.44, 10.0.0.2',
	));
	configuratorSecurityAssert($resolved === '203.0.113.44', 'Trusted proxy client IP was not resolved');

	putenv('CONFIGURATOR_ALLOWED_CIDRS=203.0.113.0/24,2001:db8::/32');
	$security->assertAllowed('203.0.113.44');
	$security->assertAllowed('2001:db8::25');
	configuratorSecurityRejects(fn() => $security->assertAllowed('198.51.100.10'), 'CIDR allowlist accepted a foreign IP');
	putenv('CONFIGURATOR_ALLOWED_CIDRS=invalid-cidr');
	configuratorSecurityRejects(fn() => $security->assertAllowed('203.0.113.44'), 'Invalid CIDR allowlist failed open');

	$first = $security->consumeRateLimit('login', '203.0.113.44', 2, 300);
	$second = $security->consumeRateLimit('login', '203.0.113.44', 2, 300);
	$third = (new ConfiguratorSecurity($root))->consumeRateLimit('login', '203.0.113.44', 2, 300);
	configuratorSecurityAssert($first['allowed'] && $second['allowed'] && !$third['allowed'], 'Persistent rate limit was not enforced');

	$security->audit('login', 'failed', '203.0.113.44', array(
		'reason' => 'password',
		'password' => 'do-not-log-me',
		'sql_query' => 'select sensitive',
	));
	$audit = $security->recentAudit(5);
	configuratorSecurityAssert(count($audit) === 1 && $audit[0]['event'] === 'login', 'Security audit was not persisted');
	$auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
	configuratorSecurityAssert(!str_contains($auditJson, 'do-not-log-me') && !str_contains($auditJson, 'select_sensitive'), 'Security audit retained a secret or SQL');
	configuratorSecurityAssert(($audit[0]['context']['password'] ?? '') === '[redacted]', 'Secret audit field was not redacted');
	configuratorSecurityAssert(ConfiguratorSecurity::localAddress('127.0.0.1') && !ConfiguratorSecurity::localAddress('203.0.113.44'), 'Local operator classification failed');

	echo "Configurator security tests passed.\n";
}
finally
{
	if ($previousAllowlist === false) putenv('CONFIGURATOR_ALLOWED_CIDRS');
	else putenv('CONFIGURATOR_ALLOWED_CIDRS=' . $previousAllowlist);
	if ($previousTrustedProxies === false) putenv('TRUSTED_PROXY_CIDRS');
	else putenv('TRUSTED_PROXY_CIDRS=' . $previousTrustedProxies);
	configuratorSecurityRemove($root);
}
