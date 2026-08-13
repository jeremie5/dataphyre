<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Verified, scope-bound Studio collaboration capability claims. */
final class PanelStudioCollaborationIntentVerification implements \JsonSerializable {
	/** @var list<string> */
	private readonly array $abilities;

	/** @param list<string> $abilities */
	public function __construct(
		array $abilities,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly string $keyId,
		private readonly string $nonce,
	){
		if($abilities===[]||!array_is_list($abilities)||count($abilities)>4){
			throw new \InvalidArgumentException('Verified Studio collaboration abilities are invalid.');
		}
		$normalized=[];
		foreach($abilities as $ability){
			if(!is_string($ability)||!in_array($ability,['delta','mutate','presence','typing'],true)){
				throw new \InvalidArgumentException('Verified Studio collaboration abilities are invalid.');
			}
			$normalized[$ability]=true;
		}
		$values=array_keys($normalized);
		sort($values,SORT_STRING);
		$this->abilities=$values;
		if($issuedAt<1||$expiresAt<=$issuedAt||$expiresAt-$issuedAt>900){
			throw new \InvalidArgumentException('Verified Studio collaboration lifetime is invalid.');
		}
		if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $keyId)!==1||preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){
			throw new \InvalidArgumentException('Verified Studio collaboration proof metadata is invalid.');
		}
	}

	/** @return list<string> */ public function abilities():array{return $this->abilities;}
	public function allows(string $ability):bool{return in_array($ability, $this->abilities, true);}
	public function issuedAt():int{return $this->issuedAt;}
	public function expiresAt():int{return $this->expiresAt;}
	public function keyId():string{return $this->keyId;}
	public function nonce():string{return $this->nonce;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_studio_collaboration_intent_verification',
			'version'=>1,
			'abilities'=>$this->abilities,
			'issued_at'=>$this->issuedAt,
			'expires_at'=>$this->expiresAt,
			'key_id'=>$this->keyId,
			'nonce_digest'=>hash('sha256', $this->nonce),
		];
	}
}
