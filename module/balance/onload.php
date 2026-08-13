<?php

function onBalanceEvent($oper, $uid, $params)
{
	global $_cfg, $_currs;
	
	if (($oper != 'RegComplete') or ($_cfg['Bal_RegBonus'] <= 0))
		return;
	useLib('balance');
	if ($_cfg['Const_IntCurr'])
		opOperCreate($uid, 'BONUS', 1, $_cfg['Bal_RegBonus'], array(), 'Bonus', true, true);
	else
		foreach ($_currs as $cid => $c)
			if ($x = $_cfg['Bal_Rate' . $c['cCurrID']])
				opOperCreate($uid, 'BONUS', $cid, _zr($_cfg['Bal_RegBonus'] / $x, $cid), array(), 'Bonus', true, true);
	return true;
}

?>