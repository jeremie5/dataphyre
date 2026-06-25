<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Bounded trace buffer for Panel diagnostics.
 *
 * Trace events are sanitized, retained in memory, optionally mirrored into the
 * active PHP session, and forwarded to Dataphyre's legacy `tracelog` hook when
 * available. The buffer is intentionally capped so debug instrumentation does
 * not grow without bound during long panel sessions. Trace context is diagnostic
 * only and must not be used as an authorization source.
 */
final class PanelTrace {

	private const LIMIT=200;
	private const SESSION_KEY='dataphyre_panel_trace_recent';
	private static array $events=[];
	private static array $spans=[];

	/**
	 * Starts a timed trace span and records its begin event.
	 *
	 * @param string $event Span event name.
	 * @param array<string, mixed> $context Context sanitized into trace-safe values.
	 * @return string Span id used by `end()` or `fail()`.
	 */
	public static function begin(string $event, array $context=[]): string {
		$id=self::eventId();
		$name=Resource::normalizeName($event) ?: 'span';
		self::$spans[$id]=[
			'id'=>$id,
			'event'=>$name,
			'time'=>microtime(true),
			'context'=>self::sanitize($context),
			'memory'=>memory_get_usage(true),
		];
		self::record($name.'.begin', $context+['span_id'=>$id]);
		return $id;
	}

	/**
	 * Ends a timed span and records duration and memory delta.
	 *
	 * Missing span ids are recorded as `span.end_missing` instead of throwing,
	 * keeping diagnostic calls safe in cleanup paths.
	 *
	 * @param string $spanId Span id returned by `begin()`.
	 * @param array<string, mixed> $context Additional trace context.
	 * @return void Trace state is updated in memory/session.
	 */
	public static function end(string $spanId, array $context=[]): void {
		$span=self::$spans[$spanId] ?? null;
		if(!is_array($span)){
			self::record('span.end_missing', $context+['span_id'=>$spanId]);
			return;
		}
		unset(self::$spans[$spanId]);
		$duration=(microtime(true) - (float)$span['time']) * 1000;
		self::record((string)$span['event'].'.end', $context+[
			'span_id'=>$spanId,
			'duration_ms'=>round($duration, 3),
			'memory_delta'=>memory_get_usage(true) - (int)$span['memory'],
		]);
	}

	/**
	 * Records a failed span with exception metadata.
	 *
	 * Active spans include elapsed duration. Unknown spans still produce a
	 * generic failure event so exception paths remain visible.
	 *
	 * @param string $spanId Span id associated with the failure.
	 * @param \Throwable $exception Exception that ended the span.
	 * @param array<string, mixed> $context Additional trace context.
	 * @return void Trace state is updated in memory/session.
	 */
	public static function fail(string $spanId, \Throwable $exception, array $context=[]): void {
		$span=self::$spans[$spanId] ?? null;
		if(is_array($span)){
			unset(self::$spans[$spanId]);
			$event=(string)$span['event'].'.failed';
			$context+= [
				'span_id'=>$spanId,
				'duration_ms'=>round((microtime(true) - (float)$span['time']) * 1000, 3),
			];
		}
		else
		{
			$event='span.failed';
			$context+=['span_id'=>$spanId];
		}
		self::record($event, $context+[
			'exception'=>$exception::class,
			'message'=>$exception->getMessage(),
			'file'=>$exception->getFile(),
			'line'=>$exception->getLine(),
		]);
	}

	/**
	 * Records a single sanitized Panel trace event.
	 *
	 * Events include an id, timestamp, normalized event name, sanitized context,
	 * and current memory usage. The in-memory buffer and session mirror are both
	 * capped to `LIMIT` entries. Context sanitization bounds size and object shape,
	 * but callers should still avoid passing secrets because scalar strings remain
	 * visible until truncated.
	 *
	 * @param string $event Event name.
	 * @param array<string, mixed> $context Context sanitized before storage.
	 * @return void Trace event is stored and optionally forwarded to `tracelog`.
	 */
	public static function record(string $event, array $context=[]): void {
		$entry=[
			'id'=>self::eventId(),
			'time'=>microtime(true),
			'event'=>Resource::normalizeName($event) ?: 'event',
			'context'=>self::sanitize($context),
			'memory'=>memory_get_usage(true),
		];
		self::$events[]=$entry;
		if(count(self::$events)>self::LIMIT){
			self::$events=array_slice(self::$events, -self::LIMIT);
		}
		self::persist($entry);
		if(function_exists('\tracelog')){
			\tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Panel '.$entry['event'], 'panel_event', [$entry['context']]);
		}
	}

