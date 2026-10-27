<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$module = 'Bal';
$setup_saver=static function(array $current,array $input)use($db):void{
    try{HScript\Finance\CurrencyRateCatalog::save($db,$current,$input);}
    catch(\InvalidArgumentException|\OverflowException|\Brick\Math\Exception\MathException){View::setError('Некорректный курс или параметр. Используйте неотрицательное десятичное число; неизвестные валюты не сохраняются.');}
};
View::setPage('rate_uses_cbr',in_array(HScript\Finance\RateUpdater::baseCurrency($_currs),['USD','EUR','RUB'],true),0);
View::setPage('rate_currencies',HScript\Finance\CurrencyRateCatalog::currencies(),0);
require_once('module/admin/setup.php');

View::setPage('lastupdate', View::timeToStr(stampToTime($_cfg['Bal_LastUpdate']), 2));

View::showPage();

?>
