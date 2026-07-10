# Nextcloud Server Code Review — Write Path, Scan Path, Object Store Coupling

Purpose: technical appendix for the failover bulk-import design (manifest-driven
reconciler with a swappable executor). Maps what "ensure file exists" costs
today, where per-file behavior is structural, and where a batched
bulk-registration primitive can slot in.

Reviewed against `master` (2026-07). All line numbers from this checkout.

> **Version note:** `occ files:scan` lives at `apps/files/lib/Command/Scan.php`
> (moved out of `core/Command/Files/`). The command delegates to
> `lib/private/Files/Utils/Scanner.php` (mount/user orchestration), which
> drives `lib/private/Files/Cache/Scanner.php` (per-storage scanning).

---

## 1. File → responsibility → coupling map

| File | Responsibility | Key coupling points |
|---|---|---|
| `lib/private/Files/Cache/Cache.php` | The **only** writer of `oc_filecache` rows (insert/update/remove/move); folder-size recalculation | fileid allocation (DB auto-increment at INSERT); parent row must pre-exist; per-row events (`CacheEntryInsertedEvent`…); unique key `(storage, path_hash)`; commits/restarts *caller's* transaction on conflict |
| `lib/private/Files/Cache/Scanner.php` | Discovery: stat storage, diff vs cache, call Cache insert/update; per-directory transactions | one shared lock + one stat + ≥2 SELECTs + 1 write **per file**; parent-first recursion; etag reuse policy (`storage_mtime` comparison) |
| `lib/private/Files/Cache/Propagator.php` | Bump mtime/etag/size of all ancestors after a change | `SELECT … FOR UPDATE` on ancestor rows (ordered by path_hash, deadlock-avoidance); **has an existing batch mode** (`beginBatch`/`commitBatch`) |
| `lib/private/Files/Cache/Updater.php` | Reacts to in-process filesystem ops: shallow rescan + size diff + propagate | calls `Scanner::scan(SHALLOW)` + `Propagator::propagateChange` per operation; `correctParentStorageMtime` extra UPDATE per op |
| `lib/private/Files/Utils/Scanner.php` | Per-user/mount orchestration for occ scan; wraps each mount scan in a propagator batch | dispatches typed events per file (`NodeAddedToCache`, `FileCacheUpdated`); `correctFolderSize` for scan root |
| `apps/files/lib/Command/Scan.php` | CLI loop: users → mounts → `Utils\Scanner::scan` | strictly serial (one process, one user, one file at a time); per-file interrupt check & counter listeners |
| `lib/private/Files/ObjectStore/ObjectStoreStorage.php` | Storage backend where **metadata lives only in the cache**; objects keyed `urn:oid:<fileid>` | `writeStream()` couples cache INSERT → fileid → URN → S3 put → cache move/update; `mkdir`/`rename` are cache-only ops; `getURN()` is public & overridable |
| `lib/private/Files/ObjectStore/ObjectStoreScanner.php` | Neuters the scanner for object stores (`scan()`/`scanFile()` return null) | **`occ files:scan` cannot index primary object storage at all** — proof that manifest-driven registration is the only viable path there |
| `lib/private/Files/ObjectStore/S3ObjectTrait.php` | Raw S3 I/O: single put < `putSizeLimit`, multipart above; SSE-C/SSE-KMS params on every call | object write knows nothing about the cache; coupling is one-directional (URN string) |
| `lib/public/Files/Cache/ICache.php`, `IScanner.php`, `IPropagator.php` | OCP surface an upstream-alignable primitive must extend | today: strictly single-entry (`insert($file, $data)`), no bulk method |

---

## 2. Write path: what one new file costs (`Cache.php`)

Entry point `Cache::put()` (`Cache.php:259`) → `getId()` SELECT decides
insert vs update.

### `Cache::insert()` (`Cache.php:284`) — per row:

1. **Normalize** path to Unicode NFC (`normalize()`, `Cache.php:1227`) — an
   invariant, not cosmetics: `path_hash = md5(NFC path)` is the unique key.
2. **Partial-data gate**: `size`, `mtime`, `mimetype` required, else the entry
   is buffered in `$this->partial` and **no row is written** (`Cache.php:293–299`).
