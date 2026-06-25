<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_STATE_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_STATE_TRAIT_LOADED', true);

/**
 * Collects Flightdeck debugbar state from the current request lifecycle.
 *
 * The trait assembles SQL, routing, request, response, templating, panel,
 * Reactor, runtime, trace, timeline, error, and diagnostic snapshots into the
 * payload consumed by the debugbar renderer and persisted snapshots.
 */
trait dataphyre_flightdeck_debugbar_state {

	/**
	 * Builds the full debugbar state payload for the current request.
	 *
	 * Calling this method may attach the SQL observer and inspect global runtime
	 * state, headers, included files, buffers, tracelog entries, and module
	 * diagnostics. It does not emit debugbar markup.
	 *
	 * @param ?string $buffer Optional response buffer used for response diagnostics.
	 * @return array<string,mixed> Complete debugbar state payload.
	 */
	public static function state(?string $buffer=null): array {
		self::attach_sql_observer();
		$started=defined('REQUEST_TIME_FLOAT') ? (float)REQUEST_TIME_FLOAT : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
		$duration=max(0, (microtime(true) - $started) * 1000);
		$included=get_included_files();
		$modules=[];
		foreach($included as $file){
			$normalized=str_replace('\\', '/', $file);
			if(preg_match('#/modules/([^/]+)/#', $normalized, $match)){
				$modules[$match[1]]=true;
			}
		}
		ksort($modules);
		$sql=self::sql_state();
		$routing=self::routing_state();
		$request=self::request_state();
		$response=self::response_state($buffer);
		$templating=self::templating_state();
		$panel=self::panel_state(array_keys($modules));
		$reactor=self::reactor_state(array_keys($modules));
		$runtime=self::runtime_state($included, array_keys($modules));
		$asset_node=self::asset_node_state(array_keys($modules));
		$trace=self::trace_state();
		$timeline=self::timeline_state($started, $duration, $sql, $routing, $request, $runtime, $panel, $reactor);
		$errors=self::error_state();
		$diagnostics=self::diagnostics_state($sql, $routing, $request, $response, $templating, $runtime, $trace, $timeline, $errors, $panel, $reactor);
		return [
			'available'=>true,
			'enabled'=>self::enabled(),
			'request_id'=>defined('RQID') ? (string)RQID : '',
			'app'=>defined('APP') ? (string)APP : '',
			'method'=>$_SERVER['REQUEST_METHOD'] ?? 'GET',
			'uri'=>$_SERVER['REQUEST_URI'] ?? '',
			'duration_ms'=>round($duration, 3),
			'memory_mb'=>round(memory_get_usage(true) / 1048576, 3),
			'peak_mb'=>round(memory_get_peak_usage(true) / 1048576, 3),
			'files'=>count($included),
			'modules'=>array_keys($modules),
			'run_mode'=>defined('RUN_MODE') ? (string)RUN_MODE : '',
			'production'=>defined('IS_PRODUCTION') && IS_PRODUCTION===true,
			'sql'=>$sql,
			'routing'=>$routing,
			'request'=>$request,
			'response'=>$response,
			'templating'=>$templating,
			'panel'=>$panel,
			'reactor'=>$reactor,
			'asset_node'=>$asset_node,
			'runtime'=>$runtime,
			'trace'=>$trace,
			'timeline'=>$timeline,
			'errors'=>$errors,
			'diagnostics'=>$diagnostics,
		];
	}

	/**
	 * Collects Reactor manifest, trace, component, and insight state.
	 *
	 * @param list<string> $modules Runtime module names detected from included files.
	 * @return array<string,mixed> Reactor debugbar payload, or an empty array when Reactor is unavailable.
	 */
	private static function reactor_state(array $modules): array {
		$available=in_array('reactor', $modules, true) || class_exists('\\Dataphyre\\Reactor\\Reactor', false);
		if($available!==true){
			return [];
		}
		$loaded=class_exists('\\Dataphyre\\Reactor\\Reactor', false);
		if($loaded!==true){
			return [
				'available'=>true,
				'loaded'=>false,
				'event_count'=>0,
				'events'=>[],
				'components'=>[],
				'capability_counts'=>[],
				'event_counts'=>[],
				'insights'=>[],
				'manifest'=>[],
			];
		}
		try{
			$manifest=\Dataphyre\Reactor\Reactor::manifest();
		}
		catch(\Throwable $exception){
			$manifest=['error'=>$exception->getMessage()];
		}
		try{
			$events=class_exists('\\Dataphyre\\Reactor\\ReactorTrace', false) ? \Dataphyre\Reactor\ReactorTrace::events() : [];
		}
		catch(\Throwable){
			$events=[];
		}
		$events=self::reactor_events_state($events);
		$components=is_array($manifest['components'] ?? null) ? $manifest['components'] : [];
		$capability_counts=[];
		foreach($components as $component){
			if(!is_array($component)){
				continue;
			}
			foreach(is_array($component['capabilities'] ?? null) ? $component['capabilities'] : [] as $capability){
				$capability=(string)$capability;
				$capability_counts[$capability]=($capability_counts[$capability] ?? 0)+1;
			}
		}
		ksort($capability_counts);
		$event_counts=[];
		foreach($events as $event){
			$name=(string)($event['event'] ?? 'event');
			$event_counts[$name]=($event_counts[$name] ?? 0)+1;
		}
		ksort($event_counts);
		return [
			'available'=>true,
			'loaded'=>true,
			'event_count'=>count($events),
			'events'=>$events,
			'event_counts'=>$event_counts,
			'components'=>array_slice(self::reactor_component_summary($components), 0, 80),
			'capability_counts'=>$capability_counts,
			'manifest_error'=>(string)($manifest['error'] ?? ''),
			'manifest'=>self::reactor_manifest_summary($manifest),
			'insights'=>self::reactor_insights($events, $components, $manifest),
		];
	}

	/**
	 * Normalizes recent Reactor trace events for debugbar display.
	 *
	 * @param list<array<string,mixed>> $events Raw ReactorTrace events.
	 * @return list<array{id:string,time:float,event:string,category:string,component:string,action:string,status:?int,duration_ms:?float,memory_delta:?int,memory:int,context:array<string,string>}> Bounded normalized event rows.
	 */
	private static function reactor_events_state(array $events): array {
		$rows=[];
		foreach(array_slice($events, -160) as $event){
			if(!is_array($event)){
				continue;
			}
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$name=(string)($event['event'] ?? 'event');
			$rows[]=[
				'id'=>(string)($event['id'] ?? ''),
				'time'=>is_numeric($event['time'] ?? null) ? (float)$event['time'] : 0.0,
				'event'=>$name,
				'category'=>self::reactor_event_category($name),
				'component'=>(string)($context['component'] ?? $context['requested'] ?? ''),
				'action'=>(string)($context['action'] ?? ''),
				'status'=>is_numeric($context['status'] ?? null) ? (int)$context['status'] : null,
				'duration_ms'=>is_numeric($context['duration_ms'] ?? null) ? (float)$context['duration_ms'] : null,
				'memory_delta'=>is_numeric($context['memory_delta'] ?? null) ? (int)$context['memory_delta'] : null,
				'memory'=>(int)($event['memory'] ?? 0),
				'context'=>self::reactor_context_summary($context),
			];
		}
		return $rows;
	}

	/**
	 * Maps a Reactor event name to a debugbar category.
	 *
	 * @param string $event Raw Reactor event name.
	 * @return string Category label used by filters and summaries.
	 */
	private static function reactor_event_category(string $event): string {
		$event=strtolower($event);
		foreach([
			'snapshot'=>'snapshot',
			'authorization'=>'auth',
			'validation'=>'validation',
			'action'=>'action',
			'effect'=>'effect',
			'model'=>'model',
			'component'=>'component',
			'manifest'=>'manifest',
			'request'=>'request',
			'response'=>'response',
			'span'=>'span',
		] as $needle=>$category){
			if(str_contains($event, $needle)){
				return $category;
			}
		}
		return 'lifecycle';
	}

	/**
	 * Reduces Reactor event context into scalar-safe summary values.
	 *
	 * @param array<string,mixed> $context Raw event context.
	 * @return array<string,string> Context summary safe for JSON snapshots.
	 */
	private static function reactor_context_summary(array $context): array {
		$summary=[];
		foreach($context as $key=>$value){
			$key=(string)$key;
			if(is_scalar($value) || $value===null){
				$summary[$key]=self::shorten((string)($value ?? 'null'), 160);
				continue;
			}
			if(is_array($value)){
				$summary[$key]='array('.count($value).')';
				continue;
			}
			$summary[$key]=get_debug_type($value);
		}
		return $summary;
	}

	/**
	 * Summarizes Reactor component capabilities and registered behavior counts.
	 *
	 * @param list<array<string,mixed>> $components Reactor manifest component rows.
	 * @return list<array{name:string,capabilities:list<mixed>,state_keys:int,locked:int,actions:int,computed:int,rules:int,listeners:int,session:int,bindings:array<string,int>}> Component summary rows.
	 */
	private static function reactor_component_summary(array $components): array {
		$rows=[];
		foreach($components as $component){
			if(!is_array($component)){
				continue;
			}
			$bindings=is_array($component['bindings'] ?? null) ? $component['bindings'] : [];
			$rows[]=[
				'name'=>(string)($component['name'] ?? ''),
				'capabilities'=>array_values(is_array($component['capabilities'] ?? null) ? $component['capabilities'] : []),
				'state_keys'=>count(is_array($component['state_keys'] ?? null) ? $component['state_keys'] : []),
				'locked'=>count(is_array($component['locked'] ?? null) ? $component['locked'] : []),
				'actions'=>count(is_array($component['actions'] ?? null) ? $component['actions'] : []),
				'computed'=>count(is_array($component['computed'] ?? null) ? $component['computed'] : []),
				'rules'=>count(is_array($component['rules'] ?? null) ? $component['rules'] : []),
				'listeners'=>count(is_array($component['listeners'] ?? null) ? $component['listeners'] : []),
				'session'=>count(is_array($component['session'] ?? null) ? $component['session'] : []),
				'bindings'=>array_map(static fn(mixed $fields): int => count(is_array($fields) ? $fields : []), $bindings),
			];
		}
		return $rows;
	}

