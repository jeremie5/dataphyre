<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Process-local registry for panel surfaces and their boot lifecycle.
 *
 * The registry gives the panel framework a single place to create, find, and
 * configure named `PanelInstance` surfaces. The default surface reuses the
 * process-wide `PanelManager`; named surfaces receive isolated managers so
 * resources, pages, widgets, and plugins can be grouped by panel entry point.
 *
 * Registry state is static and lasts for the current PHP process until `flush()`
 * is called. Long-running workers and test suites should flush when they need a
 * fresh panel configuration snapshot.
 */
final class PanelRegistry {

	/** @var array<string, PanelInstance> */
	private static array $surfaces=[];
	private static bool $configured=false;

	/**
	 * Returns a named panel surface, creating it when necessary.
	 *
	 * Names are normalized through the same resource-name rules used throughout
	 * the panel framework. If an existing surface is requested with a non-empty
	 * config payload, that config is merged into the existing instance before it is
	 * returned.
	 *
	 * @param string $name Surface name; blank values resolve to `default`.
	 * @param array<string,mixed> $config Initial or additional surface configuration.
	 * @return PanelInstance Registered panel surface for the normalized name.
	 */
	public static function surface(string $name='default', array $config=[]): PanelInstance {
		$name=self::normalizeName($name);
		if(isset(self::$surfaces[$name])){
			if($config!==[]){
				self::$surfaces[$name]->config($config);
			}
			return self::$surfaces[$name];
		}
		$manager=$name==='default' ? PanelManager::instance() : new PanelManager();
		return self::$surfaces[$name]=new PanelInstance($name, $manager, $config);
	}

	/**
	 * Registers an already-built panel surface under a normalized name.
	 *
	 * Existing entries with the same normalized name are replaced. This is useful
	 * for tests, package bootstraps, and applications that construct a specialized
	 * `PanelInstance` before handing it to the global registry.
	 *
	 * @param PanelInstance $surface Surface instance to expose through the registry.
	 * @param ?string $name Optional registry name; null uses the surface's own name.
	 * @return PanelInstance The registered surface instance.
	 */
	public static function register(PanelInstance $surface, ?string $name=null): PanelInstance {
		$name=self::normalizeName($name ?? $surface->name());
		self::$surfaces[$name]=$surface;
		return $surface;
	}

	/**
	 * Reports whether a surface has already been registered or created.
	 *
	 *
	 * @param string $name Surface name to check before or after normalization.
	 * @return bool True when the normalized surface exists in the process registry.
	 */
	public static function has(string $name): bool {
		return isset(self::$surfaces[self::normalizeName($name)]);
	}

	/**
	 * Returns a registered surface without creating it.
	 *
	 * Unlike `surface()`, this accessor has no side effects and is safe for
	 * discovery code that needs to distinguish an absent surface from an empty one.
	 *
	 * @param string $name Surface name to resolve.
	 * @return ?PanelInstance Registered surface, or null when absent.
	 */
	public static function get(string $name): ?PanelInstance {
		return self::$surfaces[self::normalizeName($name)] ?? null;
	}

	/**
	 * Attaches a provider to a named panel surface.
	 *
	 * The target surface is created if it does not yet exist. Provider strings and
	 * callables are resolved by `PanelInstance::provide()`, keeping provider
	 * normalization in the instance layer.
	 *
	 * @param string $surface Surface that should receive the provider.
	 * @param PanelProvider|callable|string $provider Provider object, class name, or callback accepted by the surface.
	 * @return PanelInstance Surface after the provider has been applied.
	 */
	public static function provide(string $surface, PanelProvider|callable|string $provider): PanelInstance {
		return self::surface($surface)->provide($provider);
	}

	/**
	 * Attaches a plugin to a named panel surface.
	 *
	 * The target surface is created if it does not yet exist. Plugin instantiation,
	 * configuration, and duplicate handling are delegated to the surface.
	 *
	 * @param string $surface Surface that should receive the plugin.
	 * @param PanelPlugin|string $plugin Plugin instance or class name accepted by the surface.
	 * @param array<string,mixed> $config Plugin configuration forwarded unchanged.
	 * @return PanelInstance Surface after the plugin has been applied.
	 */
	public static function plugin(string $surface, PanelPlugin|string $plugin, array $config=[]): PanelInstance {
		return self::surface($surface)->plugin($plugin, $config);
	}

