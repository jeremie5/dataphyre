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

class Application extends application_definition implements \JsonSerializable {

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

	public static function current(?string $project_root=null, ?string $application_name=null): ?self {
		$current_definition=runtime::current_application_definition();
		if($current_definition instanceof application_definition && ($application_name===null || $current_definition->id===$application_name)){
			return static::fromDefinition($current_definition);
		}
		$project_root=static::normalizeProjectRoot($project_root);
		$application_name=$application_name!==null ? trim($application_name) : static::defaultApplicationName();
		if($project_root===null || $application_name===''){
			return null;
		}
		return static::discover($application_name, $project_root);
	}

	public static function discover(string $application_name, ?string $project_root=null): ?self {
		$project_root=static::normalizeProjectRoot($project_root);
		$application_name=trim($application_name);
		if($project_root===null || $application_name===''){
			return null;
		}
		$definition=runtime::resolve_application_definition($project_root, $application_name);
		return $definition instanceof application_definition ? static::fromDefinition($definition) : null;
	}

	public static function exists(string $application_name, ?string $project_root=null): bool {
		return static::discover($application_name, $project_root)!==null;
	}

	public static function discoverMany(array|string $application_names, ?string $project_root=null): ApplicationCatalog {
		$project_root=static::normalizeProjectRoot($project_root);
		$application_names=is_array($application_names) ? $application_names : [$application_names];
		$applications=[];
		foreach($application_names as $application_name){
			$application_name=trim((string)$application_name);
			if($application_name===''){
				continue;
			}
			$application=static::discover($application_name, $project_root);
			if($application instanceof self){
				$applications[$application->id]=$application;
			}
		}
		return new ApplicationCatalog($project_root, $applications);
	}

	public static function roots(?string $project_root=null): array {
		$project_root=static::normalizeProjectRoot($project_root);
		if($project_root===null){
			return [];
		}
		return app_locator::roots($project_root);
	}

	public static function available(?string $project_root=null): array {
		$applications=[];
		foreach(static::roots($project_root) as $root){
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

	public static function catalog(?string $project_root=null): ApplicationCatalog {
		$project_root=static::normalizeProjectRoot($project_root);
		return static::discoverMany(static::available($project_root), $project_root);
	}

	public static function legacy(string $id, string $root_directory, array $config=[]): self {
		return new self(
			$id,
			$root_directory,
			$config['rootpath_file'] ?? ($root_directory.'/rootpaths.php'),
			$config['routes_file'] ?? null,
			$config['compiled_routes_file'] ?? null,
			$config['framework_bootstrap_file'] ?? null,
			$config['legacy_bootstrap_file'] ?? ($root_directory.'/application_bootstrap.php'),
			$config['autoload'] ?? [],
			$config['options'] ?? []
		);
	}

	public function option(string $key, mixed $default=null): mixed {
		return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
	}

	public function hasOption(string $key): bool {
		return array_key_exists($key, $this->options);
	}

	public function hasRootpathFile(): bool {
		return $this->rootpath_file!==null && is_file($this->rootpath_file);
	}

	public function hasRoutesFile(): bool {
		return $this->routes_file!==null && is_file($this->routes_file);
	}

	public function hasCompiledRoutes(): bool {
		return $this->compiled_routes_file!==null && is_file($this->compiled_routes_file);
	}

	public function hasAutoload(): bool {
		return $this->autoload!==[];
	}

	public function autoloadPrefixes(): array {
		return $this->autoload;
	}

	public function hasFrameworkBootstrap(): bool {
		return $this->framework_bootstrap_file!==null && is_file($this->framework_bootstrap_file);
	}

	public function hasLegacyBootstrap(): bool {
		return $this->legacy_bootstrap_file!==null && is_file($this->legacy_bootstrap_file);
	}

	public function fallbackToLegacyBootstrap(): bool {
		return $this->should_fallback_to_legacy_bootstrap();
	}

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

	public function canBoot(): bool {
		return $this->bootMode()!==null;
	}

	public function bootstrapPlan(?string $project_root=null): BootstrapPlan {
		return BootstrapPlan::fromApplication($this, $project_root);
	}

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

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizeProjectRoot(?string $project_root): ?string {
		$project_root=trim((string)($project_root ?? runtime::current_project_root() ?? (defined('ROOTPATH') && !empty(ROOTPATH['root']) ? ROOTPATH['root'] : '')));
		if($project_root===''){
			return null;
		}
		$resolved=realpath($project_root);
		return rtrim($resolved!==false ? $resolved : $project_root, '/\\');
	}

	private static function defaultApplicationName(): string {
		return defined('APP') ? trim((string)constant('APP')) : '';
	}
}
