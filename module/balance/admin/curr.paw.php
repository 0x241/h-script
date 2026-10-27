<?php

use HScript\Cache\CatalogCache;
use HScript\Payment\Provider\PawProviderException;
use HScript\Payment\Provider\PawProviderRepository;
use HScript\Payment\Provider\PawProviderService;
use HScript\Template\View;
use HScript\Update\SchemaStateRepository;
use HScript\Update\SchemaVersion;

// Internal part of the ordinary payment-system controller, never a route.
if (($_GS['module'] ?? '') !== 'balance/admin/curr' || !isset($paymentManager)) {
    http_response_code(404);
    return;
}
View::setPage('paw_mode',true);
View::setPage('paw_referral_url',HScript\Payment\Provider\ProviderReferral::link('PAW'));
$boundProfileId=($el && $el['cCID']==='PAW') ? (int)($el['cProviderID']??0) : 0;
$pawUrl=moduleToLink('balance/admin/curr').($boundProfileId>0 ? '?id='.(int)$el['cID'] : '?provider=PAW');
if (!$boundProfileId && isset($_GET['profile_id']) && is_string($_GET['profile_id']) && ctype_digit($_GET['profile_id']))
    $pawUrl.='&profile_id='.rawurlencode($_GET['profile_id']);
View::setPage('paw_url',$pawUrl);
View::setPage('paw_connection_url',moduleToLink('balance/admin/curr').'?provider=PAW'.($boundProfileId>0?'&profile_id='.$boundProfileId:''));
View::setPage('paw_currency',$boundProfileId>0 ? $el['cCurrID'] : '');

$repository=new PawProviderRepository($db);
$tab=$boundProfileId ? 'settings' : 'setup';
$schema=(new SchemaStateRepository($db))->currentVersion();
$schemaReady=$schema!==null && SchemaVersion::compare($schema,'1.0.5')>=0;
View::setPage('paw_schema_ready',$schemaReady);
View::setPage('paw_payout_ready',HScript\Payment\Provider\PawPayoutSchema::ready($db->link,true),0);
View::setPage('paw_native_ready',$schema!==null && SchemaVersion::compare($schema,'1.0.12')>=0);
if (!$schemaReady) {
    // A source update can precede the explicit, backup-required migration.
    // Do not query provider tables, issue a form token or contact PAW yet.
    unset($_POST['api_key'],$_IN['api_key']);
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    View::setPage('paw_profile',$repository->view(null));
    View::setPage('paw_tab',$tab);
    View::setPage('paw_error',\HScript\Template\View::_t('gateway.error.update_the_database_to_1_0_5_using_the'));
    View::showPage();
    return;
}
$service=new PawProviderService($repository,null,static function() use ($catalogCache): void { $catalogCache->invalidate(CatalogCache::CURRENCIES); });
$error=null;
try {
    if (View::sendedForm('','paw_currency_save')) {
        View::checkFormSecurity('paw_currency_save');
        if (!$boundProfileId || PawProviderService::number(_IN('profile_id'))!==$boundProfileId)
            throw new PawProviderException('configuration_changed');
        $service->saveCurrency((int)$_user['uLevel'],(int)$el['cID'],$_POST);
        View::showInfo('Saved',$pawUrl.'&tab=settings');
    }
    if (View::sendedForm('','paw_profile_save')) {
        View::checkFormSecurity('paw_profile_save');
        if (time()-(int)($_SESSION['_paw_validation_at']??0)<30) throw new PawProviderException('validation_cooldown');
        $_SESSION['_paw_validation_at']=time();
        // Preserve exact key bytes for validation; never repopulate it into a view.
        $input=['profile_id'=>_IN('profile_id'),'revision'=>_IN('revision'),
            'api_key'=>$_POST['api_key']??null,'enabled'=>_IN('enabled'),'fiats'=>$_POST['fiats']??[],'form_complete'=>_IN('form_complete'),'activation_scope'=>$_POST['activation_scope']??[]];
        if ($boundProfileId>0 && PawProviderService::number($input['profile_id'])!==$boundProfileId)
            throw new PawProviderException('configuration_changed');
        unset($_POST['api_key'],$_IN['api_key']);
        $saved=$service->save((int)$_user['uLevel'],$input);
        unset($input);
        $savedUrl=$boundProfileId>0 ? $pawUrl : moduleToLink('balance/admin/curr').'?provider=PAW&profile_id='.$saved['id'];
        View::showInfo('Saved',$savedUrl.'&tab='.$tab);
    }
    $retire=View::sendedForm('remove','paw_profile_manage');
    $disable=View::sendedForm('disable','paw_profile_manage');
    if ($retire || $disable) {
        View::checkFormSecurity('paw_profile_manage');
        $managedId=PawProviderService::number(_IN('profile_id'));
        if ($boundProfileId>0 && $managedId!==$boundProfileId) throw new PawProviderException('configuration_changed');
        $service->disable((int)$_user['uLevel'],$managedId,PawProviderService::number(_IN('revision')),$retire);
        View::showInfo('Saved',$retire ? $out_link : $pawUrl.'&tab='.$tab);
    }
} catch (FormAbortException $e) {
    $error=\HScript\Template\View::_t('gateway.error.the_form_could_not_be_verified_refresh_the_page');
} catch (PawProviderException $e) {
    $errors=[
        'input_invalid'=>\HScript\Template\View::_t('gateway.error.check_the_form_fields'),
        'pending_payouts'=>\HScript\Template\View::_t('gateway.error.complete_or_reconcile_unfinished_paw_payouts_first_the_key'),
        'payout_sandbox_unsupported'=>\HScript\Template\View::_t('gateway.error.paw_does_not_support_sandbox_payouts_withdrawals_remain_disabled'),
        'pending_invoices'=>\HScript\Template\View::_t('gateway.error.complete_or_reconcile_unfinished_paw_invoices_first_the_key'),
        'currency_decimal_invalid'=>\HScript\Template\View::_t('gateway.error.check_amounts_and_fees_for_invalid_precision_or_values'),
        'invalid_api_key'=>\HScript\Template\View::_t('gateway.error.paw_did_not_verify_the_api_key_check_it'),
        'credential_unavailable'=>\HScript\Template\View::_t('gateway.error.the_saved_key_is_unavailable_check_the_application_encryption'),
        'encryption_unavailable'=>\HScript\Template\View::_t('gateway.error.encryption_is_unavailable_configure_app_data_key_before_connecting'),
        'fiat_unsupported'=>\HScript\Template\View::_t('gateway.error.the_selected_currency_is_unavailable_in_paw'),
        'merchant_restricted'=>\HScript\Template\View::_t('gateway.error.paw_has_restricted_this_account_check_the_account_in'),
        'provider_unavailable'=>\HScript\Template\View::_t('gateway.error.paw_is_unavailable_or_returned_unverified_data_settings_were'),
        'configuration_changed'=>\HScript\Template\View::_t('gateway.error.settings_have_already_changed_refresh_the_page_before_saving'),
        'configuration_busy'=>\HScript\Template\View::_t('gateway.error.configuration_is_busy_refresh_the_page_and_try_again'),
        'environment_changed'=>\HScript\Template\View::_t('gateway.error.to_switch_between_sandbox_and_live_remove_the_connection'),
        'validation_cooldown'=>\HScript\Template\View::_t('gateway.error.paw_verification_can_be_repeated_after_30_seconds'),
        'forbidden'=>\HScript\Template\View::_t('gateway.error.access_denied')
    ];
    $error=$errors[$e->reason]??\HScript\Template\View::_t('gateway.error.paw_settings_could_not_be_saved');
} catch (Throwable $e) {
    // Do not surface transport/SQL exceptions, headers or credential data.
    $error=HScript\Payment\Provider\CurrencyArchive::message($e)??\HScript\Template\View::_t('gateway.error.paw_settings_could_not_be_saved_refresh_the_page');
} finally {
    unset($_POST['api_key'],$_IN['api_key'],$input);
}

