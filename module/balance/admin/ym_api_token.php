<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

foreach ($_currs as $cid => $c)
	if ($c['cCID'] == 'YM')
	{
		$el = $c;
		opDecodeCurrParams($el, $el['P'], $el['PSCI'], $el['PAPI']);
		break;
	}

if (!$el)
	View::ShowInfo('*Error', moduleToLink('balance/admin/currs'));

$uri = FullURL(moduleToLink());
if (!$el['PAPI']['id'] or !$el['PAPI']['secretpass'] or $el['PAPI']['apipass'])
	View::ShowInfo('*Error', moduleToLink('balance/admin/curr') . '?id=' . $el['cID']);

require_once("lib/yandexmoney/api.php");

if (!$code = _GET('code'))
{
	$scope = array("account-info operation-history operation-details payment-p2p");
	$oauthState = bin2hex(random_bytes(32));
	$_SESSION['_ym_oauth_state'] = array('token' => $oauthState, 'expires' => time() + 600);
	GoToURL(YandexMoney\API::buildObtainTokenUrl($el['PAPI']['id'], $uri, $scope) . '&state=' . rawurlencode($oauthState));
}

$pendingState = $_SESSION['_ym_oauth_state'] ?? null;
unset($_SESSION['_ym_oauth_state']);
$returnedState = $_GET['state'] ?? null;
if (!is_array($pendingState) || !is_string($returnedState)
	|| (int)($pendingState['expires'] ?? 0) < time()
	|| !hash_equals((string)($pendingState['token'] ?? ''), $returnedState))
{
	http_response_code(403);
	exit;
}

if (!is_string($code) || $code === '' || strlen($code) > 2048)
{
	http_response_code(400);
	exit;
}
$access_token_response = YandexMoney\API::getAccessToken($el['PAPI']['id'], $code, $uri, $el['PAPI']['secretpass']);
xaddtolog($access_token_response, 'YD');

$el['PAPI']['apipass'] = $access_token_response->access_token;
$key = $el['cID'] . $el['cCID'] . stampToTime($el['cMTS']);
if ($db->update('Currs', array('cParamsAPI' => encodeArrayToStr($el['PAPI'], $key, 3)), '', 'cID=?d', array($el['cID'])))
	View::showInfo('Saved', moduleToLink('balance/admin/curr') . '?id=' . $el['cID']);

?>
