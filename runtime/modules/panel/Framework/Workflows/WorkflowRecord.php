<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable persisted workflow aggregate with optimistic version and audit history.
 */
final class WorkflowRecord implements \JsonSerializable {
	/** @var list<string> */
	private array $assignedRoles;
	/** @var list<WorkflowEvent> */
	private array $history;
	/** @var array<string,array<string,mixed>> */
	private array $idempotency;

	/**
	 * @param array<string,mixed> $data
	 * @param list<string> $assignedRoles
	 * @param ?array<string,mixed> $pendingApproval
	 * @param list<WorkflowEvent> $history
	 * @param array<string,array<string,mixed>> $idempotency
	 * @param array<string,mixed> $metadata
	 */
	private function __construct(
		private readonly string $definition,
		private readonly string $id,
		private readonly string $state,
		private readonly array $data,
		private readonly int $version,
		private readonly ?string $assignedTo,
		array $assignedRoles,
		private readonly ?string $deadlineAt,
		private readonly ?array $pendingApproval,
		array $history,
		array $idempotency,
		private readonly array $metadata,
		private readonly string $createdAt,
		private readonly string $updatedAt
	){
		$this->assignedRoles=array_values(array_unique(array_filter(array_map(WorkflowState::normalize(...), $assignedRoles))));
		$this->history=$history;
		$this->idempotency=$idempotency;
	}

	/** @param array<string,mixed> $data @param list<string> $assignedRoles @param array<string,mixed> $metadata */
	public static function create(
		string $definition,
		string $id,
		string $state,
		array $data,
		WorkflowEvent $createdEvent,
		?string $assignedTo=null,
		array $assignedRoles=[],
		?string $deadlineAt=null,
		array $metadata=[]
	): self {
		$now=$createdEvent->occurredAt();
		return new self(
			WorkflowState::normalize($definition), trim($id), WorkflowState::normalize($state),
			self::jsonSafe($data), 1, $assignedTo, $assignedRoles, $deadlineAt, null,
			[$createdEvent], [], self::jsonSafe($metadata), $now, $now
		);
	}

	/** @param array<string,mixed> $record */
	public static function fromArray(array $record): self {
		$history=[];
		foreach(is_array($record['history'] ?? null) ? $record['history'] : [] as $event){
			if(is_array($event)){
				$history[]=WorkflowEvent::fromArray($event);
			}
		}
		return new self(
			(string)($record['definition'] ?? ''), (string)($record['id'] ?? ''),
			(string)($record['state'] ?? ''), is_array($record['data'] ?? null) ? $record['data'] : [],
			max(0, (int)($record['version'] ?? 0)),
			isset($record['assigned_to']) ? (string)$record['assigned_to'] : null,
			is_array($record['assigned_roles'] ?? null) ? $record['assigned_roles'] : [],
			isset($record['deadline_at']) ? (string)$record['deadline_at'] : null,
			is_array($record['pending_approval'] ?? null) ? $record['pending_approval'] : null,
			$history,
			is_array($record['idempotency'] ?? null) ? $record['idempotency'] : [],
			is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
			(string)($record['created_at'] ?? ''), (string)($record['updated_at'] ?? '')
		);
	}

	public function definition(): string { return $this->definition; }
	public function id(): string { return $this->id; }
	public function state(): string { return $this->state; }
	/** @return array<string,mixed> */
	public function data(): array { return $this->data; }
	public function version(): int { return $this->version; }
	public function assignedTo(): ?string { return $this->assignedTo; }
	/** @return list<string> */
	public function assignedRoles(): array { return $this->assignedRoles; }
	public function deadlineAt(): ?string { return $this->deadlineAt; }
	/** @return ?array<string,mixed> */
	public function pendingApproval(): ?array { return $this->pendingApproval; }
	/** @return list<WorkflowEvent> */
	public function history(): array { return $this->history; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }
	public function createdAt(): string { return $this->createdAt; }
	public function updatedAt(): string { return $this->updatedAt; }

	public function lastHash(): string {
		$last=$this->history[count($this->history)-1] ?? null;
		return $last instanceof WorkflowEvent ? $last->hash() : '';
	}

	public function historyValid(): bool {
		$previous='';
		foreach($this->history as $event){
			if(!$event->verify($previous)){
				return false;
			}
			$previous=$event->hash();
		}
		return true;
	}

	public function isOverdue(?\DateTimeImmutable $now=null): bool {
		if($this->deadlineAt===null || $this->deadlineAt===''){
			return false;
		}
		try{
			$deadline=new \DateTimeImmutable($this->deadlineAt);
		}catch(\Throwable){
			return false;
		}
		return $deadline < ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
	}

