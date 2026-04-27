<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class HydratedSearchHit implements \JsonSerializable {

	public function __construct(
		private readonly SearchHit $hit,
		private readonly mixed $document,
		private readonly bool $resolved
	){}

	public function hit(): SearchHit {
		return $this->hit;
	}

	public function id(): string {
		return $this->hit->id();
	}

	public function key(): string {
		return $this->hit->key();
	}

	public function score(): float {
		return $this->hit->score();
	}

	public function document(): mixed {
		return $this->document;
	}

	public function resolved(): bool {
		return $this->resolved;
	}

	public function missing(): bool {
		return !$this->resolved;
	}

	public function toArray(): array {
		return [
			'id'=>$this->id(),
			'score'=>$this->score(),
			'resolved'=>$this->resolved,
			'document'=>$this->document,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
