<?php
use HScript\Payment\Provider\{LegacyProviderService,LegacyAccountSettings,ProviderCatalog,ProviderException};
use HScript\Template\View;
if(($_GS['module']??'')!=='balance/admin/curr'||!isset($paymentManager)||!in_array($providerKind??null,LegacyProviderService::KINDS,true)){http_response_code(404);return;}
$url=moduleToLink('balance/admin/curr').'?provider='.$providerKind;
$ready=(int)$db->link->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND COLUMN_NAME='cProviderID'")->fetchColumn()===1
    &&(int)$db->link->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='PaymentGatewayCatalog'")->fetchColumn()===1;
if(!$ready){http_response_code(503);View::setPage('legacy_setup',['kind'=>$providerKind,'name'=>ProviderCatalog::GROUPS[$providerKind][0],'rows'=>[],'choices'=>[],'url'=>$url]);View::setPage('legacy_setup_error',\HScript\Template\View::_t('gateway.error.complete_the_standard_database_update_with_a_verified_backup'));View::showPage();return;}

try{
    (new ProviderCatalog($db))->requireAvailable($providerKind);
    if(View::sendedForm('','legacy_account_save')){
        View::checkFormSecurity('legacy_account_save');
        LegacyAccountSettings::save($db,(int)$_user['uLevel'],$providerKind,$_IN);
        $catalogCache->invalidate(HScript\Cache\CatalogCache::CURRENCIES);goToURL($url);
    }
    if(View::sendedForm('','legacy_add')){
        View::checkFormSecurity('legacy_add');
        $scope=[];$scopeCount=$db->link->prepare('SELECT COUNT(*) FROM Currs WHERE cProviderID IS NULL AND cCID=? AND cCurrID=?');
        foreach(LegacyProviderService::choices($providerKind)as$key=>$choice){$scopeCount->execute([$choice['gateway'],$choice['currency']]);if((int)$scopeCount->fetchColumn()<=1)$scope[]=$key;}
        HScript\Payment\Provider\CurrencySelection::scope($_IN,array_keys(LegacyProviderService::choices($providerKind)),$scope);
        LegacyProviderService::select($db,(int)$_user['uLevel'],$providerKind,$_IN);
        $catalogCache->invalidate(HScript\Cache\CatalogCache::CURRENCIES);goToURL($url);
    }
}catch(FormAbortException){}
catch(Throwable $error){
    View::setPage('legacy_setup_error',HScript\Payment\Provider\CurrencyArchive::message($error)??(($error instanceof ProviderException&&$error->reason==='input_invalid')?\HScript\Template\View::_t('gateway.error.select_currencies_from_the_list'):\HScript\Template\View::_t('gateway.error.settings_could_not_be_saved_changes_were_rolled_back')));
}
$choices=LegacyProviderService::choices($providerKind);$rows=LegacyProviderService::rows($db,$providerKind);$matched=[];
$exists=$db->link->prepare('SELECT cID FROM Currs WHERE cCID=? AND cCurrID=? ORDER BY cID');
foreach($choices as&$choice){
    $exists->execute([$choice['gateway'],$choice['currency']]);$ids=array_map('intval',$exists->fetchAll(PDO::FETCH_COLUMN));
    $choice['exists']=(bool)array_filter($rows,static fn($row)=>in_array((int)$row['cID'],$ids,true)&&($row['cArchivedAt']??null)===null);$choice['connections']=[];
    foreach($rows as$row)if(in_array((int)$row['cID'],$ids,true)){$choice['connections'][]=$row;$matched[(int)$row['cID']]=true;}
}unset($choice);
$extraRows=array_values(array_filter($rows,static fn($row)=>!isset($matched[(int)$row['cID']])));
View::setPage('legacy_selection_revision',LegacyProviderService::revision($rows));
$accounts=[];
if(in_array($providerKind,['WEBMONEY','YOOMONEY'],true))foreach($rows as$row){
    if(($row['cArchivedAt']??null)!==null||!LegacyAccountSettings::definition($row['cCID']))continue;
    $q=$db->link->prepare('SELECT * FROM Currs WHERE cID=?');$q->execute([$row['cID']]);
    try{$account=LegacyAccountSettings::view($q->fetch(PDO::FETCH_ASSOC));if($account)$accounts[]=$account;}
    catch(ProviderException){View::setPage('legacy_setup_error',\HScript\Template\View::_t('gateway.error.saved_account_settings_could_not_be_read_check_the'));}
}
View::setPage('legacy_accounts',$accounts,0);

$choices=HScript\Payment\Provider\CurrencySelection::selectedFirst($choices,array_keys(array_filter($choices,static fn($choice)=>$choice['exists'])));
View::setPage('legacy_setup',['kind'=>$providerKind,'name'=>ProviderCatalog::GROUPS[$providerKind][0],'url'=>$url,'rows'=>$rows,'choices'=>$choices]);
View::showPage();
