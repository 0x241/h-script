<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Update\UpdateStatusService;

$ready = array('framework_ready' => true, 'application_version' => '1.0.5',
    'installed_application_version' => '1.0.5', 'installed_schema_version' => '1.0.2',
    'target_schema_version' => '1.0.2', 'schema_gate' => null,
    'latest_run' => array('urState' => 'completed'));
if (!UpdateStatusService::isReconciled($ready)) throw new RuntimeException('Completed installation is not ready');
foreach (array(
    array('installed_application_version' => '1.0.4', 'installed_schema_version' => '1.0.1'),
    array('installed_application_version' => '1.0.4'),
    array('installed_schema_version' => '1.0.1'),
    array('installed_application_version' => null),
    array('schema_gate' => array('reason' => 'schema_update_required')),
    array('latest_run' => array('urState' => 'health')),
    array('latest_run' => array('urState' => 'failed')),
    array('framework_ready' => false),
) as $drift) {
    if (UpdateStatusService::isReconciled(array_replace($ready, $drift))) throw new RuntimeException('Unfinished update displayed as ready');
}
if (!UpdateStatusService::isReconciled(array_replace($ready, array('latest_run' => null)))) throw new RuntimeException('Fresh install is not ready');
echo "Configurator lifecycle tests passed.\n";
