<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\async;

/**
 * In-process event dispatcher with priority, groups, aliases, and async bridges.
 *
 * The emitter keeps listener state on the instance and supports direct events,
 * fnmatch-style listener keys, default listeners, aliases, namespace handlers,
 * metadata records, event-argument transformers, propagation stop flags, and optional
 * promise-backed listener execution. It is intentionally small and mutable for
 * kernel callers that need a lightweight coordination primitive.
 */
class event_emitter {
	
	private $listeners=[];
	private $max_listeners=10;
	private $default_listeners=[];
	private $listener_groups=[];
	private $event_aliases=[];
	private $logging_enabled=false;
	private $logger;
	private $argument_transformers=[];
	private $async_mode=false;
	private $wildcard_handlers=[];
	private $namespace_handlers=[];
	private $propagation_stopped=[];

	/**
	 * Registers a listener for an event with optional priority and group metadata.
	 *
	 * Listeners are sorted from highest priority to lowest after registration. When
	 * a group is provided, the listener is also tracked in the group index for bulk
	 * inspection and removal. If the event exceeds max_listeners, the lowest-priority
	 * listener is discarded.
	 *
	 * @param string $event Event name or fnmatch-compatible pattern.
	 * @param callable $listener Listener invoked with emitted event arguments.
	 * @param int $priority Higher values run earlier for the same event.
	 * @param string|null $group Optional group name for bulk management.
	 * @return void
	 */
	public function on(string $event, callable $listener, int $priority=0, ?string $group=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

	/**
	 * Emits an event to matching listeners, aliases, and default listeners.
	 *
	 * Emission is skipped when propagation is stopped for the event. Registered
	 * aliases are emitted after the original event name, event-argument transformers
	 * may rewrite arguments per emitted name, and async mode wraps listener execution
	 * in promises. Listener `Exception` instances are swallowed into handle_error();
	 * other throwables are not contained here.
	 *
	 * @param string $event Event name to dispatch.
	 * @param mixed ...$args Event arguments passed to listeners.
	 * @return void
	 */
	public function emit(string $event, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(isset($this->propagation_stopped[$event]) && $this->propagation_stopped[$event]){
			return;
		}
		$events_to_emit=array_merge([$event], $this->event_aliases[$event]??[]);
		foreach($events_to_emit as $e){
			$matched_listeners=$this->match_event($e);
			foreach($matched_listeners as $listener_data){
				try{
					if(isset($this->argument_transformers[$e])){
						$args=$this->argument_transformers[$e](...$args);
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

	/**
	 * Removes a specific listener from one event.
	 *
	 * Listener comparison is strict, so wrapped once(), conditional, or intercepted
	 * listeners must be removed using the stored wrapper callable rather than the
	 * original callable.
	 *
	 * @param string $event Event name to modify.
	 * @param callable $listener Exact listener callable to remove.
	 * @return void
	 */
	public function remove_listener(string $event, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(isset($this->listeners[$event])){
			$this->listeners[$event]=array_filter($this->listeners[$event], function($l)use($listener){
				return $l['listener'] !== $listener;
			});
		}
	}

	/**
	 * Registers a listener that removes itself before its first invocation.
	 *
	 * The generated wrapper is registered through on(), so priority ordering and the
	 * max listener cap are applied exactly like a normal listener.
	 *
	 * @param string $event Event name to observe once.
	 * @param callable $listener Listener invoked for the first emission only.
	 * @param int $priority Higher values run earlier for the same event.
	 * @return void
	 */
	public function once(string $event, callable $listener, int $priority=0): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$wrapper=null;
		$wrapper=function(...$args)use($event, $listener, &$wrapper){
			$this->remove_listener($event, $wrapper);
			$listener(...$args);
		};
		$this->on($event, $wrapper, $priority);
	}

	/**
	 * Sets the maximum retained listener count per event.
	 *
	 * The limit is enforced during future on() calls by discarding the final listener
	 * after priority sorting. Existing listener arrays are not trimmed immediately.
	 *
	 * @param int $max_listeners Maximum listeners retained for each event.
	 * @return void
	 */
	public function set_max_listeners(int $max_listeners): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->max_listeners=$max_listeners;
	}

	/**
	 * Counts listeners registered directly under an event name.
	 *
	 * Wildcard, namespace, and default listeners are not included unless they were
	 * also registered under the exact event key.
	 *
	 * @param string $event Event name to inspect.
	 * @return int Number of listeners stored for the event.
	 */
	public function get_listener_count(string $event): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return isset($this->listeners[$event]) ? count($this->listeners[$event]) : 0;
	}

	/**
	 * Removes listeners for one event or clears the main listener table.
	 *
	 * Passing null clears direct event listeners only; group, namespace, alias,
	 * transformer, and propagation state are left intact.
	 *
	 * @param string|null $event Optional event name to clear.
	 * @return void
	 */
	public function remove_all_listeners(?string $event=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if($event){
			unset($this->listeners[$event]);
		}
		else
		{
			$this->listeners=[];
		}
	}

	/**
	 * Adds a listener that runs after every emitted event.
	 *
	 * Default listeners receive the final event arguments after alias dispatch and
	 * any transformer side effects from the emitted events.
	 *
	 * @param callable $listener Listener invoked after event-specific listeners.
	 * @return void
	 */
	public function set_default_listener(callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->default_listeners[]=$listener;
	}

	/**
	 * Adds an alias event name that is emitted whenever the source event fires.
	 *
	 * Aliases are appended and may each have independent listeners and event-argument
	 * transformers. Alias definitions do not create reverse mappings.
	 *
	 * @param string $event Source event name.
	 * @param string $alias Additional event name emitted with the same event arguments.
	 * @return void
	 */
	public function set_event_alias(string $event, string $alias): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!isset($this->event_aliases[$event])){
			$this->event_aliases[$event]=[];
		}
		$this->event_aliases[$event][]=$alias;
	}

	/**
	 * Enables emitter logging through a logger object or callable-like adapter.
	 *
	 * The implementation expects the logger value to expose log() when events or
	 * listener errors are recorded.
	 *
	 * @param callable $logger Logger object or callable accepted by the kernel API.
	 * @return void
	 */
	public function enable_logging(callable $logger): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->logging_enabled=true;
		$this->logger=$logger;
	}

