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

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

if(file_exists($filepath=$rootpath['common_dataphyre']."config/async.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/async.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['async'])){
	core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAsync: No configuration file available.', 'safemode');
}

require_once(__DIR__."/promise.php");
require_once(__DIR__."/coroutine.php");
require_once(__DIR__."/websocket.php");
require_once(__DIR__."/event_emitter.php");
require_once(__DIR__."/process.php");

// Promise class
/*
class promise {
	private $state='pending';
	private $value;
	private $handlers=[];
	private $on_cancel;
	private $is_cancelled=false;
	public function __construct(callable $executor, callable $on_cancel=null){
	public static function all(array $promises): self {
	public static function race(array $promises): self {
	public static function allSettled(array $promises): self {
	public static function with_timeout(callable $executor, int $timeout): self {
	public function then(?callable $on_fulfilled=null, ?callable $on_rejected=null): self {
	public function catch(callable $on_rejected): self {
	public function finally(callable $on_finally): self {
	public function cancel(): void {
	public function on_cancel(callable $callback): self {
	private function handle(callable $on_fulfilled, callable $on_rejected): void {
	private function resolve(mixed $value): void {
	private function reject(string|object $reason): void {
	public static function retry(callable $task, int $retries, int $delay=0): self {
}
*/

// Coroutine class
/*
class coroutine{
	protected static $tasks=[];
	protected static $id=0;
	protected static $waiting=[];
	protected static $fibers=[];
	protected static $event_loop_running=false;
	protected static $deferred=[];
	protected static $context=[]; 
	protected static $prioritized_tasks=[];
	public static function create(callable $callable, int $priority=0): int {
	public static function run(): void {
	public static function sleep(int $seconds): void {
	public static function async(callable $callable): object {
	public static function set_timeout(callable $callable, int $milliseconds): void {
	public static function set_interval(callable $callable, int $milliseconds): void {
	public static function cancel(int $id): void {
	public static function defer(callable $callable): int {
	public static function await(callable $callable): mixed {
	public static function set_context(mixed $key, mixed $value): void {
	public static function get_context(mixed $key) : mixed{
}
*/

// web_socket_server class
/*
class web_socket_server{
	protected $address;
	protected $port;
	protected $clients;
	protected $sockets;
	protected $callbacks;
	public function __construct($address, $port){
	public function on($event, $callback){
	public function start(){
	private function handshake($client){
	private function broadcast($client, $msg){
	private function unmask($payload){
	private function mask($text){
}
*/

// event_emitter class
/*
class event_emitter {
	private $listeners=[];
	private $max_listeners=10;
	private $default_listeners=[];
	private $listener_groups=[];
	private $event_aliases=[];
	private $logging_enabled=false;
	private $logger;
	private $payload_transformers=[];
	private $async_mode=false;
	private $wildcard_handlers=[];
	private $namespace_handlers=[];
	private $propagation_stopped=[];
	public function on(string $event, callable $listener, int $priority=0, string $group=null): void {
	public function emit(string $event, ...$args): void {
	public function remove_listener(string $event, callable $listener): void {
	public function once(string $event, callable $listener, int $priority=0): void {
	public function set_max_listeners(int $max_listeners): void {
	public function get_listener_count(string $event): int {
	public function remove_all_listeners(string $event=null): void {
	public function set_default_listener(callable $listener): void {
	public function set_event_alias(string $event, string $alias): void {
	public function enable_logging(callable $logger): void {
	public function disable_logging(): void {
	public function inspect_listeners(string $event): array {
	private function handle_error(\Exception $ex): void {
	public function throttle(string $event, int $interval): void {
	public function debounce(string $event, int $interval): void {
	public function set_payload_transformer(string $event, callable $transformer): void {
	public function enable_async_mode(): void {
	public function disable_async_mode(): void {
	private function handle_async(callable $listener, ...$args): void {
	public function get_group_listeners(string $group): array {
	public function remove_group_listeners(string $group): void {
	public function stop_propagation(string $event): void {
	public function continue_propagation(string $event): void {
	private function match_event(string $event): array {
	public function add_wildcard_listener(string $pattern, callable $listener): void {
	public function emit_to_namespace(string $namespace, ...$args): void {
	public function on_namespace(string $namespace, callable $listener): void {
	public function add_listener_with_metadata(string $event, callable $listener, array $metadata): void {
	public function get_listener_metadata(string $event): array {
	public function add_conditional_listener(string $event, callable $listener, callable $condition): void {
	public function intercept_event(string $event, callable $interceptor): void {
}
*/

use dataphyre\async\promise;
use dataphyre\async\coroutine;
use dataphyre\event_emitter;

if(dp_module_present("tracelog")){
	async::set_logger(function($message){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $message);
	});
}

class async {

