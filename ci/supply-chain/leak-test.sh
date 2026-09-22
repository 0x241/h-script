#!/bin/sh
set -eu
. ci/supply-chain/common.sh
sc_init
set --
if [ -n "${CI_JOB_ID:-}" ]; then
    # Reuse the release builder: the classic Docker image store cannot export OCI.
    export BUILDX_CONFIG="${H_SCRIPT_BUILDX_CONFIG:-/tmp/h-script-buildx}"
    mkdir -p "$BUILDX_CONFIG"
    chmod 0700 "$BUILDX_CONFIG"
    if ! docker buildx inspect hscript-release >/dev/null 2>&1; then
        docker buildx create --driver docker-container --name hscript-release >/dev/null
    fi
    set -- --builder hscript-release
fi
build_error() {
    sc_php -r '$marker=trim(file_get_contents("/work/canary")); foreach (file($argv[1]) as $line) if (str_contains($line,"ERROR") || str_contains($line,"error:")) echo str_replace($marker,"[CANARY]",$line);' "$1" >&2
    exit 1
}
mkdir "$SC_WORK/context" "$SC_WORK/oci" "$SC_WORK/release"
if [ -n "${CI_COMMIT_SHA:-}" ]; then
    git archive "$CI_COMMIT_SHA" | tar -x -C "$SC_WORK/context"
else
    # Include local, not-yet-committed task files, but never ignored runtime data.
    git ls-files --cached --others --exclude-standard -z > "$SC_WORK/source-files"
    COPYFILE_DISABLE=1 tar --null -T "$SC_WORK/source-files" -cf "$SC_WORK/source.tar"
    tar -xf "$SC_WORK/source.tar" -C "$SC_WORK/context"
fi
sc_php -r 'file_put_contents("/work/canary", "hs-test-".bin2hex(random_bytes(32)));'
mkdir -p "$SC_WORK/context/tests/fixtures" "$SC_WORK/context/ci"
for path in .env _config.local.php module/_config/pass tests/fixtures/canary.txt ci/canary.txt; do
    cp "$SC_WORK/canary" "$SC_WORK/context/$path"
done
# No secret is supplied as a build argument. The test asserts that the explicit
# build context exclusions also protect max provenance and intermediate layers.
docker buildx build "$@" --target app --sbom=true --provenance=mode=max \
    --metadata-file "$SC_WORK/build-metadata.json" \
    --output "type=oci,dest=$SC_WORK/image.tar,compression=gzip,force-compression=true" \
    "$SC_WORK/context" > "$SC_WORK/image-build.log" 2>&1 || build_error /work/image-build.log
tar -xf "$SC_WORK/image.tar" -C "$SC_WORK/oci"
docker buildx build "$@" --target shared-release --output "type=local,dest=$SC_WORK/release" \
    "$SC_WORK/context" > "$SC_WORK/archive-build.log" 2>&1 || build_error /work/archive-build.log
sc_php /src/ci/supply-chain/check-canary.php /work/canary /work/oci /work/release \
    /work/build-metadata.json /work/image-build.log /work/archive-build.log > dist/evidence/secret-boundary.txt
cat dist/evidence/secret-boundary.txt