	/**
	 * Disables event and error logging for this emitter instance.
	 *
	 * The stored logger reference is cleared so future emissions avoid retaining the
	 * previous logger object.
	 *
	 * @return void
	 */
	public function disable_logging(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->logging_enabled=false;
		$this->logger=null;
	}

	/**
	 * Returns the raw listener records stored for an event.
	 *
	 * Each record may contain listener, priority, group, or metadata keys depending
	 * on the registration method used.
	 *
	 * @param string $event Event name to inspect.
	 * @return array<int, array<string, mixed>> Stored listener records.
	 */
	public function inspect_listeners(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return $this->listeners[$event]??[];
	}

	/**
	 * Records listener failures through the configured logger.
	 *
	 * Listener exceptions are intentionally contained by emit() so one failing
	 * subscriber does not stop remaining dispatch work. When logging is disabled,
	 * the failure is swallowed and the emitter preserves legacy best-effort
	 * semantics.
	 *
	 * @param \Exception $ex Listener exception caught during dispatch.
	 * @return void
	 */
	private function handle_error(\Exception $ex): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if($this->logging_enabled && $this->logger){
			$this->logger->log("Error during event handling: ".$ex->getMessage());
		}
	}

	/**
	 * Registers a throttling wrapper for an event.
	 *
	 * The wrapper re-emits the same event only when at least $interval seconds have
	 * elapsed since the last allowed emission. Because it re-emits through emit(),
	 * callers should avoid using this on events where recursive emission is not
	 * intended.
	 *
	 * @param string $event Event name to throttle.
	 * @param int $interval Minimum seconds between forwarded emissions.
	 * @return void
	 */
	public function throttle(string $event, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$last_emit_time=0;
		$this->on($event, function(...$args)use($event, &$last_emit_time, $interval){
			$current_time=microtime(true);
			if($current_time - $last_emit_time >= $interval){
				$last_emit_time=$current_time;
				$this->emit($event, ...$args);
			}
		});
	}

	/**
	 * Registers a debouncing wrapper for an event.
	 *
	 * Each event argument set cancels the prior scheduled timeout and schedules a new
	 * re-emission after the interval. The timeout is managed through dataphyre\async.
	 *
	 * @param string $event Event name to debounce.
	 * @param int $interval Delay in milliseconds after the latest event arguments.
	 * @return void
	 */
	public function debounce(string $event, int $interval): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$timeout=null;
		$this->on($event, function(...$args)use($event, &$timeout, $interval){
			if($timeout){
				\dataphyre\async::cancel($timeout);
			}
			$timeout=\dataphyre\async::set_timeout(function()use($event, $args){
				$this->emit($event, ...$args);
			}, $interval);
		});
	}

	/**
	 * Sets the transformer used to rewrite event arguments for one event.
	 *
	 * During emit(), the transformer receives the current argument list and its
	 * return value replaces the arguments passed to later listeners for that emitted
	 * name. The transformer must return the shape expected by variadic listener
	 * invocation.
	 *
	 * @param string $event Event name whose arguments should be transformed.
	 * @param callable $transformer Callable receiving and returning event arguments.
	 * @return void
	 */
	public function set_payload_transformer(string $event, callable $transformer): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->argument_transformers[$event]=$transformer;
	}

	/**
	 * Enables promise-backed listener dispatch.
	 *
	 * In async mode, each matched listener is passed to handle_async() instead of
	 * being invoked directly. The current implementation creates promises for side
	 * effects and does not collect them.
	 *
	 * @return void
	 */
	public function enable_async_mode(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->async_mode=true;
	}

	/**
	 * Restores direct synchronous listener dispatch.
	 *
	 * Existing listeners and queued promises are not modified.
	 *
	 * @return void
	 */
	public function disable_async_mode(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->async_mode=false;
	}

	/**
	 * Dispatches a listener through the async promise bridge.
	 *
	 * The bridge executes the listener for side effects and resolves immediately
	 * after the callable returns. Promises are not retained by the emitter, so
	 * callers should treat async mode as fire-and-forget dispatch.
	 *
	 * @param callable $listener Listener callable selected by emit().
	 * @param mixed ...$args Event arguments passed to the listener.
	 * @return void
	 */
	private function handle_async(callable $listener, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		// Example implementation using a promise-based approach
		new \dataphyre\async\promise(function($resolve)use($listener, $args){
			$listener(...$args);
			$resolve();
		});
	}

	/**
	 * Returns listeners registered under a group name.
	 *
	 * The returned values are raw callables collected during on(); event names and
	 * listener metadata are not included in the group index.
	 *
	 * @param string $group Group name to inspect.
	 * @return array<int, callable> Listeners associated with the group.
	 */
	public function get_group_listeners(string $group): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		return $this->listener_groups[$group]??[];
	}

	/**
	 * Removes every listener associated with a group from all events.
	 *
	 * The group index is deleted after the listeners are removed from direct event
	 * listener arrays. Namespace and wildcard handlers are not affected.
	 *
	 * @param string $group Group name to remove.
	 * @return void
	 */
	public function remove_group_listeners(string $group): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

	/**
	 * Marks an event as stopped so future emit() calls return immediately.
	 *
	 * Propagation state is tracked by exact event name and does not automatically
	 * apply to aliases or namespaces.
	 *
	 * @param string $event Event name to stop.
	 * @return void
	 */
	public function stop_propagation(string $event): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->propagation_stopped[$event]=true;
	}

	/**
	 * Clears the stopped flag for an event.
	 *
	 * Subsequent emit() calls for the exact event name will dispatch listeners again.
	 *
	 * @param string $event Event name to resume.
	 * @return void
	 */
	public function continue_propagation(string $event): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->propagation_stopped[$event]=false;
	}

	/**
	 * Returns direct listener records whose registered event pattern matches.
	 *
	 * Matching uses fnmatch(), so literal event names and wildcard patterns share
	 * the same storage path. Listener records are returned in their stored order;
	 * on() maintains priority sorting for each registered event bucket.
	 *
	 * @param string $event Emitted event name.
	 * @return array<int, array<string, mixed>> Listener records selected for dispatch.
	 */
	private function match_event(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$matched_listeners=[];
		foreach($this->listeners as $registered_event=>$listeners){
			if(fnmatch($registered_event, $event)){
				$matched_listeners=array_merge($matched_listeners, $listeners);
			}
		}
		return $matched_listeners;
	}

	/**
	 * Stores a wildcard listener outside the main direct listener table.
	 *
	 * This method records pattern handlers for consumers that inspect wildcard state.
	 * The current emit() path matches patterns from the main listener table through
	 * match_event(), so callers that need active dispatch should also use on().
	 *
	 * @param string $pattern Wildcard pattern associated with the listener.
	 * @param callable $listener Listener callable for the pattern.
	 * @return void
	 */
	public function add_wildcard_listener(string $pattern, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->wildcard_handlers[$pattern]=$listener;
	}

	/**
	 * Emits event arguments to namespace handlers whose keys start with a namespace prefix.
	 *
	 * Namespace matching is prefix-based with strpos(..., 0). Handlers are invoked
	 * directly and are independent from normal event aliases and propagation flags.
	 *
	 * @param string $namespace Namespace prefix to dispatch.
	 * @param mixed ...$args Event arguments passed to namespace handlers.
	 * @return void
	 */
	public function emit_to_namespace(string $namespace, ...$args): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		foreach($this->namespace_handlers as $namespace_key=>$handlers){
			if(strpos($namespace_key, $namespace)===0){
				foreach($handlers as $handler){
					$handler(...$args);
				}
			}
		}
	}

	/**
	 * Registers a handler under a namespace key.
	 *
	 * Namespace handlers are dispatched only by emit_to_namespace(), not by emit().
	 * Multiple handlers are retained in registration order.
	 *
	 * @param string $namespace Namespace key or prefix bucket.
	 * @param callable $listener Listener invoked by emit_to_namespace().
	 * @return void
	 */
	public function on_namespace(string $namespace, callable $listener): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!isset($this->namespace_handlers[$namespace])){
			$this->namespace_handlers[$namespace]=[];
		}
		$this->namespace_handlers[$namespace][]=$listener;
	}

	/**
	 * Adds a listener record that carries arbitrary metadata.
	 *
	 * Metadata records are returned by get_listener_metadata(). Because this method
	 * does not set priority or group keys, mixed registration styles can produce
	 * heterogeneous listener records for the same event.
	 *
	 * @param string $event Event name to observe.
	 * @param callable $listener Listener invoked by emit().
	 * @param array<string,mixed> $metadata Metadata stored beside the listener record and returned by get_listener_metadata().
	 * @return void
	 */
	public function add_listener_with_metadata(string $event, callable $listener, array $metadata): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!isset($this->listeners[$event])){
			$this->listeners[$event]=[];
		}
		$this->listeners[$event][]=['listener'=>$listener, 'metadata'=>$metadata];
	}

	/**
	 * Returns metadata stored on listener records for an event.
	 *
	 * The method expects listener records to contain metadata keys, so it is intended
	 * for events populated through add_listener_with_metadata().
	 *
	 * @param string $event Event name to inspect.
	 * @return array<int, mixed> Metadata values stored for the event listeners.
	 */
	public function get_listener_metadata(string $event): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$metadata=[];
		foreach($this->listeners[$event] as $listener_data){
			$metadata[]=$listener_data['metadata'];
		}
		return $metadata;
	}

	/**
	 * Registers a listener wrapper guarded by a runtime condition.
	 *
	 * The condition receives the emitted event arguments. The wrapped listener runs
	 * only when the condition returns a truthy value.
	 *
	 * @param string $event Event name to observe.
	 * @param callable $listener Listener invoked when the condition passes.
	 * @param callable $condition Predicate receiving emitted event arguments.
	 * @return void
	 */
	public function add_conditional_listener(string $event, callable $listener, callable $condition): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$this->on($event, function(...$args)use($listener, $condition){
			if($condition(...$args)){
				$listener(...$args);
			}
		});
	}

	/**
	 * Wraps existing listeners for an event with an interceptor.
	 *
	 * The interceptor receives the original listener as its first argument followed
	 * by the emitted event arguments, allowing instrumentation, authorization, or
	 * argument adaptation around current listeners. Future listeners are not intercepted.
	 *
	 * @param string $event Event name whose current listeners should be wrapped.
	 * @param callable $interceptor Interceptor receiving original listener and event arguments.
	 * @return void
	 */
	public function intercept_event(string $event, callable $interceptor): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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
