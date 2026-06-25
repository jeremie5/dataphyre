<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SQL_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SQL_TRAIT_LOADED', true);

/**
 * Defines Flightdeck kernel trait responsibilities for dataphyre flightdeck debugbar sql.
 *
 * Flightdeck kernel boundary: requests, route dispatch, controller execution, operator UI, generated assets, or response rendering.
 */
trait dataphyre_flightdeck_debugbar_sql {

	/**
	 * Builds the SQL panel state from normalized runtime SQL events.
	 *
	 * The state groups executed and queued queries, cache events, failures,
	 * duplicates, target summaries, operation summaries, cache summaries, template
	 * binding groups, and insight records. It reads process-local trace buffers only
	 * and returns aggregate data for rendering without mutating SQL state.
	 *
	 * @return array<string, mixed> SQL debugbar state payload.
	 */
	private static function sql_state(): array {
		$events=self::sql_events();
		$query_events=array_values(array_filter($events, static function(array $event): bool {
			$operation=(string)($event['operation'] ?? '');
			return in_array($operation, ['query', 'select', 'count', 'insert', 'update', 'delete', 'upsert'], true)
				&& in_array((string)($event['event'] ?? ''), ['execute', 'queue_push'], true);
		}));
		$execute_events=array_values(array_filter($query_events, static fn(array $event): bool => ($event['event'] ?? '')==='execute'));
		$queue_execute_events=array_values(array_filter($events, static fn(array $event): bool => ($event['operation'] ?? '')==='queue_execute' && ($event['event'] ?? '')==='queue_execute_end'));
		$cache_events=array_values(array_filter($events, static fn(array $event): bool => str_starts_with((string)($event['event'] ?? ''), 'cache_')));
		$total_duration=0.0;
		$slow_events=0;
		foreach($execute_events as $event){
			$duration=(float)($event['context']['duration_ms'] ?? 0.0);
			$total_duration+=$duration;
			if($duration>=50.0){
				$slow_events++;
			}
		}
		$failed_events=count(array_filter($events, static fn(array $event): bool => ($event['result_ok'] ?? null)===false));
		$duplicates=self::duplicate_sql_events($query_events);
		$templated=array_values(array_filter($query_events, static function(array $event): bool {
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			return trim((string)($context['render_trace_id'] ?? ''))!=='' || trim((string)($context['binding_trace_id'] ?? ''))!=='';
		}));
		$target_summary=self::sql_target_summary($query_events);
		$operation_summary=self::sql_operation_summary($query_events);
		$cache_summary=self::sql_cache_summary($cache_events);
		$insights=self::sql_insights($query_events, $duplicates, $target_summary, $cache_summary);
		return [
			'events'=>$events,
			'query_events'=>count($query_events),
			'execute_events'=>count($execute_events),
			'queued_events'=>count(array_filter($query_events, static fn(array $event): bool => ($event['queued'] ?? false)===true)),
			'queue_execute_events'=>count($queue_execute_events),
			'failed_events'=>$failed_events,
			'slow_events'=>$slow_events,
			'total_duration_ms'=>round($total_duration, 3),
			'cache_hits'=>count(array_filter($cache_events, static fn(array $event): bool => ($event['event'] ?? '')==='cache_hit')),
			'cache_misses'=>count(array_filter($cache_events, static fn(array $event): bool => ($event['event'] ?? '')==='cache_miss')),
			'cache_stores'=>count(array_filter($cache_events, static fn(array $event): bool => ($event['event'] ?? '')==='cache_store')),
			'cache_invalidations'=>count(array_filter($cache_events, static fn(array $event): bool => ($event['event'] ?? '')==='cache_invalidate')),
			'slowest'=>self::slowest_sql_events($execute_events, 8),
			'duplicates'=>$duplicates,
			'templated_events'=>$templated,
			'target_summary'=>$target_summary,
			'operation_summary'=>$operation_summary,
			'cache_summary'=>$cache_summary,
			'insights'=>$insights,
		];
	}

	/**
	 * Collects SQL trace events from Flightdeck globals and the Database facade.
	 *
	 * Events are normalized, de-duplicated by timestamp/operation/target/query
	 * shape, then sorted by capture time. Invalid trace payloads are ignored so
	 * debugbar rendering remains best-effort and non-fatal.
	 *
	 * @return array<int,array<string,mixed>> Normalized SQL events in chronological order.
	 */
	private static function sql_events(): array {
		$events=[];
		$seen=[];
		$append=function(mixed $event)use(&$events, &$seen): void{
			$normalized=self::normalize_sql_event($event);
			if($normalized===null){
				return;
			}
			$key=implode('|', [
				(string)round((float)($normalized['timestamp'] ?? 0), 6),
				(string)($normalized['event'] ?? ''),
				(string)($normalized['operation'] ?? ''),
				(string)($normalized['location'] ?? ''),
				(string)($normalized['context']['statement'] ?? $normalized['context']['query'] ?? ''),
				self::json($normalized['context']['vars'] ?? []),
			]);
			if(isset($seen[$key])){
				return;
			}
			$seen[$key]=true;
			$events[]=$normalized;
		};
		foreach(($GLOBALS['dataphyre_flightdeck_sql_events'] ?? []) as $event){
			$append($event);
		}
		if(class_exists('Dataphyre\\Database\\DB', false) && method_exists('Dataphyre\\Database\\DB', 'recentTraces')){
			try{
				foreach(\Dataphyre\Database\DB::recentTraces(self::SQL_BUFFER_LIMIT) as $trace){
					$append($trace);
				}
			}catch(\Throwable){
			}
		}
		usort($events, static fn(array $a, array $b): int => ((float)($a['timestamp'] ?? 0))<=>((float)($b['timestamp'] ?? 0)));
		return array_values($events);
	}

