<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\Resource;

/**
 * Generates permission catalogs, role matrices, and role presets for Panel resources.
 *
 * The catalog turns registered Panel resources into normalized permission
 * strings, expands action and relation permissions, and can render the result
 * as arrays, Markdown, or HTML. It is a diagnostic and seeding helper: it does
 * not authorize requests directly, but it documents and bootstraps the
 * permission vocabulary consumed by `PermissionPanel`.
 */
final class PermissionCatalog {

	private const BASE_OPERATIONS=[
		'view_any',
		'view',
		'create',
		'update',
		'delete',
		'force_delete',
		'restore',
		'duplicate',
		'export',
		'import',
	];

	/**
	 * Builds the permission catalog for registered Panel resources.
	 *
	 * Hidden resources are included by default, base CRUD/import/export
	 * operations are always present, and action/relation permissions are added
	 * unless disabled in options. Output is keyed internally by permission name,
	 * sorted naturally, and returned as a list for UI and export consumers.
	 *
	 * @param PanelInstance|PanelManager $panel Panel instance or manager whose resources should be inspected.
	 * @param array<string, mixed> $options Catalog options merged with permission panel configuration.
	 * @return array<int, array<string, mixed>> Catalog rows with permission, resource, operation, type, and description metadata.
	 */
	public static function panel(PanelInstance|PanelManager $panel, array $options=[]): array {
		$manager=$panel instanceof PanelInstance ? $panel->manager() : $panel;
		$options=array_replace([
			'permission_prefix'=>'panel',
			'resource_prefix'=>'',
			'include_hidden'=>true,
			'include_action_permissions'=>true,
			'include_relation_permissions'=>true,
		], self::panelConfig(), $options);
		$catalog=[];
		foreach($manager->resources() as $resource){
			if(!$resource instanceof Resource){
				continue;
			}
			$manifest=$resource->toArray();
			if(($manifest['hidden_from_navigation'] ?? false)===true && ($options['include_hidden'] ?? true)!==true){
				continue;
			}
			$resourceName=self::resourcePermissionName($manifest['name'] ?? $resource->name(), $options);
			foreach(self::operationsFor($manifest, $options) as $operation=>$meta){
				$permission=self::join([$options['permission_prefix'], $options['resource_prefix'], $resourceName, $operation]);
				$catalog[$permission]=array_replace([
					'permission'=>$permission,
					'resource'=>$manifest['name'] ?? $resource->name(),
					'resource_label'=>$manifest['plural_label'] ?? $manifest['label'] ?? $resource->name(),
					'operation'=>$operation,
					'type'=>'resource',
					'description'=>self::humanize($operation).' '.$resourceName,
				], $meta);
			}
		}
		ksort($catalog, SORT_NATURAL);
		return array_values($catalog);
	}

	/**
	 * Compares stored role definitions against known permissions.
	 *
	 * When a panel is supplied, the permission universe comes from the generated
	 * panel catalog. Without a panel, permissions are inferred from stored role
	 * rules. Each role is classified into allowed, denied, and missing lists so
	 * operators can spot drift between role definitions and generated policy.
	 *
	 * @param PanelInstance|PanelManager|null $panel Optional panel source for the permission universe.
	 * @param array<string, mixed> $options Catalog options forwarded to `panel()`.
	 * @return array<int, array{role:string, allows:array<int, string>, denies:array<int, string>, missing:array<int, string>}> Role coverage matrix.
	 */
	public static function roleMatrix(PanelInstance|PanelManager|null $panel=null, array $options=[]): array {
		$permissions=$panel!==null ? array_column(self::panel($panel, $options), 'permission') : [];
		$roles=Permission::repository()->roleDefinitions();
		if($permissions===[]){
			foreach($roles as $rules){
				foreach($rules as $rule){
					$unwrapped=PermissionRule::unwrap($rule);
					if(($unwrapped['permission'] ?? '')!==''){
						$permissions[]=$unwrapped['permission'];
					}
				}
			}
		}
		$permissions=array_values(array_unique($permissions));
		sort($permissions, SORT_NATURAL);
		$matrix=[];
		foreach($roles as $role=>$rules){
			$set=Permission::set($rules);
			$row=[
				'role'=>$role,
				'allows'=>[],
				'denies'=>[],
				'missing'=>[],
			];
			foreach($permissions as $permission){
				$result=$set->explain($permission);
				if(($result['allowed'] ?? false)===true){
					$row['allows'][]=$permission;
				}
				elseif(($result['reason'] ?? '')==='deny'){
					$row['denies'][]=$permission;
				}
				else{
					$row['missing'][]=$permission;
				}
			}
			$matrix[]=$row;
		}
		return $matrix;
	}

