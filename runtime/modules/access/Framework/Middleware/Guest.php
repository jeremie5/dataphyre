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

final class Guest {

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
