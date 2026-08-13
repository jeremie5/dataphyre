<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait ManagesRuntimeState {

	public function global(string $name): GlobalState {
		return new GlobalState($this, $name);
	}

	public function globalMap(string $name): GlobalState {
		return new GlobalState($this, $name, true);
	}

	/** Runs one scoped native-global contract and passes its managed view to the callback. */
	public function withGlobal(string $name, mixed $value, callable $callback): mixed {
		return $this->global($name)->withValue($value, $callback);
	}

	/** Runs one scoped native-global absence contract and passes its managed view to the callback. */
	public function withoutGlobal(string $name, callable $callback): mixed {
		return $this->global($name)->withoutValue($callback);
	}

	/**
	 * Applies cleanup-managed process environment overrides for one test case.
	 *
	 * Null removes a variable. Repeated calls compose as nested scopes and are
	 * restored in LIFO order even when the case fails.
	 *
	 * @param array<string,scalar|null> $variables
	 */
	public function environment(array $variables): self {
		foreach($variables as $name=>$value){
			if(!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)!==1){
				throw new \InvalidArgumentException('Environment variable names must be PHP-style identifiers.');
			}
			if($value!==null && !is_scalar($value)){
				throw new \InvalidArgumentException('Environment variable values must be scalar or null.');
			}
			$previous=getenv($name);
			$had_env=array_key_exists($name, $_ENV);
			$previous_env=$_ENV[$name] ?? null;
			$had_server=array_key_exists($name, $_SERVER);
			$previous_server=$_SERVER[$name] ?? null;
			$this->defer(static function()use(
				$name,
				$previous,
				$had_env,
				$previous_env,
				$had_server,
				$previous_server
			): void {
				if($previous===false){
					putenv($name);
				}else{
					putenv($name.'='.$previous);
				}
				if($had_env){
					$_ENV[$name]=$previous_env;
				}else{
					unset($_ENV[$name]);
				}
				if($had_server){
					$_SERVER[$name]=$previous_server;
				}else{
					unset($_SERVER[$name]);
				}
			});
			if($value===null){
				putenv($name);
				unset($_ENV[$name]);
				unset($_SERVER[$name]);
			}else{
				$normalized=is_bool($value) ? ($value ? '1' : '0') : (string)$value;
				putenv($name.'='.$normalized);
				$_ENV[$name]=$normalized;
				$_SERVER[$name]=$normalized;
			}
		}
		return $this;
	}

	/**
	 * Applies cleanup-managed runtime INI overrides for one test case.
	 *
	 * Startup-only directives should be supplied to coveredPhpProcess() through
	 * php_ini instead; this helper deliberately fails when PHP rejects a runtime
	 * change so a test never proceeds under an accidental host configuration.
	 *
	 * @param array<string,scalar> $settings
	 */
	public function phpIni(array $settings): self {
		foreach($settings as $name=>$value){
			if(!is_string($name) || trim($name)===''){
				throw new \InvalidArgumentException('PHP INI setting names must be non-empty strings.');
			}
			if(!is_scalar($value)){
				throw new \InvalidArgumentException('PHP INI setting values must be scalar.');
			}
			$previous=ini_get($name);
			if($previous===false){
				throw new \InvalidArgumentException('Unknown PHP INI setting: '.$name);
			}
			$normalized=is_bool($value) ? ($value ? '1' : '0') : (string)$value;
			if(ini_set($name,$normalized)===false){
				throw new \RuntimeException('PHP INI setting cannot be changed at runtime: '.$name);
			}
			$this->defer(static function()use($name,$previous): void {
				if(ini_set($name,$previous)===false){
					throw new \RuntimeException('PHP INI setting could not be restored: '.$name);
				}
			});
		}
		return $this;
	}

	/** Registers a cleanup-managed userland stream wrapper for one test case. */
	public function streamWrapper(string $scheme, string $wrapper_class): self {
		$scheme=strtolower(trim($scheme));
		if(preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)!==1){
			throw new \InvalidArgumentException('Stream-wrapper schemes must follow URI scheme syntax.');
		}
		if(!class_exists($wrapper_class)){
			throw new \InvalidArgumentException('Stream-wrapper class is unavailable: '.$wrapper_class);
		}
		if(in_array($scheme, stream_get_wrappers(), true)){
			throw new \LogicException('Stream-wrapper scheme is already registered: '.$scheme);
		}
		if(!stream_wrapper_register($scheme, $wrapper_class)){
			throw new \RuntimeException('Unable to register stream-wrapper scheme: '.$scheme);
		}
		$this->defer(static function()use($scheme): void {
			if(in_array($scheme, stream_get_wrappers(), true)){
				stream_wrapper_unregister($scheme);
			}
		});
		return $this;
	}

	/** @param array<string|int,mixed> $initial */
	public function state(string $channel, array $initial=[]): TestState {
		return TestState::create($this, $channel, $initial);
	}

	public function defineSymbols(string $php): mixed {
		return PhpStub::define($php);
	}

	public function loadStub(string $path): mixed {
		return PhpStub::load($path);
	}

	/** Loads a framework entrypoint and exposes fluent assertions for its published symbols. */
	public function phpBootstrap(string $path): PhpBootstrapProbe {
		return new PhpBootstrapProbe($this, $path);
	}

	/** @param array<string,string|int|float|bool|null> $values */
	public function setEnvironmentForTest(array $values): void {
		$previous=[];
		foreach($values as $name=>$value){
			$name=(string)$name;
			$previous[$name]=['value'=>getenv($name), 'env_exists'=>array_key_exists($name, $_ENV), 'env'=>$_ENV[$name] ?? null];
			if($value===null){
				putenv($name);
				unset($_ENV[$name]);
			}else{
				$string=is_bool($value) ? ($value ? '1' : '0') : (string)$value;
				putenv($name.'='.$string);
				$_ENV[$name]=$string;
			}
		}
		$this->cleanup(static function()use($previous): void {
			foreach($previous as $name=>$state){
				$state['value']===false ? putenv($name) : putenv($name.'='.$state['value']);
				if($state['env_exists']){
					$_ENV[$name]=$state['env'];
				}else{
					unset($_ENV[$name]);
				}
			}
		});
	}

	/** @param array<string,mixed> $values */
	public function setGlobalsForTest(array $values): void {
		$previous=[];
		foreach($values as $name=>$value){
			$name=(string)$name;
			$previous[$name]=['exists'=>array_key_exists($name, $GLOBALS), 'value'=>$GLOBALS[$name] ?? null];
			$GLOBALS[$name]=$value;
		}
		$this->cleanup(static function()use($previous): void {
			foreach($previous as $name=>$state){
				if($state['exists']){
					$GLOBALS[$name]=$state['value'];
				}else{
					unset($GLOBALS[$name]);
				}
			}
		});
	}

	/** @param array<string,mixed> $values */
	public function withGlobals(array $values, callable $callback): mixed {
		$previous=[];
		foreach($values as $name=>$value){
			$name=(string)$name;
			$previous[$name]=['exists'=>array_key_exists($name, $GLOBALS), 'value'=>$GLOBALS[$name] ?? null];
			$GLOBALS[$name]=$value;
		}
		try{
			return $callback();
		}finally { foreach($previous as $name=>$state){
				if($state['exists']){
					$GLOBALS[$name]=$state['value'];
				}else{
					unset($GLOBALS[$name]);
				}
			}
		}
	}
}
