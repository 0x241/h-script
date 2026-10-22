<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Only the ordered updater invokes this resumable, backup-required DDL. */
final class PaymentProviderSchema
{
    public static function ensure(Connection $database, ?bool $shared=false, ?string $currencyCheck=null): void
    {
        $pdo=$database->link;
        if (str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB') && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)
            throw new RuntimeException('Provider CHECK constraints must be enforced');
        $definitions=require dirname(__DIR__,3).'/resources/payment-provider-schema.php';
        foreach ($definitions as $table=>$sql) $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$sql.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        foreach (array_merge(array_keys($definitions),['Currs']) as $table) {
            $s=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);
            if ($s->fetchColumn()!=='InnoDB') throw new RuntimeException('Incompatible provider storage engine: '.$table);
        }
        $columns=self::columns($pdo,'Currs');
        foreach (['cProviderID'=>'int unsigned NULL DEFAULT NULL','cProviderSelected'=>'tinyint unsigned NOT NULL DEFAULT 0'] as $name=>$ddl)
            if (!isset($columns[$name])) $pdo->exec('ALTER TABLE Currs ADD COLUMN '.$name.' '.$ddl);
        self::verifyColumns($pdo,'PaymentProviders',[
            'ppID'=>['int unsigned','NO'], 'ppPublicID'=>['char(32)','NO','ascii_bin'],
            'ppKind'=>['varchar(8)','NO','ascii_bin'], 'ppSlot'=>['varchar(8)','YES','ascii_bin'],
            'ppEnvironment'=>['varchar(8)','NO','ascii_bin'], 'ppState'=>['varchar(8)','NO','ascii_bin'],
            'ppRevision'=>['int unsigned','NO'], 'ppCurrentKeyVersion'=>['int unsigned','NO'],
            'ppValidationState'=>['varchar(8)','NO','ascii_bin'], 'ppValidatedAt'=>['bigint unsigned','YES'],
            'ppCreatedAt'=>['bigint unsigned','NO'], 'ppUpdatedAt'=>['bigint unsigned','NO']
        ]);
        self::verifyColumns($pdo,'PaymentProviderCredentials',[
            'ppcProviderID'=>['int unsigned','NO'], 'ppcVersion'=>['int unsigned','NO'],
            'ppcEnvironment'=>['varchar(8)','NO','ascii_bin'], 'ppcEncryptedKey'=>['text','NO','ascii_bin'],
            'ppcCreatedAt'=>['bigint unsigned','NO'], 'ppcRetiredAt'=>['bigint unsigned','YES']
        ]);
        self::verifyColumns($pdo,'Currs',['cProviderID'=>['int unsigned','YES'],'cProviderSelected'=>['tinyint unsigned','NO']],false);
        if ((self::columns($pdo,'PaymentProviders')['ppID']['Extra']??'')!=='auto_increment') throw new RuntimeException('Incompatible provider identity');
        foreach ([
            ['PaymentProviders','PRIMARY',['ppID']], ['PaymentProviders','uq_provider_public',['ppPublicID']],
            ['PaymentProviders','uq_provider_slot',['ppSlot']], ['PaymentProviders','uq_provider_environment',['ppID','ppEnvironment']],
            ['PaymentProviderCredentials','PRIMARY',['ppcProviderID','ppcVersion']]
        ] as [$table,$name,$cols]) self::unique($pdo,$table,$name,$cols,false);
        self::unique($pdo,'Currs','uq_currency_provider_fiat',['cProviderID',isset(self::columns($pdo,'Currs')['cAssetKey'])?'cAssetKey':'cCurrID'],true);
        self::foreign($pdo,'PaymentProviderCredentials','fk_credential_provider',['ppcProviderID','ppcEnvironment'],'PaymentProviders',['ppID','ppEnvironment'],false);
        self::foreign($pdo,'Currs','fk_currency_provider',['cProviderID'],'PaymentProviders',['ppID'],true);
        foreach ([
            'PaymentProviders'=>[
                'chk_provider_kind'=>$shared ? "ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE')" : "ppKind='PAW'",
                'chk_provider_environment'=>"ppEnvironment IN ('live','sandbox')",
                'chk_provider_state'=>"ppState IN ('enabled','disabled','retired')",
                'chk_provider_slot'=>$shared ? "(ppState='retired' AND ppSlot IS NULL) OR (ppState<>'retired' AND ppSlot IS NOT NULL AND ppSlot=ppKind)" : "(ppState='retired' AND ppSlot IS NULL) OR (ppState<>'retired' AND ppSlot IS NOT NULL AND ppSlot='PAW')",
                'chk_provider_validation'=>"ppValidationState IN ('none','valid')"
            ],
            'PaymentProviderCredentials'=>['chk_credential_version'=>'ppcVersion>0'],
            'Currs'=>[
                'chk_currency_provider'=>$shared ? ($currencyCheck ?? ProviderConnectionSchema::CURRENCY_CHECK) : "(cProviderID IS NULL AND cProviderSelected=0 AND (cCID IS NULL OR cCID<>'PAW')) OR (cProviderID IS NOT NULL AND cCID IS NOT NULL AND cCID='PAW' AND cProviderSelected IN (0,1))",
                // A later financial-runtime migration must explicitly lift this guard.
                'chk_paw_configuration_only'=>($shared ? "cCID<>'PAW'" : 'cProviderID IS NULL').' OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0)'
            ]
        ] as $table=>$checks) foreach ($checks as $name=>$clause) {
            if ($shared===null && in_array($name,['chk_provider_kind','chk_provider_slot','chk_currency_provider','chk_paw_configuration_only'],true)) continue;
            self::check($pdo,$table,$name,$clause,$table==='Currs');
        }
    }
    private static function columns(PDO $pdo,string $table): array { return $pdo->query('SHOW FULL COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC); }
    private static function verifyColumns(PDO $pdo,string $table,array $expected,bool $exact=true): void
    {
        $columns=self::columns($pdo,$table);
        if ($exact && count($columns)!==count($expected)) throw new RuntimeException('Incompatible provider columns: '.$table);
        foreach ($expected as $name=>$spec) {
            $c=$columns[$name]??[];$type=preg_replace('/^(bigint|int|tinyint)\([0-9]+\)/','$1',strtolower($c['Type']??''));
            if ($type!==$spec[0] || ($c['Null']??'')!==$spec[1] || (isset($spec[2]) && ($c['Collation']??'')!==$spec[2]))
                throw new RuntimeException('Incompatible provider column: '.$name);
        }
        $defaults=match ($table) {
            'PaymentProviders'=>['ppSlot'=>null,'ppState'=>'disabled','ppRevision'=>'0','ppCurrentKeyVersion'=>'0','ppValidationState'=>'none','ppValidatedAt'=>null],
            'PaymentProviderCredentials'=>['ppcRetiredAt'=>null],
            'Currs'=>['cProviderID'=>null,'cProviderSelected'=>'0'],
            default=>[]
        };
        foreach ($defaults as $name=>$expectedDefault) {
            $default=$columns[$name]['Default'];
            // MariaDB SHOW COLUMNS quotes string literals and emits SQL NULL.
            if ($default==='NULL') $default=null;
            elseif (is_string($default) && str_starts_with($default,"'") && str_ends_with($default,"'")) $default=substr($default,1,-1);
            if ($default!==null) $default=(string)$default;
            if ($default!==$expectedDefault || ($columns[$name]['Extra']??'')!=='') throw new RuntimeException('Incompatible provider default: '.$name);
        }
    }
    public static function unique(PDO $pdo,string $table,string $name,array $columns,bool $add): void
    {
        $indexes=array_values(array_filter($pdo->query('SHOW INDEX FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),static fn($r)=>$r['Key_name']===$name));
        if (!$indexes && $add) { $pdo->exec('ALTER TABLE '.$table.' ADD UNIQUE KEY '.$name.' ('.implode(',',$columns).')');return; }
        usort($indexes,static fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
        if (array_column($indexes,'Column_name')!==$columns || array_sum(array_column($indexes,'Non_unique'))!==0) throw new RuntimeException('Incompatible provider uniqueness: '.$name);
        foreach ($indexes as $index) if ($index['Sub_part']!==null) throw new RuntimeException('Provider uniqueness cannot be prefixed');
    }
    public static function foreign(PDO $pdo,string $table,string $name,array $columns,string $target,array $refs,bool $add): void
    {
        $s=$pdo->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION');$s->execute([$table,$name]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows && $add) { $pdo->exec('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' FOREIGN KEY ('.implode(',',$columns).') REFERENCES '.$target.' ('.implode(',',$refs).') ON DELETE RESTRICT ON UPDATE RESTRICT');return; }
        if (array_column($rows,'COLUMN_NAME')!==$columns || array_column($rows,'REFERENCED_COLUMN_NAME')!==$refs) throw new RuntimeException('Incompatible provider reference: '.$name);
        $schema=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach ($rows as $r) if ($r['REFERENCED_TABLE_SCHEMA']!==$schema || $r['REFERENCED_TABLE_NAME']!==$target || $r['DELETE_RULE']!=='RESTRICT' || $r['UPDATE_RULE']!=='RESTRICT') throw new RuntimeException('Provider history must use restricted references');
    }
    public static function check(PDO $pdo,string $table,string $name,string $clause,bool $add): void
    {
        $s=$pdo->prepare('SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_NAME=?');$s->execute([$table,$name]);$actual=$s->fetchColumn();
        if ($actual===false && $add) { $pdo->exec('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' CHECK ('.$clause.')');$actual=$clause; }
        $hasEnforced=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND TABLE_NAME='TABLE_CONSTRAINTS' AND COLUMN_NAME='ENFORCED'")->fetchColumn()>0;
        if ($hasEnforced) {
            $s=$pdo->prepare('SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$s->execute([$table,$name]);
            if ($s->fetchColumn()!=='YES') throw new RuntimeException('Provider CHECK must be enforced: '.$name);
        }
        if ($actual===false || self::expression($actual)!==self::expression($clause)) throw new RuntimeException('Incompatible provider check: '.$name);
    }

    /** Preserve AND/OR grouping and literal case; engines add redundant parentheses. */
    private static function expression(string $sql): array|string
    {
        $sql=preg_replace("/_[a-z][a-z0-9]*(?=')/i",'',str_replace("\\'","'",$sql));
        preg_match_all("/'(?:[^'\\\\]|\\\\.|'')*'|[a-zA-Z_][a-zA-Z0-9_]*|<>|!=|<=|>=|[0-9]+|[^\\s`]/",$sql,$matches);
        $tokens=array_map(static fn(string $t): string=>str_starts_with($t,"'")?$t:($t==='!='?'<>':strtolower($t)),$matches[0]);
        return self::group($tokens);
    }
    private static function group(array $tokens): array|string
    {
        while (($tokens[0]??null)==='(' && end($tokens)===')') {
            $depth=0;$whole=true;$last=count($tokens)-1;
            foreach ($tokens as $i=>$t) {
                if ($t==='(') $depth++;elseif ($t===')') $depth--;
                if ($depth===0 && $i<$last) { $whole=false;break; }
            }
            if (!$whole) break;
            $tokens=array_slice($tokens,1,-1);
        }
        foreach (['or','and'] as $operator) {
            $parts=[];$start=0;$depth=0;
            foreach ($tokens as $i=>$t) {
                if ($t==='(') $depth++;elseif ($t===')') $depth--;
                elseif ($depth===0 && $t===$operator) { $parts[]=array_slice($tokens,$start,$i-$start);$start=$i+1; }
            }
            if ($parts) {
                $parts[]=array_slice($tokens,$start);
                return [$operator,array_map(self::group(...),$parts)];
            }
        }
        // Atoms may contain only redundant operand/IN-list parentheses here.
        return implode('',array_filter($tokens,static fn($t)=>$t!=='(' && $t!==')'));
    }
}
