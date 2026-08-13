<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HMAC keyring for short-lived, context and subscription bound connect and resume intents. */
final class PanelRealtimeIntentSigner implements \JsonSerializable {
	private const AUDIENCE='dp-panel-realtime';
	private const TYPE='DP-REALTIME';
	private const PAYLOAD_KEYS=['aud','cursor','exp','iat','nonce','panel','principal_tag','purpose','subscription_tag','tenant_tag','v'];
	/** @var array<string,string> */ private array $keys=[];
	private readonly string $currentKeyId;
	private ?\Closure $clock;

	/** @param array<string,string> $keys */
	public function __construct(array $keys, string $currentKeyId, ?callable $clock=null, private readonly int $leeway=5){
		if($leeway<0 || $leeway>60){ throw new \InvalidArgumentException('Panel realtime intent leeway must be between 0 and 60 seconds.'); }
		if($keys===[] || array_is_list($keys) || count($keys)>8){ throw new \InvalidArgumentException('Panel realtime intent keyring requires an object-like map of 1-8 keys.'); }
		foreach($keys as $keyId=>$secret){
			$keyId=PanelRealtimeGuard::identifier((string)$keyId, 'key id', 64);
			if(isset($this->keys[$keyId])){ throw new \InvalidArgumentException('Panel realtime intent key ids must remain unique after normalization.'); }
			if(!is_string($secret) || strlen($secret)<32){ throw new \InvalidArgumentException('Panel realtime signing keys must contain at least 32 bytes.'); }
			$this->keys[$keyId]=$secret;
		}
		$currentKeyId=PanelRealtimeGuard::identifier($currentKeyId, 'current key id', 64);
		if(!isset($this->keys[$currentKeyId])){ throw new \InvalidArgumentException('Panel realtime current signing key is not retained.'); }
		$this->currentKeyId=$currentKeyId;
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function issueSubscription(PanelRealtimeSubscription $subscription, int $ttl=300): PanelRealtimeIntent { return $this->issue('subscribe', $subscription, 0, $ttl); }
	public function issueResume(PanelRealtimeSubscription $subscription, int $cursor, int $ttl=300): PanelRealtimeIntent {
		if($cursor<0){ throw new \InvalidArgumentException('Panel realtime resume cursor cannot be negative.'); }
		return $this->issue('resume', $subscription, $cursor, $ttl);
	}

	public function verify(string $token, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context, string $expectedPurpose): PanelRealtimeIntentVerification {
		if(!in_array($expectedPurpose, ['subscribe','resume'], true) || !$subscription->belongsTo($context)){ throw $this->invalid(); }
		if($token==='' || strlen($token)>4096 || substr_count($token, '.')!==2){ throw $this->invalid(); }
		[$encodedHeader,$encodedPayload,$encodedSignature]=explode('.', $token, 3);
		$headerJson=PanelRealtimeGuard::decode($encodedHeader);
		if($headerJson===null || strlen($headerJson)>512){ throw $this->invalid(); }
		try{ $header=json_decode($headerJson, true, 8, JSON_THROW_ON_ERROR); }catch(\Throwable){ throw $this->invalid(); }
		if(!is_array($header) || array_keys($header)!==['alg','kid','typ','v'] || $header['alg']!=='HS256' || $header['typ']!==self::TYPE || $header['v']!==1 || !is_string($header['kid'])){ throw $this->invalid(); }
		$keyId=$header['kid']; $key=$this->keys[$keyId] ?? null;
		if($key===null){ throw $this->invalid(); }
		$signature=PanelRealtimeGuard::decode($encodedSignature); $input=$encodedHeader.'.'.$encodedPayload;
		if($signature===null || !hash_equals(hash_hmac('sha256', $input, $key, true), $signature)){ throw $this->invalid(); }
		$payloadJson=PanelRealtimeGuard::decode($encodedPayload);
		if($payloadJson===null || strlen($payloadJson)>8192){ throw $this->invalid(); }
		try{ $payload=json_decode($payloadJson, true, PanelRealtimeGuard::MAX_JSON_DEPTH+1, JSON_THROW_ON_ERROR); }catch(\Throwable){ throw $this->invalid(); }
		if(!is_array($payload) || array_is_list($payload)){ throw $this->invalid(); }
		$keys=array_keys($payload); sort($keys, SORT_STRING); $expected=self::PAYLOAD_KEYS; sort($expected, SORT_STRING);
		if($keys!==$expected){ throw $this->invalid(); }
		try{
			if($payload['v']!==1 || $payload['aud']!==self::AUDIENCE || $payload['purpose']!==$expectedPurpose || $payload['panel']!==$context->panel()){ throw $this->invalid(); }
			if(!is_int($payload['cursor']) || $payload['cursor']<0 || !is_int($payload['iat']) || !is_int($payload['exp']) || $payload['iat']<0 || $payload['exp']<=$payload['iat'] || $payload['exp']-$payload['iat']>3600){ throw $this->invalid(); }
			if($expectedPurpose==='subscribe' && $payload['cursor']!==0){ throw $this->invalid(); }
			if(!is_string($payload['tenant_tag']) || !is_string($payload['principal_tag']) || !is_string($payload['subscription_tag'])){ throw $this->invalid(); }
			if(!hash_equals($context->scopeTag('tenant', $key), $payload['tenant_tag']) || !hash_equals($context->scopeTag('principal', $key), $payload['principal_tag']) || !hash_equals($subscription->bindingTag($key), $payload['subscription_tag'])){ throw $this->invalid(); }
			$nonce=PanelRealtimeGuard::text($payload['nonce'], 'nonce', 32);
			if(preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){ throw $this->invalid(); }
			$now=$this->now();
			if($payload['iat']>$now+$this->leeway){ throw $this->invalid(); }
			if($payload['exp']<$now-$this->leeway){ throw new PanelRealtimeException('intent_expired', 401, 'Panel realtime intent has expired.'); }
		}
		catch(PanelRealtimeException $exception){ throw $exception; }
		catch(\Throwable){ throw $this->invalid(); }
		return new PanelRealtimeIntentVerification($expectedPurpose,$payload['cursor'],$payload['iat'],$payload['exp'],$keyId,$nonce);
	}

	public function jsonSerialize(): array {
		$keyIds=array_keys($this->keys); sort($keyIds, SORT_STRING);
		return ['type'=>'panel_realtime_intent_signer','version'=>1,'algorithm'=>'HS256','current_key_id'=>$this->currentKeyId,'verification_key_ids'=>$keyIds,'retained_key_count'=>max(0,count($keyIds)-1),'maximum_ttl_seconds'=>3600,'secrets_exposed'=>false];
	}

	private function issue(string $purpose, PanelRealtimeSubscription $subscription, int $cursor, int $ttl): PanelRealtimeIntent {
		if($ttl<30 || $ttl>3600){ throw new \InvalidArgumentException('Panel realtime intent TTL must be between 30 and 3600 seconds.'); }
		$now=$this->now(); $key=$this->keys[$this->currentKeyId]; $context=$subscription->context();
		$payload=['aud'=>self::AUDIENCE,'cursor'=>$cursor,'exp'=>$now+$ttl,'iat'=>$now,'nonce'=>bin2hex(random_bytes(16)),'panel'=>$context->panel(),'principal_tag'=>$context->scopeTag('principal',$key),'purpose'=>$purpose,'subscription_tag'=>$subscription->bindingTag($key),'tenant_tag'=>$context->scopeTag('tenant',$key),'v'=>1];
		$header=['alg'=>'HS256','kid'=>$this->currentKeyId,'typ'=>self::TYPE,'v'=>1];
		$input=PanelRealtimeGuard::encode(PanelRealtimeGuard::canonicalJson($header)).'.'.PanelRealtimeGuard::encode(PanelRealtimeGuard::canonicalJson($payload));
		$token=$input.'.'.PanelRealtimeGuard::encode(hash_hmac('sha256', $input, $key, true));
		if(strlen($token)>4096){ throw new \LengthException('Panel realtime intent exceeds 4096 bytes.'); }
		return new PanelRealtimeIntent($token,$purpose,$now,$now+$ttl,$this->currentKeyId);
	}
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel realtime signer clock must return a non-negative integer timestamp.'); } return $value; }
	private function invalid(): PanelRealtimeException { return new PanelRealtimeException('intent_invalid', 401, 'Panel realtime intent is invalid.'); }
}
