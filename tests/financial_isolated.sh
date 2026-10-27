#!/bin/sh
# Run from the local Compose project root. No application/database mounts are writable.
set -eu
set +x
: "${1:?PHP application image required}" "${2:?MySQL image required}"
application_image=$1
database_image=$2
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
set -- -v "$root:/var/www/html:ro"
if [ "${H_SCRIPT_FINANCIAL_CANDIDATE:-0}" = 1 ]; then
    # CI verifies installed candidate code; only tests are supplied externally.
    set -- -v "$root/tests:/var/www/html/tests:ro"
fi
property_path=
if [ -n "${H_SCRIPT_FINANCIAL_PROPERTY_FILE:-}" ]; then
    test -f "$H_SCRIPT_FINANCIAL_PROPERTY_FILE"
    set -- "$@" -v "$H_SCRIPT_FINANCIAL_PROPERTY_FILE:/tmp/financial-property-cases.json:ro"
    property_path=/tmp/financial-property-cases.json
fi
temporary=$(mktemp -d)
cat > "$temporary/_config.php" <<'PHP'
<?php
$_cfg = ['db_credentials_env' => true, 'db_host' => getenv('DB_HOST'), 'db_name' => getenv('DB_NAME')];
PHP
printf '<?php\n' > "$temporary/_config.local.php"
set -- "$@" -v "$temporary/_config.php:/var/www/html/_config.php:ro"
# A read-only source bind cannot create a missing nested mountpoint.
if [ "${H_SCRIPT_FINANCIAL_CANDIDATE:-0}" = 1 ] || [ -f "$root/_config.local.php" ]; then
    set -- "$@" -v "$temporary/_config.local.php:/var/www/html/_config.local.php:ro"
