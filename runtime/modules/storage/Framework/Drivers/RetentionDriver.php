<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Drivers;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Path;

/**
 * Storage driver decorator that applies retention and legal-hold rules.
 *
 * The driver forwards object operations to a configured target disk while keeping
 * retention metadata in a JSON manifest. It behaves like a soft object-lock
 * layer: reads and listing pass through, writes and deletes are refused while a
 * path is locked, and retention state can be reported or released without
 * changing the underlying storage driver's implementation.
 *
 * The manifest is process-local filesystem state, not a remote object-lock API.
 * Deployments that need regulatory retention must pair this driver with storage
 * and operational controls appropriate for that threat model.
 */
final class RetentionDriver implements StorageDriver {

	private string $disk;
	private string $manifest;
	private ?int $defaultRetainFor;
	private bool $defaultLegalHold;

	/**
	 * Creates a retention wrapper around a target storage disk.
	 *
	 * `disk` or `target` identifies the disk that receives real file operations.
	 * `manifest` controls where retention records are persisted. Optional
	 * `retain_for` or `default_retain_for` values define a default duration in
	 * seconds or strtotime-compatible text; `legal_hold` enables a default lock
	 * that must be explicitly released.
	 *
	 * @param array<string,mixed> $config Retention driver configuration.
	 * @param ?StorageManager $manager Storage manager used to access the target disk.
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-retention.json');
		$this->defaultRetainFor=$this->durationSeconds($config['retain_for'] ?? $config['default_retain_for'] ?? null);
		$this->defaultLegalHold=(bool)($config['legal_hold'] ?? $config['default_legal_hold'] ?? false);
		if($this->disk===''){
			throw new \RuntimeException('Retention storage disks require a target disk.');
		}
	}

	/**
	 * Reports whether the target disk currently contains a path.
	 *
	 * Existence checks do not consult retention records because lock state is only
	 * meaningful for objects that still exist on the target disk.
	 *
	 * @param string $path Object path to check on the target disk.
	 * @return bool True when the target disk reports the object exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents from the target disk.
	 *
	 * Retention state does not restrict reads. Any access control or encryption
	 * behavior remains the responsibility of the target disk and storage manager.
	 *
	 * @param string $path Object path to read.
	 * @param array<string,mixed> $options Read options forwarded to the target disk.
	 * @return string|false Object contents, or false when the target disk cannot read the path.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk.
	 *
	 * Retention state does not restrict streaming reads. The returned value shape
	 * is determined by the underlying storage driver.
	 *
	 * @param string $path Object path to stream.
	 * @param array<string,mixed> $options Stream options forwarded to the target disk.
	 * @return mixed stream handle or failure marker returned by the underlying retained disk.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes an object unless the current path is under retention lock.
	 *
	 * Existing locked objects cannot be overwritten. Successful writes update the
	 * retention record using explicit options or the driver's default retention
	 * configuration. Failed target writes leave the manifest unchanged.
	 *
	 * @param string $path Object path to write.
	 * @param mixed $contents Contents accepted by the target storage driver.
	 * @param array<string,mixed> $options Write and retention options.
	 * @return bool True when the target write succeeds and retention metadata is recorded.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		if($this->exists($path) && $this->locked($path)){
			return false;
		}
		if($this->manager->put($path, $contents, $this->disk, $options)!==true){
			return false;
		}
		$this->record($path, $options);
		return true;
	}

	/**
	 * Deletes an object unless its retention record is currently locked.
	 *
	 * The manifest record is removed before the target delete is attempted. If the
	 * target delete fails, the retention record is still gone, so callers that need
	 * stronger transactional behavior must handle that at a higher layer.
	 *
	 * @param string $path Object path to delete.
	 * @return bool True when the target disk reports successful deletion.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		if($this->locked($path)){
			return false;
		}
		$records=$this->records();
		unset($records[$path]);
		$this->writeRecords($records);
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Returns target metadata with retention details attached.
	 *
	 * The target disk provides size, modification time, and MIME type. This driver
	 * adds a `retention` entry to the metadata extras containing the manifest
	 * record and current lock evaluation.
	 *
	 * @param string $path Object path to describe.
	 * @return FileMetadata|false Metadata enriched with retention state, or false when the target has no metadata.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$extra=$metadata->extra();
		$extra['retention']=$this->retentionRecord($path);
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the target disk.
	 *
	 * Retention records are not merged into list output. Use `metadata()` for
	 * per-object retention details or `retentionReport()` for aggregate lock state.
	 *
	 * @param string $prefix Prefix passed to the target disk list operation.
	 * @param array<string,mixed> $options List options forwarded to the target disk.
	 * @return array<int|string,mixed> Target disk listing entries.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL through the target disk.
	 *
	 * Retention state does not prevent URL generation; authorization and URL
	 * signing behavior are delegated to the underlying storage driver.
	 *
	 * @param string $path Object path for the temporary URL.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string,mixed> $options URL options forwarded to the target disk.
	 * @return string|false Temporary URL, or false when the target disk cannot create one.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Adds or updates retention metadata for an existing object.
	 *
	 * The object must exist on the target disk. Existing manifest fields are
	 * preserved unless the provided options replace retention time or legal-hold
	 * state.
	 *
	 * @param string $path Object path whose retention state should change.
	 * @param array<string,mixed> $options Retention options such as `retain_until`, `retain_for`, or `legal_hold`.
	 * @return bool True when the manifest was updated for an existing object.
	 */
	public function setRetention(string $path, array $options=[]): bool {
		$path=Path::normalize($path);
		if(!$this->exists($path)){
			return false;
		}
		$this->record($path, $options, true);
		return true;
	}

