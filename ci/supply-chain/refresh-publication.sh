#!/bin/sh
# Called only after the retained manifest signature, identity and bytes verify.
# Rescan the ORIGINAL source, archive and digest; never build or sign a release.
set -eu
. ci/supply-chain/common.sh
sc_init
: "${CI_COMMIT_TAG:?}" "${CI_COMMIT_SHA:?}" "${CANDIDATE_DIGEST:?}" "${CI_REGISTRY_IMAGE:?}"
export SC_REPORT_SUBDIR=publication-checks
export CANDIDATE_REF="$CI_REGISTRY_IMAGE@$CANDIDATE_DIGEST"
export DOCKER_CONFIG="$SC_WORK/credentials"
mkdir "$DOCKER_CONFIG"
printf '%s' "$CI_REGISTRY_PASSWORD" | docker login "$CI_REGISTRY" --username "$CI_REGISTRY_USER" --password-stdin
sh ci/supply-chain/audit.sh composer "$CI_COMMIT_SHA"
sh ci/supply-chain/audit.sh npm "$CI_COMMIT_SHA"
sh ci/supply-chain/scan-files.sh source
sh ci/supply-chain/scan-files.sh shared "dist/h-script-${CI_COMMIT_TAG#v}-shared-hosting.tar.gz"
sh ci/supply-chain/scan-image.sh
sc_php /src/ci/supply-chain/gate.php recheck \
    /reports/publication-checks/composer.report.json \
    /reports/publication-checks/npm-production.report.json \
    /reports/publication-checks/npm-toolchain.report.json \
    /reports/publication-checks/source.report.json \
    /reports/publication-checks/shared.report.json \
    /reports/publication-checks/image-amd64.report.json \
    /reports/publication-checks/image-arm64.report.json
# Retain the relationship to the immutable signed release without replacing it.
sha256sum dist/evidence/manifest.json > dist/publication-checks/retained-manifest.sha256
cp ci/supply-chain/policy.json dist/publication-checks/policy.json
cp ci/supply-chain/common.sh dist/publication-checks/tools.sh
