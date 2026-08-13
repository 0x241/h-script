<?php

use HScript\Template\View;

// var $module must be set!

$setup_module = $module;

$_auth = 90;
require_once('module/auth.php');

try
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if ($_GS['demo'] and ($_user['uLevel'] < 99))
			View::showInfo('*Denied');

		$current_setup = $db->fetchIDRows(
			$db->select('Cfg', '*', 'Module=?', array($setup_module)),
			'Val',
			'Prop'
		);
		$preserve_empty = isset($setup_preserve_empty) && is_array($setup_preserve_empty)
			? $setup_preserve_empty
			: array();
		$db->delete('Cfg', 'Module=?', array($setup_module));
		foreach ($_IN as $p => $v)
			if (substr($p, -4) != '_btn')
			{
				if ($v === '' && in_array($p, $preserve_empty, true) && array_key_exists($p, $current_setup))
					$v = $current_setup[$p];
				$db->insert('Cfg', array(
					'Module' => $setup_module,
					'Prop' => $p,
					'Val' => $v
				));
			}
		View::showInfo('Saved');
	}

}
catch (FormAbortException $e)
{
}

View::setPage('cfg', $db->fetchIDRows($db->select('Cfg', '*', 'Module=?', array($setup_module)), 'Val', 'Prop'));

?>
