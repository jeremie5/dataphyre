<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable diagnostic view of one rendered Dataphyre runtime.
 *
 * RuntimeTrace correlates template render metadata, binding-resolution trace rows,
 * and SQL/search trace events into payloads that Flightdeck and debug
 * tools can inspect without reaching back into the live renderer. The class treats
 * trace inputs as already-captured facts and only normalizes, groups, and counts
 * them for human-readable diagnostics.
 */
final class RuntimeTrace {

	/** @var array<string, int|string|bool|null>|null */
	private ?array $summary=null;

	/** @var array<int, array<string, mixed>>|null */
	private ?array $sqlTraceArrays=null;

	/** @var array<int, array<string, mixed>>|null */
	private ?array $bindingsWithSql=null;

	/** @var array<string, array<int, mixed>>|null */
	private ?array $sqlTracesByBinding=null;

	/** @var array<int, mixed>|null */
	private ?array $orphanSqlTraces=null;

	/**
	 * Captures render, binding, and SQL trace payloads.
	 *
	 * The constructor performs no I/O. Arrays are kept as supplied so diagnostics
	 * preserve the original trace fields alongside derived summaries.
	 *
	 * @param string|null $renderTraceId Correlation id for the template render.
	 * @param string|null $templateName Rendered template name, when known.
	 * @param array<string, mixed>|null $manifest Template/runtime manifest captured for the render.
	 * @param array<int, mixed> $bindingTrace Binding resolution trace rows.
	 * @param array<int, mixed> $sqlTraces SQL or search trace rows/objects.
	 */
	public function __construct(
		private ?string $renderTraceId,
		private ?string $templateName=null,
		private ?array $manifest=null,
		private array $bindingTrace=[],
		private array $sqlTraces=[]
	){}

	/**
	 * Returns the render correlation identifier.
	 *
	 * @return string|null Render trace id shared by related diagnostic payloads.
	 */
	public function renderTraceId(): ?string {
		return $this->renderTraceId;
	}

	/**
	/**
	 * Returns the rendered template name.
	 *
	 * @return string|null Template name captured by the renderer.
	 */
	public function templateName(): ?string {
		return $this->templateName;
	}

	/**
	/**
	 * Reports whether manifest metadata was captured.
	 *
	 * @return bool True when manifest() will return an array.
	 */
	public function hasManifest(): bool {
		return is_array($this->manifest);
	}

	/**
	 * Returns manifest metadata captured for the render.
	 *
	 * @return array<string, mixed>|null Manifest payload, or null when unavailable.
	 */
	public function manifest(): ?array {
		return $this->manifest;
	}

	/**
	/**
	 * Reports whether binding trace rows were captured.
	 *
	 * @return bool True when bindingTrace() contains at least one entry.
	 */
	public function hasBindings(): bool {
		return $this->bindingTrace!==[];
	}

	/**
	 * Returns raw binding resolution trace rows.
	 *
	 * @return array<int, mixed> Binding trace entries as captured by the renderer.
	 */
	public function bindingTrace(): array {
		return $this->bindingTrace;
	}

	/**
	/**
	 * Reports whether SQL/search trace rows were captured.
	 *
	 * @return bool True when sqlTraces() contains at least one entry.
	 */
	public function hasSqlTraces(): bool {
		return $this->sqlTraces!==[];
	}

	/**
	 * Returns raw SQL/search trace entries.
	 *
	 * @return array<int, mixed> Trace entries as captured by SQL instrumentation.
	 */
	public function sqlTraces(): array {
		return $this->sqlTraces;
	}

	/**
	 * Returns SQL/search traces normalized to arrays.
	 *
	 * @return array<int, array<string, mixed>> Trace payloads safe for JSON/debug output.
	 */
	public function sqlTraceArrays(): array {
		if($this->sqlTraceArrays!==null){
			return $this->sqlTraceArrays;
		}
		return $this->sqlTraceArrays=array_values(array_map([$this, 'normalizeSqlTrace'], $this->sqlTraces));
	}

