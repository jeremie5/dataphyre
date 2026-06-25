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

/**
 * Builds portable manifests for permission catalogs, roles, and audits.
 *
 * Manifests are deterministic snapshots of the permission surface used by
 * documentation, reviews, deployments, and import tooling. They can include Panel
 * catalog entries, role presets, stored roles, stored assignments, audit
 * findings, and optional generation metadata.
 */
final class PermissionManifest {

	/**
	 * @var array{left:array<string,mixed>, right:array<string,mixed>, report:array<string,mixed>}|null
	 */
	private static ?array $diffCache=null;

	/**
	 * Builds a normalized permission manifest.
	 *
	 * Options control which sections are included. Generated manifests are sorted
	 * before returning so repeated builds can be diffed without noise from source
	 * ordering, repository ordering, or assignment iteration order.
	 *
	 * @param PanelInstance|PanelManager|null $panel Panel source used for catalog, presets, and audit sections.
	 * @param array<string, mixed> $options Inclusion options and catalog/audit options.
	 * @return array<string, mixed> Manifest with `version`, `module`, and selected permission sections.
	 */
	public static function build(PanelInstance|PanelManager|null $panel=null, array $options=[]): array {
		$options=array_replace([
			'include_catalog'=>true,
			'include_presets'=>true,
			'include_roles'=>true,
			'include_assignments'=>false,
			'include_audit'=>true,
			'include_generated_at'=>false,
		], $options);
		$manifest=[
			'version'=>1,
			'module'=>'dataphyre.permission',
		];
		if(($options['include_generated_at'] ?? false)===true){
			$manifest['generated_at']=gmdate('c');
		}
		if($panel!==null && ($options['include_catalog'] ?? true)===true){
			$manifest['catalog']=PermissionCatalog::panel($panel, $options);
		}
		if($panel!==null && ($options['include_presets'] ?? true)===true){
			$manifest['presets']=PermissionCatalog::rolePresets($panel, $options);
		}
		if(($options['include_roles'] ?? true)===true){
			$manifest['roles']=self::normalizeRoles(Permission::repository()->roleDefinitions());
		}
		if(($options['include_assignments'] ?? false)===true){
			$manifest['assignments']=self::normalizeAssignments(Permission::repository()->assignments());
		}
		if(($options['include_audit'] ?? true)===true){
			$manifest['audit']=PermissionAudit::run($panel, $options);
		}
		return self::sortManifest($manifest);
	}

	/**
	 * Encodes a permission manifest as JSON.
	 *
	 * Pretty JSON is the default because manifests are intended for review,
	 * checked-in snapshots, and generated reference output. The output always ends with a
	 * trailing newline for file-friendly exports.
	 *
	 * @param PanelInstance|PanelManager|null $panel Panel source used by build().
	 * @param array<string, mixed> $options Build options plus optional `pretty=false`.
	 * @return string JSON manifest document.
	 */
	public static function json(PanelInstance|PanelManager|null $panel=null, array $options=[]): string {
		$flags=JSON_UNESCAPED_SLASHES;
		if(($options['pretty'] ?? true)!==false){
			$flags|=JSON_PRETTY_PRINT;
		}
		return json_encode(self::build($panel, $options), $flags)."\n";
	}

