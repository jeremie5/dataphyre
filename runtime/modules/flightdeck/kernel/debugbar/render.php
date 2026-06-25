<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_RENDER_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_RENDER_TRAIT_LOADED', true);

/**
 * Renders Flightdeck debugbar markup, snapshots, panels, and embedded assets.
 *
 * The renderer trait converts debugbar state payloads into isolated toolbar HTML
 * and standalone snapshot pages. It focuses on presentation and escaping; state
 * collection lives in the debugbar state trait.
 */
trait dataphyre_flightdeck_debugbar_render {

	/**
	 * Returns the isolated toolbar stylesheet embedded with debugbar markup.
	 *
	 * The CSS scopes all selectors under the debugbar root, uses dark color-scheme
	 * controls, preserves accessible hidden labels, and defines dock/collapse/
	 * maximized panel states without depending on external assets.
	 *
	 * @return string CSS block for the inline Flightdeck debugbar toolbar.
	 */
	private static function toolbar_css(): string {
		return '
		#dataphyre-flightdeck-debugbar{--dfd-body-max:70vh;position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;background:#07111f;color:#e6f0ff;border:1px solid rgba(144,205,244,.35);box-shadow:0 18px 60px rgba(0,0,0,.35);border-radius:8px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;line-height:1.35;overflow:hidden;transition:top .16s ease,bottom .16s ease,max-width .16s ease,transform .16s ease}
		#dataphyre-flightdeck-debugbar[data-dfd-dock="top"]{top:12px;bottom:auto}
		#dataphyre-flightdeck-debugbar[data-dfd-maximized="1"][data-dfd-collapsed="0"]{left:8px;right:8px;top:8px;bottom:8px;display:flex;flex-direction:column;max-width:none;margin:0}
		#dataphyre-flightdeck-debugbar[data-dfd-size="short"]{--dfd-body-max:38vh}
		#dataphyre-flightdeck-debugbar[data-dfd-size="tall"]{--dfd-body-max:86vh}
		#dataphyre-flightdeck-debugbar[data-dfd-collapsed="1"]{max-width:min(980px,calc(100vw - 24px));margin:0 auto}
		#dataphyre-flightdeck-debugbar *{box-sizing:border-box;letter-spacing:0}
		#dataphyre-flightdeck-debugbar :where(div,section,article,details,summary,p,span,b,strong,em,small,label,table,thead,tbody,tr,td,th,ul,ol,li,dl,dt,dd,code,pre){color:inherit}
		#dataphyre-flightdeck-debugbar a{color:#8bd3ff;text-decoration:none}
		#dataphyre-flightdeck-debugbar button,#dataphyre-flightdeck-debugbar input,#dataphyre-flightdeck-debugbar select,#dataphyre-flightdeck-debugbar textarea{font:inherit;color:#e6f0ff;background:#020817;color-scheme:dark}
		#dataphyre-flightdeck-debugbar option{color:#e6f0ff;background:#020817}
		#dataphyre-flightdeck-debugbar code,#dataphyre-flightdeck-debugbar pre{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#dbeafe}
		#dataphyre-flightdeck-debugbar .dfd-top{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.09)}
		#dataphyre-flightdeck-debugbar[data-dfd-collapsed="1"] .dfd-top{border-bottom:0}
		#dataphyre-flightdeck-debugbar .dfd-brand{font-weight:800;color:#fff}
		#dataphyre-flightdeck-debugbar .dfd-pills{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
		#dataphyre-flightdeck-debugbar .dfd-pill{display:inline-flex;align-items:center;gap:5px;max-width:360px;padding:5px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		#dataphyre-flightdeck-debugbar .dfd-pill-label{color:#9fb6d8;font-weight:700;text-transform:uppercase;font-size:9px;letter-spacing:0}
		#dataphyre-flightdeck-debugbar .dfd-pill-value{color:inherit;font-weight:800}
		#dataphyre-flightdeck-debugbar .dfd-pill.dfd-good{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.38);color:#bbf7d0}
		#dataphyre-flightdeck-debugbar .dfd-pill.dfd-warn{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.4);color:#ffe0a3}
		#dataphyre-flightdeck-debugbar .dfd-pill.dfd-bad{background:rgba(239,68,68,.16);border-color:rgba(239,68,68,.45);color:#fecaca}
		#dataphyre-flightdeck-debugbar .dfd-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
		#dataphyre-flightdeck-debugbar .dfd-actions a,#dataphyre-flightdeck-debugbar .dfd-shell-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border-radius:6px;border:1px solid rgba(144,205,244,.22);background:rgba(255,255,255,.06);color:#d9ecff;cursor:pointer;text-decoration:none}
		#dataphyre-flightdeck-debugbar .dfd-actions a:hover,#dataphyre-flightdeck-debugbar .dfd-shell-btn:hover{background:rgba(144,205,244,.14);border-color:rgba(144,205,244,.4)}
		#dataphyre-flightdeck-debugbar .dfd-shell-btn[aria-pressed="true"]{background:rgba(56,189,248,.18);border-color:rgba(56,189,248,.46);color:#fff}
		#dataphyre-flightdeck-debugbar .dfd-actions a.dfd-danger:hover{background:rgba(239,68,68,.16);border-color:rgba(239,68,68,.48);color:#fecaca}
		#dataphyre-flightdeck-debugbar .dfd-action-icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;font-size:15px;line-height:1;font-weight:900}
		#dataphyre-flightdeck-debugbar .dfd-action-label{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
		#dataphyre-flightdeck-debugbar .dfd-resizer{height:9px;display:flex;align-items:center;justify-content:center;cursor:ns-resize;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.07);touch-action:none}
		#dataphyre-flightdeck-debugbar .dfd-resizer span{width:54px;height:3px;border-radius:999px;background:rgba(144,205,244,.42)}
		#dataphyre-flightdeck-debugbar .dfd-resizer:hover span,#dataphyre-flightdeck-debugbar.dfd-resizing .dfd-resizer span{background:#8bd3ff}
		#dataphyre-flightdeck-debugbar .dfd-body{max-height:var(--dfd-body-max);overflow:auto;background:linear-gradient(180deg,rgba(255,255,255,.025),rgba(255,255,255,.01))}
		#dataphyre-flightdeck-debugbar[data-dfd-maximized="1"][data-dfd-collapsed="0"] .dfd-top{flex:0 0 auto}
		#dataphyre-flightdeck-debugbar[data-dfd-maximized="1"][data-dfd-collapsed="0"] .dfd-body{flex:1;max-height:none;min-height:0}
		#dataphyre-flightdeck-debugbar[data-dfd-maximized="1"][data-dfd-collapsed="0"] .dfd-resizer{display:none}
		#dataphyre-flightdeck-debugbar[data-dfd-collapsed="1"] .dfd-body,#dataphyre-flightdeck-debugbar[data-dfd-collapsed="1"] .dfd-modules,#dataphyre-flightdeck-debugbar[data-dfd-collapsed="1"] .dfd-resizer{display:none}
		#dataphyre-flightdeck-debugbar .dfd-panel-nav{position:sticky;top:0;z-index:2;display:flex;gap:6px;align-items:center;overflow:auto;padding:8px 12px;background:rgba(7,17,31,.96);border-bottom:1px solid rgba(255,255,255,.09);scrollbar-width:thin}
		#dataphyre-flightdeck-debugbar .dfd-filter{display:inline-flex;align-items:center;gap:6px;box-sizing:border-box;height:26px;min-width:min(280px,50vw);margin:0;padding:0;border:0;color:#9fb6d8;white-space:nowrap;line-height:1;vertical-align:middle}
		#dataphyre-flightdeck-debugbar .dfd-filter>span{display:inline-flex;align-items:center;height:26px;line-height:1}
		#dataphyre-flightdeck-debugbar .dfd-filter input{display:block;box-sizing:border-box;width:100%;height:26px;min-height:26px;margin:0;border-radius:999px;border:1px solid rgba(144,205,244,.2);background:rgba(2,8,23,.82);color:#e6f0ff;padding:4px 9px;line-height:16px;outline:none;appearance:none}
		#dataphyre-flightdeck-debugbar .dfd-filter input:focus{border-color:rgba(56,189,248,.55);box-shadow:0 0 0 1px rgba(56,189,248,.22)}
		#dataphyre-flightdeck-debugbar .dfd-filter-status{display:inline-flex;align-items:center;height:26px;min-width:48px;color:#9fb6d8;white-space:nowrap;line-height:1}
		#dataphyre-flightdeck-debugbar .dfd-accessibility-filterbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
		#dataphyre-flightdeck-debugbar .dfd-nav-btn{display:inline-flex;align-items:center;box-sizing:border-box;gap:5px;height:26px;min-height:26px;margin:0;padding:4px 8px;border-radius:999px;border:1px solid rgba(144,205,244,.18);background:rgba(255,255,255,.045);color:#d9ecff;white-space:nowrap;line-height:1;cursor:pointer}
		#dataphyre-flightdeck-debugbar .dfd-nav-btn:hover,#dataphyre-flightdeck-debugbar .dfd-nav-btn.dfd-active{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.45);color:#fff}
		#dataphyre-flightdeck-debugbar .dfd-nav-btn.dfd-warn{border-color:rgba(245,158,11,.38);color:#ffe0a3}
		#dataphyre-flightdeck-debugbar .dfd-nav-btn.dfd-bad{border-color:rgba(239,68,68,.42);color:#fecaca}
		#dataphyre-flightdeck-debugbar[data-dfd-focused="1"] .dfd-nav-btn.dfd-active{background:rgba(56,189,248,.24);box-shadow:0 0 0 1px rgba(56,189,248,.35) inset}
		#dataphyre-flightdeck-debugbar details.dfd-panel{border-top:1px solid rgba(255,255,255,.07)}
		#dataphyre-flightdeck-debugbar[data-dfd-focused="1"] details.dfd-panel[data-dfd-panel]:not(.dfd-active-panel){display:none}
		#dataphyre-flightdeck-debugbar details.dfd-panel[data-dfd-filter-hidden="1"],#dataphyre-flightdeck-debugbar .dfd-nav-btn[data-dfd-filter-hidden="1"]{display:none}
		#dataphyre-flightdeck-debugbar details.dfd-panel>summary{cursor:pointer;display:flex;gap:8px;align-items:center;justify-content:space-between;padding:9px 12px;color:#d9ecff}
		#dataphyre-flightdeck-debugbar summary::marker{color:#9fb6d8}
		#dataphyre-flightdeck-debugbar .dfd-panel-body{padding:0 12px 12px}
		#dataphyre-flightdeck-debugbar .dfd-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
		#dataphyre-flightdeck-debugbar .dfd-metric{border:1px solid rgba(255,255,255,.09);border-radius:8px;padding:8px;background:rgba(255,255,255,.04);min-width:0}
		#dataphyre-flightdeck-debugbar .dfd-metric span{display:block;color:#9fb6d8;font-size:11px}
		#dataphyre-flightdeck-debugbar .dfd-metric b{display:block;margin-top:2px;color:#fff;font-size:14px;overflow:hidden;text-overflow:ellipsis}
		#dataphyre-flightdeck-debugbar .dfd-table{width:100%;border-collapse:collapse}
		#dataphyre-flightdeck-debugbar .dfd-table th,#dataphyre-flightdeck-debugbar .dfd-table td{padding:6px;border-top:1px solid rgba(255,255,255,.08);vertical-align:top;text-align:left;color:#e6f0ff}
		#dataphyre-flightdeck-debugbar .dfd-table th{color:#9fb6d8;font-weight:700}
		#dataphyre-flightdeck-debugbar .dfd-inline-action{display:inline-flex;align-items:center;justify-content:center;min-height:24px;margin-top:4px;padding:3px 7px;border-radius:999px;border:1px solid rgba(144,205,244,.22);background:rgba(255,255,255,.06);color:#d9ecff;cursor:pointer;font-size:11px;font-weight:800}
		#dataphyre-flightdeck-debugbar .dfd-inline-action:hover,#dataphyre-flightdeck-debugbar .dfd-inline-action.dfd-active{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.45);color:#fff}
		#dataphyre-flightdeck-debugbar .dfd-timeline{display:grid;gap:7px;margin:8px 0}
		#dataphyre-flightdeck-debugbar .dfd-tick{display:grid;grid-template-columns:72px 88px 1fr;gap:8px;align-items:center}
		#dataphyre-flightdeck-debugbar .dfd-track{height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}
		#dataphyre-flightdeck-debugbar .dfd-bar{height:100%;min-width:2px;border-radius:999px;background:#38bdf8}
		#dataphyre-flightdeck-debugbar .dfd-range-track{position:relative;height:10px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}
		#dataphyre-flightdeck-debugbar .dfd-range-bar{position:absolute;top:0;bottom:0;min-width:2px;border-radius:999px;background:#38bdf8}
		#dataphyre-flightdeck-debugbar .dfd-bar.dfd-good{background:#22c55e}
		#dataphyre-flightdeck-debugbar .dfd-bar.dfd-warn{background:#f59e0b}
		#dataphyre-flightdeck-debugbar .dfd-bar.dfd-bad{background:#ef4444}
		#dataphyre-flightdeck-debugbar .dfd-range-bar.dfd-good{background:#22c55e}
		#dataphyre-flightdeck-debugbar .dfd-range-bar.dfd-warn{background:#f59e0b}
		#dataphyre-flightdeck-debugbar .dfd-range-bar.dfd-bad{background:#ef4444}
		#dataphyre-flightdeck-debugbar .dfd-code{max-height:180px;overflow:auto;margin:6px 0 0;padding:8px;border-radius:8px;background:#020817;color:#dbeafe;border:1px solid rgba(255,255,255,.08);white-space:pre-wrap}
		#dataphyre-flightdeck-debugbar .dfd-stack{margin-top:8px;border:1px solid rgba(125,211,252,.24);border-radius:10px;background:#03101f;color:#e6f0ff;overflow:hidden}
		#dataphyre-flightdeck-debugbar .dfd-stack>summary{cursor:pointer;padding:8px 10px;font-weight:800;color:#dff6ff;background:rgba(14,165,233,.1)}
		#dataphyre-flightdeck-debugbar .dfd-stack>summary span{color:#93c5fd;margin-left:6px}
		#dataphyre-flightdeck-debugbar .fd-stack-map{display:flex;gap:6px;flex-wrap:wrap;padding:8px 10px;border-top:1px solid rgba(125,211,252,.14)}
		#dataphyre-flightdeck-debugbar .fd-stack-map a{display:inline-flex;gap:5px;align-items:center;border:1px solid rgba(14,165,233,.24);border-radius:999px;padding:5px 8px;color:#dff6ff;background:rgba(14,165,233,.11);text-decoration:none;font-weight:800}
		#dataphyre-flightdeck-debugbar .fd-stack-map span{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:18px;border-radius:999px;background:#0f172a;color:#dff6ff}
		#dataphyre-flightdeck-debugbar .fd-snippet{scroll-margin-top:48px;border-top:1px solid rgba(125,211,252,.15);padding:9px 10px;background:transparent;color:#f8fafc}
		#dataphyre-flightdeck-debugbar .fd-snippet-head{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:6px}
		#dataphyre-flightdeck-debugbar .fd-snippet h3{margin:0;font-size:11px;color:#dbeafe;word-break:break-all}
		#dataphyre-flightdeck-debugbar .fd-frame-index{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:20px;margin-right:5px;border-radius:999px;background:rgba(14,165,233,.18);color:#dff6ff}
		#dataphyre-flightdeck-debugbar .fd-snippet-meta{margin:0 0 7px;color:#bfdbfe}
		#dataphyre-flightdeck-debugbar .fd-snippet-meta code{color:#fed7aa;background:rgba(249,115,22,.14);border-radius:6px;padding:1px 4px}
		#dataphyre-flightdeck-debugbar .fd-snippet-actions{display:flex;gap:6px;flex-wrap:wrap}
		#dataphyre-flightdeck-debugbar .fd-snippet-actions a{display:inline-flex;align-items:center;border:1px solid rgba(125,211,252,.26);border-radius:999px;padding:4px 7px;color:#dff6ff;text-decoration:none;background:rgba(14,165,233,.1);font-weight:800}
		#dataphyre-flightdeck-debugbar .fd-code,#dataphyre-flightdeck-debugbar .fd-snippet [id^=codeContainer]{margin:0;background:#07111f!important;color:#dbeafe!important;border-radius:8px;padding:7px 8px!important;overflow:auto;line-height:1.18!important;box-shadow:none!important;max-width:none!important;width:100%!important;white-space:pre}
		#dataphyre-flightdeck-debugbar .fd-code span{display:block}
		#dataphyre-flightdeck-debugbar .fd-code b{color:#93c5fd}
		#dataphyre-flightdeck-debugbar .fd-callsite-line,#dataphyre-flightdeck-debugbar .fd-hit{display:block!important;background:rgba(249,115,22,.2)!important;border-left:3px solid #fb923c!important;margin-left:0!important;padding-left:7px!important;line-height:1.18!important}
		#dataphyre-flightdeck-debugbar .fd-diagnostics{padding:8px 10px;border-top:1px solid rgba(125,211,252,.14);background:rgba(15,23,42,.45)}
		#dataphyre-flightdeck-debugbar .fd-diagnostics h2{margin:0 0 7px;font-size:12px;color:#dff6ff}
		#dataphyre-flightdeck-debugbar .fd-diagnostic{border:1px solid rgba(125,211,252,.16);border-radius:8px;padding:8px;margin-top:7px;background:rgba(2,6,23,.45)}
		#dataphyre-flightdeck-debugbar .fd-diagnostic h3{margin:0 0 4px;font-size:12px;color:#fed7aa}
		#dataphyre-flightdeck-debugbar .fd-diagnostic p{margin:4px 0;color:#bfdbfe}
		#dataphyre-flightdeck-debugbar .fd-diagnostic dl{display:grid;grid-template-columns:120px 1fr;gap:4px 8px;margin:7px 0 0;font-size:11px}
		#dataphyre-flightdeck-debugbar .fd-diagnostic dt{color:#93c5fd;font-weight:800}
		#dataphyre-flightdeck-debugbar .fd-diagnostic dd{margin:0;color:#e2e8f0;word-break:break-word}
		'.self::trace_css('#dataphyre-flightdeck-debugbar').'
		#dataphyre-flightdeck-debugbar .dfd-muted{color:#9fb6d8}
		#dataphyre-flightdeck-debugbar .dfd-modules{padding:0 12px 10px;color:#9fb6d8}
		@media(max-width:900px){#dataphyre-flightdeck-debugbar{left:8px;right:8px;bottom:8px}#dataphyre-flightdeck-debugbar[data-dfd-dock="top"]{top:8px}#dataphyre-flightdeck-debugbar .dfd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}#dataphyre-flightdeck-debugbar .dfd-route-pill{display:none}}
		@media(max-width:620px){#dataphyre-flightdeck-debugbar .dfd-body{max-height:min(var(--dfd-body-max),62vh)}#dataphyre-flightdeck-debugbar .dfd-modules{display:none}#dataphyre-flightdeck-debugbar .dfd-grid{grid-template-columns:1fr}#dataphyre-flightdeck-debugbar .dfd-pill-label{display:none}}
		';
	}

	/**
	 * Renders a persisted debugbar snapshot as standalone HTML.
	 *
	 * @param array{id?:string,label?:string,recorded_at?:int,duration_ms?:int|float,memory_mb?:int|float,files?:int,sql?:array<string,mixed>,routing?:array<string,mixed>,request?:array<string,mixed>,response?:array<string,mixed>,client?:array<string,mixed>,templating?:array<string,mixed>,panel?:array<string,mixed>,reactor?:array<string,mixed>,asset_node?:array<string,mixed>,runtime?:array<string,mixed>,trace?:array<string,mixed>,timeline?:array<string,mixed>,errors?:array<string,mixed>,diagnostics?:array<string,mixed>} $snapshot Compact debugbar snapshot payload read from snapshot storage or the current request capture.
	 * @return string Snapshot HTML including stylesheet and deferred script tags.
	 */
	public static function render_snapshot_html(array $snapshot): string {
		$sql=is_array($snapshot['sql'] ?? null) ? $snapshot['sql'] : [];
		$routing=is_array($snapshot['routing'] ?? null) ? $snapshot['routing'] : [];
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$response=is_array($snapshot['response'] ?? null) ? $snapshot['response'] : [];
		$client=is_array($snapshot['client'] ?? null) ? $snapshot['client'] : [];
		$templating=is_array($snapshot['templating'] ?? null) ? $snapshot['templating'] : [];
		$panel=is_array($snapshot['panel'] ?? null) ? $snapshot['panel'] : [];
		$reactor=is_array($snapshot['reactor'] ?? null) ? $snapshot['reactor'] : [];
		$asset_node=is_array($snapshot['asset_node'] ?? null) ? $snapshot['asset_node'] : [];
		$runtime=is_array($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : [];
		$trace=is_array($snapshot['trace'] ?? null) ? $snapshot['trace'] : [];
		$timeline=is_array($snapshot['timeline'] ?? null) ? $snapshot['timeline'] : [];
		$errors=is_array($snapshot['errors'] ?? null) ? $snapshot['errors'] : [];
		$diagnostics=is_array($snapshot['diagnostics'] ?? null) ? $snapshot['diagnostics'] : [];
		return '<link rel="stylesheet" href="'.self::e(self::asset_url('debugbar-snapshot.css')).'"><div class="dfd-history">'
			.self::render_snapshot_header($snapshot)
			.self::render_triage_panel($snapshot, $client)
			.self::render_comparison_panel($snapshot)
			.self::render_diagnostics_panel($diagnostics, $errors)
			.self::render_sql_panel($sql)
			.self::render_timeline_panel($timeline, $client)
			.self::render_tracelog_panel($trace)
			.self::render_routing_panel($routing)
			.self::render_panel_lifecycle_panel($panel)
			.self::render_reactor_panel($reactor)
			.self::render_asset_node_panel($asset_node)
			.self::render_request_panel($request)
			.self::render_response_panel($response)
			.self::render_accessibility_panel($client)
			.self::render_client_panel($client)
			.self::render_templating_panel($templating)
			.self::render_runtime_panel($runtime, $trace)
			.'</div>'
			.'<script src="'.self::e(self::asset_url('debugbar-snapshot.js')).'" defer></script>';
	}

	/**
	 * Wraps toolbar markup in a shadow-root host when the browser supports it.
	 *
	 * @param string $toolbar Fully rendered toolbar markup.
	 * @return string Isolation host and bootstrap script, or original markup when encoding fails.
	 */
	private static function isolate_toolbar_markup(string $toolbar): string {
		$payload=json_encode($toolbar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if(!is_string($payload)){
			return $toolbar;
		}
		$nonce=defined('NONCE') ? ' nonce="'.self::e((string)NONCE).'"' : '';
		$host_style='all:initial!important;display:block!important;position:static!important;z-index:2147483000!important';
		$host='<dataphyre-flightdeck-root id="dataphyre-flightdeck-debugbar-host" style="'.$host_style.'"></dataphyre-flightdeck-root>';
		$script=<<<JS
<script{$nonce}>(function(){
	var host=document.getElementById("dataphyre-flightdeck-debugbar-host");
	if(!host){
		host=document.createElement("dataphyre-flightdeck-root");
		host.id="dataphyre-flightdeck-debugbar-host";
		(document.body || document.documentElement).appendChild(host);
	}
	host.setAttribute("style", "{$host_style}");
	try{
		var root=host.shadowRoot || host.attachShadow({mode:"open"});
		root.innerHTML={$payload};
	}catch(shadowError){
		host.innerHTML={$payload};
	}
})();</script>
JS;
		return $host.$script;
	}

	/**
	 * Renders the live debugbar toolbar for the current response.
	 *
	 * @param ?string $buffer Optional response buffer used for state collection.
	 * @param array{modules?:list<string>,uri?:string,method?:string,duration_ms?:int|float,memory_mb?:int|float,files?:int,sql?:array<string,mixed>,routing?:array<string,mixed>,request?:array<string,mixed>,response?:array<string,mixed>,templating?:array<string,mixed>,panel?:array<string,mixed>,reactor?:array<string,mixed>,asset_node?:array<string,mixed>,runtime?:array<string,mixed>,trace?:array<string,mixed>,timeline?:array<string,mixed>,errors?:array<string,mixed>,diagnostics?:array<string,mixed>}|null $state Optional precomputed debugbar state.
	 * @return string Isolated toolbar markup.
	 */
	private static function markup(?string $buffer=null, ?array $state=null): string {
		$state=$state ?? self::state($buffer);
		$snapshot=self::record_snapshot($state);
		if(!is_array($snapshot)){
			$snapshot=self::compact_snapshot($state);
		}
		$sql=is_array($state['sql'] ?? null) ? $state['sql'] : [];
		$routing=is_array($state['routing'] ?? null) ? $state['routing'] : [];
		$request=is_array($state['request'] ?? null) ? $state['request'] : [];
		$response=is_array($state['response'] ?? null) ? $state['response'] : [];
		$templating=is_array($state['templating'] ?? null) ? $state['templating'] : [];
		$panel=is_array($state['panel'] ?? null) ? $state['panel'] : [];
		$reactor=is_array($state['reactor'] ?? null) ? $state['reactor'] : [];
		$asset_node=is_array($state['asset_node'] ?? null) ? $state['asset_node'] : [];
		$runtime=is_array($state['runtime'] ?? null) ? $state['runtime'] : [];
		$trace=is_array($state['trace'] ?? null) ? $state['trace'] : [];
		$timeline=is_array($state['timeline'] ?? null) ? $state['timeline'] : [];
		$errors=is_array($state['errors'] ?? null) ? $state['errors'] : [];
		$diagnostics=is_array($state['diagnostics'] ?? null) ? $state['diagnostics'] : [];
		$client=is_array($snapshot['client'] ?? null) ? $snapshot['client'] : [];
		$snapshot_id=(string)($snapshot['id'] ?? '');
		$client_token=$snapshot_id!=='' ? self::client_token($snapshot_id) : '';
		$modules=implode(', ', array_slice($state['modules'], 0, 12));
		if(count($state['modules'])>12){
			$modules.=' +'.(count($state['modules']) - 12);
		}
		$open_url='/dataphyre/debugbar';
		$disable_url='/dataphyre/debugbar?action=disable';
		$route_label=(string)($routing['matched_route'] ?? $routing['request_path'] ?? $state['uri'] ?? '');
		$sql_summary=(int)($sql['query_events'] ?? 0).' sql';
		if((float)($sql['total_duration_ms'] ?? 0)>0){
			$sql_summary.=' / '.self::format_ms((float)$sql['total_duration_ms']);
		}
		$status_code=(int)($request['status'] ?? 200);
		$status_tone=$status_code>=500 ? 'bad' : ($status_code>=400 ? 'warn' : '');
		$finding_count=(int)($diagnostics['count'] ?? 0);
		$finding_tone=self::level_tone((string)($diagnostics['worst_level'] ?? 'ok'));
		$template_summary=(int)($templating['sql_binding_count'] ?? 0).' bound sql';
		$toolbar='<link rel="stylesheet" href="'.self::e(self::asset_url('debugbar.css')).'"><aside id="dataphyre-flightdeck-debugbar">'
		.'<div class="dfd-top"><div class="dfd-pills"><span class="dfd-brand">Dataphyre Flightdeck</span>'
		.self::status_pill('Time', self::format_ms((float)$state['duration_ms']))
		.self::status_pill('HTTP', (string)$status_code, $status_tone)
		.self::status_pill('Memory', (string)$state['memory_mb'].'mb')
		.self::status_pill('Files', (string)$state['files'])
		.self::status_pill('SQL', $sql_summary, ((int)($sql['failed_events'] ?? 0)>0 ? 'bad' : ((int)($sql['slow_events'] ?? 0)>0 ? 'warn' : '')))
		.self::status_pill('Findings', (string)$finding_count, $finding_tone)
		.self::status_pill('Bindings', $template_summary)
		.self::status_pill('Route', (string)$state['method'].' '.$route_label, '', 'dfd-route-pill')
		.'</div><div class="dfd-actions">'
		.self::action_button('dock', 'Dock top', '&#8593;')
		.self::action_button('maximize', 'Full view', '&#9974;')
		.self::action_button('size', 'Tall height', '&#8597;')
		.self::action_button('collapse', 'Collapse Flightdeck', '&#8722;', ['aria-expanded'=>'true'])
		.self::action_button('focus', 'Focus active panel', '&#9673;')
		.self::action_button('expand-panels', 'Open all panels', '&#8862;')
		.self::action_button('collapse-panels', 'Close all panels', '&#8863;')
		.self::action_link($open_url, 'Open Flightdeck console', '&#8599;')
		.self::action_link($disable_url, 'Disable Flightdeck toolbar', '&#215;', 'dfd-danger')
		.'</div></div>'
		.'<div class="dfd-resizer" data-dfd-resizer title="Drag to resize Flightdeck"><span></span></div>'
		.'<div class="dfd-body">'
		.self::render_panel_nav($state, $snapshot, $client)
		.self::render_triage_panel($state, $client)
		.self::render_comparison_panel($snapshot)
		.self::render_diagnostics_panel($diagnostics, $errors)
		.self::render_sql_panel($sql)
		.self::render_timeline_panel($timeline, $client)
		.self::render_tracelog_panel($trace)
		.self::render_routing_panel($routing)
		.self::render_panel_lifecycle_panel($panel)
		.self::render_reactor_panel($reactor)
		.self::render_asset_node_panel($asset_node)
		.self::render_request_panel($request)
		.self::render_response_panel($response)
		.self::render_accessibility_panel($client)
		.self::render_client_panel($client)
		.self::render_templating_panel($templating)
		.self::render_runtime_panel($runtime, $trace)
		.'<div class="dfd-modules">Modules: '.self::e($modules ?: 'none').'</div>'
		.'</div></aside>';
		return self::isolate_toolbar_markup($toolbar)
		.'<script src="'.self::e(self::asset_url('debugbar.js')).'" defer></script>'
		.self::client_probe_script($snapshot_id, $client_token);
	}

	/**
	 * Renders diagnostic findings and captured PHP errors.
	 *
	 * @param array{count?:int,worst_level?:string,findings?:list<array{level?:string,source?:string,title?:string,detail?:string,next?:string}>} $diagnostics Diagnostic finding rows derived from request, runtime, response, and client evidence.
	 * @param array{events?:list<array{severity?:string,file?:string,line?:int,message?:string,timestamp?:int,stack?:list<array<string,mixed>>}>} $errors Captured PHP warning/error payload rendered beside derived diagnostics.
	 * @return string Diagnostics panel HTML.
	 */
	private static function render_diagnostics_panel(array $diagnostics, array $errors): string {
		$findings=is_array($diagnostics['findings'] ?? null) ? $diagnostics['findings'] : [];
		$error_events=is_array($errors['events'] ?? null) ? $errors['events'] : [];
		$worst=(string)($diagnostics['worst_level'] ?? 'ok');
		$html='<details id="dfd-panel-diagnostics" class="dfd-panel" data-dfd-panel="diagnostics"'.($findings!==[] || $error_events!==[] ? ' open' : '').'><summary><span>Diagnostics</span><span class="dfd-muted">'.self::e((string)count($findings)).' findings</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Overall', $worst==='ok' ? 'clear' : $worst, 'Derived request health')
			.self::metric('Findings', (string)count($findings), 'Status, SQL, routing, memory, trace')
			.self::metric('PHP Events', (string)count($error_events), 'Captured warnings and errors')
			.self::metric('Worst Level', $worst, 'Highest severity found')
			.'</div>';
		if($findings===[]){
			$html.='<p class="dfd-muted">No derived diagnostics for this request.</p>';
		}
		else
		{
			$html.='<table class="dfd-table"><thead><tr><th>Level</th><th>Source</th><th>Finding</th><th>Detail</th><th>Next step</th></tr></thead><tbody>';
			foreach($findings as $finding){
				$level=(string)($finding['level'] ?? 'info');
				$next=self::diagnostic_next_step($finding);
				$references=self::triage_reference_links([
					'level'=>$level,
					'title'=>(string)($finding['title'] ?? ''),
					'detail'=>(string)($finding['detail'] ?? ''),
					'source'=>(string)($finding['source'] ?? 'runtime'),
					'next'=>$next,
				]);
				$html.='<tr>'
					.'<td>'.self::pill(self::e($level), self::level_tone($level)).'</td>'
					.'<td>'.self::e((string)($finding['source'] ?? 'runtime')).'</td>'
					.'<td>'.self::e((string)($finding['title'] ?? '')).'</td>'
					.'<td>'.self::e((string)($finding['detail'] ?? '')).'</td>'
					.'<td>'.self::e($next).($references!=='' ? '<div class="dfd-ref-list">'.$references.'</div>' : '').'</td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		if($error_events!==[]){
			$html.='<details><summary>PHP warnings and errors</summary><table class="dfd-table"><thead><tr><th>When</th><th>Severity</th><th>Source</th><th>Message</th></tr></thead><tbody>';
			foreach(array_slice($error_events, -20) as $event){
				$severity=(string)($event['severity'] ?? 'info');
				$source=trim(basename((string)($event['file'] ?? '')).':'.(string)($event['line'] ?? ''), ':');
				$stack=self::render_error_stack_panel($event);
				$html.='<tr>'
					.'<td>'.self::e(date('H:i:s', (int)($event['timestamp'] ?? time()))).'</td>'
					.'<td>'.self::pill(self::e($severity), self::level_tone($severity)).'</td>'
					.'<td>'.self::e($source).'</td>'
					.'<td>'.self::e((string)($event['message'] ?? '')).'</td>'
					.'</tr>';
				if($stack!==''){
					$html.='<tr><td></td><td colspan="3">'.$stack.'</td></tr>';
				}
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Builds the recommended next-step text for a diagnostic finding.
	 *
	 * @param array{level?:string,source?:string,title?:string,detail?:string,next?:string} $finding Diagnostic finding row from the state collector.
	 * @return string Human-readable next step.
	 */
	private static function diagnostic_next_step(array $finding): string {
		$title=strtolower((string)($finding['title'] ?? ''));
		$source=strtolower((string)($finding['source'] ?? 'runtime'));
		if(str_contains($title, 'php emitted') || $source==='php'){
			return 'Open the PHP event list and start with the first stack-linked warning near the request origin.';
		}
		if(str_contains($title, 'mojibake') || str_contains($title, 'encoding')){
			return 'Open Response Audit and check charset plus the upstream text source that rendered the damaged copy.';
		}
		if(str_contains($title, 'duplicate document shells')){
			return 'Open Response Audit and find the duplicate layout/include that emitted a second document shell.';
		}
		if(str_contains($title, 'suspicious asset') || str_contains($title, 'local assets')){
			return 'Open Response Audit and inspect the asset resolution rows before the URL falls through routing.';
		}
		if(str_contains($title, 'duplicate html ids')){
			return 'Open Response Audit and fix the repeated id before testing browser-side selectors.';
		}
		if(str_contains($title, 'json') || str_contains($title, 'api response')){
			return 'Open Response Audit and inspect the decoded JSON marker path or raw preview.';
		}
		if($source==='sql' || str_contains($title, 'sql') || str_contains($title, 'query')){
			return 'Open SQL Flight Recorder and inspect the event statement, target, and callsite.';
		}
		if($source==='reactor' || str_contains($title, 'reactor')){
			return 'Open Reactor and inspect the component, action, validation, and emitted effects for the closest lifecycle event.';
		}
		if($source==='routing' || str_contains($title, 'route')){
			return 'Open Routing and verify the matched route, target file, and not-found handling.';
		}
		if($source==='tracelog' || str_contains($title, 'trace')){
			return 'Open Tracelog and inspect the pre-module buffer around bootstrap and module loading.';
		}
		if($source==='timeline' || str_contains($title, 'timeline')){
			return 'Open Timeline and compare the request range with SQL, routing, and browser events.';
		}
		if($source==='runtime' || str_contains($title, 'memory')){
			return 'Open Runtime and compare peak memory, included files, and loaded module state.';
		}
		if($source==='request'){
			return 'Open Request and Response Audit to compare status, headers, and rendered body.';
		}
		if($source==='response'){
			return 'Open Response Audit and inspect the rendered body evidence for this finding.';
		}
		return 'Open the referenced panel below and inspect the closest source evidence.';
	}

	/**
	 * Renders the header for a persisted debugbar snapshot page.
	 *
	 * @param array{label?:string,recorded_at?:int,duration_ms?:int|float,memory_mb?:int|float,files?:int,request?:array{status?:int},diagnostics?:array{count?:int,worst_level?:string},sql?:array{query_events?:int}} $snapshot Snapshot payload used for persisted history headers.
	 * @return string Snapshot header HTML.
	 */
	private static function render_snapshot_header(array $snapshot): string {
		$status=(int)($snapshot['request']['status'] ?? 200);
		$status_tone=$status>=500 ? 'bad' : ($status>=400 ? 'warn' : '');
		$diagnostics=is_array($snapshot['diagnostics'] ?? null) ? $snapshot['diagnostics'] : [];
		$finding_count=(int)($diagnostics['count'] ?? 0);
		$recorded_at=(int)($snapshot['recorded_at'] ?? time());
		return '<section class="dfd-snapshot-head">'
			.'<div><span class="dfd-muted">Recorded '.self::e(date('Y-m-d H:i:s', $recorded_at)).'</span><h2>'.self::e((string)($snapshot['label'] ?? self::snapshot_label($snapshot))).'</h2></div>'
			.'<div class="dfd-pills">'
			.self::pill((string)$status, $status_tone)
			.self::pill(self::format_ms((float)($snapshot['duration_ms'] ?? 0)))
			.self::pill(self::e((string)($snapshot['memory_mb'] ?? 0)).'mb')
			.self::pill(self::e((string)($snapshot['files'] ?? 0)).' files')
			.self::pill(self::e((string)$finding_count).' findings', self::level_tone((string)($diagnostics['worst_level'] ?? 'ok')))
			.self::pill(self::e((string)($snapshot['sql']['query_events'] ?? 0)).' sql')
			.'</div></section>';
	}

	/**
	 * Renders the debugbar panel navigation and filter controls.
	 *
	 * @param array{modules?:list<string>,uri?:string,method?:string,sql?:array<string,mixed>,routing?:array<string,mixed>,request?:array<string,mixed>,response?:array<string,mixed>,templating?:array<string,mixed>,panel?:array<string,mixed>,reactor?:array<string,mixed>,asset_node?:array<string,mixed>,runtime?:array<string,mixed>,timeline?:array<string,mixed>,trace?:array<string,mixed>,diagnostics?:array<string,mixed>} $state Live debugbar state assembled before response injection.
	 * @param array{id?:string,comparison?:array<string,mixed>,client?:array<string,mixed>} $snapshot Persisted snapshot metadata returned after recording the current request.
	 * @param array{event_count?:int,client_http_errors?:int,client_fetch_errors?:int,js_errors?:int,resource_errors?:int,slow_resources?:int,client_http_slow?:int,accessibility_issues?:int,accessibility_adjustments?:int} $client Client-side telemetry summary merged from prior browser beacons.
	 * @return string Panel navigation HTML.
	 */
	private static function render_panel_nav(array $state, array $snapshot, array $client): string {
		$sql=is_array($state['sql'] ?? null) ? $state['sql'] : [];
		$request=is_array($state['request'] ?? null) ? $state['request'] : [];
		$response=is_array($state['response'] ?? null) ? $state['response'] : [];
		$routing=is_array($state['routing'] ?? null) ? $state['routing'] : [];
		$templating=is_array($state['templating'] ?? null) ? $state['templating'] : [];
		$panel=is_array($state['panel'] ?? null) ? $state['panel'] : [];
		$reactor=is_array($state['reactor'] ?? null) ? $state['reactor'] : [];
		$asset_node=is_array($state['asset_node'] ?? null) ? $state['asset_node'] : [];
		$runtime=is_array($state['runtime'] ?? null) ? $state['runtime'] : [];
		$timeline=is_array($state['timeline'] ?? null) ? $state['timeline'] : [];
		$trace=is_array($state['trace'] ?? null) ? $state['trace'] : [];
		$diagnostics=is_array($state['diagnostics'] ?? null) ? $state['diagnostics'] : [];
		$comparison=is_array($snapshot['comparison'] ?? null) ? $snapshot['comparison'] : [];
		$response_issues=count(is_array($response['suspicious_assets'] ?? null) ? $response['suspicious_assets'] : [])
			+count(is_array($response['duplicate_ids'] ?? null) ? $response['duplicate_ids'] : [])
			+count(is_array($response['suspicious_phrases'] ?? null) ? $response['suspicious_phrases'] : [])
			+(int)($response['mojibake_count'] ?? 0)
			+(int)($response['missing_asset_count'] ?? 0)
			+(int)($response['json_failure_count'] ?? 0);
		$status=(int)($request['status'] ?? 200);
		$module_count=(int)($runtime['modules_count'] ?? (is_array($state['modules'] ?? null) ? count($state['modules']) : 0));
		$trace_count=(int)($trace['entry_count'] ?? (count(is_array($trace['entries'] ?? null) ? $trace['entries'] : []) + count(is_array($trace['live_entries'] ?? null) ? $trace['live_entries'] : []) + count(is_array($trace['session_entries'] ?? null) ? $trace['session_entries'] : [])));
		$items=[
			['triage', 'Triage', (string)count(self::triage_items($state, $client)), self::level_tone((string)($diagnostics['worst_level'] ?? 'ok'))],
			['diagnostics', 'Diagnostics', (string)((int)($diagnostics['count'] ?? 0)), self::level_tone((string)($diagnostics['worst_level'] ?? 'ok'))],
			['sql', 'SQL', (string)((int)($sql['query_events'] ?? 0)), (int)($sql['failed_events'] ?? 0)>0 ? 'bad' : ((int)($sql['slow_events'] ?? 0)>0 || (is_array($sql['duplicates'] ?? null) && $sql['duplicates']!==[]) ? 'warn' : '')],
			['timeline', 'Timeline', (string)((int)($timeline['event_count'] ?? count($timeline['events'] ?? []))), ''],
			['tracelog', 'Tracelog', (string)$trace_count, $trace_count>0 ? '' : ''],
			['routing', 'Route', !empty($routing['matched_route']) ? 'matched' : 'check', empty($routing['matched_route']) && $status<400 ? 'warn' : ''],
			['request', 'Request', (string)$status, $status>=500 ? 'bad' : ($status>=400 ? 'warn' : '')],
			['response', 'Response', (string)$response_issues, $response_issues>0 ? 'warn' : ''],
			['accessibility', 'Accessibility', (string)((int)($client['accessibility_issues'] ?? 0)), (int)($client['accessibility_issues'] ?? 0)>0 ? 'warn' : ((int)($client['accessibility_adjustments'] ?? 0)>0 ? '' : '')],
			['browser', 'Browser', (string)((int)($client['event_count'] ?? 0)), ((int)($client['client_http_errors'] ?? 0)+(int)($client['client_fetch_errors'] ?? 0)+(int)($client['js_errors'] ?? 0)+(int)($client['resource_errors'] ?? 0))>0 ? 'bad' : (((int)($client['slow_resources'] ?? 0)+(int)($client['client_http_slow'] ?? 0)+(int)($client['accessibility_issues'] ?? 0))>0 ? 'warn' : '')],
			['templating', 'Template', (string)((int)($templating['sql_binding_count'] ?? 0)), ''],
			['runtime', 'Runtime', (string)$module_count, ''],
		];
		if(!empty($comparison['available'])){
			array_splice($items, 1, 0, [[
				'comparison',
				'Compare',
				(string)((int)($comparison['regressions'] ?? 0)),
				(int)($comparison['error_regressions'] ?? 0)>0 ? 'bad' : ((int)($comparison['regressions'] ?? 0)>0 ? 'warn' : ''),
			]]);
		}
		if(!empty($asset_node['available'])){
			$cdn_configured=($asset_node['configured'] ?? null)===true;
			$cdn_can_store=($asset_node['can_store'] ?? null)!==false;
			array_splice($items, 7, 0, [[
				'cdn-server',
				'Asset Node',
				(string)count(is_array($asset_node['trace'] ?? null) ? $asset_node['trace'] : []),
				($cdn_configured && $cdn_can_store) ? '' : 'warn',
			]]);
		}
		if(!empty($panel['available'])){
			$panel_event_count=(int)($panel['event_count'] ?? 0);
			array_splice($items, 7, 0, [[
				'panel',
				'Panel',
				!empty($panel['loaded']) ? (string)$panel_event_count : 'idle',
				!empty($panel['insights']) ? self::level_tone((string)($panel['insights'][0]['level'] ?? 'info')) : '',
			]]);
		}
		if(!empty($reactor['available'])){
			$reactor_event_count=(int)($reactor['event_count'] ?? 0);
			array_splice($items, 8, 0, [[
				'reactor',
				'Reactor',
				!empty($reactor['loaded']) ? (string)$reactor_event_count : 'idle',
				!empty($reactor['insights']) ? self::level_tone((string)($reactor['insights'][0]['level'] ?? 'info')) : '',
			]]);
		}
		$html='<div class="dfd-panel-nav" role="navigation" aria-label="Flightdeck panels">'
			.'<label class="dfd-filter"><span>Filter</span><input type="search" data-dfd-filter placeholder="panel text, status, file, query"></label>'
			.'<button type="button" class="dfd-nav-btn" data-dfd-action="clear-filter">Clear</button>'
			.'<span class="dfd-filter-status" data-dfd-filter-status></span>';
		foreach($items as $item){
			$panel=(string)($item[0] ?? '');
			$label=(string)($item[1] ?? '');
			$count=(string)($item[2] ?? '');
			$tone=(string)($item[3] ?? '');
			$html.='<button type="button" class="dfd-nav-btn'.($tone!=='' ? ' dfd-'.$tone : '').'" data-dfd-panel-target="'.self::e($panel).'">'.self::e($label).'<span class="dfd-muted">'.self::e($count).'</span></button>';
		}
		return $html.'</div>';
	}

	/**
	 * Renders the high-level triage summary for a debugbar snapshot.
	 *
	 * @param array{duration_ms?:int|float,sql?:array{total_duration_ms?:int|float},request?:array{status?:int},response?:array<string,mixed>,diagnostics?:array{count?:int,worst_level?:string},timeline?:array{duration_ms?:int|float},errors?:array<string,mixed>,comparison?:array<string,mixed>,client?:array<string,mixed>} $snapshot Snapshot or live state payload used to surface first-response debugging leads.
	 * @param array{event_count?:int,page_performance?:array{load_ms?:int|float,dom_content_loaded_ms?:int|float},production_replay?:array<string,mixed>} $client Client-side telemetry state merged into the triage summary.
	 * @return string Triage panel HTML.
	 */
	private static function render_triage_panel(array $snapshot, array $client=[]): string {
		if($client===[] && is_array($snapshot['client'] ?? null)){
			$client=$snapshot['client'];
		}
		$sql=is_array($snapshot['sql'] ?? null) ? $snapshot['sql'] : [];
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$response=is_array($snapshot['response'] ?? null) ? $snapshot['response'] : [];
		$diagnostics=is_array($snapshot['diagnostics'] ?? null) ? $snapshot['diagnostics'] : [];
		$timeline=is_array($snapshot['timeline'] ?? null) ? $snapshot['timeline'] : [];
		$duration_ms=(float)($snapshot['duration_ms'] ?? $timeline['duration_ms'] ?? 0);
		$status=(int)($request['status'] ?? 200);
		$page=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		$page_load_ms=(float)($page['load_ms'] ?? 0);
		$sql_ms=(float)($sql['total_duration_ms'] ?? 0);
		$server_label=$duration_ms>0 ? self::format_ms($duration_ms) : 'pending';
		$sql_share=$duration_ms>0 && $sql_ms>0 ? round(min(999, ($sql_ms / max(1.0, $duration_ms)) * 100), 1).'%' : '0%';
		$items=self::triage_items($snapshot, $client);
		$primary=$items[0] ?? null;
		$outcome=$status>=500 ? 'server failure' : ($status>=400 ? 'not successful' : (((string)($diagnostics['worst_level'] ?? 'ok'))==='ok' && $items===[] ? 'clear' : 'needs attention'));
		$html='<details id="dfd-panel-triage" class="dfd-panel" data-dfd-panel="triage" open><summary><span>Triage</span><span class="dfd-muted">'.self::e((string)count($items)).' leads</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Outcome', $outcome, $primary!==null ? (string)($primary['title'] ?? '') : 'No strong failure signal')
			.self::metric('Server', $server_label, 'SQL '.$sql_share.' of measured server time')
			.self::metric('Browser', $page_load_ms>0 ? self::format_ms($page_load_ms) : 'pending', $page!==[] ? 'DOM '.self::format_ms((float)($page['dom_content_loaded_ms'] ?? 0)) : 'Client probe has not reported yet')
			.self::metric('Signals', (string)count($items), (int)($diagnostics['count'] ?? 0).' server findings / '.(int)($client['event_count'] ?? 0).' browser events')
			.'</div>';
		if($items===[]){
			$html.='<p class="dfd-muted">No strong leads yet. The lower panels still contain the full request evidence.</p>';
		}
		else
		{
			$html.='<table class="dfd-table"><thead><tr><th>Level</th><th>Area</th><th>Lead</th><th>Next check</th></tr></thead><tbody>';
			foreach(array_slice($items, 0, 8) as $item){
				$level=(string)($item['level'] ?? 'info');
				$source=(string)($item['source'] ?? 'runtime');
				$source_target=self::triage_panel_target($source, (string)($item['title'] ?? ''), (string)($item['next'] ?? ''));
				$references=self::triage_reference_links($item);
				$html.='<tr>'
					.'<td>'.self::pill(self::e($level), self::level_tone($level)).'</td>'
					.'<td>'.self::triage_panel_link($source, $source_target).'</td>'
					.'<td><b>'.self::e((string)($item['title'] ?? '')).'</b><br><span class="dfd-muted">'.self::e((string)($item['detail'] ?? '')).'</span></td>'
					.'<td>'.self::e((string)($item['next'] ?? 'Open the related panel below.')).($references!=='' ? '<div class="dfd-ref-list">'.$references.'</div>' : '').'</td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		$phases=self::triage_phases($snapshot, $client);
		if($phases!==[]){
			$max=max(1.0, ...array_map(static fn(array $phase): float => (float)($phase['duration_ms'] ?? 0), $phases));
			$html.='<details open><summary>Phase timing</summary><table class="dfd-table"><thead><tr><th>Phase</th><th>Time</th><th>Share</th><th>Signal</th></tr></thead><tbody>';
			foreach($phases as $phase){
				$value=(float)($phase['duration_ms'] ?? 0);
				$width=max(2.0, min(100.0, ($value / $max) * 100));
				$tone=(string)($phase['tone'] ?? '');
				$html.='<tr>'
					.'<td>'.self::e((string)($phase['label'] ?? '')).'</td>'
					.'<td>'.self::format_ms($value).'</td>'
					.'<td><div class="dfd-track"><div class="dfd-bar'.($tone!=='' ? ' dfd-'.$tone : '').'" style="width:'.self::e((string)round($width, 2)).'%"></div></div></td>'
					.'<td>'.self::e((string)($phase['detail'] ?? '')).'</td>'
					.'</tr>';
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Builds prioritized triage cards from server and client evidence.
	 *
	 * @param array{duration_ms?:int|float,sql?:array<string,mixed>,request?:array<string,mixed>,response?:array<string,mixed>,diagnostics?:array<string,mixed>,errors?:array<string,mixed>,comparison?:array<string,mixed>,timeline?:array<string,mixed>} $snapshot Snapshot payload containing server-side evidence.
	 * @param array{event_count?:int,js_errors?:int,resource_errors?:int,client_http_errors?:int,client_fetch_errors?:int,accessibility_issues?:int,page_performance?:array<string,mixed>,production_replay?:array<string,mixed>} $client Client-side telemetry state containing browser and accessibility evidence.
	 * @return list<array{level:string,title:string,detail:string,source:string,next:string,priority:int}> Triage item rows sorted by severity and evidence priority.
	 */
	private static function triage_items(array $snapshot, array $client): array {
		$sql=is_array($snapshot['sql'] ?? null) ? $snapshot['sql'] : [];
		$request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
		$response=is_array($snapshot['response'] ?? null) ? $snapshot['response'] : [];
		$diagnostics=is_array($snapshot['diagnostics'] ?? null) ? $snapshot['diagnostics'] : [];
		$errors=is_array($snapshot['errors'] ?? null) ? $snapshot['errors'] : [];
		$comparison=is_array($snapshot['comparison'] ?? null) ? $snapshot['comparison'] : [];
		$items=[];
		$seen=[];
		$add=static function(string $level, string $title, string $detail, string $source, string $next, int $priority=50)use(&$items, &$seen): void{
			$key=strtolower($source.'|'.$title);
			if(isset($seen[$key])){
				return;
			}
			$seen[$key]=true;
			$items[]=[
				'level'=>$level,
				'title'=>$title,
				'detail'=>$detail,
				'source'=>$source,
				'next'=>$next,
				'priority'=>$priority,
			];
		};
		$status=(int)($request['status'] ?? 200);
		if($status>=500){
			$add('error', 'HTTP '.$status.' response', 'The request reached Flightdeck with a server error status.', 'request', 'Open Diagnostics first, then check Response Audit for the rendered failure page.', 5);
		}
		elseif($status>=400){
			$add('warning', 'HTTP '.$status.' response', 'The request did not finish successfully.', 'request', 'Open Routing and Response Audit to confirm the matched target and response body.', 20);
		}
		if($comparison!==[] && (int)($comparison['regressions'] ?? 0)>0){
			$level=(int)($comparison['error_regressions'] ?? 0)>0 ? 'error' : 'warning';
			$add($level, 'Regression versus previous capture', (string)($comparison['summary'] ?? 'This request changed from the previous matching capture.'), 'comparison', 'Open Comparison to see which metric changed and jump to the previous capture.', 9);
		}
		$error_events=is_array($errors['events'] ?? null) ? $errors['events'] : [];
		$severe=array_values(array_filter($error_events, static fn(array $event): bool => in_array((string)($event['severity'] ?? ''), ['fatal', 'error'], true)));
		if($severe!==[]){
			$event=$severe[array_key_last($severe)];
			$source=trim(basename((string)($event['file'] ?? '')).':'.(string)($event['line'] ?? ''), ':');
			$add('error', 'PHP error captured', self::shorten((string)($event['message'] ?? ''), 180).($source!=='' ? ' at '.$source : ''), 'php', 'Open Diagnostics for the PHP event, then inspect the source line from the stack.', 1);
		}
		elseif($error_events!==[]){
			$add('warning', 'PHP warnings captured', count($error_events).' PHP warning/notice event'.(count($error_events)===1 ? '' : 's').' were recorded.', 'php', 'Open Diagnostics and fix the first warning near the request origin.', 25);
		}
		if(!empty($response['is_json'])){
			if(empty($response['json_valid'])){
				$add('error', 'Invalid JSON response', (string)($response['json_error'] ?? 'The response could not be decoded.'), 'response', 'Open Response Audit and inspect the raw JSON preview or upstream error copy.', 6);
			}
			elseif((int)($response['json_failure_count'] ?? 0)>0){
				$markers=is_array($response['json_failure_markers'] ?? null) ? $response['json_failure_markers'] : [];
				$first=is_array($markers[0] ?? null) ? $markers[0] : [];
				$add('error', 'API failure marker', self::shorten((string)($first['path'] ?? '$').' = '.(string)($first['value'] ?? ''), 180), 'response', 'Open Response Audit and inspect the failing batch route or marker path.', 7);
			}
		}
		$client_api_failures=(int)($client['client_http_errors'] ?? 0) + (int)($client['client_fetch_errors'] ?? 0);
		if($client_api_failures>0){
			$linked=(int)($client['linked_server_events'] ?? 0);
			$add('error', 'Browser API calls failed', $client_api_failures.' failed fetch/XHR event'.($client_api_failures===1 ? '' : 's').($linked>0 ? ', '.$linked.' linked to server snapshots.' : '.'), 'browser', 'Open Browser Events and follow any linked server snapshot for the backend side.', 8);
		}
		$accessibility_issues=(int)($client['accessibility_issues'] ?? 0);
		$accessibility_adjustments=(int)($client['accessibility_adjustments'] ?? 0);
		if($accessibility_issues>0){
			$add('warning', 'Panel accessibility policies need attention', $accessibility_issues.' field'.($accessibility_issues===1 ? '' : 's').' reported usable width, contrast, touch target, label, or adornment issues.', 'accessibility', 'Open Browser Events and inspect the Accessibility metric and field-level issue rows.', 13);
		}
		elseif($accessibility_adjustments>0){
			$add('info', 'Panel accessibility policies adjusted layout', $accessibility_adjustments.' field'.($accessibility_adjustments===1 ? '' : 's').' were expanded, stacked, or otherwise adjusted by policy.', 'accessibility', 'Open Browser Events to verify the automatic field layout adjustments.', 66);
		}
		$production_replay=is_array($client['production_replay'] ?? null) ? $client['production_replay'] : [];
		if($production_replay!==[]){
			$replay_status=(int)($production_replay['response_status'] ?? 0);
			$replay_responded=(int)($production_replay['replay_responded'] ?? 0)===1 || $replay_status>0;
			$write_blocks=(int)($production_replay['replay_write_blocks'] ?? 0);
			$replay_server_ms=(float)($production_replay['server_duration_ms'] ?? 0);
			if((int)($production_replay['replay_verified'] ?? 0)!==1 && $replay_responded===true){
				$add('warning', 'Production replay responded without metrics', 'HTTP '.$replay_status.' returned, but Dataphyre replay headers were missing.', 'replay', 'Open Browser Events and confirm the signed replay reached the Dataphyre bootstrap before output was sent.', 11);
			}
			elseif((int)($production_replay['replay_verified'] ?? 0)!==1){
				$add('warning', 'Production replay did not return an HTTP response', 'The browser could not read a replay response for this request.', 'replay', 'Open Browser Events and check whether the replay request was blocked by the browser or connection layer.', 11);
			}
			elseif($replay_status>=500){
				$add('error', 'Production replay failed', 'Read-only production replay returned HTTP '.$replay_status.'.', 'replay', 'Open Browser Events for replay status, server time, memory, and blocked writes.', 11);
			}
			elseif($replay_status>=400){
				$add('warning', 'Production replay did not succeed', 'Read-only production replay returned HTTP '.$replay_status.'.', 'replay', 'Open Browser Events and compare it with the debug request.', 22);
			}
			if($write_blocks>0){
				$add('warning', 'Replay hit write paths', $write_blocks.' SQL/cache mutation'.($write_blocks===1 ? ' was' : 's were').' blocked to keep the replay read-only.', 'replay', 'Open Browser Events and SQL Flight Recorder to identify request code that writes during render.', 23);
			}
			if($replay_server_ms>0 && $duration_ms>0){
				$delta=$duration_ms - $replay_server_ms;
				if(abs($delta)>=50.0){
					$direction=$delta>0 ? 'slower than' : 'faster than';
					$add('info', 'Production replay timing differs', 'Debug request was '.self::format_ms(abs($delta)).' '.$direction.' the production-mode replay.', 'replay', 'Use this as the production-like baseline before optimizing debug-only overhead.', 65);
				}
			}
		}
		$js_failures=(int)($client['js_errors'] ?? 0) + (int)($client['unhandled_rejections'] ?? 0);
		if($js_failures>0){
			$add('error', 'Browser JavaScript failed', $js_failures.' JavaScript error/rejection event'.($js_failures===1 ? '' : 's').' were reported.', 'browser', 'Open Browser Events and fix the first script error before chasing layout symptoms.', 12);
		}
		$asset_failures=(int)($client['resource_errors'] ?? 0) + (int)($client['stylesheet_missing'] ?? 0) + (int)($response['missing_asset_count'] ?? 0);
		if($asset_failures>0){
			$add('warning', 'Assets are not loading cleanly', $asset_failures.' asset signal'.($asset_failures===1 ? '' : 's').' were found across response and browser checks.', 'assets', 'Open Response Audit for filesystem resolution and Browser Events for actual load failures.', 18);
		}
		if((int)($sql['failed_events'] ?? 0)>0){
			$add('error', 'SQL operation failed', (int)$sql['failed_events'].' SQL event'.((int)$sql['failed_events']===1 ? '' : 's').' reported a failed result.', 'sql', 'Open SQL Flight Recorder and inspect the failed event statement and callsite.', 10);
		}
		if((int)($sql['slow_events'] ?? 0)>0){
			$add('warning', 'Slow SQL is present', (int)$sql['slow_events'].' SQL execution'.((int)$sql['slow_events']===1 ? '' : 's').' crossed the slow threshold.', 'sql', 'Open SQL Flight Recorder and start with the slowest query table.', 35);
		}
		$sql_insights=is_array($sql['insights'] ?? null) ? $sql['insights'] : [];
		foreach($sql_insights as $insight){
			if(!is_array($insight) || !in_array((string)($insight['level'] ?? ''), ['warning', 'error'], true)){
				continue;
			}
			$add(
				(string)$insight['level'],
				(string)($insight['title'] ?? 'SQL insight'),
				(string)($insight['detail'] ?? ''),
				'sql',
				(string)($insight['next'] ?? 'Open SQL Flight Recorder and inspect the derived insight.'),
				30
			);
			break;
		}
		$duplicates=is_array($sql['duplicates'] ?? null) ? $sql['duplicates'] : [];
		if($duplicates!==[]){
			$extra=array_sum(array_map(static fn(array $group): int => max(0, (int)($group['count'] ?? 0) - 1), $duplicates));
			$add('warning', 'Repeated SQL shape', count($duplicates).' repeated shape group'.(count($duplicates)===1 ? '' : 's').' produced '.$extra.' extra execution'.($extra===1 ? '' : 's').'.', 'sql', 'Open SQL Flight Recorder and collapse the repeated query into a batch or cache-backed lookup.', 32);
		}
		$page=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		$page_load_ms=(float)($page['load_ms'] ?? 0);
		if($page_load_ms>=8000){
			$add('error', 'Page load is very slow', 'Browser load completed in '.self::format_ms($page_load_ms).'.', 'browser', 'Open Timeline to compare server time, DOM timing, and slow resource events.', 14);
		}
		elseif($page_load_ms>=3000){
			$add('warning', 'Page load is slow', 'Browser load completed in '.self::format_ms($page_load_ms).'.', 'browser', 'Open Timeline to see whether the delay is server, DOM, or resources.', 36);
		}
		$resource_summary=is_array($client['resource_summary'] ?? null) ? $client['resource_summary'] : [];
		if($resource_summary!==[]){
			$total_transfer=(int)($resource_summary['total_transfer_size'] ?? 0);
			$max_duration=(float)($resource_summary['max_duration_ms'] ?? 0);
			if($total_transfer>=5242880){
				$add($total_transfer>=15728640 ? 'error' : 'warning', 'Browser resources are heavy', 'Sampled resources transferred '.self::format_bytes($total_transfer).'.', 'browser', 'Open Browser Events and inspect Resource Timing by type and slowest resources.', 26);
			}
			if($max_duration>=3000){
				$add($max_duration>=8000 ? 'error' : 'warning', 'One resource dominates load time', 'The slowest sampled resource took '.self::format_ms($max_duration).'.', 'browser', 'Open Browser Events and inspect the slowest Resource Timing row.', 24);
			}
		}
		if((int)($response['mojibake_count'] ?? 0)>0){
			$add('warning', 'Encoding looks damaged', (int)$response['mojibake_count'].' mojibake-like sequence'.((int)$response['mojibake_count']===1 ? '' : 's').' appeared in the response.', 'response', 'Open Response Audit and check charset plus upstream text sources.', 28);
		}
		$suspicious_phrases=is_array($response['suspicious_phrases'] ?? null) ? $response['suspicious_phrases'] : [];
		if($suspicious_phrases!==[]){
			$add('warning', 'Error copy reached the response', 'Matched: '.implode(', ', array_slice($suspicious_phrases, 0, 3)).'.', 'response', 'Open Response Audit and find which fallback rendered the message.', 16);
		}
		foreach((is_array($diagnostics['findings'] ?? null) ? $diagnostics['findings'] : []) as $finding){
			if(!is_array($finding)){
				continue;
			}
			$level=(string)($finding['level'] ?? 'info');
			$title=(string)($finding['title'] ?? '');
			if($title===''){
				continue;
			}
			$add($level, $title, (string)($finding['detail'] ?? ''), (string)($finding['source'] ?? 'runtime'), 'Open the related panel below for the source evidence.', $level==='error' ? 40 : 60);
		}
		$order=['fatal'=>0, 'error'=>1, 'warning'=>2, 'info'=>3, 'ok'=>4];
		usort($items, static function(array $a, array $b)use($order): int{
			$severity=($order[(string)($a['level'] ?? 'info')] ?? 3)<=>($order[(string)($b['level'] ?? 'info')] ?? 3);
			return $severity!==0 ? $severity : ((int)($a['priority'] ?? 50)<=> (int)($b['priority'] ?? 50));
		});
		return $items;
	}

	/**
	 * Renders reference links attached to a triage item.
	 *
	 * @param array{level?:string,title?:string,detail?:string,source?:string,next?:string} $item Triage item row used to infer stable target-panel links.
	 * @return string Reference-link HTML.
	 */
	private static function triage_reference_links(array $item): string {
		$targets=[];
		$add=function(string $target)use(&$targets): void{
			$target=trim($target);
			if($target!=='' && !isset($targets[$target])){
				$targets[$target]=self::panel_label($target);
			}
		};
		$source=(string)($item['source'] ?? '');
		$primary=self::triage_panel_target($source, (string)($item['title'] ?? ''), (string)($item['next'] ?? ''));
		$add($primary);
		$text=strtolower(implode(' ', [
			$source,
			(string)($item['title'] ?? ''),
			(string)($item['detail'] ?? ''),
			(string)($item['next'] ?? ''),
		]));
		$phrases=[
			'diagnostics'=>'diagnostics',
			'php'=>'diagnostics',
			'comparison'=>'comparison',
			'compare'=>'comparison',
			'sql'=>'sql',
			'query'=>'sql',
			'cache'=>'sql',
			'timeline'=>'timeline',
			'performance'=>'timeline',
			'reactor'=>'reactor',
			'reactive'=>'reactor',
			'cdn server'=>'cdn-server',
			'cdn'=>'cdn-server',
			'routing'=>'routing',
			'route'=>'routing',
			'request'=>'request',
			'status'=>'request',
			'response audit'=>'response',
			'response'=>'response',
			'json'=>'response',
			'asset'=>'response',
			'browser events'=>'browser',
			'browser'=>'browser',
			'client'=>'browser',
			'javascript'=>'browser',
			'accessibility'=>'accessibility',
			'a11y'=>'accessibility',
			'replay'=>'browser',
			'template'=>'templating',
			'templating'=>'templating',
			'tracelog'=>'tracelog',
			'trace'=>'tracelog',
			'panel'=>'panel',
			'resource'=>'panel',
			'form'=>'panel',
			'action'=>'panel',
			'runtime'=>'runtime',
			'memory'=>'runtime',
		];
		foreach($phrases as $phrase=>$target){
			if(str_contains($text, $phrase)){
				$add($target);
			}
		}
		$html='';
		foreach(array_slice($targets, 0, 4, true) as $target=>$label){
			$html.=self::triage_panel_link($label, (string)$target);
		}
		return $html;
	}

	/**
	 * Chooses the most relevant panel target for a triage item.
	 *
	 * @param string $source Triage source key.
	 * @param string $title Triage item title.
	 * @param string $next Suggested next-step text.
	 * @return string Panel target identifier.
	 */
	private static function triage_panel_target(string $source, string $title='', string $next=''): string {
		$text=strtolower(trim($source.' '.$title.' '.$next));
		foreach([
			'php'=>'diagnostics',
			'diagnostic'=>'diagnostics',
			'comparison'=>'comparison',
			'compare'=>'comparison',
			'sql'=>'sql',
			'query'=>'sql',
			'cache'=>'sql',
			'timeline'=>'timeline',
			'performance'=>'timeline',
			'cdn server'=>'cdn-server',
			'cdn'=>'cdn-server',
			'route'=>'routing',
			'routing'=>'routing',
			'request'=>'request',
			'status'=>'request',
			'response'=>'response',
			'asset'=>'response',
			'json'=>'response',
			'mojibake'=>'response',
			'browser'=>'browser',
			'client'=>'browser',
			'javascript'=>'browser',
			'accessibility'=>'accessibility',
			'a11y'=>'accessibility',
			'replay'=>'browser',
			'template'=>'templating',
			'tracelog'=>'tracelog',
			'trace'=>'tracelog',
			'runtime'=>'runtime',
			'memory'=>'runtime',
		] as $needle=>$target){
			if(str_contains($text, $needle)){
				return $target;
			}
		}
		return 'runtime';
	}

	/**
	 * Renders a navigation link from triage to a debugbar panel.
	 *
	 * @param string $label Link label.
	 * @param string $target Panel target identifier.
	 * @return string Anchor HTML.
	 */
	private static function triage_panel_link(string $label, string $target): string {
		$target=self::valid_panel_target($target);
		return '<a class="dfd-ref" href="#dfd-panel-'.self::e($target).'" data-dfd-panel-target="'.self::e($target).'">'.self::e($label).'</a>';
	}

	/**
	 * Normalizes a panel target to a registered debugbar panel.
	 *
	 * @param string $target Candidate panel target.
	 * @return string Registered panel target, or runtime as the fallback.
	 */
	private static function valid_panel_target(string $target): string {
		$target=strtolower(trim($target));
		return in_array($target, self::panel_targets(), true) ? $target : 'runtime';
	}

	/**
	 * Returns the canonical debugbar panel targets.
	 *
	 * @return list<string> Panel target identifiers.
	 */
	private static function panel_targets(): array {
		return ['triage', 'comparison', 'diagnostics', 'sql', 'timeline', 'tracelog', 'routing', 'panel', 'reactor', 'cdn-server', 'request', 'response', 'browser', 'accessibility', 'templating', 'runtime'];
	}

	/**
	 * Resolves a display label for a debugbar panel target.
	 *
	 * @param string $target Panel target identifier.
	 * @return string Display label.
	 */
	private static function panel_label(string $target): string {
		return match(self::valid_panel_target($target)){
			'comparison'=>'Comparison',
			'diagnostics'=>'Diagnostics',
			'sql'=>'SQL',
			'timeline'=>'Timeline',
			'tracelog'=>'Tracelog',
			'routing'=>'Routing',
			'panel'=>'Panel',
			'reactor'=>'Reactor',
			'cdn-server'=>'Asset Node',
			'request'=>'Request',
			'response'=>'Response',
			'browser'=>'Browser',
			'templating'=>'Templating',
			'triage'=>'Triage',
			default=>'Runtime',
		};
	}

	/**
	 * Builds request-phase summaries for the triage panel.
	 *
	 * @param array{duration_ms?:int|float,sql?:array{total_duration_ms?:int|float,execute_events?:int},timeline?:array{duration_ms?:int|float}} $snapshot Snapshot payload with server timing evidence.
	 * @param array{page_performance?:array{first_byte_ms?:int|float,dom_content_loaded_ms?:int|float,load_ms?:int|float,resource_count?:int},production_replay?:array{server_duration_ms?:int|float,replay_write_blocks?:int}} $client Client-side telemetry state with browser timing and production-replay evidence.
	 * @return list<array{label:string,duration_ms:float,detail:string,tone:string}> Phase summary rows rendered into timing bars.
	 */
	private static function triage_phases(array $snapshot, array $client): array {
		$sql=is_array($snapshot['sql'] ?? null) ? $snapshot['sql'] : [];
		$timeline=is_array($snapshot['timeline'] ?? null) ? $snapshot['timeline'] : [];
		$page=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		$duration_ms=(float)($snapshot['duration_ms'] ?? $timeline['duration_ms'] ?? 0);
		$sql_ms=(float)($sql['total_duration_ms'] ?? 0);
		$phases=[];
		if($duration_ms>0){
			$phases[]=[
				'label'=>'Server request',
				'duration_ms'=>$duration_ms,
				'detail'=>'PHP, routing, modules, and response rendering',
				'tone'=>$duration_ms>=3000 ? 'bad' : ($duration_ms>=1000 ? 'warn' : ''),
			];
		}
		if($sql_ms>0){
			$share=$duration_ms>0 ? ($sql_ms / max(1.0, $duration_ms)) : 0.0;
			$phases[]=[
				'label'=>'SQL time',
				'duration_ms'=>$sql_ms,
				'detail'=>round($share * 100, 1).'% of server time across '.(int)($sql['execute_events'] ?? 0).' executions',
				'tone'=>$sql_ms>=500 || $share>=0.6 ? 'bad' : ($sql_ms>=100 || $share>=0.35 ? 'warn' : ''),
			];
		}
		$production_replay=is_array($client['production_replay'] ?? null) ? $client['production_replay'] : [];
		$replay_server_ms=(float)($production_replay['server_duration_ms'] ?? 0);
		if($replay_server_ms>0){
			$write_blocks=(int)($production_replay['replay_write_blocks'] ?? 0);
			$detail='Production mode, read-only replay';
			if($duration_ms>0){
				$delta=$duration_ms - $replay_server_ms;
				$detail.=' / '.($delta>=0 ? 'debug +'.self::format_ms(abs($delta)) : 'debug -'.self::format_ms(abs($delta)));
			}
			if($write_blocks>0){
				$detail.=' / '.$write_blocks.' write block'.($write_blocks===1 ? '' : 's');
			}
			$phases[]=[
				'label'=>'Production replay',
				'duration_ms'=>$replay_server_ms,
				'detail'=>$detail,
				'tone'=>$write_blocks>0 ? 'warn' : '',
			];
		}
		$first_byte_ms=(float)($page['first_byte_ms'] ?? 0);
		$dom_ms=(float)($page['dom_content_loaded_ms'] ?? 0);
		$load_ms=(float)($page['load_ms'] ?? 0);
		if($first_byte_ms>0){
			$gap=$duration_ms>0 ? max(0.0, $first_byte_ms - $duration_ms) : 0.0;
			$phases[]=[
				'label'=>'Browser first byte',
				'duration_ms'=>$first_byte_ms,
				'detail'=>$gap>0 ? 'Approx. '.self::format_ms($gap).' outside measured PHP time' : 'Response started within measured server time',
				'tone'=>$first_byte_ms>=3000 ? 'bad' : ($first_byte_ms>=1000 ? 'warn' : ''),
			];
		}
		if($dom_ms>0){
			$phases[]=[
				'label'=>'DOM ready',
				'duration_ms'=>$dom_ms,
				'detail'=>'Browser parsed the document and ran blocking work',
				'tone'=>$dom_ms>=5000 ? 'bad' : ($dom_ms>=2000 ? 'warn' : ''),
			];
		}
		if($load_ms>0){
			$resource_count=(int)($page['resource_count'] ?? 0);
			$phases[]=[
				'label'=>'Page complete',
				'duration_ms'=>$load_ms,
				'detail'=>$resource_count.' resource'.($resource_count===1 ? '' : 's').' observed by the browser',
				'tone'=>$load_ms>=8000 ? 'bad' : ($load_ms>=3000 ? 'warn' : ''),
			];
		}
		return $phases;
	}

	/**
	 * Renders snapshot comparison changes when a prior snapshot is available.
	 *
	 * @param array{comparison?:array{available?:bool,changes?:list<array<string,mixed>>,regressions?:int,error_regressions?:int,improvements?:int,previous_id?:string,previous_label?:string,previous_recorded_at?:int,summary?:string}} $snapshot Snapshot payload with optional previous-capture comparison data.
	 * @return string Comparison panel HTML.
	 */
	private static function render_comparison_panel(array $snapshot): string {
		$comparison=is_array($snapshot['comparison'] ?? null) ? $snapshot['comparison'] : [];
		if($comparison===[] || empty($comparison['available'])){
			return '';
		}
		$changes=is_array($comparison['changes'] ?? null) ? $comparison['changes'] : [];
		$regressions=(int)($comparison['regressions'] ?? 0);
		$error_regressions=(int)($comparison['error_regressions'] ?? 0);
		$improvements=(int)($comparison['improvements'] ?? 0);
		$previous_id=(string)($comparison['previous_id'] ?? '');
		$previous_label=(string)($comparison['previous_label'] ?? 'previous request');
		$previous_link=$previous_id!=='' ? self::history_link($previous_id, $previous_label) : self::e($previous_label);
		$duration_change=self::comparison_change_by_key($changes, 'duration_ms');
		$sql_change=self::comparison_change_by_key($changes, 'sql_queries') ?? self::comparison_change_by_key($changes, 'sql_time_ms');
		$browser_change=self::comparison_change_by_key($changes, 'browser_load_ms') ?? self::comparison_change_by_key($changes, 'browser_events');
		$html='<details id="dfd-panel-comparison" class="dfd-panel" data-dfd-panel="comparison"'.($regressions>0 ? ' open' : '').'><summary><span>Comparison</span><span class="dfd-muted">'.self::e((string)($comparison['summary'] ?? 'previous capture')).'</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Previous', date('H:i:s', (int)($comparison['previous_recorded_at'] ?? time())), $previous_label)
			.self::metric('Regressions', (string)$regressions, $error_regressions.' severe / '.$improvements.' improved')
			.self::metric('Server Delta', is_array($duration_change) ? (string)($duration_change['delta_label'] ?? '') : 'same', is_array($duration_change) ? 'Was '.(string)($duration_change['previous_label'] ?? '') : 'No server timing change')
			.self::metric('Browser Delta', is_array($browser_change) ? (string)($browser_change['delta_label'] ?? '') : 'same', is_array($browser_change) ? (string)($browser_change['label'] ?? 'browser') : 'No browser timing change')
			.'</div>';
		$html.='<p class="dfd-muted">Compared with '.$previous_link.'.</p>';
		if($changes===[]){
			return $html.'<p class="dfd-muted">No measurable change from the previous capture.</p></div></details>';
		}
		$html.='<table class="dfd-table"><thead><tr><th>Metric</th><th>Previous</th><th>Current</th><th>Delta</th><th>Read</th></tr></thead><tbody>';
		foreach(array_slice($changes, 0, 14) as $change){
			$tone=(string)($change['tone'] ?? '');
			$direction=(string)($change['direction'] ?? '');
			$read=$tone==='bad' ? 'worse' : ($tone==='warn' ? 'slower or noisier' : ($tone==='good' ? 'better' : $direction));
			$html.='<tr>'
				.'<td>'.self::e((string)($change['label'] ?? '')).'</td>'
				.'<td>'.self::e((string)($change['previous_label'] ?? '')).'</td>'
				.'<td>'.self::e((string)($change['current_label'] ?? '')).'</td>'
				.'<td>'.self::pill(self::e((string)($change['delta_label'] ?? '')), $tone).'</td>'
				.'<td>'.self::e($read).'</td>'
				.'</tr>';
		}
		$html.='</tbody></table>';
		if(is_array($sql_change)){
			$html.='<p class="dfd-muted">SQL shifted by '.self::e((string)($sql_change['delta_label'] ?? '')).' on '.self::e((string)($sql_change['label'] ?? 'SQL')).'.</p>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Finds one comparison change row by key.
	 *
	 * @param list<array{key?:string,label?:string,previous_label?:string,current_label?:string,delta_label?:string,tone?:string,direction?:string}> $changes Snapshot comparison change rows.
	 * @param string $key Change key to locate.
	 * @return array{key?:string,label?:string,previous_label?:string,current_label?:string,delta_label?:string,tone?:string,direction?:string}|null Matching change row.
	 */
	private static function comparison_change_by_key(array $changes, string $key): ?array {
		foreach($changes as $change){
			if(is_array($change) && (string)($change['key'] ?? '')===$key){
				return $change;
			}
		}
		return null;
	}

	/**
	 * Merges browser telemetry events into the server timeline payload.
	 *
	 * @param array{events?:list<array<string,mixed>>,duration_ms?:int|float} $timeline Server timeline payload.
	 * @param array{page_performance?:array<string,mixed>,events?:list<array<string,mixed>>,resource_timing?:array<string,mixed>} $client Client-side telemetry state used to append browser milestones.
	 * @return array{events:list<array<string,mixed>>,duration_ms?:int|float} Timeline payload with additional client events.
	 */
	private static function timeline_with_client_events(array $timeline, array $client): array {
		$events=[];
		foreach((is_array($timeline['events'] ?? null) ? $timeline['events'] : []) as $event){
			if(is_array($event)){
				$event['source']=$event['source'] ?? 'server';
				$events[]=$event;
			}
		}
		$page=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		if($page!==[]){
			$first_byte_ms=(float)($page['first_byte_ms'] ?? 0);
			$dom_ms=(float)($page['dom_content_loaded_ms'] ?? 0);
			$load_ms=(float)($page['load_ms'] ?? 0);
			if($first_byte_ms>0){
				$events[]=self::client_timeline_event($first_byte_ms, 'browser', 'First byte received', '', '');
			}
			if($dom_ms>0){
				$events[]=self::client_timeline_event($dom_ms, 'browser', 'DOM ready', '', '');
			}
			if($load_ms>0){
				$tone=$load_ms>=8000 ? 'bad' : ($load_ms>=3000 ? 'warn' : '');
				$events[]=self::client_timeline_event($load_ms, 'browser', 'Page load complete', (string)((int)($page['resource_count'] ?? 0)).' resources', $tone);
			}
		}
		$page_load_ms=(float)($page['load_ms'] ?? 0);
		$request_duration_ms=(float)($timeline['duration_ms'] ?? 0);
		foreach((is_array($client['events'] ?? null) ? $client['events'] : []) as $event){
			if(!is_array($event)){
				continue;
			}
			$type=(string)($event['type'] ?? 'client_event');
			if($type==='page_performance'){
				continue;
			}
			$offset=(float)($event['start_time_ms'] ?? 0);
			if($offset<=0 || $offset>86400000){
				$offset=$page_load_ms>0 ? $page_load_ms : $request_duration_ms;
			}
			$label=match($type){
				'resource_error'=>'Browser resource failed',
				'stylesheet_missing'=>'Stylesheet missing',
				'js_error'=>'JavaScript error',
				'unhandled_rejection'=>'Unhandled promise rejection',
				'client_http_error'=>'Browser API error',
				'client_fetch_error'=>'Browser fetch failed',
				'client_http_slow'=>'Slow browser API request',
				'slow_resource'=>'Slow browser resource',
				'resource_timing'=>'Resource timing',
				'production_replay'=>'Production replay',
				'accessibility_policy'=>'Panel accessibility policy',
				default=>'Browser event',
			};
			$detail=(string)($event['url'] ?? $event['source'] ?? $event['message'] ?? '');
			if(trim($detail)===''){
				$detail=(string)($event['message'] ?? '');
			}
			if($type==='production_replay'){
				$detail=trim('HTTP '.(string)($event['response_status'] ?? 0).' / server '.self::format_ms((float)($event['server_duration_ms'] ?? 0)).' / app peak '.(string)($event['replay_peak_mb'] ?? 0).'mb');
				if((float)($event['replay_debug_overhead_mb'] ?? 0)>0){
					$detail.=' / debug overhead excluded '.(float)$event['replay_debug_overhead_mb'].'mb';
				}
			}
			if($type==='accessibility_policy'){
				$detail=(int)($event['a11y_issue_count'] ?? 0).' issue fields / '.(int)($event['a11y_adjustment_count'] ?? 0).' adjusted fields';
			}
			$tone=self::level_tone((string)($event['level'] ?? ''));
			if($tone==='' && in_array($type, ['client_http_slow', 'slow_resource'], true)){
				$tone='warn';
			}
			if($type==='resource_timing'){
				$status=(int)($event['response_status'] ?? 0);
				$event_duration=(float)($event['duration_ms'] ?? 0);
				$tone=$status>=400 ? 'bad' : ($event_duration>=1000 ? 'warn' : $tone);
			}
			$events[]=self::client_timeline_event(
				$offset,
				$type==='production_replay' ? 'browser-replay' : ($type==='accessibility_policy' ? 'accessibility' : (str_starts_with($type, 'client_') ? 'browser-api' : (in_array($type, ['slow_resource', 'resource_timing', 'resource_error', 'stylesheet_missing'], true) ? 'browser-resource' : 'browser'))),
				$label,
				self::shorten($detail, 180),
				$tone,
				(float)($event['duration_ms'] ?? 0)
			);
		}
		usort($events, static function(array $a, array $b): int {
			$start=((float)($a['start_offset_ms'] ?? $a['offset_ms'] ?? 0))<=>((float)($b['start_offset_ms'] ?? $b['offset_ms'] ?? 0));
			return $start!==0 ? $start : ((float)($b['duration_ms'] ?? 0))<=>((float)($a['duration_ms'] ?? 0));
		});
		return $events;
	}

	/**
	 * Builds a normalized client timeline event row.
	 *
	 * @param float $offset_ms Event offset from request start.
	 * @param string $type Client event type.
	 * @param string $label Display label.
	 * @param string $detail Optional detail text.
	 * @param string $tone Optional severity tone.
	 * @param float $duration_ms Optional event duration.
	 * @return array{offset_ms:float,type:string,label:string,detail:string,tone:string,duration_ms:float,source:string} Timeline event row.
	 */
	private static function client_timeline_event(float $offset_ms, string $type, string $label, string $detail='', string $tone='', float $duration_ms=0.0): array {
		$offset_ms=max(0.0, $offset_ms);
		$duration_ms=max(0.0, $duration_ms);
		return [
			'offset_ms'=>round($offset_ms, 3),
			'start_offset_ms'=>round($offset_ms, 3),
			'end_offset_ms'=>round($offset_ms + $duration_ms, 3),
			'type'=>$type,
			'label'=>$label,
			'detail'=>$detail,
			'tone'=>$tone,
			'duration_ms'=>round($duration_ms, 3),
			'source'=>'client',
		];
	}

	/**
	 * Renders request timeline events and client telemetry overlays.
	 *
	 * @param array{events?:list<array<string,mixed>>,duration_ms?:int|float} $timeline Timeline payload containing server-side request events.
	 * @param array{events?:list<array<string,mixed>>,page_performance?:array<string,mixed>,resource_timing?:array<string,mixed>} $client Client-side telemetry state appended to the rendered timeline.
	 * @return string Timeline panel HTML.
	 */
	private static function render_timeline_panel(array $timeline, array $client=[]): string {
		$server_events=is_array($timeline['events'] ?? null) ? $timeline['events'] : [];
		$events=self::timeline_with_client_events($timeline, $client);
		$duration=max(1.0, (float)($timeline['duration_ms'] ?? 1.0));
		foreach($events as $event){
			$duration=max($duration, (float)($event['end_offset_ms'] ?? ((float)($event['offset_ms'] ?? 0) + (float)($event['duration_ms'] ?? 0))));
		}
		$html='<details id="dfd-panel-timeline" class="dfd-panel" data-dfd-panel="timeline"><summary><span>Timeline</span><span class="dfd-muted">'.self::e((string)count($events)).' events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Waterfall', self::format_ms($duration), 'Server plus browser-side timing')
			.self::metric('Events', (string)count($events), count($server_events).' server / '.max(0, count($events) - count($server_events)).' browser')
			.self::metric('First Event', isset($events[0]) ? (string)($events[0]['label'] ?? '') : 'none', 'Earliest captured marker')
			.self::metric('Last Event', isset($events[array_key_last($events)]) ? (string)($events[array_key_last($events)]['label'] ?? '') : 'none', 'Latest captured marker')
			.'</div>';
		if($events===[]){
			return $html.'<p class="dfd-muted">No timeline events captured.</p></div></details>';
		}
		$html.='<div class="dfd-timeline">';
		foreach(array_slice($events, 0, 48) as $event){
			$offset=(float)($event['start_offset_ms'] ?? $event['offset_ms'] ?? 0);
			$event_duration=(float)($event['duration_ms'] ?? 0);
			$left=max(0.0, min(100.0, ($offset / $duration) * 100));
			$width=$event_duration>0 ? max(0.6, min(100.0 - $left, ($event_duration / $duration) * 100)) : 0.6;
			$tone=(string)($event['tone'] ?? '');
			$duration_label=$event_duration>0 ? ' - '.self::format_ms($event_duration) : '';
			$html.='<div class="dfd-tick">'
				.'<span class="dfd-muted">'.self::format_ms($offset).'</span>'
				.self::pill(self::e((string)($event['type'] ?? 'event')), $tone)
				.'<div><div class="dfd-range-track"><div class="dfd-range-bar'.($tone!=='' ? ' dfd-'.$tone : '').'" style="left:'.self::e((string)round($left, 2)).'%;width:'.self::e((string)round($width, 2)).'%"></div></div>'
				.'<div><b>'.self::e((string)($event['label'] ?? '')).'</b><span class="dfd-muted">'.$duration_label.' '.self::e(self::shorten((string)($event['detail'] ?? ''), 180)).'</span></div></div>'
				.'</div>';
		}
		if(count($events)>48){
			$html.='<p class="dfd-muted">Showing 48 of '.self::e((string)count($events)).' timeline events.</p>';
		}
		return $html.'</div></div></details>';
	}

	/**
	 * Renders routing snapshot information.
	 *
	 * @param array{matched_route?:string,request_path?:string,target_file?:string,controller?:string,action?:string,params?:array<string,mixed>,checks?:list<array<string,mixed>>} $routing Routing debugbar state captured during request dispatch.
	 * @return string Routing panel HTML.
	 */
	private static function render_routing_panel(array $routing): string {
		$bindings=is_array($routing['bindings'] ?? null) ? $routing['bindings'] : [];
		$html='<details id="dfd-panel-routing" class="dfd-panel" data-dfd-panel="routing"><summary><span>Routing</span><span class="dfd-muted">'.self::e((string)($routing['request_path'] ?? '')).'</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Route', (string)($routing['matched_route'] ?? 'unmatched'), 'Matched pattern')
			.self::metric('Target', basename((string)($routing['matched_file'] ?? '')), (string)($routing['matched_file'] ?? ''))
			.self::metric('Non-matches', (string)($routing['non_match_count'] ?? 0), 'Routes skipped before match')
			.self::metric('Bindings', (string)count($bindings), 'Captured parameters')
			.'</div>';
		if($bindings!==[]){
			$html.='<pre class="dfd-code">'.self::e(self::json($bindings)).'</pre>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders Panel lifecycle events, resources, pages, widgets, and actions.
	 *
	 * @param array{available?:bool,events?:list<array<string,mixed>>,resources?:list<array<string,mixed>>,current_page?:string,current_resource?:string} $panel Panel debugbar state for Panel lifecycle and resource resolution.
	 * @return string Panel lifecycle panel HTML.
	 */
	private static function render_panel_lifecycle_panel(array $panel): string {
		if(empty($panel['available'])){
			return '';
		}
		$loaded=!empty($panel['loaded']);
		$events=is_array($panel['events'] ?? null) ? $panel['events'] : [];
		$resources=is_array($panel['resources'] ?? null) ? $panel['resources'] : [];
		$pages=is_array($panel['pages'] ?? null) ? $panel['pages'] : [];
		$widgets=is_array($panel['widgets'] ?? null) ? $panel['widgets'] : [];
		$actions=is_array($panel['actions'] ?? null) ? $panel['actions'] : [];
		$category_counts=is_array($panel['category_counts'] ?? null) ? $panel['category_counts'] : [];
		$operation_counts=is_array($panel['operation_counts'] ?? null) ? $panel['operation_counts'] : [];
		$insights=is_array($panel['insights'] ?? null) ? $panel['insights'] : [];
		$html='<details id="dfd-panel-panel" class="dfd-panel" data-dfd-panel="panel"'.($insights!==[] ? ' open' : '').'><summary><span>Panel</span><span class="dfd-muted">'.self::e((string)count($events)).' lifecycle events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Framework', $loaded ? 'loaded' : 'available', $loaded ? 'Panel classes are active in this request' : 'Panel module exists but has not been booted')
			.self::metric('Resources', (string)count($resources), count($actions).' actions / '.count($widgets).' widgets')
			.self::metric('Pages', (string)count($pages), count(is_array($panel['navigation'] ?? null) ? $panel['navigation'] : []).' navigation entries')
			.self::metric('Events', (string)count($events), count($category_counts).' lifecycle categories')
			.'</div>';
		if(!$loaded){
			return $html.'<p class="dfd-muted">No Panel classes were loaded during this request. This is normal for non-Panel pages.</p></div></details>';
		}
		if($insights!==[]){
			$html.='<table class="dfd-table"><thead><tr><th>Level</th><th>Finding</th><th>Detail</th></tr></thead><tbody>';
			foreach($insights as $insight){
				if(!is_array($insight)){
					continue;
				}
				$html.='<tr><td>'.self::pill(self::e((string)($insight['level'] ?? 'info')), self::level_tone((string)($insight['level'] ?? 'info'))).'</td><td>'.self::e((string)($insight['title'] ?? '')).'</td><td>'.self::e((string)($insight['detail'] ?? '')).'</td></tr>';
			}
			$html.='</tbody></table>';
		}
		if($category_counts!==[] || $operation_counts!==[]){
			$html.='<div class="dfd-grid">';
			foreach(array_slice($category_counts, 0, 8, true) as $category=>$count){
				$html.=self::metric('Category', (string)$count, (string)$category);
			}
			foreach(array_slice($operation_counts, 0, 4, true) as $operation=>$count){
				$html.=self::metric('Operation', (string)$count, (string)$operation);
			}
			$html.='</div>';
		}
		if($events!==[]){
			$html.='<h3 class="dfd-muted">Lifecycle Trace</h3><table class="dfd-table"><thead><tr><th>When</th><th>Event</th><th>Resource</th><th>Operation</th><th>Result</th><th>Context</th></tr></thead><tbody>';
			foreach(array_reverse(array_slice($events, -80)) as $event){
				$time=(float)($event['time'] ?? 0);
				$duration=is_numeric($event['duration_ms'] ?? null) ? self::format_ms((float)$event['duration_ms']) : '';
				$status=is_numeric($event['status'] ?? null) ? (string)$event['status'] : $duration;
				$html.='<tr>'
					.'<td>'.self::e($time>0 ? date('H:i:s', (int)$time).sprintf('.%03d', ((int)($time*1000))%1000) : '').'</td>'
					.'<td><code>'.self::e((string)($event['event'] ?? 'event')).'</code><br><span class="dfd-muted">'.self::e((string)($event['category'] ?? '')).'</span></td>'
					.'<td>'.self::e((string)($event['resource'] ?? '')).'</td>'
					.'<td>'.self::e((string)($event['operation'] ?? '')).'</td>'
					.'<td>'.self::e($status).'</td>'
					.'<td><code>'.self::e(self::json(is_array($event['context'] ?? null) ? $event['context'] : [])).'</code></td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		if($resources!==[]){
			$html.='<h3 class="dfd-muted">Resources</h3><table class="dfd-table"><thead><tr><th>Name</th><th>Label</th><th>Source</th><th>Shape</th><th>Actions</th></tr></thead><tbody>';
			foreach($resources as $resource){
				$html.='<tr>'
					.'<td><code>'.self::e((string)($resource['name'] ?? '')).'</code></td>'
					.'<td>'.self::e((string)($resource['label'] ?? '')).'</td>'
					.'<td>'.self::e((string)($resource['source'] ?? '')).'</td>'
					.'<td>'.self::e((string)($resource['fields'] ?? 0)).' fields / '.self::e((string)($resource['columns'] ?? 0)).' columns / '.self::e((string)($resource['relations'] ?? 0)).' relations</td>'
					.'<td>'.self::e((string)($resource['actions'] ?? 0)).'</td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		if($pages!==[] || $widgets!==[] || $actions!==[]){
			$html.='<details class="dfd-stack"><summary>Registered Panel Shape <span>'.count($pages).' pages / '.count($widgets).' widgets / '.count($actions).' actions</span></summary>';
			$html.='<pre class="dfd-code">'.self::e(self::json([
				'pages'=>$pages,
				'widgets'=>$widgets,
				'actions'=>array_slice($actions, 0, 40),
				'theme'=>is_array($panel['theme'] ?? null) ? $panel['theme'] : [],
			])).'</pre></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders Reactor events, components, capabilities, and insights.
	 *
	 * @param array{available?:bool,components?:list<array<string,mixed>>,actions?:list<array<string,mixed>>,validations?:list<array<string,mixed>>,effects?:list<array<string,mixed>>,events?:list<array<string,mixed>>} $reactor Reactor debugbar state for component lifecycle and emitted effects.
	 * @return string Reactor panel HTML.
	 */
	private static function render_reactor_panel(array $reactor): string {
		if(empty($reactor['available'])){
			return '';
		}
		$loaded=!empty($reactor['loaded']);
		$events=is_array($reactor['events'] ?? null) ? $reactor['events'] : [];
		$components=is_array($reactor['components'] ?? null) ? $reactor['components'] : [];
		$capability_counts=is_array($reactor['capability_counts'] ?? null) ? $reactor['capability_counts'] : [];
		$event_counts=is_array($reactor['event_counts'] ?? null) ? $reactor['event_counts'] : [];
		$insights=is_array($reactor['insights'] ?? null) ? $reactor['insights'] : [];
		$manifest=is_array($reactor['manifest'] ?? null) ? $reactor['manifest'] : [];
		$html='<details id="dfd-panel-reactor" class="dfd-panel" data-dfd-panel="reactor"'.($insights!==[] ? ' open' : '').'><summary><span>Reactor</span><span class="dfd-muted">'.self::e((string)count($events)).' lifecycle events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Framework', $loaded ? 'loaded' : 'available', $loaded ? 'Reactor classes are active in this request' : 'Reactor module exists but has not been booted')
			.self::metric('Components', (string)count($components), 'Registered reactive component shapes')
			.self::metric('Capabilities', (string)count($capability_counts), 'Used Reactor feature groups')
			.self::metric('Events', (string)count($events), 'Lifecycle trace events')
			.'</div>';
		if(!$loaded){
			return $html.'<p class="dfd-muted">No Reactor classes were loaded during this request. This is normal for pages without reactive islands.</p></div></details>';
		}
		if((string)($reactor['manifest_error'] ?? '')!==''){
			$html.='<p class="dfd-alert">Manifest error: '.self::e((string)$reactor['manifest_error']).'</p>';
		}
		if($insights!==[]){
			$html.='<table class="dfd-table"><thead><tr><th>Level</th><th>Finding</th><th>Detail</th></tr></thead><tbody>';
			foreach($insights as $insight){
				if(!is_array($insight)){
					continue;
				}
				$level=(string)($insight['level'] ?? 'info');
				$html.='<tr><td>'.self::pill(self::e($level), self::level_tone($level)).'</td><td>'.self::e((string)($insight['title'] ?? '')).'</td><td>'.self::e((string)($insight['detail'] ?? '')).'</td></tr>';
			}
			$html.='</tbody></table>';
		}
		if($capability_counts!==[] || $event_counts!==[]){
			$html.='<div class="dfd-grid">';
			foreach(array_slice($capability_counts, 0, 8, true) as $capability=>$count){
				$html.=self::metric('Capability', (string)$count, (string)$capability);
			}
			foreach(array_slice($event_counts, 0, 4, true) as $event=>$count){
				$html.=self::metric('Event', (string)$count, (string)$event);
			}
			$html.='</div>';
		}
		if($components!==[]){
			$html.='<h3 class="dfd-muted">Reactive Components</h3><table class="dfd-table"><thead><tr><th>Name</th><th>Capabilities</th><th>State</th><th>Actions</th><th>Rules</th><th>Bindings</th></tr></thead><tbody>';
			foreach($components as $component){
				if(!is_array($component)){
					continue;
				}
				$bindings=is_array($component['bindings'] ?? null) ? $component['bindings'] : [];
				$binding_parts=[];
				foreach($bindings as $type=>$count){
					$binding_parts[]=(string)$type.': '.(string)$count;
				}
				$html.='<tr>'
					.'<td><code>'.self::e((string)($component['name'] ?? '')).'</code></td>'
					.'<td>'.self::reactor_badges(is_array($component['capabilities'] ?? null) ? $component['capabilities'] : []).'</td>'
					.'<td>'.self::e((string)($component['state_keys'] ?? 0)).' keys / '.self::e((string)($component['locked'] ?? 0)).' locked / '.self::e((string)($component['computed'] ?? 0)).' computed / '.self::e((string)($component['session'] ?? 0)).' session</td>'
					.'<td>'.self::e((string)($component['actions'] ?? 0)).'</td>'
					.'<td>'.self::e((string)($component['rules'] ?? 0)).'</td>'
					.'<td>'.self::e($binding_parts!==[] ? implode(', ', $binding_parts) : 'none').'</td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		if($events!==[]){
			$html.='<h3 class="dfd-muted">Lifecycle Trace</h3><table class="dfd-table"><thead><tr><th>When</th><th>Event</th><th>Component</th><th>Action</th><th>Result</th><th>Context</th></tr></thead><tbody>';
			foreach(array_reverse(array_slice($events, -80)) as $event){
				$time=(float)($event['time'] ?? 0);
				$duration=is_numeric($event['duration_ms'] ?? null) ? self::format_ms((float)$event['duration_ms']) : '';
				$status=is_numeric($event['status'] ?? null) ? (string)$event['status'] : $duration;
				$html.='<tr>'
					.'<td>'.self::e($time>0 ? date('H:i:s', (int)$time).sprintf('.%03d', ((int)($time*1000))%1000) : '').'</td>'
					.'<td><code>'.self::e((string)($event['event'] ?? 'event')).'</code><br><span class="dfd-muted">'.self::e((string)($event['category'] ?? '')).'</span></td>'
					.'<td>'.self::e((string)($event['component'] ?? '')).'</td>'
					.'<td>'.self::e((string)($event['action'] ?? '')).'</td>'
					.'<td>'.self::e($status).'</td>'
					.'<td><code>'.self::e(self::json(is_array($event['context'] ?? null) ? $event['context'] : [])).'</code></td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		$html.='<details class="dfd-stack"><summary>Manifest Summary <span>'.self::e((string)($manifest['version'] ?? '')).'</span></summary><pre class="dfd-code">'.self::e(self::json($manifest)).'</pre></details>';
		return $html.'</div></details>';
	}

	/**
	 * Renders Reactor capability or event-count badges.
	 *
	 * @param array<string,int|float|string> $items Badge labels mapped to scalar counts or status values.
	 * @return string Badge HTML.
	 */
	private static function reactor_badges(array $items): string {
		if($items===[]){
			return '<span class="dfd-muted">none</span>';
		}
		$html='';
		foreach($items as $item){
			$html.=self::pill(self::e((string)$item)).' ';
		}
		return trim($html);
	}

	/**
	 * Renders asset-node server, request, trace, and issue details.
	 *
	 * @param array{available?:bool,configured?:bool,can_store?:bool,trace?:list<array<string,mixed>>,origin?:string,storage?:string,errors?:list<array<string,mixed>>} $asset_node Asset-node debugbar state for CDN/storage resolution.
	 * @return string Asset-node panel HTML.
	 */
	private static function render_asset_node_panel(array $asset_node): string {
		if(empty($asset_node['available'])){
			return '';
		}
		$storage=is_array($asset_node['storage'] ?? null) ? $asset_node['storage'] : [];
		$config=is_array($asset_node['config'] ?? null) ? $asset_node['config'] : [];
		$servers=is_array($asset_node['servers'] ?? null) ? $asset_node['servers'] : [];
		$request=is_array($asset_node['request'] ?? null) ? $asset_node['request'] : [];
		$trace=is_array($asset_node['trace'] ?? null) ? $asset_node['trace'] : [];
		$configured=($asset_node['configured'] ?? null)===true;
		$can_store=($asset_node['can_store'] ?? null)!==false;
		$disk_total=(int)($storage['total_bytes'] ?? 0);
		$disk_free=(int)($storage['free_bytes'] ?? 0);
		$disk_label=$disk_total>0
			? self::format_bytes($disk_free).' free'
			: 'unknown';
		$current_label=(string)($asset_node['current_name'] ?? '');
		if($current_label===''){
			$current_label=(string)($asset_node['current_ip'] ?? 'unknown');
		}
		$default_protocol=(string)($config['default_protocol'] ?? '');
		$default_port=(int)($config['default_port'] ?? 0);
		$effective_default_port=(int)($config['effective_default_port'] ?? $default_port);
		$default_transport=$default_protocol.':'.($effective_default_port>0 ? (string)$effective_default_port : (string)$default_port);
		if($default_port<=0 && $effective_default_port>0){
			$default_transport.=' (effective)';
		}
		$html='<details id="dfd-panel-cdn-server" class="dfd-panel" data-dfd-panel="cdn-server"><summary><span>Asset Node</span><span class="dfd-muted">'.self::e((string)count($trace)).' events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Node', $current_label, 'IP '.(string)($asset_node['current_ip'] ?? '').' / step '.(string)($asset_node['server_step'] ?? 'n/a'))
			.self::metric('Config', $configured ? 'configured' : 'missing', (int)($config['server_count'] ?? 0).' server'.((int)($config['server_count'] ?? 0)===1 ? '' : 's').' / redundancy '.(int)($config['redundancy_level'] ?? 0))
			.self::metric('Storage', $can_store ? 'writable' : 'blocked', !empty($storage['exists']) ? 'Path exists' : 'Path missing')
			.self::metric('Disk', $disk_label, $disk_total>0 ? (string)($storage['used_percent'] ?? 0).'% used' : (string)($storage['disk_path'] ?? ''))
			.'</div>';
		$html.='<table class="dfd-table"><tbody>'
			.'<tr><th>Storage path</th><td><code>'.self::e((string)($storage['path'] ?? '')).'</code></td></tr>'
			.'<tr><th>Request URI</th><td><code>'.self::e((string)($request['uri'] ?? '')).'</code></td></tr>'
			.'<tr><th>Default transport</th><td>'.self::e($default_transport).'</td></tr>'
			.'<tr><th>Container threshold</th><td>'.self::format_bytes((int)($config['containerization_size_threshold'] ?? 0)).'</td></tr>'
			.'</tbody></table>';
		$content_probe=is_array($request['content_probe'] ?? null) ? $request['content_probe'] : [];
		if($content_probe!==[]){
			$stat_bits=[];
			foreach(['parent_exists'=>'parent', 'file_exists'=>'exists', 'is_file'=>'file', 'is_readable'=>'readable', 'is_link'=>'link'] as $key=>$label){
				if(array_key_exists($key, $content_probe)){
					$stat_bits[]=$label.'='.(string)$content_probe[$key];
				}
			}
			if(trim((string)($content_probe['size_bytes'] ?? ''))!==''){
				$stat_bits[]='size='.self::format_bytes((int)$content_probe['size_bytes']);
			}
			$html.='<details open><summary>Resolved content path</summary><table class="dfd-table"><tbody>'
				.'<tr><th>Decoded block path</th><td><code>'.self::e((string)($content_probe['decoded_blockpath'] ?? '')).'</code></td></tr>'
				.'<tr><th>Expected directory</th><td><code>'.self::e((string)($content_probe['relative_directory'] ?? '')).'</code></td></tr>'
				.'<tr><th>Expected filename</th><td><code>'.self::e((string)($content_probe['filename'] ?? '')).'</code></td></tr>'
				.'<tr><th>Expected file</th><td><code>'.self::e((string)($content_probe['expected_file'] ?? '')).'</code></td></tr>'
				.'<tr><th>Parent directory</th><td><code>'.self::e((string)($content_probe['parent_directory'] ?? '')).'</code></td></tr>'
				.'<tr><th>PHP stat</th><td>'.self::e(implode(' / ', $stat_bits)).'</td></tr>'
				.'</tbody></table></details>';
		}
		$params=is_array($request['params'] ?? null) ? $request['params'] : [];
		if($params!==[]){
			$html.='<details><summary>Request parameters</summary><pre class="dfd-code">'.self::e(self::json($params)).'</pre></details>';
		}
		if($servers!==[]){
			$html.='<details open><summary>Configured servers</summary><table class="dfd-table"><thead><tr><th>IP</th><th>Name</th><th>Datacenter</th><th>Transport</th></tr></thead><tbody>';
			foreach($servers as $server){
				if(!is_array($server)){
					continue;
				}
				$protocol=(string)($server['protocol'] ?? '');
				$port=(int)($server['port'] ?? 0);
				$transport=$protocol.($port>0 ? ':'.$port : '');
				$html.='<tr>'
					.'<td><code>'.self::e((string)($server['ip'] ?? '')).'</code></td>'
					.'<td>'.self::e((string)($server['name'] ?? '')).'</td>'
					.'<td>'.self::e((string)($server['datacenter'] ?? '')).'</td>'
					.'<td>'.self::e($transport).'</td>'
					.'</tr>';
			}
			$html.='</tbody></table></details>';
		}
		if($trace!==[]){
			$html.='<details open><summary>Request trace</summary><table class="dfd-table"><thead><tr><th>When</th><th>Stage</th><th>Data</th></tr></thead><tbody>';
			foreach($trace as $event){
				if(!is_array($event)){
					continue;
				}
				$data=is_array($event['data'] ?? null) ? $event['data'] : [];
				$html.='<tr>'
					.'<td>'.self::format_ms((float)($event['offset_ms'] ?? 0)).'</td>'
					.'<td><code>'.self::e((string)($event['stage'] ?? '')).'</code></td>'
					.'<td><code>'.self::e(self::shorten(self::json($data), 420)).'</code></td>'
					.'</tr>';
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders PHP runtime, memory, file, module, and trace summary details.
	 *
	 * @param array{modules_count?:int,modules?:list<string>,included_files?:int,memory_peak_mb?:int|float,ini?:array<string,mixed>,environment?:array<string,mixed>} $runtime Runtime debugbar state for loaded modules, files, memory, and environment.
	 * @param array{entry_count?:int,entries?:list<array<string,mixed>>,live_entries?:list<array<string,mixed>>,session_entries?:list<array<string,mixed>>} $trace Trace debugbar state used to correlate runtime boot evidence.
	 * @return string Runtime panel HTML.
	 */
	private static function render_runtime_panel(array $runtime, array $trace): string {
		$files_by_module=is_array($runtime['files_by_module'] ?? null) ? $runtime['files_by_module'] : [];
		$included_tail=is_array($runtime['included_tail'] ?? null) ? $runtime['included_tail'] : [];
		$root_paths=is_array($runtime['root_paths'] ?? null) ? $runtime['root_paths'] : [];
		$trace_count=(int)($trace['entry_count'] ?? 0);
		$html='<details id="dfd-panel-runtime" class="dfd-panel" data-dfd-panel="runtime"><summary><span>Runtime</span><span class="dfd-muted">'.self::e((string)($runtime['modules_count'] ?? 0)).' modules / '.self::e((string)($runtime['files_count'] ?? 0)).' files</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('PHP', (string)($runtime['php_version'] ?? PHP_VERSION), (string)($runtime['sapi'] ?? PHP_SAPI).' on '.(string)($runtime['os'] ?? PHP_OS_FAMILY))
			.self::metric('Memory Limit', (string)($runtime['memory_limit'] ?? ''), 'Peak '.self::format_bytes(memory_get_peak_usage(true)))
			.self::metric('Opcache', !empty($runtime['opcache_enabled']) ? 'on' : 'off', (string)($runtime['extensions_count'] ?? 0).' extensions loaded')
			.self::metric('Trace Buffer', (string)$trace_count.' rows', self::format_bytes((int)($trace['live_bytes'] ?? 0)).' live / '.self::format_bytes((int)($trace['session_bytes'] ?? 0)).' retained')
			.'</div>';
		if($files_by_module!==[]){
			$html.='<details open><summary>Included files by module</summary><table class="dfd-table"><thead><tr><th>Module</th><th>Files</th></tr></thead><tbody>';
			foreach(array_slice($files_by_module, 0, 18, true) as $module=>$count){
				$html.='<tr><td>'.self::e((string)$module).'</td><td>'.self::e((string)$count).'</td></tr>';
			}
			$html.='</tbody></table></details>';
		}
		if($root_paths!==[]){
			$html.='<details><summary>Root paths</summary><pre class="dfd-code">'.self::e(self::json($root_paths)).'</pre></details>';
		}
		if($included_tail!==[]){
			$html.='<details><summary>Latest included files</summary><pre class="dfd-code">'.self::e(implode("\n", $included_tail)).'</pre></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders sanitized request metadata.
	 *
	 * @param array{method?:string,uri?:string,status?:int,headers?:array<string,string>,query?:array<string,mixed>,body_preview?:string,cookies?:array<string,mixed>,session?:array<string,mixed>} $request Request debugbar state sanitized for toolbar rendering.
	 * @return string Request panel HTML.
	 */
	private static function render_request_panel(array $request): string {
		$query=is_array($request['query_params'] ?? null) ? $request['query_params'] : [];
		$body=is_array($request['body_params'] ?? null) ? $request['body_params'] : [];
		$cookies=is_array($request['cookies'] ?? null) ? $request['cookies'] : [];
		$session=is_array($request['session'] ?? null) ? $request['session'] : [];
		$headers=is_array($request['headers'] ?? null) ? $request['headers'] : [];
		$response_headers=is_array($request['response_headers'] ?? null) ? $request['response_headers'] : [];
		$html='<details id="dfd-panel-request" class="dfd-panel" data-dfd-panel="request"><summary><span>Request</span><span class="dfd-muted">'.self::e((string)($request['method'] ?? 'GET')).' '.self::e((string)($request['path'] ?? '/')).'</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Status', (string)($request['status'] ?? 200), 'Current response code')
			.self::metric('Host', (string)($request['host'] ?? ''), (string)($request['scheme'] ?? 'http').'://'.(string)($request['host'] ?? ''))
			.self::metric('Input', count($query).' query / '.count($body).' body', 'Sanitized request parameters')
			.self::metric('State', count($cookies).' cookies / '.count($session).' session', 'Sensitive cookie values are hidden')
			.'</div>';
		$html.='<table class="dfd-table"><tbody>'
			.'<tr><th>Client</th><td>'.self::e((string)($request['client_ip'] ?? '')).'</td></tr>'
			.'<tr><th>User agent</th><td>'.self::e(self::shorten((string)($request['user_agent'] ?? ''), 220)).'</td></tr>'
			.'<tr><th>Flags</th><td>'.self::e((!empty($request['ajax']) ? 'ajax ' : '').(!empty($request['do_not_track']) ? 'privacy-signal' : 'normal')).'</td></tr>'
			.'</tbody></table>';
		$sections=[
			'Query parameters'=>$query,
			'Body parameters'=>$body,
			'Request headers'=>$headers,
			'Cookies'=>$cookies,
			'Session'=>$session,
			'Response headers'=>$response_headers,
		];
		foreach($sections as $label=>$value){
			if($value===[]){
				continue;
			}
			$html.='<details><summary>'.self::e($label).'</summary><pre class="dfd-code">'.self::e(self::json($value)).'</pre></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders response body, JSON, HTML, and asset diagnostics.
	 *
	 * @param array{status?:int,headers?:array<string,string>,body_preview?:string,content_type?:string,duplicate_ids?:list<string>,suspicious_assets?:list<array<string,mixed>>,suspicious_phrases?:list<string>,mojibake_count?:int,missing_asset_count?:int,json_failure_count?:int} $response Response debugbar state and rendered-body audit evidence.
	 * @return string Response panel HTML.
	 */
	private static function render_response_panel(array $response): string {
		$assets=is_array($response['assets'] ?? null) ? $response['assets'] : [];
		$suspicious_assets=is_array($response['suspicious_assets'] ?? null) ? $response['suspicious_assets'] : [];
		$duplicate_ids=is_array($response['duplicate_ids'] ?? null) ? $response['duplicate_ids'] : [];
		$suspicious_phrases=is_array($response['suspicious_phrases'] ?? null) ? $response['suspicious_phrases'] : [];
		$json_failures=is_array($response['json_failure_markers'] ?? null) ? $response['json_failure_markers'] : [];
		$json_batch_routes=is_array($response['json_batch_routes'] ?? null) ? $response['json_batch_routes'] : [];
		$issue_count=count($suspicious_assets)+count($duplicate_ids)+count($suspicious_phrases)+(int)($response['mojibake_count'] ?? 0);
		if(!empty($response['is_json']) && (empty($response['json_valid']) || $json_failures!==[])){
			$issue_count+=max(1, count($json_failures));
		}
		$html='<details id="dfd-panel-response" class="dfd-panel" data-dfd-panel="response"><summary><span>Response Audit</span><span class="dfd-muted">'.self::e((string)$issue_count).' signals</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Body', !empty($response['available']) ? self::format_bytes((int)($response['bytes'] ?? 0)) : 'not captured', 'Response buffer before toolbar handling')
			.self::metric('Format', (string)($response['body_kind'] ?? 'unknown'), !empty($response['is_json']) ? ((bool)($response['json_valid'] ?? false) ? 'valid JSON' : (string)($response['json_error'] ?? 'invalid JSON')) : (string)($response['title'] ?? ''))
			.self::metric('Charset', (string)($response['charset'] ?? '') ?: 'missing', (string)($response['content_type'] ?? ''))
			.self::metric('Assets', (string)($response['asset_count'] ?? 0), (int)($response['resolved_asset_count'] ?? 0).' local / '.(int)($response['missing_asset_count'] ?? 0).' missing / '.(int)($response['remote_asset_count'] ?? 0).' remote')
			.'</div>';
		$html.='<table class="dfd-table"><tbody>'
			.'<tr><th>Title</th><td>'.self::e((string)($response['title'] ?? '')).'</td></tr>'
			.'<tr><th>Shell</th><td>'.self::e((string)($response['html_tag_count'] ?? 0)).' html / '.self::e((string)($response['body_tag_count'] ?? 0)).' body</td></tr>'
			.'<tr><th>Elements</th><td>'.self::e((string)($response['script_count'] ?? 0)).' scripts / '.self::e((string)($response['stylesheet_count'] ?? 0)).' stylesheets / '.self::e((string)($response['image_count'] ?? 0)).' images / '.self::e((string)($response['form_count'] ?? 0)).' forms</td></tr>'
			.'</tbody></table>';
		if(!empty($response['is_json'])){
			$html.='<table class="dfd-table"><tbody>'
				.'<tr><th>JSON</th><td>'.((bool)($response['json_valid'] ?? false) ? 'valid' : 'invalid').'</td></tr>'
				.'<tr><th>Shape</th><td>'.self::e((string)($response['json_top_level'] ?? '')).' / '.self::e((string)($response['json_item_count'] ?? 0)).' items / '.self::e((string)($response['json_route_count'] ?? 0)).' route-like keys</td></tr>'
				.'<tr><th>Keys</th><td><code>'.self::e(implode(', ', array_slice(is_array($response['json_keys'] ?? null) ? $response['json_keys'] : [], 0, 18))).'</code></td></tr>'
				.'</tbody></table>';
			if($json_failures!==[]){
				$html.='<details open><summary>API failure markers</summary><table class="dfd-table"><thead><tr><th>Path</th><th>Value</th></tr></thead><tbody>';
				foreach(array_slice($json_failures, 0, 24) as $marker){
					$html.='<tr><td><code>'.self::e((string)($marker['path'] ?? '')).'</code></td><td><code>'.self::e(self::shorten((string)($marker['value'] ?? ''), 220)).'</code></td></tr>';
				}
				$html.='</tbody></table></details>';
			}
			if($json_batch_routes!==[]){
				$html.='<details open><summary>API batch routes</summary><table class="dfd-table"><thead><tr><th>Route</th><th>Status</th><th>Results</th><th>Keys</th><th>Failure</th></tr></thead><tbody>';
				foreach(array_slice($json_batch_routes, 0, 24) as $route){
					$markers=is_array($route['failure_markers'] ?? null) ? $route['failure_markers'] : [];
					$first_marker=is_array($markers[0] ?? null) ? $markers[0] : [];
					$failure=trim((string)($first_marker['path'] ?? '').' '.(string)($first_marker['value'] ?? ''));
					$html.='<tr>'
						.'<td><code>'.self::e((string)($route['route'] ?? '')).'</code></td>'
						.'<td>'.self::pill(self::e((string)($route['status'] ?? 'mixed')), ((string)($route['status'] ?? '')==='failed' ? 'bad' : '')).'</td>'
						.'<td>'.self::e((string)($route['entries'] ?? 0)).' entries / '.self::e((string)($route['success'] ?? 0)).' ok / '.self::e((string)($route['failed'] ?? 0)).' failed</td>'
						.'<td><code>'.self::e(implode(', ', array_slice(is_array($route['keys'] ?? null) ? $route['keys'] : [], 0, 10))).'</code></td>'
						.'<td>'.self::e(self::shorten($failure, 220)).'</td>'
						.'</tr>';
				}
				$html.='</tbody></table></details>';
			}
			if(!empty($response['json_error'])){
				$html.='<details open><summary>JSON parse error</summary><pre class="dfd-code">'.self::e((string)$response['json_error']).'</pre></details>';
			}
			if(array_key_exists('json_preview', $response)){
				$html.='<details><summary>JSON preview</summary><pre class="dfd-code">'.self::e(self::json($response['json_preview'])).'</pre></details>';
			}
		}
		if($suspicious_phrases!==[]){
			$html.='<details open><summary>Suspicious response text</summary><pre class="dfd-code">'.self::e(implode("\n", $suspicious_phrases)).'</pre></details>';
		}
		if($suspicious_assets!==[]){
			$html.='<details open><summary>Suspicious assets</summary><table class="dfd-table"><thead><tr><th>Kind</th><th>Issue</th><th>Status</th><th>URL</th><th>File</th></tr></thead><tbody>';
			foreach(array_slice($suspicious_assets, 0, 30) as $asset){
				$file=(string)($asset['local_path'] ?? '');
				$html.='<tr><td>'.self::e((string)($asset['kind'] ?? '')).'</td><td>'.self::e((string)($asset['issue'] ?? '')).'</td><td>'.self::e((string)($asset['status'] ?? '')).'</td><td><code>'.self::e(self::shorten((string)($asset['url'] ?? ''), 220)).'</code></td><td><code>'.self::e(self::shorten($file, 180)).'</code></td></tr>';
			}
			$html.='</tbody></table></details>';
		}
		if($duplicate_ids!==[]){
			$html.='<details><summary>Duplicate HTML ids</summary><table class="dfd-table"><thead><tr><th>ID</th><th>Count</th></tr></thead><tbody>';
			foreach(array_slice($duplicate_ids, 0, 30) as $id=>$count){
				$html.='<tr><td><code>'.self::e((string)$id).'</code></td><td>'.self::e((string)$count).'</td></tr>';
			}
			$html.='</tbody></table></details>';
		}
		if($assets!==[]){
			$html.='<details><summary>Assets</summary><table class="dfd-table"><thead><tr><th>Kind</th><th>Status</th><th>URL</th><th>File / MIME</th></tr></thead><tbody>';
			foreach(array_slice($assets, 0, 40) as $asset){
				$file=(string)($asset['local_path'] ?? '');
				$mime=(string)($asset['mime'] ?? $asset['expected_mime'] ?? '');
				$file_label=trim(($file!=='' ? self::shorten($file, 180) : '').($mime!=='' ? ' '.$mime : ''));
				$html.='<tr><td>'.self::e((string)($asset['kind'] ?? '')).'</td><td>'.self::e((string)($asset['status'] ?? '')).'</td><td><code>'.self::e(self::shorten((string)($asset['url'] ?? ''), 240)).'</code></td><td><code>'.self::e($file_label).'</code></td></tr>';
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders browser telemetry captured by the debugbar client.
	 *
	 * @param array{event_count?:int,events?:list<array<string,mixed>>,page_performance?:array<string,mixed>,resource_timing?:array<string,mixed>,js_errors?:int,resource_errors?:int,client_http_errors?:int,client_fetch_errors?:int} $client Client-side telemetry state received from the browser probe.
	 * @return string Browser/client panel HTML.
	 */
	private static function render_client_panel(array $client): string {
		$events=is_array($client['events'] ?? null) ? $client['events'] : [];
		$count=(int)($client['event_count'] ?? count($events));
		$linked=(int)($client['linked_server_events'] ?? 0);
		$page_performance=is_array($client['page_performance'] ?? null) ? $client['page_performance'] : [];
		$resource_summary=is_array($client['resource_summary'] ?? null) ? $client['resource_summary'] : [];
		$production_replay=is_array($client['production_replay'] ?? null) ? $client['production_replay'] : [];
		$page_load=(float)($page_performance['load_ms'] ?? 0);
		$replay_status=(int)($production_replay['response_status'] ?? 0);
		$replay_server_ms=(float)($production_replay['server_duration_ms'] ?? 0);
		$replay_write_blocks=(int)($production_replay['replay_write_blocks'] ?? 0);
		$replay_debug_overhead=(float)($production_replay['replay_debug_overhead_mb'] ?? 0);
		$accessibility_issues=(int)($client['accessibility_issues'] ?? 0);
		$accessibility_adjustments=(int)($client['accessibility_adjustments'] ?? 0);
		$accessibility_checked=(int)($client['accessibility_checked'] ?? 0);
		$replay_memory_detail=$replay_write_blocks.' blocked write'.($replay_write_blocks===1 ? '' : 's');
		if($replay_debug_overhead>0){
			$replay_memory_detail.=' / debug overhead excluded '.$replay_debug_overhead.'mb';
		}
		$html='<details id="dfd-panel-browser" class="dfd-panel" data-dfd-panel="browser"'.($count>0 ? ' open' : '').'><summary><span>Browser Events</span><span class="dfd-muted" data-dfd-browser-count>'.self::e((string)$count).' events</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Page Load', $page_load>0 ? self::format_ms($page_load) : 'pending', $page_performance!==[] ? 'DOMContentLoaded '.self::format_ms((float)($page_performance['dom_content_loaded_ms'] ?? 0)) : 'Navigation timing')
			.self::metric('Resource Errors', (string)((int)($client['resource_errors'] ?? 0) + (int)($client['stylesheet_missing'] ?? 0)), 'Failed or missing browser assets')
			.self::metric('JavaScript', (string)((int)($client['js_errors'] ?? 0) + (int)($client['unhandled_rejections'] ?? 0)), 'Errors and unhandled rejections')
			.self::metric('Network', (string)((int)($client['client_http_errors'] ?? 0) + (int)($client['client_fetch_errors'] ?? 0)), (int)($client['client_http_slow'] ?? 0).' slow fetch/XHR')
			.self::metric('Accessibility', (string)$accessibility_issues, $accessibility_adjustments.' adjustment'.($accessibility_adjustments===1 ? '' : 's').' / '.$accessibility_checked.' checked')
			.self::metric('Server Links', (string)$linked, 'Matched captured backend snapshots')
			.self::metric('Resources', (string)((int)($resource_summary['count'] ?? 0)).' sampled', self::format_bytes((int)($resource_summary['total_transfer_size'] ?? 0)).' transferred')
			.self::metric('Production Replay', $production_replay!==[] ? ($replay_status>0 ? (string)$replay_status : 'captured') : 'pending', $replay_server_ms>0 ? 'Server '.self::format_ms($replay_server_ms) : 'Signed read-only replay')
			.self::metric('Replay Memory', $production_replay!==[] ? (string)($production_replay['replay_peak_mb'] ?? 0).'mb' : 'pending', $replay_memory_detail)
			.'</div>';
		if((float)($client['last_seen_at'] ?? 0)>0){
			$html.='<p class="dfd-muted">Last browser event at '.self::e(self::client_time_label((float)$client['last_seen_at'])).'.</p>';
		}
		$html.=self::render_client_resource_timing($resource_summary);
		if($events===[]){
			return $html.'<p class="dfd-muted" data-dfd-browser-empty>No browser-side events have been reported for this snapshot yet.</p><div data-dfd-browser-live></div></div></details>';
		}
		$display_events=array_values(array_filter($events, static fn(array $event): bool => (string)($event['type'] ?? '')!=='resource_timing'));
		if($display_events===[]){
			return $html.'<p class="dfd-muted" data-dfd-browser-empty>No browser-side failures or API issues have been reported for this snapshot.</p><div data-dfd-browser-live></div></div></details>';
		}
		$html.='<div data-dfd-browser-live></div><table class="dfd-table"><thead><tr><th>Type</th><th>Message</th><th>Source</th><th>Server</th><th>Detail</th></tr></thead><tbody>';
		foreach(array_slice(array_reverse($display_events), 0, 40) as $event){
			$type=(string)($event['type'] ?? 'client_event');
			$message=(string)($event['message'] ?? '');
			$source=(string)($event['url'] ?? $event['source'] ?? '');
			$detail=[];
			if(!empty($event['tag'])){
				$detail[]='tag '.$event['tag'];
			}
			if((int)($event['line'] ?? 0)>0){
				$detail[]='line '.(int)$event['line'].':'.(int)($event['column'] ?? 0);
			}
			if(!empty($event['method'])){
				$detail[]=(string)$event['method'];
			}
			if((float)($event['duration_ms'] ?? 0)>0){
				$detail[]=self::format_ms((float)$event['duration_ms']);
			}
			if((int)($event['response_status'] ?? 0)>0){
				$detail[]='status '.(int)$event['response_status'];
			}
			if((float)($event['load_ms'] ?? 0)>0){
				$detail[]='load '.self::format_ms((float)$event['load_ms']);
			}
			if((float)($event['dom_content_loaded_ms'] ?? 0)>0){
				$detail[]='dom '.self::format_ms((float)$event['dom_content_loaded_ms']);
			}
			if((int)($event['resource_count'] ?? 0)>0){
				$detail[]=(int)$event['resource_count'].' resources';
			}
			if((int)($event['replay_responded'] ?? 0)===1){
				$detail[]='HTTP response received';
			}
			if((int)($event['replay_verified'] ?? 0)===1){
				$detail[]='replay verified';
			}
			if((int)($event['replay_production'] ?? 0)===1){
				$detail[]='production mode';
			}
			if((int)($event['replay_readonly'] ?? 0)===1){
				$detail[]='read-only';
			}
			if((float)($event['server_duration_ms'] ?? 0)>0){
				$detail[]='server '.self::format_ms((float)$event['server_duration_ms']);
			}
			if((float)($event['replay_peak_mb'] ?? 0)>0){
				$detail[]='peak '.(float)$event['replay_peak_mb'].'mb';
			}
			if((float)($event['replay_debug_overhead_mb'] ?? 0)>0){
				$detail[]='debug overhead excluded '.(float)$event['replay_debug_overhead_mb'].'mb';
			}
			if((int)($event['replay_body_bytes'] ?? 0)>0){
				$detail[]='body '.self::format_bytes((int)$event['replay_body_bytes']);
			}
			if((int)($event['replay_write_blocks'] ?? 0)>0){
				$detail[]=(int)$event['replay_write_blocks'].' write blocks';
			}
			if($type==='accessibility_policy'){
				$detail[]=(int)($event['a11y_checked'] ?? 0).' checked';
				$detail[]=(int)($event['a11y_issue_count'] ?? 0).' issue fields';
				$detail[]=(int)($event['a11y_adjustment_count'] ?? 0).' adjusted fields';
				if(($event['a11y_field_source'] ?? '')==='combined_fields'){
					$detail[]='combined field rows';
				}
			}
			$server=self::client_event_server_link($event);
			$html.='<tr><td>'.self::pill(self::e($type), self::level_tone((string)($event['level'] ?? 'info'))).'</td><td>'.self::e(self::shorten($message, 220)).'</td><td><code>'.self::e(self::shorten($source, 260)).'</code></td><td>'.$server.'</td><td>'.self::e(implode(' / ', $detail)).'</td></tr>';
			if($type==='accessibility_policy'){
				$html.=self::render_client_accessibility_event($event);
			}
			if(!empty($event['stack'])){
				$html.='<tr><td></td><td colspan="4"><pre class="dfd-code">'.self::e((string)$event['stack']).'</pre></td></tr>';
			}
		}
		return $html.'</tbody></table></div></details>';
	}

	/**
	 * Renders accessibility issues, remediations, fields, and event logs.
	 *
	 * @param array{accessibility_issues?:int,accessibility_adjustments?:int,accessibility_events?:list<array<string,mixed>>,accessibility_fields?:list<array<string,mixed>>} $client Client-side accessibility telemetry state received from browser probes.
	 * @return string Accessibility panel HTML.
	 */
	private static function render_accessibility_panel(array $client): string {
		$issues=is_array($client['accessibility_issue_fields'] ?? null) ? $client['accessibility_issue_fields'] : [];
		$adjustments=is_array($client['accessibility_adjustment_fields'] ?? null) ? $client['accessibility_adjustment_fields'] : [];
		$latest=is_array($client['accessibility_latest'] ?? null) ? $client['accessibility_latest'] : [];
		$issue_count=(int)($client['accessibility_issues'] ?? 0);
		$adjustment_count=(int)($client['accessibility_adjustments'] ?? 0);
		$checked=(int)($client['accessibility_checked'] ?? 0);
		$event_count=(int)($client['accessibility_policy_events'] ?? 0);
		$events=is_array($client['accessibility_events'] ?? null) ? $client['accessibility_events'] : [];
		$issue_tokens=is_array($client['accessibility_issue_tokens'] ?? null) ? $client['accessibility_issue_tokens'] : [];
		$action_tokens=is_array($client['accessibility_action_tokens'] ?? null) ? $client['accessibility_action_tokens'] : [];
		$open=$issue_count>0 || $adjustment_count>0;
		$html='<details id="dfd-panel-accessibility" class="dfd-panel" data-dfd-panel="accessibility"'.($open ? ' open' : '').'><summary><span>Accessibility</span><span class="dfd-muted" data-dfd-accessibility-count>'.self::e((string)$issue_count).' issues</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Issue Targets', (string)$issue_count, 'Targets needing attention', '', 'data-dfd-accessibility-metric="issues"')
			.self::metric('Adjusted Fields', (string)$adjustment_count, 'Automatic layout recoveries', '', 'data-dfd-accessibility-metric="adjustments"')
			.self::metric('Checked Targets', (string)$checked, 'Fields and tables evaluated by policy', '', 'data-dfd-accessibility-metric="checked"')
			.self::metric('Policy Events', (string)$event_count, 'Browser accessibility reports', '', 'data-dfd-accessibility-metric="events"')
			.'</div>';
		$html.='<div data-dfd-accessibility-token-summary>'.self::render_accessibility_token_summary($issue_tokens, $action_tokens).'</div>';
		if($latest!==[]){
			$html.='<p class="dfd-muted">'.self::e((string)($latest['message'] ?? 'Latest accessibility policy event captured.')).'</p>';
		}
		$retained_note=$latest!==[]
			&& (int)($latest['a11y_issue_count'] ?? 0)===0
			&& (int)($latest['a11y_adjustment_count'] ?? 0)===0
			&& ($issues!==[] || $adjustments!==[]);
		$html.='<p class="dfd-muted" data-dfd-accessibility-retained-note'.($retained_note ? '' : ' hidden').'>'
			.($retained_note ? 'Latest policy report passed without field rows; showing the last issue rows captured for this snapshot.' : '')
			.'</p>';
		$html.='<div data-dfd-accessibility-events>'.self::render_accessibility_event_log($events).'</div>';
		$html.='<div class="dfd-accessibility-filterbar"><button type="button" class="dfd-inline-action dfd-active" data-dfd-accessibility-kind-filter="all" data-dfd-accessibility-kind-label="All">All '.self::e((string)($issue_count+$adjustment_count)).'</button><button type="button" class="dfd-inline-action" data-dfd-accessibility-kind-filter="issue" data-dfd-accessibility-kind-label="Issues">Issues '.self::e((string)$issue_count).'</button><button type="button" class="dfd-inline-action" data-dfd-accessibility-kind-filter="adjustment" data-dfd-accessibility-kind-label="Adjustments">Adjustments '.self::e((string)$adjustment_count).'</button><label class="dfd-filter"><span>Filter</span><input type="search" data-dfd-accessibility-filter placeholder="target, token, or message"></label><button type="button" class="dfd-inline-action" data-dfd-accessibility-clear-filter>Clear</button><button type="button" class="dfd-inline-action" data-dfd-accessibility-copy-panel>Copy visible report</button><span class="dfd-filter-status" data-dfd-accessibility-filter-status></span></div>';
		$html.='<div data-dfd-accessibility-remediation>'.self::render_accessibility_remediation($issue_tokens, $action_tokens).'</div>';
		$html.='<p class="dfd-muted" data-dfd-accessibility-no-results hidden>No fields match the current accessibility filters.</p>';
		$html.='<div data-dfd-accessibility-live>';
		if($issues!==[]){
			$html.='<details open><summary>Fields needing attention</summary>'.self::render_accessibility_fields_table($issues).'</details>';
		}
		if($adjustments!==[]){
			$html.='<details '.($issues===[] ? 'open' : '').'><summary>Automatic adjustments</summary>'.self::render_accessibility_fields_table($adjustments).'</details>';
		}
		if($issues===[] && $adjustments===[]){
			$html.='<p class="dfd-muted" data-dfd-accessibility-empty>No Panel accessibility policy reports have been received for this snapshot yet.</p>';
		}
		return $html.'</div></div></details>';
	}

	/**
	 * Renders the accessibility event log table.
	 *
	 * @param list<array{type?:string,token?:string,message?:string,node?:string,selector?:string,action?:string,timestamp?:int}> $events Accessibility telemetry events.
	 * @return string Event-log HTML.
	 */
	private static function render_accessibility_event_log(array $events): string {
		if($events===[]){
			return '';
		}
		$html='<details open><summary>Policy event log</summary><table class="dfd-table"><thead><tr><th>Time</th><th>Status</th><th>Checked</th><th>Issues</th><th>Adjustments</th><th>Tokens</th><th>Message</th></tr></thead><tbody>';
		foreach(array_slice($events, 0, 8) as $event){
			if(!is_array($event)){
				continue;
			}
			$timestamp=(float)($event['timestamp'] ?? 0);
			$seconds=$timestamp>100000000000 ? (int)floor($timestamp / 1000) : (int)$timestamp;
			$status=(string)($event['a11y_status'] ?? '');
			$tone=((int)($event['a11y_issue_count'] ?? 0)>0 || $status==='needs_attention')
				? 'warn'
				: ($status==='adjusted' || (int)($event['a11y_adjustment_count'] ?? 0)>0 ? '' : 'good');
			$tokens=self::accessibility_event_tokens($event);
			$source=(string)($event['a11y_field_source'] ?? '');
			$html.='<tr>'
				.'<td>'.self::e($seconds>0 ? date('H:i:s', $seconds) : '').'</td>'
				.'<td>'.self::pill(self::e($status!=='' ? $status : 'checked'), $tone).'</td>'
				.'<td>'.self::e((string)((int)($event['a11y_checked'] ?? 0))).'</td>'
				.'<td>'.self::e((string)((int)($event['a11y_issue_count'] ?? 0))).'</td>'
				.'<td>'.self::e((string)((int)($event['a11y_adjustment_count'] ?? 0))).'</td>'
				.'<td>'.self::render_accessibility_token_buttons($tokens).'</td>'
				.'<td>'.self::e(self::shorten((string)($event['message'] ?? ''), 220)).($source==='combined_fields' ? ' <span class="dfd-muted">(combined fields)</span>' : '').'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Extracts issue and action tokens from an accessibility event.
	 *
	 * @param array{type?:string,token?:string,message?:string,node?:string,selector?:string,action?:string} $event Accessibility telemetry event.
	 * @return array{issues:array<string,int>,actions:array<string,int>} Token groups for summaries and remediation.
	 */
	private static function accessibility_event_tokens(array $event): array {
		$counts=[];
		foreach([
			[is_array($event['a11y_issues'] ?? null) ? $event['a11y_issues'] : [], 'issues'],
			[is_array($event['a11y_adjustments'] ?? null) ? $event['a11y_adjustments'] : [], 'actions'],
		] as [$fields, $key]){
			foreach($fields as $field){
				if(!is_array($field)){
					continue;
				}
				foreach(is_array($field[$key] ?? null) ? $field[$key] : [] as $token){
					$token=(string)$token;
					if($token!==''){
						$counts[$token]=($counts[$token] ?? 0)+1;
					}
				}
			}
		}
		arsort($counts);
		return array_slice($counts, 0, 5, true);
	}

	/**
	 * Renders accessibility token filter buttons.
	 *
	 * @param array<string,int> $tokens Token counts keyed by token name.
	 * @return string Token button HTML.
	 */
	private static function render_accessibility_token_buttons(array $tokens): string {
		if($tokens===[]){
			return '<span class="dfd-muted">none</span>';
		}
		$html='';
		foreach($tokens as $token=>$count){
			$label=(string)$token.' '.$count;
			$html.='<button type="button" class="dfd-inline-action" data-dfd-accessibility-filter-token="'.self::e((string)$token).'">'.self::e($label).'</button> ';
		}
		return trim($html);
	}

	/**
	 * Renders issue/action token summaries for accessibility diagnostics.
	 *
	 * @param array<string,int> $issue_tokens Issue token counts.
	 * @param array<string,int> $action_tokens Action token counts.
	 * @return string Token summary HTML.
	 */
	private static function render_accessibility_token_summary(array $issue_tokens, array $action_tokens): string {
		if($issue_tokens===[] && $action_tokens===[]){
			return '';
		}
		$html='<div class="dfd-grid">';
		foreach([
			'Issue Types'=>$issue_tokens,
			'Action Types'=>$action_tokens,
		] as $label=>$tokens){
			$hint=$tokens===[] ? 'none' : implode(', ', array_map(
				static fn(string $token, int $count): string => $token.' '.$count,
				array_keys(array_slice($tokens, 0, 4, true)),
				array_values(array_slice($tokens, 0, 4, true))
			));
			$html.=self::metric($label, (string)array_sum(array_map('intval', $tokens)), self::shorten($hint, 120));
		}
		return $html.'</div>';
	}

	/**
	 * Renders remediation guidance derived from accessibility issue/action tokens.
	 *
	 * @param array<string,int> $issue_tokens Issue token counts.
	 * @param array<string,int> $action_tokens Action token counts.
	 * @return string Remediation HTML.
	 */
	private static function render_accessibility_remediation(array $issue_tokens, array $action_tokens): string {
		$tokens=array_merge(array_keys($issue_tokens), array_keys($action_tokens));
		if($tokens===[]){
			return '';
		}
		$guidance=[
			'width_constrained'=>['level'=>'warning', 'title'=>'Increase usable field width', 'next'=>'Raise the field column span, reduce adornments/buttons, or lower the configured min usable width/characters for this field.'],
			'contrast_fail'=>['level'=>'error', 'title'=>'Fix contrast tokens', 'next'=>'Update the field, label, or control color tokens so foreground/background contrast reaches the configured ratio.'],
			'touch_target_fail'=>['level'=>'warning', 'title'=>'Increase touch target size', 'next'=>'Use larger icon buttons, padding, or compact-control exceptions so interactive targets meet the policy minimum.'],
			'adornment_pressure'=>['level'=>'warning', 'title'=>'Reduce adornment pressure', 'next'=>'Move prepend/append buttons into a menu, stack adornments, or widen the field so the control remains usable.'],
			'width_expanded'=>['level'=>'info', 'title'=>'Width was auto-expanded', 'next'=>'Consider a larger default column span if this field repeatedly needs automatic expansion.'],
			'adornment_expanded'=>['level'=>'info', 'title'=>'Adornment pressure widened the field', 'next'=>'Check whether the field should start full-width or whether adornments can be simplified.'],
			'adornment_stacked'=>['level'=>'info', 'title'=>'Adornments were stacked', 'next'=>'Confirm the stacked control still scans well and consider replacing secondary text buttons with icons.'],
			'row_reflowed'=>['level'=>'info', 'title'=>'Row was reflowed', 'next'=>'This field stayed in its row and was resized after a sibling moved out for accessibility.'],
			'dom_reordered'=>['level'=>'info', 'title'=>'Field moved in DOM order', 'next'=>'The expanded field was moved after the row siblings it displaced so DOM and visual order stay aligned.'],
			'card_flattened'=>['level'=>'info', 'title'=>'Card wrapper flattened', 'next'=>'Remove the extra wrapper card or move meaningful heading/actions/content into it.'],
			'table_columns_optimized'=>['level'=>'info', 'title'=>'Table columns optimized', 'next'=>'Define table column width priorities, min/max widths, or responsive visibility rules if this optimized layout should become the default.'],
			'table_columns_compacted'=>['level'=>'info', 'title'=>'Table columns compacted', 'next'=>'Shorten table labels/cell copy, hide lower-priority columns, or configure tighter min widths for compact columns.'],
			'table_scroll_preserved'=>['level'=>'info', 'title'=>'Table scroll preserved', 'next'=>'Reduce visible columns or add responsive column hiding if this table should fit without horizontal scroll.'],
			'label_expanded'=>['level'=>'info', 'title'=>'Label pressure widened the field', 'next'=>'Shorten the label/hint or allocate a wider grid slot for this field.'],
			'label_stacked'=>['level'=>'info', 'title'=>'Label was stacked', 'next'=>'Confirm stacked labels are acceptable for this form density, or shorten label/hint copy.'],
		];
		$html='<details open><summary>Remediation guidance</summary><table class="dfd-table"><thead><tr><th>Token</th><th>Count</th><th>Severity</th><th>Guidance</th></tr></thead><tbody>';
		foreach(array_slice($tokens, 0, 12) as $token){
			$count=(int)($issue_tokens[$token] ?? $action_tokens[$token] ?? 0);
			$row=$guidance[$token] ?? ['level'=>'info', 'title'=>str_replace('_', ' ', $token), 'next'=>'Inspect the field row and policy message for this token.'];
			$html.='<tr>'
				.'<td><code>'.self::e((string)$token).'</code></td>'
				.'<td>'.self::e((string)$count).'</td>'
				.'<td>'.self::pill(self::e((string)$row['level']), self::level_tone((string)$row['level'])).'</td>'
				.'<td><b>'.self::e((string)$row['title']).'</b><br><span class="dfd-muted">'.self::e((string)$row['next']).'</span></td>'
				.'</tr>';
		}
		return $html.'</tbody></table></details>';
	}

	/**
	 * Renders field-level accessibility measurements and fixes.
	 *
	 * @param list<array{name?:string,label?:string,type?:string,selector?:string,issues?:list<string>,actions?:list<string>}> $fields Accessibility field rows.
	 * @return string Field table HTML.
	 */
	private static function render_accessibility_fields_table(array $fields): string {
		if($fields===[]){
			return '';
		}
		$html='<table class="dfd-table"><thead><tr><th>Target</th><th>Issues</th><th>Actions</th><th>Messages</th><th>Measurements</th></tr></thead><tbody>';
		foreach(array_slice($fields, 0, 10) as $field){
			if(!is_array($field)){
				continue;
			}
			$name=(string)($field['name'] ?? $field['label'] ?? 'field');
			$issues=implode(', ', is_array($field['issues'] ?? null) ? $field['issues'] : []);
			$actions=implode(', ', is_array($field['actions'] ?? null) ? $field['actions'] : []);
			$messages=array_merge(
				is_array($field['issue_messages'] ?? null) ? $field['issue_messages'] : [],
				is_array($field['action_messages'] ?? null) ? $field['action_messages'] : []
			);
			$kind=$issues!=='' ? 'issue' : ($actions!=='' ? 'adjustment' : 'field');
			$measurements=[];
			if((float)($field['usable_width'] ?? 0)>0 || (float)($field['required_width'] ?? 0)>0){
				$measurements[]='width '.round((float)($field['usable_width'] ?? 0), 1).'/'.round((float)($field['required_width'] ?? 0), 1).'px';
			}
			if((string)($field['required_width_source'] ?? '')!==''){
				$measurements[]='source '.(string)$field['required_width_source'];
			}
			if((int)($field['touch_target_failures'] ?? 0)>0){
				$measurements[]=(int)$field['touch_target_failures'].' touch target failures';
			}
			if((int)($field['table_columns'] ?? 0)>0){
				$measurements[]=(int)$field['table_columns'].' columns';
			}
			if((float)($field['table_available_width'] ?? 0)>0 || (float)($field['table_applied_width'] ?? 0)>0 || (float)($field['table_desired_width'] ?? 0)>0){
				$measurements[]='table '.round((float)($field['table_applied_width'] ?? 0), 1).'/'.round((float)($field['table_desired_width'] ?? 0), 1).'px in '.round((float)($field['table_available_width'] ?? 0), 1).'px';
			}
			if((int)($field['table_compact_columns'] ?? 0)>0){
				$measurements[]=(int)$field['table_compact_columns'].' compact columns';
			}
			if(!empty($field['table_scroll_preserved'])){
				$measurements[]='scroll preserved';
			}
			$report_lines=['Target: '.$name];
			if($issues!==''){
				$report_lines[]='Issues: '.$issues;
			}
			if($actions!==''){
				$report_lines[]='Actions: '.$actions;
			}
			if($messages!==[]){
				$report_lines[]='Messages: '.implode(' ', $messages);
			}
			if($measurements!==[]){
				$report_lines[]='Measurements: '.implode(' / ', $measurements);
			}
			$report=implode("\n", $report_lines);
			$haystack=trim($name.' '.$issues.' '.$actions.' '.implode(' ', $messages).' '.implode(' ', $measurements));
			$html.='<tr data-dfd-accessibility-row data-dfd-accessibility-kind="'.self::e($kind).'" data-dfd-accessibility-search="'.self::e(strtolower($haystack)).'">'
				.'<td><code>'.self::e(self::shorten($name, 120)).'</code><br><button type="button" class="dfd-inline-action" data-dfd-accessibility-focus="'.self::e($name).'">Focus target</button> <button type="button" class="dfd-inline-action" data-dfd-accessibility-copy="'.self::e($report).'">Copy report</button></td>'
				.'<td>'.self::e(self::shorten($issues, 180)).'</td>'
				.'<td>'.self::e(self::shorten($actions, 180)).'</td>'
				.'<td>'.self::e(self::shorten(implode(' ', $messages), 280)).'</td>'
				.'<td>'.self::e(implode(' / ', $measurements)).'</td>'
				.'</tr>';
		}
		return $html.'</tbody></table>';
	}

	/**
	 * Renders a compact accessibility event summary for the client panel.
	 *
	 * @param array{type?:string,token?:string,message?:string,node?:string,selector?:string,action?:string,timestamp?:int} $event Accessibility telemetry event.
	 * @return string Event summary HTML.
	 */
	private static function render_client_accessibility_event(array $event): string {
		$fields=array_merge(
			is_array($event['a11y_issues'] ?? null) ? $event['a11y_issues'] : [],
			is_array($event['a11y_adjustments'] ?? null) ? $event['a11y_adjustments'] : []
		);
		if($fields===[]){
			return '';
		}
		return '<tr><td></td><td colspan="4">'.self::render_accessibility_fields_table($fields).'</td></tr>';
	}

	/**
	 * Renders client resource-timing summary rows.
	 *
	 * @param array{resources?:list<array{name?:string,initiatorType?:string,duration?:int|float,transferSize?:int,encodedBodySize?:int,decodedBodySize?:int}>,slow?:list<array<string,mixed>>,errors?:list<array<string,mixed>>,total_duration_ms?:int|float,count?:int} $summary Resource timing summary payload.
	 * @return string Resource timing HTML.
	 */
	private static function render_client_resource_timing(array $summary): string {
		if($summary===[] || (int)($summary['count'] ?? 0)<=0){
			return '';
		}
		$by_type=is_array($summary['by_type'] ?? null) ? $summary['by_type'] : [];
		$slowest=is_array($summary['slowest'] ?? null) ? $summary['slowest'] : [];
		$html='<details open><summary>Resource Timing</summary>';
		$html.='<div class="dfd-grid">'
			.self::metric('Sampled', (string)((int)($summary['count'] ?? 0)), 'Slowest and largest browser resources')
			.self::metric('Transfer', self::format_bytes((int)($summary['total_transfer_size'] ?? 0)), 'Decoded '.self::format_bytes((int)($summary['total_decoded_size'] ?? 0)))
			.self::metric('Total Resource Time', self::format_ms((float)($summary['total_duration_ms'] ?? 0)), 'Sum of sampled resource durations')
			.self::metric('Slowest Resource', self::format_ms((float)($summary['max_duration_ms'] ?? 0)), 'Highest single resource duration')
			.'</div>';
		if($by_type!==[]){
			$html.='<table class="dfd-table"><thead><tr><th>Type</th><th>Count</th><th>Total Time</th><th>Slowest</th><th>Transfer</th><th>Decoded</th></tr></thead><tbody>';
			foreach(array_slice($by_type, 0, 12, true) as $type=>$row){
				if(!is_array($row)){
					continue;
				}
				$html.='<tr>'
					.'<td>'.self::e((string)$type).'</td>'
					.'<td>'.self::e((string)((int)($row['count'] ?? 0))).'</td>'
					.'<td>'.self::format_ms((float)($row['total_duration_ms'] ?? 0)).'</td>'
					.'<td>'.self::format_ms((float)($row['max_duration_ms'] ?? 0)).'</td>'
					.'<td>'.self::format_bytes((int)($row['total_transfer_size'] ?? 0)).'</td>'
					.'<td>'.self::format_bytes((int)($row['total_decoded_size'] ?? 0)).'</td>'
					.'</tr>';
			}
			$html.='</tbody></table>';
		}
		if($slowest!==[]){
			$html.='<details open><summary>Slowest resources</summary><table class="dfd-table"><thead><tr><th>Start</th><th>Type</th><th>Time</th><th>Status</th><th>Transfer</th><th>Protocol</th><th>URL</th></tr></thead><tbody>';
			foreach(array_slice($slowest, 0, 16) as $resource){
				if(!is_array($resource)){
					continue;
				}
				$status=(int)($resource['response_status'] ?? 0);
				$html.='<tr>'
					.'<td>'.self::format_ms((float)($resource['start_time_ms'] ?? 0)).'</td>'
					.'<td>'.self::e((string)($resource['initiator_type'] ?? 'other')).'</td>'
					.'<td>'.self::format_ms((float)($resource['duration_ms'] ?? 0)).'</td>'
					.'<td>'.($status>0 ? self::pill(self::e((string)$status), $status>=400 ? 'bad' : '') : '<span class="dfd-muted">n/a</span>').'</td>'
					.'<td>'.self::format_bytes((int)($resource['transfer_size'] ?? 0)).'</td>'
					.'<td>'.self::e(trim((string)($resource['next_hop_protocol'] ?? '').' '.(string)($resource['render_blocking_status'] ?? ''))).'</td>'
					.'<td><code>'.self::e(self::shorten((string)($resource['url'] ?? ''), 260)).'</code></td>'
					.'</tr>';
			}
			$html.='</tbody></table></details>';
		}
		return $html.'</details>';
	}

	/**
	 * Renders a link from a client event to the related server timeline span.
	 *
	 * @param array{type?:string,url?:string,status?:int,method?:string,duration_ms?:int|float,server_timeline_id?:string,request_id?:string} $event Client telemetry event.
	 * @return string Link HTML or an empty string.
	 */
	private static function client_event_server_link(array $event): string {
		$id=trim((string)($event['server_snapshot_id'] ?? ''));
		if($id===''){
			return '<span class="dfd-muted">not matched</span>';
		}
		$label=(string)($event['server_label'] ?? 'server snapshot');
		$status=(int)($event['server_status'] ?? 0);
		$duration=(float)($event['server_duration_ms'] ?? 0);
		$findings=(int)($event['server_findings'] ?? 0);
		$meta=[];
		if($status>0){
			$meta[]='HTTP '.$status;
		}
		if($duration>0){
			$meta[]=self::format_ms($duration);
		}
		if($findings>0){
			$meta[]=$findings.' finding'.($findings===1 ? '' : 's');
		}
		return '<a href="/dataphyre/debugbar?request='.rawurlencode($id).'">'.self::e(self::shorten($label, 140)).'</a>'
			.($meta!==[] ? '<br><span class="dfd-muted">'.self::e(implode(' / ', $meta)).'</span>' : '');
	}

	/**
	 * Renders a link to a persisted debugbar history snapshot.
	 *
	 * @param string $id Snapshot id.
	 * @param string $label Link label.
	 * @return string History link HTML or an empty string when no id is available.
	 */
	private static function history_link(string $id, string $label): string {
		$id=trim($id);
		if($id===''){
			return self::e($label);
		}
		return '<a href="/dataphyre/debugbar?request='.rawurlencode($id).'">'.self::e(self::shorten($label, 140)).'</a>';
	}

	/**
	 * Renders templating configuration and binding trace details.
	 *
	 * @param array{sql_binding_count?:int,bindings?:list<array<string,mixed>>,templates?:list<array<string,mixed>>,cache_hits?:int,cache_misses?:int} $templating Templating debugbar state for SQL binding and template-cache evidence.
	 * @return string Templating panel HTML.
	 */
	private static function render_templating_panel(array $templating): string {
		$bindings=is_array($templating['bindings'] ?? null) ? $templating['bindings'] : [];
		$html='<details id="dfd-panel-templating" class="dfd-panel" data-dfd-panel="templating"><summary><span>Templating</span><span class="dfd-muted">'.self::e((string)($templating['sql_binding_count'] ?? 0)).' SQL bindings</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Render Traces', (string)($templating['render_trace_count'] ?? 0), 'Template renders linked to SQL')
			.self::metric('SQL Bindings', (string)($templating['sql_binding_count'] ?? 0), 'Bindings that executed SQL')
			.self::metric('Strict Mode', !empty($templating['strict_mode']) ? 'on' : 'off', 'Template guard mode')
			.self::metric('Contracts', (string)($templating['contracts'] ?? 0), 'Registered template contracts')
			.'</div>';
		if($bindings!==[]){
			$html.='<table class="dfd-table"><thead><tr><th>Template</th><th>Binding</th><th>Path</th><th>Target</th></tr></thead><tbody>';
			foreach(array_slice($bindings, 0, 16) as $binding){
				$html.='<tr><td>'.self::e((string)($binding['template_name'] ?? '')).'</td><td>'.self::e((string)($binding['binding_name'] ?? $binding['binding_trace_id'] ?? '')).'</td><td>'.self::e((string)($binding['binding_path'] ?? '')).'</td><td>'.self::e((string)($binding['query_target'] ?? '')).'</td></tr>';
			}
			$html.='</tbody></table>';
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders a debugbar metric tile.
	 *
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $hint Supporting hint text.
	 * @param string $tone Optional severity tone class.
	 * @param string $attributes Additional HTML attributes.
	 * @return string Metric HTML.
	 */
	private static function metric(string $label, string $value, string $hint, string $tone='', string $attributes=''): string {
		$key=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $label), '-'));
		$class=trim('dfd-metric '.($tone!=='' ? 'dfd-'.$tone : ''));
		return '<div class="'.self::e($class).'" data-dfd-metric="'.self::e($key).'"'.($attributes!=='' ? ' '.$attributes : '').'><span>'.self::e($label).'</span><b>'.self::e($value).'</b><span>'.self::e(self::shorten($hint, 90)).'</span></div>';
	}

	/**
	 * Renders a compact debugbar pill.
	 *
	 * @param string $text Pill text.
	 * @param string $tone Optional tone class.
	 * @param string $extra_class Additional class names.
	 * @return string Pill HTML.
	 */
	private static function pill(string $text, string $tone='', string $extra_class=''): string {
		$class=trim('dfd-pill '.($tone!=='' ? 'dfd-'.$tone : '').' '.$extra_class);
		return '<span class="'.$class.'">'.$text.'</span>';
	}

	/**
	 * Renders a labeled status pill.
	 *
	 * @param string $label Status label.
	 * @param string $value Status value.
	 * @param string $tone Optional tone class.
	 * @param string $extra_class Additional class names.
	 * @return string Status pill HTML.
	 */
	private static function status_pill(string $label, string $value, string $tone='', string $extra_class=''): string {
		$class=trim('dfd-pill '.($tone!=='' ? 'dfd-'.$tone : '').' '.$extra_class);
		return '<span class="'.$class.'"><span class="dfd-pill-label">'.self::e($label).'</span><span class="dfd-pill-value">'.self::e($value).'</span></span>';
	}

	/**
	 * Renders an icon button for debugbar shell actions.
	 *
	 * @param string $action Data action identifier.
	 * @param string $label Accessible button label.
	 * @param string $icon Visible icon glyph.
	 * @param array<string,string|int|float|bool|null> $attributes Additional button attributes escaped into the toolbar control.
	 * @return string Button HTML.
	 */
	private static function action_button(string $action, string $label, string $icon, array $attributes=[]): string {
		$attrs='';
		foreach($attributes as $name=>$value){
			$attrs.=' '.self::e((string)$name).'="'.self::e((string)$value).'"';
		}
		return '<button type="button" class="dfd-shell-btn" data-dfd-action="'.self::e($action).'" title="'.self::e($label).'" aria-label="'.self::e($label).'"'.$attrs.'><span class="dfd-action-icon" aria-hidden="true">'.$icon.'</span><span class="dfd-action-label">'.self::e($label).'</span></button>';
	}

	/**
	 * Renders an icon link for debugbar shell actions.
	 *
	 * @param string $href Link URL.
	 * @param string $label Accessible link label.
	 * @param string $icon Visible icon glyph.
	 * @param string $extra_class Additional class names.
	 * @return string Link HTML.
	 */
	private static function action_link(string $href, string $label, string $icon, string $extra_class=''): string {
		$class=trim($extra_class);
		return '<a'.($class!=='' ? ' class="'.self::e($class).'"' : '').' href="'.self::e($href).'" title="'.self::e($label).'" aria-label="'.self::e($label).'"><span class="dfd-action-icon" aria-hidden="true">'.$icon.'</span><span class="dfd-action-label">'.self::e($label).'</span></a>';
	}

}
