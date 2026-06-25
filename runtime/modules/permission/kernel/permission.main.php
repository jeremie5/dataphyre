<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Module initialization");

if(function_exists('dp_define_module_config')){
	dp_define_module_config('permission', 'DP_PERMISSION_CFG', [
		'default_roles'=>['default'],
		'roles'=>[],
		'aliases'=>[],
		'conditions'=>[],
		'super_permissions'=>['*'],
		'subject'=>[
			'id_resolver'=>null,
			'user_resolver'=>null,
			'permission_resolver'=>null,
			'role_resolver'=>null,
			'permission_keys'=>['permissions', 'permission', 'abilities'],
			'role_keys'=>['roles', 'role', 'groups', 'group'],
		],
		'storage'=>[
			'assignments_table'=>'dataphyre.permission_assignments',
			'roles_table'=>'dataphyre.permission_roles',
			'role_permissions_table'=>'dataphyre.permission_role_permissions',
			'auto_hydrate'=>true,
		],
		'cache'=>[
			'enabled'=>true,
			'max_subjects'=>512,
		],
		'trace'=>[
			'enabled'=>false,
			'max_entries'=>256,
			'include_context'=>false,
		],
		'panel'=>[
			'permission_prefix'=>'panel',
			'resource_prefix'=>'',
			'allow_guest_pages'=>[],
			'guest_permissions'=>[],
			'super_permission'=>'panel.*',
			'operation_aliases'=>[
				'index'=>'view_any',
				'store'=>'create',
				'inline_update'=>'update',
				'bulk_update'=>'update_any',
				'bulk_delete'=>'delete_any',
				'bulk_export'=>'export_any',
			],
		],
	]);
}

if(defined('RUN_MODE') && RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/permission.diagnostic.php');
}

if(function_exists('sql_define_table')){
	$storage=is_array(DP_PERMISSION_CFG['storage'] ?? null) ? DP_PERMISSION_CFG['storage'] : [];
	sql_define_table((string)($storage['assignments_table'] ?? 'dataphyre.permission_assignments'), __DIR__.'/permission.tables.php', 'assignments');
	sql_define_table((string)($storage['roles_table'] ?? 'dataphyre.permission_roles'), __DIR__.'/permission.tables.php', 'roles');
	sql_define_table((string)($storage['role_permissions_table'] ?? 'dataphyre.permission_role_permissions'), __DIR__.'/permission.tables.php', 'role_permissions');
}

/**
 * Kernel bridge for Dataphyre Permission authorization.
 *
 * The bridge loads the Framework permission engine on demand and exposes snake_case-compatible authorization, tracing, condition, and catalog helpers to legacy runtime callers.
 */
final class permission {

	public static function __callStatic(string $name, array $arguments): mixed {
		$snake=strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
		if(method_exists(self::class, $snake)){
			return self::$snake(...$arguments);
		}
		self::framework();
		if(method_exists(\Dataphyre\Permission\Permission::class, $name)){
			return \Dataphyre\Permission\Permission::$name(...$arguments);
		}
		throw new \BadMethodCallException('Permission method does not exist: '.$name);
	}

