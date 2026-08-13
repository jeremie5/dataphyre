<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable, hash-chained workflow audit event.
 */
final class WorkflowEvent implements \JsonSerializable {
	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $diff
	 * @param array<string,mixed> $metadata
	 */
	private function __construct(
		private readonly string $id,
		private readonly string $type,
		private readonly string $actorId,
		private readonly ?string $transition,
		private readonly string $stateBefore,
		private readonly string $stateAfter,
		private readonly array $before,
		private readonly array $after,
		private readonly array $diff,
		private readonly array $metadata,
		private readonly string $occurredAt,
		private readonly string $previousHash,
		private readonly string $hash
	){}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $diff
	 * @param array<string,mixed> $metadata
	 */
	public static function make(
		string $type,
		string $actorId,
		string $stateBefore,
		string $stateAfter,
		array $before=[],
		array $after=[],
		array $diff=[],
		?string $transition=null,
		array $metadata=[],
		string $previousHash='',
		?string $occurredAt=null,
		?string $id=null
	): self {
		$type=WorkflowState::normalize($type) ?: 'event';
		$id=trim((string)$id) ?: self::generatedId($type);
		$occurredAt=$occurredAt!==null ? self::date($occurredAt) : gmdate('c');
		$payload=[
			'id'=>$id, 'type'=>$type, 'actor_id'=>trim($actorId),
			'transition'=>$transition===null ? null : WorkflowState::normalize($transition),
			'state_before'=>WorkflowState::normalize($stateBefore),
			'state_after'=>WorkflowState::normalize($stateAfter),
			'before'=>WorkflowRecord::jsonSafe($before), 'after'=>WorkflowRecord::jsonSafe($after),
			'diff'=>WorkflowRecord::jsonSafe($diff), 'metadata'=>WorkflowRecord::jsonSafe($metadata),
			'occurred_at'=>$occurredAt, 'previous_hash'=>strtolower(trim($previousHash)),
		];
		$hash=hash('sha256', self::canonicalJson($payload));
		$arguments=array_values($payload);
		$arguments[]=$hash;
		return new self(...$arguments);
	}

	/** @param array<string,mixed> $event */
	public static function fromArray(array $event): self {
		return new self(
			(string)($event['id'] ?? ''), (string)($event['type'] ?? 'event'),
			(string)($event['actor_id'] ?? ''), isset($event['transition']) ? (string)$event['transition'] : null,
			(string)($event['state_before'] ?? ''), (string)($event['state_after'] ?? ''),
			is_array($event['before'] ?? null) ? $event['before'] : [],
			is_array($event['after'] ?? null) ? $event['after'] : [],
			is_array($event['diff'] ?? null) ? $event['diff'] : [],
			is_array($event['metadata'] ?? null) ? $event['metadata'] : [],
			(string)($event['occurred_at'] ?? ''), (string)($event['previous_hash'] ?? ''),
			(string)($event['hash'] ?? '')
		);
	}

	public function id(): string { return $this->id; }
	public function type(): string { return $this->type; }
	public function actorId(): string { return $this->actorId; }
	public function transition(): ?string { return $this->transition; }
	public function stateBefore(): string { return $this->stateBefore; }
	public function stateAfter(): string { return $this->stateAfter; }
	/** @return array<string,mixed> */
	public function before(): array { return $this->before; }
	/** @return array<string,mixed> */
	public function after(): array { return $this->after; }
	/** @return array<string,mixed> */
	public function diff(): array { return $this->diff; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }
	public function occurredAt(): string { return $this->occurredAt; }
	public function previousHash(): string { return $this->previousHash; }
	public function hash(): string { return $this->hash; }

	public function verify(?string $expectedPreviousHash=null): bool {
		if($expectedPreviousHash!==null && !hash_equals(strtolower($expectedPreviousHash), strtolower($this->previousHash))){
			return false;
		}
		$payload=$this->jsonSerialize();
		unset($payload['hash']);
		return $this->hash!=='' && hash_equals($this->hash, hash('sha256', self::canonicalJson($payload)));
	}

	public function jsonSerialize(): array {
		return [
			'id'=>$this->id, 'type'=>$this->type, 'actor_id'=>$this->actorId,
			'transition'=>$this->transition, 'state_before'=>$this->stateBefore,
			'state_after'=>$this->stateAfter, 'before'=>$this->before, 'after'=>$this->after,
			'diff'=>$this->diff, 'metadata'=>$this->metadata, 'occurred_at'=>$this->occurredAt,
			'previous_hash'=>$this->previousHash, 'hash'=>$this->hash,
		];
	}

	/** @param mixed $value */
	public static function canonicalJson(mixed $value): string {
		$value=self::canonicalize(WorkflowRecord::jsonSafe($value));
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private static function canonicalize(mixed $value): mixed {
		if(!is_array($value)){
			return $value;
		}
		if(!array_is_list($value)){
			ksort($value, SORT_STRING);
		}
		foreach($value as $key=>$nested){
			$value[$key]=self::canonicalize($nested);
		}
		return $value;
	}

	private static function generatedId(string $type): string {
		try{
			$random=bin2hex(random_bytes(10));
		}catch(\Throwable){
			$random=str_replace('.', '', uniqid('', true));
		}
		return $type.'_'.gmdate('YmdHis').'_'.$random;
	}

	private static function date(string $value): string {
		try{
			return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('c');
		}catch(\Throwable){
			return gmdate('c');
		}
	}
}
