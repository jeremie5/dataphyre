<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Http\Request;
use Dataphyre\Sanitation\SanitizationResult;

final class ApiContext {

	private ?SanitizationResult $validation_result=null;
	private array $bindings=[];
	private array $binding_trace=[];

	public function __construct(
		private readonly Request $request,
		private readonly array $route
	){}

	public function request(): Request {
		return $this->request;
	}

	public function route(): array {
		return $this->route;
	}

	public function method(): string {
		return $this->request->method();
	}

	public function path(): string {
		return $this->request->path();
	}

	public function parameters(?string $key=null, mixed $default=null): mixed {
		$parameters=$this->request->route_parameters();
		if($key===null){
			return $parameters;
		}
		$value=$this->pathValue($parameters, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function query(?string $key=null, mixed $default=null): mixed {
		return $this->request->query($key, $default);
	}

	public function body(?string $key=null, mixed $default=null): mixed {
		return $this->request->input($key, $default);
	}

	public function input(?string $key=null, mixed $default=null, array|string|null $sources=null): mixed {
		$input=$this->all($sources);
		if($key===null){
			return $input;
		}
		$value=$this->pathValue($input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function all(array|string|null $sources=null): array {
		return $this->mergeSources($this->normalizeSources($sources));
	}

	public function cookie(?string $key=null, mixed $default=null): mixed {
		return $this->request->cookie($key, $default);
	}

	public function header(?string $name=null, mixed $default=null): mixed {
		if($name===null){
			return $this->request->headers();
		}
		return $this->request->header($name, $default);
	}

	public function server(?string $key=null, mixed $default=null): mixed {
		return $this->request->server($key, $default);
	}

	public function validate(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		$this->ensureSanitationFramework();
		$sources=$this->normalizeSources($options['sources'] ?? null);
		$sanitation_options=$this->extractSanitationOptions($options);
		$result=\Dataphyre\Sanitation\Sanitation::schema(
			$this->all($sources),
			$schema,
			$defaults,
			$sanitation_options
		);
		$this->validation_result=$result;
		return $result;
	}

	public function validated(?string $key=null, mixed $default=null): mixed {
		if($this->validation_result===null){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $this->validation_result->validated();
		}
		return $this->validation_result->get($key, $default);
	}

	public function validation(): ?SanitizationResult {
		return $this->validation_result;
	}

	public function hasValidatedInput(): bool {
		return $this->validation_result instanceof SanitizationResult;
	}

	public function withValidationResult(SanitizationResult $result): self {
		$this->validation_result=$result;
		return $this;
	}

	public function bindings(): array {
		return $this->bindings;
	}

	public function binding(string $path, mixed $default=null): mixed {
		$value=$this->pathValue($this->bindings, $path);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function hasBinding(string $path): bool {
		return $this->pathValue($this->bindings, $path)['present']===true;
	}

	public function bindingTrace(): array {
		return $this->binding_trace;
	}

	public function withBindings(array $bindings, array $binding_trace=[]): self {
		$this->bindings=$bindings;
		$this->binding_trace=$binding_trace;
		return $this;
	}

	public function auth(): array {
		$auth=$this->request->attribute('dataphyre_api_auth', []);
		return is_array($auth) ? $auth : [];
	}

	public function hasAuth(): bool {
		return ($this->auth()['authorized'] ?? false)===true;
	}

	public function authScheme(): ?string {
		$scheme=$this->auth()['scheme'] ?? null;
		return is_string($scheme) && trim($scheme)!=='' ? trim($scheme) : null;
	}

	public function authIdentity(mixed $default=null): mixed {
		$auth=$this->auth();
		return array_key_exists('identity', $auth) ? $auth['identity'] : $default;
	}

	public function authScopes(): array {
		$scopes=$this->auth()['scopes'] ?? [];
		return is_array($scopes) ? $scopes : [];
	}

	public function authContext(?string $key=null, mixed $default=null): mixed {
		$context=$this->auth()['context'] ?? [];
		if(!is_array($context)){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $context;
		}
		$value=$this->pathValue($context, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function authMeta(?string $key=null, mixed $default=null): mixed {
		$meta=$this->auth()['meta'] ?? [];
		if(!is_array($meta)){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $meta;
		}
		$value=$this->pathValue($meta, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function dispatch(array $request, array $options=[]): array {
		return Api::dispatch($request, $this->dispatchDefaults($options));
	}

	public function dispatchBatch(array $requests, array $options=[]): array {
		return Api::dispatchBatch($requests, $this->dispatchDefaults($options));
	}

	public function dispatchChain(array $requests, array $options=[]): array {
		return Api::dispatchChain($requests, $this->dispatchDefaults($options));
	}

	private function mergeSources(array $sources): array {
		$merged=[];
		foreach($sources as $source){
			$payload=match ($source) {
				'query' => $this->request->query(),
				'body' => $this->request->input(),
				'route' => $this->request->route_parameters(),
				'cookies' => $this->request->cookie(),
				'headers' => $this->request->headers(),
				'server' => $this->request->server(),
				default => [],
			};
			if(is_array($payload)===false){
				continue;
			}
			$merged=array_replace_recursive($merged, $payload);
		}
		return $merged;
	}

	private function normalizeSources(array|string|null $sources): array {
		if($sources===null){
			return ['query', 'body', 'route'];
		}
		if(is_string($sources)){
			$sources=[$sources];
		}
		$normalized=[];
		foreach($sources as $source){
			$source=strtolower(trim((string)$source));
			if($source===''){
				continue;
			}
			if(!in_array($source, ['route', 'query', 'body', 'cookies', 'headers', 'server'], true)){
				continue;
			}
			$normalized[$source]=$source;
		}
		return $normalized!==[] ? array_values($normalized) : ['query', 'body', 'route'];
	}

	private function extractSanitationOptions(array $options): array {
		unset($options['sources'], $options['status'], $options['message'], $options['headers']);
		return $options;
	}

	private function ensureSanitationFramework(): void {
		if(class_exists('Dataphyre\\Sanitation\\Sanitation')){
			return;
		}
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('sanitation');
		}
		if(class_exists('Dataphyre\\Sanitation\\Sanitation')===false){
			throw new \RuntimeException('Dataphyre sanitation is required for API schema validation.');
		}
	}

	private function pathValue(array $source, string $path): array {
		if($path==='' || str_contains($path, '.')===false){
			return [
				'present'=>array_key_exists($path, $source),
				'value'=>array_key_exists($path, $source) ? $source[$path] : null,
			];
		}
		$current=$source;
		foreach(explode('.', $path) as $segment){
			if(is_array($current)===false || array_key_exists($segment, $current)===false){
				return [
					'present'=>false,
					'value'=>null,
				];
			}
			$current=$current[$segment];
		}
		return [
			'present'=>true,
			'value'=>$current,
		];
	}

	public function bindingData(): array {
		return [
			'request'=>[
				'method'=>$this->method(),
				'path'=>$this->path(),
			],
			'route'=>$this->request->route_parameters(),
			'query'=>$this->request->query(),
			'body'=>$this->request->input(),
			'input'=>$this->all(),
			'validated'=>$this->validated(),
			'auth'=>$this->auth(),
			'bindings'=>$this->bindings,
		];
	}

	private function dispatchDefaults(array $options): array {
		$route_dispatch=is_array($this->route['api']['dispatch'] ?? null) ? $this->route['api']['dispatch'] : [];
		return array_replace($route_dispatch, [
			'base_request'=>$this->request,
			'auth'=>$this->hasAuth() ? $this->auth() : null,
			'trust_auth'=>$this->hasAuth(),
		], $options);
	}
}
