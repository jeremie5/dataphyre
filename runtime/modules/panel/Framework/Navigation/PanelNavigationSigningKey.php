<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable HMAC key used for Panel navigation intents.
 *
 * The secret is deliberately omitted from JSON and manifest output. Key ids are
 * public rotation labels and must not themselves contain secret material.
 */
final class PanelNavigationSigningKey implements \JsonSerializable {

	public function __construct(
		private readonly string $id,
		private readonly string $secret,
		private readonly ?int $notBefore=null,
		private readonly ?int $expiresAt=null
	){
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $id)!==1){
			throw new \InvalidArgumentException('Navigation signing key ids must contain 1 to 64 safe characters.');
		}
		if(strlen($secret)<32){
			throw new \InvalidArgumentException('Navigation signing secrets must contain at least 32 bytes.');
		}
		if($notBefore!==null && $expiresAt!==null && $expiresAt<=$notBefore){
			throw new \InvalidArgumentException('Navigation signing key expiry must follow its activation time.');
		}
	}

	public function id(): string { return $this->id; }
	public function secret(): string { return $this->secret; }
	public function notBefore(): ?int { return $this->notBefore; }
	public function expiresAt(): ?int { return $this->expiresAt; }

	public function canSignAt(int $timestamp): bool {
		return ($this->notBefore===null || $timestamp>=$this->notBefore)
			&& ($this->expiresAt===null || $timestamp<$this->expiresAt);
	}

	/** @return array{id:string,algorithm:string,not_before:?int,expires_at:?int,secret_serialized:bool} */
	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'algorithm'=>'hmac-sha256',
			'not_before'=>$this->notBefore,
			'expires_at'=>$this->expiresAt,
			'secret_serialized'=>false,
		];
	}
}
