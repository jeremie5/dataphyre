<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable authorization-backed active tenant context. */
final class PanelTenantContext implements \JsonSerializable {

	/** @param array<string,mixed> $entitlement @param array<string,mixed> $meta */
	private function __construct(
		private readonly PanelRequest $request,
		private readonly ?PanelTenant $tenant,
		private readonly ?PanelTenantMembership $membership,
		private readonly bool $active,
		private readonly string $code,
		private readonly string $source,
		private readonly array $entitlement=[],
		private readonly array $meta=[]
	){}

	/** @param array<string,mixed> $entitlement @param array<string,mixed> $meta */
	public static function active(PanelRequest $request, PanelTenant $tenant, PanelTenantMembership $membership, string $source='request', array $entitlement=[], array $meta=[]): self {
		return new self(
			$request->withTenant($tenant->name()),
			$tenant,
			$membership,
			true,
			'active',
			Resource::normalizeName($source) ?: 'resolved',
			PanelTenantSanitizer::map($entitlement),
			PanelTenantSanitizer::map($meta)
		);
	}

	/** @param array<string,mixed> $meta */
	public static function inactive(PanelRequest $request, string $code='tenant_unresolved', string $source='none', array $meta=[]): self {
		return new self(
			$request->withTenant(null),
			null,
			null,
			false,
			Resource::normalizeName($code) ?: 'tenant_unresolved',
			Resource::normalizeName($source) ?: 'none',
			[],
			PanelTenantSanitizer::map($meta)
		);
	}

	public function request(): PanelRequest { return $this->request; }
	public function tenant(): ?PanelTenant { return $this->tenant; }
	public function membership(): ?PanelTenantMembership { return $this->membership; }
	public function tenantKey(): ?string { return $this->tenant?->name(); }
	public function isActive(): bool { return $this->active; }
	public function isAuthorized(): bool { return $this->active && $this->membership instanceof PanelTenantMembership; }
	public function code(): string { return $this->code; }
	public function source(): string { return $this->source; }
	/** @return array<string,mixed> */
	public function entitlement(): array { return $this->entitlement; }
	/** @return array<string,mixed> */
	public function meta(): array { return $this->meta; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'active'=>$this->active,
			'authorized'=>$this->isAuthorized(),
			'code'=>$this->code,
			'source'=>$this->source,
			'tenant'=>$this->tenant?->name(),
			'membership'=>$this->membership?->toArray(),
			'entitlement'=>$this->entitlement,
			'request'=>[
				'tenant'=>$this->request->tenantKey(),
				'has_user'=>$this->request->user()!==null,
			],
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }
}
