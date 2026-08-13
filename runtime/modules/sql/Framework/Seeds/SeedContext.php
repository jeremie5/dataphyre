<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use RuntimeException;

/** SQL execution context passed to seed apply and rollback callbacks. */
final class SeedContext {
	/** @var callable(string|array<string,string>,?array<int,mixed>,bool):mixed|null */
	private $query_executor;

	/**
	 * @param callable(string|array<string,string>,?array<int,mixed>,bool):mixed|null $query_executor
	 * @param array<string,mixed> $attributes Application-owned context values.
	 */
	public function __construct(
		?callable $query_executor=null,
		private ?string $dbms=null,
		private array $attributes=[],
		private ?string $cluster=null,
	) {
		$this->query_executor=$query_executor;
		$this->dbms=$dbms!==null && trim($dbms)!=='' ? strtolower(trim($dbms)) : null;
		$this->cluster=$cluster!==null && trim($cluster)!=='' ? trim($cluster) : null;
	}

	/**
	 * Executes SQL immediately and throws when Dataphyre reports a query failure.
	 *
	 * Raw mutation targets are inferred from registered table locations by default,
	 * so the surrounding framework transaction defers their cache invalidation until
	 * commit. Pass an explicit list for vendor-specific SQL, or false only when the
	 * caller has proved that no cached table can be affected.
	 *
	 * @param array|bool|null $clear_cache Null/true infers targets, an array is explicit, and false opts out.
	 */
	public function query(
		string|array $query,
		?array $vars=null,
		bool $associative=false,
		array|bool|null $clear_cache=null,
	): mixed {
		$query=$this->clusterAwareQuery($query);
		$clear_cache=$this->resolveWriteInvalidation($query, $clear_cache);
		if($this->query_executor!==null){
			$result=($this->query_executor)($query, $vars, $associative);
		}else{
			if(!class_exists('\dataphyre\sql') || !method_exists('\dataphyre\sql', 'query')){
				throw new RuntimeException('Dataphyre SQL must be booted before a seed can execute queries.');
			}
			$result=\dataphyre\sql::query($query, $vars, $associative, false, false, $clear_cache, null);
		}
		if($result===false){
			$message='Dataphyre SQL rejected a seed query.';
			if(class_exists('\dataphyre\sql') && method_exists('\dataphyre\sql', 'last_query_error')){
				$error=\dataphyre\sql::last_query_error();
				if(is_array($error) && trim((string)($error['message'] ?? ''))!==''){
					$message.=' '.trim((string)$error['message']);
				}
			}
			throw new RuntimeException($message);
		}
		if(
			$this->query_executor!==null
			&& $clear_cache!==false
			&& class_exists('\dataphyre\sql')
			&& method_exists('\dataphyre\sql', 'invalidate_cache')
		){
			\dataphyre\sql::invalidate_cache($clear_cache);
		}
		return $result;
	}

	public function dbms(): string {
		if($this->dbms!==null){
			return $this->dbms;
		}
		if(defined('DP_SQL_CFG') && defined('DP_CORE_CFG')){
			$datacenter=(string)(DP_CORE_CFG['datacenter'] ?? '');
			$cluster=$this->cluster ?? (string)(DP_SQL_CFG['default_cluster'] ?? '');
			$dbms=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'][$cluster]['dbms'] ?? null;
			if(is_string($dbms) && trim($dbms)!==''){
				return strtolower(trim($dbms));
			}
		}
		return 'unknown';
	}

	/** Requested SQL cluster, or null when Dataphyre's configured default is used. */
	public function cluster(): ?string { return $this->cluster; }

	public function attribute(string $name, mixed $default=null): mixed {
		return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
	}

	/** @return array<string,mixed> */
	public function attributes(): array { return $this->attributes; }

	/** @return array|false */
	private function resolveWriteInvalidation(string|array $query, array|bool|null $clear_cache): array|false {
		if(is_array($clear_cache)){
			$targets=[];
			foreach($clear_cache as $target){
				$target=trim((string)$target);
				if($target!=='') $targets[$target]=$target;
			}
			return $targets!==[] ? array_values($targets) : false;
		}
		if($clear_cache===false){
			return false;
		}
		if(!class_exists('\dataphyre\sql') || !method_exists('\dataphyre\sql', 'query_write_targets')){
			return false;
		}
		$statements=[];
		if(is_string($query)){
			$statements[]=$query;
		}else{
			$dbms=$this->dbms();
			if(is_string($query[$dbms] ?? null)){
				$statements[]=$query[$dbms];
			}else{
				foreach(['postgresql','mysql','sqlite'] as $candidate){
					if(is_string($query[$candidate] ?? null)) $statements[]=$query[$candidate];
				}
			}
		}
		$targets=[];
		foreach($statements as $statement){
			foreach(\dataphyre\sql::query_write_targets($statement, true) as $target){
				$target=trim((string)$target);
				if($target!=='') $targets[$target]=$target;
			}
		}
		return $targets!==[] ? array_values($targets) : false;
	}

	private function clusterAwareQuery(string|array $query): string|array {
		if($this->cluster===null){
			return $query;
		}
		if(is_array($query)){
			$query['dbms_cluster_override']=$this->cluster;
			return $query;
		}
		return [
			'mysql'=>$query,
			'postgresql'=>$query,
			'sqlite'=>$query,
			'dbms_cluster_override'=>$this->cluster,
		];
	}
}
