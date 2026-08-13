<?php

$root = dirname(__DIR__);
$variables = array(
	'INSTALL_MAIL_HOST',
	'INSTALL_MAIL_PORT',
	'INSTALL_MAIL_SECURE',
	'INSTALL_MAIL_USERNAME',
	'INSTALL_MAIL_PASSWORD',
	'INSTALL_MAIL_FROM_ADDRESS',
	'INSTALL_MAIL_ADMIN_ADDRESS',
	'INSTALL_MAIL_ADMIN_LANG',
);
$files = array(
	'docker/runtime/install-db.php',
	'docker-compose.yml',
	'docker/env.example',
	'.gitlab-ci.yml',
	'README.md',
);

foreach ($files as $relativeFile)
{
	$content = (string)file_get_contents($root . '/' . $relativeFile);
	foreach ($variables as $variable)
		if (!str_contains($content, $variable))
			throw new RuntimeException("$variable is missing from $relativeFile");
}

foreach (array('docker-compose.yml', 'docker/env.example', 'README.md') as $relativeFile)
{
	$content = (string)file_get_contents($root . '/' . $relativeFile);
	if (!str_contains($content, 'INSTALL_MAIL_PASSWORD_FILE'))
		throw new RuntimeException("INSTALL_MAIL_PASSWORD_FILE is missing from $relativeFile");
}

$installerSource = (string)file_get_contents($root . '/docker/runtime/install-db.php');
if (!str_contains($installerSource, "getenv(\$name . '_FILE')"))
	throw new RuntimeException('Installer does not resolve Docker-style secret files');

foreach (array('docker-compose.yml', 'docker/env.example', '.gitlab-ci.yml', 'README.md') as $relativeFile)
{
	$content = (string)file_get_contents($root . '/' . $relativeFile);
	if (!str_contains($content, 'INSTALL_MAIL_PASSWORD_B64'))
		throw new RuntimeException("INSTALL_MAIL_PASSWORD_B64 transport is missing from $relativeFile");
}
if (!str_contains($installerSource, "\$name . '_B64'"))
	throw new RuntimeException('Installer does not decode the base64 secret transport');

$installer = $installerSource;
foreach (array("'Mail' => array(", "'NotifyMail' =>", "'AdminMail' =>", "'AdminLang' =>") as $mapping)
	if (!str_contains($installer, $mapping))
		throw new RuntimeException("Installer mail mapping is missing: $mapping");

echo "Install mail configuration tests passed.\n";
