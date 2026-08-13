<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable bulk relation command with concurrency and idempotency metadata. */
final class PanelRelationWorkspaceCommand implements \JsonSerializable {
	private const OPERATIONS=['attach', 'detach', 'update_pivot', 'reorder', 'restore'];

	private function __construct(
		private readonly string $operation,
		private readonly array $keys,
		private readonly array $values,
		private readonly ?int $expectedVersion,
		private readonly string $idempotencyKey,
		private readonly ?string $actor,
		private readonly array $metadata
	){}

	public static function make(string $operation, array|string|int $keys=[], array $values=[], array $options=[]): self {
		$operation=strtolower(trim($operation));
		if(!in_array($operation, self::OPERATIONS, true)){ throw new \InvalidArgumentException('Unsupported relation workspace operation: '.$operation); }
		$keys=is_array($keys) ? $keys : [$keys];
		$keys=array_values(array_unique(array_filter(array_map('strval', $keys), static fn(string $key): bool => $key!=='')));
		$id=trim((string)($options['idempotency_key'] ?? ''));
		if($id===''){ $id='relation:'.$operation.':'.hash('sha256', json_encode([$keys, $values, microtime(true)], JSON_THROW_ON_ERROR)); }
		return new self(
			$operation, $keys, $values,
			isset($options['expected_version']) ? max(0, (int)$options['expected_version']) : null,
			$id,
			isset($options['actor']) ? trim((string)$options['actor']) ?: null : null,
			is_array($options['metadata'] ?? null) ? $options['metadata'] : []
		);
	}

	public function operation(): string { return $this->operation; }
	public function keys(): array { return $this->keys; }
	public function values(): array { return $this->values; }
	public function expectedVersion(): ?int { return $this->expectedVersion; }
	public function idempotencyKey(): string { return $this->idempotencyKey; }
	public function actor(): ?string { return $this->actor; }
	public function metadata(): array { return $this->metadata; }
	public function jsonSerialize(): array { return ['operation'=>$this->operation, 'keys'=>$this->keys, 'values'=>$this->values, 'expected_version'=>$this->expectedVersion, 'idempotency_key'=>$this->idempotencyKey, 'actor'=>$this->actor, 'metadata'=>$this->metadata]; }
}
