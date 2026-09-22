<?php

namespace HScript\Telemetry;

use HScript\Http\ClientIp;
use RuntimeException;

/** No redirects, proxies or caller-supplied paths; DNS is validated and pinned. */
final class DomainProofHttpClient implements DomainProofTransport
{
	public const PATH = '/api/v1/installations/domain-proof';

	public function __construct(private DnsResolverInterface $dns = new PassiveDnsResolver()) {}

	public static function publicAddress(string $ip): bool
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
			return false;
		if (str_contains($ip, ':') && !ClientIp::matchesCidr($ip, '2000::/3'))
			return false;
		foreach (array('100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
			'198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
			'2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20') as $range)
			if (ClientIp::matchesCidr($ip, $range))
				return false;
		return true;
	}

	public function fetch(string $domain): array
	{
		if ($domain === '' || PassiveDnsResolver::normalizeDomain($domain) !== $domain)
			throw new RuntimeException('proof_domain_invalid');
		$resolved = $this->dns->resolve($domain, '');
		$addresses = $resolved['addresses'] ?? array();
		if (!in_array($resolved['status'] ?? '', array('matched', 'mismatch'), true)
			|| !$addresses || count($addresses) > 32)
			throw new RuntimeException('proof_dns_unavailable');
		foreach ($addresses as $address)
			if (!is_string($address) || !self::publicAddress($address))
				throw new RuntimeException('proof_address_blocked');
		$address = $addresses[0];
		$pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
		$body = '';
		$headers = 0;
		$curl = curl_init('https://' . $domain . self::PATH);
		try
		{
			curl_setopt_array($curl, array(
				CURLOPT_RESOLVE => array($domain . ':443:' . $pinned),
				CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
				CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
				CURLOPT_HTTPHEADER => array('Accept: application/json', 'Cache-Control: no-cache'),
				CURLOPT_USERAGENT => 'H-Script-Domain-Proof/1',
				CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
					if (strlen($body) + strlen($chunk) > 4096) return 0;
					$body .= $chunk;
					return strlen($chunk);
				},
				CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
					$headers += strlen($line);
					return $headers <= 16384 ? strlen($line) : 0;
				},
			));
			if (curl_exec($curl) === false || (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200)
				throw new RuntimeException('proof_https_failed');
			$decoded = json_decode($body, true, 8);
			if (!is_array($decoded) || ($decoded['success'] ?? null) !== true || !is_array($decoded['data'] ?? null))
				throw new RuntimeException('proof_response_invalid');
			return $decoded['data'];
		}
		finally { curl_close($curl); }
	}
}
