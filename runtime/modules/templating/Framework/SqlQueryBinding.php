<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class SqlQueryBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private const REPOSITORY_QUERY='Dataphyre\\Database\\RepositoryQuery';
	private const TABLE_QUERY='Dataphyre\\Database\\TableQuery';

	public function __construct(
		private readonly object $query,
		private readonly string $mode,
		private readonly array $options=[],
		private readonly string $name='sql.query.records'
	){}

	public static function make(object $query, string $mode='records', array $options=[]): self {
		if(!self::supports($query)){
			throw new \InvalidArgumentException(
				'Templating query bindings require a Dataphyre SQL RepositoryQuery or TableQuery instance.'
			);
		}

		$mode=self::normalizeMode($mode);
		return new self(
			clone $query,
			$mode,
			$options,
			'sql.query.'.$mode
		);
	}

	public static function supports(object $query): bool {
		return self::matches($query, self::REPOSITORY_QUERY)
			|| self::matches($query, self::TABLE_QUERY);
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
			'type'=>'sql_query',
			'driver'=>'sql',
			'query_mode'=>$this->mode,
			'query_class'=>$this->query::class,
			'query_target_type'=>$this->targetType(),
			'query_target'=>$this->target(),
			'query_fingerprint'=>$query_fingerprint,
			'query_identity_mode'=>$query_identity_requested ? 'inherit' : 'state',
			'query_identity_requested'=>$query_identity_requested,
			'query_identity_source'=>$query_identity_source,
			'query_identity_available'=>$query_fingerprint!==null,
			'query_options'=>$this->metadataOptions(),
			'query_cache_names'=>$this->defaultBindingCacheNames(),
			'persistent_cache'=>$this->bindingCacheMetadata(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	public function resolve(BindingContext $context): mixed {
		return match($this->mode){
			'rows'=>$this->query->get(
				$this->options['columns'] ?? null,
				$this->options['caching'] ?? null
			),
			'first'=>$this->query->first(
				$this->options['columns'] ?? null,
				$this->options['caching'] ?? null
			),
			'records'=>$this->query->getRecords(
				$this->options['columns'] ?? null,
				$this->options['hydrator'] ?? null,
				$this->options['caching'] ?? null
			),
			'first_record'=>$this->query->firstRecord(
				$this->options['columns'] ?? null,
				$this->options['hydrator'] ?? null,
				$this->options['caching'] ?? null
			),
			'page'=>$this->query->paginate(
				$this->page(),
				$this->perPage(),
				$this->options['columns'] ?? null,
				$this->options['caching'] ?? null
			),
			'page_records'=>$this->query->paginateRecords(
				$this->page(),
				$this->perPage(),
				$this->options['columns'] ?? null,
				$this->options['hydrator'] ?? null,
				$this->options['caching'] ?? null
			),
			'value'=>$this->query->value(
				$this->requiredOption('column'),
				$this->options['caching'] ?? null
			),
			'pluck'=>$this->query->pluck(
				$this->requiredOption('column'),
				$this->optionalString('key_column'),
				$this->options['caching'] ?? null
			),
			'key_by'=>$this->query->keyBy(
				$this->requiredOption('key_column'),
				$this->options['columns'] ?? null,
				$this->options['caching'] ?? null
			),
			'count'=>$this->query->count($this->options['caching'] ?? null),
			'exists'=>$this->query->exists($this->options['caching'] ?? null),
			default=>throw new \InvalidArgumentException("Unsupported templating SQL query binding mode '{$this->mode}'."),
		};
	}

	public function cacheIdentity(BindingContext $context): mixed {
		$query_fingerprint=$this->queryFingerprint();
		$query_identity_requested=$this->inheritsQueryIdentity();
		$query_identity_source=$this->queryIdentitySource($query_fingerprint);
		return array_filter([
			'type'=>'sql_query',
			'query_class'=>$this->query::class,
			'mode'=>$this->mode,
			'target_type'=>$this->targetType(),
			'target'=>$this->target(),
			'query_fingerprint'=>$query_identity_source==='fingerprint' ? $query_fingerprint : null,
			'query_identity_mode'=>$query_identity_requested ? 'inherit' : 'state',
			'query_identity_source'=>$query_identity_source,
			'state'=>$query_identity_source==='fingerprint' ? null : $this->queryState(),
			'options'=>$this->cacheIdentityOptions(),
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
			'', 'records', 'get_records', 'all_records'=>'records',
			'rows', 'get', 'all'=>'rows',
			'first'=>'first',
			'first_record', 'record'=>'first_record',
			'page', 'paginate', 'page_rows'=>'page',
			'page_records', 'paginate_records'=>'page_records',
			'value'=>'value',
			'pluck'=>'pluck',
			'key_by', 'keyby'=>'key_by',
			'count'=>'count',
			'exists'=>'exists',
			default=>throw new \InvalidArgumentException("Unsupported templating SQL query binding mode '{$mode}'."),
		};
	}

	private static function matches(object $query, string $class): bool {
		return class_exists($class) && is_a($query, $class);
	}

	private function targetType(): ?string {
		if(method_exists($this->query, 'repositoryClass')){
			return 'repository';
		}
		if(method_exists($this->query, 'table')){
			return 'table';
		}
		return null;
	}

	private function target(): ?string {
		if(method_exists($this->query, 'repositoryClass')){
			$target=$this->query->repositoryClass();
			return is_string($target) && trim($target)!=='' ? trim($target) : null;
		}
		if(method_exists($this->query, 'table')){
			$target=$this->query->table();
			return is_string($target) && trim($target)!=='' ? trim($target) : null;
		}
		return null;
	}

	private function metadataOptions(): array {
		$options=[];
		if(array_key_exists('columns', $this->options)){
			$options['columns']=$this->options['columns'];
		}
		if(array_key_exists('column', $this->options)){
			$options['column']=(string)$this->options['column'];
		}
		if(array_key_exists('key_column', $this->options)){
			$options['key_column']=(string)$this->options['key_column'];
		}
		if(in_array($this->mode, ['page', 'page_records'], true)){
			$options['page']=$this->page();
			$options['per_page']=$this->perPage();
		}
		if(array_key_exists('hydrator', $this->options) && $this->options['hydrator']!==null){
			$options['hydrator']=is_object($this->options['hydrator'])
				? $this->options['hydrator']::class
				: get_debug_type($this->options['hydrator']);
		}
		if(array_key_exists('caching', $this->options)){
			$options['caching']=$this->options['caching'];
		}
		return $options;
	}

	private function cacheIdentityOptions(): array {
		$options=$this->metadataOptions();
		unset($options['hydrator']);
		unset($options['binding_cache']);
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

		$ttl=300;
		$names=$this->defaultBindingCacheNames();
		$identity=null;

		if(is_int($config) || is_float($config) || (is_string($config) && is_numeric($config))){
			$ttl=max(1, (int)$config);
		}
		elseif($config===true){
			$ttl=300;
		}
		elseif(is_array($config)){
			$ttl=max(1, (int)($config['ttl'] ?? 300));
			$names=array_values(array_unique(array_merge(
				$names,
				$this->normalizeNames($config['names'] ?? [])
			)));
			$identity=$config['identity'] ?? null;
		}
		else{
			return null;
		}

		return [
			'ttl'=>$ttl,
			'names'=>$names,
			'identity'=>$identity,
		];
	}

	private function defaultBindingCacheNames(): array {
		$state=$this->queryState();
		return $this->normalizeNames($state['caching'] ?? []);
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

	private function page(): int {
		return max(1, (int)($this->options['page'] ?? 1));
	}

	private function perPage(): int {
		return max(1, (int)($this->options['per_page'] ?? 50));
	}

	private function requiredOption(string $key): string {
		$value=$this->optionalString($key);
		if($value!==null){
			return $value;
		}
		throw new \InvalidArgumentException("Templating SQL query binding mode '{$this->mode}' requires option '{$key}'.");
	}

	private function optionalString(string $key): ?string {
		$value=$this->options[$key] ?? null;
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}
}
