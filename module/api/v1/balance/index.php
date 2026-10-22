<?php

use HScript\Http\ApiResponse;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalOutput;

require dirname(__DIR__) . '/bootstrap.php';
apiV1Require('balance:read', array('GET'));

$wallets = array();
foreach ($_currs as $currency)
{
	$available = Decimal::of($currency['wBal'] ?? 0);
	$locked = Decimal::of($currency['wLock'] ?? 0);
	$outgoing = Decimal::of($currency['wOut'] ?? 0);
	$wallets[] = array(
		'currency' => array(
			'id' => (int)$currency['cID'],
			'gateway_id' => (string)$currency['cCID'],
			'code' => (string)$currency['cCurr'],
			'name' => (string)$currency['cName'],
			'decimals' => (int)$currency['cNumDec'],
		),
		'available' => DecimalOutput::stored($available),
		'locked' => DecimalOutput::stored($locked),
		'outgoing' => DecimalOutput::stored($outgoing),
		'total' => DecimalOutput::stored($available->plus($locked)->plus($outgoing)),
	);
}

ApiResponse::success(array('wallets' => $wallets));
