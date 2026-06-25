<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Hydrated view of full-text search results.
 *
 * `HydratedSearchResults` keeps the original engine-level metrics from
 * `SearchResults` while replacing raw hits with `HydratedSearchHit` objects that
 * may contain application documents. The object preserves missing-document
 * information so callers can distinguish "matched by the index" from "hydrated
 * from storage successfully".
 */
final class HydratedSearchResults implements \Countable, \IteratorAggregate, \JsonSerializable {

	/**
	 * Creates a hydrated result set for one full-text index.
	 *
	 * `count` is the total reported by the search engine before hydration; the
	 * number of hydrated hit objects may be lower or may include hits whose
	 * document is missing. `certainty` and `time` are engine metrics carried
	 * through unchanged for diagnostics and ranking UI.
	 *
	 * @param string $indexName Index that produced the search results.
	 * @param array<int,HydratedSearchHit> $hits Hydrated hits in engine order.
	 * @param int $count Engine-reported total result count.
	 * @param float $certainty Engine-reported certainty or score confidence.
	 * @param float $time Engine-reported search duration.
	 * @param ?IndexDefinition $definition Optional index definition used during hydration.
	 * @param array<string,mixed> $rawResponse Original engine response data.
	 */
	public function __construct(
		private readonly string $indexName,
		private readonly array $hits,
		private readonly int $count,
		private readonly float $certainty,
		private readonly float $time,
		private readonly ?IndexDefinition $definition=null,
		private readonly array $rawResponse=[]
	){}

	/** @var array<string,mixed>|null */
	private ?array $arrayPayload=null;

	/** @var array<int,mixed>|null */
	private ?array $documentPayload=null;

	/** @var array<int,string>|null */
	private ?array $missingIdPayload=null;

	/**
	 * Creates hydrated results from a raw search result envelope.
	 *
	 * The raw result contributes index name, total, certainty, timing, and raw
	 * response data. The provided hits are assumed to already be hydrated and kept
	 * in their existing order.
	 *
	 * @param SearchResults $results Engine result envelope before hydration.
	 * @param array<int,HydratedSearchHit> $hits Hydrated hits in engine order.
	 * @param ?IndexDefinition $definition Optional index definition used for hydration.
	 * @return self Hydrated result set preserving engine metrics.
	 */
	public static function fromResults(SearchResults $results, array $hits, ?IndexDefinition $definition=null): self {
		return new self(
			$results->indexName(),
			$hits,
			$results->total(),
			$results->certainty(),
			$results->time(),
			$definition,
			$results->raw()
		);
	}

	/**
	 * Returns the full-text index that produced these results.
	 *
	 *
	 * @return string Index name.
	 */
	public function indexName(): string {
		return $this->indexName;
	}

	/**
	 * Returns the index definition used during hydration when available.
	 *
	 * @return ?IndexDefinition Index definition or null when hydration was not tied to a definition object.
	 */
	public function definition(): ?IndexDefinition {
		return $this->definition;
	}

	/**
	 * Counts hydrated hit objects currently held by this result set.
	 *
	 * This is not necessarily the same as `total()`, which is the engine-reported
	 * total before document hydration.
	 *
	 * @return int Number of hydrated hit objects.
	 */
	public function count(): int {
		return count($this->hits);
	}

	/**
	 * Returns the engine-reported total result count.
	 *
	 * The total can be greater than `count()` when pagination, limits, or
	 * hydration failures mean only part of the result set is represented locally.
	 *
	 * @return int Total matches reported by the search engine.
	 */
	public function total(): int {
		return $this->count;
	}

	/**
	 * Returns the engine-reported certainty metric.
	 *
	 * The scale is defined by the backing engine; Dataphyre carries it through for
	 * UI and diagnostics without normalizing it.
	 *
	 * @return float Search certainty or confidence value.
	 */
	public function certainty(): float {
		return $this->certainty;
	}

	/**
	 * Returns the engine-reported search duration.
	 *
	 *
	 * @return float Search duration as reported by the engine.
	 */
	public function time(): float {
		return $this->time;
	}

	/**
	 * Returns hydrated hits in result order.
	 *
	 * @return array<int,HydratedSearchHit> Hydrated hit objects.
	 */
	public function hits(): array {
		return $this->hits;
	}

	/**
	 * Returns the first hydrated hit.
	 *
	 *
	 * @return ?HydratedSearchHit First hit, or null when the result set is empty.
	 */
	public function first(): ?HydratedSearchHit {
		return $this->hits[0] ?? null;
	}

	/**
	 * Returns hydrated documents from each hit.
	 *
	 * Missing hits return their hit document value, which may be null depending on
	 * `HydratedSearchHit` construction. Use `missingIds()` to inspect hydration
	 * gaps explicitly.
	 *
	 * @return array<int,mixed> Documents in hit order.
	 */
	public function documents(): array {
		if($this->documentPayload!==null){
			return $this->documentPayload;
		}
		$documents=[];
		$missingIds=$this->missingIdPayload===null ? [] : null;
		foreach($this->hits as $hit){
			$documents[]=$hit->document();
			if($missingIds!==null && $hit->missing()){
				$missingIds[]=$hit->id();
			}
		}
		if($missingIds!==null){
			$this->missingIdPayload=$missingIds;
		}
		return $this->documentPayload=$documents;
	}

	/**
	 * Returns ids that matched the index but could not be hydrated.
	 *
	 * Missing ids are useful for index repair jobs because they identify stale
	 * search entries whose backing documents are no longer available.
	 *
	 * @return array<int,string> Missing document ids in hit order.
	 */
	public function missingIds(): array {
		if($this->missingIdPayload!==null){
			return $this->missingIdPayload;
		}
		$ids=[];
		foreach($this->hits as $hit){
			if($hit->missing()){
				$ids[]=$hit->id();
			}
		}
		return $this->missingIdPayload=$ids;
	}

	/**
	 * Returns the original raw search response.
	 *
	 * The response is not normalized and may contain engine-specific fields.
	 *
	 * @return array<string,mixed> Raw engine response data.
	 */
	public function raw(): array {
		return $this->rawResponse;
	}

	/**
	 * Iterates hydrated hits in result order.
	 *
	 *
	 * @return \Traversable<int,HydratedSearchHit> Iterator over hydrated hits.
	 */
	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->hits);
	}

	/**
	 * Returns hydrated hits with engine totals and timing.
	 *
	 * The array includes the index name, hydrated hit arrays, engine total,
	 * certainty, and timing. It intentionally omits the raw response unless
	 * callers explicitly request `raw()`.
	 *
	 * @return array<string,mixed> Hydrated result summary.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$results=[];
		foreach($this->hits as $hit){
			$results[]=$hit->toArray();
		}
		return $this->arrayPayload=[
			'index'=>$this->indexName,
			'results'=>$results,
			'count'=>$this->count,
			'certainty'=>$this->certainty,
			'time'=>$this->time,
		];
	}

	/**
	 * Serializes hydrated results for JSON output.
	 *
	 * @return array<string,mixed> Hydrated result summary.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
