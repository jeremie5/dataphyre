<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Bridges deferred agent executions into Panel's leased operation workers.
 *
 * The persisted operation contains only PanelAgentWorkflowJob. Signed intents,
 * confirmation evidence, and the idempotency key remain behind the host-owned
 * resolver and are revalidated by PanelAgentRuntime inside the worker.
 */
final class PanelAgentWorkflowOperationBridge implements \JsonSerializable {
	public const OPERATION_TYPE='panel_agent_workflow';
	private readonly string $resolverFingerprint;
	private readonly ?\Closure $clock;

	public function __construct(
		private readonly PanelAgentRuntime $runtime,
		private readonly PanelAgentWorkflowJobResolver $resolver,
		?callable $clock=null,
	){
		$this->resolverFingerprint=PanelAgentGuard::digest($resolver->fingerprint(),'workflow resolver fingerprint');
		$this->clock=$clock===null?null:\Closure::fromCallable($clock);
	}

	/** @param array<string,mixed> $options */
	public function job(string $reference,PanelAgentDeferredExecution $execution,array $options=[]):PanelAgentWorkflowJob{
		return PanelAgentWorkflowJob::make($reference,$execution,$this->resolverFingerprint,$options);
	}

	public function register(PanelOperationHandlerRegistry $handlers,bool $replace=false):self{
		$handlers->register(self::OPERATION_TYPE,fn(mixed $payload,PanelOperationExecution $execution,PanelOperationRecord $record):array=>$this->handle($payload,$execution,$record),$replace);
		return$this;
	}

	public function submit(PanelLeasedOperationRunner $runner,PanelAgentWorkflowJob $job,?string $operationId=null):PanelOperationRecord{
		$this->assertJob($job);
		if($job->expired($this->now())){ throw new PanelAgentException('worker_job_expired','Deferred Panel agent workflow job has expired.',410); }
		$options=[
			'queue'=>$job->queue(),'max_attempts'=>$job->maxAttempts(),'idempotency_key'=>$job->operationIdempotencyKey(),'total'=>1,
			'metadata'=>['bridge'=>'panel_agent_workflow','job_fingerprint'=>$job->fingerprint(),'sensitive_execution_material_persisted'=>false],
			'created_at'=>$runner->store()->currentTime(),
		];
		if($operationId!==null){$options['id']=$operationId;}
		$record=$runner->submit(self::OPERATION_TYPE,$job->name(),['job'=>$job->jsonSerialize()],$options);
		$this->assertOperation($record,$job);
		return$record;
	}

	/** @return array<string,mixed> */
	private function handle(mixed $payload,PanelOperationExecution $execution,PanelOperationRecord $record):array{
		if(!is_array($payload) || array_keys($payload)!==['job'] || !is_array($payload['job'])){
			throw new PanelAgentException('worker_payload_invalid','Deferred Panel agent workflow payload is invalid.',400);
		}
		try{$job=PanelAgentWorkflowJob::fromArray($payload['job']);}
		catch(\Throwable $error){throw new PanelAgentException('worker_payload_invalid','Deferred Panel agent workflow payload is invalid.',400,$error);}
		$this->assertJob($job);
		$this->assertOperation($record,$job);
		$now=$this->now();
		if($job->expired($now)){throw new PanelAgentException('worker_job_expired','Deferred Panel agent workflow job has expired.',410);}
		$record=$execution->guard();
		$worker=PanelAgentWorkflowWorkerContext::fromOperation($record,$execution->requireLease(),$job);
		try{$deferred=$this->resolver->resolve($job,$worker);}
		catch(\Throwable $error){throw new PanelAgentException('worker_resolution_failed','Deferred Panel agent workflow material could not be resolved.',503,$error);}
		if(!hash_equals($job->executionFingerprint(),$deferred->fingerprint()) || !hash_equals($job->planHash(),$deferred->plan()->hash()) || !hash_equals($job->scopeFingerprint(),$deferred->context()->scopeFingerprint()) || $job->expiresAt()!==$deferred->expiresAt()){
			throw new PanelAgentException('worker_material_mismatch','Deferred Panel agent workflow material does not match its queued commitment.',409);
		}
		$execution->guard();
		try{
			$result=$this->runtime->execute(
				$deferred->plan(),$deferred->planIntent(),$deferred->context(),$deferred->approvalIntents(),
				$deferred->idempotencyKey(),$deferred->expectedStoreRevision(),$deferred->confirmationEvidence(),
			);
		}catch(\Throwable $error){
			throw new PanelAgentException('worker_execution_failed','Deferred Panel agent workflow execution failed closed.',503,$error);
		}
		$execution->guard();
		$execution->progress(1,1,$result->replayed()?'Deferred agent workflow recovered.':'Deferred agent workflow completed.',1,0);
		return[
			'type'=>'panel_agent_workflow_operation_result','version'=>1,'job_fingerprint'=>$job->fingerprint(),
			'worker_claim'=>$worker->claimFingerprint(),'agent_result'=>$result->jsonSerialize(),'sensitive_execution_material_persisted'=>false,
		];
	}

	private function assertJob(PanelAgentWorkflowJob $job):void{
		if(!hash_equals($this->resolverFingerprint,$job->resolverFingerprint())){
			throw new PanelAgentException('worker_resolver_stale','Deferred Panel agent workflow resolver configuration changed.',409);
		}
	}

	private function assertOperation(PanelOperationRecord $record,PanelAgentWorkflowJob $job):void{
		$metadata=['bridge'=>'panel_agent_workflow','job_fingerprint'=>$job->fingerprint(),'sensitive_execution_material_persisted'=>false];
		if($record->type()!==self::OPERATION_TYPE || $record->name()!==$job->name() || $record->queue()!==$job->queue() || $record->maxAttempts()!==$job->maxAttempts() || $record->idempotencyKey()!==$job->operationIdempotencyKey() || $record->total()!==1 || $record->metadata()!==$metadata){
			throw new PanelAgentException('worker_operation_mismatch','Deferred Panel agent workflow operation does not match its queued commitment.',409);
		}
	}

	private function now():int{
		$value=$this->clock===null?time():($this->clock)();
		if(!is_int($value)||$value<0){throw new \UnexpectedValueException('Panel agent workflow bridge clock must return a non-negative integer timestamp.');}
		return$value;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return[
			'type'=>'panel_agent_workflow_operation_bridge','version'=>1,'operation_type'=>self::OPERATION_TYPE,
			'resolver_fingerprint'=>$this->resolverFingerprint,'delivery'=>'at_least_once','operation_ownership'=>'lease_fenced',
			'agent_execution_ownership'=>'renewable_fenced','sensitive_execution_material_persisted'=>false,
			'routes_installed'=>false,'model_client_installed'=>false,'worker_process_installed'=>false,
		];
	}
}