	/**
	 * Extracts high-level Reactor manifest counters.
	 *
	 * @param array<string,mixed> $manifest Reactor manifest payload.
	 * @return array{module:string,version:string,component_count:int,trace_count:int} Compact manifest summary.
	 */
	private static function reactor_manifest_summary(array $manifest): array {
		return [
			'module'=>(string)($manifest['module'] ?? 'reactor'),
			'version'=>(string)($manifest['version'] ?? ''),
			'component_count'=>(int)($manifest['component_count'] ?? 0),
			'trace_count'=>(int)($manifest['trace']['count'] ?? 0),
		];
	}

	/**
	 * Builds Reactor diagnostic insights from manifest and trace evidence.
	 *
	 * @param list<array<string,mixed>> $events Normalized Reactor event rows.
	 * @param list<array<string,mixed>> $components Reactor manifest component rows.
	 * @param array<string,mixed> $manifest Reactor manifest payload.
	 * @return list<array{level:string,source:string,title:string,detail:string}> Insight rows.
	 */
	private static function reactor_insights(array $events, array $components, array $manifest): array {
		$insights=[];
		if((string)($manifest['error'] ?? '')!==''){
			$insights[]=['level'=>'error', 'source'=>'reactor', 'title'=>'Reactor manifest failed', 'detail'=>(string)$manifest['error']];
		}
		$errors=array_values(array_filter($events, static function(array $event): bool {
			$name=strtolower((string)($event['event'] ?? ''));
			return str_contains($name, 'failed') || str_contains($name, 'invalid') || str_contains($name, 'denied') || str_contains($name, 'missing');
		}));
		if($errors!==[]){
			$insights[]=['level'=>'warning', 'source'=>'reactor', 'title'=>'Reactor lifecycle warnings', 'detail'=>count($errors).' Reactor event'.(count($errors)===1 ? '' : 's').' reported a failed, invalid, denied, or missing state.'];
		}
		if($components===[] && $events!==[]){
			$insights[]=['level'=>'warning', 'source'=>'reactor', 'title'=>'Reactor emitted events without components', 'detail'=>'Lifecycle activity exists, but the manifest had no registered components.'];
		}
		foreach($components as $component){
			if(!is_array($component)){
				continue;
			}
			$actions=count(is_array($component['actions'] ?? null) ? $component['actions'] : []);
			$rules=count(is_array($component['rules'] ?? null) ? $component['rules'] : []);
			if($actions>0 && $rules===0){
				$insights[]=['level'=>'info', 'source'=>'reactor', 'title'=>'Reactive actions without validation', 'detail'=>'Component '.(string)($component['name'] ?? 'unknown').' exposes '.$actions.' action'.($actions===1 ? '' : 's').' and no validation rules.'];
				break;
			}
		}
		return $insights;
	}

	/**
	 * Collects Panel trace, describe, resource, page, widget, and insight state.
	 *
	 * @param list<string> $modules Runtime module names detected from included files.
	 * @return array<string,mixed> Panel debugbar payload, or an empty array when Panel is unavailable.
	 */
	private static function panel_state(array $modules): array {
		$available=in_array('panel', $modules, true) || class_exists('\\Dataphyre\\Panel\\Panel', false);
		if($available!==true){
			return [];
		}
		$loaded=class_exists('\\Dataphyre\\Panel\\Panel', false);
		if($loaded!==true){
			return [
				'available'=>true,
				'loaded'=>false,
				'event_count'=>0,
				'events'=>[],
				'category_counts'=>[],
				'describe'=>[],
				'insights'=>[],
			];
		}
		try{
			$summary=\Dataphyre\Panel\Panel::trace_summary();
		}
		catch(\Throwable $exception){
			$summary=['count'=>0, 'events'=>[], 'latest'=>[], 'error'=>$exception->getMessage()];
		}
		try{
			$events=\Dataphyre\Panel\Panel::trace();
		}
		catch(\Throwable){
			$events=[];
		}
		try{
			$describe=\Dataphyre\Panel\Panel::describe();
		}
		catch(\Throwable $exception){
			$describe=['error'=>$exception->getMessage()];
		}
		$events=self::panel_events_state($events);
		$resources=is_array($describe['resources'] ?? null) ? $describe['resources'] : [];
		$pages=is_array($describe['pages'] ?? null) ? $describe['pages'] : [];
		$widgets=is_array($describe['widgets'] ?? null) ? $describe['widgets'] : [];
		$actions=self::panel_action_summary($resources);
		$category_counts=[];
		foreach($events as $event){
			$category=(string)($event['category'] ?? 'lifecycle');
			$category_counts[$category]=($category_counts[$category] ?? 0)+1;
		}
		ksort($category_counts);
		$operation_counts=[];
		foreach($events as $event){
			$operation=(string)($event['operation'] ?? '');
			if($operation!==''){
				$operation_counts[$operation]=($operation_counts[$operation] ?? 0)+1;
			}
		}
		arsort($operation_counts);
		return [
			'available'=>true,
			'loaded'=>true,
			'event_count'=>count($events),
			'events'=>$events,
			'summary'=>is_array($summary) ? $summary : [],
			'category_counts'=>$category_counts,
			'operation_counts'=>array_slice($operation_counts, 0, 16, true),
			'resources'=>array_slice(self::panel_resource_summary($resources), 0, 80),
			'pages'=>array_slice(self::panel_page_summary($pages), 0, 80),
			'widgets'=>array_slice(self::panel_widget_summary($widgets), 0, 80),
			'actions'=>$actions,
			'navigation'=>array_slice(is_array($describe['navigation'] ?? null) ? $describe['navigation'] : [], 0, 80),
			'theme'=>is_array($describe['theme'] ?? null) ? $describe['theme'] : [],
			'describe_error'=>(string)($describe['error'] ?? ''),
			'insights'=>self::panel_insights($events, $resources, $pages, $widgets, $actions, $describe),
		];
	}

	/**
	 * Normalizes recent Panel trace events for debugbar display.
	 *
	 * @param list<array<string,mixed>> $events Raw Panel trace events.
	 * @return list<array{id:string,time:float,event:string,category:string,resource:string,operation:string,status:?int,duration_ms:?float,memory_delta:?int,memory:int,context:array<string,string>}> Bounded normalized event rows.
	 */
	private static function panel_events_state(array $events): array {
		$rows=[];
		foreach(array_slice($events, -160) as $event){
			if(!is_array($event)){
				continue;
			}
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$name=(string)($event['event'] ?? 'event');
			$rows[]=[
				'id'=>(string)($event['id'] ?? ''),
				'time'=>is_numeric($event['time'] ?? null) ? (float)$event['time'] : 0.0,
				'event'=>$name,
				'category'=>self::panel_event_category($name),
				'resource'=>(string)($context['resource'] ?? ($context['request']['resource'] ?? '')),
				'operation'=>(string)($context['operation'] ?? ($context['request']['operation'] ?? '')),
				'status'=>is_numeric($context['result']['status'] ?? null) ? (int)$context['result']['status'] : null,
				'duration_ms'=>is_numeric($context['duration_ms'] ?? null) ? (float)$context['duration_ms'] : null,
				'memory_delta'=>is_numeric($context['memory_delta'] ?? null) ? (int)$context['memory_delta'] : null,
				'memory'=>(int)($event['memory'] ?? 0),
				'context'=>self::panel_context_summary($context),
			];
		}
		return $rows;
	}

	/**
	 * Maps a Panel event name to a debugbar category.
	 *
	 * @param string $event Raw Panel event name.
	 * @return string Category label used by filters and summaries.
	 */
	private static function panel_event_category(string $event): string {
		$event=strtolower($event);
		foreach([
			'action'=>'action',
			'bulk'=>'bulk',
			'save'=>'write',
			'delete'=>'write',
			'restore'=>'write',
			'duplicate'=>'write',
			'import'=>'import',
			'export'=>'export',
			'form'=>'form',
			'field'=>'form',
			'table'=>'table',
			'relation'=>'relation',
			'widget'=>'widget',
			'navigation'=>'navigation',
			'theme'=>'theme',
			'search'=>'search',
			'request'=>'request',
			'page'=>'page',
			'resource'=>'resource',
		] as $needle=>$category){
			if(str_contains($event, $needle)){
				return $category;
			}
		}
		return 'lifecycle';
	}

	/**
	 * Reduces Panel event context into scalar-safe summary values.
	 *
	 * @param array<string,mixed> $context Raw event context.
	 * @return array<string,string> Context summary safe for JSON snapshots.
	 */
	private static function panel_context_summary(array $context): array {
		$summary=[];
		foreach($context as $key=>$value){
			$key=(string)$key;
			if(is_scalar($value) || $value===null){
				$summary[$key]=self::shorten((string)($value ?? 'null'), 160);
				continue;
			}
			if(is_array($value)){
				if(isset($value['resource'], $value['operation'])){
					$summary[$key]='request '.$value['resource'].'/'.$value['operation'];
				}
				elseif(isset($value['status'])){
					$summary[$key]='status '.$value['status'];
				}
				elseif(isset($value['type'], $value['count'])){
					$summary[$key]=(string)$value['type'].'('.(string)$value['count'].')';
				}
				else
				{
					$summary[$key]='array('.count($value).')';
				}
				continue;
			}
			$summary[$key]=get_debug_type($value);
		}
		return $summary;
	}

