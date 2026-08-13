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
	* Narrow bridge to the existing AutomationExecutor.
	*
	* It preserves Automation validation, policy, confirmation, idempotency, receipt,
	* and failure behavior. It does not install actions or bypass their checks.
 */
final class PanelAgentAutomationToolExecutor implements PanelAgentToolExecutor, \JsonSerializable {
	/** @var list<string> */ private array $permissions;

	/** @param list<string> $permissions */
	public function __construct(
		private readonly AutomationExecutor $executor,
		private readonly string $action,
		array $permissions=[],
		private readonly ?string $confirmationPhrase=null
	){
		PanelAgentGuard::identifier($action, 'automation action', 128);
		$this->permissions=[];
		foreach($permissions as $permission){ $this->permissions[]=PanelAgentGuard::identifier((string)$permission, 'automation permission', 160); }
		$this->permissions=array_values(array_unique($this->permissions)); sort($this->permissions, SORT_STRING);
		if($confirmationPhrase!==null){ PanelAgentGuard::boundedString($confirmationPhrase, 'automation confirmation phrase', 512); }
	}

	public function execute(PanelAgentToolExecutionRequest $request): PanelAgentToolExecutionResult {
		$actor=WorkflowActor::from(['id'=>'panel-agent:'.$request->context()->tenantFingerprint().':'.$request->context()->subjectFingerprint(),'permissions'=>$this->permissions]);
		$automationRequest=new AutomationExecutionRequest(
			$request->arguments(),$actor,$request->idempotencyKey(),$request->dryRun(),$request->confirmed(),
			$request->confirmed() ? $this->confirmationPhrase : null,
			['panel_agent'=>['panel'=>$request->context()->panel(),'scope_fingerprint'=>$request->context()->scopeFingerprint(),'plan_hash'=>$request->planHash(),'step'=>$request->step()]]
		);
		$result=$request->dryRun() ? $this->executor->plan($this->action,$automationRequest) : $this->executor->execute($this->action,$automationRequest);
		if(!$result->ok()){
			return PanelAgentToolExecutionResult::failure('Automation action failed: '.$result->code().'. '.$result->message(),false,['automation_code'=>$result->code()]);
		}
		$output=$request->dryRun() ? $result->plan()?->jsonSerialize() : $result->receipt()?->result();
		return PanelAgentToolExecutionResult::success($output,['automation_code'=>$result->code(),'receipt_present'=>$result->receipt()!==null]);
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_automation_tool_executor','version'=>1,'action'=>strtolower(trim($this->action)),
			'permission_count'=>count($this->permissions),'confirmation_phrase_configured'=>$this->confirmationPhrase!==null,
			'automation_guards_preserved'=>true,'executor_class_exposed'=>false,'secrets_exposed'=>false,
		];
	}
}
