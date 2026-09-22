<?php

namespace HScript\Telemetry;

use RuntimeException;
use Throwable;

/** Performs bounded passive A/AAAA resolution without contacting installations over HTTP. */
final class PassiveDnsResolver implements DnsResolverInterface
{
	private const MAX_CNAME_HOPS = 8;
	private const MIN_CACHE_SECONDS = 3600;
	private const MAX_CACHE_SECONDS = 86400;
	private const DEFAULT_TIMEOUT_SECONDS = 3.0;

	/** @var null|callable(string,int,float):array */
	private $query;
	private float $timeoutSeconds;

	public function __construct(?callable $query = null, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
	{
		$this->query = $query;
		$this->timeoutSeconds = max(0.1, min(10.0, $timeoutSeconds));
	}

	public function resolve(string $domain, string $observedIp): array
	{
		$now = time();
		$domain = self::normalizeDomain($domain);
		if ($domain === '')
			return $this->result('invalid', array(), $now, self::MIN_CACHE_SECONDS, 'domain_invalid');

		$deadline = microtime(true) + $this->timeoutSeconds;
		$current = $domain;
		$visited = array();
		$addresses = array();
		$minimumTtl = self::MAX_CACHE_SECONDS;
		$sawNonPublic = false;
		$lastError = 'no_records';

		try
		{
			for ($hop = 0; $hop <= self::MAX_CNAME_HOPS; $hop++)
			{
				if (isset($visited[$current]))
					return $this->result('invalid', array(), $now, self::MIN_CACHE_SECONDS, 'cname_loop');
				$visited[$current] = true;
				if (microtime(true) >= $deadline)
					return $this->result('unresolved', array(), $now, self::MIN_CACHE_SECONDS, 'dns_timeout');

				$records = array();
				$errors = array();
				foreach (array(1, 28) as $type)
				{
					$response = $this->query($current, $type, max(0.05, $deadline - microtime(true)));
					$records = array_merge($records, $response['records'] ?? array());
					if (!empty($response['error']))
						$errors[] = (string)$response['error'];
				}

				$next = '';
				foreach ($records as $record)
				{
					$name = self::normalizeDomain((string)($record['name'] ?? ''));
					if ($name !== $current)
						continue;
					$ttl = max(0, (int)($record['ttl'] ?? 0));
					if ($ttl > 0)
						$minimumTtl = min($minimumTtl, $ttl);
					$type = (string)($record['type'] ?? '');
					if ($type === 'CNAME')
					{
						$next = self::normalizeDomain((string)($record['target'] ?? ''));
						continue;
					}
					if (!in_array($type, array('A', 'AAAA'), true))
						continue;
					$address = self::normalizeIp((string)($record['address'] ?? ''));
					if ($address === '')
						continue;
					if (!self::isPublicIp($address))
					{
						$sawNonPublic = true;
						continue;
					}
					$addresses[$address] = true;
				}

				if ($addresses)
					break;
				if ($next !== '')
				{
					if ($hop === self::MAX_CNAME_HOPS)
						return $this->result('invalid', array(), $now, self::MIN_CACHE_SECONDS, 'cname_depth');
					$current = $next;
					continue;
				}
				if (in_array('dns_timeout', $errors, true))
					$lastError = 'dns_timeout';
				elseif (in_array('nxdomain', $errors, true))
					$lastError = 'nxdomain';
				elseif ($errors)
					$lastError = 'dns_error';
				break;
			}
		}
		catch (Throwable)
		{
			return $this->result('unresolved', array(), $now, self::MIN_CACHE_SECONDS, 'dns_error');
		}

		if (!$addresses)
			return $this->result(
				$sawNonPublic ? 'invalid' : 'unresolved',
				array(),
				$now,
				self::MIN_CACHE_SECONDS,
				$sawNonPublic ? 'non_public_address' : $lastError
			);

		$addresses = array_keys($addresses);
		usort($addresses, static function (string $left, string $right): int {
			return strcmp((string)inet_pton($left), (string)inet_pton($right));
		});
		$observedIp = self::normalizeIp($observedIp);
		$status = $observedIp !== '' && isset(array_flip($addresses)[$observedIp]) ? 'matched' : 'mismatch';
		$ttl = max(self::MIN_CACHE_SECONDS, min(self::MAX_CACHE_SECONDS, $minimumTtl));
		return $this->result($status, $addresses, $now, $ttl, '');
	}

	/** @return array{records:array<int,array<string,mixed>>,error:string} */
	private function query(string $name, int $type, float $timeout): array
	{
		if ($this->query !== null)
		{
			$result = ($this->query)($name, $type, $timeout);
			return is_array($result) ? $result : array('records' => array(), 'error' => 'dns_error');
		}
		return $this->udpQuery($name, $type, $timeout);
	}

	/** @return array{records:array<int,array<string,mixed>>,error:string} */
	private function udpQuery(string $name, int $type, float $timeout): array
	{
		$servers = $this->nameServers();
		if (!$servers)
			return array('records' => array(), 'error' => 'dns_unavailable');

		$id = random_int(1, 65535);
		$packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $this->encodeName($name) . pack('nn', $type, 1);
		$deadline = microtime(true) + max(0.05, $timeout);
		foreach ($servers as $server)
		{
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0)
				break;
			$target = str_contains($server, ':') ? 'udp://[' . $server . ']:53' : 'udp://' . $server . ':53';
			$errno = 0;
			$error = '';
			$stream = stream_socket_client($target, $errno, $error, min(1.0, $remaining), STREAM_CLIENT_CONNECT);
			if (!is_resource($stream))
				continue;
			try
			{
				$wait = min(1.0, max(0.05, $deadline - microtime(true)));
				stream_set_timeout($stream, (int)$wait, (int)(($wait - (int)$wait) * 1000000));
				if (fwrite($stream, $packet) !== strlen($packet))
					continue;
				$response = fread($stream, 4096);
				$meta = stream_get_meta_data($stream);
				if (!is_string($response) || strlen($response) < 12 || !empty($meta['timed_out']))
					continue;
				return $this->parseResponse($response, $id);
			}
			finally
			{
				fclose($stream);
			}
		}
		return array('records' => array(), 'error' => 'dns_timeout');
	}

