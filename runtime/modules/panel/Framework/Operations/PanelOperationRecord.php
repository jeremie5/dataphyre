<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable, JSON-safe state record for a durable Panel operation.
 *
 * The record owns lifecycle invariants while stores own concurrency and durability.
 */
final class PanelOperationRecord implements \JsonSerializable {

	private const MAX_LOGS=1000;
	private const MAX_CHECKPOINTS=250;
	private const MAX_ARTIFACTS=500;

	/** @param array<string, mixed> $data */
	private function __construct(private array $data) {}

	/** @param array<string, mixed> $options */
	public static function make(string $type, string $name='operation', array $options=[]): self {
		$now=self::time($options['created_at'] ?? null);
		$id=self::identifier((string)($options['id'] ?? self::generateId($type)));
		$type=self::slug($type, 'operation');
		$name=trim($name)!=='' ? trim($name) : $type;
		$maxAttempts=max(1, min(100, (int)($options['max_attempts'] ?? 1)));
		$queue=self::slug((string)($options['queue'] ?? 'default'), 'default');
		$idempotency=self::optionalString($options['idempotency_key'] ?? null, 255);
		return self::fromArray([
			'id'=>$id,
			'type'=>$type,
			'name'=>$name,
			'queue'=>$queue,
			'status'=>PanelOperationStatus::QUEUED,
			'revision'=>0,
			'attempt'=>0,
			'max_attempts'=>$maxAttempts,
			'retry_delay_seconds'=>max(0, min(86400, (int)($options['retry_delay_seconds'] ?? 0))),
			'idempotency_key'=>$idempotency,
			'payload'=>$options['payload'] ?? [],
			'metadata'=>$options['metadata'] ?? [],
			'total'=>max(0, (int)($options['total'] ?? 0)),
			'processed'=>0,
			'succeeded'=>0,
			'failed'=>0,
			'progress_message'=>null,
			'checkpoints'=>[],
			'logs'=>[],
			'artifacts'=>[],
			'result'=>null,
			'error'=>null,
			'worker'=>null,
			'resume_pending'=>false,
			'available_at'=>$now,
			'created_at'=>$now,
			'updated_at'=>$now,
			'started_at'=>null,
			'finished_at'=>null,
			'heartbeat_at'=>null,
			'pause_requested_at'=>null,
			'cancel_requested_at'=>null,
		]);
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$id=self::identifier((string)($input['id'] ?? ''));
		$status=PanelOperationStatus::normalize((string)($input['status'] ?? PanelOperationStatus::QUEUED));
		$created=self::time($input['created_at'] ?? null);
		$updated=self::time($input['updated_at'] ?? $created);
		$attempt=max(0, (int)($input['attempt'] ?? 0));
		$maxAttempts=max(1, min(100, (int)($input['max_attempts'] ?? 1)));
		if($attempt>$maxAttempts){
			throw new \InvalidArgumentException('Panel operation attempt exceeds max_attempts.');
		}
		$total=max(0, (int)($input['total'] ?? 0));
		$processed=max(0, (int)($input['processed'] ?? 0));
		$succeeded=max(0, (int)($input['succeeded'] ?? 0));
		$failed=max(0, (int)($input['failed'] ?? 0));
		if($total>0 && $processed>$total){
			throw new \InvalidArgumentException('Panel operation processed count exceeds total.');
		}
		if($succeeded+$failed>$processed){
			throw new \InvalidArgumentException('Panel operation outcome counts exceed processed count.');
		}
		$data=[
			'id'=>$id,
			'type'=>self::slug((string)($input['type'] ?? 'operation'), 'operation'),
			'name'=>self::boundedString((string)($input['name'] ?? 'operation'), 200, 'operation'),
			'queue'=>self::slug((string)($input['queue'] ?? 'default'), 'default'),
			'status'=>$status,
			'revision'=>max(0, (int)($input['revision'] ?? 0)),
			'attempt'=>$attempt,
			'max_attempts'=>$maxAttempts,
			'retry_delay_seconds'=>max(0, min(86400, (int)($input['retry_delay_seconds'] ?? 0))),
			'idempotency_key'=>self::optionalString($input['idempotency_key'] ?? null, 255),
			'payload'=>self::jsonValue($input['payload'] ?? [], 'payload'),
			'metadata'=>self::map($input['metadata'] ?? [], 'metadata'),
			'total'=>$total,
			'processed'=>$processed,
			'succeeded'=>$succeeded,
			'failed'=>$failed,
			'progress_message'=>self::optionalString($input['progress_message'] ?? null, 1000),
			'checkpoints'=>self::boundedList($input['checkpoints'] ?? [], self::MAX_CHECKPOINTS, 'checkpoints'),
			'logs'=>self::boundedList($input['logs'] ?? [], self::MAX_LOGS, 'logs'),
			'artifacts'=>self::boundedList($input['artifacts'] ?? [], self::MAX_ARTIFACTS, 'artifacts'),
			'result'=>array_key_exists('result', $input) && $input['result']!==null ? self::jsonValue($input['result'], 'result') : null,
			'error'=>array_key_exists('error', $input) && $input['error']!==null ? self::map($input['error'], 'error') : null,
			'worker'=>self::optionalString($input['worker'] ?? null, 200),
			'resume_pending'=>(bool)($input['resume_pending'] ?? false),
			'available_at'=>self::time($input['available_at'] ?? $created),
			'created_at'=>$created,
			'updated_at'=>$updated,
			'started_at'=>self::optionalTime($input['started_at'] ?? null),
			'finished_at'=>self::optionalTime($input['finished_at'] ?? null),
			'heartbeat_at'=>self::optionalTime($input['heartbeat_at'] ?? null),
			'pause_requested_at'=>self::optionalTime($input['pause_requested_at'] ?? null),
			'cancel_requested_at'=>self::optionalTime($input['cancel_requested_at'] ?? null),
		];
		return new self($data);
	}

