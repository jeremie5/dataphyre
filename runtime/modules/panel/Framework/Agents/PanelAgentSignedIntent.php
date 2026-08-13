<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Opaque client-carried plan or approval authorization. */
final class PanelAgentSignedIntent implements \JsonSerializable {
	public function __construct(
		private readonly string $audience,
		private readonly string $token,
		private readonly int $issuedAt,
		private readonly int $expiresAt
	){
		PanelAgentGuard::identifier($audience, 'intent audience', 96);
		PanelAgentGuard::boundedString($token, 'signed intent', 32768);
		if($issuedAt<0 || $expiresAt<=$issuedAt){ throw new \InvalidArgumentException('Panel agent signed intent timestamps are invalid.'); }
	}
	public function audience(): string { return strtolower(trim($this->audience)); }
	public function token(): string { return $this->token; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function jsonSerialize(): array { return ['type'=>'panel_agent_signed_intent','version'=>1,'audience'=>$this->audience(),'intent'=>$this->token,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt]; }
}
