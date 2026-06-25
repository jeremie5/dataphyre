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
 * Storage decorator that emits events around delegated storage operations.
 *
 * Each operation delegates to a target disk, then dispatches a normalized event
 * record to event-specific listeners, wildcard listeners, an optional JSONL
 * log, and the storage manager event bus. Write and delete also emit `before_*`
 * events before the target mutation is attempted.
 */
final class EventedDriver implements StorageDriver {

	/** @var string Target disk receiving the actual storage operation. */
	private string $disk;
	/** @var ?string Optional JSONL event log path. */
	private ?string $log;
	/** @var array<string, list<callable|string>> */
	private array $listeners;

	/**
	 * Initializes event listeners, optional JSONL logging, and delegated target storage.
	 *
	 * `disk` or `target` selects the physical disk. `listeners` maps event names
	 * or `*` to callables/function names. `log` enables append-only JSONL event
	 * persistence.
	 *
	 * @param array<string, mixed> $config Evented driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation and event emission.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->log=isset($config['log']) ? (string)$config['log'] : null;
		$this->listeners=is_array($config['listeners'] ?? null) ? $config['listeners'] : [];
		if($this->disk===''){
			throw new \RuntimeException('Evented storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether an object exists without emitting events.
	 *
	 * Existence probes are intentionally quiet so guard checks do not trigger
	 * listener side effects or event-log noise.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk has an object at the path.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents and emits a `read` event with operation status.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target read options included in the event record.
	 * @return string|false Object contents, or false when the target read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		$result=$this->manager->get($path, $this->disk, $options);
		$this->dispatch('read', $path, ['ok'=>$result!==false, 'options'=>$options]);
		return $result;
	}

	/**
	 * Opens a read stream and emits a `read_stream` event.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target stream options included in the event record.
	 * @return mixed Target stream result, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$result=$this->manager->readStream($path, $this->disk, $options);
		$this->dispatch('read_stream', $path, ['ok'=>is_resource($result), 'options'=>$options]);
		return $result;
	}

	/**
	 * Emits `before_write`, writes through the target disk, then emits `write`.
	 *
	 * The post-write event includes the target result as `ok`; listener failures
	 * are not caught by this driver.
	 *
	 * @param string $path Logical object path.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array<string, mixed> $options Target write options included in event records.
	 * @return bool True when the target write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$this->dispatch('before_write', $path, ['options'=>$options]);
		$result=$this->manager->put($path, $contents, $this->disk, $options);
		$this->dispatch('write', $path, ['ok'=>$result, 'options'=>$options]);
		return $result;
	}

	/**
	 * Emits `before_delete`, deletes through the target disk, then emits `delete`.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target delete succeeds.
	 */
	public function delete(string $path): bool {
		$this->dispatch('before_delete', $path);
		$result=$this->manager->delete($path, $this->disk);
		$this->dispatch('delete', $path, ['ok'=>$result]);
		return $result;
	}

	/**
	 * Returns target metadata without emitting events.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Target metadata, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		return $this->manager->metadata($path, $this->disk);
	}

	/**
	 * Lists target objects and emits a `list` event with item count.
	 *
	 * @param string $prefix Optional target path prefix.
	 * @param array<string, mixed> $options Target list options included in the event record.
	 * @return array<int, FileMetadata> Target metadata entries.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$result=$this->manager->list($prefix, $this->disk, $options);
		$this->dispatch('list', $prefix, ['ok'=>true, 'count'=>count($result), 'options'=>$options]);
		return $result;
	}

	/**
	 * Creates a temporary URL and emits a `temporary_url` event.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options Target URL options included in the event record.
	 * @return string|false Temporary URL, or false when unavailable.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$result=$this->manager->temporaryUrl($path, $expires, $this->disk, $options);
		$this->dispatch('temporary_url', $path, ['ok'=>$result!==false, 'options'=>$options]);
		return $result;
	}

	/**
	 * Reads recent event rows from the optional JSONL event log.
	 *
	 * Rows are filtered by normalized path when provided, malformed JSONL entries
	 * are skipped, and the result is returned newest-first with a clamped limit.
	 *
	 * @param ?string $path Optional logical path filter.
	 * @param array{limit?: int} $options Trail query options.
	 * @return array<int, array<string, mixed>> Newest matching event records.
	 */
	public function eventTrail(?string $path=null, array $options=[]): array {
		if($this->log===null || !is_file($this->log)){
			return [];
		}
		$path=$path!==null ? Path::normalize($path) : null;
		$limit=max(1, min(1000, (int)($options['limit'] ?? 100)));
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
			$rows[]=$row;
		}
		return array_slice(array_reverse($rows), 0, $limit);
	}

	/**
	 * Dispatches an event record to listeners, log storage, and manager events.
	 *
	 * Event-specific listeners run before wildcard listeners. Every record is
	 * enriched with event name, normalized path, target disk, and timestamp. Listener
	 * exceptions propagate to the storage caller before JSONL logging or manager
	 * emission can run.
	 *
	 * @param string $event Event name.
	 * @param string $path Logical object path or prefix.
	 * @param array<string, mixed> $eventData Event-specific record fields.
	 * @return void
	 */
	private function dispatch(string $event, string $path, array $eventData=[]): void {
		$eventData+=['event'=>$event, 'path'=>Path::normalize($path), 'disk'=>$this->disk, 'time'=>time()];
		foreach($this->listeners[$event] ?? [] as $listener){
			$this->callListener($listener, $eventData);
		}
		foreach($this->listeners['*'] ?? [] as $listener){
			$this->callListener($listener, $eventData);
		}
		if($this->log!==null){
			$this->log($eventData);
		}
		$this->manager->emit('storage.'.$event, $eventData);
	}

	/**
	 * Invokes one configured listener when it is callable.
	 *
	 * @param callable|string $listener Callable or global function name.
	 * @param array<string, mixed> $eventData Event record.
	 * @return void
	 */
	private function callListener(callable|string $listener, array $eventData): void {
		if(is_string($listener) && function_exists($listener)){
			$listener($eventData);
			return;
		}
		if(is_callable($listener)){
			$listener($eventData);
		}
	}

	/**
	 * Appends one event record to the optional JSONL log.
	 *
	 * Directory creation and write errors are suppressed so logging does not
	 * change the result of the delegated storage operation.
	 *
	 * @param array<string, mixed> $eventData Event record.
	 * @return void
	 */
	private function log(array $eventData): void {
		$dir=dirname((string)$this->log);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		@file_put_contents((string)$this->log, json_encode($eventData, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
	}
}