	public function id(): string { return $this->data['id']; }
	public function type(): string { return $this->data['type']; }
	public function name(): string { return $this->data['name']; }
	public function queue(): string { return $this->data['queue']; }
	public function status(): string { return $this->data['status']; }
	public function revision(): int { return $this->data['revision']; }
	public function attempt(): int { return $this->data['attempt']; }
	public function maxAttempts(): int { return $this->data['max_attempts']; }
	public function retryDelaySeconds(): int { return $this->data['retry_delay_seconds']; }
	public function idempotencyKey(): ?string { return $this->data['idempotency_key']; }
	/** @return array<string, mixed>|list<mixed>|scalar|null */
	public function payload(): mixed { return $this->data['payload']; }
	/** @return array<string, mixed> */
	public function metadata(): array { return $this->data['metadata']; }
	public function total(): int { return $this->data['total']; }
	public function processed(): int { return $this->data['processed']; }
	public function succeeded(): int { return $this->data['succeeded']; }
	public function failed(): int { return $this->data['failed']; }
	public function progressMessage(): ?string { return $this->data['progress_message']; }
	/** @return list<array<string, mixed>> */
	public function checkpoints(): array { return $this->data['checkpoints']; }
	/** @return list<array<string, mixed>> */
	public function logs(): array { return $this->data['logs']; }
	/** @return list<array<string, mixed>> */
	public function artifacts(): array { return $this->data['artifacts']; }
	public function result(): mixed { return $this->data['result']; }
	/** @return array<string, mixed>|null */
	public function error(): ?array { return $this->data['error']; }
	public function worker(): ?string { return $this->data['worker']; }
	public function availableAt(): string { return $this->data['available_at']; }
	public function createdAt(): string { return $this->data['created_at']; }
	public function updatedAt(): string { return $this->data['updated_at']; }
	public function heartbeatAt(): ?string { return $this->data['heartbeat_at']; }
	public function terminal(): bool { return PanelOperationStatus::terminal($this->status()); }
	public function canRetry(): bool { return $this->attempt()<$this->maxAttempts(); }
	public function percent(): int {
		if(in_array($this->status(), [PanelOperationStatus::COMPLETED, PanelOperationStatus::COMPLETED_WITH_FAILURES], true)){ return 100; }
		return $this->total()===0 ? 0 : min(100, (int)floor(($this->processed()/$this->total())*100));
	}

	public function withRevision(int $revision): self {
		return $this->change(['revision'=>max(0, $revision)], false);
	}

