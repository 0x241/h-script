#!/bin/sh
set -eu

to_lower() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

is_enabled() {
    VALUE="$(to_lower "${RECOVERY_ENABLED:-0}")"
    [ "$VALUE" = "1" ] || [ "$VALUE" = "true" ] || [ "$VALUE" = "yes" ] || [ "$VALUE" = "on" ]
}

if ! is_enabled; then
    echo "H-Script recovery scheduler is disabled by RECOVERY_ENABLED=${RECOVERY_ENABLED:-0}"
    while true; do sleep 3600; done
fi

INTERVAL="${RECOVERY_BACKUP_INTERVAL_SECONDS:-3600}"
case "$INTERVAL" in
    ''|*[!0-9]*) echo "RECOVERY_BACKUP_INTERVAL_SECONDS must be a positive integer" >&2; exit 1 ;;
esac
if [ "$INTERVAL" -lt 60 ]; then
    echo "RECOVERY_BACKUP_INTERVAL_SECONDS must be at least 60" >&2
    exit 1
fi

echo "H-Script recovery scheduler started; interval: ${INTERVAL}s"
while true; do
    result=0
    sh bin/recovery-once.sh || result=$?
    if [ "${RECOVERY_RUN_ONCE:-0}" = "1" ]; then exit "$result"; fi
    if [ "$result" -ne 0 ]; then
        echo "H-Script recovery cycle failed; the next scheduled attempt is retained" >&2
    fi
    sleep "$INTERVAL"
done
