<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Binds one authorization subject to the permission engine for fluent policy checks.
 *
 * PermissionSubject is intentionally small: it does not normalize identities, load roles, or cache decisions. The wrapped
 * subject is passed through exactly as provided so PermissionEngine and the Permission facade remain the single source of
 * truth for identity resolution, wildcard expansion, condition evaluation, and audit explanations.
 *
 * Use this object when application code already knows which actor, token, service account, or anonymous principal is being
 * evaluated and wants repeated checks without passing the subject into every call.
 */
final class PermissionSubject {

	/**
	 * Creates a subject-bound permission facade.
	 *
	 * The constructor stores references only. It performs no authorization work and has no side effects, which makes the
	 * object safe to create before the caller knows which permission check will be needed.
	 *
	 * @param mixed $subject Actor, identity, token, model, or scalar understood by the configured permission resolver.
	 * @param PermissionEngine $engine Engine responsible for evaluating permissions for the subject.
	 */
	public function __construct(
		private readonly mixed $subject,
		private readonly PermissionEngine $engine
	){}

	/**
	 * Requires the subject to satisfy every requested permission.
	 *
	 * This is the strict authorization path for guarded actions. Array permission input is interpreted by
	 * PermissionEngine::allowsAll(), so callers can express composite gates without manually looping through the permission
	 * list.
	 *
	 * @param mixed $requiredPermission Permission string, enum, list, or domain object supported by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return bool Whether the subject is allowed all requested permissions.
	 */
	public function can(mixed $requiredPermission, array $context=[]): bool {
		return $this->engine->allowsAll($this->subject, $requiredPermission, $context);
	}

	/**
	 * Allows the subject when at least one requested permission is granted.
	 *
	 * This method is useful for alternate routes to the same feature, such as "manage users" or "view team users". It
	 * delegates aggregation semantics to PermissionEngine::allowsAny() so wildcard and inherited permissions are resolved
	 * consistently with the rest of the permission system.
	 *
	 * @param mixed $requiredPermission Permission string, enum, list, or domain object supported by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return bool Whether any requested permission is allowed for the subject.
	 */
	public function any(mixed $requiredPermission, array $context=[]): bool {
		return $this->engine->allowsAny($this->subject, $requiredPermission, $context);
	}

	/**
	 * Returns per-permission decisions for a batch check.
	 *
	 * The returned shape is owned by PermissionEngine::decisions(); callers should treat keys as permission identifiers and
	 * values as the engine's decision records or booleans. This method is read-only and does not persist audit trails by
	 * itself.
	 *
	 * @param mixed $permissions Permission collection accepted by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return array<string, mixed> Engine decision map keyed by normalized permission.
	 */
	public function decisions(mixed $permissions, array $context=[]): array {
		return $this->engine->decisions($this->subject, $permissions, $context);
	}

	/**
	 * Evaluates a collection of permissions into allow/deny booleans.
	 *
	 * This is the compact batch form for UIs that need to enable several actions at once without carrying full explanation
	 * metadata.
	 *
	 * @param mixed $permissions Permission collection accepted by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return array<string, bool> Boolean authorization map keyed by normalized permission.
	 */
	public function allowsMany(mixed $permissions, array $context=[]): array {
		return $this->engine->allowsMany($this->subject, $permissions, $context);
	}

	/**
	 * Filters a permission collection down to entries allowed for the subject.
	 *
	 * The engine controls whether original keys are preserved and how permission-like values are normalized. Use this when
	 * rendering menus, command palettes, or action groups where denied actions should be omitted entirely.
	 *
	 * @param mixed $permissions Permission collection accepted by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return array<mixed> Permission entries that passed the engine check.
	 */
	public function filterAllowed(mixed $permissions, array $context=[]): array {
		return $this->engine->filterAllowed($this->subject, $permissions, $context);
	}

	/**
	 * Negates the strict all-permissions check for expressive guard code.
	 *
	 * @param mixed $requiredPermission Permission string, enum, list, or domain object supported by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return bool Whether at least one required permission is denied.
	 */
	public function cannot(mixed $requiredPermission, array $context=[]): bool {
		return !$this->can($requiredPermission, $context);
	}

	/**
	 * Checks a permission and an additional condition expression for the subject.
	 *
	 * Conditions are evaluated by the Permission facade, not by this wrapper. This keeps conditional policy grammar,
	 * attribute access, and failure reporting consistent with global permission helpers.
	 *
	 * @param mixed $requiredPermission Permission requirement to evaluate.
	 * @param array<string, mixed>|string $conditions Condition expression or condition map.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return bool Whether both the permission and the condition expression allow the subject.
	 */
	public function canWhen(mixed $requiredPermission, array|string $conditions, array $context=[]): bool {
		return Permission::checkWhen($requiredPermission, $conditions, $this->subject, $context);
	}

	/**
	 * Enforces a conditional permission check through the Permission facade.
	 *
	 * The facade owns the failure behavior for ensure_when(); depending on configuration it may throw, abort, or return a
	 * boolean denial. This wrapper supplies the bound subject and leaves enforcement semantics untouched.
	 *
	 * @param mixed $requiredPermission Permission requirement to enforce.
	 * @param array<string, mixed>|string $conditions Condition expression or condition map.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return bool Whether the facade completed the enforcement path as an allow result.
	 */
	public function ensureWhen(mixed $requiredPermission, array|string $conditions, array $context=[]): bool {
		return Permission::ensureWhen($requiredPermission, $conditions, $this->subject, $context);
	}

	/**
	 * Explains a conditional permission decision for diagnostics and examples.
	 *
	 * Explanation payloads are intentionally delegated to Permission::explainWhen() so policy traces, condition traces,
	 * and denial reasons use the same shape as framework-level helpers.
	 *
	 * @param mixed $requiredPermission Permission requirement to explain.
	 * @param array<string, mixed>|string $conditions Condition expression or condition map.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return array<string, mixed> Explanation payload produced by the Permission facade.
	 */
	public function explainWhen(mixed $requiredPermission, array|string $conditions, array $context=[]): array {
		return Permission::explainWhen($requiredPermission, $conditions, $this->subject, $context);
	}

	/**
	 * Creates a reusable permission set view for the subject.
	 *
	 * PermissionSet is the richer object form for repeated checks against one subject and context. The engine decides which
	 * permissions are loaded or lazily resolved; this wrapper only supplies the bound subject.
	 *
	 * @param array<string, mixed> $context Runtime facts captured by the PermissionSet.
	 * @return PermissionSet Subject-specific permission set produced by the engine.
	 */
	public function set(array $context=[]): PermissionSet {
		return $this->engine->setFor($this->subject, $context);
	}

	/**
	 * Explains a strict permission decision for the subject.
	 *
	 * Use this for audit pages, debugging, and permission reports where the caller needs to know why a permission was granted or
	 * denied rather than just the final boolean.
	 *
	 * @param mixed $requiredPermission Permission string, enum, list, or domain object supported by the engine.
	 * @param array<string, mixed> $context Runtime facts used by policies and condition resolvers.
	 * @return array<string, mixed> Engine explanation payload for the authorization decision.
	 */
	public function explain(mixed $requiredPermission, array $context=[]): array {
		return $this->engine->explain($this->subject, $requiredPermission, $context);
	}
}
