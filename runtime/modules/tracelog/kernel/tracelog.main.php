<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('tracelog', 'DP_TRACELOG_CFG', [
	'enable_tracelog'=>false,
	'save_to_file'=>false,
	'file_lifespan'=>6,
	'password'=>'',
]);
if(function_exists('sql_define_table')){
	sql_define_table('dataphyre.tracelogs', __DIR__.'/tracelog.tables.php', 'tracelogs');
}

\heisenconstant('TRID', fn()=>RQID);

register_shutdown_function(function(){
	try{
		if(function_exists('\dataphyre_debug_logging_suppressed') && \dataphyre_debug_logging_suppressed()===true){
			return;
		}
		if(tracelog::$enable===true){
			tracelog::persist_to_session();
			if(tracelog::$save_to_sql===true){
				tracelog::save_to_database($GLOBALS['tracelog_rqid'] ?? RQID);
			}
		}
	}catch(\Throwable $exception){
		\dataphyre_shutdown_log('Exception on Dataphyre Tracelog shutdown callback', $exception);
	}
});

if(defined('TRACELOG_BOOT_ENABLE') || defined('TRACELOG_FORCE_ENABLE') || (DP_TRACELOG_CFG['enable_tracelog'] ?? false)===true){
	new tracelog();
	tracelog::$enable=true;
	if(defined('TRACELOG_BOOT_ENABLE_PLOTTING') || defined('TRACELOG_BOOT_PLOTTING_ENABLE')){
		tracelog::set_plotting(true);
	}
}
else
{
	unset($GLOBALS['retroactive_tracelog']);
}

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/tracelog.diagnostic.php');
}

/**
 * Captures request traces for Flightdeck, diagnostics, sessions, and SQL storage.
 *
 * Tracelog buffers formatted runtime events during the request, can defer early
 * events until the module is fully enabled, persists bounded copies into the
 * PHP session, writes larger handoff files for Flightdeck viewers, and can store
 * final traces in the `dataphyre.tracelogs` table. The class keeps the legacy
 * snake_case kernel API because it is called by global tracing helpers.
 */
class tracelog {
	
	public static $tracelog='';
	public static $constructed=false;
	public static $enable=false;
	public static $open=false;
    public static $plotting=false;
    public static $dynamic_unit_testing=false;
    public static $defer=true;
    public static $save_to_sql=false;
    private static ?string $last_handoff_token=null;
    private const TRACE_BUFFER_LIMIT_BYTES=2097152;
    
	/**
	 * Initializes Tracelog and installs the PHP error handler.
	 *
	 * Constructing the class marks tracing as constructed but does not itself
	 * enable trace capture; bootstrap code controls `self::$enable`.
	 */
	public function __construct(){
		self::$constructed=true;
		self::set_handler();
	}
	
	/**
	 * Persists the current trace buffer to the SQL tracelog table.
	 *
	 * The stored row includes request id, server address, app name, timestamp, and
	 * the full in-memory trace buffer. Insert failures are reported back through
	 * tracelog as fatal messages when tracing is still active.
	 *
	 * @param string $rqid Request id associated with the trace row.
	 * @return void
	 */
	public static function save_to_database(string $rqid): void {
		$time=date('Y-m-d H:i:s', strtotime('now'));
		if(false===$log=sql_insert(
			$L="dataphyre.tracelogs", 
			$F=[
				"rqid"=>$rqid,
				"log"=>self::$tracelog,
				"server"=>$_SERVER['SERVER_ADDR'],
				"app"=>APP,
				"date"=>$time
			]
		)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed creating log in database", $S="fatal");
		}
	}

