<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Canonical HMAC issuer and verifier for version-one navigation intents. */
final class PanelNavigationIntentSigner implements \JsonSerializable {
	public const MAX_TOKEN_BYTES=8192;
	private const HEADER=['alg'=>'HS256','typ'=>'DP-NAV','v'=>1];

	public function __construct(
		private readonly PanelNavigationKeyProvider $keys,
		private readonly ?PanelNavigationReplayGuard $replayGuard=null,
		private readonly int $leeway=15,
		private readonly int $maxTokenBytes=self::MAX_TOKEN_BYTES
	){
		if($leeway<0 || $leeway>300){ throw new \InvalidArgumentException('Navigation intent leeway must be between 0 and 300 seconds.'); }
		if($maxTokenBytes<512 || $maxTokenBytes>32768){ throw new \InvalidArgumentException('Navigation intent token size limits must be between 512 and 32768 bytes.'); }
	}

	public function issue(PanelNavigationIntent $intent, ?int $now=null): string {
		$now=$now ?? time();
		$key=$this->keys->current($now);
		if(!$key instanceof PanelNavigationSigningKey){ throw new \RuntimeException('No active Panel navigation signing key is available.'); }
		$header=self::HEADER+['kid'=>$key->id()];
		$encodedHeader=self::encode(self::canonicalJson($header));
		$encodedPayload=self::encode(self::canonicalJson($intent->payload()));
		$input=$encodedHeader.'.'.$encodedPayload;
		$token=$input.'.'.self::encode(hash_hmac('sha256', $input, $key->secret(), true));
		if(strlen($token)>$this->maxTokenBytes){ throw new \LengthException('Navigation intent exceeds its configured token size limit.'); }
		return $token;
	}

