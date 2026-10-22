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
$tab=_GET('tab');if (!in_array($tab,['setup','settings','network'],true)) $tab=$bound?'settings':'setup';
if(!$bound && $tab==='settings')$tab='setup';
if($bound && $tab==='setup' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET')goToURL($connectionUrl);
$nativeNetwork=$bound?(HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$el['cCID']][0]??null):null;
View::setPage('provider_connection_url',$connectionUrl);
View::setPage('provider_name',$name);View::setPage('provider_kind',$providerKind);View::setPage('provider_url',$url);View::setPage('provider_tab',$tab);
$schema=(new SchemaStateRepository($db))->currentVersion();
if ($schema===null || SchemaVersion::compare($schema,'1.0.11')<0) {
    unset($_POST['credentials'],$_IN['credentials']);http_response_code(503);
    View::setPage('provider_error','Для общего подключения требуется обновить базу до 1.0.11 через конфигуратор с резервной копией.');View::showPage();return;
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
    if(($tab==='network' && (!$bound || $providerKind!=='CCAPI' || $nativeNetwork===null)) || (!$bound && isset($_GET['network']))) {
        if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && $profile && $providerKind==='CCAPI'){
            foreach($repository->connections((int)$profile['ppID']) as $candidate){$chain=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$candidate['cCID']][0]??null;if($chain===$network)goToURL(moduleToLink('balance/admin/curr').'?id='.(int)$candidate['cID'].'&tab=network');}
        }
        throw new ProviderException('network_not_selected');
    }
    foreach(['provider_token_refresh','provider_token_add','provider_network_wallets'] as $tokenForm)if(View::sendedForm('',$tokenForm)){
        View::checkFormSecurity($tokenForm);
        if($tab!=='network' || $nativeNetwork===null || _IN('network')!==$nativeNetwork || $providerKind!=='CCAPI' || !$profile || ProviderService::number(_IN('profile_id'))!==(int)$profile['ppID'] || !is_string(_IN('network')))throw new ProviderException('configuration_changed');
        $network=_IN('network');$revision=ProviderService::number(_IN('revision'));
        if($tokenForm==='provider_token_refresh')$tokenService->refresh((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network);
        elseif($tokenForm==='provider_token_add')$tokenService->add((int)$_user['uLevel'],(int)$profile['ppID'],$revision,$network,ProviderService::number(_IN('catalog_revision')),$_POST['tokens']??[]);
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
} catch (FormAbortException) { $error='Не удалось проверить форму. Обновите страницу и повторите действие.'; }
catch (Throwable $e) {
    $error=match($e->getMessage()) {
        'binding_required'=>'Выберите существующую валюту этого провайдера. Для одного кода шлюза допускается одна активная валюта.',
        'payments_pending'=>'Есть незавершённые платежи. Завершите их сверку перед заменой ключей или привязки валюты.',
        'configuration_changed'=>'Настройки изменились. Обновите страницу перед сохранением.',
        'environment_changed'=>'Для смены тестового и рабочего режима создайте новое подключение. История сохранится.',
        'encryption_unavailable'=>'Настройте APP_DATA_KEY перед сохранением ключей.',
        'provider_unavailable'=>'Проверка подключения не пройдена. Проверьте ключи и доступ к API.',
        'wallet_unavailable'=>'Указанный кошелёк не найден в аккаунте Volet.',
        'profile_disabled'=>'Сначала включите профиль и выберите эту валюту на вкладке подключения.',
        'validation_cooldown'=>'Повторная проверка доступна через минуту.',
        'network_not_selected'=>'Сначала выберите валюту этой сети и сохраните подключение.',
        'token_catalog_ambiguous'=>'Каталог содержит неоднозначные обозначения токенов. Подключение не изменено.',
        'legacy_token_requires_reconciliation'=>'USDT уже связан с прежней общей валютой или историей платежей. Сначала выполните отдельную сверку: автоматически переносить остатки и создавать повторный платёжный контур нельзя.',
        'token_exchange_unsupported'=>'Для токена из каталога обмен и депозитные планы требуют отдельной модели оценки актива и пока недоступны.',
        'automatic_withdrawal_unsupported'=>'Для этой интеграции доступен ручной вывод.',
        'merchant_restricted'=>'Stripe ещё не разрешил приём платежей для этого аккаунта.',
        'currency_decimal_invalid'=>'Проверьте точность сумм и комиссий.',
        default=>'Не удалось сохранить настройки. Проверьте поля формы и состояние подключения.'
    };
} finally { unset($_POST['credentials'],$_IN['credentials'],$input); }
View::setPage('provider_error',$error);
$profile=$bound?$repository->find($bound):$repository->current($providerKind);
$connections=$profile?$repository->connections((int)$profile['ppID']):[];
$connectionById=array_column($connections,null,'cID');
$selected=[];foreach ($connections as $c) if ($c['cCID']!=='CCT' && (int)$c['cProviderSelected']) $selected[]=$c['cCurrID'];
$fields=ProviderSettings::fields($providerKind);$display=[];$wallets=[];
if ($profile && (int)$profile['ppCurrentKeyVersion']>0) {
    try { $keys=$repository->credentials($profile,(int)$profile['ppCurrentKeyVersion']);
        foreach ($fields as $field=>[$label,$secret]) if (!$secret) $display[$field]=$keys[$field]??'';
    } catch (Throwable) { View::setPage('provider_error','Сохранённые ключи недоступны. Проверьте APP_DATA_KEY.'); }
    unset($keys);
}
$choices=ProviderSettings::choices($providerKind);$bindings=[];$boundAssets=[];
foreach ($connections as $c) {
    if($c['cCID']==='CCT')continue;
    $boundAssets[$c['cCurrID']]=(int)$c['cID'];
    if ($providerKind==='VOLET') { opDecodeCurrParams($c,$p,$sci,$api);$wallets[$c['cCurrID']]=$api['acc']??''; }
}
foreach ($choices as $asset=>$choice) {
    $rows=$db->fetchRows($db->select('Currs','cID,cName,cProviderID','cCID=? AND cCurrID=?',[$choice['gateway'],$asset]));
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
View::setPage('provider_fields',$fields);View::setPage('provider_values',$display);View::setPage('provider_wallets',$wallets);View::setPage('provider_choices',$choices);View::setPage('provider_selected',$selected);View::setPage('provider_bindings',$bindings);View::setPage('provider_bound_assets',$boundAssets);
if ($bound) {
    $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[(int)$el['cID']]));
    View::setPage('provider_currency_presentation',HScript\Payment\Provider\CurrencyPresentation::describe($currency));
    $tokenChain=HScript\Payment\Provider\CurrencyPresentation::describe($currency);
    if($nativeNetwork!==null)View::setPage('provider_currency_network_url',$url.'&tab=network',0);
    elseif($tokenChain['network']!==null){foreach($connections as $parent){$chain=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$parent['cCID']][0]??null;$routes=HScript\Payment\Routing\PaymentRoutes::catalog($currency['cCID'],$currency);if($chain!==null && (reset($routes)['network']??null)===$chain)View::setPage('provider_currency_network_url',moduleToLink('balance/admin/curr').'?id='.(int)$parent['cID'].'&tab=network',0);}}
    View::setPage('payment_route_choices',HScript\Payment\Routing\PaymentRoutes::catalog($currency['cCID'],$currency));
    View::setPage('payment_route_enabled',array_keys(HScript\Payment\Routing\PaymentRoutes::enabled($currency)));
    unset($currency['cParams'],$currency['cParamsSCI'],$currency['cParamsAPI']);View::stampArrayToStr($currency,'cMTS');
    $currency['profile_id']=(int)$profile['ppID'];$currency['revision']=(int)$profile['ppRevision'];View::setPage('provider_currency_values',$currency);
}
if ($providerKind==='STRIPE') View::setPage('provider_webhook',fullURL(moduleToLink('balance/status')).'?its=ST');
if($providerKind==='CCAPI' && $profile && $profile['ppState']!=='retired' && $tab==='network' && $nativeNetwork!==null){
    $network=$nativeNetwork;
    if((int)($el['cProviderSelected']??0)!==1)View::setPage('provider_error','Сеть не подключена. Выберите её на странице подключения шлюза.');
    else {
        $label=HScript\Payment\Routing\TokenCatalog::NETWORKS[$network][0]??$el['cCurrID'];
        $catalog=$tokenService->catalog((int)$profile['ppID'],$network);
        $tokens=[];
        foreach($connections as $c)if(in_array($c['cCID'],['CCT','CCTU','CCTC'],true)){
            try{$enabled=HScript\Payment\Routing\PaymentRoutes::enabled($c);foreach(HScript\Payment\Routing\PaymentRoutes::catalog($c['cCID'],$c) as $code=>$route)if($route['network']===$network){$tokens[]=$connectionView[(int)$c['cID']]+['contract'=>$route['contract'],'route_enabled'=>isset($enabled[$code])];break;}}catch(Throwable){}
        }
        View::setPage('provider_network',$network);View::setPage('provider_network_label',$label);
        View::setPage('provider_network_url',$url.'&tab=network',0);View::setPage('provider_network_connections',$tokens);
        View::setPage('provider_network_wallets',HScript\Payment\Routing\CcapiWallets::read($db,(int)$profile['ppID'],$network));
        View::setPage('provider_tokens_supported',isset(HScript\Payment\Routing\TokenCatalog::NETWORKS[$network]));
        View::setPage('provider_tokens',HScript\Payment\Routing\TokenCatalogService::routes($catalog,$profile));
        View::setPage('provider_token_catalog',$catalog);
    }
}
View::setPage('provider_referral_url',HScript\Payment\Provider\ProviderReferral::ccapi());
View::showPage();
