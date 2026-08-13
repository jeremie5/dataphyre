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
 * Validates, bounds, canonicalizes, and rebuilds standalone Panel requests.
 *
 * The guard treats the incoming Request as untrusted and never mutates it.
 * Route and tenant identity are removed from every caller-controlled channel;
 * the host later installs only values returned by trusted resolvers.
 */
final class PanelStandaloneHostRequestGuard {
	private const IDENTITY_KEYS=[
		'uri','resource','operation','record','record_key','action','relation',
		'panel_resource','panel_operation','panel_record','panel_action',
		'panel_relation','panel_segments','segments','path',
		'panel_surface','surface','panel_mount_prefix','mount_prefix',
		'panel_tenant','tenant','tenant_key',
	];
	private const TENANT_HEADERS=[
		'x_dataphyre_panel_tenant',
		'x_panel_tenant',
		'x_dataphyre_tenant',
		'x_tenant',
		'x_tenant_id',
	];

	/** @param array<string,int> $limits */
	public function __construct(
		private readonly string $prefix,
		private readonly array $limits
	){}

	/** @return array<string,int> */
	public static function defaultLimits(): array {
		return [
			'max_path_bytes'=>4096,
			'max_segments'=>32,
			'max_segment_bytes'=>255,
			'max_headers'=>100,
			'max_header_bytes'=>8192,
			'max_header_total_bytes'=>65536,
			'max_query_items'=>200,
			'max_query_depth'=>8,
			'max_query_bytes'=>32768,
			'max_body_items'=>1000,
			'max_body_depth'=>16,
			'max_body_bytes'=>1048576,
			'max_string_bytes'=>262144,
			'max_cookies'=>100,
			'max_cookie_depth'=>4,
			'max_cookie_bytes'=>16384,
			'max_files'=>20,
			'max_file_bytes'=>20971520,
			'max_file_total_bytes'=>52428800,
			'max_file_name_bytes'=>512,
			'max_content_length'=>68157440,
		];
	}

	/** @param array<string,mixed> $limits @return array<string,int> */
	public static function normalizeLimits(array $limits): array {
		$normalized=self::defaultLimits();
		foreach($limits as $name=>$value){
			if(!is_string($name) || !array_key_exists($name, $normalized) || !is_numeric($value)){
				continue;
			}
			$normalized[$name]=max(1, (int)$value);
		}
		return $normalized;
	}

