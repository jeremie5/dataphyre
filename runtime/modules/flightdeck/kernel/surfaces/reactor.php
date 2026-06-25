<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');

if(defined('DATAPHYRE_FLIGHTDECK_REACTOR_SURFACE_LOADED')){
	dataphyre_flightdeck_reactor_surface::dispatch();
	return;
}
define('DATAPHYRE_FLIGHTDECK_REACTOR_SURFACE_LOADED', true);

/**
 * Renders Reactor manifest and lifecycle diagnostics inside Flightdeck.
 *
 * The surface lazily loads the Reactor framework module, summarizes registered
 * components, capability counts, bindings, trace events, and memory snapshots,
 * then exposes a small CSS asset through the Flightdeck asset route.
 */
final class dataphyre_flightdeck_reactor_surface {

	/**
	 * Dispatches the Reactor inspector surface.
	 *
	 * When Reactor cannot be loaded the surface emits a 503 diagnostic page.
	 * Otherwise it renders summary cards, component tables, lifecycle trace rows,
	 * and the raw manifest payload for test and shell integration debugging.
	 *
	 * @return void Emits the Reactor Flightdeck page.
	 */
	public static function dispatch(): void {
		self::load_reactor();
		if(!class_exists('\Dataphyre\Reactor\Reactor')){
			http_response_code(503);
			echo dataphyre_flightdeck_view::layout(
				'Reactor',
				dataphyre_flightdeck_view::card('Reactor', '<p class="fd-muted">The Dataphyre Reactor module is unavailable.</p>'),
				'reactor'
			);
			return;
		}
		$manifest=\Dataphyre\Reactor\Reactor::manifest();
		$trace=class_exists('\Dataphyre\Reactor\ReactorTrace')
			? \Dataphyre\Reactor\ReactorTrace::events()
			: [];
		$content=self::summary_cards($manifest);
		$content.=dataphyre_flightdeck_view::card(
			'Reactive Components',
			self::components_table(is_array($manifest['components'] ?? null) ? $manifest['components'] : []),
			['subtitle'=>'Registered Reactor components visible in this request.']
		);
		$content.=dataphyre_flightdeck_view::card(
			'Lifecycle Trace',
			self::events_table($trace),
			['subtitle'=>'Recent Reactor request, model, validation, action, effect, and response events.']
		);
		$content.=dataphyre_flightdeck_view::card(
			'Manifest Payload',
			dataphyre_flightdeck_view::code(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
			['subtitle'=>'Serializable shape consumed by diagnostics, tests, and shell integrations.']
		);
		echo dataphyre_flightdeck_view::module_page(
			'Reactor',
			'Reactor Inspector',
			'Live component lifecycle, manifest, validation, action, effect, and client-binding diagnostics.',
			$content,
			'reactor',
			['head'=>'<link rel="stylesheet" href="'.self::e(self::asset_url('reactor-surface.css')).'">']
		);
	}

	/**
	 * Builds a cache-versioned Flightdeck asset URL for the Reactor surface.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Public Flightdeck asset URL with a content hash query value.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/flightdeck/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Returns the short content hash used to version a Reactor surface asset.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-1 prefix, or "missing" when the asset is unknown.
	 */
	public static function asset_version(string $asset): string {
		$content=self::asset_content($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Returns the inline Reactor surface asset body and content type.
	 *
	 *
	 * @return ?array{content_type:string,body:string} Asset payload, or null for unknown assets.
	 */
	public static function asset_content(string $asset): ?array {
		return self::asset_name($asset)==='reactor-surface.css'
			? ['content_type'=>'text/css; charset=UTF-8', 'body'=>self::style()]
			: null;
	}

	/**
	 * Sanitizes a Reactor asset request to a safe basename.
	 *
	 * @param string $asset Raw asset path from the asset router.
	 * @return string Safe filename, or an empty string for invalid names.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Loads the Reactor framework bootstrap when it is not already available.
	 *
	 * Core module loading is attempted first so app-configured module paths win.
	 * The framework bootstrap file is required directly as a fallback for
	 * standalone Flightdeck requests.
	 *
	 * @return void
	 */
	private static function load_reactor(): void {
		if(class_exists('\Dataphyre\Reactor\Reactor')){
			return;
		}
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('reactor');
			if(class_exists('\Dataphyre\Reactor\Reactor')){
				return;
			}
		}
		$bootstrap=defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])
			? rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/reactor/Framework/Bootstrap.php'
			: dirname(__DIR__, 3).'/reactor/Framework/Bootstrap.php';
		if(is_file($bootstrap)){
			require_once($bootstrap);
		}
	}

