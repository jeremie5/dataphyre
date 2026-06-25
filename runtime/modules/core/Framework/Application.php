<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

use dataphyre\application_definition;
use dataphyre\app_locator;
use dataphyre\runtime;

/**
 * Immutable description of a Dataphyre application discovered from the project tree.
 *
 * An application records every boot-relevant path Dataphyre needs to decide between
 * compiled routes, Framework bootstrap, and legacy bootstrap. Framework callers use this
 * class as the camelCase facade over the kernel `application_definition` value.
 */
class Application extends application_definition implements \JsonSerializable {

	/**
	 * Wraps a kernel application definition in the Framework-facing value object.
	 *
	 * @param application_definition $definition Kernel discovery result produced by `dataphyre\runtime`.
	 * @return self Framework value preserving the same paths, autoload prefixes, and options.
	 */
	public static function fromDefinition(application_definition $definition): self {
		return new self(
			$definition->id,
			$definition->root_directory,
			$definition->rootpath_file,
			$definition->routes_file,
			$definition->compiled_routes_file,
			$definition->framework_bootstrap_file,
			$definition->legacy_bootstrap_file,
			$definition->autoload,
			$definition->options
		);
	}

	/**
	 * Resolves the current application from the booted runtime or from the project tree.
	 *
	 * When the runtime has already selected an application, that in-memory definition wins.
	 * Otherwise Dataphyre falls back to `APP` and `ROOTPATH['root']` when explicit arguments
	 * are not supplied.
	 *
	 * @param ?string $projectRoot Project root used for discovery when no runtime definition is active.
	 * @param ?string $applicationName Application id; defaults to the `APP` constant when available.
	 * @return ?self Discovered application, or `null` when no root/name pair can be resolved.
	 */
	public static function current(?string $projectRoot=null, ?string $applicationName=null): ?self {
		$currentDefinition=runtime::current_application_definition();
		if($currentDefinition instanceof application_definition && ($applicationName===null || $currentDefinition->id===$applicationName)){
			return static::fromDefinition($currentDefinition);
		}
		$projectRoot=static::normalizeProjectRoot($projectRoot);
		$applicationName=$applicationName!==null ? trim($applicationName) : static::defaultApplicationName();
		if($projectRoot===null || $applicationName===''){
			return null;
		}
		return static::discover($applicationName, $projectRoot);
	}

	/**
	 * Discovers one application definition beneath a project root.
	 *
	 * @param string $applicationName Directory/id name to resolve under configured application roots.
	 * @param ?string $projectRoot Project root; defaults to the active runtime root when available.
	 * @return ?self Application metadata, or `null` when the application cannot be found.
	 */
	public static function discover(string $applicationName, ?string $projectRoot=null): ?self {
		$projectRoot=static::normalizeProjectRoot($projectRoot);
		$applicationName=trim($applicationName);
		if($projectRoot===null || $applicationName===''){
			return null;
		}
		$definition=runtime::resolve_application_definition($projectRoot, $applicationName);
		return $definition instanceof application_definition ? static::fromDefinition($definition) : null;
	}

	/**
	 * Reports whether an application can be discovered without materializing a catalog.
	 *
	 * @param string $applicationName Application name.
	 * @param ?string $projectRoot Project root.
	 * @return bool `true` when discovery returns an application definition.
	 */
	public static function exists(string $applicationName, ?string $projectRoot=null): bool {
		return static::discover($applicationName, $projectRoot)!==null;
	}

	/**
	 * Discovers a set of application names and indexes the successful matches by id.
	 *
	 * Empty names and missing applications are skipped so callers can pass user/config input
	 * directly and still receive a clean catalog.
	 *
	 * @param array|string $applicationNames One application id or a list of candidate ids.
	 * @param ?string $projectRoot Project root used for every lookup.
	 * @return ApplicationCatalog Catalog keyed by discovered application id.
	 */
	public static function discoverMany(array|string $applicationNames, ?string $projectRoot=null): ApplicationCatalog {
		$projectRoot=static::normalizeProjectRoot($projectRoot);
		$applicationNames=is_array($applicationNames) ? $applicationNames : [$applicationNames];
		$applications=[];
		foreach($applicationNames as $applicationName){
			$applicationName=trim((string)$applicationName);
			if($applicationName===''){
				continue;
			}
			$application=static::discover($applicationName, $projectRoot);
			if($application instanceof self){
				$applications[$application->id]=$application;
			}
		}
		return new ApplicationCatalog($projectRoot, $applications);
	}

