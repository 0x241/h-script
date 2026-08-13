<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$template = (string)file_get_contents($root . '/tpl/admin/list.block.twig');
$javascript = (string)file_get_contents($root . '/static/js/app.js');

foreach (array('data-hs-select-all', 'data-hs-select-item') as $attribute) {
    if (!str_contains($template, $attribute)) {
        throw new RuntimeException("Admin list template is missing $attribute.");
    }
    if (!str_contains($javascript, $attribute)) {
        throw new RuntimeException("Application JavaScript does not handle $attribute.");
    }
}

foreach (array('target.checked', 'selectAll.indeterminate', "'htmx:afterSwap'") as $behavior) {
    if (!str_contains($javascript, $behavior)) {
        throw new RuntimeException("Admin list selection behavior is missing: $behavior.");
    }
}

if (str_contains($template, 'id="swall"')) {
    throw new RuntimeException('Admin list still uses a page-global select-all ID.');
}

foreach (array(
    'tpl/base.twig',
    'tpl/header.twig',
    'tpl/admin/header.twig',
    'tpl/external/header.twig',
    'tpl/external/auth.header.twig',
) as $layout) {
    $content = (string)file_get_contents($root . '/' . $layout);
    if (!str_contains($content, 'static/js/app.js?v=')) {
        throw new RuntimeException("$layout does not invalidate the cached application JavaScript.");
    }
}

echo "Admin list selection tests passed.\n";
