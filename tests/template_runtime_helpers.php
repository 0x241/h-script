<?php

declare(strict_types=1);

use HScript\Template\View;

require_once dirname(__DIR__) . '/vendor/autoload.php';

global $_cfg, $_currs, $_GS;
$_cfg['UI_NumDec'] = 2;
$_currs = array();
$_GS['lang'] = 'en';
$_GS['lang_dir'] = '';
$_GS['mode'] = '';

// Match production order: Twig starts before module/udf.php defines _z.
View::initialize();
require_once dirname(__DIR__) . '/module/udf.php';
View::tplRegisterLegacyPlugins();

$reflection = new ReflectionClass(View::class);
$property = $reflection->getProperty('environment');
$property->setAccessible(true);
$environment = $property->getValue();
$environment->setCache(false);
if ($environment->getFunction('_z') === null) {
    throw new RuntimeException('Late-bound _z Twig function was not registered.');
}

foreach (array('balance/index.twig', 'depo/index.twig', 'depo/admin/stat.twig') as $template) {
    $environment->load($template);
}

$rendered = $environment->createTemplate('{{ _z(12.5, 999, 0) }}')->render();
if ($rendered !== '12.50') {
    throw new RuntimeException('Late-bound _z Twig function rendered incorrectly.');
}

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
try {
    $first = $environment->createTemplate('{{ reset(values) }}')->render(array(
        'values' => array('first', 'second'),
    ));
} finally {
    restore_error_handler();
}
if ($first !== 'first') {
    throw new RuntimeException('Twig reset compatibility wrapper failed.');
}

echo "Template runtime helper tests passed.\n";
