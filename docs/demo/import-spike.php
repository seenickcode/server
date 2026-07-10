#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nick Manning
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ============================================================================
 * THROWAWAY DEMO / BENCHMARK HARNESS — NOT FOR MERGE, NOT FOR PRODUCTION.
 * ============================================================================
 *
 * Manifest-driven batched file registration into a user's primary object
 * storage. Validates the mechanics mapped in docs/code-review.md §5:
 *
 *   1. bulk existence pre-check (one path_hash IN (...) SELECT per chunk,
 *      sidestepping Cache::insert's transaction-fission-on-conflict)
 *   2. parents-first folder creation with a parent-id map (no per-row
 *      getParentId SELECT)
 *   3. file rows inserted in transactions of --batch-size
 *   4. object PUT to urn:oid:<fileid> (rows-first, objects-second — the
 *      same protocol as ObjectStoreStorage::writeStream, batched)
 *   5. verify via objectExists; heal missing objects on re-run
 *   6. ONE Propagator batch flush at the end (ancestor etags/sizes/mtimes
 *      bump once — the sync-visibility "commit")
 *
 * Deliberate simplifications: no file locking (import-owned subtree), whole
 * manifest held in memory (fine to ~1M entries), serial object PUTs,
 * per-row Cache::insert() inside batch transactions (a real upstream
 * primitive would use multi-row INSERT). Refuses nothing — do not point it
 * at live user data.
 *
 * Usage:
 *   php docs/demo/import-spike.php --manifest=/tmp/demo02.jsonl --user=demo02 \
 *       [--batch-size=1000] [--dry-run] [--register-only] [--no-verify] \
 *       [--report=/tmp/results.csv]
 *
 * Manifest: JSONL, one object per line:
 *   {"path":"Imported/a/b.bin","size":123,"mtime":1690000000,
 *    "mimetype":"application/octet-stream","source":"/abs/local/file"}
 */

use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;

if (PHP_SAPI !== 'cli') {
	echo "CLI only\n";
	exit(1);
}

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../lib/base.php';

function fail(string $msg): never {
	fwrite(STDERR, "error: $msg\n");
	exit(1);
}

function parentOf(string $path): string {
	$dir = dirname($path);
	return $dir === '.' ? '' : $dir;
}

function fmtBytes(float $bytes): string {
	foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
		if ($bytes < 1024) {
			return sprintf('%.1f %s', $bytes, $unit);
		}
		$bytes /= 1024;
	}
	return sprintf('%.1f PiB', $bytes);
}

$opts = getopt('', ['manifest:', 'user:', 'batch-size::', 'dry-run', 'register-only', 'no-verify', 'report::']);
$manifestPath = $opts['manifest'] ?? fail('--manifest required');
$uid = $opts['user'] ?? fail('--user required');
$batchSize = max(1, (int)($opts['batch-size'] ?? 1000));
$dryRun = isset($opts['dry-run']);
$registerOnly = isset($opts['register-only']);
$verify = !isset($opts['no-verify']);
$reportCsv = $opts['report'] ?? null;

if (!is_readable($manifestPath)) {
	fail("cannot read manifest $manifestPath");
}
if (!Server::get(IUserManager::class)->userExists($uid)) {
	fail("no such user: $uid");
}

// --- resolve the user's home storage, cache, object store --------------------
$rootFolder = Server::get(IRootFolder::class);
$userFolder = $rootFolder->getUserFolder($uid); // sets up mounts + skeleton
$storage = $userFolder->getMountPoint()->getStorage() ?? fail('no storage for user');
$cache = $storage->getCache();
$connection = Server::get(IDBConnection::class);
$storageNumericId = $cache->getNumericStorageId();
$prefix = trim($userFolder->getInternalPath(), '/'); // usually 'files'

$objectStoreStorage = null;
if ($storage->instanceOfStorage(ObjectStoreStorage::class)) {
	$unwrapped = $storage;
	while ($unwrapped instanceof Wrapper) {
		$unwrapped = $unwrapped->getWrapperStorage();
	}
	if ($unwrapped instanceof ObjectStoreStorage) {
		$objectStoreStorage = $unwrapped;
	}
}
if (!$registerOnly && $objectStoreStorage === null) {
	fail('primary storage is not an object store; re-run with --register-only');
}
$objectStore = $objectStoreStorage?->getObjectStore();

// --- parse manifest -----------------------------------------------------------
$tStart = microtime(true);
$entries = []; // target path => entry
foreach (file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $lineNo => $line) {
	$e = json_decode($line, true);
	if (!is_array($e) || !isset($e['path'], $e['size'], $e['mtime'], $e['mimetype'])) {
		fail('bad manifest line ' . ($lineNo + 1));
	}
	$target = $cache->normalize($prefix . '/' . ltrim((string)$e['path'], '/'));
	$entries[$target] = [
		'target' => $target,
		'size' => (int)$e['size'],
		'mtime' => (int)$e['mtime'],
		'mimetype' => (string)$e['mimetype'],
		'source' => isset($e['source']) ? (string)$e['source'] : null,
		'checksum' => isset($e['checksum']) ? (string)$e['checksum'] : '',
	];
}
$total = count($entries);
echo "manifest: $total entries for user $uid (storage $storageNumericId, prefix '$prefix')\n";

