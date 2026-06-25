<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Converts panel permission names between Dataphyre and Shield-style formats.
 *
 * The namer centralizes panel permission prefixes, resource pluralization,
 * operation aliases, relation/action naming, and import/export conversion for
 * Laravel Shield-like permission strings while preserving negative rule prefixes.
 */
final class PermissionNamer {

	private const SHIELD_OPERATIONS=[
		'view_any'=>'view_any',
		'view'=>'view',
		'create'=>'create',
		'update'=>'update',
		'delete_any'=>'delete_any',
		'delete'=>'delete',
		'force_delete_any'=>'force_delete_any',
		'force_delete'=>'force_delete',
		'restore_any'=>'restore_any',
		'restore'=>'restore',
		'replicate'=>'duplicate',
		'reorder'=>'reorder',
		'export'=>'export',
		'import'=>'import',
	];

	/** @var array{permissions:array<int,string>|string,options:array<string,mixed>,result:array<int,string>}|null */
	private static ?array $lastFromShieldMany=null;

	/** @var array{permissions:array<int,string>|string,options:array<string,mixed>,result:array<int,string>}|null */
	private static ?array $lastToShieldMany=null;

	/**
	 * Builds a canonical Dataphyre panel permission name.
	 *
	 * Resource and operation values are normalized before joining with the
	 * configured permission/resource prefixes. Empty prefix segments are dropped,
	 * and operation aliases such as `store`, `index`, and `bulk_delete` are mapped
	 * to the canonical operation vocabulary used by Panel catalogs.
	 *
	 * @param string $resource Resource name or slug.
	 * @param string $operation Operation alias such as `view`, `store`, or `delete_any`.
	 * @param array<string, mixed> $options Naming options merged over panel permission config.
	 * @return string Dot-delimited permission name.
	 */
	public static function panel(string $resource, string $operation, array $options=[]): string {
		$options=array_replace(self::panelConfig(), $options);
		return self::panelWithOptions($resource, $operation, $options);
	}

	/**
	 * Builds a panel permission name using already-resolved naming options.
	 *
	 * @param string $resource Resource name or slug.
	 * @param string $operation Operation alias.
	 * @param array<string, mixed> $options Resolved naming options.
	 * @return string Dot-delimited permission name.
	 */
	private static function panelWithOptions(string $resource, string $operation, array $options): string {
		return self::join([
			$options['permission_prefix'] ?? 'panel',
			$options['resource_prefix'] ?? '',
			self::resource($resource, $options),
			self::operation($operation),
		]);
	}

	/**
	 * Builds a permission name for a custom panel action.
	 *
	 * The action name is normalized into the `action.<name>` operation namespace
	 * so action grants remain separate from CRUD, relation, and import/export
	 * operations.
	 *
	 * @param string $resource Resource name or slug.
	 * @param string $action Action name.
	 * @param array<string, mixed> $options Naming options.
	 * @return string Permission name with `action.<name>` operation segment.
	 */
	public static function panelAction(string $resource, string $action, array $options=[]): string {
		return self::panel($resource, 'action.'.PermissionRule::normalize($action), $options);
	}

	/**
	 * Builds a permission name for a relation operation on a panel resource.
	 *
	 * Relation permissions use the `relation.<relation>.<operation>` operation
	 * namespace. The relation name and operation alias are normalized before the
	 * final permission string is assembled.
	 *
	 * @param string $resource Parent resource name or slug.
	 * @param string $relation Relation name.
	 * @param string $operation Relation operation alias.
	 * @param array<string, mixed> $options Naming options.
	 * @return string Permission name with `relation.<relation>.<operation>` segment.
	 */
	public static function panelRelation(string $resource, string $relation, string $operation='view', array $options=[]): string {
		return self::panel($resource, 'relation.'.PermissionRule::normalize($relation).'.'.self::operation($operation), $options);
	}

	/**
	 * Converts one Shield-style permission into Dataphyre panel permission format.
	 *
	 * Supports both underscore and dot operation separators, applies configured
	 * operation aliases, and preserves a leading negative marker.
	 * Inputs that do not match a known Shield operation are returned as normalized
	 * permission strings rather than rejected, allowing mixed policy imports to
	 * pass through unchanged.
	 *
	 * @param string $permission Shield-style permission string.
	 * @param array<string, mixed> $options Naming and operation mapping options.
	 * @return string Dataphyre permission name, or normalized input when no Shield operation matches.
	 */
	public static function fromShield(string $permission, array $options=[]): string {
		$operations=self::shieldOperations($options);
		return self::fromShieldWithOperations($permission, $options, $operations, self::shieldOperationPrefixes($operations));
	}

