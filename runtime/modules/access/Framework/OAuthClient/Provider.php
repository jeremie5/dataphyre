<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Auth;
use Dataphyre\Access\Exceptions\InvalidOAuthStateException;
use Dataphyre\Access\Exceptions\OAuthException;
use Dataphyre\Access\Jwt\JwtCodec;
use Dataphyre\Access\Jwt\JwtPayload;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * OAuth 2/OpenID Connect provider client for one configured identity provider.
 *
 * Provider owns the complete remote identity flow for a provider alias: outbound
 * authorization requests, state/PKCE/nonce transaction storage, callback
 * validation, token exchange, optional id_token verification, userinfo fetching,
 * OAuthUser mapping, token refresh/revocation, and local guard login handoff. The
 * class is immutable for request-specific options; fluent modifiers return clones
 * so a shared provider can safely be reused by multiple callers.
 */
class Provider {

	private string $name;
	private array $config;
	private Manager $manager;
	private bool $stateless=false;
	private ?string $redirectUriOverride=null;
	private ?array $scopesOverride=null;
	private array $parameters=[];
	private static ?array $lastScopeListInput=null;
	private static ?array $lastScopeListOutput=null;

	/**
	 * Creates a provider client from resolved configuration.
	 *
	 * Construction does not contact the remote provider. Discovery documents, JWKS
	 * keys, HTTP calls, and state writes are deferred until the corresponding OAuth
	 * flow method is invoked.
	 *
	 * @param string $name Provider alias used in errors, state keys, and OAuthUser records.
	 * @param array<string, mixed> $config Provider endpoints, credentials, mapping, and flow options.
	 * @param Manager $manager Manager that produced this provider instance.
	 */
	public function __construct(string $name, array $config, Manager $manager){
		$this->name=trim($name);
		$this->config=$config;
		$this->manager=$manager;
	}

	/**
	 * Returns the provider alias.
	 *
	 * @return string Trimmed provider name used for configuration lookup and diagnostics.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	/**
	 * Returns the provider configuration snapshot.
	 *
	 * @return array<string, mixed> Config values supplied by Manager and runtime extensions.
	 */
	public function config(): array {
		return $this->config;
	}

	/**
	/**
	 * Returns the manager that owns provider registration.
	 *
	 * @return Manager Shared OAuth manager that created this provider.
	 */
	public function manager(): Manager {
		return $this->manager;
	}

	/**
	 * Returns a clone configured to skip state, PKCE, and nonce persistence.
	 *
	 * Stateless mode is useful for trusted mobile/native callbacks or tests, but it
	 * removes CSRF and replay protections normally provided by the StateStore.
	 *
	 * @param bool $stateless Whether the cloned provider should bypass transaction storage.
	 * @return static Cloned provider with the stateless flag applied.
	 */
	public function stateless(bool $stateless=true): static {
		$clone=clone $this;
		$clone->stateless=$stateless;
		return $clone;
	}

	/**
	 * Returns a clone with per-request OAuth scopes.
	 *
	 * Empty values are discarded and duplicates are removed before the authorization
	 * URL is built.
	 *
	 * @param array<int, mixed> $scopes Requested scope names.
	 * @return static Cloned provider with scope override applied.
	 */
	public function scopes(array $scopes): static {
		$clone=clone $this;
		$clone->scopesOverride=self::normalizeScopeList($scopes);
		return $clone;
	}

	/**
	 * Returns a clone with a per-request redirect URI override.
	 *
	 * The same URI is stored in the state transaction and later sent during token
	 * exchange, which keeps providers that require exact redirect URI matching happy.
	 *
	 * @param string $redirectUri Absolute callback URI registered with the provider.
	 * @return static Cloned provider with redirect URI override applied.
	 */
	public function redirectUri(string $redirectUri): static {
		$clone=clone $this;
		$clone->redirectUriOverride=trim($redirectUri);
		return $clone;
	}

	/**
	 * Returns a clone with extra authorization request parameters.
	 *
	 * These parameters are merged last and can override generated authorization
	 * values when a provider requires custom prompts, access types, login hints, or
	 * other vendor-specific fields.
	 *
	 * @param array<string, mixed> $parameters Additional authorization query parameters.
	 * @return static Cloned provider with merged authorization parameters.
	 */
	public function with(array $parameters): static {
		$clone=clone $this;
		$clone->parameters=array_replace($clone->parameters, $parameters);
		return $clone;
	}

	/**
	 * Builds an outbound authorization request and stores transaction state.
	 *
	 * When state is enabled the transaction stores redirect URI, scopes, PKCE code
	 * verifier, and nonce so the callback can validate the response before token
	 * exchange. The returned value contains both the final URL and generated secrets
	 * for observability/testing.
	 *
	 * @return AuthorizationRequest Authorization URL plus generated state, PKCE, and nonce metadata.
	 */
	public function authorizationRequest(): AuthorizationRequest {
		$authorizationUrl=$this->requiredConfig('authorization_url');
		$state=$this->shouldUseState() ? $this->generateRandomToken(32) : null;
		$codeVerifier=$this->shouldUsePkce() ? $this->generateRandomToken(64) : null;
		$nonce=$this->shouldUseNonce() ? $this->generateRandomToken(32) : null;
		$params=array_replace(
			$this->authorizeParameters($state, $codeVerifier, $nonce),
			$this->extraParameters('authorize'),
			$this->parameters
		);
		if($state!==null){
			$this->stateStore()->put($state, [
				'redirect_uri'=>$this->resolveRedirectUri(),
				'scopes'=>$this->resolveScopes(),
				'code_verifier'=>$codeVerifier,
				'nonce'=>$nonce,
			]);
		}
		return new AuthorizationRequest(
			$this->name,
			$this->appendQuery($authorizationUrl, $params),
			$state,
			$codeVerifier,
			$nonce
		);
	}

