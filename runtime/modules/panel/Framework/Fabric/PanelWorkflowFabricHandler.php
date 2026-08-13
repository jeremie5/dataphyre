<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Routes typed workflow commands into the existing WorkflowEngine. */
final class PanelWorkflowFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly WorkflowEngine $workflows){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		$definition=$this->requiredIdentifier($input,'definition');
		$id=$this->requiredIdentifier($input,'id');
		$actor=new WorkflowActor($command->actorId(),$command->roles(),$command->permissions(),[
			'tenant_id'=>$command->tenantId(),'correlation_id'=>$command->correlationId(),
		]);
		$result=match($command->command()){
			'workflow.start'=>$this->workflows->start(
				$definition,$id,$this->map($input['data']??[],'workflow start data'),$actor,$command->idempotencyKey(),
				$this->map($input['metadata']??[],'workflow start metadata'),
			),
			'workflow.transition'=>$this->workflows->transition(
				$definition,$id,$this->requiredName($input,'transition'),
				$this->map($input['data_patch']??[],'workflow transition patch'),$actor,$command->expectedRevision(),$command->idempotencyKey(),
			),
			'workflow.approve'=>$this->workflows->approve(
				$definition,$id,$actor,$this->optionalString($input,'comment',''),$command->expectedRevision(),$command->idempotencyKey(),
			),
			'workflow.reject'=>$this->workflows->reject(
				$definition,$id,$actor,$this->optionalString($input,'comment',''),$command->expectedRevision(),$command->idempotencyKey(),
			),
			'workflow.assign'=>$this->workflows->assign(
				$definition,$id,$this->nullableIdentifier($input,'assigned_to'),$this->roles($input['roles']??[]),
				$actor,$command->expectedRevision(),$command->idempotencyKey(),
			),
			'workflow.rollback'=>$this->workflows->rollback(
				$definition,$id,$actor,$this->nullableIdentifier($input,'event_id'),$this->optionalString($input,'reason',''),
				$command->expectedRevision(),$command->idempotencyKey(),
			),
			default=>throw new PanelCommandExecutionException('workflow_command_unknown','The workflow command is not supported.'),
		};
		if(!$result->ok()){
			throw new PanelCommandExecutionException($this->errorCode('workflow',$result->code()),$this->safeMessage($result->message()));
		}
		$record=$result->record();
		$event=new PanelEventDraft(
			'workflow.'.$result->code(),'workflow',$id,
			[
				'definition'=>$definition,'instance_id'=>$id,'code'=>$result->code(),'message'=>$result->message(),
				'replayed'=>$result->replayed(),'state'=>$record?->state(),'version'=>$record?->version(),
				'native_event_ids'=>array_map(static fn(WorkflowEvent $event):string=>$event->id(),$result->events()),
			],
			['source'=>'workflow_engine'],
		);
		return PanelCommandOutcome::make($result,[$event],[
			'native_runtime'=>'workflow_engine','native_replayed'=>$result->replayed(),'native_code'=>$result->code(),
		]);
	}

	public function jsonSerialize():array{return ['type'=>'panel_workflow_fabric_handler','version'=>1,'commands'=>['workflow.start','workflow.transition','workflow.approve','workflow.reject','workflow.assign','workflow.rollback'],'native_idempotency'=>true,'optimistic_concurrency'=>true];}

	/** @param array<string,mixed> $input */
	private function requiredIdentifier(array $input,string $key):string {
		$value=$input[$key]??null;
		if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is required.");}
		try{return PanelOperationsGuard::identifier($value,"workflow {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredName(array $input,string $key):string {
		$value=$input[$key]??null;
		if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is required.");}
		try{return PanelOperationsGuard::name($value,"workflow {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function nullableIdentifier(array $input,string $key):?string {
		$value=$input[$key]??null;
		if($value===null||$value===''){return null;}
		if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is invalid.");}
		try{return PanelOperationsGuard::identifier($value,"workflow {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function optionalString(array $input,string $key,string $default):string {
		$value=$input[$key]??$default;
		if(!is_string($value)){throw new PanelCommandExecutionException('workflow_input_invalid',"Workflow {$key} is invalid.");}
		return substr(trim($value),0,2048);
	}

	/** @return array<string,mixed> */
	private function map(mixed $value,string $label):array {
		if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new PanelCommandExecutionException('workflow_input_invalid',ucfirst($label).' must be an object.');}
		try{return PanelOperationsGuard::canonical($value);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('workflow_input_invalid',ucfirst($label).' is invalid.',$error);}
	}

	/** @return list<string> */
	private function roles(mixed $value):array {
		if(is_string($value)){$value=[$value];}
		if(!is_array($value)){throw new PanelCommandExecutionException('workflow_input_invalid','Workflow assignment roles are invalid.');}
		try{return PanelOperationsGuard::roles($value,'workflow assignment role');}
		catch(\Throwable $error){throw new PanelCommandExecutionException('workflow_input_invalid','Workflow assignment roles are invalid.',$error);}
	}

	private function errorCode(string $prefix,string $code):string{return substr($prefix.'_'.preg_replace('/[^a-z0-9_.-]+/','_',strtolower($code)),0,96);}
	private function safeMessage(string $message):string{return substr(trim($message)!==''?$message:'Workflow command failed.',0,2048);}
}
