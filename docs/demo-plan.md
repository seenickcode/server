# Bulk Import — Demo Runbook & Load-Test Plan

Companion to `docs/code-review.md` (the technical appendix). This doc is the
*show-it* side: reproducible before/after demo steps and the load-test plan
whose results feed the team report.

Decision context: all-PHP implementation in this server clone (appendix §8).

---

## 1. Demo environment (one-time setup, ~15 min)

Everything runs in Docker — **including PHP**, so nothing is needed on the
host. `docs/demo/docker-compose.yml` runs three services: `app` (PHP 8.3
serving *this checkout*, mounted at `/var/www/html` — see
`docs/demo/Dockerfile`), `db` (MariaDB — SQLite hides all the locking
behavior we care about), and `minio` (standing in for STACKIT S3).

```bash
cd docs/demo
docker compose build && docker compose up -d

# convenience aliases used throughout this runbook
alias occ='docker compose exec -T app php occ'
alias spike='docker compose exec -T app php docs/demo/import-spike.php'

# two buckets: one as NC primary storage, one playing "external S3 to import"
# (the mc image's entrypoint is `mc` itself — the override is required)
docker run --rm --network demo_default --entrypoint /bin/sh minio/mc -c '
  mc alias set local http://minio:9000 nextcloud nextcloud12345 &&
  mc mb --ignore-existing local/nc-primary local/legacy-data'

# syntax-check the spike now that a PHP runtime exists
docker compose exec -T app php -l docs/demo/import-spike.php
```

**Pre-seed the primary object storage config BEFORE install** (the
production topology — this matters, see Act 3; pre-seeding also puts the
admin home on object storage). Write `config/config.php` with only the
block below — the installer merges its own settings around it:

```bash
occ maintenance:install \
  --database mysql --database-host db \
  --database-name nextcloud --database-user nextcloud --database-pass nextcloud \
  --admin-user admin --admin-pass admin
occ config:system:set trusted_domains 1 --value=localhost:8080
# demo users (repeat for demo01..demoNN)
docker compose exec -T -e OC_PASS=demo1234 app php occ user:add --password-from-env demo01
```

`config/config.php` pre-seed (hostnames are the compose service names):

```php
'objectstore' => [
  'class' => \OC\Files\ObjectStore\S3::class,
  'arguments' => [
    'bucket' => 'nc-primary',
    'hostname' => 'minio', 'port' => 9000, 'use_ssl' => false,
    'use_path_style' => true,
    'key' => 'nextcloud', 'secret' => 'nextcloud12345',
    'autocreate' => true,
  ],
],
```

Web UI: `http://localhost:8080` (admin/admin). MinIO console:
`http://localhost:9001`. DB shell:
`docker compose exec db mariadb -unextcloud -pnextcloud nextcloud`
(the `mariadb:11` image ships `mariadb`, not `mysql`).

> Note: the objectstore block must exist **before creating any users**, or
> their homes land on local storage and Act 3 loses its punchline.

Seed the "legacy" bucket with a synthetic dataset (adjust `FILES`/`USERS`;
10k files across 20 users is enough for a live demo, deeper trees make the
propagation story more visible):

```bash
# docs/demo/seed-legacy.sh — synthetic tree: USERS users × FILES files,
# 3-level dirs, backdated mtimes. Writes to docs/demo/data/ (the spike's
# object source); MIRROR=1 also pushes to the legacy-data bucket for Act 1.
USERS=20 FILES=500 MIRROR=1 ./docs/demo/seed-legacy.sh
```

`occ user:add` a handful of demo users (`demo01…demo20`), or loop it.

---

## 2. Demo A — the "before" (runnable today, no new code)

### Act 1: scan-based import is slow and serial

Mount the legacy bucket for one user via files_external (S3, key prefix
`demo01/`), then:

```bash
time occ files:scan demo01 -v
```

