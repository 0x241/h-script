<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;
use HScript\Finance\FinancialReconciler;

// This fixture deliberately uses the installed schema, never the installer.
if (getenv('H_SCRIPT_FINANCIAL_FIXTURE') !== '1'
    || !in_array(getenv('APP_ENV'), ['local', 'development', 'dev', 'test'], true)
    || getenv('APP_DOMAIN') !== 'hs.local') {
    fwrite(STDERR, "Only the explicitly enabled hs.local test stand is supported.\n");
    exit(64);
}
$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/vendor/autoload.php';
$_cfg = [];
require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
$credentials = DatabaseCredentials::fromConfig($_cfg, 'hs.local');
// APP_DOMAIN alone cannot authorize writes to an accidentally remote config.
if (!in_array($credentials->host(), ['database', 'mysql', 'lamp-mysql8', '127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Fixture database must be on the local Compose network.\n");
    exit(64);
}

final class FinancialStandView
{
    public static function __callStatic($name, $arguments) { return null; }
}
class_alias(FinancialStandView::class, 'HScript\\Template\\View');
function useLib($module = '') { /* Explicit imports below; delivery is disabled. */ }
function moduleToLink($module = '', $chpu = false, $https = 0) { return '/' . $module; }

final class FinancialStandConnection extends Connection
{
    public function replace($table, $values, $fields = '', $filter = '', $ph_values = null)
    {
        // Deposit creation normally changes a shared cron boundary. Keep the
        // installation's real configuration intact while exercising the rest.
        if ($table === 'Cfg' && ($values['Module'] ?? '') === 'Depo'
            && ($values['Prop'] ?? '') === 'LastTime') return true;
        return parent::replace($table, $values, $fields, $filter, $ph_values);
    }
}

$db = new FinancialStandConnection();
if (!$db->open($credentials->connectionHost(), $credentials->database(), $credentials->username(), $credentials->password())) {
    fwrite(STDERR, "Local fixture database unavailable.\n"); exit(1);
}
$pdo = $db->link;
$marker = 'zz-fixture7-';
$label = '[FAKE-A7]';
$users = 256;
$rounds = 12;
$mode = 'seed';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--clear') $mode = 'clear';
    elseif ($argument === '--status') $mode = 'status';
    elseif (preg_match('/^--users=([0-9]+)$/D', $argument, $match)) $users = (int)$match[1];
    elseif (preg_match('/^--rounds=([0-9]+)$/D', $argument, $match)) $rounds = (int)$match[1];
    else { fwrite(STDERR, "Usage: financial_stand.php [--users=16..1024] [--rounds=1..100] | --status | --clear\n"); exit(64); }
}
if ($users < 16 || $users > 1024 || $rounds < 1 || $rounds > 100) exit(64);

function standIds(string $table, string $field, string $where, array $values): array
{
    global $pdo;
    $query = $pdo->prepare("SELECT $field FROM $table WHERE $where ORDER BY $field");
    $query->execute($values);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}
function standCounts(): array
{
    global $pdo, $marker, $label;
    $result = [];
    foreach (['Users'=>['uLogin LIKE ?', $marker.'%'], 'Currs'=>['cName LIKE ?', $label.'%'],
        'Plans'=>['pName LIKE ?', $label.'%'], 'News'=>['nTopic LIKE ?', $label.'%'],
        'FAQ'=>['fCat LIKE ?', $label.'%']] as $table=>[$where,$value]) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where"); $q->execute([$value]); $result[$table]=(int)$q->fetchColumn();
    }
    foreach (['Wallets'=>'wuID','Opers'=>'ouID','Deps'=>'duID','Hist'=>'huID','Review'=>'ouID','Tickets'=>'tuID','Msg'=>'muID','MBox'=>'buID'] as $table=>$owner) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM $table JOIN Users ON uID=$owner WHERE uLogin LIKE ?");
        $q->execute([$marker.'%']); $result[$table]=(int)$q->fetchColumn();
    }
    $q=$pdo->prepare('SELECT COUNT(*) FROM TMsg JOIN Tickets ON tID=mtID JOIN Users ON uID=tuID WHERE uLogin LIKE ?');
    $q->execute([$marker.'%']); $result['TMsg']=(int)$q->fetchColumn();
    return $result;
}
function standMust(mixed $value, string $scenario, bool $id = false): mixed
{
    if (($id && (!is_int($value) || $value <= 0)) || (!$id && $value !== true)) {
        $suffix=is_string($value) && preg_match('/^[a-z0-9_]+$/D',$value) ? '_'.$value : '';
        throw new RuntimeException($scenario.$suffix);
    }
    return $value;
}
function standInsert(string $table, array $row): int
{
    global $pdo;
    $sql='INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int)($row['auID'] ?? $pdo->lastInsertId());
}
function standDepo(int $id): array
{
    global $db;
    return $db->fetch1Row($db->select('Deps JOIN Plans ON pID=dpID JOIN Users ON uID=duID','*','dID=?d',[$id]));
}
function standOperation(int $user, string $operation, int $currency, float $sum, array $params=[], bool $complete=true): int
{
    global $label;
    return standMust(opOperCreate($user,$operation,$currency,$sum,$params,$label,$complete,true),'fixture_'.$operation,true);
}