	/**
	 * Lists directories that Dataphyre will scan for applications under the project root.
	 *
	 * @param ?string $projectRoot Project root; defaults to the active runtime root when available.
	 * @return list<string> Absolute or configured application-root paths.
	 */
	public static function roots(?string $projectRoot=null): array {
		$projectRoot=static::normalizeProjectRoot($projectRoot);
		if($projectRoot===null){
			return [];
		}
		return app_locator::roots($projectRoot);
	}

	/**
	 * Lists application ids found in every configured application root.
	 *
	 * The result is de-duplicated by directory name; it does not validate bootability.
	 *
	 * @param ?string $projectRoot Project root used to resolve application roots.
	 * @return list<string> Application directory names.
	 */
	public static function available(?string $projectRoot=null): array {
		$applications=[];
		foreach(static::roots($projectRoot) as $root){
			if(!is_dir($root)){
				continue;
			}
			foreach(scandir($root) ?: [] as $entry){
				if($entry==='.' || $entry==='..'){
					continue;
				}
				if(!is_dir($root.'/'.$entry)){
					continue;
				}
				$applications[$entry]=true;
			}
		}
		return array_keys($applications);
	}

	/**
	 * Builds a discovery catalog for every available application id.
	 *
	 * @param ?string $projectRoot Project root used to scan application roots.
	 * @return ApplicationCatalog Catalog of boot metadata for discoverable applications.
	 */
	public static function catalog(?string $projectRoot=null): ApplicationCatalog {
		$projectRoot=static::normalizeProjectRoot($projectRoot);
		return static::discoverMany(static::available($projectRoot), $projectRoot);
	}

	/**
	 * Creates an application definition for older projects that only expose rootpaths and bootstrap files.
	 *
	 * @param string $id Application id used in catalogs and runtime state.
	 * @param string $rootDirectory Application root directory.
	 * @param array{rootpath_file?:?string,routes_file?:?string,compiled_routes_file?:?string,framework_bootstrap_file?:?string,legacy_bootstrap_file?:?string,autoload?:array<string,string|list<string>>,options?:array<string,mixed>} $config Optional path overrides and framework options.
	 * @return self Legacy-compatible application definition.
	 */
	public static function legacy(string $id, string $rootDirectory, array $config=[]): self {
		return new self(
			$id,
			$rootDirectory,
			$config['rootpath_file'] ?? ($rootDirectory.'/rootpaths.php'),
			$config['routes_file'] ?? null,
			$config['compiled_routes_file'] ?? null,
			$config['framework_bootstrap_file'] ?? null,
			$config['legacy_bootstrap_file'] ?? ($rootDirectory.'/application_bootstrap.php'),
			$config['autoload'] ?? [],
			$config['options'] ?? []
		);
	}

	/**
	 * Reads an application option captured during discovery.
	 *
	 * @param string $key Option key captured from application discovery metadata.
	 * @param mixed $default Value returned when the option is absent.
	 * @return mixed discovered option value, including null, or the caller default when absent.
	 */
	public function option(string $key, mixed $default=null): mixed {
		return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
	}

	/**
	 * Reports whether discovery captured an application option.
	 *
	 * @param string $key Option key captured from application discovery metadata.
	 * @return bool `true` when the option exists, even if its value is `null`.
	 */
	public function hasOption(string $key): bool {
		return array_key_exists($key, $this->options);
	}

	/**
	 * Reports whether the application rootpaths file exists on disk.
	 *
	 * @return bool `true` when `rootpathFile` points to an existing file.
	 */
	public function hasRootpathFile(): bool {
		return $this->rootpath_file!==null && is_file($this->rootpath_file);
	}

	/**
	 * Reports whether the source routes file exists on disk.
	 *
	 * @return bool `true` when route declarations can be loaded from the application.
	 */
	public function hasRoutesFile(): bool {
		return $this->routes_file!==null && is_file($this->routes_file);
	}

	/**
	 * Reports whether a compiled routes artifact exists for fast boot.
	 *
	 * @return bool `true` when compiled route dispatch can be attempted first.
	 */
	public function hasCompiledRoutes(): bool {
		return $this->compiled_routes_file!==null && is_file($this->compiled_routes_file);
	}

	/**
	 * Reports whether application-specific PSR-style autoload prefixes were discovered.
	 *
	 * @return bool `true` when `autoloadPrefixes()` will return at least one mapping.
	 */
	public function hasAutoload(): bool {
		return $this->autoload!==[];
	}

	/**
	 * Returns application autoload prefix mappings.
	 *
	 * @return array<string,string|list<string>> Namespace prefixes mapped to one or more directories.
	 */
	public function autoloadPrefixes(): array {
		return $this->autoload;
	}

