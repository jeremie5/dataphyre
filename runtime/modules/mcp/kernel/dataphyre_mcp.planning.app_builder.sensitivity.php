<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP app-builder sensitivity and app-owned policy decision helpers.
 */
trait dataphyre_mcp_planning_app_builder_sensitivity_surfaces {
/**
	 * Adds deferred continuation entities and fields to the sensitivity-only scope.
	 *
	 * This keeps large scaffold writes chunked while still warning app agents
	 * about billing, tenant, audit, usage, webhook, and credential-shaped
	 * resources before they start writing the first chunk.
	 *
	 * @param array<int,array<string,mixed>> $schemas Current planned-chunk schemas.
	 * @param array<string,mixed> $entity_planning Chunking and continuation metadata.
	 * @return array<int,array<string,mixed>> Schemas for lightweight sensitivity summarization.
	 */
	private function app_builder_sensitivity_schemas(array $schemas, array $entity_planning): array {
		$sensitivity_schemas=$schemas;
		$seen=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$seen[$this->app_builder_entity_key($entity)]=true;
			}
		}
		foreach(is_array($entity_planning['continuation_calls'] ?? null) ? $entity_planning['continuation_calls'] : [] as $call){
			if(!is_array($call)){
				continue;
			}
			$arguments=is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
			$fields_by_entity=is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];
			foreach(array_map('strval', is_array($arguments['entities'] ?? null) ? $arguments['entities'] : []) as $entity){
				if($entity===''){
					continue;
				}
				$key=$this->app_builder_entity_key($entity);
				if(isset($seen[$key])){
					continue;
				}
				$seen[$key]=true;
				$sensitivity_schemas[]=[
					'entity'=>$entity,
					'table'=>str_replace('-', '_', $this->slug_name($entity)),
					'fields'=>$this->app_builder_schema_fields($this->field_hints(is_array($fields_by_entity[$entity] ?? null) ? $fields_by_entity[$entity] : [])),
					'relationships'=>[],
					'metadata_scope'=>'continuation_call',
				];
			}
		}
		return $sensitivity_schemas;
	}

	/**
	 * Summarizes likely data-sensitivity signals from planned app schema fields.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Lightweight data sensitivity summary.
	 */
	private function app_builder_data_sensitivity_summary(array $schemas): array {
		$rules=$this->app_builder_sensitivity_rules();
		$entity_rules=$this->app_builder_entity_sensitivity_rules();
		$signals=[];
		$categories=[];
		$seen_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_scope=strtolower($entity.' '.$table.' '.str_replace('_', ' ', $table));
			foreach($entity_rules as $category=>$needles){
				foreach($needles as $needle){
					if($entity_scope!=='' && str_contains($entity_scope, $needle)){
						$key=$category.':entity:'.$this->app_builder_entity_key($entity);
						if(!isset($seen_signals[$key])){
							$seen_signals[$key]=true;
							$categories[$category]=true;
							$signals[]=[
								'entity'=>$entity,
								'scope'=>'entity',
								'category'=>$category,
								'action'=>$this->app_builder_sensitive_field_action($category),
							] + $this->app_builder_sensitive_category_policy($category);
						}
						break 2;
					}
				}
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				$normalized=strtolower($name);
				foreach($rules as $category=>$needles){
					foreach($needles as $needle){
						if($normalized===$needle || str_contains($normalized, $needle)){
							if($this->app_builder_sensitivity_field_match_is_contextual_status($category, $needle, $entity, $name)){
								continue;
							}
							$key=$category.':field:'.$this->app_builder_entity_key($entity).':'.$name;
							if(isset($seen_signals[$key])){
								break 2;
							}
							$seen_signals[$key]=true;
							$categories[$category]=true;
							$signals[]=[
								'entity'=>$entity,
								'field'=>$name,
								'scope'=>'field',
								'category'=>$category,
								'action'=>$this->app_builder_sensitive_field_action($category),
							] + $this->app_builder_sensitive_category_policy($category);
							break 2;
						}
					}
				}
			}
		}
		$category_list=array_keys($categories);
		$category_policies=$this->app_builder_sensitive_category_policies($category_list);
		$policy_metadata=$this->app_builder_sensitive_policy_metadata($category_list, $category_policies, false);
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Schema-derived sensitivity hints for app-owned access, redaction, storage, and verification decisions; not a governance gate by itself.',
			'has_sensitive_signals'=>$signals!==[],
			'categories'=>array_values($category_list),
			'signals'=>$signals,
			'recommended_actions'=>$this->app_builder_sensitive_recommended_actions($category_list),
			'category_policies'=>$category_policies,
			'policy_metadata'=>$policy_metadata,
			'default_action'=>$signals===[] ? 'No sensitive field or entity names were inferred; continue with ordinary app-owned prewrite and focused verification.' : 'Decide app-owned access policy, redaction, storage, validation, and focused checks for these fields or entities before writing files.',
			'escalation_hint'=>'Open enterprise/governance review only when the task is corporate-ready, security/privacy/compliance-sensitive, release-facing, or claims reusable Dataphyre framework behavior.',
			'not_required'=>[
				'MCP/release-surface publication validation for ordinary app sensitivity hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a compact register of app-owned policy decisions before writes.
	 *
	 * @param array<string,mixed> $app_contract_summary App contract summary.
	 * @param array<string,mixed> $data_sensitivity_summary Data sensitivity summary.
	 * @return array<string,mixed> Policy decision register.
	 */
	private function app_builder_policy_decision_register(array $app_contract_summary, array $data_sensitivity_summary): array {
		$decisions=[];
		foreach(is_array($app_contract_summary['decision_prompts'] ?? null) ? $app_contract_summary['decision_prompts'] : [] as $prompt){
			if(!is_array($prompt)){
				continue;
			}
			$id=(string)($prompt['id'] ?? '');
			if($id===''){
				continue;
			}
			$status=(string)($prompt['status'] ?? 'needs_app_decision');
			$decisions[]=[
				'id'=>$id,
				'source'=>'app_contract_summary.decision_prompts',
				'status'=>$status,
			'required_before_write'=>in_array($status, ['needs_app_decision', 'needs_adapter_mapping', 'needs_app_owned_design'], true),
				'action'=>(string)($prompt['prompt'] ?? ''),
			];
		}
		if(($data_sensitivity_summary['has_sensitive_signals'] ?? false)===true){
			$policy_metadata=is_array($data_sensitivity_summary['policy_metadata'] ?? null) ? $data_sensitivity_summary['policy_metadata'] : [];
			$decisions[]=[
				'id'=>'sensitive_data_policy',
				'source'=>'data_sensitivity_summary',
				'status'=>'needs_app_decision',
				'required_before_write'=>true,
				'categories'=>array_values(array_map('strval', is_array($data_sensitivity_summary['categories'] ?? null) ? $data_sensitivity_summary['categories'] : [])),
				'policy_metadata'=>$policy_metadata,
				'action'=>(string)($data_sensitivity_summary['default_action'] ?? 'Decide app-owned access, redaction, storage, validation, and focused checks before writing files.'),
			];
		}
		$required=array_values(array_filter($decisions, static fn(array $decision): bool => ($decision['required_before_write'] ?? false)===true));
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Compact app-owned policy decisions to resolve before writing broad app scaffolds; this is not an enterprise audit gate.',
			'status'=>$required===[] ? 'no_required_policy_decisions_detected' : 'requires_app_policy_decisions',
			'required_count'=>count($required),
			'decisions'=>$decisions,
			'prewrite_check'=>'prewrite_checklist.checks.app_contract_decisions',
			'not_required'=>[
				'enterprise audit for ordinary app-owned policy decisions',
				'MCP/release-surface publication validation',
				'Dataphyre hot-path benchmark evidence',
			],
			'escalate_only_for'=>'Corporate-ready, security/privacy/compliance-sensitive, release-facing, reusable Dataphyre framework, or shared production hot-path claims.',
		];
	}

	/**
	 * Returns ordered field-name sensitivity match rules.
	 *
	 * @return array<string,array<int,string>> Category => field-name fragments.
	 */
	private function app_builder_sensitivity_rules(): array {
		return [
			'identity_or_contact'=>['email', 'phone', 'mobile', 'address', 'postal', 'zip', 'dob', 'birth', 'ip_address', 'user_agent'],
			'billing_or_financial'=>['card', 'payment', 'billing', 'bank', 'iban', 'routing_number', 'tax_id', 'vat', 'stripe', 'invoice', 'plan_id', 'subscription_id', 'entitlement_id', 'amount_cents', 'price_cents', 'total_cents'],
			'credentials_or_secrets'=>['password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'auth_token', 'cookie', 'private_key', 'totp', 'otp', 'passkey', 'credential'],
			'tenant_or_access_scope'=>['tenant_id', 'workspace_id', 'organization_id', 'org_id', 'team_id', 'account_id', 'customer_id', 'store_id', 'role_id', 'permission_id'],
			'regulated_personal_data'=>['ssn', 'sin', 'national_id', 'passport', 'health', 'medical', 'hipaa', 'gdpr'],
			'retention_or_records'=>['retention_until', 'retained_until', 'retain_until', 'legal_hold', 'legal_hold_until', 'records_hold', 'purge_after', 'delete_after'],
			'approval_or_audit'=>['approved_by', 'approved_at', 'approver_id', 'reviewed_by', 'reviewed_at', 'effective_at', 'expires_at', 'expired_at', 'actor_id'],
			'data_residency_or_export'=>['data_region', 'region_code', 'residency_region', 'classification', 'data_classification', 'exported_at', 'exported_by', 'export_batch_id'],
		];
	}

	/**
	 * Filters operational status wording that overlaps regulated-data keywords.
	 *
	 * @param string $category Matched sensitivity category.
	 * @param string $needle Matched keyword.
	 * @param string $entity Current entity name.
	 * @param string $field Current field name.
	 * @return bool True when the match is domain status, not sensitive data.
	 */
	private function app_builder_sensitivity_field_match_is_contextual_status(string $category, string $needle, string $entity, string $field): bool {
		if($category!=='regulated_personal_data' || $needle!=='health'){
			return false;
		}
		$field_key=strtolower($field);
		if(!in_array($field_key, ['health_status', 'service_health_status'], true)){
			return false;
		}
		return in_array($this->app_builder_entity_key($entity), ['project', 'program', 'milestone', 'servicehealth', 'healthscore'], true);
	}

	/**
	 * Returns ordered entity/table-name sensitivity match rules.
	 *
	 * @return array<string,array<int,string>> Category => entity/table fragments.
	 */
	private function app_builder_entity_sensitivity_rules(): array {
		return [
			'billing_or_financial'=>['invoice', 'payment', 'subscription', 'billing', 'entitlement'],
			'credentials_or_secrets'=>['webhook', 'api key', 'apikey', 'sso', 'totp', 'secret'],
			'tenant_or_access_scope'=>['organization', 'workspace', 'tenant', 'seat', 'usage', 'audit'],
			'regulated_personal_data'=>['compliance', 'legal hold', 'retention'],
			'retention_or_records'=>['retention', 'record hold', 'legal hold'],
			'approval_or_audit'=>['approval', 'audit', 'review'],
			'data_residency_or_export'=>['export', 'data residency', 'classification'],
		];
	}

	/**
	 * Returns compact app-owned handling guidance for one sensitivity category.
	 *
	 * @param string $category Sensitivity category.
	 * @return string App-owned handling action.
	 */
	private function app_builder_sensitive_field_action(string $category): string {
		return match($category){
			'credentials_or_secrets'=>'omit_from_table_output_and_make_write_only_or_adapter_resolved',
			'billing_or_financial'=>'mask_or_permission_gate_and_verify_export_redaction',
			'identity_or_contact'=>'permission_gate_search_and_redact_in_shared_views',
			'tenant_or_access_scope'=>'enforce_scope_in_app_repository_policy_and_regression_checks',
			'regulated_personal_data'=>'require_explicit_retention_access_and_redaction_policy',
			'retention_or_records'=>'define_app_owned_retention_hold_and_purge_policy',
			'approval_or_audit'=>'verify_app_owned_approval_audit_and_effective_date_policy',
			'data_residency_or_export'=>'permission_gate_exports_and_verify_region_or_classification_policy',
			default=>'decide_app_owned_access_redaction_storage_and_verification',
		};
	}

	/**
	 * Builds category-level handling guidance for detected sensitivity categories.
	 *
	 * @param array<int,string> $categories Detected sensitivity categories.
	 * @return array<string,string> Category => app-owned handling action.
	 */
	private function app_builder_sensitive_recommended_actions(array $categories): array {
		$actions=[];
		foreach($categories as $category){
			$category=(string)$category;
			if($category!==''){
				$actions[$category]=$this->app_builder_sensitive_field_action($category);
			}
		}
		return $actions;
	}

	/**
	 * Builds structured app-owned policy metadata for one sensitivity category.
	 *
	 * @param string $category Sensitivity category.
	 * @return array<string,string> Machine-readable policy metadata.
	 */
	private function app_builder_sensitive_category_policy(string $category): array {
		return match($category){
			'credentials_or_secrets'=>[
				'sensitivity_level'=>'critical',
				'default_exposure'=>'write_only_or_adapter_resolved',
				'redaction_default'=>'omit',
				'storage_policy'=>'hash_or_encrypted_reference_only',
				'verification_focus'=>'not_in_table_output_or_search_indexes',
			],
			'billing_or_financial'=>[
				'sensitivity_level'=>'high',
				'default_exposure'=>'permission_gated',
				'redaction_default'=>'mask',
				'storage_policy'=>'app_owned_retention_and_export_policy',
				'verification_focus'=>'export_redaction_and_permission_checks',
			],
			'identity_or_contact'=>[
				'sensitivity_level'=>'high',
				'default_exposure'=>'permission_gated',
				'redaction_default'=>'redact_in_shared_views',
				'storage_policy'=>'app_owned_minimization_and_access_policy',
				'verification_focus'=>'shared_view_redaction_and_search_permissions',
			],
			'tenant_or_access_scope'=>[
				'sensitivity_level'=>'high',
				'default_exposure'=>'scoped_internal',
				'redaction_default'=>'do_not_treat_as_public',
				'storage_policy'=>'repository_scope_enforced',
				'verification_focus'=>'tenant_scope_and_cross_tenant_negative_checks',
			],
			'regulated_personal_data'=>[
				'sensitivity_level'=>'critical',
				'default_exposure'=>'explicit_policy_required',
				'redaction_default'=>'redact',
				'storage_policy'=>'retention_access_and_compliance_policy_required',
				'verification_focus'=>'access_redaction_retention_and_audit_checks',
			],
			'retention_or_records'=>[
				'sensitivity_level'=>'medium',
				'default_exposure'=>'policy_gated',
				'redaction_default'=>'policy_defined',
				'storage_policy'=>'retention_hold_and_purge_policy_required',
				'verification_focus'=>'hold_purge_and_expiry_checks',
			],
			'approval_or_audit'=>[
				'sensitivity_level'=>'medium',
				'default_exposure'=>'permission_gated',
				'redaction_default'=>'policy_defined',
				'storage_policy'=>'append_or_effective_date_policy_required',
				'verification_focus'=>'approval_audit_and_effective_date_checks',
			],
			'data_residency_or_export'=>[
				'sensitivity_level'=>'high',
				'default_exposure'=>'export_permission_gated',
				'redaction_default'=>'policy_defined',
				'storage_policy'=>'region_classification_and_export_policy_required',
				'verification_focus'=>'region_classification_and_export_permission_checks',
			],
			default=>[
				'sensitivity_level'=>'medium',
				'default_exposure'=>'app_policy_required',
				'redaction_default'=>'policy_defined',
				'storage_policy'=>'app_owned_storage_policy_required',
				'verification_focus'=>'access_redaction_storage_and_validation_checks',
			],
		};
	}

	/**
	 * Builds structured policy metadata for detected sensitivity categories.
	 *
	 * @param array<int,string> $categories Detected sensitivity categories.
	 * @return array<string,array<string,string>> Category => policy metadata.
	 */
	private function app_builder_sensitive_category_policies(array $categories): array {
		$policies=[];
		foreach($categories as $category){
			$category=(string)$category;
			if($category!==''){
				$policies[$category]=$this->app_builder_sensitive_category_policy($category) + [
					'action'=>$this->app_builder_sensitive_field_action($category),
				];
			}
		}
		return $policies;
	}

	/**
	 * Builds a compact copy-forward policy matrix for detected sensitivity categories.
	 *
	 * @param array<int,string> $categories Detected sensitivity categories.
	 * @param array<string,array<string,string>> $category_policies Category policy metadata.
	 * @param bool $hard_block Whether this policy currently hard-blocks writes.
	 * @return array<string,mixed> Compact policy metadata.
	 */
	private function app_builder_sensitive_policy_metadata(array $categories, array $category_policies, bool $hard_block): array {
		$level_rank=['low'=>1, 'medium'=>2, 'high'=>3, 'critical'=>4];
		$highest='none';
		$highest_rank=0;
		$exposure_defaults=[];
		$storage_policies=[];
		$redaction_defaults=[];
		$verification_focuses=[];
		foreach($category_policies as $category=>$policy){
			if(!is_array($policy)){
				continue;
			}
			$level=(string)($policy['sensitivity_level'] ?? 'medium');
			$rank=$level_rank[$level] ?? 2;
			if($rank>$highest_rank){
				$highest=$level;
				$highest_rank=$rank;
			}
			if(isset($policy['default_exposure'])){
				$exposure_defaults[(string)$category]=(string)$policy['default_exposure'];
			}
			if(isset($policy['storage_policy'])){
				$storage_policies[(string)$category]=(string)$policy['storage_policy'];
			}
			if(isset($policy['redaction_default'])){
				$redaction_defaults[(string)$category]=(string)$policy['redaction_default'];
			}
			if(isset($policy['verification_focus'])){
				$verification_focuses[(string)$category]=(string)$policy['verification_focus'];
			}
		}
		return [
			'owner'=>'consuming_application',
			'mode'=>$hard_block ? 'elevated_prewrite_confirmation' : 'lightweight_app_owned_policy',
			'hard_block'=>$hard_block,
			'categories'=>array_values(array_map('strval', $categories)),
			'highest_sensitivity_level'=>$highest,
			'exposure_defaults'=>$exposure_defaults,
			'storage_policies'=>$storage_policies,
			'redaction_defaults'=>$redaction_defaults,
			'verification_focuses'=>$verification_focuses,
			'required_agent_decisions'=>array_values(array_filter([
				$categories===[] ? '' : 'apply_app_owned_access_redaction_storage_and_validation_policy',
				$hard_block ? 'confirm_elevated_security_or_governance_policy_before_writes' : '',
			])),
			'not_required'=>[
				'MCP/release-surface publication validation for ordinary app sensitivity policy metadata',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds skeleton-specific sensitive field guidance from field hints.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Planned field hints.
	 * @return array<string,mixed> Sensitive field guidance.
	 */
	private function app_builder_sensitive_field_policy(array $field_hints): array {
		$signals=[];
		$categories=[];
		foreach($field_hints as $hint){
			if(!is_array($hint)){
				continue;
			}
			$name=(string)($hint['name'] ?? '');
			$normalized=strtolower($name);
			foreach($this->app_builder_sensitivity_rules() as $category=>$needles){
				foreach($needles as $needle){
					if($normalized===$needle || str_contains($normalized, $needle)){
						$categories[$category]=true;
						$signals[]=[
							'field'=>$name,
							'category'=>$category,
							'action'=>$this->app_builder_sensitive_field_action($category),
						] + $this->app_builder_sensitive_category_policy($category);
						break 2;
					}
				}
			}
		}
		$category_list=array_keys($categories);
		$category_policies=$this->app_builder_sensitive_category_policies($category_list);
		return [
			'has_sensitive_fields'=>$signals!==[],
			'categories'=>array_values($category_list),
			'signals'=>$signals,
			'recommended_actions'=>$this->app_builder_sensitive_recommended_actions($category_list),
			'category_policies'=>$category_policies,
			'policy_metadata'=>$this->app_builder_sensitive_policy_metadata($category_list, $category_policies, false),
			'policy'=>$signals===[] ? 'No sensitive field names were inferred for this skeleton.' : 'Before writing this skeleton, apply the category-specific app-owned policy for sensitive, retained, exported, approval, residency, or classification fields in Panel columns/forms and regression checks.',
		];
	}
}
