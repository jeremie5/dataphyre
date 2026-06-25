<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
try{
	bootstrap();
}catch(\Throwable $exception){
	if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){
		echo $exception->getMessage();
		exit();
	}
	pre_init_error('Fatal error: Unable to load application bootstrap', $exception);
}
	  
/**
 * Boots the Dataphyre runtime for the current application process.
 *
 * Bootstrap resolves project/application roots, configures Flightdeck replay
 * safety flags, installs conservative error handling before full framework load,
 * loads runtime configuration, and prepares the module/application environment.
 * Fatal startup failures are handled by the top-level guard before this function
 * returns control to application code.
 *
 * @return void
 */
function bootstrap(){
 
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Let there be light');
 
	ini_set('display_errors', 0);
 	set_error_handler(function(...$args){ return;}, E_ALL);
 
	require_once(__DIR__.'/bootstrap_config.php');

	$bootstrap_state=\dataphyre\bootstrap_config::resolve(__DIR__);
	$project_root=$bootstrap_state['project_root'];
	$bootstrap_config=$bootstrap_state['bootstrap'];
	$bootstrap_application_roots=$bootstrap_state['application_roots'];
	$flightdeck_replay=flightdeck_replay_request($bootstrap_config, $project_root);
	if(($flightdeck_replay['requested'] ?? false)===true){
		if(!isset($bootstrap_config['flightdeck']) || !is_array($bootstrap_config['flightdeck'])){
			$bootstrap_config['flightdeck']=[];
		}
		if(!isset($bootstrap_config['flightdeck']['debugbar']) || !is_array($bootstrap_config['flightdeck']['debugbar'])){
			$bootstrap_config['flightdeck']['debugbar']=[];
		}
		$bootstrap_config['flightdeck']['debugbar']['enabled']=false;
		$bootstrap_config['flightdeck']['debugbar']['production_replay_active']=true;
	}
	if($flightdeck_replay['enabled']===true){
		$bootstrap_config['is_production']=true;
	}
	$GLOBALS['dataphyre_debug_logging_suppressed']=($flightdeck_replay['enabled'] ?? false)===true;
	$GLOBALS['dataphyre_bootstrap_config']=$bootstrap_config;
	$GLOBALS['dataphyre_flightdeck_config']=is_array($bootstrap_config['flightdeck'] ?? null) ? $bootstrap_config['flightdeck'] : [];
	$GLOBALS['dataphyre_flightdeck_replay']=$flightdeck_replay;

	define('BS_VERSION', '2.0');
	define('DATAPHYRE_PROJECT_ROOT', rtrim($project_root, '/\\').'/');
	define('DATAPHYRE_RUNTIME_ROOT', rtrim(__DIR__, '/\\').'/');
	define('DATAPHYRE_BOOTSTRAP_CONFIG', $bootstrap_config); // still needed?
	define('DATAPHYRE_FLIGHTDECK_CONFIG', $GLOBALS['dataphyre_flightdeck_config']);
	define('DATAPHYRE_APPLICATION_ROOTS', $bootstrap_application_roots);
	define('IS_PRODUCTION', $bootstrap_config['is_production'] ?? true);
	define('DATAPHYRE_FLIGHTDECK_REPLAY', (bool)($flightdeck_replay['enabled'] ?? false));
	define('DATAPHYRE_FLIGHTDECK_REPLAY_READONLY', (bool)($flightdeck_replay['enabled'] ?? false) && (bool)($flightdeck_replay['readonly'] ?? false));
	define('DATAPHYRE_DEBUG_LOGGING_SUPPRESSED', DATAPHYRE_FLIGHTDECK_REPLAY===true);
	define('LICENSE', $bootstrap_config['license'] ?? false);

	if(isset($_GET['tracelog']) && DATAPHYRE_FLIGHTDECK_REPLAY!==true){
		if(defined('TRACELOG_BOOT_ENABLE')!==true){
			define('TRACELOG_BOOT_ENABLE', true);
		}
		if(isset($_GET['plotting'])){
			if(defined('TRACELOG_BOOT_ENABLE_PLOTTING')!==true){
				define('TRACELOG_BOOT_ENABLE_PLOTTING', true);
			}
			if(defined('TRACELOG_BOOT_PLOTTING_ENABLE')!==true){
				define('TRACELOG_BOOT_PLOTTING_ENABLE', true);
			}
		}
	}

	define('INITIAL_MEMORY_USAGE', memory_get_usage());
	$load_average=function_exists('sys_getloadavg') ? sys_getloadavg() : false;
	define('CPU_USAGE', is_array($load_average) ? ($load_average[0] ?? 0) : 0);
	$_SERVER['REQUEST_TIME_FLOAT']=microtime(true);
	if(DATAPHYRE_FLIGHTDECK_REPLAY===true){
		flightdeck_send_replay_marker_headers();
		flightdeck_register_replay_headers((float)$_SERVER['REQUEST_TIME_FLOAT']);
	}

	if(!defined('RQID')){
		heisenconstant('RQID', fn()=>'rq_'.bin2hex(random_bytes(16)));
	}
	
	if(
		isset($_SERVER['SERVER_ADDR'])
		&& in_array($_SERVER['SERVER_ADDR'], ['localhost', '127.0.0.1', '192.168.0.1', '0.0.0.0'], true)
		&& !empty($bootstrap_config['public_ip_address'])
	){
		$_SERVER['SERVER_ADDR']=$bootstrap_config['public_ip_address'];
		if(!empty($bootstrap_config['web_server_port'])){
			$_SERVER['SELF_ADDR']=$_SERVER['SERVER_ADDR'].':'.$bootstrap_config['web_server_port'];
		}
	}

	set_time_limit($bootstrap_config['max_execution_time'] ?? 30);

	if(isset($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'])){
		$bootstrap_config['app']=$_SERVER['HTTP_X_DATAPHYRE_APPLICATION'];
	}
	else
	{
		$host_app_map=is_array($bootstrap_config['host_app_map'] ?? null) ? $bootstrap_config['host_app_map'] : [];
		$request_host=strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
		$request_host=preg_replace('/:\d+$/', '', $request_host) ?? $request_host;
		if($request_host!=='' && isset($host_app_map[$request_host])){
			$bootstrap_config['app']=$host_app_map[$request_host];
		}
		else
		{
		if($bootstrap_config['prevent_keyless_direct_access']===true && DATAPHYRE_FLIGHTDECK_REPLAY!==true){
			if(!file_exists($file=$project_root.'/direct_access_key')){
				file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
			}
			if(!in_array($_SERVER['HTTP_X_TRAFFIC_SOURCE'] ?? null, ['haproxy', 'internal_traffic'], true)){
				$key=trim(file_get_contents($project_root.'/direct_access_key'));
				if(empty($_REQUEST['direct_access_key']) || trim($_REQUEST['direct_access_key'])!==$key){
					http_response_code(403);
					die('<h1>Direct access requires authentication.</h1>');
				}
			}
			else
			{
				if(filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_IP)!==false){
					if(empty($_REQUEST['direct_access_key']) || trim($_REQUEST['direct_access_key'])!==$key){
						http_response_code(403);
						die('<h1>Direct access requires authentication.</h1>');
					}
				}
			}
		}
		}
	}

	if($bootstrap_config['allow_app_override']===true){
		if(!file_exists($file=$project_root.'/app_override_key')){
			file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
		}
		foreach(['_POST', '_GET', '_COOKIE'] as $source){
			if(!empty($$source['app_override'])){
				$key=file_get_contents($project_root.'/app_override_key');
				$user_app=explode(',', $$source['app_override']);
				if(($user_app[1] ?? null)===$key){
					$bootstrap_config['app']=$user_app[0];
				}
			}
		}
	}

	define('APP', $bootstrap_config['app']);
	flightdeck_start_debugbar_buffer();
	
	foreach($bootstrap_application_roots as $applications_root){
		$candidate=rtrim((string)$applications_root, '/\\').'/'.APP;
		if(!is_dir($candidate)){
			continue;
		}
		foreach(['backend/dataphyre', 'dataphyre'] as $relative_root){
			$dataphyre_candidate=rtrim($candidate, '/\\').'/'.$relative_root;
			if(is_dir($dataphyre_candidate) || is_dir(dirname($dataphyre_candidate))){
				if(!defined('DATAPHYRE_BOOTSTRAP_LOG_DIRECTORY')){
					define('DATAPHYRE_BOOTSTRAP_LOG_DIRECTORY', rtrim($dataphyre_candidate, '/\\').'/logs/');
				}
				break 2;
			}
		}
	}

	try{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Starting application bootstrap');
		require_once(__DIR__.'/modules/core/kernel/bootstrap.php');
		\dataphyre\runtime::boot($project_root, APP, $bootstrap_application_roots);
	}catch(\Throwable $exception){
		pre_init_error('Fatal error: Unable to load application bootstrap', $exception);
	}

}

