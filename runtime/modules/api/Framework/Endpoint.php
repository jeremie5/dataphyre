<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Routing\CompilableRoute;
use Dataphyre\Routing\Route;

final class Endpoint implements CompilableRoute {

	private array $methods;
	private string $path;
	private mixed $handler;
	private array $middleware=[];
	private array $tags=[];
	private array $aliases=[];
	private ?string $summary=null;
	private ?string $description=null;
	private ?string $operation_id=null;
	private bool $deprecated=false;
	private array $parameters=[];
	private ?array $request_body=null;
	private array $responses=[];
	private array $security_schemes=[];
	private array $security=[];
	private array $servers=[];
	private ?array $execution=null;
	private ?array $schema_definition=null;
	private ?array $trace_definition=null;
	private ?array $cache_definition=null;
	private ?array $profile_definition=null;
	private ?array $dispatch_definition=null;
	private array $bindings=[];
	private array $lifecycle=[
		'before'=>[],
		'after'=>[],
		'error'=>[],
	];

	private function __construct(array $methods, string $path, mixed $handler=null){
		$this->methods=array_values(array_unique(array_map(
			static fn(string $method): string => strtoupper(trim($method)),
			array_map('strval', $methods)
		)));
		$this->path=self::normalizePath($path);
		$this->handler=$handler;
	}

	public static function methods(array|string $methods, string $path, mixed $handler=null): self {
		return new self((array)$methods, $path, $handler);
	}

	public static function get(string $path, mixed $handler=null): self {
		return new self(['GET'], $path, $handler);
	}

	public static function post(string $path, mixed $handler=null): self {
		return new self(['POST'], $path, $handler);
	}

	public static function put(string $path, mixed $handler=null): self {
		return new self(['PUT'], $path, $handler);
	}

	public static function patch(string $path, mixed $handler=null): self {
		return new self(['PATCH'], $path, $handler);
	}

	public static function delete(string $path, mixed $handler=null): self {
		return new self(['DELETE'], $path, $handler);
	}