	/**
	 * Generates opinionated role presets from the panel permission catalog.
	 *
	 * Presets map generated operations to common operator roles. The manager
	 * preset may include explicit denies for destructive operations, preserving a
	 * visible policy boundary even if broader grants are added later.
	 *
	 * @param PanelInstance|PanelManager $panel Panel source for generated permissions.
	 * @param array<string, mixed> $options Preset options such as selected roles and destructive deny behavior.
	 * @return array<string, array{label:string, description:string, permissions:array<int, string>}> Role preset definitions keyed by role name.
	 */
	public static function rolePresets(PanelInstance|PanelManager $panel, array $options=[]): array {
		$options=array_replace([
			'prefix'=>'panel',
			'roles'=>['owner', 'manager', 'operator', 'viewer', 'auditor'],
			'deny_dangerous_for_manager'=>true,
		], $options);
		$rows=self::panel($panel, $options);
		$permissions=array_values(array_unique(array_column($rows, 'permission')));
		$byOperation=[];
		foreach($rows as $row){
			$operation=(string)($row['operation'] ?? '');
			$permission=(string)($row['permission'] ?? '');
			if($operation!=='' && $permission!==''){
				$byOperation[$operation][]=$permission;
			}
		}
		$presets=[
			'owner'=>[
				'label'=>'Owner',
				'description'=>'Full Panel access generated from the permission catalog.',
				'permissions'=>['panel.*'],
			],
			'manager'=>[
				'label'=>'Manager',
				'description'=>'Operational access without destructive force-delete controls.',
				'permissions'=>array_values(array_unique(array_merge(
					self::permissionsForOperations($byOperation, ['view_any', 'view', 'create', 'update', 'duplicate', 'restore', 'export', 'import']),
					self::permissionsByPrefix($permissions, ['panel.permission.catalog.view'])
				))),
			],
			'operator'=>[
				'label'=>'Operator',
				'description'=>'Daily work access for viewing, creating, updating, and non-destructive actions.',
				'permissions'=>self::permissionsForOperations($byOperation, ['view_any', 'view', 'create', 'update', 'export']),
			],
			'viewer'=>[
				'label'=>'Viewer',
				'description'=>'Read-only access.',
				'permissions'=>self::permissionsForOperations($byOperation, ['view_any', 'view']),
			],
			'auditor'=>[
				'label'=>'Auditor',
				'description'=>'Read and export access for review workflows.',
				'permissions'=>self::permissionsForOperations($byOperation, ['view_any', 'view', 'export']),
			],
		];
		if(($options['deny_dangerous_for_manager'] ?? true)===true){
			foreach(self::permissionsForOperations($byOperation, ['delete', 'force_delete']) as $permission){
				$presets['manager']['permissions'][]='-'.$permission;
			}
		}
		$wanted=array_fill_keys(PermissionRule::many($options['roles'] ?? array_keys($presets)), true);
		$filtered=[];
		foreach($presets as $role=>$preset){
			if(isset($wanted[$role])){
				$preset['permissions']=array_values(array_unique($preset['permissions']));
				sort($preset['permissions'], SORT_NATURAL);
				$filtered[$role]=$preset;
			}
		}
		return $filtered;
	}

