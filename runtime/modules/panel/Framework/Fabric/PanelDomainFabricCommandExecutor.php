<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Sends materialized domain commands through the unified policy/idempotency fabric. */
final class PanelDomainFabricCommandExecutor implements PanelDomainCommandExecutor,\JsonSerializable {
	public function __construct(private readonly PanelCommandFabric $fabric){}

	public function execute(PanelDomainCommandInvocation $invocation):mixed {
		$definition=$invocation->command();
		$context=$invocation->context();
		$roles=is_array($context['roles']??null)?$context['roles']:[];
		$permissions=is_array($context['permissions']??null)?$context['permissions']:[];
		$metadata=[
			'domain_id'=>$definition->domainId(),'domain_version'=>$definition->domainVersion(),'entity'=>$definition->entity(),
			'operation'=>$definition->operation(),'record_id'=>$invocation->recordId(),'dry_run'=>$invocation->dryRun(),
			'confirmed'=>$invocation->confirmed(),'resource'=>$invocation->recordId()!==null?['type'=>$definition->entity(),'id'=>$invocation->recordId()]:null,
		];
		$command=new PanelCommandEnvelope(
			'domain.'.$definition->domainId().'.'.$definition->name(),$definition->ability(),$invocation->tenantId(),$invocation->actorId(),
			$invocation->idempotencyKey(),[
				'definition'=>$definition->jsonSerialize(),'input'=>$invocation->input(),'record_id'=>$invocation->recordId(),
				'dry_run'=>$invocation->dryRun(),'confirmed'=>$invocation->confirmed(),'context'=>$context,
			],$definition->risk(),$roles,$permissions,
			is_string($context['correlation_id']??null)?$context['correlation_id']:null,
			is_string($context['causation_id']??null)?$context['causation_id']:null,
			is_int($context['expected_revision']??null)?$context['expected_revision']:null,$metadata,
		);
		$receipt=$this->fabric->dispatch($command);
		if(!$receipt->ok()){
			throw new PanelCommandExecutionException('domain_command_failed',$receipt->error()??'The domain command was refused.');
		}
		return $receipt->result();
	}

	public function jsonSerialize():array{return ['type'=>'panel_domain_fabric_command_executor','version'=>1,'policy_gated'=>true,'tenant_scoped_idempotency'=>true,'fabric'=>$this->fabric];}
}
