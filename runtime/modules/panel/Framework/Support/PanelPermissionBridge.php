<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Translates panel resources, actions, relations, and pages into permission identifiers.
 *
 * The bridge is the panel framework's integration point with the optional
 * Dataphyre Permission module. It keeps permission naming deterministic for
 * generated UI, route handlers, resource actions, and relation managers while
 * allowing installations without the permission module to continue operating.
 *
 * Security model:
 * - when panel permissions are not configured, checks intentionally allow access;
 * - when permissions are configured but the module cannot be loaded, checks also
 *   allow access so configuration can be staged before the dependency exists;
 * - when the permission module is available and throws, checks deny access and
 *   record the failure through panel tracing.
 */
final class PanelPermissionBridge {

	/**
	 * Reports whether panel permission checks are enabled by configuration.
	 *
	 * The bridge accepts either `permission` or legacy `permissions` panel config
	 * keys. A missing key or explicit `false` keeps panel authorization in
	 * permissive mode, which is useful while scaffolding a project before the
	 * permission module and grants are in place.
	 *
	 * @return bool True when panel permission checks should be attempted.
	 */
	public static function configured(): bool {
		$config=PanelConfig::config('permission', PanelConfig::config('permissions', null));
		return $config!==null && $config!==false;
	}

	/**
	 * Reports whether the Dataphyre Permission facade can be called.
	 *
	 * The method first checks for an already-loaded facade and then asks the core
	 * loader for the `permission` framework module when core is present. It does
	 * not instantiate permission repositories or validate grants; it only confirms
	 * that the static facade needed by `allows()` exists.
	 *
	 * @return bool True when `\Dataphyre\Permission\Permission` is loadable.
	 */
	public static function available(): bool {
		$available=class_exists('\Dataphyre\Permission\Permission');
		if(!$available && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('permission')===true){
			$available=class_exists('\Dataphyre\Permission\Permission');
		}
		return $available;
	}

