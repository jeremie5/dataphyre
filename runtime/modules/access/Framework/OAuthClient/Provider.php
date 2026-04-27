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

class Provider {

	private string $name;
	private array $config;
	private Manager $manager;
	private bool $stateless=false;
	private ?string $redirect_uri_override=null;
	private ?array $scopes_override=null;
	private array $parameters=[];

	public function __construct(string $name, array $config, Manager $manager){
		$this->name=trim($name);
		$this->config=$config;
		$this->manager=$manager;
	}

	public function name(): string {
		return $this->name;
	}

	public function config(): array {
		return $this->config;
	}

	public function manager(): Manager {
		return $this->manager;
	}

	public function stateless(bool $stateless=true): static {
		$clone=clone $this;
		$clone->stateless=$stateless;
		return $clone;
	}

	public function scopes(array $scopes): static {
		$clone=clone $this;
		$clone->scopes_override=array_values(array_unique(array_filter(array_map(
			static fn(mixed $scope): string=>trim((string)$scope),
			$scopes
		), static fn(string $scope): bool=>$scope!=='')));
		return $clone;
	}

	public function redirectUri(string $redirect_uri): static {
		$clone=clone $this;
		$clone->redirect_uri_override=trim($redirect_uri);
		return $clone;
	}

	public function with(array $parameters): static {
		$clone=clone $this;
		$clone->parameters=array_replace($clone->parameters, $parameters);
		return $clone;
	}

	public function authorizationRequest(): AuthorizationRequest {
		$authorization_url=$this->required_config('authorization_url');
		$state=$this->should_use_state() ? $this->generate_random_token(32) : null;
		$code_verifier=$this->should_use_pkce() ? $this->generate_random_token(64) : null;
		$nonce=$this->should_use_nonce() ? $this->generate_random_token(32) : null;
		$params=array_replace(
			$this->authorize_parameters($state, $code_verifier, $nonce),
			$this->extra_parameters('authorize'),
			$this->parameters
		);
		if($state!==null){
			$this->state_store()->put($state, [
				'redirect_uri'=>$this->resolve_redirect_uri(),
				'scopes'=>$this->resolve_scopes(),
				'code_verifier'=>$code_verifier,
				'nonce'=>$nonce,
			]);
		}
		return new AuthorizationRequest(
			$this->name,
			$this->append_query($authorization_url, $params),
			$state,
			$code_verifier,
			$nonce
		);
	}

	public function authorizationUrl(): string {
		return $this->authorizationRequest()->url();
	}

	public function redirect(): Response {
		return $this->authorizationRequest()->response();
	}

	public function user(Request|array|null $request=null): OAuthUser {
		$callback=$this->callback_parameters($request);
		$error=$this->first_string($callback, ['error', 'oauth_error']);
		if($error!==null){
			$message=$this->first_string($callback, ['error_description', 'error_message']) ?? $error;
			throw new OAuthException('OAuth authorization failed: '.$message);
		}
		$code=$this->first_string($callback, ['code']);
		if($code===null){
			throw new OAuthException("OAuth callback for '{$this->name}' is missing an authorization code.");
		}
		$transaction=$this->consume_transaction($callback);
		$token_response=$this->exchange_code_for_token($code, $transaction);
		return $this->oauth_user_from_token_response($token_response, $callback, $transaction);
	}

	public function userFromToken(string $access_token): OAuthUser {
		$access_token=trim($access_token);
		if($access_token===''){
			throw new OAuthException("OAuth access token cannot be empty for provider '{$this->name}'.");
		}
		return $this->oauth_user_from_token_response([
			'access_token'=>$access_token,
			'token_type'=>'Bearer',
		]);
	}

	public function refresh(string|OAuthUser $refresh_token_or_user): array {
		$refresh_token=$this->extract_refresh_token($refresh_token_or_user);
		if($refresh_token===''){
			throw new OAuthException("OAuth refresh token is missing for provider '{$this->name}'.");
		}
		$payload=[
			'grant_type'=>'refresh_token',
			'refresh_token'=>$refresh_token,
		];
		$headers=$this->client_auth_headers_and_payload($payload, 'refresh_auth_method');
		$response=$this->http_client()->send(
			'POST',
			$this->required_config('token_url'),
			array_replace($payload, $this->extra_parameters('refresh')),
			$headers
		);
		$decoded=$this->decode_response_body($response);
		$this->throw_for_http_error($response, $decoded, 'token refresh');
		if(!isset($decoded['access_token']) || !is_string($decoded['access_token']) || trim($decoded['access_token'])===''){
			throw new OAuthException("OAuth refresh response for '{$this->name}' is missing access_token.");
		}
		if(!isset($decoded['refresh_token']) || !is_string($decoded['refresh_token']) || trim($decoded['refresh_token'])===''){
			$decoded['refresh_token']=$refresh_token;
		}
		return $decoded;
	}