/**
 * Validates a Flightdeck production replay request before bootstrap constants are defined.
 *
 * replay is accepted only for signed GET/HEAD requests whose token
 * matches the current URI, method, expiry, project-root-derived secret, and
 * Flightdeck password material. Accepted requests are read-only and force
 * production semantics while rejected requests return a reason for diagnostics.
 */
function flightdeck_replay_request(array $bootstrap_config, string $project_root): array {
	$requested=(string)($_SERVER['HTTP_X_DATAPHYRE_FLIGHTDECK_REPLAY'] ?? '')==='1';
	$disabled=[
		'enabled'=>false,
		'readonly'=>false,
		'requested'=>$requested,
		'reason'=>'disabled',
	];
	if(PHP_SAPI==='cli'){
		return $disabled;
	}
	if($requested!==true){
		return $disabled;
	}
	$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	if(!in_array($method, ['GET', 'HEAD'], true)){
		$disabled['reason']='unsafe_method';
		return $disabled;
	}
	$token=trim((string)($_SERVER['HTTP_X_DATAPHYRE_FLIGHTDECK_REPLAY_TOKEN'] ?? ''));
	if($token===''){
		$disabled['reason']='missing_token';
		return $disabled;
	}
	$parts=explode('.', $token, 2);
	if(count($parts)!==2){
		$disabled['reason']='malformed_token';
		return $disabled;
	}
	[$data, $signature]=$parts;
	$secret=flightdeck_replay_secret($bootstrap_config, $project_root);
	if($secret===''){
		$disabled['reason']='missing_secret';
		return $disabled;
	}
	$expected=hash_hmac('sha256', $data, $secret);
	if(hash_equals($expected, $signature)!==true){
		$disabled['reason']='bad_signature';
		return $disabled;
	}
	$payload=json_decode(flightdeck_base64url_decode($data), true);
	if(!is_array($payload)){
		$disabled['reason']='bad_payload';
		return $disabled;
	}
	if((int)($payload['exp'] ?? 0)<time()){
		$disabled['reason']='expired';
		return $disabled;
	}
	if(strtoupper((string)($payload['method'] ?? ''))!==$method){
		$disabled['reason']='method_mismatch';
		return $disabled;
	}
	$current_uri=(string)($_SERVER['REQUEST_URI'] ?? '/');
	if(hash_equals((string)($payload['uri'] ?? ''), $current_uri)!==true){
		$disabled['reason']='uri_mismatch';
		return $disabled;
	}
	return [
		'enabled'=>true,
		'readonly'=>true,
		'requested'=>true,
		'reason'=>'ok',
		'method'=>$method,
		'uri'=>$current_uri,
		'client'=>(string)($_SERVER['HTTP_X_DATAPHYRE_FLIGHTDECK_REPLAY_CLIENT'] ?? ''),
	];
}

