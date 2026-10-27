<?php

use HScript\Template\View;
use HScript\Content\FaqCategories;
use HScript\Cache\CatalogCache;

$_auth=90;
require_once('module/auth.php');
try {
    if(View::sendedForm()){
        View::checkFormSecurity();
        if($_GS['demo'] && $_user['uLevel']<99)View::showInfo('*Denied');
        FaqCategories::save($db,$_IN,$_cfg['UI__Langs'][0]??'en');
        $catalogCache->invalidate(CatalogCache::CONFIG);
        $catalogCache->invalidate(CatalogCache::FAQ);
        View::showInfo('Saved');
    }
} catch(InvalidArgumentException $e){
    try{View::setError('categories_invalid');}catch(FormAbortException $e){}
} catch(FormAbortException $e){}
View::setPage('cfg',$db->fetchIDRows($db->select('Cfg','*','Module=?',['FAQ']),'Val','Prop'));
View::showPage();