	/**
	 * @param array<string,mixed> $expected Expected audience, panel, surface, bindings, operation, outcome, target, time, and replay settings.
	 */
	public function verify(string $token, array $expected=[]): PanelNavigationIntentVerification {
		if(strlen($token)>$this->maxTokenBytes){ return PanelNavigationIntentVerification::rejected('oversized'); }
		if($token==='' || substr_count($token, '.')!==2){ return PanelNavigationIntentVerification::rejected('malformed'); }
		[$encodedHeader,$encodedPayload,$encodedSignature]=explode('.', $token, 3);
		$headerJson=self::decode($encodedHeader);
		$payloadJson=self::decode($encodedPayload);
		$signature=self::decode($encodedSignature);
		if($headerJson===null || $payloadJson===null || $signature===null || strlen($signature)!==32){ return PanelNavigationIntentVerification::rejected('malformed'); }
		try{
			$header=json_decode($headerJson, true, 16, JSON_THROW_ON_ERROR);
			$payload=json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR);
		}
		catch(\JsonException){ return PanelNavigationIntentVerification::rejected('malformed'); }
		if(!is_array($header) || !is_array($payload)){ return PanelNavigationIntentVerification::rejected('malformed'); }
		$allowedHeader=['alg','typ','v','kid'];
		foreach(array_keys($header) as $claim){ if(!is_string($claim) || !in_array($claim, $allowedHeader, true)){ return PanelNavigationIntentVerification::rejected('unsupported'); } }
		if(($header['alg'] ?? null)!=='HS256' || ($header['typ'] ?? null)!=='DP-NAV' || ($header['v'] ?? null)!==1){ return PanelNavigationIntentVerification::rejected('unsupported'); }
		$keyId=is_string($header['kid'] ?? null) ? $header['kid'] : '';
		if($keyId==='' || self::encode(self::canonicalJson($header))!==$encodedHeader || self::encode(self::canonicalJson($payload))!==$encodedPayload){
			return PanelNavigationIntentVerification::rejected('malformed', $keyId!=='' ? $keyId : null);
		}
		$key=$this->keys->find($keyId);
		if(!$key instanceof PanelNavigationSigningKey){ return PanelNavigationIntentVerification::rejected('missing_key', $keyId); }
		$input=$encodedHeader.'.'.$encodedPayload;
		$calculated=hash_hmac('sha256', $input, $key->secret(), true);
		if(!hash_equals($calculated, $signature)){ return PanelNavigationIntentVerification::rejected('invalid_signature', $keyId); }
		try{ $intent=PanelNavigationIntent::fromPayload($payload); }
		catch(\InvalidArgumentException){ return PanelNavigationIntentVerification::rejected('invalid_claims', $keyId); }
		if(($key->notBefore()!==null && $intent->issuedAt()<$key->notBefore())
			|| ($key->expiresAt()!==null && $intent->issuedAt()>=$key->expiresAt())){
			return PanelNavigationIntentVerification::rejected('invalid_claims', $keyId);
		}
		$now=(int)($expected['now'] ?? time());
		if($intent->issuedAt()>$now+$this->leeway){ return PanelNavigationIntentVerification::rejected('issued_in_future', $keyId); }
		if($intent->notBefore()>$now+$this->leeway){ return PanelNavigationIntentVerification::rejected('not_yet_valid', $keyId); }
		if($intent->expiresAt()<=$now-$this->leeway){ return PanelNavigationIntentVerification::rejected('expired', $keyId); }
		if(!$this->matchesExpected($intent, $expected)){ return PanelNavigationIntentVerification::rejected('context_mismatch', $keyId); }
		if(isset($expected['return_target'])){
			$target=PanelNavigationTarget::normalize((string)$expected['return_target']);
			if($target===null || !hash_equals($intent->returnTarget(), $target)){ return PanelNavigationIntentVerification::rejected('target_mismatch', $keyId); }
		}
		if(($expected['consume'] ?? false)===true && $this->replayGuard instanceof PanelNavigationReplayGuard){
			$context=[
				'now'=>$now,
				'panel'=>$intent->panel(),
				'surface'=>$intent->surface(),
				'operation'=>$intent->operation(),
				'outcome'=>$intent->outcome(),
				'key_id'=>$keyId,
			];
			if(!$this->replayGuard->accept($intent->nonce(), $intent->expiresAt(), $context)){ return PanelNavigationIntentVerification::rejected('replay', $keyId); }
		}
		return PanelNavigationIntentVerification::accepted($intent, $keyId);
	}

	public function keyProvider(): PanelNavigationKeyProvider { return $this->keys; }
	public function replayGuard(): ?PanelNavigationReplayGuard { return $this->replayGuard; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_navigation_intent_signer',
			'version'=>1,
			'algorithm'=>'hmac-sha256',
			'canonical_encoding'=>true,
			'max_token_bytes'=>$this->maxTokenBytes,
			'leeway_seconds'=>$this->leeway,
			'replay_guard'=>$this->replayGuard!==null,
			'key_provider'=>$this->keys->manifest(),
			'secrets_serialized'=>false,
		];
	}

	/** @param array<string,mixed> $expected */
	private function matchesExpected(PanelNavigationIntent $intent, array $expected): bool {
		$claims=[
			'audience'=>$intent->audience(),
			'panel'=>$intent->panel(),
			'surface'=>$intent->surface(),
			'tenant_binding'=>$intent->tenantBinding(),
			'principal_binding'=>$intent->principalBinding(),
			'operation'=>$intent->operation(),
			'outcome'=>$intent->outcome(),
		];
		foreach($claims as $name=>$actual){
			if(!array_key_exists($name, $expected)){ continue; }
			$expectedValue=(string)$expected[$name];
			if(in_array($name, ['tenant_binding','principal_binding'], true) && preg_match('/^[a-f0-9]{64}$/D', $expectedValue)!==1){
				$expectedValue=hash('sha256', trim($expectedValue)!=='' ? trim($expectedValue) : 'guest');
			}
			if(!hash_equals($actual, $expectedValue)){ return false; }
		}
		return true;
	}

	/** @param array<mixed> $value */
	public static function canonicalJson(array $value): string {
		$value=self::canonicalize($value);
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonicalize(array $value): array {
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){
			if(is_array($item)){ $value[$key]=self::canonicalize($item); }
			elseif(!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item!==null){
				throw new \InvalidArgumentException('Navigation intent payloads support only JSON scalar values and arrays.');
			}
		}
		return $value;
	}

	private static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

	private static function decode(string $value): ?string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ return null; }
		$padding=(4-(strlen($value)%4))%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		if(!is_string($decoded) || !hash_equals(self::encode($decoded), $value)){ return null; }
		return $decoded;
	}
}
