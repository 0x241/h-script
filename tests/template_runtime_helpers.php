<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

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

foreach (array(
    'balance/index.twig', 'balance/oper.twig', 'depo/index.twig', 'depo/admin/stat.twig',
    'message/show.twig', 'message/admin/message.twig', 'tickets/ticket.twig',
    'tickets/admin/ticket.twig', 'account/admin/user.twig', '_read_receipt.twig',
) as $template) {
    $environment->load($template);
}

$rendered = $environment->createTemplate('{{ _z(12.5, 999, 0) }}')->render();
if ($rendered !== '12.50') {
    throw new RuntimeException('Late-bound _z Twig function rendered incorrectly.');
}

$_currs[1] = array('cNumDec' => 2, 'cCurr' => '<img src=x onerror=alert(1)> USD');
$rendered = $environment->createTemplate('{{ _z(12.5, 1, 1) }}')->render();
if (str_contains($rendered, '<img') || !str_contains($rendered, '&lt;img src=x onerror=alert(1)&gt; USD')) {
    throw new RuntimeException('Amount formatter exposed untrusted currency markup.');
}

$rendered = $environment->createTemplate('{{ content|safe_html }}')->render(array(
    'content' => '<p class="intro">Привет <strong>мир</strong></p><img src="data:image/svg+xml;base64,AA">{{ 7*7 }}',
));
if (!str_contains($rendered, '<strong>мир</strong>') || str_contains($rendered, '<img')
    || !str_contains($rendered, '{{ 7*7 }}')) {
    throw new RuntimeException('Twig rich text filter changed content or exposed executable markup.');
}

$payment = $environment->render('balance/_pform.twig', array(
    'pform' => array('url' => 'https://example.test/pay', 'memo' => '"><script>alert(1)</script>'),
    '_GET' => array(),
    'btn_text' => 'Pay',
));
if (str_contains($payment, '<script>') || !str_contains($payment, '&lt;script&gt;')) {
    throw new RuntimeException('Gateway field value escaped the payment form attribute.');
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