**What to show:** the summary table `files:scan` prints (folders, files,
elapsed) → compute files/sec live in front of the team. The `-v` stream of
one-line-per-file *is* the architecture: one lock, one S3 HEAD, ~4 queries,
one insert per line, one PHP process. Point at the matching hot loop in
`lib/private/Files/Cache/Scanner.php:104` if the audience is technical.

### Act 2: write amplification, measured

```bash
dbroot() { docker compose exec -T db mariadb -uroot -pnextcloud "$@"; }
dbroot -e "SET GLOBAL general_log='ON'; SET GLOBAL log_output='TABLE';"
occ files:scan demo01 --path=/demo01/files/dir-01   # ~100 files
dbroot -e "SELECT count(*) FROM mysql.general_log WHERE argument LIKE '%filecache%';
           SET GLOBAL general_log='OFF';"
```

**What to show:** queries ÷ files ≈ 5–8 per file. Multiply by 30M on the
slide.

### Act 3: the primary-storage dead end (the killer visual)

Upload files **directly** into the primary bucket, the way "just copy the
bytes to S3" naively suggests, then scan:

```bash
docker run --rm --network host minio/mc \
  cp /etc/hosts local/nc-primary/demo-direct-upload.txt
occ files:scan --all
```

**What to show:** the scan reports *zero* new files, and the file never
appears in the web UI. This is not a bug — `ObjectStoreScanner::scan()` is
an intentional no-op (`lib/private/Files/ObjectStore/ObjectStoreScanner.php:17`);
on primary object storage **the database is the filesystem**. There is no
"rescan it later" escape hatch. Registration must be explicit → the entire
design rationale in one 30-second demo.

### Act 4: metadata loss + unknowable partial state

