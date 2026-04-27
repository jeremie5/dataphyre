<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

final class SecurityScheme {

	private string $name;
	private array $definition;

	private function __construct(string $name, array $definition){
		$this->name=trim($name);
		$this->definition=$definition;
	}

	public static function jwtGuard(string $guard='jwt', string $name='jwtAuth', array $options=[]): self {
		return self::guard($name, [$guard], [
			'type'=>'http',
			'scheme'=>'bearer',
			'bearerFormat'=>'JWT',
		], $options);
	}

	public static function guard(
		string $name,
		array|string $guards,
		array $openapi=[],
		array $options=[]
	): self {
		$guards=is_array($guards) ? $guards : [$guards];
		$guards=array_values(array_filter(array_map(
			static fn(string $guard): string => trim($guard),
			array_map('strval', $guards)
		), static fn(string $guard): bool => $guard!==''));
		$definition=[
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>[
				'type'=>'guard',
				'guards'=>$guards,
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'Authentication is required for this endpoint.'),
			],
			'openapi'=>array_replace(self::defaultGuardOpenApi($guards), $openapi),
		];
		if(isset($options['description']) && is_string($options['description']) && trim($options['description'])!==''){
			$definition['openapi']['description']=trim($options['description']);
		}
		return new self($name, $definition);
	}

	public static function bearer(string $name='bearerAuth', array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>[
				'type'=>'bearer',
				'resolver'=>self::normalizeResolver($options['resolver'] ?? null),
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'A valid bearer token is required for this endpoint.'),
			],
			'openapi'=>array_filter([
				'type'=>'http',
				'scheme'=>'bearer',
				'bearerFormat'=>isset($options['bearer_format']) ? (string)$options['bearer_format'] : null,
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	public static function basic(string $name='basicAuth', array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>[
				'type'=>'basic',
				'resolver'=>self::normalizeResolver($options['resolver'] ?? null),
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'Valid basic authentication credentials are required for this endpoint.'),
			],
			'openapi'=>array_filter([
				'type'=>'http',
				'scheme'=>'basic',
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	public static function apiKey(
		string $name,
		string $parameter,
		string $location='header',
		array $options=[]
	): self {
		$location=strtolower(trim($location));
		if(!in_array($location, ['header', 'query', 'cookie'], true)){
			$location='header';
		}
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>[
				'type'=>'api_key',
				'location'=>$location,
				'parameter'=>trim($parameter),
				'resolver'=>self::normalizeResolver($options['resolver'] ?? null),
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'A valid API key is required for this endpoint.'),
			],
			'openapi'=>array_filter([
				'type'=>'apiKey',
				'in'=>$location,
				'name'=>trim($parameter),
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	public static function oauth2(string $name, array $flows, array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>self::runtimeForDocumentationDrivenScheme($options),
			'openapi'=>array_filter([
				'type'=>'oauth2',
				'flows'=>$flows,
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	public static function openIdConnect(string $name, string $open_id_connect_url, array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>self::runtimeForDocumentationDrivenScheme($options),
			'openapi'=>array_filter([
				'type'=>'openIdConnect',
				'openIdConnectUrl'=>trim($open_id_connect_url),
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	public static function custom(string $name, array $openapi, array $runtime=[], array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>self::normalizeRuntime($runtime),
			'openapi'=>$openapi,
		]);
	}

	public function name(): string {
		return $this->name;
	}

	public function scopes(): array {
		return $this->definition['scopes'] ?? [];
	}

	public function toArray(): array {
		return array_replace($this->definition, [
			'name'=>$this->name,
		]);
	}

	private static function runtimeForDocumentationDrivenScheme(array $options): array {
		if(isset($options['guard'])){
			return [
				'type'=>'guard',
				'guards'=>array_values(array_filter((array)$options['guard'], static fn(mixed $guard): bool => is_string($guard) && trim($guard)!=='')),
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'Authentication is required for this endpoint.'),
			];
		}
		if(isset($options['resolver'])){
			return [
				'type'=>'callback',
				'resolver'=>self::normalizeResolver($options['resolver']),
				'failure_status'=>(int)($options['failure_status'] ?? 401),
				'failure_message'=>(string)($options['failure_message'] ?? 'Authentication is required for this endpoint.'),
			];
		}
		return [
			'type'=>'docs_only',
			'failure_status'=>(int)($options['failure_status'] ?? 401),
			'failure_message'=>(string)($options['failure_message'] ?? 'Authentication is required for this endpoint.'),
		];
	}

	private static function defaultGuardOpenApi(array $guards): array {
		$first_guard=$guards[0] ?? null;
		return match ($first_guard) {
			'jwt' => [
				'type'=>'http',
				'scheme'=>'bearer',
				'bearerFormat'=>'JWT',
			],
			'session', 'access' => [
				'type'=>'apiKey',
				'in'=>'cookie',
				'name'=>'PHPSESSID',
			],
			default => [
				'type'=>'http',
				'scheme'=>'bearer',
			],
		};
	}

	private static function normalizeRuntime(array $runtime): array {
		if(isset($runtime['resolver'])){
			$runtime['resolver']=self::normalizeResolver($runtime['resolver']);
		}
		if(isset($runtime['guards'])){
			$runtime['guards']=array_values(array_filter(array_map(
				static fn(string $guard): string => trim($guard),
				array_map('strval', (array)$runtime['guards'])
			), static fn(string $guard): bool => $guard!==''));
		}
		return $runtime;
	}

	private static function normalizeResolver(mixed $resolver): mixed {
		if($resolver===null){
			return null;
		}
		if(is_string($resolver) && trim($resolver)!==''){
			return trim($resolver);
		}
		if(
			is_array($resolver)
			&& count($resolver)===2
			&& is_string($resolver[0])
			&& is_string($resolver[1])
		){
			return [trim($resolver[0], '\\'), trim($resolver[1])];
		}
		throw new \InvalidArgumentException('API security resolver must be a callable string or a [class, method] array.');
	}

	private static function normalizeScopes(array|string $scopes): array {
		$scopes=is_array($scopes) ? $scopes : [$scopes];
		$normalized=[];
		foreach($scopes as $scope){
			$scope=trim((string)$scope);
			if($scope===''){
				continue;
			}
			$normalized[$scope]=$scope;
		}
		return array_values($normalized);
	}
}
