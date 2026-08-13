<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/assets_support.php';
require_once dirname(__DIR__, 3).'/http.php';

/** Resolves immutable embedded Tracelog assets into HTTP response envelopes. */
final class dataphyre_tracelog_asset_endpoint {
	/** @param array<string,mixed> $runtime @return array{status:int,headers:array<string,string>,body:string} */
	public static function dispatch(array $runtime=[]): array {
		$bindings=is_array($runtime['bindings'] ?? null) ? $runtime['bindings'] : [];
		$query=is_array($runtime['query'] ?? null) ? $runtime['query'] : [];
		$server=is_array($runtime['server'] ?? null) ? $runtime['server'] : [];
		$uri=(string)($server['REQUEST_URI'] ?? '');
		$asset=(string)($bindings['asset'] ?? $query['asset'] ?? basename((string)parse_url($uri, PHP_URL_PATH)));
		$content=dataphyre_tracelog_asset_content($asset);
		if(!is_array($content)){
			return [
				'status'=>404,
				'headers'=>['Content-Type'=>'text/plain; charset=utf-8','Cache-Control'=>'no-store'],
				'body'=>'Not found',
			];
		}
		$body=(string)($content['body'] ?? '');
		$contentType=(string)($content['content_type'] ?? 'application/octet-stream');
		$etag='"'.sha1($asset.'|'.$body).'"';
		$modifiedAt=(int)($runtime['modified_at'] ?? (filemtime(__FILE__) ?: time()));
		$lastModified=gmdate('D, d M Y H:i:s', $modifiedAt).' GMT';
		$ifNoneMatch=trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
		$ifModifiedSince=strtotime((string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
		$notModified=($ifNoneMatch!=='' && hash_equals($etag, $ifNoneMatch))
			|| ($ifNoneMatch==='' && $ifModifiedSince>0 && $ifModifiedSince>=$modifiedAt);
		$headers=[
			'Content-Type'=>$contentType,
			'Cache-Control'=>'public, max-age=31536000, immutable',
			'ETag'=>$etag,
			'Last-Modified'=>$lastModified,
			'Vary'=>'Accept-Encoding',
			'X-Content-Type-Options'=>'nosniff',
		];
		if($notModified){
			return ['status'=>304,'headers'=>$headers,'body'=>''];
		}
		$headers['Content-Length']=(string)strlen($body);
		$method=strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
		return ['status'=>200,'headers'=>$headers,'body'=>$method==='HEAD' ? '' : $body];
	}

	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): ?array {
		$dispatch ??=!defined('DATAPHYRE_TRACELOG_ASSET_NO_DISPATCH');
		if(!$dispatch){
			return null;
		}
		if(!isset($runtime['bindings']) && class_exists('dataphyre\\routing', false)){
			$runtime['bindings']=\dataphyre\routing::$bindings;
		}
		$runtime['query'] ??=$_GET;
		$runtime['server'] ??=$_SERVER;
		$response=self::dispatch($runtime);
		$emit=$runtime['emit'] ?? 'dataphyre_emit_http_response';
		if(!is_callable($emit)){
			throw new LogicException('Tracelog asset emitter must be callable.');
		}
		$emit($response);
		return $response;
	}
}

dataphyre_tracelog_asset_endpoint::bootstrap();
