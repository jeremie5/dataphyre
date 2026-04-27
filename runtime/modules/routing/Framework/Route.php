<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

final class Route implements CompilableRoute {

	private array $methods;
	private string $path;
	private mixed $handler;
	private array $middleware=[];

	private function __construct(array $methods, string $path, mixed $handler){
		$this->methods=self::normalize_methods($methods);
		$this->path=self::normalize_path($path);
		$this->handler=$handler;
	}

	public static function methods(array|string $methods, string $path, mixed $handler): self {
		return new self((array)$methods, $path, $handler);
	}

	public static function get(string $path, mixed $handler): self {
		return new self(['GET'], $path, $handler);
	}

	public static function post(string $path, mixed $handler): self {
		return new self(['POST'], $path, $handler);
	}

	public static function put(string $path, mixed $handler): self {
		return new self(['PUT'], $path, $handler);
	}

	public static function patch(string $path, mixed $handler): self {
		return new self(['PATCH'], $path, $handler);
	}

	public static function delete(string $path, mixed $handler): self {
		return new self(['DELETE'], $path, $handler);
	}

	public static function any(string $path, mixed $handler): self {
		return new self(['ANY'], $path, $handler);
	}

	public function middleware(array|string ...$middleware): self {
		$definitions=[];
		foreach($middleware as $definition){
			if(is_array($definition) && self::is_list($definition)){
				foreach($definition as $nested_definition){
					$definitions[]=$nested_definition;
				}
				continue;
			}
			$definitions[]=$definition;
		}
		foreach($definitions as $definition){
			$this->middleware[]=$this->normalize_middleware($definition);
		}
		return $this;
	}

	public function compile(): array {
		$route=[
			'methods'=>$this->methods,
			'handler'=>$this->compile_handler($this->handler),
		];
		if($this->middleware!==[]){
			$route['middleware']=$this->middleware;
		}
		if($this->path==='/' || !str_contains($this->path, '{')){
			$route['exact_path']=$this->path;
			return $route;
		}
		$route['path_regex']=$this->compile_path_regex($this->path, $splat_parameters);
		if($splat_parameters!==[]){
			$route['splat_parameters']=$splat_parameters;
		}
		return $route;
	}

	private function compile_handler(mixed $handler): mixed {
		if($handler instanceof ControllerAction){
			return $handler->compile();
		}
		if(is_string($handler) || is_callable($handler)){
			return $handler;
		}
		if(is_array($handler)){
			return $handler;
		}
		throw new \RuntimeException('Route handler is invalid or unsupported.');
	}

	private function compile_path_regex(string $path, array &$splat_parameters): string {
		$splat_parameters=[];
		$segments=array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment!==''));
		if($segments===[]){
			return '#^/$#';
		}
		$regex_segments=[];
		foreach($segments as $segment){
			if(preg_match('/^\{\.\.\.([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)===1){
				$name=$matches[1];
				$splat_parameters[]=$name;
				$regex_segments[]='(?P<'.$name.'>.*)';
				continue;
			}
			if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)===1){
				$regex_segments[]='(?P<'.$matches[1].'>[^/]+)';
				continue;
			}
			$regex_segments[]=preg_quote($segment, '#');
		}
		return '#^/'.implode('/', $regex_segments).'$#';
	}

	private static function normalize_methods(array $methods): array {
		$normalized=[];
		foreach($methods as $method){
			$method=strtoupper(trim((string)$method));
			if($method===''){
				continue;
			}
			$normalized[$method]=$method;
		}
		return array_values($normalized);
	}

	private static function normalize_path(string $path): string {
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	private function normalize_middleware(mixed $definition): array {
		if(is_string($definition)){
			return $this->normalize_middleware_string($definition);
		}
		if(is_array($definition) && !self::is_list($definition)){
			$normalized=[];
			if(isset($definition['class']) && is_string($definition['class']) && trim($definition['class'])!==''){
				$normalized['class']=trim($definition['class'], '\\');
			}
			$alias=$definition['alias'] ?? $definition['name'] ?? null;
			if(isset($normalized['class'])===false && is_string($alias) && trim($alias)!==''){
				$normalized['alias']=trim($alias);
			}
			if(isset($definition['parameters'])){
				$normalized['parameters']=$this->normalize_middleware_parameters($definition['parameters']);
			}
			if(isset($definition['module']) || isset($definition['modules'])){
				$modules=$definition['modules'] ?? $definition['module'];
				$normalized_modules=$this->normalize_middleware_modules($modules);
				if($normalized_modules!==[]){
					$normalized['modules']=$normalized_modules;
				}
			}
			if(isset($definition['bootstrap']) && is_string($definition['bootstrap']) && trim($definition['bootstrap'])!==''){
				$normalized['bootstrap']=trim($definition['bootstrap']);
			}
			if($normalized!==[]){
				return $normalized;
			}
		}
		throw new \RuntimeException('Route middleware definition is invalid or unsupported.');
	}

	private function normalize_middleware_string(string $definition): array {
		$definition=trim($definition);
		if($definition===''){
			throw new \RuntimeException('Route middleware definition cannot be empty.');
		}
		if(str_contains($definition, '\\')){
			return ['class'=>trim($definition, '\\')];
		}
		[$alias, $parameter_string]=array_pad(explode(':', $definition, 2), 2, null);
		$normalized=['alias'=>trim($alias)];
		if($parameter_string!==null && trim($parameter_string)!==''){
			$normalized['parameters']=$this->normalize_middleware_parameters(explode(',', $parameter_string));
		}
		return $normalized;
	}

	private function normalize_middleware_parameters(mixed $parameters): array {
		if(!is_array($parameters)){
			$parameters=[$parameters];
		}
		$normalized=[];
		foreach($parameters as $parameter){
			$normalized[]=$parameter;
		}
		return $normalized;
	}

	private function normalize_middleware_modules(mixed $modules): array {
		if(!is_array($modules)){
			$modules=[$modules];
		}
		$normalized=[];
		foreach($modules as $module){
			$module=strtolower(trim((string)$module));
			if($module===''){
				continue;
			}
			$normalized[$module]=$module;
		}
		return array_values($normalized);
	}

	private static function is_list(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}
}
