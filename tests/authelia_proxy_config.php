<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function autheliaProxyAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

$compose = file_get_contents($root . '/docker-compose.yml');
$composer = file_get_contents($root . '/composer.json');
$apache = file_get_contents($root . '/docker/apache/httpd.conf');
$virtualHost = file_get_contents($root . '/docker/authelia/nginx/hscript.conf.template');
$autheliaConfig = file_get_contents($root . '/docker/authelia/remote/configuration.yml.example');
$users = file_get_contents($root . '/docker/authelia/remote/users.yml.example');
$topology = file_get_contents($root . '/docker/authelia/nginx/topology.env.example');
$readme = file_get_contents($root . '/docker/authelia/README.md');
$gitIgnore = file_get_contents($root . '/.gitignore');
$dockerIgnore = file_get_contents($root . '/.dockerignore');
foreach (array($compose, $composer, $apache, $virtualHost, $autheliaConfig, $users, $topology, $readme, $gitIgnore, $dockerIgnore) as $contents)
	autheliaProxyAssert(is_string($contents), 'Authelia deployment file could not be read');

autheliaProxyAssert(!str_contains($compose, 'authelia:'), 'Remote Authelia was duplicated in the H-Script Compose stack');
autheliaProxyAssert(!str_contains($composer, 'authelia'), 'Authelia became an application Composer dependency');
autheliaProxyAssert(str_contains($compose, '${APP_BIND_IP:-127.0.0.1}:${APP_PORT:-8080}:80'), 'Application port is not loopback-only by default');
autheliaProxyAssert(substr_count($compose, 'TRUSTED_PROXY_CIDRS:-127.0.0.1/32,::1/128') === 2, 'Application and cron proxy trust defaults differ');
autheliaProxyAssert(!str_contains($compose, 'TRUSTED_PROXY_CIDRS:-127.0.0.1/32,::1/128,10.0.0.0/8'), 'Compose still trusts all private networks by default');
autheliaProxyAssert(!str_contains($apache, 'SetEnvIf X-Forwarded-Proto'), 'Apache still trusts a client-supplied HTTPS header');

autheliaProxyAssert(str_contains($virtualHost, 'server ${HS_TAILSCALE_IP}:${HS_UPSTREAM_PORT};'), 'H-Script upstream is not sourced from the topology environment');
autheliaProxyAssert(str_contains($virtualHost, 'server ${AUTHELIA_LISTEN_IP}:${AUTHELIA_LISTEN_PORT};'), 'Authelia upstream is not sourced from the topology environment');
autheliaProxyAssert(str_contains($virtualHost, 'server_name ${HS_PUBLIC_HOST};'), 'H-Script public host is not sourced from the topology environment');
autheliaProxyAssert(str_contains($virtualHost, 'server_name ${AUTHELIA_PUBLIC_HOST};'), 'Authelia public host is not sourced from the topology environment');
autheliaProxyAssert(str_contains($virtualHost, 'ssl_certificate ${HS_TLS_CERTIFICATE};') && str_contains($virtualHost, 'ssl_certificate ${AUTHELIA_TLS_CERTIFICATE};'), 'TLS certificate paths are not sourced from the topology environment');
autheliaProxyAssert(str_contains($virtualHost, 'proxy_pass http://hscript_authelia_backend/api/authz/auth-request;'), 'Gateway Nginx does not use the local Authelia upstream');
autheliaProxyAssert(!str_contains($virtualHost, '100.64.') && !str_contains($readme, '100.64.'), 'An example Tailscale IP leaked outside topology.env');
autheliaProxyAssert(str_contains($virtualHost, 'proxy_connect_timeout 2s;') && str_contains($virtualHost, 'proxy_read_timeout 5s;'), 'Auth request is not bounded');
autheliaProxyAssert(str_contains($virtualHost, 'location ~ ^/_cfg(?:/|$)') && str_contains($virtualHost, 'location ~ ^/admin(?:/|$)'), 'Protected Nginx routes are incomplete or not anchored');
autheliaProxyAssert(!str_contains($virtualHost, 'location ~*'), 'Protected Nginx routes became case-insensitive');
autheliaProxyAssert(substr_count($virtualHost, 'location / {') >= 3, 'HTTP redirect, public H-Script, or Authelia portal location is missing');
autheliaProxyAssert(str_contains($virtualHost, 'proxy_pass http://hscript_backend;'), 'Gateway Nginx does not use the rendered H-Script upstream');
autheliaProxyAssert(substr_count($virtualHost, 'auth_request /internal/authelia/authz;') === 2, 'Only the two protected upstreams may invoke Authelia');
autheliaProxyAssert(!str_contains($virtualHost, '$upstream_http_remote_'), 'Unused Authelia identity was forwarded to H-Script');
foreach (array('Remote-User', 'X-Forwarded-User', 'CF-Connecting-IP', 'True-Client-IP') as $header)
	autheliaProxyAssert(str_contains($virtualHost, 'proxy_set_header ' . $header . ' "";'), 'Client identity header is not cleared: ' . $header);
