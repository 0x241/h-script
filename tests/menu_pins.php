<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Navigation\{MenuPins,MenuPinsSchema};
financialReset();
MenuPinsSchema::ensure($db);
$allowed=['operations','wallets','account'];
financialAssert(MenuPins::get($db,1,'cabinet',$allowed)===[],'new account empty');
financialAssert(MenuPins::change($db,1,'cabinet',$allowed,'pin','operations')===['operations'],'pin');
financialAssert(MenuPins::change($db,1,'cabinet',$allowed,'pin','wallets')===['wallets','operations'],'order');
financialAssert(MenuPins::change($db,1,'cabinet',$allowed,'pin','wallets')===['wallets','operations'],'no duplicate');
financialAssert(MenuPins::get($db,2,'cabinet',$allowed)===[],'owner isolation');
MenuPins::change($db,1,'admin',['balance/admin/currs'],'pin','balance/admin/currs');
financialAssert(MenuPins::get($db,1,'cabinet',$allowed)===['wallets','operations'],'scope isolation');
financialAssert(MenuPins::change($db,1,'cabinet',$allowed,'pin','forbidden')===['wallets','operations'],'allowlist');
financialAssert(MenuPins::change($db,1,'cabinet',$allowed,'unpin','wallets')===['operations'],'unpin');
financialAssert(MenuPins::get($db,1,'cabinet',$allowed,['wallets'])===['operations'],'legacy does not overwrite');
financialAssert(MenuPins::get($db,2,'cabinet',$allowed,['wallets','forbidden','wallets'])===['wallets'],'one-time legacy filtered import');
$pdo->beginTransaction();MenuPins::change($db,1,'cabinet',$allowed,'pin','account');$pdo->rollBack();
financialAssert(MenuPins::get($db,1,'cabinet',$allowed)===['operations'],'caller rollback');
$db->update('AddInfo',['aMenuPins'=>'{"cabinet":[null,{},"operations","forbidden","operations"]}'],'','auID=?d',[3]);
financialAssert(MenuPins::get($db,3,'cabinet',$allowed)===['operations'],'malformed entries filtered');
$before=$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll();
MenuPins::change($db,3,'cabinet',$allowed,'pin','account');
financialAssert($before===$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll(),'money unchanged');
$pdo->exec('ALTER TABLE AddInfo DROP COLUMN aMenuPins');MenuPinsSchema::ensure($db);MenuPinsSchema::ensure($db);
financialAssert(MenuPins::get($db,1,'cabinet',$allowed)===[],'explicit additive migration resumable');
$pdo->exec('ALTER TABLE AddInfo MODIFY aMenuPins varchar(100) NULL');
try {MenuPinsSchema::ensure($db);throw new LogicException('schema should refuse');} catch(RuntimeException $e) {financialAssert(str_contains($e->getMessage(),'incompatible'),'wrong shape refused');}
echo "Menu pin SQL checks passed ($assertions assertions).\n";
