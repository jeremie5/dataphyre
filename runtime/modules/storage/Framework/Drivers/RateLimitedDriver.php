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

/**
 * Decorates a storage disk with per-action rate limiting backed by a local state file.
 *
 * RateLimitedDriver checks a configured bucket before delegating each storage operation to
 * StorageManager. Allowed operations are forwarded to the target disk; denied operations return
 * the StorageDriver failure shape for that method without touching the downstream disk. Counters
 * are persisted as JSON so limits survive across requests within the configured state window.
 */
final class RateLimitedDriver implements StorageDriver {

	private string $disk;
	private string $state;
	/** @var array<string, array{limit:int, window:int}> */
	private array $limits;

	/**
	 * Initializes rate-limit state, action buckets, and delegated target storage.
	 *
	 * The disk can be supplied as disk or target. The state path can be supplied as state or
	 * manifest, and defaults to the system temp directory. Limits may target individual actions
	 * or "*" as a fallback. A missing target disk is a configuration error because this driver
	 * only decorates another disk.
	 *
	 * @param array<string,mixed> $config Rate-limited disk configuration.
	 * @param ?StorageManager $manager Optional storage manager; defaults to the shared instance.
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->state=(string)($config['state'] ?? $config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-ratelimit.json');
		$this->limits=$this->normalizeLimits($config['limits'] ?? []);
		if($this->disk===''){
			throw new \RuntimeException('Rate-limited storage disks require a target disk.');
		}
	}

	/**
	 * Calculates exists for the current Storage Framework selection.
	 *
	 * The read bucket is charged before the existence check is delegated. When the bucket is
	 * exhausted, the method returns false without consulting the target disk.
	 *
	 * @param string $path Storage path to check.
	 * @return bool True when allowed and the target disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->allowed('read', $path) && $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents when the read bucket allows it.
	 *
	 * @param string $path Storage path to read.
	 * @param array<string,mixed> $options Read options and optional rate_limit_key or actor bucket identity.
	 * @return string|false File contents from the target disk, or false when denied or delegated read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->allowed('read', $path, $options) ? $this->manager->get($path, $this->disk, $options) : false;
	}

	/**
	 * Opens a read stream when the read bucket allows it.
	 *
	 * @param string $path Storage path to stream.
	 * @param array<string,mixed> $options Stream options and optional bucket identity.
	 * @return mixed Target disk stream resource/handle, or false when denied or unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->allowed('read', $path, $options) ? $this->manager->readStream($path, $this->disk, $options) : false;
	}

	/**
	 * Writes object contents when the write bucket allows it.
	 *
	 * @param string $path Storage path to write.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array<string,mixed> $options Write options and optional bucket identity.
	 * @return bool True when allowed and the target disk stores the contents.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		return $this->allowed('write', $path, $options) ? $this->manager->put($path, $contents, $this->disk, $options) : false;
	}

	/**
	 * Deletes an object when the delete bucket allows it.
	 *
	 * @param string $path Storage path to delete.
	 * @return bool True when allowed and the target disk deletes the path.
	 */
	public function delete(string $path): bool {
		return $this->allowed('delete', $path) ? $this->manager->delete($path, $this->disk) : false;
	}

	/**
	 * Reads file metadata when the metadata bucket allows it.
	 *
	 * Metadata requests are rate-limited separately from content reads so callers can configure
	 * cheaper or stricter inspection budgets.
	 *
	 * @param string $path Storage path to inspect.
	 * @return FileMetadata|false Metadata from the target disk, or false when denied or unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		return $this->allowed('metadata', $path) ? $this->manager->metadata($path, $this->disk) : false;
	}

	/**
	 * Lists objects below a prefix when the list bucket allows it.
	 *
	 * @param string $prefix Storage prefix to list.
	 * @param array<string,mixed> $options List options and optional bucket identity.
	 * @return array<int|string,mixed> Target disk listing, or an empty list when denied.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->allowed('list', $prefix, $options) ? $this->manager->list($prefix, $this->disk, $options) : [];
	}

	/**
	 * Creates a temporary URL when the temporary_url bucket allows it.
	 *
	 * @param string $path Storage path for the signed URL.
	 * @param int|\DateTimeInterface $expires Expiration time or timestamp accepted by the manager.
	 * @param array<string,mixed> $options URL options and optional bucket identity.
	 * @return string|false Temporary URL, or false when denied or unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->allowed('temporary_url', $path, $options) ? $this->manager->temporaryUrl($path, $expires, $this->disk, $options) : false;
	}

	/**
	 * Reports configured limits and current bucket state.
	 *
	 * Prefix and options are accepted for driver-interface symmetry but do not filter the report.
	 * The returned bucket map is the raw persisted state so diagnostics can inspect reset times
	 * and counts.
	 *
	 * @param string $prefix Unused report scope placeholder.
	 * @param array<string,mixed> $options Unused report options placeholder.
	 * @return array<string,mixed> Current rate-limit report.
	 */
	public function rateLimitReport(string $prefix='', array $options=[]): array {
		$state=$this->state();
		return [
			'ok'=>true,
			'disk'=>$this->disk,
			'limits'=>$this->limits,
			'buckets'=>$state,
		];
	}

