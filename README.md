# H-Script

H-Script 1.0.0 is a PHP CMS for financial projects. It includes user accounts,
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
`1.0.0`; do not use a floating `latest` tag in production.

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
APP_IMAGE_TAG=1.0.0
APP_PULL_POLICY=always

APP_ENV=production
APP_DEBUG=0
APP_DOMAIN=example.com
APP_SYS_ID=change-me-stable-secret
APP_SYS_MAIL=admin@example.com
APP_EXTERNAL_NETWORK=external-proxy-network

DB_PASSWORD=change-me-db-password
MYSQL_ROOT_PASSWORD=change-me-root-password
CONFIGURATOR_PASSWORD=change-me-configurator-password

TURNSTILE_SITE_KEY=change-me-site-key
TURNSTILE_SECRET_KEY=change-me-secret-key
```

The same digest is also published as `ghcr.io/0x241/h-script:1.0.0`. Both public
packages should allow end users to pull without `docker login`. A private GHCR
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

`APP_AUTO_INSTALL=1` is not a migration mechanism. Never set
`APP_INSTALL_FORCE=1` on an existing staging or production database unless the
explicit goal is to delete and recreate its tables.

### Image upgrades and rollback

Back up MySQL and runtime volumes before an upgrade. Change `APP_IMAGE_TAG` to a
new exact version, then run:

```bash
docker compose pull app cron
docker compose up -d --no-build --remove-orphans
docker compose ps
docker compose logs --tail=200 app cron
```

To roll back, restore the previous image tag and repeat `pull` and `up`. If a
release contains an irreversible database migration, restore a compatible
database backup as part of the rollback.

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

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ ^/(?:\.git|\.agents|\.codex|\.cfg|backup|compile|docker|lib|logs|module|tpl|tpl_c|vendor)(?:/|$) {
    deny all;
}

location ~ ^/upload/.*\.(?:php|phtml|php[0-9]?|phps|cgi|pl|fcgi)$ {
    deny all;
}

location ~ ^/(?:_config\.php|composer\.(?:json|lock)|memory\.md|tasks\.md)$ {
    deny all;
}
```

Open `https://example.com/_cfg`, configure the database, and run the initial
installation. Add a scheduler call every minute:

```cron
* * * * * curl -fsS --max-time 50 https://example.com/cron?auto >/dev/null
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
  --build-arg APP_VERSION=1.0.0 \
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
7. Configure the hosting scheduler to request `/cron?auto` every minute.
8. Open **Admin > Settings > Scheduler**, enable the external scheduler flag,
   save it, and use the manual trigger once.
9. Confirm that at least one module timestamp changes in scheduler statistics.
10. Test registration, login, mail delivery, and Turnstile over HTTPS.

Before an upgrade, back up MySQL, `upload/`, `_config.php`, and
`module/_config/pass`. Replace only application files and never delete runtime
data. Apply schema changes through an explicit documented migration.

## Docker environment variables

The complete reference is `docker/env.example`.

### Application and image

| Variable | Purpose |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | Environment and diagnostic output; use `production` and `0` in production. |
| `APP_IMAGE` | Local image name, Docker Hub repository, or GHCR package. |
| `APP_IMAGE_TAG` | Exact release tag, for example `1.0.0`. |
| `APP_PULL_POLICY` | `build` for source builds or `always` for registry images. |
| `APP_DOMAIN` | Public domain without a scheme; keep it stable after installation. |
| `APP_SYS_ID` | Stable system secret/identifier; keep it stable after installation. |
| `APP_SYS_MAIL` | Default installation email; used for the main administrator, sender and system-notification fallbacks unless their dedicated overrides are set. |
| `APP_CFG_LINK` | Configurator path; defaults to `_cfg`. |
| `APP_DEMO_MODE` | Enables demo restrictions without rewriting existing users. |
| `APP_PORT` | Host port mapped to container port 80. |
| `APP_EXTERNAL_NETWORK` | Existing external reverse-proxy network. |
| `APP_NETWORK_ALIAS` | Application alias on the external proxy network. |
| `TRUSTED_PROXY_CIDRS` | Trusted reverse-proxy CIDRs allowed to supply forwarded headers. |
| `TELEMETRY_ENDPOINT` | Installation collector; defaults to `https://h-script.com/api/v1/installations`. |
| `INSTALL_TELEMETRY_STATS` | `1` sends public aggregates; `0` sends mandatory installation data only. |
| `TELEMETRY_COLLECTOR_ENABLED` | Enables incoming collector APIs on the central instance only. |
| `TELEMETRY_COLLECTOR_DOMAIN` | Domain allowed to host collector APIs and administration. |
| `TELEMETRY_RATE_LIMIT` | Collector requests per IP per minute; defaults to `30`. |

