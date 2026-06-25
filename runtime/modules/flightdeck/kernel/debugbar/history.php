<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_HISTORY_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_HISTORY_TRAIT_LOADED', true);

/**
 * Stores and compares Flightdeck debugbar request snapshots.
 *
 * The history trait keeps a bounded session-local timeline of application
 * requests, browser telemetry, replay diagnostics, and comparison metrics. It
 * never writes persistent storage; the data exists only inside the authenticated
 * Flightdeck session.
 */
trait dataphyre_flightdeck_debugbar_history {

	/**
	 * Returns normalized request snapshots from the current session.
	 *
	 * @return array<int, array<string, mixed>> Session snapshots with ids.
	 */
	public static function history(): array {
		$history=$_SESSION[self::HISTORY_KEY] ?? [];
		if(!is_array($history)){
			return [];
		}
		$normalized=[];
		foreach($history as $snapshot){
			if(is_array($snapshot) && isset($snapshot['id'])){
				$normalized[]=$snapshot;
			}
		}
		return array_values($normalized);
	}

	/**
	 * Finds a stored request snapshot by id.
	 *
	 *
	 * @param string $id Snapshot id.
	 * @return ?array<string, mixed> Matching snapshot, or null when absent.
	 */
	public static function history_snapshot(string $id): ?array {
		$id=trim($id);
		if($id===''){
			return null;
		}
		foreach(self::history() as $snapshot){
			if((string)($snapshot['id'] ?? '')===$id){
				return $snapshot;
			}
		}
		return null;
	}

	/**
	 * Clears debugbar history from the current session.
	 *
	 *
	 * @return void
	 */
	public static function clear_history(): void {
		unset($_SESSION[self::HISTORY_KEY]);
	}

	/**
	 * Creates the HMAC token used by the browser telemetry endpoint.
	 *
	 * The token binds client events to one captured server snapshot and prevents
	 * unrelated pages from posting telemetry into the session history.
	 *
	 * @param string $snapshot_id Snapshot id issued by the debugbar.
	 * @return string HMAC token for client event submission.
	 */
	public static function client_token(string $snapshot_id): string {
		return hash_hmac('sha256', 'client|'.trim($snapshot_id), self::secret());
	}

	/**
	 * Creates a short-lived token for read-only production replay.
	 *
	 * @param string $method HTTP method to replay.
	 * @param string $uri Request URI to replay.
	 * @return string Signed replay token, or empty string when replay is unavailable.
	 */
	private static function replay_token(string $method, string $uri): string {
		$secret=self::replay_secret();
		if($secret===''){
			return '';
		}
		$payload=[
			'iat'=>time(),
			'exp'=>time() + 180,
			'method'=>strtoupper(trim($method)),
			'uri'=>$uri,
			'nonce'=>bin2hex(random_bytes(10)),
		];
		$data=self::base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
		return $data.'.'.hash_hmac('sha256', $data, $secret);
	}

	/**
	 * Records browser telemetry for a stored snapshot.
	 *
	 * Events are accepted only with a matching client token and active session.
	 * Accepted events are normalized, linked back to matching server snapshots
	 * when possible, merged into the snapshot client state, and folded into
	 * diagnostics/comparison data.
	 *
	 * @param string $snapshot_id Snapshot id to update.
	 * @param string $token HMAC token produced by client_token().
	 * @param array<int, mixed> $events Raw browser telemetry events.
	 * @return array<string, mixed> Acceptance result and event counts.
	 */
	public static function record_client_events(string $snapshot_id, string $token, array $events): array {
		$snapshot_id=trim($snapshot_id);
		if($snapshot_id==='' || hash_equals(self::client_token($snapshot_id), $token)!==true){
			return ['ok'=>false, 'message'=>'Invalid client event token.'];
		}
		if(session_status()!==PHP_SESSION_ACTIVE){
			return ['ok'=>false, 'message'=>'No active Flightdeck session.'];
		}
		$normalized=self::normalize_client_events($events);
		if($normalized===[]){
			return ['ok'=>true, 'message'=>'No client events accepted.', 'event_count'=>0];
		}
		$history=self::history();
		$normalized=self::link_client_events_to_history($normalized, $history, $snapshot_id);
		foreach($history as $index=>$snapshot){
			if((string)($snapshot['id'] ?? '')!==$snapshot_id){
				continue;
			}
			$client=self::merge_client_events(is_array($snapshot['client'] ?? null) ? $snapshot['client'] : [], $normalized);
			$history[$index]['client']=$client;
			$history[$index]['diagnostics']=self::with_client_diagnostics(is_array($history[$index]['diagnostics'] ?? null) ? $history[$index]['diagnostics'] : [], $client);
			$previous=self::previous_comparable_snapshot($history, $history[$index]);
			if($previous!==null){
				$history[$index]['comparison']=self::snapshot_comparison($history[$index], $previous);
			}
			else
			{
				unset($history[$index]['comparison']);
			}
			$_SESSION[self::HISTORY_KEY]=self::history_within_session_budget(array_slice($history, 0, self::HISTORY_LIMIT));
			return [
				'ok'=>true,
				'message'=>'Client events recorded.',
				'event_count'=>(int)$client['event_count'],
				'accepted'=>count($normalized),
				'linked'=>count(array_filter($normalized, static fn(array $event): bool => !empty($event['server_snapshot_id']))),
			];
		}
		return ['ok'=>false, 'message'=>'Snapshot was not found.'];
	}

	/**
	 * Stores the current request state in session history.
	 *
	 * CLI, inactive sessions, and Flightdeck control-plane paths are ignored so
	 * history remains focused on application requests.
	 *
	 * @param array<string, mixed> $state Current debugbar runtime state.
	 * @return ?array<string, mixed> Stored snapshot, or null when recording is skipped.
	 */
	private static function record_snapshot(array $state): ?array {
		if(PHP_SAPI==='cli'){
			return null;
		}
		if(session_status()!==PHP_SESSION_ACTIVE){
			return null;
		}
		$path=self::current_path();
		if(self::is_control_plane_path($path)===true){
			return null;
		}
		$snapshot=self::compact_snapshot($state);
		$history=self::history();
		$previous=self::previous_comparable_snapshot($history, $snapshot);
		if($previous!==null){
			$snapshot['comparison']=self::snapshot_comparison($snapshot, $previous);
		}
		$history=array_values(array_filter($history, static fn(array $entry): bool => (string)($entry['id'] ?? '')!==(string)$snapshot['id']));
		array_unshift($history, $snapshot);
		$_SESSION[self::HISTORY_KEY]=self::history_within_session_budget(array_slice($history, 0, self::HISTORY_LIMIT));
		return $snapshot;
	}

