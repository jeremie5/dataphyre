<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Dialback {

	public static function fire(string $event_name, mixed ...$data): mixed {
		return \dataphyre\core::dialback($event_name, ...$data);
	}

	public static function register(string $event_name, callable $callback): bool {
		return \dataphyre\core::register_dialback($event_name, $callback)===true;
	}

	public static function has(string $event_name): bool {
		return \dataphyre\core::has_dialback($event_name);
	}

	public static function callbacks(string $event_name): array {
		return \dataphyre\core::dialback_callbacks($event_name);
	}

	public static function names(?string $prefix=null): array {
		return static::catalog($prefix)->names();
	}

	public static function count(?string $prefix=null): int {
		return static::catalog($prefix)->count();
	}

	public static function callbackCount(?string $prefix=null): int {
		return static::catalog($prefix)->callbackCount();
	}

	public static function event(string $event_name): DialbackEvent {
		return DialbackEvent::fromCallbacks($event_name, static::callbacks($event_name));
	}

	public static function events(array $event_names): DialbackCatalog {
		$selected=[];
		foreach($event_names as $event_name){
			$event_name=trim((string)$event_name);
			if($event_name===''){
				continue;
			}
			$selected[$event_name]=static::callbacks($event_name);
		}
		return new DialbackCatalog(null, $selected);
	}

	public static function catalog(?string $prefix=null): DialbackCatalog {
		$events=\dataphyre\core::dialback_all();
		$catalog=new DialbackCatalog(null, $events);
		return $prefix!==null ? $catalog->scope($prefix) : $catalog;
	}
}