foreach (array('HS_PUBLIC_HOST', 'AUTHELIA_PUBLIC_HOST', 'HS_TAILSCALE_IP', 'HS_UPSTREAM_PORT', 'GATEWAY_TAILSCALE_IP', 'AUTHELIA_LISTEN_IP', 'AUTHELIA_LISTEN_PORT', 'HS_TLS_CERTIFICATE', 'HS_TLS_CERTIFICATE_KEY', 'AUTHELIA_TLS_CERTIFICATE', 'AUTHELIA_TLS_CERTIFICATE_KEY', 'ACME_WEBROOT') as $variable)
	autheliaProxyAssert(str_contains($topology, $variable . '='), 'Topology environment variable is missing: ' . $variable);
autheliaProxyAssert(str_contains($topology, 'APP_BIND_IP=${HS_TAILSCALE_IP}') && str_contains($topology, 'TRUSTED_PROXY_CIDRS=${GATEWAY_TAILSCALE_IP}/32'), 'H-Script topology does not derive its bind or trusted proxy address');
autheliaProxyAssert(str_contains($readme, "envsubst '\${HS_PUBLIC_HOST} \${AUTHELIA_PUBLIC_HOST}"), 'Documented Nginx rendering does not restrict envsubst variables');
foreach (array('/api/v1/installations/register', '/api/v1/installations/report', '/api/v1/installations/domain-verification', '/api/v1/installations/domain-proof', '/balance/status', '/cron?auto') as $bypassExample)
	autheliaProxyAssert(str_contains($readme, $bypassExample), 'Mandatory machine-route bypass example is missing: ' . $bypassExample);
autheliaProxyAssert(str_contains($readme, 'по умолчанию отключена'), 'Documentation does not state that Authelia is disabled by default');

foreach (array('server:', 'authentication_backend:', 'session:', 'storage:', 'notifier:', 'access_control:') as $section)
	autheliaProxyAssert(str_contains($autheliaConfig, $section), 'Full Authelia example is missing section: ' . $section);
autheliaProxyAssert(str_contains($autheliaConfig, "address: 'tcp://127.0.0.1:9091/'"), 'Native Authelia does not bind to loopback');
autheliaProxyAssert(str_contains($autheliaConfig, "implementation: 'AuthRequest'"), 'AuthRequest endpoint is not configured');
autheliaProxyAssert(str_contains($autheliaConfig, "default_policy: 'deny'"), 'Remote Authelia default deny is missing');
autheliaProxyAssert(str_contains($autheliaConfig, "'^/_cfg([/?].*)?$'") && str_contains($autheliaConfig, "'^/admin([/?].*)?$'"), 'Remote Authelia resources are incomplete or not anchored');
autheliaProxyAssert(str_contains($autheliaConfig, "address: 'submission://smtp.example.com:587'"), 'TLS SMTP notifier example is missing');
autheliaProxyAssert(!str_contains($autheliaConfig, 'filesystem:'), 'Authelia example configures more than one notifier');
autheliaProxyAssert(str_contains($users, "password: '<ARGON2ID_HASH>'"), 'Users example does not require an explicit generated password hash');
foreach (array('/api/v1', '/cron', '/balance/status') as $machinePath)
	autheliaProxyAssert(!str_contains($autheliaConfig, $machinePath), 'Machine route was protected by default: ' . $machinePath);

autheliaProxyAssert(str_contains($gitIgnore, '/docs/') && str_contains($dockerIgnore, '/docs/'), 'Local documentation directory is not ignored');

echo "Authelia proxy configuration tests passed.\n";
