<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Compiles subject permissions and evaluates authorization decisions.
 *
 * PermissionEngine turns direct subject permissions, subject roles, configured role definitions, permission aliases, optional
 * repository hydration, default roles, and super-permissions into cached PermissionSet instances. It is the central
 * policy-evaluation object behind PermissionSubject and higher-level permission helpers.
 *
 * The engine separates strict "all permissions must pass" checks from permissive "any permission may pass" checks, and can
 * return per-permission decisions or full explanations for diagnostics and audit views. Subject caches are keyed by resolved
 * subject id plus tenant context when available; anonymous subjects are keyed from their serialized value and tenant.
 */
final class PermissionEngine {

	/** @var array<string, array<int, string>> Role definitions keyed by normalized role name. */
	private array $roles=[];
	/** @var array<string, string> Permission aliases keyed by normalized alias. */
	private array $aliases=[];
	/** @var array<int, string> Roles granted to every subject before subject roles are merged. */
	private array $defaultRoles=[];
	/** @var array<int, string> Permissions that grant global/super access when present in a set. */
	private array $superPermissions=[];
	private bool $cacheEnabled=true;
	private int $maxSubjects=512;
	/** @var array<string, PermissionSet> */
	private array $subjectCache=[];
	/** @var array<string, PermissionSet> */
	private array $compiledCache=[];

	/**
	 * Creates an engine from permission configuration.
	 *
	 * Config keys include roles, aliases, default_roles, super_permissions, and cache. Role and alias maps are normalized up
	 * front so later checks work with stable permission tokens.
	 *
	 * @param array<string, mixed> $config Permission engine configuration.
	 */
	public function __construct(array $config=[]) {
		$this->roles=self::normalizeRoleMap(is_array($config['roles'] ?? null) ? $config['roles'] : []);
		$this->aliases=self::normalizeAliasMap(is_array($config['aliases'] ?? null) ? $config['aliases'] : []);
		$this->defaultRoles=PermissionRule::many($config['default_roles'] ?? []);
		$this->superPermissions=PermissionRule::many($config['super_permissions'] ?? ['*']);
		$cache=is_array($config['cache'] ?? null) ? $config['cache'] : [];
		$this->cacheEnabled=($cache['enabled'] ?? true)!==false;
		$this->maxSubjects=max(16, (int)($cache['max_subjects'] ?? 512));
	}

	/**
	 * Builds an engine from the global DP_PERMISSION_CFG array.
	 *
	 * @return self Engine configured from global permission settings.
	 */
	public static function fromConfig(): self {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		return new self($config);
	}

	/**
	 * Defines or replaces a role at runtime.
	 *
	 * Cache state is flushed because role expansion can affect every compiled and subject-specific permission set.
	 *
	 * @param string $role Role name to normalize and define.
	 * @param array|string $permissions Permission list accepted by PermissionRule::many().
	 */
	public function defineRole(string $role, array|string $permissions): void {
		$role=PermissionRule::normalize($role);
		if($role===''){
			return;
		}
		$this->roles[$role]=PermissionRule::many($permissions);
		$this->flush();
	}

	/**
	 * Clears subject and compiled-set caches.
	 */
	public function flush(): void {
		$this->subjectCache=[];
		$this->compiledCache=[];
	}

	/**
	 * Requires the subject to satisfy every requested permission.
	 *
	 * The first denied permission stops evaluation. PermissionTrace receives the failed permission when tracing is enabled.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $requiredPermission Permission or permission collection.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return bool Whether all required permissions are allowed.
	 */
	public function allowsAll(mixed $subject, mixed $requiredPermission, array $context=[]): bool {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$set=$this->setFor($subject, $context);
		$allowed=true;
		$failed=null;
		foreach(PermissionRule::many($requiredPermission) as $permission){
			if(!$set->allows($permission, $context)){
				$allowed=false;
				$failed=$permission;
				break;
			}
		}
		$this->traceDecision('check.all', $subject, $requiredPermission, $allowed, $started, $context, ['failed'=>$failed]);
		return $allowed;
	}