	private static $event_loop=[];
	private static $concurrency_limit=10;
	private static $current_concurrency=0;
	private static $waiting_queue=[];
	private static $prioritized_event_loop=[];
	private static $batch_size=5;
	private static $current_batch=[];
	private static $rate_limit=100;
	private static $current_rate=0;
	private static $throttle_tasks=[];
	private static $debounce_tasks=[];
	private static $task_queue=[];
	private static $logger;
	private static $cancellation_tokens=[];

    private static function send_curl_request(string $url, array $options): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        return new promise(function($resolve, $reject)use($url, $options){
            coroutine::create(function()use($url, $options, $resolve, $reject){
                $ch=curl_init($url);
                curl_setopt_array($ch, $options);
                $response=curl_exec($ch);
                $error=curl_error($ch);
                $info=curl_getinfo($ch);
                curl_close($ch);
                if($error){
                    $reject(new \Exception($error));
                }
				else
				{
                    $resolve(['response' => $response, 'info' => $info]);
                }
            });
        });
    }

	public static function get_url(string $url, array $headers=[], bool $return_headers=false, int $priority=0): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return coroutine::async(function()use($url, $headers, $return_headers, $priority){
			self::add_to_event_loop(function()use($url, $headers, $return_headers){
				self::manage_concurrency(function()use($url, $headers, $return_headers){
					$options=[
						CURLOPT_RETURNTRANSFER=>true,
						CURLOPT_FOLLOWLOCATION=>true,
						CURLOPT_HTTPHEADER=>$headers
					];
					$result=self::send_curl_request($url, $options);
					$response=$result['response'];
					$info=$result['info'];
					if($return_headers){
						return ['body'=>$response, 'headers'=>$info];
					}
					else
					{
						return $response;
					}
				});
			}, $priority);
		});
	}
	
	public static function post_url(string $url, array $data, array $headers=[], bool $return_headers=false, int $priority=0): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return coroutine::async(function()use($url, $data, $headers, $return_headers, $priority){
			self::add_to_event_loop(function()use($url, $data, $headers, $return_headers){
				self::manage_concurrency(function()use($url, $data, $headers, $return_headers){
					$options=[
						CURLOPT_RETURNTRANSFER=>true,
						CURLOPT_FOLLOWLOCATION=>true,
						CURLOPT_POST=>true,
						CURLOPT_POSTFIELDS=>http_build_query($data),
						CURLOPT_HTTPHEADER=>$headers
					];
					$result=self::send_curl_request($url, $options);
					$response=$result['response'];
					$info=$result['info'];
					if($return_headers){
						return ['body'=>$response, 'headers'=>$info];
					}
					else
					{
						return $response;
					}
				});
			}, $priority);
		});
	}

	public static function get_json(string $url): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$promise=new promise(function($resolve, $reject)use($url){
			coroutine::create(function()use($url, $resolve, $reject){
				try{
					$response=self::send_curl_request($url, [
						CURLOPT_RETURNTRANSFER=>true,
						CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
					]);
                    $resolve($response->then(function($result){
                        return json_decode($result['response'], true);
                    }));
				}catch(Exception $e){
					$reject($e);
				}
			});
		});
		return $promise;
	}

	public static function post_json(string $url, array $data): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$promise=new promise(function($resolve, $reject)use($url, $data){
			coroutine::create(function()use($url, $data, $resolve, $reject){
				try{
					$response=self::send_curl_request($url, [
						CURLOPT_RETURNTRANSFER=>true,
						CURLOPT_POST=>true,
						CURLOPT_POSTFIELDS=>json_encode($data),
						CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
					]);
                    $resolve($response->then(function($result){
                        return json_decode($result['response'], true);
                    }));
				}catch(Exception $e){
					$reject($e);
				}
			});
		});
		return $promise;
	}
	
    public static function read_stream($stream): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        return new promise(function($resolve, $reject)use($stream){
            coroutine::create(function()use($stream, $resolve, $reject){
                $data='';
                while(!feof($stream)){
                    $data .= fread($stream, 8192);
                    // Simulate asynchronous behavior
                    coroutine::sleep(0);
                }
                if($data===false){
                    $reject(new \Exception("Error reading stream"));
                }
				else
				{
                    $resolve($data);
                }
            });
        });
    }

    public static function write_stream($stream, $data): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        return new promise(function($resolve, $reject)use($stream, $data){
            coroutine::create(function()use($stream, $data, $resolve, $reject){
                $result=fwrite($stream, $data);
                if($result===false){
                    $reject(new \Exception("Error writing to stream"));
                }
				else
				{
                    $resolve($result);
                }
            });
        });
    }
	
	public static function throttle(string $key, callable $task, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$throttle_tasks[$key])){
			self::$throttle_tasks[$key]=false;
		}
		if(!self::$throttle_tasks[$key]){
			self::$throttle_tasks[$key]=true;
			self::set_timeout(function()use($key, $task){
				$task();
				self::$throttle_tasks[$key]=false;
			}, $interval);
		}
	}

	public static function debounce(string $key, callable $task, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset(self::$debounce_tasks[$key])){
			self::cancel(self::$debounce_tasks[$key]);
		}
		self::$debounce_tasks[$key]=self::set_timeout($task, $interval);
	}
		
	public static function queue(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$task_queue[]=$task;
		if(count(self::$task_queue)===1){
			self::process_next_in_queue();
		}
	}
	
	public static function set_logger(callable $logger): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$logger=$logger;
	}

	private static function log(string $message): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$logger){
			call_user_func(self::$logger, $message);
		}
	}
	
	private static function handle_error(\Exception $ex): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::log('Error: '.$ex->getMessage());
		self::log('Stack trace: '.$ex->getTraceAsString());
		throw $ex;
	}

	private static function process_next_in_queue(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!empty(self::$task_queue)){
			$task=array_shift(self::$task_queue);
			$task();
			self::process_next_in_queue();
		}
	}
		
	public static function create_cancellation_token(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$token=uniqid('token_', true);
		self::$cancellation_tokens[$token]=false;
		return $token;
	}

	public static function cancel_token(string $token): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset(self::$cancellation_tokens[$token])){
			self::$cancellation_tokens[$token]=true;
		}
	}

	public static function is_cancelled(string $token): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return self::$cancellation_tokens[$token]??false;
	}
	
	public static function process_batches(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		while(!empty(self::$prioritized_event_loop)){
			self::process_batch();
		}
		if(!empty(self::$current_batch)){
			self::process_batch();
		}
	}
		
	private static function manage_rate_limiting(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$current_rate<self::$rate_limit){
			self::$current_rate++;
			$task();
		}
		else
		{
			self::$waiting_queue[]=$task;
		}
	}

	private static function task_rate_complete(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$current_rate--;
		if(!empty(self::$waiting_queue)){
			$task=array_shift(self::$waiting_queue);
			self::manage_rate_limiting($task);
		}
	}
	
	private static function manage_concurrency(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$current_concurrency<self::$concurrency_limit){
			self::$current_concurrency++;
			$task();
		}
		else
		{
			self::$waiting_queue[]=$task;
		}
	}
	
	public static function on_event(string $event, callable $listener, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		event_emitter::on($event, $listener, $priority);
	}

	public static function add_listener_with_metadata(string $event, callable $listener, array $metadata): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		event_emitter::add_listener_with_metadata($event, $listener, $metadata);
	}

	private static function task_complete(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$current_concurrency--;
		if(!empty(self::$waiting_queue)){
			$task=array_shift(self::$waiting_queue);
			self::manage_concurrency($task);
		}
	}

	private static function add_to_event_loop(callable $task, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$prioritized_event_loop[$priority])){
			self::$prioritized_event_loop[$priority]=[];
		}
		self::$prioritized_event_loop[$priority][]=$task;
		ksort(self::$prioritized_event_loop);
	}

	private static function process_batch(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$batch=self::$current_batch;
		self::$current_batch=[];
		foreach($batch as $task){
			$task();
		}
	}

	public static function run_event_loop(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		while(!empty(self::$prioritized_event_loop) || !empty(self::$current_batch)){
			if(!empty(self::$prioritized_event_loop)){
				$priorities=array_keys(self::$prioritized_event_loop);
				$priority=$priorities[0];
				$task=array_shift(self::$prioritized_event_loop[$priority]);
				if(empty(self::$prioritized_event_loop[$priority])){
					unset(self::$prioritized_event_loop[$priority]);
				}
				$task();
			}
			else
			{
				self::process_batch();
			}
		}
	}
	
	public static function with_timeout(callable $executor, int $timeout): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$promise=new promise($executor);
		self::set_timeout(function()use($promise){
			$promise->cancel();
		}, $timeout);
		return $promise;
	}
	
	public static function parallel(array $tasks): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return promise::all(array_map(function($task){
			return self::await($task);
		}, $tasks));
	}

	public static function set_timeout(callable $callable, int $milliseconds): void {
		coroutine::set_timeout($callable, $milliseconds);
	}

	public static function set_interval(callable $callable, int $milliseconds): void {
		coroutine::set_interval($callable, $milliseconds);
	}

	public static function cancel(int $id): void {
		coroutine::cancel($id);
	}

	public static function defer(callable $callable): int {
		return coroutine::defer($callable);
	}

	public static function await(callable $callable): mixed {
		return coroutine::await($callable);
	}

	public static function set_context(mixed $key, mixed $value): void {
		coroutine::set_context($key, $value);
	}

	public static function get_context(mixed $key): mixed {
		return coroutine::get_context($key);
	}

	public static function timeout(promise $promise, int $timeout): promise {
		return promise::timeout($promise, $timeout);
	}

	public static function retry(callable $task, int $retries, int $delay=0): promise {
		return promise::retry($task, $retries, $delay);
	}

	public static function set_batch_size(int $size): void {
		self::$batch_size=$size;
	}

}