	/**
	 * Diffs two permission manifests.
	 *
	 * Role definitions are normalized before comparison, and catalog comparison
	 * uses only permission tokens. This keeps diffs focused on authorization
	 * behavior rather than labels or presentation metadata.
	 *
	 * @param array<string, mixed> $left Baseline manifest.
	 * @param array<string, mixed> $right Candidate manifest.
	 * @return array{roles: array<string, array<int, string>>, catalog: array{added: array<int, string>, removed: array<int, string>}, changed: array{roles: array<int, string>, catalog: array<int, string>}}
	 */
	public static function diff(array $left, array $right): array {
		if(
			self::$diffCache!==null &&
			self::$diffCache['left']===$left &&
			self::$diffCache['right']===$right
		){
			return self::$diffCache['report'];
		}
		$cacheable=self::isCacheableTree($left) && self::isCacheableTree($right);
		$leftRoles=self::normalizeRoles(is_array($left['roles'] ?? null) ? $left['roles'] : []);
		$rightRoles=self::normalizeRoles(is_array($right['roles'] ?? null) ? $right['roles'] : []);
		$leftCatalog=self::catalogPermissions(is_array($left['catalog'] ?? null) ? $left['catalog'] : []);
		$rightCatalog=self::catalogPermissions(is_array($right['catalog'] ?? null) ? $right['catalog'] : []);
		$roleDiff=self::diffMap($leftRoles, $rightRoles);
		$report=[
			'roles'=>$roleDiff,
			'catalog'=>[
				'added'=>array_values(array_diff($rightCatalog, $leftCatalog)),
				'removed'=>array_values(array_diff($leftCatalog, $rightCatalog)),
			],
			'changed'=>[
				'roles'=>$roleDiff['changed'],
				'catalog'=>array_values(array_diff(array_merge(array_diff($rightCatalog, $leftCatalog), array_diff($leftCatalog, $rightCatalog)), [])),
			],
		];
		if($cacheable){
			self::$diffCache=[
				'left'=>$left,
				'right'=>$right,
				'report'=>$report,
			];
		}
		return $report;
	}

	/**
	 * Imports role definitions from a manifest into the permission repository.
	 *
	 * Roles are read from the `roles` section when present and fall back to
	 * `presets`. Dry-run mode reports the roles that would be stored without
	 * mutating the repository.
	 *
	 * @param array<string, mixed> $manifest Permission manifest or role preset data.
	 * @param array{dry_run?: bool, system?: bool} $options Import behavior options.
	 * @return array<string, bool> Store result keyed by normalized role name.
	 */
	public static function importRoles(array $manifest, array $options=[]): array {
		$roles=self::rolesFromManifest($manifest);
		$results=[];
		foreach($roles as $role=>$permissions){
			if(($options['dry_run'] ?? false)===true){
				$results[$role]=true;
				continue;
			}
			$results[$role]=Permission::storeRole($role, $permissions, [
				'label'=>ucfirst(str_replace(['.', '_', '-'], ' ', $role)),
				'description'=>'Imported from permission manifest.',
				'system'=>(bool)($options['system'] ?? true),
			]);
		}
		return $results;
	}

	/**
	 * Extracts normalized role definitions from manifest data.
	 *
	 * @param array<string, mixed> $manifest Permission manifest or role preset data.
	 * @return array<string, array<int, string>> Normalized role permission map.
	 */
	private static function rolesFromManifest(array $manifest): array {
		$source=is_array($manifest['roles'] ?? null) ? $manifest['roles'] : [];
		if($source===[] && is_array($manifest['presets'] ?? null)){
			$source=$manifest['presets'];
		}
		$roles=[];
		foreach($source as $role=>$definition){
			$role=PermissionRule::normalize((string)$role);
			if($role===''){
				continue;
			}
			if(is_array($definition) && array_key_exists('permissions', $definition)){
				$roles[$role]=PermissionRule::many($definition['permissions']);
			}
			else{
				$roles[$role]=PermissionRule::many($definition);
			}
		}
		return self::normalizeRoles($roles);
	}

	/**
	 * Normalizes and sorts role names and permission tokens.
	 *
	 * @param array<string, mixed> $roles Raw role map.
	 * @return array<string, array<int, string>> Sorted role permission map.
	 */
	private static function normalizeRoles(array $roles): array {
		$normalized=[];
		foreach($roles as $role=>$permissions){
			$role=PermissionRule::normalize((string)$role);
			if($role===''){
				continue;
			}
			$rules=self::normalizeRolePermissions($permissions);
			sort($rules, SORT_NATURAL);
			$normalized[$role]=$rules;
		}
		ksort($normalized, SORT_NATURAL);
		return $normalized;
	}

