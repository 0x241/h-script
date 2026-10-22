<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/vendor/autoload.php';

use HScript\Security\FailedLoginPolicy;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	$checks++;
	if (!$condition) throw new RuntimeException($message);
};
foreach (array(null, '', '  ', 0, '0', ' 0 ') as $value) $assert(FailedLoginPolicy::parse($value) === null, 'Zero or blank value must inherit');
foreach (array(1, 100, '1', '100', ' 7 ') as $value) $assert(FailedLoginPolicy::parse($value) === (int)$value, 'Valid integer boundary');
foreach (array(-1, 101, '-1', '1.0', '1e1', '001', '00', '1x', true, false, array(), 1.0) as $value)
{
	$rejected = false;
	try { FailedLoginPolicy::parse($value); }
	catch (InvalidArgumentException) { $rejected = true; }
	$assert($rejected, 'Invalid personal threshold was accepted');
}
foreach (array(array(0, null, 0), array(3, null, 3), array(0, 2, 2), array(3, 10, 3), array(10, 3, 3), array(3, 3, 3), array(0, 100, 100), array(100, 1, 1)) as [$global, $personal, $effective])
{
	$assert(FailedLoginPolicy::effective($global, $personal) === $effective, 'Effective threshold mismatch');
	$assert(!FailedLoginPolicy::needsConfirmation($global, $personal, max(0, $effective - 1)), 'Premature confirmation');
	$assert(FailedLoginPolicy::needsConfirmation($global, $personal, $effective) === ($effective > 0), 'Boundary confirmation mismatch');
	$assert(FailedLoginPolicy::needsConfirmation($global, $personal, 1000) === ($effective > 0), 'Repeated failure confirmation mismatch');
}
$assert(FailedLoginPolicy::assignment(null) === 'NULL', 'NULL must survive the legacy numeric adapter');
$assert(FailedLoginPolicy::assignment(100) === '100', 'Safe numeric SQL assignment');
echo "Failed login policy passed: $checks checks.\n";