3. **Parent lookup**: `getParentId()` → 1 SELECT. If the parent row doesn't
   exist → hard throw `Parent folder not in filecache` (`Cache.php:305–307`).
   ⇒ *invariant: strict parent-before-child insertion order.*
4. **`normalizeData()`** (`Cache.php:452`):
   - `path_hash = md5(path)`;
   - mimetype string → numeric id via `IMimeTypeLoader` (may itself INSERT
     into `oc_mimetypes` on first sight);
   - if `mtime` absent, **`storage_mtime` is copied into `mtime`** (`Cache.php:474–477`);
   - `encrypted` bool → int, or `encryptedVersion` if set (`Cache.php:478–485`);
   - `metadata_etag`, `creation_time`, `upload_time` split out for
     `oc_filecache_extended`.
5. **1 INSERT into `oc_filecache`**; **fileid = `getLastInsertId()`**
   (`Cache.php:322–323`). This is the *only* place fileids are minted —
   DB auto-increment, at insert time, one at a time.
6. Optional **1 INSERT into `oc_filecache_extended`** (creation/upload time —
   the import cares: original timestamps live here) (`Cache.php:325–335`).
7. **Two event dispatches** of `CacheEntryInsertedEvent` (legacy string +
   typed) (`Cache.php:337–339`). Listeners run synchronously in-process:
   preview generation queuing, activity, files_metadata, search indexing, etc.
8. **Conflict handling** (`Cache.php:342–352`): on unique-constraint violation
   `(storage, path_hash)`, if inside a transaction it **commits the caller's
   transaction and opens a new one**, then falls through to `update()`.
   ⇒ *a batch wrapped in one transaction is silently split at every conflict —
   atomicity of a batch cannot be assumed if re-running over existing rows
   (which an idempotent reconciler does by design).*

### `Cache::update()` (`Cache.php:370`) — per row:

- 1 UPDATE with a self-neutralizing WHERE (`neq OR isNull` per column,
  `Cache.php:389–394`) so unchanged data costs a no-op-row UPDATE;
- upsert into `filecache_extended` (INSERT, catch conflict, UPDATE);
- 1 SELECT `getPathById` + `CacheEntryUpdatedEvent` dispatch.

### Size & etag propagation — two distinct mechanisms

**A. `Cache::correctFolderSize()`** (`Cache.php:970`) — recursive walk **to the
root**: per ancestor, `calculateFolderSize()` SELECTs *all direct children's
sizes* (`Cache.php:1044–1055`) + conditional UPDATE. Cost O(depth ×
avg-folder-width) reads per correction. Sentinel: folder `size = -1` means
"not fully scanned" (feeds `getIncomplete()` / background scan).

**B. `Propagator::propagateChange()`** (`Propagator.php:43`) — computes every
ancestor path, then a single UPDATE
`SET mtime = GREATEST(mtime, :t), etag = uniqid(), size = size + :delta (CASE WHEN size > -1)`
over `path_hash IN (…)`. On non-SQLite it first locks the ancestor rows with
`SELECT … FOR UPDATE ORDER BY path_hash` inside a transaction
(`Propagator.php:121–139`) — deterministic lock order to dodge deadlocks, with
3 retries. **This is the live-instance contention hotspot**: every write under
a user bumps the same root rows.

**Batch mode already exists** (`Propagator.php:169–286`): `beginBatch()`
aggregates `(path → max(time), Σ sizeDiff)` in memory; `commitBatch()` locks
all touched ancestors once and applies one UPDATE per ancestor row in a single
transaction. `Utils\Scanner` wraps every mount scan in it
(`Utils/Scanner.php:223–239`). ⇒ *upstream precedent for exactly the batching
posture the import needs: N file writes → one propagation flush.*

### Etag semantics (matters for sync clients)

- Etags are **opaque random tokens**, not content hashes:
  `Common::getETag()` = `uniqid()` (`lib/private/Files/Storage/Common.php:422`),
  ditto every propagation.
- Desktop/mobile sync discovers changes **top-down by comparing folder
  etags**. Invariant for any batch write: after a subtree lands, every
  ancestor folder etag up to the root must change *once* — otherwise clients
  never see imported files; conversely one root bump after a completed batch
  triggers exactly one discovery walk. Never bump per-file (that's N
  full-tree re-walks).