`APP_SYS_ID` must remain stable because it identifies the installation.
Docker database credentials are read directly from environment variables or
mounted secret files and are not persisted in `_config.php`.

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
| `CRON_INTERVAL_SECONDS` | Scheduler interval; defaults to 60 seconds. |
| `CRON_URL`, `CRON_HOST_HEADER` | Internal scheduler URL and optional Host header. |
| `CRON_TIMEOUT_SECONDS`, `CRON_START_DELAY_SECONDS` | Per-call timeout and initial delay. |

### Security and initial installation

| Variable | Purpose |
| --- | --- |
| `CONFIGURATOR_PASSWORD` | Independent configurator password; mandatory in production. |
| `APP_DATA_KEY` | Long random key for authenticated encryption of persisted integration secrets; mandatory in production. |
| `ALLOW_EMPTY_CONFIGURATOR_PASSWORD` | Local-only exception; keep `0` in production. |
| `REQUIRE_TURNSTILE` | Requires configured Turnstile keys at startup. |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | Public and secret Turnstile keys. |
| `SESSION_COOKIE_SECURE`, `SESSION_COOKIE_SAMESITE`, `SESSION_GC_MAXLIFETIME` | Session cookie policy and server-side lifetime in seconds; the default lifetime is 30 days while application idle limits remain authoritative. |
| `APP_AUTO_INSTALL` | One-time schema bootstrap on an empty database; return to `0`. |
| `APP_INSTALL_FORCE` | Destructive database recreation; normally always `0`. |
| `INSTALL_ADMIN_*` | First main administrator credentials, PIN, and secret answer. |
| `INSTALL_DEMO_ADMIN_*` | Separate demo administrator when demo mode is enabled. |
| `INSTALL_MAIL_HOST`, `INSTALL_MAIL_PORT` | Initial SMTP endpoint. |
| `INSTALL_MAIL_SECURE` | `1` enables STARTTLS, or SMTPS when port 465 is selected. |
| `INSTALL_MAIL_USERNAME`, `INSTALL_MAIL_PASSWORD` | Initial SMTP credentials; both may be empty only for a trusted unauthenticated relay. |
| `INSTALL_MAIL_FROM_ADDRESS` | Optional initial `Sys.NotifyMail` sender override; defaults to `APP_SYS_MAIL`. |
| `INSTALL_MAIL_ADMIN_ADDRESS` | Optional initial `Sys.AdminMail` notification destination override; defaults to `INSTALL_ADMIN_MAIL`, then `APP_SYS_MAIL`. |
| `INSTALL_MAIL_ADMIN_LANG` | Initial administrator email language, such as `en` or `ru`. |
| `INSTALL_NO_LOGINS`, `INSTALL_INT_CURR`, `INSTALL_INT_CURR_ID` | Initial login policy and internal currency. |

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
   installation date, and a random installation ID. A daily heartbeat then
   reports the current version. This layer cannot be disabled in the installer
   or administration interface.
2. Public statistics are enabled by default and send numeric aggregates such as
   user counts, online users, incoming funds, payouts, and other totals grouped
   by currency. They can be disabled independently in
   `/admin/setup/telemetry`.

Logins, email addresses, user IPs, individual operations, payout destinations,
passwords, gateway keys, settings, and database content are not transmitted.
The collector sees the source server IP like any HTTPS service and uses it for
rate limiting and diagnostics. Installation owners should disclose mandatory
registration in their own documentation and privacy policy.

Docker installation defaults:

```env
INSTALL_TELEMETRY_STATS=1
TELEMETRY_ENDPOINT=https://h-script.com/api/v1/installations
```

Set `INSTALL_TELEMETRY_STATS=0` to transmit mandatory installation information
without public aggregates. Collector downtime does not block login, financial
operations, or scheduler execution; reporting is fail-open and records the last
status for diagnostics.

