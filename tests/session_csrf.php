<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Security\SessionCsrf;

require dirname(__DIR__) . '/vendor/autoload.php';

session_id('phase4-' . bin2hex(random_bytes(8)));
session_start();
$first = SessionCsrf::token();
if (!preg_match('/^[a-f0-9]{64}$/D', $first)
	|| !SessionCsrf::valid($first)
	|| SessionCsrf::valid('')
	|| SessionCsrf::valid(array($first))
	|| SessionCsrf::valid(strtoupper($first)))
	throw new RuntimeException('Session CSRF validation failed');

$_SESSION = array();
$second = SessionCsrf::token();
if ($second === $first || SessionCsrf::valid($first) || !SessionCsrf::valid($second))
	throw new RuntimeException('Session CSRF token survived a session reset');

session_destroy();
echo "Session CSRF tests passed.\n";
