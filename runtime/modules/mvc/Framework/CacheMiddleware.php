<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * Adds HTTP cache metadata and conditional request handling to MVC responses.
 *
 * The middleware normalizes downstream output to a Response, applies Cache-Control,
 * optional ETag and Last-Modified validators, then lets the response evaluate
 * request headers such as If-None-Match and If-Modified-Since.
 */
final class CacheMiddleware {

	/**
	 * Applies cache headers to the downstream response.
	 *
	 * The visibility argument treats any value other than "private" as public.
	 * Invalid last-modified strings are ignored rather than failing route
	 * dispatch.
	 *
	 * @param Request $request HTTP request carrying conditional cache headers.
	 * @param callable $next Downstream middleware/controller callback.
	 * @param int|string $seconds Cache lifetime in seconds, clamped to zero or greater.
	 * @param string $visibility Cache visibility; "private" disables public caching.
	 * @param ?string $etag Optional ETag validator.
	 * @param int|string|null $lastModified Optional Unix timestamp or strtotime-compatible date.
	 * @return Response Response with cache validators and conditional headers applied.
	 */
	public function handle(
		Request $request,
		callable $next,
		int|string $seconds=60,
		string $visibility='public',
		?string $etag=null,
		int|string|null $lastModified=null
	): Response {
		$response=Response::normalize($next($request), 'html');
		$seconds=max(0, (int)$seconds);
		$response=$response->cacheFor($seconds, strtolower($visibility)!=='private');
		if(is_string($etag) && trim($etag)!==''){
			$response=$response->withEtag($etag);
		}
		if($lastModified!==null && $lastModified!==''){
			$timestamp=is_numeric($lastModified) ? (int)$lastModified : strtotime((string)$lastModified);
			if($timestamp!==false){
				$response=$response->withLastModified($timestamp);
			}
		}
		return $response->withConditionalHeaders($request);
	}
}