// --- phase 1: bulk existence pre-check ---------------------------------------
$tPre = microtime(true);
$hashToTarget = [];
foreach ($entries as $target => $_) {
	$hashToTarget[md5($target)] = $target;
}
$existing = []; // target => [fileid, size, mtime]
$preCheckQueries = 0;
foreach (array_chunk(array_keys($hashToTarget), 1000) as $chunk) {
	$qb = $connection->getQueryBuilder();
	$result = $qb->select('fileid', 'path', 'size', 'mtime')
		->from('filecache')
		->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageNumericId, IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
		->executeQuery();
	$preCheckQueries++;
	while ($row = $result->fetch()) {
		$existing[$row['path']] = [
			'fileid' => (int)$row['fileid'],
			'size' => (int)$row['size'],
			'mtime' => (int)$row['mtime'],
		];
	}
	$result->closeCursor();
}

$new = [];       // target => entry
$changed = [];   // target => entry (+fileid, +oldSize)
$unchanged = []; // target => entry (+fileid)
foreach ($entries as $target => $e) {
	if (!isset($existing[$target])) {
		$new[$target] = $e;
	} elseif ($existing[$target]['size'] !== $e['size'] || $existing[$target]['mtime'] !== $e['mtime']) {
		$e['fileid'] = $existing[$target]['fileid'];
		$e['oldSize'] = $existing[$target]['size'];
		$changed[$target] = $e;
	} else {
		$e['fileid'] = $existing[$target]['fileid'];
		$unchanged[$target] = $e;
	}
}
$tPreDone = microtime(true);
printf(
	"pre-check:   %d rows in %d SELECTs, %.2fs — %d new, %d changed, %d unchanged\n",
	$total, $preCheckQueries, $tPreDone - $tPre, count($new), count($changed), count($unchanged)
);

// --- dry run: this IS the drift report ----------------------------------------
if ($dryRun) {
	$driftMissingObjects = [];
	if ($verify && $objectStore !== null && !$registerOnly) {
		foreach ([$unchanged, $changed] as $set) {
			foreach ($set as $target => $e) {
				if (!$objectStore->objectExists($objectStoreStorage->getURN($e['fileid']))) {
					$driftMissingObjects[] = $target;
				}
			}
		}
	}
	echo "dry-run: would insert " . count($new) . ' rows, update ' . count($changed) . ' rows'
		. ($verify && $objectStore !== null && !$registerOnly
			? '; objects missing for ' . count($driftMissingObjects) . ' existing rows'
			: '') . "\n";
	foreach (array_slice($driftMissingObjects, 0, 20) as $t) {
		echo "  DRIFT missing object: $t\n";
	}
	exit(count($new) + count($changed) + count($driftMissingObjects) > 0 ? 2 : 0);
}

// --- phase 2: ensure folders, parents-first, with a parent-id map -------------
$tFolders = microtime(true);
$rootId = $cache->getId($prefix);
if ($rootId === -1) {
	fail("user files root '$prefix' not in filecache");
}
$dirIds = [$prefix => $rootId];