	public static function any(string $path, mixed $handler=null): self {
		return new self(['ANY'], $path, $handler);
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

	public function alias(string $alias): self {
		$alias=self::normalizeAlias($alias);
		if($alias===''){
			throw new \RuntimeException('API endpoint alias cannot be empty.');
		}
		$this->aliases[$alias]=$alias;
		return $this;
	}

	public function aliases(array|string ...$aliases): self {
		foreach($aliases as $alias){
			if(is_array($alias)){
				$this->aliases(...$alias);
				continue;
			}
			$normalized=self::normalizeAlias((string)$alias);
			if($normalized===''){
				continue;
			}
			$this->aliases[$normalized]=$normalized;
		}
		return $this;
	}

	public function summary(string $summary): self {
		$this->summary=trim($summary);
		return $this;
	}

	public function description(string $description): self {
		$this->description=trim($description);
		return $this;
	}

	public function operationId(string $operation_id): self {
		$this->operation_id=trim($operation_id);
		return $this;
	}

	public function deprecated(bool $deprecated=true): self {
		$this->deprecated=$deprecated;
		return $this;
	}

	public function parameter(string $name, string $in, array $schema=[], array $options=[]): self {
		$this->parameters[]=$this->normalizeParameter($name, $in, $schema, $options);
		return $this;
	}

	public function pathParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'path', $schema, $options);
	}

	public function queryParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'query', $schema, $options);
	}

	public function headerParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'header', $schema, $options);
	}

	public function cookieParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'cookie', $schema, $options);
	}

	public function requestBody(array $content, bool $required=false, ?string $description=null): self {
		$this->request_body=array_filter([
			'required'=>$required,
			'description'=>$description!==null && trim($description)!=='' ? trim($description) : null,
			'content'=>$content,
		], static fn(mixed $value): bool => $value!==null);
		return $this;
	}

	public function jsonBody(array $schema, bool $required=false, ?string $description=null): self {
		return $this->requestBody([
			'application/json'=>[
				'schema'=>$schema,
			],
		], $required, $description);
	}

	public function response(int|string $status, array $definition): self {
		$this->responses[(string)$status]=$definition;
		return $this;
	}

	public function jsonResponse(int|string $status, array $schema, string $description='OK'): self {
		return $this->response($status, [
			'description'=>$description,
			'content'=>[
				'application/json'=>[
					'schema'=>$schema,
				],
			],
		]);
	}

	public function auth(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$compiled=$scheme->toArray();
			$this->security_schemes[$compiled['name']]=$compiled;
			$this->security[]=[
				$compiled['name']=>$compiled['scopes'] ?? [],
			];
		}
		return $this;
	}

	public function authAll(SecurityScheme ...$schemes): self {
		$requirement=[];
		foreach($schemes as $scheme){
			$compiled=$scheme->toArray();
			$this->security_schemes[$compiled['name']]=$compiled;
			$requirement[$compiled['name']]=$compiled['scopes'] ?? [];
		}
		if($requirement!==[]){
			$this->security[]=$requirement;
		}
		return $this;
	}

	public function server(string $url, ?string $description=null): self {
		$server=['url'=>trim($url)];
		if($description!==null && trim($description)!==''){
			$server['description']=trim($description);
		}
		$this->servers[]=$server;
		return $this;
	}

	public function execute(mixed $target, array $options=[]): self {
		$this->execution=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	public function withBinding(string $path, mixed $target, array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeCallableBindingDefinition($path, $target, $options),
		];
		return $this;
	}

	public function withBindings(array $bindings): self {
		foreach($bindings as $path=>$binding){
			if(is_string($path)===false || trim($path)===''){
				continue;
			}
			if(is_array($binding) && array_key_exists('target', $binding)){
				$options=$binding;
				$target=$options['target'];
				unset($options['target']);
				$this->withBinding($path, $target, $options);
				continue;
			}
			$this->withBinding($path, $binding);
		}
		return $this;
	}

	public function withQuery(string $path, object $query, string $mode='records', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeQueryBindingDefinition($path, $query, $mode, $options, false),
		];
		return $this;
	}

	public function withQueryIdentity(string $path, object $query, string $mode='records', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeQueryBindingDefinition($path, $query, $mode, $options, true),
		];
		return $this;
	}

	public function withSearch(string $path, object $query, string $mode='results', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeSearchBindingDefinition($path, $query, $mode, $options, false),
		];
		return $this;
	}

	public function withSearchIdentity(string $path, object $query, string $mode='results', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeSearchBindingDefinition($path, $query, $mode, $options, true),
		];
		return $this;
	}

	public function schema(array $schema, array $defaults=[], array $options=[]): self {
		$this->schema_definition=[
			'rules'=>$schema,
			'defaults'=>$defaults,
			'options'=>$options,
		];
		return $this;
	}

	public function withTrace(bool $enabled=true, array $options=[]): self {
		$options['enabled']=$enabled;
		$this->trace_definition=$options;
		return $this;
	}

	public function cache(int|float|string $ttl=300, array $options=[]): self {
		$ttl=max(1, (int)$ttl);
		$normalized=$this->normalizeStaticOptions($options, 'API cache options must be composed of scalar, null, or array values.');
		$names=$normalized['names'] ?? [];
		unset($normalized['names']);
		$this->cache_definition=array_replace([
			'ttl'=>$ttl,
			'names'=>$this->normalizeCacheNames($names),
		], $normalized);
		return $this;
	}

	public function profile(string $name, array $options=[]): self {
		$name=trim($name);
		if($name===''){
			throw new \RuntimeException('API profile name cannot be empty.');
		}
		$this->profile_definition=array_replace([
			'name'=>$name,
		], $this->normalizeStaticOptions($options, 'API profile options must be composed of scalar, null, or array values.'));
		return $this;
	}

	public function dispatchDefaults(array $options): self {
		$this->dispatch_definition=array_replace(
			$this->dispatch_definition ?? [],
			$this->normalizeStaticOptions($options, 'API dispatch defaults must be composed of scalar, null, or array values.')
		);
		return $this;
	}

	public function beforeExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['before'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	public function afterExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['after'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	public function onError(mixed $target, array $options=[]): self {
		$this->lifecycle['error'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	public function compile(): array {
		$handler=$this->compileRouteHandler();
		$route=Route::methods($this->methods, $this->path, $handler);
		if($this->middleware!==[]){
			$route->middleware(...$this->middleware);
		}
		$compiled=$route->compile();
		$compiled['path_template']=$this->path;
		$compiled['api']=$this->compileApiMetadata();
		return $compiled;
	}

	private function compileApiMetadata(): array {
		$parameters=$this->mergeInferredPathParameters($this->parameters);
		$responses=$this->responses!==[]
			? $this->responses
			: ['200'=>['description'=>'OK']];
		return array_filter([
			'path'=>$this->path,
			'methods'=>$this->methods,
			'tags'=>array_values($this->tags),
			'aliases'=>array_values($this->aliases),
			'summary'=>$this->summary,
			'description'=>$this->description,
			'operation_id'=>$this->operation_id,
			'deprecated'=>$this->deprecated,
			'parameters'=>$parameters,
			'request_body'=>$this->request_body,
			'responses'=>$responses,
			'security_schemes'=>$this->security_schemes,
			'security'=>$this->security,
			'servers'=>$this->servers,
			'execution'=>$this->execution,
			'bindings'=>$this->compileBindings(),
			'lifecycle'=>$this->compileLifecycle(),
			'schema'=>$this->schema_definition,
			'trace'=>$this->trace_definition,
			'cache'=>$this->cache_definition,
			'profile'=>$this->profile_definition,
			'dispatch'=>$this->dispatch_definition,
		], static fn(mixed $value): bool => $value!==null && $value!==[] && $value!=='');
	}

	private function compileRouteHandler(): mixed {
		if($this->execution!==null){
			return ['type'=>'api'];
		}
		if($this->handler!==null){
			return $this->handler;
		}
		throw new \RuntimeException('API endpoint requires either a route handler or an execute target.');
	}

	private function mergeInferredPathParameters(array $parameters): array {
		$documented=[];
		foreach($parameters as $parameter){
			if(($parameter['in'] ?? null)!=='path'){
				continue;
			}
			$documented[(string)($parameter['name'] ?? '')]=true;
		}
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->path, $matches)!==1){
			return $parameters;
		}
		foreach($matches[1] as $name){
			if(isset($documented[$name])){
				continue;
			}
			$parameters[]=$this->normalizeParameter($name, 'path', ['type'=>'string'], ['required'=>true]);
		}
		return $parameters;
	}

	private function normalizeParameter(string $name, string $in, array $schema, array $options): array {
		$parameter=[
			'name'=>trim($name),
			'in'=>strtolower(trim($in)),
			'schema'=>$schema!==[] ? $schema : ['type'=>'string'],
		];
		if($parameter['in']==='path'){
			$parameter['required']=true;
		}elseif(array_key_exists('required', $options)){
			$parameter['required']=$options['required']===true;
		}
		if(isset($options['description']) && trim((string)$options['description'])!==''){
			$parameter['description']=trim((string)$options['description']);
		}
		foreach(['deprecated', 'allowEmptyValue', 'explode'] as $key){
			if(array_key_exists($key, $options)){
				$parameter[$key]=$options[$key]===true;
			}
		}
		if(isset($options['example'])){
			$parameter['example']=$options['example'];
		}
		if(isset($options['examples']) && is_array($options['examples'])){
			$parameter['examples']=$options['examples'];
		}
		if(isset($options['style']) && trim((string)$options['style'])!==''){
			$parameter['style']=trim((string)$options['style']);
		}
		return $parameter;
	}

	private function normalizeExecutionTarget(mixed $target, array $options): array {
		$normalized=[
			'bootstrap'=>isset($options['bootstrap']) && is_string($options['bootstrap']) && trim($options['bootstrap'])!==''
				? trim($options['bootstrap'])
				: null,
		];
		if(is_string($target)){
			$target=trim($target);
			if($target===''){
				throw new \RuntimeException('API execute target cannot be empty.');
			}
			if(str_contains($target, '::')){
				[$class, $method]=array_pad(explode('::', $target, 2), 2, null);
				$class=trim((string)$class, '\\');
				$method=trim((string)$method);
				if($class==='' || $method===''){
					throw new \RuntimeException('API execute target must use a valid Class::method reference.');
				}
				return array_filter($normalized+[
					'type'=>'class_method',
					'class'=>$class,
					'method'=>$method,
					'static'=>true,
				], static fn(mixed $value): bool => $value!==null);
			}
			return array_filter($normalized+[
				'type'=>'callable',
				'reference'=>$target,
			], static fn(mixed $value): bool => $value!==null);
		}
		if(
			is_array($target)
			&& array_keys($target)===range(0, count($target)-1)
			&& count($target)===2
			&& is_string($target[0])
			&& is_string($target[1])
		){
			$class=trim($target[0], '\\');
			$method=trim($target[1]);
			if($class==='' || $method===''){
				throw new \RuntimeException('API execute target must use a valid [Class, method] reference.');
			}
			return array_filter($normalized+[
				'type'=>'class_method',
				'class'=>$class,
				'method'=>$method,
				'static'=>true,
			], static fn(mixed $value): bool => $value!==null);
		}
		if(is_array($target)){
			$class=trim((string)($target['class'] ?? ''), '\\');
			$method=trim((string)($target['method'] ?? ''));
			$reference=isset($target['reference']) ? trim((string)$target['reference']) : '';
			$bootstrap=$target['bootstrap'] ?? ($normalized['bootstrap'] ?? null);
			$normalized['bootstrap']=is_string($bootstrap) && trim($bootstrap)!==''
				? trim($bootstrap)
				: null;
			if($class!=='' && $method!==''){
				return array_filter($normalized+[
					'type'=>'class_method',
					'class'=>$class,
					'method'=>$method,
					'static'=>($target['static'] ?? true)===true,
				], static fn(mixed $value): bool => $value!==null);
			}
			if($reference!==''){
				return array_filter($normalized+[
					'type'=>'callable',
					'reference'=>$reference,
				], static fn(mixed $value): bool => $value!==null);
			}
		}
		throw new \RuntimeException('API execute target must be a callable string, a Class::method reference, or a compiled callable definition.');
	}

	private function normalizeCallableBindingDefinition(string $path, mixed $target, array $options): array {
		$normalized=[
			'type'=>'callable',
			'target'=>$this->normalizeExecutionTarget($target, $options),
		];
		$identity=$options['identity'] ?? null;
		if($identity!==null){
			$normalized['identity']=$this->normalizeBindingValue($identity, true);
		}
		return $normalized;
	}

	private function normalizeQueryBindingDefinition(string $path, object $query, string $mode, array $options, bool $inherit_identity): array {
		if(!method_exists($query, 'executionState')){
			throw new \RuntimeException("API SQL binding '{$path}' requires a query object with executionState().");
		}
		return [
			'type'=>'sql_query',
			'mode'=>trim($mode)!=='' ? trim($mode) : 'records',
			'query_class'=>$query::class,
			'query_state'=>$this->normalizeBindingValue($query->executionState()),
			'inherit_query_identity'=>$inherit_identity,
			'options'=>$this->normalizeBindingValue($options),
		];
	}

	private function normalizeSearchBindingDefinition(string $path, object $query, string $mode, array $options, bool $inherit_identity): array {
		if(!method_exists($query, 'executionState')){
			throw new \RuntimeException("API search binding '{$path}' requires a query object with executionState().");
		}
		return [
			'type'=>'search_query',
			'mode'=>trim($mode)!=='' ? trim($mode) : 'results',
			'query_class'=>$query::class,
			'query_state'=>$this->normalizeBindingValue($query->executionState()),
			'inherit_query_identity'=>$inherit_identity,
			'options'=>$this->normalizeBindingValue($options),
		];
	}

	private function compileBindings(): array {
		$compiled=[];
		foreach($this->bindings as $binding){
			$path=trim((string)($binding['path'] ?? ''));
			$definition=is_array($binding['definition'] ?? null) ? $binding['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}
			$compiled[]=[
				'path'=>$path,
				'definition'=>$definition,
			];
		}
		return $compiled;
	}

	private function compileLifecycle(): array {
		$compiled=[];
		foreach(['before', 'after', 'error'] as $phase){
			$hooks=[];
			foreach(($this->lifecycle[$phase] ?? []) as $target){
				if(!is_array($target)){
					continue;
				}
				$hooks[]=$target;
			}
			if($hooks!==[]){
				$compiled[$phase]=$hooks;
			}
		}
		return $compiled;
	}

	private function normalizeStaticOptions(array $options, string $error_message): array {
		$normalized=[];
		foreach($options as $key=>$value){
			$normalized[$key]=$this->normalizeStaticValue($value, $error_message);
		}
		return $normalized;
	}

	private function normalizeStaticValue(mixed $value, string $error_message): mixed {
		if(is_array($value)){
			$normalized=[];
			foreach($value as $key=>$entry){
				$normalized[$key]=$this->normalizeStaticValue($entry, $error_message);
			}
			return $normalized;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		throw new \RuntimeException($error_message);
	}

	private function normalizeBindingValue(mixed $value, bool $allow_callable_identity=false): mixed {
		if(is_array($value)){
			$normalized=[];
			foreach($value as $key=>$entry){
				$normalized[$key]=$this->normalizeBindingValue($entry, $allow_callable_identity);
			}
			return $normalized;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		if($allow_callable_identity && is_string($value)){
			return trim($value);
		}
		throw new \RuntimeException('API binding options must be composed of scalar, null, or array values.');
	}

	private function normalizeCacheNames(array|string|null $names): array {
		if($names===null){
			return [];
		}
		$names=is_array($names) ? $names : [$names];
		$normalized=[];
		foreach($names as $name){
			if(!is_string($name)){
				continue;
			}
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[$name]=$name;
		}
		return array_values($normalized);
	}

	private static function normalizePath(string $path): string {
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	private static function normalizeAlias(string $alias): string {
		return trim(trim($alias), "/\\");
	}
}
