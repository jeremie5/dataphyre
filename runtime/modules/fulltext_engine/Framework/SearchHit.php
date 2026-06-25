<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Represents a ranked full-text search result.
 *
 * SearchHit carries the indexed record identifier and the engine-calculated
 * score. It is intentionally small and serializable so search adapters can
 * return consistent result rows regardless of the underlying backend.
 */
final class SearchHit implements \JsonSerializable {

	/**
	 * Creates a search hit value.
	 *
	 * @param string $id Indexed record identifier.
	 * @param float $score Relevance score reported by the search backend.
	 */
	public function __construct(
		private readonly string $id,
		private readonly float $score
	){}

	/**
	 * Returns the indexed record identifier.
	 *
	 *
	 * @return string Record identifier.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the record key alias.
	 *
	 * `key()` mirrors id() for callers that use key terminology when mapping hits
	 * back to records.
	 *
	 * @return string Record identifier.
	 */
	public function key(): string {
		return $this->id;
	}

	/**
	 * Returns the relevance score.
	 *
	 *
	 * @return float Search backend relevance score.
	 */
	public function score(): float {
		return $this->score;
	}

	/**
	 * Returns the hit identifier and score for search result lists.
	 *
	 * @return array{id: string, score: float}
	 */
	public function toArray(): array {
		return [
			'id'=>$this->id,
			'score'=>$this->score,
		];
	}

	/**
	 * Serializes the hit for JSON result sets.
	 *
	 * @return array{id: string, score: float}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