try {
    if ($pdo->query("SELECT GET_LOCK('hscript-financial-stand-v1',0)")->fetchColumn() != 1) throw new RuntimeException('fixture_busy');
    if ($mode === 'status') {
        echo json_encode(['status'=>'fixture_status','rows'=>standCounts()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"; exit(0);
    }
    $owned = standIds('Users','uID','uLogin LIKE ?',[$marker.'%']);
    if ($mode === 'clear') {
        $currencies = standIds('Currs','cID','cName LIKE ?',[$label.'%']);
        $plans = standIds('Plans','pID','pName LIKE ?',[$label.'%']);
        $ids = implode(',', $owned ?: [0]);
        $cids = implode(',', $currencies ?: [0]);
        $pids = implode(',', $plans ?: [0]);
        // Refuse cleanup if subsequent manual use connected real records to the fixture.
        foreach (["SELECT COUNT(*) FROM Users WHERE uRef IN ($ids) AND uID NOT IN ($ids)",
            "SELECT COUNT(*) FROM Wallets WHERE wuID IN ($ids) AND wcID NOT IN ($cids)",
            "SELECT COUNT(*) FROM Opers WHERE ouID IN ($ids) AND ocID NOT IN ($cids)",
            "SELECT COUNT(*) FROM Wallets WHERE wcID IN ($cids) AND wuID NOT IN ($ids)",
            "SELECT COUNT(*) FROM Opers WHERE ocID IN ($cids) AND ouID NOT IN ($ids)",
            "SELECT COUNT(*) FROM Deps WHERE (dcID IN ($cids) OR dpID IN ($pids)) AND duID NOT IN ($ids)",
            "SELECT COUNT(*) FROM Opers WHERE ouID NOT IN ($ids) AND oOper IN ('TRIN','EXIN') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(oParams) THEN oParams ELSE '{}' END,'$.oid')) AS UNSIGNED) IN (SELECT oID FROM Opers WHERE ouID IN ($ids))",
            "SELECT COUNT(*) FROM Opers WHERE ouID IN ($ids) AND oOper='TR' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(oParams) THEN oParams ELSE '{}' END,'$.uid2')) AS UNSIGNED) NOT IN ($ids)",
            "SELECT COUNT(*) FROM MBox WHERE buID NOT IN ($ids) AND (buID2 IN ($ids) OR bmID IN (SELECT mID FROM Msg WHERE muID IN ($ids)))"] as $check) {
            if ((int)$pdo->query($check)->fetchColumn() !== 0) throw new RuntimeException('fixture_has_external_links');
        }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM TMsg WHERE mtID IN (SELECT tID FROM Tickets WHERE tuID IN ($ids))");
        foreach (['Wallets'=>'wuID','Opers'=>'ouID','Deps'=>'duID','Hist'=>'huID','Review'=>'ouID','Tickets'=>'tuID','Msg'=>'muID','MBox'=>'buID','AddInfo'=>'auID','Users'=>'uID'] as $table=>$owner) $pdo->exec("DELETE FROM $table WHERE $owner IN ($ids)");
        foreach (['News'=>'nTopic','FAQ'=>'fCat','Plans'=>'pName','Currs'=>'cName'] as $table=>$name) {
            $q=$pdo->prepare("DELETE FROM $table WHERE $name LIKE ?"); $q->execute([$label.'%']);
        }
        $pdo->exec("DELETE FROM Cfg WHERE Module='Fixture7'");
        $pdo->commit();
        echo json_encode(['status'=>'fixture_cleared','rows'=>standCounts()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"; exit(0);
    }
    $status=$pdo->query("SELECT Val FROM Cfg WHERE Module='Fixture7' AND Prop='Status'")->fetchColumn();
    if ($owned) {
        if ($status !== 'completed') throw new RuntimeException('partial_fixture_use_clear_before_retry');
        echo json_encode(['status'=>'fixture_already_seeded','rows'=>standCounts()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"; exit(0);
    }
    $pdo->exec("INSERT INTO Cfg (Module,Prop,Val) VALUES ('Fixture7','Status','seeding') ON DUPLICATE KEY UPDATE Val='seeding'");
    // Only known legacy diagnostics are counted; other PHP warnings still fail visibly.
    $diagnostics=0;
    $referralDiagnostics=0;
    set_error_handler(static function ($severity,$message,$file) use (&$diagnostics,&$referralDiagnostics): bool {
        if ($severity === E_WARNING && $message === 'Undefined array key "dState"' && str_ends_with($file,'/module/depo/lib.php')) { $diagnostics++; return true; }
        if ($severity === E_WARNING && $message === 'Undefined array key "cid2"' && str_ends_with($file,'/module/refsys/onload.php')) { $referralDiagnostics++; return true; }
        return false;
    });
    require $root . '/module/lib.php';
    require $root . '/module/balance/lib.php';
    require $root . '/module/depo/lib.php';
    require $root . '/module/refsys/onload.php';
    $_cfg = ['UI_NumDec'=>2,'Const_IntCurr'=>0,'Depo_ChargeMode'=>1,'Depo_Interval'=>0,'Depo_OneFromGroup'=>0,
        'Bal_RateUSD'=>'1','Bal_RateEUR'=>'2','Bal_RateBTC'=>'30000','Depo_LastTime'=>0,
        'Ref__Perc'=>'2,1,0.5','Ref_Type'=>0,'Ref__DPerc'=>'1,0.5','Ref_DType'=>0,'Ref__PPerc'=>'1,0.5','Ref_PType'=>0];
    $_onload=['RefSys'=>true];
    $_GS=array_replace($_GS ?? [], ['client_ip'=>'127.0.0.1','root_url'=>'http://hs.local/',
        'https'=>false,'domain'=>'hs.local','root_dir'=>$root.'/']);
    $currencyIds=[];
    foreach (['USD'=>2,'EUR'=>2,'BTC'=>6] as $currency=>$scale) {
        $currencyIds[]=standInsert('Currs',['cName'=>$label.' '.$currency,'cCID'=>'BW','cCurrID'=>$currency,'cCurr'=>$currency,'cNumDec'=>$scale,
            'cDisabled'=>0,'cCASHINMode'=>1,'cCASHOUTMode'=>1,'cEXMode'=>1,'cTRMode'=>1,'cBUYMode'=>1,'cSELLMode'=>1,'cSELL2Mode'=>1,'cGIVEMode'=>1,'cTAKEMode'=>1,
            'cCASHOUTComis'=>1,'cEXComis'=>0.5,'cTRComis'=>1.5]);
    }
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    $group=(int)$pdo->query('SELECT COALESCE(MAX(pGroup),0)+1 FROM Plans')->fetchColumn();
    $planIds=[];
    foreach ([['Short',2,0,100],['Compound',12,5,100],['Partial return',3,0,75],['Open ended',0,2,100]] as [$name,$periods,$bonus,$return]) {
        $planIds[]=standInsert('Plans',['pName'=>$label.' '.$name,'pDescr'=>'Synthetic local financial audit plan','pGroup'=>$group,
            'pMin'=>0.01,'pMax'=>500000,'pPerc'=>2,'pPer'=>24,'pNPer'=>$periods,'pReturn'=>$return,'pCompndMax'=>100,'pEnAdd'=>1,'pMinAdd'=>0.01,'pClComis'=>10,'pBonus'=>$bonus]);
    }
    // Basic roles only. No fake account receives administrator privileges.
    $password=password_hash('Synthetic7-local-only!',PASSWORD_BCRYPT,['cost'=>10]);
    $userIds=[];
    for ($i=0;$i<$users;$i++) {
        $login=$marker.str_pad((string)($i+1),4,'0',STR_PAD_LEFT);
        $userIds[]=standInsert('Users',['uLogin'=>$login,'uMail'=>$login.'@fixture.invalid','uPass'=>$password,'uState'=>1,'uLevel'=>1,
            'uLang'=>$i%2?'ru':'en','uRef'=>$i ? $userIds[intdiv($i-1,3)] : 0,'uBTS'=>timeToStamp(time()-86400*(1+$i%60))]);
        standInsert('AddInfo',['auID'=>end($userIds),'aName'=>$label.' User '.($i+1),'aCountry'=>'Test','aCity'=>'Fixture city',
            'aCTS'=>timeToStamp(),'aNoMail'=>1,'aNeedReConfig'=>0,'aDefCurr'=>$currencyIds[0]]);
    }
    foreach ($userIds as $i=>$uid) {
        foreach ($currencyIds as $j=>$cid) standOperation($uid,'BONUS',$cid,$j===2?10:10000);
        for ($round=0;$round<$rounds;$round++) {
            $cid=$currencyIds[$round%2];
            $oid=standOperation($uid,'CASHIN',$cid,100+($round%7),[],false);
            standMust(opOperConfirm($uid,$oid,['sum'=>100+($round%7),'date'=>time(),'batch'=>'f7-in-'.$uid.'-'.$round],true),'cashin_confirm');
            standMust(opOperComplete($uid,$oid,[],true),'cashin_complete');
            foreach (['BONUS','PENALTY','BUY','SELL','SELL2','CALCOUT'] as $operation) standOperation($uid,$operation,$cid,1+($round%3));
            standOperation($uid,'TR',$cid,5.25,['uid2'=>$userIds[($i+1)%$users]]);
            standOperation($uid,'EX',$cid,4,['cid2'=>$currencyIds[1-($round%2)]]);
            $oid=standOperation($uid,'CASHOUT',$cid,5,[],false);
            standMust(opOperConfirm($uid,$oid,[],true),'cashout_confirm');
            if ($round%4===0) standMust(opOperCancel($uid,$oid,[],false),'cashout_cancel_user');
            elseif ($round%4===1) standMust(opOperCancel($uid,$oid,[],true),'cashout_cancel_admin');
            elseif ($round%4===2) standMust(opOperComplete($uid,$oid,['date'=>time(),'batch'=>'f7-out-'.$uid.'-'.$round],true),'cashout_complete');
            // Other withdrawals remain genuinely reserved in wOut / state 2.
            if ($round===0) {
                standOperation($uid,'CASHIN',$cid,7,[],false); // state 0
                $pending=standOperation($uid,'CASHIN',$cid,8,[],false);
                $pdo->prepare('UPDATE Opers SET oState=1 WHERE oID=?')->execute([$pending]); // legacy waiting state
            }
        }
        // Exercise the six-place currency beyond its initial credit.
        standOperation($uid,'TR',$currencyIds[2],0.000125,['uid2'=>$userIds[($i+1)%$users]]);
        standOperation($uid,'EX',$currencyIds[2],0.0001,['cid2'=>$currencyIds[0]]);
        $oid=standOperation($uid,'CASHOUT',$currencyIds[2],0.00005,[],false);
        standMust(opOperConfirm($uid,$oid,[],true),'crypto_cashout_confirm');
        standMust(opOperComplete($uid,$oid,['date'=>time(),'batch'=>'f7-crypto-'.$uid],true),'crypto_cashout_complete');
        $crypto=standMust(opDepoCreate($uid,$currencyIds[2],0.005,50,$planIds[0],true),'crypto_deposit_create',true);
        standMust(opDepoCharge(standDepo($crypto)),'crypto_deposit_accrual');
        standMust(opDepoCharge(standDepo($crypto)),'crypto_deposit_terminal');
        foreach ($planIds as $j=>$pid) {
            $cid=$currencyIds[$j%2];
            $did=standMust(opDepoCreate($uid,$cid,100,($i%3)*50,$pid,true),'deposit_create',true);
            standMust(opDepoAdd($uid,$cid,20,$did),'deposit_topup');
            standMust(opDepoSub($uid,$cid,10,$did),'deposit_partial');
            standMust(opDepoCharge(standDepo($did)),'deposit_accrual');
            if ($j===0) standMust(opDepoCharge(standDepo($did)),'deposit_terminal');
            elseif ($j===2) {
                if ($i%2) {
                    standMust(opDepoCharge(standDepo($did)),'deposit_partial_return_accrual');
                    standMust(opDepoCharge(standDepo($did)),'deposit_partial_return_terminal');
                } else standMust(opDepoClose($uid,$cid,$did),'deposit_admin_close',true);
            }
            elseif ($j===1 && $i%4===0) standMust(opDepoSub($uid,$cid,(float)standDepo($did)['dZD'],$did),'deposit_full_early_withdrawal');
            else {
                if ($j===3 && $i%4===1) {
                    $changed=opDepoChangePlan($uid,$planIds[1],$did);
                    if ($changed !== 1) throw new RuntimeException('deposit_plan_change');
                    $changed=opDepoChangeCompnd($uid,$did,($i%3)*50===50?100:50);
                    if ($changed !== 1) throw new RuntimeException('deposit_compound_change');
                }
                // Keep active synthetic principal, but move the next boundary
                // beyond the audit session so background cron cannot race it.
                $pdo->prepare('UPDATE Deps SET dNTS=?,dETS=? WHERE dID=?')->execute([timeToStamp(time()+30*86400),timeToStamp(time()+90*86400),$did]);
            }
        }
        if (($i+1)%32===0) fwrite(STDERR,'Fixture users completed: '.($i+1).'/'.$users."\n");
    }
    $pdo->beginTransaction();
    for ($i=0;$i<300;$i++) standInsert('News',['nTS'=>timeToStamp(time()-$i*7200),'nTopic'=>$label.' {!ru!}Новость '.$i.'{!en!}News '.$i.'{!!}',
        'nAnnounce'=>'Synthetic announcement '.$i,'nText'=>'<p>'.$label.' Synthetic news: currencies, deposits, transfers. Unicode: Пример € ₿.</p>',
        'nAttn'=>$i%2,'nDBegin'=>timeToStamp(time()-86400),'nDEnd'=>0]);
    for ($i=0;$i<80;$i++) standInsert('FAQ',['fCat'=>$label.' '.['Wallets','Deposits','Transfers','Security'][$i%4],'fCTS'=>timeToStamp(),
        'fHidden'=>$i%7===0?1:0,'fOrder'=>$i,'fQuestion'=>$label.' Synthetic question '.$i.'?','fAnswer'=>'<p>Synthetic answer '.$i.'. No real transaction or personal data.</p>']);
    for ($i=0;$i<1200;$i++) {
        $uid=$userIds[$i%$users];
        standInsert('Review',['ouID'=>$uid,'oAuthor'=>$label.' User '.($i%$users+1),'oText'=>$label.' Synthetic review '.$i.'. Пример текста и длинного отзыва.','oRating'=>1+$i%5,'oState'=>$i%4===0?0:1,'oOrder'=>$i%20,'oTS'=>timeToStamp(time()-$i*3600)]);
        $tid=standInsert('Tickets',['tuID'=>$uid,'tTID'=>'f7-ticket-'.$i,'tName'=>$label.' User','tMail'=>$marker.($i%$users+1).'@fixture.invalid',
            'tTS'=>timeToStamp(time()-$i*1800),'tLTS'=>timeToStamp(),'tCat'=>$label.' Financial audit','tTopic'=>$label.' Ticket '.$i,
            'tText'=>'Synthetic support request '.$i,'tPriority'=>$i%3,'tState'=>$i%4,'tLang'=>$i%2?'ru':'en']);
        for ($reply=0;$reply<3;$reply++) standInsert('TMsg',['mtID'=>$tid,'muID'=>$uid,'mTS'=>timeToStamp(),'mText'=>$label.' Synthetic reply '.$reply,'mRTS'=>$reply%2?timeToStamp():0]);
        $mid=standInsert('Msg',['muID'=>$uid,'mTS'=>timeToStamp(),'mTopic'=>$label.' Message '.$i,'mText'=>'Synthetic local inbox content '.$i,
            'mLang'=>$i%2?'ru':'en','mTo'=>json_encode([$userIds[($i+1)%$users]]),'mToCnt'=>1]);
        standInsert('MBox',['buID'=>$userIds[($i+1)%$users],'buID2'=>$uid,'bmID'=>$mid,'bRTS'=>$i%3?timeToStamp():0,'bDeleted'=>$i%9===0?1:0]);
    }
    $pdo->exec("UPDATE Cfg SET Val='completed' WHERE Module='Fixture7' AND Prop='Status'");
    $pdo->commit();
    $report=(new FinancialReconciler())->run($pdo);
    echo json_encode(['status'=>'fixture_seeded','users'=>$users,'rounds'=>$rounds,'rows'=>standCounts(),
        'legacy_diagnostics'=>$diagnostics,'legacy_referral_optional_key_diagnostics'=>$referralDiagnostics,
        'reconciliation'=>$report],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // A bounded scenario name identifies progress without exposing database credentials.
    $code=$error instanceof RuntimeException && preg_match('/^[a-zA-Z0-9_]+$/D',$error->getMessage()) ? $error->getMessage() : 'fixture_failed';
    fwrite(STDERR,json_encode(['status'=>'error','error_code'=>$code,'rows'=>standCounts()],JSON_THROW_ON_ERROR)."\n"); exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('hscript-financial-stand-v1')");
}
