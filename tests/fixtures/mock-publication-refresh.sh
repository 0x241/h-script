#!/bin/sh
# Only the refresh boundary is mocked; retained identity/hash/policy checks run.
set -eu
test "$CI_COMMIT_TAG" = v1.2.3
printf '%s\n' "$CI_COMMIT_SHA@$CANDIDATE_DIGEST" >> "$MOCK_PUBLICATION_ROOT/refresh-calls"
exit "${MOCK_REFRESH_FAILURE:-0}"
