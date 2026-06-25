<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_STACK_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_STACK_TRAIT_LOADED', true);

/**
 * Adds source-stack panels to Flightdeck Debugbar snapshots.
 *
 * The trait turns captured PHP errors, SQL trace context, and PHP backtraces
 * into bounded frame arrays for the shared snippet renderer. It only emits
 * Debugbar HTML/CSS; it does not read source files directly, mutate captured
 * events, or expose snippet links outside the operator debug surface.
 */
trait dataphyre_flightdeck_debugbar_stack {

	/**
	 * Renders a source stack panel for a captured PHP error event.
	 *
	 * When the event carries a file and line, that location is promoted to
	 * an origin frame before the captured stack is normalized. The panel id is a
	 * stable hash of the error location/message/timestamp so repeated errors produce
	 * deterministic snippet anchors inside one snapshot.
	 *
	 * @param array<string, mixed> $event Captured PHP error event with file, line, severity, message, timestamp, and stack fields.
	 * @return string Rendered stack snippet panel, or an empty string when snippets are unavailable.
	 */
	private static function render_error_stack_panel(array $event): string {
		$file=(string)($event['file'] ?? '');
		$line=(int)($event['line'] ?? 0);
		$origin=$file!=='' && $line>0 ? [
			'file'=>$file,
			'line'=>$line,
			'function'=>(string)($event['severity'] ?? 'php_error'),
			'symbol'=>(string)($event['severity'] ?? 'PHP error'),
			'kind'=>'origin',
		] : null;
		$frames=self::frames_from_stack($event['stack'] ?? [], $origin);
		return self::render_stack_snippet_panel(
			$frames,
			'PHP source stack',
			'php-'.substr(hash('sha256', $file.'|'.$line.'|'.(string)($event['message'] ?? '').'|'.(string)($event['timestamp'] ?? '')), 0, 12),
			6
		);
	}

	/**
	 * Renders a source stack panel for a captured SQL event.
	 *
	 * SQL context can provide a caller frame plus a stack trace. The caller
	 * is marked as the origin and named from its call/label or the SQL operation
	 * before the bounded stack snippet panel is rendered.
	 *
	 * @param array<string, mixed> $event Captured SQL event summary.
	 * @param array<string, mixed> $context SQL trace context with caller, stack, and query metadata.
	 * @return string Rendered stack snippet panel, or an empty string when snippets are unavailable.
	 */
	private static function render_sql_stack_panel(array $event, array $context): string {
		$origin=is_array($context['caller'] ?? null) ? $context['caller'] : null;
		if(is_array($origin)){
			$origin['kind']='origin';
			$origin['symbol']=(string)($origin['call'] ?? $origin['label'] ?? $event['operation'] ?? 'SQL callsite');
		}
		$frames=self::frames_from_stack($context['stack'] ?? [], $origin);
		return self::render_stack_snippet_panel(
			$frames,
			'Source stack',
			'sql-'.substr(hash('sha256', (string)($event['location'] ?? '').'|'.(string)($context['statement'] ?? $context['query'] ?? '').'|'.(string)($event['timestamp'] ?? '')), 0, 12),
			5
		);
	}

	/**
	 * Delegates normalized frames to the shared stack-snippet renderer.
	 *
	 * Rendering is skipped when no frames exist or the snippet helper is
	 * not loaded. The panel is compact, bounded by the requested limit, avoids
	 * Datadoc actions in Debugbar context, and highlights the originating callsite.
	 *
	 * @param array<int, array<string, mixed>> $frames Normalized stack frames.
	 * @param string $summary Panel summary label.
	 * @param string $id_seed Stable id seed for generated snippet anchors.
	 * @param int $limit Maximum number of frames to render.
	 * @return string Rendered HTML panel, or an empty string when unavailable.
	 */
	private static function render_stack_snippet_panel(array $frames, string $summary, string $id_seed, int $limit): string {
		if($frames===[] || class_exists('dataphyre_flightdeck_stack_snippets', false)!==true){
			return '';
		}
		return dataphyre_flightdeck_stack_snippets::render_panel($frames, [
			'details_class'=>'dfd-stack',
			'summary'=>$summary,
			'id_prefix'=>'dfd-'.$id_seed.'-frame-',
			'limit'=>max(1, $limit),
			'context_lines'=>5,
			'compact'=>true,
			'show_stack_map'=>true,
			'show_stack_links'=>true,
			'show_datadoc_actions'=>false,
			'use_datadoc_context'=>false,
			'highlight_class'=>'fd-callsite-line',
		]);
	}

