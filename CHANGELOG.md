# Changelog

All notable changes to H-Script are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

No changes recorded after the 1.0.9 release candidate.

## [1.0.9] - 2026-10-27

Release candidate: application `1.0.9`, schema `1.0.16`. The application version,
npm metadata and source compatibility range are aligned. The schema target is
unchanged from the tested staging branch; upgrading the published 1.0.8 release
from schema `1.0.11` still requires its bundled backup-protected migrations through
the Configurator. Existing installations must keep `APP_AUTO_INSTALL=0`.

### Release verification

- Verify the same complete release suites using at most two independent fixtures:
  ten browser families and five database families on MySQL 8.4, MariaDB 10.11 and
  MariaDB 11.4. Preserve all release gates, failure codes, cleanup and per-suite logs.
- Test real cron-worker death and server-side advisory-lock release rather than
  assuming an immediate release after closing client PDO. Production locking is unchanged.
- Use a job-scoped Docker credential helper in the three release test jobs;
  config files contain no registry token. Real Docker CLI/Buildx and signal cleanup
  are covered by synthetic regression tests.
- Combine Compose, frontend and version checks under their existing rules to reduce
  runner handoffs.

### Recovery scheduling

- Staging deploys persist recovery worker settings in the private environment file,
  including the external secrets mount and shared RPO/RTO policy. Production update
  gates continue to require verified database/files backups and isolated restore evidence.
- A recent restore report no longer suppresses a new drill when retention removed
  its backup, the source schema changed, or its evidence is incomplete or invalid.
  Corrupted current bundles still fail verification; no freshness limits are relaxed.
- Select the latest recovery bundle by the atomically published report ID rather
  than filesystem timestamps. Tied or changed file times cannot select an older
  archive; a failed latest publication is rejected without a fallback.

### Added

- Add 321 RU/EN keys across eleven gateway connection, currency, token and account
  templates, including credential labels and validation messages. Translation
  checks catch unkeyed gateway text; browser tests switch RU/EN/RU without altering
  settings or revealing saved credentials.

- Consistent currency membership checkboxes across shared and manual gateway
  connections. Deselecting archives an idle currency without deleting its ledger,
  balance, encrypted settings or history; selecting it again restores the same ID
  enabled, with financial modes disabled. Active deposits, reservations, unresolved receipts
  and payout jobs block archival, with complete rollback on contention or failure.
- Explicit selection on shared/manual connections enables the selected currency
  under an enabled gateway. Existing modes must pass the ordinary capability checks;
  new/restored currencies keep every financial mode off. CoinPayments NEW remains
  configuration-only until its settlement contract is verified.
- Separate connected/archive/all filters, read-only archived currency pages and
  explicit whole-gateway archival wording. Removed redundant former-currency blocks.
  Native-network selection does not implicitly archive its token currencies.
- Token membership is managed on CCAPI network pages and separate network-grouped
  token pages for PayKassa/CoinPayments NEW, with no token list on their main
  connections. PayKassa links only token-capable networks from their coin cards;
  BNB and TRON replace duplicate network cards without changing SCI or ledger IDs.
  Equal-height cards keep settings and network actions aligned; selected currencies
  appear before unselected currencies within each gateway/network group. Shared
  PayKassa USDT appears on both Ethereum/TRON pages with one explicit common balance.
  Full-profile selections on these pages preserve hidden choices without activating them. Existing tokens remain manageable when their native coin is archived.
  Owners retain a separate read-only view of archived balances in their cabinet,
  history and payment details. Historical duplicate bank accounts restore together
  with their original IDs and disabled modes, without merging balances.
- Strict original-order callback routing and authenticated, receipt-only replay for
  explicitly archived currencies. Missing settlement proof requires reconciliation;
  no new callback claim or credit is manufactured. PayKassa reuses only the original
  verified token digest, with a bounded verification lease and no new archived API read.
- A homepage unique-currency counter beside the gateway count replaces the processed
  counter. It counts distinct codes in the bundled gateway catalog, once per code
  across providers/networks; arbitrary user-added tokens are outside that total.
- Explicit backup-required schema 1.0.16 adds nullable archival metadata and a database
  mode guard. Existing disabled/unselected rows are not automatically reclassified.


- One currency-card template for PAW and manual/legacy connections, combining
  existing and addable currencies. Shared connection links use five equal columns
  on wide screens. Recipient forms have separate currency blocks within gateway
  and network groups, with bank-specific field hints.
- WebMoney WMID and YooMoney application/API settings on their main connection
  pages. Currency saves preserve these account fields; stale or mixed account
  changes are rejected. Secret inputs stay blank, and account edits update only
  their original encrypted dictionary without altering receipt keys or modes.

- Compact full-width balance tables with a horizontal action toolbar; payment
  requisites are grouped by gateway while retaining each currency's own fields.
- Common BankWire, YooMoney, WebMoney and SberBank currency setup. BankWire offers
  a bounded fiat catalog, separate account details per currency and no new BTC
  accounts. Existing ledger IDs, encrypted settings and history remain unchanged.
- Bank recipients saved with an IBAN are covered by wallet locking; bank deposit
  receipts no longer replace saved recipient details. Concurrent currency adds
  and BankWire enabling use a shared lock and currency-specific uniqueness.
- First-time wallet creation and unchanged wallet/default-currency saves use SQL
  execution success rather than auto-ID or affected-row counts. IBAN-only manual
  BankWire withdrawals work in both the cabinet and REST API without automatic sends.
- Manual bank invoices show the receiving account for the selected currency,
  amount and operation reference separately from the payer's saved payout details.
- Source-first deployments retain the safe pre-migration provider screen when
  archive metadata tables or columns are not yet present.

- CoinPayments NEW connection preparation with a per-instance API catalog, selected
  coins/tokens and separate immutable network/contract identities. Credentials are
  encrypted and validated using read-only API access. Schema 1.0.15 keeps all NEW
  currencies hidden and every financial mode disabled pending verified Sandbox
  settlement/fee receipts; Legacy operations and keys remain separate.