	/**
	 * Returns recent trace events from session and memory buffers.
	 *
	 * @return array<int, array<string, mixed>> Deduplicated recent trace events, capped to `LIMIT`.
	 */
	public static function events(): array {
		return self::combinedEvents();
	}

	/**
	 * Clears in-memory spans/events and the session trace mirror.
	 *
	 * @return void Trace buffers are reset for the current process/session.
	 */
	public static function flush(): void {
		self::$events=[];
		self::$spans=[];
		if(self::sessionAvailable()){
			unset($_SESSION[self::SESSION_KEY]);
		}
	}

	/**
	 * Summarizes recent events and active spans for debug surfaces.
	 *
	 * @return array{count:int, events:array<string, int>, latest:array<int, array<string, mixed>>, active_spans:array<int, array<string, mixed>>} Trace summary.
	 */
	public static function summary(): array {
		$counts=[];
		$events=self::combinedEvents();
		foreach($events as $event){
			$name=(string)($event['event'] ?? 'event');
			$counts[$name]=($counts[$name] ?? 0)+1;
		}
		return [
			'count'=>count($events),
			'events'=>$counts,
			'latest'=>array_slice($events, -10),
			'active_spans'=>array_values(self::$spans),
		];
	}

	/**
	 * Persists an event into the session mirror when sessions are active.
	 *
	 * @param array<string, mixed> $entry Sanitized trace event.
	 * @return void Session trace state is updated when available.
	 */
	private static function persist(array $entry): void {
		if(!self::sessionAvailable()){
			return;
		}
		$events=is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
		$events[]=$entry;
		if(count($events)>self::LIMIT){
			$events=array_slice($events, -self::LIMIT);
		}
		$_SESSION[self::SESSION_KEY]=$events;
	}

	/**
	 * Combines session and process-local events into a bounded list.
	 *
	 * @return array<int, array<string, mixed>> Deduplicated events.
	 */
	private static function combinedEvents(): array {
		$events=[];
		if(self::sessionAvailable() && is_array($_SESSION[self::SESSION_KEY] ?? null)){
			$events=$_SESSION[self::SESSION_KEY];
		}
		foreach(self::$events as $event){
			$events[]=$event;
		}
		$events=self::dedupe($events);
		if(count($events)>self::LIMIT){
			$events=array_slice($events, -self::LIMIT);
		}
		return $events;
	}

