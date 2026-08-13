<?php

use HScript\Mail\EmailTemplate;
use HScript\Template\View;

$module = 'Mail';
$setup_preserve_empty = array('Password');
require_once('module/admin/setup.php');

$preview_link = fullURL(moduleToLink());
if (_GET('preview'))
{
	$preview_lang = View::getLang(_GET('lang'));
	$content = View::emailContent('AskConfirmSECLOGIN', array(
		'name' => $preview_lang === 'ru' ? 'Иван' : 'Alex',
		'login' => 'demo-user',
		'code' => '0f59118c2b06d9852dfa04bd7a0a84ae',
		'url' => fullURL(moduleToLink('confirm')),
	), $preview_lang);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Frame-Options: SAMEORIGIN', true);
	echo EmailTemplate::render(
		$content['subject'] ?? 'H-Script',
		$content['message'] ?? 'Email translation is not configured.',
		(string)($_cfg['Sys_SiteName'] ?? 'H-Script'),
		(string)($_GS['root_url'] ?? '')
	);
	exit;
}

View::setPage('email_preview_url', $preview_link . '?preview=1', 0);
View::setPage('email_preview_langs', View::translationLanguages(), 0);
View::setPage('mail_translations_url', fullURL(moduleToLink('translations/admin')) . '?prefix=mail.', 0);

View::showPage();

?>
