<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Rehydrates an encrypted domain invocation and calls the host-owned executor. */
final class PanelDelegatingDomainFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly PanelDomainCommandExecutor $delegate){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		$definitionPayload=$input['definition']??null;
		$data=$input['input']??null;
		$context=$input['context']??[];
		if(!is_array($definitionPayload)||!is_array($data)||!is_array($context)||($data!==[]&&array_is_list($data))||($context!==[]&&array_is_list($context))){
			throw new PanelCommandExecutionException('domain_invocation_invalid','The encrypted domain invocation is invalid.');
		}
		try{$definition=PanelDomainCommandDefinition::hydrate($definitionPayload);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('domain_definition_invalid','The domain command definition failed its integrity check.',$error);}
		if(!hash_equals('domain.'.$definition->domainId().'.'.$definition->name(),$command->command())||!hash_equals($definition->ability(),$command->ability())){
			throw new PanelCommandExecutionException('domain_scope_mismatch','The domain command scope does not match its signed definition.');
		}
		$recordId=$input['record_id']??null;
		if($recordId!==null&&!is_string($recordId)){throw new PanelCommandExecutionException('domain_invocation_invalid','The domain record id is invalid.');}
		$invocation=new PanelDomainCommandInvocation(
			$definition,$command->tenantId(),$command->actorId(),$command->idempotencyKey(),$data,$recordId,
			($input['dry_run']??false)===true,($input['confirmed']??false)===true,$context,
		);
		try{$result=$this->delegate->execute($invocation);}
		catch(PanelCommandExecutionException $error){throw$error;}
		catch(\Throwable $error){throw new PanelCommandExecutionException('domain_executor_failed','The host domain command executor failed.',$error);}
		$aggregateId=$recordId??$definition->domainId();
		$event=new PanelEventDraft(
			'domain.command_executed',$definition->entity(),$aggregateId,
			[
				'domain_id'=>$definition->domainId(),'domain_version'=>$definition->domainVersion(),
				'command'=>$definition->qualifiedName(),'entity'=>$definition->entity(),'operation'=>$definition->operation(),
				'record_id'=>$recordId,'dry_run'=>$invocation->dryRun(),
			],
			['source'=>'domain_command_executor','reversible'=>$definition->reversible()],
		);
		return PanelCommandOutcome::make($result,[$event],[
			'native_runtime'=>'domain_command_executor','domain_id'=>$definition->domainId(),'domain_command'=>$definition->qualifiedName(),
		]);
	}

	public function jsonSerialize():array{return ['type'=>'panel_delegating_domain_fabric_handler','version'=>1,'encrypted_invocation'=>true,'definition_integrity_check'=>true,'delegate_type'=>$this->delegate::class];}
}
