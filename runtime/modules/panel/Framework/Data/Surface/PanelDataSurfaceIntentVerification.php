<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Verified internal envelope. It is deliberately not serializable. */
final class PanelDataSurfaceIntentVerification {
	/** @param array<string,mixed> $safeState */
	public function __construct(
		private readonly string $keyId,
		private readonly string $nonce,
		private readonly string $panel,
		private readonly string $definition,
		private readonly ?string $definitionFingerprint,
		private readonly string $resource,
		private readonly string $source,
		private readonly PanelDataSurfaceType $surface,
		private readonly string $queryFingerprint,
		private readonly string $projectionFingerprint,
		private readonly array $safeState,
		private readonly PanelDataSurfaceRange $range,
		private readonly int $issuedAt,
		private readonly int $expiresAt
	){}
	public function keyId(): string { return $this->keyId; }
	public function nonce(): string { return $this->nonce; }
	public function panel(): string { return $this->panel; }
	public function definition(): string { return $this->definition; }
	public function definitionFingerprint():?string{return$this->definitionFingerprint;}
	public function resource(): string { return $this->resource; }
	public function source(): string { return $this->source; }
	public function surface(): PanelDataSurfaceType { return $this->surface; }
	public function queryFingerprint(): string { return $this->queryFingerprint; }
	public function projectionFingerprint(): string { return $this->projectionFingerprint; }
	/** @return array<string,mixed> */ public function safeState(): array { return $this->safeState; }
	public function range(): PanelDataSurfaceRange { return $this->range; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function expiresAt(): int { return $this->expiresAt; }

	/** State-blind facts suitable for a host authorization callback before any registry lookup. */
	public function authorizationEnvelope(): array {
		$envelope=[
				'operation'=>'window','panel'=>$this->panel,'definition'=>$this->definition,
			'resource'=>$this->resource,'source'=>$this->source,'surface'=>$this->surface->value,
			'query_fingerprint'=>$this->queryFingerprint,'projection_fingerprint'=>$this->projectionFingerprint,
			'range'=>$this->range->jsonSerialize(),'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,
		];if($this->definitionFingerprint!==null){$envelope['definition_fingerprint']=$this->definitionFingerprint;}return$envelope;
	}
}
