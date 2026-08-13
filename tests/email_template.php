<?php

use HScript\Mail\EmailTemplate;
use HScript\Template\View;

require dirname(__DIR__) . '/vendor/autoload.php';

function emailTemplateAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$html = EmailTemplate::render(
	'Подтверждение <входа>',
	"Здравствуйте!\nПерейдите по <a href=\"https://example.test/confirm\">ссылке</a>.",
	'H-Script & Partners',
	'https://example.test/'
);

emailTemplateAssert(str_contains($html, '<!doctype html>'), 'HTML document wrapper is missing');
emailTemplateAssert(str_contains($html, '>H</td>') && str_contains($html, '>Script</td>'), 'Email brand is missing');
emailTemplateAssert(!str_contains($html, '>H-Script</td>'), 'Email logo repeats the H letter');
emailTemplateAssert(str_contains($html, 'Подтверждение &lt;входа&gt;'), 'Subject was not escaped');
emailTemplateAssert(str_contains($html, 'H-Script &amp; Partners'), 'Site name was not escaped');
emailTemplateAssert(str_contains($html, 'href="https://example.test"'), 'Site URL was not normalized');
emailTemplateAssert(str_contains($html, 'Здравствуйте!<br>'), 'Plain-text line breaks were not preserved');
emailTemplateAssert(str_contains($html, 'mso-hide:all;">Здравствуйте! Перейдите по ссылке.</div>'), 'Email preview does not use the content after the subject');
emailTemplateAssert(!str_contains($html, 'mso-hide:all;">Подтверждение'), 'Email preview repeats the subject');
emailTemplateAssert(str_contains($html, 'max-width:640px'), 'Responsive card width is missing');
emailTemplateAssert(!str_contains($html, 'text-transform:uppercase'), 'Redundant brand eyebrow was rendered above the subject');
emailTemplateAssert(!str_contains($html, '&bull;'), 'Email footer contains the obsolete bullet separator');
emailTemplateAssert(!str_contains($html, '&copy;'), 'Email footer contains an unnecessary copyright entity');
emailTemplateAssert(!str_contains($html, '&nbsp;'), 'Email template contains a visible non-breaking-space artifact');
emailTemplateAssert(str_contains($html, 'min-width:40px;height:40px;table-layout:fixed'), 'Email logo mark can be compressed by the mail client');
emailTemplateAssert(!str_contains($html, '<script'), 'Email template contains executable script');

$invalidUrlHtml = EmailTemplate::render('Test', 'Message', 'H-Script', 'javascript:alert(1)');
emailTemplateAssert(!str_contains($invalidUrlHtml, 'javascript:'), 'Unsafe root URL was rendered');

$_GS = array(
	'lang' => 'en',
	'default_lang' => 'en',
	'client_ip' => '203.0.113.10',
	'root_url' => 'https://example.test/',
	'site_name' => 'H-Script',
	'mode' => '',
	'theme' => '',
);
$_cfg = array('Sys_SiteName' => 'H-Script');
$params = array(
	'name' => 'Alex <Admin>',
	'login' => 'alex',
	'code' => '482913',
	'url' => 'https://example.test/confirm',
);
$english = View::emailContent('AskConfirmSECLOGIN', $params, 'en');
$russian = View::emailContent('AskConfirmSECLOGIN', $params, 'ru');
emailTemplateAssert($english['subject'] === 'Confirm account sign-in', 'English email subject is wrong');
emailTemplateAssert($russian['subject'] === 'Подтверждение входа в аккаунт', 'Russian email subject is wrong');
emailTemplateAssert(str_contains($english['message'], 'Hello, <strong>Alex &lt;Admin&gt;</strong>!'), 'Username is not emphasized or escaped');
emailTemplateAssert(str_contains($english['message'], 'account <strong>alex</strong>'), 'Account login is not emphasized');
emailTemplateAssert(str_contains($english['message'], "</b>\n\nEnter it on the site"), 'Confirmation action spacing is missing');
emailTemplateAssert(!str_contains($english['message'], 'Зафиксирована'), 'English email contains Russian content');
$confirmationHtml = EmailTemplate::render($english['subject'], $english['message'], 'H-Script', 'https://example.test');
emailTemplateAssert(
	preg_match('/mso-hide:all;">([^<]*)<\/div>/', $confirmationHtml, $previewMatch) === 1,
	'Confirmation email preview is missing'
);
emailTemplateAssert(str_contains($previewMatch[1], 'A sign-in attempt from a new IP address'), 'Confirmation preview omits the message body');
emailTemplateAssert(!str_contains($previewMatch[1], '482913'), 'Confirmation preview exposes the security code');
emailTemplateAssert(!str_contains($previewMatch[1], 'H-Script'), 'Confirmation preview is polluted by the brand name');

