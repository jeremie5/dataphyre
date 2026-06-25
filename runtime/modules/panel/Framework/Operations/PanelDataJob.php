<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Synchronous panel data import/export job definition and runner.
 *
 * A data job captures the work plan for panel imports, exports, and bulk data
 * operations. It records work items, chunking, queue intent, resource context,
 * handler/mapper callbacks, progress events, generated artifacts, failures,
 * trace records, and a serializable result summary. Queue settings are metadata
 * only in this class; run() always executes synchronously in the current process.
 */
final class PanelDataJob implements \JsonSerializable {

	/** @var string Unique job identifier used by plans, traces, and results. */
	private string $id;
	/** @var string Normalized operation type such as import or export. */
	private string $type;
	/** @var string Normalized job name used for artifacts and display. */
	private string $name;
	/** @var ?Resource Panel resource associated with the job. */
	private ?Resource $resource=null;
	/** @var array<int, mixed> Work items processed by the job. */
	private array $items=[];
	/** @var int Number of items processed per chunk. */
	private int $chunkSize=100;
	/** @var ?string Queue name requested for async execution, or null for direct execution. */
	private ?string $queue=null;
	/** @var ?\Closure Item handler callback for non-mapping jobs. */
	private ?\Closure $handler=null;
	/** @var ?\Closure Item mapper callback for export-style jobs. */
	private ?\Closure $mapper=null;
	/** @var ?\Closure Progress callback invoked after each completed chunk. */
	private ?\Closure $progressHandler=null;
	/** @var array<int, array<string, mixed>> Generated artifacts including contents and metadata. */
	private array $artifacts=[];
	/** @var array<string, mixed> Job metadata emitted in plans and results. */
	private array $metadata=[];

