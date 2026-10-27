<?php

declare(strict_types=1);
namespace HScript\Content;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Util\StringHelper;
use InvalidArgumentException;

/** The raw multilingual label is the stored category identity; no translated keys are saved. */
final class FaqCategories
{
    public static function parse(array|string $value): array
    {
        if(is_array($value)){foreach($value as $line)if(!is_string($line))throw new InvalidArgumentException('Invalid FAQ category');$value=implode("\n",$value);}
        $lines=preg_split('/\R/u',$value);
        if($lines===false || count($lines)>200)throw new InvalidArgumentException('Invalid FAQ categories');
        $result=[];
        foreach($lines as $line){
            if(!is_string($line))throw new InvalidArgumentException('Invalid FAQ category');
            $line=trim($line);if($line==='')continue;
            if(mb_strlen($line,'UTF-8')>100 || preg_match('/[\x00-\x1f\x7f]/',$line))throw new InvalidArgumentException('Invalid FAQ category');
            if(!in_array($line,$result,true))$result[]=$line;
        }
        return $result;
    }
    public static function options(array $categories,string $locale,string $primary): array
    {
        $result=[];
        foreach($categories as $category){
            $label=StringHelper::textLangFilter($category,$locale);
            if(trim($label)==='')$label=StringHelper::textLangFilter($category,$primary);
            $result[$category]=$label;
        }
        return $result;
    }
    public static function save(Connection $db,array $input,string $primary='en'): void
    {
        $values=[];
        foreach(['ShowCount','InBlock'] as $field){
            $n=$input[$field]??null;
            if(!is_string($n) || !preg_match('/^\d{1,3}$/D',$n) || (int)$n>100)throw new InvalidArgumentException('Invalid FAQ count');
            $values[$field]=(string)(int)$n;
        }
        $raw=$input['_Cats']??'';if(!is_array($raw) && !is_string($raw))throw new InvalidArgumentException('Invalid FAQ categories');
        $categories=self::parse($raw);$values['_Cats']=implode("\r\n",$categories);
        TransactionScope::run($db->link,static function() use($db,$values,$categories,$primary):void{
            $pdo=$db->link;
            $old=$pdo->query("SELECT Val FROM Cfg WHERE Module='FAQ' AND Prop='_Cats' FOR UPDATE")->fetchColumn();
            $previous=$old===false?[]:self::parse($old);
            $pdo->exec("DELETE FROM Cfg WHERE Module='FAQ'");
            $insert=$pdo->prepare("INSERT INTO Cfg(Module,Prop,Val) VALUES('FAQ',?,?)");
            foreach($values as $key=>$value)$insert->execute([$key,$value]);
            // Compare exact raw labels, including language markers; removed labels become General.
            $rows=$pdo->query("SELECT fID,fCat FROM FAQ WHERE fCat<>''")->fetchAll(\PDO::FETCH_ASSOC);
            $change=$pdo->prepare('UPDATE FAQ SET fCat=? WHERE fID=?');
            foreach($rows as $row)if(!in_array($row['fCat'],$categories,true)){
                $matches=[];$source=StringHelper::textLangFilter($row['fCat'],$primary);
                // Adding/editing translations keeps questions in the same source-language category.
                if(in_array($row['fCat'],$previous,true) && trim($source)!=='')
                    foreach($categories as $category)if(StringHelper::textLangFilter($category,$primary)===$source)$matches[]=$category;
                $change->execute([count($matches)===1?$matches[0]:'',$row['fID']]);
            }
        });
    }
}