	/**
	 * Returns SQL/search traces correlated to a binding trace id.
	 *
	 * @param string $bindingTraceId Binding correlation id from a binding trace row.
	 * @return array<int, mixed> Raw traces whose context references the binding id.
	 */
	public function sqlTracesForBinding(string $bindingTraceId): array {
		$bindingTraceId=trim($bindingTraceId);
		if($bindingTraceId===''){
			return [];
		}
		$this->buildSqlTraceCorrelationCache();
		return $this->sqlTracesByBinding[$bindingTraceId] ?? [];
	}

	/**
	 * Returns SQL/search traces that are not tied to a binding row.
	 *
	 * @return array<int, mixed> Raw traces without a binding_trace_id context.
	 */
	public function orphanSqlTraces(): array {
		$this->buildSqlTraceCorrelationCache();
		return $this->orphanSqlTraces ?? [];
	}

	/**
	 * Returns each binding trace row decorated with correlated SQL traces.
	 *
	 * @return array<int, array<string, mixed>> Binding payloads plus sql_trace_count and sql trace arrays.
	 */
	public function bindingsWithSql(): array {
		if($this->bindingsWithSql!==null){
			return $this->bindingsWithSql;
		}
		$grouped=[];
		$sqlTracesByBinding=[];
		foreach($this->sqlTraces as $trace){
			$bindingTraceId=$this->sqlTraceBindingId($trace);
			if($bindingTraceId!==null){
				$sqlTracesByBinding[$bindingTraceId][]=$trace;
			}
		}
		foreach($this->bindingTrace as $binding){
			if(!is_array($binding)){
				continue;
			}
			$bindingTraceId=$this->bindingTraceIdFromBinding($binding);
			$sqlTraces=$bindingTraceId!==null ? ($sqlTracesByBinding[$bindingTraceId] ?? []) : [];
			$sqlTraceArrays=[];
			foreach($sqlTraces as $trace){
				$sqlTraceArrays[]=$this->normalizeSqlTrace($trace);
			}
			$grouped[]=array_replace($binding, [
				'sql_trace_count'=>count($sqlTraces),
				'sql_traces'=>$sqlTraces,
				'sql_trace_arrays'=>$sqlTraceArrays,
			]);
		}
		return $this->bindingsWithSql=$grouped;
	}

	/**
	 * Groups all query fingerprints found in binding and SQL traces.
	 *
	 * @return array<int, array<string, mixed>> Fingerprint groups with drivers, targets, paths, and counts.
	 */
	public function queryFingerprints(): array {
		return $this->queryFingerprintsByDriver();
	}

	/**
	/**
	 * Groups SQL driver query fingerprints.
	 *
	 * @return array<int, array<string, mixed>> Fingerprint groups for SQL traces.
	 */
	public function sqlQueryFingerprints(): array {
		return $this->queryFingerprintsByDriver('sql');
	}

	/**
	/**
	 * Groups fulltext/search driver query fingerprints.
	 *
	 * @return array<int, array<string, mixed>> Fingerprint groups for fulltext traces.
	 */
	public function searchQueryFingerprints(): array {
		return $this->queryFingerprintsByDriver('fulltext');
	}

