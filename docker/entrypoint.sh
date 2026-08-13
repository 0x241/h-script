#!/bin/sh
set -eu

APP_ENV_VALUE="$(printf '%s' "${APP_ENV:-production}" | tr '[:upper:]' '[:lower:]')"
APP_DEBUG_VALUE="$(printf '%s' "${APP_DEBUG:-0}" | tr '[:upper:]' '[:lower:]')"

has_value_or_file() {
    eval "VALUE=\${$1:-}"
    eval "FILE_VALUE=\${$1_FILE:-}"
    [ -n "$VALUE" ] || { [ -n "$FILE_VALUE" ] && [ -r "$FILE_VALUE" ]; }
}

mkdir -p /run/apache2 /var/log/apache2 logs tpl_c upload compile backup .cfg
chown -R www-data:www-data logs tpl_c upload compile backup .cfg 2>/dev/null || true

if [ "$APP_ENV_VALUE" = "dev" ] || [ "$APP_ENV_VALUE" = "development" ] || [ "$APP_ENV_VALUE" = "local" ] \
    || [ "$APP_DEBUG_VALUE" = "1" ] || [ "$APP_DEBUG_VALUE" = "true" ] || [ "$APP_DEBUG_VALUE" = "yes" ] || [ "$APP_DEBUG_VALUE" = "on" ]; then
    DISPLAY_ERRORS="On"
    STARTUP_ERRORS="On"
    ERROR_REPORTING="E_ALL"
    OPCACHE_VALIDATE="1"
else
    DISPLAY_ERRORS="Off"
    STARTUP_ERRORS="Off"
    ERROR_REPORTING="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_STRICT"
    OPCACHE_VALIDATE="0"
fi

if [ -n "${SESSION_COOKIE_SECURE+x}" ]; then
    COOKIE_SECURE="$SESSION_COOKIE_SECURE"
elif [ "$APP_ENV_VALUE" = "dev" ] || [ "$APP_ENV_VALUE" = "development" ] || [ "$APP_ENV_VALUE" = "local" ]; then
    COOKIE_SECURE="0"
else
    COOKIE_SECURE="1"
fi

cat > /usr/local/etc/php/conf.d/99-runtime.ini <<INI
display_errors = ${DISPLAY_ERRORS}
display_startup_errors = ${STARTUP_ERRORS}
error_reporting = ${ERROR_REPORTING}
opcache.validate_timestamps = ${OPCACHE_VALIDATE}
session.cookie_secure = ${COOKIE_SECURE}
session.cookie_samesite = ${SESSION_COOKIE_SAMESITE:-Lax}
INI

sed -i "s/^ServerName .*/ServerName ${APP_DOMAIN:-localhost}/" /etc/apache2/httpd.conf

if [ "${APP_GENERATE_CONFIG:-1}" != "0" ]; then
    if [ -z "${DB_HOST:-}" ] || [ -z "${DB_NAME:-}" ] || [ -z "${DB_USER:-}" ] || ! has_value_or_file DB_PASSWORD; then
        if [ ! -f /var/www/html/_config.php ]; then
            echo "DB_HOST, DB_NAME, DB_USER and DB_PASSWORD are required to generate _config.php" >&2
            exit 1
        fi
    else
        if [ "$APP_ENV_VALUE" != "dev" ] && [ "$APP_ENV_VALUE" != "development" ] && [ "$APP_ENV_VALUE" != "local" ] \
            && [ "${REQUIRE_TURNSTILE:-1}" != "0" ] \
            && { ! has_value_or_file TURNSTILE_SITE_KEY || ! has_value_or_file TURNSTILE_SECRET_KEY; }; then
            echo "TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are required in production" >&2
            exit 1
        fi
        php /usr/local/share/hscript/write-config.php
        php /usr/local/share/hscript/install-db.php
    fi
fi

if [ "$APP_ENV_VALUE" != "dev" ] && [ "$APP_ENV_VALUE" != "development" ] && [ "$APP_ENV_VALUE" != "local" ] \
    && [ "${ALLOW_EMPTY_CONFIGURATOR_PASSWORD:-0}" != "1" ] \
    && ! has_value_or_file CONFIGURATOR_PASSWORD \
    && [ ! -s /var/www/html/module/_config/pass ]; then
    echo "CONFIGURATOR_PASSWORD is required in production when module/_config/pass is absent" >&2
    exit 1
fi

if [ "$APP_ENV_VALUE" != "dev" ] && [ "$APP_ENV_VALUE" != "development" ] && [ "$APP_ENV_VALUE" != "local" ] \
    && ! has_value_or_file APP_DATA_KEY; then
    echo "APP_DATA_KEY is required in production to encrypt persisted integration secrets" >&2
    exit 1
fi

if [ "${START_PHP_FPM:-1}" != "0" ]; then
    php-fpm -D
fi
exec "$@"
