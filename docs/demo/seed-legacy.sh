#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nick Manning
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Seed a synthetic "legacy" dataset: USERS users x FILES files each, in a
# 3-level directory tree, random sizes 1KB-512KB, mtimes backdated up to
# ~3 years (so the metadata-preservation demo is visible).
#
# Usage:
#   USERS=20 FILES=500 ./seed-legacy.sh          # writes to docs/demo/data/
#   MIRROR=1 ./seed-legacy.sh                    # also mirror into MinIO
#                                                # bucket legacy-data (Demo A)
#
# File names are deliberately shell/JSON-safe; build-manifest.sh relies on it.
set -euo pipefail

USERS=${USERS:-5}
FILES=${FILES:-200}
DATA_DIR=${DATA_DIR:-"$(cd "$(dirname "$0")" && pwd)/data"}
MIRROR=${MIRROR:-0}

fmt_ts() { # epoch -> touch -t format, BSD (macOS) first, GNU fallback
  date -r "$1" +%Y%m%d%H%M.%S 2>/dev/null || date -d "@$1" +%Y%m%d%H%M.%S
}

now=$(date +%s)
for n in $(seq 1 "$USERS"); do
  u=$(printf 'demo%02d' "$n")
  for i in $(seq 1 "$FILES"); do
    d1=$(( (i % 8) + 1 ))
    d2=$(( (i % 3) + 1 ))
    dir="$DATA_DIR/$u/Imported/dir-0$d1/sub-$d2"
    mkdir -p "$dir"
    f="$dir/file-$(printf '%05d' "$i").bin"
    size=$(( (RANDOM % 512 + 1) * 1024 ))
    head -c "$size" /dev/urandom > "$f"
    past=$(( now - (RANDOM * RANDOM % 94608000) )) # up to ~3 years ago
    touch -t "$(fmt_ts "$past")" "$f"
  done
  echo "seeded $u ($FILES files)"
done

if [ "$MIRROR" = "1" ]; then
  echo "mirroring $DATA_DIR -> minio://legacy-data ..."
  docker run --rm --network host -v "$DATA_DIR:/data:ro" minio/mc sh -c '
    mc alias set local http://localhost:9000 nextcloud nextcloud12345 &&
    mc mirror --overwrite /data local/legacy-data'
fi
echo "done. data root: $DATA_DIR"