	/**
	 * Converts a raw SQL trace payload into Flightdeck's stable event shape.
	 *
	 * The normalizer accepts arrays, JsonSerializable values, and objects exposing
	 * toArray(). Selected top-level fields are promoted into context for backward
	 * compatibility, and context is sanitized before rendering.
	 *
	 * @param mixed $event Raw SQL event from the kernel, DB facade, or observer bridge.
	 * @return ?array<string,mixed> Normalized SQL event, or null for unsupported input.
	 */
	private static function normalize_sql_event(mixed $event): ?array {
		if(is_object($event) && method_exists($event, 'toArray')){
			$event=$event->toArray();
		}
		elseif($event instanceof \JsonSerializable){
			$event=$event->jsonSerialize();
		}
		if(!is_array($event)){
			return null;
		}
		$context=is_array($event['context'] ?? null) ? $event['context'] : [];
		foreach(['statement', 'query', 'select', 'fields', 'params', 'vars', 'duration_ms', 'result_count', 'affected_entries', 'scope', 'caller', 'stack', 'render_trace_id', 'binding_trace_id', 'template_name', 'binding_name', 'binding_path', 'query_fingerprint', 'query_target_type', 'query_target', 'query_mode'] as $key){
			if(array_key_exists($key, $event) && !array_key_exists($key, $context)){
				$context[$key]=$event[$key];
			}
		}
		return [
			'source'=>self::string_or($event['source'] ?? null, 'kernel'),
			'event'=>self::string_or($event['event'] ?? null, 'unknown'),
			'operation'=>self::string_or($event['operation'] ?? null, ''),
			'message'=>self::string_or($event['message'] ?? null, ''),
			'reason'=>self::string_or($event['reason'] ?? null, ''),
			'location'=>self::string_or($event['location'] ?? null, ''),
			'cluster'=>self::string_or($event['cluster'] ?? null, ''),
			'dbms'=>self::string_or($event['dbms'] ?? null, ''),
			'queue'=>self::string_or($event['queue'] ?? null, ''),
			'queued'=>(bool)($event['queued'] ?? false),
			'cache_status'=>self::string_or($event['cache_status'] ?? null, ''),
			'cache_type'=>self::string_or($event['cache_type'] ?? null, ''),
			'cache_names'=>self::string_list($event['cache_names'] ?? []),
			'invalidation_names'=>self::string_list($event['invalidation_names'] ?? []),
			'result_ok'=>array_key_exists('result_ok', $event) ? (is_bool($event['result_ok']) ? $event['result_ok'] : null) : null,
			'context'=>self::sanitize_context($context),
			'timestamp'=>is_numeric($event['timestamp'] ?? null) ? (float)$event['timestamp'] : microtime(true),
		];
	}

