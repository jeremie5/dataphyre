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
 * Protects MVC routes that must be reached through signed URLs.
 *
 * The middleware verifies the request signature with an explicit constructor
 * secret, the current application configuration, or the DATAPHYRE_MVC_SIGNING_KEY
 * environment fallback before allowing route dispatch to continue.
 */
final class SignedUrlMiddleware {

	/**
	 * Stores an optional signing secret for this middleware instance.
	 *
	 * @param ?string $secret Secret used ahead of application config and environment fallback.
	 */
	public function __construct(private ?string $secret=null){}

	/**
	 * Validates the request signature before invoking the next middleware.
	 *
	 * Invalid or unverifiable signatures return a 403 HTML response instead of
	 * throwing, which keeps signed asset and action links inside normal response
	 * handling.
	 *
	 * @param Request $request Incoming request containing signature parameters.
	 * @param callable $next Downstream middleware/controller callback.
	 * @return mixed Downstream response for valid signatures, otherwise a 403 response.
	 */
	public function handle(Request $request, callable $next): mixed {
		$secret=$this->secret;
		if(!is_string($secret) || trim($secret)===''){
			$app=$request->attribute('app');
			if($app instanceof MvcApplication){
				$configured=$app->config('signed_url_secret');
				$secret=is_string($configured) ? $configured : null;
			}
		}
		if(!is_string($secret) || trim($secret)===''){
			$env=getenv('DATAPHYRE_MVC_SIGNING_KEY');
			$secret=is_string($env) ? $env : '';
		}
		if(!SignedUrl::valid($request, $secret)){
			return Response::html('Forbidden', 403);
		}
		return $next($request);
	}
}
