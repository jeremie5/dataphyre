<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_TRACE_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_TRACE_TRAIT_LOADED', true);

/**
 * Defines Flightdeck kernel trait responsibilities for dataphyre flightdeck debugbar trace.
 *
 * Flightdeck kernel boundary: requests, route dispatch, controller execution, operator UI, generated assets, or response rendering.
 */
trait dataphyre_flightdeck_debugbar_trace {

	/**
	 * Builds the trace panel state from retroactive, live, and session trace logs.
	 *
	 * Retroactive entries are normalized first, live tracelog HTML is parsed when
	 * available, and session fallback is used only when no live entries exist. The
	 * returned payload includes source metadata, parsed entries, byte counts, and
	 * plot-ready graph state without clearing any trace buffers.
	 *
	 * @return array<string, mixed> Trace debugbar state payload.
	 */
	private static function trace_state(): array {
		$entries=$GLOBALS['retroactive_tracelog'] ?? [];
		$entries=is_array($entries) ? $entries : [];
		$normalized=[];
		foreach($entries as $index=>$entry){
			$normalized_entry=self::normalize_retroactive_trace_entry($entry, (int)$index);
			if($normalized_entry!==null){
				$normalized[]=$normalized_entry;
			}
		}
		$live_log='';
		$live_bytes=0;
		if(class_exists('dataphyre\\tracelog', false) && property_exists('dataphyre\\tracelog', 'tracelog')){
			try{
				$live_log=(string)\dataphyre\tracelog::$tracelog;
				$live_bytes=strlen($live_log);
			}catch(\Throwable){
				$live_log='';
				$live_bytes=0;
			}
		}
		$live_entries=self::live_trace_entries($live_log);
		$session_log='';
		if($live_entries===[] && session_status()===PHP_SESSION_ACTIVE){
			$session_log=(string)($_SESSION['flightdeck_last_tracelog'] ?? $_SESSION['tracelog'] ?? '');
		}
		$session_entries=$session_log!=='' ? self::live_trace_entries($session_log, 'session') : [];
		$session_handoff='';
		if(session_status()===PHP_SESSION_ACTIVE){
			$session_handoff=(string)($_SESSION['flightdeck_last_tracelog_handoff'] ?? '');
		}
		$all_entries=array_merge($normalized, $live_entries, $session_entries);
		$plot=self::trace_plot_state($all_entries);
		return [
			'retroactive_count'=>count($entries),
			'live_bytes'=>$live_bytes,
			'live_entry_count'=>count($live_entries),
			'session_bytes'=>strlen($session_log),
			'session_entry_count'=>count($session_entries),
			'entry_count'=>count($normalized)+count($live_entries)+count($session_entries),
			'handoff'=>$session_handoff,
			'plot'=>$plot,
			'entries'=>$normalized,
			'live_entries'=>$live_entries,
			'session_entries'=>$session_entries,
		];
	}

	/**
	 * Builds the call-graph payload used by the Tracelog panel.
	 *
	 * Persisted plotting frames are preferred because they preserve nested call
	 * stacks. When the plotting file is unavailable, normalized trace rows are
	 * converted into a linear graph so the panel still exposes call flow.
	 *
	 * @param array<int,array<string,mixed>> $entries Normalized live, session, and retroactive trace rows.
	 * @return array<string,mixed> Graph payload with source, frame counts, nodes, and links.
	 */
	private static function trace_plot_state(array $entries): array {
		$frames=self::trace_plot_frames_from_file();
		if($frames!==[]){
			return self::trace_plot_from_frames($frames, 'plotting_file');
		}
		return self::trace_plot_from_entries($entries, 'trace_rows');
	}

	/**
	 * Loads recent Tracelog plotting frames from the runtime cache file.
	 *
	 * The reader keeps only the latest bounded window and accepts newline-delimited
	 * JSON arrays. Missing, unreadable, empty, or malformed cache content simply
	 * yields no frames so debugbar rendering remains best-effort.
	 *
	 * @return array<int,array<int,array<string,mixed>>> Decoded stack frames ordered by capture time.
	 */
	private static function trace_plot_frames_from_file(): array {
		$file=self::trace_plot_file();
		if($file==='' || is_file($file)!==true || is_readable($file)!==true){
			return [];
		}
		$handle=@fopen($file, 'rb');
		if(!is_resource($handle)){
			return [];
		}
		$lines=[];
		while(($line=fgets($handle))!==false){
			$line=trim($line);
			if($line===''){
				continue;
			}
			$lines[]=$line;
			if(count($lines)>600){
				array_shift($lines);
			}
		}
		fclose($handle);
		$frames=[];
		foreach($lines as $line){
			$decoded=json_decode($line, true);
			if(is_array($decoded) && $decoded!==[]){
				$frames[]=$decoded;
			}
		}
		return $frames;
	}

	/**
	 * Resolves the runtime cache path for persisted Tracelog plotting data.
	 *
	 * Dataphyre deployments may expose either dataphyre or common_dataphyre roots;
	 * both map to the same cache filename. An empty string means the current
	 * bootstrap did not publish a usable runtime root.
	 *
	 * @return string Absolute plotting cache path, or an empty string.
	 */
	private static function trace_plot_file(): string {
		if(defined('ROOTPATH') && is_array(ROOTPATH) && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/tracelog_plotting.dat';
		}
		if(defined('ROOTPATH') && is_array(ROOTPATH) && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/tracelog_plotting.dat';
		}
		return '';
	}

