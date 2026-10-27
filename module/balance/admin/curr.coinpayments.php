<?php
use HScript\Cache\CatalogCache;
use HScript\Payment\CoinPaymentsNewClient;
use HScript\Payment\Provider\{CoinPaymentsNewSchema,CoinPaymentsNewService,ProviderException,ProviderRepository,ProviderService};
use HScript\Template\View;

if(($_GS['module']??'')!=='balance/admin/curr'||!isset($paymentManager)||($providerKind??null)!=='COINNEW'){http_response_code(404);return;}
View::setPage('coinnew_mode',true);
$tokenPage=_GET('tab')==='tokens';View::setPage('coinnew_token_page',$tokenPage);
$url=moduleToLink('balance/admin/curr').'?provider=COINNEW';View::setPage('coinnew_url',$url);
if(!CoinPaymentsNewSchema::ready($db->link)){
    http_response_code(503);View::setPage('coinnew_error',\HScript\Template\View::_t('gateway.error.update_the_database_to_1_0_15_through_the'));View::showPage();return;
}
$repository=new ProviderRepository($db);$profile=$el?$repository->find((int)$el['cProviderID']):$repository->current('COINNEW');
if($profile&&$profile['ppKind']!=='COINNEW'){http_response_code(409);View::setPage('coinnew_error',\HScript\Template\View::_t('gateway.error.the_connection_has_changed_refresh_the_page'));View::showPage();return;}
$credentials=[];$redirect=false;
try{
    if($profile)$credentials=$repository->credentials($profile,(int)$profile['ppCurrentKeyVersion']);
    if($el&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    if(View::sendedForm('','coinnew_catalog')){
        View::checkFormSecurity('coinnew_catalog');
        $instance=_IN('instance');if(!is_string($instance)||!isset(CoinPaymentsNewClient::ORIGINS[$instance]))throw new ProviderException('input_invalid');
        if(time()-(int)($_SESSION['coinnew_catalog_at'][$instance]??0)<10)throw new ProviderException('catalog_throttled');
        $_SESSION['coinnew_catalog_at'][$instance]=time();
        $_SESSION['coinnew_catalog'][$instance]=['at'=>time(),'items'=>CoinPaymentsNewClient::catalog($instance)];
        $_SESSION['coinnew_instance']=$instance;
    }
    if(View::sendedForm('','coinnew_save')){
        View::checkFormSecurity('coinnew_save');
        (new CoinPaymentsNewService($db,null,null,static function()use($catalogCache):void{$catalogCache->invalidate(CatalogCache::CURRENCIES);}))->save((int)$_user['uLevel'],$_IN);
        $redirect=true;
    }
    if(View::sendedForm('','coinnew_disable')){
        View::checkFormSecurity('coinnew_disable');
        (new ProviderService($db,null,static function()use($catalogCache):void{$catalogCache->invalidate(CatalogCache::CURRENCIES);}))->disable((int)$_user['uLevel'],'COINNEW',ProviderService::number(_IN('profile_id')),ProviderService::number(_IN('revision')),_IN('retire')==='1');
        $redirect=true;
    }
}catch(FormAbortException){View::setPage('coinnew_error',\HScript\Template\View::_t('gateway.error.form_verification_failed_refresh_the_page'));}
catch(Throwable $e){
    $reason=$e instanceof ProviderException?$e->reason:'provider_unavailable';
    $messages=['input_invalid'=>\HScript\Template\View::_t('gateway.error.check_the_platform_and_api_credentials'),'migration_required'=>\HScript\Template\View::_t('gateway.error.a_standard_database_update_to_1_0_15_is'),
        'catalog_unavailable'=>\HScript\Template\View::_t('gateway.error.the_coinpayments_catalog_is_unavailable_or_its_format_has'),
        'catalog_throttled'=>\HScript\Template\View::_t('gateway.error.the_catalog_was_just_refreshed_wait_a_few_seconds'),
        'asset_unavailable'=>\HScript\Template\View::_t('gateway.error.a_selected_asset_is_currently_unavailable_refresh_the_catalog'),
        'configuration_changed'=>\HScript\Template\View::_t('gateway.error.the_connection_has_changed_refresh_the_page_before_saving'),
        'connection_identity_changed'=>\HScript\Template\View::_t('gateway.error.create_a_new_connection_for_a_different_client_id'),
        'payments_pending'=>\HScript\Template\View::_t('gateway.error.reconcile_unfinished_operations_first'),
        'encryption_unavailable'=>\HScript\Template\View::_t('gateway.error.configure_app_data_key_before_saving_keys'),
        'asset_identity_invalid'=>\HScript\Template\View::_t('gateway.error.the_saved_currency_identifier_requires_verification_changes_were_not')];
    View::setPage('coinnew_error',HScript\Payment\Provider\CurrencyArchive::message($e)??$messages[$reason]??\HScript\Template\View::_t('gateway.error.coinpayments_new_access_could_not_be_verified_check_the'));
}finally{unset($_IN['credentials'],$_POST['credentials']);}
if($redirect)goToURL($url);
$profile=$el?$profile:$repository->current('COINNEW');
$instance=$credentials['instance']??($_SESSION['coinnew_instance']??'A');
if(!isset(CoinPaymentsNewClient::ORIGINS[$instance]))$instance='A';
$snapshot=$_SESSION['coinnew_catalog'][$instance]??[];
$catalog=time()-(int)($snapshot['at']??0)<600?($snapshot['items']??[]):[];
$connections=$profile?$repository->connections((int)$profile['ppID']):[];$selected=[];
foreach($connections as$c){
    try{$asset=CoinPaymentsNewService::asset($c);}catch(Throwable){continue;}
    $selected[$asset['id']]=['currency_id'=>(int)$c['cID'],'selected'=>(int)$c['cProviderSelected']===1];
    if(!isset($catalog[$asset['id']])){$asset['status']='saved';$asset['enabled']=false;$catalog[$asset['id']]=$asset;}
}
foreach($catalog as$id=>&$asset){$asset['selectable']=isset($selected[$id])||CoinPaymentsNewService::selectable($asset,$instance);}
unset($asset);
if($el){try{View::setPage('coinnew_currency',CoinPaymentsNewService::asset($el)+['currency_id'=>(int)$el['cID'],'name'=>$el['cName']]);}catch(Throwable){View::setPage('coinnew_error',\HScript\Template\View::_t('gateway.error.the_currency_identifier_could_not_be_read'));}}
if($tokenPage && !$profile)goToURL($url);
$groups=[];$hidden=[];
foreach($catalog as$id=>$asset){
    if((!empty($asset['contract']))!==$tokenPage){$hidden[$id]=$asset;continue;}
    $groups[$tokenPage?$asset['network']:''][$id]=$asset;
}
$selectedKeys=array_keys(array_filter($selected,static fn($asset)=>$asset['selected']));
foreach($groups as&$group)$group=HScript\Payment\Provider\CurrencySelection::selectedFirst($group,$selectedKeys);unset($group);
View::setPage('coinnew_groups',$groups);View::setPage('coinnew_hidden',$hidden);
View::setPage('coinnew_profile',$profile);View::setPage('coinnew_instance',$instance);View::setPage('coinnew_client',$credentials['client_id']??'');
View::setPage('coinnew_catalog',$catalog);View::setPage('coinnew_selected',$selected);View::setPage('coinnew_catalog_at',$snapshot['at']??null);
View::showPage();