/**
 * Derives the HMAC secret used for Flightdeck production replay tokens.
 *
 * the secret is intentionally tied to Flightdeck password material,
 * license identity, and normalized project root so tokens cannot be replayed
 * across projects or installs with different operator credentials.
 */
function flightdeck_replay_secret(array $bootstrap_config, string $project_root): string {
	$flightdeck=$bootstrap_config['flightdeck'] ?? [];
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
	$license=$bootstrap_config['license'] ?? '';
	$license_key=is_array($license) ? (string)($license['key'] ?? '') : (string)$license;
	$material=array_merge($password_material, [
		$license_key,
		rtrim($project_root, '/\\').'/',
	]);
	return hash('sha256', 'flightdeck-production-replay|'.implode('|', $material));
}

/**
 * Decodes URL-safe base64 token payload data.
 *
 * invalid payloads collapse to an empty string so replay validation can
 * fail closed without throwing during the earliest bootstrap phase.
 */
function flightdeck_base64url_decode(string $value): string {
	$padding=strlen($value) % 4;
	if($padding>0){
		$value.=str_repeat('=', 4 - $padding);
	}
	$decoded=base64_decode(strtr($value, '-_', '+/'), true);
	return is_string($decoded) ? $decoded : '';
}

/**
 * Emits headers that identify an active Flightdeck replay response.
 *
 * marker headers are best-effort and skipped after output begins. They
 * expose replay, production, and read-only state to Flightdeck clients without
 * changing application response bodies.
 */
function flightdeck_send_replay_marker_headers(): void {
	if(headers_sent()){
		return;
	}
	header('X-Dataphyre-Replay: 1');
	header('X-Dataphyre-Replay-Production: '.((defined('IS_PRODUCTION') && IS_PRODUCTION===true) ? '1' : '0'));
	header('X-Dataphyre-Replay-Readonly: '.((defined('DATAPHYRE_FLIGHTDECK_REPLAY_READONLY') && DATAPHYRE_FLIGHTDECK_REPLAY_READONLY===true) ? '1' : '0'));
}

/**
 * Registers replay shutdown headers for timing, memory, and write-block metadata.
 *
 * the shutdown callback re-emits replay markers and reports application
 * duration, memory adjusted for debug overhead, and replay write-block counts as
 * response headers when headers are still mutable.
 */
function flightdeck_register_replay_headers(float $started_at): void {
	register_shutdown_function(static function()use($started_at): void{
		if(headers_sent()){
			return;
		}
		$duration_ms=round(max(0.0, (microtime(true) - $started_at) * 1000), 3);
		$memory=flightdeck_replay_memory_metrics();
		flightdeck_send_replay_marker_headers();
		header('X-Dataphyre-Replay-Duration-Ms: '.$duration_ms);
		header('X-Dataphyre-Replay-Memory-Mb: '.$memory['current_mb']);
		header('X-Dataphyre-Replay-Peak-Mb: '.$memory['peak_mb']);
		header('X-Dataphyre-Replay-Memory-Mode: app');
		header('X-Dataphyre-Replay-Debug-Overhead-Mb: '.$memory['debug_overhead_mb']);
		header('X-Dataphyre-Replay-Write-Blocks: '.(int)($GLOBALS['dataphyre_flightdeck_replay_write_blocks'] ?? 0));
	});
}

/**
 * Calculates replay memory metrics with debug logging overhead removed.
 *
 * current and peak memory are adjusted by estimated tracelog/debugbar
 * payload size so Flightdeck production replay reports application cost rather
 * than the cost of collecting debug artifacts.
 */
function flightdeck_replay_memory_metrics(): array {
	$debug_overhead=flightdeck_debug_logging_overhead_bytes();
	$raw_current=memory_get_usage(false);
	$raw_peak=memory_get_peak_usage(false);
	$current_bytes=$raw_current - min($debug_overhead, $raw_current);
	$peak_bytes=max($current_bytes, $raw_peak - min($debug_overhead, $raw_peak));
	return [
		'current_mb'=>round($current_bytes / 1048576, 3),
		'peak_mb'=>round($peak_bytes / 1048576, 3),
		'debug_overhead_mb'=>round($debug_overhead / 1048576, 3),
	];
}

/**
 * Estimates memory used by bootstrap-visible debug logging payloads.
 *
 * the estimate walks known global and session debug containers plus the
 * constructed tracelog buffer. It is deliberately approximate and side-effect
 * free so it can run during shutdown header emission.
 */
function flightdeck_debug_logging_overhead_bytes(): int {
	$bytes=0;
	foreach([
		'retroactive_tracelog',
		'dataphyre_flightdeck_sql_events',
		'dataphyre_flightdeck_php_errors',
		'dataphyre_flightdeck_php_shutdown_error',
	] as $key){
		if(array_key_exists($key, $GLOBALS)){
			$bytes+=flightdeck_debug_payload_size($GLOBALS[$key]);
		}
	}
	if(class_exists('\dataphyre\tracelog', false)){
		$bytes+=strlen((string)\dataphyre\tracelog::$tracelog);
	}
	if(session_status()===PHP_SESSION_ACTIVE && isset($_SESSION) && is_array($_SESSION)){
		foreach([
			'tracelog',
			'tracelog_plotting',
			'flightdeck_last_tracelog',
			'flightdeck_last_tracelog_rqid',
			'flightdeck_last_tracelog_time',
			'flightdeck_last_tracelog_handoff',
			'dataphyre_flightdeck_debugbar_history',
			'runtime_memory_used',
			'memory_used',
			'memory_used_peak',
			'defined_user_function_count',
			'exec_time',
			'included_files',
		] as $key){
			if(array_key_exists($key, $_SESSION)){
				$bytes+=flightdeck_debug_payload_size($_SESSION[$key]);
			}
		}
	}
	return $bytes;
}