	/**
	 * Clears all persisted rate-limit buckets.
	 *
	 * Reset is global for this driver state file. Prefix and options are accepted for
	 * driver-interface symmetry but are not used to scope the reset.
	 *
	 * @param string $prefix Unused reset scope placeholder.
	 * @param array<string,mixed> $options Unused reset options placeholder.
	 * @return array<string,bool> Reset acknowledgement.
	 */
	public function resetRateLimits(string $prefix='', array $options=[]): array {
		$this->writeState([]);
		return ['ok'=>true, 'reset'=>true];
	}

	/**
	 * Charges one bucket for a storage action and reports whether it may proceed.
	 *
	 * Missing limits allow the action. Expired buckets reset before counting. Exhausted buckets
	 * are persisted unchanged and block downstream delegation.
	 *
	 * @param string $action Storage action name such as read, write, delete, list, metadata, or temporary_url.
	 * @param string $path Storage path or prefix being accessed.
	 * @param array<string,mixed> $options Operation options containing optional rate_limit_key or actor.
	 * @return bool True when the action is allowed.
	 */
	private function allowed(string $action, string $path, array $options=[]): bool {
		$limit=$this->limits[$action] ?? $this->limits['*'] ?? null;
		if($limit===null){
			return true;
		}
		$key=$this->bucketKey($action, $options);
		$state=$this->state();
		$now=time();
		$bucket=$state[$key] ?? ['count'=>0, 'reset_at'=>$now+$limit['window']];
		if((int)($bucket['reset_at'] ?? 0)<=$now){
			$bucket=['count'=>0, 'reset_at'=>$now+$limit['window']];
		}
		if((int)$bucket['count']>=$limit['limit']){
			$state[$key]=$bucket;
			$this->writeState($state);
			return false;
		}
		$bucket['count']=(int)$bucket['count']+1;
		$state[$key]=$bucket;
		$this->writeState($state);
		return true;
	}

	/**
	 * Builds the persisted bucket key for an action and caller identity.
	 *
	 * Identity precedence is rate_limit_key, then actor, then REMOTE_ADDR, then global. The
	 * storage path is not part of the bucket, so limits apply per action and identity across the
	 * decorated disk.
	 *
	 * @param string $action Storage action name.
	 * @param array<string,mixed> $options Operation options.
	 * @return string Bucket key stored in the state file.
	 */
	private function bucketKey(string $action, array $options): string {
		$identity=(string)($options['rate_limit_key'] ?? $options['actor'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'global'));
		return $action.':'.$identity;
	}

	/**
	 * Normalizes configured rate-limit rules.
	 *
	 * Each rule becomes an action key with a positive limit and positive window in seconds.
	 * Numeric-keyed entries may declare their action in the rule data, and invalid entries are
	 * ignored.
	 *
	 * @param mixed $limits Raw limits configuration.
	 * @return array<string,array{limit:int,window:int}> Normalized action limits.
	 */
	private function normalizeLimits(mixed $limits): array {
		if(!is_array($limits)){
			return [];
		}
		$out=[];
		foreach($limits as $action=>$limit){
			if(is_int($action) && is_array($limit) && isset($limit['action'])){
				$action=(string)$limit['action'];
			}
			if(is_int($action) || !is_array($limit)){
				continue;
			}
			$out[(string)$action]=[
				'limit'=>max(1, (int)($limit['limit'] ?? $limit['max'] ?? 60)),
				'window'=>max(1, $this->durationSeconds($limit['window'] ?? $limit['per'] ?? 60)),
			];
		}
		return $out;
	}

	/**
	 * Converts a numeric or natural-language duration into seconds.
	 *
	 * Strings are parsed as relative future durations such as "1 minute". Unparseable values
	 * fall back to sixty seconds.
	 *
	 * @param mixed $value Raw duration value.
	 * @return int Positive duration in seconds.
	 */
	private function durationSeconds(mixed $value): int {
		if(is_int($value) || is_numeric($value)){
			return (int)$value;
		}
		if(is_string($value)){
			$time=strtotime('+'.$value);
			if($time!==false){
				return max(1, $time-time());
			}
		}
		return 60;
	}

	/**
	 * Loads the persisted bucket state from disk.
	 *
	 * Missing or invalid JSON state files are treated as empty state rather than failing storage
	 * operations.
	 *
	 * @return array<string,mixed> Persisted bucket map.
	 */
	private function state(): array {
		if(!is_file($this->state)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->state), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists the bucket state as pretty JSON with an exclusive write lock.
	 *
	 * The containing directory is created when missing. A false return means the limiter could not
	 * persist state, but callers currently continue based on the in-memory decision already made.
	 *
	 * @param array<string,mixed> $state Bucket state to persist.
	 * @return bool True when the state file was written.
	 */
	private function writeState(array $state): bool {
		$dir=dirname($this->state);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($this->state, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}
}
