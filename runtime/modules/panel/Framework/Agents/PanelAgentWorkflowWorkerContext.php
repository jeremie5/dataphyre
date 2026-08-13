<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Secret-free fenced operation identity supplied to a deferred job resolver. */
final class PanelAgentWorkflowWorkerContext implements \JsonSerializable {
	private readonly string $claimFingerprint;

	private function __construct(
		private readonly string $operationId,
		private readonly int $attempt,
		private readonly string $worker,
		private readonly int $fence,
		private readonly string $queue,
		private readonly string $jobFingerprint,
	){
		$this->claimFingerprint=hash('sha256',PanelAgentGuard::canonicalJson([
			'contract'=>'panel_agent_workflow_worker_claim_v1','operation_id'=>$operationId,'attempt'=>$attempt,
			'worker'=>$worker,'fence'=>$fence,'queue'=>$queue,'job_fingerprint'=>$jobFingerprint,
		]));
	}

	public static function fromOperation(PanelOperationRecord $record,PanelOperationLease $lease,PanelAgentWorkflowJob $job):self{
		if($record->id()!==$lease->operationId() || $record->worker()!==$lease->worker() || $record->queue()!==$job->queue() || $record->attempt()<1){
			throw new \InvalidArgumentException('Panel agent workflow worker context requires one matching active operation lease.');
		}
		return new self($record->id(),$record->attempt(),$lease->worker(),$lease->fence(),$record->queue(),$job->fingerprint());
	}

	public function operationId():string{return$this->operationId;}
	public function attempt():int{return$this->attempt;}
	public function worker():string{return$this->worker;}
	public function fence():int{return$this->fence;}
	public function queue():string{return$this->queue;}
	public function jobFingerprint():string{return$this->jobFingerprint;}
	public function claimFingerprint():string{return$this->claimFingerprint;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return[
			'type'=>'panel_agent_workflow_worker_context','version'=>1,'operation_id'=>$this->operationId,'attempt'=>$this->attempt,
			'worker'=>$this->worker,'fence'=>$this->fence,'queue'=>$this->queue,'job_fingerprint'=>$this->jobFingerprint,
			'claim_fingerprint'=>$this->claimFingerprint,'lease_token_exposed'=>false,
		];
	}
}
