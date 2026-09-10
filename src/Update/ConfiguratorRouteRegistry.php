<?php

namespace HScript\Update;

use InvalidArgumentException;

final class ConfiguratorRouteRegistry
{
	public const PUBLIC_AUTH = 'public-authentication';
	public const INITIAL_INSTALL = 'initial-install';
	public const AUTHENTICATED_MAINTENANCE = 'authenticated-maintenance';
	public const READ_ONLY_DIAGNOSTICS = 'read-only-diagnostics';
	public const CONDITIONAL_BOOTSTRAP = 'conditional-bootstrap';

	private const ROUTES = array(
		'login' => array(
			'access' => self::PUBLIC_AUTH,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'pass' => array(
			'access' => self::CONDITIONAL_BOOTSTRAP,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'setup' => array(
			'access' => self::AUTHENTICATED_MAINTENANCE,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'install' => array(
			'access' => self::INITIAL_INSTALL,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'modules' => array(
			'access' => self::READ_ONLY_DIAGNOSTICS,
			'methods' => array('GET'),
			'mutates_server_state' => false,
		),
		'update' => array(
			'access' => self::AUTHENTICATED_MAINTENANCE,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'backup' => array(
			'access' => self::AUTHENTICATED_MAINTENANCE,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
		'security' => array(
			'access' => self::AUTHENTICATED_MAINTENANCE,
			'methods' => array('GET', 'POST'),
			'mutates_server_state' => true,
		),
	);

	public static function keys(): array
	{
		return array_keys(self::ROUTES);
	}

	public static function all(): array
	{
		return self::ROUTES;
	}

	public static function get(string $route): array
	{
		if (!isset(self::ROUTES[$route]))
			throw new InvalidArgumentException('Unknown Configurator route');
		return self::ROUTES[$route];
	}
}