	/**
	 * Returns the authorization URL for redirecting the user agent.
	 *
	 * @return string Fully composed provider authorization URL.
	 */
	public function authorizationUrl(): string {
		return $this->authorizationRequest()->url();
	}

	/**
	/**
	 * Returns an HTTP redirect response to the authorization URL.
	 *
	 * @return Response Redirect response built from the generated authorization request.
	 */
	public function redirect(): Response {
		return $this->authorizationRequest()->response();
	}

	/**
	 * Exchanges an OAuth callback for a mapped OAuth user.
	 *
	 * The callback must include an authorization code unless it carries a provider
	 * error. Stateful flows also require a valid, unexpired state transaction before
	 * token exchange proceeds.
	 *
	 * @param Request|array|null $request Callback request object, callback array, or null to read $_GET.
	 * @return OAuthUser Remote identity and token metadata.
	 *
	 * @throws InvalidOAuthStateException When state is missing, expired, or unknown.
	 * @throws OAuthException When the callback reports an error or token exchange fails.
	 */
	public function user(Request|array|null $request=null): OAuthUser {
		$callback=$this->callbackParameters($request);
		$error=$this->firstString($callback, ['error', 'oauth_error']);
		if($error!==null){
			$message=$this->firstString($callback, ['error_description', 'error_message']) ?? $error;
			throw new OAuthException('OAuth authorization failed: '.$message);
		}
		$code=$this->firstString($callback, ['code']);
		if($code===null){
			throw new OAuthException("OAuth callback for '{$this->name}' is missing an authorization code.");
		}
		$transaction=$this->consumeTransaction($callback);
		$tokenResponse=$this->exchangeCodeForToken($code, $transaction);
		return $this->oauthUserFromTokenResponse($tokenResponse, $callback, $transaction);
	}

	/**
	 * Fetches and maps a user profile using an existing access token.
	 *
	 * @param string $accessToken Provider-issued bearer token.
	 * @return OAuthUser Remote identity resolved from token-backed userinfo/id_token data.
	 *
	 * @throws OAuthException When the token is empty or provider calls fail.
	 */
	public function userFromToken(string $accessToken): OAuthUser {
		$accessToken=trim($accessToken);
		if($accessToken===''){
			throw new OAuthException("OAuth access token cannot be empty for provider '{$this->name}'.");
		}
		return $this->oauthUserFromTokenResponse([
			'access_token'=>$accessToken,
			'token_type'=>'Bearer',
		]);
	}

	/**
	 * Refreshes tokens through the configured token endpoint.
	 *
	 * The response must contain a non-empty access_token. If the provider omits a
	 * replacement refresh_token, the original refresh token is preserved in the
	 * returned payload.
	 *
	 * @param string|OAuthUser $refreshTokenOrUser Refresh token string or OAuthUser carrying one.
	 * @return array<string, mixed> Decoded token refresh payload.
	 *
	 * @throws OAuthException When the refresh token is missing, the endpoint fails, or access_token is absent.
	 */
	public function refresh(string|OAuthUser $refreshTokenOrUser): array {
		$refreshToken=$this->extractRefreshToken($refreshTokenOrUser);
		if($refreshToken===''){
			throw new OAuthException("OAuth refresh token is missing for provider '{$this->name}'.");
		}
		$payload=[
			'grant_type'=>'refresh_token',
			'refresh_token'=>$refreshToken,
		];
		$headers=$this->clientAuthHeadersAndPayload($payload, 'refresh_auth_method');
		$response=$this->httpClient()->send(
			'POST',
			$this->requiredConfig('token_url'),
			array_replace($payload, $this->extraParameters('refresh')),
			$headers
		);
		$decoded=$this->decodeResponseBody($response);
		$this->throwForHttpError($response, $decoded, 'token refresh');
		if(!isset($decoded['access_token']) || !is_string($decoded['access_token']) || trim($decoded['access_token'])===''){
			throw new OAuthException("OAuth refresh response for '{$this->name}' is missing access_token.");
		}
		if(!isset($decoded['refresh_token']) || !is_string($decoded['refresh_token']) || trim($decoded['refresh_token'])===''){
			$decoded['refresh_token']=$refreshToken;
		}
		return $decoded;
	}

	/**
	 * Refreshes tokens and maps the resulting profile into an OAuthUser.
	 *
	 * @param string|OAuthUser $refreshTokenOrUser Refresh token string or OAuthUser carrying one.
	 * @return OAuthUser User profile carrying refreshed token metadata.
	 */
	public function refreshedUser(string|OAuthUser $refreshTokenOrUser): OAuthUser {
		return $this->oauthUserFromTokenResponse($this->refresh($refreshTokenOrUser));
	}

	/**
	 * Revokes an access or refresh token through the configured revocation endpoint.
	 *
	 * Revocation is considered successful when the provider returns a non-error HTTP
	 * response. Providers commonly return an empty body for this operation.
	 *
	 * @param string|OAuthUser $tokenOrUser Token string or OAuthUser carrying token metadata.
	 * @param string|null $hint Optional access_token or refresh_token hint.
	 * @return bool True when the provider accepts the revocation request.
	 *
	 * @throws OAuthException When the token is missing or the revocation endpoint fails.
	 */
	public function revoke(string|OAuthUser $tokenOrUser, ?string $hint=null): bool {
		$token=$this->extractRevocationToken($tokenOrUser, $hint);
		if($token===''){
			throw new OAuthException("OAuth revocation token is missing for provider '{$this->name}'.");
		}
		$payload=[
			'token'=>$token,
		];
		$normalizedHint=$this->normalizeRevocationHint($hint, $tokenOrUser);
		if($normalizedHint!==null){
			$payload['token_type_hint']=$normalizedHint;
		}
		$headers=$this->clientAuthHeadersAndPayload($payload, 'revocation_auth_method');
		$response=$this->httpClient()->send(
			'POST',
			$this->requiredConfig('revocation_url'),
			array_replace($payload, $this->extraParameters('revoke')),
			$headers
		);
		$decoded=$this->decodeResponseBody($response);
		$this->throwForHttpError($response, $decoded, 'token revocation');
		return true;
	}

