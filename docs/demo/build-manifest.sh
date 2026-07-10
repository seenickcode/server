#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nick Manning
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Emit a per-user JSONL manifest from the seeded dataset. This plays the role
# of the export-time manifest builder (LDS sidecars / Keepit metadata): it
# captures path, size, ORIGINAL mtime and mimetype while they still exist.
#
# Usage: build-manifest.sh <user> [data-dir] > manifests/<user>.jsonl
#
# SOURCE_ROOT: prefix to substitute for data-dir in the emitted "source"
# paths. Needed when the manifest is built on the host but consumed by the
# spike inside the app container, where the repo is mounted at /var/www/html:
#   SOURCE_ROOT=/var/www/html/docs/demo/data ./build-manifest.sh demo02
#
# NOTE: assumes shell/JSON-safe filenames (guaranteed by seed-legacy.sh).
# A real manifest builder must JSON-encode properly.
set -euo pipefail

USER_ID=${1:?usage: build-manifest.sh <user> [data-dir]}
DATA_DIR=${2:-"$(cd "$(dirname "$0")" && pwd)/data"}
SOURCE_ROOT=${SOURCE_ROOT:-"$DATA_DIR"}
ROOT="$DATA_DIR/$USER_ID"
[ -d "$ROOT" ] || { echo "no seeded data at $ROOT (run seed-legacy.sh)" >&2; exit 1; }

find "$ROOT" -type f | LC_ALL=C sort | while IFS= read -r f; do
  rel="${f#"$ROOT"/}"
  if stat -f '%z %m' "$f" >/dev/null 2>&1; then
    read -r size mtime <<< "$(stat -f '%z %m' "$f")"   # BSD/macOS
  else
    read -r size mtime <<< "$(stat -c '%s %Y' "$f")"   # GNU
  fi
  case "$rel" in
    *.txt)        mime="text/plain" ;;
    *.pdf)        mime="application/pdf" ;;
    *.jpg|*.jpeg) mime="image/jpeg" ;;
    *.png)        mime="image/png" ;;
    *)            mime="application/octet-stream" ;;
  esac
  printf '{"path":"%s","size":%s,"mtime":%s,"mimetype":"%s","source":"%s"}\n' \
    "$rel" "$size" "$mtime" "$mime" "$SOURCE_ROOT/$USER_ID/$rel"
done