	/**
	 * Normalizes one role's permission list.
	 *
	 * @param mixed $permissions Raw role permission input.
	 * @return array<int, string> Unique normalized permission tokens.
	 */
	private static function normalizeRolePermissions(mixed $permissions): array {
		if(!is_array($permissions)){
			return PermissionRule::many($permissions);
		}
		$rules=[];
		foreach($permissions as $permission){
			if(!is_string($permission)){
				return PermissionRule::many($permissions);
			}
			$permission=PermissionRule::normalize($permission);
			if($permission!==''){
				$rules[]=$permission;
			}
		}
		return array_values(array_unique($rules));
	}

	/**
	 * Reduces repository assignment rows to manifest-safe fields.
	 *
	 * @param array<int, mixed> $assignments Raw repository assignments.
	 * @return array<int, array<string, mixed>> Sorted assignment rows with stable keys.
	 */
	private static function normalizeAssignments(array $assignments): array {
		$normalized=[];
		foreach($assignments as $assignment){
			if(!is_array($assignment)){
				continue;
			}
			$row=[];
			foreach(['subject_type', 'subject_id', 'scope', 'kind', 'value', 'negative'] as $key){
				$row[$key]=$assignment[$key] ?? null;
			}
			$normalized[]=$row;
		}
		usort($normalized, static fn(array $left, array $right): int => json_encode($left) <=> json_encode($right));
		return $normalized;
	}

	/**
	 * Extracts normalized permission tokens from catalog rows.
	 *
	 * @param array<int, mixed> $catalog Permission catalog rows.
	 * @return array<int, string> Unique sorted catalog permission tokens.
	 */
	private static function catalogPermissions(array $catalog): array {
		$permissions=[];
		foreach($catalog as $row){
			if(is_array($row) && isset($row['permission'])){
				$permission=PermissionRule::normalize((string)$row['permission']);
				if($permission!==''){
					$permissions[]=$permission;
				}
			}
		}
		$permissions=array_values(array_unique($permissions));
		sort($permissions, SORT_NATURAL);
		return $permissions;
	}

	/**
	 * Diffs map keys and changed map values.
	 *
	 * @param array<string, mixed> $left Baseline map.
	 * @param array<string, mixed> $right Candidate map.
	 * @return array{added: array<int, string>, removed: array<int, string>, changed: array<int, string>}
	 */
	private static function diffMap(array $left, array $right): array {
		return [
			'added'=>array_values(array_diff(array_keys($right), array_keys($left))),
			'removed'=>array_values(array_diff(array_keys($left), array_keys($right))),
			'changed'=>self::changedKeys($left, $right),
		];
	}

	/**
	 * Returns keys present in both maps whose values changed.
	 *
	 * @param array<string, mixed> $left Baseline map.
	 * @param array<string, mixed> $right Candidate map.
	 * @return array<int, string> Sorted changed keys.
	 */
	private static function changedKeys(array $left, array $right): array {
		$changed=[];
		foreach($left as $key=>$value){
			if(array_key_exists($key, $right) && $value!==$right[$key]){
				$changed[]=$key;
			}
		}
		sort($changed, SORT_NATURAL);
		return $changed;
	}

	/**
	 * Checks whether an array tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate tree.
	 * @return bool True when the tree contains only scalar, null, and array values.
	 */
	private static function isCacheableTree(array $values): bool {
		foreach($values as $value){
			if(is_array($value)){
				if(!self::isCacheableTree($value)){
					return false;
				}
				continue;
			}
			if($value!==null && !is_scalar($value)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Sorts manifest sections for deterministic output.
	 *
	 * @param array<string, mixed> $manifest Manifest data.
	 * @return array<string, mixed> Manifest with sorted list and map sections.
	 */
	private static function sortManifest(array $manifest): array {
		foreach(['catalog', 'assignments'] as $listKey){
			if(is_array($manifest[$listKey] ?? null)){
				usort($manifest[$listKey], static fn(array $left, array $right): int => json_encode($left) <=> json_encode($right));
			}
		}
		foreach(['roles', 'presets'] as $mapKey){
			if(is_array($manifest[$mapKey] ?? null)){
				ksort($manifest[$mapKey], SORT_NATURAL);
			}
		}
		return $manifest;
	}
}