	/**
	 * Resolves a local application user from the mapped OAuth profile.
	 *
	 * The optional resolve_user callback is the trust handoff from remote identity
	 * into local account linking/creation. Returning null or false prevents login.
	 *
	 * @param OAuthUser $oauthUser Remote identity and token metadata.
	 * @return mixed Local user value accepted by Auth::login(), or null when no resolver exists.
	 */
	public function resolveLocalUser(OAuthUser $oauthUser): mixed {
		$resolver=$this->config['resolve_user'] ?? null;
		if(!is_callable($resolver)){
			return null;
		}
		$payload=[
			'provider'=>$this->name,
			'oauth_user_type'=>$oauthUser::class,
		];
		$dialback=\dataphyre\core::dialback('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_RESOLVE_LOCAL_USER', $payload);
		if($dialback!==null){
			return $dialback;
		}
		$localUser=$resolver($oauthUser, $this);
		$dialback=\dataphyre\core::dialback('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_RESOLVE_LOCAL_USER', $payload+[
			'resolved'=>$localUser!==null && $localUser!==false,
			'local_user_type'=>is_object($localUser) ? $localUser::class : gettype($localUser),
		]);
		return $dialback!==null ? $dialback : $localUser;
	}

	/**
	 * Completes an OAuth flow and creates a local Dataphyre access session.
	 *
	 * The method accepts either a pre-resolved OAuthUser or callback input that can
	 * be exchanged into one. Login succeeds only after resolveLocalUser() returns a
	 * local principal accepted by the configured guard.
	 *
	 * @param Request|array|OAuthUser|null $requestOrUser Callback input or already-resolved OAuth user.
	 * @param string|null $guard Guard name/auth channel for the local session.
	 * @param bool $remember Whether the local session should persist.
	 * @return bool True when account resolution and guard login both succeed.
	 */
	public function login(
		Request|array|OAuthUser|null $requestOrUser=null,
		?string $guard=null,
		bool $remember=true
	): bool {
		$oauthUser=$requestOrUser instanceof OAuthUser
			? $requestOrUser
			: $this->user($requestOrUser instanceof Request || is_array($requestOrUser) ? $requestOrUser : null);
		$localUser=$this->resolveLocalUser($oauthUser);
		if($localUser===null || $localUser===false){
			\dataphyre\core::dialback('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_LOGIN', [
				'provider'=>$this->name,
				'guard'=>$guard,
				'remember'=>$remember,
				'resolved'=>false,
				'ok'=>false,
			]);
			return false;
		}
		$payload=[
			'provider'=>$this->name,
			'guard'=>$guard,
			'remember'=>$remember,
			'resolved'=>true,
			'local_user_type'=>is_object($localUser) ? $localUser::class : gettype($localUser),
		];
		$dialback=\dataphyre\core::dialback('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_LOGIN', $payload);
		if(is_bool($dialback)){
			return $dialback;
		}
		$result=Auth::login($localUser, $remember, $guard);
		$dialback=\dataphyre\core::dialback('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_LOGIN', $payload+['ok'=>$result]);
		return is_bool($dialback) ? $dialback : $result;
	}

	/**
	 * Builds the base authorization query for the provider redirect.
	 *
	 * The query includes client id, redirect URI, scopes, optional state, optional
	 * OpenID nonce, and PKCE challenge fields when those protections are active.
	 *
	 * @param string|null $state Generated CSRF state token for stateful flows.
	 * @param string|null $codeVerifier Generated PKCE verifier stored server-side.
	 * @param string|null $nonce Generated OpenID Connect nonce stored server-side.
	 * @return array<string, mixed> Authorization endpoint query parameters.
	 */
	private function authorizeParameters(?string $state, ?string $codeVerifier, ?string $nonce): array {
		$params=[
			'response_type'=>trim((string)($this->config['response_type'] ?? 'code')),
			'client_id'=>$this->requiredConfig('client_id'),
		];
		$redirectUri=$this->resolveRedirectUri();
		if($redirectUri!==null){
			$params['redirect_uri']=$redirectUri;
		}
		$scopes=$this->resolveScopes();
		if($scopes!==[]){
			$params['scope']=implode((string)($this->config['scope_separator'] ?? ' '), $scopes);
		}
		if($state!==null){
			$params['state']=$state;
		}
		if($nonce!==null){
			$params['nonce']=$nonce;
		}
		if($codeVerifier!==null){
			$params['code_challenge']=$this->codeChallenge($codeVerifier);
			$params['code_challenge_method']=strtoupper((string)($this->config['pkce_method'] ?? 'S256'));
		}
		return $params;
	}

	/**
	 * Normalizes callback input into an associative array.
	 *
	 * @param Request|array|null $request Request object, raw callback array, or null to read $_GET.
	 * @return array<string, mixed> Callback parameters from the provider redirect.
	 */
	private function callbackParameters(Request|array|null $request): array {
		if($request instanceof Request){
			$callback=$request->query();
			return is_array($callback) ? $callback : [];
		}
		if(is_array($request)){
			return $request;
		}
		return $_GET;
	}

