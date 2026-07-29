<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database;

/**
 * Execution-local SQL and cache environment.
 *
 * A data environment lets an application run the same repositories, table
 * definitions, queries, and cache policies against a separately configured
 * cluster. The context is stack based, exception safe, and isolated per Fiber,
 * which makes it suitable for request middleware as well as nested jobs.
 *
 * Applications define environments in `DP_SQL_CFG['data_environments']`:
 *
 *     'sandbox'=>[
 *         'cluster'=>'ServeSandbox',
 *         'cache_namespace'=>'serve-sandbox',
 *     ],
 *
 * Explicit cluster contexts continue to win over the ambient environment.
 */
final class DataEnvironment {

	/** @var list<array{name:string,cluster:?string,cache_namespace:?string,token:string}> */
	private static array $mainStack=[];

	/** @var ?\WeakMap<\Fiber,list<array{name:string,cluster:?string,cache_namespace:?string,token:string}>> */
	private static ?\WeakMap $fiberStacks=null;

	/**
	 * Runs work inside a configured data environment and always restores the
	 * previous context, including when the callback throws.
	 *
	 * @param array{cluster?:?string,cache_namespace?:?string} $overrides
	 */
	public static function run(string $name, callable $callback, array $overrides=[]): mixed {
		$token=self::push($name, $overrides);
		try{
			return $callback(self::current());
		}finally{
			self::pop($token);
		}
	}

	/**
	 * Activates an environment until the matching token is popped.
	 *
	 * Prefer run() for ordinary request/job work. The token requirement prevents
	 * one integration from accidentally unwinding another integration's context.
	 *
	 * @param array{cluster?:?string,cache_namespace?:?string} $overrides
	 */
	public static function push(string $name, array $overrides=[]): string {
		$definition=self::definition($name, $overrides);
		$token=bin2hex(random_bytes(16));
		$stack=self::stack();
		$stack[]=$definition+['token'=>$token];
		self::replaceStack($stack);
		return $token;
	}

	/**
	 * Restores the previous environment.
	 *
	 * @throws \RuntimeException when the token is missing or not the active one.
	 */
	public static function pop(string $token): void {
		$stack=self::stack();
		$current=$stack[array_key_last($stack)] ?? null;
		if(!is_array($current) || !hash_equals((string)$current['token'], $token)){
			throw new \RuntimeException('Dataphyre data environment contexts must be released in stack order.');
		}
		array_pop($stack);
		self::replaceStack($stack);
	}

	/** @return array{name:string,cluster:?string,cache_namespace:?string} */
	public static function current(): array {
		$stack=self::stack();
		$current=$stack[array_key_last($stack)] ?? null;
		if(!is_array($current)){
			return ['name'=>'live', 'cluster'=>null, 'cache_namespace'=>null];
		}
		return [
			'name'=>(string)$current['name'],
			'cluster'=>$current['cluster'],
			'cache_namespace'=>$current['cache_namespace'],
		];
	}

	public static function name(): string {
		return self::current()['name'];
	}

	public static function is(string $name): bool {
		return hash_equals(self::normalizeName($name), self::name());
	}

	public static function active(): bool {
		return self::stack()!==[];
	}

	public static function clusterOverride(): ?string {
		return self::current()['cluster'];
	}

	public static function cacheNamespace(): ?string {
		return self::current()['cache_namespace'];
	}

	/**
	 * Prefixes a cache key only when a non-live environment is active.
	 */
	public static function cacheKey(string $key): string {
		$namespace=self::cacheNamespace();
		return $namespace===null ? $key : $namespace.'::'.$key;
	}

	/**
	 * Prefixes a filesystem cache location with a traversal-safe segment.
	 */
	public static function cachePath(string $location): string {
		$namespace=self::cacheNamespace();
		return $namespace===null ? $location : $namespace.'/'.$location;
	}

	/**
	 * Resolves SQL configuration plus caller overrides into one immutable frame.
	 *
	 * @param array{cluster?:?string,cache_namespace?:?string} $overrides
	 * @return array{name:string,cluster:?string,cache_namespace:?string}
	 */
	private static function definition(string $name, array $overrides): array {
		$name=self::normalizeName($name);
		$config=defined('DP_SQL_CFG') && is_array(DP_SQL_CFG) ? DP_SQL_CFG : [];
		$configured=$config['data_environments'][$name] ?? [];
		$configured=is_array($configured) ? $configured : [];

		$cluster=array_key_exists('cluster', $overrides)
			? self::nullableIdentifier($overrides['cluster'], 'cluster')
			: self::nullableIdentifier($configured['cluster'] ?? null, 'cluster');
		$cacheNamespace=array_key_exists('cache_namespace', $overrides)
			? self::nullableIdentifier($overrides['cache_namespace'], 'cache namespace')
			: self::nullableIdentifier($configured['cache_namespace'] ?? null, 'cache namespace');
		if($cacheNamespace===null && $name!=='live'){
			$cacheNamespace=$name;
		}

		if($cluster!==null){
			$coreConfig=defined('DP_CORE_CFG') && is_array(DP_CORE_CFG) ? DP_CORE_CFG : [];
			$datacenter=trim((string)($coreConfig['datacenter'] ?? ''));
			$clusters=$config['datacenters'][$datacenter]['dbms_clusters'] ?? [];
			if(is_array($clusters) && $clusters!==[] && !array_key_exists($cluster, $clusters)){
				throw SqlError::unknownCluster($cluster, array_keys($clusters), $datacenter!=='' ? $datacenter : null);
			}
		}

		return [
			'name'=>$name,
			'cluster'=>$cluster,
			'cache_namespace'=>$cacheNamespace,
		];
	}

	private static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		if($name==='' || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $name)!==1){
			throw new \InvalidArgumentException('Dataphyre data environment names may contain lowercase letters, numbers, dots, underscores, and hyphens.');
		}
		return $name;
	}

	private static function nullableIdentifier(mixed $value, string $label): ?string {
		if($value===null){
			return null;
		}
		$value=trim((string)$value);
		if($value===''){
			return null;
		}
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value)!==1){
			throw new \InvalidArgumentException('Dataphyre data environment '.$label.' is invalid.');
		}
		return $value;
	}

	/** @return list<array{name:string,cluster:?string,cache_namespace:?string,token:string}> */
	private static function stack(): array {
		$fiber=\Fiber::getCurrent();
		if($fiber===null){
			return self::$mainStack;
		}
		self::$fiberStacks ??= new \WeakMap();
		return self::$fiberStacks[$fiber] ?? [];
	}

	/** @param list<array{name:string,cluster:?string,cache_namespace:?string,token:string}> $stack */
	private static function replaceStack(array $stack): void {
		$fiber=\Fiber::getCurrent();
		if($fiber===null){
			self::$mainStack=$stack;
			return;
		}
		self::$fiberStacks ??= new \WeakMap();
		if($stack===[]){
			unset(self::$fiberStacks[$fiber]);
			return;
		}
		self::$fiberStacks[$fiber]=$stack;
	}
}
