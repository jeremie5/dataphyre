<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Rotating-key signer for scope-bound plan and parent-bound approval intents. */
final class PanelAgentIntentSigner implements \JsonSerializable {
	private const TYPE='DP-AGENT';
	private const PLAN_AUDIENCE='dp-panel-agent-plan';
	private const APPROVAL_AUDIENCE='dp-panel-agent-approval';
	private const PAYLOAD_KEYS=['aud','catalog','confirmation','exp','iat','nonce','parent','plan','policy','scope','subject','v'];
	/** @var array<string,string> */ private array $keys=[];
	private readonly string $currentKeyId;
	private ?\Closure $clock;

	/** @param array<string,string> $keys */
	public function __construct(array $keys, string $currentKeyId, ?callable $clock=null, private readonly int $leeway=5) {
		if($leeway<0 || $leeway>60){ throw new \InvalidArgumentException('Panel agent intent leeway must be between zero and 60 seconds.'); }
		if($keys===[] || array_is_list($keys) || count($keys)>8){ throw new \InvalidArgumentException('Panel agent intent keyring requires an object-like map of 1-8 keys.'); }
		foreach($keys as $keyId=>$secret){
			$keyId=PanelAgentGuard::identifier((string)$keyId, 'key id', 64);
			if(isset($this->keys[$keyId])){ throw new \InvalidArgumentException('Panel agent intent key ids must remain unique after normalization.'); }
			if(!is_string($secret) || strlen($secret)<32){ throw new \InvalidArgumentException('Panel agent signing keys must contain at least 32 bytes.'); }
			$this->keys[$keyId]=$secret;
		}
		$currentKeyId=PanelAgentGuard::identifier($currentKeyId, 'current key id', 64);
		if(!isset($this->keys[$currentKeyId])){ throw new \InvalidArgumentException('Panel agent current signing key is not in the keyring.'); }
		$this->currentKeyId=$currentKeyId;
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function issuePlan(PanelAgentPlan $plan, PanelAgentRequestContext $context, int $ttl=300): PanelAgentSignedIntent {
		$this->assertPlanContext($plan, $context);
		return $this->issue(self::PLAN_AUDIENCE, $plan, $context->subjectFingerprint(), '', $ttl);
	}

	public function issueApproval(PanelAgentPlan $plan, PanelAgentIntentVerification $planIntent, PanelAgentRequestContext $approver, int $ttl=300): PanelAgentSignedIntent {
		if($planIntent->audience()!==self::PLAN_AUDIENCE || !hash_equals($plan->hash(), $planIntent->planHash()) || !hash_equals($plan->scopeFingerprint(), $planIntent->scopeFingerprint())){
			throw new PanelAgentException('intent_parent_invalid', 'Panel agent approval parent intent is invalid.', 401);
		}
		return $this->issue(self::APPROVAL_AUDIENCE, $plan, $approver->subjectFingerprint(), $planIntent->nonce(), $ttl);
	}

	public function verifyPlan(string $token, PanelAgentPlan $plan, PanelAgentRequestContext $context): PanelAgentIntentVerification {
		$this->assertPlanContext($plan, $context);
		return $this->verify($token, self::PLAN_AUDIENCE, $plan, $context, '', $context->subjectFingerprint());
	}

	public function verifyApproval(string $token, PanelAgentPlan $plan, PanelAgentRequestContext $executionContext, string $parentNonce): PanelAgentIntentVerification {
		$this->assertPlanContext($plan, $executionContext);
		return $this->verify($token, self::APPROVAL_AUDIENCE, $plan, $executionContext, $parentNonce, null);
	}

	public function jsonSerialize(): array {
		$keyIds=array_keys($this->keys); sort($keyIds, SORT_STRING);
		return [
			'type'=>'panel_agent_intent_signer','version'=>1,'algorithm'=>'HS256','current_key_id'=>$this->currentKeyId,
			'verification_key_ids'=>$keyIds,'retained_key_count'=>max(0, count($keyIds)-1),'maximum_ttl'=>900,
			'plan_audience'=>self::PLAN_AUDIENCE,'approval_audience'=>self::APPROVAL_AUDIENCE,'secrets_exposed'=>false,
		];
	}

	private function issue(string $audience, PanelAgentPlan $plan, string $subject, string $parent, int $ttl): PanelAgentSignedIntent {
		if($ttl<30 || $ttl>900){ throw new \InvalidArgumentException('Panel agent intent TTL must be between 30 and 900 seconds.'); }
		$now=$this->now(); $key=$this->keys[$this->currentKeyId];
		$payload=[
			'v'=>1,'aud'=>$audience,'scope'=>$plan->scopeFingerprint(),'subject'=>$subject,'plan'=>$plan->hash(),
			'catalog'=>$plan->catalogFingerprint(),'policy'=>$plan->policyFingerprint(),'confirmation'=>$plan->confirmationVerifierFingerprint() ?? '','parent'=>$parent,
			'iat'=>$now,'exp'=>$now+$ttl,'nonce'=>bin2hex(random_bytes(16)),
		];
		$header=['alg'=>'HS256','kid'=>$this->currentKeyId,'typ'=>self::TYPE,'v'=>1];
		$input=$this->encode(PanelAgentGuard::canonicalJson($header)).'.'.$this->encode(PanelAgentGuard::canonicalJson($payload));
		$token=$input.'.'.$this->encode(hash_hmac('sha256', $input, $key, true));
		if(strlen($token)>32768){ throw new \LengthException('Panel agent signed intent exceeds its byte limit.'); }
		return new PanelAgentSignedIntent($audience, $token, $now, $now+$ttl);
	}

	private function verify(string $token, string $audience, PanelAgentPlan $plan, PanelAgentRequestContext $context, string $parent, ?string $subject): PanelAgentIntentVerification {
		if($token==='' || strlen($token)>32768 || substr_count($token, '.')!==2){ throw $this->invalid(); }
		[$encodedHeader,$encodedPayload,$encodedSignature]=explode('.', $token, 3);
		$headerJson=$this->decode($encodedHeader);
		if($headerJson===null || strlen($headerJson)>512){ throw $this->invalid(); }
		try{ $header=json_decode($headerJson, true, 8, JSON_THROW_ON_ERROR); }catch(\JsonException){ throw $this->invalid(); }
		if(!is_array($header) || array_keys($header)!==['alg','kid','typ','v'] || $header['alg']!=='HS256' || $header['typ']!==self::TYPE || $header['v']!==1 || !is_string($header['kid'])){ throw $this->invalid(); }
		$key=$this->keys[$header['kid']] ?? null; $signature=$this->decode($encodedSignature); $input=$encodedHeader.'.'.$encodedPayload;
		if($key===null || $signature===null || !hash_equals(hash_hmac('sha256', $input, $key, true), $signature)){ throw $this->invalid(); }
		$payloadJson=$this->decode($encodedPayload);
		if($payloadJson===null || strlen($payloadJson)>4096){ throw $this->invalid(); }
		try{ $payload=json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR); }catch(\JsonException){ throw $this->invalid(); }
		if(!is_array($payload) || array_is_list($payload)){ throw $this->invalid(); }
		$keys=array_keys($payload); sort($keys, SORT_STRING); $expected=self::PAYLOAD_KEYS; sort($expected, SORT_STRING);
		if($keys!==$expected){ throw $this->invalid(); }
		try{
			if($payload['v']!==1 || $payload['aud']!==$audience || !is_string($payload['nonce']) || preg_match('/^[a-f0-9]{32}$/D', $payload['nonce'])!==1 || !is_string($payload['parent']) || !is_string($payload['subject'])){ throw $this->invalid(); }
			$scope=PanelAgentGuard::digest((string)$payload['scope'], 'intent scope');
			$planHash=PanelAgentGuard::digest((string)$payload['plan'], 'intent plan');
			$catalog=PanelAgentGuard::digest((string)$payload['catalog'], 'intent catalog');
			$policy=PanelAgentGuard::digest((string)$payload['policy'], 'intent policy');
			if(!is_string($payload['confirmation'])){ throw $this->invalid(); }
			$confirmation=$payload['confirmation']==='' ? null : PanelAgentGuard::digest($payload['confirmation'], 'intent confirmation verifier');
			$subjectFingerprint=PanelAgentGuard::digest($payload['subject'], 'intent subject');
			if(!is_int($payload['iat']) || !is_int($payload['exp']) || $payload['iat']<0 || $payload['exp']<=$payload['iat'] || $payload['exp']-$payload['iat']>900){ throw $this->invalid(); }
			if(!hash_equals($context->scopeFingerprint(), $scope) || !hash_equals($plan->hash(), $planHash) || !hash_equals($plan->catalogFingerprint(), $catalog) || !hash_equals($plan->policyFingerprint(), $policy) || !hash_equals($plan->confirmationVerifierFingerprint() ?? '', $payload['confirmation']) || !hash_equals($parent, $payload['parent']) || ($subject!==null && !hash_equals($subject, $subjectFingerprint))){ throw $this->invalid(); }
			$now=$this->now();
			if($payload['iat']>$now+$this->leeway){ throw $this->invalid(); }
			if($payload['exp']<$now-$this->leeway){ throw new PanelAgentException('intent_expired', 'Panel agent signed intent has expired.', 401); }
		}catch(PanelAgentException $exception){ throw $exception; }catch(\Throwable){ throw $this->invalid(); }
		return new PanelAgentIntentVerification($audience, $header['kid'], $payload['nonce'], $scope, $subjectFingerprint, $planHash, $catalog, $policy, $payload['parent'], $payload['iat'], $payload['exp'], $confirmation);
	}

	private function assertPlanContext(PanelAgentPlan $plan, PanelAgentRequestContext $context): void {
		if(!hash_equals($plan->scopeFingerprint(), $context->scopeFingerprint()) || !hash_equals($plan->subjectFingerprint(), $context->subjectFingerprint())){ throw new PanelAgentException('scope_mismatch', 'Panel agent plan scope does not match the request context.', 403); }
	}
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel agent intent clock must return a non-negative integer timestamp.'); } return $value; }
	private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
	private function decode(string $value): ?string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ return null; }
		$padding=(4-strlen($value)%4)%4; $decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		return is_string($decoded) ? $decoded : null;
	}
	private function invalid(): PanelAgentException { return new PanelAgentException('intent_invalid', 'Panel agent signed intent is invalid.', 401); }
}
