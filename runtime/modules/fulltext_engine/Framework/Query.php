<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Mutable fulltext search query builder for one index.
 *
 * Query collects search criteria and execution options, then delegates execution
 * to SearchManager. It also produces deterministic fingerprint and execution-state
 * data so runtime tracing, cache identity, and delayed execution can describe
 * the exact search intent without re-inspecting the builder internals.
 */
final class Query {

	/** @var array<string, string> */
	private array $criteria=[];

	private ?string $language=null;
	private ?int $maxResults=null;
	private ?bool $booleanMode=null;
	private ?float $threshold=null;
	private ?string $forcedAlgorithms=null;

	/**
	 * Creates a query builder bound to a search manager and index.
	 *
	 * The constructor performs no search and no validation. Criteria and options are
	 * accumulated until raw(), get(), hydrate(), or first() executes the query.
	 *
	 * @param readonly SearchManager $manager Manager that owns search execution and hydration.
	 * @param readonly string $indexName Index name targeted by this query.
	 */
	public function __construct(
		private readonly SearchManager $manager,
		private readonly string $indexName
	){}

	/**
	 * Returns the target index name.
	 *
	 * @return string Index passed to SearchManager during execution.
	 */
	public function index(): string {
		return $this->indexName;
	}

	/**
	 * Adds or replaces one field criterion.
	 *
	 * Criteria values are strings because the fulltext manager treats them as query
	 * text, not typed SQL predicates.
	 *
	 * @param string $field Searchable field or logical criterion name.
	 * @param string $value Query text for the field.
	 * @return self Same builder for fluent chaining.
	 */
	public function where(string $field, string $value): self {
		$this->criteria[$field]=$value;
		return $this;
	}

	/**
	 * Merges multiple criteria into the current query.
	 *
	 * Keys and values are normalized to strings so the fingerprint and manager call
	 * receive a stable criteria shape.
	 *
	 * @param array<string|int, mixed> $criteria Field-to-query-text map.
	 * @return self Same builder for fluent chaining.
	 */
	public function terms(array $criteria): self {
		foreach($criteria as $field=>$value){
			$this->criteria[(string)$field]=(string)$value;
		}
		return $this;
	}

	/**
	 * Replaces all existing criteria with a new criteria map.
	 *
	 * @param array<string|int, mixed> $criteria Replacement field-to-query-text map.
	 * @return self Same builder for fluent chaining.
	 */
	public function replace(array $criteria): self {
		$this->criteria=[];
		return $this->terms($criteria);
	}

	/**
	 * Sets a language override for stemming/tokenization.
	 *
	 * @param string $language Language code understood by the configured search backend.
	 * @return self Same builder for fluent chaining.
	 */
	public function language(string $language): self {
		$this->language=$language;
		return $this;
	}

	/**
	 * Sets the maximum number of search hits to request.
	 *
	 * @param int $maxResults Result limit passed through to SearchManager.
	 * @return self Same builder for fluent chaining.
	 */
	public function limit(int $maxResults): self {
		$this->maxResults=$maxResults;
		return $this;
	}

	/**
	 * Sets boolean-mode search behavior.
	 *
	 * @param bool $booleanMode Whether compatible algorithms should interpret terms in boolean mode.
	 * @return self Same builder for fluent chaining.
	 */
	public function boolean(bool $booleanMode=true): self {
		$this->booleanMode=$booleanMode;
		return $this;
	}

	/**
	 * Sets the minimum accepted search score.
	 *
	 * @param float $threshold Score threshold passed to SearchManager.
	 * @return self Same builder for fluent chaining.
	 */
	public function threshold(float $threshold): self {
		$this->threshold=$threshold;
		return $this;
	}

	/**
	 * Forces one or more search algorithms for this query.
	 *
	 * @param string $forcedAlgorithms Algorithm selector string understood by SearchManager.
	 * @return self Same builder for fluent chaining.
	 */
	public function algorithms(string $forcedAlgorithms): self {
		$this->forcedAlgorithms=$forcedAlgorithms;
		return $this;
	}

	/**
	 * Returns the current criteria map.
	 *
	 * @return array<string, string> Field-to-query-text criteria used for execution.
	 */
	public function criteria(): array {
		return $this->criteria;
	}

