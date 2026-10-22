<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Finance\{CurrencyScale,FinancialReconciler,FinancialAuditSnapshot};

function reconciliationCommand(array $args, array $environment=[]): array
{
    $out=tempnam(sys_get_temp_dir(),'fixture-reconcile-');$err=tempnam(sys_get_temp_dir(),'fixture-reconcile-');
    try {
        $env=array_replace(getenv(),['DB_USER'=>'fixture_readonly','DB_PASSWORD'=>'synthetic-readonly-password'],$environment);
        $p=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/bin/financial-reconcile.php'],$args),
            [0=>['file','/dev/null','r'],1=>['file',$out,'w'],2=>['file',$err,'w']],$pipes,dirname(__DIR__),$env);
        if (!is_resource($p)) throw new RuntimeException('Cannot launch fixture CLI');
        $code=proc_close($p);$stdout=file_get_contents($out);$stderr=file_get_contents($err);
        financialAssert($stderr==='','CLI has no unexpected stderr');
        foreach (['PRIVATE','synthetic-readonly-password','fixture_readonly','fixture-secret','fixture-receiver'] as $secret)
            financialAssert(!str_contains($stdout,$secret),'CLI output excludes synthetic identity/credentials/configuration');
        return [$code,$stdout];
    } finally { unlink($out);unlink($err); }
}

financialReset();propertyScale(2);
$pdo->exec("UPDATE Users SET uMail='fixture-secret@fixture.invalid' WHERE uID=1");
$pdo->exec("INSERT INTO Cfg (Module,Prop,Val) VALUES ('Secret','Token','PRIVATE_fixture-secret')");
$readonly=new PDO('mysql:host=database;dbname=fixture;charset=utf8mb4','fixture_readonly','synthetic-readonly-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (['UPDATE Users SET uBal=1 WHERE uID=1','DELETE FROM Wallets','CREATE TABLE unauthorized (id INT)','SELECT * FROM Jobs','SELECT * FROM IdempotencyKeys'] as $sql) {
    try {$readonly->exec($sql);throw new LogicException('Read-only account has excess privileges');}
    catch (PDOException) {$assertions++;}
}
$before=propertyFingerprint();
foreach ([[],['--json'],['--text'],['--snapshot'],['--queries']] as $args) {
    [$code,$out]=reconciliationCommand($args);financialAssert($code===0,'clean CLI exit0');
    if ($args===['--text']) financialAssert(str_starts_with($out,"status: clear\nfinding_count: 0\n"),'clear text output');
    elseif ($args!==['--queries']) {
        $json=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        financialAssert(($args===['--snapshot']?$json['reconciliation']['status']:$json['status'])==='clear','clean JSON projection/report');
        if ($args===['--snapshot']) financialAssert($json['format']===FinancialAuditSnapshot::FORMAT && $json['scale_context']===['ui_num_dec'=>2],'sanitized snapshot precision context');
    }
    financialAssert(propertyFingerprint()===$before,'CLI changes no table');
}

// SQL checker mirrors PHP resolved precision, including default/null and byte-exact keys.
$rawScales=[null,-100,-99,-1,0,1,6,7,8,9,100];
$uiValues=[null,'','0','2','06','8','9','-1',' 2',"2\n",'2.0','PRIVATE_fixture-secret'];
$vectors=0;
foreach ($uiValues as $ui) {
    $pdo->prepare("UPDATE Cfg SET Val=? WHERE Module='UI' AND Prop='NumDec'")->execute([$ui]);
    foreach ($rawScales as $raw) {
        $pdo->prepare('UPDATE Currs SET cNumDec=? WHERE cID=1')->execute([$raw]);
        try {CurrencyScale::resolve(['cNumDec'=>$raw],['UI_NumDec'=>$ui]);$expected=false;}
        catch (InvalidArgumentException) {$expected=true;}
        $before=propertyFingerprint();$r=(new FinancialReconciler())->run($pdo);
        financialAssert($r['checks']['currency_scale_storage']['count']===($expected?1:0),'SQL resolved-scale matches independent PHP contract');
        financialAssert(array_column($r['checks']['currency_scale_storage']['sample'],'entity_id')===($expected?[1]:[]),'exact affected currency IDs');
        financialAssert(propertyFingerprint()===$before,'configuration checker reads only');$vectors++;
    }
}
foreach ([['ui','NumDec'],['UI ','NumDec'],['UI','NumDec '],['0','UI_NumDec'],['','UI_NumDec'],[null,'UI_NumDec']] as [$module,$prop]) {
    $pdo->exec('DELETE FROM Cfg');$pdo->exec('UPDATE Currs SET cNumDec=0 WHERE cID=1');
    $pdo->prepare('INSERT INTO Cfg (Module,Prop,Val) VALUES (?,?,?)')->execute([$module,$prop,'9']);
    $recognized=$module===null||$module===''||$module==='0';
    $r=(new FinancialReconciler())->run($pdo);financialAssert($r['checks']['currency_scale_storage']['count']===($recognized?1:0),'byte-exact PHP key aliases');$vectors++;
}
$pdo->exec("INSERT INTO Cfg (Module,Prop,Val) VALUES ('UI','NumDec','2')");
$r=(new FinancialReconciler())->run($pdo);financialAssert($r['checks']['currency_scale_storage']['count']===1,'ambiguous logical keys require review even with valid numeric fallback');

