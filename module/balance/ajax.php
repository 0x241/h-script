<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');
useLib('balance');

try 
{

	switch (_RQ('do'))
	{
	case 'getcommission':
		$oper = strtoupper((string)_RQ('Oper'));
		$cid = intval(_RQ('PSys'));
		$sum_value = (string)_RQ('Sum');
		cn($sum_value);
		$sum = (float)$sum_value;
		$commission = 0;
		$commission_ready = false;
		if (in_array($oper, array('CASHIN', 'CASHOUT', 'EX', 'TR')) and ($cid > 0) and ($sum > 0) and isset($_currs[$cid]))
		{
			$commission = opCalcComis($cid, $oper, $sum, $_user['uLevel'] >= 90);
			if (!is_string($commission))
				$commission_ready = true;
			else
				$commission = 0;
		}
		View::setPage('commission_ready', $commission_ready, 0);
		View::setPage('commission_value', $commission, 0);
		View::setPage('commission_cid', $cid, 0);
		View::showPage('commission', 'balance');
		break;
	case 'getcurr':
		$cid = intval(_RQ('cid'));
		$c = isset($_currs[$cid]) ? $_currs[$cid] : null;
		if ($c && isset($c['cCurr']))
			echo('<small>' . StringHelper::textLangFilter($c['cCurr'], $_GS['lang']) . '</small>');
		else
			echo('');
		break;
	case 'getsum':
		$is_html = _RQ('format') == 'html';
		$sum_value = isset($_REQUEST['sum']) ? $_REQUEST['sum'] : _RQ('Sum');
		cn($sum_value);
		$oper = strtoupper((string)StringHelper::valueIf(_RQ('oper'), _RQ('oper'), _RQ('Oper')));
		$cid = intval(StringHelper::valueIf(_RQ('cid'), _RQ('cid'), _RQ('PSys')));
		if (!$cid or !isset($_currs[$cid]))
		{
			if ($is_html)
			{
				View::setPage('oper', $oper, 0);
				View::setPage('preview_currency', '—', 0);
				View::setPage('preview_commission', '—', 0);
				View::setPage('preview_total', '—', 0);
				View::showPage('admin/sum.preview', 'balance');
			}
			else
				echo(json_encode(array('', '-', '-')));
			break;
		}
		$sum = _zr($sum_value, $cid);
		$c = $_currs[$cid];
		$cid2 = $cid;
		$csum = 0;
		$sum2 = 0;
		$by_admin = $_user['uLevel'] >= 90;
		if ($_cfg['Const_IntCurr'] and in_array($oper, array('CASHIN', 'CASHOUT')))
		{
			$zc = $_cfg['Bal_Rate' . $c['cCurrID']];
			if ($zc > 0)
			{
				if ($oper == 'CASHIN')
				{
						$cid2 = 1;
					$csum = opCalcComis($cid, 'CASHIN', $sum, $by_admin);
					if (!is_string($csum))
					{
						$sum -= $csum;
						$csum1 = opCalcComis($cid, 'EX', $sum, $by_admin);
						if (!is_string($csum1))
							$sum2 = opCalcExSum(true, $cid, $sum - $csum1);
					}
				}
				else
				{
					$cid2 = $cid;
					$csum1 = opCalcComis($cid, 'EX', $sum, $by_admin);
					if (!is_string($csum1))
					{
						$sum2 = opCalcExSum(false, $cid, $sum - $csum1);
						if (!is_string($sum2))
						{
							$csum = opCalcComis($cid, 'CASHOUT', $sum2, $by_admin);
							if (!is_string($csum))
								$sum2 -= $csum;
						}
					}
				}
			}
		}
		elseif ($oper == 'EX')
		{
			$cid2 = intval(StringHelper::valueIf(_RQ('cid2'), _RQ('cid2'), _RQ('PSys2')));
			$csum = opCalcComis($cid, $oper, $sum, $by_admin);
			if (!is_string($csum))
				$sum2 = opCalcEx($cid, $cid2, $sum - $csum);
		}
		else
		{
			$csum = opCalcComis($cid, $oper, $sum, $by_admin);
			if (!is_string($csum))
				$sum2 = $sum - $csum;
		}
		if (is_string($csum))
			$csum = 0;
		if (is_string($sum2))
			$sum2 = 0;
		$currency = StringHelper::textLangFilter($c['cCurr'], $_GS['lang']);
		$commission = StringHelper::valueIf($csum > 0, _z($csum, $cid, 2), '-');
		$total = StringHelper::valueIf($sum2 > 0, _z($sum2, $cid2, 2), '-');
		if ($is_html)
		{
			View::setPage('oper', $oper, 0);
			View::setPage('preview_currency', $currency, 0);
			View::setPage('preview_commission', StringHelper::valueIf($commission == '-', '—', $commission), 0);
			View::setPage('preview_total', StringHelper::valueIf($total == '-', '—', $total), 0);
			View::showPage('admin/sum.preview', 'balance');
		}
		else
			echo(json_encode(array('<small>' . $currency . '</small>', $commission, $total)));
		break;
	}

}
catch (FormAbortException $e)
{
}

?>
