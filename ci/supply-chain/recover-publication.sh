#!/bin/sh
# Recovery reuses the original verified bytes; never rebuild/re-sign an old SemVer.
set -eu
. ci/supply-chain/common.sh
sc_init
: "${GITHUB_RELEASE_TOKEN:?}" "${RELEASE_RECOVERY_TAG:?}" "${RELEASE_EVIDENCE_JOB_ID:?}"
printf '%s\n' "$RELEASE_RECOVERY_TAG" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'
printf '%s\n' "$RELEASE_EVIDENCE_JOB_ID" | grep -Eq '^[0-9]+$'
tag_sha=$(sh ci/supply-chain/check-release-tag.sh "$RELEASE_RECOVERY_TAG")
artifact_api="$CI_API_V4_URL/projects/$CI_PROJECT_ID/jobs/$RELEASE_EVIDENCE_JOB_ID"
archive="h-script-${RELEASE_RECOVERY_TAG#v}-shared-hosting.tar.gz"
for file in "$archive" "$archive.sigstore.json" SHA256SUMS release-evidence.tar.gz; do
    curl --fail --silent --show-error --location --header "JOB-TOKEN: $CI_JOB_TOKEN" "$artifact_api/artifacts/dist/$file" -o "dist/$file"
done
cp dist/release-evidence.tar.gz "$SC_WORK/evidence.tar.gz"
sc_php /src/ci/supply-chain/check-files.php evidence /work/evidence.tar.gz
tar -xzf "$SC_WORK/evidence.tar.gz" -C "$SC_WORK"
# Copy only checked flat filenames; verification fails on missing or altered bytes.
cp "$SC_WORK/evidence/"* dist/evidence/
CANDIDATE_DIGEST=$(sc_php -r '$v=json_decode(file_get_contents("/reports/evidence/manifest.json"),true,32,JSON_THROW_ON_ERROR); echo $v["image_digest"];')
export CANDIDATE_DIGEST
CI_COMMIT_TAG="$RELEASE_RECOVERY_TAG" CI_COMMIT_SHA="$tag_sha" sh ci/supply-chain/evidence.sh publication

github_url='https://github.com/0x241/h-script.git'
github_auth=$(printf 'x-access-token:%s' "$GITHUB_RELEASE_TOKEN" | base64 | tr -d '\n')
github_tag=$(git -c "http.https://github.com/.extraheader=AUTHORIZATION: basic $github_auth" ls-remote --refs "$github_url" "refs/tags/$RELEASE_RECOVERY_TAG" | awk '{print $1}')
if [ -z "$github_tag" ]; then
    git -c "http.https://github.com/.extraheader=AUTHORIZATION: basic $github_auth" push "$github_url" "$tag_sha:refs/tags/$RELEASE_RECOVERY_TAG"
else test "$github_tag" = "$tag_sha"; fi
export DOCKER_CONFIG="$SC_WORK/credentials"
mkdir "$DOCKER_CONFIG"
printf '%s' "$CI_REGISTRY_PASSWORD" | docker login "$CI_REGISTRY" --username "$CI_REGISTRY_USER" --password-stdin
export GITHUB_TOKEN="$GITHUB_RELEASE_TOKEN"
docker run --rm --entrypoint /bin/sh --env GITHUB_TOKEN --env GITHUB_REPOSITORY=0x241/h-script \
    --env "RELEASE_TAG=$RELEASE_RECOVERY_TAG" --env "EXPECTED_SHA=$tag_sha" --env "ARCHIVE=$archive" \
    -v "$SC_ROOT/dist:/artifacts:ro" -v "$PUBLISH_GITHUB_RELEASE_SCRIPT:/usr/local/share/hscript/publish-github-release.sh:ro" \
    "$CI_REGISTRY_IMAGE@$CANDIDATE_DIGEST" /usr/local/share/hscript/publish-github-release.sh
