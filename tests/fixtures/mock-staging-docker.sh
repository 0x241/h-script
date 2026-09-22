#!/bin/sh
set -eu
case "$*" in
    'image inspect '*) printf 'sha256:local-image-id\n' ;;
    'ps -aq '*|'ps -q '*)
        case "$MOCK_STAGING" in
            missing) exit 0 ;;
            duplicate) printf 'container-a\ncontainer-b\n' ;;
            *) printf 'container-a\n' ;;
        esac ;;
    *'{{.State.Running}}')
        if [ "$MOCK_STAGING" = stopped ]; then echo false; else echo true; fi ;;
    *'{{.Config.Image}}')
        if [ "$MOCK_STAGING" = moved ]; then echo 'registry.invalid/app:latest'; else printf '%s@%s\n' "${CANDIDATE_REF%@*}" "$CANDIDATE_DIGEST"; fi ;;
    *'{{.Image}}')
        if [ "$MOCK_STAGING" = wrong_id ]; then echo 'sha256:other-image-id'; else echo 'sha256:local-image-id'; fi ;;
    'exec container-a php bin/update.php reconcile')
        if [ "$MOCK_STAGING" = reconciliation_fail ]; then exit 1; fi
        printf '{"status":"ready"}\n' ;;
    *) exit 98 ;;
esac
