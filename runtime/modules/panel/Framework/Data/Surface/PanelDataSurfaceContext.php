<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit host-owned panel, tenant, and principal scope for DataSurface IO. */
final class PanelDataSurfaceContext {
	private function __construct(
		private readonly string $panel,
		private readonly string $tenant,
		private readonly string $principal,
		private readonly PanelSecurityContext|array $host,
		private readonly string $correlationId
	){}

	/** @param PanelSecurityContext|array<string,mixed> $host */
	public static function fromTrusted(string $panel, PanelSecurityContext|array $host): self {
		$panel=PanelDataSurfaceGuard::identifier($panel, 'panel', 96);
		if($host instanceof PanelSecurityContext){
			$tenant=$host->tenantId();
			$principal=$host->actorId();
			$correlation=$host->attribute('correlation_id', '');
		}
		else{
			$tenant=$host['tenant_id'] ?? null;
			$principal=$host['principal_id'] ?? $host['actor_id'] ?? null;
			$correlation=$host['correlation_id'] ?? '';
		}
		if($tenant===null || $principal===null){ throw new \InvalidArgumentException('Panel DataSurface context requires trusted tenant and principal identifiers.'); }
		$tenant=PanelDataSurfaceGuard::boundedString($tenant, 'tenant', 256);
		$principal=PanelDataSurfaceGuard::boundedString($principal, 'principal', 256);
		$correlation=is_string($correlation) || is_int($correlation) ? preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)$correlation) ?? '' : '';
		return new self($panel, $tenant, $principal, $host, substr($correlation, 0, 128));
	}

	public function panel(): string { return $this->panel; }
	public function tenant(): string { return $this->tenant; }
	public function principal(): string { return $this->principal; }
	public function correlationId(): string { return $this->correlationId; }
	public function securityContext(): ?PanelSecurityContext { return $this->host instanceof PanelSecurityContext ? $this->host : null; }
	public function get(string $name, mixed $default=null): mixed {
		return $this->host instanceof PanelSecurityContext ? $this->host->attribute($name, $default) : ($this->host[$name] ?? $default);
	}

	/** Secret-free facts safe for pre-query envelopes and public manifests. */
	public function publicMetadata(): array {
		return ['bound'=>true,'panel'=>$this->panel,'has_tenant'=>true,'has_principal'=>true,'correlation_id'=>$this->correlationId];
	}
}
