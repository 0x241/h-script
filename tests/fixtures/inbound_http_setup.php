<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('APP_ENV')!=='development'
    || getenv('APP_DOMAIN')!=='fixture.invalid' || getenv('DB_NAME')!=='fixture') exit(64);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$pdo=$db->link; $_cfg=[];
foreach ($pdo->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll() as $row)
    $_cfg[($row['Module']!==''?$row['Module'].'_':'').$row['Prop']]=$row['Val'];
if (($argv[1] ?? '')==='--setup') {
    $now=time();
    $db->insert('Currs',['cID'=>5,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'CPE','cName'=>'Synthetic inbound ETH',
        'cCurrID'=>'ETH','cNumDec'=>2,'cMTS'=>timeToStamp($now),'cCASHINMode'=>2,'cCASHINComis'=>10,
        'cParamsSCI'=>encodeArrayToStr(['id'=>'fixture-inbound-merchant','key'=>'synthetic-inbound-secret'],'5CPE'.$now,2)]);
    $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
    if ($uid!==2) throw new RuntimeException('Synthetic owner mismatch');
    $db->insert('Wallets',['wuID'=>$uid,'wcID'=>5,'wBal'=>0,'wLock'=>0,'wOut'=>0]);
    echo "Synthetic inbound ETH currency with original fee 10 ready.\n"; exit;
}
if (($argv[1] ?? '')==='--policy') {
    $db->update('Currs',['cCASHINComis'=>20,'cCASHINComisMin'=>50,'cCASHINComisMax'=>60,'cCASHINMin'=>200,'cCASHINMax'=>50,'cCASHINMode'=>0],'','cID=?d',[5]);
    echo "Synthetic post-invoice fee/range policy changed; original operation remains gross100/fee10.\n"; exit;
}
if (($argv[1] ?? '')!=='--verify' || !in_array($argv[2] ?? '',['invoice','settled','restart'],true)) exit(64);
$stage=$argv[2]; $settled=$stage!=='invoice';
$rows=$pdo->query('SELECT * FROM Opers WHERE ocID=5 ORDER BY oID')->fetchAll();
if (count($rows)!==1 || $rows[0]['ouID']!==2 || $rows[0]['oOper']!=='CASHIN'
    || $rows[0]['oSum']!=='100.000000' || $rows[0]['oComis']!=='10.000000'
    || ($settled ? $rows[0]['oState']!==3 : $rows[0]['oState']!==0)) throw new RuntimeException('HTTP original inbound operation mismatch');
$o=$rows[0];
$wallet=$pdo->query('SELECT wBal,wLock,wOut FROM Wallets WHERE wuID=2 AND wcID=5')->fetch();
if ($wallet!==['wBal'=>$settled?'90.000000':'0.000000','wLock'=>'0.000000','wOut'=>'0.000000']) throw new RuntimeException('HTTP original fee net mismatch');
$form=HScript\Finance\DepositCheckout::storedForm($o);
$row=$pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt'")->fetchColumn();
$attempt=json_decode($row,true,32,JSON_THROW_ON_ERROR)['data']['contract'];
if ($form['inbound_intent']!==$attempt || $attempt['gross']!=='100.00' || $attempt['commission']!=='10'
    || $attempt['owner_id']!==2 || $attempt['currency_id']!==5 || $attempt['gateway']!=='CPE' || $attempt['asset']!=='ETH'
    || $form['merchant']!=='fixture-inbound-merchant' || $form['amountf']!=='100.00000000') throw new RuntimeException('HTTP protected invoice/durable money proof mismatch');
$receipt=strToArray($o['oParams2']);
if ($settled && (isset($receipt['sum']) || isset($receipt['inbound_intent']))) throw new RuntimeException('HTTP immutable money leaked into editable receipt');
// Legacy history omits hcID/hoID (separately characterized); this isolated
// owner has no other financial effects, so count its BAL events directly.
if ((int)$pdo->query("SELECT COUNT(*) FROM Hist WHERE huID=2 AND hOper='BAL'")->fetchColumn()!==($settled?1:0)) throw new RuntimeException('HTTP duplicate/missing financial credit');
if ((int)$pdo->query("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:cpe:currency:5:%'")->fetchColumn()!==($settled?3:0)) throw new RuntimeException('HTTP callback claim count mismatch');
foreach ((new HScript\Finance\FinancialReconciler())->run($pdo)['checks'] as $name=>$check)
    if ($name!=='balance_history_incomplete' && $check['count']!==0) throw new RuntimeException('HTTP reconciliation failed: '.$name);
$projection=['Users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
foreach (['Opers','Wallets','Hist','IdempotencyKeys','Jobs'] as $table) $projection[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
$hash=hash('sha256',serialize($projection));
if ($stage==='settled') file_put_contents('/tmp/inbound-http-settled.sha256',$hash,LOCK_EX);
if ($stage==='restart' && !hash_equals(trim((string)file_get_contents('/tmp/inbound-http-settled.sha256')),$hash)) throw new RuntimeException('HTTP restart replay changed financial state');
echo "Inbound HTTP original fee, one net90 credit and SQL reconciliation verified ($stage).\n";
