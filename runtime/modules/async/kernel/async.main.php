<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('async', 'DP_ASYNC_CFG', [
	'dependencies'=>[],
	'included_vars'=>[],
	'excluded_vars'=>[],
	'framework'=>[
		'default_dispatcher'=>'coroutine',
		'pool_concurrency'=>10,
	],
]);

require_once(__DIR__."/promise.php");
require_once(__DIR__."/coroutine.php");
require_once(__DIR__."/websocket.php");
require_once(__DIR__."/event_emitter.php");
require_once(__DIR__."/process.php");

use dataphyre\async\promise;
use dataphyre\async\coroutine;
use dataphyre\event_emitter;

if(dp_module_present("tracelog")){
	async::set_logger(function($message){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $message);
	});
}

/**
 * Static async entry point for coroutine scheduling, promises, timers, and events.
 *
 * The async kernel module coordinates process-local queues, prioritized event-loop
 * tasks, curl-backed HTTP promises, stream helpers, cancellation flags, throttled
 * and debounced work, coroutine context, and event-emitter bridges. Its public API
 * intentionally stays snake_case for kernel callers while framework code can
 * wrap these primitives in camelCase objects.
 */
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

	/**
	 * Executes a curl request inside the coroutine scheduler and resolves a promise.
	 *
	 * The helper centralizes transport behavior for HTTP helpers. It returns
	 * both the raw curl response and curl_getinfo() metadata on success, and rejects
	 * with an exception containing curl_error() when transport fails.
	 *
	 * @param string $url Curl-supported request URL.
	 * @param array<int,mixed> $options CURLOPT_* integer option map passed directly to curl_setopt_array().
	 * @return promise Promise resolving to array{response:string|bool,info:array<string,mixed>}.
	 */
    private static function send_curl_request(string $url, array $options): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

	/**
	 * Schedules an asynchronous HTTP GET request through the coroutine event loop.
	 *
	 * The request is wrapped in coroutine::async(), then inserted into the prioritized
	 * async event loop. Concurrency is bounded by the static concurrency limit before
	 * curl execution begins. When $return_headers is true the resolved value contains
	 * body and curl info metadata; otherwise it resolves to the response body.
	 *
	 * @param string $url Absolute or curl-supported URL to request.
	 * @param list<string> $headers Header lines passed to CURLOPT_HTTPHEADER, such as "Accept: application/json".
	 * @param bool $return_headers Include curl_getinfo() metadata with the body.
	 * @param int $priority Lower numeric priorities are processed first.
	 * @return object Coroutine async handle for the scheduled request.
	 */
	public static function get_url(string $url, array $headers=[], bool $return_headers=false, int $priority=0): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return coroutine::async(function()use($url, $headers, $return_headers, $priority){
			self::add_to_event_loop(function()use($url, $headers, $return_headers){
				self::manage_concurrency(function()use($url, $headers, $return_headers){
					$options=[
						CURLOPT_RETURNTRANSFER=>true,
						CURLOPT_FOLLOWLOCATION=>true,
						CURLOPT_HTTPHEADER=>$headers
					];
					$result=yield self::send_curl_request($url, $options);
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
	
	/**
	 * Schedules an asynchronous form-encoded HTTP POST request.
	 *
	 * The form fields are encoded with http_build_query() and dispatched through the same
	 * prioritized, concurrency-limited curl path as get_url(). The resolved value is
	 * either the response body or an array with body and curl info metadata.
	 *
	 * @param string $url Absolute or curl-supported URL to request.
	 * @param array<string,mixed> $data Form fields encoded with http_build_query() for CURLOPT_POSTFIELDS.
	 * @param list<string> $headers Header lines passed to CURLOPT_HTTPHEADER.
	 * @param bool $return_headers Include curl_getinfo() metadata with the body.
	 * @param int $priority Lower numeric priorities are processed first.
	 * @return object Coroutine async handle for the scheduled request.
	 */
	public static function post_url(string $url, array $data, array $headers=[], bool $return_headers=false, int $priority=0): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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
					$result=yield self::send_curl_request($url, $options);
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

	/**
	 * Fetches JSON from a URL and resolves with decoded associative data.
	 *
	 * The request advertises application/json and delegates transport to the shared
	 * curl promise helper. Transport failures reject the promise; JSON decoding uses
	 * PHP's default json_decode() behavior and therefore returns null for invalid JSON.
	 *
	 * @param string $url Absolute or curl-supported URL returning JSON.
	 * @return promise Promise resolving to decoded JSON data.
	 */
	public static function get_json(string $url): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$promise=new promise(function($resolve, $reject)use($url){
			coroutine::create(function()use($url, $resolve, $reject){
				try{
					$response=yield self::send_curl_request($url, [
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

	/**
	 * Sends an asynchronous JSON POST request and decodes the JSON response.
	 *
	 * The input array is encoded with json_encode(), sent with a JSON content-type,
	 * and decoded as an associative array on success. Transport failures reject the
	 * promise.
	 *
	 * @param string $url Absolute or curl-supported URL receiving JSON.
	 * @param array<string,mixed>|list<mixed> $data JSON-serializable request data encoded for CURLOPT_POSTFIELDS.
	 * @return promise Promise resolving to decoded JSON response data.
	 */
	public static function post_json(string $url, array $data): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$promise=new promise(function($resolve, $reject)use($url, $data){
			coroutine::create(function()use($url, $data, $resolve, $reject){
				try{
					$response=yield self::send_curl_request($url, [
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
	
    /**
     * Reads a stream into memory from within a coroutine-backed promise.
     *
     * The method repeatedly reads 8 KiB chunks until EOF and yields through
     * coroutine::sleep(0) between chunks to allow other async work to advance.
     *
     * @param resource $stream Readable PHP stream resource.
     * @return promise Promise resolving to the complete stream contents.
     */
    public static function read_stream($stream): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

    /**
     * Writes data to a stream from within a coroutine-backed promise.
     *
     * fwrite() failure rejects the promise with an exception. On success the
     * promise resolves to the number of bytes written by PHP.
     *
     * @param resource $stream Writable PHP stream resource.
     * @param string $data Bytes accepted by fwrite().
     * @return promise Promise resolving to the written byte count.
     */
    public static function write_stream($stream, $data): promise {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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
	
	/**
	 * Runs a keyed task at most once per interval window.
	 *
	 * The first call for an idle key schedules the task with set_timeout(). Later
	 * calls for the same key are ignored until the timeout callback executes and
	 * clears the key's throttle flag.
	 *
	 * @param string $key Throttle bucket identifier.
	 * @param callable $task Task to execute when the interval expires.
	 * @param int $interval Delay in milliseconds before the task runs.
	 * @return void
	 */
	public static function throttle(string $key, callable $task, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

	/**
	 * Delays a keyed task until calls stop arriving for the interval.
	 *
	 * Any existing timeout for the key is cancelled before scheduling the new one,
	 * so only the most recent task for that key is retained.
	 *
	 * @param string $key Debounce bucket identifier.
	 * @param callable $task Task to execute after inactivity.
	 * @param int $interval Delay in milliseconds after the last call.
	 * @return void
	 */
	public static function debounce(string $key, callable $task, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(isset(self::$debounce_tasks[$key])){
			self::cancel(self::$debounce_tasks[$key]);
		}
		self::$debounce_tasks[$key]=self::set_timeout($task, $interval);
	}
		
	/**
	 * Adds a callable to the synchronous FIFO task queue.
	 *
	 * When the queue was previously empty, processing begins immediately and drains
	 * recursively until every queued task has run.
	 *
	 * @param callable $task Task to append to the FIFO queue.
	 * @return void
	 */
	public static function queue(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::$task_queue[]=$task;
		if(count(self::$task_queue)===1){
			self::process_next_in_queue();
		}
	}
	
	/**
	 * Registers the logger used by async error and diagnostic helpers.
	 *
	 * The callable receives one message string. Module initialization wires this to
	 * tracelog when that module is present.
	 *
	 * @param callable $logger Logger callback receiving a message string.
	 * @return void
	 */
	public static function set_logger(callable $logger): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::$logger=$logger;
	}

	/**
	 * Writes a diagnostic message through the configured async logger.
	 *
	 * Missing loggers are ignored so async helpers can be used before tracelog or
	 * another logging module is available.
	 *
	 * @param string $message Diagnostic message.
	 */
	private static function log(string $message): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(self::$logger){
			call_user_func(self::$logger, $message);
		}
	}

	/**
	 * Logs and rethrows an exception raised during async execution.
	 *
	 * @param \Exception $ex Exception to report and propagate.
	 *
	 * @throws \Exception Always rethrows the supplied exception.
	 */
	private static function handle_error(\Exception $ex): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::log('Error: '.$ex->getMessage());
		self::log('Stack trace: '.$ex->getTraceAsString());
		throw $ex;
	}

	/**
	 * Drains the synchronous FIFO task queue recursively.
	 *
	 * Each task is removed before invocation so tasks appended while running are
	 * processed after older queued work.
	 */
	private static function process_next_in_queue(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!empty(self::$task_queue)){
			$task=array_shift(self::$task_queue);
			$task();
			self::process_next_in_queue();
		}
	}
		
	/**
	 * Creates an uncancelled token tracked in async static state.
	 *
	 * Tokens are unique process-local strings and are not automatically removed;
	 * long-running workers should treat them as lightweight coordination flags.
	 *
	 * @return string New cancellation token identifier.
	 */
	public static function create_cancellation_token(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$token=uniqid('token_', true);
		self::$cancellation_tokens[$token]=false;
		return $token;
	}

	/**
	 * Marks a known cancellation token as cancelled.
	 *
	 * Unknown tokens are ignored, allowing callers to race cancellation against task
	 * completion without defensive existence checks.
	 *
	 * @param string $token Token returned by create_cancellation_token().
	 * @return void
	 */
	public static function cancel_token(string $token): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(isset(self::$cancellation_tokens[$token])){
			self::$cancellation_tokens[$token]=true;
		}
	}

	/**
	 * Checks whether a cancellation token has been marked cancelled.
	 *
	 * Unknown tokens are treated as active and return false.
	 *
	 * @param string $token Token returned by create_cancellation_token().
	 * @return bool True when the token exists and has been cancelled.
	 */
	public static function is_cancelled(string $token): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return self::$cancellation_tokens[$token]??false;
	}
	
	/**
	 * Drains prioritized and current batch queues until no batch work remains.
	 *
	 * Prioritized event-loop tasks are processed first. A partially-filled current
	 * batch is then executed once to avoid leaving deferred work stranded.
	 *
	 * @return void
	 */
	public static function process_batches(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		while(!empty(self::$prioritized_event_loop)){
			self::process_batch();
		}
		if(!empty(self::$current_batch)){
			self::process_batch();
		}
	}

	/**
	 * Runs or queues a task according to the process-local rate limit.
	 *
	 * Tasks exceeding the configured limit are appended to the shared waiting queue
	 * and resumed by task_rate_complete().
	 *
	 * @param callable $task Task guarded by the rate limiter.
	 */
	private static function manage_rate_limiting(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(self::$current_rate<self::$rate_limit){
			self::$current_rate++;
			$task();
		}
		else
		{
			self::$waiting_queue[]=$task;
		}
	}

	/**
	 * Releases one rate-limit slot and starts the next waiting task.
	 */
	private static function task_rate_complete(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::$current_rate--;
		if(!empty(self::$waiting_queue)){
			$task=array_shift(self::$waiting_queue);
			self::manage_rate_limiting($task);
		}
	}

	/**
	 * Runs or queues a task according to the concurrency limit.
	 *
	 * The concurrency counter is incremented before task invocation. Callers that
	 * use this helper are responsible for eventually calling task_complete() when
	 * work finishes.
	 *
	 * @param callable $task Task guarded by the concurrency limiter.
	 */
	private static function manage_concurrency(callable $task): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(self::$current_concurrency<self::$concurrency_limit){
			self::$current_concurrency++;
			$task();
		}
		else
		{
			self::$waiting_queue[]=$task;
		}
	}
	
	/**
	 * Registers an async module listener with the shared event emitter.
	 *
	 * Listener ordering is delegated to event_emitter::on(), including the priority
	 * semantics implemented by the emitter module.
	 *
	 * @param string $event Event name to observe.
	 * @param callable $listener Listener invoked by the event emitter.
	 * @param int $priority Listener priority for emitter ordering.
	 * @return void
	 */
	public static function on_event(string $event, callable $listener, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		event_emitter::on($event, $listener, $priority);
	}

	/**
	 * Registers an event listener together with metadata for later inspection.
	 *
	 * Metadata storage and retrieval semantics are delegated to the shared event
	 * emitter; async exposes this as a convenience bridge for kernel callers.
	 *
	 * @param string $event Event name to observe.
	 * @param callable $listener Listener invoked by the event emitter.
	 * @param array<string,mixed> $metadata Arbitrary listener metadata stored with the event emitter entry.
	 * @return void
	 */
	public static function add_listener_with_metadata(string $event, callable $listener, array $metadata): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		event_emitter::add_listener_with_metadata($event, $listener, $metadata);
	}

	/**
	 * Releases one concurrency slot and resumes the next waiting task.
	 */
	private static function task_complete(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::$current_concurrency--;
		if(!empty(self::$waiting_queue)){
			$task=array_shift(self::$waiting_queue);
			self::manage_concurrency($task);
		}
	}

	/**
	 * Adds a task to the prioritized async event-loop queue.
	 *
	 * Lower numeric priorities are processed first by run_event_loop().
	 *
	 * @param callable $task Task to schedule.
	 * @param int $priority Event-loop priority.
	 */
	private static function add_to_event_loop(callable $task, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!isset(self::$prioritized_event_loop[$priority])){
			self::$prioritized_event_loop[$priority]=[];
		}
		self::$prioritized_event_loop[$priority][]=$task;
		ksort(self::$prioritized_event_loop);
	}

	/**
	 * Executes and clears the current batch queue.
	 *
	 * The batch snapshot is cleared before tasks run so tasks queued during a batch
	 * belong to a later drain cycle.
	 */
	private static function process_batch(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$batch=self::$current_batch;
		self::$current_batch=[];
		foreach($batch as $task){
			$task();
		}
	}

	/**
	 * Runs scheduled async event-loop and batch work until both queues are empty.
	 *
	 * Prioritized tasks are popped in key order and executed before current batch
	 * work. Tasks may enqueue more work while the loop is running.
	 *
	 * @return void
	 */
	public static function run_event_loop(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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
	
	/**
	 * Wraps an executor in a promise and schedules cancellation after a timeout.
	 *
	 * The timeout uses coroutine scheduling and calls cancel() on the created
	 * promise. Executor behavior and cancellation callbacks are owned by promise.
	 *
	 * @param callable $executor Promise executor receiving resolve and reject callbacks.
	 * @param int $timeout Cancellation delay in milliseconds.
	 * @return promise Promise created from the executor.
	 */
	public static function with_timeout(callable $executor, int $timeout): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$promise=new promise($executor);
		self::set_timeout(function()use($promise){
			$promise->cancel();
		}, $timeout);
		return $promise;
	}
	
	/**
	 * Awaits multiple callable tasks and resolves when all promises complete.
	 *
	 * Each task is passed through async::await(), then combined with promise::all().
	 * Rejection semantics are inherited from promise::all().
	 *
	 * @param array<int, callable> $tasks Callable tasks to await in parallel.
	 * @return promise Promise resolving to all task results.
	 */
	public static function parallel(array $tasks): promise {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return promise::all(array_map(function($task){
			return self::await($task);
		}, $tasks));
	}

	/**
	 * Schedules a one-shot coroutine timer.
	 *
	 * The returned identifier can be passed to cancel() before the timer fires.
	 *
	 * @param callable $callable Timer callback.
	 * @param int $milliseconds Delay before execution.
	 * @return int Coroutine timer identifier.
	 */
	public static function set_timeout(callable $callable, int $milliseconds): int {
		return coroutine::set_timeout($callable, $milliseconds);
	}

	/**
	 * Schedules a repeating coroutine timer.
	 *
	 * The returned identifier can be passed to cancel() to stop future executions.
	 *
	 * @param callable $callable Interval callback.
	 * @param int $milliseconds Delay between executions.
	 * @return int Coroutine timer identifier.
	 */
	public static function set_interval(callable $callable, int $milliseconds): int {
		return coroutine::set_interval($callable, $milliseconds);
	}

	/**
	 * Cancels a coroutine task or timer by identifier.
	 *
	 * Identifiers are produced by coroutine scheduling helpers exposed through async,
	 * including set_timeout(), set_interval(), and defer().
	 *
	 * @param int $id Coroutine task or timer identifier.
	 * @return void
	 */
	public static function cancel(int $id): void {
		coroutine::cancel($id);
	}

	/**
	 * Defers a callable through the coroutine scheduler.
	 *
	 * Deferred work runs according to coroutine's event-loop rules and returns an
	 * identifier that can be cancelled before execution.
	 *
	 * @param callable $callable Deferred callback.
	 * @return int Coroutine deferred task identifier.
	 */
	public static function defer(callable $callable): int {
		return coroutine::defer($callable);
	}

	/**
	 * Runs a callable through coroutine::await() and returns the chained promise.
	*
	 * Despite the name, coroutine::await() registers a pass-through continuation
	 * and does not synchronously block until the callable resolves.
	*
	 * @param callable $callable Callable to await.
	 * @return mixed Chained promise returned by the coroutine scheduler.
	 */
	public static function await(callable $callable): mixed {
		return coroutine::await($callable);
	}

	/**
	 * Stores a value in coroutine execution context.
	 *
	 * Context keys and values are delegated to coroutine static state, making them
	 * process-local and scheduler-scoped rather than request-global persistence.
	 *
	 * @param int|string $key Context key.
	 * @param mixed $value Context value stored without cloning or serialization.
	 * @return void
	 */
	public static function set_context(mixed $key, mixed $value): void {
		coroutine::set_context($key, $value);
	}

	/**
	 * Reads a value from coroutine execution context.
	 *
	 * Missing keys return the coroutine layer's default value, usually null.
	 *
	 * @param int|string $key Context key.
	 * @return mixed coroutine context value for the key, usually null when absent.
	 */
	public static function get_context(mixed $key): mixed {
		return coroutine::get_context($key);
	}

	/**
	 * Applies promise-level timeout behavior to an existing promise.
	 *
	 * This delegates to promise::timeout(), preserving the promise module's
	 * cancellation and rejection semantics.
	 *
	 * @param promise $promise Promise to guard with a timeout.
	 * @param int $timeout Timeout in milliseconds.
	 * @return promise Timeout-wrapped promise.
	 */
	public static function timeout(promise $promise, int $timeout): promise {
		return promise::timeout($promise, $timeout);
	}

	/**
	 * Retries a promise-producing task through the promise module.
	 *
	 * Retry count and delay semantics are inherited from promise::retry().
	 *
	 * @param callable $task Task invoked for each attempt.
	 * @param int $retries Maximum retry attempts after the first failure.
	 * @param int $delay Delay between attempts in milliseconds.
	 * @return promise Promise resolving from the successful attempt.
	 */
	public static function retry(callable $task, int $retries, int $delay=0): promise {
		return promise::retry($task, $retries, $delay);
	}

	/**
	 * Sets the number of tasks the async batch processor should group together.
	 *
	 * The value is stored directly in static state for later batch scheduling. The
	 * current implementation does not clamp or validate the size.
	 *
	 * @param int $size Desired batch size.
	 * @return void
	 */
	public static function set_batch_size(int $size): void {
		self::$batch_size=$size;
	}

}
