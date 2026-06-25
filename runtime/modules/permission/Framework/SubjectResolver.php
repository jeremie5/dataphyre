<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

use Dataphyre\Access\Auth;

/**
 * Resolves the effective permission subject used by guard and policy checks.
 *
 * The resolver is the Permission module's identity adapter. It accepts an
 * explicit subject when one is supplied, otherwise it consults configured
 * callbacks, the Access\Auth facade, and the legacy session user id fallback.
 * All public methods normalize their output so authorization code can compare
 * identifiers, roles, and rule strings without knowing how the current project
 * represents users.
 *
 * @api
 */
final class SubjectResolver {

	private static ?array $lastDefaultRolesSubject=null;

	/** @var list<string>|null */
	private static ?array $lastDefaultRolesOutput=null;

	/**
	 * Resolves a stable subject identifier for permission comparisons.
	 *
	 * Resolution order is intentionally project-overridable: a configured
	 * `id_resolver` wins first, followed by common subject fields (`id`,
	 * `user_id`, `userid`, `uuid`), then Access\Auth::id(), and finally the
	 * legacy `$_SESSION['userid']` value. Empty strings, `false`, and `null`
	 * are treated as unresolved values so later fallbacks can still answer.
	 *
	 * @param mixed $subject Explicit subject array, object, scalar wrapper, or null to use the current authenticated subject.
	 * @return int|string|null Subject identifier suitable for rule lookups, or null when no identity is available.
	 */
	public static function id(mixed $subject=null): int|string|null {
		$config=self::subjectConfig();
		$resolver=$config['id_resolver'] ?? null;
		if(is_callable($resolver)){
			$value=$resolver($subject);
			if($value!==null && $value!==false && $value!==''){
				return $value;
			}
		}
		$subject=self::subject($subject);
		foreach(['id', 'user_id', 'userid', 'uuid'] as $key){
			$value=self::read($subject, $key);
			if($value!==null && $value!==false && $value!==''){
				return $value;
			}
		}
		if(class_exists(Auth::class)){
			return Auth::id();
		}
		if(isset($_SESSION['userid'])){
			return $_SESSION['userid'];
		}
		return null;
	}

	/**
	 * Resolves the current permission subject object or array.
	 *
	 * Explicit subjects are returned unchanged. When no subject is passed, a
	 * configured `user_resolver` may supply the project-specific user model; if
	 * it does not, the Access\Auth facade is used only when it is loaded and has
	 * an authenticated user. This method never creates guests or placeholder
	 * users, which keeps anonymous authorization decisions explicit.
	 *
	 * @param mixed $subject Explicit subject supplied by a caller, or null to resolve the active authenticated subject.
	 * @return mixed Subject value consumed by permission and role resolvers, or null for anonymous callers.
	 */
	public static function subject(mixed $subject=null): mixed {
		if($subject!==null){
			return $subject;
		}
		$config=self::subjectConfig();
		$resolver=$config['user_resolver'] ?? null;
		if(is_callable($resolver)){
			$value=$resolver();
			if($value!==null){
				return $value;
			}
		}
		if(class_exists(Auth::class) && Auth::check()){
			return Auth::user();
		}
		return null;
	}

	/**
	 * Resolves normalized permission rule strings for a subject.
	 *
	 * Permission lookup can be delegated to the Dataphyre core dialback, a
	 * configured `permission_resolver`, or fields/getters on the subject itself.
	 * Every source is passed through PermissionRule::many() so callers receive a
	 * de-duplicated list of comparable permission tokens regardless of whether
	 * the backing project stores strings, arrays, or richer rule values.
	 *
	 * @param mixed $subject Explicit subject or null to resolve the current subject.
	 * @param array<string,mixed> $context Policy context forwarded to dialbacks and project resolvers, commonly including resource, tenant, owner, route, or request data.
	 * @return list<string> Unique normalized permission tokens.
	 */
	public static function permissions(mixed $subject=null, array $context=[]): array {
		if(class_exists('\dataphyre\core', false)){
			$dialback=\dataphyre\core::dialback('CALL_PERMISSION_RESOLVE_SUBJECT_PERMISSIONS', $subject, $context);
			if($dialback!==null){
				return PermissionRule::many($dialback);
			}
		}
		$config=self::subjectConfig();
		$resolver=$config['permission_resolver'] ?? null;
		if(is_callable($resolver)){
			return PermissionRule::many($resolver(self::subject($subject), $context));
		}
		$subject=self::subject($subject);
		$permissions=[];
		foreach(($config['permission_keys'] ?? ['permissions']) as $key){
			$value=self::read($subject, (string)$key);
			if($value!==null){
				$permissions=array_merge($permissions, PermissionRule::many($value));
			}
		}
		return array_values(array_unique($permissions));
	}

