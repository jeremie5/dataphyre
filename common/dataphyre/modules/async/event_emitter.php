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


namespace dataphyre\async;

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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset($this->listeners[$event])){
			$this->listeners[$event]=[];
		}
		$this->listeners[$event][]=['listener'=>$listener, 'priority'=>$priority, 'group'=>$group];
		usort($this->listeners[$event], function($a, $b){
			return $b['priority'] <=> $a['priority'];
		});
		if($group){
			if(!isset($this->listener_groups[$group])){
				$this->listener_groups[$group]=[];
			}
			$this->listener_groups[$group][]=$listener;
		}
		if(count($this->listeners[$event]) > $this->max_listeners){
			array_pop($this->listeners[$event]);
			// Optionally log or throw an exception ifmax listeners exceeded
		}
	}

	public function emit(string $event, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset($this->propagation_stopped[$event]) && $this->propagation_stopped[$event]){
			return;
		}
		$events_to_emit=array_merge([$event], $this->event_aliases[$event]??[]);
		foreach($events_to_emit as $e){
			$matched_listeners=$this->match_event($e);
			foreach($matched_listeners as $listener_data){
				try{
					if(isset($this->payload_transformers[$e])){
						$args=$this->payload_transformers[$e](...$args);
					}
					if($this->async_mode){
						$this->handle_async($listener_data['listener'], ...$args);
					}
					else
					{
						$listener_data['listener'](...$args);
					}
				}catch(\Exception $ex){
					$this->handle_error($ex);
				}
			}
		}
		foreach($this->default_listeners as $listener){
			$listener(...$args);
		}
		if($this->logging_enabled && $this->logger){
			$this->logger->log("Event emitted: $event", $args);
		}
	}

	public function remove_listener(string $event, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset($this->listeners[$event])){
			$this->listeners[$event]=array_filter($this->listeners[$event], function($l)use($listener){
				return $l['listener'] !== $listener;
			});
		}
	}

	public function once(string $event, callable $listener, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$wrapper=null;
		$wrapper=function(...$args)use($event, $listener, &$wrapper){
			$this->remove_listener($event, $wrapper);
			$listener(...$args);
		};
		$this->on($event, $wrapper, $priority);
	}

	public function set_max_listeners(int $max_listeners): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->max_listeners=$max_listeners;
	}

	public function get_listener_count(string $event): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return isset($this->listeners[$event]) ? count($this->listeners[$event]) : 0;
	}

	public function remove_all_listeners(string $event=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($event){
			unset($this->listeners[$event]);
		}
		else
		{
			$this->listeners=[];
		}
	}

	public function set_default_listener(callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->default_listeners[]=$listener;
	}

	public function set_event_alias(string $event, string $alias): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset($this->event_aliases[$event])){
			$this->event_aliases[$event]=[];
		}
		$this->event_aliases[$event][]=$alias;
	}

	public function enable_logging(callable $logger): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->logging_enabled=true;
		$this->logger=$logger;
	}

	public function disable_logging(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->logging_enabled=false;
		$this->logger=null;
	}

	public function inspect_listeners(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return $this->listeners[$event]??[];
	}

	private function handle_error(\Exception $ex): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($this->logging_enabled && $this->logger){
			$this->logger->log("Error during event handling: ".$ex->getMessage());
		}
	}

	public function throttle(string $event, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$last_emit_time=0;
		$this->on($event, function(...$args)use($event, &$last_emit_time, $interval){
			$current_time=microtime(true);
			if($current_time - $last_emit_time >= $interval){
				$last_emit_time=$current_time;
				$this->emit($event, ...$args);
			}
		});
	}

	public function debounce(string $event, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$timeout=null;
		$this->on($event, function(...$args)use($event, &$timeout, $interval){
			if($timeout){
				clear_timeout($timeout);
			}
			$timeout=set_timeout(function()use($event, $args){
				$this->emit($event, ...$args);
			}, $interval);
		});
	}

	public function set_payload_transformer(string $event, callable $transformer): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->payload_transformers[$event]=$transformer;
	}

	public function enable_async_mode(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->async_mode=true;
	}

	public function disable_async_mode(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->async_mode=false;
	}

	private function handle_async(callable $listener, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		// Example implementation using a promise-based approach
		new \dataphyre\async\promise(function($resolve)use($listener, $args){
			$listener(...$args);
			$resolve();
		});
	}

	public function get_group_listeners(string $group): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return $this->listener_groups[$group]??[];
	}

	public function remove_group_listeners(string $group): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset($this->listener_groups[$group])){
			foreach($this->listener_groups[$group] as $listener){
				foreach($this->listeners as $event=>$listeners){
					$this->listeners[$event]=array_filter($listeners, function($l)use($listener){
						return $l['listener'] !== $listener;
					});
				}
			}
			unset($this->listener_groups[$group]);
		}
	}

	public function stop_propagation(string $event): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->propagation_stopped[$event]=true;
	}

	public function continue_propagation(string $event): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->propagation_stopped[$event]=false;
	}

	private function match_event(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$matched_listeners=[];
		foreach($this->listeners as $registered_event=>$listeners){
			if(fnmatch($registered_event, $event)){
				$matched_listeners=array_merge($matched_listeners, $listeners);
			}
		}
		return $matched_listeners;
	}

	public function add_wildcard_listener(string $pattern, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->wildcard_handlers[$pattern]=$listener;
	}

	public function emit_to_namespace(string $namespace, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		foreach($this->namespace_handlers as $namespace_key=>$handlers){
			if(strpos($namespace_key, $namespace)===0){
				foreach($handlers as $handler){
					$handler(...$args);
				}
			}
		}
	}

	public function on_namespace(string $namespace, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset($this->namespace_handlers[$namespace])){
			$this->namespace_handlers[$namespace]=[];
		}
		$this->namespace_handlers[$namespace][]=$listener;
	}

	public function add_listener_with_metadata(string $event, callable $listener, array $metadata): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset($this->listeners[$event])){
			$this->listeners[$event]=[];
		}
		$this->listeners[$event][]=['listener'=>$listener, 'metadata'=>$metadata];
	}

	public function get_listener_metadata(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$metadata=[];
		foreach($this->listeners[$event] as $listener_data){
			$metadata[]=$listener_data['metadata'];
		}
		return $metadata;
	}

	public function add_conditional_listener(string $event, callable $listener, callable $condition): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$this->on($event, function(...$args)use($listener, $condition){
			if($condition(...$args)){
				$listener(...$args);
			}
		});
	}

	public function intercept_event(string $event, callable $interceptor): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset($this->listeners[$event])){
			foreach($this->listeners[$event] as &$listener_data){
				$original_listener=$listener_data['listener'];
				$listener_data['listener']=function(...$args)use($original_listener, $interceptor){
					$interceptor($original_listener, ...$args);
				};
			}
		}
	}
	
}