### Central collector on h-script.com

Only the central instance should enable collector mode:

```env
TELEMETRY_COLLECTOR_ENABLED=1
TELEMETRY_COLLECTOR_DOMAIN=h-script.com
TELEMETRY_RATE_LIMIT=30
```

These values are ordinary visible CI/CD variables. They are configuration, not
secrets, so they do not need masking. Do not restore the removed shared
`TELEMETRY_READ_TOKEN` design.

Collector mode additionally requires the request host to match
`TELEMETRY_COLLECTOR_DOMAIN`. This prevents a normal installed copy from
exposing the central registry merely because a local administrator has
`uLevel=99`.

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
GET /api/v1/installations/stats
```

Protected service endpoint:

```text
GET /api/v1/installations
Authorization: Bearer hst_<secret>
```

Installation-side `hsi_` tokens authenticate only registration and heartbeat;
they cannot read collector data. Service `hst_` tokens cannot submit installation
reports.

The landing-page counters use collector `processed` and `platforms` values.
Public financial totals are self-reported and therefore are not independently
verified. The collector canonicalizes domains and counts only one active
installation per canonical domain to limit simple duplication, but this does not
prevent an owner from reporting false values. Future verified metrics require a
separate HTTP or DNS domain-ownership challenge and an anti-fraud policy. Until
that exists, label public totals as reported aggregates rather than audited data.

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

## Image publication

GitLab is the build authority. Every staging revision produces one candidate in
the GitLab Container Registry, identified by the exact Git tree hash. Staging
pulls that candidate instead of rebuilding it. A protected release-tag pipeline
then copies the same multi-architecture manifest to Docker Hub and GHCR without
rebuilding it.

Published references:

```text
registry.gitlab.com/0x241/h-script:tree-<tree-sha>
docker.io/0x241/h-script:1.0.0
docker.io/0x241/h-script:1.0
docker.io/0x241/h-script:1
ghcr.io/0x241/h-script:1.0.0
ghcr.io/0x241/h-script:1.0
ghcr.io/0x241/h-script:1
```

Images contain `linux/amd64` and `linux/arm64`, BuildKit SBOM/provenance
attestations, a generated CycloneDX SBOM, and keyless Cosign signatures. Full
SemVer tags are immutable and there is no floating `latest` tag. Release
credentials are stored only as protected CI/CD variables; build and promotion
utilities are never included in runtime images or shared-hosting archives.

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

## GitLab staging and GitHub promotion

GitLab keeps the permanent `main`, `stage/docker-release`, and `release/public`
branches. The protected `release/public` branch is the only source for GitHub;
GitHub `main` mirrors it one way and must resolve to the same commit SHA.

1. Develop and review changes in GitLab branches, then merge them into
   `stage/docker-release`.
2. Let the staging pipeline build, scan, sign and deploy one exact GitLab
   Container Registry candidate. Test its migrations, API, queue, payment,
   telemetry, authentication, and UI.
3. Run the optional manual `release:promote-public` job in the successful staging
   pipeline. It audits the tested tree and creates one release commit whose
   parent is the previous `release/public` commit. It never merges staging
   history into the public branch.
4. Audit the complete `release/public` tree and reachable history for secrets,
   local configuration, internal agent files, and unreleased documentation.
5. Create the protected immutable release tag from `release/public` and verify
   that both references point to the same commit.
6. Run the manual public-image promotion job. It verifies the staging signature,
   copies the same digest to Docker Hub and GHCR, signs the public references,
   and retains the compiled shared-hosting archive.
7. Run the manual `publish:github-source` job from the same staging pipeline. It
   fetches GitLab `release/public`, requires its tree to match the tested staging
   tree, pushes only that clean-history commit to GitHub `main`, and verifies the
   remote SHA. Push release tags to GitHub after creating them.
8. Run the manual GitHub release job to publish the shared-hosting archive,
   checksum file, and Sigstore bundle next to the mirrored source tag.

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
git push github --tags
```

Do not merge GitHub changes back into GitLab. GitHub is the public release
mirror; GitLab `stage/docker-release` and `release/public` are the promotion
gates. Compare SHAs before every mirror push.

## Operations and diagnostics

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
only with an explicit migration/update step and a verified backup. Never use
`APP_INSTALL_FORCE=1` to repair or upgrade an existing database.

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