1. In the web UI, note the imported files' mtimes = *scan time-ish S3
   mtimes*, owner = the mounting user. Original ownership, timestamps, ACLs:
   gone. (Manifest carries them; scan structurally can't.)
2. Re-run Act 1 and `Ctrl-C` it halfway. Show
   `SELECT count(*) FROM oc_filecache WHERE size = -1;` — the only recovery
   signal is "some folders are incomplete, rescan everything."

---

## 3. Demo B — the "after" (requires the spike)

**Prerequisite — the throwaway benchmark harness at
`docs/demo/import-spike.php`** (clearly labeled not-for-merge; it exists to
validate the §5 mechanics and produce numbers). It bootstraps `lib/base.php`
directly, so it requires **zero changes to tracked server code** — no command
registration, no core diff. Contract:

```
spike --manifest=docs/demo/manifests/demo02.jsonl --user=demo02 \
    [--batch-size=1000] [--dry-run] [--register-only] [--no-verify] \
    [--report=docs/demo/results.csv]
```

(`spike` = the alias from §1; paths are container paths — everything under
the repo, including `docs/demo/`, is visible inside the `app` container at
the same relative location.)

Per the appendix, it must do exactly the batched protocol:

1. Read per-user JSONL manifest (path, size, mtime, checksum, mimetype).
2. Bulk existence pre-check (one `path_hash IN (…)` SELECT per batch) →
   split insert/update sets (sidesteps the conflict-fission trap, §5.4).
3. Insert folders parents-first, then files in transactions of
   `--batch-size` rows; collect fileids.
4. PUT objects from the legacy bucket to `urn:oid:<fileid>` (parallelizable
   later; serial is fine for the demo), verify via `objectExists`.
5. One `Propagator::beginBatch()/commitBatch()` flush + root etag bump per
   run — sync clients discover everything at once.
6. Print: rows/sec, objects/sec, total queries, wall time.
7. `--dry-run` prints the diff (the drift-report seed); rerun after success
   prints "0 to do" (idempotency proof).

### The side-by-side

```bash
# BEFORE (Act 1 numbers, same dataset, user demo01 via scan)
# AFTER — build the manifest on the host, with container source paths:
mkdir -p docs/demo/manifests
SOURCE_ROOT=/var/www/html/docs/demo/data \
  ./docs/demo/build-manifest.sh demo02 > docs/demo/manifests/demo02.jsonl
time spike --manifest=docs/demo/manifests/demo02.jsonl --user=demo02
```

Then, live:

1. **Web UI**: log in as `demo02` — files are first-class in the home
   directory, correct original mtimes (from the manifest), instantly
   visible. Compare against demo01's scan-imported metadata.
2. **Resume**: re-run the same command → completes in seconds, "0 inserted,
   N verified". Kill it mid-run first if you want the full effect: rerun
   heals, no rescan.
3. **Verification**: `--dry-run` after deleting one object from the bucket →
   drift report names exactly the missing file.

### Scorecard slide — measured 2026-07-10, local rig, 100 files + ~34 dirs per user

| | scan-based (today) | batched registration (spike) |
|---|---|---|
| DB queries per entry (cold run) | **6.1** (828 total, 205 filecache writes) | **2.0** (263 total) — and the spike still uses per-row `Cache::insert`; a multi-row upstream primitive drops this further |
| DB registration rate | n/a (fused with discovery) | **11.5–11.8k rows/s** (batch=1000) |
| End-to-end wall (26 MiB) | 0.49s (see caveat) | 0.58s incl. object PUTs at 42–47 MiB/s + full verify |
| Works on primary object storage | **no** — scan is a structural no-op (Act 3) | **yes** — it's the native writeStream protocol, batched |
| Original mtime / ownership | lost | preserved from manifest (verified in UI) |
| Interrupted run | unknown state, full rescan | **rerun = resume: 0.09s no-op reconcile** |
| "Did we get everything?" | no answer | `--dry-run` named the exact deleted object; rerun healed it (verified byte-identical over WebDAV, md5 match) |
| Projected 30M files, 8 workers | _needs S1–S3_ | _needs S1–S3_ (naive extrapolation of the DB leg alone: ~5 min; object leg dominates) |

**Caveat for the deck:** on this rig MinIO answers in ~0ms, so scan
wall-time looks flattering — the 20–50 files/s ceiling of #58549 comes from
real S3 round-trips per file that the local rig doesn't reproduce. The
transferable local numbers are *queries per entry* (6.1 vs 2.0) and the
structural rows (primary-storage no-op, metadata loss, resumability). Real
throughput contrast comes from the load-test plan (S1–S4) against real S3.

Bonus find while measuring: `occ files:scan` miscounts — new files are
reported as "Updated", never "New" (`Utils/Scanner.php` `addToCache`
listener checks `if ($fileId)`, but new inserts carry `-1`, which is truthy
in PHP). Cheap goodwill fix for upstream.

---

## 4. Load-test plan

Purpose: convert the open blockers from the one-pager (sustained DB insert
rate; app-tier throughput; do-no-harm) into measured numbers for the team.

### 4.1 Environment

- Staging box sized like one production app node + a production-class
  MySQL/PostgreSQL (**never SQLite** — the propagator's `FOR UPDATE` path is
  bypassed there, appendix §2B, so SQLite results are fiction).
- MinIO local for mechanics; one confirmation run against real STACKIT S3
  for network truth.
- Dataset generator: synthetic manifests, parameterized
  `users × files/user × depth × size-distribution`. Match the expected real
  distribution (~1.2 MB mean if 35 TB / 30M files, heavy small-file tail —
  ops-bound, not bandwidth-bound).

### 4.2 Scenarios

**S1 — Registration ceiling (DB-bound).** `--register-only` (no object I/O),
1M rows, sweep batch size {100, 500, 1000, 5000} × workers {1, 4, 8, 16},
one user-subtree per worker (the §5.4 disjoint-lock design).
*Report:* rows/sec per cell; the knee in the batch-size curve; projected
wall-time for 30M rows. *Pass:* 30M rows ≪ 24h with headroom.

**S2 — Object throughput (S3-bound).** Bytes leg only: PUT ops/sec and MB/s
vs parallel streams {8, 32, 128}, small-file mix vs large-file mix.
*Report:* whether PHP workers saturate the link (this is the tripwire for
the deferred Go/rclone uploader decision, appendix §8).

**S3 — End-to-end per-user.** Full spike (register + PUT + verify) for a
realistic user (5k files, 6 GB), N workers over 100 users.
*Report:* users/hour → projected 120k-user critical-set wall time.

**S4 — Do-no-harm (the one the team will ask about).** Import at fixed
throttle levels {0/off, 25%, 50%, 100% of S1 ceiling} while synthetic live
load runs: k6 or Locust driving WebDAV PROPFIND + small uploads + file
listings for ~200 virtual users (the failover-onboarding traffic shape).
*Report:* live-traffic p95/p99 latency and error rate vs import rate — one
chart. Find the max import rate where live p95 degrades <20%. That number
*is* the throttle default.
*Watch:* `innodb_row_lock_waits`, deadlock count (`SHOW ENGINE INNODB
STATUS`), propagator retries in nextcloud.log — contention will appear on
ancestor rows first (appendix §2B).

**S5 — Idempotency at scale.** Rerun the reconciler over 1M already-imported
rows. *Report:* no-op reconcile minutes (this is the nightly-refresh cost
and the resume cost — the "resume = rerun" claim, quantified).

**S6 — Phase-1 identities.** `occ user:add` loop vs provisioning-API batch:
users/sec, → does 120k fit inside RTO or does Nubus/UDM own this. (Least
explored blocker; cheap to measure early.)

### 4.3 Metrics & tooling

| Layer | Metric | Tool |
|---|---|---|
| Import | rows/sec, objects/sec, batch latency, retries | spike `--report=csv` |
| DB | inserts/sec, lock waits, deadlocks, replication lag if any | `mysqld_exporter` or `pt-stalk`; `general_log` only for short query-count samples |
| S3 | PUT ops/sec, MB/s, 5xx/throttle responses | MinIO console / client-side CSV |
| Live traffic (S4) | p50/p95/p99, error rate | k6 summary export |
| Host | CPU per PHP worker, connections | `pidstat`, `SHOW PROCESSLIST` |

### 4.4 Report template (per scenario)

> **Scenario / dataset / topology** → table of the sweep, one chart,
> **projection against the 24h RTO**, and the *decision the number feeds*
> (S1→batch-size default; S2→uploader language tripwire; S4→throttle
> default; S6→provisioning path).

Run order: S6 and S1 first (cheapest, biggest unknowns), then S2/S3, S4
last (needs the tuned defaults from S1/S2 to be meaningful).

---

## 5. Prep checklist

- [x] `docs/demo/docker-compose.yml`, `seed-legacy.sh`, `build-manifest.sh`
      (shell scripts smoke-tested: JSONL valid, mtimes backdated)
- [x] `docs/demo/import-spike.php` — linted and **validated end-to-end**
      (2026-07-10, 100-file dataset): import 0.71s (11.5k rows/s DB leg,
      42.5 MiB/s objects), no-op rerun 0.09s, drift dry-run named the
      exact deleted object, rerun healed it, WebDAV round-trip
      byte-identical (md5 match), folder sizes/etags propagated
- [x] `app` container (Dockerfile + compose): PHP 8.3 runs this checkout,
      no host PHP needed
- [x] Rig installed: NC 35 dev + MariaDB + MinIO primary object storage;
      demo01–03 provisioned (demo01 reserved un-imported for Act 1)
- [ ] Act 1–2 (files_external mount of legacy-data + scan timing) — needs
      the files_external mount configured for demo01
- [ ] Larger dataset for headline numbers (USERS=20 FILES=500, MIRROR=1)
- [ ] k6 script for the S4 live-traffic shape
- [ ] Scorecard + one S4 chart in the deck; extrapolations labeled
