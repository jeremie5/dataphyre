<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

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

	public function should_fallback_to_legacy_bootstrap(): bool {
		return ($this->options['fallback_to_legacy_bootstrap'] ?? true)===true;
	}

	private static function existing_file(string $file): ?string {
		return is_file($file) ? $file : null;
	}
}