	public function start(string $worker='local', mixed $at=null): self {
		if(!in_array($this->status(), [PanelOperationStatus::QUEUED, PanelOperationStatus::RETRY_WAIT], true)){
			throw new \LogicException('Only queued or retry-wait operations can start.');
		}
		$resuming=(bool)$this->data['resume_pending'];
		if(!$resuming && !$this->canRetry() && $this->attempt()>0){
			throw new \LogicException('Panel operation exhausted its attempts.');
		}
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::RUNNING, [
			'attempt'=>$this->attempt()+($resuming ? 0 : 1),
			'worker'=>self::boundedString($worker, 200, 'local'),
			'resume_pending'=>false,
			'started_at'=>$this->data['started_at'] ?? $at,
			'heartbeat_at'=>$at,
			'finished_at'=>null,
			'error'=>null,
		], $at);
	}

	public function heartbeat(mixed $at=null): self {
		if(!PanelOperationStatus::active($this->status())){
			return $this;
		}
		$at=self::time($at);
		return $this->change(['heartbeat_at'=>$at, 'updated_at'=>$at], false);
	}

	public function progress(int $processed, ?int $total=null, ?string $message=null, ?int $succeeded=null, ?int $failed=null, mixed $at=null): self {
		if(!PanelOperationStatus::active($this->status())){
			throw new \LogicException('Progress can only be updated while an operation is active.');
		}
		$total=$total===null ? $this->total() : max(0, $total);
		$processed=max($this->processed(), $processed);
		if($total>0 && $processed>$total){
			throw new \InvalidArgumentException('Processed count cannot exceed total.');
		}
		$succeeded=$succeeded===null ? $this->succeeded() : max(0, $succeeded);
		$failed=$failed===null ? $this->failed() : max(0, $failed);
		if($succeeded+$failed>$processed){
			throw new \InvalidArgumentException('Outcome counts cannot exceed processed count.');
		}
		$at=self::time($at);
		return $this->change([
			'total'=>$total, 'processed'=>$processed, 'succeeded'=>$succeeded, 'failed'=>$failed,
			'progress_message'=>self::optionalString($message, 1000), 'heartbeat_at'=>$at, 'updated_at'=>$at,
		], false);
	}

	/** @param array<string, mixed> $state */
	public function checkpoint(string $name, array $state=[], mixed $at=null): self {
		$name=self::slug($name, 'checkpoint');
		$at=self::time($at);
		$checkpoints=$this->checkpoints();
		$checkpoints[]=['name'=>$name, 'sequence'=>count($checkpoints)+1, 'at'=>$at, 'state'=>self::map($state, 'checkpoint state')];
		$checkpoints=array_slice($checkpoints, -self::MAX_CHECKPOINTS);
		return $this->change(['checkpoints'=>$checkpoints, 'heartbeat_at'=>$at, 'updated_at'=>$at], false);
	}

	/** @param array<string, mixed> $context */
	public function log(string $level, string $message, array $context=[], mixed $at=null): self {
		$level=strtolower(trim($level));
		if(!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)){
			throw new \InvalidArgumentException("Unsupported Panel operation log level '{$level}'.");
		}
		$at=self::time($at);
		$logs=$this->logs();
		$logs[]=['sequence'=>count($logs)+1, 'level'=>$level, 'message'=>self::boundedString($message, 4000, '(empty)'), 'context'=>self::map($context, 'log context'), 'at'=>$at];
		$logs=array_slice($logs, -self::MAX_LOGS);
		return $this->change(['logs'=>$logs, 'updated_at'=>$at], false);
	}

	/** @param array<string, mixed> $metadata */
	public function artifact(string $name, string $location, string $mime='application/octet-stream', ?int $bytes=null, array $metadata=[], mixed $at=null): self {
		$at=self::time($at);
		$artifacts=$this->artifacts();
		$artifacts[]=[
			'name'=>self::boundedString($name, 255, 'artifact'),
			'location'=>self::boundedString($location, 4096, 'inline'),
			'mime'=>self::boundedString($mime, 255, 'application/octet-stream'),
			'bytes'=>$bytes===null ? null : max(0, $bytes),
			'metadata'=>self::map($metadata, 'artifact metadata'),
			'created_at'=>$at,
		];
		$artifacts=array_slice($artifacts, -self::MAX_ARTIFACTS);
		return $this->change(['artifacts'=>$artifacts, 'updated_at'=>$at], false);
	}

	public function requestPause(mixed $at=null): self {
		if($this->status()===PanelOperationStatus::PAUSE_REQUESTED || $this->status()===PanelOperationStatus::PAUSED){ return $this; }
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::PAUSE_REQUESTED, ['pause_requested_at'=>$at], $at);
	}

	public function markPaused(mixed $at=null): self {
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::PAUSED, ['worker'=>null, 'heartbeat_at'=>$at], $at);
	}

	public function resume(mixed $at=null): self {
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::QUEUED, [
			'worker'=>null, 'available_at'=>$at, 'pause_requested_at'=>null, 'finished_at'=>null, 'resume_pending'=>true,
		], $at);
	}

	public function requestCancel(mixed $at=null): self {
		if($this->terminal()){ return $this; }
		$at=self::time($at);
		if(in_array($this->status(), [PanelOperationStatus::QUEUED, PanelOperationStatus::PAUSED, PanelOperationStatus::RETRY_WAIT], true)){
			return $this->transition(PanelOperationStatus::CANCELLED, ['cancel_requested_at'=>$at, 'finished_at'=>$at, 'worker'=>null], $at);
		}
		if($this->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $this; }
		return $this->transition(PanelOperationStatus::CANCEL_REQUESTED, ['cancel_requested_at'=>$at], $at);
	}

	public function cancel(mixed $at=null): self {
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::CANCELLED, ['cancel_requested_at'=>$this->data['cancel_requested_at'] ?? $at, 'finished_at'=>$at, 'worker'=>null], $at);
	}

	public function retry(?int $delaySeconds=null, mixed $at=null): self {
		if(!$this->canRetry()){
			throw new \LogicException('Panel operation has no retry attempts remaining.');
		}
		$at=self::time($at);
		$delay=max(0, min(86400, $delaySeconds ?? $this->retryDelaySeconds()));
		$available=(new \DateTimeImmutable($at))->modify('+'.$delay.' seconds')->format(DATE_ATOM);
		return $this->transition(PanelOperationStatus::RETRY_WAIT, [
			'available_at'=>$available, 'worker'=>null, 'finished_at'=>null, 'resume_pending'=>false,
		], $at);
	}

	public function requeue(mixed $at=null): self {
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::QUEUED, ['available_at'=>$at, 'worker'=>null, 'finished_at'=>null, 'resume_pending'=>false], $at);
	}

	/** Explicit operator retry, extending the attempt budget when it was exhausted. */
	public function manualRetry(mixed $at=null): self {
		if($this->status()!==PanelOperationStatus::FAILED){ throw new \LogicException('Manual retry is only available for failed operations.'); }
		if($this->attempt()>=100){ throw new \LogicException('Panel operation reached the hard limit of 100 attempts.'); }
		$at=self::time($at);
		return $this->transition(PanelOperationStatus::QUEUED, [
			'max_attempts'=>max($this->maxAttempts(), $this->attempt()+1),
			'available_at'=>$at, 'worker'=>null, 'finished_at'=>null, 'resume_pending'=>false, 'error'=>null,
		], $at);
	}

	public function complete(mixed $result=null, string $status=PanelOperationStatus::COMPLETED, mixed $at=null): self {
		$status=PanelOperationStatus::normalize($status);
		if(!in_array($status, [PanelOperationStatus::COMPLETED, PanelOperationStatus::COMPLETED_WITH_FAILURES], true)){
			throw new \InvalidArgumentException('Completion status must be completed or completed_with_failures.');
		}
		$at=self::time($at);
		return $this->transition($status, [
			'result'=>self::jsonValue($result, 'result'), 'error'=>null, 'finished_at'=>$at,
			'heartbeat_at'=>$at, 'worker'=>null,
		], $at);
	}

	public function fail(\Throwable|string $error, mixed $at=null): self {
		$at=self::time($at);
		$descriptor=is_string($error)
			? ['type'=>'RuntimeException', 'message'=>self::boundedString($error, 4000, 'Operation failed.')]
			: ['type'=>$error::class, 'message'=>self::boundedString($error->getMessage(), 4000, 'Operation failed.'), 'code'=>$error->getCode()];
		return $this->transition(PanelOperationStatus::FAILED, [
			'error'=>$descriptor, 'finished_at'=>$at, 'heartbeat_at'=>$at, 'worker'=>null,
		], $at);
	}

	/** @return array<string, mixed> */
	public function manifest(): array {
		return array_merge($this->data, [
			'percent'=>$this->percent(),
			'terminal'=>$this->terminal(),
			'can_retry'=>$this->canRetry(),
		]);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array { return $this->manifest(); }

	/** @param array<string, mixed> $changes */
	private function transition(string $status, array $changes, string $at): self {
		PanelOperationStatus::assertTransition($this->status(), $status);
		return $this->change(array_merge($changes, ['status'=>$status, 'updated_at'=>$at]), false);
	}

	/** @param array<string, mixed> $changes */
	private function change(array $changes, bool $normalize=true): self {
		$data=array_replace($this->data, $changes);
		return $normalize ? self::fromArray($data) : new self($data);
	}

	private static function generateId(string $type): string {
		return self::slug($type, 'operation').'_'.bin2hex(random_bytes(12));
	}

	private static function identifier(string $id): string {
		$id=trim($id);
		if($id==='' || strlen($id)>190 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $id)!==1){
			throw new \InvalidArgumentException('Panel operation id must be 1-190 safe identifier characters.');
		}
		return $id;
	}

	private static function slug(string $value, string $fallback): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
		$value=trim($value, '_');
		return substr($value!=='' ? $value : $fallback, 0, 100);
	}

	private static function boundedString(string $value, int $max, string $fallback): string {
		$value=trim($value);
		if($value===''){ $value=$fallback; }
		if(strlen($value)>$max){ throw new \InvalidArgumentException("Value exceeds {$max} bytes."); }
		return $value;
	}

	private static function optionalString(mixed $value, int $max): ?string {
		if($value===null){ return null; }
		$value=trim((string)$value);
		if($value===''){ return null; }
		if(strlen($value)>$max){ throw new \InvalidArgumentException("Value exceeds {$max} bytes."); }
		return $value;
	}

	private static function time(mixed $value): string {
		if($value===null || $value===''){ return gmdate(DATE_ATOM); }
		try{ return (new \DateTimeImmutable((string)$value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM); }
		catch(\Throwable){ throw new \InvalidArgumentException('Invalid Panel operation timestamp.'); }
	}

	private static function optionalTime(mixed $value): ?string { return $value===null || $value==='' ? null : self::time($value); }

	/** @return array<string, mixed> */
	private static function map(mixed $value, string $label): array {
		if(!is_array($value) || ($value!==[] && array_is_list($value))){
			throw new \InvalidArgumentException("Panel operation {$label} must be an object-like array.");
		}
		/** @var array<string, mixed> $normalized */
		$normalized=self::jsonValue($value, $label);
		return $normalized;
	}

	/** @return list<array<string, mixed>> */
	private static function boundedList(mixed $value, int $max, string $label): array {
		if(!is_array($value)){ throw new \InvalidArgumentException("Panel operation {$label} must be an array."); }
		$list=[];
		foreach(array_slice(array_values($value), -$max) as $entry){
			$list[]=self::map($entry, $label.' entry');
		}
		return $list;
	}

	private static function jsonValue(mixed $value, string $label, int $depth=0): mixed {
		if($depth>16){ throw new \InvalidArgumentException("Panel operation {$label} exceeds maximum nesting depth."); }
		if($value===null || is_string($value) || is_int($value) || is_bool($value)){ return $value; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException("Panel operation {$label} contains a non-finite number."); }
			return $value;
		}
		if($value instanceof \JsonSerializable){ return self::jsonValue($value->jsonSerialize(), $label, $depth+1); }
		if(is_array($value)){
			if(count($value)>10000){ throw new \InvalidArgumentException("Panel operation {$label} contains too many entries."); }
			$out=[];
			foreach($value as $key=>$item){
				if(!is_int($key) && !is_string($key)){ throw new \InvalidArgumentException("Panel operation {$label} contains an invalid key."); }
				$out[$key]=self::jsonValue($item, $label, $depth+1);
			}
			return $out;
		}
		throw new \InvalidArgumentException("Panel operation {$label} contains a non-serializable value.");
	}
}
