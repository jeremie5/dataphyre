<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Session helper for controller state, flash data, validation errors, and CSRF.
 *
 * Session wraps native PHP session storage when available and falls back to an
 * in-memory store for CLI/tests. It provides dot-path reads and writes, one-cycle
 * flash semantics, old input, error bags, counters, arrays, and token rotation.
 */
final class Session {

	private static array $fallback=[];
	private static bool $started=false;

	/**
	 * Starts session storage when needed and initializes flash bookkeeping.
	 *
	 * Web requests start PHP's native session when headers are still writable. CLI and
	 * unavailable native sessions use the in-memory fallback store.
	 *
	 * @return void
	 */
	public static function start(): void {
		if(self::$started){
			return;
		}
		if(PHP_SAPI!=='cli' && session_status()===PHP_SESSION_NONE && headers_sent()===false){
			session_start();
		}
		self::$started=true;
		$store=&self::store();
		$store['_flash_old'] ??=[];
		$store['_flash_new'] ??=[];
	}

	/**
	 * Reads a session value using literal keys or dot notation.
	 *
	 * @param string $key Session key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Session value at the literal key or dot path, or the supplied default when absent.
	 */
	public static function get(string $key, mixed $default=null): mixed {
		self::start();
		$store=&self::store();
		return self::dataGet($store, $key, $default);
	}

	/**
	 * Stores a session value using literal keys or dot notation.
	 *
	 * Missing intermediate arrays are created for dot-path writes.
	 *
	 * @param string $key Session key or dot path.
	 * @param mixed $value Value to store.
	 * @return void
	 */
	public static function put(string $key, mixed $value): void {
		self::start();
		$store=&self::store();
		self::dataSet($store, $key, $value);
	}

	/**
	 * Checks whether a session key exists.
	 *
	 * @param string $key Session key or dot path.
	 * @return bool True when the literal key or nested path exists.
	 */
	public static function has(string $key): bool {
		self::start();
		$store=&self::store();
		return self::dataHas($store, $key);
	}

	/**
	 * Removes a session value using literal keys or dot notation.
	 *
	 * @param string $key Session key or dot path to remove.
	 * @return void
	 */
	public static function forget(string $key): void {
		self::start();
		$store=&self::store();
		self::dataForget($store, $key);
	}

	/**
	 * Reads a session value and removes it in the same operation.
	 *
	 * @param string $key Session key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Removed session value, or the supplied default when the key was absent.
	 */
	public static function pull(string $key, mixed $default=null): mixed {
		self::start();
		$store=&self::store();
		$value=self::dataGet($store, $key, $default);
		self::dataForget($store, $key);
		return $value;
	}

	/**
	 * Returns a stored value or computes and stores it once.
	 *
	 * @param string $key Session key or dot path.
	 * @param callable $callback Callback used to produce the value when absent.
	 * @return mixed Existing session value, or the callback result after it has been stored.
	 */
	public static function remember(string $key, callable $callback): mixed {
		self::start();
		$store=&self::store();
		if(self::dataHas($store, $key)){
			return self::dataGet($store, $key);
		}
		$value=$callback();
		self::dataSet($store, $key, $value);
		return $value;
	}

	/**
	 * Increments a numeric session value.
	 *
	 * Missing and non-numeric values are treated as zero before applying the amount.
	 *
	 * @param string $key Session key or dot path.
	 * @param int|float $amount Amount to add.
	 * @return int|float Updated numeric value.
	 */
	public static function increment(string $key, int|float $amount=1): int|float {
		self::start();
		$store=&self::store();
		$current=self::dataGet($store, $key, 0);
		$current=is_numeric($current) ? $current + 0 : 0;
		$value=$current+$amount;
		self::dataSet($store, $key, $value);
		return $value;
	}

	/**
	 * Decrements a numeric session value.
	 *
	 * @param string $key Session key or dot path.
	 * @param int|float $amount Amount to subtract.
	 * @return int|float Updated numeric value.
	 */
	public static function decrement(string $key, int|float $amount=1): int|float {
		return self::increment($key, -$amount);
	}