	/** Normalizes a trusted mount prefix into an exact absolute-path boundary. */
	public static function normalizePrefix(string $prefix): string {
		$prefix=trim($prefix);
		if($prefix==='' || preg_match('/[\x00-\x1F\x7F?#]/', $prefix)===1 || str_contains($prefix, '\\')){
			throw new \InvalidArgumentException('Standalone Panel prefix must be a plain absolute path.');
		}
		$prefix='/'.trim($prefix, '/');
		if(strlen($prefix)>1024){
			throw new \InvalidArgumentException('Standalone Panel prefix is too long.');
		}
		if($prefix==='/'){
			return '/';
		}
		foreach(explode('/', trim($prefix, '/')) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){
				throw new \InvalidArgumentException('Standalone Panel prefix contains an invalid segment.');
			}
		}
		return rtrim($prefix, '/');
	}

	/** Reports exact-prefix membership without consuming or mutating a request. */
	public function matches(\Dataphyre\Http\Request|string $request): bool {
		$path=$request instanceof \Dataphyre\Http\Request ? $request->path() : (string)(parse_url($request, PHP_URL_PATH) ?? '');
		if($path===''){
			$path='/';
		}
		return $this->suffix($path)!==null;
	}

	/**
	 * @return array{
	 *   matched:bool,
	 *   request?:\Dataphyre\Http\Request,
	 *   route_kind?:string,
	 *   segments?:list<string>,
	 *   asset?:?string,
	 *   method?:string,
	 *   unsafe?:bool
	 * }
	 */
	public function inspect(\Dataphyre\Http\Request $request, string $surface): array {
		$path=$request->path();
		$suffix=$this->suffix($path);
		if($suffix===null){
			return ['matched'=>false];
		}
		$this->validateRequestShape($request, $path);
		$segments=$this->segments($suffix);
		$route=$this->route($segments);
		$method=strtoupper($request->effectiveMethod());
		if(!in_array($method, ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'], true)){
			throw new PanelStandaloneHostException('method_not_allowed', 405, 'The request method is not supported.', [
				'Allow'=>'GET, HEAD, POST, PUT, PATCH, DELETE',
			]);
		}
		$unsafe=!in_array($method, ['GET','HEAD'], true);
		$this->validateContentType($request, $unsafe);
		$query=$this->stripIdentity($request->query());
		$body=$this->stripIdentity($request->input());
		$headers=$this->stripTenantHeaders($request->headers());
		$server=$this->stripTenantServer($request->server());
		$attributes=$this->stripAttributes($request->attributes());
		$routeParameters=[
			'panel_surface'=>$surface,
			'panel_mount_prefix'=>$this->prefix,
			'panel_segments'=>$segments,
		];
		if($route['asset']!==null){
			$routeParameters['asset']=$route['asset'];
		}
		$rebuilt=\Dataphyre\Http\Request::create(
			$request->method(),
			$path,
			$query,
			$body,
			$request->cookie(),
			$server,
			$headers,
			$routeParameters,
			$attributes,
			$this->legacyFiles($request->files()),
		);
		return [
			'matched'=>true,
			'request'=>$rebuilt,
			'route_kind'=>$route['kind'],
			'segments'=>$segments,
			'asset'=>$route['asset'],
			'method'=>$method,
			'unsafe'=>$unsafe,
		];
	}

	private function suffix(string $path): ?string {
		if($this->prefix==='/'){
			return ltrim($path, '/');
		}
		if($path===$this->prefix){
			return '';
		}
		return str_starts_with($path, $this->prefix.'/') ? substr($path, strlen($this->prefix)+1) : null;
	}

	private function validateRequestShape(\Dataphyre\Http\Request $request, string $path): void {
		if(strlen($path)>$this->limits['max_path_bytes']){
			throw new PanelStandaloneHostException('path_too_large', 413, 'The request path is too large.');
		}
		if(preg_match('/[\x00-\x1F\x7F]/', $path)===1 || preg_match('//u', $path)!==1){
			throw new PanelStandaloneHostException('invalid_path', 400, 'The request path is malformed.');
		}
		$this->validateHeaders($request->headers());
		$this->validateContentLength($request);
		$this->validateArray('query', $request->query(), $this->limits['max_query_items'], $this->limits['max_query_depth'], $this->limits['max_query_bytes']);
		$this->validateArray('body', $request->input(), $this->limits['max_body_items'], $this->limits['max_body_depth'], $this->limits['max_body_bytes']);
		$this->validateArray('cookie', $request->cookie(), $this->limits['max_cookies'], $this->limits['max_cookie_depth'], $this->limits['max_cookie_bytes']);
		$this->validateFiles($request->files());
	}

	/** @param array<string,mixed> $headers */
	private function validateHeaders(array $headers): void {
		if(count($headers)>$this->limits['max_headers']){
			throw new PanelStandaloneHostException('headers_too_large', 413, 'The request contains too many headers.');
		}
		$total=0;
		foreach($headers as $name=>$value){
			if(!is_string($name)){
				throw new PanelStandaloneHostException('invalid_header', 400, 'The request contains an invalid header.');
			}
			$values=is_array($value) ? $value : [$value];
			foreach($values as $item){
				if(!is_scalar($item) && $item!==null){
					throw new PanelStandaloneHostException('invalid_header', 400, 'The request contains an invalid header.');
				}
				$item=(string)$item;
				$bytes=strlen($name)+strlen($item);
				if(strlen($item)>$this->limits['max_header_bytes'] || preg_match('/[\r\n\x00]/', $item)===1){
					throw new PanelStandaloneHostException('invalid_header', 400, 'The request contains an invalid header.');
				}
				$total+=$bytes;
			}
		}
		if($total>$this->limits['max_header_total_bytes']){
			throw new PanelStandaloneHostException('headers_too_large', 413, 'The request headers are too large.');
		}
		$encoding=strtolower(trim((string)($headers['content_encoding'] ?? '')));
		if($encoding!=='' && $encoding!=='identity'){
			throw new PanelStandaloneHostException('unsupported_content_encoding', 415, 'Compressed request bodies are not supported.');
		}
	}

	private function validateContentLength(\Dataphyre\Http\Request $request): void {
		$value=$request->header('Content-Length');
		if($value===null || $value===''){
			return;
		}
		if(!is_scalar($value) || preg_match('/^(0|[1-9][0-9]*)$/D', trim((string)$value))!==1){
			throw new PanelStandaloneHostException('invalid_content_length', 400, 'Content-Length must be a non-negative integer.');
		}
		if((int)$value>$this->limits['max_content_length']){
			throw new PanelStandaloneHostException('request_too_large', 413, 'The request body is too large.');
		}
	}

	/** @param array<mixed> $value */
	private function validateArray(string $name, array $value, int $maxItems, int $maxDepth, int $maxBytes): void {
		$items=0;
		$bytes=0;
		$walk=function(array $current, int $depth) use (&$walk,&$items,&$bytes,$name,$maxItems,$maxDepth,$maxBytes): void {
			if($depth>$maxDepth){
				throw new PanelStandaloneHostException($name.'_too_deep', 413, ucfirst($name).' data is nested too deeply.');
			}
			foreach($current as $key=>$item){
				$items++;
				$bytes+=strlen((string)$key);
				if($items>$maxItems || $bytes>$maxBytes){
					throw new PanelStandaloneHostException($name.'_too_large', 413, ucfirst($name).' data is too large.');
				}
				if(is_array($item)){
					$walk($item, $depth+1);
					continue;
				}
				if(!is_scalar($item) && $item!==null){
					throw new PanelStandaloneHostException('invalid_'.$name, 400, ucfirst($name).' data contains an unsupported value.');
				}
				$string=(string)$item;
				if(strlen($string)>$this->limits['max_string_bytes']){
					throw new PanelStandaloneHostException($name.'_value_too_large', 413, ucfirst($name).' data contains an oversized value.');
				}
				$bytes+=strlen($string);
				if($bytes>$maxBytes){
					throw new PanelStandaloneHostException($name.'_too_large', 413, ucfirst($name).' data is too large.');
				}
			}
		};
		$walk($value, 1);
	}

	/** @param array<string,mixed> $files */
	private function validateFiles(array $files): void {
		if(count($files)>$this->limits['max_files']){
			throw new PanelStandaloneHostException('too_many_files', 413, 'The request contains too many files.');
		}
		$total=0;
		foreach($files as $file){
			if(!$file instanceof \Dataphyre\Http\UploadedFile){
				throw new PanelStandaloneHostException('invalid_upload', 400, 'The request contains invalid upload metadata.');
			}
			$size=$file->size();
			if($size<0 || $size>$this->limits['max_file_bytes']){
				throw new PanelStandaloneHostException('upload_too_large', 413, 'An uploaded file is too large.');
			}
			if(strlen($file->clientOriginalName())>$this->limits['max_file_name_bytes'] || strlen($file->mimeType())>255){
				throw new PanelStandaloneHostException('invalid_upload', 400, 'The request contains invalid upload metadata.');
			}
			$total+=$size;
		}
		if($total>$this->limits['max_file_total_bytes']){
			throw new PanelStandaloneHostException('uploads_too_large', 413, 'The combined upload payload is too large.');
		}
	}

	private function validateContentType(\Dataphyre\Http\Request $request, bool $unsafe): void {
		if(!$unsafe){
			return;
		}
		$hasPayload=$request->input()!==[] || $request->files()!==[] || (int)$request->header('Content-Length', 0)>0;
		if(!$hasPayload){
			return;
		}
		$contentType=strtolower(trim((string)$request->header('Content-Type', '')));
		$contentType=trim(explode(';', $contentType, 2)[0]);
		$allowed=$contentType==='application/json'
			|| str_ends_with($contentType, '+json')
			|| $contentType==='application/x-www-form-urlencoded'
			|| $contentType==='multipart/form-data';
		if(!$allowed){
			throw new PanelStandaloneHostException('unsupported_content_type', 415, 'The request content type is not supported.');
		}
	}

	/** @return list<string> */
	private function segments(string $suffix): array {
		if($suffix===''){
			return [];
		}
		$raw=explode('/', $suffix);
		if(count($raw)>$this->limits['max_segments']){
			throw new PanelStandaloneHostException('too_many_path_segments', 413, 'The request path contains too many segments.');
		}
		$segments=[];
		foreach($raw as $segment){
			if($segment===''){
				throw new PanelStandaloneHostException('invalid_path', 400, 'The request path contains an empty segment.');
			}
			$segments[]=$this->decodeSegment($segment);
		}
		return $segments;
	}

	private function decodeSegment(string $segment): string {
		if(strlen($segment)>($this->limits['max_segment_bytes']*3) || preg_match('/%(?![0-9A-Fa-f]{2})/', $segment)===1){
			throw new PanelStandaloneHostException('invalid_path_encoding', 400, 'The request path encoding is malformed.');
		}
		$decoded=rawurldecode($segment);
		if(preg_match('/%[0-9A-Fa-f]{2}/', $decoded)===1){
			throw new PanelStandaloneHostException('unstable_path_encoding', 400, 'Nested path encoding is not accepted.');
		}
		if($decoded==='' || strlen($decoded)>$this->limits['max_segment_bytes'] || preg_match('//u', $decoded)!==1){
			throw new PanelStandaloneHostException('invalid_path_segment', 400, 'The request path contains an invalid segment.');
		}
		if($decoded==='.' || $decoded==='..' || str_contains($decoded, '/') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded)===1){
			throw new PanelStandaloneHostException('invalid_path_segment', 400, 'The request path contains an unsafe segment.');
		}
		return $decoded;
	}

	/** @param list<string> $segments @return array{kind:string,asset:?string} */
	private function route(array $segments): array {
		$first=strtolower((string)($segments[0] ?? ''));
		if($first==='assets'){
			if(count($segments)!==2 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $segments[1])!==1){
				throw new PanelStandaloneHostException('asset_not_found', 404, 'The requested Panel asset was not found.');
			}
			return ['kind'=>'asset','asset'=>$segments[1]];
		}
		if($first==='upload'){
			if(count($segments)!==1){
				throw new PanelStandaloneHostException('upload_not_found', 404, 'The requested Panel upload route was not found.');
			}
			return ['kind'=>'upload','asset'=>null];
		}
		return ['kind'=>'page','asset'=>null];
	}

	/** @param array<string|int,mixed> $input @return array<string|int,mixed> */
	private function stripIdentity(array $input): array {
		$tenantParameter=class_exists(PanelConfig::class) ? PanelConfig::tenantParameter() : 'tenant';
		foreach([...self::IDENTITY_KEYS, $tenantParameter] as $key){
			unset($input[$key]);
		}
		return PanelRouteParser::withoutIdentityQuery($input);
	}

	/** @param array<string,mixed> $headers @return array<string,mixed> */
	private function stripTenantHeaders(array $headers): array {
		foreach(self::TENANT_HEADERS as $header){
			unset($headers[$header]);
		}
		return $headers;
	}

	/** @param array<string,mixed> $server @return array<string,mixed> */
	private function stripTenantServer(array $server): array {
		foreach(array_keys($server) as $key){
			$normalized=strtolower((string)$key);
			if(str_contains($normalized, 'tenant') && (str_starts_with($normalized, 'http_') || str_starts_with($normalized, 'redirect_http_'))){
				unset($server[$key]);
			}
		}
		return $server;
	}

	/** @param array<string,mixed> $attributes @return array<string,mixed> */
	private function stripAttributes(array $attributes): array {
		foreach(array_keys($attributes) as $key){
			if(in_array(strtolower((string)$key), ['user','auth_user','tenant','tenant_key','panel_tenant'], true) || str_starts_with((string)$key, '__panel_standalone_')){
				unset($attributes[$key]);
			}
		}
		return $attributes;
	}

	/** @param array<string,mixed> $files @return array<string,array<string,mixed>> */
	private function legacyFiles(array $files): array {
		$legacy=[];
		foreach($files as $key=>$file){
			if(!$file instanceof \Dataphyre\Http\UploadedFile){
				continue;
			}
			$legacy[(string)$key]=[
				'name'=>$file->clientOriginalName(),
				'type'=>$file->mimeType(),
				'tmp_name'=>$file->path(),
				'error'=>$file->error(),
				'size'=>$file->size(),
			];
		}
		return $legacy;
	}
}