/**
 * Estimates serialized memory size for debug payload values.
 *
 * recursive arrays are bounded by depth, scalar values use inexpensive
 * fixed or string-size estimates, and objects/resources are treated as opaque to
 * avoid invoking userland behavior during bootstrap diagnostics.
 */
function flightdeck_debug_payload_size(mixed $value, int $depth=0): int {
	if($depth>8){
		return 64;
	}
	if(is_string($value)){
		return strlen($value) + 32;
	}
	if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
		return 32;
	}
	if(is_array($value)){
		$bytes=48;
		foreach($value as $key=>$item){
			$bytes+=flightdeck_debug_payload_size($key, $depth + 1);
			$bytes+=flightdeck_debug_payload_size($item, $depth + 1);
			$bytes+=32;
		}
		return $bytes;
	}
	if(is_object($value)){
		return 256;
	}
	if(is_resource($value)){
		return 64;
	}
	return 32;
}

/**
 * Starts the Flightdeck debugbar injection buffer when the request is eligible.
 *
 * debugbar injection is skipped for CLI, disabled config, missing opt-in
 * cookie, unavailable debugbar files, non-HTML responses, and thrown debugbar
 * errors. Eligible HTML buffers are passed through the debugbar injector.
 */
function flightdeck_start_debugbar_buffer(): void {
	if(PHP_SAPI==='cli'){
		return;
	}
	$config=defined('DATAPHYRE_BOOTSTRAP_CONFIG') ? DATAPHYRE_BOOTSTRAP_CONFIG : [];
	if(!is_array($config)){
		$config=[];
	}
	$flightdeck=$config['flightdeck'] ?? [];
	if(!is_array($flightdeck) || ($flightdeck['enabled'] ?? true)===false){
		return;
	}
	$debugbar=$flightdeck['debugbar'] ?? [];
	if(is_array($debugbar) && ($debugbar['enabled'] ?? true)===false){
		return;
	}
	if(empty($_COOKIE['dataphyre_flightdeck_debugbar'])){
		return;
	}
	$debugbar_file=__DIR__.'/modules/flightdeck/kernel/debugbar.php';
	if(is_file($debugbar_file)){
		try{
			require_once($debugbar_file);
			if(class_exists('dataphyre_flightdeck_debugbar', false) && dataphyre_flightdeck_debugbar::enabled()===true){
				dataphyre_flightdeck_debugbar::start_request();
			}
		}catch(\Throwable){
		}
	}
	ob_start(static function(string $buffer) use($debugbar_file): string {
		if($buffer===''){
			return $buffer;
		}
		$is_html_response=false;
		foreach(headers_list() as $header){
			if(stripos($header, 'Content-Type:')!==0){
				continue;
			}
			if(stripos($header, 'text/html')===false){
				return $buffer;
			}
			$is_html_response=true;
		}
		if($is_html_response!==true && stripos($buffer, '</body>')===false && stripos($buffer, '<html')===false && stripos($buffer, '<!doctype')===false){
			return $buffer;
		}
		if(!is_file($debugbar_file)){
			return $buffer;
		}
		try{
			require_once($debugbar_file);
			if(class_exists('dataphyre_flightdeck_debugbar', false)){
				return dataphyre_flightdeck_debugbar::inject($buffer);
			}
		}catch(\Throwable){
			return $buffer;
		}
		return $buffer;
	});
}

/**
 * Quantum Constant Expansion (QCE)
 * --------------------------------
 * A Dataphyre pattern for deferred, introspectable, single-evaluation constants.
 *
 * Heisenconstants are "quantum constants" — lazily evaluated, cached after first access,
 * and introspectable for debug purposes. Their state collapses only when observed.
 *
 * Any Closure in the passed value (or array of values) will be wrapped in a container
 * that handles caching, string/int/array/bool coercion, and debug visibility.
 *
 * Usage example:
 *
 * heisenconstant('MY_CONST', [
 *     'now' => fn() => date('c'),
 *     'uuid' => fn() => bin2hex(random_bytes(16)),
 * ]);
 *
 * echo MY_CONST['now'];     // triggers evaluation of 'now'
 * var_dump(MY_CONST);       // displays evaluation status
 *
 * 🧠 Ideal for: runtime-defined per-request globals like CSP nonces, UUIDs, AB test IDs,
 * or anything you "might" need — but only if needed.
 *
 * Warning: Here be dragons. Constants that aren’t.
 * Jérémie Fréreault – 2025-04-10
 */