- Archived provider currencies have distinct labels and operation links by their
  original IDs. Connected legacy systems are omitted from the add-connection list.
- Volet registration referral link and clearer legacy bank/merchant setup guidance.
- Checkout reconciliation failures render a readable notice instead of a blank
  page, without issuing another upstream invoice request.
- Connection requirements for SberPay and SBP, with inbound RUB and no withdrawals
  planned after bank access is available. No direct NSPK access is presumed.

- Shared connections for CryptoCurrencyAPI, Volet, PayKassa, Stripe and PAW, with
  encrypted versioned credentials and separate currency settings. Providers are
  counted once. Retired ePayCore and standalone legacy CCAPI adapters are removed.
- Network-specific token balances discovered from the CCAPI catalog, separate
  USDT currencies per network, and separate collection and payout source settings.
- Asynchronous CCAPI v3 withdrawals from an explicit source address: queued requests
  retain the reservation until network confirmation. Signed outgoing notifications
  bind the original amount, recipient, key, network and token contract. Retries and
  status recovery never create a second send; token finality requires a signed
  notification. Destination tags/memos and ambiguous legacy token routes are excluded.
- PAW native v2 VARY top-ups: actual paid fiat minus the agreed fixed local fee,
  raw-body HMAC verification, original invoice/profile binding and permanent
  idempotency. Sandbox has simulated invoices without a hosted checkout; real
  callback delivery requires public HTTPS.
- PAW withdrawals to a saved USDT TRC-20 or USDC ERC-20 address. The provider converts
  the original accounting fiat; the merchant pays network charges. A committed
  request is sent once, and only its acknowledged order can be polled to completion.
  Missing acknowledgments require reconciliation. Sandbox payouts are unsupported;
  existing connections remain disabled for outgoing payments after the explicit upgrade.
- A compact rate table covering reviewed gateway currencies, exact manual prices
  and optional batched market updates. Missing quotes preserve prior values; token
  symbols alone never authorize exchange. Finance navigation includes withdrawal,
  exchange and transfer links.
- Expanded PayKassa currency/network catalog with separate settings and ledger IDs
  for new BNB Smart Chain, Ethereum and TON assets, destination tags/memos and
  original invoice verification. New choices are disabled until explicitly configured.
  Sandbox is removed from PayKassa connections and transport; test invoices cannot
  settle live balances. The partner panel includes the bundled referral URL and
  manual cashback terms; connection checks explain their exact scope. Berty USD/RUB
  remains outside the crypto SCI adapter pending its separate receipt contract.
- Saved menu pins per account, a local QR code and payment status polling, gateway
  filters, and a structured manual operation confirmation form.

### Fixed

- Separate gateway connection and currency pages, with one global enable control,
  no repeated PAW information panels and direct links to currency settings.
- Keep public language navigation available when editorial translations are
  missing, without advertising untranslated pages as SEO alternatives.
- Allow multilingual FAQ category labels with `{!ru!}…{!en!}…{!!}` markers. Removing
  a category moves its questions to General in the same settings transaction.
- Correct restore-test selection when backups share a timestamp, clarify expired
  recovery evidence, and preserve strict migration/restore gates.

### Security

- Runtime curl/libcurl require patched 8.23.0 or later (CVE-2026-92392). Source
  archives exclude development documentation;
  the artifact path and secret-file policy remains unchanged.
- Retain the patched PHP 8.4.27, PHP 8.5.12/Composer 2.10.3 builder and
  Node 24.22.1 LTS baseline from 1.0.8. Bundle fonts/icons locally and remove
  unconditional third-party CSP permissions.
- Use exact decimal money, immutable fee and invoice snapshots, and atomic
  financial writes with concurrency, replay and failure recovery coverage.

### Compatibility and verification notes

Currency membership and financial modes are separate settings. Selecting a visible
currency enables that currency under an enabled gateway, subject to capability
checks. Adding or restoring a currency leaves its financial modes off. Deselection
archives the original ledger only after pending work has been resolved; it does
not move balances or rewrite historical operations.

The historical PayKassa USDT account retains one shared Ethereum/TRON balance and
appears on both network pages. Its network links do not split the ledger or change
SCI identifiers. CoinPayments NEW remains configuration-only until its settlement
and fee contracts have been verified.

Gateway validation covers isolated SQL suites, authenticated Chromium/WebKit
flows, signed callback replay after application restart, and RU/EN/RU switching
with unchanged form controls. These checks are separate from merchant acceptance
and staging deployment. Apply schema updates through the Configurator after a
verified backup; do not use automatic installation to migrate an existing database.

## Earlier release and staging notes

The following historical entries are retained separately from the 1.0.9 release
summary. They include existing behavior and earlier staging work; a complete
version-by-version history audit remains separate.

### Security

- Inventory and CI gate for browser output bypasses; sanitize rich text, URLs,
  email HTML, and gateway-controlled output at their rendering boundaries.
- Add per-response CSP nonces and a surface-specific Report-Only policy with a
  bounded, privacy-reduced violation collector. The legacy policy remains
  enforced until staging observation and integration checks are complete.
- Add a CI-checked route matrix and harden CSRF and authorization for session
  mutations, account ownership, administrator role changes, cron, OAuth state,
  and signed payment callbacks. The full negative route and gateway sandbox
  matrix remains a staging acceptance task.
- Require the current configurator password for password changes and confirm
  the new password before saving. Initial password setup remains supported.

### Fixed

- Generate news slugs from each locale's translated title in canonical, hreflang,
  sitemap and language links; normalize old article URLs with one permanent redirect.
- Keep release component tests compatible with runtime images without npm source
  metadata; derive synthetic upgrade versions from the tested application version.

- Make public language selection deterministic from the locale URL, including
  legacy redirects, pagination and encoded article slugs. Expired cached news
  and untranslated editorial content no longer advertise invalid SEO alternates.
- Correct shared public HTML structure and labels validated across the locale
  acceptance matrix.

