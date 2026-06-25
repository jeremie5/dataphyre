<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

/**
 * API security definition shared by runtime guards and OpenAPI generation.
 *
 * A scheme carries three coordinated surfaces: normalized scope names,
 * runtime enforcement metadata, and the OpenAPI security-scheme fragment. The
 * factories cover common authentication patterns while keeping custom schemes
 * possible for applications with their own resolver or documentation model.
 * Runtime resolvers and guard names are trusted application configuration: this
 * value object normalizes their shape but does not execute or authorize them.
 */
final class SecurityScheme {

	/** @var string OpenAPI security scheme name. */
	private string $name;

	/** @var array<string, mixed> Normalized scheme definition with scopes, runtime, and openapi sections. */
	private array $definition;

	/** @var ?array<string, mixed> Cached OpenAPI/runtime serialization payload. */
	private ?array $arrayPayload=null;
	private static mixed $lastGuardListInput=null;
	private static ?array $lastGuardListOutput=null;
	private static mixed $lastScopeListInput=null;
	private static ?array $lastScopeListOutput=null;

	/**
	 * Stores a normalized security scheme definition.
	 *
	 * @param string $name Security scheme name used in OpenAPI components and route requirements.
	 * @param array<string, mixed> $definition Scheme data.
	 */
	private function __construct(string $name, array $definition){
		$this->name=trim($name);
		$this->definition=$definition;
	}

	/**
	 * Creates a bearer-JWT scheme backed by a Dataphyre guard.
	 *
	 * @param string $guard Runtime guard name to evaluate.
	 * @param string $name Security scheme name exposed to OpenAPI.
	 * @param array<string, mixed> $options Optional scopes, description, and failure response metadata.
	 * @return self Guard scheme with HTTP bearer JWT OpenAPI metadata.
	 */
	public static function jwtGuard(string $guard='jwt', string $name='jwtAuth', array $options=[]): self {
		return self::guard($name, [$guard], [
			'type'=>'http',
			'scheme'=>'bearer',
			'bearerFormat'=>'JWT',
		], $options);
	}