function heisenconstant(string $name, mixed $value): void {
    $wrap = function(string $name, mixed $v) {
        if (!$v instanceof Closure) {
            return $v;
        }
        return new /**
         * Lazy container for a deferred heisenconstant value.
         *
         * The wrapper evaluates its Closure on first observation, caches the
         * result for the request, and exposes permissive array, property, call,
         * string, raw-reference, reset, and debug access for bootstrap-era
         * constants that may resolve to arrays, objects, scalars, or null.
         */
        class($name, $v) implements \ArrayAccess {
            private string $name;
            private Closure $fn;
            private mixed $cached = null;
            private bool $evaluated = false;
            /**
             * Captures the heisenconstant key and deferred evaluator.
             *
             * wrapper instances do not evaluate at construction time;
             * the Closure is stored until property, array, call, string, invoke,
             * raw, or debug access observes the value.
             */
            public function __construct(string $name, Closure $fn) {
                $this->name = $name;
                $this->fn   = $fn;
            }
            /**
             * Collapses the deferred value once and caches it.
             *
             * evaluation is idempotent per wrapper lifecycle, and reset
             * is the only supported path back to an unevaluated state.
             */
            private function evaluate(): void {
                if (!$this->evaluated) {
                    $this->cached = ($this->fn)();
                    $this->evaluated = true;
                }
            }
            /**
             * Proxies property reads to the evaluated cached value.
             *
             * arrays use key lookup with null fallback, while objects use
             * dynamic property access; scalar cached values rely on PHP's native
             * behavior after evaluation.
             */
            public function __get(string $key): mixed {
                $this->evaluate();
                if(is_array($this->cached)){
                    return $this->cached[$key] ?? null;
                }
                return $this->cached->{$key};
            }
            /**
             * Proxies method calls to the evaluated cached object.
             *
             * this preserves lazy construction for service-like constants
             * while leaving method existence and argument errors to the cached value.
             */
            public function __call(string $name, array $args): mixed {
                $this->evaluate();
                return $this->cached->{$name}(...$args);
            }
            /**
             * Reports whether an evaluated array or object contains an offset.
             *
             * ArrayAccess support exists so lazy constants can masquerade
             * as configuration arrays after first observation.
             */
            public function offsetExists(mixed $offset): bool {
                $this->evaluate();
                if(is_array($this->cached)){
                    return array_key_exists($offset, $this->cached);
                }
                if(is_object($this->cached)){
                    return isset($this->cached->{$offset});
                }
                return false;
            }
            /**
             * Returns an offset by reference from the evaluated value.
             *
             * missing array or object offsets are initialized to null so
             * callers can mutate nested lazy configuration through ArrayAccess.
             */
            public function &offsetGet(mixed $offset): mixed {
                $this->evaluate();
                if(is_array($this->cached)){
                    if(array_key_exists($offset, $this->cached)!==true){
                        $this->cached[$offset]=null;
                    }
                    return $this->cached[$offset];
                }
                if(is_object($this->cached)){
                    if(isset($this->cached->{$offset})!==true){
                        $this->cached->{$offset}=null;
                    }
                    return $this->cached->{$offset};
                }
                static $null=null;
                return $null;
            }
            /**
             * Sets an offset on the evaluated array or object value.
             *
             * array append semantics are preserved for null offsets, and
             * object offsets are written as dynamic properties.
             */
            public function offsetSet(mixed $offset, mixed $value): void {
                $this->evaluate();
                if(is_array($this->cached)){
                    if($offset===null){
                        $this->cached[]=$value;
                    }
                    else
                    {
                        $this->cached[$offset]=$value;
                    }
                    return;
                }
                if(is_object($this->cached)){
                    $this->cached->{$offset}=$value;
                }
            }
            /**
             * Removes an offset from the evaluated array or object value.
             *
             * unset is a no-op for scalar cached values, matching the
             * wrapper's role as a permissive bootstrap constant container.
             */
            public function offsetUnset(mixed $offset): void {
                $this->evaluate();
                if(is_array($this->cached)){
                    unset($this->cached[$offset]);
                    return;
                }
                if(is_object($this->cached)){
                    unset($this->cached->{$offset});
                }
            }
            /**
             * Returns the evaluated cached value.
             *
             * invocation is the explicit observation path for callers that
             * need the underlying value rather than array, property, or string access.
             */
            public function __invoke(): mixed {
                $this->evaluate();
                return $this->cached;
            }
            /**
             * Returns the evaluated cached value by reference.
             *
             * raw access supports legacy bootstrap callers that intentionally
             * mutate the resolved value after the constant has collapsed.
             */
            public function &raw(): mixed {
                $this->evaluate();
                return $this->cached;
            }
            /**
             * Clears the cached value so the evaluator can run again.
             *
             * reset is primarily a diagnostic escape hatch; normal runtime
             * constant use should remain single-evaluation per request.
             */
            public function reset(): void {
                $this->cached = null;
                $this->evaluated = false;
            }
            /**
             * Coerces the evaluated value to a string.
             *
             * string conversion observes the constant and delegates scalar
             * compatibility to PHP's native cast rules.
             */
            public function __toString(): string {
                $this->evaluate();
                return (string)$this->cached;
            }
            /**
             * Exposes the evaluated value for debug inspection.
             *
             * debug output intentionally collapses the value so dumps show
             * the effective runtime state rather than the unevaluated Closure.
             */
            public function __debugInfo(): array {
				$this->evaluate();
				return [
					'value' => $this->cached
				];
            }
        };
    };
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = $wrap($name, $v);
        }
    } else {
        $value = $wrap($name, $value);
    }
    if (!defined($name)) {
        define($name, $value);
    }
}

/**
 * Reports whether a value is a recognized bootstrap tracelog severity or event type.
 *
 * early tracelog normalization accepts legacy and modern call signatures;
 * this helper distinguishes actual type tokens from free-form message text before
 * the real tracelog class has loaded.
 */
function dataphyre_tracelog_known_type(mixed $value): bool {
	if(!is_string($value)){
		return false;
	}
	return in_array(strtolower($value), [
		'fatal',
		'error',
		'warning',
		'info',
		'function_call',
		'function_call_with_test',
	], true);
}

/**
 * Extracts the normalized directory portion of a trace path.
 *
 * used only by legacy tracelog argument detection, with backslashes
 * normalized before comparing directory and filename positions.
 */
function dataphyre_tracelog_path_directory(string $path): string {
	$path=str_replace('\\', '/', $path);
	$position=strrpos($path, '/');
	if($position===false){
		return '';
	}
	return rtrim(substr($path, 0, $position), '/');
}

/**
 * Detects the historical tracelog signature that passed directory before filename.
 *
 * this compatibility check lets bootstrap accept older callers without
 * changing the public tracelog shim. It validates directory/file/line shape before
 * treating the first argument as a directory.
 */
