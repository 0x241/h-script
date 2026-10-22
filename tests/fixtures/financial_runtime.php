<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Database\Connection;
use HScript\Finance\FinancialAuditSnapshot;
use HScript\Finance\FinancialReconciler;

if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/vendor/autoload.php';
ini_set('error_log', '/tmp/financial-test-errors.log');
$legacyDiagnostics=[];
set_error_handler(static function ($severity,$message,$file) use (&$legacyDiagnostics): bool {
    if ($severity === E_WARNING && $message === 'Undefined array key "dState"' && str_ends_with($file,'/module/depo/lib.php')) {
        $legacyDiagnostics[]=$message; return true;
    }
    return false;
});

// Only delivery/rendering is stubbed. Financial functions, SQL and history are real.
final class FinancialFixtureView
{
    public static bool $queueMail = false;
    public static array $pages = [];
    public static function __callStatic($name, $arguments) {
        if (strtolower($name) === 'setpage') self::$pages[$arguments[0]] = $arguments[1];
        if (self::$queueMail && strtolower($name) === 'sendmailtouser')
            return HScript\Mail\Mailer::send($arguments[0], 'Synthetic financial notification', 'Synthetic fixture body');
        return null;
    }
}
class_alias(FinancialFixtureView::class, 'HScript\\Template\\View');
function useLib($module = '') { /* Financial modules are explicitly loaded below. */ }
function moduleToLink($module = '', $chpu = false, $https = 0) { return '/' . $module; }

final class FinancialFixtureConnection extends Connection
{
    public bool $rejectTransferChild = false;
    public bool $rejectExchangeChild = false;
    public bool $rejectPayoutJob = false;
    public bool $rejectWalletUpdate = false;
    public bool $abortEmailTransactionOnce = false;
    public ?string $rejectRateProperty = null;
    public bool $throwRateWrite = false;
    public bool $rejectDepositInsert = false;
    public bool $rejectDepositUpdate = false;
    public bool $rejectDepositLastTime = false;
    public ?string $rejectInfoOperation = null;
    public function insert($table, $values, $fields = '', $as_replace = false)
    {
        if ($this->rejectDepositInsert && $table === 'Deps') return 0;
        if ($this->rejectDepositLastTime && $table === 'Cfg' && ($values['Module'] ?? '') === 'Depo') return 0;
        if ($table === 'Opers' && ($values['oOper'] ?? '') === $this->rejectInfoOperation) return 0;
        if ($table === 'Cfg' && ($values['Module'] ?? '') === 'Bal'
            && ($values['Prop'] ?? '') === $this->rejectRateProperty) {
            if ($this->throwRateWrite) throw new PDOException('Synthetic rate write failure');
            return 0;
        }
        if ($this->rejectTransferChild && $table === 'Opers' && ($values['oOper'] ?? '') === 'TRIN') return 0;
        if ($this->rejectExchangeChild && $table === 'Opers' && ($values['oOper'] ?? '') === 'EXIN') return 0;
        if ($this->rejectPayoutJob && $table === 'Jobs' && ($values['jType'] ?? '') === 'financial.withdraw') return 0;
        if ($this->abortEmailTransactionOnce && $table === 'Jobs' && ($values['jType'] ?? '') === 'email') {
            $this->abortEmailTransactionOnce = false;
            // Model InnoDB's whole-transaction rollback before delivering 1213.
            $this->link->rollBack();
            $error = new PDOException('Synthetic email deadlock', 40001);
            $error->errorInfo = ['40001', 1213, 'Synthetic email deadlock'];
            throw $error;
        }
        return parent::insert($table, $values, $fields, $as_replace);
    }
    public function update($table, $values, $fields = '', $filter = '', $ph_values = null)
    {
        if ($this->rejectDepositUpdate && $table === 'Deps') return 0;
        if ($this->rejectWalletUpdate && $table === 'Wallets') return 0;
        return parent::update($table, $values, $fields, $filter, $ph_values);
    }
}
$db = new FinancialFixtureConnection();
if (!$db->open(getenv('DB_HOST'), 'fixture', getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
$pdo = $db->link;
require $root . '/_dbstru.php';
foreach (['Cfg','Users','AddInfo','PaymentProviders','PaymentProviderCredentials','Currs','Wallets','Opers','Plans','Deps','Hist','IdempotencyKeys','Jobs'] as $table) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . $_dbstru[$table] . ') ENGINE=InnoDB');
}
require $root . '/module/lib.php';
require $root . '/module/balance/lib.php';
require $root . '/module/depo/lib.php';
require $root . '/module/refsys/onload.php';

