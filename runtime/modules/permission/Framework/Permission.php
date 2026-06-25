<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Static entry point for Dataphyre Permission authorization decisions.
 *
 * `Permission` exposes engine selection, permission checks, conditional rules,
 * tracing, and subject helpers while preserving a typed Framework API over
 * kernel policy behavior.
 */
final class Permission {

	private static ?PermissionEngine $engine=null;

	public static function __callStatic(string $name, array $arguments): mixed {
		$camel=preg_replace_callback('/_([a-z])/', static fn(array $match): string=>strtoupper($match[1]), $name);
		if(is_string($camel) && $camel!==$name && method_exists(self::class, $camel)){
			return self::$camel(...$arguments);
		}
		throw new \BadMethodCallException('Permission method does not exist: '.$name);
	}

	/**
	 * Returns the active authorization engine.
	 *
	 * The engine is loaded once from permission configuration and reused until `useEngine()` or `flush()` replaces the cached instance.
	 * @return PermissionEngine Active authorization engine.
	 */
	public static function engine(): PermissionEngine {
		return self::$engine ??= PermissionEngine::fromConfig();
	}

	/**
	 * Replaces the active authorization engine.
	 *
	 * Tests, alternate policy stores, and embedding applications can inject a prebuilt engine; later static API checks use it directly.
	 *
	 * @param PermissionEngine $engine Authorization engine to cache for subsequent static API calls.
	 * @return void
	 */
	public static function useEngine(PermissionEngine $engine): void {
		self::$engine=$engine;
	}

	/**
	 * Enables or disables permission trace collection.
	 *
	 * Tracing records decisions and condition checks for diagnostics only; it does not change allow/deny outcomes.
	 *
	 * @param bool $enabled Whether subsequent permission evaluations should be recorded.
	 * @return void
	 */
	public static function trace(bool $enabled=true): void {
		PermissionTrace::enable($enabled);
	}

	/**
	 * Returns collected permission trace entries.
	 *
	 * Entries reflect the current process trace buffer and are cleared only by `flushTrace()` or `flush()`.
	 * @return list<array<string,mixed>> Trace entries collected in the current process.
	 */
	public static function traces(): array {
		return PermissionTrace::entries();
	}

	/**
	 * Returns aggregate trace counters.
	 *
	 * Stats summarize the current trace buffer without mutating it.
	 * @return array<string,int> Trace counters grouped by the trace helper.
	 */
	public static function traceStats(): array {
		return PermissionTrace::stats();
	}

	/**
	 * Returns a grouped trace summary.
	 *
	 * The summary is derived from recorded entries and is intended for diagnostics and permission audit surfaces.
	 * @return array<string,mixed> Trace summary grouped by recorded decisions and condition checks.
	 */
	public static function traceSummary(): array {
		return PermissionTrace::summary();
	}

	/**
	 * Clears the permission trace buffer.
	 *
	 * This leaves the active engine, repository, and condition registry intact.
	 * @return void
	 */
	public static function flushTrace(): void {
		PermissionTrace::flush();
	}

