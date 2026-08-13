<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Bounded, redacted read model for the governed Operations OS.
 *
 * This facade deliberately projects allowlisted metadata instead of forwarding
 * subsystem manifests wholesale. Command inputs, idempotency keys, work data,
 * event payloads, evidence, credentials, signatures, and lease tokens never
 * cross the console boundary.
 */
final class PanelOperationsConsole implements \JsonSerializable {
	public const DEFAULT_LIMIT=25;
	public const MAX_LIMIT=100;

	public function __construct(
		private readonly PanelOperationsOs $operationsOs,
		private readonly int $maximumLimit=self::MAX_LIMIT,
	){
		if($maximumLimit<1||$maximumLimit>1000){
			throw new \InvalidArgumentException('Operations console maximum limit is invalid.');
		}
	}

	public function operationsOs():PanelOperationsOs{return$this->operationsOs;}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	public function snapshot(?string $tenantId=null,array $options=[]):array {
		if($tenantId!==null){$tenantId=PanelOperationsGuard::identifier($tenantId,'Operations console tenant');}
		$limit=$this->limit($options['limit']??self::DEFAULT_LIMIT);
		$after=max(0,(int)($options['event_cursor']??0));
		$workAfter=max(0,(int)($options['work_cursor']??0));
		$workCriteria=$this->workCriteria(is_array($options['work']??null)?$options['work']:[]);

		$status=$this->capture('runtime_status',fn():array=>$this->runtimeStatus());
		$fabric=$this->capture('command_fabric',fn():array=>$this->fabricSnapshot($tenantId,$after,$limit));
		$work=$this->capture('work_graph',fn():array=>$this->workSnapshot($tenantId,$workCriteria,$workAfter,$limit));
		$domains=$this->capture('domains',fn():array=>$this->domainSnapshot());
		$policy=$this->capture('policy',fn():array=>$this->policySnapshot());
		$intelligence=$this->capture('intelligence',fn():array=>$this->intelligenceSnapshot($tenantId,$limit));
		$compliance=$this->capture('compliance',fn():array=>$this->complianceSnapshot());
		$fleet=$this->capture('fleet',fn():array=>$this->fleetSnapshot($tenantId,$limit));

		return PanelManifestContract::stamp([
			'type'=>'panel_operations_console_snapshot','version'=>1,'tenant_id'=>$tenantId,
			'generated_at'=>is_string($status['generated_at']??null)?$status['generated_at']:gmdate('c'),
			'limits'=>['requested'=>$limit,'maximum'=>$this->maximumLimit,'event_cursor'=>$after,'work_cursor'=>$workAfter],
			'attention'=>$this->attention($fabric,$work,$domains,$policy,$intelligence,$compliance,$fleet),
			'status'=>$status,'work'=>$work,'fabric'=>$fabric,'domains'=>$domains,'policy'=>$policy,
			'intelligence'=>$intelligence,'compliance'=>$compliance,'fleet'=>$fleet,
			'security'=>[
				'tenant_scope_explicit'=>$tenantId!==null,'command_inputs_exposed'=>false,'idempotency_keys_exposed'=>false,
					'work_data_exposed'=>false,'event_payloads_exposed'=>false,'evidence_payloads_exposed'=>false,
					'credentials_exposed'=>false,'signatures_exposed'=>false,'lease_tokens_exposed'=>false,
					'federation_payloads_exposed'=>false,'federation_nonces_exposed'=>false,'transport_callbacks_exposed'=>false,
			],
		]);
	}

