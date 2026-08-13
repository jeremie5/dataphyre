<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Explicit host-approved scope DTO; raw Panel authorization is never copied here. */
final class PanelHttpDataSourceScope implements \JsonSerializable {
	private readonly string $principal;
	private readonly string|int|null $tenant;
	/** @var array<string,mixed> */
	private readonly array $authorization;

	/** @param array<string,mixed> $authorization */
	private function __construct(string $principal, string|int|null $tenant, array $authorization){
		$this->principal=PanelHttpDataSourceValue::text($principal, 'Remote principal', 192);
		$this->tenant=is_string($tenant) ? PanelHttpDataSourceValue::text($tenant, 'Remote tenant', 192) : $tenant;
		$this->authorization=PanelHttpDataSourceValue::scopeMap($authorization);
	}

	/** @param array<string,mixed> $authorization */
	public static function make(string|int $principal, string|int|null $tenant=null, array $authorization=[]): self {
		return new self((string)$principal, $tenant, PanelHttpDataSourceValue::scopeMap($authorization));
	}

	public function principal(): string { return $this->principal; }
	public function tenant(): string|int|null { return $this->tenant; }
	/** @return array<string,mixed> */ public function authorization(): array { return $this->authorization; }
	public function fingerprint(): string { return hash('sha256', PanelHttpDataSourceValue::canonical($this->jsonSerialize())); }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['principal'=>$this->principal, 'tenant'=>$this->tenant, 'authorization'=>$this->authorization]; }
}
