<?php

namespace HScript\Util;

// Text lib

if (function_exists('mb_internal_encoding'))
	mb_internal_encoding("UTF-8");
if (function_exists('mb_regex_encoding'))
	mb_regex_encoding("UTF-8");
/**
 * Contains string, identifier, path, and formatting helpers used by legacy modules.
 */
final class StringHelper
{
public static function sEmpty($s)
{
	return ('' === trim((string)$s));
}

public static function mTrim(&$s)
{
	if (!is_array($s)) 
		$s = trim((string)$s);
	else 
		foreach ($s as $i => $v) 
			self::mTrim($s[$i]);
}

public static function valueIf($cond, $v1, $v2 = '')
{
	return ($cond ? $v1 : $v2);
}

public static function exValue($v1, $v2)
{
	return ($v2 ? $v2 : $v1);
}

public static function firstNotEmpty($a)
{
	if (is_array($a))
		foreach ($a as $s)
			if (!self::sEmpty($s))
				return $s;
	return '';
}

public static function textLen($s)
{
	return strlen((string)$s);
}

public static function textPos($ss, $text, $reg = true)
{
	$reg ? $p = strpos($text, $ss) : $p = stripos($text, $ss);
	if ($p === false) 
		return -1;
	else
		return $p;
}

public static function textRPos($ss, $text, $reg = true)
{
	$reg ? $p = strrpos($text, $ss) : $p = strripos($text, $ss);
	if ($p === false) 
		return -1;
	else
		return $p;
}

public static function textSubStr($txt, $from, $cnt)
{
	return substr($txt, $from, $cnt);
}

public static function textLeft($txt, $cnt = 1)
{
	return substr($txt, 0, $cnt);
}

public static function textRight($txt, $cnt = 1)
{
	return substr($txt, -$cnt);
}

public static function textReplace($in_txt, $old_txt, $new_txt)
{
	return str_replace($old_txt, $new_txt, $in_txt);
//	return ereg_replace($old_txt, $new_txt, $in_txt);
}

public static function textUp($txt)
{
	return strtoupper($txt);
}

public static function textLow($txt)
{
	return strtolower($txt);
}

public static function cutElemL(&$txt, $sep, $reg = true)
{
	$p = self::textPos($sep, $txt, $reg);
	if ($p < 0) 
	{
		$s = $txt;
		$txt = '';
	} 
	else 
	{
		$s = self::textLeft($txt, $p);
		$txt = ltrim(substr($txt, $p + self::textLen($sep)));
	}
	return trim($s);
}

public static function cutElemR(&$txt, $sep, $reg = true)
{
	$p = self::textRPos($sep, $txt, $reg);
	if ($p < 0) 
	{
		$s = $txt;
		$txt = '';
	} 
	else 
	{
		$s = substr($txt, $p + self::textLen($sep));
		$txt = rtrim(self::textLeft($txt, $p));
	}
	return trim($s);
}

public static function get1ElemL($txt, $sep)
{
	$a = explode($sep, $txt, 2);
	return trim(reset($a));
}

public static function textLangFilter($text, $lng = '', $lt = '{!', $rt = '!}', $langs = array())
{
	$lng = self::textLow($lng);
	if ($langs and !in_array($lng, $langs)) 
		$lng = reset($langs);
	$t = '';
	$clng = ''; // current lang
	$nlng = ''; // new lang
	$i = 0;
	$i1 = $i;
	while ($i < self::textLen($text))
	{
		$i2 = strpos($text, $lt, $i);
		if ($i2 !== false)
		{
			if ($i2 === 0 || $text[$i2 - 1] != '/')
			{
				$j = strpos($text, $rt, $i2);
				if ($j !== false)
				{
					$nlng = trim(self::textSubStr($text, $i2 + self::textLen($lt), $j - $i2 - self::textLen($lt)));
					if ($nlng !== '' && $nlng[0] == '/')
						$nlng = '';
					$i = $j + 2;
				} 
				else
				{
					$nlng = '';
					$i = self::textLen($text);
				}
			}
			else
			{
				$i = $i2 + 1;
				continue;
			}
		} 
		else
		{
			$i2 = self::textLen($text);
			$i = $i2;
		}
		if (('' === $clng) or ($clng == $lng))
			$t .= self::textSubStr($text, $i1, $i2 - $i1);
		$clng = $nlng;
		$i1 = $i;
	}
	return $t;
}

public static function textVarReplace($text, $consts)
{
	foreach ($consts as $k => $v)
		$text = preg_replace("~#$k#~", $v, $text);
	return $text;
}

public static function textRandom($text)
{
	$i = 0;
	return self::parseRandom($text, $i);
}

private static function parseRandom($text, &$position)
{
	$value = '';
	for ($i = $position; $i < self::textLen($text); $i++)
		if ($text[$i] == '{')
		{
			$i++;
			$value .= self::parseRandom($text, $i);
		}
		elseif ($text[$i] == '}')
		{
			$position = $i;
			break;
		}
		else
			$value .= $text[$i];
	$values = explode('|', $value);
	return $values[array_rand($values)];
}

public static function toTranslitURL($str)
{
    $converter = array(
        'а' => 'a',   'б' => 'b',   'в' => 'v',
        'г' => 'g',   'д' => 'd',   'е' => 'e',
        'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
        'и' => 'i',   'й' => 'y',   'к' => 'k',
        'л' => 'l',   'м' => 'm',   'н' => 'n',
        'о' => 'o',   'п' => 'p',   'р' => 'r',
        'с' => 's',   'т' => 't',   'у' => 'u',
        'ф' => 'f',   'х' => 'h',   'ц' => 'c',
        'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
        'ь' => '\'',  'ы' => 'y',   'ъ' => '\'',
        'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
        
        'А' => 'A',   'Б' => 'B',   'В' => 'V',
        'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
        'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
        'И' => 'I',   'Й' => 'Y',   'К' => 'K',
        'Л' => 'L',   'М' => 'M',   'Н' => 'N',
        'О' => 'O',   'П' => 'P',   'Р' => 'R',
        'С' => 'S',   'Т' => 'T',   'У' => 'U',
        'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
        'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
        'Ь' => '\'',  'Ы' => 'Y',   'Ъ' => '\'',
        'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
    );
    $str = strtr($str, $converter);
    $str = strtolower($str);
    $str = preg_replace('~[^-a-z0-9_]+~u', '-', $str);
    return trim($str, "-");	
//	return iconv("UTF-8", "ISO-8859-1//TRANSLIT", $str)
}

}

?>
