<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
	require dirname(__DIR__) . '/vendor/autoload.php';
} else {
	spl_autoload_register(function ($class) {
		if (str_starts_with($class, 'HScript\\')) {
			$file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
			if (file_exists($file)) require_once $file;
		}
	});
	require_once dirname(__DIR__) . '/lib/main.php';
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	$checks++;
	if (!$condition) throw new RuntimeException($message);
};

if (($argv[1] ?? '') === '--default') {
	// =========================================================================
	// Default Engine Tests (image captcha)
	// =========================================================================
	if (!function_exists('moduleToLink')) {
		function moduleToLink($module = '', $chpu = false, $https = 0) {
			return '/' . $module;
		}
	}

	$_SESSION = array();
	$_cfg = array(
		'Captcha_Service' => 'default',
		'Captcha_View' => 0,
	);
	$_GS = array(
		'client_ip' => '127.0.0.1',
	);

	require dirname(__DIR__) . '/module/captcha/onstart.php';

	// --- Test 1: Mode 2 verification consumption and bypass prevention ---
	$_SESSION = array();
	$_POST = array();

	// 1. Render form with mode 2
	$html = getCaptcha(2, 'login_frm');
	$assert(str_contains($html, '<img'), 'Default captcha generates image tag');
	$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Timestamp initialized > 0');

	// Mock captcha code in session
	$_SESSION['_capt']['login_frm'] = 'TEST123';

	// 2. Submit with matching code
	$_POST['__Capt'] = 'TEST123';
	$res1 = chkCaptcha('login_frm');
	$assert($res1 === true, 'Default captcha check with valid code passes');
	$assert(!isset($_SESSION['_capt']['login_frm']), 'Default captcha code consumed from session');
	$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Timestamp remains > 0');

	// 3. Subsequent submission without solving a new captcha must fail
	unset($_POST['__Capt']);
	$res2 = chkCaptcha('login_frm');
	$assert($res2 === false, 'Subsequent submission without solving captcha must be rejected');

	// --- Test 2: Mode 1 adaptive flow ---
	$_SESSION = array();
	$_POST = array();

	// 1. First visit
	$html1 = getCaptcha(1, 'login_frm');
	$assert($html1 === '', 'First visit mode 1 has no captcha');
	$res_m1_1 = chkCaptcha('login_frm');
	$assert($res_m1_1 === true, 'First submit passes');
	$assert($_SESSION['_captTS']['login_frm'] > 0, 'Timestamp recorded');

	// 2. Re-render requires captcha
	$html2 = getCaptcha(1, 'login_frm');
	$assert(str_contains($html2, '<img'), 'Re-render requires image captcha');
	$_SESSION['_capt']['login_frm'] = 'ABCD';
	$_POST['__Capt'] = 'ABCD';
	$res_m1_2 = chkCaptcha('login_frm');
	$assert($res_m1_2 === true, 'Solving captcha passes');
	$assert($_SESSION['_captTS']['login_frm'] > 0, 'Timestamp stays > 0');

	// 3. Immediate bypass attempt must fail
	unset($_POST['__Capt']);
	$res_m1_3 = chkCaptcha('login_frm');
	$assert($res_m1_3 === false, 'Immediate bypass attempt without captcha is rejected');

	echo "Default captcha bypass tests passed: $checks checks.\n";
	exit(0);
}

// =========================================================================
// Turnstile Engine Tests
// =========================================================================

$_SESSION = array();
$_cfg = array(
	'Captcha_Service' => 'turnstile',
	'Turnstile_SiteKey' => 'test-site-key',
	'Turnstile_SecretKey' => '1x00000000000000000000000000000000000000AA',
);
$_GS = array(
	'client_ip' => '127.0.0.1',
	'csp_nonce' => 'test-nonce',
);
putenv('APP_ENV=local');

require dirname(__DIR__) . '/module/captcha/onstart.php';

// --- Test 1: Always-captcha mode (mode 2) must not be bypassed after 1 successful verification ---
$_SESSION = array();
$_POST = array();

// 1. Render form with mode 2 (always captcha)
$html = getCaptcha(2, 'login_frm');
$assert($html !== '', 'Captcha HTML must be generated for mode 2');
$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Captcha timestamp must be initialized > 0');

// 2. Submit form with valid Turnstile token
$_POST['cf-turnstile-response'] = 'valid-dummy-token';
$res1 = chkCaptcha('login_frm');
$assert($res1 === true, 'First submission with valid token must pass captcha check');
$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Captcha timestamp must remain > 0 after successful verification');

// 3. Submit form a second time without a captcha token (e.g. reusing unused __Cert)
unset($_POST['cf-turnstile-response']);
$res2 = chkCaptcha('login_frm');
$assert($res2 === false, 'Second submission without captcha token must be rejected, not bypassed');

// --- Test 2: Adaptive captcha mode (mode 1) must require fresh verification once triggered ---
$_SESSION = array();
$_POST = array();

// 1. First visit: no captcha required yet
$html1 = getCaptcha(1, 'login_frm');
$assert($html1 === '', 'First visit in mode 1 should not require captcha');
$assert(($_SESSION['_captTS']['login_frm'] ?? 0) === 0, 'Timestamp should be 0 initially in mode 1');

// 2. First submission without captcha: passes because it is the first attempt
$res_m1_1 = chkCaptcha('login_frm');
$assert($res_m1_1 === true, 'First submission in mode 1 passes without captcha');
$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Timestamp recorded after first submission');

// 3. Immediate re-render (< 60s): captcha is now required
$html2 = getCaptcha(1, 'login_frm');
$assert($html2 !== '', 'Immediate re-render in mode 1 must require captcha');
$assert($_SESSION['_captTS']['login_frm'] > 0, 'Timestamp stays > 0');

// 4. Submit with valid token: passes
$_POST['cf-turnstile-response'] = 'valid-dummy-token';
$res_m1_2 = chkCaptcha('login_frm');
$assert($res_m1_2 === true, 'Submission with valid captcha token must pass');
$assert(isset($_SESSION['_captTS']['login_frm']) && $_SESSION['_captTS']['login_frm'] > 0, 'Timestamp stays > 0 after valid submission in mode 1');

// 5. Immediate subsequent submission without solving a new captcha: must be rejected
unset($_POST['cf-turnstile-response']);
$res_m1_3 = chkCaptcha('login_frm');
$assert($res_m1_3 === false, 'Immediate subsequent submission without token must be rejected');

// --- Test 3: Disabled captcha mode (mode 0) ---
$_SESSION = array();
$_POST = array();
$html0 = getCaptcha(0, 'login_frm');
$assert($html0 === '', 'Mode 0 must not generate captcha HTML');
$assert(($_SESSION['_captTS']['login_frm'] ?? 0) === 0, 'Timestamp must be 0 for mode 0');
$res_m0 = chkCaptcha('login_frm');
$assert($res_m0 === true, 'Mode 0 must allow submission without captcha');

// --- Test 4: resetCaptcha explicitly resets state for multi-step transitions ---
resetCaptcha('login_frm');
$assert(($_SESSION['_captTS']['login_frm'] ?? null) === 0, 'resetCaptcha must explicitly clear timestamp to 0');

echo "Turnstile captcha bypass access control tests passed: $checks checks.\n";

// Run default engine tests in child process
passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --default', $childExit);
if ($childExit !== 0) {
	throw new RuntimeException("Default captcha bypass child tests failed with exit code $childExit");
}

echo "All captcha bypass access control tests passed.\n";