	/**
	 * Creates a job with normalized type/name and generated id.
	 *
	 * The generated id includes time and random entropy so repeated jobs with the
	 * same type/name remain distinguishable in traces and UI.
	 *
	 * @param string $type Operation type before normalization.
	 * @param string $name Job name before normalization.
	 */
	private function __construct(string $type, string $name='job') {
		$this->type=Resource::normalizeName($type) ?: 'operation';
		$this->name=Resource::normalizeName($name) ?: $this->type;
		$this->id=$this->name.'_'.substr(sha1($this->type.'|'.$this->name.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)), 0, 14);
	}

	/**
	 * Creates a data job for an arbitrary operation type.
	 *
	 * @param string $type Operation type before normalization.
	 * @param string $name Job name before normalization.
	 * @return self New data job definition.
	 */
	public static function make(string $type, string $name='job'): self {
		return new self($type, $name);
	}

	/**
	 * Creates an import data job.
	 *
	 * @param string $name Job name before normalization.
	 * @return self Import job definition.
	 */
	public static function import(string $name='import'): self {
		return new self('import', $name);
	}

	/**
	 * Creates an export data job.
	 *
	 * @param string $name Job name before normalization.
	 * @return self Export job definition.
	 */
	public static function export(string $name='export'): self {
		return new self('export', $name);
	}

	/**
	 * Reads or overrides the job identifier.
	 *
	 * Blank override values are ignored so the generated id remains available.
	 *
	 * @param ?string $id Optional id override before normalization.
	 * @return string|self Current id when reading, otherwise this job after mutation.
	 */
	public function id(?string $id=null): string|self {
		if($id===null){
			return $this->id;
		}
		$id=Resource::normalizeName($id);
		if($id!==''){
			$this->id=$id;
		}
		return $this;
	}

	/**
	 * Attaches a panel resource context to the job.
	 *
	 * The resource name is also copied into metadata so plans and results remain
	 * useful after serialization.
	 *
	 * @param Resource $resource Resource associated with the import/export job.
	 * @return self This job with resource context attached.
	 */
	public function resource(Resource $resource): self {
		$this->resource=$resource;
		$this->metadata['resource']=$resource->name();
		return $this;
	}

	/**
	 * Replaces the work item list.
	 *
	 * @param array<int|string, mixed> $items Items to process; keys are discarded for stable offsets.
	 * @return self This job with items replaced.
	 */
	public function items(array $items): self {
		$this->items=array_values($items);
		return $this;
	}

	/**
	 * Replaces the work item list with import rows.
	 *
	 * @param array<int|string, mixed> $rows Import rows; keys are discarded for stable offsets.
	 * @return self This job with rows as items.
	 */
	public function rows(array $rows): self {
		return $this->items($rows);
	}

	/**
	 * Replaces the work item list with export records.
	 *
	 * @param array<int|string, mixed> $records Export records; keys are discarded for stable offsets.
	 * @return self This job with records as items.
	 */
	public function records(array $records): self {
		return $this->items($records);
	}

	/**
	 * Sets the number of items processed per chunk.
	 *
	 * @param int $size Desired chunk size, clamped to 1..10000.
	 * @return self This job with chunk size updated.
	 */
	public function chunkSize(int $size): self {
		$this->chunkSize=max(1, min(10000, $size));
		return $this;
	}

	/**
	 * Marks the job as queueable or direct.
	 *
	 * Passing false or null disables queue intent. String values are normalized,
	 * with `default` used as the fallback queue name. This method does not enqueue
	 * work; it only records queue metadata for plans and results.
	 *
	 * @param string|bool|null $queue Queue name, true/default queue, or false/null for direct execution.
	 * @return self This job with queue metadata updated.
	 */
	public function queue(string|bool|null $queue='default'): self {
		$this->queue=$queue===false || $queue===null ? null : (Resource::normalizeName((string)$queue) ?: 'default');
		return $this;
	}

	/**
	 * Sets the per-item handler callback.
	 *
	 * Handlers receive item, offset, resource, and job. Handler return values are
	 * ignored unless a mapper is also configured. Exceptions thrown by handlers are
	 * captured as item failures by run().
	 *
	 * @param callable $handler Item handler callback.
	 * @return self This job with handler attached.
	 */
	public function handle(callable $handler): self {
		$this->handler=\Closure::fromCallable($handler);
		return $this;
	}

	/**
	 * Sets the per-item mapper callback.
	 *
	 * Mapper return values are collected and written to a generated JSON export
	 * artifact after the job completes. Exceptions thrown by mappers are captured as
	 * item failures and do not abort later items.
	 *
	 * @param callable $mapper Item mapper callback.
	 * @return self This job with mapper attached.
	 */
	public function map(callable $mapper): self {
		$this->mapper=\Closure::fromCallable($mapper);
		return $this;
	}

	/**
	 * Sets the chunk progress callback.
	 *
	 * The callback receives the normalized chunk event and the current job after
	 * each chunk completes.
	 *
	 * @param callable $handler Callback receiving chunk event and job.
	 * @return self This job with progress callback attached.
	 */
	public function progress(callable $handler): self {
		$this->progressHandler=\Closure::fromCallable($handler);
		return $this;
	}

	/**
	 * Appends an in-memory artifact to the job output.
	 *
	 * Artifacts are kept in memory and included in the final result summary;
	 * filesystem persistence is left to the caller.
	 *
	 * @param string $name Artifact filename or label.
	 * @param string $contents Artifact contents.
	 * @param string $mime Artifact MIME type.
	 * @param array<string, mixed> $metadata Artifact metadata.
	 * @return self This job with artifact appended.
	 */
	public function artifact(string $name, string $contents, string $mime='text/plain', array $metadata=[]): self {
		$name=trim($name) ?: 'artifact.txt';
		$this->artifacts[]=[
			'name'=>$name,
			'mime'=>$mime,
			'bytes'=>strlen($contents),
			'contents'=>$contents,
			'metadata'=>$metadata,
		];
		return $this;
	}

	/**
	 * Merges diagnostic metadata into the job.
	 *
	 * @param array<string, mixed> $metadata Metadata to shallow-merge.
	 * @return self This job with metadata updated.
	 */
	public function metadata(array $metadata): self {
		$this->metadata=array_replace($this->metadata, $metadata);
		return $this;
	}

	/**
	 * Serializes the planned job without executing callbacks.
	 *
	 * The plan is safe for previews and queue manifests because it exposes
	 * counts, queue intent, callback presence, and metadata without including
	 * item contents.
	 *
	 * @return array<string, mixed> Job plan data without item contents or callback objects.
	 */
	public function plan(): array {
		return [
			'id'=>$this->id,
			'type'=>$this->type,
			'name'=>$this->name,
			'resource'=>$this->resource?->name(),
			'total'=>count($this->items),
			'chunk_size'=>$this->chunkSize,
			'chunks'=>(int)ceil(count($this->items) / $this->chunkSize),
			'queueable'=>$this->queue!==null,
			'queue'=>$this->queue,
			'handler'=>$this->handler instanceof \Closure,
			'mapper'=>$this->mapper instanceof \Closure,
			'metadata'=>$this->metadata,
		];
	}

	/**
	 * Executes the job synchronously and returns an aggregate result.
	 *
	 * Items are processed in chunks. Exceptions from item callbacks are captured
	 * as per-item failures instead of aborting the whole job. Mapper output is
	 * exported to JSON, failures are exported to CSV, progress handlers run after
	 * each chunk, and trace events are emitted for start/completion. Artifact
	 * contents stay in memory during execution; the final result exposes artifact
	 * summaries only.
	 *
	 * @return PanelDataJobResult Completed job summary with failures, artifacts, events, and metadata.
	 */
	public function run(): PanelDataJobResult {
		$started=microtime(true);
		$total=count($this->items);
		$processed=0;
		$succeeded=0;
		$failures=[];
		$events=[];
		$mapped=[];
		PanelTrace::record('data_job.started', [
			'plan'=>$this->plan(),
		]);
		foreach(array_chunk($this->items, $this->chunkSize, true) as $chunkIndex=>$chunk){
			$events[]=self::event('chunk_started', [
				'chunk'=>$chunkIndex+1,
				'items'=>count($chunk),
				'processed'=>$processed,
				'total'=>$total,
			]);
			foreach($chunk as $offset=>$item){
				try{
					$result=$this->processItem($item, (int)$offset, $processed);
					if($this->mapper instanceof \Closure){
						$mapped[]=$result;
					}
					$succeeded++;
				}
				catch(\Throwable $exception){
					$failures[]=self::failure((int)$offset, $item, $exception->getMessage());
				}
				$processed++;
			}
			$event=self::event('chunk_completed', [
				'chunk'=>$chunkIndex+1,
				'items'=>count($chunk),
				'processed'=>$processed,
				'succeeded'=>$succeeded,
				'failed'=>count($failures),
				'total'=>$total,
			]);
			$events[]=$event;
			if($this->progressHandler instanceof \Closure){
				($this->progressHandler)($event, $this);
			}
		}
		if($mapped!==[]){
			$this->artifact($this->name.'-export.json', json_encode($mapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', 'application/json', [
				'generated_by'=>'PanelDataJob',
				'rows'=>count($mapped),
			]);
		}
		if($failures!==[]){
			$this->artifact($this->name.'-failures.csv', self::failureCsv($failures), 'text/csv', [
				'failure_count'=>count($failures),
			]);
		}
		$status=$failures===[] ? 'completed' : ($succeeded>0 ? 'completed_with_failures' : 'failed');
		$result=PanelDataJobResult::make([
			'id'=>$this->id,
			'type'=>$this->type,
			'name'=>$this->name,
			'status'=>$status,
			'total'=>$total,
			'processed'=>$processed,
			'succeeded'=>$succeeded,
			'failed'=>count($failures),
			'failures'=>$failures,
			'artifacts'=>self::artifactSummaries($this->artifacts),
			'events'=>$events,
			'metadata'=>array_replace($this->metadata, [
				'duration_ms'=>round((microtime(true) - $started) * 1000, 3),
				'queueable'=>$this->queue!==null,
				'queue'=>$this->queue,
				'chunks'=>(int)ceil($total / $this->chunkSize),
			]),
		]);
		PanelTrace::record('data_job.completed', [
			'result'=>$result,
		]);
		return $result;
	}

	/**
	 * Exposes the job plan data to json_encode().
	 *
	 * @return array<string, mixed> Job plan data without item contents or callback objects.
	 */
	public function jsonSerialize(): array {
		return $this->plan();
	}

	/**
	 * Processes one item through mapper, handler, or identity fallback.
	 *
	 * Mapper callbacks take precedence over handlers because export-style jobs need
	 * collected row output. With no callback, the original item is returned so
	 * mapping jobs can still produce identity exports.
	 *
	 * @param mixed $item Work item.
	 * @param int $offset Original zero-based item offset.
	 * @param int $processed Count processed before this item.
	 * @return mixed Mapper/handler result, or original item when no callback exists.
	 */
	private function processItem(mixed $item, int $offset, int $processed): mixed {
		if($this->mapper instanceof \Closure){
			return ($this->mapper)($item, $offset, $this->resource, $this);
		}
		if($this->handler instanceof \Closure){
			return ($this->handler)($item, $offset, $this->resource, $this);
		}
		return $item;
	}

	/**
	 * Builds a timestamped job event record.
	 *
	 * @param string $name Event name before normalization.
	 * @param array<string, mixed> $data Event data.
	 * @return array{event: string, time: float, data: array<string, mixed>} Event record.
	 */
	private static function event(string $name, array $data=[]): array {
		return [
			'event'=>Resource::normalizeName($name) ?: 'event',
			'time'=>microtime(true),
			'data'=>$data,
		];
	}

	/**
	 * Builds a compact failure record for one failed item.
	 *
	 * @param int $offset Original item offset.
	 * @param mixed $item Failed work item.
	 * @param string $message Failure message.
	 * @return array{offset: int, message: string, item: mixed} Failure record.
	 */
	private static function failure(int $offset, mixed $item, string $message): array {
		return [
			'offset'=>$offset,
			'message'=>$message,
			'item'=>self::compactValue($item),
		];
	}

	/**
	 * Converts failure records into a CSV artifact.
	 *
	 * @param array<int, array<string, mixed>> $failures Failure records.
	 * @return string CSV document containing offset, message, and compact item JSON.
	 */
	private static function failureCsv(array $failures): string {
		$handle=fopen('php://temp', 'r+');
		if($handle===false){
			return '';
		}
		fputcsv($handle, ['offset', 'message', 'item'], ',', '"', '');
		foreach($failures as $failure){
			fputcsv($handle, [
				(string)($failure['offset'] ?? ''),
				(string)($failure['message'] ?? ''),
				json_encode($failure['item'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
			], ',', '"', '');
		}
		rewind($handle);
		$csv=(string)stream_get_contents($handle);
		fclose($handle);
		return $csv;
	}

	/**
	 * Summarizes generated artifacts without including full contents.
	 *
	 * Artifact contents are intentionally omitted from the returned summaries so
	 * final job results can be rendered or serialized without embedding full export
	 * files.
	 *
	 * @param array<int, array<string, mixed>> $artifacts Artifact records.
	 * @return array<int, array{name: string, mime: string, bytes: int, metadata: array<string, mixed>}> Artifact summaries.
	 */
	private static function artifactSummaries(array $artifacts): array {
		$summaries=[];
		foreach($artifacts as $artifact){
			$summaries[]=[
				'name'=>(string)($artifact['name'] ?? 'artifact'),
				'mime'=>(string)($artifact['mime'] ?? 'text/plain'),
				'bytes'=>(int)($artifact['bytes'] ?? strlen((string)($artifact['contents'] ?? ''))),
				'metadata'=>is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [],
			];
		}
		return $summaries;
	}

	/**
	 * Compacts large or object item values for failure diagnostics.
	 *
	 * Large arrays are summarized by count and first keys. Objects are described
	 * by class name so failure records remain serializable and reasonably small.
	 *
	 * @param mixed $value Item value to compact.
	 * @return mixed small serializable value, summarized large array, or object class descriptor.
	 */
	private static function compactValue(mixed $value): mixed {
		if(is_array($value)){
			if(count($value)>12){
				return [
					'type'=>'array',
					'count'=>count($value),
					'keys'=>array_slice(array_keys($value), 0, 12),
				];
			}
			return $value;
		}
		if(is_object($value)){
			return [
				'type'=>'object',
				'class'=>$value::class,
			];
		}
		return $value;
	}
}