	/**
	 * Normalizes origin and captured stack data into de-duplicated source frames.
	*
	 * Only frames with a concrete file and positive line number are kept.
	 * The origin frame, when present, is appended first; subsequent callsite frames
	 * are capped at twelve and deduplicated by normalized file, line, and symbol.
	*
	 * @param mixed $stack Captured stack data, usually an array of frame arrays.
	 * @param mixed $origin Optional origin frame with file, line, function/call, symbol, and kind fields.
	 * @return array<int, array<string, mixed>> Normalized stack frames for snippet rendering.
	 */
	private static function frames_from_stack(mixed $stack, mixed $origin=null): array {
		$frames=[];
		$seen=[];
		$append=function(mixed $frame, string $kind)use(&$frames, &$seen): void{
			if(!is_array($frame)){
				return;
			}
			$file=(string)($frame['file'] ?? '');
			$line=(int)($frame['line'] ?? 0);
			if($file==='' || $line<=0){
				return;
			}
			$class=(string)($frame['class'] ?? '');
			$type=(string)($frame['type'] ?? ($class!=='' ? '::' : ''));
			$function=(string)($frame['function'] ?? '');
			$call=(string)($frame['call'] ?? trim($class.$type.$function));
			$symbol=(string)($frame['symbol'] ?? $call ?: $function ?: basename($file).':'.$line);
			$key=str_replace('\\', '/', $file).'|'.$line.'|'.$symbol;
			if(isset($seen[$key])){
				return;
			}
			$seen[$key]=true;
			$frames[]=[
				'index'=>count($frames),
				'file'=>$file,
				'line'=>$line,
				'class'=>$class,
				'type'=>$type,
				'function'=>$function!=='' ? $function : $symbol,
				'call'=>$call,
				'symbol'=>$symbol,
				'kind'=>$kind,
			];
		};
		if(is_array($origin)){
			$append($origin, (string)($origin['kind'] ?? 'origin'));
		}
		foreach(is_array($stack) ? $stack : [] as $frame){
			$append($frame, 'callsite');
			if(count($frames)>=12){
				break;
			}
		}
		return $frames;
	}

	/**
	 * Converts a PHP backtrace into Debugbar source frames.
	 *
	 * Flightdeck internal frames are skipped so diagnostics point at the
	 * application/runtime caller that triggered the event. The result is capped at
	 * twelve frames to keep retained snapshots small.
	 *
	 * @param array<int, mixed> $trace debug_backtrace()-style frame list.
	 * @return array<int, array<string, mixed>> Normalized non-Flightdeck frames.
	 */
	private static function stack_frames_from_backtrace(array $trace): array {
		$frames=[];
		foreach($trace as $frame){
			if(!is_array($frame) || empty($frame['file']) || empty($frame['line'])){
				continue;
			}
			$normalized=str_replace('\\', '/', (string)$frame['file']);
			if(str_contains($normalized, '/modules/flightdeck/')){
				continue;
			}
			$frames[]=[
				'file'=>(string)$frame['file'],
				'line'=>(int)$frame['line'],
				'class'=>(string)($frame['class'] ?? ''),
				'type'=>(string)($frame['type'] ?? ''),
				'function'=>(string)($frame['function'] ?? ''),
				'call'=>trim((string)($frame['class'] ?? '').(string)($frame['type'] ?? '').(string)($frame['function'] ?? '')),
			];
			if(count($frames)>=12){
				break;
			}
		}
		return $frames;
	}

