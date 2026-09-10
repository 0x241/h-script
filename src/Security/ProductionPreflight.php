<?php

namespace HScript\Security;

use RuntimeException;

final class ProductionPreflight
{
	public function __construct(
		private string $projectRoot,
		private bool $https,
		private bool $docker
	) {
		$root = realpath($projectRoot);
		if ($root === false || !is_dir($root)) throw new RuntimeException('Project root could not be resolved');
		$this->projectRoot = rtrim($root, '/');
	}

	public function inspect(): array
	{
		$production = strtolower(trim((string)(getenv('APP_ENV') ?: ''))) === 'production';
		$checks = array();
		$this->add($checks, 'php', 'PHP', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION, array('backup', 'update'));
		foreach (array('json', 'hash', 'pdo', 'pdo_mysql') as $extension)
			$this->add($checks, 'ext_' . $extension, 'PHP: ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'Доступно' : 'Расширение не загружено', array('backup', 'update'));

		$config = $this->projectRoot . '/_config.php';
		$this->add($checks, 'config_readable', '_config.php', is_file($config) && is_readable($config), 'Конфигурация должна читаться приложением', array('backup', 'update'), $config);
		$mode = is_file($config) ? (fileperms($config) & 0777) : 0;
		$configProtected = !$production || (($mode & 0002) === 0 && ($mode & 0004) === 0);
		$this->add($checks, 'config_permissions', 'Права _config.php', $configProtected, $production ? sprintf('Режим %04o; файл не должен читаться или изменяться всеми', $mode) : sprintf('Режим %04o; production-проверка выключена', $mode), array(), $config, 'warning');

		$cfgPath = $this->projectRoot . '/.cfg';
		$backupPath = trim((string)(getenv('BACKUP_STORAGE_PATH') ?: '')) ?: $this->projectRoot . '/backup';
		$updatePath = trim((string)(getenv('UPDATE_WORK_PATH') ?: '')) ?: $cfgPath . '/update';
		$this->directoryCheck($checks, 'runtime', '.cfg runtime', $cfgPath, array('update'));
		$this->directoryCheck($checks, 'uploads', 'Загрузки', $this->projectRoot . '/upload', array(), 'warning');
		$this->directoryCheck($checks, 'backup', 'Хранилище бэкапов', $backupPath, array('backup', 'update'));
		$this->directoryCheck($checks, 'staging', 'Рабочая папка обновления', $updatePath, array('update'));
		if (!$this->docker)
			$this->add($checks, 'code_writable', 'Файлы CMS', is_writable($this->projectRoot), 'Для shared hosting каталог CMS должен допускать атомарную замену файлов', array('update'), $this->projectRoot);
		else
			$this->add($checks, 'code_image', 'Файлы CMS', true, 'Код поставляется неизменяемым Docker-образом', array(), $this->projectRoot);

		$pass = $this->projectRoot . '/module/_config/pass';
		$this->add($checks, 'configurator_password', 'Пароль конфигуратора', is_file($pass) && trim((string)@file_get_contents($pass)) !== '', 'Непустой отдельный пароль обязателен', array('backup', 'update'), $pass);
		$autoInstall = in_array(strtolower(trim((string)(getenv('APP_AUTO_INSTALL') ?: '0'))), array('1', 'true', 'yes', 'on'), true);
		$this->add($checks, 'auto_install', 'APP_AUTO_INSTALL', !$production || !$autoInstall, $autoInstall ? 'Отключите после первичной инициализации' : 'Отключён', $production ? array('backup', 'update') : array(), null, 'warning');
		$debug = in_array(strtolower(trim((string)(getenv('APP_DEBUG') ?: '0'))), array('1', 'true', 'yes', 'on'), true);
		$this->add($checks, 'debug', 'APP_DEBUG', !$production || !$debug, $debug ? 'Диагностический вывод включён' : 'Отключён', array(), null, 'warning');
		$this->add($checks, 'https', 'HTTPS', !$production || $this->https, $this->https ? 'Защищённое соединение определено' : ($production ? 'Production-конфигуратор должен открываться по HTTPS' : 'Локальная среда без HTTPS'), $production ? array('backup', 'update') : array(), null, 'warning');
		$allowlist = trim((string)(getenv('CONFIGURATOR_ALLOWED_CIDRS') ?: ''));
		$this->add($checks, 'cidr', 'CIDR allowlist', $allowlist !== '', $allowlist !== '' ? 'Прямой доступ ограничен сетью оператора' : 'Не задан; доступ ограничивается паролем и reverse proxy', array(), null, 'warning');

		return array(
			'production' => $production,
			'checks' => $checks,
			'blockers' => array_values(array_filter($checks, static fn(array $check): bool => !$check['passed'] && $check['blocks'])),
			'warnings' => array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'warning')),
		);
	}

	public function forOperation(string $operation): array
	{
		return array_values(array_filter(
			$this->inspect()['checks'],
			static fn(array $check): bool => !$check['passed'] && in_array($operation, $check['blocks'], true)
		));
	}

	public function assertAllows(string $operation): void
	{
		$blocked = $this->forOperation($operation);
		if ($blocked)
			throw new RuntimeException('Production preflight failed: ' . implode(', ', array_column($blocked, 'label')));
	}

	private function directoryCheck(array &$checks, string $id, string $label, string $path, array $blocks, string $failure = 'error'): void
	{
		// Docker intentionally exposes persistent directories through top-level
		// symlinks. What matters here is the resolved directory and its rights.
		$exists = is_dir($path);
		$ready = $exists && is_readable($path) && is_writable($path);
		$detail = $ready ? 'Каталог доступен для чтения и записи' : 'Каталог отсутствует или недоступен для чтения/записи';
		if (!$exists && !file_exists($path) && !is_link($path))
		{
			$parent = dirname($path);
			while ($parent !== dirname($parent) && !file_exists($parent)) $parent = dirname($parent);
			if (is_dir($parent) && is_writable($parent))
			{
				$ready = true;
				$detail = 'Каталог будет создан в доступном для записи родительском каталоге';
			}
		}
		$this->add($checks, $id, $label, $ready, $detail, $blocks, $path, $failure);
	}

	private function add(
		array &$checks,
		string $id,
		string $label,
		bool $passed,
		string $detail,
		array $blocks,
		?string $path = null,
		string $failure = 'error'
	): void {
		$checks[] = array(
			'id' => $id,
			'label' => $label,
			'passed' => $passed,
			'status' => $passed ? 'ok' : $failure,
			'detail' => $detail,
			'blocks' => $blocks,
			'path' => $path,
		);
	}
}