	/**
	 * Builds the effective permission naming options for panel authorization.
	 *
	 * Options are assembled from panel config, optional global
	 * `DP_PERMISSION_CFG['panel']`, and hard defaults. Panel config wins over the
	 * global permission config; both win over defaults. The returned payload is
	 * read by permission-name builders and by the authorization gate, so it keeps
	 * all expected keys present even when callers provide a partial config.
	 *
	 * @return array{permission_prefix:string,resource_prefix:string,super_permission:string,allow_guest_pages?:array<int,string>} Effective panel permission options.
	 */
	public static function options(): array {
		$config=PanelConfig::config('permission', PanelConfig::config('permissions', []));
		$options=is_array($config) ? $config : [];
		if(defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) && is_array(\DP_PERMISSION_CFG['panel'] ?? null)){
			$options=array_replace(\DP_PERMISSION_CFG['panel'], $options);
		}
		return array_replace([
			'permission_prefix'=>'panel',
			'resource_prefix'=>'',
			'super_permission'=>'panel.*',
		], $options);
	}

	/**
	 * Normalizes a panel resource name for inclusion in permission identifiers.
	 *
	 * Resource names are passed through `Resource::normalizeName()` so route,
	 * class-derived, and hand-written names collapse to the same permission
	 * segment. Resources generated for the permission UI keep a stable
	 * `permission.*` namespace instead of the internal `permission_` prefix.
	 *
	 * @param string $resource Panel resource slug, class-derived name, or route-facing resource token.
	 * @return string Permission-safe resource segment.
	 */
	public static function resourceName(string $resource): string {
		$resource=Resource::normalizeName($resource);
		if(str_starts_with($resource, 'permission_')){
			return 'permission.'.substr($resource, strlen('permission_'));
		}
		return $resource;
	}

	/**
	 * Builds a canonical panel permission identifier for a resource operation.
	 *
	 * The resulting name has the configured permission prefix, optional resource
	 * prefix, normalized resource segment, and operation segment joined by dots.
	 * Empty segments are removed so disabling `resource_prefix` does not produce
	 * double-dot identifiers.
	 *
	 * @param string $resource Panel resource name before permission normalization.
	 * @param string $operation Operation segment such as `view`, `create`, `update`, or `delete`.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return string Dot-delimited permission identifier, for example `panel.orders.view`.
	 */
	public static function name(string $resource, string $operation, ?array $options=null): string {
		$options=$options ?? self::options();
		return self::join([
			$options['permission_prefix'] ?? 'panel',
			$options['resource_prefix'] ?? '',
			self::resourceName($resource),
			$operation,
		]);
	}

	/**
	 * Builds the permission identifier used by a custom resource action.
	 *
	 * Action names live under an `action.*` operation namespace so they do not
	 * collide with CRUD operations or relation operations on the same resource.
	 *
	 * @param string $resource Panel resource that owns the action.
	 * @param string $action Action label, slug, or method-derived action name.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return string Permission identifier such as `panel.orders.action.refund`.
	 */
	public static function actionName(string $resource, string $action, ?array $options=null): string {
		return self::name($resource, 'action.'.Resource::normalizeName($action), $options);
	}

	/**
	 * Builds the permission identifier used by a resource relation manager.
	 *
	 * Relation operations intentionally collapse to either `view` or `update`.
	 * Index-like operations inspect related data, while every other relation
	 * operation can mutate the relationship or related records and is checked as
	 * an update.
	 *
	 * @param string $resource Panel resource that exposes the relation.
	 * @param string $relation Relation name before normalization.
	 * @param string $operation Relation operation requested by the panel surface.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return string Permission identifier such as `panel.orders.relation.items.view`.
	 */
	public static function relationName(string $resource, string $relation, string $operation='view', ?array $options=null): string {
		$operation=Resource::normalizeName($operation);
		$operation=in_array($operation, ['view', 'index', 'list'], true) ? 'view' : 'update';
		return self::name($resource, 'relation.'.Resource::normalizeName($relation).'.'.$operation, $options);
	}

	/**
	 * Builds the permission identifier used by a standalone panel page.
	 *
	 * Page operation aliases mirror common route and controller verbs: index,
	 * show, and render become `view`; store becomes `create`; edit becomes
	 * `update`. Unknown non-empty operations pass through normalization so custom
	 * page flows can define their own permission segments.
	 *
	 * @param string $page Panel page name, slug, or route-facing page token.
	 * @param string $operation Page operation or controller verb.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return string Permission identifier such as `panel.dashboard.view`.
	 */
	public static function pageName(string $page, string $operation='view', ?array $options=null): string {
		$operation=Resource::normalizeName($operation);
		$operation=match ($operation) {
			'index', 'show', 'render' => 'view',
			'store' => 'create',
			'edit' => 'update',
			default => $operation ?: 'view',
		};
		return self::name($page, $operation, $options);
	}

	/**
	 * Reports whether a page is explicitly allowed before user authorization.
	 *
	 * Guest pages are compared after normalization, allowing config authors to use
	 * route-like, title-like, or class-derived names without changing the
	 * generated permission namespace. This method only checks the page allow-list;
	 * it does not consult the permission module.
	 *
	 * @param string $page Page requested by the panel router or renderer.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return bool True when the normalized page is present in `allow_guest_pages`.
	 */
	public static function allowsGuestPage(string $page, ?array $options=null): bool {
		$options=$options ?? self::options();
		$pages=is_array($options['allow_guest_pages'] ?? null) ? $options['allow_guest_pages'] : [];
		$page=Resource::normalizeName($page);
		foreach($pages as $candidate){
			if(Resource::normalizeName((string)$candidate)===$page){
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates a panel permission against the configured authorization backend.
	 *
	 * Authorization is permissive until panel permissions are configured and the
	 * permission module is available. Once both are true, the user is allowed when
	 * they hold either the configured super permission or the exact panel
	 * permission. Backend exceptions are treated as denials because they happen
	 * after the application has opted into authorization enforcement.
	 *
	 * @param string $permission Concrete panel permission identifier produced by this bridge.
	 * @param mixed $user Optional identity object, scalar user id, or null to let the permission module resolve the active user.
	 * @param array<string,mixed> $context Additional facts forwarded unchanged to the permission module.
	 * @param ?array $options Precomputed option payload from `options()`; null reads the current config.
	 * @return bool True when access should be granted for the requested panel operation.
	 */
	public static function allows(string $permission, mixed $user=null, array $context=[], ?array $options=null): bool {
		if(!self::configured()){
			return true;
		}
		$options=$options ?? self::options();
		if(!self::available()){
			return true;
		}
		try{
			return \Dataphyre\Permission\Permission::any([
				$options['super_permission'] ?? 'panel.*',
				$permission,
			], $user, $context);
		}
		catch(\Throwable $exception){
			PanelTrace::record('permission_bridge.error', [
				'permission'=>$permission,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Joins permission name segments into a normalized dot-delimited identifier.
	 *
	 * Each segment is normalized through the panel resource normalizer and trimmed
	 * of leading or trailing dots before empty values are discarded. The helper is
	 * private so all public builders share the same identifier hygiene rules.
	 *
	 * @param array<int,mixed> $parts Permission name segments before normalization.
	 * @return string Permission identifier with no empty segments.
	 */
	private static function join(array $parts): string {
		return implode('.', array_values(array_filter(array_map(
			static fn(mixed $part): string => trim(Resource::normalizeName((string)$part), '.'),
			$parts
		), static fn(string $part): bool => $part!=='')));
	}
}
