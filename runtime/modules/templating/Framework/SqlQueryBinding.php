<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Template binding that executes a Dataphyre SQL query on demand.
 *
 * The binding wraps repository and table query objects, clones them for
 * template isolation, exposes metadata/cache identity without executing the
 * query, and resolves into rows, records, pagination, scalar values, keyed
 * collections, counts, or existence checks.
 */
final class SqlQueryBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private const REPOSITORY_QUERY='Dataphyre\\Database\\RepositoryQuery';
	private const TABLE_QUERY='Dataphyre\\Database\\TableQuery';

	/**
	 * Stores a supported SQL query and normalized binding mode.
	 *
	 * @param object $query Cloned repository/table query used during resolution.
	 * @param string $mode Normalized output mode.
	 * @param array<string, mixed> $options Binding options such as columns, hydrator, pagination, caching, or identity behavior.
	 * @param string $name Stable binding name exposed to template metadata.
	 */
	public function __construct(
		private readonly object $query,
		private readonly string $mode,
		private readonly array $options=[],
		private readonly string $name='sql.query.records'
	){}

	/**
	 * Creates a SQL query binding after validating the query type.
	 *
	 * @param object $query Dataphyre repository or table query instance.
	 * @param string $mode Requested resolution mode.
	 * @param array<string, mixed> $options Binding options.
	 * @return self Immutable binding with a cloned query.
	 * @throws \InvalidArgumentException When the object or mode is unsupported.
	 */
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

	/**
	 * Checks whether an object is a supported SQL query type.
	 *
	 * @param object $query Candidate query object.
	 * @return bool `true` for repository or table query instances.
	 */
	public static function supports(object $query): bool {
		return self::matches($query, self::REPOSITORY_QUERY)
			|| self::matches($query, self::TABLE_QUERY);
	}

	/**
	 * Selects whether binding cache identity should prefer the query fingerprint.
	 *
	 * @param bool $inherit Whether to use `fingerprint()` when available.
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
	 * @return string Name such as `sql.query.records` or `sql.query.page`.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Describes the SQL binding without executing the query.
	 *
	 * @return array<string, mixed> Metadata including mode, query target, fingerprint availability, identity source, options, cache names, and persistent cache details.
	 */
	public function metadata(): array {
		$queryFingerprint=$this->queryFingerprint();
		$queryIdentityRequested=$this->inheritsQueryIdentity();
		$queryIdentitySource=$this->queryIdentitySource($queryFingerprint);
		return array_filter([
			'type'=>'sql_query',
			'driver'=>'sql',
			'query_mode'=>$this->mode,
			'query_class'=>$this->query::class,
			'query_target_type'=>$this->targetType(),
			'query_target'=>$this->target(),
			'query_fingerprint'=>$queryFingerprint,
			'query_identity_mode'=>$queryIdentityRequested ? 'inherit' : 'state',
			'query_identity_requested'=>$queryIdentityRequested,
			'query_identity_source'=>$queryIdentitySource,
			'query_identity_available'=>$queryFingerprint!==null,
			'query_options'=>$this->metadataOptions(),
			'query_cache_names'=>$this->defaultBindingCacheNames(),
			'persistent_cache'=>$this->bindingCacheMetadata(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Executes the SQL query and returns the shape requested by the mode.
	 *
	 * Required scalar options are validated at execution time: `value` and
	 * `pluck` require `column`, while `key_by` requires `key_column`.
	 *
	 * @param BindingContext $context Template binding context; currently not consumed by this binding.
	 * @return mixed rows, first row, records, scalar value, plucked map, keyed rows, aggregate, or count for the selected mode.
	 */
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

	/**
	 * Builds the cache identity payload for this SQL binding.
	 *
	 * @param BindingContext $context Template binding context; currently not consumed by this binding.
	 * @return array<string, mixed> Identity payload derived from query target, mode, fingerprint or state, and cache-safe options.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		$queryFingerprint=$this->queryFingerprint();
		$queryIdentityRequested=$this->inheritsQueryIdentity();
		$queryIdentitySource=$this->queryIdentitySource($queryFingerprint);
		return array_filter([
			'type'=>'sql_query',
			'query_class'=>$this->query::class,
			'mode'=>$this->mode,
			'target_type'=>$this->targetType(),
			'target'=>$this->target(),
			'query_fingerprint'=>$queryIdentitySource==='fingerprint' ? $queryFingerprint : null,
			'query_identity_mode'=>$queryIdentityRequested ? 'inherit' : 'state',
			'query_identity_source'=>$queryIdentitySource,
			'state'=>$queryIdentitySource==='fingerprint' ? null : $this->queryState(),
			'options'=>$this->cacheIdentityOptions(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
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
	 * Normalizes SQL binding mode aliases.
	 *
	 * @param string $mode Requested mode.
	 * @return string Canonical mode.
	 * @throws \InvalidArgumentException When the mode is not supported.
	 */
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

	/**
	 * Tests a query object against an optional framework query class.
	 *
	 * @return bool `true` when the class exists and the query is an instance of it.
	 */
	private static function matches(object $query, string $class): bool {
		return class_exists($class) && is_a($query, $class);
	}

	/**
	 * Identifies whether the query targets a repository or table.
	 *
	 * @return ?string `repository`, `table`, or `null` when the query cannot describe its target.
	 */
	private function targetType(): ?string {
		if(method_exists($this->query, 'repositoryClass')){
			return 'repository';
		}
		if(method_exists($this->query, 'table')){
			return 'table';
		}
		return null;
	}

	/**
	 * Reads the repository class or table name from the query.
	 *
	 * @return ?string Trimmed target identifier, or `null`.
	 */
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

	/**
	 * Returns metadata-safe option details.
	 *
	 * @return array<string, mixed> Options relevant to output shape, pagination, hydration, and SQL caching.
	 */
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

	/**
	 * Removes execution-only details from options used for cache identity.
	 *
	 * @return array<string, mixed> Cache-safe option payload.
	 */
	private function cacheIdentityOptions(): array {
		$options=$this->metadataOptions();
		unset($options['hydrator']);
		unset($options['binding_cache']);
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

	/**
	 * Uses query execution-state caching names as default persistent cache names.
	 *
	 * @return array<int, string> Default cache names.
	 */
	private function defaultBindingCacheNames(): array {
		$state=$this->queryState();
		return $this->normalizeNames($state['caching'] ?? []);
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
	 * Returns the requested pagination page.
	 *
	 * @return int Current page number, clamped to at least 1.
	 */
	private function page(): int {
		return max(1, (int)($this->options['page'] ?? 1));
	}

	/**
	 * Returns the requested pagination page size.
	 *
	 * @return int Page size, clamped to at least 1.
	 */
	private function perPage(): int {
		return max(1, (int)($this->options['per_page'] ?? 50));
	}

	/**
	 * Returns a required string option or throws a mode-specific error.
	 *
	 * @param string $key Required option key.
	 * @return string Non-empty option value.
	 */
	private function requiredOption(string $key): string {
		$value=$this->optionalString($key);
		if($value!==null){
			return $value;
		}
		throw new \InvalidArgumentException("Templating SQL query binding mode '{$this->mode}' requires option '{$key}'.");
	}

	/**
	 * Reads a non-empty string option.
	 *
	 * @param string $key Option key.
	 * @return ?string Trimmed option value, or `null`.
	 */
	private function optionalString(string $key): ?string {
		$value=$this->options[$key] ?? null;
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

}
