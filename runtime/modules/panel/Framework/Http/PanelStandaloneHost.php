<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Secure front-controller host for running Panel without MVC or Routing.
 *
 * The host owns exact-prefix routing, bounded request rebuilding, authentication,
 * authorization, rate, origin, CSRF, tenant, response, redirect, and SAPI
 * emission boundaries. Security callbacks are immutable configuration: every
 * mutator returns a clone and missing mandatory policies fail closed.
 */
final class PanelStandaloneHost implements \JsonSerializable {
	private readonly PanelInstance $surface;
	private readonly string $prefix;
	private bool $assetsEnabled=true;
	private bool $uploadsEnabled=false;
	private bool $mutationsEnabled=false;
	private bool $anonymousEnabled=false;
	private bool $development=false;
	private mixed $authenticator=null;
	private mixed $authorizer=null;
	private mixed $rateLimiter=null;
	private mixed $originValidator=null;
	private mixed $csrfIssuer=null;
	private mixed $csrfValidator=null;
	private mixed $tenantResolver=null;
	private mixed $redirectValidator=null;
	private mixed $requestCapturer=null;
	/** @var array<string,int> */
	private array $limits;
	/** @var array<string,string|array<int,string>> */
	private array $securityHeaders=[];

	public function __construct(PanelInstance $surface, string $prefix='/panel', array $limits=[]){
		$this->surface=$surface;
		$this->prefix=PanelStandaloneHostRequestGuard::normalizePrefix($prefix);
		$this->limits=PanelStandaloneHostRequestGuard::normalizeLimits($limits);
	}

	/** Creates a standalone host for an instance, named surface, or the default Panel. */
	public static function surface(PanelInstance|string|null $surface=null, string $prefix='/panel'): self {
		if(!$surface instanceof PanelInstance){
			$name=is_string($surface) && trim($surface)!=='' ? trim($surface) : 'default';
			$surface=Panel::surface($name);
		}
		$prefix=PanelStandaloneHostRequestGuard::normalizePrefix($prefix);
		return new self($surface, $prefix);
	}