	/**
	 * Finds the most recent prior snapshot for the same app/method/path.
	 *
	 * @param array<int, array<string, mixed>> $history Existing session snapshots.
	 * @param array<string, mixed> $snapshot Current snapshot.
	 * @return ?array<string, mixed> Comparable previous snapshot.
	 */
	private static function previous_comparable_snapshot(array $history, array $snapshot): ?array {
		$key=self::comparable_snapshot_key($snapshot);
		if($key===''){
			return null;
		}
		$id=(string)($snapshot['id'] ?? '');
		foreach($history as $candidate){
			if(!is_array($candidate) || (string)($candidate['id'] ?? '')===$id){
				continue;
			}
			if(self::comparable_snapshot_key($candidate)===$key){
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Builds the comparison key used to match request snapshots.
	 *
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 * @return string App, method, and normalized path key.
	 */
	private static function comparable_snapshot_key(array $snapshot): string {
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$app=(string)($snapshot['app'] ?? '');
		$method=strtoupper(trim((string)($snapshot['method'] ?? $request['method'] ?? 'GET')));
		$path=trim((string)($request['path'] ?? ''));
		if($path===''){
			$uri=(string)($snapshot['uri'] ?? '');
			$parsed=parse_url($uri, PHP_URL_PATH);
			$path=is_string($parsed) ? $parsed : $uri;
		}
		$path='/'.ltrim($path, '/');
		$path=preg_replace('#/+#', '/', $path) ?? $path;
		return $method!=='' && $path!=='' ? $app.'|'.$method.'|'.$path : '';
	}

	/**
	 * Compares a snapshot against a prior comparable request.
	 *
	 * @param array<string, mixed> $snapshot Current snapshot.
	 * @param array<string, mixed> $previous Previous comparable snapshot.
	 * @return array<string, mixed> Comparison summary and per-metric changes.
	 */
	private static function snapshot_comparison(array $snapshot, array $previous): array {
		$changes=[];
		foreach(self::comparison_metric_definitions() as $definition){
			$change=self::comparison_change($snapshot, $previous, $definition);
			if($change!==null){
				$changes[]=$change;
			}
		}
		$regressions=count(array_filter($changes, static fn(array $change): bool => in_array((string)($change['tone'] ?? ''), ['warn', 'bad'], true)));
		$error_regressions=count(array_filter($changes, static fn(array $change): bool => (string)($change['tone'] ?? '')==='bad'));
		$improvements=count(array_filter($changes, static fn(array $change): bool => (string)($change['direction'] ?? '')==='better'));
		return [
			'available'=>true,
			'previous_id'=>(string)($previous['id'] ?? ''),
			'previous_label'=>(string)($previous['label'] ?? self::snapshot_label($previous)),
			'previous_recorded_at'=>(int)($previous['recorded_at'] ?? 0),
			'previous_status'=>(int)($previous['request']['status'] ?? 0),
			'key'=>self::comparable_snapshot_key($snapshot),
			'regressions'=>$regressions,
			'error_regressions'=>$error_regressions,
			'improvements'=>$improvements,
			'changes'=>$changes,
			'summary'=>$regressions>0
				? $regressions.' regression'.($regressions===1 ? '' : 's').($improvements>0 ? ', '.$improvements.' improvement'.($improvements===1 ? '' : 's') : '')
				: ($improvements>0 ? $improvements.' improvement'.($improvements===1 ? '' : 's') : 'similar to previous capture'),
		];
	}

	/**
	 * Defines metrics used for request-to-request comparison.
	 *
	 * @return array<int, array<string, mixed>> Metric definitions and thresholds.
	 */
	private static function comparison_metric_definitions(): array {
		return [
			['key'=>'status', 'label'=>'Status', 'unit'=>'status'],
			['key'=>'duration_ms', 'label'=>'Server time', 'unit'=>'ms', 'warn_delta'=>250.0, 'bad_delta'=>2000.0, 'warn_percent'=>0.30, 'bad_percent'=>1.00],
			['key'=>'sql_queries', 'label'=>'SQL queries', 'unit'=>'count', 'warn_delta'=>10.0, 'bad_delta'=>50.0, 'warn_percent'=>0.50, 'bad_percent'=>1.00],
			['key'=>'sql_time_ms', 'label'=>'SQL time', 'unit'=>'ms', 'warn_delta'=>75.0, 'bad_delta'=>500.0, 'warn_percent'=>0.50, 'bad_percent'=>1.00],
			['key'=>'findings', 'label'=>'Findings', 'unit'=>'count', 'warn_delta'=>1.0, 'bad_delta'=>4.0],
			['key'=>'browser_events', 'label'=>'Browser events', 'unit'=>'count', 'warn_delta'=>1.0, 'bad_delta'=>5.0],
			['key'=>'browser_load_ms', 'label'=>'Browser load', 'unit'=>'ms', 'warn_delta'=>500.0, 'bad_delta'=>3000.0, 'warn_percent'=>0.30, 'bad_percent'=>0.80],
			['key'=>'missing_assets', 'label'=>'Missing assets', 'unit'=>'count', 'warn_delta'=>1.0, 'bad_delta'=>5.0],
			['key'=>'api_failures', 'label'=>'API failures', 'unit'=>'count', 'warn_delta'=>1.0, 'bad_delta'=>1.0],
			['key'=>'memory_mb', 'label'=>'Memory', 'unit'=>'mb', 'warn_delta'=>16.0, 'bad_delta'=>64.0, 'warn_percent'=>0.50, 'bad_percent'=>1.00],
			['key'=>'body_bytes', 'label'=>'Body size', 'unit'=>'bytes', 'warn_delta'=>262144.0, 'bad_delta'=>1048576.0, 'warn_percent'=>0.75, 'bad_percent'=>2.00],
		];
	}

	/**
	 * Calculates one metric delta between two snapshots.
	 *
	 * @param array<string, mixed> $snapshot Current snapshot.
	 * @param array<string, mixed> $previous Previous snapshot.
	 * @param array<string, mixed> $definition Metric definition.
	 * @return ?array<string, mixed> Change payload, or null when unchanged.
	 */
	private static function comparison_change(array $snapshot, array $previous, array $definition): ?array {
		$key=(string)($definition['key'] ?? '');
		if($key==='status'){
			$current=(int)self::snapshot_metric_value($snapshot, $key);
			$before=(int)self::snapshot_metric_value($previous, $key);
			if($current===$before){
				return null;
			}
			$current_score=self::status_score($current);
			$before_score=self::status_score($before);
			$direction=$current_score>$before_score ? 'worse' : 'better';
			$tone=$direction==='worse' ? ($current>=500 ? 'bad' : 'warn') : 'good';
			return [
				'key'=>$key,
				'label'=>(string)($definition['label'] ?? $key),
				'previous'=>$before,
				'current'=>$current,
				'delta'=>$current - $before,
				'delta_label'=>$before.' -> '.$current,
				'previous_label'=>(string)$before,
				'current_label'=>(string)$current,
				'direction'=>$direction,
				'tone'=>$tone,
				'significant'=>true,
			];
		}
		$current=(float)self::snapshot_metric_value($snapshot, $key);
		$before=(float)self::snapshot_metric_value($previous, $key);
		$delta=$current - $before;
		if(abs($delta)<0.001){
			return null;
		}
		$abs_delta=abs($delta);
		$percent=$before>0 ? $delta / $before : ($current>0 ? 1.0 : 0.0);
		$warn_delta=(float)($definition['warn_delta'] ?? PHP_FLOAT_MAX);
		$bad_delta=(float)($definition['bad_delta'] ?? PHP_FLOAT_MAX);
		$warn_percent=(float)($definition['warn_percent'] ?? PHP_FLOAT_MAX);
		$bad_percent=(float)($definition['bad_percent'] ?? PHP_FLOAT_MAX);
		$is_regression=$delta>0;
		$is_improvement=$delta<0;
		$significant=$abs_delta>=$warn_delta || abs($percent)>=$warn_percent;
		$tone='';
		if($is_regression && $significant){
			$tone=($abs_delta>=$bad_delta || $percent>=$bad_percent) ? 'bad' : 'warn';
		}
		elseif($is_improvement && $significant){
			$tone='good';
		}
		$unit=(string)($definition['unit'] ?? 'count');
		return [
			'key'=>$key,
			'label'=>(string)($definition['label'] ?? $key),
			'previous'=>$before,
			'current'=>$current,
			'delta'=>$delta,
			'percent'=>$percent,
			'delta_label'=>self::comparison_delta_label($delta, $percent, $unit),
			'previous_label'=>self::comparison_value_label($before, $unit),
			'current_label'=>self::comparison_value_label($current, $unit),
			'direction'=>$is_regression ? 'worse' : 'better',
			'tone'=>$tone,
			'significant'=>$significant,
		];
	}

	/**
	 * Extracts a numeric comparison metric from a snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 * @param string $key Metric key.
	 * @return float|int Numeric metric value.
	 */
	private static function snapshot_metric_value(array $snapshot, string $key): float|int {
		$sql=is_array($snapshot['sql'] ?? null) ? $snapshot['sql'] : [];
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$response=is_array($snapshot['response'] ?? null) ? $snapshot['response'] : [];
		$client=is_array($snapshot['client'] ?? null) ? $snapshot['client'] : [];
		$page=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		return match($key){
			'status'=>(int)($request['status'] ?? 0),
			'duration_ms'=>(float)($snapshot['duration_ms'] ?? 0),
			'sql_queries'=>(int)($sql['query_events'] ?? 0),
			'sql_time_ms'=>(float)($sql['total_duration_ms'] ?? 0),
			'findings'=>(int)($snapshot['diagnostics']['count'] ?? 0),
			'browser_events'=>(int)($client['resource_errors'] ?? 0) + (int)($client['stylesheet_missing'] ?? 0) + (int)($client['js_errors'] ?? 0) + (int)($client['unhandled_rejections'] ?? 0) + (int)($client['client_http_errors'] ?? 0) + (int)($client['client_fetch_errors'] ?? 0) + (int)($client['client_http_slow'] ?? 0) + (int)($client['slow_resources'] ?? 0) + (int)($client['accessibility_issues'] ?? 0),
			'browser_load_ms'=>(float)($page['load_ms'] ?? 0),
			'missing_assets'=>(int)($response['missing_asset_count'] ?? 0),
			'api_failures'=>(int)($response['json_failure_count'] ?? 0) + (int)($client['client_http_errors'] ?? 0) + (int)($client['client_fetch_errors'] ?? 0),
			'memory_mb'=>(float)($snapshot['memory_mb'] ?? 0),
			'body_bytes'=>(int)($response['bytes'] ?? 0),
			default=>0,
		};
	}

	/**
	 * Converts an HTTP status into comparison severity.
	 *
	 * @param int $status HTTP response status.
	 * @return int Severity bucket for status regression checks.
	 */
	private static function status_score(int $status): int {
		if($status>=500){
			return 3;
		}
		if($status>=400){
			return 2;
		}
		if($status>=300){
			return 1;
		}
		return 0;
	}

	/**
	 * Formats a metric delta for the comparison UI.
	 *
	 * @param float $delta Absolute metric delta.
	 * @param float $percent Relative change from the previous value.
	 * @param string $unit Metric unit.
	 * @return string Human-readable delta label.
	 */
	private static function comparison_delta_label(float $delta, float $percent, string $unit): string {
		$sign=$delta>0 ? '+' : '-';
		$value=self::comparison_value_label(abs($delta), $unit);
		if(abs($percent)>0.001){
			return $sign.$value.' ('.$sign.(string)round(abs($percent) * 100, 1).'%)';
		}
		return $sign.$value;
	}

	/**
	 * Formats a comparison metric value.
	 *
	 * @param float|int $value Numeric metric value.
	 * @param string $unit Metric unit.
	 * @return string Human-readable value label.
	 */
	private static function comparison_value_label(float|int $value, string $unit): string {
		return match($unit){
			'ms'=>self::format_ms((float)$value),
			'mb'=>self::e((string)round((float)$value, 2)).'mb',
			'bytes'=>self::format_bytes((int)$value),
			default=>self::e((string)(int)round((float)$value)),
		};
	}

	/**
	 * Compacts full debugbar state into a session-safe snapshot.
	 *
	 * Large event collections are trimmed before storage so browser sessions do
	 * not balloon while still retaining the most useful diagnostics.
	 *
	 * @param array<string, mixed> $state Full debugbar state.
	 * @return array<string, mixed> Compact session snapshot.
	 */
	private static function compact_snapshot(array $state): array {
		$id=sha1(implode('|', [
			(string)($state['request_id'] ?? ''),
			(string)($state['method'] ?? ''),
			(string)($state['uri'] ?? ''),
			(string)microtime(true),
			bin2hex(random_bytes(4)),
		]));
		$sql=is_array($state['sql'] ?? null) ? $state['sql'] : [];
		$sql['events']=array_slice(is_array($sql['events'] ?? null) ? $sql['events'] : [], -self::HISTORY_SQL_EVENT_LIMIT);
		$sql['slowest']=array_slice(is_array($sql['slowest'] ?? null) ? $sql['slowest'] : [], 0, 12);
		$sql['duplicates']=array_slice(is_array($sql['duplicates'] ?? null) ? $sql['duplicates'] : [], 0, 16);
		$sql['templated_events']=array_slice(is_array($sql['templated_events'] ?? null) ? $sql['templated_events'] : [], 0, 32);
		$timeline=is_array($state['timeline'] ?? null) ? $state['timeline'] : [];
		$timeline['events']=array_slice(is_array($timeline['events'] ?? null) ? $timeline['events'] : [], 0, 120);
		$trace=is_array($state['trace'] ?? null) ? $state['trace'] : [];
		$trace['entries']=array_slice(is_array($trace['entries'] ?? null) ? $trace['entries'] : [], -self::TRACE_HISTORY_LIMIT);
		$trace['live_entries']=array_slice(is_array($trace['live_entries'] ?? null) ? $trace['live_entries'] : [], -self::TRACE_HISTORY_LIMIT);
		$trace['session_entries']=array_slice(is_array($trace['session_entries'] ?? null) ? $trace['session_entries'] : [], -self::TRACE_HISTORY_LIMIT);
		$panel=is_array($state['panel'] ?? null) ? $state['panel'] : [];
		$panel['events']=array_slice(is_array($panel['events'] ?? null) ? $panel['events'] : [], -120);
		$panel['resources']=array_slice(is_array($panel['resources'] ?? null) ? $panel['resources'] : [], 0, 80);
		$panel['pages']=array_slice(is_array($panel['pages'] ?? null) ? $panel['pages'] : [], 0, 80);
		$panel['widgets']=array_slice(is_array($panel['widgets'] ?? null) ? $panel['widgets'] : [], 0, 80);
		$panel['actions']=array_slice(is_array($panel['actions'] ?? null) ? $panel['actions'] : [], 0, 120);
		$panel['navigation']=array_slice(is_array($panel['navigation'] ?? null) ? $panel['navigation'] : [], 0, 80);
		$snapshot=[
			'id'=>$id,
			'recorded_at'=>time(),
			'label'=>self::snapshot_label($state),
			'available'=>(bool)($state['available'] ?? true),
			'enabled'=>(bool)($state['enabled'] ?? true),
			'request_id'=>(string)($state['request_id'] ?? ''),
			'app'=>(string)($state['app'] ?? ''),
			'method'=>(string)($state['method'] ?? ''),
			'uri'=>(string)($state['uri'] ?? ''),
			'duration_ms'=>(float)($state['duration_ms'] ?? 0),
			'memory_mb'=>(float)($state['memory_mb'] ?? 0),
			'peak_mb'=>(float)($state['peak_mb'] ?? 0),
			'files'=>(int)($state['files'] ?? 0),
			'modules'=>is_array($state['modules'] ?? null) ? array_slice($state['modules'], 0, 80) : [],
			'run_mode'=>(string)($state['run_mode'] ?? ''),
			'production'=>(bool)($state['production'] ?? false),
			'request'=>is_array($state['request'] ?? null) ? $state['request'] : [],
			'response'=>is_array($state['response'] ?? null) ? $state['response'] : [],
			'client'=>is_array($state['client'] ?? null) ? $state['client'] : self::client_state_from_events([]),
			'routing'=>is_array($state['routing'] ?? null) ? $state['routing'] : [],
			'sql'=>$sql,
			'templating'=>is_array($state['templating'] ?? null) ? $state['templating'] : [],
			'panel'=>$panel,
			'asset_node'=>is_array($state['asset_node'] ?? null) ? $state['asset_node'] : [],
			'runtime'=>is_array($state['runtime'] ?? null) ? $state['runtime'] : [],
			'trace'=>$trace,
			'timeline'=>$timeline,
			'errors'=>is_array($state['errors'] ?? null) ? $state['errors'] : [],
			'diagnostics'=>is_array($state['diagnostics'] ?? null) ? $state['diagnostics'] : [],
		];
		return self::clamp_history_value($snapshot);
	}

	/**
	 * Trims history until it fits the session storage budget.
	 *
	 * @param array<int, mixed> $history Candidate history entries.
	 * @return array<int, mixed> History entries within budget.
	 */
	private static function history_within_session_budget(array $history): array {
		$history=array_values(array_map(static fn($entry)=>is_array($entry) ? self::clamp_history_value($entry) : $entry, $history));
		$budget=786432;
		while(count($history)>1 && strlen(serialize($history))>$budget){
			array_pop($history);
		}
		return $history;
	}

	/**
	 * Recursively clamps values stored in session history.
	 *
	 * Strings and nested arrays are bounded to protect session size and avoid
	 * retaining deep object-shaped payloads.
	 *
	 * @param mixed $value Value to clamp.
	 * @param string $key Current payload key used for field-specific limits.
	 * @param int $depth Recursion depth.
	 * @return mixed scalar, bounded string, depth marker, or recursively clamped array safe for session storage.
	 */
	private static function clamp_history_value(mixed $value, string $key='', int $depth=0): mixed {
		if($depth>8){
			return '[depth-limit]';
		}
		if(is_string($value)){
			$max=match($key){
				'message', 'message_html', 'detail', 'stack'=>2000,
				'query', 'normalized_query', 'sql', 'body', 'preview'=>1600,
				'file', 'path', 'url', 'source'=>700,
				default=>1200,
			};
			return strlen($value)>$max ? substr($value, 0, max(1, $max - 3)).'...' : $value;
		}
		if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return $value;
		}
		if(is_object($value)){
			return '[object '.get_debug_type($value).']';
		}
		if(!is_array($value)){
			return get_debug_type($value);
		}
		$limit=match($key){
			'entries', 'live_entries', 'session_entries'=>80,
			'nodes'=>120,
			'links'=>180,
			'events'=>90,
			'assets'=>60,
			default=>100,
		};
		$result=[];
		$count=0;
		foreach($value as $nested_key=>$nested_value){
			if($count>=$limit){
				$result[is_int($nested_key) ? $count : '...']='truncated';
				break;
			}
			$result[$nested_key]=self::clamp_history_value($nested_value, (string)$nested_key, $depth+1);
			$count++;
		}
		return $result;
	}

	/**
	 * Normalizes raw browser telemetry events.
	 *
	 * @param array<int, mixed> $events Browser-submitted event payloads.
	 * @return array<int, array<string, mixed>> Accepted normalized events.
	 */
	private static function normalize_client_events(array $events): array {
		$normalized=[];
		foreach(array_slice($events, 0, self::CLIENT_BATCH_LIMIT) as $event){
			if(!is_array($event)){
				continue;
			}
			$type=self::client_event_type((string)($event['type'] ?? 'client_event'));
			$a11y_issues_source=is_array($event['a11y_issues'] ?? null) ? $event['a11y_issues'] : (is_array($event['issues'] ?? null) ? $event['issues'] : []);
			$a11y_adjustments_source=is_array($event['a11y_adjustments'] ?? null) ? $event['a11y_adjustments'] : (is_array($event['adjustments'] ?? null) ? $event['adjustments'] : []);
			$a11y_issues=self::normalize_accessibility_fields($a11y_issues_source);
			$a11y_adjustments=self::normalize_accessibility_fields($a11y_adjustments_source);
			$a11y_reported_source=(string)($event['a11y_field_source'] ?? '');
			$a11y_field_source=in_array($a11y_reported_source, ['combined_fields', 'split_fields'], true)
				? $a11y_reported_source
				: ($a11y_issues!==[] || $a11y_adjustments!==[] ? 'split_fields' : '');
			$a11y_combined_fields=is_array($event['a11y_fields'] ?? null) ? $event['a11y_fields'] : (is_array($event['fields'] ?? null) ? $event['fields'] : []);
			if($a11y_issues===[] && $a11y_adjustments===[] && $a11y_combined_fields!==[]){
				foreach(self::normalize_accessibility_fields($a11y_combined_fields) as $field){
					if(($field['issues'] ?? [])!==[]){
						$a11y_issues[]=$field;
					}
					if(($field['actions'] ?? [])!==[]){
						$a11y_adjustments[]=$field;
					}
				}
				if($a11y_issues!==[] || $a11y_adjustments!==[]){
					$a11y_field_source='combined_fields';
				}
			}
			$a11y_checked=max(0, (int)($event['a11y_checked'] ?? $event['checked'] ?? 0));
			$a11y_issue_count=max(count($a11y_issues), (int)($event['a11y_issue_count'] ?? $event['issue_count'] ?? 0));
			$a11y_adjustment_count=max(count($a11y_adjustments), (int)($event['a11y_adjustment_count'] ?? $event['adjustment_count'] ?? 0));
			$a11y_status=(string)($event['a11y_status'] ?? $event['status'] ?? '');
			if($a11y_status==='' && $type==='accessibility_policy'){
				$a11y_status=$a11y_issue_count>0 ? 'needs_attention' : ($a11y_adjustment_count>0 ? 'adjusted' : 'pass');
			}
			$normalized[]=[
				'type'=>$type,
				'level'=>self::client_event_level($type, (string)($event['level'] ?? '')),
				'message'=>self::shorten((string)($event['message'] ?? ''), 360),
				'url'=>self::shorten((string)($event['url'] ?? ''), 520),
				'method'=>self::shorten(strtoupper((string)($event['method'] ?? '')), 16),
				'tag'=>self::shorten((string)($event['tag'] ?? ''), 40),
				'rel'=>self::shorten((string)($event['rel'] ?? ''), 80),
				'source'=>self::shorten((string)($event['source'] ?? $event['file'] ?? ''), 520),
				'line'=>max(0, (int)($event['line'] ?? 0)),
				'column'=>max(0, (int)($event['column'] ?? $event['col'] ?? 0)),
				'stack'=>self::shorten((string)($event['stack'] ?? ''), 1600),
				'initiator_type'=>self::shorten((string)($event['initiator_type'] ?? ''), 60),
				'duration_ms'=>round(max(0.0, (float)($event['duration_ms'] ?? 0)), 3),
				'start_time_ms'=>round(max(0.0, (float)($event['start_time_ms'] ?? 0)), 3),
				'transfer_size'=>max(0, (int)($event['transfer_size'] ?? 0)),
				'encoded_size'=>max(0, (int)($event['encoded_size'] ?? 0)),
				'decoded_size'=>max(0, (int)($event['decoded_size'] ?? 0)),
				'response_status'=>max(0, (int)($event['response_status'] ?? 0)),
				'next_hop_protocol'=>self::shorten((string)($event['next_hop_protocol'] ?? ''), 40),
				'render_blocking_status'=>self::shorten((string)($event['render_blocking_status'] ?? ''), 40),
				'dom_content_loaded_ms'=>round(max(0.0, (float)($event['dom_content_loaded_ms'] ?? 0)), 3),
				'load_ms'=>round(max(0.0, (float)($event['load_ms'] ?? 0)), 3),
				'first_byte_ms'=>round(max(0.0, (float)($event['first_byte_ms'] ?? 0)), 3),
				'resource_count'=>max(0, (int)($event['resource_count'] ?? 0)),
				'slow_resource_count'=>max(0, (int)($event['slow_resource_count'] ?? 0)),
				'server_snapshot_id'=>self::shorten((string)($event['server_snapshot_id'] ?? ''), 80),
				'server_request_id'=>self::shorten((string)($event['server_request_id'] ?? ''), 80),
				'server_label'=>self::shorten((string)($event['server_label'] ?? ''), 240),
				'server_status'=>max(0, (int)($event['server_status'] ?? 0)),
				'server_duration_ms'=>round(max(0.0, (float)($event['server_duration_ms'] ?? 0)), 3),
				'server_findings'=>max(0, (int)($event['server_findings'] ?? 0)),
				'replay_responded'=>!empty($event['replay_responded']) ? 1 : 0,
				'replay_verified'=>!empty($event['replay_verified']) ? 1 : 0,
				'replay_production'=>!empty($event['replay_production']) ? 1 : 0,
				'replay_readonly'=>!empty($event['replay_readonly']) ? 1 : 0,
				'replay_memory_mb'=>round(max(0.0, (float)($event['replay_memory_mb'] ?? 0)), 3),
				'replay_peak_mb'=>round(max(0.0, (float)($event['replay_peak_mb'] ?? 0)), 3),
				'replay_debug_overhead_mb'=>round(max(0.0, (float)($event['replay_debug_overhead_mb'] ?? 0)), 3),
				'replay_memory_mode'=>self::shorten((string)($event['replay_memory_mode'] ?? ''), 32),
				'replay_body_bytes'=>max(0, (int)($event['replay_body_bytes'] ?? 0)),
				'replay_write_blocks'=>max(0, (int)($event['replay_write_blocks'] ?? 0)),
				'a11y_checked'=>$a11y_checked,
				'a11y_issue_count'=>$a11y_issue_count,
				'a11y_adjustment_count'=>$a11y_adjustment_count,
				'a11y_status'=>self::shorten($a11y_status, 40),
				'a11y_field_source'=>$a11y_field_source,
				'a11y_issues'=>$a11y_issues,
				'a11y_adjustments'=>$a11y_adjustments,
				'timestamp'=>is_numeric($event['timestamp'] ?? null) ? (float)$event['timestamp'] : round(microtime(true) * 1000),
			];
		}
		return $normalized;
	}

	/**
	 * Normalizes browser-reported accessibility field diagnostics.
	 *
	 * @param array<int, mixed> $fields Raw accessibility field payloads.
	 * @return array<int, array<string, mixed>> Bounded accessibility field diagnostics.
	 */
	private static function normalize_accessibility_fields(array $fields): array {
		$normalized=[];
		foreach(array_slice($fields, 0, 10) as $field){
			if(!is_array($field)){
				continue;
			}
			$string_list=static function(mixed $items, int $limit, int $length): array {
				if(!is_array($items)){
					return [];
				}
				return array_map(
					static fn(mixed $item): string => self::shorten((string)$item, $length),
					array_slice($items, 0, $limit)
				);
			};
			$normalized[]=[
				'name'=>self::shorten((string)($field['name'] ?? ''), 120),
				'label'=>self::shorten((string)($field['label'] ?? ''), 160),
				'issues'=>$string_list($field['issues'] ?? [], 8, 80),
				'actions'=>$string_list($field['actions'] ?? [], 8, 80),
				'issue_messages'=>$string_list($field['issue_messages'] ?? [], 4, 240),
				'action_messages'=>$string_list($field['action_messages'] ?? [], 4, 240),
				'width_status'=>self::shorten((string)($field['width_status'] ?? ''), 40),
				'contrast_status'=>self::shorten((string)($field['contrast_status'] ?? ''), 40),
				'touch_target_status'=>self::shorten((string)($field['touch_target_status'] ?? ''), 40),
				'adornment_status'=>self::shorten((string)($field['adornment_status'] ?? ''), 40),
				'label_status'=>self::shorten((string)($field['label_status'] ?? ''), 40),
				'usable_width'=>round(max(0.0, (float)($field['usable_width'] ?? 0)), 3),
				'required_width'=>round(max(0.0, (float)($field['required_width'] ?? 0)), 3),
				'required_width_source'=>self::shorten((string)($field['required_width_source'] ?? ''), 40),
				'touch_target_failures'=>max(0, (int)($field['touch_target_failures'] ?? 0)),
				'table_columns'=>max(0, (int)($field['table_columns'] ?? 0)),
				'table_available_width'=>round(max(0.0, (float)($field['table_available_width'] ?? 0)), 3),
				'table_desired_width'=>round(max(0.0, (float)($field['table_desired_width'] ?? 0)), 3),
				'table_applied_width'=>round(max(0.0, (float)($field['table_applied_width'] ?? 0)), 3),
				'table_compact_columns'=>max(0, (int)($field['table_compact_columns'] ?? 0)),
				'table_scroll_preserved'=>!empty($field['table_scroll_preserved']) ? 1 : 0,
			];
		}
		return $normalized;
	}

	/**
	 * Links browser HTTP events to matching server snapshots when possible.
	 *
	 * @param array<int, array<string, mixed>> $events Normalized browser events.
	 * @param array<int, array<string, mixed>> $history Session snapshot history.
	 * @param string $origin_snapshot_id Snapshot that submitted the browser events.
	 * @return array<int, array<string, mixed>> Events annotated with server snapshot data.
	 */
	private static function link_client_events_to_history(array $events, array $history, string $origin_snapshot_id): array {
		foreach($events as $index=>$event){
			$type=(string)($event['type'] ?? '');
			if(!in_array($type, ['client_http_error', 'client_http_slow', 'client_fetch_error'], true)){
				continue;
			}
			$match=self::matching_snapshot_for_client_event($event, $history, $origin_snapshot_id);
			if($match===null){
				continue;
			}
			$events[$index]['server_snapshot_id']=(string)($match['id'] ?? '');
			$events[$index]['server_request_id']=(string)($match['request_id'] ?? '');
			$events[$index]['server_label']=(string)($match['label'] ?? self::snapshot_label($match));
			$events[$index]['server_status']=(int)($match['request']['status'] ?? 0);
			$events[$index]['server_duration_ms']=(float)($match['duration_ms'] ?? 0);
			$events[$index]['server_findings']=(int)($match['diagnostics']['count'] ?? 0);
		}
		return $events;
	}

	/**
	 * Finds the best server snapshot for a browser-side HTTP event.
	 *
	 * @param array<string, mixed> $event Normalized browser HTTP event.
	 * @param array<int, array<string, mixed>> $history Session snapshot history.
	 * @param string $origin_snapshot_id Snapshot that submitted the browser events.
	 * @return ?array<string, mixed> Best matching server snapshot.
	 */
	private static function matching_snapshot_for_client_event(array $event, array $history, string $origin_snapshot_id): ?array {
		$target=self::client_event_target($event);
		if($target['path']===''){
			return null;
		}
		$best=null;
		$best_score=-1;
		foreach($history as $snapshot){
			if(!is_array($snapshot) || (string)($snapshot['id'] ?? '')===$origin_snapshot_id){
				continue;
			}
			$candidate=self::snapshot_target($snapshot);
			if($candidate['path']==='' || $candidate['path']!==$target['path']){
				continue;
			}
			if($target['method']!=='' && $candidate['method']!=='' && $target['method']!==$candidate['method']){
				continue;
			}
			if($target['host']!=='' && $candidate['host']!=='' && $target['host']!==$candidate['host']){
				continue;
			}
			$score=100;
			if($target['method']!=='' && $target['method']===$candidate['method']){
				$score+=20;
			}
			if($target['query']!=='' && $target['query']===$candidate['query']){
				$score+=10;
			}
			$status=(int)($event['response_status'] ?? 0);
			$candidate_status=(int)($snapshot['request']['status'] ?? 0);
			if($status>0 && $candidate_status===$status){
				$score+=8;
			}
			$event_seconds=self::client_event_seconds($event);
			$recorded=(int)($snapshot['recorded_at'] ?? 0);
			if($event_seconds>0 && $recorded>0){
				$distance=abs($event_seconds - $recorded);
				$score+=max(0, 10 - min(10, (int)floor($distance / 10)));
			}
			if($score>$best_score){
				$best_score=$score;
				$best=$snapshot;
			}
		}
		return is_array($best) ? $best : null;
	}

	/**
	 * Extracts method, host, path, and query from a browser event URL.
	 *
	 * @param array<string, mixed> $event Normalized browser event.
	 * @return array{method:string,host:string,path:string,query:string}
	 */
	private static function client_event_target(array $event): array {
		$url=trim((string)($event['url'] ?? ''));
		$parts=$url!=='' ? parse_url($url) : false;
		$path=is_array($parts) ? (string)($parts['path'] ?? '') : '';
		if($path==='' && $url!=='' && str_starts_with($url, '/')){
			$path=strtok($url, '?') ?: $url;
		}
		return [
			'method'=>strtoupper(trim((string)($event['method'] ?? ''))),
			'host'=>self::normalize_host(is_array($parts) ? (string)($parts['host'] ?? '') : ''),
			'path'=>$path,
			'query'=>is_array($parts) ? (string)($parts['query'] ?? '') : '',
		];
	}

	/**
	 * Extracts method, host, path, and query from a server snapshot.
	 *
	 * @param array<string, mixed> $snapshot Server snapshot payload.
	 * @return array{method:string,host:string,path:string,query:string}
	 */
	private static function snapshot_target(array $snapshot): array {
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$uri=(string)($snapshot['uri'] ?? '');
		$parts=$uri!=='' ? parse_url($uri) : false;
		$path=(string)($request['path'] ?? '');
		if($path===''){
			$path=is_array($parts) ? (string)($parts['path'] ?? '') : '';
		}
		return [
			'method'=>strtoupper(trim((string)($snapshot['method'] ?? $request['method'] ?? ''))),
			'host'=>self::normalize_host((string)($request['host'] ?? '')),
			'path'=>$path,
			'query'=>(string)($request['query'] ?? (is_array($parts) ? (string)($parts['query'] ?? '') : '')),
		];
	}

	/**
	 * Converts a browser event timestamp to Unix seconds.
	 *
	 * @param array<string, mixed> $event Browser event payload.
	 * @return int Unix seconds, or 0 when unavailable.
	 */
	private static function client_event_seconds(array $event): int {
		$timestamp=(float)($event['timestamp'] ?? 0);
		if($timestamp<=0){
			return 0;
		}
		return (int)($timestamp>100000000000 ? floor($timestamp / 1000) : floor($timestamp));
	}

	/**
	 * Normalizes a host for browser/server request matching.
	 *
	 * @param string $host Raw host header or URL host.
	 * @return string Lowercase host without a port.
	 */
	private static function normalize_host(string $host): string {
		$host=strtolower(trim($host));
		return preg_replace('/:\d+$/', '', $host) ?? $host;
	}

	/**
	 * Merges new browser events into existing client state.
	 *
	 * @param array<string, mixed> $client Existing client state.
	 * @param array<int, array<string, mixed>> $events New normalized events.
	 * @return array<string, mixed> Recomputed client state.
	 */
	private static function merge_client_events(array $client, array $events): array {
		$existing=is_array($client['events'] ?? null) ? $client['events'] : [];
		return self::client_state_from_events(array_slice(array_merge($existing, $events), -self::CLIENT_EVENT_LIMIT));
	}

	/**
	 * Aggregates browser telemetry into debugbar client state.
	 *
	 * @param array<int, mixed> $events Normalized browser events.
	 * @return array<string, mixed> Counts, summaries, latest events, and accessibility state.
	 */
	private static function client_state_from_events(array $events): array {
		$events=array_values(array_filter($events, static fn(mixed $event): bool => is_array($event)));
		$counts=[
			'resource_error'=>0,
			'js_error'=>0,
			'unhandled_rejection'=>0,
			'slow_resource'=>0,
			'stylesheet_missing'=>0,
			'client_http_error'=>0,
			'client_http_slow'=>0,
			'client_fetch_error'=>0,
			'page_performance'=>0,
			'resource_timing'=>0,
			'production_replay'=>0,
			'accessibility_policy'=>0,
		];
		$last_seen_at=0.0;
		$linked=0;
		$page_performance=[];
		$resource_timings=[];
		$production_replay=[];
		$accessibility_latest=[];
		$accessibility_issues=[];
		$accessibility_adjustments=[];
		$accessibility_issue_tokens=[];
		$accessibility_action_tokens=[];
		$accessibility_events=[];
		$accessibility_issue_total=0;
		$accessibility_adjustment_total=0;
		$accessibility_checked=0;
		foreach($events as $event){
			$type=(string)($event['type'] ?? 'client_event');
			if(isset($counts[$type])){
				$counts[$type]++;
			}
			if($type==='page_performance'){
				$page_performance=$event;
			}
			if($type==='resource_timing'){
				$resource_timings[]=$event;
			}
			if($type==='production_replay'){
				$production_replay=$event;
			}
			if($type==='accessibility_policy'){
				$accessibility_latest=$event;
				$accessibility_issue_total=max($accessibility_issue_total, (int)($event['a11y_issue_count'] ?? 0));
				$accessibility_adjustment_total=max($accessibility_adjustment_total, (int)($event['a11y_adjustment_count'] ?? 0));
				$accessibility_checked=max($accessibility_checked, (int)($event['a11y_checked'] ?? 0));
				$event_issues=is_array($event['a11y_issues'] ?? null) ? $event['a11y_issues'] : [];
				$event_adjustments=is_array($event['a11y_adjustments'] ?? null) ? $event['a11y_adjustments'] : [];
				if($event_issues!==[]){
					$accessibility_issues=$event_issues;
					$accessibility_issue_tokens=self::accessibility_token_counts($accessibility_issues, 'issues');
				}
				if($event_adjustments!==[]){
					$accessibility_adjustments=$event_adjustments;
					$accessibility_action_tokens=self::accessibility_token_counts($accessibility_adjustments, 'actions');
				}
				$accessibility_events[]=$event;
				if(count($accessibility_events)>8){
					array_shift($accessibility_events);
				}
			}
			if(!empty($event['server_snapshot_id'])){
				$linked++;
			}
			$last_seen_at=max($last_seen_at, (float)($event['timestamp'] ?? 0));
		}
		return [
			'event_count'=>count($events),
			'resource_errors'=>$counts['resource_error'],
			'js_errors'=>$counts['js_error'],
			'unhandled_rejections'=>$counts['unhandled_rejection'],
			'slow_resources'=>$counts['slow_resource'],
			'stylesheet_missing'=>$counts['stylesheet_missing'],
			'client_http_errors'=>$counts['client_http_error'],
			'client_http_slow'=>$counts['client_http_slow'],
			'client_fetch_errors'=>$counts['client_fetch_error'],
			'page_performance_count'=>$counts['page_performance'],
			'page_performance'=>$page_performance,
			'resource_timing_count'=>$counts['resource_timing'],
			'resource_summary'=>self::client_resource_timing_summary($resource_timings),
			'production_replay_count'=>$counts['production_replay'],
			'production_replay'=>$production_replay,
			'accessibility_policy_events'=>$counts['accessibility_policy'],
			'accessibility_issues'=>$accessibility_issue_total,
			'accessibility_adjustments'=>$accessibility_adjustment_total,
			'accessibility_checked'=>$accessibility_checked,
			'accessibility_latest'=>$accessibility_latest,
			'accessibility_events'=>array_reverse($accessibility_events),
			'accessibility_issue_fields'=>$accessibility_issues,
			'accessibility_adjustment_fields'=>$accessibility_adjustments,
			'accessibility_issue_tokens'=>$accessibility_issue_tokens,
			'accessibility_action_tokens'=>$accessibility_action_tokens,
			'linked_server_events'=>$linked,
			'last_seen_at'=>$last_seen_at,
			'events'=>$events,
		];
	}

	/**
	 * Counts accessibility issue or action tokens across fields.
	 *
	 * @param array<int, array<string, mixed>> $fields Accessibility field diagnostics.
	 * @param string $key Field key containing token arrays.
	 * @return array<string, int> Token counts sorted descending.
	 */
	private static function accessibility_token_counts(array $fields, string $key): array {
		$counts=[];
		foreach($fields as $field){
			if(!is_array($field)){
				continue;
			}
			foreach(is_array($field[$key] ?? null) ? $field[$key] : [] as $token){
				$token=trim((string)$token);
				if($token===''){
					continue;
				}
				$counts[$token]=($counts[$token] ?? 0)+1;
			}
		}
		arsort($counts);
		return $counts;
	}

	/**
	 * Summarizes browser Resource Timing events.
	 *
	 * @param array<int, mixed> $resources Resource timing events.
	 * @return array<string, mixed> Transfer, duration, type, slowest, and largest summaries.
	 */
	private static function client_resource_timing_summary(array $resources): array {
		$resources=array_values(array_filter($resources, static fn(mixed $event): bool => is_array($event)));
		$summary=[
			'count'=>count($resources),
			'total_duration_ms'=>0.0,
			'max_duration_ms'=>0.0,
			'total_transfer_size'=>0,
			'total_encoded_size'=>0,
			'total_decoded_size'=>0,
			'by_type'=>[],
			'slowest'=>[],
			'largest'=>[],
		];
		foreach($resources as $resource){
			$type=trim((string)($resource['initiator_type'] ?? ''));
			$type=$type!=='' ? $type : 'other';
			$duration=(float)($resource['duration_ms'] ?? 0);
			$transfer=(int)($resource['transfer_size'] ?? 0);
			$encoded=(int)($resource['encoded_size'] ?? 0);
			$decoded=(int)($resource['decoded_size'] ?? 0);
			$summary['total_duration_ms']+=$duration;
			$summary['max_duration_ms']=max((float)$summary['max_duration_ms'], $duration);
			$summary['total_transfer_size']+=$transfer;
			$summary['total_encoded_size']+=$encoded;
			$summary['total_decoded_size']+=$decoded;
			$summary['by_type'][$type] ??=[
				'count'=>0,
				'total_duration_ms'=>0.0,
				'max_duration_ms'=>0.0,
				'total_transfer_size'=>0,
				'total_decoded_size'=>0,
			];
			$summary['by_type'][$type]['count']++;
			$summary['by_type'][$type]['total_duration_ms']+=$duration;
			$summary['by_type'][$type]['max_duration_ms']=max((float)$summary['by_type'][$type]['max_duration_ms'], $duration);
			$summary['by_type'][$type]['total_transfer_size']+=$transfer;
			$summary['by_type'][$type]['total_decoded_size']+=$decoded;
		}
		uasort($summary['by_type'], static fn(array $a, array $b): int => ((float)($b['total_duration_ms'] ?? 0))<=>((float)($a['total_duration_ms'] ?? 0)));
		$slowest=$resources;
		usort($slowest, static fn(array $a, array $b): int => ((float)($b['duration_ms'] ?? 0))<=>((float)($a['duration_ms'] ?? 0)));
		$largest=$resources;
		usort($largest, static fn(array $a, array $b): int => ((int)($b['transfer_size'] ?? 0))<=>((int)($a['transfer_size'] ?? 0)));
		$summary['slowest']=array_slice($slowest, 0, 16);
		$summary['largest']=array_slice($largest, 0, 12);
		return $summary;
	}

	/**
	 * Adds client-side findings to existing server diagnostics.
	 *
	 * @param array<string, mixed> $diagnostics Existing diagnostics payload.
	 * @param array<string, mixed> $client Aggregated client telemetry.
	 * @return array<string, mixed> Diagnostics with browser findings included.
	 */
	private static function with_client_diagnostics(array $diagnostics, array $client): array {
		$findings=is_array($diagnostics['findings'] ?? null) ? $diagnostics['findings'] : [];
		$findings=array_values(array_filter($findings, static fn(array $finding): bool => (string)($finding['source'] ?? '')!=='client'));
		$resource_errors=(int)($client['resource_errors'] ?? 0);
		$stylesheet_missing=(int)($client['stylesheet_missing'] ?? 0);
		$js_errors=(int)($client['js_errors'] ?? 0);
		$rejections=(int)($client['unhandled_rejections'] ?? 0);
		$slow=(int)($client['slow_resources'] ?? 0);
		$client_http_errors=(int)($client['client_http_errors'] ?? 0);
		$client_fetch_errors=(int)($client['client_fetch_errors'] ?? 0);
		$client_http_slow=(int)($client['client_http_slow'] ?? 0);
		$page_performance=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		$resource_summary=is_array($client['resource_summary'] ?? null) ? $client['resource_summary'] : [];
		$production_replay=is_array($client['production_replay'] ?? null) ? $client['production_replay'] : [];
		$accessibility_issues=(int)($client['accessibility_issues'] ?? 0);
		$accessibility_adjustments=(int)($client['accessibility_adjustments'] ?? 0);
		if($accessibility_issues>0){
			$findings[]=[
				'level'=>'warning',
				'title'=>'Panel accessibility policies need attention',
				'detail'=>$accessibility_issues.' field'.($accessibility_issues===1 ? '' : 's').' reported accessibility policy issues in the browser.',
				'source'=>'accessibility',
			];
		}
		elseif($accessibility_adjustments>0){
			$findings[]=[
				'level'=>'info',
				'title'=>'Panel accessibility policies adjusted layout',
				'detail'=>$accessibility_adjustments.' field'.($accessibility_adjustments===1 ? '' : 's').' were automatically adjusted to satisfy accessibility policies.',
				'source'=>'accessibility',
			];
		}
		if($resource_errors>0 || $stylesheet_missing>0){
			$findings[]=[
				'level'=>'error',
				'title'=>'Browser resource load failures',
				'detail'=>($resource_errors + $stylesheet_missing).' browser resource issue'.(($resource_errors + $stylesheet_missing)===1 ? '' : 's').' were reported after the response reached the client.',
				'source'=>'client',
			];
		}
		if($js_errors>0 || $rejections>0){
			$findings[]=[
				'level'=>'error',
				'title'=>'Browser JavaScript failed',
				'detail'=>($js_errors + $rejections).' JavaScript error/rejection event'.(($js_errors + $rejections)===1 ? '' : 's').' were reported by the browser.',
				'source'=>'client',
			];
		}
		if($client_http_errors>0 || $client_fetch_errors>0){
			$findings[]=[
				'level'=>'error',
				'title'=>'Browser API requests failed',
				'detail'=>($client_http_errors + $client_fetch_errors).' fetch/XHR issue'.(($client_http_errors + $client_fetch_errors)===1 ? '' : 's').' were reported by the browser.',
				'source'=>'client',
			];
		}
		if($slow>0){
			$findings[]=[
				'level'=>'warning',
				'title'=>'Slow browser resources',
				'detail'=>$slow.' browser resource'.($slow===1 ? '' : 's').' crossed the client-side duration threshold.',
				'source'=>'client',
			];
		}
		if($client_http_slow>0){
			$findings[]=[
				'level'=>'warning',
				'title'=>'Slow browser API requests',
				'detail'=>$client_http_slow.' fetch/XHR request'.($client_http_slow===1 ? '' : 's').' crossed the client-side duration threshold.',
				'source'=>'client',
			];
		}
		if($resource_summary!==[]){
			$total_transfer=(int)($resource_summary['total_transfer_size'] ?? 0);
			$max_duration=(float)($resource_summary['max_duration_ms'] ?? 0);
			if($total_transfer>=5242880){
				$findings[]=[
					'level'=>$total_transfer>=15728640 ? 'error' : 'warning',
					'title'=>'Heavy browser resource payload',
					'detail'=>'The sampled resource timings transferred '.self::format_bytes($total_transfer).'.',
					'source'=>'client',
				];
			}
			if($max_duration>=3000){
				$findings[]=[
					'level'=>$max_duration>=8000 ? 'error' : 'warning',
					'title'=>'Resource timing outlier',
					'detail'=>'The slowest sampled resource took '.self::format_ms($max_duration).'.',
					'source'=>'client',
				];
			}
		}
		if($production_replay!==[]){
			$status=(int)($production_replay['response_status'] ?? 0);
			$responded=(int)($production_replay['replay_responded'] ?? 0)===1 || $status>0;
			$verified=(int)($production_replay['replay_verified'] ?? 0)===1;
			$write_blocks=(int)($production_replay['replay_write_blocks'] ?? 0);
			if($verified!==true && $responded===true){
				$findings[]=[
					'level'=>'warning',
					'title'=>'Production replay responded without Dataphyre metrics',
					'detail'=>'HTTP '.$status.' returned, but Dataphyre replay headers were missing, so production-like metrics may be unavailable.',
					'source'=>'client',
				];
			}
			elseif($verified!==true){
				$findings[]=[
					'level'=>'warning',
					'title'=>'Production replay did not return an HTTP response',
					'detail'=>'The browser could not read a replay response for this request.',
					'source'=>'client',
				];
			}
			elseif($status>=500){
				$findings[]=[
					'level'=>'error',
					'title'=>'Production replay returned HTTP '.$status,
					'detail'=>'The signed read-only replay failed while running with production mode enabled.',
					'source'=>'client',
				];
			}
			elseif($status>=400){
				$findings[]=[
					'level'=>'warning',
					'title'=>'Production replay returned HTTP '.$status,
					'detail'=>'The signed read-only replay did not finish with a successful status.',
					'source'=>'client',
				];
			}
			if($write_blocks>0){
				$findings[]=[
					'level'=>'warning',
					'title'=>'Production replay blocked write paths',
					'detail'=>$write_blocks.' SQL/cache mutation'.($write_blocks===1 ? ' was' : 's were').' skipped during the read-only replay.',
					'source'=>'client',
				];
			}
		}
		if($page_performance!==[]){
			$load_ms=(float)($page_performance['load_ms'] ?? 0);
			$dom_ms=(float)($page_performance['dom_content_loaded_ms'] ?? 0);
			if($load_ms>=8000){
				$findings[]=[
					'level'=>'error',
					'title'=>'Browser page load is very slow',
					'detail'=>'The browser reported a full load time of '.self::format_ms($load_ms).'.',
					'source'=>'client',
				];
			}
			elseif($load_ms>=3000 || $dom_ms>=2500){
				$findings[]=[
					'level'=>'warning',
					'title'=>'Browser page load is slow',
					'detail'=>'DOMContentLoaded '.self::format_ms($dom_ms).' / full load '.self::format_ms($load_ms).'.',
					'source'=>'client',
				];
			}
		}
		return self::diagnostics_from_findings($findings);
	}

	/**
	 * Builds a diagnostics summary from finding entries.
	 *
	 * @param array<int, array<string, mixed>> $findings Diagnostic finding entries.
	 * @return array{count:int,worst_level:string,findings:array<int,array<string,mixed>>}
	 */
	private static function diagnostics_from_findings(array $findings): array {
		$order=['ok'=>0, 'info'=>1, 'warning'=>2, 'error'=>3, 'fatal'=>4];
		$worst='ok';
		foreach($findings as $finding){
			$level=(string)($finding['level'] ?? 'info');
			if(($order[$level] ?? 1)>($order[$worst] ?? 0)){
				$worst=$level;
			}
		}
		return [
			'count'=>count($findings),
			'worst_level'=>$worst,
			'findings'=>$findings,
		];
	}

	/**
	 * Normalizes browser telemetry event type names.
	 *
	 * @param string $type Raw event type.
	 * @return string Accepted event type or client_event fallback.
	 */
	private static function client_event_type(string $type): string {
		$type=strtolower(trim($type));
		return in_array($type, ['resource_error', 'js_error', 'unhandled_rejection', 'slow_resource', 'stylesheet_missing', 'client_http_error', 'client_http_slow', 'client_fetch_error', 'page_performance', 'resource_timing', 'production_replay', 'accessibility_policy'], true) ? $type : 'client_event';
	}

	/**
	 * Normalizes browser telemetry severity levels.
	 *
	 * @param string $type Normalized event type.
	 * @param string $level Raw severity label.
	 * @return string info, warning, or error.
	 */
	private static function client_event_level(string $type, string $level): string {
		$level=strtolower(trim($level));
		if(in_array($level, ['info', 'warning', 'error'], true)){
			return $level;
		}
		if(in_array($type, ['page_performance', 'resource_timing', 'production_replay', 'accessibility_policy'], true)){
			return 'info';
		}
		return in_array($type, ['slow_resource', 'client_http_slow'], true) ? 'warning' : 'error';
	}

}
