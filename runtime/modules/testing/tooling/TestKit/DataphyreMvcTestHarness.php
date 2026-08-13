<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class DataphyreMvcTestHarness {

	/** @var array<int, callable> */
	private array $autoloaders=[];

	public function __destruct() {
		foreach($this->autoloaders as $loader){
			spl_autoload_unregister($loader);
		}
	}

	public function autoload(string $namespace, string $root): self {
		$namespace=trim($namespace, '\\').'\\';
		$root=rtrim(str_replace('\\', '/', $root), '/').'/';
		$loader=static function(string $class) use($namespace, $root): void {
			if(!str_starts_with($class, $namespace)){
				return;
			}
			$relative=substr($class, strlen($namespace));
			$path=$root.str_replace('\\', '/', $relative).'.php';
			if(is_file($path)){
				require_once $path;
			}
		};
		spl_autoload_register($loader);
		$this->autoloaders[]=$loader;
		return $this;
	}

	public function register(string $name, array $config): self {
		$config['manifest_cache']=$config['manifest_cache'] ?? false;
		\Dataphyre\Mvc\Mvc::register($name, $config);
		return $this;
	}

	public function registerFromConfig(string $name, string $config_path, array $overrides=[]): self {
		$config_path=str_replace('\\', '/', $config_path);
		if(!is_file($config_path)){
			throw new \RuntimeException('MVC test config file is missing: '.$config_path);
		}
		$config=require $config_path;
		if(!is_array($config)){
			throw new \RuntimeException('MVC test config file must return an array: '.$config_path);
		}
		$config=array_replace_recursive($config, $overrides);
		if(!array_key_exists('manifest_cache', $overrides)){
			$config['manifest_cache']=false;
		}
		return $this->register($name, $config);
	}

	public function app(string $name): object {
		return \Dataphyre\Mvc\Mvc::app($name);
	}

	public function dispatch(string $app, string $method, string $path, array $options=[]): object {
		$request=\Dataphyre\Http\Request::create(
			$method,
			$path,
			(array)($options['query'] ?? []),
			(array)($options['body'] ?? []),
			(array)($options['cookies'] ?? []),
			(array)($options['server'] ?? []),
			(array)($options['headers'] ?? []),
			(array)($options['route_parameters'] ?? []),
			(array)($options['attributes'] ?? []),
			(array)($options['files'] ?? [])
		);
		return \Dataphyre\Mvc\Mvc::host($app)->dispatch($request);
	}

	public function json(object $response): array {
		if(!$response instanceof \Dataphyre\Http\Response){
			throw new \InvalidArgumentException('Expected a Dataphyre HTTP response.');
		}
		$decoded=json_decode($response->body, true);
		if(json_last_error()!==JSON_ERROR_NONE || !is_array($decoded)){
			throw new \RuntimeException('Response body is not valid JSON: '.json_last_error_msg());
		}
		return $decoded;
	}
}
