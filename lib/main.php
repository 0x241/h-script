<?php

use HScript\Util\StringHelper;
use HScript\Http\ClientIp;
use HScript\Security\SecretCipher;

final class FormAbortException extends RuntimeException
{
}

function hsConfigureErrorHandling()
{
    $hsAppEnv = strtolower((string)getenv('APP_ENV'));
    $hsDebugEnv = strtolower((string)getenv('APP_DEBUG'));
    $hsProduction = in_array($hsAppEnv, array('prod', 'production'), true);
    $hsDebug = !$hsProduction && (
        in_array($hsAppEnv, array('dev', 'development', 'local'), true)
        || in_array($hsDebugEnv, array('1', 'true', 'yes', 'on'), true)
    );

    ini_set('display_errors', $hsDebug ? '1' : '0');
    ini_set('display_startup_errors', $hsDebug ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/logs/error.log');
    error_reporting(E_ALL);
}

function hsHasDatabaseConfiguration(array $config): bool
{
    if (empty($config['db_host']) || empty($config['db_name'])) {
        return false;
    }

    // Docker keeps credentials in environment variables or mounted secret
    // files. Traditional installations retain the encoded login in config.
    return !empty($config['db_credentials_env']) || !empty($config['db_login']);
}

hsConfigureErrorHandling();
define("HS2_BR", "<br />");
define("HS2_NL", "\r\n");
define("HS2_UNIX_SECOND", 1);
define("HS2_UNIX_MINUTE", 60);
define("HS2_UNIX_HOUR", 60 * HS2_UNIX_MINUTE);
define("HS2_UNIX_DAY", 24 * HS2_UNIX_HOUR);
global $_GS;
$_GS = [];
$_GS["info"] = [];
$_SERVER += array(
    "SERVER_NAME" => "localhost",
    "SCRIPT_NAME" => "",
    "SERVER_PORT" => 80,
    "REQUEST_URI" => "/",
    "SERVER_ADDR" => "127.0.0.1",
    "REMOTE_ADDR" => "127.0.0.1"
);
$_GS["domain"] = preg_replace("|^(www\\.)|i", "", $_SERVER["SERVER_NAME"]);
$s = $_GS["domain"];
StringHelper::cutElemR($s, ".");
StringHelper::cutElemR($s, ".");
$_GS["subdomain"] = $s;
$s = $_SERVER["SCRIPT_NAME"];
$_GS["script"] = StringHelper::cutElemR($s, "/");
$_GS["root_dir"] = $s ? substr($s, 1) . "/" : "";
$s = $_GS["script"];
StringHelper::cutElemR($s, ".");
$_GS["module"] = strtolower($s);
$_GS["https"] = $_SERVER["SERVER_PORT"] == 443;
$_GS["root_url"] = getrooturl($_GS["https"]);
$s = $_SERVER["REQUEST_URI"];
StringHelper::cutElemL($s, "/" . $_GS["root_dir"]);
$_GS["uri"] = $s;
$_GS["server_ip"] = $_SERVER["SERVER_ADDR"];
$_GS["client_ip"] = ClientIp::resolve($_SERVER);
$_GS["is_local"] = substr($_GS["server_ip"], 0, -1) == "127.0.0.";
$_GS["is_self"] = $_GS["client_ip"] == $_GS["server_ip"];
$_GS["lang"] = "";
$_GS["mode"] = "";
$_GS["theme"] = "";
$_GS["default_lang"] = "en";
$_GS["TZ"] = 0;
$_GS["site_name"] = "";
$_GS["as_term"] = false;
global $_IN;
$_IN = fromGPC($_POST);
if (!function_exists('mb_strtoupper'))
{
	function mb_strlen($string) {
		return strlen($string);
	}
	function mb_strtoupper($string) {
		return strtoupper($string);
	}
}
function xAbort($message = "")
{
    throw new FormAbortException($message);
}
function xSysInfo($message, $type = 0)
{
    $_GS["info"][$type][] = $message;
    if ($type < 2) {
        return NULL;
    }
    xAddToLog($message, "system");
    if ($type == 2) {
        xabort($message);
    }
    xStop($message);
}
function xSysWarning($message)
{
    xsysinfo($message, 1);
}
function xSysError($message)
{
    xsysinfo($message, 2);
}
function xSysStop($message, $and_refresh = false)
{
    if ($and_refresh && !headers_sent()) {
        refreshToURL(5);
    }
    xsysinfo($message, 3);
}
function xTerminal($is_debug = false)
{
    global $_GS;
    if ($is_debug) {
        error_reporting(32767);
    }
    ob_implicit_flush();
    header("Content-Type: text/plain; charset=\"utf-8\"");
    header("Pragma: no-cache");
    $_GS["as_term"] = true;
}
function xEcho()
{
    global $_GS;
    foreach (func_num_args() ? func_get_args() : ["- - - - -"] as $message) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        $message .= HS2_NL;
        if (!$_GS["as_term"]) {
            $message = nl2br($message);
        }
        echo $message;
    }
}
function xStop()
{
    foreach (func_get_args() as $message) {
        xecho($message);
    }
    exit;
}
function hashPassword($password)
{
    return password_hash((string)$password, PASSWORD_BCRYPT, array('cost' => 12));
}
function verifyPassword($password, $hash)
{
    return is_string($hash) && ($hash !== '') && password_verify((string)$password, $hash);
}
function passwordHashNeedsRehash($hash)
{
    return !is_string($hash) || ($hash === '') || password_needs_rehash($hash, PASSWORD_BCRYPT, array('cost' => 12));
}
function isPasswordHash($hash)
{
    if (!is_string($hash) || ($hash === '')) {
        return false;
    }
    $info = password_get_info($hash);
    return !empty($info['algo']);
}
function legacyPasswordDigest($password, $salt, $salt_first = true)
{
    $password = (string)$password;
    $salt = (string)$salt;
    return hash('md5', $salt_first ? ($salt . $password) : ($password . $salt));
}
function verifyPasswordWithLegacyDigest($password, $hash, $salt, $salt_first = true)
{
    if (verifyPassword($password, $hash)) {
        return true;
    }
    return verifyPassword(legacyPasswordDigest($password, $salt, $salt_first), $hash);
}
function hsEnvFlagIsEnabled($value)
{
    return in_array(strtolower((string)$value), array('1', 'true', 'yes', 'on'), true);
}
function hsEnvFlagIsDisabled($value)
{
    return in_array(strtolower((string)$value), array('0', 'false', 'no', 'off'), true);
}
function hsIsHttpsRequest()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return ClientIp::isForwardedHttps($_SERVER);
}
function hsIsLocalHostRequest()
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = trim(preg_replace('/:\d+$/', '', $host), '[]');
    return $host === 'localhost'
        || $host === '::1'
        || preg_match('/^127\./', $host)
        || substr($host, -6) === '.local';
}
function hsUseSecureCookies()
{
    $override = getenv('SESSION_COOKIE_SECURE');
    if ($override !== false && $override !== '') {
        if (hsEnvFlagIsEnabled($override)) {
            return true;
        }
        if (hsEnvFlagIsDisabled($override)) {
            return false;
        }
    }
    if (!hsIsHttpsRequest() && hsIsLocalHostRequest()) {
        return false;
    }
    $env = strtolower((string)getenv('APP_ENV'));
    $debug = getenv('APP_DEBUG');
    if (in_array($env, array('prod', 'production'), true)) {
        return true;
    }
    if (in_array($env, array('dev', 'development', 'local'), true) || hsEnvFlagIsEnabled($debug)) {
        return false;
    }
    return hsIsHttpsRequest();
}
function hsSessionSameSite()
{
    $same_site = strtolower(trim((string)getenv('SESSION_COOKIE_SAMESITE')));
    $same_sites = array('strict' => 'Strict', 'lax' => 'Lax', 'none' => 'None');
    if (isset($same_sites[$same_site])) {
        $same_site = $same_sites[$same_site];
    } else {
        $same_site = 'Strict';
    }
    return $same_site;
}
function hsConfigureSessionSecurity()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
	$gc_lifetime = intval(getenv('SESSION_GC_MAXLIFETIME'));
	if ($gc_lifetime < HS2_UNIX_HOUR) {
		$gc_lifetime = 30 * HS2_UNIX_DAY;
	}
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', hsUseSecureCookies() ? '1' : '0');
    ini_set('session.cookie_samesite', hsSessionSameSite());
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
	// PHP's default is 1440 seconds. That was shorter than the application's
	// configurable user session and let GC remove an otherwise valid session.
	ini_set('session.gc_maxlifetime', (string)$gc_lifetime);
}
function xAddToLog($message, $topic = "", $clear_before = false)
{
    global $_GS;
    $fname = "logs/log_" . $topic . ".txt";
    if ($clear_before && file_exists($fname)) {
        unlink($fname);
    }
    clearstatcache();
    $t = is_file($fname) ? intval(filemtime($fname)) : 0;
    if ($f = fopen($fname, "a")) {
        $d = abs(time() - $t);
        if (10 <= $d) {
            fwrite($f, "- - - - - [" . gmdate("d.m.y H:i:s") . ($d <= 120 ? " +" . $d : "") . "] - - - - -" . HS2_NL);
        }
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        fwrite($f, "<" . $_GS["module"] . "> " . $message . HS2_NL);
        fclose($f);
    }
}
function startSessionSafely($regenerate = false)
{
    hsConfigureSessionSecurity();
    $error = null;
    set_error_handler(function ($severity, $message) use (&$error) {
        $error = $message;
        return true;
    });
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $started = session_start();
        } else {
            $started = true;
        }
        if ($started && $regenerate) {
            $started = session_regenerate_id(true);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $started = false;
    }
    restore_error_handler();
    if (!$started) {
        xAddToLog($error ?: 'Unable to start session', 'system');
    }
    return $started;
}
function resetSessionSafely()
{
    if (session_status() !== PHP_SESSION_ACTIVE && !startSessionSafely()) {
        return false;
    }
    $_SESSION = array();
    return startSessionSafely(true);
}
function hsSetCookie($name, $value, $expires = 0, $path = '/', $http_only = true, $same_site = null)
{
    if ($same_site === null || $same_site === '') {
        $same_site = hsSessionSameSite();
    }
    return setcookie((string)$name, (string)$value, array(
        'expires' => intval($expires),
        'path' => (string)$path,
        'secure' => hsUseSecureCookies(),
        'httponly' => (bool)$http_only,
        'samesite' => (string)$same_site
    ));
}
function getRootURL($as_HTTPS = false)
{
    global $_GS;
    return ($as_HTTPS ? "https" : "http") . "://" . $_GS["domain"] . "/" . $_GS["root_dir"];
}
function fromGPC($s)
{
    if (!is_null($s)) {
        StringHelper::mTrim($s);
    }
    return $s;
}
function filterInput($s, $mask = "")
{
    if (is_null($s) || !$mask) {
        return $s;
    }
    if ($mask == "*") {
        return strip_tags($s);
    }
    preg_match("/^" . $mask . "\$/", $s, $a);
    return isset($a[0]) ? $a[0] : "";
}
function _arr_val(&$arr, $p)
{
    if (!isset($arr) || !is_array($arr)) {
        return NULL;
    }
    if (preg_match("/(.+)\\[(.*)\\]/", $p, $a)) {
        if (!array_key_exists($a[1], $arr)) {
            return NULL;
        }
        return _arr_val($arr[$a[1]], $a[2]);
    }
    return array_key_exists($p, $arr) ? $arr[$p] : NULL;
}
function isset_IN($p = "btn")
{
    global $_IN;
    return !is_null(_arr_val($_IN, $p));
}
function _IN($p, $mask = "")
{
    global $_IN;
    return filterinput(_arr_val($_IN, $p), $mask);
}
function _COOKIE($p, $mask = "")
{
    return isset($_COOKIE[$p]) ? filterinput(fromgpc($_COOKIE[$p]), $mask) : NULL;
}
function _GET($p, $mask = "")
{
    return isset($_GET[$p]) ? filterinput(fromgpc($_GET[$p]), $mask) : NULL;
}
function _POST($p, $mask = "")
{
    return isset($_POST[$p]) ? filterinput(fromgpc($_POST[$p]), $mask) : NULL;
}
function isset_RQ($p)
{
    $_RQ = $_GET + $_POST;
    return !is_null(_arr_val($_RQ, $p));
}
function _RQ($p, $mask = "")
{
    $_RQ = $_GET + $_POST;
    return filterinput(fromgpc(_arr_val($_RQ, $p)), $mask);
}
function _SESSION($p)
{
    return isset($_SESSION[$p]) ? $_SESSION[$p] : NULL;
}
function _INN($p)
{
    return intval(_in($p));
}
function _COOKIEN($p)
{
    return intval(_COOKIE($p));
}
function _GETN($p)
{
    return intval(_GET($p));
}
function _POSTN($p)
{
    return intval(_POST($p));
}
function _RQN($p)
{
    return intval(_rq($p));
}
function validMail($s)
{
    $mask = "|^.+@.+\\..+\$|";
    return preg_match($mask, StringHelper::textLow($s));
}
function validURL($s)
{
    $mask = "|^https?:\\/\\/.+\\..+\$|i";
    return preg_match($mask, StringHelper::textLow($s));
}
function getDomain($s)
{
    $mask = "|^(?:https?:\\/\\/)?(?:www\\.)?([^\\/]+)|i";
    preg_match($mask, StringHelper::textLow($s), $a);
    return $a[1];
}
function validDomain($s)
{
    return preg_match("|.+\\..+\$|", $s);
}
function valid_filename($f)
{
    return !StringHelper::sEmpty($f) && $f != "." && $f != ".." && StringHelper::textPos("/", $f) < 0 && StringHelper::textPos(chr(0), $f) < 0;
}
function compare_ip($ip1, $ip2, $level = 4)
{
    $ip1 = explode(".", $ip1);
    $ip2 = explode(".", $ip2);
    for ($i = 0; $i <= $level - 1; $i++) {
        if ($ip1[$i] != $ip2[$i]) {
            return false;
        }
    }
    return true;
}
function numInRange($z, $a, $b)
{
    return $a <= $z && $z <= $b;
}
function numRange($z, $a, $b)
{
    if ($z < $a) {
        $z = $a;
    } else {
        if ($b < $z) {
            $z = $b;
        }
    }
    return $z;
}
function calcPerc($sum, $perc, $r = 2)
{
    return round($sum * $perc / 100, $r);
}
function idArrayCreate($arr, $fld = 0)
{
    $res = [];
    foreach ($arr as $r) {
        $res[$r[$fld]] = $r;
    }
    return $res;
}
function idArrayFind($arr, $fld, $value)
{
    foreach ($arr as $i => $r) {
        if ($r[$fld] == $value) {
            return $i;
        }
    }
    return NULL;
}
function idArraySum($arr, $fld)
{
    $z = 0;
    foreach ($arr as $r) {
        $z += $r[$fld];
    }
    return $z;
}
function asArray($a, $dlm = ",", $skip_empty = true)
{
    if (is_array($a)) {
        return $a;
    }
    $r = [];
    foreach (explode($dlm, (string)$a) as $v) {
        $v = trim($v);
        if (!$skip_empty || !StringHelper::sEmpty($v)) {
            $r[] = $v;
        }
    }
    return $r;
}
function asStr($s, $dlm = ",")
{
    if (!is_array($s)) {
        return $s;
    }
    return strval(implode($dlm, $s));
}
function arrayToStr($a)
{
    if (is_array($a)) {
        $json = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }
    return $a;
}
function safeUnserialize($s)
{
    if (!is_string($s) || ($s === '')) {
        return false;
    }
    $error = null;
    set_error_handler(function ($severity, $message) use (&$error) {
        $error = $message;
        return true;
    });
    try {
        $value = unserialize($s, array('allowed_classes' => false));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $value = false;
    }
    restore_error_handler();
    if ($error !== null) {
        xAddToLog($error, 'serialize');
    }
    return $value;
}
function strToArray($s)
{
    if (is_string($s) && $s !== '') {
        $json = json_decode($s, true);
        if (is_array($json)) {
            return $json;
        }
    }
    if (is_array($a = safeUnserialize($s))) {
        return $a;
    }
    return [];
}
function encodeArrayToStr($arr, $key, $variant = 0)
{
    $context = 'array:' . intval($variant) . ':' . (string)$key;
    $json = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && ($encrypted = SecretCipher::encrypt($json, $context)) !== null) {
        return $encrypted;
    }
    return encode1(arraytostr($arr), $key, true, ord($key) % 8);
}
function decodeArrayFromStr($s, $key, $variant = 0)
{
    $s = (string)$s;
    if (SecretCipher::isEncrypted($s)) {
        $json = SecretCipher::decrypt($s, 'array:' . intval($variant) . ':' . (string)$key);
        if ($json === null) {
            xAddToLog('Unable to decrypt protected data', 'security');
            return array();
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : array();
    }
    return strtoarray(decode1($s, $key, true, ord($key) % 8));
}
function fullURL($url = "*", $as_HTTPS = -1)
{
    global $_GS;
    $url = trim($url);
    if ($url == "*") {
        $url = $_GS["uri"];
    } else {
        $url = StringHelper::get1ElemL($url, "\n");
    }
    if (!validurl($url)) {
        if ($as_HTTPS === -1) {
            $as_HTTPS = $_GS["https"];
        }
        $url = getrooturl($as_HTTPS) . $url;
    }
    return $url;
}
function goToURL($url = "*", $work_after = 0)
{
    $url = fullurl($url);
    $isHtmxRequest = isset($_SERVER["HTTP_HX_REQUEST"])
        && strtolower((string)$_SERVER["HTTP_HX_REQUEST"]) === "true";
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_commit();
    }
    if (!headers_sent()) {
        if ($isHtmxRequest) {
            // A regular 302 is followed inside the HTMX request and the login
            // document would be swapped into the existing cabinet/admin shell.
            // HX-Redirect explicitly requests a full browser navigation.
            http_response_code(200);
            header("HX-Redirect: " . $url);
            header("Content-Length: 0");
        } else {
            header("Location: " . $url);
        }
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($url, ENT_QUOTES) . '";</script>';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES) . '">';
    }
    $work_after = intval($work_after);
    if ($work_after < 1) {
        exit;
    }
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        try {
            set_time_limit($work_after);
        } catch (Throwable $e) {
            xAddToLog($e->getMessage(), 'system');
        }
    }
    header("Connection: close");
    header("Content-Length: 0");
    ob_end_clean();
    ob_end_flush();
    flush();
}
function refreshToURL($t = 0, $url = "*")
{
    if ($t < 1) {
        $t = 1;
    }
    $url = fullurl($url);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_commit();
    }
    header("Refresh: " . $t . "; URL=" . $url);
}
function timeToStamp($t = "*")
{
    if ("" === $t) {
        return "";
    }
    if ("*" === $t) {
        $t = time();
    }
    return gmdate("YmdHis", $t);
}
function stampToTime($p)
{
    if (empty($p)) {
        return NULL;
    }
    $p = str_pad($p, 14, "0", STR_PAD_LEFT);
    return gmmktime(
        intval(substr($p, 8, 2)),
        intval(substr($p, 10, 2)),
        intval(substr($p, 12, 2)),
        intval(substr($p, 4, 2)),
        intval(substr($p, 6, 2)),
        intval(substr($p, 0, 4))
    );
}
function subStamps($p1, $p2 = -1)
{
    $t1 = stamptotime($p1);
    if ($p2 < 0) {
        $t2 = $t1;
        $t1 = time();
    } else {
        $t2 = stamptotime($p2);
    }
    return $t1 - $t2;
}
function encode1($text, $pass, $as_hex = true, $dl = 0)
{
    $text = strval(base64_encode((string)$text));
    $pass = mb_strtoupper(md5((string)$pass . mb_strlen($text)));
    $code = "";
    $n = $dl;
    for ($i = 0; $i < mb_strlen($text); $i++) {
        if (mb_strlen($pass) - $dl <= $n) {
            $n = 0;
        }
        $c = ord($text[$i]) ^ ord($pass[$n]);
        $code .= $as_hex ? sprintf("%02x", $c) : chr($c);
        $n++;
    }
    if (!$as_hex) {
        $code = base64_encode($code);
    }
    return $code;
}
function decode1($code, $pass, $as_hex = true, $dl = 0)
{
    if (!$as_hex) {
        $code = base64_decode((string)$code);
    }
    $code = (string)$code;
    $pass = mb_strtoupper(md5((string)$pass . (mb_strlen($code) >> $as_hex)));
    $text = "";
    $n = $dl;
    $i = 0;
    while ($i < mb_strlen($code)) {
        if (mb_strlen($pass) - $dl <= $n) {
            $n = 0;
        }
        if ($as_hex) {
            $c = hexdec(mb_substr($code, $i, 2));
        } else {
            $c = ord($code[$i]);
        }
        $text .= chr($c ^ ord($pass[$n]));
        $n++;
        $i += 1 + $as_hex;
    }
    return base64_decode($text);
}
?>
