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
 * Enforces Dataphyre MVC permission checks before controller dispatch.
 *
 * The middleware passes the current request into the MVC permission context and
 * requires at least one permission argument to be granted by Mvc::can().
 */
final class PermissionMiddleware {

	/**
	 * Forwards authorized requests or throws a 403 response exception.
	 *
	 *
	 * @return mixed Result returned by the next middleware/controller callable.
	 * @throws HttpException When no requested permission is granted.
	 */
	public function handle(Request $request, callable $next, string ...$permissions): mixed {
		if($permissions!==[] && Mvc::can($permissions, null, ['request'=>$request])){
			return $next($request);
		}
		throw new HttpException(403, 'Permission denied.');
	}
}
