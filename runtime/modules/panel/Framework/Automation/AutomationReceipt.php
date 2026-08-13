<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Durable, redacted action execution receipt and rollback reference.
 */
final class AutomationReceipt implements \JsonSerializable {
	/**
	 * @param array<string,mixed> $input
	 * @param mixed $result
	 * @param list<string> $rollbackInstructions
	 * @param array<string,mixed> $metadata
	 */
	private function __construct(
		private readonly string $id,
		private readonly string $action,
		private readonly string $actionVersion,
		private readonly string $status,
		private readonly string $actorId,
		private readonly ?string $idempotencyHash,
		private readonly array $input,
		private readonly string $planHash,
		private readonly mixed $result,
		private readonly ?string $error,
		private readonly array $rollbackInstructions,
		private readonly ?string $parentReceiptId,
		private readonly array $metadata,
		private readonly string $startedAt,
		private readonly string $completedAt
	){}

	/** @param array<string,mixed> $input @param list<string> $rollbackInstructions @param array<string,mixed> $metadata */
	public static function create(
		AutomationAction $action,
		string $status,
		WorkflowActor $actor,
		?string $idempotencyKey,
		array $input,
		string $planHash,
		mixed $result=null,
		?string $error=null,
		array $rollbackInstructions=[],
		?string $parentReceiptId=null,
		array $metadata=[],
		?string $startedAt=null,
		?string $completedAt=null
	): self {
		$startedAt=$startedAt ?? gmdate('c');
		$completedAt=$completedAt ?? gmdate('c');
		return new self(
			'receipt_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(10)), $action->name(), $action->versionValue(),
			WorkflowState::normalize($status) ?: 'completed', $actor->id(),
			$idempotencyKey===null || trim($idempotencyKey)==='' ? null : hash('sha256', trim($idempotencyKey)),
			self::redact(WorkflowRecord::jsonSafe($input)), strtolower(trim($planHash)),
			self::redact(WorkflowRecord::jsonSafe($result)), $error===null ? null : trim($error),
			array_values($rollbackInstructions), $parentReceiptId, self::redact(WorkflowRecord::jsonSafe($metadata)),
			$startedAt, $completedAt
		);
	}

	/** @param array<string,mixed> $receipt */
	public static function fromArray(array $receipt): self {
		return new self(
			(string)($receipt['id'] ?? ''), (string)($receipt['action'] ?? ''), (string)($receipt['action_version'] ?? '1'),
			(string)($receipt['status'] ?? 'failed'), (string)($receipt['actor_id'] ?? ''),
			isset($receipt['idempotency_hash']) ? (string)$receipt['idempotency_hash'] : null,
			is_array($receipt['input'] ?? null) ? $receipt['input'] : [], (string)($receipt['plan_hash'] ?? ''),
			$receipt['result'] ?? null, isset($receipt['error']) ? (string)$receipt['error'] : null,
			is_array($receipt['rollback_instructions'] ?? null) ? $receipt['rollback_instructions'] : [],
			isset($receipt['parent_receipt_id']) ? (string)$receipt['parent_receipt_id'] : null,
			is_array($receipt['metadata'] ?? null) ? $receipt['metadata'] : [],
			(string)($receipt['started_at'] ?? ''), (string)($receipt['completed_at'] ?? '')
		);
	}

	public function id(): string { return $this->id; }
	public function action(): string { return $this->action; }
	public function actionVersion(): string { return $this->actionVersion; }
	public function status(): string { return $this->status; }
	public function actorId(): string { return $this->actorId; }
	public function idempotencyHash(): ?string { return $this->idempotencyHash; }
	/** @return array<string,mixed> */
	public function input(): array { return $this->input; }
	public function planHash(): string { return $this->planHash; }
	public function result(): mixed { return $this->result; }
	public function error(): ?string { return $this->error; }
	/** @return list<string> */
	public function rollbackInstructions(): array { return $this->rollbackInstructions; }
	public function parentReceiptId(): ?string { return $this->parentReceiptId; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }
	public function startedAt(): string { return $this->startedAt; }
	public function completedAt(): string { return $this->completedAt; }
	public function ok(): bool { return in_array($this->status, ['completed','rolled_back'], true); }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_automation_receipt', 'id'=>$this->id, 'action'=>$this->action,
			'action_version'=>$this->actionVersion, 'status'=>$this->status, 'ok'=>$this->ok(),
			'actor_id'=>$this->actorId, 'idempotency_hash'=>$this->idempotencyHash,
			'input'=>$this->input, 'plan_hash'=>$this->planHash, 'result'=>$this->result,
			'error'=>$this->error, 'rollback_instructions'=>$this->rollbackInstructions,
			'rollback_available'=>$this->rollbackInstructions!==[], 'parent_receipt_id'=>$this->parentReceiptId,
			'metadata'=>$this->metadata, 'started_at'=>$this->startedAt, 'completed_at'=>$this->completedAt,
		];
	}

	/** Redacts common secret-bearing keys recursively before persistence. */
	public static function redact(mixed $value): mixed {
		if(!is_array($value)){ return $value; }
		$result=[];
		foreach($value as $key=>$nested){
			$name=strtolower((string)$key);
			$sensitive=preg_match('/(?:password|passwd|secret|token|credential|private[_-]?key|authorization|cookie)/', $name)===1;
			$result[$key]=$sensitive ? '[redacted]' : self::redact($nested);
		}
		return $result;
	}
}
