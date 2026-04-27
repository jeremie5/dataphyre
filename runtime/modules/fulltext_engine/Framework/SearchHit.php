<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class SearchHit implements \JsonSerializable {

	public function __construct(
		private readonly string $id,
		private readonly float $score
	){}

	public function id(): string {
		return $this->id;
	}

	public function key(): string {
		return $this->id;
	}

	public function score(): float {
		return $this->score;
	}

	public function toArray(): array {
		return [
			'id'=>$this->id,
			'score'=>$this->score,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
