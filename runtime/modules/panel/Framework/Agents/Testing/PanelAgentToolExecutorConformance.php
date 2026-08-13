<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Pure conformance checks for host executor fixtures without causing execution. */
final class PanelAgentToolExecutorConformance implements \JsonSerializable {
	/** @return list<array{id:string,requirement:string}> */
	public static function cases(): array {
		return [
			['id'=>'dry_run_honored','requirement'=>'A dry-run request must not create external side effects.'],
			['id'=>'idempotency_honored','requirement'=>'The exact idempotency key must reach the downstream operation.'],
			['id'=>'cancellation_honored','requirement'=>'The adapter must stop promptly when its host cancellation signal is active.'],
			['id'=>'json_output_only','requirement'=>'Success output and metadata must contain bounded JSON values only.'],
			['id'=>'generic_errors','requirement'=>'Failures must not expose credentials, callbacks, classes, or raw prompts.'],
		];
	}

	/** @return list<string> */
	public static function inspect(PanelAgentToolExecutionResult $result, PanelAgentTool $tool): array {
		$issues=[];
		if($result->ok() && $result->error()!==null){ $issues[]='Successful executor results cannot contain an error.'; }
		if(!$result->ok() && ($result->error()===null || trim($result->error())==='')){ $issues[]='Failed executor results require a non-empty error.'; }
		if(!$result->ok() && $result->output()!==null){ $issues[]='Failed executor results cannot contain output.'; }
		try{ if($result->ok()){ PanelAgentGuard::boundedOutput($result->output(),$tool->outputByteLimit()); } PanelAgentGuard::assertJson($result->metadata(),32768); }
		catch(\Throwable){ $issues[]='Executor output or metadata is not bounded JSON.'; }
		if($result->error()!==null && strlen($result->error())>$tool->errorByteLimit()){ $issues[]='Executor errors must be bounded before leaving the adapter.'; }
		return $issues;
	}

	public function jsonSerialize(): array { return ['type'=>'panel_agent_tool_executor_conformance','version'=>1,'cases'=>self::cases(),'executes_tools'=>false]; }
}
