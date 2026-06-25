<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP app-builder schema, skeleton, field parsing, naming, and entity inference helpers.
 */
trait dataphyre_mcp_planning_app_builder_schema_surfaces {


	/**
	 * Builds a compact Panel resource class skeleton.
	 *
	 * @param string $class Resource class stem without Resource suffix.
	 * @param string $table Table/resource key.
	 * @param string $label Human label.
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return string PHP source preview.
	 */
	private function panel_resource_code_skeleton(string $class, string $table, string $label, array $field_hints, string $namespace='App\\Panel\\Resources'): string {
		$fields=$this->app_builder_schema_fields($field_hints);
		$filters=$this->app_builder_filter_entries($field_hints);
		$columns=[];
		foreach($fields as $field){
			$name=(string)($field['name'] ?? '');
			if($this->app_builder_field_matches_sensitivity_category($name, 'credentials_or_secrets')){
				continue;
			}
			$type=$this->app_builder_panel_type($name, (string)($field['type'] ?? 'string'), 'column');
			$columns[]="\t\t\t\t\$panel->column('".$this->php_string_literal($name)."', '".$this->php_string_literal($type)."')->searchable()->sortable(),";
		}
		$form_fields=[];
		foreach($fields as $field){
			$form_fields[]=$this->app_builder_panel_field_source($field);
		}
		$filter_lines=[];
		foreach($filters as $filter){
			$filter_lines[]=$this->app_builder_panel_filter_source($filter);
		}
		$filter_block=$filter_lines!==[]
			? "\n\t\t\t->filters([\n".implode("\n", $filter_lines)."\n\t\t\t])"
			: '';
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."namespace {$namespace};\n\n"
			."use Dataphyre\\Panel\\PanelInstance;\n"
			."use Dataphyre\\Panel\\Resource;\n\n"
			."final class {$class}Resource {\n\n"
			."\tpublic static function make(PanelInstance \$panel): Resource {\n"
			."\t\treturn \$panel->resource('".$this->php_string_literal($table)."')\n"
			."\t\t\t->label('".$this->php_string_literal($label)."')\n"
			."\t\t\t->pluralLabel('".$this->php_string_literal($this->plural_label($label))."')\n"
			."\t\t\t->table('".$this->php_string_literal($table)."')\n"
			."\t\t\t->queryUsing(static fn(): array => [])\n"
			."\t\t\t->columns([\n".implode("\n", $columns)."\n\t\t\t])\n"
			."\t\t\t->fields([\n".implode("\n", $form_fields)."\n\t\t\t])"
			.$filter_block.";\n"
			."\t}\n"
			."}\n";
	}

	/**
	 * Returns the first sensitivity category matching a field name.
	 *
	 * @param string $name Field name.
	 * @return string Sensitivity category or empty string.
	 */
	private function app_builder_field_sensitivity_category(string $name): string {
		$normalized=strtolower($name);
		foreach($this->app_builder_sensitivity_rules() as $category=>$needles){
			foreach($needles as $needle){
				if($normalized===$needle || str_contains($normalized, $needle)){
					return (string)$category;
				}
			}
		}
		return '';
	}

	/**
	 * Checks whether a field name matches one sensitivity category.
	 *
	 * @param string $name Field name.
	 * @param string $category Sensitivity category.
	 * @return bool True when the category's rule fragments match.
	 */
	private function app_builder_field_matches_sensitivity_category(string $name, string $category): bool {
		$rules=$this->app_builder_sensitivity_rules();
		$needles=is_array($rules[$category] ?? null) ? $rules[$category] : [];
		$normalized=strtolower($name);
		foreach($needles as $needle){
			if($normalized===$needle || str_contains($normalized, (string)$needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a compact Panel manifest registration skeleton.
	 *
	 * @param string $class Resource class stem without Resource suffix.
	 * @return string PHP source preview.
	 */
	private function panel_manifest_code_skeleton(string $class, string $resource_namespace='App\\Panel\\Resources'): string {
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."use {$resource_namespace}\\{$class}Resource;\n\n"
			."return static function(\\Dataphyre\\Panel\\PanelInstance \$panel): void {\n"
			."\t\$panel->register({$class}Resource::make(\$panel));\n"
			."};\n";
	}

	/**
	 * Builds a compact TableSchema preview for app data-model planning.
	 *
	 * @param string $class Entity class stem.
	 * @param string $table SQL table name.
	 * @param array<int,string> $columns Schema columns.
	 * @param array<string,string> $casts Schema casts.
	 * @return string PHP source preview.
	 */
	private function table_schema_code_skeleton(string $class, string $table, array $columns, array $casts, string $framework_namespace='App\\Framework'): string {
		$column_lines=[];
		foreach($columns as $column){
			$column_lines[]="\t\t'".$this->php_string_literal($column)."',";
		}
		$cast_lines=[];
		foreach($casts as $column=>$cast){
			$cast_lines[]="\t\t'".$this->php_string_literal((string)$column)."'=>'".$this->php_string_literal((string)$cast)."',";
		}
		$casts_source=$cast_lines===[] ? '[]' : "[\n".implode("\n", $cast_lines)."\n\t]";
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."namespace {$framework_namespace}\\Schema;\n\n"
			."use Dataphyre\\Database\\TableSchema;\n\n"
			."final class {$class}TableSchema {\n\n"
			."\tprivate const COLUMNS = [\n".implode("\n", $column_lines)."\n\t];\n\n"
			."\tprivate static ?TableSchema \$schema=null;\n\n"
			."\tpublic static function schema(): TableSchema {\n"
			."\t\treturn self::\$schema ??= new TableSchema('".$this->php_string_literal($table)."', self::COLUMNS, [], 'id', ".$casts_source.");\n"
			."\t}\n"
			."}\n";
	}

	/**
	 * Builds a compact TableRepository preview for app data-model planning.
	 *
	 * @param string $class Entity class stem.
	 * @return string PHP source preview.
	 */
	private function table_repository_code_skeleton(string $class, string $framework_namespace='App\\Framework'): string {
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."namespace {$framework_namespace}\\Repository;\n\n"
			."use {$framework_namespace}\\Record\\{$class}Record;\n"
			."use {$framework_namespace}\\Schema\\{$class}TableSchema;\n"
			."use Dataphyre\\Database\\TableRepository;\n"
			."use Dataphyre\\Database\\TableSchema;\n\n"
			."final class {$class}Repository extends TableRepository {\n\n"
			."\tprotected static function table(): string {\n"
			."\t\treturn static::schema()->table();\n"
			."\t}\n\n"
			."\tprotected static function schema(): ?TableSchema {\n"
			."\t\treturn {$class}TableSchema::schema();\n"
			."\t}\n\n"
			."\tprotected static function recordClass(): ?string {\n"
			."\t\treturn {$class}Record::class;\n"
			."\t}\n"
			."}\n";
	}

	/**
	 * Builds a compact Record preview for app data-model planning.
	 *
	 * @param string $class Entity class stem.
	 * @param array<int,string> $columns Schema columns.
	 * @return string PHP source preview.
	 */
	private function table_record_code_skeleton(string $class, array $columns, string $framework_namespace='App\\Framework'): string {
		$methods=[];
		$seen=[];
		foreach($columns as $column){
			if($column==='id'){
				continue;
			}
			$method=$this->camel_name($column);
			if($method==='' || isset($seen[$method])){
				continue;
			}
			$seen[$method]=true;
			$methods[]="\tpublic function {$method}(): mixed {\n\t\treturn \$this->get('".$this->php_string_literal($column)."');\n\t}";
		}
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."namespace {$framework_namespace}\\Record;\n\n"
			."use Dataphyre\\Database\\Record;\n\n"
			."final class {$class}Record extends Record {\n"
			.($methods===[] ? '' : "\n".implode("\n\n", $methods)."\n")
			."}\n";
	}

	/**
	 * Builds a compact Panel regression JSON skeleton.
	 *
	 * @param string $resource Resource key.
	 * @return string JSON preview.
	 */
	private function panel_regression_json_skeleton(string $resource): string {
		return json_encode([
			'name'=>'panel.'.$resource,
			'resource'=>$resource,
			'checks'=>[
				'index_renders',
				'form_schema_renders',
				'table_filters_render',
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: "{}";
	}

	/**
	 * Extracts field names for compact skeletons.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,string> Field names.
	 */
	private function app_builder_field_names(array $field_hints): array {
		$fields=[];
		foreach($field_hints as $hint){
			$name=trim((string)($hint['name'] ?? ''));
			if($name!==''){
				$fields[]=$name;
			}
		}
		return array_values(array_unique($fields ?: ['id', 'name', 'status']));
	}

	/**
	 * Selects entity-specific default fields for app-builder plans.
	 *
	 * @param string $entity Entity name.
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Field definition hints.
	 */
	private function app_builder_fields_for_entity(string $entity, array $args): array {
		if(is_array($args['fields'] ?? null) && $args['fields']!==[]){
			$fields=$args['fields'];
			$entity_fields=$this->app_builder_entity_fields_input($entity, $fields);
			if(is_array($entity_fields)){
				return $entity_fields;
			}
			if(!$this->app_builder_fields_input_is_nested($fields)){
				return $fields;
			}
		}
		$key=strtolower(str_replace([' ', '_', '-'], '', $entity));
		return match($key){
			'organization'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'billing_email'=>['type'=>'string'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'tenant'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'workspace'=>$this->app_builder_default_workspace_fields($args),
			'team'=>$this->app_builder_default_team_fields($args),
			'company'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'legal_name'=>['type'=>'string'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'user'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'email'=>['type'=>'string', 'required'=>true],
				'role_id'=>['type'=>'integer', 'foreign_key_target'=>'roles'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'role'=>$this->app_builder_default_role_fields($args),
			'permissionset'=>$this->app_builder_default_permission_set_fields($args),
			'membership'=>$this->app_builder_default_membership_fields($args),
			'invitation'=>$this->app_builder_default_invitation_fields($args),
			'account'=>$this->app_builder_default_account_fields($args),
			'customer'=>$this->app_builder_default_customer_fields($args),
			'contact'=>[
				'account_id'=>['type'=>'integer', 'foreign_key_target'=>'accounts'],
				'name'=>['type'=>'string', 'required'=>true],
				'email'=>['type'=>'string'],
			],
			'subscription'=>$this->app_builder_default_subscription_fields($args),
			'subscriptionchange'=>$this->app_builder_default_subscription_change_fields($args),
			'plan'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'price_cents'=>['type'=>'integer', 'required'=>true],
				'currency'=>['type'=>'string', 'default'=>'USD'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'entitlement'=>[
				'plan_id'=>['type'=>'integer', 'foreign_key_target'=>'plans'],
				'key'=>['type'=>'string', 'required'=>true],
				'limit_value'=>['type'=>'integer'],
				'enabled'=>['type'=>'boolean', 'default'=>true],
			],
			'invoice'=>$this->app_builder_default_invoice_fields($args),
			'invoiceline'=>$this->app_builder_default_invoice_line_fields($args),
			'taxrate'=>$this->app_builder_default_tax_rate_fields($args),
			'taxexemption'=>$this->app_builder_default_tax_exemption_fields($args),
			'payment'=>$this->app_builder_default_payment_fields($args),
			'paymentdispute'=>$this->app_builder_default_payment_dispute_fields($args),
			'dunningattempt'=>$this->app_builder_default_dunning_attempt_fields($args),
			'refund'=>$this->app_builder_default_refund_fields($args),
			'creditmemo'=>$this->app_builder_default_credit_memo_fields($args),
			'journalentry'=>$this->app_builder_default_journal_entry_fields($args),
			'journalline'=>$this->app_builder_default_journal_line_fields($args),
			'revenueschedule'=>$this->app_builder_default_revenue_schedule_fields($args),
			'revenuerecognition'=>$this->app_builder_default_revenue_recognition_fields($args),
			'usagemeter'=>$this->app_builder_default_usage_meter_fields($args),
			'billingaccount'=>$this->app_builder_default_billing_account_fields($args),
			'apiproduct'=>$this->app_builder_default_api_product_fields($args),
			'apiclient'=>$this->app_builder_default_api_client_fields($args),
			'webhook'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'url'=>['type'=>'string', 'required'=>true],
				'encrypted_secret_ref'=>['type'=>'string'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'apikey'=>$this->app_builder_default_api_key_fields($args),
			'oauthapplication'=>$this->app_builder_default_oauth_application_fields($args),
			'oauthgrant'=>$this->app_builder_default_oauth_grant_fields($args),
			'ratelimitpolicy'=>$this->app_builder_default_rate_limit_policy_fields($args),
			'usageevent'=>$this->app_builder_default_usage_event_fields($args),
			'serviceaccount'=>$this->app_builder_default_service_account_fields($args),
			'accessreview'=>$this->app_builder_default_access_review_fields($args),
			'accessreviewitem'=>$this->app_builder_default_access_review_item_fields($args),
			'sessionpolicy'=>$this->app_builder_default_session_policy_fields($args),
			'credentialrotationjob'=>$this->app_builder_default_credential_rotation_job_fields($args),
			'ssoprovider'=>$this->app_builder_default_sso_provider_fields($args),
			'scimprovider'=>$this->app_builder_default_scim_provider_fields($args),
			'scimprovisioningjob'=>$this->app_builder_default_scim_provisioning_job_fields($args),
			'impersonationsession'=>$this->app_builder_default_impersonation_session_fields($args),
			'breakglassaccessgrant'=>$this->app_builder_default_break_glass_access_grant_fields($args),
			'totpdevice'=>[
				'user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'label'=>['type'=>'string', 'required'=>true],
				'encrypted_secret_ref'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'slapolicy'=>$this->app_builder_default_sla_policy_fields($args),
			'auditevent'=>$this->app_builder_default_audit_event_fields($args),
			'consentrecord'=>$this->app_builder_default_consent_record_fields($args),
			'datasubjectrequest'=>$this->app_builder_default_data_subject_request_fields($args),
			'retentionpolicy'=>$this->app_builder_default_retention_policy_fields($args),
			'legalhold'=>$this->app_builder_default_legal_hold_fields($args),
			'processingactivity'=>$this->app_builder_default_processing_activity_fields($args),
			'dataprocessingagreement'=>$this->app_builder_default_data_processing_agreement_fields($args),
			'subprocessor'=>$this->app_builder_default_subprocessor_fields($args),
			'transferimpactassessment'=>$this->app_builder_default_transfer_impact_assessment_fields($args),
			'featureflag'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'key'=>['type'=>'string', 'required'=>true],
				'rules'=>['type'=>'json', 'required'=>true],
				'enabled'=>['type'=>'boolean', 'default'=>false],
			],
			'rolloutplan'=>$this->app_builder_default_rollout_plan_fields($args),
			'migrationrun'=>$this->app_builder_default_migration_run_fields($args),
			'backfilljob'=>$this->app_builder_default_backfill_job_fields($args),
			'rollbackplan'=>$this->app_builder_default_rollback_plan_fields($args),
			'compatibilitywindow'=>$this->app_builder_default_compatibility_window_fields($args),
			'changeapproval'=>$this->app_builder_default_change_approval_fields($args),
			'onboardingcase'=>$this->app_builder_default_onboarding_case_fields($args),
			'kyccheck'=>$this->app_builder_default_kyc_check_fields($args),
			'riskreview'=>$this->app_builder_default_risk_review_fields($args),
			'supportticket'=>[
				'organization_id'=>['type'=>'integer', 'foreign_key_target'=>'organizations'],
				'subject'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'open'],
				'priority'=>['type'=>'string', 'default'=>'normal'],
			],
			'renewalopportunity'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'subscription_id'=>['type'=>'integer', 'foreign_key_target'=>'subscriptions'],
				'renewal_at'=>['type'=>'date', 'required'=>true],
				'amount_cents'=>['type'=>'integer'],
				'stage'=>['type'=>'string', 'default'=>'open'],
			],
			'healthscore'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'score'=>['type'=>'integer', 'required'=>true],
				'signal_summary'=>['type'=>'json'],
				'measured_at'=>['type'=>'datetime', 'required'=>true],
			],
			'successplan'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'goals'=>['type'=>'json', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'questionnaire'=>$this->app_builder_default_questionnaire_fields($args),
			'questionnaireresponse'=>$this->app_builder_default_questionnaire_response_fields($args),
			'meeting'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'contact_id'=>['type'=>'integer', 'foreign_key_target'=>'contacts'],
				'scheduled_at'=>['type'=>'datetime', 'required'=>true],
				'notes'=>['type'=>'text'],
			],
			'note'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'author_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'body'=>['type'=>'text', 'required'=>true],
			],
			'risk'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'severity'=>['type'=>'string', 'default'=>'medium'],
				'status'=>['type'=>'string', 'default'=>'open'],
				'mitigation_plan'=>['type'=>'text'],
			],
			'escalation'=>[
				'account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'],
				'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'severity'=>['type'=>'string', 'default'=>'high'],
				'status'=>['type'=>'string', 'default'=>'open'],
			],
			'playbook'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'trigger_rules'=>['type'=>'json', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'contract'=>$this->app_builder_default_contract_fields($args),
			'contractclause'=>$this->app_builder_default_contract_clause_fields($args),
			'contractobligation'=>$this->app_builder_default_contract_obligation_fields($args),
			'signaturerequest'=>$this->app_builder_default_signature_request_fields($args),
			'borrower'=>$this->app_builder_default_borrower_fields($args),
			'loanapplication'=>$this->app_builder_default_loan_application_fields($args),
			'loanproduct'=>$this->app_builder_default_loan_product_fields($args),
			'underwritingreview'=>$this->app_builder_default_underwriting_review_fields($args),
			'creditdecision'=>$this->app_builder_default_credit_decision_fields($args),
			'collateralitem'=>$this->app_builder_default_collateral_item_fields($args),
			'loanagreement'=>$this->app_builder_default_loan_agreement_fields($args),
			'loanaccount'=>$this->app_builder_default_loan_account_fields($args),
			'disbursement'=>$this->app_builder_default_disbursement_fields($args),
			'repaymentschedule'=>$this->app_builder_default_repayment_schedule_fields($args),
			'repayment'=>$this->app_builder_default_repayment_fields($args),
			'delinquencycase'=>$this->app_builder_default_delinquency_case_fields($args),
			'collectionaction'=>$this->app_builder_default_collection_action_fields($args),
			'study'=>$this->app_builder_default_study_fields($args),
			'studysite'=>$this->app_builder_default_study_site_fields($args),
			'participant'=>$this->app_builder_default_participant_fields($args),
			'consentform'=>$this->app_builder_default_consent_form_fields($args),
			'studyvisit'=>$this->app_builder_default_study_visit_fields($args),
			'visitprocedure'=>$this->app_builder_default_visit_procedure_fields($args),
			'labresult'=>$this->app_builder_default_lab_result_fields($args),
			'adverseevent'=>$this->app_builder_default_adverse_event_fields($args),
			'protocoldeviation'=>$this->app_builder_default_protocol_deviation_fields($args),
			'regulatorysubmission'=>$this->app_builder_default_regulatory_submission_fields($args),
			'monitorfinding'=>$this->app_builder_default_monitor_finding_fields($args),
			'portfolio'=>$this->app_builder_default_portfolio_fields($args),
			'property'=>$this->app_builder_default_property_fields($args),
			'unit'=>$this->app_builder_default_unit_fields($args),
			'tenantprofile'=>$this->app_builder_default_tenant_profile_fields($args),
			'lease'=>$this->app_builder_default_lease_fields($args),
			'leaseterm'=>$this->app_builder_default_lease_term_fields($args),
			'rentschedule'=>$this->app_builder_default_rent_schedule_fields($args),
			'rentpayment'=>$this->app_builder_default_rent_payment_fields($args),
			'securitydeposit'=>$this->app_builder_default_security_deposit_fields($args),
			'renewaloffer'=>$this->app_builder_default_renewal_offer_fields($args),
			'arrearscase'=>$this->app_builder_default_arrears_case_fields($args),
			'policyholder'=>$this->app_builder_default_policyholder_fields($args),
			'policy'=>$this->app_builder_default_policy_fields($args),
			'coverageitem'=>$this->app_builder_default_coverage_item_fields($args),
			'claim'=>$this->app_builder_default_claim_fields($args),
			'claimant'=>$this->app_builder_default_claimant_fields($args),
			'claimexposure'=>$this->app_builder_default_claim_exposure_fields($args),
			'claimreserve'=>$this->app_builder_default_claim_reserve_fields($args),
			'claimpayment'=>$this->app_builder_default_claim_payment_fields($args),
			'adjusterassignment'=>$this->app_builder_default_adjuster_assignment_fields($args),
			'claimdocument'=>$this->app_builder_default_claim_document_fields($args),
			'fraudreview'=>$this->app_builder_default_fraud_review_fields($args),
			'subrogationcase'=>$this->app_builder_default_subrogation_case_fields($args),
			'plant'=>$this->app_builder_default_plant_fields($args),
			'workcenter'=>$this->app_builder_default_work_center_fields($args),
			'equipment'=>$this->app_builder_default_equipment_fields($args),
			'billofmaterial'=>$this->app_builder_default_bill_of_material_fields($args),
			'bomcomponent'=>$this->app_builder_default_bom_component_fields($args),
			'productionorder'=>$this->app_builder_default_production_order_fields($args),
			'routingstep'=>$this->app_builder_default_routing_step_fields($args),
			'materialrequirement'=>$this->app_builder_default_material_requirement_fields($args),
			'workorderoperation'=>$this->app_builder_default_work_order_operation_fields($args),
			'qualityinspection'=>$this->app_builder_default_quality_inspection_fields($args),
			'downtimeevent'=>$this->app_builder_default_downtime_event_fields($args),
			'maintenancerequest'=>$this->app_builder_default_maintenance_request_fields($args),
			'site'=>$this->app_builder_default_site_fields($args),
			'asset'=>$this->app_builder_default_asset_fields($args),
			'workorder'=>$this->app_builder_default_work_order_fields($args),
			'technicianassignment'=>$this->app_builder_default_technician_assignment_fields($args),
			'qualityaudit'=>$this->app_builder_default_quality_audit_fields($args),
			'auditfinding'=>$this->app_builder_default_audit_finding_fields($args),
			'nonconformance'=>$this->app_builder_default_nonconformance_fields($args),
			'capaplan'=>$this->app_builder_default_capa_plan_fields($args),
			'correctiveaction'=>$this->app_builder_default_corrective_action_fields($args),
			'preventiveaction'=>$this->app_builder_default_preventive_action_fields($args),
			'deviation'=>$this->app_builder_default_deviation_fields($args),
			'inspectionchecklist'=>$this->app_builder_default_inspection_checklist_fields($args),
			'documentcontrol'=>$this->app_builder_default_document_control_fields($args),
			'inspection'=>$this->app_builder_default_inspection_fields($args),
			'inspectionitem'=>$this->app_builder_default_inspection_item_fields($args),
			'partusage'=>$this->app_builder_default_part_usage_fields($args),
			'inventoryitem'=>$this->app_builder_default_inventory_item_fields($args),
			'servicecontract'=>$this->app_builder_default_service_contract_fields($args),
			'incident'=>$this->app_builder_default_incident_fields($args),
			'servicehealth'=>$this->app_builder_default_service_health_fields($args),
			'statusupdate'=>$this->app_builder_default_status_update_fields($args),
			'diagnosticbundle'=>$this->app_builder_default_diagnostic_bundle_fields($args),
			'runbook'=>$this->app_builder_default_runbook_fields($args),
			'securityincident'=>$this->app_builder_default_security_incident_fields($args),
			'alert'=>$this->app_builder_default_alert_fields($args),
			'alertrule'=>$this->app_builder_default_alert_rule_fields($args),
			'incidentassignment'=>$this->app_builder_default_incident_assignment_fields($args),
			'incidenttimelineevent'=>$this->app_builder_default_incident_timeline_event_fields($args),
			'evidenceitem'=>$this->app_builder_default_evidence_item_fields($args),
			'containmentaction'=>$this->app_builder_default_containment_action_fields($args),
			'remediationtask'=>$this->app_builder_default_remediation_task_fields($args),
			'vulnerability'=>$this->app_builder_default_vulnerability_fields($args),
			'postmortem'=>$this->app_builder_default_postmortem_fields($args),
			'service'=>$this->app_builder_default_service_fields($args),
			'servicerequest'=>$this->app_builder_default_service_request_fields($args),
			'problemrecord'=>$this->app_builder_default_problem_record_fields($args),
			'configurationitem'=>$this->app_builder_default_configuration_item_fields($args),
			'release'=>$this->app_builder_default_release_fields($args),
			'maintenancewindow'=>$this->app_builder_default_maintenance_window_fields($args),
			'knowledgearticle'=>$this->app_builder_default_knowledge_article_fields($args),
			'changerequest'=>$this->app_builder_default_change_request_fields($args),
			'expense'=>$this->app_builder_default_expense_fields($args),
			'budget'=>$this->app_builder_default_budget_fields($args),
			'costcenter'=>[
				'department_id'=>['type'=>'integer', 'foreign_key_target'=>'departments'],
				'code'=>['type'=>'string', 'required'=>true],
				'name'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'department'=>$this->app_builder_default_department_fields($args),
			'employee'=>$this->app_builder_default_employee_fields($args),
			'employmentcontract'=>$this->app_builder_default_employment_contract_fields($args),
			'compensationplan'=>$this->app_builder_default_compensation_plan_fields($args),
			'benefitsenrollment'=>$this->app_builder_default_benefits_enrollment_fields($args),
			'ptorequest'=>$this->app_builder_default_pto_request_fields($args),
			'timesheet'=>$this->app_builder_default_timesheet_fields($args),
			'performancereview'=>$this->app_builder_default_performance_review_fields($args),
			'goal'=>$this->app_builder_default_goal_fields($args),
			'learner'=>$this->app_builder_default_learner_fields($args),
			'manager'=>$this->app_builder_default_learning_manager_fields($args),
			'course'=>$this->app_builder_default_course_fields($args),
			'module'=>$this->app_builder_default_learning_module_fields($args),
			'lesson'=>$this->app_builder_default_lesson_fields($args),
			'assignment'=>$this->app_builder_default_learning_assignment_fields($args),
			'attestation'=>$this->app_builder_default_attestation_fields($args),
			'certificate'=>$this->app_builder_default_certificate_fields($args),
			'quiz'=>$this->app_builder_default_quiz_fields($args),
			'question'=>$this->app_builder_default_question_fields($args),
			'attempt'=>$this->app_builder_default_attempt_fields($args),
			'policyacknowledgement'=>$this->app_builder_default_policy_acknowledgement_fields($args),
			'provider'=>$this->app_builder_default_provider_fields($args),
			'providerprofile'=>$this->app_builder_default_provider_profile_fields($args),
			'certification'=>$this->app_builder_default_provider_certification_fields($args),
			'credentialingapplication'=>$this->app_builder_default_credentialing_application_fields($args),
			'credentialingstep'=>$this->app_builder_default_credentialing_step_fields($args),
			'verification'=>$this->app_builder_default_provider_verification_fields($args),
			'payerenrollment'=>$this->app_builder_default_payer_enrollment_fields($args),
			'networkcontract'=>$this->app_builder_default_network_contract_fields($args),
			'facility'=>$this->app_builder_default_facility_fields($args),
			'privilege'=>$this->app_builder_default_privilege_fields($args),
			'expiration'=>$this->app_builder_default_expiration_fields($args),
			'backgroundcheck'=>$this->app_builder_default_background_check_fields($args),
			'sanctioncheck'=>$this->app_builder_default_sanction_check_fields($args),
			'committeereview'=>$this->app_builder_default_committee_review_fields($args),
			'approvaldecision'=>$this->app_builder_default_approval_decision_fields($args),
			'trainingassignment'=>$this->app_builder_default_training_assignment_fields($args),
			'complianceattestation'=>$this->app_builder_default_compliance_attestation_fields($args),
			'vendorriskassessment'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'vendor_name'=>['type'=>'string', 'required'=>true],
				'risk_score'=>['type'=>'integer', 'default'=>0],
				'status'=>['type'=>'string', 'default'=>'draft'],
			],
			'framework'=>$this->app_builder_default_framework_fields($args),
			'control'=>$this->app_builder_default_control_fields($args),
			'policyversion'=>$this->app_builder_default_policy_version_fields($args),
			'controltest'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'control_key'=>['type'=>'string', 'required'=>true],
				'result'=>['type'=>'string', 'default'=>'pending'],
				'tested_at'=>['type'=>'datetime'],
			],
			'evidencerequest'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'requested_from_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'due_at'=>['type'=>'datetime'],
				'status'=>['type'=>'string', 'default'=>'open'],
			],
			'evidenceupload'=>$this->app_builder_default_evidence_upload_fields($args),
			'evidencepackage'=>$this->app_builder_default_evidence_package_fields($args),
			'dataasset'=>$this->app_builder_default_data_asset_fields($args),
			'dataclassification'=>$this->app_builder_default_data_classification_fields($args),
			'dataowner'=>$this->app_builder_default_data_owner_fields($args),
			'datalineageedge'=>$this->app_builder_default_data_lineage_edge_fields($args),
			'dataaccessrequest'=>$this->app_builder_default_data_access_request_fields($args),
			'accessapproval'=>$this->app_builder_default_access_approval_fields($args),
			'retentionschedule'=>$this->app_builder_default_retention_schedule_fields($args),
			'policyexception'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'reason'=>['type'=>'text', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'open'],
			],
			'riskfinding'=>$this->app_builder_default_risk_finding_fields($args),
			'reviewcycle'=>$this->app_builder_default_review_cycle_fields($args),
			'remediationplan'=>[
				'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
				'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'actions'=>['type'=>'json', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'open'],
			],
			'report'=>$this->app_builder_default_report_fields($args),
			'dashboard'=>$this->app_builder_default_dashboard_fields($args),
			'reportwidget'=>$this->app_builder_default_report_widget_fields($args),
			'metricdefinition'=>$this->app_builder_default_metric_definition_fields($args),
			'reportrun'=>$this->app_builder_default_report_run_fields($args),
			'reportsubscription'=>$this->app_builder_default_report_subscription_fields($args),
			'notification'=>$this->app_builder_default_notification_fields($args),
			'notificationtemplate'=>$this->app_builder_default_notification_template_fields($args),
			'notificationchannel'=>$this->app_builder_default_notification_channel_fields($args),
			'notificationpreference'=>$this->app_builder_default_notification_preference_fields($args),
			'notificationsuppression'=>$this->app_builder_default_notification_suppression_fields($args),
			'notificationdelivery'=>$this->app_builder_default_notification_delivery_fields($args),
			'deliveryreceipt'=>$this->app_builder_default_delivery_receipt_fields($args),
			'escalationmessage'=>$this->app_builder_default_escalation_message_fields($args),
			'seat'=>[
				'subscription_id'=>['type'=>'integer', 'foreign_key_target'=>'subscriptions'],
				'user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'status'=>['type'=>'string', 'default'=>'assigned'],
			],
			'license'=>$this->app_builder_default_license_fields($args),
			'slaincident'=>[
				'sla_policy_id'=>['type'=>'integer', 'foreign_key_target'=>'sla policies'],
				'incident_id'=>['type'=>'integer', 'foreign_key_target'=>'incidents'],
				'breached_at'=>['type'=>'datetime'],
				'severity'=>['type'=>'string', 'default'=>'high'],
			],
			'connector'=>[
				'team_id'=>['type'=>'integer', 'foreign_key_target'=>'teams'],
				'name'=>['type'=>'string', 'required'=>true],
				'provider'=>['type'=>'string', 'required'=>true],
				'config'=>['type'=>'json'],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'integrationconnection'=>$this->app_builder_default_integration_connection_fields($args),
			'externalobjectmap'=>$this->app_builder_default_external_object_map_fields($args),
			'syncrun'=>$this->app_builder_default_sync_run_fields($args),
			'synccheckpoint'=>$this->app_builder_default_sync_checkpoint_fields($args),
			'importbatch'=>$this->app_builder_default_import_batch_fields($args),
			'exportjob'=>$this->app_builder_default_export_job_fields($args),
			'outboxevent'=>$this->app_builder_default_outbox_event_fields($args),
			'jobrun'=>$this->app_builder_default_job_run_fields($args),
			'scheduledjob'=>$this->app_builder_default_scheduled_job_fields($args),
			'deadletterevent'=>$this->app_builder_default_dead_letter_event_fields($args),
			'caserecord'=>$this->app_builder_default_case_record_fields($args),
			'caseparticipant'=>$this->app_builder_default_case_participant_fields($args),
			'caseassignment'=>$this->app_builder_default_case_assignment_fields($args),
			'casecomment'=>$this->app_builder_default_case_comment_fields($args),
			'casedocument'=>$this->app_builder_default_case_document_fields($args),
			'casedecision'=>$this->app_builder_default_case_decision_fields($args),
			'casesla'=>$this->app_builder_default_case_sla_fields($args),
			'caseevent'=>$this->app_builder_default_case_event_fields($args),
			'agentprofile'=>$this->app_builder_default_agent_profile_fields($args),
			'prompttemplate'=>$this->app_builder_default_prompt_template_fields($args),
			'promptversion'=>$this->app_builder_default_prompt_version_fields($args),
			'toolpermission'=>$this->app_builder_default_tool_permission_fields($args),
			'modelpolicy'=>$this->app_builder_default_model_policy_fields($args),
			'evaluationrun'=>$this->app_builder_default_evaluation_run_fields($args),
			'evaluationfinding'=>$this->app_builder_default_evaluation_finding_fields($args),
			'safetyreview'=>$this->app_builder_default_safety_review_fields($args),
			'agentincident'=>$this->app_builder_default_agent_incident_fields($args),
			'vendor'=>[
				'name'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
				'contact_email'=>['type'=>'string'],
			],
			'vendorcontact'=>$this->app_builder_default_vendor_contact_fields($args),
			'product'=>[
				'sku'=>['type'=>'string', 'required'=>true],
				'name'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string', 'default'=>'active'],
			],
			'purchaserequest'=>[
				'requester_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
				'status'=>['type'=>'string', 'default'=>'draft'],
				'needed_at'=>['type'=>'date'],
			],
			'purchaserequestline'=>[
				'purchase_request_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'purchase requests'],
				'sku'=>['type'=>'string', 'required'=>true],
				'quantity'=>['type'=>'integer', 'required'=>true],
			],
			'riskassessment'=>$this->app_builder_default_risk_assessment_fields($args),
			'approvalworkflow'=>$this->app_builder_default_approval_workflow_fields($args),
			'approvalstep'=>$this->app_builder_default_approval_step_fields($args),
			'warehouse'=>$this->app_builder_default_warehouse_fields($args),
			'stocklocation'=>$this->app_builder_default_stock_location_fields($args),
			'stocklot'=>$this->app_builder_default_stock_lot_fields($args),
			'serialnumber'=>$this->app_builder_default_serial_number_fields($args),
			'inventorytransfer'=>$this->app_builder_default_inventory_transfer_fields($args),
			'goodsreceipt'=>$this->app_builder_default_goods_receipt_fields($args),
			'picklist'=>$this->app_builder_default_pick_list_fields($args),
			'cyclecount'=>$this->app_builder_default_cycle_count_fields($args),
			'inventoryadjustment'=>$this->app_builder_default_inventory_adjustment_fields($args),
			'supplier'=>$this->app_builder_default_supplier_fields($args),
			'purchaseorder'=>$this->app_builder_default_purchase_order_fields($args),
			'shipment'=>$this->app_builder_default_shipment_fields($args),
			'document'=>$this->app_builder_default_document_fields($args),
			'trustcenterartifact'=>$this->app_builder_default_trust_center_artifact_fields($args),
			'webhookendpoint'=>$this->app_builder_default_webhook_endpoint_fields($args),
			'webhookdelivery'=>$this->app_builder_default_webhook_delivery_fields($args),
			'ticket'=>$this->app_builder_default_ticket_fields($args),
			'program'=>$this->app_builder_default_program_fields($args),
			'project'=>$this->app_builder_default_project_fields($args),
			'milestone'=>$this->app_builder_default_milestone_fields($args),
			'projecttask'=>$this->app_builder_default_project_task_fields($args),
			'projectdependency'=>$this->app_builder_default_project_dependency_fields($args),
			'projectrisk'=>$this->app_builder_default_project_risk_fields($args),
			'projectissue'=>$this->app_builder_default_project_issue_fields($args),
			'decisionlog'=>$this->app_builder_default_decision_log_fields($args),
			'stakeholder'=>$this->app_builder_default_stakeholder_fields($args),
			default=>[
				'name'=>['type'=>'string', 'required'=>true],
				'status'=>['type'=>'string'],
			],
		};
	}

	/**
	 * Builds a quick entity-key lookup from current app-builder arguments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,bool> Normalized entity key set.
	 */
	private function app_builder_entity_key_set(array $args): array {
		$entity_keys=[];
		foreach(array_map('strval', is_array($args['entities'] ?? null) ? $args['entities'] : []) as $entity){
			$entity_keys[$this->app_builder_entity_key($entity)]=true;
		}
		return $entity_keys;
	}

	/**
	 * Selects the most concrete app-owned scope field available in the scaffold.
	 *
	 * @param array<string,bool> $entity_keys Normalized entity key set.
	 * @param bool $required Whether the scope field should be required.
	 * @return array<string,array<string,mixed>> One scope field definition or none.
	 */
	private function app_builder_default_scope_field(array $entity_keys, bool $required=false): array {
		foreach([
			'workspace'=>['workspace_id', 'workspaces'],
			'account'=>['account_id', 'accounts'],
			'organization'=>['organization_id', 'organizations'],
			'company'=>['company_id', 'companies'],
			'tenant'=>['tenant_id', 'tenants'],
		] as $entity_key=>$scope){
			if(!isset($entity_keys[$entity_key])){
				continue;
			}
			$field=['type'=>'integer', 'foreign_key_target'=>$scope[1]];
			if($required){
				$field['required']=true;
			}
			return [$scope[0]=>$field];
		}
		return [];
	}

	/**
	 * Selects context-aware default fields for workspaces.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Workspace field definition hints.
	 */
	private function app_builder_default_workspace_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}elseif(isset($entity_keys['company'])){
			$fields['company_id']=['type'=>'integer', 'foreign_key_target'=>'companies'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'slug'=>['type'=>'string'],
			'region'=>['type'=>'string'],
		];
	}

	/**
	 * Selects context-aware default fields for teams.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Team field definition hints.
	 */
	private function app_builder_default_team_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for departments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Department field definition hints.
	 */
	private function app_builder_default_department_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'manager_employee_id'=>['type'=>'integer', 'foreign_key_target'=>'employees'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for employees.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Employee field definition hints.
	 */
	private function app_builder_default_employee_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['department'])){
			$fields['department_id']=['type'=>'integer', 'foreign_key_target'=>'departments'];
		}
		if(isset($entity_keys['user'])){
			$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'employee_number'=>['type'=>'string'],
			'manager_employee_id'=>['type'=>'integer', 'foreign_key_target'=>'employees'],
			'employment_status'=>['type'=>'string', 'default'=>'active'],
			'hire_date'=>['type'=>'date'],
		];
	}

	/**
	 * Selects context-aware default fields for effective-dated employment contracts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Employment contract field definition hints.
	 */
	private function app_builder_default_employment_contract_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'contract_type'=>['type'=>'string', 'default'=>'full_time'],
			'effective_from'=>['type'=>'date', 'required'=>true],
			'effective_to'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for compensation plans.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Compensation plan field definition hints.
	 */
	private function app_builder_default_compensation_plan_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'base_salary_cents'=>['type'=>'integer'],
			'effective_from'=>['type'=>'date', 'required'=>true],
			'effective_to'=>['type'=>'date'],
		];
	}

