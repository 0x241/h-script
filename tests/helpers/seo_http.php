<?php

declare(strict_types=1);

// Shared read-only HTTP transport. BASE_URL may include an installation subdirectory.
$base = rtrim($argv[1] ?? '', '/');
if (!preg_match('~^https?://~', $base)) {
    fwrite(STDERR, "Usage: php tests/locale_routing_http.php BASE_URL [HOST_HEADER]\n");
    exit(2);
}
$host = $argv[2] ?? (string)parse_url($base, PHP_URL_HOST);
$request = static function (string $path, string $cookie = '', string $accept = '', bool $head = false) use ($base, $host): CurlHandle {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Accept-Language: ' . $accept],
        CURLOPT_COOKIE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_NOBODY => $head,
        CURLOPT_TIMEOUT => 45,
    ]);
    $caFile = getenv('SEO_TEST_CA_FILE');
    if ($caFile !== false && $caFile !== '') {
        // Trust only the explicit fixture CA; certificate and hostname checks stay enabled.
        curl_setopt($curl, CURLOPT_CAINFO, $caFile);
    }
    return $curl;
};
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$results = [];
// Keep live staging checks serial when investigating worker exhaustion.
$concurrency = max(1, min(8, (int)(getenv('SEO_HTTP_CONCURRENCY') ?: 8)));
$run = static function (array $cases) use ($request, $check, $concurrency, &$results): void {
    foreach (array_chunk($cases, $concurrency, true) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($chunk as $key => $case) {
            $handles[$key] = $request(...$case);
            curl_multi_add_handle($multi, $handles[$key]);
        }
        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running);
        foreach ($handles as $key => $handle) {
            $raw = curl_multi_getcontent($handle);
            $check(curl_errno($handle) === 0, 'HTTP transport: ' . $key . ' ' . curl_error($handle));
            $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $headers = substr($raw, 0, $headerSize);
            preg_match('/^Location: (.+)\r?$/mi', $headers, $location);
            $results[$key] = [
                'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'headers' => $headers,
                'body' => substr($raw, $headerSize),
                'location' => trim($location[1] ?? ''),
            ];
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    }
};
