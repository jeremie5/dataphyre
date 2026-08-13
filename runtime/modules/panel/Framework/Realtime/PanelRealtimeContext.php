<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Trusted host scope. Tenant and principal values never enter public manifests. */
final class PanelRealtimeContext implements \JsonSerializable {
	private function __construct(
		private readonly string $panel,
		private readonly string $tenant,
		private readonly string $principal,
		private readonly PanelSecurityContext|array $host,
		private readonly string $correlationId
	){}

	/** @param PanelSecurityContext|array<string,mixed> $host */
	public static function fromTrusted(string $panel, PanelSecurityContext|array $host): self {
		$panel=PanelRealtimeGuard::identifier($panel, 'panel', 96);
		if($host instanceof PanelSecurityContext){
			$tenant=$host->tenantId(); $principal=$host->actorId(); $correlation=$host->attribute('correlation_id', '');
		}
		else{
			$tenant=$host['tenant_id'] ?? null; $principal=$host['principal_id'] ?? $host['actor_id'] ?? null; $correlation=$host['correlation_id'] ?? '';
		}
		if($tenant===null || $principal===null){ throw new \InvalidArgumentException('Panel realtime context requires trusted tenant and principal identifiers.'); }
		$tenant=PanelRealtimeGuard::text($tenant, 'tenant', 256);
		$principal=PanelRealtimeGuard::text($principal, 'principal', 256);
		$correlation=is_string($correlation) || is_int($correlation) ? preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)$correlation) ?? '' : '';
		return new self($panel, $tenant, $principal, $host, substr($correlation, 0, 128));
	}

	public function panel(): string { return $this->panel; }
	public function tenant(): string { return $this->tenant; }
	public function principal(): string { return $this->principal; }
	public function correlationId(): string { return $this->correlationId; }
	public function get(string $name, mixed $default=null): mixed { return $this->host instanceof PanelSecurityContext ? $this->host->attribute($name, $default) : ($this->host[$name] ?? $default); }
	public function streamKey(string $channel): string { return hash('sha256', "panel-realtime-stream-v1\0{$this->panel}\0{$this->tenant}\0".PanelRealtimeGuard::identifier($channel, 'channel')); }
	public function scopeTag(string $domain, string $key): string { return PanelRealtimeGuard::encode(hash_hmac('sha256', "panel-realtime-{$domain}-v1\0{$this->panel}\0{$this->tenant}\0{$this->principal}", $key, true)); }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_context','version'=>1,'panel'=>$this->panel,'tenant_bound'=>true,'principal_bound'=>true,'correlation_id'=>$this->correlationId]; }
}
