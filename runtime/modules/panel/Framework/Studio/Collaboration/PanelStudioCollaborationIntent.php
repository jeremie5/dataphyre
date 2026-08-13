<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Short-lived Studio collaboration bearer intent; normal serialization omits its token. */
final class PanelStudioCollaborationIntent implements \JsonSerializable {
	/** @var list<string> */
	private readonly array $abilities;

	/** @param list<string> $abilities */
	public function __construct(
		private readonly string $token,
		array $abilities,
		private readonly int $issuedAt,
		private readonly int $expiresAt,
		private readonly string $keyId,
	){
		if(strlen($token)<32||strlen($token)>4096||substr_count($token, '.')!==2){
			throw new \InvalidArgumentException('Studio collaboration intent token is invalid.');
		}
		$this->abilities=self::normalizeAbilities($abilities);
		if($issuedAt<1||$expiresAt<=$issuedAt||$expiresAt-$issuedAt>900){
			throw new \InvalidArgumentException('Studio collaboration intent lifetime is invalid.');
		}
		if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $keyId)!==1){
			throw new \InvalidArgumentException('Studio collaboration intent key id is invalid.');
		}
	}

	public function token():string{return $this->token;}
	/** @return list<string> */ public function abilities():array{return $this->abilities;}
	public function issuedAt():int{return $this->issuedAt;}
	public function expiresAt():int{return $this->expiresAt;}
	public function keyId():string{return $this->keyId;}

	/** Explicit browser-delivery model. @return array<string,mixed> */
	public function browserModel():array {
		return [
			'type'=>'panel_studio_collaboration_browser_intent',
			'version'=>1,
			'token'=>$this->token,
			'abilities'=>$this->abilities,
			'issued_at'=>$this->issuedAt,
			'expires_at'=>$this->expiresAt,
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_studio_collaboration_intent',
			'version'=>1,
			'abilities'=>$this->abilities,
			'issued_at'=>$this->issuedAt,
			'expires_at'=>$this->expiresAt,
			'key_id'=>$this->keyId,
			'token_serialized'=>false,
		];
	}

	/** @param list<string> $abilities @return list<string> */
	private static function normalizeAbilities(array $abilities):array {
		if(!array_is_list($abilities)||$abilities===[]||count($abilities)>4){
			throw new \InvalidArgumentException('Studio collaboration intent abilities are invalid.');
		}
		$allowed=['delta','mutate','presence','typing'];
		$normalized=[];
		foreach($abilities as $ability){
			if(!is_string($ability)||!in_array($ability, $allowed, true)){
				throw new \InvalidArgumentException('Studio collaboration intent ability is invalid.');
			}
			$normalized[$ability]=true;
		}
		$result=array_keys($normalized);
		sort($result, SORT_STRING);
		return $result;
	}
}