	/**
	 * Renders manifest-level Reactor summary cards and event counts.
	 *
	 * @param array{version?:string,components?:list<array<string,mixed>>,trace?:array{count?:int,active_spans?:list<mixed>,latest?:list<array<string,mixed>>,events?:array<string,int>}}|array<string,mixed> $manifest Reactor manifest payload.
	 * @return string HTML summary and event-count card.
	 */
	private static function summary_cards(array $manifest): string {
		$trace=is_array($manifest['trace'] ?? null) ? $manifest['trace'] : [];
		$components=is_array($manifest['components'] ?? null) ? $manifest['components'] : [];
		$capabilities=[];
		foreach($components as $component){
			foreach(is_array($component['capabilities'] ?? null) ? $component['capabilities'] : [] as $capability){
				$capabilities[$capability]=true;
			}
		}
		$cards='';
		$cards.=self::metric('Version', (string)($manifest['version'] ?? 'unknown'), 'Installed Reactor module version.');
		$cards.=self::metric('Components', (string)count($components), 'Registered reactive components.');
		$cards.=self::metric('Capabilities', (string)count($capabilities), 'Distinct features used by components.');
		$cards.=self::metric('Trace Events', (string)($trace['count'] ?? 0), 'Retained lifecycle events.');
		$cards.=self::metric('Active Spans', (string)count(is_array($trace['active_spans'] ?? null) ? $trace['active_spans'] : []), 'Open Reactor operations.');
		$cards.=self::metric('Latest', self::latest_label(is_array($trace['latest'] ?? null) ? $trace['latest'] : []), 'Most recent lifecycle event.');
		$event_rows=[];
		foreach(is_array($trace['events'] ?? null) ? $trace['events'] : [] as $event=>$count){
			$event_rows[]=[
				'<code>'.self::e((string)$event).'</code>',
				self::e((string)$count),
			];
		}
		return '<section class="fd-metrics">'.$cards.'</section>'
			.dataphyre_flightdeck_view::card('Event Counts', dataphyre_flightdeck_view::table(['Event', 'Count'], $event_rows));
	}

	/**
	 * Renders registered Reactor component capabilities and binding counts.
	 *
	 * @param array<int,array> $components Manifest component records.
	 * @return string HTML table of component diagnostics.
	 */
	private static function components_table(array $components): string {
		$rows=[];
		foreach($components as $component){
			if(!is_array($component)){
				continue;
			}
			$bindings=is_array($component['bindings'] ?? null) ? $component['bindings'] : [];
			$rows[]=[
				'<code>'.self::e((string)($component['name'] ?? '')).'</code>',
				self::list_badges(is_array($component['capabilities'] ?? null) ? $component['capabilities'] : []),
				self::count_label($component['state_keys'] ?? []),
				self::count_label($component['locked'] ?? []),
				self::count_label($component['actions'] ?? []),
				self::count_label($component['computed'] ?? []),
				self::count_label($component['rules'] ?? []),
				self::count_label($component['listeners'] ?? []),
				self::binding_label($bindings),
			];
		}
		return dataphyre_flightdeck_view::table(['Component', 'Capabilities', 'State', 'Locked', 'Actions', 'Computed', 'Rules', 'Listeners', 'Bindings'], $rows);
	}

	/**
	 * Renders recent Reactor trace events in reverse chronological order.
	 *
	 * @param array<int,array> $events Reactor trace event records.
	 * @return string HTML table limited to the latest retained events.
	 */
	private static function events_table(array $events): string {
		$rows=[];
		foreach(array_reverse(array_slice($events, -100)) as $event){
			$time=(float)($event['time'] ?? 0);
			$context=is_array($event['context'] ?? null) ? $event['context'] : [];
			$rows[]=[
				self::e($time>0 ? date('H:i:s', (int)$time).sprintf('.%03d', ((int)($time*1000))%1000) : ''),
				'<code>'.self::e((string)($event['event'] ?? 'event')).'</code>',
				self::context_summary($context),
				self::e(self::format_bytes((int)($event['memory'] ?? 0))),
			];
		}
		return dataphyre_flightdeck_view::table(['When', 'Event', 'Context', 'Memory'], $rows);
	}