	/**
	 * Converts one Shield-style permission using a precomputed operation map.
	 *
	 * @param string $permission Shield-style permission string.
	 * @param array<string, mixed> $options Naming options.
	 * @param array<string, string> $operations Precomputed Shield-to-Dataphyre operation map.
	 * @return string Dataphyre permission name, or normalized input when no Shield operation matches.
	 */
	private static function fromShieldWithOperations(string $permission, array $options, array $operations, ?array $operationPrefixes=null): string {
		$negative=str_starts_with(trim($permission), '-');
		$permission=PermissionRule::normalize($permission);
		$permission=ltrim($permission, '-');
		$resolvedOptions=array_replace(self::panelConfig(), $options);
		return self::fromShieldWithResolvedOptions($permission, $negative, $resolvedOptions, $operationPrefixes ?? self::shieldOperationPrefixes($operations));
	}

	/**
	 * Converts one normalized Shield-style permission using resolved options.
	 *
	 * @param string $permission Normalized positive Shield-style permission.
	 * @param bool $negative Whether to restore a leading negative marker.
	 * @param array<string, mixed> $options Resolved naming options.
	 * @param array<string, string> $operations Precomputed Shield-to-Dataphyre operation map.
	 * @return string Dataphyre permission name, or normalized input when no Shield operation matches.
	 */
	private static function fromShieldWithResolvedOptions(string $permission, bool $negative, array $options, array $operationPrefixes): string {
		$matchedOperation='';
		$matchedResource='';
		foreach($operationPrefixes as $entry){
			if(str_starts_with($permission, $entry['underscore'])){
				$matchedOperation=$entry['semantic'];
				$matchedResource=substr($permission, $entry['underscore_length']);
				break;
			}
			if(str_starts_with($permission, $entry['dot'])){
				$matchedOperation=$entry['semantic'];
				$matchedResource=substr($permission, $entry['dot_length']);
				break;
			}
		}
		if($matchedOperation==='' || $matchedResource===''){
			$semantic=$permission;
		}
		else{
			$semantic=self::panelWithOptions($matchedResource, $matchedOperation, $options);
		}
		return $negative ? '-'.$semantic : $semantic;
	}

	/**
	 * Converts multiple Shield-style permissions into Dataphyre format.
	 *
	 * Input is normalized through `PermissionRule::many()`, so nested arrays,
	 * newline-delimited strings, and negative rules follow the same behavior as
	 * authorization checks.
	 *
	 * @param array<int, string>|string $permissions Permission string or list accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $options Naming and operation mapping options.
	 * @return array<int, string> Unique Dataphyre permission names.
	 */
	public static function fromShieldMany(array|string $permissions, array $options=[]): array {
		if(
			self::$lastFromShieldMany!==null &&
			self::$lastFromShieldMany['permissions']===$permissions &&
			self::$lastFromShieldMany['options']===$options
		){
			return self::$lastFromShieldMany['result'];
		}
		$result=[];
		$operations=self::shieldOperations($options);
		$operationPrefixes=self::shieldOperationPrefixes($operations);
		$resolvedOptions=array_replace(self::panelConfig(), $options);
		foreach(PermissionRule::many($permissions) as $permission){
			$negative=str_starts_with(trim($permission), '-');
			$permission=ltrim(PermissionRule::normalize($permission), '-');
			$result[]=self::fromShieldWithResolvedOptions($permission, $negative, $resolvedOptions, $operationPrefixes);
		}
		$result=array_values(array_unique($result));
		self::$lastFromShieldMany=[
			'permissions'=>$permissions,
			'options'=>$options,
			'result'=>$result,
		];
		return $result;
	}

