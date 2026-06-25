<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;

/**
 * Allows only unauthenticated MVC requests through the middleware chain.
 *
 * The optional auth type selects which MVC authentication context is checked.
 */
final class GuestMiddleware {

	/**
	 * Forwards guests and rejects already-authenticated users.
	 *
	 *
	 * @param Request $request Current HTTP request.
	 * @param callable $next Next middleware/controller callable.
	 * @param ?string $authType Optional MVC auth type or guard.
	 * @return mixed downstream response or controller value returned for unauthenticated requests.
	 * @throws HttpException When the selected auth context is already logged in.
	 */
	public function handle(Request $request, callable $next, ?string $authType=null): mixed {
		if(!Mvc::loggedIn($authType)){
			return $next($request);
		}
		throw new HttpException(403, 'Already authenticated.');
	}
}
