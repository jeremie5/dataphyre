<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Verified, scope-bound subscription or resume claim. */
final class PanelRealtimeIntentVerification implements \JsonSerializable {
	public function __construct(
		private readonly string $purpose,
		private readonly int $cursor,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly string $keyId,
		private readonly string $nonce
	){
		if(!in_array($purpose, ['subscribe','resume'], true) || $cursor<0 || $issuedAt<0 || $expiresAt<=$issuedAt || preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){ throw new \InvalidArgumentException('Panel realtime verified intent is invalid.'); }
	}
	public function purpose(): string { return $this->purpose; }
	public function cursor(): int { return $this->cursor; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function keyId(): string { return $this->keyId; }
	public function nonce(): string { return $this->nonce; }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_intent_verification','version'=>1,'purpose'=>$this->purpose,'cursor'=>$this->cursor,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'key_id'=>$this->keyId,'nonce_exposed'=>false,'scope_bound'=>true]; }
}