	/**
	 * Selects context-aware default fields for benefits enrollments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Benefits enrollment field definition hints.
	 */
	private function app_builder_default_benefits_enrollment_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'plan_key'=>['type'=>'string', 'required'=>true],
			'coverage_level'=>['type'=>'string', 'default'=>'employee'],
			'effective_from'=>['type'=>'date', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for PTO requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> PTO request field definition hints.
	 */
	private function app_builder_default_pto_request_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'start_date'=>['type'=>'date', 'required'=>true],
			'end_date'=>['type'=>'date', 'required'=>true],
			'hours_requested'=>['type'=>'decimal'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for timesheets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Timesheet field definition hints.
	 */
	private function app_builder_default_timesheet_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'period_start'=>['type'=>'date', 'required'=>true],
			'period_end'=>['type'=>'date', 'required'=>true],
			'hours_total'=>['type'=>'decimal'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for performance reviews.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Performance review field definition hints.
	 */
	private function app_builder_default_performance_review_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'reviewer_employee_id'=>['type'=>'integer', 'foreign_key_target'=>'employees'],
			'review_period'=>['type'=>'string', 'required'=>true],
			'rating'=>['type'=>'string', 'default'=>'meets_expectations'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for goals.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Goal field definition hints.
	 */
	private function app_builder_default_goal_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'foreign_key_target'=>'employees'],
			'title'=>['type'=>'string', 'required'=>true],
			'target_at'=>['type'=>'date'],
			'progress'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for learning/compliance learners.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Learner field definition hints.
	 */
	private function app_builder_default_learner_fields(array $args): array {
		return [
			'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
			'user_id'=>['type'=>'integer', 'foreign_key_target'=>'user'],
			'manager_id'=>['type'=>'integer', 'foreign_key_target'=>'manager'],
			'email'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for learning managers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Manager field definition hints.
	 */
	private function app_builder_default_learning_manager_fields(array $args): array {
		return [
			'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
			'user_id'=>['type'=>'integer', 'foreign_key_target'=>'user'],
			'email'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for learning courses.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Course field definition hints.
	 */
	private function app_builder_default_course_fields(array $args): array {
		return [
			'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspaces'],
			'title'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text'],
			'version'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for learning modules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Module field definition hints.
	 */
	private function app_builder_default_learning_module_fields(array $args): array {
		return [
			'course_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'course'],
			'title'=>['type'=>'string', 'required'=>true],
			'position'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for learning lessons.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Lesson field definition hints.
	 */
	private function app_builder_default_lesson_fields(array $args): array {
		return [
			'module_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'module'],
			'title'=>['type'=>'string', 'required'=>true],
			'content_ref'=>['type'=>'json'],
			'position'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for learning assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Assignment field definition hints.
	 */
	private function app_builder_default_learning_assignment_fields(array $args): array {
		return [
			'learner_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'learner'],
			'course_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'course'],
			'assigned_by_id'=>['type'=>'integer', 'foreign_key_target'=>'user'],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'assigned'],
		];
	}

	/**
	 * Selects context-aware default fields for learning attestations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Attestation field definition hints.
	 */
	private function app_builder_default_attestation_fields(array $args): array {
		return [
			'learner_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'learner'],
			'course_id'=>['type'=>'integer', 'foreign_key_target'=>'course'],
			'policy_key'=>['type'=>'string'],
			'attested_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for learning certificates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Certificate field definition hints.
	 */
	private function app_builder_default_certificate_fields(array $args): array {
		return [
			'learner_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'learner'],
			'course_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'course'],
			'certificate_ref'=>['type'=>'string', 'required'=>true],
			'issued_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'issued'],
		];
	}

	/**
	 * Selects context-aware default fields for quizzes.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Quiz field definition hints.
	 */
	private function app_builder_default_quiz_fields(array $args): array {
		return [
			'course_id'=>['type'=>'integer', 'foreign_key_target'=>'course'],
			'module_id'=>['type'=>'integer', 'foreign_key_target'=>'module'],
			'title'=>['type'=>'string', 'required'=>true],
			'passing_score'=>['type'=>'integer', 'default'=>80],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for quiz questions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Question field definition hints.
	 */
	private function app_builder_default_question_fields(array $args): array {
		return [
			'quiz_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'quiz'],
			'prompt'=>['type'=>'text', 'required'=>true],
			'question_type'=>['type'=>'string', 'default'=>'single_choice'],
			'options'=>['type'=>'json'],
			'position'=>['type'=>'integer', 'default'=>0],
		];
	}

	/**
	 * Selects context-aware default fields for quiz attempts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Attempt field definition hints.
	 */
	private function app_builder_default_attempt_fields(array $args): array {
		return [
			'quiz_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'quiz'],
			'learner_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'learner'],
			'score'=>['type'=>'integer', 'default'=>0],
			'passed'=>['type'=>'boolean', 'default'=>false],
			'submitted_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for policy acknowledgements.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Policy acknowledgement field definition hints.
	 */
	private function app_builder_default_policy_acknowledgement_fields(array $args): array {
		return [
			'learner_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'learner'],
			'policy_key'=>['type'=>'string', 'required'=>true],
			'policy_version'=>['type'=>'string'],
			'acknowledged_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for healthcare provider credentialing records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Provider field definition hints.
	 */
	private function app_builder_default_provider_fields(array $args): array {
		return [
			'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspace'],
			'npi'=>['type'=>'string', 'required'=>true],
			'first_name'=>['type'=>'string', 'required'=>true],
			'last_name'=>['type'=>'string', 'required'=>true],
			'provider_type'=>['type'=>'string', 'default'=>'individual'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	private function app_builder_default_license_fields(array $args): array {
		if($this->app_builder_has_provider_credentialing_context(strtolower((string)($args['task'] ?? ''))) || isset($this->app_builder_entity_key_set($args)['provider'])){
			return [
				'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
				'license_number'=>['type'=>'string', 'required'=>true],
				'state'=>['type'=>'string'],
				'issued_at'=>['type'=>'date'],
				'expires_at'=>['type'=>'date'],
				'status'=>['type'=>'string', 'default'=>'active'],
			];
		}
		return [
			'account_id'=>['type'=>'integer', 'foreign_key_target'=>'accounts'],
			'seat_id'=>['type'=>'integer', 'foreign_key_target'=>'seats'],
			'license_key'=>['type'=>'string'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	private function app_builder_default_provider_profile_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'specialty'=>['type'=>'string'],
			'taxonomy_code'=>['type'=>'string'],
			'practice_address'=>['type'=>'json'],
			'contact_email'=>['type'=>'string'],
		];
	}

	private function app_builder_default_provider_certification_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'name'=>['type'=>'string', 'required'=>true],
			'issuer'=>['type'=>'string'],
			'issued_at'=>['type'=>'date'],
			'expires_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	private function app_builder_default_credentialing_application_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'application_number'=>['type'=>'string', 'required'=>true],
			'submitted_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	private function app_builder_default_credentialing_step_fields(array $args): array {
		return [
			'credentialing_application_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'credentialing_application'],
			'step_type'=>['type'=>'string', 'required'=>true],
			'assigned_to_id'=>['type'=>'integer', 'foreign_key_target'=>'user'],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	private function app_builder_default_provider_verification_fields(array $args): array {
		return [
			'credentialing_application_id'=>['type'=>'integer', 'foreign_key_target'=>'credentialing_application'],
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'verification_type'=>['type'=>'string', 'required'=>true],
			'verified_at'=>['type'=>'datetime'],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	private function app_builder_default_payer_enrollment_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'payer_name'=>['type'=>'string', 'required'=>true],
			'enrollment_number'=>['type'=>'string'],
			'effective_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	private function app_builder_default_network_contract_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'foreign_key_target'=>'provider'],
			'payer_enrollment_id'=>['type'=>'integer', 'foreign_key_target'=>'payer_enrollment'],
			'contract_name'=>['type'=>'string', 'required'=>true],
			'effective_at'=>['type'=>'date'],
			'expires_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	private function app_builder_default_facility_fields(array $args): array {
		return [
			'workspace_id'=>['type'=>'integer', 'foreign_key_target'=>'workspace'],
			'name'=>['type'=>'string', 'required'=>true],
			'facility_type'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	private function app_builder_default_privilege_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'facility_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'facility'],
			'privilege_name'=>['type'=>'string', 'required'=>true],
			'granted_at'=>['type'=>'date'],
			'expires_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	private function app_builder_default_expiration_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'foreign_key_target'=>'provider'],
			'related_entity'=>['type'=>'string', 'required'=>true],
			'related_id'=>['type'=>'integer', 'required'=>true],
			'expires_at'=>['type'=>'date', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'upcoming'],
		];
	}

	private function app_builder_default_background_check_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'requested_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'result'=>['type'=>'string', 'default'=>'pending'],
			'evidence_ref'=>['type'=>'json'],
		];
	}

	private function app_builder_default_sanction_check_fields(array $args): array {
		return [
			'provider_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'provider'],
			'checked_at'=>['type'=>'datetime'],
			'source'=>['type'=>'string', 'required'=>true],
			'result'=>['type'=>'string', 'default'=>'clear'],
			'evidence_ref'=>['type'=>'json'],
		];
	}

	private function app_builder_default_committee_review_fields(array $args): array {
		return [
			'credentialing_application_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'credentialing_application'],
			'reviewed_at'=>['type'=>'datetime'],
			'committee_name'=>['type'=>'string', 'required'=>true],
			'recommendation'=>['type'=>'string', 'default'=>'pending'],
			'notes'=>['type'=>'text'],
		];
	}

	private function app_builder_default_approval_decision_fields(array $args): array {
		return [
			'credentialing_application_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'credentialing_application'],
			'decided_by_id'=>['type'=>'integer', 'foreign_key_target'=>'user'],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'decided_at'=>['type'=>'datetime'],
			'reason'=>['type'=>'text'],
		];
	}

	/**
	 * Selects context-aware default fields for training assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Training assignment field definition hints.
	 */
	private function app_builder_default_training_assignment_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'course_key'=>['type'=>'string', 'required'=>true],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'assigned'],
		];
	}

	/**
	 * Selects context-aware default fields for compliance attestations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Compliance attestation field definition hints.
	 */
	private function app_builder_default_compliance_attestation_fields(array $args): array {
		return [
			'employee_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'employees'],
			'policy_key'=>['type'=>'string', 'required'=>true],
			'attested_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for compliance frameworks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Framework field definition hints.
	 */
	private function app_builder_default_framework_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'version'=>['type'=>'string'],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for app-owned compliance controls.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Control field definition hints.
	 */
	private function app_builder_default_control_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['framework'])){
			$fields['framework_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'frameworks'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}
		$unique_scope=array_keys($fields);
		return $fields + [
			'control_key'=>['type'=>'string', 'required'=>true, 'unique_with'=>$unique_scope],
			'title'=>['type'=>'string', 'required'=>true],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'frequency'=>['type'=>'string', 'default'=>'quarterly'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for evidence uploads.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Evidence upload field definition hints.
	 */
	private function app_builder_default_evidence_upload_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['evidencerequest'])){
			$fields['evidence_request_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'evidence requests'];
		}elseif(isset($entity_keys['control'])){
			$fields['control_id']=['type'=>'integer', 'foreign_key_target'=>'controls'];
		}
		return $fields + [
			'object_ref'=>['type'=>'json', 'required'=>true],
			'uploaded_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'classification'=>['type'=>'string', 'default'=>'confidential'],
			'expires_at'=>['type'=>'datetime'],
			'uploaded_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending_review'],
		];
	}

	/**
	 * Selects context-aware default fields for audit evidence packages.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Evidence package field definition hints.
	 */
	private function app_builder_default_evidence_package_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['framework'])){
			$fields['framework_id']=['type'=>'integer', 'foreign_key_target'=>'frameworks'];
		}
		if(isset($entity_keys['control'])){
			$fields['control_id']=['type'=>'integer', 'foreign_key_target'=>'controls'];
		}
		return $fields + [
			'package_key'=>['type'=>'string', 'required'=>true],
			'audit_period_start'=>['type'=>'date', 'required'=>true],
			'audit_period_end'=>['type'=>'date', 'required'=>true],
			'evidence_refs'=>['type'=>'json', 'required'=>true],
			'export_ref'=>['type'=>'json'],
			'classification'=>['type'=>'string', 'default'=>'confidential'],
			'generated_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'generated_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for data catalog assets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data asset field definition hints.
	 */
	private function app_builder_default_data_asset_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataclassification'])){
			$fields['data_classification_id']=['type'=>'integer', 'foreign_key_target'=>'data classifications'];
		}
		if(isset($entity_keys['dataowner'])){
			$fields['data_owner_id']=['type'=>'integer', 'foreign_key_target'=>'data owners'];
		}
		return $fields + [
			'asset_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'asset_type'=>['type'=>'string', 'default'=>'table'],
			'system_name'=>['type'=>'string'],
			'data_region'=>['type'=>'string'],
			'classified_at'=>['type'=>'datetime'],
			'last_scanned_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for data classifications.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data classification field definition hints.
	 */
	private function app_builder_default_data_classification_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'classification_key'=>['type'=>'string', 'required'=>true],
			'label'=>['type'=>'string', 'required'=>true],
			'sensitivity_level'=>['type'=>'string', 'default'=>'internal'],
			'handling_policy'=>['type'=>'json'],
			'retention_policy'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for data ownership stewardship.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data owner field definition hints.
	 */
	private function app_builder_default_data_owner_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'owner_role'=>['type'=>'string', 'default'=>'steward'],
			'stewardship_scope'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for directed data lineage edges.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data lineage edge field definition hints.
	 */
	private function app_builder_default_data_lineage_edge_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataasset'])){
			$fields['source_data_asset_id']=['type'=>'integer', 'foreign_key_target'=>'data assets'];
			$fields['target_data_asset_id']=['type'=>'integer', 'foreign_key_target'=>'data assets'];
		}
		return $fields + [
			'lineage_type'=>['type'=>'string', 'default'=>'transforms_to'],
			'transform_ref'=>['type'=>'string'],
			'observed_at'=>['type'=>'datetime'],
			'confidence_score'=>['type'=>'integer', 'default'=>100],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for data access requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data access request field definition hints.
	 */
	private function app_builder_default_data_access_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataasset'])){
			$fields['data_asset_id']=['type'=>'integer', 'foreign_key_target'=>'data assets'];
		}
		$fields['requester_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'access_purpose'=>['type'=>'text', 'required'=>true],
			'access_level'=>['type'=>'string', 'default'=>'read'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'submitted'],
		];
	}

	/**
	 * Selects default fields for access approvals.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Access approval field definition hints.
	 */
	private function app_builder_default_access_approval_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataaccessrequest'])){
			$fields['data_access_request_id']=['type'=>'integer', 'foreign_key_target'=>'data access requests'];
		}
		$fields['approver_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'approval_step'=>['type'=>'string', 'default'=>'owner_review'],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'decided_at'=>['type'=>'datetime'],
			'decision_reason'=>['type'=>'text'],
		];
	}

	/**
	 * Selects default fields for retention schedules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Retention schedule field definition hints.
	 */
	private function app_builder_default_retention_schedule_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataasset'])){
			$fields['data_asset_id']=['type'=>'integer', 'foreign_key_target'=>'data assets'];
		}
		if(isset($entity_keys['dataclassification'])){
			$fields['data_classification_id']=['type'=>'integer', 'foreign_key_target'=>'data classifications'];
		}
		return $fields + [
			'retention_rule_key'=>['type'=>'string', 'required'=>true],
			'retention_period_days'=>['type'=>'integer', 'required'=>true],
			'purge_after'=>['type'=>'date'],
			'legal_hold'=>['type'=>'boolean', 'default'=>false],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for reports.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Report field definition hints.
	 */
	private function app_builder_default_report_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'filters'=>['type'=>'json'],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'visibility'=>['type'=>'string', 'default'=>'workspace'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for dashboards.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Dashboard field definition hints.
	 */
	private function app_builder_default_dashboard_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'layout'=>['type'=>'json', 'required'=>true],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'visibility'=>['type'=>'string', 'default'=>'workspace'],
			'default_filter_json'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for report widgets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Report widget field definition hints.
	 */
	private function app_builder_default_report_widget_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['dashboard'])){
			$fields['dashboard_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'dashboards'];
		}elseif(isset($entity_keys['report'])){
			$fields['report_id']=['type'=>'integer', 'foreign_key_target'=>'reports'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		if(isset($entity_keys['metricdefinition'])){
			$fields['metric_definition_id']=['type'=>'integer', 'foreign_key_target'=>'metric definitions'];
		}
		return $fields + [
			'title'=>['type'=>'string', 'required'=>true],
			'widget_type'=>['type'=>'string', 'default'=>'chart'],
			'position_json'=>['type'=>'json'],
			'config_json'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for metric definitions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Metric definition field hints.
	 */
	private function app_builder_default_metric_definition_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'metric_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'formula'=>['type'=>'text', 'required'=>true],
			'dimension_keys'=>['type'=>'json'],
			'freshness_minutes'=>['type'=>'integer', 'default'=>60],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for report runs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Report run field definition hints.
	 */
	private function app_builder_default_report_run_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['report'])){
			$fields['report_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'reports'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'run_key'=>['type'=>'string', 'required'=>true],
			'parameters_json'=>['type'=>'json'],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
			'row_count'=>['type'=>'integer', 'default'=>0],
			'output_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'queued'],
		];
	}

	/**
	 * Selects context-aware default fields for report subscriptions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Report subscription field hints.
	 */
	private function app_builder_default_report_subscription_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['report'])){
			$fields['report_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'reports'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'recipient_email'=>['type'=>'string'],
			'schedule_cron'=>['type'=>'string'],
			'format'=>['type'=>'string', 'default'=>'pdf'],
			'last_sent_at'=>['type'=>'datetime'],
			'suppressed_until'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for app-owned integration connections.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Integration connection field definition hints.
	 */
	private function app_builder_default_integration_connection_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'provider'=>['type'=>'string', 'required'=>true],
			'connection_ref'=>['type'=>'string'],
			'credential_ref'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
			'last_success_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for external object mapping tables.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> External object map field definition hints.
	 */
	private function app_builder_default_external_object_map_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['integrationconnection'])){
			$fields['integration_connection_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'integration connections'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		$fields['provider']=['type'=>'string', 'required'=>true];
		$fields['object_type']=['type'=>'string', 'required'=>true];
		$fields['external_id']=['type'=>'string', 'required'=>true];
		if(isset($fields['integration_connection_id'])){
			$fields['external_id']['unique_with']=['integration_connection_id', 'object_type'];
		}
		return $fields + [
			'local_type'=>['type'=>'string', 'required'=>true],
			'local_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'sync_status'=>['type'=>'string', 'default'=>'active'],
			'last_synced_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for resumable sync runs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Sync run field definition hints.
	 */
	private function app_builder_default_sync_run_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['integrationconnection'])){
			$fields['integration_connection_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'integration connections'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'sync_direction'=>['type'=>'string', 'default'=>'pull'],
			'sync_cursor'=>['type'=>'string'],
			'sync_checkpoint'=>['type'=>'string'],
			'sync_status'=>['type'=>'string', 'default'=>'pending'],
			'remote_count'=>['type'=>'integer', 'default'=>0],
			'local_count'=>['type'=>'integer', 'default'=>0],
			'drift_status'=>['type'=>'string'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'dead_letter_reason'=>['type'=>'text'],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
			'last_success_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for sync checkpoints.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Sync checkpoint field definition hints.
	 */
	private function app_builder_default_sync_checkpoint_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['integrationconnection'])){
			$fields['integration_connection_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'integration connections'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		$fields['stream_key']=['type'=>'string', 'required'=>true];
		if(isset($fields['integration_connection_id'])){
			$fields['stream_key']['unique_with']=['integration_connection_id'];
		}
		return $fields + [
			'sync_cursor'=>['type'=>'string'],
			'sync_checkpoint'=>['type'=>'string'],
			'sync_status'=>['type'=>'string', 'default'=>'active'],
			'last_success_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for import batches.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Import batch field definition hints.
	 */
	private function app_builder_default_import_batch_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['integrationconnection'])){
			$fields['integration_connection_id']=['type'=>'integer', 'foreign_key_target'=>'integration connections'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'source_ref'=>['type'=>'string'],
			'request_hash'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'pending'],
			'remote_count'=>['type'=>'integer', 'default'=>0],
			'local_count'=>['type'=>'integer', 'default'=>0],
			'error_count'=>['type'=>'integer', 'default'=>0],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for export jobs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Export job field definition hints.
	 */
	private function app_builder_default_export_job_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['integrationconnection'])){
			$fields['integration_connection_id']=['type'=>'integer', 'foreign_key_target'=>'integration connections'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'destination_ref'=>['type'=>'string'],
			'request_hash'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'pending'],
			'local_count'=>['type'=>'integer', 'default'=>0],
			'remote_count'=>['type'=>'integer', 'default'=>0],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for reliable outbox events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Outbox event field definition hints.
	 */
	private function app_builder_default_outbox_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['event_type']=['type'=>'string', 'required'=>true];
		$fields['payload']=['type'=>'json', 'required'=>true];
		$fields['idempotency_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['idempotency_key']['unique_with']=[$scope_fields[0]];
		}else{
			$fields['idempotency_key']['unique']=true;
		}
		return $fields + [
			'request_hash'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'queued'],
			'queued_at'=>['type'=>'datetime'],
			'processed_at'=>['type'=>'datetime'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'next_retry_at'=>['type'=>'datetime'],
			'dead_lettered_at'=>['type'=>'datetime'],
			'last_error'=>['type'=>'text'],
		];
	}

	/**
	 * Selects default fields for app-owned job run records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Job run field definition hints.
	 */
	private function app_builder_default_job_run_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'job_id'=>['type'=>'string', 'required'=>true],
			'queue_name'=>['type'=>'string', 'required'=>true],
			'worker_id'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'queued'],
			'scheduled_at'=>['type'=>'datetime'],
			'run_at'=>['type'=>'datetime'],
			'locked_at'=>['type'=>'datetime'],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
			'attempt_count'=>['type'=>'integer', 'default'=>0],
			'next_retry_at'=>['type'=>'datetime'],
			'last_error'=>['type'=>'text'],
		];
	}

	/**
	 * Selects default fields for app-owned scheduled jobs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Scheduled job field definition hints.
	 */
	private function app_builder_default_scheduled_job_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['job_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['job_key']['unique_with']=[$scope_fields[0]];
		}else{
			$fields['job_key']['unique']=true;
		}
		return $fields + [
			'queue_name'=>['type'=>'string', 'required'=>true],
			'cron_expression'=>['type'=>'string'],
			'scheduled_at'=>['type'=>'datetime'],
			'run_at'=>['type'=>'datetime'],
			'last_success_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for dead-letter events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Dead-letter event field definition hints.
	 */
	private function app_builder_default_dead_letter_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'source_type'=>['type'=>'string', 'required'=>true],
			'source_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'event_id'=>['type'=>'string'],
			'payload'=>['type'=>'json'],
			'dead_letter_reason'=>['type'=>'text', 'required'=>true],
			'dead_lettered_at'=>['type'=>'datetime'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'last_error'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'failed'],
		];
	}

	/**
	 * Selects default fields for regulated case records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case record field definition hints.
	 */
	private function app_builder_default_case_record_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'case_number'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'intake_source'=>['type'=>'string', 'default'=>'manual'],
			'priority'=>['type'=>'string', 'default'=>'medium'],
			'case_status'=>['type'=>'string', 'default'=>'open'],
			'triaged_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'closed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for case participants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case participant field definition hints.
	 */
	private function app_builder_default_case_participant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'participant_role'=>['type'=>'string', 'default'=>'viewer'],
			'display_name'=>['type'=>'string'],
			'email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for case assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case assignment field definition hints.
	 */
	private function app_builder_default_case_assignment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		$fields['assignee_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'assignment_role'=>['type'=>'string', 'default'=>'investigator'],
			'assigned_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for threaded case comments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case comment field definition hints.
	 */
	private function app_builder_default_case_comment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		$fields['author_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'parent_comment_id'=>['type'=>'integer', 'foreign_key_target'=>'case comments'],
			'body'=>['type'=>'text', 'required'=>true],
			'visibility'=>['type'=>'string', 'default'=>'internal'],
			'posted_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for case document evidence.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case document field definition hints.
	 */
	private function app_builder_default_case_document_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		if(isset($entity_keys['document'])){
			$fields['document_id']=['type'=>'integer', 'foreign_key_target'=>'documents'];
		}
		return $fields + [
			'object_ref'=>['type'=>'json'],
			'document_role'=>['type'=>'string', 'default'=>'evidence'],
			'classification'=>['type'=>'string', 'default'=>'confidential'],
			'uploaded_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'uploaded_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for case decisions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case decision field definition hints.
	 */
	private function app_builder_default_case_decision_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		$fields['decided_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'decision_reason'=>['type'=>'text'],
			'decided_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for case SLA timers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case SLA field definition hints.
	 */
	private function app_builder_default_case_sla_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		return $fields + [
			'sla_key'=>['type'=>'string', 'required'=>true],
			'target_at'=>['type'=>'datetime', 'required'=>true],
			'breached_at'=>['type'=>'datetime'],
			'escalated_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for case event history.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Case event field definition hints.
	 */
	private function app_builder_default_case_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['caserecord'])){
			$fields['case_record_id']=['type'=>'integer', 'foreign_key_target'=>'case records'];
		}
		return $fields + [
			'event_type'=>['type'=>'string', 'required'=>true],
			'actor_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'metadata'=>['type'=>'json'],
			'occurred_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'recorded'],
		];
	}

	/**
	 * Selects default fields for app-owned agent profiles.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Agent profile field definition hints.
	 */
	private function app_builder_default_agent_profile_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'agent_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'purpose'=>['type'=>'text'],
			'default_model'=>['type'=>'string'],
			'risk_tier'=>['type'=>'string', 'default'=>'medium'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for prompt templates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Prompt template field definition hints.
	 */
	private function app_builder_default_prompt_template_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		return $fields + [
			'prompt_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'template_type'=>['type'=>'string', 'default'=>'system'],
			'variables_schema'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for prompt versions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Prompt version field definition hints.
	 */
	private function app_builder_default_prompt_version_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['prompttemplate'])){
			$fields['prompt_template_id']=['type'=>'integer', 'foreign_key_target'=>'prompt templates'];
		}
		return $fields + [
			'version_label'=>['type'=>'string', 'required'=>true],
			'prompt_body'=>['type'=>'text', 'required'=>true],
			'change_summary'=>['type'=>'text'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for agent tool permissions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Tool permission field definition hints.
	 */
	private function app_builder_default_tool_permission_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		return $fields + [
			'tool_name'=>['type'=>'string', 'required'=>true],
			'permission_mode'=>['type'=>'string', 'default'=>'allow'],
			'constraint_json'=>['type'=>'json'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for model usage policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Model policy field definition hints.
	 */
	private function app_builder_default_model_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		return $fields + [
			'policy_key'=>['type'=>'string', 'required'=>true],
			'allowed_models'=>['type'=>'json', 'required'=>true],
			'data_classification'=>['type'=>'string', 'default'=>'internal'],
			'max_tokens'=>['type'=>'integer'],
			'approval_status'=>['type'=>'string', 'default'=>'pending'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for evaluation runs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Evaluation run field definition hints.
	 */
	private function app_builder_default_evaluation_run_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		if(isset($entity_keys['promptversion'])){
			$fields['prompt_version_id']=['type'=>'integer', 'foreign_key_target'=>'prompt versions'];
		}
		return $fields + [
			'evaluation_key'=>['type'=>'string', 'required'=>true],
			'dataset_ref'=>['type'=>'json'],
			'metric_summary'=>['type'=>'json'],
			'pass_rate'=>['type'=>'integer', 'default'=>0],
			'started_at'=>['type'=>'datetime'],
			'finished_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'queued'],
		];
	}

	/**
	 * Selects default fields for evaluation findings.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Evaluation finding field definition hints.
	 */
	private function app_builder_default_evaluation_finding_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['evaluationrun'])){
			$fields['evaluation_run_id']=['type'=>'integer', 'foreign_key_target'=>'evaluation runs'];
		}
		return $fields + [
			'finding_type'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'summary'=>['type'=>'text', 'required'=>true],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for safety review gates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Safety review field definition hints.
	 */
	private function app_builder_default_safety_review_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		if(isset($entity_keys['evaluationrun'])){
			$fields['evaluation_run_id']=['type'=>'integer', 'foreign_key_target'=>'evaluation runs'];
		}
		return $fields + [
			'reviewer_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'review_status'=>['type'=>'string', 'default'=>'pending'],
			'gate_reason'=>['type'=>'text'],
			'approved_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for agent incidents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Agent incident field definition hints.
	 */
	private function app_builder_default_agent_incident_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['agentprofile'])){
			$fields['agent_profile_id']=['type'=>'integer', 'foreign_key_target'=>'agent profiles'];
		}
		if(isset($entity_keys['evaluationfinding'])){
			$fields['evaluation_finding_id']=['type'=>'integer', 'foreign_key_target'=>'evaluation findings'];
		}
		return $fields + [
			'incident_key'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'incident_summary'=>['type'=>'text', 'required'=>true],
			'detected_at'=>['type'=>'datetime'],
			'resolved_at'=>['type'=>'datetime'],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for roles.
	 *
	 * Roles stay app-owned, but when PermissionSet is part of the scaffold the
	 * generated model should express the relationship instead of leaving agents
	 * to invent a parallel permissions JSON convention.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Role field definition hints.
	 */
	private function app_builder_default_role_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}
		$fields['name']=['type'=>'string', 'required'=>true];
		if(isset($entity_keys['permissionset'])){
			$fields['permission_set_id']=['type'=>'integer', 'foreign_key_target'=>'permission sets'];
		}else{
			$fields['permissions']=['type'=>'json', 'required'=>true];
		}
		return $fields + [
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for permission sets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Permission set field definition hints.
	 */
	private function app_builder_default_permission_set_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'permissions'=>['type'=>'json', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for workspace/account memberships.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Membership field definition hints.
	 */
	private function app_builder_default_membership_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'organizations'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenants'];
		}
		$scope_fields=array_keys($fields);
		$fields['user_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'];
		if($scope_fields!==[]){
			$fields['user_id']['unique_with']=[$scope_fields[0]];
		}
		if(isset($entity_keys['role'])){
			$fields['role_id']=['type'=>'integer', 'foreign_key_target'=>'roles'];
		}
		if(isset($entity_keys['permissionset'])){
			$fields['permission_set_id']=['type'=>'integer', 'foreign_key_target'=>'permission sets'];
		}
		return $fields + [
			'status'=>['type'=>'string', 'default'=>'active'],
			'joined_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for invitations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Invitation field definition hints.
	 */
	private function app_builder_default_invitation_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys, true);
		$scope_fields=array_keys($fields);
		$fields['email']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['email']['unique_with']=[$scope_fields[0], 'status'];
		}
		if(isset($entity_keys['role'])){
			$fields['role_id']=['type'=>'integer', 'foreign_key_target'=>'roles'];
		}
		if(isset($entity_keys['permissionset'])){
			$fields['permission_set_id']=['type'=>'integer', 'foreign_key_target'=>'permission sets'];
		}
		return $fields + [
			'token_hash'=>['type'=>'string', 'required'=>true, 'unique'=>true],
			'status'=>['type'=>'string', 'default'=>'pending'],
			'expires_at'=>['type'=>'datetime', 'required'=>true],
			'accepted_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for API keys.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> API key field definition hints.
	 */
	private function app_builder_default_api_key_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['apiclient'])){
			$fields['api_client_id']=['type'=>'integer', 'foreign_key_target'=>'api clients'];
		}
		return $fields + [
			'label'=>['type'=>'string', 'required'=>true],
			'encrypted_secret_ref'=>['type'=>'string', 'required'=>true],
			'token_hash'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
			'rotated_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'last_used_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for API products.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> API product field definition hints.
	 */
	private function app_builder_default_api_product_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'product_key'=>['type'=>'string', 'required'=>true, 'unique'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text'],
			'visibility'=>['type'=>'string', 'default'=>'private'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for API clients.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> API client field definition hints.
	 */
	private function app_builder_default_api_client_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['apiproduct'])){
			$fields['api_product_id']=['type'=>'integer', 'foreign_key_target'=>'api products'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'client_key'=>['type'=>'string', 'required'=>true, 'unique'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'redirect_uris'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for OAuth applications.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> OAuth application field definition hints.
	 */
	private function app_builder_default_oauth_application_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['apiclient'])){
			$fields['api_client_id']=['type'=>'integer', 'foreign_key_target'=>'api clients'];
		}
		return $fields + [
			'client_id'=>['type'=>'string', 'required'=>true, 'not_foreign_key'=>true, 'unique'=>true],
			'client_secret_ref'=>['type'=>'string'],
			'redirect_uris'=>['type'=>'json', 'required'=>true],
			'grant_types'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for OAuth grants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> OAuth grant field definition hints.
	 */
	private function app_builder_default_oauth_grant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['oauthapplication'])){
			$fields['oauth_application_id']=['type'=>'integer', 'foreign_key_target'=>'oauth applications'];
		}
		if(isset($entity_keys['user'])){
			$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'grant_type'=>['type'=>'string', 'default'=>'authorization_code'],
			'scope'=>['type'=>'string'],
			'approved_at'=>['type'=>'datetime'],
			'revoked_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for API rate limit policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Rate limit policy field definition hints.
	 */
	private function app_builder_default_rate_limit_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['apiproduct'])){
			$fields['api_product_id']=['type'=>'integer', 'foreign_key_target'=>'api products'];
		}
		if(isset($entity_keys['apiclient'])){
			$fields['api_client_id']=['type'=>'integer', 'foreign_key_target'=>'api clients'];
		}
		return $fields + [
			'policy_key'=>['type'=>'string', 'required'=>true],
			'window_seconds'=>['type'=>'integer', 'required'=>true, 'default'=>60],
			'request_limit'=>['type'=>'integer', 'required'=>true],
			'burst_limit'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for API usage events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Usage event field definition hints.
	 */
	private function app_builder_default_usage_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['apiclient'])){
			$fields['api_client_id']=['type'=>'integer', 'foreign_key_target'=>'api clients'];
		}
		if(isset($entity_keys['apiproduct'])){
			$fields['api_product_id']=['type'=>'integer', 'foreign_key_target'=>'api products'];
		}
		return $fields + [
			'event_key'=>['type'=>'string'],
			'metric_key'=>['type'=>'string', 'required'=>true],
			'quantity'=>['type'=>'integer', 'default'=>1],
			'occurred_at'=>['type'=>'datetime', 'required'=>true],
			'request_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'status'=>['type'=>'string', 'default'=>'recorded'],
		];
	}

	/**
	 * Selects context-aware default fields for service accounts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Service account field definition hints.
	 */
	private function app_builder_default_service_account_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['role'])){
			$fields['role_id']=['type'=>'integer', 'foreign_key_target'=>'roles'];
		}
		if(isset($entity_keys['permissionset'])){
			$fields['permission_set_id']=['type'=>'integer', 'foreign_key_target'=>'permission sets'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'owner_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'credential_ref'=>['type'=>'string'],
			'last_used_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for access review campaigns.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Access review field definition hints.
	 */
	private function app_builder_default_access_review_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'reviewer_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'started_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for access review line items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Access review item field definition hints.
	 */
	private function app_builder_default_access_review_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['accessreview'])){
			$fields['access_review_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'access reviews'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		if(isset($entity_keys['user'])){
			$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		if(isset($entity_keys['serviceaccount'])){
			$fields['service_account_id']=['type'=>'integer', 'foreign_key_target'=>'service accounts'];
		}
		if(isset($entity_keys['role'])){
			$fields['role_id']=['type'=>'integer', 'foreign_key_target'=>'roles'];
		}
		if(isset($entity_keys['permissionset'])){
			$fields['permission_set_id']=['type'=>'integer', 'foreign_key_target'=>'permission sets'];
		}
		return $fields + [
			'access_subject_type'=>['type'=>'string', 'required'=>true],
			'access_subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'justification'=>['type'=>'text'],
			'decided_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'decided_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for session policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Session policy field definition hints.
	 */
	private function app_builder_default_session_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'max_session_minutes'=>['type'=>'integer', 'default'=>480],
			'mfa_required'=>['type'=>'boolean', 'default'=>true],
			'allowed_ip_ranges'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for credential rotation jobs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Credential rotation job field definition hints.
	 */
	private function app_builder_default_credential_rotation_job_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['serviceaccount'])){
			$fields['service_account_id']=['type'=>'integer', 'foreign_key_target'=>'service accounts'];
		}
		if(isset($entity_keys['apikey'])){
			$fields['api_key_id']=['type'=>'integer', 'foreign_key_target'=>'api keys'];
		}
		return $fields + [
			'credential_ref'=>['type'=>'string'],
			'rotation_reason'=>['type'=>'string'],
			'scheduled_at'=>['type'=>'datetime'],
			'rotated_at'=>['type'=>'datetime'],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects context-aware default fields for SSO providers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> SSO provider field definition hints.
	 */
	private function app_builder_default_sso_provider_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'issuer_url'=>['type'=>'string', 'required'=>true],
			'saml_entity_id'=>['type'=>'string'],
			'certificate_ref'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for SCIM directory providers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> SCIM provider field definition hints.
	 */
	private function app_builder_default_scim_provider_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'base_url'=>['type'=>'string', 'required'=>true],
			'credential_ref'=>['type'=>'string'],
			'user_filter_rules'=>['type'=>'json'],
			'group_filter_rules'=>['type'=>'json'],
			'last_sync_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for SCIM provisioning jobs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> SCIM provisioning job field definition hints.
	 */
	private function app_builder_default_scim_provisioning_job_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['scimprovider'])){
			$fields['scim_provider_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'scim providers'];
		}
		return $fields + [
			'operation'=>['type'=>'string', 'required'=>true],
			'subject_type'=>['type'=>'string', 'required'=>true],
			'subject_external_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'idempotency_key'=>['type'=>'string'],
			'payload_ref'=>['type'=>'json'],
			'error_summary'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'queued'],
		];
	}

	/**
	 * Selects context-aware default fields for audited impersonation sessions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Impersonation session field definition hints.
	 */
	private function app_builder_default_impersonation_session_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'actor_user_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
			'target_user_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
			'reason'=>['type'=>'text', 'required'=>true],
			'approval_ref'=>['type'=>'json'],
			'started_at'=>['type'=>'datetime', 'required'=>true],
			'ended_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime', 'required'=>true],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for emergency break-glass access grants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Break-glass access grant field definition hints.
	 */
	private function app_builder_default_break_glass_access_grant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'requested_by_user_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
			'approved_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'access_scope'=>['type'=>'string', 'required'=>true],
			'justification'=>['type'=>'text', 'required'=>true],
			'expires_at'=>['type'=>'datetime', 'required'=>true],
			'used_at'=>['type'=>'datetime'],
			'revoked_at'=>['type'=>'datetime'],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for SLA policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> SLA policy field definition hints.
	 */
	private function app_builder_default_sla_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'target_minutes'=>['type'=>'integer', 'required'=>true],
		];
	}

	/**
	 * Selects default fields for service health records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Service health field definition hints.
	 */
	private function app_builder_default_service_health_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'service_key'=>['type'=>'string', 'required'=>true],
			'health_status'=>['type'=>'string', 'default'=>'healthy'],
			'heartbeat_at'=>['type'=>'datetime'],
			'last_seen_at'=>['type'=>'datetime'],
			'degraded_reason'=>['type'=>'text'],
			'uptime_status'=>['type'=>'string', 'default'=>'operational'],
		];
	}

	/**
	 * Selects default fields for status updates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Status update field definition hints.
	 */
	private function app_builder_default_status_update_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['incident'])){
			$fields['incident_id']=['type'=>'integer', 'foreign_key_target'=>'incidents'];
		}
		return $fields + [
			'message'=>['type'=>'text', 'required'=>true],
			'impact'=>['type'=>'string', 'default'=>'minor'],
			'status'=>['type'=>'string', 'default'=>'draft'],
			'notified_at'=>['type'=>'datetime'],
			'copy_safe_evidence'=>['type'=>'json'],
		];
	}

	/**
	 * Selects default fields for diagnostic bundles.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Diagnostic bundle field definition hints.
	 */
	private function app_builder_default_diagnostic_bundle_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['incident'])){
			$fields['incident_id']=['type'=>'integer', 'foreign_key_target'=>'incidents'];
		}
		return $fields + [
			'diagnostic_summary'=>['type'=>'text', 'required'=>true],
			'copy_safe_evidence'=>['type'=>'json', 'required'=>true],
			'trace_id'=>['type'=>'string'],
			'correlation_id'=>['type'=>'string'],
			'log_ref'=>['type'=>'string'],
			'error_code'=>['type'=>'string'],
		];
	}

