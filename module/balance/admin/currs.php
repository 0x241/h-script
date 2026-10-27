<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Currs';
$id_field = 'cID';

try 
{

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		View::showInfo('*CantComplete');
/*		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('del'))
			{
				// ??? chk Internal
//				$db->delete($table, '?# ?i', array($id_field, $ids));
			}
			
			View::showInfo();
		}
		else
			View::showInfo('*NoSelected');*/
	}

} 
catch (FormAbortException $e)
{
}

useLib('balance');

$visible = "cCID NOT IN (".implode(',',array_map([$db->link,'quote'],HScript\Payment\PaymentManager::DEPRECATED_IDS)).")";
$provider_filters = [];
$provider_currency_ids = [];
foreach ($db->fetchRows($db->select('Currs', 'cID,cCID', $visible)) as $currency) {
	$kind = HScript\Payment\Provider\ProviderCatalog::kind($currency['cCID']) ?? ((int)$currency['cID'] === 1 ? 'INTERNAL' : 'OTHER');
	$provider_currency_ids[$kind][] = (int)$currency['cID'];
}
foreach (HScript\Payment\Provider\ProviderCatalog::GROUPS as $kind => [$name])
	if (isset($provider_currency_ids[$kind])) $provider_filters[$kind] = $name;
if (isset($provider_currency_ids['INTERNAL'])) $provider_filters['INTERNAL'] = View::_t('currs.filter.internal');
if (isset($provider_currency_ids['OTHER'])) $provider_filters['OTHER'] = View::_t('currs.filter.other');
$selected_provider = is_string($_GET['provider'] ?? null) ? $_GET['provider'] : 'ALL';
if (!isset($provider_filters[$selected_provider])) $selected_provider = 'ALL';
$filter = $visible;
$filter_values = null;
if ($selected_provider !== 'ALL') {
	$filter .= ' AND cID ?i';
	$filter_values = [$provider_currency_ids[$selected_provider]];
}
$archiveColumn=(bool)$db->link->query("SHOW COLUMNS FROM Currs LIKE 'cArchivedAt'")->fetch();
$archiveState=is_string($_GET['state']??null)?$_GET['state']:'active';
if(!in_array($archiveState,['active','archived','all'],true))$archiveState='active';
if($archiveColumn&&$archiveState!=='all')$filter.=$archiveState==='archived'?" AND (cArchivedAt IS NOT NULL OR EXISTS(SELECT 1 FROM PaymentProviders WHERE ppID=cProviderID AND ppState='retired'))":" AND cArchivedAt IS NULL AND NOT EXISTS(SELECT 1 FROM PaymentProviders WHERE ppID=cProviderID AND ppState='retired')";
View::setPage('currency_filter_state',$archiveState,0);
View::setPage('provider_filters', $provider_filters, 0);
View::setPage('selected_provider', $selected_provider, 0);
View::setPage('provider_filter_params', '&provider=' . rawurlencode($selected_provider).'&state='.$archiveState, 0);
$provider_connections=[];
foreach($db->link->query('SELECT ppKind,ppState FROM PaymentProviders WHERE ppSlot IS NOT NULL ORDER BY ppKind')->fetchAll(PDO::FETCH_ASSOC) as $profile){
    $kind=$profile['ppKind'];
    if(!isset(HScript\Payment\Provider\ProviderCatalog::GROUPS[$kind]))continue;
    if($selected_provider!=='ALL' && $selected_provider!==$kind)continue;
    $provider_connections[$kind]=['name'=>HScript\Payment\Provider\ProviderCatalog::GROUPS[$kind][0],'enabled'=>$profile['ppState']==='enabled'];
}
foreach(HScript\Payment\Provider\LegacyProviderService::KINDS as$kind){
    if($selected_provider!=='ALL' && $selected_provider!==$kind)continue;
    if($rows=HScript\Payment\Provider\LegacyProviderService::rows($db,$kind))$provider_connections[$kind]=['name'=>HScript\Payment\Provider\ProviderCatalog::GROUPS[$kind][0],'enabled'=>count(array_filter($rows,static fn($r)=>(int)$r['cDisabled']===0))>0];
}
View::setPage('provider_connections',$provider_connections,0);

$list = opPageGet(_GETN('page') ?: (isset($_GET['provider']) ? 1 : 0), 20, $table, '*', $filter, $filter_values,
	array(
		$id_field => array($id_field)
	),
	_GET('sort'), $id_field
);
$currency_manager = new HScript\Payment\PaymentManager($db);
$list = HScript\Payment\Provider\CurrencyPresentation::annotate($db, $list);
foreach ($list as $id => $r) {
    $api_configured = false;
    if (empty($r['cProviderID']) && !empty($r['cParamsAPI']) && isset($currency_manager->fields($r['cCID'], 'api')['apipass'])) {
        opDecodeCurrParams($r, $pay, $sci, $api);
        $api_configured = !empty($api['apipass']);
        unset($pay, $sci, $api);
    }
    $list[$id]['PAPI']=['configured'=>$api_configured];
    $list[$id]['presentation']=HScript\Payment\Provider\CurrencyPresentation::describe($r);
    unset($list[$id]['cParams'],$list[$id]['cParamsSCI'],$list[$id]['cParamsAPI']);
}
View::setPage('list', $list);

View::showPage();

?>
