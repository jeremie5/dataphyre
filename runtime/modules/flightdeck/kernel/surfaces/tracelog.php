<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');

if(defined('DATAPHYRE_FLIGHTDECK_TRACELOG_SURFACE_LOADED')){
	if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
		dataphyre_flightdeck_tracelog_surface::dispatch();
	}
	return;
}
define('DATAPHYRE_FLIGHTDECK_TRACELOG_SURFACE_LOADED', true);

/**
 * Renders Flightdeck's Tracelog viewer, retained trace buffer, and plotter.
 *
 * The surface reads trace handoffs from the tracelog module, session-retained
 * buffers, and temporary plotting files. It exposes cache-versioned CSS and JS
 * assets for the Flightdeck asset router while keeping trace data local to the
 * current runtime session and cache directory.
 */
final class dataphyre_flightdeck_tracelog_surface {

	/**
	 * Dispatches the Tracelog surface route.
	 *
	 * The default route renders runtime metrics and the trace buffer; the
	 * /plotter route consumes pending plotting frames and renders the D3 graph.
	 *
	 * @return void Emits the complete Flightdeck page.
	 */
	public static function dispatch(): void {
		$segments=self::segments();
		if(($segments[0] ?? '')==='plotter'){
			self::render_plotter();
			return;
		}
		self::render_viewer();
	}

