<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SUPPORT_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SUPPORT_TRAIT_LOADED', true);

/**
 * Shared support helpers for Flightdeck Debugbar assets, snapshots, and safety.
 *
 * The trait provides cache-versioned asset lookup, debugbar CSS/JS bodies,
 * request and context sanitization, memory headroom checks, signed cookie
 * helpers, and small formatting utilities used by the toolbar and snapshot UI.
 */
trait dataphyre_flightdeck_debugbar_support {

	/**
	 * Builds a cache-versioned Debugbar asset URL.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Public Debugbar asset URL with a short content hash.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/debugbar/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Returns the short content hash used for Debugbar asset cache busting.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-1 prefix, or "missing" when unknown.
	 */
	public static function asset_version(string $asset): string {
		static $versions=[];
		$name=self::asset_name($asset);
		if(isset($versions[$name])){
			return $versions[$name];
		}
		$content=self::asset_content($name);
		if($content===null){
			return 'missing';
		}
		$versions[$name]=substr(sha1((string)$content['body']), 0, 16);
		return $versions[$name];
	}

	/**
	 * Returns inline Debugbar asset content and content type.
	 *
	 *
	 * @param string $asset Requested asset filename.
	 * @return ?array{content_type:string,body:string} Asset payload, or null when unknown.
	 */
	public static function asset_content(string $asset): ?array {
		static $assets=[];
		$name=self::asset_name($asset);
		if(isset($assets[$name])){
			return $assets[$name];
		}
		$content=match($name){
			'debugbar.css'=>[
				'content_type'=>'text/css; charset=UTF-8',
				'body'=>self::toolbar_css(),
			],
			'debugbar.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'body'=>self::script_body(self::layout_script()),
			],
			'debugbar-snapshot.css'=>[
				'content_type'=>'text/css; charset=UTF-8',
				'body'=>self::snapshot_css(),
			],
			'debugbar-snapshot.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'body'=>self::script_body(self::snapshot_script()),
			],
			default=>null,
		};
		if($content!==null){
			$assets[$name]=$content;
		}
		return $content;
	}

	/**
	 * Normalizes an asset request to a safe Debugbar asset basename.
	 *
	 * directory separators are collapsed to a basename and only
	 * alphanumeric, dot, underscore, and dash characters are accepted. Invalid names
	 * return an empty string so asset lookup cannot traverse the filesystem or serve
	 * arbitrary embedded payloads.
	 *
	 * @param string $asset Raw asset path or request value.
	 * @return string Safe asset basename, or an empty string when invalid.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Extracts JavaScript from a generated script tag.
	 *
	 * Debugbar assets are authored as script-tag fragments for inline UI
	 * use, then stripped to a JavaScript body for asset responses. Raw JavaScript is
	 * returned unchanged after trimming.
	 *
	 * @param string $script Script tag or raw JavaScript.
	 * @return string JavaScript body suitable for an asset response.
	 */
	private static function script_body(string $script): string {
		$script=trim($script);
		if(preg_match('/^<script\b[^>]*>(.*)<\/script>$/is', $script, $matches)===1){
			return trim((string)$matches[1])."\n";
		}
		return $script;
	}

	/**
	 * Maps PHP error constants to Debugbar severity names.
	 *
	 * PHP fatal and warning families are collapsed to toolbar severities;
	 * notices, deprecated warnings, and unknown integer codes are treated as
	 * informational so diagnostics stay displayable across PHP versions.
	 *
	 * @param int $errno PHP error constant.
	 * @return string Debugbar severity label.
	 */
	private static function php_error_severity(int $errno): string {
		if(in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR], true)){
			return 'error';
		}
		if(in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING], true)){
			return 'warning';
		}
		if(in_array($errno, [E_NOTICE, E_USER_NOTICE, 2048, E_DEPRECATED, E_USER_DEPRECATED], true)){
			return 'info';
		}
		return 'info';
	}

	/**
	 * Determines whether a PHP error code represents a fatal stop.
	 *
	 * @param int $errno PHP error constant.
	 * @return bool True for fatal engine, parse, compile, or user errors.
	 */
	private static function is_fatal_error(int $errno): bool {
		return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
	}

	/**
	 * Converts a diagnostic level into a CSS tone suffix.
	 *
	 * @param string $level Diagnostic level label.
	 * @return string CSS tone name, or an empty string for neutral levels.
	 */
	private static function level_tone(string $level): string {
		return match(strtolower(trim($level))){
			'fatal', 'error'=>'bad',
			'warning'=>'warn',
			default=>'',
		};
	}

	/**
	 * Parses PHP ini byte notation into an integer byte count.
	 *
	 * `-1` and empty values are treated as unlimited because memory-limit
	 * callers need a zero sentinel. The parser accepts PHP-style k/m/g suffixes and
	 * falls back to the numeric value for raw byte strings.
	 *
	 * @param string $value Raw ini byte value.
	 * @return int Parsed byte count, or 0 for unlimited/empty limits.
	 */
	private static function parse_ini_bytes(string $value): int {
		$value=trim($value);
		if($value==='' || $value==='-1'){
			return 0;
		}
		$unit=strtolower(substr($value, -1));
		$number=(float)$value;
		return (int)match($unit){
			'g'=>$number * 1073741824,
			'm'=>$number * 1048576,
			'k'=>$number * 1024,
			default=>$number,
		};
	}

	/**
	 * Returns the active PHP memory limit in bytes.
	 *
	 * @return int Byte limit, or 0 when PHP reports no limit.
	 */
	private static function memory_limit_bytes(): int {
		return self::parse_ini_bytes((string)ini_get('memory_limit'));
	}

	/**
	 * Estimates remaining request memory available to Debugbar.
	 *
	 * unlimited memory returns PHP_INT_MAX. Limited memory subtracts the
	 * real allocated usage so snapshot rendering can avoid large optional payloads
	 * when the request is near exhaustion.
	 *
	 * @return int Remaining bytes, or PHP_INT_MAX when unlimited.
	 */
	private static function memory_remaining_bytes(): int {
		$limit=self::memory_limit_bytes();
		if($limit<=0){
			return PHP_INT_MAX;
		}
		return max(0, $limit - memory_get_usage(true));
	}

	/**
	 * Checks whether enough memory remains for an optional Debugbar payload.
	 *
	 * a reserve is always kept for shutdown handling and error rendering.
	 * The reserve scales with the configured limit and is clamped to avoid
	 * over-consuming small memory budgets.
	 *
	 * @param int $bytes Estimated bytes required by the optional payload.
	 * @param int $reserve Minimum reserve bytes to keep free.
	 * @return bool True when the payload can be attempted safely.
	 */
	private static function has_memory_headroom(int $bytes, int $reserve=2097152): bool {
		$limit=self::memory_limit_bytes();
		if($limit<=0){
			return true;
		}
		$reserve=max($reserve, min(4194304, max(1048576, (int)floor($limit * 0.12))));
		return self::memory_remaining_bytes() > ($bytes + $reserve);
	}

	/**
	 * Detects low-memory PHP configurations for cheaper Debugbar behavior.
	 *
	 * @return bool True when the configured memory limit is at or below 32 MiB.
	 */
	private static function memory_limit_is_tight(): bool {
		$limit=self::memory_limit_bytes();
		return $limit>0 && $limit<=33554432;
	}

	/**
	 * Returns the snapshot-history stylesheet.
	 *
	 * the CSS is embedded in PHP so the control plane can serve Debugbar
	 * history without external build artifacts. It composes panel navigation,
	 * stack-snippet, and trace styles into the `.dfd-history` scope.
	 *
	 * @return string CSS for the Debugbar snapshot UI.
	 */
	private static function snapshot_css(): string {
		return '.dfd-history{display:grid;gap:14px;color:#e6f0ff}.dfd-history *{box-sizing:border-box;letter-spacing:0}.dfd-history a{color:#8bd3ff}.dfd-history h2{margin:.2rem 0 0;color:#fff}.dfd-history .dfd-snapshot-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;background:#07111f;border:1px solid rgba(144,205,244,.25);border-radius:12px;padding:14px}.dfd-history .dfd-pills{display:flex;gap:6px;flex-wrap:wrap}.dfd-history .dfd-pill{display:inline-flex;align-items:center;gap:5px;max-width:360px;padding:5px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#e6f0ff}.dfd-history .dfd-pill.dfd-good{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.38);color:#bbf7d0}.dfd-history .dfd-pill.dfd-warn{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.4);color:#ffe0a3}.dfd-history .dfd-pill.dfd-bad{background:rgba(239,68,68,.16);border-color:rgba(239,68,68,.45);color:#fecaca}.dfd-history details.dfd-panel{background:#07111f;border:1px solid rgba(144,205,244,.22);border-radius:12px;overflow:hidden}.dfd-history details.dfd-panel>summary{cursor:pointer;display:flex;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px;color:#d9ecff}.dfd-history .dfd-panel-body{padding:0 12px 12px}.dfd-history .dfd-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}.dfd-history .dfd-metric{border:1px solid rgba(255,255,255,.09);border-radius:8px;padding:8px;background:rgba(255,255,255,.04);min-width:0}.dfd-history .dfd-metric span{display:block;color:#9fb6d8;font-size:11px}.dfd-history .dfd-metric b{display:block;margin-top:2px;color:#fff;font-size:14px;overflow:hidden;text-overflow:ellipsis}.dfd-history .dfd-table{width:100%;border-collapse:collapse;background:transparent;color:#e6f0ff}.dfd-history .dfd-table th,.dfd-history .dfd-table td{padding:6px;border-top:1px solid rgba(255,255,255,.08);vertical-align:top;text-align:left}.dfd-history .dfd-table th{color:#9fb6d8;font-weight:700;background:transparent}.dfd-history .dfd-timeline{display:grid;gap:7px;margin:8px 0}.dfd-history .dfd-tick{display:grid;grid-template-columns:72px 88px 1fr;gap:8px;align-items:center}.dfd-history .dfd-track{height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}.dfd-history .dfd-bar{height:100%;min-width:2px;border-radius:999px;background:#38bdf8}.dfd-history .dfd-range-track{position:relative;height:10px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}.dfd-history .dfd-range-bar{position:absolute;top:0;bottom:0;min-width:2px;border-radius:999px;background:#38bdf8}.dfd-history .dfd-bar.dfd-good,.dfd-history .dfd-range-bar.dfd-good{background:#22c55e}.dfd-history .dfd-bar.dfd-warn,.dfd-history .dfd-range-bar.dfd-warn{background:#f59e0b}.dfd-history .dfd-bar.dfd-bad,.dfd-history .dfd-range-bar.dfd-bad{background:#ef4444}.dfd-history .dfd-code{max-height:260px;overflow:auto;margin:6px 0 0;padding:8px;border-radius:8px;background:#020817;color:#dbeafe;border:1px solid rgba(255,255,255,.08);white-space:pre-wrap}'.self::panel_nav_css('.dfd-history').self::stack_snippet_css('.dfd-history').self::trace_css('.dfd-history').'.dfd-history .dfd-muted{color:#9fb6d8}@media(max-width:900px){.dfd-history .dfd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.dfd-history .dfd-grid{grid-template-columns:1fr}.dfd-history .dfd-tick{grid-template-columns:1fr}}';
	}

	/**
	 * Builds reusable panel navigation CSS for a scoped Debugbar surface.
	 *
	 * @param string $scope CSS selector prefix for the host surface.
	 * @return string CSS rules for filter controls and panel navigation buttons.
	 */
	private static function panel_nav_css(string $scope): string {
		return $scope.' .dfd-panel-nav{position:sticky;top:0;z-index:2;display:flex;gap:6px;align-items:center;overflow:auto;padding:8px 12px;background:rgba(7,17,31,.96);border-bottom:1px solid rgba(255,255,255,.09);scrollbar-width:thin}'
			.$scope.' .dfd-filter{display:inline-flex;align-items:center;gap:6px;box-sizing:border-box;height:26px;min-width:min(280px,50vw);margin:0;padding:0;border:0;color:#9fb6d8;white-space:nowrap;line-height:1;vertical-align:middle}'
			.$scope.' .dfd-filter>span{display:inline-flex;align-items:center;height:26px;line-height:1}'
			.$scope.' .dfd-filter input{display:block;box-sizing:border-box;width:100%;height:26px;min-height:26px;margin:0;border-radius:999px;border:1px solid rgba(144,205,244,.2);background:rgba(2,8,23,.82);color:#e6f0ff;padding:4px 9px;line-height:16px;outline:none;appearance:none}'
			.$scope.' .dfd-filter input:focus{border-color:rgba(56,189,248,.55);box-shadow:0 0 0 1px rgba(56,189,248,.22)}'
			.$scope.' .dfd-filter-status{display:inline-flex;align-items:center;height:26px;min-width:48px;color:#9fb6d8;white-space:nowrap;line-height:1}'
			.$scope.' .dfd-nav-btn{display:inline-flex;align-items:center;box-sizing:border-box;gap:5px;height:26px;min-height:26px;margin:0;padding:4px 8px;border-radius:999px;border:1px solid rgba(144,205,244,.18);background:rgba(255,255,255,.045);color:#d9ecff;white-space:nowrap;line-height:1;cursor:pointer}'
			.$scope.' .dfd-nav-btn:hover,'.$scope.' .dfd-nav-btn.dfd-active{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.45);color:#fff}'
			.$scope.' .dfd-nav-btn.dfd-warn{border-color:rgba(245,158,11,.38);color:#ffe0a3}'
			.$scope.' .dfd-nav-btn.dfd-bad{border-color:rgba(239,68,68,.42);color:#fecaca}'
			.$scope.' .dfd-nav-btn[data-dfd-filter-hidden="1"]{display:none}';
	}

	/**
	 * Builds reusable source-reference link CSS for a scoped Debugbar surface.
	 *
	 * @param string $scope CSS selector prefix for the host surface.
	 * @return string CSS rules for source reference links.
	 */
	private static function reference_css(string $scope): string {
		return $scope.' details.dfd-panel{scroll-margin-top:12px}'
			.$scope.' .dfd-ref-list{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}'
			.$scope.' .dfd-ref{display:inline-flex;align-items:center;gap:4px;width:max-content;max-width:100%;padding:3px 7px;border:1px solid rgba(125,211,252,.28);border-radius:999px;background:rgba(14,165,233,.1);color:#dff6ff;text-decoration:none;font-size:11px;font-weight:800;white-space:nowrap}'
			.$scope.' .dfd-ref:hover{background:rgba(14,165,233,.2);border-color:rgba(125,211,252,.48);color:#fff}';
	}

	/**
	 * Formats a millisecond duration for escaped Debugbar output.
	 *
	 * @param float $value Duration in milliseconds.
	 * @return string Escaped compact duration label.
	 */
	private static function format_ms(float $value): string {
		if($value>=1000){
			return self::e((string)round($value / 1000, 2)).'s';
		}
		return self::e((string)round($value, 2)).'ms';
	}

	/**
	 * Formats bytes for escaped Debugbar output.
	 *
	 * @param int $bytes Byte count.
	 * @return string Escaped compact size label.
	 */
	private static function format_bytes(int $bytes): string {
		if($bytes>=1073741824){
			return self::e((string)round($bytes / 1073741824, 2)).'gb';
		}
		if($bytes>=1048576){
			return self::e((string)round($bytes / 1048576, 2)).'mb';
		}
		if($bytes>=1024){
			return self::e((string)round($bytes / 1024, 2)).'kb';
		}
		return self::e((string)$bytes).'b';
	}

	/**
	 * Formats a client-provided timestamp for snapshot display.
	 *
	 * browser APIs may report either seconds or milliseconds depending on
	 * the captured value. Very large values are treated as milliseconds before
	 * conversion to a local time label.
	 *
	 * @param float $milliseconds Client timestamp or elapsed marker.
	 * @return string `H:i:s` label, or `none` for empty values.
	 */
	private static function client_time_label(float $milliseconds): string {
		if($milliseconds<=0){
			return 'none';
		}
		$seconds=$milliseconds>100000000000 ? $milliseconds / 1000 : $milliseconds;
		return date('H:i:s', (int)$seconds);
	}

	/**
	 * Encodes a value for readable JSON diagnostics.
	 *
	 * @param mixed $value Value to encode.
	 * @return string Pretty JSON, or `null` when encoding fails.
	 */
	private static function json(mixed $value): string {
		$json=json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : 'null';
	}

	/**
	 * Normalizes and truncates a diagnostic string.
	 *
	 * @param string $value Raw diagnostic text.
	 * @param int $max Maximum output length.
	 * @return string Single-line shortened text.
	 */
	private static function shorten(string $value, int $max=120): string {
		$value=preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
		if(strlen($value)<=$max){
			return $value;
		}
		return substr($value, 0, max(1, $max - 3)).'...';
	}

	/**
	 * Collects request headers from the active PHP runtime.
	 *
	 * getallheaders() is preferred when available, with a $_SERVER
	 * fallback that reconstructs HTTP_* headers plus content metadata. Values are
	 * captured for diagnostics and must be sanitized before display when sensitive.
	 *
	 * @return array<string, mixed> Request headers keyed by conventional header name.
	 */
	private static function request_headers(): array {
		if(function_exists('getallheaders')){
			$headers=getallheaders();
			if(is_array($headers)){
				return $headers;
			}
		}
		$headers=[];
		foreach($_SERVER ?? [] as $key=>$value){
			if(str_starts_with((string)$key, 'HTTP_')===false){
				continue;
			}
			$name=str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string)$key, 5)))));
			$headers[$name]=$value;
		}
		foreach(['CONTENT_TYPE'=>'Content-Type', 'CONTENT_LENGTH'=>'Content-Length'] as $server_key=>$header_name){
			if(isset($_SERVER[$server_key])){
				$headers[$header_name]=$_SERVER[$server_key];
			}
		}
		return $headers;
	}

	/**
	 * Sanitizes a top-level diagnostic context payload.
	 *
	 * every value is routed through sanitize_value() with its key so
	 * sensitive names are redacted consistently before context is stored or shown in
	 * the toolbar.
	 *
	 * @param array<string|int, mixed> $context Raw context payload.
	 * @return array<string, mixed> Sanitized context keyed by string names.
	 */
	private static function sanitize_context(array $context): array {
		$sanitized=[];
		foreach($context as $key=>$value){
			$key=(string)$key;
			$sanitized[$key]=self::sanitize_value($value, $key);
		}
		return $sanitized;
	}

	/**
	 * Sanitizes one diagnostic value for retention and display.
	 *
	 * sensitive keys are redacted, strings are length-limited, arrays are
	 * bounded by depth and item count, and objects are represented by debug type.
	 * This prevents Debugbar snapshots from leaking credentials or retaining
	 * unbounded application graphs.
	 *
	 * @param mixed $value Raw diagnostic value.
	 * @param string $key Current key name used for sensitivity checks.
	 * @param int $depth Current recursion depth.
	 * @return mixed Sanitized scalar, array, type marker, or redaction marker.
	 */
	private static function sanitize_value(mixed $value, string $key='', int $depth=0): mixed {
		if($depth>4){
			return '[depth-limit]';
		}
		if(self::is_sensitive_key($key)===true){
			return '[redacted]';
		}
		if(is_string($value)){
			return strlen($value)>1200 ? substr($value, 0, 1200).'...' : $value;
		}
		if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return $value;
		}
		if(is_array($value)){
			$resolved=[];
			$count=0;
			foreach($value as $nested_key=>$nested_value){
				if($count>=40){
					$resolved['...']='truncated';
					break;
				}
				$resolved[$nested_key]=self::sanitize_value($nested_value, (string)$nested_key, $depth+1);
				$count++;
			}
			return $resolved;
		}
		if(is_object($value)){
			return '[object '.get_debug_type($value).']';
		}
		return get_debug_type($value);
	}

	/**
	 * Detects key names that should never be exposed in Debugbar diagnostics.
	 *
	 * @param string $key Candidate context key.
	 * @return bool True when the key name implies credential or token material.
	 */
	private static function is_sensitive_key(string $key): bool {
		return preg_match('/password|passwd|secret|token|csrf|api[_-]?key|authorization|cookie/i', $key)===1;
	}

	/**
	 * Returns a trimmed string or a fallback.
	 *
	 * @param mixed $value Candidate string value.
	 * @param string $fallback Fallback when the value is not a non-empty string.
	 * @return string Trimmed string or fallback.
	 */
	private static function string_or(mixed $value, string $fallback=''): string {
		return is_string($value) && trim($value)!=='' ? trim($value) : $fallback;
	}

	/**
	 * Normalizes a mixed value into a unique non-empty string list.
	 *
	 * @param mixed $value Candidate list value.
	 * @return array<int, string> Unique trimmed string entries.
	 */
	private static function string_list(mixed $value): array {
		if(!is_array($value)){
			return [];
		}
		$resolved=[];
		foreach($value as $entry){
			if(is_string($entry) && trim($entry)!==''){
				$resolved[]=trim($entry);
			}
		}
		return array_values(array_unique($resolved));
	}

	/**
	 * Returns the normalized current request path.
	 *
	 * @return string Request path with a leading slash and collapsed separators.
	 */
	private static function current_path(): string {
		$uri=(string)($_SERVER['REQUEST_URI'] ?? '');
		$path=parse_url($uri, PHP_URL_PATH);
		$path=is_string($path) ? $path : $uri;
		$path='/'.ltrim($path, '/');
		return preg_replace('#/+#', '/', $path) ?? $path;
	}

	/**
	 * Checks whether the active request targets Dataphyre's control plane.
	 *
	 * @return bool True for `/dataphyre` and nested control-plane paths.
	 */
	private static function is_control_plane_request(): bool {
		return self::is_control_plane_path(self::current_path());
	}

	/**
	 * Checks whether a path belongs to the Dataphyre control plane.
	 *
	 * paths are normalized before comparison so duplicate slashes and
	 * missing leading slashes do not bypass Debugbar suppression checks.
	 *
	 * @param string $path Request path to inspect.
	 * @return bool True for `/dataphyre` and nested control-plane paths.
	 */
	private static function is_control_plane_path(string $path): bool {
		$path='/'.ltrim(trim($path), '/');
		$path=preg_replace('#/+#', '/', $path) ?? $path;
		return $path==='/dataphyre' || str_starts_with($path, '/dataphyre/');
	}

	/**
	 * Escapes text for HTML output.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Verifies a signed Debugbar session cookie.
	 *
	 * cookies are `base64url(payload).hmac` values. Verification accepts
	 * current and legacy app-bound secrets, uses hash_equals() for signatures, and
	 * requires an unexpired JSON payload before enabling toolbar access.
	 *
	 * @param string $token Cookie token to verify.
	 * @return bool True when the signature is valid and the payload is unexpired.
	 */
	private static function verify_cookie(string $token): bool {
		if($token===''){
			return false;
		}
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return false;
		}
		[$data, $signature]=$parts;
		$valid_signature=false;
		foreach(self::secret_candidates() as $secret){
			$expected=hash_hmac('sha256', $data, $secret);
			if(hash_equals($expected, $signature)===true){
				$valid_signature=true;
				break;
			}
		}
		if($valid_signature!==true){
			return false;
		}
		$payload=json_decode(self::base64url_decode($data), true);
		return is_array($payload) && (int)($payload['exp'] ?? 0)>=time();
	}

	/**
	 * Returns the current Debugbar cookie signing secret.
	 *
	 * @return string Secret derived from Flightdeck auth material.
	 */
	private static function secret(): string {
		return self::secret_for_app(null);
	}

	/**
	 * Derives a Debugbar cookie signing secret for an app context.
	 *
	 * secret material is based on Flightdeck password hashes/plaintext
	 * fallback configuration, optional app identity, and project root. Missing auth
	 * falls back to a deterministic disabled-installation secret so verification
	 * remains well-defined without exposing real credentials.
	 *
	 * @param string|null $app Optional application identity for legacy cookies.
	 * @return string SHA-256 signing secret.
	 */
	private static function secret_for_app(?string $app): string {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return hash('sha256', 'flightdeck-debugbar|missing-auth');
		}
		$config=dataphyre_flightdeck_auth::config();
		$material=[
			$config['password_hash'] ?? '',
			$config['developer_password_hash'] ?? '',
			$config['password'] ?? '',
			$config['developer_password'] ?? '',
			$app,
			defined('DATAPHYRE_PROJECT_ROOT') ? DATAPHYRE_PROJECT_ROOT : '',
		];
		return hash('sha256', 'flightdeck-debugbar|'.implode('|', array_map('strval', $material)));
	}

	/**
	 * Returns all accepted Debugbar cookie signing secrets.
	 *
	 * the current app-neutral secret is first, followed by legacy
	 * app-bound secrets so older cookies can be verified during configuration
	 * transitions without widening the accepted material beyond known app names.
	 *
	 * @return array<int, string> Unique signing secrets.
	 */
	private static function secret_candidates(): array {
		$secrets=[self::secret()];
		foreach(self::legacy_secret_apps() as $app){
			$secrets[]=self::secret_for_app($app);
		}
		return array_values(array_unique($secrets));
	}

	/**
	 * Discovers legacy application names used by older Debugbar cookies.
	 *
	 * @return array<int, string> Unique application identifiers from constants/bootstrap config.
	 */
	private static function legacy_secret_apps(): array {
		$apps=[];
		if(defined('APP') && is_string(APP) && APP!==''){
			$apps[]=APP;
		}
		$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') && is_array(DATAPHYRE_BOOTSTRAP_CONFIG)
			? DATAPHYRE_BOOTSTRAP_CONFIG
			: ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
		if(is_array($bootstrap) && is_string($bootstrap['app'] ?? null) && $bootstrap['app']!==''){
			$apps[]=$bootstrap['app'];
		}
		return array_values(array_unique($apps));
	}

	/**
	 * Derives the production replay signing secret.
	 *
	 * replay requires Flightdeck password material. The secret also binds
	 * to license and project-root context so captured replay tokens cannot move
	 * freely between installations. Missing password material disables replay by
	 * returning an empty secret.
	 *
	 * @return string Replay secret hash, or an empty string when replay is unavailable.
	 */
	private static function replay_secret(): string {
		$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') && is_array(DATAPHYRE_BOOTSTRAP_CONFIG)
			? DATAPHYRE_BOOTSTRAP_CONFIG
			: ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
		if(!is_array($bootstrap)){
			$bootstrap=[];
		}
		$flightdeck=$bootstrap['flightdeck'] ?? [];
		if(class_exists('dataphyre_flightdeck_auth', false)===true){
			$flightdeck=array_replace_recursive(is_array($flightdeck) ? $flightdeck : [], dataphyre_flightdeck_auth::config());
		}
		if(!is_array($flightdeck)){
			$flightdeck=[];
		}
		$password_material=[];
		foreach(['password_hash', 'developer_password_hash', 'password', 'developer_password'] as $key){
			$value=$flightdeck[$key] ?? null;
			if(is_string($value) && trim($value)!==''){
				$password_material[]=trim($value);
			}
		}
		if($password_material===[]){
			return '';
		}
		$license=$bootstrap['license'] ?? (defined('LICENSE') ? LICENSE : '');
		$license_key=is_array($license) ? (string)($license['key'] ?? '') : (string)$license;
		$material=array_merge($password_material, [
			$license_key,
			defined('DATAPHYRE_PROJECT_ROOT') ? DATAPHYRE_PROJECT_ROOT : '',
		]);
		return hash('sha256', 'flightdeck-production-replay|'.implode('|', $material));
	}

	/**
	 * Writes the signed Debugbar cookie and mirrors it into the current request.
	 *
	 * cookies are HTTP-only, SameSite=Strict, scoped to the application
	 * root, and marked secure when the request is HTTPS or behind an HTTPS proxy.
	 * Updating $_COOKIE keeps the current request lifecycle consistent after writes.
	 *
	 * @param string $value Cookie token value.
	 * @param int $expires Unix expiry timestamp.
	 * @return void
	 */
	private static function set_cookie(string $value, int $expires): void {
		setcookie(self::COOKIE, $value, [
			'expires'=>$expires,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		$_COOKIE[self::COOKIE]=$value;
	}

	/**
	 * Determines whether Debugbar cookies should be marked secure.
	 *
	 * @return bool True when the current request appears to be HTTPS.
	 */
	private static function secure_cookie(): bool {
		return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
			|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https');
	}

	/**
	 * Encodes binary-safe data for compact cookie tokens.
	 *
	 * @param string|false $value Raw value to encode.
	 * @return string Base64url encoded value without padding.
	 */
	private static function base64url_encode(string|false $value): string {
		return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
	}

	/**
	 * Decodes a base64url token segment.
	 *
	 * @param string $value Base64url encoded value.
	 * @return string Decoded bytes, or an empty string when decoding fails.
	 */
	private static function base64url_decode(string $value): string {
		$padding=strlen($value) % 4;
		if($padding>0){
			$value.=str_repeat('=', 4 - $padding);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return is_string($decoded) ? $decoded : '';
	}

}
