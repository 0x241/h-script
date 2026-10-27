#!/bin/sh
set -eu
case "$2" in
    mysql84) image='mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a' ;;
    mariadb1011) image='mariadb:10.11@sha256:07c0aaff7396b74cb7975cba78257178d188e30f531a5db2b617c48beef13c41' ;;
    mariadb114) image='mariadb:11.4@sha256:80494b9810694179889f7281ec44ca928241df577159c0356a1070e2e94616a1' ;;
    *) exit 2 ;;
esac
printf 'database=%s\n' "$image"
for suite in update_isolated telemetry_isolated failed_login_isolated financial_isolated idempotency_isolated; do
    started=$(date +%s)
    printf 'engine=%s suite_started=%s\n' "$2" "$suite"
    H_SCRIPT_FINANCIAL_CANDIDATE=1 sh "tests/$suite.sh" "$1" "$image"
    printf 'engine=%s suite_passed=%s duration_seconds=%s\n' "$2" "$suite" "$(($(date +%s) - started))"
done
