<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP app-builder planning surfaces and helpers.
 */
trait dataphyre_mcp_planning_app_builder_surfaces {

	use dataphyre_mcp_planning_app_builder_schema_surfaces;
	use dataphyre_mcp_planning_app_builder_contract_surfaces;
	use dataphyre_mcp_planning_app_builder_response_surfaces;

	/**
	 * Builds the compact app-builder lane used by task/start surfaces.
	 *
	 * This lane keeps ordinary application work practical: agents see likely
	 * files, edits, scaffold tools, and focused verification before the heavier
	 * governance annex. It is still read-only and only derives planning data.
	 *
	 * @param string $task User task text.
	 * @param array<string,mixed> $args Optional builder lane options.
	 * @return array<string,mixed> Compact app-building guidance.
	 */
	private function app_builder_lane(string $task, array $args=[]): array {
		$task=trim($task);
		$scaffold_type=$this->infer_app_builder_scaffold_type($task, $args);
		$entities=$this->infer_app_builder_entities($task, $args);
		$field_context_args=$args;
		$field_context_args['entities']=$entities;
		$app_path_context=$this->app_builder_path_context($args);
		$application_path_for_plans=($app_path_context['path_input_valid'] ?? true)===true
			? (string)($app_path_context['application_path'] ?? '')
			: '';
		$app_namespace_for_plans=($app_path_context['namespace_input_valid'] ?? true)===true
			? (string)($app_path_context['app_namespace'] ?? 'App')
			: 'App';
		$max_entities=$this->app_builder_max_entities($args);
		$planned_entities=array_values(array_slice($entities, 0, $max_entities));
		$plans=[];
		foreach($planned_entities as $entity){
			$plans[]=$this->generate_scaffold_plan([
				'type'=>$scaffold_type,
				'name'=>$entity,
				'fields'=>$this->app_builder_fields_for_entity($entity, $field_context_args),
				'module'=>trim((string)($args['module'] ?? '')),
				'application'=>trim((string)($args['application'] ?? '')),
				'application_path'=>$application_path_for_plans,
				'app_namespace'=>$app_namespace_for_plans,
				'path'=>trim((string)($args['path'] ?? '')),
				'methods'=>is_array($args['methods'] ?? null) ? $args['methods'] : [],
				'group'=>trim((string)($args['group'] ?? '')),
				'auth'=>trim((string)($args['auth'] ?? '')),
			]);
		}
		$schema_context=[];
		foreach($plans as $plan){
			$schema_context[]=$this->app_builder_schema_context_from_plan($plan);
		}
		$files=[];
		$verification=[];
		$next_edits=[];
		$code_skeletons=[];
		$data_model=[];
		foreach($plans as $plan){
			foreach($plan['proposed_files'] ?? [] as $file){
				$files[]=(string)$file;
			}
			foreach($plan['verification'] ?? [] as $tool){
				$verification[]=(string)$tool;
			}
			foreach($plan['steps'] ?? [] as $step){
				$next_edits[]=(string)$step;
			}
			foreach($this->app_builder_code_skeletons($plan) as $skeleton){
				$code_skeletons[]=$skeleton;
			}
			$code_unit_test_skeletons=$this->app_builder_code_unit_test_skeletons($plan);
			foreach($code_unit_test_skeletons as $skeleton){
				$path=(string)($skeleton['path'] ?? '');
				if($path!==''){
					$files[]=$path;
				}
				$code_skeletons[]=$skeleton;
			}
			if($code_unit_test_skeletons!==[]){
				$verification[]='app_local_php_unit_tests';
			}
			foreach($this->app_builder_data_model($plan, $schema_context) as $model){
				$data_model[]=$model;
				foreach($this->app_builder_data_model_code_skeletons($model) as $skeleton){
					$code_skeletons[]=$skeleton;
				}
			}
		}
		$task=trim((string)($args['task'] ?? ''));
		$proportional_guidance=$this->mcp_task_proportional_guidance($task);
		$hard_block_sensitive_writes=$this->app_builder_task_requires_sensitive_write_confirmation($task, $proportional_guidance);
		$companion_surface_handoff=$this->app_builder_companion_surface_handoff($task, $scaffold_type, $args, $entities);
		return [
			'lane'=>'builder',
			'purpose'=>'Concise app-building path before governance details.',
			'default_for'=>'ordinary application feature work',
			'progressive_disclosure'=>'Use this lane first; open governance, enterprise audit, and publication validation only when task signals require them.',
			'task'=>$task,
			'scaffold_type'=>$scaffold_type,
			'app_path_context'=>$app_path_context,
			'entities'=>$entities,
			'entity_input_contract'=>$this->app_builder_entity_input_contract(['entities'=>$entities], $args),
			'entity_planning'=>$this->app_builder_entity_planning($entities, $planned_entities, $scaffold_type, $max_entities, $field_context_args),
			'sensitivity_policy'=>[
				'tier'=>$proportional_guidance['tier'] ?? 'lightweight',
				'hard_block_sensitive_writes'=>$hard_block_sensitive_writes,
				'signals'=>$proportional_guidance['signals'] ?? [],
				'reason'=>$hard_block_sensitive_writes
					? 'Task text explicitly asks for security, governance, privacy, compliance, redaction, access-policy, tenant-isolation, retention, or other elevated sensitive-data behavior.'
					: 'Schema-derived sensitive fields are app-owned implementation obligations for ordinary app scaffolds.',
			],
			'entrypoint_tool'=>'dataphyre_app_builder_plan_generate',
			'primary_tool'=>'dataphyre_scaffold_plan_generate',
			'scaffold_tool'=>'dataphyre_scaffold_plan_generate',
			'follow_up_tools'=>array_values(array_unique(array_filter([
				'dataphyre_task_pack_generate',
				$scaffold_type==='panel_resource' ? 'dataphyre_panel_scaffold_catalog' : null,
				$scaffold_type==='routing_controller' || $scaffold_type==='mvc_controller' ? 'dataphyre_route_source_static_summary' : null,
				$scaffold_type==='api_endpoint' ? 'dataphyre_api_docs_static_summary' : null,
				$scaffold_type==='api_endpoint' ? 'dataphyre_api_recipe_catalog' : null,
				$companion_surface_handoff!==[] ? 'dataphyre_api_docs_static_summary' : null,
				$companion_surface_handoff!==[] ? 'dataphyre_api_recipe_catalog' : null,
				$companion_surface_handoff!==[] ? 'dataphyre_route_source_static_summary' : null,
				$scaffold_type==='sql_table' ? 'dataphyre_sql_schema_read' : null,
			]))),
			'companion_surface_handoff'=>$companion_surface_handoff,
			'files_to_create'=>array_values(array_unique($files)),
			'data_model'=>array_values($data_model),
			'next_edits'=>array_values(array_slice(array_unique($next_edits), 0, 8)),
			'code_skeletons'=>array_values($code_skeletons),
			'code_skeleton_policy'=>'Starter skeletons are read-only previews; adapt them to local app conventions before writing files.',
			'verification'=>array_values(array_unique($verification ?: ['dataphyre_php_lint'])),
			'verification_plan'=>$this->app_builder_verification_plan($files, $data_model, array_values(array_unique($verification ?: ['dataphyre_php_lint']))),
			'acceptance_criteria'=>$this->app_builder_acceptance_criteria($files, $data_model),
			'extension_boundary_summary'=>$this->app_builder_extension_boundary_summary($app_path_context),
			'governance_lane'=>[
				'collapsed_by_default'=>true,
				'open_when'=>[
					'corporate-ready, public Dataphyre framework, or release-facing claims',
					'security, identity/access, session, credential, tenant isolation, privacy, compliance, billing, audit, data residency, retention, or legal-hold behavior',
					'Dataphyre runtime-internal or shared production hot-path changes',
				],
			],
			'scaffold_plans'=>$plans,
		];
	}

	/**
	 * Builds the schema shape shared by builder-plan summaries and data-model metadata.
	 *
	 * @param array<string,mixed> $plan Dry-run scaffold plan.
	 * @return array<string,mixed> Schema context row.
	 */
	private function app_builder_schema_context_from_plan(array $plan): array {
		$name=(string)($plan['name'] ?? 'Resource');
		$field_hints=is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : [];
		$table=str_replace('-', '_', $this->slug_name($name));
		return [
			'entity'=>$this->studly_name($name),
			'table'=>$table,
			'fields'=>$this->app_builder_schema_fields($field_hints),
			'relationships'=>$this->app_builder_relationships($field_hints),
		];
	}

	/**
	 * Builds lightweight app-owned PHP test skeletons beside generated unit-test manifests.
	 *
	 * @param array<string,mixed> $plan Dry-run scaffold plan.
	 * @return array<int,array<string,mixed>> Code-defined PHP test skeleton previews.
	 */
	private function app_builder_code_unit_test_skeletons(array $plan): array {
		$path=$this->app_builder_code_unit_test_path($plan);
		if($path===''){
			return [];
		}
		$type=(string)($plan['type'] ?? '');
		$name=(string)($plan['name'] ?? 'Resource');
		$suite=basename($path, '.test.php');
		$kind=match($type){
			'panel_resource'=>'panel_code_unit_test',
			'api_endpoint'=>'api_code_unit_test',
			'sql_table'=>'sql_code_unit_test',
			default=>'app_code_unit_test',
		};
		$purpose=match($kind){
			'panel_code_unit_test'=>'Lightweight app-owned PHP unit test skeleton for generated Panel resource behavior',
			'api_code_unit_test'=>'Lightweight app-owned PHP unit test skeleton for generated API endpoint behavior',
			'sql_code_unit_test'=>'Lightweight app-owned PHP unit test skeleton for generated SQL/data-model behavior',
			default=>'Lightweight app-owned PHP unit test skeleton for generated app behavior',
		};
		$focus=match($kind){
			'panel_code_unit_test'=>'resource loading, manifest registration, fields, filters, actions, and relation adapters',
			'api_code_unit_test'=>'endpoint handler results, request validation, auth policy, and copy-safe response payloads',
			'sql_code_unit_test'=>'TableSchema metadata, repository defaults, required fields, indexes, and relationship hints',
			default=>'the generated app contract and local integration points',
		};
		return [[
			'path'=>$path,
			'kind'=>$kind,
			'language'=>'php',
			'purpose'=>$purpose,
			'adaptation_notes'=>[
				'Keep this test file under the consuming application backend/dataphyre/unit_tests directory.',
				'Replace placeholder assertions with app-local checks for '.$focus.'.',
				'Run it with the consuming application local PHP unit-test command after adapting generated files.',
				'Use this as focused app verification; do not turn ordinary app scaffolds into maintainer or release-validation work.',
			],
			'content'=>$this->app_builder_code_unit_test_content($suite, $kind, $name, is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : []),
		]];
	}

	/**
	 * Derives an app-owned PHP test path from the scaffold's focused manifest path.
	 *
	 * @param array<string,mixed> $plan Dry-run scaffold plan.
	 * @return string Repo-relative app-owned test path, or empty when no app-owned unit-test manifest exists.
	 */
	private function app_builder_code_unit_test_path(array $plan): string {
		foreach(is_array($plan['proposed_files'] ?? null) ? $plan['proposed_files'] : [] as $file){
			$file=(string)$file;
			if(str_contains($file, '/backend/dataphyre/unit_tests/') && str_ends_with($file, '.json')){
				return substr($file, 0, -5).'.test.php';
			}
		}
		return '';
	}

	/**
	 * Builds a runner-neutral PHP test skeleton for app-owned unit test files.
	 *
	 * @param string $suite Test suite id derived from the planned file name.
	 * @param string $kind Skeleton kind.
	 * @param string $name Planned resource or endpoint name.
	 * @return string PHP skeleton content.
	 */
	private function app_builder_code_unit_test_content(string $suite, string $kind, string $name, array $field_hints=[]): string {
		$case=match($kind){
			'panel_code_unit_test'=>'generated_panel_resource_contract',
			'api_code_unit_test'=>'generated_api_endpoint_contract',
			'sql_code_unit_test'=>'generated_sql_data_contract',
			default=>'generated_app_contract',
		};
		$tag=match($kind){
			'panel_code_unit_test'=>'panel',
			'api_code_unit_test'=>'api',
			'sql_code_unit_test'=>'sql',
			default=>'app',
		};
		$focus=match($kind){
			'panel_code_unit_test'=>'resource, manifest, schema, filter, action, and relation assertions',
			'api_code_unit_test'=>'handler, validation, auth, and copy-safe response assertions',
			'sql_code_unit_test'=>'schema, repository, record, and SQL config assertions',
			default=>'app-owned generated behavior assertions',
		};
		$field_names=array_values(array_filter(array_map('strval', array_keys($field_hints)), static fn(string $field): bool=>$field!==''));
		$field_export=var_export($field_names, true);
		$assertion_block=match($kind){
			'panel_code_unit_test'=>
				"    \$expected_fields=".$field_export.";\n".
				"    \$surface=[]; // TODO: load the app-owned Panel resource/manifest surface.\n".
				"    foreach(\$expected_fields as \$field){\n".
				"        \$t->panelHasField(\$surface, \$field);\n".
				"    }\n".
				"    // \$permissions=\$t->fakePermissions()->allow('".$this->slug_name($name).".view', '*', ['id'=>1]);\n".
				"    // \$t->permits(\$permissions, ['id'=>1], '".$this->slug_name($name).".view');\n",
			'api_code_unit_test'=>
				"    \$response=[]; // TODO: call the app-owned endpoint handler with a fake request.\n".
				"    \$t->responseStatus(200, \$response);\n".
				"    \$t->responseJsonSubset(['data'=>[]], \$response);\n".
				"    // \$auth=\$t->fakeAuth(['id'=>1]);\n".
				"    // \$auth->assertAuthenticated(\$t);\n",
			'sql_code_unit_test'=>
				"    \$schema=[]; // TODO: load the app-owned TableSchema metadata.\n".
				"    foreach(".$field_export." as \$field){\n".
				"        \$t->schemaHasColumn(\$schema, \$field);\n".
				"    }\n".
				"    // \$db=\$t->fakeDatabase(['".$this->slug_name($name)."'=>array_fill_keys(".$field_export.", 'mixed')]);\n".
				"    // \$t->tableCount(\$db, '".$this->slug_name($name)."', 0);\n",
			default=>
				"    \$events=[]; // TODO: record app-owned integration events or trace rows.\n".
				"    \$t->expect(\$events)->toBeType('array');\n",
		};
		$todo='Bind this skeleton to app-local '.$focus.' for '.$this->title_label($name).($field_names!==[] ? '; inferred fields: '.implode(', ', $field_names) : '').'.';
		return "<?php\n".
			"declare(strict_types=1);\n\n".
			"use Dataphyre\\Test\\Context;\n".
			"use function Dataphyre\\Test\\test;\n\n".
			"test(".var_export($case, true).", static function(Context \$t): void {\n".
			"    \$t->todo(".var_export($todo, true).");\n".
			$assertion_block.
			"})->tag(".var_export($tag, true).", 'generated-scaffold', ".var_export($suite, true).");\n";
	}

	/**
	 * Suggests a second app-builder surface when a mixed app task asks for APIs.
	 *
	 * @param string $task Task text.
	 * @param string $scaffold_type Current primary scaffold type.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @param array<int,string> $entities Planned domain entities.
	 * @return array<string,mixed> Lightweight follow-up guidance.
	 */
	private function app_builder_companion_surface_handoff(string $task, string $scaffold_type, array $args, array $entities): array {
		if($scaffold_type==='api_endpoint' || !$this->app_builder_task_mentions_api_surface($task)){
			return [];
		}
		$primary_entity=$this->app_builder_companion_surface_entity($task, $entities, $args);
		if($primary_entity===''){
			$primary_entity='Resource';
		}
		$slug=str_replace('_', '-', $this->slug_name($primary_entity));
		$name=$this->studly_name($primary_entity).' API';
		$lower=strtolower($task);
		if(str_contains($lower, 'self-service') || str_contains($lower, 'self service') || str_contains($lower, 'customer-facing') || str_contains($lower, 'customer facing')){
			$name=$this->studly_name($primary_entity).' Self Service API';
		}
		$arguments=[
			'task'=>'Plan app-owned API endpoints for the API/self-service surface mentioned in the original task after the entity scaffold chunks are complete, using '.$this->studly_name($primary_entity).' as the first endpoint focus unless the consuming app overrides it. Original app task context: '.$this->app_builder_companion_task_context($task),
			'scaffold_type'=>'api_endpoint',
			'payload_profile'=>'compact',
			'name'=>$name,
			'path'=>'/api/'.$slug.'/{id}',
			'methods'=>['GET'],
			'group'=>$slug.'.v1',
			'auth'=>'app-owned auth/tenant policy',
		];
		$path_context_args=$args;
		if(!array_key_exists('application_path', $path_context_args) && array_key_exists('path', $args) && !is_array($args['path'])){
			$path_context_args['application_path']=(string)$args['path'];
		}
		$path_context=$this->app_builder_path_context($path_context_args);
		if(($path_context['path_input_valid'] ?? true)===true && ($path_context['application_path'] ?? '')!==''){
			$arguments['application_path']=(string)$path_context['application_path'];
		}
		if(($path_context['namespace_input_valid'] ?? true)===true && ($path_context['app_namespace'] ?? '')!==''){
			$arguments['app_namespace']=(string)$path_context['app_namespace'];
		}
		return [
			'status'=>'api_endpoint_follow_up_recommended',
			'owner'=>'consuming_application',
			'why'=>'The task asks for an API or self-service surface while the primary scaffold is app-owned CRUD/admin work.',
			'when'=>'After entity_planning.continuation_calls are complete and before treating the app scaffold as ready to write.',
			'next_tool'=>'dataphyre_app_builder_plan_generate',
			'arguments'=>$arguments,
			'preserve_from_primary_plan'=>[
				'app_path_context',
				'entity_planning.dependency_context',
				'data_sensitivity_summary',
				'policy_decision_register',
				'verification_handoff',
			],
			'not_required'=>[
				'governance detail unless the API policy itself is security/compliance-sensitive',
				'Dataphyre runtime-internal edits',
				'MCP/release validation for ordinary app-owned API files',
			],
			'endpoint_queue'=>$this->app_builder_companion_endpoint_queue($task, $entities, $primary_entity, $arguments),
		];
	}

