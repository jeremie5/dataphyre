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
 * Requires an authenticated MVC session before dispatching protected routes.
 *
 * The middleware delegates authentication state to the MVC facade so route
 * definitions can enforce the configured Access auth type without coupling
 * controllers to session or token internals.
 */
final class AccessMiddleware {

	/**
	 * Passes authenticated requests to the next middleware or raises 401.
	 *
	 * When an auth type is supplied it is passed through to Mvc::loggedIn(),
	 * allowing route groups to require a specific authentication channel.
	 *
	 * @param Request $request HTTP request being dispatched.
	 * @param callable $next Downstream middleware/controller callback.
	 * @param ?string $authType Optional configured auth type to validate.
	 * @return mixed response or controller value returned by the next callback after authentication passes.
	 * @throws HttpException When no valid session exists for the requested auth type.
	 */
	public function handle(Request $request, callable $next, ?string $authType=null): mixed {
		if(Mvc::loggedIn($authType)){
			return $next($request);
		}
		throw new HttpException(401, 'Unauthenticated.');
	}
}