	public function dispatch(PanelCommandEnvelope $command):PanelCommandReceipt{return$this->operationsOs->commandFabric()->dispatch($command);}
	public function recordSignal(PanelIntelligenceSignal $signal,PanelPolicyRequest|array $request):PanelIntelligenceSignal{return$this->operationsOs->intelligence()->recordSignal($signal,$request);}
	/** @param array<string,mixed> $evidence */public function observe(string $kind,string $tenantId,string $source,string $subjectType,string|int $subjectId,string $summary,string $severity,int $confidenceBasisPoints,PanelPolicyRequest|array $request,array $evidence=[],string|int|\DateTimeInterface|null $observedAt=null,int $ttlSeconds=900):PanelIntelligenceSignal{return$this->operationsOs->intelligence()->observe($kind,$tenantId,$source,$subjectType,$subjectId,$summary,$severity,$confidenceBasisPoints,$request,$evidence,$observedAt,$ttlSeconds);}
	/** @param array<string,mixed> $input */public function propose(string $signalId,string $command,string $ability,array $input,string $risk,string $reason,PanelPolicyRequest|array $request,string $idempotencyKey,int $requestedApprovals=0,?int $expectedRevision=null):PanelIntelligenceProposal{return$this->operationsOs->intelligence()->propose($signalId,$command,$ability,$input,$risk,$reason,$request,$idempotencyKey,$requestedApprovals,$expectedRevision);}
	public function approveProposal(string $proposalId,PanelPolicyRequest|array $request,?int $ttlSeconds=null):PanelIntelligenceApproval{return$this->operationsOs->intelligence()->approve($proposalId,$request,$ttlSeconds);}
	public function rejectProposal(string $proposalId,string $reason,PanelPolicyRequest|array $request):PanelIntelligenceProposal{return$this->operationsOs->intelligence()->reject($proposalId,$reason,$request);}
	public function dispatchProposal(string $proposalId,PanelPolicyRequest|array $request,bool $confirmed=false,?int $expectedRevision=null,?int $staleAfterSeconds=null):PanelCommandReceipt{return$this->operationsOs->intelligence()->dispatch($proposalId,$request,$confirmed,$expectedRevision,$staleAfterSeconds);}
	/** @param array<string,mixed> $evidence */public function recordFeedback(string $proposalId,string $outcome,int $effectivenessBasisPoints,array $evidence,PanelPolicyRequest|array $request,string $idempotencyKey):PanelIntelligenceFeedback{return$this->operationsOs->intelligence()->recordFeedback($proposalId,$outcome,$effectivenessBasisPoints,$evidence,$request,$idempotencyKey);}
	/** @return array{resumed:list<array<string,mixed>>,errors:array<string,string>} */public function recoverIntelligence(PanelPolicyRequest|array $request,?int $staleAfterSeconds=null,int $limit=25):array{return$this->operationsOs->intelligence()->recoverStale($request,$staleAfterSeconds,$this->limit($limit));}

	/** @return array{resumed:list<array<string,mixed>>,error_count:int} */
	public function recoverStale(int $staleAfterSeconds=300,int $limit=25):array {
		$result=$this->operationsOs->commandFabric()->recoverStale(max(0,min(604800,$staleAfterSeconds)),$this->limit($limit));
		return['resumed'=>array_map([$this,'receiptSummary'],$result['resumed']),'error_count'=>count($result['errors'])];
	}

	/** @return array<string,mixed> */
	public function drainSubscriber(string $name,int $limit=100):array {
		$result=$this->operationsOs->commandFabric()->drainSubscriber($name,$this->limit($limit));
		return[
			'subscriber'=>(string)($result['subscriber']??$name),'ok'=>($result['ok']??false)===true,
			'cursor'=>(int)($result['cursor']??0),'processed'=>(int)($result['processed']??0),
			'skipped'=>(int)($result['skipped']??0),'retry_sequence'=>isset($result['retry_sequence'])?(int)$result['retry_sequence']:null,
			'error_code'=>isset($result['error_code'])?(string)$result['error_code']:null,'busy'=>($result['busy']??false)===true,
		];
	}

	/** @return array<string,mixed> */
	public function receiptSummary(PanelCommandReceipt $receipt):array {
		$metadata=$receipt->metadata();
		return[
			'id'=>$receipt->id(),'status'=>$receipt->status(),'ok'=>$receipt->ok(),'replay'=>$receipt->replay(),
			'event_count'=>count($receipt->eventIds()),'completed_at'=>$receipt->completedAt(),
			'error_code'=>isset($metadata['error_code'])?(string)$metadata['error_code']:null,
			'handler_pattern'=>isset($metadata['handler_pattern'])?(string)$metadata['handler_pattern']:null,
		];
	}

	public function jsonSerialize():array {
		return PanelManifestContract::stamp([
			'type'=>'panel_operations_console_manifest','version'=>1,'maximum_limit'=>$this->maximumLimit,
			'capabilities'=>[
				'bounded_snapshots'=>true,'tenant_scoped_work'=>true,'safe_global_control_plane'=>true,
				'redacted_command_journal'=>true,'redacted_event_stream'=>true,'attention_queue'=>true,
				'crash_recovery'=>true,'subscriber_drain'=>true,'governed_command_dispatch'=>true,
						'closed_loop_intelligence'=>true,'governed_proposals'=>true,'outcome_effectiveness'=>true,
						'durable_release_execution'=>true,'fenced_release_recovery'=>true,'automatic_release_rollback'=>true,
						'durable_federation_transport'=>true,'offline_federation_queue'=>true,'federation_reconciliation'=>true,
			],
			'security'=>[
				'host_authorization_required'=>true,'host_csrf_required'=>true,'fabric_policy_rechecked'=>true,
				'raw_state_serialized'=>false,'raw_payloads_serialized'=>false,'secrets_serialized'=>false,
			],
		]);
	}

