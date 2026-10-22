#!/bin/sh
# Sourced by disposable internal-network HTTP runners after their setup.
# Never read hs.local/staging. Each capture is synthetic and private.
gateway_capture_begin() {
    capture_directory="$root/dist/financial/$name"
    mkdir -p "$capture_directory"
    chmod 700 "$capture_directory"
    docker image inspect "$1" --format '{{.Id}}' > "$capture_directory/image-id.txt"
    printf '%s\n' '{"version":1,"origin":"captured_synthetic_http","live_provider_capture":false,"network":"disposable_internal","domain":"fixture.invalid","credentials":"synthetic_only","raw_wire":"browser API requests; body digest checked against server callback audit","assertions":"runner must pass browser and financial SQL verification before saving a phase"}' > "$capture_directory/provenance.json"
    gateway_capture_phase baseline ''
}
gateway_capture_phase() {
    capture_phase=$1 capture_browser=$2
    docker exec "$application" php tests/fixtures/gateway_http_projection.php "$capture_phase"
    docker cp "$application:/tmp/gateway-http-projection-$capture_phase.json" "$capture_directory/$capture_phase-projection.json"
    if [ -n "$capture_browser" ] && docker cp "$capture_browser:/tmp/gateway-http-captured-wire.jsonl" "$capture_directory/$capture_phase-wire.jsonl" 2>/dev/null; then
        docker cp "$capture_directory/$capture_phase-wire.jsonl" "$application:/tmp/gateway-http-captured-wire.jsonl"
        docker exec "$application" php tests/fixtures/gateway_http_capture_verify.php "$capture_phase"
        docker cp "$application:/tmp/gateway-http-captured-verified.jsonl" "$capture_directory/$capture_phase-wire.jsonl"
    fi
    for capture_provider in paykassa volet ccapi; do
        if docker exec "$application" test -f "/tmp/$capture_provider-http-wire.jsonl"; then
            docker cp "$application:/tmp/$capture_provider-http-wire.jsonl" "$capture_directory/$capture_phase-$capture_provider-provider-double.jsonl"
        fi
    done
    chmod 600 "$capture_directory/"*
}
