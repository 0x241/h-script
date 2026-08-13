# Database migrations

Run migrations once against an existing database from the Docker Compose root after taking a backup. Do not enable `APP_AUTO_INSTALL` to apply schema updates.

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
These scripts are explicit update steps; do not enable `APP_AUTO_INSTALL` or
`APP_INSTALL_FORCE` to apply them.

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

Add the central installation registry and daily aggregate reports. Run this
only on the central H-Script collector; ordinary installations do not need to
enable the collector API:

```sh
docker compose exec -T database sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrations/20260725_add_installation_telemetry.sql
```

On staging, the Configurator database update is an alternative to the command
above and also synchronizes the `Const_DBVer` schema marker. Back up the
database first and run it once after deploying the telemetry schema. Never use
`APP_AUTO_INSTALL` or `APP_INSTALL_FORCE` as a migration mechanism.

The migration creates `Installations`, `InstallationReports`, and
`TelemetryServiceTokens`. The central `/admin/setup/collector` route reads the
database directly and issues a separate hashed `hst_…` token per external
service. It is available only when the current administrator has `uLevel=99`,
collector mode is enabled, and the request domain equals
`TELEMETRY_COLLECTOR_DOMAIN`. Ordinary installations keep only their local
outbound telemetry page at `/admin/setup/telemetry`.
