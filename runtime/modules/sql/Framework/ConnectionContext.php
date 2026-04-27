<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class ConnectionContext {

	private ?string $cluster;

	public function __construct(?string $cluster=null){
		$this->cluster=$cluster!==null ? trim($cluster) : null;
		if($this->cluster===''){
			$this->cluster=null;
		}
		if($this->cluster!==null && trim($this->cluster)!==''){
			if(!DB::hasCluster($this->cluster)){
				throw SqlError::unknownCluster($this->cluster, DB::clusters(), DB::datacenter());
			}
		}
	}

	public function cluster(): ?string {
		return $this->cluster;
	}

	public function dbms(): ?string {
		return DB::clusterDbms($this->cluster);
	}

	public function begin(): Transaction {
		return (new Transaction($this->cluster))->begin();
	}

	public function transaction(callable $callback): mixed {
		return (new Transaction($this->cluster))->run($callback);
	}

	public function attemptTransaction(callable $callback): TransactionResult {
		return (new Transaction($this->cluster))->attempt($callback);
	}

	public function query(
		string|array $query,
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): mixed {
		return sql_query(
			$this->clusterAwareQuery($query),
			$vars,
			$associative,
			$multipoint,
			$caching,
			$clear_cache
		);
	}

	public function value(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): mixed {
		return $this->query($query, $vars, false, false, $caching, $clear_cache);
	}

	public function row(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): ?array {
		$result=$this->query($query, $vars, true, false, $caching, $clear_cache);
		return is_array($result) ? $result : null;
	}

	public function rows(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): array {
		$result=$this->query($query, $vars, true, true, $caching, $clear_cache);
		return is_array($result) ? $result : [];
	}

	public function queueQuery(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return sql_query(
			$this->clusterAwareQuery($query),
			$vars,
			$associative,
			$multipoint,
			$caching,
			$clear_cache,
			$queue,
			$callback
		);
	}

	public function queueValue(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, false, false, $caching, $clear_cache);
	}

	public function queueRow(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, true, false, $caching, $clear_cache);
	}

	public function queueRows(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, true, true, $caching, $clear_cache);
	}

	private function clusterAwareQuery(string|array $query): string|array {
		if($this->cluster===null || $this->cluster===''){
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