	/**
	 * Stores generated role presets through the permission repository.
	 *
	 * Each preset is saved as a role with label, description, and system flag.
	 * Repository return values are preserved per role so seed callers can report
	 * partial success or validation failures.
	 *
	 * @param PanelInstance|PanelManager $panel Panel source for generated presets.
	 * @param array<string, mixed> $options Preset and repository options.
	 * @return array<string, mixed> Repository result keyed by seeded role name.
	 */
	public static function seedRolePresets(PanelInstance|PanelManager $panel, array $options=[]): array {
		$presets=self::rolePresets($panel, $options);
		$results=[];
		foreach($presets as $role=>$preset){
			$results[$role]=Permission::storeRole($role, $preset['permissions'] ?? [], [
				'label'=>$preset['label'] ?? ucfirst($role),
				'description'=>$preset['description'] ?? '',
				'system'=>(bool)($options['system'] ?? true),
			]);
		}
		return $results;
	}

	/**
	 * Renders the generated permission catalog as Markdown.
	 *
	 * Table-cell pipe characters are escaped so labels and operation names do
	 * not break the generated table.
	 *
	 * @param PanelInstance|PanelManager $panel Panel source for generated permissions.
	 * @param array<string, mixed> $options Catalog options forwarded to `panel()`.
	 * @return string Markdown document containing a permission table.
	 */
	public static function markdown(PanelInstance|PanelManager $panel, array $options=[]): string {
		$rows=self::panel($panel, $options);
		$lines=[
			'# Permission Catalog',
			'',
			'| Permission | Resource | Operation | Type |',
			'| --- | --- | --- | --- |',
		];
		foreach($rows as $row){
			$lines[]='| `'.$row['permission'].'` | '.self::md($row['resource_label'] ?? $row['resource'] ?? '').' | `'.self::md($row['operation'] ?? '').'` | '.self::md($row['type'] ?? '').' |';
		}
		return implode("\n", $lines)."\n";
	}

	/**
	 * Renders the generated permission catalog as escaped Panel HTML.
	 *
	 * All dynamic values are HTML-escaped before insertion. The returned markup
	 * is intended for trusted Panel chrome, not as a standalone public document.
	 *
	 * @param PanelInstance|PanelManager $panel Panel source for generated permissions.
	 * @param array<string, mixed> $options Catalog options forwarded to `panel()`.
	 * @return string HTML section containing a permission table.
	 */
	public static function html(PanelInstance|PanelManager $panel, array $options=[]): string {
		$rows=self::panel($panel, $options);
		$html='<div class="dp-panel-section"><h2>Permission Catalog</h2><p class="dp-panel-muted">Generated semantic permissions for registered Panel resources.</p>';
		$html.='<table class="dp-panel-table"><thead><tr><th>Permission</th><th>Resource</th><th>Operation</th><th>Type</th></tr></thead><tbody>';
		foreach($rows as $row){
			$html.='<tr><td><code>'.self::e((string)$row['permission']).'</code></td><td>'.self::e((string)($row['resource_label'] ?? $row['resource'] ?? '')).'</td><td><code>'.self::e((string)($row['operation'] ?? '')).'</code></td><td>'.self::e((string)($row['type'] ?? '')).'</td></tr>';
		}
		$html.='</tbody></table></div>';
		return $html;
	}