	/**
	 * Consumes and validates the stored OAuth transaction for a callback.
	 *
	 * Stateless flows return null. Stateful flows require a callback state value and
	 * remove the matching transaction from StateStore so replayed callbacks fail.
	 *
	 * @param array<string, mixed> $callback Callback parameters from the provider.
	 * @return array<string, mixed>|null Stored transaction metadata, or null for stateless flows.
	 *
	 * @throws InvalidOAuthStateException When state is missing, invalid, or expired.
	 */
	private function consumeTransaction(array $callback): ?array {
		if($this->stateless===true){
			return null;
		}
		if($this->shouldUseState()===false){
			return null;
		}
		$state=$this->firstString($callback, ['state']);
		if($state===null){
			throw new InvalidOAuthStateException("OAuth callback for '{$this->name}' is missing state.");
		}
		$transaction=$this->stateStore()->pull($state);
		if(!is_array($transaction)){
			throw new InvalidOAuthStateException("OAuth state is invalid or expired for provider '{$this->name}'.");
		}
		return $transaction;
	}

	/**
	 * Maps a token response into a complete OAuthUser.
	 *
	 * The method verifies id_token claims when configured, fetches userinfo when an
	 * endpoint is available, and falls back to id_token claims when userinfo is not
	 * returned.
	 *
	 * @param array<string, mixed> $tokenResponse Decoded OAuth token response.
	 * @param array<string, mixed> $callback Callback parameters that produced the token.
	 * @param array<string, mixed>|null $transaction Consumed state transaction, if any.
	 * @return OAuthUser Mapped user profile and token metadata.
	 */
	private function oauthUserFromTokenResponse(
		array $tokenResponse,
		array $callback=[],
		?array $transaction=null
	): OAuthUser {
		$idTokenPayload=$this->validateIdToken($tokenResponse, $transaction);
		$idTokenClaims=$idTokenPayload?->claims() ?? [];
		$profileResponse=$this->fetchUserinfo($tokenResponse);
		if($profileResponse===[] && $idTokenClaims!==[]){
			$profileResponse=$idTokenClaims;
		}
		return $this->mapUser($tokenResponse, $profileResponse, $callback, $idTokenClaims);
	}

	/**
	 * Validates an OpenID Connect id_token and enforces nonce binding.
	 *
	 * Validation is skipped when no id_token is present or verify_id_token is false.
	 * Otherwise issuer, audience, allowed algorithms, leeway, and verification keys
	 * are taken from explicit config or discovery/JWKS resolution.
	 *
	 * @param array<string, mixed> $tokenResponse Decoded token response.
	 * @param array<string, mixed>|null $transaction Stored transaction containing expected nonce.
	 * @return JwtPayload|null Verified JWT payload, or null when verification is not applicable.
	 *
	 * @throws OAuthException When nonce validation fails.
	 */
	private function validateIdToken(array $tokenResponse, ?array $transaction=null): ?JwtPayload {
		$idToken=trim((string)($tokenResponse['id_token'] ?? ''));
		if($idToken===''){
			return null;
		}
		if((bool)$this->configValue('verify_id_token', true)===false){
			return null;
		}
		$clientId=$this->requiredConfig('client_id');
		$jwtConfig=[
			'algorithms'=>$this->configValue('id_token_algorithms', ['RS256']),
			'issuer'=>$this->configValue('id_token_issuer', $this->configValue('issuer')),
			'audience'=>$this->configValue('id_token_audience', $clientId),
			'leeway'=>(int)$this->configValue('id_token_leeway', 60),
		];
		$hasDirectKey=false;
		foreach(['public_key', 'verification_key', 'key'] as $directKey){
			$configValue=$this->configValue('id_token_'.$directKey);
			if(is_string($configValue) && trim($configValue)!==''){
				$jwtConfig[$directKey]=trim($configValue);
				$hasDirectKey=true;
			}
		}
		if($hasDirectKey===false){
			$jwtConfig['key_resolver']=function(string $algorithm, array $headers, array $claims, array $config): string {
				return JwksResolver::resolve($algorithm, $headers, $this->idTokenKeyConfig());
			};
		}
		$payload=JwtCodec::decode($idToken, $jwtConfig);
		$expectedNonce=$transaction['nonce'] ?? $this->configValue('expected_nonce');
		if(is_string($expectedNonce) && $expectedNonce!==''){
			$receivedNonce=(string)($payload->claim('nonce') ?? '');
			if($receivedNonce==='' || hash_equals($expectedNonce, $receivedNonce)===false){
				throw new OAuthException("OAuth id_token nonce is invalid for provider '{$this->name}'.");
			}
		}
		return $payload;
	}

	/**
	 * Builds JWKS/discovery configuration for id_token verification.
	 *
	 * @return array<string, mixed> Key resolver configuration passed to JwksResolver.
	 */
	private function idTokenKeyConfig(): array {
		$config=[
			'http'=>is_array($this->configValue('http')) ? $this->configValue('http') : [],
		];
		foreach([
			'jwks',
			'jwks_url',
			'issuer',
			'discover',
			'discovery_url',
			'openid_configuration_url',
		] as $key){
			$value=$this->configValue($key);
			if($value!==null){
				$config[$key]=$value;
			}
		}
		return $config;
	}

