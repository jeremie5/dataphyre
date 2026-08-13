<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Adds instance-scoped configurators and runtime macros to Panel definitions.
 *
 * PanelExtensible is mixed into immutable-style Panel builders so packages and
 * applications can register cross-cutting defaults without subclassing every
 * value object. Registration inside a PanelInstance context is owned by that
 * surface. The static maps below are an explicit legacy adapter for calls made
 * outside a surface and can be migrated without breaking existing code.
 */
trait PanelExtensible {

	/** @var list<callable(static):mixed> Configurators applied before important configurators. */
	private static array $panelConfigurators=[];

	/** @var list<callable(static):mixed> Late configurators that can override normal defaults. */
	private static array $panelImportantConfigurators=[];

	/** @var array<string,callable(mixed...):mixed> Normalized macro names mapped to dynamic callables. */
	private static array $panelMacros=[];

	/** Registry that configured this concrete builder instance, if any. */
	private ?PanelInstanceExtensionRegistry $panelExtensionRegistryOwner=null;

	/**
	 * Registers a configurator for new instances of the consuming class.
	 *
	 * Configurators receive the current instance and may either mutate it,
	 * return a replacement instance, or return anything else to leave the
	 * current object unchanged. Important configurators run after normal ones,
	 * which lets application boot code override package defaults.
	 *
	 * @param callable(static):mixed $callback Configurator invoked by configured().
	 * @param bool $important Whether to run after normal configurators.
	 * @return void
	 */
	public static function configureUsing(callable $callback, bool $important=false): void {
		$registry=self::activePanelExtensionRegistry();
		if($registry instanceof PanelInstanceExtensionRegistry){
			$registry->registerConfigurator(static::class, $callback, $important);
			return;
		}
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
		$registry=self::activePanelExtensionRegistry();
		if($registry instanceof PanelInstanceExtensionRegistry){
			$registry->flushConfigurators(static::class);
			return;
		}
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
	 * @param callable(mixed...):mixed $macro Macro callable. Bound closures receive the consuming builder as `$this`; other callables receive it as their first argument.
	 * @return void
	 */
	public static function macro(string $name, callable $macro): void {
		$name=Resource::normalizeName($name);
		if($name===''){
			return;
		}
		$registry=self::activePanelExtensionRegistry();
		if($registry instanceof PanelInstanceExtensionRegistry){
			$registry->registerMacro(static::class, $name, $macro);
			return;
		}
		self::$panelMacros[$name]=$macro;
	}

	/**
	 * Reports whether a macro is registered for the normalized name.
	 *
	 * @param string $name Macro name to check.
	 * @return bool True when a dynamic method is available.
	 */
	public static function hasMacro(string $name): bool {
		$name=Resource::normalizeName($name);
		$registry=self::activePanelExtensionRegistry();
		if($registry instanceof PanelInstanceExtensionRegistry && $registry->hasMacro(static::class, $name)){
			return true;
		}
		if(!$registry instanceof PanelInstanceExtensionRegistry && PanelInstanceExtensionRegistry::uniqueUnscopedMacro(static::class, $name)!==null){
			return true;
		}
		return isset(self::$panelMacros[$name]);
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
		$registry=self::activePanelExtensionRegistry();
		if($registry instanceof PanelInstanceExtensionRegistry){
			$registry->flushMacros(static::class);
			return;
		}
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
		$registry=$this->panelExtensionRegistryOwner ?? self::activePanelExtensionRegistry();
		$scoped=$registry instanceof PanelInstanceExtensionRegistry ? $registry->macro(static::class, $name) : null;
		if(!is_callable($scoped) && !$registry instanceof PanelInstanceExtensionRegistry){
			$unscoped=PanelInstanceExtensionRegistry::uniqueUnscopedMacro(static::class, $name);
			if($unscoped!==null){ [$registry,$scoped]=$unscoped; $this->panelExtensionRegistryOwner=$registry; }
		}
		if(is_callable($scoped)){
			if($scoped instanceof \Closure){
				if((new \ReflectionFunction($scoped))->isStatic()){
					return $scoped(...$arguments);
				}
				return $scoped->call($this, ...$arguments);
			}
			return $scoped($this, ...$arguments);
		}
		if(isset(self::$panelMacros[$name])){
			$macro=self::$panelMacros[$name];
			if($macro instanceof \Closure){
				if((new \ReflectionFunction($macro))->isStatic()){
					return $macro(...$arguments);
				}
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
		$registry=self::activePanelExtensionRegistry();
		$scoped=$registry instanceof PanelInstanceExtensionRegistry ? $registry->macro(static::class, $name) : null;
		if(!is_callable($scoped) && !$registry instanceof PanelInstanceExtensionRegistry){
			$unscoped=PanelInstanceExtensionRegistry::uniqueUnscopedMacro(static::class, $name);
			$scoped=$unscoped[1] ?? null;
		}
		if(is_callable($scoped)){
			return $scoped(...$arguments);
		}
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
	 * @param static $instance Newly-created Panel builder/value object.
	 * @return static Configured instance after normal and important hooks run.
	 */
	protected static function configured(self $instance): self {
		$registry=self::activePanelExtensionRegistry();
		$scopedConfigurators=null;
		if(!$registry instanceof PanelInstanceExtensionRegistry){
			$unscoped=PanelInstanceExtensionRegistry::uniqueUnscopedConfigurators(static::class);
			if($unscoped!==null){ [$registry,$scopedConfigurators]=$unscoped; }
		}
		if($registry instanceof PanelInstanceExtensionRegistry){
			$instance->panelExtensionRegistryOwner=$registry;
		}
		$configurators=array_merge(
			self::$panelConfigurators,
			self::$panelImportantConfigurators,
			$scopedConfigurators ?? ($registry instanceof PanelInstanceExtensionRegistry ? $registry->configurators(static::class) : [])
		);
		foreach($configurators as $configurator){
			$result=$configurator($instance);
			if($result instanceof self){
				$instance=$result;
			}
		}
		return $instance;
	}

	/** Returns only an explicitly active instance registry, never the legacy adapter. */
	private static function activePanelExtensionRegistry(): ?PanelInstanceExtensionRegistry {
		$registry=PanelContext::config('__panel_extension_registry');
		return $registry instanceof PanelInstanceExtensionRegistry ? $registry : null;
	}
}
