<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

require_once dirname(__DIR__, 3).'/http.php';

/**
 * Vestra object-storage facade with explicit configuration, transport, SQL,
 * token, and filesystem boundaries.
 */
class vestra {
	/** @var array<string,mixed> */
	private static array $runtime=[];
	/** @var array<string,array<string,mixed>> */
	private static array $accessTokenCache=[];
	/** @var array<string,array{token:string,expires_at:int}> */
	private static array $writeTokenCache=[];
	/** @var array<string,int> */
	private static array $accessTokenFailureCache=[];
	private static int $lastHttpStatus=0;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return [
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
			'tenant_read_token'=>'',
			'token_ttl'=>3600,
			'token_grace'=>60,
			'use_tenant_grant'=>true,
			'allow_unsigned'=>false,
			'delete_source_after_propagate'=>false,
			'tenants'=>[],
		];
	}

	public static function resetRuntime(array $runtime=[]): void {
		self::$runtime=$runtime;
		self::$accessTokenCache=[];
		self::$writeTokenCache=[];
		self::$accessTokenFailureCache=[];
		self::$lastHttpStatus=0;
	}

	public static function configureRuntime(array $runtime): void {
		self::$runtime=array_replace(self::$runtime, $runtime);
	}

	/** @return array<string,mixed> */
	public static function runtimeState(): array {
		return self::$runtime;
	}

	public static function configured(): bool {
		self::trace(__FUNCTION__);
		return self::baseUrl()!=='' || self::publicBaseUrl()!=='';
	}

	/** Builds a tokenized Fabric object URL or falls back to persisted links. */
	public static function object_url(mixed $reference, array $parameters=[]): bool|string {
		self::trace(__FUNCTION__, func_get_args());
		$reference=self::normalizeReference($reference);
		if($reference===false){
			return false;
		}
		$url=self::fabricUrl($reference, '', $parameters);
		if(is_string($url)){
			return $url;
		}
		$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
		$url=self::firstScalar([
			'object'=>$links['object'] ?? null,
			'persistent'=>$links['persistent'] ?? null,
			'permanent'=>$links['permanent'] ?? null,
			'canonical'=>$links['canonical'] ?? null,
			'public'=>$links['public'] ?? null,
			'delivery'=>$links['delivery'] ?? null,
			'tenant'=>$links['tenant'] ?? null,
			'signed'=>$links['signed'] ?? null,
			'object_url'=>$reference['object_url'] ?? null,
			'persistent_url'=>$reference['persistent_url'] ?? null,
			'url'=>$reference['url'] ?? null,
		], ['object','persistent','permanent','canonical','public','delivery','tenant','signed','object_url','persistent_url','url']);
		if($url==='' && is_string($reference['url_template'] ?? null)){
			$url=strtr($reference['url_template'], [
				'{object_id}'=>(string)($reference['object_id'] ?? ''),
				'{blockid}'=>(string)($reference['object_id'] ?? ''),
				'{tenant}'=>(string)($reference['tenant'] ?? ''),
				'{plan}'=>(string)($reference['plan'] ?? $reference['rate'] ?? ''),
				'{rate}'=>(string)($reference['rate'] ?? ''),
			]);
		}
		if($url===''){
			return false;
		}
		$parameters=self::withReferencePasskey($reference, $parameters);
		return $parameters===[] ? $url : self::updateQuery($url, $parameters);
	}

	/** Builds a tokenized Fabric asset URL with an optional decorative extension. */
	public static function asset_url(mixed $reference, string $extension='', array $parameters=[]): bool|string {
		self::trace(__FUNCTION__, func_get_args());
		$reference=self::normalizeReference($reference);
		if($reference===false){
			return false;
		}
		$url=self::fabricUrl($reference, $extension, $parameters);
		if(is_string($url)){
			return $url;
		}
		$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
		$asset=self::firstScalar([
			'asset'=>$links['asset'] ?? null,
			'asset_url'=>$reference['asset_url'] ?? null,
		], ['asset','asset_url']);
		if($asset!==''){
			$asset=self::urlWithExtension($asset, $extension);
			$parameters=self::withReferencePasskey($reference, $parameters);
			return $parameters===[] ? $asset : self::updateQuery($asset, $parameters);
		}
		$url=self::object_url($reference, $parameters);
		return is_string($url) ? self::urlWithExtension($url, $extension) : false;
	}

	/** Updates local usage accounting and purges the remote object at zero. */
	public static function update_use_count(mixed $reference, int $amount): bool|int {
		self::trace(__FUNCTION__, func_get_args());
		$objectId=self::objectId($reference);
		if($objectId===false){
			return false;
		}
		$selected=self::sql(
			'select',
			'use_count',
			'dataphyre.vestra_objects',
			'WHERE object_id=?',
			[$objectId],
			true,
			false,
		);
		$row=is_array($selected) && array_key_exists('use_count', $selected)
			? $selected
			: (is_array($selected) ? ($selected[0] ?? null) : $selected);
		if(!is_array($row) && !is_object($row)){
			return false;
		}
		$current=(int)(is_array($row) ? ($row['use_count'] ?? 0) : ($row->use_count ?? 0));
		$newCount=$current+$amount;
		if($newCount>0){
			$updated=self::sql('update', 'dataphyre.vestra_objects', 'use_count=?,updated_at=?', 'WHERE object_id=?', [
				$newCount, self::timestamp(), $objectId,
			]);
			return $updated===false ? false : $newCount;
		}
		$tenant=is_array($reference) ? (string)($reference['tenant'] ?? '') : '';
		$response=self::objectRequest('DELETE', '/objects/'.$objectId, [], $tenant, ['max_bytes'=>1]);
		if(!is_array($response) || !(($response['ok'] ?? false)===true || ($response['status'] ?? '')==='success')){
			return false;
		}
		self::sql('delete', 'dataphyre.vestra_objects', 'WHERE object_id=?', [$objectId], true);
		return 0;
	}

	/** Rewrites HTML/CSS resource URLs using known references or propagation. */
	public static function ingest_resources(string $html, ?int $resource_limit=null, array $known_changes=[]): array {
		self::trace(__FUNCTION__, func_get_args());
		$changes=[];
		$count=0;
		$pattern='~(?P<attr_prefix>\\b(?:src|href|data|poster)\\s*=\\s*["\\\'])(?P<attr>[^"\\\']+)(?P<attr_suffix>["\\\'])|(?P<css_prefix>url\\(\\s*["\\\']?)(?P<css>[^)"\\\']+)(?P<css_suffix>["\\\']?\\s*\\))~i';
		$propagate=self::$runtime['ingest_propagate'] ?? [self::class, 'propagate'];
		if(!is_callable($propagate)){
			throw new \LogicException('Vestra ingestion propagation boundary must be callable.');
		}
		$result=preg_replace_callback($pattern, static function(array $match) use (&$changes, &$count, $resource_limit, $known_changes, $propagate): string {
			if($resource_limit!==null && $count>=$resource_limit){
				return $match[0];
			}
			$url=(string)(($match['attr'] ?? '')!=='' ? $match['attr'] : ($match['css'] ?? ''));
			if($url==='' || preg_match('~^(?:data:|javascript:|#)~i', $url)===1){
				return $match[0];
			}
			$reference=$known_changes[$url] ?? $propagate($url, false);
			if(!is_array($reference)){
				self::log('Vestra ingestion could not propagate '.$url.'.');
				return $match[0];
			}
			$path=(string)(parse_url($url, PHP_URL_PATH) ?? '');
			$extension=(string)(pathinfo($path, PATHINFO_EXTENSION) ?: '');
			$vestraUrl=self::asset_url($reference, $extension);
			if(!is_string($vestraUrl)){
				return $match[0];
			}
			if(!isset($known_changes[$url])){
				$changes[$url]=$reference;
				$count++;
			}
			return str_replace($url, $vestraUrl, $match[0]);
		}, $html);
		return ['new_html'=>is_string($result) ? $result : $html, 'changes'=>$changes];
	}

	/** Propagates a local file or remote origin into Vestra object storage. */
	public static function propagate(string $file, bool $encryption=false): bool|array {
		self::trace(__FUNCTION__, func_get_args());
		$dialback=self::dialback('CALL_VESTRA_PROPAGATE', $file, $encryption);
		if($dialback!==null){
			return is_array($dialback) ? $dialback : false;
		}
		$file=trim($file);
		if($file===''){
			self::log('Vestra propagation requires a file path or URL.');
			return false;
		}
		$isRemote=filter_var($file, FILTER_VALIDATE_URL)!==false;
		$hash='';
		$stage='';
		$metadata=[];
		$bytes=self::defaultWriteMaxBytes('');
		$tenant=self::tenant();
		if(!$isRemote){
			if(self::fs('exists', $file)!==true || self::fs('readable', $file)!==true){
				self::log('Vestra propagation source does not exist or is unreadable.');
				return false;
			}
			$hash=(string)(self::fs('hash', $file) ?: '');
			if($hash===''){
				self::log('Vestra propagation could not hash the source file.');
				return false;
			}
			if(!$encryption){
				$known=self::sql('select', 'object_id,reference', 'dataphyre.vestra_objects', 'WHERE hash=?', [$hash], false, false);
				$knownReference=is_array($known) ? ($known['reference'] ?? null) : null;
				if(is_string($knownReference)){
					$decoded=json_decode($knownReference, true);
					$knownReference=is_array($decoded) ? $decoded : null;
				}
				if(is_array($knownReference)){
					self::update_use_count($knownReference, 1);
					return $knownReference;
				}
			}
			$cache=self::cacheDirectory();
			if($cache==='' || (self::fs('is_dir', $cache)!==true && self::fs('mkdir', $cache)!==true)){
				self::log('Vestra cache directory is unavailable.');
				return false;
			}
			$stage=$cache.self::uuid().self::sourceExtension($file);
			if($encryption){
				$content=self::fs('read', $file);
				$encrypt=self::$runtime['encrypt'] ?? (class_exists(core::class, false) ? [core::class, 'encrypt_data'] : null);
				if(!is_string($content) || !is_callable($encrypt)){
					self::log('Vestra encrypted propagation cannot read or encrypt the source.');
					return false;
				}
				$encrypted=$encrypt($content, ['vestra', $hash]);
				if(!is_string($encrypted) || $encrypted==='' || self::fs('write', $stage, $encrypted)===false){
					self::log('Vestra encrypted staging failed.');
					return false;
				}
				$metadata=[
					'encrypted'=>true,
					'encryption'=>'dataphyre-core',
					'encryption_salt'=>['vestra',$hash],
					'original_hash'=>$hash,
					'original_mime_type'=>self::fileContentType($file),
					'original_filename'=>basename($file),
					'original_filesize'=>(int)(self::fs('size', $file) ?: 0),
				];
			}else{
				if(self::fs('copy', $file, $stage)!==true){
					self::log('Vestra staging copy failed.');
					return false;
				}
			}
			$bytes=max(1, (int)(self::fs('size', $stage) ?: 0));
		}
		$reference=!$isRemote && $stage!==''
			? self::reserveAndUpload($stage, $hash, $tenant, $bytes, self::fileContentType($stage))
			: false;
		if(!is_array($reference)){
			$origin=$isRemote ? $file : self::localOriginUrl(basename($stage));
			$response=self::objectRequest('POST', '/objects/fetch', [
				'origin'=>$origin,
				'max_bytes'=>$bytes,
			], $tenant, ['max_bytes'=>$bytes,'write_token_path'=>'/objects/fetch']);
			$reference=is_array($response) ? self::referenceFromResponse($response, $hash) : false;
		}
		if(!is_array($reference)){
			self::cleanupStage($stage);
			self::log('Vestra propagation did not receive a usable object reference.');
			return false;
		}
		if($metadata!==[]){
			$reference['metadata']=array_merge(is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [], $metadata);
			$reference['encrypted']=true;
			$reference['hash']=$hash;
			$reference['mime_type']=$metadata['original_mime_type'];
			$reference['filesize']=$metadata['original_filesize'];
		}
		self::recordObject($reference);
		self::cleanupStage($stage);
		if(!$isRemote && self::config('delete_source_after_propagate', false)===true){
			self::fs('delete', $file);
		}
		return $reference;
	}

	public static function cacheDirectory(): string {
		$configured=trim((string)(self::$runtime['cache_directory'] ?? ''));
		if($configured!==''){
			return rtrim($configured, '/\\').DIRECTORY_SEPARATOR;
		}
		$roots=defined('ROOTPATH') && is_array(ROOTPATH) ? ROOTPATH : [];
		$root=trim((string)($roots['common_dataphyre'] ?? ''));
		return $root!=='' ? rtrim($root, '/\\').DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'vestra'.DIRECTORY_SEPARATOR : '';
	}

	/** @return array<string,mixed> */
	private static function configAll(): array {
		if(is_array(self::$runtime['config'] ?? null)){
			return array_replace(self::defaults(), self::$runtime['config']);
		}
		$config=defined('DP_VESTRA_CFG') ? constant('DP_VESTRA_CFG') : [];
		return is_array($config) ? array_replace(self::defaults(), $config) : self::defaults();
	}

	private static function config(string $key, mixed $default=null): mixed {
		$config=self::configAll();
		return $config[$key] ?? $default;
	}

	/** @return array<string,mixed> */
	private static function profile(string $tenant=''): array {
		$config=self::configAll();
		$tenants=is_array($config['tenants'] ?? null) ? $config['tenants'] : [];
		unset($config['tenants']);
		$key=trim($tenant);
		if($key===''){
			$key=trim((string)($config['default_tenant'] ?? $config['tenant'] ?? ''));
		}
		if($key!=='' && is_array($tenants[$key] ?? null)){
			$config=array_replace($config, $tenants[$key]);
			if(trim((string)($config['tenant'] ?? ''))===''){
				$config['tenant']=$key;
			}
		}
		return $config;
	}

	private static function legacy(array $keys, mixed $default=''): mixed {
		$legacy=self::$runtime['legacy_config'] ?? null;
		foreach($keys as $key){
			if(is_array($legacy) && array_key_exists($key, $legacy)){
				return $legacy[$key];
			}
			if(is_callable($legacy)){
				$value=$legacy($key);
				if($value!==null && $value!==''){
					return $value;
				}
			}
			if(defined('CFG') && is_array(CFG) && array_key_exists($key, CFG)){
				return CFG[$key];
			}
			if(function_exists('config')){
				$value=\config($key);
				if($value!==null && $value!==''){
					return $value;
				}
			}
		}
		return $default;
	}

	private static function env(array $names, string $default=''): string {
		$read=self::$runtime['env'] ?? 'getenv';
		if(!is_callable($read)){
			throw new \LogicException('Vestra environment boundary must be callable.');
		}
		foreach($names as $name){
			$value=$read($name);
			if(is_scalar($value) && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		return $default;
	}

	private static function baseUrl(string $tenant=''): string {
		$value=trim((string)(self::profile($tenant)['base_url'] ?? ''));
		$value=$value!=='' ? $value : trim((string)self::legacy(['vestra_url'], ''));
		$value=$value!=='' ? $value : self::env(['VESTRA_URL','VESTRA_BASE_URL']);
		return $value!=='' ? rtrim($value, '/').'/' : '';
	}

	private static function publicBaseUrl(string $tenant=''): string {
		$value=trim((string)(self::profile($tenant)['object_url'] ?? ''));
		$value=$value!=='' ? $value : trim((string)self::legacy(['vestra_object_url'], ''));
		$value=$value!=='' ? $value : self::env(['VESTRA_OBJECT_URL','VESTRA_PUBLIC_URL']);
		$value=$value!=='' ? $value : self::baseUrl($tenant);
		return $value!=='' ? rtrim($value, '/').'/' : '';
	}

	private static function tenant(): string {
		$config=self::configAll();
		$value=trim((string)($config['default_tenant'] ?? $config['tenant'] ?? ''));
		return $value!=='' ? $value : trim((string)self::legacy(['vestra_tenant'], ''));
	}

	private static function rate(string $tenant=''): string {
		$value=trim((string)(self::profile($tenant)['rate'] ?? ''));
		$value=$value!=='' ? $value : trim((string)self::legacy(['vestra_rate','vestra_plan'], ''));
		return $value!=='' ? $value : 's';
	}

	private static function apiUrl(string $tenant=''): string {
		$value=trim((string)(self::profile($tenant)['api_url'] ?? ''));
		$value=$value!=='' ? $value : trim((string)self::legacy(['vestra_api_url'], ''));
		$value=$value!=='' ? $value : self::env(['VESTRA_API_URL']);
		if($value===''){
			$base=self::baseUrl($tenant);
			$value=$base!=='' ? rtrim($base, '/').'/control/api' : '';
		}
		return $value!=='' ? rtrim($value, '/').'/' : '';
	}

	private static function setting(string $key, string $tenant='', string $default=''): string {
		$value=trim((string)(self::profile($tenant)[$key] ?? ''));
		$value=$value!=='' ? $value : trim((string)self::legacy(['vestra_'.$key], ''));
		$value=$value!=='' ? $value : self::env(['VESTRA_'.strtoupper($key)]);
		return $value!=='' ? $value : $default;
	}

	private static function authMode(string $tenant=''): string {
		$mode=strtolower(self::setting('api_auth_mode', $tenant, 'control_key'));
		return in_array($mode, ['bearer','session'], true) ? 'bearer' : 'control_key';
	}

	private static function defaultWriteMaxBytes(string $tenant): int {
		$value=(int)(self::profile($tenant)['default_write_max_bytes'] ?? 67108864);
		return max(1, $value);
	}

	/** @return array<string,mixed>|false */
	private static function controlRequest(string $method, string $path, array $payload, string $tenant='', string $encoding='json'): array|false {
		$apiUrl=self::apiUrl($tenant);
		$apiToken=self::setting('api_token', $tenant);
		if($apiUrl==='' || $apiToken===''){
			return false;
		}
		$headers=['Accept'=>'application/json'];
		$headers['Content-Type']=$encoding==='form' ? 'application/x-www-form-urlencoded' : 'application/json';
		$headers[self::authMode($tenant)==='bearer' ? 'Authorization' : 'X-Vestra-Control-Key']=self::authMode($tenant)==='bearer' ? 'Bearer '.$apiToken : $apiToken;
		$body=$encoding==='form'
			? http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
			: (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
		return self::sendJson([
			'purpose'=>'control',
			'url'=>rtrim($apiUrl, '/').'/'.ltrim($path, '/'),
			'method'=>strtoupper($method),
			'headers'=>$headers,
			'body'=>$body,
			'ca_bundle'=>self::caBundle($tenant),
		]);
	}

	/** @return array<string,mixed>|false */
	private static function objectRequest(string $method, string $path, array $payload, string $tenant='', array $context=[]): array|false {
		$base=trim((string)($context['base_url'] ?? self::baseUrl($tenant)));
		if($base===''){
			self::log('Vestra base URL is not configured.');
			return false;
		}
		$method=strtoupper($method);
		$headers=['Content-Type'=>'application/json'];
		if(($context['auth'] ?? 'write')==='node'){
			$token=trim((string)($context['node_token'] ?? self::setting('node_token', $tenant)));
			if($token===''){
				self::log('Vestra node token is not configured.');
				return false;
			}
			$headers['X-Vestra-Node-Token']=$token;
		}else{
			$context['max_bytes']??=max(1, strlen((string)json_encode($payload, JSON_UNESCAPED_SLASHES)));
			$token=self::writeToken($tenant, $method, $path, $context);
			if($token===''){
				self::log('Vestra write token is not configured.');
				return false;
			}
			$headers['X-Vestra-Write-Token']=$token;
		}
		return self::sendJson([
			'purpose'=>'object',
			'url'=>rtrim($base, '/').'/'.ltrim($path, '/'),
			'method'=>$method,
			'headers'=>$headers,
			'body'=>in_array($method, ['GET','HEAD'], true) ? '' : (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
			'ca_bundle'=>self::caBundle($tenant),
		]);
	}

	private static function caBundle(string $tenant): string {
		$path=self::setting('ca_bundle', $tenant);
		return $path!=='' && is_file($path) ? $path : '';
	}

	/** @return array<string,mixed>|false */
	private static function sendJson(array $request): array|false {
		$response=self::send($request);
		if($response===false){
			return false;
		}
		$decoded=is_array($response['json'] ?? null)
			? $response['json']
			: json_decode((string)($response['body'] ?? ''), true);
		if(!is_array($decoded)){
			self::log('Vestra returned an invalid JSON response.');
			return false;
		}
		return $decoded;
	}

	/** @return array<string,mixed>|false */
	private static function send(array $request): array|false {
		self::$lastHttpStatus=0;
		$http=self::$runtime['http'] ?? 'dataphyre_http_request';
		if(!is_callable($http)){
			throw new \LogicException('Vestra HTTP boundary must be callable.');
		}
		$response=$http($request);
		if(!is_array($response)){
			self::log('Vestra HTTP transport failed.');
			return false;
		}
		$status=(int)($response['status'] ?? 0);
		self::$lastHttpStatus=$status;
		if($status<200 || $status>=300){
			self::log('Vestra HTTP request failed with status '.$status.'.');
			return false;
		}
		return $response;
	}

	private static function writeToken(string $tenant, string $method, string $path, array $context): string {
		$configured=self::setting('write_token', $tenant);
		if($configured!==''){
			return $configured;
		}
		$scope=trim((string)($context['write_token_path'] ?? self::profile($tenant)['write_token_path'] ?? $path));
		$scope=$scope!=='' ? strtr($scope, ['{tenant}'=>$tenant,'{rate}'=>self::rate($tenant),'{plan}'=>self::rate($tenant),'{blockid}'=>'*']) : '/v/'.$tenant.'/'.self::rate($tenant).'/*';
		$maxBytes=max(1, (int)($context['max_bytes'] ?? self::defaultWriteMaxBytes($tenant)));
		$ttl=max(1, min(3600, (int)($context['expires_in_secs'] ?? self::profile($tenant)['write_token_ttl'] ?? 300)));
		$key=implode('|', [$tenant,$method,$scope,(string)$maxBytes,(string)$ttl]);
		$cached=self::$writeTokenCache[$key] ?? null;
		if(is_array($cached) && ($cached['expires_at']===0 || $cached['expires_at']>self::clock()+30)){
			return $cached['token'];
		}
		$response=self::controlRequest('POST', '/tenants/'.rawurlencode($tenant).'/tokens/write', [
			'rate'=>self::rate($tenant),
			'method'=>$method,
			'path'=>$scope,
			'max_bytes'=>$maxBytes,
			'expires_in_secs'=>$ttl,
		], $tenant, 'form');
		$token=is_array($response) ? self::firstScalar($response, ['token','write_token']) : '';
		if($token===''){
			return '';
		}
		$expires=(int)(is_array($response) ? self::firstScalar($response, ['expires_at']) : 0);
		self::$writeTokenCache[$key]=['token'=>$token,'expires_at'=>$expires];
		return $token;
	}

	/** @return array<string,mixed>|false */
	private static function tenantToken(array $reference, array $context): array|false {
		if(is_scalar($context['token'] ?? null) && trim((string)$context['token'])!==''){
			return ['token'=>(string)$context['token'],'tenant'=>$context['tenant'],'rate'=>$context['rate'],'tenant_grant'=>(bool)$context['tenant_grant']];
		}
		$grant=!empty($context['tenant_grant']) && !isset($context['object_expires_at']);
		$key=implode('|', [(string)$context['tenant'],(string)$context['rate'],$grant ? '*' : (string)$reference['object_id'],(string)($context['object_expires_at'] ?? '')]);
		$cached=self::$accessTokenCache[$key] ?? null;
		if(is_array($cached) && (!is_numeric($cached['expires_at'] ?? null) || (int)$cached['expires_at']>self::clock()+30)){
			return $cached;
		}
		$retry_after=(int)(self::$accessTokenFailureCache[$key] ?? 0);
		if($retry_after>self::clock()){
			return false;
		}
		unset(self::$accessTokenFailureCache[$key]);
		$dialback=self::dialback('CALL_VESTRA_ISSUE_TENANT_TOKEN', $reference, $context);
		if(is_string($dialback) && trim($dialback)!==''){
			$dialback=['token'=>trim($dialback),'tenant'=>$context['tenant'],'rate'=>$context['rate'],'tenant_grant'=>$grant];
		}
		if(is_array($dialback) && trim((string)($dialback['token'] ?? ''))!==''){
			self::$accessTokenCache[$key]=$dialback;
			return $dialback;
		}
		$issued=self::issueAccessToken($reference, $context, $grant);
		if(is_array($issued)){
			unset(self::$accessTokenFailureCache[$key]);
			self::$accessTokenCache[$key]=$issued;
			return $issued;
		}
		$configured=trim((string)($context['tenant_read_token'] ?? self::setting('tenant_read_token', (string)$context['tenant'])));
		if($configured!==''){
			$result=['token'=>$configured,'tenant'=>$context['tenant'],'rate'=>$context['rate'],'permanent'=>true,'tenant_grant'=>true];
			self::$accessTokenCache[$key]=$result;
			return $result;
		}
		$node=self::setting('node_token', (string)$context['tenant']);
		if($node!==''){
			$response=self::objectRequest('POST', '/tenant/token/issue', [
				'tenant'=>$context['tenant'],
				'rate'=>$context['rate'],
				'blockid'=>$reference['object_id'],
				'expires_in_secs'=>$context['expires_in_secs'],
				'grace_secs'=>$context['grace_secs'],
			], (string)$context['tenant'], ['auth'=>'node','node_token'=>$node]);
			if(is_array($response) && trim((string)($response['token'] ?? ''))!==''){
				unset(self::$accessTokenFailureCache[$key]);
				self::$accessTokenCache[$key]=$response;
				return $response;
			}
		}
		// A single asset helper may traverse several equivalent facade fallbacks.
		// Suppress duplicate control-plane storms briefly after all credential paths
		// fail; the next request can recover one second later.
		self::$accessTokenFailureCache[$key]=self::clock()+1;
		return false;
	}

	/** @return array<string,mixed>|false */
	private static function issueAccessToken(array $reference, array $context, bool $grant): array|false {
		if(self::setting('api_token', (string)$context['tenant'])===''){
			return false;
		}
		$payload=[
			'rate'=>$context['rate'],
			'method'=>'GET',
			'blockid'=>(int)$reference['object_id'],
			'tenant_grant'=>$grant,
			'expires_in_secs'=>max(1, (int)$context['expires_in_secs']),
			'grace_secs'=>max(0, (int)$context['grace_secs']),
		];
		if(is_numeric($context['object_expires_at'] ?? null)){
			$payload['object_expires_at']=(int)$context['object_expires_at'];
			$payload['tenant_grant']=false;
		}
		$response=false;
		for($attempt=0; $attempt<5; $attempt++){
			$response=self::controlRequest('POST', '/tenants/'.rawurlencode((string)$context['tenant']).'/tokens/access', $payload, (string)$context['tenant'], 'form');
			if($response!==false || !in_array(self::$lastHttpStatus, [0,429,502,503,504], true)){
				break;
			}
			if($attempt<4){
				self::pauseTransientControlRetry($attempt);
			}
		}
		if(!is_array($response) || (($response['ok'] ?? true)===false)){
			return false;
		}
		$token=self::firstScalar($response, ['token','access_token']);
		if($token===''){
			return false;
		}
		$expires=self::firstScalar($response, ['expires_at']);
		return [
			'token'=>$token,
			'tenant'=>$context['tenant'],
			'rate'=>$context['rate'],
			'expires_at'=>is_numeric($expires) ? (int)$expires : null,
			'permanent'=>false,
			'tenant_grant'=>$grant && !isset($payload['object_expires_at']),
			'object_expires_at'=>$payload['object_expires_at'] ?? null,
		];
	}

	private static function pauseTransientControlRetry(int $attempt): void {
		$microseconds=min(200000, 25000 * (2 ** max(0, $attempt)));
		$pause=self::$runtime['sleep'] ?? 'usleep';
		if(is_callable($pause)){
			$pause($microseconds);
		}
	}

	/** @return array<string,mixed>|false */
	private static function tenantContext(array $reference, array $parameters): array|false {
		$requested=(string)($parameters['tenant'] ?? $reference['tenant'] ?? self::tenant());
		$profile=self::profile($requested);
		$profileTenant=trim((string)($profile['tenant'] ?? ''));
		$profileRate=trim((string)($parameters['rate'] ?? $parameters['plan'] ?? $reference['rate'] ?? $profile['rate'] ?? ''));
		$context=[
			'tenant'=>$profileTenant!=='' ? $profileTenant : $requested,
			'rate'=>$profileRate!=='' ? $profileRate : self::rate($requested),
			'expires_in_secs'=>(int)($parameters['expires_in_secs'] ?? $profile['token_ttl'] ?? 3600),
			'grace_secs'=>(int)($parameters['grace_secs'] ?? $profile['token_grace'] ?? 60),
			'tenant_grant'=>(bool)($parameters['tenant_grant'] ?? $profile['use_tenant_grant'] ?? true),
		];
		foreach(['base_url','object_url','api_url','api_token','api_auth_mode','node_token','write_token','tenant_read_token','allow_unsigned','object_expires_at','filename','token','passkey'] as $key){
			if(array_key_exists($key, $parameters)){
				$context[$key]=$parameters[$key];
			}elseif(array_key_exists($key, $reference)){
				$context[$key]=$reference[$key];
			}elseif(array_key_exists($key, $profile)){
				$context[$key]=$profile[$key];
			}
		}
		$dialback=self::dialback('CALL_VESTRA_RESOLVE_TENANT_CONTEXT', $reference, $parameters, $context);
		if(is_array($dialback)){
			$context=array_replace($context, $dialback);
		}elseif(is_string($dialback) && trim($dialback)!==''){
			$context['rate']=trim($dialback);
		}
		if(isset($context['object_expires_at'])){
			$context['tenant_grant']=false;
		}
		$context['tenant']=trim((string)$context['tenant']);
		$context['rate']=trim((string)$context['rate']);
		return $context['tenant']!=='' && $context['rate']!=='' ? $context : false;
	}

	private static function fabricUrl(array $reference, string $extension, array $parameters): string|false {
		if(!isset($reference['object_id']) || !is_numeric($reference['object_id'])){
			return false;
		}
		$context=self::tenantContext($reference, $parameters);
		if($context===false){
			return false;
		}
		$base=trim((string)($context['object_url'] ?? ''));
		$base=$base!=='' ? $base : self::publicBaseUrl((string)$context['tenant']);
		if($base===''){
			return false;
		}
		$token=self::tenantToken($reference, $context);
		$allowUnsigned=(bool)($parameters['allow_unsigned'] ?? $context['allow_unsigned'] ?? false);
		if($token===false && !$allowUnsigned){
			return false;
		}
		$url=rtrim($base, '/').'/v/'.rawurlencode((string)$context['tenant']).'/'.rawurlencode((string)$context['rate']).'/'.(int)$reference['object_id'];
		$expires=$context['object_expires_at'] ?? (is_array($token) ? ($token['object_expires_at'] ?? null) : null);
		if(is_numeric($expires) && (int)$expires>0){
			$url.='/e/'.(int)$expires;
		}
		$transforms=self::transformDirectives($parameters);
		if(is_array($token) && trim((string)($token['token'] ?? ''))!==''){
			$url.='/t/'.rawurlencode((string)$token['token']).'/'.$transforms['prefix'].self::decorativeFilename($reference, $context, $extension);
		}
		$query=[];
		$tokens=is_array($reference['tokens'] ?? null) ? $reference['tokens'] : [];
		$passkey=$context['passkey'] ?? $tokens['passkey'] ?? null;
		if(is_scalar($passkey) && trim((string)$passkey)!==''){
			$query['passkey']=(string)$passkey;
		}
		$reserved=['tenant','rate','plan','base_url','object_url','api_url','api_token','api_auth_mode','node_token','write_token','tenant_read_token','allow_unsigned','expires_in_secs','grace_secs','tenant_grant','token','filename','passkey','object_expires_at'];
		foreach($parameters as $key=>$value){
			if(in_array($key, $reserved, true)){
				continue;
			}
			if(isset($transforms['consumed'][$key]) && is_array($token)){
				continue;
			}
			$query[$key]=$value;
		}
		return $query===[] ? $url : self::updateQuery($url, $query);
	}

	/** @return array{prefix:string,consumed:array<string,bool>} */
	private static function transformDirectives(array $parameters): array {
		$directives=[];
		$consumed=[];
		foreach(['width'=>'w','height'=>'h'] as $name=>$short){
			$value=$parameters[$name] ?? $parameters[$short] ?? null;
			if(is_numeric($value) && (int)$value>0){
				$directives[]=$short.(int)$value;
				$consumed[$name]=$consumed[$short]=true;
			}
		}
		$mode=trim((string)($parameters['mode'] ?? ''));
		if($mode!=='' && preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $mode)===1){
			$directives[]='m'.$mode;
			$consumed['mode']=true;
		}
		$quality=$parameters['quality'] ?? $parameters['q'] ?? null;
		if(is_numeric($quality) && (int)$quality>0){
			$directives[]='q'.max(1, min(100, (int)$quality));
			$consumed['quality']=$consumed['q']=true;
		}
		$mime=strtolower(trim((string)($parameters['mime'] ?? $parameters['mime_type'] ?? '')));
		$mime=str_replace('image/', '', $mime);
		$mime=$mime==='jpg' ? 'jpeg' : $mime;
		if(in_array($mime, ['jpeg','png','webp'], true)){
			$directives[]='f'.$mime;
			$consumed['mime']=$consumed['mime_type']=true;
		}
		return ['prefix'=>$directives===[] ? '' : '__tr/'.implode('/', $directives).'/', 'consumed'=>$consumed];
	}

	private static function decorativeFilename(array $reference, array $context, string $extension): string {
		$filename=trim((string)($context['filename'] ?? $reference['filename'] ?? 'object-'.$reference['object_id']));
		if($filename==='' || str_starts_with($filename, '/') || str_contains($filename, "\0")){
			$filename='object-'.$reference['object_id'];
		}
		$extension=ltrim(trim($extension), '.');
		if($extension!=='' && preg_match('/\\.'.preg_quote($extension, '/').'$/i', $filename)!==1){
			$filename.='.'.$extension;
		}
		$segments=[];
		foreach(explode('/', str_replace('\\', '/', trim($filename, '/'))) as $segment){
			if($segment!=='' && $segment!=='.' && $segment!=='..'){
				$segments[]=rawurlencode($segment);
			}
		}
		return $segments===[] ? 'object' : implode('/', $segments);
	}

	private static function normalizeReference(mixed $reference): array|false {
		if(!is_array($reference)){
			return false;
		}
		if(is_array($reference['reference'] ?? null)){
			$reference=$reference['reference'];
		}
		$id=self::objectId($reference);
		if($id!==false){
			$reference['object_id']=$id;
		}else{
			$handle=self::objectHandle($reference);
			$links=is_array($reference['links'] ?? null) ? $reference['links'] : [];
			if($handle==='' && $links===[] && self::firstScalar($reference, ['url','public_url','object_url'])===''){
				return false;
			}
			if($handle!==''){
				$reference['object_handle']=$reference['handle']=$handle;
			}
		}
		$reference['driver']??='vestra';
		if(trim((string)($reference['tenant'] ?? ''))===''){
			$tenant=self::tenant();
			if($tenant!==''){
				$reference['tenant']=$tenant;
			}
		}
		return $reference;
	}

	private static function objectId(mixed $reference): int|false {
		if(!is_array($reference)){
			return false;
		}
		foreach(['reference','data','object','reservation','allocation'] as $key){
			if(is_array($reference[$key] ?? null)){
				$id=self::objectId($reference[$key]);
				if($id!==false){
					return $id;
				}
			}
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		$storage=is_array($metadata['storage'] ?? null) ? $metadata['storage'] : [];
		$value=$reference['object_id'] ?? $reference['objectId'] ?? $reference['id'] ?? $reference['blockid'] ?? $metadata['object_id'] ?? $metadata['block_id'] ?? $storage['object_id'] ?? $storage['block_id'] ?? null;
		return is_numeric($value) ? (int)$value : false;
	}

	private static function objectHandle(mixed $reference): string {
		if(!is_array($reference)){
			return '';
		}
		foreach(['reference','data','object','reservation','allocation'] as $key){
			if(is_array($reference[$key] ?? null)){
				$handle=self::objectHandle($reference[$key]);
				if($handle!==''){
					return $handle;
				}
			}
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		return self::firstScalar(array_replace($metadata, $reference), ['object_handle','handle','file_handle','asset_handle']);
	}

	/** @return array<string,mixed>|false */
	private static function referenceFromResponse(array $response, string $hash=''): array|false {
		if(($response['ok'] ?? true)===false){
			return false;
		}
		$status=(string)($response['status'] ?? 'success');
		if(!in_array($status, ['success','available','accepted','ready','uploaded','reserved','reservation_ready_for_database_commit'], true)){
			return false;
		}
		$data=is_array($response['data'] ?? null) ? $response['data'] : [];
		$source=is_array($response['reference'] ?? null)
			? $response['reference']
			: (is_array($data['reference'] ?? null) ? $data['reference'] : (is_array($data['object'] ?? null) ? $data['object'] : ($data!==[] ? $data : $response)));
		$id=self::objectId($source);
		$handle=self::objectHandle($source);
		if($id===false && $handle===''){
			return false;
		}
		$metadata=is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
		$sourceMetadata=is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
		$links=[];
		foreach(['object_url'=>'object','asset_url'=>'asset','public_url'=>'public','delivery_url'=>'delivery','persistent_url'=>'persistent','permanent_url'=>'permanent','signed_url'=>'signed','url'=>'canonical','href'=>'href'] as $field=>$name){
			$value=$source[$field] ?? $data[$field] ?? $response[$field] ?? $metadata[$field] ?? null;
			if(is_string($value) && trim($value)!==''){
				$links[$name]=trim($value);
			}
		}
		foreach(['links','urls'] as $container){
			foreach([$source[$container] ?? null,$data[$container] ?? null,$response[$container] ?? null,$metadata[$container] ?? null] as $values){
				if(is_array($values)){
					foreach($values as $key=>$value){
						if(is_string($value) && trim($value)!==''){
							$links[(string)$key]=trim($value);
						}
					}
				}
			}
		}
		$tokens=[];
		foreach(['passkey','token','persistent_token','delivery_token','totp','access_token','signature','sig'] as $key){
			$value=$source[$key] ?? $response[$key] ?? $metadata[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				$tokens[$key]=(string)$value;
			}
		}
		$reference=['driver'=>'vestra','tenant'=>(string)($source['tenant'] ?? $response['tenant'] ?? $metadata['tenant'] ?? self::tenant())];
		if($id!==false){
			$reference['object_id']=$id;
			$reference['fabric']=['blockid'=>$id,'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}','rate_source'=>'tenant_context'];
		}
		if($handle!==''){
			$reference['object_handle']=$reference['handle']=$handle;
		}
		if($links!==[]){
			$reference['links']=$links;
		}
		if($tokens!==[]){
			$reference['tokens']=$tokens;
		}
		$reference['hash']=(string)($source['hash'] ?? $response['hash'] ?? $metadata['hash'] ?? $hash);
		$mime=self::firstScalar(array_replace($metadata, $response, $source), ['mime_type','content_type']);
		if($mime!==''){
			$reference['mime_type']=$mime;
		}
		$size=$source['filesize'] ?? $source['size'] ?? $response['filesize'] ?? $response['size'] ?? $metadata['filesize'] ?? $metadata['size'] ?? null;
		if(is_numeric($size)){
			$reference['filesize']=(int)$size;
		}
		$referenceMetadata=$sourceMetadata!==[] ? $sourceMetadata : $metadata;
		if($referenceMetadata!==[]){
			$reference['metadata']=$referenceMetadata;
		}
		return $reference;
	}

	/** @return array<string,mixed>|false */
	private static function reserveAndUpload(string $file, string $hash, string $tenant, int $bytes, string $contentType): array|false {
		if($tenant==='' || self::setting('api_token', $tenant)===''){
			return false;
		}
		$key=self::safeObjectKey($file, $hash);
		$response=self::controlRequest('POST', '/tenants/'.rawurlencode($tenant).'/objects/reserve', [
			'object_key'=>$key,
			'name'=>$key,
			'content_type'=>$contentType,
			'max_bytes'=>$bytes,
			'bytes'=>$bytes,
			'rate'=>self::rate($tenant),
			'method'=>'PUT',
			'checksum_sha256'=>$hash,
		], $tenant, 'form');
		if(!is_array($response) || ($response['ok'] ?? true)===false){
			return false;
		}
		$guidance=self::uploadGuidance(is_array($response['data'] ?? null) ? $response['data'] : $response);
		if($guidance===false){
			return false;
		}
		$url=(string)$guidance['url'];
		if(str_starts_with($url, '/')){
			$base=self::publicBaseUrl($tenant);
			if($base===''){
				return false;
			}
			$url=rtrim($base, '/').$url;
		}
		$headers=$guidance['headers'];
		$headers['Content-Type']??=$contentType;
		$headers['Content-Length']=(string)$bytes;
		$upload=self::send([
			'purpose'=>'upload',
			'url'=>$url,
			'method'=>$guidance['method'],
			'headers'=>$headers,
			'file'=>$file,
			'ca_bundle'=>self::caBundle($tenant),
		]);
		if($upload===false){
			return false;
		}
		if(is_array($upload['json'] ?? null)){
			$response['data']=array_replace(is_array($response['data'] ?? null) ? $response['data'] : [], $upload['json']);
		}
		$response['metadata']=array_replace(is_array($response['metadata'] ?? null) ? $response['metadata'] : [], [
			'content_type'=>$contentType,'filesize'=>$bytes,'hash'=>$hash,
		]);
		$reference=self::referenceFromResponse($response, $hash);
		if(is_array($reference)){
			$reference['filename']=basename($file);
			$reference['mime_type']=$contentType;
			$reference['filesize']=$bytes;
		}
		return $reference;
	}

	/** @return array{url:string,method:string,headers:array<string,string>}|false */
	private static function uploadGuidance(array $data): array|false {
		$containers=[];
		foreach(['upload','upload_guidance','direct_upload','request','put','post'] as $key){
			if(is_array($data[$key] ?? null)){
				$containers[]=$data[$key];
			}
		}
		$containers[]=$data;
		foreach($containers as $value){
			$url=self::firstScalar($value, ['url','upload_url','href','endpoint','put_url','post_url','location','upload_endpoint']);
			if($url===''){
				continue;
			}
			$method=strtoupper(self::firstScalar($value, ['method','http_method']));
			$method=in_array($method, ['PUT','POST','PATCH'], true) ? $method : (isset($value['post_url']) ? 'POST' : 'PUT');
			$headers=[];
			$source=is_array($value['headers'] ?? null) ? $value['headers'] : (is_array($value['request_headers'] ?? null) ? $value['request_headers'] : []);
			foreach($source as $name=>$header){
				if(is_scalar($header)){
					$headers[is_int($name) ? trim((string)$header) : trim((string)$name)]=is_int($name) ? '' : trim((string)$header);
				}
			}
			return ['url'=>$url,'method'=>$method,'headers'=>$headers];
		}
		return false;
	}

	private static function recordObject(array $reference): void {
		$id=self::objectId($reference);
		$encoded=json_encode($reference, JSON_UNESCAPED_SLASHES);
		if($id===false || !is_string($encoded) || !self::sqlAvailable('select') || !self::sqlAvailable('insert') || !self::sqlAvailable('update')){
			return;
		}
		$now=self::timestamp();
		if(self::sql('select', 'object_id', 'dataphyre.vestra_objects', 'WHERE object_id=?', [$id])!==false){
			self::sql('update', 'dataphyre.vestra_objects', 'use_count=use_count+1,reference=?,updated_at=?', 'WHERE object_id=?', [$encoded,$now,$id]);
			return;
		}
		self::sql('insert', 'dataphyre.vestra_objects', [
			'object_id'=>$id,
			'tenant'=>(string)($reference['tenant'] ?? ''),
			'hash'=>(string)($reference['hash'] ?? ''),
			'mime_type'=>(string)($reference['mime_type'] ?? ''),
			'filesize'=>(int)($reference['filesize'] ?? 0),
			'reference'=>$encoded,
			'use_count'=>1,
			'created_at'=>$now,
			'updated_at'=>$now,
		]);
	}

	private static function sql(string $operation, mixed ...$arguments): mixed {
		$callable=self::$runtime['sql_'.$operation] ?? 'sql_'.$operation;
		return is_callable($callable) ? $callable(...$arguments) : false;
	}

	private static function sqlAvailable(string $operation): bool {
		return is_callable(self::$runtime['sql_'.$operation] ?? 'sql_'.$operation);
	}

	private static function localOriginUrl(string $filename): string {
		$server=is_array(self::$runtime['server'] ?? null) ? self::$runtime['server'] : $_SERVER;
		$https=strtolower((string)($server['HTTPS'] ?? ''));
		$scheme=$https!=='' && $https!=='off' ? 'https' : 'http';
		$host=trim((string)($server['HTTP_HOST'] ?? ''));
		if($host===''){
			$host=trim((string)($server['SERVER_ADDR'] ?? '127.0.0.1'));
			$port=(int)($server['SERVER_PORT'] ?? 0);
			$default=$scheme==='https' ? 443 : 80;
			if($port>0 && $port!==$default){
				$host=(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$host.']' : $host).':'.$port;
			}
		}
		return $scheme.'://'.$host.'/dataphyre/vestra/'.rawurlencode($filename);
	}

	private static function safeObjectKey(string $file, string $hash): string {
		$name=preg_replace('/[^a-zA-Z0-9._-]+/', '-', basename(str_replace('\\', '/', $file))) ?: 'object';
		$name=trim($name, '.-') ?: 'object';
		return 'dataphyre/'.date('Y/m', self::clock()).'/'.substr($hash, 0, 16).'-'.$name;
	}

	private static function fileContentType(string $file): string {
		$mime=self::$runtime['mime'] ?? (function_exists('mime_content_type') ? 'mime_content_type' : null);
		$value=is_callable($mime) ? $mime($file) : false;
		return is_string($value) && $value!=='' ? $value : 'application/octet-stream';
	}

	private static function sourceExtension(string $file): string {
		$extension=preg_replace('/[^a-zA-Z0-9]+/', '', (string)pathinfo($file, PATHINFO_EXTENSION));
		return is_string($extension) && $extension!=='' ? '.'.strtolower($extension) : '';
	}

	private static function cleanupStage(string $stage): void {
		if($stage!=='' && self::fs('exists', $stage)===true){
			self::fs('delete', $stage);
		}
	}

	private static function fs(string $operation, mixed ...$arguments): mixed {
		$defaults=[
			'exists'=>static fn(string $path): bool=>is_file($path),
			'readable'=>static fn(string $path): bool=>is_readable($path),
			'hash'=>static fn(string $path): string|false=>hash_file('sha256', $path),
			'is_dir'=>static fn(string $path): bool=>is_dir($path),
			'mkdir'=>static fn(string $path): bool=>@mkdir($path, 0775, true),
			'read'=>static fn(string $path): string|false=>@file_get_contents($path),
			'write'=>static fn(string $path, string $contents): int|false=>@file_put_contents($path, $contents),
			'copy'=>static fn(string $source, string $destination): bool=>@copy($source, $destination),
			'size'=>static fn(string $path): int|false=>@filesize($path),
			'delete'=>static fn(string $path): bool=>@unlink($path),
		];
		$callback=self::$runtime['fs_'.$operation] ?? $defaults[$operation] ?? null;
		if(!is_callable($callback)){
			throw new \LogicException('Vestra filesystem '.$operation.' boundary must be callable.');
		}
		return $callback(...$arguments);
	}

	private static function withReferencePasskey(array $reference, array $parameters): array {
		$tokens=is_array($reference['tokens'] ?? null) ? $reference['tokens'] : [];
		if(!array_key_exists('passkey', $parameters) && is_scalar($tokens['passkey'] ?? null)){
			$parameters['passkey']=$tokens['passkey'];
		}
		return $parameters;
	}

	private static function urlWithExtension(string $url, string $extension): string {
		$extension=ltrim(trim($extension), '.');
		if($extension===''){
			return $url;
		}
		$parts=parse_url($url);
		if($parts===false){
			return $url;
		}
		$path=(string)($parts['path'] ?? '');
		if(preg_match('/\\.'.preg_quote($extension, '/').'$/i', $path)!==1){
			$path.='.'.$extension;
		}
		$base='';
		if(isset($parts['scheme'])){$base.=$parts['scheme'].'://';}
		if(isset($parts['user'])){$base.=$parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';}
		$base.=(string)($parts['host'] ?? '');
		if(isset($parts['port'])){$base.=':'.$parts['port'];}
		$base.=$path;
		if(isset($parts['query'])){$base.='?'.$parts['query'];}
		if(isset($parts['fragment'])){$base.='#'.$parts['fragment'];}
		return $base;
	}

	private static function updateQuery(string $url, array $parameters): string {
		$parts=parse_url($url);
		if($parts===false){
			return $url;
		}
		$query=[];
		parse_str((string)($parts['query'] ?? ''), $query);
		$query=array_replace($query, $parameters);
		$base=preg_replace('/[?#].*$/', '', $url) ?: $url;
		if(isset($parts['fragment'])){
			$base=preg_replace('/#.*$/', '', $base) ?: $base;
		}
		$encoded=http_build_query($query);
		return $base.($encoded!=='' ? '?'.$encoded : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
	}

	private static function firstScalar(array $array, array $keys): string {
		foreach($keys as $key){
			$value=$array[$key] ?? null;
			if(is_scalar($value) && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		foreach($array as $value){
			if(is_array($value)){
				$nested=self::firstScalar($value, $keys);
				if($nested!==''){
					return $nested;
				}
			}
		}
		return '';
	}

	private static function uuid(): string {
		$uuid=self::$runtime['uuid'] ?? (function_exists('uuid') ? 'uuid' : null);
		return is_callable($uuid) ? (string)$uuid() : bin2hex(random_bytes(16));
	}

	private static function timestamp(): string {
		return date('Y-m-d H:i:s', self::clock());
	}

	private static function clock(): int {
		$clock=self::$runtime['time'] ?? null;
		return is_callable($clock) ? (int)$clock() : (is_numeric($clock) ? (int)$clock : time());
	}

	private static function dialback(string $event, mixed ...$arguments): mixed {
		$dialback=self::$runtime['dialback'] ?? (class_exists(core::class, false) ? [core::class, 'dialback'] : null);
		return is_callable($dialback) ? $dialback($event, ...$arguments) : null;
	}

	private static function trace(string $function, array $arguments=[]): void {
		$trace=self::$runtime['trace'] ?? (function_exists('tracelog') ? 'tracelog' : null);
		if(is_callable($trace)){
			$trace(__FILE__, __LINE__, __CLASS__, $function, null, 'function_call', $arguments);
		}
	}

	private static function log(string $message): void {
		$log=self::$runtime['log'] ?? (function_exists('tracelog') ? static fn(string $message): mixed=>\tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $message, 'fatal') : null);
		if(is_callable($log)){
			$log($message);
		}
	}
}

/** Initializes Vestra configuration, schema registration, and cache ownership. */
function vestra_bootstrap(?bool $dispatch=null, array $runtime=[]): array {
	$dispatch ??=!defined('DATAPHYRE_VESTRA_NO_DISPATCH');
	if(!$dispatch){
		return ['initialized'=>false,'table_registered'=>false,'cache_ready'=>false];
	}
	if(!defined('DATAPHYRE_VESTRA_RUNTIME_MODULE_LOADED')){
		define('DATAPHYRE_VESTRA_RUNTIME_MODULE_LOADED', true);
	}
	$trace=$runtime['trace'] ?? (function_exists('tracelog') ? 'tracelog' : null);
	if(is_callable($trace)){
		$trace(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Module initialization');
	}
	$defineConfig=$runtime['define_config'] ?? (function_exists('dp_define_module_config') ? 'dp_define_module_config' : null);
	if(is_callable($defineConfig)){
		$defineConfig('vestra', 'DP_VESTRA_CFG', vestra::defaults());
	}
	$tableRegistered=false;
	$defineTable=$runtime['define_table'] ?? (function_exists('sql_define_table') ? 'sql_define_table' : null);
	if(is_callable($defineTable)){
		$defineTable('dataphyre.vestra_objects', __DIR__.'/vestra.tables.php', 'objects');
		$tableRegistered=true;
	}
	vestra::resetRuntime(is_array($runtime['vestra_runtime'] ?? null) ? $runtime['vestra_runtime'] : []);
	$cache=vestra::cacheDirectory();
	$mkdir=$runtime['mkdir'] ?? static fn(string $path): bool=>is_dir($path) || @mkdir($path, 0775, true);
	$writable=$runtime['is_writable'] ?? 'is_writable';
	if(!is_callable($mkdir) || !is_callable($writable)){
		throw new \LogicException('Vestra cache boundaries must be callable.');
	}
	$cacheReady=$cache!=='' && $mkdir($cache) && $writable($cache);
	if(!$cacheReady && is_callable($trace)){
		$trace(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'DataphyreVestra: Missing cache folder write permission.', 'fatal');
	}
	return ['initialized'=>true,'table_registered'=>$tableRegistered,'cache_ready'=>$cacheReady];
}

vestra_bootstrap();