	public function refreshedUser(string|OAuthUser $refresh_token_or_user): OAuthUser {
		return $this->oauth_user_from_token_response($this->refresh($refresh_token_or_user));
	}

	public function revoke(string|OAuthUser $token_or_user, ?string $hint=null): bool {
		$token=$this->extract_revocation_token($token_or_user, $hint);
		if($token===''){
			throw new OAuthException("OAuth revocation token is missing for provider '{$this->name}'.");
		}
		$payload=[
			'token'=>$token,
		];
		$normalized_hint=$this->normalize_revocation_hint($hint, $token_or_user);
		if($normalized_hint!==null){
			$payload['token_type_hint']=$normalized_hint;
		}
		$headers=$this->client_auth_headers_and_payload($payload, 'revocation_auth_method');
		$response=$this->http_client()->send(
			'POST',
			$this->required_config('revocation_url'),
			array_replace($payload, $this->extra_parameters('revoke')),
			$headers
		);
		$decoded=$this->decode_response_body($response);
		$this->throw_for_http_error($response, $decoded, 'token revocation');
		return true;
	}

	public function resolveLocalUser(OAuthUser $oauth_user): mixed {
		$resolver=$this->config['resolve_user'] ?? null;
		if(!is_callable($resolver)){
			return null;
		}
		return $resolver($oauth_user, $this);
	}

	public function login(
		Request|array|OAuthUser|null $request_or_user=null,
		?string $guard=null,
		bool $remember=true
	): bool {
		$oauth_user=$request_or_user instanceof OAuthUser
			? $request_or_user
			: $this->user($request_or_user instanceof Request || is_array($request_or_user) ? $request_or_user : null);
		$local_user=$this->resolveLocalUser($oauth_user);
		if($local_user===null || $local_user===false){
			return false;
		}
		return Auth::login($local_user, $remember, $guard);
	}

	private function authorize_parameters(?string $state, ?string $code_verifier, ?string $nonce): array {
		$params=[
			'response_type'=>trim((string)($this->config['response_type'] ?? 'code')),
			'client_id'=>$this->required_config('client_id'),
		];
		$redirect_uri=$this->resolve_redirect_uri();
		if($redirect_uri!==null){
			$params['redirect_uri']=$redirect_uri;
		}
		$scopes=$this->resolve_scopes();
		if($scopes!==[]){
			$params['scope']=implode((string)($this->config['scope_separator'] ?? ' '), $scopes);
		}
		if($state!==null){
			$params['state']=$state;
		}
		if($nonce!==null){
			$params['nonce']=$nonce;
		}
		if($code_verifier!==null){
			$params['code_challenge']=$this->code_challenge($code_verifier);
			$params['code_challenge_method']=strtoupper((string)($this->config['pkce_method'] ?? 'S256'));
		}
		return $params;
	}

	private function callback_parameters(Request|array|null $request): array {
		if($request instanceof Request){
			$callback=$request->query();
			return is_array($callback) ? $callback : [];
		}
		if(is_array($request)){
			return $request;
		}
		return $_GET;
	}

	private function consume_transaction(array $callback): ?array {
		if($this->stateless===true){
			return null;
		}
		if($this->should_use_state()===false){
			return null;
		}
		$state=$this->first_string($callback, ['state']);
		if($state===null){
			throw new InvalidOAuthStateException("OAuth callback for '{$this->name}' is missing state.");
		}
		$transaction=$this->state_store()->pull($state);
		if(!is_array($transaction)){
			throw new InvalidOAuthStateException("OAuth state is invalid or expired for provider '{$this->name}'.");
		}
		return $transaction;
	}

	private function oauth_user_from_token_response(
		array $token_response,
		array $callback=[],
		?array $transaction=null
	): OAuthUser {
		$id_token_payload=$this->validate_id_token($token_response, $transaction);
		$id_token_claims=$id_token_payload?->claims() ?? [];
		$profile_response=$this->fetch_userinfo($token_response);
		if($profile_response===[] && $id_token_claims!==[]){
			$profile_response=$id_token_claims;
		}
		return $this->map_user($token_response, $profile_response, $callback, $id_token_claims);
	}

