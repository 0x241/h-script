<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Content\FaqCategories as C;
$pdo->exec('CREATE TABLE IF NOT EXISTS FAQ ('.$_dbstru['FAQ'].') ENGINE=InnoDB');
$pdo->exec('DELETE FROM FAQ');
$category='{!ru!}Платежи{!en!}Payments{!!}';
$first=$db->insert('FAQ',['fCat'=>$category,'fQuestion'=>'Fixture kept','fAnswer'=>'Answer']);
$second=$db->insert('FAQ',['fCat'=>'Removed','fQuestion'=>'Fixture removed','fAnswer'=>'Answer']);
$finance=static function()use($pdo):string{$rows=[];foreach(['Users','Wallets','Opers','Deps','Hist','IdempotencyKeys','PaymentProviders','PaymentProviderCredentials']as$t)$rows[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();return hash('sha256',serialize($rows));};
$before=$finance();
C::save($db,['ShowCount'=>'10','InBlock'=>'3','_Cats'=>$category]);
financialAssert($pdo->query('SELECT fCat FROM FAQ WHERE fID='.$first)->fetchColumn()===$category,'kept raw multilingual category identity');
financialAssert($pdo->query('SELECT fCat FROM FAQ WHERE fID='.$second)->fetchColumn()==='' && $pdo->query('SELECT fAnswer FROM FAQ WHERE fID='.$second)->fetchColumn()==='Answer','removed category clears relation without deleting answer');
C::save($db,['ShowCount'=>'10','InBlock'=>'3','_Cats'=>$category."\nPlain"]);
$plain=$db->insert('FAQ',['fCat'=>'Plain','fQuestion'=>'Category translation edit','fAnswer'=>'Retained answer']);
$translated='{!ru!}Обычная{!en!}Plain{!!}';
C::save($db,['ShowCount'=>'10','InBlock'=>'3','_Cats'=>$category."\n".$translated]);
financialAssert($pdo->query('SELECT fCat FROM FAQ WHERE fID='.$plain)->fetchColumn()===$translated,'adding a category translation preserves existing questions');
C::save($db,['ShowCount'=>'10','InBlock'=>'3','_Cats'=>$category]);
financialAssert($pdo->query('SELECT fCat FROM FAQ WHERE fID='.$plain)->fetchColumn()==='','removing a translated category clears the relation');
$settings=$pdo->query("SELECT * FROM Cfg WHERE Module='FAQ' ORDER BY Prop")->fetchAll();$rows=$pdo->query('SELECT * FROM FAQ ORDER BY fID')->fetchAll();
$pdo->exec("CREATE TRIGGER fixture_faq_failure BEFORE UPDATE ON FAQ FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic FAQ failure'");
try{C::save($db,['ShowCount'=>'20','InBlock'=>'4','_Cats'=>'']);throw new LogicException('Failed update accepted');}catch(PDOException){}
financialAssert($settings===$pdo->query("SELECT * FROM Cfg WHERE Module='FAQ' ORDER BY Prop")->fetchAll() && $rows===$pdo->query('SELECT * FROM FAQ ORDER BY fID')->fetchAll(),'FAQ relation failure rolls back category settings and questions');
$pdo->exec('DROP TRIGGER fixture_faq_failure');
C::save($db,['ShowCount'=>'10','InBlock'=>'3','_Cats'=>'']);
financialAssert((int)$pdo->query("SELECT COUNT(*) FROM FAQ WHERE fCat<>''")->fetchColumn()===0,'removing last category moves every question to General');
financialAssert($finance()===$before,'FAQ changes leave all financial rows untouched');
echo "FAQ settings SQL: $assertions assertions passed\n";