	/**
	 * Exchanges an authorization code for provider tokens.
	 *
	 * Redirect URI and PKCE verifier are replayed from the stored transaction when
	 * available to satisfy provider binding rules.
	 *
	 * @param string $code Authorization code from callback parameters.
	 * @param array<string, mixed>|null $transaction Stored state transaction for this callback.
	 * @return array<string, mixed> Decoded token response containing access_token.
	 *
	 * @throws OAuthException When token_url is missing, HTTP fails, or access_token is absent.
	 */
	private function exchangeCodeForToken(string $code, ?array $transaction): array {
		$payload=[
			'grant_type'=>trim((string)($this->config['grant_type'] ?? 'authorization_code')),
			'code'=>$code,
		];
		$redirectUri=$transaction['redirect_uri'] ?? $this->resolveRedirectUri();
		if(is_string($redirectUri) && $redirectUri!==''){
			$payload['redirect_uri']=$redirectUri;
		}
		if(isset($transaction['code_verifier']) && is_string($transaction['code_verifier']) && $transaction['code_verifier']!==''){
			$payload['code_verifier']=$transaction['code_verifier'];
		}
		$headers=$this->clientAuthHeadersAndPayload($payload, 'token_auth_method');
		$response=$this->httpClient()->send(
			'POST',
			$this->requiredConfig('token_url'),
			array_replace($payload, $this->extraParameters('token')),
			$headers
		);
		$decoded=$this->decodeResponseBody($response);
		$this->throwForHttpError($response, $decoded, 'token exchange');
		if(!isset($decoded['access_token']) || !is_string($decoded['access_token']) || trim($decoded['access_token'])===''){
			throw new OAuthException("OAuth token response for '{$this->name}' is missing access_token.");
		}
		return $decoded;
	}

	/**
	 * Fetches the provider userinfo document for an access token.
	 *
	 * Providers can receive the token as a bearer header, query parameter, or form
	 * field depending on userinfo_auth_method configuration.
	 *
	 * @param array<string, mixed> $tokenResponse Decoded token response containing access_token.
	 * @return array<string, mixed> Decoded userinfo response, or an empty array when unavailable.
	 *
	 * @throws OAuthException When the userinfo endpoint returns an error response.
	 */
	private function fetchUserinfo(array $tokenResponse): array {
		$userinfoUrl=trim((string)$this->configValue('userinfo_url', ''));
		if($userinfoUrl===''){
			return [];
		}
		$accessToken=trim((string)($tokenResponse['access_token'] ?? ''));
		if($accessToken===''){
			return [];
		}
		$method=strtoupper(trim((string)$this->configValue('userinfo_method', 'GET')));
		$authMethod=strtolower(trim((string)$this->configValue('userinfo_auth_method', 'bearer')));
		$headers=is_array($this->configValue('userinfo_headers')) ? $this->configValue('userinfo_headers') : [];
		$query=[];
		$body=null;
		if($authMethod==='bearer'){
			$headers['Authorization']='Bearer '.$accessToken;
		}
		elseif($authMethod==='query'){
			$query[(string)$this->configValue('userinfo_token_parameter', 'access_token')]=$accessToken;
		}
		elseif($authMethod==='form'){
			$body=[(string)$this->configValue('userinfo_token_parameter', 'access_token')=>$accessToken];
		}
		$extraQuery=is_array($this->configValue('userinfo_query')) ? $this->configValue('userinfo_query') : [];
		$query=array_replace($query, $extraQuery);
		if(is_array($body)){
			$body=array_replace($body, $this->extraParameters('userinfo'));
		}
		$response=$this->httpClient()->send($method, $userinfoUrl, $body, $headers, $query);
		$decoded=$this->decodeResponseBody($response);
		$this->throwForHttpError($response, $decoded, 'userinfo fetch');
		return $decoded;
	}

	/**
	 * Maps token, profile, callback, and id_token data into an OAuthUser value.
	 *
	 * Identity mappings may be strings or ordered lists of dot paths. Profile
	 * attributes are preferred, then id_token claims, then token response fields.
	 *
	 * @param array<string, mixed> $tokenResponse OAuth token response.
	 * @param array<string, mixed> $profileResponse Userinfo response or equivalent profile data.
	 * @param array<string, mixed> $callback Original callback parameters.
	 * @param array<string, mixed> $idTokenClaims Verified id_token claims.
	 * @return OAuthUser Normalized identity and token bundle.
	 */
	private function mapUser(
		array $tokenResponse,
		array $profileResponse,
		array $callback,
		array $idTokenClaims=[]
	): OAuthUser {
		$identity=is_array($this->configValue('identity')) ? $this->configValue('identity') : [];
		$attributes=$profileResponse!==[] ? $profileResponse : ($idTokenClaims!==[] ? $idTokenClaims : $tokenResponse);
		$id=$this->identityValue($identity['id'] ?? ['sub', 'id', 'user.id'], $attributes, $tokenResponse, $idTokenClaims);
		$email=$this->nullableString($this->identityValue($identity['email'] ?? 'email', $attributes, $tokenResponse, $idTokenClaims));
		$name=$this->nullableString($this->identityValue($identity['name'] ?? ['name', 'full_name'], $attributes, $tokenResponse, $idTokenClaims));
		$nickname=$this->nullableString($this->identityValue($identity['nickname'] ?? ['nickname', 'preferred_username', 'login'], $attributes, $tokenResponse, $idTokenClaims));
		$avatar=$this->nullableString($this->identityValue($identity['avatar'] ?? ['picture', 'avatar', 'avatar_url'], $attributes, $tokenResponse, $idTokenClaims));
		$emailVerified=$this->nullableBool($this->identityValue($identity['email_verified'] ?? ['email_verified', 'verified_email'], $attributes, $tokenResponse, $idTokenClaims));
		$scopes=$this->normalizeScopes($tokenResponse['scope'] ?? $tokenResponse['scopes'] ?? []);
		$expiresIn=$this->nullableInt($tokenResponse['expires_in'] ?? null);
		return new OAuthUser(
			$this->name,
			(is_int($id) || is_string($id)) ? $id : null,
			$nickname,
			$name,
			$email,
			$emailVerified,
			$avatar,
			$this->nullableString($tokenResponse['access_token'] ?? null),
			$this->nullableString($tokenResponse['refresh_token'] ?? null),
			$this->nullableString($tokenResponse['id_token'] ?? null),
			$this->nullableString($tokenResponse['token_type'] ?? null),
			$expiresIn,
			$scopes,
			is_array($attributes) ? $attributes : [],
			$idTokenClaims,
			$tokenResponse,
			$profileResponse,
			$callback
		);
	}

