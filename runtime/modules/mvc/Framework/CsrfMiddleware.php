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
 * Enforces CSRF token validation for state-changing MVC requests.
 *
 * Safe HTTP methods initialize a session token and continue. Mutating requests
 * must provide the token through the _token input field or X-CSRF-Token header.
 */
final class CsrfMiddleware {

	/**
	 * Validates the request CSRF token before continuing route dispatch.
	 *
	 * Token comparison uses hash_equals() against the session token to avoid
	 * timing leaks on attacker-controlled input.
	 *
	 * @param Request $request HTTP request being dispatched.
	 * @param callable $next Downstream middleware/controller callback.
	 * @return mixed Downstream response for valid or safe requests, otherwise a 419 response.
	 */
	public function handle(Request $request, callable $next): mixed {
		Session::start();
		if(in_array($request->effectiveMethod(), ['GET', 'HEAD', 'OPTIONS'], true)){
			Session::token();
			return $next($request);
		}
		$token=$request->input('_token', $request->header('X-CSRF-Token'));
		if(!is_string($token) || !hash_equals(Session::token(), $token)){
			return Response::html('CSRF token mismatch.', 419);
		}
		return $next($request);
	}
}