	/**
	 * Appends a value to an array stored in session.
	 *
	 * Existing non-array values are replaced with a new array containing the appended
	 * value.
	 *
	 * @param string $key Session key or dot path.
	 * @param mixed $value Value to append.
	 * @return array<int, mixed> Updated array value.
	 */
	public static function push(string $key, mixed $value): array {
		self::start();
		$store=&self::store();
		$current=self::dataGet($store, $key, []);
		$current=is_array($current) ? $current : [];
		$current[]=$value;
		self::dataSet($store, $key, $current);
		return $current;
	}

	/**
	 * Stores a flash value for the next request cycle.
	 *
	 * The key is written to session and tracked in _flash_new so ageFlash() can expire
	 * it after it has been available for one subsequent request.
	 *
	 * @param string $key Flash key.
	 * @param mixed $value Flash value.
	 * @return void
	 */
	public static function flash(string $key, mixed $value): void {
		self::put($key, $value);
		$store=&self::store();
		$store['_flash_new'][]=$key;
		$store['_flash_new']=array_values(array_unique($store['_flash_new']));
	}

	/**
	 * Reads flashed old input.
	 *
	 * Passing null returns the whole _old_input array; otherwise dot notation can read
	 * a single old input value.
	 *
	 * @param string|null $key Optional old input key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full old input array, one value, or default.
	 */
	public static function old(?string $key=null, mixed $default=null): mixed {
		$old=self::get('_old_input', []);
		if($key===null){
			return is_array($old) ? $old : [];
		}
		return is_array($old) ? self::dataGet($old, $key, $default) : $default;
	}

	/**
	 * Flashes request input for old() lookups on the next request.
	 *
	 * @param array<string, mixed> $input Input values to flash.
	 * @return void
	 */
	public static function flashInput(array $input): void {
		self::flash('_old_input', $input);
	}

	/**
	 * Flashes validation errors into a named error bag.
	 *
	 * Empty bag names normalize to default.
	 *
	 * @param array<string, array<int, string>|string> $errors Validation errors.
	 * @param string $bag Error bag name.
	 * @return void
	 */
	public static function flashErrors(array $errors, string $bag='default'): void {
		$bag=trim($bag);
		if($bag===''){
			$bag='default';
		}
		$current=self::get('_errors', []);
		if(!is_array($current)){
			$current=[];
		}
		$current[$bag]=$errors;
		self::flash('_errors', $current);
	}

	/**
	 * Returns flashed validation errors for one bag.
	 *
	 * @param string $bag Error bag name.
	 * @return array<string, array<int,string>|string> Error messages keyed by field for the named bag.
	 */
	public static function errors(string $bag='default'): array {
		$bag=trim($bag);
		if($bag===''){
			$bag='default';
		}
		$errors=self::get('_errors', []);
		if(!is_array($errors)){
			return [];
		}
		return isset($errors[$bag]) && is_array($errors[$bag]) ? $errors[$bag] : [];
	}

	/**
	 * Returns flashed validation errors for one field.
	 *
	 * @param string $field Field name or dot path.
	 * @param string $bag Error bag name.
	 * @return array<int, string> Field error messages.
	 */
	public static function error(string $field, string $bag='default'): array {
		$errors=self::errors($bag);
		$value=self::dataGet($errors, $field, []);
		return is_array($value) ? $value : [];
	}

	/**
	 * Checks whether a named error bag contains errors.
	 *
	 * @param string $bag Error bag name.
	 * @return bool True when the bag has at least one error entry.
	 */
	public static function hasErrors(string $bag='default'): bool {
		return self::errors($bag)!==[];
	}

	/**
	 * Returns the session CSRF token, creating one when absent.
	 *
	 * @return string 64-character hexadecimal token.
	 */
	public static function token(): string {
		self::start();
		$token=self::get('_token');
		if(!is_string($token) || $token===''){
			$token=bin2hex(random_bytes(32));
			self::put('_token', $token);
		}
		return $token;
	}

