<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, privacy-safe proof that the current actor belongs to one tenant. */
final class PanelTenantMembership implements \JsonSerializable {

	/**
	 * @param list<string> $roles
	 * @param list<string> $permissions
	 * @param array<string,mixed> $meta
	 */
	private function __construct(
		private readonly string $tenant,
		private readonly array $roles=[],
		private readonly array $permissions=[],
		private readonly bool $active=true,
		private readonly bool $canSwitch=true,
		private readonly bool $preferred=false,
		private readonly ?int $expiresAt=null,
		private readonly array $meta=[]
	){}

	/** @param list<string> $roles @param list<string> $permissions @param array<string,mixed> $meta */
	public static function make(string $tenant, array $roles=[], array $permissions=[], bool $active=true, bool $canSwitch=true, bool $preferred=false, ?int $expiresAt=null, array $meta=[]): self {
		$tenant=PanelTenantSanitizer::tenantKey($tenant) ?? '';
		if($tenant===''){ throw new \InvalidArgumentException('Tenant memberships require a safe tenant key.'); }
		return new self(
			$tenant,
			self::names($roles, 32),
			self::names($permissions, 64),
			$active,
			$canSwitch,
			$preferred,
			$expiresAt!==null && $expiresAt>0 ? $expiresAt : null,
			PanelTenantSanitizer::map($meta)
		);
	}

	/** @param array<string,mixed> $membership */
	public static function fromArray(array $membership, ?string $defaultTenant=null): self {
		$tenant=(string)($membership['tenant'] ?? $membership['tenant_key'] ?? $membership['name'] ?? $defaultTenant ?? '');
		return self::make(
			$tenant,
			is_array($membership['roles'] ?? null) ? $membership['roles'] : [],
			is_array($membership['permissions'] ?? null) ? $membership['permissions'] : [],
			($membership['active'] ?? true)===true,
			($membership['can_switch'] ?? true)===true,
			($membership['preferred'] ?? $membership['current'] ?? false)===true,
			is_numeric($membership['expires_at'] ?? null) ? (int)$membership['expires_at'] : null,
			is_array($membership['meta'] ?? null) ? $membership['meta'] : []
		);
	}

	public function tenant(): string { return $this->tenant; }
	/** @return list<string> */
	public function roles(): array { return $this->roles; }
	/** @return list<string> */
	public function permissions(): array { return $this->permissions; }
	public function preferred(): bool { return $this->preferred; }
	public function expiresAt(): ?int { return $this->expiresAt; }
	/** @return array<string,mixed> */
	public function meta(): array { return $this->meta; }

	public function isActive(?int $now=null): bool {
		$now ??= time();
		return $this->active && ($this->expiresAt===null || $this->expiresAt>$now);
	}

	public function canSwitch(?int $now=null): bool { return $this->canSwitch && $this->isActive($now); }

	public function allows(string $permission, ?int $now=null): bool {
		if(!$this->isActive($now)){ return false; }
		$permission=Resource::normalizeName($permission);
		return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'tenant'=>$this->tenant,
			'roles'=>$this->roles,
			'permissions'=>$this->permissions,
			'active'=>$this->isActive(),
			'can_switch'=>$this->canSwitch(),
			'preferred'=>$this->preferred,
			'expires_at'=>$this->expiresAt,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return list<string> */
	private static function names(array $values, int $limit): array {
		$names=[];
		foreach($values as $value){
			if(count($names)>=$limit){ break; }
			if(!is_scalar($value)){ continue; }
			$name=$value==='*' ? '*' : Resource::normalizeName((string)$value);
			if($name!=='' && !in_array($name, $names, true)){ $names[]=$name; }
		}
		return $names;
	}
}
