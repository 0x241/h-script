#!/bin/sh
set -eu
base_image=${1:-hscript-pcre2-hotfix-test:latest}
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
platform=$(docker image inspect --format "{{.Os}}/{{.Architecture}}" "$base_image")
project="hs-retention-$(date +%s)-$$"
work=$(mktemp -d)
old_image="$project:before"
new_image="$project:after"
cleanup() {
  docker compose -p "$project" -f "$work/compose.yml" down --volumes >/dev/null 2>&1 || true
  docker image rm "$old_image" "$new_image" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT INT TERM
cat > "$work/Dockerfile" <<EOF
FROM $base_image
ARG PROBE_RELEASE
LABEL hscript.retention-test-release=\$PROBE_RELEASE
EOF
docker build --platform "$platform" --network none --build-arg PROBE_RELEASE=1.0.7 -t "$old_image" "$work" >/dev/null
docker build --platform "$platform" --network none --build-arg PROBE_RELEASE=1.0.8 -t "$new_image" "$work" >/dev/null
# Keep installed code and dependencies from the image; mount only the probes.
cat > "$work/compose.yml" <<EOF
services:
  writer:
    image: $old_image
    platform: $platform
    entrypoint: ["php"]
    command: ["-r", "sleep(3600);"]
    network_mode: none
    environment:
      H_SCRIPT_NDJSON_UPGRADE_TEST: "1"
    volumes:
      - "$root/tests:/var/www/html/tests:ro"
      - evidence:/evidence
volumes:
  evidence:
EOF
docker compose -p "$project" -f "$work/compose.yml" up -d --no-build >/dev/null
docker compose -p "$project" -f "$work/compose.yml" exec -T writer php tests/ndjson_upgrade_probe.php before
python3 - "$work/compose.yml" "$old_image" "$new_image" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]);p.write_text(p.read_text().replace('image: '+sys.argv[2], 'image: '+sys.argv[3]))
PY
docker compose -p "$project" -f "$work/compose.yml" up -d --no-build --force-recreate >/dev/null
docker compose -p "$project" -f "$work/compose.yml" exec -T writer php tests/ndjson_upgrade_probe.php after
