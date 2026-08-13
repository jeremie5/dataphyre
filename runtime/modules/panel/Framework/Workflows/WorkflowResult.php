<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Structured domain result for every workflow mutation and refusal.
 */
final class WorkflowResult implements \JsonSerializable {
	/** @param list<string> $errors @param list<WorkflowEvent> $events @param array<string,mixed> $metadata */
	private function __construct(
		private readonly bool $ok,
		private readonly string $code,
		private readonly string $message,
		private readonly ?WorkflowRecord $record=null,
		private readonly array $events=[],
		private readonly array $errors=[],
		private readonly bool $replayed=false,
		private readonly array $metadata=[]
	){}

	/** @param list<WorkflowEvent> $events @param array<string,mixed> $metadata */
	public static function success(string $code, string $message, ?WorkflowRecord $record=null, array $events=[], array $metadata=[]): self {
		return new self(true, WorkflowState::normalize($code) ?: 'ok', trim($message), $record, $events, [], false, $metadata);
	}

	/** @param list<string> $errors @param array<string,mixed> $metadata */
	public static function failure(string $code, string $message, ?WorkflowRecord $record=null, array $errors=[], array $metadata=[]): self {
		return new self(false, WorkflowState::normalize($code) ?: 'failed', trim($message), $record, [], array_values($errors), false, $metadata);
	}

	public function asReplay(): self {
		return new self($this->ok, $this->code, $this->message, $this->record, $this->events, $this->errors, true, $this->metadata);
	}

	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	public function record(): ?WorkflowRecord { return $this->record; }
	/** @return list<WorkflowEvent> */
	public function events(): array { return $this->events; }
	/** @return list<string> */
	public function errors(): array { return $this->errors; }
	public function replayed(): bool { return $this->replayed; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	/** @return array<string,mixed> */
	public function idempotencySnapshot(?string $fingerprint=null): array {
		return [
			'ok'=>$this->ok, 'code'=>$this->code, 'message'=>$this->message,
			'event_ids'=>array_map(static fn(WorkflowEvent $event): string=>$event->id(), $this->events),
			'record_version'=>$this->record?->version(), 'fingerprint'=>$fingerprint,
			'metadata'=>$this->metadata,
		];
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_workflow_result', 'ok'=>$this->ok, 'code'=>$this->code,
			'message'=>$this->message, 'record'=>$this->record,
			'events'=>$this->events, 'errors'=>$this->errors,
			'replayed'=>$this->replayed, 'metadata'=>$this->metadata,
		];
	}
}