	/**
	 * Converts one Dataphyre panel permission into Shield-style format.
	 *
	 * Permissions outside the configured panel prefix are returned normalized and
	 * unchanged. Negative permission markers are preserved.
	 * Relation and action operation segments are flattened using underscores in
	 * the Shield output because Shield-style names do not preserve Dataphyre's
	 * dotted operation namespace.
	 *
	 * @param string $permission Dataphyre permission string.
	 * @param array<string, mixed> $options Naming and operation mapping options.
	 * @return string Shield-style permission string.
	 */
	public static function toShield(string $permission, array $options=[]): string {
		return self::toShieldWithReverseOperations($permission, $options, self::reverseShieldOperations($options));
	}

	/**
	 * Converts one Dataphyre panel permission using a precomputed reverse operation map.
	 *
	 * @param string $permission Dataphyre permission string.
	 * @param array<string, mixed> $options Naming options.
	 * @param array<string, string> $reverseOperations Dataphyre operation to Shield operation map.
	 * @return string Shield-style permission string.
	 */
	private static function toShieldWithReverseOperations(string $permission, array $options, array $reverseOperations): string {
		$negative=str_starts_with(trim($permission), '-');
		$permission=PermissionRule::normalize($permission);
		$permission=ltrim($permission, '-');
		$options=array_replace(self::panelConfig(), $options);
		return self::toShieldWithResolvedOptions($permission, $negative, $options, $reverseOperations);
	}

	/**
	 * Converts one normalized Dataphyre permission using resolved options.
	 *
	 * @param string $permission Normalized positive Dataphyre permission.
	 * @param bool $negative Whether to restore a leading negative marker.
	 * @param array<string, mixed> $options Resolved naming options.
	 * @param array<string, string> $reverseOperations Dataphyre operation to Shield operation map.
	 * @return string Shield-style permission string.
	 */
	private static function toShieldWithResolvedOptions(string $permission, bool $negative, array $options, array $reverseOperations): string {
		$prefix=PermissionRule::normalize((string)($options['permission_prefix'] ?? 'panel'));
		$resourcePrefix=PermissionRule::normalize((string)($options['resource_prefix'] ?? ''));
		$parts=explode('.', $permission);
		if(($parts[0] ?? '')!==$prefix){
			return ($negative ? '-' : '').$permission;
		}
		array_shift($parts);
		if($resourcePrefix!=='' && ($parts[0] ?? '')===$resourcePrefix){
			array_shift($parts);
		}
		if(count($parts)<2){
			return ($negative ? '-' : '').$permission;
		}
		$operation=array_pop($parts);
		$resource=implode('.', $parts);
		$operation=self::reverseShieldOperation($operation, $reverseOperations);
		return ($negative ? '-' : '').$operation.'_'.$resource;
	}

	/**
	 * Converts multiple Dataphyre permissions into Shield-style format.
	 *
	 * Duplicate converted names are removed after normalization so exports remain
	 * stable even when aliases or overlapping inputs collapse to the same Shield
	 * string.
	 *
	 * @param array<int, string>|string $permissions Permission string or list accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $options Naming and operation mapping options.
	 * @return array<int, string> Unique Shield-style permission names.
	 */
	public static function toShieldMany(array|string $permissions, array $options=[]): array {
		if(
			self::$lastToShieldMany!==null &&
			self::$lastToShieldMany['permissions']===$permissions &&
			self::$lastToShieldMany['options']===$options
		){
			return self::$lastToShieldMany['result'];
		}
		$result=[];
		$reverseOperations=self::reverseShieldOperations($options);
		$resolvedOptions=array_replace(self::panelConfig(), $options);
		foreach(PermissionRule::many($permissions) as $permission){
			$negative=str_starts_with(trim($permission), '-');
			$permission=ltrim(PermissionRule::normalize($permission), '-');
			$result[]=self::toShieldWithResolvedOptions($permission, $negative, $resolvedOptions, $reverseOperations);
		}
		$result=array_values(array_unique($result));
		self::$lastToShieldMany=[
			'permissions'=>$permissions,
			'options'=>$options,
			'result'=>$result,
		];
		return $result;
	}

