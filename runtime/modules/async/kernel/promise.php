<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\async;

/**
 * Coordinates a single asynchronous completion value inside the Dataphyre coroutine runtime.
 *
 * A promise starts pending, settles exactly once as fulfilled or rejected, and dispatches queued handlers synchronously when settlement occurs. Cancellation is advisory: it prevents future handler dispatch from handle(), invokes registered cancellation callbacks, and delegates to the optional on-cancel hook, but it does not rewind external work that the executor already started.
 */
class promise {
	
	private $state='pending';
	private $value;
	private $handlers=[];
	private $on_cancel;
	private $is_cancelled=false;
	private $cancel_callbacks=[];

	/**
	 * Starts a promise by executing the supplied resolver function immediately.
	 *
	 * The executor receives resolve and reject callbacks that close over this promise. Exceptions thrown by the executor reject the promise; values passed to resolve may themselves be promises, in which case this instance adopts the nested promise's eventual state.
	 *
	 * @param callable $executor Function invoked as executor(callable $resolve, callable $reject).
	 * @param ?callable $on_cancel Optional hook invoked when cancel() is called.
	 */
	public function __construct(callable $executor, ?callable $on_cancel=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$resolve=function($value){
			$this->resolve($value);
		};
		$reject=function($reason){
			$this->reject($reason);
		};
		$this->on_cancel=$on_cancel;
		try{
			$executor($resolve, $reject);
		}catch(\Exception $e){
			$this->reject($e);
		}
	}

