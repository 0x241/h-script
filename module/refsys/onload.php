<?php

use HScript\Util\StringHelper;

function _refPay($uid, $usr, $cid, $sum, $perc, $level = 0) // uID, uLogin
{
	useLib('balance');
	return opOperCreate($uid, 'REF', $cid, _zr(calcPerc($sum, $perc, 6), $cid),
		array('tag' => $usr['uID'], 'reflogin' => $usr['uLogin']),
		$usr['uLogin'] . StringHelper::valueIf($level > 1, " L$level"), true, true);
}

function _refPayByLevels($uid, $cid, $sum, $percs, $type, $userproc)
{
	global $db;
	if (!$percs)
		return false;
	if ($usr = opReadUser($uid))
		if ($uid = $usr['uRef'])
			if ($type > 0)
			{
				switch ($type)
				{
				case 3: // Tier by the total principal of directly referred users.
					$a = $db->fetchRows($db->select('Users', 'uID', 'uRef=?d', array($uid))); // reflist
					$u=array();
					foreach ($a as $v) $u[]=$v['uID'];
					$n = $db->fetch1($db->select('Deps', 'SUM(dZD)', '(duID ?i)', array($u)));
					break;
				case 4: // Tier by the sponsor's own active deposit principal.
					$n = $db->fetch1($db->select('Deps', 'SUM(dZD)', 'duID=?d and dState=1', array($uid)));
					break;
				default:
					$n = $db->count('Users', 'uRef=?d and (SELECT COUNT(*) FROM Deps WHERE duID=uID)>0', array($uid));
				}
				$rusr = opReadUser($uid);
				if ($a = asArray($rusr[$userproc], HS2_NL))
					$percs = $a;
				$perc = 0;
				// Threshold entries use "minimum-percentage" notation. The last
				// threshold not exceeding the metric determines the single payout.
				foreach ($percs as $i => $l)
					if ($l = explode('-', $l, 2))
					{
						cn($l[0]);
						cn($l[1]);
						if ((0 + $l[0]) > $n)
							break;
						else
							$perc = (0 + $l[1]);
					}
				_refPay($uid, $usr, $cid, $sum, $perc, $i + 1);
			}
			else
				// Flat mode walks the uRef chain and may override every level's
				// percentage with that sponsor's per-user configuration.
				foreach (asArray($percs) as $i => $perc)
					if ($rusr = opReadUser($uid)) // upref
					{
						if ($a = asArray($rusr[$userproc], HS2_NL))
							$perc = $a[$i];
						cn($perc);
						if ($perc > 0)
							_refPay($uid, $usr, $cid, $sum, $perc, $i + 1);
						$uid = $rusr['uRef'];
					}
					else
						break;
}

function onRefSysEvent($oper, $uid, $params)
{
	global $db, $_cfg;
	switch ($oper)
	{
	case 'Oper':
		if ($params['oper'] != 'CASHIN')
			break;
		if ($params['cid2'])
		{
			$params['cid'] = $params['cid2'];
			$params['sum'] = $params['sum2'];
		}
		_refPayByLevels($uid, $params['cid'], $params['sum'], $_cfg['Ref__Perc'], $_cfg['Ref_Type'], 'aPerc');
		break;
	case 'DepoCreate':
		_refPayByLevels($uid, $params['cid'], $params['sum'], StringHelper::exValue($_cfg['Ref__DPerc'], $params['percs']), $_cfg['Ref_DType'], 'aDPerc');
		break;
	case 'DepoCharge':
		_refPayByLevels($uid, $params['cid'], $params['sum'], StringHelper::exValue($_cfg['Ref__PPerc'], $params['percs']), $_cfg['Ref_PType'], 'aPPerc');
		break;
	default:
		return;
	}
}

?>