	/**
	 * Summarizes described panel resources for debugbar tables.
	 *
	 * @param list<array<string,mixed>> $resources Panel::describe() resource rows.
	 * @return list<array{name:string,label:string,group:string,source:string,fields:int,columns:int,filters:int,views:int,actions:int,relations:int,searchable:bool,hidden:bool}> Resource summary rows.
	 */
	private static function panel_resource_summary(array $resources): array {
		$rows=[];
		foreach($resources as $resource){
			if(!is_array($resource)){
				continue;
			}
			$table=is_array($resource['table_schema'] ?? null) ? $resource['table_schema'] : [];
			$form=is_array($resource['form'] ?? null) ? $resource['form'] : [];
			$rows[]=[
				'name'=>(string)($resource['name'] ?? ''),
				'label'=>(string)($resource['label'] ?? ''),
				'group'=>(string)($resource['navigation_group'] ?? $resource['group'] ?? ''),
				'source'=>(string)($resource['table'] ?? $resource['repository'] ?? $resource['model'] ?? ''),
				'fields'=>count(is_array($form['fields'] ?? null) ? $form['fields'] : []),
				'columns'=>count(is_array($table['columns'] ?? null) ? $table['columns'] : []),
				'filters'=>count(is_array($table['filters'] ?? null) ? $table['filters'] : []),
				'views'=>count(is_array($table['views'] ?? null) ? $table['views'] : []),
				'actions'=>count(is_array($resource['actions'] ?? null) ? $resource['actions'] : []),
				'relations'=>count(is_array($resource['relations'] ?? null) ? $resource['relations'] : []),
				'searchable'=>!empty($resource['global_searchable']),
				'hidden'=>!empty($resource['hidden_from_navigation']),
			];
		}
		return $rows;
	}

	/**
	 * Summarizes described panel pages for debugbar tables.
	 *
	 * @param list<array<string,mixed>> $pages Panel::describe() page rows.
	 * @return list<array{name:string,label:string,route:string,group:string,actions:int,renders:bool,authorizes:bool}> Page summary rows.
	 */
	private static function panel_page_summary(array $pages): array {
		$rows=[];
		foreach($pages as $page){
			if(!is_array($page)){
				continue;
			}
			$rows[]=[
				'name'=>(string)($page['name'] ?? ''),
				'label'=>(string)($page['label'] ?? ''),
				'route'=>(string)($page['route'] ?? ''),
				'group'=>(string)($page['group'] ?? ''),
				'actions'=>count(is_array($page['actions'] ?? null) ? $page['actions'] : []),
				'renders'=>!empty($page['renders']),
				'authorizes'=>!empty($page['authorizes']),
			];
		}
		return $rows;
	}

	/**
	 * Summarizes described panel widgets for debugbar tables.
	 *
	 * @param list<array<string,mixed>> $widgets Panel::describe() widget rows.
	 * @return list<array{name:string,label:string,type:string,tone:string,sort:int}> Widget summary rows.
	 */
	private static function panel_widget_summary(array $widgets): array {
		$rows=[];
		foreach($widgets as $widget){
			if(!is_array($widget)){
				continue;
			}
			$rows[]=[
				'name'=>(string)($widget['name'] ?? ''),
				'label'=>(string)($widget['label'] ?? ''),
				'type'=>(string)($widget['type'] ?? ''),
				'tone'=>(string)($widget['tone'] ?? ''),
				'sort'=>(int)($widget['sort'] ?? 100),
			];
		}
		return $rows;
	}

	/**
	 * Extracts resource action summaries from described resources.
	 *
	 * @param list<array<string,mixed>> $resources Panel::describe() resource rows.
	 * @return list<array{resource:string,name:string,label:string,tone:string,modal:bool,requires_confirmation:bool}> Bounded action summary rows.
	 */
	private static function panel_action_summary(array $resources): array {
		$rows=[];
		foreach($resources as $resource){
			if(!is_array($resource)){
				continue;
			}
			foreach(is_array($resource['actions'] ?? null) ? $resource['actions'] : [] as $action){
				if(!is_array($action)){
					continue;
				}
				$rows[]=[
					'resource'=>(string)($resource['name'] ?? ''),
					'name'=>(string)($action['name'] ?? ''),
					'label'=>(string)($action['label'] ?? ''),
					'tone'=>(string)($action['tone'] ?? ''),
					'modal'=>!empty($action['modal']),
					'requires_confirmation'=>!empty($action['requires_confirmation']),
				];
			}
		}
		return array_slice($rows, 0, 120);
	}

	/**
	 * Builds Panel diagnostic insights from trace and describe evidence.
	 *
	 * @param list<array<string,mixed>> $events Normalized Panel event rows.
	 * @param list<array<string,mixed>> $resources Described resources.
	 * @param list<array<string,mixed>> $pages Described pages.
	 * @param list<array<string,mixed>> $widgets Described widgets.
	 * @param list<array<string,mixed>> $actions Action summary rows.
	 * @param array<string,mixed> $describe Raw Panel::describe() payload.
	 * @return list<array{level:string,source:string,title:string,detail:string}> Insight rows.
	 */
	private static function panel_insights(array $events, array $resources, array $pages, array $widgets, array $actions, array $describe): array {
		$insights=[];
		$errors=array_values(array_filter($events, static fn(array $event): bool => str_contains((string)($event['event'] ?? ''), 'error') || str_contains((string)($event['event'] ?? ''), 'failed')));
		if($errors!==[]){
			$insights[]=['level'=>'error', 'source'=>'panel', 'title'=>'Panel lifecycle errors', 'detail'=>count($errors).' Panel event'.(count($errors)===1 ? '' : 's').' reported an error or failed span.'];
		}
		if((string)($describe['error'] ?? '')!==''){
			$insights[]=['level'=>'error', 'source'=>'panel', 'title'=>'Panel description failed', 'detail'=>(string)$describe['error']];
		}
		if($resources===[] && $pages===[] && $widgets===[] && $events!==[]){
			$insights[]=['level'=>'warning', 'source'=>'panel', 'title'=>'Panel emitted events without registered surfaces', 'detail'=>'Lifecycle activity exists, but no resources, pages, or widgets were visible to describe().'];
		}
		if(count($actions)>0 && count(array_filter($actions, static fn(array $action): bool => !empty($action['requires_confirmation'])))===0){
			$insights[]=['level'=>'info', 'source'=>'panel', 'title'=>'Actions have no confirmation gates', 'detail'=>count($actions).' registered actions were found and none advertise a confirmation requirement.'];
		}
		return $insights;
	}