	/**
	 * Removes duplicate trace entries by event id.
	 *
	 * Legacy entries without ids receive deterministic synthetic ids based on
	 * their content and position.
	 *
	 * @param array<int, mixed> $events Candidate events.
	 * @return array<int, array<string, mixed>> Deduplicated event records.
	 */
	private static function dedupe(array $events): array {
		$deduped=[];
		$seen=[];
		foreach($events as $index=>$event){
			if(!is_array($event)){
				continue;
			}
			$id=(string)($event['id'] ?? '');
			if($id===''){
				$id='legacy_'.$index.'_'.sha1(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
			}
			if(isset($seen[$id])){
				continue;
			}
			$seen[$id]=true;
			$deduped[]=$event;
		}
		return $deduped;
	}

	/**
	 * Generates a compact event id.
	 *
	 * @return string Random hex id, or a unique fallback when random bytes fail.
	 */
	private static function eventId(): string {
		try{
			return bin2hex(random_bytes(8));
		}
		catch(\Throwable){
			return str_replace('.', '', uniqid('panel_trace_', true));
		}
	}

	/**
	 * Reports whether PHP session storage can be used.
	 *
	 * @return bool `true` when a session is active.
	 */
	private static function sessionAvailable(): bool {
		return PHP_SESSION_ACTIVE===session_status();
	}

	/**
	 * Sanitizes context maps for trace storage.
	 *
	 * @param array<string|int, mixed> $context Raw context.
	 * @return array<string, mixed> String-keyed sanitized context.
	 */
	private static function sanitize(array $context): array {
		$clean=[];
		foreach($context as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			$clean[$key]=self::sanitizeValue($value);
		}
		return $clean;
	}

	/**
	 * Converts rich Panel objects into compact trace-safe summaries.
	 *
	 * Long strings are truncated, large arrays are summarized, and arbitrary
	 * objects are reduced to class names so traces remain bounded and
	 * serializable. Known Panel state objects expose counts, names, and status
	 * fields instead of full records, form values, or rendered content.
	 *
	 * @param mixed $value Raw context value.
	 * @return mixed Sanitized scalar, array, or compact summary.
	 */
	private static function sanitizeValue(mixed $value): mixed {
		if($value instanceof Resource){
			return $value->name();
		}
		if($value instanceof RelationManager || $value instanceof Action || $value instanceof Field || $value instanceof Column || $value instanceof Widget || $value instanceof PanelCommand){
			return $value->toArray()['name'] ?? $value::class;
		}
		if($value instanceof PanelRequest){
			return $value->toArray();
		}
		if($value instanceof PanelPageResult){
			return [
				'status'=>$value->status(),
				'redirect_to'=>$value->redirectTo(),
				'data_keys'=>array_keys($value->data()),
				'notification_count'=>count($value->notifications()),
				'content_bytes'=>strlen($value->content()),
			];
		}
		if($value instanceof PanelFormState){
			return [
				'valid'=>$value->valid(),
				'value_keys'=>array_keys($value->values()),
				'errors'=>$value->errors(),
			];
		}
		if($value instanceof PanelActionState){
			return [
				'action'=>$value->actionName(),
				'mode'=>$value->mode(),
				'stage'=>$value->stage(),
				'valid'=>$value->valid(),
				'bulk'=>$value->bulk(),
				'selected_count'=>$value->selectedCount(),
				'data_keys'=>array_keys($value->data()),
			];
		}
		if($value instanceof PanelInfolistState){
			return [
				'record_key'=>$value->recordKey(),
				'record_title'=>$value->recordTitle(),
				'entry_count'=>count($value->entries()),
				'visible_entry_count'=>count($value->visibleEntries()),
				'sections'=>array_keys($value->visibleSections()),
			];
		}
		if($value instanceof PanelRelationState){
			return [
				'relation'=>$value->relationName(),
				'parent_key'=>(string)($value->parent()['key'] ?? ''),
				'all_records'=>count($value->allRecords()),
				'filtered_records'=>count($value->filteredRecords()),
				'page_records'=>count($value->pageRecords()),
				'page'=>$value->tableState()->page(),
				'per_page'=>$value->tableState()->perPage(),
			];
		}
		if($value instanceof PanelNavigationState){
			return [
				'entries'=>count($value->entries()),
				'groups'=>count($value->groups()),
				'active'=>$value->active(),
				'search'=>[
					'query'=>(string)($value->search()['query'] ?? ''),
					'result_count'=>(int)($value->search()['result_count'] ?? 0),
				],
			];
		}
		if($value instanceof PanelCommandState){
			return [
				'commands'=>count($value->commands()),
				'groups'=>count($value->groups()),
				'query'=>$value->query(),
				'matches'=>count($value->matched()),
			];
		}
		if($value instanceof PanelSurfaceState){
			return [
				'title'=>$value->title(),
				'kind'=>$value->kind(),
				'status'=>$value->status(),
				'navigation'=>$value->navigation(),
				'commands'=>$value->commands(),
				'state_keys'=>array_keys($value->states()),
				'chrome'=>$value->chrome(),
			];
		}
		if($value instanceof PanelWidgetState){
			return [
				'name'=>$value->name(),
				'type'=>$value->type(),
				'label'=>$value->label(),
				'tone'=>$value->tone(),
				'has_error'=>$value->hasError(),
				'chart'=>$value->chart()!==[] ? [
					'type'=>$value->chart()['type'] ?? '',
					'points'=>$value->chart()['point_count'] ?? 0,
					'datasets'=>count($value->chart()['datasets'] ?? []),
				] : null,
			];
		}
		if(is_array($value)){
			if(count($value)>25){
				return [
					'type'=>'array',
					'count'=>count($value),
					'keys'=>array_slice(array_keys($value), 0, 25),
				];
			}
			return array_map([self::class, 'sanitizeValue'], $value);
		}
		if(is_object($value)){
			return [
				'type'=>'object',
				'class'=>$value::class,
			];
		}
		if(is_string($value) && strlen($value)>500){
			return substr($value, 0, 500).'...';
		}
		return $value;
	}
}
