<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Template binding that resolves a Dataphyre full-text query on demand.
 *
 * The binding clones the query at construction so template execution cannot
 * mutate the caller's query builder, exposes metadata for cache
 * diagnostics, and can derive cache identity either from a query fingerprint or
 * from the query execution state.
 */
final class SearchQueryBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private const SEARCH_QUERY='Dataphyre\\FulltextEngine\\Query';

	/** @var array<string, mixed>|null */
	private ?array $metadataPayload=null;

	/**
	 * Stores a supported search query and normalized binding mode.
	 *
	 * @param object $query Cloned full-text query object used during resolution.
	 * @param string $mode Normalized output mode.
	 * @param array<string, mixed> $options Binding options such as resolver, binding cache, or identity behavior.
	 * @param string $name Stable binding name exposed to template metadata.
	 */
	public function __construct(
		private readonly object $query,
		private readonly string $mode,
		private readonly array $options=[],
		private readonly string $name='search.query.results'
	){}

	/**
	 * Creates a search query binding after validating the query type.
	 *
	 * @param object $query Dataphyre full-text query instance.
	 * @param string $mode Requested resolution mode.
	 * @param array<string, mixed> $options Binding options.
	 * @return self Immutable binding with a cloned query.
	 * @throws \InvalidArgumentException When the object is not a supported search query or the mode is unknown.
	 */
	public static function make(object $query, string $mode='results', array $options=[]): self {
		if(!self::supports($query)){
			throw new \InvalidArgumentException(
				'Templating search bindings require a Dataphyre fulltext Query instance.'
			);
		}

		$mode=self::normalizeMode($mode);
		return new self(
			clone $query,
			$mode,
			$options,
			'search.query.'.$mode
		);
	}

	/**
	 * Checks whether an object is a Dataphyre full-text query.
	 *
	 * @param object $query Candidate query object.
	 * @return bool `true` when the full-text query class exists and the object is an instance of it.
	 */
	public static function supports(object $query): bool {
		return class_exists(self::SEARCH_QUERY) && is_a($query, self::SEARCH_QUERY);
	}

	/**
	 * Selects whether binding cache identity should prefer the query fingerprint.
	 *
	 * @param bool $inherit Whether to use `fingerprint()` when the query exposes one.
	 * @return self New binding with updated identity mode.
	 */
	public function inheritIdentity(bool $inherit=true): self {
		return new self(
			clone $this->query,
			$this->mode,
			array_replace($this->options, ['inherit_query_identity'=>$inherit]),
			$this->name
		);
	}

	/**
	 * Forces cache identity to use execution state instead of query fingerprint.
	 *
	 * @return self New binding with query fingerprint inheritance disabled.
	 */
	public function useExecutionStateIdentity(): self {
		return $this->inheritIdentity(false);
	}

	/**
	 * Returns the stable template binding name.
	 *
	 * @return string Name such as `search.query.results` or `search.query.documents`.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Describes the binding without executing the search query.
	 *
	 * @return array<string, mixed> Metadata including mode, index, fingerprint availability, identity source, options, and persistent cache details.
	 */
	public function metadata(): array {
		if($this->metadataPayload!==null){
			return $this->metadataPayload;
		}
		$queryFingerprint=$this->queryFingerprint();
		$queryIdentityRequested=$this->inheritsQueryIdentity();
		$queryIdentitySource=$this->queryIdentitySource($queryFingerprint);
		return $this->metadataPayload=self::compactPayload([
			'type'=>'search_query',
			'driver'=>'fulltext',
			'query_mode'=>$this->mode,
			'query_class'=>$this->query::class,
			'query_target_type'=>'index',
			'query_target'=>$this->indexName(),
			'query_fingerprint'=>$queryFingerprint,
			'query_identity_mode'=>$queryIdentityRequested ? 'inherit' : 'state',
			'query_identity_requested'=>$queryIdentityRequested,
			'query_identity_source'=>$queryIdentitySource,
			'query_identity_available'=>$queryFingerprint!==null,
			'query_options'=>$this->metadataOptions(),
			'persistent_cache'=>$this->bindingCacheMetadata(),
		]);
	}

	/**
	 * Executes the search query and returns the shape requested by the mode.
	 *
	 * Modes return full result objects, raw hits, first result, raw engine
	 * payload, hydrated hits, or hydrated documents. Hydration delegates to the
	 * optional resolver supplied in binding options.
	 *
	 * @param BindingContext $context Template binding context; currently not consumed by this binding.
	 * @return mixed search results, hits, first hit, raw engine payload, hydrated hits, or hydrated documents for the selected mode.
	 */
	public function resolve(BindingContext $context): mixed {
		return match($this->mode){
			'results'=>$this->query->get(),
			'hits'=>$this->query->get()->hits(),
			'first'=>$this->query->first(),
			'raw'=>$this->query->raw(),
			'hydrated'=>$this->query->hydrate($this->options['resolver'] ?? null),
			'documents'=>$this->query->hydrate($this->options['resolver'] ?? null)->documents(),
			default=>throw new \InvalidArgumentException("Unsupported templating search binding mode '{$this->mode}'."),
		};
	}

	/**
	 * Builds the cache identity payload for this search binding.
	 *
	 * @param BindingContext $context Template binding context; currently not consumed by this binding.
	 * @return array<string, mixed> Identity payload derived from query class, mode, index, fingerprint or state, and options.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		$queryFingerprint=$this->queryFingerprint();
		$queryIdentityRequested=$this->inheritsQueryIdentity();
		$queryIdentitySource=$this->queryIdentitySource($queryFingerprint);
		return self::compactPayload([
			'type'=>'search_query',
			'query_class'=>$this->query::class,
			'mode'=>$this->mode,
			'index'=>$this->indexName(),
			'query_fingerprint'=>$queryIdentitySource==='fingerprint' ? $queryFingerprint : null,
			'query_identity_mode'=>$queryIdentityRequested ? 'inherit' : 'state',
			'query_identity_source'=>$queryIdentitySource,
			'state'=>$queryIdentitySource==='fingerprint' ? null : $this->queryState(),
			'options'=>$this->metadataOptions(),
		]);
	}

	/**
	 * Returns persistent binding-cache settings when enabled.
	 *
	 * @param BindingContext $context Template binding context; currently not consumed by this binding.
	 * @return ?array{ttl:int, names:array<int, string>, identity:mixed} Cache configuration, or `null` when disabled.
	 */
	public function persistentCache(BindingContext $context): ?array {
		$config=$this->bindingCacheConfig();
		if($config===null){
			return null;
		}
		return [
			'ttl'=>$config['ttl'],
			'names'=>$config['names'],
			'identity'=>$config['identity'] ?? null,
		];
	}

	/**
	 * Normalizes search binding mode aliases.
	 *
	 * @param string $mode Requested mode.
	 * @return string Canonical mode.
	 * @throws \InvalidArgumentException When the mode is not supported.
	 */
	private static function normalizeMode(string $mode): string {
		$mode=strtolower(trim($mode));
		return match($mode){
			'', 'results', 'get'=>'results',
			'hits'=>'hits',
			'first'=>'first',
			'raw'=>'raw',
			'hydrated', 'hydrate'=>'hydrated',
			'documents', 'docs'=>'documents',
			default=>throw new \InvalidArgumentException("Unsupported templating search binding mode '{$mode}'."),
		};
	}

	/**
	 * Returns the query index name when the query exposes one.
	 *
	 * @return ?string Trimmed index name, or `null` when unavailable.
	 */
	private function indexName(): ?string {
		if(!method_exists($this->query, 'index')){
			return null;
		}
		$index=$this->query->index();
		return is_string($index) && trim($index)!=='' ? trim($index) : null;
	}

	/**
	 * Returns metadata-safe option details.
	 *
	 * @return array<string, mixed> Resolver class/type information safe for diagnostics.
	 */
	private function metadataOptions(): array {
		$options=[];
		if(array_key_exists('resolver', $this->options) && $this->options['resolver']!==null){
			$options['resolver']=is_object($this->options['resolver'])
				? $this->options['resolver']::class
				: get_debug_type($this->options['resolver']);
		}
		return $options;
	}

	/**
	 * Describes persistent cache inheritance behavior for metadata.
	 *
	 * @return ?array<string, mixed> Cache metadata, or `null` when persistent binding cache is disabled.
	 */
	private function bindingCacheMetadata(): ?array {
		$config=$this->bindingCacheConfig();
		if($config===null){
			return null;
		}
		$queryFingerprint=$this->queryFingerprint();
		$queryIdentityRequested=$this->inheritsQueryIdentity();
		$queryIdentitySource=$this->queryIdentitySource($queryFingerprint);
		return [
			'ttl'=>$config['ttl'],
			'names'=>$config['names'],
			'explicit_identity'=>array_key_exists('identity', $config) && $config['identity']!==null,
			'requested_query_fingerprint_identity'=>$queryIdentityRequested,
			'inherits_query_fingerprint'=>$queryIdentitySource==='fingerprint'
				&& (!array_key_exists('identity', $config) || $config['identity']===null),
		];
	}

	/**
	 * Normalizes the `binding_cache` option.
	 *
	 * @return ?array{ttl:int, names:array<int, string>, identity:mixed} Cache configuration, or `null` when disabled/invalid.
	 */
	private function bindingCacheConfig(): ?array {
		$config=$this->options['binding_cache'] ?? null;
		if($config===null || $config===false){
			return null;
		}
		if(is_int($config) || is_float($config) || (is_string($config) && is_numeric($config))){
			return [
				'ttl'=>max(1, (int)$config),
				'names'=>[],
				'identity'=>null,
			];
		}
		if($config===true){
			return [
				'ttl'=>300,
				'names'=>[],
				'identity'=>null,
			];
		}
		if(!is_array($config)){
			return null;
		}
		return [
			'ttl'=>max(1, (int)($config['ttl'] ?? 300)),
			'names'=>$this->normalizeNames($config['names'] ?? []),
			'identity'=>$config['identity'] ?? null,
		];
	}

	/**
	 * Normalizes binding cache name lists.
	 *
	 * @param array<string|int, mixed>|string|bool|null $names Candidate cache names.
	 * @return array<int, string> Unique non-empty cache names.
	 */
	private function normalizeNames(array|string|bool|null $names): array {
		$names=is_array($names) ? $names : [$names];
		$normalized=[];
		foreach($names as $name){
			if(!is_string($name)){
				continue;
			}
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[$name]=true;
		}
		return array_keys($normalized);
	}

	/**
	 * Reads query execution state when available.
	 *
	 * @return array<string, mixed> Execution-state payload, or an empty array.
	 */
	private function queryState(): array {
		if(method_exists($this->query, 'executionState')){
			$state=$this->query->executionState();
			return is_array($state) ? $state : [];
		}
		return [];
	}

	/**
	 * Reads the query fingerprint when available.
	 *
	 * @return ?string Non-empty fingerprint, or `null`.
	 */
	private function queryFingerprint(): ?string {
		if(!method_exists($this->query, 'fingerprint')){
			return null;
		}
		$fingerprint=$this->query->fingerprint();
		if(!is_string($fingerprint)){
			return null;
		}
		$fingerprint=trim($fingerprint);
		return $fingerprint!=='' ? $fingerprint : null;
	}

	/**
	 * Reports whether fingerprint identity inheritance was requested.
	 *
	 * @return bool `true` when `inherit_query_identity` is enabled.
	 */
	private function inheritsQueryIdentity(): bool {
		return ($this->options['inherit_query_identity'] ?? false)===true;
	}

	/**
	 * Selects the actual identity source for the current query.
	 *
	 * @param ?string $queryFingerprint Fingerprint discovered from the query.
	 * @return string `fingerprint` when requested and available, otherwise `execution_state`.
	 */
	private function queryIdentitySource(?string $queryFingerprint): string {
		return $this->inheritsQueryIdentity() && $queryFingerprint!==null
			? 'fingerprint'
			: 'execution_state';
	}

	/**
	 * Drops null and empty-array entries from diagnostic payloads.
	 *
	 * @param array<string, mixed> $payload Raw payload.
	 * @return array<string, mixed> Compact payload.
	 */
	private static function compactPayload(array $payload): array {
		$compact=[];
		foreach($payload as $key=>$value){
			if($value!==null && $value!==[]){
				$compact[$key]=$value;
			}
		}
		return $compact;
	}
}
