<?php

use HScript\Util\StringHelper;

use HScript\Template\View;
use HScript\Finance\FinancialForm;
use HScript\Finance\FinancialOperationException;

$_auth = 70;
require_once('module/auth.php');

$table = 'Opers';
$id_field = 'oID';
$fform = 'opers_filter';
	
try 
{

	if (View::sendedForm('', $fform))
	{
		View::checkFormSecurity($fform);
		
		foreach (array('uLogin', 'oOper', 'ocID', 'oBatch', 'oState', 'oMemo') as $f)
			$_SESSION[$fform][$f] = _IN($f);
		opPageReset();
		if (isset($_GET['currency_id'])) goToURL(moduleToLink());
		goToURL();
	}

	if (View::sendedForm('clear', $fform))
	{
		View::checkFormSecurity($fform);
		
		unset($_SESSION[$fform]);
		opPageReset();
		if (isset($_GET['currency_id'])) goToURL(moduleToLink());
		goToURL();
	}

	if (isset_IN('ids'))
	{
		$action = null;
		foreach (['complete', 'confirm', 'cancel', 'del'] as $candidate)
			if (View::sendedForm($candidate)) $action = $candidate;
		if ($action === null) { http_response_code(403); xSysStop('Invalid financial action', true); }
		FinancialForm::check('admin:balance:bulk:' . $action);
		$requested = array_values(array_unique(array_map('intval', (array)_IN('ids'))));
		sort($requested, SORT_NUMERIC);
		$response = FinancialForm::execute('admin:balance:bulk:' . $action, ['operation_ids' => $requested], static function () use ($requested, $action, $table, $id_field) {
			global $db;
			$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', [$id_field, $requested], $id_field), 1);
			if (!$ids) return ['info' => '*NoSelected'];
			foreach ($ids as $id)
			{
				if ($action === 'complete') FinancialOperationException::check(opOperComplete(0, $id, [], true));
				elseif ($action === 'cancel') FinancialOperationException::check(opOperCancel(0, $id, [], true));
				elseif ($action === 'confirm')
				{
					$o = $db->fetch1Row($db->query('SELECT * FROM Opers WHERE oID=?d FOR UPDATE', [$id]));
					if ((int)$o['oState'] >= 3 || !in_array($o['oOper'], ['CASHIN', 'CASHOUT'], true)) continue;
					$p = strToArray($o['oParams2']);
					if (empty($p['date'])) $p['date'] = timeToStamp();
					if (empty($p['batch'])) $p['batch'] = 'M' . str_pad($id, 6, '0', STR_PAD_LEFT);
					$db->update('Opers', ['oParams2' => arrayToStr($p)], '', 'oID=?d', [$id]);
					if ((int)$o['oState'] < 2) FinancialOperationException::check(opOperConfirm($o['ouID'], $id, [], true));
					FinancialOperationException::check(opOperComplete($o['ouID'], $id, [], true));
				}
			}
			if ($action === 'del') $db->delete($table, '(oState >= 4) and (?# ?i)', [$id_field, $ids]);
			return ['info' => ''];
		});
		View::setError($response);
		View::showInfo($response['info']);
	}

} 
catch (FormAbortException $e)
{
}

if ($user = _GET('user'))
{
	$flt = 'uLogin=?';
	$fp = array($user);
	View::setPage('linkparams', "&user=$user");
}
else
{
	$flt = '1';
	$fp = array();
	$effectiveFilters=$_SESSION[$fform]??[];
	$currencyFilter=filter_var($_GET['currency_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
	if ($currencyFilter!==false && $currencyFilter!==null) {
		$effectiveFilters=['ocID'=>(string)$currencyFilter];
		View::setPage('linkparams','&currency_id='.$currencyFilter,0);
		View::setPage('sort_linkparams','&currency_id='.$currencyFilter,0);
	}
	View::setPage('effective_filters',$effectiveFilters);
	if ($effectiveFilters)
		foreach (array('uLogin' => '', 'oOper' => '0', 'ocID' => '0', 'oBatch' => '', 'oState' => '9', 'oMemo' => '') as $f => $v0)
			if (($v = $effectiveFilters[$f]??$v0) != $v0)
			{
				if ($f == 'uLogin')
				{
					$flt .= ' and ((Users.uLogin=?) or (Users.uMail=?))';
					$fp[] = $v;
				}
				else
					$flt .= ' and (' . $f . StringHelper::valueIf($f == 'oMemo', ' ?%)' ,'=?)');
				$fp[] = $v;
			}
}

$list = opPageGet(_GETN('page') ?: (isset($_GET['currency_id']) ? 1 : 0), 20,
	"$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID",
	"$table.*, uLogin, cName, cCurr, (oState=2) AS _Marked", 
	$flt, $fp,
	array(
		$id_field => array(),
		'uLogin' => array('uLogin', 'uLogin desc'),
		'oTS' => array('oCTS desc', 'oCTS', 'oTS desc', 'oTS')
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'oCTS, oTS');
$list=HScript\Payment\Provider\CurrencyPresentation::annotate($db,$list);
foreach ($list as $id => $r)
{
	$list[$id]['oParams'] = strToArray($r['oParams']);
	$list[$id]['oParams2'] = strToArray($r['oParams2']);
}

View::setPage('list', $list);

$currs = array();
foreach (HScript\Payment\Provider\CurrencyPresentation::annotate($db,$db->fetchIDRows($db->select('Currs','*'),false,'cID')) as $id => $c)
	$currs[$id] = $c['cName'];
View::setPage('currs', $currs);

global $_page;
if (empty($_page['up_category'])) {
	$_page['up_category'] = 'Баланс';
	$_page['up_modules'] = array(
		'balance/admin/opers' => 'Операции',
		'balance/admin/currs' => 'Платежные системы',
		'balance/admin/setup' => 'Настройки'
	);
}

View::showPage();

?>
