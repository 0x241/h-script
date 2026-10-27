<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;

/** Explicit activation intent, separate from retained hidden membership. */
final class CurrencySelection
{
    /** Stable presentation order only; never an activation or archive decision. */
    public static function selectedFirst(array $choices,array $selectedKeys): array
    {
        $selected=[];$other=[];
        foreach($choices as$key=>$choice){if(in_array($key,$selectedKeys,true))$selected[$key]=$choice;else $other[$key]=$choice;}
        return $selected+$other;
    }
    public static function scope(array $input,array $known,?array $expected=null): array
    {
        $scope=$input['activation_scope']??[];
        if(!is_array($scope)||!array_is_list($scope)||count($scope)>count($known)||count(array_unique($scope,SORT_REGULAR))!==count($scope))throw new ProviderException('input_invalid');
        foreach($scope as$key)if(!is_string($key)||!in_array($key,$known,true))throw new ProviderException('input_invalid');
        if($expected!==null){$a=$scope;$b=$expected;sort($a,SORT_STRING);sort($b,SORT_STRING);if($a!==$b)throw new ProviderException('input_invalid');}
        return $scope;
    }
    public static function activate(Connection $db,array $row,callable $validate,bool $reveal=false): void
    {
        if(!$db->link->inTransaction())throw new \LogicException('Activation requires a transaction');
        if(($row['cArchivedAt']??null)!==null)throw new ProviderException('configuration_changed');
        if((int)$row['cDisabled']===0)return;
        $validate($row);
        $q=$db->link->prepare('UPDATE Currs SET cDisabled=0'.($reveal?',cHidden=0':'').' WHERE cID=?');$q->execute([$row['cID']]);
    }
}