	/**
	 * Allows the subject when at least one requested permission passes.
	 *
	 * The first allowed permission stops evaluation. PermissionTrace receives the matched permission when tracing is enabled.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $requiredPermission Permission or permission collection.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return bool Whether any requested permission is allowed.
	 */
	public function allowsAny(mixed $subject, mixed $requiredPermission, array $context=[]): bool {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$set=$this->setFor($subject, $context);
		$allowed=false;
		$matched=null;
		foreach(PermissionRule::many($requiredPermission) as $permission){
			if($set->allows($permission, $context)){
				$allowed=true;
				$matched=$permission;
				break;
			}
		}
		$this->traceDecision('check.any', $subject, $requiredPermission, $allowed, $started, $context, ['matched'=>$matched]);
		return $allowed;
	}

	/**
	 * Returns per-permission decision records for a subject.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $permissions Permission collection to evaluate.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return array<string, array<string, mixed>> Decision records produced by PermissionSet::decisions().
	 */
	public function decisions(mixed $subject, mixed $permissions, array $context=[]): array {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$decisions=$this->setFor($subject, $context)->decisions($permissions, $context);
		$this->traceDecision('decisions', $subject, $permissions, !in_array(false, array_map(static fn(array $decision): bool => ($decision['allowed'] ?? false)===true, $decisions), true), $started, $context, [
			'count'=>count($decisions),
		]);
		return $decisions;
	}

	/**
	 * Returns boolean allow/deny results for many permissions.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $permissions Permission collection to evaluate.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return array<string, bool> Boolean decision map keyed by normalized permission.
	 */
	public function allowsMany(mixed $subject, mixed $permissions, array $context=[]): array {
		return $this->setFor($subject, $context)->allowsMany($permissions, $context);
	}

	/**
	 * Filters a permission collection to entries allowed for the subject.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $permissions Permission collection to filter.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return array<mixed> Allowed permission entries in PermissionSet filter format.
	 */
	public function filterAllowed(mixed $subject, mixed $permissions, array $context=[]): array {
		return $this->setFor($subject, $context)->filterAllowed($permissions, $context);
	}

	/**
	 * Explains why a subject is allowed or denied a permission set.
	 *
	 * The explanation includes final allowed state, resolved subject id, per-permission checks, roles, and compiled
	 * permissions. It is designed for audits, debugging, and examples.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param mixed $requiredPermission Permission or permission collection to explain.
	 * @param array<string, mixed> $context Runtime context used by subject resolvers and permission rules.
	 * @return array{allowed:bool, subject_id:mixed, checks:array<int, array<string, mixed>>, roles:array<int, string>, permissions:array<int, string>} Authorization explanation.
	 */
	public function explain(mixed $subject, mixed $requiredPermission, array $context=[]): array {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$set=$this->setFor($subject, $context);
		$checks=[];
		$allowed=true;
		foreach(PermissionRule::many($requiredPermission) as $permission){
			$result=$set->explain($permission, $context);
			$checks[]=$result;
			if(($result['allowed'] ?? false)!==true){
				$allowed=false;
			}
		}
		$explanation=[
			'allowed'=>$allowed,
			'subject_id'=>SubjectResolver::id($subject),
			'checks'=>$checks,
			'roles'=>$set->roles(),
			'permissions'=>$set->permissions(),
		];
		$this->traceDecision('explain', $subject, $requiredPermission, $allowed, $started, $context, [
			'checks'=>$checks,
		]);
		return $explanation;
	}

	/**
	 * Compiles or retrieves the PermissionSet for a subject and context.
	 *
	 * Subject sets are cached when cache is enabled. The cache is bounded by max_subjects and evicts the oldest inserted
	 * subject set when full.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param array<string, mixed> $context Runtime context, commonly including tenant or tenant_id.
	 * @return PermissionSet Compiled permission set for the subject.
	 */
	public function setFor(mixed $subject=null, array $context=[]): PermissionSet {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$subjectKey=$this->subjectCacheKey($subject, $context);
		if($this->cacheEnabled && isset($this->subjectCache[$subjectKey])){
			$this->traceCache('subject.set', $subject, true, $started, $context);
			return $this->subjectCache[$subjectKey];
		}
		$rules=$this->rulesFor($subject, $context);
		$permissions=$rules['permissions'];
		$roles=$rules['roles'];
		$set=$this->compile($permissions, $roles);
		if($this->cacheEnabled){
			if(count($this->subjectCache)>=$this->maxSubjects){
				array_shift($this->subjectCache);
			}
			$this->subjectCache[$subjectKey]=$set;
		}
		$this->traceCache('subject.set', $subject, false, $started, $context, [
			'roles'=>$roles,
			'permissions_count'=>count($permissions),
		]);
		return $set;
	}