	/**
	 * Converts nested plotting frames into a bounded graph model.
	 *
	 * Each stack frame becomes a node keyed by file, line, class, and function.
	 * Adjacent stack entries become directed links, with counts accumulated across
	 * frames before trimming for browser-safe rendering.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $frames Captured call stacks from the plotting cache.
	 * @param string $source Graph source label for the UI.
	 * @return array<string,mixed> Trimmed graph payload for render_trace_plot().
	 */
	private static function trace_plot_from_frames(array $frames, string $source): array {
		$nodes=[];
		$node_order=[];
		$links=[];
		$seen_frames=0;
		foreach($frames as $frame){
			if(!is_array($frame) || $frame===[]){
				continue;
			}
			$seen_frames++;
			$stack=array_values(array_filter($frame, static fn($entry): bool => is_array($entry)));
			foreach($stack as $entry){
				$id=self::trace_plot_node_id($entry);
				if($id===''){
					continue;
				}
				if(!isset($nodes[$id])){
					$nodes[$id]=self::trace_plot_node($id, $entry, count($node_order));
					$node_order[]=$id;
				}
				$nodes[$id]['count']++;
				$nodes[$id]['last_time']=(string)($entry['time'] ?? $nodes[$id]['last_time'] ?? '');
			}
			for($index=count($stack)-1; $index>0; $index--){
				$source_id=self::trace_plot_node_id($stack[$index]);
				$target_id=self::trace_plot_node_id($stack[$index-1]);
				if($source_id==='' || $target_id==='' || $source_id===$target_id){
					continue;
				}
				$key=$source_id.'>'.$target_id;
				if(!isset($links[$key])){
					$links[$key]=['source'=>$source_id, 'target'=>$target_id, 'count'=>0, 'time'=>(string)($stack[$index-1]['time'] ?? '')];
				}
				$links[$key]['count']++;
			}
		}
		return self::trace_plot_trim($nodes, $links, $node_order, $seen_frames, $source);
	}