	/**
	 * Resolves when every input promise fulfills, or rejects on the first rejection.
	 *
	 * Result positions preserve the original array keys used during iteration. An empty input resolves immediately with an empty array, allowing callers to compose fan-in operations without a separate base case.
	 *
	 * @param array<int|string,self> $promises Promises to wait for.
	 * @return self Promise fulfilled with an array of values or rejected with the first rejection reason.
	 */
	public static function all(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve, $reject)use($promises){
			$results=[];
			$remaining=count($promises);
			if($remaining===0){
				$resolve($results);
				return;
			}

			foreach($promises as $i=>$promise){
				$promise->then(function($value)use(&$results, $i, &$remaining, $resolve){
					$results[$i]=$value;
					$remaining--;
					if($remaining===0){
						$resolve($results);
					}
				}, $reject);
			}
		});
	}
	
	/**
	 * Re-runs an asynchronous task until it fulfills or the retry budget is exhausted.
	 *
	 * The task must return a promise each time it is called. Rejections schedule another attempt until the attempt count reaches the configured retry limit; delays are scheduled through the coroutine timer so retry timing remains inside the async kernel.
	 *
	 * @param callable $task Function returning a promise for one attempt.
	 * @param int $retries Maximum number of attempts, including the initial attempt.
	 * @param int $delay Delay in milliseconds before retrying after a rejection.
	 * @return self Promise fulfilled with the successful attempt value or rejected with the final reason.
	 */
	public static function retry(callable $task, int $retries, int $delay=0): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve, $reject)use($task, $retries, $delay){
			$attempt=0;
			$try=function()use($task, &$attempt, $resolve, $reject, $retries, $delay, &$try){
				$attempt++;
				$task()->then($resolve)->catch(function($reason)use($attempt, $retries, $delay, $try, $reject){
					if($attempt<$retries){
						if($delay>0){
							coroutine::set_timeout($try, $delay);
						}
						else
						{
							$try();
						}
					}
					else
					{
						$reject($reason);
					}
				});
			};
			$try();
		});
	}

	/**
	 * Settles with the first input promise that fulfills or rejects.
	 *
	 * The returned promise attaches to every input promise and mirrors the earliest settlement. An empty race resolves with null, giving callers a deterministic sentinel instead of leaving an unobservable pending promise.
	 *
	 * @param array<int|string,self> $promises Promises competing to settle the returned promise.
	 * @return self Promise settled by the first input settlement, or fulfilled with null for an empty input.
	 */
	public static function race(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve, $reject)use($promises){
			if($promises===[]){
				$resolve(null);
				return;
			}
			foreach($promises as $promise){
				$promise->then($resolve, $reject);
			}
		});
	}

	/**
	 * Resolves after every input promise has either fulfilled or rejected.
	 *
	 * Unlike all(), this helper never rejects because of an input rejection. Each result row records the input promise's final state and stored value or rejection reason, which makes it suitable for diagnostics, cleanup, or best-effort fan-out work.
	 *
	 * @param array<int|string,self> $promises Promises to observe until settlement.
	 * @return self Promise fulfilled with settlement rows keyed by the original input keys.
	 */
	public static function all_settled(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve)use($promises){
			$results=[];
			$remaining=count($promises);
			if($remaining===0){
				$resolve($results);
				return;
			}
			foreach($promises as $i=>$promise){
				$promise->finally(function()use(&$results, $i, &$remaining, $promise){
					$results[$i]=[
						'status'=>$promise->state,
						'value'=>$promise->value
					];
					$remaining--;
					if($remaining===0){
						$resolve($results);
					}
				});
			}
		});
	}

	/**
	 * Wraps an executor with a coroutine timeout.
	 *
	 * The timer rejects the returned promise with an Exception when it fires first. Fulfillment or rejection from the executor cancels the timer before settling, preventing the timeout from racing after the wrapped work has already completed.
	 *
	 * @param callable $executor Function invoked as executor(callable $resolve, callable $reject).
	 * @param int $timeout Timeout in milliseconds.
	 * @return self Promise mirroring the executor unless the timeout rejects first.
	 */
	public static function with_timeout(callable $executor, int $timeout): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve, $reject)use($executor, $timeout){
			$timer=coroutine::set_timeout(function()use($reject){
				$reject(new \Exception("Promise timed out"));
			}, $timeout);
			$executor(
				function($value)use($resolve, $timer){
					coroutine::cancel($timer);
					$resolve($value);
				},
				function($reason)use($reject, $timer){
					coroutine::cancel($timer);
					$reject($reason);
				}
			);
		});
	}

	/**
	 * Applies a coroutine timeout to an existing promise.
	 *
	 * The returned promise follows the supplied promise if it settles before the timer. Timeout rejection does not cancel the original promise; callers that need cancellation must call cancel() or wire an on-cancel hook separately.
	 *
	 * @param self $promise Promise to mirror.
	 * @param int $timeout Timeout in milliseconds.
	 * @return self Promise rejected on timeout or settled with the supplied promise's outcome.
	 */
	public static function timeout(self $promise, int $timeout): self {
		return self::with_timeout(function($resolve, $reject)use($promise){
			$promise->then($resolve, $reject);
		}, $timeout);
	}

	/**
	 * Chains fulfillment and rejection handlers onto this promise.
	 *
	 * Handlers are queued while this promise is pending and invoked immediately if it is already settled. A handler return value fulfills the chained promise; `Exception` instances thrown by a handler reject it. Missing handlers pass fulfillment values through or propagate rejection reasons unchanged.
	 *
	 * @param ?callable $on_fulfilled Optional handler invoked with the fulfillment value.
	 * @param ?callable $on_rejected Optional handler invoked with the rejection reason.
	 * @return self Chained promise representing the handler result.
	 */
	public function then(?callable $on_fulfilled=null, ?callable $on_rejected=null): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return new self(function($resolve, $reject)use($on_fulfilled, $on_rejected){
			$this->handle(function($value)use($on_fulfilled, $resolve, $reject){
				if(is_callable($on_fulfilled)){
					try{
						$resolve($on_fulfilled($value));
					}catch(\Exception $e){
						$reject($e);
					}
				}
				else
				{
					$resolve($value);
				}
			}, function($reason)use($on_rejected, $resolve, $reject){
				if(is_callable($on_rejected)){
					try{
						$resolve($on_rejected($reason));
					}catch(\Exception $e){
						$reject($e);
					}
				}
				else
				{
					$reject($reason);
				}
			});
		}, $this->on_cancel);
	}

	/**
	 * Registers a rejection handler by chaining through then().
	 *
	 *
	 * @param callable $on_rejected Handler invoked with the rejection reason.
	 * @return self Chained promise representing the rejection handler result.
	 */
	public function catch(callable $on_rejected): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return $this->then(null, $on_rejected);
	}

	/**
	 * Registers cleanup work that runs after fulfillment or rejection.
	 *
	 * The cleanup callback receives no value and its normal return value is ignored. Fulfillment values pass through after cleanup. Rejection reasons are thrown again after cleanup; callers should reject with Throwable-compatible objects when chaining through finally().
	 *
	 * @param callable $on_finally Cleanup callback invoked after this promise settles.
	 * @return self Chained promise that preserves the original settlement unless cleanup throws.
	 */
	public function finally(callable $on_finally): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return $this->then(
			function($value)use($on_finally){
				$on_finally();
				return $value;
			},
			function($reason)use($on_finally){
				$on_finally();
				throw $reason;
			}
		);
	}

	/**
	 * Cancels handler delivery and invokes registered cancellation hooks.
	 *
	 * Cancellation sets an advisory flag, runs callbacks registered through on_cancel(), and then invokes the constructor-level on-cancel hook. It does not change state to rejected or fulfilled, and it does not guarantee the executor or external work has stopped.
	 *
	 * @return void Cancellation state and callbacks are updated in place.
	 */
	public function cancel(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->is_cancelled=true;
		foreach($this->cancel_callbacks as $callback){
			call_user_func($callback);
		}
		if($this->on_cancel){
			call_user_func($this->on_cancel);
		}
	}
	
	/**
	 * Registers a callback that runs when cancel() is invoked.
	 *
	 * Callbacks are stored in registration order and are not invoked immediately when registering on an already cancelled promise. The same promise is returned so cancellation hooks can be composed with chains.
	 *
	 * @param callable $callback Cancellation callback.
	 * @return self Same promise for fluent configuration.
	 */
	public function on_cancel(callable $callback): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->cancel_callbacks[]=$callback;
		return $this;
	}

	/**
	 * Reads the current settlement state.
	 *
	 * @return string One of pending, fulfilled, or rejected.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Indicates whether the promise has left the pending state.
	 *
	 * Cancellation alone does not count as settlement because cancel() does not change the stored state.
	 *
	 * @return bool True when state() is fulfilled or rejected.
	 */
	public function settled(): bool {
		return $this->state!=='pending';
	}

	/**
	 * Reads the stored fulfillment value or rejection reason.
	 *
	 * The same slot is used for both fulfillment values and rejection reasons. Callers should inspect state() or settled() before interpreting the value.
	 *
	 * @return mixed Fulfillment value, rejection reason, or null before settlement when no value has been stored.
	 */
	public function value(): mixed {
		return $this->value;
	}

	/**
	 * Indicates whether cancel() has been invoked.
	 *
	 *
	 * @return bool True after cancellation has been requested.
	 */
	public function is_cancelled(): bool {
		return $this->is_cancelled;
	}

	/**
	 * Queues or dispatches a pair of settlement handlers for this promise.
	 *
	 * Pending promises store handlers until resolve() or reject() runs. Settled promises dispatch the matching handler immediately unless cancellation has been requested, in which case handler delivery is suppressed.
	 *
	 * @param callable $on_fulfilled Handler invoked with the fulfillment value.
	 * @param callable $on_rejected Handler invoked with the rejection reason.
	 * @return void Handler state is queued or invoked in place.
	 */
	private function handle(callable $on_fulfilled, callable $on_rejected): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if($this->is_cancelled){
			return;
		}
		if($this->state==='pending'){
			$this->handlers[]=compact('on_fulfilled', 'on_rejected');
			return;
		}
		if($this->state==='fulfilled'){
			$on_fulfilled($this->value);
		}
		if($this->state==='rejected'){
			$on_rejected($this->value);
		}
	}

	/**
	 * Fulfills the promise once or adopts another promise's eventual state.
	 *
	 * Settlement is ignored after the first fulfillment or rejection. When the value is another promise, this promise subscribes to that promise and resolves or rejects from the nested outcome instead of storing the promise object directly.
	 *
	 * @param mixed $value Fulfillment value or nested promise to assimilate.
	 * @return void Promise state, stored value, and queued handlers are updated in place.
	 */
	private function resolve(mixed $value): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if($this->state !== 'pending'){
			return;
		}
		if($value instanceof self){
			$value->then([$this, 'resolve'], [$this, 'reject']);
			return;
		}
		$this->state='fulfilled';
		$this->value=$value;
		foreach($this->handlers as $handler){
			$handler['on_fulfilled']($value);
		}
	}

	/**
	 * Rejects the promise once and dispatches queued rejection handlers.
	 *
	 * Rejection reasons are stored as supplied, usually an exception object or diagnostic string. Additional reject or resolve calls after settlement are ignored so downstream chains observe a single stable failure.
	 *
	 * @param string|object $reason Rejection reason stored for value() and passed to handlers.
	 * @return void Promise state, stored reason, and queued handlers are updated in place.
	 */
	private function reject(string|object $reason): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if($this->state !== 'pending'){
			return;
		}
		$this->state='rejected';
		$this->value=$reason;
		foreach($this->handlers as $handler){
			$handler['on_rejected']($reason);
		}
	}
	
}
