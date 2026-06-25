<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;

/**
 * Integrates Dataphyre permissions with Panel authorization and admin resources.
 *
 * The adapter installs a Panel authorizer, derives permission names from panel
 * abilities and request context, and can register built-in resources for role
 * and assignment management. It centralizes the panel permission naming
 * convention so resource policies, catalog pages, and admin CRUD screens all
 * evaluate the same permission strings.
 */
final class PermissionPanel {

	/**
	 * Registers the permission authorizer on a Panel instance.
	 *
	 * Options are merged over `DP_PERMISSION_CFG['panel']`, stored back into the
	 * panel config under `permission`, and used to build the authorization
	 * closure that checks super-permission and derived panel permission names.
	 *
	 * @param PanelInstance $panel Panel receiving the authorizer.
	 * @param array<string, mixed> $options Panel permission options.
	 * @return PanelInstance Same panel after authorization and config registration.
	 */
	public static function register(PanelInstance $panel, array $options=[]): PanelInstance {
		$options=array_replace(self::config(), $options);
		$panel->authorize(self::authorizer($options));
		return $panel->config('permission', $options);
	}

	/**
	 * Registers role, assignment, and optional catalog administration surfaces.
	 *
	 * The resources use the configured panel group and sort order, and the
	 * catalog page is protected by either the super permission or the explicit
	 * `panel.permission.catalog.view` permission.
	 *
	 * @param PanelInstance $panel Panel receiving permission management resources.
	 * @param array<string, mixed> $options Admin-resource options merged with permission panel config.
	 * @return PanelInstance Same panel after resources/pages are registered.
	 */
	public static function registerAdminResources(PanelInstance $panel, array $options=[]): PanelInstance {
		$options=array_replace([
			'group'=>'Security',
			'sort'=>900,
			'catalog_page'=>true,
		], array_replace(self::config(), $options));
		$panel->register(self::rolesResource($panel, $options));
		$panel->register(self::assignmentsResource($panel, $options));
		if(($options['catalog_page'] ?? true)===true){
			$panel->registerPage($panel->page('permission_catalog')
				->label('Permission Catalog')
				->icon('list-checks')
				->content(static fn(): array => [
					'title'=>'Permission Catalog',
					'content'=>PermissionAudit::html($panel, $options).PermissionCatalog::html($panel, $options),
				])
				->authorize(static fn(mixed $user=null): bool => Permission::any([
					$options['super_permission'] ?? 'panel.*',
					'panel.permission.catalog.view',
				], $user)));
		}
		return $panel;
	}

	/**
	 * Builds the Panel authorization closure used by `PanelInstance::authorize()`.
	 *
	 * Guest pages configured through `allow_guest_pages` bypass permission
	 * checks. All other requests evaluate the configured super permission and
	 * the request-derived panel permission with context containing tenant,
	 * resource, operation, record, action, and relation data.
	 *
	 * @param array<string, mixed> $options Panel permission options.
	 * @return \Closure Closure shaped as `(string $ability, mixed $resource, mixed $user, PanelRequest $request): bool`.
	 */
	public static function authorizer(array $options=[]): \Closure {
		$options=array_replace(self::config(), $options);
		return static function(string $ability, mixed $resource, mixed $user, PanelRequest $request) use ($options): bool {
			if(self::isGuestPage($request, $options)){
				return true;
			}
			$permission=self::permissionFor($ability, $resource, $request, $options);
			$context=[
				'panel'=>$request->query('panel', null),
				'tenant'=>$request->tenant(),
				'resource'=>$request->resourceName(),
				'operation'=>$request->operation(),
				'record'=>$request->recordKey(),
				'action'=>$request->actionName(),
				'relation'=>$request->relationName(),
			];
			return Permission::any([
				$options['super_permission'] ?? 'panel.*',
				$permission,
			], $user, $context);
		};
	}

	/**
	 * Attaches a permission authorizer to a Panel resource.
	 *
	 * Resource ability names are normalized through panel operation aliases and
	 * joined with the configured permission/resource prefixes. The resource
	 * authorizer checks both the super permission and the resource-specific
	 * permission, passing the record in context.
	 *
	 * @param Resource $resource Resource to protect.
	 * @param ?string $name Optional permission resource name override.
	 * @param array<string, mixed> $options Panel permission options.
	 * @return Resource Same resource with authorization callback attached.
	 */
	public static function resource(Resource $resource, ?string $name=null, array $options=[]): Resource {
		$options=array_replace(self::config(), $options);
		$resourceName=$name !== null ? PermissionRule::normalize($name) : $resource->name();
		return $resource->authorize(static function(string $ability, mixed $record, mixed $user) use ($resourceName, $options): bool {
			$operation=self::operation($ability, $options);
			$permission=self::join([
				$options['permission_prefix'] ?? 'panel',
				$options['resource_prefix'] ?? '',
				$resourceName,
				$operation,
			]);
			return Permission::any([$options['super_permission'] ?? 'panel.*', $permission], $user, ['record'=>$record]);
		});
	}