	/** @return array<string,mixed> */
	private function runtimeStatus():array {
		$status=$this->operationsOs->status();
		return[
			'policy_revision'=>(int)($status['policy_revision']??0),'operator_router_revision'=>(int)($status['operator_router_revision']??0),
			'semantic_revision'=>(int)($status['semantic_revision']??0),'lineage_revision'=>(int)($status['lineage_revision']??0),
			'federation_revision'=>(int)($status['federation_revision']??0),'installed_domain_count'=>count((array)($status['installed_domains']??[])),
			'domain_activation_revision'=>(int)($status['domain_activation_revision']??0),'command_fabric_revision'=>(int)($status['command_fabric_revision']??0),
					'command_fabric_sequence'=>(int)($status['command_fabric_sequence']??0),'intelligence_revision'=>(int)($status['intelligence_revision']??0),'release_execution_revision'=>(int)($status['release_execution_revision']??0),'federation_gateway_revision'=>(int)($status['federation_gateway_revision']??0),'compliance_chain_verified'=>($status['compliance_chain_verified']??false)===true,
			'generated_at'=>(string)($status['generated_at']??gmdate('c')),
		];
	}

	/** @return array<string,mixed> */
	private function fabricSnapshot(?string $tenantId,int $after,int $limit):array {
		$fabric=$this->operationsOs->commandFabric();$manifest=$fabric->jsonSerialize();$state=$fabric->store()->payload();
		$commands=[];
		foreach((array)($state['commands']??[])as$entry){
			if(!is_array($entry)||!is_array($entry['envelope']??null)){continue;}$envelope=$entry['envelope'];
			if($tenantId!==null&&($envelope['tenant_id']??null)!==$tenantId){continue;}
			$receipt=is_string($envelope['idempotency_hash']??null)&&is_array($state['receipts'][$envelope['idempotency_hash']]??null)?$state['receipts'][$envelope['idempotency_hash']]:null;
			$commands[]=[
				'id'=>'command_'.substr((string)($entry['fingerprint']??''),0,16),'command'=>(string)($envelope['command']??''),'ability'=>(string)($envelope['ability']??''),
				'tenant_id'=>(string)($envelope['tenant_id']??''),'actor_hash'=>hash('sha256',(string)($envelope['actor_id']??'')),
				'risk'=>(string)($envelope['risk']??'unknown'),'status'=>(string)($entry['status']??'unknown'),'attempts'=>(int)($entry['attempts']??0),
				'correlation_id'=>is_string($envelope['correlation_id']??null)?$envelope['correlation_id']:null,
				'created_at'=>(string)($envelope['created_at']??''),'updated_at'=>(string)($entry['updated_at']??''),
				'event_count'=>is_array($receipt['event_ids']??null)?count($receipt['event_ids']):0,
				'error_code'=>is_array($receipt['metadata']??null)&&isset($receipt['metadata']['error_code'])?(string)$receipt['metadata']['error_code']:null,
			];
		}
		usort($commands,static fn(array $left,array $right):int=>[$right['updated_at'],$right['id']]<=>[$left['updated_at'],$left['id']]);
		$events=array_map(fn(PanelEventEnvelope $event):array=>$this->eventSummary($event),$fabric->events($after,$limit,$tenantId));
		$integrity=is_array($manifest['integrity']??null)?$manifest['integrity']:[];$subscribers=[];
		foreach((array)($manifest['subscribers']??[])as$name=>$subscriber){if(is_array($subscriber)){$subscribers[]=['name'=>(string)$name,'patterns'=>array_values(array_map('strval',(array)($subscriber['patterns']??[]))),'cursor'=>(int)($subscriber['cursor']??0)];}}
		return[
			'revision'=>(int)($manifest['revision']??0),'sequence'=>(int)($manifest['sequence']??0),'commands'=>(int)($manifest['commands']??0),
			'receipts'=>(int)($manifest['receipts']??0),'events'=>(int)($manifest['events']??0),'executing'=>(int)($manifest['executing']??0),
			'integrity'=>['ok'=>($integrity['ok']??false)===true,'commands'=>(int)($integrity['commands']??0),'receipts'=>(int)($integrity['receipts']??0),'events'=>(int)($integrity['events']??0)],
			'subscribers'=>$subscribers,'journal'=>array_slice($commands,0,$limit),'event_stream'=>$events,
			'next_event_cursor'=>$events!==[]?(int)$events[array_key_last($events)]['sequence']:$after,
			'guarantees'=>[
				'encrypted_command_payloads'=>($manifest['guarantees']['encrypted_command_payloads']??false)===true,
				'signed_receipts'=>($manifest['guarantees']['signed_receipts']??false)===true,
				'tamper_evident_event_chain'=>($manifest['guarantees']['tamper_evident_event_chain']??false)===true,
				'fenced_subscriber_ownership'=>($manifest['guarantees']['fenced_subscriber_ownership']??false)===true,
			],
		];
	}

