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
 * Allows only unauthenticated visitors through an access middleware chain.
 *
 * Each configured guard is checked before the request reaches the next handler.
 * When no guard is supplied, the default guard is checked.
 */
final class Guest {

	/**
	 * Rejects authenticated users and forwards guests to the next handler.
	 *
	 * The middleware throws an AuthenticationException as soon as any selected
	 * guard reports an authenticated user.
	 *
	 * @param mixed $request Framework request object or payload.
	 * @param callable $next Next middleware/controller callable.
	 * @param string ...$guards Guard names to check; empty uses the default guard.
	 * @return mixed response-like value returned by the downstream callable for guest requests.
	 * @throws AuthenticationException When an authenticated user attempts a guest-only request.
	 */
	public function handle(mixed $request, callable $next, string ...$guards): mixed {
		$guards=$guards!==[] ? $guards : [null];
		foreach($guards as $guard){
			if(Auth::check($guard)===true){
				throw new AuthenticationException('This request is only available to guests.');
			}
		}
		return $next($request);
	}
}