	/**
	 * Builds the built-in role management resource.
	 *
	 * The resource reads and writes through the permission repository and is
	 * protected as `permission.roles.*` through `resource()`.
	 *
	 * @param PanelInstance $panel Panel used to construct the resource definition.
	 * @param array<string, mixed> $options Admin-resource and permission options.
	 * @return Resource Configured role CRUD resource.
	 */
	public static function rolesResource(PanelInstance $panel, array $options=[]): Resource {
		$options=array_replace(self::config(), $options);
		return self::resource(
			$panel->resource('permission_roles')
				->label('Role')
				->pluralLabel('Roles')
				->icon('shield-check')
				->group((string)($options['group'] ?? 'Security'))
				->sort((int)($options['sort'] ?? 900))
				->navigationDescription('Semantic permission bundles.')
				->recordKeyUsing('name')
				->recordTitleUsing('label')
				->queryUsing(static fn(): array => Permission::repository()->rolesWithPermissions())
				->saveUsing(static fn(array $data, mixed $record=null): array => Permission::repository()->saveRoleFromPanel($data, $record))
				->deleteUsing(static fn(array $record): array => [
					'deleted'=>Permission::repository()->deleteRole($record),
					'message'=>'Role deleted.',
				])
				->fields([
					['name'=>'name', 'label'=>'Name', 'required'=>true, 'help'=>'Example: support_manager'],
					['name'=>'label', 'label'=>'Label'],
					['name'=>'description', 'label'=>'Description', 'type'=>'textarea'],
					['name'=>'permissions', 'label'=>'Rules', 'type'=>'textarea', 'required'=>true, 'help'=>'One permission per line. Prefix denies with -.'],
					['name'=>'system', 'label'=>'System role', 'type'=>'checkbox'],
				])
				->columns([
					['name'=>'name', 'label'=>'Role', 'searchable'=>true, 'sortable'=>true, 'copyable'=>true],
					['name'=>'label', 'searchable'=>true, 'sortable'=>true],
					['name'=>'permissions', 'label'=>'Rules', 'type'=>'array', 'toggleable'=>true],
					['name'=>'system', 'type'=>'boolean', 'sortable'=>true],
					['name'=>'updated_at', 'type'=>'datetime', 'sortable'=>true],
				]),
			'permission.roles',
			$options
		);
	}

	/**
	 * Builds the built-in subject assignment management resource.
	 *
	 * Assignment queries are scoped by the panel request tenant. Saves and
	 * deletes delegate to the permission repository, and the resource is
	 * protected as `permission.assignments.*`.
	 *
	 * @param PanelInstance $panel Panel used to construct the resource definition.
	 * @param array<string, mixed> $options Admin-resource and permission options.
	 * @return Resource Configured assignment CRUD resource.
	 */
	public static function assignmentsResource(PanelInstance $panel, array $options=[]): Resource {
		$options=array_replace(self::config(), $options);
		return self::resource(
			$panel->resource('permission_assignments')
				->label('Assignment')
				->pluralLabel('Assignments')
				->icon('key-round')
				->group((string)($options['group'] ?? 'Security'))
				->sort((int)($options['sort'] ?? 900)+1)
				->navigationDescription('Subject-specific roles, grants, and denies.')
				->recordKeyUsing('id')
				->recordTitleUsing(static fn(array $record): string => ($record['subject_type'] ?? 'subject').':'.($record['subject_id'] ?? '').' -> '.($record['value'] ?? ''))
				->queryUsing(static fn(PanelRequest $request): array => Permission::repository()->assignments(['scope'=>$request->tenant()]))
				->saveUsing(static fn(array $data, mixed $record=null): array => Permission::repository()->saveAssignmentFromPanel($data, $record))
				->deleteUsing(static fn(array $record): array => [
					'deleted'=>Permission::repository()->deleteAssignment($record),
					'message'=>'Assignment deleted.',
				])
				->fields([
					['name'=>'subject_type', 'label'=>'Subject type', 'required'=>true, 'default'=>'user'],
					['name'=>'subject_id', 'label'=>'Subject ID', 'required'=>true],
					['name'=>'scope', 'label'=>'Scope', 'default'=>'global', 'required'=>true],
					['name'=>'kind', 'label'=>'Kind', 'type'=>'select', 'required'=>true, 'default'=>'permission', 'options'=>['permission'=>'Permission', 'role'=>'Role']],
					['name'=>'value', 'label'=>'Value', 'required'=>true],
					['name'=>'negative', 'label'=>'Deny', 'type'=>'checkbox'],
					['name'=>'created_by', 'label'=>'Created by'],
				])
				->columns([
					['name'=>'subject_type', 'label'=>'Type', 'searchable'=>true, 'sortable'=>true],
					['name'=>'subject_id', 'label'=>'Subject', 'searchable'=>true, 'sortable'=>true, 'copyable'=>true],
					['name'=>'scope', 'searchable'=>true, 'sortable'=>true],
					['name'=>'kind', 'type'=>'badge', 'sortable'=>true],
					['name'=>'value', 'searchable'=>true, 'copyable'=>true],
					['name'=>'negative', 'label'=>'Deny', 'type'=>'boolean', 'sortable'=>true],
					['name'=>'created_at', 'type'=>'datetime', 'sortable'=>true],
				]),
			'permission.assignments',
			$options
		);
	}