- Separate installed CMS/database versions from deployed update targets in the
  Configurator; a historical successful update no longer masks pending migration.
  Identify expired, future-dated or invalid recovery evidence separately.
- Continue integrity scans automatically in bounded authenticated browser batches,
  serialize them with cron and restart stale cursors after a verified baseline
  change. Stop automatic retries on authentication/network errors.

- Harden SQL backup creation against existing-file truncation and symlink writes,
  including gzip output. Restrict directories to `0700`, new files to `0600`,
  and tighten existing Docker backup permissions at startup. Deny both backup
  URLs and their resolved volume in Apache. Atomically publish recovery JSON
  through exclusive private temporary files; reject linked metadata.

- Staging now waits at a blocking manual `verify-staging` stage for the operator
  to complete any Configurator update before read-only reconciliation. Older
  pipeline image mismatches report why retrying the obsolete job is rejected.

- Fixed native MySQL JSON values in the PHP streaming backup fallback: decode
  their binary SQL literals as UTF-8 without changing BLOB data. Added a forced
  PHP backup/PDO restore regression for JSON, Unicode, binary, NULL and decimals.

- Share non-secret recovery policy between the Docker web updater and recovery
  worker without exposing drill credentials. Run the recovery scheduler as the
  application user so private backup/drill evidence remains readable by preflight.
- Explain recovery-policy, backup/drill, freshness and queue blockers in the
  Configurator. Reconciliation now reports failed check IDs safely in CI.

### Added

- Multilingual public URLs with self-canonical HTTPS links, reciprocal hreflang,
  primary-language x-default and direct language links usable without JavaScript.
  Private and technical routes retain their existing addresses.
- A localized XML sitemap generated from installed languages, registered public
  routes and published translated content, portable across domains and subfolders.
- HTTP/cache, browser and isolated-installation SEO acceptance tests; configurable
  HTTP concurrency for sequential staging diagnostics.

- Automatic HTTPS domain-control confirmation for nginx/CDN installations with
  different inbound/outbound IPs: authenticated one-use challenges, 7-day expiry,
  renewal and domain-change invalidation. Checks pin public DNS addresses, verify
  TLS, forbid redirects/proxies, and bound response sizes/timeouts. DNS remains
  diagnostic; fresh HTTPS proof is an alternative public-metric eligibility path,
  not a financial-data trust label. CMS `1.0.5` requires the backup-protected
  schema migration `1.0.1` → `1.0.2`.
- Add domain-proof/SSRF regressions, real-database pagination/verification tests
  on the supported engines, and browser checks for private backup URLs/downloads.

- Added operator incident/rotation/recovery runbooks and scoped collector consumer
  emergency stop in the existing token CLI, with isolated rotation/revocation and
  HTTP ingestion-stop tests. Disabled consumers cannot authenticate surviving tokens.

- Started the operations release contract: explicit application/schema downgrade
  rejection in the existing update path and a blocking CI component suite that
  runs installed application code at the exact candidate image digest.
- Extended compatibility to a strict format-2 runtime/browser and rollback
  contract; added queue/secrets and production schema-update recovery preflight,
  backup/drill freshness and verification of the local recovery bundle.
- Added isolated exact-image updater/migration CI tests on MySQL 8.4 and MariaDB
  10.11/11.4, digest-pinned staging and a read-only pre-promotion image guard.
- Added a read-only post-upgrade reconciliation CLI and staging promotion gate,
  reusing recovery invariants and checking migration metadata, queue and cron/
  telemetry freshness. Added separate pinned Chromium/Firefox/WebKit CI scenarios
  and integrated production preflight tests with real Redis, local SQL/runtime
  restore drills against disposable databases.

- Added fail-closed Composer/npm and source/shared/image supply-chain gates,
  reviewed expiring exceptions, immutable SemVer digest preflight, synthetic
  secret boundary tests and permanently retained signed release evidence.
- Added bounded structured NDJSON logs, low-cardinality metrics, trusted-proxy
  correlation propagation across HTTP/HTMX/API, queue, cron, telemetry and
  gateways, operator-only health/readiness checks, and suppressed runbook-linked
  fail-open alerts.
- Added scheduled disaster-recovery bundles on top of the existing verified SQL
  backup/restore services: strict runtime allowlists and checksums, local
  retention in backup/, isolated restore drills,
  environment-owned RPO/RTO policies and restricted fail-open alert evidence.
- Added strict authenticated installation registration and idempotent daily
  reports with server-observed IP history, bounded passive DNS states, rejection
  counters, and demo aggregate isolation on the central collector.
- Added independent bounded pagination and validated filters for installations
  and collector service tokens, SQL aggregate summaries, stable ordering, and
  permission-gated IP/DNS details without exposing token hashes or raw reports.
- Added million-row query/memory regression coverage plus isolated database
  backup/restore, schema-migration, interrupted-update, Authelia bypass, and
  manual/scheduled integrity integration checks.

### Changed

- Removed built-in remote backup transport, restic dependency and storage/key
  settings. External operator tools own replication; local backups and drills remain.
- Unified Configurator text under configurator.* keys in the shared JSON language
  catalogs, including update messages and language selection before DB installation.

- Bound recovery notification delivery to five seconds, preserve scheduler
  retries after failed cycles, and return nonzero for failed one-shot jobs.
- Run restore drills with prefix-scoped database accounts without access to the
  system database; reject failed database creation/cleanup explicitly. Default
  recovery scheduling is hourly, below the default collector/token RPO.
- Add recovery notification, one-shot/scheduler and limited-account restore
  regressions to the release gates.

- Accept the existing paused service-token state during restore/reconciliation.

- Reject code-only recovery after entering a schema-changing migration, including
  partially committed DDL that has not advanced the recorded schema version.
- Record the installed source application version in SQL backup manifests under
  the update lock, instead of the version of the new running updater/image.
- Fail reconciliation with nonzero exit and bounded JSON on DB connection errors;
  do not interpret unavailable financial/queue queries as zero violations.
