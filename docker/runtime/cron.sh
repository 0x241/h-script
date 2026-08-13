#!/bin/sh
set -eu

to_lower() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

is_disabled() {
    VALUE="$(to_lower "${CRON_ENABLED:-1}")"
    [ "$VALUE" = "0" ] || [ "$VALUE" = "false" ] || [ "$VALUE" = "no" ] || [ "$VALUE" = "off" ]
}

if is_disabled; then
    echo "H-Script cron is disabled by CRON_ENABLED=${CRON_ENABLED:-1}"
    while true; do
        sleep 3600
    done
fi

CRON_URL="${CRON_URL:-http://app/cron}"
CRON_HOST_HEADER="${CRON_HOST_HEADER:-${APP_DOMAIN:-localhost}}"
CRON_INTERVAL_SECONDS="${CRON_INTERVAL_SECONDS:-60}"
CRON_TIMEOUT_SECONDS="${CRON_TIMEOUT_SECONDS:-30}"
CRON_START_DELAY_SECONDS="${CRON_START_DELAY_SECONDS:-10}"

echo "H-Script cron started: ${CRON_URL}, Host: ${CRON_HOST_HEADER}, interval: ${CRON_INTERVAL_SECONDS}s"
sleep "$CRON_START_DELAY_SECONDS"

while true; do
    TS="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    if curl -fsS --max-time "$CRON_TIMEOUT_SECONDS" -H "Host: ${CRON_HOST_HEADER}" "$CRON_URL" -o /dev/null; then
        echo "${TS} cron ok"
    else
        echo "${TS} cron failed" >&2
    fi
    sleep "$CRON_INTERVAL_SECONDS"
done
