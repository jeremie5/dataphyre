<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Policy-gated first-party control commands for the Operations OS console. */
final class PanelOperationsOsFabricHandler implements PanelCommandHandler {
	private const ABILITIES=[
		'operations_os.policy.engage'=>'operations_os.policy.engage',
		'operations_os.policy.release'=>'operations_os.policy.release',
		'operations_os.release.pause'=>'release.ring.pause',
		'operations_os.release.rollback'=>'release.rollback',
		'operations_os.release.execute'=>'release.execute',
		'operations_os.release.recover'=>'release.execute.recover',
		'operations_os.federation.desired'=>'operations_os.federation.desired',
		'operations_os.federation.heartbeat'=>'federation.transport.send',
		'operations_os.federation.push_desired'=>'federation.transport.send',
		'operations_os.federation.reconcile'=>'federation.transport.send',
		'operations_os.federation.flush'=>'federation.transport.flush',
	];

	public function __construct(private readonly PanelOperationsOs $operationsOs){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$expected=self::ABILITIES[$command->command()]??null;
		if($expected===null){throw new \OutOfBoundsException('Operations OS control command is not supported.');}
		if(!hash_equals($expected,$command->ability())){throw new \LogicException('Operations OS control command ability does not match its effect.');}
		$input=$command->input();
		return match($command->command()){
			'operations_os.policy.engage'=>$this->policy($command,$input,true),
			'operations_os.policy.release'=>$this->policy($command,$input,false),
			'operations_os.release.pause'=>$this->releasePause($command,$input),
			'operations_os.release.rollback'=>$this->releaseRollback($command,$input),
			'operations_os.release.execute'=>$this->releaseExecute($command,$input),
			'operations_os.release.recover'=>$this->releaseRecover($command,$input),
			'operations_os.federation.desired'=>$this->federationDesired($command,$input),
			'operations_os.federation.heartbeat'=>$this->federationHeartbeat($command,$input),
			'operations_os.federation.push_desired'=>$this->federationPushDesired($command,$input),
			'operations_os.federation.reconcile'=>$this->federationReconcile($command,$input),
			'operations_os.federation.flush'=>$this->federationFlush($command,$input),
		};
	}

	/** @param array<string,mixed> $input */
	private function policy(PanelCommandEnvelope $command,array $input,bool $engage):PanelCommandOutcome {
		$pattern=strtolower(trim((string)($input['pattern']??'')));
		PanelOperationsGuard::abilityPatterns([$pattern],'policy kill switch');
		if($engage){$this->operationsOs->policy()->engage($pattern);}else{$this->operationsOs->policy()->release($pattern);}
		$state=$engage?'engaged':'released';
		return PanelCommandOutcome::make(
			['operation'=>'policy_'.$state,'pattern'=>$pattern,'revision'=>$this->operationsOs->policy()->revision()],
			[new PanelEventDraft('operations_os.policy.'.$state,'policy','control_plane',['pattern'=>$pattern,'revision'=>$this->operationsOs->policy()->revision()],['risk'=>$command->risk()])],
			['control_plane'=>'policy'],
		);
	}

	/** @param array<string,mixed> $input */
	private function releasePause(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$ring=PanelOperationsGuard::name((string)($input['ring']??''),'release ring');$paused=self::boolean($input['paused']??true);
		$this->operationsOs->releases()->pause($ring,$paused,$this->policyRequest($command,'release_ring',$ring));
		return PanelCommandOutcome::make(
			['operation'=>'release_pause','ring'=>$ring,'paused'=>$paused],
			[new PanelEventDraft('operations_os.release.pause_changed','release_ring',$ring,['paused'=>$paused],['risk'=>$command->risk()])],
			['control_plane'=>'releases'],
		);
	}

	/** @param array<string,mixed> $input */
	private function releaseRollback(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$ring=PanelOperationsGuard::name((string)($input['ring']??''),'release ring');
		$deployment=$this->operationsOs->releases()->rollback($ring,$this->policyRequest($command,'release_ring',$ring),$command->idempotencyKey());
		return PanelCommandOutcome::make(
			['operation'=>'release_rollback','ring'=>$ring,'deployment_id'=>(string)($deployment['id']??''),'status'=>(string)($deployment['status']??'unknown')],
			[new PanelEventDraft('operations_os.release.rolled_back','release_ring',$ring,['deployment_id'=>(string)($deployment['id']??''),'artifact_id'=>(string)($deployment['artifact_id']??'')],['risk'=>$command->risk()])],
			['control_plane'=>'releases'],
		);
	}

	/** @param array<string,mixed> $input */
	private function releaseExecute(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$artifact=PanelOperationsGuard::name((string)($input['artifact_id']??''),'release artifact id');$ring=PanelOperationsGuard::name((string)($input['ring']??''),'release ring');$health=$input['health']??[];if(!is_array($health)){throw new \InvalidArgumentException('Release execution health must be an object-like map.');}
		$execution=$this->operationsOs->releaseExecution()->execute($artifact,$ring,$health,$this->policyRequest($command,'release_artifact',$artifact),$command->idempotencyKey(),'fabric-worker');
		return PanelCommandOutcome::make(
			['operation'=>'release_execute','execution_id'=>(string)$execution['id'],'deployment_id'=>(string)$execution['deployment_id'],'artifact_id'=>$artifact,'ring'=>$ring,'status'=>(string)$execution['status']],
			[new PanelEventDraft('operations_os.release.execution_completed','release_execution',(string)$execution['id'],['deployment_id'=>(string)$execution['deployment_id'],'artifact_id'=>$artifact,'ring'=>$ring,'status'=>(string)$execution['status']],['risk'=>$command->risk()])],
			['control_plane'=>'release_execution'],
		);
	}

