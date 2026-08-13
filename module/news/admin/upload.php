<?php

$_auth = 90;
$_smode = 1;
require_once('module/auth.php');

header('Content-Type: application/json; charset=utf-8');

function newsUploadResponse(array $payload, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
	newsUploadResponse(array('error' => array('message' => 'Метод запроса не поддерживается.')), 405);

$cert = (string)($_SERVER['HTTP_X_HS_FORM_CERT'] ?? '');
$certValid = false;
foreach ((array)($_SESSION['_cert'] ?? array()) as $sessionCerts)
	foreach ((array)$sessionCerts as $sessionCert)
		if ($cert !== '' && hash_equals((string)$sessionCert, $cert))
		{
			$certValid = true;
			break 2;
		}
if (!$certValid)
	newsUploadResponse(array('error' => array('message' => 'Сессия формы истекла. Обновите страницу.')), 403);

$upload = $_FILES['upload'] ?? null;
if (!$upload || !isset($upload['tmp_name'], $upload['size']) || $upload['error'] !== UPLOAD_ERR_OK)
	newsUploadResponse(array('error' => array('message' => 'Не удалось получить файл.')), 400);
if ($upload['size'] < 1 || $upload['size'] > 10 * 1024 * 1024)
	newsUploadResponse(array('error' => array('message' => 'Допустимый размер изображения: до 10 МБ.')), 400);
if (!is_uploaded_file($upload['tmp_name']))
	newsUploadResponse(array('error' => array('message' => 'Некорректный файл.')), 400);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
$extensions = array(
	'image/jpeg' => 'jpg',
	'image/png' => 'png',
	'image/gif' => 'gif',
	'image/webp' => 'webp'
);
if (!isset($extensions[$mime]))
	newsUploadResponse(array('error' => array('message' => 'Доступны только JPG, PNG, GIF и WebP.')), 400);

$directory = 'upload/news';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
	newsUploadResponse(array('error' => array('message' => 'Не удалось подготовить каталог загрузок.')), 500);

$fileName = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $fileName))
	newsUploadResponse(array('error' => array('message' => 'Не удалось сохранить файл.')), 500);

$rootPath = parse_url((string)$_GS['root_url'], PHP_URL_PATH);
if (!is_string($rootPath) || $rootPath === '')
	$rootPath = '/';
$uploadUrl = rtrim('/' . ltrim($rootPath, '/'), '/') . '/' . $directory . '/' . $fileName;
newsUploadResponse(array('url' => $uploadUrl));

?>
