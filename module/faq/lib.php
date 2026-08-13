<?php

use HScript\Util\StringHelper;
use HScript\Cache\CatalogCache;

function faqGetBlock($n = 0)
{
	global $db, $_cfg, $catalogCache;
	if ($n <= 0)
		$n = StringHelper::exValue(1, $_cfg['FAQ_InBlock']);
	$list = $catalogCache->remember(
		CatalogCache::FAQ,
		'block:' . $n,
		static fn(): array => $db->fetchIDRows(
			$db->select('FAQ', '*', 'fHidden=0', array(), 'RAND()', $n),
			false,
			'fID'
		)
	);
	return $list;
}

function faqDefaultRows()
{
	return array(
		array(
			'fQuestion' => 'How much CMS costs and how to buy?',
			'fAnswer' => 'The script is fully decrypted and free. You can support our efforts to maintain and develop the project with a donation.'
		),
		array(
			'fQuestion' => 'Where can I download the complete manual for working with the script?',
			'fAnswer' => 'Full manual you can download <a href="https://h-script.com/eng_manual.pdf">here</a>.'
		),
		array(
			'fQuestion' => 'I downloaded the package and unpacked it, but nothing works! Why?',
			'fAnswer' => 'System requires PHP 8 with mod_rewrite, mbstring, GD2 and cURL enabled.'
		),
		array(
			'fQuestion' => 'I want to install this script on your server. What steps should I take?',
			'fAnswer' => '<ul><li>Upload the script to the home directory on your server.</li><li>Set write permissions for logs, tpl_c and _config.php during setup.</li><li>Create a database.</li><li>Open the configurator and fill database/admin settings.</li><li>Configure an external scheduler to request /cron?auto every minute.</li></ul>'
		),
		array(
			'fQuestion' => 'What a... stupid design?',
			'fAnswer' => 'The script comes without a project design. A custom design can be integrated separately.'
		),
		array(
			'fQuestion' => 'I need special functions, which are not in CMS. Could you write it on a by-order basis?',
			'fAnswer' => 'Yes. Contact the project maintainer to discuss custom work.'
		),
		array(
			'fQuestion' => 'Important!',
			'fAnswer' => '<b>The entire responsibility for the work rests solely with the HYIP admin.</b>'
		)
	);
}

function faqSeedDefaultRows($db)
{
	if (!$db || $db->count('FAQ') > 0)
		return;
	$order = 10;
	foreach (faqDefaultRows() as $row)
	{
		$row['fCTS'] = timeToStamp();
		$row['fOrder'] = $order;
		$row['fHidden'] = 0;
		$db->insert('FAQ', $row, 'fHidden, fCTS, fOrder, fQuestion, fAnswer');
		$order += 10;
	}
}

?>
