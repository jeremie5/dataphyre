<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bearer intent wrapper whose serialized form never includes the credential. */
final class PanelRealtimeIntent implements \JsonSerializable {
	public function __construct(
		private readonly string $token,
		private readonly string $purpose,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly string $keyId
	){
		if($token==='' || strlen($token)>4096){ throw new \InvalidArgumentException('Panel realtime intent token is invalid.'); }
		if(!in_array($purpose, ['subscribe','resume'], true) || $issuedAt<0 || $expiresAt<=$issuedAt){ throw new \InvalidArgumentException('Panel realtime intent metadata is invalid.'); }
	}
	public function token(): string { return $this->token; }
	public function purpose(): string { return $this->purpose; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function keyId(): string { return $this->keyId; }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_intent','version'=>1,'purpose'=>$this->purpose,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'key_id'=>$this->keyId,'credential_exposed'=>false]; }
}
