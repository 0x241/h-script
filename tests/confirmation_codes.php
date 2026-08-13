<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/confirm/lib.php';

function confirmationCodeAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$_cfg = array(
	'sys_id' => 'confirmation-test-system',
	'Const_Salt' => 'confirmation-test-salt',
);

$memo = opConfirmCodeMemo('482913');
confirmationCodeAssert((bool) preg_match('/^[a-f0-9]{32}$/', $memo), 'Stored confirmation code is not a 32-character HMAC');
confirmationCodeAssert($memo === opConfirmCodeMemo('482913'), 'Confirmation code HMAC is not deterministic');
confirmationCodeAssert($memo !== opConfirmCodeMemo('482914'), 'Different confirmation codes produced the same HMAC');
$longCode = '0f59118c2b06d9852dfa04bd7a0a84ae';
$longMemo = opConfirmCodeMemo($longCode);
confirmationCodeAssert((bool) preg_match('/^[a-f0-9]{32}$/', $longMemo), 'Long confirmation code HMAC has the wrong format');
confirmationCodeAssert($longMemo !== $longCode, 'Long confirmation code is stored in plaintext');

$db = new class {
	public array $calls = array();

	public function count($table, $filter = '', $values = null, $field = ''): int
	{
		$this->calls[] = array($table, $filter, $values, $field);
		return count($this->calls) === 1 ? 1 : 0;
	}
};

$code = opConfirmGenerateCode();
confirmationCodeAssert((bool) preg_match('/^[a-f0-9]{32}$/', $code), 'Email confirmation code is not exactly 32 hexadecimal characters');
confirmationCodeAssert(count($db->calls) === 2, 'Confirmation code collision was not retried');
confirmationCodeAssert($db->calls[0][0] === 'Hist', 'Confirmation code checked the wrong table');
confirmationCodeAssert($db->calls[0][1] === 'hOper=? and (hMemo=? or hMemo=?)', 'Confirmation code did not check historical code collisions');
confirmationCodeAssert($db->calls[0][2][0] === 'CONFIRM', 'Confirmation code checked the wrong operation');
confirmationCodeAssert(
	(bool) preg_match('/^[a-f0-9]{32}$/', $db->calls[0][2][1]),
	'Confirmation code collision check did not use the stored HMAC'
);
confirmationCodeAssert(
	(bool) preg_match('/^[a-f0-9]{32}$/', $db->calls[0][2][2]),
	'Confirmation code collision check did not protect legacy plaintext codes'
);

$smsCode = opConfirmGenerateCode(6);
confirmationCodeAssert((bool) preg_match('/^[0-9]{6}$/', $smsCode), 'SMS confirmation code is not exactly 6 digits');

$db = new class {
	public int $calls = 0;

	public function count($table, $filter = '', $values = null, $field = ''): int
	{
		$this->calls++;
		return 1;
	}
};

$failedSafely = false;
try
{
	opConfirmGenerateCode();
}
catch (RuntimeException $e)
{
	$failedSafely = $e->getMessage() === 'Unable to generate a unique confirmation code';
}
confirmationCodeAssert($failedSafely, 'Confirmation code generator did not fail safely after repeated collisions');
confirmationCodeAssert($db->calls === 20, 'Confirmation code generator used an unexpected retry limit');

$template = file_get_contents(dirname(__DIR__) . '/tpl/confirm/index.twig');
confirmationCodeAssert(
	str_contains($template, "code_mode == 'long'")
		&& str_contains($template, "isset_IN('Code')")
		&& str_contains($template, 'name="Code"')
		&& str_contains($template, 'maxlength="60"')
		&& !str_contains($template, 'name="Code" value="{{ code_value }}" type="hidden"'),
	'Legacy long confirmation links do not use the visible full-length field'
);
confirmationCodeAssert(
	str_contains($template, "_SESSION._confirm_code_mode")
		&& str_contains($template, "{% set code_mode = 'long' %}")
		&& str_contains($template, "_IN('CodeMode')")
		&& str_contains($template, 'name="CodeMode" value="{{ code_mode }}"')
		&& !str_contains($template, "'confirm.choose_format'")
		&& !str_contains($template, '?mode=short'),
	'Direct confirmation visits do not use a universal full-length field'
);
$controller = file_get_contents(dirname(__DIR__) . '/module/confirm/index.php');
confirmationCodeAssert(
	str_contains($controller, '$memo !== $code') && str_contains($controller, "array('CONFIRM', \$code)"),
	'Legacy plaintext 32-character confirmation codes no longer have a lookup fallback'
);
confirmationCodeAssert(
	str_contains($controller, "unset(\$_SESSION['_confirm_code_mode'])"),
	'Confirmation channel hint is not cleared after a successful operation'
);
$library = file_get_contents(dirname(__DIR__) . '/module/confirm/lib.php');
confirmationCodeAssert(
	str_contains($library, "\$_SESSION['_confirm_code_mode'] = 'long'")
		&& str_contains($library, "\$_SESSION['_confirm_code_mode'] = 'short'"),
	'Confirmation preparation does not remember whether e-mail or SMS was used'
);
confirmationCodeAssert(
	str_contains($library, "\$params['channel'] = 'email'")
		&& str_contains($library, "\$params['channel'] = 'sms'")
		&& str_contains($library, 'function opConfirmResend(')
		&& str_contains($library, "\$channel === 'sms'")
		&& !str_contains($library, 'function opConfirmResendSMS('),
	'Resending a confirmation no longer preserves its original delivery channel'
);

echo "Confirmation code tests passed.\n";