	/** @param array<string,mixed> $criteria @return array<string,mixed> */
	private function workSnapshot(?string $tenantId,array $criteria,int $after,int $limit):array {
		if($tenantId===null){return['scoped'=>false,'tenant_id'=>null,'queue'=>[],'timeline'=>[],'next_timeline_cursor'=>$after,'sla'=>null,'audit_verified'=>null];}
		$work=$this->operationsOs->workGraph();$items=$work->queue($tenantId,$criteria,$limit);$timeline=$work->timeline($tenantId,null,$after,$limit);$events=array_map([$this,'workEventSummary'],$timeline);
		return[
			'scoped'=>true,'tenant_id'=>$tenantId,'criteria'=>$criteria,
			'queue'=>array_map([$this,'workItemSummary'],$items),
			'timeline'=>$events,'next_timeline_cursor'=>$events!==[]?(int)$events[array_key_last($events)]['sequence']:$after,
			'sla'=>$work->sla($tenantId),'audit_verified'=>$work->verifyAudit($tenantId),
		];
	}

	/** @return array<string,mixed> */
	private function domainSnapshot():array {
		$status=$this->operationsOs->status();$activation=$this->operationsOs->activation();$manifest=$activation->jsonSerialize();$active=$activation->activeCompilations();$domains=[];
		foreach(array_keys((array)($status['installed_domains']??[]))as$id){
			try{$compilation=$this->operationsOs->compilation((string)$id);$history=$this->operationsOs->compilationHistory((string)$id);$drift=$active[$id]??null;$drift=$drift instanceof PanelDomainCompilation?$activation->drift((string)$id):['active'=>false,'drifted'=>false,'mismatches'=>[]];
				$domains[]=['id'=>$compilation->domainId(),'version'=>$compilation->domainVersion(),'digest'=>$compilation->digest(),'signed'=>$compilation->signed(),'trusted'=>$this->operationsOs->verifyCompilation($compilation),'active'=>($drift['active']??false)===true,'drifted'=>($drift['drifted']??false)===true,'drift_channels'=>array_values(array_map('strval',(array)($drift['mismatches']??[]))),'history_depth'=>count($history)];
			}catch(\Throwable){$domains[]=['id'=>(string)$id,'version'=>null,'digest'=>null,'signed'=>false,'trusted'=>false,'active'=>false,'drifted'=>true,'drift_channels'=>['projection_unavailable'],'history_depth'=>0];}
		}
		usort($domains,static fn(array $left,array $right):int=>$left['id']<=>$right['id']);
		return['revision'=>(int)($manifest['revision']??0),'receipt_count'=>(int)($manifest['receipt_count']??0),'installed_count'=>count($domains),'active_count'=>count($active),'drifted_count'=>count(array_filter($domains,static fn(array $domain):bool=>$domain['drifted'])),'items'=>$domains];
	}

	/** @return array<string,mixed> */
	private function policySnapshot():array {
		$manifest=$this->operationsOs->policy()->jsonSerialize();
		return['revision'=>(int)($manifest['revision']??0),'bundle_count'=>(int)($manifest['bundle_count']??0),'kill_switches'=>array_values(array_map('strval',(array)($manifest['kill_switches']??[]))),'require_signed'=>($manifest['require_signed']??false)===true,'default_deny'=>($manifest['default_deny']??false)===true,'deny_overrides'=>($manifest['deny_overrides']??false)===true];
	}

