<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../vendor/autoload.php';

use HScript\Security\CronAuthentication;

$token = str_repeat('a', 64);
$server = array('REQUEST_METHOD' => 'POST', 'HTTP_X_HSCRIPT_CRON_TOKEN' => $token);
if (!CronAuthentication::authorized($server, $token)
	|| CronAuthentication::authorized(array_replace($server, array('REQUEST_METHOD' => 'GET')), $token)
	|| CronAuthentication::authorized(array_replace($server, array('HTTP_X_HSCRIPT_CRON_TOKEN' => 'wrong')), $token)
	|| CronAuthentication::authorized($server, '')
	|| CronAuthentication::authorized(array('REQUEST_METHOD' => 'POST'), $token))
	throw new RuntimeException('Cron HTTP authentication failed');

echo "Cron HTTP authentication passed.\n";
