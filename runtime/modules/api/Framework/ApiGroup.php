<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

final class ApiGroup {

	private ?string $name=null;
	private string $prefix='';
	private array $middleware=[];
	private array $tags=[];
	private array $security_any=[];
	private array $security_all=[];
	private array $servers=[];
	private ?array $trace_definition=null;
	private array $lifecycle=[
		'before'=>[],
		'after'=>[],
		'error'=>[],
	];
	private array $dispatch_defaults=[];

	private function __construct(?string $name=null){
		$name=is_string($name) ? trim($name) : null;
		$this->name=$name!=='' ? $name : null;
	}

	public static function make(?string $name=null): self {
		return new self($name);
	}

	public function prefix(string $prefix): self {
		$this->prefix=self::normalizePath($prefix);
		return $this;
	}

	public function middleware(array|string ...$middleware): self {
		foreach($middleware as $definition){
			$this->middleware[]=$definition;
		}
		return $this;
	}

	public function tag(array|string ...$tags): self {
		foreach($tags as $tag){
			if(is_array($tag)){
				$this->tag(...$tag);
				continue;
			}
			$tag=trim((string)$tag);
			if($tag===''){
				continue;
			}
			$this->tags[$tag]=$tag;
		}
		return $this;
	}

	public function auth(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$this->security_any[]=$scheme;
		}
		return $this;
	}

	public function authAll(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$this->security_all[]=$scheme;
		}
		return $this;
	}

	public function server(string $url, ?string $description=null): self {
		$this->servers[]=[
			'url'=>trim($url),
			'description'=>$description,
		];
		return $this;
	}

	public function withTrace(bool $enabled=true, array $options=[]): self {
		$options['enabled']=$enabled;
		$this->trace_definition=$options;
		return $this;
	}

	public function beforeExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['before'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	public function afterExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['after'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	public function onError(mixed $target, array $options=[]): self {
		$this->lifecycle['error'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	public function dispatchDefaults(array $defaults): self {
		$this->dispatch_defaults=array_replace($this->dispatch_defaults, $defaults);
		return $this;
	}

	public function methods(array|string $methods, string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::methods($methods, $this->path($path), $handler));
	}

	public function get(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::get($this->path($path), $handler));
	}

	public function post(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::post($this->path($path), $handler));
	}

	public function put(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::put($this->path($path), $handler));
	}

	public function patch(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::patch($this->path($path), $handler));
	}

	public function delete(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::delete($this->path($path), $handler));
	}

	public function any(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::any($this->path($path), $handler));
	}

	public function apply(Endpoint $endpoint): Endpoint {
		if($this->middleware!==[]){
			$endpoint->middleware(...$this->middleware);
		}
		if($this->tags!==[]){
			$endpoint->tag(...array_values($this->tags));
		}
		if($this->security_any!==[]){
			$endpoint->auth(...$this->security_any);
		}
		if($this->security_all!==[]){
			$endpoint->authAll(...$this->security_all);
		}
		foreach($this->servers as $server){
			$endpoint->server((string)($server['url'] ?? ''), isset($server['description']) ? (string)$server['description'] : null);
		}
		if(is_array($this->trace_definition)){
			$enabled=($this->trace_definition['enabled'] ?? true)===true;
			$options=$this->trace_definition;
			unset($options['enabled']);
			$endpoint->withTrace($enabled, $options);
		}
		foreach($this->lifecycle['before'] as $hook){
			$endpoint->beforeExecute($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		foreach($this->lifecycle['after'] as $hook){
			$endpoint->afterExecute($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		foreach($this->lifecycle['error'] as $hook){
			$endpoint->onError($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		if($this->dispatch_defaults!==[]){
			$endpoint->dispatchDefaults($this->dispatch_defaults);
		}
		if($this->name!==null){
			$endpoint->profile($this->name, array_filter([
				'prefix'=>$this->prefix!=='' ? $this->prefix : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''));
		}
		return $endpoint;
	}

	private function path(string $path): string {
		return self::joinPath($this->prefix, $path);
	}

	private static function joinPath(string $prefix, string $path): string {
		$prefix=self::normalizePath($prefix);
		$path=self::normalizePath($path);
		if($prefix==='/' || $prefix===''){
			return $path;
		}
		if($path==='/'){
			return $prefix;
		}
		return rtrim($prefix, '/').'/'.ltrim($path, '/');
	}

	private static function normalizePath(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}
}
