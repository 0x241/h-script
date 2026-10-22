<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\{CurrencyInput,CalendarInput};
$pdo->exec('CREATE TABLE IF NOT EXISTS Calend ('.$_dbstru['Calend'].') ENGINE=InnoDB');
financialReset();$pdo->exec('DELETE FROM Calend');
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_configuration_cases.json'),true,32,JSON_THROW_ON_ERROR);
function configurationFingerprint(): string {
    global $pdo;
    $all=[];foreach (['Currs','Calend','Plans','Users','AddInfo','Wallets','Deps','Opers','Cfg','Hist','Jobs','IdempotencyKeys'] as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
    }return hash('sha256',serialize($all));
}
foreach ($fixture['valid'] as $c) {
    $input=CurrencyInput::normalize($c['input']);$db->update('Currs',$input,'','cID=1');
    $actual=$db->fetch1Row($db->select('Currs',implode(',',array_keys($c['expected'])),'cID=1'));
    financialAssert($actual===$c['expected'],'Actual signed SQL currency range and precision');
}
foreach ($fixture['invalid'] as $c) {
    $before=configurationFingerprint();
    try { $db->update('Currs',CurrencyInput::normalize($c['input']),'','cID=1');throw new LogicException('Bad input saved'); }
    catch (InvalidArgumentException) { financialAssert(configurationFingerprint()===$before,'Refused currency leaves all financial/configuration/receipt/queue rows untouched'); }
}
foreach ($fixture['calendar'] as $i=>$c) {
    $id=$db->insert('Calend',['cTS'=>20270101000000+$i,'cType'=>1,'cPerc'=>CalendarInput::percentage($c['input'])]);
    financialAssert($db->fetch1($db->select('Calend','cPerc','cID=?d',[$id]))===$c['expected'],'Actual calendar SQL fixed2 stored oracle');
}
foreach (['-0.01','1.001','100000',[]] as $bad) {
    $before=configurationFingerprint();try { $db->insert('Calend',['cPerc'=>CalendarInput::percentage($bad)]);throw new LogicException('Bad calendar stored'); }
    catch (InvalidArgumentException) { financialAssert(configurationFingerprint()===$before,'Calendar refusal leaves all rows untouched'); }
}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'currency_sql_vectors'=>count($fixture['valid']),'calendar_sql_vectors'=>count($fixture['calendar'])],JSON_THROW_ON_ERROR)."\n";
