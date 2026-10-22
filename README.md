# H-Script

H-Script 1.0.7 is a PHP CMS for financial projects. It includes user accounts,
deposits, payment gateways, a referral system, administration tools, installation
telemetry, and a versioned REST API.

## Technology stack

- PHP 8.4, PDO prepared statements, and PSR-4 (`HScript\` to `src/`)
- Twig 3, Tailwind CSS 4, and HTMX 2
- A custom design system without Franken UI or jQuery
- MySQL 8.4 and optional Redis 8 caching
- A MySQL-backed background job queue
- Apache and PHP-FPM in the production image

## Deployment options

| Environment | Recommended delivery | Upgrade method |
| --- | --- | --- |
| VPS or dedicated server | Published Docker Hub image (GHCR mirror) with Docker Compose | Change the SemVer tag, pull, and restart |
| Development or private build | Docker Compose from source | Pull Git changes and rebuild |
| VPS without Docker | Nginx/Apache, PHP-FPM, MySQL, and a release archive | Deploy a release or use Git and Composer |
| Shared hosting | Production release archive with `vendor/` and compiled CSS | Back up runtime data and upload the new release |

For most installations, use the published image. It is reproducible and already
contains Composer dependencies and compiled CSS. Pin an exact version such as
`1.0.7`; do not use a floating `latest` tag in production. This source tree targets
`1.0.7`; the image examples below require that release to have been published.
Until then, use an existing published tag or build this source for staging.

## Docker with a published image

Requirements:

- Docker Engine and Docker Compose v2
- A domain pointing to the server
- Cloudflare Turnstile keys
- An external Docker network used by the reverse proxy, or a dedicated network

Clone the repository because the Compose file and environment example are still
required:

```bash
git clone https://github.com/0x241/h-script.git
cd h-script
cp docker/env.example .env
docker network create external-proxy-network
```

If the reverse proxy already owns a network, set its name instead of creating
`external-proxy-network`.

Select the published image in `.env`:

```env
APP_IMAGE=docker.io/0x241/h-script
APP_IMAGE_TAG=1.0.7
APP_PULL_POLICY=always

APP_ENV=production
APP_DEBUG=0
APP_DOMAIN=example.com
APP_SYS_ID=change-me-stable-secret
APP_SYS_MAIL=admin@example.com
APP_BIND_IP=127.0.0.1
APP_EXTERNAL_NETWORK=external-proxy-network

DB_PASSWORD=change-me-db-password
MYSQL_ROOT_PASSWORD=change-me-root-password
CONFIGURATOR_PASSWORD=change-me-configurator-password

TURNSTILE_SITE_KEY=change-me-site-key
TURNSTILE_SECRET_KEY=change-me-secret-key
```

The release workflow publishes the same digest as `ghcr.io/0x241/h-script:1.0.7`.
Both public packages should allow end users to pull without `docker login`. A private GHCR
package requires a token with `read:packages` permission.

Use these variables only for the first bootstrap of an empty database:

```env
APP_AUTO_INSTALL=1
INSTALL_ADMIN_NAME=Administrator
INSTALL_ADMIN_LOGIN=admin
INSTALL_ADMIN_PASSWORD=change-me-admin-password
INSTALL_ADMIN_SECRET_ANSWER=change-me-secret-answer
INSTALL_ADMIN_PIN=change-me-pin

INSTALL_MAIL_HOST=smtp.example.com
INSTALL_MAIL_PORT=587
INSTALL_MAIL_SECURE=1
INSTALL_MAIL_USERNAME=mailer@example.com
INSTALL_MAIL_PASSWORD=change-me-smtp-password
INSTALL_MAIL_ADMIN_LANG=en

# Optional only when these addresses differ from APP_SYS_MAIL:
# INSTALL_ADMIN_MAIL=owner@example.com
# INSTALL_MAIL_FROM_ADDRESS=noreply@example.com
# INSTALL_MAIL_ADMIN_ADDRESS=notifications@example.com
```

Start the services:

```bash
docker compose pull app cron database redis
docker compose up -d --no-build
```

After the first successful installation, set:

```env
APP_AUTO_INSTALL=0
```

and apply the configuration:

```bash
docker compose up -d --no-build
```

`APP_AUTO_INSTALL=1` is not a migration mechanism. It works only with an empty
database and never recreates existing tables.

### Image upgrades and rollback

Back up MySQL and runtime volumes before an upgrade. Set `APP_IMAGE_REF` to the
verified `repository@sha256:...` (or clear it and set `APP_IMAGE_TAG` to the exact
version), pause cron/queue workers and drain processing jobs, then run:

```bash
docker compose pull app cron
docker compose up -d --no-build app
docker compose ps
docker compose logs --tail=200 app
```

The new container records lifecycle drift before Apache starts serving traffic.
When the schema is unchanged and the installed CMS version is a supported source,
normal routes stay available while the Configurator offers a one-click health
check that records the new CMS version. A confirmed schema mismatch, unsupported
source version, invalid lifecycle marker, or active update still gates normal
routes with HTTP 503 while the Configurator remains available. A schema update
creates a verified SQL backup and runs bundled migrations; a code-only update
skips both. Then start `cron` with
`docker compose up -d --no-build cron`. The Configurator never replaces Docker
image files or accesses the Docker socket. Changes made directly inside the old
container are not preserved; keep custom code in a derived image, local module,
custom theme, or an explicit volume.

Restore the previous image only if the schema/runtime state is still compatible.
After a schema migration has started, even an unchanged schema version is not
proof that DDL did not commit. Use the verified full-backup recovery procedure
or resume the idempotent migration; there is no automatic schema downgrade.

The Configurator and CLI can create a streamed, checksum-verified SQL backup.
Use `php bin/backup.php` without arguments to display the create, verify, list,
delete, and CLI-only restore commands. Docker updates use an exact image tag;
shared hosting uses the official release archive through `php bin/update.php`.

Before a schema-changing Docker update, create and independently verify a
backup. Keep the returned `id` outside the container logs:

```bash
docker compose exec -T app php bin/backup.php create
docker compose exec -T app php bin/backup.php verify <backup-id>
```

The common update service also creates and verifies a backup automatically
before its first database-changing migration. A `code-only` update intentionally
creates no SQL backup and runs no migration. If an update stops, inspect
`/_cfg?update` or `php bin/update.php status`, then resume the recorded run; do
not start a second update or enable auto-install.

Test recovery in a separate empty database before an emergency restore. The
target name must be repeated explicitly and restoring into the configured live
database is rejected unless the separate `--allow-current-database` safeguard is
also supplied:

```bash
docker compose exec -T app php bin/backup.php restore <backup-id> \
  --target-host=database:3306 \
  --target-database=hscript_restore_test \
  --target-user=hscript_restore \
  --confirm-target=hscript_restore_test
```

Provide the target password through `RESTORE_DB_PASSWORD_FILE` (preferred) or
`RESTORE_DB_PASSWORD`. The target is required to be empty unless the operator
adds `--allow-non-empty-target` explicitly.

Automated disaster recovery is provided by the separate `recovery` service. It
still invokes `bin/backup.php`, creates a checksum/allowlist runtime archive,
keeps SQL and runtime bundles in `backup/`, applies local retention, and restores
the latest local bundle into a random isolated database for read-only verification.
It remains disabled until row bounds, RPO/RTO and drill database are configured.
Copying, encryption and retention on another server are managed by external
operator tools, not by H-Script. Operator runbooks are maintained locally and
are not included in the source repository or release archives.

Application, API, HTMX, queue, cron, telemetry, database, payment and recovery
events use one bounded NDJSON schema and correlation ID. Low-cardinality metrics
are written separately for an operator-managed collector; process/readiness
checks remain CLI-only. Alert `runbook` fields identify sections in the local
operator documentation; they are not public application URLs or bundled files.

## Docker build from source

Use this mode for development or private changes:

```bash
git clone https://github.com/0x241/h-script.git
cd h-script
cp docker/env.example .env
docker network create external-proxy-network
```

Use local image settings:

```env
APP_IMAGE=h-script
APP_IMAGE_TAG=local
APP_PULL_POLICY=build
```

Build and start the stack:

```bash
docker compose up -d --build
```

The Dockerfile runs `composer install` and compiles Tailwind in a Node 20 build
stage. Node.js and `node_modules` are not included in the runtime image.

## VPS without Docker

Requirements:

- Linux with Nginx or Apache and PHP-FPM 8.4
- PHP extensions: `curl`, `dom`, `gd`, `mbstring`, `pdo_mysql`, `simplexml`,
  `sodium`, and `opcache`; the `redis` extension is optional
- MySQL 8.4 or a compatible MariaDB release
- Composer 2
- Node.js 20 only when compiling CSS from source
- HTTPS and system cron

Prepare the application:

```bash
git clone https://github.com/0x241/h-script.git
cd h-script
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run css:build
```

The web root must point to the project root containing `rw.php` and `.htaccess`.
The PHP-FPM user needs write access to these runtime directories:

```text
logs/
tpl_c/
upload/
compile/
backup/
.cfg/
```

The first installation also needs permission to create `_config.php` and
`module/_config/pass`. After installation, restrict those files to `0640`.
Do not grant `0777` to the entire project.

For Apache, enable `mod_rewrite`, `mod_headers`, and `AllowOverride FileInfo
Options`. The project `.htaccess` blocks private directories and script execution
inside `upload/`.

For Nginx, configure the front controller and reproduce the access restrictions:

```nginx
location / {
    try_files $uri $uri/ /rw.php?$query_string;
}

# Deny regexes must precede the generic PHP handler (first matching regex wins).
location ~* ^/(?:\.git|\.agents|\.codex|\.cfg|backup|compile|docker|lib|logs|module|tpl|tpl_c|vendor)(?:/|$) {
    deny all;
}

location ~ ^/upload/.*\.(?:php|phtml|php[0-9]?|phps|cgi|pl|fcgi)$ {
    deny all;
}

location ~ ^/(?:_config\.php|composer\.(?:json|lock)|memory\.md|tasks\.md)$ {
    deny all;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

Open `https://example.com/_cfg`, configure the database, and run the initial
installation. Add a scheduler call every minute:

```cron
* * * * * curl -fsS --max-time 50 -X POST -H "X-HScript-Cron-Token: $CRON_TOKEN" https://example.com/cron >/dev/null
```

If Redis is unavailable, set `REDIS_ENABLED=0`; the application continues
without cache. Otherwise configure `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB`,
`REDIS_PASSWORD`, and `REDIS_PREFIX`.

## Shared hosting without Docker

Shared hosting is suitable only when it provides PHP 8.4, MySQL, cron, HTTPS,
and control over the web root or `.htaccess`. Redis is optional.

Use a production release archive that already contains:

- `vendor/` from a production Composer install
- compiled `static/css/app.css`
- application files without `.env`, `_config.php`, dumps, or local secrets

A normal GitHub source ZIP is not sufficient on hosting without SSH and Composer
because `vendor/` is not committed.

Download `h-script-<version>-shared-hosting.tar.gz` and `SHA256SUMS` from the
release pipeline artifacts. Maintainers can build the same compiled package
locally with Docker. The `shared-release` target emits only the archive and
checksum:

```bash
docker buildx build \
  --target shared-release \
  --build-arg APP_VERSION=1.0.7 \
  --output type=local,dest=dist \
  .
(cd dist && sha256sum -c SHA256SUMS)
```

Installation procedure:

1. Create a dedicated MySQL database and user.
2. Extract the release into the site root.
3. Grant write access only to the runtime directories listed above.
4. Confirm that `.htaccess` is active and private files return HTTP 403.
5. Open `/_cfg`, save the database connection, and run initial installation.
6. Restrict configuration files to `0640`.
7. Set a random `CRON_TOKEN` of at least 32 characters in the PHP environment and hosting scheduler. Configure the scheduler to POST to `/cron` with the `X-HScript-Cron-Token` header every minute.
8. Open **Admin > Settings > Scheduler**, enable the scheduler,
   save it, and use the manual trigger once. The manual button submits an
   administrator form with CSRF protection; it does not expose `CRON_TOKEN`.
9. Confirm that at least one module timestamp changes in scheduler statistics.
10. Test registration, login, mail delivery, and Turnstile over HTTPS.

Before an upgrade, back up MySQL, `upload/`, `_config.php`, and
`module/_config/pass`. Prefer the authenticated Configurator update page and an
official shared-hosting release archive: it verifies the archive, preserves
declared runtime paths, creates a verified backup before database changes, and
leaves a stopped run resumable. Never delete runtime data or use auto-install as
an updater. For CLI diagnosis use `php bin/update.php status`; test SQL recovery
against a separate empty database with `php bin/backup.php restore` before any
live restore.

## Docker environment variables

The complete reference is `docker/env.example`.

### Application and image

| Variable | Purpose |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | Environment and diagnostic output; use `production` and `0` in production. |
| `APP_IMAGE` | Local image name, Docker Hub repository, or GHCR package. |
| `APP_IMAGE_TAG` | Exact release tag, for example `1.0.7`. |
| `APP_IMAGE_REF` | Optional `repository@sha256:...`; overrides the image/tag pair. Staging always sets it. |
| `APP_PULL_POLICY` | `build` for source builds or `always` for registry images. |
| `APP_DOMAIN` | Public domain without a scheme; keep it stable after installation. |
| `APP_SYS_ID` | Stable system secret/identifier; keep it stable after installation. |
| `APP_SYS_MAIL` | Default installation email; used for the main administrator, sender and system-notification fallbacks unless their dedicated overrides are set. |
| `APP_CFG_LINK` | Configurator path; defaults to `_cfg`. |
| `APP_DEMO_MODE` | Enables demo restrictions without rewriting existing users. |
| `APP_BIND_IP` | Host IP used for the published application port; defaults to `127.0.0.1`. Use the server's Tailscale IPv4 for private remote access. |
| `APP_PORT` | Host port mapped to container port 80. |
| `APP_EXTERNAL_NETWORK` | Existing external reverse-proxy network. |
| `APP_NETWORK_ALIAS` | Application alias on the external proxy network. |
| `TRUSTED_PROXY_CIDRS` | Exact trusted reverse-proxy CIDRs allowed to supply forwarded headers; defaults to loopback only. |
| `OBSERVABILITY_LOG_PATH`, `OBSERVABILITY_METRICS_PATH` | Absolute operator-only NDJSON paths consumed by the external logs/metrics collector. |
| `NDJSON_ROTATED_FILES`, `NDJSON_RETENTION_DAYS`, `CSP_LOG_MAX_BYTES` | Rotation count (1–100, default 7), required observation days (1–90, default 7), and CSP per-file byte bound (default 20 MiB). |
| `OBSERVABILITY_LOG_MAX_BYTES`, `OBSERVABILITY_METRICS_MAX_BYTES` | Per-file bounds; active file plus `NDJSON_ROTATED_FILES` private generations (default 7). |
| `OBSERVABILITY_CRON_MAX_AGE_SECONDS` | Maximum age of the last application cron heartbeat. |
| `OBSERVABILITY_QUEUE_MAX_DEPTH`, `OBSERVABILITY_QUEUE_MAX_AGE_SECONDS`, `OBSERVABILITY_QUEUE_MAX_FAILED` | Queue readiness and alert thresholds. |
| `OBSERVABILITY_DNS_BACKLOG_MAX` | Maximum collector DNS verification backlog. |
| `OBSERVABILITY_DB_SLOW_MS` | Slow-query event threshold; query text and parameters are never logged. |
| `OBSERVABILITY_ALERT_WINDOW_SECONDS`, `OBSERVABILITY_HTTP_ERROR_THRESHOLD`, `OBSERVABILITY_SESSION_FAILURE_THRESHOLD` | External-collector evaluation window and request/session thresholds. |
| `OBSERVABILITY_TELEMETRY_FAILURE_THRESHOLD`, `OBSERVABILITY_GATEWAY_FAILURE_THRESHOLD` | External-collector failure thresholds for telemetry and gateway attempts. |
| `OBSERVABILITY_ALERT_COMMAND` | Optional absolute fail-open notifier executable receiving a restricted alert JSON path. |
| `TELEMETRY_ENDPOINT` | Installation collector; defaults to `https://h-script.com/api/v1/installations`. |
| `INSTALL_TELEMETRY_STATS` | `1` sends public aggregates; `0` sends mandatory installation data only. |
| `TELEMETRY_COLLECTOR_ENABLED` | Enables incoming collector APIs on the central instance only. |
| `TELEMETRY_INGESTION_ENABLED` | Independently enables authenticated registration/report ingestion on the central collector only. |
| `TELEMETRY_COLLECTOR_DOMAIN` | Domain allowed to host collector APIs and administration. |
| `TELEMETRY_RATE_LIMIT` | Collector requests per IP per minute; defaults to `30`. |

`APP_SYS_ID` must remain stable because it identifies the installation.
Docker database credentials are read directly from environment variables or
mounted secret files and are not persisted in `_config.php`.

For staging, create an environment-scoped GitLab variable named `APP_BIND_IP`
with the server's Tailscale IPv4 (normally in `100.64.0.0/10`). Compose then
publishes `${APP_PORT}` only on that address. Keep the `127.0.0.1` default when
the port should be reachable only from the host; the external proxy network can
still reach the `app` service directly on container port 80.

When a separate Nginx/Authelia gateway reaches H-Script over Tailscale, also
create the environment-scoped GitLab variable `TRUSTED_PROXY_CIDRS` with the
gateway's exact Tailscale address and `/32` suffix. It is not a secret and does
not need masking, but it should be protected with the deployment environment.
Do not use the whole Tailscale or private-network range.

Verify the source IP actually seen by the application. Tailscale forwarding SNAT
can replace the gateway address with a Docker bridge address; with HTTPS enforced,
this causes repeated redirects to the same HTTPS URL because forwarded headers
are correctly rejected. Preserve the gateway source address in the network path
instead of trusting an entire Docker subnet. On a dedicated application host,
`tailscale set --snat-subnet-routes=false` can preserve it, but review advertised
subnet/exit routes and return routing before changing this host-wide setting.

An optional gateway VPS can run both public Nginx and Authelia, require 1FA or
2FA only for chosen routes, and proxy H-Script to its second VPS over a private
Tailscale connection. H-Script does not embed or run Authelia, and its built-in
authorization remains active. The two-server setup, proxy-bypass controls and
machine-route exclusions have complete copy-ready examples in
[`docker/authelia/`](docker/authelia/README.md).

### Database, Redis, and scheduler

| Variable | Purpose |
| --- | --- |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Application MySQL connection. |
| `MYSQL_ROOT_PASSWORD` | MySQL container root password. |
| `DB_TYPE` | H-Script SQL driver type; the supplied MySQL/PDO setup uses `1`. |
| `REDIS_ENABLED` | `1` enables caching; `0` selects fail-open no-cache operation. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB` | Redis connection. |
| `REDIS_PASSWORD`, `REDIS_PREFIX` | Redis password and key prefix. |
| `CRON_ENABLED` | Enables the separate Compose `cron` service. |
| `CRON_TOKEN` | Required random secret (at least 32 characters) shared by the application and scheduler; set it as a protected GitLab variable for staging deployment. |
| `CRON_INTERVAL_SECONDS` | Scheduler interval; defaults to 60 seconds. |
| `CRON_URL`, `CRON_HOST_HEADER` | Internal scheduler URL and optional Host header. |
| `CRON_TIMEOUT_SECONDS`, `CRON_START_DELAY_SECONDS` | Per-call timeout and initial delay. |

### Backup and update

| Variable | Purpose |
| --- | --- |
| `BACKUP_STORAGE_PATH` | Absolute backup directory outside the document root when possible; an in-root directory must have effective web access denial. |
| `BACKUP_MAX_BYTES`, `BACKUP_MIN_FREE_BYTES` | Hard archive-size and remaining-free-space limits checked before publication. |
| `BACKUP_RETENTION_COUNT`, `BACKUP_RETENTION_DAYS` | Local retention limits; an unfinished update protects its attached backup. |
| `BACKUP_MYSQLDUMP_PATH`, `BACKUP_MYSQL_PATH` | Optional absolute paths to dump and restore clients. |
| `RESTORE_MAX_STATEMENT_BYTES` | Maximum statement size for the bounded PDO streaming fallback when the system MySQL client cannot authenticate to MySQL 8.4. |
| `RECOVERY_ENABLED`, `RECOVERY_BACKUP_INTERVAL_SECONDS` | Enables the recovery scheduler and sets its interval; keep it within the strictest configured RPO. |
| `RECOVERY_RUNTIME_ROOT`, `RECOVERY_RUNTIME_PATHS` | Runtime source and exact supported allowlist (`upload` plus custom themes by default). |
| `RECOVERY_INCLUDE_PROTECTED_CONFIG` | Explicit opt-in for exact configuration/hash paths; `.env` remains forbidden. |
| `RECOVERY_*_RPO_SECONDS`, `RECOVERY_*_RTO_SECONDS`, `RECOVERY_DRILL_MAX_AGE_SECONDS` | Environment-owned objectives for CMS, collector and service-token registry plus maximum drill age. |
| `RECOVERY_ROW_BOUNDS_FILE`, `RECOVERY_DRILL_DB_*` | Environment row-count policy and credentials for randomly named disposable drill databases. |
| `RECOVERY_ALERT_COMMAND` | Optional absolute fail-open notifier executable receiving only the restricted alert-file path. |
| `UPDATE_WORK_PATH` | Protected work directory for packages, journals, baselines, and resumable run state. |
| `UPDATE_MAX_PACKAGE_BYTES`, `UPDATE_MAX_UNPACKED_BYTES`, `UPDATE_MAX_FILES` | Package and extraction limits. |
| `UPDATE_MIN_FREE_BYTES`, `UPDATE_RETENTION_COUNT` | Update free-space and prepared-package retention limits. |

Backup manifests contain the SHA-256 checksum, exact schema/application
versions, table inventory, adapter, and verification status. Partial archives
are never published. `RESTORE_DB_PASSWORD` and `RESTORE_DB_PASSWORD_FILE` apply
only to the explicitly named restore target and are not application settings.

Backup storage contains sensitive SQL and may contain protected configuration.
The `1.0.7` runtime uses owner-only directories (`0700`) and files (`0600`);
Docker startup tightens existing files in `/var/www/shared/backup`. Run manual
backup/recovery commands as the application user (`--user www-data` in Docker),
not root, so the web updater can read its private archives. Apache denies both
`/backup/` and the resolved volume. An external nginx serving these files itself
must also deny that location; `.htaccess` alone does not protect nginx.
Never mount backup storage into a public file server. Configurator downloads
require its separate authenticated session and a valid CSRF token.

These restrictions do not protect against a compromised root/application account
or loss of the host. Keep independent copies with the external backup tools you
operate; H-Script does not copy backups to another server or encrypt archives.

### Security and initial installation

| Variable | Purpose |
| --- | --- |
| `CONFIGURATOR_PASSWORD` | Independent configurator password; mandatory in production. |
| `CONFIGURATOR_ALLOWED_CIDRS` | Optional comma-separated IPv4/IPv6 CIDR allowlist for the Configurator. |
| `APP_DATA_KEY` | Long random key for authenticated encryption of persisted integration secrets; mandatory in production. |
| `ALLOW_EMPTY_CONFIGURATOR_PASSWORD` | Local-only exception; keep `0` in production. |
| `REQUIRE_TURNSTILE` | Requires configured Turnstile keys at startup. |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | Public and secret Turnstile keys. |
| `SESSION_COOKIE_SECURE`, `SESSION_COOKIE_SAMESITE`, `SESSION_GC_MAXLIFETIME` | Session cookie policy and server-side lifetime in seconds; the default lifetime is 30 days while application idle limits remain authoritative. |
| `APP_AUTO_INSTALL` | One-time schema bootstrap on an empty database; return to `0`. |
| `INSTALL_ADMIN_*` | First main administrator credentials, PIN, and secret answer. |
| `INSTALL_DEMO_ADMIN_*` | Separate demo administrator when demo mode is enabled. |
| `INSTALL_MAIL_HOST`, `INSTALL_MAIL_PORT` | Initial SMTP endpoint. |
| `INSTALL_MAIL_SECURE` | `1` enables STARTTLS, or SMTPS when port 465 is selected. |
| `INSTALL_MAIL_USERNAME`, `INSTALL_MAIL_PASSWORD` | Initial SMTP credentials; both may be empty only for a trusted unauthenticated relay. |
| `INSTALL_MAIL_FROM_ADDRESS` | Optional initial `Sys.NotifyMail` sender override; defaults to `APP_SYS_MAIL`. |
| `INSTALL_MAIL_ADMIN_ADDRESS` | Optional initial `Sys.AdminMail` notification destination override; defaults to `INSTALL_ADMIN_MAIL`, then `APP_SYS_MAIL`. |
| `INSTALL_MAIL_ADMIN_LANG` | Initial administrator email language, such as `en` or `ru`. |
| `INSTALL_NO_LOGINS`, `INSTALL_INT_CURR`, `INSTALL_INT_CURR_ID` | Initial login policy and internal currency. |
| `INTEGRITY_SCAN_BATCH_FILES`, `INTEGRITY_SCAN_MAX_SECONDS` | Bound one local file-integrity pass; an interrupted scan resumes from its saved cursor. |
| `INTEGRITY_SCAN_MAX_FILES`, `INTEGRITY_SCAN_MAX_FINDINGS` | Hard limits for scanned files and persisted findings. |
| `INTEGRITY_NOTIFY` | `1` sends one generic administrator notification for each new critical change set. |

After the first successful installation, the following GitLab variables are no
longer needed: `APP_INSTALL_FORCE` (removed completely), `APP_AUTO_INSTALL`, and
all `INSTALL_*` bootstrap variables. `APP_AUTO_INSTALL` may instead remain
explicitly set to `0`. Variables whose names start with `UPDATE_` are current
only when they also appear in `docker/env.example`; old feed, channel,
public-key, or signature variables are not read by the updater.

The authenticated Configurator **Security** page shows production preflight,
official-release file integrity and a redacted action audit. All Twig templates
are customizable and do not trigger a critical alert by themselves. Unexpected
executable files in writable or template directories remain critical. An
operator can start a scan there; the open page continues bounded, CSRF-protected
batches automatically without repeated Resume clicks. Closing the page leaves
the saved cursor for cron, which checks daily and resumes unfinished work after
five minutes. A changed verified release baseline starts a fresh scan rather
than reusing the old cursor. Cron is unavailable while a required schema update
blocks public traffic, but the authenticated Security page can still scan files.
Network/authentication errors stop automatic requests and leave a manual retry.
Every finding
includes the relative file path, modification time and check time. Critical
changes remain visible in both the Configurator and the main administration
header until the files are restored, even after acknowledgement. The installed
integrity baseline changes only after a verified official update is activated
successfully.

Sensitive values support Docker-style `*_FILE` variants, including
`APP_DATA_KEY_FILE`, `DB_PASSWORD_FILE`, `MYSQL_ROOT_PASSWORD_FILE`,
`CONFIGURATOR_PASSWORD_FILE`, `TURNSTILE_SECRET_KEY_FILE`, and
`INSTALL_MAIL_PASSWORD_FILE`. Do not set a plain value and its `_FILE` variant
at the same time.

#### Session variable in GitLab

The staging job requires `APP_DATA_KEY`; use a protected, masked value of at
least 32 random bytes and never rotate it without re-encrypting saved payment
and wallet parameters. It also forwards `SESSION_GC_MAXLIFETIME`; add that value
when the server-side lifetime should be explicit in GitLab:

| Variable | Recommended value | GitLab type | Visibility | Protected | Expand variable reference |
| --- | --- | --- | --- | --- | --- |
| `APP_DATA_KEY` | output of `openssl rand -base64 32` | Variable | Masked | Yes | Off |
| `SESSION_GC_MAXLIFETIME` | `2592000` | Variable | Visible | Yes | Off |

Use environment scope `staging` (and a separate production-scoped value when a
production deploy job is added). This value is not a secret. No new CI/CD
variables are required for ePochta: its API v3 public/private keys are stored
through **Admin > Settings > SMS**, and an empty private-key field preserves the
already configured secret.

#### Initial mail variables in GitLab

Mail variables seed `Cfg` only during the first empty-database bootstrap with
`APP_AUTO_INSTALL=1`. With `APP_AUTO_INSTALL=0`, redeployment does not overwrite
mail settings changed in administration. Existing installations should either
keep using **Admin > Settings > Mail** or intentionally recreate an empty
database; auto-install must not be enabled as an update mechanism.

Create the GitLab variables with environment scope `staging` for the staging
deployment and use the following policy. The `stage/docker-release` branch must
itself be protected before variables marked **Protected** are available to its
pipelines.

Do not create `INSTALL_ADMIN_MAIL`, `INSTALL_MAIL_FROM_ADDRESS`, or
`INSTALL_MAIL_ADMIN_ADDRESS` when all three roles use `APP_SYS_MAIL`; the deploy
job and installer apply those fallbacks automatically. Add only the override
whose address must differ. The SMTP username is a provider credential and does
not have to match any of these addresses.

| Variable | GitLab type | Visibility | Protected | Expand variable reference |
| --- | --- | --- | --- | --- |
| `INSTALL_MAIL_HOST` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_PORT` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_SECURE` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_USERNAME` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_PASSWORD` | Variable | Masked | Yes | Off |
| `INSTALL_MAIL_FROM_ADDRESS` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_ADMIN_ADDRESS` | Variable | Visible | Yes | Off |
| `INSTALL_MAIL_ADMIN_LANG` | Variable | Visible | Yes | Off |

Use a normal masked `Variable`, not a GitLab `File`, for
`INSTALL_MAIL_PASSWORD`: the staging job writes a permission-restricted Compose
environment file and does not mount GitLab runner files into the container. The
job encodes the value into the internal `INSTALL_MAIL_PASSWORD_B64` transport
variable, so Docker Compose cannot reinterpret password characters such as `$`;
do not create the `_B64` variable manually in GitLab. Keep GitLab variable
expansion disabled as an additional safeguard. For standalone Docker
deployments, `INSTALL_MAIL_PASSWORD_FILE` remains available when the referenced
secret file is mounted inside the application container.

## Installation registry and telemetry

Telemetry has two layers:

1. Mandatory system registration sends the domain, H-Script version,
   installation date, and a random installation ID. A daily authenticated
   heartbeat repeats the installation identity, current version and report
   sequence. This layer cannot be disabled in the installer or administration
   interface.
2. Public statistics are enabled by default and send numeric aggregates such as
   user counts, online users, incoming funds, payouts, and other totals grouped
   by currency. They can be disabled independently in
   `/admin/setup/telemetry`.

Logins, email addresses, user IPs, individual operations, payout destinations,
passwords, gateway keys, settings, and database content are not transmitted.
The collector derives the source server IP from the request; clients cannot
submit an `ip` field. It retains at most 20 unique addresses per installation
and removes observations older than 90 days. Passive DNS follows bounded
A/AAAA/CNAME lookups and records `matched`, `mismatch`, `unresolved`, or
`invalid` with a cached snapshot. A DNS match controls aggregate eligibility;
it is not proof of domain ownership. Installation owners should disclose
mandatory registration in their own documentation and privacy policy.

Docker installation defaults:

```env
INSTALL_TELEMETRY_STATS=1
TELEMETRY_ENDPOINT=https://h-script.com/api/v1/installations
```

Set `INSTALL_TELEMETRY_STATS=0` to transmit mandatory installation information
without public aggregates. Collector downtime does not block login, financial
operations, or scheduler execution; reporting is fail-open and records the last
status and next attempt for diagnostics. The daily cron task retries the same
persisted payload and sequence, so an interrupted delivery is idempotent.

### Central collector on h-script.com

Only the central instance should enable collector mode:

```env
TELEMETRY_COLLECTOR_ENABLED=1
TELEMETRY_INGESTION_ENABLED=1
TELEMETRY_COLLECTOR_DOMAIN=h-script.com
TELEMETRY_RATE_LIMIT=30
```

These values are ordinary visible CI/CD variables. They are configuration, not
secrets, so they do not need masking. Do not restore the removed shared
`TELEMETRY_READ_TOKEN` design.

Collector mode additionally requires the request host to match
`TELEMETRY_COLLECTOR_DOMAIN`, the explicit ingestion flag, and schema `1.0.1`.
This prevents a normal installed copy from exposing the central registry merely
because a local administrator has `uLevel=99`. Registration and daily reports
accept only exact JSON fields and types, enforce bounded values, require the
installation Bearer token, reject replay/conflicting sequences, and record the
server-observed source IP. Demo users never authorize these endpoints; demo mode
only forces optional business aggregates off for the installation-side sender.

Roles are intentionally separate:

- `uLevel=99`: main collector administrator; manages installations, collector
  accounts, and token ownership.
- `uLevel=10`: external collector consumer; can issue, reissue, pause, and revoke
  only its own `hst_` service tokens.
- `uLevel=90` and ordinary administrators: no collector token authority.
- Demo administrators never see collector or telemetry management data.

Service tokens use this format:

```text
hst_<64 lowercase hexadecimal characters>
```

Only the SHA-256 hash is stored. The plaintext secret is shown once. The main
administrator may issue a token only for an active `uLevel=10` account and sees
a collector-account summary. Each consumer should receive its own expiring
token so it can be revoked and audited independently.

Public aggregate endpoint:

```text
GET /api/v1/installations/public-stats
```

Protected service endpoint:

```text
GET /api/v1/installations/stats?page=1&per_page=25
Authorization: Bearer hst_<secret>
```

The protected list accepts `per_page=25|50|100` and filters `q`, `version`,
`connection`, `sharing`, `dns_status`, and exact normalized `ip`. The web
collector uses an independent `token_page`/`token_per_page` namespace for the
service-token list. Pages are stably ordered and capped at 100 rows; overall
summaries are separate aggregate queries. Token hashes and raw report bodies
are never selected. Full source IP, bounded IP history, and DNS snapshots are
shown only to a collector administrator with `telemetry_sensitive`; the service
API omits them.

Installation-side `hsi_` tokens authenticate only registration and heartbeat;
they cannot read collector data. Service `hst_` tokens cannot submit installation
reports.

The landing-page counters use collector `processed` and `platforms` values.
Public financial totals are self-reported and therefore are not independently
verified. The collector canonicalizes domains and counts only one active
installation with `dns_status=matched` per canonical domain to limit simple
duplication, but this passive DNS observation does not prevent an owner from
reporting false values. Future verified metrics require a separate ownership
challenge and an anti-fraud policy. Until that exists, label public totals as
reported aggregates rather than audited data.

After deploying collector tables for the first time, back up the database and
run the explicit configurator schema update once. Do not use auto-install or
forced installation as an update mechanism.

## Cryptocurrency rates

The balance scheduler loads keyless cryptocurrency exchange rates from the
Coinbase Data API over verified HTTPS. Enable individual currency updates in
**Admin > Balance > Settings**. A failed response does not overwrite the last
valid stored rate.

## REST API v1

Base path:

```text
/api/v1
```

Main resources:

| Method | Endpoint | Scope |
| --- | --- | --- |
| `GET` | `/api/v1/user` | `user:read` |
| `GET` | `/api/v1/balance` | `balance:read` |
| `GET` | `/api/v1/operations` | `operations:read` |
| `POST` | `/api/v1/deposit` | `deposit:write` |
| `POST` | `/api/v1/withdraw` | `withdraw:write` |

Use a per-user bearer token created in **Account > API access** or by an
administrator in **Settings > API**:

```http
Authorization: Bearer hs_<secret>
Accept: application/json
Content-Type: application/json
```

Token plaintext is shown once; only a hash is stored. Tokens support scopes,
expiration, suspension, reactivation, and permanent revocation.

Financial POST requests require `Idempotency-Key` (1–128 ASCII letters/digits
and `:._-`). Use a new key for each intended operation and the same key/body for
retries. Exact successful retries return the original status/data; changed
normalized input returns 409, and a missing/invalid key returns 400. Deposit
creation returns an authenticated cabinet `payment.checkout_url`; withdrawals
reserve funds and queue provider delivery (202/processing). Read operations for
current state.

New monetary API projections and financial POST snapshots use decimal strings:
wallet buckets/totals, operation amount/fee/net and currency limits retain all
six stored places (for example `"0.000001"`), independently of currency display
scale. Fee percentages retain their stored precision. Totals and net amounts
are calculated exactly and never serialized in scientific notation. Previously
completed idempotency receipts keep their original JSON values and types,
including numeric amounts; retries do not rewrite or reformat those snapshots.

Response envelope:

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {
    "timestamp": 1786380000,
    "version": "1.0"
  }
}
```

Rate limiting is applied independently by client IP and token. Responses include
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`. The API is
stateless and does not create a browser session cookie.

## Development

Install dependencies and build CSS:

```bash
composer install
npm ci
npm run css:build
```

Watch Tailwind during UI work:

```bash
npm run css:watch
```

Application classes live in `src/` and use the `HScript\` namespace. Add a new
payment integration by implementing `PaymentGatewayInterface`, placing the class
in `src/Payment/Gateways/`, registering active IDs in `PaymentManager`, and adding
callback, deposit, withdrawal, and signature-validation tests.

### Financial test stand and phase 1 checks

The faker is `tests/fixtures/financial_stand.php`. Run it only on the local
`hs.local` copy, after creating and verifying a database backup. From
`/Users/blackparads/Documents/HS/origin/docker-compose-lamp`:

```sh
docker compose exec -T -e APP_DOMAIN=hs.local webserver php /var/www/html/bin/backup.php create
# Verify the returned backup ID before seeding:
docker compose exec -T -e APP_DOMAIN=hs.local webserver php /var/www/html/bin/backup.php verify <backup-id>
docker compose exec -T -e APP_ENV=local -e APP_DOMAIN=hs.local -e H_SCRIPT_FINANCIAL_FIXTURE=1 webserver \
  php /var/www/html/tests/fixtures/financial_stand.php --users=256 --rounds=12
docker compose exec -T -e APP_ENV=local -e APP_DOMAIN=hs.local -e H_SCRIPT_FINANCIAL_FIXTURE=1 webserver \
  php /var/www/html/tests/fixtures/financial_stand.php --status
```

The default profile creates 256 basic accounts, USD/EUR/BTC operations, four
plans, deposits, referrals, cancellations and extensive fake content. Accounts
are marked `zz-fixture7-*`; content is marked `[FAKE-A7]`. A completed rerun is
idempotent. For marker-only cleanup use the same command with `--clear`; it
refuses cross-links to original records. Notifications and external payouts are
stubbed. Never run this against production or staging, and never use the
installer to reset an existing database.

Run the full phase 1 suite in disposable databases, without changing hs.local:

```sh
sh www/h-script-3/tests/financial_isolated.sh <application-image> mysql:8.4
sh www/h-script-3/tests/financial_isolated.sh <application-image> mariadb:10.11
sh www/h-script-3/tests/financial_isolated.sh <application-image> mariadb:11.4
```

This includes financial characterizations, phase-2 concurrency/failure tests,
snapshot fidelity/replay and faker lifecycle/cleanup checks. Known financial
defects remain explicit findings.

For registry crash/retry checks run `tests/idempotency_isolated.sh` with the
same application/database image arguments. For actual REST/callback/cabinet/admin
retries and application restart, use a cached Playwright image:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=<playwright-image> \
  sh www/h-script-3/tests/financial_http_isolated.sh <application-image> mysql:8.4
```

All runners create isolated databases/internal networks and clean up their own
resources. The HTTP fixture seeds only its fresh database and does not seed on
restart.

The financial matrix also runs `tests/financial_state_recovery.php`. Real SQL
triggers model a rejected update, a write that changes no row, and a write that
leaves the previous operation state. Confirmation, completion and cancellation
must roll back their financial effects when any intended field is not saved.
Receipt fields are verified under both changed-row and matched-row PDO semantics;
an identical CASHIN confirmation remains a valid no-op. The tests check deposit
top-up/withdrawal/close rollback and failures before transaction start, before
commit and after a successful commit whose response was lost. Only committed
results replay through a fresh connection; uncommitted work can retry. Every scenario checks all fixture
tables and reconciles the exact expected findings, including the separately
classified legacy BAL audit limitation. These faults exist only in guarded tests
on disposable databases; production code has no fault switch.

`tests/financial_concurrency.php` uses separate PHP workers and real SQL locks
to exercise distinct withdrawals/transfers, opposite transfers with a database
deadlock, competing deposit top-ups/withdrawals, duplicate accrual boundaries,
and callback/completion versus cancellation. A controlled overlapping session
commits a top-up or withdrawal after the accrual's initial owner lookup; the
accrual must use the current locked principal and state. Both terminal and
nonterminal periods, failed requests and fresh-process retries are reconciled
with exact expected balances and audit IDs. Barriers exist only in this guarded
fixture test. No production timing switch or schema migration is required.

`tests/financial_deposit_current.php` additionally checks opening limits and
group prerequisites after another SQL session commits, including hidden group
members and changed Plan policy. Nested callers use current deposit rows while
retaining transaction ownership; caller rollback preserves the other session's
committed changes. Ordinary UI reads retain their snapshot. These checks refresh
SQL deposit/Plan rows, without changing request configuration or exchange rates.

`tests/financial_manual_batch.php` runs distinct manual incoming/outgoing
settlements against the same currency and batch. SQL batch equality, including
case and the database collation, is preserved. Concurrent requests can complete
one source only; rejected requests retain their pending reservation. Both
REPEATABLE READ and READ COMMITTED, existing caller snapshots, genuine late SQL
failures and fresh-process success/refusal retries are checked on fixture data.
No provider request or queue worker runs in this suite.

`tests/financial_registration_once.php` checks one automatic bonus per account,
including aliases of the numeric user ID, changed bonus/rate/currency settings,
parallel activation calls, caller rollback and a late SQL failure after an earlier
currency and queued notification. Valid supported currencies keep their existing
rounding rules; invalid or unsupported amounts are skipped. All bonus currencies
and their durable receipt commit together. Registration activation, PIN, history
and queued notifications share the originating transaction. The confirmation
code is consumed only with that commit; a failed registration can retry its
unused code. Parallel activation keeps the original PIN and one registration
history. Session cookies and redirects follow the owner commit.
Completed legacy BONUS rows with memo `Bonus` have no registration event binding:
a current read preserves them and records `legacy_bonus_unbound` instead of
issuing more money or inventing historical provenance. Review ambiguous legacy
awards before making any additional manual payment. No historical row is repaired.
To test the actual previous-handler cutover, set
`H_SCRIPT_FINANCIAL_REGISTRATION_LEGACY_IMAGE` to an immutable application image
from before this guard when running the isolated matrix; both producer and
candidate use the same fresh fixture database, never the live installation.

`tests/financial_registration_recovery.php` checks genuine late SQL failures
at bonus insertion and final confirmation consumption, current used/expiry/IP
and blocked-account guards, nested caller rollback and normal source retries.
Previously consumed legacy confirmation codes and missing old awards are not
repaired automatically; investigate them through the read-only escalation process.

The matrix also runs generated sequential properties and a least-privilege
reconciliation CLI suite. Frozen seeds exercise currency scales 0/2/3/4/6/8,
fees, rates, operation sequences, deposit plans and valid acyclic referral trees.
A separate Python stdlib Decimal model predicts each balance and declared
source/sink (external settlement, commission, exchange, earnings or principal
return). Every successful/rejected/replayed step checks all fixture tables and
the exact known BAL audit counts and IDs. A failure prints its seed and operation
prefix for reproduction. This suite does not replace the parallel SQL tests.
To generate additional seeds, run from the local Compose root:

```sh
python3 www/h-script-3/tests/generators/financial_properties.py --seed 123 --seed 456 \
  > /tmp/financial-property-cases.json
H_SCRIPT_FINANCIAL_CANDIDATE=1 \
H_SCRIPT_FINANCIAL_PROPERTY_FILE=/tmp/financial-property-cases.json \
  sh www/h-script-3/tests/financial_isolated.sh <application-image> mysql:8.4
```

The supplied cases are mounted read-only into the disposable database runner.
The generator has no database access. Test entry points require CLI and the
fixture environment, and remain excluded from production distributions.

The HTTP suite also checks current YooMoney HMAC-SHA256 and CoinPayments signed
raw-body callbacks, tampering, original amounts, time, pending notifications
and concurrent settlement. The isolated financial runner includes
`tests/financial_webhook_contract.php` for amount/receiver/precision races,
immutable payout reservation and late authenticated replay checks.
Run `php tests/gateway_webhooks.php` for the offline verification contracts,
including registered gateways' configuration guards and the public YooMoney example.
The callback inventory is `tests/fixtures/gateway_contract_profiles.json` for
all 30 registered IDs. Automatic callbacks must pass an accepted incoming
profile; other profiles and all outgoing callbacks return unsupported.
EA/EAT/EAT1/BSC/BSCT/XRPA/TRA/TRAT, ACR and PKAU/PKAR also refuse automated
invoice, payout and balance transport, including stale saved automation flags.
Their configuration and history remain available. SB/BW retain manual review.

CoinPayments accepts IPN version 1.0 and its required identifier, raw-body
HMAC-SHA512, original merchant/key binding and exact decimal amounts. Automatic
receipt IDs are limited to 39 ASCII letters, digits or hyphens; longer provider
IDs require reconciliation before any callback claim. The checkout uses the
legacy CoinPayments payment-button endpoint. YooMoney uses HMAC-SHA256 and a
39-byte receipt-ID limit. Its original encrypted checkout freezes the receiving
wallet and notification-key hash before provider I/O, since notifications have
no signed receiver field. Missing original profiles or changed keys/receivers
require reconciliation; they are never rebuilt from current settings.

WebMoney requires SHA256 `LMI_HASH2` and an explicitly configured IANA timezone
in the ordinary SCI settings. Confirm the notification timezone for the merchant
before configuring it; the application supplies no default. The original
checkout freezes that timezone, key hash, receiving purse and UTF-8 BASE64
description. Invalid dates, daylight-saving gaps/ambiguous times and timestamps
before order creation minus 300 seconds or beyond current time plus 300 seconds
are rejected at first acceptance. Exact accepted retries retain their original
time proof. HASH-only, SIGN and positive-HOLD notifications are unsupported.
Unsigned prerequest validates an existing protected order before replying YES;
it never credits funds. Missing original proof requires reconciliation.

Paykassa crypto deposits (PKB/PKE/PKL) confirm the exact notification token
through SCI 0.4 and bind merchant, amount, asset, receiver and invoice to the
saved encrypted checkout. Original invoice, key and receiver checks run before
any verification request. A durable merchant/key budget defaults to 10 requests
per minute, with a 30-second per-order cooldown. To change the bounded limit,
set `PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE` to an integer from 1 through 1000;
invalid values fail closed. Failed or uncertain requests consume the budget.
A verified token digest is cached for seven days; exact retries avoid provider
I/O and still recheck original intent/configuration. Different tokens cannot
reuse that verification. Partial payments, old URL-only checkouts and missing
original profiles require reconciliation. Payout modernization remains separate.
Run `php tests/paykassa_callbacks.php` for socket-free transport checks; the
financial matrix includes original-invoice and quota/cache concurrency tests.

For the actual Paykassa deposit form, callbacks, concurrent settlement and
restart replay, run from the Compose root:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=<cached-playwright-image> \
  sh www/h-script-3/tests/paykassa_http_isolated.sh <application-image> mysql:8.4
```

This creates a disposable database and internal network. Its provider double
is mounted read-only outside the document root and enabled only by the test
runner; it never connects to Paykassa. No fixture hook is installed in the
production application. Release CI runs this suite against the candidate.

Volet automatic incoming settlement currently supports AC/USD, full same-asset
SCI payments with zero provider and H-Script commissions. Configure both SCI
and API credentials, including the receiving wallet. The encrypted checkout
freezes credentials, amount, precision and local commission policy; changes
require reconciliation. A signed notification is confirmed through authenticated
order and transaction lookups. Nonzero fees, conversion, partial payments, ACR
and older checkouts without this proof are unsupported for automatic settlement.
Run `php tests/volet_callbacks.php` for offline checks; the financial matrix
includes SQL tests. The isolated HTTP runner is `tests/volet_http_isolated.sh`,
using the same image arguments and browser environment as the Paykassa runner.

Automatic inbound settlement preserves the operation's agreed gross amount and
local commission. Checkout commits that intent before contacting a provider;
later percentage, minimum, maximum or amount-range policy edits apply to new
orders, while an existing invoice retains its original fee. Changes to its
operation amount, commission, currency or precision require reconciliation.
The bounded CoinPayments profile without a checkout freezes the locked
operation, merchant and key at its first authenticated acceptance; providers
that require an original invoice cannot use this path. Older invoices and callback identities without
this immutable proof require reconciliation, including completed retries;
the proof is never reconstructed from an editable receipt or current policy.
Volet's stricter zero-fee profile still applies. The financial matrix includes
`tests/financial_inbound_intent.php` with fee-policy and concurrent replay checks.
For the actual deposit/pay form, post-invoice fee edits and callback/restart
checks, run `tests/inbound_http_isolated.sh` with the same Compose-root image
arguments and browser environment as the Paykassa runner.

Run `php tests/gateway_callback_policy.php` for registry policy and frozen
sanitized wire cases. For unsupported callbacks, transaction-ID bounds and
unconfigured WebMoney timezone, run `tests/gateway_policy_http_isolated.sh`
with the same image arguments and browser environment. Each isolated HTTP
runner saves private captures under `dist/financial/<unique-run-name>`:
synthetic request/response bytes, body hashes matched to server audit outcomes,
image identity, provider-double metadata and financial SQL projections.
The fixtures identify published examples and synthetic data explicitly;
these captures establish local behavior, while real merchant compatibility
remains a separate operational check. Tests and fixture files are excluded
from release artifacts and reject HTTP execution even if mounted locally.

Partner registration for PAW Payments is informational and does not require
an attribution API. H-Script cashback is
paid manually after reconciling transactions in PAW; automatic cashback is
outside the integration. Connect PAW through the ordinary payment-system form at `/admin/curr?add`;
its currency connections remain disabled until native VARY settlement and
financial acceptance are complete.

To replay the populated local stand itself, capture a private read-only
projection and supply its container path to each matrix command:

```sh
umask 077
mkdir -p www/h-script-3/dist/financial
chmod 700 www/h-script-3/dist/financial
docker compose exec -T -e APP_DOMAIN=hs.local webserver \
  php /var/www/html/bin/financial-reconcile.php --snapshot > www/h-script-3/dist/financial/populated.json
chmod 600 www/h-script-3/dist/financial/populated.json
H_SCRIPT_FINANCIAL_SNAPSHOT_FILE=/var/www/html/dist/financial/populated.json \
  sh www/h-script-3/tests/financial_isolated.sh <application-image> mysql:8.4
```

Repeat the last command with `mariadb:10.11` and `mariadb:11.4`. The projection
retains internal numeric IDs for investigation; keep it private. It is not a
restorable application backup. `bin/financial-reconcile.php` only reads data;
`--queries` prints its SQL, `--json` prints the classified-check report, and
`--text` prints the same counts and bounded record IDs for an operator.
The check uses one repeatable read-only snapshot, including transactional `Cfg`
for inherited currency precision. Scales through 8 are valid; individual writes
still refuse values that cannot be represented exactly in SQL's 6 places.
Snapshot format `hscript-financial-audit-v2` includes only sanitized inherited
precision in addition to its seven financial projections. Recapture old v1
projections before replaying them; their missing configuration context cannot
certify the current report. No database migration is required.

The eight-table snapshot does not include `IdempotencyKeys`; its
`referral_receipts_not_in_audit_snapshot` limitation means the report does not
verify the newer source-to-REF receipt bindings. Use the original receipt and
operation IDs during investigation. It cannot reconstruct an old unbound
referral event or a complete historical ledger.

The invariant suite retains three explicit characterizations: BAL history may
lack currency, amount and source operation metadata (F01); legacy `BUY2` has no
supported confirmation/completion implementation (F10); and trusted direct core
CASHIN calls can adjust an amount (F11). `BUY2` settlement is unavailable;
existing records require operator review. F11 does not describe an authenticated
gateway callback: supported automated settlement checks the original signed
intent, currency and amount before changing funds. Investigate any unexplained
record using the escalation process below. No historical balance or award is
repaired automatically.

Use a dedicated account with SELECT on `Users`, `Wallets`, `Opers`, `Deps`,
`Currs`, `Plans`, `Hist` and `Cfg`. The CLI loads no application bootstrap and
does not execute auth, workers, telemetry, provider calls, migrations or repairs.
JSON/text modes exit 0 for clear, 2 for findings, 1 for diagnostic failure and
64 for invalid arguments. Snapshot exit 0 means successful export, including
exports containing findings; use JSON/text to assess the reconciliation result.

Before merging financial changes, require installed component checks, the full
financial and idempotency matrices on all three supported databases, and actual
financial HTTP tests including application restart. Before staging promotion or
release, retain that evidence for the exact candidate image and run the monetary
CLI against the deployment using its read-only account. The existing staging
`bin/update.php reconcile` job checks update/schema state and is a separate gate.
Keep the monetary report private with mode 0600. On exit 2, stop promotion and
give the responsible operator the check names and record IDs; investigate the
original source operations, reservations, receipts and a verified backup before
deciding any correction. Known historical findings require explicit record-level
classification; a blanket F01 exemption is insufficient. Any new or unexplained
finding blocks promotion. On exit 1, resolve availability, permissions or table
engine problems and rerun the read-only check. The CLI never fixes data.

`tests/`, `ci/` and `dist/` are excluded from both Docker releases and shared
hosting archives. Apache denies their URLs (including static fixture files);
PHP test entry points also reject web SAPIs before loading application code.
The special FPM regression runs only in its private test pool with an explicit
pool environment flag. Faker CLI additionally requires the enable flag,
local/development/test `APP_ENV`, exact `hs.local` domain and a local database
host. Keep test scripts out of the production document root; on other web
servers apply equivalent directory-denial rules. `php tests/test_access.php`
checks every PHP test with GET/POST even without Apache rules.

### Custom template modes

`Users.uMode` selects a per-user Twig override directory:

```text
tpl/themes/<mode>/
```

`partner` in `tpl/themes/partner/` is the custom mode name itself, not a required
intermediate folder. Choose a unique name that differs from default directories
and contains only safe lowercase letters, digits, hyphens, or underscores. Keep
it within the application length limit.

Only overridden templates belong in the custom directory. Twig falls back to the
normal `tpl/` tree for every missing file. Example:

```text
tpl/themes/partner/account/index.twig
tpl/themes/partner/index/index.twig
```

Set `Users.uMode` to `partner` for the selected account. Do not copy the complete
default template tree unless every file intentionally diverges.

### Light and dark themes

`Users.uTheme` stores the persistent `light` or `dark` preference. Theme changes
flow through the `system` module, update the cookie for immediate rendering, and
write the normalized value to the database for authenticated users. Future
developers should preserve this path instead of writing the field directly from
templates.

When Tailwind classes or theme tokens change, rebuild CSS and bump the asset
version used by layouts so browsers do not retain stale styles.

### Messages, support, and static pages

The user cabinet exposes one `/messages` journal. Incoming and sent messages are
grouped by a stable conversation identifier, shown as one chat, and marked read
as a complete thread when opened. A reply remains in the current conversation;
separate inbox and outbox pages are intentionally not registered. Direct
messages have no workflow status, while tickets are the support workflow with
new, in-progress, replied, and closed states.

The retired guest `/support` route and its implementation were removed. User
support requests belong in `/tickets`; direct messages remain status-free
conversations.

Database-backed custom pages and their `Pages` table were removed. Add a new
static page as an explicit `module/udp/<name>.php` controller and
`tpl/udp/<name>.twig` template, then register its route in `module/_config.php`.
Apply `migrations/20260811_remove_custom_pages.sql` once to existing databases
after taking a backup.

### Content blocks and administration

The news and review modules have two independent limits: **items per page** for
their catalogue route and **items in block** for the home page. The home-page
grid renders exactly the configured block count, including a single item.
Review pagination uses the shared symmetric vertical spacing.

FAQ category names are rendered as section headings above their question cards.
Administrator ticket details use the same chat structure as the user view;
new tickets are green, closed tickets are red, and support-level operators are
distinguished from the main administrator.

Deposit statistics accept `YYYY-MM-DD` through native date inputs, include the
selected end date, and retain historical rows for disabled currencies. Summary
cards distinguish registered users, deposit records, deposit volume, payouts,
and net cash flow.

## Public locale URLs and indexing migration

Indexable public routes use installed, enabled CMS languages in `UI__Langs`.
The first valid entry is the primary locale; cookie, session, IP and browser
language do not change a legacy redirect target. Keep that ordering stable
throughout the indexing migration. Locale URLs override visitor preferences.

| Existing public address | Permanent target (`<primary>` is the configured locale) |
| --- | --- |
| `/`, `/home`, `/home/` | `/<primary>/` |
| `/contacts`, `/contacts/` | `/<primary>/contacts/` |
| `/news`, `/news/` | `/<primary>/news/` |
| `/show/<id>/<slug>` | `/<primary>/show/<id>/<slug>/` |
| `/show?id=<id>` | `/<primary>/show/?id=<id>` |
| `/faq`, `/reviews`, `/rules`, `/about` (with or without trailing slash) | `/<primary>/<same-alias>/` |
| `/intro`, `/intro/` | `/<primary>/intro/` (only indexable while intro is enabled) |

GET/HEAD use 301; other methods use 308 to preserve form bodies. Safe functional
parameters (`page`, item `id`, supported review `sort`) and allowed attribution
parameters survive the redirect. Canonical removes attribution parameters.
An installation subdirectory precedes every path in this table. Disabled intro
retains its existing redirect to home after locale normalization; it is omitted
from sitemap. Missing/unpublished news ultimately returns 404.

Login, registration, cabinet, administration, Configurator, API, callbacks,
cron, static assets, `/robots.txt` and `/sitemap.xml` keep their existing URLs.
The last two endpoints are stateless and independent of language cookies.
`robots.txt` advertises exactly one HTTPS sitemap at the installation root.

Sitemap includes separate HTTPS URLs and reciprocal `xhtml:link` alternates for
translated public landing pages and published news, matching HTML metadata.
Each article URL uses the title translated into that URL's locale. Canonical,
hreflang, sitemap and language links agree on these locale-specific slugs. The
article ID remains stable; old, foreign-language or outdated slugs redirect once
to the current localized title, preserving allowed attribution parameters.
Its scope remains the existing registered landing pages and news items; it does
not enumerate pagination or create separate FAQ/review item URLs. Listing
availability uses the same first-page data as HTML. Disabled intro, closed-site
content, private/technical routes and noindex variants are excluded.

Every required content field must have a nonempty translation. Unmarked legacy
editorial text belongs to the configured primary locale; add language markers
such as `{!ru!}Текст{!en!}Text{!!}` to declare translations. Catalog fallback is
not proof of translation. Untranslated variants remain readable with noindex
and are not advertised as alternates. `x-default` is included only when the
primary translation exists. Sitemap and HTML share `PublicSeo`; do not maintain
an independent language list in either output.

Before rollout, verify the enabled languages, primary ordering, translated
content, HTTPS domain and publication settings. Save the old URL inventory
and a verified backup. Deploy routing, HTML metadata and sitemap together in
one tested release; no schema migration is needed, and `APP_AUTO_INSTALL=0`
remains required after initial bootstrap. Clear any old proxy/CDN sitemap and
redirect cache. Run the HTTP checks against staging before production:

```sh
php tests/locale_routing.php
php tests/public_seo.php
php tests/localized_sitemap.php
php tests/seo_portability.php
php tests/seo_acceptance_http.php https://your-domain.example
```

`seo_acceptance_http.php` discovers the primary locale from the root redirect,
enabled languages from the private switcher, and article URLs from sitemap.
It checks every sitemap entry and the URL/cookie/Accept-Language matrix for
public routes, pagination and one discovered article. It makes no fixture writes
and assumes a stable, unlocked site during the run. Set `SEO_HTTP_CONCURRENCY=1`
to check a live installation sequentially; the default is 8 concurrent requests
(the supported range is 1–8). This limits concurrency without skipping checks.
For a custom private header, pass an explicit comma-separated enabled language
list as the third argument:

```sh
php tests/seo_acceptance_http.php https://tenant.example/cms tenant.example fr,de,en
```

The base URL may contain an installation subdirectory. The optional second
argument overrides the HTTP Host for local transport. The local LAMP equivalent,
from the Compose root, is
`docker compose exec -T webserver php tests/seo_acceptance_http.php http://127.0.0.1 hs.local`.
The older `localized_sitemap_http.php` suite remains a shipped RU/EN baseline.
Local HTTP checks validate HTTPS metadata but do not verify deployed TLS.

No installation domain, article ID or sitemap entry count is fixed in the runtime.
The component portability suite covers different primary locales, language lists,
domains, subdirectories and publication counts. Standard routes work after an
atomic update. Custom routes need explicit `indexable`/`sitemap` registration,
translation requirements in `PublicSeo` and integration with the shared public
header. A custom theme or missing translations cannot be made SEO-ready merely
by installing the routing code. The current sitemap is a single document; sites
approaching sitemap protocol size/URL limits need sitemap splitting before rollout.

Public page language is determined by the full locale path and does not require
`Vary: Cookie`. Full HTML still contains session/user interface elements and sends
`Cache-Control: no-store`. Reverse proxies/CDNs must honor it and `Set-Cookie`;
do not force-cache these responses. If public HTML caching is introduced later,
separate personalized fragments first and include scheme, host, full locale path
and functional query parameters in its cache key. Do not strip the locale prefix.
Robots and sitemap remain stateless. Verify actual edge settings on staging;
a local proxy probe does not certify the external CDN.

Browser acceptance uses `tests/browser/seo.config.cjs` with the existing pinned
Playwright dependency and its Chromium/Firefox/WebKit runtimes:

```sh
cd tests/browser
npm ci --ignore-scripts --no-audit --no-fund
SEO_BASE_URL=https://tenant.example/cms npx --no-install playwright test --config=seo.config.cjs
```

A separate disposable integration suite checks Apache `.htaccess` routing with
an empty database, 17 translated articles, a changed primary language, a real
`/nested/cms/` installation and an Nginx HTTPS proxy. It verifies the fixture
certificate and hostname using `SEO_TEST_CA_FILE`; TLS verification stays enabled.
It requires a Debian PHP/Apache image with the application's PHP extensions,
MySQL, the local Nginx image and OpenSSL. Run from the local LAMP Compose root:

```sh
sh www/h-script-3/tests/seo_isolated.sh lamp-webserver:latest mysql:8.4
```

The suite copies an explicit source allowlist, excludes live configuration and
user data, creates its own internal network/database, and removes its containers
on exit. It does not use the working site's database. This tests shared-hosting
rewrite behavior in a container; the actual hosting provider and public edge
still need deployment acceptance.

The six cases exercise ordinary language links with JavaScript enabled/disabled.
For HTML5 validation, set `SEO_HTML_DIR` to a private temporary directory during
the acceptance run, then run [Nu HTML Checker](https://validator.github.io/validator/docs/vnu.1.html)
against the saved documents (`vnu --errors-only --skip-non-html DIRECTORY`).
The exported documents cover all tested locale pages and sitemap targets; no
public HTML is uploaded to a third-party validation service.

After production rollout, submit the stable sitemap URL in Search Console.
Use URL Inspection for old/new homepage, a static page, a news item and a
paginated listing in each language; check redirects, crawl access, rendered
canonical and Google's selected canonical. Monitor indexing reports and server
404/redirect logs. A same-domain path change does not need Change of Address.
Retain legacy redirects for at least one year and until the index and inbound
links have moved; keeping them indefinitely is preferable. Submission or a
successful live test is not evidence that Google has indexed the new URLs.
See [Google's migration guidance](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes)
and [localized sitemap rules](https://developers.google.com/search/docs/specialty/international/localized-versions#sitemap).

## Image publication

GitLab is the build authority. Every staging revision produces one candidate in
the GitLab Container Registry, identified by the exact Git tree hash. Staging
pulls that candidate instead of rebuilding it. A protected release-tag pipeline
then copies the same multi-architecture manifest to Docker Hub and GHCR without
rebuilding it.

Published references:

```text
registry.gitlab.com/0x241/h-script:tree-<tree-sha>
docker.io/0x241/h-script:1.0.7
docker.io/0x241/h-script:1.0
docker.io/0x241/h-script:1
ghcr.io/0x241/h-script:1.0.7
ghcr.io/0x241/h-script:1.0
ghcr.io/0x241/h-script:1
```

Images contain `linux/amd64` and `linux/arm64`, BuildKit SBOM/provenance
attestations, a generated CycloneDX SBOM, and keyless Cosign signatures. Full
SemVer tags are immutable and there is no floating `latest` tag. Release
credentials are stored only as protected CI/CD variables; build and promotion
utilities are never included in runtime images or shared-hosting archives.

The pipeline enforces blocking Composer/npm audits, source/shared/image scans,
time-limited exceptions, immutable tag preflight and permanent signed release
evidence. Pinned-tool refreshes must pass the same gates.

The dedicated staging runner reuses the `hscript-release` BuildKit builder and
its local cache between pipelines; the GitLab registry cache remains the
runner-independent fallback. The first multi-platform build after a runner or
runtime change is intentionally slower because PHP extensions are compiled for
both architectures. Per-commit labels and application files do not invalidate
that stable runtime layer. New pushes cancel an obsolete in-progress candidate
build, and a resource group prevents two candidates from competing for the same
builder. Set `H_SCRIPT_BUILDX_CONFIG` only when the runner needs a persistent
state directory other than `/tmp/h-script-buildx`.

Configure these GitLab CI/CD variables before the first public release:

| Variable | Visibility | Protected | Expand variable reference |
| --- | --- | --- | --- |
| `DOCKERHUB_USERNAME` | Visible | Yes | Yes |
| `DOCKERHUB_TOKEN` | Masked and hidden | Yes | No |
| `GHCR_USERNAME` | Visible | Yes | Yes |
| `GHCR_TOKEN` | Masked and hidden | Yes | No |
| `GITHUB_RELEASE_TOKEN` | Masked and hidden | Yes | No |

Use dedicated, least-privilege tokens. Do not create custom variables for the
GitLab registry: the pipeline uses GitLab's built-in `CI_REGISTRY_*` values.
`GITHUB_RELEASE_TOKEN` requires write access to repository contents and releases.
Protect `release/public` and release tags, and enable **Allow Git push requests
to the repository** for this project's CI job tokens before enabling the manual
publication jobs. No permanent GitLab release token is required.

GitHub Container Registry creates the first personal-account package as private
even when its source repository is public. After the first successful image
promotion, open the `h-script` package on GitHub, select **Package settings**, and
under **Danger Zone** change its visibility to **Public**. This one-time change
enables anonymous `docker pull ghcr.io/0x241/h-script:1.0.7`; GitHub does not
allow a public package to be made private again.

## GitLab staging and GitHub promotion

Maintain local operator procedures for incident handling, scoped consumer
emergency stop, secret rotation and scheduled drill acceptance.
Assign actual owners and verify external services before treating local test
results as operational acceptance.

Before packaging/deployment, `test:release-components` runs application/update,
API, queue, telemetry, gateway and redaction component tests inside the exact
candidate image digest with networking disabled. Only the tests are mounted;
the application and dependencies come from the image. `test:release-migrations`
uses that digest against disposable, digest-pinned MySQL 8.4 and MariaDB
10.11/11.4 databases, including partial DDL failure/resume and rollback rejection.
`test:release-browser` uses the same candidate with a separate pinned
Chromium/Firefox/WebKit runner to check rendering, CSS/HTMX, login/session and API
authentication; browser dependencies never enter the application image. All three
suites block packaging. Staging deploy and manual promotion share a resource
group and verify the running app/cron image identities against the candidate.
After deployment, the pipeline pauses (blocked/manual) at the explicit
`verify-staging` stage. Complete any required update at the staging Configurator
(`/_cfg?update`, unless `APP_CFG_LINK` is customized), then manually run
`test:staging-reconciliation` **in the pipeline of the currently deployed image**.
If no update is required, simply run this verification job. The confirmation
button does not run migrations or backups; it acknowledges operator readiness.
The job is blocking (`allow_failure: false`): pending confirmation is not a failed
deployment, but a real failed check still blocks promotion. Retrying a job from
an older pipeline is rejected if staging now runs a different candidate.
`test:staging-reconciliation` runs `php bin/update.php reconcile` in the staging
app, checking versions/migrations, basic financial invariants, queue, cron and
telemetry freshness in a read-only DB transaction. Manual promotion requires and
repeats that gate. It does not repair data or automatically complete an upgrade.
The legacy shared-hosting format-2 handoff is locally tested through
the same CLI with an explicit `--project-root`; protected live pipeline acceptance
remains pending, so phase 4 is not yet accepted for production.
The updater explicitly rejects application and schema downgrades independently
of the supported source range. Code rollback remains a separate recovery action
and is not permission to downgrade a database schema.

GitLab keeps the permanent `main`, `stage/docker-release`, and `release/public`
branches. The protected `release/public` branch is the only source for GitHub;
GitHub `main` mirrors it one way and must resolve to the same commit SHA.

1. Develop and review changes in GitLab branches, then merge them into
   `stage/docker-release`.
2. Let the staging pipeline build, scan, sign and deploy one exact GitLab
   Container Registry candidate. Test its migrations, API, queue, payment,
   telemetry, authentication, and UI.
   Complete any required Configurator update and manually run
   `test:staging-reconciliation` in `verify-staging`; proceed only after it passes.
3. Run the optional manual `release:promote-public` job in the successful staging
   pipeline. It audits the tested tree and creates one release commit whose
   parent is the previous `release/public` commit. It never merges staging
   history into the public branch. Open the manual job by name to override
   `PUBLIC_RELEASE_TITLE` with a concise description of the public update; the
   tested staging SHA is retained in the commit body.
4. Audit the complete `release/public` tree and reachable history for secrets,
   local configuration, internal agent files, and unreleased documentation.
5. Run the manual `publish:github-source` job from the same staging pipeline. It
   fetches GitLab `release/public`, requires its tree to match the tested staging
   tree, pushes only that clean-history commit to GitHub `main`, and verifies the
   remote SHA.
6. Create the protected immutable release tag from `release/public` and verify
   that both references point to the same commit. The tag starts a dedicated
   release pipeline.
7. Run the manual public-image promotion job in the tag pipeline. It verifies
   the staging signature, copies the same digest to Docker Hub and GHCR, signs
   the public references, and retains the compiled shared-hosting archive.
8. Run the manual GitHub release job. It requires GitHub `main` to match the
   tagged commit, creates the same immutable lightweight tag when it is absent,
   and publishes the shared-hosting archive, checksum file, and Sigstore bundle.
   An existing GitHub tag is accepted only when it already points to the exact
   same commit; the pipeline never overwrites tags.

The next public promotion is rejected until the current `release/public` tip is
already mirrored to GitHub `main`. This prevents multiple unpublished promotion
commits from accumulating in the public history. Promoting an identical tree is
also rejected.

If the GitHub release job fails after an immutable tag has already been created,
run `publish:recover-shared-github-release` from the current protected staging
pipeline with `RELEASE_RECOVERY_TAG` and `RELEASE_EVIDENCE_JOB_ID` pointing to
the original tag job's retained evidence. It verifies and reuses the original
signed bytes; it never rebuilds a published version. This optional shortcut
requires permitted cross-pipeline artifact downloads. Otherwise retry the
publication job in the original tag pipeline; see the supply-chain policy.

`release/public` is permanent and is updated for every release; do not create a
new temporary transfer branch each time. Only release maintainers should be able
to update or delete it. The first clean publication may require a one-time
`force-with-lease` after archiving the previous GitHub `main` tip.

Example remotes:

```bash
git remote -v
git remote add github git@github.com:0x241/h-script.git
git fetch origin --tags
git fetch github --tags
git push github origin/release/public:main
git push github origin/release/public:refs/tags/v1.0.0
```

Do not merge GitHub changes back into GitLab. GitHub is the public release
mirror; GitLab `stage/docker-release` and `release/public` are the promotion
gates. Compare SHAs before every mirror push.

## Operations and diagnostics

### Recurrent gateway 504 and private-route 500

Capture evidence during the failure, before rebooting. On the Nginx gateway,
inspect the error log for the failing request and note its timestamp and upstream:

```sh
sudo tail -n 100 /var/log/nginx/error.log
sudo journalctl -u nginx -u tailscaled --since '20 minutes ago' --no-pager
```

`while connecting to upstream` points to the backend listener, network path or
connection backlog. `while reading response header from upstream` means the
connection was established but a response header did not arrive in time. The
shipped gateway template uses a 5-second connect timeout and a 60-second read
timeout; check the deployed values before drawing conclusions from timing alone.
For a private-route 500 with `auth request unexpected status`, inspect Authelia
and its upstream as well: the authorization subrequest is separate from H-Script.
See the [Nginx proxy timeouts](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_connect_timeout)
and [auth_request status handling](https://nginx.org/en/docs/http/ngx_http_auth_request_module.html).

On the application server, run these from the **deployed Compose directory**
with the same project name and environment used by deployment:

```sh
date -Is
uptime
free -h
df -h
df -i
docker compose ps
docker stats --no-stream
docker compose logs --since 20m --tail 200 app database redis
sudo journalctl -k --since '20 minutes ago' --no-pager | grep -Ei 'oom|out of memory|killed process'
docker compose exec -T app curl -sS --connect-timeout 3 --max-time 15 -o /dev/null -w 'HTTP=%{http_code} connect=%{time_connect} start=%{time_starttransfer} total=%{time_total}\n' -H 'Host: YOUR_PUBLIC_HOST' http://127.0.0.1/en/
```

Replace `YOUR_PUBLIC_HOST` and `/en/` with the actual domain and enabled locale.
Compare that internal request with a request from the gateway to the configured
Tailscale backend IP/port using the same Host header. A fast container-local
response but a failed gateway-to-backend connection points to the inter-server
path or exposed listener. If the internal PHP request also stalls, compare a
known static asset and inspect PHP-FPM workers, database locks/connections and
outbound calls. Check FPM logs for `pm.max_children` exhaustion; use an operator-only
[FPM slow log](https://www.php.net/manual/en/install.fpm.configuration.php) to
identify blocked scripts before changing worker limits or timeouts. A host reboot
clears transient state but does not establish which component failed.

### Sessions and administrator impersonation

PHP session storage uses `SESSION_GC_MAXLIFETIME` (30 days by default) so the
runtime does not discard an otherwise active session after PHP's usual short
default. The application's configured user idle limits and the separate
15-minute administration idle timeout remain authoritative and may expire a
session earlier.

The scoped authentication cookie can restore an ordinary session if only the
`PHPSESSID` cookie is lost. Non-remembered logins remain nonpersistent, recovery
tokens are random, and remember-me cookies are distinguished from ordinary
session recovery. Administrator impersonation stores a separately authorized
return context and displays a return-to-administrator action.

### SMS delivery

The ePochta integration targets the AtomPark/ePochta SMS API v3 over HTTPS. Set
the public and private API keys under **Admin > Settings > SMS**; legacy account
login/password fields are no longer used. Existing installations must apply
`migrations/20260811_remove_gravatar_and_legacy_epochta.sql`; it disables the
provider when API v3 keys are absent so an obsolete configuration cannot send.

SMS submission and delivery polling normalize provider errors into job failure
details. Configure the provider in administration rather than CI/CD because the
keys live in `Cfg` and are preserved across container deployments.

### Email delivery

Docker images do not include a local mail transfer agent. Configure an external
SMTP provider in **Admin > Settings > Mail** before testing account or system
messages:

- set the SMTP host and port;
- enable encryption for STARTTLS (normally port 587) or SMTPS (port 465);
- provide both the SMTP username and password, or leave both empty only for an
  explicitly trusted unauthenticated relay.

Leaving the host empty selects PHP's native `mail()` transport and is intended
only for shared hosting where the provider has configured it. Saving the form
with an empty password keeps the previously stored SMTP password.

`Sys.AdminMail` is the independent destination for system notifications. It is
not synchronized with `Users.uMail` of the `uLevel=99` account: changing the
notification mailbox must not silently change the administrator's account or
login address. `Sys.NotifyMail` is the sender shown in the `From` header.

Email subjects and bodies use the same JSON translation catalog as the web
interface. User notifications follow `Users.uLang`; administrator notifications
follow `Sys.AdminLang`, with English and Russian fallback when the requested
language has no email keys. Select the administrator notification language under
**Admin > Settings > Main** and edit content under **Admin > Settings >
Translations > Emails**. Overrides are stored in the `Cfg` table, so they
survive container and image replacement. The rendered H-Script email can be
checked without sending anything under **Admin > Settings > Mail > Email
preview**.

The Configurator also uses `lang/en.json` and `lang/ru.json`: its keys start
with `configurator.` (for example `cfg_t('configurator.login.sign_in')`). Add
matching keys to another language catalog to translate its interface. The
language selector lists installed catalogs; missing keys fall back to English.
It works before database setup and respects shared translation overrides once
configuration is loaded. Do not add inline Russian/English pairs to PHP.

Email is queued in the `Jobs` table and delivered by the scheduler. For Docker,
keep both `CRON_ENABLED=1` and the database setting **Scheduler > Enabled** on.
Transport failures are written to the `app` container log and to `Jobs.jError`.
Inspect both the application and scheduler containers when delivery stalls:

```bash
docker compose logs --tail=200 app cron
```

Useful Docker checks:

```bash
docker compose ps
docker compose logs --tail=200 app cron database redis
docker compose exec app php -v
docker compose exec app php -m
docker compose exec app composer validate --no-check-publish
```

Application logs are in `logs/`. Queue health is visible in the scheduler and
the `Jobs` table. Redis failures are fail-open and logged once per request.

After the first bootstrap, keep `APP_AUTO_INSTALL=0`. Apply future schema changes
only with the Configurator update flow or another explicit migration step after
a verified backup. The application has no destructive force-reinstall flag.

### Stale `mysql.sock` in the local macOS LAMP environment

The local bind-mounted MySQL data directory can retain `mysql.sock` and
`mysql.sock.lock` after an unclean stop. MySQL 8.4 may then restart before
`mysqld` starts with an error similar to:

```text
chown: changing ownership of '/var/lib/mysql/mysql.sock': No such file or directory
```

Stop the local stack, confirm no MySQL container is using the directory, and move
only the stale socket entries out of the bind-mounted data directory. Do not
delete database files. Then start the database and inspect its logs. This issue
belongs to the local macOS bind mount; production Compose uses a named volume and
is not affected by that local socket artifact.

## License and change history

See `CHANGELOG.md` for release history. Confirm the repository license and all
third-party gateway SDK licenses before distributing a production release.

### Ручной cron и частота телеметрии

На `/admin/cron` кнопка ручного вызова выполняет задания, срок которых наступил.
Нужны права администратора и действующий CSRF-сертификат; в demo запуск доступен
владельцу уровня 99. Одновременный запуск блокируется общей блокировкой базы.
Начисления выполняются перед внешними курсами и доставкой сообщений. Сохранение
переключателя cron сохраняет расписания модулей; статистика показывает время
начала последнего вызова отдельно от следующего срока.

Телеметрия автоматически повторяется раз в сутки. Кнопки регистрации/отправки
администратора используют общий минимальный интервал **5 минут**, сохранённый
в базе до сетевого запроса. Ошибка, параллельные запросы и перезапуск PHP этот
лимит не сбрасывают. Повторная кнопка во время ожидания сохраняет настройки и
показывает сообщение об ограничении.

`tests/failed_login_isolated.sh` также запускает `cron_telemetry_database.php`:
16 конкурирующих процессов, повторы после ошибки/перезапуска, недоступное
хранилище, расписание и блокировка cron. Клиенты телеметрии фальшивые; база и
сеть создаются отдельно. Эти тесты защищены тем же CLI/HTTP запретом, что и фейкер.

### Фаза 2: реестр идемпотентности

Миграция schema 1.0.4 и транзакционный реестр ключей/результатов требуют
резервной копии перед обновлением.
Для проверки на отдельной пустой базе из родительского Compose-каталога:

```sh
sh www/h-script-3/tests/idempotency_isolated.sh <candidate-image> mysql:8.4
```

Та же команда поддерживает MariaDB 10.11/11.4. Проверяются повтор, конфликт,
владелец/маршрут, 16 процессов и авария после claim/operation/commit.
Новые тесты также исключены из production образа и запрещены через HTTP.
После установки сохраняйте `APP_AUTO_INSTALL=0`; обновления схемы выполняйте
через явный шаг миграции после резервного копирования.

### CryptoCurrencyAPI v3: native inputs

For **CCAB/CCAL/CCAD/CCAG/CCAH** (BTC/LTC/DASH/DOGE/BCH), set the ordinary SCI
fields **Protocol version = 3** and **Version 3 API key**. The separate API fields
use the same format for balance queries. Existing `apipass` credentials and old
URL-only invoices are not migrated: reconcile those invoices before using this
profile. Keep each installation's callback URL distinct; invoice `uniqID` includes
its SHA-256 namespace so two installations sharing a provider key do not overwrite
each other's receiver callback settings. Key rotation also requires reconciling
outstanding invoices. Native v3 payouts are unavailable.

The checkout shows the saved receiver, asset and exact amount. Automatic credit
requires one scalar positive operation label, the original receiver/gross/asset,
empty native `token` and `tokenContract`, and the original encrypted invoice and
fee proof. Later commission policy edits preserve the originally agreed local
fee; the provider's network fee is separate. Confirmations 0/1 remain pending.
Manual values **100 and 101** require reconciliation: the official
[IPN overview](https://new.cryptocurrencyapi.net/docs/.ipn/ipn-overview) and
[manualConfirm method](https://new.cryptocurrencyapi.net/docs/.api/method-manualConfirm)
state different manual values, so neither triggers automatic credit.

This bounded profile accepts decimal strings and safe integer JSON tokens, a
closed set of scalar IPN fields and colon-free signed values. Array labels/IDs,
token transfers, prefixed BCH cashaddr (`bitcoincash:`), raw fractional/scientific
JSON numbers, extra fields and legacy callbacks require reconciliation. Unprefixed
native BCH addresses are supported. Accepted provider time is bound across
pending/final/replay; no undocumented maximum settlement age is imposed. Full
`txid#pos` remains the permanent registry identity; only the legacy 40-character
operation batch column uses a shorter storage alias, never replay authority. The
[official signature algorithm](https://new.cryptocurrencyapi.net/ru/docs/.ipn/ipn-php)
hashes sorted values without key names or delimiter escaping, which requires
these restrictions. Captured merchant callbacks have not been used to claim
broader wire compatibility.

Offline verification: `php tests/ccapi_callbacks.php`. For real SQL, run from the
parent Compose directory:

```sh
sh www/h-script-3/tests/financial_isolated.sh <candidate-image> mysql:8.4
sh www/h-script-3/tests/ccapi_http_isolated.sh <candidate-image> mysql:8.4
```

The existing matrix also supports MariaDB 10.11/11.4. Fixtures use disposable
internal networks and fake provider transport. Test entry points deny HTTP and
are excluded from production images; no live merchant API is called.

### Decimal commissions, exchanges and balance storage

Commission calculation and shared balance mutations use `brick/math` through
Composer. Commission rounding preserves the existing order: HALF_UP to six
places, then the currency scale, minimum-before-maximum clamp, then the currency
scale again. Additional operation commission is rounded separately after the
clamp. Existing stored balances and informational receipt amounts are preserved.

Current money columns remain `DECIMAL(13,6)` with a maximum of
`9999999.999999`. A currency configured with eight decimals may use only amounts
exactly representable in those columns; extra nonzero places or overflow are
rejected before financial writes. No schema or existing balance conversion is
performed. Exchange arithmetic preserves the source-scale intermediate rounding
before target conversion. New saved quotes are fixed strings at the final target
scale, matching the paired credit (including exchanges to the base currency).
Later rate changes do not recalculate a saved quote; existing receipts are not
rewritten. Nonpositive rate factors, rounded-zero outputs, storage precision loss
and overflow are rejected before a new source operation is created. Exchange
preview uses the base currency's commission for internal-currency withdrawals.

Coinbase rates are accepted only as positive plain decimal strings (at most 36
whole and 18 fractional digits); numeric JSON, exponents and locale notation are
rejected. CBR XML values allow the documented decimal comma and a positive
integer nominal. Per-unit CBR division uses HALF_UP18; cross-base fiat ratios
retain HALF_UP4. Missing, malformed or rounded-zero fiat quotes reject the whole
fiat group and leave its existing configuration intact. Valid enabled crypto
quotes may still be collected independently. All validated quotes and LastUpdate
are saved in one transaction; a failed write rolls back the whole publication.
The existing hourly cron guard remains in place.

Browser CASHIN with internal-denominated input and external registration bonuses
now divide the raw amount by the canonical currency code's rate, then round once
at the destination currency scale, without EXIn/EXOut adjustments. Commission
and net previews use the same converted CASHIN amount. Missing or invalid rates,
rounded-zero amounts, storage precision loss and overflow are refused. Bonus
currencies remain independent; an unsupported currency is skipped. REST deposit
amount units and existing registration-event retry semantics are unchanged.
New API monetary fields use decimal strings; completed response snapshots retain
their original representation.

`php tests/decimal_money.php` checks the decimal types and independent rounding
vectors. The isolated financial command above also runs
`tests/financial_decimal_money.php`, `tests/financial_decimal_exchange.php` and
`tests/financial_decimal_rates.php` and `tests/financial_decimal_deposit.php`
against each supported database. `php tests/decimal_exchange.php` checks separate
Python Decimal oracle vectors for exchange rounding at all scales. These tests
are CLI-only and excluded from production packages. Rate response and publishing
checks also run with `php tests/fiat_rates.php`, `php tests/crypto_rates.php` and
`php tests/rate_update.php`; these use synthetic responses without network calls.

Deposit writers use exact decimal principal, valuation and percentages. New
bonus, profit and reinvestment amounts round HALF_UP to six places and then the
configured currency scale; compound percentages round HALF_UP to two places.
Opening plan eligibility, bonus and referral event basis retain the gross amount.
The stored initial principal excludes the GIVE commission, and the active
principal includes that net amount plus the bonus. Top-ups and reinvestment add
only the actual GIVE net. Creation and top-ups require a positive net
contribution, including when an opening bonus is configured. Partial withdrawals reduce principal by the gross TAKE
amount and apply the existing commission order.

Existing six-place principal is preserved on full withdrawal, administrative
close and 100% maturity return. Other maturity percentages round the worked
principal at the earnings boundary and add the exact compounded principal.
Plan transition caps use the native principal minimum, with division rounded
CEILING at the smaller of the currency scale and six storage places. Plan changes
validate ownership, amount range, capacity, prerequisites and compound bounds;
the current deposit is excluded from capacity counts. Active hidden plans still
occupy their group. Hidden plans require the
administrator path. Negative bonus/profit/return plans are now unsupported for
new deposits or plan transitions, and negative earnings/returns refuse accrual.
Positive returns above 100% remain supported within storage and balance limits.

Every deposit writer checks the total active principal against the owner's
locked bucket under transaction locks. Existing mismatches refuse mutation and
require administrator reconciliation; this release does not repair old rows.
Failed writes and unrepresentable amounts roll back the entire financial unit,
including the accrual boundary and idempotency receipt.

`php tests/decimal_deposit.php` checks 200 independent Python Decimal vectors.
Regenerate them with `python3 tests/fixtures/decimal_deposit_oracle.py`. The SQL
suite checks commissions, historical principal, plan transitions, precision,
overflow, failed writes, nested transactions and boundary retries. Run it through
the same disposable three-database matrix above. Tests and fixture generators
remain excluded from release images and archives; PHP entry points deny HTTP.

Referral rewards calculate the exact source amount times the configured rate,
round HALF_UP to six places, then to the receiving currency scale. CASHIN uses
settled net or the saved target quote after conversion; deposit opening retains
gross basis and deposit earnings use gross profit. Rates retain their configured
precision. Numeric settings accept leading/trailing decimal points and bounded
scientific notation without floats; percentage input allows up to 36 integer
and 18 fractional places, independently of request-money precision.
Nonpositive rates, rounded-zero rewards and inactive sponsors receive
no payout; the flat chain continues to higher ancestors. A short personal rate
override skips absent absolute levels instead of shifting another rate upward.

Tier thresholds compare exact decimal aggregates, including totals above the
range of an individual stored amount. Configured order and duplicate thresholds
are preserved; lookup stops at the first higher threshold. Every entry is
validated before any payout; a signed exponent does not act as the
threshold/percentage separator. Type 3 includes all
deposit states of direct referrals, and type 4 includes the sponsor's active
principal. Both preserve the existing sum across currencies without conversion.
Malformed rates/tiers, unrepresentable rewards and failed writes abort all levels
and the originating financial transaction, including queued notifications.
Existing rows are not rewritten. CASHIN completion, deposit opening and each
accrual period now bind the source identity, owner, currency, basis and original
REF operation IDs in a durable receipt within the source transaction. Exact
retries keep that receipt despite later rate or sponsor configuration changes.
A changed basis conflicts. First issuance requires the actual source transition;
a bare event for an unbound historical source returns `ref_source_unbound`, even
inside a caller transaction. Review such history instead of inferring payouts
from matching sponsor tags or sums. There is no automatic historical backfill.

Sponsor eligibility, ancestry, personal overrides and tier metrics use current
locked SQL rows inside the financial transaction, including nested callers with
an earlier read snapshot. Request configuration and rates keep their existing
request scope.

The configured flat traversal rejects a repeated user, including an inactive
sponsor, before paying that user twice. A cycle rolls back the original source,
earlier rewards, receipt and queued notifications. Tier mode pays one sponsor
and rejects a self-reference. These rules apply to the configured traversal;
they do not perform a global inventory of every account's referral graph.

`php tests/decimal_referral.php` checks 252 Python Decimal vectors. Regenerate
them with `python3 tests/fixtures/decimal_referral_oracle.py`. The isolated matrix
also runs `tests/financial_decimal_referral.php`, covering actual event basis,
overrides, threshold boundaries, inactive sponsors, SQL failures and rollback.

`tests/financial_referral_once.php` adds 90 seeded graphs with an independent
Python Decimal issuance oracle, real concurrent PHP workers under REPEATABLE
READ and READ COMMITTED, separate deposit periods, fresh-process replay, source
cycles, nested caller rollback and genuine late SQL failures. Each scenario
reconciles exact expected audit findings and verifies the source plus rewards.
Regenerate its offline cases with
`python3 tests/fixtures/financial_referral_oracle.py`; the generator has no
network or database access. Set `H_SCRIPT_FINANCIAL_REFERRAL_LEGACY_IMAGE` to an
immutable image from before these bindings to exercise a genuine old producer
and the candidate on the same disposable database. Use
`H_SCRIPT_FINANCIAL_CANDIDATE=1` to test installed candidate runtime with only
tests supplied externally. The legacy registration cutover option can run in
the same matrix. Test files and generators stay excluded from releases and
PHP entry points refuse HTTP.

The shared `_z` template formatter rounds HALF_UP only at display time and
escapes localized currency text. API serialization does not perform display
rounding. `php tests/decimal_output.php` checks 162 Python Decimal formatting
vectors; regenerate with `python3 tests/fixtures/decimal_output_oracle.py`.
The isolated financial matrix also checks exact SQL projections and old/new
receipt replay in `tests/financial_decimal_output.php`. To exercise installed
API output and actual app restart, run from the Compose root:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_output_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

That runner bootstraps only a disposable internal database and uses synthetic
read fixtures, including wide totals and a negative historical net. Those
fixtures test serialization, without claiming a reconciled economic state.
Tests remain excluded from release images/archives and PHP test entry points
deny HTTP even when web-server rules are absent.

The deposit calculator uses exact decimal strings and preserves its historical
two-place HALF_UP rounding at every step. It is a preview of plan percentages,
bonus, compound and return; it does not simulate settlement fees, conversions or
calendar accruals. Compounding is limited to 0–100%, calculation work to 10,000
periods and intermediate amounts to 36 whole digits. Unsupported forecasts show
an unavailable message without writing any financial rows. Deposit list current
profit rounds HALF_UP at six places; hourly profit uses the receiving currency
scale. The `decimal_format` Twig filter groups fixed decimal text without floats.

Run `php tests/decimal_forecast.php` for 180 Python Decimal forecast cases and
regenerate the fixture with `python3 tests/fixtures/decimal_forecast_oracle.py`.
The installed isolated financial matrix also runs
`tests/financial_decimal_forecast.php`: 216 SQL list projections, controller input
fallbacks, nullable historical amounts and checks that reading a forecast never
mutates financial tables. Existing SQL NULL amounts are interpreted as zero at
read boundaries; stored rows are not rewritten.

The actual browser calculator, grouped decimal output, escaping, bounded work
and unchanged stored rows across restart can be checked from the Compose root:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_forecast_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

This runner creates only synthetic data in a disposable internal database.

Read-only deposit statistics, administrator totals and cabinet currency groups
use exact decimal sums. SQL totals keep six places; valued deposit statistics
retain rate/offset precision until HALF_UP display rounding. Existing gross/net,
operation-state, Auto memo, date and disabled-system rules remain in place.
The filtered cash flow is calculated with exact six-place subtraction before Twig.
Missing/NULL/non-numeric read values mean zero without rewriting stored rows;
this fallback is not used for financial writes. Wallet allocation rounds HALF_UP
to one place, and balances sort by exact value. Fixed-format computed display
values are bounded to 80 whole and 36 fractional digits; scientific input keeps
the existing 36/18 bounds. Public telemetry uses its own fixed-eight-place string
contract, described under Decimal provider and public-statistics boundaries below.

Run `php tests/decimal_aggregate.php` for independent Python portfolio and valued
sum vectors; regenerate with `python3 tests/fixtures/decimal_aggregate_oracle.py`.
The isolated installed financial matrix runs `tests/financial_decimal_aggregate.php`
on synthetic SQL sums, nullable rows and exact rates/offsets and verifies that
reads leave all financial tables unchanged. Actual administrator statistics,
period/system selection and cabinet reads are checked across app restart:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_aggregate_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

Run from the Compose root. This runner only creates a disposable internal
synthetic database with the administrator login-mail recipient disabled for this
read-only projection check; all queued rows remain part of the fingerprint.
PHP test entry points deny HTTP and release artifacts exclude
the test directory.

Administrator plan money guards can be checked with `php tests/decimal_plan.php`;
regenerate the independent Python vectors with
`python3 tests/fixtures/decimal_plan_oracle.py`. The installed isolated financial
matrix also checks actual SQL storage, manual accrual receipts and late scheduler
write failures with `tests/financial_decimal_plan.php` on disposable databases.
For native plan/manual forms and receipt replay after an application restart,
run from the Docker Compose project root:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_plan_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

This runner uses an internal network and a fresh synthetic database; no source
or production database is mounted. Tests are CLI-only, denied by HTTP and excluded
from the installed release. Never copy the test directory to a public web root.

Currency/calendar exact storage guards are tested with
`php tests/decimal_configuration.php` and the installed isolated financial matrix.
Independent Python cases can be regenerated with
`python3 tests/fixtures/decimal_configuration_oracle.py`.
From the Compose root, native forms and restart reads use the same disposable
fixture isolation and release/HTTP test restrictions described above:

```sh
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_configuration_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

Decimal provider and public-statistics boundaries
------------------------------------------------

Provider requests carry plain decimal strings. New payouts preserve the reserved
amount exactly: YooMoney and Volet/USD require cents; the supported legacy
CoinPayments and Paykassa paths accept at most eight places within H-Script's
12-whole-digit envelope. These are application guards, not a claim that every
provider asset has the same precision. Excess precision, zero, negative values,
float arguments and overflow are rejected before loading a network client.
Existing saved invoices, financial receipts and callback proofs are unchanged.
WebMoney's new checkout retains explicit HALF_UP cent rounding; YooMoney/YMC
checkout requires an exact cent amount. YooMoney uses the documented
`https://yoomoney.ru` API origin and stops after a refused request-payment.
`amount_due` still denotes the amount received; its fee/debit semantics are
unchanged. Legacy Paykassa payout API compatibility must be verified with the
merchant before enabling it; offline wire tests do not prove live acceptance.

The Docker image includes PHP SOAP for the existing Volet generated SDK. Shared
hosting needs `ext-soap` when using Volet balance queries or payouts. Its current
[WSDL](https://account.volet.com/wsm/apiWebService?wsdl) declares `xs:decimal`;
SOAP request and response amounts remain text, including single-wallet and
empty balance responses. PAW stays installed through Composer; its read-only
profile checks and manual partner cashback do not enable automated payments.

Public telemetry **schema 2** represents monetary fields as canonical unsigned
strings with exactly eight decimal places, with the existing per-field maximum
`1000000000000000.00000000`. Counters remain integers. Public read aggregates and
`processed` metrics also return decimal strings; their sum may exceed that
individual-report maximum. SQL aggregation/display retains explicit HALF_UP8;
local valued statistics retain their full precision until this boundary.
Deploy the collector accepting schemas 1 and 2 **before upgrading reporters**.
No database migration is needed for this JSON contract. Previously stored
schema 1 numeric reports keep their validated bytes, hashes and exact retry
behavior; an existing same-day reporter cache is reused unchanged. Old numeric
cache ingress cannot restore digits that were already lost upstream.

The simple deposit form/AJAX preview computes principal × plan percentage ×
period count exactly, then formats the display. It does not simulate compound,
calendar, return percentages or fees. Monetary templates use decimal grouping
and subtraction rather than Twig's numeric coercion.

Run from the Docker Compose root on an installed candidate:

```sh
H_SCRIPT_FINANCIAL_CANDIDATE=1 \
  sh www/h-script-3/tests/financial_isolated.sh CANDIDATE_IMAGE mysql:8.4
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=hscript-lifecycle-browser:local \
  sh www/h-script-3/tests/decimal_closure_http_isolated.sh CANDIDATE_IMAGE mysql:8.4
```

The suite includes actual SDK form/HMAC captures and native SOAP with closed
transports, without payment-network requests. Its sanitized WSDL fixture has
no external imports. Regenerate independent transport/preview/public vectors
with `python3 tests/fixtures/decimal_closure_oracle.py`; the schema 1 goldens came
from a previous installed image. Tests stay outside release artifacts and all
PHP test entry points refuse HTTP execution.
