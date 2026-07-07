<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre;

if(defined('DATAPHYRE_VESTRA_RUNTIME_MODULE_LOADED')){
	return;
}
define('DATAPHYRE_VESTRA_RUNTIME_MODULE_LOADED', true);

if(class_exists(__NAMESPACE__.'\vestra', false)){
	return;
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('vestra', 'DP_VESTRA_CFG', [
	'base_url'=>'',
	'object_url'=>'',
	'default_tenant'=>'',
	'tenant'=>'',
	'rate'=>'',
	'api_url'=>'',
	'api_token'=>'',
	'api_auth_mode'=>'control_key',
	'organization'=>'',
	'ca_bundle'=>'',
	'write_token'=>'',
	'write_token_path'=>'',
	'write_token_ttl'=>300,
	'default_write_max_bytes'=>67108864,
	'node_token'=>'',
	'token_ttl'=>3600,
	'token_grace'=>60,
	'use_tenant_grant'=>true,
	'allow_unsigned'=>false,
	'tenants'=>[],
]);
if(function_exists('sql_define_table')){
	sql_define_table('dataphyre.vestra_objects', __DIR__.'/vestra.tables.php', 'objects');
}

$cache_directory=ROOTPATH['common_dataphyre'].'cache/vestra/';
if(!is_dir($cache_directory)){
	@mkdir($cache_directory, 0775, true);
}
if(!is_writable($cache_directory)){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreVestra: Missing cache folder write permission.', 'fatal');
}

/**
 * Kernel integration for Dataphyre Vestra object storage.
 *
 * The Vestra kernel maps local files and remote resource URLs to Vestra Fabric
 * references, builds URLs from those references, updates application-owned use
 * counts, rewrites HTML resources, and pushes assets to the configured Vestra API.
 */
class vestra{

	/**
	 * Reads a Vestra module configuration value from DP_VESTRA_CFG.
	 *
	 * @param string $key Config key to read.
	 * @param mixed $default Fallback when config is missing or not an array.
	 * @return mixed Vestra configuration value from DP_VESTRA_CFG, or the caller fallback when absent.
	 */
	private static function config(string $key, mixed $default=null): mixed {
		$config=defined('\DP_VESTRA_CFG') ? constant('\DP_VESTRA_CFG') : [];
		return is_array($config) ? ($config[$key] ?? $default) : $default;
	}

	/**
	 * Returns the full Vestra module configuration.
	 *
	 * @return array<string,mixed> Vestra configuration.
	 */
	private static function config_all(): array {
		$config=defined('\DP_VESTRA_CFG') ? constant('\DP_VESTRA_CFG') : [];
		return is_array($config) ? $config : [];
	}

	/**
	 * Resolves a configured tenant profile.
	 *
	 * Flat config keys remain the default profile. Entries under `tenants` override
	 * those defaults for a specific Fabric tenant id or profile alias.
	 *
	 * @param string $tenant Tenant id or configured profile alias.
	 * @return array<string,mixed> Resolved tenant profile.
	 */
	private static function tenant_profile(string $tenant=''): array {
		$config=self::config_all();
		$profile=$config;
		unset($profile['tenants']);
		$tenants=is_array($config['tenants'] ?? null) ? $config['tenants'] : [];
		$tenant=trim($tenant);
		$profile_key=$tenant;
		if($profile_key==='' && isset($config['default_tenant'])){
			$profile_key=trim((string)$config['default_tenant']);
		}
		if($profile_key==='' && isset($config['tenant'])){
			$profile_key=trim((string)$config['tenant']);
		}
		if($profile_key!=='' && isset($tenants[$profile_key]) && is_array($tenants[$profile_key])){
			$profile=array_merge($profile, $tenants[$profile_key]);
			if(!isset($profile['tenant']) || trim((string)$profile['tenant'])===''){
				$profile['tenant']=$profile_key;
			}
		}
		return $profile;
	}

	/**
	 * Reads a value from a tenant profile with global config fallback.
	 *
	 * @param string $key Config key.
	 * @param string $tenant Tenant id or profile alias.
	 * @param mixed $default Fallback.
	 * @return mixed Resolved profile value.
	 */
	private static function profile_config(string $key, string $tenant='', mixed $default=null): mixed {
		$profile=self::tenant_profile($tenant);
		return $profile[$key] ?? self::config($key, $default);
	}

	/**
	 * Returns the configured Vestra API base URL with a trailing slash.
	 *
	 * @return string Vestra API base URL, or an empty string when not configured.
	 */
	private static function base_url(string $tenant=''): string {
		$base_url=trim((string)self::profile_config('base_url', $tenant, ''));
		if($base_url===''){
			$base_url=trim((string)(getenv('VESTRA_URL') ?: getenv('VESTRA_BASE_URL') ?: ''));
		}
		if($base_url===''){
			return '';
		}
		return rtrim($base_url, '/').'/';
	}

	/**
	 * Returns the public Fabric URL base with a trailing slash.
	 *
	 * @return string Public Fabric URL base, or an empty string when not configured.
	 */
	private static function public_base_url(string $tenant=''): string {
		$object_url=trim((string)self::profile_config('object_url', $tenant, ''));
		if($object_url==='' && defined('\CFG') && is_array(\CFG)){
			$object_url=trim((string)(\CFG['vestra_object_url'] ?? ''));
		}
		if($object_url==='' && function_exists('\config')){
			$object_url=trim((string)\config('vestra_object_url'));
		}
		if($object_url===''){
			$object_url=trim((string)(getenv('VESTRA_OBJECT_URL') ?: getenv('VESTRA_PUBLIC_URL') ?: ''));
		}
		if($object_url===''){
			$base_url=self::base_url($tenant);
			if($base_url===''){
				return '';
			}
			return $base_url;
		}
		return rtrim($object_url, '/').'/';
	}

	/**
	 * Returns the configured application tenant for Vestra accounting and URL query context.
	 *
	 * @return string Tenant identifier, or an empty string when the application does not need one.
	 */
	private static function tenant(): string {
		$tenant=trim((string)self::config('default_tenant', ''));
		if($tenant===''){
			$tenant=trim((string)self::config('tenant', ''));
		}
		if($tenant==='' && defined('\CFG') && is_array(\CFG)){
			$tenant=trim((string)(\CFG['vestra_tenant'] ?? ''));
		}
		if($tenant==='' && function_exists('\config')){
			$tenant=trim((string)\config('vestra_tenant'));
		}
		return $tenant;
	}

	/**
	 * Returns the default Vestra Fabric rate for tenant URLs.
	 *
	 * @return string Normalized rate fallback.
	 */
	private static function rate(string $tenant=''): string {
		$rate=trim((string)self::profile_config('rate', $tenant, ''));
		if($rate==='' && defined('\CFG') && is_array(\CFG)){
			$rate=trim((string)(\CFG['vestra_rate'] ?? \CFG['vestra_plan'] ?? ''));
		}
		if($rate==='' && function_exists('\config')){
			$rate=trim((string)(\config('vestra_rate') ?: \config('vestra_plan')));
		}
		return $rate!=='' ? $rate : 's';
	}

	/**
	 * Returns the Vestra Control API base used to mint scoped tenant tokens.
	 *
	 * @return string Control API URL ending in `/api/`, or an empty string when unavailable.
	 */
	private static function api_url(string $tenant=''): string {
		$api_url=trim((string)self::profile_config('api_url', $tenant, ''));
		if($api_url==='' && defined('\CFG') && is_array(\CFG)){
			$api_url=trim((string)(\CFG['vestra_api_url'] ?? ''));
		}
		if($api_url==='' && function_exists('\config')){
			$api_url=trim((string)\config('vestra_api_url'));
		}
		if($api_url===''){
			$api_url=trim((string)(getenv('VESTRA_API_URL') ?: ''));
		}
		if($api_url===''){
			$base_url=self::base_url($tenant);
			if($base_url!==''){
				$api_url=rtrim($base_url, '/').'/control/api';
			}
		}
		return $api_url!=='' ? rtrim($api_url, '/').'/' : '';
	}

	/**
	 * Reports whether any Vestra URL endpoint is configured.
	 *
	 * @return bool True when either the Vestra API URL or public object URL is available.
	 */
	public static function configured(): bool {
		return self::base_url()!=='' || self::public_base_url()!=='';
	}

	/**
	 * Returns the Vestra write token used by write-side routes.
	 *
	 * The token is scoped and issued by Vestra/control-plane code. It is kept
	 * separate from the Dataphyre private key so application writes do not borrow
	 * node-level authority.
	 *
	 * @return string Configured Vestra write token, or an empty string.
	 */
	private static function vestra_api_token(string $tenant=''): string {
		$token=trim((string)self::profile_config('api_token', $tenant, ''));
		if($token==='' && defined('\CFG') && is_array(\CFG)){
			$token=trim((string)(\CFG['vestra_api_token'] ?? ''));
		}
		if($token==='' && function_exists('\config')){
			$token=trim((string)\config('vestra_api_token'));
		}
		if($token===''){
			$token=trim((string)(getenv('VESTRA_API_TOKEN') ?: ''));
		}
		return $token;
	}

	private static function vestra_tenant_read_token(string $tenant=''): string {
		$token=trim((string)self::profile_config('tenant_read_token', $tenant, ''));
		if($token==='' && defined('\CFG') && is_array(\CFG)){
			$token=trim((string)(\CFG['vestra_tenant_read_token'] ?? ''));
		}
		if($token==='' && function_exists('\config')){
			$token=trim((string)\config('vestra_tenant_read_token'));
		}
		if($token===''){
			$token=trim((string)(getenv('VESTRA_TENANT_READ_TOKEN') ?: getenv('SHOPIRO_VESTRA_TENANT_READ_TOKEN') ?: ''));
		}
		return $token;
	}

	private static function api_auth_mode(string $tenant=''): string {
		$mode=strtolower(trim((string)self::profile_config('api_auth_mode', $tenant, '')));
		if($mode==='' && defined('\CFG') && is_array(\CFG)){
			$mode=strtolower(trim((string)(\CFG['vestra_api_auth_mode'] ?? '')));
		}
		if($mode==='' && function_exists('\config')){
			$mode=strtolower(trim((string)\config('vestra_api_auth_mode')));
		}
		if($mode===''){
			$mode=strtolower(trim((string)(getenv('VESTRA_API_AUTH_MODE') ?: '')));
		}
		return in_array($mode, ['bearer', 'session'], true) ? 'bearer' : 'control_key';
	}

	private static function organization(string $tenant=''): string {
		$organization=trim((string)self::profile_config('organization', $tenant, ''));
		if($organization==='' && defined('\CFG') && is_array(\CFG)){
			$organization=trim((string)(\CFG['vestra_organization'] ?? ''));
		}
		if($organization==='' && function_exists('\config')){
			$organization=trim((string)\config('vestra_organization'));
		}
		if($organization===''){
			$organization=trim((string)(getenv('VESTRA_ORGANIZATION') ?: ''));
		}
		if($organization===''){
			$organization=trim($tenant);
		}
		if($organization===''){
			$organization=self::tenant();
		}
		return $organization;
	}

	private static function ca_bundle(string $tenant=''): string {
		$path=trim((string)self::profile_config('ca_bundle', $tenant, ''));
		if($path==='' && defined('\CFG') && is_array(\CFG)){
			$path=trim((string)(\CFG['vestra_ca_bundle'] ?? ''));
		}
		if($path==='' && function_exists('\config')){
			$path=trim((string)\config('vestra_ca_bundle'));
		}
		if($path===''){
			$path=trim((string)(getenv('VESTRA_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE') ?: ''));
		}
		return $path!=='' && is_file($path) ? $path : '';
	}

	private static function configure_curl_tls(\CurlHandle $curl, string $tenant=''): void {
		$ca_bundle=self::ca_bundle($tenant);
		if($ca_bundle!==''){
			curl_setopt($curl, CURLOPT_CAINFO, $ca_bundle);
		}
	}

	/**
	 * Sends a request to the public Vestra Control API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Control API route relative to `/api`.
	 * @param array<string,mixed> $payload Request body.
	 * @param string $tenant Tenant/profile context for credentials.
	 * @param string $idempotency_key Optional idempotency key.
	 * @param string $encoding Body encoding. Use `form` for tenant control routes that parse form input.
	 * @return array<string,mixed>|false Decoded envelope or false on transport failure.
	 */
	private static function control_request(string $method, string $path, array $payload=[], string $tenant='', string $idempotency_key='', string $encoding='json', array $credentials=[]): array|false {
		$api_url=isset($credentials['api_url']) && is_scalar($credentials['api_url']) ? trim((string)$credentials['api_url']) : '';
		if($api_url===''){
			$api_url=self::api_url($tenant);
		}
		$api_token=isset($credentials['api_token']) && is_scalar($credentials['api_token']) ? trim((string)$credentials['api_token']) : '';
		if($api_token===''){
			$api_token=self::vestra_api_token($tenant);
		}
		$api_auth_mode=isset($credentials['api_auth_mode']) && is_scalar($credentials['api_auth_mode']) ? strtolower(trim((string)$credentials['api_auth_mode'])) : self::api_auth_mode($tenant);
		if($api_url==='' || $api_token===''){
			return false;
		}
		$encoding=strtolower(trim($encoding));
		if(!in_array($encoding, ['json', 'form'], true)){
			$encoding='json';
		}
		$headers=[
			'Accept: application/json',
			'Content-Type: '.($encoding==='form' ? 'application/x-www-form-urlencoded' : 'application/json'),
		];
		if($idempotency_key!==''){
			$headers[]='Idempotency-Key: '.$idempotency_key;
		}
		if($api_auth_mode==='bearer' || $api_auth_mode==='session'){
			$headers[]='Authorization: Bearer '.$api_token;
		}
		else
		{
			$headers[]='X-Vestra-Control-Key: '.$api_token;
		}
		$curl=curl_init();
		self::configure_curl_tls($curl, $tenant);
		curl_setopt($curl, CURLOPT_URL, rtrim($api_url, '/').'/'.ltrim($path, '/'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		if(strtoupper($method)!=='GET' && strtoupper($method)!=='HEAD'){
			curl_setopt($curl, CURLOPT_POSTFIELDS, $encoding==='form' ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : json_encode($payload, JSON_UNESCAPED_SLASHES));
		}
		$result=curl_exec($curl);
		if($result===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed reaching Vestra Control: '.curl_error($curl), $S='fatal');
			curl_close($curl);
			return false;
		}
		curl_close($curl);
		$decoded=json_decode($result, true);
		if(!is_array($decoded)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra Control returned invalid JSON.', $S='fatal');
			return false;
		}
		return $decoded;
	}

	/**
	 * Returns a scoped write token, minting one from the configured API token when needed.
	 *
	 * @param string $tenant Tenant id or profile alias.
	 * @param string $method HTTP method being authorized.
	 * @param string $path HTTP path being authorized.
	 * @param array<string,mixed> $context Token issuance facts.
	 * @return string Configured or freshly issued Vestra write token.
	 */
	private static function vestra_write_token(string $tenant='', string $method='PUT', string $path='', array $context=[]): string {
		$token=trim((string)self::profile_config('write_token', $tenant, ''));
		if($token==='' && defined('\CFG') && is_array(\CFG)){
			$token=trim((string)(\CFG['vestra_write_token'] ?? \CFG['vestra_write_token'] ?? ''));
		}
		if($token==='' && function_exists('\config')){
			$token=trim((string)(\config('vestra_write_token') ?: \config('vestra_write_token')));
		}
		if($token===''){
			$token=trim((string)(getenv('VESTRA_WRITE_TOKEN') ?: ''));
		}
		if($token!==''){
			return $token;
		}
		$api_token=self::vestra_api_token($tenant);
		if($api_token===''){
			return '';
		}
		$rate=trim((string)($context['rate'] ?? self::rate($tenant)));
		$scope_path=self::write_token_path($path, $tenant, $rate, $context);
		$max_bytes=max(1, (int)($context['max_bytes'] ?? self::default_write_max_bytes($tenant)));
		return self::issue_write_token($tenant, strtoupper($method), $scope_path, $max_bytes, $rate, $context);
	}

	private static function write_token_path(string $request_path, string $tenant, string $rate, array $context=[]): string {
		$path=trim((string)($context['write_token_path'] ?? self::profile_config('write_token_path', $tenant, '')));
		if($path===''){
			$path=trim($request_path);
		}
		if($path===''){
			$path='/v/{tenant}/{rate}/*';
		}
		return strtr($path, [
			'{tenant}'=>$tenant,
			'{rate}'=>$rate,
			'{plan}'=>$rate,
			'{blockid}'=>'*',
		]);
	}

	private static function default_write_max_bytes(string $tenant=''): int {
		$value=(int)self::profile_config('default_write_max_bytes', $tenant, 0);
		if($value<=0 && defined('\CFG') && is_array(\CFG)){
			$value=(int)(\CFG['vestra_default_write_max_bytes'] ?? 0);
		}
		if($value<=0 && function_exists('\config')){
			$value=(int)\config('vestra_default_write_max_bytes');
		}
		if($value<=0){
			$value=(int)(getenv('VESTRA_DEFAULT_WRITE_MAX_BYTES') ?: 67108864);
		}
		return max(1, $value);
	}

	/**
	 * Issues a native Vestra write token through the public control API.
	 *
	 * @param string $tenant Tenant id.
	 * @param string $method Authorized method.
	 * @param string $path Authorized request path.
	 * @param int $max_bytes Signed byte ceiling.
	 * @param string $rate Fabric rate.
	 * @param array<string,mixed> $context Additional token request facts.
	 * @return string Native compact write token, or an empty string on failure.
	 */
	private static function issue_write_token(string $tenant, string $method, string $path, int $max_bytes, string $rate, array $context=[]): string {
		static $cache=[];
		$ttl=max(1, min(3600, (int)($context['expires_in_secs'] ?? self::profile_config('write_token_ttl', $tenant, 300))));
		$key=implode('|', [$tenant, $rate, $method, $path, (string)$max_bytes, (string)$ttl]);
		if(isset($cache[$key]) && is_array($cache[$key])){
			$expires_at=(int)($cache[$key]['expires_at'] ?? 0);
			if($expires_at===0 || $expires_at>time()+30){
				return (string)($cache[$key]['token'] ?? '');
			}
			unset($cache[$key]);
		}
		$api_url=self::api_url($tenant);
		$api_token=self::vestra_api_token($tenant);
		if($api_url==='' || $api_token===''){
			return '';
		}
		$payload=[
			'rate'=>$rate,
			'method'=>$method,
			'path'=>$path,
			'max_bytes'=>$max_bytes,
			'expires_in_secs'=>$ttl,
		];
		foreach(['prepaid_amount_cents', 'prepaid_authorization', 'prepaid_idempotency_key', 'idempotency_key', 'write_id', 'upload_id'] as $key_name){
			if(isset($context[$key_name]) && is_scalar($context[$key_name]) && trim((string)$context[$key_name])!==''){
				$payload[$key_name]=$context[$key_name];
			}
		}
		$curl=curl_init();
		self::configure_curl_tls($curl, $tenant);
		curl_setopt($curl, CURLOPT_URL, rtrim($api_url, '/').'/tenants/'.rawurlencode($tenant).'/tokens/write');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
		$headers=[
			'accept: application/json',
			'content-type: application/x-www-form-urlencoded',
		];
		if(self::api_auth_mode($tenant)==='bearer'){
			$headers[]='authorization: Bearer '.$api_token;
		}
		else
		{
			$headers[]='x-vestra-control-key: '.$api_token;
		}
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload, '', '&', PHP_QUERY_RFC3986));
		$result=curl_exec($curl);
		if($result===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed issuing Vestra write token: '.curl_error($curl), $S='fatal');
			curl_close($curl);
			return '';
		}
		curl_close($curl);
		$decoded=json_decode($result, true);
		if(!is_array($decoded)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra write-token response was invalid JSON.', $S='fatal');
			return '';
		}
		$token=(string)($decoded['data']['write_token']['token'] ?? $decoded['write_token']['token'] ?? $decoded['token'] ?? '');
		if($token===''){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra write-token issue failed: '.json_encode(['ok'=>$decoded['ok'] ?? null, 'code'=>$decoded['code'] ?? null, 'status'=>$decoded['status'] ?? null], JSON_UNESCAPED_SLASHES), $S='fatal');
			return '';
		}
		$expires_at=(int)($decoded['data']['write_token']['expires_at'] ?? $decoded['write_token']['expires_at'] ?? 0);
		$cache[$key]=['token'=>$token, 'expires_at'=>$expires_at];
		return $token;
	}

	/**
	 * Issues a Vestra Fabric access token through the public control API when
	 * that deployment returns concrete tokens instead of only grant contracts.
	 *
	 * @param string $tenant Tenant id.
	 * @param string $rate Fabric rate.
	 * @param int $blockid Object block id.
	 * @param bool $tenant_grant Whether the token should be reusable for tenant/rate.
	 * @param array<string,mixed> $context Additional access-token request facts.
	 * @return array<string,mixed>|false Token envelope, or false when the control surface only prepares grants.
	 */
	private static function issue_access_token(string $tenant, string $rate, int $blockid, bool $tenant_grant, array $context=[]): array|false {
		$api_url=isset($context['api_url']) && is_scalar($context['api_url']) ? trim((string)$context['api_url']) : self::api_url($tenant);
		$api_token=isset($context['api_token']) && is_scalar($context['api_token']) ? trim((string)$context['api_token']) : self::vestra_api_token($tenant);
		if($api_url==='' || $api_token===''){
			return false;
		}
		$ttl=max(1, min(3600, (int)($context['expires_in_secs'] ?? self::profile_config('token_ttl', $tenant, 300))));
		$payload=[
			'rate'=>$rate,
			'method'=>'GET',
			'blockid'=>$blockid,
			'tenant_grant'=>$tenant_grant,
			'expires_in_secs'=>$ttl,
			'grace_secs'=>max(0, (int)($context['grace_secs'] ?? self::profile_config('token_grace', $tenant, 60))),
		];
		if(isset($context['object_expires_at']) && is_numeric($context['object_expires_at'])){
			$payload['object_expires_at']=(int)$context['object_expires_at'];
			$payload['tenant_grant']=false;
		}
		$response=self::control_request('POST', '/tenants/'.rawurlencode($tenant).'/tokens/access', $payload, $tenant, '', 'form', [
			'api_url'=>$api_url,
			'api_token'=>$api_token,
			'api_auth_mode'=>$context['api_auth_mode'] ?? null,
		]);
		if(!is_array($response) || (($response['ok'] ?? false)===false)){
			return false;
		}
		$token=self::extract_access_token($response);
		if($token===''){
			return false;
		}
		$expires_at=self::extract_token_expiry($response);
		return [
			'token'=>$token,
			'tenant'=>$tenant,
			'rate'=>$rate,
			'expires_at'=>$expires_at,
			'permanent'=>false,
			'tenant_grant'=>$tenant_grant && !isset($payload['object_expires_at']),
			'object_expires_at'=>$payload['object_expires_at'] ?? null,
		];
	}

	/**
	 * Extracts an access token from public or private Vestra Control response shapes.
	 *
	 * @param array<string,mixed> $response Decoded Control response.
	 * @return string Token value or an empty string.
	 */
	private static function extract_access_token(array $response): string {
		$candidates=[
			$response['data']['access_token']['token'] ?? null,
			$response['data']['token']['token'] ?? null,
			$response['data']['grant']['token'] ?? null,
			$response['data']['token'] ?? null,
			$response['data']['access_token'] ?? null,
			$response['access_token']['token'] ?? null,
			$response['token']['token'] ?? null,
			$response['token'] ?? null,
			$response['access_token'] ?? null,
		];
		foreach($candidates as $candidate){
			if(is_scalar($candidate) && trim((string)$candidate)!==''){
				return trim((string)$candidate);
			}
		}
		return '';
	}

	/**
	 * Extracts the access token expiry from common response shapes.
	 *
	 * @param array<string,mixed> $response Decoded Control response.
	 * @return int|null Epoch expiry, or null when unknown.
	 */
	private static function extract_token_expiry(array $response): ?int {
		$candidates=[
			$response['data']['access_token']['expires_at'] ?? null,
			$response['data']['token']['expires_at'] ?? null,
			$response['data']['grant']['expires_at'] ?? null,
			$response['expires_at'] ?? null,
		];
		foreach($candidates as $candidate){
			if(is_numeric($candidate) && (int)$candidate>0){
				return (int)$candidate;
			}
			if(is_string($candidate) && trim($candidate)!==''){
				$epoch=strtotime($candidate);
				if($epoch!==false && $epoch>0){
					return $epoch;
				}
			}
		}
		return null;
	}

	/**
	 * Returns the Vestra node token used only for operator/signer routes.
	 *
	 * @return string Configured Vestra node token, or an empty string.
	 */
	private static function vestra_node_token(string $tenant=''): string {
		$token=trim((string)self::profile_config('node_token', $tenant, ''));
		if($token==='' && defined('\CFG') && is_array(\CFG)){
			$token=trim((string)(\CFG['vestra_node_token'] ?? ''));
		}
		if($token==='' && function_exists('\config')){
			$token=trim((string)\config('vestra_node_token'));
		}
		if($token===''){
			$token=trim((string)(getenv('VESTRA_NODE_TOKEN') ?: ''));
		}
		return $token;
	}

	/**
	 * Sends JSON to a Vestra API route.
	 *
	 * Writes use Vestra's scoped write token. The Dataphyre private key is never
	 * sent to Vestra object routes.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Vestra route beginning with `/`.
	 * @param array<string,mixed> $payload JSON request payload.
	 * @return array<string,mixed>|false Decoded Vestra response or false on failure.
	 */
	private static function vestra_request(string $method, string $path, array $payload=[], string $auth='write', string $tenant='', array $auth_context=[]): array|false {
		$base_url=isset($auth_context['base_url']) && is_scalar($auth_context['base_url']) ? trim((string)$auth_context['base_url']) : '';
		if($base_url===''){
			$base_url=self::base_url($tenant);
		}
		if($base_url==='' && defined('\CFG') && is_array(\CFG)){
			$base_url=trim((string)(\CFG['vestra_url'] ?? ''));
		}
		if($base_url==='' && function_exists('\config')){
			$base_url=trim((string)\config('vestra_url'));
		}
		if($base_url===''){
			$base_url=trim((string)(getenv('VESTRA_BASE_URL') ?: ''));
		}
		if($base_url===''){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra base_url is not configured.', $S='fatal');
			return false;
		}
		$method=strtoupper($method);
		$headers=['content-type: application/json'];
		if($auth==='node'){
			$node_token=isset($auth_context['node_token']) && is_scalar($auth_context['node_token']) ? trim((string)$auth_context['node_token']) : self::vestra_node_token($tenant);
			if($node_token===''){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra node_token is not configured for Vestra signer routes.', $S='fatal');
				return false;
			}
			$headers[]='x-vestra-node-token: '.$node_token;
		}
		else
		{
			if(!isset($auth_context['max_bytes'])){
				$payload_json=json_encode($payload, JSON_UNESCAPED_SLASHES);
				$auth_context['max_bytes']=is_string($payload_json) ? strlen($payload_json) : 1;
			}
			$write_token=self::vestra_write_token($tenant, $method, $path, $auth_context);
			if($write_token===''){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra write_token is not configured for Vestra writes.', $S='fatal');
				return false;
			}
			$headers[]='x-vestra-write-token: '.$write_token;
		}
		$curl=curl_init();
		self::configure_curl_tls($curl, $tenant);
		curl_setopt($curl, CURLOPT_URL, rtrim($base_url, '/').'/'.ltrim($path, '/'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		if($method!=='GET' && $method!=='HEAD'){
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
		}
		$result=curl_exec($curl);
		if($result===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed reaching Vestra server: '.curl_error($curl), $S='fatal');
			curl_close($curl);
			return false;
		}
		curl_close($curl);
		$decoded_result=json_decode($result, true);
		if(!is_array($decoded_result)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra server returned invalid JSON; response_bytes='.strlen((string)$result), $S='fatal');
			return false;
		}
		return $decoded_result;
	}

	/**
	 * Extracts the canonical object id from a Vestra reference or object response.
	 *
	 * @param mixed $reference Vestra reference or object response.
	 * @return int|false Object id, or false when none is present.
	 */
	private static function object_id_from_reference(mixed $reference): bool|int {
		if(!is_array($reference)){
			return false;
		}
		foreach(['reference', 'data', 'object', 'reservation', 'allocation'] as $nested_key){
			if(isset($reference[$nested_key]) && is_array($reference[$nested_key])){
				$nested=self::object_id_from_reference($reference[$nested_key]);
				if($nested!==false){
					return $nested;
				}
			}
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		$storage=is_array($metadata['storage'] ?? null) ? $metadata['storage'] : [];
		$object_id=$reference['object_id']
			?? $reference['objectId']
			?? $reference['id']
			?? $reference['blockid']
			?? $metadata['object_id']
			?? $metadata['block_id']
			?? $storage['object_id']
			?? $storage['block_id']
			?? null;
		if(!is_numeric($object_id)){
			return false;
		}
		return (int)$object_id;
	}

	private static function object_handle_from_reference(mixed $reference): string {
		if(!is_array($reference)){
			return '';
		}
		foreach(['reference', 'data', 'object', 'reservation', 'allocation'] as $nested_key){
			if(isset($reference[$nested_key]) && is_array($reference[$nested_key])){
				$nested=self::object_handle_from_reference($reference[$nested_key]);
				if($nested!==''){
					return $nested;
				}
			}
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		foreach(['object_handle', 'handle', 'file_handle', 'asset_handle'] as $key){
			$value=$reference[$key] ?? $metadata[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		$object=is_array($reference['object'] ?? null) ? $reference['object'] : [];
		foreach(['handle', 'object_handle'] as $key){
			$value=$object[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		return '';
	}

	/**
	 * Builds the persisted Vestra Fabric reference from a Vestra response.
	 *
	 * The reference intentionally preserves node-provided links and token material.
	 * A numeric object id alone is not enough to reconstruct delivery URLs because
	 * tenant, passkey, plan/rate, and signing context can be reference-specific.
	 *
	 * @param array<string,mixed> $response Vestra object response.
	 * @param string $hash Optional known SHA-256 hash.
	 * @return array<string,mixed>|false Vestra Fabric reference or false on malformed responses.
	 */
	private static function reference_from_response(array $response, string $hash=''): array|false {
		if(isset($response['ok']) && $response['ok']===false){
			return false;
		}
		if(isset($response['status']) && !in_array((string)$response['status'], ['success', 'available', 'accepted', 'ready', 'uploaded', 'reserved', 'reservation_ready_for_database_commit'], true)){
			return false;
		}
		$envelope_data=is_array($response['data'] ?? null) ? $response['data'] : [];
		if(is_array($response['reference'] ?? null)){
			$source=$response['reference'];
		}
		elseif(is_array($envelope_data['reference'] ?? null)){
			$source=$envelope_data['reference'];
		}
		elseif($envelope_data!==[] && (self::object_id_from_reference($envelope_data)!==false || self::object_handle_from_reference($envelope_data)!=='')){
			$source=$envelope_data;
		}
		else
		{
			$source=$response;
		}
		if(is_array($envelope_data['object'] ?? null)){
			$source=array_merge($source, $envelope_data['object']);
		}
		$object_id=self::object_id_from_reference($source);
		$object_handle=self::object_handle_from_reference($source);
		if($object_id===false && $object_handle===''){
			return false;
		}
		$metadata=is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
		if(isset($envelope_data['metadata']) && is_array($envelope_data['metadata'])){
			$metadata=array_merge($metadata, $envelope_data['metadata']);
		}
		$source_metadata=is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
		$storage=is_array($metadata['storage'] ?? null) ? $metadata['storage'] : [];
		$delivery=is_array($metadata['delivery'] ?? null) ? $metadata['delivery'] : [];
		$links=[];
		foreach([
			'object'=>'object_url',
			'asset'=>'asset_url',
			'public'=>'public_url',
			'delivery'=>'delivery_url',
			'tenant'=>'tenant_url',
			'persistent'=>'persistent_url',
			'permanent'=>'permanent_url',
			'signed'=>'signed_url',
			'canonical'=>'url',
			'href'=>'href',
		] as $name=>$key){
			$value=$source[$key] ?? $envelope_data[$key] ?? $response[$key] ?? $metadata[$key] ?? $delivery[$key] ?? $storage[$key] ?? null;
			if(is_string($value) && trim($value)!==''){
				$links[$name]=trim($value);
			}
		}
		foreach(['links', 'urls'] as $container_key){
			$container=$source[$container_key] ?? $envelope_data[$container_key] ?? $response[$container_key] ?? $metadata[$container_key] ?? $delivery[$container_key] ?? null;
			if(is_array($container)){
				foreach($container as $key=>$value){
					if(is_string($value) && trim($value)!==''){
						$links[(string)$key]=trim($value);
					}
				}
			}
		}
		$tokens=[];
		foreach(['passkey', 'token', 'persistent_token', 'delivery_token', 'totp', 'access_token', 'signature', 'sig'] as $key){
			$value=$source[$key] ?? $response[$key] ?? $metadata[$key] ?? $storage[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				$tokens[$key]=(string)$value;
			}
		}
		foreach(['tokens', 'query'] as $container_key){
			$container=$source[$container_key] ?? $response[$container_key] ?? $metadata[$container_key] ?? null;
			if(is_array($container)){
				foreach($container as $key=>$value){
					if(is_scalar($value) && trim((string)$value)!==''){
						$tokens[(string)$key]=(string)$value;
					}
				}
			}
		}
		$reference=[
			'driver'=>'vestra',
			'tenant'=>(string)($source['tenant'] ?? $response['tenant'] ?? $metadata['tenant'] ?? self::tenant()),
		];
		if($object_id!==false){
			$reference['object_id']=$object_id;
			$reference['fabric']=[
				'blockid'=>$object_id,
				'tenant_url_template'=>(string)($delivery['tenant_url_template'] ?? '/v/{tenant}/{rate}/{blockid}'),
				'rate_source'=>'tenant_context',
			];
		}
		if($object_handle!==''){
			$reference['object_handle']=$object_handle;
			$reference['handle']=$object_handle;
		}
		if($links!==[]){
			$reference['links']=$links;
		}
		if($tokens!==[]){
			$reference['tokens']=$tokens;
		}
		if($hash!=='' || isset($response['hash']) || isset($metadata['hash'])){
			$reference['hash']=(string)($response['hash'] ?? $metadata['hash'] ?? $hash);
		}
		foreach(['mime_type', 'content_type'] as $key){
			$value=$source[$key] ?? $response[$key] ?? $metadata[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				$reference['mime_type']=(string)$value;
				break;
			}
		}
		$filesize=$source['filesize'] ?? $response['filesize'] ?? $metadata['filesize'] ?? $source['size'] ?? $response['size'] ?? $metadata['size'] ?? null;
		if(is_numeric($filesize)){
			$reference['filesize']=(int)$filesize;
		}
		$template=$source['url_template'] ?? $response['url_template'] ?? $metadata['url_template'] ?? $delivery['tenant_url_template'] ?? null;
		if(is_string($template) && trim($template)!==''){
			$reference['url_template']=trim($template);
		}
		if($source_metadata!==[]){
			$reference['metadata']=$source_metadata;
		}
		elseif($metadata!==[]){
			$reference['metadata']=$metadata;
		}
		return $reference;
	}

	/**
	 * Builds a temporary local origin URL for a cached Vestra upload file.
	 *
	 * The URL is derived from the current server environment and points at the
	 * Dataphyre Vestra route that can expose the cached file to the remote Vestra API.
	 *
	 * @param string $fileid Cache filename generated for the upload.
	 * @return string Absolute local origin URL.
	 */
	private static function local_origin_url(string $fileid): string {
		$https=(string)($_SERVER['HTTPS'] ?? '');
		$scheme=($https!=='' && strtolower($https)!=='off') ? 'https' : 'http';
		$host=trim((string)($_SERVER['HTTP_HOST'] ?? ''));
		if($host===''){
			$host=trim((string)($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));
			$port=(int)($_SERVER['SERVER_PORT'] ?? 0);
			$default_port=($scheme==='https') ? 443 : 80;
			if($port>0 && $port!==$default_port){
				if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
					$host='['.$host.']';
				}
				$host.=':'.$port;
			}
		}
		return $scheme.'://'.$host.'/dataphyre/vestra/'.$fileid;
	}

	private static function safe_object_key(string $file, string $hash): string {
		$name=basename(str_replace('\\', '/', $file));
		$name=preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?: 'object';
		$name=trim($name, '.-');
		if($name===''){
			$name='object';
		}
		return 'dataphyre/'.date('Y/m').'/'.substr($hash, 0, 16).'-'.$name;
	}

	private static function file_content_type(string $file): string {
		$mime=function_exists('mime_content_type') ? (string)(mime_content_type($file) ?: '') : '';
		return $mime!=='' ? $mime : 'application/octet-stream';
	}

	private static function fabric_reserve_upload(string $file, string $hash, string $tenant, int $bytes, string $content_type): array|false {
		if($tenant===''){
			return false;
		}
		$object_key=self::safe_object_key($file, $hash);
		$idempotency_key='dataphyre_'.$tenant.'_'.substr(hash('sha256', implode('|', [$tenant, $object_key, (string)$bytes, $hash])), 0, 40);
		$rate=self::rate($tenant);
		$payload=[
			'object_key'=>$object_key,
			'name'=>$object_key,
			'content_type'=>$content_type,
			'max_bytes'=>$bytes,
			'bytes'=>$bytes,
			'rate'=>$rate,
			'method'=>'PUT',
			'checksum_sha256'=>$hash,
			'idempotency_key'=>$idempotency_key,
		];
		$response=self::control_request('POST', '/tenants/'.rawurlencode($tenant).'/objects/reserve', $payload, $tenant, $idempotency_key, 'form');
		if(!is_array($response) || (($response['ok'] ?? false)===false)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra Fabric reserve failed: '.json_encode([
				'code'=>is_array($response) ? ($response['code'] ?? null) : null,
				'message'=>is_array($response) ? ($response['message'] ?? null) : null,
			], JSON_UNESCAPED_SLASHES), $S='fatal');
			return false;
		}
		$data=is_array($response['data'] ?? null) ? $response['data'] : $response;
		$upload=self::fabric_upload_guidance($data);
		if($upload===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra reserve did not include usable upload guidance.', $S='fatal');
			return false;
		}
		$upload_response=self::stream_file_to_upload_guidance($file, $upload, $content_type, $bytes, $tenant);
		if($upload_response===false){
			return false;
		}
		$combined=$response;
		$combined['metadata']=array_merge(is_array($combined['metadata'] ?? null) ? $combined['metadata'] : [], [
			'upload_response'=>$upload_response,
			'content_type'=>$content_type,
			'filesize'=>$bytes,
			'hash'=>$hash,
		]);
		if(is_array($upload_response['decoded'] ?? null)){
			$combined['data']=array_merge(is_array($combined['data'] ?? null) ? $combined['data'] : [], $upload_response['decoded']);
		}
		$reference=self::reference_from_response($combined, $hash);
		if($reference!==false){
			$reference['filename']=basename($file);
			$reference['mime_type']=$content_type;
			$reference['filesize']=$bytes;
		}
		return $reference;
	}

	private static function fabric_upload_guidance(array $data): array|false {
		$containers=[];
		foreach(['upload', 'upload_guidance', 'direct_upload', 'request', 'put', 'post'] as $key){
			if(is_array($data[$key] ?? null)){
				$containers[]=['value'=>$data[$key], 'strict'=>false];
			}
		}
		$containers[]=['value'=>$data, 'strict'=>true];
		foreach($containers as $container){
			$value=is_array($container['value'] ?? null) ? $container['value'] : [];
			$strict=!empty($container['strict']);
			$url=self::first_scalar($value, $strict ? ['upload_url', 'put_url', 'post_url', 'upload_endpoint'] : ['url', 'upload_url', 'href', 'endpoint', 'put_url', 'post_url', 'location', 'upload_endpoint']);
			if($url===''){
				continue;
			}
			$method=strtoupper(self::first_scalar($value, ['method', 'http_method']));
			if($method===''){
				$method=isset($value['post_url']) ? 'POST' : 'PUT';
			}
			$headers=[];
			$header_source=is_array($value['headers'] ?? null) ? $value['headers'] : (is_array($value['request_headers'] ?? null) ? $value['request_headers'] : []);
			foreach($header_source as $key=>$value){
				if(is_int($key) && is_scalar($value)){
					$headers[]=trim((string)$value);
				}
				elseif(is_scalar($value)){
					$headers[]=trim((string)$key).': '.trim((string)$value);
				}
			}
			return [
				'url'=>$url,
				'method'=>in_array($method, ['PUT', 'POST', 'PATCH'], true) ? $method : 'PUT',
				'headers'=>array_values(array_filter($headers)),
			];
		}
		return false;
	}

	private static function first_scalar(array $array, array $keys): string {
		foreach($keys as $key){
			$value=$array[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		foreach($array as $value){
			if(is_array($value)){
				$nested=self::first_scalar($value, $keys);
				if($nested!==''){
					return $nested;
				}
			}
		}
		return '';
	}

	private static function stream_file_to_upload_guidance(string $file, array $upload, string $content_type, int $bytes, string $tenant=''): array|false {
		$url=trim((string)($upload['url'] ?? ''));
		if($url===''){
			return false;
		}
		if(str_starts_with($url, '/')){
			$base=rtrim((string)self::public_base_url($tenant!=='' ? $tenant : self::tenant()), '/');
			if($base===''){
				return false;
			}
			$url=$base.$url;
		}
		$handle=fopen($file, 'rb');
		if(!is_resource($handle)){
			return false;
		}
		$headers=is_array($upload['headers'] ?? null) ? $upload['headers'] : [];
		$has_content_type=false;
		foreach($headers as $header){
			if(is_string($header) && stripos($header, 'content-type:')===0){
				$has_content_type=true;
				break;
			}
		}
		if(!$has_content_type){
			$headers[]='content-type: '.$content_type;
		}
		$headers[]='content-length: '.$bytes;
		$curl=curl_init();
		self::configure_curl_tls($curl, $tenant);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, (string)($upload['method'] ?? 'PUT'));
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_UPLOAD, true);
		curl_setopt($curl, CURLOPT_INFILE, $handle);
		curl_setopt($curl, CURLOPT_INFILESIZE, $bytes);
		$result=curl_exec($curl);
		$status=(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		if($result===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra upload stream failed: '.curl_error($curl), $S='fatal');
			curl_close($curl);
			fclose($handle);
			return false;
		}
		curl_close($curl);
		fclose($handle);
		if($status<200 || $status>=300){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra upload stream failed with HTTP '.$status, $S='fatal');
			return false;
		}
		$decoded=json_decode((string)$result, true);
		return [
			'http_status'=>$status,
			'decoded'=>is_array($decoded) ? $decoded : [],
		];
	}

	/**
	 * Adds an asset extension to a URL path while preserving query and fragment.
	 *
	 * @param string $url Base URL from a Vestra reference.
	 * @param string $extension File extension without semantic validation.
	 * @return string URL with the extension applied.
	 */
	private static function url_with_extension(string $url, string $extension=''): string {
		$extension=ltrim(trim($extension), '.');
		if($extension===''){
			return $url;
		}
		$fragment='';
		if(str_contains($url, '#')){
			[$url, $fragment]=explode('#', $url, 2);
			$fragment='#'.$fragment;
		}
		$query='';
		if(str_contains($url, '?')){
			[$url, $query]=explode('?', $url, 2);
			$query='?'.$query;
		}
		if(preg_match('/\.'.preg_quote($extension, '/').'$/i', $url)===1){
			return $url.$query.$fragment;
		}
		return $url.'.'.$extension.$query.$fragment;
	}

	/**
	 * Normalizes a caller-provided Vestra Fabric reference.
	 *
	 * @param mixed $reference Vestra Fabric reference.
	 * @return array<string,mixed>|false Normalized reference.
	 */
	private static function normalize_reference(mixed $reference): array|false {
		if(!is_array($reference)){
			return false;
		}
		if(isset($reference['reference']) && is_array($reference['reference'])){
			$reference=$reference['reference'];
		}
		$object_id=self::object_id_from_reference($reference);
		if($object_id!==false){
			$reference['object_id']=$object_id;
		}
		else
		{
			$handle=self::object_handle_from_reference($reference);
			$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
			if($handle==='' && $links===[] && empty($reference['url']) && empty($reference['public_url']) && empty($reference['object_url'])){
				return false;
			}
			if($handle!==''){
				$reference['object_handle']=$handle;
				$reference['handle']=$handle;
			}
		}
		if(!isset($reference['driver'])){
			$reference['driver']='vestra';
		}
		if(!isset($reference['tenant']) || (string)$reference['tenant']===''){
			$tenant=self::tenant();
			if($tenant!==''){
				$reference['tenant']=$tenant;
			}
		}
		return $reference;
	}

	/**
	 * Resolves the current Vestra Fabric tenant context for a reference.
	 *
	 * Applications should use this dialback to tie delivery to current billing
	 * state. Persisted object references intentionally do not freeze the rate.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string,mixed> $parameters URL generation parameters.
	 * @return array<string,mixed>|false Tenant context, or false when tenant is unknown.
	 */
	private static function tenant_context(array $reference, array $parameters=[]): array|false {
		$context=[
			'tenant'=>(string)($parameters['tenant'] ?? $reference['tenant'] ?? self::tenant()),
		];
		$profile=self::tenant_profile($context['tenant']);
		$context['tenant']=(string)($profile['tenant'] ?? $parameters['tenant'] ?? $reference['tenant'] ?? $context['tenant']);
		$context['rate']=(string)($parameters['rate'] ?? $parameters['plan'] ?? $reference['rate'] ?? $profile['rate'] ?? self::rate($context['tenant']));
		$context['expires_in_secs']=(int)($parameters['expires_in_secs'] ?? $profile['token_ttl'] ?? self::config('token_ttl', 3600));
		$context['grace_secs']=(int)($parameters['grace_secs'] ?? $profile['token_grace'] ?? self::config('token_grace', 60));
		$context['tenant_grant']=(bool)($parameters['tenant_grant'] ?? $profile['use_tenant_grant'] ?? self::config('use_tenant_grant', false));
		foreach(['base_url', 'object_url', 'api_url', 'api_token', 'api_auth_mode', 'node_token', 'write_token', 'tenant_read_token', 'allow_unsigned'] as $key){
			if(isset($parameters[$key])){
				$context[$key]=$parameters[$key];
			}
			elseif(isset($profile[$key])){
				$context[$key]=$profile[$key];
			}
		}
		foreach(['object_expires_at', 'filename', 'token', 'passkey'] as $key){
			if(isset($parameters[$key])){
				$context[$key]=$parameters[$key];
			}
			elseif(isset($reference[$key])){
				$context[$key]=$reference[$key];
			}
		}
		if(null!==$dialback=core::dialback('CALL_VESTRA_RESOLVE_TENANT_CONTEXT', $reference, $parameters, $context)){
			if(is_array($dialback)){
				$context=array_merge($context, $dialback);
			}
			elseif(is_string($dialback) && trim($dialback)!==''){
				$context['rate']=trim($dialback);
			}
		}
		if(isset($context['object_expires_at'])){
			$context['tenant_grant']=false;
		}
		$context['tenant']=trim((string)($context['tenant'] ?? ''));
		$context['rate']=trim((string)($context['rate'] ?? ''));
		if($context['tenant']==='' || $context['rate']===''){
			return false;
		}
		return $context;
	}

	/**
	 * Issues or accepts a Vestra tenant token for the current tenant context.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string,mixed> $context Resolved tenant context.
	 * @return array<string,mixed>|false Token response or false when issuance fails.
	 */
	private static function tenant_token(array $reference, array $context): array|false {
		static $token_cache=[];
		if(isset($context['token']) && is_scalar($context['token']) && trim((string)$context['token'])!==''){
			return [
				'token'=>(string)$context['token'],
				'tenant'=>(string)$context['tenant'],
				'rate'=>(string)$context['rate'],
				'permanent'=>false,
				'tenant_grant'=>(bool)($context['tenant_grant'] ?? false),
			];
		}
		$tenant_grant=!empty($context['tenant_grant']) && !isset($context['object_expires_at']);
		$cache_key=implode('|', [
			(string)$context['tenant'],
			(string)$context['rate'],
			$tenant_grant ? 'grant' : 'object',
			$tenant_grant ? '*' : (string)$reference['object_id'],
			(string)($context['object_expires_at'] ?? ''),
		]);
		if(isset($token_cache[$cache_key]) && is_array($token_cache[$cache_key])){
			$cached=$token_cache[$cache_key];
			$expires_at=$cached['expires_at'] ?? null;
			if($expires_at===null || !is_numeric($expires_at) || (int)$expires_at>time()+30){
				return $cached;
			}
			unset($token_cache[$cache_key]);
		}
		if(null!==$dialback=core::dialback('CALL_VESTRA_ISSUE_TENANT_TOKEN', $reference, $context)){
			if(is_array($dialback) && isset($dialback['token'])){
				$token_cache[$cache_key]=$dialback;
				return $dialback;
			}
			if(is_string($dialback) && trim($dialback)!==''){
				$token_cache[$cache_key]=[
					'token'=>trim($dialback),
					'tenant'=>(string)$context['tenant'],
					'rate'=>(string)$context['rate'],
					'permanent'=>false,
					'tenant_grant'=>$tenant_grant,
				];
				return $token_cache[$cache_key];
			}
		}
		$issued=self::issue_access_token((string)$context['tenant'], (string)$context['rate'], (int)$reference['object_id'], $tenant_grant, $context);
		if(is_array($issued) && isset($issued['token']) && trim((string)$issued['token'])!==''){
			$token_cache[$cache_key]=$issued;
			return $issued;
		}
		$configured_token=isset($context['tenant_read_token']) && is_scalar($context['tenant_read_token'])
			? trim((string)$context['tenant_read_token'])
			: self::vestra_tenant_read_token((string)$context['tenant']);
		if($configured_token!==''){
			$token_cache[$cache_key]=[
				'token'=>$configured_token,
				'tenant'=>(string)$context['tenant'],
				'rate'=>(string)$context['rate'],
				'permanent'=>true,
				'tenant_grant'=>true,
			];
			return $token_cache[$cache_key];
		}
		$payload=[
			'tenant'=>(string)$context['tenant'],
			'rate'=>(string)$context['rate'],
			'blockid'=>(string)$reference['object_id'],
			'expires_in_secs'=>max(1, (int)($context['expires_in_secs'] ?? 3600)),
			'grace_secs'=>max(0, (int)($context['grace_secs'] ?? 60)),
		];
		if($tenant_grant){
			$payload['tenant_grant']=true;
		}
		if(isset($context['object_expires_at']) && is_numeric($context['object_expires_at'])){
			$payload['object_expires_at']=(int)$context['object_expires_at'];
		}
		$base_url=isset($context['base_url']) && is_scalar($context['base_url']) ? trim((string)$context['base_url']) : self::base_url((string)$context['tenant']);
		$node_token=isset($context['node_token']) && is_scalar($context['node_token']) ? trim((string)$context['node_token']) : self::vestra_node_token((string)$context['tenant']);
		if($base_url!=='' && $node_token!==''){
			$response=self::vestra_request('POST', '/tenant/token/issue', $payload, 'node', (string)$context['tenant'], [
				'base_url'=>$base_url,
				'node_token'=>$node_token,
			]);
			if(is_array($response) && isset($response['token'])){
				$token_cache[$cache_key]=$response;
				return $response;
			}
		}
		return false;
	}

	/**
	 * Encodes a Fabric path while preserving safe nested decorative filenames.
	 *
	 * @param string $path Relative path.
	 * @return string Encoded relative path.
	 */
	private static function encode_relative_path(string $path): string {
		$segments=[];
		foreach(explode('/', str_replace('\\', '/', trim($path, '/'))) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){
				continue;
			}
			$segments[]=rawurlencode($segment);
		}
		return $segments!==[] ? implode('/', $segments) : 'object';
	}

	/**
	 * Chooses the decorative filename used by path-token Fabric URLs.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string,mixed> $context Resolved tenant context.
	 * @param string $extension Optional extension.
	 * @return string Safe relative decorative filename.
	 */
	private static function decorative_filename(array $reference, array $context, string $extension=''): string {
		$filename=trim((string)($context['filename'] ?? $reference['filename'] ?? 'object-'.$reference['object_id']));
		if($filename==='' || str_starts_with($filename, '/') || str_contains($filename, "\0")){
			$filename='object-'.$reference['object_id'];
		}
		$extension=ltrim(trim($extension), '.');
		if($extension!=='' && !preg_match('/\.'.preg_quote($extension, '/').'$/i', $filename)){
			$filename.='.'.$extension;
		}
		return self::encode_relative_path($filename);
	}

	/**
	 * Builds path-scoped image transform directives for tokenized Fabric assets.
	 *
	 * Cloud and CDN caches can be configured to ignore query strings. Keeping
	 * image dimensions in the path prevents different variants from collapsing
	 * into the same edge object.
	 *
	 * @param array<string,mixed> $parameters URL generation parameters.
	 * @return array{prefix:string,consumed:array<string,bool>}
	 */
	private static function transform_path_directives(array $parameters): array {
		$directives=[];
		$consumed=[];
		$add_int=function(string $name, string $short) use (&$parameters, &$directives, &$consumed): void {
			$value=$parameters[$name] ?? $parameters[$short] ?? null;
			if(is_numeric($value) && (int)$value>0){
				$directives[]=$short.(int)$value;
				$consumed[$name]=true;
				$consumed[$short]=true;
			}
		};
		$add_int('width', 'w');
		$add_int('height', 'h');
		$mode=trim((string)($parameters['mode'] ?? ''));
		if($mode!=='' && preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $mode)){
			$directives[]='m'.$mode;
			$consumed['mode']=true;
		}
		$quality=$parameters['quality'] ?? $parameters['q'] ?? null;
		if(is_numeric($quality) && (int)$quality>0){
			$directives[]='q'.max(1, min(100, (int)$quality));
			$consumed['quality']=true;
			$consumed['q']=true;
		}
		$mime=trim((string)($parameters['mime'] ?? $parameters['mime_type'] ?? ''));
		if($mime!==''){
			$normalized=strtolower($mime);
			$normalized=str_replace('image/', '', $normalized);
			if($normalized==='jpg'){
				$normalized='jpeg';
			}
			if(in_array($normalized, ['jpeg', 'png', 'webp'], true)){
				$directives[]='f'.$normalized;
				$consumed['mime']=true;
				$consumed['mime_type']=true;
			}
		}
		return [
			'prefix'=>$directives!==[] ? '__tr/'.implode('/', $directives).'/' : '',
			'consumed'=>$consumed,
		];
	}

	/**
	 * Builds a current Vestra Fabric URL from a reference and tenant context.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param string $extension Optional decorative extension.
	 * @param array<string,mixed> $parameters URL generation parameters.
	 * @return string|false Current Fabric URL, or false when context/token issuance fails.
	 */
	private static function fabric_url(array $reference, string $extension='', array $parameters=[]): bool|string {
		if(!isset($reference['object_id']) || !is_numeric($reference['object_id'])){
			return false;
		}
		$context=self::tenant_context($reference, $parameters);
		if($context===false){
			return false;
		}
		$public_base=isset($context['object_url']) && is_scalar($context['object_url'])
			? rtrim((string)$context['object_url'], '/').'/'
			: self::public_base_url((string)$context['tenant']);
		if($public_base===''){
			return false;
		}
		$allow_unsigned=(bool)($parameters['allow_unsigned'] ?? $context['allow_unsigned'] ?? self::profile_config('allow_unsigned', (string)$context['tenant'], false));
		$token=self::tenant_token($reference, $context);
		if($token===false && !$allow_unsigned){
			return false;
		}
		$tenant=rawurlencode((string)$context['tenant']);
		$rate=rawurlencode((string)$context['rate']);
		$blockid=(string)$reference['object_id'];
		$base=rtrim($public_base, '/').'/v/'.$tenant.'/'.$rate.'/'.$blockid;
		$object_expires_at=$context['object_expires_at'] ?? ($token['object_expires_at'] ?? null);
		if(is_numeric($object_expires_at) && (int)$object_expires_at>0){
			$base.='/e/'.(int)$object_expires_at;
		}
		$transform_path=self::transform_path_directives($parameters);
		if(is_array($token) && isset($token['token']) && trim((string)$token['token'])!==''){
			$base.='/t/'.rawurlencode((string)$token['token']).'/'.$transform_path['prefix'].self::decorative_filename($reference, $context, $extension);
		}
		$query=[];
		$tokens=is_array($reference['tokens'] ?? null) ? $reference['tokens'] : [];
		$passkey=$context['passkey'] ?? $tokens['passkey'] ?? null;
		if(is_scalar($passkey) && trim((string)$passkey)!==''){
			$query['passkey']=(string)$passkey;
		}
		foreach($parameters as $key=>$value){
			if(in_array($key, ['tenant', 'rate', 'plan', 'base_url', 'object_url', 'api_url', 'api_token', 'api_auth_mode', 'node_token', 'write_token', 'tenant_read_token', 'allow_unsigned', 'expires_in_secs', 'grace_secs', 'tenant_grant', 'token', 'filename', 'passkey'], true)){
				continue;
			}
			if(isset($transform_path['consumed'][$key]) && is_array($token) && isset($token['token']) && trim((string)$token['token'])!==''){
				continue;
			}
			$query[$key]=$value;
		}
		return $query!==[] ? \dataphyre\core::url_updated_querystring($base, $query) : $base;
	}

	/**
	 * Builds a public URL from a Vestra Fabric reference.
	 *
	 * @param mixed $reference Vestra Fabric reference containing links or templates.
	 * @param array<string,mixed> $parameters Query parameters to add to the URL.
	 * @return string|false Public Vestra object URL, or false when context or signing fails.
	 */
	public static function object_url(mixed $reference, array $parameters=[]): bool|string {
		$reference=self::normalize_reference($reference);
		if($reference===false){
			return false;
		}
		$fabric_url=self::fabric_url($reference, '', $parameters);
		if(is_string($fabric_url)){
			return $fabric_url;
		}
		$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
		$url=(string)($links['object'] ?? $links['persistent'] ?? $links['permanent'] ?? $links['canonical'] ?? $links['public'] ?? $links['delivery'] ?? $links['tenant'] ?? $links['signed'] ?? $reference['object_url'] ?? $reference['persistent_url'] ?? $reference['url'] ?? '');
		if($url==='' && isset($reference['url_template']) && is_string($reference['url_template'])){
			$url=strtr($reference['url_template'], [
				'{object_id}'=>(string)$reference['object_id'],
				'{blockid}'=>(string)$reference['object_id'],
				'{tenant}'=>(string)($reference['tenant'] ?? ''),
				'{plan}'=>(string)($reference['plan'] ?? ''),
				'{rate}'=>(string)($reference['rate'] ?? ''),
			]);
		}
		if($url===''){
			return false;
		}
		$tokens=is_array($reference['tokens'] ?? null) ? $reference['tokens'] : [];
		if(isset($tokens['passkey']) && !isset($parameters['passkey'])){
			$parameters['passkey']=$tokens['passkey'];
		}
		if(!empty($parameters)){
			$url=\dataphyre\core::url_updated_querystring($url, $parameters);
		}
		return $url;
	}

	/**
	 * Builds a public URL for a Vestra Fabric reference and optional extension.
	 *
	 * @param mixed $reference Vestra Fabric reference containing links or templates.
	 * @param string $extension File extension to append before any passkey.
	 * @param array<string,mixed> $parameters Query parameters to add to the URL.
	 * @return string|false Public Vestra asset URL, or false when context or signing fails.
	 */
	public static function asset_url(mixed $reference, string $extension='', array $parameters=[]): bool|string {
		$reference=self::normalize_reference($reference);
		if($reference===false){
			return false;
		}
		$fabric_url=self::fabric_url($reference, $extension, $parameters);
		if(is_string($fabric_url)){
			return $fabric_url;
		}
		$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
		$asset_url='';
		if(isset($links['asset']) && is_scalar($links['asset'])){
			$asset_url=trim((string)$links['asset']);
		}
		if($asset_url==='' && isset($reference['asset_url']) && is_scalar($reference['asset_url'])){
			$asset_url=trim((string)$reference['asset_url']);
		}
		if($asset_url!==''){
			$url=$asset_url;
			$tokens=is_array($reference['tokens'] ?? null) ? $reference['tokens'] : [];
			if(isset($tokens['passkey']) && !isset($parameters['passkey'])){
				$parameters['passkey']=$tokens['passkey'];
			}
			$url=self::url_with_extension($url, $extension);
			return !empty($parameters) ? \dataphyre\core::url_updated_querystring($url, $parameters) : $url;
		}
		$url=self::object_url($reference, $parameters);
		return is_string($url) ? self::url_with_extension($url, $extension) : false;
	}

	/**
	 * Updates Vestra application reference count and purges remote objects that reach zero.
	 *
	 * @param mixed $reference Vestra Fabric reference.
	 * @param int $amount Signed amount to add to the current use count.
	 * @return bool|int New positive use count, 0 after successful purge, or false on failure.
	 */
	public static function update_use_count(mixed $reference, int $amount) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$object_id=self::object_id_from_reference($reference);
		if($object_id===false){
			return false;
		}
		if(false!==$object=sql_select(
			$S="use_count",
			$L="dataphyre.vestra_objects",
			$P="WHERE object_id=?",
			$V=array($object_id)
		)){
			$new_count=$object['use_count']+$amount;
			if($new_count>0){
				if(false!==sql_update(
					$L="dataphyre.vestra_objects",
					$F="use_count=?,updated_at=?",
					$P="WHERE object_id=?",
					$V=array($new_count, date('Y-m-d H:i:s'), $object_id)
				)){
					return $new_count;
				}
			}
			else
			{
				$tenant=is_array($reference) ? (string)($reference['tenant'] ?? '') : '';
				$decoded_result=self::vestra_request('DELETE', '/objects/'.$object_id, [], 'write', $tenant, [
					'max_bytes'=>1,
					'rate'=>self::rate($tenant),
				]);
				if(is_array($decoded_result) && ($decoded_result['status'] ?? '')==="success"){
					return 0;
				}
			}
		}
		return false;
	}

	/**
	 * Rewrites HTML resource references to Vestra URLs.
	 *
	 * Known changes are reused first. Unknown non-Vestra URLs are propagated to the
	 * Vestra, replaced in the HTML, and recorded in the `changes` map. The method can
	 * stop after `resource_limit` replacements to spread ingestion across passes.
	 *
	 * @param string $html HTML or CSS-containing markup to scan.
	 * @param ?int $resource_limit Maximum number of new replacements in this pass.
	 * @param array<string,array<string,mixed>> $known_changes Previously propagated URL-to-reference map.
	 * @return array{new_html:string,changes:array<string,array<string,mixed>>} Rewritten HTML and Vestra references.
	 */
	public static function ingest_resources(string $html, ?int $resource_limit=null, array $known_changes=[]): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$patterns=[
			'img'=>'/<img\s[^>]*?src=["\']([^"\']+)["\']/i',
			'video'=>'/<source\s[^>]*?src=["\']([^"\']+)["\']/i',
			'script'=>'/<script\s[^>]*?src=["\']([^"\']+)["\']/i',
			'style'=>'/<link\s[^>]*?href=["\']([^"\']+)["\'][^>]*?rel=["\']stylesheet["\']/i',
			'audio'=>'/<audio\s[^>]*?src=["\']([^"\']+)["\']/i',
			'iframe'=>'/<iframe\s[^>]*?src=["\']([^"\']+)["\']/i',
			'css_bg'=>'/url\((["\']?)([^"\')]+)\1\)/i',
			'favicon'=>'/<link\s[^>]*?href=["\']([^"\']+)["\'][^>]*?rel=["\'](icon|shortcut icon)["\']/i',
			'font'=>'/@font-face\s*{[^}]*?url\(["\']?([^)"\']+)\)?["\']?[^}]*?}/i',
			'source_in_picture'=>'/<source\s[^>]*?srcset=["\']([^"\']+)["\'][^>]*?>/i',
			'pdf_object'=>'/<object\s[^>]*?type=["\']application\/pdf["\'][^>]*?data=["\']([^"\']+)["\'][^>]*?>/i',
			'svg_img'=>'/<img\s[^>]*?src=["\']([^"\']+?\.svg)["\']/i',
			'pdf_link'=>'/<a\s[^>]*?href=["\']([^"\']+?\.pdf)["\']/i'
		];
		$changes=[];
		$replacements_count=0;
		$url_handler=function($matches)use(&$changes, &$replacements_count, $resource_limit, $known_changes){
			if($resource_limit!==null && $replacements_count>=$resource_limit){
				return $matches[0];
			}
			$url=$matches[1];
			if(isset($known_changes[$url])){
				$vestra_url=self::asset_url($known_changes[$url]);
				return is_string($vestra_url) ? str_replace($matches[1], $vestra_url, $matches[0]) : $matches[0];
			}
			$reference=self::propagate($url);
			if(is_array($reference)){
				$url_parts=explode('?', $url);
				$clean_url=$url_parts[0];
				$path_parts=pathinfo($clean_url);
				$extension=$path_parts['extension']??'';
				$vestra_url=self::asset_url($reference, $extension);
				if(is_string($vestra_url)){
					$changes[$url]=$reference;
					$replacements_count++;
					$result=str_replace($matches[1], $vestra_url, $matches[0]);
					return $result;
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Invalid Vestra Fabric reference', $S='fatal');
			}
			return $matches[0];
		};
		foreach($patterns as $tag=>$pattern){
			$html=preg_replace_callback($pattern, $url_handler, $html);
			if($resource_limit !== null && $replacements_count>=$resource_limit){
				break;
			}
		}
		return[
			'new_html'=>$html, 
			'changes'=>$changes
		];
	}

	/**
	 * Records application accounting for a Vestra object.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @return void
	 */
	private static function record_object(array $reference): void {
		if(!function_exists('sql_select') || !function_exists('sql_insert') || !function_exists('sql_update')){
			return;
		}
		$object_id=self::object_id_from_reference($reference);
		if($object_id===false){
			return;
		}
		$now=date('Y-m-d H:i:s');
		$reference_json=json_encode($reference, JSON_UNESCAPED_SLASHES);
		if(!is_string($reference_json)){
			return;
		}
		$fields=[
			'object_id'=>$object_id,
			'tenant'=>(string)($reference['tenant'] ?? ''),
			'hash'=>(string)($reference['hash'] ?? ''),
			'mime_type'=>(string)($reference['mime_type'] ?? ''),
			'filesize'=>(int)($reference['filesize'] ?? 0),
			'reference'=>$reference_json,
			'use_count'=>1,
			'created_at'=>$now,
			'updated_at'=>$now,
		];
		if(false!==sql_select($S='object_id', $L='dataphyre.vestra_objects', $P='WHERE object_id=?', $V=[$object_id])){
			sql_update($L='dataphyre.vestra_objects', $F='use_count=use_count+1,reference=?,updated_at=?', $P='WHERE object_id=?', $V=[$reference_json, $now, $object_id]);
			return;
		}
		sql_insert($L='dataphyre.vestra_objects', $F=$fields);
	}

	/**
	 * Pushes a local file or remote URL into Vestra-backed object storage.
	 *
	 * Local files are de-duplicated by SHA-256 when encryption is disabled, then
	 * streamed through the public Fabric reserve/upload flow when a Control API
	 * credential is configured. The legacy origin-fetch path remains as fallback.
	 * Encrypted local files are encrypted before staging and keep their original
	 * file metadata in the returned Vestra reference. Remote URLs are sent directly
	 * as origins.
	 *
	 * @param string $file Local filesystem path or remote URL.
	 * @param bool $encryption True when the Vestra should store the asset encrypted.
	 * @return array<string,mixed>|false Vestra Fabric reference, or false on failure.
	 */
	public static function propagate(string $file, bool $encryption=false) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_VESTRA_PROPAGATE",...func_get_args())) return $early_return;
		$fileid=uuid().'.'.pathinfo($file, PATHINFO_EXTENSION);
		$hash='';
		$encrypted_metadata=[];
		$upload_max_bytes=0;
		$streamed_reference=false;
		if(!filter_var($file, FILTER_VALIDATE_URL)){
			if(!file_exists($file)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='File does not exist', $S='fatal');
				return false;
			}
			$hash=hash_file('sha256', $file);
			if(!is_string($hash) || $hash===''){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed hashing file for Vestra propagation.', $S='fatal');
				return false;
			}
			if($encryption===false){
				if(false!==$row=sql_select(
					$S="object_id,reference", 
					$L="dataphyre.vestra_objects", 
					$P="WHERE hash=?", 
					$V=[$hash], 
					$F=false, 
					$C=false
				)){
					$stored_reference=$row['reference'] ?? null;
					if(is_string($stored_reference)){
						$decoded_reference=json_decode($stored_reference, true);
						$stored_reference=is_array($decoded_reference) ? $decoded_reference : null;
					}
					if(is_array($stored_reference)){
						self::update_use_count($stored_reference, 1);
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra: File hash was already known', $S='fatal');
						return $stored_reference;
					}
				}
			}
			$staged_file=ROOTPATH['common_dataphyre'].'cache/vestra/'.$fileid;
			if($encryption){
				$original_content=file_get_contents($file);
				if($original_content===false){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed reading file for encrypted propagation.', $S='fatal');
					return false;
				}
				$encrypted_content=core::encrypt_data($original_content, ['vestra', $hash]);
				if(!is_string($encrypted_content) || $encrypted_content===''){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed encrypting file for Vestra propagation.', $S='fatal');
					return false;
				}
				if(false===file_put_contents($staged_file, $encrypted_content)){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed writing encrypted file into cache.', $S='fatal');
					return false;
				}
				$original_mime=function_exists('mime_content_type') ? (string)(mime_content_type($file) ?: '') : '';
				$original_filesize=filesize($file);
				$encrypted_metadata=[
					'encrypted'=>true,
					'encryption'=>'dataphyre-core',
					'encryption_salt'=>['vestra', $hash],
					'original_hash'=>$hash,
					'original_mime_type'=>$original_mime,
					'original_filename'=>basename($file),
				];
				if(is_int($original_filesize)){
					$encrypted_metadata['original_filesize']=$original_filesize;
				}
				$staged_filesize=filesize($staged_file);
				$upload_max_bytes=is_int($staged_filesize) && $staged_filesize>0 ? $staged_filesize : strlen($encrypted_content);
			}
			elseif(!copy($file, $staged_file)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed copying file into cache.', $S='fatal');
				return false;
			}
			else
			{
				$staged_filesize=filesize($staged_file);
				$upload_max_bytes=is_int($staged_filesize) && $staged_filesize>0 ? $staged_filesize : self::default_write_max_bytes(self::tenant());
			}
			$tenant_for_upload=self::tenant();
			$content_type=self::file_content_type($staged_file);
			$streamed_reference=self::fabric_reserve_upload($staged_file, $hash, $tenant_for_upload, max(1, $upload_max_bytes), $content_type);
			$origin=self::local_origin_url($fileid);
		}
		else
		{
			$origin=$file;
			$upload_max_bytes=self::default_write_max_bytes(self::tenant());
		}
		$tenant=self::tenant();
		$rate=self::rate($tenant);
		if(is_array($streamed_reference)){
			$reference=$streamed_reference;
		}
		else
		{
			$decoded_result=self::vestra_request('POST', '/objects/fetch', ['origin'=>$origin, 'max_bytes'=>max(1, $upload_max_bytes)], 'write', $tenant, [
				'max_bytes'=>max(1, $upload_max_bytes),
				'rate'=>$rate,
				'write_token_path'=>'/objects/fetch',
			]);
			if(!is_array($decoded_result)){
				if(!filter_var($file, FILTER_VALIDATE_URL) && isset($staged_file) && is_string($staged_file) && file_exists($staged_file)){
					unlink($staged_file);
				}
				return false;
			}
			$reference=self::reference_from_response($decoded_result, $hash);
			if($reference===false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Vestra fetch returned a negative result: '.json_encode([
					'ok'=>$decoded_result['ok'] ?? null,
					'code'=>$decoded_result['code'] ?? null,
					'status'=>$decoded_result['status'] ?? null,
					'keys'=>array_slice(array_keys($decoded_result), 0, 8),
				], JSON_UNESCAPED_SLASHES), $S='fatal');
				if(!filter_var($file, FILTER_VALIDATE_URL) && isset($staged_file) && is_string($staged_file) && file_exists($staged_file)){
					unlink($staged_file);
				}
				return false;
			}
		}
		if($encrypted_metadata!==[]){
			$reference['metadata']=array_merge(is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [], $encrypted_metadata);
			$reference['encrypted']=true;
			if(!empty($encrypted_metadata['original_mime_type'])){
				$reference['mime_type']=(string)$encrypted_metadata['original_mime_type'];
			}
			if(isset($encrypted_metadata['original_filesize'])){
				$reference['filesize']=(int)$encrypted_metadata['original_filesize'];
			}
			$reference['hash']=$hash;
		}
		self::record_object($reference);
		if(!filter_var($file, FILTER_VALIDATE_URL)){
			if(!unlink($file)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting origin file.', $S='fatal');
			}
			if(!unlink(ROOTPATH['common_dataphyre'].'cache/vestra/'.$fileid)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting cached file.', $S='fatal');
			}
		}
		return $reference;
	}
	
}
