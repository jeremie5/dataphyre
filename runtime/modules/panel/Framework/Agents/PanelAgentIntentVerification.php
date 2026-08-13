<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Verified internal claims. Deliberately not JSON-serializable. */
final class PanelAgentIntentVerification {
	public function __construct(
		private readonly string $audience,
		private readonly string $keyId,
		private readonly string $nonce,
		private readonly string $scopeFingerprint,
		private readonly string $subjectFingerprint,
		private readonly string $planHash,
		private readonly string $catalogFingerprint,
		private readonly string $policyFingerprint,
		private readonly string $parentNonce,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly ?string $confirmationVerifierFingerprint=null
	){}
	public function audience(): string { return $this->audience; }
	public function keyId(): string { return $this->keyId; }
	public function nonce(): string { return $this->nonce; }
	public function scopeFingerprint(): string { return $this->scopeFingerprint; }
	public function subjectFingerprint(): string { return $this->subjectFingerprint; }
	public function planHash(): string { return $this->planHash; }
	public function catalogFingerprint(): string { return $this->catalogFingerprint; }
	public function policyFingerprint(): string { return $this->policyFingerprint; }
	public function confirmationVerifierFingerprint(): ?string { return $this->confirmationVerifierFingerprint; }
	public function parentNonce(): string { return $this->parentNonce; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }
}
