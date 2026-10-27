<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;

/** One terminal VARY payment, proof and ordinary wallet credit commit together. */
final class PawSettlement
{
    public static function settle(Connection $db,int $currency,array $verified): IdempotencyResult
    {
        if(($verified['correct']??null)!==true || ($verified['authenticated']??null)!==true || ($verified['outcome']??null)!=='verified'
            || !empty($verified['outbound']) || !empty($verified['wait']) || !is_string($verified['tag']??null)
            || !preg_match('/^[1-9][0-9]{0,9}$/D',$verified['tag']))throw new CallbackValidationException('callback_authentication_required');
        return TransactionScope::run($db->link,static function()use($db,$currency,$verified):IdempotencyResult {
            $o=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[(int)$verified['tag']]));
            if(!$o || $o['cCID']!=='PAW' || $o['oOper']!=='CASHIN' || (int)$o['ocID']!==$currency || $verified['curr']!==$o['cCurrID'])throw new CallbackValidationException('paw_operation_mismatch');
            $form=DepositCheckout::storedForm($o);
            if($form===null)throw new CallbackValidationException('paw_invoice_missing');
            $attempt=PawContract::attempt($db,$o,$form);
            $binding=PawContract::validate($o,$verified,$form,$attempt);
            $state=(int)$o['oState'];$batch='PAW:'.$form['paw_order'];
            if($state<0 || $state>3 || CallbackAmount::atScale($o['oSum'],$attempt['scale'])!==($state===3?$binding['amount']:$attempt['gross'])
                || ($state===3 && $o['oBatch']!==$batch))throw new CallbackValidationException('paw_operation_mismatch');
            $fingerprint=IdempotencyRequest::fingerprint($binding);
            if(ArchivedReplay::inactive($o))return ArchivedReplay::paw($db,$o,$form,$binding,$fingerprint);
            $result=(new IdempotencyStore($db))->executeInTransaction('gateway:paw:currency:'.$currency.':settled',hash('sha256',$form['paw_order']),0,$fingerprint,
                static function()use($db,$o,$binding,$batch,$state):IdempotencyResult {
                    if($state===3)throw new CallbackValidationException('paw_binding_missing');
                    CallbackContract::refreshCurrencyContext($o,$binding['profile']['intent']['scale']);
                    $db->update('Opers',['oSum'=>$binding['amount']],'','oID=?d',[(int)$o['oID']]);
                    // No receipt sum: ordinary completion would recalculate commission.
                    $receipt=['batch'=>$batch,'date'=>$binding['processed_at'],'acc'=>'PAW',
                        'paw_requested'=>$binding['profile']['intent']['gross'],'paw_received'=>$binding['amount'],'paw_status'=>$binding['status']];
                    FinancialOperationException::check(opOperConfirm(-1,(int)$o['oID'],$receipt));
                    FinancialOperationException::check(opOperComplete(-1,(int)$o['oID']));
                    return new IdempotencyResult(['settled'=>true,'paw_binding'=>$binding],200,(int)$o['oID']);
                });
            if($result->operationId!==(int)$o['oID'] || ($result->data['paw_binding']??null)!==$binding || ($result->data['settled']??null)!==true)
                throw new CallbackValidationException('paw_binding_missing');
            return $result;
        });
    }
}