	/**
	 * Resolves direct permissions and roles for a subject before compilation.
	 *
	 * SubjectResolver provides the baseline rules. When storage auto-hydration is enabled, PermissionRepository contributes
	 * additional subject permissions, roles, and role definitions. Default roles are merged into every subject.
	 *
	 * @param mixed $subject Subject value understood by SubjectResolver.
	 * @param array<string, mixed> $context Runtime context used by resolvers and repository lookups.
	 * @return array{permissions:array<int, string>, roles:array<int, string>} Normalized subject rules.
	 */
	public function rulesFor(mixed $subject=null, array $context=[]): array {
		$permissions=SubjectResolver::permissions($subject, $context);
		$roles=SubjectResolver::roles($subject, $context);
		if($this->autoHydrate()){
			$repository=PermissionRepository::instance();
			$permissions=array_merge($permissions, $repository->permissionsFor($subject, $context));
			$roles=array_merge($roles, $repository->rolesFor($subject, $context));
			foreach($repository->roleDefinitions() as $role=>$rules){
				if(!isset($this->roles[$role])){
					$this->roles[$role]=$rules;
				}
			}
		}
		return [
			'permissions'=>PermissionRule::many($permissions),
			'roles'=>array_values(array_unique(array_merge($this->defaultRoles, PermissionRule::many($roles)))),
		];
	}

	/**
	 * Compiles a PermissionSet from direct permissions and roles.
	 *
	 * Compiled sets are cached by direct permissions, roles, role definitions, aliases, and super-permissions. Role expansion
	 * follows nested role/group references before PermissionSet::compile() applies aliases and super-permission behavior.
	 *
	 * @param array|string $permissions Direct permissions.
	 * @param array|string $roles Subject roles.
	 * @return PermissionSet Compiled permission set.
	 */
	public function compile(array|string $permissions=[], array|string $roles=[]): PermissionSet {
		$started=PermissionTrace::enabled() ? microtime(true) : 0.0;
		$permissions=PermissionRule::many($permissions);
		$roles=PermissionRule::many($roles);
		$key=sha1(json_encode([$permissions, $roles, $this->roles, $this->aliases, $this->superPermissions]));
		if(isset($this->compiledCache[$key])){
			$this->traceCompile($permissions, $roles, true, $started);
			return $this->compiledCache[$key];
		}
		$expandedPermissions=$this->expandPermissions($permissions, $roles);
		$set=PermissionSet::compile($expandedPermissions, $roles, $this->aliases, $this->superPermissions);
		$this->traceCompile($permissions, $roles, false, $started, count($expandedPermissions));
		return $this->compiledCache[$key]=$set;
	}

	/**
	 * Expands roles into direct permissions, following nested role/group references.
	 *
	 * @param array<int, string> $permissions Direct normalized permissions.
	 * @param array<int, string> $roles Roles to expand.
	 * @return array<int, string> Unique direct and role-derived permissions.
	 */
	private function expandPermissions(array $permissions, array $roles): array {
		$expanded=$permissions;
		$seen=[];
		$stack=$roles;
		while($stack!==[]){
			$role=PermissionRule::normalize((string)array_pop($stack));
			if($role==='' || isset($seen[$role])){
				continue;
			}
			$seen[$role]=true;
			$rolePermissions=$this->roles[$role] ?? [];
			foreach($rolePermissions as $permission){
				$permission=PermissionRule::normalize($permission);
				if(str_starts_with($permission, 'role.') || str_starts_with($permission, 'group.')){
					$stack[]=substr($permission, strpos($permission, '.')+1);
					continue;
				}
				$expanded[]=$permission;
			}
		}
		return array_values(array_unique($expanded));
	}

	/**
	 * Builds a cache key for a subject and tenant context.
	 *
	 * @param mixed $subject Subject value.
	 * @param array<string, mixed> $context Runtime context.
	 * @return string Subject cache key.
	 */
	private function subjectCacheKey(mixed $subject, array $context): string {
		$id=SubjectResolver::id($subject);
		$tenant=$context['tenant'] ?? $context['tenant_id'] ?? null;
		if($id!==null && $id!==false && $id!==''){
			return 'id:'.(string)$id.'|tenant:'.(string)$tenant;
		}
		return 'anon:'.sha1(serialize([$subject, $tenant]));
	}

