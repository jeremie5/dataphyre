<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/**
 * Boots Dataphyre framework modules for a test file without repeating policy,
 * autoloader, and module-registration boilerplate in every suite.
 */
final class Framework {

	/** @param list<string> $modules */
	private function __construct(private string $modules_root, private array $modules) {}

	/**
	 * @param array<int|string,string|bool>|string $modules
	 * @param array{
	 *   enabled?:array<int|string,string|bool>,
	 *   disabled?:array<int|string,string|bool>,
	 *   core_implicit?:bool,
	 *   constants?:array<string,mixed>,
	 *   functions?:array<int,string>,
	 *   before_modules?:array<int,string>,
	 *   files?:array<int,string>,
	 *   register?:bool,
	 *   modules_root?:string
	 * } $options
	 */
	public static function boot(array|string $modules=[], array $options=[]): self {
		$modules=self::names($modules);
		$enabled=self::map($options['enabled'] ?? $modules);
		$enabled=['core'=>true]+$enabled;
		$disabled=self::map($options['disabled'] ?? []);
		$core_implicit=($options['core_implicit'] ?? true)===true;
		$constants=[];
		foreach((array)($options['constants'] ?? []) as $name=>$value){
			$name=trim((string)$name);
			if($name===''){
				continue;
			}
			if(array_key_exists($name, $constants) && $constants[$name]!==$value){
				throw new \RuntimeException('Dataphyre test bootstrap constant conflicts with its declared value: '.$name);
			}
			if(defined($name)){
				if(constant($name)!==$value){
					throw new \RuntimeException('Dataphyre test bootstrap constant conflicts with its declared value: '.$name);
				}
			}
			$constants[$name]=$value;
		}
		$policy=[
			'enabled'=>$enabled,
			'disabled'=>$disabled,
			'core_implicit'=>$core_implicit,
		];
		if(array_key_exists('DATAPHYRE_MODULE_POLICY', $constants) && !self::policiesMatch($constants['DATAPHYRE_MODULE_POLICY'], $policy)){
			throw new \RuntimeException('Dataphyre framework modules conflict with the declared test bootstrap policy.');
		}
		if(defined('DATAPHYRE_MODULE_POLICY') && !self::policiesMatch(constant('DATAPHYRE_MODULE_POLICY'), $policy))
		{
			throw new \RuntimeException('Dataphyre framework modules conflict with the existing test bootstrap policy.');
		}
		$functions=array_map('strval', (array)($options['functions'] ?? []));
		foreach($functions as $function){
			self::noopFunctionDefinition($function);
		}
		$modules_root=rtrim((string)($options['modules_root'] ?? self::defaultModulesRoot()), '/\\');
		if($modules_root==='' || !is_dir($modules_root)){
			throw new \RuntimeException('Dataphyre framework modules root is unavailable: '.$modules_root);
		}
		$autoloader=$modules_root.'/core/kernel/autoloader.php';
		if(!is_file($autoloader)){
			throw new \RuntimeException('Dataphyre framework autoloader is unavailable: '.$autoloader);
		}
		$framework=new self($modules_root, $modules);
		$before_modules=array_map('strval', (array)($options['before_modules'] ?? []));
		$files=array_map('strval', (array)($options['files'] ?? []));
		foreach([...$before_modules, ...$files] as $file){
			$path=$framework->path($file);
			if(!is_file($path)){
				throw new \RuntimeException('Dataphyre test bootstrap file is unavailable: '.$path);
			}
		}
		foreach($constants as $name=>$value){
			if(!defined($name) && !define($name, $value)){
				throw new \RuntimeException('Dataphyre test bootstrap constant could not be defined: '.$name);
			}
		}
		if(!defined('DATAPHYRE_MODULE_POLICY') && !define('DATAPHYRE_MODULE_POLICY', $policy)){
			throw new \RuntimeException('Dataphyre test bootstrap module policy could not be defined.');
		}
		foreach($functions as $function){
			self::defineNoopFunction($function);
		}
		require_once $autoloader;
		foreach($before_modules as $file){
			$framework->require($file);
		}
		if(($options['register'] ?? true)!==false){
			if(!class_exists('dataphyre\\autoloader')){
				throw new \RuntimeException('Dataphyre framework autoloader class did not load.');
			}
			\dataphyre\autoloader::register($modules_root);
			if($modules!==[]){
				\dataphyre\autoloader::register_framework_modules($modules);
			}
		}
		foreach($files as $file){
			$framework->require($file);
		}
		return $framework;
	}