	private function validate_id_token(array $token_response, ?array $transaction=null): ?JwtPayload {
		$id_token=trim((string)($token_response['id_token'] ?? ''));
		if($id_token===''){
			return null;
		}
		if((bool)$this->config_value('verify_id_token', true)===false){
			return null;
		}
		$client_id=$this->required_config('client_id');
		$jwt_config=[
			'algorithms'=>$this->config_value('id_token_algorithms', ['RS256']),
			'issuer'=>$this->config_value('id_token_issuer', $this->config_value('issuer')),
			'audience'=>$this->config_value('id_token_audience', $client_id),
			'leeway'=>(int)$this->config_value('id_token_leeway', 60),
		];
		$has_direct_key=false;
		foreach(['public_key', 'verification_key', 'key'] as $direct_key){
			$config_value=$this->config_value('id_token_'.$direct_key);
			if(is_string($config_value) && trim($config_value)!==''){
				$jwt_config[$direct_key]=trim($config_value);
				$has_direct_key=true;
			}
		}
		if($has_direct_key===false){
			$jwt_config['key_resolver']=function(string $algorithm, array $headers, array $claims, array $config): string {
				return JwksResolver::resolve($algorithm, $headers, $this->id_token_key_config());
			};
		}
		$payload=JwtCodec::decode($id_token, $jwt_config);
		$expected_nonce=$transaction['nonce'] ?? $this->config_value('expected_nonce');
		if(is_string($expected_nonce) && $expected_nonce!==''){
			$received_nonce=(string)($payload->claim('nonce') ?? '');
			if($received_nonce==='' || hash_equals($expected_nonce, $received_nonce)===false){
				throw new OAuthException("OAuth id_token nonce is invalid for provider '{$this->name}'.");
			}
		}
		return $payload;
	}

	private function id_token_key_config(): array {
		$config=[
			'http'=>is_array($this->config_value('http')) ? $this->config_value('http') : [],
		];
		foreach([
			'jwks',
			'jwks_url',
			'issuer',
			'discover',
			'discovery_url',
			'openid_configuration_url',
		] as $key){
			$value=$this->config_value($key);
			if($value!==null){
				$config[$key]=$value;
			}
		}
		return $config;
	}

	private function exchange_code_for_token(string $code, ?array $transaction): array {
		$payload=[
			'grant_type'=>trim((string)($this->config['grant_type'] ?? 'authorization_code')),
			'code'=>$code,
		];
		$redirect_uri=$transaction['redirect_uri'] ?? $this->resolve_redirect_uri();
		if(is_string($redirect_uri) && $redirect_uri!==''){
			$payload['redirect_uri']=$redirect_uri;
		}
		if(isset($transaction['code_verifier']) && is_string($transaction['code_verifier']) && $transaction['code_verifier']!==''){
			$payload['code_verifier']=$transaction['code_verifier'];
		}
		$headers=$this->client_auth_headers_and_payload($payload, 'token_auth_method');
		$response=$this->http_client()->send(
			'POST',
			$this->required_config('token_url'),
			array_replace($payload, $this->extra_parameters('token')),
			$headers
		);
		$decoded=$this->decode_response_body($response);
		$this->throw_for_http_error($response, $decoded, 'token exchange');
		if(!isset($decoded['access_token']) || !is_string($decoded['access_token']) || trim($decoded['access_token'])===''){
			throw new OAuthException("OAuth token response for '{$this->name}' is missing access_token.");
		}
		return $decoded;
	}

	private function fetch_userinfo(array $token_response): array {
		$userinfo_url=trim((string)$this->config_value('userinfo_url', ''));
		if($userinfo_url===''){
			return [];
		}
		$access_token=trim((string)($token_response['access_token'] ?? ''));
		if($access_token===''){
			return [];
		}
		$method=strtoupper(trim((string)$this->config_value('userinfo_method', 'GET')));
		$auth_method=strtolower(trim((string)$this->config_value('userinfo_auth_method', 'bearer')));
		$headers=is_array($this->config_value('userinfo_headers')) ? $this->config_value('userinfo_headers') : [];
		$query=[];
		$body=null;
		if($auth_method==='bearer'){
			$headers['Authorization']='Bearer '.$access_token;
		}
		elseif($auth_method==='query'){
			$query[(string)$this->config_value('userinfo_token_parameter', 'access_token')]=$access_token;
		}
		elseif($auth_method==='form'){
			$body=[(string)$this->config_value('userinfo_token_parameter', 'access_token')=>$access_token];
		}
		$extra_query=is_array($this->config_value('userinfo_query')) ? $this->config_value('userinfo_query') : [];
		$query=array_replace($query, $extra_query);
		if(is_array($body)){
			$body=array_replace($body, $this->extra_parameters('userinfo'));
		}
		$response=$this->http_client()->send($method, $userinfo_url, $body, $headers, $query);
		$decoded=$this->decode_response_body($response);
		$this->throw_for_http_error($response, $decoded, 'userinfo fetch');
		return $decoded;
	}

