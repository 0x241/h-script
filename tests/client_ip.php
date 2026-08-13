<?php

use HScript\Http\ClientIp;

require dirname(__DIR__) . '/vendor/autoload.php';

$trusted = '127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
$assertSame = static function ($expected, $actual, string $case): void
{
	if ($expected !== $actual)
		throw new RuntimeException($case . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};

$assertSame('203.0.113.8', ClientIp::resolve(array(
	'REMOTE_ADDR' => '203.0.113.8',
	'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
), $trusted), 'untrusted remote ignores forwarded headers');

$assertSame('198.51.100.20', ClientIp::resolve(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
), $trusted), 'trusted docker proxy');

$assertSame('198.51.100.20', ClientIp::resolve(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 198.51.100.20, 10.0.0.4',
), $trusted), 'rightmost untrusted address wins');

$assertSame('198.51.100.21', ClientIp::resolve(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_CF_CONNECTING_IP' => '198.51.100.21',
	'HTTP_X_FORWARDED_FOR' => '198.51.100.21, 203.0.113.9',
), $trusted), 'cloudflare connecting address takes precedence');

$assertSame('2001:db8::7', ClientIp::resolve(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_X_REAL_IP' => '2001:db8::7',
), $trusted), 'trusted real ip fallback');

$assertSame(true, ClientIp::isForwardedHttps(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_X_FORWARDED_PROTO' => 'https',
), $trusted), 'trusted forwarded https');

$assertSame(false, ClientIp::isForwardedHttps(array(
	'REMOTE_ADDR' => '203.0.113.8',
	'HTTP_X_FORWARDED_PROTO' => 'https',
), $trusted), 'untrusted forwarded https');

$assertSame(true, ClientIp::isForwardedHttps(array(
	'REMOTE_ADDR' => '172.20.0.5',
	'HTTP_X_FORWARDED_SSL' => 'on',
), $trusted), 'trusted forwarded ssl');

echo "Client IP component tests passed.\n";