	/**
	 * Resolves normalized role names for a subject.
	 *
	 * Roles follow the same extension chain as permissions: Dataphyre dialback,
	 * configured resolver, then configured subject fields. Permission tokens with
	 * `role.` or `group.` prefixes are also promoted into roles so projects can
	 * express role membership through rule grants without duplicating storage.
	 *
	 * @param mixed $subject Explicit subject or null to resolve the current subject.
	 * @param array<string,mixed> $context Policy context forwarded to dialbacks and project resolvers, commonly including resource, tenant, owner, route, or request data.
	 * @return list<string> Unique normalized role or group names.
	 */
	public static function roles(mixed $subject=null, array $context=[]): array {
		if(class_exists('\dataphyre\core', false)){
			$dialback=\dataphyre\core::dialback('CALL_PERMISSION_RESOLVE_SUBJECT_ROLES', $subject, $context);
			if($dialback!==null){
				return PermissionRule::many($dialback);
			}
		}
		$config=self::subjectConfig();
		$resolver=$config['role_resolver'] ?? null;
		if(is_callable($resolver)){
			return PermissionRule::many($resolver(self::subject($subject), $context));
		}
		$useDefaultArrayCache=$context===[]
			&& is_array($subject)
			&& ($config['role_keys'] ?? ['roles'])===['roles']
			&& ($config['permission_keys'] ?? ['permissions'])===['permissions'];
		if($useDefaultArrayCache && self::$lastDefaultRolesOutput!==null && self::$lastDefaultRolesSubject===$subject){
			return self::$lastDefaultRolesOutput;
		}
		$subject=self::subject($subject);
		$roles=[];
		foreach(($config['role_keys'] ?? ['roles']) as $key){
			$value=self::read($subject, (string)$key);
			if($value!==null){
				$roles=array_merge($roles, PermissionRule::many($value));
			}
		}
		foreach(self::permissions($subject, $context) as $permission){
			if(str_starts_with($permission, 'role.')){
				$roles[]=substr($permission, 5);
			}
			elseif(str_starts_with($permission, 'group.')){
				$roles[]=substr($permission, 6);
			}
		}
		$roles=array_values(array_unique($roles));
		if($useDefaultArrayCache){
			self::$lastDefaultRolesSubject=$subject;
			self::$lastDefaultRolesOutput=$roles;
		}
		return $roles;
	}

	/**
	 * Reads a configured identity, permission, or role field from a subject.
	 *
	 * Arrays are read by key, objects are read from public properties first, and
	 * then from conventional getters such as `getUserId()` for `user_id`. Missing
	 * values return null instead of raising notices so optional subject shapes can
	 * be probed safely during fallback resolution.
	 *
	 * @param mixed $subject Subject array or object to inspect.
	 * @param string $key Field name requested by the resolver configuration.
	 * @return mixed Field value, getter result, or null when the key is unavailable.
	 */
	private static function read(mixed $subject, string $key): mixed {
		if($subject===null){
			return null;
		}
		if(is_array($subject)){
			return $subject[$key] ?? null;
		}
		if(is_object($subject)){
			if(isset($subject->{$key})){
				return $subject->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($subject, $method)){
				return $subject->{$method}();
			}
		}
		return null;
	}

	/**
	 * Returns the permission subject resolver configuration.
	 *
	 * The configuration is read from the global DP_PERMISSION_CFG constant when
	 * present. Only the nested `subject` array is exposed here, which keeps the
	 * resolver isolated from unrelated permission settings and lets tests provide
	 * a narrow fixture.
	 *
	 * @return array<string, mixed> Subject resolver callbacks, field names, and fallback options.
	 */
	private static function subjectConfig(): array {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		return is_array($config['subject'] ?? null) ? $config['subject'] : [];
	}
}
