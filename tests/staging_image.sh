#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$(awk '/^test:staging-reconciliation:/ {capture=1; next} capture && /^[^[:space:]]/ {exit} capture {print}' "$root/.gitlab-ci.yml")
printf '%s\n' "$gate" | grep -q '^  stage: verify-staging$'
printf '%s\n' "$gate" | grep -q '^  when: manual$'
printf '%s\n' "$gate" | grep -q '^  allow_failure: false$'
printf '%s\n' "$gate" | grep -q '^  manual_confirmation: '
printf '%s\n' "$gate" | grep -q 'job: deploy:staging'
printf '%s\n' "$gate" | grep -q 'sh ci/reconcile-staging.sh'
promotion=$(awk '/^release:promote-public:/ {capture=1; next} capture && /^[^[:space:]]/ {exit} capture {print}' "$root/.gitlab-ci.yml")
printf '%s\n' "$promotion" | grep -q 'job: test:staging-reconciliation'
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT HUP INT TERM
ln -s "$root/tests/fixtures/mock-staging-docker.sh" "$temporary/docker"
export PATH="$temporary:$PATH"
export COMPOSE_PROJECT_NAME=fixture CANDIDATE_REF=registry.invalid/app:candidate
export CANDIDATE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
MOCK_STAGING=ready sh "$root/ci/verify-staging-image.sh"
for state in missing duplicate stopped moved wrong_id; do
    if MOCK_STAGING="$state" sh "$root/ci/verify-staging-image.sh"; then
        echo "Unsafe staging state accepted: $state" >&2
        exit 1
    fi
done
if MOCK_STAGING=moved sh "$root/ci/verify-staging-image.sh" 2>"$temporary/mismatch.log"; then exit 1; fi
grep -q 'do not retry an older pipeline' "$temporary/mismatch.log"
ln -s "$root/ci" "$temporary/ci"
(
    cd "$temporary"
    MOCK_STAGING=ready sh ci/reconcile-staging.sh
    if MOCK_STAGING=reconciliation_fail sh ci/reconcile-staging.sh; then
        echo 'Failed reconciliation accepted' >&2
        exit 1
    fi
)
echo 'Staging digest guard tests passed.'
