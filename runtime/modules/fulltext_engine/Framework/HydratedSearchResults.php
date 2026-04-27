<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class HydratedSearchResults implements \Countable, \IteratorAggregate, \JsonSerializable {

	/**
	 * @param array<int, HydratedSearchHit> $hits
	 */
	public function __construct(
		private readonly string $index_name,
		private readonly array $hits,
		private readonly int $count,
		private readonly float $certainty,
		private readonly float $time,
		private readonly ?IndexDefinition $definition=null,
		private readonly array $raw_response=[]
	){}

	/**
	 * @param array<int, HydratedSearchHit> $hits
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

	public function indexName(): string {
		return $this->index_name;
	}

	public function definition(): ?IndexDefinition {
		return $this->definition;
	}

	public function count(): int {
		return count($this->hits);
	}

	public function total(): int {
		return $this->count;
	}

	public function certainty(): float {
		return $this->certainty;
	}

	public function time(): float {
		return $this->time;
	}

	/**
	 * @return array<int, HydratedSearchHit>
	 */
	public function hits(): array {
		return $this->hits;
	}

	public function first(): ?HydratedSearchHit {
		return $this->hits[0] ?? null;
	}

	public function documents(): array {
		return array_map(static fn(HydratedSearchHit $hit): mixed => $hit->document(), $this->hits);
	}

	public function missingIds(): array {
		$ids=[];
		foreach($this->hits as $hit){
			if($hit->missing()){
				$ids[]=$hit->id();
			}
		}
		return $ids;
	}

	public function raw(): array {
		return $this->raw_response;
	}

	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->hits);
	}

	public function toArray(): array {
		return [
			'index'=>$this->index_name,
			'results'=>array_map(static fn(HydratedSearchHit $hit): array => $hit->toArray(), $this->hits),
			'count'=>$this->count,
			'certainty'=>$this->certainty,
			'time'=>$this->time,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
