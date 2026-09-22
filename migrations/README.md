# Database migrations

Run migrations once against an existing database from the Docker Compose root after taking a backup. Do not enable `APP_AUTO_INSTALL` to apply schema updates.

Store a review display author independently from an optional user account. This
allows an administrator to specify either an existing or a new username while
preserving the account link for existing users:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < www/h-script-3/migrations/20260825_add_review_author.sql
```

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260716_add_review_rating.sql
```

Repair news settings saved under the wrong module key by the shared admin setup controller:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260718_repair_news_settings.sql
```

Remove the obsolete Gravatar switch and legacy ePochta XML credentials. The
migration disables ePochta when API v3 keys have not yet been saved; configure
the public/private keys in the SMS settings before enabling the provider again:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260811_remove_gravatar_and_legacy_epochta.sql
```

Remove the retired database-backed custom-page module. This drops `Pages` and
therefore permanently deletes its content; take a backup first:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260811_remove_custom_pages.sql
```

For the `feature/debug` update, run the two `20260811` migrations in the order
shown above after deploying the matching application code. Verify the backup
before the custom-page migration because dropping `Pages` is irreversible.
These scripts are explicit update steps; do not enable `APP_AUTO_INSTALL` to
apply them. It only bootstraps an empty initial database.

Remove InvestorsStartPage authorization, reCAPTCHA v1, SMSPilot and the retired
request-driven cron flag. The migration preserves ePochta API v3 and Turnstile:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260812_remove_legacy_integrations.sql
```

Add REST API v1 token and rate-limit storage:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260724_add_api_v1.sql
```

After the migration, issue a token inside the application container. The plaintext
token is shown only once:

```sh
docker compose exec app php bin/api-token.php create 1 integration '*'
```

Use a comma-separated scope list instead of `*` to restrict a token. Available
scopes are `user:read`, `balance:read`, `operations:read`, `deposit:write`, and
`withdraw:write`.

The legacy SQL bootstrap below creates the original central installation
registry. Run it only when reconstructing a pre-versioned collector database;
ordinary current updates must not invoke it directly:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260725_add_installation_telemetry.sql
```

После перехода на явные версии конфигуратор больше не перестраивает таблицы по
`_dbstru.php`. Существующая установка один раз фиксирует фактические исходные
версии командой `APP_DOMAIN=example.com php bin/update.php bootstrap
--acknowledge-application=<installed-cms-version>
--acknowledge-schema=1.0.0`, после чего
новые изменения схемы проходят только через `migrations/versioned` и общий
реестр. Никогда не используйте `APP_AUTO_INSTALL` как механизм миграции: он
работает только при первичной инициализации пустой базы.

`versioned/202609101200_telemetry_ingestion.php` is the current
backup-required transition from schema `1.0.0` to `1.0.1`. It upgrades or
creates `Installations`, `InstallationReports`, and
`TelemetryServiceTokens`; adds `InstallationIpHistory`,
`InstallationDomainEvents`, and `TelemetryIngestionCounters`; backfills report
sequence/hash metadata; and installs the indexes used by bounded collector
lists. Apply it only through the authenticated Configurator/common update
service. Docker uses the bundled migration action after the new exact image is
running; shared hosting applies it as part of the verified official archive.
The service acquires the migration lock and refuses this transition until a
verified backup has been attached.

The central `/admin/setup/collector` route reads the database directly and
issues a separate hashed `hst_…` token per external service. It is available
only when the current administrator has `uLevel=99`, collector mode and
ingestion are enabled, the request domain equals
`TELEMETRY_COLLECTOR_DOMAIN`, and schema `1.0.1` is current. Ordinary
installations keep only their local outbound telemetry page at
`/admin/setup/telemetry`.
