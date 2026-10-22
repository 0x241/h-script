#!/bin/sh
# Pinned to the same Composer/Node and security tools as the existing build.
SC_COMPOSER='composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee'
SC_NODE='node:24.22.1-alpine3.24@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53'
SC_TRIVY='aquasec/trivy:0.73.0@sha256:7cced7cae583819fc7806d4cbc0dbbc7cad18b99f7d3e235192e6da8c091045c'
SC_COSIGN='ghcr.io/sigstore/cosign/cosign:v3.0.6@sha256:de9c65609e6bde17e6b48de485ee788407c9502fa08b8f4459f595b21f56cd00'
sc_init() {
    set +x
    umask 077
    SC_ROOT=$(pwd)
    SC_WORK=$(mktemp -d)
    SC_REPORT_SUBDIR=${SC_REPORT_SUBDIR:-evidence}
    case "$SC_REPORT_SUBDIR" in evidence|publication-checks) ;; *) exit 1;; esac
    mkdir -p "dist/$SC_REPORT_SUBDIR" "$SC_WORK/cache"
    trap 'rm -rf -- "$SC_WORK"' EXIT HUP INT TERM
}
sc_php() {
    docker run --rm --network none --user "$(id -u):$(id -g)" --entrypoint php \
        -v "$SC_ROOT:/src:ro" -v "$SC_ROOT/dist:/reports" -v "$SC_WORK:/work" \
        "$SC_COMPOSER" "$@"
}
sc_trivy() {
    docker run --rm --user "$(id -u):$(id -g)" \
        --env DOCKER_CONFIG=/credentials --env TRIVY_DB_REPOSITORY \
        -v "${DOCKER_CONFIG:-$SC_WORK/cache}:/credentials:ro" \
        -v "$SC_WORK:/work" -v "$SC_ROOT/dist:/reports" \
        "$SC_TRIVY" "$@" --cache-dir /work/cache --quiet --ignorefile /dev/null
}
