<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Static entry point for Dataphyre's named dialback extension points.
 *
 * Dialbacks are runtime callbacks registered against string event names and
 * executed through the core module. This entry point keeps the legacy global registry
 * accessible through typed catalog/event value objects for diagnostics and
 * framework consumers.
 */
final class Dialback {

	/**
	 * Fires a named dialback event through the core runtime.
	 *
	 * Callback execution order, early-return behavior, and side effects are owned
	 * by `dataphyre\core::dialback()`.
	 *
	 * @param string $eventName Dialback event name.
	 * @param mixed ...$data Event arguments forwarded to registered callbacks.
	 * @return mixed value produced by the core dispatcher after registered callbacks run.
	 */
	public static function fire(string $eventName, mixed ...$data): mixed {
		return \dataphyre\core::dialback($eventName, ...$data);
	}

	/**
	 * Registers a callback for a named dialback event.
	 *
	 * @param callable $callback Callback invoked by the core dispatcher.
	 * @return bool True when the core registry accepted the callback.
	 */
	public static function register(string $eventName, callable $callback): bool {
		return \dataphyre\core::register_dialback($eventName, $callback)===true;
	}

	/**
	 * Checks whether an event has at least one registered dialback callback.
	 *
	 * @param string $eventName Event name.
	 * @return bool True when callbacks exist for the event.
	 */
	public static function has(string $eventName): bool {
		return \dataphyre\core::has_dialback($eventName);
	}

	/**
	 * Returns the callbacks registered for one dialback event.
	 *
	 * The callbacks are returned exactly as stored by the core registry so
	 * diagnostics can inspect callable shapes without firing the event.
	 *
	 * @param string $eventName Event name.
	 * @return array<int, callable> Registered callbacks.
	 */
	public static function callbacks(string $eventName): array {
		return \dataphyre\core::dialback_callbacks($eventName);
	}

	/**
	 * Lists registered dialback event names.
	 *
	 * A prefix scopes the catalog before names are returned, allowing module or
	 * feature-specific dialback inspection.
	 * @param ?string $prefix Optional event-name prefix.
	 * @return array<int, string> Registered event names.
	 */
	public static function names(?string $prefix=null): array {
		return static::catalog($prefix)->names();
	}

	/**
	 * Counts registered dialback events.
	 *
	 * @param ?string $prefix Optional event-name prefix.
	 * @return int Number of registered event names.
	 */
	public static function count(?string $prefix=null): int {
		return static::catalog($prefix)->count();
	}

	/**
	 * Counts callbacks registered across matching dialback events.
	 *
	 *
	 * @param ?string $prefix Optional event-name prefix.
	 * @return int Total callback count in the selected catalog.
	 */
	public static function callbackCount(?string $prefix=null): int {
		return static::catalog($prefix)->callbackCount();
	}

	/**
	 * Builds a value object for one dialback event.
	 *
	 * The returned event is a read-only snapshot of callback metadata at the time
	 * this method is called.
	 *
	 * @param string $eventName Event name.
	 * @return DialbackEvent Event snapshot.
	 */
	public static function event(string $eventName): DialbackEvent {
		return DialbackEvent::fromCallbacks($eventName, static::callbacks($eventName));
	}

	/**
	 * Builds a catalog containing a selected list of dialback events.
	 *
	 * Blank names are ignored. Event names are preserved as supplied after
	 * trimming and mapped to their current callback lists.
	 *
	 * @param array<int, string> $eventNames Event names to include.
	 * @return DialbackCatalog Catalog containing only the selected events.
	 */
	public static function events(array $eventNames): DialbackCatalog {
		$selected=[];
		foreach($eventNames as $eventName){
			$eventName=trim((string)$eventName);
			if($eventName===''){
				continue;
			}
			$selected[$eventName]=static::callbacks($eventName);
		}
		return new DialbackCatalog(null, $selected);
	}

	/**
	 * Returns a catalog snapshot of all registered dialbacks.
	 *
	 * @param ?string $prefix Optional event-name prefix used to scope the catalog.
	 * @return DialbackCatalog Catalog snapshot for diagnostics and runtime callers.
	 */
	public static function catalog(?string $prefix=null): DialbackCatalog {
		$events=\dataphyre\core::dialback_all();
		$catalog=new DialbackCatalog(null, $events);
		return $prefix!==null ? $catalog->scope($prefix) : $catalog;
	}
}