	/**
	 * Replays trace events captured before Tracelog was fully enabled.
	 *
	 * Retroactive rows are prepended so early bootstrap activity appears before
	 * later runtime events. When plotting is active, trace rows also emit plot
	 * frames before their argument payload is cleared from the row.
	 *
	 * @return void
	 */
	public static function process_retroactive(): void {
		global $retroactive_tracelog;
		if(function_exists('\dataphyre_debug_logging_suppressed') && \dataphyre_debug_logging_suppressed()===true){
			unset($GLOBALS['retroactive_tracelog']);
			return;
		}
		$initial_memory=ini_get("memory_limit");
		ini_set("memory_limit", "256M");
		if(isset($retroactive_tracelog) && is_array($retroactive_tracelog)){
			if(self::$enable===true){
				foreach(array_reverse($retroactive_tracelog) as $log){
					if(is_string($log)){
						self::prepend_log($log);
					}
					else
					{
						$tracelog_overhead=strlen(json_encode($log, JSON_UNESCAPED_UNICODE));
						$log[8]=$log[8]-$tracelog_overhead;
						if(self::$plotting===true){
							self::write_plot_frame(is_array($log[9] ?? null) ? $log[9] : self::plot_frame_from_trace_row($log));
							$log[9]=null;
						}
						self::tracelog(...$log);
					}
				}
			}
		}
		ini_set("memory_limit", $initial_memory);
		unset($GLOBALS['retroactive_tracelog']);
	}

	/**
	 * Persists the current trace for Flightdeck handoff and session readers.
	 *
	 * The method drains retroactive traces, writes a full handoff file when
	 * possible, stores a bounded copy in session storage, and records request id,
	 * timestamp, and handoff token metadata for the Flightdeck viewer.
	 *
	 * @return void
	 */
	public static function persist_to_session(): void {
		if(self::$enable!==true){
			return;
		}
		if(self::$defer){
			self::$defer=false;
		}
		self::process_retroactive();
		$handoff_token=self::write_handoff_trace(self::$tracelog);
		$session_trace=self::session_trace_payload(self::$tracelog);
		$_SESSION['tracelog']=$session_trace;
		$_SESSION['flightdeck_last_tracelog']=$session_trace;
		$_SESSION['flightdeck_last_tracelog_rqid']=defined('RQID') ? (string)RQID : '';
		$_SESSION['flightdeck_last_tracelog_time']=time();
		if($handoff_token!==null){
			$_SESSION['flightdeck_last_tracelog_handoff']=$handoff_token;
		}
	}

	/**
	 * Builds the session-safe trace payload.
	 *
	 * Full traces are kept in the session only while they remain below the
	 * session payload limit. Larger traces retain their tail and point operators
	 * to the handoff viewer for the complete capture.
	 *
	 * @param string $trace Current HTML trace buffer.
	 * @return string Trace payload suitable for PHP session storage.
	 */
	private static function session_trace_payload(string $trace): string {
		if($trace===''){
			return '';
		}
		$limit=196608;
		if(strlen($trace)<=$limit){
			return $trace;
		}
		return '<br><b>Trace was too large for PHP session storage; retained tail shown. Open the Tracelog viewer with the handoff token for the full trace.</b><br>'
			.substr($trace, -$limit);
	}

	/**
	 * Reads the most recent or token-selected handoff trace.
	 *
	 * A supplied token must pass HMAC verification before it resolves to a file.
	 * Without a token, the newest session-derived handoff file is preferred, then
	 * recent files in the handoff directory are used as a fallback.
	 *
	 * @param ?string $handoff_token Signed handoff token from a Flightdeck URL.
	 * @return string Full trace contents, or an empty string when no file is available.
	 */
	public static function last_handoff_trace(?string $handoff_token=null): string {
		if($handoff_token!==null && $handoff_token!==''){
			$file=self::handoff_file_from_token($handoff_token);
			if($file!==null && is_file($file)){
				return (string)@file_get_contents($file);
			}
		}
		$files=self::handoff_files();
		$newest_file='';
		$newest_time=0;
		foreach($files as $file){
			if(!is_file($file)){
				continue;
			}
			$mtime=(int)@filemtime($file);
			if($mtime>=$newest_time){
				$newest_file=$file;
				$newest_time=$mtime;
			}
		}
		if($newest_file!==''){
			return (string)@file_get_contents($newest_file);
		}
		foreach(self::recent_handoff_files() as $file){
			return (string)@file_get_contents($file);
		}
		return '';
	}

