<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;

/** Membership lifecycle. Call under the owning catalog/profile and currency locks. */
final class CurrencyArchive
{
    public static function message(\Throwable $error): ?string
    {
        if($error instanceof \PDOException&&in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))return \HScript\Template\View::_t('gateway.error.the_currency_is_in_use_changes_were_rolled_back');
        return match($error->getMessage()){
            'currency_archive_migration_required'=>\HScript\Template\View::_t('gateway.error.complete_the_standard_database_update_to_1_0_16'),
            'activation_ambiguous'=>\HScript\Template\View::_t('gateway.error.this_currency_has_multiple_historical_accounts_enable_the_required'),
            'activation_unavailable'=>\HScript\Template\View::_t('gateway.error.the_saved_currency_modes_cannot_be_enabled_check_currency'),
            'currency_archive_deposits'=>\HScript\Template\View::_t('gateway.error.the_currency_has_active_deposits_complete_them_before_archival'),
            'currency_archive_reservations'=>\HScript\Template\View::_t('gateway.error.the_currency_has_reserved_funds_or_pending_withdrawal_amounts'),
            'currency_archive_operations'=>\HScript\Template\View::_t('gateway.error.the_currency_has_unfinished_operations_reconcile_them_before_archival'),
            'currency_archive_invoice'=>\HScript\Template\View::_t('gateway.error.the_currency_has_issued_invoices_or_a_payment_verification'),
            default=>null,
        };
    }
    public static function requireReady(Connection $db): void
    {
        if(!CurrencyArchiveSchema::ready($db->link))throw new ProviderException('currency_archive_migration_required');
    }
    public static function writable(Connection $db,array $ids): bool
    {
        if(!$db->link->inTransaction())throw new \LogicException('Currency state requires a transaction');
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);
        if(!$ids||min($ids)<1)return false;
        // The permanent internal ledger (ID 1) cannot be archived. It needs no lifecycle lock.
        $ids=array_values(array_diff($ids,[1]));if(!$ids)return true;
        try{$q=$db->link->prepare('SELECT cID,cArchivedAt FROM Currs WHERE cID IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY cID FOR UPDATE NOWAIT');$q->execute($ids);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
        catch(\PDOException $e){if($e->getCode()==='42S22')return true;throw $e;}
        return count($rows)===count($ids)&&!array_filter($rows,static fn($row)=>$row['cArchivedAt']!==null);
    }
    public static function archive(Connection $db,array $row): void
    {
        if(!$db->link->inTransaction())throw new \LogicException('Archive requires a transaction');
        if((int)$row['cID']===1)throw new ProviderException('currency_archive_internal');
        if(($row['cArchivedAt']??null)!==null)return;
        self::assertIdle($db,(int)$row['cID']);
        $modes=implode(',',array_map(static fn($mode)=>'c'.$mode.'Mode=0',CurrencyArchiveSchema::MODES));
        $q=$db->link->prepare('UPDATE Currs SET cArchivedAt=?,cProviderSelected=0,cDisabled=1,cHidden=1,'.$modes.' WHERE cID=?');$q->execute([time(),$row['cID']]);
    }
    public static function restore(Connection $db,array $row): bool
    {
        if(!$db->link->inTransaction())throw new \LogicException('Restore requires a transaction');
        if(($row['cArchivedAt']??null)===null)return false;
        $q=$db->link->prepare('UPDATE Currs SET cArchivedAt=NULL,cProviderSelected=? WHERE cID=?');$q->execute([$row['cProviderID']===null?0:1,$row['cID']]);return true;
    }
    public static function assertIdle(Connection $db,int $id): void
    {
        $pdo=$db->link;
        foreach([
            ['SELECT dID FROM Deps WHERE dcID=? AND dState IN (0,1) LIMIT 1 FOR UPDATE NOWAIT','currency_archive_deposits'],
            ['SELECT wuID FROM Wallets WHERE wcID=? AND (wLock<>0 OR wOut<>0) LIMIT 1 FOR UPDATE NOWAIT','currency_archive_reservations'],
            ['SELECT oID FROM Opers WHERE ocID=? AND oState<=2 LIMIT 1 FOR UPDATE NOWAIT','currency_archive_operations'],
        ]as[$sql,$reason]){$q=$pdo->prepare($sql);$q->execute([$id]);if($q->fetchColumn()!==false)throw new ProviderException($reason);}
        // A pending exchange also owns its target currency, including old serialized quotes.
        $q=$pdo->query("SELECT oID,oParams FROM Opers WHERE oOper='EX' AND oState<=2 ORDER BY oID LIMIT 5001 FOR UPDATE NOWAIT");$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000)throw new ProviderException('currency_archive_operations');
        foreach($rows as$operation){$params=\strToArray($operation['oParams']);if(!isset($params['cid2'])||!is_scalar($params['cid2'])||(int)$params['cid2']===$id)throw new ProviderException('currency_archive_operations');}
        // A cancelled local receipt is not proof that an external invoice was cancelled.
        $q=$pdo->prepare("SELECT ikID,ikState,ikResponse,oState FROM IdempotencyKeys JOIN Opers ON oID=ikOperationID WHERE ocID=? AND ikNamespace='invoice:attempt' ORDER BY ikID FOR UPDATE NOWAIT");$q->execute([$id]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$attempt)if($attempt['ikState']!=='completed'||(int)$attempt['oState']!==3)throw new ProviderException('currency_archive_invoice');
        $q=$pdo->prepare("SELECT ikID FROM IdempotencyKeys JOIN Opers ON oID=ikOperationID WHERE ocID=? AND ikState<>'completed' LIMIT 1 FOR UPDATE NOWAIT");$q->execute([$id]);if($q->fetchColumn()!==false)throw new ProviderException('currency_archive_operations');
        $q=$pdo->prepare("SELECT ikResponse FROM IdempotencyKeys JOIN Opers ON oID=ikOperationID WHERE ocID=? AND ikNamespace='verification:paykassa:order' FOR UPDATE NOWAIT");$q->execute([$id]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)as$response){try{$data=json_decode($response,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ProviderException('currency_archive_invoice');}if(!is_array($data)||!is_bool($data['inflight']??null)||!is_int($data['last']??null)||($data['inflight']&&$data['last']>time()-\HScript\Finance\PaykassaVerification::LEASE))throw new ProviderException('currency_archive_invoice');}
        $q=$pdo->query("SELECT jPayload FROM Jobs WHERE jType='financial.withdraw' AND jState<>2 ORDER BY jID LIMIT 5001 FOR UPDATE NOWAIT");$jobs=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($jobs)>5000)throw new ProviderException('currency_archive_operations');
        foreach($jobs as$payload){$data=\HScript\Queue\JobQueue::decodePayload((string)$payload);$oid=$data['operation_id']??null;if(!is_int($oid)&&!(is_string($oid)&&ctype_digit($oid)))throw new ProviderException('currency_archive_operations');$s=$pdo->prepare('SELECT ocID FROM Opers WHERE oID=? FOR UPDATE NOWAIT');$s->execute([$oid]);if((int)$s->fetchColumn()===$id)throw new ProviderException('currency_archive_operations');}
    }
}
