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

final class Authenticate {

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
