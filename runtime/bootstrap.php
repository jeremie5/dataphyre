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
	  
function bootstrap(){
 
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Let there be light');
 
	ini_set('display_errors', 0);
 	set_error_handler(function(...$args){ return;}, E_ALL);
 
	require_once(__DIR__.'/bootstrap_config.php');

	$bootstrap_state=\dataphyre\bootstrap_config::resolve(__DIR__);
	$project_root=$bootstrap_state['project_root'];
	$bootstrap_config=$bootstrap_state['bootstrap'];
	$bootstrap_application_roots=$bootstrap_state['application_roots'];
	$GLOBALS['dataphyre_bootstrap_config']=$bootstrap_config;

	define('BS_VERSION', '1.0.1');
	define('DATAPHYRE_PROJECT_ROOT', rtrim($project_root, '/\\').'/');
	define('DATAPHYRE_RUNTIME_ROOT', rtrim(__DIR__, '/\\').'/');
	define('DATAPHYRE_BOOTSTRAP_CONFIG', $bootstrap_config); // still needed?
	define('DATAPHYRE_APPLICATION_ROOTS', $bootstrap_application_roots);
	define('IS_PRODUCTION', $bootstrap_config['is_production'] ?? true);
	define('LICENSE', $bootstrap_config['license'] ?? false);

	define('INITIAL_MEMORY_USAGE', memory_get_usage());
	define('CPU_USAGE', sys_getloadavg()[0]);
	$_SERVER['REQUEST_TIME_FLOAT']=microtime(true);

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
		if($bootstrap_config['prevent_keyless_direct_access']===true){
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

// Bootstrap helper functions

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
        return new class($name, $v) implements \ArrayAccess {
            private string $name;
            private Closure $fn;
            private mixed $cached = null;
            private bool $evaluated = false;
            public function __construct(string $name, Closure $fn) {
                $this->name = $name;
                $this->fn   = $fn;
            }
            private function evaluate(): void {
                if (!$this->evaluated) {
                    $this->cached = ($this->fn)();
                    $this->evaluated = true;
                }
            }
            public function __get(string $key): mixed {
                $this->evaluate();
                if(is_array($this->cached)){
                    return $this->cached[$key] ?? null;
                }
                return $this->cached->{$key};
            }
            public function __call(string $name, array $args): mixed {
                $this->evaluate();
                return $this->cached->{$name}(...$args);
            }
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
            public function __invoke(): mixed {
                $this->evaluate();
                return $this->cached;
            }
            public function &raw(): mixed {
                $this->evaluate();
                return $this->cached;
            }
            public function reset(): void {
                $this->cached = null;
                $this->evaluated = false;
            }
            public function __toString(): string {
                $this->evaluate();
                return (string)$this->cached;
            }
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

function tracelog($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null){
	if(class_exists('\dataphyre\dpanel', false)){
		if(defined('RUN_MODE') && RUN_MODE!=='diagnostic') return;
		\dataphyre\dpanel::tracelog_bypass($filename, $line, $class, $function, $text, $type, $arguments);
	}
	if(class_exists('\dataphyre\tracelog', false) && \dataphyre\tracelog::$constructed===true){
		if(\dataphyre\tracelog::$enable===true){
			return \dataphyre\tracelog::tracelog($filename, $line, $class, $function, $text, $type, $arguments);
		}
	}
	else
	{
		global $retroactive_tracelog;
		$retroactive_tracelog??=[];
		$retroactive_tracelog[]=[$filename, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage()];
	}
	if($type==='fatal'){
		log_error('Fatal tracelog: '.$class.'/'.$function.'(): '.$text);
	}
	return false;
}

function minified_font(){
	return "@font-face{font-family:Phyro-Bold;src:url('https://cdn.shopiro.ca/res/assets/genesis/fonts/Phyro-Bold.ttf')}.phyro-bold{font-family:'Phyro-Bold', sans-serif;font-weight:700;font-style:normal;line-height:1.15;letter-spacing:-.02em;-webkit-font-smoothing:antialiased}";
}

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
	$log_file=$log_directory.($log_date=gmdate('Y-m-d H:00')).'.html';
	$new_entry='<tr><td>'.$timestamp.'</td><td>'.$error.$log_data.'</td></tr><!--ENDLOG-->';
	file_put_contents($log_file, $new_entry, FILE_APPEND);
}

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
