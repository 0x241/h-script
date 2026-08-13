<?php

function cfgPasswordAlgo()
{
	if (defined('PASSWORD_ARGON2ID')) {
		return PASSWORD_ARGON2ID;
	}
	return PASSWORD_BCRYPT;
}

function cfgPasswordHash($password)
{
	$hash = password_hash((string)$password, cfgPasswordAlgo());
	if ($hash === false) {
		xSysStop('Configurator: password hash failed');
	}
	return $hash;
}

function cfgPasswordIsModern($hash)
{
	return is_string($hash) && str_starts_with($hash, '$');
}

function cfgPasswordVerify($password, $hash, $domain)
{
	$hash = trim((string)$hash);
	if ($hash === '') {
		return false;
	}
	if (cfgPasswordIsModern($hash)) {
		return password_verify((string)$password, $hash);
	}
	return hash_equals($hash, md5((string)$domain . (string)$password));
}

function cfgPasswordNeedsRehash($hash)
{
	if (!cfgPasswordIsModern($hash)) {
		return true;
	}
	return password_needs_rehash((string)$hash, cfgPasswordAlgo());
}