	/**
	 * Resolves one identity field from mapping rules and available payloads.
	 *
	 * @param mixed $mapping Dot path or ordered list of dot paths.
	 * @param array<string, mixed> $attributes Primary profile attributes.
	 * @param array<string, mixed> $fallback Token response fallback fields.
	 * @param array<string, mixed> $idTokenClaims Verified id_token claim fallback fields.
	 * @return mixed First non-null mapped value, or null when no path matches.
	 */
	private function identityValue(mixed $mapping, array $attributes, array $fallback, array $idTokenClaims=[]): mixed {
		if(is_array($mapping)){
			foreach($mapping as $path){
				$value=$this->identityValue($path, $attributes, $fallback, $idTokenClaims);
				if($value!==null){
					return $value;
				}
			}
			return null;
		}
		if(!is_string($mapping) || trim($mapping)===''){
			return null;
		}
		return $this->arrayGet(
			$attributes,
			$mapping,
			$this->arrayGet($idTokenClaims, $mapping, $this->arrayGet($fallback, $mapping))
		);
	}

	/**
	 * Reads a dot-path value from a nested array.
	 *
	 * @param array<string, mixed> $source Source array to inspect.
	 * @param string $path Dot-separated path such as user.email.
	 * @param mixed $default Value returned when the path is absent.
	 * @return mixed nested value found by dot path, or the caller default when any segment is absent.
	 */
	private function arrayGet(array $source, string $path, mixed $default=null): mixed {
		$segments=array_values(array_filter(explode('.', trim($path)), static fn(string $segment): bool=>$segment!==''));
		if($segments===[]){
			return $default;
		}
		$value=$source;
		foreach($segments as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){
				return $default;
			}
			$value=$value[$segment];
		}
		return $value;
	}

	/**
	 * Creates an HTTP client using provider-specific transport config.
	 *
	 * @return HttpClient Client used for token, userinfo, revocation, discovery, and JWKS calls.
	 */
	private function httpClient(): HttpClient {
		return new HttpClient(is_array($this->configValue('http')) ? $this->configValue('http') : []);
	}

	/**
	 * Creates the state transaction store for this provider.
	 *
	 * @return StateStore Store scoped by provider name and configured TTL.
	 */
	private function stateStore(): StateStore {
		return new StateStore($this->name, (int)$this->configValue('state_ttl', 600));
	}

	/**
	 * Resolves the effective redirect URI for this request.
	 *
	 * @return string|null Override or configured redirect URI, or null when omitted.
	 */
	private function resolveRedirectUri(): ?string {
		$redirectUri=$this->redirectUriOverride ?? $this->configValue('redirect_uri');
		if(!is_string($redirectUri)){
			return null;
		}
		$redirectUri=trim($redirectUri);
		return $redirectUri!=='' ? $redirectUri : null;
	}

	/**
	 * Resolves configured or per-request scopes.
	 *
	 * @return array<int, string> Normalized scope list.
	 */
	private function resolveScopes(): array {
		$scopes=$this->scopesOverride ?? $this->configValue('scopes', []);
		return $this->normalizeScopes($scopes);
	}

	/**
	 * Normalizes scope strings or arrays into a unique ordered list.
	 *
	 * @param mixed $scopes Scope string using the configured separator, or an array of values.
	 * @return array<int, string> Non-empty unique scope names.
	 */
	private function normalizeScopes(mixed $scopes): array {
		if(is_string($scopes)){
			$separator=(string)$this->configValue('scope_separator', ' ');
			$scopes=$separator!=='' ? explode($separator, $scopes) : [$scopes];
		}
		if(!is_array($scopes)){
			return [];
		}
		return self::normalizeScopeList($scopes);
	}

	/**
	 * Normalizes scope values into first-seen order without blank entries.
	 *
	 * @param array<int, mixed> $scopes Raw scope values.
	 * @return array<int, string> Non-empty unique scope names.
	 */
	private static function normalizeScopeList(array $scopes): array {
		if(self::$lastScopeListInput===$scopes && self::$lastScopeListOutput!==null){
			return self::$lastScopeListOutput;
		}
		$normalized=[];
		$seen=[];
		foreach($scopes as $scope){
			$scope=trim((string)$scope);
			if($scope==='' || isset($seen[$scope])){
				continue;
			}
			$seen[$scope]=true;
			$normalized[]=$scope;
		}
		self::$lastScopeListInput=$scopes;
		return self::$lastScopeListOutput=$normalized;
	}

	/**
	 * Reads a required string configuration value.
	 *
	 * Discovery-backed keys are resolved through configValue() before validation.
	 *
	 * @param string $key Config key to read.
	 * @return string Trimmed non-empty config value.
	 *
	 * @throws OAuthException When the value is missing or empty.
	 */
	private function requiredConfig(string $key): string {
		$value=$this->configValue($key);
		$value=is_string($value) ? trim($value) : '';
		if($value===''){
			throw new OAuthException("OAuth provider '{$this->name}' is missing required config '{$key}'.");
		}
		return $value;
	}

	/**
	 * Reads explicit config, discovery config, or a default value.
	 *
	 * @param string $key Provider config key.
	 * @param mixed $default Fallback value when explicit and discovered values are absent.
	 * @return mixed explicit provider config value, discovered metadata value, or the caller default.
	 */
	private function configValue(string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $this->config)){
			return $this->config[$key];
		}
		$discoveryKey=$this->discoveryKey($key);
		if($discoveryKey!==null){
			$discovered=$this->discoveryConfiguration();
			if(array_key_exists($discoveryKey, $discovered)){
				return $discovered[$discoveryKey];
			}
		}
		return $default;
	}

	/**
	 * Fetches OpenID provider discovery configuration.
	 *
	 * Discovery failures are intentionally soft because explicit endpoint config can
	 * still satisfy non-discovery providers and partial OpenID setups.
	 *
	 * @return array<string, mixed> Discovered OpenID configuration, or an empty array on failure.
	 */
	private function discoveryConfiguration(): array {
		try{
			return OpenIdDiscovery::fetch($this->config);
		}
		catch(\Throwable){
			return [];
		}
	}

	/**
	 * Maps Dataphyre config names to OpenID discovery document fields.
	 *
	 * @param string $key Dataphyre provider config key.
	 * @return string|null Discovery field name, or null for non-discoverable keys.
	 */
	private function discoveryKey(string $key): ?string {
		return match($key){
			'authorization_url'=>'authorization_endpoint',
			'token_url'=>'token_endpoint',
			'userinfo_url'=>'userinfo_endpoint',
			'jwks_url'=>'jwks_uri',
			'revocation_url'=>'revocation_endpoint',
			'issuer'=>'issuer',
			default=>null,
		};
	}

	/**
	 * Determines whether the authorization flow should bind callbacks with state.
	 *
	 * State is forced on when PKCE or nonce is active because their generated values
	 * must be persisted between redirect and callback.
	 *
	 * @return bool True when state should be generated and stored.
	 */
	private function shouldUseState(): bool {
		if($this->stateless===true){
			return false;
		}
		if($this->shouldUsePkce()===true || $this->shouldUseNonce()===true){
			return true;
		}
		return (bool)$this->configValue('state', true);
	}

	/**
	 * Determines whether PKCE should be used for authorization-code exchange.
	 *
	 * @return bool True when PKCE is enabled and the provider is not stateless.
	 */
	private function shouldUsePkce(): bool {
		return $this->stateless===false && (bool)$this->configValue('pkce', true);
	}

	/**
	 * Determines whether an OpenID Connect nonce should be generated.
	 *
	 * @return bool True when nonce validation is enabled and the provider is not stateless.
	 */
	private function shouldUseNonce(): bool {
		return $this->stateless===false && (bool)$this->configValue('nonce', false);
	}

	/**
	 * Derives the PKCE code challenge for a verifier.
	 *
	 * @param string $codeVerifier High-entropy verifier stored in the state transaction.
	 * @return string Plain or S256 challenge sent to the authorization endpoint.
	 */
	private function codeChallenge(string $codeVerifier): string {
		$method=strtoupper((string)$this->configValue('pkce_method', 'S256'));
		if($method==='PLAIN'){
			return $codeVerifier;
		}
		return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
	}

	/**
	 * Generates a URL-safe random token.
	 *
	 * @param int $bytes Number of random bytes before base64url encoding.
	 * @return string Base64url token without padding.
	 */
	private function generateRandomToken(int $bytes): string {
		return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
	}

	/**
	 * Appends RFC3986-encoded query parameters to a URL.
	 *
	 * @param string $url Base URL that may already contain a query string.
	 * @param array<string, mixed> $query Query parameters to append.
	 * @return string URL with query parameters appended.
	 */
	private function appendQuery(string $url, array $query): string {
		$queryString=http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		if($queryString===''){
			return $url;
		}
		return str_contains($url, '?')
			? $url.'&'.$queryString
			: $url.'?'.$queryString;
	}

	/**
	 * Decodes a provider HTTP response body.
	 *
	 * JSON is preferred, with application/x-www-form-urlencoded parsing used as a
	 * compatibility fallback for OAuth endpoints that return form-encoded payloads.
	 *
	 * @param array<string, mixed> $response HTTP response array containing body.
	 * @return array<string, mixed> Decoded response payload.
	 */
	private function decodeResponseBody(array $response): array {
		$body=(string)($response['body'] ?? '');
		if(trim($body)===''){
			return [];
		}
		$decoded=json_decode($body, true);
		if(is_array($decoded)){
			return $decoded;
		}
		parse_str($body, $parsed);
		return is_array($parsed) ? $parsed : [];
	}

	/**
	 * Converts non-2xx provider responses into OAuth exceptions.
	 *
	 * @param array<string, mixed> $response HTTP response array containing status.
	 * @param array<string, mixed> $decoded Decoded error payload.
	 * @param string $operation Human-readable operation name for diagnostics.
	 * @return void
	 *
	 * @throws OAuthException When the provider response status is outside the 2xx range.
	 */
	private function throwForHttpError(array $response, array $decoded, string $operation): void {
		$status=(int)($response['status'] ?? 0);
		if($status>=200 && $status<300){
			return;
		}
		$message=$this->firstString($decoded, ['error_description', 'error_message', 'message', 'error']) ?? 'HTTP '.$status;
		throw new OAuthException("OAuth {$operation} failed for '{$this->name}': ".$message);
	}

	/**
	 * Reads extra provider parameters for a named operation.
	 *
	 * @param string $key Operation suffix such as authorize, token, refresh, revoke, or userinfo.
	 * @return array<string, mixed> Extra parameters configured for that operation.
	 */
	private function extraParameters(string $key): array {
		$configKey='extra_'.$key.'_parameters';
		$value=$this->configValue($configKey, []);
		return is_array($value) ? $value : [];
	}

	/**
	 * Returns the first non-empty string value for a set of keys.
	 *
	 * @param array<string, mixed> $source Payload to inspect.
	 * @param array<int, string> $keys Candidate keys in priority order.
	 * @return string|null Trimmed value, or null when none are present.
	 */
	private function firstString(array $source, array $keys): ?string {
		foreach($keys as $key){
			if(isset($source[$key]) && is_string($source[$key]) && trim($source[$key])!==''){
				return trim($source[$key]);
			}
		}
		return null;
	}

	/**
	 * Normalizes optional string fields.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|null Trimmed string, or null for non-strings and empty strings.
	 */
	private function nullableString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Normalizes optional integer fields.
	 *
	 * @param mixed $value Candidate numeric value.
	 * @return int|null Integer value, or null when not numeric.
	 */
	private function nullableInt(mixed $value): ?int {
		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * Normalizes optional boolean fields from provider payloads.
	 *
	 * @param mixed $value Candidate boolean, string, or integer value.
	 * @return bool|null Normalized boolean, or null when the value is ambiguous.
	 */
	private function nullableBool(mixed $value): ?bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_string($value)){
			$normalized=strtolower(trim($value));
			if(in_array($normalized, ['1', 'true', 'yes'], true)){
				return true;
			}
			if(in_array($normalized, ['0', 'false', 'no'], true)){
				return false;
			}
		}
		if(is_int($value)){
			return $value!==0;
		}
		return null;
	}

	/**
	 * Applies OAuth client authentication to request headers or payload.
	 *
	 * Supports none, client_secret_basic, client_id_post, and client_secret_post
	 * style authentication. The payload is mutated when credentials belong in the
	 * request body.
	 *
	 * @param array<string, mixed> $payload Mutable request payload.
	 * @param string $configKey Config key selecting the auth method for this operation.
	 * @return array<string, string> Headers required for client authentication.
	 */
	private function clientAuthHeadersAndPayload(array &$payload, string $configKey): array {
		$aliasKey=match($configKey){
			'token_auth_method'=>'token_endpoint_auth_method',
			'revocation_auth_method'=>'revocation_endpoint_auth_method',
			default=>null,
		};
		$authMethodConfig=$this->configValue($configKey);
		if(($authMethodConfig===null || $authMethodConfig==='') && $aliasKey!==null){
			$authMethodConfig=$this->configValue($aliasKey);
		}
		$authMethod=strtolower(trim((string)($authMethodConfig ?? $this->configValue('token_auth_method', 'client_secret_post'))));
		$headers=[];
		$clientId=$this->requiredConfig('client_id');
		$clientSecret=(string)$this->configValue('client_secret', '');
		if($authMethod==='none'){
			return $headers;
		}
		if($authMethod==='client_secret_basic'){
			$headers['Authorization']='Basic '.base64_encode($clientId.':'.$clientSecret);
			return $headers;
		}
		$payload['client_id']=$clientId;
		if($authMethod!=='client_id_post' && $clientSecret!==''){
			$payload['client_secret']=$clientSecret;
		}
		return $headers;
	}

	/**
	 * Extracts a refresh token from a string or OAuthUser.
	 *
	 * @param string|OAuthUser $refreshTokenOrUser Token string or user carrying token metadata.
	 * @return string Trimmed refresh token, possibly empty when unavailable.
	 */
	private function extractRefreshToken(string|OAuthUser $refreshTokenOrUser): string {
		if($refreshTokenOrUser instanceof OAuthUser){
			return trim((string)($refreshTokenOrUser->refreshToken() ?? ''));
		}
		return trim((string)$refreshTokenOrUser);
	}

	/**
	 * Extracts the token that should be sent to the revocation endpoint.
	 *
	 * @param string|OAuthUser $tokenOrUser Token string or user carrying token metadata.
	 * @param string|null $hint Optional token type hint used to choose access or refresh token.
	 * @return string Trimmed token value, possibly empty when unavailable.
	 */
	private function extractRevocationToken(string|OAuthUser $tokenOrUser, ?string $hint): string {
		if(is_string($tokenOrUser)){
			return trim($tokenOrUser);
		}
		$hint=$this->normalizeRevocationHint($hint, $tokenOrUser);
		if($hint==='refresh_token'){
			return trim((string)($tokenOrUser->refreshToken() ?? ''));
		}
		return trim((string)($tokenOrUser->accessToken() ?? ''));
	}

	/**
	 * Normalizes the revocation token type hint.
	 *
	 * OAuthUser values default to access_token because that is the token extracted
	 * without an explicit refresh_token hint.
	 *
	 * @param string|null $hint Caller-supplied hint.
	 * @param string|OAuthUser $tokenOrUser Token source used for defaulting.
	 * @return string|null access_token, refresh_token, or null when no valid hint applies.
	 */
	private function normalizeRevocationHint(?string $hint, string|OAuthUser $tokenOrUser): ?string {
		$hint=$hint!==null ? strtolower(trim($hint)) : null;
		if($hint==='access_token' || $hint==='refresh_token'){
			return $hint;
		}
		if($tokenOrUser instanceof OAuthUser){
			return 'access_token';
		}
		return null;
	}
}