	/**
	 * Reports whether repository-backed permission hydration is enabled.
	 *
	 * @return bool Whether storage.auto_hydrate is enabled in DP_PERMISSION_CFG.
	 */
	private function autoHydrate(): bool {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$storage=is_array($config['storage'] ?? null) ? $config['storage'] : [];
		return ($storage['auto_hydrate'] ?? true)!==false;
	}

	/**
	 * Emits a permission decision trace event when tracing is enabled.
	 *
	 * @param string $event Trace event name.
	 * @param mixed $subject Subject value.
	 * @param mixed $permissions Permission input being evaluated.
	 * @param bool $allowed Final decision.
	 * @param float $started Start timestamp from microtime(true).
	 * @param array<string, mixed> $context Runtime context.
	 * @param array<string, mixed> $extra Additional trace fields.
	 */
	private function traceDecision(string $event, mixed $subject, mixed $permissions, bool $allowed, float $started, array $context, array $extra=[]): void {
		if(!PermissionTrace::enabled()){
			return;
		}
		PermissionTrace::record($event, $extra+[
			'subject_id'=>SubjectResolver::id($subject),
			'permissions'=>PermissionRule::many($permissions),
			'allowed'=>$allowed,
			'context'=>$context,
			'duration_ms'=>(microtime(true)-$started)*1000,
		]);
	}

	/**
	 * Emits a subject-cache trace event when tracing is enabled.
	 *
	 * @param string $event Trace event name.
	 * @param mixed $subject Subject value.
	 * @param bool $hit Whether the subject cache was hit.
	 * @param float $started Start timestamp from microtime(true).
	 * @param array<string, mixed> $context Runtime context.
	 * @param array<string, mixed> $extra Additional trace fields.
	 */
	private function traceCache(string $event, mixed $subject, bool $hit, float $started, array $context, array $extra=[]): void {
		if(!PermissionTrace::enabled()){
			return;
		}
		PermissionTrace::record($event, $extra+[
			'subject_id'=>SubjectResolver::id($subject),
			'cache_hit'=>$hit,
			'context'=>$context,
			'duration_ms'=>(microtime(true)-$started)*1000,
		]);
	}

	/**
	 * Emits a compiled-set cache trace event when tracing is enabled.
	 *
	 * @param array<int, string> $permissions Direct permissions used for compilation.
	 * @param array<int, string> $roles Roles used for compilation.
	 * @param bool $hit Whether the compiled-set cache was hit.
	 * @param float $started Start timestamp from microtime(true).
	 * @param ?int $expandedCount Number of permissions after role expansion on cache miss.
	 */
	private function traceCompile(array $permissions, array $roles, bool $hit, float $started, ?int $expandedCount=null): void {
		if(!PermissionTrace::enabled()){
			return;
		}
		PermissionTrace::record('compile', [
			'permissions_count'=>count($permissions),
			'roles'=>$roles,
			'expanded_permissions_count'=>$expandedCount,
			'cache_hit'=>$hit,
			'duration_ms'=>(microtime(true)-$started)*1000,
		]);
	}

	/**
	 * Normalizes configured role definitions.
	 *
	 * @param array<string, mixed> $roles Raw role map.
	 * @return array<string, array<int, string>> Normalized role-to-permissions map.
	 */
	private static function normalizeRoleMap(array $roles): array {
		$normalized=[];
		foreach($roles as $role=>$permissions){
			$role=PermissionRule::normalize((string)$role);
			if($role!==''){
				$normalized[$role]=PermissionRule::many($permissions);
			}
		}
		return $normalized;
	}

	/**
	 * Normalizes permission aliases.
	 *
	 * @param array<string, string> $aliases Raw alias map.
	 * @return array<string, string> Normalized alias-to-target map.
	 */
	private static function normalizeAliasMap(array $aliases): array {
		$normalized=[];
		foreach($aliases as $alias=>$target){
			$alias=PermissionRule::normalize((string)$alias);
			$target=PermissionRule::normalize((string)$target);
			if($alias!=='' && $target!==''){
				$normalized[$alias]=$target;
			}
		}
		return $normalized;
	}
}