	private function map_user(
		array $token_response,
		array $profile_response,
		array $callback,
		array $id_token_claims=[]
	): OAuthUser {
		$identity=is_array($this->config_value('identity')) ? $this->config_value('identity') : [];
		$attributes=$profile_response!==[] ? $profile_response : ($id_token_claims!==[] ? $id_token_claims : $token_response);
		$id=$this->identity_value($identity['id'] ?? ['sub', 'id', 'user.id'], $attributes, $token_response, $id_token_claims);
		$email=$this->nullable_string($this->identity_value($identity['email'] ?? 'email', $attributes, $token_response, $id_token_claims));
		$name=$this->nullable_string($this->identity_value($identity['name'] ?? ['name', 'full_name'], $attributes, $token_response, $id_token_claims));
		$nickname=$this->nullable_string($this->identity_value($identity['nickname'] ?? ['nickname', 'preferred_username', 'login'], $attributes, $token_response, $id_token_claims));
		$avatar=$this->nullable_string($this->identity_value($identity['avatar'] ?? ['picture', 'avatar', 'avatar_url'], $attributes, $token_response, $id_token_claims));
		$email_verified=$this->nullable_bool($this->identity_value($identity['email_verified'] ?? ['email_verified', 'verified_email'], $attributes, $token_response, $id_token_claims));
		$scopes=$this->normalize_scopes($token_response['scope'] ?? $token_response['scopes'] ?? []);
		$expires_in=$this->nullable_int($token_response['expires_in'] ?? null);
		return new OAuthUser(
			$this->name,
			(is_int($id) || is_string($id)) ? $id : null,
			$nickname,
			$name,
			$email,
			$email_verified,
			$avatar,
			$this->nullable_string($token_response['access_token'] ?? null),
			$this->nullable_string($token_response['refresh_token'] ?? null),
			$this->nullable_string($token_response['id_token'] ?? null),
			$this->nullable_string($token_response['token_type'] ?? null),
			$expires_in,
			$scopes,
			is_array($attributes) ? $attributes : [],
			$id_token_claims,
			$token_response,
			$profile_response,
			$callback
		);
	}

	private function identity_value(mixed $mapping, array $attributes, array $fallback, array $id_token_claims=[]): mixed {
		if(is_array($mapping)){
			foreach($mapping as $path){
				$value=$this->identity_value($path, $attributes, $fallback, $id_token_claims);
				if($value!==null){
					return $value;
				}
			}
			return null;
		}
		if(!is_string($mapping) || trim($mapping)===''){
			return null;
		}
		return $this->array_get(
			$attributes,
			$mapping,
			$this->array_get($id_token_claims, $mapping, $this->array_get($fallback, $mapping))
		);
	}

