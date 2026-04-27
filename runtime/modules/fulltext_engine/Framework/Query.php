<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class Query {

	/** @var array<string, string> */
	private array $criteria=[];

	private ?string $language=null;
	private ?int $max_results=null;
	private ?bool $boolean_mode=null;
	private ?float $threshold=null;
	private ?string $forced_algorithms=null;

	public function __construct(
		private readonly SearchManager $manager,
		private readonly string $index_name
	){}

	public function index(): string {
		return $this->index_name;
	}

	public function where(string $field, string $value): self {
		$this->criteria[$field]=$value;
		return $this;
	}

	public function terms(array $criteria): self {
		foreach($criteria as $field=>$value){
			$this->criteria[(string)$field]=(string)$value;
		}
		return $this;
	}

	public function replace(array $criteria): self {
		$this->criteria=[];
		return $this->terms($criteria);
	}

	public function language(string $language): self {
		$this->language=$language;
		return $this;
	}

	public function limit(int $max_results): self {
		$this->max_results=$max_results;
		return $this;
	}

	public function boolean(bool $boolean_mode=true): self {
		$this->boolean_mode=$boolean_mode;
		return $this;
	}

	public function threshold(float $threshold): self {
		$this->threshold=$threshold;
		return $this;
	}

	public function algorithms(string $forced_algorithms): self {
		$this->forced_algorithms=$forced_algorithms;
		return $this;
	}

	public function criteria(): array {
		return $this->criteria;
	}

	public function fingerprintPayload(): array {
		return [
			'type'=>'search_query',
			'index'=>$this->index_name,
			'criteria'=>$this->criteria,
			'language'=>$this->language,
			'max_results'=>$this->max_results,
			'boolean_mode'=>$this->boolean_mode,
			'threshold'=>$this->threshold,
			'forced_algorithms'=>$this->forced_algorithms,
		];
	}

	public function fingerprint(): string {
		$payload=$this->fingerprintPayload();
		$encoded=json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		return sha1($encoded!==false ? $encoded : serialize($payload));
	}

	public function executionState(): array {
		$payload=$this->fingerprintPayload();
		$state=$payload;
		unset($state['type']);
		$state['fingerprint_payload']=$payload;
		$encoded=json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		$state['fingerprint']=sha1($encoded!==false ? $encoded : serialize($payload));
		return $state;
	}

	public static function fromExecutionState(array $state): self {
		$index_name=trim((string)($state['index'] ?? ''));
		if($index_name===''){
			throw new \InvalidArgumentException('Search query execution state requires an index name.');
		}
		$query=\Dataphyre\FulltextEngine\Search::query($index_name);
		$query->criteria=is_array($state['criteria'] ?? null) ? $state['criteria'] : [];
		$query->language=is_string($state['language'] ?? null) && trim((string)$state['language'])!==''
			? trim((string)$state['language'])
			: null;
		$query->max_results=is_int($state['max_results'] ?? null) ? $state['max_results'] : null;
		$query->boolean_mode=is_bool($state['boolean_mode'] ?? null) ? $state['boolean_mode'] : null;
		$query->threshold=is_numeric($state['threshold'] ?? null) ? (float)$state['threshold'] : null;
		$query->forced_algorithms=is_string($state['forced_algorithms'] ?? null) && trim((string)$state['forced_algorithms'])!==''
			? trim((string)$state['forced_algorithms'])
			: null;
		return $query;
	}

	public function raw(): bool|array {
		return $this->manager->rawSearch(
			$this->index_name,
			$this->criteria,
			$this->language,
			$this->max_results,
			$this->boolean_mode,
			$this->threshold,
			$this->forced_algorithms
		);
	}

	public function get(): SearchResults {
		return $this->manager->search(
			$this->index_name,
			$this->criteria,
			$this->language,
			$this->max_results,
			$this->boolean_mode,
			$this->threshold,
			$this->forced_algorithms
		);
	}

	public function hydrate(mixed $resolver=null): HydratedSearchResults {
		return $this->manager->hydrate($this->get(), $resolver);
	}

	public function first(): ?SearchHit {
		return $this->get()->first();
	}
}
