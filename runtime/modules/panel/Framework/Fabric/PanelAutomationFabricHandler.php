<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Routes automation execute/rollback commands into the existing executor. */
final class PanelAutomationFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly AutomationExecutor $automation){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		$name=$command->command()==='automation.execute'?$this->requiredName($input,'name'):null;
		$request=new AutomationExecutionRequest(
			$this->map($input['input']??[],'automation input'),
			new WorkflowActor($command->actorId(),$command->roles(),$command->permissions(),['tenant_id'=>$command->tenantId()]),
			$command->idempotencyKey(),
			(bool)($input['dry_run']??$command->metadata()['dry_run']??false),
			(bool)($input['confirmed']??$command->metadata()['confirmed']??false),
			$this->nullableString($input,'confirmation_phrase'),
			array_replace($this->map($input['context']??[],'automation context'),[
				'tenant_id'=>$command->tenantId(),'correlation_id'=>$command->correlationId(),'causation_id'=>$command->causationId(),
			]),
		);
		$result=match($command->command()){
			'automation.execute'=>$this->automation->execute((string)$name,$request),
			'automation.rollback'=>$this->automation->rollback($this->requiredIdentifier($input,'receipt_id'),$request),
			default=>throw new PanelCommandExecutionException('automation_command_unknown','The automation command is not supported.'),
		};
		if(!$result->ok()){
			throw new PanelCommandExecutionException($this->errorCode($result->code()),$this->safeMessage($result->message()));
		}
		$receipt=$result->receipt();
		$aggregate=$receipt?->id()??($name??$this->requiredIdentifier($input,'receipt_id'));
		$event=new PanelEventDraft(
			'automation.'.$result->code(),'automation',$aggregate,
			[
				'code'=>$result->code(),'message'=>$result->message(),'replayed'=>$result->replayed(),
				'action'=>$receipt?->action()??$name,'receipt_id'=>$receipt?->id(),'status'=>$receipt?->status(),
			],
			['source'=>'automation_executor'],
		);
		return PanelCommandOutcome::make($result,[$event],[
			'native_runtime'=>'automation_executor','native_replayed'=>$result->replayed(),'native_code'=>$result->code(),
		]);
	}

	public function jsonSerialize():array{return ['type'=>'panel_automation_fabric_handler','version'=>1,'commands'=>['automation.execute','automation.rollback'],'native_idempotency'=>true,'dry_run'=>true,'rollback'=>true];}

	/** @param array<string,mixed> $input */
	private function requiredName(array $input,string $key):string {
		$value=$input[$key]??null;
		if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('automation_input_invalid',"Automation {$key} is required.");}
		try{return PanelOperationsGuard::name($value,"automation {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('automation_input_invalid',"Automation {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredIdentifier(array $input,string $key):string {
		$value=$input[$key]??null;
		if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('automation_input_invalid',"Automation {$key} is required.");}
		try{return PanelOperationsGuard::identifier($value,"automation {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('automation_input_invalid',"Automation {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function nullableString(array $input,string $key):?string {
		$value=$input[$key]??null;if($value===null){return null;}
		if(!is_string($value)){throw new PanelCommandExecutionException('automation_input_invalid',"Automation {$key} is invalid.");}
		return substr($value,0,2048);
	}

	/** @return array<string,mixed> */
	private function map(mixed $value,string $label):array {
		if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new PanelCommandExecutionException('automation_input_invalid',ucfirst($label).' must be an object.');}
		try{return PanelOperationsGuard::canonical($value);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('automation_input_invalid',ucfirst($label).' is invalid.',$error);}
	}

	private function errorCode(string $code):string{return substr('automation_'.preg_replace('/[^a-z0-9_.-]+/','_',strtolower($code)),0,96);}
	private function safeMessage(string $message):string{return substr(trim($message)!==''?$message:'Automation command failed.',0,2048);}
}
