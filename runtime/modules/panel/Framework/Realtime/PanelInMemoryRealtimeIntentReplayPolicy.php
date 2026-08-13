<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded process-local nonce consumer for tests and single-process hosts. */
final class PanelInMemoryRealtimeIntentReplayPolicy implements PanelRealtimeSubscriptionIntentReplayPolicy {
	/** @var array<string,int> */ private array $consumed=[];
	private ?\Closure $clock;

	public function __construct(private readonly int $maximumEntries=10000, private readonly int $retentionGraceSeconds=60, ?callable $clock=null){
		if($maximumEntries<1 || $maximumEntries>1000000 || $retentionGraceSeconds<0 || $retentionGraceSeconds>300){ throw new \InvalidArgumentException('Panel realtime intent replay policy bounds are invalid.'); }
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function consume(PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool {
		if($intent->purpose()!=='subscribe' || !$subscription->belongsTo($context)){ throw new \InvalidArgumentException('Panel realtime replay policy accepts only matching subscription intents.'); }
		$now=$this->now(); $this->purge($now);
		if($intent->expiresAt()<$now){ throw new PanelRealtimeException('intent_expired',401,'Panel realtime intent has expired.'); }
		$nonce=$intent->nonce(); if(isset($this->consumed[$nonce])){ return false; }
		if(count($this->consumed)>=$this->maximumEntries){ throw new PanelRealtimeException('replay_policy_capacity',503,'Panel realtime replay protection is at capacity.',true); }
		$this->consumed[$nonce]=$intent->expiresAt()+$this->retentionGraceSeconds;
		return true;
	}

	public function jsonSerialize(): array { return ['type'=>'panel_realtime_subscription_intent_replay_policy','version'=>1,'adapter'=>'memory','mode'=>'single_use_initial_connect','active_nonces'=>count($this->consumed),'maximum_entries'=>$this->maximumEntries,'retention_grace_seconds'=>$this->retentionGraceSeconds,'atomic_process_local'=>true,'durable'=>false,'distributed'=>false,'nonce_values_exposed'=>false,'resume_intents_consumed'=>false]; }
	private function purge(int $now): void { foreach($this->consumed as $nonce=>$expiresAt){ if($expiresAt<$now){ unset($this->consumed[$nonce]); } } }
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel realtime replay policy clock must return a non-negative integer timestamp.'); } return $value; }
}
