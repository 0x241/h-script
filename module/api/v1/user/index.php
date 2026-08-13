<?php

use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
apiV1Require('user:read', array('GET'));

$user = $db->fetch1Row($db->select(
	'Users LEFT JOIN AddInfo ON auID=uID',
	'uID, uLogin, uMail, uState, uLevel, uLang, uMode, uTheme, uRef, uWDDisable, uLTS,
	 aName, aCTS, aCountry, aCity, aTel, aTZ',
	'uID=?d',
	array($apiAuth['user_id']),
	'',
	1
));
if (!$user)
	ApiResponse::error('user_not_found', 'User not found', 404);

ApiResponse::success(array(
	'id' => (int)$user['uID'],
	'login' => (string)$user['uLogin'],
	'email' => (string)$user['uMail'],
	'name' => (string)$user['aName'],
	'state' => (int)$user['uState'],
	'level' => (int)$user['uLevel'],
	'language' => (string)$user['uLang'],
	'mode' => (string)$user['uMode'],
	'theme' => (string)$user['uTheme'],
	'referrer_id' => (int)$user['uRef'],
	'withdrawals_enabled' => !(bool)$user['uWDDisable'],
	'country' => (string)$user['aCountry'],
	'city' => (string)$user['aCity'],
	'phone' => (string)$user['aTel'],
	'timezone_offset_minutes' => (int)$user['aTZ'],
	'created_at' => apiV1Stamp($user['aCTS']),
	'last_seen_at' => apiV1Stamp($user['uLTS']),
));