$fallback = View::emailContent('OperBUY', array(
	'name' => 'Alex',
	'oper' => 'BUY',
	'oid' => 10,
	'sum' => '25.00',
	'curr' => 'USD',
	'psys' => 'Internal',
	'url' => 'https://example.test/operation',
), 'en');
emailTemplateAssert($fallback['subject'] === 'Operation processed', 'Generic operation fallback is missing');
emailTemplateAssert(str_contains($fallback['message'], 'Purchase #10'), 'Generic operation fallback exposes the internal BUY code');
emailTemplateAssert(!str_contains($fallback['message'], 'Operation BUY'), 'Generic operation fallback contains a raw operation code');

$accrualParams = array(
	'name' => 'Alex',
	'login' => 'alex',
	'oper' => 'CALCIN',
	'oid' => 205,
	'sum' => '100',
	'curr' => 'USD',
	'psys' => 'Internal',
	'tag' => 5,
	'url' => 'https://example.test/operation',
);
$accrual = View::emailContent('OperCALCIN', $accrualParams, 'ru');
emailTemplateAssert($accrual['subject'] === 'Начисление по вкладу', 'Accrual subject exposes the CALCIN code');
emailTemplateAssert(str_contains($accrual['message'], '100 USD со вклада #5'), 'Accrual email omits its currency');
emailTemplateAssert(str_contains($accrual['message'], "#5.\n\nОткрыть:"), 'Accrual action link has no top spacing');

$adminAccrual = View::emailContent('OperCALCIN', $accrualParams + array('uid' => 7), 'ru', 'admin');
emailTemplateAssert(str_contains($adminAccrual['message'], 'Начисление по вкладу #205'), 'Administrator email exposes the CALCIN code');
emailTemplateAssert(!str_contains($adminAccrual['message'], 'CALCIN'), 'Administrator email contains a raw operation code');

$balanceLib = file_get_contents(dirname(__DIR__) . '/module/balance/lib.php');
emailTemplateAssert(substr_count($balanceLib, "['cCurrID'] ?? ''") >= 2, 'Operation email currency has no cCurrID fallback');

$_cfg['Translations_en'] = json_encode(array(
	'mail.user.askconfirmseclogin.subject' => 'Custom sign-in subject',
), JSON_THROW_ON_ERROR);
View::translationClearCache();
$overridden = View::emailContent('AskConfirmSECLOGIN', $params, 'en');
emailTemplateAssert($overridden['subject'] === 'Custom sign-in subject', 'Database translation override was ignored');

$db = new class {
	public array $saved = array();

	public function replace(string $table, array $values): int
	{
		$this->saved = array('table' => $table, 'values' => $values);
		return 1;
	}
};
$_cfg = array('Sys_SiteName' => 'H-Script');
emailTemplateAssert(View::translationSaveAll(array(
	'en' => array('mail.preview.title' => 'Custom preview title'),
)), 'Translation override could not be saved');
emailTemplateAssert($db->saved['table'] === 'Cfg', 'Translation override used the wrong table');
$savedOverrides = json_decode($db->saved['values']['Val'], true, 512, JSON_THROW_ON_ERROR);
emailTemplateAssert(
	$savedOverrides === array('mail.preview.title' => 'Custom preview title'),
	'Translation override was not stored as a compact database diff'
);

echo "Email template tests passed.\n";
