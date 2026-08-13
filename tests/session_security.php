<?php

putenv('SESSION_GC_MAXLIFETIME=7200');
require dirname(__DIR__) . '/vendor/autoload.php';

hsConfigureSessionSecurity();

$assertSame = static function ($expected, $actual, string $case): void
{
	if ($expected !== $actual)
		throw new RuntimeException($case . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};

$assertSame('7200', ini_get('session.gc_maxlifetime'), 'configured server-side session lifetime');
$assertSame('1', ini_get('session.use_strict_mode'), 'strict session ids');
$assertSame('1', ini_get('session.use_only_cookies'), 'cookie-only session ids');
$assertSame('1', ini_get('session.cookie_httponly'), 'HttpOnly session cookie');

echo "Session security tests passed.\n";
