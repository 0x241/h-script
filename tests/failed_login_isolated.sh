#!/bin/sh
# Run from the local Compose root; every database/container is disposable and uniquely named.
set -eu
set +x
: "${1:?application image required}" "${2:?database image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-login-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
application="$name-app"
browser="$name-browser"
network_created=0 database_created=0 application_created=0 browser_created=0
cleanup() {
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-login-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-login-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh -w /var/www/html \
    --env "H_SCRIPT_LOGIN_BROWSER=${H_SCRIPT_LOGIN_BROWSER_IMAGE:+1}" \
    --env H_SCRIPT_LOGIN_THRESHOLD_TEST=1 --env APP_DOMAIN=fixture.invalid --env APP_ENV=development --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-login-password \
    --env APP_DATA_KEY=synthetic-login-key --env CONFIGURATOR_PASSWORD=synthetic-login-configurator \
    --env INSTALL_ADMIN_PASSWORD=synthetic-login-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env CRON_TOKEN=synthetic-isolated-cron-token-00000001 \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        attempt=0
        until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
            attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
        done
        php tests/failed_login_policy.php
        php tests/failed_login_database.php
        php tests/cron_telemetry_database.php
        if [ "$H_SCRIPT_LOGIN_BROWSER" = 1 ]; then
            # Only this isolated fixture is recreated before its first application bootstrap.
            php -r '\''if(getenv("DB_NAME")!=="fixture"||getenv("H_SCRIPT_LOGIN_THRESHOLD_TEST")!=="1")exit(2); require "vendor/autoload.php"; $db=new HScript\Database\Connection(); $db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD")); foreach($db->link->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table)$db->link->exec("DROP TABLE `".str_replace("`","``",$table)."`");'\''
            php /usr/local/share/hscript/write-config.php
            APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
            export APP_AUTO_INSTALL=0
            php tests/fixtures/login_threshold_setup.php
            export APP_GENERATE_CONFIG=0
            exec docker-entrypoint-hscript httpd -DFOREGROUND -f /etc/apache2/httpd.conf
        fi
    ' >/dev/null
application_created=1
if [ -n "${H_SCRIPT_LOGIN_BROWSER_IMAGE:-}" ]; then
    docker start "$application" >/dev/null
    attempt=0
    until docker exec "$application" curl -fsS --max-time 2 -H 'Host: fixture.invalid' http://127.0.0.1/login >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 180 ] || [ "$(docker inspect "$application" --format '{{.State.Running}}')" != true ]; then docker logs --tail 60 "$application"; exit 1; fi
        sleep 1
    done
    docker logs --tail 60 "$application"
    docker create --name "$browser" --network "$name" --init --shm-size=1g \
        -v "$root/tests/browser/login-threshold.config.cjs:/tests/login-threshold.config.cjs:ro" \
        -v "$root/tests/browser/login-threshold.spec.cjs:/tests/login-threshold.spec.cjs:ro" \
        "$H_SCRIPT_LOGIN_BROWSER_IMAGE" npx --no-install playwright test --config=login-threshold.config.cjs >/dev/null
    browser_created=1
    docker start -a "$browser" || :
    docker exec "$application" php tests/fixtures/login_threshold_verify.php
    test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
else
    docker start -a "$application"
    test "$(docker inspect "$application" --format '{{.State.ExitCode}}')" = 0
fi
echo 'Isolated login threshold suite passed.'
