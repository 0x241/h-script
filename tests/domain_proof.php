<?php

// Replace only network primitives in this process; assert the real client's policy.
namespace HScript\Telemetry {
	function curl_init(string $url): object { $GLOBALS['proof_url'] = $url; return new \stdClass(); }
	function curl_setopt_array($handle, array $options): bool { $GLOBALS['proof_options'] = $options; return true; }
	function curl_exec($handle): bool {
		$options = $GLOBALS['proof_options'];
		$body = $GLOBALS['proof_body'];
		return $options[CURLOPT_HEADERFUNCTION]($handle, $GLOBALS['proof_header'] ?? "HTTP/1.1 200 OK\r\n") > 0
			&& $options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
	}
	function curl_getinfo($handle, int $option): int { return $GLOBALS['proof_status'] ?? 200; }
	function curl_close($handle): void {}
}

namespace {
	use HScript\Database\Connection;
	use HScript\Telemetry\DnsResolverInterface;
	use HScript\Telemetry\DomainProofDocument;
	use HScript\Telemetry\DomainProofHttpClient;
	use HScript\Telemetry\DomainProofService;
	use HScript\Telemetry\TelemetryClientInterface;
	use HScript\Telemetry\TelemetryReporter;

	require dirname(__DIR__) . '/vendor/autoload.php';
	$checks = 0;
	$assert = static function (bool $ok, string $message) use (&$checks): void {
		$checks++;
		if (!$ok) throw new RuntimeException($message);
	};
	$reject = static function (callable $call, string $message) use ($assert): void {
		$failed = false;
		try { $call(); } catch (RuntimeException) { $failed = true; }
		$assert($failed, $message);
	};
	foreach (array('127.0.0.1', '10.0.0.1', '172.16.1.1', '192.168.1.1', '169.254.169.254',
		'100.64.0.1', '0.0.0.0', '224.0.0.1', '240.1.1.1', '192.0.2.1', '198.18.0.1',
		'::1', '::ffff:8.8.8.8', '64:ff9b::808:808', 'fc00::1', 'fe80::1', 'ff02::1',
		'2001:db8::1', '2002:0808:0808::1', '2001::1', '3fff::1', 'garbage') as $ip)
		$assert(!DomainProofHttpClient::publicAddress($ip), 'Unsafe IP allowed: ' . $ip);
	foreach (array('8.8.8.8', '188.127.224.39', '2606:4700:4700::1111', '2001:4860:4860::8888') as $ip)
		$assert(DomainProofHttpClient::publicAddress($ip), 'Public address rejected: ' . $ip);
	$dns = new class implements DnsResolverInterface {
		public array $addresses = array('8.8.8.8');
		public int $queries = 0;
		public function resolve(string $domain, string $observedIp): array {
			$this->queries++;
			return array('status' => 'mismatch', 'addresses' => $this->addresses);
		}
	};
	$http = new DomainProofHttpClient($dns);
	$GLOBALS['proof_body'] = json_encode(array('success' => true, 'data' => array('nonce' => 'test')));
	$assert($http->fetch('example.org')['nonce'] === 'test', 'Proof document not parsed');
	$options = $GLOBALS['proof_options'];
	$assert($GLOBALS['proof_url'] === 'https://example.org/api/v1/installations/domain-proof', 'Unexpected proof URL');
	$assert($options[CURLOPT_RESOLVE] === array('example.org:443:8.8.8.8') && $dns->queries === 1, 'DNS not pinned');
	$assert($options[CURLOPT_PROXY] === '' && $options[CURLOPT_NOPROXY] === '*', 'Environment proxy allowed');
	$assert($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS checks disabled');
	$assert(!$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_MAXREDIRS] === 0 && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Redirect/protocol policy unsafe');
	$assert($options[CURLOPT_TIMEOUT] === 5 && $options[CURLOPT_CONNECTTIMEOUT] === 2, 'Network operation unbounded');
	foreach (array('localhost', '127.0.0.1', 'example.org:443', 'user@example.org', 'example.org/path', 'https://example.org', 'example.local') as $domain)
		$reject(static fn() => $http->fetch($domain), 'Unsafe domain allowed: ' . $domain);
	$dns->addresses = array('8.8.8.8', '10.1.1.1');
	$reject(static fn() => $http->fetch('example.org'), 'Mixed private/public DNS allowed');
	$dns->addresses = array('2606:4700:4700::1111');
	$http->fetch('example.org');
	$assert($GLOBALS['proof_options'][CURLOPT_RESOLVE] === array('example.org:443:[2606:4700:4700::1111]'), 'IPv6 pin malformed');
	$GLOBALS['proof_status'] = 302;
	$reject(static fn() => $http->fetch('example.org'), 'Redirect response accepted');
	$GLOBALS['proof_status'] = 200;
	$GLOBALS['proof_body'] = str_repeat('a', 4097);
	$reject(static fn() => $http->fetch('example.org'), 'Oversized response accepted');
	$GLOBALS['proof_body'] = '{}';
	$reject(static fn() => $http->fetch('example.org'), 'Invalid envelope accepted');
	$GLOBALS['proof_header'] = str_repeat('a', 16385);
	$reject(static fn() => $http->fetch('example.org'), 'Oversized headers accepted');

	$now = time();
	$config = array('Telemetry_InstallationID' => '123e4567-e89b-42d3-a456-426614174099', 'Telemetry_Domain' => 'example.org');
	$proof = array('installation_id' => $config['Telemetry_InstallationID'], 'domain' => 'example.org', 'nonce' => str_repeat('f', 64), 'expires_at' => $now + 600);
	$config['Telemetry_DomainProof'] = json_encode($proof + array('secret' => 'must-not-leak'));
	$assert(DomainProofDocument::fromConfig($config, $now) === $proof, 'Stored proof not served or extra fields leaked');
	$assert(DomainProofDocument::fromConfig($config, $now + 600) === null, 'Expired document served');
	$assert(DomainProofDocument::fromConfig($config, $now - 30) === $proof, 'Small clock skew broke proof publication');
	$assert(DomainProofDocument::fromConfig($config, $now - 61) === null, 'Overlong expiry accepted');
	$config['Telemetry_Domain'] = 'other.org';
	$assert(DomainProofDocument::fromConfig($config, $now) === null, 'Proof survived domain change');
	$assert(!DomainProofService::fresh(array('tiDomainVerifiedAt' => $now + 1, 'tiDomainVerifiedUntil' => $now + 100), $now), 'Future proof accepted');
	$assert(!DomainProofService::fresh(array('tiDomainVerifiedAt' => $now - 1, 'tiDomainVerifiedUntil' => $now), $now), 'Expired verification accepted');

	$db = new class extends Connection {
		public array $config = array();
		public function replace($table, $values, $fields = '') {
			$this->config[$values['Module'] . '_' . $values['Prop']] = $values['Val'];
			return true;
		}
	};
	$client = new class($db, $assert) implements TelemetryClientInterface {
		public int $proofCalls = 0;
		public bool $fail = false;
		public function __construct(private $db, private $assert) {}
		public function request(string $method, string $path, array $payload, string $token): array {
			$data = array();
			if ($path === 'domain-verification') {
				$this->proofCalls++;
				if ($this->fail) throw new RuntimeException('Synthetic outage');
				if ($payload['operation'] === 'challenge') $data = array('state' => 'challenge', 'nonce' => str_repeat('e', 64), 'expires_at' => time() + 630);
				else {
					$document = DomainProofDocument::fromConfig($this->db->config);
					($this->assert)($document !== null && $document['installation_id'] === $payload['installation_id'] && $document['domain'] === $payload['domain'], 'Reporter did not publish challenge before verification');
					$data = array('state' => 'verified', 'verified_until' => time() + DomainProofService::LIFETIME + 30,
						'public_metrics' => array('processed' => 50, 'platforms' => 1, 'updated_at' => time()));
				}
			}
			return array('ok' => true, 'status' => 200, 'data' => $data);
		}
	};
	$reporter = new TelemetryReporter($db, array(), 'example.org', $client);
	$assert($reporter->register()['ok'], 'Registration failed');
	$assert($client->proofCalls === 2 && $db->config['Telemetry_DomainVerifiedUntil'] > time(), 'Handshake incomplete');
	$assert(json_decode($db->config['Telemetry_PublicMetrics'], true)['platforms'] === 1, 'Verified eligibility not reflected in cached metrics');
	$assert(DomainProofDocument::fromConfig($db->config) === null, 'Consumed challenge still published');
	$reporter->report();
	$assert($client->proofCalls === 2, 'Fresh proof re-requested');
	$client->fail = true;
	$changed = new TelemetryReporter($db, $db->config, 'changed.org', $client);
	$assert($changed->register()['ok'], 'Proof outage broke registration');
	$assert((int)$db->config['Telemetry_DomainVerifiedUntil'] === 0, 'Domain change preserved verification');
	$assert($db->config['Telemetry_DomainProofError'] === 'proof_local_error', 'Proof failure not recorded');
	echo 'Domain proof tests passed (' . $checks . " checks).\n";
}
