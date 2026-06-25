<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Immutable result set returned by the fulltext engine search facade.
 *
 * SearchResults separates the normalized hit list from the kernel's raw response
 * envelope. Countable and iterable behavior reflect the materialized hits
 * available to the caller, while total() preserves the kernel-reported total
 * count so callers can distinguish page size from total matches.
 */
final class SearchResults implements \Countable, \IteratorAggregate, \JsonSerializable {

	/**
	 * Stores normalized search hits and kernel execution metadata.
	 *
	 * @param string $indexName Index that produced the result set.
	 * @param list<SearchHit> $hits Ordered hit objects for this page of results.
	 * @param int $count Kernel-reported total match count.
	 * @param float $certainty Aggregate certainty or confidence score from the kernel.
	 * @param float $time Kernel-reported execution time.
	 * @param array<string, mixed> $rawResponse Original kernel response retained for diagnostics.
	 */
	public function __construct(
		private readonly string $indexName,
		private readonly array $hits,
		private readonly int $count,
		private readonly float $certainty,
		private readonly float $time,
		private readonly array $rawResponse=[]
	){}

	/** @var array{index:string,results:list<array<string,float>>,count:int,certainty:float,time:float}|null */
	private ?array $arrayPayload=null;

	/** @var list<string>|null */
	private ?array $idPayload=null;

	/** @var list<float>|null */
	private ?array $scorePayload=null;

	/**
	 * Converts the legacy kernel search response into typed result objects.
	 *
	 * Kernel results are arrays where each entry contains one id => score pair.
	 * Non-array entries are skipped and the first pair in each result row is
	 * preserved, matching the historical fulltext engine response shape.
	 *
	 * @param string $indexName Index that produced the response.
	 * @param array<string, mixed> $response Raw kernel search response.
	 * @return self Normalized search result set.
	 */
	public static function fromKernelResponse(string $indexName, array $response): self {
		$hits=[];
		$ids=[];
		$scores=[];
		$results=[];
		foreach(($response['results'] ?? []) as $result){
			if(!is_array($result)){
				continue;
			}
			foreach($result as $id=>$score){
				$id=(string)$id;
				$score=(float)$score;
				$hits[]=new SearchHit($id, $score);
				$ids[]=$id;
				$scores[]=$score;
				$results[]=[$id=>$score];
				break;
			}
		}
		$searchResults=new self(
			$indexName,
			$hits,
			(int)($response['count'] ?? count($hits)),
			(float)($response['certainty'] ?? 0.0),
			(float)($response['time'] ?? 0.0),
			$response
		);
		$searchResults->idPayload=$ids;
		$searchResults->scorePayload=$scores;
		$searchResults->arrayPayload=[
			'index'=>$searchResults->indexName,
			'results'=>$results,
			'count'=>$searchResults->count,
			'certainty'=>$searchResults->certainty,
			'time'=>$searchResults->time,
		];
		return $searchResults;
	}

	/**
	 * Returns the searched index name.
	 *
	 * @return string Fulltext index name.
	 */
	public function indexName(): string {
		return $this->indexName;
	}

	/**
	 * Returns the number of materialized hits in this result object.
	 *
	 * @return int Page hit count used by Countable.
	 */
	public function count(): int {
		return count($this->hits);
	}

	/**
	 * Returns the kernel-reported total match count.
	 *
	 * @return int Total matches, which may be larger than hitCount() for paged results.
	 */
	public function total(): int {
		return $this->count;
	}

	/**
	 * Returns the number of SearchHit objects available in this result object.
	 *
	 * @return int Materialized hit count.
	 */
	public function hitCount(): int {
		return count($this->hits);
	}

	/**
	 * Returns the aggregate certainty score supplied by the search kernel.
	 *
	 * @return float Certainty score, defaulting to 0.0 when the kernel omits it.
	 */
	public function certainty(): float {
		return $this->certainty;
	}

	/**
	 * Returns the kernel-reported search execution time.
	 *
	 * @return float Search time in the units emitted by the kernel response.
	 */
	public function time(): float {
		return $this->time;
	}

	/**
	 * Returns the ordered hit objects for this result page.
	 *
	 * @return list<SearchHit> Search hits in score/order sequence.
	 */
	public function hits(): array {
		return $this->hits;
	}

	/**
	 * Returns the first materialized hit.
	 *
	 * @return ?SearchHit Highest-priority hit, or null when no hits are present.
	 */
	public function first(): ?SearchHit {
		return $this->hits[0] ?? null;
	}

	/**
	 * Reports whether the kernel total indicates no matches.
	 *
	 * @return bool True when total() is zero.
	 */
	public function isEmpty(): bool {
		return $this->count===0;
	}

	/**
	 * Reports whether the kernel total indicates at least one match.
	 *
	 * @return bool True when total() is greater than zero.
	 */
	public function isNotEmpty(): bool {
		return !$this->isEmpty();
	}

	/**
	 * Returns the hit identifiers in result order.
	 *
	 * @return list<string> Search hit ids.
	 */
	public function ids(): array {
		if($this->idPayload!==null){
			return $this->idPayload;
		}
		$ids=[];
		foreach($this->hits as $hit){
			$ids[]=$hit->id();
		}
		return $this->idPayload=$ids;
	}

	/**
	 * Returns the hit scores in result order.
	 *
	 * @return list<float> Search hit scores.
	 */
	public function scores(): array {
		if($this->scorePayload!==null){
			return $this->scorePayload;
		}
		$scores=[];
		foreach($this->hits as $hit){
			$scores[]=$hit->score();
		}
		return $this->scorePayload=$scores;
	}

	/**
	 * Returns the raw kernel response retained for diagnostics or compatibility.
	 *
	 * @return array<string, mixed> Original search kernel response.
	 */
	public function raw(): array {
		return $this->rawResponse;
	}

	/**
	 * Resolves hit identifiers into application records.
	 *
	 * Search::hydrate() owns resolver interpretation; this method preserves the fluent object boundary so callers can
	 * pass a SearchResults value directly into record hydration.
	 *
	 * @param mixed $resolver Optional resolver accepted by Search::hydrate().
	 * @return HydratedSearchResults Hydrated result wrapper.
	 */
	public function hydrate(mixed $resolver=null): HydratedSearchResults {
		return Search::hydrate($this, $resolver);
	}

	/**
	 * Exports the legacy-compatible search result data.
	 *
	 * @return array{index: string, results: list<array<string, float>>, count: int, certainty: float, time: float}
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$results=[];
		foreach($this->hits as $hit){
			$results[]=[$hit->id()=>$hit->score()];
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
	 * Iterates over materialized SearchHit objects.
	 *
	 * @return \Traversable<int, SearchHit> Iterator over hits in result order.
	 */
	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->hits);
	}

	/**
	 * Exposes the legacy-compatible search result data to json_encode().
	 *
	 * @return array{index: string, results: list<array<string, float>>, count: int, certainty: float, time: float}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
