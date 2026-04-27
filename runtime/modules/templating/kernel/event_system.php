<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

trait event_system {
	
	private static $events=[
		'before_render' => [],
		'after_render' => [],
		'on_error' => []
	];
	
	public static function register_event_hook(string $event, callable $callback): void {
		if(isset(self::$events[$event])){
			self::$events[$event][]=$callback;
		}
	}

	private static function trigger_event(string $event, ...$args): void {
		if(isset(self::$events[$event])){
			foreach(self::$events[$event] as $callback){
				call_user_func($callback, ...$args);
			}
		}
	}
	
    public static function enable_event_system(string $event, array $data): object {
        // Use promise to handle asynchronous event broadcasting
        return new \dataphyre\async\promise(function($resolve, $reject) use($event, $data){
            try {
                \dataphyre\web_socket_server::broadcast($event, json_encode($data));
                $resolve("Event broadcasted: $event");
            } catch(\Exception $e){
                $reject("Failed to broadcast event: ".$e->getMessage());
            }
        });
    }
	
	private static function enable_watch_mode(string $template_file): void {
		// Schedule a coroutine to watch for template file changes
		\dataphyre\async\coroutine::set_interval(function() use($template_file){
			if(self::$is_dev_mode){
				\dataphyre\web_socket_server::broadcast("reload_template", $template_file);
			}
		}, 1000); // Check every second
	}
	
}