	/**
	 * Builds aggregate counters for runtime diagnostics.
	 *
	 * @return array<string, int|string|bool|null> Summary counts for bindings, fingerprints, cache, and warnings.
	 */
	public function summary(): array {
		if($this->summary!==null){
			return $this->summary;
		}
		$sqlTraces=$this->sqlTraceArrays();
		$fingerprintCounts=$this->summaryFingerprintCounts();
		$correlationCounts=$this->sqlCorrelationCounts();
		$sqlCacheHitCount=0;
		$sqlCacheMissCount=0;
		$sqlCacheStoreCount=0;
		$sqlInvalidationCount=0;
		$sqlWarningCount=0;
		foreach($sqlTraces as $trace){
			if(($trace['cache_status'] ?? null)==='hit'){
				$sqlCacheHitCount++;
			}
			elseif(($trace['cache_status'] ?? null)==='miss'){
				$sqlCacheMissCount++;
			}
			$event=$trace['event'] ?? null;
			if($event==='cache_store'){
				$sqlCacheStoreCount++;
			}
			elseif($event==='cache_invalidate'){
				$sqlInvalidationCount++;
			}
			elseif($event==='guardrail_warning'){
				$sqlWarningCount++;
			}
		}

		return $this->summary=[
			'render_trace_id'=>$this->renderTraceId,
			'template_name'=>$this->templateName,
			'has_manifest'=>$this->hasManifest(),
			'binding_count'=>count($this->bindingTrace),
			'binding_with_sql_count'=>$correlationCounts['bindings_with_sql'],
			'query_fingerprint_count'=>$fingerprintCounts['total'],
			'sql_query_fingerprint_count'=>$fingerprintCounts['sql'],
			'search_query_fingerprint_count'=>$fingerprintCounts['fulltext'],
			'fingerprint_identity_binding_count'=>$fingerprintCounts['fingerprint_identity_bindings'],
			'sql_trace_count'=>count($sqlTraces),
			'orphan_sql_trace_count'=>$correlationCounts['orphan_sql_traces'],
			'sql_cache_hit_count'=>$sqlCacheHitCount,
			'sql_cache_miss_count'=>$sqlCacheMissCount,
			'sql_cache_store_count'=>$sqlCacheStoreCount,
			'sql_invalidation_count'=>$sqlInvalidationCount,
			'sql_warning_count'=>$sqlWarningCount,
		];
	}

	/**
	 * Serializes the full trace into a stable diagnostic payload.
	 *
	 * @return array<string, mixed> Render metadata, decorated bindings, grouped fingerprints, traces, and summary.
	 */
	public function toArray(): array {
		return [
			'render_trace_id'=>$this->renderTraceId,
			'template_name'=>$this->templateName,
			'manifest'=>$this->manifest,
			'binding_trace'=>$this->bindingTrace,
			'bindings_with_sql'=>$this->bindingsWithSql(),
			'query_fingerprints'=>$this->queryFingerprints(),
			'sql_query_fingerprints'=>$this->sqlQueryFingerprints(),
			'search_query_fingerprints'=>$this->searchQueryFingerprints(),
			'sql_traces'=>$this->sqlTraceArrays(),
			'summary'=>$this->summary(),
		];
	}

