<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');

if(defined('DATAPHYRE_FLIGHTDECK_PANEL_SURFACE_LOADED')){
	dataphyre_flightdeck_panel_surface::dispatch();
	return;
}
define('DATAPHYRE_FLIGHTDECK_PANEL_SURFACE_LOADED', true);

/**
 * Renders the Flightdeck diagnostics surface for Dataphyre Panel state.
 *
 * The surface is intentionally route-friendly and self-contained: it loads the Panel framework
 * if needed, gathers retained trace events plus current resource/page metadata, and renders
 * HTML tables through the shared Flightdeck view helpers. It also exposes a small CSS asset for
 * Panel-specific metric and context chips.
 */
final class dataphyre_flightdeck_panel_surface {

	/**
	 * Renders the Panel diagnostics request as a complete Flightdeck page.
	 *
	 * The request loads Panel on demand, reads retained trace and registration
	 * metadata, and writes HTML through shared Flightdeck view helpers. When the
	 * Panel framework cannot be loaded, it emits a 503 page that keeps the shell
	 * usable while making the missing dependency explicit.
	 *
	 * @return void
	 */
	public static function dispatch(): void {
		self::load_panel();
		if(!class_exists('\Dataphyre\Panel\Panel')){
			http_response_code(503);
			echo dataphyre_flightdeck_view::layout(
				'Panel',
				dataphyre_flightdeck_view::card('Panel', '<p class="fd-muted">The Dataphyre Panel framework module is unavailable.</p>'),
				'panel'
			);
			return;
		}

		$summary=\Dataphyre\Panel\Panel::trace_summary();
		$events=\Dataphyre\Panel\Panel::trace();
		$resources=\Dataphyre\Panel\Panel::describe();
		$content=self::summary_cards($summary, $resources);
		$content.=dataphyre_flightdeck_view::card(
			'Panel Lifecycle Trace',
			self::events_table($events),
			['subtitle'=>'Latest retained Panel resource, form, save, action, relation, and render events.']
		);
		$content.=dataphyre_flightdeck_view::card(
			'Registered Resources',
			self::resources_table(is_array($resources['resources'] ?? null) ? $resources['resources'] : []),
			['subtitle'=>'Resource metadata currently registered in this request.']
		);
		$content.=dataphyre_flightdeck_view::card(
			'Registered Pages',
			self::pages_table(is_array($resources['pages'] ?? null) ? $resources['pages'] : []),
			['subtitle'=>'Custom Panel pages currently registered in this request.']
		);

		echo dataphyre_flightdeck_view::module_page(
			'Panel',
			'Panel Resource Inspector',
			'Generated-resource lifecycle, form validation, action, relation, notification, and redirect diagnostics.',
			$content,
			'panel',
			['head'=>'<link rel="stylesheet" href="'.self::e(self::asset_url('panel-surface.css')).'">']
		);
	}

