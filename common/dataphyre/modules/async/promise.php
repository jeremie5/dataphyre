<?php
/*************************************************************************
*  Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, ifany. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

namespace dataphyre\async;

class promise {
	
	private $state='pending';
	private $value;
	private $handlers=[];
	private $on_cancel;
	private $is_cancelled=false;
	private $cancel_callbacks=[];

	public function __construct(callable $executor, callable $on_cancel=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function all(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return new self(function($resolve, $reject)use($promises){
			$results=[];
			$remaining=count($promises);

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
	
	public static function retry(callable $task, int $retries, int $delay=0): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function race(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return new self(function($resolve, $reject)use($promises){
			foreach($promises as $promise){
				$promise->then($resolve, $reject);
			}
		});
	}

	public static function allSettled(array $promises): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return new self(function($resolve)use($promises){
			$results=[];
			$remaining=count($promises);
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

	public static function with_timeout(callable $executor, int $timeout): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return new self(function($resolve, $reject)use($executor, $timeout){
			$timer=set_timeout(function()use($reject){
				$reject(new \Exception("Promise timed out"));
			}, $timeout);
			$executor(
				function($value)use($resolve, $timer){
					clear_timeout($timer);
					$resolve($value);
				},
				function($reason)use($reject, $timer){
					clear_timeout($timer);
					$reject($reason);
				}
			);
		});
	}

	public function then(?callable $on_fulfilled=null, ?callable $on_rejected=null): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public function catch(callable $on_rejected): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return $this->then(null, $on_rejected);
	}

	public function finally(callable $on_finally): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public function cancel(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->is_cancelled=true;
		foreach($this->cancel_callbacks as $callback){
			call_user_func($callback);
		}
		if($this->on_cancel){
			call_user_func($this->on_cancel);
		}
	}
	
	public function on_cancel(callable $callback): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->cancel_callbacks[]=$callback;
		return $this;
	}

	private function handle(callable $on_fulfilled, callable $on_rejected): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	private function resolve(mixed $value): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	private function reject(string|object $reason): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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