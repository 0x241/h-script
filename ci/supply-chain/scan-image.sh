#!/bin/sh
set -eu
. ci/supply-chain/common.sh
sc_init
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_PROJECT_URL:?}" "${CI_SERVER_URL:?}"
exact="${CANDIDATE_REF%@*}@${CANDIDATE_DIGEST}"
test "$(docker buildx imagetools inspect "$exact" --format '{{.Manifest.Digest}}')" = "$CANDIDATE_DIGEST"
for arch in amd64 arm64; do
    sc_trivy image --image-src remote --platform "linux/$arch" --scanners vuln,secret --image-config-scanners secret \
        --format json --output /work/raw.json "$exact" 2> "$SC_WORK/error"
    sc_php /src/ci/supply-chain/gate.php trivy "image-$arch" /work/raw.json "/reports/$SC_REPORT_SUBDIR/image-$arch.report.json" 0
    sc_trivy image --image-src remote --platform "linux/$arch" --scanners vuln --format cyclonedx \
        --output "/reports/$SC_REPORT_SUBDIR/image-$arch.cdx.json" "$exact" 2> "$SC_WORK/error"
done
if [ "$SC_REPORT_SUBDIR" = publication-checks ]; then exit 0; fi
docker buildx imagetools inspect "$exact" --raw > dist/evidence/image-index.json
docker buildx imagetools inspect "$exact" --format '{{json .Provenance}}' > dist/evidence/provenance.json
docker buildx imagetools inspect "$exact" --format '{{json .SBOM}}' > dist/evidence/buildkit-sbom.json
docker run --rm --user "$(id -u):$(id -g)" --env DOCKER_CONFIG=/credentials --env HOME=/tmp \
    -v "$DOCKER_CONFIG:/credentials:ro" "$SC_COSIGN" verify \
    --certificate-identity "${CI_PROJECT_URL}//.gitlab-ci.yml@refs/heads/stage/docker-release" \
    --certificate-oidc-issuer "$CI_SERVER_URL" "$exact" > dist/evidence/image-verification.json
docker run --rm --user "$(id -u):$(id -g)" --env DOCKER_CONFIG=/credentials --env HOME=/tmp \
    -v "$DOCKER_CONFIG:/credentials:ro" "$SC_COSIGN" download signature "$exact" > dist/evidence/image-signatures.json
docker run --rm --network none --entrypoint php "$exact" -r 'echo PHP_VERSION, PHP_EOL;' > dist/evidence/php-version.txt
docker run --rm "$SC_TRIVY" --version > dist/evidence/trivy-version.txt
printf '%s\n' "$exact" > dist/evidence/candidate-image.txt