	/**
	 * Normalizes panel operation aliases to canonical operation segments.
	 *
	 * @param string $operation Raw operation name.
	 * @return string Canonical operation segment.
	 */
	private static function operation(string $operation): string {
		$operation=PermissionRule::normalize($operation);
		return match ($operation) {
			'viewany', 'view_any', 'index', 'list' => 'view_any',
			'store' => 'create',
			'edit' => 'update',
			'destroy' => 'delete',
			'delete_any', 'bulk_delete' => 'delete_any',
			'force_delete_any', 'bulk_force_delete' => 'force_delete_any',
			'force_delete' => 'force_delete',
			'restore_any', 'bulk_restore' => 'restore_any',
			'replicate' => 'duplicate',
			default => $operation,
		};
	}

	/**
	 * Normalizes and optionally pluralizes resource names for permission segments.
	 *
	 * @param string $resource Raw resource name.
	 * @param array<string, mixed> $options Naming options.
	 * @return string Canonical resource segment.
	 */
	private static function resource(string $resource, array $options): string {
		$resource=PermissionRule::normalize($resource);
		if(($options['pluralize'] ?? true)!==false && !str_ends_with($resource, 's')){
			$resource.='s';
		}
		if(str_starts_with($resource, 'permission_')){
			return 'permission.'.substr($resource, strlen('permission_'));
		}
		return $resource;
	}

	/**
	 * Returns Shield-to-Dataphyre operation mappings sorted by longest Shield key first.
	 *
	 * @param array<string, mixed> $options Mapping override options.
	 * @return array<string, string> Shield operation to Dataphyre operation map.
	 */
	private static function shieldOperations(array $options): array {
		$operations=self::SHIELD_OPERATIONS;
		if(is_array($options['shield_operations'] ?? null)){
			foreach($options['shield_operations'] as $shield=>$semantic){
				$operations[PermissionRule::normalize((string)$shield)]=self::operation((string)$semantic);
			}
		}
		uksort($operations, static fn(string $left, string $right): int => strlen($right)<=>strlen($left));
		return $operations;
	}

	/**
	 * Builds reusable Shield operation prefix metadata for conversion scans.
	 *
	 * @param array<string, string> $operations Shield operation to Dataphyre operation map.
	 * @return array<int, array{underscore: string, underscore_length: int, dot: string, dot_length: int, semantic: string}>
	 */
	private static function shieldOperationPrefixes(array $operations): array {
		$prefixes=[];
		foreach($operations as $shieldOperation=>$semanticOperation){
			$underscore=str_replace('.', '_', $shieldOperation).'_';
			$dot=$shieldOperation.'.';
			$prefixes[]=[
				'underscore'=>$underscore,
				'underscore_length'=>strlen($underscore),
				'dot'=>$dot,
				'dot_length'=>strlen($dot),
				'semantic'=>$semanticOperation,
			];
		}
		return $prefixes;
	}

	/**
	 * Finds the Shield operation name for a canonical Dataphyre operation.
	 *
	 * @param string $operation Canonical operation segment.
	 * @param array<string, mixed> $options Mapping override options.
	 * @return string Shield-style operation segment.
	 */
	private static function reverseShieldOperation(string $operation, array $reverseOperations): string {
		return $reverseOperations[$operation] ?? str_replace('.', '_', $operation);
	}

	/**
	 * Returns a Dataphyre operation to Shield operation map.
	 *
	 * @param array<string, mixed> $options Mapping override options.
	 * @return array<string, string> Dataphyre operation to Shield operation map.
	 */
	private static function reverseShieldOperations(array $options): array {
		$reverse=[];
		foreach(self::shieldOperations($options) as $shield=>$semantic){
			if(!isset($reverse[$semantic])){
				$reverse[$semantic]=str_replace('.', '_', $shield);
			}
		}
		return $reverse;
	}

	/**
	 * Joins normalized permission parts while dropping empty segments.
	 *
	 * @param array<int, mixed> $parts Permission path segments.
	 * @return string Dot-delimited permission name.
	 */
	private static function join(array $parts): string {
		$normalized=[];
		foreach($parts as $part){
			$part=trim(PermissionRule::normalize((string)$part), '.');
			if($part!==''){
				$normalized[]=$part;
			}
		}
		return implode('.', $normalized);
	}

	/**
	 * Reads panel naming defaults from `DP_PERMISSION_CFG`.
	 *
	 * @return array<string, mixed> Panel permission naming config.
	 */
	private static function panelConfig(): array {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		return is_array($config['panel'] ?? null) ? $config['panel'] : [];
	}
}