$needed = [];
foreach ($new as $e) {
	$d = parentOf($e['target']);
	while ($d !== '' && $d !== $prefix && !isset($needed[$d])) {
		$needed[$d] = true;
		$d = parentOf($d);
	}
}
$dirList = array_keys($needed);
usort($dirList, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

$foldersCreated = 0;
$now = time();
foreach ($dirList as $dir) {
	$id = $cache->getId($dir);
	if ($id === -1) {
		$parentId = $dirIds[parentOf($dir)] ?? $cache->getId(parentOf($dir));
		$id = $cache->insert($dir, [
			'mimetype' => 'httpd/unix-directory',
			'size' => 0,
			'mtime' => $now,
			'storage_mtime' => $now,
			'permissions' => Constants::PERMISSION_ALL,
			'etag' => uniqid(),
			'parent' => $parentId,
		]);
		$foldersCreated++;
	}
	$dirIds[$dir] = $id;
}
$tFoldersDone = microtime(true);

// --- phase 3: file rows, batched transactions ---------------------------------
$tRows = microtime(true);
$inserted = 0;
foreach (array_chunk(array_keys($new), $batchSize) as $chunkKeys) {
	$connection->beginTransaction();
	try {
		foreach ($chunkKeys as $target) {
			$e = $new[$target];
			$parentId = $dirIds[parentOf($target)] ?? $cache->getId(parentOf($target));
			$new[$target]['fileid'] = $cache->insert($target, [
				'size' => $e['size'],
				'mtime' => $e['mtime'],
				'storage_mtime' => $e['mtime'], // truthful: cache IS the storage here
				'mimetype' => $e['mimetype'],
				'etag' => uniqid(),
				'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE,
				'checksum' => $e['checksum'],
				'parent' => $parentId,
			]);
			$inserted++;
		}
		$connection->commit();
	} catch (\Throwable $t) {
		if ($connection->inTransaction()) {
			$connection->rollBack();
		}
		throw $t;
	}
}

$updated = 0;
if ($changed !== []) {
	$connection->beginTransaction();
	try {
		foreach ($changed as $e) {
			$cache->update($e['fileid'], [
				'size' => $e['size'],
				'mtime' => $e['mtime'],
				'storage_mtime' => $e['mtime'],
				'etag' => uniqid(),
			]);
			$updated++;
		}
		$connection->commit();
	} catch (\Throwable $t) {
		if ($connection->inTransaction()) {
			$connection->rollBack();
		}
		throw $t;
	}
}
$tRowsDone = microtime(true);
$rowsSecs = max($tRowsDone - $tRows, 0.001);
printf(
	"rows:        %d inserted, %d updated, %d folders — %.2fs (%.0f rows/s, batch=%d)\n",
	$inserted, $updated, $foldersCreated, $rowsSecs,
	($inserted + $updated) / $rowsSecs, $batchSize
);

// --- phase 4: objects (rows-first, objects-second) ----------------------------
$tObjects = microtime(true);
$objectsWritten = 0;
$bytesWritten = 0;
if (!$registerOnly) {
	foreach ([$new, $changed] as $set) {
		foreach ($set as $e) {
			if ($e['source'] === null || !is_readable($e['source'])) {
				fail("missing source for {$e['target']} — cannot write object");
			}
			$fh = fopen($e['source'], 'r');
			$objectStore->writeObject($objectStoreStorage->getURN($e['fileid']), $fh, $e['mimetype']);
			if (is_resource($fh)) {
				fclose($fh);
			}
			$objectsWritten++;
			$bytesWritten += $e['size'];
		}
	}
}
$tObjectsDone = microtime(true);

// --- phase 5: verify (and heal) ------------------------------------------------
$tVerify = microtime(true);
$verified = 0;
$healed = 0;
$driftErrors = 0;
if ($verify && !$registerOnly) {
	foreach ([$new, $changed, $unchanged] as $set) {
		foreach ($set as $e) {
			$urn = $objectStoreStorage->getURN($e['fileid']);
			if ($objectStore->objectExists($urn)) {
				$verified++;
				continue;
			}
			if ($e['source'] !== null && is_readable($e['source'])) {
				$fh = fopen($e['source'], 'r');
				$objectStore->writeObject($urn, $fh, $e['mimetype']);
				if (is_resource($fh)) {
					fclose($fh);
				}
				$healed++;
				$bytesWritten += $e['size'];
			} else {
				fwrite(STDERR, "DRIFT: object missing and no source for {$e['target']}\n");
				$driftErrors++;
			}
		}
	}
}
$tVerifyDone = microtime(true);

// --- phase 6: ONE propagation flush -------------------------------------------
$tProp = microtime(true);
$propagator = $storage->getPropagator();
$propagator->beginBatch();
foreach ($new as $e) {
	$propagator->propagateChange($e['target'], $e['mtime'], $e['size']);
}
foreach ($changed as $e) {
	$propagator->propagateChange($e['target'], $e['mtime'], $e['size'] - $e['oldSize']);
}
$propagator->commitBatch();
$tPropDone = microtime(true);

// --- report ---------------------------------------------------------------------
$tTotal = microtime(true) - $tStart;
$objSecs = max($tObjectsDone - $tObjects, 0.001);
if (!$registerOnly) {
	printf(
		"objects:     %d written (%s) — %.2fs (%.1f obj/s, %s/s)\n",
		$objectsWritten, fmtBytes((float)$bytesWritten), $objSecs,
		$objectsWritten / $objSecs, fmtBytes($bytesWritten / $objSecs)
	);
}
if ($verify && !$registerOnly) {
	printf(
		"verify:      %d ok, %d healed, %d DRIFT — %.2fs\n",
		$verified, $healed, $driftErrors, $tVerifyDone - $tVerify
	);
}
printf("propagation: 1 batch flush — %.2fs\n", $tPropDone - $tProp);
printf("total:       %.2fs\n", $tTotal);

if ($reportCsv !== null) {
	$header = "ts,user,entries,inserted,updated,unchanged,folders,batch_size,objects_written,bytes,healed,drift,secs_precheck,secs_rows,secs_objects,secs_verify,secs_propagate,secs_total,rows_per_sec,obj_per_sec\n";
	$line = sprintf(
		"%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%.3f,%.3f,%.3f,%.3f,%.3f,%.3f,%.1f,%.1f\n",
		date('c'), $uid, $total, $inserted, $updated, count($unchanged), $foldersCreated, $batchSize,
		$objectsWritten, $bytesWritten, $healed, $driftErrors,
		$tPreDone - $tPre, $rowsSecs, $objSecs, $tVerifyDone - $tVerify, $tPropDone - $tProp, $tTotal,
		($inserted + $updated) / $rowsSecs, $objectsWritten / $objSecs
	);
	if (!file_exists($reportCsv)) {
		file_put_contents($reportCsv, $header);
	}
	file_put_contents($reportCsv, $line, FILE_APPEND);
	echo "report appended to $reportCsv\n";
}

exit($driftErrors > 0 ? 2 : 0);
