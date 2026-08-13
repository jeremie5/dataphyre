<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One ordered change-feed entry from a subscribable Panel data source. */
final class PanelDataChange implements \JsonSerializable {
	/** @param array<string, mixed> $metadata */
	public function __construct(
		private readonly int $sequence,
		private readonly string $operation,
		private readonly string|int $key,
		private readonly mixed $before,
		private readonly mixed $after,
		private readonly string $occurredAt,
		private readonly array $metadata=[]
	){
		if($sequence<1){ throw new \InvalidArgumentException('Panel data change sequence must be positive.'); }
		if(!in_array($operation, ['insert', 'update', 'delete', 'replace'], true)){ throw new \InvalidArgumentException("Unsupported Panel data change operation '{$operation}'."); }
	}
	public function sequence(): int { return $this->sequence; }
	public function operation(): string { return $this->operation; }
	public function key(): string|int { return $this->key; }
	public function before(): mixed { return $this->before; }
	public function after(): mixed { return $this->after; }
	/** @return array<string, mixed> */
	public function jsonSerialize(): array { return ['sequence'=>$this->sequence, 'operation'=>$this->operation, 'key'=>$this->key, 'before'=>$this->before, 'after'=>$this->after, 'occurred_at'=>$this->occurredAt, 'metadata'=>$this->metadata]; }
}