fi
name="hscript-financial-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
network_created=0
database_created=0
cleanup() {
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database \
    --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-financial-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-financial-password "$database_image" \
    --log-bin-trust-function-creators=1 >/dev/null
database_created=1
docker start "$database" >/dev/null
# Prepare only the fresh test schema before granting table-specific SELECT rights.
docker exec --env MYSQL_PWD=synthetic-financial-root "$database" /bin/sh -eu -c '
    if command -v mariadb >/dev/null 2>&1; then client=mariadb; else client=mysql; fi
    attempt=0
    until "$client" --user=root -e "SELECT 1" >/dev/null 2>&1; do
        attempt=$((attempt + 1)); test "$attempt" -lt 90; sleep 1
    done
'
docker run --rm --network "$name" --entrypoint php \
    --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
    --env DB_HOST=database:3306 --env DB_NAME=fixture \
    --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
    "$@" -w /var/www/html "$application_image" -r '
        set_exception_handler(static function (Throwable $e) { fwrite(STDERR, "Synthetic fixture schema preparation failed: " . $e->getMessage() . PHP_EOL); exit(1); });
        // The temporary initialization server may accept socket queries before TCP is ready.
        for ($attempt = 0; ; $attempt++) {
            try { new PDO("mysql:host=database;dbname=fixture", getenv("DB_USER"), getenv("DB_PASSWORD")); break; }
            catch (Throwable $e) { if ($attempt >= 89) throw $e; sleep(1); }
        }
        require "tests/fixtures/financial_runtime.php";
    '
# Least-privilege CLI checks use a separate SELECT-only account in this fresh DB.
docker exec --env MYSQL_PWD=synthetic-financial-root "$database" /bin/sh -eu -c '
    if command -v mariadb >/dev/null 2>&1; then client=mariadb; else client=mysql; fi
    "$client" --user=root <<SQL
CREATE USER '\''fixture_readonly'\''@'\''%'\'' IDENTIFIED BY '\''synthetic-readonly-password'\'';
GRANT SELECT ON fixture.Users TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Wallets TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Opers TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Deps TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Currs TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Plans TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Hist TO '\''fixture_readonly'\''@'\''%'\'';
GRANT SELECT ON fixture.Cfg TO '\''fixture_readonly'\''@'\''%'\'';
SQL
'
# Optional genuine previous-image producer: keep its rows in this fresh fixture
# while the candidate verifies the legacy registration-bonus cutover.
if [ -n "${H_SCRIPT_FINANCIAL_REGISTRATION_LEGACY_IMAGE:-}" ]; then
    docker run --rm --network "$name" --entrypoint php \
        --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
        --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
        "$@" -w /var/www/html "$H_SCRIPT_FINANCIAL_REGISTRATION_LEGACY_IMAGE" tests/financial_registration_once.php legacy-produce
    docker run --rm --network "$name" --entrypoint php \
        --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
        --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
        "$@" -w /var/www/html "$application_image" tests/financial_registration_once.php legacy-consume
fi
# Genuine old-source cutover shares only this fresh fixture database.
if [ -n "${H_SCRIPT_FINANCIAL_REFERRAL_LEGACY_IMAGE:-}" ]; then
    docker run --rm --network "$name" --entrypoint php \
        --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
        --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
        -v "$root/tests:/var/www/html/tests:ro" \
        -v "$temporary/_config.php:/var/www/html/_config.php:ro" \
        -v "$temporary/_config.local.php:/var/www/html/_config.local.php:ro" \
        -w /var/www/html "$H_SCRIPT_FINANCIAL_REFERRAL_LEGACY_IMAGE" tests/financial_referral_once.php legacy-produce
    docker run --rm --network "$name" --entrypoint php \
        --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
        --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
        "$@" -w /var/www/html "$application_image" tests/financial_referral_once.php legacy-consume
fi
docker run --rm --network "$name" --entrypoint /bin/sh \
    --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_FINANCIAL_FIXTURE=1 --env APP_ENV=local --env APP_DOMAIN=hs.local \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-financial-password \
    --env OBSERVABILITY_LOG_PATH=/tmp/financial-log.ndjson --env OBSERVABILITY_METRICS_PATH=/tmp/financial-metrics.ndjson \
    --env "H_SCRIPT_FINANCIAL_SNAPSHOT_FILE=${H_SCRIPT_FINANCIAL_SNAPSHOT_FILE:-}" \
    --env "H_SCRIPT_FINANCIAL_PROPERTY_FILE=$property_path" \
    "$@" -w /var/www/html "$application_image" -eu -c '
        attempt=0
        until php -r '\''try { new PDO("mysql:host=database;dbname=fixture",getenv("DB_USER"),getenv("DB_PASSWORD")); } catch (Throwable) { exit(1); }'\''; do
            attempt=$((attempt + 1)); test "$attempt" -lt 90; sleep 1
        done
        php tests/decimal_money.php
        php tests/decimal_exchange.php
        php tests/decimal_deposit.php
        php tests/decimal_referral.php
        php tests/decimal_output.php
        php tests/decimal_forecast.php
        php tests/decimal_aggregate.php
        php tests/decimal_plan.php
        php tests/decimal_configuration.php
        php tests/decimal_closure.php
        php tests/gateway_decimal_wire.php
        php tests/financial_properties.php
        php tests/financial_reconciliation_cli.php
        php tests/financial_concurrency.php
        php tests/financial_deposit_current.php
        php tests/financial_manual_batch.php
        php tests/financial_registration_once.php
        php tests/financial_registration_recovery.php
        php tests/financial_referral_once.php
        php tests/financial_state_recovery.php
        php tests/financial_decimal_money.php
        php tests/financial_decimal_exchange.php
        php tests/financial_decimal_rates.php
        php tests/financial_decimal_deposit.php
        php tests/financial_decimal_referral.php
        php tests/financial_decimal_output.php
        php tests/financial_decimal_forecast.php
        php tests/financial_decimal_aggregate.php
        php tests/financial_decimal_plan.php
        php tests/financial_decimal_configuration.php
        php tests/financial_decimal_public.php
        php tests/financial_invariants.php
        php tests/financial_idempotency.php
        php tests/financial_webhook_contract.php
        php tests/financial_inbound_intent.php
        php tests/financial_inbound_profile.php
        php tests/financial_ccapi_contract.php
        php tests/financial_paykassa_contract.php
        php tests/financial_paykassa_verification.php
        php tests/financial_volet_contract.php
        php tests/financial_paw_provider.php
        php tests/financial_snapshot_fidelity.php
        php -d memory_limit=512M tests/financial_snapshot_replay.php
        php -d memory_limit=512M tests/financial_stand_lifecycle.php
        php tests/financial_shared_provider.php
        php tests/menu_pins.php
        php tests/financial_token_routes.php
        php tests/financial_token_catalog.php
        php tests/faq_categories_sql.php
        php tests/financial_paw_native.php
        php tests/financial_ccapi_payout.php
        php tests/financial_currency_rates.php
        php tests/financial_paw_payout.php
        php tests/financial_paykassa_assets.php
        php tests/financial_coinpayments_new.php
        php tests/financial_legacy_provider.php
        php tests/financial_legacy_account.php
        php tests/financial_currency_archive.php
        php tests/financial_currency_activation.php
    '