function dataphyre_tracelog_uses_legacy_directory_argument(array $raw_arguments): bool {
	if(count($raw_arguments)<5){
		return false;
	}
	if(!is_string($raw_arguments[0] ?? null) || !is_string($raw_arguments[1] ?? null)){
		return false;
	}
	$line=$raw_arguments[2] ?? null;
	if(!is_int($line) && !(is_string($line) && ctype_digit($line))){
		return false;
	}
	$directory=rtrim(str_replace('\\', '/', $raw_arguments[0]), '/');
	$file=str_replace('\\', '/', $raw_arguments[1]);
	$file_directory=dataphyre_tracelog_path_directory($file);
	if($directory!=='' && $file_directory!=='' && strcasecmp($directory, $file_directory)===0){
		return true;
	}
	return preg_match('/\.php$/i', $file)===1 && (str_contains($file, '/') || str_contains($file, '\\'));
}

/**
 * Converts mixed tracelog fragments into safe bootstrap strings.
 *
 * scalar values are rendered directly, booleans are explicit, null is
 * empty, and structured values prefer JSON so retroactive logs remain readable.
 */
function dataphyre_tracelog_stringify(mixed $value): string {
	if($value===null){
		return '';
	}
	if(is_bool($value)){
		return $value ? 'true' : 'false';
	}
	if(is_scalar($value)){
		return (string)$value;
	}
	$encoded=json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	return is_string($encoded) ? $encoded : '['.gettype($value).']';
}

/**
 * Normalizes all supported bootstrap tracelog call signatures.
 *
 * the shim accepts legacy directory-first calls, modern file/line calls,
 * type/message swaps, scalar extra arguments, and array argument payloads, then
 * returns the canonical seven-field tuple consumed by tracelog backends.
 */
function dataphyre_tracelog_normalize_call(array $raw_arguments): array {
	if(dataphyre_tracelog_uses_legacy_directory_argument($raw_arguments)){
		$filename=$raw_arguments[1] ?? null;
		$line=$raw_arguments[2] ?? null;
		$class=$raw_arguments[3] ?? null;
		$function=$raw_arguments[4] ?? null;
		$text=$raw_arguments[5] ?? null;
		$type=$raw_arguments[6] ?? null;
		$arguments=$raw_arguments[7] ?? null;
	}
	else
	{
		$filename=$raw_arguments[0] ?? null;
		$line=$raw_arguments[1] ?? null;
		$class=$raw_arguments[2] ?? null;
		$function=$raw_arguments[3] ?? null;
		$text=$raw_arguments[4] ?? null;
		$type=$raw_arguments[5] ?? null;
		$arguments=$raw_arguments[6] ?? null;
	}
	if(is_array($type) && $arguments===null){
		$arguments=$type;
		$type=null;
	}
	if($arguments!==null && !is_array($arguments)){
		if(!dataphyre_tracelog_known_type($type) && dataphyre_tracelog_known_type($arguments)){
			if($type!==null && $type!=='' && $text!==null && $text!=='' && !dataphyre_tracelog_known_type($text)){
				$text=$type;
			}
			$type=(string)$arguments;
		}
		else
		{
			$extra=dataphyre_tracelog_stringify($arguments);
			$text=($text===null || $text==='') ? $extra : dataphyre_tracelog_stringify($text).' '.$extra;
		}
		$arguments=null;
	}
	if($type!==null && !is_string($type)){
		$type=dataphyre_tracelog_stringify($type);
	}
	if($type==='' || !dataphyre_tracelog_known_type($type)){
		$type=$type==='' ? null : $type;
	}
	if($text!==null && !is_string($text)){
		$text=dataphyre_tracelog_stringify($text);
	}
	return [$filename, $line, $class, $function, $text, $type, $arguments];
}

/**
 * Captures a trace event before or after the full tracelog module is available.
 *
 * production logging is suppressed unless explicitly enabled, Flightdeck
 * replay disables debug logging, constructed tracelog receives canonical events,
 * and pre-construction events are retained in a bounded retroactive buffer.
 */
function tracelog($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null){
	if(dataphyre_debug_logging_suppressed()===true){
		return false;
	}
	if(defined('IS_PRODUCTION') && IS_PRODUCTION===true && dataphyre_tracelog_explicitly_requested()!==true){
		return false;
	}
	[$filename, $line, $class, $function, $text, $type, $arguments]=dataphyre_tracelog_normalize_call(func_get_args());
	$trace_frame=null;
	if(dataphyre_tracelog_plotting_requested()===true){
		$trace_frame=dataphyre_tracelog_plot_frame();
	}
	if(class_exists('\dataphyre\dpanel', false)){
		if(defined('RUN_MODE') && RUN_MODE!=='diagnostic') return;
		\dataphyre\dpanel::tracelog_bypass($filename, $line, $class, $function, $text, $type, $arguments);
	}
	if(class_exists('\dataphyre\tracelog', false) && \dataphyre\tracelog::$constructed===true){
		if(\dataphyre\tracelog::$enable===true){
			return \dataphyre\tracelog::tracelog($filename, $line, $class, $function, $text, $type, $arguments, null, null, $trace_frame);
		}
	}
	else
	{
		global $retroactive_tracelog;
		$retroactive_tracelog??=[];
		$retroactive_tracelog[]=[$filename, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage(), $trace_frame];
		if(count($retroactive_tracelog)>3000){
			array_splice($retroactive_tracelog, 0, count($retroactive_tracelog) - 3000);
		}
	}
	if($type==='fatal'){
		log_error('Fatal tracelog: '.$class.'/'.$function.'(): '.$text);
	}
	return false;
}

/**
 * Reports whether bootstrap tracelog should attach call-plot frames.
 *
 * plotting can be requested through bootstrap constants or the constructed
 * tracelog class, allowing early trace events to include stack frames when the
 * operator explicitly asks for plotting.
 */
function dataphyre_tracelog_plotting_requested(): bool {
	if(defined('TRACELOG_BOOT_ENABLE_PLOTTING') || defined('TRACELOG_BOOT_PLOTTING_ENABLE')){
		return true;
	}
	if(class_exists('\dataphyre\tracelog', false) && \dataphyre\tracelog::$constructed===true && \dataphyre\tracelog::$enable===true && \dataphyre\tracelog::$plotting===true){
		return true;
	}
	return false;
}

