<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\RuntimeTrace;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['core']);

final class DpRuntimeTraceObject {
	public function __construct(
		private ?string $binding,
		private ?string $fingerprint,
		private ?string $source,
		private ?string $mode,
		private ?string $targetType,
		private ?string $target,
		private mixed $payload
	){}
	public function bindingTraceId(): ?string { return $this->binding; }
	public function queryFingerprint(): ?string { return $this->fingerprint; }
	public function queryIdentitySource(): ?string { return $this->source; }
	public function queryIdentityMode(): ?string { return $this->mode; }
	public function queryTargetType(): ?string { return $this->targetType; }
	public function queryTarget(): ?string { return $this->target; }
	public function toArray(): mixed { return $this->payload; }
}

final class DpRuntimeTraceSparseObject {
	public function queryFingerprint(): string { return 'fp-sparse'; }
}

test('runtime trace correlates array and object SQL traces into complete diagnostics', static function(Context $t): void {
	$bindings=[
		[
			'path'=>'orders', 'binding'=>'orders',
			'correlation'=>['binding_trace_id'=>'bind-1'],
			'identity'=>['query_fingerprint'=>'fp-orders', 'source'=>'fingerprint', 'mode'=>'query'],
			'source'=>['driver'=>'sql', 'target_type'=>'table', 'target'=>'orders'],
		],
		[
			'path'=>'search', 'binding'=>'results', 'binding_trace_id'=>'bind-2',
			'identity'=>['query_fingerprint'=>'fp-search', 'source'=>'path', 'mode'=>'search'],
			'source'=>['driver'=>'fulltext', 'target_type'=>'index', 'target'=>'products'],
		],
		[
			'path'=>'orders-again', 'binding'=>'orders2',
			'identity'=>['query_fingerprint'=>'fp-orders', 'source'=>'fingerprint', 'mode'=>'query'],
			'source'=>['driver'=>'sql', 'target_type'=>'table', 'target'=>'orders'],
		],
		['identity'=>[], 'source'=>[]],
		'ignored-binding',
	];
	$arrayTrace=[
		'event'=>'cache_store', 'cache_status'=>'miss',
		'context'=>[
			'binding_trace_id'=>'bind-1', 'query_fingerprint'=>'fp-orders',
			'query_identity_source'=>'fingerprint', 'query_identity_mode'=>'query',
			'query_target_type'=>'table', 'query_target'=>'orders',
		],
	];
	$objectTrace=new DpRuntimeTraceObject(
		'bind-2', 'fp-object', 'query', 'prepared', 'table', 'customers',
		['event'=>'cache_invalidate', 'cache_status'=>'hit', 'context'=>['binding_trace_id'=>'bind-2']]
	);
	$orphan=[
		'event'=>'guardrail_warning', 'cache_status'=>'hit',
		'context'=>['query_fingerprint'=>'fp-orphan', 'query_target'=>'audit'],
	];
	$blankObject=new DpRuntimeTraceObject(' ', ' ', ' ', '', '', '', 'not-an-array');
	$trace=new RuntimeTrace('render-1', 'orders.tpl', ['version'=>1], $bindings, [
		$arrayTrace, $objectTrace, $orphan, $blankObject, 'invalid-trace',
	]);
	$t->same('render-1', $trace->renderTraceId());
	$t->same('orders.tpl', $trace->templateName());
	$t->isTrue($trace->hasManifest());
	$t->same(['version'=>1], $trace->manifest());
	$t->isTrue($trace->hasBindings());
	$t->same($bindings, $trace->bindingTrace());
	$t->isTrue($trace->hasSqlTraces());
	$t->same(5, count($trace->sqlTraces()));
	$t->same(5, count($trace->sqlTraceArrays()));
	$t->same([], $trace->sqlTraceArrays()[3]);
	$t->same([], $trace->sqlTraceArrays()[4]);
	$t->same([$arrayTrace], $trace->sqlTracesForBinding('bind-1'));
	$t->same([$objectTrace], $trace->sqlTracesForBinding('bind-2'));
	$t->same([], $trace->sqlTracesForBinding(' '));
	$t->same(3, count($trace->orphanSqlTraces()));
	$t->same(4, count($trace->bindingsWithSql()));
	$t->same(1, $trace->bindingsWithSql()[0]['sql_trace_count']);
	$t->same(0, $trace->bindingsWithSql()[2]['sql_trace_count']);
	$t->producesStableResult(static fn()=>$trace->bindingsWithSql());
	$t->producesStableResult(static fn()=>$trace->orphanSqlTraces());

	$all=$trace->queryFingerprints();
	$t->same(4, count($all));
	$t->same(3, count($trace->sqlQueryFingerprints()));
	$t->same(1, count($trace->searchQueryFingerprints()));
	$orders=array_values(array_filter($all, static fn(array $group): bool=>$group['fingerprint']==='fp-orders'))[0];
	$t->same(2, $orders['binding_count']);
	$t->same(1, $orders['sql_trace_count']);
	$t->same(['query'], $orders['identity_modes']);
	$t->same(['fingerprint'], $orders['identity_sources']);
	$t->same(['orders', 'orders-again'], $orders['paths']);
	$t->same(['orders', 'orders2'], $orders['bindings']);
	$t->same([['target_type'=>'table', 'target'=>'orders']], $orders['targets']);

	$summary=$trace->summary();
	$t->same(5, $summary['binding_count']);
	$t->same(2, $summary['binding_with_sql_count']);
	$t->same(4, $summary['query_fingerprint_count']);
	$t->same(3, $summary['sql_query_fingerprint_count']);
	$t->same(1, $summary['search_query_fingerprint_count']);
	$t->same(2, $summary['fingerprint_identity_binding_count']);
	$t->same(5, $summary['sql_trace_count']);
	$t->same(3, $summary['orphan_sql_trace_count']);
	$t->same(2, $summary['sql_cache_hit_count']);
	$t->same(1, $summary['sql_cache_miss_count']);
	$t->same(1, $summary['sql_cache_store_count']);
	$t->same(1, $summary['sql_invalidation_count']);
	$t->same(1, $summary['sql_warning_count']);
	$t->same($summary, $trace->summary());
	$t->same($summary, $trace->toArray()['summary']);
})->tag('core', 'runtime-trace', 'coverage')->group('framework-coverage');

