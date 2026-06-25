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
use Dataphyre\Storage\Support\Stream;

/**
 * Decorates a storage disk with write-time content scanning and quarantine.
 *
 * ScannedDriver treats writes as the security boundary: content is scanned before it reaches
 * the target disk, blocked bytes are written to quarantine storage, and scan evidence is
 * recorded in a local JSON manifest. Reads, listings, metadata, and temporary URLs delegate
 * to the target disk, while metadata and reports expose scan history for diagnostics.
 */
final class ScannedDriver implements StorageDriver {

	private string $disk;
	private string $quarantineDisk;
	private string $quarantinePrefix;
	private string $manifest;
	private string $scannerCommand;
	private bool $requireScanner;
	/** @var list<string> */
	private array $denyPatterns;

	/**
	 * Initializes write scanning, quarantine storage, deny patterns, and scan manifest persistence.
	 *
	 * The target disk is required. Quarantine storage defaults to the target disk but uses a
	 * separate prefix. Scanning can be performed by deny patterns, an external scanner command,
	 * or both. When require_scanner is true, missing scanner_command blocks writes instead of
	 * allowing them.
	 *
	 * @param array<string,mixed> $config Scanned disk configuration.
	 * @param ?StorageManager $manager Optional storage manager; defaults to the shared instance.
	 * @throws \RuntimeException When target or quarantine storage is not configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->quarantineDisk=(string)($config['quarantine_disk'] ?? $config['quarantine'] ?? $this->disk);
		$this->quarantinePrefix=Path::normalize((string)($config['quarantine_prefix'] ?? '_dataphyre_quarantine'));
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-scan.json');
		$this->scannerCommand=(string)($config['scanner_command'] ?? '');
		$this->requireScanner=(bool)($config['require_scanner'] ?? false);
		$this->denyPatterns=array_values(array_filter(array_map('strval', (array)($config['deny_patterns'] ?? []))));
		if($this->disk===''){
			throw new \RuntimeException('Scanned storage disks require a target disk.');
		}
		if($this->quarantineDisk==='' || $this->quarantinePrefix===''){
			throw new \RuntimeException('Scanned storage disks require quarantine storage.');
		}
	}

	/**
	 * Calculates exists for the current Storage Framework selection.
	 *
	 * Existence checks only inspect the target disk. Quarantined objects are intentionally
	 * invisible to normal storage reads.
	 *
	 * @param string $path Storage path to check.
	 * @return bool True when the target disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads stored content from the target disk.
	 *
	 * This method does not read quarantine storage. Content must have passed scanning and been
	 * written to the target disk before it can be read through this driver.
	 *
	 * @param string $path Storage path to read.
	 * @param array<string,mixed> $options Read options forwarded to the target disk.
	 * @return string|false File contents, or false when unavailable.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk.
	 *
	 * @param string $path Storage path to stream.
	 * @param array<string,mixed> $options Stream options forwarded to the target disk.
	 * @return mixed Target disk stream resource/handle, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Scans content before writing it to the target disk.
	 *
	 * Resource contents are read into memory for scanning. Clean content is written to the
	 * target disk and recorded with clean status. Blocked content is written to quarantine
	 * storage, recorded as quarantined, and the write returns false so callers cannot treat
	 * the object as stored.
	 *
	 * @param string $path Target storage path.
	 * @param mixed $contents Stringable contents or readable resource to scan.
	 * @param array<string,mixed> $options Write options forwarded to target or quarantine storage.
	 * @return bool True when content passes scanning and is stored on the target disk.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($path==='' || $body===false){
			return false;
		}
		$result=$this->scan($path, $body);
		if(($result['ok'] ?? false)!==true){
			$this->quarantine($path, $body, $result, $options);
			return false;
		}
		if($this->manager->put($path, $body, $this->disk, $options)!==true){
			return false;
		}
		$this->record($path, array_merge($result, [
			'status'=>'clean',
			'size'=>strlen($body),
			'scanned_at'=>time(),
		]));
		return true;
	}

	/**
	 * Deletes a target object and removes its scan record.
	 *
	 * Deleting a clean target object does not delete quarantined copies for the same original
	 * path; purgeQuarantine() owns quarantine cleanup.
	 *
	 * @param string $path Target storage path.
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
	 * Loads target metadata and attaches scan evidence.
	 *
	 * The metadata extras receive a scan key containing the manifest record for the path,
	 * when available. Missing target metadata returns false even when a quarantined copy exists.
	 *
	 * @param string $path Target storage path.
	 * @return FileMetadata|false Metadata enriched with scan record data, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$extra=$metadata->extra();
		$extra['scan']=$this->recordFor($path) ?? [];
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the target disk.
	 *
	 * Quarantine storage is not included in normal listings.
	 *
	 * @param string $prefix Target prefix to list.
	 * @param array<string,mixed> $options List options forwarded to the target disk.
	 * @return array<int|string,mixed> Target disk listing.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL for a target object.
	 *
	 * URLs are only generated for target-disk paths and never expose quarantine storage.
	 *
	 * @param string $path Target path for the signed URL.
	 * @param int|\DateTimeInterface $expires Expiration time or timestamp accepted by the manager.
	 * @param array<string,mixed> $options URL options forwarded to the target disk.
	 * @return string|false Temporary URL, or false when unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Reports scan manifest counts under an optional prefix.
	 *
	 * The report is manifest-backed and counts clean versus blocked/quarantined records. It does
	 * not rescan target storage or quarantine storage.
	 *
	 * @param string $prefix Optional original path prefix used to filter scan records.
	 * @param array<string,mixed> $options Reserved report options.
	 * @return array{ok:bool,objects:int,clean:int,blocked:int} Scan summary.
	 */
	public function scanReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$total=0;
		$clean=0;
		$blocked=0;
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$total++;
			($record['status'] ?? '')==='clean' ? $clean++ : $blocked++;
		}
		return ['ok'=>true, 'objects'=>$total, 'clean'=>$clean, 'blocked'=>$blocked];
	}

	/**
	 * Deletes quarantined objects and removes their manifest records.
	 *
	 * Purging only touches records with quarantined status. Clean target objects and their records are left intact.
	 *
	 * @param string $prefix Optional original path prefix used to filter quarantined records.
	 * @param array<string,mixed> $options Reserved purge options.
	 * @return array{ok:bool,purged:int} Purge acknowledgement and quarantine deletion count.
	 */
	public function purgeQuarantine(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$purged=0;
		foreach($records as $path=>$record){
			if(!is_array($record) || ($record['status'] ?? '')!=='quarantined'){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$quarantinePath=(string)($record['quarantine_path'] ?? '');
			if($quarantinePath!=='' && $this->manager->delete($quarantinePath, $this->quarantineDisk)){
				$purged++;
			}
			unset($records[$path]);
		}
		$this->writeRecords($records);
		return ['ok'=>true, 'purged'=>$purged];
	}

	/**
	 * Scans one content body using deny patterns and an optional external command.
	 *
	 * Deny patterns can be regular expressions or plain substrings. External scanner commands
	 * receive a temporary file path either through a {file} placeholder or as the final argument.
	 * A non-zero scanner exit code blocks the write.
	 *
	 * @param string $path Target storage path being scanned.
	 * @param string $body Content bytes to inspect.
	 * @return array<string,mixed> Scan result with ok/status/reason/scanner details.
	 */
	private function scan(string $path, string $body): array {
		foreach($this->denyPatterns as $pattern){
			if($pattern!=='' && @preg_match($pattern, '')!==false && preg_match($pattern, $body)===1){
				return ['ok'=>false, 'status'=>'blocked', 'reason'=>'deny_pattern', 'pattern'=>$pattern];
			}
			if($pattern!=='' && str_contains($body, $pattern)){
				return ['ok'=>false, 'status'=>'blocked', 'reason'=>'deny_pattern', 'pattern'=>$pattern];
			}
		}
		if($this->scannerCommand===''){
			return $this->requireScanner
				? ['ok'=>false, 'status'=>'blocked', 'reason'=>'scanner_unavailable']
				: ['ok'=>true, 'scanner'=>'none'];
		}
		$tmp=tempnam(sys_get_temp_dir(), 'dp-scan-');
		if($tmp===false || file_put_contents($tmp, $body)===false){
			return ['ok'=>false, 'status'=>'blocked', 'reason'=>'scan_temp_failed'];
		}
		$command=str_contains($this->scannerCommand, '{file}')
			? str_replace('{file}', escapeshellarg($tmp), $this->scannerCommand)
			: $this->scannerCommand.' '.escapeshellarg($tmp);
		$output=[];
		$code=0;
		@exec($command, $output, $code);
		@unlink($tmp);
		return [
			'ok'=>$code===0,
			'status'=>$code===0 ? 'clean' : 'blocked',
			'scanner'=>'command',
			'exit_code'=>$code,
			'output'=>implode("\n", array_slice($output, 0, 20)),
		];
	}

	/**
	 * Stores blocked content in quarantine storage and records scan evidence.
	 *
	 * Quarantine write failures are not surfaced directly to the caller; the public write has
	 * already failed because scanning did not pass. Manifest persistence is still attempted so
	 * diagnostics can record the blocked decision.
	 *
	 * @param string $path Original target storage path.
	 * @param string $body Blocked content bytes.
	 * @param array<string,mixed> $result Scan result that caused quarantine.
	 * @param array<string,mixed> $options Write options forwarded to quarantine storage.
	 * @return void Quarantine side effects and manifest record are attempted in place.
	 */
	private function quarantine(string $path, string $body, array $result, array $options=[]): void {
		$quarantinePath=$this->quarantinePath($path);
		$this->manager->put($quarantinePath, $body, $this->quarantineDisk, $options);
		$this->record($path, array_merge($result, [
			'status'=>'quarantined',
			'size'=>strlen($body),
			'quarantine_path'=>$quarantinePath,
			'scanned_at'=>time(),
		]));
	}

	/**
	 * Builds a unique quarantine storage path for blocked content.
	 *
	 * The path includes the configured quarantine prefix, a timestamp, random bytes, and the
	 * original path to preserve operator context while avoiding collisions.
	 *
	 * @param string $path Original target storage path.
	 * @return string Quarantine storage path.
	 */
	private function quarantinePath(string $path): string {
		return Path::normalize($this->quarantinePrefix.'/'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'/'.$path);
	}

	/**
	 * Writes or replaces one scan manifest record.
	 *
	 * @param string $path Original target storage path.
	 * @param array<string,mixed> $record Scan record fields to merge with the normalized path.
	 * @return bool True when the manifest was written.
	 */
	private function record(string $path, array $record): bool {
		$records=$this->records();
		$records[Path::normalize($path)]=array_merge(['path'=>Path::normalize($path)], $record);
		return $this->writeRecords($records);
	}

	/**
	 * Looks up one scan manifest record by normalized target path.
	 *
	 * @param string $path Target storage path.
	 * @return ?array<string,mixed> Scan record, or null when absent.
	 */
	private function recordFor(string $path): ?array {
		$record=$this->records()[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Loads scan manifest records from disk.
	 *
	 * Missing or invalid manifests are treated as empty scan state.
	 *
	 * @return array<string,mixed> Scan records keyed by normalized original path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists sorted scan manifest records as pretty JSON.
	 *
	 * Sorting keeps diagnostics deterministic. The containing directory is created when missing
	 * and writes use an exclusive lock.
	 *
	 * @param array<string,mixed> $records Scan records keyed by normalized original path.
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