	/** @param array<string,mixed> $input */
	private function releaseRecover(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$stale=(int)($input['stale_after_seconds']??0);$limit=(int)($input['limit']??25);$result=$this->operationsOs->releaseExecution()->recoverStale($this->policyRequest($command,'release_execution','recovery'),'fabric-recovery',$stale,$limit);
		return PanelCommandOutcome::make(
			['operation'=>'release_recover','resumed_count'=>count($result['resumed']),'error_count'=>count($result['errors'])],
			[new PanelEventDraft('operations_os.release.recovery_completed','release_execution','recovery',['resumed_count'=>count($result['resumed']),'error_count'=>count($result['errors'])],['risk'=>$command->risk()])],
			['control_plane'=>'release_execution'],
		);
	}

	/** @param array<string,mixed> $input */
	private function federationDesired(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$desired=$input['desired']??null;if(!is_array($desired)){throw new \InvalidArgumentException('Federation desired state must be an object-like map.');}
		$this->operationsOs->federation()->desired($desired);$revision=$this->operationsOs->federation()->revision();
		return PanelCommandOutcome::make(
			['operation'=>'federation_desired','state_count'=>count($desired),'revision'=>$revision],
			[new PanelEventDraft('operations_os.federation.desired_changed','federation','control_plane',['state_names'=>array_values(array_map('strval',array_keys($desired))),'revision'=>$revision],['risk'=>$command->risk()])],
			['control_plane'=>'federation'],
		);
	}

	/** @param array<string,mixed> $input */
	private function federationHeartbeat(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$target=PanelOperationsGuard::name((string)($input['target_node']??''),'federation target node');$manifest=$input['node']??null;if(!is_array($manifest)){throw new \InvalidArgumentException('Federation heartbeat requires a signed node manifest.');}$message=$this->operationsOs->federationGateway()->heartbeat(PanelFederationNode::hydrate($manifest),$target,$this->policyRequest($command,'federation_node',$target),$command->idempotencyKey(),self::boolean($input['immediate']??true));return$this->federationMessageOutcome($command,$message,'heartbeat');
	}

	/** @param array<string,mixed> $input */
	private function federationPushDesired(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$target=PanelOperationsGuard::name((string)($input['target_node']??''),'federation target node');$desired=$input['desired']??null;if(!is_array($desired)){throw new \InvalidArgumentException('Federation desired state must be an object-like map.');}$message=$this->operationsOs->federationGateway()->pushDesired($target,$desired,$this->policyRequest($command,'federation_node',$target),$command->idempotencyKey(),self::boolean($input['immediate']??true));return$this->federationMessageOutcome($command,$message,'push_desired');
	}

	/** @param array<string,mixed> $input */
	private function federationReconcile(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$target=PanelOperationsGuard::name((string)($input['target_node']??''),'federation target node');$message=$this->operationsOs->federationGateway()->requestReconciliation($target,$this->policyRequest($command,'federation_node',$target),$command->idempotencyKey(),self::boolean($input['immediate']??true));return$this->federationMessageOutcome($command,$message,'reconcile');
	}

	/** @param array<string,mixed> $input */
	private function federationFlush(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$result=$this->operationsOs->federationGateway()->flush($this->policyRequest($command,'federation','outbox'),(int)($input['limit']??25),(int)($input['stale_after_seconds']??300));return PanelCommandOutcome::make(['operation'=>'federation_flush','delivered_count'=>count($result['delivered']),'error_count'=>count($result['errors'])],[new PanelEventDraft('operations_os.federation.outbox_flushed','federation','outbox',['delivered_count'=>count($result['delivered']),'error_count'=>count($result['errors'])],['risk'=>$command->risk()])],['control_plane'=>'federation_gateway']);
	}

	/** @param array<string,mixed> $message */
	private function federationMessageOutcome(PanelCommandEnvelope $command,array $message,string $operation):PanelCommandOutcome {return PanelCommandOutcome::make(['operation'=>'federation_'.$operation,'message_id'=>(string)($message['id']??''),'target_node'=>(string)($message['target_node']??''),'status'=>(string)($message['status']??'unknown'),'attempts'=>(int)($message['attempts']??0)],[new PanelEventDraft('operations_os.federation.message_queued','federation_message',(string)($message['id']??''),['kind'=>(string)($message['kind']??''),'target_node'=>(string)($message['target_node']??''),'status'=>(string)($message['status']??'unknown')],['risk'=>$command->risk()])],['control_plane'=>'federation_gateway']);}

	private function policyRequest(PanelCommandEnvelope $command,string $resourceType,string $resourceId):PanelPolicyRequest {
		return new PanelPolicyRequest(
			$command->actorId(),$command->ability(),$command->tenantId(),$resourceType,$resourceId,$command->risk(),
			$command->roles(),$command->permissions(),['correlation_id'=>$command->correlationId(),'source'=>'operations_os.command_fabric'],
		);
	}

	private static function boolean(mixed $value):bool {
		if(is_bool($value)){return$value;}$parsed=filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);
		if($parsed===null){throw new \InvalidArgumentException('Operations OS control boolean is invalid.');}return$parsed;
	}
}