$assertions = 0;
$gaps = [];
function financialAssert(bool $condition, string $name): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException($name);
    $assertions++;
}
function financialValue(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function financialReset(): void
{
    global $db,$pdo,$_cfg,$_currs,$_GS,$_onload;
    $db->rejectTransferChild = $db->rejectWalletUpdate = false;
    $db->rejectExchangeChild = $db->rejectPayoutJob = false;
    $db->abortEmailTransactionOnce = FinancialFixtureView::$queueMail = false;
    FinancialFixtureView::$pages = [];
    $db->rejectDepositInsert = $db->rejectDepositUpdate = $db->rejectDepositLastTime = false;
    $db->rejectInfoOperation = null;
    $db->rejectRateProperty = null; $db->throwRateWrite = false;
    foreach(['PaymentConfirmations','ProviderTokenCatalogs','ProviderNetworkWallets'] as $configurationTable)if($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$pdo->quote($configurationTable))->fetchColumn())$pdo->exec('DELETE FROM '.$configurationTable);
    foreach (['IdempotencyKeys','Jobs','Hist','Opers','Deps','Wallets','AddInfo','Users','Currs','PaymentProviderCredentials','PaymentProviders','Plans','Cfg'] as $table) $pdo->exec('DELETE FROM ' . $table);
    $_cfg = ['UI_NumDec'=>2,'Const_IntCurr'=>0,'Depo_ChargeMode'=>1,'Depo_Interval'=>0,'Depo_OneFromGroup'=>0,'Bal_RateEUR'=>'2','Bal_RateBTC'=>'30000','Depo_LastTime'=>0];
    $_onload = [];
    $_GS['client_ip'] = '127.0.0.1';
    $_GS['root_url'] = 'http://fixture.invalid/';
    foreach ([1,2,3] as $id) {
        $db->insert('Users',['uID'=>$id,'uLogin'=>'fixture-'.$id,'uMail'=>'fixture-'.$id.'@fixture.invalid','uState'=>1,'uRef'=>$id<3 ? $id+1 : 0]);
        $db->insert('AddInfo',['auID'=>$id]);
    }
    foreach ([1=>'USD',2=>'EUR',3=>'BTC'] as $id=>$currency) {
        $salt=time();
        $gateway=$id===1?'BW':'CP';
        $db->insert('Currs',['cID'=>$id,'cCID'=>$gateway,'cCurrID'=>$currency,'cName'=>$currency,'cNumDec'=>$id===3?8:2,'cCASHOUTMode'=>1,'cEXMode'=>1,'cTRMode'=>1,'cGIVEMode'=>1,'cTAKEMode'=>1,
            'cMTS'=>timeToStamp($salt),'cParamsSCI'=>encodeArrayToStr(['id'=>'fixture-merchant','key'=>'fixture-key'], $id.$gateway.$salt,2)]);
    }
    $_currs = $db->fetchIDRows($db->select('Currs'),false,'cID');
    $db->insert('Plans',['pID'=>1,'pName'=>'Fixture','pMin'=>1,'pMax'=>1000,'pPerc'=>10,'pPer'=>24,'pNPer'=>2,'pReturn'=>100,'pCompndMax'=>100,'pEnAdd'=>1,'pClComis'=>10]);
}
function financialWallet(int $user, int $currency, string $available, string $locked='0.000000', string $outgoing='0.000000'): void
{
    financialAssert(financialValue("SELECT wBal FROM Wallets WHERE wuID=$user AND wcID=$currency") === $available, 'available bucket');
    financialAssert(financialValue("SELECT wLock FROM Wallets WHERE wuID=$user AND wcID=$currency") === $locked, 'locked bucket');
    financialAssert(financialValue("SELECT wOut FROM Wallets WHERE wuID=$user AND wcID=$currency") === $outgoing, 'outgoing bucket');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM (' . FinancialReconciler::queries()['user_total'] . ') f')===0,'uBal aggregate');
}
function financialGap(string $id, bool $condition): void
{
    global $gaps;
    financialAssert($condition, 'Known finding no longer reproduced: '.$id.'; review audit and strengthen contract');
    $gaps[] = $id;
}
function financialDepo(int $id): array
{
    global $db;
    return $db->fetch1Row($db->select('Deps LEFT JOIN Plans ON pID=dpID LEFT JOIN Users ON uID=duID','*','dID=?',[$id]));
}
function financialFingerprint(): string
{
    global $pdo;
    $all=[];
    foreach (FinancialAuditSnapshot::COLUMNS as $table=>$columns) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows); $all[$table]=$rows;
    }
    return hash('sha256',serialize($all));
}
