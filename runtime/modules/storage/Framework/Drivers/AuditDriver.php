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
 * Storage decorator that records JSONL audit entries around delegated I/O.
 *
 * The driver leaves object content handling to a target disk and appends one audit row
 * per read, stream read, write, delete, metadata, list, and temporary URL call.
 * Rows include operation result, normalized path, actor/request hints, IP, and
 * optional reason/count fields; logging failures are intentionally non-blocking.
 */
final class AuditDriver implements StorageDriver {

	/** @var string Target disk receiving the actual storage operation. */
	private string $disk;
	/** @var string Filesystem path to the append-only JSONL audit log. */
	private string $log;

	/**
	 * Initializes audit logging for delegated storage operations.
	 *
	 * `disk` or `target` selects the physical disk. `log` controls where audit
	 * rows are appended and defaults to a temp-file JSONL log.
	 *
	 * @param array<string, mixed> $config Audit driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->log=(string)($config['log'] ?? sys_get_temp_dir().'/dataphyre-storage-audit.jsonl');
		if($this->disk===''){
			throw new \RuntimeException('Audit storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether an object exists without writing an audit row.
	 *
	 * Existence checks are intentionally read-only and unaudited to keep frequent
	 * guard probes from flooding the audit trail.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk has an object at the path.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents and records read success or failure.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target read options plus optional audit context.
	 * @return string|false Object contents, or false when the target read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		$result=$this->manager->get($path, $this->disk, $options);
		$this->record('read', $path, $result!==false, $options);
		return $result;
	}

	/**
	 * Opens a read stream and records whether a stream resource was returned.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target stream options plus optional audit context.
	 * @return mixed Target stream result, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$result=$this->manager->readStream($path, $this->disk, $options);
		$this->record('read_stream', $path, is_resource($result), $options);
		return $result;
	}

	/**
	 * Writes object contents and records the target write result.
	 *
	 * @param string $path Logical object path.
	 * @param mixed $contents Stringable contents or stream accepted by the target disk.
	 * @param array<string, mixed> $options Target write options plus optional audit context.
	 * @return bool True when the target write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$result=$this->manager->put($path, $contents, $this->disk, $options);
		$this->record('write', $path, $result, $options);
		return $result;
	}

	/**
	 * Deletes an object and records the target delete result.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target delete succeeds.
	 */
	public function delete(string $path): bool {
		$result=$this->manager->delete($path, $this->disk);
		$this->record('delete', $path, $result);
		return $result;
	}

	/**
	 * Reads object metadata and records whether metadata was available.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Target metadata, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$result=$this->manager->metadata($path, $this->disk);
		$this->record('metadata', $path, $result instanceof FileMetadata);
		return $result;
	}

	/**
	 * Lists target objects and records the returned item count.
	 *
	 * @param string $prefix Optional target path prefix.
	 * @param array<string, mixed> $options Target list options plus optional audit context.
	 * @return array<int, FileMetadata> Target metadata entries.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$result=$this->manager->list($prefix, $this->disk, $options);
		$this->record('list', $prefix, true, ['count'=>count($result)] + $options);
		return $result;
	}

	/**
	 * Creates a temporary URL and records whether signing succeeded.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options Target URL options plus optional audit context.
	 * @return string|false Temporary URL, or false when unavailable.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$result=$this->manager->temporaryUrl($path, $expires, $this->disk, $options);
		$this->record('temporary_url', $path, $result!==false, $options);
		return $result;
	}

	/**
	 * Reads recent audit rows, optionally filtered by path and operation.
	 *
	 * The log is scanned from disk, malformed JSONL rows are ignored, matching
	 * rows are reversed to newest-first order, and `limit` is clamped to 1..1000.
	 *
	 * @param ?string $path Optional logical path filter.
	 * @param array{limit?: int, operation?: string} $options Trail query options.
	 * @return array<int, array<string, mixed>> Newest matching audit rows.
	 */
	public function auditTrail(?string $path=null, array $options=[]): array {
		if(!is_file($this->log)){
			return [];
		}
		$path=$path!==null ? Path::normalize($path) : null;
		$limit=max(1, min(1000, (int)($options['limit'] ?? 100)));
		$operation=isset($options['operation']) ? (string)$options['operation'] : null;
		$rows=[];
		$file=new \SplFileObject($this->log, 'rb');
		while(!$file->eof()){
			$line=trim((string)$file->fgets());
			if($line===''){
				continue;
			}
			$row=json_decode($line, true);
			if(!is_array($row)){
				continue;
			}
			if($path!==null && ($row['path'] ?? '')!==$path){
				continue;
			}
			if($operation!==null && ($row['operation'] ?? '')!==$operation){
				continue;
			}
			$rows[]=$row;
		}
		return array_slice(array_reverse($rows), 0, $limit);
	}

	/**
	 * Appends one audit row to the JSONL log.
	 *
	 * Directory creation and writes are suppressed so audit logging never changes
	 * the result of the underlying storage operation.
	 *
	 * @param string $operation Operation name.
	 * @param string $path Logical object path or prefix.
	 * @param bool $ok Whether the delegated operation succeeded.
	 * @param array<string, mixed> $options Optional actor/request/reason/count context.
	 * @return void
	 */
	private function record(string $operation, string $path, bool $ok, array $options=[]): void {
		$dir=dirname($this->log);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		$row=[
			'id'=>bin2hex(random_bytes(12)),
			'time'=>time(),
			'operation'=>$operation,
			'path'=>Path::normalize($path),
			'ok'=>$ok,
			'actor'=>$options['actor'] ?? $this->actor(),
			'request_id'=>$options['request_id'] ?? $this->requestId(),
			'ip'=>$options['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
		];
		if(isset($options['reason'])){
			$row['reason']=(string)$options['reason'];
		}
		if(isset($options['count'])){
			$row['count']=(int)$options['count'];
		}
		@file_put_contents($this->log, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * Resolves the current actor from well-known application constants.
	 *
	 * @return ?string Actor identifier, or null when none is available.
	 */
	private function actor(): ?string {
		foreach(['USER_ID', 'ACCOUNT_ID', 'APP_USER_ID'] as $constant){
			if(defined($constant)){
				return (string)constant($constant);
			}
		}
		return null;
	}

	/**
	 * Resolves the current request identifier from server headers.
	 *
	 * @return ?string Request identifier, or null when none is available.
	 */
	private function requestId(): ?string {
		foreach(['HTTP_X_REQUEST_ID', 'REQUEST_ID'] as $key){
			if(isset($_SERVER[$key]) && trim((string)$_SERVER[$key])!==''){
				return (string)$_SERVER[$key];
			}
		}
		return null;
	}
}