$profile=$boundProfileId>0 ? $repository->find($boundProfileId) : $repository->current();
if (!$boundProfileId && isset($_GET['profile_id'])) {
    try {
        $id=PawProviderService::number($_GET['profile_id']);
        $profile=$id>0 ? $repository->find($id) : $profile;
        if (!$profile) $error=\HScript\Template\View::_t('gateway.error.paw_profile_not_found');
    } catch (PawProviderException $e) { $error=\HScript\Template\View::_t('gateway.error.paw_profile_not_found'); }
}
$view=$repository->view($profile);
$selected=[];
foreach ($view['connections'] as $c) if ((int)$c['cProviderSelected']===1) $selected[]=$c['cCurrID'];
View::setPage('paw_profile',$view);
View::setPage('paw_selected',$selected);
View::setPage('paw_fiats',HScript\Payment\Provider\CurrencySelection::selectedFirst(array_combine(PawProviderService::FIATS,PawProviderService::FIATS),$selected));
View::setPage('paw_error',$error);
View::setPage('paw_tab',$tab);
if ($boundProfileId) {
    $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[(int)$el['cID']]));
    // Legacy credential columns are never sent to the shared currency form.
    unset($currency['cParams'],$currency['cParamsSCI'],$currency['cParamsAPI']);
    View::stampArrayToStr($currency,'cMTS');
    $currency['profile_id']=$view['id'];
    $currency['revision']=$view['revision'];
    View::setPage('paw_currency_values',$currency);
} else {
    View::setPage('paw_currency_values',['cID'=>0,'cDisabled'=>1,'cHidden'=>0,'cName'=>'PAW Payments USD',
        'cCurrID'=>'USD','cCurr'=>'USD','cNumDec'=>2,'cMTS'=>'','profile_id'=>$view['id'],'revision'=>$view['revision']]);
}
View::setPage('paw_currency_preview',$boundProfileId===0);
View::showPage();
