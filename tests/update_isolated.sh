#!/bin/sh
# Each invocation owns only uniquely named disposable Docker resources.
set -eu
set +x
: "${1:?application image required}" "${2:?database image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-migrations-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
application="$name-app"
redis="$name-redis"
browser="$name-browser"
network_created=0
database_created=0
application_created=0
redis_created=0
browser_created=0
cleanup() {
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$redis_created" = 1 ]; then docker rm -fv "$redis" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database \
    --tmpfs /var/lib/mysql:rw,size=1g \
    --env MYSQL_ROOT_PASSWORD=synthetic-isolated-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-isolated-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --name "$redis" --network "$name" --network-alias redis \
    redis:8-alpine@sha256:becdda6c7f4b3fb42e42fd7f120bbf5c54c4caaaf16f26da24e4563d2c1f0576 >/dev/null
redis_created=1
docker start "$redis" >/dev/null
docker create --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh \
    --env "H_SCRIPT_BROWSER_MODE=${H_SCRIPT_BROWSER_IMAGE:+1}" \
    --env APP_ENV=development --env APP_DOMAIN=fixture.invalid --env REDIS_ENABLED=0 \
    --env APP_DATA_KEY=synthetic-isolated-key --env CONFIGURATOR_PASSWORD=synthetic-isolated-configurator \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-isolated-password \
    --env INSTALL_ADMIN_PASSWORD=synthetic-isolated-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env H_SCRIPT_UPDATE_DB_TEST=1 --env H_SCRIPT_UPDATE_SERVICE_TEST=1 \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        php /usr/local/share/hscript/write-config.php
        attempt=0
        until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"), getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD")) ? 0 : 1);'\''; do
            attempt=$((attempt + 1))
            test "$attempt" -lt 120
            sleep 1
        done
        APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
        export APP_AUTO_INSTALL=0
        php tests/update_runtime_database.php
        php tests/backup_json_integration.php
        php tests/update_package.php
        php tests/update_docker_code_only_integration.php
        php tests/update_service_integration.php
        php tests/update_external_engine.php
        php tests/incident_response_integration.php
        php tests/update_reconciliation_integration.php
        php tests/update_production_preflight_integration.php
        php tests/update_migration_integration.php
        if [ "$H_SCRIPT_BROWSER_MODE" = 1 ]; then
            php tests/fixtures/browser_setup.php
            export APP_GENERATE_CONFIG=0
            exec docker-entrypoint-hscript httpd -DFOREGROUND -f /etc/apache2/httpd.conf
        fi
    ' >/dev/null
application_created=1
if [ -n "${H_SCRIPT_BROWSER_IMAGE:-}" ]; then
    docker start "$application" >/dev/null
    attempt=0
    until docker exec "$application" curl -fsS --max-time 2 -H 'Host: fixture.invalid' http://127.0.0.1/login >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 240 ] || [ "$(docker inspect "$application" --format '{{.State.Running}}')" != true ]; then
            docker logs --tail 80 "$application"
            exit 1
        fi
        sleep 1
    done
    docker logs --tail 80 "$application"
    docker create --name "$browser" --network "$name" --init --shm-size=1g "$H_SCRIPT_BROWSER_IMAGE" >/dev/null
    browser_created=1
    docker start -a "$browser"
    test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
else
    docker start -a "$application"
    test "$(docker inspect "$application" --format '{{.State.ExitCode}}')" = 0
fi
echo 'Isolated updater/migration suite passed.'