	/**
	 * Replaces the session CSRF token.
	 *
	 * @return string Newly generated 64-character hexadecimal token.
	 */
	public static function regenerateToken(): string {
		$token=bin2hex(random_bytes(32));
		self::put('_token', $token);
		return $token;
	}

	/**
	 * Ages flash data and removes entries from the previous flash cycle.
	 *
	 * New flash keys become old flash keys; old flash keys are removed from the store.
	 * This is intended to run once per request lifecycle.
	 *
	 * @return void
	 */
	public static function ageFlash(): void {
		self::start();
		$store=&self::store();
		foreach((array)($store['_flash_old'] ?? []) as $key){
			unset($store[$key]);
		}
		$store['_flash_old']=array_values(array_unique((array)($store['_flash_new'] ?? [])));
		$store['_flash_new']=[];
	}

	/**
	 * Clears all session data tracked by this wrapper.
	 *
	 * Active native PHP sessions are emptied and the CLI/fallback store is reset.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if(PHP_SAPI!=='cli' && session_status()===PHP_SESSION_ACTIVE){
			$_SESSION=[];
		}
		self::$fallback=[];
		self::$started=false;
	}

	/**
	 * Returns the active mutable session store by reference.
	 *
	 * Native $_SESSION is used when available; otherwise the static fallback array is
	 * returned for CLI/tests or requests without an active PHP session.
	 *
	 * @return array<string, mixed> Mutable session store.
	 */
	private static function &store(): array {
		if(PHP_SAPI!=='cli' && session_status()===PHP_SESSION_ACTIVE){
			return $_SESSION;
		}
		return self::$fallback;
	}

	/**
	 * Reads an array value using literal keys or dot notation.
	 *
	 * Literal key matches win before dot traversal so keys containing dots remain
	 * addressable.
	 *
	 * @param array<string, mixed> $data Source array.
	 * @param string $key Literal key or dot path.
	 * @param mixed $default Value returned when absent.
	 * @return mixed Array value at the literal key or dot path, or the supplied default when absent.
	 */
	private static function dataGet(array $data, string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $data)){
			return $data[$key];
		}
		if(!str_contains($key, '.')){
			return $default;
		}
		$current=$data;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	/**
	 * Checks array key presence using literal keys or dot notation.
	 *
	 * @param array<string, mixed> $data Source array.
	 * @param string $key Literal key or dot path.
	 * @return bool True when the key exists.
	 */
	private static function dataHas(array $data, string $key): bool {
		if(array_key_exists($key, $data)){
			return true;
		}
		if(!str_contains($key, '.')){
			return false;
		}
		$current=$data;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return false;
			}
			$current=$current[$segment];
		}
		return true;
	}

	/**
	 * Sets an array value using literal keys or dot notation.
	 *
	 * @param array<string, mixed> $data Target array passed by reference.
	 * @param string $key Literal key or dot path.
	 * @param mixed $value Value to store.
	 * @return void
	 */
	private static function dataSet(array &$data, string $key, mixed $value): void {
		if(!str_contains($key, '.')){
			$data[$key]=$value;
			return;
		}
		$current=&$data;
		foreach(explode('.', $key) as $segment){
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
		$current=$value;
	}

	/**
	 * Removes an array value using literal keys or dot notation.
	 *
	 * @param array<string, mixed> $data Target array passed by reference.
	 * @param string $key Literal key or dot path.
	 * @return void
	 */
	private static function dataForget(array &$data, string $key): void {
		if(array_key_exists($key, $data)){
			unset($data[$key]);
			return;
		}
		if(!str_contains($key, '.')){
			return;
		}
		$current=&$data;
		$segments=explode('.', $key);
		$lastIndex=count($segments) - 1;
		foreach($segments as $index=>$segment){
			if($index===$lastIndex){
				if(is_array($current)){
					unset($current[$segment]);
				}
				return;
			}
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return;
			}
			$current=&$current[$segment];
		}
	}
}
