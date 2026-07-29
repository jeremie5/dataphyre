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

	/** Executes SQL immediately and throws when Dataphyre reports a query failure. */
	public function query(string|array $query, ?array $vars=null, bool $associative=false): mixed {
		$query=$this->clusterAwareQuery($query);
		if($this->query_executor!==null){
			$result=($this->query_executor)($query, $vars, $associative);
		}else{
			if(!class_exists('\dataphyre\sql') || !method_exists('\dataphyre\sql', 'query')){
				throw new RuntimeException('Dataphyre SQL must be booted before a seed can execute queries.');
			}
			$result=\dataphyre\sql::query($query, $vars, $associative, false, false, false, null);
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