	private function array_get(array $source, string $path, mixed $default=null): mixed {
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

	private function http_client(): HttpClient {
		return new HttpClient(is_array($this->config_value('http')) ? $this->config_value('http') : []);
	}

	private function state_store(): StateStore {
		return new StateStore($this->name, (int)$this->config_value('state_ttl', 600));
	}

	private function resolve_redirect_uri(): ?string {
		$redirect_uri=$this->redirect_uri_override ?? $this->config_value('redirect_uri');
		if(!is_string($redirect_uri)){
			return null;
		}
		$redirect_uri=trim($redirect_uri);
		return $redirect_uri!=='' ? $redirect_uri : null;
	}

	private function resolve_scopes(): array {
		$scopes=$this->scopes_override ?? $this->config_value('scopes', []);
		return $this->normalize_scopes($scopes);
	}

	private function normalize_scopes(mixed $scopes): array {
		if(is_string($scopes)){
			$separator=(string)$this->config_value('scope_separator', ' ');
			$scopes=$separator!=='' ? explode($separator, $scopes) : [$scopes];
		}
		if(!is_array($scopes)){
			return [];
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $scope): string=>trim((string)$scope),
			$scopes
		), static fn(string $scope): bool=>$scope!=='')));
	}

	private function required_config(string $key): string {
		$value=$this->config_value($key);
		$value=is_string($value) ? trim($value) : '';
		if($value===''){
			throw new OAuthException("OAuth provider '{$this->name}' is missing required config '{$key}'.");
		}
		return $value;
	}

	private function config_value(string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $this->config)){
			return $this->config[$key];
		}
		$discovery_key=$this->discovery_key($key);
		if($discovery_key!==null){
			$discovered=$this->discovery_configuration();
			if(array_key_exists($discovery_key, $discovered)){
				return $discovered[$discovery_key];
			}
		}
		return $default;
	}

	private function discovery_configuration(): array {
		try{
			return OpenIdDiscovery::fetch($this->config);
		}
		catch(\Throwable){
			return [];
		}
	}

	private function discovery_key(string $key): ?string {
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

	private function should_use_state(): bool {
		if($this->stateless===true){
			return false;
		}
		if($this->should_use_pkce()===true || $this->should_use_nonce()===true){
			return true;
		}
		return (bool)$this->config_value('state', true);
	}

	private function should_use_pkce(): bool {
		return $this->stateless===false && (bool)$this->config_value('pkce', true);
	}

	private function should_use_nonce(): bool {
		return $this->stateless===false && (bool)$this->config_value('nonce', false);
	}

	private function code_challenge(string $code_verifier): string {
		$method=strtoupper((string)$this->config_value('pkce_method', 'S256'));
		if($method==='PLAIN'){
			return $code_verifier;
		}
		return rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
	}

	private function generate_random_token(int $bytes): string {
		return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
	}

	private function append_query(string $url, array $query): string {
		$query_string=http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		if($query_string===''){
			return $url;
		}
		return str_contains($url, '?')
			? $url.'&'.$query_string
			: $url.'?'.$query_string;
	}

	private function decode_response_body(array $response): array {
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

	private function throw_for_http_error(array $response, array $decoded, string $operation): void {
		$status=(int)($response['status'] ?? 0);
		if($status>=200 && $status<300){
			return;
		}
		$message=$this->first_string($decoded, ['error_description', 'error_message', 'message', 'error']) ?? 'HTTP '.$status;
		throw new OAuthException("OAuth {$operation} failed for '{$this->name}': ".$message);
	}

	private function extra_parameters(string $key): array {
		$config_key='extra_'.$key.'_parameters';
		$value=$this->config_value($config_key, []);
		return is_array($value) ? $value : [];
	}

	private function first_string(array $source, array $keys): ?string {
		foreach($keys as $key){
			if(isset($source[$key]) && is_string($source[$key]) && trim($source[$key])!==''){
				return trim($source[$key]);
			}
		}
		return null;
	}

	private function nullable_string(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	private function nullable_int(mixed $value): ?int {
		return is_numeric($value) ? (int)$value : null;
	}

	private function nullable_bool(mixed $value): ?bool {
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

	private function client_auth_headers_and_payload(array &$payload, string $config_key): array {
		$alias_key=match($config_key){
			'token_auth_method'=>'token_endpoint_auth_method',
			'revocation_auth_method'=>'revocation_endpoint_auth_method',
			default=>null,
		};
		$auth_method_config=$this->config_value($config_key);
		if(($auth_method_config===null || $auth_method_config==='') && $alias_key!==null){
			$auth_method_config=$this->config_value($alias_key);
		}
		$auth_method=strtolower(trim((string)($auth_method_config ?? $this->config_value('token_auth_method', 'client_secret_post'))));
		$headers=[];
		$client_id=$this->required_config('client_id');
		$client_secret=(string)$this->config_value('client_secret', '');
		if($auth_method==='none'){
			return $headers;
		}
		if($auth_method==='client_secret_basic'){
			$headers['Authorization']='Basic '.base64_encode($client_id.':'.$client_secret);
			return $headers;
		}
		$payload['client_id']=$client_id;
		if($auth_method!=='client_id_post' && $client_secret!==''){
			$payload['client_secret']=$client_secret;
		}
		return $headers;
	}

	private function extract_refresh_token(string|OAuthUser $refresh_token_or_user): string {
		if($refresh_token_or_user instanceof OAuthUser){
			return trim((string)($refresh_token_or_user->refreshToken() ?? ''));
		}
		return trim((string)$refresh_token_or_user);
	}

	private function extract_revocation_token(string|OAuthUser $token_or_user, ?string $hint): string {
		if(is_string($token_or_user)){
			return trim($token_or_user);
		}
		$hint=$this->normalize_revocation_hint($hint, $token_or_user);
		if($hint==='refresh_token'){
			return trim((string)($token_or_user->refreshToken() ?? ''));
		}
		return trim((string)($token_or_user->accessToken() ?? ''));
	}

	private function normalize_revocation_hint(?string $hint, string|OAuthUser $token_or_user): ?string {
		$hint=$hint!==null ? strtolower(trim($hint)) : null;
		if($hint==='access_token' || $hint==='refresh_token'){
			return $hint;
		}
		if($token_or_user instanceof OAuthUser){
			return 'access_token';
		}
		return null;
	}
}
