<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Flightdeck runtime toolbar.
 *
 * Loaded only when the signed toolbar cookie is present or from the
 * Flightdeck control panel toggle.
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_LOADED', true);

$flightdeck_auth_file=__DIR__.'/auth.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}
$flightdeck_stack_snippets_file=__DIR__.'/stack_snippets.php';
if(is_file($flightdeck_stack_snippets_file)){
	require_once($flightdeck_stack_snippets_file);
}

$flightdeck_debugbar_trait_files=[
	__DIR__.'/debugbar/history.php',
	__DIR__.'/debugbar/state.php',
	__DIR__.'/debugbar/trace.php',
	__DIR__.'/debugbar/sql.php',
	__DIR__.'/debugbar/stack.php',
	__DIR__.'/debugbar/render.php',
	__DIR__.'/debugbar/scripts.php',
	__DIR__.'/debugbar/assets.php',
	__DIR__.'/debugbar/support.php',
];
foreach($flightdeck_debugbar_trait_files as $flightdeck_debugbar_trait_file){
	require_once($flightdeck_debugbar_trait_file);
}

/**
 * Captures request diagnostics and injects the Flightdeck runtime toolbar.
 *
 * The debugbar coordinates authentication-gated enablement, trace capture, SQL
 * and PHP error observers, shutdown status repair, request snapshots, memory
 * limit adjustments, and safe toolbar injection into HTML responses. It is
 * intentionally tolerant of partial bootstrap state and low-memory requests.
 */
final class dataphyre_flightdeck_debugbar {

	private const COOKIE='dataphyre_flightdeck_debugbar';
	private const SQL_BUFFER_LIMIT=240;
	private const ERROR_BUFFER_LIMIT=120;
	private const HISTORY_KEY='dataphyre_flightdeck_debugbar_history';
	private const HISTORY_LIMIT=12;
	private const HISTORY_SQL_EVENT_LIMIT=80;
	private const CLIENT_EVENT_LIMIT=120;
	private const CLIENT_BATCH_LIMIT=40;
	private const CLIENT_RESOURCE_TIMING_LIMIT=32;
	private const TRACE_TIGHT_RENDER_LIMIT=80;
	private const TRACE_HISTORY_LIMIT=160;
	private static bool $sql_observer_attached=false;
	private static bool $error_observer_attached=false;
	private static bool $shutdown_observer_attached=false;
	private static bool $response_status_guard_attached=false;
	private static int $response_status_guard_pass=0;
	private static int $injection_response_status=0;
	private static mixed $previous_error_handler=null;

	use dataphyre_flightdeck_debugbar_history;
	use dataphyre_flightdeck_debugbar_state;
	use dataphyre_flightdeck_debugbar_trace;
	use dataphyre_flightdeck_debugbar_sql;
	use dataphyre_flightdeck_debugbar_stack;
	use dataphyre_flightdeck_debugbar_render;
	use dataphyre_flightdeck_debugbar_scripts;
	use dataphyre_flightdeck_debugbar_assets;
	use dataphyre_flightdeck_debugbar_support;