- Added an explicit external-engine target to the existing shared-hosting CLI,
  allowing a separately verified target release to upgrade a strict format-1
  installation without replacing its old parser manually or bypassing lifecycle.
- Publication retries preserve original signed release bytes after scan expiry
  and rescan the original source, archive and image digest when needed. Recovery
  validates public-branch ancestry explicitly, including shallow runner clones.

- Fixed mixed root/PHP-FPM ownership and concurrent NDJSON rotation, made failed
  readiness queries explicitly unknown, measured cron freshness from successful
  completion only, and bounded readiness notifier execution with a timeout.
- Documented the schema `1.0.0` to `1.0.1` telemetry migration, verified backup
  and isolated restore workflow, Docker/shared-hosting update recovery, central
  ingestion flags, collector API routes, and machine-route Authelia bypasses.
- Kept public traffic online after a compatible code-only Docker image change;
  the Configurator still exposes the pending lifecycle health check and records
  the new CMS version after it succeeds.
- Made the Docker “Update and complete” action resume its compatible unfinished
  run instead of failing while trying to create a duplicate run.
- Allowed the central collector to complete an update while the public
  `h-script.com` installation is in demo mode; malformed and unauthorized
  telemetry remains an ingestion concern rather than a lifecycle gate.
- Added responsive, dependency-free H-Script pages for unknown routes, active
  maintenance, and update states that genuinely require a traffic gate, with
  Russian/English switching and the shared product logo and copyright.

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

## [1.0.3] - 2026-09-10

### Added

- Added a two-VPS deployment example where public Nginx and Authelia share the
  gateway VPS, while Nginx proxies H-Script over Tailscale with matching
  anchored, case-sensitive access-control rules for the Configurator and
  administration routes.
- Kept Authelia entirely outside the H-Script runtime while preserving built-in
  authorization, keeping public and machine routes outside `auth_request`, and
  clearing client identity headers before the application upstream.
- Added an authenticated Configurator Security page with production preflight,
  optional trusted-proxy-aware CIDR access control, persistent rate limits and a
  redacted local action audit.
- Added bounded, resumable official-release file integrity checks with neutral
  Twig customization reporting, critical writable-directory executable checks,
  daily cron continuation, persistent Configurator/admin alerts and optional
  one-time generic e-mail notification.
- Added an immutable file baseline generated inside each official Docker image
  and shared-hosting archive and replaced only after a verified update succeeds.
- Added configurable demo administrator credentials to the login page.
- Added an explicit read-only translation interface for demo administrators;
  the existing server-side write restriction remains authoritative.
- Added dynamic `robots.txt` and `sitemap.xml`, canonical and social metadata,
  structured data, and indexable-route metadata based on the configured
  application URL.
- Added a localized contacts page for partnership and vulnerability reports,
  plus a multi-card partners section with W-Shop in the public navigation.
- Added administrator review creation and editing with a validated existing or
  standalone author login, an editable publication date, and an explicit
  migration for persisted review authors.
- Added initial-setup guidance in administration and production database-backup
  guidance in the configurator update flow.
- Added a GitLab-first release pipeline that builds and deploys one signed,
  scanned multi-architecture candidate, promotes its exact digest to Docker Hub
  and GHCR, and publishes a checksummed, signed shared-hosting archive.
- Added explicit Docker distribution allowlists, pinned build/runtime bases,
  BuildKit SBOM and provenance attestations, CycloneDX export, critical Trivy
  gating, and keyless Cosign verification for release artifacts.
- Added a local ignored audit report, recursive sensitive-data redaction,
  allowlist HTML sanitization, authenticated encryption for persisted secrets,
  and focused security/rate-provider tests.
- Added threaded, chat-style message views for users and administrators and a
  matching chat presentation for administrator ticket handling.
- Added stable message conversation identifiers, legacy thread backfilling,
  grouped unread counters, and HTMX replies inside the current conversation.
- Added focused regression tests for the ePochta/AtomPark SMS API v3 client and
  server-side session-security configuration.
- Added explicit cleanup migrations for legacy Gravatar/ePochta configuration
  and the retired database-backed custom-page table.
- Added a localized public rules page with operator-adaptation guidance,
  structured usage and security terms, and links in desktop, mobile and footer
  navigation.
- Added Composer PSR-4 autoloading for the `HScript\\` namespace and separated
  database, HTTP, mail, template, cache, queue, payment and utility components
  into `src/`.
- Added REST API v1 for user, balance, operation, deposit and withdrawal flows,
  with hashed per-user Bearer tokens, scopes, expiry, revocation and separate
  IP/token rate limits.
- Added administrator and user interfaces for issuing and managing API tokens;
  token secrets are displayed once and never stored in plaintext.
- Added Redis-backed fail-open caching for configuration and public catalogues,
  with generation-based invalidation after database mutations.
- Added a database job queue for asynchronous e-mail and SMS delivery, including
  retries, stalled-job recovery and retention cleanup from cron.
- Added a mandatory installation registry for domain, H-Script version,
  installation date and a random installation ID.
- Added signed daily installation heartbeats, local delivery status, retry via
  cron, and an admin page for optional public aggregate statistics.
- Added a disabled-by-default central collector with per-installation hashed
  tokens, IP rate limiting, daily reports and a service-token-protected summary
  API.
- Added a public aggregate endpoint and connected the home-page `processed` and
  `platforms` counters to accepted funds and registered installations.
- Added an explicit database migration for central installation/report storage.
- Added a `uLevel=99` collector dashboard with summary cards and a per-installation
  table of domains, versions, connection state and permitted public aggregates.
- Added database-backed `hst_…` collector service tokens with one-time secret
  display, expiry, pause, revocation and last-use audit.
- Added the isolated `uLevel=10` collector role with self-service issuance,
  owner-restricted reissuance and revocation of `hst_…` tokens.
- Added mandatory assignment of new collector service tokens to an active
  `uLevel=10` user and a collector-account summary table for the main
  administrator.
