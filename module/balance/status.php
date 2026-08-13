<?php

use HScript\Template\View;
use HScript\Payment\PaymentManager;
use HScript\Security\SensitiveDataRedactor;

$rawBody = file_get_contents('php://input');
if (!$_POST)
	$_IN = $_POST = $_GET;
if (!$_POST)
	$_IN = $_POST = json_decode($rawBody, true);
if (!is_array($_IN))
	$_IN = array();

$_smode = 2;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

if (!($psys = $paymentManager->detectCallback($_IN, $_GET)))
{
	xAddToLog('*** Unknown request ***');
	xAddToLog('GET: ' . print_r(SensitiveDataRedactor::redact($_GET), true));
	xAddToLog('POST: ' . print_r(SensitiveDataRedactor::redact($_POST), true));
	xStop('Status URL');
}

try 
{
	$c = $db->fetch1Row($db->select('Currs', '*', 'cDisabled=0 and cCID=?', array($psys)));
	if (!$c['cID'])
		xStop("*** Payment system '$psys' disabled ***");
} 
catch (FormAbortException $e)
{
}

opDecodeCurrParams($c, $p, $p_sci, $p_api);
$request = $_IN;
$request['_raw_body'] = $rawBody;
$request['_json'] = json_decode($rawBody, true);
$request['_server'] = $_SERVER;
$res = $paymentManager->handleCallback($psys, $request, $p_sci);
if (isset($res['response']))
	echo $res['response'];
if (!empty($res['handled']))
	exit;
$oid = isset($res['tag']) ? $res['tag'] : 0;
if (empty($res['correct']) or !$oid)
{
	View::sendMailToAdmin('AddFundsError', array(
		'request' => print_r(SensitiveDataRedactor::redact($_IN), true),
		'result' => print_r(SensitiveDataRedactor::redact($res), true),
		'params' => '[REDACTED]'
		)
	);
	exit;
}
if (!$db->count('Opers', 'oID=?d and ocID=?d and oOper=? and oState<3', array($oid, $c['cID'], 'CASHIN')))
	exit;
$res['cid'] = $c['cID'];
$res['date'] = time();
$res['acc'] = $res['accfrom'] ?? '';
if (($err = opOperConfirm(-1, $oid, $res, false)) === true)
	if (!empty($res['wait']) or (($err = opOperComplete(-1, $oid)) === true))
		exit;
View::sendMailToAdmin('AddFundsError2', array(
	'oid' => $oid,
	'error' => $err
	)
);

?>
