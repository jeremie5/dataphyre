<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Adds template render hooks and development-time event broadcasting.
 *
 * The trait stores process-local hook callbacks for render lifecycle events and
 * exposes asynchronous websocket broadcasting helpers used by development
 * watch mode. Hook callbacks execute in registration order and are not isolated;
 * exceptions thrown by a callback propagate to the caller that triggered the
 * lifecycle event.
 */
trait event_system {

	private static $events=[
		'before_render' => [],
		'after_render' => [],
		'on_error' => []
	];

	/**
	 * Registers a callback for a known template lifecycle event.
	 *
	 * Unknown event names are ignored so callers can safely probe optional hook
	 * support without mutating the event map.
	 *
	 * @param string $event Hook name such as before_render, after_render, or on_error.
	 * @param callable $callback Callback invoked with lifecycle event arguments.
	 * @return void
	 */
	public static function register_event_hook(string $event, callable $callback): void {
		if(isset(self::$events[$event])){
			self::$events[$event][]=$callback;
		}
	}

	/**
	 * Invokes callbacks registered for a template lifecycle event.
	 *
	 * Unknown events are ignored. Registered callbacks receive the arguments
	 * exactly as supplied by the renderer, so hook authors own argument validation
	 * and side effects.
	 *
	 * @param string $event Hook name to trigger.
	 * @param mixed ...$args Arguments forwarded to each callback.
	 * @return void
	 */
	private static function trigger_event(string $event, ...$args): void {
		if(isset(self::$events[$event])){
			foreach(self::$events[$event] as $callback){
				call_user_func($callback, ...$args);
			}
		}
	}

    /**
     * Broadcasts a template event through the websocket server asynchronously.
     *
     * The returned promise resolves with a status string after broadcast, or
     * rejects with the websocket exception message when delivery fails. Event
     * data is JSON-encoded as provided; encoding failures are not transformed
     * into template errors by this helper.
     *
     * @param string $event Websocket event name.
     * @param array<string,mixed> $data Event data forwarded to websocket subscribers.
     * @return object Async promise for the broadcast attempt.
     */
    public static function enable_event_system(string $event, array $data): object {
        return new \dataphyre\async\promise(function($resolve, $reject) use($event, $data){
            try {
                \dataphyre\web_socket_server::broadcast($event, json_encode($data));
                $resolve("Event broadcasted: $event");
            } catch(\Exception $e){
                $reject("Failed to broadcast event: ".$e->getMessage());
            }
        });
    }

	/**
	 * Enables development watch broadcasts for a template file.
	 *
	 * A coroutine periodically announces reload_template while development mode
	 * remains active on the consuming templating class. The interval is process
	 * local and continues until the async runtime or owning process stops it.
	 *
	 * @param string $template_file Template path being watched.
	 * @return void
	 */
	private static function enable_watch_mode(string $template_file): void {
		\dataphyre\async\coroutine::set_interval(function() use($template_file){
			if(self::$is_dev_mode){
				\dataphyre\web_socket_server::broadcast("reload_template", $template_file);
			}
		}, 1000);
	}

}
