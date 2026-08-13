<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Append-only, redacted, hash-chained lifecycle receipt. */
final class PanelAgentAuditReceipt implements \JsonSerializable {
	/** @param array<string,mixed> $details */
	private function __construct(
		private readonly int $sequence,
		private readonly string $event,
		private readonly string $scopeFingerprint,
		private readonly string $actorFingerprint,
		private readonly string $planHash,
		private readonly string $code,
		private readonly array $details,
		private readonly string $previousHash,
		private readonly int $occurredAt,
		private readonly string $hash
	){}

	/** @param array<string,mixed> $details */
	public static function create(int $sequence, string $event, PanelAgentRequestContext $actor, string $planHash, string $code, array $details, string $previousHash, int $occurredAt): self {
		if($sequence<1){ throw new \InvalidArgumentException('Panel agent audit sequence must be positive.'); }
		$event=PanelAgentGuard::identifier($event, 'audit event', 96);
		$code=PanelAgentGuard::identifier($code, 'audit code', 96);
		$planHash=PanelAgentGuard::digest($planHash, 'audit plan hash');
		if($sequence===1 && $previousHash!==''){ throw new \InvalidArgumentException('The first Panel agent audit receipt cannot have a previous hash.'); }
		if($sequence>1){ $previousHash=PanelAgentGuard::digest($previousHash, 'previous audit hash'); }
		if($occurredAt<0){ throw new \InvalidArgumentException('Panel agent audit timestamp is invalid.'); }
		$details=PanelAgentGuard::redact($details); PanelAgentGuard::assertJson($details, 65536);
		$payload=[
			'version'=>1,'sequence'=>$sequence,'event'=>$event,'scope_fingerprint'=>$actor->scopeFingerprint(),
			'actor_fingerprint'=>$actor->subjectFingerprint(),'plan_hash'=>$planHash,'code'=>$code,
			'details'=>$details,'previous_hash'=>$previousHash,'occurred_at'=>$occurredAt,
		];
		return new self(
			sequence: $sequence,
			event: $event,
			scopeFingerprint: $actor->scopeFingerprint(),
			actorFingerprint: $actor->subjectFingerprint(),
			planHash: $planHash,
			code: $code,
			details: $details,
			previousHash: $previousHash,
			occurredAt: $occurredAt,
			hash: hash('sha256', PanelAgentGuard::canonicalJson($payload)),
		);
	}

	/**
	 * Rehydrates an exact, already-redacted receipt without trusting PHP object
	 * serialization. The receipt's canonical digest is verified before an object
	 * is constructed; callers must still verify its position in the audit chain.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$keys=array_keys($payload); sort($keys,SORT_STRING);
		$expected=['actor_fingerprint','code','details','event','hash','occurred_at','plan_hash','previous_hash','scope_fingerprint','sequence','type','version'];
		if($keys!==$expected || ($payload['type'] ?? null)!=='panel_agent_audit_receipt' || ($payload['version'] ?? null)!==1){
			throw new \InvalidArgumentException('Panel agent audit receipt payload is invalid.');
		}
		if(!is_int($payload['sequence']) || $payload['sequence']<1 || !is_int($payload['occurred_at']) || $payload['occurred_at']<0 || !is_array($payload['details'])){
			throw new \InvalidArgumentException('Panel agent audit receipt payload is invalid.');
		}
		$event=PanelAgentGuard::identifier(is_string($payload['event']) ? $payload['event'] : '', 'audit event', 96);
		$code=PanelAgentGuard::identifier(is_string($payload['code']) ? $payload['code'] : '', 'audit code', 96);
		$scope=PanelAgentGuard::digest(is_string($payload['scope_fingerprint']) ? $payload['scope_fingerprint'] : '', 'audit scope fingerprint');
		$actor=PanelAgentGuard::digest(is_string($payload['actor_fingerprint']) ? $payload['actor_fingerprint'] : '', 'audit actor fingerprint');
		$plan=PanelAgentGuard::digest(is_string($payload['plan_hash']) ? $payload['plan_hash'] : '', 'audit plan hash');
		$hash=PanelAgentGuard::digest(is_string($payload['hash']) ? $payload['hash'] : '', 'audit receipt hash');
		$previous=$payload['previous_hash'];
		if($payload['sequence']===1){
			if($previous!==''){ throw new \InvalidArgumentException('The first Panel agent audit receipt cannot have a previous hash.'); }
		}else{
			$previous=PanelAgentGuard::digest(is_string($previous) ? $previous : '', 'previous audit hash');
		}
		if($event!==$payload['event'] || $code!==$payload['code'] || $scope!==$payload['scope_fingerprint'] || $actor!==$payload['actor_fingerprint'] || $plan!==$payload['plan_hash'] || $hash!==$payload['hash']){
			throw new \InvalidArgumentException('Panel agent audit receipt payload is not canonical.');
		}
		PanelAgentGuard::assertJson($payload['details'],65536);
		$redacted=PanelAgentGuard::redact($payload['details']);
		if(!hash_equals(PanelAgentGuard::canonicalJson($payload['details']),PanelAgentGuard::canonicalJson($redacted))){
			throw new \InvalidArgumentException('Panel agent audit receipt details are not redacted.');
		}
		$canonical=$payload; unset($canonical['type'],$canonical['hash']);
		if(!hash_equals($hash,hash('sha256',PanelAgentGuard::canonicalJson($canonical)))){
			throw new \InvalidArgumentException('Panel agent audit receipt hash is invalid.');
		}
		return new self(
			sequence:$payload['sequence'],event:$event,scopeFingerprint:$scope,actorFingerprint:$actor,
			planHash:$plan,code:$code,details:$payload['details'],previousHash:$previous,
			occurredAt:$payload['occurred_at'],hash:$hash,
		);
	}

	public function sequence(): int { return $this->sequence; }
	public function event(): string { return $this->event; }
	public function scopeFingerprint(): string { return $this->scopeFingerprint; }
	public function actorFingerprint(): string { return $this->actorFingerprint; }
	public function planHash(): string { return $this->planHash; }
	public function code(): string { return $this->code; }
	/** @return array<string,mixed> */ public function details(): array { return $this->details; }
	public function previousHash(): string { return $this->previousHash; }
	public function occurredAt(): int { return $this->occurredAt; }
	public function hash(): string { return $this->hash; }

	public function verify(string $expectedPreviousHash): bool {
		if(!hash_equals($expectedPreviousHash, $this->previousHash)){ return false; }
		$payload=$this->jsonSerialize(); unset($payload['type'],$payload['hash']);
		return hash_equals($this->hash, hash('sha256', PanelAgentGuard::canonicalJson($payload)));
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_audit_receipt','version'=>1,'sequence'=>$this->sequence,'event'=>$this->event,
			'scope_fingerprint'=>$this->scopeFingerprint,'actor_fingerprint'=>$this->actorFingerprint,
			'plan_hash'=>$this->planHash,'code'=>$this->code,'details'=>$this->details,
			'previous_hash'=>$this->previousHash,'occurred_at'=>$this->occurredAt,'hash'=>$this->hash,
		];
	}
}
