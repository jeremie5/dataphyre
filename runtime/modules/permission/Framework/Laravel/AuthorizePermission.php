<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission\Laravel;

use Dataphyre\Permission\Permission;

/**
 * Adapts Dataphyre permission checks to the Laravel middleware pipeline.
 *
 * The middleware resolves the current request user and route when those Laravel
 * methods exist, passes them into the Permission module as authorization
 * context, and only advances the request when every supplied permission is
 * granted.
 */
final class AuthorizePermission {

	/**
	 * Enforces all requested permissions before invoking the next middleware.
	 *
	 * Laravel installations receive the normal abort(403) response when the
	 * helper is available. Non-Laravel consumers receive the original
	 * AuthorizationException so tests and alternate hosts can handle denial
	 * explicitly.
	 *
	 * @param mixed $request Request-like object that may expose user() and route().
	 * @param \Closure $next Next middleware callback.
	 * @param ...string $permissions Permission names or aliases that must all resolve truthy.
	 * @return mixed laravel response or downstream value returned after all permissions pass.
	 * @throws \Dataphyre\Permission\Exceptions\AuthorizationException When the permission check fails outside Laravel's abort flow.
	 */
	public function handle(mixed $request, \Closure $next, string ...$permissions): mixed {
		$user=method_exists($request, 'user') ? $request->user() : null;
		$context=[
			'route'=>method_exists($request, 'route') ? $request->route() : null,
			'request'=>$request,
		];
		try{
			Permission::ensure($permissions, $user, $context);
			return $next($request);
		}
		catch(\Dataphyre\Permission\Exceptions\AuthorizationException $exception){
			if(function_exists('abort')){
				abort(403);
			}
			throw $exception;
		}
	}
}