- Restored per-user `uMode` template selection: an administrator can assign a
  safe existing `tpl/themes/<mode>/` directory which overrides the base
  templates with automatic fallback and cannot collide with core module
  directories.
- Added a logo-style SVG favicon across public, account, admin and configurator
  layouts.
- Added separate real cabinet, operations and deposits captures to the animated
  home-page browser stack.
- Added fintech-style UI direction for public pages and the configurator, based on Stripe / Linear patterns.
- Added two-column wallet/requisites layout with payment-system icon decoration.
- Added localized RU/EN language switcher that keeps the current page instead of redirecting to the home page.
- Added JSON-based translation flow and `{_t}` usage for public UI text.
- Added redesigned configurator navigation with top tabs, responsive layout and localized labels.
- Added redesigned login/register screens with a two-column trust/security layout.
- Added "Back to top" footer action with smooth scroll.
- Added Docker Compose runtime for H-Script with PHP 8.4, Apache, PHP-FPM and MySQL 8.4.
- Added Alpine-based PHP image build with Composer multi-stage dependency installation.
- Added runtime `_config.php` generation from environment variables.
- Added `docker/env.example` for end-user installation.
- Added automatic database initialization via `APP_AUTO_INSTALL=1`.
- Added optional `uLevel=90` demo admin seed for first installs with `APP_DEMO_MODE=1`.
- Added CI/CD variables for initial admin account, configurator password, DB credentials, Turnstile and demo mode.
- Added Docker-style `*_FILE` secret support for sensitive values.
- Added Apache production config with internal rewrite rules and protected private directories.
- Added deployment guidance for prebuilt Docker images, source builds, non-Docker VPS and shared hosting.
- Added a documented one-way GitLab to GitHub migration procedure.
- Added guarded manual CI jobs that promote an exact tested staging tree into
  clean-history `release/public` and mirror only that branch to GitHub `main`.
- Added a compare-and-swap guard for the one-time replacement of the unrelated
  legacy GitHub `main` history; all later source publications remain
  fast-forward-only.
- Added immutable release-tag synchronization from GitLab to GitHub before the
  shared-hosting GitHub Release is created.
- Added idempotent GitHub Release publication and a guarded recovery job that
  reproducibly rebuilds an archive without moving an immutable release tag or
  requiring cross-pipeline artifact access.

### Changed

- Kept legacy installations online while they record lifecycle metadata for the
  first time; only a confirmed CMS or schema mismatch now blocks public traffic.
- Kept Docker lifecycle markers readable by the unprivileged web process so
  metadata onboarding cannot leave the public site behind a false update gate.
- Restricted the default trusted-proxy list to IPv4/IPv6 loopback and stopped
  Apache from treating an arbitrary client `X-Forwarded-Proto` header as HTTPS;
  deployments behind a proxy must now declare its exact CIDR explicitly.
- Replaced hard-coded example proxy addresses with an env-rendered Nginx
  topology template and named H-Script/Authelia upstreams.
- Excluded generated backup archives and manifests from Git and Docker build
  contexts while keeping the access-denying `backup/.htaccess` in releases.
- Improved the public home page with Russian numeric inflection for platform
  and gateway counters, a multi-partner card grid, consistent source-link
  blocks, and cross-browser navigation spacing.
- Embedded the GitHub Release publisher in `.gitlab-ci.yml` and made public
  source promotion reject tracked `.gitlab/` directories, keeping GitLab CI
  helper paths out of the clean GitHub history.
- Made public promotion require the previous `release/public` tip to match
  GitHub `main`, reject duplicate trees, and support a human-readable
  `PUBLIC_RELEASE_TITLE` with the staging SHA retained in the commit body.
- Made configurator version reporting use the detected database/application
  state consistently on both the main and update pages.
- Reused a serialized persistent BuildKit builder for multi-platform staging
  candidates, isolated the stable PHP runtime from per-commit image metadata,
  and enabled cancellation of obsolete builds on newer pushes.
- Removed duplicate and unused PHP extension compilation plus unused Alpine
  packages from the production image, and added an explicit runtime module
  assertion during the build.
- Removed Endroid's unused bundled Noto Sans label font and switched TOTP QR
  generation to the label-free SVG writer path.
- Standardized primary page actions across tickets, messages, and deposits on
  the shared 48-pixel button component.
- Made release scanning use Trivy's official GHCR vulnerability database
  directly and reuse one database cache for SBOM generation and enforcement.
- Serialized multi-platform image publication and registry-cache export, with
  bounded retries for transient GitLab Registry manifest consistency failures.
- Restored consistent spacing between administrative filters and result tables;
  translation editing once again shows every active language in one matrix and
  uses safe per-key saving plus a separate explicit action for adding a key.
- Restored the shared admin-table “select all” checkbox, including partial
  selection state and tables refreshed through HTMX.
- Restored late-bound Twig finance helpers on staging, removed the hard-coded
  base-currency ID from rate cron, and stopped CAPTCHA settings from rendering
  previously saved Turnstile keys.
- Fixed Docker staging installations being redirected to the configurator when
  database credentials are intentionally supplied through environment secrets.
- Switched CBR exchange-rate loading to verified HTTPS/XML parsing, migrated
  new deposit statistics to JSON, prohibited classes in legacy unserialization,
  and separated expected form aborts from unexpected runtime failures.
- Docker now reads database credentials directly from environment or `*_FILE`
  secrets; production also requires the new protected `APP_DATA_KEY` CI/CD
  variable for encrypted payment and wallet parameters.
- Updated vendored HTMX from 2.0.0 to 2.0.4 and reduced the CKEditor runtime
  distribution from all package translations/maps/types to the five supported
  interface languages and required runtime assets.
- Replaced one-second server-side clock polling with a local browser clock,
  restored payment-form auto-submit, and reduced active deposit polling.
- Unified user messages into one `/messages` conversation journal. Incoming
  and outgoing entries in the same thread now open as one dialog instead of
  appearing as unrelated messages.
- Reworked administrator ticket details as a chat, aligned the reply action
  with the input, labeled main administrators and support staff accurately,
  and made new/closed ticket states green/red respectively.
