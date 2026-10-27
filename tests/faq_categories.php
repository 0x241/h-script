<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Content\FaqCategories as C;
$assert=function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$ru='{!ru!}Платежи{!en!}Payments{!!}';
$assert(C::parse(" $ru\r\nGeneral\nGeneral\n\n")===[$ru,'General'],'one raw label per line');
$assert(C::parse([$ru."\nGeneral"])===[$ru,'General'],'legacy LF-only array settings hydration');
$assert(C::options([$ru,'General'],'en','ru')===[$ru=>'Payments','General'=>'General'],'localized labels retain raw identities');
$assert(C::options(['{!ru!}Платежи{!!}'],'en','ru')===['{!ru!}Платежи{!!}'=>'Платежи'],'missing translation has readable fallback');
foreach([str_repeat('я',101),"Bad\0category",['nested'=>[]],array_fill(0,201,'x')] as $bad){try{C::parse($bad);throw new LogicException('Invalid category accepted');}catch(InvalidArgumentException){}}
echo "FAQ categories: normalization, multilingual labels and bounds passed\n";
