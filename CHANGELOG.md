# Changelog

All notable changes to H-Script are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

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

### Changed

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
- `APP_INSTALL_FORCE=1` recreates a non-empty database and deletes existing tables.