	/**
	 * Renders a list of capability names as Flightdeck badges.
	 *
	 * @param array<int,string> $items Capability labels.
	 * @return string Badge HTML or a muted none label.
	 */
	private static function list_badges(array $items): string {
		if($items===[]){
			return '<span class="fd-muted">none</span>';
		}
		$html='';
		foreach($items as $item){
			$html.=dataphyre_flightdeck_view::badge((string)$item, 'info').' ';
		}
		return trim($html);
	}

	/**
	 * Formats a manifest list count.
	 *
	 * @param mixed $value Expected array value from the manifest.
	 * @return string Count label, with non-arrays treated as zero.
	 */
	private static function count_label(mixed $value): string {
		if(!is_array($value)){
			return '0';
		}
		return (string)count($value);
	}

	/**
	 * Renders binding groups and field counts for a component.
	 *
	 * @param array<string,mixed> $bindings Binding groups keyed by binding type.
	 * @return string HTML binding summary.
	 */
	private static function binding_label(array $bindings): string {
		if($bindings===[]){
			return '<span class="fd-muted">none</span>';
		}
		$parts=[];
		foreach($bindings as $type=>$fields){
			$parts[]='<span class="fd-reactor-kv"><b>'.self::e((string)$type).'</b> '.self::e((string)count(is_array($fields) ? $fields : [])).'</span>';
		}
		return '<div class="fd-reactor-context">'.implode('', $parts).'</div>';
	}

	/**
	 * Renders a compact key/value summary for one lifecycle event context.
	 *
	 * @param array<string,mixed> $context Event context payload.
	 * @return string HTML context summary capped to the first eight entries.
	 */
	private static function context_summary(array $context): string {
		if($context===[]){
			return '<span class="fd-muted">none</span>';
		}
		$parts=[];
		foreach($context as $key=>$value){
			$parts[]='<span class="fd-reactor-kv"><b>'.self::e((string)$key).'</b> '.self::e(self::value_label($value)).'</span>';
			if(count($parts)>=8){
				$parts[]='<span class="fd-muted">+'.(count($context)-8).' more</span>';
				break;
			}
		}
		return '<div class="fd-reactor-context">'.implode('', $parts).'</div>';
	}

	/**
	 * Converts scalar and structured event values into compact labels.
	 *
	 * @param mixed $value Event context value.
	 * @return string Scalar value, array count, or debug type label.
	 */
	private static function value_label(mixed $value): string {
		if(is_scalar($value) || $value===null){
			return (string)($value ?? 'null');
		}
		if(is_array($value)){
			return 'array('.count($value).')';
		}
		return get_debug_type($value);
	}

	/**
	 * Extracts the newest lifecycle event name from the manifest trace summary.
	 *
	 * @param array<int,array> $latest Latest trace event records.
	 * @return string Event name, or "none" when no trace exists.
	 */
	private static function latest_label(array $latest): string {
		$last=end($latest);
		return is_array($last) ? (string)($last['event'] ?? 'event') : 'none';
	}

	/**
	 * Renders one Reactor summary metric card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $hint Supporting text.
	 * @return string HTML metric card.
	 */
	private static function metric(string $label, string $value, string $hint): string {
		return '<article class="fd-metric"><span>'.self::e($label).'</span><b>'.self::e($value).'</b><p>'.self::e($hint).'</p></article>';
	}

	/**
	 * Formats memory byte counts for lifecycle trace rows.
	 *
	 * @param int $bytes Memory usage in bytes.
	 * @return string Human-readable byte label.
	 */
	private static function format_bytes(int $bytes): string {
		if($bytes>=1048576){
			return round($bytes/1048576, 2).' MB';
		}
		if($bytes>=1024){
			return round($bytes/1024, 2).' KB';
		}
		return $bytes.' B';
	}

	/**
	 * Returns the Reactor surface CSS.
	 *
	 * @return string Stylesheet body for reactor-surface.css.
	 */
	private static function style(): string {
		return '.fd-reactor-context{display:flex;gap:5px;flex-wrap:wrap}.fd-reactor-kv{display:inline-flex;gap:4px;padding:3px 7px;border-radius:999px;background:#eef8ff;color:#075985}.fd-reactor-kv b{color:#0f172a}';
	}

	/**
	 * Escapes text for Reactor surface HTML fragments.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-safe text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