	/** @return array<string,mixed> */
	private function intelligenceSnapshot(?string $tenantId,int $limit):array {
		$operator=$this->operationsOs->operator()->jsonSerialize();$router=is_array($operator['router']??null)?$operator['router']:[];$models=[];
		foreach((array)($router['models']??[])as$model){if(is_array($model)){$models[]=['id'=>(string)($model['id']??''),'provider'=>(string)($model['provider']??''),'model'=>(string)($model['model']??''),'health'=>(string)($model['health']??'unknown'),'regions'=>array_values(array_map('strval',(array)($model['regions']??[]))),'classifications'=>array_values(array_map('strval',(array)($model['classifications']??[])))];}}
		$semantics=$this->operationsOs->semantics()->jsonSerialize();$lineage=$this->operationsOs->lineage()->jsonSerialize();$closed=$this->operationsOs->intelligence();$closedManifest=$closed->jsonSerialize();$proposals=[];
		foreach($closed->proposals($tenantId,null,$limit)as$proposal){$proposals[]=['id'=>$proposal->id(),'signal_id'=>$proposal->signalId(),'tenant_id'=>$proposal->tenantId(),'command'=>$proposal->command(),'ability'=>$proposal->ability(),'risk'=>$proposal->risk(),'status'=>$proposal->status(),'required_approvals'=>$proposal->requiredApprovals(),'approval_count'=>$proposal->approvalCount(),'revision'=>$proposal->revision(),'dispatch_attempts'=>$proposal->dispatchAttempts(),'feedback_count'=>$proposal->feedbackCount(),'created_at'=>$proposal->createdAt(),'updated_at'=>$proposal->updatedAt(),'receipt_status'=>$proposal->receipt()['status']??null];}
		$signals=[];foreach($closed->signals($tenantId,null,$limit)as$signal){$signals[]=['id'=>$signal->id(),'kind'=>$signal->kind(),'tenant_id'=>$signal->tenantId(),'source'=>$signal->source(),'subject_type'=>$signal->subjectType(),'subject_hash'=>hash('sha256',$signal->subjectId()),'severity'=>$signal->severity(),'confidence_basis_points'=>$signal->confidenceBasisPoints(),'observed_at'=>$signal->observedAt(),'expires_at'=>$signal->expiresAt()];}
		return[
			'operator'=>['router_revision'=>(int)($router['revision']??0),'model_count'=>count($models),'models'=>$models,'tool_count'=>(int)($operator['tool_count']??0),'evaluator_count'=>count((array)($operator['evaluator_names']??[])),'executor_configured'=>($operator['executor_configured']??false)===true],
			'semantics'=>['revision'=>(int)($semantics['revision']??0),'metric_count'=>count((array)($semantics['metrics']??[]))],
			'lineage'=>['revision'=>(int)($lineage['revision']??0),'node_count'=>count((array)($lineage['nodes']??[])),'edge_count'=>count((array)($lineage['edges']??[]))],
			'closed_loop'=>['revision'=>(int)($closedManifest['revision']??0),'signal_count'=>(int)($closedManifest['signal_count']??0),'proposal_count'=>(int)($closedManifest['proposal_count']??0),'proposal_statuses'=>(array)($closedManifest['proposal_statuses']??[]),'feedback_count'=>(int)($closedManifest['feedback_count']??0),'signals'=>$signals,'proposals'=>$proposals,'effectiveness'=>$closed->effectiveness($tenantId)],
		];
	}

	/** @return array<string,mixed> */
	private function complianceSnapshot():array {
		$manifest=$this->operationsOs->compliance()->jsonSerialize();$automation=$this->operationsOs->complianceAutomation()->jsonSerialize();$collectors=is_array($automation['collectors']??null)?$automation['collectors']:[];$frameworks=is_array($automation['frameworks']??null)?$automation['frameworks']:[];
		return['verified'=>$this->operationsOs->compliance()->verify(),'sequence'=>(int)($manifest['sequence']??0),'control_count'=>(int)($manifest['control_count']??0),'evidence_count'=>(int)($manifest['evidence_count']??0),'active_hold_count'=>(int)($manifest['active_hold_count']??0),'default_deny'=>($manifest['default_deny']??false)===true,'collector_count'=>(int)($collectors['collector_count']??0),'framework_pack_count'=>(int)($frameworks['pack_count']??0),'framework_control_count'=>(int)($frameworks['control_count']??0),'automation_capabilities'=>(array)($automation['capabilities']??[])];
	}