- Replaced the obsolete ePochta XML login/password integration with the HTTPS
  AtomPark/ePochta SMS API v3 public/private-key protocol, JSON responses,
  documented checksums, delivery-state polling, and normalized failures.
- Reworked deposit statistics into clearer summary cards and period results,
  with an HTML date picker, inclusive end dates, disabled-currency history,
  and explicit net-flow/deposit-volume labels.
- Made home-page news and review blocks honor their independent configured item
  counts, including one-item layouts, and clarified the difference between
  per-page and home-block limits in administration.
- Grouped FAQ entries under category headings outside the individual question
  cards and centered flash-message icons with their text.
- Updated the shared pagination spacing and review layout so the vertical gap
  above and below pagination is consistent.
- Restored the FAQ and news total counters independently of translated labels,
  and replaced the public authentication and home-page copyright text with
  canonical H-Script version and MIT license metadata.
- Reframed the public rules as official-demo terms, covering the MIT-licensed
  open-source model, third-party deployment responsibility, demo-only data and
  operations, vulnerability disclosure, prohibited research methods, warranty
  disclaimer and limitation of liability.
- Wrapped every outbound HTML email in a responsive H-Script branded layout
  with email-client-safe inline styling and a plain-text fallback for SMTP.
- Refined the email layout with a cleaner card, emphasized recipient and account
  names, improved confirmation-code styling, and removed the duplicate brand
  eyebrow above the message subject.
- Corrected the email logo to render as the `H` mark plus `Script`, and added
  spacing before confirmation links.
- Prevented mail clients from compressing the square email logo and removed
  non-breaking-space placeholders that could surface as stray characters.
- Confirmation now remembers whether the pending code came from e-mail or SMS
  and detects linked code length. Context-free visits use one universal field
  instead of assuming a six-digit code or asking users to select a format.
- Repeating a confirmation now uses its original e-mail or SMS channel, and an
  invalid code keeps the current input layout instead of switching to the
  six-digit SMS form.
- Email-client previews now use message content after the subject instead of
  repeating the subject or falling through to the `H-Script` wordmark; bold
  confirmation secrets are excluded from the preview text.
- Unified the public, authentication, confirmation, cabinet, administration,
  configurator and email branding around the home-page `H` mark plus `Script`
  logo; administration no longer substitutes a tools icon and `Admin` label.
- Mail delivery now selects SMTP when a host is configured, supports STARTTLS
  on port 587 and SMTPS on port 465, and preserves an existing SMTP password
  when the administration form is saved with an empty password field.
- Added install-only SMTP, sender, administrator mailbox, and mail-language
  variables to Docker Compose and GitLab staging deployment, including
  `INSTALL_MAIL_PASSWORD_FILE` support for mounted Docker secrets.
- Migrated email subjects and bodies from legacy `.lng` files into the JSON
  translation catalog. User notifications now follow `Users.uLang`, while
  administrator notifications follow `Sys.AdminLang`, with locale fallback.
  The administrator notification language is selectable in the main settings.
- Translation changes made in administration are stored as `Cfg` overrides
  instead of writing into the container image. The editor now works with one
  language at a time, includes an email-only filter, and links to a localized
  email preview on the mail settings page.
- Completed both bundled translation catalogs for every static Twig key and
  added CI checks for key parity, placeholder parity, encoding artifacts, and
  accidental Cyrillic or untranslated English-only values.
- Moved shared operation, deposit, user, ticket and SMS statuses plus the main,
  security, interface, telemetry, referral, user-list and deposit-list
  administration screens into the same localized catalog, removing mixed
  Russian/English output from those pages.
- Migrated database access from MySQLi to PDO prepared statements while keeping
  the established query placeholder API for legacy modules.
- Migrated all application templates from Smarty 5 `.tpl` files to Twig 3
  `.twig` templates.
- Replaced the monolithic UI stylesheet and Franken UI experiments with a
  Tailwind CSS build and the project's own design system.
- Replaced application jQuery and legacy page scripts with server-rendered
  interactions, vanilla browser primitives and targeted HTMX requests.
- Decomposed the monolithic payment gateway dispatcher into a PSR-4
  `PaymentManager`, a gateway interface and individual gateway strategy classes.
- Replaced the defunct Cryptonator ticker with the keyless Coinbase Data API
  exchange-rate endpoint for BTC, ETH, LTC and XRP, using the configured
  internal currency as the quote currency.
- Changed collector installation rows to foreground accepted top-ups and
  payouts instead of deposit lifecycle counts.
- Reset the public product version to `1.0.0` and renamed active legacy
  package/image identifiers to `h-script` / `H-Script`.
- Changed the release image target to `ghcr.io/0x241/h-script`.
- Documented promotion of each tested GitLab staging tree through the permanent
  clean-history `release/public` branch to canonical `main`, followed by a
  one-way GitHub mirror.
- Public aggregate telemetry is enabled by default on new installations and can
  be disabled independently from mandatory installation registration.
- Reworked public header branding into a clean `H-Script CMS` logo.
- Reworked the home page visual palette toward a Stripe-like blue/indigo style.
- Widened the operations badge and matched its icon size to the adjacent
  Bitcoin notification.
- Replaced the smart-contract badge with an operations-processed label and
  linked the footer documentation item to GitHub while keeping a separate FAQ.