	/**
	 * Returns deterministic query identity data.
	 *
	 * The array intentionally includes every execution-affecting option so cache
	 * identity and trace grouping do not collapse distinct searches.
	 *
	 * @return array<string, mixed> Stable fingerprint source data.
	 */
	public function fingerprintPayload(): array {
		return [
			'type'=>'search_query',
			'index'=>$this->indexName,
			'criteria'=>$this->criteria,
			'language'=>$this->language,
			'max_results'=>$this->maxResults,
			'boolean_mode'=>$this->booleanMode,
			'threshold'=>$this->threshold,
			'forced_algorithms'=>$this->forcedAlgorithms,
		];
	}

	/**
	 * Returns a SHA-1 fingerprint for the current query intent.
	 *
	 * @return string Stable query fingerprint derived from fingerprintPayload().
	 */
	public function fingerprint(): string {
		$payload=$this->fingerprintPayload();
		$encoded=json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		return sha1($encoded!==false ? $encoded : serialize($payload));
	}

	/**
	 * Returns replayable query execution state.
	 *
	 * The state includes direct execution fields, the original fingerprint source,
	 * and the computed fingerprint. It can be persisted by runtime trace consumers
	 * and later restored with fromExecutionState().
	 *
	 * @return array<string, mixed> Replayable query state plus fingerprint metadata.
	 */
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

	/**
	 * Rebuilds a query builder from a captured execution state.
	 *
	 * Only known execution fields are restored. Missing optional fields fall back to
	 * manager defaults when the query is executed.
	 *
	 * @param array<string, mixed> $state State produced by executionState().
	 * @return self Query builder scoped to the captured index.
	 *
	 * @throws \InvalidArgumentException When the state has no index name.
	 */
	public static function fromExecutionState(array $state): self {
		$indexName=trim((string)($state['index'] ?? ''));
		if($indexName===''){
			throw new \InvalidArgumentException('Search query execution state requires an index name.');
		}
		$query=\Dataphyre\FulltextEngine\Search::query($indexName);
		$query->criteria=is_array($state['criteria'] ?? null) ? $state['criteria'] : [];
		$query->language=is_string($state['language'] ?? null) && trim((string)$state['language'])!==''
			? trim((string)$state['language'])
			: null;
		$query->maxResults=is_int($state['max_results'] ?? null) ? $state['max_results'] : null;
		$query->booleanMode=is_bool($state['boolean_mode'] ?? null) ? $state['boolean_mode'] : null;
		$query->threshold=is_numeric($state['threshold'] ?? null) ? (float)$state['threshold'] : null;
		$query->forcedAlgorithms=is_string($state['forced_algorithms'] ?? null) && trim((string)$state['forced_algorithms'])!==''
			? trim((string)$state['forced_algorithms'])
			: null;
		return $query;
	}

	/**
	 * Executes the query and returns the manager's raw backend response.
	 *
	 * @return array|false Raw backend response, or false when SearchManager cannot execute the search.
	 */
	public function raw(): bool|array {
		return $this->manager->rawSearch(
			$this->indexName,
			$this->criteria,
			$this->language,
			$this->maxResults,
			$this->booleanMode,
			$this->threshold,
			$this->forcedAlgorithms
		);
	}

	/**
	 * Executes the query and returns ranked search results.
	 *
	 * @return SearchResults Result wrapper containing hits, scores, and query metadata.
	 */
	public function get(): SearchResults {
		return $this->manager->search(
			$this->indexName,
			$this->criteria,
			$this->language,
			$this->maxResults,
			$this->booleanMode,
			$this->threshold,
			$this->forcedAlgorithms
		);
	}

	/**
	 * Executes the query and hydrates matching documents.
	 *
	 * @param mixed $resolver Optional resolver override accepted by SearchManager.
	 * @return HydratedSearchResults Search hits paired with hydrated domain records.
	 */
	public function hydrate(mixed $resolver=null): HydratedSearchResults {
		return $this->manager->hydrate($this->get(), $resolver);
	}

	/**
	 * Executes the query and returns the first ranked hit.
	 *
	 * @return SearchHit|null First hit, or null when the query has no matches.
	 */
	public function first(): ?SearchHit {
		return $this->get()->first();
	}
}