	/**
	 * Checks whether the toolbar is authorized and enabled for this request.
	 *
	 * The Flightdeck auth class must be loaded, the console/debugbar auth policy
	 * must allow rendering, and the signed debugbar toggle cookie must verify.
	 *
	 * @return bool True when toolbar capture and injection may proceed.
	 */
	public static function enabled(): bool {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return false;
		}
		return dataphyre_flightdeck_auth::debugbar_allowed()===true
			&& self::verify_cookie((string)($_COOKIE[self::COOKIE] ?? ''))===true;
	}

	/**
	 * Initializes debugbar capture for an application request.
	 *
	 * Startup applies any configured memory increase, skips control-plane routes,
	 * marks the debugbar active, enables tracelog capture, and attaches SQL,
	 * error, and response-status observers.
	 *
	 * @return void
	 */
	public static function start_request(): void {
		self::apply_configured_memory_limit();
		if(self::is_control_plane_request()===true){
			return;
		}
		$GLOBALS['dataphyre_flightdeck_debugbar_active']=true;
		self::enable_tracelog_capture_when_configured();
		self::attach_sql_observer();
		self::attach_error_observer();
		self::attach_response_status_guard();
	}

	/**
	 * Enables tracelog capture according to Flightdeck debugbar configuration.
	 *
	 * Retroactive capture globals and boot constants are set before the tracelog
	 * class necessarily exists; when the class is already loaded its runtime
	 * flags are updated directly.
	 *
	 * @return void
	 */
	private static function enable_tracelog_capture_when_configured(): void {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return;
		}
		$config=dataphyre_flightdeck_auth::config();
		$debugbar=is_array($config['debugbar'] ?? null) ? $config['debugbar'] : [];
		$capture=($debugbar['capture_tracelog'] ?? true)!==false || ($debugbar['force_tracelog'] ?? false)===true;
		if($capture!==true){
			return;
		}
		$plotting=($debugbar['capture_tracelog_plotting'] ?? true)!==false || ($debugbar['force_tracelog_plotting'] ?? false)===true;
		$GLOBALS['dataphyre_tracelog_capture_retroactive']=true;
		$GLOBALS['dataphyre_flightdeck_debugbar_plotting']=$plotting;
		foreach(['TRACELOG_FORCE_ENABLE', 'TRACELOG_BOOT_ENABLE'] as $constant){
			if(defined($constant)!==true){
				define($constant, true);
			}
		}
		if($plotting===true){
			foreach(['TRACELOG_BOOT_ENABLE_PLOTTING', 'TRACELOG_BOOT_PLOTTING_ENABLE'] as $constant){
				if(defined($constant)!==true){
					define($constant, true);
				}
			}
		}
		if(class_exists('dataphyre\\tracelog', false)!==true){
			return;
		}
		try{
			\dataphyre\tracelog::$enable=true;
			if($plotting===true && method_exists('dataphyre\\tracelog', 'set_plotting')){
				\dataphyre\tracelog::set_plotting(true);
			}
		}catch(\Throwable){
		}
	}

	/**
	 * Registers the shutdown guard that restores pre-injection response status.
	 *
	 * @return void
	 */
	private static function attach_response_status_guard(): void {
		if(self::$response_status_guard_attached===true){
			return;
		}
		register_shutdown_function([self::class, 'finalize_response_status_guard']);
		self::$response_status_guard_attached=true;
	}

	/**
	 * Restores a successful response status accidentally changed at shutdown.
	 *
	 * Some late shutdown work can set a 5xx after Flightdeck has already decided
	 * the response was injectable. The guard restores the captured non-error
	 * status unless a fatal error occurred or headers are already sent.
	 *
	 * @return void
	 */
	public static function finalize_response_status_guard(): void {
		if(self::$response_status_guard_pass<1){
			self::$response_status_guard_pass++;
			register_shutdown_function([self::class, 'finalize_response_status_guard']);
			return;
		}
		$injection_status=self::$injection_response_status;
		if($injection_status<=0 || $injection_status>=400){
			return;
		}
		$final_status=(int)(http_response_code() ?: 200);
		if($final_status<500){
			return;
		}
		$error=error_get_last();
		$had_fatal=is_array($error) && self::is_fatal_error((int)($error['type'] ?? 0))===true;
		if($had_fatal===true || headers_sent()){
			return;
		}
		http_response_code($injection_status);
		$GLOBALS['dataphyre_flightdeck_debugbar_status_restored']=[
			'from'=>$final_status,
			'to'=>$injection_status,
			'at'=>time(),
		];
	}

	/**
	 * Applies the configured debugbar memory limit when it raises the ceiling.
	 *
	 * Flightdeck never lowers a request's memory limit. Applied or skipped
	 * decisions are recorded in globals so the toolbar can explain effective
	 * memory behavior.
	 *
	 * @return void
	 */
	public static function apply_configured_memory_limit(): void {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return;
		}
		$config=dataphyre_flightdeck_auth::config();
		if(($config['enabled'] ?? true)===false){
			return;
		}
		$debugbar=is_array($config['debugbar'] ?? null) ? $config['debugbar'] : [];
		if(($debugbar['enabled'] ?? true)===false){
			return;
		}
		$configured=$debugbar['memory_limit'] ?? $debugbar['request_memory_limit'] ?? null;
		$target=self::normalize_configured_memory_limit($configured);
		if($target===null){
			return;
		}
		$current=(string)ini_get('memory_limit');
		$current_bytes=self::parse_ini_bytes($current);
		$target_bytes=$target==='-1' ? 0 : self::parse_ini_bytes($target);
		if($current_bytes<=0 && $target!=='-1'){
			$GLOBALS['dataphyre_flightdeck_debugbar_memory_limit']=[
				'previous'=>$current,
				'configured'=>$target,
				'effective'=>$current,
				'applied'=>false,
			];
			return;
		}
		if($target!=='-1' && $current_bytes>0 && $target_bytes<=$current_bytes){
			$GLOBALS['dataphyre_flightdeck_debugbar_memory_limit']=[
				'previous'=>$current,
				'configured'=>$target,
				'effective'=>$current,
				'applied'=>false,
			];
			return;
		}
		$previous=@ini_set('memory_limit', $target);
		if($previous!==false){
			$GLOBALS['dataphyre_flightdeck_debugbar_memory_limit']=[
				'previous'=>(string)$previous,
				'configured'=>$target,
				'effective'=>(string)ini_get('memory_limit'),
				'applied'=>true,
			];
		}
	}

	/**
	 * Normalizes a configured memory-limit value for `ini_set()`.
	 *
	 * Numeric values, shorthand units, and `-1` are accepted. Empty, falsey, or
	 * invalid values return null and therefore leave PHP's limit unchanged.
	 *
	 * @param mixed $value Raw debugbar memory-limit config value.
	 * @return ?string Normalized PHP memory limit, or null when disabled/invalid.
	 */
	private static function normalize_configured_memory_limit(mixed $value): ?string {
		if($value===null || $value===false){
			return null;
		}
		if(is_int($value) || is_float($value)){
			return $value>0 ? (string)(int)$value : null;
		}
		$value=trim((string)$value);
		if($value==='' || in_array(strtolower($value), ['0', 'false', 'off', 'none', 'null'], true)){
			return null;
		}
		if($value==='-1'){
			return '-1';
		}
		if(preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt])(?:i?b)?$/i', $value, $matches)===1){
			$number=(string)$matches[1];
			if(str_contains($number, '.')){
				$number=rtrim(rtrim($number, '0'), '.');
			}
			return $number.strtoupper((string)$matches[2]);
		}
		if(preg_match('/^[0-9]+$/', $value)===1){
			return $value;
		}
		if(function_exists('tracelog')){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Invalid Flightdeck debugbar memory_limit value: '.$value, $S='warning');
		}
		return null;
	}

	/**
	 * Subscribes the debugbar to SQL events when the SQL module supports it.
	 *
	 * Attachment is idempotent and skipped safely during early bootstrap or
	 * runtimes where the SQL observer API is unavailable.
	 *
	 * @return void
	 */
	public static function attach_sql_observer(): void {
		if(self::$sql_observer_attached===true){
			return;
		}
		if(class_exists('dataphyre\\sql', false)!==true || method_exists('dataphyre\\sql', 'add_observer')!==true){
			return;
		}
		\dataphyre\sql::add_observer([self::class, 'observe_sql']);
		self::$sql_observer_attached=true;
	}

	/**
	 * Buffers one SQL observer event for the current request.
	 *
	 * Events are stored in a bounded global ring buffer and skipped when memory
	 * headroom is too low to protect the primary response.
	 *
	 * @param array<string, mixed> $event SQL observer event payload.
	 * @return void
	 */
	public static function observe_sql(array $event): void {
		if(self::has_memory_headroom(524288)===false){
			return;
		}
		$GLOBALS['dataphyre_flightdeck_sql_events'] ??= [];
		if(!is_array($GLOBALS['dataphyre_flightdeck_sql_events'])){
			$GLOBALS['dataphyre_flightdeck_sql_events']=[];
		}
		$GLOBALS['dataphyre_flightdeck_sql_events'][]=$event;
		if(count($GLOBALS['dataphyre_flightdeck_sql_events'])>self::SQL_BUFFER_LIMIT){
			$GLOBALS['dataphyre_flightdeck_sql_events']=array_slice($GLOBALS['dataphyre_flightdeck_sql_events'], -self::SQL_BUFFER_LIMIT);
		}
	}

	/**
	 * Installs PHP error and shutdown observers for debugbar diagnostics.
	 *
	 * The previous error handler is preserved and called after Flightdeck records
	 * the error, allowing existing runtime error behavior to continue.
	 *
	 * @return void
	 */
	public static function attach_error_observer(): void {
		if(self::$error_observer_attached===true){
			return;
		}
		self::$previous_error_handler=set_error_handler([self::class, 'observe_php_error']);
		self::$error_observer_attached=true;
		if(self::$shutdown_observer_attached!==true){
			register_shutdown_function([self::class, 'observe_shutdown']);
			self::$shutdown_observer_attached=true;
		}
	}

	/**
	 * Records a PHP error and delegates to the previous handler.
	 *
	 * Returning the previous handler's boolean preserves PHP error propagation
	 * semantics. If the previous handler itself fails, Flightdeck records that
	 * failure and suppresses further handler exceptions.
	 *
	 * @param int $errno PHP error number.
	 * @param string $errstr Error message.
	 * @param string $errfile Source file, when available.
	 * @param int $errline Source line, when available.
	 * @return bool Whether PHP should consider the error handled.
	 */
	public static function observe_php_error(int $errno, string $errstr, string $errfile='', int $errline=0): bool {
		self::record_php_error($errno, $errstr, $errfile, $errline);
		if(is_callable(self::$previous_error_handler)){
			try{
				$result=(self::$previous_error_handler)($errno, $errstr, $errfile, $errline);
				return is_bool($result) ? $result : true;
			}catch(\Throwable $exception){
				self::record_php_error(E_USER_WARNING, 'Previous PHP error handler failed: '.$exception->getMessage(), __FILE__, __LINE__);
				return true;
			}
		}
		return false;
	}

	/**
	 * Captures fatal-error and late response-status diagnostics at shutdown.
	 *
	 * Fatal errors are added to the PHP error buffer and force a 500 snapshot
	 * status when needed. Non-error successful responses are ignored to avoid
	 * unnecessary session writes.
	 *
	 * @return void
	 */
	public static function observe_shutdown(): void {
		$error=error_get_last();
		$had_fatal=is_array($error) && self::is_fatal_error((int)($error['type'] ?? 0))===true;
		$final_status=(int)(http_response_code() ?: 200);
		if($had_fatal){
			self::record_php_error(
				(int)($error['type'] ?? E_ERROR),
				(string)($error['message'] ?? 'Fatal PHP error'),
				(string)($error['file'] ?? ''),
				(int)($error['line'] ?? 0)
			);
			if($final_status<500){
				$final_status=500;
			}
		}
		if(session_status()!==PHP_SESSION_ACTIVE){
			return;
		}
		if($had_fatal!==true && $final_status<400){
			return;
		}
		try{
			self::record_shutdown_status($final_status, $had_fatal);
		}catch(\Throwable){
		}
	}

	/**
	 * Writes shutdown status and error context into Flightdeck history.
	 *
	 * The method first tries to merge into an existing snapshot for the same
	 * request id or route path. If none exists and the status is notable, it
	 * records a compact fallback snapshot for post-mortem diagnostics.
	 *
	 * @param int $final_status Final HTTP response status observed at shutdown.
	 * @param bool $had_fatal Whether shutdown was caused by a fatal PHP error.
	 * @return void
	 */
	private static function record_shutdown_status(int $final_status, bool $had_fatal): void {
		if(self::has_memory_headroom(524288)===false){
			return;
		}
		$errors=self::error_state();
		$request_id=defined('RQID') ? (string)RQID : '';
		$method=(string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$uri=(string)($_SERVER['REQUEST_URI'] ?? '/');
		$path=(string)(parse_url($uri, PHP_URL_PATH) ?: '/');
		$history=self::history();
		foreach($history as $index=>$snapshot){
			if(!is_array($snapshot)){
				continue;
			}
			$snapshot_request=is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
			$snapshot_uri=(string)($snapshot['uri'] ?? '');
			$snapshot_path=(string)($snapshot_request['path'] ?? (parse_url($snapshot_uri, PHP_URL_PATH) ?: ''));
			$same_request=$request_id!=='' && (string)($snapshot['request_id'] ?? '')===$request_id;
			if($same_request!==true){
				$same_request=strtoupper((string)($snapshot['method'] ?? $snapshot_request['method'] ?? ''))===strtoupper($method)
					&& $snapshot_path===$path;
			}
			if($same_request!==true){
				continue;
			}
			$history[$index]['request']=array_replace($snapshot_request, ['status'=>$final_status]);
			$history[$index]['errors']=$errors;
			$history[$index]['shutdown']=[
				'observed'=>true,
				'fatal'=>$had_fatal,
				'status'=>$final_status,
				'observed_at'=>time(),
			];
			$_SESSION[self::HISTORY_KEY]=self::history_within_session_budget(array_slice($history, 0, self::HISTORY_LIMIT));
			return;
		}
		if($had_fatal!==true && $final_status<400){
			return;
		}
		self::record_snapshot([
			'available'=>true,
			'enabled'=>true,
			'request_id'=>$request_id,
			'app'=>defined('APP') ? (string)APP : '',
			'method'=>$method,
			'uri'=>$uri,
			'duration_ms'=>defined('REQUEST_TIME_FLOAT') ? round((microtime(true) - (float)REQUEST_TIME_FLOAT) * 1000, 3) : 0,
			'memory_mb'=>round(memory_get_usage(true) / 1048576, 3),
			'peak_mb'=>round(memory_get_peak_usage(true) / 1048576, 3),
			'files'=>count(get_included_files()),
			'modules'=>[],
			'run_mode'=>defined('RUN_MODE') ? (string)RUN_MODE : '',
			'production'=>defined('IS_PRODUCTION') && IS_PRODUCTION===true,
			'request'=>[
				'status'=>$final_status,
				'method'=>$method,
				'path'=>$path,
				'query'=>(string)(parse_url($uri, PHP_URL_QUERY) ?: ''),
				'host'=>(string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
			],
			'response'=>['available'=>false],
			'client'=>self::client_state_from_events([]),
			'routing'=>[],
			'sql'=>[],
			'templating'=>[],
			'asset_node'=>[],
			'runtime'=>[],
			'trace'=>[],
			'timeline'=>[],
			'errors'=>$errors,
			'diagnostics'=>[
				'count'=>1,
				'findings'=>[[
					'level'=>$final_status>=500 ? 'error' : 'warning',
					'title'=>'Response status changed at shutdown',
					'detail'=>'Flightdeck observed HTTP '.$final_status.' after page rendering had completed.',
					'source'=>'shutdown',
				]],
			],
		]);
	}

	/**
	 * Buffers a PHP error with severity, timestamp, memory, and optional stack.
	 *
	 * The buffer is bounded and stack capture is skipped under tight memory to
	 * keep the debugbar from worsening error conditions.
	 *
	 * @param int $errno PHP error number.
	 * @param string $errstr Error message.
	 * @param string $errfile Source file, when available.
	 * @param int $errline Source line, when available.
	 * @return void
	 */
	private static function record_php_error(int $errno, string $errstr, string $errfile='', int $errline=0): void {
		if(self::has_memory_headroom(262144)===false){
			return;
		}
		$GLOBALS['dataphyre_flightdeck_php_errors'] ??= [];
		if(!is_array($GLOBALS['dataphyre_flightdeck_php_errors'])){
			$GLOBALS['dataphyre_flightdeck_php_errors']=[];
		}
		$GLOBALS['dataphyre_flightdeck_php_errors'][]=[
			'errno'=>$errno,
			'severity'=>self::php_error_severity($errno),
			'message'=>$errstr,
			'file'=>$errfile,
			'line'=>$errline,
			'timestamp'=>microtime(true),
			'memory_bytes'=>memory_get_usage(true),
			'stack'=>self::has_memory_headroom(1048576)
				? self::stack_frames_from_backtrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12))
				: [],
		];
		if(count($GLOBALS['dataphyre_flightdeck_php_errors'])>self::ERROR_BUFFER_LIMIT){
			$GLOBALS['dataphyre_flightdeck_php_errors']=array_slice($GLOBALS['dataphyre_flightdeck_php_errors'], -self::ERROR_BUFFER_LIMIT);
		}
	}

	/**
	 * Enables the toolbar by issuing a signed debugbar toggle cookie.
	 *
	 * The operation only succeeds for authenticated Flightdeck console requests.
	 * The toggle cookie is separate from the console auth cookie and expires
	 * after twelve hours.
	 *
	 * @return void
	 */
	public static function enable(): void {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return;
		}
		if(dataphyre_flightdeck_auth::debugbar_allowed()!==true){
			return;
		}
		$payload=[
			'iat'=>time(),
			'exp'=>time() + 43200,
			'n'=>bin2hex(random_bytes(10)),
		];
		$data=self::base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signature=hash_hmac('sha256', $data, self::secret());
		self::set_cookie($data.'.'.$signature, time() + 43200);
	}

	/**
	 * Disables the toolbar by expiring the debugbar toggle cookie.
	 *
	 * @return void
	 */
	public static function disable(): void {
		setcookie(self::COOKIE, '', [
			'expires'=>time() - 3600,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		unset($_COOKIE[self::COOKIE]);
	}

	/**
	 * Quickly checks whether a response buffer is eligible for toolbar markup.
	 *
	 * Explicit non-HTML content types are rejected before scanning the body for
	 * HTML markers. Unknown content types may still pass if the buffer looks like
	 * an HTML document.
	 *
	 * @param string $buffer Response body buffer.
	 * @return bool True when markup injection is plausible.
	 */
	private static function quick_response_allows_toolbar_markup(string $buffer): bool {
		if($buffer===''){
			return false;
		}
		$content_type='';
		foreach(headers_list() as $header){
			if(stripos((string)$header, 'Content-Type:')===0){
				$content_type=strtolower(trim(substr((string)$header, strlen('Content-Type:'))));
				break;
			}
		}
		if($content_type!==''){
			if(str_contains($content_type, 'text/html') || str_contains($content_type, 'application/xhtml+xml')){
				return true;
			}
			if(str_contains($content_type, 'json')
				|| str_contains($content_type, 'javascript')
				|| str_contains($content_type, 'text/css')
				|| str_starts_with($content_type, 'image/')
				|| str_starts_with($content_type, 'font/')
				|| str_starts_with($content_type, 'audio/')
				|| str_starts_with($content_type, 'video/')){
				return false;
			}
		}
		return stripos($buffer, '</body>')!==false
			|| stripos($buffer, '<!doctype')!==false
			|| stripos($buffer, '<html')!==false;
	}

	/**
	 * Explains why full toolbar injection should switch to compact mode.
	 *
	 * @param string $buffer Response body buffer.
	 * @return string Human-readable low-memory reason, or an empty string when full injection is safe.
	 */
	private static function low_memory_reason(string $buffer): string {
		$limit=self::memory_limit_bytes();
		$remaining=self::memory_remaining_bytes();
		$body_bytes=strlen($buffer);
		if($limit>0 && $limit<=16777216){
			return 'PHP memory_limit is '.self::format_bytes($limit).'; full Flightdeck was skipped to protect the response.';
		}
		if(self::memory_limit_is_tight()===true && self::has_memory_headroom($body_bytes + 6291456)===false){
			return 'Only '.self::format_bytes($remaining).' remained under the '.self::format_bytes($limit).' PHP memory limit.';
		}
		if(self::has_memory_headroom($body_bytes + 4194304)===false){
			return 'Not enough memory remained to collect and inject the full Flightdeck toolbar.';
		}
		return '';
	}

	/**
	 * Builds the compact low-memory Flightdeck toolbar.
	 *
	 * @param string $reason Explanation shown in the compact toolbar.
	 * @return string Isolated compact toolbar markup.
	 */
	private static function low_memory_markup(string $reason): string {
		$open_url='/dataphyre/debugbar';
		$disable_url='/dataphyre/debugbar?action=disable';
		$memory=self::format_bytes(memory_get_usage(true));
		$limit=self::memory_limit_bytes();
		$limit_label=$limit>0 ? self::format_bytes($limit) : 'unlimited';
		$toolbar='<aside id="dataphyre-flightdeck-debugbar" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;background:#07111f;color:#e6f0ff;border:1px solid rgba(144,205,244,.35);box-shadow:0 12px 36px rgba(0,0,0,.35);border-radius:8px;font-family:system-ui,-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,sans-serif;font-size:12px;line-height:1.35;overflow:hidden">'
			.'<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 12px">'
			.'<div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap"><b style="color:#fff">Dataphyre Flightdeck</b>'
			.'<span style="padding:4px 8px;border-radius:999px;background:rgba(245,158,11,.16);border:1px solid rgba(245,158,11,.42);color:#ffe0a3">Compact</span>'
			.'<span style="padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1)">Memory '.$memory.' / '.$limit_label.'</span>'
			.'<span style="color:#9fb6d8">'.self::e($reason).'</span></div>'
			.'<div style="display:flex;gap:6px"><a href="'.self::e($open_url).'" target="_blank" rel="noopener" title="Open Flightdeck console" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid rgba(144,205,244,.22);background:rgba(255,255,255,.06);color:#d9ecff;text-decoration:none">&#8599;</a>'
			.'<a href="'.self::e($disable_url).'" title="Disable Flightdeck toolbar" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid rgba(239,68,68,.36);background:rgba(239,68,68,.12);color:#fecaca;text-decoration:none">&#215;</a></div>'
			.'</div></aside>';
		return self::isolate_toolbar_markup($toolbar);
	}

	/**
	 * Inserts toolbar markup before `</body>` when possible.
	 *
	 * Injection is skipped if the combined body would exceed available memory.
	 * Documents without a closing body receive the toolbar at the end.
	 *
	 * @param string $buffer Original response body.
	 * @param string $markup Toolbar markup to inject.
	 * @return string Response body with toolbar markup when memory allows.
	 */
	private static function splice_toolbar_markup(string $buffer, string $markup): string {
		$needed=strlen($buffer) + strlen($markup);
		if(self::has_memory_headroom($needed, 3145728)===false){
			return $buffer;
		}
		$body_position=stripos($buffer, '</body>');
		if($body_position===false){
			return $buffer.$markup;
		}
		return substr($buffer, 0, $body_position).$markup.substr($buffer, $body_position);
	}

	/**
	 * Injects the Flightdeck toolbar into an eligible response buffer.
	 *
	 * The injector avoids control-plane requests, duplicate toolbars, non-HTML
	 * responses, and low-memory full renders. Eligible responses are snapshotted
	 * before markup generation so the toolbar and history views describe the same
	 * request state.
	 *
	 * @param string $buffer Response body buffer.
	 * @return string Original or toolbar-augmented response body.
	 */
	public static function inject(string $buffer): string {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return $buffer;
		}
		if(self::enabled()!==true){
			return $buffer;
		}
		self::apply_configured_memory_limit();
		if(self::is_control_plane_request()===true){
			return $buffer;
		}
		if(stripos($buffer, 'id="dataphyre-flightdeck-debugbar"')!==false || stripos($buffer, "id='dataphyre-flightdeck-debugbar'")!==false || stripos($buffer, 'id="dataphyre-flightdeck-debugbar-host"')!==false || stripos($buffer, "id='dataphyre-flightdeck-debugbar-host'")!==false){
			return $buffer;
		}
		if(self::quick_response_allows_toolbar_markup($buffer)!==true){
			return $buffer;
		}
		self::$injection_response_status=(int)(http_response_code() ?: 200);
		$low_memory_reason=self::low_memory_reason($buffer);
		if($low_memory_reason!==''){
			return self::splice_toolbar_markup($buffer, self::low_memory_markup($low_memory_reason));
		}
		$state=self::state($buffer);
		$response=is_array($state['response'] ?? null) ? $state['response'] : [];
		if(self::response_allows_toolbar_markup($response, $buffer)!==true){
			if(self::has_memory_headroom(1048576)){
				self::record_snapshot($state);
			}
			return $buffer;
		}
		if(self::has_memory_headroom(strlen($buffer) + 4194304)===false){
			return self::splice_toolbar_markup($buffer, self::low_memory_markup('Flightdeck captured request state, then switched to compact mode before injection to avoid exhausting PHP memory.'));
		}
		$markup=self::markup($buffer, $state);
		return self::splice_toolbar_markup($buffer, $markup);
	}

}