- Centralized registration form rendering through `account/register/form.twig`.
- Reworked configurator pages: setup, install, modules, update, password and footer.
- Moved configurator flash messages outside the main content flow to avoid HTMX spacing issues.
- Moved the demo-mode notice from the home page to login and registration pages.
- Reworked showcase form rendering for auth pages, including inline checkbox labels and button-style secondary actions.
- Changed initial login captcha mode from always-on to auto mode for steadier Turnstile rendering.
- Configurator password now uses `password_hash` instead of weak MD5 storage.
- Legacy configurator MD5 password is still accepted and rehashed after successful login.
- PHP error display now depends on `APP_ENV` / `APP_DEBUG`.
- CSRF form certificate generation now uses `random_bytes(32)` and `hash_equals`.
- Turnstile test secret no longer passes in production.
- Initial install now persists Turnstile settings into the `Cfg` table when provided.
- Demo installs no longer force demo admins into the initial reconfiguration screen.
- Docker Compose can switch between a local build and a registry image through `APP_IMAGE`, `APP_IMAGE_TAG` and `APP_PULL_POLICY`.
- Admin settings forms use smaller semantic sections; checkbox fields no longer consume an entire grid row by default.
- Admin settings render one parameter per horizontal row on desktop and stack each row on mobile.
- Reworked the local telemetry settings into compact horizontal status and
  aggregate-control rows, with privacy details collapsed before the
  full-width statistics table.
- Restricted telemetry settings and collector statistics to the main
  `uLevel=99` administrator; demo administrators no longer see the route.
- Split local outbound telemetry from the central collector dashboard. The
  collector route is hidden unless collector mode is enabled on its configured
  domain.
- Clarified autonomous cron setup: Docker installations enable the separate
  scheduler automatically, while shared hosting must configure `/cron?auto`
  once per minute and then confirm it in the admin settings.
- Added class-level PHPDoc across `src/`, fully documented payment, cache,
  queue, and API response contracts, and added focused explanations to complex
  balance, deposit, and referral calculations.
- Converted `README.md`, `CHANGELOG.md`, `future_work.md`, and the project
  progress tracker to English and refreshed the future roadmap.
- Split the post-1.0.0 roadmap into five phase-based plans covering installer
  hardening, telemetry trust, financial correctness, browser security, and
  operations/supply-chain controls, with dependencies tracked in `progress.md`.

### Security

- Stopped exposing TOTP enrollment secrets to Google Charts, redacted payment
  callbacks before logs and e-mail, and sanitized administrator-authored news
  and FAQ HTML before persistence.
- Made PHP session garbage-collection lifetime configurable through
  `SESSION_GC_MAXLIFETIME` with a 30-day default, while retaining the
  application and administration idle-timeout checks.
- Replaced predictable login-recovery values with cryptographically random
  128-bit tokens and separated ordinary session recovery from persistent
  remember-me cookies.
- Preserved and authorized administrator impersonation state explicitly so an
  administrator can safely return from a user session without losing context.
- Migrated passwords, PIN codes and security answers from legacy MD5-derived
  values to bcrypt with automatic rehash after successful legacy verification.
- Replaced interpolated database access with PDO prepared statements and typed
  placeholders throughout the shared database layer.
- Enabled TLS peer and hostname verification for shared HTTP and payment clients.
- Removed error-suppression operators from project-owned PHP code and retained
  explicit production logging.
- Added CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy and
  Permissions-Policy response headers.
- Hardened sessions with strict mode, secure/HttpOnly/SameSite cookies, session
  ID rotation and a separate 15-minute idle timeout for administration pages.
- Public platform and processed counters now count only the earliest
  installation identity for each canonical domain; duplicate UUIDs remain
  visible to the collector administrator but cannot inflate public aggregates.
  Local, reserved and IP-only hostnames are also excluded from public totals.
- Installation telemetry excludes user-level records, never blocks installation,
  and stores only token hashes on the collector.
- The installation inventory API requires a separate hashed token per external
  service, applies per-token rate limits, and no longer shares one environment
  secret between all consumers.
- Incoming collector routes require both the explicit collector flag and an
  exact normalized match with `TELEMETRY_COLLECTOR_DOMAIN`.
- Production startup fails if the configurator password is absent.
- Production startup fails if Turnstile keys are required but missing.
- Session cookie defaults are hardened for production.
- `.dockerignore` now excludes local configs, caches, archives, IDE files, uploads and local theme assets.
- Shared-hosting `.htaccess` rules protect private directories, configuration files and executable uploads without Apache-only `php_flag` directives.

### Fixed

- Fixed Docker `APP_DEMO_MODE` detection so demo restrictions and login access
  details are applied consistently when demo mode is enabled by environment.
- Masked the recovery security-answer field by default and added an accessible
  eye control for showing and hiding password and answer values.
- Restored calendar day-type options and documented the accepted date format in
  review, calendar-day, and news administration forms.
- Fixed Safari layout differences in the public header and contacts page,
  including the theme/action divider and navigation spacing.
- Fixed GitHub Release publication silently skipping its script because the
  release container was started without attached standard input.

- Fixed the cabinet sidebar balance card appearing whenever only one external
  payment system was enabled; it is now reserved for internal-currency mode.
- Fixed shared-hosting package validation exiting with SIGPIPE after a
  successful build, and forced staging deploys to pull the exact `tree-*`
  candidate instead of a legacy `APP_IMAGE_TAG` project variable.
- Fixed home-page news/review limits being ignored and review pagination
  retaining asymmetric surrounding whitespace.
- Fixed unread message counts, premature read marking on send, whole-thread
  read state, and the ticket menu being highlighted on message pages.
- Prevented PHP's short default garbage-collection lifetime from invalidating
  otherwise active sessions and added recovery when the `PHPSESSID` cookie is
  lost while the scoped authentication cookie is still valid.
- Fixed administrator-to-user impersonation intermittently ending in access
  denial or an unrecoverable user session.
- Fixed deposit statistics period parsing, inclusive date boundaries, missing
  disabled currencies, misleading totals, and unsafe empty numeric values.
- Fixed message and ticket send buttons being vertically misaligned with their
  text areas and removed a duplicated broadcast-mode explanation.
- Restored channel-specific confirmation formats: email uses a random
  32-character hexadecimal token and SMS uses a random 6-digit code. Only a
  keyed 32-character truncated HMAC-SHA-256 is stored; existing plaintext tokens
  remain valid through the legacy lookup fallback.
- Long legacy confirmation links now render their code in a regular full-width
  field, while current 6-digit codes keep the segmented one-time-code input.
