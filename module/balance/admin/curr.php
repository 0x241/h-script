<?php

use HScript\Template\View;
use HScript\Payment\PaymentManager;
use HScript\Finance\CurrencyInput;
use HScript\Payment\Provider\ProviderCatalog;

$_auth = 90;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

useLib('balance');

$table = 'Currs';
$id_field = 'cID';
$out_link = moduleToLink('balance/admin/currs');
$el = null;
if ($requestedCurrency = _GETN('id'))
    $el = $db->fetch1Row($db->select($table, '*', "$id_field=?d", array($requestedCurrency)));
if (_GET('provider') === 'PAW' || ($el && $el['cCID'] === 'PAW'))
{
    // PAW uses the same payment-system route and encrypted shared profile.
    require __DIR__ . '/curr.paw.php';
    return;
}


$providerKind=_GET('provider');
if ($el && !empty($el['cProviderID'])) $providerKind=ProviderCatalog::kind($el['cCID']);
if (is_string($providerKind) && in_array($providerKind,ProviderCatalog::SHARED,true)) {
    require __DIR__.'/curr.provider.php';return;
}
if ($el && in_array($el['cCID'],PaymentManager::DEPRECATED_IDS,true)) {
    // Retired rows remain financial references, never editable connections.
    http_response_code(410);View::setPage('retired_currency',true);View::showPage();return;
}
$providerCatalog=(new ProviderCatalog($db))->available();

