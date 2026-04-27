<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class SearchQueryBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private const SEARCH_QUERY='Dataphyre\\FulltextEngine\\Query';

	public function __construct(
		private readonly object $query,
		private readonly string $mode,
		private readonly array $options=[],
		private readonly string $name='search.query.results'
	){}

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

	public static function supports(object $query): bool {
		return class_exists(self::SEARCH_QUERY) && is_a($query, self::SEARCH_QUERY);
	}

	public function inheritIdentity(bool $inherit=true): self {
		return new self(
			clone $this->query,
			$this->mode,
			array_replace($this->options, ['inherit_query_identity'=>$inherit]),
			$this->name
		);
	}

	public function useExecutionStateIdentity(): self {
		return $this->inheritIdentity(false);
	}

	public function name(): string {
		return $this->name;
	}

	public function metadata(): array {
		$query_fingerprint=$this->queryFingerprint();
		$query_identity_requested=$this->inheritsQueryIdentity();
		$query_identity_source=$this->queryIdentitySource($query_fingerprint);
		return array_filter([
			'type'=>'search_query',
			'driver'=>'fulltext',
			'query_mode'=>$this->mode,
			'query_class'=>$this->query::class,
			'query_target_type'=>'index',
			'query_target'=>$this->indexName(),
			'query_fingerprint'=>$query_fingerprint,
			'query_identity_mode'=>$query_identity_requested ? 'inherit' : 'state',
			'query_identity_requested'=>$query_identity_requested,
			'query_identity_source'=>$query_identity_source,
			'query_identity_available'=>$query_fingerprint!==null,
			'query_options'=>$this->metadataOptions(),
			'persistent_cache'=>$this->bindingCacheMetadata(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

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

	public function cacheIdentity(BindingContext $context): mixed {
		$query_fingerprint=$this->queryFingerprint();
		$query_identity_requested=$this->inheritsQueryIdentity();
		$query_identity_source=$this->queryIdentitySource($query_fingerprint);
		return array_filter([
			'type'=>'search_query',
			'query_class'=>$this->query::class,
			'mode'=>$this->mode,
			'index'=>$this->indexName(),
			'query_fingerprint'=>$query_identity_source==='fingerprint' ? $query_fingerprint : null,
			'query_identity_mode'=>$query_identity_requested ? 'inherit' : 'state',
			'query_identity_source'=>$query_identity_source,
			'state'=>$query_identity_source==='fingerprint' ? null : $this->queryState(),
			'options'=>$this->metadataOptions(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

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

	private function indexName(): ?string {
		if(!method_exists($this->query, 'index')){
			return null;
		}
		$index=$this->query->index();
		return is_string($index) && trim($index)!=='' ? trim($index) : null;
	}

	private function metadataOptions(): array {
		$options=[];
		if(array_key_exists('resolver', $this->options) && $this->options['resolver']!==null){
			$options['resolver']=is_object($this->options['resolver'])
				? $this->options['resolver']::class
				: get_debug_type($this->options['resolver']);
		}
		return $options;
	}

	private function bindingCacheMetadata(): ?array {
		$config=$this->bindingCacheConfig();
		if($config===null){
			return null;
		}
		$query_fingerprint=$this->queryFingerprint();
		$query_identity_requested=$this->inheritsQueryIdentity();
		$query_identity_source=$this->queryIdentitySource($query_fingerprint);
		return [
			'ttl'=>$config['ttl'],
			'names'=>$config['names'],
			'explicit_identity'=>array_key_exists('identity', $config) && $config['identity']!==null,
			'requested_query_fingerprint_identity'=>$query_identity_requested,
			'inherits_query_fingerprint'=>$query_identity_source==='fingerprint'
				&& (!array_key_exists('identity', $config) || $config['identity']===null),
		];
	}

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

	private function queryState(): array {
		if(method_exists($this->query, 'executionState')){
			$state=$this->query->executionState();
			return is_array($state) ? $state : [];
		}
		return [];
	}

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

	private function inheritsQueryIdentity(): bool {
		return ($this->options['inherit_query_identity'] ?? false)===true;
	}

	private function queryIdentitySource(?string $query_fingerprint): string {
		return $this->inheritsQueryIdentity() && $query_fingerprint!==null
			? 'fingerprint'
			: 'execution_state';
	}
}
