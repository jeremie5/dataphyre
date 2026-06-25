<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Search hit paired with the document resolved from its index reference.
 *
 * Full-text search first returns lightweight `SearchHit` records containing
 * identity, key, and relevance score. Hydration attaches the domain document
 * that key points at and records whether resolution succeeded, allowing result
 * pipelines to preserve ranking information even when an indexed document has
 * been deleted or cannot be loaded.
 */
final class HydratedSearchHit implements \JsonSerializable {

	/**
	 * Captures a search hit and its hydration outcome.
	 *
	 * `$document` is caller-shaped because each search index can hydrate into a
	 * different domain object or array shape. `$resolved` is the authoritative
	 * signal for whether the document should be trusted as a live record.
	 *
	 * @param SearchHit $hit Raw index hit with id, key, and score.
	 * @param mixed $document Hydrated document, or miss placeholder supplied by the resolver.
	 * @param bool $resolved Whether the resolver found a live document for the hit.
	 */
	public function __construct(
		private readonly SearchHit $hit,
		private readonly mixed $document,
		private readonly bool $resolved
	){}

	/** @var array{id:string, score:float, resolved:bool, document:mixed}|null */
	private ?array $arrayPayload=null;

	/**
	 * Returns the raw search hit that produced this hydrated result.
	 *
	 * @return SearchHit Index hit containing stable identity and ranking data.
	 */
	public function hit(): SearchHit {
		return $this->hit;
	}

	/**
	 * Returns the stable index hit id.
	 *
	 * @return string Hit id from the raw search result.
	 */
	public function id(): string {
		return $this->hit->id();
	}

	/**
	 * Returns the document lookup key associated with the hit.
	 *
	 * @return string Search index key used by hydration to locate the document.
	 */
	public function key(): string {
		return $this->hit->key();
	}

	/**
	 * Returns the relevance score assigned by the search engine.
	 *
	 * @return float Score preserved from the raw hit for ranking and diagnostics.
	 */
	public function score(): float {
		return $this->hit->score();
	}

	/**
	 * Returns the document supplied by the hydration resolver.
	 *
	 * @return mixed Domain document, array data, or miss placeholder associated with the hit.
	 */
	public function document(): mixed {
		return $this->document;
	}

	/**
	 * Reports whether hydration found a live document.
	 *
	 * @return bool `true` when `document()` represents a resolved live document.
	 */
	public function resolved(): bool {
		return $this->resolved;
	}

	/**
	 * Reports whether the indexed document could not be resolved.
	 *
	 *
	 * @return bool `true` when the hit remains in the index but no live document was found.
	 */
	public function missing(): bool {
		return !$this->resolved;
	}

	/**
	 * Serializes the hydrated hit for APIs and diagnostics.
	 *
	 * @return array{id:string, score:float, resolved:bool, document:mixed} Ranking and hydration state.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		return $this->arrayPayload=[
			'id'=>$this->id(),
			'score'=>$this->score(),
			'resolved'=>$this->resolved,
			'document'=>$this->document,
		];
	}

	/**
	 * Serializes ranking and hydration state for JSON output.
	 *
	 * @return array{id:string, score:float, resolved:bool, document:mixed} Ranking and hydration state.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