	/**
	 * Returns every registered surface keyed by normalized surface name.
	 *
	 * The returned array exposes the live surface instances held by the registry.
	 * Callers can inspect or configure those surfaces directly; use `flush()` when
	 * process-local registry state must be discarded.
	 *
	 * @return array<string,PanelInstance> Registered panel surfaces.
	 */
	public static function all(): array {
		return self::$surfaces;
	}

	/**
	 * Returns the normalized names of every registered surface.
	 *
	 *
	 * @return array<int,string> Surface names in registry insertion order.
	 */
	public static function names(): array {
		return array_keys(self::$surfaces);
	}

	/**
	 * Builds a compact diagnostic description of every registered surface.
	 *
	 * The payload intentionally lists names and plugin ids instead of embedding
	 * full resource/page/widget definitions. It is shaped for debug panels,
	 * traces, and health checks that need to see what has been booted
	 * without serializing entire surface objects.
	 *
	 * @return array<string,array{name:string,resources:array<int,string>,pages:array<int,string>,widgets:array<int,string>,plugins:array<int,string>}> Registered surface summary.
	 */
	public static function describe(): array {
		$description=[];
		foreach(self::$surfaces as $name=>$surface){
			$surfaceDescription=$surface->describe();
			$description[$name]=[
				'name'=>$surface->name(),
				'resources'=>array_column($surfaceDescription['resources'] ?? [], 'name'),
				'pages'=>array_column($surfaceDescription['pages'] ?? [], 'name'),
				'widgets'=>array_column($surfaceDescription['widgets'] ?? [], 'name'),
				'plugins'=>array_column($surfaceDescription['plugins'] ?? [], 'id'),
			];
		}
		return $description;
	}

	/**
	 * Boots panel providers, plugins, and named surfaces from panel configuration.
	 *
	 * Configuration boot is idempotent for the current process. The default
	 * surface receives top-level `providers` and `plugins`; each configured named
	 * surface receives its own config payload after provider and plugin lists are
	 * removed from the surface config array.
	 *
	 * @return array<string,PanelInstance> Registered surfaces after configured providers and plugins have been applied.
	 */
	public static function bootConfigured(): array {
		if(self::$configured){
			return self::all();
		}
		self::$configured=true;
		$defaultProviders=PanelConfig::config('providers', []);
		if(is_array($defaultProviders) && $defaultProviders!==[]){
			self::surface('default')->provideMany($defaultProviders);
		}
		$defaultPlugins=PanelConfig::config('plugins', []);
		if(is_array($defaultPlugins) && $defaultPlugins!==[]){
			self::surface('default')->plugins($defaultPlugins);
		}
		$surfaces=PanelConfig::config('surfaces', []);
		if(is_array($surfaces)){
			foreach($surfaces as $name=>$config){
				if(!is_array($config)){
					continue;
				}
				$providers=$config['providers'] ?? [];
				$plugins=$config['plugins'] ?? [];
				unset($config['providers']);
				unset($config['plugins']);
				$surface=self::surface((string)$name, $config);
				if(is_array($providers)){
					$surface->provideMany($providers);
				}
				if(is_array($plugins)){
					$surface->plugins($plugins);
				}
			}
		}
		return self::all();
	}

	/**
	 * Clears every registered surface and resets configuration boot state.
	 *
	 * Use this in tests, hot-reload loops, or long-running workers before booting a
	 * different panel configuration. Existing surface objects held elsewhere are
	 * not destroyed, but they are no longer returned by the registry.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$surfaces=[];
		self::$configured=false;
	}

	/**
	 * Normalizes a registry surface name.
	 *
	 * Surface names share the panel resource normalizer so route-facing names,
	 * class-derived names, and config names compare consistently. Empty names are
	 * mapped to the default surface.
	 *
	 * @param string $name Raw surface name.
	 * @return string Normalized surface name, never empty.
	 */
	private static function normalizeName(string $name): string {
		$name=Resource::normalizeName($name);
		return $name!=='' ? $name : 'default';
	}
}
