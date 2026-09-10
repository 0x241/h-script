<?php

namespace HScript\Update;

use HScript\Application;
use RuntimeException;

/** Official release catalog backed by github.com/0x241/h-script. */
final class GitHubReleaseProvider implements OfficialReleaseProvider
{
	private const API_ROOT = 'https://api.github.com/repos/0x241/h-script';

	public function latest(): array
	{
		return $this->release(self::API_ROOT . '/releases/latest', null);
	}

	public function byVersion(string $version): array
	{
		$version = SchemaVersion::requireValid($version, 'release version');
		return $this->release(self::API_ROOT . '/releases/tags/' . rawurlencode('v' . $version), $version);
	}

	public function downloadArchive(array $release, string $target, int $maximumBytes): void
	{
		$url = (string)($release['archive_url'] ?? '');
		$this->assertGitHubAssetUrl($url);
		$this->download($url, $target, $maximumBytes);
	}

	private function release(string $url, ?string $expectedVersion): array
	{
		$data = $this->json($this->request($url, 2097152));
		$tag = is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
		if (!str_starts_with($tag, 'v'))
			throw new RuntimeException('GitHub release tag is invalid');
		$version = SchemaVersion::requireValid(substr($tag, 1), 'GitHub release version');
		if (!preg_match('/^\d+\.\d+\.\d+$/', $version))
			throw new RuntimeException('Only stable SemVer GitHub releases are accepted');
		if ($expectedVersion !== null && $version !== $expectedVersion)
			throw new RuntimeException('GitHub release version does not match the requested version');
		if (!empty($data['draft']) || !empty($data['prerelease']))
			throw new RuntimeException('Draft and prerelease GitHub releases are not accepted');

		$archiveName = 'h-script-' . $version . '-shared-hosting.tar.gz';
		$assets = array();
		foreach (($data['assets'] ?? array()) as $asset)
		{
			if (!is_array($asset) || !is_string($asset['name'] ?? null) || !is_string($asset['browser_download_url'] ?? null))
				continue;
			if (isset($assets[$asset['name']]))
				throw new RuntimeException('GitHub release contains duplicated asset names');
			$assets[$asset['name']] = $asset['browser_download_url'];
		}
		if (!isset($assets[$archiveName], $assets['SHA256SUMS']))
			throw new RuntimeException('GitHub release does not contain the shared-hosting archive and SHA256SUMS');
		$this->assertGitHubAssetUrl($assets[$archiveName]);
		$this->assertGitHubAssetUrl($assets['SHA256SUMS']);
		if (isset($assets[$archiveName . '.sigstore.json']))
			$this->assertGitHubAssetUrl($assets[$archiveName . '.sigstore.json']);
		$checksums = $this->request($assets['SHA256SUMS'], 1048576);
		$checksum = $this->checksumFor($checksums, $archiveName);
		$changes = $this->changes((string)($data['body'] ?? ''));
		$summary = trim((string)($data['name'] ?? ''));
		if ($summary === '') $summary = 'H-Script ' . $version;
		$releasedAt = (string)($data['published_at'] ?? '');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $releasedAt))
			throw new RuntimeException('GitHub release publication time is invalid');

		return array(
			'version' => $version,
			'tag' => $tag,
			'released_at' => $releasedAt,
			'summary' => substr($summary, 0, 300),
			'changes' => $changes ?: array('Официальный релиз H-Script ' . $version),
			'archive_name' => $archiveName,
			'archive_url' => $assets[$archiveName],
			'archive_sha256' => $checksum,
			'sigstore_url' => (string)($assets[$archiveName . '.sigstore.json'] ?? ''),
		);
	}

	private function changes(string $body): array
	{
		$result = array();
		foreach (preg_split('/\R/', $body) ?: array() as $line)
		{
			$line = trim(preg_replace('/^\s*(?:[-*+]\s+|#{1,6}\s*)/', '', $line));
			if ($line === '' || str_starts_with($line, '<!--')) continue;
			$result[] = substr($line, 0, 300);
			if (count($result) >= 30) break;
		}
		return $result;
	}

	private function checksumFor(string $contents, string $archiveName): string
	{
		foreach (preg_split('/\R/', $contents) ?: array() as $line)
			if (preg_match('/^([a-f0-9]{64})\s+\*?' . preg_quote($archiveName, '/') . '$/', trim($line), $match))
				return $match[1];
		throw new RuntimeException('SHA256SUMS does not contain the expected release archive');
	}

	private function json(string $contents): array
	{
		try { $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR); }
		catch (\JsonException $exception) { throw new RuntimeException('GitHub release response is invalid', 0, $exception); }
		if (!is_array($data) || array_is_list($data))
			throw new RuntimeException('GitHub release response is invalid');
		return $data;
	}

	private function request(string $url, int $maximumBytes): string
	{
		$stream = fopen('php://temp/maxmemory:2097152', 'w+b');
		if ($stream === false) throw new RuntimeException('Temporary release response could not be opened');
		try
		{
			$this->curl($url, $stream, $maximumBytes);
			rewind($stream);
			$contents = stream_get_contents($stream);
			if (!is_string($contents)) throw new RuntimeException('GitHub release response could not be read');
			return $contents;
		}
		finally { fclose($stream); }
	}

	private function download(string $url, string $target, int $maximumBytes): void
	{
		$stream = fopen($target, 'xb');
		if ($stream === false) throw new RuntimeException('Release archive download could not be started');
		try { $this->curl($url, $stream, $maximumBytes); }
		catch (\Throwable $exception) { fclose($stream); unlink($target); throw $exception; }
		fclose($stream);
	}

	/** @param resource $stream */
	private function curl(string $url, $stream, int $maximumBytes): void
	{
		if (!function_exists('curl_init')) throw new RuntimeException('PHP curl extension is required for GitHub releases');
		$bytes = 0;
		$curl = curl_init($url);
		curl_setopt_array($curl, array(
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'),
			CURLOPT_USERAGENT => Application::userAgent(),
			CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, &$bytes, $maximumBytes): int {
				$length = strlen($chunk);
				$bytes += $length;
				if ($bytes > $maximumBytes) return 0;
				$written = fwrite($stream, $chunk);
				return $written === false ? 0 : $written;
			},
		));
		$ok = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error = curl_error($curl);
		curl_close($curl);
		if ($ok !== true || $status !== 200 || $bytes < 1 || !fflush($stream))
			throw new RuntimeException('Official GitHub release download failed' . ($error !== '' ? ': ' . $error : ''));
	}

	private function assertGitHubAssetUrl(string $url): void
	{
		$parts = parse_url($url);
		if (($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'github.com'
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| (isset($parts['port']) && $parts['port'] !== 443))
			throw new RuntimeException('GitHub release asset URL is invalid');
		if (!str_starts_with((string)($parts['path'] ?? ''), '/0x241/h-script/releases/download/'))
			throw new RuntimeException('GitHub release asset does not belong to 0x241/h-script');
	}
}