	public function panel(): PanelInstance {
		return $this->surface;
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function authenticateUsing(callable $callback): self {
		return $this->with('authenticator', \Closure::fromCallable($callback));
	}

	public function authorizeUsing(callable $callback): self {
		return $this->with('authorizer', \Closure::fromCallable($callback));
	}

	public function rateLimitUsing(callable $callback): self {
		return $this->with('rateLimiter', \Closure::fromCallable($callback));
	}

	public function originUsing(callable $callback): self {
		return $this->with('originValidator', \Closure::fromCallable($callback));
	}

	public function csrfUsing(callable $issuer, callable $validator): self {
		$clone=clone $this;
		$clone->csrfIssuer=\Closure::fromCallable($issuer);
		$clone->csrfValidator=\Closure::fromCallable($validator);
		return $clone;
	}

	public function tenantUsing(callable $callback): self {
		return $this->with('tenantResolver', \Closure::fromCallable($callback));
	}

	/** Explicitly approves redirects not contained by the Panel mount. */
	public function redirectUsing(callable $callback): self {
		return $this->with('redirectValidator', \Closure::fromCallable($callback));
	}

	/**
	 * Replaces SAPI capture with a trusted Request adapter.
	 *
	 * This is useful for workers, alternate servers, and deterministic tests that
	 * need standalone emission without mutating PHP superglobals.
	 */
	public function captureUsing(callable $callback): self {
		return $this->with('requestCapturer', \Closure::fromCallable($callback));
	}

	public function allowAnonymous(bool $enabled=true): self {
		return $this->with('anonymousEnabled', $enabled);
	}

	public function allowAssets(bool $enabled=true): self {
		return $this->with('assetsEnabled', $enabled);
	}

	/** Enabling uploads also enables the mutation security contract. */
	public function allowUploads(bool $enabled=true): self {
		$clone=clone $this;
		$clone->uploadsEnabled=$enabled;
		if($enabled){
			$clone->mutationsEnabled=true;
		}
		return $clone;
	}

	public function allowMutations(bool $enabled=true): self {
		$clone=clone $this;
		$clone->mutationsEnabled=$enabled;
		if(!$enabled){
			$clone->uploadsEnabled=false;
		}
		return $clone;
	}

	public function readOnly(): self {
		return $this->allowMutations(false);
	}

	public function developmentErrors(bool $enabled=true): self {
		return $this->with('development', $enabled);
	}

	/** @param array<string,mixed> $limits */
	public function withLimits(array $limits): self {
		$clone=clone $this;
		$clone->limits=PanelStandaloneHostRequestGuard::normalizeLimits(array_replace($this->limits, $limits));
		return $clone;
	}

	/** @param array<string,string|array<int,string>> $headers */
	public function withSecurityHeaders(array $headers): self {
		$clone=clone $this;
		$clone->securityHeaders=array_replace($this->securityHeaders, $headers);
		return $clone;
	}

	/** Reports exact mount membership without dispatching the request. */
	public function matches(\Dataphyre\Http\Request|string $request): bool {
		return $this->requestGuard()->matches($request);
	}

	/**
	 * Handles one already-captured framework request.
	 *
	 * Requests outside the mount become a no-store 404. `serve()` is the
	 * built-in-server integration that instead returns false outside the mount.
	 */
	public function handle(\Dataphyre\Http\Request $request): \Dataphyre\Http\Response {
		$requestId=PanelErrorEnvelope::correlationId($this->requestIdCandidate($request));
		$context=null;
		try{
			$match=$this->requestGuard()->inspect($request, $this->surface->name());
			if(($match['matched'] ?? false)!==true){
				throw new PanelStandaloneHostException('panel_not_found', 404, 'The requested Panel route was not found.');
			}
			$trusted=$match['request'];
			$routeKind=(string)$match['route_kind'];
			$method=(string)$match['method'];
			$unsafe=($match['unsafe'] ?? false)===true;
			$ability=$this->ability($routeKind, $unsafe);
			$context=new PanelStandaloneHostContext(
				$trusted,
				$this->surface,
				$routeKind,
				$ability,
				$this->prefix,
				$match['segments'],
				$match['asset'],
				$method,
				$unsafe,
				null,
				null,
				$requestId,
			);
			$response=$this->dispatch($context);
			$this->trace($context, $response->status, true);
			return $response;
		}
		catch(PanelStandaloneHostException $exception){
			$response=$this->errorResponse($exception, $requestId);
			$this->trace($context, $response->status, false, $exception->errorCode(), $request);
			return $response;
		}
		catch(\Throwable $exception){
			$failure=new PanelStandaloneHostException(
				'internal_error',
				500,
				'The Panel request could not be completed.',
				[],
				$exception,
			);
			$response=$this->errorResponse($failure, $requestId);
			$this->trace($context, $response->status, false, 'internal_error', $request);
			return $response;
		}
	}

	/** Handles and emits one request through Dataphyre's HTTP response emitter. */
	public function emit(?\Dataphyre\Http\Request $request=null): \Dataphyre\Http\Response {
		try{
			$request ??=$this->capture();
			$response=$this->handle($request);
		}
		catch(PanelStandaloneHostException $exception){
			$response=$this->errorResponse($exception, PanelErrorEnvelope::correlationId());
		}
		catch(\Throwable $exception){
			$response=$this->errorResponse(new PanelStandaloneHostException(
				'internal_error',
				500,
				'The Panel request could not be completed.',
				[],
				$exception,
			), PanelErrorEnvelope::correlationId());
		}
		\Dataphyre\Http\ResponseEmitter::emit($response);
		return $response;
	}

	/**
	 * Built-in PHP router entrypoint.
	 *
	 * Returns false without output when the current request is outside the mount,
	 * allowing PHP's development server to continue normal static-file handling.
	 */
	public function serve(?\Dataphyre\Http\Request $request=null): bool {
		if($request===null){
			$rawPath=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
			if(!$this->matches($rawPath)){
				return false;
			}
		}
		elseif(!$this->matches($request)){
			return false;
		}
		$this->emit($request);
		return true;
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$missing=[];
		if(!$this->anonymousEnabled && !is_callable($this->authenticator)){
			$missing[]='authentication';
		}
		if(!is_callable($this->authorizer)){
			$missing[]='authorization';
		}
		if(!is_callable($this->rateLimiter)){
			$missing[]='rate_limit';
		}
		if($this->mutationsEnabled){
			if(!is_callable($this->originValidator)){
				$missing[]='origin';
			}
			if(!is_callable($this->csrfIssuer)){
				$missing[]='csrf_issuer';
			}
			if(!is_callable($this->csrfValidator)){
				$missing[]='csrf_validator';
			}
		}
		return [
			'type'=>'panel_standalone_host_manifest',
			'version'=>1,
			'panel'=>$this->surface->name(),
			'prefix'=>$this->prefix,
			'mode'=>$this->mutationsEnabled ? 'read_write' : 'read_only',
			'routes'=>[
				'pages'=>$this->prefix,
				'assets'=>$this->join('assets/{asset}'),
				'upload'=>$this->join('upload'),
			],
			'capabilities'=>[
				'assets'=>$this->assetsEnabled,
				'uploads'=>$this->uploadsEnabled,
				'mutations'=>$this->mutationsEnabled,
				'anonymous'=>$this->anonymousEnabled,
				'tenant_resolver'=>is_callable($this->tenantResolver),
				'external_redirect_policy'=>is_callable($this->redirectValidator),
				'custom_request_capture'=>is_callable($this->requestCapturer),
				'development_errors'=>$this->development,
			],
			'security'=>[
				'assets_public'=>true,
				'page_policies'=>['authentication','authorization','rate_limit'],
				'mutation_policies'=>['origin','csrf_issuer','csrf_validator'],
				'identity_source'=>'path_and_trusted_resolvers',
				'request_rebuilt'=>true,
				'response_guarded'=>true,
				'ready'=>$missing===[],
				'missing'=>$missing,
			],
			'limits'=>$this->limits,
			'runtime_prerequisites'=>[
				'content_encoding'=>'identity',
				'php_post_max_size_must_not_exceed_host_limit'=>true,
				'php_upload_max_filesize_must_not_exceed_host_limit'=>true,
				'web_server_header_and_body_limits_remain_required'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function dispatch(PanelStandaloneHostContext $context): \Dataphyre\Http\Response {
		$this->validateMethodAndCapability($context);
		if($context->routeKind()==='asset'){
			$result=PanelAssetController::response((string)$context->asset(), $context->request());
			return $this->responseGuard()->normalize($result, $context->request(), $context, true);
		}
		$context=$this->resolveTenant($context);
		$context=$this->authenticate($context);
		$this->authorize($context);
		$this->rateLimit($context);
		$this->ensureMutationReadiness();
		if($context->unsafe()){
			$this->validateOrigin($context);
			$this->validateCsrf($context);
		}
		$request=$context->request();
		if($context->tenant()!==null){
			$request->mergeRouteParameters(['panel_tenant'=>$context->tenant()]);
			$request->setAttribute('tenant', $context->tenant());
		}
		if($context->user()!==null){
			$request->setAttribute('user', $context->user());
		}
		$request->setAttribute('__panel_standalone_context', $context);
		$context=$context->withRequest($request);
		$frame=[
			'panel_mount_prefix'=>$this->prefix,
			'navigation_intent_mount'=>$this->prefix,
			'url_builder'=>PanelRoute::urlBuilder($this->prefix),
			'asset_url_builder'=>fn(string $asset): string=>PanelRoute::assetUrl($this->prefix, $asset),
			'upload_url'=>PanelRoute::uploadUrl($this->prefix),
			PanelCsrfTokenBridge::HOST_CONTEXT=>$context,
			PanelCsrfTokenBridge::ISSUER_CONTEXT=>$this->csrfIssuer,
			PanelCsrfTokenBridge::CACHE_CONTEXT=>new \ArrayObject(),
			'upload_csrf'=>false,
		];
		$result=PanelContext::run($frame, function() use ($context,$request): mixed {
			if($context->routeKind()==='upload'){
				return PanelUploadController::handle($request);
			}
			return PanelHost::surface($this->surface, $context->user())->response($request, ['infer_segments'=>true]);
		});
		return $this->responseGuard()->normalize($result, $request, $context, false);
	}

	private function validateMethodAndCapability(PanelStandaloneHostContext $context): void {
		if($context->routeKind()==='asset'){
			if(!$this->assetsEnabled){
				throw new PanelStandaloneHostException('asset_not_found', 404, 'The requested Panel asset was not found.');
			}
			if(!in_array($context->method(), ['GET','HEAD'], true)){
				throw new PanelStandaloneHostException('method_not_allowed', 405, 'Panel assets require GET or HEAD.', ['Allow'=>'GET, HEAD']);
			}
			return;
		}
		if($context->routeKind()==='upload'){
			if(!$this->uploadsEnabled){
				throw new PanelStandaloneHostException('upload_not_found', 404, 'The requested Panel upload route was not found.');
			}
			if($context->method()!=='POST'){
				throw new PanelStandaloneHostException('method_not_allowed', 405, 'Panel uploads require POST.', ['Allow'=>'POST']);
			}
			return;
		}
		if($context->method()==='OPTIONS'){
			throw new PanelStandaloneHostException('method_not_allowed', 405, 'The request method is not supported.', [
				'Allow'=>$this->mutationsEnabled ? 'GET, HEAD, POST, PUT, PATCH, DELETE' : 'GET, HEAD',
			]);
		}
		if($context->unsafe() && !$this->mutationsEnabled){
			throw new PanelStandaloneHostException('read_only', 405, 'This Panel host is read-only.', ['Allow'=>'GET, HEAD']);
		}
	}

	private function resolveTenant(PanelStandaloneHostContext $context): PanelStandaloneHostContext {
		if(!is_callable($this->tenantResolver)){
			return $context;
		}
		try{
			$value=$this->invoke($this->tenantResolver, $context, [], ['context','request']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('tenant_policy_unavailable', 503, 'The tenant resolver is unavailable.', [], $exception);
		}
		if($value===null || $value===''){
			return $context;
		}
		if(!is_string($value) && !is_numeric($value)){
			throw new PanelStandaloneHostException('invalid_tenant', 503, 'The tenant resolver returned an invalid tenant.');
		}
		$tenant=trim((string)$value);
		if($tenant==='' || strlen($tenant)>255 || preg_match('/[\x00-\x1F\x7F]/', $tenant)===1){
			throw new PanelStandaloneHostException('invalid_tenant', 503, 'The tenant resolver returned an invalid tenant.');
		}
		return $context->withTenant($tenant);
	}

	private function authenticate(PanelStandaloneHostContext $context): PanelStandaloneHostContext {
		if(!is_callable($this->authenticator)){
			if($this->anonymousEnabled){
				return $context;
			}
			throw new PanelStandaloneHostException('authentication_unavailable', 503, 'Authentication is not configured for this Panel host.');
		}
		try{
			$user=$this->invoke($this->authenticator, $context, [], ['context','request']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('authentication_unavailable', 503, 'Authentication is temporarily unavailable.', [], $exception);
		}
		if($user===null || $user===false){
			if($this->anonymousEnabled){
				return $context;
			}
			throw new PanelStandaloneHostException('unauthenticated', 401, 'Authentication is required.');
		}
		return $context->withUser($user);
	}

	private function authorize(PanelStandaloneHostContext $context): void {
		if(!is_callable($this->authorizer)){
			throw new PanelStandaloneHostException('authorization_unavailable', 503, 'Authorization is not configured for this Panel host.');
		}
		try{
			$allowed=$this->invoke($this->authorizer, $context, [
				'ability'=>$context->ability(),
				'user'=>$context->user(),
				'tenant'=>$context->tenant(),
			], ['ability','context','user','tenant']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('authorization_unavailable', 503, 'Authorization is temporarily unavailable.', [], $exception);
		}
		if($allowed!==true){
			throw new PanelStandaloneHostException('forbidden', 403, 'The current subject is not authorized for this Panel route.');
		}
	}

	private function rateLimit(PanelStandaloneHostContext $context): void {
		if(!is_callable($this->rateLimiter)){
			throw new PanelStandaloneHostException('rate_limit_unavailable', 503, 'Rate limiting is not configured for this Panel host.');
		}
		try{
			$result=$this->invoke($this->rateLimiter, $context, [], ['context','request']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('rate_limit_unavailable', 503, 'Rate limiting is temporarily unavailable.', [], $exception);
		}
		$allowed=$result===true || (is_array($result) && ($result['allowed'] ?? false)===true);
		if($allowed){
			return;
		}
		$retry=is_array($result) && is_numeric($result['retry_after'] ?? null) ? max(1, (int)$result['retry_after']) : 60;
		throw new PanelStandaloneHostException('rate_limited', 429, 'Too many Panel requests.', ['Retry-After'=>(string)$retry]);
	}

	private function ensureMutationReadiness(): void {
		if(!$this->mutationsEnabled){
			return;
		}
		if(!is_callable($this->originValidator) || !is_callable($this->csrfIssuer) || !is_callable($this->csrfValidator)){
			throw new PanelStandaloneHostException('mutation_security_unavailable', 503, 'Mutation security is not fully configured for this Panel host.');
		}
	}

	private function validateOrigin(PanelStandaloneHostContext $context): void {
		$origin=trim((string)$context->request()->header('Origin', ''));
		if($origin==='' || strlen($origin)>2048 || preg_match('/[\x00-\x1F\x7F]/', $origin)===1){
			throw new PanelStandaloneHostException('invalid_origin', 403, 'The request origin is missing or invalid.');
		}
		$parts=parse_url($origin);
		if(!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])){
			throw new PanelStandaloneHostException('invalid_origin', 403, 'The request origin is missing or invalid.');
		}
		try{
			$allowed=$this->invoke($this->originValidator, $context, [
				'origin'=>$origin,
				'referer'=>(string)$context->request()->header('Referer', ''),
			], ['origin','context','request','referer']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('origin_policy_unavailable', 503, 'The origin policy is unavailable.', [], $exception);
		}
		if($allowed!==true){
			throw new PanelStandaloneHostException('invalid_origin', 403, 'The request origin is not allowed.');
		}
	}

	private function validateCsrf(PanelStandaloneHostContext $context): void {
		$upload=$context->routeKind()==='upload';
		$scope=$upload ? PanelCsrfTokenBridge::UPLOAD_SCOPE : PanelCsrfTokenBridge::FORM_SCOPE;
		$field=$upload ? PanelCsrfTokenBridge::UPLOAD_FIELD : PanelCsrfTokenBridge::FORM_FIELD;
		$token=$context->request()->input($field, $context->request()->header(PanelCsrfTokenBridge::HEADER));
		if(!is_string($token) || trim($token)==='' || strlen($token)>4096 || preg_match('/[\x00-\x1F\x7F]/', $token)===1){
			throw new PanelStandaloneHostException('csrf_failed', 419, 'CSRF validation failed.');
		}
		try{
			$valid=$this->invoke($this->csrfValidator, $context, [
				'token'=>$token,
				'scope'=>$scope,
				'field'=>$field,
			], ['token','scope','context','request']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('csrf_policy_unavailable', 503, 'CSRF validation is unavailable.', [], $exception);
		}
		if($valid!==true){
			throw new PanelStandaloneHostException('csrf_failed', 419, 'CSRF validation failed.');
		}
	}

	private function invoke(callable $callback, PanelStandaloneHostContext $context, array $values=[], array $position=[]): mixed {
		return PanelUtilityResolver::evaluate($callback, $values+[
			'context'=>$context,
			'request'=>$context->request(),
			'host'=>$this,
			'panel'=>$this->surface,
			'ability'=>$context->ability(),
			'method'=>$context->method(),
			'unsafe'=>$context->unsafe(),
			'user'=>$context->user(),
			'tenant'=>$context->tenant(),
		], $position);
	}

	private function ability(string $routeKind, bool $unsafe): string {
		return match($routeKind){
			'asset'=>'panel.asset.read',
			'upload'=>'panel.upload',
			default=>$unsafe ? 'panel.page.mutate' : 'panel.page.read',
		};
	}

	private function requestGuard(): PanelStandaloneHostRequestGuard {
		return new PanelStandaloneHostRequestGuard($this->prefix, $this->limits);
	}

	private function responseGuard(): PanelStandaloneHostResponseGuard {
		return new PanelStandaloneHostResponseGuard($this->prefix, $this->securityHeaders, $this->redirectValidator);
	}

	/** @param array<string,mixed> $runtime */
	private function capture(array $runtime=[]): \Dataphyre\Http\Request {
		if(is_callable($this->requestCapturer)){
			$request=PanelUtilityResolver::evaluate($this->requestCapturer, [
				'host'=>$this,
				'runtime'=>$runtime,
			], ['host','runtime']);
			if(!$request instanceof \Dataphyre\Http\Request){
				throw new PanelStandaloneHostException('invalid_captured_request', 500, 'The request capture adapter returned an invalid request.');
			}
			return $request;
		}
		$server=is_array($runtime['server'] ?? null) ? $runtime['server'] : (is_array($_SERVER ?? null) ? $_SERVER : []);
		$query=is_array($runtime['query'] ?? null) ? $runtime['query'] : (is_array($_GET ?? null) ? $_GET : []);
		$post=is_array($runtime['post'] ?? null) ? $runtime['post'] : (is_array($_POST ?? null) ? $_POST : []);
		$cookies=is_array($runtime['cookies'] ?? null) ? $runtime['cookies'] : (is_array($_COOKIE ?? null) ? $_COOKIE : []);
		$files=is_array($runtime['files'] ?? null) ? $runtime['files'] : (is_array($_FILES ?? null) ? $_FILES : []);
		$headers=$this->captureHeaders($server);
		$contentLength=$headers['content_length'] ?? null;
		if($contentLength!==null && (preg_match('/^(0|[1-9][0-9]*)$/D', trim((string)$contentLength))!==1 || (int)$contentLength>$this->limits['max_content_length'])){
			throw new PanelStandaloneHostException('request_too_large', 413, 'The request body is too large.');
		}
		$body=$post;
		$contentType=strtolower(trim((string)($headers['content_type'] ?? '')));
		$contentType=trim(explode(';', $contentType, 2)[0]);
		if($body===[] && ($contentType==='application/json' || str_ends_with($contentType, '+json'))){
			if(array_key_exists('raw_body', $runtime)){
				$raw=$runtime['raw_body'];
			}
			elseif(is_callable($runtime['body_reader'] ?? null)){
				$raw=($runtime['body_reader'])($this->limits['max_body_bytes']+1);
			}
			else {
				$stream=@fopen('php://input', 'rb');
				$raw=is_resource($stream) ? stream_get_contents($stream, $this->limits['max_body_bytes']+1) : '';
				if(is_resource($stream)){
					fclose($stream);
				}
			}
			if(!is_string($raw)){
				throw new PanelStandaloneHostException('invalid_body', 400, 'The request body could not be read.');
			}
			if(strlen($raw)>$this->limits['max_body_bytes']){
				throw new PanelStandaloneHostException('body_too_large', 413, 'The request body is too large.');
			}
			if(trim($raw)!==''){
				try{
					$decoded=json_decode($raw, true, $this->limits['max_body_depth'], JSON_THROW_ON_ERROR);
				}
				catch(\JsonException $exception){
					throw new PanelStandaloneHostException('invalid_json', 400, 'The JSON request body is malformed.', [], $exception);
				}
				if(!is_array($decoded)){
					throw new PanelStandaloneHostException('invalid_json', 400, 'The JSON request body must contain an object or array.');
				}
				$body=$decoded;
			}
		}
		$path=(string)(parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
		return \Dataphyre\Http\Request::create(
			(string)($server['REQUEST_METHOD'] ?? 'GET'),
			$path,
			$query,
			$body,
			$cookies,
			$server,
			$headers,
			[],
			[],
			$files,
		);
	}

	/** @param array<string,mixed> $server @return array<string,mixed> */
	private function captureHeaders(array $server): array {
		$headers=[];
		foreach($server as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			if(str_starts_with($key, 'HTTP_')){
				$headers[strtolower(substr($key, 5))]=$value;
			}
		}
		if(isset($server['CONTENT_TYPE'])){
			$headers['content_type']=$server['CONTENT_TYPE'];
		}
		if(isset($server['CONTENT_LENGTH'])){
			$headers['content_length']=$server['CONTENT_LENGTH'];
		}
		if(!isset($headers['authorization'])){
			foreach(['REDIRECT_HTTP_AUTHORIZATION','HTTP_AUTHORIZATION','Authorization'] as $key){
				if(isset($server[$key]) && is_string($server[$key]) && trim($server[$key])!==''){
					$headers['authorization']=$server[$key];
					break;
				}
			}
		}
		return $headers;
	}

	private function errorResponse(PanelStandaloneHostException $exception, string $requestId): \Dataphyre\Http\Response {
		$envelope=PanelErrorEnvelope::response(
			$exception->errorCode(),
			$exception->httpStatus(),
			$exception->getMessage(),
			$exception->getPrevious(),
			$this->development,
			['panel'=>$this->surface->name(), 'prefix'=>$this->prefix],
			$requestId,
			$this->safeErrorHeaders($exception->headers()),
		);
		$headers=array_replace($envelope->headers(), [
			'Cache-Control'=>'no-store',
			'X-Content-Type-Options'=>'nosniff',
			'Referrer-Policy'=>'same-origin',
			'X-Frame-Options'=>'SAMEORIGIN',
			'X-Correlation-ID'=>$requestId,
			'X-Dataphyre-Panel-Host'=>'standalone',
		]);
		foreach($this->safeErrorHeaders($exception->headers()) as $name=>$value){
			$headers[$name]=$value;
		}
		return new \Dataphyre\Http\Response($envelope->content(), $envelope->status(), $headers);
	}

	/** @param array<string,mixed> $headers @return array<string,string|array<int,string>> */
	private function safeErrorHeaders(array $headers): array {
		$safe=[];
		foreach($headers as $name=>$value){
			if(!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)!==1){
				continue;
			}
			$values=is_array($value) ? $value : [$value];
			$valid=[];
			foreach($values as $item){
				if((is_string($item) || is_numeric($item)) && preg_match('/[\r\n\x00]/', (string)$item)!==1){
					$valid[]=(string)$item;
				}
			}
			if($valid!==[]){
				$safe[$name]=is_array($value) ? $valid : $valid[0];
			}
		}
		return $safe;
	}

	private function requestIdCandidate(\Dataphyre\Http\Request $request): ?string {
		$value=$request->header('X-Request-ID', $request->header('X-Correlation-ID'));
		return is_string($value) ? $value : null;
	}

	private function trace(
		?PanelStandaloneHostContext $context,
		int $status,
		bool $ok,
		?string $error=null,
		?\Dataphyre\Http\Request $request=null,
	): void {
		$metadata=$context?->jsonSerialize() ?? [
			'type'=>'panel_standalone_host_context',
			'panel'=>$this->surface->name(),
			'prefix'=>$this->prefix,
			'route_digest'=>hash('sha256', (string)($request?->path() ?? '')),
		];
		PanelTrace::record('standalone.request', $metadata+[
			'status'=>$status,
			'ok'=>$ok,
			'error'=>$error,
		]);
	}

	private function join(string $path): string {
		return $this->prefix==='/' ? '/'.ltrim($path, '/') : $this->prefix.'/'.ltrim($path, '/');
	}

	private function with(string $property, mixed $value): self {
		$clone=clone $this;
		$clone->{$property}=$value;
		return $clone;
	}
}
