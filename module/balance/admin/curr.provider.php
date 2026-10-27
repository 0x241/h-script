<?php

use HScript\Cache\CatalogCache;
use HScript\Payment\Provider\{ProviderCatalog,ProviderException,ProviderRepository,ProviderService,ProviderSettings};
use HScript\Template\View;
use HScript\Update\{SchemaStateRepository,SchemaVersion};

if (($_GS['module']??'')!=='balance/admin/curr' || !isset($paymentManager,$providerKind)) { http_response_code(404);return; }
View::setPage('provider_mode',true);
$name=ProviderCatalog::GROUPS[$providerKind][0];
$bound=$el && !empty($el['cProviderID']) ? (int)$el['cProviderID'] : 0;
$url=moduleToLink('balance/admin/curr').($bound?'?id='.(int)$el['cID']:'?provider='.$providerKind);
$connectionUrl=moduleToLink('balance/admin/curr').'?provider='.$providerKind;
$tab=_GET('tab');if (!in_array($tab,['setup','settings','network','tokens'],true)) $tab=$bound?'settings':'setup';
if($tab==='tokens')$tab='setup';
$payNetwork=$providerKind==='PAYKASSA' && $tab==='network' ? _GET('network'):null;
if(!$bound && $tab==='settings')$tab='setup';
if($bound && $tab==='setup' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET')goToURL($connectionUrl);
$nativeNetwork=$bound?(HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$el['cCID']][0]??null):null;
View::setPage('provider_connection_url',$connectionUrl);
View::setPage('provider_name',$name);View::setPage('provider_kind',$providerKind);View::setPage('provider_url',$url);View::setPage('provider_tab',$tab);
$schema=(new SchemaStateRepository($db))->currentVersion();
if ($schema===null || SchemaVersion::compare($schema,'1.0.11')<0) {
    unset($_POST['credentials'],$_IN['credentials']);http_response_code(503);
    View::setPage('provider_error',\HScript\Template\View::_t('gateway.error.update_the_database_to_1_0_11_through_the'));View::showPage();return;
}
$repository=new ProviderRepository($db);
$service=new ProviderService($db,null,static function() use ($catalogCache): void { $catalogCache->invalidate(CatalogCache::CURRENCIES); });
$error=null;$profile=null;
$network=_GET('network');if(!is_string($network) || !in_array($network,HScript\Payment\CcapiV3Client::CHAINS,true))$network='ethereum';
if($tab==='network' && $nativeNetwork!==null)$network=$nativeNetwork;
$tokenService=new HScript\Payment\Routing\TokenCatalogService($db,null,static function()use($catalogCache):void{$catalogCache->invalidate(CatalogCache::CURRENCIES);});
try {
    $profile=$bound?$repository->find($bound):$repository->current($providerKind);
    if ($profile && $profile['ppKind']!==$providerKind) throw new ProviderException('configuration_changed');
    if($providerKind==='PAYKASSA' && $tab==='network'){if(!is_string($payNetwork)||!isset(HScript\Payment\Provider\PaykassaNetworks::LABELS[$payNetwork]))throw new ProviderException('input_invalid');if(!$profile)goToURL($connectionUrl);}
    if($providerKind==='CCAPI' && (($tab==='network' && (!$bound || $nativeNetwork===null)) || (!$bound && isset($_GET['network'])))) {
        if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && $profile && $providerKind==='CCAPI'){
            foreach($repository->connections((int)$profile['ppID']) as $candidate){$chain=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$candidate['cCID']][0]??null;if($chain===$network)goToURL(moduleToLink('balance/admin/curr').'?id='.(int)$candidate['cID'].'&tab=network');}
        }
        throw new ProviderException('network_not_selected');
    }
    foreach(['provider_token_refresh','provider_token_add','provider_token_select','provider_network_wallets'] as $tokenForm)if(View::sendedForm('',$tokenForm)){
        View::checkFormSecurity($tokenForm);
        if($tab!=='network' || $nativeNetwork===null || _IN('network')!==$nativeNetwork || $providerKind!=='CCAPI' || !$profile || ProviderService::number(_IN('profile_id'))!==(int)$profile['ppID'] || !is_string(_IN('network')))throw new ProviderException('configuration_changed');
        $network=_IN('network');$revision=ProviderService::number(_IN('revision'));
        if($tokenForm==='provider_token_refresh')$tokenService->refresh((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network);
        elseif($tokenForm==='provider_token_add')$tokenService->add((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network,ProviderService::number(_IN('catalog_revision')),$_POST['tokens']??[],($_POST['activate_selected']??null)==='1');
        elseif($tokenForm==='provider_token_select')$tokenService->select((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network,$_POST['token_currencies']??[],$_POST['form_complete']??null,$_POST['activation_scope']??null);
        else $tokenService->saveWallets((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network,$_POST['forward_to']??null,$_POST['payout_from']??null);
        View::showInfo('Saved',$url.'&tab=network');
    }
    if (View::sendedForm('','provider_routes_save')) {
        View::checkFormSecurity('provider_routes_save');
        if(!$bound || ProviderService::number(_IN('profile_id'))!==$bound)throw new ProviderException('configuration_changed');
        $service->saveRoutes((int)$_user['uLevel'],$providerKind,(int)$el['cID'],$_POST);View::showInfo('Saved',$url.'&tab=settings');
    }
    if (View::sendedForm('','provider_currency_save')) {
        View::checkFormSecurity('provider_currency_save');
        if (!$bound || ProviderService::number(_IN('profile_id'))!==$bound) throw new ProviderException('configuration_changed');
        $service->saveCurrency((int)$_user['uLevel'],$providerKind,(int)$el['cID'],$_POST);View::showInfo('Saved',$url.'&tab=settings');
    }
    if (View::sendedForm('','provider_save')) {
        View::checkFormSecurity('provider_save');
        if (time()-(int)($_SESSION['_provider_validation_at']??0)<30) throw new ProviderException('validation_cooldown');
        $_SESSION['_provider_validation_at']=time();
        $input=$_POST;
        $visible=ProviderSettings::choices($providerKind);
        if($providerKind==='PAYKASSA')$visible=HScript\Payment\Provider\PaykassaNetworks::visible($payNetwork);
        if($providerKind==='CCAPI')unset($visible['USDT'],$visible['USDC']);
        HScript\Payment\Provider\CurrencySelection::scope($input,array_keys(ProviderSettings::choices($providerKind)),array_keys($visible));
        if (($bound && ProviderService::number($input['profile_id']??null)!==$bound) || (!$bound && ProviderService::number($input['profile_id']??null)!==(int)($profile['ppID']??0))) throw new ProviderException('configuration_changed');
        unset($_POST['credentials'],$_IN['credentials']);
        $service->save((int)$_user['uLevel'],$providerKind,$input);unset($input);View::showInfo('Saved',$connectionUrl);
    }
    $retire=View::sendedForm('remove','provider_manage');$disable=View::sendedForm('disable','provider_manage');
    if ($retire || $disable) {
        View::checkFormSecurity('provider_manage');
        if (ProviderService::number(_IN('profile_id'))!==(int)($profile['ppID']??0)) throw new ProviderException('configuration_changed');
        $service->disable((int)$_user['uLevel'],$providerKind,ProviderService::number(_IN('profile_id')),ProviderService::number(_IN('revision')),$retire);
        View::showInfo('Saved',$out_link);
    }
} catch (FormAbortException) { $error=\HScript\Template\View::_t('gateway.error.the_form_could_not_be_verified_refresh_the_page'); }
catch (Throwable $e) {
    $error=HScript\Payment\Provider\CurrencyArchive::message($e)??match($e->getMessage()) {
        'binding_required'=>\HScript\Template\View::_t('gateway.error.select_an_existing_currency_for_this_provider_only_one'),
        'payments_pending'=>\HScript\Template\View::_t('gateway.error.unfinished_payments_exist_reconcile_them_before_changing_credentials_or'),
        'configuration_changed'=>\HScript\Template\View::_t('gateway.error.settings_have_changed_refresh_the_page_before_saving'),
        'paykassa_migration_required'=>\HScript\Template\View::_t('gateway.error.update_the_database_to_1_0_14_through_the'),
        'paykassa_sandbox_removed'=>\HScript\Template\View::_t('gateway.error.the_saved_connection_is_unavailable_reconnect_the_merchant_its'),
        'environment_changed'=>\HScript\Template\View::_t('gateway.error.connection_parameters_have_changed_create_a_new_connection_history'),
        'encryption_unavailable'=>\HScript\Template\View::_t('gateway.error.configure_app_data_key_before_saving_credentials'),
        'provider_unavailable'=>\HScript\Template\View::_t('gateway.error.connection_verification_failed_check_credentials_and_api_access'),
        'wallet_unavailable'=>\HScript\Template\View::_t('gateway.error.the_specified_wallet_was_not_found_in_the_volet'),
        'profile_disabled'=>\HScript\Template\View::_t('gateway.error.enable_the_profile_and_select_this_currency_on_the'),
        'validation_cooldown'=>\HScript\Template\View::_t('gateway.error.verification_can_be_repeated_after_one_minute'),
        'network_not_selected'=>\HScript\Template\View::_t('gateway.error.select_the_currency_for_this_network_and_save_the'),
        'token_catalog_ambiguous'=>\HScript\Template\View::_t('gateway.error.the_catalog_contains_ambiguous_token_symbols_the_connection_was'),
        'legacy_token_requires_reconciliation'=>\HScript\Template\View::_t('gateway.error.usdt_is_already_bound_to_a_shared_currency_or'),
        'token_exchange_unsupported'=>\HScript\Template\View::_t('gateway.error.exchange_and_deposit_plans_for_catalog_tokens_require_a'),
        'automatic_withdrawal_unsupported'=>\HScript\Template\View::_t('gateway.error.this_integration_supports_manual_withdrawals'),
        'ccapi_payout_source_required'=>\HScript\Template\View::_t('gateway.error.set_a_source_address_on_the_network_wallets_page'),
        'ccapi_payout_route_ambiguous'=>\HScript\Template\View::_t('gateway.error.use_a_separate_single_network_token_currency_for_automatic'),
        'merchant_restricted'=>\HScript\Template\View::_t('gateway.error.stripe_has_not_enabled_payments_for_this_account_yet'),
        'currency_decimal_invalid'=>\HScript\Template\View::_t('gateway.error.check_the_precision_of_amounts_and_fees'),
        default=>\HScript\Template\View::_t('gateway.error.settings_could_not_be_saved_check_the_form_and')
    };
} finally { unset($_POST['credentials'],$_IN['credentials'],$input); }
View::setPage('provider_error',$error);
$profile=$bound?$repository->find($bound):$repository->current($providerKind);
$connections=$profile?$repository->connections((int)$profile['ppID']):[];

$connectionById=array_column($connections,null,'cID');
$selected=[];foreach ($connections as $c) if ($c['cCID']!=='CCT' && (int)$c['cProviderSelected']) $selected[]=$providerKind==='PAYKASSA'?HScript\Payment\PaykassaAssets::choiceKey($c):$c['cCurrID'];
$fields=ProviderSettings::fields($providerKind);$display=[];$wallets=[];
if ($profile && (int)$profile['ppCurrentKeyVersion']>0) {
    try { $keys=$repository->credentials($profile,(int)$profile['ppCurrentKeyVersion']);
        foreach ($fields as $field=>[$label,$secret]) if (!$secret) $display[$field]=$keys[$field]??'';
    } catch (Throwable) { View::setPage('provider_error',\HScript\Template\View::_t('gateway.error.saved_credentials_are_unavailable_check_app_data_key')); }
    unset($keys);
}
$choices=ProviderSettings::choices($providerKind);$bindings=[];$boundAssets=[];
foreach ($connections as $c) {
    if($c['cCID']==='CCT')continue;
    $boundAssets[$providerKind==='PAYKASSA'?HScript\Payment\PaykassaAssets::choiceKey($c):$c['cCurrID']]=(int)$c['cID'];
    if ($providerKind==='VOLET') { opDecodeCurrParams($c,$p,$sci,$api);$wallets[$c['cCurrID']]=$api['acc']??''; }
}
foreach ($choices as $asset=>$choice) {
    $rows=$db->fetchRows($db->select('Currs','cID,cName,cProviderID','cCID=? AND cCurrID=?',[$choice['gateway'],$choice['currency']]));
    foreach ($rows as $row) if ($row['cProviderID']===null || (int)$row['cProviderID']===(int)($profile['ppID']??0)) $bindings[$asset][]=['id'=>(int)$row['cID'],'name'=>$row['cName']];
}
$connectionView=[];$networkCurrencies=[];
foreach($connections as $c){
    $row=array_intersect_key($c,array_flip(['cID','cCID','cCurrID','cName','cProviderSelected','cDisabled','cCASHINMode']));
    $row['presentation']=HScript\Payment\Provider\CurrencyPresentation::describe($c);
    $native=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$c['cCID']]??null;
    if($native && $providerKind==='CCAPI'){
        $networkCurrencies[$native[0]]=$row;
        $row['network_url']=moduleToLink('balance/admin/curr').'?id='.(int)$c['cID'].'&tab=network';
    }

    $connectionView[(int)$c['cID']]=$row;
}
View::setPage('provider_profile',$profile);View::setPage('provider_connections',$connectionView);
$groups=[];$hiddenChoices=[];$choiceNetworks=[];
$visible=$providerKind==='PAYKASSA'?HScript\Payment\Provider\PaykassaNetworks::visible($payNetwork):$choices;
foreach($choices as$asset=>$choice){if(!isset($visible[$asset])){$hiddenChoices[$asset]=$choice;continue;}$groups[''][$asset]=$choice;}

foreach($groups as&$group)$group=HScript\Payment\Provider\CurrencySelection::selectedFirst($group,$selected);unset($group);
View::setPage('provider_choice_groups',$groups);View::setPage('provider_hidden_choices',$hiddenChoices);
if($providerKind==='PAYKASSA'&&$profile&&$payNetwork===null)foreach($visible as$asset=>$choice){$chain=HScript\Payment\Provider\PaykassaNetworks::navigation($choice['gateway']);if($chain!==null)$choiceNetworks[$asset]=['key'=>$chain,'label'=>HScript\Payment\Provider\PaykassaNetworks::LABELS[$chain]];}
View::setPage('provider_choice_networks',$choiceNetworks);View::setPage('provider_is_token_page',$providerKind==='PAYKASSA' && $tab==='network');
View::setPage('provider_pay_network',$payNetwork);
if($payNetwork!==null)View::setPage('provider_network_label',($payNetwork==='shared'?'Ethereum / TRON · '.View::_t('gateway.admin.shared_account_2'):HScript\Payment\Provider\PaykassaNetworks::LABELS[$payNetwork]??''));
View::setPage('provider_form_url',$connectionUrl.($payNetwork!==null?'&tab=network&network='.rawurlencode($payNetwork):''),0);
View::setPage('provider_fields',$fields);View::setPage('provider_values',$display);View::setPage('provider_wallets',$wallets);View::setPage('provider_choices',$choices);View::setPage('provider_selected',$selected);View::setPage('provider_bindings',$bindings);View::setPage('provider_bound_assets',$boundAssets);
if ($bound) {
    $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[(int)$el['cID']]));
    View::setPage('provider_currency_presentation',HScript\Payment\Provider\CurrencyPresentation::describe($currency));
    $tokenChain=HScript\Payment\Provider\CurrencyPresentation::describe($currency);
    if($providerKind==='PAYKASSA'){
        $chain=HScript\Payment\Provider\PaykassaNetworks::navigation($currency['cCID'])??HScript\Payment\Provider\PaykassaNetworks::token($currency['cCID']);
        if($chain!==null)View::setPage('provider_currency_network_url',$connectionUrl.'&tab=network&network='.$chain,0);
    }
    elseif($nativeNetwork!==null)View::setPage('provider_currency_network_url',$url.'&tab=network',0);
    elseif($tokenChain['network']!==null){foreach($connections as $parent){$chain=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$parent['cCID']][0]??null;$routes=HScript\Payment\Routing\PaymentRoutes::catalog($currency['cCID'],$currency);if($chain!==null && (reset($routes)['network']??null)===$chain)View::setPage('provider_currency_network_url',moduleToLink('balance/admin/curr').'?id='.(int)$parent['cID'].'&tab=network',0);}}
    View::setPage('payment_route_choices',HScript\Payment\Routing\PaymentRoutes::catalog($currency['cCID'],$currency));
    View::setPage('payment_route_enabled',array_keys(HScript\Payment\Routing\PaymentRoutes::enabled($currency)));
    unset($currency['cParams'],$currency['cParamsSCI'],$currency['cParamsAPI']);View::stampArrayToStr($currency,'cMTS');
    $currency['profile_id']=(int)$profile['ppID'];$currency['revision']=(int)$profile['ppRevision'];View::setPage('provider_currency_values',$currency);
}
if ($providerKind==='STRIPE') View::setPage('provider_webhook',fullURL(moduleToLink('balance/status')).'?its=ST');
if($providerKind==='CCAPI' && $profile && $profile['ppState']!=='retired' && $tab==='network' && $nativeNetwork!==null){
    $network=$nativeNetwork;
    View::setPage('provider_network_selected',(int)($el['cProviderSelected']??0)===1);
    {
        $label=HScript\Payment\Routing\TokenCatalog::NETWORKS[$network][0]??$el['cCurrID'];
        $catalog=$tokenService->catalog((int)$profile['ppID'],$network);
        $tokens=[];
        foreach($connections as $c)if(in_array($c['cCID'],['CCT','CCTU','CCTC'],true)){
            try{$enabled=HScript\Payment\Routing\PaymentRoutes::enabled($c);$routes=HScript\Payment\Routing\PaymentRoutes::catalog($c['cCID'],$c);foreach($routes as $code=>$route)if(count($routes)===1 && $route['network']===$network){$tokens[]=$connectionView[(int)$c['cID']]+['contract'=>$route['contract'],'route_enabled'=>isset($enabled[$code])];break;}}catch(Throwable){}
        }
        View::setPage('provider_network',$network);View::setPage('provider_network_label',$label);
        $tokens=HScript\Payment\Provider\CurrencySelection::selectedFirst(array_column($tokens,null,'cID'),array_map(static fn($t)=>(int)$t['cID'],array_filter($tokens,static fn($t)=>(int)$t['cProviderSelected']===1)));
        View::setPage('provider_network_url',$url.'&tab=network',0);View::setPage('provider_network_connections',$tokens);
        View::setPage('provider_network_wallets',HScript\Payment\Routing\CcapiWallets::read($db,(int)$profile['ppID'],$network));
        View::setPage('provider_tokens_supported',isset(HScript\Payment\Routing\TokenCatalog::NETWORKS[$network]));
        View::setPage('provider_tokens',HScript\Payment\Routing\TokenCatalogService::routes($catalog,$profile));
        View::setPage('provider_token_catalog',$catalog);
    }
}
View::setPage('provider_referral_url',HScript\Payment\Provider\ProviderReferral::link($providerKind),0);
View::showPage();