	public function modulesRoot(): string {
		return $this->modules_root;
	}

	/** @return list<string> */
	public function modules(): array {
		return $this->modules;
	}

	public function path(string $relative=''): string {
		$relative=ltrim(str_replace('\\', '/', trim($relative)), '/');
		return $relative==='' ? $this->modules_root : $this->modules_root.'/'.$relative;
	}

	public function require(string $relative): mixed {
		$path=$this->path($relative);
		if(!is_file($path)){
			throw new \RuntimeException('Dataphyre test bootstrap file is unavailable: '.$path);
		}
		return require_once $path;
	}

	private static function defaultModulesRoot(): string {
		return defined('ROOTPATH') && is_array(ROOTPATH)
			? rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules'
			: '';
	}

	/** @param array<int|string,string|bool>|string $values @return list<string> */
	private static function names(array|string $values): array {
		$values=is_string($values) ? [$values] : $values;
		$names=[];
		foreach($values as $key=>$value){
			$name=is_string($key) ? $key : (string)$value;
			$enabled=is_string($key) ? $value!==false : true;
			$name=trim($name);
			if($enabled && $name!=='' && !in_array($name, $names, true)){
				$names[]=$name;
			}
		}
		return $names;
	}

	/** @param array<int|string,string|bool>|string $values @return array<string,bool> */
	private static function map(array|string $values): array {
		$map=[];
		foreach(self::names($values) as $name){
			$map[$name]=true;
		}
		return $map;
	}

	/** @param array{enabled:array<string,bool>,disabled:array<string,bool>,core_implicit:bool} $expected */
	private static function policiesMatch(mixed $actual, array $expected): bool {
		if(!is_array($actual) || !isset($actual['enabled'], $actual['disabled']) || !is_array($actual['enabled']) || !is_array($actual['disabled'])){
			return false;
		}
		$actual_enabled=self::map($actual['enabled']);
		$actual_disabled=self::map($actual['disabled']);
		$expected_enabled=$expected['enabled'];
		$expected_disabled=$expected['disabled'];
		ksort($actual_enabled);
		ksort($actual_disabled);
		ksort($expected_enabled);
		ksort($expected_disabled);
		return $actual_enabled===$expected_enabled
			&& $actual_disabled===$expected_disabled
			&& ($actual['core_implicit'] ?? null)===$expected['core_implicit'];
	}

	private static function noopFunctionDefinition(string $qualified_function): ?string {
		$qualified_function=trim($qualified_function, '\\ ');
		if($qualified_function==='' || function_exists('\\'.$qualified_function)){
			return null;
		}
		$parts=explode('\\', $qualified_function);
		$function=array_pop($parts);
		foreach([...$parts, $function] as $part){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)!==1){
				throw new \InvalidArgumentException('Invalid test bootstrap function: '.$qualified_function);
			}
		}
		$namespace=implode('\\', $parts);
		$code=($namespace!=='' ? 'namespace '.$namespace.'; ' : '').'function '.$function.'(...$arguments): void {}';
		try{
			token_get_all('<?php '.$code, TOKEN_PARSE);
		}catch(\ParseError $failure){
			throw new \InvalidArgumentException('Invalid test bootstrap function: '.$qualified_function, 0, $failure);
		}
		return $code;
	}

	private static function defineNoopFunction(string $qualified_function): void {
		$code=self::noopFunctionDefinition($qualified_function);
		if($code!==null){
			PhpStub::define($code);
		}
	}
}