	/**
	 * Creates a scheme enforced by one or more runtime guards.
	 *
	 * Guard names are trimmed and empty names are discarded. OpenAPI defaults
	 * are inferred from the first guard unless the caller supplies overrides.
	 *
	 * @param string $name Security scheme name.
	 * @param array<int, string>|string $guards Runtime guard name or ordered guard list.
	 * @param array<string, mixed> $openapi OpenAPI security-scheme overrides.
	 * @param array<string, mixed> $options Optional scopes, description, and failure response metadata.
	 * @return self Guard-backed security scheme.
	 */
	public static function guard(
		string $name,
		array|string $guards,
		array $openapi=[],
		array $options=[]
	): self {
		$guards=self::normalizeGuards($guards);
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

	/**
	 * Creates a bearer-token scheme backed by an optional resolver.
	 *
	 * Resolver values may be callable strings or `[class, method]` arrays.
	 * Without a resolver, runtime authorization for this scheme fails closed while
	 * the OpenAPI bearer description remains available.
	 *
	 * @param string $name Security scheme name.
	 * @param array<string, mixed> $options Optional resolver, scopes, bearer format, description, and failure metadata.
	 * @return self Bearer-token security scheme.
	 */
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

	/**
	 * Creates an HTTP Basic authentication scheme.
	 *
	 * Runtime enforcement is delegated to an optional resolver; otherwise the
	 * scheme can still describe a documented basic-auth requirement.
	 *
	 * @param string $name Security scheme name.
	 * @param array<string, mixed> $options Optional resolver, scopes, description, and failure metadata.
	 * @return self Basic-auth security scheme.
	 */
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

	/**
	 * Creates an API-key scheme for header, query, or cookie extraction.
	 *
	 * Invalid locations normalize to `header`, matching the safest and most
	 * common API-key transport.
	 * Parameter names are stored as supplied after trimming; applications should
	 * avoid deriving them from untrusted input.
	 *
	 * @param string $name Security scheme name.
	 * @param string $parameter Header, query parameter, or cookie name carrying the key.
	 * @param string $location OpenAPI API-key location: `header`, `query`, or `cookie`.
	 * @param array<string, mixed> $options Optional resolver, scopes, description, and failure metadata.
	 * @return self API-key security scheme.
	 */
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

	/**
	 * Creates an OAuth2 OpenAPI scheme with optional runtime metadata.
	 *
	 * OAuth2 flows are primarily documentation-driven unless options provide a
	 * guard or resolver for runtime enforcement.
	 *
	 * @param string $name Security scheme name.
	 * @param array<string, mixed> $flows OpenAPI OAuth2 flow definitions.
	 * @param array<string, mixed> $options Optional guard, resolver, scopes, description, and failure metadata.
	 * @return self OAuth2 security scheme.
	 */
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

	/**
	 * Creates an OpenID Connect OpenAPI scheme with optional runtime metadata.
	 *
	 * Like OAuth2, OpenID Connect can be docs-only or paired with a guard or
	 * resolver supplied through options.
	 *
	 * @param string $name Security scheme name.
	 * @param string $openIdConnectUrl OpenID Connect discovery URL.
	 * @param array<string, mixed> $options Optional guard, resolver, scopes, description, and failure metadata.
	 * @return self OpenID Connect security scheme.
	 */
	public static function openIdConnect(string $name, string $openIdConnectUrl, array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>self::runtimeForDocumentationDrivenScheme($options),
			'openapi'=>array_filter([
				'type'=>'openIdConnect',
				'openIdConnectUrl'=>trim($openIdConnectUrl),
				'description'=>isset($options['description']) ? trim((string)$options['description']) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
		]);
	}

	/**
	 * Creates a custom security scheme from explicit OpenAPI and runtime data.
	 *
	 * Runtime data is lightly normalized for resolver and guard fields, but
	 * the OpenAPI fragment is preserved as supplied for advanced integrations.
	 *
	 * @param string $name Security scheme name.
	 * @param array<string, mixed> $openapi OpenAPI security-scheme fragment.
	 * @param array<string, mixed> $runtime Runtime enforcement metadata.
	 * @param array<string, mixed> $options Optional scopes.
	 * @return self Custom security scheme.
	 */
	public static function custom(string $name, array $openapi, array $runtime=[], array $options=[]): self {
		return new self($name, [
			'scopes'=>self::normalizeScopes($options['scopes'] ?? []),
			'runtime'=>self::normalizeRuntime($runtime),
			'openapi'=>$openapi,
		]);
	}

	/**
	 * Returns the OpenAPI/runtime security scheme name.
	 *
	 * @return string Trimmed scheme name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns normalized scope names associated with the scheme.
	 *
	 *
	 * @return array<int, string> Unique non-empty scope names.
	 */
	public function scopes(): array {
		return $this->definition['scopes'] ?? [];
	}

	/**
	 * Serializes the scheme for OpenAPI generation and runtime route metadata.
	 *
	 * @return array<string, mixed> Scheme data with `name`, `scopes`, `runtime`, and `openapi` sections.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= array_replace($this->definition, [
			'name'=>$this->name,
		]);
	}

	/**
	 * Builds runtime metadata for schemes whose OpenAPI model is primary.
	 *
	 * @param array<string, mixed> $options Options that may provide `guard`, `resolver`, failure status, or failure message.
	 * @return array<string, mixed> Guard, callback, or docs-only runtime data.
	 */
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

	/**
	 * Infers an OpenAPI security scheme fragment from the first runtime guard.
	 *
	 * @param array<int, string> $guards Normalized guard names.
	 * @return array<string, mixed> Default OpenAPI fragment for the guard family.
	 */
	private static function defaultGuardOpenApi(array $guards): array {
		$firstGuard=$guards[0] ?? null;
		return match ($firstGuard) {
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

	/**
	 * Normalizes custom runtime enforcement metadata.
	 *
	 * @param array<string, mixed> $runtime Runtime data supplied by callers.
	 * @return array<string, mixed> Runtime data with resolver and guard list normalized.
	 */
	private static function normalizeRuntime(array $runtime): array {
		if(isset($runtime['resolver'])){
			$runtime['resolver']=self::normalizeResolver($runtime['resolver']);
		}
		if(isset($runtime['guards'])){
			$runtime['guards']=self::normalizeGuards((array)$runtime['guards']);
		}
		return $runtime;
	}

	/**
	 * Normalizes runtime guard names while preserving order and duplicates.
	 *
	 * @param array<int|string, mixed>|string $guards Guard name or guard list.
	 * @return array<int, string> Trimmed non-empty guard names.
	 */
	private static function normalizeGuards(array|string $guards): array {
		if(self::$lastGuardListInput===$guards && self::$lastGuardListOutput!==null){
			return self::$lastGuardListOutput;
		}
		$input=$guards;
		$guards=is_array($guards) ? $guards : [$guards];
		$normalized=[];
		foreach($guards as $guard){
			$guard=trim((string)$guard);
			if($guard!==''){
				$normalized[]=$guard;
			}
		}
		self::$lastGuardListInput=$input;
		return self::$lastGuardListOutput=$normalized;
	}

	/**
	 * Normalizes resolver declarations accepted by runtime security checks.
	 *
	 * @param mixed $resolver Candidate resolver declaration.
	 * @return mixed `null`, callable string, or `[class, method]` pair.
	 * @throws \InvalidArgumentException When the resolver cannot be represented safely.
	 */
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

	/**
	 * Normalizes a scope list while preserving first occurrence order.
	 *
	 * @param array<int|string, mixed>|string $scopes Candidate scope names.
	 * @return array<int, string> Unique non-empty scope names.
	 */
	private static function normalizeScopes(array|string $scopes): array {
		if(self::$lastScopeListInput===$scopes && self::$lastScopeListOutput!==null){
			return self::$lastScopeListOutput;
		}
		$input=$scopes;
		$scopes=is_array($scopes) ? $scopes : [$scopes];
		$normalized=[];
		foreach($scopes as $scope){
			$scope=trim((string)$scope);
			if($scope===''){
				continue;
			}
			$normalized[$scope]=$scope;
		}
		self::$lastScopeListInput=$input;
		return self::$lastScopeListOutput=array_values($normalized);
	}
}
