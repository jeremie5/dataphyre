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
 * Decorates a storage disk with manifest-backed lifecycle retention rules.
 *
 * LifecycleDriver delegates normal storage operations to a target disk while recording write
 * metadata in a local JSON manifest. Lifecycle reports evaluate configured delete-after rules
 * against those records; lifecycle application deletes eligible objects from the target disk and
 * removes their manifest entries.
 */
final class LifecycleDriver implements StorageDriver {

	private string $disk;
	private string $manifest;
	/** @var list<array> */
	private array $rules;

	/**
	 * Initializes lifecycle manifest tracking and delete-after rules for a delegated disk.
	 *
	 * The target disk is supplied as disk or target. The manifest path defaults to the system
	 * temp directory. Rules can be supplied as an array, or a top-level delete_after value can
	 * create a single default rule. A missing target disk is invalid because this driver only
	 * decorates another disk.
	 *
	 * @param array<string,mixed> $config Lifecycle disk configuration.
	 * @param ?StorageManager $manager Optional storage manager; defaults to the shared instance.
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-lifecycle.json');
		$this->rules=$this->normalizeRules($config['rules'] ?? []);
		if($this->rules===[] && isset($config['delete_after'])){
			$this->rules[]=['prefix'=>'', 'delete_after'=>$config['delete_after']];
		}
		if($this->disk===''){
			throw new \RuntimeException('Lifecycle storage disks require a target disk.');
		}
	}

	/**
	 * Calculates exists for the current Storage Framework selection.
	 *
	 * Existence checks are delegated directly and do not read or update the lifecycle manifest.
	 *
	 * @param string $path Storage path to check.
	 * @return bool True when the target disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents from the target disk.
	 *
	 * @param string $path Storage path to read.
	 * @param array<string,mixed> $options Read options forwarded to the storage manager.
	 * @return string|false File contents, or false when the target disk cannot read the path.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk.
	 *
	 * @param string $path Storage path to stream.
	 * @param array<string,mixed> $options Stream options forwarded to the storage manager.
	 * @return mixed Target disk stream resource/handle, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes contents to the target disk and records lifecycle metadata.
	 *
	 * The normalized path is written first. On success, metadata is read back and the manifest
	 * record is updated with created_at, updated_at, size, and MIME type. Manifest persistence
	 * failure makes the write report false even though the target disk write may have succeeded.
	 *
	 * @param string $path Storage path to write.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array<string,mixed> $options Write options forwarded to the storage manager.
	 * @return bool True when target write and manifest update both succeed.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		if($this->manager->put($path, $contents, $this->disk, $options)!==true){
			return false;
		}
		$metadata=$this->manager->metadata($path, $this->disk);
		$records=$this->records();
		$records[$path]=[
			'path'=>$path,
			'created_at'=>$records[$path]['created_at'] ?? time(),
			'updated_at'=>time(),
			'size'=>$metadata instanceof FileMetadata ? $metadata->size() : null,
			'mime_type'=>$metadata instanceof FileMetadata ? $metadata->mimeType() : null,
		];
		return $this->writeRecords($records);
	}

	/**
	 * Deletes an object and removes its lifecycle manifest record.
	 *
	 * The manifest is updated before delegating deletion to the target disk. A manifest write
	 * failure is ignored here so explicit deletes still attempt to remove the object.
	 *
	 * @param string $path Storage path to delete.
	 * @return bool True when the target disk deletes the path.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		$records=$this->records();
		unset($records[$path]);
		$this->writeRecords($records);
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Loads file metadata and attaches lifecycle manifest data.
	 *
	 * Metadata from the target disk is wrapped with an extra lifecycle key containing the manifest
	 * record for the path, when one exists. Missing target metadata returns false.
	 *
	 * @param string $path Storage path to inspect.
	 * @return FileMetadata|false Metadata enriched with lifecycle record data, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$extra=$metadata->extra();
		$extra['lifecycle']=$this->recordFor($path) ?? [];
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the target disk.
	 *
	 * @param string $prefix Storage prefix to list.
	 * @param array<string,mixed> $options List options forwarded to the storage manager.
	 * @return array<int|string,mixed> Target disk listing.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL through the target disk.
	 *
	 * @param string $path Storage path for the signed URL.
	 * @param int|\DateTimeInterface $expires Expiration time or timestamp accepted by the manager.
	 * @param array<string,mixed> $options URL options forwarded to the storage manager.
	 * @return string|false Temporary URL, or false when unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Reports lifecycle candidates without deleting objects.
	 *
	 * The report evaluates manifest records against lifecycle rules and returns eligible paths
	 * under the optional prefix. It is always a dry run.
	 *
	 * @param string $prefix Optional storage prefix used to filter manifest records.
	 * @param array<string,mixed> $options Evaluation options.
	 * @return array<string,mixed> Lifecycle dry-run report.
	 */
	public function lifecycleReport(string $prefix='', array $options=[]): array {
		return $this->evaluate($prefix, true, $options);
	}

