<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable non-secret descriptor safe to persist in a leased operation queue. */
final class PanelAgentWorkflowJob implements \JsonSerializable {
	private readonly string $reference;
	private readonly string $executionFingerprint;
	private readonly string $resolverFingerprint;
	private readonly string $planHash;
	private readonly string $scopeFingerprint;
	private readonly string $queue;
	private readonly string $name;
	private readonly string $fingerprint;

	private function __construct(
		string $reference,
		string $executionFingerprint,
		string $resolverFingerprint,
		string $planHash,
		string $scopeFingerprint,
		private readonly int $expiresAt,
		string $queue,
		string $name,
		private readonly int $maxAttempts,
	){
		$this->reference=PanelAgentGuard::digest($reference,'workflow job reference');
		$this->executionFingerprint=PanelAgentGuard::digest($executionFingerprint,'workflow execution fingerprint');
		$this->resolverFingerprint=PanelAgentGuard::digest($resolverFingerprint,'workflow resolver fingerprint');
		$this->planHash=PanelAgentGuard::digest($planHash,'workflow job plan hash');
		$this->scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'workflow job scope fingerprint');
		if($expiresAt<1){ throw new \InvalidArgumentException('Panel agent workflow job expiry must be positive.'); }
		$this->queue=PanelAgentGuard::identifier($queue,'workflow queue',96);
		$this->name=PanelAgentGuard::boundedString($name,'workflow job name',200);
		if($maxAttempts<1 || $maxAttempts>20){ throw new \InvalidArgumentException('Panel agent workflow jobs allow between one and 20 attempts.'); }
		$this->fingerprint=hash('sha256',PanelAgentGuard::canonicalJson($this->canonical()));
	}

	/** @param array<string,mixed> $options */
	public static function make(string $reference,PanelAgentDeferredExecution $execution,string $resolverFingerprint,array $options=[]):self{
		$unknown=array_diff(array_keys($options),['queue','name','max_attempts']);
		if($unknown!==[]){ throw new \InvalidArgumentException('Panel agent workflow job options contain unsupported fields.'); }
		$reference=PanelAgentGuard::boundedString($reference,'workflow job reference',512);
		return new self(
			hash('sha256',"panel-agent-workflow-reference-v1\0".$reference),$execution->fingerprint(),$resolverFingerprint,$execution->plan()->hash(),$execution->context()->scopeFingerprint(),$execution->expiresAt(),
			(string)($options['queue']??'agent_workflows'),(string)($options['name']??'Deferred agent workflow'),(int)($options['max_attempts']??3),
		);
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self{
		$keys=array_keys($payload);sort($keys,SORT_STRING);
		$expected=['execution_fingerprint','expires_at','job_fingerprint','max_attempts','name','plan_hash','queue','reference','resolver_fingerprint','scope_fingerprint','type','version'];
		if($keys!==$expected || ($payload['type']??null)!=='panel_agent_workflow_job' || ($payload['version']??null)!==1 || !is_int($payload['expires_at']??null) || !is_int($payload['max_attempts']??null)){
			throw new \InvalidArgumentException('Panel agent workflow job payload is invalid.');
		}
		$job=new self(
			is_string($payload['reference'])?$payload['reference']:'',is_string($payload['execution_fingerprint'])?$payload['execution_fingerprint']:'',
			is_string($payload['resolver_fingerprint'])?$payload['resolver_fingerprint']:'',is_string($payload['plan_hash'])?$payload['plan_hash']:'',
			is_string($payload['scope_fingerprint'])?$payload['scope_fingerprint']:'',$payload['expires_at'],is_string($payload['queue'])?$payload['queue']:'',
			is_string($payload['name'])?$payload['name']:'',$payload['max_attempts'],
		);
		$fingerprint=PanelAgentGuard::digest(is_string($payload['job_fingerprint'])?$payload['job_fingerprint']:'','workflow job fingerprint');
		if(!hash_equals($job->fingerprint(),$fingerprint)){ throw new \InvalidArgumentException('Panel agent workflow job fingerprint is invalid.'); }
		return$job;
	}

	public function reference():string{return$this->reference;}
	public function executionFingerprint():string{return$this->executionFingerprint;}
	public function resolverFingerprint():string{return$this->resolverFingerprint;}
	public function planHash():string{return$this->planHash;}
	public function scopeFingerprint():string{return$this->scopeFingerprint;}
	public function expiresAt():int{return$this->expiresAt;}
	public function queue():string{return$this->queue;}
	public function name():string{return$this->name;}
	public function maxAttempts():int{return$this->maxAttempts;}
	public function fingerprint():string{return$this->fingerprint;}
	public function operationIdempotencyKey():string{return'panel_agent_workflow:'.$this->fingerprint;}
	public function expired(int $now):bool{if($now<0){throw new \InvalidArgumentException('Panel agent workflow job clock cannot be negative.');}return$now>=$this->expiresAt;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{return$this->canonical()+['job_fingerprint'=>$this->fingerprint];}

	/** @return array<string,mixed> */
	private function canonical():array{
		return[
			'type'=>'panel_agent_workflow_job','version'=>1,'reference'=>$this->reference,
			'execution_fingerprint'=>$this->executionFingerprint,'resolver_fingerprint'=>$this->resolverFingerprint,
			'plan_hash'=>$this->planHash,'scope_fingerprint'=>$this->scopeFingerprint,'expires_at'=>$this->expiresAt,
			'queue'=>$this->queue,'name'=>$this->name,'max_attempts'=>$this->maxAttempts,
		];
	}
}
