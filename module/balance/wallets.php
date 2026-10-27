<?php

use HScript\Template\View;
use HScript\Payment\PaymentManager;
use HScript\Database\TransactionScope;
use HScript\Finance\PawPayoutTarget;

$_auth = 1;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);
$walletHasDestination=static function(array $params,string $gateway):bool {
    if($gateway==='PAW') {
        foreach(PawPayoutTarget::ASSETS as $asset=>$metadata)if(PawPayoutTarget::valid($asset,$params[$asset]??null))return true;
        return false;
    }
    return $gateway==='BW' ? HScript\Payment\Gateways\BankWire::hasDestination($params) : !empty($params['acc']);
};

try {
    if (View::sendedForm()) {
        View::checkFormSecurity();
        if (($_cfg['Sec_MinPIN'] > 0) && !verifyPasswordWithLegacyDigest(_IN('PIN'),$_user['uPIN'],$_cfg['Const_Salt'],false))
            View::setError('pin_wrong');
        $pending=[];
        foreach ($_currs as $cid=>$currency) {
            $prefix='currency_'.$cid;
            // Omitted groups from an older/open form never erase other wallets.
            if (!array_key_exists($prefix,$_IN)) continue;
            $fields=$paymentManager->fields($currency['cCID']);$input=$_IN[$prefix];
            if (!is_array($input)) View::setError('wallet_invalid');
            foreach($input as $field=>$value)
                if(!is_string($field) || !array_key_exists($field,$fields) || !is_string($value) || strlen($value)>512)
                    View::setError('wallet_invalid');
            $normalized=opEditToCurrParams($fields,[],$input,$prefix);
            View::setError($normalized);
            if($currency['cCID']==='PAW')foreach(PawPayoutTarget::ASSETS as $asset=>$metadata)
                if(!empty($normalized[$asset]) && !PawPayoutTarget::valid($asset,$normalized[$asset]))View::setError($prefix.$asset.'_wrong');
            $pending[$cid]=$normalized;
        }
        foreach(array_keys($_IN) as $field)
            if(is_string($field) && str_starts_with($field,'currency_') && !array_key_exists(substr($field,9),$_currs))View::setError('wallet_invalid');
        $default=null;
        if($_cfg['Const_IntCurr']) {
            $default=_INN('DefCurr');
            if($default<=1 || !isset($_currs[$default]))View::setError('psys_wrong');
        }
        TransactionScope::run($db->link,static function()use($db,$pending,$default,$walletHasDestination):void {
            global $_cfg,$_currs;
            $uid=_uid();$db->query('SELECT uID FROM Users WHERE uID=?d FOR UPDATE',[$uid]);
            foreach($pending as $cid=>$params) {
                $row=$db->fetch1Row($db->query('SELECT * FROM Wallets WHERE wcID=?d AND wuID=?d FOR UPDATE',[$cid,$uid]));
                if($_cfg['Bal_LockWallets'] && $walletHasDestination(opDecodeUserCurrParams($row?:[]),$_currs[$cid]['cCID']))continue;
                $now=time();$data=['wParams'=>encodeArrayToStr($params,$cid.$uid.$now),'wMTS'=>timeToStamp($now)];
                // Wallets has a composite primary key: a successful INSERT has no
                // auto-ID, and an unchanged UPDATE may legitimately affect zero rows.
                $statement=$db->link->prepare($row
                    ?'UPDATE Wallets SET wParams=?,wMTS=? WHERE wcID=? AND wuID=?'
                    :'INSERT INTO Wallets(wParams,wMTS,wcID,wuID) VALUES(?,?,?,?)');
                $ok=$statement->execute([$data['wParams'],$data['wMTS'],$cid,$uid]);
                if(!$ok)throw new RuntimeException('wallet_save_failed');
            }
            if($default!==null){
                $statement=$db->link->prepare('SELECT auID FROM AddInfo WHERE auID=? FOR UPDATE');$statement->execute([$uid]);
                if($statement->fetchColumn()===false)throw new RuntimeException('wallet_save_failed');
                $statement=$db->link->prepare('UPDATE AddInfo SET aDefCurr=? WHERE auID=?');
                if(!$statement->execute([$default,$uid]))throw new RuntimeException('wallet_save_failed');
            }
        });
        View::showInfo('Saved');
    }
} catch (FormAbortException $e) {}
catch (Throwable) {
    http_response_code(500);
    View::setPage('wallet_save_failed',true,0);
}

$defcurr=[];$wfields=[];$wdata=[];$showbutton=false;$walletGroups=[];
foreach($_currs as $cid=>$currency) {
    if($cid>1)$defcurr[$cid]=$currency['cName'];
    $prefix='currency_'.$cid;$wdata[$prefix]=opDecodeUserCurrParams($currency);
    $locked=$_cfg['Bal_LockWallets'] && $walletHasDestination($wdata[$prefix],$currency['cCID']);
    if($fields=opCurrParamsToEdit($paymentManager->fields($currency['cCID']),$prefix,(bool)$locked)) {
        $kind=HScript\Payment\Provider\ProviderCatalog::kind($currency['cCID'])??$currency['cCID'];
        $providerName=HScript\Payment\Provider\ProviderCatalog::GROUPS[$kind][0]??$currency['cName'];
        $walletGroups[$kind]??=['name'=>$providerName,'currencies'=>[],'sections'=>[]];
        $item=['id'=>$cid,'name'=>$currency['cName'],'code'=>$currency['cCurrID'],'fields'=>$fields];
        $walletGroups[$kind]['currencies'][]=$item;
        $network=HScript\Payment\Provider\WalletPresentation::network($currency);
        $section=$network['key']??'currencies';
        $walletGroups[$kind]['sections'][$section]??=['label'=>$network['label']??'','currencies'=>[]];
        $walletGroups[$kind]['sections'][$section]['currencies'][]=$item;
        $wfields[]=$currency['cName'];
        $wfields=array_merge($wfields,$fields);$showbutton=$showbutton || !$locked;
    }
}
View::setPage('archived_holdings',HScript\Finance\ArchivedHoldings::forUser($db,(int)_uid()));
View::setPage('wallet_groups',$walletGroups);
View::setPage('defcurr',$defcurr);
View::setPage('wfields',$wfields);
View::setPage('wdata',$wdata);
View::setPage('showbutton',$showbutton);
View::showPage();
