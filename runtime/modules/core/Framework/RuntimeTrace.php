<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class RuntimeTrace {

	public function __construct(
		private ?string $render_trace_id,
		private ?string $template_name=null,
		private ?array $manifest=null,
		private array $binding_trace=[],
		private array $sql_traces=[]
	){}

	public function renderTraceId(): ?string {
		return $this->render_trace_id;
	}

	public function templateName(): ?string {
		return $this->template_name;
	}

	public function hasManifest(): bool {
		return is_array($this->manifest);
	}

	public function manifest(): ?array {
		return $this->manifest;
	}

	public function hasBindings(): bool {
		return $this->binding_trace!==[];
	}

	public function bindingTrace(): array {
		return $this->binding_trace;
	}

	public function hasSqlTraces(): bool {
		return $this->sql_traces!==[];
	}

	public function sqlTraces(): array {
		return $this->sql_traces;
	}

	public function sqlTraceArrays(): array {
		return array_values(array_map([$this, 'normalizeSqlTrace'], $this->sql_traces));
	}

	public function sqlTracesForBinding(string $binding_trace_id): array {
		$binding_trace_id=trim($binding_trace_id);
		if($binding_trace_id===''){
			return [];
		}
		return array_values(array_filter($this->sql_traces, function(mixed $trace) use($binding_trace_id): bool {
			return $this->sqlTraceBindingId($trace)===$binding_trace_id;
		}));
	}

	public function orphanSqlTraces(): array {
		return array_values(array_filter($this->sql_traces, function(mixed $trace): bool {
			return $this->sqlTraceBindingId($trace)===null;
		}));
	}

	public function bindingsWithSql(): array {
		$grouped=[];
		foreach($this->binding_trace as $binding){
			if(!is_array($binding)){
				continue;
			}
			$binding_trace_id=$this->bindingTraceIdFromBinding($binding);
			$sql_traces=$binding_trace_id!==null ? $this->sqlTracesForBinding($binding_trace_id) : [];
			$grouped[]=array_replace($binding, [
				'sql_trace_count'=>count($sql_traces),
				'sql_traces'=>$sql_traces,
				'sql_trace_arrays'=>array_values(array_map([$this, 'normalizeSqlTrace'], $sql_traces)),
			]);
		}
		return $grouped;
	}

	public function queryFingerprints(): array {
		return $this->queryFingerprintsByDriver();
	}

	public function sqlQueryFingerprints(): array {
		return $this->queryFingerprintsByDriver('sql');
	}

	public function searchQueryFingerprints(): array {
		return $this->queryFingerprintsByDriver('fulltext');
	}

	public function summary(): array {
		$sql_traces=$this->sqlTraceArrays();
		$bindings_with_sql=0;
		foreach($this->bindingsWithSql() as $binding){
			if((int)($binding['sql_trace_count'] ?? 0)>0){
				$bindings_with_sql++;
			}
		}

		return [
			'render_trace_id'=>$this->render_trace_id,
			'template_name'=>$this->template_name,
			'has_manifest'=>$this->hasManifest(),
			'binding_count'=>count($this->binding_trace),
			'binding_with_sql_count'=>$bindings_with_sql,
			'query_fingerprint_count'=>count($this->queryFingerprints()),
			'sql_query_fingerprint_count'=>count($this->sqlQueryFingerprints()),
			'search_query_fingerprint_count'=>count($this->searchQueryFingerprints()),
			'fingerprint_identity_binding_count'=>$this->countBindingsUsingFingerprintIdentity(),
			'sql_trace_count'=>count($sql_traces),
			'orphan_sql_trace_count'=>count($this->orphanSqlTraces()),
			'sql_cache_hit_count'=>$this->countSqlTracesByCacheStatus($sql_traces, 'hit'),
			'sql_cache_miss_count'=>$this->countSqlTracesByCacheStatus($sql_traces, 'miss'),
			'sql_cache_store_count'=>$this->countSqlEvents($sql_traces, 'cache_store'),
			'sql_invalidation_count'=>$this->countSqlEvents($sql_traces, 'cache_invalidate'),
			'sql_warning_count'=>$this->countSqlEvents($sql_traces, 'guardrail_warning'),
		];
	}

	public function toArray(): array {
		return [
			'render_trace_id'=>$this->render_trace_id,
			'template_name'=>$this->template_name,
			'manifest'=>$this->manifest,
			'binding_trace'=>$this->binding_trace,
			'bindings_with_sql'=>$this->bindingsWithSql(),
			'query_fingerprints'=>$this->queryFingerprints(),
			'sql_query_fingerprints'=>$this->sqlQueryFingerprints(),
			'search_query_fingerprints'=>$this->searchQueryFingerprints(),
			'sql_traces'=>$this->sqlTraceArrays(),
			'summary'=>$this->summary(),
		];
	}

	private function bindingTraceIdFromBinding(array $binding): ?string {
		$correlation=is_array($binding['correlation'] ?? null) ? $binding['correlation'] : [];
		$value=$correlation['binding_trace_id'] ?? ($binding['binding_trace_id'] ?? null);
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingQueryFingerprint(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['query_fingerprint'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingQueryIdentitySource(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['source'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingQueryIdentityMode(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['mode'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingDriver(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['driver'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingTarget(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['target'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function bindingTargetType(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['target_type'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	private function sqlTraceBindingId(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'bindingTraceId')){
			$value=$trace->bindingTraceId();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['binding_trace_id'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function sqlTraceQueryFingerprint(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'queryFingerprint')){
			$value=$trace->queryFingerprint();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['query_fingerprint'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function sqlTraceQueryIdentitySource(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'queryIdentitySource')){
			$value=$trace->queryIdentitySource();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['query_identity_source'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function sqlTraceQueryIdentityMode(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'queryIdentityMode')){
			$value=$trace->queryIdentityMode();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['query_identity_mode'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function sqlTraceQueryTargetType(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'queryTargetType')){
			$value=$trace->queryTargetType();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['query_target_type'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function sqlTraceQueryTarget(mixed $trace): ?string {
		if(is_object($trace) && method_exists($trace, 'queryTarget')){
			$value=$trace->queryTarget();
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		if(is_array($trace)){
			$context=is_array($trace['context'] ?? null) ? $trace['context'] : [];
			$value=$context['query_target'] ?? null;
			return is_string($value) && trim($value)!=='' ? trim($value) : null;
		}
		return null;
	}

	private function normalizeSqlTrace(mixed $trace): array {
		if(is_object($trace) && method_exists($trace, 'toArray')){
			$payload=$trace->toArray();
			return is_array($payload) ? $payload : [];
		}
		return is_array($trace) ? $trace : [];
	}

	private function countSqlTracesByCacheStatus(array $sql_traces, string $status): int {
		$count=0;
		foreach($sql_traces as $trace){
			if(($trace['cache_status'] ?? null)===$status){
				$count++;
			}
		}
		return $count;
	}

	private function countSqlEvents(array $sql_traces, string $event): int {
		$count=0;
		foreach($sql_traces as $trace){
			if(($trace['event'] ?? null)===$event){
				$count++;
			}
		}
		return $count;
	}

	private function countBindingsUsingFingerprintIdentity(): int {
		$count=0;
		foreach($this->binding_trace as $binding){
			if(!is_array($binding)){
				continue;
			}
			if($this->bindingQueryIdentitySource($binding)==='fingerprint'){
				$count++;
			}
		}
		return $count;
	}

	private function queryFingerprintsByDriver(?string $driver_filter=null): array {
		$groups=[];

		foreach($this->binding_trace as $binding){
			if(!is_array($binding)){
				continue;
			}
			$driver=$this->bindingDriver($binding);
			$fingerprint=$this->bindingQueryFingerprint($binding);
			if($driver===null || $fingerprint===null){
				continue;
			}
			if($driver_filter!==null && $driver!==$driver_filter){
				continue;
			}
			$key=$driver.'|'.$fingerprint;
			if(!isset($groups[$key])){
				$groups[$key]=[
					'driver'=>$driver,
					'fingerprint'=>$fingerprint,
					'identity_modes'=>[],
					'identity_sources'=>[],
					'paths'=>[],
					'bindings'=>[],
					'targets'=>[],
					'binding_count'=>0,
					'sql_trace_count'=>0,
				];
			}
			$groups[$key]['binding_count']++;
			$mode=$this->bindingQueryIdentityMode($binding);
			if($mode!==null){
				$groups[$key]['identity_modes'][$mode]=true;
			}
			$source=$this->bindingQueryIdentitySource($binding);
			if($source!==null){
				$groups[$key]['identity_sources'][$source]=true;
			}
			$path=$this->traceString($binding['path'] ?? null);
			if($path!==null){
				$groups[$key]['paths'][$path]=true;
			}
			$binding_name=$this->traceString($binding['binding'] ?? null);
			if($binding_name!==null){
				$groups[$key]['bindings'][$binding_name]=true;
			}
			$target_type=$this->bindingTargetType($binding);
			$target=$this->bindingTarget($binding);
			if($target!==null){
				$target_key=($target_type ?? '').'|'.$target;
				$groups[$key]['targets'][$target_key]=array_filter([
					'target_type'=>$target_type,
					'target'=>$target,
				], static fn(mixed $value): bool => $value!==null && $value!=='');
			}
		}

		foreach($this->sql_traces as $trace){
			$fingerprint=$this->sqlTraceQueryFingerprint($trace);
			if($fingerprint===null){
				continue;
			}
			$driver='sql';
			if($driver_filter!==null && $driver!==$driver_filter){
				continue;
			}
			$key=$driver.'|'.$fingerprint;
			if(!isset($groups[$key])){
				$groups[$key]=[
					'driver'=>$driver,
					'fingerprint'=>$fingerprint,
					'identity_modes'=>[],
					'identity_sources'=>[],
					'paths'=>[],
					'bindings'=>[],
					'targets'=>[],
					'binding_count'=>0,
					'sql_trace_count'=>0,
				];
			}
			$groups[$key]['sql_trace_count']++;
			$mode=$this->sqlTraceQueryIdentityMode($trace);
			if($mode!==null){
				$groups[$key]['identity_modes'][$mode]=true;
			}
			$source=$this->sqlTraceQueryIdentitySource($trace);
			if($source!==null){
				$groups[$key]['identity_sources'][$source]=true;
			}
			$target_type=$this->sqlTraceQueryTargetType($trace);
			$target=$this->sqlTraceQueryTarget($trace);
			if($target!==null){
				$target_key=($target_type ?? '').'|'.$target;
				$groups[$key]['targets'][$target_key]=array_filter([
					'target_type'=>$target_type,
					'target'=>$target,
				], static fn(mixed $value): bool => $value!==null && $value!=='');
			}
		}

		foreach($groups as &$group){
			$group['identity_modes']=array_values(array_keys($group['identity_modes']));
			$group['identity_sources']=array_values(array_keys($group['identity_sources']));
			$group['paths']=array_values(array_keys($group['paths']));
			$group['bindings']=array_values(array_keys($group['bindings']));
			$group['targets']=array_values($group['targets']);
		}
		unset($group);

		usort($groups, function(array $left, array $right): int {
			$driver_compare=strcmp((string)($left['driver'] ?? ''), (string)($right['driver'] ?? ''));
			if($driver_compare!==0){
				return $driver_compare;
			}
			return strcmp((string)($left['fingerprint'] ?? ''), (string)($right['fingerprint'] ?? ''));
		});

		return array_values($groups);
	}

	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}
}