	/**
	 * Builds a compact endpoint queue for mixed Panel/API app plans.
	 *
	 * @param string $task Original task text.
	 * @param array<int,string> $entities Planned domain entities.
	 * @param string $primary_entity Seed entity used by the direct follow-up call.
	 * @param array<string,mixed> $base_arguments Base companion API app-builder arguments.
	 * @return array<int,array<string,mixed>> Endpoint candidates to preserve after entity chunks.
	 */
	private function app_builder_companion_endpoint_queue(string $task, array $entities, string $primary_entity, array $base_arguments): array {
		$lower=strtolower($task);
		$entity_keys=[];
		foreach($entities as $entity){
			$entity=(string)$entity;
			$key=$this->app_builder_entity_key($entity);
			if($key!==''){
				$entity_keys[$key]=$entity;
			}
		}
		$candidates=[];
		$add=function(string $id, string $entity, string $method, string $path, array $checks) use (&$candidates, $base_arguments, $task): void {
			if($entity===''){
				return;
			}
			$label=$this->studly_name($entity);
			$group=str_replace('_', '-', $this->slug_name($label)).'.v1';
			$arguments=[
				'task'=>'Plan the '.$id.' app-owned API endpoint from companion_surface_handoff.endpoint_queue after entity chunks are complete. Original app task context: '.$this->app_builder_companion_task_context($task),
				'scaffold_type'=>'api_endpoint',
				'payload_profile'=>'compact',
				'name'=>$label.' '.str_replace(' ', ' ', ucwords(str_replace('_', ' ', $id))).' API',
				'path'=>$path,
				'methods'=>[$method],
				'group'=>$group,
				'auth'=>'app-owned auth/tenant policy',
			];
			foreach(['application_path', 'app_namespace'] as $key){
				if(isset($base_arguments[$key]) && trim((string)$base_arguments[$key])!==''){
					$arguments[$key]=(string)$base_arguments[$key];
				}
			}
			$candidates[$id]=[
				'id'=>$id,
				'entity'=>$label,
				'method'=>$method,
				'path'=>$path,
				'checks'=>$checks,
				'next_tool'=>'dataphyre_app_builder_plan_generate',
				'argument_source'=>'companion_surface_handoff.endpoint_queue['.$id.'].follow_up_arguments',
				'follow_up_arguments'=>$arguments,
			];
		};
		$primary=$this->studly_name($primary_entity!=='' ? $primary_entity : (string)($entities[0] ?? 'Resource'));
		$primary_slug=str_replace('_', '-', $this->slug_name($primary));
		$add('self_service_show', $primary, 'GET', '/api/'.$primary_slug.'/{id}', ['auth_scope', 'tenant_scope', 'not_found_or_not_visible']);
		if(str_contains($lower, 'self-service') || str_contains($lower, 'self service') || str_contains($lower, 'customer-facing') || str_contains($lower, 'customer facing')){
			$add('self_service_update', $primary, 'PATCH', '/api/'.$primary_slug.'/{id}', ['request_validation', 'permission_check', 'optimistic_or_policy_conflict']);
		}
		foreach(['webhookdeliveryattempt', 'webhookdelivery', 'webhookendpoint', 'webhook'] as $key){
			if(isset($entity_keys[$key]) || str_contains($lower, 'webhook')){
				$entity=$entity_keys[$key] ?? 'WebhookDeliveryAttempt';
				$add('webhook_retry_or_ingest', $entity, 'POST', '/api/webhooks/{id}/retry', ['signature_or_actor_check', 'idempotency_key', 'retry_limit_or_dead_letter']);
				break;
			}
		}
		foreach(['usagemeter', 'usageevent', 'billingaccount', 'subscription', 'invoice'] as $key){
			if(isset($entity_keys[$key]) || str_contains($lower, 'billing') || str_contains($lower, 'usage')){
				$entity=$entity_keys[$key] ?? 'UsageMeter';
				$add('usage_or_billing_meter', $entity, 'POST', '/api/usage/{meter_key}', ['tenant_scope', 'idempotency_key', 'quota_or_plan_check']);
				break;
			}
		}
		foreach(['auditevent', 'auditlog'] as $key){
			if(isset($entity_keys[$key]) || str_contains($lower, 'audit')){
				$entity=$entity_keys[$key] ?? 'AuditEvent';
				$add('audit_event_query', $entity, 'GET', '/api/audit-events', ['permission_check', 'redaction', 'date_or_cursor_filter']);
				break;
			}
		}
		return array_values($candidates);
	}

	/**
	 * Picks the most useful entity for a mixed Panel/API follow-up.
	 *
	 * @param string $task Task text.
	 * @param array<int,string> $entities Planned domain entities.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return string Entity label for the companion API endpoint seed.
	 */
	private function app_builder_companion_surface_entity(string $task, array $entities, array $args): string {
		$lower=strtolower($task);
		$entity_keys=[];
		foreach($entities as $entity){
			$entity=(string)$entity;
			$entity_keys[$this->app_builder_entity_key($entity)]=$entity;
		}
		$prefer=[];
		if(str_contains($lower, 'customer-facing') || str_contains($lower, 'customer facing') || str_contains($lower, 'customer self-service') || str_contains($lower, 'customer self service')){
			$prefer=['customer', 'account', 'contact', 'workspace', 'organization'];
		}elseif(str_contains($lower, 'vendor self-service') || str_contains($lower, 'vendor self service')){
			$prefer=['vendor', 'vendorcontact', 'document', 'riskassessment'];
		}elseif(str_contains($lower, 'webhook')){
			$prefer=['webhookendpoint', 'webhook', 'eventsubscription', 'integration'];
		}elseif(str_contains($lower, 'self-service') || str_contains($lower, 'self service')){
			$prefer=['customer', 'vendor', 'account', 'user', 'workspace', 'organization'];
		}
		foreach($prefer as $key){
			if(isset($entity_keys[$key])){
				return $entity_keys[$key];
			}
		}
		return (string)($entities[0] ?? trim((string)($args['name'] ?? 'Resource')));
	}

	/**
	 * Keeps companion API follow-up calls aware of the original app intent.
	 *
	 * @param string $task Original task text.
	 * @return string Bounded single-line context for the follow-up task argument.
	 */
	private function app_builder_companion_task_context(string $task): string {
		$context=trim(preg_replace('/\s+/', ' ', $task) ?? $task);
		if($context===''){
			return 'API/self-service surface requested by the primary app scaffold.';
		}
		return strlen($context)>420 ? substr($context, 0, 417).'...' : $context;
	}

