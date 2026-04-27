<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class SearchResults implements \Countable, \IteratorAggregate, \JsonSerializable {

	/**
	 * @param array<int, SearchHit> $hits
	 */
	public function __construct(
		private readonly string $index_name,
		private readonly array $hits,
		private readonly int $count,
		private readonly float $certainty,
		private readonly float $time,
		private readonly array $raw_response=[]
	){}

	public static function fromKernelResponse(string $index_name, array $response): self {
		$hits=[];
		foreach(($response['results'] ?? []) as $result){
			if(!is_array($result)){
				continue;
			}
			foreach($result as $id=>$score){
				$hits[]=new SearchHit((string)$id, (float)$score);
				break;
			}
		}
		return new self(
			$index_name,
			$hits,
			(int)($response['count'] ?? count($hits)),
			(float)($response['certainty'] ?? 0.0),
			(float)($response['time'] ?? 0.0),
			$response
		);
	}

	public function indexName(): string {
		return $this->index_name;
	}

	public function count(): int {
		return count($this->hits);
	}

	public function total(): int {
		return $this->count;
	}

	public function hitCount(): int {
		return count($this->hits);
	}

	public function certainty(): float {
		return $this->certainty;
	}

	public function time(): float {
		return $this->time;
	}

	/**
	 * @return array<int, SearchHit>
	 */
	public function hits(): array {
		return $this->hits;
	}

	public function first(): ?SearchHit {
		return $this->hits[0] ?? null;
	}

	public function isEmpty(): bool {
		return $this->count===0;
	}

	public function isNotEmpty(): bool {
		return !$this->isEmpty();
	}

	public function ids(): array {
		return array_map(static fn(SearchHit $hit): string => $hit->id(), $this->hits);
	}

	public function scores(): array {
		return array_map(static fn(SearchHit $hit): float => $hit->score(), $this->hits);
	}

	public function raw(): array {
		return $this->raw_response;
	}

	public function hydrate(mixed $resolver=null): HydratedSearchResults {
		return Search::hydrate($this, $resolver);
	}

	public function toArray(): array {
		return [
			'index'=>$this->index_name,
			'results'=>array_map(static fn(SearchHit $hit): array => [$hit->id()=>$hit->score()], $this->hits),
			'count'=>$this->count,
			'certainty'=>$this->certainty,
			'time'=>$this->time,
		];
	}

	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->hits);
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
