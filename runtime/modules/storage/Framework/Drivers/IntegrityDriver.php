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
 * Storage decorator that records and verifies object checksums.
 *
 * The integrity driver delegates all object IO to a configured target disk and
 * maintains a JSON manifest containing path, algorithm, checksum, size, MIME
 * type, and update timestamp for written files. Verification recomputes the
 * target object's checksum and compares it with the manifest using
 * {@see hash_equals()} so diagnostics can detect drift or tampering.
 */
final class IntegrityDriver implements StorageDriver {

	/** @var string Target disk that owns object storage. */
	private string $disk;
	/** @var string Filesystem path to the JSON integrity manifest. */
	private string $manifest;
	/** @var string Default hash algorithm used for new manifest records. */
	private string $algorithm;

	/**
	 * Initializes the integrity decorator and validates its hash algorithm.
	 *
	 * `disk` or `target` identifies the underlying storage disk. `manifest`
	 * controls where checksum records are persisted, and `algorithm` chooses the
	 * default hashing algorithm for new writes. Construction fails early when the
	 * target disk is absent or the configured hash algorithm is not available in
	 * the current PHP runtime.
	 *
	 * @param array<string, mixed> $config Integrity driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 * @throws \RuntimeException When the configured hash algorithm is unavailable.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-integrity.json');
		$this->algorithm=(string)($config['algorithm'] ?? 'sha256');
		if($this->disk===''){
			throw new \RuntimeException('Integrity storage disks require a target disk.');
		}
		if(!in_array($this->algorithm, hash_algos(), true)){
			throw new \RuntimeException("Integrity hash algorithm '{$this->algorithm}' is unavailable.");
		}
	}

	/**
	 * Checks whether an object exists on the target disk.
	 *
	 * The integrity manifest is not consulted; existence reflects only the
	 * delegated storage backend.
	 *
	 * @param string $path Object path to test on the target disk.
	 * @return bool True when the target disk reports the object exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads an object from the target disk without verifying its checksum.
	 *
	 * Call {@see verifyIntegrity()} when callers need evidence that the current
	 * object bytes still match the recorded checksum.
	 *
	 * @param string $path Object path to read.
	 * @param array<string, mixed> $options Target-driver read options.
	 * @return string|false Object contents, or false when the target read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk without verifying its checksum.
	 *
	 * Stream integrity is left to explicit verification because consuming the
	 * stream here would change the caller's IO contract.
	 *
	 * @param string $path Object path to stream.
	 * @param array<string, mixed> $options Target-driver stream options.
	 * @return mixed stream handle or failure marker returned by the target disk driver.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes an object and records a checksum when the write succeeds.
	 *
	 * The target disk write is the source of truth for success. After a
	 * successful write, the driver computes a checksum from the stored object and
	 * updates the manifest. A manifest write failure does not turn the original
	 * object write into a failed storage operation.
	 *
	 * @param string $path Object path to write.
	 * @param mixed $contents Contents accepted by the target driver.
	 * @param array<string, mixed> $options Target-driver write options; `integrity_algorithm` overrides the default hash.
	 * @return bool True when the target disk write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$ok=$this->manager->put($path, $contents, $this->disk, $options);
		if($ok){
			$this->record($path, (string)($options['integrity_algorithm'] ?? $this->algorithm), $options);
		}
		return $ok;
	}

	/**
	 * Deletes an object and removes its integrity record when deletion succeeds.
	 *
	 * The manifest is left untouched when the target disk deletion fails, which
	 * preserves the last known checksum evidence for the still-present object.
	 *
	 * @param string $path Object path to delete.
	 * @return bool True when the target disk deletion succeeds.
	 */
	public function delete(string $path): bool {
		$ok=$this->manager->delete($path, $this->disk);
		if($ok){
			$this->forget($path);
		}
		return $ok;
	}

