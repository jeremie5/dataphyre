<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Middleware;

use Dataphyre\Access\Auth;
use Dataphyre\Access\Exceptions\AuthenticationException;

/**
 * Requires an authenticated Access guard before continuing a request.
 *
 * The middleware accepts one or more guard names and falls back to the default
 * guard when none are supplied. The first passing guard becomes the active guard
 * for downstream Access calls.
 */
final class Authenticate {

	/**
	 * Checks configured guards and invokes the next callback on success.
	 *
	 * Authentication failures throw a framework exception so adapters can convert
	 * the denial to redirects, JSON errors, or plain HTTP responses.
	 *
	 * @param mixed $request Request passed through to the downstream callback.
	 * @param callable $next Downstream middleware callback.
	 * @param ...string $guards Guard names to test, or default guard when empty.
	 * @return mixed response-like value returned by the downstream callable after a guard authenticates.
	 * @throws AuthenticationException When no guard is authenticated.
	 */
	public function handle(mixed $request, callable $next, string ...$guards): mixed {
		$guards=$guards!==[] ? $guards : [null];
		foreach($guards as $guard){
			if(Auth::check($guard)===true){
				if($guard!==null && $guard!==''){
					Auth::shouldUse($guard);
				}
				return $next($request);
			}
		}
		throw new AuthenticationException('Authentication is required for this request.');
	}
}