	/**
	 * Renders the SQL Flight Recorder panel from aggregated SQL state.
	 *
	 * The panel combines request metrics, insight rows, target/operation/cache
	 * summaries, recent events, slowest events, and duplicate query shapes. All
	 * trace text is escaped before entering the debugbar DOM.
	 *
	 * @param array<string,mixed> $sql Aggregated state from sql_state().
	 * @return string SQL debugbar panel HTML.
	 */
	private static function render_sql_panel(array $sql): string {
		$events=is_array($sql['events'] ?? null) ? $sql['events'] : [];
		$slowest=is_array($sql['slowest'] ?? null) ? $sql['slowest'] : [];
		$duplicates=is_array($sql['duplicates'] ?? null) ? $sql['duplicates'] : [];
		$target_summary=is_array($sql['target_summary'] ?? null) ? $sql['target_summary'] : [];
		$operation_summary=is_array($sql['operation_summary'] ?? null) ? $sql['operation_summary'] : [];
		$cache_summary=is_array($sql['cache_summary'] ?? null) ? $sql['cache_summary'] : [];
		$insights=is_array($sql['insights'] ?? null) ? $sql['insights'] : [];
		$recent=array_slice(
			array_reverse(array_values(array_filter(
				$events,
				static fn(array $event): bool => in_array((string)($event['operation'] ?? ''), ['query', 'select', 'count', 'insert', 'update', 'delete', 'upsert', 'queue_execute'], true)
			))),
			0,
			14
		);
		$html='<details id="dfd-panel-sql" class="dfd-panel" data-dfd-panel="sql" open><summary><span>SQL Flight Recorder</span><span class="dfd-muted">'.self::e((string)($sql['query_events'] ?? 0)).' events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Executed', (string)($sql['execute_events'] ?? 0), 'Immediate query executions')
			.self::metric('Queued', (int)($sql['queued_events'] ?? 0).' pushed / '.(int)($sql['queue_execute_events'] ?? 0).' runs', 'Deferred SQL activity')
			.self::metric('SQL Time', self::format_ms((float)($sql['total_duration_ms'] ?? 0)), 'Measured kernel time')
			.self::metric('Cache', (int)($sql['cache_hits'] ?? 0).' hit / '.(int)($sql['cache_misses'] ?? 0).' miss', 'Stores '.(int)($sql['cache_stores'] ?? 0).', clears '.(int)($sql['cache_invalidations'] ?? 0))
			.'</div>';
		if($insights!==[]){
			$html.=self::render_sql_insights($insights);
		}
		if($target_summary!==[]){
			$hot=$target_summary[0];
			$html.='<p class="dfd-muted">Hottest SQL target: '.self::e((string)($hot['target'] ?? 'unknown')).' with '.self::format_ms((float)($hot['total_duration_ms'] ?? 0)).' across '.self::e((string)($hot['count'] ?? 0)).' event'.((int)($hot['count'] ?? 0)===1 ? '' : 's').'.</p>';
			$html.=self::render_sql_target_summary($target_summary);
		}
		if($operation_summary!==[]){
			$html.=self::render_sql_operation_summary($operation_summary);
		}
		if($cache_summary!==[]){
			$html.=self::render_sql_cache_summary($cache_summary);
		}
		if($duplicates!==[]){
			$repeat_count=array_sum(array_map(static fn(array $duplicate): int => max(0, (int)($duplicate['count'] ?? 0) - 1), $duplicates));
			$html.='<p class="dfd-muted">Repeated query shapes: '.self::e((string)count($duplicates)).' groups, '.self::e((string)$repeat_count).' extra executions.</p>';
		}
		if($recent===[]){
			return $html.'<p class="dfd-muted">No SQL trace events captured for this request.</p></div></details>';
		}
		$html.='<table class="dfd-table"><thead><tr><th>When</th><th>Op</th><th>Target</th><th>Time</th><th>Trace</th></tr></thead><tbody>';
		foreach($recent as $event){
			$html.=self::render_sql_row($event);
		}
		$html.='</tbody></table>';
		if($slowest!==[]){
			$html.='<details><summary>Slowest queries</summary><table class="dfd-table"><tbody>';
			foreach($slowest as $event){
				$html.=self::render_sql_row($event);
			}
			$html.='</tbody></table></details>';
		}
		if($duplicates!==[]){
			$html.='<details><summary>Repeated query shapes</summary><table class="dfd-table"><thead><tr><th>Count</th><th>Time</th><th>Target</th><th>Origin</th><th>Statement</th></tr></thead><tbody>';
			foreach(array_slice($duplicates, 0, 10) as $duplicate){
				$html.='<tr>'
					.'<td>'.self::e((string)($duplicate['count'] ?? 0)).'</td>'
					.'<td>'.self::format_ms((float)($duplicate['total_duration_ms'] ?? 0)).'</td>'
					.'<td>'.self::e((string)($duplicate['location'] ?? '')).'</td>'
					.'<td>'.self::e(self::shorten(implode(' / ', array_slice($duplicate['callers'] ?? [], 0, 3)), 160)).'</td>'
					.'<td><code>'.self::e(self::shorten((string)($duplicate['statement'] ?? ''), 220)).'</code></td>'
					.'</tr>';
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders ranked SQL performance and correctness insights.
	 *
	 * Insights are precomputed models with level, evidence, target, and next-check
	 * guidance. The renderer keeps the table bounded so pathological traces do not
	 * overwhelm the debugbar.
	 *
	 * @param array<int,array<string,mixed>> $insights Insight models from sql_insights().
	 * @return string Insight table HTML.
	 */
	private static function render_sql_insights(array $insights): string {
		$html='<details open><summary>SQL insights</summary><table class="dfd-table"><thead><tr><th>Level</th><th>Pattern</th><th>Target</th><th>Evidence</th><th>Next check</th></tr></thead><tbody>';
		foreach(array_slice($insights, 0, 10) as $insight){
			$level=(string)($insight['level'] ?? 'info');
			$evidence=[];
			if((int)($insight['count'] ?? 0)>0){
				$evidence[]=(int)$insight['count'].' event'.((int)$insight['count']===1 ? '' : 's');
			}
			if((float)($insight['time_ms'] ?? 0)>0){
				$evidence[]=self::format_ms((float)$insight['time_ms']);
			}
			if(trim((string)($insight['origin'] ?? ''))!==''){
				$evidence[]=(string)$insight['origin'];
			}
			$html.='<tr>'
				.'<td>'.self::pill(self::e($level), self::level_tone($level)).'</td>'
				.'<td><b>'.self::e((string)($insight['title'] ?? 'SQL insight')).'</b><br><span class="dfd-muted">'.self::e((string)($insight['detail'] ?? '')).'</span></td>'
				.'<td>'.self::e((string)($insight['target'] ?? 'unknown')).'</td>'
				.'<td>'.self::e($evidence!==[] ? implode(' / ', $evidence) : 'current request').'</td>'
				.'<td>'.self::e((string)($insight['next'] ?? 'Inspect the related SQL rows below.')).'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Renders a heatmap of SQL target activity.
	 *
	 * Target rows are sorted by time/count upstream and display operation mix,
	 * total events, execution time, failure/slow/queued signals, and likely caller
	 * or template origins.
	 *
	 * @param array<int,array<string,mixed>> $targets Target summaries from sql_target_summary().
	 * @return string Target heatmap HTML.
	 */
	private static function render_sql_target_summary(array $targets): string {
		$max=max(1.0, ...array_map(static fn(array $target): float => (float)($target['total_duration_ms'] ?? 0), $targets));
		$html='<details open><summary>Target heatmap</summary><table class="dfd-table"><thead><tr><th>Target</th><th>Ops</th><th>Events</th><th>Time</th><th>Signal</th><th>Origin</th></tr></thead><tbody>';
		foreach(array_slice($targets, 0, 12) as $target){
			$time=(float)($target['total_duration_ms'] ?? 0);
			$width=max(2.0, min(100.0, ($time / $max) * 100));
			$tone=(int)($target['failed_count'] ?? 0)>0 ? 'bad' : ((int)($target['slow_count'] ?? 0)>0 ? 'warn' : '');
			$origins=array_merge(
				is_array($target['templates'] ?? null) ? $target['templates'] : [],
				is_array($target['callers'] ?? null) ? $target['callers'] : []
			);
			$signals=[];
			if((int)($target['failed_count'] ?? 0)>0){
				$signals[]=(int)$target['failed_count'].' failed';
			}
			if((int)($target['slow_count'] ?? 0)>0){
				$signals[]=(int)$target['slow_count'].' slow';
			}
			if((int)($target['queued_count'] ?? 0)>0){
				$signals[]=(int)$target['queued_count'].' queued';
			}
			if((float)($target['slowest_ms'] ?? 0)>0){
				$signals[]='max '.self::format_ms((float)$target['slowest_ms']);
			}
			$html.='<tr>'
				.'<td><b>'.self::e((string)($target['target'] ?? 'unknown')).'</b><br><span class="dfd-muted">'.self::e(trim((string)($target['dbms'] ?? '').' '.(string)($target['cluster'] ?? ''))).'</span></td>'
				.'<td>'.self::e(self::count_map_label(is_array($target['operations'] ?? null) ? $target['operations'] : [])).'</td>'
				.'<td>'.self::e((string)($target['count'] ?? 0)).' total<br><span class="dfd-muted">'.self::e((string)($target['execute_count'] ?? 0)).' executed</span></td>'
				.'<td>'.self::format_ms($time).'<div class="dfd-track"><div class="dfd-bar'.($tone!=='' ? ' dfd-'.$tone : '').'" style="width:'.self::e((string)round($width, 2)).'%"></div></div></td>'
				.'<td>'.self::e($signals!==[] ? implode(' / ', $signals) : 'clear').'</td>'
				.'<td>'.self::e(self::shorten(implode(' / ', array_slice(array_values(array_unique($origins)), 0, 4)), 190)).'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Renders aggregate SQL operation mix.
	 *
	 * The operation table separates executed and queued counts so developers can
	 * distinguish immediate request cost from deferred queue activity.
	 *
	 * @param array<int,array<string,mixed>> $operations Operation summaries from sql_operation_summary().
	 * @return string Operation summary HTML.
	 */
	private static function render_sql_operation_summary(array $operations): string {
		$html='<details><summary>Operation mix</summary><table class="dfd-table"><thead><tr><th>Operation</th><th>Events</th><th>Executed</th><th>Queued</th><th>Time</th><th>Signal</th></tr></thead><tbody>';
		foreach(array_slice($operations, 0, 12) as $operation){
			$tone=(int)($operation['failed_count'] ?? 0)>0 ? 'bad' : ((int)($operation['slow_count'] ?? 0)>0 ? 'warn' : '');
			$signal=[];
			if((int)($operation['failed_count'] ?? 0)>0){
				$signal[]=(int)$operation['failed_count'].' failed';
			}
			if((int)($operation['slow_count'] ?? 0)>0){
				$signal[]=(int)$operation['slow_count'].' slow';
			}
			$html.='<tr>'
				.'<td>'.self::pill(self::e((string)($operation['operation'] ?? 'query')), $tone).'</td>'
				.'<td>'.self::e((string)($operation['count'] ?? 0)).'</td>'
				.'<td>'.self::e((string)($operation['execute_count'] ?? 0)).'</td>'
				.'<td>'.self::e((string)($operation['queued_count'] ?? 0)).'</td>'
				.'<td>'.self::format_ms((float)($operation['total_duration_ms'] ?? 0)).'</td>'
				.'<td>'.self::e($signal!==[] ? implode(' / ', $signal) : 'clear').'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Renders cache hit/miss/store/invalidation summaries by target and cache type.
	 *
	 * Cache names and invalidation names are compacted to keep the table readable
	 * while still exposing unstable namespaces and clear targets.
	 *
	 * @param array<int,array<string,mixed>> $caches Cache summaries from sql_cache_summary().
	 * @return string Cache map HTML.
	 */
	private static function render_sql_cache_summary(array $caches): string {
		$html='<details><summary>Cache map</summary><table class="dfd-table"><thead><tr><th>Target</th><th>Type</th><th>Hit</th><th>Miss</th><th>Store</th><th>Clear</th><th>Names</th></tr></thead><tbody>';
		foreach(array_slice($caches, 0, 12) as $cache){
			$names=array_merge(
				is_array($cache['cache_names'] ?? null) ? $cache['cache_names'] : [],
				is_array($cache['invalidation_names'] ?? null) ? $cache['invalidation_names'] : []
			);
			$html.='<tr>'
				.'<td>'.self::e((string)($cache['target'] ?? 'unknown')).'</td>'
				.'<td>'.self::e((string)($cache['cache_type'] ?? 'default')).'</td>'
				.'<td>'.self::e((string)($cache['hits'] ?? 0)).'</td>'
				.'<td>'.self::e((string)($cache['misses'] ?? 0)).'</td>'
				.'<td>'.self::e((string)($cache['stores'] ?? 0)).'</td>'
				.'<td>'.self::e((string)($cache['invalidations'] ?? 0)).'</td>'
				.'<td>'.self::e(self::shorten(implode(', ', array_slice(array_values(array_unique($names)), 0, 8)), 180)).'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Renders one SQL event row and its expandable trace details.
	 *
	 * Statement, variables, and context are displayed inside escaped code blocks;
	 * stack panels are attached when trace metadata exists.
	 *
	 * @param array<string,mixed> $event Normalized SQL event.
	 * @return string SQL event table row HTML.
	 */
	private static function render_sql_row(array $event): string {
		$context=is_array($event['context'] ?? null) ? $event['context'] : [];
		$statement=(string)($context['statement'] ?? $context['query'] ?? '');
		$vars=$context['vars'] ?? [];
		$duration=(float)($context['duration_ms'] ?? 0);
		$caller=self::caller_label($context['caller'] ?? null);
		$status=($event['result_ok'] ?? null)===false ? 'bad' : ($duration>=50.0 ? 'warn' : '');
		$trace_parts=array_values(array_filter([
			$caller,
			(string)($context['template_name'] ?? ''),
			(string)($context['binding_name'] ?? ''),
			(string)($context['binding_path'] ?? ''),
			(string)($event['queue'] ?? ''),
			(string)($event['cache_status'] ?? ''),
		], static fn(string $value): bool => trim($value)!==''));
		$details='';
		if($statement!=='' || $vars!==[]){
			$stack=self::render_sql_stack_panel($event, $context);
			$details='<details><summary><code>'.self::e(self::shorten($statement !== '' ? $statement : (string)($event['operation'] ?? ''), 180)).'</code></summary><pre class="dfd-code">'.self::e(trim($statement)."\n\nvars: ".self::json($vars)."\ncontext: ".self::json($context)).'</pre>'.$stack.'</details>';
		}
		return '<tr>'
			.'<td>'.self::e(date('H:i:s', (int)($event['timestamp'] ?? time()))).'</td>'
			.'<td>'.self::pill(self::e((string)($event['operation'] ?? '')).' '.self::e((string)($event['event'] ?? '')), $status).'</td>'
			.'<td>'.self::e((string)($event['location'] ?? '')).$details.'</td>'
			.'<td>'.($duration>0 ? self::format_ms($duration) : self::e((($event['queued'] ?? false) ? 'queued' : ''))).'</td>'
			.'<td>'.self::e(implode(' / ', $trace_parts)).'</td>'
			.'</tr>';
	}

	/**
	 * Groups query events by database target, DBMS, and cluster.
	 *
	 * Each group tracks operation mix, queued/executed counts, failures, slow
	 * events, total duration, slowest statement, callers, and template origins for
	 * heatmap rendering and insight generation.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized query events.
	 * @return array<int,array<string,mixed>> Target summaries sorted by duration and count.
	 */
	private static function sql_target_summary(array $events): array {
		$groups=[];
		foreach($events as $event){
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$statement=(string)($context['statement'] ?? $context['query'] ?? '');
			$target=trim((string)($event['location'] ?? ''));
			if($target===''){
				$target=trim((string)($context['query_target'] ?? ''));
			}
			if($target===''){
				$target=self::sql_statement_target($statement);
			}
			if($target===''){
				$target='unknown';
			}
			$dbms=trim((string)($event['dbms'] ?? ''));
			$cluster=trim((string)($event['cluster'] ?? ''));
			$key=strtolower($dbms.'|'.$cluster.'|'.$target);
			$operation=trim((string)($event['operation'] ?? 'query'));
			$operation=$operation!=='' ? $operation : 'query';
			$duration=(float)($context['duration_ms'] ?? 0);
			$executed=(string)($event['event'] ?? '')==='execute';
			$queued=!empty($event['queued']) || (string)($event['event'] ?? '')==='queue_push';
			$groups[$key] ??=[
				'target'=>$target,
				'dbms'=>$dbms,
				'cluster'=>$cluster,
				'count'=>0,
				'execute_count'=>0,
				'queued_count'=>0,
				'failed_count'=>0,
				'slow_count'=>0,
				'total_duration_ms'=>0.0,
				'slowest_ms'=>0.0,
				'slowest_statement'=>'',
				'operations'=>[],
				'callers'=>[],
				'templates'=>[],
			];
			$groups[$key]['count']++;
			$groups[$key]['operations'][$operation]=($groups[$key]['operations'][$operation] ?? 0) + 1;
			if($queued){
				$groups[$key]['queued_count']++;
			}
			if($executed){
				$groups[$key]['execute_count']++;
				$groups[$key]['total_duration_ms']+=$duration;
				if($duration>=50.0){
					$groups[$key]['slow_count']++;
				}
				if($duration>(float)$groups[$key]['slowest_ms']){
					$groups[$key]['slowest_ms']=$duration;
					$groups[$key]['slowest_statement']=$statement;
				}
			}
			if(($event['result_ok'] ?? null)===false){
				$groups[$key]['failed_count']++;
			}
			$caller=self::caller_label($context['caller'] ?? null);
			if($caller!=='' && !in_array($caller, $groups[$key]['callers'], true)){
				$groups[$key]['callers'][]=$caller;
			}
			$template=trim(implode(' / ', array_filter([
				(string)($context['template_name'] ?? ''),
				(string)($context['binding_name'] ?? ''),
			], static fn(string $value): bool => trim($value)!=='')));
			if($template!=='' && !in_array($template, $groups[$key]['templates'], true)){
				$groups[$key]['templates'][]=$template;
			}
		}
		foreach($groups as &$group){
			arsort($group['operations']);
			$group['total_duration_ms']=round((float)$group['total_duration_ms'], 3);
			$group['slowest_ms']=round((float)$group['slowest_ms'], 3);
		}
		unset($group);
		$summary=array_values($groups);
		usort($summary, static function(array $a, array $b): int {
			$time=((float)($b['total_duration_ms'] ?? 0))<=>((float)($a['total_duration_ms'] ?? 0));
			return $time!==0 ? $time : ((int)($b['count'] ?? 0))<=>((int)($a['count'] ?? 0));
		});
		return array_slice($summary, 0, 18);
	}

	/**
	 * Groups query events by SQL operation.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized query events.
	 * @return array<int,array<string,mixed>> Operation summaries sorted by duration and count.
	 */
	private static function sql_operation_summary(array $events): array {
		$groups=[];
		foreach($events as $event){
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$operation=trim((string)($event['operation'] ?? 'query'));
			$operation=$operation!=='' ? $operation : 'query';
			$duration=(float)($context['duration_ms'] ?? 0);
			$executed=(string)($event['event'] ?? '')==='execute';
			$queued=!empty($event['queued']) || (string)($event['event'] ?? '')==='queue_push';
			$groups[$operation] ??=[
				'operation'=>$operation,
				'count'=>0,
				'execute_count'=>0,
				'queued_count'=>0,
				'failed_count'=>0,
				'slow_count'=>0,
				'total_duration_ms'=>0.0,
			];
			$groups[$operation]['count']++;
			if($queued){
				$groups[$operation]['queued_count']++;
			}
			if($executed){
				$groups[$operation]['execute_count']++;
				$groups[$operation]['total_duration_ms']+=$duration;
				if($duration>=50.0){
					$groups[$operation]['slow_count']++;
				}
			}
			if(($event['result_ok'] ?? null)===false){
				$groups[$operation]['failed_count']++;
			}
		}
		foreach($groups as &$group){
			$group['total_duration_ms']=round((float)$group['total_duration_ms'], 3);
		}
		unset($group);
		$summary=array_values($groups);
		usort($summary, static function(array $a, array $b): int {
			$time=((float)($b['total_duration_ms'] ?? 0))<=>((float)($a['total_duration_ms'] ?? 0));
			return $time!==0 ? $time : ((int)($b['count'] ?? 0))<=>((int)($a['count'] ?? 0));
		});
		return $summary;
	}

	/**
	 * Groups cache events by target and cache type.
	 *
	 * The summary keeps separate hit, miss, store, and invalidation counters plus
	 * the distinct cache namespaces observed during the request.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized cache events.
	 * @return array<int,array<string,mixed>> Cache summaries sorted by activity.
	 */
	private static function sql_cache_summary(array $events): array {
		$groups=[];
		foreach($events as $event){
			$target=trim((string)($event['location'] ?? ''));
			if($target===''){
				$target='unknown';
			}
			$type=trim((string)($event['cache_type'] ?? ''));
			$type=$type!=='' ? $type : 'default';
			$key=strtolower($target.'|'.$type);
			$groups[$key] ??=[
				'target'=>$target,
				'cache_type'=>$type,
				'hits'=>0,
				'misses'=>0,
				'stores'=>0,
				'invalidations'=>0,
				'cache_names'=>[],
				'invalidation_names'=>[],
			];
			match((string)($event['event'] ?? '')){
				'cache_hit'=>$groups[$key]['hits']++,
				'cache_miss'=>$groups[$key]['misses']++,
				'cache_store'=>$groups[$key]['stores']++,
				'cache_invalidate'=>$groups[$key]['invalidations']++,
				default=>null,
			};
			foreach(is_array($event['cache_names'] ?? null) ? $event['cache_names'] : [] as $name){
				if(is_string($name) && trim($name)!=='' && !in_array($name, $groups[$key]['cache_names'], true)){
					$groups[$key]['cache_names'][]=$name;
				}
			}
			foreach(is_array($event['invalidation_names'] ?? null) ? $event['invalidation_names'] : [] as $name){
				if(is_string($name) && trim($name)!=='' && !in_array($name, $groups[$key]['invalidation_names'], true)){
					$groups[$key]['invalidation_names'][]=$name;
				}
			}
		}
		$summary=array_values($groups);
		usort($summary, static function(array $a, array $b): int {
			$a_total=(int)($a['hits'] ?? 0)+(int)($a['misses'] ?? 0)+(int)($a['stores'] ?? 0)+(int)($a['invalidations'] ?? 0);
			$b_total=(int)($b['hits'] ?? 0)+(int)($b['misses'] ?? 0)+(int)($b['stores'] ?? 0)+(int)($b['invalidations'] ?? 0);
			return $b_total<=>$a_total;
		});
		return $summary;
	}

	/**
	 * Derives actionable SQL insights from request-level SQL summaries.
	 *
	 * The detector looks for repeated query shapes, template binding loops, hot
	 * targets, cache miss pressure, and mixed read/write targets. Insights are
	 * de-duplicated, severity-ranked, and capped before rendering.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized query events.
	 * @param array<int,array<string,mixed>> $duplicates Duplicate query-shape groups.
	 * @param array<int,array<string,mixed>> $target_summary Target summaries.
	 * @param array<int,array<string,mixed>> $cache_summary Cache summaries.
	 * @return array<int,array<string,mixed>> Ranked insight models.
	 */
	private static function sql_insights(array $events, array $duplicates, array $target_summary, array $cache_summary): array {
		$insights=[];
		$add=static function(string $level, string $title, string $detail, string $target, string $next, int $score, int $count=0, float $time_ms=0.0, string $origin='')use(&$insights): void{
			$key=strtolower($title.'|'.$target.'|'.$detail);
			foreach($insights as $existing){
				if((string)($existing['_key'] ?? '')===$key){
					return;
				}
			}
			$insights[]=[
				'_key'=>$key,
				'level'=>$level,
				'title'=>$title,
				'detail'=>$detail,
				'target'=>$target,
				'next'=>$next,
				'score'=>$score,
				'count'=>$count,
				'time_ms'=>round($time_ms, 3),
				'origin'=>$origin,
			];
		};
		foreach($duplicates as $duplicate){
			$count=(int)($duplicate['count'] ?? 0);
			$total=(float)($duplicate['total_duration_ms'] ?? 0);
			if($count<4){
				continue;
			}
			$target=(string)($duplicate['location'] ?? 'unknown');
			$origin=self::shorten(implode(' / ', array_slice(is_array($duplicate['callers'] ?? null) ? $duplicate['callers'] : [], 0, 2)), 160);
			$level=$count>=20 || $total>=1000.0 ? 'error' : 'warning';
			$add($level, 'Likely repeated lookup', 'The same query shape ran '.$count.' times'.($total>0 ? ' for '.self::format_ms($total) : '').'.', $target, 'Batch the lookup, prefetch the relation, or cache the query at the caller/template boundary.', 70 + min(25, $count), $count, $total, $origin);
		}
		foreach(self::sql_template_binding_groups($events) as $group){
			$count=(int)($group['count'] ?? 0);
			if($count<5){
				continue;
			}
			$total=(float)($group['total_duration_ms'] ?? 0);
			$target=(string)($group['target'] ?? 'unknown');
			$template=(string)($group['template'] ?? '');
			$level=$count>=15 || $total>=750.0 ? 'error' : 'warning';
			$add($level, 'Template binding loop', 'A template binding performed SQL '.$count.' times in one request.', $target, 'Move the binding to a prepared collection, memoize it for the render, or load the data before entering the repeated view.', 64 + min(20, $count), $count, $total, $template);
		}
		foreach($target_summary as $target){
			$count=(int)($target['execute_count'] ?? $target['count'] ?? 0);
			$total=(float)($target['total_duration_ms'] ?? 0);
			if($count<3 || $total<250.0){
				continue;
			}
			$name=(string)($target['target'] ?? 'unknown');
			$slow=(int)($target['slow_count'] ?? 0);
			$level=$total>=1500.0 || $slow>=5 ? 'error' : 'warning';
			$add($level, 'Hot SQL target', $name.' consumed '.self::format_ms($total).' across '.$count.' executed event'.($count===1 ? '' : 's').'.', $name, 'Inspect Target heatmap and Slowest queries to decide whether this target needs an index, batching, or cache coverage.', 56 + min(20, (int)floor($total / 100)), $count, $total, self::shorten(implode(' / ', array_slice(is_array($target['callers'] ?? null) ? $target['callers'] : [], 0, 2)), 160));
		}
		foreach($cache_summary as $cache){
			$misses=(int)($cache['misses'] ?? 0);
			$hits=(int)($cache['hits'] ?? 0);
			$stores=(int)($cache['stores'] ?? 0);
			if($misses<4 || $misses<=$hits*2){
				continue;
			}
			$target=(string)($cache['target'] ?? 'unknown');
			$type=(string)($cache['cache_type'] ?? 'default');
			$detail=$misses.' cache miss'.($misses===1 ? '' : 'es').' versus '.$hits.' hit'.($hits===1 ? '' : 's').($stores===0 ? ', with no store event captured.' : '.');
			$add('warning', 'Cache miss pressure', $detail, $target, 'Check whether '.$type.' cache keys are stable and whether this request stores the result after a miss.', 48 + min(20, $misses), $misses + $hits + $stores, 0.0, $type);
		}
		$read_write=self::sql_read_write_targets($target_summary);
		foreach($read_write as $target){
			$operations=is_array($target['operations'] ?? null) ? $target['operations'] : [];
			$write_count=(int)($operations['insert'] ?? 0) + (int)($operations['update'] ?? 0) + (int)($operations['delete'] ?? 0) + (int)($operations['upsert'] ?? 0);
			$read_count=(int)($operations['select'] ?? 0) + (int)($operations['count'] ?? 0) + (int)($operations['query'] ?? 0);
			if($write_count<1 || $read_count<3){
				continue;
			}
			$name=(string)($target['target'] ?? 'unknown');
			$add('info', 'Read/write mix on one target', 'This request read '.$read_count.' time'.($read_count===1 ? '' : 's').' and wrote '.$write_count.' time'.($write_count===1 ? '' : 's').' against the same target.', $name, 'Confirm cache invalidation order and avoid stale reads after writes.', 18 + min(12, $read_count + $write_count), $read_count + $write_count, (float)($target['total_duration_ms'] ?? 0), self::count_map_label($operations));
		}
		usort($insights, static function(array $a, array $b): int {
			$severity=['error'=>3, 'warning'=>2, 'info'=>1];
			$severity_compare=($severity[(string)($b['level'] ?? 'info')] ?? 0)<=>($severity[(string)($a['level'] ?? 'info')] ?? 0);
			return $severity_compare!==0 ? $severity_compare : ((int)($b['score'] ?? 0))<=>((int)($a['score'] ?? 0));
		});
		foreach($insights as &$insight){
			unset($insight['_key']);
		}
		unset($insight);
		return array_slice($insights, 0, 14);
	}

	/**
	 * Groups executed SQL events by template/binding origin and target.
	 *
	 * Template metadata comes from context fields injected by templating or SQL
	 * observers, letting Flightdeck spot loops caused by repeated template
	 * bindings.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized query events.
	 * @return array<int,array<string,mixed>> Template/binding groups sorted by count and duration.
	 */
	private static function sql_template_binding_groups(array $events): array {
		$groups=[];
		foreach($events as $event){
			if((string)($event['event'] ?? '')!=='execute'){
				continue;
			}
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$template=trim((string)($context['template_name'] ?? ''));
			$binding=trim((string)($context['binding_name'] ?? $context['binding_path'] ?? ''));
			if($template==='' && $binding===''){
				continue;
			}
			$target=trim((string)($event['location'] ?? $context['query_target'] ?? 'unknown'));
			$key=strtolower($template.'|'.$binding.'|'.$target);
			$groups[$key] ??=[
				'template'=>trim($template.($binding!=='' ? ' / '.$binding : '')),
				'target'=>$target!=='' ? $target : 'unknown',
				'count'=>0,
				'total_duration_ms'=>0.0,
			];
			$groups[$key]['count']++;
			$groups[$key]['total_duration_ms']+=(float)($context['duration_ms'] ?? 0);
		}
		$result=array_values($groups);
		usort($result, static function(array $a, array $b): int {
			$count=((int)($b['count'] ?? 0))<=>((int)($a['count'] ?? 0));
			return $count!==0 ? $count : ((float)($b['total_duration_ms'] ?? 0))<=>((float)($a['total_duration_ms'] ?? 0));
		});
		return $result;
	}

	/**
	 * Filters target summaries to targets that were both read and written.
	 *
	 * @param array<int,array<string,mixed>> $target_summary Target summaries.
	 * @return array<int,array<string,mixed>> Targets with read and write operation counts.
	 */
	private static function sql_read_write_targets(array $target_summary): array {
		return array_values(array_filter($target_summary, static function(array $target): bool {
			$operations=is_array($target['operations'] ?? null) ? $target['operations'] : [];
			$reads=(int)($operations['select'] ?? 0) + (int)($operations['count'] ?? 0) + (int)($operations['query'] ?? 0);
			$writes=(int)($operations['insert'] ?? 0) + (int)($operations['update'] ?? 0) + (int)($operations['delete'] ?? 0) + (int)($operations['upsert'] ?? 0);
			return $reads>0 && $writes>0;
		}));
	}

	/**
	 * Extracts the likely table/target from a SQL statement preview.
	 *
	 * FROM, INTO, UPDATE, and JOIN tokens are recognized. Statements without a
	 * parseable target are treated as raw SQL instead of being discarded.
	 *
	 * @param string $statement SQL statement preview.
	 * @return string Target name, raw sql, or an empty string.
	 */
	private static function sql_statement_target(string $statement): string {
		$statement=preg_replace('/\s+/', ' ', trim($statement)) ?? trim($statement);
		if($statement===''){
			return '';
		}
		if(preg_match('/\b(?:from|into|update|join)\s+([`"\[]?[a-z0-9_.:-]+[`"\]]?)/i', $statement, $match)!==1){
			return 'raw sql';
		}
		return trim((string)$match[1], '`"[]');
	}

	/**
	 * Formats a count map as compact label text.
	 *
	 * @param array<string|int,int|float> $counts Count map.
	 * @param int $limit Maximum number of entries to include.
	 * @return string Slash-separated count label.
	 */
	private static function count_map_label(array $counts, int $limit=5): string {
		arsort($counts);
		$parts=[];
		foreach(array_slice($counts, 0, $limit, true) as $name=>$count){
			$parts[]=trim((string)$name).' '.(int)$count;
		}
		return $parts!==[] ? implode(' / ', $parts) : 'none';
	}

	/**
	 * Selects the slowest SQL events by measured duration.
	 *
	 * @param array<int,array<string,mixed>> $events Executed SQL events.
	 * @param int $limit Maximum number of events to return.
	 * @return array<int,array<string,mixed>> Slowest events.
	 */
	private static function slowest_sql_events(array $events, int $limit): array {
		usort($events, static fn(array $a, array $b): int => ((float)($b['context']['duration_ms'] ?? 0))<=>((float)($a['context']['duration_ms'] ?? 0)));
		return array_slice($events, 0, max(1, $limit));
	}

	/**
	 * Groups repeated SQL query shapes for duplicate-query diagnostics.
	 *
	 * Query fingerprints from the SQL kernel are preferred. When absent, statement
	 * text is normalized through sql_shape_key() so literal values do not prevent
	 * repeated query detection.
	 *
	 * @param array<int,array<string,mixed>> $events Normalized query events.
	 * @return array<int,array<string,mixed>> Duplicate groups sorted by count and duration.
	 */
	private static function duplicate_sql_events(array $events): array {
		$groups=[];
		foreach($events as $event){
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$statement=(string)($context['statement'] ?? $context['query'] ?? '');
			$key=(string)($context['query_fingerprint'] ?? '');
			if($key===''){
				$key=self::sql_shape_key($statement);
			}
			if($key===''){
				continue;
			}
			$groups[$key] ??=[
				'count'=>0,
				'total_duration_ms'=>0.0,
				'location'=>(string)($event['location'] ?? ''),
				'statement'=>$statement,
				'callers'=>[],
			];
			$groups[$key]['count']++;
			$groups[$key]['total_duration_ms']+=(float)($context['duration_ms'] ?? 0);
			$caller=self::caller_label($context['caller'] ?? null);
			if($caller!=='' && !in_array($caller, $groups[$key]['callers'], true)){
				$groups[$key]['callers'][]=$caller;
			}
		}
		$duplicates=array_values(array_filter($groups, static fn(array $group): bool => (int)($group['count'] ?? 0)>1));
		usort($duplicates, static function(array $a, array $b): int {
			$count_compare=((int)($b['count'] ?? 0))<=>((int)($a['count'] ?? 0));
			return $count_compare!==0 ? $count_compare : ((float)($b['total_duration_ms'] ?? 0))<=>((float)($a['total_duration_ms'] ?? 0));
		});
		return $duplicates;
	}

	/**
	 * Builds a stable fingerprint for a SQL statement shape.
	 *
	 * Quoted strings and numeric literals are replaced with placeholders, then
	 * whitespace/case are normalized before hashing.
	 *
	 * @param string $statement SQL statement preview.
	 * @return string SHA-1 query-shape key, or an empty string.
	 */
	private static function sql_shape_key(string $statement): string {
		$statement=trim($statement);
		if($statement===''){
			return '';
		}
		$shape=preg_replace("/'(?:''|[^'])*'|\"(?:\"\"|[^\"])*\"/", '?', $statement) ?? $statement;
		$shape=preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $shape) ?? $shape;
		$shape=preg_replace('/\s+/', ' ', strtolower(trim($shape))) ?? strtolower(trim($shape));
		return sha1($shape);
	}

	/**
	 * Formats caller trace metadata into a readable label.
	 *
	 * Existing labels are preferred; otherwise the label is rebuilt from file,
	 * line, and call fields.
	 *
	 * @param mixed $caller Caller context from a normalized SQL event.
	 * @return string Human-readable caller label, or an empty string.
	 */
	private static function caller_label(mixed $caller): string {
		if(!is_array($caller)){
			return '';
		}
		$label=(string)($caller['label'] ?? '');
		if(trim($label)!==''){
			return trim($label);
		}
		$file=(string)($caller['file'] ?? '');
		$line=(int)($caller['line'] ?? 0);
		$call=(string)($caller['call'] ?? '');
		$file_label=$file!=='' ? basename($file).($line>0 ? ':'.$line : '') : '';
		return trim($file_label.($call!=='' ? ' '.$call : ''));
	}

}