/**
 * Builds a compact stack frame payload for trace plotting.
 *
 * bootstrap frames exclude tracelog internals, include timing relative to
 * the request start, avoid argument capture, and cap the result so trace plotting
 * cannot explode early-request memory usage.
 */
function dataphyre_tracelog_plot_frame(): array {
	$backtrace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
	$frames=[];
	foreach($backtrace as $trace){
		$function=(string)($trace['function'] ?? '');
		$class=(string)($trace['class'] ?? '');
		if(in_array($function, ['tracelog', 'dataphyre_tracelog_plot_frame'], true)){
			continue;
		}
		if($class==='dataphyre\\tracelog' || $class==='dataphyre\tracelog'){
			continue;
		}
		if(in_array($function, ['include', 'include_once', 'require', 'require_once'], true)){
			continue;
		}
		$frames[]=[
			'file'=>(string)($trace['file'] ?? 'N/A'),
			'line'=>$trace['line'] ?? 'N/A',
			'function'=>$function !== '' ? $function : 'global',
			'class'=>$class !== '' ? $class : 'N/A',
			'type'=>(string)($trace['type'] ?? 'N/A'),
			'args'=>[],
			'time'=>number_format((microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 3, '.', ''),
		];
	}
	return array_slice($frames, 0, 16);
}

/**
 * Reports whether debug trace capture was explicitly requested.
 *
 * production-safe defaults require force constants, query flags, or
 * retroactive capture state before bootstrap keeps trace events.
 */
function dataphyre_tracelog_explicitly_requested(): bool {
	return defined('TRACELOG_FORCE_ENABLE')
		|| defined('TRACELOG_BOOT_ENABLE')
		|| isset($_GET['tracelog'])
		|| ($GLOBALS['dataphyre_tracelog_capture_retroactive'] ?? false)===true;
}

/**
 * Reports whether bootstrap debug logging must be suppressed.
 *
 * suppression is true during Flightdeck replay and when either constants,
 * globals, or replay state indicate that debug payloads must not accumulate.
 */
function dataphyre_debug_logging_suppressed(): bool {
	if(defined('DATAPHYRE_DEBUG_LOGGING_SUPPRESSED') && DATAPHYRE_DEBUG_LOGGING_SUPPRESSED===true){
		return true;
	}
	if(defined('DATAPHYRE_FLIGHTDECK_REPLAY') && DATAPHYRE_FLIGHTDECK_REPLAY===true){
		return true;
	}
	if(($GLOBALS['dataphyre_debug_logging_suppressed'] ?? false)===true){
		return true;
	}
	$replay=$GLOBALS['dataphyre_flightdeck_replay'] ?? null;
	return is_array($replay) && ($replay['enabled'] ?? false)===true;
}

/**
 * Returns the minimal inline font helper used by pre-init error pages.
 *
 * the bootstrap error renderer cannot rely on loaded assets, so this
 * helper provides the tiny CSS needed for the Dataphyre wordmark fallback.
 */
function minified_font(){
	return ".phyro-bold{font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-weight:700;font-style:normal;line-height:1.15;-webkit-font-smoothing:antialiased}";
}

/**
 * Resolves the safest available bootstrap log directory.
 *
 * resolution prefers loaded Dataphyre roots, then configured bootstrap
 * log directories, then bootstrap-discovered directories, and finally the project
 * root logs folder for failures before the runtime is fully available.
 */
function bootstrap_log_directory(): string {
	if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
		return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/logs/';
	}
	if(defined('ROOTPATH') && !empty(ROOTPATH['bootstrap_log_directory'])){
		return rtrim((string)ROOTPATH['bootstrap_log_directory'], '/\\').'/';
	}
	if(defined('DATAPHYRE_BOOTSTRAP_LOG_DIRECTORY') && DATAPHYRE_BOOTSTRAP_LOG_DIRECTORY!==''){
		return rtrim((string)DATAPHYRE_BOOTSTRAP_LOG_DIRECTORY, '/\\').'/';
	}
	return (defined('DATAPHYRE_PROJECT_ROOT')
		? rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/'
		: rtrim(dirname(dirname(__DIR__)), '/\\').'/'
	).'logs/';
}

/**
 * Writes a best-effort panic log for failures before normal logging is reliable.
 *
 * panic logging mirrors to PHP error_log, creates the bootstrap log
 * directory if possible, writes hourly panic files, and suppresses filesystem
 * errors to avoid recursive bootstrap failure.
 */
function bootstrap_panic_log(string $error, ?object $exception=null): void {
	$timestamp=gmdate('Y-m-d H:i:s T');
	$message='['.$timestamp.'] '.$error;
	if($exception!==null){
		$message.=' | '.get_class($exception).': '.$exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine();
	}
	@error_log($message);
	$log_directory=bootstrap_log_directory();
	if(!is_dir($log_directory)){
		@mkdir($log_directory, 0777, true);
	}
	$log_file=$log_directory.gmdate('Y-m-d H:00').'.panic.log';
	@file_put_contents($log_file, $message.PHP_EOL.($exception?->getTraceAsString() ?? '').PHP_EOL.PHP_EOL, FILE_APPEND);
}

/**
 * Writes an HTML bootstrap error log entry.
 *
 * this legacy logger records timestamped errors plus optional exception
 * details into hourly HTML log files once a writable bootstrap log directory can
 * be resolved.
 */
