<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Immutable-ish descriptor for a Dataphyre application root and boot files.
 *
 * Application definitions are discovered from explicit config or filesystem
 * conventions, then passed through the runtime bootstrapper to load rootpaths,
 * routes, compiled dispatch tables, framework bootstrap files, and legacy
 * application bootstrap files.
 */
class application_definition {

	public string $id;
	public string $root_directory;
	public ?string $rootpath_file;
	public ?string $routes_file;
	public ?string $compiled_routes_file;
	public ?string $framework_bootstrap_file;
	public ?string $legacy_bootstrap_file;
	public array $autoload;
	public array $options;

	/**
	 * Creates an application descriptor from normalized boot metadata.
	 *
	 * @param string $id Stable application id.
	 * @param string $root_directory Application root directory; trailing separators are removed.
	 * @param ?string $rootpath_file Optional rootpaths.php file.
	 * @param ?string $routes_file Optional routes.php file.
	 * @param ?string $compiled_routes_file Optional compiled route dispatcher file.
	 * @param ?string $framework_bootstrap_file Optional framework bootstrap file.
	 * @param ?string $legacy_bootstrap_file Optional legacy application bootstrap file.
	 * @param array<string,string> $autoload PSR-style namespace prefixes mapped to directories.
	 * @param array{fallback_to_legacy_bootstrap?:bool}|array<string,mixed> $options Bootstrap options such as fallback_to_legacy_bootstrap.
	 */
	public function __construct(
		string $id,
		string $root_directory,
		?string $rootpath_file=null,
		?string $routes_file=null,
		?string $compiled_routes_file=null,
		?string $framework_bootstrap_file=null,
		?string $legacy_bootstrap_file=null,
		array $autoload=[],
		array $options=[]
	){
		$this->id=$id;
		$this->root_directory=rtrim($root_directory, '/\\');
		$this->rootpath_file=$rootpath_file;
		$this->routes_file=$routes_file;
		$this->compiled_routes_file=$compiled_routes_file;
		$this->framework_bootstrap_file=$framework_bootstrap_file;
		$this->legacy_bootstrap_file=$legacy_bootstrap_file;
		$this->autoload=$autoload;
		$this->options=$options;
	}

	/**
	 * Builds an application definition from an explicit config array.
	 *
	 * Missing id and root_directory values fall back to the discovered
	 * application name and directory so partial config files can override only
	 * selected boot paths.
	 *
	 * @param array{id?:string,root_directory?:string,rootpath_file?:?string,routes_file?:?string,compiled_routes_file?:?string,framework_bootstrap_file?:?string,legacy_bootstrap_file?:?string,autoload?:array<string,string>,options?:array<string,mixed>} $definition Application definition config.
	 * @param string $app_name Discovered application key.
	 * @param string $app_directory Discovered application root.
	 * @return self Descriptor populated from config and discovery fallbacks.
	 */
	public static function from_array(array $definition, string $app_name, string $app_directory): self {
		return new self(
			$definition['id'] ?? $app_name,
			$definition['root_directory'] ?? $app_directory,
			$definition['rootpath_file'] ?? null,
			$definition['routes_file'] ?? null,
			$definition['compiled_routes_file'] ?? null,
			$definition['framework_bootstrap_file'] ?? null,
			$definition['legacy_bootstrap_file'] ?? null,
			$definition['autoload'] ?? [],
			$definition['options'] ?? []
		);
	}

	/**
	 * Builds an application definition from standard Dataphyre file conventions.
	 *
	 * Conventional discovery checks rootpaths.php, routes.php, the compiled
	 * backend route cache, framework_bootstrap.php, application_bootstrap.php,
	 * and a framework directory that maps to the app namespace.
	 *
	 * @param string $app_name Application key used for id and autoload prefix.
	 * @param string $app_directory Application root directory.
	 * @return self Convention-derived descriptor.
	 */
	public static function from_conventions(string $app_name, string $app_directory): self {
		$app_directory=rtrim($app_directory, '/\\');
		$framework_directory=$app_directory.'/framework';
		$autoload=[];
		if(is_dir($framework_directory)){
			$autoload[$app_name.'\\framework\\']=$framework_directory;
		}
		return new self(
			$app_name,
			$app_directory,
			self::existing_file($app_directory.'/rootpaths.php'),
			self::existing_file($app_directory.'/routes.php'),
			self::existing_file($app_directory.'/backend/dataphyre/cache/routes.compiled.php'),
			self::existing_file($app_directory.'/framework_bootstrap.php'),
			self::existing_file($app_directory.'/application_bootstrap.php'),
			$autoload,
			[
				'fallback_to_legacy_bootstrap'=>true,
			]
		);
	}

	/**
	 * Returns a new descriptor with selected config overrides applied.
	 *
	 * Path keys are replaced only when present. Autoload entries are merged over
	 * the current map, while options use array_replace so explicit override
	 * values take precedence without discarding unrelated options.
	 *
	 * @param array{id?:string,root_directory?:string,rootpath_file?:?string,routes_file?:?string,compiled_routes_file?:?string,framework_bootstrap_file?:?string,legacy_bootstrap_file?:?string,autoload?:array<string,string>,options?:array<string,mixed>} $definition Override payload using the same keys as from_array().
	 * @return self New descriptor containing the merged boot metadata.
	 */
	public function with_overrides(array $definition): self {
		return new self(
			$definition['id'] ?? $this->id,
			$definition['root_directory'] ?? $this->root_directory,
			array_key_exists('rootpath_file', $definition) ? $definition['rootpath_file'] : $this->rootpath_file,
			array_key_exists('routes_file', $definition) ? $definition['routes_file'] : $this->routes_file,
			array_key_exists('compiled_routes_file', $definition) ? $definition['compiled_routes_file'] : $this->compiled_routes_file,
			array_key_exists('framework_bootstrap_file', $definition) ? $definition['framework_bootstrap_file'] : $this->framework_bootstrap_file,
			array_key_exists('legacy_bootstrap_file', $definition) ? $definition['legacy_bootstrap_file'] : $this->legacy_bootstrap_file,
			array_key_exists('autoload', $definition)
				? array_merge($this->autoload, (array)$definition['autoload'])
				: $this->autoload,
			array_key_exists('options', $definition)
				? array_replace($this->options, (array)$definition['options'])
				: $this->options
		);
	}

	/**
	 * Reports whether the runtime should load the legacy application bootstrap.
	 *
	 * The fallback is enabled by default to preserve conventional applications
	 * unless config explicitly sets fallback_to_legacy_bootstrap to false.
	 *
	 * @return bool True when legacy bootstrap fallback remains enabled.
	 */
	public function should_fallback_to_legacy_bootstrap(): bool {
		return ($this->options['fallback_to_legacy_bootstrap'] ?? true)===true;
	}

	/**
	 * Returns a file path only when the conventional file exists.
	 *
	 * @param string $file Candidate path.
	 * @return ?string Existing file path, or null when absent.
	 */
	private static function existing_file(string $file): ?string {
		return is_file($file) ? $file : null;
	}
}
