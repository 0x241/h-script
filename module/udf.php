<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

// User Defined Functions module

function _z($z, $cid, $mode = 0) // 0-only sum / 1-sum and curr / 2-sum (+bold) and curr 
{
	global $_GS, $_cfg, $_currs;
	$r = isset($_currs[$cid]['cNumDec']) ? $_currs[$cid]['cNumDec'] : 0;
	if ($r <= 0)
		$r = $_cfg['UI_NumDec'];
	if ($z === '' || $z === null || !is_numeric($z))
		$z = 0;
	$z = number_format((float)$z, 0 + $r, '.', '');
	if ($mode < 1)
		return $z;
	if ($mode === 2)
		$z = "<b>$z</b>";
	$curr = isset($_currs[$cid]['cCurr']) ? $_currs[$cid]['cCurr'] : '';
	return $z . ' <small>' . StringHelper::textLangFilter($curr, $_GS['lang']) . '</small>';
}

function updateUserCounters()
{
	global $db, $_auth, $_user;
	$level = isset($_user['uLevel']) ? $_user['uLevel'] : 0;
	if ($level >= 90)
		View::setPage('count_aopers', $db->count('Opers', 'oState=2'));
	View::setPage('count_msg', intval($db->fetch1($db->select(
		'MBox LEFT JOIN Msg ON Msg.mID=MBox.bmID',
		'COUNT(DISTINCT CASE WHEN Msg.mGroup>0 THEN Msg.mGroup ELSE Msg.mID END)',
		'COALESCE(MBox.bRTS, 0)=0 and MBox.buID=?d and MBox.bDeleted=0', array(_uid())
	))));
	if ($_auth < 90)
	{
		View::setPage('count_opers', $db->count('Opers', 'oNTS>0 and ouID=?d', array(_uid())));
        View::setPage('count_tickets', $db->count('Tickets LEFT JOIN TMsg ON tID=mtID',
            'ISNULL(mRTS) and tuID=?d and tuID<>muID', array(_uid())));
	}
}

define('AVATAR_DIR', 'upload/avatar/');

?>