	/** @return ?array<string,mixed> */
	public function idempotencyResult(string $key): ?array {
		$key=trim($key);
		return $key!=='' ? ($this->idempotency[hash('sha256', $key)] ?? null) : null;
	}

	public function event(string $eventId): ?WorkflowEvent {
		foreach($this->history as $event){
			if(hash_equals($event->id(), $eventId)){
				return $event;
			}
		}
		return null;
	}

	public function lastAppliedTransition(): ?WorkflowEvent {
		for($index=count($this->history)-1; $index>=0; $index--){
			if($this->history[$index]->type()==='transition_applied'){
				return $this->history[$index];
			}
		}
		return null;
	}

	/**
	 * Creates the next optimistic version; callers provide already hash-linked events.
	 *
	 * @param array<string,mixed> $changes
	 * @param list<WorkflowEvent> $events
	 * @param ?array<string,mixed> $idempotencyResult
	 */
	public function next(array $changes, array $events, ?string $idempotencyKey=null, ?array $idempotencyResult=null): self {
		$history=array_merge($this->history, $events);
		$idempotency=$this->idempotency;
		if($idempotencyKey!==null && trim($idempotencyKey)!=='' && $idempotencyResult!==null){
			$idempotency[hash('sha256', trim($idempotencyKey))]=self::jsonSafe($idempotencyResult);
			if(count($idempotency)>128){
				$idempotency=array_slice($idempotency, -128, null, true);
			}
		}
		$now=$events!==[] ? $events[count($events)-1]->occurredAt() : gmdate('c');
		return new self(
			$this->definition, $this->id,
			WorkflowState::normalize((string)($changes['state'] ?? $this->state)),
			self::jsonSafe(is_array($changes['data'] ?? null) ? $changes['data'] : $this->data),
			$this->version+1,
			array_key_exists('assigned_to', $changes) ? ($changes['assigned_to']===null ? null : trim((string)$changes['assigned_to'])) : $this->assignedTo,
			is_array($changes['assigned_roles'] ?? null) ? $changes['assigned_roles'] : $this->assignedRoles,
			array_key_exists('deadline_at', $changes) ? ($changes['deadline_at']===null ? null : (string)$changes['deadline_at']) : $this->deadlineAt,
			array_key_exists('pending_approval', $changes) ? (is_array($changes['pending_approval']) ? self::jsonSafe($changes['pending_approval']) : null) : $this->pendingApproval,
			$history, $idempotency,
			self::jsonSafe(is_array($changes['metadata'] ?? null) ? $changes['metadata'] : $this->metadata),
			$this->createdAt, $now
		);
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_workflow_record', 'definition'=>$this->definition, 'id'=>$this->id,
			'state'=>$this->state, 'data'=>$this->data, 'version'=>$this->version,
			'assigned_to'=>$this->assignedTo, 'assigned_roles'=>$this->assignedRoles,
			'deadline_at'=>$this->deadlineAt, 'overdue'=>$this->isOverdue(),
			'pending_approval'=>$this->pendingApproval,
			'history'=>array_map(static fn(WorkflowEvent $event): array=>$event->jsonSerialize(), $this->history),
			'history_valid'=>$this->historyValid(), 'idempotency'=>$this->idempotency,
			'metadata'=>$this->metadata, 'created_at'=>$this->createdAt, 'updated_at'=>$this->updatedAt,
		];
	}

	/** Converts arbitrary callback data into deterministic, persistence-safe JSON values. */
	public static function jsonSafe(mixed $value, int $depth=0): mixed {
		if($depth>24){
			return '[maximum depth]';
		}
		if($value===null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)){
			return is_float($value) && !is_finite($value) ? (string)$value : $value;
		}
		if($value instanceof \DateTimeInterface){
			return $value->format('c');
		}
		if($value instanceof \JsonSerializable){
			return self::jsonSafe($value->jsonSerialize(), $depth+1);
		}
		if(is_object($value)){
			return ['class'=>$value::class, 'value'=>self::jsonSafe(get_object_vars($value), $depth+1)];
		}
		if(is_resource($value)){
			return ['resource'=>get_resource_type($value)];
		}
		if(is_array($value)){
			$result=[];
			foreach($value as $key=>$nested){
				$result[is_int($key) ? $key : (string)$key]=self::jsonSafe($nested, $depth+1);
			}
			return $result;
		}
		return (string)$value;
	}
}