	/** @return array<string,mixed> */
		private function fleetSnapshot(?string $tenantId,int $limit):array {
			$federation=$this->operationsOs->federation()->jsonSerialize();$nodes=[];
			foreach((array)($federation['nodes']??[])as$node){if(is_array($node)){$nodes[]=['id'=>(string)($node['id']??''),'environment'=>(string)($node['environment']??''),'region'=>(string)($node['region']??''),'sequence'=>(int)($node['sequence']??0),'expires_at'=>(string)($node['expires_at']??''),'capability_count'=>count((array)($node['capabilities']??[]))];}}
			$gateway=$this->operationsOs->federationGateway();$gatewayManifest=$gateway->jsonSerialize();$outbox=[];foreach($gateway->outbox($limit)as$message){$outbox[]=$this->federationOutboxSummary($message);}
			$releases=$this->operationsOs->releases()->jsonSerialize();$rings=[];
			foreach((array)($releases['rings']??[])as$name=>$ring){if(is_array($ring)){$rings[]=['name'=>(string)($ring['name']??$name),'order'=>(int)($ring['order']??0),'traffic_basis_points'=>(int)($ring['traffic_basis_points']??0),'paused'=>($ring['paused']??false)===true,'health_gate_count'=>count((array)($ring['health_gates']??[]))];}}
			$artifacts=[];foreach((array)($releases['artifacts']??[])as$artifact){if(is_array($artifact)){$artifacts[]=['id'=>(string)($artifact['id']??''),'version'=>(string)($artifact['version']??''),'digest'=>(string)($artifact['digest']??''),'created_at'=>(string)($artifact['created_at']??''),'bound_digest_count'=>(int)($artifact['bound_digest_count']??0),'sbom_component_count'=>(int)($artifact['sbom_component_count']??0)];}}
			$releaseExecution=$this->operationsOs->releaseExecution();$executionManifest=$releaseExecution->jsonSerialize();$executions=$tenantId!==null?$releaseExecution->executions($tenantId,$limit):[];$executionStatuses=$tenantId===null?(array)($executionManifest['statuses']??[]):[];if($tenantId!==null){foreach($executions as$execution){$status=(string)($execution['status']??'unknown');$executionStatuses[$status]=($executionStatuses[$status]??0)+1;}ksort($executionStatuses,SORT_STRING);}
		$marketplace=$this->operationsOs->marketplace()->jsonSerialize();$marketplaceTrust=$this->operationsOs->hasMarketplaceTrustNetwork()?$this->operationsOs->marketplaceTrustNetwork()->health():null;$reviews=[];
		foreach((array)($marketplace['reviews']??[])as$review){if(is_array($review)){$reviews[]=['package_id'=>(string)($review['package_id']??''),'package_version'=>(string)($review['package_version']??''),'status'=>(string)($review['status']??'unknown'),'risk_score'=>(int)($review['risk_score']??0),'finding_count'=>count((array)($review['findings']??[])),'approval_count'=>count((array)($review['approvers']??[])),'required_approvals'=>(int)($review['required_approvals']??0)];}}
		$studio=$this->operationsOs->studioBranches()->jsonSerialize();
		return[
				'federation'=>['revision'=>(int)($federation['revision']??0),'node_count'=>count($nodes),'drift_count'=>(int)($federation['drift_count']??0),'desired_state_count'=>count((array)($federation['desired_state']??[])),'nodes'=>$nodes,'gateway'=>['local_node_id'=>(string)($gatewayManifest['local_node_id']??''),'revision'=>(int)($gatewayManifest['revision']??0),'transport_configured'=>($gatewayManifest['transport_configured']??false)===true,'outbox_count'=>(int)($gatewayManifest['outbox_count']??0),'outbox_statuses'=>(array)($gatewayManifest['outbox_statuses']??[]),'inbox_count'=>(int)($gatewayManifest['inbox_count']??0),'inbox_statuses'=>(array)($gatewayManifest['inbox_statuses']??[]),'peer_count'=>(int)($gatewayManifest['peer_count']??0),'recent_outbox'=>$outbox,'integrity'=>['ok'=>($gatewayManifest['integrity']['ok']??false)===true]]],
				'releases'=>['sequence'=>(int)($releases['sequence']??0),'artifact_count'=>(int)($releases['artifact_count']??0),'artifacts'=>$artifacts,'deployment_count'=>(int)($releases['deployment_count']??0),'deployment_statuses'=>(array)($releases['deployment_statuses']??[]),'flag_count'=>count((array)($releases['flags']??[])),'paused_ring_count'=>count(array_filter($rings,static fn(array $ring):bool=>$ring['paused'])),'rings'=>$rings,'execution'=>['configured'=>$releaseExecution->configured(),'revision'=>(int)($executionManifest['revision']??0),'execution_count'=>$tenantId!==null?count($executions):(int)($executionManifest['execution_count']??0),'statuses'=>$executionStatuses,'executions'=>$executions,'tenant_scoped'=>$tenantId!==null]],
			'marketplace'=>['review_count'=>(int)($marketplace['review_count']??0),'required_approvals'=>(int)($marketplace['required_approvals']??0),'reviews'=>$reviews,'trust_network_configured'=>$marketplaceTrust!==null,'trust_network'=>$marketplaceTrust],
			'studio'=>['workspace_count'=>(int)($studio['workspace_count']??0),'branch_count'=>(int)($studio['branch_count']??0),'commit_count'=>(int)($studio['commit_count']??0),'required_approvals'=>(int)($studio['required_approvals']??0)],
		];
	}