- Replaced raw operation identifiers such as `CALCIN` in email bodies with
  localized operation names, restored currency fallback from `cCurrID`, added
  spacing before operation links and removed decorative footer entities.
- Added actionable SMTP/native transport errors to application logs and queued
  job failures instead of storing only a generic email delivery error.
- Detect the missing native `sendmail` transport in Docker and direct the
  administrator to configure SMTP instead of silently accepting failed mail.
- Corrected support-ticket reply delivery so an administrator response uses the
  ticket owner's address and language instead of the responding administrator.
- Masked the outbound server IP address and skipped its external lookup while
  the application is running in demo mode.
- Kept authentication, registration, public, cabinet and administration pages
  on their exact current URL when switching between light and dark themes.
- Made the inviter optional when an administrator creates a user, while still
  validating and resolving an inviter login when one is supplied.
- Bumped the compiled CSS asset version so browsers and the staging CDN no
  longer reuse the pre-telemetry-layout stylesheet.
- Fixed empty `Depo_S0` values producing an epoch-based `20659`-day runtime;
  statistics now fall back to the recorded installation timestamp.
- Invalidated the collector public-metrics cache immediately after installation
  registration and heartbeat, so the home-page platform counter no longer waits
  for the previous five-minute cache entry to expire.
- Removed the obsolete raw `Theme` field from the administrator's user form;
  light/dark preference remains controlled by the interface switcher.
- Forwarded the HTTP `Authorization` header through the Docker Apache/FastCGI
  boundary so installation and service Bearer tokens reach the API.
- Applied the compact telemetry overview at the desktop `lg` breakpoint, keeping
  stage and local layouts consistent under Retina scaling and browser zoom.
- Fixed duplicated ticket page headings and duplicated footer navigation.
- Fixed flash messages leaving empty vertical space after fade-out.
- Fixed language switcher redirects and HTMX interception issues.
- Fixed registration warnings caused by missing array keys under PHP 8.4.
- Fixed configurator notification spacing and empty line rendering in HTMX flows.
- Fixed missing payment-system visual labels by adding client-side icon decoration.
- Fixed demo reset-password guard for protected system accounts.
- Fixed login/logout fatal error caused by invalid `$_GET` access.
- Fixed Turnstile sizing inside narrow forms and removed the captcha wrapper background.
- Fixed the mobile navigation overlay showing through the translucent sidebar.
- Fixed the public mobile menu background being limited to the 88px header containing block.
- Fixed the third home-page metric being hidden below the `sm` breakpoint.
- Fixed shorter typing-animation phrases aligning to the left of the
  `High-Scalable` slot instead of remaining centered.
- Fixed the extra leading gap before shorter typing-animation phrases by
  synchronizing the animated slot width with the active phrase.
- Fixed installation reports remaining in `HTTP 401 · invalid_token` after a
  collector reset by re-registering and retrying the report once.
- Restored the larger Inter black weight for `High-Tech`, `High-Yield` and
  `High-Scalable` while keeping the animated phrase centered.
- Prevented hyphenated hero phrases from wrapping inside the animated clipping
  container, so `High-Tech` is typed completely before the hold phase.
- Fixed expired authenticated sessions rendering the complete login page inside
  the existing admin/cabinet shell by converting HTMX redirects into a full
  browser navigation.
- Fixed the Configurator database updater on MySQL 8 by replacing obsolete
  `TYPE=` syntax and preparing each replacement table before an atomic swap, so
  a schema error no longer removes the live table. Docker startup also restores
  `Cfg` from the exact `_Cfg` state left by the former failed updater.

### Removed

- Removed the persistent Configurator installation section and the destructive
  `APP_INSTALL_FORCE` path. Initial web/Docker setup now accepts only an empty
  database.
- Removed the inactive legacy `_a-ddos` browser-loop challenge. It was not
  loaded by the application and did not provide request-rate or DDoS protection.
- Removed InvestorsStartPage authorization completely, including its route,
  settings, templates and translations.
- Removed legacy reCAPTCHA v1, SMSPilot, the request-driven cron fallback,
  dormant guest-support code, unused Smarty compatibility methods and confirmed
  orphan controllers/templates.
- Removed the separate user inbox/outbox pages in favor of the unified
  conversation journal.
- Removed the public `/support` route and its dormant implementation; authenticated
  support requests use tickets.
- Removed database-backed custom pages, their administration UI, route, cache
  invalidation, and schema definition. The cleanup migration drops `Pages`.
- Removed the obsolete Gravatar switch and fallback so account avatars use only
  the custom uploaded-image/initials implementation.
- Removed legacy ePochta XML credentials and request code; empty API v3 key
  fields preserve the existing secret in administration.
- Removed the discontinued uLogin.ru and Loginza registration/authentication
  routes, widgets, account controls, templates, and administration setting. The
  Configurator update also deletes the retired `Account/Loginza` configuration
  row from existing installations.
- Removed Smarty 5, all application `.tpl` templates and the bundled
  `lib/smarty` fallback.
- Removed jQuery, obsolete application JavaScript and the old
  `hs-fintech.css` stylesheet.
- Removed the monolithic `lib/psys.php`, obsolete gateway SDKs and defunct
  payment gateway identifiers without active runtime support.
- Removed Franken UI dependencies and prototypes from the production frontend.
- Removed the GitHub Actions image-publishing workflow; release owners publish
  tested semver images explicitly, while end users only pull the ready image.
- Removed the remaining commented `chkLic` calls and legacy `.lic` validation
  implementation from the runtime and template layer.

### Notes

- Existing installations must apply both `20260811` migrations and
  `20260812_remove_legacy_integrations.sql` after a verified
  backup. Configure ePochta API v3 public/private keys before re-enabling that
  provider; the old login/password values cannot be migrated.
- Staging may set `SESSION_GC_MAXLIFETIME=2592000` explicitly. It is not a
  secret, and the same value is used by default when the CI/CD variable is
  absent.
- `APP_DEMO_MODE=1` enables demo mode for first installation. Existing databases are not rewritten by changing the env variable later.
