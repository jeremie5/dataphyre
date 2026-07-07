<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP client setup, onboarding, troubleshooting, and config-audit surfaces.
 */
trait dataphyre_mcp_client_setup_surfaces {

	/**
	 * Builds an installation checklist for a target MCP client.
	 *
	 * @param array<string,mixed> $args Target client and checklist options.
	 * @return array Client install checklist payload.
	 */
	private function mcp_client_install_checklist(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$config=$this->mcp_client_config_summary([
			'include_cwd'=>($args['include_cwd'] ?? false)===true,
			'php_command'=>trim((string)($args['php_command'] ?? 'php')) ?: 'php',
			'allow_unsafe'=>false,
		]);
		return [
			'checklist_type'=>'dataphyre_mcp_client_install_checklist',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'target'=>$target,
			'client_audience'=>$this->mcp_client_audience_contract('client_install_checklist'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_install_checklist'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_install_checklist'),
			'tool_audience_boundaries'=>$config['tool_audience_boundaries'] ?? $this->mcp_tool_audience_boundaries([]),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_install_checklist', ['target'=>$target]),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_install_checklist'),
			'transport_and_filesystem_boundary'=>$config['transport_and_filesystem_boundary'] ?? $this->mcp_transport_filesystem_boundary_contract(),
			'recommended_instruction_path'=>$this->agent_context_path($target),
			'server_entrypoint_contract'=>$config['server_entrypoint_contract'] ?? $this->mcp_server_entrypoint_contract(),
			'config'=>$config['manual_config'] ?? [],
			'generator_commands'=>$config['config_generator'] ?? [],
			'steps'=>[
				[
					'id'=>'generate_config',
					'action'=>'Generate or copy the portable stdio config for the MCP client.',
					'tool'=>'dataphyre_mcp_client_config_summary',
					'expected'=>'Client points command to php and args to common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php.',
				],
				[
					'id'=>'add_instructions',
					'action'=>'Generate target agent instructions and place them in the client-appropriate rules file if that client supports one.',
					'tool'=>'dataphyre_agent_context_generate',
					'expected'=>'Instructions include Dataphyre safety rules, module docs guidance, and app-agnostic runtime rules.',
				],
				[
					'id'=>'export_manifest',
					'action'=>'Export the live tool/resource/prompt manifest so the client can cache available capabilities.',
					'tool'=>'dataphyre_mcp_manifest_export',
					'expected'=>'Manifest reports protocol 2025-11-25, read-only default safety, and grouped tool names.',
				],
				[
					'id'=>'export_prompts',
					'action'=>'Export reusable prompt bundles for workflows the target client does not natively fetch via prompts/get.',
					'tool'=>'dataphyre_prompt_pack_export',
					'expected'=>'Prompt pack includes feature planning, diagnostics, routing, SQL, Panel, and release triage workflows.',
				],
				[
					'id'=>'verify_server',
					'action'=>'Validate local client wiring with smoke tests and live stdio validation.',
					'tool'=>'dataphyre_mcp_live_validate',
					'expected'=>'The local client can launch the stdio server, initialize, and see registered tools without app-specific strings in shared MCP code.',
					'audience_scope'=>'local_client_setup_not_app_behavior',
					'not_app_behavior_proof'=>true,
					'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
				],
			],
			'target_notes'=>$this->mcp_client_target_notes($target),
			'safety_notes'=>[
				'Checklist generation does not write client config files.',
				'Keep examples generic; product-local PHP paths and app server scripts do not belong in shared MCP docs or code.',
				'Unsafe mode must be a deliberate client config choice and should not be enabled for normal read-only installation.',
			],
			'recommended_tools'=>[
				'dataphyre_mcp_client_config_summary',
				'dataphyre_agent_context_generate',
				'dataphyre_mcp_manifest_export',
				'dataphyre_prompt_pack_export',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
			],
			'publication_validation'=>[
				'Use dataphyre_mcp_verify_all only before publishing shared MCP setup docs, release notes, or MCP/release-surface claims.',
				'Use Dataphyre MCP publication evidence only after changing MCP server wiring or public setup surfaces.',
			],
		];
	}

	/**
	 * Builds a configuration installation plan for a target MCP client.
	 *
	 * @param array<string,mixed> $args Target client and configuration options.
	 * @return array Client config install plan payload.
	 */
	private function mcp_client_config_install_plan(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$php_command=trim((string)($args['php_command'] ?? 'php'));
		if($php_command===''){
			$php_command='php';
		}
		$config_path=trim((string)($args['config_path'] ?? ''));
		if($config_path===''){
			$config_path=match($target){
				'codex'=>'<codex-client-config-path>',
				'claude'=>'<claude-desktop-config-path>',
				'cursor'=>'<cursor-mcp-config-path>',
				default=>'<mcp-client-config-path>',
			};
		}
		$config=$this->mcp_client_config_summary([
			'include_cwd'=>false,
			'php_command'=>$php_command,
			'allow_unsafe'=>false,
		]);
		return [
			'plan_type'=>'dataphyre_mcp_client_config_install_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'artifacts_written'=>false,
			'target'=>$target,
			'client_audience'=>$this->mcp_client_audience_contract('client_config_install_plan'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_config_install_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_config_install_plan'),
			'tool_audience_boundaries'=>$config['tool_audience_boundaries'] ?? $this->mcp_tool_audience_boundaries([]),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_config_install_plan', ['target'=>$target]),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_config_install_plan'),
			'transport_and_filesystem_boundary'=>$config['transport_and_filesystem_boundary'] ?? $this->mcp_transport_filesystem_boundary_contract(),
			'server_entrypoint_contract'=>$config['server_entrypoint_contract'] ?? $this->mcp_server_entrypoint_contract(),
			'config_path'=>$config_path,
			'current_safe_surfaces'=>[
				'config_summary'=>'dataphyre_mcp_client_config_summary',
				'install_checklist'=>'dataphyre_mcp_client_install_checklist',
				'config_audit'=>'dataphyre_mcp_client_config_audit',
				'smoke_tests'=>'dataphyre_mcp_smoke_test_export',
				'live_validation'=>'dataphyre_mcp_live_validate',
			],
			'proposed_config'=>$config['manual_config'] ?? [],
			'proposed_writes'=>[
				[
					'path'=>$config_path,
					'owner'=>'caller_owned_client_config',
					'write_policy'=>'caller_owned_write_only',
					'content'=>'merge or add mcpServers.dataphyre stdio entry from proposed_config',
				],
			],
			'preconditions'=>[
				'client config path must be caller-provided or resolved by the client, not hardcoded in shared MCP code',
				'caller must back up existing client config before writing changes',
				'installer must merge with existing mcpServers entries rather than replacing unrelated client configuration',
				'installer must keep unsafe mode disabled unless the caller explicitly chooses an unsafe profile',
				'installer must run dataphyre_mcp_client_config_audit before writing and a smoke test after writing',
			],
			'denied_writes'=>[
				'Dataphyre runtime or MCP module files',
				'application-specific config files',
				'product-local PHP binary paths in shared MCP code or docs',
				'existing unrelated MCP server entries',
				'unsafe flags without explicit caller opt-in',
			],
			'rollback_plan'=>[
				'restore the caller-owned config backup',
				'remove only the mcpServers.dataphyre entry if no backup is available',
				'run the client config audit and smoke-test export again after rollback',
			],
			'verification_steps'=>[
				[
					'tool'=>'dataphyre_mcp_client_config_audit',
					'purpose'=>'Audit the proposed merged config before writing caller-owned client config.',
					'audience_scope'=>'local_client_setup',
				],
				[
					'tool'=>'dataphyre_mcp_smoke_test_export',
					'purpose'=>'Export target shell/runtime smoke requests for local client setup.',
					'audience_scope'=>'local_client_setup',
				],
				[
					'tool'=>'dataphyre_mcp_live_validate',
					'purpose'=>'Validate stdio server wiring from the project root after adding the local client entry.',
					'audience_scope'=>'local_client_setup_not_app_behavior',
					'not_app_behavior_proof'=>true,
					'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
				],
				[
					'tool'=>'dataphyre_mcp_verify_all',
					'purpose'=>'Use only before publishing installer guidance or other MCP/release-surface claims.',
					'audience_scope'=>'publication_validation_not_ordinary_app_work',
				],
			],
			'safety_notes'=>[
				'This plan does not write client config files, create directories, launch clients, or mutate runtime code.',
				'Concrete config locations remain client-owned and target-specific.',
				'Keep the shared MCP module product-neutral and avoid committing machine-local paths.',
			],
		];
	}

	/**
	 * Returns client-specific configuration notes and caveats.
	 *
	 * @param string $target Client target identifier.
	 * @return array Target notes.
	 */
	private function mcp_client_target_notes(string $target): array {
		return match($target){
			'codex'=>[
				'Use the generated instructions as project guidance for Codex before editing Dataphyre runtime or application files.',
				'Keep MCP config in the client-managed configuration location rather than committing machine-local paths.',
			],
			'claude'=>[
				'Use the generated instructions as Claude project guidance and keep the stdio server command rooted at the project.',
				'Prefer prompt pack export when the client cannot fetch MCP prompts directly.',
			],
			'cursor'=>[
				'Use the generated instructions as Cursor rules content, typically at .cursor/rules/dataphyre.mdc when the project wants committed rules.',
				'Use manifest export to review tool names and schemas before creating custom Cursor commands.',
			],
			default=>[
				'Use the manual config JSON shape for any stdio-capable MCP client.',
				'Use generated agent context and prompt packs as optional client-side guidance.',
			],
		};
	}

	/**
	 * Exports a smoke-test plan for validating MCP client connectivity.
	 *
	 * @param array<string,mixed> $args Target client and smoke-test options.
	 * @return array Smoke-test plan payload.
	 */
	private function mcp_smoke_test_export(array $args): array {
		$format=strtolower(trim((string)($args['format'] ?? 'all')));
		if(!in_array($format, ['powershell', 'bash', 'node', 'php', 'all'], true)){
			$format='all';
		}
		$requests=[
			[
				'name'=>'initialize',
				'message'=>['jsonrpc'=>'2.0', 'id'=>1, 'method'=>'initialize', 'params'=>['protocolVersion'=>'2025-11-25', 'capabilities'=>[], 'clientInfo'=>['name'=>'dataphyre-mcp-smoke', 'version'=>'1.0.0']]],
				'expect'=>'result.serverInfo.name equals dataphyre-mcp',
			],
			[
				'name'=>'tools/list',
				'message'=>['jsonrpc'=>'2.0', 'id'=>2, 'method'=>'tools/list', 'params'=>[]],
				'expect'=>'result.tools includes dataphyre_mcp_doctor and dataphyre_mcp_manifest_export',
			],
			[
				'name'=>'prompts/list',
				'message'=>['jsonrpc'=>'2.0', 'id'=>3, 'method'=>'prompts/list', 'params'=>[]],
				'expect'=>'result.prompts includes dataphyre_runtime_guidelines',
			],
			[
				'name'=>'resources/read capabilities',
				'message'=>['jsonrpc'=>'2.0', 'id'=>4, 'method'=>'resources/read', 'params'=>['uri'=>'dataphyre://mcp-capabilities']],
				'expect'=>'resource JSON reports default_safety read_only',
			],
			[
				'name'=>'tools/call doctor',
				'message'=>['jsonrpc'=>'2.0', 'id'=>5, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_doctor', 'arguments'=>[]]],
				'expect'=>'tool JSON reports passed true and failed_count 0',
			],
		];
		$entrypoint_contract=$this->mcp_server_entrypoint_contract();
		$server_path=$entrypoint_contract['stdio_server'];
		$scripts=[
			'powershell'=>[
				'description'=>'Single initialize frame smoke test for PowerShell clients.',
				'command'=>"php {$server_path}",
				'script'=>implode("\n", [
					"\$body = '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-11-25\",\"capabilities\":{},\"clientInfo\":{\"name\":\"dataphyre-mcp-smoke\",\"version\":\"1.0.0\"}}}'",
					'$length = [System.Text.Encoding]::UTF8.GetByteCount($body)',
					'$frame = "Content-Length: $length`r`n`r`n$body"',
					'$frame | php common\dataphyre\runtime\modules\mcp\kernel\dataphyre_mcp.php',
				]),
			],
			'bash'=>[
				'description'=>'Single initialize frame smoke test for POSIX shells.',
				'command'=>"php {$server_path}",
				'script'=>implode("\n", [
					"body='{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-11-25\",\"capabilities\":{},\"clientInfo\":{\"name\":\"dataphyre-mcp-smoke\",\"version\":\"1.0.0\"}}}'",
					'length=$(printf "%s" "$body" | wc -c | tr -d " ")',
					'printf "Content-Length: %s\r\n\r\n%s" "$length" "$body" | php common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
				]),
			],
			'node'=>[
				'description'=>'Spawn the stdio server and send one initialize frame from Node.js.',
				'command'=>'node smoke-mcp.js',
				'script'=>implode("\n", [
					"const { spawnSync } = require('node:child_process');",
					"const body = JSON.stringify({jsonrpc:'2.0', id:1, method:'initialize', params:{protocolVersion:'2025-11-25', capabilities:{}, clientInfo:{name:'dataphyre-mcp-smoke', version:'1.0.0'}}});",
					'const frame = `Content-Length: ${Buffer.byteLength(body)}\r\n\r\n${body}`;',
					"const result = spawnSync('php', ['common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php'], {input: frame, encoding: 'utf8'});",
					"process.stdout.write(result.stdout);",
					"process.stderr.write(result.stderr);",
					"process.exit(result.status ?? 1);",
				]),
			],
			'php'=>[
				'description'=>'Spawn the stdio server and send one initialize frame from PHP.',
				'command'=>'php smoke-mcp.php',
				'script'=>implode("\n", [
					'<?php',
					'$body=json_encode(["jsonrpc"=>"2.0","id"=>1,"method"=>"initialize","params"=>["protocolVersion"=>"2025-11-25","capabilities"=>(object)[],"clientInfo"=>["name"=>"dataphyre-mcp-smoke","version"=>"1.0.0"]]], JSON_UNESCAPED_SLASHES);',
					'$frame="Content-Length: ".strlen($body)."\\r\\n\\r\\n".$body;',
					'$descriptor=[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];',
					'$process=proc_open([PHP_BINARY, "common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php"], $descriptor, $pipes, getcwd());',
					'fwrite($pipes[0], $frame); fclose($pipes[0]);',
					'echo stream_get_contents($pipes[1]);',
					'fwrite(STDERR, stream_get_contents($pipes[2]));',
					'exit(proc_close($process));',
				]),
			],
		];
		$selected_scripts=$format==='all' ? $scripts : [$format=>$scripts[$format]];
		return [
			'export_type'=>'dataphyre_mcp_smoke_test_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'transport'=>'stdio',
			'protocol'=>'2025-11-25',
			'server_entrypoint_contract'=>$entrypoint_contract,
			'client_audience'=>$this->mcp_client_audience_contract('smoke_test_export'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('smoke_test_export'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('smoke_test_export'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('smoke_test_export'),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('smoke_test_export'),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'server_command'=>[
				'command'=>'php',
				'args'=>[$server_path],
				'cwd'=>'project root',
			],
			'requests'=>$requests,
			'scripts'=>$selected_scripts,
			'recommended_validation_tools'=>[
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_client_config_audit',
			],
			'recommended_validation_boundaries'=>$this->mcp_client_validation_tool_boundaries([
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_client_config_audit',
			]),
			'publication_validation'=>$this->mcp_publication_validation_contract('smoke_test_export'),
			'safety_notes'=>[
				'Smoke exports do not execute commands or write client files.',
				'Examples use portable php commands and repo-relative server paths.',
				'Keep product-local PHP paths in local client config only, not shared MCP code or docs.',
			],
		];
	}

	/**
	 * Builds an onboarding pack for a target MCP client.
	 *
	 * @param array<string,mixed> $args Target client and onboarding options.
	 * @return array Client onboarding payload.
	 */
	private function mcp_client_onboarding_pack(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$smoke_format=strtolower(trim((string)($args['smoke_format'] ?? 'all')));
		if(!in_array($smoke_format, ['powershell', 'bash', 'node', 'php', 'all'], true)){
			$smoke_format='all';
		}
		$config=$this->mcp_client_config_summary([
			'include_cwd'=>false,
			'php_command'=>'php',
			'allow_unsafe'=>false,
		]);
		$checklist=$this->mcp_client_install_checklist([
			'target'=>$target,
			'include_cwd'=>false,
			'php_command'=>'php',
		]);
		$smoke=$this->mcp_smoke_test_export(['format'=>$smoke_format]);
		$prompt_catalog=$this->mcp_prompt_catalog([]);
		$manifest=$this->mcp_manifest_export([
			'include_schemas'=>($args['include_schemas'] ?? false)===true,
			'include_docs_resources'=>false,
		]);
		return [
			'pack_type'=>'dataphyre_mcp_client_onboarding_pack',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'target'=>$target,
			'transport'=>'stdio',
			'protocol'=>'2025-11-25',
			'client_audience'=>$this->mcp_client_audience_contract('client_onboarding_pack'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_onboarding_pack'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_onboarding_pack'),
			'tool_audience_boundaries'=>$manifest['tool_audience_boundaries'] ?? $this->mcp_tool_audience_boundaries([]),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_onboarding_pack', ['target'=>$target]),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_onboarding_pack'),
			'transport_and_filesystem_boundary'=>$manifest['transport_and_filesystem_boundary'] ?? $this->mcp_transport_filesystem_boundary_contract(),
			'config'=>$config['manual_config'] ?? [],
			'config_generator'=>$config['config_generator'] ?? [],
			'instruction_path'=>$checklist['recommended_instruction_path'] ?? null,
			'install_steps'=>$checklist['steps'] ?? [],
			'target_notes'=>$checklist['target_notes'] ?? [],
			'smoke_tests'=>[
				'format'=>$smoke_format,
				'requests'=>$smoke['requests'] ?? [],
				'scripts'=>$smoke['scripts'] ?? [],
			],
			'prompt_catalog'=>[
				'prompt_count'=>$prompt_catalog['prompt_count'] ?? 0,
				'prompts'=>$prompt_catalog['prompts'] ?? [],
			],
			'manifest_excerpt'=>[
				'tool_count'=>is_array($manifest['tools'] ?? null) ? count($manifest['tools']) : 0,
				'prompt_count'=>is_array($manifest['prompts'] ?? null) ? count($manifest['prompts']) : 0,
				'resource_count'=>is_array($manifest['resources'] ?? null) ? count($manifest['resources']) : 0,
				'tool_groups'=>$manifest['tool_groups'] ?? [],
				'safety'=>$manifest['safety'] ?? [],
				'application_agent_operating_contract'=>$manifest['application_agent_operating_contract'] ?? $this->mcp_application_agent_operating_contract('client_onboarding_manifest_excerpt'),
				'ordinary_app_work'=>$manifest['ordinary_app_work'] ?? $this->mcp_ordinary_app_work_contract('client_onboarding_manifest_excerpt'),
				'tool_audience_boundaries'=>$manifest['tool_audience_boundaries'] ?? $this->mcp_tool_audience_boundaries([]),
				'transport_and_filesystem_boundary'=>$manifest['transport_and_filesystem_boundary'] ?? $this->mcp_transport_filesystem_boundary_contract(),
			],
			'validation_plan'=>[
				[
					'tool'=>'dataphyre_mcp_live_validate',
					'purpose'=>'Run only when local MCP wiring changed, smoke output is inconclusive, or a client setup failure needs deeper stdio validation.',
					'audience_scope'=>'local_client_setup_not_app_behavior',
					'not_app_behavior_proof'=>true,
					'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
				],
				[
					'tool'=>'dataphyre_mcp_verify_all',
					'purpose'=>'Use only before publishing shared MCP setup docs, release notes, or MCP/release-surface claims.',
					'audience_scope'=>'publication_validation_not_ordinary_app_work',
				],
				[
					'tool'=>'dataphyre_mcp_docs_coverage_report',
					'purpose'=>'Use after adding any public MCP tool, prompt, resource, or safety boundary.',
					'audience_scope'=>'publication_validation_not_ordinary_app_work',
				],
			],
			'portable_policy'=>[
				'Use repo-relative server paths in committed docs and examples.',
				'Keep product-local PHP paths, local app names, and server scripts in private client config only.',
				'Unsafe mode is not included in the onboarding pack; enable it only by deliberate local opt-in.',
			],
		];
	}

	/**
	 * Produces troubleshooting guidance for common MCP client failures.
	 *
	 * @param array<string,mixed> $args Target client and observed symptom options.
	 * @return array Troubleshooting payload.
	 */
	private function mcp_client_troubleshoot(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$symptoms=array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), (array)($args['symptoms'] ?? [])), static fn(string $value): bool => $value!==''));
		$haystack=strtolower(implode("\n", $symptoms));
		$diagnoses=[];
		$rules=[
			[
				'id'=>'no_server_response',
				'matches'=>['no response', 'timed out', 'timeout', 'eof', 'server disconnected', 'closed'],
				'cause'=>'The client may not be launching the stdio server, or the server is exiting before it reads a complete frame.',
				'fixes'=>[
					'Run dataphyre_mcp_live_validate from the project root for local client setup.',
					'Confirm the client command is php and args include common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php.',
					'Confirm the client cwd is the project root or that the server is launched from the project root.',
				],
			],
			[
				'id'=>'invalid_framing',
				'matches'=>['content-length', 'invalid json', 'parse error', 'missing header', 'framing'],
				'cause'=>'The client smoke request may be missing MCP stdio Content-Length framing or may be sending malformed JSON.',
				'fixes'=>[
					'Use dataphyre_mcp_smoke_test_export for known-good framed initialize examples.',
					'Compute Content-Length from the byte length of the JSON body.',
					'Send \\r\\n\\r\\n between headers and the JSON body.',
				],
			],
			[
				'id'=>'wrong_working_directory',
				'matches'=>['not found', 'unable to resolve', 'no such file', 'cannot open', 'failed opening required'],
				'cause'=>'The server or helper script is probably being launched from the wrong directory.',
				'fixes'=>[
					'Run the server from the project root.',
					'Use dataphyre_mcp_client_config_summary to generate repo-relative args.',
					'If the client supports cwd, set it to the project root in local client config.',
				],
			],
			[
				'id'=>'php_binary',
				'matches'=>['php is not recognized', 'php: command not found', 'no syntax errors detected', 'php binary', 'php.exe'],
				'cause'=>'The client and verification helpers may be using different PHP binaries.',
				'fixes'=>[
					'Use the client-local PHP path only in private client config.',
					'Set DATAPHYRE_MCP_PHP_BINARY for MCP verification helpers that spawn PHP.',
					'Do not commit product-local PHP paths into shared MCP code or docs.',
				],
			],
			[
				'id'=>'missing_tool',
				'matches'=>['unknown dataphyre tool', 'missing tool', 'tool not found', 'method not found'],
				'cause'=>'The client may have a stale manifest cache or be connected to an older server process.',
				'fixes'=>[
					'Restart the MCP client process.',
					'Call dataphyre_mcp_manifest_export or tools/list to refresh the live tool list.',
					'Run dataphyre_mcp_live_validate to confirm core public tools are registered.',
				],
			],
			[
				'id'=>'unsafe_expectation',
				'matches'=>['sql execution', 'route dispatch', 'write files', 'mutating', 'unsafe', 'permission'],
				'cause'=>'Dataphyre MCP defaults to read-only and intentionally withholds unsafe runtime actions unless explicitly gated.',
				'fixes'=>[
					'Use planner and summary tools first, such as dataphyre_sql_query_plan or dataphyre_apply_audit_plan.',
					'Only enable --allow-unsafe or DATAPHYRE_MCP_ALLOW_UNSAFE=1 for deliberate local runs.',
					'Do not expect route dispatch, config secrets, or live SQL execution from the default server.',
				],
			],
		];
		foreach($rules as $rule){
			$matched=[];
			foreach($rule['matches'] as $term){
				if($haystack!=='' && str_contains($haystack, strtolower((string)$term))){
					$matched[]=$term;
				}
			}
			if($matched!==[]){
				$diagnoses[]=[
					'id'=>$rule['id'],
					'matched_terms'=>$matched,
					'likely_cause'=>$rule['cause'],
					'recommended_fixes'=>$rule['fixes'],
				];
			}
		}
		if($diagnoses===[]){
			$diagnoses[]=[
				'id'=>'generic_client_setup',
				'matched_terms'=>[],
				'likely_cause'=>'No specific known failure signature matched the supplied symptoms.',
				'recommended_fixes'=>[
					'Start with dataphyre_mcp_client_onboarding_pack for the target client.',
					'Run dataphyre_mcp_smoke_test_export and verify initialize over stdio.',
					'Run dataphyre_mcp_live_validate for local setup; reserve publication validation for published shared MCP setup docs or MCP/release-surface claims.',
				],
			];
		}
		return [
			'troubleshoot_type'=>'dataphyre_mcp_client_troubleshoot',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'target'=>$target,
			'client_audience'=>$this->mcp_client_audience_contract('client_troubleshoot'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_troubleshoot'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_troubleshoot'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_troubleshoot', ['target'=>$target]),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_troubleshoot'),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'symptom_count'=>count($symptoms),
			'diagnoses'=>$diagnoses,
			'baseline_checks'=>[
				'dataphyre_mcp_client_config_summary',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
			],
			'baseline_check_boundaries'=>$this->mcp_client_validation_tool_boundaries([
				'dataphyre_mcp_client_config_summary',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
			]),
			'publication_validation'=>$this->mcp_publication_validation_contract('client_troubleshoot'),
			'target_notes'=>$this->mcp_client_target_notes($target),
			'portable_policy'=>[
				'Keep committed examples generic and repo-relative.',
				'Keep product-local paths in private client config only.',
				'Do not hardcode application-specific names in shared MCP module files.',
			],
		];
	}

	/**
	 * Builds a client compatibility matrix for Dataphyre MCP capabilities.
	 *
	 * @param array<string,mixed> $args Optional client filters and matrix options.
	 * @return array Compatibility matrix payload.
	 */
	private function mcp_client_compatibility_matrix(array $args): array {
		$requested=array_values(array_filter(array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), (array)($args['targets'] ?? [])), static fn(string $value): bool => $value!==''));
		$supported=['codex', 'claude', 'cursor', 'generic'];
		$targets=$requested===[] ? $supported : array_values(array_intersect($supported, $requested));
		if($targets===[]){
			$targets=$supported;
		}
		$rows=[];
		foreach($targets as $target){
			$checklist=$this->mcp_client_install_checklist(['target'=>$target, 'php_command'=>'php']);
			$preferred_smoke=match($target){
				'codex', 'cursor'=>'powershell',
				'claude'=>'node',
				default=>'all',
			};
			$rows[]=[
				'target'=>$target,
				'status'=>'supported_stdio',
				'transport'=>'stdio',
				'config_source'=>'dataphyre_mcp_client_config_summary',
				'instruction_path'=>$checklist['recommended_instruction_path'] ?? $this->agent_context_path($target),
				'preferred_smoke_format'=>$preferred_smoke,
				'onboarding_tool'=>'dataphyre_mcp_client_onboarding_pack',
				'troubleshooting_tool'=>'dataphyre_mcp_client_troubleshoot',
				'validation_tools'=>[
					'dataphyre_mcp_smoke_test_export',
					'dataphyre_mcp_live_validate',
				],
				'validation_tool_boundaries'=>$this->mcp_client_validation_tool_boundaries([
					'dataphyre_mcp_smoke_test_export',
					'dataphyre_mcp_live_validate',
				]),
				'publication_validation'=>$this->mcp_publication_validation_contract('client_compatibility_matrix_row'),
				'notes'=>$this->mcp_client_target_notes($target),
				'known_caveats'=>$this->mcp_client_compatibility_caveats($target),
			];
		}
		return [
			'matrix_type'=>'dataphyre_mcp_client_compatibility_matrix',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'transport'=>'stdio',
			'protocol'=>'2025-11-25',
			'supported_targets'=>$supported,
			'targets'=>$targets,
			'client_audience'=>$this->mcp_client_audience_contract('client_compatibility_matrix'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_compatibility_matrix'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_compatibility_matrix'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_compatibility_matrix', ['target'=>$targets[0] ?? 'generic']),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_compatibility_matrix'),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'rows'=>$rows,
			'portable_policy'=>[
				'Commit repo-relative server args only.',
				'Keep local PHP binary paths and client-specific cwd values in private client configuration.',
				'Use read-only setup by default; unsafe mode is opt-in and not part of normal compatibility checks.',
			],
			'recommended_sequence'=>[
				'dataphyre_mcp_client_onboarding_pack',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_client_troubleshoot',
			],
			'recommended_sequence_boundaries'=>$this->mcp_client_validation_tool_boundaries([
				'dataphyre_mcp_client_onboarding_pack',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_client_troubleshoot',
			]),
			'publication_validation'=>[
				'Use dataphyre_mcp_verify_all only before publishing shared MCP setup docs, release notes, or MCP/release-surface claims.',
			],
		];
	}

	/**
	 * Returns compatibility caveats for one MCP client target.
	 *
	 * @param string $target Client target identifier.
	 * @return array Caveat strings.
	 */
	private function mcp_client_compatibility_caveats(string $target): array {
		return match($target){
			'codex'=>[
				'Use local project guidance for Dataphyre editing rules before changing runtime or app code.',
				'Prefer live validation from the project root after editing MCP surfaces.',
			],
			'claude'=>[
				'Use prompt packs when the client does not fetch MCP prompts directly.',
				'Keep command and cwd configuration in the client-managed settings location.',
			],
			'cursor'=>[
				'Committed Cursor rules are acceptable only when they remain app-agnostic.',
				'Refresh manifest-derived custom commands after tool registration changes.',
			],
			default=>[
				'Generic clients must support MCP stdio Content-Length framing.',
				'Use the smoke-test export to verify the client can send initialize and read a framed response.',
			],
		};
	}

	/**
	 * Describes what client validation helpers prove so agents do not upgrade setup checks into app proof.
	 *
	 * @param array<int,string> $tools Client helper tool names to describe.
	 * @return array<string,array<string,mixed>> Tool-name keyed boundary metadata.
	 */
	private function mcp_client_validation_tool_boundaries(array $tools): array {
		$catalog=[
			'dataphyre_mcp_client_onboarding_pack'=>[
				'audience_scope'=>'local_client_setup',
				'purpose'=>'Collect portable client setup instructions, smoke fixtures, and next actions without proving application behavior.',
			],
			'dataphyre_mcp_client_config_summary'=>[
				'audience_scope'=>'local_client_setup',
				'purpose'=>'Inspect client command, args, cwd guidance, and portable config metadata.',
			],
			'dataphyre_mcp_client_config_audit'=>[
				'audience_scope'=>'local_client_setup',
				'purpose'=>'Check caller-provided client config shape and portability before smoke testing.',
			],
			'dataphyre_mcp_smoke_test_export'=>[
				'audience_scope'=>'local_client_setup',
				'purpose'=>'Generate known-good framed stdio smoke requests for the target client.',
			],
			'dataphyre_mcp_live_validate'=>[
				'audience_scope'=>'local_client_setup_not_app_behavior',
				'purpose'=>'Validate local MCP server wiring and core tool registration after setup or client failures.',
				'not_app_behavior_proof'=>true,
				'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
			],
			'dataphyre_mcp_client_troubleshoot'=>[
				'audience_scope'=>'local_client_setup',
				'purpose'=>'Diagnose client stdio, framing, cwd, PHP binary, stale manifest, or unsafe-mode expectation issues.',
			],
		];
		$boundaries=[];
		foreach($tools as $tool){
			if(isset($catalog[$tool])){
				$boundaries[$tool]=$catalog[$tool];
			}
		}
		return $boundaries;
	}

	/**
	 * Audits a target MCP client configuration for readiness and safety.
	 *
	 * @param array<string,mixed> $args Client target and configuration details.
	 * @return array Client configuration audit payload.
	 */
	private function mcp_client_config_audit(array $args): array {
		$config=[];
		$parse_error=null;
		if(isset($args['config']) && is_array($args['config'])){
			$config=$args['config'];
		}elseif(isset($args['config_json']) && trim((string)$args['config_json'])!==''){
			$decoded=json_decode((string)$args['config_json'], true);
			if(is_array($decoded)){
				$config=$decoded;
			}else{
				$parse_error=json_last_error_msg();
			}
		}
		$server=is_array($config['mcpServers']['dataphyre'] ?? null) ? $config['mcpServers']['dataphyre'] : null;
		$issues=[];
		$warnings=[];
		$passes=[];
		if($parse_error!==null){
			$issues[]=[
				'id'=>'invalid_json',
				'severity'=>'error',
				'message'=>'config_json is not valid JSON: '.$parse_error,
				'fix'=>'Pass decoded config through config or valid JSON through config_json.',
			];
		}
		if($config===[]){
			$issues[]=[
				'id'=>'empty_config',
				'severity'=>'error',
				'message'=>'No client config was provided.',
				'fix'=>'Use dataphyre_mcp_client_config_summary or dataphyre_mcp_client_onboarding_pack to get a portable baseline.',
			];
		}elseif($server===null){
			$issues[]=[
				'id'=>'missing_dataphyre_server',
				'severity'=>'error',
				'message'=>'Config does not define mcpServers.dataphyre.',
				'fix'=>'Add a dataphyre server entry with command php and args pointing to the MCP server script.',
			];
		}else{
			$command=(string)($server['command'] ?? '');
			$args_list=is_array($server['args'] ?? null) ? array_values(array_map('strval', $server['args'])) : [];
			$cwd=trim((string)($server['cwd'] ?? ''));
			$entrypoint_contract=$this->mcp_server_entrypoint_contract();
			$expected_server=$entrypoint_contract['stdio_server'];
			$module_bootstrap=$entrypoint_contract['module_bootstrap'];
			if($command===''){
				$issues[]=[
					'id'=>'missing_command',
					'severity'=>'error',
					'message'=>'mcpServers.dataphyre.command is missing.',
					'fix'=>'Set command to php or to a private local PHP binary path.',
				];
			}elseif(strtolower(basename(str_replace('\\', '/', $command)))==='php' || str_ends_with(strtolower($command), 'php.exe')){
				$passes[]='command_present';
			}else{
				$warnings[]=[
					'id'=>'non_php_command',
					'severity'=>'warning',
					'message'=>'Dataphyre MCP expects a PHP command; this config uses '.$command.'.',
					'fix'=>'Use php as the portable command, or keep a private local PHP binary path only in local client config.',
				];
			}
			if(!in_array($expected_server, $args_list, true)){
				$issues[]=[
					'id'=>'missing_server_arg',
					'severity'=>'error',
					'message'=>'args does not include '.$expected_server.'.',
					'fix'=>'Set args to include the repo-relative MCP server path.',
				];
			}else{
				$passes[]='server_arg_present';
			}
			if(in_array($module_bootstrap, $args_list, true)){
				$issues[]=[
					'id'=>'module_bootstrap_used_as_server',
					'severity'=>'error',
					'message'=>'args uses '.$module_bootstrap.', which is the Dataphyre runtime module bootstrap, not the MCP stdio server.',
					'fix'=>'Launch '.$expected_server.' as the MCP stdio server.',
				];
			}
			if(in_array('--allow-unsafe', $args_list, true)){
				$warnings[]=[
					'id'=>'unsafe_enabled',
					'severity'=>'warning',
					'message'=>'Config enables --allow-unsafe.',
					'fix'=>'Use read-only mode for normal client setup; keep unsafe mode for deliberate local runs only.',
				];
			}else{
				$passes[]='unsafe_not_enabled';
			}
			if($cwd===''){
				$warnings[]=[
					'id'=>'cwd_not_set',
					'severity'=>'warning',
					'message'=>'cwd is not set; this is acceptable only if the client launches from the project root.',
					'fix'=>'Set cwd to the project root in private client config when the client supports it.',
				];
			}else{
				$passes[]='cwd_present';
			}
			$serialized=json_encode($server, JSON_UNESCAPED_SLASHES) ?: '';
			$app_pattern='/'.implode('|', [
				'sho'.'piro',
				'applications\\/sho'.'piro',
				'tools\\/sho'.'piro',
				'\\.local\\/sho'.'piro',
			]).'/i';
			if(preg_match($app_pattern, $serialized)===1){
				$issues[]=[
					'id'=>'product_local_path',
					'severity'=>'error',
					'message'=>'Config contains product-local path or application-specific text.',
					'fix'=>'Keep product-local paths out of shared examples; use private local client config only.',
				];
			}else{
				$passes[]='no_product_local_paths';
			}
		}
		$passed=$issues===[];
		return [
			'audit_type'=>'dataphyre_mcp_client_config_audit',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'passed'=>$passed,
			'issue_count'=>count($issues),
			'warning_count'=>count($warnings),
			'client_audience'=>$this->mcp_client_audience_contract('client_config_audit'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_config_audit'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_config_audit'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_config_audit', ['audit_passed'=>$passed]),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_config_audit'),
			'server_entrypoint_contract'=>$this->mcp_server_entrypoint_contract(),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'passes'=>array_values(array_unique($passes)),
			'issues'=>$issues,
			'warnings'=>$warnings,
			'expected'=>[
				'server_key'=>'mcpServers.dataphyre',
				'command'=>'php',
				'server_arg'=>'common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
				'module_bootstrap'=>'common/dataphyre/runtime/modules/mcp/kernel/mcp.main.php',
				'default_mode'=>'read_only',
			],
			'recommended_followup'=>[
				'dataphyre_mcp_client_config_summary',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_client_troubleshoot',
			],
		];
	}
}
