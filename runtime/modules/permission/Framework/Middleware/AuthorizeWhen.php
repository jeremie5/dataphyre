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
 * Authorizes a request only when all permissions satisfy configured conditions.
 *
 * Parameters use the compact "permission,permission|condition,condition" route
 * syntax shared with AuthorizeAnyWhen, but this middleware delegates to
 * Permission::ensureWhen() for all-required semantics.
 */
final class AuthorizeWhen {

	/**
	 * Enforces permission and condition checks before invoking the next callback.
	 *
	 * Request arrays and objects are both supported so the middleware can be used
	 * by native Dataphyre routes and framework adapters.
	 *
	 * @param mixed $request Request object or array carrying user/context data.
	 * @param callable $next Downstream middleware callback.
	 * @param ...string $parameters Permission and condition parameters from the route.
	 * @return mixed response or handler value returned by the next callback after permission conditions pass.
	 */
	public function handle(mixed $request, callable $next, string ...$parameters): mixed {
		[$permissions, $conditions]=self::parse($parameters);
		Permission::ensureWhen($permissions, $conditions, self::user($request), self::context($request));
		return $next($request);
	}

	/**
	 * Splits middleware parameters into permission names and condition names.
	 *
	 * @param array<int, string> $parameters Raw route middleware parameters.
	 * @return array{0: array<int, string>, 1: array<int, string>} Permission names and condition names.
	 */
	private static function parse(array $parameters): array {
		$joined=implode(',', array_map(static fn(mixed $value): string => trim((string)$value), $parameters));
		[$permissionPart, $conditionPart]=array_pad(explode('|', $joined, 2), 2, '');
		$permissions=array_values(array_filter(array_map('trim', explode(',', $permissionPart)), static fn(string $value): bool => $value!==''));
		$conditions=array_values(array_filter(array_map('trim', explode(',', $conditionPart)), static fn(string $value): bool => $value!==''));
		return [$permissions, $conditions];
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

	/**
	 * Builds the permission context array for authorization checks.
	 *
	 * @param mixed $request Request object or array supplied to middleware.
	 * @return array<string, mixed> Context containing the original request and any array request data.
	 */
	private static function context(mixed $request): array {
		return is_array($request) ? $request+['request'=>$request] : ['request'=>$request];
	}
}