	/**
	 * Expands a resource manifest into permission operations.
	 *
	 * @param array<string, mixed> $manifest Resource manifest from `Resource::toArray()`.
	 * @param array<string, mixed> $options Catalog expansion options.
	 * @return array<string, array<string, mixed>> Operation metadata keyed by operation segment.
	 */
	private static function operationsFor(array $manifest, array $options): array {
		$operations=[];
		foreach(self::BASE_OPERATIONS as $operation){
			$operations[$operation]=[];
		}
		foreach([
			'bulk_updates'=>'bulk_update',
			'duplicates'=>'duplicate',
			'restores'=>'restore',
			'deletes'=>'delete',
			'force_deletes'=>'force_delete',
			'imports'=>'import',
		] as $flag=>$operation){
			if(($manifest[$flag] ?? false)===true){
				$operations[$operation]['capability']=$flag;
			}
		}
		if(($options['include_action_permissions'] ?? true)===true){
			foreach(($manifest['actions'] ?? []) as $action){
				if(!is_array($action)){
					continue;
				}
				$name=PermissionRule::normalize((string)($action['name'] ?? ''));
				if($name!==''){
					$operations['action.'.$name]=['type'=>'action'];
				}
			}
		}
		if(($options['include_relation_permissions'] ?? true)===true){
			foreach(($manifest['relations'] ?? []) as $relation){
				if(!is_array($relation)){
					continue;
				}
				$name=PermissionRule::normalize((string)($relation['name'] ?? ''));
				if($name!==''){
					$operations['relation.'.$name.'.view']=['type'=>'relation'];
					$operations['relation.'.$name.'.update']=['type'=>'relation'];
				}
			}
		}
		return $operations;
	}

	/**
	 * Selects permissions that belong to requested operation groups.
	 *
	 * @param array<string, array<int, string>> $byOperation Permissions grouped by operation.
	 * @param array<int, string> $operations Operation names to include.
	 * @return array<int, string> Unique permissions in requested operation groups.
	 */
	private static function permissionsForOperations(array $byOperation, array $operations): array {
		$permissions=[];
		foreach($operations as $operation){
			foreach($byOperation[$operation] ?? [] as $permission){
				$permissions[]=$permission;
			}
		}
		return array_values(array_unique($permissions));
	}

	/**
	 * Selects exact permissions from a wanted set.
	 *
	 * @param array<int, string> $permissions Candidate permissions.
	 * @param array<int, string> $wanted Exact permissions to retain.
	 * @return array<int, string> Matching permissions.
	 */
	private static function permissionsByPrefix(array $permissions, array $wanted): array {
		$wanted=array_fill_keys($wanted, true);
		return array_values(array_filter($permissions, static fn(string $permission): bool => isset($wanted[$permission])));
	}

	/**
	 * Normalizes a Panel resource name for permission generation.
	 *
	 * Permission management resources use `permission.*` segments instead of
	 * raw `permission_*` resource names.
	 *
	 * @param string $resource Panel resource name.
	 * @param array<string, mixed> $options Reserved for future naming options.
	 * @return string Permission resource segment.
	 */
	private static function resourcePermissionName(string $resource, array $options): string {
		$resource=PermissionRule::normalize($resource);
		if(str_starts_with($resource, 'permission_')){
			return 'permission.'.substr($resource, strlen('permission_'));
		}
		return $resource;
	}

	/**
	 * Joins permission segments after normalization and blank removal.
	 *
	 * @param array<int, mixed> $parts Permission segments.
	 * @return string Dot-delimited permission name.
	 */
	private static function join(array $parts): string {
		return implode('.', array_values(array_filter(array_map(
			static fn(mixed $part): string => trim(PermissionRule::normalize((string)$part), '.'),
			$parts
		), static fn(string $part): bool => $part!=='')));
	}

	/**
	 * Reads panel permission defaults from global configuration.
	 *
	 * @return array<string, mixed> `DP_PERMISSION_CFG['panel']` when present, otherwise an empty option map.
	 */
	private static function panelConfig(): array {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		return is_array($config['panel'] ?? null) ? $config['panel'] : [];
	}

	/**
	 * Escapes text for HTML output.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-safe text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Escapes text for Markdown table cells.
	 *
	 * @param string $value Raw table-cell text.
	 * @return string Markdown table-safe text.
	 */
	private static function md(string $value): string {
		return str_replace('|', '\\|', $value);
	}

	/**
	 * Converts operation segments into a readable description fragment.
	 *
	 * @param string $value Operation segment.
	 * @return string Human-readable operation label.
	 */
	private static function humanize(string $value): string {
		return ucfirst(str_replace(['.', '_'], ' ', $value));
	}
}