	/**
	 * Derives the permission string for a Panel request.
	 *
	 * Resource names are normalized from the request or explicit resource
	 * object. Relation requests become `relation.{name}.{operation}` and custom
	 * actions become `action.{name}`. Empty ability falls back to the request
	 * operation before operation aliases are applied.
	 *
	 * @param string $ability Panel ability being checked.
	 * @param mixed $resource Resource object or request resource context.
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel permission options.
	 * @return string Normalized permission name such as `panel.posts.update`.
	 */
	public static function permissionFor(string $ability, mixed $resource, PanelRequest $request, array $options=[]): string {
		$options=array_replace(self::config(), $options);
		$resourceName=$request->resourceName();
		if($resource instanceof Resource){
			$resourceName=$resource->name();
		}
		$resourceName=PermissionRule::normalize((string)($resourceName ?? 'dashboard'));
		$operation=self::operation($ability !== '' ? $ability : $request->operation(), $options);
		if($request->relationName()!==null){
			if($operation==='relation'){
				$operation=strtoupper($request->method())==='POST' ? 'update' : 'view';
			}
			$operation='relation.'.$request->relationName().'.'.$operation;
		}
		if($request->actionName()!==null){
			$operation='action.'.$request->actionName();
		}
		return self::join([
			$options['permission_prefix'] ?? 'panel',
			$options['resource_prefix'] ?? '',
			$resourceName,
			$operation,
		]);
	}

	/**
	 * Normalizes a Panel ability into the permission operation vocabulary.
	 *
	 * Built-in aliases map common CRUD and bulk action names to stable
	 * permission operations. Callers may extend or override aliases with
	 * `operation_aliases`.
	 *
	 * @param string $operation Panel ability or operation name.
	 * @param array<string, mixed> $options Panel permission options.
	 * @return string Normalized operation segment.
	 */
	private static function operation(string $operation, array $options): string {
		$operation=PermissionRule::normalize($operation);
		$aliases=array_replace([
			'index'=>'view_any',
			'list'=>'view_any',
			'viewany'=>'view_any',
			'show'=>'view',
			'edit'=>'update',
			'store'=>'create',
			'destroy'=>'delete',
			'bulk_delete'=>'delete_any',
			'bulk_force_delete'=>'force_delete_any',
			'bulk_restore'=>'restore_any',
			'replicate'=>'duplicate',
		], is_array($options['operation_aliases'] ?? null) ? $options['operation_aliases'] : []);
		return PermissionRule::normalize((string)($aliases[$operation] ?? $operation));
	}

	/**
	 * Checks whether the request targets a configured guest-access page.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel permission options.
	 * @return bool `true` when the request resource is in `allow_guest_pages`.
	 */
	private static function isGuestPage(PanelRequest $request, array $options): bool {
		$page=$request->resourceName();
		if($page===null){
			return false;
		}
		$pages=PermissionRule::many($options['allow_guest_pages'] ?? []);
		return in_array(PermissionRule::normalize($page), $pages, true);
	}

	/**
	 * Joins permission name segments after normalizing and removing blanks.
	 *
	 * @param array<int, mixed> $parts Permission name segments.
	 * @return string Dot-delimited permission name.
	 */
	private static function join(array $parts): string {
		return implode('.', array_values(array_filter(array_map(
			static fn(mixed $part): string => trim(PermissionRule::normalize((string)$part), '.'),
			$parts
		), static fn(string $part): bool => $part!=='')));
	}

	/**
	 * Reads permission panel defaults from global configuration.
	 *
	 * @return array<string, mixed> `DP_PERMISSION_CFG['panel']` when present, otherwise an empty option map.
	 */
	private static function config(): array {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		return is_array($config['panel'] ?? null) ? $config['panel'] : [];
	}
}
