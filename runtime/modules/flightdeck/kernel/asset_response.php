<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_RESPONSE_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_ASSET_RESPONSE_LOADED',true);

/**
 * Builds and emits immutable Flightdeck asset responses.
 *
 * The responder centralizes asset-name resolution, cache validators, HEAD
 * semantics, missing responses, and header emission for both Flightdeck and
 * Debugbar asset entrypoints.
 */
final class dataphyre_flightdeck_asset_response {

	/**
	 * Resolves an asset name using route bindings, query input, then request URI.
	 *
	 * @param array<string,mixed> $route_bindings Router-captured route values.
	 * @param array<string,mixed> $query Request query values.
	 * @param array<string,mixed> $server Request server values.
	 */
	public static function request_asset(array $route_bindings=[], array $query=[], array $server=[]): string {
		return (string)($route_bindings['asset']
			?? $query['asset']
			?? basename((string)parse_url((string)($server['REQUEST_URI'] ?? ''),PHP_URL_PATH)));
	}

	/**
	 * Builds a deterministic immutable response for one embedded asset.
	 *
	 * @param ?array{content_type?:string,body?:string} $content Embedded asset payload.
	 * @param array<string,mixed> $server Request server values.
	 * @return array{status:int,headers:list<string>,remove_headers:list<string>,body:string,asset:string,etag:string,last_modified:string}
	 */
	public static function build(string $asset, ?array $content, string $source_file, array $server=[]): array {
		if(!is_array($content)){
			return self::missing();
		}
		$body=(string)($content['body'] ?? '');
		$content_type=(string)($content['content_type'] ?? 'application/octet-stream');
		$etag='"'.sha1($asset.'|'.$body).'"';
		$modified_at=@filemtime($source_file) ?: time();
		$last_modified=gmdate('D, d M Y H:i:s',$modified_at).' GMT';
		$headers=[
			'Content-Type: '.$content_type,
			'Cache-Control: public, max-age=31536000, immutable',
			'ETag: '.$etag,
			'Last-Modified: '.$last_modified,
			'Vary: Accept-Encoding',
			'X-Content-Type-Options: nosniff',
		];
		$if_none_match=trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
		$if_modified_since=strtotime((string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
		$mtime=strtotime($last_modified) ?: time();
		if(($if_none_match!=='' && $if_none_match===$etag)
			|| ($if_none_match==='' && $if_modified_since>0 && $if_modified_since>=$mtime)){
			return self::response(304,$headers,'',$asset,$etag,$last_modified,['Pragma','Expires']);
		}
		$headers[]='Content-Length: '.strlen($body);
		$method=strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
		return self::response(200,$headers,$method==='HEAD' ? '' : $body,$asset,$etag,$last_modified,['Pragma','Expires']);
	}

	/**
	 * Builds the shared fail-closed missing-asset response.
	 *
	 * @return array{status:int,headers:list<string>,remove_headers:list<string>,body:string,asset:string,etag:string,last_modified:string}
	 */
	public static function missing(): array {
		return self::response(404,[
			'Content-Type: text/plain; charset=utf-8',
			'Cache-Control: no-store',
		],'Not found','','','');
	}

	/**
	 * Emits a response built by this class.
	 *
	 * @param array{status?:int,headers?:array,remove_headers?:array,body?:string} $response Asset response payload.
	 */
	public static function emit(array $response): void {
		foreach($response['remove_headers'] ?? [] as $header){
			header_remove((string)$header);
		}
		http_response_code((int)($response['status'] ?? 200));
		foreach($response['headers'] ?? [] as $header){
			header((string)$header);
		}
		echo (string)($response['body'] ?? '');
	}

	/**
	 * Creates the stable response value shared by all response branches.
	 *
	 * @param list<string> $headers Response headers.
	 * @param list<string> $remove_headers Headers to remove before emission.
	 * @return array{status:int,headers:list<string>,remove_headers:list<string>,body:string,asset:string,etag:string,last_modified:string}
	 */
	private static function response(int $status, array $headers, string $body, string $asset, string $etag, string $last_modified, array $remove_headers=[]): array {
		return compact('status','headers','remove_headers','body','asset','etag','last_modified');
	}
}