	/** @return array{records:array<int,array<string,mixed>>,error:string} */
	private function parseResponse(string $packet, int $expectedId): array
	{
		$header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
		if (!is_array($header) || (int)$header['id'] !== $expectedId || (((int)$header['flags']) & 0x8000) === 0)
			throw new RuntimeException('Invalid DNS response');
		if ((int)$header['questions'] < 0 || (int)$header['questions'] > 10)
			throw new RuntimeException('Invalid DNS question count');
		$rcode = ((int)$header['flags']) & 0x000f;
		if ($rcode !== 0)
			return array('records' => array(), 'error' => $rcode === 3 ? 'nxdomain' : 'dns_error');

		$offset = 12;
		for ($index = 0; $index < (int)$header['questions']; $index++)
		{
			$this->decodeName($packet, $offset);
			$offset += 4;
			if ($offset > strlen($packet))
				throw new RuntimeException('Truncated DNS question');
		}

		$records = array();
		$total = min(128, (int)$header['answers'] + (int)$header['authority'] + (int)$header['additional']);
		for ($index = 0; $index < $total; $index++)
		{
			$name = self::normalizeDomain($this->decodeName($packet, $offset));
			if ($offset + 10 > strlen($packet))
				throw new RuntimeException('Truncated DNS record');
			$meta = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
			$offset += 10;
			$length = (int)$meta['length'];
			if ($length < 0 || $offset + $length > strlen($packet))
				throw new RuntimeException('Invalid DNS record length');
			if ((int)$meta['class'] === 1 && $name !== '')
			{
				if ((int)$meta['type'] === 1 && $length === 4)
					$records[] = array('name' => $name, 'type' => 'A', 'address' => inet_ntop(substr($packet, $offset, 4)), 'ttl' => (int)$meta['ttl']);
				elseif ((int)$meta['type'] === 28 && $length === 16)
					$records[] = array('name' => $name, 'type' => 'AAAA', 'address' => inet_ntop(substr($packet, $offset, 16)), 'ttl' => (int)$meta['ttl']);
				elseif ((int)$meta['type'] === 5)
				{
					$nameOffset = $offset;
					$records[] = array('name' => $name, 'type' => 'CNAME', 'target' => $this->decodeName($packet, $nameOffset), 'ttl' => (int)$meta['ttl']);
				}
			}
			$offset += $length;
		}
		return array('records' => $records, 'error' => '');
	}