function log_error(string $error, ?object $exception=null){
	$timestamp=gmdate('Y-m-d H:i:s T');
	$log_data='';
	if($exception!==null){
		$log_data='<div class="card bg-light mb-3">';
		$log_data.='<div class="card-header">Exception: '.htmlspecialchars(get_class($exception)).'</div>';
		$log_data.='<div class="card-body"><p class="card-text"><strong>Message:</strong> '.htmlspecialchars($exception->getMessage()).'</p>';
		$log_data.='<p class="card-text"><strong>File:</strong> '.htmlspecialchars($exception->getFile()).'</p>';
		$log_data.='<p class="card-text"><strong>Line:</strong> '.htmlspecialchars($exception->getLine()).'</p>';
		$log_data.='<pre class="card-text bg-dark text-white p-2"><strong>Trace:</strong> '.htmlspecialchars($exception->getTraceAsString()).'</pre></div></div>';
	}
	$log_directory=bootstrap_log_directory();
	if(!is_dir($log_directory)){
		@mkdir($log_directory, 0777, true);
	}
	$log_file=$log_directory.str_replace(':', '_', gmdate('Y-m-d H:00')).'.html';
	$new_entry='<tr><td>'.$timestamp.'</td><td>'.$error.$log_data.'</td></tr><!--ENDLOG-->';
	file_put_contents($log_file, $new_entry, FILE_APPEND);
}

/**
 * Logs failures that occur inside shutdown callbacks.
 *
 * shutdown logging delegates to panic logging when available, otherwise
 * falls back to PHP error_log so late bootstrap failures still leave evidence.
 */
function dataphyre_shutdown_log(string $error, ?object $exception=null): void {
	if(function_exists('bootstrap_panic_log')){
		bootstrap_panic_log('Shutdown callback failure: '.$error, $exception);
		return;
	}
	@error_log('Shutdown callback failure: '.$error.($exception ? ' | '.get_class($exception).': '.$exception->getMessage() : ''));
}

/**
 * Attempts to render the Flightdeck pre-init error surface.
 *
 * the renderer is used only outside production, only when its file is
 * present, and failures inside the renderer are logged before the caller falls
 * back to the generic bootstrap error response.
 */
function flightdeck_render_pre_init_error(?string $error_message=null, ?object $exception=null): bool {
	if(!defined('IS_PRODUCTION') || IS_PRODUCTION!==false){
		return false;
	}
	$flightdeck_pre_init_renderer=__DIR__.'/modules/flightdeck/kernel/pre_init_error.php';
	if(!is_file($flightdeck_pre_init_renderer)){
		return false;
	}
	try{
		require_once($flightdeck_pre_init_renderer);
		return class_exists('dataphyre_flightdeck_pre_init_error', false)
			&& dataphyre_flightdeck_pre_init_error::render($error_message, $exception)===true;
	}catch(\Throwable $flightdeck_exception){
		log_error('Flightdeck pre-init renderer failed', $flightdeck_exception);
		return false;
	}
}

/**
 * Terminates bootstrap with the safest available pre-init error response.
 *
 * the routine panic-logs first, prevents recursive rendering loops,
 * delegates to core unavailable when the runtime is loaded, triggers InternalModule when
 * possible, clears buffers, tries the Flightdeck renderer in development, and
 * finally emits a minimal 503 HTML fallback.
 */
function pre_init_error(?string $error_message=null, ?object $exception=null, ?bool $is_from_unavailable=false) : never {
	$panic_message='Pre-init error: '.($error_message ?? 'Unknown bootstrap failure');
	bootstrap_panic_log($panic_message, $exception);
	if(!defined('DP_PRE_INIT_ERROR_ACTIVE')){
		define('DP_PRE_INIT_ERROR_ACTIVE', true);
	}
	else
	{
		while(ob_get_level()!==0){
			ob_end_clean();
		}
		if(!defined('DP_PRE_INIT_ERROR_RECURSIVE_RENDER_ATTEMPTED')){
			define('DP_PRE_INIT_ERROR_RECURSIVE_RENDER_ATTEMPTED', true);
			if(flightdeck_render_pre_init_error($error_message, $exception)===true){
				exit();
			}
		}
		http_response_code(IS_PRODUCTION ? 500 : 200);
		header('Content-Type: text/plain; charset=utf-8');
		echo $panic_message;
		if($exception!==null){
			echo "\n".get_class($exception).': '.$exception->getMessage();
		}
		exit();
	}
	if(defined('DP_CORE_LOADED') && $is_from_unavailable===false){
		if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){
			while(ob_get_level()!==0){
				ob_end_clean();
			}
			if(isset($error_message)){
				log_error('Pre-init error: '.$error_message, $exception);
			}
			if(flightdeck_render_pre_init_error($error_message, $exception)===true){
				exit();
			}
		}
		\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Pre init error: '.$error_message, 'safemode', $exception);
	}
	if(!defined('RUN_MODE') || RUN_MODE!=='diagnostic'){
		if(class_exists('dataphyre\internal_module', false)){
			dataphyre\internal_module::trigger('pre_init_error', [
				'exception'=>$exception,
				'collect_tracelog'=>true
			], 5);
		}
	}
	while(ob_get_level()!==0){
		ob_end_clean();
	}
	if(isset($error_message)){
		log_error('Pre-init error: '.$error_message, $exception);
	}
	if(flightdeck_render_pre_init_error($error_message, $exception)===true){
		exit();
	}
	http_response_code(IS_PRODUCTION ? 503 : 200);
	header('Retry-After: 300');
	header('Content-Type: text/html');
	echo'<!DOCTYPE html>';
	echo'<html>';
	echo'<head>';
	echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
	echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
	echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
	echo'<style>'.minified_font().'</style>';
	echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
	echo'</head>';
	echo'<body>';
	echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
	echo'<h3>Dataphyre has encountered a fatal error.</h3>';
	echo'<h3>Error description is available in Dataphyre\'s logs folder under '.gmdate('Y-m-d H:00').'.log'.' at '.gmdate('H:i:s T').'</h3>';
	echo'</body>';
	echo'</html>';
	exit();
}
