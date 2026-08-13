<?php

namespace HScript\Mail;

/**
 * Builds the email-safe H-Script frame used by every outbound HTML message.
 */
final class EmailTemplate
{
	public static function render(
		string $subject,
		string $message,
		string $siteName = 'H-Script',
		string $rootUrl = ''
	): string {
		$subject = self::escape(trim($subject));
		$siteName = self::escape(trim($siteName) ?: 'H-Script');
		$rootUrl = self::normalizeRootUrl($rootUrl);
		$preview = self::escape(self::previewText($message));
		$body = nl2br(trim(str_replace(array("\r\n", "\r"), "\n", $message)), false);
		$siteLabel = $rootUrl !== ''
			? '<a href="' . self::escape($rootUrl) . '" style="color:#2563eb;text-decoration:none;font-weight:700;">' . $siteName . '</a>'
			: '<span style="color:#0f172a;font-weight:700;">' . $siteName . '</span>';

		return '<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light">
	<title>' . $subject . '</title>
	<style>
		@media only screen and (max-width: 680px) {
			.hs-shell { padding: 24px 12px !important; }
			.hs-card-body { padding: 32px 24px 30px !important; }
			.hs-card-footer { padding: 20px 24px !important; }
			.hs-title { font-size: 26px !important; }
		}
		.hs-message a { color:#2563eb;text-decoration:none;font-weight:700;border-bottom:1px solid #bfdbfe; }
		.hs-message strong { color:#0f172a;font-weight:800; }
		.hs-message b { display:inline-block;margin:2px 1px;padding:4px 9px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-weight:800;letter-spacing:0.04em; }
	</style>
</head>
<body style="margin:0;padding:0;background:#eef2f7;color:#334155;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;">
	<div style="display:none!important;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">' . $preview . '</div>
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#eef2f7;">
		<tr>
			<td class="hs-shell" align="center" style="padding:48px 16px;">
				<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;">
					<tr>
						<td style="padding:0 8px 20px;">
							<table role="presentation" cellspacing="0" cellpadding="0" border="0">
								<tr>
									<td width="40" valign="middle" style="width:40px;min-width:40px;">
										<table role="presentation" width="40" height="40" cellspacing="0" cellpadding="0" border="0" style="width:40px;min-width:40px;height:40px;table-layout:fixed;border:2.5px solid #171717;border-collapse:separate;border-spacing:0;border-radius:8px;box-sizing:border-box;background:transparent;">
											<tr><td width="35" height="35" align="center" valign="middle" style="width:35px;height:35px;padding:0;color:#171717;font-size:21px;font-weight:900;line-height:35px;">H</td></tr>
										</table>
									</td>
									<td style="padding-left:12px;color:#171717;font-size:24px;font-weight:700;letter-spacing:-0.6px;">Script</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td style="overflow:hidden;border:1px solid #dbe3ee;border-radius:24px;background:#ffffff;box-shadow:0 18px 48px rgba(15,23,42,0.10);">
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
								<tr><td height="6" style="height:6px;background:#2563eb;font-size:0;line-height:0;"></td></tr>
								<tr>
									<td class="hs-card-body" style="padding:46px 48px 42px;">
										<h1 class="hs-title" style="margin:0;color:#0f172a;font-size:32px;line-height:1.22;font-weight:900;letter-spacing:-0.9px;">' . $subject . '</h1>
										<div style="width:48px;height:4px;margin:22px 0 26px;border-radius:999px;background:#2563eb;font-size:0;line-height:0;"></div>
										<div class="hs-message" dir="auto" style="color:#475569;font-size:16px;line-height:1.8;word-break:break-word;">' . $body . '</div>
									</td>
								</tr>
								<tr>
									<td class="hs-card-footer" style="border-top:1px solid #e2e8f0;background:#f8fafc;padding:22px 48px;color:#94a3b8;font-size:12px;line-height:1.7;">
										' . $siteLabel . '<br><span>Copyright ' . date('Y') . ' H-Script</span>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>';
	}

	private static function normalizeRootUrl(string $rootUrl): string
	{
		$rootUrl = trim($rootUrl);
		$parts = $rootUrl !== '' ? parse_url($rootUrl) : false;
		if (!is_array($parts) || empty($parts['host']))
			return '';
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (!in_array($scheme, array('http', 'https'), true))
			return '';
		return rtrim($rootUrl, '/');
	}

	private static function previewText(string $message): string
	{
		// Confirmation codes use <b>; omit those secrets from inbox snippets.
		$message = (string)preg_replace('/<b\b[^>]*>.*?<\/b>/isu', '', $message);
		$message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$message = trim((string)preg_replace('/\s+/u', ' ', $message));
		return mb_substr($message, 0, 240, 'UTF-8');
	}

	private static function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
