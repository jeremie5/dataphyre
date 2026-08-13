<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Production-oriented, allowlisted SQL data source with fail-closed scope and signed keyset pagination. */
class PanelSqlDataSource implements PanelDataSource, \JsonSerializable {
	private readonly string $name;
	private readonly string $authorizationMode;
	private readonly ?\Closure $authorize;
	private readonly PanelSqlCursorCodec $cursorCodec;
	private readonly int $cursorTtl;
	private readonly bool $countTotal;
	private readonly string $driver;
	/** @var array<string,mixed> */
	private readonly array $executorManifest;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly PanelSqlExecutor $executor, private readonly PanelSqlSchema $schema, array $options=[]) {
		self::assertOptions($options);
		if(array_key_exists('name', $options) && !is_string($options['name'])){ throw new \InvalidArgumentException('Panel SQL data-source name must be a string.'); }
		if(array_key_exists('authorization_mode', $options) && !is_string($options['authorization_mode'])){ throw new \InvalidArgumentException('Panel SQL authorization_mode must be a string.'); }
		if(array_key_exists('cursor_active_key', $options) && !is_string($options['cursor_active_key'])){ throw new \InvalidArgumentException('Panel SQL cursor_active_key must be a string.'); }
		if(array_key_exists('cursor_ttl', $options) && !is_int($options['cursor_ttl'])){ throw new \InvalidArgumentException('Panel SQL cursor_ttl must be an integer.'); }
		if(array_key_exists('count_total', $options) && !is_bool($options['count_total'])){ throw new \InvalidArgumentException('Panel SQL count_total must be boolean.'); }
		$this->name=self::safeName((string)($options['name'] ?? $schema->name()));
		$mode=strtolower(trim((string)($options['authorization_mode'] ?? 'deny')));
		if(!in_array($mode, ['deny','trusted','callback'], true)){ throw new \InvalidArgumentException('Panel SQL authorization_mode must be deny, trusted, or callback.'); }
		$callback=$options['authorize'] ?? null;
		if($mode==='callback' && !is_callable($callback)){ throw new \InvalidArgumentException('Panel SQL callback authorization requires an authorize callable.'); }
		if($mode!=='callback' && $callback!==null){ throw new \InvalidArgumentException('Panel SQL authorize is only valid in callback authorization mode.'); }
		$this->authorizationMode=$mode; $this->authorize=$callback===null ? null : \Closure::fromCallable($callback);

		$keys=$options['cursor_keys'] ?? null;
		if(!is_array($keys) || $keys===[] || array_is_list($keys)){ throw new \InvalidArgumentException('Panel SQL data sources require an explicit cursor_keys map.'); }
		$clock=$options['clock'] ?? null;
		if($clock!==null && !is_callable($clock)){ throw new \InvalidArgumentException('Panel SQL clock must be callable.'); }
		$this->cursorCodec=new PanelSqlCursorCodec($keys, isset($options['cursor_active_key']) ? (string)$options['cursor_active_key'] : null, $clock);
		$this->cursorTtl=(int)($options['cursor_ttl'] ?? 900);
		if($this->cursorTtl<30 || $this->cursorTtl>86400){ throw new \InvalidArgumentException('Panel SQL cursor_ttl must be between 30 and 86400 seconds.'); }
		$this->countTotal=($options['count_total'] ?? true)!==false;
		try{ $manifest=$executor->manifest(); }
		catch(\Throwable $error){ throw new \InvalidArgumentException('Panel SQL executor manifest failed.', 0, $error); }
		$safe=PanelSearchSanitizer::value($manifest);
		if(!is_array($safe) || ($safe!==[] && array_is_list($safe))){ throw new \InvalidArgumentException('Panel SQL executor manifest must be an object-like array.'); }
		$this->executorManifest=$safe;
		try{ $driver=strtolower(trim($executor->driver())); }
		catch(\Throwable $error){ throw new \InvalidArgumentException('Panel SQL executor driver failed.', 0, $error); }
		if(!in_array($driver, ['mysql','pgsql','sqlite'], true)){ throw new \InvalidArgumentException('Panel SQL executor reported an unsupported driver.'); }
		$this->driver=$driver;
	}

	/** @param array<string,mixed> $options */
	public static function usingPdo(\PDO $pdo, PanelSqlSchema $schema, array $options=[]): self {
		return new self(new PanelPdoSqlExecutor($pdo), $schema, $options);
	}

	public function query(PanelDataQuery $query): PanelDataResult {
		PanelQueryCapabilities::fromArray($this->capabilities())->assertSupports($query);
		if($query->includes()!==[]){ throw new PanelUnsupportedQueryException(['include'], $this->capabilities()); }
		if($query->limitValue()>$this->schema->maxLimit()){ throw new PanelUnsupportedQueryException(['max_limit'], $this->capabilities()); }
		$this->assertTenant($query); $securityScope=$this->authorize($query);

		$compiler=new PanelSqlQueryCompiler($this->schema, $this->driver);
		$fingerprint=$compiler->contextFingerprint($query, $securityScope); $decoded=null;
		if($query->cursorToken()!==null){
			try{ $cursor=$this->cursorCodec->decode($query->cursorToken(), $fingerprint); }
			catch(\Throwable $error){ throw new PanelSqlCursorException($error); }
			$decoded=['offset'=>$cursor['offset'], 'values'=>$cursor['values']];
		}
		$plan=$compiler->compile($query, $securityScope, $decoded);

		$total=null;
		if($this->countTotal){
			$value=$this->executeScalar($plan->countSql(), $plan->baseParameters(), 'count');
			if(!is_int($value) && !(is_string($value) && ctype_digit($value))){ throw new PanelSqlExecutionException('count'); }
			$total=(int)$value;
			if($total<0){ throw new PanelSqlExecutionException('count'); }
		}

		$aggregates=[];
		if($plan->aggregateSql()!==null){
			$rows=$this->executeRows($plan->aggregateSql(), $plan->baseParameters(), 'aggregates');
			if(count($rows)!==1){ throw new PanelSqlExecutionException('aggregates'); }
			foreach($plan->aggregateSpecs() as $spec){
				if(!array_key_exists($spec['alias'], $rows[0])){ throw new PanelSqlExecutionException('aggregates'); }
				$aggregates[$spec['alias']]=self::aggregateValue($rows[0][$spec['alias']], $spec['function']);
			}
		}

		$rows=$this->executeRows($plan->sql(), $plan->parameters(), 'rows');
		if(count($rows)>$plan->limit()+1){ throw new PanelSqlExecutionException('rows'); }
		$hasMore=count($rows)>$plan->limit(); if($hasMore){ array_pop($rows); }
		$items=[]; $lastCursorValues=null;
		foreach($rows as $row){
			if(!is_array($row) || array_is_list($row)){ throw new PanelSqlExecutionException('rows'); }
			$cursorValues=[];
			foreach($plan->cursorSorts() as $sort){
				if(!array_key_exists($sort['alias'], $row)){ throw new PanelSqlExecutionException('cursor_projection'); }
				$cursorValues[]=self::cursorValue($row[$sort['alias']]);
			}
			$item=[];
			foreach($plan->projectedFields() as $field){
				if(!array_key_exists($field, $row)){ throw new PanelSqlExecutionException('projection'); }
				$item[$field]=$row[$field];
			}
			$key=$item[$this->schema->primaryKey()] ?? null;
			if((!is_int($key) && !is_string($key)) || (is_string($key) && $key==='')){ throw new PanelSqlExecutionException('record_key'); }
			$items[]=$item; $lastCursorValues=$cursorValues;
		}
		$next=null;
		if($hasMore){
			if($lastCursorValues===null){ throw new PanelSqlExecutionException('cursor_projection'); }
			try{ $next=$this->cursorCodec->encode($fingerprint, $lastCursorValues, $plan->offset()+count($items), $this->cursorTtl); }
			catch(\Throwable $error){ throw new PanelSqlExecutionException('cursor_encode', $error); }
		}
		$page=new PanelDataPage($plan->offset(), $plan->limit(), count($items), $total, $next, null);
		$publicQuery=PanelDataQuery::fromArray($query->urlState());
		return new PanelDataResult($items, $page, $this->name, $aggregates, [], [
			'adapter'=>'sql', 'driver'=>$this->driver, 'schema'=>$this->schema->name(),
			'stable_record_key'=>$this->schema->primaryKey(), 'keyset_cursor'=>true,
			'forward_only_cursor'=>true, 'total_known'=>$total!==null, 'has_more'=>$hasMore,
			'tenant_scope_applied'=>$this->schema->tenantField()!==null && $query->tenantKey()!==null,
			'authorization_scope_applied'=>$this->authorizationMode!=='trusted',
			'authorization_metadata_serialized'=>false, 'snapshot_consistent'=>false,
		], $publicQuery);
	}

	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed {
		$query=($scope ?? PanelDataQuery::make())->cursor(null)->offset(0)->limit(1)->where($this->schema->primaryKey(), $id);
		return $this->query($query)->items()[0] ?? null;
	}

	/** @return array<string,mixed> */
	public function capabilities(): array {
		return array_replace(PanelQueryCapabilities::full('sql'), [
			'relations'=>$this->schema->hasRelations(), 'relation_depth'=>$this->schema->relationDepth(),
			'search'=>true, 'select'=>true, 'include'=>false, 'cursor'=>true, 'offset'=>true,
			'aggregates'=>true, 'tenant'=>$this->schema->tenantField()!==null, 'authorization'=>true,
			'keyset_cursor'=>true, 'cursor_signed'=>true, 'cursor_key_rotation'=>true,
			'cursor_previous'=>false, 'stable_record_keys'=>true, 'record_key_field'=>$this->schema->primaryKey(), 'count_total'=>$this->countTotal,
			'max_limit'=>$this->schema->maxLimit(), 'snapshot_consistent'=>false, 'mutations'=>false,
		]);
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_sql_data_source', 'version'=>1, 'name'=>$this->name,
			'schema'=>$this->schema->manifest(), 'executor'=>$this->executorManifest,
			'capabilities'=>$this->capabilities(), 'authorization'=>[
				'mode'=>$this->authorizationMode, 'fail_closed'=>true,
				'typed_scope_only'=>true, 'callback_serialized'=>false,
			],
			'cursor'=>$this->cursorCodec->jsonSerialize()+['ttl_seconds'=>$this->cursorTtl, 'forward_only'=>true],
			'security'=>[
				'raw_sql_accepted'=>false, 'identifiers_allowlisted'=>true, 'values_parameterized'=>true,
				'dsn_serialized'=>false, 'credentials_serialized'=>false, 'parameters_serialized'=>false,
				'authorization_metadata_serialized'=>false,
			],
			'consistency'=>[
				'count_aggregate_rows_atomic'=>false, 'snapshot_consistent'=>false,
				'host_transaction_supported'=>($this->executorManifest['adapter'] ?? null)==='pdo', 'host_transaction_owned'=>false,
			],
		];
	}

	/** @return array<string,mixed> */ public function jsonSerialize(): array { return $this->manifest(); }
	public function schema(): PanelSqlSchema { return $this->schema; }
	public function executor(): PanelSqlExecutor { return $this->executor; }
	public function cursorCodec(): PanelSqlCursorCodec { return $this->cursorCodec; }

	private function assertTenant(PanelDataQuery $query): void {
		if($this->schema->tenantField()===null && $query->tenantKey()!==null){ throw new PanelSqlAccessDeniedException('tenant_not_supported'); }
		if($this->schema->requiresTenant() && $query->tenantKey()===null){ throw new PanelSqlAccessDeniedException('tenant_required'); }
	}

	private function authorize(PanelDataQuery $query): ?PanelQueryExpression {
		if($this->authorizationMode==='deny'){ throw new PanelSqlAccessDeniedException('authorization_not_configured'); }
		if($this->authorizationMode==='trusted'){ return null; }
		try{ $decision=($this->authorize)($query->authorizationMetadata(), $query->tenantKey(), $query, $this->schema); }
		catch(\Throwable){ throw new PanelSqlAccessDeniedException('authorization_failed'); }
		if($decision===true){ return null; }
		if($decision instanceof PanelQueryExpression){ return $decision; }
		if($decision===false || $decision===null){ throw new PanelSqlAccessDeniedException('authorization_denied'); }
		throw new PanelSqlAccessDeniedException('authorization_invalid');
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */
	private function executeRows(string $sql, array $parameters, string $operation): array {
		try{ return $this->executor->rows($sql, $parameters); }
		catch(PanelSqlExecutionException $error){ throw $error; }
		catch(\Throwable $error){ throw new PanelSqlExecutionException($operation, $error); }
	}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function executeScalar(string $sql, array $parameters, string $operation): mixed {
		try{ return $this->executor->scalar($sql, $parameters); }
		catch(PanelSqlExecutionException $error){ throw $error; }
		catch(\Throwable $error){ throw new PanelSqlExecutionException($operation, $error); }
	}

	private static function cursorValue(mixed $value): null|bool|int|float|string {
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){ return $value; }
		if(is_float($value) && is_finite($value)){ return $value; }
		throw new PanelSqlExecutionException('cursor_projection');
	}

	private static function aggregateValue(mixed $value, string $function): mixed {
		if($value===null){ return null; }
		if(in_array($function, ['count','distinct_count'], true)){
			if(!is_numeric($value)){ throw new PanelSqlExecutionException('aggregates'); }
			return (int)$value;
		}
		if(in_array($function, ['sum','avg'], true) && is_string($value) && is_numeric($value)){
			return str_contains($value, '.') ? (float)$value : (int)$value;
		}
		return $value;
	}

	/** @param array<string,mixed> $options */
	private static function assertOptions(array $options): void {
		$allowed=['name','authorization_mode','authorize','cursor_keys','cursor_active_key','cursor_ttl','count_total','clock'];
		$unknown=array_values(array_diff(array_keys($options), $allowed));
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown Panel SQL data-source option: '.(string)$unknown[0]); }
	}

	private static function safeName(string $name): string {
		$name=strtolower(trim($name)); $name=preg_replace('/[^a-z0-9]+/', '_', $name) ?? ''; $name=trim($name, '_');
		if($name==='' || strlen($name)>64){ throw new \InvalidArgumentException('Panel SQL data-source name must contain between 1 and 64 normalized bytes.'); }
		return $name;
	}
}