	/**
	 * Reads target metadata and enriches it with the integrity record when present.
	 *
	 * Metadata remains delegated to the target disk. The returned metadata value
	 * is rebuilt only to append an `integrity` entry to its extra metadata.
	 *
	 * @param string $path Object path whose metadata should be loaded.
	 * @return FileMetadata|false Metadata with integrity extras, or false when the target has no metadata.
	 */
	public function metadata(string $path): FileMetadata|false {
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$record=$this->recordFor($path);
		$extra=$metadata->extra();
		if($record!==null){
			$extra['integrity']=$record;
		}
		return new FileMetadata($metadata->path(), $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the target disk.
	 *
	 * Integrity records are not used as a listing source; this method reflects
	 * the target driver's current object inventory.
	 *
	 * @param string $prefix Target-disk listing prefix.
	 * @param array<string, mixed> $options Target-driver listing options.
	 * @return array<int|string, mixed> Listing entries returned by the target driver.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Generates a temporary URL through the target disk.
	 *
	 * URL generation does not verify object integrity; callers can run
	 * verification before issuing URLs when the access workflow requires it.
	 *
	 * @param string $path Object path to expose temporarily.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object understood by the target driver.
	 * @param array<string, mixed> $options Target-driver URL options.
	 * @return string|false Temporary URL, or false when the target driver cannot create one.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Verifies one object's current checksum against the manifest.
	 *
	 * Missing manifest records and unreadable objects return failure reports
	 * with a diagnostic message. Recorded objects include expected and actual
	 * checksums plus the algorithm and check timestamp.
	 *
	 * @param string $path Object path to verify.
	 * @param array<string, mixed> $options Target-driver checksum options.
	 * @return array<string,mixed> Integrity verification result.
	 */
	public function verifyIntegrity(string $path, array $options=[]): array {
		$path=Path::normalize($path);
		$record=$this->recordFor($path);
		if($record===null){
			return ['ok'=>false, 'path'=>$path, 'message'=>'No integrity record exists.'];
		}
		$algorithm=(string)($record['algorithm'] ?? $this->algorithm);
		$actual=$this->manager->checksum($path, $this->disk, $algorithm, $options);
		if($actual===false){
			return ['ok'=>false, 'path'=>$path, 'message'=>'Unable to read object for integrity verification.'];
		}
		$expected=(string)($record['checksum'] ?? '');
		return [
			'ok'=>hash_equals($expected, $actual),
			'path'=>$path,
			'algorithm'=>$algorithm,
			'expected'=>$expected,
			'actual'=>$actual,
			'checked_at'=>time(),
		];
	}

	/**
	 * Verifies every manifest record under an optional prefix.
	 *
	 * The report iterates manifest records, not target-disk listings. This makes
	 * it a check for known recorded objects; unrecorded files on the target disk
	 * are intentionally outside the report.
	 *
	 * @param string $prefix Optional normalized path prefix used to filter manifest records.
	 * @param array<string, mixed> $options Target-driver checksum options.
	 * @return array<string,mixed> Aggregate integrity report.
	 */
	public function integrityReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$checked=0;
		$passed=0;
		$failed=[];
		foreach($records as $path=>$record){
			if($prefix!=='' && !str_starts_with((string)$path, $prefix)){
				continue;
			}
			$result=$this->verifyIntegrity((string)$path, $options);
			$checked++;
			if(($result['ok'] ?? false)===true){
				$passed++;
			}
			else{
				$failed[]=$result;
			}
		}
		return [
			'ok'=>$failed===[],
			'checked'=>$checked,
			'passed'=>$passed,
			'failed'=>count($failed),
			'failures'=>$failed,
		];
	}

	/**
	 * Computes and stores an integrity record for one object.
	 *
	 * The checksum is computed after the target write so the manifest describes
	 * bytes as persisted by the target driver, including any driver-side encoding
	 * or transformation.
	 *
	 * @param string $path Object path to record.
	 * @param string $algorithm Hash algorithm to use for the checksum.
	 * @param array<string, mixed> $options Target-driver checksum options.
	 * @return bool True when the checksum was computed and the manifest was written.
	 */
	private function record(string $path, string $algorithm, array $options=[]): bool {
		if(!in_array($algorithm, hash_algos(), true)){
			return false;
		}
		$path=Path::normalize($path);
		$checksum=$this->manager->checksum($path, $this->disk, $algorithm, $options);
		if($checksum===false){
			return false;
		}
		$metadata=$this->manager->metadata($path, $this->disk);
		$records=$this->records();
		$records[$path]=[
			'path'=>$path,
			'algorithm'=>$algorithm,
			'checksum'=>$checksum,
			'size'=>$metadata instanceof FileMetadata ? $metadata->size() : null,
			'mime_type'=>$metadata instanceof FileMetadata ? $metadata->mimeType() : null,
			'updated_at'=>time(),
		];
		return $this->writeRecords($records);
	}

	/**
	 * Removes the manifest record for a deleted object.
	 *
	 * @param string $path Object path whose record should be removed.
	 * @return bool True when the updated manifest was written.
	 */
	private function forget(string $path): bool {
		$records=$this->records();
		unset($records[Path::normalize($path)]);
		return $this->writeRecords($records);
	}

	/**
	 * Loads the manifest record for one normalized object path.
	 *
	 * @param string $path Object path to resolve in the manifest.
	 * @return ?array<string, mixed> Integrity record, or null when absent or malformed.
	 */
	private function recordFor(string $path): ?array {
		$records=$this->records();
		$record=$records[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Reads all integrity records from the manifest file.
	 *
	 * Missing or malformed manifests are treated as empty so storage operations
	 * can continue and future writes can rebuild the record set.
	 *
	 * @return array<string, array<string, mixed>> Manifest records keyed by normalized object path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists the complete manifest record map to disk.
	 *
	 * The manifest directory is created on demand and the JSON file is written
	 * with an exclusive lock to avoid partial writes from concurrent requests.
	 *
	 * @param array<string, array<string, mixed>> $records Manifest records keyed by normalized object path.
	 * @return bool True when the manifest JSON was written successfully.
	 */
	private function writeRecords(array $records): bool {
		$dir=dirname($this->manifest);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($this->manifest, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}
}