	/**
	 * Selects default fields for runbooks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Runbook field definition hints.
	 */
	private function app_builder_default_runbook_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'service_key'=>['type'=>'string'],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'steps'=>['type'=>'json', 'required'=>true],
			'escalation_policy'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for notification work items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification field definition hints.
	 */
	private function app_builder_default_notification_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['notificationtemplate'])){
			$fields['notification_template_id']=['type'=>'integer', 'foreign_key_target'=>'notification templates'];
		}
		if(isset($entity_keys['notificationchannel'])){
			$fields['notification_channel_id']=['type'=>'integer', 'foreign_key_target'=>'notification channels'];
		}
		return $fields + [
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'recipient_email'=>['type'=>'string'],
			'template_key'=>['type'=>'string', 'required'=>true],
			'channel'=>['type'=>'string', 'default'=>'email'],
			'payload'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'queued'],
		];
	}

	/**
	 * Selects default fields for notification templates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification template field definition hints.
	 */
	private function app_builder_default_notification_template_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['template_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['template_key']['unique_with']=$scope_fields;
		}
		return $fields + [
			'channel'=>['type'=>'string', 'default'=>'email'],
			'subject_template'=>['type'=>'string'],
			'body_template'=>['type'=>'text', 'required'=>true],
			'variables_schema'=>['type'=>'json'],
			'locale'=>['type'=>'string', 'default'=>'en'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for notification channel adapters.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification channel field definition hints.
	 */
	private function app_builder_default_notification_channel_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['channel_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['channel_key']['unique_with']=$scope_fields;
		}
		return $fields + [
			'channel'=>['type'=>'string', 'default'=>'email'],
			'provider'=>['type'=>'string', 'required'=>true],
			'config_ref'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for recipient notification preferences.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification preference field definition hints.
	 */
	private function app_builder_default_notification_preference_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'preference_key'=>['type'=>'string', 'required'=>true],
			'channel'=>['type'=>'string', 'default'=>'email'],
			'enabled'=>['type'=>'boolean', 'default'=>true],
			'quiet_hours'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for notification suppression windows.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification suppression field definition hints.
	 */
	private function app_builder_default_notification_suppression_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'recipient_email'=>['type'=>'string'],
			'suppression_reason'=>['type'=>'text'],
			'suppressed_until'=>['type'=>'datetime', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for notification delivery attempts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Notification delivery field definition hints.
	 */
	private function app_builder_default_notification_delivery_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['notification'])){
			$fields['notification_id']=['type'=>'integer', 'foreign_key_target'=>'notifications'];
		}
		if(isset($entity_keys['notificationtemplate'])){
			$fields['notification_template_id']=['type'=>'integer', 'foreign_key_target'=>'notification templates'];
		}
		if(isset($entity_keys['notificationchannel'])){
			$fields['notification_channel_id']=['type'=>'integer', 'foreign_key_target'=>'notification channels'];
		}
		return $fields + [
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'recipient_email'=>['type'=>'string'],
			'channel'=>['type'=>'string', 'default'=>'email'],
			'provider_ref'=>['type'=>'string', 'not_foreign_key'=>true],
			'request_hash'=>['type'=>'string'],
			'sent_at'=>['type'=>'datetime'],
			'delivered_at'=>['type'=>'datetime'],
			'failed_at'=>['type'=>'datetime'],
			'delivery_status'=>['type'=>'string', 'default'=>'queued'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'last_error'=>['type'=>'text'],
		];
	}

	/**
	 * Selects default fields for provider delivery receipts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Delivery receipt field definition hints.
	 */
	private function app_builder_default_delivery_receipt_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['notificationdelivery'])){
			$fields['notification_delivery_id']=['type'=>'integer', 'foreign_key_target'=>'notification deliveries'];
		}
		return $fields + [
			'provider_ref'=>['type'=>'string', 'not_foreign_key'=>true],
			'receipt_status'=>['type'=>'string', 'default'=>'received'],
			'received_at'=>['type'=>'datetime'],
			'payload'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for escalation fallback messages.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Escalation message field definition hints.
	 */
	private function app_builder_default_escalation_message_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['alertrule'])){
			$fields['alert_rule_id']=['type'=>'integer', 'foreign_key_target'=>'alert rules'];
		}
		if(isset($entity_keys['notificationdelivery'])){
			$fields['notification_delivery_id']=['type'=>'integer', 'foreign_key_target'=>'notification deliveries'];
		}
		return $fields + [
			'escalation_channel'=>['type'=>'string', 'default'=>'email'],
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'message_template_key'=>['type'=>'string', 'required'=>true],
			'sent_at'=>['type'=>'datetime'],
			'acknowledged_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for programs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Program field definition hints.
	 */
	private function app_builder_default_program_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'program_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'strategic_objective'=>['type'=>'text'],
			'health_status'=>['type'=>'string', 'default'=>'green'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for projects.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Project field definition hints.
	 */
	private function app_builder_default_project_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['program'])){
			$fields['program_id']=['type'=>'integer', 'foreign_key_target'=>'programs'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}else{
			$fields['owner_id']=['type'=>'integer'];
		}
		return $fields + [
			'project_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'health_status'=>['type'=>'string', 'default'=>'green'],
			'start_date'=>['type'=>'date'],
			'due_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for project milestones.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Milestone field definition hints.
	 */
	private function app_builder_default_milestone_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'due_at'=>['type'=>'date', 'required'=>true],
			'health_status'=>['type'=>'string', 'default'=>'green'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for project tasks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Project task field definition hints.
	 */
	private function app_builder_default_project_task_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['milestone'])){
			$fields['milestone_id']=['type'=>'integer', 'foreign_key_target'=>'milestones'];
		}
		if(isset($entity_keys['user'])){
			$fields['assignee_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'title'=>['type'=>'string', 'required'=>true],
			'priority'=>['type'=>'string', 'default'=>'medium'],
			'due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for project dependencies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Project dependency field definition hints.
	 */
	private function app_builder_default_project_dependency_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'];
		}
		return $fields + [
			'depends_on_project_id'=>['type'=>'integer', 'foreign_key_target'=>'projects'],
			'depends_on_task_id'=>['type'=>'integer', 'foreign_key_target'=>'project tasks'],
			'blocker_summary'=>['type'=>'text', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for project risks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Project risk field definition hints.
	 */
	private function app_builder_default_project_risk_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'risk_summary'=>['type'=>'text', 'required'=>true],
			'probability'=>['type'=>'string', 'default'=>'medium'],
			'impact'=>['type'=>'string', 'default'=>'medium'],
			'mitigation_plan'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for project issues.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Project issue field definition hints.
	 */
	private function app_builder_default_project_issue_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'issue_summary'=>['type'=>'text', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'resolution_plan'=>['type'=>'text'],
			'resolved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for decision logs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Decision log field definition hints.
	 */
	private function app_builder_default_decision_log_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['user'])){
			$fields['decided_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'decision_summary'=>['type'=>'text', 'required'=>true],
			'rationale'=>['type'=>'text'],
			'decided_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'recorded'],
		];
	}

	/**
	 * Selects default fields for project stakeholders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Stakeholder field definition hints.
	 */
	private function app_builder_default_stakeholder_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['user'])){
			$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'email'=>['type'=>'string'],
			'stakeholder_role'=>['type'=>'string', 'default'=>'observer'],
			'update_frequency'=>['type'=>'string', 'default'=>'weekly'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for budgets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Budget field definition hints.
	 */
	private function app_builder_default_budget_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['costcenter'])){
			$fields['cost_center_id']=['type'=>'integer', 'foreign_key_target'=>'cost centers'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'period_start'=>['type'=>'date'],
			'period_end'=>['type'=>'date'],
		];
	}

	/**
	 * Selects default fields for expenses.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Expense field definition hints.
	 */
	private function app_builder_default_expense_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['costcenter'])){
			$fields['cost_center_id']=['type'=>'integer', 'foreign_key_target'=>'cost centers'];
		}
		if(isset($entity_keys['budget'])){
			$fields['budget_id']=['type'=>'integer', 'foreign_key_target'=>'budgets'];
		}
		if(isset($entity_keys['user'])){
			$fields['submitted_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'expense_date'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'submitted'],
		];
	}

	/**
	 * Selects default fields for IT service catalog records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Service field definition hints.
	 */
	private function app_builder_default_service_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['owning_team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		if(isset($entity_keys['user'])){
			$fields['service_owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'service_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text'],
			'criticality'=>['type'=>'string', 'default'=>'medium'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for service requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Service request field definition hints.
	 */
	private function app_builder_default_service_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['user'])){
			$fields['requester_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['assignee_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'request_key'=>['type'=>'string', 'required'=>true],
			'summary'=>['type'=>'string', 'required'=>true],
			'fulfillment_status'=>['type'=>'string', 'default'=>'open'],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for ITSM problem records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Problem record field definition hints.
	 */
	private function app_builder_default_problem_record_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['incident'])){
			$fields['incident_id']=['type'=>'integer', 'foreign_key_target'=>'incidents'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'problem_key'=>['type'=>'string', 'required'=>true],
			'summary'=>['type'=>'text', 'required'=>true],
			'root_cause'=>['type'=>'text'],
			'workaround'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'investigating'],
		];
	}

	/**
	 * Selects default fields for configuration items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Configuration item field definition hints.
	 */
	private function app_builder_default_configuration_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		return $fields + [
			'ci_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'ci_type'=>['type'=>'string', 'default'=>'application'],
			'relationships'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for releases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Release field definition hints.
	 */
	private function app_builder_default_release_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['changerequest'])){
			$fields['change_request_id']=['type'=>'integer', 'foreign_key_target'=>'change requests'];
		}
		return $fields + [
			'release_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'version'=>['type'=>'string'],
			'release_window_start'=>['type'=>'datetime'],
			'release_window_end'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'planned'],
		];
	}

	/**
	 * Selects default fields for maintenance windows.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Maintenance window field definition hints.
	 */
	private function app_builder_default_maintenance_window_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['release'])){
			$fields['release_id']=['type'=>'integer', 'foreign_key_target'=>'releases'];
		}
		return $fields + [
			'window_start'=>['type'=>'datetime', 'required'=>true],
			'window_end'=>['type'=>'datetime', 'required'=>true],
			'customer_message'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for knowledge articles.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Knowledge article field definition hints.
	 */
	private function app_builder_default_knowledge_article_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['user'])){
			$fields['author_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'article_key'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'body'=>['type'=>'text', 'required'=>true],
			'published_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for incidents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Incident field definition hints.
	 */
	private function app_builder_default_incident_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['configurationitem'])){
			$fields['configuration_item_id']=['type'=>'integer', 'foreign_key_target'=>'configuration items'];
		}
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}else{
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for change requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Change request field definition hints.
	 */
	private function app_builder_default_change_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['project'])){
			$fields['project_id']=['type'=>'integer', 'foreign_key_target'=>'projects'];
		}
		if(isset($entity_keys['service'])){
			$fields['service_id']=['type'=>'integer', 'foreign_key_target'=>'services'];
		}
		if(isset($entity_keys['configurationitem'])){
			$fields['configuration_item_id']=['type'=>'integer', 'foreign_key_target'=>'configuration items'];
		}
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		if(isset($entity_keys['user'])){
			$fields['requester_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}else{
			$fields['requester_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'change_summary'=>['type'=>'text', 'required'=>true],
			'change_type'=>['type'=>'string', 'default'=>'standard'],
			'planned_start_at'=>['type'=>'datetime'],
			'planned_end_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for security incidents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Security incident field definition hints.
	 */
	private function app_builder_default_security_incident_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		if(isset($entity_keys['alert'])){
			$fields['alert_id']=['type'=>'integer', 'foreign_key_target'=>'alerts'];
		}
		if(isset($entity_keys['user'])){
			$fields['incident_commander_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'incident_key'=>['type'=>'string', 'required'=>true, 'unique'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'incident_status'=>['type'=>'string', 'default'=>'triage'],
			'detected_at'=>['type'=>'datetime'],
			'contained_at'=>['type'=>'datetime'],
			'resolved_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for security alerts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Alert field definition hints.
	 */
	private function app_builder_default_alert_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['alertrule'])){
			$fields['alert_rule_id']=['type'=>'integer', 'foreign_key_target'=>'alert rules'];
		}
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		if(isset($entity_keys['securityincident'])){
			$fields['security_incident_id']=['type'=>'integer', 'foreign_key_target'=>'security incidents'];
		}
		return $fields + [
			'alert_key'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'source'=>['type'=>'string'],
			'signal_payload'=>['type'=>'json'],
			'triaged_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'new'],
		];
	}

	/**
	 * Selects default fields for security incident assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Incident assignment field definition hints.
	 */
	private function app_builder_default_incident_assignment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['user'])){
			$fields['user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		if(isset($entity_keys['team'])){
			$fields['team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'assignment_role'=>['type'=>'string', 'default'=>'responder'],
			'assigned_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'assigned'],
		];
	}

	/**
	 * Selects default fields for security incident timeline events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Incident timeline event field definition hints.
	 */
	private function app_builder_default_incident_timeline_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['user'])){
			$fields['actor_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'event_type'=>['type'=>'string', 'required'=>true],
			'event_summary'=>['type'=>'text', 'required'=>true],
			'evidence_ref'=>['type'=>'json'],
			'occurred_at'=>['type'=>'datetime', 'required'=>true],
		];
	}

	/**
	 * Selects default fields for security evidence items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Evidence item field definition hints.
	 */
	private function app_builder_default_evidence_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['user'])){
			$fields['collected_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'evidence_ref'=>['type'=>'json', 'required'=>true],
			'evidence_hash'=>['type'=>'string'],
			'chain_of_custody'=>['type'=>'json'],
			'collected_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for containment actions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Containment action field definition hints.
	 */
	private function app_builder_default_containment_action_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'action_type'=>['type'=>'string', 'required'=>true],
			'action_summary'=>['type'=>'text', 'required'=>true],
			'rollback_plan'=>['type'=>'text'],
			'executed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for remediation tasks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Remediation task field definition hints.
	 */
	private function app_builder_default_remediation_task_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['vulnerability'])){
			$fields['vulnerability_id']=['type'=>'integer', 'foreign_key_target'=>'vulnerabilities'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'remediation_summary'=>['type'=>'text', 'required'=>true],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for vulnerabilities.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Vulnerability field definition hints.
	 */
	private function app_builder_default_vulnerability_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		return $fields + [
			'vulnerability_key'=>['type'=>'string', 'required'=>true],
			'cve_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'discovered_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for postmortems.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Postmortem field definition hints.
	 */
	private function app_builder_default_postmortem_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$fields['security_incident_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'security incidents'];
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'summary'=>['type'=>'text', 'required'=>true],
			'root_cause'=>['type'=>'text'],
			'action_items'=>['type'=>'json'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for alert rules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Alert rule field definition hints.
	 */
	private function app_builder_default_alert_rule_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'notification_channel'=>['type'=>'string', 'default'=>'email'],
			'recipient_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'alert_status'=>['type'=>'string', 'default'=>'active'],
			'acknowledged_at'=>['type'=>'datetime'],
			'suppressed_until'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for audit events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Audit event field definition hints.
	 */
	private function app_builder_default_audit_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'actor_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'event_type'=>['type'=>'string', 'required'=>true],
			'metadata'=>['type'=>'json', 'required'=>true],
		];
	}

	/**
	 * Selects default fields for app-owned consent records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Consent record field definition hints.
	 */
	private function app_builder_default_consent_record_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['subject_email']=['type'=>'string', 'required'=>true];
		$fields['purpose_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['purpose_key']['unique_with']=array_merge([$scope_fields[0]], ['subject_email']);
		}
		return $fields + [
			'lawful_basis'=>['type'=>'string', 'default'=>'consent'],
			'consent_status'=>['type'=>'string', 'default'=>'granted'],
			'consented_at'=>['type'=>'datetime'],
			'revoked_at'=>['type'=>'datetime'],
			'data_region'=>['type'=>'string'],
			'retention_until'=>['type'=>'date'],
		];
	}

	/**
	 * Selects default fields for data subject requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data subject request field definition hints.
	 */
	private function app_builder_default_data_subject_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'requester_email'=>['type'=>'string', 'required'=>true],
			'request_type'=>['type'=>'string', 'default'=>'access'],
			'status'=>['type'=>'string', 'default'=>'open'],
			'due_at'=>['type'=>'datetime'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'fulfilled_at'=>['type'=>'datetime'],
			'exported_at'=>['type'=>'datetime'],
			'data_region'=>['type'=>'string'],
			'retention_until'=>['type'=>'date'],
		];
	}

	/**
	 * Selects default fields for data processing agreements.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Data processing agreement field definition hints.
	 */
	private function app_builder_default_data_processing_agreement_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['vendor'])){
			$fields['vendor_id']=['type'=>'integer', 'foreign_key_target'=>'vendors'];
		}
		if(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		return $fields + [
			'agreement_key'=>['type'=>'string', 'required'=>true],
			'processor_name'=>['type'=>'string', 'required'=>true],
			'data_categories'=>['type'=>'json', 'required'=>true],
			'processing_purposes'=>['type'=>'json', 'required'=>true],
			'data_region'=>['type'=>'string'],
			'scc_ref'=>['type'=>'json'],
			'effective_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for subprocessors.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Subprocessor field definition hints.
	 */
	private function app_builder_default_subprocessor_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataprocessingagreement'])){
			$fields['data_processing_agreement_id']=['type'=>'integer', 'foreign_key_target'=>'data processing agreements'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'service_description'=>['type'=>'text', 'required'=>true],
			'data_region'=>['type'=>'string'],
			'approved_at'=>['type'=>'datetime'],
			'notice_required'=>['type'=>'boolean', 'default'=>true],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for transfer impact assessments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Transfer impact assessment field definition hints.
	 */
	private function app_builder_default_transfer_impact_assessment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['dataprocessingagreement'])){
			$fields['data_processing_agreement_id']=['type'=>'integer', 'foreign_key_target'=>'data processing agreements'];
		}
		return $fields + [
			'source_region'=>['type'=>'string', 'required'=>true],
			'destination_region'=>['type'=>'string', 'required'=>true],
			'legal_basis'=>['type'=>'string', 'default'=>'scc'],
			'risk_summary'=>['type'=>'text', 'required'=>true],
			'safeguards'=>['type'=>'json', 'required'=>true],
			'reviewed_at'=>['type'=>'datetime'],
			'next_review_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for retention policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Retention policy field definition hints.
	 */
	private function app_builder_default_retention_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'policy_key'=>['type'=>'string', 'required'=>true],
			'subject_type'=>['type'=>'string', 'required'=>true],
			'classification'=>['type'=>'string', 'default'=>'confidential'],
			'retention_until'=>['type'=>'date'],
			'purge_after'=>['type'=>'date'],
			'legal_hold'=>['type'=>'boolean', 'default'=>false],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for legal holds.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Legal hold field definition hints.
	 */
	private function app_builder_default_legal_hold_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'subject_type'=>['type'=>'string', 'required'=>true],
			'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'legal_hold'=>['type'=>'boolean', 'default'=>true],
			'hold_reason'=>['type'=>'text', 'required'=>true],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'retention_until'=>['type'=>'date'],
			'released_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for processing activity records.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Processing activity field definition hints.
	 */
	private function app_builder_default_processing_activity_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'activity_key'=>['type'=>'string', 'required'=>true],
			'data_category'=>['type'=>'string', 'required'=>true],
			'lawful_basis'=>['type'=>'string', 'default'=>'contract'],
			'processor'=>['type'=>'string'],
			'data_region'=>['type'=>'string'],
			'classification'=>['type'=>'string', 'default'=>'confidential'],
			'retention_until'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for rollout plans.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Rollout plan field definition hints.
	 */
	private function app_builder_default_rollout_plan_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'flag_key'=>['type'=>'string', 'required'=>true],
			'rollout_wave'=>['type'=>'integer', 'default'=>1],
			'rollout_stage'=>['type'=>'string', 'default'=>'draft'],
			'target_scope'=>['type'=>'json', 'required'=>true],
			'blocker_reason'=>['type'=>'text'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'effective_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for migration runs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Migration run field definition hints.
	 */
	private function app_builder_default_migration_run_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['migration_id']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['migration_id']['unique_with']=[$scope_fields[0]];
		}else{
			$fields['migration_id']['unique']=true;
		}
		return $fields + [
			'dry_run'=>['type'=>'boolean', 'default'=>true],
			'dry_run_at'=>['type'=>'datetime'],
			'source_count'=>['type'=>'integer', 'default'=>0],
			'target_count'=>['type'=>'integer', 'default'=>0],
			'schema_version'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for backfill jobs.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Backfill job field definition hints.
	 */
	private function app_builder_default_backfill_job_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'backfill_id'=>['type'=>'string', 'required'=>true],
			'migration_id'=>['type'=>'string'],
			'source_count'=>['type'=>'integer', 'default'=>0],
			'target_count'=>['type'=>'integer', 'default'=>0],
			'dry_run'=>['type'=>'boolean', 'default'=>true],
			'backfilled_at'=>['type'=>'datetime'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for rollback plans.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Rollback plan field definition hints.
	 */
	private function app_builder_default_rollback_plan_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'rollback_reason'=>['type'=>'text', 'required'=>true],
			'previous_state'=>['type'=>'json', 'required'=>true],
			'restore_point_id'=>['type'=>'string'],
			'rollback_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'rollback_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'ready'],
		];
	}

	/**
	 * Selects default fields for compatibility windows.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Compatibility window field definition hints.
	 */
	private function app_builder_default_compatibility_window_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'version'=>['type'=>'string', 'required'=>true],
			'minimum_version'=>['type'=>'string'],
			'maximum_version'=>['type'=>'string'],
			'compatibility_window'=>['type'=>'string', 'required'=>true],
			'deprecated_at'=>['type'=>'datetime'],
			'sunset_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for change approvals.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Change approval field definition hints.
	 */
	private function app_builder_default_change_approval_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'change_request_id'=>['type'=>'string', 'required'=>true],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'approved_at'=>['type'=>'datetime'],
			'effective_at'=>['type'=>'datetime'],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'notes'=>['type'=>'text'],
		];
	}

	/**
	 * Selects context-aware default fields for subscriptions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Subscription field definition hints.
	 */
	private function app_builder_default_subscription_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}
		$fields['status']=['type'=>'string', 'default'=>'trialing'];
		$fields['plan_id']=isset($entity_keys['plan'])
			? ['type'=>'integer', 'foreign_key_target'=>'plans']
			: ['type'=>'string', 'not_foreign_key'=>true];
		return $fields;
	}

	/**
	 * Selects default fields for scheduled subscription lifecycle changes.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Subscription change field definition hints.
	 */
	private function app_builder_default_subscription_change_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['subscription'])){
			$fields['subscription_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'subscriptions'];
		}
		if(isset($entity_keys['plan'])){
			$fields['from_plan_id']=['type'=>'integer', 'foreign_key_target'=>'plans'];
			$fields['to_plan_id']=['type'=>'integer', 'foreign_key_target'=>'plans'];
		}else{
			$fields['from_plan_ref']=['type'=>'string', 'not_foreign_key'=>true];
			$fields['to_plan_ref']=['type'=>'string', 'not_foreign_key'=>true];
		}
		return $fields + [
			'change_type'=>['type'=>'string', 'default'=>'upgrade'],
			'proration_cents'=>['type'=>'integer', 'default'=>0],
			'effective_at'=>['type'=>'datetime'],
			'requested_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'approved_at'=>['type'=>'datetime'],
			'canceled_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects context-aware default fields for invoices.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Invoice field definition hints.
	 */
	private function app_builder_default_invoice_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['billingaccount'])){
			$fields['billing_account_id']=['type'=>'integer', 'foreign_key_target'=>'billing accounts'];
		}elseif(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['subscription'])){
			$fields['subscription_id']=['type'=>'integer', 'foreign_key_target'=>'subscriptions'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'invoice_number'=>['type'=>'string', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'total_cents'=>['type'=>'integer', 'required'=>true],
			'issued_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for invoice line items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Invoice line field definition hints.
	 */
	private function app_builder_default_invoice_line_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'invoices'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'description'=>['type'=>'string', 'required'=>true],
			'quantity'=>['type'=>'integer', 'default'=>1],
			'unit_price_cents'=>['type'=>'integer', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'revenue_start_at'=>['type'=>'date'],
			'revenue_end_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for billing tax rates.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Tax rate field definition hints.
	 */
	private function app_builder_default_tax_rate_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		$fields['tax_code']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['tax_code']['unique_with']=[$scope_fields[0], 'jurisdiction_code'];
		}
		return $fields + [
			'jurisdiction_code'=>['type'=>'string', 'required'=>true],
			'country_code'=>['type'=>'string', 'required'=>true],
			'region_code'=>['type'=>'string'],
			'rate_bps'=>['type'=>'integer', 'required'=>true],
			'inclusive'=>['type'=>'boolean', 'default'=>false],
			'effective_at'=>['type'=>'date'],
			'expires_at'=>['type'=>'date'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for customer billing tax exemptions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Tax exemption field definition hints.
	 */
	private function app_builder_default_tax_exemption_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['billingaccount'])){
			$fields['billing_account_id']=['type'=>'integer', 'foreign_key_target'=>'billing accounts'];
		}
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		return $fields + [
			'exemption_number'=>['type'=>'string', 'required'=>true, 'not_foreign_key'=>true],
			'exemption_type'=>['type'=>'string', 'default'=>'resale'],
			'certificate_ref'=>['type'=>'json', 'required'=>true],
			'country_code'=>['type'=>'string', 'required'=>true],
			'region_code'=>['type'=>'string'],
			'valid_from'=>['type'=>'date'],
			'valid_until'=>['type'=>'date'],
			'verified_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for payments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Payment field definition hints.
	 */
	private function app_builder_default_payment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'foreign_key_target'=>'invoices'];
		}elseif(isset($entity_keys['subscription'])){
			$fields['subscription_id']=['type'=>'integer', 'foreign_key_target'=>'subscriptions'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'provider_payment_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'reconciled_at'=>['type'=>'datetime'],
			'posted_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for payment disputes and chargebacks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Payment dispute field definition hints.
	 */
	private function app_builder_default_payment_dispute_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['payment'])){
			$fields['payment_id']=['type'=>'integer', 'foreign_key_target'=>'payments'];
		}
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'foreign_key_target'=>'invoices'];
		}
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		return $fields + [
			'provider_dispute_id'=>['type'=>'string', 'required'=>true, 'not_foreign_key'=>true],
			'dispute_reason'=>['type'=>'string', 'default'=>'fraudulent'],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'evidence_ref'=>['type'=>'json'],
			'response_due_at'=>['type'=>'datetime'],
			'resolved_at'=>['type'=>'datetime'],
			'outcome'=>['type'=>'string', 'default'=>'pending'],
			'status'=>['type'=>'string', 'default'=>'needs_response'],
		];
	}

	/**
	 * Selects default fields for failed-payment dunning attempts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Dunning attempt field definition hints.
	 */
	private function app_builder_default_dunning_attempt_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'invoices'];
		}
		if(isset($entity_keys['subscription'])){
			$fields['subscription_id']=['type'=>'integer', 'foreign_key_target'=>'subscriptions'];
		}
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		return $fields + [
			'attempt_number'=>['type'=>'integer', 'default'=>1],
			'failure_code'=>['type'=>'string', 'not_foreign_key'=>true],
			'failure_reason'=>['type'=>'text'],
			'next_retry_at'=>['type'=>'datetime'],
			'notification_ref'=>['type'=>'json'],
			'recovered_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for refunds.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Refund field definition hints.
	 */
	private function app_builder_default_refund_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['payment'])){
			$fields['payment_id']=['type'=>'integer', 'foreign_key_target'=>'payments'];
		}
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'foreign_key_target'=>'invoices'];
		}
		return $fields + [
			'provider_refund_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'refund_reason'=>['type'=>'text'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'refunded_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for credit memos.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Credit memo field definition hints.
	 */
	private function app_builder_default_credit_memo_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'foreign_key_target'=>'invoices'];
		}
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}
		return $fields + [
			'credit_memo_number'=>['type'=>'string', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'credit_reason'=>['type'=>'text'],
			'approved_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'issued_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for journal entries.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Journal entry field definition hints.
	 */
	private function app_builder_default_journal_entry_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'entry_number'=>['type'=>'string', 'required'=>true],
			'posting_date'=>['type'=>'date', 'required'=>true],
			'description'=>['type'=>'text'],
			'posted_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'posted_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for journal lines.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Journal line field definition hints.
	 */
	private function app_builder_default_journal_line_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['journalentry'])){
			$fields['journal_entry_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'journal entries'];
		}
		return $fields + [
			'account_code'=>['type'=>'string', 'required'=>true],
			'debit_cents'=>['type'=>'integer', 'default'=>0],
			'credit_cents'=>['type'=>'integer', 'default'=>0],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'memo'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'posted'],
		];
	}

	/**
	 * Selects default fields for revenue schedules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Revenue schedule field definition hints.
	 */
	private function app_builder_default_revenue_schedule_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['invoice'])){
			$fields['invoice_id']=['type'=>'integer', 'foreign_key_target'=>'invoices'];
		}
		if(isset($entity_keys['invoiceline'])){
			$fields['invoice_line_id']=['type'=>'integer', 'foreign_key_target'=>'invoice lines'];
		}
		return $fields + [
			'schedule_key'=>['type'=>'string', 'required'=>true],
			'total_revenue_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'period_start'=>['type'=>'date', 'required'=>true],
			'period_end'=>['type'=>'date', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for revenue recognition entries.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Revenue recognition field definition hints.
	 */
	private function app_builder_default_revenue_recognition_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['revenueschedule'])){
			$fields['revenue_schedule_id']=['type'=>'integer', 'foreign_key_target'=>'revenue schedules'];
		}
		if(isset($entity_keys['journalentry'])){
			$fields['journal_entry_id']=['type'=>'integer', 'foreign_key_target'=>'journal entries'];
		}
		return $fields + [
			'recognition_date'=>['type'=>'date', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'recognized_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for usage meters.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Usage meter field definition hints.
	 */
	private function app_builder_default_usage_meter_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['subscription'])){
			$fields['subscription_id']=['type'=>'integer', 'foreign_key_target'=>'subscriptions'];
		}elseif(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}
		return $fields + [
			'metric_key'=>['type'=>'string', 'required'=>true],
			'quantity'=>['type'=>'integer', 'required'=>true],
			'period_start'=>['type'=>'datetime', 'required'=>true],
			'period_end'=>['type'=>'datetime', 'required'=>true],
		];
	}

	/**
	 * Selects context-aware default fields for customers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Customer field definition hints.
	 */
	private function app_builder_default_customer_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'external_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for billing accounts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Billing account field definition hints.
	 */
	private function app_builder_default_billing_account_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'organizations'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'provider_customer_id'=>['type'=>'string', 'not_foreign_key'=>true],
			'billing_email'=>['type'=>'string'],
			'currency'=>['type'=>'string', 'default'=>'USD'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for accounts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Account field definition hints.
	 */
	private function app_builder_default_account_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'foreign_key_target'=>'organizations'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for onboarding cases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Onboarding case field definition hints.
	 */
	private function app_builder_default_onboarding_case_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['organization'])){
			$fields['organization_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'organizations'];
		}
		return $fields + [
			'case_number'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
			'assigned_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'due_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for KYC checks.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> KYC check field definition hints.
	 */
	private function app_builder_default_kyc_check_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['onboardingcase'])){
			$fields['onboarding_case_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'onboarding cases'];
		}elseif(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers'];
		}
		return $fields + [
			'provider_ref'=>['type'=>'string', 'not_foreign_key'=>true],
			'check_type'=>['type'=>'string', 'default'=>'identity'],
			'result'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'pending'],
			'checked_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for risk reviews.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Risk review field definition hints.
	 */
	private function app_builder_default_risk_review_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['onboardingcase'])){
			$fields['onboarding_case_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'onboarding cases'];
		}elseif(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers'];
		}
		return $fields + [
			'score'=>['type'=>'integer', 'default'=>0],
			'rating'=>['type'=>'string', 'default'=>'medium'],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'reviewed_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'reviewed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for contracts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Contract field definition hints.
	 */
	private function app_builder_default_contract_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'foreign_key_target'=>'workspaces'];
		}elseif(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'foreign_key_target'=>'tenants'];
		}
		if(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		if(isset($entity_keys['vendor'])){
			$fields['vendor_id']=['type'=>'integer', 'foreign_key_target'=>'vendors'];
		}
		return $fields + [
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'effective_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'renewal_notice_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for contract clauses.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Contract clause field definition hints.
	 */
	private function app_builder_default_contract_clause_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['contract'])){
			$fields['contract_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'contracts'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'clause_key'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'body'=>['type'=>'text', 'required'=>true],
			'risk_level'=>['type'=>'string', 'default'=>'medium'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for contract obligations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Contract obligation field definition hints.
	 */
	private function app_builder_default_contract_obligation_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['contract'])){
			$fields['contract_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'contracts'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'obligation_type'=>['type'=>'string', 'required'=>true],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for signature requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Signature request field definition hints.
	 */
	private function app_builder_default_signature_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['contract'])){
			$fields['contract_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'contracts'];
		}else{
			$fields=$this->app_builder_default_scope_field($entity_keys);
		}
		return $fields + [
			'signer_email'=>['type'=>'string', 'required'=>true],
			'provider_ref'=>['type'=>'string', 'not_foreign_key'=>true],
			'signature_status'=>['type'=>'string', 'default'=>'pending'],
			'sent_at'=>['type'=>'datetime'],
			'signed_at'=>['type'=>'datetime'],
			'evidence_ref'=>['type'=>'json'],
		];
	}

	/**
	 * Selects default fields for quality audits.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Quality audit field definition hints.
	 */
	private function app_builder_default_quality_audit_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['user'])){
			$fields['auditor_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'audit_key'=>['type'=>'string', 'required'=>true],
			'scope'=>['type'=>'text'],
			'scheduled_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for audit findings.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Audit finding field definition hints.
	 */
	private function app_builder_default_audit_finding_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['qualityaudit'])){
			$fields['quality_audit_id']=['type'=>'integer', 'foreign_key_target'=>'quality audits'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'finding_key'=>['type'=>'string', 'required'=>true],
			'finding_summary'=>['type'=>'text', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'evidence_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for nonconformances.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Nonconformance field definition hints.
	 */
	private function app_builder_default_nonconformance_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['auditfinding'])){
			$fields['audit_finding_id']=['type'=>'integer', 'foreign_key_target'=>'audit findings'];
		}
		if(isset($entity_keys['inspection'])){
			$fields['inspection_id']=['type'=>'integer', 'foreign_key_target'=>'inspections'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'nonconformance_key'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text', 'required'=>true],
			'disposition'=>['type'=>'string', 'default'=>'review'],
			'root_cause'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for CAPA plans.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> CAPA plan field definition hints.
	 */
	private function app_builder_default_capa_plan_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['nonconformance'])){
			$fields['nonconformance_id']=['type'=>'integer', 'foreign_key_target'=>'nonconformances'];
		}
		if(isset($entity_keys['deviation'])){
			$fields['deviation_id']=['type'=>'integer', 'foreign_key_target'=>'deviations'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'capa_key'=>['type'=>'string', 'required'=>true],
			'root_cause'=>['type'=>'text', 'required'=>true],
			'effectiveness_check_due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for corrective actions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Corrective action field definition hints.
	 */
	private function app_builder_default_corrective_action_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['capaplan'])){
			$fields['capa_plan_id']=['type'=>'integer', 'foreign_key_target'=>'capa plans'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'action_summary'=>['type'=>'text', 'required'=>true],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'effectiveness_verified_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for preventive actions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Preventive action field definition hints.
	 */
	private function app_builder_default_preventive_action_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['capaplan'])){
			$fields['capa_plan_id']=['type'=>'integer', 'foreign_key_target'=>'capa plans'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'action_summary'=>['type'=>'text', 'required'=>true],
			'verification_method'=>['type'=>'text'],
			'due_at'=>['type'=>'datetime'],
			'verified_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for quality deviations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Deviation field definition hints.
	 */
	private function app_builder_default_deviation_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['user'])){
			$fields['reported_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'deviation_key'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text', 'required'=>true],
			'impact_assessment'=>['type'=>'text'],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending_review'],
		];
	}

	/**
	 * Selects default fields for inspection checklists.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inspection checklist field definition hints.
	 */
	private function app_builder_default_inspection_checklist_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		return $fields + [
			'checklist_key'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'items_schema'=>['type'=>'json', 'required'=>true],
			'revision'=>['type'=>'string', 'default'=>'1'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for controlled documents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Document control field definition hints.
	 */
	private function app_builder_default_document_control_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'document_key'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'object_ref'=>['type'=>'json'],
			'revision'=>['type'=>'string', 'default'=>'1'],
			'effective_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for borrowers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Borrower field definition hints.
	 */
	private function app_builder_default_borrower_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'borrower_number'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'contact_email'=>['type'=>'string'],
			'onboarding_status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for loan applications.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Loan application field definition hints.
	 */
	private function app_builder_default_loan_application_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['borrower'])){
			$fields['borrower_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'borrowers'];
		}
		if(isset($entity_keys['loanproduct'])){
			$fields['loan_product_id']=['type'=>'integer', 'foreign_key_target'=>'loan products'];
		}
		return $fields + [
			'application_number'=>['type'=>'string', 'required'=>true],
			'requested_amount_cents'=>['type'=>'integer', 'required'=>true],
			'application_status'=>['type'=>'string', 'default'=>'submitted'],
			'submitted_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for loan products.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Loan product field definition hints.
	 */
	private function app_builder_default_loan_product_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'product_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'rate_rules'=>['type'=>'json', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for underwriting reviews.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Underwriting review field definition hints.
	 */
	private function app_builder_default_underwriting_review_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanapplication'])){
			$fields['loan_application_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan applications'];
		}
		if(isset($entity_keys['user'])){
			$fields['underwriter_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'review_number'=>['type'=>'string', 'required'=>true],
			'risk_score'=>['type'=>'integer', 'default'=>0],
			'recommendation'=>['type'=>'string', 'default'=>'pending'],
			'reviewed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for credit decisions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Credit decision field definition hints.
	 */
	private function app_builder_default_credit_decision_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanapplication'])){
			$fields['loan_application_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan applications'];
		}
		if(isset($entity_keys['underwritingreview'])){
			$fields['underwriting_review_id']=['type'=>'integer', 'foreign_key_target'=>'underwriting reviews'];
		}
		if(isset($entity_keys['user'])){
			$fields['decided_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'decision_reason'=>['type'=>'text', 'required'=>true],
			'approved_amount_cents'=>['type'=>'integer'],
			'decided_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for collateral items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Collateral item field definition hints.
	 */
	private function app_builder_default_collateral_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanapplication'])){
			$fields['loan_application_id']=['type'=>'integer', 'foreign_key_target'=>'loan applications'];
		}
		return $fields + [
			'collateral_type'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text'],
			'valuation_cents'=>['type'=>'integer'],
			'valuation_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'proposed'],
		];
	}

	/**
	 * Selects default fields for loan agreements.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Loan agreement field definition hints.
	 */
	private function app_builder_default_loan_agreement_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanapplication'])){
			$fields['loan_application_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan applications'];
		}
		if(isset($entity_keys['creditdecision'])){
			$fields['credit_decision_id']=['type'=>'integer', 'foreign_key_target'=>'credit decisions'];
		}
		return $fields + [
			'agreement_number'=>['type'=>'string', 'required'=>true],
			'generated_document_ref'=>['type'=>'json'],
			'signed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for loan accounts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Loan account field definition hints.
	 */
	private function app_builder_default_loan_account_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['borrower'])){
			$fields['borrower_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'borrowers'];
		}
		if(isset($entity_keys['loanagreement'])){
			$fields['loan_agreement_id']=['type'=>'integer', 'foreign_key_target'=>'loan agreements'];
		}
		return $fields + [
			'account_number'=>['type'=>'string', 'required'=>true],
			'principal_balance_cents'=>['type'=>'integer', 'default'=>0],
			'interest_rate_bps'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for loan disbursements.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Disbursement field definition hints.
	 */
	private function app_builder_default_disbursement_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanaccount'])){
			$fields['loan_account_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan accounts'];
		}
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'disbursement_number'=>['type'=>'string', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for repayment schedules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Repayment schedule field definition hints.
	 */
	private function app_builder_default_repayment_schedule_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanaccount'])){
			$fields['loan_account_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan accounts'];
		}
		return $fields + [
			'installment_number'=>['type'=>'integer', 'required'=>true],
			'due_at'=>['type'=>'datetime', 'required'=>true],
			'principal_due_cents'=>['type'=>'integer', 'required'=>true],
			'interest_due_cents'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for repayments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Repayment field definition hints.
	 */
	private function app_builder_default_repayment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanaccount'])){
			$fields['loan_account_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan accounts'];
		}
		if(isset($entity_keys['repaymentschedule'])){
			$fields['repayment_schedule_id']=['type'=>'integer', 'foreign_key_target'=>'repayment schedules'];
		}
		return $fields + [
			'payment_reference'=>['type'=>'string', 'not_foreign_key'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'reconciled_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'received'],
		];
	}

	/**
	 * Selects default fields for delinquency cases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Delinquency case field definition hints.
	 */
	private function app_builder_default_delinquency_case_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['loanaccount'])){
			$fields['loan_account_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'loan accounts'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'case_number'=>['type'=>'string', 'required'=>true],
			'days_past_due'=>['type'=>'integer', 'default'=>0],
			'stage'=>['type'=>'string', 'default'=>'early'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for collection actions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Collection action field definition hints.
	 */
	private function app_builder_default_collection_action_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['delinquencycase'])){
			$fields['delinquency_case_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'delinquency cases'];
		}
		if(isset($entity_keys['user'])){
			$fields['actor_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'action_type'=>['type'=>'string', 'required'=>true],
			'outcome'=>['type'=>'text'],
			'next_action_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for clinical studies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Study field definition hints.
	 */
	private function app_builder_default_study_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'study_number'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'protocol_version'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'planning'],
		];
	}

	/**
	 * Selects default fields for clinical study sites.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Study site field definition hints.
	 */
	private function app_builder_default_study_site_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'studies'];
		}
		return $fields + [
			'site_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'activated_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending_activation'],
		];
	}

	/**
	 * Selects default fields for study participants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Participant field definition hints.
	 */
	private function app_builder_default_participant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'studies'];
		}
		if(isset($entity_keys['studysite'])){
			$fields['study_site_id']=['type'=>'integer', 'foreign_key_target'=>'study sites'];
		}
		return $fields + [
			'participant_code'=>['type'=>'string', 'required'=>true],
			'enrolled_at'=>['type'=>'datetime'],
			'enrollment_status'=>['type'=>'string', 'default'=>'screening'],
		];
	}

	/**
	 * Selects default fields for informed consent forms.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Consent form field definition hints.
	 */
	private function app_builder_default_consent_form_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['participant'])){
			$fields['participant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'participants'];
		}
		return $fields + [
			'consent_version'=>['type'=>'string', 'required'=>true],
			'consented_at'=>['type'=>'datetime'],
			'object_ref'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for study visits.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Study visit field definition hints.
	 */
	private function app_builder_default_study_visit_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['participant'])){
			$fields['participant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'participants'];
		}
		return $fields + [
			'visit_name'=>['type'=>'string', 'required'=>true],
			'scheduled_at'=>['type'=>'datetime', 'required'=>true],
			'completed_at'=>['type'=>'datetime'],
			'adherence_status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for visit procedures.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Visit procedure field definition hints.
	 */
	private function app_builder_default_visit_procedure_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['studyvisit'])){
			$fields['study_visit_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'study visits'];
		}
		return $fields + [
			'procedure_code'=>['type'=>'string', 'required'=>true],
			'completed_at'=>['type'=>'datetime'],
			'result_summary'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for lab results.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Lab result field definition hints.
	 */
	private function app_builder_default_lab_result_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['participant'])){
			$fields['participant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'participants'];
		}
		if(isset($entity_keys['studyvisit'])){
			$fields['study_visit_id']=['type'=>'integer', 'foreign_key_target'=>'study visits'];
		}
		if(isset($entity_keys['user'])){
			$fields['reviewed_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'test_code'=>['type'=>'string', 'required'=>true],
			'result_payload'=>['type'=>'json', 'required'=>true],
			'review_status'=>['type'=>'string', 'default'=>'pending'],
			'reviewed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for adverse events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Adverse event field definition hints.
	 */
	private function app_builder_default_adverse_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['participant'])){
			$fields['participant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'participants'];
		}
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'foreign_key_target'=>'studies'];
		}
		return $fields + [
			'event_number'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'mild'],
			'reportable'=>['type'=>'boolean', 'default'=>false],
			'reported_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for protocol deviations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Protocol deviation field definition hints.
	 */
	private function app_builder_default_protocol_deviation_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'studies'];
		}
		if(isset($entity_keys['participant'])){
			$fields['participant_id']=['type'=>'integer', 'foreign_key_target'=>'participants'];
		}
		return $fields + [
			'deviation_number'=>['type'=>'string', 'required'=>true],
			'description'=>['type'=>'text', 'required'=>true],
			'impact_assessment'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'review'],
		];
	}

	/**
	 * Selects default fields for regulatory submissions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Regulatory submission field definition hints.
	 */
	private function app_builder_default_regulatory_submission_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'studies'];
		}
		return $fields + [
			'submission_number'=>['type'=>'string', 'required'=>true],
			'submission_type'=>['type'=>'string', 'required'=>true],
			'submitted_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for clinical monitor findings.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Monitor finding field definition hints.
	 */
	private function app_builder_default_monitor_finding_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['study'])){
			$fields['study_id']=['type'=>'integer', 'foreign_key_target'=>'studies'];
		}
		if(isset($entity_keys['studysite'])){
			$fields['study_site_id']=['type'=>'integer', 'foreign_key_target'=>'study sites'];
		}
		if(isset($entity_keys['user'])){
			$fields['monitor_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'finding_number'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'minor'],
			'description'=>['type'=>'text', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for property portfolios.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Portfolio field definition hints.
	 */
	private function app_builder_default_portfolio_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['owning_team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'portfolio_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for properties.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Property field definition hints.
	 */
	private function app_builder_default_property_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['portfolio'])){
			$fields['portfolio_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'portfolios'];
		}
		return $fields + [
			'property_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'address'=>['type'=>'text'],
			'availability_status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for property units.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Unit field definition hints.
	 */
	private function app_builder_default_unit_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['property'])){
			$fields['property_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'properties'];
		}
		return $fields + [
			'unit_number'=>['type'=>'string', 'required'=>true],
			'unit_type'=>['type'=>'string'],
			'availability_status'=>['type'=>'string', 'default'=>'available'],
			'market_rent_cents'=>['type'=>'integer'],
		];
	}

	/**
	 * Selects default fields for lease tenants without colliding with platform tenants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Tenant profile field definition hints.
	 */
	private function app_builder_default_tenant_profile_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'tenant_number'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'contact_email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for leases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Lease field definition hints.
	 */
	private function app_builder_default_lease_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['unit'])){
			$fields['unit_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'units'];
		}
		if(isset($entity_keys['tenantprofile'])){
			$fields['tenant_profile_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenant profiles'];
		}
		return $fields + [
			'lease_number'=>['type'=>'string', 'required'=>true],
			'starts_at'=>['type'=>'datetime', 'required'=>true],
			'ends_at'=>['type'=>'datetime'],
			'lease_status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for lease terms.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Lease term field definition hints.
	 */
	private function app_builder_default_lease_term_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		return $fields + [
			'term_key'=>['type'=>'string', 'required'=>true],
			'terms_payload'=>['type'=>'json', 'required'=>true],
			'effective_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for rent schedules.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Rent schedule field definition hints.
	 */
	private function app_builder_default_rent_schedule_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		return $fields + [
			'period_start'=>['type'=>'datetime', 'required'=>true],
			'period_end'=>['type'=>'datetime'],
			'rent_due_cents'=>['type'=>'integer', 'required'=>true],
			'due_at'=>['type'=>'datetime', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'scheduled'],
		];
	}

	/**
	 * Selects default fields for rent payments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Rent payment field definition hints.
	 */
	private function app_builder_default_rent_payment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		if(isset($entity_keys['rentschedule'])){
			$fields['rent_schedule_id']=['type'=>'integer', 'foreign_key_target'=>'rent schedules'];
		}
		return $fields + [
			'payment_reference'=>['type'=>'string', 'not_foreign_key'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'reconciled_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'received'],
		];
	}

	/**
	 * Selects default fields for security deposits.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Security deposit field definition hints.
	 */
	private function app_builder_default_security_deposit_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		return $fields + [
			'deposit_amount_cents'=>['type'=>'integer', 'required'=>true],
			'held_amount_cents'=>['type'=>'integer', 'default'=>0],
			'accounting_status'=>['type'=>'string', 'default'=>'held'],
		];
	}

	/**
	 * Selects default fields for lease renewal offers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Renewal offer field definition hints.
	 */
	private function app_builder_default_renewal_offer_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		return $fields + [
			'offer_number'=>['type'=>'string', 'required'=>true],
			'proposed_rent_cents'=>['type'=>'integer'],
			'offered_at'=>['type'=>'datetime'],
			'response_status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for arrears cases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Arrears case field definition hints.
	 */
	private function app_builder_default_arrears_case_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['lease'])){
			$fields['lease_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'leases'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'case_number'=>['type'=>'string', 'required'=>true],
			'balance_due_cents'=>['type'=>'integer', 'required'=>true],
			'days_past_due'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for insurance policyholders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Policyholder field definition hints.
	 */
	private function app_builder_default_policyholder_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'policyholder_number'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'contact_email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for insurance policies.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Policy field definition hints.
	 */
	private function app_builder_default_policy_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['policyholder'])){
			$fields['policyholder_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'policyholders'];
		}
		return $fields + [
			'policy_number'=>['type'=>'string', 'required'=>true],
			'line_of_business'=>['type'=>'string', 'required'=>true],
			'effective_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for policy coverage items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Coverage item field definition hints.
	 */
	private function app_builder_default_coverage_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['policy'])){
			$fields['policy_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'policies'];
		}
		return $fields + [
			'coverage_code'=>['type'=>'string', 'required'=>true],
			'limit_cents'=>['type'=>'integer'],
			'deductible_cents'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for insurance claims.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claim field definition hints.
	 */
	private function app_builder_default_claim_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['policy'])){
			$fields['policy_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'policies'];
		}
		if(isset($entity_keys['user'])){
			$fields['owner_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'claim_number'=>['type'=>'string', 'required'=>true],
			'loss_date'=>['type'=>'date'],
			'intake_channel'=>['type'=>'string', 'default'=>'manual'],
			'adjudication_status'=>['type'=>'string', 'default'=>'intake'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for claimants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claimant field definition hints.
	 */
	private function app_builder_default_claimant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'claimant_type'=>['type'=>'string', 'default'=>'insured'],
			'contact_email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for claim exposures.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claim exposure field definition hints.
	 */
	private function app_builder_default_claim_exposure_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		if(isset($entity_keys['coverageitem'])){
			$fields['coverage_item_id']=['type'=>'integer', 'foreign_key_target'=>'coverage items'];
		}
		return $fields + [
			'exposure_type'=>['type'=>'string', 'required'=>true],
			'estimated_loss_cents'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for claim reserves.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claim reserve field definition hints.
	 */
	private function app_builder_default_claim_reserve_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claimexposure'])){
			$fields['claim_exposure_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claim exposures'];
		}
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'reserve_amount_cents'=>['type'=>'integer', 'required'=>true],
			'change_reason'=>['type'=>'text'],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for claim payments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claim payment field definition hints.
	 */
	private function app_builder_default_claim_payment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		if(isset($entity_keys['claimant'])){
			$fields['claimant_id']=['type'=>'integer', 'foreign_key_target'=>'claimants'];
		}
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'payment_number'=>['type'=>'string', 'required'=>true],
			'amount_cents'=>['type'=>'integer', 'required'=>true],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for adjuster assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Adjuster assignment field definition hints.
	 */
	private function app_builder_default_adjuster_assignment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		if(isset($entity_keys['user'])){
			$fields['adjuster_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'assigned_at'=>['type'=>'datetime'],
			'workload_weight'=>['type'=>'integer', 'default'=>1],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for claim documents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Claim document field definition hints.
	 */
	private function app_builder_default_claim_document_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		if(isset($entity_keys['user'])){
			$fields['uploaded_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'document_type'=>['type'=>'string', 'required'=>true],
			'object_ref'=>['type'=>'json', 'required'=>true],
			'evidence_status'=>['type'=>'string', 'default'=>'received'],
		];
	}

	/**
	 * Selects default fields for fraud reviews.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Fraud review field definition hints.
	 */
	private function app_builder_default_fraud_review_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		if(isset($entity_keys['user'])){
			$fields['reviewer_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'indicator_summary'=>['type'=>'json', 'required'=>true],
			'risk_score'=>['type'=>'integer', 'default'=>0],
			'decision'=>['type'=>'string', 'default'=>'pending'],
			'reviewed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for subrogation cases.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Subrogation case field definition hints.
	 */
	private function app_builder_default_subrogation_case_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['claim'])){
			$fields['claim_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'claims'];
		}
		return $fields + [
			'recovery_target'=>['type'=>'string', 'required'=>true],
			'expected_recovery_cents'=>['type'=>'integer'],
			'recovered_cents'=>['type'=>'integer', 'default'=>0],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for manufacturing plants.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Plant field definition hints.
	 */
	private function app_builder_default_plant_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['owning_team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'plant_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'calendar_rules'=>['type'=>'json'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for manufacturing work centers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Work center field definition hints.
	 */
	private function app_builder_default_work_center_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['plant'])){
			$fields['plant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'plants'];
		}
		return $fields + [
			'work_center_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'capacity_units_per_hour'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for manufacturing equipment.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Equipment field definition hints.
	 */
	private function app_builder_default_equipment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['workcenter'])){
			$fields['work_center_id']=['type'=>'integer', 'foreign_key_target'=>'work centers'];
		}elseif(isset($entity_keys['plant'])){
			$fields['plant_id']=['type'=>'integer', 'foreign_key_target'=>'plants'];
		}
		return $fields + [
			'equipment_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'maintenance_status'=>['type'=>'string', 'default'=>'operational'],
			'last_serviced_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for bills of materials.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Bill of material field definition hints.
	 */
	private function app_builder_default_bill_of_material_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['product'])){
			$fields['product_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'products'];
		}
		return $fields + [
			'bom_number'=>['type'=>'string', 'required'=>true],
			'version'=>['type'=>'string', 'default'=>'1'],
			'effective_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for BOM components.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> BOM component field definition hints.
	 */
	private function app_builder_default_bom_component_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['billofmaterial'])){
			$fields['bill_of_material_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'bills of materials'];
		}
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'inventory items'];
		}elseif(isset($entity_keys['product'])){
			$fields['component_product_id']=['type'=>'integer', 'foreign_key_target'=>'products'];
		}
		return $fields + [
			'quantity_required'=>['type'=>'integer', 'required'=>true],
			'uom'=>['type'=>'string', 'default'=>'each'],
			'scrap_percent'=>['type'=>'integer', 'default'=>0],
		];
	}

	/**
	 * Selects default fields for production orders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Production order field definition hints.
	 */
	private function app_builder_default_production_order_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['plant'])){
			$fields['plant_id']=['type'=>'integer', 'foreign_key_target'=>'plants'];
		}
		if(isset($entity_keys['product'])){
			$fields['product_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'products'];
		}
		if(isset($entity_keys['billofmaterial'])){
			$fields['bill_of_material_id']=['type'=>'integer', 'foreign_key_target'=>'bills of materials'];
		}
		return $fields + [
			'production_order_number'=>['type'=>'string', 'required'=>true],
			'quantity_planned'=>['type'=>'integer', 'required'=>true],
			'planned_start_at'=>['type'=>'datetime'],
			'planned_end_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'planned'],
		];
	}

	/**
	 * Selects default fields for manufacturing routing steps.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Routing step field definition hints.
	 */
	private function app_builder_default_routing_step_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['product'])){
			$fields['product_id']=['type'=>'integer', 'foreign_key_target'=>'products'];
		}
		if(isset($entity_keys['workcenter'])){
			$fields['work_center_id']=['type'=>'integer', 'foreign_key_target'=>'work centers'];
		}
		return $fields + [
			'step_sequence'=>['type'=>'integer', 'required'=>true],
			'operation_name'=>['type'=>'string', 'required'=>true],
			'standard_minutes'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for production material requirements.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Material requirement field definition hints.
	 */
	private function app_builder_default_material_requirement_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['productionorder'])){
			$fields['production_order_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'production orders'];
		}
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['stocklot'])){
			$fields['allocated_stock_lot_id']=['type'=>'integer', 'foreign_key_target'=>'stock lots'];
		}
		return $fields + [
			'quantity_required'=>['type'=>'integer', 'required'=>true],
			'quantity_allocated'=>['type'=>'integer', 'default'=>0],
			'allocation_status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for shop-floor work order operations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Work order operation field definition hints.
	 */
	private function app_builder_default_work_order_operation_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['productionorder'])){
			$fields['production_order_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'production orders'];
		}
		if(isset($entity_keys['routingstep'])){
			$fields['routing_step_id']=['type'=>'integer', 'foreign_key_target'=>'routing steps'];
		}
		if(isset($entity_keys['equipment'])){
			$fields['equipment_id']=['type'=>'integer', 'foreign_key_target'=>'equipment'];
		}
		if(isset($entity_keys['user'])){
			$fields['operator_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'started_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'actual_minutes'=>['type'=>'integer'],
			'operation_status'=>['type'=>'string', 'default'=>'queued'],
		];
	}

	/**
	 * Selects default fields for manufacturing quality inspections.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Quality inspection field definition hints.
	 */
	private function app_builder_default_quality_inspection_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['productionorder'])){
			$fields['production_order_id']=['type'=>'integer', 'foreign_key_target'=>'production orders'];
		}
		if(isset($entity_keys['stocklot'])){
			$fields['stock_lot_id']=['type'=>'integer', 'foreign_key_target'=>'stock lots'];
		}
		if(isset($entity_keys['user'])){
			$fields['inspected_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'inspection_number'=>['type'=>'string', 'required'=>true],
			'result'=>['type'=>'string', 'default'=>'pending'],
			'quality_hold'=>['type'=>'boolean', 'default'=>false],
			'inspected_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for equipment downtime events.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Downtime event field definition hints.
	 */
	private function app_builder_default_downtime_event_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['equipment'])){
			$fields['equipment_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'equipment'];
		}
		if(isset($entity_keys['workcenter'])){
			$fields['work_center_id']=['type'=>'integer', 'foreign_key_target'=>'work centers'];
		}
		return $fields + [
			'reason_code'=>['type'=>'string', 'required'=>true],
			'started_at'=>['type'=>'datetime', 'required'=>true],
			'ended_at'=>['type'=>'datetime'],
			'notes'=>['type'=>'text'],
		];
	}

	/**
	 * Selects default fields for maintenance requests.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Maintenance request field definition hints.
	 */
	private function app_builder_default_maintenance_request_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['equipment'])){
			$fields['equipment_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'equipment'];
		}
		if(isset($entity_keys['unit'])){
			$fields['unit_id']=['type'=>'integer', 'foreign_key_target'=>'units'];
		}elseif(isset($entity_keys['property'])){
			$fields['property_id']=['type'=>'integer', 'foreign_key_target'=>'properties'];
		}
		if(isset($entity_keys['downtimeevent'])){
			$fields['downtime_event_id']=['type'=>'integer', 'foreign_key_target'=>'downtime events'];
		}
		if(isset($entity_keys['user'])){
			$fields['requested_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'request_number'=>['type'=>'string', 'required'=>true],
			'priority'=>['type'=>'string', 'default'=>'normal'],
			'preventive'=>['type'=>'boolean', 'default'=>false],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for warehouses.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Warehouse field definition hints.
	 */
	private function app_builder_default_warehouse_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['team'])){
			$fields['owning_team_id']=['type'=>'integer', 'foreign_key_target'=>'teams'];
		}
		return $fields + [
			'warehouse_code'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'region'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for stock locations.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Stock location field definition hints.
	 */
	private function app_builder_default_stock_location_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['warehouse'])){
			$fields['warehouse_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'warehouse'];
		}
		return $fields + [
			'location_code'=>['type'=>'string', 'required'=>true],
			'zone'=>['type'=>'string'],
			'bin_type'=>['type'=>'string', 'default'=>'storage'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for stock lots.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Stock lot field definition hints.
	 */
	private function app_builder_default_stock_lot_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['stocklocation'])){
			$fields['stock_location_id']=['type'=>'integer', 'foreign_key_target'=>'stock locations'];
		}
		return $fields + [
			'lot_number'=>['type'=>'string', 'required'=>true],
			'quantity_on_hand'=>['type'=>'integer', 'default'=>0],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'available'],
		];
	}

	/**
	 * Selects default fields for serial numbers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Serial number field definition hints.
	 */
	private function app_builder_default_serial_number_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['stocklot'])){
			$fields['stock_lot_id']=['type'=>'integer', 'foreign_key_target'=>'stock lots'];
		}
		return $fields + [
			'serial_number'=>['type'=>'string', 'required'=>true],
			'serial_status'=>['type'=>'string', 'default'=>'available'],
			'last_seen_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for inventory transfers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inventory transfer field definition hints.
	 */
	private function app_builder_default_inventory_transfer_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['stocklocation'])){
			$fields['from_stock_location_id']=['type'=>'integer', 'foreign_key_target'=>'stock locations'];
			$fields['to_stock_location_id']=['type'=>'integer', 'foreign_key_target'=>'stock locations'];
		}
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'transfer_key'=>['type'=>'string', 'required'=>true],
			'quantity'=>['type'=>'integer', 'required'=>true],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects default fields for goods receipts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Goods receipt field definition hints.
	 */
	private function app_builder_default_goods_receipt_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['purchaseorder'])){
			$fields['purchase_order_id']=['type'=>'integer', 'foreign_key_target'=>'purchase orders'];
		}
		if(isset($entity_keys['supplier'])){
			$fields['supplier_id']=['type'=>'integer', 'foreign_key_target'=>'suppliers'];
		}
		if(isset($entity_keys['warehouse'])){
			$fields['warehouse_id']=['type'=>'integer', 'foreign_key_target'=>'warehouse'];
		}
		return $fields + [
			'receipt_number'=>['type'=>'string', 'required'=>true],
			'received_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'received'],
		];
	}

	/**
	 * Selects default fields for pick lists.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Pick list field definition hints.
	 */
	private function app_builder_default_pick_list_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['shipment'])){
			$fields['shipment_id']=['type'=>'integer', 'foreign_key_target'=>'shipments'];
		}
		if(isset($entity_keys['warehouse'])){
			$fields['warehouse_id']=['type'=>'integer', 'foreign_key_target'=>'warehouse'];
		}
		if(isset($entity_keys['user'])){
			$fields['assigned_to']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'pick_list_number'=>['type'=>'string', 'required'=>true],
			'pick_status'=>['type'=>'string', 'default'=>'open'],
			'completed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for cycle counts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Cycle count field definition hints.
	 */
	private function app_builder_default_cycle_count_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['warehouse'])){
			$fields['warehouse_id']=['type'=>'integer', 'foreign_key_target'=>'warehouse'];
		}
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['user'])){
			$fields['counted_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'count_key'=>['type'=>'string', 'required'=>true],
			'expected_quantity'=>['type'=>'integer'],
			'counted_quantity'=>['type'=>'integer', 'required'=>true],
			'variance_reason'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'review'],
		];
	}

	/**
	 * Selects default fields for inventory adjustments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inventory adjustment field definition hints.
	 */
	private function app_builder_default_inventory_adjustment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['cyclecount'])){
			$fields['cycle_count_id']=['type'=>'integer', 'foreign_key_target'=>'cycle counts'];
		}
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'foreign_key_target'=>'inventory items'];
		}
		if(isset($entity_keys['user'])){
			$fields['approved_by']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		return $fields + [
			'adjustment_quantity'=>['type'=>'integer', 'required'=>true],
			'adjustment_reason'=>['type'=>'text', 'required'=>true],
			'approved_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for suppliers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Supplier field definition hints.
	 */
	private function app_builder_default_supplier_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'name'=>['type'=>'string', 'required'=>true],
			'supplier_code'=>['type'=>'string'],
			'contact_email'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for purchase orders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Purchase order field definition hints.
	 */
	private function app_builder_default_purchase_order_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['purchaserequest'])){
			$fields['purchase_request_id']=['type'=>'integer', 'foreign_key_target'=>'purchase requests'];
		}
		if(isset($entity_keys['supplier'])){
			$fields['supplier_id']=['type'=>'integer', 'foreign_key_target'=>'suppliers'];
		}elseif(isset($entity_keys['vendor'])){
			$fields['vendor_id']=['type'=>'integer', 'foreign_key_target'=>'vendors'];
		}
		return $fields + [
			'purchase_order_number'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for shipments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Shipment field definition hints.
	 */
	private function app_builder_default_shipment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['purchaseorder'])){
			$fields['purchase_order_id']=['type'=>'integer', 'foreign_key_target'=>'purchase orders'];
		}
		if(isset($entity_keys['warehouse'])){
			$fields['warehouse_id']=['type'=>'integer', 'foreign_key_target'=>'warehouse'];
		}
		return $fields + [
			'shipment_number'=>['type'=>'string'],
			'tracking_number'=>['type'=>'string'],
			'shipped_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects default fields for customer service sites.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Site field definition hints.
	 */
	private function app_builder_default_site_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers'];
		}
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'address'=>['type'=>'text'],
			'region'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for field-service assets.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Asset field definition hints.
	 */
	private function app_builder_default_asset_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['site'])){
			$fields['site_id']=['type'=>'integer', 'foreign_key_target'=>'sites'];
		}elseif(isset($entity_keys['department'])){
			$fields['department_id']=['type'=>'integer', 'foreign_key_target'=>'departments'];
		}
		return $fields + [
			'asset_tag'=>['type'=>'string', 'required'=>true],
			'serial_number'=>['type'=>'string'],
			'name'=>['type'=>'string', 'required'=>true],
			'asset_status'=>['type'=>'string', 'default'=>'active'],
			'last_serviced_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects default fields for work orders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Work order field definition hints.
	 */
	private function app_builder_default_work_order_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		if(isset($entity_keys['unit'])){
			$fields['unit_id']=['type'=>'integer', 'foreign_key_target'=>'units'];
		}elseif(isset($entity_keys['property'])){
			$fields['property_id']=['type'=>'integer', 'foreign_key_target'=>'properties'];
		}
		if(isset($entity_keys['maintenancerequest'])){
			$fields['maintenance_request_id']=['type'=>'integer', 'foreign_key_target'=>'maintenance requests'];
		}
		if(isset($entity_keys['vendor'])){
			$fields['vendor_id']=['type'=>'integer', 'foreign_key_target'=>'vendors'];
		}
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}
		return $fields + [
			'work_order_number'=>['type'=>'string', 'required'=>true],
			'priority'=>['type'=>'string', 'default'=>'normal'],
			'scheduled_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'work_order_status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for technician assignments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Technician assignment field definition hints.
	 */
	private function app_builder_default_technician_assignment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['workorder'])){
			$fields['work_order_id']=['type'=>'integer', 'foreign_key_target'=>'work orders'];
		}
		$fields['technician_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		return $fields + [
			'assigned_at'=>['type'=>'datetime'],
			'accepted_at'=>['type'=>'datetime'],
			'completed_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'assigned'],
		];
	}

	/**
	 * Selects default fields for inspections.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inspection field definition hints.
	 */
	private function app_builder_default_inspection_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['workorder'])){
			$fields['work_order_id']=['type'=>'integer', 'foreign_key_target'=>'work orders'];
		}
		if(isset($entity_keys['asset'])){
			$fields['asset_id']=['type'=>'integer', 'foreign_key_target'=>'assets'];
		}
		return $fields + [
			'checklist_key'=>['type'=>'string'],
			'inspected_by'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'inspected_at'=>['type'=>'datetime'],
			'result'=>['type'=>'string', 'default'=>'pending'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects default fields for inspection checklist items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inspection item field definition hints.
	 */
	private function app_builder_default_inspection_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['inspection'])){
			$fields['inspection_id']=['type'=>'integer', 'foreign_key_target'=>'inspections'];
		}
		return $fields + [
			'item_key'=>['type'=>'string', 'required'=>true],
			'label'=>['type'=>'string', 'required'=>true],
			'result'=>['type'=>'string', 'default'=>'pending'],
			'notes'=>['type'=>'text'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for parts consumed by work orders.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Part usage field definition hints.
	 */
	private function app_builder_default_part_usage_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['workorder'])){
			$fields['work_order_id']=['type'=>'integer', 'foreign_key_target'=>'work orders'];
		}
		if(isset($entity_keys['inventoryitem'])){
			$fields['inventory_item_id']=['type'=>'integer', 'foreign_key_target'=>'inventory items'];
		}
		return $fields + [
			'sku'=>['type'=>'string', 'required'=>true],
			'quantity'=>['type'=>'integer', 'required'=>true],
			'unit_cost_cents'=>['type'=>'integer'],
			'used_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'posted'],
		];
	}

	/**
	 * Selects default fields for inventory items.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Inventory item field definition hints.
	 */
	private function app_builder_default_inventory_item_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		return $this->app_builder_default_scope_field($entity_keys) + [
			'sku'=>['type'=>'string', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
			'quantity_on_hand'=>['type'=>'integer', 'default'=>0],
			'reorder_point'=>['type'=>'integer'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects default fields for service contracts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Service contract field definition hints.
	 */
	private function app_builder_default_service_contract_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}
		if(isset($entity_keys['site'])){
			$fields['site_id']=['type'=>'integer', 'foreign_key_target'=>'sites'];
		}
		return $fields + [
			'contract_number'=>['type'=>'string', 'required'=>true],
			'coverage_level'=>['type'=>'string', 'default'=>'standard'],
			'effective_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for vendor contacts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Vendor contact field definition hints.
	 */
	private function app_builder_default_vendor_contact_fields(array $args): array {
		return [
			'vendor_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'vendors'],
			'name'=>['type'=>'string', 'required'=>true],
			'email'=>['type'=>'string', 'required'=>true],
			'phone'=>['type'=>'string'],
			'is_primary'=>['type'=>'boolean', 'default'=>false],
		];
	}

	/**
	 * Selects context-aware default fields for questionnaires.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Questionnaire field definition hints.
	 */
	private function app_builder_default_questionnaire_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'workspaces'];
		}
		return $fields + [
			'title'=>['type'=>'string', 'required'=>true],
			'version'=>['type'=>'integer', 'default'=>1],
			'schema'=>['type'=>'json', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for questionnaire responses.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Questionnaire response field definition hints.
	 */
	private function app_builder_default_questionnaire_response_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['vendor'])){
			$fields['vendor_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'vendors'];
		}
		if(isset($entity_keys['questionnaire'])){
			$fields['questionnaire_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'questionnaires'];
		}
		if(isset($entity_keys['user'])){
			$fields['submitted_by_user_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		}
		if($fields===[]){
			$fields=[
				'subject_type'=>['type'=>'string', 'required'=>true],
				'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			];
		}
		return $fields + [
			'answers'=>['type'=>'json', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'in_progress'],
			'submitted_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for risk assessments.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Risk assessment field definition hints.
	 */
	private function app_builder_default_risk_assessment_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$subject=[];
		if(isset($entity_keys['vendor'])){
			$subject=['vendor_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'vendors']];
		}elseif(isset($entity_keys['account'])){
			$subject=['account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts']];
		}else{
			$subject=[
				'subject_type'=>['type'=>'string', 'required'=>true],
				'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			];
		}
		return $subject + [
			'score'=>['type'=>'integer', 'default'=>0],
			'rating'=>['type'=>'string', 'default'=>'medium'],
			'findings'=>['type'=>'json'],
			'reviewed_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'reviewed_at'=>['type'=>'datetime'],
		];
	}

	/**
	 * Selects context-aware default fields for policy versions.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Policy version field definition hints.
	 */
	private function app_builder_default_policy_version_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['policy'])){
			$fields['policy_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'policies'];
		}
		return $fields + [
			'version_number'=>['type'=>'string', 'required'=>true],
			'title'=>['type'=>'string', 'required'=>true],
			'content_ref'=>['type'=>'json'],
			'effective_at'=>['type'=>'datetime'],
			'expires_at'=>['type'=>'datetime'],
			'approved_by_user_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for compliance risk findings.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Risk finding field definition hints.
	 */
	private function app_builder_default_risk_finding_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['control'])){
			$fields['control_id']=['type'=>'integer', 'foreign_key_target'=>'controls'];
		}
		if(isset($entity_keys['evidencerequest'])){
			$fields['evidence_request_id']=['type'=>'integer', 'foreign_key_target'=>'evidence requests'];
		}
		return $fields + [
			'title'=>['type'=>'string', 'required'=>true],
			'severity'=>['type'=>'string', 'default'=>'medium'],
			'description'=>['type'=>'text'],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'due_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for compliance review cycles.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Review cycle field definition hints.
	 */
	private function app_builder_default_review_cycle_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		return $fields + [
			'name'=>['type'=>'string', 'required'=>true],
			'starts_at'=>['type'=>'datetime'],
			'due_at'=>['type'=>'datetime'],
			'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
			'status'=>['type'=>'string', 'default'=>'planned'],
		];
	}

	/**
	 * Selects context-aware default fields for approval workflow headers.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Approval workflow field definition hints.
	 */
	private function app_builder_default_approval_workflow_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenants'];
		}
		return $fields + [
			'subject_type'=>['type'=>'string', 'required'=>true],
			'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
		];
	}

	/**
	 * Selects context-aware default fields for documents.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Document field definition hints.
	 */
	private function app_builder_default_document_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$task=strtolower((string)($args['task'] ?? ''));
		$is_procurement=isset($entity_keys['purchaseorder']) || isset($entity_keys['purchaserequest']) || str_contains($task, 'procurement') || str_contains($task, 'purchase order') || str_contains($task, 'purchase request');
		if($is_procurement){
			return [
				'purchase_order_id'=>['type'=>'integer', 'foreign_key_target'=>'purchase orders'],
				'storage_ref'=>['type'=>'json'],
				'name'=>['type'=>'string', 'required'=>true],
			];
		}
		if(isset($entity_keys['vendor']) || isset($entity_keys['employee']) || str_contains($task, 'vendor onboarding') || str_contains($task, 'compliance') || str_contains($task, 'evidence') || str_contains($task, 'document upload') || str_contains($task, 'regulated') || str_contains($task, 'audit retention')){
			$fields=$this->app_builder_default_scope_field($entity_keys);
			if(isset($entity_keys['vendor'])){
				$fields['vendor_id']=['type'=>'integer', 'foreign_key_target'=>'vendors'];
			}
			if(isset($entity_keys['employee'])){
				$fields['employee_id']=['type'=>'integer', 'foreign_key_target'=>'employees'];
			}
			return $fields + [
				'object_ref'=>['type'=>'json', 'required'=>true],
				'document_type'=>['type'=>'string', 'default'=>'other'],
				'classification'=>['type'=>'string', 'default'=>'confidential'],
				'expires_at'=>['type'=>'datetime'],
			];
		}
		return [
			'subject_type'=>['type'=>'string', 'required'=>true],
			'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'storage_ref'=>['type'=>'json', 'required'=>true],
			'name'=>['type'=>'string', 'required'=>true],
		];
	}

	/**
	 * Selects context-aware default fields for customer trust-center artifacts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Trust-center artifact field definition hints.
	 */
	private function app_builder_default_trust_center_artifact_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		$scope_fields=array_keys($fields);
		if(isset($entity_keys['customer'])){
			$fields['customer_id']=['type'=>'integer', 'foreign_key_target'=>'customers'];
		}
		if(isset($entity_keys['account'])){
			$fields['account_id']=['type'=>'integer', 'foreign_key_target'=>'accounts'];
		}
		$fields['artifact_key']=['type'=>'string', 'required'=>true];
		if($scope_fields!==[]){
			$fields['artifact_key']['unique_with']=[$scope_fields[0]];
		}else{
			$fields['artifact_key']['unique']=true;
		}
		return $fields + [
			'title'=>['type'=>'string', 'required'=>true],
			'artifact_type'=>['type'=>'string', 'default'=>'soc2_report'],
			'object_ref'=>['type'=>'json', 'required'=>true],
			'access_level'=>['type'=>'string', 'default'=>'restricted'],
			'nda_required'=>['type'=>'boolean', 'default'=>true],
			'valid_from'=>['type'=>'date'],
			'valid_until'=>['type'=>'date'],
			'published_at'=>['type'=>'datetime'],
			'status'=>['type'=>'string', 'default'=>'draft'],
		];
	}

	/**
	 * Selects context-aware default fields for webhook endpoints.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Webhook endpoint field definition hints.
	 */
	private function app_builder_default_webhook_endpoint_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=[];
		if(isset($entity_keys['tenant'])){
			$fields['tenant_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'tenants'];
		}elseif(isset($entity_keys['workspace'])){
			$fields['workspace_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'workspaces'];
		}
		return $fields + [
			'url'=>['type'=>'string', 'required'=>true],
			'secret_hash'=>['type'=>'string', 'required'=>true],
			'events'=>['type'=>'json', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'active'],
		];
	}

	/**
	 * Selects context-aware default fields for webhook delivery attempts.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Webhook delivery field definition hints.
	 */
	private function app_builder_default_webhook_delivery_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$fields=$this->app_builder_default_scope_field($entity_keys);
		if(isset($entity_keys['webhookendpoint'])){
			$fields['webhook_endpoint_id']=['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'webhook endpoints'];
		}
		$idempotency_scope=[];
		if(isset($fields['webhook_endpoint_id'])){
			$idempotency_scope=['webhook_endpoint_id'];
		}else{
			$scope_fields=array_keys($fields);
			if($scope_fields!==[]){
				$idempotency_scope=[$scope_fields[0]];
			}
		}
		$fields['event_type']=['type'=>'string', 'required'=>true];
		$fields['payload']=['type'=>'json', 'required'=>true];
		$fields['idempotency_key']=['type'=>'string', 'required'=>true];
		if($idempotency_scope!==[]){
			$fields['idempotency_key']['unique_with']=$idempotency_scope;
		}else{
			$fields['idempotency_key']['unique']=true;
		}
		return $fields + [
			'request_hash'=>['type'=>'string'],
			'status'=>['type'=>'string', 'default'=>'pending'],
			'retry_count'=>['type'=>'integer', 'default'=>0],
			'next_retry_at'=>['type'=>'datetime'],
			'last_attempt_at'=>['type'=>'datetime'],
			'delivered_at'=>['type'=>'datetime'],
			'dead_letter_reason'=>['type'=>'text'],
		];
	}

	/**
	 * Selects context-aware default fields for approval workflow steps.
	 *
	 * Approval steps are a reusable workflow primitive, not procurement-specific.
	 * Keep purchase-request coupling only when the task or entities are clearly
	 * procurement-shaped; otherwise use a generic app-owned workflow reference.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Approval step field definition hints.
	 */
	private function app_builder_default_approval_step_fields(array $args): array {
		$entity_keys=$this->app_builder_entity_key_set($args);
		$task=strtolower((string)($args['task'] ?? ''));
		$is_procurement=isset($entity_keys['purchaserequest']) || isset($entity_keys['purchaseorder']) || str_contains($task, 'procurement') || str_contains($task, 'purchase request') || str_contains($task, 'purchase order');
		if($is_procurement){
			return [
				'purchase_request_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'purchase requests'],
				'approver_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
				'status'=>['type'=>'string', 'default'=>'pending'],
			];
		}
		if(isset($entity_keys['approvalworkflow'])){
			return [
				'approval_workflow_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'approval workflows'],
				'assignee_user_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
				'decision'=>['type'=>'string', 'default'=>'pending'],
				'decided_at'=>['type'=>'datetime'],
				'notes'=>['type'=>'text'],
			];
		}
		return [
			'subject_type'=>['type'=>'string', 'required'=>true],
			'subject_id'=>['type'=>'integer', 'required'=>true, 'not_foreign_key'=>true],
			'assignee_user_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'users'],
			'decision'=>['type'=>'string', 'default'=>'pending'],
		];
	}

	/**
	 * Selects context-aware default fields for tickets when explicit fields are absent.
	 *
	 * @param array<string,mixed> $args Builder arguments.
	 * @return array<string,mixed> Ticket field definition hints.
	 */
	private function app_builder_default_ticket_fields(array $args): array {
		$entity_keys=[];
		foreach(array_map('strval', is_array($args['entities'] ?? null) ? $args['entities'] : []) as $entity){
			$entity_keys[$this->app_builder_entity_key($entity)]=true;
		}
		$fields=[
			'title'=>['type'=>'string', 'required'=>true],
			'status'=>['type'=>'string', 'default'=>'open'],
			'priority'=>['type'=>'string', 'default'=>'normal'],
		];
		if(isset($entity_keys['account'])){
			$fields=['account_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'accounts']] + $fields;
			if(isset($entity_keys['contact'])){
				$fields['contact_id']=['type'=>'integer', 'foreign_key_target'=>'contacts'];
			}
			return $fields;
		}
		if(isset($entity_keys['workorder'])){
			$fields=['work_order_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'work orders']] + $fields;
			return $fields;
		}
		if(isset($entity_keys['customer'])){
			$fields=['customer_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'customers']] + $fields;
			return $fields;
		}
		if(isset($entity_keys['workspace'])){
			$fields=['workspace_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'workspaces']] + $fields;
			return $fields;
		}
		$task=strtolower((string)($args['task'] ?? ''));
		if(isset($entity_keys['project']) || str_contains($task, 'ticket tracker') || str_contains($task, 'project')){
			return [
				'title'=>['type'=>'string', 'required'=>true],
				'project_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'],
				'status'=>['type'=>'string', 'default'=>'open'],
				'priority'=>['type'=>'string', 'default'=>'normal'],
				'assignee_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
				'due_at'=>['type'=>'datetime'],
			];
		}
		$fields['assignee_id']=['type'=>'integer', 'foreign_key_target'=>'users'];
		$fields['due_at']=['type'=>'datetime'];
		return $fields;
	}

	/**
	 * Selects a per-entity fields block from natural nested agent input.
	 *
	 * @param string $entity Entity name.
	 * @param array<string|int,mixed> $fields Raw fields argument.
	 * @return array<string|int,mixed>|null Matched fields block, or null.
	 */
	private function app_builder_entity_fields_input(string $entity, array $fields): ?array {
		$entity_key=$this->app_builder_entity_key($entity);
		foreach($fields as $key=>$definition){
			if(!is_string($key) || !is_array($definition)){
				continue;
			}
			if($this->app_builder_entity_key($key)!==$entity_key){
				continue;
			}
			if(is_array($definition['fields'] ?? null)){
				return $definition['fields'];
			}
			return $definition;
		}
		foreach($fields as $definition){
			if(!is_array($definition)){
				continue;
			}
			$name=trim((string)($definition['entity'] ?? $definition['resource'] ?? ''));
			if($name==='' || $this->app_builder_entity_key($name)!==$entity_key || !is_array($definition['fields'] ?? null)){
				continue;
			}
			return $definition['fields'];
		}
		return null;
	}

	/**
	 * Detects whether a fields argument is an entity-to-fields map.
	 *
	 * @param array<string|int,mixed> $fields Raw fields argument.
	 * @return bool True when the argument is nested per entity.
	 */
	private function app_builder_fields_input_is_nested(array $fields): bool {
		foreach($fields as $key=>$definition){
			if(is_string($key) && is_array($definition) && !$this->app_builder_field_definition_like($definition)){
				return true;
			}
			if(is_array($definition) && array_key_exists('fields', $definition) && (array_key_exists('entity', $definition) || array_key_exists('resource', $definition))){
				return true;
			}
		}
		return false;
	}

	/**
	 * Detects field-definition arrays without mistaking entity maps that contain
	 * a real field named "name" for a single flat field definition.
	 *
	 * @param array<string|int,mixed> $definition Raw array field/entity definition.
	 * @return bool True when the array itself describes one field.
	 */
	private function app_builder_field_definition_like(array $definition): bool {
		if(array_key_exists('type', $definition)){
			return true;
		}
		if(!array_key_exists('name', $definition) || !is_string($definition['name'] ?? null)){
			return false;
		}
		foreach(['required', 'default', 'default_value', 'options', 'choices', 'foreign_key_target', 'not_foreign_key', 'foreign_key', 'unique', 'unique_with', 'unique_scope'] as $metadata_key){
			if(array_key_exists($metadata_key, $definition)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes entity labels for matching nested fields input.
	 *
	 * @param string $entity Entity label.
	 * @return string Stable comparison key.
	 */
	private function app_builder_entity_key(string $entity): string {
		return strtolower(preg_replace('/[^a-z0-9]+/i', '', $this->app_builder_normalize_entity_name($entity)) ?? $entity);
	}

	/**
	 * Converts field hints into schema rows for builder output.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,array<string,mixed>> Schema field rows.
	 */
	private function app_builder_schema_fields(array $field_hints): array {
		$fields=[];
		foreach($field_hints as $hint){
			$name=trim((string)($hint['name'] ?? ''));
			if($name===''){
				continue;
			}
			$field=[
				'name'=>$name,
				'type'=>(string)($hint['type'] ?? 'string'),
				'required'=>($hint['required'] ?? false)===true,
			];
			if(is_array($hint['options'] ?? null) && $hint['options']!==[]){
				$field['options']=array_values(array_map('strval', $hint['options']));
			}
			if(array_key_exists('default', $hint)){
				$field['default']=$this->app_builder_scalar_default($hint['default']);
			}
			if(($hint['unique'] ?? false)===true){
				$field['unique']=true;
			}
			if(is_array($hint['unique_with'] ?? null) && $hint['unique_with']!==[]){
				$field['unique_with']=array_values(array_map('strval', $hint['unique_with']));
			}
			if(isset($hint['foreign_key_target']) && trim((string)$hint['foreign_key_target'])!==''){
				$field['foreign_key_target']=(string)$hint['foreign_key_target'];
			}
			if(($hint['not_foreign_key'] ?? false)===true){
				$field['not_foreign_key']=true;
			}
			$fields[]=$field;
		}
		return $fields;
	}

	/**
	 * Suggests Panel filters from field names and types.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,string> Filter names.
	 */
	private function app_builder_filters(array $field_hints): array {
		$filters=[];
		foreach($field_hints as $hint){
			$name=(string)($hint['name'] ?? '');
			$type=(string)($hint['type'] ?? '');
			$is_relationship=trim((string)($hint['foreign_key_target'] ?? ''))!=='';
			if(in_array($name, ['status', 'priority'], true) || ($is_relationship && str_ends_with($name, '_id')) || in_array($type, ['date', 'datetime', 'boolean'], true) || $this->app_builder_field_options($hint)!==[]){
				$filters[]=$name;
			}
		}
		return array_values(array_unique($filters));
	}

	/**
	 * Builds typed Panel filter entries from field hints.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,array<string,mixed>> Filter entries.
	 */
	private function app_builder_filter_entries(array $field_hints): array {
		$entries=[];
		foreach($field_hints as $hint){
			$name=(string)($hint['name'] ?? '');
			if(in_array($name, $this->app_builder_filters($field_hints), true)){
				$entry=[
					'name'=>$name,
					'type'=>$this->app_builder_panel_type_for_field($hint, 'filter'),
				];
				$options=$this->app_builder_field_options($hint);
				if($options!==[]){
					$entry['options']=$options;
				}
				if(array_key_exists('default', $hint)){
					$entry['default']=$this->app_builder_scalar_default($hint['default']);
				}
				$entries[]=$entry;
			}
		}
		return $entries;
	}

	/**
	 * Maps schema field rows or hint rows to Panel helper types.
	 *
	 * @param array<string,mixed> $field Field hint or schema row.
	 * @param string $context Panel helper context.
	 * @return string Panel helper type.
	 */
	private function app_builder_panel_type_for_field(array $field, string $context): string {
		if($context!=='column' && $this->app_builder_field_options($field)!==[]){
			return 'select';
		}
		if($context!=='column' && trim((string)($field['foreign_key_target'] ?? ''))!==''){
			return 'select';
		}
		if($context!=='column' && ($field['not_foreign_key'] ?? false)===true && str_ends_with((string)($field['name'] ?? ''), '_id')){
			return in_array(strtolower((string)($field['type'] ?? '')), ['integer', 'int', 'float', 'decimal', 'number'], true) ? 'number' : 'text';
		}
		return $this->app_builder_panel_type((string)($field['name'] ?? ''), (string)($field['type'] ?? 'string'), $context);
	}

	/**
	 * Builds one Panel field skeleton line, preserving options/default metadata when present.
	 *
	 * @param array<string,mixed> $field Builder schema field row.
	 * @return string PHP source line.
	 */
	private function app_builder_panel_field_source(array $field): string {
		$name=(string)($field['name'] ?? '');
		$type=$this->app_builder_panel_type_for_field($field, 'field');
		$options=$this->app_builder_field_options($field);
		$has_default=array_key_exists('default', $field);
		$sensitivity_category=$this->app_builder_field_matches_sensitivity_category($name, 'credentials_or_secrets')
			? 'credentials_or_secrets'
			: $this->app_builder_field_sensitivity_category($name);
		if($options===[] && !$has_default && $sensitivity_category!=='credentials_or_secrets'){
			return "\t\t\t\t\$panel->field('".$this->php_string_literal($name)."', '".$this->php_string_literal($type)."'),";
		}
		$parts=[
			"'name'=>'".$this->php_string_literal($name)."'",
			"'type'=>'".$this->php_string_literal($type)."'",
		];
		if($options!==[]){
			$parts[]="'options'=>".$this->app_builder_panel_options_source($options);
		}
		if($has_default){
			$parts[]="'default'=>".$this->app_builder_php_scalar_literal($field['default']);
		}
		if(($field['required'] ?? false)===true){
			$parts[]="'required'=>true";
		}
		if($sensitivity_category==='credentials_or_secrets'){
			$parts[]="'sensitive_field_policy'=>'".$this->php_string_literal($this->app_builder_sensitive_field_action($sensitivity_category))."'";
		}
		return "\t\t\t\t\$panel->field([".implode(', ', $parts)."]),";
	}

	/**
	 * Builds one Panel filter skeleton line, preserving options/default metadata when present.
	 *
	 * @param array<string,mixed> $filter Panel filter entry.
	 * @return string PHP source line.
	 */
	private function app_builder_panel_filter_source(array $filter): string {
		$name=(string)($filter['name'] ?? '');
		$type=(string)($filter['type'] ?? 'text');
		$options=$this->app_builder_field_options($filter);
		$has_default=array_key_exists('default', $filter);
		if($options===[] && !$has_default){
			return "\t\t\t\t\$panel->filter('".$this->php_string_literal($name)."', '".$this->php_string_literal($type)."'),";
		}
		$parts=[
			"'name'=>'".$this->php_string_literal($name)."'",
			"'type'=>'".$this->php_string_literal($type)."'",
		];
		if($options!==[]){
			$parts[]="'options'=>".$this->app_builder_panel_options_source($options);
		}
		if($has_default){
			$parts[]="'default'=>".$this->app_builder_php_scalar_literal($filter['default']);
		}
		return "\t\t\t\t\$panel->filter([".implode(', ', $parts)."]),";
	}

	/**
	 * Keeps structured scalar defaults typed while bounding generated metadata.
	 *
	 * @param mixed $value Default value.
	 * @return string|int|float|bool|null Scalar default value.
	 */
	private function app_builder_scalar_default(mixed $value): string|int|float|bool|null {
		if(is_bool($value) || is_int($value) || is_float($value) || $value===null){
			return $value;
		}
		$string_value=trim((string)$value);
		return $string_value!=='' ? substr($string_value, 0, 80) : null;
	}

	/**
	 * Renders a bounded scalar as PHP literal source for skeleton previews.
	 *
	 * @param mixed $value Scalar default value.
	 * @return string PHP literal.
	 */
	private function app_builder_php_scalar_literal(mixed $value): string {
		$value=$this->app_builder_scalar_default($value);
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(is_int($value) || is_float($value)){
			return (string)$value;
		}
		if($value===null){
			return 'null';
		}
		return "'".$this->php_string_literal((string)$value)."'";
	}

	/**
	 * Extracts bounded string options from a schema field, filter, or hint row.
	 *
	 * @param array<string,mixed> $field Field-like row.
	 * @return array<int,string> Normalized option values.
	 */
	private function app_builder_field_options(array $field): array {
		if(!is_array($field['options'] ?? null)){
			return [];
		}
		$options=[];
		foreach($field['options'] as $option){
			$value=trim((string)$option);
			if($value!==''){
				$options[]=$value;
			}
		}
		return array_values(array_unique($options));
	}

	/**
	 * Builds a compact PHP option map for Panel array field/filter definitions.
	 *
	 * @param array<int,string> $options Option values.
	 * @return string PHP array source.
	 */
	private function app_builder_panel_options_source(array $options): string {
		$items=[];
		foreach($options as $option){
			$value=(string)$option;
			if($value===''){
				continue;
			}
			$items[]="'".$this->php_string_literal($value)."'=>'".$this->php_string_literal($this->app_builder_panel_option_label($value))."'";
		}
		return '['.implode(', ', $items).']';
	}

	/**
	 * Converts an option value into a readable Panel label.
	 *
	 * @param string $value Option value.
	 * @return string Option label.
	 */
	private function app_builder_panel_option_label(string $value): string {
		return $this->title_label(str_replace(['_', '-'], ' ', $value));
	}

	/**
	 * Maps schema hints to conservative Panel helper types.
	 *
	 * @param string $name Field/filter name.
	 * @param string $schema_type Schema type.
	 * @param string $context Panel helper context.
	 * @return string Panel helper type.
	 */
	private function app_builder_panel_type(string $name, string $schema_type, string $context): string {
		$schema_type=strtolower($schema_type);
		if(in_array($name, ['status', 'priority'], true)){
			return 'select';
		}
		if(str_ends_with($name, '_id')){
			return 'text';
		}
		return match($schema_type){
			'integer', 'int', 'float', 'decimal', 'number'=>'number',
			'date'=>'date',
			'datetime', 'timestamp'=>'datetime',
			'boolean', 'bool'=>'boolean',
			default=>'text',
		};
	}

	/**
	 * Maps builder schema hints to conservative TableSchema casts.
	 *
	 * @param string $schema_type Builder schema type.
	 * @return string|null TableSchema cast name, or null for raw strings.
	 */
	private function app_builder_sql_cast(string $schema_type): ?string {
		return match(strtolower($schema_type)){
			'integer', 'int'=>'int',
			'float', 'decimal', 'number'=>'float',
			'boolean', 'bool'=>'bool',
			'date'=>'datetime',
			'datetime', 'timestamp'=>'datetime',
			'json', 'jsonb'=>'json',
			default=>null,
		};
	}

	/**
	 * Infers simple relationships from foreign-key field names.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,array<string,string>> Relationship hints.
	 */
	private function app_builder_relationships(array $field_hints): array {
		$relationships=[];
		foreach($field_hints as $hint){
			$name=(string)($hint['name'] ?? '');
			$target=trim((string)($hint['foreign_key_target'] ?? ''));
			if($name!=='' && $target!==''){
				if($target!==''){
					$target_entity=$this->app_builder_relationship_target_entity($target);
					$target_key=str_replace('-', '_', $this->slug_name($target_entity));
					$relationships[]=[
						'type'=>'belongs_to',
						'field'=>$name,
						'target'=>$target_key,
						'target_entity'=>$target_entity,
						'target_table'=>$target_key,
						'label'=>$this->title_label($target_entity),
					];
				}
			}
		}
		return $relationships;
	}

	/**
	 * Converts a foreign-key target hint such as "purchase requests" into the
	 * singular entity label used by relationship metadata.
	 *
	 * @param string $target Raw foreign-key target hint or field-derived target.
	 * @return string Singular PascalCase entity label.
	 */
	private function app_builder_relationship_target_entity(string $target): string {
		$tokens=$this->name_tokens($target);
		if($tokens===[]){
			return 'DataphyreItem';
		}
		$last=count($tokens)-1;
		$tokens[$last]=$this->app_builder_singular_entity_token($tokens[$last]);
		$name=$this->studly_name(implode(' ', $tokens));
		return $this->app_builder_php_safe_entity_name($name);
	}

	/**
	 * Avoids class-like entity names that would generate invalid PHP artifacts.
	 *
	 * @param string $entity Normalized PascalCase entity name.
	 * @return string PHP-safe entity name.
	 */
	private function app_builder_php_safe_entity_name(string $entity): string {
		$reserved=[
			'abstract'=>true, 'and'=>true, 'array'=>true, 'as'=>true, 'bool'=>true, 'boolean'=>true, 'break'=>true, 'callable'=>true,
			'case'=>true, 'catch'=>true, 'class'=>true, 'clone'=>true, 'const'=>true, 'continue'=>true,
			'declare'=>true, 'default'=>true, 'die'=>true, 'do'=>true, 'echo'=>true, 'else'=>true,
			'elseif'=>true, 'empty'=>true, 'enddeclare'=>true, 'endfor'=>true, 'endforeach'=>true,
			'endif'=>true, 'endswitch'=>true, 'endwhile'=>true, 'enum'=>true, 'eval'=>true, 'exit'=>true,
			'extends'=>true, 'false'=>true, 'final'=>true, 'finally'=>true, 'float'=>true, 'fn'=>true, 'for'=>true, 'foreach'=>true,
			'function'=>true, 'global'=>true, 'goto'=>true, 'if'=>true, 'implements'=>true, 'include'=>true,
			'include_once'=>true, 'instanceof'=>true, 'insteadof'=>true, 'int'=>true, 'integer'=>true, 'interface'=>true, 'isset'=>true,
			'iterable'=>true, 'list'=>true, 'match'=>true, 'mixed'=>true, 'namespace'=>true, 'never'=>true, 'new'=>true, 'null'=>true,
			'object'=>true, 'or'=>true, 'parent'=>true, 'print'=>true, 'private'=>true, 'protected'=>true, 'public'=>true, 'readonly'=>true, 'real'=>true, 'require'=>true,
			'require_once'=>true, 'return'=>true, 'self'=>true, 'static'=>true, 'string'=>true, 'switch'=>true, 'throw'=>true,
			'trait'=>true, 'true'=>true, 'try'=>true, 'unset'=>true, 'use'=>true, 'var'=>true, 'void'=>true, 'while'=>true,
			'xor'=>true, 'yield'=>true,
		];
		return isset($reserved[strtolower($entity)]) ? $entity.'Record' : $entity;
	}

	/**
	 * Escapes a value for single-quoted PHP string previews.
	 *
	 * @param string $value String value.
	 * @return string Escaped value without surrounding quotes.
	 */
	private function php_string_literal(string $value): string {
		return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
	}

	/**
	 * Converts a raw name into a readable title label.
	 *
	 * @param string $name Raw name.
	 * @return string Title label.
	 */
	private function title_label(string $name): string {
		$tokens=$this->name_tokens($name);
		if($tokens===[]){
			return 'Dataphyre Item';
		}
		$label_tokens=[];
		foreach($tokens as $token){
			$label_tokens[]=$this->enterprise_acronym_token($token) ?? ucfirst(strtolower($token));
		}
		return implode(' ', $label_tokens);
	}

	/**
	 * Converts an identifier into camelCase for generated record accessors.
	 *
	 * @param string $name Raw identifier.
	 * @return string camelCase method stem.
	 */
	private function camel_name(string $name): string {
		$parts=preg_split('/[^A-Za-z0-9]+/', trim($name)) ?: [];
		$parts=array_values(array_filter($parts, static fn(string $part): bool => $part!==''));
		if($parts===[]){
			return '';
		}
		$first=strtolower(array_shift($parts));
		foreach($parts as $part){
			$first.=ucfirst(strtolower($part));
		}
		return $first;
	}

	/**
	 * Returns a simple plural label for generated app-builder previews.
	 *
	 * @param string $label Singular label.
	 * @return string Plural-ish label.
	 */
	private function plural_label(string $label): string {
		$lower=strtolower($label);
		if(str_ends_with($lower, 'y')){
			return substr($label, 0, -1).'ies';
		}
		if(str_ends_with($lower, 's')){
			return $label.'es';
		}
		return $label.'s';
	}

	/**
	 * Infers the best dry-run scaffold family for an app-builder task.
	 *
	 * @param string $task Task text.
	 * @param array<string,mixed> $args Optional explicit scaffold options.
	 * @return string Scaffold type accepted by generate_scaffold_plan().
	 */
	private function infer_app_builder_scaffold_type(string $task, array $args): string {
		$explicit=strtolower(trim((string)($args['scaffold_type'] ?? '')));
		if(in_array($explicit, ['panel_resource', 'routing_controller', 'api_endpoint', 'sql_table', 'mvc_controller', 'runtime_module'], true)){
			return $explicit;
		}
		$lower=strtolower($task);
		if(str_contains($lower, 'panel') || str_contains($lower, 'admin') || str_contains($lower, 'crud') || str_contains($lower, 'resource')){
			return 'panel_resource';
		}
		if(str_contains($lower, 'openapi') || str_contains($lower, 'api endpoint') || str_contains($lower, 'api route') || str_contains($lower, 'api handler') || str_contains($lower, 'api docs') || str_contains($lower, 'json endpoint') || str_contains($lower, 'rest endpoint') || str_contains($lower, 'rest api')){
			return 'api_endpoint';
		}
		if(str_contains($lower, 'route') || str_contains($lower, 'controller') || str_contains($lower, 'endpoint')){
			return 'routing_controller';
		}
		if(str_contains($lower, 'table') || str_contains($lower, 'schema') || str_contains($lower, 'migration')){
			return 'sql_table';
		}
		return 'panel_resource';
	}

	/**
	 * Infers likely domain entities for compact app-building plans.
	 *
	 * @param string $task Task text.
	 * @param array<string,mixed> $args Optional explicit name/entities.
	 * @return array<int,string> Entity display names.
	 */
	private function infer_app_builder_entities(string $task, array $args): array {
		$entities=[];
		if(is_array($args['entities'] ?? null)){
			foreach($args['entities'] as $entity){
				$entity=trim((string)$entity);
				if($entity!==''){
					$entities[]=$this->app_builder_normalize_entity_name($entity);
				}
			}
		}
		$name=trim((string)($args['name'] ?? ''));
		if($name!==''){
			$entities[]=$this->app_builder_normalize_entity_name($name);
		}
		if($entities!==[]){
			return array_values(array_unique($entities));
		}
		if(is_array($args['fields'] ?? null) && $this->app_builder_fields_input_is_nested($args['fields'])){
			$entities=$this->app_builder_entities_from_fields_input($args['fields']);
			if($entities!==[]){
				return array_values(array_unique($entities));
			}
		}
		$lower=strtolower($task);
		$entities=$this->app_builder_entities_from_task_phrases($task);
		$has_pascal_entity_list=$this->app_builder_has_pascal_entity_list_context($task);
		if(!$has_pascal_entity_list && $this->app_builder_has_customer_success_renewal_context($lower)){
			$entities=$this->app_builder_preferred_entity_order($entities, ['RenewalOpportunity', 'Account', 'Contact', 'Workspace', 'Subscription', 'HealthScore', 'SuccessPlan', 'Meeting', 'Note', 'Risk', 'Escalation', 'Playbook', 'Task']);
		}elseif(!$has_pascal_entity_list && $this->app_builder_has_customer_success_context($lower)){
			$entities=$this->app_builder_preferred_entity_order($entities, ['Workspace', 'Account', 'Contact', 'Subscription', 'UsageMeter', 'HealthScore', 'SuccessPlan', 'Task', 'Note', 'Risk', 'Escalation', 'Playbook', 'Alert', 'Dashboard', 'AuditEvent', 'Notification']);
		}
		if(!$has_pascal_entity_list && $this->app_builder_has_learning_compliance_context($lower)){
			$entities=$this->app_builder_preferred_entity_order($entities, ['Workspace', 'Learner', 'Course', 'Module', 'Lesson', 'Assignment', 'Attestation', 'Certificate', 'Quiz', 'Question', 'Attempt', 'RemediationPlan', 'PolicyAcknowledgement', 'EvidenceUpload', 'AuditEvent', 'Notification', 'Dashboard', 'Tenant', 'Policy']);
		}
		if(!$has_pascal_entity_list && $this->app_builder_has_provider_credentialing_context($lower)){
			$entities=$this->app_builder_preferred_entity_order($entities, ['Workspace', 'Provider', 'ProviderProfile', 'License', 'Certification', 'CredentialingApplication', 'CredentialingStep', 'Verification', 'PayerEnrollment', 'NetworkContract', 'Facility', 'Privilege', 'Expiration', 'Document', 'BackgroundCheck', 'SanctionCheck', 'CommitteeReview', 'ApprovalDecision', 'AuditEvent', 'Notification', 'Dashboard', 'Tenant', 'Policy']);
		}
		if($this->app_builder_has_property_lease_context($lower)){
			$entities=$this->app_builder_preferred_entity_order($entities, ['Workspace', 'Portfolio', 'Property', 'Unit', 'TenantProfile', 'Lease', 'LeaseTerm', 'RentSchedule', 'RentPayment', 'SecurityDeposit', 'MaintenanceRequest', 'WorkOrder', 'Inspection', 'Vendor', 'RenewalOffer', 'ArrearsCase']);
		}
		$known=[
			'Project'=>['project', 'projects'],
			'Ticket'=>['ticket', 'tickets'],
			'Task'=>['task tracker', 'task tracking', 'task management', 'task board', 'task list', 'task lists', 'task admin', 'task crud', 'tasks'],
			'Issue'=>['issue', 'issues'],
			'Customer'=>['customer', 'customers'],
			'User'=>['user', 'users'],
			'Order'=>['order', 'orders'],
			'Product'=>['product', 'products'],
			'Invoice'=>['invoice', 'invoices'],
			'Comment'=>['comment', 'comments'],
		];
		foreach($known as $entity=>$phrases){
			if($this->app_builder_task_mentions_known_entity($lower, $entity, $phrases)){
				if(!$this->app_builder_has_entity_or_specialization($entities, $entity)){
					$entities[]=$entity;
				}
			}
		}
		if($entities===[]){
			$fallback=$task!=='' ? $task : 'App Resource';
			$entities[]=$this->app_builder_normalize_entity_name(substr($fallback, 0, 80));
		}
		return array_values(array_unique($entities));
	}

	/**
	 * Extracts entity names from nested per-entity field input when entities are omitted.
	 *
	 * @param array<string|int,mixed> $fields Raw fields argument.
	 * @return array<int,string> Entity display names in caller-supplied order.
	 */
	private function app_builder_entities_from_fields_input(array $fields): array {
		$entities=[];
		foreach($fields as $key=>$definition){
			if(is_string($key) && is_array($definition) && !$this->app_builder_field_definition_like($definition)){
				$entity=trim($key);
			}else{
				$entity=is_array($definition) ? trim((string)($definition['entity'] ?? $definition['resource'] ?? '')) : '';
			}
			if($entity===''){
				continue;
			}
			$entities[]=$this->app_builder_normalize_entity_name($entity);
		}
		return array_values(array_unique($entities));
	}

	/**
	 * Checks known entity phrases with token boundaries so product nouns do not
	 * leak out of ordinary MCP workflow wording such as "task pack".
	 *
	 * @param string $lower Lowercase task text.
	 * @param string $entity Candidate entity name.
	 * @param array<int,string> $phrases Entity phrases to match.
	 * @return bool True when the phrase looks like a domain entity mention.
	 */
	private function app_builder_task_mentions_known_entity(string $lower, string $entity, array $phrases): bool {
		if($entity==='Task' && preg_match('/\btask\s+(pack|packs|start|starts|context|contexts|guidance|workflow|workflows|tool|tools|surface|surfaces)\b/', $lower)===1){
			foreach(['task tracker', 'task tracking', 'task management', 'task board', 'task list', 'task lists', 'task admin', 'task crud', 'tasks'] as $specific_phrase){
				if($this->bounded_phrase_match($lower, $specific_phrase)){
					return true;
				}
			}
			return false;
		}
		if($entity==='Customer' && ($this->bounded_phrase_match($lower, 'customer success') || $this->bounded_phrase_match($lower, 'customer onboarding') || $this->bounded_phrase_match($lower, 'customer operations'))){
			foreach(['customers', 'customer records', 'customer crud', 'customer admin'] as $specific_phrase){
				if($this->bounded_phrase_match($lower, $specific_phrase)){
					return true;
				}
			}
			return false;
		}
		foreach($phrases as $phrase){
			if($this->bounded_phrase_match($lower, $phrase)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Matches a lowercase phrase on alphanumeric boundaries.
	 *
	 * @param string $text Lowercase text to scan.
	 * @param string $phrase Lowercase phrase to match.
	 * @return bool True when the phrase is present as a bounded token sequence.
	 */
	private function bounded_phrase_match(string $text, string $phrase): bool {
		$phrase=trim($phrase);
		if($phrase===''){
			return false;
		}
		return preg_match('/(?<![a-z0-9])'.preg_quote($phrase, '/').'(?![a-z0-9])/', $text)===1;
	}

	/**
	 * Detects property/lease app-builder context so tenant-like wording resolves
	 * to lease tenant profiles rather than platform tenancy records.
	 *
	 * @param string $lower Lowercase task text.
	 * @return bool True when the task is about property or lease operations.
	 */
	private function app_builder_has_property_lease_context(string $lower): bool {
		foreach(['property and lease', 'lease operations', 'lease lifecycle', 'leases', 'rent schedules', 'rent payments', 'security deposits', 'property/unit availability', 'unit availability', 'arrears collections'] as $phrase){
			if($this->bounded_phrase_match($lower, $phrase)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Detects customer-success/renewal operations prompts so generic SaaS nouns
	 * are ordered as one coherent enterprise scaffold instead of fallback CRUD.
	 *
	 * @param string $lower Lowercase task text.
	 * @return bool True when the task is about customer success operations.
	 */
	private function app_builder_has_customer_success_context(string $lower): bool {
		foreach(['customer success', 'customer operations', 'success plans', 'health scores', 'account health', 'renewal opportunities', 'renewals platform', 'customer renewals'] as $phrase){
			if($this->bounded_phrase_match($lower, $phrase)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Detects customer-success prompts centered on renewals, where renewal
	 * opportunities are the leading workflow resource.
	 *
	 * @param string $lower Lowercase task text.
	 * @return bool True when renewal lifecycle is central to the prompt.
	 */
	private function app_builder_has_customer_success_renewal_context(string $lower): bool {
		return $this->app_builder_has_customer_success_context($lower)
			&& (
				$this->bounded_phrase_match($lower, 'renewal opportunities')
				|| $this->bounded_phrase_match($lower, 'renewals platform')
				|| $this->bounded_phrase_match($lower, 'customer renewals')
				|| $this->bounded_phrase_match($lower, 'renewal lifecycle')
			);
	}

	/**
	 * Detects learning/compliance prompts so course, learner, assignment, and
	 * evidence flows stay ahead of generic audit or policy artifacts.
	 *
	 * @param string $lower Lowercase task text.
	 * @return bool True when the task is about enterprise learning compliance.
	 */
	private function app_builder_has_learning_compliance_context(string $lower): bool {
		foreach(['learning compliance', 'compliance training', 'training compliance', 'enterprise learning', 'learning management', 'learners', 'courses', 'quizzes', 'certificates', 'policy acknowledgements', 'policy acknowledgments'] as $phrase){
			if($this->bounded_phrase_match($lower, $phrase)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Detects healthcare provider credentialing prompts so provider workflow
	 * resources lead ahead of generic document, license, contract, or audit nouns.
	 *
	 * @param string $lower Lowercase task text.
	 * @return bool True when the task is about provider credentialing.
	 */
	private function app_builder_has_provider_credentialing_context(string $lower): bool {
		foreach(['provider credentialing', 'credentialing applications', 'credentialing steps', 'payer enrollments', 'network contracts', 'provider profiles', 'sanction checks', 'committee reviews', 'provider privileges'] as $phrase){
			if($this->bounded_phrase_match($lower, $phrase)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Detects prompts that already provide a PascalCase entity list. Those lists
	 * should preserve caller order instead of applying domain-preferred ordering.
	 *
	 * @param string $task Original task text.
	 * @return bool True when a "with Entity, Entity" style list is present.
	 */
	private function app_builder_has_pascal_entity_list_context(string $task): bool {
		return preg_match('/\bwith\s+[A-Z][A-Za-z0-9]*(?:s)?\s*,\s*[A-Z][A-Za-z0-9]*(?:s)?/u', $task)===1;
	}

	/**
	 * Extracts bounded domain entity phrases from natural app-builder task text.
	 *
	 * This is intentionally a small product-facing phrase map rather than a broad
	 * NLP parser: it catches common enterprise CRUD nouns while keeping ordinary
	 * app-builder output predictable and easy for agents to override with
	 * explicit entities.
	 *
	 * @param string $task Task text.
	 * @return array<int,string> Normalized entity names ordered by first mention.
	 */
	private function app_builder_entities_from_task_phrases(string $task): array {
		$lower=strtolower($task);
		$candidates=[
			'Organization'=>['organizations', 'organisation', 'organisations', 'organization'],
			'Tenant'=>['tenants', 'tenant'],
			'Workspace'=>['workspaces', 'workspace'],
			'User'=>['users', 'user'],
			'Team'=>['teams', 'team'],
			'Role'=>['roles', 'role'],
			'PermissionSet'=>['permission sets', 'permission set', 'permissionsets', 'permissionset'],
			'Membership'=>['memberships', 'membership', 'workspace memberships', 'workspace membership', 'members', 'member'],
			'Invitation'=>['invitations', 'invitation', 'invites', 'invite'],
			'Account'=>['accounts', 'account'],
			'Customer'=>['customers', 'customer records', 'customer record', 'customer'],
			'Contact'=>['contacts', 'contact'],
			'Subscription'=>['subscriptions'],
			'SubscriptionChange'=>['subscription changes', 'subscription change', 'subscriptionchanges', 'subscriptionchange', 'plan changes', 'plan change', 'scheduled plan changes', 'scheduled plan change', 'subscription upgrades', 'subscription upgrade', 'subscription downgrades', 'subscription downgrade', 'proration adjustments', 'proration adjustment'],
			'Plan'=>['plans', 'billing plans', 'pricing plans', 'plan catalog'],
			'Entitlement'=>['entitlements', 'entitlement'],
			'Invoice'=>['invoices', 'invoice'],
			'InvoiceLine'=>['invoice lines', 'invoice line', 'invoicelines', 'invoiceline', 'invoice line items', 'invoice line item'],
			'TaxRate'=>['tax rates', 'tax rate', 'taxrates', 'taxrate', 'vat rates', 'vat rate', 'gst rates', 'gst rate', 'sales tax rates', 'sales tax rate'],
			'TaxExemption'=>['tax exemptions', 'tax exemption', 'taxexemptions', 'taxexemption', 'exemption certificates', 'exemption certificate', 'tax certificates', 'tax certificate', 'vat exemptions', 'vat exemption'],
			'Payment'=>['payments', 'payment'],
			'PaymentDispute'=>['payment disputes', 'payment dispute', 'paymentdisputes', 'paymentdispute', 'chargebacks', 'chargeback', 'dispute evidence', 'dispute representment', 'representment evidence'],
			'DunningAttempt'=>['dunning attempts', 'dunning attempt', 'dunningattempts', 'dunningattempt', 'failed payment recovery', 'payment recovery attempts', 'payment recovery attempt', 'retry schedules', 'retry schedule'],
			'Refund'=>['refunds', 'refund'],
			'CreditMemo'=>['credit memos', 'credit memo', 'creditmemos', 'creditmemo'],
			'JournalEntry'=>['journal entries', 'journal entry', 'journalentries', 'journalentry', 'journal posting'],
			'JournalLine'=>['journal lines', 'journal line', 'journallines', 'journalline'],
			'RevenueSchedule'=>['revenue schedules', 'revenue schedule', 'revenueschedules', 'revenueschedule', 'revenue recognition schedules', 'revenue recognition schedule'],
			'RevenueRecognition'=>['revenue recognitions', 'revenue recognition', 'revenuerecognitions', 'revenuerecognition'],
			'UsageMeter'=>['usage meters', 'usage meter', 'metered usage', 'usage events', 'usage event'],
			'BillingAccount'=>['billing accounts', 'billing account', 'billingaccounts', 'billingaccount'],
			'Webhook'=>['webhooks', 'webhook'],
			'APIProduct'=>['api products', 'api product', 'apiproducts', 'apiproduct', 'api product catalog'],
			'APIClient'=>['api clients', 'api client', 'apiclients', 'apiclient', 'client onboarding', 'api consumers', 'api consumer'],
			'APIKey'=>['api keys', 'api key', 'apikeys', 'apikey'],
			'OAuthApplication'=>['oauth applications', 'oauth application', 'oauthapplications', 'oauthapplication', 'oauth apps', 'oauth app', 'oauth callback urls', 'oauth callback url'],
			'OAuthGrant'=>['oauth grants', 'oauth grant', 'oauthgrants', 'oauthgrant', 'grant approvals', 'grant approval'],
			'RateLimitPolicy'=>['rate limit policies', 'rate limit policy', 'ratelimitpolicies', 'ratelimitpolicy', 'rate limit windows', 'rate limits', 'rate limit'],
			'UsageEvent'=>['usage events', 'usage event', 'usageevents', 'usageevent', 'usage metering', 'api usage events', 'api usage event'],
			'Service'=>['services', 'service catalog', 'service catalogue', 'it services', 'it service'],
			'ServiceRequest'=>['service requests', 'service request', 'servicerequests', 'servicerequest', 'request fulfillment', 'request fulfilment'],
			'ProblemRecord'=>['problem records', 'problem record', 'problemrecords', 'problemrecord', 'problem root cause analysis'],
			'ConfigurationItem'=>['configuration items', 'configuration item', 'configurationitems', 'configurationitem', 'cmdb relationships', 'cmdb items', 'cmdb item'],
			'Release'=>['releases', 'release', 'release windows', 'release window'],
			'MaintenanceWindow'=>['maintenance windows', 'maintenance window', 'maintenance communications', 'maintenance communication'],
			'KnowledgeArticle'=>['knowledge articles', 'knowledge article', 'knowledgearticles', 'knowledgearticle', 'knowledge publishing'],
			'ServiceAccount'=>['service accounts', 'service account', 'serviceaccounts', 'serviceaccount'],
			'AccessReview'=>['access reviews', 'access review', 'accessreviews', 'accessreview', 'access review campaigns', 'access review campaign'],
			'AccessReviewItem'=>['access review items', 'access review item', 'accessreviewitems', 'accessreviewitem', 'orphaned access items', 'orphaned access item'],
			'SessionPolicy'=>['session policies', 'session policy', 'sessionpolicies', 'sessionpolicy'],
			'CredentialRotationJob'=>['credential rotation jobs', 'credential rotation job', 'credentialrotationjobs', 'credentialrotationjob', 'credential rotations', 'credential rotation'],
			'SSOProvider'=>['sso providers', 'sso provider'],
			'SCIMProvider'=>['scim providers', 'scim provider', 'scim directories', 'scim directory', 'directory provisioning providers', 'directory provisioning provider'],
			'SCIMProvisioningJob'=>['scim provisioning jobs', 'scim provisioning job', 'provisioning jobs', 'provisioning job', 'directory sync jobs', 'directory sync job', 'user provisioning jobs', 'user provisioning job'],
			'ImpersonationSession'=>['impersonation sessions', 'impersonation session', 'admin impersonation sessions', 'admin impersonation session', 'support impersonation', 'support access sessions', 'support access session'],
			'BreakGlassAccessGrant'=>['break glass access grants', 'break glass access grant', 'break-glass access grants', 'break-glass access grant', 'emergency access grants', 'emergency access grant', 'break glass access', 'break-glass access'],
			'TOTPDevice'=>['totp devices', 'totp device'],
			'SLAPolicy'=>['sla policies', 'sla policy', 'slapolicies', 'slapolicy'],
			'AuditEvent'=>['audit events', 'audit event', 'auditevents', 'auditevent', 'audit trails', 'audit trail', 'audit log', 'audit logs'],
			'ConsentRecord'=>['consent records', 'consent record', 'consentrecords', 'consentrecord', 'consent logs', 'consent log', 'consents'],
			'DataSubjectRequest'=>['data subject requests', 'data subject request', 'datasubjectrequests', 'datasubjectrequest', 'dsr requests', 'dsr request', 'privacy requests', 'privacy request', 'erasure requests', 'erasure request'],
			'RetentionPolicy'=>['retention policies', 'retention policy', 'retentionpolicies', 'retentionpolicy', 'records retention policies', 'records retention policy'],
			'LegalHold'=>['legal holds', 'legal hold', 'legalholds', 'legalhold', 'records holds', 'records hold'],
			'ProcessingActivity'=>['processing activities', 'processing activity', 'processingactivities', 'processingactivity', 'ropa records', 'ropa record', 'processing register'],
			'DataProcessingAgreement'=>['data processing agreements', 'data processing agreement', 'dataprocessingagreements', 'dataprocessingagreement', 'dpa records', 'dpa record', 'dpa agreements', 'dpa agreement'],
			'Subprocessor'=>['subprocessors', 'subprocessor', 'sub-processors', 'sub-processor', 'vendor subprocessors', 'vendor subprocessor'],
			'TransferImpactAssessment'=>['transfer impact assessments', 'transfer impact assessment', 'transferimpactassessments', 'transferimpactassessment', 'cross-border transfer assessments', 'cross-border transfer assessment', 'data transfer assessments', 'data transfer assessment'],
			'FeatureFlag'=>['feature flags', 'feature flag', 'featureflags', 'featureflag'],
			'RolloutPlan'=>['rollout plans', 'rollout plan', 'rolloutplans', 'rolloutplan', 'rollout waves', 'rollout wave'],
			'MigrationRun'=>['migration runs', 'migration run', 'migrationruns', 'migrationrun'],
			'BackfillJob'=>['backfill jobs', 'backfill job', 'backfilljobs', 'backfilljob'],
			'RollbackPlan'=>['rollback plans', 'rollback plan', 'rollbackplans', 'rollbackplan', 'recovery plans', 'recovery plan'],
			'CompatibilityWindow'=>['compatibility windows', 'compatibility window', 'compatibilitywindows', 'compatibilitywindow', 'deprecation windows', 'deprecation window'],
			'ChangeApproval'=>['change approvals', 'change approval', 'changeapprovals', 'changeapproval'],
			'OnboardingCase'=>['onboarding cases', 'onboarding case', 'onboardingcases', 'onboardingcase', 'onboarding workflows', 'onboarding workflow'],
			'KYCCheck'=>['kyc checks', 'kyc check', 'kycchecks', 'kyccheck', 'know your customer checks', 'know your customer check'],
			'RiskReview'=>['risk reviews', 'risk review', 'riskreviews', 'riskreview'],
			'SupportTicket'=>['support tickets', 'support ticket'],
			'RenewalOpportunity'=>['renewal opportunities', 'renewal opportunity', 'renewals', 'renewal'],
			'HealthScore'=>['health scores', 'health score', 'account health'],
			'SuccessPlan'=>['success plans', 'success plan'],
			'Questionnaire'=>['questionnaires', 'questionnaire'],
			'QuestionnaireResponse'=>['questionnaire responses', 'questionnaire response', 'vendor responses', 'vendor response'],
			'TrustCenterArtifact'=>['trust center artifacts', 'trust center artifact', 'trustcenterartifacts', 'trustcenterartifact', 'trust center documents', 'trust center document', 'customer trust artifacts', 'customer trust artifact', 'compliance artifacts', 'compliance artifact', 'soc 2 reports', 'soc 2 report', 'soc2 reports', 'soc2 report', 'iso certificates', 'iso certificate'],
			'Meeting'=>['meetings', 'meeting'],
			'Note'=>['notes', 'note'],
			'RiskAssessment'=>['risk assessments', 'risk assessment'],
			'Risk'=>['risks', 'risk'],
			'Escalation'=>['escalations', 'escalation'],
			'Playbook'=>['playbooks', 'playbook'],
			'Contract'=>['contracts', 'contract'],
			'ContractClause'=>['contract clauses', 'contract clause', 'contractclauses', 'contractclause', 'clause library', 'clause libraries'],
			'ContractObligation'=>['contract obligations', 'contract obligation', 'contractobligations', 'contractobligation', 'obligations', 'obligation'],
			'SignatureRequest'=>['signature requests', 'signature request', 'signaturerequests', 'signaturerequest', 'e-signature requests', 'e-signature request'],
			'Borrower'=>['borrowers', 'borrower', 'borrower onboarding'],
			'LoanApplication'=>['loan applications', 'loan application', 'loanapplications', 'loanapplication', 'loan origination'],
			'LoanProduct'=>['loan products', 'loan product', 'loanproducts', 'loanproduct'],
			'UnderwritingReview'=>['underwriting reviews', 'underwriting review', 'underwritingreviews', 'underwritingreview', 'underwriting workflow'],
			'CreditDecision'=>['credit decisions', 'credit decision', 'creditdecisions', 'creditdecision', 'credit decision reasons', 'credit decision reason'],
			'CollateralItem'=>['collateral items', 'collateral item', 'collateralitems', 'collateralitem', 'collateral valuation'],
			'LoanAgreement'=>['loan agreements', 'loan agreement', 'loanagreements', 'loanagreement', 'agreement generation'],
			'LoanAccount'=>['loan accounts', 'loan account', 'loanaccounts', 'loanaccount', 'loan servicing'],
			'Disbursement'=>['disbursements', 'disbursement', 'disbursement approvals', 'disbursement approval'],
			'RepaymentSchedule'=>['repayment schedules', 'repayment schedule', 'repaymentschedules', 'repaymentschedule', 'amortization schedules', 'amortization schedule'],
			'Repayment'=>['repayments', 'repayment', 'repayment reconciliation'],
			'DelinquencyCase'=>['delinquency cases', 'delinquency case', 'delinquencycases', 'delinquencycase', 'delinquency workflow'],
			'CollectionAction'=>['collection actions', 'collection action', 'collectionactions', 'collectionaction', 'collections actions', 'collections action'],
			'Study'=>['studies', 'study', 'clinical studies', 'clinical study', 'study protocol versions', 'study protocol version'],
			'StudySite'=>['study sites', 'study site', 'studysites', 'studysite', 'site activation'],
			'Participant'=>['participants', 'participant', 'participant enrollment'],
			'ConsentForm'=>['consent forms', 'consent form', 'consentforms', 'consentform', 'informed consent tracking'],
			'StudyVisit'=>['study visits', 'study visit', 'studyvisits', 'studyvisit', 'visit schedule adherence'],
			'VisitProcedure'=>['visit procedures', 'visit procedure', 'visitprocedures', 'visitprocedure', 'procedure completion'],
			'LabResult'=>['lab results', 'lab result', 'labresults', 'labresult', 'lab result review'],
			'AdverseEvent'=>['adverse events', 'adverse event', 'adverseevents', 'adverseevent', 'adverse event reporting'],
			'ProtocolDeviation'=>['protocol deviations', 'protocol deviation', 'protocoldeviations', 'protocoldeviation', 'protocol deviation review'],
			'RegulatorySubmission'=>['regulatory submissions', 'regulatory submission', 'regulatorysubmissions', 'regulatorysubmission', 'regulatory submission tracking'],
			'MonitorFinding'=>['monitor findings', 'monitor finding', 'monitorfindings', 'monitorfinding'],
			'Portfolio'=>['portfolios', 'portfolio', 'portfolio ownership'],
			'Property'=>['properties', 'property', 'property availability'],
			'Unit'=>['units', 'unit', 'property/unit availability', 'unit availability'],
			'TenantProfile'=>['tenants', 'tenant profiles', 'tenant profile', 'lease tenants', 'lease tenant'],
			'Lease'=>['leases', 'lease', 'lease lifecycle'],
			'LeaseTerm'=>['lease terms', 'lease term', 'leaseterms', 'leaseterm'],
			'RentSchedule'=>['rent schedules', 'rent schedule', 'rentschedules', 'rentschedule', 'rent schedule generation'],
			'RentPayment'=>['rent payments', 'rent payment', 'rentpayments', 'rentpayment', 'payment reconciliation'],
			'SecurityDeposit'=>['security deposits', 'security deposit', 'securitydeposits', 'securitydeposit', 'deposit accounting'],
			'RenewalOffer'=>['renewal offers', 'renewal offer', 'renewaloffers', 'renewaloffer', 'renewal workflows'],
			'ArrearsCase'=>['arrears cases', 'arrears case', 'arrearscases', 'arrearscase', 'arrears collections'],
			'Policyholder'=>['policyholders', 'policyholder', 'policy holders', 'policy holder'],
			'Policy'=>['policies', 'policy records', 'policy record', 'insurance policies', 'insurance policy'],
			'CoverageItem'=>['coverage items', 'coverage item', 'coverageitems', 'coverageitem', 'policy coverage lookup', 'coverage lookup'],
			'Claim'=>['claims', 'claim', 'claim intake', 'adjudication workflow'],
			'Claimant'=>['claimants', 'claimant'],
			'ClaimExposure'=>['claim exposures', 'claim exposure', 'claimexposures', 'claimexposure', 'exposure tracking'],
			'ClaimReserve'=>['claim reserves', 'claim reserve', 'claimreserves', 'claimreserve', 'reserve changes', 'reserve change'],
			'ClaimPayment'=>['claim payments', 'claim payment', 'claimpayments', 'claimpayment', 'payment approvals', 'payment approval'],
			'AdjusterAssignment'=>['adjuster assignments', 'adjuster assignment', 'adjusterassignments', 'adjusterassignment', 'adjuster workload'],
			'ClaimDocument'=>['claim documents', 'claim document', 'claimdocuments', 'claimdocument', 'claim evidence', 'claim evidence documents'],
			'FraudReview'=>['fraud reviews', 'fraud review', 'fraudreviews', 'fraudreview', 'fraud indicators', 'fraud indicator'],
			'SubrogationCase'=>['subrogation cases', 'subrogation case', 'subrogationcases', 'subrogationcase', 'subrogation recovery'],
			'Plant'=>['plants', 'plant', 'plant calendars', 'plant calendar'],
			'WorkCenter'=>['work centers', 'work center', 'workcenters', 'workcenter', 'capacity planning'],
			'Equipment'=>['equipment', 'machines', 'machine'],
			'BillOfMaterial'=>['bills of materials', 'bill of materials', 'bill of material', 'billsofmaterials', 'billsofmaterial', 'billofmaterials', 'billofmaterial', 'bom versioning'],
			'BOMComponent'=>['bom components', 'bom component', 'bomcomponents', 'bomcomponent', 'component requirements', 'component requirement'],
			'ProductionOrder'=>['production orders', 'production order', 'productionorders', 'productionorder', 'shop floor orders', 'shop floor order'],
			'RoutingStep'=>['routing steps', 'routing step', 'routingsteps', 'routingstep', 'manufacturing routing', 'operation routing'],
			'MaterialRequirement'=>['material requirements', 'material requirement', 'materialrequirements', 'materialrequirement', 'material allocation', 'material allocations'],
			'WorkOrderOperation'=>['work order operations', 'work order operation', 'workorderoperations', 'workorderoperation', 'shop floor execution'],
			'QualityInspection'=>['quality inspections', 'quality inspection', 'qualityinspections', 'qualityinspection', 'quality holds', 'quality hold'],
			'DowntimeEvent'=>['downtime events', 'downtime event', 'downtimeevents', 'downtimeevent', 'downtime reason codes', 'downtime reason code'],
			'MaintenanceRequest'=>['maintenance requests', 'maintenance request', 'maintenancerequests', 'maintenancerequest', 'preventive maintenance handoff'],
			'Site'=>['sites', 'site', 'customer sites', 'customer site'],
			'WorkOrder'=>['work orders', 'work order', 'workorders', 'workorder', 'work order scheduling'],
			'TechnicianAssignment'=>['technician assignments', 'technician assignment', 'technicianassignments', 'technicianassignment', 'technician assignment'],
			'QualityAudit'=>['quality audits', 'quality audit', 'qualityaudits', 'qualityaudit', 'audit schedules', 'audit schedule'],
			'AuditFinding'=>['audit findings', 'audit finding', 'auditfindings', 'auditfinding', 'finding severity'],
			'Nonconformance'=>['nonconformances', 'nonconformance', 'non-conformances', 'non-conformance', 'nonconformance disposition'],
			'CAPAPlan'=>['capa plans', 'capa plan', 'capaplans', 'capaplan', 'capa app', 'root cause analysis'],
			'CorrectiveAction'=>['corrective actions', 'corrective action', 'correctiveaction', 'correctiveactions', 'corrective action owners'],
			'PreventiveAction'=>['preventive actions', 'preventive action', 'preventiveaction', 'preventiveactions', 'preventive action verification'],
			'Deviation'=>['deviations', 'deviation', 'deviation approvals', 'deviation approval'],
			'Inspection'=>['inspections', 'inspection'],
			'InspectionChecklist'=>['inspection checklists', 'inspection checklist', 'inspectionchecklists', 'inspectionchecklist'],
			'InspectionItem'=>['inspection items', 'inspection item', 'inspectionitems', 'inspectionitem', 'inspection checklists', 'inspection checklist'],
			'DocumentControl'=>['document controls', 'document control', 'documentcontrols', 'documentcontrol', 'controlled document revisions', 'controlled documents', 'controlled document'],
			'PartUsage'=>['part usages', 'part usage', 'partusages', 'partusage', 'parts consumption', 'part consumption'],
			'InventoryItem'=>['inventory items', 'inventory item', 'inventoryitems', 'inventoryitem'],
			'Warehouse'=>['warehouses', 'warehouse', 'warehouse bins', 'warehouse bin'],
			'StockLocation'=>['stock locations', 'stock location', 'stocklocations', 'stocklocation', 'bins', 'bin locations', 'bin location'],
			'StockLot'=>['stock lots', 'stock lot', 'stocklots', 'stocklot', 'lot traceability', 'lot and serial traceability'],
			'SerialNumber'=>['serial numbers', 'serial number', 'serialnumbers', 'serialnumber', 'serial traceability'],
			'InventoryTransfer'=>['inventory transfers', 'inventory transfer', 'inventorytransfers', 'inventorytransfer', 'transfer approvals', 'transfer approval'],
			'GoodsReceipt'=>['goods receipts', 'goods receipt', 'goodsreceipts', 'goodsreceipt', 'receiving', 'supplier receipts', 'supplier receipt'],
			'PickList'=>['pick lists', 'pick list', 'picklists', 'picklist', 'pick pack ship', 'pick/pack/ship', 'pick workflows'],
			'CycleCount'=>['cycle counts', 'cycle count', 'cyclecounts', 'cyclecount', 'cycle count variance review'],
			'InventoryAdjustment'=>['inventory adjustments', 'inventory adjustment', 'inventoryadjustments', 'inventoryadjustment', 'inventory adjustment approvals'],
			'Supplier'=>['suppliers', 'supplier'],
			'ServiceContract'=>['service contracts', 'service contract', 'servicecontracts', 'servicecontract', 'contract coverage'],
			'DataAsset'=>['data assets', 'data asset', 'dataassets', 'dataasset', 'data catalog', 'catalog assets', 'catalog asset'],
			'DataClassification'=>['data classifications', 'data classification', 'dataclassifications', 'dataclassification', 'classification rules', 'classification rule'],
			'DataOwner'=>['data owners', 'data owner', 'dataowners', 'dataowner', 'owner stewardship', 'data stewardship'],
			'DataLineageEdge'=>['data lineage edges', 'data lineage edge', 'datalineageedges', 'datalineageedge', 'lineage graph', 'lineage edges', 'lineage edge'],
			'DataAccessRequest'=>['data access requests', 'data access request', 'dataaccessrequests', 'dataaccessrequest', 'access requests', 'access request'],
			'AccessApproval'=>['access approvals', 'access approval', 'accessapprovals', 'accessapproval'],
			'RetentionSchedule'=>['retention schedules', 'retention schedule', 'retentionschedules', 'retentionschedule', 'retention rules', 'retention rule'],
			'Asset'=>['assets', 'asset', 'asset inventory'],
			'Incident'=>['incidents', 'incident'],
			'ServiceHealth'=>['service health', 'servicehealth', 'health checks', 'health check', 'service status', 'service statuses'],
			'StatusUpdate'=>['status updates', 'status update', 'statusupdates', 'statusupdate', 'status page updates', 'status page update'],
			'DiagnosticBundle'=>['diagnostic bundles', 'diagnostic bundle', 'diagnosticbundles', 'diagnosticbundle', 'copy safe diagnostics', 'copy-safe diagnostics'],
			'Runbook'=>['runbooks', 'runbook'],
			'SecurityIncident'=>['security incidents', 'security incident', 'securityincidents', 'securityincident', 'incident response', 'incident response app'],
			'Alert'=>['alerts', 'alert', 'alert triage', 'severity queues', 'severity queue'],
			'AlertRule'=>['alert rules', 'alert rule', 'alertrules', 'alertrule'],
			'IncidentAssignment'=>['incident assignments', 'incident assignment', 'incident commander assignment', 'commander assignment', 'responder assignments', 'responder assignment'],
			'IncidentTimelineEvent'=>['incident timeline events', 'incident timeline event', 'timeline events', 'timeline event', 'incident timeline'],
			'EvidenceItem'=>['evidence items', 'evidence item', 'evidence chain of custody', 'chain of custody'],
			'ContainmentAction'=>['containment actions', 'containment action', 'containment approvals', 'containment approval'],
			'RemediationTask'=>['remediation tasks', 'remediation task', 'remediation slas', 'remediation sla'],
			'Vulnerability'=>['vulnerabilities', 'vulnerability', 'vulnerability links', 'vulnerability link', 'cve links', 'cve link'],
			'Postmortem'=>['postmortems', 'postmortem', 'postmortem action items', 'postmortem action item'],
			'ChangeRequest'=>['change requests', 'change request', 'change tickets', 'change ticket'],
			'Expense'=>['expenses', 'expense'],
			'Budget'=>['budgets', 'budget'],
			'CostCenter'=>['cost centers', 'cost center', 'cost centres', 'cost centre'],
			'Company'=>['companies', 'company'],
			'Department'=>['departments', 'department'],
			'Employee'=>['employees', 'employee'],
			'EmploymentContract'=>['employment contracts', 'employment contract', 'employmentcontracts', 'employmentcontract'],
			'CompensationPlan'=>['compensation plans', 'compensation plan', 'compensationplans', 'compensationplan'],
			'BenefitsEnrollment'=>['benefits enrollments', 'benefits enrollment', 'benefitsenrollments', 'benefitsenrollment', 'benefit enrollments', 'benefit enrollment'],
			'PTORequest'=>['pto requests', 'pto request', 'ptorequests', 'ptorequest', 'time off requests', 'time off request'],
			'Timesheet'=>['timesheets', 'timesheet'],
			'PerformanceReview'=>['performance reviews', 'performance review', 'performancereviews', 'performancereview'],
			'Goal'=>['goals', 'goal'],
			'Learner'=>['learners', 'learner'],
			'Manager'=>['managers', 'manager'],
			'Course'=>['courses', 'course'],
			'Module'=>['modules', 'module'],
			'Lesson'=>['lessons', 'lesson'],
			'Assignment'=>['assignments', 'assignment', 'due dates', 'due date'],
			'Attestation'=>['attestations', 'attestation'],
			'Certificate'=>['certificates', 'certificate'],
			'Quiz'=>['quizzes', 'quiz'],
			'Question'=>['questions', 'question'],
			'Attempt'=>['attempts', 'attempt'],
			'PolicyAcknowledgement'=>['policy acknowledgements', 'policy acknowledgement', 'policy acknowledgments', 'policy acknowledgment'],
			'Provider'=>['providers', 'provider'],
			'ProviderProfile'=>['provider profiles', 'provider profile', 'providerprofiles', 'providerprofile'],
			'Certification'=>['certifications', 'certification'],
			'CredentialingApplication'=>['credentialing applications', 'credentialing application', 'credentialingapplications', 'credentialingapplication'],
			'CredentialingStep'=>['credentialing steps', 'credentialing step', 'credentialingsteps', 'credentialingstep'],
			'Verification'=>['verifications', 'verification'],
			'PayerEnrollment'=>['payer enrollments', 'payer enrollment', 'payerenrollments', 'payerenrollment'],
			'NetworkContract'=>['network contracts', 'network contract', 'networkcontracts', 'networkcontract'],
			'Facility'=>['facilities', 'facility'],
			'Privilege'=>['privileges', 'privilege', 'provider privileges', 'provider privilege'],
			'Expiration'=>['expirations', 'expiration'],
			'BackgroundCheck'=>['background checks', 'background check', 'backgroundchecks', 'backgroundcheck'],
			'SanctionCheck'=>['sanction checks', 'sanction check', 'sanctionchecks', 'sanctioncheck'],
			'CommitteeReview'=>['committee reviews', 'committee review', 'committeereviews', 'committeereview'],
			'ApprovalDecision'=>['approval decisions', 'approval decision', 'approvaldecisions', 'approvaldecision'],
			'TrainingAssignment'=>['training assignments', 'training assignment', 'trainingassignments', 'trainingassignment'],
			'ComplianceAttestation'=>['compliance attestations', 'compliance attestation', 'complianceattestations', 'complianceattestation'],
			'VendorRiskAssessment'=>['vendor risk assessments', 'vendor risk assessment', 'vendorriskassessments', 'vendorriskassessment'],
			'Framework'=>['frameworks', 'framework', 'compliance frameworks', 'compliance framework', 'control frameworks', 'control framework'],
			'Control'=>['controls', 'control', 'control catalog', 'control catalogs', 'compliance controls', 'compliance control'],
			'PolicyVersion'=>['policy versions', 'policy version', 'policyversions', 'policyversion', 'effective dated policy versions', 'effective-dated policy versions', 'policy revision', 'policy revisions'],
			'ControlTest'=>['control tests', 'control test', 'controltests', 'controltest'],
			'EvidenceRequest'=>['evidence requests', 'evidence request', 'evidencerequests', 'evidencerequest'],
			'EvidenceUpload'=>['evidence uploads', 'evidence upload', 'evidenceuploads', 'evidenceupload'],
			'EvidencePackage'=>['evidence packages', 'evidence package', 'evidencepackages', 'evidencepackage', 'audit evidence packages', 'audit evidence package', 'compliance evidence packages', 'compliance evidence package'],
			'PolicyException'=>['policy exceptions', 'policy exception', 'exceptions', 'exception'],
			'RiskFinding'=>['risk findings', 'risk finding', 'riskfindings', 'riskfinding'],
			'ReviewCycle'=>['review cycles', 'review cycle', 'reviewcycles', 'reviewcycle', 'review cadence', 'review cadences'],
			'RemediationPlan'=>['remediation plans', 'remediation plan', 'remediationplans', 'remediationplan'],
			'Report'=>['reports', 'report'],
			'Dashboard'=>['dashboards', 'dashboard'],
			'ReportWidget'=>['report widgets', 'report widget', 'reportwidgets', 'reportwidget', 'dashboard widgets', 'dashboard widget'],
			'MetricDefinition'=>['metric definitions', 'metric definition', 'metricdefinitions', 'metricdefinition', 'metrics', 'metric formulas', 'metric formula'],
			'ReportRun'=>['report runs', 'report run', 'reportruns', 'reportrun', 'scheduled report runs', 'scheduled report run'],
			'ReportSubscription'=>['report subscriptions', 'report subscription', 'reportsubscriptions', 'reportsubscription', 'subscribed reports', 'subscribed report'],
			'Notification'=>['notifications', 'notification'],
			'NotificationTemplate'=>['notification templates', 'notification template', 'notificationtemplates', 'notificationtemplate', 'template variables', 'template variable'],
			'NotificationChannel'=>['notification channels', 'notification channel', 'notificationchannels', 'notificationchannel', 'channel adapters', 'channel adapter'],
			'NotificationPreference'=>['notification preferences', 'notification preference', 'notificationpreferences', 'notificationpreference', 'recipient preferences', 'recipient preference', 'quiet hours'],
			'NotificationSuppression'=>['notification suppressions', 'notification suppression', 'notificationsuppressions', 'notificationsuppression', 'suppression windows', 'suppression window'],
			'NotificationDelivery'=>['notification deliveries', 'notification delivery', 'notificationdeliveries', 'notificationdelivery'],
			'DeliveryReceipt'=>['delivery receipts', 'delivery receipt', 'deliveryreceipts', 'deliveryreceipt'],
			'EscalationMessage'=>['escalation messages', 'escalation message', 'escalationmessages', 'escalationmessage', 'escalation fallback', 'fallback messages', 'fallback message'],
			'Seat'=>['seats', 'seat'],
			'License'=>['licenses', 'license', 'licences', 'licence'],
			'SLAIncident'=>['sla incidents', 'sla incident', 'sla breaches', 'sla breach'],
			'Connector'=>['connectors', 'connector'],
			'IntegrationConnection'=>['integration connections', 'integration connection', 'provider connections', 'provider connection', 'oauth connections', 'oauth connection'],
			'ExternalObjectMap'=>['external object maps', 'external object map', 'external id maps', 'external id map', 'provider object maps', 'provider object map'],
			'SyncRun'=>['sync runs', 'sync run', 'sync jobs', 'sync job', 'synchronization runs', 'synchronization run'],
			'SyncCheckpoint'=>['sync checkpoints', 'sync checkpoint', 'sync cursors', 'sync cursor', 'sync resume state'],
			'ImportBatch'=>['import batches', 'import batch', 'import jobs', 'import job', 'delta imports', 'delta import'],
			'ExportJob'=>['export jobs', 'export job', 'export batches', 'export batch'],
			'OutboxEvent'=>['outbox events', 'outbox event', 'outboxevents', 'outboxevent', 'reliable outbox events', 'reliable outbox event'],
			'JobRun'=>['job runs', 'job run', 'jobruns', 'jobrun', 'worker runs', 'worker run'],
			'ScheduledJob'=>['scheduled jobs', 'scheduled job', 'scheduledjobs', 'scheduledjob', 'recurring jobs', 'recurring job'],
			'DeadLetterEvent'=>['dead letter events', 'dead letter event', 'deadletterevents', 'deadletterevent', 'dead-letter events', 'dead-letter event'],
			'CaseRecord'=>['case records', 'case record', 'caserecords', 'caserecord', 'cases', 'case management', 'case intake', 'intake triage'],
			'CaseParticipant'=>['case participants', 'case participant', 'caseparticipants', 'caseparticipant', 'participant roles', 'participant role'],
			'CaseAssignment'=>['case assignments', 'case assignment', 'caseassignments', 'caseassignment', 'investigator assignment', 'investigator assignments'],
			'CaseComment'=>['case comments', 'case comment', 'casecomments', 'casecomment', 'threaded comments', 'threaded comment'],
			'CaseDocument'=>['case documents', 'case document', 'casedocuments', 'casedocument', 'document evidence'],
			'CaseDecision'=>['case decisions', 'case decision', 'casedecisions', 'casedecision', 'decision reasons', 'decision reason'],
			'CaseSLA'=>['case slas', 'case sla', 'caseslas', 'casesla', 'sla breach tracking'],
			'CaseEvent'=>['case events', 'case event', 'caseevents', 'caseevent', 'case history', 'event history'],
			'AgentProfile'=>['agent profiles', 'agent profile', 'agentprofiles', 'agentprofile', 'agents', 'agent ownership'],
			'PromptTemplate'=>['prompt templates', 'prompt template', 'prompttemplates', 'prompttemplate'],
			'PromptVersion'=>['prompt versions', 'prompt version', 'promptversions', 'promptversion', 'prompt versioning'],
			'ToolPermission'=>['tool permissions', 'tool permission', 'toolpermissions', 'toolpermission', 'tool allowlists', 'tool allowlist'],
			'ModelPolicy'=>['model policies', 'model policy', 'modelpolicies', 'modelpolicy', 'model policy approvals', 'model policy approval'],
			'EvaluationRun'=>['evaluation runs', 'evaluation run', 'evaluationruns', 'evaluationrun', 'eval datasets', 'eval dataset'],
			'EvaluationFinding'=>['evaluation findings', 'evaluation finding', 'evaluationfindings', 'evaluationfinding'],
			'SafetyReview'=>['safety reviews', 'safety review', 'safetyreviews', 'safetyreview', 'safety review gates', 'safety review gate'],
			'AgentIncident'=>['agent incidents', 'agent incident', 'agentincidents', 'agentincident', 'agent incident response'],
			'Program'=>['programs', 'program', 'program ownership'],
			'Project'=>['projects', 'project', 'portfolio projects', 'portfolio project'],
			'Milestone'=>['milestones', 'milestone', 'milestone health'],
			'ProjectTask'=>['project tasks', 'project task', 'projecttasks', 'projecttask', 'task assignment', 'task assignments'],
			'ProjectDependency'=>['project dependencies', 'project dependency', 'projectdependencies', 'projectdependency', 'dependency blockers', 'dependency blocker'],
			'ProjectRisk'=>['project risks', 'project risk', 'projectrisks', 'projectrisk', 'raid risks', 'raid risk', 'raid logs'],
			'ProjectIssue'=>['project issues', 'project issue', 'projectissues', 'projectissue', 'raid issues', 'raid issue'],
			'DecisionLog'=>['decision logs', 'decision log', 'decisionlogs', 'decisionlog', 'decision records', 'decision record'],
			'Stakeholder'=>['stakeholders', 'stakeholder', 'stakeholder updates', 'stakeholder update'],
			'Vendor'=>['vendors', 'vendor'],
			'VendorContact'=>['vendor contacts', 'vendor contact'],
			'Product'=>['products', 'product'],
			'PurchaseRequest'=>['purchase requests', 'purchase request', 'purchaserequests', 'purchaserequest'],
			'PurchaseRequestLine'=>['purchase request lines', 'purchase request line', 'request lines', 'request line', 'line items', 'line item'],
			'ApprovalWorkflow'=>['approval workflows', 'approval workflow', 'review workflows', 'review workflow'],
			'ApprovalStep'=>['approval steps', 'approval step', 'approvals', 'approval'],
			'PurchaseOrder'=>['purchase orders', 'purchase order', 'purchaseorders', 'purchaseorder'],
			'Shipment'=>['shipments', 'shipment'],
			'WebhookEndpoint'=>['webhook endpoints', 'webhook endpoint', 'webhookendpoints', 'webhookendpoint'],
			'WebhookDelivery'=>['webhook deliveries', 'webhook delivery', 'webhook delivery jobs', 'webhook delivery job', 'delivery attempts', 'delivery attempt', 'webhook retries', 'webhook retry'],
			'Document'=>['documents', 'document', 'attachments', 'attachment'],
		];
		$found=[];
		foreach($candidates as $entity=>$phrases){
			$position=null;
			foreach($phrases as $phrase){
				if($entity==='Team' && in_array($phrase, ['teams', 'team'], true)){
					$team_is_audience=preg_match('/\bfor\s+(?:[a-z0-9-]+\s+){0,3}'.preg_quote($phrase, '/').'\b/', $lower)===1;
					if($team_is_audience && !$this->bounded_phrase_match($lower, 'with teams') && !$this->bounded_phrase_match($lower, 'teams,') && !$this->bounded_phrase_match($lower, 'teams and') && !$this->bounded_phrase_match($lower, 'team crud') && !$this->bounded_phrase_match($lower, 'team admin')){
						continue;
					}
				}
				if($entity==='Role' && in_array($phrase, ['roles', 'role'], true) && ($this->bounded_phrase_match($lower, 'participant roles') || $this->bounded_phrase_match($lower, 'participant role'))){
					continue;
				}
				if($entity==='Company' && in_array($phrase, ['companies', 'company'], true)){
					$company_is_audience=preg_match('/\bfor\s+(?:[a-z0-9-]+\s+){0,3}'.preg_quote($phrase, '/').'\b/', $lower)===1;
					if($company_is_audience && !$this->bounded_phrase_match($lower, 'with companies') && !$this->bounded_phrase_match($lower, 'companies,') && !$this->bounded_phrase_match($lower, 'companies and') && !$this->bounded_phrase_match($lower, 'company crud') && !$this->bounded_phrase_match($lower, 'company admin')){
						continue;
					}
				}
				if($entity==='Tenant' && $phrase==='tenant' && ($this->bounded_phrase_match($lower, 'tenant scoping') || $this->bounded_phrase_match($lower, 'tenant scope') || $this->bounded_phrase_match($lower, 'tenant policy') || $this->bounded_phrase_match($lower, 'tenant filters') || $this->bounded_phrase_match($lower, 'tenant isolation'))){
					continue;
				}
				if($entity==='Tenant' && in_array($phrase, ['tenants', 'tenant'], true) && $this->app_builder_has_property_lease_context($lower)){
					continue;
				}
				if($entity==='TenantProfile' && in_array($phrase, ['tenants', 'tenant profiles', 'tenant profile'], true) && !($this->app_builder_has_property_lease_context($lower) || $this->bounded_phrase_match($lower, 'lease tenants') || $this->bounded_phrase_match($lower, 'lease tenant'))){
					continue;
				}
				if($entity==='Plan' && $phrase==='plans' && ($this->bounded_phrase_match($lower, 'success plans') || $this->bounded_phrase_match($lower, 'rollout plans') || $this->bounded_phrase_match($lower, 'rollback plans') || $this->bounded_phrase_match($lower, 'recovery plans') || $this->bounded_phrase_match($lower, 'remediation plans'))){
					continue;
				}
				if($entity==='Portfolio' && in_array($phrase, ['portfolios', 'portfolio'], true) && ($this->bounded_phrase_match($lower, 'project portfolio') || $this->bounded_phrase_match($lower, 'project portfolios') || $this->bounded_phrase_match($lower, 'portfolio projects') || $this->bounded_phrase_match($lower, 'portfolio project') || $this->bounded_phrase_match($lower, 'portfolio management'))){
					continue;
				}
				if($entity==='Policy' && in_array($phrase, ['policies'], true) && ($this->bounded_phrase_match($lower, 'retention policies') || $this->bounded_phrase_match($lower, 'retention policy') || $this->bounded_phrase_match($lower, 'session policies') || $this->bounded_phrase_match($lower, 'session policy') || $this->bounded_phrase_match($lower, 'model policies') || $this->bounded_phrase_match($lower, 'model policy') || $this->bounded_phrase_match($lower, 'rate limit policies') || $this->bounded_phrase_match($lower, 'rate limit policy') || $this->bounded_phrase_match($lower, 'sla policies') || $this->bounded_phrase_match($lower, 'sla policy'))){
					continue;
				}
				if($entity==='ServiceAccount' && in_array($phrase, ['service account', 'service accounts'], true) && ($this->bounded_phrase_match($lower, 'service catalog') || $this->bounded_phrase_match($lower, 'service requests') || $this->bounded_phrase_match($lower, 'service request') || $this->bounded_phrase_match($lower, 'it service') || $this->bounded_phrase_match($lower, 'it services'))){
					continue;
				}
				if($entity==='UsageMeter' && in_array($phrase, ['usage events', 'usage event'], true) && ($this->bounded_phrase_match($lower, 'api usage events') || $this->bounded_phrase_match($lower, 'api usage event') || $this->bounded_phrase_match($lower, 'usage metering'))){
					continue;
				}
				if($entity==='Payment' && in_array($phrase, ['payments', 'payment'], true) && ($this->bounded_phrase_match($lower, 'claim payments') || $this->bounded_phrase_match($lower, 'claim payment') || $this->bounded_phrase_match($lower, 'payment approvals') || $this->bounded_phrase_match($lower, 'payment approval'))){
					continue;
				}
				if($entity==='Payment' && in_array($phrase, ['payments', 'payment'], true) && ($this->bounded_phrase_match($lower, 'repayments') || $this->bounded_phrase_match($lower, 'repayment') || $this->bounded_phrase_match($lower, 'repayment reconciliation'))){
					continue;
				}
				if($entity==='Payment' && in_array($phrase, ['payments', 'payment'], true) && ($this->bounded_phrase_match($lower, 'rent payments') || $this->bounded_phrase_match($lower, 'rent payment') || $this->bounded_phrase_match($lower, 'payment reconciliation')) && $this->app_builder_has_property_lease_context($lower)){
					continue;
				}
				if($entity==='RentPayment' && in_array($phrase, ['rent payments', 'rent payment', 'payment reconciliation'], true) && !$this->app_builder_has_property_lease_context($lower)){
					continue;
				}
				if($entity==='Customer' && $phrase==='customer' && ($this->bounded_phrase_match($lower, 'customer success') || $this->bounded_phrase_match($lower, 'customer onboarding') || $this->bounded_phrase_match($lower, 'customer operations'))){
					continue;
				}
				if($entity==='Account' && $phrase==='account' && ($this->bounded_phrase_match($lower, 'account team') || $this->bounded_phrase_match($lower, 'account teams'))){
					continue;
				}
				if($entity==='Contact' && $phrase==='contacts' && $this->bounded_phrase_match($lower, 'vendor contacts')){
					continue;
				}
				if($entity==='Vendor' && in_array($phrase, ['vendors', 'vendor'], true) && ($this->bounded_phrase_match($lower, 'suppliers') || $this->bounded_phrase_match($lower, 'supplier receipts') || $this->bounded_phrase_match($lower, 'supplier receipt'))){
					continue;
				}
				if($entity==='RiskReview' && $phrase==='risk review' && $this->bounded_phrase_match($lower, 'risk reviews')){
					continue;
				}
				if($entity==='RiskReview' && in_array($phrase, ['risk reviews', 'risk review'], true) && ($this->bounded_phrase_match($lower, 'credit decisions') || $this->bounded_phrase_match($lower, 'credit decision') || $this->bounded_phrase_match($lower, 'underwriting workflow'))){
					continue;
				}
				if($entity==='RenewalOpportunity' && in_array($phrase, ['renewals', 'renewal'], true) && ($this->bounded_phrase_match($lower, 'renewal workflows') || $this->bounded_phrase_match($lower, 'renewal offers') || $this->bounded_phrase_match($lower, 'renewal offer') || $this->bounded_phrase_match($lower, 'leases') || $this->bounded_phrase_match($lower, 'lease lifecycle'))){
					continue;
				}
				if($entity==='Risk' && $phrase==='risk' && ($this->bounded_phrase_match($lower, 'risk assessments') || $this->bounded_phrase_match($lower, 'risk assessment') || $this->bounded_phrase_match($lower, 'risk scoring') || $this->bounded_phrase_match($lower, 'vendor risk'))){
					continue;
				}
				if($entity==='Risk' && in_array($phrase, ['risks', 'risk'], true) && ($this->bounded_phrase_match($lower, 'project risks') || $this->bounded_phrase_match($lower, 'project risk') || $this->bounded_phrase_match($lower, 'raid logs'))){
					continue;
				}
				if($entity==='Escalation' && $phrase==='escalation' && ($this->bounded_phrase_match($lower, 'escalation fallback') || $this->bounded_phrase_match($lower, 'escalation message') || $this->bounded_phrase_match($lower, 'escalation messages'))){
					continue;
				}
				if($entity==='Report' && $phrase==='report' && ($this->bounded_phrase_match($lower, 'reporting operations') || $this->bounded_phrase_match($lower, 'reporting app') || $this->bounded_phrase_match($lower, 'reporting analytics'))){
					continue;
				}
				if($entity==='Notification' && $phrase==='notification' && ($this->bounded_phrase_match($lower, 'notification operations') || $this->bounded_phrase_match($lower, 'notification template') || $this->bounded_phrase_match($lower, 'notification templates') || $this->bounded_phrase_match($lower, 'notification channel') || $this->bounded_phrase_match($lower, 'notification channels') || $this->bounded_phrase_match($lower, 'notification preference') || $this->bounded_phrase_match($lower, 'notification preferences') || $this->bounded_phrase_match($lower, 'notification delivery') || $this->bounded_phrase_match($lower, 'notification deliveries') || $this->bounded_phrase_match($lower, 'notification suppression') || $this->bounded_phrase_match($lower, 'notification suppressions'))){
					continue;
				}
				if($entity==='Contract' && $phrase==='contract' && ($this->bounded_phrase_match($lower, 'contract lifecycle') || $this->bounded_phrase_match($lower, 'contract management') || $this->bounded_phrase_match($lower, 'contract owners'))){
					continue;
				}
				if($entity==='Contract' && in_array($phrase, ['contracts', 'contract'], true) && ($this->bounded_phrase_match($lower, 'service contracts') || $this->bounded_phrase_match($lower, 'service contract') || $this->bounded_phrase_match($lower, 'contract coverage'))){
					continue;
				}
				if($entity==='ServiceContract' && in_array($phrase, ['service contracts', 'service contract'], true) && ($this->bounded_phrase_match($lower, 'service catalog') || $this->bounded_phrase_match($lower, 'service requests') || $this->bounded_phrase_match($lower, 'service request'))){
					continue;
				}
				if($entity==='Asset' && in_array($phrase, ['assets', 'asset'], true) && ($this->bounded_phrase_match($lower, 'data assets') || $this->bounded_phrase_match($lower, 'data asset') || $this->bounded_phrase_match($lower, 'catalog assets') || $this->bounded_phrase_match($lower, 'catalog asset'))){
					continue;
				}
				if($entity==='Site' && in_array($phrase, ['sites', 'site'], true) && ($this->bounded_phrase_match($lower, 'study sites') || $this->bounded_phrase_match($lower, 'study site') || $this->bounded_phrase_match($lower, 'site activation'))){
					continue;
				}
				if($entity==='Incident' && in_array($phrase, ['incidents', 'incident'], true) && ($this->bounded_phrase_match($lower, 'agent incidents') || $this->bounded_phrase_match($lower, 'agent incident') || $this->bounded_phrase_match($lower, 'agentincidents') || $this->bounded_phrase_match($lower, 'agentincident') || $this->bounded_phrase_match($lower, 'security incidents') || $this->bounded_phrase_match($lower, 'security incident') || $this->bounded_phrase_match($lower, 'securityincidents') || $this->bounded_phrase_match($lower, 'securityincident') || $this->bounded_phrase_match($lower, 'incident response'))){
					continue;
				}
				if($entity==='SLAIncident' && in_array($phrase, ['sla breaches', 'sla breach'], true) && ($this->bounded_phrase_match($lower, 'case slas') || $this->bounded_phrase_match($lower, 'case sla') || $this->bounded_phrase_match($lower, 'sla breach tracking'))){
					continue;
				}
				if($entity==='Claim' && $phrase==='claims' && $this->bounded_phrase_match($lower, 'claims operations')){
					continue;
				}
				if($entity==='Claim' && in_array($phrase, ['claims', 'claim'], true) && ($this->bounded_phrase_match($lower, 'queue claims') || $this->bounded_phrase_match($lower, 'job claims') || $this->bounded_phrase_match($lower, 'worker claims') || $this->bounded_phrase_match($lower, 'claim jobs') || $this->bounded_phrase_match($lower, 'claim locks'))){
					continue;
				}
				if($entity==='CaseSLA' && in_array($phrase, ['sla breach tracking'], true) && !$this->bounded_phrase_match($lower, 'case management') && !$this->bounded_phrase_match($lower, 'cases') && !$this->bounded_phrase_match($lower, 'case records')){
					continue;
				}
				if($entity==='CaseRecord' && $phrase==='case management' && ($this->bounded_phrase_match($lower, 'cases') || $this->bounded_phrase_match($lower, 'case records') || $this->bounded_phrase_match($lower, 'case record'))){
					continue;
				}
				if($entity==='Participant' && in_array($phrase, ['participants', 'participant'], true) && ($this->bounded_phrase_match($lower, 'case participants') || $this->bounded_phrase_match($lower, 'case participant') || $this->bounded_phrase_match($lower, 'participant roles') || $this->bounded_phrase_match($lower, 'participant role'))){
					continue;
				}
				if($entity==='CaseDecision' && in_array($phrase, ['decision reasons', 'decision reason'], true) && ($this->bounded_phrase_match($lower, 'credit decision reasons') || $this->bounded_phrase_match($lower, 'credit decision reason') || $this->bounded_phrase_match($lower, 'credit decisions') || $this->bounded_phrase_match($lower, 'credit decision'))){
					continue;
				}
				if($entity==='CaseDocument' && in_array($phrase, ['document evidence'], true) && ($this->bounded_phrase_match($lower, 'claims') || $this->bounded_phrase_match($lower, 'claim documents') || $this->bounded_phrase_match($lower, 'claim document'))){
					continue;
				}
				if($entity==='Comment' && in_array($phrase, ['comment', 'comments'], true) && ($this->bounded_phrase_match($lower, 'case comments') || $this->bounded_phrase_match($lower, 'case comment') || $this->bounded_phrase_match($lower, 'threaded comments') || $this->bounded_phrase_match($lower, 'threaded comment'))){
					continue;
				}
				if($entity==='Document' && in_array($phrase, ['documents', 'document'], true) && ($this->bounded_phrase_match($lower, 'case documents') || $this->bounded_phrase_match($lower, 'case document') || $this->bounded_phrase_match($lower, 'document evidence'))){
					continue;
				}
				if($entity==='Document' && in_array($phrase, ['documents', 'document'], true) && ($this->bounded_phrase_match($lower, 'claim documents') || $this->bounded_phrase_match($lower, 'claim document') || $this->bounded_phrase_match($lower, 'claim evidence') || $this->bounded_phrase_match($lower, 'claim evidence documents'))){
					continue;
				}
				if($entity==='Document' && in_array($phrase, ['documents', 'document'], true) && ($this->bounded_phrase_match($lower, 'document control') || $this->bounded_phrase_match($lower, 'document controls') || $this->bounded_phrase_match($lower, 'controlled documents') || $this->bounded_phrase_match($lower, 'controlled document') || $this->bounded_phrase_match($lower, 'controlled document revisions'))){
					continue;
				}
				if($entity==='Document' && in_array($phrase, ['documents', 'document'], true) && ($this->bounded_phrase_match($lower, 'consent forms') || $this->bounded_phrase_match($lower, 'consent form') || $this->bounded_phrase_match($lower, 'informed consent'))){
					continue;
				}
				if($entity==='EvidenceRequest' && in_array($phrase, ['evidence requests', 'evidence request'], true) && ($this->bounded_phrase_match($lower, 'evidence chain of custody') || $this->bounded_phrase_match($lower, 'evidence items') || $this->bounded_phrase_match($lower, 'evidence item'))){
					continue;
				}
				if($entity==='EvidenceUpload' && in_array($phrase, ['evidence uploads', 'evidence upload'], true) && ($this->bounded_phrase_match($lower, 'evidence chain of custody') || $this->bounded_phrase_match($lower, 'evidence items') || $this->bounded_phrase_match($lower, 'evidence item'))){
					continue;
				}
				if($entity==='RemediationPlan' && in_array($phrase, ['remediation plans', 'remediation plan'], true) && ($this->bounded_phrase_match($lower, 'remediation tasks') || $this->bounded_phrase_match($lower, 'remediation task') || $this->bounded_phrase_match($lower, 'remediation slas') || $this->bounded_phrase_match($lower, 'remediation sla'))){
					continue;
				}
				if($entity==='Assignment' && in_array($phrase, ['assignments', 'assignment'], true) && !$this->app_builder_has_learning_compliance_context($lower)){
					continue;
				}
				if($entity==='Verification' && in_array($phrase, ['verifications', 'verification'], true) && !$this->app_builder_has_provider_credentialing_context($lower)){
					continue;
				}
				if($entity==='Provider' && in_array($phrase, ['providers', 'provider'], true) && !$this->app_builder_has_provider_credentialing_context($lower)){
					continue;
				}
				if($entity==='Webhook' && $phrase==='webhook' && ($this->bounded_phrase_match($lower, 'webhook endpoint') || $this->bounded_phrase_match($lower, 'webhook endpoints') || $this->bounded_phrase_match($lower, 'webhookendpoint') || $this->bounded_phrase_match($lower, 'webhookendpoints') || $this->bounded_phrase_match($lower, 'webhook retry') || $this->bounded_phrase_match($lower, 'webhook retries') || $this->bounded_phrase_match($lower, 'webhook delivery'))){
					continue;
				}
				if($entity==='Framework' && in_array($phrase, ['framework', 'frameworks'], true) && ($this->bounded_phrase_match($lower, 'dataphyre framework') || $this->bounded_phrase_match($lower, 'dataphyre frameworks') || $this->bounded_phrase_match($lower, 'framework internals') || $this->bounded_phrase_match($lower, 'framework users'))){
					continue;
				}
				if($entity==='Control' && in_array($phrase, ['control', 'controls'], true) && ($this->bounded_phrase_match($lower, 'access controls') || $this->bounded_phrase_match($lower, 'rollout controls') || $this->bounded_phrase_match($lower, 'ui controls') || $this->bounded_phrase_match($lower, 'form controls') || $this->bounded_phrase_match($lower, 'control plane'))){
					continue;
				}
				if($entity==='Control' && in_array($phrase, ['control', 'controls'], true) && ($this->bounded_phrase_match($lower, 'finance controls') || $this->bounded_phrase_match($lower, 'close controls'))){
					continue;
				}
				if($entity==='Control' && in_array($phrase, ['control', 'controls'], true) && ($this->bounded_phrase_match($lower, 'change control') || $this->bounded_phrase_match($lower, 'portfolio control'))){
					continue;
				}
				if($entity==='PurchaseRequestLine' && in_array($phrase, ['line items', 'line item'], true) && ($this->bounded_phrase_match($lower, 'invoice line items') || $this->bounded_phrase_match($lower, 'invoice line item'))){
					continue;
				}
				if($entity==='Task' && in_array($phrase, ['tasks', 'task'], true) && ($this->bounded_phrase_match($lower, 'project tasks') || $this->bounded_phrase_match($lower, 'project task') || $this->bounded_phrase_match($lower, 'task assignment') || $this->bounded_phrase_match($lower, 'task assignments'))){
					continue;
				}
				if($entity==='Issue' && in_array($phrase, ['issues', 'issue'], true) && ($this->bounded_phrase_match($lower, 'project issues') || $this->bounded_phrase_match($lower, 'project issue') || $this->bounded_phrase_match($lower, 'raid logs'))){
					continue;
				}
				if($entity==='InspectionItem' && in_array($phrase, ['inspection checklists', 'inspection checklist'], true)){
					continue;
				}
				if($entity==='EvaluationFinding' && in_array($phrase, ['finding severity'], true) && ($this->bounded_phrase_match($lower, 'audit findings') || $this->bounded_phrase_match($lower, 'audit finding') || $this->bounded_phrase_match($lower, 'quality audit'))){
					continue;
				}
				if($entity==='AuditFinding' && in_array($phrase, ['audit findings', 'audit finding', 'finding severity'], true) && ($this->bounded_phrase_match($lower, 'monitor findings') || $this->bounded_phrase_match($lower, 'monitor finding'))){
					continue;
				}
				if($entity==='Deviation' && in_array($phrase, ['deviations', 'deviation', 'deviation approvals', 'deviation approval'], true) && ($this->bounded_phrase_match($lower, 'protocol deviations') || $this->bounded_phrase_match($lower, 'protocol deviation') || $this->bounded_phrase_match($lower, 'protocol deviation review'))){
					continue;
				}
				if($entity==='Product' && in_array($phrase, ['products', 'product'], true) && ($this->bounded_phrase_match($lower, 'api products') || $this->bounded_phrase_match($lower, 'api product') || $this->bounded_phrase_match($lower, 'api product catalog'))){
					continue;
				}
				if($entity==='Product' && in_array($phrase, ['products', 'product'], true) && ($this->bounded_phrase_match($lower, 'loan products') || $this->bounded_phrase_match($lower, 'loan product') || $this->bounded_phrase_match($lower, 'loanproducts') || $this->bounded_phrase_match($lower, 'loanproduct'))){
					continue;
				}
				if($entity==='WorkOrder' && in_array($phrase, ['work orders', 'work order', 'workorders', 'workorder'], true) && ($this->bounded_phrase_match($lower, 'work order operations') || $this->bounded_phrase_match($lower, 'work order operation') || $this->bounded_phrase_match($lower, 'workorderoperations') || $this->bounded_phrase_match($lower, 'workorderoperation'))){
					continue;
				}
				if($entity==='Order' && in_array($phrase, ['order', 'orders'], true) && ($this->bounded_phrase_match($lower, 'work orders') || $this->bounded_phrase_match($lower, 'work order') || $this->bounded_phrase_match($lower, 'workorders') || $this->bounded_phrase_match($lower, 'workorder'))){
					continue;
				}
				if($entity==='Order' && in_array($phrase, ['order', 'orders'], true) && ($this->bounded_phrase_match($lower, 'work order vendor dispatch') || $this->bounded_phrase_match($lower, 'maintenance triage'))){
					continue;
				}
				if($entity==='Order' && in_array($phrase, ['order', 'orders'], true) && ($this->bounded_phrase_match($lower, 'purchase order') || $this->bounded_phrase_match($lower, 'purchase orders') || $this->bounded_phrase_match($lower, 'purchase order matching'))){
					continue;
				}
				if($entity==='Order' && in_array($phrase, ['order', 'orders'], true) && ($this->bounded_phrase_match($lower, 'production order') || $this->bounded_phrase_match($lower, 'production orders') || $this->bounded_phrase_match($lower, 'productionorders') || $this->bounded_phrase_match($lower, 'productionorder'))){
					continue;
				}
				if($entity==='ApprovalStep' && in_array($phrase, ['approval', 'approvals'], true) && ($this->bounded_phrase_match($lower, 'approval workflow') || $this->bounded_phrase_match($lower, 'approval workflows') || $this->bounded_phrase_match($lower, 'approval gate') || $this->bounded_phrase_match($lower, 'approval gates') || $this->bounded_phrase_match($lower, 'change approval') || $this->bounded_phrase_match($lower, 'change approvals') || $this->bounded_phrase_match($lower, 'changeapproval') || $this->bounded_phrase_match($lower, 'changeapprovals') || $this->bounded_phrase_match($lower, 'access approval') || $this->bounded_phrase_match($lower, 'access approvals') || $this->bounded_phrase_match($lower, 'accessapproval') || $this->bounded_phrase_match($lower, 'accessapprovals') || $this->bounded_phrase_match($lower, 'model policy approval') || $this->bounded_phrase_match($lower, 'model policy approvals'))){
					continue;
				}
				if($entity==='ApprovalStep' && in_array($phrase, ['approval', 'approvals'], true) && ($this->bounded_phrase_match($lower, 'payment approvals') || $this->bounded_phrase_match($lower, 'payment approval')) && ($this->bounded_phrase_match($lower, 'claim payments') || $this->bounded_phrase_match($lower, 'claim payment') || $this->bounded_phrase_match($lower, 'claims'))){
					continue;
				}
				if($entity==='ApprovalStep' && in_array($phrase, ['approval', 'approvals'], true) && ($this->bounded_phrase_match($lower, 'disbursement approvals') || $this->bounded_phrase_match($lower, 'disbursement approval'))){
					continue;
				}
				$match=strpos($lower, $phrase);
				if($match===false || !$this->bounded_phrase_match($lower, $phrase)){
					continue;
				}
				$position=$position===null ? $match : min($position, $match);
			}
			if($position!==null){
				$found[]=['position'=>$position, 'entity'=>$entity];
			}
		}
		$known_entity_keys=[];
		foreach(array_keys($candidates) as $entity){
			$known_entity_keys[$this->app_builder_entity_key((string)$entity)]=(string)$entity;
		}
		if(preg_match_all('/(?<![A-Za-z0-9])([A-Z][A-Za-z0-9]*)(?![A-Za-z0-9])/', $task, $matches, PREG_OFFSET_CAPTURE)>0){
			foreach($matches[1] as $match){
				$raw=(string)($match[0] ?? '');
				$position=(int)($match[1] ?? 0);
				if(strlen($raw)<3){
					continue;
				}
				$entity=$known_entity_keys[$this->app_builder_entity_key($this->app_builder_normalize_entity_name($raw))] ?? null;
				if($entity===null){
					continue;
				}
				if($entity==='Tenant' && $this->app_builder_has_property_lease_context($lower)){
					continue;
				}
				$found[]=['position'=>$position, 'entity'=>$entity];
			}
		}
		usort($found, static fn(array $a, array $b): int => ($a['position'] <=> $b['position']) ?: strcmp((string)$a['entity'], (string)$b['entity']));
		return array_values(array_unique(array_map(static fn(array $item): string => (string)$item['entity'], $found)));
	}

	/**
	 * Applies a domain-preferred order while preserving non-domain entities after it.
	 *
	 * @param array<int,string> $entities Inferred entities.
	 * @param array<int,string> $preferred Preferred entity order for a domain.
	 * @return array<int,string> Reordered entities.
	 */
	private function app_builder_preferred_entity_order(array $entities, array $preferred): array {
		$remaining=[];
		foreach(array_values(array_unique(array_map('strval', $entities))) as $entity){
			$remaining[$this->app_builder_entity_key($entity)]=$entity;
		}
		$ordered=[];
		foreach($preferred as $entity){
			$key=$this->app_builder_entity_key($entity);
			if(!isset($remaining[$key])){
				continue;
			}
			$ordered[]=$remaining[$key];
			unset($remaining[$key]);
		}
		return array_values(array_merge($ordered, array_values($remaining)));
	}

	/**
	 * Normalizes explicit or inferred entity labels into singular PascalCase.
	 *
	 * @param string $entity Raw entity label.
	 * @return string Class-like singular entity label.
	 */
	private function app_builder_normalize_entity_name(string $entity): string {
		$tokens=$this->name_tokens($entity);
		if($tokens===[]){
			return 'DataphyreItem';
		}
		$last=count($tokens)-1;
		$tokens[$last]=$this->app_builder_singular_entity_token($tokens[$last]);
		return $this->app_builder_php_safe_entity_name($this->studly_name(implode(' ', $tokens)));
	}

	/**
	 * Singularizes the last token of an entity label without touching common
	 * non-count nouns that naturally end in "s".
	 *
	 * @param string $token Last entity-name token.
	 * @return string Singular-ish token.
	 */
	private function app_builder_singular_entity_token(string $token): string {
		$lower=strtolower($token);
		if(in_array($lower, ['access', 'analysis', 'business', 'case', 'news', 'status'], true)){
			return $token;
		}
		if($lower==='cas'){
			return 'case';
		}
		if(str_ends_with($lower, 'ies') && strlen($token)>3){
			return substr($token, 0, -3).'y';
		}
		if($lower==='leases'){
			return substr($token, 0, -1);
		}
		if(str_ends_with($lower, 'sses') && strlen($token)>4){
			return substr($token, 0, -2);
		}
		if(str_ends_with($lower, 'cases') && strlen($token)>=5){
			return substr($token, 0, -1);
		}
		if((str_ends_with($lower, 'enses') || str_ends_with($lower, 'onses') || str_ends_with($lower, 'rses') || str_ends_with($lower, 'nces')) && strlen($token)>5){
			return substr($token, 0, -1);
		}
		if((str_ends_with($lower, 'ches') || str_ends_with($lower, 'shes') || str_ends_with($lower, 'xes') || str_ends_with($lower, 'zes')) && strlen($token)>4){
			return substr($token, 0, -2);
		}
		if(str_ends_with($lower, 'ses') && strlen($token)>3){
			return substr($token, 0, -2);
		}
		if(str_ends_with($lower, 's') && !str_ends_with($lower, 'ss') && strlen($token)>1){
			return substr($token, 0, -1);
		}
		return $token;
	}

	/**
	 * Checks whether a generic entity is already represented by a more specific
	 * compound entity such as PurchaseOrder.
	 *
	 * @param array<int,string> $entities Current inferred entities.
	 * @param string $entity Generic candidate entity.
	 * @return bool True when the candidate should be skipped.
	 */
	private function app_builder_has_entity_or_specialization(array $entities, string $entity): bool {
		$key=$this->app_builder_entity_key($entity);
		foreach($entities as $existing){
			$existing_key=$this->app_builder_entity_key((string)$existing);
			if($existing_key===$key || (strlen($existing_key)>strlen($key) && str_ends_with($existing_key, $key))){
				return true;
			}
		}
		return false;
	}

}
