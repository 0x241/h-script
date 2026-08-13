<?php

use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
apiV1Require('balance:read', array('GET'));

$wallets = array();
foreach ($_currs as $currency)
{
	$available = (float)($currency['wBal'] ?? 0);
	$locked = (float)($currency['wLock'] ?? 0);
	$outgoing = (float)($currency['wOut'] ?? 0);
	$wallets[] = array(
		'currency' => array(
			'id' => (int)$currency['cID'],
			'gateway_id' => (string)$currency['cCID'],
			'code' => (string)$currency['cCurr'],
			'name' => (string)$currency['cName'],
			'decimals' => (int)$currency['cNumDec'],
		),
		'available' => $available,
		'locked' => $locked,
		'outgoing' => $outgoing,
		'total' => $available + $locked + $outgoing,
	);
}

ApiResponse::success(array('wallets' => $wallets));