	/**
	 * Writes the full trace buffer to every session-derived handoff file.
	 *
	 * Multiple candidate ids are written so the viewer can survive browser cookie
	 * and PHP session naming differences. The returned token references the first
	 * candidate and is signed by handoff_secret().
	 *
	 * @param string $trace Current HTML trace buffer.
	 * @return ?string Signed token for the primary handoff file, or null for an empty trace or missing directory.
	 */
	private static function write_handoff_trace(string $trace): ?string {
		if($trace===''){
			return null;
		}
		$first_token=null;
		foreach(self::handoff_files() as $file){
			$id=pathinfo($file, PATHINFO_FILENAME);
			$first_token??=self::sign_handoff_id($id);
			if(class_exists('\dataphyre\core', false)){
				core::file_put_contents_forced($file, $trace);
				continue;
			}
			$directory=dirname($file);
			if(!is_dir($directory)){
				@mkdir($directory, 0775, true);
			}
			@file_put_contents($file, $trace, LOCK_EX);
		}
		self::$last_handoff_token=$first_token;
		return $first_token;
	}

	/**
	 * Builds a Flightdeck route URL with the current handoff token.
	 *
	 * @param string $route Route path relative to the site root.
	 * @return string Root-relative URL carrying a `handoff` query parameter when a token is available.
	 */
	private static function handoff_url(string $route): string {
		$token=self::$last_handoff_token ?? self::sign_primary_handoff_id();
		$query=$token!==null ? '?'.http_build_query(['handoff'=>$token]) : '';
		return '/'.ltrim($route, '/').$query;
	}

	/**
	 * Returns writable handoff file paths derived from active session identifiers.
	 *
	 * Identifiers are hashed before becoming filenames so raw cookie or session
	 * values are never exposed on disk.
	 *
	 * @return array<int, string> Candidate `.dat` file paths.
	 */
	private static function handoff_files(): array {
		$base=self::handoff_directory();
		if($base===''){
			return [];
		}
		$keys=array_filter([
			session_id(),
			(string)($_COOKIE[session_name()] ?? ''),
			(string)($_COOKIE['dataphyre_flightdeck'] ?? ''),
		], static fn($key)=>is_string($key) && $key!=='');
		$files=[];
		foreach(array_unique($keys) as $key){
			$files[]=$base.'/'.sha1((string)$key).'.dat';
		}
		return $files;
	}

	/**
	 * Resolves the cache directory used for trace handoff files.
	 *
	 * @return string Handoff directory path, or an empty string when no root path is defined.
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
	 * Returns the newest handoff files as a fallback lookup set.
	 *
	 * @return array<int, string> Up to three handoff file paths sorted newest first.
	 */
	private static function recent_handoff_files(): array {
		$directory=self::handoff_directory();
		if($directory==='' || !is_dir($directory)){
			return [];
		}
		$files=glob($directory.'/*.dat') ?: [];
		usort($files, static fn($a, $b)=>(int)@filemtime($b) <=> (int)@filemtime($a));
		return array_slice($files, 0, 3);
	}

	/**
	 * Signs the first session-derived handoff id.
	 *
	 * @return ?string Signed token for the primary handoff file, or null when no file candidates exist.
	 */
	private static function sign_primary_handoff_id(): ?string {
		$files=self::handoff_files();
		if($files===[]){
			return null;
		}
		return self::sign_handoff_id(pathinfo($files[0], PATHINFO_FILENAME));
	}

	/**
	 * Signs a handoff id with the project-local handoff secret.
	 *
	 * @param string $id SHA-1 handoff file id.
	 * @return string Token in `id.signature` format.
	 */
	private static function sign_handoff_id(string $id): string {
		return $id.'.'.hash_hmac('sha256', $id, self::handoff_secret());
	}