	/**
	 * Creates a subject-scoped permission helper.
	 *
	 * The returned helper binds the provided subject to the current engine for chained checks, explanations, and assertions.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @return PermissionSubject Subject-scoped permission helper.
	 */
	public static function for(mixed $subject=null): PermissionSubject {
		return new PermissionSubject($subject, self::engine());
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context such as resource, tenant, owner, request, guard, or condition data.
	 * @return bool True when every required permission is allowed for the subject and context.
	 */
	public static function check(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return self::engine()->allowsAll($subject, $requiredPermission, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context included in the thrown authorization exception on denial.
	 * @return bool True when every required permission is allowed; denial raises the authorization exception.
	 */
	public static function ensure(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(self::check($requiredPermission, $subject, $context)){
			return true;
		}
		throw new Exceptions\AuthorizationException('Permission denied.', PermissionRule::many($requiredPermission), $context);
	}

	/**
	 * Registers a named conditional predicate.
	 *
	 * The callable is stored in the process condition registry and can be referenced by `checkWhen()`, `ensureWhen()`, and explanations.
	 *
	 * @param string $name Condition name used by policy checks.
	 * @param callable $condition Predicate invoked with subject, context, and permission data.
	 * @return void
	 */
	public static function defineCondition(string $name, callable $condition): void {
		PermissionCondition::define($name, $condition);
	}

	/**
	 * Lists registered condition names.
	 *
	 * The list reflects the in-process condition registry, including conditions added after engine creation.
	 * @return list<string> Registered condition names.
	 */
	public static function conditions(): array {
		return PermissionCondition::names();
	}

	/**
	 * Requires all permissions and all named conditions to pass.
	 *
	 * Permission denial short-circuits before condition predicates run; each normalized permission must satisfy the requested conditions.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression accepted by `PermissionCondition`.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context forwarded to permission checks and named condition predicates.
	 * @return bool True when the permission set and named conditions all pass.
	 */
	public static function checkWhen(mixed $requiredPermission, array|string $conditions, mixed $subject=null, array $context=[]): bool {
		if(!self::check($requiredPermission, $subject, $context)){
			return false;
		}
		foreach(PermissionRule::many($requiredPermission) as $permission){
			if(!PermissionCondition::passes($conditions, $subject, $context, $permission)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensures all permissions and all named conditions pass.
	 *
	 * Failure throws `AuthorizationException` with normalized permissions, authorization context, and normalized condition names.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression accepted by `PermissionCondition`.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context forwarded to permission checks and named condition predicates.
	 * @return bool True when permission and condition checks pass; denial raises the authorization exception.
	 */
	public static function ensureWhen(mixed $requiredPermission, array|string $conditions, mixed $subject=null, array $context=[]): bool {
		if(self::checkWhen($requiredPermission, $conditions, $subject, $context)){
			return true;
		}
		throw new Exceptions\AuthorizationException('Permission condition denied.', PermissionRule::many($requiredPermission), $context+['conditions'=>PermissionCondition::normalizeMany($conditions)]);
	}

	/**
	 * Explains a permission check combined with condition checks.
	 *
	 * The result contains the base permission explanation plus per-permission condition explanations and an aggregate `allowed` flag.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param array|string $conditions Condition name, condition list, or condition expression accepted by `PermissionCondition`.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context included in permission and condition explanations.
	 * @return array{allowed:bool, permission:array<string,mixed>, conditions:array<string,array<string,mixed>>} Conditional authorization explanation.
	 */
	public static function explainWhen(mixed $requiredPermission, array|string $conditions, mixed $subject=null, array $context=[]): array {
		$permissionExplanation=self::explain($requiredPermission, $subject, $context);
		$conditionExplanations=[];
		foreach(PermissionRule::many($requiredPermission) as $permission){
			$conditionExplanations[$permission]=PermissionCondition::explain($conditions, $subject, $context, $permission);
		}
		return [
			'allowed'=>($permissionExplanation['allowed'] ?? false)===true && !in_array(false, array_map(static fn(array $item): bool => ($item['allowed'] ?? false)===true, $conditionExplanations), true),
			'permission'=>$permissionExplanation,
			'conditions'=>$conditionExplanations,
		];
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context such as resource, tenant, owner, request, guard, or condition data.
	 * @return bool True when every required permission is allowed for the subject and context.
	 */
	public static function allows(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return self::check($requiredPermission, $subject, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used when evaluating any matching permission.
	 * @return bool True when at least one required permission is allowed.
	 */
	public static function any(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return self::engine()->allowsAny($subject, $requiredPermission, $context);
	}

	/**
	 * Returns detailed decisions for each requested permission.
	 *
	 * Permissions are normalized by the engine and evaluated with the same subject and context so callers can inspect per-rule allow/deny state.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context attached to each permission decision.
	 * @return array<string,bool> Decision map keyed by normalized permission string.
	 */
	public static function decisions(mixed $permissions, mixed $subject=null, array $context=[]): array {
		return self::engine()->decisions($subject, $permissions, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used while building the permission decision map.
	 * @return array<string,bool> Allow/deny map for each normalized permission string.
	 */
	public static function allowsMany(mixed $permissions, mixed $subject=null, array $context=[]): array {
		return self::engine()->allowsMany($subject, $permissions, $context);
	}

	/**
	 * Returns only permissions currently allowed for the subject.
	 *
	 * The engine returns normalized permissions in evaluation order while dropping denied entries.
	 *
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context used while filtering allowed permissions.
	 * @return list<string> Normalized permissions that are allowed for the subject and context.
	 */
	public static function filterAllowed(mixed $permissions, mixed $subject=null, array $context=[]): array {
		return self::engine()->filterAllowed($subject, $permissions, $context);
	}

	/**
	 * Evaluates Permission authorization rules.
	 *
	 * Authorization helpers normalize required permissions, resolve subjects, and apply aliases, roles, and super-permissions before evaluating the request.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context included in the thrown authorization exception on denial.
	 * @return bool True when at least one permission is allowed; denial raises the authorization exception.
	 */
	public static function ensureAny(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(self::any($requiredPermission, $subject, $context)){
			return true;
		}
		throw new Exceptions\AuthorizationException('Permission denied.', PermissionRule::many($requiredPermission), $context);
	}

	/**
	 * Checks whether all required permissions are denied.
	 *
	 * This is the inverse of `check()`, so mixed allow/deny behavior follows the engine's all-permissions semantics.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context such as resource, tenant, owner, request, guard, or condition data.
	 * @return bool True when the full permission set is denied for the subject and context.
	 */
	public static function denies(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return !self::check($requiredPermission, $subject, $context);
	}

	/**
	 * Explains why a permission set is allowed or denied.
	 *
	 * The returned explanation is produced by the engine and may include normalized permissions, subject facts, roles, aliases, super-permission matches, and denial reasons.
	 *
	 * @param mixed $requiredPermission Required permission or permission list.
	 * @param mixed $subject Subject being authorized.
	 * @param array<string,mixed> $context Authorization context included in the returned explanation.
	 * @return array{allowed:bool, subject_id:mixed, checks:array<int,array<string,mixed>>, roles:array<int,string>, permissions:array<int,string>} Authorization explanation.
	 */
	public static function explain(mixed $requiredPermission, mixed $subject=null, array $context=[]): array {
		return self::engine()->explain($subject, $requiredPermission, $context);
	}

	/**
	 * Compiles permissions and roles into a reusable permission set.
	 *
	 * Role expansion and permission normalization use the current engine, making the result suitable for repeated checks against the same policy configuration.
	 *
	 * @param array|string $permissions Permission names or rules to include directly.
	 * @param array|string $roles Role names whose permissions should be included.
	 * @return PermissionSet Normalized permission set.
	 */
	public static function set(array|string $permissions=[], array|string $roles=[]): PermissionSet {
		return self::engine()->compile($permissions, $roles);
	}

	/**
	 * Defines a role on the active engine.
	 *
	 * The role is available to subsequent in-process checks; persistent role storage is handled by `storeRole()`.
	 *
	 * @param string $role Role name to define.
	 * @param array|string $permissions Permission names or rules granted by the role.
	 * @return void
	 */
	public static function defineRole(string $role, array|string $permissions): void {
		self::engine()->defineRole($role, $permissions);
	}

	/**
	 * Returns the permission repository singleton.
	 *
	 * Repository methods persist role definitions, subject grants, denials, assignments, and revocations according to configured storage.
	 * @return PermissionRepository Active permission repository.
	 */
	public static function repository(): PermissionRepository {
		return PermissionRepository::instance();
	}

	/**
	 * Builds permission catalog metadata for a Panel surface.
	 *
	 * Invalid panel objects return an empty catalog instead of throwing, allowing optional Panel integrations to probe safely.
	 *
	 * @param mixed $panel Panel instance or manager to inspect.
	 * @param array<string,mixed> $options Panel catalog options controlling resource prefixing, aliases, and manifest scope.
	 * @return array<string,mixed> Panel permission catalog with resources, actions, aliases, and generated permission names.
	 */
	public static function panelCatalog(mixed $panel, array $options=[]): array {
		if(!$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			return [];
		}
		return PermissionCatalog::panel($panel, $options);
	}

	/**
	 * Reads or normalizes Permission catalog metadata.
	 *
	 * Catalog helpers expose roles, permission names, aliases, manifests, and policy metadata for Panel and diagnostics.
	 *
	 * @param mixed $panel Optional Panel instance or manager used to scope the matrix.
	 * @param array<string,mixed> $options Role matrix options controlling panel scope, default roles, and persistence lookups.
	 * @return array<string,mixed> Role-to-permission matrix with panel scope, persisted assignments, and default role metadata.
	 */
	public static function roleMatrix(mixed $panel=null, array $options=[]): array {
		if($panel!==null && !$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			return [];
		}
		return PermissionCatalog::roleMatrix($panel, $options);
	}

	/**
	 * Reads or normalizes Permission catalog metadata.
	 *
	 * Catalog helpers expose roles, permission names, aliases, manifests, and policy metadata for Panel and diagnostics.
	 *
	 * @param mixed $panel Panel instance or manager used to derive presets.
	 * @param array<string,mixed> $options Preset generation options controlling panel scope and role metadata.
	 * @return array<string,mixed> Generated role preset definitions for the supplied Panel surface.
	 */
	public static function rolePresets(mixed $panel, array $options=[]): array {
		if(!$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			return [];
		}
		return PermissionCatalog::rolePresets($panel, $options);
	}

	/**
	 * Persists generated role presets for a Panel surface.
	 *
	 * Invalid panel objects return an empty result; valid panels delegate seeding and overwrite policy to the catalog helper.
	 *
	 * @param mixed $panel Panel instance or manager used to derive presets.
	 * @param array<string,mixed> $options Seed options controlling panel scope, overwrite behavior, and persistence details.
	 * @return array<string,mixed> Seed result with persisted preset counts, skipped roles, and overwrite status.
	 */
	public static function seedRolePresets(mixed $panel, array $options=[]): array {
		if(!$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			return [];
		}
		return PermissionCatalog::seedRolePresets($panel, $options);
	}

	/**
	 * Reads or normalizes Permission catalog metadata.
	 *
	 * Catalog helpers expose roles, permission names, aliases, manifests, and policy metadata for Panel and diagnostics.
	 *
	 * @param mixed $panel Optional Panel instance or manager; invalid non-null values are ignored.
	 * @param array<string,mixed> $options Manifest options controlling panel scope, permission aliases, and storage hydration.
	 * @return array<string,mixed> Permission manifest containing aliases, roles, permissions, resources, and storage metadata.
	 */
	public static function manifest(mixed $panel=null, array $options=[]): array {
		if($panel!==null && !$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			$panel=null;
		}
		return PermissionManifest::build($panel, $options);
	}

	/**
	 * Reads or normalizes Permission catalog metadata.
	 *
	 * Catalog helpers expose roles, permission names, aliases, manifests, and policy metadata for Panel and diagnostics.
	 *
	 * @param mixed $panel Optional Panel instance or manager; invalid non-null values are ignored.
	 * @param array<string,mixed> $options Manifest JSON options controlling panel scope and serialization policy.
	 * @return string Serialized permission manifest JSON.
	 */
	public static function manifestJson(mixed $panel=null, array $options=[]): string {
		if($panel!==null && !$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			$panel=null;
		}
		return PermissionManifest::json($panel, $options);
	}

	/**
	 * Diffs two permission manifests.
	 *
	 * The result describes added, removed, and changed permission, role, alias, and panel-resource entries.
	 *
	 * @param array<string,mixed> $left Previously generated permission manifest.
	 * @param array<string,mixed> $right Current permission manifest to compare against the left side.
	 * @return array<string,mixed> Manifest diff with added, removed, and changed permission, role, alias, and resource entries.
	 */
	public static function diffManifests(array $left, array $right): array {
		return PermissionManifest::diff($left, $right);
	}

	/**
	 * Imports role definitions from a permission manifest.
	 *
	 * Import behavior is controlled by options such as overwrite, merge, validation, and persistence policy.
	 *
	 * @param array<string,mixed> $manifest Permission manifest containing roles, permissions, aliases, and panel resource entries.
	 * @param array<string,mixed> $options Import options controlling overwrite, merge, validation, and persistence behavior.
	 * @return array<string,mixed> Import result with processed, skipped, persisted, and validation details.
	 */
	public static function importManifestRoles(array $manifest, array $options=[]): array {
		return PermissionManifest::importRoles($manifest, $options);
	}

	/**
	 * Builds a normalized Panel permission name.
	 *
	 * Resource, operation, prefixes, and aliases are normalized through the permission namer so Panel and Shield-style names can round-trip.
	 *
	 * @param string $resource Panel resource or resource key.
	 * @param string $operation CRUD or custom operation name.
	 * @param array<string,mixed> $options Naming options such as permission prefix, resource prefix, aliases, and operation aliases.
	 * @return string Panel permission name after prefix, resource, operation, and alias normalization.
	 */
	public static function name(string $resource, string $operation, array $options=[]): string {
		return PermissionNamer::panel($resource, $operation, $options);
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
	public static function fromShield(string $permission, array $options=[]): string {
		return PermissionNamer::fromShield($permission, $options);
	}

	/**
	 * Converts multiple Shield-style permissions into Framework names.
	 *
	 * Array and string inputs are normalized through the same conversion rules used by `fromShield()`.
	 *
	 * @param array|string $permissions Shield-style permission string or list.
	 * @param array<string,mixed> $options Shield conversion options applied to each permission string.
	 * @return list<string> Converted permission strings.
	 */
	public static function fromShieldMany(array|string $permissions, array $options=[]): array {
		return PermissionNamer::fromShieldMany($permissions, $options);
	}

	/**
	 * Converts one Framework permission into Shield-style naming.
	 *
	 * The conversion preserves configured prefixes and operation aliases so exported names match external policy tooling.
	 *
	 * @param string $permission Framework permission string.
	 * @param array<string,mixed> $options Shield conversion options such as permission prefix, resource prefix, and operation aliases.
	 * @return string Shield-style permission name converted from Framework input.
	 */
	public static function toShield(string $permission, array $options=[]): string {
		return PermissionNamer::toShield($permission, $options);
	}

	/**
	 * Converts multiple Framework permissions into Shield-style names.
	 *
	 * Array and string inputs are normalized through the same conversion rules used by `toShield()`.
	 *
	 * @param array|string $permissions Framework permission string or list.
	 * @param array<string,mixed> $options Shield conversion options applied to each permission string.
	 * @return list<string> Converted permission strings.
	 */
	public static function toShieldMany(array|string $permissions, array $options=[]): array {
		return PermissionNamer::toShieldMany($permissions, $options);
	}

	/**
	 * Audits configured permission coverage.
	 *
	 * Panel inputs scope the audit to Panel resources; invalid non-null panel values fall back to a general audit rather than failing.
	 *
	 * @param mixed $panel Optional Panel instance or manager used to scope the audit.
	 * @param array<string,mixed> $options Audit options controlling panel scope, persistence hydration, and expected role coverage.
	 * @return array<string,mixed> Permission coverage audit with roles, assignments, expected coverage, and findings.
	 */
	public static function audit(mixed $panel=null, array $options=[]): array {
		if($panel!==null && !$panel instanceof \Dataphyre\Panel\PanelInstance && !$panel instanceof \Dataphyre\Panel\PanelManager){
			return PermissionAudit::run(null, $options);
		}
		return PermissionAudit::run($panel, $options);
	}

	/**
	 * Audits role definitions against known permissions and assignments.
	 *
	 * The report can surface uncovered permissions, unknown grants, conflicting assignments, and option-controlled severity details.
	 *
	 * @param array<string,array|string|list<string>> $roles Role definitions keyed by role name.
	 * @param list<string>|array<string,mixed> $knownPermissions Permission keys expected to be covered by roles.
	 * @param list<array{subject?:mixed,role?:string,permission?:string,effect?:string}> $assignments Persisted or simulated assignments included in the audit.
	 * @param array<string,mixed> $options Audit options controlling severity thresholds and manifest scope.
	 * @return array<string,mixed> Role audit report with uncovered, unknown, conflicting, and severity details.
	 */
	public static function auditRoles(array $roles, array $knownPermissions=[], array $assignments=[], array $options=[]): array {
		return PermissionAudit::roles($roles, $knownPermissions, $assignments, $options);
	}

	/**
	 * Evaluates a permission expectation matrix.
	 *
	 * Each subject and expectation case is evaluated with shared defaults from options and returned as a structured test report.
	 *
	 * @param array<int|string,mixed> $subjects Test subjects keyed by case name or numeric index.
	 * @param array<int|string,mixed> $expectations Expected permission outcomes for each subject/case.
	 * @param array<string,mixed> $options Test options controlling context defaults and failure reporting.
	 * @return array<string,mixed> Matrix evaluation report with cases, expected outcomes, failures, and context details.
	 */
	public static function testMatrix(array $subjects, array $expectations, array $options=[]): array {
		return PermissionTest::matrix($subjects, $expectations, $options);
	}

	/**
	 * Asserts a permission expectation matrix.
	 *
	 * Assertion behavior is delegated to `PermissionTest`, including any configured exception/reporting policy.
	 *
	 * @param array<int|string,mixed> $subjects Test subjects keyed by case name or numeric index.
	 * @param array<int|string,mixed> $expectations Expected permission outcomes asserted for each subject/case.
	 * @param array<string,mixed> $options Assertion options controlling context defaults and exception/reporting behavior.
	 * @return array<string,mixed> Assertion report for the evaluated permission expectation matrix.
	 */
	public static function assertMatrix(array $subjects, array $expectations, array $options=[]): array {
		return PermissionTest::assertMatrix($subjects, $expectations, $options);
	}

	/**
	 * Asserts that a subject is allowed the given permissions.
	 *
	 * The assertion helper evaluates the current engine and raises according to the test helper's failure policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param array<string,mixed> $context Authorization context used while asserting allowed permissions.
	 * @return bool True when the allowed-permission assertion passes.
	 */
	public static function assertAllows(mixed $subject, mixed $permissions, array $context=[]): bool {
		return PermissionTest::assertAllows($subject, $permissions, $context);
	}

	/**
	 * Asserts that a subject is denied the given permissions.
	 *
	 * The assertion helper evaluates the current engine and raises according to the test helper's failure policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param mixed $permissions Permission string, rule, list, or nested permission input.
	 * @param array<string,mixed> $context Authorization context used while asserting denied permissions.
	 * @return bool True when the denied-permission assertion passes.
	 */
	public static function assertDenies(mixed $subject, mixed $permissions, array $context=[]): bool {
		return PermissionTest::assertDenies($subject, $permissions, $context);
	}

	/**
	 * Simulates temporary permission changes for one subject.
	 *
	 * Grants, denials, roles, removals, and revocations from `$changes` are applied only inside the simulator and do not persist to the repository.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param array{grant?:mixed,grants?:mixed,allow?:mixed,allows?:mixed,add?:mixed,add_permissions?:mixed,permissions?:mixed,deny?:mixed,denies?:mixed,deny_permissions?:mixed,remove?:mixed,removes?:mixed,revoke?:mixed,revokes?:mixed,remove_permissions?:mixed,revoke_permissions?:mixed,role?:mixed,roles?:mixed,grant_roles?:mixed,add_roles?:mixed,remove_roles?:mixed,revoke_roles?:mixed} $changes Temporary role, permission, alias, or condition changes applied only for the simulation.
	 * @param mixed $checks Permission checks to evaluate after applying the temporary changes.
	 * @param array<string,mixed> $context Authorization context used while evaluating simulated checks.
	 * @return array<string,mixed> Simulation report with temporary changes, evaluated checks, and resulting decisions.
	 */
	public static function simulate(mixed $subject, array $changes, mixed $checks, array $context=[]): array {
		return PermissionSimulator::run($subject, $changes, $checks, $context);
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
		return PermissionSnapshot::subject($subject, $permissions, $context, $options);
	}

	/**
	 * Diffs two permission snapshots.
	 *
	 * The result highlights changed decisions, traces, explanations, and subject permission state between the two captures.
	 *
	 * @param array<string,mixed> $left Previously captured permission snapshot.
	 * @param array<string,mixed> $right Current permission snapshot to compare against the left side.
	 * @return array<string,mixed> Snapshot diff with changed decisions, traces, explanations, and subject permission state.
	 */
	public static function diffSnapshots(array $left, array $right): array {
		return PermissionSnapshot::diff($left, $right);
	}

	/**
	 * Optimizes raw permission rules.
	 *
	 * The optimizer normalizes redundant, overlapping, or unreachable rule entries without mutating the active engine.
	 *
	 * @param array|string $rules Permission rule string or list to optimize.
	 * @return array<string,mixed> Optimized rule report with normalized rules and removed redundant entries.
	 */
	public static function optimizeRules(array|string $rules): array {
		return PermissionOptimizer::optimize($rules);
	}

	/**
	 * Analyzes raw permission rules.
	 *
	 * Analysis reports redundancy, conflicts, coverage, and structural warnings without changing persisted role or subject state.
	 *
	 * @param array|string $rules Permission rule string or list to analyze.
	 * @return array<string,mixed> Rule analysis report with redundancy, conflict, coverage, and structural warnings.
	 */
	public static function analyzeRules(array|string $rules): array {
		return PermissionOptimizer::analyze($rules);
	}

	/**
	 * Analyzes role rule definitions.
	 *
	 * The optimizer inspects each role's declared permissions and returns role-level warnings and normalization advice.
	 *
	 * @param array<string,array|string|list<string>> $roles Role definitions keyed by role name.
	 * @return array<string,mixed> Role-rule analysis report with role-level warnings and normalization advice.
	 */
	public static function analyzeRoleRules(array $roles): array {
		return PermissionOptimizer::roles($roles);
	}

	/**
	 * Persists a role definition through the repository.
	 *
	 * Successful writes flush cached engine/repository/condition/trace state so later checks see the persisted role definition.
	 *
	 * @param string $role Role name to persist.
	 * @param array|string $permissions Permission names or rules granted by the role.
	 * @param array<string,mixed> $metadata Role metadata persisted beside the permission list.
	 * @return bool True when the role definition is persisted and cached permission state is flushed.
	 */
	public static function storeRole(string $role, array|string $permissions, array $metadata=[]): bool {
		$result=self::repository()->defineRole($role, $permissions, $metadata);
		if($result){
			self::flush();
		}
		return $result;
	}

	/**
	 * Persists an allow assignment for one subject.
	 *
	 * Assignment context can carry actor, tenant, source, expiry, or audit metadata for repository-backed persistence.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $permission Permission string to allow.
	 * @param array<string,mixed> $context Assignment context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the allow assignment is persisted for the subject.
	 */
	public static function assignPermission(mixed $subject, string $permission, array $context=[]): bool {
		return self::repository()->assignPermission($subject, $permission, $context);
	}

	/**
	 * Persists a denial assignment for one subject.
	 *
	 * Denials are stored through the repository and can override matching grants according to engine policy.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $permission Permission string to deny.
	 * @param array<string,mixed> $context Denial context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the denial assignment is persisted for the subject.
	 */
	public static function denyPermission(mixed $subject, string $permission, array $context=[]): bool {
		return self::repository()->denyPermission($subject, $permission, $context);
	}

	/**
	 * Persists a role assignment for one subject.
	 *
	 * Role assignment context can carry actor, tenant, source, expiry, or audit metadata for repository-backed persistence.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $role Role name to assign.
	 * @param array<string,mixed> $context Role assignment context, including optional actor, tenant, source, expiry, or audit metadata.
	 * @return bool True when the role assignment is persisted for the subject.
	 */
	public static function assignRole(mixed $subject, string $role, array $context=[]): bool {
		return self::repository()->assignRole($subject, $role, $context);
	}

	/**
	 * Revokes one persisted assignment.
	 *
	 * Successful revocations flush cached permission state so later checks reload current repository data.
	 *
	 * @param mixed $subject Subject being authorized.
	 * @param string $kind Assignment kind, such as role, permission, or denial.
	 * @param string $value Role or permission value to revoke.
	 * @param array<string,mixed> $context Revocation context, including optional actor, tenant, source, or audit metadata.
	 * @return bool True when the matching persisted assignment is revoked.
	 */
	public static function revoke(mixed $subject, string $kind, string $value, array $context=[]): bool {
		$result=self::repository()->revoke($subject, $kind, $value, $context);
		if($result){
			self::flush();
		}
		return $result;
	}

	/**
	 * Clears cached permission runtime state.
	 *
	 * The active engine, repository singleton, condition registry, and trace buffer are reset for the current PHP process.
	 * @return void
	 */
	public static function flush(): void {
		self::$engine=null;
		PermissionRepository::flush();
		PermissionCondition::flush();
		PermissionTrace::flush();
	}
}
