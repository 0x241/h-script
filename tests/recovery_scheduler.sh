#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
scheduler="$root/docker/runtime/recovery.sh"
if [ ! -f "$scheduler" ]; then scheduler=$(command -v hscript-recovery-scheduler); fi
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT HUP INT TERM
mkdir -p "$temporary/bin" "$temporary/mock"
cp "$root/bin/recovery-once.sh" "$temporary/bin/recovery-once.sh"
cp "$root/tests/fixtures/mock-recovery-php.sh" "$temporary/mock/php"
cp "$root/tests/fixtures/mock-recovery-sleep.sh" "$temporary/mock/sleep"
chmod 0700 "$temporary/mock/php" "$temporary/mock/sleep"
export PATH="$temporary/mock:$PATH"
export RECOVERY_TEST_CALLS="$temporary/calls"
cd "$temporary"
for failure in none backup bundle drill policy; do
    export RECOVERY_TEST_FAIL="$failure"
    : > "$RECOVERY_TEST_CALLS"
    status=0
    sh bin/recovery-once.sh > "$temporary/output" 2>&1 || status=$?
    if [ "$failure" = none ]; then test "$status" -eq 0; else test "$status" -ne 0; fi
    grep -q drill-if-due "$RECOVERY_TEST_CALLS"
    grep -q policy "$RECOVERY_TEST_CALLS"
    if [ "$failure" = backup ]; then
        grep -q record-local-backup-failure "$RECOVERY_TEST_CALLS"
        if grep -q bundle "$RECOVERY_TEST_CALLS"; then exit 1; fi
    fi
    status=0
    RECOVERY_ENABLED=1 RECOVERY_RUN_ONCE=1 sh "$scheduler" > "$temporary/output" 2>&1 || status=$?
    if [ "$failure" = none ]; then test "$status" -eq 0; else test "$status" -ne 0; fi
done
export RECOVERY_TEST_FAIL=backup
: > "$RECOVERY_TEST_CALLS"
status=0
RECOVERY_ENABLED=1 RECOVERY_RUN_ONCE=0 sh "$scheduler" > "$temporary/output" 2>&1 || status=$?
test "$status" -eq 42
grep -q retry-wait "$RECOVERY_TEST_CALLS"
echo 'Recovery one-shot failures and scheduler retry tests passed.'