	/**
	 * Collects asset-node server, request, trace, and insight state.
	 *
	 * @param list<string> $modules Runtime module names detected from included files.
	 * @return array<string,mixed> Asset-node debugbar payload, or an empty array when unavailable.
	 */
	private static function asset_node_state(array $modules): array {
		$available=in_array('asset_node', $modules, true)
			|| class_exists('dataphyre\\asset_node', false)
			|| defined('DP_ASSET_NODE_CFG');
		if($available!==true){
			return [];
		}
		$config=defined('DP_ASSET_NODE_CFG') && is_array(DP_ASSET_NODE_CFG) ? DP_ASSET_NODE_CFG : [];
		$servers=is_array($config['servers'] ?? null) ? $config['servers'] : [];
		$current_ip='';
		$current_name='';
		$current_info=false;
		$configured=null;
		$server_step=false;
		$can_store=null;
		$storage_path='';
		if(class_exists('dataphyre\\asset_node', false)){
			$current_ip=(string)self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::current_server_ip(), '');
			$current_name=(string)self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::current_server_name(), '');
			$current_info=self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::current_server_info(), false);
			$configured=(bool)self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::configured(), false);
			$server_step=self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::get_server_step(), false);
			$can_store=(bool)self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::can_store_block(), false);
			$storage_path=(string)self::asset_node_safe_call(static fn()=>\dataphyre\asset_node::storage_path(), '');
		}
		$disk_path=$storage_path!=='' ? (is_dir($storage_path) ? $storage_path : dirname($storage_path)) : '';
		$total_space=$disk_path!=='' ? @disk_total_space($disk_path) : false;
		$free_space=$disk_path!=='' ? @disk_free_space($disk_path) : false;
		$total_bytes=is_numeric($total_space) ? (int)$total_space : 0;
		$free_bytes=is_numeric($free_space) ? (int)$free_space : 0;
		$used_percent=$total_bytes>0 ? round((1-($free_bytes/$total_bytes))*100, 2) : 0.0;
		$default_protocol=(string)($config['default_protocol'] ?? 'http');
		$default_port=(int)($config['default_port'] ?? 0);
		$effective_default_port=$default_port>0 ? $default_port : ($default_protocol==='https' ? 443 : 80);
		return [
			'available'=>true,
			'configured'=>$configured,
			'current_ip'=>$current_ip,
			'current_name'=>$current_name,
			'current_info'=>is_array($current_info) ? $current_info : [],
			'server_step'=>$server_step===false ? null : (int)$server_step,
			'can_store'=>$can_store,
			'storage'=>[
				'path'=>$storage_path,
				'disk_path'=>$disk_path,
				'exists'=>$storage_path!=='' && is_dir($storage_path),
				'total_bytes'=>$total_bytes,
				'free_bytes'=>$free_bytes,
				'used_percent'=>$used_percent,
			],
			'config'=>[
				'server_count'=>count($servers),
				'redundancy_level'=>(int)($config['redundancy_level'] ?? 0),
				'default_protocol'=>$default_protocol,
				'default_port'=>$default_port,
				'effective_default_port'=>$effective_default_port,
				'containerization_size_threshold'=>(int)($config['containerization_size_threshold'] ?? 0),
				'datacenter_priority'=>is_array($config['datacenter_priority'] ?? null) ? array_values($config['datacenter_priority']) : [],
			],
			'servers'=>self::asset_node_servers_state($servers),
			'request'=>self::asset_node_request_state(),
			'trace'=>self::asset_node_trace_state(),
		];
	}

	/**
	 * Executes an asset-node diagnostic callback without breaking debugbar state.
	 *
	 * @param callable $callback Diagnostic callback.
	 * @param mixed $fallback Value returned when the callback throws.
	 * @return mixed diagnostic value from the callback, or the supplied fallback when collection throws.
	 */
	private static function asset_node_safe_call(callable $callback, mixed $fallback): mixed {
		try{
			return $callback();
		}catch(\Throwable){
			return $fallback;
		}
	}

	/**
	 * Normalizes configured asset-node server rows.
	 *
	 * @param array<string,array<string,mixed>> $servers Raw asset-node server payloads keyed by IP.
	 * @return list<array{ip:string,name:string,datacenter:string,protocol:string,port:int}> Server summary rows.
	 */
	private static function asset_node_servers_state(array $servers): array {
		$rows=[];
		foreach(array_slice($servers, 0, 16, true) as $ip=>$server){
			$server=is_array($server) ? $server : [];
			$rows[]=[
				'ip'=>(string)$ip,
				'name'=>(string)($server['name'] ?? ''),
				'datacenter'=>(string)($server['datacenter'] ?? ''),
				'protocol'=>(string)($server['protocol'] ?? ''),
				'port'=>(int)($server['port'] ?? 0),
			];
		}
		return $rows;
	}

	/**
	 * Captures request metadata relevant to asset-node routing.
	 *
	 * @return array{uri:string,params:array<string,string>,has_passkey:bool,has_expected_hash:bool,content_probe:array<string,string>} Asset-node request payload.
	 */
	private static function asset_node_request_state(): array {
		$params=[];
		foreach(($_REQUEST ?? []) as $key=>$value){
			$key=(string)$key;
			if(in_array(strtolower($key), ['pvk', 'passkey', 'expected_hash'], true)){
				$params[$key]='[redacted]';
				continue;
			}
			if(is_scalar($value) || $value===null){
				$params[$key]=self::shorten((string)$value, 180);
			}
			else
			{
				$params[$key]='['.gettype($value).']';
			}
		}
		return [
			'uri'=>(string)($_REQUEST['uri'] ?? ''),
			'params'=>$params,
			'has_passkey'=>trim((string)($_REQUEST['passkey'] ?? ''))!=='',
			'has_expected_hash'=>trim((string)($_REQUEST['expected_hash'] ?? ''))!=='',
			'content_probe'=>self::asset_node_latest_trace_data(['content.local_probe']),
		];
	}

	/**
	 * Loads and summarizes the latest asset-node trace data.
	 *
	 * @return list<array{offset_ms:float,stage:string,data:array<string,string>}> Trace summary payload for debugbar rendering.
	 */
	private static function asset_node_trace_state(): array {
		$trace=$GLOBALS['dataphyre_asset_node_trace'] ?? [];
		if(!is_array($trace)){
			return [];
		}
		$rows=[];
		foreach(array_slice($trace, -48) as $entry){
			if(!is_array($entry)){
				continue;
			}
			$data=is_array($entry['data'] ?? null) ? $entry['data'] : [];
			$rows[]=[
				'offset_ms'=>round(max(0.0, (((float)($entry['time'] ?? microtime(true))) - (defined('REQUEST_TIME_FLOAT') ? (float)REQUEST_TIME_FLOAT : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)))) * 1000), 3),
				'stage'=>self::shorten((string)($entry['stage'] ?? ''), 80),
				'data'=>self::asset_node_trace_data($data),
			];
		}
		return $rows;
	}

	/**
	 * Normalizes one asset-node trace stage payload.
	 *
	 * @param array<string,mixed> $data Raw trace-stage data.
	 * @return array<string,string> Trace-stage summary.
	 */
	private static function asset_node_trace_data(array $data): array {
		$result=[];
		foreach($data as $key=>$value){
			$key=(string)$key;
			if(in_array(strtolower($key), ['pvk', 'passkey', 'expected_hash'], true)){
				$result[$key]='[redacted]';
				continue;
			}
			if(is_bool($value)){
				$result[$key]=$value ? 'true' : 'false';
			}
			elseif(is_scalar($value) || $value===null){
				$result[$key]=self::shorten((string)$value, 160);
			}
			else
			{
				$result[$key]='['.gettype($value).']';
			}
		}
		return $result;
	}

	/**
	 * Chooses the latest useful trace-stage payload from an asset-node trace.
	 *
	 * @param list<string>|array<string,string> $stages Ordered or keyed trace stages.
	 * @return array<string,string> Latest trace-stage summary.
	 */
	private static function asset_node_latest_trace_data(array $stages): array {
		$trace=$GLOBALS['dataphyre_asset_node_trace'] ?? [];
		if(!is_array($trace)){
			return [];
		}
		$stages=array_map('strval', $stages);
		for($index=count($trace)-1; $index>=0; $index--){
			$entry=$trace[$index] ?? null;
			if(!is_array($entry)){
				continue;
			}
			if(!in_array((string)($entry['stage'] ?? ''), $stages, true)){
				continue;
			}
			$data=is_array($entry['data'] ?? null) ? $entry['data'] : [];
			return self::asset_node_trace_data($data);
		}
		return [];
	}

	/**
	 * Summarizes PHP runtime, memory, file, module, and environment state.
	 *
	 * @param list<string> $included Included PHP file paths.
	 * @param list<string> $modules Runtime module names detected from included files.
	 * @return array<string,mixed> Runtime debugbar payload.
	 */
	private static function runtime_state(array $included, array $modules): array {
		$files_by_module=[];
		foreach($included as $file){
			$normalized=str_replace('\\', '/', $file);
			$module='application';
			if(preg_match('#/modules/([^/]+)/#', $normalized, $match)){
				$module=$match[1];
			}
			elseif(str_contains($normalized, '/common/')){
				$module='common';
			}
			$files_by_module[$module]=($files_by_module[$module] ?? 0) + 1;
		}
		arsort($files_by_module);
		$root_paths=[];
		if(defined('ROOTPATH') && is_array(ROOTPATH)){
			foreach(['dataphyre', 'common_dataphyre', 'common_dataphyre_runtime', 'applications', 'backend', 'views'] as $key){
				if(isset(ROOTPATH[$key]) && is_string(ROOTPATH[$key])){
					$root_paths[$key]=ROOTPATH[$key];
				}
			}
		}
		$opcache_status=function_exists('opcache_get_status') ? opcache_get_status(false) : false;
		return [
			'php_version'=>PHP_VERSION,
			'sapi'=>PHP_SAPI,
			'os'=>PHP_OS_FAMILY,
			'timezone'=>date_default_timezone_get(),
			'memory_limit'=>(string)ini_get('memory_limit'),
			'max_execution_time'=>(string)ini_get('max_execution_time'),
			'display_errors'=>(string)ini_get('display_errors'),
			'log_errors'=>(string)ini_get('log_errors'),
			'error_reporting'=>error_reporting(),
			'flightdeck_debugbar_memory_limit'=>is_array($GLOBALS['dataphyre_flightdeck_debugbar_memory_limit'] ?? null) ? $GLOBALS['dataphyre_flightdeck_debugbar_memory_limit'] : null,
			'opcache_enabled'=>is_array($opcache_status) ? (bool)($opcache_status['opcache_enabled'] ?? false) : false,
			'extensions_count'=>count(get_loaded_extensions()),
			'files_count'=>count($included),
			'modules_count'=>count($modules),
			'modules'=>$modules,
			'files_by_module'=>$files_by_module,
			'included_tail'=>array_values(array_slice(array_map(static fn(string $file): string => str_replace('\\', '/', $file), $included), -24)),
			'root_paths'=>$root_paths,
			'session_status'=>session_status(),
			'session_name'=>session_name(),
			'initial_memory_bytes'=>defined('INITIAL_MEMORY_USAGE') ? (int)INITIAL_MEMORY_USAGE : 0,
		];
	}

	/**
	 * Builds the display label used for a stored debugbar snapshot.
	 *
	 * @param array<string,mixed> $state Complete debugbar state payload.
	 * @return string Human-readable snapshot label.
	 */
	private static function snapshot_label(array $state): string {
		$method=(string)($state['method'] ?? $state['request']['method'] ?? 'GET');
		$uri=(string)($state['uri'] ?? $state['request']['path'] ?? '/');
		return trim($method.' '.$uri);
	}

	/**
	 * Captures PHP last-error and recent retroactive tracelog errors.
	 *
	 * @return array{count:int,counts:array<string,int>,events:list<array<string,mixed>>} Error summary payload.
	 */
	private static function error_state(): array {
		$events=$GLOBALS['dataphyre_flightdeck_php_errors'] ?? [];
		$events=is_array($events) ? $events : [];
		$normalized=[];
		$counts=[];
		foreach(array_slice($events, -self::ERROR_BUFFER_LIMIT) as $event){
			if(!is_array($event)){
				continue;
			}
			$severity=self::php_error_severity((int)($event['errno'] ?? 0));
			$counts[$severity]=($counts[$severity] ?? 0) + 1;
			$normalized[]=[
				'errno'=>(int)($event['errno'] ?? 0),
				'severity'=>$severity,
				'message'=>self::shorten((string)($event['message'] ?? ''), 260),
				'file'=>(string)($event['file'] ?? ''),
				'line'=>(int)($event['line'] ?? 0),
				'timestamp'=>is_numeric($event['timestamp'] ?? null) ? (float)$event['timestamp'] : microtime(true),
				'memory_bytes'=>(int)($event['memory_bytes'] ?? 0),
				'stack'=>is_array($event['stack'] ?? null) ? array_slice($event['stack'], 0, 12) : [],
			];
		}
		return [
			'count'=>count($normalized),
			'counts'=>$counts,
			'events'=>$normalized,
		];
	}

	/**
	 * Builds cross-module diagnostic findings for the current request.
	 *
	 * @param array<string,mixed> $sql SQL debugbar state.
	 * @param array<string,mixed> $routing Routing debugbar state.
	 * @param array<string,mixed> $request Request debugbar state.
	 * @param array<string,mixed> $response Response debugbar state.
	 * @param array<string,mixed> $templating Templating debugbar state.
	 * @param array<string,mixed> $runtime Runtime debugbar state.
	 * @param array<string,mixed> $trace Trace debugbar state.
	 * @param array<string,mixed> $timeline Timeline debugbar state.
	 * @param array<string,mixed> $errors Error debugbar state.
	 * @param array<string,mixed> $panel Panel debugbar state.
	 * @param array<string,mixed> $reactor Reactor debugbar state.
	 * @return array{count:int,worst_level:string,findings:list<array{level:string,title:string,detail:string,source:string}>} Diagnostic finding rows.
	 */
	private static function diagnostics_state(array $sql, array $routing, array $request, array $response, array $templating, array $runtime, array $trace, array $timeline, array $errors, array $panel=[], array $reactor=[]): array {
		$findings=[];
		$add=static function(string $level, string $title, string $detail, string $source='runtime')use(&$findings): void{
			$findings[]=[
				'level'=>$level,
				'title'=>$title,
				'detail'=>$detail,
				'source'=>$source,
			];
		};
		$status=(int)($request['status'] ?? 200);
		if($status>=500){
			$add('error', 'Response is failing', 'HTTP '.$status.' was active when Flightdeck rendered.', 'request');
		}
		elseif($status>=400){
			$add('warning', 'Response is not successful', 'HTTP '.$status.' was active when Flightdeck rendered.', 'request');
		}
		$error_count=(int)($errors['count'] ?? 0);
		if($error_count>0){
			$error_events=is_array($errors['events'] ?? null) ? $errors['events'] : [];
			$severe=count(array_filter($error_events, static fn(array $event): bool => in_array((string)($event['severity'] ?? ''), ['fatal', 'error'], true)));
			$add($severe>0 ? 'error' : 'warning', 'PHP emitted diagnostics', $error_count.' PHP warning/error event'.($error_count===1 ? '' : 's').' captured during this request.', 'php');
		}
		if((int)($sql['failed_events'] ?? 0)>0){
			$add('error', 'SQL work failed', (int)$sql['failed_events'].' SQL event'.((int)$sql['failed_events']===1 ? '' : 's').' reported a failed result.', 'sql');
		}
		if((int)($sql['slow_events'] ?? 0)>0){
			$add('warning', 'Slow SQL detected', (int)$sql['slow_events'].' SQL execution'.((int)$sql['slow_events']===1 ? '' : 's').' crossed the 50ms threshold.', 'sql');
		}
		$duplicate_groups=is_array($sql['duplicates'] ?? null) ? $sql['duplicates'] : [];
		if($duplicate_groups!==[]){
			$extra=array_sum(array_map(static fn(array $group): int => max(0, (int)($group['count'] ?? 0) - 1), $duplicate_groups));
			$add('warning', 'Repeated query shapes', count($duplicate_groups).' repeated SQL shape'.(count($duplicate_groups)===1 ? '' : 's').' produced '.$extra.' extra execution'.($extra===1 ? '' : 's').'.', 'sql');
		}
		$query_events=(int)($sql['query_events'] ?? 0);
		if($query_events>=100){
			$add('error', 'Very high query count', $query_events.' SQL events were captured in one request.', 'sql');
		}
		elseif($query_events>=50){
			$add('warning', 'High query count', $query_events.' SQL events were captured in one request.', 'sql');
		}
		if(empty($routing['matched_route']) && $status<400 && !str_starts_with((string)($request['path'] ?? ''), '/dataphyre')){
			$add('warning', 'No matched route recorded', 'Routing did not expose a matched route even though the response is not an error.', 'routing');
		}
		if(!empty($response['available'])){
			if(!empty($response['is_json'])){
				if(empty($response['json_valid'])){
					$add('error', 'JSON response is invalid', 'The response looked like JSON but could not be decoded: '.(string)($response['json_error'] ?? 'unknown parse error').'.', 'response');
				}
				elseif((int)($response['json_failure_count'] ?? 0)>0){
					$markers=is_array($response['json_failure_markers'] ?? null) ? $response['json_failure_markers'] : [];
					$first=is_array($markers[0] ?? null) ? $markers[0] : [];
					$detail=(string)($first['path'] ?? '$').' = '.self::shorten((string)($first['value'] ?? ''), 120);
					$add('error', 'API response reports failure', $detail, 'response');
				}
			}
			if((int)($response['mojibake_count'] ?? 0)>0){
				$add('warning', 'Mojibake-like text detected', (int)$response['mojibake_count'].' suspicious encoding sequence'.((int)$response['mojibake_count']===1 ? '' : 's').' found in the response body.', 'response');
			}
			$suspicious_phrases=is_array($response['suspicious_phrases'] ?? null) ? $response['suspicious_phrases'] : [];
			if($suspicious_phrases!==[]){
				$level=in_array('Something broke on our end', $suspicious_phrases, true) || in_array('Fatal error', $suspicious_phrases, true) || in_array('Service unavailability', $suspicious_phrases, true) ? 'error' : 'warning';
				$add($level, 'Error copy found in response', 'Matched: '.implode(', ', array_slice($suspicious_phrases, 0, 4)).'.', 'response');
			}
			if((int)($response['body_tag_count'] ?? 0)>1 || (int)($response['html_tag_count'] ?? 0)>1){
				$add('warning', 'Duplicate document shells', 'The response contains '.(int)($response['html_tag_count'] ?? 0).' <html> tags and '.(int)($response['body_tag_count'] ?? 0).' <body> tags.', 'response');
			}
			if((bool)($response['is_html'] ?? false)===true && trim((string)($response['charset'] ?? ''))===''){
				$add('warning', 'HTML charset is not declared', 'No charset was found in Content-Type or a meta charset tag.', 'response');
			}
			$suspicious_assets=is_array($response['suspicious_assets'] ?? null) ? $response['suspicious_assets'] : [];
			if($suspicious_assets!==[]){
				$add('warning', 'Suspicious asset URLs', count($suspicious_assets).' asset URL'.(count($suspicious_assets)===1 ? '' : 's').' look likely to fail or route incorrectly.', 'response');
			}
			if((int)($response['missing_asset_count'] ?? 0)>0){
				$add('warning', 'Local assets may fall through routing', (int)$response['missing_asset_count'].' same-origin asset URL'.((int)$response['missing_asset_count']===1 ? '' : 's').' did not resolve to a local file candidate.', 'response');
			}
			$duplicate_ids=is_array($response['duplicate_ids'] ?? null) ? $response['duplicate_ids'] : [];
			if($duplicate_ids!==[]){
				$add('warning', 'Duplicate HTML ids', count($duplicate_ids).' duplicate id value'.(count($duplicate_ids)===1 ? '' : 's').' found in the response.', 'response');
			}
		}
		$memory_limit=self::parse_ini_bytes((string)($runtime['memory_limit'] ?? ''));
		if($memory_limit>0){
			$peak=memory_get_peak_usage(true);
			$ratio=$peak / $memory_limit;
			if($ratio>=0.85){
				$add('error', 'Memory pressure is high', 'Peak memory reached '.self::format_bytes($peak).' of '.self::format_bytes($memory_limit).'.', 'runtime');
			}
			elseif($ratio>=0.65){
				$add('warning', 'Memory pressure is rising', 'Peak memory reached '.self::format_bytes($peak).' of '.self::format_bytes($memory_limit).'.', 'runtime');
			}
		}
		if((int)($timeline['event_count'] ?? 0)>120){
			$add('info', 'Timeline was trimmed', 'Only the first 120 timeline events are retained in the toolbar state.', 'timeline');
		}
		if((int)($trace['retroactive_count'] ?? 0)>0){
			$add('info', 'Retroactive trace entries exist', (int)$trace['retroactive_count'].' pre-module trace entr'.((int)$trace['retroactive_count']===1 ? 'y was' : 'ies were').' captured.', 'tracelog');
		}
		foreach(is_array($panel['insights'] ?? null) ? $panel['insights'] : [] as $insight){
			if(!is_array($insight)){
				continue;
			}
			$add(
				(string)($insight['level'] ?? 'info'),
				(string)($insight['title'] ?? 'Panel insight'),
				(string)($insight['detail'] ?? ''),
				(string)($insight['source'] ?? 'panel')
			);
		}
		foreach(is_array($reactor['insights'] ?? null) ? $reactor['insights'] : [] as $insight){
			if(!is_array($insight)){
				continue;
			}
			$add(
				(string)($insight['level'] ?? 'info'),
				(string)($insight['title'] ?? 'Reactor insight'),
				(string)($insight['detail'] ?? ''),
				(string)($insight['source'] ?? 'reactor')
			);
		}
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
	 * Builds a unified request timeline from SQL, Panel, Reactor, and runtime evidence.
	 *
	 * @param float $started Request start timestamp.
	 * @param float $duration Request duration in milliseconds.
	 * @param array<string,mixed> $sql SQL debugbar state.
	 * @param array<string,mixed> $routing Routing debugbar state.
	 * @param array<string,mixed> $request Request debugbar state.
	 * @param array<string,mixed> $runtime Runtime debugbar state.
	 * @param array<string,mixed> $panel Panel debugbar state.
	 * @param array<string,mixed> $reactor Reactor debugbar state.
	 * @return array{duration_ms:float,events:list<array<string,mixed>>,event_count:int} Timeline payload with ordered event rows.
	 */
	private static function timeline_state(float $started, float $duration, array $sql, array $routing, array $request, array $runtime, array $panel=[], array $reactor=[]): array {
		$events=[];
		$add=static function(float $offset_ms, string $type, string $label, string $detail='', string $tone='', float $duration_ms=0.0, array $extra=[])use(&$events): void{
			$offset_ms=max(0.0, $offset_ms);
			$duration_ms=max(0.0, $duration_ms);
			$end_offset_ms=$duration_ms>0 ? $offset_ms + $duration_ms : $offset_ms;
			$events[]=[
				'offset_ms'=>round($offset_ms, 3),
				'start_offset_ms'=>round($offset_ms, 3),
				'end_offset_ms'=>round(max($offset_ms, $end_offset_ms), 3),
				'type'=>$type,
				'label'=>$label,
				'detail'=>$detail,
				'tone'=>$tone,
				'duration_ms'=>round($duration_ms, 3),
			]+$extra;
		};
		$add(0.0, 'request', 'Request started', (string)($request['method'] ?? 'GET').' '.(string)($request['path'] ?? '/'));
		if(is_numeric($routing['matched_at'] ?? null)){
			$add(((float)$routing['matched_at'] - $started) * 1000, 'routing', 'Route matched', (string)($routing['matched_route'] ?? ''), '');
		}
		elseif(!empty($routing['matched_route'])){
			$add(0.0, 'routing', 'Route matched', (string)$routing['matched_route']);
		}
		$sql_events=is_array($sql['events'] ?? null) ? $sql['events'] : [];
		foreach(self::sql_queue_wait_events($sql_events, $started, $duration) as $queue_event){
			$add(
				(float)($queue_event['offset_ms'] ?? 0),
				'sql-queue',
				(string)($queue_event['label'] ?? 'Queued SQL waiting'),
				(string)($queue_event['detail'] ?? ''),
				(string)($queue_event['tone'] ?? ''),
				(float)($queue_event['duration_ms'] ?? 0),
				['range_kind'=>'queued-wait']
			);
		}
		foreach($sql_events as $event){
			if(!is_array($event)){
				continue;
			}
			$event_name=(string)($event['event'] ?? '');
			$operation=(string)($event['operation'] ?? '');
			if($event_name==='' || $operation===''){
				continue;
			}
			if(!in_array($operation, ['query', 'select', 'count', 'insert', 'update', 'delete', 'upsert', 'queue_execute', 'read', 'write'], true) && !str_starts_with($event_name, 'cache_')){
				continue;
			}
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$event_duration=(float)($context['duration_ms'] ?? 0);
			$tone=($event['result_ok'] ?? null)===false ? 'bad' : ($event_duration>=50.0 ? 'warn' : '');
			$statement=(string)($context['statement'] ?? $context['query'] ?? '');
			$detail=$statement!=='' ? $statement : trim((string)($event['location'] ?? '').' '.(string)($event['reason'] ?? ''));
			$offsets=self::timeline_event_range($event, $started, $duration);
			if($event_name==='queue_push'){
				$detail=trim((string)($event['location'] ?? '').' queued for '.(string)($event['queue'] ?? 'end'));
			}
			elseif($operation==='queue_execute'){
				$detail=trim('queue '.(string)($event['queue'] ?? 'end').' '.(string)($context['result_count'] ?? ''));
			}
			$add(
				(float)$offsets['start_ms'],
				str_starts_with($event_name, 'cache_') ? 'cache' : ($operation==='queue_execute' ? 'sql-queue' : 'sql'),
				trim($operation.' '.$event_name),
				self::shorten($detail, 180),
				$tone,
				(float)$offsets['duration_ms'],
				[
					'range_kind'=>$operation==='queue_execute' ? 'queue-flush' : ($event_duration>0 ? 'execution' : 'marker'),
					'queue'=>(string)($event['queue'] ?? ''),
				]
			);
		}
		foreach(is_array($panel['events'] ?? null) ? $panel['events'] : [] as $event){
			if(!is_array($event)){
				continue;
			}
			$time=is_numeric($event['time'] ?? null) ? (float)$event['time'] : 0.0;
			if($time<=0){
				continue;
			}
			$offset=max(0.0, ($time - $started) * 1000);
			$event_duration=is_numeric($event['duration_ms'] ?? null) ? (float)$event['duration_ms'] : 0.0;
			$label=(string)($event['event'] ?? 'Panel event');
			$detail=trim((string)($event['resource'] ?? '').' '.(string)($event['operation'] ?? ''));
			$tone=str_contains($label, 'failed') || str_contains($label, 'error') ? 'bad' : ($event_duration>=100.0 ? 'warn' : '');
			$add(
				$offset,
				'panel',
				'Panel '.$label,
				self::shorten($detail, 180),
				$tone,
				$event_duration,
				['range_kind'=>$event_duration>0 ? 'panel-span' : 'marker']
			);
		}
		foreach(is_array($reactor['events'] ?? null) ? $reactor['events'] : [] as $event){
			if(!is_array($event)){
				continue;
			}
			$time=is_numeric($event['time'] ?? null) ? (float)$event['time'] : 0.0;
			if($time<=0){
				continue;
			}
			$offset=max(0.0, ($time - $started) * 1000);
			$event_duration=is_numeric($event['duration_ms'] ?? null) ? (float)$event['duration_ms'] : 0.0;
			$label=(string)($event['event'] ?? 'Reactor event');
			$detail=trim((string)($event['component'] ?? '').' '.(string)($event['action'] ?? ''));
			$tone=str_contains($label, 'failed') || str_contains($label, 'invalid') || str_contains($label, 'denied') || str_contains($label, 'missing') ? 'warn' : ($event_duration>=100.0 ? 'warn' : '');
			$add(
				$offset,
				'reactor',
				'Reactor '.$label,
				self::shorten($detail, 180),
				$tone,
				$event_duration,
				['range_kind'=>$event_duration>0 ? 'reactor-span' : 'marker']
			);
		}
		$add($duration, 'request', 'Response ready', (string)($request['status'] ?? 200).' / '.(string)($runtime['files_count'] ?? 0).' files');
		usort($events, static function(array $a, array $b): int {
			$start=((float)($a['start_offset_ms'] ?? $a['offset_ms'] ?? 0))<=>((float)($b['start_offset_ms'] ?? $b['offset_ms'] ?? 0));
			return $start!==0 ? $start : ((float)($b['duration_ms'] ?? 0))<=>((float)($a['duration_ms'] ?? 0));
		});
		return [
			'duration_ms'=>round($duration, 3),
			'events'=>array_slice($events, 0, 120),
			'event_count'=>count($events),
		];
	}

	/**
	 * Calculates start, end, and duration offsets for a trace event.
	 *
	 * @param array<string,mixed> $event Raw trace event.
	 * @param float $started Request start timestamp.
	 * @param float $request_duration_ms Total request duration in milliseconds.
	 * @return array{start_ms:float,end_ms:float,duration_ms:float} Timeline range.
	 */
	private static function timeline_event_range(array $event, float $started, float $request_duration_ms): array {
		$context=is_array($event['context'] ?? null) ? $event['context'] : [];
		$duration_ms=max(0.0, (float)($context['duration_ms'] ?? 0));
		$timestamp=is_numeric($event['timestamp'] ?? null) ? (float)$event['timestamp'] : ($started + ($request_duration_ms / 1000));
		$event_name=(string)($event['event'] ?? '');
		if($duration_ms>0 && in_array($event_name, ['execute', 'queue_execute_end'], true)){
			$end_ms=max(0.0, ($timestamp - $started) * 1000);
			$start_ms=max(0.0, $end_ms - $duration_ms);
			return [
				'start_ms'=>round($start_ms, 3),
				'end_ms'=>round($end_ms, 3),
				'duration_ms'=>round(max(0.0, $end_ms - $start_ms), 3),
			];
		}
		$start_ms=max(0.0, ($timestamp - $started) * 1000);
		return [
			'start_ms'=>round($start_ms, 3),
			'end_ms'=>round($start_ms + $duration_ms, 3),
			'duration_ms'=>round($duration_ms, 3),
		];
	}

	/**
	 * Derives queue-wait timeline events from SQL queue push and execute events.
	 *
	 * @param list<array<string,mixed>> $events Raw SQL trace events.
	 * @param float $started Request start timestamp.
	 * @param float $request_duration_ms Total request duration in milliseconds.
	 * @return list<array{offset_ms:float,duration_ms:float,queue:string,wait_ms:float}> Queue wait timeline rows.
	 */
	private static function sql_queue_wait_events(array $events, float $started, float $request_duration_ms): array {
		$starts=[];
		$ends=[];
		foreach($events as $event){
			if(!is_array($event) || (string)($event['operation'] ?? '')!=='queue_execute' || !is_numeric($event['timestamp'] ?? null)){
				continue;
			}
			$queue=(string)($event['queue'] ?? 'end');
			if((string)($event['event'] ?? '')==='queue_execute_start'){
				$starts[$queue][]= (float)$event['timestamp'];
				continue;
			}
			if((string)($event['event'] ?? '')==='queue_execute_end'){
				$context=is_array($event['context'] ?? null) ? $event['context'] : [];
				$duration_ms=max(0.0, (float)($context['duration_ms'] ?? 0));
				$end=(float)$event['timestamp'];
				$ends[$queue][]=[
					'start'=>$end - ($duration_ms / 1000),
					'end'=>$end,
				];
			}
		}
		$queue_start_for=static function(string $queue, float $after)use($starts, $ends, $started, $request_duration_ms): float{
			$candidates=[];
			foreach($starts[$queue] ?? [] as $candidate){
				if($candidate>=$after){
					$candidates[]=$candidate;
				}
			}
			foreach($ends[$queue] ?? [] as $window){
				$candidate=(float)($window['start'] ?? 0);
				if($candidate>=$after){
					$candidates[]=$candidate;
				}
			}
			if($candidates!==[]){
				sort($candidates, SORT_NUMERIC);
				return (float)$candidates[0];
			}
			return $started + ($request_duration_ms / 1000);
		};
		$waits=[];
		foreach($events as $event){
			if(!is_array($event) || (string)($event['event'] ?? '')!=='queue_push' || !is_numeric($event['timestamp'] ?? null)){
				continue;
			}
			$queued_at=(float)$event['timestamp'];
			$queue=(string)($event['queue'] ?? 'end');
			$flush_at=$queue_start_for($queue, $queued_at);
			$start_ms=max(0.0, ($queued_at - $started) * 1000);
			$duration_ms=max(0.0, ($flush_at - $queued_at) * 1000);
			if($duration_ms<=0.5){
				continue;
			}
			$operation=(string)($event['operation'] ?? 'query');
			$location=(string)($event['location'] ?? '');
			$waits[]=[
				'offset_ms'=>round($start_ms, 3),
				'duration_ms'=>round($duration_ms, 3),
				'label'=>'Queued SQL waiting',
				'detail'=>self::shorten(trim($operation.' '.$location.' in queue '.$queue), 180),
				'tone'=>$duration_ms>=1000 ? 'warn' : '',
			];
		}
		return $waits;
	}

	/**
	 * Captures the current routing snapshot or a request-path fallback.
	 *
	 * @return array<string,mixed> Routing snapshot from the routing module, or request path/method fallback metadata.
	 */
	private static function routing_state(): array {
		if(class_exists('dataphyre\\routing', false) && method_exists('dataphyre\\routing', 'debug_snapshot')){
			try{
				$snapshot=\dataphyre\routing::debug_snapshot();
				if(is_array($snapshot)){
					return $snapshot;
				}
			}catch(\Throwable){
			}
		}
		return [
			'request_path'=>(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
			'method'=>(string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
		];
	}

	/**
	 * Captures sanitized request, cookie, session, and response-header state.
	 *
	 * @return array<string,mixed> Sanitized request metadata with redacted cookies, bounded session preview, and response headers.
	 */
	private static function request_state(): array {
		$headers=self::request_headers();
		$scheme=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') ? 'https' : 'http';
		$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
		$query=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?: '');
		$cookies=[];
		foreach(array_keys($_COOKIE ?? []) as $cookie_name){
			$cookies[(string)$cookie_name]='[redacted]';
		}
		$session=[];
		if(isset($_SESSION) && is_array($_SESSION)){
			$count=0;
			foreach($_SESSION as $key=>$value){
				if($count>=30){
					$session['...']='truncated';
					break;
				}
				$session[(string)$key]=self::sanitize_value($value, (string)$key);
				$count++;
			}
		}
		return [
			'status'=>http_response_code() ?: 200,
			'method'=>(string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			'scheme'=>$scheme,
			'host'=>(string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
			'path'=>$path,
			'query'=>$query,
			'client_ip'=>(string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
			'user_agent'=>(string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
			'ajax'=>strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))==='xmlhttprequest',
			'do_not_track'=>(string)($_SERVER['HTTP_DNT'] ?? $_SERVER['HTTP_SEC_GPC'] ?? '')!=='',
			'headers'=>self::sanitize_context($headers),
			'query_params'=>self::sanitize_context($_GET ?? []),
			'body_params'=>self::sanitize_context($_POST ?? []),
			'cookies'=>$cookies,
			'session'=>$session,
			'response_headers'=>headers_list(),
		];
	}

	/**
	 * Inspects the response buffer and headers for content diagnostics.
	 *
	 * The response state classifies JSON, HTML, binary, or text bodies, extracts
	 * safe previews, detects suspicious payload markers, and checks referenced
	 * assets when an HTML body is available.
	 *
	 * @param ?string $buffer Response body buffer, when captured.
	 * @return array<string,mixed> Response metadata, content classification, safe preview, asset diagnostics, and suspicious-marker findings.
	 */
	private static function response_state(?string $buffer): array {
		$headers=headers_list();
		$content_type='';
		foreach($headers as $header){
			if(stripos((string)$header, 'Content-Type:')===0){
				$content_type=trim(substr((string)$header, strlen('Content-Type:')));
				break;
			}
		}
		$state=[
			'available'=>is_string($buffer),
			'content_type'=>$content_type,
			'headers'=>$headers,
			'bytes'=>is_string($buffer) ? strlen($buffer) : 0,
			'chars'=>is_string($buffer) ? (function_exists('mb_strlen') ? mb_strlen($buffer, '8bit') : strlen($buffer)) : 0,
			'body_kind'=>'unknown',
			'is_html'=>false,
			'is_json'=>false,
			'json_valid'=>false,
			'json_top_level'=>'',
			'json_key_count'=>0,
			'json_keys'=>[],
			'json_item_count'=>0,
			'json_route_count'=>0,
			'json_batch_route_count'=>0,
			'json_batch_routes'=>[],
			'json_error'=>'',
			'json_preview'=>null,
			'json_failure_count'=>0,
			'json_failure_markers'=>[],
			'title'=>'',
			'charset'=>'',
			'has_doctype'=>false,
			'html_tag_count'=>0,
			'body_tag_count'=>0,
			'script_count'=>0,
			'stylesheet_count'=>0,
			'image_count'=>0,
			'form_count'=>0,
			'asset_count'=>0,
			'resolved_asset_count'=>0,
			'missing_asset_count'=>0,
			'remote_asset_count'=>0,
			'assets'=>[],
			'suspicious_assets'=>[],
			'duplicate_ids'=>[],
			'mojibake_count'=>0,
			'suspicious_phrases'=>[],
		];
		if(!is_string($buffer) || $buffer===''){
			return $state;
		}
		$lower=strtolower($buffer);
		$trimmed=ltrim($buffer);
		$declared_json=stripos($content_type, 'json')!==false;
		$looks_json=str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
		$state['is_html']=str_contains($lower, '<html') || str_contains($lower, '<!doctype') || str_contains($lower, '</body>');
		$state['is_json']=$declared_json || $looks_json;
		$state['body_kind']=$state['is_json'] ? 'json' : ($state['is_html'] ? 'html' : (self::looks_binary($buffer) ? 'binary' : 'text'));
		if($state['is_json']===true){
			$decoded=json_decode($buffer, true);
			$state['json_valid']=json_last_error()===JSON_ERROR_NONE;
			if($state['json_valid']===true){
				$state['json_top_level']=is_array($decoded) ? (self::is_assoc_array($decoded) ? 'object' : 'array') : get_debug_type($decoded);
				if(is_array($decoded)){
					$keys=array_keys($decoded);
					$state['json_item_count']=count($decoded);
					$state['json_keys']=array_slice(array_map('strval', $keys), 0, 24);
					$state['json_key_count']=self::is_assoc_array($decoded) ? count($keys) : 0;
					$state['json_route_count']=count(array_filter($keys, static fn(mixed $key): bool => is_string($key) && str_contains($key, '/')));
					$state['json_batch_routes']=self::json_batch_routes($decoded);
					$state['json_batch_route_count']=count($state['json_batch_routes']);
				}
				$state['json_preview']=self::json_preview($decoded);
				$state['json_failure_markers']=self::json_failure_markers($decoded);
				$state['json_failure_count']=count($state['json_failure_markers']);
			}
			else
			{
				$state['json_error']=json_last_error_msg();
			}
		}
		$state['has_doctype']=str_contains($lower, '<!doctype');
		$state['html_tag_count']=(int)preg_match_all('/<html\b/i', $buffer);
		$state['body_tag_count']=(int)preg_match_all('/<body\b/i', $buffer);
		$state['script_count']=(int)preg_match_all('/<script\b/i', $buffer);
		$state['stylesheet_count']=(int)preg_match_all('/<link\b[^>]*rel=["\']?stylesheet/i', $buffer);
		$state['image_count']=(int)preg_match_all('/<img\b/i', $buffer);
		$state['form_count']=(int)preg_match_all('/<form\b/i', $buffer);
		if(preg_match('/<title[^>]*>(.*?)<\/title>/is', $buffer, $match)===1){
			$state['title']=self::shorten(trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 180);
		}
		if(preg_match('/<meta\b[^>]*charset=["\']?\s*([^"\'\s>]+)/i', $buffer, $match)===1){
			$state['charset']=trim($match[1]);
		}
		elseif(preg_match('/charset=([^;\s]+)/i', $content_type, $match)===1){
			$state['charset']=trim($match[1]);
		}
		$assets=self::response_assets($buffer);
		$state['assets']=array_slice($assets, 0, 80);
		$state['asset_count']=count($assets);
		$state['resolved_asset_count']=count(array_filter($assets, static fn(array $asset): bool => ($asset['status'] ?? '')==='found'));
		$state['missing_asset_count']=count(array_filter($assets, static fn(array $asset): bool => ($asset['status'] ?? '')==='missing'));
		$state['remote_asset_count']=count(array_filter($assets, static fn(array $asset): bool => ($asset['status'] ?? '')==='remote'));
		$state['suspicious_assets']=array_values(array_filter($assets, static fn(array $asset): bool => ($asset['issue'] ?? '')!==''));
		$state['duplicate_ids']=self::duplicate_html_ids($buffer);
		$state['mojibake_count']=self::mojibake_count($buffer);
		$phrases=[
			'Something broke on our end',
			'Failed opening required',
			'Call to undefined function',
			'Fatal error',
			'Parse error',
			'Warning:',
			'Notice:',
			'Strict Standards',
			'Deprecated:',
			'Service unavailability',
		];
		foreach($phrases as $phrase){
			if(stripos($buffer, $phrase)!==false){
				$state['suspicious_phrases'][]=$phrase;
			}
		}
		return $state;
	}

	/**
	 * Determines whether the debugbar toolbar may be injected into a response.
	 *
	 * @param array<string,mixed> $response Response debugbar payload.
	 * @param string $buffer Response body buffer.
	 * @return bool True when the body is HTML-like and not JSON, binary, CSS, media, or script output.
	 */
	private static function response_allows_toolbar_markup(array $response, string $buffer): bool {
		if($buffer===''){
			return false;
		}
		if(!empty($response['is_json']) || in_array((string)($response['body_kind'] ?? ''), ['json', 'binary'], true)){
			return false;
		}
		$content_type=strtolower((string)($response['content_type'] ?? ''));
		if($content_type!==''){
			if(str_contains($content_type, 'text/html') || str_contains($content_type, 'application/xhtml+xml')){
				return true;
			}
			if(
				str_contains($content_type, 'json')
				|| str_contains($content_type, 'javascript')
				|| str_contains($content_type, 'text/css')
				|| str_starts_with($content_type, 'image/')
				|| str_starts_with($content_type, 'font/')
				|| str_starts_with($content_type, 'audio/')
				|| str_starts_with($content_type, 'video/')
			){
				return false;
			}
		}
		return !empty($response['is_html'])
			|| stripos($buffer, '</body>')!==false
			|| stripos($buffer, '<!doctype')!==false
			|| stripos($buffer, '<html')!==false;
	}

	/**
	 * Detects null-byte binary content in a response sample.
	 *
	 * @param string $buffer Response body buffer.
	 * @return bool True when the first sample contains binary null bytes.
	 */
	private static function looks_binary(string $buffer): bool {
		if($buffer===''){
			return false;
		}
		$sample=substr($buffer, 0, 1024);
		return str_contains($sample, "\0");
	}

	/**
	 * Builds a bounded, redacted preview of decoded JSON content.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @param int $depth Current recursion depth.
	 * @param string $key Current object key used for sensitive-value redaction.
	 * @return mixed redacted scalar, bounded array preview, truncation marker, or debug-type label safe for debugbar snapshots.
	 */
	private static function json_preview(mixed $value, int $depth=0, string $key=''): mixed {
		if($depth>4){
			return '[depth-limit]';
		}
		if($key!=='' && self::is_sensitive_key($key)===true){
			return '[redacted]';
		}
		if(is_array($value)){
			$result=[];
			$count=0;
			foreach($value as $key=>$entry){
				if($count>=24){
					$result['...']='truncated';
					break;
				}
				$result[$key]=self::json_preview($entry, $depth+1, (string)$key);
				$count++;
			}
			return $result;
		}
		if(is_string($value)){
			return self::shorten($value, 420);
		}
		if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return $value;
		}
		return get_debug_type($value);
	}

	/**
	 * Summarizes route-keyed JSON batch responses.
	 *
	 * @param array<string,mixed> $decoded Decoded top-level JSON object.
	 * @return list<array{route:string,total:int,success:int,failed:int,unknown:int,keys:list<string>}> Batch route summaries.
	 */
	private static function json_batch_routes(array $decoded): array {
		if(self::is_assoc_array($decoded)!==true){
			return [];
		}
		$routes=[];
		foreach($decoded as $route=>$payload){
			if(!is_string($route) || str_contains($route, '/')!==true){
				continue;
			}
			$entries=is_array($payload) && self::is_assoc_array($payload)!==true ? $payload : [$payload];
			$success=0;
			$failed=0;
			$unknown=0;
			$entry_keys=[];
			foreach($entries as $entry){
				$status=self::json_batch_entry_status($entry);
				if($status==='success'){
					$success++;
				}
				elseif($status==='failed'){
					$failed++;
				}
				else
				{
					$unknown++;
				}
				if(is_array($entry)){
					foreach(array_keys($entry) as $entry_key){
						$entry_key=(string)$entry_key;
						if(!in_array($entry_key, $entry_keys, true)){
							$entry_keys[]=$entry_key;
						}
						if(count($entry_keys)>=12){
							break;
						}
					}
				}
			}
			$markers=self::json_failure_markers($payload, '$["'.$route.'"]');
			$routes[]=[
				'route'=>$route,
				'entries'=>count($entries),
				'success'=>$success,
				'failed'=>$failed,
				'unknown'=>$unknown,
				'status'=>$failed>0 ? 'failed' : ($success>0 && $unknown===0 ? 'success' : 'mixed'),
				'keys'=>$entry_keys,
				'failure_markers'=>array_slice($markers, 0, 8),
				'preview'=>self::json_preview(array_slice($entries, 0, 3)),
			];
			if(count($routes)>=32){
				break;
			}
		}
		return $routes;
	}

	/**
	 * Classifies one JSON batch entry as success, failed, or unknown.
	 *
	 * @param mixed $entry Decoded batch entry.
	 * @return string Entry status label.
	 */
	private static function json_batch_entry_status(mixed $entry): string {
		if(!is_array($entry)){
			return 'unknown';
		}
		if(array_key_exists('success', $entry) && $entry['success']!==false){
			return 'success';
		}
		foreach(['failed', 'failure', 'error', 'errors', 'exception'] as $key){
			if(array_key_exists($key, $entry) && self::json_failure_value_is_significant($entry[$key])===true){
				return 'failed';
			}
		}
		if(array_key_exists('success', $entry) && $entry['success']===false){
			return 'failed';
		}
		return 'unknown';
	}

	/**
	 * Finds failure-like markers inside decoded JSON content.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @param string $path Current JSON path label.
	 * @param int $depth Current recursion depth.
	 * @param int $visited Mutable visited-node counter.
	 * @return array Failure marker rows with path and value fields.
	 */
	private static function json_failure_markers(mixed $value, string $path='$', int $depth=0, int &$visited=0): array {
		if($depth>7 || $visited>220){
			return [];
		}
		$visited++;
		if(!is_array($value)){
			return [];
		}
		$markers=[];
		foreach($value as $key=>$entry){
			if(count($markers)>=32){
				break;
			}
			$key_label=is_int($key) ? '['.$key.']' : (string)$key;
			$entry_path=is_int($key) ? $path.$key_label : $path.'.'.$key_label;
			$normalized=strtolower((string)$key);
			if(self::json_value_reports_failure($normalized, $entry)===true){
				$markers[]=[
					'path'=>$entry_path,
					'value'=>self::json_failure_value_label($entry),
				];
			}
			if(is_array($entry)){
				foreach(self::json_failure_markers($entry, $entry_path, $depth+1, $visited) as $nested){
					$markers[]=$nested;
					if(count($markers)>=32){
						break 2;
					}
				}
			}
		}
		return $markers;
	}

	/**
	 * Determines whether a JSON key/value pair represents a failure marker.
	 *
	 * @param string $key Lowercase JSON key.
	 * @param mixed $value Decoded JSON value.
	 * @return bool True when the key and value indicate failure.
	 */
	private static function json_value_reports_failure(string $key, mixed $value): bool {
		if(in_array($key, ['failed', 'failure', 'error', 'exception', 'error_code'], true)){
			return self::json_failure_value_is_significant($value);
		}
		if($key==='errors' && self::json_failure_value_is_significant($value)===true){
			return true;
		}
		if($key==='success' && $value===false){
			return true;
		}
		if(in_array($key, ['status', 'state'], true) && is_string($value)){
			return in_array(strtolower($value), ['failed', 'failure', 'error', 'errored', 'exception'], true);
		}
		return false;
	}

	/**
	 * Determines whether a JSON failure value is meaningful enough to report.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return bool True when the value is not an empty false-like marker.
	 */
	private static function json_failure_value_is_significant(mixed $value): bool {
		if($value===null || $value===false || $value==='' || $value===[]){
			return false;
		}
		if(is_array($value)){
			return count($value)>0;
		}
		return true;
	}

	/**
	 * Converts a JSON failure value into a compact display label.
	 *
	 * @param mixed $value Decoded JSON failure value.
	 * @return string Human-readable failure label.
	 */
	private static function json_failure_value_label(mixed $value): string {
		if(is_string($value)){
			return $value;
		}
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(is_int($value) || is_float($value)){
			return (string)$value;
		}
		if(is_array($value)){
			return self::shorten(self::json(self::json_preview($value, 0)), 420);
		}
		if($value===null){
			return 'null';
		}
		return get_debug_type($value);
	}

	/**
	 * Reports whether an array should be treated as a JSON object.
	 *
	 * @param array<int|string,mixed> $value Array to inspect.
	 * @return bool True when keys are not a contiguous zero-based list.
	 */
	private static function is_assoc_array(array $value): bool {
		if($value===[]){
			return false;
		}
		return array_keys($value)!==range(0, count($value) - 1);
	}

	/**
	 * Captures templating configuration and SQL binding trace state.
	 *
	 * @return array<string,mixed> Templating debugbar payload.
	 */
	private static function templating_state(): array {
		$state=[];
		if(class_exists('dataphyre\\templating', false) && method_exists('dataphyre\\templating', 'state')){
			try{
				$raw=\dataphyre\templating::state();
				if(is_array($raw)){
					$state=[
						'dev_mode'=>(bool)($raw['is_dev_mode'] ?? false),
						'strict_mode'=>(bool)($raw['strict_mode'] ?? false),
						'cache_dir'=>is_string($raw['cache_dir'] ?? null) ? $raw['cache_dir'] : '',
						'contracts'=>is_array($raw['template_contracts'] ?? null) ? count($raw['template_contracts']) : 0,
					];
				}
			}catch(\Throwable){
			}
		}
		$sql=self::sql_events();
		$bindings=[];
		foreach($sql as $event){
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$binding_trace_id=trim((string)($context['binding_trace_id'] ?? ''));
			if($binding_trace_id===''){
				continue;
			}
			$bindings[$binding_trace_id]=[
				'binding_trace_id'=>$binding_trace_id,
				'render_trace_id'=>(string)($context['render_trace_id'] ?? ''),
				'template_name'=>(string)($context['template_name'] ?? ''),
				'binding_name'=>(string)($context['binding_name'] ?? ''),
				'binding_path'=>(string)($context['binding_path'] ?? ''),
				'query_target'=>(string)($context['query_target'] ?? ''),
				'query_mode'=>(string)($context['query_mode'] ?? ''),
			];
		}
		$render_ids=[];
		foreach($bindings as $binding){
			$render_id=trim((string)($binding['render_trace_id'] ?? ''));
			if($render_id!==''){
				$render_ids[$render_id]=true;
			}
		}
		return array_replace($state, [
			'sql_binding_count'=>count($bindings),
			'render_trace_count'=>count($render_ids),
			'bindings'=>array_values($bindings),
		]);
	}

}