	/**
	 * Applies lifecycle deletion rules to eligible manifest records.
	 *
	 * By default this deletes eligible target-disk objects and removes their records. Passing
	 * dry_run=true in options returns the same report shape without deletion.
	 *
	 * @param string $prefix Optional storage prefix used to filter manifest records.
	 * @param array<string,mixed> $options Lifecycle options, including optional dry_run.
	 * @return array<string,mixed> Lifecycle application report.
	 */
	public function applyLifecycle(string $prefix='', array $options=[]): array {
		return $this->evaluate($prefix, (bool)($options['dry_run'] ?? false), $options);
	}

	/**
	 * Evaluates lifecycle rules and optionally deletes eligible objects.
	 *
	 * Candidate paths come only from the manifest, not from a live disk scan. Dry runs return
	 * eligible paths; apply mode deletes each candidate, removes successful deletions from the
	 * manifest, and reports whether every candidate was deleted.
	 *
	 * @param string $prefix Optional normalized prefix filter.
	 * @param bool $dryRun True to report only, false to delete eligible objects.
	 * @param array<string,mixed> $options Evaluation options reserved for future lifecycle policies.
	 * @return array<string,mixed> Lifecycle evaluation report.
	 */
	private function evaluate(string $prefix='', bool $dryRun=true, array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$candidates=[];
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$rule=$this->matchingRule($path, $record);
			if($rule===null){
				continue;
			}
			$candidates[]=['path'=>$path, 'rule'=>$rule, 'record'=>$record];
		}
		if($dryRun){
			return ['ok'=>true, 'dry_run'=>true, 'eligible'=>count($candidates), 'deleted'=>0, 'paths'=>array_column($candidates, 'path')];
		}
		$deleted=[];
		foreach($candidates as $candidate){
			$path=(string)$candidate['path'];
			if($this->manager->delete($path, $this->disk)){
				unset($records[$path]);
				$deleted[]=$path;
			}
		}
		$this->writeRecords($records);
		return ['ok'=>count($deleted)===count($candidates), 'dry_run'=>false, 'eligible'=>count($candidates), 'deleted'=>count($deleted), 'paths'=>$deleted];
	}

	/**
	 * Finds the first lifecycle rule that makes a manifest record eligible.
	 *
	 * Rules may filter by prefix and extension before checking delete_after or max_age against
	 * updated_at, falling back to created_at. The first matching expired rule wins.
	 *
	 * @param string $path Normalized storage path.
	 * @param array<string,mixed> $record Manifest record for the path.
	 * @return ?array<string,mixed> Matching lifecycle rule, or null when the record is not eligible.
	 */
	private function matchingRule(string $path, array $record): ?array {
		foreach($this->rules as $rule){
			$prefix=Path::normalize((string)($rule['prefix'] ?? ''));
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$extensions=(array)($rule['extensions'] ?? []);
			if($extensions!==[]){
				$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
				$allowed=array_map(static fn(mixed $value): string => strtolower(ltrim((string)$value, '.')), $extensions);
				if(!in_array($extension, $allowed, true)){
					continue;
				}
			}
			$deleteAfter=$this->durationSeconds($rule['delete_after'] ?? $rule['max_age'] ?? null);
			if($deleteAfter===null){
				continue;
			}
			$basis=(int)($record['updated_at'] ?? $record['created_at'] ?? 0);
			if($basis>0 && $basis+$deleteAfter<=time()){
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Keeps only array lifecycle rules from raw configuration.
	 *
	 * Invalid entries are ignored so malformed rules cannot break normal storage operations.
	 *
	 * @param mixed $rules Raw rules configuration.
	 * @return list<array<string,mixed>> Normalized rule list.
	 */
	private function normalizeRules(mixed $rules): array {
		if(!is_array($rules)){
			return [];
		}
		$out=[];
		foreach($rules as $rule){
			if(is_array($rule)){
				$out[]=$rule;
			}
		}
		return $out;
	}

	/**
	 * Converts a numeric or natural-language duration to seconds.
	 *
	 * Null and blank values mean no duration was configured. Strings are parsed as relative future
	 * durations such as "30 days".
	 *
	 * @param mixed $value Raw duration value.
	 * @return ?int Non-negative duration in seconds, or null when unavailable.
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
	 * Reads one normalized manifest record for a path.
	 *
	 * @param string $path Storage path to look up.
	 * @return ?array<string,mixed> Manifest record, or null when absent.
	 */
	private function recordFor(string $path): ?array {
		$record=$this->records()[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Loads all lifecycle manifest records from disk.
	 *
	 * Missing or invalid JSON manifests are treated as empty state so storage operations can
	 * continue.
	 *
	 * @return array<string,mixed> Manifest records keyed by normalized path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists lifecycle manifest records as sorted pretty JSON.
	 *
	 * Sorting keeps diffs deterministic for diagnostics. The containing directory is created when
	 * missing and writes use an exclusive lock.
	 *
	 * @param array<string,mixed> $records Manifest records keyed by normalized path.
	 * @return bool True when the manifest was written.
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
