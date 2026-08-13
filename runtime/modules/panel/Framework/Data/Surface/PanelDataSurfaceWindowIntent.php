<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Opaque, client-carryable signed request for exactly one bounded surface window. */
final class PanelDataSurfaceWindowIntent implements \JsonSerializable {
	public function __construct(
		private readonly string $token,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly string $keyId
	){
		PanelDataSurfaceGuard::boundedString($token, 'intent', 16384);
		PanelDataSurfaceGuard::identifier($keyId, 'key id', 64);
		if($issuedAt<0 || $expiresAt<=$issuedAt){ throw new \InvalidArgumentException('Panel DataSurface intent timestamps are invalid.'); }
	}
	public function token(): string { return $this->token; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function keyId(): string { return $this->keyId; }
	public function jsonSerialize(): array { return ['type'=>'panel_data_surface_intent','version'=>1,'intent'=>$this->token,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt]; }
}
