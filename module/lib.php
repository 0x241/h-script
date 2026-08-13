<?php

use HScript\Util\StringHelper;

use HScript\Cache\CatalogCache;
use HScript\Template\View;
use HScript\Database\Connection;

/*
	Module: Global
*/

function opAddHist($oper, $uid = -1, $params = '', $memo = '', $tag = 0, $cid = 0, $sum = 0, $oid = 0)
{
	global $_GS, $db;
	if ($uid < 0) 
		$uid = _uid();
	// hcID, hSum, hParams, hTag, hMemo, hoID
	$db->insert('Hist', 
		array(
			'hTS' => timeToStamp(), 
			'hOper' => $oper,
			'huID' => $uid,
			'hIP' => $_GS['client_ip'],
			'hParams' => arrayToStr($params),
			'hTag' => $tag,
			'hMemo' => $memo
		)
	);
}

function opReadUser($uid) 
{
	global $db;
	return (($uid > 0) ? $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', '*', 'uID=?d', array($uid))) : array());
}

function opUserConsts($usr, $a = array())
{
	$a['uid'] = $usr['uID'];
	$a['name'] = $usr['aName'];
	$a['login'] = $usr['uLogin'];
	return $a;
}

// Event

function opEvent($oper, $uid = -1, $params = array())
{
	global $_onload;
	if ($uid < 0)
		$uid = _uid();
	foreach ($_onload as $m => $s)
	{
		$m = 'on' . $m . 'Event';
		if (function_exists($m))
			$m($oper, $uid, $params);
	}
}

// Pages

function opPageReset()
{
	unset($_SESSION['_PL'][View::getFormName()]);
}

function opPageGet($page, $page_size, $table,
	$fields = '*', $filter = '', $ph_values = null, $orders = array(), $order = '', $id_field = Connection::DEFAULT_ID_FIELD)
{
	$page_range = 10; // line size
	global $db, $_GS;
	foreach ($orders as $f => $a)
	{
		if (!$a)
		{
			if ($f == $id_field)
				$orders[$f] = array("$f desc", $f);
			else
				$orders[$f] = array($f, "$f desc");
		}
	}
	$form = View::getFormName();
	$pl = isset($_SESSION['_PL'][$form]) ? $_SESSION['_PL'][$form] : array();
	if (!is_array($pl))
		$pl = array();
	$params = array('Orders' => $orders, 'Order' => '');
	if ($orders)
	{
		if (!$order)
		{
			$order = isset($pl['Order']) ? $pl['Order'] : '';
			if (!$order)
				$order = key($orders);
		}
		else
			$pl['Page'] = 0;
		if (!isset($orders[$order]))
			$order = key($orders);
		$a = StringHelper::textRight($order, 1);
		if (is_numeric($a))
			$order = StringHelper::textLeft($order, -1);
		else
			$a = 0;
		if (!isset($orders[$order][$a]) or !$orders[$order][$a])
			$a = 0;
		$sorder = isset($orders[$order][$a]) ? $orders[$order][$a] : '';
		if ($sorder)
		{
			$order .= $a;
			$pl['Order'] = $order;
			$params['Order'] = $order;
		}
	}
	else
		$sorder = '';
	$rows_count = $db->count($table, $filter, $ph_values);
	$pages_count = ceil($rows_count / $page_size);
	$params['RowsCount'] = $rows_count;
	$params['PagesCount'] = $pages_count;
	if ($page == 0)
		$page = isset($pl['Page']) ? $pl['Page'] : 0;
	$page = 0 + $page;
	if ($page > $pages_count)
		$page = $pages_count;
	if ($page < 1)
		$page = 1;
	$pl['Page'] = $page;
	$params['Page'] = $page;
	if ($pages_count > 1)
	{
		$ir2 = floor(($page_range - 1) / 2);
		$ir = 2 * $ir2; // index range
		if ($pages_count <= ($ir + 1))
		{
			$is = 1;
			$ie = $pages_count;
		}
		else 
		{
			$is = $page - $ir2; // index start
			if ($is < 1) 
				$is = 1;
			elseif (($is + $ir) > $pages_count) 
			{
				$is = $pages_count - $ir;
				if ($is < 1) 
					$is = 1;
			}
			$ie = $is + $ir; // range done (index end)
		}
		$pages = array();
		if ($is > 1)
			$pages[] = array('<<', 1);
		if ($page > 1)
			$pages[] = array('<', $page - 1);
		if ($page - 50 > 1)
			$pages[] = array($page - 50, $page - 50);
		if ($page - 10 > 1)
			$pages[] = array($page - 10, $page - 10);
		for ($i = $is; $i <= $ie; $i++)
			$pages[] = array($i, $i);
		if ($page + 10 < $pages_count)
			$pages[] = array($page + 10, $page + 10);
		if ($page + 50 < $pages_count)
			$pages[] = array($page + 50, $page + 50);
		if ($page < $pages_count)
			$pages[] = array('>', $page + 1);
		if ($ie < $pages_count)
			$pages[] = array('>>', $pages_count);
		$params['Pages'] = $pages;
	}
	$_SESSION['_PL'][$form] = $pl;
	View::setPage('pl_params', $params);
	return $db->fetchIDRows(
		$db->select($table, $fields, $filter, $ph_values, $sorder,
			'' . (($page - 1) * $page_size) . ',' . $page_size), false, $id_field);
}

function opArrayPageGet(int $page, int $page_size, array $rows): array
{
	$rows_count = count($rows);
	$pages_count = (int)ceil($rows_count / $page_size);
	$form = View::getFormName();
	$state = isset($_SESSION['_PL'][$form]) && is_array($_SESSION['_PL'][$form])
		? $_SESSION['_PL'][$form]
		: array();
	if ($page <= 0)
		$page = (int)($state['Page'] ?? 1);
	if ($page > $pages_count && $pages_count > 0)
		$page = $pages_count;
	if ($page < 1)
		$page = 1;
	$state['Page'] = $page;
	$_SESSION['_PL'][$form] = $state;
	$params = array(
		'Orders' => array(),
		'Order' => '',
		'RowsCount' => $rows_count,
		'PagesCount' => $pages_count,
		'Page' => $page,
	);
	if ($pages_count > 1)
	{
		$page_range = 10;
		$radius = (int)floor(($page_range - 1) / 2);
		$start = max(1, min($page - $radius, $pages_count - (2 * $radius)));
		$end = min($pages_count, $start + (2 * $radius));
		$pages = array();
		if ($start > 1)
			$pages[] = array('<<', 1);
		if ($page > 1)
			$pages[] = array('<', $page - 1);
		if ($page - 50 > 1)
			$pages[] = array($page - 50, $page - 50);
		if ($page - 10 > 1)
			$pages[] = array($page - 10, $page - 10);
		for ($number = $start; $number <= $end; $number++)
			$pages[] = array($number, $number);
		if ($page + 10 < $pages_count)
			$pages[] = array($page + 10, $page + 10);
		if ($page + 50 < $pages_count)
			$pages[] = array($page + 50, $page + 50);
		if ($page < $pages_count)
			$pages[] = array('>', $page + 1);
		if ($end < $pages_count)
			$pages[] = array('>>', $pages_count);
		$params['Pages'] = $pages;
	}
	View::setPage('pl_params', $params);
	return array_slice($rows, ($page - 1) * $page_size, $page_size, true);
}

function opCachedCatalogPageGet(
	string $catalog,
	string $variant,
	int $page,
	int $page_size,
	callable $callback
): array
{
	global $catalogCache;
	$rows = $catalogCache->remember($catalog, $variant, $callback);
	return opArrayPageGet($page, $page_size, $rows);
}

// Write to Cfg

function opWriteCfg($m, $f, $v, $flt = '', $fltarr = array())
{
	global $db;
	return $db->replace('Cfg', array(
			'Module' => $m,
			'Prop' => $f,
			'Val' => $v
		), '',
		'Module=? and Prop=?' . $flt, array_merge(array($m, $f), $fltarr)
	);
}

function cn(&$v)
{
	$v = str_replace(' ', '', $v);
	$v = str_replace(',', '.', $v);
	return $v;
}

?>