	/**
	 * Builds a fallback graph from flat normalized trace rows.
	 *
	 * Flat rows do not preserve stack depth, so consecutive callable rows are
	 * linked in capture order. The resulting graph is less precise than plotting
	 * frames but still shows hot call sites and repeated transitions.
	 *
	 * @param array<int,array<string,mixed>> $entries Normalized trace rows.
	 * @param string $source Graph source label for the UI.
	 * @return array<string,mixed> Trimmed graph payload for render_trace_plot().
	 */
	private static function trace_plot_from_entries(array $entries, string $source): array {
		$nodes=[];
		$node_order=[];
		$links=[];
		$previous_id='';
		$seen_frames=0;
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$call=trim((string)($entry['call'] ?? ''));
			if($call===''){
				continue;
			}
			$seen_frames++;
			$frame=[
				'file'=>(string)($entry['file'] ?? ''),
				'line'=>(string)($entry['line'] ?? ''),
				'function'=>$call,
				'class'=>'',
				'args'=>[],
				'time'=>(string)($entry['offset_ms'] ?? ''),
			];
			$id=self::trace_plot_node_id($frame);
			if($id===''){
				continue;
			}
			if(!isset($nodes[$id])){
				$nodes[$id]=self::trace_plot_node($id, $frame, count($node_order));
				$node_order[]=$id;
			}
			$nodes[$id]['count']++;
			if($previous_id!=='' && $previous_id!==$id){
				$key=$previous_id.'>'.$id;
				if(!isset($links[$key])){
					$links[$key]=['source'=>$previous_id, 'target'=>$id, 'count'=>0, 'time'=>(string)($entry['offset_ms'] ?? '')];
				}
				$links[$key]['count']++;
			}
			$previous_id=$id;
		}
		return self::trace_plot_trim($nodes, $links, $node_order, $seen_frames, $source);
	}

	/**
	 * Computes the stable graph node id for one call-frame entry.
	 *
	 * Function name is required; class values reported as N/A are ignored. File
	 * and line remain part of the identity so identical functions in different
	 * files or call sites stay distinct.
	 *
	 * @param array<string,mixed> $entry Raw plotting frame or synthesized trace frame.
	 * @return string SHA-1 node id, or an empty string when no function is present.
	 */
	private static function trace_plot_node_id(array $entry): string {
		$function=trim((string)($entry['function'] ?? ''));
		if($function===''){
			return '';
		}
		$class=trim((string)($entry['class'] ?? ''));
		if(strtoupper($class)==='N/A'){
			$class='';
		}
		$call=trim($class.($class!=='' && $function!=='' ? '::' : '').$function, ':');
		$file=(string)($entry['file'] ?? '');
		$line=(string)($entry['line'] ?? '');
		return sha1($file.'|'.$line.'|'.$call);
	}

	/**
	 * Normalizes one call-frame entry into the graph node contract.
	 *
	 * Argument previews are limited and scalarized to avoid leaking oversized
	 * structures into the debugbar payload. Colors are deterministic per call so
	 * repeated functions remain visually stable across renders.
	 *
	 * @param string $id Stable node id from trace_plot_node_id().
	 * @param array<string,mixed> $entry Raw plotting frame or synthesized trace frame.
	 * @param int $index First-seen ordering index.
	 * @return array<string,mixed> Graph node payload.
	 */
	private static function trace_plot_node(string $id, array $entry, int $index): array {
		$function=trim((string)($entry['function'] ?? 'frame'));
		$class=trim((string)($entry['class'] ?? ''));
		if(strtoupper($class)==='N/A'){
			$class='';
		}
		$call=trim($class.($class!=='' && $function!=='' ? '::' : '').$function, ':');
		$args=is_array($entry['args'] ?? null) ? array_slice($entry['args'], 0, 6) : [];
		return [
			'id'=>$id,
			'index'=>$index,
			'label'=>$function!=='' ? $function : 'frame',
			'call'=>$call!=='' ? $call : $function,
			'class'=>$class,
			'file'=>(string)($entry['file'] ?? ''),
			'line'=>(string)($entry['line'] ?? ''),
			'args'=>array_map(static fn($value): string => is_scalar($value) || $value===null ? (string)$value : '['.gettype($value).']', $args),
			'count'=>0,
			'last_time'=>(string)($entry['time'] ?? ''),
			'color'=>self::trace_call_color($call!=='' ? $call : $function),
		];
	}

	/**
	 * Bounds graph size while preserving the busiest call nodes and links.
	 *
	 * Nodes are ranked by hit count and original order, links are then filtered to
	 * surviving nodes and ranked by count. The returned payload is intentionally
	 * capped for predictable Flightdeck memory and DOM cost.
	 *
	 * @param array<string,array<string,mixed>> $nodes Node map keyed by node id.
	 * @param array<string,array<string,mixed>> $links Link map keyed by source/target pair.
	 * @param array<int,string> $node_order First-seen node ids.
	 * @param int $frame_count Number of frames or rows considered.
	 * @param string $source Graph source label.
	 * @return array<string,mixed> Bounded graph payload.
	 */
	private static function trace_plot_trim(array $nodes, array $links, array $node_order, int $frame_count, string $source): array {
		if($nodes===[]){
			return ['source'=>$source, 'frame_count'=>$frame_count, 'node_count'=>0, 'link_count'=>0, 'nodes'=>[], 'links'=>[]];
		}
		uasort($nodes, static function(array $a, array $b): int {
			$count=((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
			return $count!==0 ? $count : ((int)($a['index'] ?? 0) <=> (int)($b['index'] ?? 0));
		});
		$nodes=array_slice($nodes, 0, 72, true);
		$allowed=array_fill_keys(array_keys($nodes), true);
		$links=array_values(array_filter($links, static fn($link): bool => isset($allowed[(string)$link['source']], $allowed[(string)$link['target']])));
		usort($links, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
		$links=array_slice($links, 0, 120);
		$nodes=array_values($nodes);
		foreach($nodes as $index=>$node){
			$nodes[$index]['index']=$index;
		}
		return [
			'source'=>$source,
			'frame_count'=>$frame_count,
			'node_count'=>count($nodes),
			'link_count'=>count($links),
			'nodes'=>$nodes,
			'links'=>$links,
		];
	}

	/**
	 * Converts pre-module retroactive trace rows into the panel row contract.
	 *
	 * The historical retroactive buffer stores positional arrays with file, line,
	 * class, function, message, type, arguments, timestamp, and memory fields.
	 * Plain string rows are accepted as informational messages; other values are
	 * discarded.
	 *
	 * @param mixed $entry Raw retroactive tracelog entry.
	 * @param int $index Entry position in the original buffer.
	 * @return ?array<string,mixed> Normalized trace row, or null for unsupported data.
	 */
	private static function normalize_retroactive_trace_entry(mixed $entry, int $index): ?array {
		if(is_array($entry)){
			$file=(string)($entry[0] ?? '');
			$line=(string)($entry[1] ?? '');
			$class=(string)($entry[2] ?? '');
			$function=(string)($entry[3] ?? '');
			$timestamp=is_numeric($entry[7] ?? null) ? (float)$entry[7] : null;
			$arguments=self::sanitize_value($entry[6] ?? [], 'arguments');
			return [
				'origin'=>'retroactive',
				'index'=>$index,
				'file'=>$file,
				'line'=>(string)$line,
				'call'=>trim($class.'::'.$function, ':'),
				'type'=>self::trace_level((string)($entry[5] ?? 'info')),
				'message'=>self::trace_plain_text((string)($entry[4] ?? '')),
				'timestamp'=>$timestamp,
				'offset_ms'=>self::trace_offset_ms($timestamp),
				'memory_bytes'=>is_numeric($entry[8] ?? null) ? (int)$entry[8] : 0,
				'arguments'=>$arguments,
				'parameter_shape'=>self::trace_parameter_shape($arguments),
				'call_kind'=>self::trace_call_kind((string)($entry[5] ?? 'info'), trim($class.'::'.$function, ':'), (string)($entry[4] ?? '')),
				'call_color'=>self::trace_call_color(trim($class.'::'.$function, ':')),
			];
		}
		if(is_string($entry) && trim($entry)!==''){
			return [
				'origin'=>'retroactive',
				'index'=>$index,
				'file'=>'',
				'line'=>'',
				'call'=>'',
				'type'=>'info',
				'message'=>self::trace_plain_text($entry),
				'timestamp'=>null,
				'offset_ms'=>null,
				'memory_bytes'=>0,
				'arguments'=>[],
				'parameter_shape'=>'',
				'call_kind'=>'',
				'call_color'=>'',
			];
		}
		return null;
	}

	/**
	 * Parses the HTML live tracelog buffer into normalized trace rows.
	 *
	 * The live buffer is legacy HTML, so this parser extracts source labels,
	 * timings, memory labels, call markers, message HTML, and parameter previews
	 * while preserving a sanitized inline-message rendering path.
	 *
	 * @param string $log HTML tracelog buffer captured during or after the request.
	 * @param string $origin Origin label assigned to each row.
	 * @return array<int,array<string,mixed>> Normalized trace rows.
	 */
	private static function live_trace_entries(string $log, string $origin='live'): array {
		if(trim($log)===''){
			return [];
		}
		$rows=preg_split('/<br\s*\/?>/i', $log) ?: [];
		$entries=[];
		foreach($rows as $index=>$row){
			$row=trim((string)$row);
			if($row===''){
				continue;
			}
			$plain=self::trace_plain_text($row);
			if($plain===''){
				continue;
			}
			$message_html=self::trace_message_html($row);
			$file='';
			$line='';
			if(preg_match('/<span\s+title=(["\'])(.*?)\1>(.*?)<\/span>\s*:\s*([^:<]+)\s*:/is', $row, $match)===1){
				$file=html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$line=trim((string)$match[4]);
			}
			elseif(preg_match('/([A-Za-z0-9_.-]+\.php)\s*:\s*([0-9]+)\s*:/i', $plain, $match)===1){
				$file=(string)$match[1];
				$line=(string)$match[2];
			}
			$header='';
			if(preg_match('/<b[^>]*>(.*?)<\/b>/is', $row, $match)===1){
				$header=self::trace_plain_text((string)$match[1]);
			}
			$message=$plain;
			if($header!=='' && str_starts_with($message, $header)){
				$message=trim(substr($message, strlen($header)));
			}
			if($file!=='' || $line!==''){
				$source_pattern='/^\s*'.preg_quote(basename($file), '/').'\s*:\s*'.preg_quote($line, '/').'\s*:\s*>?\s*/i';
				$message=preg_replace($source_pattern, '', $message) ?? $message;
			}
			$offset_ms=null;
			$memory_label='';
			if($header!=='' && preg_match('/^([0-9.]+)ms,\s*(.*?)\s*>/i', $header, $match)===1){
				$offset_ms=(float)$match[1];
				$memory_label=trim((string)$match[2]);
			}
			$call='';
			if(preg_match('/\bFCw?T?:\s*([^(]+)\(/', $message, $match)===1){
				$call=trim((string)$match[1]);
			}
			elseif(preg_match('/\bFC:\s*([^:]+(?:::[^:]+)?)\(\):/', $message, $match)===1){
				$call=trim((string)$match[1]);
			}
			$type=self::trace_level_from_html($row);
			$entries[]=[
				'origin'=>$origin,
				'index'=>(int)$index,
				'file'=>$file,
				'line'=>$line,
				'call'=>$call,
				'type'=>$type,
				'call_kind'=>self::trace_call_kind($type, $call, $message),
				'call_color'=>self::trace_call_color_from_html($row, $call) ?: self::trace_call_color($call),
				'message'=>$message!=='' ? $message : $plain,
				'message_html'=>$message_html,
				'raw'=>$plain,
				'timestamp'=>null,
				'offset_ms'=>$offset_ms,
				'memory_label'=>$memory_label,
				'memory_bytes'=>0,
				'arguments'=>[],
				'parameter_shape'=>self::trace_parameter_shape_from_message($message, $call),
			];
		}
		return $entries;
	}

	/**
	 * Extracts the display message fragment from a legacy tracelog HTML row.
	 *
	 * Only the message portion is kept, and it is passed through trace_inline_html()
	 * before rendering. Rows without a message fragment return an empty string so
	 * the plain-text fallback can be used instead.
	 *
	 * @param string $row Raw HTML tracelog row.
	 * @return string Sanitized inline HTML message fragment.
	 */
	private static function trace_message_html(string $row): string {
		$message_html='';
		if(preg_match('/<\/i>\s*>\s*<b[^>]*>(.*)<\/b>\s*$/is', $row, $match)===1){
			$message_html=(string)$match[1];
		}
		elseif(preg_match_all('/<b[^>]*>(.*?)<\/b>/is', $row, $matches) && !empty($matches[1])){
			$message_html=(string)$matches[1][array_key_last($matches[1])];
		}
		if(trim($message_html)===''){
			return '';
		}
		return self::trace_inline_html($message_html);
	}

	/**
	 * Sanitizes allowed inline color spans from a tracelog message fragment.
	 *
	 * Span text is escaped and only color declarations accepted by
	 * trace_span_color() are preserved. Every other tag is reduced to plain text,
	 * making this the HTML safety boundary for live tracelog messages.
	 *
	 * @param string $html Legacy tracelog message HTML.
	 * @return string Escaped message HTML with safe color spans restored.
	 */
	private static function trace_inline_html(string $html): string {
		$tokens=[];
		$html=preg_replace_callback('/<span\b([^>]*)>(.*?)<\/span>/is', static function(array $match)use(&$tokens): string{
			$color=self::trace_span_color((string)$match[1]);
			$text=self::trace_plain_text((string)$match[2]);
			if($color===''){
				return self::e($text);
			}
			$token='DFDTRACEINLINE'.count($tokens).'TOKEN';
			$tokens[$token]='<span style="color:'.self::e($color).'">'.self::e($text).'</span>';
			return $token;
		}, $html) ?? $html;
		$rendered=self::e(self::trace_plain_text($html));
		foreach($tokens as $token=>$replacement){
			$rendered=str_replace($token, $replacement, $rendered);
		}
		return $rendered;
	}

	/**
	 * Extracts a safe CSS color value from span attributes.
	 *
	 * Hex, rgb/rgba, hsl/hsla, and a small named-color allowlist are accepted.
	 * Everything else is rejected so legacy trace markup cannot inject arbitrary
	 * styles into the Flightdeck panel.
	 *
	 * @param string $attributes Raw span attribute text.
	 * @return string Safe color token, or an empty string.
	 */
	private static function trace_span_color(string $attributes): string {
		if(preg_match('/\bcolor\s*:\s*([^;"\']+)/i', $attributes, $match)!==1){
			return '';
		}
		$color=trim((string)$match[1]);
		if(preg_match('/^#[0-9a-f]{3,8}$/i', $color)===1){
			return $color;
		}
		if(preg_match('/^(?:rgb|rgba|hsl|hsla)\([0-9.,% ]+\)$/i', $color)===1){
			return $color;
		}
		$allowed=['red', 'orange', 'pink', 'green', 'lime', 'cyan', 'yellow', 'white', 'black'];
		return in_array(strtolower($color), $allowed, true) ? strtolower($color) : '';
	}

	/**
	 * Classifies a trace row as a function-call marker when possible.
	 *
	 * Explicit trace levels are preferred, then legacy FC and FCwT markers inside
	 * the message are used. Rows without call evidence keep an empty marker.
	 *
	 * @param string $type Raw trace level.
	 * @param string $call Extracted call label.
	 * @param string $message Trace message text or HTML.
	 * @return string FCwT, FC, or an empty string.
	 */
	private static function trace_call_kind(string $type, string $call='', string $message=''): string {
		$type=self::trace_level($type);
		$message=strtolower(self::trace_plain_text($message));
		if($type==='function_call_with_test' || str_contains($message, 'fcwt:')){
			return 'FCwT';
		}
		if($type==='function_call' || str_contains($message, 'fc:') || trim($call)!==''){
			return 'FC';
		}
		return '';
	}

	/**
	 * Reads the legacy inline color associated with a traced call.
	 *
	 * This preserves colors emitted by the original tracelog renderer when the
	 * colored span text contains the extracted call label.
	 *
	 * @param string $row Raw HTML tracelog row.
	 * @param string $call Extracted call label.
	 * @return string Safe color token, or an empty string.
	 */
	private static function trace_call_color_from_html(string $row, string $call): string {
		if(preg_match_all('/<span\b([^>]*)>(.*?)<\/span>/is', $row, $matches, PREG_SET_ORDER)!==false){
			foreach($matches as $match){
				$color=self::trace_span_color((string)($match[1] ?? ''));
				if($color===''){
					continue;
				}
				$text=self::trace_plain_text((string)($match[2] ?? ''));
				if($call!=='' && str_contains($text, $call)){
					return $color;
				}
			}
		}
		return '';
	}

	/**
	 * Assigns a deterministic HSL color to a call label.
	 *
	 * The color is derived from the call hash rather than runtime order, keeping
	 * the same function visually consistent across separate debugbar renders.
	 *
	 * @param string $call Function or method label.
	 * @return string HSL color token, or an empty string for blank calls.
	 */
	private static function trace_call_color(string $call): string {
		$call=trim($call);
		if($call===''){
			return '';
		}
		$hash=sha1($call);
		$hue=hexdec(substr($hash, 0, 6)) % 360;
		return 'hsl('.$hue.', 76%, 68%)';
	}

	/**
	 * Summarizes traced argument values into a compact signature preview.
	 *
	 * The preview communicates scalar values and broad structural types without
	 * embedding full argument payloads into every trace row.
	 *
	 * @param mixed $arguments Arguments captured by the retroactive trace buffer.
	 * @return string Comma-separated parameter shape preview.
	 */
	private static function trace_parameter_shape(mixed $arguments): string {
		if(!is_array($arguments) || $arguments===[]){
			return '';
		}
		$shape=[];
		foreach($arguments as $value){
			$shape[]=self::trace_parameter_shape_value($value);
		}
		return implode(',', $shape);
	}

	/**
	 * Formats one argument value for the parameter-shape preview.
	 *
	 * Scalars are represented directly with bounded strings; arrays, objects,
	 * callables, nulls, and unknown values are reduced to type labels.
	 *
	 * @param mixed $value Captured argument value.
	 * @return string Compact argument preview token.
	 */
	private static function trace_parameter_shape_value(mixed $value): string {
		if(is_string($value)){
			return '"'.self::shorten($value, 80).'"';
		}
		if(is_array($value)){
			return 'Array';
		}
		if(is_bool($value)){
			return $value ? 'True' : 'False';
		}
		if(is_int($value)){
			return 'Integer('.$value.')';
		}
		if(is_float($value)){
			return 'Float('.rtrim(rtrim((string)$value, '0'), '.').')';
		}
		if($value===null){
			return 'Null';
		}
		if(is_object($value)){
			return 'Object';
		}
		if(is_callable($value)){
			return 'Callable';
		}
		return 'N/A';
	}

	/**
	 * Extracts parameter text from a legacy FC or FCwT message.
	 *
	 * Live tracelog rows often contain only rendered text rather than structured
	 * argument arrays, so this parser captures the text between call parentheses
	 * for display beside the call label.
	 *
	 * @param string $message Plain or HTML trace message.
	 * @param string $call Optional call label used to narrow the match.
	 * @return string Parameter preview text, or an empty string.
	 */
	private static function trace_parameter_shape_from_message(string $message, string $call=''): string {
		$message=self::trace_plain_text($message);
		if($message===''){
			return '';
		}
		$call=trim($call);
		$pattern=$call!=='' ? '/'.preg_quote($call, '/').'\((.*?)\)/s' : '/\bFCw?T?:\s*[^()]+\((.*?)\)/s';
		if(preg_match($pattern, $message, $match)!==1){
			return '';
		}
		return trim((string)$match[1]);
	}

	/**
	 * Converts legacy tracelog HTML or encoded text into normalized plain text.
	 *
	 * Line breaks, HTML entities, non-breaking spaces, and known mojibake arrow
	 * sequences are normalized before whitespace is compacted per line.
	 *
	 * @param string $value Raw trace HTML or text.
	 * @return string Normalized plain-text trace content.
	 */
	private static function trace_plain_text(string $value): string {
		$value=str_replace(["\r\n", "\r"], "\n", $value);
		$value=preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
		$value=html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value=str_replace(["\xE2\x96\xB8", "\xC3\xA2\xE2\x82\xAC\xE2\x80\xB8", "\xC2\xA0"], ['>', '>', ' '], $value);
		$lines=array_map(static fn(string $line): string => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line), explode("\n", $value));
		return trim(implode("\n", array_values(array_filter($lines, static fn(string $line): bool => $line!==''))));
	}

	/**
	 * Normalizes trace severity and call-marker levels.
	 *
	 * Unknown values fall back to info so renderer tone selection never receives
	 * arbitrary level strings.
	 *
	 * @param string $type Raw trace level.
	 * @return string Canonical trace level.
	 */
	private static function trace_level(string $type): string {
		$type=strtolower(trim($type));
		return in_array($type, ['fatal', 'error', 'warning', 'info', 'function_call', 'function_call_with_test'], true) ? $type : 'info';
	}

	/**
	 * Infers a trace level from legacy HTML row styling and markers.
	 *
	 * Historical rows encode severity through inline colors and FC marker text.
	 * This detector maps those conventions into the canonical trace levels used by
	 * the renderer.
	 *
	 * @param string $row Raw HTML tracelog row.
	 * @return string Canonical trace level.
	 */
	private static function trace_level_from_html(string $row): string {
		$lower=strtolower($row);
		if(str_contains($lower, 'color:red') || str_contains($lower, 'tracelog fatal')){
			return 'fatal';
		}
		if(str_contains($lower, 'color:pink')){
			return 'error';
		}
		if(str_contains($lower, 'color:orange')){
			return 'warning';
		}
		if(str_contains($lower, 'fcwt:')){
			return 'function_call_with_test';
		}
		if(str_contains($lower, 'fc:')){
			return 'function_call';
		}
		return 'info';
	}

	/**
	 * Converts an absolute trace timestamp into request-relative milliseconds.
	 *
	 * The calculation uses REQUEST_TIME_FLOAT when available and clamps negative
	 * offsets to zero to tolerate clock or buffer ordering differences.
	 *
	 * @param ?float $timestamp Absolute event timestamp.
	 * @return ?float Request-relative offset in milliseconds.
	 */
	private static function trace_offset_ms(?float $timestamp): ?float {
		if($timestamp===null || $timestamp<=0){
			return null;
		}
		$started=defined('REQUEST_TIME_FLOAT') ? (float)REQUEST_TIME_FLOAT : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
		if($started<=0){
			return null;
		}
		return round(max(0, ($timestamp - $started) * 1000), 3);
	}

	/**
	 * Renders the Flightdeck Tracelog panel.
	 *
	 * The panel combines current request rows, pre-module retroactive rows,
	 * retained session rows, and the call graph. Counts and byte sizes are shown
	 * before detailed logs so heavy traces remain scannable.
	 *
	 * @param array<string,mixed> $trace Aggregated trace state from trace_state().
	 * @return string Tracelog debugbar panel HTML.
	 */
	private static function render_tracelog_panel(array $trace): string {
		$retroactive=is_array($trace['entries'] ?? null) ? $trace['entries'] : [];
		$live=is_array($trace['live_entries'] ?? null) ? $trace['live_entries'] : [];
		$session=is_array($trace['session_entries'] ?? null) ? $trace['session_entries'] : [];
		$plot=is_array($trace['plot'] ?? null) ? $trace['plot'] : [];
		$count=(int)($trace['entry_count'] ?? (count($retroactive)+count($live)+count($session)));
		$html='<details id="dfd-panel-tracelog" class="dfd-panel" data-dfd-panel="tracelog"'.($count>0 ? ' open' : '').'><summary><span>Tracelog</span><span class="dfd-muted">'.self::e((string)$count).' rows</span></summary><div class="dfd-panel-body">';
		$html.='<div class="dfd-grid">'
			.self::metric('Rows', (string)$count, count($live).' live / '.count($retroactive).' pre-module / '.count($session).' retained')
			.self::metric('Live Buffer', self::format_bytes((int)($trace['live_bytes'] ?? 0)), 'Current dataphyre\\tracelog buffer')
			.self::metric('Plot Frames', (string)((int)($plot['frame_count'] ?? 0)), (int)($plot['node_count'] ?? 0).' nodes / '.(int)($plot['link_count'] ?? 0).' edges')
			.self::metric('Retained', self::format_bytes((int)($trace['session_bytes'] ?? 0)), trim((string)($trace['handoff'] ?? ''))!=='' ? 'Session fallback with handoff token' : 'Session fallback')
			.'</div>';
		$html.='<p class="dfd-muted"><a href="/dataphyre/tracelog" target="_blank" rel="noopener">Open Tracelog viewer</a> / <a href="/dataphyre/tracelog/plotter" target="_blank" rel="noopener">Open call graph</a></p>';
		if($count<=0){
			return $html.'<p class="dfd-muted">No Tracelog rows were captured for this request.</p></div></details>';
		}
		if($plot!==[] && (int)($plot['node_count'] ?? 0)>0){
			$html.=self::render_trace_plot($plot);
		}
		if($live!==[]){
			$html.=self::render_trace_log('Current request tracelog', $live, true);
		}
		if($retroactive!==[]){
			$html.=self::render_trace_log('Pre-module tracelog buffer', $retroactive, $live===[]);
		}
		if($session!==[]){
			$html.=self::render_trace_log('Retained session tracelog', $session, $live===[] && $retroactive===[]);
		}
		return $html.'</div></details>';
	}

	/**
	 * Renders the call graph as static SVG plus a hydrated graph payload.
	 *
	 * The SVG provides a no-script fallback and the embedded template carries the
	 * same bounded graph model for interactive Flightdeck assets. Node labels,
	 * colors, and link counts are escaped before entering the DOM.
	 *
	 * @param array<string,mixed> $plot Graph payload from trace_plot_state().
	 * @return string Call graph HTML, or an empty string when no nodes exist.
	 */
	private static function render_trace_plot(array $plot): string {
		$nodes=is_array($plot['nodes'] ?? null) ? $plot['nodes'] : [];
		$links=is_array($plot['links'] ?? null) ? $plot['links'] : [];
		if($nodes===[]){
			return '';
		}
		$width=920;
		$height=340;
		$node_positions=[];
		$count=count($nodes);
		foreach($nodes as $index=>$node){
			if(!is_array($node)){
				continue;
			}
			$column=$count<=1 ? 0 : $index % 12;
			$row=(int)floor($index / 12);
			$rows=max(1, (int)ceil($count / 12));
			$x=52 + ($column * (($width - 104) / max(1, min(11, $count - 1))));
			$y=62 + ($row * (($height - 118) / max(1, $rows - 1)));
			if($rows===1){
				$y=(float)($height / 2);
			}
			$node_positions[(string)$node['id']]=['x'=>$x, 'y'=>$y, 'node'=>$node];
		}
		$cloud_payload=json_encode([
			'nodes'=>array_values($nodes),
			'links'=>array_values($links),
			'width'=>$width,
			'height'=>$height,
		], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$cloud_payload=is_string($cloud_payload) ? $cloud_payload : '{"nodes":[],"links":[]}';
		$cloud_id='dfd-trace-cloud-'.substr(sha1($cloud_payload), 0, 16);
		$svg='<svg class="dfd-trace-plot-svg" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="Tracelog call graph">';
		$svg.='<defs><marker id="dfd-trace-plot-arrow" viewBox="0 -5 10 10" refX="12" refY="0" markerWidth="5" markerHeight="5" orient="auto"><path d="M0,-5L10,0L0,5" fill="#7dd3fc"/></marker></defs>';
		$svg.='<g class="dfd-trace-plot-static">';
		foreach($links as $link){
			if(!is_array($link)){
				continue;
			}
			$source=$node_positions[(string)($link['source'] ?? '')] ?? null;
			$target=$node_positions[(string)($link['target'] ?? '')] ?? null;
			if($source===null || $target===null){
				continue;
			}
			$stroke_width=min(5.0, 1.1 + (log(max(1, (int)($link['count'] ?? 1)), 2) * .45));
			$svg.='<line x1="'.self::e((string)round((float)$source['x'], 2)).'" y1="'.self::e((string)round((float)$source['y'], 2)).'" x2="'.self::e((string)round((float)$target['x'], 2)).'" y2="'.self::e((string)round((float)$target['y'], 2)).'" stroke-width="'.self::e((string)round($stroke_width, 2)).'" marker-end="url(#dfd-trace-plot-arrow)"><title>'.self::e((string)($link['count'] ?? 1)).' calls</title></line>';
		}
		$svg.='</g><g class="dfd-trace-plot-static">';
		foreach($node_positions as $position){
			$node=is_array($position['node'] ?? null) ? $position['node'] : [];
			$label=self::shorten((string)($node['label'] ?? 'frame'), 28);
			$detail=trim((string)($node['call'] ?? $label))."\n".trim((string)($node['file'] ?? '')).':'.trim((string)($node['line'] ?? ''))."\n".(string)($node['count'] ?? 0).' hits';
			$radius=min(14.0, 5.0 + log(max(1, (int)($node['count'] ?? 1)), 2));
			$color=(string)($node['color'] ?? '#7dd3fc');
			$svg.='<g class="dfd-trace-plot-node" transform="translate('.self::e((string)round((float)$position['x'], 2)).' '.self::e((string)round((float)$position['y'], 2)).')">'
				.'<title>'.self::e($detail).'</title>'
				.'<circle r="'.self::e((string)round($radius, 2)).'" fill="'.self::e($color).'"></circle>'
				.'<text x="'.self::e((string)round($radius + 4, 2)).'" y="4">'.self::e($label).'</text>'
				.'</g>';
		}
		$svg.='</g>';
		$svg.='</svg>';
		$source=(string)($plot['source'] ?? 'trace_rows');
		return '<details open><summary>Call graph <span class="dfd-muted">'.self::e((string)($plot['node_count'] ?? count($nodes))).' nodes / '.self::e((string)($plot['link_count'] ?? count($links))).' edges / '.self::e($source).'</span></summary><div class="dfd-trace-plot" data-dfd-trace-cloud="'.self::e($cloud_id).'">'.$svg.'<template id="'.self::e($cloud_id).'" data-dfd-trace-cloud-data>'.self::e($cloud_payload).'</template></div></details>';
	}

	/**
	 * Renders a named group of trace rows.
	 *
	 * When PHP memory is tight, the oldest rows are omitted and the newest bounded
	 * window is rendered. This keeps Flightdeck useful during failure analysis
	 * without exhausting the request.
	 *
	 * @param string $label Group label shown in the details summary.
	 * @param array<int,array<string,mixed>> $entries Normalized trace rows.
	 * @param bool $open Whether the group should be expanded initially.
	 * @return string Trace log group HTML.
	 */
	private static function render_trace_log(string $label, array $entries, bool $open=false): string {
		$total=count($entries);
		$omitted=0;
		if(self::memory_limit_is_tight()===true && $total>self::TRACE_TIGHT_RENDER_LIMIT){
			$omitted=$total - self::TRACE_TIGHT_RENDER_LIMIT;
			$entries=array_slice($entries, -self::TRACE_TIGHT_RENDER_LIMIT);
		}
		$html='<details'.($open ? ' open' : '').'><summary>'.self::e($label).' <span class="dfd-muted">'.self::e((string)$total).' rows</span></summary>';
		if($omitted>0){
			$html.='<p class="dfd-muted">'.self::e((string)$omitted).' earlier rows omitted because PHP memory is tightly limited.</p>';
		}
		$html.='<div class="dfd-trace-log">';
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$html.=self::render_trace_line($entry);
		}
		$html.='</div>';
		return $html.'</details>';
	}

	/**
	 * Renders one normalized trace row.
	 *
	 * Prefix metadata, call markers, parameter previews, message content, and
	 * captured argument details are composed from the normalized trace contract.
	 * Message HTML is used only when it has passed the trace_inline_html() safety
	 * boundary.
	 *
	 * @param array<string,mixed> $entry Normalized trace row.
	 * @return string Trace line HTML.
	 */
	private static function render_trace_line(array $entry): string {
		$offset=$entry['offset_ms'] ?? null;
		$when=is_numeric($offset) ? self::format_ms((float)$offset) : '';
		if($when==='' && is_numeric($entry['timestamp'] ?? null)){
			$when=date('H:i:s', (int)$entry['timestamp']);
		}
		$memory_label=(string)($entry['memory_label'] ?? '');
		if($memory_label==='' && (int)($entry['memory_bytes'] ?? 0)>0){
			$memory_label=self::format_bytes((int)$entry['memory_bytes']);
		}
		$type=(string)($entry['type'] ?? 'info');
		$file=(string)($entry['file'] ?? '');
		$line=(string)($entry['line'] ?? '');
		$source=trim(($file!=='' ? basename($file) : '').($line!=='' ? ':'.$line : ''), ':');
		$message=(string)($entry['message'] ?? $entry['raw'] ?? '');
		$message_html=(string)($entry['message_html'] ?? '');
		$arguments=$entry['arguments'] ?? [];
		$parameter_shape=trim((string)($entry['parameter_shape'] ?? ''));
		$tone=self::level_tone($type);
		$prefix_parts=array_values(array_filter([
			$when!=='' ? $when : (string)($entry['origin'] ?? 'trace'),
			$memory_label,
			$source,
		], static fn(string $part): bool => trim($part)!==''));
		$call=(string)($entry['call'] ?? '');
		$call_kind=(string)($entry['call_kind'] ?? self::trace_call_kind($type, $call, $message));
		$call_color=(string)($entry['call_color'] ?? self::trace_call_color($call));
		$html='<div class="dfd-trace-line'.($tone!=='' ? ' dfd-'.$tone : '').'">';
		$html.='<span class="dfd-trace-prefix">'.self::e(implode(', ', $prefix_parts));
		if($call_kind!=='' || $call!==''){
			$html.=($prefix_parts!==[] ? ', ' : '').'<span class="dfd-trace-kind">'.self::e($call_kind!=='' ? $call_kind : 'FC').'</span>';
			if($call!==''){
				$html.=' <span class="dfd-trace-call"'.($call_color!=='' ? ' style="color:'.self::e($call_color).'"' : '').'>'.self::e($call).'</span>';
			}
			if($parameter_shape!==''){
				$html.='<span class="dfd-trace-shape">('.self::e($parameter_shape).')</span>';
			}
		}
		elseif($type!=='' && $type!=='info'){
			$html.=($prefix_parts!==[] ? ', ' : '').self::e($type);
		}
		$html.=' &gt; </span>';
		$html.='<span class="dfd-trace-message">'.($message_html!=='' ? $message_html : self::e($message)).'</span>';
		if(is_array($arguments) && $arguments!==[]){
			$html.='<details class="dfd-trace-args"><summary>values</summary><pre>'.self::e(self::json($arguments)).'</pre></details>';
		}
		return $html.'</div>';
	}

	/**
	 * Generates scoped CSS for the Tracelog panel and call graph.
	 *
	 * The rules are namespaced by the supplied selector so embedded debugbar
	 * styles do not bleed into application pages.
	 *
	 * @param string $scope CSS selector prefix for the active Flightdeck shell.
	 * @return string Scoped CSS rules.
	 */
	private static function trace_css(string $scope): string {
		return $scope.' .dfd-trace-plot{position:relative;margin:8px 0 10px;border:1px solid rgba(125,211,252,.18);border-radius:8px;background:#020817;overflow:auto}'
			.$scope.' .dfd-trace-plot-svg{display:block;width:100%;min-width:720px;height:340px;cursor:grab;touch-action:none}'
			.$scope.' .dfd-trace-plot-svg:active{cursor:grabbing}'
			.$scope.' .dfd-trace-plot-svg line{stroke:#7dd3fc;stroke-opacity:.38}'
			.$scope.' .dfd-trace-plot-svg path{fill:none;stroke:#7dd3fc;stroke-opacity:.34}'
			.$scope.' .dfd-trace-plot[data-dfd-cloud-ready="1"] .dfd-trace-plot-static{display:none}'
			.$scope.' .dfd-trace-plot-node circle{stroke:#07111f;stroke-width:2px}'
			.$scope.' .dfd-trace-plot-node{cursor:grab}'
			.$scope.' .dfd-trace-plot-node:active{cursor:grabbing}'
			.$scope.' .dfd-trace-plot-node text{font:3.7px ui-sans-serif,system-ui,sans-serif;fill:#e6f0ff;paint-order:stroke;stroke:#020817;stroke-width:1px;stroke-linejoin:round}'
			.$scope.' .dfd-trace-log{margin:8px 0 10px;padding:8px 10px;max-height:420px;overflow:auto;border:1px solid rgba(255,255,255,.08);border-radius:6px;background:#020817;color:#dbeafe;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;line-height:1.48;white-space:pre-wrap}'
			.$scope.' .dfd-trace-line{display:block;margin:0;padding:0;color:#dbeafe;word-break:break-word}'
			.$scope.' .dfd-trace-line.dfd-warn .dfd-trace-prefix{color:#fbbf24}'
			.$scope.' .dfd-trace-line.dfd-bad .dfd-trace-prefix{color:#fca5a5}'
			.$scope.' .dfd-trace-prefix{color:rgba(125,211,252,.78)}'
			.$scope.' .dfd-trace-kind{color:#67e8f9;font-weight:800}'
			.$scope.' .dfd-trace-call{font-weight:800}'
			.$scope.' .dfd-trace-shape{color:#c4b5fd;margin-left:0}'
			.$scope.' .dfd-trace-message{color:#e6f0ff}'
			.$scope.' .dfd-trace-message span{font-weight:700}'
			.$scope.' .dfd-trace-args{display:inline;margin-left:6px;color:#9fb6d8}'
			.$scope.' .dfd-trace-args summary{display:inline;cursor:pointer;color:#9fb6d8}'
			.$scope.' .dfd-trace-args pre{margin:4px 0 6px 16px;padding:6px;border-left:1px solid rgba(125,211,252,.22);background:transparent;color:#bfdbfe;white-space:pre-wrap}'
			.self::reference_css($scope);
	}

}