	/**
	 * Builds a versioned URL for a Panel Flightdeck surface asset.
	 *
	 * @param string $asset Requested asset name, sanitized before it is reflected into the URL.
	 * @return string Flightdeck asset route URL with a content-derived version query parameter.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/flightdeck/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Calculates a short cache-busting version for a Panel surface asset.
	 *
	 * @param string $asset Requested asset name.
	 * @return string First 16 SHA-1 characters for known assets, or `missing` for unknown names.
	 */
	public static function asset_version(string $asset): string {
		$content=self::asset_content($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Resolves a Panel surface asset into an HTTP-ready content payload.
	 *
	 * @param string $asset Requested asset name.
	 * @return array{content_type:string, body:string}|null MIME type and body for known assets, otherwise `null`.
	 */
	public static function asset_content(string $asset): ?array {
		return self::asset_name($asset)==='panel-surface.css'
			? ['content_type'=>'text/css; charset=UTF-8', 'body'=>self::style()]
			: null;
	}

	/**
	 * Sanitizes a requested surface asset path down to a supported basename.
	 *
	 * @param string $asset Raw route segment or asset path.
	 * @return string Safe basename containing only alphanumerics, dot, underscore, and dash; empty when invalid.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Ensures the Panel framework classes are available before diagnostics are rendered.
	 *
	 * The loader first asks the Dataphyre core module loader, then falls back to Panel's
	 * bootstrap file so the surface can still work in partially initialized Flightdeck requests.
	 *
	 * @return void
	 */
	private static function load_panel(): void {
		if(class_exists('\Dataphyre\Panel\Panel')){
			return;
		}
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('panel');
			if(class_exists('\Dataphyre\Panel\Panel')){
				return;
			}
		}
		$bootstrap=ROOTPATH['common_dataphyre_runtime'].'modules/panel/Framework/Bootstrap.php';
		if(is_file($bootstrap)){
			require_once($bootstrap);
		}
	}

	/**
	 * Builds metric cards and event-count output from Panel trace summaries.
	 *
	 * @param array<string, mixed> $summary Aggregated trace summary from `Panel::trace_summary()`.
	 * @param array<string, mixed> $resources Resource and page description from `Panel::describe()`.
	 * @return string HTML section containing metrics and event counts.
	 */
	private static function summary_cards(array $summary, array $resources): string {
		$counts=is_array($summary['events'] ?? null) ? $summary['events'] : [];
		$latest=is_array($summary['latest'] ?? null) ? $summary['latest'] : [];
		$resource_count=count(is_array($resources['resources'] ?? null) ? $resources['resources'] : []);
		$page_count=count(is_array($resources['pages'] ?? null) ? $resources['pages'] : []);
		$search_count=count(is_array($resources['global_searchable_resources'] ?? null) ? $resources['global_searchable_resources'] : []);
		$navigation_count=count(is_array($resources['navigation'] ?? null) ? $resources['navigation'] : []);
		$cards='';
		$cards.=self::metric('Trace Events', (string)($summary['count'] ?? 0), 'Retained Panel lifecycle events.');
		$cards.=self::metric('Resources', (string)$resource_count, 'Registered resources in this request.');
		$cards.=self::metric('Pages', (string)$page_count, 'Registered custom Panel pages.');
		$cards.=self::metric('Searchable', (string)$search_count, 'Resources exposed to Panel global search.');
		$cards.=self::metric('Navigation', (string)$navigation_count, 'Visible Panel navigation entries.');
		$cards.=self::metric('Latest', self::latest_label($latest), 'Most recent lifecycle event.');
		$event_rows=[];
		foreach($counts as $event=>$count){
			$event_rows[]=[
				'<code>'.self::e((string)$event).'</code>',
				self::e((string)$count),
			];
		}
		return '<section class="fd-metrics">'.$cards.'</section>'
			.dataphyre_flightdeck_view::card('Event Counts', dataphyre_flightdeck_view::table(['Event', 'Count'], $event_rows));
	}

	/**
	 * Renders the latest retained Panel lifecycle events as a Flightdeck table.
	 *
	 * @param array<int, array<string, mixed>> $events Trace events emitted by Panel runtime hooks.
	 * @return string HTML table with timestamp, event name, summarized context, and memory.
	 */
	private static function events_table(array $events): string {
		$rows=[];
		foreach(array_reverse(array_slice($events, -80)) as $event){
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
	 * Renders registered Panel resources and their operational capabilities.
	 *
	 * @param array<int, mixed> $resources Resource descriptions from `Panel::describe()`.
	 * @return string HTML table summarizing forms, tables, navigation, search, actions, and relations.
	 */
	private static function resources_table(array $resources): string {
		$rows=[];
		foreach($resources as $resource){
			if(!is_array($resource)){
				continue;
			}
			$rows[]=[
				'<code>'.self::e((string)($resource['name'] ?? '')).'</code>',
				self::e((string)($resource['label'] ?? '')),
				self::e((string)($resource['table'] ?? $resource['repository'] ?? $resource['model'] ?? '')),
				self::e((string)count(is_array($resource['form']['fields'] ?? null) ? $resource['form']['fields'] : [])),
				self::e((string)count(is_array($resource['table_schema']['columns'] ?? null) ? $resource['table_schema']['columns'] : [])),
				self::e((string)count(is_array($resource['table_schema']['views'] ?? null) ? $resource['table_schema']['views'] : [])),
				self::e((string)count(is_array($resource['table_schema']['summaries'] ?? null) ? $resource['table_schema']['summaries'] : [])),
				!empty($resource['navigation_badge_lazy']) ? 'lazy' : self::e((string)($resource['navigation_badge'] ?? '')),
				!empty($resource['global_searchable']) ? 'yes' : 'no',
				self::e((string)count(is_array($resource['actions'] ?? null) ? $resource['actions'] : [])),
				self::e((string)count(is_array($resource['relations'] ?? null) ? $resource['relations'] : [])),
			];
		}
		return dataphyre_flightdeck_view::table(['Name', 'Label', 'Source', 'Fields', 'Columns', 'Views', 'Summaries', 'Badge', 'Search', 'Actions', 'Relations'], $rows);
	}

	/**
	 * Renders registered custom Panel pages and their routing/navigation metadata.
	 *
	 * @param array<int, mixed> $pages Page descriptions from `Panel::describe()`.
	 * @return string HTML table summarizing labels, routes, groups, badges, and lifecycle hooks.
	 */
	private static function pages_table(array $pages): string {
		$rows=[];
		foreach($pages as $page){
			if(!is_array($page)){
				continue;
			}
			$rows[]=[
				'<code>'.self::e((string)($page['name'] ?? '')).'</code>',
				self::e((string)($page['label'] ?? '')),
				self::e((string)($page['route'] ?? '')),
				self::e((string)($page['group'] ?? '')),
				self::e((string)($page['icon'] ?? '')),
				!empty($page['navigation_badge_lazy']) ? 'lazy' : self::e((string)($page['navigation_badge'] ?? '')),
				!empty($page['renders']) ? 'yes' : 'no',
				!empty($page['authorizes']) ? 'yes' : 'no',
			];
		}
		return dataphyre_flightdeck_view::table(['Name', 'Label', 'Route', 'Group', 'Icon', 'Badge', 'Renders', 'Authorizes'], $rows);
	}

	/**
	 * Converts trace context data into compact key/value chips for the diagnostics table.
	 *
	 * @param array<string, mixed> $context Raw trace context payload.
	 * @return string HTML fragment safe for inclusion in a Flightdeck table cell.
	 */
	private static function context_summary(array $context): string {
		if($context===[]){
			return '<span class="fd-muted">none</span>';
		}
		$parts=[];
		foreach($context as $key=>$value){
			$parts[]='<span class="fd-panel-kv"><b>'.self::e((string)$key).'</b> '.self::e(self::value_label($value)).'</span>';
			if(count($parts)>=8){
				$parts[]='<span class="fd-muted">+'.(count($context)-8).' more</span>';
				break;
			}
		}
		return '<div class="fd-panel-context">'.implode('', $parts).'</div>';
	}

	/**
	 * Formats a trace context value for compact diagnostic display.
	 *
	 * @param mixed $value Scalar, array, or object value captured with a trace event.
	 * @return string Human-readable label that avoids dumping large nested payloads.
	 */
	private static function value_label(mixed $value): string {
		if(is_scalar($value) || $value===null){
			return (string)($value ?? 'null');
		}
		if(is_array($value)){
			if(isset($value['resource'])){
				return 'resource '.(string)$value['resource'].' / '.(string)($value['operation'] ?? '');
			}
			if(isset($value['valid'])){
				return ((bool)$value['valid'] ? 'valid' : 'invalid').' form';
			}
			if(isset($value['type'], $value['count'])){
				return (string)$value['type'].'('.(string)$value['count'].')';
			}
			return 'array('.count($value).')';
		}
		return get_debug_type($value);
	}

	/**
	 * Extracts the most recent Panel event name from summary metadata.
	 *
	 * @param array<int, array<string, mixed>> $latest Latest-event collection from the trace summary.
	 * @return string Event name, or `none` when no trace event has been retained.
	 */
	private static function latest_label(array $latest): string {
		$last=end($latest);
		return is_array($last) ? (string)($last['event'] ?? 'event') : 'none';
	}

	/**
	 * Renders one dashboard metric tile.
	 *
	 * @param string $label Metric label.
	 * @param string $value Display value.
	 * @param string $hint Short explanatory text for the metric.
	 * @return string Escaped metric-card HTML.
	 */
	private static function metric(string $label, string $value, string $hint): string {
		return '<article class="fd-metric"><span>'.self::e($label).'</span><b>'.self::e($value).'</b><p>'.self::e($hint).'</p></article>';
	}

	/**
	 * Formats a byte count using compact binary units.
	 *
	 * @param int $bytes Raw byte count.
	 * @return string Human-readable byte, KB, or MB value.
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
	 * Returns CSS used only by the Panel Flightdeck surface.
	 *
	 * @return string Stylesheet body for Panel context chips.
	 */
	private static function style(): string {
		return '.fd-panel-context{display:flex;gap:5px;flex-wrap:wrap}.fd-panel-kv{display:inline-flex;gap:4px;padding:3px 7px;border-radius:999px;background:#eef8ff;color:#075985}.fd-panel-kv b{color:#0f172a}';
	}

	/**
	 * Escapes text for safe insertion into Flightdeck HTML.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-escaped text using UTF-8 substitution for invalid sequences.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