	/**
	 * Builds stack-snippet CSS for a scoped Debugbar surface.
	 *
	 * CSS is returned as a string because Debugbar can render snippets both
	 * inline and inside generated asset responses. The supplied scope prevents stack
	 * snippet styles from leaking into the host application page.
	 *
	 * @param string $scope CSS selector prefix for the host surface.
	 * @return string CSS rules for stack panels, maps, snippets, and diagnostics.
	 */
	private static function stack_snippet_css(string $scope): string {
		return $scope.' .dfd-stack{margin-top:8px;border:1px solid rgba(125,211,252,.24);border-radius:10px;background:#03101f;color:#e6f0ff;overflow:hidden}'
			.$scope.' .dfd-stack>summary{cursor:pointer;padding:8px 10px;font-weight:800;color:#dff6ff;background:rgba(14,165,233,.1)}'
			.$scope.' .dfd-stack>summary span{color:#93c5fd;margin-left:6px}'
			.$scope.' .fd-stack-map{display:flex;gap:6px;flex-wrap:wrap;padding:8px 10px;border-top:1px solid rgba(125,211,252,.14)}'
			.$scope.' .fd-stack-map a{display:inline-flex;gap:5px;align-items:center;border:1px solid rgba(14,165,233,.24);border-radius:999px;padding:5px 8px;color:#dff6ff;background:rgba(14,165,233,.11);text-decoration:none;font-weight:800}'
			.$scope.' .fd-stack-map span{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:18px;border-radius:999px;background:#0f172a;color:#dff6ff}'
			.$scope.' .fd-snippet{scroll-margin-top:48px;border-top:1px solid rgba(125,211,252,.15);padding:9px 10px;background:transparent;color:#f8fafc}'
			.$scope.' .fd-snippet-head{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:6px}'
			.$scope.' .fd-snippet h3{margin:0;font-size:11px;color:#dbeafe;word-break:break-all}'
			.$scope.' .fd-frame-index{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:20px;margin-right:5px;border-radius:999px;background:rgba(14,165,233,.18);color:#dff6ff}'
			.$scope.' .fd-snippet-meta{margin:0 0 7px;color:#bfdbfe}'
			.$scope.' .fd-snippet-meta code{color:#fed7aa;background:rgba(249,115,22,.14);border-radius:6px;padding:1px 4px}'
			.$scope.' .fd-snippet-actions{display:flex;gap:6px;flex-wrap:wrap}'
			.$scope.' .fd-snippet-actions a{display:inline-flex;align-items:center;border:1px solid rgba(125,211,252,.26);border-radius:999px;padding:4px 7px;color:#dff6ff;text-decoration:none;background:rgba(14,165,233,.1);font-weight:800}'
			.$scope.' .fd-code,'.$scope.' .fd-snippet [id^=codeContainer]{margin:0;background:#07111f!important;color:#dbeafe!important;border-radius:8px;padding:7px 8px!important;overflow:auto;line-height:1.18!important;box-shadow:none!important;max-width:none!important;width:100%!important;white-space:pre}'
			.$scope.' .fd-code span{display:block}'
			.$scope.' .fd-code b{color:#93c5fd}'
			.$scope.' .fd-callsite-line,'.$scope.' .fd-hit{display:block!important;background:rgba(249,115,22,.2)!important;border-left:3px solid #fb923c!important;margin-left:0!important;padding-left:7px!important;line-height:1.18!important}'
			.$scope.' .fd-diagnostics{padding:8px 10px;border-top:1px solid rgba(125,211,252,.14);background:rgba(15,23,42,.45)}'
			.$scope.' .fd-diagnostics h2{margin:0 0 7px;font-size:12px;color:#dff6ff}'
			.$scope.' .fd-diagnostic{border:1px solid rgba(125,211,252,.16);border-radius:8px;padding:8px;margin-top:7px;background:rgba(2,6,23,.45)}'
			.$scope.' .fd-diagnostic h3{margin:0 0 4px;font-size:12px;color:#fed7aa}'
			.$scope.' .fd-diagnostic p{margin:4px 0;color:#bfdbfe}'
			.$scope.' .fd-diagnostic dl{display:grid;grid-template-columns:120px 1fr;gap:4px 8px;margin:7px 0 0;font-size:11px}'
			.$scope.' .fd-diagnostic dt{color:#93c5fd;font-weight:800}'
			.$scope.' .fd-diagnostic dd{margin:0;color:#e2e8f0;word-break:break-word}';
	}

}
