<?php

declare(strict_types=1);

// Mutations are limited to the disposable database created by seo_isolated.sh.
if (getenv('H_SCRIPT_SEO_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') {
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'), 'fixture', getenv('DB_USER'), getenv('DB_PASSWORD'))) {
    exit(1);
}
$locales = $argv[1] ?? 'en,fr';
$count = (int)($argv[2] ?? 0);
$httpsOnly = (int)($argv[3] ?? 0);
if (!in_array($httpsOnly, [0, 1], true) || !in_array($locales, ['en,fr', 'fr,en'], true) || !in_array($count, [0, 17], true)) {
    exit(2);
}
foreach (['UI' => ['_Langs' => str_replace(',', "\n", $locales)], 'Sys' => ['NeedReConfig' => 0], 'Sec' => ['HTTPSMode' => $httpsOnly], 'News' => ['ShowCount' => 3], 'FAQ' => ['ShowCount' => 3]] as $module => $settings) {
    foreach ($settings as $property => $value) {
        $db->replace('Cfg', ['Module' => $module, 'Prop' => $property, 'Val' => $value]);
    }
}
$db->query('DELETE FROM News');
for ($i = 0; $i < $count; $i++) {
    $db->insert('News', [
        'nID' => 7001 + $i * 13,
        'nTopic' => '{!en!}Article ' . $i . '{!fr!}Actualité ' . $i . '{!!}',
        'nAnnounce' => '',
        'nText' => '{!en!}<p>English article ' . $i . '</p>{!fr!}<p>Article français ' . $i . '</p>{!!}',
        'nTS' => '20260901000000', 'nDBegin' => 0, 'nDEnd' => 0, 'nAttn' => 0,
    ]);
}
echo "Disposable SEO fixture: $locales, $count articles.\n";
