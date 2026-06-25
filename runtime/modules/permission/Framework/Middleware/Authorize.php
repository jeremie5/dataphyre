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
 * Enforces that the current request subject has every listed permission.
 *
 * This middleware is the all-permissions counterpart to AuthorizeAny and keeps
 * request handling framework-neutral by extracting the user from either an
 * object-style request or an array request.
 */
final class Authorize {

	/**
	 * Runs the permission check before invoking the next middleware callback.
	 *
	 * The original request is always included in authorization context so
	 * conditions and resolvers can inspect route, tenant, or transport details.
	 *
	 * @param mixed $request Request object or array carrying optional user data.
	 * @param callable $next Downstream middleware callback.
	 * @param ...string $permissions Permission names or aliases that must all pass.
	 * @return mixed response or handler value returned by the next callback after all permissions pass.
	 */
	public function handle(mixed $request, callable $next, string ...$permissions): mixed {
		Permission::ensure($permissions, self::user($request), ['request'=>$request]);
		return $next($request);
	}

	/**
	 * Resolves the user subject from supported request shapes.
	 *
	 * @param mixed $request Request object exposing user() or array containing user.
	 * @return mixed User subject passed to the Permission module, or null when absent.
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