	/** @return array<string,mixed> */
	private function eventSummary(PanelEventEnvelope $event):array {
		return['id'=>$event->id(),'sequence'=>$event->sequence(),'event_type'=>$event->eventType(),'aggregate_type'=>$event->aggregateType(),'aggregate_id'=>$event->aggregateId(),'tenant_id'=>$event->tenantId(),'actor_hash'=>hash('sha256',$event->actorId()),'correlation_id'=>$event->correlationId(),'occurred_at'=>$event->occurredAt(),'digest'=>$event->digest()];
	}

	/** @return array<string,mixed> */
	private function workItemSummary(PanelWorkItem $item):array {
		return['id'=>$item->id(),'type'=>$item->type(),'title'=>$item->title(),'state'=>$item->state(),'priority'=>$item->priority(),'queue'=>$item->queue(),'assignee'=>$item->assignee(),'subject_type'=>$item->subjectType(),'subject_id'=>$item->subjectId(),'due_at'=>$item->dueAt(),'tags'=>$item->tags(),'version'=>$item->version(),'created_at'=>$item->createdAt(),'updated_at'=>$item->updatedAt()];
	}

	/** @return array<string,mixed> */
	private function workEventSummary(PanelWorkEvent $event):array {
		return['id'=>$event->id(),'sequence'=>$event->sequence(),'item_id'=>$event->itemId(),'operation'=>$event->operation(),'actor_hash'=>hash('sha256',$event->actorId()),'occurred_at'=>$event->occurredAt(),'reversible'=>$event->reversible(),'correlation_id'=>$event->correlationId(),'hash'=>$event->hash()];
	}

	/** @param array<string,mixed> $message @return array<string,mixed> */
	private function federationOutboxSummary(array $message):array {
		return['id'=>(string)($message['id']??''),'kind'=>(string)($message['kind']??''),'target_node'=>(string)($message['target_node']??''),'sequence'=>(int)($message['sequence']??0),'status'=>(string)($message['status']??'unknown'),'attempts'=>(int)($message['attempts']??0),'last_error_code'=>isset($message['last_error_code'])?(string)$message['last_error_code']:null,'issued_at'=>(string)($message['issued_at']??''),'expires_at'=>(string)($message['expires_at']??''),'updated_at'=>(string)($message['updated_at']??''),'payload_redacted'=>true];
	}