	/**
	 * Resolves a signed handoff token to a cache file path.
	 *
	 * Tokens are accepted only when the id has the expected SHA-1 shape and the
	 * HMAC matches the current project secret.
	 *
	 * @param string $token Token received from a Flightdeck handoff URL.
	 * @return ?string Handoff file path, or null when the token is invalid.
	 */
	private static function handoff_file_from_token(string $token): ?string {
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return null;
		}
		[$id, $signature]=$parts;
		if(!preg_match('/^[a-f0-9]{40}$/', $id)){
			return null;
		}
		$expected=hash_hmac('sha256', $id, self::handoff_secret());
		if(hash_equals($expected, $signature)!==true){
			return null;
		}
		$directory=self::handoff_directory();
		return $directory!=='' ? $directory.'/'.$id.'.dat' : null;
	}

	/**
	 * Derives the project-local secret used to sign handoff tokens.
	 *
	 * The secret is built from license, app, project root, and root path values so
	 * tokens are not portable across installations.
	 *
	 * @return string Hex-encoded signing secret.
	 */
	private static function handoff_secret(): string {
		return hash('sha256', implode('|', [
			defined('LICENSE') && is_array(LICENSE) ? (string)(LICENSE['key'] ?? '') : '',
			defined('APP') ? (string)APP : '',
			defined('DATAPHYRE_PROJECT_ROOT') ? (string)DATAPHYRE_PROJECT_ROOT : '',
			defined('ROOTPATH') && !empty(ROOTPATH['root']) ? (string)ROOTPATH['root'] : '',
		]));
	}
	
	/**
	 * Appends Flightdeck viewer launch scripts to the response buffer when open.
	 *
	 * When Tracelog is enabled and open, this callback persists the trace,
	 * snapshots runtime metrics into the session, and opens either the normal
	 * viewer or plotting viewer with a signed handoff URL.
	 *
	 * @param mixed $buffer Output buffer value received from PHP.
	 * @return mixed original buffer, or the buffer with a signed viewer-launch script appended.
	 */
	public static function buffer_callback(mixed $buffer): mixed {
		if(self::$enable===true){
			if(self::$open===true){
				self::persist_to_session();
				$_SESSION['runtime_memory_used']=INITIAL_MEMORY_USAGE;
				$_SESSION['memory_used']=memory_get_usage()-INITIAL_MEMORY_USAGE;
				$_SESSION['memory_used_peak']=memory_get_peak_usage()-INITIAL_MEMORY_USAGE;
				$_SESSION['defined_user_function_count']=count(get_defined_functions()['user'] ?? []);
				$_SESSION['exec_time']=microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"];
				$_SESSION['included_files']=count(get_included_files());
				if(self::$plotting===true){
					return $buffer."<script>window.open('".self::handoff_url('dataphyre/tracelog/plotter')."', '_blank', 'width=1000, height=1000');</script>";
				}
				return $buffer."<script>window.open('".self::handoff_url('dataphyre/tracelog')."', '_blank', 'width=1000, height=1000');</script>";
			}
		}
		return $buffer;
	}
	
    /**
     * Enables or disables trace plotting output.
     *
     * Enabling plotting clears the plotting cache file so the next viewer run
     * starts with frames from the current request only.
     *
     * @param mixed $value Truthy value to enable plotting, falsey value to disable it.
     * @return void
     */
    public static function set_plotting($value){
        if(self::$plotting!==$value){
            self::$plotting=$value;
            if($value) @unlink(ROOTPATH['dataphyre'].'cache/tracelog_plotting.dat');
        }
    }

	
	/**
	 * Installs the Tracelog PHP error handler.
	 *
	 * The handler records non-fatal PHP errors into the active trace and escalates
	 * fatal user errors through Dataphyre's unavailable flow. Dialbacks can
	 * override handler installation or individual error handling.
	 *
	 * @return mixed Dialback return value when a dialback handles installation, otherwise null.
	 */
	private static function set_handler(){
		if(null!==$early_return=core::dialback('CALL_TRACELOG_SET_HANDLER',...func_get_args())) return $early_return;
		set_error_handler(function($errno, $errstr, $errfile, $errline){
			if(null!==$early_return=core::dialback('CALL_TRACELOG_ERROR_FOUND',...func_get_args())) return $early_return;
			if($errno===E_ERROR || $errno===E_USER_ERROR){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreTracelog: Fatal error during execution.', 'safemode');
			}
			if(self::$enable===true){
				$log='<br><table style="border: 1px solid white;"><tr><th style="color:red">Error</th><th style="color:red">File</th><th style="color:red">Line</th></tr><tr><td style="border: 1px solid white;">'.htmlspecialchars($errstr).'</td><td style="border: 1px solid white;">'.$errfile.'</td> <td style="border: 1px solid white;">'.$errline.'</td></tr></table>';
				if(self::$defer===true){
					$GLOBALS['retroactive_tracelog'][]=$log;
				}
				else
				{
					self::$tracelog.=$log;
				}
			}
			return true;
		});
	}

	/**
	 * Appends or defers a formatted trace event.
	 *
	 * Events are ignored when tracing is disabled or debug logging is suppressed.
	 * While tracing is deferred, rows are stored in the retroactive buffer with
	 * timing, memory, and optional plot-frame context. Active tracing normalizes
	 * argument summaries, formats the message by severity, writes the HTML entry,
	 * and emits plot frames when plotting is enabled.
	 *
	 * @param ?string $file Source file associated with the event.
	 * @param ?string $line Source line associated with the event.
	 * @param ?string $class Source class associated with the event.
	 * @param ?string $function Source function associated with the event.
	 * @param ?string $text Message text or generated argument summary.
	 * @param ?string $type Trace severity or special event type.
	 * @param mixed $arguments Function arguments to summarize.
	 * @param ?float $retroactive_time Original event timestamp for deferred rows.
	 * @param ?int $retroactive_memory Original memory usage for deferred rows.
	 * @param ?array $plot_frame Optional prebuilt plotting frame.
	 * @return bool True when the event was accepted into the trace pipeline.
	 */
	public static function tracelog(?string $file, ?string $line, ?string $class, ?string $function, ?string $text, ?string $type='info', mixed $arguments=null, ?float $retroactive_time=null, ?int $retroactive_memory=null, ?array $plot_frame=null) : bool {
		if(function_exists('\dataphyre_debug_logging_suppressed') && \dataphyre_debug_logging_suppressed()===true){
			return false;
		}
		if(self::$enable===false) return false;
		if($arguments!==null && !is_array($arguments)){
			$arguments=[$arguments];
		}
		if($plot_frame===null && self::$plotting===true && function_exists('\dataphyre_tracelog_plot_frame')){
			$plot_frame=\dataphyre_tracelog_plot_frame();
		}
		if(self::$defer===true){
			$GLOBALS['retroactive_tracelog'][]=[$file, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage(), $plot_frame];
			return true;
		}
		if($type==='function_call_with_test'){
			if(class_exists('dataphyre\dpanel') || $dpanel=dp_module_present('dpanel')){
				if(isset($dpanel) && is_array($dpanel)){
					require_once($dpanel[0]);
				}
				\dataphyre\dpanel::generate_dynamic_unit_test($file, $line, $class, $function, $arguments);
			}
		}
		static $last_function_signature=null;
		static $function_colors=[];
		if(!empty($class))$function=$class.'::'.$function;
		$time=$retroactive_time ?? microtime(true);
		$memory=($retroactive_memory ?? memory_get_usage())-INITIAL_MEMORY_USAGE;
		$tracelog_time=number_format(($time-$_SERVER["REQUEST_TIME_FLOAT"])*1000, 3, '.');
		$pre='';
		if(!empty($function)){
			if(is_array($arguments)){
				foreach($arguments as $key=>$value){
					if(is_string($value)){
						$arguments[$key]='"'.$value.'"';
					}
					elseif(is_array($value)){
						$arguments[$key]='Array';
					}
					elseif($value===true){
						$arguments[$key]='True';
					}
					elseif($value===false){
						$arguments[$key]='False';
					}
					elseif(is_integer($value)){
						$arguments[$key]='Integer('.$value.')';
					}
					elseif(is_null($value)){
						$arguments[$key]='Null';
					}
					elseif(is_object($value)){
						$arguments[$key]='Object';
					}
					elseif(is_callable($value)){
						$arguments[$key]='Callable';
					}
					else
					{
						$arguments[$key]='N/A';
					}
				}
				$text=implode(',', $arguments);
				$text=htmlentities($text);
			}
			$function_colors[$function]??=core::random_hex_color();
			if($type==='function_call'){
				$text='<span style="color:#85f1ff;">FC:</span> <span style="color:'.$function_colors[$function].'">'.$function.'('.$text.')</span>';
			}
			elseif($type==='function_call_with_test'){
				$text='<span style="color:#84b3ff;" title="Function Call with dynamic unit Test generation">FCwT:</span> <span style="color:'.$function_colors[$function].'">'.$function.'('.$text.')</span>';
			}
			else
			{
				$pre='<span style="color:'.$function_colors[$function].'">FC: '.$function.'():</span> ';
			}
		}
		if(empty($type) || $type==='info'){
			$type='info';
			$text=$pre.'<span style="color:#28cc49">'.$text.'</span>';
		}
		elseif($type==='warning'){
			$text=$pre.'<span style="color:orange">'.$text.'</span>';
		}
		elseif($type==='error'){
			$text=$pre.'<span style="color:pink">'.$text.'</span>';
		}
		elseif($type==='fatal'){
			log_error('Tracelog fatal: '.$class.'/'.$function.'(): '.$text);
			$text=$pre.'<span style="color:red">'.$text.'</span>';
		}
		self::$tracelog??='';
		$log='<br><b>'.$tracelog_time.'ms, '.core::convert_storage_unit($memory).' ▸ </b> <i><span title="'.$file.'">'.basename($file).'</span>:'.$line.':</i> > <b>'.$text.'</b>';
		if(is_null($retroactive_time)){
			self::append_log($log);
		}
		else
		{
			self::prepend_log($log);
		}
		if(self::$plotting===true){
			self::write_plot_frame(is_array($plot_frame) ? $plot_frame : self::plot_frame_from_trace_row([$file, $line, $class, $function, $text, $type, $arguments, $time]));
		}
		return true;
	}

	/**
	 * Converts a deferred trace row into plotting frame data.
	 *
	 * @param array<int, mixed> $row Retroactive trace row.
	 * @return array<int, array<string, mixed>> Plot frame list consumed by the trace plotter.
	 */
	private static function plot_frame_from_trace_row(array $row): array {
		$time=is_numeric($row[7] ?? null)
			? number_format(((float)$row[7] - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 3, '.', '')
			: number_format((microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 3, '.', '');
		return [[
			'file'=>(string)($row[0] ?? 'N/A'),
			'line'=>$row[1] ?? 'N/A',
			'function'=>(string)($row[3] ?? 'global') ?: 'global',
			'class'=>(string)($row[2] ?? 'N/A') ?: 'N/A',
			'type'=>'trace',
			'args'=>[],
			'time'=>$time,
		]];
	}

	/**
	 * Appends plotting frame data to the plotting cache file.
	 *
	 * @param array<int, array<string, mixed>> $processed_trace Plot frame payload.
	 * @return void
	 */
	private static function write_plot_frame(array $processed_trace): void {
		$file_path=ROOTPATH['dataphyre'].'cache/tracelog_plotting.dat';
		$json_trace=json_encode($processed_trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(is_string($json_trace)){
			$directory=dirname($file_path);
			if(!is_dir($directory)){
				@mkdir($directory, 0775, true);
			}
			@file_put_contents($file_path, $json_trace . PHP_EOL, FILE_APPEND | LOCK_EX);
		}
	}

	/**
	 * Appends a formatted trace entry and trims the tail-protected buffer.
	 *
	 * @param string $log HTML trace entry.
	 * @return void
	 */
	private static function append_log(string $log): void {
		self::$tracelog=(string)(self::$tracelog ?? '').$log;
		self::trim_trace_buffer(false);
	}

	/**
	 * Prepends a formatted retroactive trace entry and trims the head-protected buffer.
	 *
	 * @param string $log HTML trace entry.
	 * @return void
	 */
	private static function prepend_log(string $log): void {
		self::$tracelog=$log.(string)(self::$tracelog ?? '');
		self::trim_trace_buffer(true);
	}

	/**
	 * Trims the in-memory trace buffer to protect request memory usage.
	 *
	 * @param bool $keep_head True to keep the beginning of the trace, false to keep the tail.
	 * @return void
	 */
	private static function trim_trace_buffer(bool $keep_head): void {
		$limit=self::TRACE_BUFFER_LIMIT_BYTES;
		if(strlen((string)self::$tracelog)<=$limit){
			return;
		}
		$notice='<br><b style="color:orange">Tracelog buffer was trimmed to protect the request. Use narrower tracing for a full capture.</b>';
		if($keep_head){
			self::$tracelog=substr((string)self::$tracelog, 0, $limit).$notice;
			return;
		}
		self::$tracelog=$notice.substr((string)self::$tracelog, -$limit);
	}
	
}