	/**
	 * Releases retention constraints recorded for an object.
	 *
	 * By default the retain-until timestamp is cleared. Legal hold remains until
	 * `release_legal_hold` is true. The object itself is not deleted or modified;
	 * only manifest state changes.
	 *
	 * @param string $path Object path whose retention state should be released.
	 * @param array<string,mixed> $options Release flags such as `release_legal_hold` and `release_retain_until`.
	 * @return bool True when no record exists or the updated manifest was written.
	 */
	public function releaseRetention(string $path, array $options=[]): bool {
		$path=Path::normalize($path);
		$records=$this->records();
		if(!isset($records[$path])){
			return true;
		}
		if(($options['release_legal_hold'] ?? false)===true){
			$records[$path]['legal_hold']=false;
		}
		if(($options['release_retain_until'] ?? true)===true){
			$records[$path]['retain_until']=null;
		}
		$records[$path]['released_at']=time();
		return $this->writeRecords($records);
	}

	/**
	 * Summarizes retention records under an optional prefix.
	 *
	 * The report is derived only from the manifest. It does not verify that every
	 * recorded object still exists on the target disk, making it a retention-state
	 * diagnostic rather than a storage inventory.
	 *
	 * @param string $prefix Optional normalized object prefix to filter records.
	 * @param array<string,mixed> $options Reserved for future report options.
	 * @return array<string,int|bool> Aggregate retention-state report.
	 */
	public function retentionReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$total=0;
		$locked=0;
		$legalHolds=0;
		$expired=0;
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$total++;
			$isLocked=$this->recordLocked($record);
			if($isLocked){
				$locked++;
			}
			if(($record['legal_hold'] ?? false)===true){
				$legalHolds++;
			}
			if(!$isLocked && ($record['retain_until'] ?? null)!==null && (int)$record['retain_until']<time()){
				$expired++;
			}
		}
		return [
			'ok'=>true,
			'objects'=>$total,
			'locked'=>$locked,
			'unlocked'=>$total-$locked,
			'legal_holds'=>$legalHolds,
			'expired'=>$expired,
		];
	}

	/**
	 * Writes or merges a manifest record for a path.
	 *
	 * A merged record keeps existing creation time and retained fields unless the
	 * new options explicitly replace them. Non-merged writes create a fresh record
	 * after a successful object write.
	 *
	 * @param string $path Normalized object path.
	 * @param array<string,mixed> $options Retention options from write or setRetention.
	 * @param bool $merge True when existing record state should be preserved.
	 * @return void
	 */
	private function record(string $path, array $options=[], bool $merge=false): void {
		$records=$this->records();
		$current=$merge && is_array($records[$path] ?? null) ? $records[$path] : [];
		$retainUntil=$this->retentionUntil($options, $current);
		$records[$path]=array_merge($current, [
			'path'=>$path,
			'retain_until'=>$retainUntil,
			'legal_hold'=>(bool)($options['legal_hold'] ?? $current['legal_hold'] ?? $this->defaultLegalHold),
			'updated_at'=>time(),
		]);
		if(!isset($records[$path]['created_at'])){
			$records[$path]['created_at']=time();
		}
		$this->writeRecords($records);
	}

	/**
	 * Returns a manifest record augmented with current lock state.
	 *
	 * Missing or malformed records are treated as empty unlocked records.
	 *
	 * @param string $path Normalized object path.
	 * @return array<string,mixed> Retention record with a `locked` boolean.
	 */
	private function retentionRecord(string $path): array {
		$record=$this->records()[$path] ?? [];
		$record=is_array($record) ? $record : [];
		$record['locked']=$this->recordLocked($record);
		return $record;
	}

	/**
	 * Reports whether a normalized path is currently locked by retention state.
	 *
	 * @param string $path Normalized object path.
	 * @return bool True when the manifest record has legal hold or a future retain-until timestamp.
	 */
	private function locked(string $path): bool {
		$record=$this->records()[$path] ?? null;
		return is_array($record) && $this->recordLocked($record);
	}

	/**
	 * Evaluates whether a manifest record currently blocks writes and deletes.
	 *
	 * Legal hold always locks. Retain-until locks only while the timestamp is in
	 * the future; expired timestamps are reported separately by `retentionReport()`.
	 *
	 * @param array<string,mixed> $record Retention manifest record.
	 * @return bool True when the record currently prevents mutation.
	 */
	private function recordLocked(array $record): bool {
		if(($record['legal_hold'] ?? false)===true){
			return true;
		}
		$retainUntil=$record['retain_until'] ?? null;
		return $retainUntil!==null && (int)$retainUntil>time();
	}

	/**
	 * Resolves the retain-until timestamp for a record update.
	 *
	 * Explicit `retain_until` wins, then explicit `retain_for`, then an existing
	 * record value during merges, then the driver's default duration. Null means
	 * the record has no time-based lock.
	 *
	 * @param array<string,mixed> $options Retention options for the update.
	 * @param array<string,mixed> $current Existing manifest record when merging.
	 * @return ?int Unix timestamp until which the object should remain locked.
	 */
	private function retentionUntil(array $options, array $current=[]): ?int {
		$until=$this->timeValue($options['retain_until'] ?? null);
		if($until!==null){
			return $until;
		}
		$duration=$this->durationSeconds($options['retain_for'] ?? null);
		if($duration!==null){
			return time()+$duration;
		}
		if(array_key_exists('retain_until', $current)){
			return $current['retain_until'] !== null ? (int)$current['retain_until'] : null;
		}
		return $this->defaultRetainFor!==null ? time()+$this->defaultRetainFor : null;
	}

	/**
	 * Converts a timestamp-like value into a Unix timestamp.
	 *
	 * DateTime values, integers, and strtotime-compatible strings are accepted.
	 * Blank and unparseable values return null.
	 *
	 * @param mixed $value Candidate retain-until value.
	 * @return ?int Unix timestamp or null when no valid time is supplied.
	 */
	private function timeValue(mixed $value): ?int {
		if($value===null || $value===''){
			return null;
		}
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		if(is_int($value)){
			return $value;
		}
		if(is_string($value)){
			$time=strtotime($value);
			return $time!==false ? $time : null;
		}
		return null;
	}

	/**
	 * Converts a duration-like value into seconds.
	 *
	 * Integers and numeric strings are treated as seconds. Non-numeric strings are
	 * parsed as relative durations by prefixing them with `+` for `strtotime()`.
	 * Negative durations are clamped to zero.
	 *
	 * @param mixed $value Candidate duration value.
	 * @return ?int Duration in seconds or null when no valid duration is supplied.
	 */
	private function durationSeconds(mixed $value): ?int {
		if($value===null || $value===''){
			return null;
		}
		if(is_int($value)){
			return max(0, $value);
		}
		if(is_numeric($value)){
			return max(0, (int)$value);
		}
		if(is_string($value)){
			$time=strtotime('+'.$value);
			return $time!==false ? max(0, $time-time()) : null;
		}
		return null;
	}

	/**
	 * Reads all retention manifest records.
	 *
	 * Missing manifests and invalid JSON are treated as empty state so storage
	 * operations can continue without fatal recovery work.
	 *
	 * @return array<string,array<string,mixed>> Manifest records keyed by normalized object path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists retention manifest records to disk.
	 *
	 * Records are key-sorted for stable diffs and written with an exclusive file
	 * lock. The manifest directory is created on demand when possible.
	 *
	 * @param array<string,array<string,mixed>> $records
	 * Manifest records keyed by normalized object path.
	 * @return bool True when the JSON manifest was written.
	 */
	private function writeRecords(array $records): bool {
		ksort($records);
		$dir=dirname($this->manifest);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($this->manifest, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}
}
