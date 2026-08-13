<?php

function getUploadedFileName($f)
{
	$fn = $_FILES[$f]['tmp_name'];
	if (!$_FILES[$f]['error'] and is_uploaded_file($fn))
		return $fn;
	return false;
}

function imageLoadAny($fn)
{
	if (!is_readable($fn))
		return false;
	$loaders = array('imagecreatefromgif', 'imagecreatefromjpeg', 'imagecreatefrompng');
	foreach ($loaders as $loader)
	{
		if (!function_exists($loader))
			continue;
		$error = null;
		set_error_handler(function ($severity, $message) use (&$error) {
			$error = $message;
			return true;
		});
		try {
			$im = $loader($fn);
		} catch (Throwable $e) {
			$error = $e->getMessage();
			$im = false;
		}
		restore_error_handler();
		if ($im)
			return $im;
	}
	return false;
}

function imageResize($im0, $w2, $h2)
{
	$im2 = imagecreatetruecolor($w2, $h2);
	imagefilledrectangle($im2, 0, 0, $w2, $h2, imagecolorallocate($im2, 255, 255, 255));
	$w0 = imagesx($im0);
	$h0 = imagesy($im0);
	if (($w0 < 10) or ($h0 < 10))
		return $im2;
	$coef = min($w0 / $w2, $h0 / $h2);
	$w1 = round($w2 * $coef);
	$h1 = round($h2 * $coef);
	imagecopyresampled(
		$im2,
		$im0,
		0, 0,
		(int)round(max(0, ($w0 - $w1) / 2)), (int)round(max(0, ($h0 - $h1) / 2)),
		$w2, $h2,
		(int)$w1, (int)$h1
	);
	return $im2;
}

function imageLoad($f)
{
	if ($fn = getUploadedFileName($f))
		if ($im = imageLoadAny($fn))
			return $im;
	return false;
}

?>