- Scanner reuses an existing etag only if `storage_mtime` is unchanged and the
  folder isn't marked unscanned (`Scanner.php:169–185`). ⇒ *invariant: write
  `storage_mtime` = the value the storage will report later*, or the next
  scan/Updater pass treats every imported file as modified, regenerating etags
  and stampeding the sync clients.

---

## 3. Scan path: why per-file processing is structural

### `Scanner::scanFile()` (`Scanner.php:104`) — per file, in order:

1. `verifyPath` + partial-file blacklist;
2. **shared lock acquire** via `ILockingProvider` (Redis/DB round-trip)
   (`Scanner.php:119–123`);
3. `getData()` → `storage->getMetaData()` — **a stat against the backing
   storage**; for external S3 that is a network HEAD per file (the 20–50
   files/s ceiling in #58549 lives here plus the per-file writes);
4. `getParentId()` SELECT; if parent missing, *recursively scan the parent
   synchronously* (`Scanner.php:156–163`);
5. `cache->get($file)` SELECT; strict array diff against cache
   (`array_diff_assoc_multi`); etag-reuse decision;
6. `addToCache()` → `Cache::insert`/`update` + **two hook systems fire per
   file** (`OC_Hook::emit` + emitter) (`Scanner.php:252–268`);
7. lock release.

Per new file: 1 lock cycle + 1 storage stat + ~3 SELECTs + 1 INSERT (+1
extended INSERT) + ≥4 hook/event dispatches. Nothing here is amortizable
across files as written — every step is keyed to one path.

### `Scanner::scanChildren()` / `handleChildren()` (`Scanner.php:395, 443`)

- Per directory: 1 `getFolderContentsById` SELECT + 1
  `storage->getDirectoryContent()` (external S3: LIST), then **serial
  `scanFile()` per child**, all inside **one DB transaction per directory**
  (`Scanner.php:453–523`, disable via `filescanner_no_transactions`).
- Note `handleChildren` *already holds the full child metadata array in
  memory before writing* — the natural graft point for a batched insert.
- On any child exception: rollback of the whole directory transaction,
  begin a fresh one, continue (`Scanner.php:496–508`) — designed around
  racing parallel scanners hitting the unique constraint.
- Depth-first recursion; parent folder's size/etag fixed up after children
  (`Scanner.php:423–435`).
- Exclusive `scanner::<path>` lock serializes whole-subtree scans
  (`Scanner.php:302–305`).

### `apps/files/lib/Command/Scan.php` + `Utils\Scanner`

- Outer loop: users → serial (`Scan.php:253–281`); per user, filesystem
  setup/teardown + fresh DB connection; per mount:
  listener attach, `propagator->beginBatch()`, `scanner->scan(recursive)`,
  `correctFolderSize(root)`, `commitBatch()` (`Utils/Scanner.php:222–239`).
- Per-file event dispatches for counters and interrupt checks
  (`Scan.php:126–164`).

**Why serial-per-file is structural, not incidental:**

1. **fileid dependency chain** — a child row cannot be written until its
   parent's fileid exists, and fileids only exist after the parent's INSERT
   returns (`getLastInsertId`). Ordering is enforced by throw
   (`Cache.php:305`), and satisfied by depth-first recursion. Any batching
   must preserve topological (parents-first) order.
2. **Discovery and registration are fused** — the scanner learns what exists
   by stat/LIST *while* writing, so it cannot know batch contents in advance.
   (A manifest severs exactly this fusion.)
3. **Concurrency safety is conflict-driven, not coordinated** — parallel
   scans are "supported" only via unique-constraint violation → rollback →
   retry-as-update. There is no work-queue or partitioning abstraction.
4. **Per-file side-channel obligations** — locks, two hook systems, typed
   events, interrupt checks — are all keyed per path in the hot loop.
5. **For primary object storage it's not even slow — it's absent**:
   `ObjectStoreStorage::getScanner()` returns `ObjectStoreScanner`
   whose `scan()`/`scanFile()` return null (`ObjectStoreScanner.php:17–29`).
   The object store is not listable into a namespace; the cache *is* the
   filesystem. Scan-based import into the target topology (goal 3) is
   structurally impossible, independent of performance.

---

## 4. Object store: fileid → `urn:oid` coupling (`ObjectStoreStorage.php`)

- **Key scheme**: `getURN(int $fileId) = objectPrefix . $fileId`, default
  prefix `'urn:oid:'` (`ObjectStoreStorage.php:45, 266–268`). Public,
  documented as overridable ("You may need a mapping table … if it cannot be
  generated from the fileid"), and the prefix is a constructor parameter
  (`objectPrefix`, line 67).
- **Flat bucket**: no directories in S3. `mkdir()` writes only a cache row
  (`ObjectStoreStorage.php:81–124`); `rename()` is `cache->move()` only
  (`:402–409`) — because the key is the fileid, moves never touch objects.
- **Write sequence** — `writeStream()` (`ObjectStoreStorage.php:478`):
  1. `stat()` (cache get); build stat: `size`, `mtime = storage_mtime =
     time()`, mimetype from path, `etag = uniqid()`, `checksum = ''`;
  2. **new file → cache INSERT first**: `cache->put($path.'.part', $stat)`
     returns the fileid (`:514–525`). *The DB row (as `.part`) exists before
     any byte reaches S3.*
  3. `objectStore->writeObject(urn:oid:<fileid>, stream)` — S3 single put or
     multipart (`S3ObjectTrait.php:206–255`), SSE parameters attached per
     request;
  4. success: if `validateWrites`, HEAD (`objectExists`) then
     `cache->move('.part' → final)`; failure: `cache->remove('.part')`
     (`:577–588`).
- So the coupling is **cache-row-first, object-second, commit-by-rename** —
  with the `.part` row as the in-flight marker. A manifest-driven registrar
  that (a) bulk-inserts rows, (b) writes objects to `urn:oid:<fileid>`,
  (c) verifies/flips state, is the *same protocol batched*, not a new one.
- Reads trust the cache: `fopen('r')` looks up `stat['fileid']` and streams
  the URN (`:303–355`); a mismatch between cached size and object size is
  self-healed on read (`:328–332`).
- `objectExists()` = S3 HEAD (`S3ObjectTrait.php:273–275`) — the natural
  verification primitive for the drift report.
- SSE: every S3 call attaches `getServerSideEncryptionParameters()` — bucket-
  level SSE is transparent to this layer. (Nextcloud's *own* server-side
  encryption app is a storage **wrapper** above this; the `encrypted` column
  stores its key version. If openDesk enables it, direct object writes bypass
  the wrapper and produce unreadable files — this is the go/no-go blocker
  from the one-pager, confirmed in code: `Cache::normalizeData` persists
  `encryptedVersion` (`Cache.php:478–485`), and nothing at the
  ObjectStoreStorage layer would create it.)

---

## 5. Design questions

### 5.1 Can one batched-ingestion primitive serve both external-storage scan and manifest registration?

**Yes — at the `Cache` layer, and the graft points already exist on both sides.**

Both consumers reduce to the same operation: *"given N fully-specified
metadata entries whose parent rows exist, make `oc_filecache` match."*

- **Scanner side**: `handleChildren()` already materializes the complete
  child list (`$newChildren = iterator_to_array(getDirectoryContent(...))`,
  `Scanner.php:446`) and the existing-children map *before* its write loop.
  Replace the serial `scanFile → insert` tail with one
  `insertBatch(array $entries): array<fileid>` / `updateBatch(...)` call and
  the scanner becomes a consumer of the primitive without changing discovery
  semantics.
- **Manifest side**: the manifest supplies exactly the fields
  `normalizeData()` wants (path, size, mtime, checksum, mimetype + derived
  storage_mtime/etag/permissions/parent), pre-sorted parents-first (paths are
  deterministic functions of the manifest — sorting by path depth is the
  topological order).
- **The shape of the primitive** (OCP-alignable): an extension to
  `OCP\Files\Cache\ICache` —
  `insertBatch(list<ICacheEntry-shaped arrays>): list<int fileids>` with
  defined semantics for: conflict rows (return existing id + update — the
  idempotent "ensure" semantics), event emission (one bulk
  `CacheEntriesInsertedEvent`, mirroring the existing
  `CacheEntriesRemovedEvent` precedent at `Cache.php:671`), and no implicit
  propagation (caller flushes a `Propagator` batch). The remainder of the
  reconciler (manifest parsing, phases, throttle) stays out of core — only
  the batch write + bulk event land upstream.

What the two consumers *don't* share: the scanner derives desired state by
stat/LIST (lossy, slow); the manifest carries it. That difference lives
entirely above the primitive.

### 5.2 What invariants must batched writes uphold?

1. **Parent-before-child**: parent fileid must exist at child-insert time
   (`Cache.php:305`). Batch = topologically ordered; folders first.
2. **Path normalization + uniqueness**: NFC-normalized path,
   `path_hash = md5(path)`, unique `(storage, path_hash)`. Manifest builder
   must emit NFC (duplicate-after-normalization is a manifest-build-time
   policy decision, confirming the one-pager's stance).
3. **Required fields**: `size`, `mtime`, `mimetype` per row or the row is
   silently deferred (`Cache.php:293–299`); mimetypes resolved to ids (bulk
   pre-insert distinct mimetypes once per batch).
4. **`storage_mtime` truthfulness**: must equal what the storage will report
   (for object store: what we set — self-consistent; for external mounts: the
   real S3 mtime) or every subsequent scan invalidates etags → sync stampede.
   Original timestamps from the manifest go to `mtime` (+ `creation_time` in
   `filecache_extended`).
5. **Etag discipline**: non-empty opaque etag per row; ancestor folder etags
   bumped **once per completed batch/subtree** via the existing
   `Propagator::beginBatch/commitBatch`, never per file; root bump last (it
   is the client's discovery trigger — effectively the batch's commit marker
   for sync visibility).
6. **Size algebra**: folder sizes are either correct sums or `-1`
   ("unscanned" — feeds background scan; `Cache::getIncomplete()`).
   A batch may insert folders at `-1` and finalize sizes bottom-up at
   subtree completion, or compute exact sizes from the manifest upfront
   (preferable: `-1` folders attract `ObjectStoreScanner::backgroundScan`,
   which does a per-object `fopen`/fstat — an accidental S3 GET storm).
   Never leave a *wrong positive* size.
7. **Encrypted flag**: `0` unless Nextcloud's encryption wrapper owns the
   file; if openDesk runs the encryption app, the direct-write executor is
   off the table entirely (SSE blocker).
8. **Permissions non-zero** (scanner skips 0-permission entries,
   `Scanner.php:461–463`).
9. **Events**: apps assume per-row `CacheEntryInsertedEvent`. A batch
   primitive must define this explicitly (bulk event + opt-in suppression of
   per-row dispatch) — silently skipping breaks previews/activity/search;
   dispatching 10M synchronously breaks the RTO. This is the main upstream
   API-design conversation.
10. **Quota/mount visibility**: user root sizes and `oc_mounts`
    (`IUserMountCache`) must be consistent after phase completion for quota
    enforcement and share resolution.

### 5.3 Where can fileid pre-allocation hook in safely?

fileids are minted solely by `oc_filecache` auto-increment at INSERT
(`Cache.php:323`). Three hooks, in order of upstream-friendliness:

1. **Rows-first, objects-second (recommended)** — don't fight the allocator;
   invert nothing. Batched cache INSERT returns fileids (per-row
   `lastInsertId` inside the batch transaction today; `INSERT … RETURNING`
   where the platform allows), then write objects to `urn:oid:<fileid>`,
   then verify (`objectExists`) and finalize. This is `writeStream()`'s own
   `.part`-row protocol, batched — same crash-safety story: a reconcile pass
   distinguishes row-without-object (rewrite) from object-without-row
   (orphan sweep) — which is exactly the drift report the design already
   requires. The in-flight marker generalizes from a `.part` path suffix to
   "row exists, verification pending" (e.g. size `-1` until verified).
2. **URN independence** — override `getURN()`/set `objectPrefix` so object
   keys derive from manifest source IDs instead of fileids. Explicitly
   sanctioned by the docblock (`ObjectStoreStorage.php:260–264`), and lets
   object upload run **before/parallel to** any DB work (bytes can land
   during backup, registration happens at failover). Cost: departs from
   stock key layout for the whole storage (it's per-storage, not per-file),
   needs a mapping, and weakens the "plain Nextcloud after import" story.
3. **Id-range reservation** (placeholder rows / manual sequence
   manipulation) — fights the allocator, DB-platform-specific, sharding
   hostile. Not recommended; listed for completeness.

Note for option 1: at 120k users the per-user *storage rows*
(`oc_storages`, `object::user:<uid>`) and root folder rows are themselves a
bulk-provisioning surface — same primitive, phase 1.

### 5.4 What do Cache.php's transaction boundaries imply for batch sizing?

- **Conflict = transaction fission.** `Cache::insert()` on unique-violation
  *commits the surrounding transaction and starts a new one*
  (`Cache.php:342–352`). An idempotent reconciler re-running over partially
  imported state hits conflicts constantly ⇒ either (a) bulk pre-check
  existence (`SELECT path_hash IN (…)` — one query per batch) and split into
  disjoint insert/update sets before opening the transaction, or (b) accept
  that "batch" means "at-least-once per row, atomic only between conflicts."
  (a) is cheap and keeps semantics clean; the reconciler diff needs that
  SELECT anyway.
- **Precedent for size**: the scanner's unit of atomicity is *one directory*
  (`Scanner.php:453–523`); the propagator batches a whole mount scan into one
  commit. So core already tolerates transactions of hundreds–thousands of
  rows, but nothing bigger has precedent. Start with per-directory or
  fixed-N (≈500–2000 row) commits; a batch is a resume checkpoint, so smaller
  batches also bound re-work on failure.
- **Lock scope is ancestor rows, not inserted rows.**
  `Propagator::commitBatch` takes `FOR UPDATE` on every touched ancestor in
  one transaction (`Propagator.php:203–247`). Batches confined to a single
  user's subtree keep lock sets disjoint from other import workers *and*
  from live traffic on other users — the DB-level argument for the
  "embarrassingly parallel per user" phase-2 design. Never batch across
  users; flush propagation per user-subtree, once.
- **Sharding**: `filecache` is shard-aware (`ShardDefinition`,
  `hintShardKey('storage', …)` throughout). Shard key = storage; each user's
  home is its own storage row ⇒ per-user batches are automatically
  single-shard. A batch primitive must simply refuse to span storages.
- **Do-no-harm knobs already present**: `filescanner_no_transactions`
  (autocommit mode for wedge-prone setups), `filesystem_cache_readonly`, and
  the propagator's retry-on-retryable-exception loop. The import's global
  throttle composes with, rather than replaces, these.

---

## 6. Condensed cost model (today vs. target)

Per new file via scan: 1 lock cycle + 1 storage stat (network on S3) +
~3 SELECTs + 1–2 INSERTs + 1 propagation UPDATE (batched per mount in occ
scan) + ≥4 hook/event dispatches — all serial in one PHP process.

Per new file via batched registration (design target): amortized share of
1 existence-check SELECT + 1 multi-row INSERT + 1 propagator-batch UPDATE per
subtree + 1 bulk event, with object PUTs parallelized independently of DB
work. No locks (import-owned subtrees), no stats (manifest is truth).

---

## 7. Existing test coverage

The reviewed write path is well covered by **integration-style tests running
against a real database** (`Test\TestCase`; CI matrix covers SQLite, MySQL,
PostgreSQL, Oracle):

| Suite | Count | Coverage relevant to the import primitive |
|---|---|---|
| `tests/lib/Files/Cache/CacheTest.php` | 27 | insert/update/move, partial-data buffering (`testPartial`), unicode normalization (`testWithNormalizer`/`testWithoutNormalizer`), `storage_mtime` copy semantics (`testStorageMTime`), fileid non-reuse (`testNoReuseOfFileId`), `getIncomplete` (`size=-1` sentinel), `filecache_extended` (`testExtended`) |
| `tests/lib/Files/Cache/ScannerTest.php` | 18 | etag reuse (`testReuseExisting`), etag recreation, no-etag-on-unscanned-folder invariants, background-scan incomplete-folder recursion, parent repair (`testRepairParent`) |
| `tests/lib/Files/Cache/PropagatorTest.php` | 5 | etag/time/size propagation, size-never-negative, **`testBatchedPropagation`** — the batch mode has first-class test precedent |
| `tests/lib/Files/Cache/UpdaterTest.php` + `UpdaterLegacyTest.php` | — | in-process update → propagation chain |
| `tests/lib/Files/ObjectStore/ObjectStoreStorageTest.php` | 12 + inherited | extends the generic storage conformance suite (`Test\Files\Storage\Storage`), so the object-store backend is held to the same contract as local storage; plus fail-injection doubles (`FailWriteObjectStore`, `FailDeleteObjectStore`) for the `.part`-row rollback paths |
| `tests/lib/Files/ObjectStore/ObjectStoreScannerTest.php` | — | pins the "scan is a no-op on object store" behavior |

Implications for the proposal:

- **Every invariant listed in §5.2 already has a test expressing it** (etag
  reuse ↔ `storage_mtime`, size algebra, normalization, propagation). An
  `insertBatch` PR can be specified largely as "CacheTest passes when N rows
  land in one call" plus new bulk-event assertions — strong upstream story,
  and the repo's contribution policy requires tests for changed logic anyway.
- **Gaps to note**: no coverage (that I found) of the
  transaction-fission-on-conflict behavior in `Cache::insert()`
  (`Cache.php:342–352`) — worth a characterization test before depending on
  or changing it; and no scale/performance tests anywhere in the path, so
  the 20–50 files/s figure from #58549 has no regression guard upstream.
  Both are cheap goodwill contributions that de-risk the main PR.
- **Live-fire find** (demo rig, 2026-07-10): `occ files:scan` counters
  misreport — newly inserted files show as "Updated", never "New". The
  `addToCache` listener in `lib/private/Files/Utils/Scanner.php:209–215`
  branches on `if ($fileId)`, but `Cache\Scanner::addToCache` emits
  `$fileId = -1` for inserts, and `-1` is truthy. Fix is
  `if ($fileId !== -1)` + a test — a third goodwill candidate.

## 8. Would implementing in Rust/Go buy speed?

Short answer: **for the data plane yes, for the control plane no — and the
control plane isn't CPU-bound anyway.**

Split the work by bottleneck:

| Workload | Bottleneck | Language leverage |
|---|---|---|
| Bytes → S3 (35 TB) | network / S3 throughput, parallelism | High — this never touches Nextcloud code. A Go/Rust uploader (or rclone) doing parallel multipart PUTs to manifest-derived keys is ideal and carries zero invariant risk. |
| Manifest building (export side) | source-API throughput | Any language; it runs upstream of Nextcloud entirely. |
| Filecache registration (tens of millions of rows) | **DB round-trips, transactions, lock scope** — not CPU | Low. PHP's per-row overhead is noise next to the SQL. The 20–50 files/s ceiling of `occ files:scan` is per-file network stats + per-file writes + serial architecture (§3), not interpreter speed. |
| Orchestration (phases, throttle, resume) | I/O waits | Any language; it calls the primitive, it doesn't need to be it. |

The arithmetic: ~30M files in 24h is ~350 rows/s sustained. Batched
multi-row inserts on MySQL/PostgreSQL comfortably reach thousands of rows/s
*from PHP* — the constraint was never language, it was one-row-one-
transaction-one-stat architecture.

Why the registration primitive should stay PHP, inside Nextcloud:

1. **Correctness surface**: mimetype-id resolution, NFC normalization,
   `path_hash`, sharding hints, encryption flags, event dispatch — all live
   in `Cache.php`. An external Rust/Go writer must reimplement them against
   a schema with no stability contract, and silently diverges on the next
   server upgrade. (This is the classic "write to another app's tables"
   failure mode.)
2. **Goal 7 (upstream-alignable)** requires the primitive be expressed
   through OCP interfaces — that is definitionally a PHP contribution.
   A side-channel importer is unproposable upstream.
3. **Do-no-harm integration**: throttling against the live instance wants
   the same DB connection semantics, config knobs
   (`filescanner_no_transactions` etc.), and lock provider the instance
   itself uses.

Recommended shape: **Go/Rust (or rclone) data plane** for object PUTs and
manifest handling, **PHP control plane** — an `occ`-invocable batch
registration primitive per §5.1 — with per-user parallelism achieved by
running N worker processes (one user subtree each, §5.4), not by making any
single process faster.

> **Decision (2026-07-10): all-PHP for now, in this server clone.** Keep the
> whole mechanism — registration primitive *and* object writes — in PHP
> against Nextcloud's own interfaces, for simplicity and to keep every line
> upstream-alignable. Scale still comes from per-user worker parallelism,
> which is language-independent. A separate Go/Rust/rclone uploader remains
> an option later purely for the bytes→S3 leg (it composes cleanly: it only
> needs manifest-derived URNs, no Nextcloud internals), if measured S3
> throughput from PHP workers ever becomes the binding constraint — per the
> table above, nothing else in the design is language-sensitive.
