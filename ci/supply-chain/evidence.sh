#!/bin/sh
set -eu
. ci/supply-chain/common.sh
sc_init
: "${CI_COMMIT_TAG:?}" "${CI_COMMIT_SHA:?}" "${CI_PROJECT_URL:?}" "${CI_SERVER_URL:?}"
if [ -z "${CANDIDATE_DIGEST:-}" ]; then
    CANDIDATE_DIGEST=$(sc_php -r '$v=json_decode(file_get_contents("/reports/evidence/manifest.json"),true,32,JSON_THROW_ON_ERROR); $d=$v["image_digest"]??""; if(!preg_match("/^sha256:[a-f0-9]{64}$/D",$d)) exit(1); echo $d;')
fi
cosign_evidence() {
    docker run --rm --user "$(id -u):$(id -g)" --env HOME=/tmp --env SIGSTORE_ID_TOKEN \
        -v "$SC_ROOT/dist:/artifacts" "$SC_COSIGN" "$@"
}
if [ "${1:-}" = build ]; then
    build_sha=$(sc_php /src/ci/supply-chain/evidence.php build-sha /reports/evidence)
    git fetch --no-tags origin "$build_sha" --depth=1
    tree_sha=$(git rev-parse "${CI_COMMIT_SHA}^{tree}")
    test "$(git rev-parse "${build_sha}^{tree}")" = "$tree_sha"
    cp ci/supply-chain/policy.json dist/evidence/policy.json
    cp ci/supply-chain/common.sh dist/evidence/tools.sh
    archive="h-script-${CI_COMMIT_TAG#v}-shared-hosting.tar.gz"
    (cd dist && sha256sum "$archive" "$archive.sigstore.json" SHA256SUMS) > dist/evidence/archive-checksums.txt
    sc_php /src/ci/supply-chain/evidence.php build /reports/evidence "$CI_COMMIT_SHA" "$tree_sha" "$CI_COMMIT_TAG" "$CANDIDATE_DIGEST"
    cosign_evidence sign-blob --yes --bundle /artifacts/evidence/manifest.sigstore.json /artifacts/evidence/manifest.json
elif [ "${1:-}" != verify ] && [ "${1:-}" != publication ]; then exit 1; fi
cosign_evidence verify-blob --bundle /artifacts/evidence/manifest.sigstore.json \
    --certificate-identity "${CI_PROJECT_URL}//.gitlab-ci.yml@refs/tags/${CI_COMMIT_TAG}" \
    --certificate-oidc-issuer "$CI_SERVER_URL" /artifacts/evidence/manifest.json
verification_mode=fresh
if [ "$1" = publication ]; then verification_mode=retained; fi
sc_php /src/ci/supply-chain/verify-evidence.php /reports/evidence "$CI_COMMIT_TAG" "$CI_COMMIT_SHA" "$CANDIDATE_DIGEST" "$verification_mode"
(cd dist && sha256sum -c evidence/archive-checksums.txt)
(cd dist && sha256sum -c SHA256SUMS)
if [ "$1" = publication ]; then
    if ! sc_php /src/ci/supply-chain/verify-evidence.php /reports/evidence "$CI_COMMIT_TAG" "$CI_COMMIT_SHA" "$CANDIDATE_DIGEST" >/dev/null 2>&1; then
        export CANDIDATE_DIGEST
        sh ci/supply-chain/refresh-publication.sh
    fi
fi
if [ "$1" = build ]; then
    tar -czf dist/release-evidence.tar.gz -C dist evidence
fi
