<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3).'/http.php';
require_once __DIR__.'/vestra.cache.php';

/** Immutable cache-file delivery endpoint used by legacy Vestra origin URLs. */
final class dataphyre_vestra_cache_endpoint {
	private const MIME_TYPES=[
		'png'=>'image/png','jpeg'=>'image/jpeg','jpg'=>'image/jpeg','gif'=>'image/gif',
		'css'=>'text/css','html'=>'text/html; charset=UTF-8','htm'=>'text/html; charset=UTF-8',
		'js'=>'application/javascript','mjs'=>'application/javascript','json'=>'application/json',
		'ogg'=>'application/ogg','pdf'=>'application/pdf','zip'=>'application/zip',
		'mp4'=>'video/mp4','mp3'=>'audio/mpeg','wav'=>'audio/wav','svg'=>'image/svg+xml',
		'webp'=>'image/webp','txt'=>'text/plain; charset=UTF-8','woff'=>'font/woff','woff2'=>'font/woff2',
	];

	/** @param array<string,mixed> $runtime @return array{status:int,headers:array<string,string>,body:string} */
	public static function dispatch(array $runtime=[]): array {
		$bindings=is_array($runtime['bindings'] ?? null) ? $runtime['bindings'] : [];
		$requested=urldecode((string)($bindings['filename'] ?? $runtime['filename'] ?? ''));
		$filename=basename(str_replace(["\0",'\\'], ['', '/'], $requested));
		$cache=dataphyre_vestra_cache_directory::resolve($runtime);
		$path=$cache!=='' && $filename!=='' ? rtrim($cache, '/\\').DIRECTORY_SEPARATOR.$filename : '';
		$exists=$runtime['exists'] ?? static fn(string $path): bool=>is_file($path) && is_readable($path);
		$read=$runtime['read'] ?? static fn(string $path): string|false=>file_get_contents($path);
		$mtime=$runtime['mtime'] ?? static fn(string $path): int=>(int)(filemtime($path) ?: time());
		if(!is_callable($exists) || !is_callable($read) || !is_callable($mtime)){
			throw new LogicException('Vestra cache endpoint boundaries must be callable.');
		}
		if($path==='' || !$exists($path)){
			return ['status'=>404,'headers'=>['Cache-Control'=>'no-store'],'body'=>'Not found'];
		}
		$body=$read($path);
		if(!is_string($body)){
			return ['status'=>404,'headers'=>['Cache-Control'=>'no-store'],'body'=>'Not found'];
		}
		$modified=$mtime($path);
		$extension=strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
		$etag='"'.sha1($path.'|'.$modified.'|'.strlen($body)).'"';
		$headers=[
			'Content-Type'=>self::MIME_TYPES[$extension] ?? 'application/octet-stream',
			'Cache-Control'=>'public, max-age=31536000, immutable',
			'ETag'=>$etag,
			'Last-Modified'=>gmdate('D, d M Y H:i:s', $modified).' GMT',
			'X-Content-Type-Options'=>'nosniff',
		];
		if(in_array($extension, ['css','js','mjs','map','svg','json','txt'], true)){
			$headers['Vary']='Accept-Encoding';
		}
		$server=is_array($runtime['server'] ?? null) ? $runtime['server'] : [];
		$ifNoneMatch=trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
		$ifModifiedSince=strtotime((string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
		if(($ifNoneMatch!=='' && hash_equals($etag, $ifNoneMatch)) || ($ifNoneMatch==='' && $ifModifiedSince>0 && $ifModifiedSince>=$modified)){
			return ['status'=>304,'headers'=>$headers,'body'=>''];
		}
		$headers['Content-Length']=(string)strlen($body);
		return [
			'status'=>200,
			'headers'=>$headers,
			'body'=>strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'))==='HEAD' ? '' : $body,
		];
	}

	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): ?array {
		$dispatch ??=!defined('DATAPHYRE_VESTRA_LOADER_NO_DISPATCH');
		if(!$dispatch){
			return null;
		}
		if(!isset($runtime['bindings']) && class_exists('dataphyre\\routing', false)){
			$runtime['bindings']=\dataphyre\routing::$bindings;
		}
		$runtime['server']??=$_SERVER;
		$response=self::dispatch($runtime);
		$emit=$runtime['emit'] ?? 'dataphyre_emit_http_response';
		if(!is_callable($emit)){
			throw new LogicException('Vestra cache response emitter must be callable.');
		}
		$emit($response);
		return $response;
	}
}

dataphyre_vestra_cache_endpoint::bootstrap();
