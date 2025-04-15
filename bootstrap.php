<?php
/*************************************************************************
*  2020-2024 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE: All information contained herein is, and remains the 
* property of Shopiro Ltd. and is provided under a dual licensing model.
* 
* This software is available for personal use under the Free Personal Use License.
* For commercial applications that generate revenue, a Commercial License must be 
* obtained. See the LICENSE file for details.
*
* This software is provided "as is", without any warranty of any kind.
*/

define('BS_VERSION', '1.0.1');

$rootpath['dataphyre']=__DIR__;

define('INITIAL_MEMORY_USAGE', memory_get_usage());
define('CPU_USAGE', sys_getloadavg()[0]);

$_SERVER['REQUEST_TIME_FLOAT']=microtime(true);

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Bootstrap initialization');

date_default_timezone_set('UTC');
header_remove('X-Powered-By');
header('Server: Dataphyre');

$bootstrap_config=require(__DIR__.'/config.php');

define('IS_PRODUCTION', $bootstrap_config['is_production'] ?? true);

define('LICENSE', $bootstrap_config['license'] ?? false);

if(in_array($_SERVER['SERVER_ADDR'], ['localhost', '127.0.0.1', '192.168.0.1', '0.0.0.0'])){
	$_SERVER['SERVER_ADDR']=$bootstrap_config['public_ip_address'];
	$_SERVER['SELF_ADDR']=$_SERVER['SERVER_ADDR'].':'.$bootstrap_config['web_server_port'];
}

set_time_limit($bootstrap_config['max_execution_time'] ?? 30);

if(isset($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'])){
	$bootstrap_config['app']=$_SERVER['HTTP_X_DATAPHYRE_APPLICATION'];
}
else
{
	if($bootstrap_config['prevent_keyless_direct_access']===true){
		if(!file_exists($file=__DIR__.'/direct_access_key')){
			file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
		}
		if(!in_array($_SERVER['HTTP_X_TRAFFIC_SOURCE'], ['haproxy', 'internal_traffic'])){
			$key=trim(file_get_contents(__DIR__.'/direct_access_key'));
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
	if(!file_exists($file=__DIR__.'/app_override_key')){
		file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
	}
	foreach(['_POST', '_GET', '_COOKIE'] as $source){
		if(!empty($$source['app_override'])){
			$key=file_get_contents(__DIR__.'/app_override_key');
			$user_app=explode(',', $$source['app_override']);
			if($user_app[1]===$key){
				$bootstrap_config['app']=$user_app[0];
			}
		}
	}
}

define('APP', $bootstrap_config['app']);

unset($bootstrap_config, $user_app, $key, $file);

try{
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Starting application bootstrap');
	include(__DIR__.'/applications/'.APP.'/application_bootstrap.php');
}catch(\Throwable $exception){
	pre_init_error('Fatal error: Unable to load application bootstrap', $exception);
}



// Bootstrap helper functions

/**
 * Quantum Constant Expansion (QCE)
 * ---------------------------------
 * A Dataphyre pattern for deferred, introspectable constant definitions.
 *
 * Values passed as closures will be lazily evaluated on string cast,
 * cached on first access, and display their quantum state during debug.
 *
 * Example:
 * heisenconstant('MY_CONST', [
 *     'now' => fn() => date('c'),
 *     'uuid' => fn() => bin2hex(random_bytes(16)),
 * ]);
 *
 * echo MY_CONST['now']; // triggers evaluation
 * var_dump(MY_CONST);   // shows eval status
 *
 * Warning: Here be dragons.
 * Jérémie Fréreault – 2025-04-10
 */
function heisenconstant(string $name, array $map): void {
    foreach($map as $key=>$value){
        if($value instanceof Closure){
            $map[$key]=new class($value){
                private Closure $fn;
                private mixed $cached=null;
                public function __construct(Closure $fn){
                    $this->fn=$fn;
                }
                public function __toString(): string{
                    return (string)($this->cached ??= ($this->fn)());
                }
                public function toInt(): int{
                    return (int)($this->cached ??=($this->fn)());
                }
                public function toFloat(): float{
                    return (float)($this->cached ??=($this->fn)());
                }
                public function toArray(): array{
                    return (array)($this->cached ??=($this->fn)());
                }
                public function toBool(): bool{
                    return (bool)($this->cached ??=($this->fn)());
                }
                public function raw(): mixed{
                    return $this->cached ??=($this->fn)();
                }
                public function reset(): void {
                    $this->cached=null;
                    $this->evaluated=false;
                }
                private function evaluate(): mixed{
                    $this->evaluated=true;
                    return ($this->fn)();
                }
                public function __debugInfo(): array{
                    return[
                        'status'=>$this->cached===null ? 'unevaluated' : 'evaluated',
                        'value'=>$this->cached ?? '[not yet evaluated]'
                    ];
                }
            };
        }
    }
    define($name, $map);
}

function tracelog($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null){
	if(class_exists('\dataphyre\dpanel', false)){
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
	$log_file=__DIR__.'/applications/'.APP.'/backend/dataphyre/logs/'.$log_date=gmdate('Y-m-d H:00') . '.html';
	$log_file=$GLOBALS['rootpath']['dataphyre'].'logs/'.$log_date=gmdate('Y-m-d H:00') . '.html';
	$new_entry='<tr><td>'.$timestamp.'</td><td>'.$error.$log_data.'</td></tr><!--ENDLOG-->';
	file_put_contents($log_file, $new_entry, FILE_APPEND);
}

function pre_init_error(?string $error_message=null, ?object $exception=null) : never {
	while(ob_get_level()!==0){
		ob_end_clean();
	}
	if(isset($error_message)){
		log_error('Pre-init error: '.$error_message, $exception);
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