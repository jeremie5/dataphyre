<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Adds global configurators and runtime macros to Panel definition objects.
 *
 * PanelExtensible is mixed into immutable-style Panel builders so packages and
 * applications can register cross-cutting defaults without subclassing every
 * value object. Its registries are static per consuming class, making the
 * lifecycle process-wide until tests or bootstraps explicitly flush them.
 */
trait PanelExtensible {

	/** @var array<int, callable> Configurators applied before important configurators. */
	private static array $panelConfigurators=[];

	/** @var array<int, callable> Late configurators that can override normal defaults. */
	private static array $panelImportantConfigurators=[];

	/** @var array<string, callable> Normalized macro names mapped to dynamic callables. */
	private static array $panelMacros=[];

	/**
	 * Registers a configurator for new instances of the consuming class.
	 *
	 * Configurators receive the current instance and may either mutate it,
	 * return a replacement instance, or return anything else to leave the
	 * current object unchanged. Important configurators run after normal ones,
	 * which lets application boot code override package defaults.
	 *
	 * @param callable $callback Configurator invoked by configured().
	 * @param bool $important Whether to run after normal configurators.
	 * @return void
	 */
	public static function configureUsing(callable $callback, bool $important=false): void {
		if($important){
			self::$panelImportantConfigurators[]=$callback;
			return;
		}
		self::$panelConfigurators[]=$callback;
	}

	/**
	 * Clears all registered configurators for the consuming class.
	 *
	 * This is primarily a test and hot-reload helper. Calling it during normal
	 * request handling removes package/application defaults for subsequently
	 * created Panel definition objects.
	 *
	 * @return void
	 */
	public static function flushConfigurators(): void {
		self::$panelConfigurators=[];
		self::$panelImportantConfigurators=[];
	}

	/**
	 * Registers a dynamic instance or static method.
	 *
	 * Macro names are normalized with Resource naming rules, so callers can use
	 * snake_case, kebab-case, or spaced labels consistently. Empty names are
	 * ignored instead of creating an unreachable macro slot.
	 *
	 * @param string $name Public macro name to expose through __call or __callStatic.
	 * @param callable $macro Macro callable.
	 * @return void
	 */
	public static function macro(string $name, callable $macro): void {
		$name=Resource::normalizeName($name);
		if($name!==''){
			self::$panelMacros[$name]=$macro;
		}
	}

	/**
	 * Reports whether a macro is registered for the normalized name.
	 *
	 * @param string $name Macro name to check.
	 * @return bool True when a dynamic method is available.
	 */
	public static function hasMacro(string $name): bool {
		return isset(self::$panelMacros[Resource::normalizeName($name)]);
	}

	/**
	 * Clears all registered macros for the consuming class.
	 *
	 * Use this to isolate tests or rebuild package state. Existing object
	 * instances keep their normal properties, but dynamic methods disappear
	 * immediately because dispatch consults the static registry at call time.
	 *
	 * @return void
	 */
	public static function flushMacros(): void {
		self::$panelMacros=[];
	}

	/**
	 * Dispatches a registered instance macro.
	 *
	 * Closure macros are rebound to the current instance so they can participate
	 * in fluent builder APIs. Non-closure callables receive the instance as the
	 * first argument, followed by the user-supplied arguments.
	 *
	 * @param string $name Requested dynamic method name.
	 * @param array<int, mixed> $arguments Arguments supplied by the caller.
	 * @return mixed value produced by the macro after instance binding or instance injection.
	 * @throws \BadMethodCallException When the macro is not registered.
	 */
	public function __call(string $name, array $arguments): mixed {
		$name=Resource::normalizeName($name);
		if(isset(self::$panelMacros[$name])){
			$macro=self::$panelMacros[$name];
			if($macro instanceof \Closure){
				return $macro->call($this, ...$arguments);
			}
			return $macro($this, ...$arguments);
		}
		throw new \BadMethodCallException('Panel method '.static::class.'::'.$name.'() is not registered.');
	}

	/**
	 * Dispatches a registered static macro.
	 *
	 * Static macro calls do not receive an instance. They are best suited for
	 * factories, shared options, or package helpers that operate outside one
	 * specific Panel definition object.
	 *
	 * @param string $name Requested dynamic static method name.
	 * @param array<int, mixed> $arguments Arguments supplied by the caller.
	 * @return mixed value produced by the registered static macro callable.
	 * @throws \BadMethodCallException When the macro is not registered.
	 */
	public static function __callStatic(string $name, array $arguments): mixed {
		$name=Resource::normalizeName($name);
		if(isset(self::$panelMacros[$name])){
			return self::$panelMacros[$name](...$arguments);
		}
		throw new \BadMethodCallException('Panel static method '.static::class.'::'.$name.'() is not registered.');
	}

	/**
	 * Applies registered configurators to a newly-created instance.
	 *
	 * Consuming classes call this from their public factory methods after the
	 * base object has been constructed. Returning an instance from a configurator
	 * replaces the working value, which supports immutable builder patterns.
	 *
	 * @param self $instance Newly-created Panel builder/value object.
	 * @return self Configured instance after normal and important hooks run.
	 */
	protected static function configured(self $instance): self {
		foreach(array_merge(self::$panelConfigurators, self::$panelImportantConfigurators) as $configurator){
			$result=$configurator($instance);
			if($result instanceof self){
				$instance=$result;
			}
		}
		return $instance;
	}
}