// Context capture/replay remains exact across UI drift; secrets never enter export.
foreach (['0','8','PRIVATE_fixture-secret'] as $ui) {
    financialReset();propertyScale(2);$pdo->exec('UPDATE Currs SET cNumDec=0 WHERE cID=1');
    $pdo->prepare("UPDATE Cfg SET Val=? WHERE Module='UI' AND Prop='NumDec'")->execute([$ui]);
    $snapshot=(new FinancialAuditSnapshot())->capture($pdo);$before=propertyFingerprint();
    [$code,$out]=reconciliationCommand(['--snapshot']);
    financialAssert($code===0 && json_decode($out,true,64,JSON_THROW_ON_ERROR)['reconciliation']===$snapshot['reconciliation'],'snapshot exit0 exports findings rather than certifies clean');
    financialAssert(propertyFingerprint()===$before,'least-privilege snapshot changes no table');
    $pdo->exec("UPDATE Cfg SET Val='2' WHERE Module='UI' AND Prop='NumDec'");
    $path=tempnam(sys_get_temp_dir(),'fixture-audit-');file_put_contents($path,json_encode($snapshot,JSON_THROW_ON_ERROR));
    try {
        $p=proc_open([PHP_BINARY,__DIR__.'/financial_snapshot_replay.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,dirname(__DIR__),array_replace(getenv(),['H_SCRIPT_FINANCIAL_SNAPSHOT_FILE'=>$path]));
        financialAssert(is_resource($p)&&proc_close($p)===0,'isolated snapshot restores sanitized inherited context');
        financialAssert((new FinancialReconciler())->run($pdo)===$snapshot['reconciliation'],'snapshot report unchanged across previous UI drift');
        $old=$snapshot;$old['format']='hscript-financial-audit-v1';unset($old['scale_context']);file_put_contents($path,json_encode($old,JSON_THROW_ON_ERROR));$before=propertyFingerprint();
        $p=proc_open([PHP_BINARY,__DIR__.'/financial_snapshot_replay.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,dirname(__DIR__),array_replace(getenv(),['H_SCRIPT_FINANCIAL_SNAPSHOT_FILE'=>$path]));
        financialAssert(is_resource($p)&&proc_close($p)!==0 && propertyFingerprint()===$before,'context-free old snapshot explicitly refused before writes');
    } finally {unlink($path);}
}
financialReset();propertyScale(8);
$before=propertyFingerprint();financialAssert(opChangeBalance(1,1,'0.00000001',0,0)==='bal_precision' && propertyFingerprint()===$before,'valid scale8 cannot truncate unrepresentable SQL6 money');
financialAssert(opChangeBalance(1,1,'1.23000000',0,0)===true,'representable scale8 amount accepted');propertyReconcile(1);
foreach (['--json','--text'] as $mode) {
    $before=propertyFingerprint();[$code,$out]=reconciliationCommand([$mode]);financialAssert($code===2,'findings CLI exit2');
    financialAssert($mode==='--text'?str_contains($out,'balance_history_incomplete: 1'):json_decode($out,true,64,JSON_THROW_ON_ERROR)['finding_count']===1,'exact known audit finding');financialAssert(propertyFingerprint()===$before,'findings CLI read-only');
}
foreach ([['--repair'],['--json','extra']] as $args) {[$code,$out]=reconciliationCommand($args);financialAssert($code===64 && str_starts_with($out,'Usage:'),'invalid CLI args exit64');}
foreach (['--json','--text'] as $mode) {
    $before=propertyFingerprint();[$code,$out]=reconciliationCommand([$mode],['DB_PASSWORD'=>'PRIVATE_fixture-secret']);
    financialAssert($code===1 && str_contains($out,'financial_reconciliation_failed'),'connection error sanitized exit1');financialAssert(propertyFingerprint()===$before,'connection failure changes no table');
}
$pdo->exec('ALTER TABLE Cfg ENGINE=MyISAM');
try {[$code,$out]=reconciliationCommand(['--json']);financialAssert($code===1,'nontransactional configuration fails closed');}
finally {$pdo->exec('ALTER TABLE Cfg ENGINE=InnoDB');}
$pdo->exec('RENAME TABLE Cfg TO fixture_missing_cfg');
try {[$code,$out]=reconciliationCommand(['--text']);financialAssert($code===1,'missing configuration fails closed');}
finally {$pdo->exec('RENAME TABLE fixture_missing_cfg TO Cfg');}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scale_vectors'=>$vectors,'select_only_tables'=>8,'snapshot_format'=>FinancialAuditSnapshot::FORMAT],JSON_THROW_ON_ERROR)."\n";