	/**
	 * Extracts a binding correlation id from a binding trace row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Non-empty binding trace id, or null when absent.
	 */
	private function bindingTraceIdFromBinding(array $binding): ?string {
		$correlation=is_array($binding['correlation'] ?? null) ? $binding['correlation'] : [];
		$value=$correlation['binding_trace_id'] ?? ($binding['binding_trace_id'] ?? null);
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the query fingerprint recorded on a binding row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Query fingerprint, or null when absent.
	 */
	private function bindingQueryFingerprint(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['query_fingerprint'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the identity source used to derive a binding fingerprint.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Identity source such as fingerprint, path, or query.
	 */
	private function bindingQueryIdentitySource(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['source'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the fingerprint identity mode from a binding row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Identity mode captured by the binding resolver.
	 */
	private function bindingQueryIdentityMode(array $binding): ?string {
		$identity=is_array($binding['identity'] ?? null) ? $binding['identity'] : [];
		$value=$identity['mode'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the source driver from a binding row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Source driver such as sql or fulltext.
	 */
	private function bindingDriver(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['driver'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the source target from a binding row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Target table, index, resource, or other source name.
	 */
	private function bindingTarget(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['target'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts the source target type from a binding row.
	 *
	 * @param array<string, mixed> $binding Binding trace row.
	 * @return string|null Target type such as table or index.
	 */
	private function bindingTargetType(array $binding): ?string {
		$source=is_array($binding['source'] ?? null) ? $binding['source'] : [];
		$value=$source['target_type'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Extracts a binding correlation id from a SQL trace object or array.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Binding trace id, or null when the trace is orphaned.
	 */
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

	/**
	 * Builds raw SQL trace correlation views used by repeated diagnostic lookups.
	 *
	 * @return void
	 */
	private function buildSqlTraceCorrelationCache(): void {
		if($this->sqlTracesByBinding!==null && $this->orphanSqlTraces!==null){
			return;
		}
		$byBinding=[];
		$orphans=[];
		foreach($this->sqlTraces as $trace){
			$bindingTraceId=$this->sqlTraceBindingId($trace);
			if($bindingTraceId===null){
				$orphans[]=$trace;
				continue;
			}
			$byBinding[$bindingTraceId][]=$trace;
		}
		$this->sqlTracesByBinding=$byBinding;
		$this->orphanSqlTraces=$orphans;
	}

	/**
	 * Extracts a query fingerprint from a SQL trace object or array.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Query fingerprint, or null when absent.
	 */
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

	/**
	 * Extracts the query identity source from a SQL trace.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Identity source captured by instrumentation.
	 */
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

	/**
	 * Extracts the query identity mode from a SQL trace.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Identity mode captured by instrumentation.
	 */
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

	/**
	 * Extracts the query target type from a SQL trace.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Target type captured by instrumentation.
	 */
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

	/**
	 * Extracts the query target from a SQL trace.
	 *
	 * @param mixed $trace SQL trace object with accessors or normalized trace array.
	 * @return string|null Target table/index/resource captured by instrumentation.
	 */
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

	/**
	 * Converts SQL trace objects or arrays into arrays.
	 *
	 * @param mixed $trace Trace object exposing toArray(), raw array, or unknown value.
	 * @return array<string, mixed> Normalized trace payload.
	 */
	private function normalizeSqlTrace(mixed $trace): array {
		if(is_object($trace) && method_exists($trace, 'toArray')){
			$payload=$trace->toArray();
			return is_array($payload) ? $payload : [];
		}
		return is_array($trace) ? $trace : [];
	}

	/**
	 * Counts normalized SQL traces by cache status.
	 *
	 * @param array<int, array<string, mixed>> $sqlTraces Normalized SQL trace payloads.
	 * @param string $status Cache status value to count.
	 * @return int Matching trace count.
	 */
	private function countSqlTracesByCacheStatus(array $sqlTraces, string $status): int {
		$count=0;
		foreach($sqlTraces as $trace){
			if(($trace['cache_status'] ?? null)===$status){
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Counts normalized SQL traces by event name.
	 *
	 * @param array<int, array<string, mixed>> $sqlTraces Normalized SQL trace payloads.
	 * @param string $event Event value to count.
	 * @return int Matching event count.
	 */
	private function countSqlEvents(array $sqlTraces, string $event): int {
		$count=0;
		foreach($sqlTraces as $trace){
			if(($trace['event'] ?? null)===$event){
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Counts summary fingerprint groups without building full diagnostic payloads.
	 *
	 * @return array{total:int, sql:int, fulltext:int, fingerprint_identity_bindings:int}
	 */
	private function summaryFingerprintCounts(): array {
		$groups=[];
		$sqlGroups=0;
		$fulltextGroups=0;
		$fingerprintIdentityBindings=0;

		foreach($this->bindingTrace as $binding){
			if(!is_array($binding)){
				continue;
			}
			if($this->bindingQueryIdentitySource($binding)==='fingerprint'){
				$fingerprintIdentityBindings++;
			}
			$driver=$this->bindingDriver($binding);
			$fingerprint=$this->bindingQueryFingerprint($binding);
			if($driver===null || $fingerprint===null){
				continue;
			}
			$key=$driver.'|'.$fingerprint;
			if(isset($groups[$key])){
				continue;
			}
			$groups[$key]=true;
			if($driver==='sql'){
				$sqlGroups++;
			}
			elseif($driver==='fulltext'){
				$fulltextGroups++;
			}
		}

		foreach($this->sqlTraces as $trace){
			$fingerprint=$this->sqlTraceQueryFingerprint($trace);
			if($fingerprint===null){
				continue;
			}
			$key='sql|'.$fingerprint;
			if(isset($groups[$key])){
				continue;
			}
			$groups[$key]=true;
			$sqlGroups++;
		}

		return [
			'total'=>count($groups),
			'sql'=>$sqlGroups,
			'fulltext'=>$fulltextGroups,
			'fingerprint_identity_bindings'=>$fingerprintIdentityBindings,
		];
	}

	/**
	 * Counts SQL/binding correlation totals without building decorated payloads.
	 *
	 * @return array{bindings_with_sql:int, orphan_sql_traces:int}
	 */
	private function sqlCorrelationCounts(): array {
		$sqlTracesByBinding=[];
		$orphanSqlTraces=0;
		foreach($this->sqlTraces as $trace){
			$bindingTraceId=$this->sqlTraceBindingId($trace);
			if($bindingTraceId===null){
				$orphanSqlTraces++;
				continue;
			}
			$sqlTracesByBinding[$bindingTraceId]=true;
		}

		$bindingsWithSql=0;
		foreach($this->bindingTrace as $binding){
			if(!is_array($binding)){
				continue;
			}
			$bindingTraceId=$this->bindingTraceIdFromBinding($binding);
			if($bindingTraceId!==null && isset($sqlTracesByBinding[$bindingTraceId])){
				$bindingsWithSql++;
			}
		}

		return [
			'bindings_with_sql'=>$bindingsWithSql,
			'orphan_sql_traces'=>$orphanSqlTraces,
		];
	}

	/**
	 * Builds grouped query fingerprint diagnostics, optionally by driver.
	 *
	 * Binding rows contribute path, binding name, target, identity source, and
	 * identity mode metadata. SQL traces contribute execution counts and target
	 * metadata. Groups are sorted by driver and fingerprint for stable rendering.
	 *
	 * @param string|null $driverFilter Optional driver filter such as sql or fulltext.
	 * @return array<int, array<string, mixed>> Grouped query fingerprint diagnostics.
	 */
	private function queryFingerprintsByDriver(?string $driverFilter=null): array {
		$groups=[];

		foreach($this->bindingTrace as $binding){
			if(!is_array($binding)){
				continue;
			}
			$driver=$this->bindingDriver($binding);
			$fingerprint=$this->bindingQueryFingerprint($binding);
			if($driver===null || $fingerprint===null){
				continue;
			}
			if($driverFilter!==null && $driver!==$driverFilter){
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
			$bindingName=$this->traceString($binding['binding'] ?? null);
			if($bindingName!==null){
				$groups[$key]['bindings'][$bindingName]=true;
			}
			$targetType=$this->bindingTargetType($binding);
			$target=$this->bindingTarget($binding);
			if($target!==null){
				$targetKey=($targetType ?? '').'|'.$target;
				$groups[$key]['targets'][$targetKey]=array_filter([
					'target_type'=>$targetType,
					'target'=>$target,
				], static fn(mixed $value): bool => $value!==null && $value!=='');
			}
		}

		foreach($this->sqlTraces as $trace){
			$fingerprint=$this->sqlTraceQueryFingerprint($trace);
			if($fingerprint===null){
				continue;
			}
			$driver='sql';
			if($driverFilter!==null && $driver!==$driverFilter){
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
			$targetType=$this->sqlTraceQueryTargetType($trace);
			$target=$this->sqlTraceQueryTarget($trace);
			if($target!==null){
				$targetKey=($targetType ?? '').'|'.$target;
				$groups[$key]['targets'][$targetKey]=array_filter([
					'target_type'=>$targetType,
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
			$driverCompare=strcmp((string)($left['driver'] ?? ''), (string)($right['driver'] ?? ''));
			if($driverCompare!==0){
				return $driverCompare;
			}
			return strcmp((string)($left['fingerprint'] ?? ''), (string)($right['fingerprint'] ?? ''));
		});

		return array_values($groups);
	}

	/**
	 * Normalizes optional trace strings.
	 *
	 * @param mixed $value Candidate trace field value.
	 * @return string|null Trimmed string, or null when absent/empty.
	 */
	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}
}
