#!/bin/sh
set -eu

RESULT_FILE="$(mktemp /tmp/hscript-database-backup.XXXXXX)"
trap 'rm -f "$RESULT_FILE"' EXIT HUP INT TERM
result=0

if php -d display_errors=stderr bin/backup.php create > "$RESULT_FILE"; then
    if ! php -d display_errors=stderr bin/recovery.php bundle --backup-result="$RESULT_FILE"; then
        echo "H-Script local recovery bundle failed after local database backup" >&2
        result=1
    fi
else
    php -d display_errors=stderr bin/recovery.php record-local-backup-failure || true
    echo "H-Script scheduled database backup failed" >&2
    result=1
fi

php -d display_errors=stderr bin/recovery.php drill-if-due || result=1
php -d display_errors=stderr bin/recovery.php policy || result=1
exit "$result"