test('runtime trace empty malformed and private counter branches remain deterministic', static function(Context $t): void {
	$trace=new RuntimeTrace(null, null, null, [
		['identity'=>['query_fingerprint'=>null, 'source'=>12, 'mode'=>[]], 'source'=>['driver'=>'', 'target'=>1, 'target_type'=>false]],
		['path'=>' ', 'binding'=>7, 'correlation'=>'invalid'],
		['path'=>7, 'binding'=>false, 'identity'=>['query_fingerprint'=>'fp-binding'], 'source'=>['driver'=>'sql']],
	], [
		['context'=>['binding_trace_id'=>7, 'query_fingerprint'=>false, 'query_identity_source'=>[], 'query_identity_mode'=>1, 'query_target_type'=>2, 'query_target'=>[]]],
		new stdClass(),
		new DpRuntimeTraceSparseObject(),
	]);
	$t->same(null, $trace->renderTraceId());
	$t->same(null, $trace->templateName());
	$t->isFalse($trace->hasManifest());
	$t->same(null, $trace->manifest());
	$t->isTrue($trace->hasBindings());
	$t->isTrue($trace->hasSqlTraces());
	$t->same(2, count($trace->queryFingerprints()));
	$t->same(2, count($trace->sqlQueryFingerprints()));
	$t->same([], $trace->searchQueryFingerprints());
	$t->same(3, $trace->summary()['orphan_sql_trace_count']);

	$empty=new RuntimeTrace('empty');
	$t->isFalse($empty->hasBindings());
	$t->isFalse($empty->hasSqlTraces());
	$t->same([], $empty->bindingsWithSql());
	$t->same([], $empty->orphanSqlTraces());
	$t->same(0, $empty->summary()['sql_trace_count']);

	$private=$t->nonPublic($trace);
	$rows=[
		['cache_status'=>'hit', 'event'=>'one'],
		['cache_status'=>'miss', 'event'=>'two'],
		['cache_status'=>'hit', 'event'=>'two'],
	];
	$t->same(2,$private->invoke('countSqlTracesByCacheStatus',$rows,'hit'));
	$t->same(0,$private->invoke('countSqlTracesByCacheStatus',$rows,'store'));
	$t->same(2,$private->invoke('countSqlEvents',$rows,'two'));
	$t->same(0,$private->invoke('countSqlEvents',$rows,'missing'));
})->tag('core', 'runtime-trace', 'coverage')->group('framework-coverage');
