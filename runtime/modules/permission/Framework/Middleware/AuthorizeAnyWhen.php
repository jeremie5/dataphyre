<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission\Middleware;

use Dataphyre\Permission\Permission;
use Dataphyre\Permission\PermissionCondition;
use Dataphyre\Permission\PermissionRule;
use Dataphyre\Permission\Exceptions\AuthorizationException;

/**
 * Authorizes a request when any permission also satisfies configured conditions.
 *
 * Middleware parameters use a compact "permission,permission|condition,condition"
 * form so route definitions can express both the permission set and contextual
 * gates without constructing PermissionRule objects manually.
 */
final class AuthorizeAnyWhen {

	/**
	 * Passes the request when at least one permission and condition set succeeds.
	 *
	 * The request user and context are extracted defensively from array or
	 * object-style requests so the middleware can run outside a single HTTP
	 * framework adapter.
	 *
	 * @param mixed $request Request object or array carrying user/context data.
	 * @param callable $next Downstream middleware callback.
	 * @param ...string $parameters Permission and condition parameters from the route.
	 * @return mixed response or handler value returned by the next callback after one permission path passes.
	 * @throws AuthorizationException When no permission passes the configured conditions.
	 */
	public function handle(mixed $request, callable $next, string ...$parameters): mixed {
		[$permissions, $conditions]=self::parse($parameters);
		$user=self::user($request);
		$context=self::context($request);
		foreach(PermissionRule::many($permissions) as $permission){
			if(Permission::check($permission, $user, $context) && PermissionCondition::passes($conditions, $user, $context, $permission)){
				return $next($request);
			}
		}
		throw new AuthorizationException('Permission condition denied.', PermissionRule::many($permissions), $context+['conditions'=>PermissionCondition::normalizeMany($conditions)]);
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