	/** @param array<string,mixed> ...$sections @return list<array<string,mixed>> */
	private function attention(array ...$sections):array {
		[$fabric,$work,$domains,$policy,$intelligence,$compliance,$fleet]=$sections;$items=[];
		foreach(['fabric'=>$fabric,'work'=>$work,'domains'=>$domains,'policy'=>$policy,'intelligence'=>$intelligence,'compliance'=>$compliance,'fleet'=>$fleet]as$section=>$payload){if(($payload['available']??false)!==true){$items[]=$this->attentionItem('critical',$section.'_unavailable',ucfirst($section).' telemetry is unavailable.',$section);}}
		if(($fabric['available']??false)===true&&($fabric['integrity']['ok']??false)!==true){$items[]=$this->attentionItem('critical','fabric_integrity','The command journal integrity check needs attention.','fabric');}
		if((int)($fabric['executing']??0)>0){$items[]=$this->attentionItem('warning','commands_executing',(int)$fabric['executing'].' commands are executing or awaiting recovery.','fabric');}
		if(($work['scoped']??false)===true&&(int)($work['sla']['overdue']??0)>0){$items[]=$this->attentionItem('critical','work_overdue',(int)$work['sla']['overdue'].' work items are overdue.','work');}
		if(($work['scoped']??false)===true&&(int)($work['sla']['unassigned']??0)>0){$items[]=$this->attentionItem('warning','work_unassigned',(int)$work['sla']['unassigned'].' work items are unassigned.','work');}
		if((int)($domains['drifted_count']??0)>0){$items[]=$this->attentionItem('critical','domain_drift',(int)$domains['drifted_count'].' active domains have runtime drift.','domains');}
		if(count((array)($policy['kill_switches']??[]))>0){$items[]=$this->attentionItem('warning','policy_kill_switches',count((array)$policy['kill_switches']).' policy kill switches are engaged.','policy');}
		$awaiting=(int)($intelligence['closed_loop']['proposal_statuses']['awaiting_approval']??0);if($awaiting>0){$items[]=$this->attentionItem('warning','intelligence_awaiting_approval',$awaiting.' intelligence proposals await independent approval.','intelligence');}
		$dispatching=(int)($intelligence['closed_loop']['proposal_statuses']['dispatching']??0);if($dispatching>0){$items[]=$this->attentionItem('critical','intelligence_dispatching',$dispatching.' intelligence proposals may require dispatch recovery.','intelligence');}
			if(($compliance['available']??false)===true&&($compliance['verified']??false)!==true){$items[]=$this->attentionItem('critical','compliance_integrity','The compliance evidence chain is not verified.','compliance');}
			if((int)($fleet['federation']['drift_count']??0)>0){$items[]=$this->attentionItem('warning','fleet_drift',(int)$fleet['federation']['drift_count'].' federation state differences need reconciliation.','fleet');}
			$gateway=(array)($fleet['federation']['gateway']??[]);if($gateway!==[]&&($gateway['integrity']['ok']??false)!==true){$items[]=$this->attentionItem('critical','federation_transport_integrity','The federation transport journal integrity check needs attention.','fleet');}
			$pending=(int)($gateway['outbox_statuses']['pending']??0)+(int)($gateway['outbox_statuses']['sending']??0);if($pending>0){$items[]=$this->attentionItem('warning','federation_delivery_pending',$pending.' federation messages are queued or awaiting recovery.','fleet');}
			$rejected=(int)($gateway['outbox_statuses']['rejected']??0);if($rejected>0){$items[]=$this->attentionItem('critical','federation_delivery_rejected',$rejected.' federation messages were rejected by their target node.','fleet');}
			if((int)($fleet['releases']['paused_ring_count']??0)>0){$items[]=$this->attentionItem('info','release_paused',(int)$fleet['releases']['paused_ring_count'].' release rings are paused.','fleet');}
			$running=(int)($fleet['releases']['execution']['statuses']['running']??0);if($running>0){$items[]=$this->attentionItem('warning','release_executing',$running.' release executions are active or awaiting fenced recovery.','fleet');}
			$rollbackFailed=(int)($fleet['releases']['execution']['statuses']['rollback_failed']??0);if($rollbackFailed>0){$items[]=$this->attentionItem('critical','release_rollback_failed',$rollbackFailed.' release rollbacks require operator intervention.','fleet');}
		$rank=['critical'=>0,'warning'=>1,'info'=>2];usort($items,static fn(array $left,array $right):int=>[$rank[$left['severity']]??9,$left['code']]<=>[$rank[$right['severity']]??9,$right['code']]);return$items;
	}

	/** @return array{severity:string,code:string,message:string,section:string} */
	private function attentionItem(string $severity,string $code,string $message,string $section):array{return compact('severity','code','message','section');}

	/** @return array<string,mixed> */
	private function capture(string $code,callable $reader):array {
		try{$payload=$reader();return['available'=>true,'error_code'=>null]+$payload;}
		catch(\Throwable){return['available'=>false,'error_code'=>$code.'_unavailable'];}
	}

	/** @param array<string,mixed> $criteria @return array<string,mixed> */
	private function workCriteria(array $criteria):array {
		$allowed=['queue','type','state','states','assignee','subject_type','subject_id','tags','overdue','search'];$result=[];
		foreach($allowed as$key){if(array_key_exists($key,$criteria)){$result[$key]=$criteria[$key];}}
		return$result;
	}

	private function limit(mixed $limit):int{return max(1,min($this->maximumLimit,(int)$limit));}
}
