<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Runtime-only callback adapter for an application-owned secure job repository. */
final class PanelAgentCallbackWorkflowJobResolver implements PanelAgentWorkflowJobResolver,\JsonSerializable {
	private readonly string $fingerprint;
	private readonly \Closure $resolver;

	public function __construct(string $fingerprint,callable $resolver){
		$this->fingerprint=PanelAgentGuard::digest($fingerprint,'workflow resolver fingerprint');
		$this->resolver=\Closure::fromCallable($resolver);
	}

	public function fingerprint():string{return$this->fingerprint;}
	public function resolve(PanelAgentWorkflowJob $job,PanelAgentWorkflowWorkerContext $context):PanelAgentDeferredExecution{
		$result=($this->resolver)($job,$context);
		if(!$result instanceof PanelAgentDeferredExecution){ throw new \UnexpectedValueException('Panel agent workflow resolver must return PanelAgentDeferredExecution.'); }
		return$result;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return['type'=>'panel_agent_callback_workflow_job_resolver','version'=>1,'fingerprint'=>$this->fingerprint,'callback_serialized'=>false,'runtime_resolver_installed'=>true,'storage_authority'=>'host'];
	}
}
