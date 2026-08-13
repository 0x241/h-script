<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

if (!$_cfg['Ref_Word'])
	GoToURL('');

if (_uid())
{
	View::setPage('upref', $upref = opReadUser($_user['uRef']));
	View::setPage('reflogin', ($upref && isset($upref['uLogin'])) ? $upref['uLogin'] : '');
	View::setPage('refurl', $_GS['root_url'] . '?' . $_cfg['Ref_Word'] . '=' . $_user['uLogin']);

	$ccurs = array();
	if ($_cfg['Const_IntCurr'])
		$ccurs[] = $_currs[1]['cCurr'];
	else
		foreach ($_currs as $c)
			if (!in_array($c['cCurr'], $ccurs))
				$ccurs[] = $c['cCurr'];
	View::SetPage('ccurs', $ccurs);

	$ref_cids = array();
	foreach ($_vcurrs as $curr => $cids) {
		if (!in_array($curr, $ccurs))
			continue;
		$ref_cids[$curr] = reset($cids);
	}
	View::setPage('ref_cids', $ref_cids);

	$n = $_cfg['Ref_Levels'];
	if ($n < 1)
		$n = 1;
	View::setPage('maxlevel', $n);
	$level = _GETN('level');
	if (($level < 1) or ($level > $n))
		$level = 1;
	View::setPage('level', $level);
	$uuu = array();
	$a = array(_uid());
	for ($i = 1; $i <= $level; $i++)
	{
		$a = $db->fetchRows($db->select('Users', 'uID', 'uRef ?i', array($a)), 1, 'uID');
		$uuu[$i] = $a;
	}

	$list = opPageGet(_GETN('page'), 10, 'Users U LEFT JOIN AddInfo ON auID=uID',
		"U.uID, U.uLogin, U.uMail, AddInfo.aCTS, AddInfo.aCity",
		'U.uID ?i', array($uuu[$level]),
		array(
			'aCTS' => array(),
			'uLogin' => array(),
			'uMail' => array()
		),
		_GET('sort'), 'uID'
	);
	View::stampTableToStr($list, 'aCTS');
	View::setPage('list', $list);

	$list2 = array();
	foreach ($_vcurrs as $curr => $cids)
	{
		if (!in_array($curr, $ccurs))
			continue;

		$list2[$curr] = $db->fetchIDRows($db->select('Users', "uID,
			(SELECT SUM(dZD) FROM Deps WHERE dcID ?i and dState>=1 and duID=uID) AS ZDepo,
			(SELECT SUM(oSum) FROM Opers WHERE ocID ?i and oOper='REF' and ouID=" . _uid() . " and oState=3 and oTag=uID) AS ZRef",
			'uID ?i', array($cids, $cids, array_keys($list))), false, 'uID');
		$list2[$curr]['ZDepo'] = $db->fetch1($db->select('Deps', 'SUM(dZD)',
			'dcID ?i and dState>=1 and duID ?i', array($cids, $uuu[$level])));
		$list2[$curr]['ZRef'] = $db->fetch1($db->select('Opers', 'SUM(oSum)',
			"ocID ?i and oOper='REF' and ouID=" . _uid() . " and oState=3 and oTag ?i", array($cids, $uuu[$level])));
	}
	View::setPage('list2', $list2);
}

View::showPage();

?>
