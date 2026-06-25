<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * SQL-backed repository for permission assignments, role definitions, and Panel permission administration.
 *
 * The repository stores direct permission grants/denials and role assignments per subject and scope, expands role
 * definitions from storage, and exposes Panel save/delete helpers that invalidate Permission's runtime cache after
 * mutations. It is intentionally tolerant of unavailable SQL helpers or invalid table configuration, returning false or
 * empty results so authorization callers can fail closed at the policy layer.
 */
final class PermissionRepository {

	private static ?self $instance=null;
	private ?array $roleCache=null;

	/**
	 * Returns the process-local repository singleton.
	 *
	 * @return self Repository instance with any cached role definitions.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the process-local repository singleton and its role cache.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Grants one normalized permission to a subject within the resolved scope.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param string $permission Permission rule to grant.
	 * @param array<string, mixed> $context Subject, scope, tenant, and created_by metadata.
	 * @return bool True when the assignment row is inserted and permission caches are flushed.
	 */
	public function assignPermission(mixed $subject, string $permission, array $context=[]): bool {
		return $this->assign($subject, 'permission', $permission, false, $context);
	}

	/**
	 * Stores one normalized negative permission assignment for a subject.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param string $permission Permission rule to deny.
	 * @param array<string, mixed> $context Subject, scope, tenant, and created_by metadata.
	 * @return bool True when the denial row is inserted and permission caches are flushed.
	 */
	public function denyPermission(mixed $subject, string $permission, array $context=[]): bool {
		return $this->assign($subject, 'permission', $permission, true, $context);
	}

	/**
	 * Assigns a normalized role name to a subject within the resolved scope.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param string $role Role name to assign.
	 * @param array<string, mixed> $context Subject, scope, tenant, and created_by metadata.
	 * @return bool True when the role assignment row is inserted and permission caches are flushed.
	 */
	public function assignRole(mixed $subject, string $role, array $context=[]): bool {
		return $this->assign($subject, 'role', $role, false, $context);
	}

	/**
	 * Deletes an assignment matching subject, scope, kind, and value.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param string $kind Assignment kind, normalized to permission or role.
	 * @param string $value Permission or role value to revoke.
	 * @param array<string, mixed> $context Subject and scope metadata.
	 * @return bool True when the delete query can be issued successfully.
	 */
	public function revoke(mixed $subject, string $kind, string $value, array $context=[]): bool {
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		[$subjectType, $subjectId]=$this->subjectKey($subject, $context);
		$value=PermissionRule::normalize($value);
		$kind=$this->kind($kind);
		if($table===null || $subjectId===null || $value==='' || function_exists('sql_delete')===false){
			return false;
		}
		$scope=$this->scope($context);
		return sql_delete($table, 'WHERE subject_type=? AND subject_id=? AND scope=? AND kind=? AND value=?', [$subjectType, (string)$subjectId, $scope, $kind, $value], true)!==false;
	}

