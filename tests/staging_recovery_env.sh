#!/bin/sh
# Exercise the same dotenv output that deploy:staging persists, with no secrets.
set -eu
set +x
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT HUP INT TERM
cp "$root/docker/env.example" "$temporary/env"
env -i PATH="$PATH" RECOVERY_ENABLED=1 RECOVERY_BACKUP_INTERVAL_SECONDS=1800 \
    RECOVERY_SECRETS_PATH=/srv/synthetic-recovery \
    RECOVERY_DRILL_DB_ADMIN_USER=synthetic_drill_operator \
    RECOVERY_DRILL_DB_ADMIN_PASSWORD_FILE=/run/secrets/hscript-recovery/drill-db-password \
    RECOVERY_CMS_RPO_SECONDS=1234 RECOVERY_DRILL_MAX_AGE_SECONDS=4567 \
    sh "$root/ci/staging-recovery-env.sh" >> "$temporary/env"
# env -i reproduces a later Compose invocation after GitLab's shell is gone.
env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
    docker compose --project-directory "$root" --env-file "$temporary/env" \
    -f "$root/docker-compose.yml" config --format json > "$temporary/compose.json"
docker run --rm -i --network none --entrypoint php \
    -v "$root/tests/recovery_compose.php:/test.php:ro" \
    composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee \
    /test.php --persisted-staging < "$temporary/compose.json"
for invalid in "one'two" "one$(printf '\r')two" 'one
RECOVERY_ENABLED=0'; do
    if env -i PATH="$PATH" RECOVERY_SECRETS_PATH="$invalid" \
        sh "$root/ci/staging-recovery-env.sh" > "$temporary/output" 2> "$temporary/error"; then
        echo 'Unsafe dotenv value accepted' >&2; exit 1
    fi
done
echo 'Staging recovery configuration persists without CI shell variables; web/drill secrets remain isolated.'
