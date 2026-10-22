<?php

use HScript\Util\StringHelper;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\Money;
use HScript\Finance\Rate;

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
		$sum = (string)DecimalInput::read($sum_value);
		$commission = 0;
		$commission_ready = false;
		if (in_array($oper, array('CASHIN', 'CASHOUT', 'EX', 'TR')) and ($cid > 0) and ($sum > 0) and isset($_currs[$cid]))
		{
			if ($oper === 'CASHIN' && $_currs[$cid]['cCID'] !== 'CCT' && !empty($_cfg['Const_IntCurr']) && !empty($_cfg['Bal_SumInInternal'])) {
				$converted = opAmountFromBase($sum, $cid);
				if (is_string($converted)) throw new InvalidArgumentException($converted);
				$sum = (string)$converted;
			}
			$commission = opCalcComis($cid, $oper, $sum, $_user['uLevel'] >= 90);
			if (!is_string($commission))
			{
				$commission_ready = true;
				$commission = (string)$commission;
			}
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
		if ($oper === 'CASHIN' && $_currs[$cid]['cCID'] !== 'CCT' && !empty($_cfg['Const_IntCurr']) && !empty($_cfg['Bal_SumInInternal'])) {
			$converted = opAmountFromBase($sum_value, $cid);
			if (is_string($converted)) throw new InvalidArgumentException($converted);
			$sum = (string)$converted;
		} else
			$sum = (string)opMoney($sum_value, !empty($_cfg['Const_IntCurr']) && $_currs[$cid]['cCID'] !== 'CCT' && $oper === 'CASHOUT' ? 1 : $cid);
		$c = $_currs[$cid];
		$cid2 = $cid;
		$csum = 0;
		$sum2 = 0;
		$by_admin = $_user['uLevel'] >= 90;
		if ($_cfg['Const_IntCurr'] and $c['cCID'] !== 'CCT' and in_array($oper, array('CASHIN', 'CASHOUT')))
		{
			try {
				Rate::of((string)DecimalInput::read($_cfg['Bal_Rate' . $c['cCurrID']] ?? 0));
				$has_rate = true;
			} catch (InvalidArgumentException) { $has_rate = false; }
			if ($has_rate)
			{
				if ($oper == 'CASHIN')
				{
						$cid2 = 1;
					$csum = opCalcComis($cid, 'CASHIN', $sum, $by_admin);
					if (!is_string($csum))
					{
						$sum = (string)DecimalInput::read($sum)->minus($csum->decimal());
						$csum1 = opCalcComis($cid, 'EX', $sum, $by_admin);
						if (!is_string($csum1))
							$sum2 = opCalcEx($cid, 1, (string)DecimalInput::read($sum)->minus($csum1->decimal()));
					}
				}
				else
				{
					$cid2 = $cid;
					$csum1 = opCalcComis(1, 'EX', $sum, $by_admin);
					if (!is_string($csum1))
					{
						$sum2 = opCalcEx(1, $cid, (string)DecimalInput::read($sum)->minus($csum1->decimal()));
						if (!is_string($sum2))
						{
							$csum = opCalcComis($cid, 'CASHOUT', (string)$sum2, $by_admin);
							if (!is_string($csum))
								$sum2 = (string)DecimalInput::read((string)$sum2)->minus($csum->decimal());
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
				$sum2 = opCalcEx($cid, $cid2, (string)DecimalInput::read($sum)->minus($csum->decimal()));
		}
		else
		{
			$csum = opCalcComis($cid, $oper, $sum, $by_admin);
			if (!is_string($csum))
				$sum2 = (string)DecimalInput::read($sum)->minus($csum->decimal());
		}
		$csum = $csum instanceof Money ? (string)$csum : '0';
		if ($sum2 instanceof Money || $sum2 instanceof Decimal) $sum2 = (string)$sum2;
		if (!is_numeric($sum2))
			$sum2 = 0;
		$currency = StringHelper::textLangFilter($c['cCurr'], $_GS['lang']);
		$unit = htmlspecialchars((string)$currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$targetUnit = htmlspecialchars((string)StringHelper::textLangFilter($_currs[$cid2]['cCurr'] ?? '', $_GS['lang']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$commission = $csum > 0 ? '<b>' . (string)opMoney($csum, $cid) . '</b> <small>' . $unit . '</small>' : '-';
		$total = $sum2 > 0 ? '<b>' . (string)opMoney($sum2, $cid2) . '</b> <small>' . $targetUnit . '</small>' : '-';
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
catch (InvalidArgumentException | OverflowException $e)
{
	http_response_code(422);
}

?>