	/**
	 * Creates or replaces a role and its permission rules.
	 *
	 * Role metadata is saved in the roles table, existing permission rows for the role are removed, and the supplied
	 * permissions are reinserted as normalized positive or negative rules. The in-memory role cache is cleared after a
	 * successful definition update.
	 *
	 * @param string $role Role name.
	 * @param array<int|string, mixed>|string $permissions Permission rules accepted by PermissionRule::many().
	 * @param array<string, mixed> $metadata Label, description, system flag, and caller-defined metadata.
	 * @return bool True when the role row and permission rows are saved.
	 */
	public function defineRole(string $role, array|string $permissions, array $metadata=[]): bool {
		$role=PermissionRule::normalize($role);
		if($role==='' || function_exists('sql_insert')===false){
			return false;
		}
		$rolesTable=$this->table('roles_table', 'dataphyre.permission_roles');
		$rolePermissionsTable=$this->table('role_permissions_table', 'dataphyre.permission_role_permissions');
		if($rolesTable===null || $rolePermissionsTable===null){
			return false;
		}
		$label=trim((string)($metadata['label'] ?? ucfirst(str_replace(['.', '_', '-'], ' ', $role))));
		$description=isset($metadata['description']) ? trim((string)$metadata['description']) : null;
		$system=(bool)($metadata['system'] ?? false);
		$roleFields=[
			'name'=>$role,
			'label'=>$label,
			'description'=>$description,
			'metadata_json'=>json_encode($metadata, JSON_UNESCAPED_SLASHES),
			'system'=>$system,
			'updated_at'=>date('Y-m-d H:i:s'),
		];
		$roleSaved=sql_insert($rolesTable, $roleFields, null, true);
		if($roleSaved===false && function_exists('sql_update')){
			$updateFields=$roleFields;
			unset($updateFields['name']);
			$roleSaved=sql_update($rolesTable, $updateFields, 'WHERE name=?', [$role], true);
		}
		if($roleSaved===false){
			return false;
		}
		if(function_exists('sql_delete')){
			sql_delete($rolePermissionsTable, 'WHERE role=?', [$role], true);
		}
		foreach(PermissionRule::many($permissions) as $permission){
			$rule=PermissionRule::unwrap($permission);
			$value=$rule['permission'];
			if($value===''){
				continue;
			}
			sql_insert($rolePermissionsTable, [
				'id'=>$this->id('dprp'),
				'role'=>$role,
				'permission'=>$value,
				'negative'=>(bool)$rule['negative'],
			], null, true);
		}
		$this->roleCache=null;
		return true;
	}

	/**
	 * Returns direct permission assignments for a subject across global and scoped rows.
	 *
	 * Negative assignments are returned with a leading dash so policy expansion can preserve deny semantics.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param array<string, mixed> $context Subject and scope metadata.
	 * @return list<string> Unique permission rules assigned directly to the subject.
	 */
	public function permissionsFor(mixed $subject, array $context=[]): array {
		$rows=$this->assignmentsFor($subject, $context);
		$permissions=[];
		foreach($rows as $row){
			if(($row['kind'] ?? '')!=='permission'){
				continue;
			}
			$value=PermissionRule::normalize((string)($row['value'] ?? ''));
			if($value!==''){
				$permissions[]=$this->truthy($row['negative'] ?? false) ? '-'.$value : $value;
			}
		}
		return array_values(array_unique($permissions));
	}

	/**
	 * Returns role assignments for a subject across global and scoped rows.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param array<string, mixed> $context Subject and scope metadata.
	 * @return list<string> Unique normalized role names assigned to the subject.
	 */
	public function rolesFor(mixed $subject, array $context=[]): array {
		$rows=$this->assignmentsFor($subject, $context);
		$roles=[];
		foreach($rows as $row){
			if(($row['kind'] ?? '')!=='role'){
				continue;
			}
			$value=PermissionRule::normalize((string)($row['value'] ?? ''));
			if($value!==''){
				$roles[]=$value;
			}
		}
		return array_values(array_unique($roles));
	}

