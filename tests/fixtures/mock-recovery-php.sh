#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$RECOVERY_TEST_CALLS"
case "$*" in
    *bin/backup.php*create*) test "${RECOVERY_TEST_FAIL:-}" != backup ;;
    *bundle*) test "${RECOVERY_TEST_FAIL:-}" != bundle ;;
    *drill-if-due*) test "${RECOVERY_TEST_FAIL:-}" != drill ;;
    *policy*) test "${RECOVERY_TEST_FAIL:-}" != policy ;;
esac