	private function decodeName(string $packet, int &$offset, int $depth = 0): string
	{
		if ($depth > 16 || $offset < 0 || $offset >= strlen($packet))
			throw new RuntimeException('Invalid DNS name');
		$labels = array();
		while (true)
		{
			if ($offset >= strlen($packet))
				throw new RuntimeException('Truncated DNS name');
			$length = ord($packet[$offset++]);
			if ($length === 0)
				break;
			if (($length & 0xc0) === 0xc0)
			{
				if ($offset >= strlen($packet))
					throw new RuntimeException('Truncated DNS pointer');
				$pointer = (($length & 0x3f) << 8) | ord($packet[$offset++]);
				$labels[] = $this->decodeName($packet, $pointer, $depth + 1);
				break;
			}
			if ($length > 63 || $offset + $length > strlen($packet))
				throw new RuntimeException('Invalid DNS label');
			$labels[] = substr($packet, $offset, $length);
			$offset += $length;
		}
		return rtrim(implode('.', array_filter($labels, static fn(string $label): bool => $label !== '')), '.');
	}

	private function encodeName(string $name): string
	{
		$result = '';
		foreach (explode('.', $name) as $label)
		{
			$length = strlen($label);
			if ($length < 1 || $length > 63)
				throw new RuntimeException('Invalid DNS label');
			$result .= chr($length) . $label;
		}
		return $result . "\0";
	}

	/** @return array<int,string> */
	private function nameServers(): array
	{
		$source = is_readable('/etc/resolv.conf') ? file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES) : false;
		$servers = array();
		foreach (is_array($source) ? $source : array() as $line)
			if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $matches))
			{
				$address = self::normalizeIp($matches[1]);
				if ($address !== '')
					$servers[$address] = true;
				if (count($servers) >= 2)
					break;
			}
		return array_keys($servers);
	}

	private function result(string $status, array $addresses, int $now, int $ttl, string $error): array
	{
		return array(
			'status' => $status,
			'addresses' => array_values($addresses),
			'checked_at' => $now,
			'expires_at' => $now + max(self::MIN_CACHE_SECONDS, min(self::MAX_CACHE_SECONDS, $ttl)),
			'error_code' => $error,
		);
	}

	public static function normalizeDomain(string $domain): string
	{
		$domain = strtolower(rtrim(trim($domain), '.'));
		if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) !== false)
			return '';
		if (!str_contains($domain, '.') || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain))
			return '';
		foreach (array('.local', '.localhost', '.test', '.invalid', '.example') as $suffix)
			if (str_ends_with($domain, $suffix))
				return '';
		return $domain;
	}

	public static function normalizeIp(string $ip): string
	{
		$ip = trim($ip);
		if (filter_var($ip, FILTER_VALIDATE_IP) === false)
			return '';
		$packed = inet_pton($ip);
		if ($packed === false)
			return '';
		$normalized = inet_ntop($packed);
		return is_string($normalized) ? strtolower($normalized) : '';
	}

	private static function isPublicIp(string $ip): bool
	{
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) !== false;
	}
}
