<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Executes or cancels already-signed agent plans without creating a shadow agent runtime. */
final class PanelAgentFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly PanelAgentRuntime $agents){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		if(!in_array($command->command(),['agent.execute','agent.cancel'],true)){
			throw new PanelCommandExecutionException('agent_command_unknown','The agent command is not supported.');
		}
		$plan=$this->plan($input['plan']??null);$context=$this->context($input['context']??null);
		if(!hash_equals($command->tenantId(),$context->tenant())||!hash_equals($command->actorId(),$context->principal())){
			throw new PanelCommandExecutionException('agent_scope_mismatch','The agent execution context does not match the command scope.');
		}
		$intent=$this->requiredString($input,'plan_intent',32768);
		$expected=$command->expectedRevision();if($expected===null){throw new PanelCommandExecutionException('agent_revision_required','Agent commands require an expected workflow-store revision.');}

		try{
			$result=$command->command()==='agent.execute'
				?$this->agents->execute(
					$plan,$intent,$context,$this->stringList($input['approval_intents']??[],'agent approval intents',2,32768),
					$command->idempotencyKey(),$expected,$this->nullableString($input,'confirmation_evidence',32768),
				)
				:$this->agents->cancel($plan,$intent,$context,$this->requiredString($input,'reason',2048),$expected);
		}catch(PanelAgentException $error){
			throw new PanelCommandExecutionException($this->errorCode($error->errorCode()),$this->safeMessage($error->getMessage()),$error);
		}catch(\InvalidArgumentException|\LengthException|\UnexpectedValueException $error){
			throw new PanelCommandExecutionException('agent_input_invalid','The agent command input is invalid.',$error);
		}
		if(!$result->ok()){
			throw new PanelCommandExecutionException($this->errorCode($result->code()),'The agent plan did not complete successfully.');
		}
		$event=new PanelEventDraft(
			'agent.'.$result->code(),'agent_plan',$result->planHash(),
			[
				'plan_hash'=>$result->planHash(),'code'=>$result->code(),'step_count'=>count($result->steps()),
				'store_revision'=>$result->storeRevision(),'native_replayed'=>$result->replayed(),
			],
			['source'=>'agent_runtime'],
		);
		return PanelCommandOutcome::make($result,[$event],[
			'native_runtime'=>'agent_runtime','native_replayed'=>$result->replayed(),'native_code'=>$result->code(),
		]);
	}

	public function jsonSerialize():array{return [
		'type'=>'panel_agent_fabric_handler','version'=>1,'commands'=>['agent.execute','agent.cancel'],
		'prepare_supported'=>false,'approve_supported'=>false,'bearer_intents_persisted_publicly'=>false,
		'native_policy'=>true,'native_idempotency'=>true,'optimistic_concurrency'=>true,
	];}

	private function plan(mixed $value):PanelAgentPlan {
		if(!is_array($value)){throw new PanelCommandExecutionException('agent_input_invalid','Agent plan is required.');}
		try{return PanelAgentPlan::hydrateExecutionPayload($value);}catch(\Throwable $error){throw new PanelCommandExecutionException('agent_input_invalid','The agent execution plan is invalid.',$error);}
	}

	private function context(mixed $value):PanelAgentRequestContext {
		if(!is_array($value)){throw new PanelCommandExecutionException('agent_input_invalid','Agent context is required.');}
		try{return PanelAgentRequestContext::hydrateExecutionPayload($value);}catch(\Throwable $error){throw new PanelCommandExecutionException('agent_input_invalid','The agent execution context is invalid.',$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredString(array $input,string $key,int $maximum):string {
		$value=$input[$key]??null;if(!is_string($value)||trim($value)===''||strlen($value)>$maximum||str_contains($value,"\0")){throw new PanelCommandExecutionException('agent_input_invalid',"Agent {$key} is invalid.");}return$value;
	}

	/** @param array<string,mixed> $input */
	private function nullableString(array $input,string $key,int $maximum):?string {
		$value=$input[$key]??null;if($value===null){return null;}if(!is_string($value)||strlen($value)>$maximum||str_contains($value,"\0")){throw new PanelCommandExecutionException('agent_input_invalid',"Agent {$key} is invalid.");}return$value;
	}

	/** @return list<string> */
	private function stringList(mixed $value,string $label,int $limit,int $maximum):array {
		if(!is_array($value)||!array_is_list($value)||count($value)>$limit){throw new PanelCommandExecutionException('agent_input_invalid',ucfirst($label).' are invalid.');}
		$result=[];foreach($value as$item){if(!is_string($item)||trim($item)===''||strlen($item)>$maximum||str_contains($item,"\0")){throw new PanelCommandExecutionException('agent_input_invalid',ucfirst($label).' are invalid.');}$result[]=$item;}return$result;
	}

	private function errorCode(string $code):string{return substr('agent_'.preg_replace('/[^a-z0-9_.-]+/','_',strtolower($code)),0,96);}
	private function safeMessage(string $message):string {$safe=PanelSensitiveDataSanitizer::sanitize($message,['max_depth'=>2,'max_items'=>4,'max_string_bytes'=>2048]);return is_string($safe)&&trim($safe)!==''?$safe:'The agent command was refused.';}
}
