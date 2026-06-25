<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission\Middleware;

use Dataphyre\Permission\Permission;

/**
 * Middleware that authorizes a request when any requested permission is granted.
 *
 * The current user is resolved from request->user() or a request array's user
 * key, then passed to the Permission facade with the request in context.
 */
final class AuthorizeAny {

	/**
	 * Ensures at least one permission is granted before forwarding the request.
	 *
	 *
	 * @return mixed Result returned by the next middleware/controller callable.
	 * @throws \Dataphyre\Permission\Exceptions\AuthorizationException When none of the permissions is granted.
	 */
	public function handle(mixed $request, callable $next, string ...$permissions): mixed {
		Permission::ensureAny($permissions, self::user($request), ['request'=>$request]);
		return $next($request);
	}

	/**
	 * Extracts the current authorization subject from common request shapes.
	 *
	 * @param mixed $request Object or array request representation.
	 * @return mixed Request user, array user, or null when unavailable.
	 */
	private static function user(mixed $request): mixed {
		if(is_object($request) && method_exists($request, 'user')){
			return $request->user();
		}
		if(is_array($request) && array_key_exists('user', $request)){
			return $request['user'];
		}
		return null;
	}
}
