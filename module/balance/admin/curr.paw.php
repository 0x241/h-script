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
View::setPage('paw_currency',$boundProfileId>0 ? $el['cCurrID'] : '');

$repository=new PawProviderRepository($db);
$tab=_GET('tab');
if (!in_array($tab,['setup','currencies','settings','info'],true)) $tab=$boundProfileId ? 'settings' : 'setup';
$schema=(new SchemaStateRepository($db))->currentVersion();
$schemaReady=$schema!==null && SchemaVersion::compare($schema,'1.0.5')>=0;
View::setPage('paw_schema_ready',$schemaReady);
if (!$schemaReady) {
    // A source update can precede the explicit, backup-required migration.
    // Do not query provider tables, issue a form token or contact PAW yet.
    unset($_POST['api_key'],$_IN['api_key']);
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    View::setPage('paw_profile',$repository->view(null));
    View::setPage('paw_tab',$tab);
    View::setPage('paw_error','Для настройки PAW требуется обновление базы данных до версии 1.0.5 через штатное обновление с резервной копией.');
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
            'api_key'=>$_POST['api_key']??null,'enabled'=>_IN('enabled'),'fiats'=>_IN('fiats')];
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
    $error='Не удалось проверить форму. Обновите страницу и повторите действие.';
} catch (PawProviderException $e) {
    $errors=[
        'input_invalid'=>'Проверьте поля формы.',
        'currency_decimal_invalid'=>'Проверьте суммы и комиссии: недопустимая точность или слишком большое значение.',
        'invalid_api_key'=>'PAW не подтвердил API-ключ. Проверьте ключ в кабинете PAW.',
        'credential_unavailable'=>'Сохранённый ключ недоступен. Проверьте ключ шифрования приложения.',
        'encryption_unavailable'=>'Шифрование недоступно. Настройте APP_DATA_KEY перед подключением PAW.',
        'fiat_unsupported'=>'Выбранная валюта недоступна в PAW.',
        'merchant_restricted'=>'PAW ограничил доступ к аккаунту. Проверьте аккаунт в кабинете PAW.',
        'provider_unavailable'=>'PAW сейчас недоступен или вернул неподтверждённые данные. Настройки не изменены.',
        'configuration_changed'=>'Настройки уже изменились. Обновите страницу перед сохранением.',
        'configuration_busy'=>'Настройка занята. Обновите страницу и повторите действие.',
        'environment_changed'=>'Для перехода между sandbox и live удалите подключение и создайте новый профиль. История сохранится.',
        'validation_cooldown'=>'Повторная проверка PAW доступна через 30 секунд.',
        'forbidden'=>'Доступ запрещён.'
    ];
    $error=$errors[$e->reason]??'Не удалось сохранить настройки PAW.';
} catch (Throwable $e) {
    // Do not surface transport/SQL exceptions, headers or credential data.
    $error='Не удалось сохранить настройки PAW. Обновите страницу и проверьте состояние профиля.';
} finally {
    unset($_POST['api_key'],$_IN['api_key'],$input);
}

$profile=$boundProfileId>0 ? $repository->find($boundProfileId) : $repository->current();
if (!$boundProfileId && isset($_GET['profile_id'])) {
    try {
        $id=PawProviderService::number($_GET['profile_id']);
        $profile=$id>0 ? $repository->find($id) : $profile;
        if (!$profile) $error='Профиль PAW не найден.';
    } catch (PawProviderException $e) { $error='Профиль PAW не найден.'; }
}
$view=$repository->view($profile);
$selected=[];
foreach ($view['connections'] as $c) if ((int)$c['cProviderSelected']===1) $selected[]=$c['cCurrID'];
View::setPage('paw_profile',$view);
View::setPage('paw_selected',$selected);
View::setPage('paw_fiats',PawProviderService::FIATS);
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
