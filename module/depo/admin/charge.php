<?php

use HScript\Template\View;
use HScript\Finance\FinancialForm;
use HScript\Finance\RequestDecimal;
use HScript\Finance\ManualCharge;

$_auth = 90;
require_once('module/auth.php');

$table = 'Plans';
$id_field = 'pID';
	
if ($_cfg['Depo_ChargeMode'] == 2)
{

	$cdate = opDepoLastManualChargeDate();

	try 
	{

		if (isset_IN('ids'))
		{
			FinancialForm::check('admin:depo:charge');
			$ids = array_values(array_unique(array_map('intval', (array)_IN('ids'))));
			sort($ids, SORT_NUMERIC);
			$p = (array)_IN('p'); // percents
			$percentages = [];
			foreach ($ids as $pid) $percentages[$pid] = RequestDecimal::normalize($p[$pid] ?? 0, true);
			$boundary = filter_var(_IN('financial_boundary'), FILTER_VALIDATE_INT);
			if (!$boundary || $boundary < 1) View::setError('date_wrong');
			$response = FinancialForm::execute('admin:depo:charge', ['plan_ids'=>$ids, 'percentages'=>$percentages, 'boundary'=>$boundary], static function () use ($ids, $percentages, $boundary, $cdate) {
				return ManualCharge::run($ids, $percentages, $boundary, $cdate);
			});
			View::setError($response);
			View::showInfo($response['info'], moduleToLink() . '?done=' . $response['done']);
		}

	} 
	catch (InvalidArgumentException|OverflowException $e)
	{
		http_response_code(422);
		xSysStop('Invalid financial percentage', true);
	}
	catch (FormAbortException $e)
{
}

	$list = opPageGet(_GETN('page'), 20, $table, "*, (SELECT COUNT('*') FROM Deps WHERE dpID=pID AND dState=1) AS cnt", 'pNoCalc=0', null, 
		array(
		), 
		_GET('sort'), $id_field
	);

	View::setPage('list', $list);
	View::setPage('cdate', View::timeToStr($cdate, 1));
	View::setPage('charge_boundary', $cdate);

}

View::showPage();

?>