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
 * Requires the current MVC user to have at least one listed permission.
 *
 * The middleware delegates subject resolution and permission checks to the MVC
 * facade while preserving the request in the permission context for conditional
 * policies.
 */
final class PermissionAnyMiddleware {

	/**
	 * Passes the request when any configured permission is granted.
	 *
	 * An empty permission list is treated as denial to avoid accidentally opening
	 * a protected route through a misconfigured middleware declaration.
	 *
	 * @param Request $request HTTP request being dispatched.
	 * @param callable $next Downstream middleware/controller callback.
	 * @param ...string $permissions Permission names or aliases accepted by Mvc::canAny().
	 * @return mixed response-like value returned by the downstream callable when any permission is granted.
	 * @throws HttpException When no supplied permission is granted.
	 */
	public function handle(Request $request, callable $next, string ...$permissions): mixed {
		if($permissions!==[] && Mvc::canAny($permissions, null, ['request'=>$request])){
			return $next($request);
		}
		throw new HttpException(403, 'Permission denied.');
	}
}