	/**
	 * Ensures the Framework permission API is available to kernel callers.
	 *
	 * Bootstrap.php is preferred when present because it owns framework class
	 * loading. The fallback list preserves legacy deployments that load this kernel
	 * file without the framework bootstrap. Repeated calls are safe and do not run
	 * authorization checks.
	 *
	 * @return void
	 */
	private static function framework(): void {
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return;
		}
		$bootstrap=dirname(__DIR__).'/Framework/Bootstrap.php';
		if(is_file($bootstrap)){
			require_once($bootstrap);
		}
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return;
		}
		foreach([
			'PermissionRule.php',
			'PermissionRepository.php',
			'PermissionCatalog.php',
			'PermissionAudit.php',
			'PermissionManifest.php',
			'PermissionNamer.php',
			'PermissionCondition.php',
			'PermissionTrace.php',
			'PermissionTest.php',
			'PermissionSimulator.php',
			'PermissionSnapshot.php',
			'PermissionOptimizer.php',
			'Exceptions/AuthorizationException.php',
			'Middleware/AuthorizeWhen.php',
			'Middleware/AuthorizeAnyWhen.php',
			'PermissionSet.php',
			'SubjectResolver.php',
			'PermissionEngine.php',
			'PermissionSubject.php',
			'Permission.php',
		] as $file){
			$path=dirname(__DIR__).'/Framework/'.$file;
			if(is_file($path)){
				require_once($path);
			}
		}
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context such as resource, tenant, owner, request, guard, or policy-specific condition data.
	 * @return bool True when every required permission is allowed for the subject and context.
	 */
	public static function check(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::check($required_permission, $subject, $context);
	}

	/**
	 * Controls Permission trace collection and reporting.
	 *
	 * Trace collection records later authorization decisions for diagnostics without changing allow or deny outcomes.
	 *
	 * @param bool $enabled Whether subsequent checks should be recorded.
	 * @return void
	 */
	public static function trace(bool $enabled=true): void {
		self::framework();
		\Dataphyre\Permission\Permission::trace($enabled);
	}

	/**
	 * Controls Permission trace collection and reporting.
	 *
	 * Entries come from the Framework trace buffer and remain there until flushed.
	 * @return array Trace entries collected in the current process.
	 */
	public static function traces(): array {
		self::framework();
		return \Dataphyre\Permission\Permission::traces();
	}

	/**
	 * Controls Permission trace collection and reporting.
	 *
	 * Stats summarize the current Framework trace buffer without mutating it.
	 * @return array Trace counters grouped by the Framework trace helper.
	 */
	public static function trace_stats(): array {
		self::framework();
		return \Dataphyre\Permission\Permission::traceStats();
	}

	/**
	 * Controls Permission trace collection and reporting.
	 *
	 * The summary groups recorded decisions and condition checks for diagnostics.
	 * @return array<string,mixed> Trace summary grouped by recorded decisions and condition checks.
	 */
	public static function trace_summary(): array {
		self::framework();
		return \Dataphyre\Permission\Permission::traceSummary();
	}

	/**
	 * Controls Permission trace collection and reporting.
	 *
	 * This clears only the trace buffer; permission configuration and persisted assignments remain untouched.
	 * @return void
	 */
	public static function flush_trace(): void {
		self::framework();
		\Dataphyre\Permission\Permission::flushTrace();
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context such as resource, tenant, owner, request, guard, or policy-specific condition data.
	 * @return bool True when every required permission is allowed; denial raises the Framework authorization exception.
	 */
	public static function ensure(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::ensure($required_permission, $subject, $context);
	}

	/**
	 * Defines or evaluates conditional Permission rules.
	 *
	 * The predicate is registered in the Framework condition registry for use by kernel and Framework conditional checks.
	 *
	 * @param string $name Condition name referenced by later checks.
	 * @param callable $condition Predicate invoked with subject, context, and permission data.
	 * @return void
	 */
	public static function define_condition(string $name, callable $condition): void {
		self::framework();
		\Dataphyre\Permission\Permission::defineCondition($name, $condition);
	}

	/**
	 * Defines or evaluates conditional Permission rules.
	 *
	 * The returned list reflects the in-process Framework condition registry.
	 * @return array Registered condition names.
	 */
	public static function conditions(): array {
		self::framework();
		return \Dataphyre\Permission\Permission::conditions();
	}

	/**
	 * Checks permissions and named conditions together.
	 *
	 * The Framework API first evaluates the permission set, then runs condition predicates with the same subject and context.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context supplied to named condition predicates.
	 * @return bool True when the permission set and named conditions all pass.
	 */
	public static function check_when(mixed $required_permission, array|string $conditions, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::checkWhen($required_permission, $conditions, $subject, $context);
	}

	/**
	 * Ensures permissions and named conditions pass.
	 *
	 * Denial raises the Framework authorization exception with normalized permissions, context, and condition metadata.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context supplied to named condition predicates.
	 * @return bool True when permission and condition checks pass; denial raises the Framework authorization exception.
	 */
	public static function ensure_when(mixed $required_permission, array|string $conditions, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::ensureWhen($required_permission, $conditions, $subject, $context);
	}

	/**
	 * Explains a conditional permission check.
	 *
	 * The explanation includes the base permission result, per-permission condition explanations, and the aggregate allowed flag.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context supplied to named condition predicates and returned explanation rows.
	 * @return array{allowed:bool, base:array<string,mixed>, conditions:array<int,array<string,mixed>>} Conditional authorization explanation.
	 */
	public static function explain_when(mixed $required_permission, array|string $conditions, mixed $subject=null, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::explainWhen($required_permission, $conditions, $subject, $context);
	}

	/**
	 * Checks permissions for a subject-scoped helper.
	 *
	 * Legacy subject-first callers are delegated to `Permission::for($subject)->can()`.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $required_permission Required permission or permission list.
	 * @param array<string,mixed> $context Authorization context passed to subject-scoped permission checks.
	 * @return bool True when the subject-scoped permission check passes.
	 */
	public static function can(mixed $subject, mixed $required_permission, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::for($subject)->can($required_permission, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used when evaluating any matching permission.
	 * @return bool True when at least one required permission is allowed.
	 */
	public static function any(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::any($required_permission, $subject, $context);
	}

	/**
	 * Returns detailed decisions for each requested permission.
	 *
	 * Permissions are normalized by the Framework engine and evaluated with the same subject and context.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context attached to each permission decision.
	 * @return array<string,bool> Decision map keyed by normalized permission string.
	 */
	public static function decisions(mixed $permissions, mixed $subject=null, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::decisions($permissions, $subject, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used while building the allowed-permission map.
	 * @return array<string,bool> Allow/deny map for each normalized permission string.
	 */
	public static function allows_many(mixed $permissions, mixed $subject=null, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::allowsMany($permissions, $subject, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used while filtering allowed permissions.
	 * @return list<string> Normalized permissions that are allowed for the subject and context.
	 */
	public static function filter_allowed(mixed $permissions, mixed $subject=null, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::filterAllowed($permissions, $subject, $context);
	}

	/**
	 * Ensures at least one permission is allowed.
	 *
	 * Denial raises the Framework authorization exception using the normalized required permission list and supplied context.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used when ensuring at least one permission is allowed.
	 * @return bool True when at least one permission is allowed; denial raises the Framework authorization exception.
	 */
	public static function ensure_any(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::ensureAny($required_permission, $subject, $context);
	}

	/**
	 * Explains why a permission set is allowed or denied.
	 *
	 * The returned Framework explanation can include normalized permissions, subject facts, roles, aliases, super-permission matches, and denial reasons.
	 *
	 * @param mixed $required_permission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context included in the returned explanation.
	 * @return array{allowed:bool, subject_id:mixed, checks:array<int,array<string,mixed>>, roles:array<int,string>, permissions:array<int,string>} Authorization explanation.
	 */
	public static function explain(mixed $required_permission, mixed $subject=null, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::explain($required_permission, $subject, $context);
	}

	/**
	 * Defines an in-process role on the Framework engine.
	 *
	 * This affects subsequent checks in the current process; use `store_role()` when the role should be persisted.
	 *
	 * @param string $role Role name to define.
	 * @param array|string $permissions Permission names or rules granted by the role.
	 * @return void
	 */
	public static function define_role(string $role, array|string $permissions): void {
		self::framework();
		\Dataphyre\Permission\Permission::defineRole($role, $permissions);
	}

	/**
	 * Persists a role definition through the Framework repository.
	 *
	 * Successful writes flush cached Framework permission state so later kernel calls reload the persisted role.
	 *
	 * @param string $role Role name to persist.
	 * @param array|string $permissions Permission names or rules granted by the role.
	 * @param array<string,mixed> $metadata Role metadata persisted beside the permission list.
	 * @return bool True when the role definition is persisted and cached permission state is flushed.
	 */
	public static function store_role(string $role, array|string $permissions, array $metadata=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::storeRole($role, $permissions, $metadata);
	}

	/**
	 * Persists an allow assignment for one subject.
	 *
	 * Assignment context can carry actor, tenant, source, expiry, or audit metadata for repository-backed storage.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $permission Permission string to allow.
	 * @param array<string,mixed> $context Assignment context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the allow assignment is persisted for the subject.
	 */
	public static function assign_permission(mixed $subject, string $permission, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::assignPermission($subject, $permission, $context);
	}

	/**
	 * Persists a denial assignment for one subject.
	 *
	 * Denials are stored through the Framework repository and can override matching grants according to engine policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $permission Permission string to deny.
	 * @param array<string,mixed> $context Denial context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the denial assignment is persisted for the subject.
	 */
	public static function deny_permission(mixed $subject, string $permission, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::denyPermission($subject, $permission, $context);
	}

	/**
	 * Persists a role assignment for one subject.
	 *
	 * Role assignment context can carry actor, tenant, source, expiry, or audit metadata for repository-backed storage.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $role Role name to assign.
	 * @param array<string,mixed> $context Role assignment context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the role assignment is persisted for the subject.
	 */
	public static function assign_role(mixed $subject, string $role, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::assignRole($subject, $role, $context);
	}

	/**
	 * Revokes one persisted assignment.
	 *
	 * Successful revocations flush cached Framework permission state before later checks run.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $kind Assignment kind, such as role, permission, or denial.
	 * @param string $value Role or permission value to revoke.
	 * @param array<string,mixed> $context Revocation context, including optional actor, tenant, source, or audit metadata.
	 * @return bool True when the matching persisted assignment is revoked.
	 */
	public static function revoke(mixed $subject, string $kind, string $value, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::revoke($subject, $kind, $value, $context);
	}

	/**
	 * Builds permission catalog metadata for a Panel surface.
	 *
	 * The Framework API validates Panel instances and returns an empty catalog for invalid optional integrations.
	 *
	 * @param mixed $panel Panel instance or manager to inspect.
	 * @param array<string,mixed> $options Panel catalog options controlling resource prefixing, aliases, and manifest scope.
	 * @return array<string,mixed> Panel permission catalog with resources, actions, aliases, and generated permission names.
	 */
	public static function panel_catalog(mixed $panel, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::panelCatalog($panel, $options);
	}

	/**
	 * Builds a role-to-permission matrix.
	 *
	 * Panel scope, default roles, persisted assignments, and role definitions are delegated to the Framework catalog helper.
	 *
	 * @param mixed $panel Optional Panel instance or manager used to scope the matrix.
	 * @param array<string,mixed> $options Role matrix options controlling panel scope, default roles, and persistence lookups.
	 * @return array<string,mixed> Role-to-permission matrix with panel scope, persisted assignments, and default role metadata.
	 */
	public static function role_matrix(mixed $panel=null, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::roleMatrix($panel, $options);
	}

	/**
	 * Builds role presets for a Panel surface.
	 *
	 * The Framework catalog derives preset definitions from panel resource metadata and supplied options.
	 *
	 * @param mixed $panel Panel instance or manager used to derive presets.
	 * @param array<string,mixed> $options Preset generation options controlling panel scope and role metadata.
	 * @return array<string,mixed> Generated role preset definitions for the supplied Panel surface.
	 */
	public static function role_presets(mixed $panel, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::rolePresets($panel, $options);
	}

	/**
	 * Persists generated Panel role presets.
	 *
	 * The Framework catalog handles panel validation, overwrite rules, and persistence details.
	 *
	 * @param mixed $panel Panel instance or manager used to derive presets.
	 * @param array<string,mixed> $options Seed options controlling panel scope, overwrite behavior, and persistence driver details.
	 * @return array<string,mixed> Seed result with persisted preset counts, skipped roles, and overwrite status.
	 */
	public static function seed_role_presets(mixed $panel, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::seedRolePresets($panel, $options);
	}

	/**
	 * Builds a permission manifest.
	 *
	 * Optional Panel scope influences resource entries; aliases, roles, permissions, and storage metadata are normalized by the Framework manifest helper.
	 *
	 * @param mixed $panel Optional Panel instance or manager.
	 * @param array<string,mixed> $options Manifest options controlling panel scope, permission aliases, and storage hydration.
	 * @return array<string,mixed> Permission manifest containing aliases, roles, permissions, resources, and storage metadata.
	 */
	public static function manifest(mixed $panel=null, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::manifest($panel, $options);
	}

	/**
	 * Builds a permission manifest JSON string.
	 *
	 *
	 * @param array<string,mixed> $options Manifest JSON options controlling panel scope and serialization policy.
	 * @return string Serialized permission manifest.
	 */
	public static function manifest_json(mixed $panel=null, array $options=[]): string {
		self::framework();
		return \Dataphyre\Permission\Permission::manifestJson($panel, $options);
	}

	/**
	 * Diffs two permission manifests.
	 *
	 * The result describes added, removed, and changed permission, role, alias, and Panel-resource entries.
	 *
	 * @param array<string,mixed> $left Previously generated permission manifest.
	 * @param array<string,mixed> $right Current permission manifest to compare against the left side.
	 * @return array<string,mixed> Manifest diff with added, removed, and changed permission, role, alias, and resource entries.
	 */
	public static function diff_manifests(array $left, array $right): array {
		self::framework();
		return \Dataphyre\Permission\Permission::diffManifests($left, $right);
	}

	/**
	 * Imports role definitions from a permission manifest.
	 *
	 * Import options control overwrite, merge, validation, and repository persistence behavior.
	 *
	 * @param array<string,mixed> $manifest Permission manifest containing roles, permissions, aliases, and panel resource entries.
	 * @param array<string,mixed> $options Import options controlling overwrite, merge, validation, and persistence behavior.
	 * @return array<string,mixed> Import result with processed, skipped, persisted, and validation details.
	 */
	public static function import_manifest_roles(array $manifest, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::importManifestRoles($manifest, $options);
	}

	/**
	 * Builds a normalized Panel permission name.
	 *
	 * Resource, operation, prefixes, and aliases are normalized through the Framework namer.
	 *
	 * @param string $resource Panel resource or resource key.
	 * @param string $operation CRUD or custom operation name.
	 * @param array<string,mixed> $options Naming options such as permission prefix, resource prefix, aliases, and operation aliases.
	 * @return string Panel permission name after prefix, resource, operation, and alias normalization.
	 */
	public static function name(string $resource, string $operation, array $options=[]): string {
		self::framework();
		return \Dataphyre\Permission\Permission::name($resource, $operation, $options);
	}

	/**
	 * Converts one Shield-style permission into the Framework naming format.
	 *
	 * Prefixes, resource prefixes, and operation aliases are applied before returning the normalized permission string.
	 *
	 * @param string $permission Shield-style permission string.
	 * @param array<string,mixed> $options Shield conversion options such as permission prefix, resource prefix, and operation aliases.
	 * @return string Framework permission name converted from Shield-style input.
	 */
	public static function from_shield(string $permission, array $options=[]): string {
		self::framework();
		return \Dataphyre\Permission\Permission::fromShield($permission, $options);
	}

	/**
	 * Converts multiple Shield-style permissions into Framework names.
	 *
	 * Array and string inputs are normalized through the same conversion rules used by `from_shield()`.
	 *
	 * @param array|string $permissions Shield-style permission string or list.
	 * @param array<string,mixed> $options Shield conversion options applied to each permission string.
	 * @return list<string> Framework permission names converted from Shield-style input.
	 */
	public static function from_shield_many(array|string $permissions, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::fromShieldMany($permissions, $options);
	}

	/**
	 * Audits configured permission coverage.
	 *
	 * Optional Panel scope, persisted assignments, hydrated roles, and expected coverage are delegated to the Framework audit helper.
	 *
	 * @param mixed $panel Optional Panel instance or manager used to scope the audit.
	 * @param array<string,mixed> $options Audit options controlling panel scope, persistence hydration, and expected role coverage.
	 * @return array<string,mixed> Permission coverage audit with roles, assignments, expected coverage, and findings.
	 */
	public static function audit(mixed $panel=null, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::audit($panel, $options);
	}

	/**
	 * Audits role definitions against known permissions and assignments.
	 *
	 * The report can surface uncovered permissions, unknown grants, conflicting assignments, and severity details.
	 *
	 * @param array<string,array|string|list<string>> $roles Role definitions keyed by role name.
	 * @param list<string>|array<string,mixed> $known_permissions Permission keys expected to be covered by roles.
	 * @param list<array{subject?:mixed,role?:string,permission?:string,effect?:string}> $assignments Persisted or simulated assignments included in the audit.
	 * @param array<string,mixed> $options Audit options controlling severity thresholds and manifest scope.
	 * @return array<string,mixed> Role audit report with uncovered, unknown, conflicting, and severity details.
	 */
	public static function audit_roles(array $roles, array $known_permissions=[], array $assignments=[], array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::auditRoles($roles, $known_permissions, $assignments, $options);
	}

	/**
	 * Evaluates a permission expectation matrix.
	 *
	 * Subjects and expectation cases are delegated to the Framework test helper with shared context defaults from options.
	 *
	 * @param array<int|string,mixed> $subjects Test subjects keyed by case name or numeric index.
	 * @param array<int|string,mixed> $expectations Expected permission outcomes for each subject/case.
	 * @param array<string,mixed> $options Test options controlling context defaults and failure reporting.
	 * @return array<string,mixed> Matrix evaluation report with cases, expected outcomes, failures, and context details.
	 */
	public static function test_matrix(array $subjects, array $expectations, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::testMatrix($subjects, $expectations, $options);
	}

	/**
	 * Asserts a permission expectation matrix.
	 *
	 * Assertion behavior, including exception/reporting policy, is delegated to the Framework test helper.
	 *
	 * @param array<int|string,mixed> $subjects Test subjects keyed by case name or numeric index.
	 * @param array<int|string,mixed> $expectations Expected permission outcomes asserted for each subject/case.
	 * @param array<string,mixed> $options Assertion options controlling context defaults and exception/reporting behavior.
	 * @return array<string,mixed> Assertion report for the evaluated permission expectation matrix.
	 */
	public static function assert_matrix(array $subjects, array $expectations, array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::assertMatrix($subjects, $expectations, $options);
	}

	/**
	 * Asserts that a subject is allowed the given permissions.
	 *
	 * The Framework test helper evaluates the current engine and raises according to its failure policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param array<string,mixed> $context Authorization context used while asserting allowed permissions.
	 * @return bool True when the allowed-permission assertion passes.
	 */
	public static function assert_allows(mixed $subject, mixed $permissions, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::assertAllows($subject, $permissions, $context);
	}

	/**
	 * Asserts that a subject is denied the given permissions.
	 *
	 * The Framework test helper evaluates the current engine and raises according to its failure policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param array<string,mixed> $context Authorization context used while asserting denied permissions.
	 * @return bool True when the denied-permission assertion passes.
	 */
	public static function assert_denies(mixed $subject, mixed $permissions, array $context=[]): bool {
		self::framework();
		return \Dataphyre\Permission\Permission::assertDenies($subject, $permissions, $context);
	}

	/**
	 * Simulates temporary permission changes for one subject.
	 *
	 * Grants, denials, roles, removals, and revocations from `$changes` are applied only inside the Framework simulator and are not persisted.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $changes Temporary role, permission, alias, or condition changes applied only for the simulation.
	 * @param mixed $checks Permission checks to evaluate after applying the temporary changes.
	 * @param array<string,mixed> $context Authorization context used while evaluating simulated checks.
	 * @return array<string,mixed> Simulation report with temporary changes, evaluated checks, and resulting decisions.
	 */
	public static function simulate(mixed $subject, array $changes, mixed $checks, array $context=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::simulate($subject, $changes, $checks, $context);
	}

	/**
	 * Captures a subject permission snapshot.
	 *
	 * Snapshots combine subject data, requested permissions, authorization context, optional traces, and explanations for later comparison.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param array<string,mixed> $context Authorization context captured with the snapshot.
	 * @param array<string,mixed> $options Snapshot options controlling included permissions, trace rows, and explanation depth.
	 * @return array<string,mixed> Permission snapshot with subject, requested permissions, context, traces, and explanations.
	 */
	public static function snapshot(mixed $subject, mixed $permissions, array $context=[], array $options=[]): array {
		self::framework();
		return \Dataphyre\Permission\Permission::snapshot($subject, $permissions, $context, $options);
	}

	/**
	 * Diffs two permission snapshots.
	 *
	 * The Framework snapshot helper reports changed decisions, traces, explanations, and subject permission state.
	 *
	 * @param array<string,mixed> $left Previously captured permission snapshot.
	 * @param array<string,mixed> $right Current permission snapshot to compare against the left side.
	 * @return array<string,mixed> Snapshot diff with changed decisions, traces, explanations, and subject permission state.
	 */
	public static function diff_snapshots(array $left, array $right): array {
		self::framework();
		return \Dataphyre\Permission\Permission::diffSnapshots($left, $right);
	}

	/**
	 * Optimizes raw permission rules.
	 *
	 * The optimizer normalizes redundant, overlapping, or unreachable rule entries without mutating active engine or repository state.
	 *
	 * @param array|string $rules Permission rule string or list to optimize.
	 * @return array<string,mixed> Optimized rule report with normalized rules and removed redundant entries.
	 */
	public static function optimize_rules(array|string $rules): array {
		self::framework();
		return \Dataphyre\Permission\Permission::optimizeRules($rules);
	}

	/**
	 * Analyzes raw permission rules.
	 *
	 * Analysis reports redundancy, conflicts, coverage, and structural warnings without changing persisted role or subject state.
	 *
	 * @param array|string $rules Permission rule string or list to analyze.
	 * @return array<string,mixed> Rule analysis report with redundancy, conflict, coverage, and structural warnings.
	 */
	public static function analyze_rules(array|string $rules): array {
		self::framework();
		return \Dataphyre\Permission\Permission::analyzeRules($rules);
	}

	/**
	 * Analyzes role rule definitions.
	 *
	 * The optimizer inspects each role's declared permissions and returns role-level warnings and normalization advice.
	 *
	 * @param array<string,array|string|list<string>> $roles Role definitions keyed by role name.
	 * @return array<string,mixed> Role-rule analysis report with role-level warnings and normalization advice.
	 */
	public static function analyze_role_rules(array $roles): array {
		self::framework();
		return \Dataphyre\Permission\Permission::analyzeRoleRules($roles);
	}

	/**
	 * Clears cached Framework permission runtime state.
	 *
	 * The active engine, repository singleton, condition registry, and trace buffer are reset for the current PHP process.
	 * @return void
	 */
	public static function flush(): void {
		self::framework();
		\Dataphyre\Permission\Permission::flush();
	}
}
