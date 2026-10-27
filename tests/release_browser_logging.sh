#!/bin/sh
# Check failure visibility/exit status without Docker, network or a database.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT HUP INT TERM
mkdir -p "$temporary/bin" "$temporary/ci" "$temporary/tests/browser"
cp "$root/ci/release-browser.sh" "$temporary/ci/release-browser.sh"
cp "$root/ci/docker-credential-hscript-ci" "$root/ci/run-test-suites.py" "$root/ci/release-browser-suite.sh" "$temporary/ci/"
cat > "$temporary/bin/docker" <<'EOF'
#!/bin/sh
case "$1" in
    login) exit 97 ;; # Credentials must not be persisted through docker login.
    buildx) printf '%s' "$DOCKER_CONFIG" > "$BROWSER_LOG_TEST_CREDENTIALS"; printf 'sha256:%064d\n' 0 ;;
    image) printf 'image cleanup\n' >> "$BROWSER_LOG_TEST_CALLS" ;;
esac
EOF
chmod 0700 "$temporary/bin/docker"
cat > "$temporary/tests/browser_fixture_permissions.sh" <<'EOF'
#!/bin/sh
echo 'Synthetic browser assertion detail'
exit "$BROWSER_LOG_TEST_FAILURE"
EOF
for suite in update_isolated failed_login_isolated financial_http_isolated gateway_policy_http_isolated inbound_http_isolated ccapi_http_isolated ccapi_payout_http_isolated paykassa_http_isolated paw_http_isolated volet_http_isolated; do
    printf 'echo later-suite >> "$BROWSER_LOG_TEST_CALLS"\n' > "$temporary/tests/$suite.sh"
done
export PATH="$temporary/bin:$PATH"
export BROWSER_LOG_TEST_CALLS="$temporary/calls" BROWSER_LOG_TEST_CREDENTIALS="$temporary/credentials"
export CI_REGISTRY=synthetic.invalid CI_REGISTRY_USER=synthetic CI_REGISTRY_PASSWORD=synthetic
export CANDIDATE_REF=synthetic.invalid/image:fixture CANDIDATE_DIGEST="sha256:$(printf '%064d' 0)"
export CI_COMMIT_SHA=synthetic CI_JOB_ID=123
cd "$temporary"
for failure in 23 0; do
    : > "$BROWSER_LOG_TEST_CALLS"
    export BROWSER_LOG_TEST_FAILURE="$failure"
    browser_log_result=0
    sh ci/release-browser.sh > output 2>&1 || browser_log_result=$?
    test "$browser_log_result" -eq "$failure"
    grep -q 'Synthetic browser assertion detail' output
    grep -q 'image cleanup' "$BROWSER_LOG_TEST_CALLS"
    test ! -d "$(cat "$BROWSER_LOG_TEST_CREDENTIALS")"
    if [ "$failure" -ne 0 ]; then
        if grep -q 'later-suite\|result=passed' "$BROWSER_LOG_TEST_CALLS" output; then exit 1; fi
    else
        grep -q 'result=passed' output
        test "$(grep -c later-suite "$BROWSER_LOG_TEST_CALLS")" -eq 10
    fi
done
echo 'Browser job failure details, exit status, early stop and cleanup verified.'