	/**
	 * Loads role-to-permission mappings from storage and caches them for this repository instance.
	 *
	 * @return array<string, list<string>> Permission rules keyed by normalized role name.
	 */
	public function roleDefinitions(): array {
		if($this->roleCache!==null){
			return $this->roleCache;
		}
		$table=$this->table('role_permissions_table', 'dataphyre.permission_role_permissions');
		if($table===null || function_exists('sql_select')===false){
			return $this->roleCache=[];
		}
		$rows=sql_select('*', $table, null, null, true, false);
		if(!is_array($rows)){
			return $this->roleCache=[];
		}
		$roles=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$role=PermissionRule::normalize((string)($row['role'] ?? ''));
			$permission=PermissionRule::normalize((string)($row['permission'] ?? ''));
			if($role==='' || $permission===''){
				continue;
			}
			$roles[$role][]=$this->truthy($row['negative'] ?? false) ? '-'.$permission : $permission;
		}
		return $this->roleCache=array_map(static fn(array $rules): array => array_values(array_unique($rules)), $roles);
	}

	/**
	 * Returns role records annotated with newline-separated permissions for Panel table editing.
	 *
	 * @return list<array<string, mixed>> Role rows with a permissions field added.
	 */
	public function rolesWithPermissions(): array {
		$rolesTable=$this->table('roles_table', 'dataphyre.permission_roles');
		if($rolesTable===null || function_exists('sql_select')===false){
			return [];
		}
		$rows=sql_select('*', $rolesTable, null, null, true, false);
		if(!is_array($rows)){
			return [];
		}
		$definitions=$this->roleDefinitions();
		$result=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$name=PermissionRule::normalize((string)($row['name'] ?? ''));
			if($name===''){
				continue;
			}
			$row['permissions']=implode("\n", $definitions[$name] ?? []);
			$result[]=$row;
		}
		return $result;
	}

	/**
	 * Lists assignment rows, optionally filtered to one resolved scope.
	 *
	 * @param array<string, mixed> $context Optional scope, tenant, or tenant_id filter.
	 * @return list<array<string, mixed>> Assignment rows from storage.
	 */
	public function assignments(array $context=[]): array {
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		if($table===null || function_exists('sql_select')===false){
			return [];
		}
		$scope=$context['scope'] ?? $context['tenant'] ?? $context['tenant_id'] ?? null;
		if($scope!==null && trim((string)$scope)!==''){
			$rows=sql_select('*', $table, 'WHERE scope=?', [$this->scope($context)], true, false);
		}
		else{
			$rows=sql_select('*', $table, null, null, true, false);
		}
		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}

	/**
	 * Persists a role edit submitted from the Panel permission resource.
	 *
	 * Renaming deletes the previous role first, then saves the new role definition and flushes permission caches.
	 *
	 * @param array<string, mixed> $data Panel form data.
	 * @param mixed $record Existing role row when editing.
	 * @return array{saved: bool, message: string} Panel action result.
	 */
	public function saveRoleFromPanel(array $data, mixed $record=null): array {
		$oldRole=is_array($record) ? PermissionRule::normalize((string)($record['name'] ?? '')) : '';
		$role=PermissionRule::normalize((string)($data['name'] ?? $oldRole));
		if($role===''){
			return ['saved'=>false, 'message'=>'Role name is required.'];
		}
		$permissions=PermissionRule::many((string)($data['permissions'] ?? ''));
		$metadata=[
			'label'=>trim((string)($data['label'] ?? '')),
			'description'=>trim((string)($data['description'] ?? '')),
			'system'=>$this->truthy($data['system'] ?? false),
		];
		if($oldRole!=='' && $oldRole!==$role){
			$this->deleteRole($oldRole);
		}
		$saved=$this->defineRole($role, $permissions, $metadata);
		Permission::flush();
		return [
			'saved'=>$saved,
			'message'=>$saved ? 'Role saved.' : 'Role could not be saved.',
		];
	}

	/**
	 * Inserts or updates one permission assignment from Panel form data.
	 *
	 * Required subject and value fields are validated before storage. Existing rows are updated by id, while new rows
	 * receive a generated assignment id.
	 *
	 * @param array<string, mixed> $data Panel form data.
	 * @param mixed $record Existing assignment row when editing.
	 * @return array{saved: bool, message: string} Panel action result.
	 */
	public function saveAssignmentFromPanel(array $data, mixed $record=null): array {
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		if($table===null || function_exists('sql_insert')===false){
			return ['saved'=>false, 'message'=>'Permission storage is unavailable.'];
		}
		$id=is_array($record) ? (string)($record['id'] ?? '') : '';
		$id=$id!=='' ? $id : (string)($data['id'] ?? '');
		$fields=[
			'id'=>$id!=='' ? $id : $this->id('dppa'),
			'subject_type'=>PermissionRule::normalize((string)($data['subject_type'] ?? 'user')) ?: 'user',
			'subject_id'=>trim((string)($data['subject_id'] ?? '')),
			'scope'=>$this->scope(['scope'=>$data['scope'] ?? 'global']),
			'kind'=>$this->kind((string)($data['kind'] ?? 'permission')),
			'value'=>PermissionRule::normalize((string)($data['value'] ?? '')),
			'negative'=>$this->truthy($data['negative'] ?? false),
			'created_by'=>isset($data['created_by']) ? trim((string)$data['created_by']) : null,
		];
		if($fields['subject_id']==='' || $fields['value']===''){
			return ['saved'=>false, 'message'=>'Subject and value are required.'];
		}
		$saved=false;
		if(is_array($record) && ($record['id'] ?? '')!=='' && function_exists('sql_update')){
			$update=$fields;
			unset($update['id']);
			$saved=sql_update($table, $update, 'WHERE id=?', [$fields['id']], true)!==false;
		}
		else{
			$saved=sql_insert($table, $fields, null, true)!==false;
		}
		Permission::flush();
		return [
			'saved'=>$saved,
			'message'=>$saved ? 'Assignment saved.' : 'Assignment could not be saved.',
		];
	}

	/**
	 * Deletes a role and its permission rows.
	 *
	 * @param string|array<string, mixed> $role Role name or role row containing name.
	 * @return bool True when the role delete query succeeds.
	 */
	public function deleteRole(string|array $role): bool {
		$role=is_array($role) ? (string)($role['name'] ?? '') : $role;
		$role=PermissionRule::normalize($role);
		$rolesTable=$this->table('roles_table', 'dataphyre.permission_roles');
		$rolePermissionsTable=$this->table('role_permissions_table', 'dataphyre.permission_role_permissions');
		if($role==='' || $rolesTable===null || $rolePermissionsTable===null || function_exists('sql_delete')===false){
			return false;
		}
		sql_delete($rolePermissionsTable, 'WHERE role=?', [$role], true);
		$deleted=sql_delete($rolesTable, 'WHERE name=?', [$role], true)!==false;
		Permission::flush();
		return $deleted;
	}

	/**
	 * Deletes one assignment by id.
	 *
	 * @param string|array<string, mixed> $assignment Assignment id or assignment row containing id.
	 * @return bool True when the assignment delete query succeeds.
	 */
	public function deleteAssignment(string|array $assignment): bool {
		$id=is_array($assignment) ? (string)($assignment['id'] ?? '') : $assignment;
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		if($id==='' || $table===null || function_exists('sql_delete')===false){
			return false;
		}
		$deleted=sql_delete($table, 'WHERE id=?', [$id], true)!==false;
		Permission::flush();
		return $deleted;
	}

	/**
	 * Inserts one permission or role assignment row.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param string $kind Assignment kind.
	 * @param string $value Permission or role value.
	 * @param bool $negative Whether this assignment represents a denial.
	 * @param array<string, mixed> $context Subject, scope, and created_by metadata.
	 * @return bool True when storage succeeds and permission caches are flushed.
	 */
	private function assign(mixed $subject, string $kind, string $value, bool $negative, array $context): bool {
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		[$subjectType, $subjectId]=$this->subjectKey($subject, $context);
		$value=PermissionRule::normalize($value);
		$kind=$this->kind($kind);
		if($table===null || $subjectId===null || $value==='' || function_exists('sql_insert')===false){
			return false;
		}
		$result=sql_insert($table, [
			'id'=>$this->id('dppa'),
			'subject_type'=>$subjectType,
			'subject_id'=>(string)$subjectId,
			'scope'=>$this->scope($context),
			'kind'=>$kind,
			'value'=>$value,
			'negative'=>$negative,
			'created_by'=>isset($context['created_by']) ? (string)$context['created_by'] : null,
		], null, true);
		if($result!==false){
			Permission::flush();
			return true;
		}
		return false;
	}

	/**
	 * Loads assignment rows for a subject from both global and resolved scopes.
	 *
	 * @param mixed $subject Subject object, scalar id, or context-resolved subject.
	 * @param array<string, mixed> $context Subject and scope metadata.
	 * @return list<array<string, mixed>> Assignment rows relevant to the subject.
	 */
	private function assignmentsFor(mixed $subject, array $context): array {
		$table=$this->table('assignments_table', 'dataphyre.permission_assignments');
		[$subjectType, $subjectId]=$this->subjectKey($subject, $context);
		if($table===null || $subjectId===null || function_exists('sql_select')===false){
			return [];
		}
		$scope=$this->scope($context);
		$rows=sql_select('*', $table, 'WHERE subject_type=? AND subject_id=? AND scope IN (?, ?)', [$subjectType, (string)$subjectId, 'global', $scope], true, false);
		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}

	/**
	 * Resolves the storage subject type and identifier.
	 *
	 * Context subject_id wins over SubjectResolver output so Panel tools and service calls can assign permissions to ids
	 * without constructing subject objects.
	 *
	 * @param mixed $subject Subject object or scalar.
	 * @param array<string, mixed> $context Subject metadata.
	 * @return array{0: string, 1: ?string} Subject type and nullable subject id.
	 */
	private function subjectKey(mixed $subject, array $context): array {
		$subjectType=PermissionRule::normalize((string)($context['subject_type'] ?? 'user')) ?: 'user';
		$subjectId=$context['subject_id'] ?? SubjectResolver::id($subject);
		if($subjectId===null || $subjectId===false || $subjectId===''){
			return [$subjectType, null];
		}
		return [$subjectType, (string)$subjectId];
	}

	/**
	 * Resolves the permission scope from scope or tenant context.
	 *
	 * @param array<string, mixed> $context Scope, tenant, or tenant_id metadata.
	 * @return string Normalized scope, defaulting to global.
	 */
	private function scope(array $context): string {
		$scope=PermissionRule::normalize((string)($context['scope'] ?? $context['tenant'] ?? $context['tenant_id'] ?? 'global'));
		return $scope!=='' ? $scope : 'global';
	}

	/**
	 * Normalizes assignment kind to the supported storage values.
	 *
	 * @param string $kind Candidate assignment kind.
	 * @return string permission or role.
	 */
	private function kind(string $kind): string {
		$kind=PermissionRule::normalize($kind);
		return in_array($kind, ['permission', 'role'], true) ? $kind : 'permission';
	}

	/**
	 * Resolves and validates a configured SQL table name.
	 *
	 * Table names are limited to simple identifiers with an optional schema prefix because they are interpolated into SQL
	 * helper calls instead of bound as parameters.
	 *
	 * @param string $key Permission storage config key.
	 * @param string $default Default table name.
	 * @return ?string Safe SQL table identifier, or null when configuration is invalid.
	 */
	private function table(string $key, string $default): ?string {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$storage=is_array($config['storage'] ?? null) ? $config['storage'] : [];
		$table=trim((string)($storage[$key] ?? $default));
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)===1 ? $table : null;
	}

	/**
	 * Generates a random storage identifier with a stable table-specific prefix.
	 *
	 * @param string $prefix Identifier prefix.
	 * @return string Random hexadecimal identifier.
	 */
	private function id(string $prefix): string {
		return $prefix.'_'.bin2hex(random_bytes(16));
	}

	/**
	 * Interprets common persisted truthy values from SQL rows and Panel forms.
	 *
	 * @param mixed $value Candidate boolean value.
	 * @return bool True for strict true, numeric/string one, true, t, or yes.
	 */
	private function truthy(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true', 't', 'yes'], true);
	}
}
