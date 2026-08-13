<?php

namespace HScript\Mail;

use HScript\Queue\JobQueue;
use HScript\Util\StringHelper;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Sends mail immediately or dispatches it through the database job queue.
 */
final class Mailer
{
	private static string $lastError = '';

	public static function lastError(): string
	{
		return self::$lastError;
	}

	public static function send(
		string $to,
		string $subject,
		string $message,
		string $from = '',
		string $fromName = ''
	): bool {
		global $jobQueue;

		self::$lastError = '';
		if (!\validMail($to) || $message === '')
			return self::fail('Invalid email recipient or empty message');
		if (!($jobQueue instanceof JobQueue))
			return self::fail('Email queue is not initialized');
		try
		{
			return $jobQueue->dispatch('email', array(
				'to' => $to,
				'subject' => $subject,
				'message' => $message,
				'from' => $from,
				'from_name' => $fromName,
			)) > 0;
		}
		catch (Throwable $e)
		{
			return self::fail('Unable to queue email: ' . $e->getMessage());
		}
	}

	public static function sendNow(
		string $to,
		string $subject,
		string $message,
		string $from = '',
		string $fromName = ''
	): bool {
		global $_GS, $_cfg;

		self::$lastError = '';
		if (!\validMail($to) || $message === '')
			return self::fail('Invalid email recipient or empty message');

		$from = self::normalizeFrom($from);
		if ($from === '')
			return self::fail('Sender address is not configured or invalid');
		if ($fromName === '')
			$fromName = self::siteName();

		$host = trim((string)($_cfg['Mail_Host'] ?? ''));
		if ($host === '')
			return self::sendNative($to, $subject, $message, $from, $fromName);

		$port = (int)($_cfg['Mail_Port'] ?? 0);
		$port = $port > 0 ? $port : 25;
		$username = trim((string)($_cfg['Mail_Username'] ?? ''));
		$password = (string)($_cfg['Mail_Password'] ?? '');
		if (($username === '') !== ($password === ''))
			return self::fail('SMTP username and password must either both be configured or both be empty');

		try
		{
			$mail = new PHPMailer(true);
			$mail->isSMTP();
			$mail->SMTPAuth = $username !== '';
			$mail->SMTPSecure = self::smtpEncryption($_cfg['Mail_Secure'] ?? '', $port);
			$mail->SMTPAutoTLS = $mail->SMTPSecure !== '';
			$mail->Host = $host;
			$mail->Port = $port;
			$mail->Timeout = 15;
			$mail->Username = $username;
			$mail->Password = $password;
			$mail->setFrom($from, $fromName);
			$mail->Subject = $subject;
			$mail->msgHTML(self::styledMessage($subject, $message));
			$mail->addReplyTo($from, $fromName);
			$mail->addAddress($to);
			$mail->isHTML(true);
			$mail->CharSet = 'utf-8';
			$mail->send();
			return true;
		}
		catch (Throwable $e)
		{
			$error = isset($mail) && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
			return self::fail('SMTP delivery failed: ' . $error);
		}
	}

	public static function sendNative(
		string $to,
		string $subject,
		string $message,
		string $from = '',
		string $fromName = ''
	): bool {
		global $_GS;

		self::$lastError = '';
		if (!\validMail($to) || $message === '')
			return self::fail('Invalid email recipient or empty message');

		$from = self::normalizeFrom($from);
		if ($from === '')
			return self::fail('Sender address is not configured or invalid');
		if ($fromName === '')
			$fromName = self::siteName();

		$sendmailPath = trim((string)ini_get('sendmail_path'));
		$sendmailBinary = preg_split('/\s+/', $sendmailPath)[0] ?? '';
		if (DIRECTORY_SEPARATOR === '/' && str_starts_with($sendmailBinary, '/') && !is_executable($sendmailBinary))
			return self::fail('Native PHP mail transport is unavailable: ' . $sendmailBinary . ' does not exist; configure SMTP in the mail settings');

		$sender = $fromName !== '' ? self::encodeHeader($fromName) . " <$from>" : $from;
		$nativeError = '';
		set_error_handler(static function (int $severity, string $error) use (&$nativeError): bool
		{
			$nativeError = $error;
			return true;
		});
		try
		{
			$result = mail(
				$to,
				self::encodeHeader($subject),
				self::styledMessage($subject, $message),
				'Content-type: text/html; charset="utf-8"' . HS2_NL
					. "From: $sender" . HS2_NL
					. 'MIME-Version: 1.0' . HS2_NL,
				'-f' . $from
			);
		}
		catch (Throwable $e)
		{
			$nativeError = $e->getMessage();
			$result = false;
		}
		finally
		{
			restore_error_handler();
		}
		if (!$result)
			return self::fail('Native PHP mail delivery failed' . ($nativeError !== '' ? ': ' . $nativeError : ''));
		return true;
	}

	private static function styledMessage(string $subject, string $message): string
	{
		global $_GS;

		return EmailTemplate::render(
			$subject,
			$message,
			self::siteName(),
			(string)($_GS['root_url'] ?? '')
		);
	}

	private static function siteName(): string
	{
		global $_GS, $_cfg;

		return trim((string)($_cfg['Sys_SiteName'] ?? $_GS['site_name'] ?? '')) ?: 'H-Script';
	}

	private static function smtpEncryption($secure, int $port): string
	{
		return (string)StringHelper::valueIf(
			$secure,
			$port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS,
			''
		);
	}

	private static function fail(string $message): bool
	{
		$message = trim(preg_replace('/\s+/', ' ', $message));
		self::$lastError = mb_substr($message, 0, 1000, 'UTF-8');
		error_log('[mail] ' . self::$lastError);
		return false;
	}

	private static function encodeHeader(string $value): string
	{
		return '=?UTF-8?B?' . base64_encode($value) . '?=';
	}

	private static function normalizeFrom(string $from): string
	{
		global $_GS;

		if ($from === '')
			$from = 'support';
		if (!\validMail($from))
			$from .= '@' . ($_GS['domain'] ?? '');
		return \validMail($from) ? $from : '';
	}
}