try
{

	$cids = $paymentManager->definitions();

	if (View::sendedForm('', 'add'))
	{
		View::checkFormSecurity('add');

        $selected=_IN('PSys');
        if (!is_string($selected) || !isset($providerCatalog[$selected])) View::setError('psys_not_selected','add');
        if ($selected==='PAW' || in_array($selected,ProviderCatalog::SHARED,true)) goToURL(moduleToLink().'?provider='.$selected);
        $gateway=_IN('Gateway');
        if (!is_string($gateway) || !in_array($gateway,$providerCatalog[$selected]['gateways'],true)) goToURL(moduleToLink().'?add&provider='.$selected);
        $c=$cids[$gateway];
        try {
            $id=HScript\Database\TransactionScope::run($db->link,static function() use ($db,$selected,$gateway,$c): int {
                // Serialize even two first additions: the provider catalog row
                // exists before either currency row, unlike an empty-CID gap.
                $q=$db->link->prepare('SELECT pgAvailable FROM PaymentGatewayCatalog WHERE pgCode=? FOR UPDATE');$q->execute([$selected]);
                if ((int)$q->fetchColumn()!==1) throw new DomainException('psys_not_selected');
                $q=$db->link->prepare('SELECT cID FROM Currs WHERE cCID=? ORDER BY cID LIMIT 1 FOR UPDATE');$q->execute([$gateway]);
                if ($q->fetchColumn()!==false) throw new DomainException('psys_already_exists');
                $q=$db->link->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cDisabled,cHidden,cCASHINMode,cCASHOUTMode) VALUES(?,?,?,?,1,1,0,0)');
                $q->execute([$gateway,$c[1],$c[0],$c[1]]);return (int)$db->link->lastInsertId();
            });
            $catalogCache->invalidate(HScript\Cache\CatalogCache::CURRENCIES);
        } catch (DomainException $error) { View::setError($error->getMessage(),'add'); }
		if ($id)
			View::showInfo('Added', moduleToLink() . "?id=$id");
		View::showInfo('*Error');
	}

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if (($cid = _INN('cID')) and (_IN('cCID')))
		{
			$existing = $db->fetch1Row($db->select($table, '*', 'cID=?d', array($cid)));
			if (!$existing || !empty($existing['cProviderID']) || $existing['cCID'] === 'PAW' || $existing['cCID'] !== _IN('cCID') || !$paymentManager->has($existing['cCID']) && $existing['cCID']!=='*')
				View::setError('provider_profile_only');
            if ($existing['cCID']!=='*' && (int)($_IN['cDisabled']??0)===0) {
                $other=$db->fetch1Row($db->select('Currs','cID','cCID=? AND cDisabled=0 AND cID<>?d',[$existing['cCID'],$cid]));
                if ($other) View::setError('provider_profile_only');
            }
			$a = $_IN;
			try { $a = CurrencyInput::normalize($a); }
			catch (InvalidArgumentException $e) { View::setError($e->getMessage()); }
			opDecodeCurrParams($db->fetch1Row($db->select($table, '*', 'cID=?d', array($cid))), $p, $p_sci, $p_api);
			$t = time();
			$key = $cid . $a['cCID'] . $t;
			View::setError($p = opEditToCurrParams($paymentManager->fields($a['cCID']), $p, (array)_IN('P'), 'P'));
			View::setError($p_sci = opEditToCurrParams($paymentManager->fields($a['cCID'], 'sci'), $p_sci, (array)_IN('PSCI'), 'PSCI'));
			View::setError($p_api = opEditToCurrParams($paymentManager->fields($a['cCID'], 'api'), $p_api, (array)_IN('PAPI'), 'PAPI'));
			$a['cParams'] = encodeArrayToStr($p, $key);
			$a['cParamsSCI'] = encodeArrayToStr($p_sci, $key, 2);
			$a['cParamsAPI'] = encodeArrayToStr($p_api, $key, 3);
			$a['cMTS'] = timeToStamp($t);
			if ($id = $db->save($table, $a,
				'cDisabled, cHidden, cName, cCurr, cNumDec, ' .
				'cCASHINMode, cCASHINMin, cCASHINMax, cCASHINInt, cCASHINComis, cCASHINComisMin, cCASHINComisMax, ' .
				'cCASHOUTMode, cCASHOUTMin, cCASHOUTMax, cCASHOUTInt, cCASHOUTComis, cCASHOUTComisMin, cCASHOUTComisMax, cCASHOUTLimitPer, cCASHOUTLimit, ' .
				'cEXMode, cEXOut, cEXIn, cEXMin, cEXMax, cEXInt, cEXComis, cEXComisMin, cEXComisMax, ' .
				'cTRMode, cTRMin, cTRMax, cTRInt, cTRComis, cTRComisMin, cTRComisMax, ' .
				'cBUYMode, ' .
				'cSELLMode, ' .
				'cBUY2Mode, ' .
				'cSELL2Mode, ' .
				'cGIVEMode, ' .
				'cTAKEMode, ' .
				'cParams, cParamsSCI, cParamsAPI, cMTS', $id_field))
				View::showInfo('Saved', $out_link . "?id=$id");
		}
		View::showInfo('*Error');
	}

}
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select($table, '*', "$id_field=?d", array($id)));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	opDecodeCurrParams($el, $el['P'], $el['PSCI'], $el['PAPI']);
	View::stampArrayToStr($el, 'cMTS');
	View::setPage('el', $el, 2);
    View::setPage('legacy_provider_kind',in_array(ProviderCatalog::kind($el['cCID']),ProviderCatalog::SHARED,true)?ProviderCatalog::kind($el['cCID']):'');
	View::setPage('cName', isset($cids[$el['cCID']]) ? $cids[$el['cCID']][0] : $el['cName']);
	View::setPage('pfields', opCurrParamsToEdit($paymentManager->fields($el['cCID']), 'P'), 1);
	View::setPage('sfields', opCurrParamsToEdit($paymentManager->fields($el['cCID'], 'sci'), 'PSCI'), 1);
	View::setPage('afields', opCurrParamsToEdit($paymentManager->fields($el['cCID'], 'api'), 'PAPI'), 1);
	if (isset($_GET['testapi']))
	{
		$res = $paymentManager->getBalance($el['cCID'], $el['PAPI']);
		View::setPage('res', $res);
	}
}
else
{
    $list=[];
    if (is_string($providerKind) && isset($providerCatalog[$providerKind]) && !in_array($providerKind,ProviderCatalog::SHARED,true) && $providerKind!=='PAW') {
        $used=$db->fetchRows($db->select('Currs','cCID'),1);$variants=[];
        foreach ($providerCatalog[$providerKind]['gateways'] as $gid) if (!in_array($gid,$used,true)) $variants[$gid]=$cids[$gid][0];
        View::setPage('add_provider_kind',$providerKind);View::setPage('add_provider_name',$providerCatalog[$providerKind]['name']);View::setPage('add_variants',$variants);
    }
    foreach ((new ProviderCatalog($db))->connectable() as $kind=>$provider) $list[$kind]=$provider['name'];
	View::setPage('cids', $list); // available CIDs
}

View::showPage();

?>
