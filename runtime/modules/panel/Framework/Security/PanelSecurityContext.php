<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable actor, tenant, session, MFA, and impersonation security context. */
final class PanelSecurityContext implements \JsonSerializable {
	private function __construct(
		private readonly string $actorId,
		private readonly ?string $tenantId,
		private readonly array $roles,
		private readonly array $permissions,
		private readonly int $mfaLevel,
		private readonly bool $trustedSession,
		private readonly ?string $sessionId,
		private readonly ?string $impersonatorId,
		private readonly array $attributes
	){}

	public static function make(string|int $actorId, array $options=[]): self {
		$actor=trim((string)$actorId);
		if($actor===''){ throw new \InvalidArgumentException('A Panel security actor id is required.'); }
		$mfaLevel=(int)($options['mfa_level'] ?? (($options['mfa'] ?? false) ? 1 : 0));
		return new self(
			$actor,
			isset($options['tenant_id']) && trim((string)$options['tenant_id'])!=='' ? trim((string)$options['tenant_id']) : null,
			self::names($options['roles'] ?? []),
			self::names($options['permissions'] ?? []),
			max(0, min(3, $mfaLevel)),
			($options['trusted_session'] ?? false)===true,
			isset($options['session_id']) ? trim((string)$options['session_id']) ?: null : null,
			isset($options['impersonator_id']) ? trim((string)$options['impersonator_id']) ?: null : null,
			is_array($options['attributes'] ?? null) ? $options['attributes'] : []
		);
	}

	public static function fromArray(array $context): self { return self::make((string)($context['actor_id'] ?? ''), $context); }
	public function actorId(): string { return $this->actorId; }
	public function tenantId(): ?string { return $this->tenantId; }
	public function roles(): array { return $this->roles; }
	public function permissions(): array { return $this->permissions; }
	public function mfaLevel(): int { return $this->mfaLevel; }
	public function trustedSession(): bool { return $this->trustedSession; }
	public function impersonating(): bool { return $this->impersonatorId!==null; }
	public function impersonatorId(): ?string { return $this->impersonatorId; }
	public function hasRole(string $role): bool { return in_array(strtolower(trim($role)), $this->roles, true) || in_array('*', $this->roles, true); }
	public function can(string $permission): bool {
		$permission=strtolower(trim($permission));
		if(in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true)){ return true; }
		$segments=explode('.', $permission);
		while(count($segments)>1){ array_pop($segments); if(in_array(implode('.', $segments).'.*', $this->permissions, true)){ return true; } }
		return false;
	}
	public function attribute(string $key, mixed $default=null): mixed { return $this->attributes[$key] ?? $default; }
	public function jsonSerialize(): array { return ['actor_id'=>$this->actorId, 'tenant_id'=>$this->tenantId, 'roles'=>$this->roles, 'permissions'=>$this->permissions, 'mfa_level'=>$this->mfaLevel, 'trusted_session'=>$this->trustedSession, 'session_id'=>$this->sessionId, 'impersonator_id'=>$this->impersonatorId, 'attributes'=>$this->attributes]; }

	private static function names(mixed $values): array {
		$values=is_array($values) ? $values : [$values];
		return array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $values))));
	}
}