	/**
	 * Detects API/self-service intent without stealing mixed admin CRUD tasks.
	 */
	private function app_builder_task_mentions_api_surface(string $task): bool {
		$lower=strtolower($task);
		foreach(['openapi', 'api endpoint', 'api endpoints', 'api route', 'api handler', 'api docs', 'json endpoint', 'rest endpoint', 'rest api', 'webhook', 'webhooks', 'self-service', 'self service'] as $needle){
			if(str_contains($lower, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines whether sensitive app fields should block writes up front.
	 *
	 * Domain nouns such as billing, tenant, token, or API key still become
	 * app-owned implementation obligations; hard blockers are reserved for
	 * explicit policy/security/governance behavior.
	 *
	 * @param string $task Task text.
	 * @param array<string,mixed> $proportional_guidance Existing escalation signals.
	 * @return bool True when the app-builder should require explicit confirmation before writes.
	 */
	private function app_builder_task_requires_sensitive_write_confirmation(string $task, array $proportional_guidance): bool {
		if($this->mcp_task_implies_release_claim($task)){
			return true;
		}
		$lower=strtolower($task);
		$explicit_phrases=[
			'security',
			'secure',
			'authorization',
			'authentication',
			'access policy',
			'access control',
			'permission policy',
			'permissions policy',
			'privacy',
			'redaction',
			'redact',
			'compliance',
			'governance',
			'tenant isolation',
			'workspace isolation',
			'data boundary',
			'data-boundary',
			'data residency',
			'data retention',
			'retention policy',
			'records retention',
			'legal hold',
			'credential handling',
			'credential management',
			'secret handling',
			'secret management',
			'key management',
			'kms',
			'encryption',
			'pii',
			'gdpr',
			'hipaa',
			'soc2',
			'sox',
			'sensitive data handling',
			'sensitive-data handling',
			'billing-sensitive data handling',
			'tenant-sensitive data handling',
		];
		foreach($explicit_phrases as $phrase){
			if(str_contains($lower, $phrase)){
				return true;
			}
		}
		if(preg_match('/\b(access|permission|privacy|redaction|retention|credential|secret|key|token|tenant|billing)\s+(rules|policy|policies|controls|handling|isolation|enforcement|redaction|masking)\b/', $lower)===1){
			return true;
		}
		return in_array('release_or_enterprise_claim', $proportional_guidance['signals'] ?? [], true);
	}

	/**
	 * Returns a compact placement rule for ordinary app-builder payloads.
	 *
	 * @param array<string,mixed> $app_path_context Path context from app_builder_path_context().
	 * @return array<string,mixed> Lightweight extension-boundary summary.
	 */
	private function app_builder_extension_boundary_summary(array $app_path_context=[]): array {
		$dataphyre_root=(string)($app_path_context['dataphyre_root'] ?? 'applications/<app>/backend/dataphyre');
		$framework_path=(string)($app_path_context['framework_path'] ?? '<app framework>');
		$framework_namespace=(string)($app_path_context['framework_namespace'] ?? 'App\\Framework');
		$placeholder_mode=($app_path_context['placeholder_mode'] ?? true)===true;
		return [
			'owner'=>'consuming_application',
			'default_rule'=>'Keep application behavior in app-owned code, config, callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters before proposing Dataphyre runtime internals.',
			'preferred_layers'=>['application_code', 'configuration', 'dialbacks_callbacks', 'plugins', 'local_mcp_metadata', 'application_adapter'],
			'app_owned_extension_targets'=>[
				'application_code'=>[
					'paths'=>['builder_response.files'],
					'source'=>'builder_response.files',
				],
				'configuration'=>[
					'paths'=>[$dataphyre_root.'/config'],
					'source'=>'builder_response.app_path_context.dataphyre_root',
				],
				'dialbacks_callbacks'=>[
					'paths'=>[$dataphyre_root.'/dialbacks', $dataphyre_root.'/callbacks'],
					'source'=>'builder_response.app_path_context.dataphyre_root',
				],
				'plugins'=>[
					'paths'=>[$dataphyre_root.'/plugins/pre_init', $dataphyre_root.'/plugins/post_init'],
					'source'=>'builder_response.app_path_context.dataphyre_root',
				],
				'local_mcp_metadata'=>[
					'paths'=>[$dataphyre_root.'/plugins/mcp'],
					'source'=>'builder_response.app_path_context.dataphyre_root',
				],
				'application_adapter'=>[
					'path'=>$framework_path,
					'namespace'=>$framework_namespace,
					'source'=>'builder_response.app_path_context.framework_path',
				],
			],
			'app_owned_extension_target_policy'=>$placeholder_mode ? 'Targets use portable placeholders until application_path is supplied.' : 'Targets use caller-supplied concrete app-owned paths and namespace hints.',
			'app_owned_placement_checklist'=>[
				'purpose'=>'Fast ordinary-app placement checklist before any runtime-internal idea.',
				'read_first'=>['builder_response.files', 'builder_response.app_path_context', 'builder_response.implementation_recipe.items'],
				'choose_layer'=>[
					'application_code'=>'Use planned app-owned resources, repositories, records, handlers, manifests, or tests when behavior belongs to one app surface.',
					'configuration'=>'Use app-owned config for toggles, policy choices, provider selection, limits, and registration.',
					'dialbacks_callbacks'=>'Use callbacks or dialbacks for lifecycle hooks, policy checks, relation options, audit stamps, and integration handoffs.',
					'plugins'=>'Use app-owned plugins for install-local behavior that should stay outside shared Dataphyre runtime code.',
					'application_adapter'=>'Use app-owned adapters for provider integrations, cross-module policies, external service boundaries, or reusable app-local abstractions.',
				],
				'before_runtime_internal'=>'If none fit, collect reusable cross-application evidence and open the governance/full contract before proposing Dataphyre internals.',
			],
			'placement_decision'=>[
				'status'=>'ordinary_app_layer_required',
				'recommended_first_layers'=>['application_code', 'configuration', 'dialbacks_callbacks', 'plugins', 'application_adapter'],
				'runtime_internal_allowed'=>false,
				'agent_action'=>'Choose one recommended_first_layers entry before writing app behavior; escalate only with reusable Dataphyre framework evidence.',
				'escalation_evidence'=>['reusable cross-application behavior', 'public module contract or diagnostic surface', 'focused tests and docs', 'release-facing validation', 'hot-path benchmark evidence only for Dataphyre shared production hot-path changes'],
			],
			'escalate_only_for'=>['reusable Dataphyre framework behavior', 'public/release-facing framework claims', 'security/governance-sensitive shared behavior', 'Dataphyre shared production hot-path work with proof'],
			'full_contract'=>'builder_plan.extension_decision_ladder or dataphyre_task_pack_generate payload_profile=governance',
		];
	}

	/**
	 * Resolves optional app-owned path and namespace hints for builder output.
	 *
	 * @param array<string,mixed> $args App-builder arguments.
	 * @return array<string,mixed> Path and namespace context.
	 */
	private function app_builder_path_context(array $args): array {
		$raw_application_path=str_replace('\\', '/', trim((string)($args['application_path'] ?? '')));
		$path_issue='';
		$path_input_valid=true;
		if($raw_application_path!==''){
			$raw_parts=array_values(array_filter(explode('/', $raw_application_path), static fn(string $part): bool => $part!==''));
			if(preg_match('/^[A-Za-z]:\//', $raw_application_path)===1 || str_starts_with($raw_application_path, '/') || str_contains($raw_application_path, '://')){
				$path_issue='absolute_or_url_application_path';
				$path_input_valid=false;
			}elseif(in_array('..', $raw_parts, true)){
				$path_issue='traversal_application_path';
				$path_input_valid=false;
			}
		}
		$application_path=$path_input_valid ? trim($raw_application_path, '/') : '';
		$raw_app_namespace=trim((string)($args['app_namespace'] ?? ''), "\\ \t\n\r\0\x0B");
		$app_namespace=$raw_app_namespace==='' ? 'App' : str_replace('/', '\\', $raw_app_namespace);
		while(str_contains($app_namespace, '\\\\')){
			$app_namespace=str_replace('\\\\', '\\', $app_namespace);
		}
		$app_namespace=trim($app_namespace, '\\');
		if($app_namespace===''){
			$app_namespace='App';
		}
		$namespace_input_valid=true;
		$namespace_issue=$raw_app_namespace==='' ? 'default_namespace' : 'caller_supplied_php_namespace';
		if($raw_app_namespace!==''){
			$segments=explode('\\', $app_namespace);
			foreach($segments as $segment){
				if($segment==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)!==1){
					$namespace_input_valid=false;
					$namespace_issue='invalid_php_namespace';
					break;
				}
			}
			if(str_contains($raw_app_namespace, '://') || str_contains($raw_app_namespace, '..')){
				$namespace_input_valid=false;
				$namespace_issue='invalid_php_namespace';
			}
		}
		if(!$namespace_input_valid){
			$app_namespace='App';
		}
		$application_id=trim((string)($args['application'] ?? ''));
		if($application_id==='' && $application_path!==''){
			$parts=array_values(array_filter(explode('/', $application_path), static fn(string $part): bool => $part!==''));
			$application_id=(string)end($parts);
			if($application_id==='dataphyre' && count($parts)>=3){
				$application_id=(string)$parts[count($parts)-3];
			}
		}
		if($application_id===''){
			$application_id='<app>';
		}
		$dataphyre_root=$application_path!=='' ? $application_path : 'applications/<app>/backend/dataphyre';
		if($application_path!=='' && !str_ends_with($dataphyre_root, '/backend/dataphyre')){
			$dataphyre_root.='/backend/dataphyre';
		}
		$framework_path=$application_path!=='' ? $dataphyre_root.'/Framework' : '<app framework>';
		$placeholder_mode=$application_path==='' && $raw_application_path==='';
		$invalid_path_supplied=$raw_application_path!=='' && !$path_input_valid;
		$path_exists=false;
		$detected_layout=$invalid_path_supplied ? 'invalid_application_path' : ($placeholder_mode ? 'placeholder' : 'caller_supplied_unverified');
		$candidate_roots=($placeholder_mode || $invalid_path_supplied) ? ['applications/<app>', 'applications/<app>/backend/dataphyre'] : array_values(array_unique([
			$application_path,
			$dataphyre_root,
		]));
		if(!$placeholder_mode && !$invalid_path_supplied){
			$repo_candidate=$this->root.'/'.$dataphyre_root;
			$path_exists=is_dir($repo_candidate);
			if($path_exists){
				$detected_layout=str_ends_with($dataphyre_root, '/backend/dataphyre') ? 'dataphyre_backend_root' : 'application_root';
			}
		}
		return [
			'application_id'=>$application_id,
			'application_path'=>$application_path,
			'dataphyre_root'=>$dataphyre_root,
			'framework_path'=>$framework_path,
			'app_namespace'=>$app_namespace,
			'panel_resource_namespace'=>$app_namespace.'\\Panel\\Resources',
			'framework_namespace'=>$app_namespace.'\\Framework',
			'namespace_input_valid'=>$namespace_input_valid,
			'namespace_input_status'=>$namespace_issue,
			'namespace_policy'=>$namespace_input_valid ? 'Use the normalized PHP namespace hint for app-owned generated skeletons.' : 'Caller supplied an invalid app_namespace; use a PHP namespace such as App or Acme\\Portal before writing generated skeletons.',
			'placeholder_mode'=>$placeholder_mode,
			'path_input_valid'=>$path_input_valid,
			'path_input_status'=>$invalid_path_supplied ? $path_issue : ($placeholder_mode ? 'placeholder_until_supplied' : 'caller_supplied_repo_relative_path'),
			'path_exists'=>$path_exists,
			'detected_layout'=>$detected_layout,
			'candidate_roots'=>$candidate_roots,
			'path_confidence'=>$invalid_path_supplied ? 'invalid_application_path_rejected' : ($placeholder_mode ? 'placeholder_until_application_path_is_supplied' : ($path_exists ? 'caller_supplied_existing_path' : 'caller_supplied_path_not_found_yet')),
			'policy'=>$invalid_path_supplied ? 'Caller supplied an invalid application_path; use repo-relative applications/<app> or applications/<app>/backend/dataphyre from dataphyre_application_catalog before writing.' : ($placeholder_mode ? 'Portable placeholder paths are used until the caller supplies application_path.' : ($path_exists ? 'Concrete app-owned paths are caller-provided hints; verify they match local conventions before writing.' : 'Caller-provided concrete app-owned path was not found locally; correct application_path before writing.')),
			'discovery_hint'=>$invalid_path_supplied ? [
				'status'=>'invalid_application_path',
				'next_tool'=>'dataphyre_application_catalog',
				'next_arguments'=>['scope'=>'applications'],
				'then_supply'=>'repo-relative application_path',
				'accepted_forms'=>['applications/<app>', 'applications/<app>/backend/dataphyre'],
				'rejected_forms'=>['absolute paths', 'URLs', 'paths containing .. traversal'],
				'not_required'=>['framework/release escalation for ordinary app path correction'],
			] : (!$namespace_input_valid ? [
				'status'=>'invalid_app_namespace',
				'next_tool'=>'dataphyre_application_catalog',
				'next_arguments'=>['scope'=>'applications'],
				'then_supply'=>'valid app_namespace',
				'accepted_forms'=>['App', 'AcmePortal', 'Acme\\Portal'],
				'rejected_forms'=>['segments starting with numbers', 'punctuation/control characters', 'URLs', 'paths containing .. traversal'],
				'not_required'=>['framework/release escalation for ordinary app namespace correction'],
			] : ($placeholder_mode ? [
				'status'=>'needs_application_path',
				'next_tool'=>'dataphyre_application_catalog',
				'next_arguments'=>['scope'=>'applications'],
				'then_supply'=>'application_path',
				'accepted_forms'=>['applications/<app>', 'applications/<app>/backend/dataphyre'],
				'also_supply_when_known'=>'app_namespace',
				'not_required'=>['framework/release escalation for ordinary app path discovery'],
			] : ($path_exists ? [
				'status'=>'concrete_app_path_available',
				'next_tool'=>null,
				'next_arguments'=>[],
				'then_supply'=>null,
			] : [
				'status'=>'concrete_app_path_not_found',
				'next_tool'=>'dataphyre_application_catalog',
				'next_arguments'=>['scope'=>'applications'],
				'then_supply'=>'corrected application_path',
				'accepted_forms'=>['applications/<app>', 'applications/<app>/backend/dataphyre'],
				'not_required'=>['framework/release escalation for ordinary app path correction'],
			]))),
		];
	}

	/**
	 * Returns the bounded entity count for one compact app-builder response.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return int Entity count between 1 and 12.
	 */
	private function app_builder_max_entities(array $args): int {
		$max=(int)($args['max_entities'] ?? 4);
		return max(1, min(12, $max));
	}

	/**
	 * Describes which entities were planned now and how to continue large apps.
	 *
	 * @param array<int,string> $entities All inferred or requested entities.
	 * @param array<int,string> $planned_entities Entities included in this response.
	 * @param string $scaffold_type Planned scaffold type.
	 * @param int $max_entities Maximum entities per compact response.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,mixed> Machine-readable chunking contract.
	 */
	private function app_builder_entity_planning(array $entities, array $planned_entities, string $scaffold_type, int $max_entities, array $args=[]): array {
		$entities=array_values(array_map('strval', $entities));
		$planned_entities=array_values(array_map('strval', $planned_entities));
		$deferred_entities=array_values(array_diff($entities, $planned_entities));
		$entity_chunks=[];
		foreach(array_chunk($entities, $max_entities) as $index=>$chunk){
			foreach($chunk as $entity){
				$entity_chunks[$this->app_builder_entity_key((string)$entity)]=$index+1;
			}
		}
		$chunks=[];
		foreach(array_chunk($entities, $max_entities) as $index=>$chunk){
			$dependency_context=$this->app_builder_chunk_dependency_context(array_values($chunk), $index+1, $entity_chunks, $args);
			$arguments=[];
			foreach(['task', 'payload_profile'] as $key){
				if(array_key_exists($key, $args) && !is_array($args[$key]) && trim((string)$args[$key])!==''){
					$arguments[$key]=(string)$args[$key];
				}
			}
			$path_context_args=$args;
			if(!array_key_exists('application_path', $path_context_args) && array_key_exists('path', $args) && !is_array($args['path'])){
				$path_context_args['application_path']=(string)$args['path'];
			}
			$path_context=$this->app_builder_path_context($path_context_args);
			if(($path_context['path_input_valid'] ?? true)===true && ($path_context['application_path'] ?? '')!==''){
				$arguments['application_path']=(string)$path_context['application_path'];
			}
			if(($path_context['namespace_input_valid'] ?? true)===true && ($path_context['app_namespace'] ?? '')!==''){
				$arguments['app_namespace']=(string)$path_context['app_namespace'];
			}
			if(!isset($arguments['task'])){
				$arguments['task']='continue app scaffold chunk';
			}
			if(!isset($arguments['payload_profile'])){
				$arguments['payload_profile']='compact';
			}
			$arguments['entities']=array_values($chunk);
			$arguments['scaffold_type']=$scaffold_type;
			$arguments['max_entities']=$max_entities;
			$arguments['dependency_context']=$dependency_context;
			$chunk_fields=$this->app_builder_fields_for_entities(array_values($chunk), $args);
			if($chunk_fields!==[]){
				$arguments['fields']=$chunk_fields;
				$arguments['field_scope']='chunk_entities';
			}else{
				$arguments['reuse_fields_from_original']=true;
			}
			$chunks[]=[
				'chunk'=>$index+1,
				'active'=>$index===0,
				'entities'=>array_values($chunk),
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>$arguments,
			];
		}
		return [
			'policy'=>'compact_chunked_multi_entity_planning',
			'max_entities_per_response'=>$max_entities,
			'total_entities'=>count($entities),
			'planned_entities'=>$planned_entities,
			'deferred_entities'=>$deferred_entities,
			'truncated'=>$deferred_entities!==[],
			'warning'=>$deferred_entities===[] ? '' : 'This compact response planned only the first chunk. Run the continuation calls for deferred entities before treating the app scaffold as complete.',
			'incoming_dependency_context'=>$this->app_builder_incoming_dependency_context($args),
			'dependency_summary'=>$this->app_builder_entity_dependency_summary($entities, $max_entities, $entity_chunks, $args),
			'continuation_calls'=>array_values(array_filter($chunks, static fn(array $chunk): bool => ($chunk['active'] ?? false)!==true)),
		];
	}

	/**
	 * Returns caller-supplied continuation dependency context, when present.
	 *
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,mixed> Incoming dependency context.
	 */
	private function app_builder_incoming_dependency_context(array $args): array {
		return is_array($args['dependency_context'] ?? null) ? $args['dependency_context'] : [];
	}

	/**
	 * Indexes caller-supplied continuation dependencies by entity and field.
	 *
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,array<string,mixed>> Dependency lookup.
	 */
	private function app_builder_incoming_dependency_lookup(array $args): array {
		$context=$this->app_builder_incoming_dependency_context($args);
		$lookup=[];
		foreach(is_array($context['dependencies'] ?? null) ? $context['dependencies'] : [] as $dependency){
			if(!is_array($dependency)){
				continue;
			}
			$entity=(string)($dependency['entity'] ?? '');
			$field=(string)($dependency['field'] ?? '');
			if($entity==='' || $field===''){
				continue;
			}
			$lookup[$this->app_builder_entity_key($entity).'|'.$field]=$dependency;
		}
		return $lookup;
	}

	/**
	 * Summarizes cross-entity dependencies for chunked app scaffolds.
	 *
	 * @param array<int,string> $entities All scaffold entities.
	 * @param int $max_entities Maximum entities per chunk.
	 * @param array<string,int> $entity_chunks Entity key to chunk index.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,mixed> Compact dependency metadata.
	 */
	private function app_builder_entity_dependency_summary(array $entities, int $max_entities, array $entity_chunks, array $args): array {
		$chunks=[];
		foreach(array_chunk($entities, $max_entities) as $index=>$chunk){
			$chunks[]=$this->app_builder_chunk_dependency_context(array_values($chunk), $index+1, $entity_chunks, $args);
		}
		return [
			'policy'=>'preserve_cross_chunk_relationship_context',
			'purpose'=>'Use dependency_context to stitch continuation chunks into one app data model without re-planning already completed entities.',
			'chunks'=>$chunks,
		];
	}

	/**
	 * Builds dependency context for one app-builder chunk.
	 *
	 * @param array<int,string> $chunk_entities Entities in this chunk.
	 * @param int $chunk_number One-based chunk number.
	 * @param array<string,int> $entity_chunks Entity key to chunk index.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,mixed> Chunk dependency context.
	 */
	private function app_builder_chunk_dependency_context(array $chunk_entities, int $chunk_number, array $entity_chunks, array $args): array {
		$dependencies=[];
		$incoming_dependencies=$this->app_builder_incoming_dependency_lookup($args);
		foreach($chunk_entities as $entity){
			$fields=$this->app_builder_fields_for_entity((string)$entity, $args);
			$relationships=$this->app_builder_relationships($this->field_hints($fields));
			foreach($relationships as $relationship){
				$target_entity=(string)($relationship['target_entity'] ?? '');
				$target_key=$this->app_builder_entity_key($target_entity);
				$target_chunk=$entity_chunks[$target_key] ?? null;
				$scope='external_reference';
				if($target_chunk!==null){
					$scope=$target_chunk===$chunk_number ? 'same_chunk' : ($target_chunk<$chunk_number ? 'previous_chunk' : 'later_chunk');
				}else{
					$incoming_dependency=$incoming_dependencies[$this->app_builder_entity_key((string)$entity).'|'.(string)($relationship['field'] ?? '')] ?? null;
					if(is_array($incoming_dependency) && (string)($incoming_dependency['target_entity'] ?? '')===$target_entity){
						$scope=(string)($incoming_dependency['scope'] ?? $scope);
						$incoming_target_chunk=$incoming_dependency['target_chunk'] ?? null;
						$target_chunk=is_int($incoming_target_chunk) ? $incoming_target_chunk : (is_numeric($incoming_target_chunk) ? (int)$incoming_target_chunk : null);
					}
				}
				$dependencies[]=[
					'entity'=>(string)$entity,
					'field'=>(string)($relationship['field'] ?? ''),
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>$scope,
					'target_chunk'=>$target_chunk,
				];
			}
		}
		return [
			'chunk'=>$chunk_number,
			'entities'=>array_values($chunk_entities),
			'dependencies'=>$dependencies,
			'policy_context'=>$this->app_builder_chunk_policy_context($args),
		];
	}

	/**
	 * Carries compact app-owned SaaS policy signals through continuation calls.
	 *
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,mixed> Copy-safe policy context for chunk replay.
	 */
	private function app_builder_chunk_policy_context(array $args): array {
		$incoming=is_array($args['dependency_context']['policy_context'] ?? null) ? $args['dependency_context']['policy_context'] : [];
		$fields_by_entity=is_array($args['fields'] ?? null) ? $args['fields'] : [];
		$scope_fields=[];
		$ownership_fields=[];
		$access_fields=[];
		$billing_or_plan_fields=[];
		$sensitive_fields=[];
		foreach($fields_by_entity as $entity=>$fields){
			if(!is_array($fields)){
				continue;
			}
			foreach($fields as $name=>$definition){
				$field_name=(string)$name;
				if($field_name===''){
					continue;
				}
				$qualified=(string)$entity.'.'.$field_name;
				if(in_array($field_name, ['tenant_id', 'workspace_id', 'organization_id', 'account_id'], true)){
					$scope_fields[]=$qualified;
				}
				if(in_array($field_name, ['owner_id', 'assignee_id', 'requester_id', 'created_by', 'updated_by'], true)){
					$ownership_fields[]=$qualified;
				}
				if(str_contains($field_name, 'role') || str_contains($field_name, 'permission') || str_contains($field_name, 'api_key') || str_contains($field_name, 'visibility') || str_contains($field_name, 'classification')){
					$access_fields[]=$qualified;
				}
				if(str_contains($field_name, 'plan') || str_contains($field_name, 'subscription') || str_contains($field_name, 'entitlement') || str_contains($field_name, 'quota') || str_contains($field_name, 'limit') || str_contains($field_name, 'mrr') || str_contains($field_name, 'amount') || str_contains($field_name, 'price') || str_contains($field_name, 'billing')){
					$billing_or_plan_fields[]=$qualified;
				}
				if(str_contains($field_name, 'email') || str_contains($field_name, 'phone') || str_contains($field_name, 'secret') || str_contains($field_name, 'token') || str_contains($field_name, 'key') || str_contains($field_name, 'password') || str_contains($field_name, 'diagnostic')){
					$sensitive_fields[]=$qualified;
				}
				if(is_array($definition)){
					$type=(string)($definition['type'] ?? '');
					if($type==='json' && (str_contains($field_name, 'evidence') || str_contains($field_name, 'diagnostic'))){
						$sensitive_fields[]=$qualified;
					}
				}
			}
		}
		$merge_unique=static function(array $incoming_values, array $derived_values): array {
			return array_values(array_unique(array_merge(array_map('strval', $incoming_values), array_map('strval', $derived_values))));
		};
		$scope_fields=$merge_unique(is_array($incoming['tenant_scope_fields'] ?? null) ? $incoming['tenant_scope_fields'] : [], $scope_fields);
		$ownership_fields=$merge_unique(is_array($incoming['ownership_fields'] ?? null) ? $incoming['ownership_fields'] : [], $ownership_fields);
		$access_fields=$merge_unique(is_array($incoming['access_fields'] ?? null) ? $incoming['access_fields'] : [], $access_fields);
		$billing_or_plan_fields=$merge_unique(is_array($incoming['billing_or_plan_fields'] ?? null) ? $incoming['billing_or_plan_fields'] : [], $billing_or_plan_fields);
		$sensitive_fields=$merge_unique(is_array($incoming['sensitive_fields'] ?? null) ? $incoming['sensitive_fields'] : [], $sensitive_fields);
		return [
			'purpose'=>'Carry app-owned tenant, actor, entitlement, and sensitive-output signals across continuation chunks without opening governance context.',
			'tenant_scope_fields'=>$scope_fields,
			'ownership_fields'=>$ownership_fields,
			'access_fields'=>$access_fields,
			'billing_or_plan_fields'=>$billing_or_plan_fields,
			'sensitive_fields'=>array_slice($sensitive_fields, 0, 12),
			'enforcement_reminder'=>$scope_fields!==[] || $ownership_fields!==[] || $access_fields!==[] || $billing_or_plan_fields!==[] ? 'Apply scope, actor/permission, and entitlement checks consistently across all chunks before render, mutation, export, notification, or relationship lookup.' : 'No cross-chunk SaaS policy signals inferred.',
		];
	}

	/**
	 * Returns entity-scoped field hints suitable for a continuation call.
	 *
	 * @param array<int,string> $entities Entity names in the continuation chunk.
	 * @param array<string,mixed> $args Original app-builder arguments.
	 * @return array<string,array<string|int,mixed>> Nested fields map.
	 */
	private function app_builder_fields_for_entities(array $entities, array $args): array {
		$raw_fields=is_array($args['fields'] ?? null) ? $args['fields'] : [];
		$fields=[];
		foreach($entities as $entity){
			$entity=(string)$entity;
			if($raw_fields===[]){
				$default_args=$args;
				unset($default_args['fields']);
				$entity_fields=$this->app_builder_fields_for_entity($entity, $default_args);
			}else{
				$entity_fields=$this->app_builder_entity_fields_input($entity, $raw_fields);
				if($entity_fields===null){
					$default_args=$args;
					unset($default_args['fields']);
					$entity_fields=$this->app_builder_fields_for_entity($entity, $default_args);
				}
			}
			if(is_array($entity_fields) && $entity_fields!==[]){
				$fields[$entity]=$entity_fields;
			}
		}
		return $fields;
	}

	/**
	 * Generates the first-class app-builder plan response.
	 *
	 * @param array<string,mixed> $args Builder task options.
	 * @return array<string,mixed> Compact builder-first payload.
	 */
	private function generate_app_builder_plan(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		if($task===''){
			throw new InvalidArgumentException('task is required.');
		}
		$lane=$this->app_builder_lane($task, $args);
		$builder_plan=$this->app_builder_builder_plan($lane);
		$payload_profile=strtolower(trim((string)($args['payload_profile'] ?? '')));
		if(!in_array($payload_profile, ['full', 'compact'], true)){
			$payload_profile='compact';
		}
		$first_read=$this->mcp_app_builder_first_read($builder_plan);
		$builder_response=$payload_profile==='compact'
			? array_replace($this->mcp_app_builder_compact_builder_view(
				$builder_plan,
				$lane,
				$first_read,
				[],
				is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [],
				$this->app_builder_governance_notes($builder_plan, $lane, true)
			), [
				'first_read'=>$first_read,
				'first_read_ref'=>'builder_response.first_read',
				'payload_budget'=>$this->mcp_app_builder_payload_budget('app_builder_plan_compact'),
				'next_action'=>$first_read['next_action'] ?? [],
				'agent_workload'=>$this->mcp_app_builder_workload_budget(),
				'files'=>$builder_plan['files'] ?? [],
				'schema'=>$builder_plan['schema'] ?? [],
				'naming_contract'=>$builder_plan['naming_contract'] ?? [],
				'entity_input_contract'=>$builder_plan['entity_input_contract'] ?? [],
				'entity_planning'=>$builder_plan['entity_planning'] ?? [],
				'scaffold_completion_summary'=>$builder_plan['scaffold_completion_summary'] ?? [],
				'companion_surface_handoff'=>$builder_plan['companion_surface_handoff'] ?? [],
				'data_sensitivity_summary'=>$this->mcp_app_builder_compact_policy_summary(is_array($builder_plan['data_sensitivity_summary'] ?? null) ? $builder_plan['data_sensitivity_summary'] : []),
				'policy_decision_register'=>$this->mcp_app_builder_compact_policy_summary(is_array($builder_plan['policy_decision_register'] ?? null) ? $builder_plan['policy_decision_register'] : []),
				'field_metadata_summary'=>$builder_plan['field_metadata_summary'] ?? [],
				'relationship_contract_summary'=>$builder_plan['relationship_contract_summary'] ?? [],
				'data_integrity_summary'=>$builder_plan['data_integrity_summary'] ?? [],
				'lifecycle_policy_summary'=>$builder_plan['lifecycle_policy_summary'] ?? [],
				'audit_retention_summary'=>$this->app_builder_compact_optional_summary($builder_plan['audit_retention_summary'] ?? [], 'has_audit_retention_fields', 'audit_retention_summary'),
				'access_control_summary'=>$this->app_builder_compact_optional_summary($builder_plan['access_control_summary'] ?? [], 'has_access_control_fields', 'access_control_summary'),
				'operational_reliability_summary'=>$this->app_builder_compact_optional_summary($builder_plan['operational_reliability_summary'] ?? [], 'has_operational_reliability_signals', 'operational_reliability_summary'),
				'support_observability_summary'=>$this->app_builder_compact_optional_summary($builder_plan['support_observability_summary'] ?? [], 'has_support_observability_signals', 'support_observability_summary'),
				'change_management_summary'=>$this->app_builder_compact_optional_summary($builder_plan['change_management_summary'] ?? [], 'has_change_management_signals', 'change_management_summary'),
				'integration_boundary_summary'=>$this->app_builder_compact_optional_summary($builder_plan['integration_boundary_summary'] ?? [], 'has_integration_boundary_signals', 'integration_boundary_summary'),
				'business_policy_summary'=>$this->app_builder_compact_optional_summary($builder_plan['business_policy_summary'] ?? [], 'has_business_policy_signals', 'business_policy_summary'),
				'process_policy_summary'=>$this->app_builder_compact_optional_summary($builder_plan['process_policy_summary'] ?? [], 'has_process_policy_signals', 'process_policy_summary'),
				'reporting_analytics_summary'=>$this->app_builder_compact_optional_summary($builder_plan['reporting_analytics_summary'] ?? [], 'has_reporting_analytics_signals', 'reporting_analytics_summary'),
				'notification_communication_summary'=>$this->app_builder_compact_optional_summary($builder_plan['notification_communication_summary'] ?? [], 'has_notification_communication_signals', 'notification_communication_summary'),
				'panel_fields'=>$builder_plan['panel_fields'] ?? [],
				'filters'=>$builder_plan['filters'] ?? [],
				'actions'=>$builder_plan['actions'] ?? [],
				'implementation_matrix'=>$builder_plan['implementation_matrix'] ?? [],
				'verification_handoff'=>$this->mcp_app_builder_compact_verification_handoff(is_array($builder_plan['verification_plan']['handoff'] ?? null) ? $builder_plan['verification_plan']['handoff'] : []),
				'verification_evidence'=>$builder_plan['verification_plan']['evidence_to_collect'] ?? [],
				'verification_evidence_summary'=>$this->mcp_compact_preview_summary(
					is_array($builder_plan['verification_plan']['evidence_to_collect'] ?? null) ? $builder_plan['verification_plan']['evidence_to_collect'] : [],
					count(is_array($builder_plan['verification_plan']['evidence_to_collect'] ?? null) ? $builder_plan['verification_plan']['evidence_to_collect'] : []),
					'dataphyre_app_builder_plan_generate payload_profile=full'
				),
				'verification_todo'=>$builder_plan['verification_plan']['verification_todo'] ?? [],
				'app_contract_summary'=>$builder_plan['app_contract_summary'] ?? [],
				'code_skeleton_summary'=>$builder_plan['code_skeleton_summary'] ?? [],
				'prewrite_checklist'=>$builder_plan['prewrite_checklist'] ?? [],
				'write_readiness'=>$first_read['write_readiness'] ?? [],
				'detail_pagination'=>$this->mcp_app_builder_detail_pagination(),
				'detail_refs'=>[
					'files'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.files',
					'schema'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.schema',
					'panel_fields'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.panel_fields',
					'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.implementation_recipe',
					'verification'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.verification_execution_plan',
					'controls'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan policy/control handoffs',
				],
				'secondary_context'=>'Default app-builder compact profile is first-page plus summaries; rerun payload_profile=full only for concrete file/schema bodies, implementation recipes, full handoff fields, or skeleton bodies needed next.',
			])
			: $this->app_builder_compact_response($builder_plan, [
				'module_docs'=>'focused_context.docs',
				'entity_input_contract'=>'entity_input_contract',
				'field_input_contract'=>'field_input_contract',
				'full_builder_plan'=>'builder_plan',
				'governance'=>'dataphyre_task_pack_generate payload_profile=governance only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary(),
			]);
		if($payload_profile==='compact'){
			$builder_response=$this->mcp_app_builder_strip_compact_raw_handoff_fields($builder_response);
			$builder_response=$this->mcp_app_builder_paginate_compact_details($builder_response);
			$detail_page=trim(strtolower((string)($args['detail_page'] ?? '')));
			if($detail_page!==''){
				$builder_response['selected_detail_page']=$this->mcp_app_builder_selected_detail_page($detail_page, $builder_plan, $builder_response);
				$builder_response=$this->mcp_app_builder_apply_selected_page_detail_counts($builder_response);
			}
			if($this->mcp_app_builder_should_enforce_compact_budget($args, ['builder_response'=>$builder_response])){
				$builder_response=$this->mcp_app_builder_enforce_compact_budget($builder_response);
				if($detail_page!==''){
					$builder_response=$this->mcp_app_builder_apply_selected_page_detail_counts($builder_response);
				}
			}
		}
		$payload=[
			'builder_response'=>$builder_response,
			'plan_type'=>'dataphyre_app_builder_plan',
			'payload_profile'=>$payload_profile,
			'code_skeletons_included'=>$payload_profile!=='compact',
			'details_collapsed'=>$payload_profile==='compact',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'task'=>$task,
			'focused_context'=>[
				'entrypoint_tool'=>$lane['entrypoint_tool'] ?? 'dataphyre_app_builder_plan_generate',
				'primary_tool'=>$lane['primary_tool'] ?? 'dataphyre_scaffold_plan_generate',
				'scaffold_tool'=>$lane['scaffold_tool'] ?? 'dataphyre_scaffold_plan_generate',
				'follow_up_tools'=>$lane['follow_up_tools'] ?? [],
				'docs'=>$this->app_builder_focused_docs($lane),
				'optional_guidance_docs'=>[
					'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
				],
			],
			'entity_input_contract'=>$this->app_builder_entity_input_contract($lane, $args),
			'field_input_contract'=>$this->app_builder_field_input_contract($lane, $args),
			'governance_notes'=>$this->app_builder_governance_notes($builder_plan, $lane),
			'context_links'=>[
				'application_agent_operating_contract'=>'dataphyre_mcp_readiness_report',
				'ordinary_app_work'=>'dataphyre_mcp_readiness_report',
				'extension_boundary'=>$payload_profile==='compact' ? 'dataphyre_app_builder_plan_generate payload_profile=full then builder_plan.extension_decision_ladder' : 'builder_plan.extension_decision_ladder',
				'governance_detail'=>'dataphyre_task_pack_generate payload_profile=governance',
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
			],
		];
		if($payload_profile==='full'){
			$payload['builder_plan']=$builder_plan;
		}else{
			$payload['omitted_default_fields']=[
				'builder_plan',
				'code_skeletons',
				'raw handoff_fields',
			];
			$payload['open_full_plan_with']='dataphyre_app_builder_plan_generate payload_profile=full';
			$payload['context_links']['full_builder_plan']='dataphyre_app_builder_plan_generate payload_profile=full';
		}
		if($payload_profile==='compact' && $this->mcp_app_builder_should_enforce_compact_budget($args, $payload)){
			$payload=$this->mcp_app_builder_enforce_compact_payload_budget($payload, $args);
		}
		return $payload;
	}

	private function mcp_app_builder_should_enforce_compact_budget(array $args, array $payload): bool {
		$entities=is_array($args['entities'] ?? null) ? $args['entities'] : [];
		if(!empty($args['enforce_payload_budget']) || trim((string)($args['detail_page'] ?? ''))!==''){
			return true;
		}
		$has_explicit_scaffold_input=($payload['entity_input_contract']['provided'] ?? false)===true
			|| ($payload['field_input_contract']['provided'] ?? false)===true;
		$explicit_field_entities=is_array($payload['field_input_contract']['explicit_entities'] ?? null)
			? $payload['field_input_contract']['explicit_entities']
			: [];
		$has_large_explicit_field_map=($payload['field_input_contract']['provided'] ?? false)===true
			&& count($explicit_field_entities)>=5;
		if($has_large_explicit_field_map){
			return true;
		}
		if($has_explicit_scaffold_input || $entities!==[] || is_array($args['fields'] ?? null)){
			return false;
		}
		$budget=is_array($payload['builder_response']['payload_budget'] ?? null)
			? $payload['builder_response']['payload_budget']
			: (is_array($payload['payload_budget'] ?? null) ? $payload['payload_budget'] : []);
		$max=(int)($budget['max_response_chars'] ?? 60000);
		if($max<=0){
			$max=60000;
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		return is_string($encoded) && strlen($encoded)>$max;
	}

	private function mcp_app_builder_selected_detail_page(string $detail_page, array $builder_plan, array $builder_response): array {
		$pagination=$this->mcp_app_builder_detail_pagination();
		$pages=is_array($pagination['pages'] ?? null) ? $pagination['pages'] : [];
		$allowed_pages=array_values(array_keys($pages));
		if(!in_array($detail_page, $allowed_pages, true)){
			return [
				'status'=>'invalid_detail_page',
				'requested'=>$detail_page,
				'allowed_pages'=>$allowed_pages,
				'open_rule'=>$pagination['open_rule'] ?? 'Open only the page needed for the next edit, blocker, or elevated-risk decision.',
			];
		}
		$sections=array_values(array_map('strval', is_array($pages[$detail_page] ?? null) ? $pages[$detail_page] : []));
		$payload=[
			'status'=>'selected',
			'page'=>$detail_page,
			'sections'=>$sections,
			'source'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page='.$detail_page,
			'payload_policy'=>'Only this detail page is materialized; rerun payload_profile=full when skeleton bodies or full cross-page context are needed.',
			'data'=>[],
			'omitted_sections'=>[],
		];
		foreach($sections as $section){
			if(array_key_exists($section, $builder_plan)){
				$payload['data'][$section]=$builder_plan[$section];
				continue;
			}
			if(array_key_exists($section, $builder_response)){
				$payload['data'][$section]=$builder_response[$section];
				continue;
			}
			if($detail_page==='governance' && $section==='enterprise_audit'){
				$payload['data'][$section]=[
					'status'=>'not_inlined_for_direct_app_builder',
					'owner'=>'maintainer_or_elevated_review',
					'open_with'=>'dataphyre_mcp_enterprise_adoption_audit',
					'start_pack_deep'=>'dataphyre_mcp_task_start_pack_export payload_profile=deep',
					'trigger_rule'=>'Open only for corporate-ready, public/release-facing, security/governance-sensitive, or Dataphyre runtime/hot-path work.',
					'ordinary_app_default'=>'not_required',
				];
				continue;
			}
			$payload['omitted_sections'][$section]=[
				'status'=>'not_available_in_builder_plan',
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			];
		}
		return $this->mcp_app_builder_limit_selected_detail_page($payload);
	}

	private function mcp_app_builder_limit_selected_detail_page(array $payload): array {
		if(!is_array($payload['data'] ?? null)){
			return $payload;
		}
		$page=(string)($payload['page'] ?? '');
		$limits=[
			['implementation_matrix', 'work_items', 12],
			['implementation_recipe', 'items', 12],
			['implementation_recipe', 'parallel_batches', 'batches', 4],
			['local_convention_probe', 'items', 8],
			['verification_execution_plan', 'items', 8],
			['verification_fixture_handoff', 'fixtures', 6],
			['verification_fixture_handoff', 'relationship_cases', 6],
			['verification_fixture_handoff', 'lifecycle_cases', 6],
			['acceptance_review_plan', 'items', 6],
			['acceptance_review_plan', 'obligation_review_items', 8],
			['verification_recovery_plan', 'branches', 6],
			['files', null, 16],
			['schema', null, 8],
			['panel_fields', null, 8],
			['filters', null, 8],
			['actions', null, 8],
		];
		foreach($limits as $limit){
			[$section, $child, $max]=$limit;
			$path=['data', (string)$section];
			if($child!==null){
				$path[]=(string)$child;
			}
			$payload=$this->mcp_app_builder_limit_compact_list($payload, $path, (int)$max, 'selected_detail_page.data.'.(string)$section.($child!==null ? '.'.(string)$child : ''));
		}
		foreach(is_array($payload['data']['implementation_recipe']['items'] ?? null) ? array_keys($payload['data']['implementation_recipe']['items']) : [] as $index){
			if(is_array($payload['data']['implementation_recipe']['items'][$index] ?? null)){
				$payload['data']['implementation_recipe']['items'][$index]=$this->mcp_app_builder_compact_recipe_item(
					$payload['data']['implementation_recipe']['items'][$index],
					'selected_detail_page.data.implementation_recipe.items['.(string)$index.']'
				);
			}
		}
		$payload=$this->mcp_app_builder_strip_compact_raw_handoff_fields($payload);
		$max=30000;
		$collapsed=[];
		foreach(['write_handoff', 'implementation_recipe', 'implementation_matrix', 'companion_surface_handoff', 'surface_execution_plan', 'local_convention_probe'] as $section){
			$encoded=$this->mcp_app_builder_compact_budget_json(['selected_detail_page'=>$payload]);
			if(is_string($encoded) && strlen($encoded)<=$max){
				break;
			}
			if(!is_array($payload['data'][$section] ?? null)){
				continue;
			}
			$payload['data'][$section]=$this->mcp_app_builder_selected_detail_section_summary(
				$section,
				$payload['data'][$section],
				'selected_detail_page.data.'.$section
			);
			$collapsed[]=$section;
		}
		$encoded=$this->mcp_app_builder_compact_budget_json(['selected_detail_page'=>$payload]);
		$payload['page_budget']=[
			'applied'=>true,
			'page'=>$page,
			'max_detail_chars'=>$max,
			'current_chars'=>is_string($encoded) ? strlen($encoded) : null,
			'collapsed_sections'=>$collapsed,
			'overflow_action'=>'Rerun dataphyre_app_builder_plan_generate payload_profile=full only when skeleton bodies or full cross-page context are needed.',
		];
		return $payload;
	}

	private function mcp_app_builder_selected_detail_section_summary(string $section, array $value, string $source): array {
		$summary=[
			'owner'=>$value['owner'] ?? 'consuming_application',
			'status'=>$value['status'] ?? 'summarized_for_compact_detail_page',
			'collapsed'=>true,
			'source'=>$source,
			'keys'=>array_values(array_slice(array_map('strval', array_keys($value)), 0, 12)),
			'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		];
		foreach([
			'items'=>'item_count',
			'work_items'=>'work_item_count',
			'batches'=>'batch_count',
			'steps'=>'step_count',
			'files'=>'file_count',
			'fields'=>'field_count',
			'tools'=>'tool_count',
		] as $key=>$count_key){
			if(is_array($value[$key] ?? null)){
				$summary[$count_key]=count($value[$key]);
			}
		}
		if(is_array($value['parallel_batches']['batches'] ?? null)){
			$summary['batch_count']=count($value['parallel_batches']['batches']);
		}
		if(is_array($value['first_batch']['paths'] ?? null)){
			$summary['first_batch_paths']=array_values(array_slice(array_map('strval', $value['first_batch']['paths']), 0, 6));
		}
		if(is_array($value['first_batch']['tools'] ?? null)){
			$summary['first_batch_tools']=array_values(array_slice(array_map('strval', $value['first_batch']['tools']), 0, 6));
		}
		if($section==='implementation_recipe' && is_array($value['items'] ?? null)){
			$summary['first_items']=array_values(array_slice(array_map(static function(mixed $item): array {
				if(!is_array($item)){
					return ['value'=>(string)$item];
				}
				return array_filter([
					'kind'=>$item['kind'] ?? null,
					'path'=>$item['path'] ?? null,
					'action'=>$item['action'] ?? ($item['edit_task'] ?? null),
					'verification_tools'=>is_array($item['verification_tools'] ?? null) ? array_values(array_slice(array_map('strval', $item['verification_tools']), 0, 4)) : null,
				], static fn(mixed $entry): bool => $entry!==null && $entry!==[]);
			}, $value['items']), 0, 4));
		}
		return $summary;
	}

	private function mcp_app_builder_strip_compact_raw_handoff_fields(array $value): array {
		foreach($value as $key=>$item){
			if($key==='handoff_fields'){
				unset($value[$key]);
				continue;
			}
			if(is_array($item)){
				$value[$key]=$this->mcp_app_builder_strip_compact_raw_handoff_fields($item);
			}
		}
		return $value;
	}

	private function mcp_app_builder_paginate_compact_details(array $builder_response): array {
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['files'], 12, 'builder_response.files');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['schema'], 4, 'builder_response.schema');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['panel_fields'], 4, 'builder_response.panel_fields');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['filters'], 4, 'builder_response.filters');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['actions'], 4, 'builder_response.actions');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_evidence'], 8, 'builder_response.verification_evidence');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_todo'], 8, 'builder_response.verification_todo');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['implementation_recipe', 'parallel_batches', 'batches'], 4, 'builder_response.implementation_recipe.parallel_batches.batches');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['implementation_matrix', 'work_items'], 12, 'builder_response.implementation_matrix.work_items');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_execution_plan', 'items'], 8, 'builder_response.verification_execution_plan.items');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['acceptance_review_plan', 'items'], 6, 'builder_response.acceptance_review_plan.items');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['acceptance_review_plan', 'obligation_review_items'], 8, 'builder_response.acceptance_review_plan.obligation_review_items');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_recovery_plan', 'branches'], 6, 'builder_response.verification_recovery_plan.branches');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_fixture_handoff', 'fixtures'], 6, 'builder_response.verification_fixture_handoff.fixtures');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_fixture_handoff', 'relationship_cases'], 6, 'builder_response.verification_fixture_handoff.relationship_cases');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['verification_fixture_handoff', 'lifecycle_cases'], 6, 'builder_response.verification_fixture_handoff.lifecycle_cases');
		$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['code_skeleton_summary', 'path_reasons'], 8, 'builder_response.code_skeleton_summary.path_reasons');
		if(is_array($builder_response['code_skeleton_summary']['paths_by_kind'] ?? null)){
			foreach(array_keys($builder_response['code_skeleton_summary']['paths_by_kind']) as $kind){
				$builder_response=$this->mcp_app_builder_limit_compact_list($builder_response, ['code_skeleton_summary', 'paths_by_kind', (string)$kind], 4, 'builder_response.code_skeleton_summary.paths_by_kind.'.(string)$kind);
			}
		}
		if(is_array($builder_response['implementation_recipe']['items'] ?? null)){
			foreach($builder_response['implementation_recipe']['items'] as $index=>$item){
				if(is_array($item)){
					$builder_response['implementation_recipe']['items'][$index]=$this->mcp_app_builder_compact_recipe_item($item, 'builder_response.implementation_recipe.items['.(string)$index.']');
				}
			}
		}
		$collapsed_sections=[
			'data_model_handoff',
			'lifecycle_state_handoff',
			'access_control_handoff',
			'operational_reliability_handoff',
			'support_observability_handoff',
			'change_management_handoff',
			'integration_boundary_handoff',
			'tenant_identity_handoff',
			'domain_workflow_handoff',
			'reporting_analytics_handoff',
			'notification_communication_handoff',
			'verification_fixture_handoff',
			'verification_recovery_plan',
		];
		$collapsed_present=[];
		foreach($collapsed_sections as $section){
			if(array_key_exists($section, $builder_response)){
				if($section==='verification_recovery_plan' && is_array($builder_response[$section])){
					$plan=$builder_response[$section];
					$branch_count=count(is_array($plan['branches'] ?? null) ? $plan['branches'] : []);
					$builder_response[$section]=[
						'owner'=>(string)($plan['owner'] ?? 'consuming_application'),
						'status'=>(string)($plan['status'] ?? 'ready_for_focused_check_failures'),
						'purpose'=>(string)($plan['purpose'] ?? 'Focused recovery branch summary.'),
						'branch_count'=>$branch_count,
						'diagnostic_tool'=>$plan['diagnostic_tool'] ?? 'dataphyre_diagnostics_last_error',
						'copy_safe_source'=>$plan['copy_safe_source'] ?? 'diagnostic_summary.copy_safe_evidence',
						'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					];
					$collapsed_present[$section]=[
						'available'=>true,
						'inline'=>'summary',
						'count'=>$branch_count,
						'count_label'=>'branches',
						'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					];
					continue;
				}
				$collapsed_present[$section]=[
					'available'=>true,
					'count'=>$this->mcp_app_builder_collapsed_section_count((string)$section, is_array($builder_response[$section]) ? $builder_response[$section] : []),
					'count_label'=>$this->mcp_app_builder_collapsed_section_count_label((string)$section),
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				];
				unset($builder_response[$section]);
			}
		}
		foreach([
			'data_model_handoff',
			'relationship_adapter_handoff',
			'implementation_recipe',
			'verification_execution_plan',
			'acceptance_review_plan',
		] as $section){
			if(!isset($collapsed_present[$section])){
				$collapsed_present[$section]=[
					'available'=>true,
					'inline'=>'omitted_from_compact_default',
					'count'=>$this->mcp_app_builder_collapsed_section_count((string)$section, is_array($builder_response[$section] ?? null) ? $builder_response[$section] : []),
					'count_label'=>$this->mcp_app_builder_collapsed_section_count_label((string)$section),
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				];
			}
		}
		$builder_response['compact_detail_policy']=[
			'profile'=>'compact',
			'inline_policy'=>'Compact responses keep the first app-agent page, concrete scaffold artifacts, chunking, write blockers, and focused verification pointers inline; implementation recipes, after-write reviews, and corporate-control handoffs are opened explicitly.',
			'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			'collapsed_sections'=>$collapsed_present,
			'detail_counts'=>$this->mcp_app_builder_compact_detail_counts($builder_response, $collapsed_present),
			'not_omitted'=>[
				'first_read',
				'files',
				'schema',
				'entity_planning.continuation_calls',
				'prewrite_checklist.prewrite_blockers',
				'detail_pagination',
				'verification_evidence_summary',
			],
		];
		return $builder_response;
	}

	private function mcp_app_builder_enforce_compact_budget(array $builder_response): array {
		$budget=is_array($builder_response['payload_budget'] ?? null) ? $builder_response['payload_budget'] : [];
		$max=(int)($budget['max_response_chars'] ?? 60000);
		if($max<=0){
			$max=60000;
		}
		$encoded=$this->mcp_app_builder_compact_budget_json(['builder_response'=>$builder_response]);
		if(is_string($encoded) && strlen($encoded)<=$max){
			return $builder_response;
		}
		$collapse_sequence=[
			'field_metadata_summary',
			'relationship_contract_summary',
			'data_integrity_summary',
			'lifecycle_policy_summary',
			'audit_retention_summary',
			'access_control_summary',
			'operational_reliability_summary',
			'support_observability_summary',
			'change_management_summary',
			'integration_boundary_summary',
			'business_policy_summary',
			'process_policy_summary',
			'reporting_analytics_summary',
			'notification_communication_summary',
			'implementation_matrix',
			'verification_evidence',
			'verification_todo',
			'code_skeleton_summary',
			'companion_surface_handoff',
			'panel_fields',
			'filters',
			'actions',
		];
		$collapsed_sections=is_array($builder_response['compact_detail_policy']['collapsed_sections'] ?? null)
			? $builder_response['compact_detail_policy']['collapsed_sections']
			: [];
		$collapsed=[];
		foreach($collapse_sequence as $section){
			if(!array_key_exists($section, $builder_response)){
				continue;
			}
			if($this->mcp_app_builder_keep_compact_enterprise_summary($section, $builder_response[$section])){
				$builder_response[$section]=$this->mcp_app_builder_compact_enterprise_summary($section, is_array($builder_response[$section]) ? $builder_response[$section] : []);
				continue;
			}
			$collapsed_sections[$section]=[
				'available'=>true,
				'inline'=>'omitted_after_budget_enforcement',
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			];
			unset($builder_response[$section]);
			$collapsed[]=$section;
		}
		if(is_array($builder_response['entity_input_contract'] ?? null)){
			$builder_response['entity_input_contract']=$this->mcp_app_builder_compact_entity_input_contract($builder_response['entity_input_contract']);
		}
		if(is_array($builder_response['app_contract_summary'] ?? null)){
			$builder_response['app_contract_summary']=$this->mcp_app_builder_compact_app_contract_summary($builder_response['app_contract_summary']);
		}
		if(
			(
				!is_array($builder_response['data_sensitivity_summary'] ?? null)
				|| (($builder_response['data_sensitivity_summary']['categories'] ?? [])===[])
			)
			&& is_array($builder_response['schema'] ?? null)
		){
			$sensitivity=$this->app_builder_data_sensitivity_summary($this->app_builder_sensitivity_schemas(
				$builder_response['schema'],
				is_array($builder_response['entity_planning'] ?? null) ? $builder_response['entity_planning'] : []
			));
			$builder_response['data_sensitivity_summary']=$this->mcp_app_builder_compact_policy_summary($sensitivity);
		}
		if(is_array($builder_response['prewrite_checklist'] ?? null)){
			$builder_response['prewrite_checklist']=$this->mcp_app_builder_compact_prewrite_checklist($builder_response['prewrite_checklist']);
		}
		$builder_response['budget_enforcement']=[
			'applied'=>true,
			'max_response_chars'=>$max,
			'collapsed_sections'=>$collapsed,
			'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		];
		$builder_response['compact_detail_policy']['collapsed_sections']=$collapsed_sections;
		$builder_response['compact_detail_policy']['budget_enforced']=true;
		$builder_response['compact_detail_policy']['detail_counts']=$this->mcp_app_builder_compact_detail_counts($builder_response, $collapsed_sections);
		return $builder_response;
	}

	private function mcp_app_builder_keep_compact_enterprise_summary(string $section, mixed $value): bool {
		if(!is_array($value)){
			return false;
		}
		return match($section){
			'data_integrity_summary'=>!empty($value['unique_constraints']),
			'audit_retention_summary'=>($value['has_audit_retention_fields'] ?? false)===true,
			'access_control_summary'=>($value['has_access_control_fields'] ?? false)===true,
			'operational_reliability_summary'=>($value['has_operational_reliability_signals'] ?? false)===true,
			'support_observability_summary'=>($value['has_support_observability_signals'] ?? false)===true,
			'change_management_summary'=>($value['has_change_management_signals'] ?? false)===true,
			'integration_boundary_summary'=>($value['has_integration_boundary_signals'] ?? false)===true,
			'companion_surface_handoff'=>!empty($value['endpoint_queue']) || !empty($value['arguments']),
			default=>false,
		};
	}

	private function mcp_app_builder_compact_enterprise_summary(string $section, array $summary): array {
		if($section==='companion_surface_handoff'){
			return array_filter([
				'status'=>$summary['status'] ?? null,
				'owner'=>$summary['owner'] ?? 'consuming_application',
				'when'=>$summary['when'] ?? null,
				'next_tool'=>$summary['next_tool'] ?? 'dataphyre_app_builder_plan_generate',
				'arguments'=>$summary['arguments'] ?? [],
				'endpoint_queue'=>array_values(array_map(static function(mixed $endpoint): array {
					if(!is_array($endpoint)){
						return [];
					}
					$follow_up=is_array($endpoint['follow_up_arguments'] ?? null) ? $endpoint['follow_up_arguments'] : [];
					return array_filter([
						'id'=>$endpoint['id'] ?? null,
						'entity'=>$endpoint['entity'] ?? null,
						'method'=>$endpoint['method'] ?? null,
						'path'=>$endpoint['path'] ?? null,
						'next_tool'=>$endpoint['next_tool'] ?? null,
						'checks'=>array_values(array_slice(array_map('strval', is_array($endpoint['checks'] ?? null) ? $endpoint['checks'] : []), 0, 8)),
						'follow_up_arguments'=>array_filter([
							'task'=>$follow_up['task'] ?? null,
							'scaffold_type'=>$follow_up['scaffold_type'] ?? null,
							'payload_profile'=>$follow_up['payload_profile'] ?? null,
							'name'=>$follow_up['name'] ?? null,
							'path'=>$follow_up['path'] ?? null,
							'methods'=>array_values(array_map('strval', is_array($follow_up['methods'] ?? null) ? $follow_up['methods'] : [])),
							'group'=>$follow_up['group'] ?? null,
							'auth'=>$follow_up['auth'] ?? null,
							'application_path'=>$follow_up['application_path'] ?? null,
							'app_namespace'=>$follow_up['app_namespace'] ?? null,
						], static fn(mixed $value): bool => $value!==null && $value!==[] && $value!==''),
					], static fn(mixed $value): bool => $value!==null && $value!==[] && $value!=='');
				}, is_array($summary['endpoint_queue'] ?? null) ? $summary['endpoint_queue'] : [])),
			], static fn(mixed $value): bool => $value!==null && $value!==[] && $value!=='');
		}
		if($section==='data_integrity_summary'){
			return array_filter([
				'unique_constraints'=>array_values(is_array($summary['unique_constraints'] ?? null) ? $summary['unique_constraints'] : []),
				'foreign_key_count'=>count(is_array($summary['foreign_keys'] ?? null) ? $summary['foreign_keys'] : []),
				'index_count'=>count(is_array($summary['indexes'] ?? null) ? $summary['indexes'] : []),
			], static fn(mixed $value): bool => $value!==null && $value!==[]);
		}
		$flag=match($section){
			'audit_retention_summary'=>'has_audit_retention_fields',
			'access_control_summary'=>'has_access_control_fields',
			'operational_reliability_summary'=>'has_operational_reliability_signals',
			'support_observability_summary'=>'has_support_observability_signals',
			'change_management_summary'=>'has_change_management_signals',
			'integration_boundary_summary'=>'has_integration_boundary_signals',
			default=>'',
		};
		$compact=[];
		if($flag!==''){
			$compact[$flag]=$summary[$flag] ?? true;
		}
		if(is_array($summary['task_signals'] ?? null)){
			$compact['task_signals']=array_values(array_map('strval', $summary['task_signals']));
		}
		if(is_array($summary['controls'] ?? null)){
			$compact['controls']=array_values(array_map(static function(mixed $control): array {
				return ['id'=>is_array($control) ? (string)($control['id'] ?? '') : (string)$control];
			}, array_filter($summary['controls'], static fn(mixed $control): bool => is_array($control) ? trim((string)($control['id'] ?? ''))!=='' : trim((string)$control)!=='')));
		}
		if(is_array($summary['fields_by_category'] ?? null)){
			$compact['fields_by_category']=[];
			foreach($summary['fields_by_category'] as $category=>$fields){
				if(!is_array($fields)){
					continue;
				}
				$compact['fields_by_category'][(string)$category]=array_values(array_slice(array_map(static function(mixed $field): array {
					if(!is_array($field)){
						return ['field'=>(string)$field];
					}
					return array_filter([
						'field'=>$field['field'] ?? null,
						'entity'=>$field['entity'] ?? null,
					], static fn(mixed $value): bool => $value!==null && $value!=='');
				}, $fields), 0, 4));
			}
		}
		$not_required_marker=match($section){
			'operational_reliability_summary'=>'Dataphyre runtime-internal queue/outbox engine edits for one app',
			'support_observability_summary'=>'Dataphyre runtime-internal observability or incident engine edits for one app',
			'change_management_summary'=>'package release validation for ordinary app-owned rollout fields',
			default=>'',
		};
		if($not_required_marker!==''){
			$compact['not_required']=[$not_required_marker];
		}
		return $compact;
	}

	private function mcp_app_builder_compact_entity_input_contract(array $contract): array {
		return array_filter([
			'provided'=>$contract['provided'] ?? null,
			'input_mode'=>$contract['input_mode'] ?? null,
			'inference'=>$contract['inference'] ?? null,
			'entities'=>array_values(array_map('strval', is_array($contract['entities'] ?? null) ? $contract['entities'] : [])),
			'explicit_entities'=>array_values(array_map('strval', is_array($contract['explicit_entities'] ?? null) ? $contract['explicit_entities'] : [])),
			'inferred_entities'=>array_values(array_map('strval', is_array($contract['inferred_entities'] ?? null) ? $contract['inferred_entities'] : [])),
			'partial_explicit_fields'=>$contract['partial_explicit_fields'] ?? null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function mcp_app_builder_compact_app_contract_summary(array $summary): array {
		$feature_intent=is_array($summary['feature_intent_summary'] ?? null) ? $summary['feature_intent_summary'] : [];
		return array_filter([
			'owner'=>$summary['owner'] ?? null,
			'status'=>$summary['status'] ?? null,
			'feature_intent_summary'=>[
				'requested_features'=>array_values(array_map('strval', is_array($feature_intent['requested_features'] ?? null) ? $feature_intent['requested_features'] : [])),
			],
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function mcp_app_builder_compact_prewrite_checklist(array $checklist): array {
		$blockers=[];
		foreach(is_array($checklist['prewrite_blockers'] ?? null) ? $checklist['prewrite_blockers'] : [] as $blocker){
			if(!is_array($blocker)){
				continue;
			}
			$blockers[]=array_filter([
				'id'=>$blocker['id'] ?? null,
				'status'=>$blocker['status'] ?? null,
				'action'=>$blocker['action'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!=='');
		}
		return array_filter([
			'owner'=>$checklist['owner'] ?? null,
			'status'=>$checklist['status'] ?? null,
			'prewrite_blockers'=>$blockers,
			'resolution_plan'=>is_array($checklist['resolution_plan'] ?? null) ? [
				'owner'=>$checklist['resolution_plan']['owner'] ?? 'consuming_application',
				'status'=>$checklist['resolution_plan']['status'] ?? null,
				'items_count'=>count(is_array($checklist['resolution_plan']['items'] ?? null) ? $checklist['resolution_plan']['items'] : []),
			] : null,
			'prewrite_reminders_count'=>count(is_array($checklist['prewrite_reminders'] ?? null) ? $checklist['prewrite_reminders'] : []),
			'implementation_obligations_count'=>count(is_array($checklist['implementation_obligations'] ?? null) ? $checklist['implementation_obligations'] : []),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function mcp_app_builder_enforce_compact_payload_budget(array $payload, array $args=[]): array {
		$has_explicit_scaffold_input=($payload['entity_input_contract']['provided'] ?? false)===true
			|| ($payload['field_input_contract']['provided'] ?? false)===true;
		$builder_response=is_array($payload['builder_response'] ?? null) ? $payload['builder_response'] : [];
		$budget=is_array($builder_response['payload_budget'] ?? null) ? $builder_response['payload_budget'] : [];
		$max=(int)($budget['max_response_chars'] ?? 60000);
		if($max<=0){
			$max=60000;
		}
		$payload=$this->mcp_app_builder_trim_compact_handoff_overhead($payload);
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)<=$max){
			return $payload;
		}
		if(is_array($payload['entity_input_contract'] ?? null)){
			$contract=$payload['entity_input_contract'];
			$payload['entity_input_contract']=[
				'provided'=>$contract['provided'] ?? null,
				'input_mode'=>$contract['input_mode'] ?? null,
				'explicit_entities'=>array_values(array_slice(array_map('strval', is_array($contract['explicit_entities'] ?? null) ? $contract['explicit_entities'] : []), 0, 8)),
				'inferred_entities_count'=>count(is_array($contract['inferred_entities'] ?? null) ? $contract['inferred_entities'] : []),
				'full_contract'=>'dataphyre_app_builder_plan_generate payload_profile=full -> entity_input_contract',
			];
		}
		if(is_array($payload['field_input_contract'] ?? null)){
			$contract=$payload['field_input_contract'];
			$payload['field_input_contract']=[
				'provided'=>$contract['provided'] ?? null,
				'input_mode'=>$contract['input_mode'] ?? null,
				'explicit_entities'=>array_values(array_slice(array_map('strval', is_array($contract['explicit_entities'] ?? null) ? $contract['explicit_entities'] : []), 0, 8)),
				'accepted_metadata_count'=>count(is_array($contract['accepted_metadata'] ?? null) ? $contract['accepted_metadata'] : []),
				'full_contract'=>'dataphyre_app_builder_plan_generate payload_profile=full -> field_input_contract',
			];
		}
		if(is_array($payload['governance_notes'] ?? null)){
			$notes=$payload['governance_notes'];
			$payload['governance_notes']=[
				'status'=>$notes['status'] ?? null,
				'mode'=>$notes['mode'] ?? null,
				'categories'=>array_values(array_slice(array_map('strval', is_array($notes['categories'] ?? null) ? $notes['categories'] : []), 0, 8)),
				'policy_required_count'=>$notes['policy_required_count'] ?? null,
				'full_notes'=>'dataphyre_app_builder_plan_generate payload_profile=full -> governance_notes',
			];
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['compact_detail_policy']['collapsed_sections'] ?? null)){
			$payload['builder_response']['compact_detail_policy']['collapsed_sections_count']=count($payload['builder_response']['compact_detail_policy']['collapsed_sections']);
			$payload['builder_response']['compact_detail_policy']['collapsed_sections_ref']='dataphyre_app_builder_plan_generate payload_profile=full';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null) && is_array($payload['builder_response']['compact_detail_policy']['detail_counts'] ?? null)){
			$counts=$payload['builder_response']['compact_detail_policy']['detail_counts'];
			$payload['builder_response']['compact_detail_policy']['detail_counts_summary']=[
				'available'=>true,
				'count'=>count($counts),
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.compact_detail_policy.detail_counts',
			];
			unset($payload['builder_response']['compact_detail_policy']['detail_counts']);
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['payload_budget']['escalation_policy'] ?? null)){
			$policy=$payload['builder_response']['payload_budget']['escalation_policy'];
			$payload['builder_response']['payload_budget']['escalation_policy']=[
				'default_owner'=>$policy['default_owner'] ?? 'consuming_application',
				'default_verification'=>$policy['default_verification'] ?? 'focused_application_or_module_checks',
				'use_extension_points_first_count'=>count(is_array($policy['use_extension_points_first'] ?? null) ? $policy['use_extension_points_first'] : []),
				'do_not_escalate_for_count'=>count(is_array($policy['do_not_escalate_for'] ?? null) ? $policy['do_not_escalate_for'] : []),
				'escalate_only_for_count'=>count(is_array($policy['escalate_only_for'] ?? null) ? $policy['escalate_only_for'] : []),
				'full_policy_ref'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.payload_budget.escalation_policy',
			];
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['budget_enforcement'] ?? null)){
			$enforcement=$payload['builder_response']['budget_enforcement'];
			$payload['builder_response']['budget_enforcement']=[
				'applied'=>true,
				'max_response_chars'=>$enforcement['max_response_chars'] ?? $max,
				'collapsed_sections_count'=>count(is_array($enforcement['collapsed_sections'] ?? null) ? $enforcement['collapsed_sections'] : []),
				'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			];
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max){
			unset(
				$payload['builder_response']['verification_evidence_pagination'],
				$payload['builder_response']['verification_todo_pagination'],
				$payload['builder_response']['files_pagination'],
				$payload['builder_response']['schema_pagination'],
				$payload['builder_response']['panel_fields_pagination'],
				$payload['builder_response']['filters_pagination'],
				$payload['builder_response']['actions_pagination']
			);
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max){
			unset(
				$payload['builder_response']['secondary_context'],
				$payload['builder_response']['next_edits'],
				$payload['builder_response']['verification']
			);
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max){
			unset(
				$payload['builder_response']['verification_evidence_summary'],
				$payload['builder_response']['policy_decision_register']
			);
			$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]='controls_summary';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max){
			if(is_array($payload['builder_response']['agent_workload'] ?? null)){
				$payload['builder_response']['agent_workload']=[
					'not_required_for_ordinary_app_work'=>['dataphyre_mcp_verify_all'],
					'full_workload'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.agent_workload',
				];
			}
			unset(
				$payload['builder_response']['governance_notes']
			);
			$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]='governance_budget_summary';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if($has_explicit_scaffold_input && is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			$payload['builder_response']=$this->mcp_app_builder_limit_compact_list($payload['builder_response'], ['detail_refs'], 4, 'builder_response.detail_refs');
			if(is_array($payload['builder_response']['detail_pagination'] ?? null)){
				unset(
					$payload['builder_response']['detail_pagination']['full_plan_tool'],
					$payload['builder_response']['detail_pagination']['start_pack_broader_context'],
					$payload['builder_response']['detail_pagination']['start_pack_detail'],
					$payload['builder_response']['detail_pagination']['start_pack_deep'],
					$payload['builder_response']['detail_pagination']['open_rule']
				);
			}
			if(is_array($payload['builder_response']['payload_budget'] ?? null)){
				unset(
					$payload['builder_response']['payload_budget']['detail_strategy'],
					$payload['builder_response']['payload_budget']['overflow_action'],
					$payload['builder_response']['payload_budget']['omitted_inline_detail'],
					$payload['builder_response']['payload_budget']['not_required'],
					$payload['builder_response']['payload_budget']['escalation_policy']
				);
			}
			unset(
				$payload['builder_response']['title'],
				$payload['builder_response']['active'],
				$payload['builder_response']['first_read_ref'],
				$payload['builder_response']['files_summary'],
				$payload['builder_response']['app_path_context'],
				$payload['builder_response']['schema_summary'],
				$payload['builder_response']['semantic_contract'],
				$payload['builder_response']['files_pagination'],
				$payload['builder_response']['schema_pagination'],
				$payload['builder_response']['detail_refs_pagination']
			);
			$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]='scaffold_list_budget_summary';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null) && is_array($payload['builder_response']['scaffold_completion_summary'] ?? null)){
			$payload['builder_response']['scaffold_completion_summary']=$this->mcp_app_builder_scaffold_completion_budget_summary($payload['builder_response']['scaffold_completion_summary']);
			$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]='scaffold_completion_queue_summary';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if($has_explicit_scaffold_input && is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			if(is_array($payload['builder_response']['first_read'] ?? null)){
				$payload['builder_response']['first_read']=$this->mcp_app_builder_selected_detail_first_read_summary($payload['builder_response']['first_read']);
			}
			if(is_array($payload['builder_response']['entity_planning'] ?? null)){
				$payload['builder_response']['entity_planning']=$this->mcp_app_builder_selected_detail_entity_planning_summary($payload['builder_response']['entity_planning']);
			}
			foreach([
				'data_sensitivity_summary',
				'data_integrity_summary',
				'access_control_summary',
				'operational_reliability_summary',
				'support_observability_summary',
				'change_management_summary',
				'app_contract_summary',
				'prewrite_checklist',
				'write_readiness',
			] as $section){
				if(is_array($payload['builder_response'][$section] ?? null)){
					$payload['builder_response'][$section]=$this->mcp_app_builder_compact_section_count_summary($section, $payload['builder_response'][$section]);
				}
			}
			unset(
				$payload['builder_response']['files'],
				$payload['builder_response']['schema'],
				$payload['builder_response']['naming_contract'],
				$payload['builder_response']['panel_fields'],
				$payload['builder_response']['filters'],
				$payload['builder_response']['actions'],
				$payload['builder_response']['entity_input_contract'],
				$payload['builder_response']['field_input_contract'],
				$payload['builder_response']['field_metadata_summary'],
				$payload['builder_response']['verification_handoff'],
				$payload['builder_response']['app_builder_lane']
			);
			$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]='first_page_budget_summary';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if($has_explicit_scaffold_input && is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			$builder_response=$payload['builder_response'];
			$payload['builder_response']=array_filter([
				'detail_pagination'=>$builder_response['detail_pagination'] ?? null,
				'payload_budget'=>$builder_response['payload_budget'] ?? null,
				'first_read'=>is_array($builder_response['first_read'] ?? null) ? $this->mcp_app_builder_selected_detail_first_read_summary($builder_response['first_read']) : null,
				'next_action'=>$builder_response['next_action'] ?? null,
				'app_path_context'=>$builder_response['app_path_context'] ?? ($builder_response['first_read']['app_path_context'] ?? null),
				'files'=>(is_array($builder_response['files'] ?? null) && $builder_response['files']!==[]) ? $builder_response['files'] : (is_array($builder_response['files_summary'] ?? null) ? [
					'summary'=>$builder_response['files_summary'],
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				] : null),
				'files_summary'=>$builder_response['files_summary'] ?? null,
				'schema'=>(is_array($builder_response['schema'] ?? null) && $builder_response['schema']!==[]) ? $builder_response['schema'] : $this->mcp_app_builder_schema_summary_rows($builder_response),
				'schema_summary'=>$builder_response['schema_summary'] ?? null,
				'entity_planning'=>is_array($builder_response['entity_planning'] ?? null) ? $this->mcp_app_builder_selected_detail_entity_planning_summary($builder_response['entity_planning'], 1) : null,
				'scaffold_completion_summary'=>is_array($builder_response['scaffold_completion_summary'] ?? null) ? $this->mcp_app_builder_scaffold_completion_budget_summary($builder_response['scaffold_completion_summary']) : null,
				'write_readiness'=>is_array($builder_response['write_readiness'] ?? null) ? $this->mcp_app_builder_compact_section_count_summary('write_readiness', $builder_response['write_readiness']) : null,
				'detail_refs'=>$builder_response['detail_refs'] ?? null,
				'compact_detail_policy'=>[
					'profile'=>'compact',
					'budget_enforced'=>true,
					'collapsed_sections_count'=>count(is_array($builder_response['compact_detail_policy']['collapsed_sections'] ?? null) ? $builder_response['compact_detail_policy']['collapsed_sections'] : []),
					'detail_counts_summary'=>'collapsed_for_budget',
					'open_details_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<planning|implementation|verification|controls|governance>',
					'final_payload_collapsed_sections'=>array_values(array_unique(array_merge(
						array_map('strval', is_array($builder_response['compact_detail_policy']['final_payload_collapsed_sections'] ?? null) ? $builder_response['compact_detail_policy']['final_payload_collapsed_sections'] : []),
						['explicit_first_page_projection']
					))),
				],
				'budget_enforcement'=>[
					'applied'=>true,
					'max_response_chars'=>$max,
					'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				],
			], static fn(mixed $value): bool => $value!==null && $value!==[]);
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(!$has_explicit_scaffold_input && is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			$builder_response=$payload['builder_response'];
			$compact_policy=is_array($builder_response['compact_detail_policy'] ?? null) ? $builder_response['compact_detail_policy'] : [];
			$collapsed_count=max(1, count(is_array($compact_policy['collapsed_sections'] ?? null) ? $compact_policy['collapsed_sections'] : []));
			$strict_task=strtolower((string)($args['task'] ?? ''));
			$strict_inferred_first_page=$this->app_builder_has_customer_success_context($strict_task) || $this->app_builder_has_learning_compliance_context($strict_task) || $this->app_builder_has_provider_credentialing_context($strict_task);
			if($strict_inferred_first_page){
				$payload['builder_response']=array_filter([
					'payload_budget'=>$builder_response['payload_budget'] ?? null,
					'first_read'=>is_array($builder_response['first_read'] ?? null) ? $this->mcp_app_builder_selected_detail_first_read_summary($builder_response['first_read']) : null,
					'next_action'=>$builder_response['next_action'] ?? null,
					'files'=>$builder_response['files'] ?? null,
					'files_summary'=>$builder_response['files_summary'] ?? null,
					'schema'=>$builder_response['schema'] ?? null,
					'schema_summary'=>$builder_response['schema_summary'] ?? null,
					'naming_contract'=>$builder_response['naming_contract'] ?? null,
					'entity_input_contract'=>$builder_response['entity_input_contract'] ?? null,
					'entity_planning'=>is_array($builder_response['entity_planning'] ?? null) ? $this->mcp_app_builder_compact_inferred_entity_planning_summary($builder_response['entity_planning']) : null,
					'detail_pagination'=>$builder_response['detail_pagination'] ?? null,
					'scaffold_completion_summary'=>is_array($builder_response['scaffold_completion_summary'] ?? null) ? $this->mcp_app_builder_scaffold_completion_budget_summary($builder_response['scaffold_completion_summary']) : null,
					'write_readiness'=>is_array($builder_response['write_readiness'] ?? null) ? $this->mcp_app_builder_compact_section_count_summary('write_readiness', $builder_response['write_readiness']) : null,
					'verification_handoff'=>is_array($builder_response['verification_handoff'] ?? null) ? [
						'owner'=>$builder_response['verification_handoff']['owner'] ?? 'consuming_application',
						'status'=>$builder_response['verification_handoff']['status'] ?? null,
						'tools'=>array_values(array_slice(array_map('strval', is_array($builder_response['verification_handoff']['tools'] ?? null) ? $builder_response['verification_handoff']['tools'] : []), 0, 6)),
						'full_handoff'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
					] : null,
					'detail_refs'=>$builder_response['detail_refs'] ?? null,
					'compact_detail_policy'=>array_filter([
						'profile'=>'compact',
						'budget_enforced'=>true,
						'collapsed_sections_count'=>$collapsed_count,
						'detail_counts_summary'=>is_array($compact_policy['detail_counts'] ?? null) ? [
							'available'=>true,
							'count'=>count($compact_policy['detail_counts']),
							'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.compact_detail_policy.detail_counts',
						] : null,
						'open_details_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<planning|implementation|verification|controls|governance>',
						'final_payload_collapsed_sections'=>array_values(array_unique(array_merge(
							array_map('strval', is_array($compact_policy['final_payload_collapsed_sections'] ?? null) ? $compact_policy['final_payload_collapsed_sections'] : []),
							['inferred_prose_first_page_projection']
						))),
					], static fn(mixed $value): bool => $value!==null && $value!==[]),
					'budget_enforcement'=>[
						'applied'=>true,
						'max_response_chars'=>$max,
						'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					],
				], static fn(mixed $value): bool => $value!==null && $value!==[]);
			}else{
				$payload['builder_response']=array_filter([
					'payload_budget'=>$builder_response['payload_budget'] ?? null,
					'first_read'=>is_array($builder_response['first_read'] ?? null) ? $this->mcp_app_builder_selected_detail_first_read_summary($builder_response['first_read']) : null,
					'next_action'=>$builder_response['next_action'] ?? null,
					'agent_workload'=>$builder_response['agent_workload'] ?? null,
					'files'=>$builder_response['files'] ?? null,
					'files_summary'=>$builder_response['files_summary'] ?? null,
					'schema'=>$builder_response['schema'] ?? null,
					'schema_summary'=>$builder_response['schema_summary'] ?? null,
					'naming_contract'=>$builder_response['naming_contract'] ?? null,
					'entity_input_contract'=>$builder_response['entity_input_contract'] ?? null,
					'entity_planning'=>is_array($builder_response['entity_planning'] ?? null) ? $this->mcp_app_builder_selected_detail_entity_planning_summary($builder_response['entity_planning']) : null,
					'detail_pagination'=>$builder_response['detail_pagination'] ?? null,
					'scaffold_completion_summary'=>is_array($builder_response['scaffold_completion_summary'] ?? null) ? $this->mcp_app_builder_scaffold_completion_budget_summary($builder_response['scaffold_completion_summary']) : null,
					'data_sensitivity_summary'=>$builder_response['data_sensitivity_summary'] ?? null,
					'data_integrity_summary'=>$builder_response['data_integrity_summary'] ?? null,
					'access_control_summary'=>$builder_response['access_control_summary'] ?? null,
					'operational_reliability_summary'=>$builder_response['operational_reliability_summary'] ?? null,
					'support_observability_summary'=>$builder_response['support_observability_summary'] ?? null,
					'change_management_summary'=>$builder_response['change_management_summary'] ?? null,
					'integration_boundary_summary'=>$builder_response['integration_boundary_summary'] ?? null,
					'companion_surface_handoff'=>$builder_response['companion_surface_handoff'] ?? null,
					'audit_retention_summary'=>$builder_response['audit_retention_summary'] ?? null,
					'app_contract_summary'=>$builder_response['app_contract_summary'] ?? null,
					'prewrite_checklist'=>$builder_response['prewrite_checklist'] ?? null,
					'write_readiness'=>$builder_response['write_readiness'] ?? null,
					'verification_handoff'=>$builder_response['verification_handoff'] ?? null,
					'implementation_matrix'=>$builder_response['implementation_matrix'] ?? null,
					'detail_refs'=>$builder_response['detail_refs'] ?? null,
					'compact_detail_policy'=>array_filter([
						'profile'=>'compact',
						'budget_enforced'=>true,
						'collapsed_sections_count'=>$collapsed_count,
						'detail_counts_summary'=>is_array($compact_policy['detail_counts'] ?? null) ? [
							'available'=>true,
							'count'=>count($compact_policy['detail_counts']),
							'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.compact_detail_policy.detail_counts',
						] : null,
						'open_details_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<planning|implementation|verification|controls|governance>',
					], static fn(mixed $value): bool => $value!==null && $value!==[]),
					'budget_enforcement'=>[
						'applied'=>true,
						'max_response_chars'=>$max,
						'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					],
				], static fn(mixed $value): bool => $value!==null && $value!==[]);
			}
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && !is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			unset(
				$payload['governance_notes'],
				$payload['entity_input_contract'],
				$payload['field_input_contract'],
				$payload['focused_context']['optional_guidance_docs']
			);
			if(is_array($payload['focused_context']['docs'] ?? null)){
				$docs=$payload['focused_context']['docs'];
				$payload['focused_context']['docs']=[
					'collapsed'=>true,
					'module_count'=>count(is_array($docs['modules'] ?? null) ? $docs['modules'] : []),
					'chunk_count'=>count(is_array($docs['chunks'] ?? null) ? $docs['chunks'] : []),
					'open_with'=>'dataphyre_task_pack_generate payload_profile=builder or dataphyre_docs_chunks_export profile=builder',
				];
			}
			if(is_array($payload['context_links'] ?? null)){
				$payload['context_links']=[
					'full_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				];
			}
			$payload['compact_payload_collapsed_sections'][]='top_level_contract_refs';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			foreach([
				'first_read',
				'entity_planning',
				'app_contract_summary',
				'extension_boundary_summary',
				'write_readiness',
				'prewrite_checklist',
				'entity_input_contract',
				'field_metadata_summary',
				'data_integrity_summary',
			] as $section){
				if(array_key_exists($section, $payload['builder_response'] ?? [])){
					if($section==='first_read' && is_array($payload['builder_response'][$section])){
						$payload['builder_response'][$section]=$this->mcp_app_builder_selected_detail_first_read_summary($payload['builder_response'][$section]);
					}elseif($section==='entity_planning' && is_array($payload['builder_response'][$section])){
						$payload['builder_response'][$section]=$this->mcp_app_builder_selected_detail_entity_planning_summary($payload['builder_response'][$section], 1);
					}else{
						unset($payload['builder_response'][$section]);
					}
					$payload['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'][]=$section;
				}
				$encoded=$this->mcp_app_builder_compact_budget_json($payload);
				if(is_string($encoded) && strlen($encoded)<=$max){
					break;
				}
			}
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			unset(
				$payload['context_links'],
				$payload['governance_notes'],
				$payload['entity_input_contract'],
				$payload['field_input_contract'],
				$payload['focused_context']
			);
			$payload['compact_payload_collapsed_sections'][]='top_level_contract_refs';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['selected_detail_page'] ?? null) && is_array($payload['builder_response']['entity_planning'] ?? null)){
			$planning=$payload['builder_response']['entity_planning'];
			$payload['builder_response']['entity_planning']=[
				'owner'=>$planning['owner'] ?? 'consuming_application',
				'truncated'=>($planning['truncated'] ?? false)===true,
				'planned_count'=>count(is_array($planning['planned_entities'] ?? null) ? $planning['planned_entities'] : []),
				'deferred_count'=>count(is_array($planning['deferred_entities'] ?? null) ? $planning['deferred_entities'] : []),
				'continuation_count'=>count(is_array($planning['continuation_calls'] ?? null) ? $planning['continuation_calls'] : []),
				'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.entity_planning',
			];
			$payload['compact_payload_collapsed_sections'][]='selected_detail_entity_planning';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['builder_response']['compact_detail_policy']['collapsed_sections'] ?? null)){
			$payload['builder_response']['compact_detail_policy']['collapsed_sections_count']=count($payload['builder_response']['compact_detail_policy']['collapsed_sections']);
			$payload['builder_response']['compact_detail_policy']['collapsed_sections_ref']='dataphyre_app_builder_plan_generate payload_profile=full';
			unset($payload['builder_response']['compact_detail_policy']['collapsed_sections']);
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max && is_array($payload['focused_context']['docs'] ?? null)){
			$docs=$payload['focused_context']['docs'];
			$payload['focused_context']['docs']=[
				'collapsed'=>true,
				'module_count'=>count(is_array($docs['modules'] ?? null) ? $docs['modules'] : []),
				'chunk_count'=>count(is_array($docs['chunks'] ?? null) ? $docs['chunks'] : []),
				'open_with'=>'dataphyre_task_pack_generate payload_profile=builder or dataphyre_docs_chunks_export profile=builder',
			];
			$payload['compact_payload_collapsed_sections'][]='focused_context.docs';
		}
		$encoded=$this->mcp_app_builder_compact_budget_json($payload);
		if(is_string($encoded) && strlen($encoded)>$max){
			unset($payload['focused_context']['optional_guidance_docs']);
			$payload['compact_payload_collapsed_sections'][]='focused_context.optional_guidance_docs';
		}
		if(!is_array($payload['builder_response']['budget_enforcement'] ?? null)){
			$payload['builder_response']['budget_enforcement']=[
				'applied'=>true,
				'max_response_chars'=>$max,
				'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			];
		}else{
			$payload['builder_response']['budget_enforcement']['applied']=true;
			$payload['builder_response']['budget_enforcement']['max_response_chars']=$payload['builder_response']['budget_enforcement']['max_response_chars'] ?? $max;
		}
		if(!is_array($payload['builder_response']['selected_detail_page'] ?? null)){
			if(!is_array($payload['builder_response']['files_summary'] ?? null) && is_array($payload['builder_response']['scaffold_completion_summary']['planned_entities'] ?? null)){
				$payload['builder_response']['files_summary']=[
					'total'=>count($payload['builder_response']['scaffold_completion_summary']['planned_entities']),
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				];
			}
			if(!is_array($payload['builder_response']['schema_summary'] ?? null) && is_array($payload['builder_response']['scaffold_completion_summary']['planned_entities'] ?? null)){
				$payload['builder_response']['schema_summary']=[
					'total'=>count($payload['builder_response']['scaffold_completion_summary']['planned_entities']),
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				];
			}
			if(is_array($payload['builder_response']['first_read']['files_summary'] ?? null)){
				$payload['builder_response']['first_read']['files_summary']['open_with']='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning';
			}
			if(is_array($payload['builder_response']['first_read']['schema_summary'] ?? null)){
				$payload['builder_response']['first_read']['schema_summary']['open_with']='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning';
			}
			if(!array_key_exists('app_path_context', $payload['builder_response']) && is_array($payload['builder_response']['first_read']['app_path_context'] ?? null)){
				$payload['builder_response']['app_path_context']=$payload['builder_response']['first_read']['app_path_context'];
			}
			if((!array_key_exists('files', $payload['builder_response']) || $payload['builder_response']['files']===[]) && is_array($payload['builder_response']['files_summary'] ?? null)){
				$payload['builder_response']['files']=[
					'summary'=>$payload['builder_response']['files_summary'],
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				];
			}
			if((!array_key_exists('schema', $payload['builder_response']) || $payload['builder_response']['schema']===[]) && is_array($payload['builder_response']['schema_summary'] ?? null)){
				$payload['builder_response']['schema']=$this->mcp_app_builder_schema_summary_rows($payload['builder_response']);
			}
			if(!array_key_exists('naming_contract', $payload['builder_response'])){
				$payload['builder_response']['naming_contract']=[
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
					'full_contract'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.naming_contract',
				];
			}
		}
		$payload['compact_payload_budget_enforced']=true;
		return $payload;
	}

	private function mcp_app_builder_trim_compact_handoff_overhead(array $payload): array {
		if(!is_array($payload['builder_response'] ?? null)){
			return $payload;
		}
		if(is_array($payload['builder_response']['first_read']['next_action'] ?? null)){
			unset($payload['builder_response']['first_read']['next_action']['handoff_fields']);
			if(is_array($payload['builder_response']['first_read']['next_action']['write_start_packet'] ?? null)){
				$payload['builder_response']['first_read']['next_action']['write_start_packet']['after_write_detail']='detail_pagination.pages.verification';
			}
			if(is_array($payload['builder_response']['first_read']['next_action']['resume_cursor']['copy_forward'] ?? null)){
				$payload['builder_response']['first_read']['next_action']['resume_cursor']['copy_forward']=array_values(array_slice(array_map('strval', $payload['builder_response']['first_read']['next_action']['resume_cursor']['copy_forward']), 0, 3));
			}
			if(is_array($payload['builder_response']['first_read']['next_action']['not_required'] ?? null)){
				$payload['builder_response']['first_read']['next_action']['not_required_count']=count($payload['builder_response']['first_read']['next_action']['not_required']);
				unset($payload['builder_response']['first_read']['next_action']['not_required']);
			}
		}
		foreach([['write_readiness'], ['first_read', 'write_readiness']] as $path){
			$readiness=&$payload['builder_response'];
			foreach($path as $segment){
				if(!is_array($readiness[$segment] ?? null)){
					unset($readiness);
					continue 2;
				}
				$readiness=&$readiness[$segment];
			}
			if(is_array($readiness['write_start_contract'] ?? null)){
				unset($readiness['write_start_contract']['after_write_evidence']);
				$readiness['write_start_contract']['after_write_detail']='detail_pagination.pages.verification';
			}
			if(is_array($readiness['not_required'] ?? null)){
				$readiness['not_required_count']=count($readiness['not_required']);
				unset($readiness['not_required']);
			}
			unset($readiness);
		}
		foreach([['verification_handoff'], ['first_read', 'verification_handoff']] as $path){
			$handoff=&$payload['builder_response'];
			foreach($path as $segment){
				if(!is_array($handoff[$segment] ?? null)){
					unset($handoff);
					continue 2;
				}
				$handoff=&$handoff[$segment];
			}
			unset($handoff['post_write_handoff_template_ref']);
			$handoff['after_write_detail']='detail_pagination.pages.verification';
			unset($handoff);
		}
		return $payload;
	}

	private function mcp_app_builder_scaffold_completion_budget_summary(array $summary): array {
		return [
			'owner'=>$summary['owner'] ?? 'consuming_application',
			'complete'=>($summary['complete'] ?? false)===true,
			'status'=>$summary['status'] ?? null,
			'planned_count'=>$summary['planned_count'] ?? count(is_array($summary['planned_entities'] ?? null) ? $summary['planned_entities'] : []),
			'deferred_count'=>$summary['deferred_count'] ?? count(is_array($summary['deferred_entities'] ?? null) ? $summary['deferred_entities'] : []),
			'planned_entities'=>array_values(array_slice(array_map('strval', is_array($summary['planned_entities'] ?? null) ? $summary['planned_entities'] : []), 0, 8)),
			'deferred_entities'=>array_values(array_slice(array_map('strval', is_array($summary['deferred_entities'] ?? null) ? $summary['deferred_entities'] : []), 0, 24)),
			'next_action'=>$summary['next_action'] ?? null,
			'next_continuation'=>$summary['next_continuation'] ?? null,
			'continuation_count'=>$summary['continuation_count'] ?? count(is_array($summary['continuation_queue'] ?? null) ? $summary['continuation_queue'] : []),
			'full_queue'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.scaffold_completion_summary.continuation_queue',
		];
	}

	private function mcp_app_builder_compact_section_count_summary(string $section, array $payload): array {
		$count=0;
		foreach(['items', 'checks', 'required_checks', 'obligations', 'signals', 'decisions', 'blockers'] as $key){
			if(is_array($payload[$key] ?? null)){
				$count+=count($payload[$key]);
			}
		}
		if($count===0){
			$count=count($payload);
		}
		return [
			'collapsed'=>true,
			'section'=>$section,
			'item_count'=>$count,
			'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls',
			'full_section'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.'.$section,
		];
	}

	private function mcp_app_builder_schema_summary_rows(array $builder_response): array {
		$planned=is_array($builder_response['scaffold_completion_summary']['planned_entities'] ?? null)
			? $builder_response['scaffold_completion_summary']['planned_entities']
			: (is_array($builder_response['entity_planning']['planned_entities'] ?? null) ? $builder_response['entity_planning']['planned_entities'] : []);
		$rows=[];
		foreach(array_values(array_slice(array_map('strval', $planned), 0, 8)) as $entity){
			if($entity===''){
				continue;
			}
			$rows[]=[
				'entity'=>$entity,
				'fields'=>[],
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			];
		}
		return $rows;
	}

	private function mcp_app_builder_apply_selected_page_detail_counts(array $builder_response): array {
		$page=is_array($builder_response['selected_detail_page'] ?? null) ? $builder_response['selected_detail_page'] : [];
		$page_name=(string)($page['page'] ?? '');
		$data=is_array($page['data'] ?? null) ? $page['data'] : [];
		if(!is_array($builder_response['compact_detail_policy']['detail_counts'] ?? null)){
			$builder_response['compact_detail_policy']['detail_counts']=[];
		}
		if($page_name==='implementation'){
			$recipe=is_array($data['implementation_recipe'] ?? null) ? $data['implementation_recipe'] : [];
			$matrix=is_array($data['implementation_matrix'] ?? null) ? $data['implementation_matrix'] : [];
			$count=(int)($recipe['item_count'] ?? count(is_array($recipe['items'] ?? null) ? $recipe['items'] : []));
			if($count<=0){
				$count=(int)($matrix['work_item_count'] ?? count(is_array($matrix['work_items'] ?? null) ? $matrix['work_items'] : []));
			}
			if($count>0){
				$builder_response['compact_detail_policy']['detail_counts']['implementation_items']=[
					'count'=>$count,
					'detail'=>'detail_pagination.pages.implementation',
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				];
			}
		}
		if($page_name==='verification'){
			$verification=is_array($data['verification_execution_plan'] ?? null) ? $data['verification_execution_plan'] : [];
			$acceptance=is_array($data['acceptance_review_plan'] ?? null) ? $data['acceptance_review_plan'] : [];
			$verification_count=(int)($verification['item_count'] ?? count(is_array($verification['items'] ?? null) ? $verification['items'] : []));
			$acceptance_count=(int)($acceptance['item_count'] ?? count(is_array($acceptance['items'] ?? null) ? $acceptance['items'] : []));
			if($verification_count>0){
				$builder_response['compact_detail_policy']['detail_counts']['verification_items']=[
					'count'=>$verification_count,
					'detail'=>'detail_pagination.pages.verification',
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				];
			}
			if($acceptance_count>0){
				$builder_response['compact_detail_policy']['detail_counts']['acceptance_items']=[
					'count'=>$acceptance_count,
					'detail'=>'detail_pagination.pages.verification',
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				];
			}
		}
		return $builder_response;
	}

	private function mcp_app_builder_selected_detail_first_read_summary(array $first_read): array {
		return [
			'title'=>$first_read['title'] ?? 'Builder first read',
			'purpose'=>'Summarized for compact first-page reading; open the referenced detail page only when needed.',
			'next_action'=>$first_read['next_action'] ?? [],
			'files_summary'=>$first_read['files_summary'] ?? [],
			'schema_summary'=>$first_read['schema_summary'] ?? [],
			'app_path_context'=>$first_read['app_path_context'] ?? [],
			'write_readiness'=>is_array($first_read['write_readiness'] ?? null) ? array_filter([
				'status'=>$first_read['write_readiness']['status'] ?? null,
				'owner'=>$first_read['write_readiness']['owner'] ?? null,
				'blocked'=>($first_read['write_readiness']['blocked'] ?? null),
				'deferred_entities'=>array_values(array_slice(array_map('strval', is_array($first_read['write_readiness']['deferred_entities'] ?? null) ? $first_read['write_readiness']['deferred_entities'] : []), 0, 8)),
			], static fn(mixed $value): bool => $value!==null && $value!==[]) : [],
			'open_details'=>$first_read['open_details'] ?? [],
		];
	}

	private function mcp_app_builder_selected_detail_entity_planning_summary(array $planning, int $max_continuations=6): array {
		$continuation_calls=[];
		$max_continuations=max(0, min(6, $max_continuations));
		foreach(array_slice(is_array($planning['continuation_calls'] ?? null) ? $planning['continuation_calls'] : [], 0, $max_continuations) as $call){
			if(!is_array($call)){
				continue;
			}
			$continuation_calls[]=array_filter([
				'tool'=>$call['tool'] ?? 'dataphyre_app_builder_plan_generate',
				'entities'=>$call['entities'] ?? [],
				'arguments'=>is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
			], static fn(mixed $value): bool => $value!==null && $value!==[]);
		}
		return [
			'owner'=>$planning['owner'] ?? 'consuming_application',
			'policy'=>$planning['policy'] ?? null,
			'max_entities_per_response'=>$planning['max_entities_per_response'] ?? null,
			'total_entities'=>$planning['total_entities'] ?? count(is_array($planning['planned_entities'] ?? null) ? $planning['planned_entities'] : []),
			'truncated'=>($planning['truncated'] ?? false)===true,
			'planned_entities'=>array_values(array_slice(array_map('strval', is_array($planning['planned_entities'] ?? null) ? $planning['planned_entities'] : []), 0, 8)),
			'deferred_entities'=>array_values(array_slice(array_map('strval', is_array($planning['deferred_entities'] ?? null) ? $planning['deferred_entities'] : []), 0, 24)),
			'incoming_dependency_context'=>$planning['incoming_dependency_context'] ?? null,
			'dependency_summary'=>$planning['dependency_summary'] ?? null,
			'continuation_count'=>count(is_array($planning['continuation_calls'] ?? null) ? $planning['continuation_calls'] : []),
			'next_continuation'=>is_array($planning['continuation_calls'][0] ?? null) ? [
				'tool'=>$planning['continuation_calls'][0]['tool'] ?? 'dataphyre_app_builder_plan_generate',
				'entities'=>array_values(array_slice(array_map('strval', is_array($planning['continuation_calls'][0]['entities'] ?? null) ? $planning['continuation_calls'][0]['entities'] : []), 0, 8)),
				'argument_source'=>'entity_planning.continuation_calls[0].arguments',
			] : null,
			'continuation_calls'=>$continuation_calls,
			'open_full_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		];
	}

	private function mcp_app_builder_compact_inferred_entity_planning_summary(array $planning): array {
		$summary=$this->mcp_app_builder_selected_detail_entity_planning_summary($planning, 1);
		foreach($summary['continuation_calls'] ?? [] as $index=>$call){
			if(!is_array($call) || !is_array($call['arguments'] ?? null)){
				continue;
			}
			if(isset($call['arguments']['task'])){
				unset($summary['continuation_calls'][$index]['arguments']['task']);
				$summary['continuation_calls'][$index]['arguments']['task_ref']='current_request.task';
			}
			if(is_array($call['arguments']['dependency_context'] ?? null)){
				$summary['continuation_calls'][$index]['arguments']['dependency_context']=$this->mcp_app_builder_compact_dependency_context($call['arguments']['dependency_context']);
			}
			if(is_array($call['arguments']['fields'] ?? null)){
				unset($summary['continuation_calls'][$index]['arguments']['fields']);
				$summary['continuation_calls'][$index]['arguments']['reuse_fields_from_original']=false;
				$summary['continuation_calls'][$index]['arguments']['field_source']='inferred_defaults_from_task_and_entities';
				$summary['continuation_calls'][$index]['fields_ref']='dataphyre_app_builder_plan_generate payload_profile=full -> builder_response.entity_planning.continuation_calls['.$index.'].arguments.fields';
			}
		}
		return $summary;
	}

	private function mcp_app_builder_compact_dependency_context(array $context): array {
		if(is_array($context['policy_context'] ?? null)){
			$policy=$context['policy_context'];
			foreach(['tenant_scope_fields', 'ownership_fields', 'access_fields', 'billing_or_plan_fields', 'sensitive_fields'] as $key){
				if(is_array($policy[$key] ?? null) && $policy[$key]===[]){
					unset($policy[$key]);
				}
			}
			$context['policy_context']=$policy;
		}
		return $context;
	}

	private function mcp_app_builder_compact_budget_json(array $payload): ?string {
		$json=json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : null;
	}

	private function mcp_app_builder_compact_detail_counts(array $builder_response, array $collapsed_present): array {
		$implementation_count=count(is_array($builder_response['implementation_recipe']['items'] ?? null) ? $builder_response['implementation_recipe']['items'] : []);
		if($implementation_count===0){
			$implementation_count=(int)($collapsed_present['implementation_recipe']['count'] ?? 0);
		}
		$verification_count=count(is_array($builder_response['verification_execution_plan']['items'] ?? null) ? $builder_response['verification_execution_plan']['items'] : []);
		if($verification_count===0){
			$verification_count=(int)($collapsed_present['verification_execution_plan']['count'] ?? 0);
		}
		$acceptance_count=count(is_array($builder_response['acceptance_review_plan']['items'] ?? null) ? $builder_response['acceptance_review_plan']['items'] : []);
		if($acceptance_count===0){
			$acceptance_count=(int)($collapsed_present['acceptance_review_plan']['count'] ?? 0);
		}
		$counts=[
			'files'=>[
				'count'=>(int)($builder_response['files_summary']['total'] ?? count(is_array($builder_response['files'] ?? null) ? $builder_response['files'] : [])),
				'detail'=>array_key_exists('files', $builder_response) ? 'builder_response.files' : 'builder_response.files_summary',
			],
			'schema'=>[
				'count'=>(int)($builder_response['schema_summary']['total'] ?? count(is_array($builder_response['schema'] ?? null) ? $builder_response['schema'] : [])),
				'detail'=>array_key_exists('schema', $builder_response) ? 'builder_response.schema' : 'builder_response.schema_summary',
			],
			'implementation_items'=>[
				'count'=>$implementation_count,
				'detail'=>'detail_pagination.pages.implementation',
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
			],
			'verification_items'=>[
				'count'=>$verification_count,
				'detail'=>'detail_pagination.pages.verification',
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
			],
			'acceptance_items'=>[
				'count'=>$acceptance_count,
				'detail'=>'detail_pagination.pages.verification',
				'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
			],
			'collapsed_sections'=>[
				'count'=>count($collapsed_present),
				'detail'=>'compact_detail_policy.collapsed_sections',
			],
		];
		foreach($counts as $key=>$count){
			if(($count['count'] ?? 0)===0){
				unset($counts[$key]);
			}
		}
		return $counts;
	}

	private function mcp_app_builder_collapsed_section_count(string $section, array $value): int {
		return match($section){
			'implementation_recipe'=>count(is_array($value['items'] ?? null) ? $value['items'] : []),
			'implementation_matrix'=>count(is_array($value['work_items'] ?? null) ? $value['work_items'] : []),
			'verification_execution_plan'=>count(is_array($value['items'] ?? null) ? $value['items'] : []),
			'acceptance_review_plan'=>count(is_array($value['items'] ?? null) ? $value['items'] : []),
			'verification_fixture_handoff'=>count(is_array($value['fixtures'] ?? null) ? $value['fixtures'] : []),
			'verification_recovery_plan'=>count(is_array($value['branches'] ?? null) ? $value['branches'] : []),
			default=>count($value),
		};
	}

	private function mcp_app_builder_collapsed_section_count_label(string $section): string {
		return match($section){
			'implementation_recipe'=>'items',
			'implementation_matrix'=>'work_items',
			'verification_execution_plan'=>'items',
			'acceptance_review_plan'=>'items',
			'verification_fixture_handoff'=>'fixtures',
			'verification_recovery_plan'=>'branches',
			default=>'entries',
		};
	}

	private function mcp_app_builder_limit_compact_list(array $root, array $path, int $limit, string $source): array {
		$cursor=&$root;
		foreach($path as $segment){
			if(!is_array($cursor) || !array_key_exists($segment, $cursor)){
				return $root;
			}
			$cursor=&$cursor[$segment];
		}
		if(!is_array($cursor)){
			return $root;
		}
		$total=count($cursor);
		if($total<=$limit){
			return $root;
		}
		$cursor=array_slice($cursor, 0, $limit);
		$parent=&$root;
		foreach(array_slice($path, 0, -1) as $segment){
			if(!is_array($parent) || !array_key_exists($segment, $parent)){
				return $root;
			}
			$parent=&$parent[$segment];
		}
		$meta_key=(string)end($path).'_pagination';
		$parent[$meta_key]=[
			'total'=>$total,
			'shown'=>$limit,
			'omitted_count'=>$total-$limit,
			'source'=>$source,
			'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		];
		return $root;
	}

	private function mcp_app_builder_compact_recipe_item(array $item, string $source): array {
		foreach(['edit_tasks', 'verification_tools', 'relationship_adapters'] as $key){
			if(!is_array($item[$key] ?? null)){
				continue;
			}
			$total=count($item[$key]);
			$limit=$key==='edit_tasks' ? 4 : 3;
			if($total>$limit){
				$item[$key]=array_slice($item[$key], 0, $limit);
				$item[$key.'_pagination']=[
					'total'=>$total,
					'shown'=>$limit,
					'omitted_count'=>$total-$limit,
					'source'=>$source.'.'.$key,
					'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				];
			}
		}
		return $item;
	}

	private function mcp_app_builder_compact_nested_lists(array $value, int $limit, string $source): array {
		if(array_is_list($value)){
			foreach($value as $index=>$item){
				if(is_array($item)){
					$value[$index]=$this->mcp_app_builder_compact_nested_lists($item, $limit, $source.'['.(string)$index.']');
				}
			}
			return $value;
		}
		foreach($value as $key=>$item){
			if(!is_array($item)){
				continue;
			}
			$item_source=$source.'.'.(string)$key;
			if(array_is_list($item)){
				$total=count($item);
				$shown=$item;
				if($total>$limit){
					$shown=array_slice($item, 0, $limit);
					$value[(string)$key.'_pagination']=[
						'total'=>$total,
						'shown'=>$limit,
						'omitted_count'=>$total-$limit,
						'source'=>$item_source,
						'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
					];
				}
				foreach($shown as $index=>$child){
					if(is_array($child)){
						$shown[$index]=$this->mcp_app_builder_compact_nested_lists($child, $limit, $item_source.'['.(string)$index.']');
					}
				}
				$value[$key]=$shown;
				continue;
			}
			$value[$key]=$this->mcp_app_builder_compact_nested_lists($item, $limit, $item_source);
		}
		return $value;
	}

	/**
	 * Selects focused docs for the current app-builder surface.
	 *
	 * @param array<string,mixed> $lane App-builder lane payload.
	 * @return array<int,string> Repo-local documentation paths.
	 */
	private function app_builder_focused_docs(array $lane): array {
		$docs=[
			'common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
			'common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
		];
		$scaffold_type=(string)($lane['scaffold_type'] ?? '');
		if(
			$scaffold_type==='api_endpoint'
			|| $scaffold_type==='routing_controller'
			|| $scaffold_type==='mvc_controller'
			|| (is_array($lane['companion_surface_handoff'] ?? null) && $lane['companion_surface_handoff']!==[])
		){
			$docs[]='common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md';
			$docs[]='common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md';
		}
		return array_values(array_unique($docs));
	}


	/**
	 * Builds the reusable builder-plan sections shared by app-builder surfaces.
	 *
	 * @param array<string,mixed> $lane App-builder lane payload.
	 * @return array<string,mixed> Files, schema, Panel affordances, verification, and skeletons.
	 */
	private function app_builder_builder_plan(array $lane): array {
		$schemas=[];
		$panel_fields=[];
		$filters=[];
		$actions=[];
		foreach($lane['scaffold_plans'] ?? [] as $plan){
			if(!is_array($plan)){
				continue;
			}
			$entity=(string)($plan['name'] ?? '');
			$field_hints=is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : [];
			$schema_fields=$this->app_builder_schema_fields($field_hints);
			$schemas[]=[
				'entity'=>$entity,
				'table'=>str_replace('-', '_', $this->slug_name($entity)),
				'fields'=>$schema_fields,
				'relationships'=>$this->app_builder_relationships($field_hints),
			];
			$panel_field_entry=[
				'entity'=>$entity,
				'fields'=>array_map(static fn(array $field): string => (string)($field['name'] ?? ''), $schema_fields),
			];
			$panel_field_metadata=$this->app_builder_panel_field_metadata($schema_fields);
			if($panel_field_metadata!==[]){
				$panel_field_entry['field_metadata']=$panel_field_metadata;
			}
			$panel_fields[]=$panel_field_entry;
			$filter_entry=[
				'entity'=>$entity,
				'filters'=>$this->app_builder_filters($field_hints),
			];
			$filter_metadata=$this->app_builder_panel_filter_metadata($field_hints);
			if($filter_metadata!==[]){
				$filter_entry['filter_metadata']=$filter_metadata;
			}
			$filters[]=$filter_entry;
			$actions[]=[
				'entity'=>$entity,
				'actions'=>['create', 'edit', 'duplicate', 'archive_or_delete'],
			];
		}
		$app_contract_summary=$this->app_builder_app_contract_summary($schemas, (string)($lane['task'] ?? ''));
		$data_sensitivity_summary=$this->app_builder_data_sensitivity_summary($this->app_builder_sensitivity_schemas($schemas, is_array($lane['entity_planning'] ?? null) ? $lane['entity_planning'] : []));
		$policy_decision_register=$this->app_builder_policy_decision_register($app_contract_summary, $data_sensitivity_summary);
		$relationship_contract_summary=$this->app_builder_relationship_contract_summary($schemas, is_array($lane['entity_planning'] ?? null) ? $lane['entity_planning'] : []);
		$field_metadata_summary=$this->app_builder_field_metadata_summary($this->app_builder_field_metadata_schemas($schemas, is_array($lane['entity_planning'] ?? null) ? $lane['entity_planning'] : []));
		$data_integrity_summary=$this->app_builder_data_integrity_summary($schemas, $schemas);
		$lifecycle_policy_summary=$this->app_builder_lifecycle_policy_summary($schemas);
		$lifecycle_state_handoff=$this->app_builder_lifecycle_state_handoff($schemas, $lifecycle_policy_summary);
		$audit_retention_summary=$this->app_builder_audit_retention_summary($schemas);
		$audit_retention_handoff=$this->app_builder_audit_retention_handoff($audit_retention_summary);
		$access_control_summary=$this->app_builder_access_control_summary($schemas, $schemas);
		$access_control_handoff=$this->app_builder_access_control_handoff($access_control_summary);
		$operational_reliability_summary=$this->app_builder_operational_reliability_summary($schemas, (string)($lane['task'] ?? ''));
		$operational_reliability_handoff=$this->app_builder_operational_reliability_handoff($operational_reliability_summary);
		$support_observability_summary=$this->app_builder_support_observability_summary($schemas, (string)($lane['task'] ?? ''));
		$support_observability_handoff=$this->app_builder_support_observability_handoff($support_observability_summary);
		$change_management_summary=$this->app_builder_change_management_summary($schemas, (string)($lane['task'] ?? ''));
		$change_management_handoff=$this->app_builder_change_management_handoff($change_management_summary);
		$integration_boundary_summary=$this->app_builder_integration_boundary_summary($schemas, (string)($lane['task'] ?? ''));
		$integration_boundary_handoff=$this->app_builder_integration_boundary_handoff($integration_boundary_summary);
		$business_policy_summary=$this->app_builder_business_policy_summary($schemas, (string)($lane['task'] ?? ''));
		$process_policy_summary=$this->app_builder_process_policy_summary($schemas, (string)($lane['task'] ?? ''));
		$tenant_identity_handoff=$this->app_builder_tenant_identity_handoff($app_contract_summary, $access_control_summary, $business_policy_summary);
		$domain_workflow_handoff=$this->app_builder_domain_workflow_handoff($business_policy_summary, $process_policy_summary);
		$reporting_analytics_summary=$this->app_builder_reporting_analytics_summary($schemas, (string)($lane['task'] ?? ''));
		$reporting_analytics_handoff=$this->app_builder_reporting_analytics_handoff($reporting_analytics_summary);
		$notification_communication_summary=$this->app_builder_notification_communication_summary($schemas, (string)($lane['task'] ?? ''));
		$notification_communication_handoff=$this->app_builder_notification_communication_handoff($notification_communication_summary);
		$endpoint_policy_metadata=$this->app_builder_endpoint_policy_metadata(is_array($lane['scaffold_plans'] ?? null) ? $lane['scaffold_plans'] : []);
		$code_skeleton_summary=$this->app_builder_code_skeleton_summary(is_array($lane['code_skeletons'] ?? null) ? $lane['code_skeletons'] : []);
		$relationship_adapter_handoff=$this->app_builder_relationship_adapter_handoff($relationship_contract_summary, $code_skeleton_summary);
		$implementation_sequence=$this->app_builder_implementation_sequence($lane['files_to_create'] ?? [], $schemas);
		$local_convention_probe=$this->app_builder_local_convention_probe($code_skeleton_summary, is_array($lane['app_path_context'] ?? null) ? $lane['app_path_context'] : $this->app_builder_path_context([]));
		$write_plan_summary=$this->app_builder_write_plan_summary($lane['files_to_create'] ?? [], $lane['data_model'] ?? [], $implementation_sequence, $code_skeleton_summary, $lane['verification_plan'] ?? [], $local_convention_probe);
		$scaffold_completion_summary=$this->app_builder_scaffold_completion_summary(is_array($lane['entity_planning'] ?? null) ? $lane['entity_planning'] : []);
		$surface_execution_plan=$this->app_builder_surface_execution_plan((string)($lane['scaffold_type'] ?? ''), $scaffold_completion_summary, is_array($lane['companion_surface_handoff'] ?? null) ? $lane['companion_surface_handoff'] : []);
		$prewrite_checklist=$this->app_builder_prewrite_checklist($lane['entity_input_contract'] ?? [], $lane['entity_planning'] ?? [], $app_contract_summary, $data_sensitivity_summary, $relationship_contract_summary, $field_metadata_summary, $lane['app_path_context'] ?? $this->app_builder_path_context([]), $write_plan_summary, $lane['verification_plan'] ?? [], is_array($lane['sensitivity_policy'] ?? null) ? $lane['sensitivity_policy'] : []);
		$write_readiness=$this->app_builder_write_readiness($scaffold_completion_summary, $prewrite_checklist);
		$verification_recovery_plan=$this->app_builder_verification_recovery_plan(is_array($lane['verification_plan'] ?? null) ? $lane['verification_plan'] : []);
		$implementation_matrix=$this->app_builder_implementation_matrix($prewrite_checklist, $write_plan_summary, is_array($lane['verification_plan'] ?? null) ? $lane['verification_plan'] : [], $code_skeleton_summary, $app_contract_summary, $policy_decision_register, $relationship_contract_summary, $field_metadata_summary, $data_integrity_summary, $data_sensitivity_summary, $tenant_identity_handoff, [
			'lifecycle_policy_summary'=>$lifecycle_policy_summary,
			'audit_retention_summary'=>$audit_retention_summary,
			'access_control_summary'=>$access_control_summary,
			'operational_reliability_summary'=>$operational_reliability_summary,
			'support_observability_summary'=>$support_observability_summary,
			'change_management_summary'=>$change_management_summary,
			'integration_boundary_summary'=>$integration_boundary_summary,
			'tenant_identity_handoff'=>$tenant_identity_handoff,
			'business_policy_summary'=>$business_policy_summary,
			'process_policy_summary'=>$process_policy_summary,
			'reporting_analytics_summary'=>$reporting_analytics_summary,
			'notification_communication_summary'=>$notification_communication_summary,
		]);
		$implementation_recipe=$this->app_builder_implementation_recipe(is_array($lane['code_skeletons'] ?? null) ? $lane['code_skeletons'] : [], $implementation_matrix, $relationship_adapter_handoff, $verification_recovery_plan);
		$verification_execution_plan=$this->app_builder_verification_execution_plan(is_array($lane['verification_plan'] ?? null) ? $lane['verification_plan'] : [], $implementation_recipe, $verification_recovery_plan);
		$verification_fixture_handoff=$this->app_builder_verification_fixture_handoff($schemas, $relationship_contract_summary, $data_sensitivity_summary, $lifecycle_policy_summary, $tenant_identity_handoff);
		$acceptance_criteria=is_array($lane['acceptance_criteria'] ?? null) ? $lane['acceptance_criteria'] : [];
		$acceptance_review_plan=$this->app_builder_acceptance_review_plan($acceptance_criteria, $implementation_recipe, $verification_execution_plan, $write_readiness);
		$builder_plan=[
			'scaffold_type'=>$lane['scaffold_type'] ?? '',
			'scaffold_plans'=>$lane['scaffold_plans'] ?? [],
			'files'=>$lane['files_to_create'] ?? [],
			'app_path_context'=>$lane['app_path_context'] ?? $this->app_builder_path_context([]),
			'schema'=>$schemas,
			'naming_contract'=>$this->app_builder_naming_contract($schemas, is_array($lane['entity_input_contract'] ?? null) ? $lane['entity_input_contract'] : []),
			'entity_input_contract'=>$lane['entity_input_contract'] ?? [],
			'entity_planning'=>$lane['entity_planning'] ?? [],
			'scaffold_completion_summary'=>$scaffold_completion_summary,
			'surface_execution_plan'=>$surface_execution_plan,
			'companion_surface_handoff'=>$lane['companion_surface_handoff'] ?? [],
			'endpoint_policy_metadata'=>$endpoint_policy_metadata,
			'data_sensitivity_summary'=>$data_sensitivity_summary,
			'policy_decision_register'=>$policy_decision_register,
			'relationship_contract_summary'=>$relationship_contract_summary,
			'relationship_adapter_handoff'=>$relationship_adapter_handoff,
			'field_metadata_summary'=>$field_metadata_summary,
			'data_integrity_summary'=>$data_integrity_summary,
			'lifecycle_policy_summary'=>$lifecycle_policy_summary,
			'lifecycle_state_handoff'=>$lifecycle_state_handoff,
			'audit_retention_summary'=>$audit_retention_summary,
			'audit_retention_handoff'=>$audit_retention_handoff,
			'access_control_summary'=>$access_control_summary,
			'access_control_handoff'=>$access_control_handoff,
			'operational_reliability_summary'=>$operational_reliability_summary,
			'operational_reliability_handoff'=>$operational_reliability_handoff,
			'support_observability_summary'=>$support_observability_summary,
			'support_observability_handoff'=>$support_observability_handoff,
			'change_management_summary'=>$change_management_summary,
			'change_management_handoff'=>$change_management_handoff,
			'integration_boundary_summary'=>$integration_boundary_summary,
			'integration_boundary_handoff'=>$integration_boundary_handoff,
			'tenant_identity_handoff'=>$tenant_identity_handoff,
			'business_policy_summary'=>$business_policy_summary,
			'process_policy_summary'=>$process_policy_summary,
			'domain_workflow_handoff'=>$domain_workflow_handoff,
			'reporting_analytics_summary'=>$reporting_analytics_summary,
			'reporting_analytics_handoff'=>$reporting_analytics_handoff,
			'notification_communication_summary'=>$notification_communication_summary,
			'notification_communication_handoff'=>$notification_communication_handoff,
			'data_model'=>$lane['data_model'] ?? [],
			'panel_fields'=>$panel_fields,
			'filters'=>$filters,
			'actions'=>$actions,
			'verification'=>$lane['verification'] ?? [],
			'verification_plan'=>$lane['verification_plan'] ?? [],
			'diagnostic_handoff_hint'=>$this->app_builder_diagnostic_handoff_hint(is_array($lane['verification_plan'] ?? null) ? $lane['verification_plan'] : []),
			'verification_recovery_plan'=>$verification_recovery_plan,
			'verification_execution_plan'=>$verification_execution_plan,
			'verification_fixture_handoff'=>$verification_fixture_handoff,
			'acceptance_criteria'=>$acceptance_criteria,
			'acceptance_review_plan'=>$acceptance_review_plan,
			'app_contract_summary'=>$app_contract_summary,
			'enterprise_app_notes'=>$this->app_builder_enterprise_app_notes($schemas),
			'extension_boundary_summary'=>$lane['extension_boundary_summary'] ?? $this->app_builder_extension_boundary_summary(),
			'governance_lane'=>$lane['governance_lane'] ?? [
				'collapsed_by_default'=>true,
				'open_when'=>$this->mcp_escalation_triggers(),
			],
			'extension_decision_ladder'=>$this->planning_extension_decision_ladder('app_builder_plan'),
			'implementation_sequence'=>$implementation_sequence,
			'local_convention_probe'=>$local_convention_probe,
			'next_edits'=>$lane['next_edits'] ?? [],
			'code_skeletons'=>$lane['code_skeletons'] ?? [],
			'code_skeleton_summary'=>$code_skeleton_summary,
			'write_plan_summary'=>$write_plan_summary,
			'implementation_matrix'=>$implementation_matrix,
			'implementation_recipe'=>$implementation_recipe,
			'prewrite_checklist'=>$prewrite_checklist,
			'write_readiness'=>$write_readiness,
		];
		$builder_plan['write_handoff']=$this->app_builder_write_handoff($builder_plan);
		return $builder_plan;
	}

	/**
	 * Extracts the first API endpoint policy metadata block from scaffold plans.
	 *
	 * @param array<int,mixed> $plans Scaffold plans.
	 * @return array<string,mixed> Endpoint policy metadata or empty array.
	 */
	private function app_builder_endpoint_policy_metadata(array $plans): array {
		foreach($plans as $plan){
			if(!is_array($plan) || ($plan['type'] ?? null)!=='api_endpoint'){
				continue;
			}
			return is_array($plan['endpoint_policy_metadata'] ?? null) ? $plan['endpoint_policy_metadata'] : [];
		}
		return [];
	}

	/**
	 * Builds a compact naming map so agents preserve Dataphyre app-builder conventions.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Entity-to-artifact naming contract.
	 */
	private function app_builder_naming_contract(array $schemas, array $entity_input_contract=[]): array {
		$mappings=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity===''){
				continue;
			}
			$table=(string)($schema['table'] ?? str_replace('-', '_', $this->slug_name($entity)));
			$class_base=$this->studly_name($entity);
			$mappings[]=[
				'entity'=>$entity,
				'class_base'=>$class_base,
				'table'=>$table,
				'panel_resource'=>$class_base.'Resource',
				'table_schema'=>$class_base.'TableSchema',
				'repository'=>$class_base.'Repository',
				'record'=>$class_base.'Record',
				'panel_manifest'=>$table.'.php',
				'panel_regression_manifest'=>'panel.'.$table.'.json',
			];
		}
		$normalizations=[];
		foreach(is_array($entity_input_contract['normalized_from_explicit'] ?? null) ? $entity_input_contract['normalized_from_explicit'] : [] as $raw=>$normalized){
			if((string)$raw!=='' && (string)$raw!==(string)$normalized){
				$normalizations[(string)$raw]=(string)$normalized;
			}
		}
		$contract=[
			'owner'=>'consuming_application',
			'purpose'=>'Preserve generated artifact names during app-owned adaptation without opening governance context.',
			'class_names'=>'Preserve PascalCase compound entity names and common enterprise acronyms in class-like names.',
			'paths_and_tables'=>'Use snake_case for tables, Panel manifest paths, and route-free regression manifest names.',
			'mappings'=>$mappings,
			'do_not_collapse_examples'=>['PurchaseRequest to Purchaserequest', 'SSOProvider to Ssoprovider', 'APIKey to Apikey'],
		];
		if($normalizations!==[]){
			$contract['normalization_notes']=[
				'php_reserved_entities'=>'Raw entity names that would generate invalid PHP class names are normalized before planning app-owned artifacts.',
				'normalized_from_explicit'=>$normalizations,
				'policy'=>'Use normalized entity names in app-owned Resource, TableSchema, Repository, Record, manifest, test, and continuation files unless the user chooses a different safe domain name.',
			];
		}
		return $contract;
	}

	/**
	 * Summarizes option/default field metadata that app agents must preserve.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Lightweight field metadata summary.
	 */
	private function app_builder_field_metadata_summary(array $schemas): array {
		$fields=[];
		$entities=[];
		$has_bounded_options=false;
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$options=$this->app_builder_field_options($field);
				$has_default=array_key_exists('default', $field);
				if($options===[] && !$has_default){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$row=[
					'entity'=>$entity,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'form_control'=>$this->app_builder_panel_type_for_field($field, 'field'),
				];
				if($options!==[]){
					$row['options']=$options;
					$has_bounded_options=true;
				}
				if($has_default){
					$row['default']=$this->app_builder_scalar_default($field['default']);
				}
				$fields[]=$row;
				if($entity!==''){
					$entities[$entity]=true;
				}
			}
		}
		return [
			'owner'=>'consuming_application',
			'has_field_metadata'=>$fields!==[],
			'has_bounded_options'=>$has_bounded_options,
			'field_count'=>count($fields),
			'entities'=>array_keys($entities),
			'fields'=>$fields,
			'policy'=>$fields===[] ? 'No bounded options/default field metadata was supplied.' : 'Preserve field options/defaults in app-owned schema, validation, Panel select controls, filters, and focused tests.',
			'not_required'=>[
				'Dataphyre runtime-internal edits for one app-specific option set',
				'framework/release escalation for ordinary app field metadata',
			],
		];
	}

	/**
	 * Adds deferred continuation fields to the metadata-only schema scope.
	 *
	 * Large app scaffolds intentionally keep concrete schema, write plans, and
	 * skeleton previews chunked. Field metadata is a lightweight obligation
	 * summary, so it can include explicit continuation fields without expanding
	 * the current chunk's write surface.
	 *
	 * @param array<int,array<string,mixed>> $schemas Current planned-chunk schemas.
	 * @param array<string,mixed> $entity_planning Chunking and continuation metadata.
	 * @return array<int,array<string,mixed>> Schemas for field-metadata summarization.
	 */
	private function app_builder_field_metadata_schemas(array $schemas, array $entity_planning): array {
		$metadata_schemas=$schemas;
		$seen=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($entity!=='' && $name!==''){
					$seen[$this->app_builder_entity_key($entity).':'.$name]=true;
				}
			}
		}
		foreach(is_array($entity_planning['continuation_calls'] ?? null) ? $entity_planning['continuation_calls'] : [] as $call){
			if(!is_array($call)){
				continue;
			}
			$arguments=is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
			$fields_by_entity=is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];
			foreach($fields_by_entity as $entity=>$fields){
				$entity=(string)$entity;
				if($entity==='' || !is_array($fields)){
					continue;
				}
				$schema_fields=[];
				foreach($this->app_builder_schema_fields($this->field_hints($fields)) as $field){
					$name=(string)($field['name'] ?? '');
					if($name===''){
						continue;
					}
					$key=$this->app_builder_entity_key($entity).':'.$name;
					if(isset($seen[$key])){
						continue;
					}
					$seen[$key]=true;
					$schema_fields[]=$field;
				}
				if($schema_fields===[]){
					continue;
				}
				$metadata_schemas[]=[
					'entity'=>$entity,
					'table'=>str_replace('-', '_', $this->slug_name($entity)),
					'fields'=>$schema_fields,
					'relationships'=>[],
					'metadata_scope'=>'continuation_call',
				];
			}
		}
		return $metadata_schemas;
	}
}
