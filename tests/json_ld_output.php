<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$header = (string)file_get_contents(dirname(__DIR__) . '/tpl/external/header.twig');
if (substr_count($header, '|json_encode(15)|raw') !== 2) {
    throw new RuntimeException('Both JSON-LD outputs must keep the four JSON_HEX flags.');
}

$twig = new Environment(new ArrayLoader(array(
    'json' => '<script type="application/ld+json">{{ value|json_encode(15)|raw }}</script>',
)), array('autoescape' => 'html'));
$payload = array('name' => '</script><script>alert("xss")</script> & \' "');
$rendered = $twig->render('json', array('value' => $payload));
if (substr_count($rendered, '</script>') !== 1 || str_contains($rendered, '<script>alert')) {
    throw new RuntimeException('JSON-LD payload escaped its script element.');
}
if (!str_contains($rendered, '\\u003C')
    || !str_contains($rendered, '\\u003E')
    || !str_contains($rendered, '\\u0026')) {
    throw new RuntimeException('JSON-LD output is missing required HTML-safe JSON escapes.');
}
$json = substr($rendered, strpos($rendered, '>') + 1, -strlen('</script>'));
if (json_decode($json, true, 512, JSON_THROW_ON_ERROR) !== $payload) {
    throw new RuntimeException('JSON-LD escaping changed the payload.');
}

echo "JSON-LD output tests passed.\n";
