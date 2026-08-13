<?php

function logoConsistencyAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$logo = file_get_contents($root . '/tpl/external/logo.twig');
foreach (array('M6 3v18', 'M18 3v18', 'M6 12h12', '>Script</span>') as $fragment)
	logoConsistencyAssert(str_contains($logo, $fragment), "Shared logo is missing $fragment");

$sharedLogoConsumers = array(
	'tpl/external/header.twig',
	'tpl/external/footer.twig',
	'tpl/external/auth.logo.twig',
	'tpl/line.top.twig',
);
foreach ($sharedLogoConsumers as $relativeFile)
{
	$content = file_get_contents($root . '/' . $relativeFile);
	logoConsistencyAssert(str_contains($content, 'external/logo.twig'), "$relativeFile does not use the shared logo");
}

$confirm = file_get_contents($root . '/tpl/confirm/index.twig');
logoConsistencyAssert(str_contains($confirm, 'external/auth.logo.twig'), 'Confirmation page does not use the shared authentication logo');

$adminShell = file_get_contents($root . '/tpl/line.top.twig');
logoConsistencyAssert(!str_contains($adminShell, "is_admin_shell %}Admin"), 'Administration still replaces the Script wordmark with Admin');

foreach (array('module/_config/_header.php', 'module/_config/login.php') as $relativeFile)
{
	$content = file_get_contents($root . '/' . $relativeFile);
	logoConsistencyAssert(str_contains($content, 'M6 3v18') && str_contains($content, '>Script</span>'), "$relativeFile uses a different logo");
}

$email = file_get_contents($root . '/src/Mail/EmailTemplate.php');
logoConsistencyAssert(str_contains($email, '>H</td>') && str_contains($email, '>Script</td>'), 'Email template uses a different wordmark');
logoConsistencyAssert(str_contains($email, 'width:40px;height:40px'), 'Email logo mark does not match the web logo dimensions');

echo "Logo consistency tests passed.\n";