	/**
	 * Reports whether the application exposes a Framework bootstrap file.
	 *
	 * @return bool `true` when Framework boot can run without falling back to legacy boot.
	 */
	public function hasFrameworkBootstrap(): bool {
		return $this->framework_bootstrap_file!==null && is_file($this->framework_bootstrap_file);
	}

	/**
	 * Reports whether the application exposes a legacy bootstrap file.
	 *
	 * @return bool `true` when legacy boot is available as a fallback or explicit mode.
	 */
	public function hasLegacyBootstrap(): bool {
		return $this->legacy_bootstrap_file!==null && is_file($this->legacy_bootstrap_file);
	}

	/**
	 * Reports whether this application is allowed to fall back to its legacy bootstrap.
	 *
	 * @return bool `true` when the inherited kernel definition permits legacy fallback.
	 */
	public function fallbackToLegacyBootstrap(): bool {
		return $this->should_fallback_to_legacy_bootstrap();
	}

	/**
	 * Determines the boot strategy Dataphyre will attempt for this application.
	 *
	 * Compiled routes take precedence, then Framework bootstrap, then legacy bootstrap when
	 * fallback is allowed. A `null` result means the application is discoverable but not bootable.
	 *
	 * @return 'compiled_routes'|'framework'|'legacy'|null Selected boot mode.
	 */
	public function bootMode(): ?string {
		if($this->hasCompiledRoutes()){
			return 'compiled_routes';
		}
		if($this->hasFrameworkBootstrap()){
			return 'framework';
		}
		if($this->fallbackToLegacyBootstrap() && $this->hasLegacyBootstrap()){
			return 'legacy';
		}
		return null;
	}

	/**
	 * Reports whether Dataphyre has any viable boot path for the application.
	 *
	 * @return bool `true` when `bootMode()` resolves to a concrete strategy.
	 */
	public function canBoot(): bool {
		return $this->bootMode()!==null;
	}

	/**
	 * Builds the executable bootstrap plan for this application.
	 *
	 * @param ?string $projectRoot Project root.
	 * @return BootstrapPlan Plan containing selected mode, paths, and bootability diagnostics.
	 */
	public function bootstrapPlan(?string $projectRoot=null): BootstrapPlan {
		return BootstrapPlan::fromApplication($this, $projectRoot);
	}

	/**
	 * Exports discovery metadata using Dataphyre's snake_case wire format.
	 *
	 * @return array{id:string,root_directory:string,rootpath_file:?string,routes_file:?string,compiled_routes_file:?string,framework_bootstrap_file:?string,legacy_bootstrap_file:?string,autoload:array<string,string|list<string>>,options:array<string,mixed>}
	 */
	public function toArray(): array {
		return [
			'id'=>$this->id,
			'root_directory'=>$this->root_directory,
			'rootpath_file'=>$this->rootpath_file,
			'routes_file'=>$this->routes_file,
			'compiled_routes_file'=>$this->compiled_routes_file,
			'framework_bootstrap_file'=>$this->framework_bootstrap_file,
			'legacy_bootstrap_file'=>$this->legacy_bootstrap_file,
			'autoload'=>$this->autoload,
			'options'=>$this->options,
		];
	}

	/**
	 * Serializes the application descriptor for JSON diagnostics.
	 *
	 * @return array{id:string,root_directory:string,rootpath_file:?string,routes_file:?string,compiled_routes_file:?string,framework_bootstrap_file:?string,legacy_bootstrap_file:?string,autoload:array<string,string|list<string>>,options:array<string,mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes an explicit, runtime, or legacy project root path.
	 *
	 * @param ?string $projectRoot Optional caller-supplied project root.
	 * @return ?string Realpath-normalized project root, or null when none is known.
	 */
	private static function normalizeProjectRoot(?string $projectRoot): ?string {
		$projectRoot=trim((string)($projectRoot ?? runtime::current_project_root() ?? (defined('ROOTPATH') && !empty(ROOTPATH['root']) ? ROOTPATH['root'] : '')));
		if($projectRoot===''){
			return null;
		}
		$resolved=realpath($projectRoot);
		return rtrim($resolved!==false ? $resolved : $projectRoot, '/\\');
	}

	/**
	 * Returns the current application name from the legacy APP constant.
	 *
	 * @return string Application name, or an empty string outside an app context.
	 */
	private static function defaultApplicationName(): string {
		return defined('APP') ? trim((string)constant('APP')) : '';
	}
}
