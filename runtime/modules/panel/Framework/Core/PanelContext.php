<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Maintains scoped Panel runtime configuration.
 *
 * PanelContext stores a stack of temporary configuration frames so nested Panel
 * operations can override behavior for a callback and reliably restore previous
 * context afterward. Lookups search from the newest frame to the oldest.
 */
final class PanelContext {

	/** @var array<int, array<string, mixed>> */
	private static array $stack=[];

	/**
	 * Runs a callback inside a temporary Panel context frame.
	 *
	 * The frame is normalized before being pushed and is always removed in a
	 * finally block, including when the callback throws.
	 *
	 * @param array<string, mixed> $config Context values for the callback.
	 * @param callable $callback Callback to execute inside the context.
	 * @return mixed value produced while the normalized panel context frame is active.
	 */
	public static function run(array $config, callable $callback): mixed {
		self::$stack[]=self::normalize($config);
		try{
			return $callback();
		}
		finally{
			array_pop(self::$stack);
		}
	}

	/**
	 * Reads one context value from the active stack.
	 *
	 * Newer frames override older frames. The default is returned when no frame
	 * contains the key.
	 *
	 * @param string $key Context key.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed value from the nearest active context frame containing the key, or the caller default.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		for($index=count(self::$stack)-1; $index>=0; $index--){
			if(array_key_exists($key, self::$stack[$index])){
				return self::$stack[$index][$key];
			}
		}
		return $default;
	}

	/**
	 * Reports whether a context key exists in any active frame.
	 *
	 * @param string $key Context key.
	 * @return bool True when the key is present in the context stack.
	 */
	public static function has(string $key): bool {
		for($index=count(self::$stack)-1; $index>=0; $index--){
			if(array_key_exists($key, self::$stack[$index])){
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the merged active context.
	 *
	 * Frames are merged oldest to newest so returned values match config()
	 * resolution semantics.
	 *
	 * @return array<string, mixed> Merged active context values.
	 */
	public static function all(): array {
		$config=[];
		foreach(self::$stack as $frame){
			$config=array_replace($config, $frame);
		}
		return $config;
	}

	/**
	 * Normalizes a context frame.
	 *
	 * Empty string keys are discarded; values are preserved unchanged.
	 *
	 * @param array<string|int, mixed> $config Raw context frame.
	 * @return array<string, mixed> Normalized context frame.
	 */
	private static function normalize(array $config): array {
		$normalized=[];
		foreach($config as $key=>$value){
			$key=trim((string)$key);
			if($key!==''){
				$normalized[$key]=$value;
			}
		}
		return $normalized;
	}
}