	/**
	 * Splits the current Tracelog route into decoded path segments.
	 *
	 * @return array<int,string> Route segments after /dataphyre/tracelog.
	 */
	private static function segments(): array {
		$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/dataphyre/tracelog'), PHP_URL_PATH) ?: '');
		$base='/dataphyre/tracelog';
		if(str_starts_with($path, $base)){
			$path=substr($path, strlen($base));
		}
		return array_values(array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!==''));
	}

	/**
	 * Renders runtime metrics and the latest available trace buffer.
	 *
	 * Trace data is selected from explicit handoff token, session buffer, then
	 * retained session copy. Fresh buffers are cleared after rendering to avoid
	 * repeatedly showing stale request-local trace state.
	 *
	 * @return void Emits the Tracelog viewer page.
	 */
	private static function render_viewer(): void {
		$jit_info=[];
		if(function_exists('opcache_get_status')){
			$opcache_status=opcache_get_status();
			if(is_array($opcache_status) && isset($opcache_status['jit']) && is_array($opcache_status['jit'])){
				$jit_info=$opcache_status['jit'];
			}
		}
		$load_average=function_exists('sys_getloadavg') ? sys_getloadavg() : false;

		$handoff_token=(string)($_GET['handoff'] ?? ($_SESSION['flightdeck_last_tracelog_handoff'] ?? ''));
		$handoff_trace=(class_exists('\dataphyre\tracelog', false) && method_exists('\dataphyre\tracelog', 'last_handoff_trace'))
			? \dataphyre\tracelog::last_handoff_trace($handoff_token)
			: '';
		if($handoff_trace===''){
			$handoff_trace=self::read_handoff_trace($handoff_token);
		}
		$session_trace=(string)($_SESSION['tracelog'] ?? '');
		$retained_trace=(string)($_SESSION['flightdeck_last_tracelog'] ?? '');
		$tracelog=$handoff_trace!=='' ? $handoff_trace : ($session_trace!=='' ? $session_trace : $retained_trace);
		$trace_is_fresh=$handoff_trace!=='' || $session_trace!=='';
		$runtime_memory=(int)($_SESSION['runtime_memory_used'] ?? 0);
		$memory_overhead=strlen($tracelog) + $runtime_memory;
		$project_memory=((int)($_SESSION['memory_used'] ?? 0)) - $memory_overhead;
		$project_memory_peak=((int)($_SESSION['memory_used_peak'] ?? 0)) - $memory_overhead;

		$plotter_available=self::plotter_available();
		$content=self::render_runtime_metrics([
			['Execution', number_format((float)($_SESSION['exec_time'] ?? 0), 3).'s'],
			['Project Memory', self::storage(max(0, $project_memory)).' / '.self::storage(max(0, $project_memory_peak))],
			['Trace Overhead', self::storage($memory_overhead)],
			['Included Files', (string)($_SESSION['included_files'] ?? count(get_included_files()))],
			['SQL Cache', self::storage(strlen(serialize($_SESSION['db_cache'] ?? [])))],
			['PHP', phpversion()],
		], [
			['CPU Load', is_array($load_average) ? round((float)($load_average[0] ?? 0), 3).'%' : 'N/A'],
			['Loaded User Functions', (string)($_SESSION['defined_user_function_count'] ?? '0')],
			['JIT Buffer', isset($jit_info['buffer_size']) ? self::storage($jit_info['buffer_size']) : 'N/A'],
			['JIT Enabled', !empty($jit_info['enabled']) ? 'Yes' : 'No'],
			['Project PHP SLOC', isset($_SESSION['tracelog_sloc']) ? number_format((float)$_SESSION['tracelog_sloc'], 0, '.', ',') : 'Not cached'],
			['Project Size', (string)($_SESSION['tracelog_code_size'] ?? 'Not cached')],
		], $plotter_available);

		if($tracelog!==''){
			$subtitle=$trace_is_fresh ? 'Fresh trace captured from the last instrumented request.' : 'Showing the last retained trace because no fresh trace buffer is pending.';
			$content.=dataphyre_flightdeck_view::card('Trace Buffer', '<div class="fd-trace-buffer">'.$tracelog.'</div>', ['subtitle'=>$subtitle]);
		}
		else
		{
			$content.=dataphyre_flightdeck_view::card('Trace Buffer', '<p class="fd-muted">Load a page with tracing enabled, then refresh this view to inspect trace data.</p>');
		}

		$page=dataphyre_flightdeck_view::module_page(
			'Tracelog',
			'Runtime Trace Viewer',
			'Session trace metrics, buffers, and graph plotting embedded inside Flightdeck.',
			$content,
			'tracelog',
			['head'=>'<link rel="stylesheet" href="'.self::e(self::asset_url('tracelog-surface.css')).'">']
		);
		echo $page;
		unset($_SESSION['tracelog'], $_SESSION['tracelog_plotting']);
	}

	/**
	 * Renders the metrics header shown above the trace buffer.
	 *
	 * @param array<int,array{0:string,1:string}> $primary_metrics Prominent metric labels and values.
	 * @param array<int,array{0:string,1:string}> $detail_metrics Secondary runtime details.
	 * @param bool $plotter_available Whether a plotting action should be shown.
	 * @return string HTML metrics section.
	 */
	private static function render_runtime_metrics(array $primary_metrics, array $detail_metrics, bool $plotter_available): string {
		$items='';
		foreach($primary_metrics as $metric){
			$items.='<span><b>'.self::e((string)$metric[1]).'</b><em>'.self::e((string)$metric[0]).'</em></span>';
		}
		$details='';
		foreach($detail_metrics as $metric){
			$details.='<span><b>'.self::e((string)$metric[0]).'</b> '.self::e((string)$metric[1]).'</span>';
		}
		$actions=$plotter_available ? '<a class="fd-primary" href="/dataphyre/tracelog/plotter">Open Plotter</a>' : '';
		return '<section class="fd-card fd-runtime-metrics"><div class="fd-runtime-head"><div><h2>Runtime Metrics</h2><p>Last instrumented request.</p></div>'.$actions.'</div><div class="fd-runtime-grid">'.$items.'</div><details class="fd-runtime-details"><summary>More runtime details</summary><div>'.$details.'</div></details></section>';
	}

	/**
	 * Reads a trace handoff file from the Dataphyre cache.
	 *
	 * A signed handoff token resolves to its SHA-1 named file. When no valid
	 * token is supplied, the newest handoff file is used as a fallback so the
	 * viewer can still recover traces after redirects.
	 *
	 * @param string $handoff_token Signed handoff token from query string or session.
	 * @return string Trace HTML/text buffer, or an empty string when unavailable.
	 */
	private static function read_handoff_trace(string $handoff_token): string {
		$directory=self::handoff_directory();
		if($directory==='' || !is_dir($directory)){
			return '';
		}
		if(preg_match('/^([a-f0-9]{40})\.[a-f0-9]{64}$/', $handoff_token, $matches)){
			$file=$directory.'/'.$matches[1].'.dat';
			if(is_file($file)){
				return (string)@file_get_contents($file);
			}
		}
		$files=glob($directory.'/*.dat') ?: [];
		usort($files, static fn($a, $b)=>(int)@filemtime($b) <=> (int)@filemtime($a));
		foreach($files as $file){
			if(is_file($file)){
				return (string)@file_get_contents($file);
			}
		}
		return '';
	}

	/**
	 * Locates the cache directory used for tracelog handoff files.
	 *
	 * @return string Absolute cache directory, or an empty string when roots are unavailable.
	 */
	private static function handoff_directory(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/tracelog_handoff';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/tracelog_handoff';
		}
		return '';
	}

	/**
	 * Renders and consumes pending tracelog plotting data.
	 *
	 * Plotter frames are read from a newline-delimited JSON cache file, capped to
	 * a large but bounded set, then the file is removed so graph data is single-use.
	 *
	 * @return void Emits the plotter page.
	 */
	private static function render_plotter(): void {
		$plotter_file=self::plotter_file();
		$traces=[];
		if(is_file($plotter_file)){
			foreach(file($plotter_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line){
				$decoded=json_decode((string)$line, true);
				if(!empty($decoded)){
					if(count($traces)>10000){
						break;
					}
					$traces[]=$decoded;
				}
			}
			@unlink($plotter_file);
		}

		if($traces===[]){
			$content=dataphyre_flightdeck_view::card(
				'Trace Plotter',
				'<p class="fd-muted">No plotting data is available. Load an application page with plotting enabled, then return here.</p>',
				['actions'=>'<a class="fd-primary" href="/dataphyre/tracelog">Back to Tracelog</a>']
			);
		}
		else
		{
			$json=json_encode($traces, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
			$content=dataphyre_flightdeck_view::card(
				'Trace Plotter',
				'<div class="fd-plotter" id="fd-tracelog-plotter"><div class="fd-plotter-tooltip"></div><svg role="img" aria-label="Tracelog call graph"></svg></div><script>window.dataphyreTracelogData='.$json.';</script><script src="'.self::e(self::asset_url('tracelog-plotter.js')).'" defer></script>',
				[
					'subtitle'=>number_format(count($traces)).' trace frames consumed from '.$plotter_file.'.',
					'actions'=>'<a class="fd-primary" href="/dataphyre/tracelog">Back to Tracelog</a>',
				]
			);
		}

		echo dataphyre_flightdeck_view::module_page(
			'Tracelog',
			'Runtime Trace Plotter',
			'D3 call-graph visualization embedded in the Flightdeck shell.',
			$content,
			'tracelog',
			[
				'head'=>'<link rel="stylesheet" href="'.self::e(self::asset_url('tracelog-surface.css')).'"><script src="https://d3js.org/d3.v6.min.js"></script>',
			]
		);
	}

	/**
	 * Builds a cache-versioned Flightdeck asset URL for this surface.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Public Flightdeck asset URL with a content hash query value.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/flightdeck/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Returns the short content hash used to version a Tracelog asset.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-1 prefix, or "missing" when the asset is unknown.
	 */
	public static function asset_version(string $asset): string {
		$content=self::asset_content($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Returns the inline asset body and content type for this surface.
	 *
	 *
	 * @return ?array{content_type:string,body:string} Asset payload, or null for unknown assets.
	 */
	public static function asset_content(string $asset): ?array {
		return match(self::asset_name($asset)){
			'tracelog-surface.css'=>['content_type'=>'text/css; charset=UTF-8', 'body'=>self::style()],
			'tracelog-plotter.js'=>['content_type'=>'application/javascript; charset=UTF-8', 'body'=>self::script_body(self::plotter_script())],
			default=>null,
		};
	}

	/**
	 * Sanitizes a Tracelog asset request to a safe basename.
	 *
	 * @param string $asset Raw asset path from the asset router.
	 * @return string Safe filename, or an empty string for invalid names.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Extracts JavaScript body content from an inline script fragment.
	 *
	 * @param string $script Full script tag or raw JavaScript.
	 * @return string JavaScript body suitable for asset responses.
	 */
	private static function script_body(string $script): string {
		$script=trim($script);
		if(preg_match('/^<script\b[^>]*>(.*)<\/script>$/is', $script, $matches)===1){
			return trim((string)$matches[1])."\n";
		}
		return $script;
	}

	/**
	 * Returns the browser script that renders D3 trace graphs.
	 *
	 * @return string Inline script tag containing the plotter implementation.
	 */
	private static function plotter_script(): string {
		return <<<'HTML'
<script>
(function(){
	const root=document.getElementById("fd-tracelog-plotter");
	if(!root){
		return;
	}
	if(typeof d3==="undefined"){
		root.insertAdjacentHTML("beforeend", "<p class=\"fd-alert\">D3 could not be loaded for the trace plotter.</p>");
		return;
	}
	const tracelogData=Array.isArray(window.dataphyreTracelogData) ? window.dataphyreTracelogData : [];
	const links=[];
	const nodes=[];
	const nodeMap={};
	tracelogData.forEach(function(trace, index){
		if(!Array.isArray(trace) || trace.length<1 || !trace[0]){
			return;
		}
		const traceEntry=trace[0];
		const nodeId=[traceEntry.file || "unknown", traceEntry.line || "0", traceEntry.function || "frame"].join(":");
		if(!nodeMap[nodeId]){
			nodeMap[nodeId]={
				id: nodeId,
				label: traceEntry.function || "frame",
				file: traceEntry.file || "unknown",
				line: traceEntry.line || "0",
				className: traceEntry.class || "N/A",
				args: Array.isArray(traceEntry.args) ? traceEntry.args : []
			};
			nodes.push(nodeMap[nodeId]);
		}
		if(index>0 && Array.isArray(tracelogData[index - 1]) && tracelogData[index - 1][0]){
			const previous=tracelogData[index - 1][0];
			const previousId=[previous.file || "unknown", previous.line || "0", previous.function || "frame"].join(":");
			if(nodeMap[nodeId] && nodeMap[previousId]){
				links.push({source: previousId, target: nodeId, time: traceEntry.time || ""});
			}
		}
	});
	const svg=d3.select(root).select("svg");
	const bounds=root.getBoundingClientRect();
	const width=Math.max(720, bounds.width || 720);
	const height=680;
	svg.attr("viewBox", "0 0 "+width+" "+height).attr("preserveAspectRatio", "xMidYMid meet");
	const tooltip=d3.select(root).select(".fd-plotter-tooltip");
	const color=d3.scaleOrdinal(d3.schemeTableau10);
	const escapeHtml=function(value){
		return String(value).replace(/[&<>"']/g, function(character){
			return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[character];
		});
	};
	const zoom=d3.zoom().scaleExtent([0.1, 10]).on("zoom", function(event){ group.attr("transform", event.transform); });
	svg.call(zoom);
	const group=svg.append("g");
	svg.append("defs").append("marker")
		.attr("id", "fd-tracelog-arrow")
		.attr("viewBox", "0 -5 10 10")
		.attr("refX", 16)
		.attr("refY", 0)
		.attr("markerWidth", 6)
		.attr("markerHeight", 6)
		.attr("orient", "auto")
		.append("path")
		.attr("d", "M0,-5L10,0L0,5")
		.attr("fill", "#94a3b8");
	const link=group.append("g").attr("class", "fd-plotter-links").selectAll("line")
		.data(links).enter().append("line")
		.attr("stroke", "#94a3b8")
		.attr("stroke-opacity", 0.56)
		.attr("stroke-width", 1.6)
		.attr("marker-end", "url(#fd-tracelog-arrow)");
	const node=group.append("g").attr("class", "fd-plotter-nodes").selectAll("g")
		.data(nodes).enter().append("g");
	node.append("circle").attr("r", 6).attr("fill", function(d){ return color(d.className); });
	node.append("text").text(function(d){ return d.label; }).attr("x", 10).attr("y", 4);
	node.on("mouseover", function(event, d){
		tooltip.html("<b>"+escapeHtml(d.label)+"</b><br>"+escapeHtml(d.file)+":"+escapeHtml(d.line)+"<br>"+escapeHtml(d.className)+"<br>"+escapeHtml(d.args.join(", ")))
			.style("visibility", "visible")
			.style("top", (event.offsetY + 14)+"px")
			.style("left", (event.offsetX + 14)+"px");
	}).on("mousemove", function(event){
		tooltip.style("top", (event.offsetY + 14)+"px").style("left", (event.offsetX + 14)+"px");
	}).on("mouseout", function(){
		tooltip.style("visibility", "hidden");
	});
	const simulation=d3.forceSimulation(nodes)
		.force("link", d3.forceLink(links).id(function(d){ return d.id; }).distance(24))
		.force("charge", d3.forceManyBody().strength(-130))
		.force("collide", d3.forceCollide().radius(44))
		.force("center", d3.forceCenter(width / 2, height / 2));
	simulation.on("tick", function(){
		link.attr("x1", function(d){ return d.source.x; })
			.attr("y1", function(d){ return d.source.y; })
			.attr("x2", function(d){ return d.target.x; })
			.attr("y2", function(d){ return d.target.y; });
		node.attr("transform", function(d){ return "translate("+d.x+","+d.y+")"; });
	});
})();
</script>
HTML;
	}

	/**
	 * Reports whether graph plotting data is available or pending.
	 *
	 * @return bool True when a plotter cache file or session plotting flag exists.
	 */
	private static function plotter_available(): bool {
		return is_file(self::plotter_file()) || !empty($_SESSION['tracelog_plotting']);
	}

	/**
	 * Locates the newline-delimited JSON file used by trace plotting.
	 *
	 * @return string Absolute plotter cache path, or an empty string when roots are unavailable.
	 */
	private static function plotter_file(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/tracelog_plotting.dat';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/tracelog_plotting.dat';
		}
		return '';
	}

	/**
	 * Formats byte counts and preformatted values for compact display.
	 *
	 * @param mixed $size Numeric byte count or already formatted value.
	 * @return string Human-readable storage label.
	 */
	private static function storage(mixed $size): string {
		if(is_numeric($size)){
			$size=(float)$size;
			if($size<=0){
				return '0 b';
			}
			$units=['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
			$i=(int)floor(log($size, 1024));
			$i=min($i, count($units) - 1);
			return round($size / pow(1024, $i), 2).' '.$units[$i];
		}
		return (string)$size;
	}

	/**
	 * Returns the Tracelog surface CSS.
	 *
	 * @return string Stylesheet body for tracelog-surface.css.
	 */
	private static function style(): string {
		return '
.fd-runtime-metrics{padding:12px 14px;margin-bottom:12px;border-radius:18px}
.fd-runtime-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.fd-runtime-head h2{font-size:1rem;margin:0}
.fd-runtime-head p{margin:.2rem 0 0;color:#64748b;font-size:.78rem}
.fd-runtime-head .fd-primary{padding:7px 11px;font-size:.82rem}
.fd-runtime-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}
.fd-runtime-grid span{display:block;border:1px solid #dbe4ef;background:#fff;border-radius:12px;padding:8px 9px;min-width:0}
.fd-runtime-grid b{display:block;font-size:.92rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fd-runtime-grid em{display:block;margin-top:2px;color:#64748b;font-size:.72rem;font-style:normal;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.fd-runtime-details{margin-top:8px;color:#64748b;font-size:.78rem}
.fd-runtime-details summary{cursor:pointer;font-weight:800}
.fd-runtime-details div{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px}
.fd-runtime-details span{border:1px solid #dbe4ef;border-radius:999px;padding:5px 8px;background:#fff}
.fd-trace-buffer{background:#07111f;color:#dbeafe;border-radius:18px;padding:16px;overflow:auto;line-height:1.55}
.fd-plotter{position:relative;min-height:680px;background:#07111f;border-radius:22px;border:1px solid rgba(125,211,252,.18);overflow:hidden}
.fd-plotter svg{display:block;width:100%;height:680px}
.fd-plotter text{font:11px ui-sans-serif,system-ui,sans-serif;fill:#dbeafe;paint-order:stroke;stroke:#07111f;stroke-width:3px;stroke-linejoin:round}
.fd-plotter-tooltip{position:absolute;visibility:hidden;z-index:5;max-width:420px;background:#f8fafc;color:#0f172a;border:1px solid #dbe4ef;border-radius:14px;padding:10px 12px;box-shadow:0 18px 50px rgba(0,0,0,.28);font-size:.86rem}
@media(max-width:1100px){.fd-runtime-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:680px){.fd-runtime-head{display:block}.fd-runtime-head .fd-primary{margin-top:8px}.fd-runtime-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
';
	}

	/**
	 * Escapes text through the shared Flightdeck view helper.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-safe text.
	 */
	private static function e(string $value): string {
		return dataphyre_flightdeck_view::e($value);
	}
}

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
	dataphyre_flightdeck_tracelog_surface::dispatch();
}
