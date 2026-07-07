<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP app-builder skeleton, verification, and app-contract helpers.
 */
trait dataphyre_mcp_planning_app_builder_contract_surfaces {

	/**
	 * Summarizes skeleton previews without inlining their content.
	 *
	 * @param array<int,array<string,string>> $skeletons Code skeleton previews.
	 * @return array<string,mixed> Compact skeleton grouping and write-order metadata.
	 */
	private function app_builder_code_skeleton_summary(array $skeletons): array {
		$known_order=['table_schema', 'table_repository', 'table_record', 'sql_code_unit_test', 'panel_resource', 'panel_manifest', 'panel_regression_manifest', 'panel_code_unit_test', 'api_route', 'api_endpoint_handler', 'api_regression_manifest', 'api_code_unit_test', 'app_code_unit_test'];
		$paths_by_kind=[];
		$sensitive_paths=[];
		$sensitive_categories=[];
		$sensitive_category_policies=[];
		$sensitive_policy_metadata=[];
		$sensitive_path_reasons=[];
		$field_metadata_paths=[];
		foreach($skeletons as $skeleton){
			if(!is_array($skeleton)){
				continue;
			}
			$kind=(string)($skeleton['kind'] ?? 'unknown');
			$path=(string)($skeleton['path'] ?? '');
			if($kind==='' || $path===''){
				continue;
			}
			$paths_by_kind[$kind][]=$path;
			if(($skeleton['sensitive_field_policy']['has_sensitive_fields'] ?? false)===true){
				$sensitive_paths[]=$path;
				$policy=is_array($skeleton['sensitive_field_policy'] ?? null) ? $skeleton['sensitive_field_policy'] : [];
				$categories=array_values(array_unique(array_map('strval', is_array($policy['categories'] ?? null) ? $policy['categories'] : [])));
				foreach($categories as $category){
					if($category!==''){
						$sensitive_categories[$category]=true;
						if(isset($policy['category_policies'][$category]) && is_array($policy['category_policies'][$category])){
							$sensitive_category_policies[$category]=$policy['category_policies'][$category];
						}
					}
				}
				$fields=[];
				foreach(is_array($policy['signals'] ?? null) ? $policy['signals'] : [] as $signal){
					if(is_array($signal) && isset($signal['field'])){
						$fields[]=(string)$signal['field'];
					}
				}
				$sensitive_path_reasons[]=[
					'path'=>$path,
					'categories'=>$categories,
					'fields'=>array_values(array_unique($fields)),
					'actions'=>is_array($policy['recommended_actions'] ?? null) ? $policy['recommended_actions'] : [],
					'category_policies'=>is_array($policy['category_policies'] ?? null) ? $policy['category_policies'] : [],
					'policy_metadata'=>is_array($policy['policy_metadata'] ?? null) ? $policy['policy_metadata'] : [],
				];
				if($sensitive_policy_metadata===[] && is_array($policy['policy_metadata'] ?? null)){
					$sensitive_policy_metadata=$policy['policy_metadata'];
				}
			}
			$adaptation_notes=is_array($skeleton['adaptation_notes'] ?? null) ? $skeleton['adaptation_notes'] : [];
			foreach($adaptation_notes as $note){
				if(is_string($note) && str_contains($note, 'options/default metadata')){
					$field_metadata_paths[]=$path;
					break;
				}
			}
		}
		$kinds=array_values(array_unique(array_merge(
			array_values(array_intersect($known_order, array_keys($paths_by_kind))),
			array_values(array_diff(array_keys($paths_by_kind), $known_order))
		)));
		return [
			'total'=>array_sum(array_map('count', $paths_by_kind)),
			'kinds'=>$kinds,
			'write_order'=>array_values(array_filter($known_order, static fn(string $kind): bool => isset($paths_by_kind[$kind]))),
			'paths_by_kind'=>$paths_by_kind,
			'sensitive_field_policy'=>[
				'has_sensitive_skeletons'=>$sensitive_paths!==[],
				'paths'=>array_values(array_unique($sensitive_paths)),
				'categories'=>array_values(array_keys($sensitive_categories)),
				'category_policies'=>$sensitive_category_policies,
				'policy_metadata'=>$sensitive_policy_metadata,
				'path_reasons'=>$sensitive_path_reasons,
				'policy'=>$sensitive_paths===[] ? 'No sensitive field names were inferred in skeleton previews.' : 'Open full code_skeletons and apply sensitive_field_policy before writing app-owned Panel resources or regression manifests.',
			],
			'field_metadata_policy'=>[
				'has_field_metadata_skeletons'=>$field_metadata_paths!==[],
				'paths'=>array_values(array_unique($field_metadata_paths)),
				'policy'=>$field_metadata_paths===[] ? 'No options/default metadata was inferred in skeleton previews.' : 'Open full code_skeletons and preserve options/default metadata in app-owned Panel select controls, validation, filters, and tests before writing.',
			],
			'policy'=>'Preview skeletons are read-only; use this summary to group app-owned writes, then open full code_skeletons for adaptation_notes before writing files.',
		];
	}

	/**
	 * Builds bounded code skeleton previews for an app-builder scaffold plan.
	 *
	 * @param array<string,mixed> $plan Dry-run scaffold plan.
	 * @return array<int,array<string,string>> Code skeleton previews.
	 */
	private function app_builder_code_skeletons(array $plan): array {
		$type=(string)($plan['type'] ?? '');
		if($type==='api_endpoint'){
			return $this->app_builder_api_endpoint_code_skeletons($plan);
		}
		if($type!=='panel_resource'){
			return [];
		}
		$name=(string)($plan['name'] ?? 'Resource');
		$class=$this->studly_name($name);
		$slug=str_replace('-', '_', $this->slug_name($name));
		$label=$this->title_label($name);
		$files=array_values(array_map('strval', is_array($plan['proposed_files'] ?? null) ? $plan['proposed_files'] : []));
		$path_context=is_array($plan['app_path_context'] ?? null) ? $plan['app_path_context'] : $this->app_builder_path_context([]);
		$panel_namespace=(string)($path_context['panel_resource_namespace'] ?? 'App\\Panel\\Resources');
		$field_hints=is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : [];
		$sensitive_policy=$this->app_builder_sensitive_field_policy($field_hints);
		$resource_notes=[
			($path_context['placeholder_mode'] ?? true)===true ? 'Replace App\\Panel\\Resources with the consuming application Panel resource namespace before writing.' : 'Verify '.$panel_namespace.' matches the consuming application Panel resource namespace before writing.',
			'Replace queryUsing(static fn(): array => []) with an app-owned repository/query adapter before writing the resource.',
			'Apply app-owned permission, tenant/workspace, ownership, and audit policy through local config, callbacks, dialbacks, plugins, or adapters.',
			'Keep the generated class in the consuming application; do not edit Dataphyre runtime internals for one resource.',
		];
		if($this->app_builder_has_choice_field_hints($field_hints)){
			$resource_notes[]='Apply schema field options/default metadata through app-owned Panel select controls, validation, and tests before writing.';
		}
		if(($sensitive_policy['has_sensitive_fields'] ?? false)===true){
			$resource_notes[]='Apply sensitive_field_policy before writing: remove, mask, make write-only, or permission-gate sensitive fields in Panel columns/forms.';
		}
		$regression_notes=[
			'Replace placeholder app paths before running the route-free Panel regression.',
			'Add app-specific filter, action, relation, permission, and empty-state checks when those behaviors are implemented.',
		];
		if(($sensitive_policy['has_sensitive_fields'] ?? false)===true){
			$regression_notes[]='Add focused checks proving sensitive fields are not exposed to unauthorized users or searchable table output.';
		}
		return array_values(array_filter([
			isset($files[0]) ? [
				'path'=>$files[0],
				'kind'=>'panel_resource',
				'language'=>'php',
				'purpose'=>'Panel resource definition',
				'adaptation_notes'=>$resource_notes,
				'sensitive_field_policy'=>$sensitive_policy,
				'content'=>$this->panel_resource_code_skeleton($class, $slug, $label, $field_hints, $panel_namespace),
			] : null,
			isset($files[1]) ? [
				'path'=>$files[1],
				'kind'=>'panel_manifest',
				'language'=>'php',
				'purpose'=>'Panel resource manifest registration',
				'adaptation_notes'=>[
					($path_context['placeholder_mode'] ?? true)===true ? 'Replace App\\Panel\\Resources with the consuming application Panel resource namespace before writing.' : 'Verify '.$panel_namespace.' matches the consuming application Panel resource namespace before writing.',
					'Register the resource through the consuming application manifest/provider conventions.',
					'Keep install-local registration app-owned unless the task explicitly requests reusable Dataphyre framework behavior.',
				],
				'content'=>$this->panel_manifest_code_skeleton($class, $panel_namespace),
			] : null,
			isset($files[2]) ? [
				'path'=>$files[2],
				'kind'=>'panel_regression_manifest',
				'language'=>'json',
				'purpose'=>'Focused Panel regression manifest',
				'adaptation_notes'=>$regression_notes,
				'sensitive_field_policy'=>$sensitive_policy,
				'content'=>$this->panel_regression_json_skeleton($slug),
			] : null,
		]));
	}

	/**
	 * Builds starter API endpoint skeleton previews for full app-builder plans.
	 *
	 * @param array<string,mixed> $plan Dry-run API endpoint plan.
	 * @return array<int,array<string,mixed>> Code skeleton previews.
	 */
	private function app_builder_api_endpoint_code_skeletons(array $plan): array {
		$name=(string)($plan['name'] ?? 'Endpoint');
		$class=$this->studly_name($name);
		$slug=$this->slug_name($name);
		$files=array_values(array_map('strval', is_array($plan['proposed_files'] ?? null) ? $plan['proposed_files'] : []));
		$endpoint=is_array($plan['endpoint'] ?? null) ? $plan['endpoint'] : [];
		$path=(string)($endpoint['path'] ?? '/api/'.$slug);
		$methods=array_values(array_map('strval', is_array($endpoint['methods'] ?? null) ? $endpoint['methods'] : ['GET']));
		if($methods===[]){
			$methods=['GET'];
		}
		$operation_id=(string)($endpoint['operation_id'] ?? str_replace('-', '.', $slug));
		$auth=(string)($endpoint['auth_hint'] ?? 'none');
		$endpoint_policy_metadata=is_array($plan['endpoint_policy_metadata'] ?? null) ? $plan['endpoint_policy_metadata'] : $this->api_endpoint_policy_metadata($methods, $auth);
		return array_values(array_filter([
			isset($files[0]) ? [
				'path'=>$files[0],
				'kind'=>'api_route',
				'language'=>'php',
				'purpose'=>'App-owned API route declaration',
				'endpoint_policy_metadata'=>$endpoint_policy_metadata,
				'adaptation_notes'=>[
					'Adapt Api::get/post/put/patch/delete calls to the consuming application endpoint declaration style before writing.',
					'Apply app-owned auth, tenant/workspace, trace, cache, and validation policy through local API configuration or endpoint metadata.',
					'Keep the declaration app-owned; do not edit Dataphyre API internals for one endpoint.',
				],
				'content'=>$this->api_route_code_skeleton($class, $path, $methods, $operation_id, $auth),
			] : null,
			isset($files[1]) ? [
				'path'=>$files[1],
				'kind'=>'api_endpoint_handler',
				'language'=>'php',
				'purpose'=>'App-owned endpoint handler',
				'endpoint_policy_metadata'=>$endpoint_policy_metadata,
				'adaptation_notes'=>[
					'Replace placeholder request/response handling with local service or repository calls.',
					'Keep reusable business behavior in app-owned Framework/service classes unless the task explicitly asks for reusable Dataphyre framework behavior.',
					'Return copy-safe error payloads and avoid exposing tokens, cookies, auth headers, signed URLs, or tenant-private identifiers.',
				],
				'content'=>$this->api_handler_code_skeleton($class),
			] : null,
			isset($files[2]) ? [
				'path'=>$files[2],
				'kind'=>'api_regression_manifest',
				'language'=>'json',
				'purpose'=>'Focused API scaffold verification manifest',
				'endpoint_policy_metadata'=>$endpoint_policy_metadata,
				'adaptation_notes'=>[
					'Replace placeholders with app-owned route names, concrete paths, and expected response contracts before running focused verification.',
					'Use MCP route/API static and manifest-preview tools; do not dispatch handlers from MCP.',
				],
				'content'=>$this->api_regression_json_skeleton($slug, $path, $methods, $operation_id),
			] : null,
		]));
	}

	/**
	 * Builds a compact API route declaration skeleton.
	 *
	 * @param string $class Endpoint class stem.
	 * @param string $path Endpoint path.
	 * @param array<int,string> $methods HTTP methods.
	 * @param string $operation_id OpenAPI operation id.
	 * @param string $auth Auth hint.
	 * @return string PHP skeleton content.
	 */
	private function api_route_code_skeleton(string $class, string $path, array $methods, string $operation_id, string $auth): string {
		$method=strtolower((string)($methods[0] ?? 'GET'));
		if(!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)){
			$method='get';
		}
		$auth_line=$auth!=='' && $auth!=='none'
			? "\n    ->auth(".var_export($auth, true).")"
			: '';
		return "<?php\n".
			"declare(strict_types=1);\n\n".
			"use Dataphyre\\Api\\Api;\n\n".
			"Api::".$method."(".var_export($path, true).")\n".
			"    ->summary(".var_export($this->title_label($class), true).")\n".
			"    ->operationId(".var_export($operation_id, true).")".$auth_line."\n".
			"    ->jsonResponse(200, ['status'=>'ok'])\n".
			"    ->execute([".$class."Endpoints::class, 'handle']);\n";
	}

	/**
	 * Builds a compact API endpoint handler skeleton.
	 *
	 * @param string $class Endpoint class stem.
	 * @return string PHP skeleton content.
	 */
	private function api_handler_code_skeleton(string $class): string {
		return "<?php\n".
			"declare(strict_types=1);\n\n".
			"final class ".$class."Endpoints {\n".
			"    public static function handle(array \$request=[]): array {\n".
			"        return [\n".
			"            'status'=>'ok',\n".
			"            'data'=>[],\n".
			"        ];\n".
			"    }\n".
			"}\n";
	}

	/**
	 * Builds a focused API regression manifest skeleton.
	 *
	 * @param string $slug Endpoint slug.
	 * @param string $path Endpoint path.
	 * @param array<int,string> $methods HTTP methods.
	 * @param string $operation_id OpenAPI operation id.
	 * @return string JSON skeleton content.
	 */
	private function api_regression_json_skeleton(string $slug, string $path, array $methods, string $operation_id): string {
		$manifest=[
			'name'=>'api.'.$slug,
			'route_preview'=>[
				'operation_id'=>$operation_id,
				'method'=>strtoupper((string)($methods[0] ?? 'GET')),
				'path'=>$path,
			],
			'checks'=>[
				['tool'=>'dataphyre_api_docs_static_summary', 'expect'=>'endpoint declaration is discoverable statically'],
				['tool'=>'dataphyre_route_manifest_read', 'expect'=>'compiled manifest contains the named API route'],
				['tool'=>'dataphyre_route_url_preview', 'expect'=>'named route URL preview resolves without dispatch'],
			],
		];
		return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
	}

	/**
	 * Reports whether planned fields include choice/default metadata.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return bool True when app-owned Panel adaptation should preserve choices/defaults.
	 */
	private function app_builder_has_choice_field_hints(array $field_hints): bool {
		foreach($field_hints as $hint){
			if(!is_array($hint)){
				continue;
			}
			if((is_array($hint['options'] ?? null) && $hint['options']!==[]) || array_key_exists('default', $hint)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds compact Panel field metadata for option/default-bearing fields.
	 *
	 * @param array<int,array<string,mixed>> $fields Builder schema field rows.
	 * @return array<int,array<string,mixed>> Panel-facing metadata sidecar.
	 */
	private function app_builder_panel_field_metadata(array $fields): array {
		$metadata=[];
		foreach($fields as $field){
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
				'field'=>$name,
				'field_type'=>(string)($field['type'] ?? 'string'),
				'form_control'=>$this->app_builder_panel_type_for_field($field, 'field'),
			];
			if($options!==[]){
				$row['options']=$options;
			}
			if($has_default){
				$row['default']=$this->app_builder_scalar_default($field['default']);
			}
			$metadata[]=$row;
		}
		return $metadata;
	}

	/**
	 * Builds compact Panel filter metadata for option/default-bearing filters.
	 *
	 * @param array<int,array<string,mixed>> $field_hints Field hint entries.
	 * @return array<int,array<string,mixed>> Filter metadata sidecar.
	 */
	private function app_builder_panel_filter_metadata(array $field_hints): array {
		$metadata=[];
		foreach($this->app_builder_filter_entries($field_hints) as $filter){
			$options=$this->app_builder_field_options($filter);
			$has_default=array_key_exists('default', $filter);
			if($options===[] && !$has_default){
				continue;
			}
			$row=[
				'field'=>(string)($filter['name'] ?? ''),
				'filter_control'=>(string)($filter['type'] ?? 'text'),
			];
			if($options!==[]){
				$row['options']=$options;
			}
			if($has_default){
				$row['default']=$this->app_builder_scalar_default($filter['default']);
			}
			$metadata[]=$row;
		}
		return $metadata;
	}

	/**
	 * Builds a concise write order for ordinary app-owned implementation.
	 *
	 * @param array<int,string> $files Proposed app-owned files.
	 * @param array<int,array<string,mixed>> $schemas Inferred entity schemas.
	 * @return array<int,array<string,mixed>> Ordered implementation guidance.
	 */
	private function app_builder_implementation_sequence(array $files, array $schemas): array {
		$files=array_values(array_map('strval', $files));
		$resource_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/panel/resources/')));
		$manifest_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/panel/manifests/')));
		$panel_regression_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/unit_tests/panel.') && str_ends_with($file, '.json')));
		$code_unit_test_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/unit_tests/') && str_ends_with($file, '.test.php')));
		$api_route_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/routes/api/')));
		$api_handler_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/api/') && str_ends_with($file, 'Endpoints.php')));
		$api_test_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/unit_tests/api.') && str_ends_with($file, '.json')));
		$tables=array_values(array_filter(array_map(static fn(array $schema): string => (string)($schema['table'] ?? ''), $schemas), static fn(string $table): bool => $table!==''));
		$entity_build_order=$this->app_builder_schema_dependency_order($schemas);
		$focused_verification_tools=['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check'];
		if($code_unit_test_files!==[]){
			$focused_verification_tools[]='app_local_php_unit_tests';
		}
		$sequence=[
			[
				'order'=>1,
				'id'=>'inspect_local_conventions',
				'goal'=>'Inspect existing app Panel resources, API routes/endpoints, manifest registration, schema/repository naming, and regression manifests before writing files.',
				'outputs'=>[
					'builder_response.local_convention_probe.items',
					'builder_response.write_plan_summary.write_order[0].probe',
					'builder_response.write_handoff.first_batch.probe',
				],
			],
			[
				'order'=>2,
				'id'=>'define_app_data_contract',
				'goal'=>'Create or update app-owned TableSchema, Repository, and Record artifacts for the inferred tables; keep tenant, permission, and audit fields application-owned.',
				'tables'=>$tables,
				'entity_build_order'=>$entity_build_order,
			],
			[
				'order'=>3,
				'id'=>'create_panel_resources',
				'goal'=>'Create Panel resource classes with queryUsing adapters, columns, fields, filters, actions, and relation-aware fields.',
				'files'=>$resource_files,
			],
			[
				'order'=>4,
				'id'=>'register_manifests',
				'goal'=>'Register the resources through app-owned Panel manifests or providers without editing Dataphyre runtime internals.',
				'files'=>$manifest_files,
			],
			[
				'order'=>5,
				'id'=>'add_route_free_regression',
				'goal'=>'Add focused route-free Panel regression manifests for table rendering, form schema, filters, and expected actions.',
				'files'=>$panel_regression_files,
			],
			[
				'order'=>6,
				'id'=>'run_focused_verification',
				'goal'=>'Run focused application or module checks for the files above; keep publication validation for MCP/release-surface claims.',
				'tools'=>$focused_verification_tools,
			],
		];
		if($api_route_files!==[] || $api_handler_files!==[] || $api_test_files!==[]){
			array_splice($sequence, -1, 0, [
				[
					'order'=>6,
					'id'=>'declare_api_routes',
					'goal'=>'Create app-owned API route declarations with summary, operationId, auth, response, and execute target metadata.',
					'files'=>$api_route_files,
				],
				[
					'order'=>7,
					'id'=>'implement_api_handlers',
					'goal'=>'Create endpoint handler classes that call app-owned services/repositories and keep secrets, tokens, signed URLs, and tenant-private identifiers out of responses.',
					'files'=>$api_handler_files,
				],
				[
					'order'=>8,
					'id'=>'add_api_regression_manifest',
					'goal'=>'Add focused API scaffold verification manifests for static API docs, route manifest reads, URL previews, and no-dispatch route checks.',
					'files'=>$api_test_files,
				],
			]);
		}
		if($code_unit_test_files!==[]){
			array_splice($sequence, -1, 0, [[
				'order'=>6,
				'id'=>'add_code_unit_tests',
				'goal'=>'Add lightweight app-owned PHP test skeletons for generated resources, endpoints, or data-model contracts.',
				'files'=>$code_unit_test_files,
			]]);
		}
		foreach($sequence as $index=>$step){
			$sequence[$index]['order']=$index+1;
		}
		return $sequence;
	}

	/**
	 * Builds a compact local app convention probe before app-owned writes.
	 *
	 * @param array<string,mixed> $code_skeleton_summary Compact skeleton grouping.
	 * @param array<string,mixed> $app_path_context App path placeholders or concrete context.
	 * @return array<string,mixed> Local convention probe.
	 */
	private function app_builder_local_convention_probe(array $code_skeleton_summary, array $app_path_context): array {
		$paths_by_kind=is_array($code_skeleton_summary['paths_by_kind'] ?? null) ? $code_skeleton_summary['paths_by_kind'] : [];
		$app_root=(string)($app_path_context['dataphyre_root'] ?? ($app_path_context['application_path'] ?? '<app>'));
		if($app_root===''){
			$app_root='<app>';
		}
		$items=[];
		$add_probe=function(string $id, array $kinds, array $globs, array $signals, array $feeds, array $apply_to) use (&$items, $paths_by_kind): void {
			$active=false;
			foreach($kinds as $kind){
				if(is_array($paths_by_kind[$kind] ?? null) && $paths_by_kind[$kind]!==[]){
					$active=true;
					break;
				}
			}
			if(!$active){
				return;
			}
			$items[]=[
				'id'=>$id,
				'applies_to_kinds'=>$kinds,
				'inspect_globs'=>$globs,
				'signals'=>$signals,
				'feeds'=>$feeds,
				'capture_fields'=>[
					'matched_files',
					'observed_patterns',
					'local_namespaces_or_registration_points',
					'style_decisions_to_apply',
				],
				'apply_to'=>$apply_to,
			];
		};
		$add_probe(
			'panel_resource_style',
			['panel_resource', 'panel_manifest', 'panel_regression_manifest', 'panel_code_unit_test'],
			[$app_root.'/panel/resources/*.php', $app_root.'/panel/manifests/*.php', $app_root.'/unit_tests/panel.*.json', $app_root.'/unit_tests/panel.*.test.php'],
			['namespace', 'queryUsing pattern', 'field/filter/action naming', 'relation option sources', 'manifest registration', 'route-free regression shape', 'code-defined PHP test shape'],
			['implementation_recipe.items[kind=panel_resource]', 'implementation_recipe.items[kind=panel_manifest]', 'verification_execution_plan.items[tool=dataphyre_run_panel_regression]', 'verification_execution_plan.items[tool=app_local_php_unit_tests]'],
			['resource namespace/imports', 'field/filter/action array shape', 'relationship option callbacks/adapters', 'panel regression manifest naming', 'PHP test skeleton naming and assertions']
		);
		$add_probe(
			'data_model_style',
			['table_schema', 'table_repository', 'table_record', 'sql_code_unit_test'],
			[$app_root.'/**/*Schema.php', $app_root.'/**/*Repository.php', $app_root.'/**/*Record.php', $app_root.'/**/dataphyre/sql/*.php', $app_root.'/unit_tests/sql.*.test.php'],
			['schema registration', 'repository query shape', 'record/cast conventions', 'table naming', 'tenant/owner scoping pattern', 'code-defined SQL/data tests'],
			['implementation_recipe.items[kind=table_schema]', 'implementation_recipe.items[kind=table_repository]', 'verification_execution_plan.items[tool=dataphyre_sql_schema_read]', 'verification_execution_plan.items[tool=app_local_php_unit_tests]'],
			['TableSchema registration shape', 'repository method/query conventions', 'Record casts/accessors', 'tenant or owner scope helper naming', 'PHP test skeleton naming and assertions']
		);
		$add_probe(
			'api_endpoint_style',
			['api_route', 'api_endpoint_handler', 'api_regression_manifest', 'api_code_unit_test'],
			[$app_root.'/routes/api/*.php', $app_root.'/api/**/*.php', $app_root.'/unit_tests/api.*.json', $app_root.'/unit_tests/api.*.test.php'],
			['route declaration style', 'execute target shape', 'auth metadata', 'operationId naming', 'response schema conventions', 'API regression manifest shape', 'code-defined PHP endpoint tests'],
			['implementation_recipe.items[kind=api_route]', 'implementation_recipe.items[kind=api_endpoint_handler]', 'verification_execution_plan.items[tool=dataphyre_route_match_preview]', 'verification_execution_plan.items[tool=app_local_php_unit_tests]'],
			['route registration shape', 'handler class/function style', 'auth and policy metadata placement', 'API regression manifest naming', 'PHP test skeleton naming and assertions']
		);
		if($items===[]){
			$items[]=[
				'id'=>'app_owned_style',
				'applies_to_kinds'=>[],
				'inspect_globs'=>[$app_root.'/**/*.php', $app_root.'/unit_tests/*.json', $app_root.'/unit_tests/*.test.php'],
				'signals'=>['namespace', 'registration pattern', 'test manifest shape', 'code-defined PHP test shape'],
				'feeds'=>['implementation_recipe.items', 'verification_execution_plan.items'],
				'capture_fields'=>[
					'matched_files',
					'observed_patterns',
					'local_namespaces_or_registration_points',
					'style_decisions_to_apply',
				],
				'apply_to'=>['app-owned file namespaces', 'registration points', 'focused test manifest shape'],
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>'inspect_before_app_owned_writes',
			'purpose'=>'Find local app conventions before adapting generated app-owned skeletons.',
			'items'=>$items,
			'copy_forward'=>'builder_response.local_convention_probe',
			'not_required'=>[
				'framework internals',
				'release readiness',
				'dataphyre_mcp_verify_all',
				'Dataphyre hot-path benchmarks',
			],
		];
	}

	/**
	 * Builds read-only data-model guidance for an app-builder scaffold plan.
	 *
	 * @param array<string,mixed> $plan Dry-run scaffold plan.
	 * @param array<int,array<string,mixed>> $schema_context Full planned-schema context for relationship scoping.
	 * @return array<int,array<string,mixed>> Data-model artifact previews.
	 */
	private function app_builder_data_model(array $plan, array $schema_context=[]): array {
		$type=(string)($plan['type'] ?? '');
		if($type!=='panel_resource' && $type!=='sql_table'){
			return [];
		}
		$name=(string)($plan['name'] ?? 'Resource');
		$class=$this->studly_name($name);
		$table=str_replace('-', '_', $this->slug_name($name));
		$path_context=is_array($plan['app_path_context'] ?? null) ? $plan['app_path_context'] : $this->app_builder_path_context([]);
		$framework_path=(string)($path_context['framework_path'] ?? '<app framework>');
		$framework_namespace=(string)($path_context['framework_namespace'] ?? 'App\\Framework');
		$placeholder_mode=($path_context['placeholder_mode'] ?? true)===true;
		$fields=$this->app_builder_schema_fields(is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : []);
		$relationships=$this->app_builder_relationships(is_array($plan['field_hints'] ?? null) ? $plan['field_hints'] : []);
		$current_schema=[
			'entity'=>$this->title_label($name),
			'table'=>$table,
			'fields'=>$fields,
			'relationships'=>$relationships,
		];
		$relationship_integrity_metadata=$this->app_builder_relationship_integrity_metadata([$current_schema], [], $schema_context);
		$scope_identifier_metadata=$this->app_builder_scope_identifier_metadata([$current_schema]);
		$data_integrity_metadata=$this->app_builder_data_integrity_summary([$current_schema], $schema_context);
		$lifecycle_policy_metadata=$this->app_builder_lifecycle_policy_summary([$current_schema]);
		$audit_retention_metadata=$this->app_builder_audit_retention_summary([$current_schema]);
		$access_control_metadata=$this->app_builder_access_control_summary([$current_schema], $schema_context);
		$operational_reliability_metadata=$this->app_builder_operational_reliability_summary([$current_schema], '');
		$support_observability_metadata=$this->app_builder_support_observability_summary([$current_schema], '');
		$change_management_metadata=$this->app_builder_change_management_summary([$current_schema], '');
		$integration_boundary_metadata=$this->app_builder_integration_boundary_summary([$current_schema], '');
		$business_policy_metadata=$this->app_builder_business_policy_summary([$current_schema], '');
		$process_policy_metadata=$this->app_builder_process_policy_summary([$current_schema], '');
		$reporting_analytics_metadata=$this->app_builder_reporting_analytics_summary([$current_schema], '');
		$notification_communication_metadata=$this->app_builder_notification_communication_summary([$current_schema], '');
		$columns=['id'];
		$casts=[];
		foreach($fields as $field){
			$column=(string)($field['name'] ?? '');
			if($column==='' || in_array($column, $columns, true)){
				continue;
			}
			$columns[]=$column;
			$cast=$this->app_builder_sql_cast((string)($field['type'] ?? 'string'));
			if($cast!==null){
				$casts[$column]=$cast;
			}
		}
		$schema_field_metadata=$this->app_builder_data_model_schema_field_metadata($fields);
		$schema_source=$this->table_schema_code_skeleton($class, $table, $columns, $casts, $framework_namespace);
		$repository_source=$this->table_repository_code_skeleton($class, $framework_namespace);
		$record_source=$this->table_record_code_skeleton($class, $columns, $framework_namespace);
		$column_security_metadata=$this->app_builder_column_security_metadata($columns);
		$namespace_note=$placeholder_mode
			? 'Replace App\\Framework with the consuming application namespace before writing files.'
			: 'Verify '.$framework_namespace.' matches the consuming application Framework namespace before writing files.';
		return [[
			'entity'=>$this->title_label($name),
			'table'=>$table,
			'primary_key'=>'id',
			'columns'=>$columns,
			'casts'=>$casts,
			'schema_field_metadata'=>$schema_field_metadata,
			'column_security_metadata'=>$column_security_metadata,
			'relationships'=>$relationships,
			'relationship_integrity_metadata'=>$relationship_integrity_metadata,
			'scope_identifier_metadata'=>$scope_identifier_metadata,
			'data_integrity_metadata'=>$data_integrity_metadata,
			'lifecycle_policy_metadata'=>$lifecycle_policy_metadata,
			'audit_retention_metadata'=>$audit_retention_metadata,
			'access_control_metadata'=>$access_control_metadata,
			'operational_reliability_metadata'=>$operational_reliability_metadata,
			'support_observability_metadata'=>$support_observability_metadata,
			'change_management_metadata'=>$change_management_metadata,
			'integration_boundary_metadata'=>$integration_boundary_metadata,
			'business_policy_metadata'=>$business_policy_metadata,
			'process_policy_metadata'=>$process_policy_metadata,
			'reporting_analytics_metadata'=>$reporting_analytics_metadata,
			'notification_communication_metadata'=>$notification_communication_metadata,
			'artifact_paths'=>[
				$framework_path.'/Schema/'.$class.'TableSchema.php',
				$framework_path.'/Repository/'.$class.'Repository.php',
				$framework_path.'/Record/'.$class.'Record.php',
			],
			'sql_config_path'=>(string)($path_context['dataphyre_root'] ?? 'applications/<app>/backend/dataphyre').'/config/sql.php',
			'scaffold_tool'=>[
				'script'=>'common/dataphyre/runtime/modules/sql/kernel/scaffold_table_artifacts.php',
				'arguments'=>[
					'--application='.(string)($path_context['application_id'] ?? '<app>'),
					'--entity='.$class,
					'--table='.$table,
					'--primary-key=id',
					'--columns='.implode(',', $columns),
				],
				'write_policy'=>'caller_owned_explicit_generation_only',
			],
			'namespace_note'=>$namespace_note,
			'code_skeletons'=>[
				[
					'path'=>$framework_path.'/Schema/'.$class.'TableSchema.php',
					'language'=>'php',
					'purpose'=>'TableSchema metadata for repository validation and casts',
					'schema_field_metadata'=>$schema_field_metadata,
					'column_security_metadata'=>$column_security_metadata,
					'relationship_integrity_metadata'=>$relationship_integrity_metadata,
					'scope_identifier_metadata'=>$scope_identifier_metadata,
					'data_integrity_metadata'=>$data_integrity_metadata,
					'lifecycle_policy_metadata'=>$lifecycle_policy_metadata,
					'audit_retention_metadata'=>$audit_retention_metadata,
					'access_control_metadata'=>$access_control_metadata,
					'operational_reliability_metadata'=>$operational_reliability_metadata,
					'support_observability_metadata'=>$support_observability_metadata,
					'change_management_metadata'=>$change_management_metadata,
					'integration_boundary_metadata'=>$integration_boundary_metadata,
					'business_policy_metadata'=>$business_policy_metadata,
					'process_policy_metadata'=>$process_policy_metadata,
					'reporting_analytics_metadata'=>$reporting_analytics_metadata,
					'notification_communication_metadata'=>$notification_communication_metadata,
					'adaptation_notes'=>[
						$namespace_note,
						'Register the schema/table through app-owned SQL config or local table-definition conventions.',
						'Apply schema_field_metadata for app-owned required fields, typed defaults, option sets, casts, and explicit relationship hints before writing TableSchema definitions.',
						'Apply data_integrity_metadata for app-owned indexes, unique constraints, tenant/workspace columns, and audit columns when the consuming app contract requires them.',
						'Apply lifecycle_policy_metadata for app-owned status defaults, terminal states, transitions, filters, and action checks when lifecycle fields are present.',
						'Apply audit_retention_metadata for app-owned actor, approval, retention hold, export, residency, and classification policy when corporate record fields are present.',
						'Apply access_control_metadata for app-owned tenant/workspace scope, ownership, role/permission, visibility, and cross-scope negative checks when access fields are present.',
						'Apply operational_reliability_metadata for app-owned idempotency, retry, request-hash, import/export, webhook, and outbox/job behavior when side-effect fields are present.',
						'Apply support_observability_metadata for app-owned incident, support, alert, health, diagnostic, severity, and copy-safe evidence policy when operability fields are present.',
						'Apply change_management_metadata for app-owned feature flags, rollout waves, migrations/backfills, rollback evidence, versioning, and compatibility checks when change fields are present.',
						'Apply integration_boundary_metadata for app-owned external providers, webhooks, sync state, idempotency, retry/dead-letter handling, token references, and reconciliation checks when integration fields are present.',
						'Apply business_policy_metadata for app-owned entitlements, quotas, eligibility, approvals/delegation, policy exceptions, waivers, and contractual terms when business-rule fields are present.',
						'Apply process_policy_metadata for app-owned assignments, queues, handoffs, SLA/deadline clocks, escalations, dependencies, and completion evidence when workflow fields are present.',
						'Apply reporting_analytics_metadata for app-owned metrics, dimensions, snapshots, freshness, drilldowns, dashboard visibility, and export controls when reporting fields are present.',
						'Apply notification_communication_metadata for app-owned templates, channels, recipients, preferences, suppression windows, delivery receipts, and escalation communications when messaging fields are present.',
					],
					'content'=>$schema_source,
				],
				[
					'path'=>$framework_path.'/Repository/'.$class.'Repository.php',
					'language'=>'php',
					'purpose'=>'Typed TableRepository boundary for the application table',
					'schema_field_metadata'=>$schema_field_metadata,
					'column_security_metadata'=>$column_security_metadata,
					'relationship_integrity_metadata'=>$relationship_integrity_metadata,
					'scope_identifier_metadata'=>$scope_identifier_metadata,
					'data_integrity_metadata'=>$data_integrity_metadata,
					'lifecycle_policy_metadata'=>$lifecycle_policy_metadata,
					'audit_retention_metadata'=>$audit_retention_metadata,
					'access_control_metadata'=>$access_control_metadata,
					'operational_reliability_metadata'=>$operational_reliability_metadata,
					'support_observability_metadata'=>$support_observability_metadata,
					'change_management_metadata'=>$change_management_metadata,
					'integration_boundary_metadata'=>$integration_boundary_metadata,
					'business_policy_metadata'=>$business_policy_metadata,
					'process_policy_metadata'=>$process_policy_metadata,
					'reporting_analytics_metadata'=>$reporting_analytics_metadata,
					'notification_communication_metadata'=>$notification_communication_metadata,
					'adaptation_notes'=>[
						$namespace_note,
						'Keep query filters, tenant/workspace scoping, lifecycle transitions, audit/retention policy, uniqueness checks, ownership/access policy, idempotency/retry checks, support/observability policy, change-management policy, integration boundary policy, business policy, process/workflow policy, reporting/analytics policy, notification/communication policy, and external service calls in this app-owned repository or an app adapter.',
						'Do not move app-specific query behavior into Dataphyre SQL internals.',
					],
					'content'=>$repository_source,
				],
				[
					'path'=>$framework_path.'/Record/'.$class.'Record.php',
					'language'=>'php',
					'purpose'=>'Record accessors for hydrated table rows',
					'schema_field_metadata'=>$schema_field_metadata,
					'column_security_metadata'=>$column_security_metadata,
					'relationship_integrity_metadata'=>$relationship_integrity_metadata,
					'scope_identifier_metadata'=>$scope_identifier_metadata,
					'data_integrity_metadata'=>$data_integrity_metadata,
					'lifecycle_policy_metadata'=>$lifecycle_policy_metadata,
					'audit_retention_metadata'=>$audit_retention_metadata,
					'access_control_metadata'=>$access_control_metadata,
					'operational_reliability_metadata'=>$operational_reliability_metadata,
					'support_observability_metadata'=>$support_observability_metadata,
					'change_management_metadata'=>$change_management_metadata,
					'integration_boundary_metadata'=>$integration_boundary_metadata,
					'business_policy_metadata'=>$business_policy_metadata,
					'process_policy_metadata'=>$process_policy_metadata,
					'reporting_analytics_metadata'=>$reporting_analytics_metadata,
					'notification_communication_metadata'=>$notification_communication_metadata,
					'adaptation_notes'=>[
						$namespace_note,
						'Add typed convenience accessors only for fields the app actually uses.',
					],
					'content'=>$record_source,
				],
			],
		]];
	}

	/**
	 * Builds compact schema-side metadata for app-owned TableSchema/repository adaptation.
	 *
	 * @param array<int,array<string,mixed>> $fields Parsed schema fields.
	 * @return array{owner:string,has_schema_field_metadata:bool,fields:array<int,array<string,mixed>>,policy:string}
	 */
	private function app_builder_data_model_schema_field_metadata(array $fields): array {
		$metadata=[];
		foreach($fields as $field){
			if(!is_array($field)){
				continue;
			}
			$name=(string)($field['name'] ?? '');
			if($name===''){
				continue;
			}
			$type=(string)($field['type'] ?? 'string');
			$row=[
				'field'=>$name,
				'field_type'=>$type,
			];
			$cast=$this->app_builder_sql_cast($type);
			if($cast!==null){
				$row['cast']=$cast;
			}
			if(($field['required'] ?? false)===true){
				$row['required']=true;
			}
			if(array_key_exists('default', $field)){
				$row['default']=$this->app_builder_scalar_default($field['default']);
			}
			$options=$this->app_builder_field_options($field);
			if($options!==[]){
				$row['options']=$options;
			}
			$target=trim((string)($field['foreign_key_target'] ?? ''));
			if($target!==''){
				$row['foreign_key_target']=$target;
			}
			if(($field['not_foreign_key'] ?? false)===true){
				$row['not_foreign_key']=true;
			}
			if(count($row)>2){
				$metadata[]=$row;
			}
		}
		return [
			'owner'=>'consuming_application',
			'has_schema_field_metadata'=>$metadata!==[],
			'fields'=>$metadata,
			'policy'=>'Use this compact handoff for app-owned TableSchema defaults, casts, required validation, option sets, and explicit relationship decisions without opening broader governance context.',
		];
	}

	/**
	 * Builds compact app-owned index, uniqueness, and constraint guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Schemas to summarize.
	 * @param array<int,array<string,mixed>> $planned_schema_context Full planned-schema context for target classification.
	 * @return array<string,mixed> Data integrity guidance.
	 */
	private function app_builder_data_integrity_summary(array $schemas, array $planned_schema_context=[]): array {
		$planned_entities=[];
		foreach(($planned_schema_context!==[] ? $planned_schema_context : $schemas) as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$planned_entities[$this->app_builder_entity_key($entity)]=true;
			}
		}
		$indexes=[];
		$unique_constraints=[];
		$foreign_key_constraints=[];
		$required_fields=[];
		$external_identifier_indexes=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$scope_fields=[];
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				if(($field['required'] ?? false)===true){
					$required_fields[]=[
						'entity'=>$entity,
						'table'=>$table,
						'field'=>$name,
					];
				}
				if($this->app_builder_field_matches_sensitivity_category($name, 'tenant_or_access_scope')){
					$scope_fields[]=$name;
					$indexes[]=[
						'entity'=>$entity,
						'table'=>$table,
						'fields'=>[$name],
						'kind'=>'scope_lookup',
						'policy'=>'tenant_or_workspace_scope_filter',
					];
				}
				if(in_array($name, ['status', 'priority'], true)){
					$indexes[]=[
						'entity'=>$entity,
						'table'=>$table,
						'fields'=>[$name],
						'kind'=>'lifecycle_filter',
						'policy'=>'panel_filter_and_queue_scan',
					];
				}
				if(in_array((string)($field['type'] ?? ''), ['date', 'datetime', 'timestamp'], true) || preg_match('/(?:_at|_date|_until|_period_end)$/', $name)===1){
					$indexes[]=[
						'entity'=>$entity,
						'table'=>$table,
						'fields'=>[$name],
						'kind'=>'timeline_filter',
						'policy'=>'dashboard_reporting_or_lifecycle_range_scan',
					];
				}
				if(($field['not_foreign_key'] ?? false)===true || preg_match('/(?:external_id|provider_reference|invoice_number|tracking_number|^key$|_key$|email)$/', $name)===1){
					$row=[
						'entity'=>$entity,
						'table'=>$table,
						'field'=>$name,
						'policy'=>'external_or_business_identifier_lookup',
					];
					$external_identifier_indexes[]=$row;
					$indexes[]=[
						'entity'=>$entity,
						'table'=>$table,
						'fields'=>[$name],
						'kind'=>'business_identifier_lookup',
						'policy'=>'confirm_tenant_scope_before_global_uniqueness',
					];
				}
				$unique_with=is_array($field['unique_with'] ?? null) ? array_values(array_map('strval', $field['unique_with'])) : [];
				if(($field['unique'] ?? false)===true || $unique_with!==[]){
					$constraint_fields=array_values(array_unique(array_merge($unique_with, [$name])));
					$unique_constraints[]=[
						'entity'=>$entity,
						'table'=>$table,
						'fields'=>$constraint_fields,
						'source'=>$unique_with!==[] ? 'explicit_unique_with' : 'explicit_unique',
						'policy'=>'implement_in_app_owned_schema_or_database_migration',
					];
				}
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$field=(string)($relationship['field'] ?? '');
				$target_entity=(string)($relationship['target_entity'] ?? '');
				if($field===''){
					continue;
				}
				$scope=isset($planned_entities[$this->app_builder_entity_key($target_entity)]) ? 'planned_entity' : 'external_reference';
				$indexes[]=[
					'entity'=>$entity,
					'table'=>$table,
					'fields'=>[$field],
					'kind'=>'relationship_lookup',
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>$scope,
					'policy'=>'app_owned_foreign_key_or_adapter_lookup',
				];
				$foreign_key_constraints[]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$field,
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>$scope,
					'policy'=>$scope==='planned_entity' ? 'create_or_validate_app_owned_foreign_key_constraint' : 'resolve_through_app_owned_external_adapter_or_existing_table',
				];
			}
			if($scope_fields!==[]){
				foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
					if(!is_array($field)){
						continue;
					}
					$name=(string)($field['name'] ?? '');
					if($name==='' || in_array($name, $scope_fields, true)){
						continue;
					}
					if(($field['not_foreign_key'] ?? false)===true || preg_match('/(?:external_id|provider_reference|invoice_number|tracking_number|^key$|_key$|email)$/', $name)===1){
						$unique_constraints[]=[
							'entity'=>$entity,
							'table'=>$table,
							'fields'=>array_values(array_unique([$scope_fields[0], $name])),
							'source'=>'inferred_scope_business_identifier',
							'policy'=>'confirm_with_app_contract_before_enforcing',
						];
					}
				}
			}
		}
		$index_keys=[];
		$deduped_indexes=[];
		foreach($indexes as $index){
			$key=(string)($index['table'] ?? '').'|'.implode(',', is_array($index['fields'] ?? null) ? $index['fields'] : []).'|'.(string)($index['kind'] ?? '');
			if(isset($index_keys[$key])){
				continue;
			}
			$index_keys[$key]=true;
			$deduped_indexes[]=$index;
		}
		$constraint_keys=[];
		$deduped_unique=[];
		foreach($unique_constraints as $constraint){
			$key=(string)($constraint['table'] ?? '').'|'.implode(',', is_array($constraint['fields'] ?? null) ? $constraint['fields'] : []);
			if(isset($constraint_keys[$key])){
				continue;
			}
			$constraint_keys[$key]=true;
			$deduped_unique[]=$constraint;
		}
		return [
			'owner'=>'consuming_application',
			'has_integrity_work'=>$deduped_indexes!==[] || $deduped_unique!==[] || $foreign_key_constraints!==[],
			'indexes'=>$deduped_indexes,
			'unique_constraints'=>$deduped_unique,
			'foreign_key_constraints'=>$foreign_key_constraints,
			'required_fields'=>$required_fields,
			'external_identifier_indexes'=>$external_identifier_indexes,
			'policy'=>'Use these as app-owned schema/migration and repository-check hints; the MCP does not execute migrations or require release validation for ordinary app work.',
			'not_required'=>[
				'Dataphyre runtime-internal edits for app-specific indexes',
				'MCP/release-surface validation for ordinary app integrity hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a compact file-by-file edit queue without inlining skeleton bodies.
	 *
	 * @param array<int,array<string,mixed>> $skeletons Full skeleton previews.
	 * @param array<string,mixed> $implementation_matrix App-owned obligation matrix.
	 * @param array<string,mixed> $relationship_adapter_handoff Relationship adapter handoff.
	 * @param array<string,mixed> $verification_recovery_plan Focused verification recovery branches.
	 * @return array<string,mixed> Compact implementation recipe.
	 */
	private function app_builder_implementation_recipe(array $skeletons, array $implementation_matrix, array $relationship_adapter_handoff, array $verification_recovery_plan): array {
		$matrix_by_path=[];
		foreach(is_array($implementation_matrix['work_items'] ?? null) ? $implementation_matrix['work_items'] : [] as $work_item){
			if(!is_array($work_item)){
				continue;
			}
			$id=(string)($work_item['id'] ?? '');
			foreach(is_array($work_item['paths'] ?? null) ? $work_item['paths'] : [] as $path){
				$path=(string)$path;
				if($path==='' || $id===''){
					continue;
				}
				$matrix_by_path[$path]['obligation_ids'][$id]=true;
				foreach(is_array($work_item['verification_tools'] ?? null) ? $work_item['verification_tools'] : [] as $tool){
					$tool=(string)$tool;
					if($tool!==''){
						$matrix_by_path[$path]['verification_tools'][$tool]=true;
					}
				}
				$action=(string)($work_item['action'] ?? '');
				if($action!==''){
					$matrix_by_path[$path]['matrix_actions'][$action]=true;
				}
			}
		}
		$recovery_by_tool=[];
		foreach(is_array($verification_recovery_plan['branches'] ?? null) ? $verification_recovery_plan['branches'] : [] as $branch){
			if(is_array($branch) && ($branch['tool'] ?? '')!==''){
				$recovery_by_tool[(string)$branch['tool']]=$this->app_builder_verification_recovery_branch_pointer((string)$branch['tool']);
			}
		}
		$relationship_adapters=is_array($relationship_adapter_handoff['adapters'] ?? null) ? $relationship_adapter_handoff['adapters'] : [];
		$items=[];
		foreach($skeletons as $skeleton){
			if(!is_array($skeleton)){
				continue;
			}
			$path=(string)($skeleton['path'] ?? '');
			if($path===''){
				continue;
			}
			$kind=(string)($skeleton['kind'] ?? 'unknown');
			$edit_tasks=array_values(array_filter(array_map('strval', is_array($skeleton['adaptation_notes'] ?? null) ? $skeleton['adaptation_notes'] : []), static fn(string $task): bool => $task!==''));
			foreach(array_keys($matrix_by_path[$path]['matrix_actions'] ?? []) as $action){
				if(!in_array($action, $edit_tasks, true)){
					$edit_tasks[]=$action;
				}
			}
			$related_adapters=[];
			if(in_array($kind, ['table_repository', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'], true)){
				foreach($relationship_adapters as $adapter){
					if(!is_array($adapter)){
						continue;
					}
					$related_adapters[]=[
						'adapter_stem'=>(string)($adapter['adapter_stem'] ?? ''),
						'panel_field_source'=>(string)($adapter['panel_field_source'] ?? ''),
						'repository_touchpoint'=>(string)($adapter['repository_touchpoint'] ?? ''),
						'verification_focus'=>array_values(array_map('strval', is_array($adapter['verification_focus'] ?? null) ? $adapter['verification_focus'] : [])),
					];
					if(count($related_adapters)>=3){
						break;
					}
				}
			}
			$verification_tools=array_values(array_keys($matrix_by_path[$path]['verification_tools'] ?? []));
			$tool_priority=match($kind){
				'panel_resource', 'panel_manifest', 'panel_regression_manifest'=>['dataphyre_run_panel_regression', 'dataphyre_php_lint'],
				'table_schema', 'table_repository', 'table_record'=>['dataphyre_sql_schema_read', 'dataphyre_php_lint'],
				'api_route', 'api_endpoint_handler', 'api_regression_manifest'=>['dataphyre_route_manifest_read', 'dataphyre_api_docs_static_summary', 'dataphyre_php_lint'],
				'panel_code_unit_test'=>['app_local_php_unit_tests', 'dataphyre_run_panel_regression', 'dataphyre_php_lint'],
				'api_code_unit_test'=>['app_local_php_unit_tests', 'dataphyre_api_docs_static_summary', 'dataphyre_php_lint'],
				'sql_code_unit_test'=>['app_local_php_unit_tests', 'dataphyre_sql_schema_read', 'dataphyre_php_lint'],
				'app_code_unit_test'=>['app_local_php_unit_tests', 'dataphyre_php_lint'],
				default=>[],
			};
			$failure_branch=null;
			foreach(array_values(array_unique(array_merge($tool_priority, $verification_tools))) as $tool){
				if(isset($recovery_by_tool[$tool])){
					$failure_branch=$recovery_by_tool[$tool];
					break;
				}
			}
			$item=[
				'order'=>count($items)+1,
				'path'=>$path,
				'kind'=>$kind,
				'purpose'=>(string)($skeleton['purpose'] ?? 'App-owned file'),
				'edit_tasks'=>array_slice($edit_tasks, 0, 6),
				'obligation_ids'=>array_values(array_keys($matrix_by_path[$path]['obligation_ids'] ?? [])),
				'verification_tools'=>$verification_tools,
			];
			if($related_adapters!==[]){
				$item['relationship_adapters']=$related_adapters;
			}
			if($failure_branch!==null){
				$item['failure_branch']=$failure_branch;
			}
			$items[]=$item;
			if(count($items)>=12){
				break;
			}
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$items===[] ? 'no_file_edit_recipe_needed' : 'ready_for_file_edits',
			'purpose'=>'Compact file-by-file edit recipe without skeleton bodies.',
			'source'=>'code_skeletons.adaptation_notes + implementation_matrix + relationship_adapter_handoff + verification_recovery_plan',
			'items'=>$items,
			'parallel_batches'=>$this->app_builder_implementation_parallel_batches($items),
			'truncated'=>count($skeletons)>count($items),
			'open_full_skeletons_when'=>'Only when ready to copy and adapt app-owned skeleton bodies.',
			'not_required'=>[
				'full skeleton bodies in the first compact response',
				'governance context for ordinary app-owned file edits',
				'MCP/release-surface validation for ordinary app-owned file edits',
			],
		];
	}

	/**
	 * Groups implementation recipe items into conservative parallel edit batches.
	 *
	 * @param array<int,array<string,mixed>> $items Compact implementation items.
	 * @return array<string,mixed> Ordered batch plan for efficient app-owned edits.
	 */
	private function app_builder_implementation_parallel_batches(array $items): array {
		$batch_defs=[
			'data_model_schema'=>[
				'order'=>1,
				'label'=>'Data model schema contracts',
				'kinds'=>['table_schema'],
				'depends_on'=>[],
				'action'=>'Create or adapt app-owned TableSchema files before repository, Panel, API, or regression files rely on columns.',
			],
			'data_model_adapters'=>[
				'order'=>2,
				'label'=>'Data model repositories and records',
				'kinds'=>['table_repository', 'table_record'],
				'depends_on'=>['data_model_schema'],
				'action'=>'Wire app-owned repository and record behavior after schema contracts are settled.',
			],
			'app_surfaces'=>[
				'order'=>3,
				'label'=>'Panel/API resources and handlers',
				'kinds'=>['panel_resource', 'api_route', 'api_endpoint_handler'],
				'depends_on'=>['data_model_schema', 'data_model_adapters'],
				'action'=>'Adapt user-facing resources and handlers against the finalized app-owned data model and relationship adapters.',
			],
			'manifests_and_regressions'=>[
				'order'=>4,
				'label'=>'Manifests and focused regression specs',
				'kinds'=>['panel_manifest', 'panel_regression_manifest', 'api_regression_manifest'],
				'depends_on'=>['app_surfaces'],
				'action'=>'Update manifests and focused regression specs after resource/handler names and fields are stable.',
			],
			'other_app_files'=>[
				'order'=>5,
				'label'=>'Other app-owned files',
				'kinds'=>[],
				'depends_on'=>['data_model_schema'],
				'action'=>'Handle remaining app-owned files after the core schema contract is known.',
			],
		];
		$items_by_batch=[];
		foreach($items as $item){
			if(!is_array($item)){
				continue;
			}
			$kind=(string)($item['kind'] ?? '');
			$batch_id='other_app_files';
			foreach($batch_defs as $candidate_id=>$definition){
				if(in_array($kind, $definition['kinds'], true)){
					$batch_id=(string)$candidate_id;
					break;
				}
			}
			$items_by_batch[$batch_id][]=$item;
		}
		$batches=[];
		foreach($batch_defs as $id=>$definition){
			$batch_items=is_array($items_by_batch[$id] ?? null) ? $items_by_batch[$id] : [];
			if($batch_items===[]){
				continue;
			}
			$paths=[];
			$kinds=[];
			$item_orders=[];
			$verification_tools=[];
			foreach($batch_items as $item){
				$path=(string)($item['path'] ?? '');
				if($path!==''){
					$paths[]=$path;
				}
				$kind=(string)($item['kind'] ?? '');
				if($kind!==''){
					$kinds[]=$kind;
				}
				if(isset($item['order'])){
					$item_orders[]=(int)$item['order'];
				}
				foreach(is_array($item['verification_tools'] ?? null) ? $item['verification_tools'] : [] as $tool){
					$tool=(string)$tool;
					if($tool!==''){
						$verification_tools[]=$tool;
					}
				}
			}
			$batches[]=[
				'id'=>(string)$id,
				'order'=>(int)$definition['order'],
				'label'=>(string)$definition['label'],
				'can_parallelize_within_batch'=>count($batch_items)>1,
				'depends_on'=>array_values(array_map('strval', is_array($definition['depends_on'] ?? null) ? $definition['depends_on'] : [])),
				'item_orders'=>array_values(array_unique($item_orders)),
				'kinds'=>array_values(array_unique($kinds)),
				'paths'=>array_values(array_unique($paths)),
				'verification_tools'=>array_values(array_unique($verification_tools)),
				'action'=>(string)$definition['action'],
			];
		}
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Conservative dependency-aware batches for efficient app-owned edits.',
			'batches'=>$batches,
			'policy'=>'Agents may parallelize within a batch after local_convention_probe and prewrite blockers are resolved; preserve batch order across schema, adapters, surfaces, and focused regressions.',
			'not_required'=>[
				'framework-internal edits for app-specific batching',
				'framework/release escalation for ordinary app edit batching',
			],
		];
	}

	/**
	 * Builds compact app-owned lifecycle/state guidance from status-like fields.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Lifecycle policy guidance.
	 */
	private function app_builder_lifecycle_policy_summary(array $schemas): array {
		$state_fields=[];
		$default_filters=[];
		$action_checks=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if(!$this->app_builder_lifecycle_field_name($name)){
					continue;
				}
				$options=$this->app_builder_field_options($field);
				$default=array_key_exists('default', $field) ? (string)$field['default'] : '';
				$terminal=[];
				$active=[];
				foreach($options as $option){
					if($this->app_builder_lifecycle_terminal_option($option)){
						$terminal[]=$option;
					}else{
						$active[]=$option;
					}
				}
				$row=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'options'=>$options,
					'default'=>$default,
					'terminal_options'=>$terminal,
					'active_options'=>$active,
					'transition_policy'=>$options!==[] ? 'define_allowed_transitions_in_app_owned_policy_or_adapter' : 'define_lifecycle_values_before_adding_transition_actions',
					'verification_focus'=>'default_filters_transition_actions_and_terminal_state_negative_checks',
				];
				$state_fields[]=$row;
				$default_filters[]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'default'=>$default,
					'exclude_by_default'=>$terminal,
					'policy'=>'confirm_list_filters_and_dashboard_queries_with_consuming_app_contract',
				];
				$action_checks[]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'actions'=>['create_default_state', 'transition_between_allowed_states', 'block_invalid_terminal_transitions'],
					'policy'=>'implement_in_app_owned_actions_callbacks_dialbacks_plugins_or_adapters',
				];
			}
		}
		return [
			'owner'=>'consuming_application',
			'has_lifecycle_fields'=>$state_fields!==[],
			'state_fields'=>$state_fields,
			'default_filters'=>$default_filters,
			'action_checks'=>$action_checks,
			'policy'=>$state_fields===[] ? 'No lifecycle/status fields were inferred.' : 'Treat status/stage/decision/priority fields as app-owned lifecycle policy: define defaults, allowed transitions, terminal states, Panel filters/actions, and focused negative checks.',
			'not_required'=>[
				'Dataphyre runtime-internal workflow engine edits for ordinary app lifecycle fields',
				'MCP/release-surface validation for ordinary app lifecycle hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Turns lifecycle metadata into an app-agent handoff for state-machine wiring.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param array<string,mixed> $lifecycle_policy_summary Lifecycle metadata.
	 * @return array<string,mixed> Concrete app-owned state-machine handoff.
	 */
	private function app_builder_lifecycle_state_handoff(array $schemas, array $lifecycle_policy_summary): array {
		$machines=[];
		$state_fields=is_array($lifecycle_policy_summary['state_fields'] ?? null) ? $lifecycle_policy_summary['state_fields'] : [];
		foreach($state_fields as $field){
			if(!is_array($field)){
				continue;
			}
			$entity=(string)($field['entity'] ?? '');
			$table=(string)($field['table'] ?? '');
			$name=(string)($field['field'] ?? '');
			$options=array_values(array_map('strval', is_array($field['options'] ?? null) ? $field['options'] : []));
			$default=(string)($field['default'] ?? '');
			$candidates=$options;
			$needs_confirmation=false;
			if($candidates===[]){
				$needs_confirmation=true;
				$candidates=array_values(array_filter(array_unique([
					$default,
					$name==='priority' ? 'normal' : 'draft',
					$name==='priority' ? 'high' : 'active',
					$name==='priority' ? 'urgent' : 'closed',
				]), static fn(string $value): bool => $value!==''));
			}
			$terminal=array_values(array_map('strval', is_array($field['terminal_options'] ?? null) ? $field['terminal_options'] : []));
			if($terminal===[]){
				$terminal=array_values(array_filter($candidates, fn(string $option): bool => $this->app_builder_lifecycle_terminal_option($option)));
			}
			$machines[]=[
				'entity'=>$entity,
				'table'=>$table,
				'field'=>$name,
				'default_state'=>$default,
				'candidate_states'=>$candidates,
				'candidate_source'=>$options!==[] ? 'field_metadata.options' : 'conservative_name_based_suggestions',
				'needs_app_confirmation'=>$needs_confirmation,
				'terminal_state_candidates'=>$terminal,
				'default_filter'=>$terminal===[] ? 'confirm whether list and dashboard views need an active-state filter' : 'exclude terminal states by default except reporting, audit, and explicit archive views',
				'action_guards'=>[
					'validate_transition_source_and_target_against_app_owned_policy',
					'check_actor_permission_and_record_scope_before_transition',
					'require_reason_or_audit_note_for_terminal_or_reversal_transitions',
					'block_updates_to_terminal_records unless the app defines a reopening action',
				],
				'verification_focus'=>[
					'default_state_on_create',
					'invalid_transition_rejected',
					'terminal_state_hidden_from_default_lists',
					'terminal_record_edit_or_reopen_guard',
				],
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$machines===[] ? 'not_triggered' : 'ready_for_app_owned_lifecycle_design',
			'purpose'=>'Concrete app-owned state/default/filter/action guard handoff for status-like fields.',
			'machine_count'=>count($machines),
			'machines'=>$machines,
			'links'=>[
				'lifecycle_policy_summary'=>'builder_response.lifecycle_policy_summary',
				'fixture_lifecycle_cases'=>'builder_response.verification_fixture_handoff.lifecycle_cases',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$machines===[] ? 'No lifecycle state machines were inferred.' : 'Keep lifecycle state machines app-owned; implement transitions through app callbacks, dialbacks, plugins, or adapters instead of Dataphyre runtime internals.',
			'not_required'=>[
				'governance context for ordinary app lifecycle design',
				'MCP/release-surface validation for ordinary app lifecycle design',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Determines whether a field name carries app lifecycle semantics.
	 *
	 * @param string $name Field name.
	 * @return bool True for status-like lifecycle fields.
	 */
	private function app_builder_lifecycle_field_name(string $name): bool {
		return in_array($name, ['status', 'stage', 'state', 'decision', 'priority'], true)
			|| str_ends_with($name, '_status')
			|| str_ends_with($name, '_stage')
			|| str_ends_with($name, '_decision');
	}

	/**
	 * Heuristically identifies terminal lifecycle option values.
	 *
	 * @param string $option Option value.
	 * @return bool True when the option usually ends an active workflow.
	 */
	private function app_builder_lifecycle_terminal_option(string $option): bool {
		$option=strtolower(trim($option));
		return in_array($option, [
			'approved',
			'archived',
			'canceled',
			'cancelled',
			'closed',
			'completed',
			'done',
			'expired',
			'failed',
			'paid',
			'rejected',
			'resolved',
			'retired',
			'suspended',
		], true);
	}

	/**
	 * Builds compact audit, retention, export, and corporate-record guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Audit and retention guidance.
	 */
	private function app_builder_audit_retention_summary(array $schemas): array {
		$fields_by_category=[
			'audit_actor_fields'=>[],
			'approval_fields'=>[],
			'effective_date_fields'=>[],
			'retention_fields'=>[],
			'legal_hold_fields'=>[],
			'export_fields'=>[],
			'residency_fields'=>[],
			'classification_fields'=>[],
		];
		$controls=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_audit_retention_field_category($name);
				if($category===''){
					continue;
				}
				$row=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_audit_retention_field_policy($category),
				];
				$fields_by_category[$category][]=$row;
			}
		}
		if($fields_by_category['audit_actor_fields']!==[] || $fields_by_category['approval_fields']!==[]){
			$controls[]=[
				'id'=>'actor_and_approval_provenance',
				'fields'=>array_values(array_merge($fields_by_category['audit_actor_fields'], $fields_by_category['approval_fields'])),
				'policy'=>'populate_from_app_owned_actor_context_and_permission_gate_edits',
				'verification_focus'=>'actor_identity_approval_permission_and_immutability_checks',
			];
		}
		if($fields_by_category['retention_fields']!==[] || $fields_by_category['legal_hold_fields']!==[]){
			$controls[]=[
				'id'=>'retention_hold_and_purge_policy',
				'fields'=>array_values(array_merge($fields_by_category['retention_fields'], $fields_by_category['legal_hold_fields'])),
				'policy'=>'define_app_owned_hold_expiry_and_purge_rules_before_destructive_actions',
				'verification_focus'=>'legal_hold_blocks_delete_and_retention_expiry_filters',
			];
		}
		if($fields_by_category['effective_date_fields']!==[]){
			$controls[]=[
				'id'=>'effective_date_window_policy',
				'fields'=>$fields_by_category['effective_date_fields'],
				'policy'=>'enforce_app_owned_effective_expiry_windows_in_repositories_and_panel_filters',
				'verification_focus'=>'effective_at_expires_at_visibility_and_transition_checks',
			];
		}
		if($fields_by_category['export_fields']!==[] || $fields_by_category['residency_fields']!==[] || $fields_by_category['classification_fields']!==[]){
			$controls[]=[
				'id'=>'export_residency_classification_policy',
				'fields'=>array_values(array_merge($fields_by_category['export_fields'], $fields_by_category['residency_fields'], $fields_by_category['classification_fields'])),
				'policy'=>'permission_gate_exports_and_apply_region_classification_rules_in_app_owned_adapters',
				'verification_focus'=>'export_permission_region_and_classification_negative_checks',
			];
		}
		return [
			'owner'=>'consuming_application',
			'has_audit_retention_fields'=>$controls!==[],
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$controls===[] ? 'No audit, retention, export, residency, or classification fields were inferred.' : 'Treat audit, retention, legal-hold, export, residency, classification, approval, and effective-date fields as app-owned policy obligations with focused checks.',
			'not_required'=>[
				'enterprise audit for ordinary app-owned corporate-record fields',
				'Dataphyre runtime-internal records-management engine edits for one app',
				'MCP/release-surface validation for ordinary app audit/retention hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for audit, retention, residency, and evidence policy.
	 *
	 * @param array<string,mixed> $audit_retention_summary Audit/retention metadata.
	 * @return array<string,mixed> App-owned audit/retention handoff.
	 */
	private function app_builder_audit_retention_handoff(array $audit_retention_summary): array {
		$controls=is_array($audit_retention_summary['controls'] ?? null) ? $audit_retention_summary['controls'] : [];
		$policies=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$policies[]=[
				'id'=>$id,
				'fields'=>$this->app_builder_audit_retention_control_fields($control),
				'evidence_contract'=>$this->app_builder_audit_retention_evidence_contract($id),
				'write_order'=>$this->app_builder_audit_retention_write_order($id),
				'negative_checks'=>$this->app_builder_audit_retention_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$policies===[] ? 'not_triggered' : 'ready_for_app_owned_records_policy',
			'purpose'=>'Concrete app-owned actor provenance, approval, retention, legal-hold, effective-date, export, residency, classification, and evidence handoff.',
			'policy_count'=>count($policies),
			'policies'=>$policies,
			'default_evidence_policy'=>[
				'store'=>'actor id or service actor, action, decision reason, scope, timestamp, policy id/version, and evidence/reference ids',
				'redact'=>'secrets, raw payloads, signed URLs, tenant/customer names, and local machine paths from shared handoffs',
				'preserve'=>'copy-safe event summaries and immutable evidence references for review, support, and audits',
			],
			'links'=>[
				'audit_retention_summary'=>'builder_response.audit_retention_summary',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'data_sensitivity_summary'=>'builder_response.data_sensitivity_summary',
				'domain_workflow_handoff'=>'builder_response.domain_workflow_handoff',
				'verification_handoff'=>'builder_response.verification_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$policies===[] ? 'No app-owned records policy controls were inferred.' : 'Keep audit provenance, retention/hold, effective windows, exports, residency, and classification in app-owned records, callbacks, dialbacks, plugins, or adapters.',
			'not_required'=>[
				'enterprise audit for ordinary app-owned corporate-record fields',
				'Dataphyre runtime-internal records-management engine edits for one app',
				'MCP/release-surface validation for ordinary app audit/retention hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Normalizes a control field list for compact audit/retention handoffs.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<int,array<string,string>> Compact field pointers.
	 */
	private function app_builder_audit_retention_control_fields(array $control): array {
		$fields=[];
		foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
			if(!is_array($field)){
				continue;
			}
			$fields[]=[
				'entity'=>(string)($field['entity'] ?? ''),
				'table'=>(string)($field['table'] ?? ''),
				'field'=>(string)($field['field'] ?? ''),
			];
		}
		return $fields;
	}

	/**
	 * Returns app-owned evidence contract notes for one records-policy control.
	 */
	private function app_builder_audit_retention_evidence_contract(string $control_id): string {
		return match($control_id){
			'actor_and_approval_provenance'=>'resolve actor from app context, permission-gate decisions, and record immutable approval/review evidence',
			'retention_hold_and_purge_policy'=>'evaluate retention expiry and legal-hold state before destructive actions and preserve hold evidence',
			'effective_date_window_policy'=>'apply effective/expiry windows in repository queries, Panel filters, and state transitions',
			'export_residency_classification_policy'=>'gate exports by permission, region, classification, redaction profile, and copy-safe export evidence',
			default=>'define app-owned records-policy evidence before exposing actions or exports',
		};
	}

	/**
	 * Returns safe write order for one records-policy control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_audit_retention_write_order(string $control_id): array {
		return match($control_id){
			'actor_and_approval_provenance'=>['resolve_actor_context', 'permission_gate_action', 'persist_actor_and_decision_reason', 'make_provenance_readable_copy_safely'],
			'retention_hold_and_purge_policy'=>['load_retention_and_hold_state', 'block_or_allow_destructive_action', 'record_hold_or_purge_decision', 'verify_purge_job_scope_when_app_adds_one'],
			'effective_date_window_policy'=>['derive_effective_window', 'apply_repository_filter', 'apply_panel_filter_or_badge', 'block_out_of_window_transition'],
			'export_residency_classification_policy'=>['resolve_export_scope', 'apply_residency_and_classification_policy', 'redact_or_deny_export', 'record_copy_safe_export_evidence'],
			default=>['derive_records_policy_scope', 'apply_policy', 'record_copy_safe_evidence'],
		};
	}

	/**
	 * Returns negative checks for one records-policy control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_audit_retention_negative_checks(string $control_id): array {
		return match($control_id){
			'actor_and_approval_provenance'=>['missing_actor_rejected_or_service_actor_recorded', 'approval_without_permission_rejected', 'approval_actor_not_mutable_after_decision'],
			'retention_hold_and_purge_policy'=>['legal_hold_blocks_delete_or_purge', 'retention_not_expired_blocks_purge', 'purge_without_policy_evidence_rejected'],
			'effective_date_window_policy'=>['before_effective_date_hidden_or_denied', 'after_expiry_hidden_or_denied', 'out_of_window_transition_rejected'],
			'export_residency_classification_policy'=>['export_without_permission_rejected', 'wrong_region_export_rejected', 'restricted_classification_redacted_or_denied'],
			default=>['records_policy_denial_checked', 'copy_safe_evidence_present'],
		};
	}

	/**
	 * Maps a field name to an audit/retention guidance category.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_audit_retention_field_category(string $name): string {
		return match(true){
			in_array($name, ['created_by', 'updated_by', 'actor_id', 'reviewed_by', 'exported_by'], true)=>'audit_actor_fields',
			in_array($name, ['approved_by', 'approved_at', 'approver_id', 'reviewed_at'], true)=>'approval_fields',
			in_array($name, ['effective_at', 'expires_at', 'expired_at'], true)=>'effective_date_fields',
			in_array($name, ['retention_until', 'retained_until', 'retain_until', 'purge_after', 'delete_after'], true)=>'retention_fields',
			str_contains($name, 'legal_hold') || str_contains($name, 'records_hold')=>'legal_hold_fields',
			str_starts_with($name, 'exported_') || in_array($name, ['export_batch_id'], true)=>'export_fields',
			in_array($name, ['data_region', 'region_code', 'residency_region'], true)=>'residency_fields',
			in_array($name, ['classification', 'data_classification'], true)=>'classification_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one audit/retention category.
	 *
	 * @param string $category Audit/retention category key.
	 * @return string Policy token.
	 */
	private function app_builder_audit_retention_field_policy(string $category): string {
		return match($category){
			'audit_actor_fields'=>'resolve_from_app_owned_actor_context_and_redact_when_shared',
			'approval_fields'=>'permission_gate_approval_mutations_and_record_decision_actor',
			'effective_date_fields'=>'enforce_effective_expiry_windows_in_repository_queries',
			'retention_fields'=>'define_retention_expiry_and_purge_policy_before_delete_actions',
			'legal_hold_fields'=>'block_destructive_actions_while_hold_is_active',
			'export_fields'=>'permission_gate_exports_and_record_export_batch_context',
			'residency_fields'=>'enforce_region_policy_in_app_owned_storage_or_query_adapters',
			'classification_fields'=>'gate_visibility_exports_and_defaults_by_classification',
			default=>'decide_app_owned_audit_retention_policy',
		};
	}

	/**
	 * Builds compact app-owned access-control and visibility guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param array<int,array<string,mixed>> $planned_schema_context Full planned-schema context for target classification.
	 * @return array<string,mixed> Access-control guidance.
	 */
	private function app_builder_access_control_summary(array $schemas, array $planned_schema_context=[]): array {
		$planned_entities=[];
		foreach(($planned_schema_context!==[] ? $planned_schema_context : $schemas) as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$planned_entities[$this->app_builder_entity_key($entity)]=true;
			}
		}
		$fields_by_category=[
			'scope_fields'=>[],
			'ownership_fields'=>[],
			'actor_fields'=>[],
			'access_policy_fields'=>[],
			'credential_reference_fields'=>[],
			'visibility_fields'=>[],
			'classification_fields'=>[],
		];
		$relationship_targets=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_access_control_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_access_control_field_policy($category),
					'verification_focus'=>$this->app_builder_access_control_verification_focus($category),
				];
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$field=(string)($relationship['field'] ?? '');
				$target_entity=(string)($relationship['target_entity'] ?? '');
				if($field==='' || $target_entity===''){
					continue;
				}
				$relationship_targets[]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$field,
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>isset($planned_entities[$this->app_builder_entity_key($target_entity)]) ? 'planned_entity' : 'external_reference',
					'policy'=>'check_target_visibility_and_scope_before_exposing_relation',
				];
			}
		}
		$controls=[];
		if($fields_by_category['scope_fields']!==[]){
			$controls[]=[
				'id'=>'tenant_workspace_scope_policy',
				'fields'=>$fields_by_category['scope_fields'],
				'policy'=>'enforce_scope_in_app_owned_repositories_filters_actions_and_tests',
				'verification_focus'=>'cross_tenant_or_cross_workspace_negative_checks',
			];
		}
		if($fields_by_category['ownership_fields']!==[] || $fields_by_category['actor_fields']!==[]){
			$controls[]=[
				'id'=>'ownership_actor_policy',
				'fields'=>array_values(array_merge($fields_by_category['ownership_fields'], $fields_by_category['actor_fields'])),
				'policy'=>'derive_actor_and_owner_from_authenticated_app_context',
				'verification_focus'=>'owner_assignee_actor_allow_deny_checks',
			];
		}
		if($fields_by_category['access_policy_fields']!==[] || $fields_by_category['credential_reference_fields']!==[]){
			$controls[]=[
				'id'=>'role_permission_policy',
				'fields'=>array_values(array_merge($fields_by_category['access_policy_fields'], $fields_by_category['credential_reference_fields'])),
				'policy'=>'resolve_roles_permissions_sso_and_api_key_references_in_app_owned_policy_or_adapters',
				'verification_focus'=>'role_permission_sso_api_key_allow_deny_checks',
			];
		}
		if($fields_by_category['visibility_fields']!==[] || $fields_by_category['classification_fields']!==[]){
			$controls[]=[
				'id'=>'visibility_classification_policy',
				'fields'=>array_values(array_merge($fields_by_category['visibility_fields'], $fields_by_category['classification_fields'])),
				'policy'=>'gate_panel_fields_exports_and_api_outputs_by_visibility_or_classification',
				'verification_focus'=>'private_team_public_classification_output_checks',
			];
		}
		if($relationship_targets!==[]){
			$controls[]=[
				'id'=>'relationship_visibility_policy',
				'fields'=>$relationship_targets,
				'policy'=>'verify_related_record_scope_and_permissions_before_linking_or_rendering_labels',
				'verification_focus'=>'relationship_lookup_scope_permission_and_empty_state_checks',
			];
		}
		return [
			'owner'=>'consuming_application',
			'has_access_control_fields'=>$controls!==[],
			'fields_by_category'=>$fields_by_category,
			'relationship_targets'=>$relationship_targets,
			'controls'=>$controls,
			'policy'=>$controls===[] ? 'No access-control fields were inferred.' : 'Treat tenant/workspace scope, ownership, roles/permissions, visibility, classification, and relationship exposure as app-owned access policy with focused allow/deny checks.',
			'not_required'=>[
				'Dataphyre runtime-internal permission engine edits for one app',
				'enterprise governance audit for ordinary app-owned access fields unless the task escalates',
				'MCP/release-surface validation for ordinary app access hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for permission, ownership, and scope checks.
	 *
	 * @param array<string,mixed> $access_control_summary Access-control metadata.
	 * @return array<string,mixed> App-owned access-control handoff.
	 */
	private function app_builder_access_control_handoff(array $access_control_summary): array {
		$controls=is_array($access_control_summary['controls'] ?? null) ? $access_control_summary['controls'] : [];
		$rules=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$fields=is_array($control['fields'] ?? null) ? $control['fields'] : [];
			$field_refs=[];
			foreach($fields as $field){
				if(!is_array($field)){
					continue;
				}
				$field_refs[]=[
					'entity'=>(string)($field['entity'] ?? ''),
					'table'=>(string)($field['table'] ?? ''),
					'field'=>(string)($field['field'] ?? ''),
					'scope'=>(string)($field['scope'] ?? ''),
					'target_entity'=>(string)($field['target_entity'] ?? ''),
				];
			}
			$rules[]=[
				'id'=>$id,
				'fields'=>$field_refs,
				'allow_path'=>$this->app_builder_access_control_allow_path($id),
				'deny_paths'=>$this->app_builder_access_control_deny_paths($id),
				'enforcement_surfaces'=>$this->app_builder_access_control_enforcement_surfaces($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$rules===[] ? 'not_triggered' : 'ready_for_app_owned_access_design',
			'purpose'=>'Concrete app-owned permission, scope, ownership, visibility, and relationship lookup handoff.',
			'rule_count'=>count($rules),
			'rules'=>$rules,
			'default_actor_context'=>[
				'source'=>'authenticated consuming-application actor context',
				'carry'=>'actor_id, roles/permissions, tenant/workspace/account scope, and request/source channel where applicable',
				'policy'=>'derive access decisions from app-owned auth/session adapters before querying or exposing records',
			],
			'links'=>[
				'access_control_summary'=>'builder_response.access_control_summary',
				'relationship_adapter_handoff'=>'builder_response.relationship_adapter_handoff',
				'verification_fixture_handoff'=>'builder_response.verification_fixture_handoff.relationship_cases',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$rules===[] ? 'No app-owned access rules were inferred.' : 'Implement allow/deny decisions in app repositories, resources, callbacks, dialbacks, plugins, or adapters before exposing Panel/API outputs.',
			'not_required'=>[
				'Dataphyre runtime-internal permission engine edits for one app',
				'enterprise governance audit for ordinary app-owned access fields unless the task escalates',
				'MCP/release-surface validation for ordinary app access design',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a compact tenant, identity, and entitlement handoff for SaaS-style app work.
	 *
	 * @param array<string,mixed> $app_contract_summary App-owned policy/data contract hints.
	 * @param array<string,mixed> $access_control_summary Access-control metadata.
	 * @param array<string,mixed> $business_policy_summary Business-policy metadata.
	 * @return array<string,mixed> Concrete app-owned tenant/identity handoff.
	 */
	private function app_builder_tenant_identity_handoff(array $app_contract_summary, array $access_control_summary, array $business_policy_summary): array {
		$present=is_array($app_contract_summary['present_fields'] ?? null) ? $app_contract_summary['present_fields'] : [];
		$missing=is_array($app_contract_summary['missing_common_fields'] ?? null) ? $app_contract_summary['missing_common_fields'] : [];
		$scope_fields=array_values(array_map('strval', is_array($present['scope'] ?? null) ? $present['scope'] : []));
		$ownership_fields=array_values(array_map('strval', is_array($present['ownership'] ?? null) ? $present['ownership'] : []));
		$access_fields=array_values(array_map('strval', is_array($present['access'] ?? null) ? $present['access'] : []));
		$billing_fields=array_values(array_map('strval', is_array($present['billing'] ?? null) ? $present['billing'] : []));
		$missing_scope=array_values(array_map('strval', is_array($missing['scope'] ?? null) ? $missing['scope'] : []));
		$missing_ownership=array_values(array_map('strval', is_array($missing['ownership'] ?? null) ? $missing['ownership'] : []));
		$access_controls=is_array($access_control_summary['controls'] ?? null) ? $access_control_summary['controls'] : [];
		$business_controls=is_array($business_policy_summary['controls'] ?? null) ? $business_policy_summary['controls'] : [];
		$access_control_ids=[];
		foreach($access_controls as $control){
			if(is_array($control) && ($control['id'] ?? '')!==''){
				$access_control_ids[]=(string)$control['id'];
			}
		}
		$business_control_ids=[];
		foreach($business_controls as $control){
			if(is_array($control) && ($control['id'] ?? '')!==''){
				$business_control_ids[]=(string)$control['id'];
			}
		}
		$has_tenant_identity=$scope_fields!==[] || $ownership_fields!==[] || $access_fields!==[] || $billing_fields!==[] || $access_control_ids!==[] || $business_control_ids!==[];
		return [
			'owner'=>'consuming_application',
			'status'=>$has_tenant_identity ? 'ready_for_app_owned_tenant_identity_design' : 'not_triggered',
			'purpose'=>'Concrete app-owned tenant scope, actor identity, permission, plan, entitlement, and relationship visibility handoff for SaaS-style application work.',
			'tenant_scope'=>[
				'fields'=>$scope_fields,
				'missing_common_fields'=>$missing_scope,
				'decision'=>$scope_fields===[] ? 'decide single-tenant, inherited parent scope, or tenant/workspace fields before broad writes' : 'apply these scope fields to repository queries, Panel filters, actions, exports, relationship lookups, and API endpoints',
				'negative_checks'=>['cross_tenant_record_hidden_or_rejected', 'missing_scope_context_rejected', 'relationship_options_scope_filtered'],
			],
			'actor_identity'=>[
				'ownership_fields'=>$ownership_fields,
				'access_fields'=>$access_fields,
				'missing_common_fields'=>$missing_ownership,
				'source'=>'authenticated consuming-application actor context',
				'carry'=>'actor_id, roles/permissions, tenant/workspace/account scope, and source channel where applicable',
				'negative_checks'=>['actor_spoofed_owner_field_ignored', 'role_without_permission_denied', 'revoked_policy_reference_denied'],
			],
			'entitlement_context'=>[
				'billing_or_plan_fields'=>$billing_fields,
				'business_controls'=>$business_control_ids,
				'decision'=>$billing_fields===[] && $business_control_ids===[] ? 'no plan or entitlement fields inferred; keep entitlement checks out of scope unless the consuming app asks for them' : 'resolve plan, subscription, entitlement, quota, and commercial policy in app-owned records or adapters before allowing gated actions',
				'negative_checks'=>['missing_entitlement_denies_action', 'expired_or_wrong_scope_entitlement_denies_action', 'quota_or_plan_override_requires_permission'],
			],
			'enforcement_order'=>[
				'load_authenticated_actor_context',
				'resolve_tenant_or_workspace_scope',
				'apply_repository_or_adapter_scope_filter',
				'check_role_permission_owner_or_visibility_rule',
				'resolve_plan_entitlement_or_quota_when_requested',
				'only_then_render_mutate_export_or_notify',
			],
			'links'=>[
				'app_contract_summary'=>'builder_response.app_contract_summary',
				'access_control_summary'=>'builder_response.access_control_summary',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'business_policy_summary'=>'builder_response.business_policy_summary',
				'policy_decision_register'=>'builder_response.policy_decision_register',
				'verification_fixture_handoff'=>'builder_response.verification_fixture_handoff',
			],
			'policy'=>$has_tenant_identity ? 'Keep tenant, actor, permission, entitlement, quota, and plan behavior in app-owned policy/config/callbacks/dialbacks/plugins or adapters; do not hardcode tenant ids, plan ids, signed URLs, or product-local identifiers in shared Dataphyre code.' : 'No tenant, identity, access, billing, or entitlement signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal tenant or identity engine edits for one app',
				'enterprise governance audit for ordinary app-owned tenant/identity decisions unless the task escalates',
				'MCP/release-surface validation for ordinary app tenant/identity hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Returns the positive path an app agent should wire for an access rule.
	 */
	private function app_builder_access_control_allow_path(string $control_id): string {
		return match($control_id){
			'tenant_workspace_scope_policy'=>'actor scope matches record tenant/workspace/account scope before list, detail, relation, action, export, or mutation access',
			'ownership_actor_policy'=>'actor is owner, assignee, requester, creator, updater, or has an app-defined override permission before privileged visibility or mutation',
			'role_permission_policy'=>'actor role/permission/API-key/SSO policy explicitly allows the operation in the current tenant/workspace scope',
			'visibility_classification_policy'=>'record visibility/classification allows the actor audience and output surface after redaction policy is applied',
			'relationship_visibility_policy'=>'related record exists and is visible in the actor scope before option labels, relation managers, links, or lookups are shown',
			default=>'app-owned policy explicitly allows the requested operation for the actor and record scope',
		};
	}

	/**
	 * Returns focused denial cases an app agent should verify.
	 *
	 * @return array<int,string> Denial checks.
	 */
	private function app_builder_access_control_deny_paths(string $control_id): array {
		return match($control_id){
			'tenant_workspace_scope_policy'=>['cross_tenant_record_hidden_or_rejected', 'missing_scope_context_rejected', 'scope_filter_applied_to_lists_relationships_actions_and_exports'],
			'ownership_actor_policy'=>['non_owner_without_override_denied', 'actor_spoofed_owner_field_ignored', 'assignment_or_requester_change_permission_checked'],
			'role_permission_policy'=>['role_without_permission_denied', 'revoked_or_missing_policy_reference_denied', 'api_key_or_sso_reference_scope_checked'],
			'visibility_classification_policy'=>['private_record_hidden_from_non_allowed_actor', 'classification_requires_permission_or_redaction', 'export_or_diagnostic_output_respects_visibility'],
			'relationship_visibility_policy'=>['out_of_scope_related_record_not_selectable', 'missing_related_record_empty_state_safe', 'relationship_label_not_leaked_without_permission'],
			default=>['unauthorized_actor_denied', 'missing_scope_denied', 'sensitive_output_not_leaked'],
		};
	}

	/**
	 * Returns app-owned surfaces where an access rule should be enforced.
	 *
	 * @return array<int,string> Enforcement surfaces.
	 */
	private function app_builder_access_control_enforcement_surfaces(string $control_id): array {
		return match($control_id){
			'relationship_visibility_policy'=>['repository_relationship_options', 'panel_relationship_fields', 'relation_managers_or_detail_links', 'route_free_panel_regression'],
			'visibility_classification_policy'=>['panel_table_columns', 'panel_forms', 'exports_or_api_outputs', 'diagnostic_handoff_redaction'],
			default=>['repository_queries', 'panel_filters', 'panel_actions', 'app_callbacks_dialbacks_plugins_or_adapters', 'focused_allow_deny_regression'],
		};
	}

	/**
	 * Maps a field name to an access-control guidance category.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_access_control_field_category(string $name): string {
		$name=strtolower(trim($name));
		if($name===''){
			return '';
		}
		$category=match(true){
			in_array($name, ['owner_id', 'assignee_id', 'requester_id', 'assigned_to', 'created_by', 'updated_by'], true)=>'ownership_fields',
			in_array($name, ['actor_id', 'approved_by', 'reviewed_by', 'exported_by'], true)=>'actor_fields',
			in_array($name, ['role_id', 'permission_id', 'policy_id', 'access_policy_id', 'group_id'], true)=>'access_policy_fields',
			in_array($name, ['api_key_id', 'sso_provider_id', 'totp_device_id', 'session_id'], true)=>'credential_reference_fields',
			in_array($name, ['visibility', 'visibility_scope', 'sharing_scope'], true)=>'visibility_fields',
			in_array($name, ['classification', 'data_classification'], true)=>'classification_fields',
			default=>'',
		};
		if($category!==''){
			return $category;
		}
		return $this->app_builder_field_matches_sensitivity_category($name, 'tenant_or_access_scope') ? 'scope_fields' : '';
	}

	/**
	 * Returns app-owned handling guidance for one access-control category.
	 *
	 * @param string $category Access-control category key.
	 * @return string Policy token.
	 */
	private function app_builder_access_control_field_policy(string $category): string {
		return match($category){
			'scope_fields'=>'enforce_scope_in_app_owned_repositories_filters_actions_and_tests',
			'ownership_fields'=>'derive_owner_assignee_requester_from_app_context_or_validated_relation',
			'actor_fields'=>'derive_actor_from_authenticated_app_context_and_redact_when_shared',
			'access_policy_fields'=>'resolve_roles_permissions_and_policies_in_app_owned_policy_or_adapter',
			'credential_reference_fields'=>'treat_auth_provider_references_as_sensitive_app_owned_relationships',
			'visibility_fields'=>'gate_panel_api_and_export_outputs_by_visibility_policy',
			'classification_fields'=>'gate_visibility_exports_and_defaults_by_classification',
			default=>'decide_app_owned_access_policy',
		};
	}

	/**
	 * Returns the focused verification target for one access-control category.
	 *
	 * @param string $category Access-control category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_access_control_verification_focus(string $category): string {
		return match($category){
			'scope_fields'=>'tenant_scope_and_cross_tenant_negative_checks',
			'ownership_fields'=>'owner_assignee_allow_deny_checks',
			'actor_fields'=>'actor_provenance_and_redaction_checks',
			'access_policy_fields'=>'role_permission_allow_deny_checks',
			'credential_reference_fields'=>'auth_provider_reference_redaction_and_scope_checks',
			'visibility_fields'=>'private_team_public_visibility_checks',
			'classification_fields'=>'classification_visibility_and_export_checks',
			default=>'focused_app_owned_access_checks',
		};
	}

	/**
	 * Builds compact app-owned operational reliability guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Operational reliability guidance.
	 */
	private function app_builder_operational_reliability_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_operational_reliability_task_signals($task);
		$fields_by_category=[
			'idempotency_fields'=>[],
			'request_hash_fields'=>[],
			'retry_fields'=>[],
			'delivery_state_fields'=>[],
			'import_export_fields'=>[],
			'external_reference_fields'=>[],
			'queue_job_fields'=>[],
		];
		$entity_signals=[];
		$relationship_targets=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_operational_reliability_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_operational_reliability_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_operational_reliability_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_operational_reliability_category_policy($category),
					'verification_focus'=>$this->app_builder_operational_reliability_verification_focus($category),
				];
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$field=(string)($relationship['field'] ?? '');
				$target_entity=(string)($relationship['target_entity'] ?? '');
				if($field==='' || $target_entity===''){
					continue;
				}
				if($this->app_builder_operational_reliability_entity_category($target_entity)===''){
					continue;
				}
				$relationship_targets[]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$field,
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'policy'=>'preserve_reliability_context_across_related_records',
				];
			}
		}
		$controls=[];
		if($fields_by_category['idempotency_fields']!==[] || $fields_by_category['request_hash_fields']!==[] || in_array('idempotent_side_effects', $task_signals, true)){
			$controls[]=[
				'id'=>'idempotency_replay_policy',
				'fields'=>array_values(array_merge($fields_by_category['idempotency_fields'], $fields_by_category['request_hash_fields'])),
				'policy'=>'reserve_scoped_idempotency_keys_and_reject_same_key_changed_payloads_in_app_owned_code',
				'verification_focus'=>'duplicate_replay_and_changed_payload_conflict_checks',
			];
		}
		if($fields_by_category['retry_fields']!==[] || $fields_by_category['delivery_state_fields']!==[] || in_array('webhook_or_delivery', $task_signals, true) || in_array('background_jobs', $task_signals, true)){
			$controls[]=[
				'id'=>'retry_delivery_policy',
				'fields'=>array_values(array_merge($fields_by_category['retry_fields'], $fields_by_category['delivery_state_fields'], $fields_by_category['queue_job_fields'])),
				'policy'=>'track_attempts_next_retry_terminal_failure_and_dead_letter_or_support_handoff_in_app_owned_adapters',
				'verification_focus'=>'retry_backoff_terminal_failure_and_no_duplicate_dispatch_checks',
			];
		}
		if($fields_by_category['import_export_fields']!==[] || in_array('import_export', $task_signals, true)){
			$controls[]=[
				'id'=>'import_export_reconciliation_policy',
				'fields'=>$fields_by_category['import_export_fields'],
				'policy'=>'store_import_export_batches_request_hashes_errors_and_reconciliation_evidence_in_app_owned_tables_or_adapters',
				'verification_focus'=>'batch_replay_redaction_reconciliation_and_partial_failure_checks',
			];
		}
		if($fields_by_category['external_reference_fields']!==[] || $relationship_targets!==[] || in_array('external_side_effects', $task_signals, true)){
			$controls[]=[
				'id'=>'external_reference_reconciliation_policy',
				'fields'=>array_values(array_merge($fields_by_category['external_reference_fields'], $relationship_targets)),
				'policy'=>'treat_provider_ids_event_ids_and_remote_status_as_adapter_owned_reconciliation_state',
				'verification_focus'=>'provider_reference_scope_status_and_replay_checks',
			];
		}
		$has_reliability_work=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_operational_reliability_signals'=>$has_reliability_work,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'relationship_targets'=>$relationship_targets,
			'controls'=>$controls,
			'policy'=>$has_reliability_work ? 'Treat webhooks, jobs, imports/exports, external side effects, retries, idempotency keys, request hashes, and provider references as app-owned reliability policy with focused replay/failure checks.' : 'No operational reliability signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal queue/outbox engine edits for one app',
				'enterprise governance audit for ordinary app-owned operational reliability fields unless the task escalates',
				'MCP/release-surface validation for ordinary app reliability hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for idempotency, retries, and side effects.
	 *
	 * @param array<string,mixed> $operational_reliability_summary Operational reliability metadata.
	 * @return array<string,mixed> App-owned reliability handoff.
	 */
	private function app_builder_operational_reliability_handoff(array $operational_reliability_summary): array {
		$controls=is_array($operational_reliability_summary['controls'] ?? null) ? $operational_reliability_summary['controls'] : [];
		$operations=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$fields=[];
			foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$fields[]=[
					'entity'=>(string)($field['entity'] ?? ''),
					'table'=>(string)($field['table'] ?? ''),
					'field'=>(string)($field['field'] ?? ''),
					'target_entity'=>(string)($field['target_entity'] ?? ''),
				];
			}
			$operations[]=[
				'id'=>$id,
				'fields'=>$fields,
				'operation_key'=>$this->app_builder_operational_reliability_operation_key($id),
				'side_effect_order'=>$this->app_builder_operational_reliability_side_effect_order($id),
				'failure_paths'=>$this->app_builder_operational_reliability_failure_paths($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$operations===[] ? 'not_triggered' : 'ready_for_app_owned_reliability_design',
			'purpose'=>'Concrete app-owned idempotency, retry, queue/outbox, import/export, and external side-effect handoff.',
			'operation_count'=>count($operations),
			'operations'=>$operations,
			'default_write_order'=>[
				'derive_scope_and_actor_context',
				'reserve_idempotency_or_operation_key_before_external_side_effect',
				'persist_request_hash_or_payload_fingerprint_when available',
				'perform_app_owned_side_effect_or_enqueue_work',
				'persist terminal success/failure/retry state with copy-safe error summary',
			],
			'links'=>[
				'operational_reliability_summary'=>'builder_response.operational_reliability_summary',
				'data_integrity_summary'=>'builder_response.data_integrity_summary',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'verification_fixture_handoff'=>'builder_response.verification_fixture_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$operations===[] ? 'No app-owned operational reliability operations were inferred.' : 'Keep retries, idempotency, queue/outbox, import/export, and external side effects in app-owned repositories, callbacks, dialbacks, plugins, workers, or adapters.',
			'not_required'=>[
				'Dataphyre runtime-internal queue/outbox engine edits for one app',
				'enterprise governance audit for ordinary app-owned operational reliability fields unless the task escalates',
				'MCP/release-surface validation for ordinary app reliability design',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Returns the app-owned operation key strategy for a reliability control.
	 */
	private function app_builder_operational_reliability_operation_key(string $control_id): string {
		return match($control_id){
			'idempotency_replay_policy'=>'scope plus idempotency_key, with request_hash when present',
			'retry_delivery_policy'=>'delivery/job id plus scope, attempt count, next_retry_at, and terminal status',
			'import_export_reconciliation_policy'=>'batch id plus scope, source/destination reference, request hash, and reconciliation status',
			'external_reference_reconciliation_policy'=>'provider/event/message/remote id plus provider scope and local owner/scope',
			default=>'app-owned stable operation id plus tenant/workspace scope',
		};
	}

	/**
	 * Returns the safe ordering for side-effect handling.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_operational_reliability_side_effect_order(string $control_id): array {
		return match($control_id){
			'idempotency_replay_policy'=>['reserve_key_before_side_effect', 'same_key_same_payload_returns_prior_result', 'same_key_changed_payload_rejected_before_side_effect'],
			'retry_delivery_policy'=>['claim_or_lock_work_item', 'increment_attempt_before_dispatch', 'schedule_next_retry_or_mark_terminal_failure', 'avoid_duplicate_dispatch_after_success'],
			'import_export_reconciliation_policy'=>['record_batch_scope_before_processing', 'store row/error summaries copy-safely', 'make replay idempotent', 'reconcile partial failures before marking complete'],
			'external_reference_reconciliation_policy'=>['resolve provider reference through app adapter', 'check scope before local link or update', 'persist remote status and reconciliation evidence', 'avoid duplicate local records'],
			default=>['derive_scope', 'reserve_operation', 'perform_side_effect', 'persist_outcome'],
		};
	}

	/**
	 * Returns focused failure paths an app agent should verify.
	 *
	 * @return array<int,string> Failure checks.
	 */
	private function app_builder_operational_reliability_failure_paths(string $control_id): array {
		return match($control_id){
			'idempotency_replay_policy'=>['duplicate_same_payload_replays_without_second_side_effect', 'duplicate_changed_payload_conflict', 'same_key_cross_scope_rejected_or_isolated'],
			'retry_delivery_policy'=>['transient_failure_schedules_retry', 'terminal_failure_stops_dispatch_and_records_safe_error', 'successful_delivery_not_retried_or_duplicated', 'stale_lock_or_claim_handled'],
			'import_export_reconciliation_policy'=>['partial_failure_reports_copy_safe_errors', 'replay_does_not_duplicate_rows_or_exports', 'redaction_and_scope_enforced_for_export'],
			'external_reference_reconciliation_policy'=>['missing_provider_reference_safe_empty_state', 'provider_reference_cross_scope_rejected', 'remote_status_conflict_requires_app_owned_reconciliation'],
			default=>['duplicate_operation_rejected_or_replayed', 'failed_side_effect_records_safe_state', 'cross_scope_operation_rejected'],
		};
	}

	/**
	 * Extracts operational reliability signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Reliability signal ids.
	 */
	private function app_builder_operational_reliability_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'webhook_or_delivery'=>['webhook', 'webhooks', 'callback delivery', 'delivery adapter', 'notification delivery'],
			'background_jobs'=>['job', 'jobs', 'queue', 'queued', 'background', 'worker', 'schedule', 'scheduled'],
			'idempotent_side_effects'=>['idempotency', 'idempotent', 'idempotency key', 'replay', 'duplicate submission'],
			'import_export'=>['import', 'imports', 'export', 'exports', 'csv', 'spreadsheet', 'reconciliation'],
			'external_side_effects'=>['payment', 'payments', 'provider', 'adapter', 'erp', 'external service', 'api key', 'remote'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry operational reliability semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_operational_reliability_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'webhook')=>'webhook_or_delivery',
			str_contains($key, 'job') || str_contains($key, 'queue') || str_contains($key, 'outbox')=>'background_jobs',
			str_contains($key, 'import') || str_contains($key, 'export')=>'import_export',
			str_contains($key, 'payment') || str_contains($key, 'provider') || str_contains($key, 'adapter')=>'external_side_effects',
			str_contains($key, 'event')=>'external_side_effects',
			default=>'',
		};
	}

	/**
	 * Classifies reliability-relevant field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_operational_reliability_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['idempotency_key', 'dedupe_key', 'replay_key'], true)=>'idempotency_fields',
			in_array($name, ['request_hash', 'payload_hash', 'body_hash', 'canonical_hash'], true)=>'request_hash_fields',
			in_array($name, ['retry_count', 'attempt_count', 'attempts', 'next_retry_at', 'last_attempt_at'], true)=>'retry_fields',
			in_array($name, ['queued_at', 'processed_at', 'delivered_at', 'failed_at', 'dead_lettered_at', 'last_error', 'status'], true)=>'delivery_state_fields',
			in_array($name, ['import_batch_id', 'export_batch_id', 'imported_at', 'exported_at', 'source_file_id', 'destination_ref'], true)=>'import_export_fields',
			in_array($name, ['provider_reference', 'external_id', 'event_id', 'message_id', 'remote_id', 'remote_status', 'tracking_number'], true)=>'external_reference_fields',
			in_array($name, ['job_id', 'queue_name', 'worker_id', 'scheduled_at', 'run_at', 'locked_at'], true)=>'queue_job_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one reliability category.
	 *
	 * @param string $category Reliability category key.
	 * @return string Policy token.
	 */
	private function app_builder_operational_reliability_category_policy(string $category): string {
		return match($category){
			'idempotency_fields'=>'reserve_scoped_key_before_side_effect_and_replay_same_payload_response',
			'request_hash_fields'=>'reject_same_key_changed_payload_before_side_effect',
			'retry_fields'=>'track_attempt_count_next_retry_and_terminal_failure',
			'delivery_state_fields'=>'persist_delivery_state_and_failure_reason_for_support_handoff',
			'import_export_fields'=>'record_batch_scope_redaction_errors_and_reconciliation_evidence',
			'external_reference_fields'=>'resolve_provider_reference_through_app_owned_adapter_and_scope_checks',
			'queue_job_fields'=>'claim_jobs_with_scope_locking_and_idempotent_completion',
			'webhook_or_delivery'=>'sign_verify_redact_and_retry_webhook_delivery_in_app_owned_adapter',
			'background_jobs'=>'keep_job_claim_retry_and_dead_letter_policy_in_app_owned_worker_or_adapter',
			'import_export'=>'keep_import_export_parsing_redaction_replay_and_reconciliation_in_app_owned_adapter',
			'external_side_effects'=>'wrap_external_side_effects_with_idempotency_request_hash_and_reconciliation',
			default=>'decide_app_owned_operational_reliability_policy',
		};
	}

	/**
	 * Returns the focused verification target for one reliability category.
	 *
	 * @param string $category Reliability category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_operational_reliability_verification_focus(string $category): string {
		return match($category){
			'idempotency_fields'=>'same_key_same_payload_replay_and_changed_payload_conflict_checks',
			'request_hash_fields'=>'request_hash_conflict_checks',
			'retry_fields'=>'retry_backoff_and_terminal_failure_checks',
			'delivery_state_fields'=>'delivery_success_failure_and_support_handoff_checks',
			'import_export_fields'=>'batch_replay_redaction_and_partial_failure_checks',
			'external_reference_fields'=>'provider_reference_scope_and_reconciliation_checks',
			'queue_job_fields'=>'job_claim_lock_retry_and_idempotent_completion_checks',
			default=>'focused_operational_reliability_checks',
		};
	}

	/**
	 * Builds compact app-owned support and observability guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Support and observability guidance.
	 */
	private function app_builder_support_observability_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_support_observability_task_signals($task);
		$fields_by_category=[
			'incident_fields'=>[],
			'support_case_fields'=>[],
			'severity_priority_fields'=>[],
			'health_status_fields'=>[],
			'diagnostic_evidence_fields'=>[],
			'alert_notification_fields'=>[],
			'sla_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_support_observability_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_support_observability_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_support_observability_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_support_observability_category_policy($category),
					'verification_focus'=>$this->app_builder_support_observability_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['incident_fields']!==[] || $fields_by_category['support_case_fields']!==[] || in_array('support_or_incidents', $task_signals, true)){
			$controls[]=[
				'id'=>'support_incident_workflow_policy',
				'fields'=>array_values(array_merge($fields_by_category['incident_fields'], $fields_by_category['support_case_fields'])),
				'policy'=>'define_app_owned_triage_assignment_escalation_and_resolution_states',
				'verification_focus'=>'support_case_triage_assignment_escalation_and_resolution_checks',
			];
		}
		if($fields_by_category['severity_priority_fields']!==[] || $fields_by_category['sla_fields']!==[] || in_array('sla_or_escalation', $task_signals, true)){
			$controls[]=[
				'id'=>'severity_sla_escalation_policy',
				'fields'=>array_values(array_merge($fields_by_category['severity_priority_fields'], $fields_by_category['sla_fields'])),
				'policy'=>'map_severity_priority_due_dates_and_sla_breaches_to_app_owned_escalation_rules',
				'verification_focus'=>'severity_priority_sla_breach_and_escalation_checks',
			];
		}
		if($fields_by_category['health_status_fields']!==[] || in_array('health_monitoring', $task_signals, true)){
			$controls[]=[
				'id'=>'health_status_policy',
				'fields'=>$fields_by_category['health_status_fields'],
				'policy'=>'store_health_status_last_seen_and_degraded_reason_in_app_owned_monitoring_surfaces',
				'verification_focus'=>'healthy_degraded_failed_state_and_staleness_checks',
			];
		}
		if($fields_by_category['diagnostic_evidence_fields']!==[] || in_array('diagnostics_or_observability', $task_signals, true)){
			$controls[]=[
				'id'=>'copy_safe_diagnostic_evidence_policy',
				'fields'=>$fields_by_category['diagnostic_evidence_fields'],
				'policy'=>'store_redacted_diagnostic_summaries_and_copy_safe_evidence_without_raw_logs_or_secrets',
				'verification_focus'=>'copy_safe_evidence_redaction_and_external_share_review_checks',
			];
		}
		if($fields_by_category['alert_notification_fields']!==[] || in_array('alerts_or_notifications', $task_signals, true)){
			$controls[]=[
				'id'=>'alert_notification_policy',
				'fields'=>$fields_by_category['alert_notification_fields'],
				'policy'=>'define_app_owned_recipient_channel_suppression_and_acknowledgement_rules',
				'verification_focus'=>'alert_recipient_acknowledgement_suppression_and_redaction_checks',
			];
		}
		$has_support_work=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_support_observability_signals'=>$has_support_work,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_support_work ? 'Treat support tickets, incidents, alerts, health status, diagnostics, SLA/escalation, and copy-safe evidence as app-owned operability policy with focused support and redaction checks.' : 'No support or observability signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal observability or incident engine edits for one app',
				'raw log sharing in app-agent handoffs',
				'enterprise governance audit for ordinary app-owned support fields unless the task escalates',
				'MCP/release-surface validation for ordinary app supportability hints',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for support, diagnostics, and runbook work.
	 *
	 * @param array<string,mixed> $support_observability_summary Support/observability metadata.
	 * @return array<string,mixed> App-owned supportability handoff.
	 */
	private function app_builder_support_observability_handoff(array $support_observability_summary): array {
		$controls=is_array($support_observability_summary['controls'] ?? null) ? $support_observability_summary['controls'] : [];
		$workflows=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$fields=[];
			foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$fields[]=[
					'entity'=>(string)($field['entity'] ?? ''),
					'table'=>(string)($field['table'] ?? ''),
					'field'=>(string)($field['field'] ?? ''),
				];
			}
			$workflows[]=[
				'id'=>$id,
				'fields'=>$fields,
				'workflow'=>$this->app_builder_support_observability_workflow($id),
				'copy_safe_handoff'=>$this->app_builder_support_observability_copy_safe_handoff($id),
				'negative_checks'=>$this->app_builder_support_observability_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$workflows===[] ? 'not_triggered' : 'ready_for_app_owned_supportability_design',
			'purpose'=>'Concrete app-owned support, incident, health, alert, runbook, and copy-safe diagnostic handoff.',
			'workflow_count'=>count($workflows),
			'workflows'=>$workflows,
			'default_copy_safe_shape'=>[
				'handoff_status'=>'copy_safe_summary_ready',
				'fields'=>['summary', 'status', 'severity_or_priority', 'scope', 'trace_or_correlation_id', 'copy_safe_evidence', 'next_action'],
				'not_included'=>['raw logs', 'secrets or tokens', 'tenant/customer names unless app policy permits', 'machine-local absolute paths', 'unredacted stack traces'],
			],
			'links'=>[
				'support_observability_summary'=>'builder_response.support_observability_summary',
				'diagnostic_handoff_hint'=>'builder_response.diagnostic_handoff_hint',
				'verification_handoff'=>'builder_response.verification_handoff',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$workflows===[] ? 'No app-owned supportability workflows were inferred.' : 'Keep support triage, runbooks, status, alerts, health, and diagnostic evidence in app-owned records, callbacks, dialbacks, plugins, or adapters with copy-safe handoffs.',
			'not_required'=>[
				'Dataphyre runtime-internal observability or incident engine edits for one app',
				'raw log sharing in app-agent handoffs',
				'enterprise governance audit for ordinary app-owned support fields unless the task escalates',
				'MCP/release-surface validation for ordinary app supportability design',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Returns app-owned workflow steps for one supportability control.
	 *
	 * @return array<int,string> Workflow steps.
	 */
	private function app_builder_support_observability_workflow(string $control_id): array {
		return match($control_id){
			'support_incident_workflow_policy'=>['capture_request_or_incident_scope', 'triage_severity_owner_and_visibility', 'assign_or_escalate', 'record_resolution_and_customer_safe_summary'],
			'severity_sla_escalation_policy'=>['derive_severity_priority', 'calculate_sla_due_at', 'detect_breach', 'trigger_app_owned_escalation_or_runbook'],
			'health_status_policy'=>['record_health_signal', 'detect_stale_or_degraded_state', 'publish_customer_safe_status_when_app_policy_allows', 'record_recovery_evidence'],
			'copy_safe_diagnostic_evidence_policy'=>['collect_trace_or_correlation_id', 'redact_raw_error_and_sensitive_context', 'store_copy_safe_evidence', 'pair_failed_checks_with_diagnostic_handoff_hint'],
			'alert_notification_policy'=>['resolve_recipients_and_channels', 'apply_suppression_or_dedupe_rules', 'record_acknowledgement', 'redact_alert_payload_for_handoff'],
			default=>['capture_support_context', 'apply_app_owned_visibility', 'record_copy_safe_next_action'],
		};
	}

	/**
	 * Returns copy-safe handoff fields for one supportability control.
	 *
	 * @return array<int,string> Copy-safe fields.
	 */
	private function app_builder_support_observability_copy_safe_handoff(string $control_id): array {
		return match($control_id){
			'copy_safe_diagnostic_evidence_policy'=>['diagnostic_summary', 'copy_safe_evidence', 'trace_id_or_correlation_id', 'redacted_error_code', 'diagnostic_next_action'],
			'support_incident_workflow_policy'=>['incident_or_case_number', 'customer_safe_summary', 'status', 'assignee_or_owner', 'next_action'],
			'health_status_policy'=>['health_status', 'degraded_reason_summary', 'last_seen_at', 'recovery_evidence_summary'],
			'severity_sla_escalation_policy'=>['severity_or_priority', 'due_at', 'breached_at', 'escalated_at', 'runbook_or_escalation_action'],
			'alert_notification_policy'=>['alert_status', 'recipient_scope', 'channel', 'acknowledged_at', 'suppression_reason'],
			default=>['summary', 'status', 'copy_safe_evidence', 'next_action'],
		};
	}

	/**
	 * Returns negative checks an app agent should verify for supportability work.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_support_observability_negative_checks(string $control_id): array {
		return match($control_id){
			'support_incident_workflow_policy'=>['out_of_scope_incident_hidden_or_rejected', 'unauthorized_assignment_denied', 'customer_safe_summary_omits_sensitive_details'],
			'severity_sla_escalation_policy'=>['breach_without_permission_cannot_be_suppressed', 'escalation_runs_once_per_threshold', 'invalid_due_at_or_resolution_state_rejected'],
			'health_status_policy'=>['stale_heartbeat_marks_degraded_or_unknown', 'raw_internal_error_not_published_to_status_page', 'recovery_requires_evidence_or_status_transition'],
			'copy_safe_diagnostic_evidence_policy'=>['raw_logs_not_included', 'secrets_tokens_paths_and_tenant_identifiers_redacted', 'diagnostic_handoff_only_after_focused_check_failure'],
			'alert_notification_policy'=>['recipient_out_of_scope_not_notified', 'suppressed_alert_not_duplicated', 'alert_payload_omits_sensitive_diagnostics'],
			default=>['raw_sensitive_context_not_shared', 'unauthorized_support_record_denied', 'copy_safe_evidence_present_for_external_handoff'],
		};
	}

	/**
	 * Extracts support and observability signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_support_observability_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'support_or_incidents'=>['support', 'ticket', 'tickets', 'incident', 'incidents', 'case', 'cases'],
			'sla_or_escalation'=>['sla', 'escalation', 'escalate', 'breach', 'breached'],
			'health_monitoring'=>['health', 'heartbeat', 'uptime', 'status page', 'monitoring'],
			'diagnostics_or_observability'=>['diagnostic', 'diagnostics', 'observability', 'trace', 'tracelog', 'logs', 'evidence'],
			'alerts_or_notifications'=>['alert', 'alerts', 'notification', 'notifications', 'reminder'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry support or observability semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_support_observability_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'incident')=>'support_or_incidents',
			str_contains($key, 'support') || str_contains($key, 'ticket') || str_contains($key, 'case')=>'support_or_incidents',
			str_contains($key, 'alert') || str_contains($key, 'notification')=>'alerts_or_notifications',
			str_contains($key, 'health') || str_contains($key, 'heartbeat')=>'health_monitoring',
			str_contains($key, 'diagnostic') || str_contains($key, 'trace') || str_contains($key, 'log')=>'diagnostics_or_observability',
			str_contains($key, 'sla') || str_contains($key, 'escalation')=>'sla_or_escalation',
			default=>'',
		};
	}

	/**
	 * Classifies support and observability field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_support_observability_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['incident_id', 'incident_number', 'impacted_service', 'affected_customer_id'], true)=>'incident_fields',
			in_array($name, ['support_ticket_id', 'ticket_number', 'case_number', 'requester_id', 'assignee_id'], true)=>'support_case_fields',
			in_array($name, ['severity', 'priority', 'impact', 'urgency'], true)=>'severity_priority_fields',
			in_array($name, ['health_status', 'heartbeat_at', 'last_seen_at', 'degraded_reason', 'uptime_status'], true)=>'health_status_fields',
			in_array($name, ['diagnostic_summary', 'copy_safe_evidence', 'error_code', 'error_message', 'trace_id', 'correlation_id', 'log_ref'], true)=>'diagnostic_evidence_fields',
			in_array($name, ['alert_status', 'alerted_at', 'acknowledged_at', 'notified_at', 'recipient_id', 'notification_channel'], true)=>'alert_notification_fields',
			in_array($name, ['sla_policy_id', 'due_at', 'breached_at', 'resolved_at', 'escalated_at'], true)=>'sla_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one support/observability category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_support_observability_category_policy(string $category): string {
		return match($category){
			'incident_fields'=>'define_incident_scope_impact_status_and_customer_safe_summary',
			'support_case_fields'=>'define_support_triage_assignment_visibility_and_resolution_policy',
			'severity_priority_fields'=>'map_severity_priority_to_escalation_and_dashboard_filters',
			'health_status_fields'=>'track_health_staleness_degraded_reason_and_recovery_evidence',
			'diagnostic_evidence_fields'=>'store_copy_safe_redacted_diagnostic_evidence_not_raw_logs',
			'alert_notification_fields'=>'gate_alert_recipients_acknowledgement_suppression_and_redaction',
			'sla_fields'=>'define_sla_due_breach_resolution_and_escalation_policy',
			'support_or_incidents'=>'keep_support_triage_incident_status_and_resolution_in_app_owned_policy',
			'sla_or_escalation'=>'keep_sla_escalation_rules_in_app_owned_policy_or_adapter',
			'health_monitoring'=>'keep_health_status_and_staleness_checks_in_app_owned_monitoring_surfaces',
			'diagnostics_or_observability'=>'use_copy_safe_diagnostic_evidence_and_redacted_handoffs',
			'alerts_or_notifications'=>'keep_alert_delivery_recipient_and_acknowledgement_policy_app_owned',
			default=>'decide_app_owned_support_observability_policy',
		};
	}

	/**
	 * Returns the focused verification target for one support/observability category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_support_observability_verification_focus(string $category): string {
		return match($category){
			'incident_fields'=>'incident_scope_status_and_customer_safe_summary_checks',
			'support_case_fields'=>'support_triage_assignment_visibility_and_resolution_checks',
			'severity_priority_fields'=>'severity_priority_filter_and_escalation_checks',
			'health_status_fields'=>'health_staleness_degraded_recovery_checks',
			'diagnostic_evidence_fields'=>'copy_safe_evidence_redaction_checks',
			'alert_notification_fields'=>'alert_recipient_acknowledgement_and_suppression_checks',
			'sla_fields'=>'sla_due_breach_resolution_and_escalation_checks',
			default=>'focused_support_observability_checks',
		};
	}

	/**
	 * Builds compact app-owned change-management guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Change-management guidance.
	 */
	private function app_builder_change_management_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_change_management_task_signals($task);
		$fields_by_category=[
			'feature_flag_fields'=>[],
			'rollout_fields'=>[],
			'migration_fields'=>[],
			'rollback_fields'=>[],
			'version_fields'=>[],
			'compatibility_fields'=>[],
			'approval_change_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_change_management_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_change_management_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_change_management_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_change_management_category_policy($category),
					'verification_focus'=>$this->app_builder_change_management_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['feature_flag_fields']!==[] || in_array('feature_flags', $task_signals, true)){
			$controls[]=[
				'id'=>'feature_flag_rollout_policy',
				'fields'=>$fields_by_category['feature_flag_fields'],
				'policy'=>'define_app_owned_flag_targeting_defaults_and_safe_disable_behavior',
				'verification_focus'=>'flag_default_targeting_enable_disable_and_scope_checks',
			];
		}
		if($fields_by_category['rollout_fields']!==[] || in_array('rollouts', $task_signals, true)){
			$controls[]=[
				'id'=>'rollout_wave_policy',
				'fields'=>$fields_by_category['rollout_fields'],
				'policy'=>'track_wave_stage_target_scope_blockers_and_promotion_criteria_in_app_owned_records',
				'verification_focus'=>'rollout_wave_blocker_promotion_and_fallback_checks',
			];
		}
		if($fields_by_category['migration_fields']!==[] || in_array('migrations_or_backfills', $task_signals, true)){
			$controls[]=[
				'id'=>'migration_backfill_policy',
				'fields'=>$fields_by_category['migration_fields'],
				'policy'=>'record_source_target_counts_dry_run_results_backfill_status_and_error_samples_in_app_owned_tables_or_adapters',
				'verification_focus'=>'dry_run_backfill_counts_partial_failure_and_idempotent_resume_checks',
			];
		}
		if($fields_by_category['rollback_fields']!==[] || in_array('rollback_or_recovery', $task_signals, true)){
			$controls[]=[
				'id'=>'rollback_evidence_policy',
				'fields'=>$fields_by_category['rollback_fields'],
				'policy'=>'capture_rollback_reason_previous_state_restore_target_and_operator_evidence_before_recovery_actions',
				'verification_focus'=>'rollback_restore_evidence_and_no_unrelated_revert_checks',
			];
		}
		if($fields_by_category['version_fields']!==[] || $fields_by_category['compatibility_fields']!==[] || in_array('versioning_or_compatibility', $task_signals, true)){
			$controls[]=[
				'id'=>'version_compatibility_policy',
				'fields'=>array_values(array_merge($fields_by_category['version_fields'], $fields_by_category['compatibility_fields'])),
				'policy'=>'track_schema_api_contract_adapter_versions_and_backward_compatibility_windows_in_app_owned_policy',
				'verification_focus'=>'version_backward_compatibility_and_deprecation_window_checks',
			];
		}
		if($fields_by_category['approval_change_fields']!==[] || in_array('change_approval', $task_signals, true)){
			$controls[]=[
				'id'=>'change_approval_policy',
				'fields'=>$fields_by_category['approval_change_fields'],
				'policy'=>'permission_gate_change_approval_and_record_actor_decision_effective_window',
				'verification_focus'=>'approval_actor_effective_window_and_denied_change_checks',
			];
		}
		$has_change_work=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_change_management_signals'=>$has_change_work,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_change_work ? 'Treat feature flags, rollout waves, migrations/backfills, rollback evidence, versioning, compatibility, and change approval as app-owned change-management policy with focused rollout/recovery checks.' : 'No change-management signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal deployment or migration engine edits for one app',
				'package release validation for ordinary app-owned rollout fields',
				'enterprise governance audit for ordinary app-owned change fields unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for rollout, migration, and rollback work.
	 *
	 * @param array<string,mixed> $change_management_summary Change-management metadata.
	 * @return array<string,mixed> App-owned change-management handoff.
	 */
	private function app_builder_change_management_handoff(array $change_management_summary): array {
		$controls=is_array($change_management_summary['controls'] ?? null) ? $change_management_summary['controls'] : [];
		$plans=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$fields=[];
			foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$fields[]=[
					'entity'=>(string)($field['entity'] ?? ''),
					'table'=>(string)($field['table'] ?? ''),
					'field'=>(string)($field['field'] ?? ''),
				];
			}
			$plans[]=[
				'id'=>$id,
				'fields'=>$fields,
				'decision_points'=>$this->app_builder_change_management_decision_points($id),
				'execution_order'=>$this->app_builder_change_management_execution_order($id),
				'negative_checks'=>$this->app_builder_change_management_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$plans===[] ? 'not_triggered' : 'ready_for_app_owned_change_design',
			'purpose'=>'Concrete app-owned feature flag, rollout, migration/backfill, rollback, compatibility, and change-approval handoff.',
			'plan_count'=>count($plans),
			'plans'=>$plans,
			'default_evidence_shape'=>[
				'fields'=>['change_id_or_key', 'actor_or_approver', 'scope', 'previous_state', 'target_state', 'verification_summary', 'rollback_or_recovery_note'],
				'not_included'=>['package release validation output', 'Dataphyre runtime deployment internals', 'unbounded diffs or raw logs'],
			],
			'links'=>[
				'change_management_summary'=>'builder_response.change_management_summary',
				'data_integrity_summary'=>'builder_response.data_integrity_summary',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'verification_handoff'=>'builder_response.verification_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$plans===[] ? 'No app-owned change-management plans were inferred.' : 'Keep flags, rollout waves, migrations/backfills, rollback evidence, compatibility windows, and approvals in app-owned records, callbacks, dialbacks, plugins, or adapters.',
			'not_required'=>[
				'Dataphyre runtime-internal deployment or migration engine edits for one app',
				'package release validation for ordinary app-owned rollout fields',
				'enterprise governance audit for ordinary app-owned change fields unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Returns the app decisions needed for one change-management control.
	 *
	 * @return array<int,string> Decision points.
	 */
	private function app_builder_change_management_decision_points(string $control_id): array {
		return match($control_id){
			'feature_flag_rollout_policy'=>['default_enabled_value', 'targeting_scope', 'safe_disable_behavior', 'override_permissions'],
			'rollout_wave_policy'=>['wave_order', 'promotion_criteria', 'blocker_policy', 'fallback_or_pause_rule'],
			'migration_backfill_policy'=>['dry_run_required', 'source_target_count_reconciliation', 'resume_key', 'partial_failure_policy'],
			'rollback_evidence_policy'=>['restore_point_source', 'previous_state_capture', 'rollback_actor_permission', 'unrelated_change_protection'],
			'version_compatibility_policy'=>['minimum_supported_version', 'deprecation_window', 'sunset_behavior', 'adapter_contract_compatibility'],
			'change_approval_policy'=>['approval_actor_policy', 'effective_window', 'denied_change_behavior', 'audit_or_reason_required'],
			default=>['scope', 'actor', 'target_state', 'verification_evidence'],
		};
	}

	/**
	 * Returns the safe execution order for one change-management control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_change_management_execution_order(string $control_id): array {
		return match($control_id){
			'feature_flag_rollout_policy'=>['read_default_and_targeting_rules', 'evaluate_actor_and_scope', 'apply_safe_enable_or_disable', 'record_flag_decision_evidence'],
			'rollout_wave_policy'=>['confirm_current_wave_state', 'verify_promotion_criteria', 'check_blockers', 'promote_pause_or_fallback_with_evidence'],
			'migration_backfill_policy'=>['run_or_record_dry_run', 'compare_source_and_target_counts', 'process_idempotent_batch_or_resume_key', 'record_partial_failures_and_reconciliation'],
			'rollback_evidence_policy'=>['capture_previous_state_and_restore_target', 'verify_rollback_actor_permission', 'restore_only_targeted_state', 'record_recovery_evidence'],
			'version_compatibility_policy'=>['check_version_window', 'apply_backward_compatibility_rule', 'record_deprecation_or_sunset_status', 'reject_out_of_window_changes'],
			'change_approval_policy'=>['collect_change_request', 'permission_gate_approver', 'apply_effective_window', 'record_approval_or_denial_reason'],
			default=>['confirm_scope', 'apply_change', 'record_evidence'],
		};
	}

	/**
	 * Returns negative checks for one change-management control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_change_management_negative_checks(string $control_id): array {
		return match($control_id){
			'feature_flag_rollout_policy'=>['out_of_scope_actor_cannot_override_flag', 'disabled_flag_blocks_dependent_behavior', 'unsafe_default_requires_explicit_app_decision'],
			'rollout_wave_policy'=>['blocked_wave_cannot_promote', 'missing_promotion_evidence_rejected', 'fallback_does_not_affect_unrelated_scope'],
			'migration_backfill_policy'=>['changed_payload_resume_conflict', 'source_target_count_mismatch_requires_reconciliation', 'partial_failure_does_not_mark_complete'],
			'rollback_evidence_policy'=>['rollback_without_restore_point_rejected', 'rollback_does_not_revert_unrelated_changes', 'rollback_actor_permission_checked'],
			'version_compatibility_policy'=>['deprecated_version_warns_or_denies_by_policy', 'sunset_version_rejected', 'adapter_contract_mismatch_safe_failure'],
			'change_approval_policy'=>['unapproved_change_denied', 'expired_effective_window_rejected', 'approver_cannot_approve_out_of_scope_change'],
			default=>['unauthorized_change_denied', 'missing_evidence_rejected'],
		};
	}

	/**
	 * Extracts change-management signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_change_management_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'feature_flags'=>['feature flag', 'feature flags', 'flag', 'flags', 'entitlement flag'],
			'rollouts'=>['rollout', 'rollouts', 'wave', 'waves', 'pilot', 'gradual release'],
			'migrations_or_backfills'=>['migration', 'migrations', 'backfill', 'backfills', 'data import', 'legacy import'],
			'rollback_or_recovery'=>['rollback', 'roll back', 'recovery', 'restore', 'fallback'],
			'versioning_or_compatibility'=>['version', 'versioning', 'compatibility', 'backward compatible', 'deprecation'],
			'change_approval'=>['change request', 'approval', 'approved_by', 'review'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry change-management semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_change_management_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'feature_flag') || str_contains($key, 'flag')=>'feature_flags',
			str_contains($key, 'rollout') || str_contains($key, 'wave') || str_contains($key, 'pilot')=>'rollouts',
			str_contains($key, 'migration') || str_contains($key, 'backfill')=>'migrations_or_backfills',
			str_contains($key, 'rollback') || str_contains($key, 'recovery')=>'rollback_or_recovery',
			str_contains($key, 'version') || str_contains($key, 'compat') || str_contains($key, 'deprecation')=>'versioning_or_compatibility',
			str_contains($key, 'change_request') || str_contains($key, 'approval')=>'change_approval',
			default=>'',
		};
	}

	/**
	 * Classifies change-management field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_change_management_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['flag_key', 'feature_flag_id', 'enabled', 'targeting_rules', 'default_enabled'], true)=>'feature_flag_fields',
			in_array($name, ['rollout_wave', 'wave_number', 'rollout_stage', 'pilot_group', 'target_scope', 'blocker_reason'], true)=>'rollout_fields',
			in_array($name, ['migration_id', 'backfill_id', 'source_count', 'target_count', 'dry_run', 'dry_run_at', 'backfilled_at'], true)=>'migration_fields',
			in_array($name, ['rollback_reason', 'rollback_at', 'rollback_by', 'previous_state', 'restore_point_id'], true)=>'rollback_fields',
			in_array($name, ['version', 'schema_version', 'api_version', 'contract_version', 'adapter_version'], true)=>'version_fields',
			in_array($name, ['compatibility_window', 'deprecated_at', 'sunset_at', 'minimum_version', 'maximum_version'], true)=>'compatibility_fields',
			in_array($name, ['change_request_id', 'approved_by', 'approved_at', 'effective_at'], true)=>'approval_change_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one change-management category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_change_management_category_policy(string $category): string {
		return match($category){
			'feature_flag_fields'=>'define_flag_defaults_targeting_rules_and_safe_disable_behavior',
			'rollout_fields'=>'track_rollout_wave_stage_target_scope_blockers_and_promotion_criteria',
			'migration_fields'=>'record_dry_run_counts_backfill_state_errors_and_idempotent_resume_evidence',
			'rollback_fields'=>'capture_previous_state_restore_target_reason_actor_and_recovery_evidence',
			'version_fields'=>'track_schema_api_contract_or_adapter_version_in_app_owned_policy',
			'compatibility_fields'=>'define_backward_compatibility_deprecation_and_sunset_windows',
			'approval_change_fields'=>'permission_gate_change_approval_and_record_actor_effective_window',
			'feature_flags'=>'keep_feature_flag_targeting_and_defaults_app_owned',
			'rollouts'=>'keep_rollout_wave_promotion_and_blocker_policy_app_owned',
			'migrations_or_backfills'=>'keep_migration_backfill_dry_run_resume_and_reconciliation_app_owned',
			'rollback_or_recovery'=>'keep_rollback_restore_and_recovery_evidence_app_owned',
			'versioning_or_compatibility'=>'keep_version_compatibility_and_deprecation_policy_app_owned',
			'change_approval'=>'keep_change_approval_actor_and_effective_window_app_owned',
			default=>'decide_app_owned_change_management_policy',
		};
	}

	/**
	 * Returns the focused verification target for one change-management category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_change_management_verification_focus(string $category): string {
		return match($category){
			'feature_flag_fields'=>'flag_default_targeting_enable_disable_and_scope_checks',
			'rollout_fields'=>'rollout_wave_blocker_promotion_and_fallback_checks',
			'migration_fields'=>'dry_run_backfill_counts_partial_failure_and_idempotent_resume_checks',
			'rollback_fields'=>'rollback_restore_evidence_and_no_unrelated_revert_checks',
			'version_fields'=>'version_contract_and_adapter_compatibility_checks',
			'compatibility_fields'=>'deprecation_sunset_and_backward_compatibility_checks',
			'approval_change_fields'=>'approval_actor_effective_window_and_denied_change_checks',
			default=>'focused_change_management_checks',
		};
	}

	/**
	 * Builds compact app-owned integration-boundary guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Integration-boundary guidance.
	 */
	private function app_builder_integration_boundary_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_integration_boundary_task_signals($task);
		$fields_by_category=[
			'external_identity_fields'=>[],
			'provider_fields'=>[],
			'webhook_fields'=>[],
			'sync_state_fields'=>[],
			'idempotency_fields'=>[],
			'retry_dead_letter_fields'=>[],
			'credential_reference_fields'=>[],
			'reconciliation_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_integration_boundary_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_integration_boundary_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_integration_boundary_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_integration_boundary_category_policy($category),
					'verification_focus'=>$this->app_builder_integration_boundary_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['external_identity_fields']!==[] || in_array('external_identity', $task_signals, true)){
			$controls[]=[
				'id'=>'external_identity_mapping_policy',
				'fields'=>$fields_by_category['external_identity_fields'],
				'policy'=>'map_external_ids_with_provider_tenant_scope_and_local_ownership_in_app_tables',
				'verification_focus'=>'external_id_uniqueness_scope_and_missing_provider_checks',
			];
		}
		if($fields_by_category['provider_fields']!==[] || in_array('external_providers', $task_signals, true)){
			$controls[]=[
				'id'=>'provider_adapter_boundary_policy',
				'fields'=>$fields_by_category['provider_fields'],
				'policy'=>'keep_provider_routing_payload_mapping_and_remote_error_translation_in_app_owned_adapters',
				'verification_focus'=>'provider_adapter_selection_mapping_and_error_translation_checks',
			];
		}
		if($fields_by_category['webhook_fields']!==[] || in_array('webhooks', $task_signals, true)){
			$controls[]=[
				'id'=>'webhook_ingestion_policy',
				'fields'=>$fields_by_category['webhook_fields'],
				'policy'=>'record_webhook_event_ids_signature_state_received_time_and_processed_time_before_side_effects',
				'verification_focus'=>'webhook_signature_duplicate_ordering_and_replay_checks',
			];
		}
		if($fields_by_category['sync_state_fields']!==[] || in_array('sync_state', $task_signals, true)){
			$controls[]=[
				'id'=>'sync_state_policy',
				'fields'=>$fields_by_category['sync_state_fields'],
				'policy'=>'track_cursor_checkpoint_direction_status_and_last_success_for_resumable_syncs',
				'verification_focus'=>'sync_resume_cursor_staleness_and_partial_failure_checks',
			];
		}
		if($fields_by_category['idempotency_fields']!==[] || in_array('idempotency', $task_signals, true)){
			$controls[]=[
				'id'=>'idempotent_side_effect_policy',
				'fields'=>$fields_by_category['idempotency_fields'],
				'policy'=>'deduplicate_side_effects_with_idempotency_keys_request_hashes_and_stable_operation_ids',
				'verification_focus'=>'duplicate_request_same_result_and_conflicting_payload_checks',
			];
		}
		if($fields_by_category['retry_dead_letter_fields']!==[] || in_array('retry_or_dead_letter', $task_signals, true)){
			$controls[]=[
				'id'=>'retry_dead_letter_policy',
				'fields'=>$fields_by_category['retry_dead_letter_fields'],
				'policy'=>'track_attempts_next_retry_dead_letter_reason_and_manual_requeue_policy_in_app_records',
				'verification_focus'=>'retry_backoff_dead_letter_and_manual_requeue_checks',
			];
		}
		if($fields_by_category['credential_reference_fields']!==[] || in_array('credential_references', $task_signals, true)){
			$controls[]=[
				'id'=>'credential_reference_policy',
				'fields'=>$fields_by_category['credential_reference_fields'],
				'policy'=>'store_only_secret_references_token_handles_or_connection_ids_and_keep_secret_values_out_of_scaffolds',
				'verification_focus'=>'secret_reference_no_plaintext_and_scope_checks',
			];
		}
		if($fields_by_category['reconciliation_fields']!==[] || in_array('reconciliation', $task_signals, true)){
			$controls[]=[
				'id'=>'reconciliation_policy',
				'fields'=>$fields_by_category['reconciliation_fields'],
				'policy'=>'record_remote_counts_local_counts_drift_hashes_and_reconciliation_status_for_external_systems',
				'verification_focus'=>'reconciliation_drift_count_mismatch_and_resolution_checks',
			];
		}
		$has_integration_work=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_integration_boundary_signals'=>$has_integration_work,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_integration_work ? 'Treat external providers, webhooks, sync state, idempotency, retries/dead letters, credential references, and reconciliation as app-owned integration boundary policy with focused duplicate/replay/recovery checks.' : 'No integration-boundary signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal HTTP client, queue, or credential-store edits for one app',
				'plain secret values in generated scaffolds or agent handoffs',
				'package release validation for ordinary app-owned integration fields',
				'enterprise governance audit for ordinary app-owned webhook or sync fields unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for provider, webhook, sync, and credential boundaries.
	 *
	 * @param array<string,mixed> $integration_boundary_summary Integration-boundary metadata.
	 * @return array<string,mixed> App-owned integration handoff.
	 */
	private function app_builder_integration_boundary_handoff(array $integration_boundary_summary): array {
		$controls=is_array($integration_boundary_summary['controls'] ?? null) ? $integration_boundary_summary['controls'] : [];
		$boundaries=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$fields=[];
			foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$fields[]=[
					'entity'=>(string)($field['entity'] ?? ''),
					'table'=>(string)($field['table'] ?? ''),
					'field'=>(string)($field['field'] ?? ''),
				];
			}
			$boundaries[]=[
				'id'=>$id,
				'fields'=>$fields,
				'adapter_contract'=>$this->app_builder_integration_boundary_adapter_contract($id),
				'write_order'=>$this->app_builder_integration_boundary_write_order($id),
				'negative_checks'=>$this->app_builder_integration_boundary_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$boundaries===[] ? 'not_triggered' : 'ready_for_app_owned_integration_design',
			'purpose'=>'Concrete app-owned provider adapter, webhook ingestion, sync, credential-reference, idempotency, retry, and reconciliation handoff.',
			'boundary_count'=>count($boundaries),
			'boundaries'=>$boundaries,
			'default_secret_policy'=>[
				'store'=>'references, handles, connection ids, token ids, or vault object references only',
				'do_not_store'=>['plain secret values', 'auth headers', 'cookies', 'signed URLs', 'provider access tokens'],
				'resolve_with'=>'app-owned credential/connection adapter at use time',
			],
			'links'=>[
				'integration_boundary_summary'=>'builder_response.integration_boundary_summary',
				'operational_reliability_handoff'=>'builder_response.operational_reliability_handoff',
				'data_sensitivity_summary'=>'builder_response.data_sensitivity_summary',
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$boundaries===[] ? 'No app-owned integration boundaries were inferred.' : 'Keep provider routing, webhook validation, sync cursors, credential resolution, reconciliation, and external side effects in app-owned adapters or plugins.',
			'not_required'=>[
				'Dataphyre runtime-internal HTTP client, queue, or credential-store edits for one app',
				'plain secret values in generated scaffolds or agent handoffs',
				'package release validation for ordinary app-owned integration fields',
				'enterprise governance audit for ordinary app-owned webhook or sync fields unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Returns app-owned adapter contract notes for one integration control.
	 */
	private function app_builder_integration_boundary_adapter_contract(string $control_id): string {
		return match($control_id){
			'external_identity_mapping_policy'=>'map provider object ids with tenant/workspace scope and local ownership before lookup, create, or update',
			'provider_adapter_boundary_policy'=>'route provider selection, payload mapping, remote errors, and retries through an app-owned adapter',
			'webhook_ingestion_policy'=>'verify signature, dedupe event id, record receipt, and persist replay evidence before side effects',
			'sync_state_policy'=>'store cursor/checkpoint/status so syncs resume idempotently and stale cursors are visible',
			'idempotent_side_effect_policy'=>'reserve operation/idempotency key and request hash before calling remote systems',
			'retry_dead_letter_policy'=>'track attempts, backoff, dead-letter reason, and manual requeue policy in app records',
			'credential_reference_policy'=>'store only credential references and resolve secret material inside app-owned credential adapters',
			'reconciliation_policy'=>'record remote/local counts, drift hashes, and resolution status before declaring integration complete',
			default=>'keep external behavior behind app-owned integration adapters',
		};
	}

	/**
	 * Returns safe write order for one integration control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_integration_boundary_write_order(string $control_id): array {
		return match($control_id){
			'external_identity_mapping_policy'=>['derive_provider_and_scope', 'lookup_local_mapping', 'validate_uniqueness', 'create_or_update_mapping_with_owner'],
			'provider_adapter_boundary_policy'=>['select_provider_adapter', 'map_payload', 'translate_remote_error_copy_safely', 'persist_adapter_result'],
			'webhook_ingestion_policy'=>['record_receipt', 'verify_signature', 'dedupe_event_id_or_payload_hash', 'process_side_effect_after_validation'],
			'sync_state_policy'=>['load_checkpoint', 'claim_sync_scope', 'process_delta_or_page', 'persist_cursor_and_status'],
			'idempotent_side_effect_policy'=>['reserve_key', 'compare_request_hash', 'execute_once', 'return_or_record_prior_result'],
			'retry_dead_letter_policy'=>['claim_attempt', 'increment_attempt_count', 'schedule_retry_or_dead_letter', 'record_manual_requeue_policy'],
			'credential_reference_policy'=>['validate_reference_scope', 'resolve_secret_at_use_time', 'avoid_logging_secret_material', 'record_reference_only'],
			'reconciliation_policy'=>['collect_remote_and_local_counts', 'compare_drift_hash', 'record_resolution_or_exception', 'block_complete_until_reconciled'],
			default=>['derive_scope', 'call_adapter', 'record_result'],
		};
	}

	/**
	 * Returns negative checks for one integration control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_integration_boundary_negative_checks(string $control_id): array {
		return match($control_id){
			'external_identity_mapping_policy'=>['duplicate_external_id_in_scope_rejected', 'same_external_id_cross_scope_isolated', 'missing_provider_reference_safe_empty_state'],
			'provider_adapter_boundary_policy'=>['unknown_provider_rejected', 'remote_error_translated_without_secret_leak', 'provider_payload_mapping_missing_required_field_rejected'],
			'webhook_ingestion_policy'=>['invalid_signature_rejected_before_side_effect', 'duplicate_event_not_processed_twice', 'out_of_order_event_safe_replay_or_hold'],
			'sync_state_policy'=>['stale_cursor_detected', 'partial_failure_resumes_from_checkpoint', 'cross_scope_checkpoint_rejected'],
			'idempotent_side_effect_policy'=>['same_key_changed_payload_conflict', 'duplicate_same_payload_returns_prior_result', 'side_effect_not_repeated_after_success'],
			'retry_dead_letter_policy'=>['retry_limit_marks_dead_letter', 'manual_requeue_requires_permission', 'dead_letter_reason_copy_safe'],
			'credential_reference_policy'=>['plain_secret_value_rejected_or_redacted', 'credential_reference_cross_scope_denied', 'secret_material_not_returned_in_handoff'],
			'reconciliation_policy'=>['remote_local_count_mismatch_blocks_complete', 'drift_hash_mismatch_requires_resolution', 'reconciled_status_requires_evidence'],
			default=>['external_side_effect_scope_checked', 'secret_material_not_exposed'],
		};
	}

	/**
	 * Extracts integration-boundary signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_integration_boundary_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'external_identity'=>['external id', 'external ids', 'remote id', 'provider id', 'third-party id'],
			'external_providers'=>['provider', 'integration', 'third party', 'third-party', 'adapter', 'connector'],
			'webhooks'=>['webhook', 'webhooks', 'event ingestion', 'signature verification'],
			'sync_state'=>['sync', 'synchronization', 'cursor', 'checkpoint', 'delta import'],
			'idempotency'=>['idempotent', 'idempotency', 'dedupe', 'deduplicate', 'request hash'],
			'retry_or_dead_letter'=>['retry', 'retries', 'dead letter', 'dead-letter', 'requeue'],
			'credential_references'=>['token reference', 'secret reference', 'credential reference', 'connection id', 'oauth'],
			'reconciliation'=>['reconcile', 'reconciliation', 'drift', 'remote count', 'local count'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry integration-boundary semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_integration_boundary_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'webhook') || str_contains($key, 'event_ingestion')=>'webhooks',
			str_contains($key, 'sync') || str_contains($key, 'checkpoint') || str_contains($key, 'cursor')=>'sync_state',
			str_contains($key, 'integration') || str_contains($key, 'provider') || str_contains($key, 'connector') || str_contains($key, 'adapter')=>'external_providers',
			str_contains($key, 'reconciliation') || str_contains($key, 'drift')=>'reconciliation',
			str_contains($key, 'dead_letter') || str_contains($key, 'retry')=>'retry_or_dead_letter',
			default=>'',
		};
	}

	/**
	 * Classifies integration-boundary field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_integration_boundary_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['external_id', 'remote_id', 'provider_object_id', 'provider_customer_id', 'provider_account_id', 'source_object_id'], true)=>'external_identity_fields',
			in_array($name, ['provider', 'provider_name', 'provider_type', 'adapter_key', 'connector_key', 'integration_id', 'connection_id'], true)=>'provider_fields',
			in_array($name, ['webhook_event_id', 'webhook_signature', 'event_type', 'received_at', 'processed_at', 'payload_hash'], true)=>'webhook_fields',
			in_array($name, ['sync_cursor', 'sync_checkpoint', 'sync_status', 'last_synced_at', 'last_success_at', 'sync_direction'], true)=>'sync_state_fields',
			in_array($name, ['idempotency_key', 'request_hash', 'operation_id', 'dedupe_key'], true)=>'idempotency_fields',
			in_array($name, ['retry_count', 'attempt_count', 'next_retry_at', 'dead_letter_reason', 'last_error_code', 'last_error_at'], true)=>'retry_dead_letter_fields',
			in_array($name, ['credential_ref', 'secret_ref', 'token_ref', 'connection_ref', 'oauth_connection_id'], true)=>'credential_reference_fields',
			in_array($name, ['remote_count', 'local_count', 'drift_status', 'drift_hash', 'reconciled_at', 'reconciliation_status'], true)=>'reconciliation_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one integration-boundary category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_integration_boundary_category_policy(string $category): string {
		return match($category){
			'external_identity_fields'=>'scope_external_ids_by_provider_and_tenant_before_lookup_or_write',
			'provider_fields'=>'keep_provider_adapter_selection_mapping_and_error_translation_app_owned',
			'webhook_fields'=>'record_webhook_identity_signature_state_and_replay_evidence_before_side_effects',
			'sync_state_fields'=>'track_sync_cursor_checkpoint_status_and_resume_policy',
			'idempotency_fields'=>'deduplicate_side_effects_with_stable_keys_and_request_hashes',
			'retry_dead_letter_fields'=>'track_retry_attempts_backoff_dead_letter_reason_and_requeue_policy',
			'credential_reference_fields'=>'store_secret_references_not_secret_values_and_verify_scope',
			'reconciliation_fields'=>'track_remote_local_counts_drift_and_reconciliation_resolution',
			'external_identity'=>'keep_external_identity_mapping_app_owned',
			'external_providers'=>'keep_provider_adapters_and_payload_mapping_app_owned',
			'webhooks'=>'keep_webhook_ingestion_signature_replay_and_ordering_policy_app_owned',
			'sync_state'=>'keep_sync_resume_checkpoint_and_staleness_policy_app_owned',
			'idempotency'=>'keep_idempotent_side_effect_policy_app_owned',
			'retry_or_dead_letter'=>'keep_retry_dead_letter_and_manual_requeue_policy_app_owned',
			'credential_references'=>'keep_credential_references_and_secret_resolution_app_owned',
			'reconciliation'=>'keep_external_reconciliation_and_drift_policy_app_owned',
			default=>'decide_app_owned_integration_boundary_policy',
		};
	}

	/**
	 * Returns the focused verification target for one integration-boundary category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_integration_boundary_verification_focus(string $category): string {
		return match($category){
			'external_identity_fields'=>'external_id_uniqueness_scope_and_missing_provider_checks',
			'provider_fields'=>'provider_adapter_selection_mapping_and_error_translation_checks',
			'webhook_fields'=>'webhook_signature_duplicate_ordering_and_replay_checks',
			'sync_state_fields'=>'sync_resume_cursor_staleness_and_partial_failure_checks',
			'idempotency_fields'=>'duplicate_request_same_result_and_conflicting_payload_checks',
			'retry_dead_letter_fields'=>'retry_backoff_dead_letter_and_manual_requeue_checks',
			'credential_reference_fields'=>'secret_reference_no_plaintext_and_scope_checks',
			'reconciliation_fields'=>'reconciliation_drift_count_mismatch_and_resolution_checks',
			default=>'focused_integration_boundary_checks',
		};
	}

	/**
	 * Builds compact app-owned business-policy guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Business-policy guidance.
	 */
	private function app_builder_business_policy_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_business_policy_task_signals($task);
		$fields_by_category=[
			'entitlement_fields'=>[],
			'quota_limit_fields'=>[],
			'eligibility_fields'=>[],
			'approval_delegation_fields'=>[],
			'exception_waiver_fields'=>[],
			'contract_terms_fields'=>[],
			'commercial_policy_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_business_policy_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_business_policy_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_business_policy_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_business_policy_category_policy($category),
					'verification_focus'=>$this->app_builder_business_policy_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['entitlement_fields']!==[] || in_array('entitlements', $task_signals, true)){
			$controls[]=[
				'id'=>'entitlement_policy',
				'fields'=>$fields_by_category['entitlement_fields'],
				'policy'=>'model_entitlements_as_app_owned_plan_feature_scope_and_effective_window_rules',
				'verification_focus'=>'entitlement_allow_deny_plan_scope_and_effective_window_checks',
			];
		}
		if($fields_by_category['quota_limit_fields']!==[] || in_array('quotas_or_limits', $task_signals, true)){
			$controls[]=[
				'id'=>'quota_limit_policy',
				'fields'=>$fields_by_category['quota_limit_fields'],
				'policy'=>'track_quota_limits_usage_reset_windows_thresholds_and_overage_policy_in_app_owned_records',
				'verification_focus'=>'quota_limit_reset_threshold_and_overage_checks',
			];
		}
		if($fields_by_category['eligibility_fields']!==[] || in_array('eligibility', $task_signals, true)){
			$controls[]=[
				'id'=>'eligibility_policy',
				'fields'=>$fields_by_category['eligibility_fields'],
				'policy'=>'record_eligibility_state_reason_rule_set_and_segment_for_explainable_business_decisions',
				'verification_focus'=>'eligibility_allow_deny_reason_and_rule_trace_checks',
			];
		}
		if($fields_by_category['approval_delegation_fields']!==[] || in_array('approvals_or_delegation', $task_signals, true)){
			$controls[]=[
				'id'=>'approval_delegation_policy',
				'fields'=>$fields_by_category['approval_delegation_fields'],
				'policy'=>'model_approval_delegation_escalation_and_authority_limits_in_app_owned_policy',
				'verification_focus'=>'delegated_approval_authority_limit_and_escalation_checks',
			];
		}
		if($fields_by_category['exception_waiver_fields']!==[] || in_array('exceptions_or_waivers', $task_signals, true)){
			$controls[]=[
				'id'=>'exception_waiver_policy',
				'fields'=>$fields_by_category['exception_waiver_fields'],
				'policy'=>'capture_exception_reason_waiver_scope_expiry_actor_and_review_state_for_policy_overrides',
				'verification_focus'=>'exception_scope_expiry_actor_and_reversion_checks',
			];
		}
		if($fields_by_category['contract_terms_fields']!==[] || in_array('contract_terms', $task_signals, true)){
			$controls[]=[
				'id'=>'contract_terms_policy',
				'fields'=>$fields_by_category['contract_terms_fields'],
				'policy'=>'track_contract_tier_term_effective_dates_renewal_and_notice_windows_in_app_owned_policy',
				'verification_focus'=>'contract_effective_window_renewal_and_term_override_checks',
			];
		}
		if($fields_by_category['commercial_policy_fields']!==[] || in_array('commercial_policy', $task_signals, true)){
			$controls[]=[
				'id'=>'commercial_policy',
				'fields'=>$fields_by_category['commercial_policy_fields'],
				'policy'=>'keep_pricing_discount_tax_and_billing_rule_decisions_in_app_owned_policy_or_adapters',
				'verification_focus'=>'commercial_rule_discount_tax_and_denied_override_checks',
			];
		}
		$has_business_policy=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_business_policy_signals'=>$has_business_policy,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_business_policy ? 'Treat entitlements, quotas, eligibility, approvals/delegation, policy exceptions, waivers, contract terms, and commercial rules as app-owned business policy with focused allow/deny and override checks.' : 'No business-policy signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal policy engine edits for one app',
				'enterprise governance audit for ordinary app-owned business rules unless the task escalates',
				'package release validation for ordinary app-owned policy fields',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Extracts business-policy signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_business_policy_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'entitlements'=>['entitlement', 'entitlements', 'plan feature', 'feature access', 'seat access'],
			'quotas_or_limits'=>['quota', 'quotas', 'limit', 'limits', 'overage', 'threshold'],
			'eligibility'=>['eligibility', 'eligible', 'ineligible', 'criteria', 'business rule'],
			'approvals_or_delegation'=>['delegation', 'delegate', 'approval chain', 'approval policy', 'authority limit', 'escalation'],
			'exceptions_or_waivers'=>['exception', 'exceptions', 'waiver', 'waivers', 'override', 'policy override'],
			'contract_terms'=>['contract term', 'contract terms', 'renewal', 'notice window', 'term date'],
			'commercial_policy'=>['pricing rule', 'discount', 'tax rule', 'billing rule', 'commercial policy'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry business-policy semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_business_policy_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'entitlement') || str_contains($key, 'plan_feature')=>'entitlements',
			str_contains($key, 'quota') || str_contains($key, 'limit') || str_contains($key, 'usage_policy')=>'quotas_or_limits',
			str_contains($key, 'eligibility') || str_contains($key, 'criteria')=>'eligibility',
			str_contains($key, 'delegation') || str_contains($key, 'authority')=>'approvals_or_delegation',
			str_contains($key, 'exception') || str_contains($key, 'waiver') || str_contains($key, 'override')=>'exceptions_or_waivers',
			str_contains($key, 'contract') || str_contains($key, 'terms')=>'contract_terms',
			str_contains($key, 'pricing') || str_contains($key, 'discount') || str_contains($key, 'billing_rule')=>'commercial_policy',
			default=>'',
		};
	}

	/**
	 * Classifies business-policy field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_business_policy_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['entitlement_key', 'feature_key', 'plan_id', 'subscription_id', 'seat_limit', 'feature_enabled'], true)=>'entitlement_fields',
			in_array($name, ['quota_limit', 'quota_used', 'usage_limit', 'overage_allowed', 'threshold_percent', 'reset_period'], true)=>'quota_limit_fields',
			in_array($name, ['eligibility_status', 'eligibility_reason', 'criteria_json', 'rule_set', 'segment_key', 'decision_reason'], true)=>'eligibility_fields',
			in_array($name, ['approval_policy_id', 'approver_id', 'delegate_id', 'authority_limit', 'escalation_level', 'escalated_at'], true)=>'approval_delegation_fields',
			in_array($name, ['policy_exception_id', 'exception_reason', 'waiver_reason', 'override_reason', 'override_by', 'expires_at'], true)=>'exception_waiver_fields',
			in_array($name, ['contract_id', 'contract_tier', 'term_start_at', 'term_end_at', 'renewal_at', 'notice_due_at'], true)=>'contract_terms_fields',
			in_array($name, ['pricing_rule_id', 'discount_code', 'discount_percent', 'tax_rule_id', 'billing_rule_id', 'commercial_terms'], true)=>'commercial_policy_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one business-policy category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_business_policy_category_policy(string $category): string {
		return match($category){
			'entitlement_fields'=>'define_plan_feature_scope_effective_window_and_denied_access_behavior',
			'quota_limit_fields'=>'track_quota_limit_usage_reset_threshold_and_overage_policy',
			'eligibility_fields'=>'record_eligibility_rule_reason_segment_and_decision_trace',
			'approval_delegation_fields'=>'model_approval_chain_delegation_authority_limit_and_escalation',
			'exception_waiver_fields'=>'capture_exception_scope_reason_expiry_actor_and_review_state',
			'contract_terms_fields'=>'track_contract_tier_term_dates_renewal_and_notice_windows',
			'commercial_policy_fields'=>'keep_pricing_discount_tax_and_billing_rules_app_owned',
			'entitlements'=>'keep_entitlement_policy_app_owned',
			'quotas_or_limits'=>'keep_quota_limit_and_overage_policy_app_owned',
			'eligibility'=>'keep_eligibility_rules_and_decision_reasons_app_owned',
			'approvals_or_delegation'=>'keep_delegation_authority_and_escalation_policy_app_owned',
			'exceptions_or_waivers'=>'keep_exception_waiver_and_override_policy_app_owned',
			'contract_terms'=>'keep_contract_term_and_renewal_policy_app_owned',
			'commercial_policy'=>'keep_commercial_rules_app_owned_or_in_app_adapters',
			default=>'decide_app_owned_business_policy',
		};
	}

	/**
	 * Returns the focused verification target for one business-policy category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_business_policy_verification_focus(string $category): string {
		return match($category){
			'entitlement_fields'=>'entitlement_allow_deny_plan_scope_and_effective_window_checks',
			'quota_limit_fields'=>'quota_limit_reset_threshold_and_overage_checks',
			'eligibility_fields'=>'eligibility_allow_deny_reason_and_rule_trace_checks',
			'approval_delegation_fields'=>'delegated_approval_authority_limit_and_escalation_checks',
			'exception_waiver_fields'=>'exception_scope_expiry_actor_and_reversion_checks',
			'contract_terms_fields'=>'contract_effective_window_renewal_and_term_override_checks',
			'commercial_policy_fields'=>'commercial_rule_discount_tax_and_denied_override_checks',
			default=>'focused_business_policy_checks',
		};
	}

	/**
	 * Builds compact app-owned process/workflow policy guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Process/workflow policy guidance.
	 */
	private function app_builder_process_policy_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_process_policy_task_signals($task);
		$fields_by_category=[
			'assignment_fields'=>[],
			'queue_fields'=>[],
			'handoff_fields'=>[],
			'sla_deadline_fields'=>[],
			'escalation_fields'=>[],
			'dependency_fields'=>[],
			'completion_evidence_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_process_policy_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_process_policy_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_process_policy_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_process_policy_category_policy($category),
					'verification_focus'=>$this->app_builder_process_policy_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['assignment_fields']!==[] || in_array('assignments', $task_signals, true)){
			$controls[]=[
				'id'=>'assignment_policy',
				'fields'=>$fields_by_category['assignment_fields'],
				'policy'=>'track_assignment_owner_pool_claim_release_reassignment_and_visibility_in_app_owned_records',
				'verification_focus'=>'assignment_claim_reassign_release_and_scope_checks',
			];
		}
		if($fields_by_category['queue_fields']!==[] || in_array('queues', $task_signals, true)){
			$controls[]=[
				'id'=>'queue_policy',
				'fields'=>$fields_by_category['queue_fields'],
				'policy'=>'define_queue_key_priority_position_locking_and_stale_claim_behavior_in_app_owned_policy',
				'verification_focus'=>'queue_priority_position_lock_and_stale_claim_checks',
			];
		}
		if($fields_by_category['handoff_fields']!==[] || in_array('handoffs', $task_signals, true)){
			$controls[]=[
				'id'=>'handoff_policy',
				'fields'=>$fields_by_category['handoff_fields'],
				'policy'=>'capture_handoff_from_to_reason_context_due_time_and_acceptance_state_before_ownership_changes',
				'verification_focus'=>'handoff_context_acceptance_rejection_and_actor_checks',
			];
		}
		if($fields_by_category['sla_deadline_fields']!==[] || in_array('sla_or_deadlines', $task_signals, true)){
			$controls[]=[
				'id'=>'sla_deadline_policy',
				'fields'=>$fields_by_category['sla_deadline_fields'],
				'policy'=>'track_due_time_sla_clock_pause_resume_breach_and_completion_windows_in_app_owned_policy',
				'verification_focus'=>'sla_pause_resume_breach_and_completion_window_checks',
			];
		}
		if($fields_by_category['escalation_fields']!==[] || in_array('escalations', $task_signals, true)){
			$controls[]=[
				'id'=>'escalation_policy',
				'fields'=>$fields_by_category['escalation_fields'],
				'policy'=>'model_escalation_level_reason_target_trigger_time_and_acknowledgement_in_app_owned_records',
				'verification_focus'=>'escalation_trigger_acknowledgement_and_no_duplicate_checks',
			];
		}
		if($fields_by_category['dependency_fields']!==[] || in_array('dependencies', $task_signals, true)){
			$controls[]=[
				'id'=>'dependency_policy',
				'fields'=>$fields_by_category['dependency_fields'],
				'policy'=>'track_blocking_dependency_type_unblock_condition_and_ready_state_for_workflow_progress',
				'verification_focus'=>'dependency_block_unblock_and_ready_state_checks',
			];
		}
		if($fields_by_category['completion_evidence_fields']!==[] || in_array('completion_evidence', $task_signals, true)){
			$controls[]=[
				'id'=>'completion_evidence_policy',
				'fields'=>$fields_by_category['completion_evidence_fields'],
				'policy'=>'record_completion_actor_time_outcome_evidence_reference_and_reopen_reason_for_auditable_workflow_end_states',
				'verification_focus'=>'completion_evidence_reopen_and_terminal_state_checks',
			];
		}
		$has_process_policy=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_process_policy_signals'=>$has_process_policy,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_process_policy ? 'Treat assignments, queues, handoffs, SLA/deadline clocks, escalations, dependencies, and completion evidence as app-owned process/workflow policy with focused progression and negative-state checks.' : 'No process/workflow policy signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal workflow engine edits for one app',
				'global queue worker or scheduler changes for ordinary app-owned workflow records',
				'enterprise governance audit for ordinary app-owned process fields unless the task escalates',
				'package release validation for ordinary app-owned workflow fields',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for domain rules and process/workflow invariants.
	 *
	 * @param array<string,mixed> $business_policy_summary Business-policy metadata.
	 * @param array<string,mixed> $process_policy_summary Process/workflow metadata.
	 * @return array<string,mixed> App-owned domain/workflow handoff.
	 */
	private function app_builder_domain_workflow_handoff(array $business_policy_summary, array $process_policy_summary): array {
		$business_controls=is_array($business_policy_summary['controls'] ?? null) ? $business_policy_summary['controls'] : [];
		$process_controls=is_array($process_policy_summary['controls'] ?? null) ? $process_policy_summary['controls'] : [];
		$rules=[];
		foreach($business_controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$rules[]=[
				'id'=>$id,
				'kind'=>'business_policy',
				'fields'=>$this->app_builder_domain_workflow_control_fields($control),
				'decision_contract'=>$this->app_builder_domain_workflow_decision_contract($id),
				'write_order'=>$this->app_builder_domain_workflow_write_order($id),
				'negative_checks'=>$this->app_builder_domain_workflow_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		foreach($process_controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$rules[]=[
				'id'=>$id,
				'kind'=>'process_policy',
				'fields'=>$this->app_builder_domain_workflow_control_fields($control),
				'decision_contract'=>$this->app_builder_domain_workflow_decision_contract($id),
				'write_order'=>$this->app_builder_domain_workflow_write_order($id),
				'negative_checks'=>$this->app_builder_domain_workflow_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$rules===[] ? 'not_triggered' : 'ready_for_app_owned_domain_workflow_design',
			'purpose'=>'Concrete app-owned business-rule, entitlement, approval, assignment, queue, SLA, dependency, and completion-evidence handoff.',
			'rule_count'=>count($rules),
			'rules'=>$rules,
			'default_invariant_policy'=>[
				'where'=>'app-owned repositories, services, Panel actions, callbacks, dialbacks, plugins, or adapters',
				'persist'=>'decision reason, actor, scope, effective window, and evidence reference when a rule changes user-visible state',
				'avoid'=>'Dataphyre runtime-internal policy/workflow engine edits for one application',
			],
			'links'=>[
				'business_policy_summary'=>'builder_response.business_policy_summary',
				'process_policy_summary'=>'builder_response.process_policy_summary',
				'lifecycle_state_handoff'=>'builder_response.lifecycle_state_handoff',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'verification_fixture_handoff'=>'builder_response.verification_fixture_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$rules===[] ? 'No app-owned domain workflow rules were inferred.' : 'Keep business rules, workflow progression, SLA clocks, assignments, approvals, and completion evidence in app-owned code and focused app/module checks.',
			'not_required'=>[
				'Dataphyre runtime-internal policy or workflow engine edits for one app',
				'global queue worker or scheduler changes for ordinary app-owned workflow records',
				'package release validation for ordinary app-owned domain/workflow fields',
				'enterprise governance audit for ordinary app-owned workflow fields unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Normalizes a control field list for compact domain/workflow handoffs.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<int,array<string,string>> Compact field pointers.
	 */
	private function app_builder_domain_workflow_control_fields(array $control): array {
		$fields=[];
		foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
			if(!is_array($field)){
				continue;
			}
			$fields[]=[
				'entity'=>(string)($field['entity'] ?? ''),
				'table'=>(string)($field['table'] ?? ''),
				'field'=>(string)($field['field'] ?? ''),
			];
		}
		return $fields;
	}

	/**
	 * Returns app-owned decision contract notes for one domain/workflow control.
	 */
	private function app_builder_domain_workflow_decision_contract(string $control_id): string {
		return match($control_id){
			'entitlement_policy'=>'resolve plan, feature, scope, effective window, and denied-access reason before allowing the action',
			'quota_limit_policy'=>'compare limit, used count, reset window, and overage policy before accepting usage',
			'eligibility_policy'=>'record rule set, segment, allow/deny decision, and explainable reason for business decisions',
			'approval_delegation_policy'=>'resolve approver/delegate authority, escalation path, and limit before accepting approval',
			'exception_waiver_policy'=>'capture waiver scope, actor, reason, expiry, and reversion behavior before overriding policy',
			'contract_terms_policy'=>'resolve contract tier, effective dates, renewal, and notice window before applying terms',
			'commercial_policy'=>'validate pricing, discount, tax, currency, and denied override behavior in app-owned policy',
			'assignment_policy'=>'resolve actor scope, assignment pool, claim/release/reassign rules, and visibility before ownership changes',
			'queue_policy'=>'claim work with priority, position, lock owner, stale-lock policy, and scope checks',
			'handoff_policy'=>'capture source actor, target actor/team, context, due time, acceptance, and rejection behavior',
			'sla_deadline_policy'=>'derive due window, pause/resume clocks, breach state, and completion cutoff before state transitions',
			'escalation_policy'=>'derive trigger, target, level, acknowledgement, and duplicate-prevention behavior',
			'dependency_policy'=>'block progression until dependencies are satisfied and ready state is recorded',
			'completion_evidence_policy'=>'require actor, time, outcome, evidence reference, and reopen reason around terminal workflow states',
			default=>'define the app-owned decision inputs, state change, audit reason, and denial behavior before writing actions',
		};
	}

	/**
	 * Returns safe write order for one domain/workflow control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_domain_workflow_write_order(string $control_id): array {
		return match($control_id){
			'entitlement_policy'=>['load_actor_scope', 'resolve_plan_feature_and_effective_window', 'allow_or_deny_action', 'record_decision_reason'],
			'quota_limit_policy'=>['load_quota_scope', 'compare_usage_limit_and_reset_window', 'apply_overage_policy', 'record_usage_result'],
			'eligibility_policy'=>['load_rule_set_and_segment', 'evaluate_criteria', 'persist_decision_reason', 'expose_copy_safe_denial'],
			'approval_delegation_policy'=>['resolve_authority_limit', 'resolve_delegate_or_escalation', 'apply_approval_decision', 'record_approval_evidence'],
			'exception_waiver_policy'=>['validate_waiver_scope', 'record_actor_reason_and_expiry', 'apply_override', 'schedule_or_check_reversion'],
			'contract_terms_policy'=>['resolve_contract_terms', 'validate_effective_window', 'apply_renewal_or_notice_rule', 'record_term_decision'],
			'commercial_policy'=>['resolve_commercial_rule', 'validate_currency_tax_discount', 'deny_unauthorized_override', 'record_pricing_decision'],
			'assignment_policy'=>['resolve_assignment_scope', 'claim_or_reassign', 'update_visibility_and_owner', 'record_assignment_event'],
			'queue_policy'=>['select_queue_scope', 'claim_lock_with_priority', 'handle_stale_lock', 'record_queue_position_state'],
			'handoff_policy'=>['record_handoff_context', 'notify_or_expose_target', 'accept_or_reject', 'record_actor_history'],
			'sla_deadline_policy'=>['derive_due_window', 'apply_pause_resume', 'detect_breach', 'record_completion_or_breach_state'],
			'escalation_policy'=>['evaluate_trigger', 'create_single_escalation', 'route_to_target', 'record_acknowledgement'],
			'dependency_policy'=>['load_dependencies', 'block_until_ready', 'record_unblock_condition', 'allow_progression'],
			'completion_evidence_policy'=>['validate_terminal_transition', 'record_actor_time_outcome', 'attach_evidence_reference', 'guard_reopen_reason'],
			default=>['derive_scope', 'evaluate_rule', 'apply_or_deny_change', 'record_copy_safe_reason'],
		};
	}

	/**
	 * Returns negative checks for one domain/workflow control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_domain_workflow_negative_checks(string $control_id): array {
		return match($control_id){
			'entitlement_policy'=>['missing_entitlement_denies_action', 'expired_entitlement_denies_action', 'cross_scope_entitlement_rejected'],
			'quota_limit_policy'=>['quota_exceeded_denied_or_overage_recorded', 'reset_window_boundary_checked', 'negative_usage_delta_rejected'],
			'eligibility_policy'=>['ineligible_segment_denied_with_reason', 'missing_rule_set_blocks_decision', 'decision_reason_not_secret_bearing'],
			'approval_delegation_policy'=>['delegate_without_authority_rejected', 'approval_over_limit_escalates', 'self_approval_rejected_when_policy_forbids'],
			'exception_waiver_policy'=>['expired_waiver_rejected', 'waiver_scope_mismatch_rejected', 'override_without_actor_reason_rejected'],
			'contract_terms_policy'=>['outside_effective_window_denied', 'renewal_notice_boundary_checked', 'term_override_without_permission_rejected'],
			'commercial_policy'=>['unauthorized_discount_rejected', 'currency_mismatch_rejected', 'tax_rule_missing_blocks_invoice_or_quote'],
			'assignment_policy'=>['cross_scope_assignee_rejected', 'claim_of_already_claimed_item_denied_or_idempotent', 'reassignment_without_permission_rejected'],
			'queue_policy'=>['stale_lock_requires_reclaim_policy', 'lower_priority_cannot_jump_queue_without_permission', 'cross_scope_queue_claim_rejected'],
			'handoff_policy'=>['handoff_to_unavailable_actor_rejected', 'handoff_reject_preserves_previous_owner', 'handoff_context_required'],
			'sla_deadline_policy'=>['breach_detected_when_due_window_passed', 'pause_without_reason_rejected', 'completion_after_deadline_records_breach'],
			'escalation_policy'=>['duplicate_escalation_not_created', 'escalation_without_trigger_rejected', 'acknowledgement_by_wrong_actor_rejected'],
			'dependency_policy'=>['blocked_item_cannot_complete', 'missing_dependency_safe_empty_state', 'unblock_without_condition_rejected'],
			'completion_evidence_policy'=>['terminal_state_without_evidence_rejected', 'reopen_without_reason_rejected', 'completion_actor_scope_checked'],
			default=>['rule_denial_path_checked', 'cross_scope_state_change_rejected', 'decision_reason_copy_safe'],
		};
	}

	/**
	 * Extracts process/workflow policy signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_process_policy_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'assignments'=>['assignment', 'assignments', 'assigned to', 'assignee', 'claim work', 'reassign'],
			'queues'=>['queue', 'queues', 'work queue', 'priority queue', 'claim queue'],
			'handoffs'=>['handoff', 'handoffs', 'hand off', 'handover', 'transfer ownership'],
			'sla_or_deadlines'=>['sla', 'deadline', 'deadlines', 'due date', 'due_at', 'breach'],
			'escalations'=>['escalation', 'escalations', 'escalate', 'escalated'],
			'dependencies'=>['dependency', 'dependencies', 'blocked by', 'blocking', 'unblock'],
			'completion_evidence'=>['completion evidence', 'completed by', 'completed at', 'acceptance evidence', 'proof of completion', 'reopen'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry process/workflow semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_process_policy_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'assignment') || str_contains($key, 'assignee')=>'assignments',
			str_contains($key, 'queue') || str_contains($key, 'work_item')=>'queues',
			str_contains($key, 'handoff') || str_contains($key, 'handover')=>'handoffs',
			str_contains($key, 'sla') || str_contains($key, 'deadline')=>'sla_or_deadlines',
			str_contains($key, 'escalation')=>'escalations',
			str_contains($key, 'dependency') || str_contains($key, 'blocker')=>'dependencies',
			str_contains($key, 'completion') || str_contains($key, 'evidence')=>'completion_evidence',
			default=>'',
		};
	}

	/**
	 * Classifies process/workflow field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_process_policy_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['assigned_to', 'assignee_id', 'assignment_pool', 'claimed_by', 'claimed_at', 'reassigned_from'], true)=>'assignment_fields',
			in_array($name, ['queue_key', 'queue_name', 'queue_position', 'priority_rank', 'locked_by', 'locked_until'], true)=>'queue_fields',
			in_array($name, ['handoff_from', 'handoff_to', 'handoff_reason', 'handoff_context', 'handoff_accepted_at', 'handoff_due_at'], true)=>'handoff_fields',
			in_array($name, ['sla_policy_id', 'due_at', 'deadline_at', 'breached_at', 'paused_at', 'resumed_at'], true)=>'sla_deadline_fields',
			in_array($name, ['escalation_level', 'escalation_reason', 'escalated_to', 'escalated_at', 'acknowledged_at', 'acknowledged_by'], true)=>'escalation_fields',
			in_array($name, ['blocked_by_id', 'dependency_id', 'dependency_type', 'blocked_reason', 'unblocked_at', 'ready_at'], true)=>'dependency_fields',
			in_array($name, ['completed_by', 'completed_at', 'completion_note', 'completion_evidence_ref', 'outcome', 'reopen_reason'], true)=>'completion_evidence_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one process/workflow category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_process_policy_category_policy(string $category): string {
		return match($category){
			'assignment_fields'=>'track_assignment_claim_release_reassignment_and_visibility',
			'queue_fields'=>'define_queue_priority_position_lock_and_stale_claim_behavior',
			'handoff_fields'=>'capture_handoff_context_acceptance_due_time_and_actor_history',
			'sla_deadline_fields'=>'track_sla_due_pause_resume_breach_and_completion_windows',
			'escalation_fields'=>'model_escalation_trigger_target_acknowledgement_and_duplicate_prevention',
			'dependency_fields'=>'track_blocking_dependency_unblock_condition_and_ready_state',
			'completion_evidence_fields'=>'record_completion_actor_time_evidence_outcome_and_reopen_reason',
			'assignments'=>'keep_assignment_policy_app_owned',
			'queues'=>'keep_queue_claim_priority_and_locking_policy_app_owned',
			'handoffs'=>'keep_handoff_acceptance_and_ownership_transfer_policy_app_owned',
			'sla_or_deadlines'=>'keep_sla_deadline_breach_and_pause_resume_policy_app_owned',
			'escalations'=>'keep_escalation_trigger_and_acknowledgement_policy_app_owned',
			'dependencies'=>'keep_dependency_block_unblock_policy_app_owned',
			'completion_evidence'=>'keep_completion_evidence_and_reopen_policy_app_owned',
			default=>'decide_app_owned_process_policy',
		};
	}

	/**
	 * Returns the focused verification target for one process/workflow category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_process_policy_verification_focus(string $category): string {
		return match($category){
			'assignment_fields'=>'assignment_claim_reassign_release_and_scope_checks',
			'queue_fields'=>'queue_priority_position_lock_and_stale_claim_checks',
			'handoff_fields'=>'handoff_context_acceptance_rejection_and_actor_checks',
			'sla_deadline_fields'=>'sla_pause_resume_breach_and_completion_window_checks',
			'escalation_fields'=>'escalation_trigger_acknowledgement_and_no_duplicate_checks',
			'dependency_fields'=>'dependency_block_unblock_and_ready_state_checks',
			'completion_evidence_fields'=>'completion_evidence_reopen_and_terminal_state_checks',
			default=>'focused_process_policy_checks',
		};
	}

	/**
	 * Builds compact app-owned reporting/analytics guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Reporting/analytics guidance.
	 */
	private function app_builder_reporting_analytics_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_reporting_analytics_task_signals($task);
		$fields_by_category=[
			'metric_fields'=>[],
			'dimension_fields'=>[],
			'snapshot_fields'=>[],
			'freshness_fields'=>[],
			'drilldown_fields'=>[],
			'dashboard_visibility_fields'=>[],
			'export_reporting_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_reporting_analytics_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_reporting_analytics_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_reporting_analytics_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_reporting_analytics_category_policy($category),
					'verification_focus'=>$this->app_builder_reporting_analytics_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['metric_fields']!==[] || in_array('metrics_or_kpis', $task_signals, true)){
			$controls[]=[
				'id'=>'metric_definition_policy',
				'fields'=>$fields_by_category['metric_fields'],
				'policy'=>'define_metric_key_formula_unit_grain_and_denominator_in_app_owned_reporting_policy',
				'verification_focus'=>'metric_formula_grain_denominator_and_null_handling_checks',
			];
		}
		if($fields_by_category['dimension_fields']!==[] || in_array('dimensions', $task_signals, true)){
			$controls[]=[
				'id'=>'dimension_filter_policy',
				'fields'=>$fields_by_category['dimension_fields'],
				'policy'=>'track_dimension_keys_segments_filters_and_tenant_scope_for_reporting_queries',
				'verification_focus'=>'dimension_filter_tenant_scope_and_empty_bucket_checks',
			];
		}
		if($fields_by_category['snapshot_fields']!==[] || in_array('snapshots', $task_signals, true)){
			$controls[]=[
				'id'=>'snapshot_policy',
				'fields'=>$fields_by_category['snapshot_fields'],
				'policy'=>'record_snapshot_period_as_of_time_source_range_and_rebuild_policy_for_reproducible_reports',
				'verification_focus'=>'snapshot_as_of_period_rebuild_and_consistency_checks',
			];
		}
		if($fields_by_category['freshness_fields']!==[] || in_array('freshness', $task_signals, true)){
			$controls[]=[
				'id'=>'freshness_policy',
				'fields'=>$fields_by_category['freshness_fields'],
				'policy'=>'track_last_calculated_staleness_threshold_refresh_state_and_lag_reason_for_dashboards',
				'verification_focus'=>'freshness_staleness_refresh_and_lag_reason_checks',
			];
		}
		if($fields_by_category['drilldown_fields']!==[] || in_array('drilldowns', $task_signals, true)){
			$controls[]=[
				'id'=>'drilldown_policy',
				'fields'=>$fields_by_category['drilldown_fields'],
				'policy'=>'define_drilldown_target_detail_scope_and_permission_checked_navigation_for_reports',
				'verification_focus'=>'drilldown_scope_permission_and_missing_target_checks',
			];
		}
		if($fields_by_category['dashboard_visibility_fields']!==[] || in_array('dashboards', $task_signals, true)){
			$controls[]=[
				'id'=>'dashboard_visibility_policy',
				'fields'=>$fields_by_category['dashboard_visibility_fields'],
				'policy'=>'model_dashboard_visibility_owner_audience_default_filters_and_hidden_metric_rules',
				'verification_focus'=>'dashboard_visibility_audience_filter_and_hidden_metric_checks',
			];
		}
		if($fields_by_category['export_reporting_fields']!==[] || in_array('report_exports', $task_signals, true)){
			$controls[]=[
				'id'=>'report_export_policy',
				'fields'=>$fields_by_category['export_reporting_fields'],
				'policy'=>'gate_report_exports_with_format_scope_redaction_row_limit_and_expiry_rules',
				'verification_focus'=>'report_export_scope_redaction_row_limit_and_expiry_checks',
			];
		}
		$has_reporting=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_reporting_analytics_signals'=>$has_reporting,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_reporting ? 'Treat metrics, dimensions, snapshots, freshness, drilldowns, dashboard visibility, and report exports as app-owned reporting/analytics policy with focused calculation, scope, and export checks.' : 'No reporting/analytics signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal analytics engine edits for one app',
				'data warehouse or BI platform setup for ordinary app-owned reporting fields',
				'package release validation for ordinary app-owned reporting fields',
				'enterprise governance audit for ordinary app-owned dashboards unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for reporting, analytics, dashboards, and exports.
	 *
	 * @param array<string,mixed> $reporting_analytics_summary Reporting/analytics metadata.
	 * @return array<string,mixed> App-owned reporting handoff.
	 */
	private function app_builder_reporting_analytics_handoff(array $reporting_analytics_summary): array {
		$controls=is_array($reporting_analytics_summary['controls'] ?? null) ? $reporting_analytics_summary['controls'] : [];
		$reports=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$reports[]=[
				'id'=>$id,
				'fields'=>$this->app_builder_reporting_analytics_control_fields($control),
				'calculation_contract'=>$this->app_builder_reporting_analytics_calculation_contract($id),
				'write_order'=>$this->app_builder_reporting_analytics_write_order($id),
				'negative_checks'=>$this->app_builder_reporting_analytics_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$reports===[] ? 'not_triggered' : 'ready_for_app_owned_reporting_design',
			'purpose'=>'Concrete app-owned metric definition, dimension, snapshot, freshness, drilldown, dashboard-visibility, and report-export handoff.',
			'reporting_rule_count'=>count($reports),
			'reporting_rules'=>$reports,
			'default_reporting_policy'=>[
				'define'=>'metric formula, unit, grain, denominator/null handling, tenant/workspace scope, and refresh/freshness behavior before displaying values',
				'protect'=>'permission-gate drilldowns and exports; apply redaction, row limits, expiry, and dashboard visibility before sharing data',
				'avoid'=>'Dataphyre runtime-internal analytics engine, BI platform, or data-warehouse setup for ordinary app-owned dashboards',
			],
			'links'=>[
				'reporting_analytics_summary'=>'builder_response.reporting_analytics_summary',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'audit_retention_handoff'=>'builder_response.audit_retention_handoff',
				'data_sensitivity_summary'=>'builder_response.data_sensitivity_summary',
				'verification_handoff'=>'builder_response.verification_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$reports===[] ? 'No app-owned reporting/analytics rules were inferred.' : 'Keep metrics, dimensions, snapshots, freshness, dashboard visibility, drilldowns, and report exports in app-owned repositories, callbacks, dialbacks, plugins, or adapters.',
			'not_required'=>[
				'Dataphyre runtime-internal analytics engine edits for one app',
				'data warehouse or BI platform setup for ordinary app-owned reporting fields',
				'package release validation for ordinary app-owned reporting fields',
				'enterprise governance audit for ordinary app-owned dashboards unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Normalizes a control field list for compact reporting handoffs.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<int,array<string,string>> Compact field pointers.
	 */
	private function app_builder_reporting_analytics_control_fields(array $control): array {
		$fields=[];
		foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
			if(!is_array($field)){
				continue;
			}
			$fields[]=[
				'entity'=>(string)($field['entity'] ?? ''),
				'table'=>(string)($field['table'] ?? ''),
				'field'=>(string)($field['field'] ?? ''),
			];
		}
		return $fields;
	}

	/**
	 * Returns app-owned calculation contract notes for one reporting control.
	 */
	private function app_builder_reporting_analytics_calculation_contract(string $control_id): string {
		return match($control_id){
			'metric_definition_policy'=>'define formula, unit, grain, denominator, null handling, rounding, and owner before rendering a metric',
			'dimension_filter_policy'=>'define allowed dimensions, filter shape, tenant/workspace scope, and empty-bucket behavior before grouping data',
			'snapshot_policy'=>'record as-of time, period, source range, rebuild policy, and consistency evidence for reproducible reports',
			'freshness_policy'=>'track last calculation, staleness threshold, refresh state, lag reason, and stale-value display behavior',
			'drilldown_policy'=>'permission-gate detail navigation, target record lookup, scope, and missing-target empty states',
			'dashboard_visibility_policy'=>'resolve owner, audience, visibility, default filters, and hidden metrics before exposing dashboards',
			'report_export_policy'=>'gate export format, scope, redaction profile, row limit, expiry, and audit/export evidence before generating files',
			default=>'define the app-owned reporting calculation, visibility, and denial behavior before rendering or exporting data',
		};
	}

	/**
	 * Returns safe write order for one reporting control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_reporting_analytics_write_order(string $control_id): array {
		return match($control_id){
			'metric_definition_policy'=>['define_metric_contract', 'implement_scoped_query_or_calculation', 'handle_null_denominator_and_rounding', 'record_or_display_metric_evidence'],
			'dimension_filter_policy'=>['define_allowed_dimensions', 'apply_tenant_scope_and_filters', 'group_or_bucket_results', 'handle_empty_buckets'],
			'snapshot_policy'=>['derive_period_and_as_of_time', 'capture_source_range_hash', 'persist_snapshot', 'define_rebuild_or_reconciliation_policy'],
			'freshness_policy'=>['load_last_calculated_state', 'compare_staleness_threshold', 'refresh_or_mark_stale', 'record_lag_reason'],
			'drilldown_policy'=>['resolve_drilldown_target', 'permission_gate_detail_scope', 'handle_missing_target', 'record_navigation_context_if_needed'],
			'dashboard_visibility_policy'=>['resolve_dashboard_owner_and_audience', 'apply_default_filters', 'hide_restricted_metrics', 'verify_shared_visibility_scope'],
			'report_export_policy'=>['resolve_export_scope', 'apply_redaction_and_row_limit', 'generate_or_queue_export', 'record_expiry_and_export_evidence'],
			default=>['derive_reporting_scope', 'calculate_or_fetch_data', 'apply_visibility_policy', 'record_copy_safe_result'],
		};
	}

	/**
	 * Returns negative checks for one reporting control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_reporting_analytics_negative_checks(string $control_id): array {
		return match($control_id){
			'metric_definition_policy'=>['missing_formula_denies_metric_publish', 'zero_denominator_handled_without_bad_value', 'metric_scope_cross_tenant_rejected'],
			'dimension_filter_policy'=>['unknown_dimension_rejected', 'dimension_cross_scope_bucket_hidden', 'empty_bucket_returns_safe_zero_or_empty_state'],
			'snapshot_policy'=>['snapshot_without_as_of_or_period_rejected', 'source_range_mismatch_blocks_reproducible_report', 'rebuild_preserves_prior_evidence'],
			'freshness_policy'=>['stale_report_marked_before_display', 'failed_refresh_records_lag_reason', 'freshness_threshold_boundary_checked'],
			'drilldown_policy'=>['drilldown_without_permission_rejected', 'missing_target_safe_empty_state', 'cross_scope_detail_navigation_rejected'],
			'dashboard_visibility_policy'=>['private_dashboard_hidden_from_other_actor', 'team_dashboard_cross_team_rejected', 'hidden_metric_not_exported_or_rendered'],
			'report_export_policy'=>['export_without_permission_rejected', 'row_limit_exceeded_denied_or_truncated', 'expired_export_not_downloadable'],
			default=>['reporting_scope_checked', 'unsafe_export_or_drilldown_denied'],
		};
	}

	/**
	 * Extracts reporting/analytics signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_reporting_analytics_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'metrics_or_kpis'=>['metric', 'metrics', 'kpi', 'kpis', 'measure', 'formula'],
			'dimensions'=>['dimension', 'dimensions', 'segment', 'segments', 'filter breakdown'],
			'snapshots'=>['snapshot', 'snapshots', 'as of', 'period close', 'reporting period'],
			'freshness'=>['freshness', 'stale', 'staleness', 'refresh', 'last calculated', 'lag'],
			'drilldowns'=>['drilldown', 'drilldowns', 'drill down', 'detail view', 'detail report'],
			'dashboards'=>['dashboard', 'dashboards', 'chart', 'charts', 'report view'],
			'report_exports'=>['report export', 'report exports', 'csv export', 'export reports', 'scheduled report'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry reporting/analytics semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_reporting_analytics_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'metric') || str_contains($key, 'kpi')=>'metrics_or_kpis',
			str_contains($key, 'dimension') || str_contains($key, 'segment')=>'dimensions',
			str_contains($key, 'snapshot') || str_contains($key, 'reporting_period')=>'snapshots',
			str_contains($key, 'dashboard') || str_contains($key, 'report')=>'dashboards',
			str_contains($key, 'export')=>'report_exports',
			default=>'',
		};
	}

	/**
	 * Classifies reporting/analytics field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_reporting_analytics_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['metric_key', 'metric_value', 'metric_unit', 'formula_key', 'denominator_value', 'aggregation_grain'], true)=>'metric_fields',
			in_array($name, ['dimension_key', 'dimension_value', 'segment_key', 'filter_json', 'group_by_key', 'breakdown_key'], true)=>'dimension_fields',
			in_array($name, ['snapshot_id', 'snapshot_at', 'as_of_at', 'period_start_at', 'period_end_at', 'source_range_hash'], true)=>'snapshot_fields',
			in_array($name, ['last_calculated_at', 'freshness_status', 'stale_after_seconds', 'refresh_status', 'refresh_lag_seconds', 'lag_reason'], true)=>'freshness_fields',
			in_array($name, ['drilldown_target', 'drilldown_scope', 'detail_route', 'source_record_id', 'source_table', 'detail_filter_json'], true)=>'drilldown_fields',
			in_array($name, ['dashboard_id', 'dashboard_visibility', 'audience_scope', 'default_filter_json', 'hidden_metric_keys', 'owner_id'], true)=>'dashboard_visibility_fields',
			in_array($name, ['export_format', 'export_status', 'export_row_count', 'export_expires_at', 'redaction_profile', 'scheduled_report_id'], true)=>'export_reporting_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one reporting/analytics category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_reporting_analytics_category_policy(string $category): string {
		return match($category){
			'metric_fields'=>'define_metric_formula_unit_grain_denominator_and_null_policy',
			'dimension_fields'=>'track_dimension_filter_segment_and_tenant_scope_policy',
			'snapshot_fields'=>'record_snapshot_period_as_of_source_range_and_rebuild_policy',
			'freshness_fields'=>'track_report_freshness_refresh_state_staleness_and_lag_reason',
			'drilldown_fields'=>'permission_gate_drilldown_target_scope_and_detail_navigation',
			'dashboard_visibility_fields'=>'model_dashboard_audience_visibility_default_filters_and_hidden_metrics',
			'export_reporting_fields'=>'gate_report_export_format_scope_redaction_row_limit_and_expiry',
			'metrics_or_kpis'=>'keep_metric_definitions_app_owned',
			'dimensions'=>'keep_dimension_filter_and_segment_policy_app_owned',
			'snapshots'=>'keep_snapshot_rebuild_and_as_of_policy_app_owned',
			'freshness'=>'keep_freshness_staleness_and_refresh_policy_app_owned',
			'drilldowns'=>'keep_drilldown_scope_and_permission_policy_app_owned',
			'dashboards'=>'keep_dashboard_visibility_and_default_filter_policy_app_owned',
			'report_exports'=>'keep_report_export_scope_redaction_and_expiry_policy_app_owned',
			default=>'decide_app_owned_reporting_analytics_policy',
		};
	}

	/**
	 * Returns the focused verification target for one reporting/analytics category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_reporting_analytics_verification_focus(string $category): string {
		return match($category){
			'metric_fields'=>'metric_formula_grain_denominator_and_null_handling_checks',
			'dimension_fields'=>'dimension_filter_tenant_scope_and_empty_bucket_checks',
			'snapshot_fields'=>'snapshot_as_of_period_rebuild_and_consistency_checks',
			'freshness_fields'=>'freshness_staleness_refresh_and_lag_reason_checks',
			'drilldown_fields'=>'drilldown_scope_permission_and_missing_target_checks',
			'dashboard_visibility_fields'=>'dashboard_visibility_audience_filter_and_hidden_metric_checks',
			'export_reporting_fields'=>'report_export_scope_redaction_row_limit_and_expiry_checks',
			default=>'focused_reporting_analytics_checks',
		};
	}

	/**
	 * Builds compact app-owned notification/communication guidance.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text.
	 * @return array<string,mixed> Notification/communication guidance.
	 */
	private function app_builder_notification_communication_summary(array $schemas, string $task): array {
		$task_signals=$this->app_builder_notification_communication_task_signals($task);
		$fields_by_category=[
			'template_fields'=>[],
			'channel_fields'=>[],
			'recipient_fields'=>[],
			'preference_fields'=>[],
			'suppression_fields'=>[],
			'delivery_receipt_fields'=>[],
			'escalation_communication_fields'=>[],
		];
		$entity_signals=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			$entity_category=$this->app_builder_notification_communication_entity_category($entity);
			if($entity_category!==''){
				$entity_signals[]=[
					'entity'=>$entity,
					'table'=>$table,
					'category'=>$entity_category,
					'policy'=>$this->app_builder_notification_communication_category_policy($entity_category),
				];
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$category=$this->app_builder_notification_communication_field_category($name);
				if($category===''){
					continue;
				}
				$fields_by_category[$category][]=[
					'entity'=>$entity,
					'table'=>$table,
					'field'=>$name,
					'field_type'=>(string)($field['type'] ?? 'string'),
					'required'=>($field['required'] ?? false)===true,
					'policy'=>$this->app_builder_notification_communication_category_policy($category),
					'verification_focus'=>$this->app_builder_notification_communication_verification_focus($category),
				];
			}
		}
		$controls=[];
		if($fields_by_category['template_fields']!==[] || in_array('templates', $task_signals, true)){
			$controls[]=[
				'id'=>'template_policy',
				'fields'=>$fields_by_category['template_fields'],
				'policy'=>'version_message_templates_with_locale_subject_body_variables_and_preview_policy_in_app_owned_records',
				'verification_focus'=>'template_locale_variable_preview_and_missing_template_checks',
			];
		}
		if($fields_by_category['channel_fields']!==[] || in_array('channels', $task_signals, true)){
			$controls[]=[
				'id'=>'channel_policy',
				'fields'=>$fields_by_category['channel_fields'],
				'policy'=>'define_channel_email_sms_push_in_app_webhook_fallback_and_provider_adapter_rules_app_owned',
				'verification_focus'=>'channel_selection_fallback_provider_and_disabled_channel_checks',
			];
		}
		if($fields_by_category['recipient_fields']!==[] || in_array('recipients', $task_signals, true)){
			$controls[]=[
				'id'=>'recipient_policy',
				'fields'=>$fields_by_category['recipient_fields'],
				'policy'=>'resolve_recipients_from_actor_role_team_tenant_and_explicit_address_scope_with_visibility_checks',
				'verification_focus'=>'recipient_resolution_scope_dedupe_and_visibility_checks',
			];
		}
		if($fields_by_category['preference_fields']!==[] || in_array('preferences', $task_signals, true)){
			$controls[]=[
				'id'=>'preference_policy',
				'fields'=>$fields_by_category['preference_fields'],
				'policy'=>'honor_opt_in_opt_out_digest_frequency_locale_timezone_and_user_preference_rules',
				'verification_focus'=>'preference_opt_out_digest_locale_and_timezone_checks',
			];
		}
		if($fields_by_category['suppression_fields']!==[] || in_array('suppression_or_quiet_hours', $task_signals, true)){
			$controls[]=[
				'id'=>'suppression_policy',
				'fields'=>$fields_by_category['suppression_fields'],
				'policy'=>'track_suppression_reason_quiet_hours_hold_until_and_duplicate_suppression_before_delivery',
				'verification_focus'=>'quiet_hours_suppression_hold_and_duplicate_checks',
			];
		}
		if($fields_by_category['delivery_receipt_fields']!==[] || in_array('delivery_receipts', $task_signals, true)){
			$controls[]=[
				'id'=>'delivery_receipt_policy',
				'fields'=>$fields_by_category['delivery_receipt_fields'],
				'policy'=>'record_send_delivered_open_bounce_failure_provider_message_and_retry_state_in_app_owned_tables',
				'verification_focus'=>'delivery_receipt_failure_bounce_retry_and_provider_id_checks',
			];
		}
		if($fields_by_category['escalation_communication_fields']!==[] || in_array('escalation_communications', $task_signals, true)){
			$controls[]=[
				'id'=>'escalation_communication_policy',
				'fields'=>$fields_by_category['escalation_communication_fields'],
				'policy'=>'model_escalation_notification_level_trigger_acknowledgement_and_next_channel_policy_app_owned',
				'verification_focus'=>'escalation_notification_trigger_acknowledgement_and_fallback_checks',
			];
		}
		$has_notification=$controls!==[] || $entity_signals!==[] || $task_signals!==[];
		return [
			'owner'=>'consuming_application',
			'has_notification_communication_signals'=>$has_notification,
			'task_signals'=>$task_signals,
			'entity_signals'=>$entity_signals,
			'fields_by_category'=>$fields_by_category,
			'controls'=>$controls,
			'policy'=>$has_notification ? 'Treat templates, channels, recipients, preferences, suppression windows, delivery receipts, and escalation communications as app-owned notification policy with focused delivery and suppression checks.' : 'No notification/communication signals were inferred.',
			'not_required'=>[
				'Dataphyre runtime-internal notification engine edits for one app',
				'external email/SMS/push provider setup for ordinary app-owned notification records',
				'package release validation for ordinary app-owned communication fields',
				'enterprise governance audit for ordinary app-owned notifications unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a concrete app-agent handoff for notifications, communication, and delivery policy.
	 *
	 * @param array<string,mixed> $notification_communication_summary Notification/communication metadata.
	 * @return array<string,mixed> App-owned notification handoff.
	 */
	private function app_builder_notification_communication_handoff(array $notification_communication_summary): array {
		$controls=is_array($notification_communication_summary['controls'] ?? null) ? $notification_communication_summary['controls'] : [];
		$notifications=[];
		foreach($controls as $control){
			if(!is_array($control)){
				continue;
			}
			$id=(string)($control['id'] ?? '');
			$notifications[]=[
				'id'=>$id,
				'fields'=>$this->app_builder_notification_communication_control_fields($control),
				'delivery_contract'=>$this->app_builder_notification_communication_delivery_contract($id),
				'write_order'=>$this->app_builder_notification_communication_write_order($id),
				'negative_checks'=>$this->app_builder_notification_communication_negative_checks($id),
				'verification_focus'=>(string)($control['verification_focus'] ?? ''),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$notifications===[] ? 'not_triggered' : 'ready_for_app_owned_notification_design',
			'purpose'=>'Concrete app-owned template, channel, recipient, preference, suppression, delivery receipt, and escalation communication handoff.',
			'notification_rule_count'=>count($notifications),
			'notification_rules'=>$notifications,
			'default_delivery_policy'=>[
				'define'=>'template version, locale, variables, channel/provider adapter, recipient scope, preference, quiet-hour, suppression, retry, and receipt behavior before sending',
				'protect'=>'do not place provider secrets, raw recipient lists, auth headers, cookies, signed URLs, or tenant-private payloads in scaffolds or handoffs',
				'avoid'=>'Dataphyre runtime-internal notification engine edits or external provider setup for ordinary app-owned notification records',
			],
			'links'=>[
				'notification_communication_summary'=>'builder_response.notification_communication_summary',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'operational_reliability_handoff'=>'builder_response.operational_reliability_handoff',
				'integration_boundary_handoff'=>'builder_response.integration_boundary_handoff',
				'support_observability_handoff'=>'builder_response.support_observability_handoff',
				'verification_handoff'=>'builder_response.verification_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe.items',
			],
			'policy'=>$notifications===[] ? 'No app-owned notification/communication rules were inferred.' : 'Keep notification templates, channel selection, recipients, preferences, suppression, delivery receipts, and escalation fallback in app-owned records, callbacks, dialbacks, plugins, or adapters.',
			'not_required'=>[
				'Dataphyre runtime-internal notification engine edits for one app',
				'external email/SMS/push provider setup for ordinary app-owned notification records',
				'plain provider secrets or auth headers in generated scaffolds or agent handoffs',
				'package release validation for ordinary app-owned communication fields',
				'enterprise governance audit for ordinary app-owned notifications unless the task escalates',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Normalizes a control field list for compact notification handoffs.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<int,array<string,string>> Compact field pointers.
	 */
	private function app_builder_notification_communication_control_fields(array $control): array {
		$fields=[];
		foreach(is_array($control['fields'] ?? null) ? $control['fields'] : [] as $field){
			if(!is_array($field)){
				continue;
			}
			$fields[]=[
				'entity'=>(string)($field['entity'] ?? ''),
				'table'=>(string)($field['table'] ?? ''),
				'field'=>(string)($field['field'] ?? ''),
			];
		}
		return $fields;
	}

	/**
	 * Returns app-owned delivery contract notes for one notification control.
	 */
	private function app_builder_notification_communication_delivery_contract(string $control_id): string {
		return match($control_id){
			'template_policy'=>'version template subject/body, locale, variables, preview behavior, and missing-template fallback before sending',
			'channel_policy'=>'select channel/provider adapter, fallback channel, disabled-channel behavior, and copy-safe provider error mapping',
			'recipient_policy'=>'resolve recipients from actor role/team/tenant scope, dedupe recipients, and avoid visibility leaks',
			'preference_policy'=>'honor opt-out, subscription, digest frequency, locale, timezone, and per-channel preference policy',
			'suppression_policy'=>'apply quiet hours, hold-until, suppression reason, duplicate suppression, and send-after behavior before delivery',
			'delivery_receipt_policy'=>'record sent/delivered/open/bounce/failure/provider ids and retry state without exposing provider secrets',
			'escalation_communication_policy'=>'derive escalation trigger, next channel, acknowledgement requirement, and fallback behavior',
			default=>'define app-owned notification policy before creating send actions or delivery records',
		};
	}

	/**
	 * Returns safe write order for one notification control.
	 *
	 * @return array<int,string> Ordered steps.
	 */
	private function app_builder_notification_communication_write_order(string $control_id): array {
		return match($control_id){
			'template_policy'=>['resolve_template_version_and_locale', 'validate_required_variables', 'render_preview_copy_safely', 'handle_missing_template'],
			'channel_policy'=>['select_channel_and_provider_adapter', 'validate_channel_enabled', 'apply_fallback_channel', 'translate_provider_error_copy_safely'],
			'recipient_policy'=>['resolve_recipient_scope', 'dedupe_recipients', 'apply_visibility_policy', 'record_recipient_resolution_summary'],
			'preference_policy'=>['load_preferences', 'apply_opt_out_and_subscription', 'apply_digest_locale_timezone', 'record_preference_decision'],
			'suppression_policy'=>['check_suppression_and_quiet_hours', 'hold_or_schedule_delivery', 'dedupe_pending_send', 'record_suppression_reason'],
			'delivery_receipt_policy'=>['create_delivery_attempt', 'record_provider_message_reference', 'update_delivery_receipt_state', 'schedule_retry_or_terminal_failure'],
			'escalation_communication_policy'=>['evaluate_escalation_trigger', 'select_next_channel', 'send_or_queue_fallback', 'record_acknowledgement_state'],
			default=>['derive_notification_scope', 'apply_policy', 'record_delivery_result'],
		};
	}

	/**
	 * Returns negative checks for one notification control.
	 *
	 * @return array<int,string> Negative checks.
	 */
	private function app_builder_notification_communication_negative_checks(string $control_id): array {
		return match($control_id){
			'template_policy'=>['missing_template_rejected_or_safe_fallback_used', 'missing_required_variable_rejected', 'unsafe_template_preview_redacted'],
			'channel_policy'=>['disabled_channel_not_used', 'unknown_provider_rejected', 'provider_error_does_not_leak_secret'],
			'recipient_policy'=>['cross_scope_recipient_hidden_or_rejected', 'duplicate_recipient_not_sent_twice', 'unauthorized_recipient_address_not_exposed'],
			'preference_policy'=>['opted_out_recipient_not_sent', 'digest_frequency_respected', 'locale_timezone_fallback_safe'],
			'suppression_policy'=>['quiet_hours_hold_delivery', 'suppressed_until_blocks_send', 'duplicate_send_suppressed'],
			'delivery_receipt_policy'=>['bounce_or_failure_records_terminal_state', 'provider_message_id_not_treated_as_secret_value', 'retry_limit_prevents_infinite_delivery'],
			'escalation_communication_policy'=>['escalation_without_trigger_rejected', 'fallback_channel_missing_blocks_or_records_failure', 'acknowledgement_by_wrong_actor_rejected'],
			default=>['notification_scope_checked', 'secret_material_not_exposed'],
		};
	}

	/**
	 * Extracts notification/communication signals from task text.
	 *
	 * @param string $task User task text.
	 * @return array<int,string> Signal ids.
	 */
	private function app_builder_notification_communication_task_signals(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$map=[
			'templates'=>['template', 'templates', 'message body', 'subject line', 'localized message'],
			'channels'=>['notification', 'notifications', 'notify', 'notification channel', 'channels', 'email', 'sms', 'push', 'in-app notification'],
			'recipients'=>['recipient', 'recipients', 'send to', 'audience', 'distribution list'],
			'preferences'=>['preference', 'preferences', 'opt out', 'opt-out', 'digest', 'locale', 'timezone'],
			'suppression_or_quiet_hours'=>['suppression', 'suppress', 'quiet hours', 'hold until', 'do not disturb'],
			'delivery_receipts'=>['delivery receipt', 'delivery receipts', 'delivered', 'bounced', 'opened', 'provider message'],
			'escalation_communications'=>['escalation notification', 'escalation message', 'fallback channel', 'acknowledgement message'],
		];
		foreach($map as $signal=>$phrases){
			foreach($phrases as $phrase){
				if(str_contains($lower, $phrase)){
					$signals[]=$signal;
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Classifies entity names that usually carry notification/communication semantics.
	 *
	 * @param string $entity Entity name.
	 * @return string Category id or empty string.
	 */
	private function app_builder_notification_communication_entity_category(string $entity): string {
		$key=strtolower($this->slug_name($entity));
		return match(true){
			str_contains($key, 'notification') || str_contains($key, 'message')=>'channels',
			str_contains($key, 'template')=>'templates',
			str_contains($key, 'recipient') || str_contains($key, 'audience')=>'recipients',
			str_contains($key, 'preference') || str_contains($key, 'subscription')=>'preferences',
			str_contains($key, 'suppression') || str_contains($key, 'quiet')=>'suppression_or_quiet_hours',
			str_contains($key, 'receipt') || str_contains($key, 'delivery')=>'delivery_receipts',
			default=>'',
		};
	}

	/**
	 * Classifies notification/communication field names.
	 *
	 * @param string $name Field name.
	 * @return string Category key or empty string.
	 */
	private function app_builder_notification_communication_field_category(string $name): string {
		$name=strtolower(trim($name));
		return match(true){
			in_array($name, ['template_key', 'template_version', 'template_id', 'notification_template_id', 'subject_template', 'body_template', 'locale', 'variables_json'], true)=>'template_fields',
			in_array($name, ['channel', 'channel_type', 'provider_key', 'fallback_channel', 'send_via', 'webhook_url_ref'], true)=>'channel_fields',
			in_array($name, ['recipient_id', 'recipient_type', 'recipient_address', 'recipient_role', 'audience_scope', 'distribution_list_id'], true)=>'recipient_fields',
			in_array($name, ['preference_key', 'opted_out', 'digest_frequency', 'preferred_locale', 'preferred_timezone', 'subscription_status'], true)=>'preference_fields',
			in_array($name, ['suppressed_until', 'suppression_reason', 'quiet_hours_start', 'quiet_hours_end', 'quiet_hours_until', 'hold_until', 'dedupe_key'], true)=>'suppression_fields',
			in_array($name, ['sent_at', 'delivered_at', 'opened_at', 'bounced_at', 'failed_at', 'provider_message_id'], true)=>'delivery_receipt_fields',
			in_array($name, ['escalation_channel', 'escalation_trigger', 'escalation_acknowledged_at', 'next_channel', 'fallback_reason', 'acknowledgement_required'], true)=>'escalation_communication_fields',
			default=>'',
		};
	}

	/**
	 * Returns app-owned handling guidance for one notification/communication category.
	 *
	 * @param string $category Category key.
	 * @return string Policy token.
	 */
	private function app_builder_notification_communication_category_policy(string $category): string {
		return match($category){
			'template_fields'=>'version_templates_with_locale_variables_subject_body_and_preview_policy',
			'channel_fields'=>'define_channel_provider_fallback_and_disabled_channel_policy',
			'recipient_fields'=>'resolve_recipient_scope_dedupe_visibility_and_address_policy',
			'preference_fields'=>'honor_opt_out_digest_locale_timezone_and_subscription_policy',
			'suppression_fields'=>'apply_suppression_quiet_hours_hold_until_and_dedupe_policy',
			'delivery_receipt_fields'=>'track_send_delivery_open_bounce_failure_and_provider_message_state',
			'escalation_communication_fields'=>'model_escalation_notification_trigger_acknowledgement_and_fallback_policy',
			'templates'=>'keep_message_template_policy_app_owned',
			'channels'=>'keep_notification_channel_and_provider_adapter_policy_app_owned',
			'recipients'=>'keep_recipient_resolution_and_visibility_policy_app_owned',
			'preferences'=>'keep_notification_preference_policy_app_owned',
			'suppression_or_quiet_hours'=>'keep_suppression_quiet_hours_and_duplicate_policy_app_owned',
			'delivery_receipts'=>'keep_delivery_receipt_and_retry_state_app_owned',
			'escalation_communications'=>'keep_escalation_communication_policy_app_owned',
			default=>'decide_app_owned_notification_communication_policy',
		};
	}

	/**
	 * Returns the focused verification target for one notification/communication category.
	 *
	 * @param string $category Category key.
	 * @return string Verification focus token.
	 */
	private function app_builder_notification_communication_verification_focus(string $category): string {
		return match($category){
			'template_fields'=>'template_locale_variable_preview_and_missing_template_checks',
			'channel_fields'=>'channel_selection_fallback_provider_and_disabled_channel_checks',
			'recipient_fields'=>'recipient_resolution_scope_dedupe_and_visibility_checks',
			'preference_fields'=>'preference_opt_out_digest_locale_and_timezone_checks',
			'suppression_fields'=>'quiet_hours_suppression_hold_and_duplicate_checks',
			'delivery_receipt_fields'=>'delivery_receipt_failure_bounce_retry_and_provider_id_checks',
			'escalation_communication_fields'=>'escalation_notification_trigger_acknowledgement_and_fallback_checks',
			default=>'focused_notification_communication_checks',
		};
	}

	/**
	 * Builds compact security metadata for app-owned data-model columns.
	 *
	 * @param array<int,string> $columns Schema columns.
	 * @return array<string,mixed> Column security metadata.
	 */
	private function app_builder_column_security_metadata(array $columns): array {
		$columns_by_name=[];
		$categories=[];
		foreach($columns as $column){
			$column=(string)$column;
			if($column==='' || $column==='id'){
				continue;
			}
			$category=$this->app_builder_field_matches_sensitivity_category($column, 'credentials_or_secrets')
				? 'credentials_or_secrets'
				: $this->app_builder_field_sensitivity_category($column);
			if($category===''){
				continue;
			}
			$categories[$category]=true;
			$columns_by_name[$column]=[
				'category'=>$category,
				'action'=>$this->app_builder_sensitive_field_action($category),
			] + $this->app_builder_sensitive_category_policy($category);
		}
		$category_list=array_values(array_keys($categories));
		$category_policies=$this->app_builder_sensitive_category_policies($category_list);
		return [
			'has_sensitive_columns'=>$columns_by_name!==[],
			'columns'=>$columns_by_name,
			'categories'=>$category_list,
			'category_policies'=>$category_policies,
			'policy_metadata'=>$this->app_builder_sensitive_policy_metadata($category_list, $category_policies, false),
			'policy'=>$columns_by_name===[] ? 'No sensitive data-model columns were inferred.' : 'Apply app-owned storage, access, redaction, scope, and verification policy before writing TableSchema, Repository, or Record artifacts.',
		];
	}

	/**
	 * Builds compact relationship, scope, and external-id integrity metadata.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param array<string,mixed> $entity_planning Chunking/dependency metadata.
	 * @param array<int,array<string,mixed>> $planned_schema_context Optional full planned-schema context used only to classify target scope.
	 * @return array<string,mixed> Relationship integrity metadata.
	 */
	private function app_builder_relationship_integrity_metadata(array $schemas, array $entity_planning, array $planned_schema_context=[]): array {
		$planned_entities=[];
		foreach(($planned_schema_context!==[] ? $planned_schema_context : $schemas) as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$planned_entities[$this->app_builder_entity_key($entity)]=$entity;
			}
		}
		$incoming_dependencies=[];
		foreach(is_array($entity_planning['incoming_dependency_context']['dependencies'] ?? null) ? $entity_planning['incoming_dependency_context']['dependencies'] : [] as $dependency){
			if(is_array($dependency)){
				$incoming_dependencies[$this->app_builder_entity_key((string)($dependency['entity'] ?? '')).'|'.(string)($dependency['field'] ?? '')]=$dependency;
			}
		}
		$local_relationships=[];
		$external_references=[];
		$scope_fields=[];
		$external_identifier_fields=[];
		$dependency_scopes=[];
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
				if($name===''){
					continue;
				}
				if($this->app_builder_field_matches_sensitivity_category($name, 'tenant_or_access_scope')){
					$scope_fields[]=[
						'entity'=>$entity,
						'field'=>$name,
						'category'=>'tenant_or_access_scope',
						'repository_policy'=>'enforce_scope_in_app_repository_policy_and_regression_checks',
					];
				}
				if(($field['not_foreign_key'] ?? false)===true){
					$external_identifier_fields[]=[
						'entity'=>$entity,
						'field'=>$name,
						'policy'=>'external_identifier_not_local_relationship',
						'adapter_decision'=>'resolve_or_validate_with_app_owned_provider_boundary',
					];
				}
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$field=(string)($relationship['field'] ?? '');
				$target_entity=(string)($relationship['target_entity'] ?? '');
				$scope=isset($planned_entities[$this->app_builder_entity_key($target_entity)]) ? 'planned_entity' : 'external_reference';
				$incoming=$incoming_dependencies[$this->app_builder_entity_key($entity).'|'.$field] ?? null;
				if(is_array($incoming) && (string)($incoming['target_entity'] ?? '')===$target_entity){
					$scope=(string)($incoming['scope'] ?? $scope);
				}
				$row=[
					'entity'=>$entity,
					'field'=>$field,
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>$scope,
					'adapter_policy'=>'app_owned_repository_or_relation_adapter',
				];
				if($scope==='external_reference'){
					$external_references[]=$row + [
						'adapter_decision'=>'resolve_external_reference_through_consuming_app_access_policy',
					];
				}else{
					$local_relationships[]=$row + [
						'adapter_decision'=>'verify_local_foreign_key_lookup_and_permissions',
					];
				}
				if($scope!==''){
					$dependency_scopes[$scope]=($dependency_scopes[$scope] ?? 0)+1;
				}
			}
		}
		foreach(is_array($entity_planning['dependency_summary']['chunks'] ?? null) ? $entity_planning['dependency_summary']['chunks'] : [] as $chunk){
			if(!is_array($chunk)){
				continue;
			}
			foreach(is_array($chunk['dependencies'] ?? null) ? $chunk['dependencies'] : [] as $dependency){
				if(!is_array($dependency)){
					continue;
				}
				$scope=(string)($dependency['scope'] ?? '');
				if($scope!==''){
					$dependency_scopes[$scope]=($dependency_scopes[$scope] ?? 0)+1;
				}
			}
		}
		return [
			'owner'=>'consuming_application',
			'has_relationships'=>$local_relationships!==[] || $external_references!==[],
			'local_relationships'=>$local_relationships,
			'external_references'=>$external_references,
			'scope_fields'=>$scope_fields,
			'external_identifier_fields'=>$external_identifier_fields,
			'dependency_scopes'=>$dependency_scopes,
			'required_adapter_decisions'=>array_values(array_filter([
				$local_relationships!==[] ? 'verify_local_relationship_lookup_permission_and_empty_state' : '',
				$external_references!==[] ? 'resolve_external_references_through_app_owned_access_policy' : '',
				$scope_fields!==[] ? 'enforce_tenant_or_access_scope_in_repositories_and_tests' : '',
				$external_identifier_fields!==[] ? 'treat_not_foreign_key_identifiers_as_provider_or_external_ids' : '',
			])),
			'not_required'=>[
				'MCP/release-surface publication validation for ordinary relationship metadata',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Builds a compact field-level map for tenant scope and external identifiers.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Scope and identifier metadata.
	 */
	private function app_builder_scope_identifier_metadata(array $schemas): array {
		$scope_fields=[];
		$external_identifiers=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$relationship_by_field=[];
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(is_array($relationship) && isset($relationship['field'])){
					$relationship_by_field[(string)$relationship['field']]=$relationship;
				}
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$relationship=is_array($relationship_by_field[$name] ?? null) ? $relationship_by_field[$name] : [];
				if($this->app_builder_field_matches_sensitivity_category($name, 'tenant_or_access_scope')){
					$row=[
						'entity'=>$entity,
						'field'=>$name,
						'category'=>'tenant_or_access_scope',
						'policy'=>'app_owned_repository_scope_required',
						'verification_focus'=>'tenant_scope_and_cross_tenant_negative_checks',
					];
					if($relationship!==[]){
						$row['relationship_target_entity']=(string)($relationship['target_entity'] ?? '');
						$row['relationship_target_table']=(string)($relationship['target_table'] ?? '');
					}
					$scope_fields[]=$row;
				}
				if(($field['not_foreign_key'] ?? false)===true){
					$external_identifiers[]=[
						'entity'=>$entity,
						'field'=>$name,
						'identifier'=>$entity.'.'.$name,
						'policy'=>'external_identifier_not_local_relationship',
						'adapter_decision'=>'resolve_or_validate_with_app_owned_provider_boundary',
					];
				}
			}
		}
		return [
			'owner'=>'consuming_application',
			'scope_fields'=>$scope_fields,
			'external_identifiers'=>$external_identifiers,
			'has_scope_fields'=>$scope_fields!==[],
			'has_external_identifiers'=>$external_identifiers!==[],
			'policy'=>'Apply tenant/workspace/account scope in app-owned repositories and treat external/provider identifiers as adapter-resolved values, not local relationships.',
			'not_required'=>[
				'MCP/release-surface publication validation for ordinary scope/identifier metadata',
				'Dataphyre hot-path benchmark evidence',
			],
		];
	}

	/**
	 * Promotes data-model skeletons into the top-level app-builder write queue.
	 *
	 * @param array<string,mixed> $model Data-model artifact preview.
	 * @return array<int,array<string,mixed>> Code skeleton previews.
	 */
	private function app_builder_data_model_code_skeletons(array $model): array {
		$skeletons=[];
		$kind_by_path=[
			'/Schema/'=>'table_schema',
			'/Repository/'=>'table_repository',
			'/Record/'=>'table_record',
		];
		foreach(is_array($model['code_skeletons'] ?? null) ? $model['code_skeletons'] : [] as $skeleton){
			if(!is_array($skeleton)){
				continue;
			}
			$path=(string)($skeleton['path'] ?? '');
			$kind='data_model_artifact';
			foreach($kind_by_path as $needle=>$candidate){
				if(str_contains($path, $needle)){
					$kind=$candidate;
					break;
				}
			}
			$skeleton['kind']=$kind;
			$skeletons[]=$skeleton;
		}
		return $skeletons;
	}

	/**
	 * Builds focused verification guidance for app-builder outputs.
	 *
	 * @param array<int,string> $files Planned app-owned files.
	 * @param array<int,array<string,mixed>> $data_model Planned data-model artifacts.
	 * @param array<int,string> $tools Suggested verification tools.
	 * @return array<string,mixed> Ordered verification plan.
	 */
	private function app_builder_verification_plan(array $files, array $data_model, array $tools): array {
		$php_paths=[];
		$panel_suites=[];
		$code_unit_test_paths=[];
		foreach($files as $file){
			$file=(string)$file;
			if(str_ends_with($file, '.php')){
				$php_paths[]=$file;
			}
			if(str_ends_with($file, '.json') && str_contains($file, '/unit_tests/panel.')){
				$panel_suites[]=$file;
			}
			if(str_ends_with($file, '.test.php') && str_contains($file, '/backend/dataphyre/unit_tests/')){
				$code_unit_test_paths[]=$file;
			}
		}
		foreach($data_model as $model){
			if(!is_array($model)){
				continue;
			}
			foreach(($model['artifact_paths'] ?? []) as $path){
				$path=(string)$path;
				if(str_ends_with($path, '.php')){
					$php_paths[]=$path;
				}
			}
		}
		$steps=[];
		if(in_array('dataphyre_php_lint', $tools, true)){
			$steps[]=[
				'order'=>1,
				'tool'=>'dataphyre_php_lint',
				'when'=>'after writing app-owned PHP files and replacing placeholders with repo-local paths',
				'arguments'=>['paths'=>array_values(array_unique($php_paths))],
				'purpose'=>'Catch syntax errors in generated resources, manifests, schema, repository, and record classes.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_run_panel_field_catalog_check', $tools, true)){
			$steps[]=[
				'order'=>2,
				'tool'=>'dataphyre_run_panel_field_catalog_check',
				'when'=>'before or after app edits when Panel field/control compatibility is relevant',
				'arguments'=>(object)[],
				'purpose'=>'Verify the shared route-free Panel field catalog still supports the planned field/control types.',
				'requires_concrete_paths'=>false,
			];
		}
		if(in_array('dataphyre_run_panel_regression', $tools, true)){
			foreach($panel_suites as $suite){
				$steps[]=[
					'order'=>3,
					'tool'=>'dataphyre_run_panel_regression',
					'when'=>'after writing the focused Panel regression manifest',
					'arguments'=>[
						'example'=>false,
						'suite_path'=>$suite,
					],
					'purpose'=>'Run the route-free resource regression for the app-owned Panel suite.',
					'requires_concrete_paths'=>true,
				];
			}
			if($panel_suites===[]){
				$steps[]=[
					'order'=>3,
					'tool'=>'dataphyre_run_panel_regression',
					'when'=>'after creating a focused Panel regression manifest',
					'arguments'=>[
						'example'=>false,
						'suite_path'=>'applications/<app>/backend/dataphyre/unit_tests/panel.<resource>.json',
					],
					'purpose'=>'Run the route-free resource regression for the app-owned Panel suite.',
					'requires_concrete_paths'=>true,
				];
			}
		}
		if(in_array('dataphyre_route_manifest_read', $tools, true)){
			$steps[]=[
				'order'=>4,
				'tool'=>'dataphyre_route_manifest_read',
				'when'=>'after route declarations are registered and the app-owned compiled route manifest exists',
				'arguments'=>[
					'manifest_path'=>'applications/<app>/backend/dataphyre/cache/mvc_routes.php',
					'limit'=>50,
					'include_handlers'=>true,
					'include_middleware'=>true,
				],
				'purpose'=>'Read the compiled app-owned route manifest without dispatching handlers or bootstrapping the application.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_route_url_preview', $tools, true)){
			$steps[]=[
				'order'=>5,
				'tool'=>'dataphyre_route_url_preview',
				'when'=>'after named routes are present in a compiled manifest',
				'arguments'=>[
					'manifest_path'=>'applications/<app>/backend/dataphyre/cache/mvc_routes.php',
					'name'=>'<route.name>',
					'parameters'=>(object)[],
					'query'=>(object)[],
				],
				'purpose'=>'Preview a named route URL from a compiled manifest without dispatching handlers.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_route_match_preview', $tools, true)){
			$steps[]=[
				'order'=>6,
				'tool'=>'dataphyre_route_match_preview',
				'when'=>'after routes are compiled and a representative method/path pair is known',
				'arguments'=>[
					'manifest_path'=>'applications/<app>/backend/dataphyre/cache/mvc_routes.php',
					'method'=>'GET',
					'path'=>'/<route-path>',
				],
				'purpose'=>'Dry-match a route against the compiled manifest without dispatching middleware or controllers.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_route_source_static_summary', $tools, true)){
			$steps[]=[
				'order'=>7,
				'tool'=>'dataphyre_route_source_static_summary',
				'when'=>'before or after edits when route declaration source should be inspected without application bootstrap',
				'arguments'=>[
					'paths'=>['applications/<app>/backend/dataphyre/routes'],
					'limit'=>80,
				],
				'purpose'=>'Statically inspect app-owned route declarations without bootstrapping the app or dispatching handlers.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_api_docs_static_summary', $tools, true)){
			$steps[]=[
				'order'=>8,
				'tool'=>'dataphyre_api_docs_static_summary',
				'when'=>'before or after API endpoint edits when declarations should be inspected statically',
				'arguments'=>[
					'paths'=>['applications/<app>/backend/dataphyre'],
					'limit'=>80,
				],
				'purpose'=>'Inspect app-owned API declarations without bootstrapping the app, dispatching routes, or generating OpenAPI at runtime.',
				'requires_concrete_paths'=>true,
			];
		}
		if(in_array('dataphyre_openapi_static_contract_summary', $tools, true)){
			$steps[]=[
				'order'=>9,
				'tool'=>'dataphyre_openapi_static_contract_summary',
				'when'=>'when OpenAPI publishing or documentation behavior is part of the app-owned endpoint work',
				'arguments'=>(object)[],
				'purpose'=>'Review the static OpenAPI contract and publish surfaces without generating a runtime document.',
				'requires_concrete_paths'=>false,
			];
		}
		if(in_array('dataphyre_api_cache_static_summary', $tools, true)){
			$steps[]=[
				'order'=>10,
				'tool'=>'dataphyre_api_cache_static_summary',
				'when'=>'when endpoint cache, trace payload, or identity behavior is part of the app-owned API work',
				'arguments'=>(object)[],
				'purpose'=>'Review static API cache contracts without touching cache storage.',
				'requires_concrete_paths'=>false,
			];
		}
		if(in_array('app_local_php_unit_tests', $tools, true) && $code_unit_test_paths!==[]){
			$steps[]=[
				'order'=>11,
				'tool'=>'app_local_php_unit_tests',
				'when'=>'after adapting generated app PHP files and code-defined unit test skeletons',
				'arguments'=>[
					'paths'=>array_values(array_unique($code_unit_test_paths)),
					'runner'=>'consuming application local PHP unit-test command',
				],
				'purpose'=>'Run lightweight code-defined PHP tests for generated app resources, endpoints, or data-model contracts.',
				'requires_concrete_paths'=>true,
			];
		}
		$tables=[];
		$sql_config_paths=[];
		foreach($data_model as $model){
			if(is_array($model) && isset($model['table'])){
				$tables[]=(string)$model['table'];
				$sql_config_paths[(string)$model['table']]=(string)($model['sql_config_path'] ?? 'applications/<app>/backend/dataphyre/config/sql.php');
			}
		}
		foreach(array_values(array_unique($tables)) as $table){
			$steps[]=[
				'order'=>20,
				'tool'=>'dataphyre_sql_schema_read',
				'when'=>'after registering app SQL config/table definitions, when schema metadata should be inspected without database execution',
				'arguments'=>[
					'table'=>$table,
					'config_path'=>$sql_config_paths[$table] ?? 'applications/<app>/backend/dataphyre/config/sql.php',
					'include_create_sql'=>false,
				],
				'purpose'=>'Confirm app-owned TableDefinition/TableSchema metadata is discoverable without connecting to a database.',
				'requires_concrete_paths'=>true,
			];
		}
		usort($steps, static fn(array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
		$evidence=[];
		$verification_todo=[];
		foreach($steps as $step){
			$tool=(string)($step['tool'] ?? '');
			if($tool===''){
				continue;
			}
			$arguments=$step['arguments'] ?? (object)[];
			$requires_concrete_paths=($step['requires_concrete_paths'] ?? false)===true;
			$evidence[]=[
				'tool'=>$tool,
				'status'=>'pending_until_executed_by_agent',
				'capture'=>$this->app_builder_verification_evidence_capture($tool),
				'arguments'=>$arguments,
				'step_source'=>'verification_plan.steps['.count($verification_todo).']',
				'requires_concrete_paths'=>$requires_concrete_paths,
				'path_bound'=>$requires_concrete_paths,
			];
			$verification_todo[]=[
				'tool'=>$tool,
				'arguments'=>$arguments,
				'requires_concrete_paths'=>$requires_concrete_paths,
				'when'=>(string)($step['when'] ?? ''),
				'capture'=>$this->app_builder_verification_evidence_capture($tool),
			];
		}
		return [
			'policy'=>'focused_application_or_module_verification',
			'owner'=>'consuming_application',
			'execution'=>'not_executed',
			'evidence_to_collect'=>$evidence,
			'verification_todo'=>$verification_todo,
			'handoff'=>$this->app_builder_verification_handoff($evidence),
			'not_required'=>[
				'MCP/release-surface publication validation',
				'Dataphyre shared hot-path benchmark evidence',
				'maintainer-only runtime release proof',
			],
			'publication_validation_link'=>'dataphyre_mcp_readiness_report',
			'recovery_hints'=>[
				'path_placeholders'=>'Replace <app>, <resource>, and <app framework> placeholders with concrete consuming-application paths before running path-bound checks.',
				'missing_panel_behavior'=>'Use dataphyre_panel_scaffold_catalog, dataphyre_panel_documentation_catalog_summary, and focused Panel docs before changing Dataphyre runtime internals.',
				'missing_sql_metadata'=>'Use dataphyre_sql_schema_read and app-owned SQL config/TableSchema registration checks without opening a database connection.',
				'last_error_triage'=>'Use dataphyre_diagnostics_last_error only for redacted app/module diagnostic summaries after a failing focused check.',
				'escalation_boundary'=>'Do not use MCP/release-surface validation or maintainer-only proof for ordinary app-builder recovery unless the task becomes MCP/release-surface, public framework, or shared hot-path work.',
			],
			'steps'=>$steps,
		];
	}

	/**
	 * Builds a compact copy-safe handoff template for focused verification.
	 *
	 * @param array<int,array<string,mixed>> $evidence Focused evidence items.
	 * @return array<string,mixed> Verification handoff guidance.
	 */
	private function app_builder_verification_handoff(array $evidence): array {
		$tools=[];
		foreach($evidence as $item){
			if(is_array($item) && ($item['tool'] ?? '')!==''){
				$tools[]=(string)$item['tool'];
			}
		}
		$tools=array_values(array_unique($tools));
		return [
			'owner'=>'consuming_application',
			'status'=>'pending_until_focused_checks_run',
			'purpose'=>'Copy-safe completion evidence for ordinary app work after concrete app-owned verification runs.',
			'tools'=>$tools,
			'copy_safe_fields'=>[
				'tool',
				'concrete_app_paths_or_arguments',
				'pass_fail_summary',
				'failing_check_names_when_any',
				'diagnostic_summary.copy_safe_evidence_when_focused_check_failed',
				'follow_up_app_owned_edits_when_any',
			],
			'not_included'=>[
				'raw full logs',
				'secrets, tokens, cookies, auth headers, or signed URLs',
				'tenant/customer/product identifiers unless already public test fixtures',
				'maintainer/source-checkout release proof',
				'Dataphyre hot-path benchmark output',
			],
			'post_write_handoff_template'=>[
				'changed_app_owned_files'=>'List concrete app-owned files created or edited from builder_response.implementation_recipe.items.',
				'local_conventions_applied'=>'Summarize observed local conventions from builder_response.local_convention_probe.items and how they were applied.',
				'focused_checks'=>'For each tool, include concrete app paths or arguments, pass/fail summary, and failing check names when any.',
				'acceptance_review'=>'Summarize builder_response.acceptance_review_plan.items as pass/fail/not-run with app-owned evidence.',
				'unresolved_app_followups'=>'List only remaining app-owned edits, decisions, or focused checks.',
				'not_release_proof'=>'This handoff is ordinary app completion evidence, not MCP/package release validation or Dataphyre hot-path proof.',
			],
			'focused_completion_packet'=>[
				'status'=>'ready_to_fill_after_focused_checks',
				'required_fields'=>[
					'changed_app_owned_files',
					'checks',
					'acceptance_review',
					'diagnostic_evidence_when_failed',
					'remaining_app_followups',
					'not_release_proof',
				],
				'check_item_shape'=>[
					'tool',
					'concrete_app_paths_or_arguments',
					'status',
					'pass_fail_summary',
					'failing_check_names_when_any',
				],
				'failure_evidence'=>'When a focused check fails, include diagnostic_summary.copy_safe_evidence_when_focused_check_failed only after redacting identifiers that are not public test fixtures.',
				'completion_decision'=>[
					'ready_to_share_when'=>'Every required field is present, every focused check has status passed or failed with pass_fail_summary, failures include copy-safe diagnostic evidence or an app-owned follow-up, and not_release_proof=true.',
					'incomplete_when'=>[
						'missing changed_app_owned_files',
						'any check lacks concrete_app_paths_or_arguments',
						'any check lacks pass_fail_summary',
						'failed check lacks diagnostic_evidence_when_failed and remaining_app_followups',
						'acceptance_review is missing or all items are not-run',
					],
					'status_values'=>['ready_to_share', 'incomplete_missing_evidence', 'failed_with_app_followups'],
					'next_action_by_status'=>[
						'ready_to_share'=>'Share the focused_completion_packet as ordinary app completion evidence.',
						'incomplete_missing_evidence'=>'Run or summarize the missing focused app/module checks before closing the task.',
						'failed_with_app_followups'=>'Use verification_recovery_plan and diagnostic_summary.copy_safe_evidence to make app-owned fixes, then rerun focused checks.',
					],
				],
				'not_release_proof'=>true,
			],
			'done_when'=>'Every path-bound verification item has concrete app-owned arguments and every focused check has a pass/fail summary.',
			'escalate_only_for'=>'MCP/release-surface claims, public Dataphyre framework claims, security/governance-sensitive claims, or Dataphyre shared production hot-path changes.',
		];
	}

	/**
	 * Builds compact app-owned fixture and negative-case guidance for focused tests.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param array<string,mixed> $relationship_contract_summary Relationship metadata.
	 * @param array<string,mixed> $data_sensitivity_summary Sensitivity metadata.
	 * @param array<string,mixed> $lifecycle_policy_summary Lifecycle metadata.
	 * @param array<string,mixed> $tenant_identity_handoff Tenant/actor/entitlement handoff metadata.
	 * @return array<string,mixed> Copy-safe fixture handoff.
	 */
	private function app_builder_verification_fixture_handoff(array $schemas, array $relationship_contract_summary, array $data_sensitivity_summary, array $lifecycle_policy_summary, array $tenant_identity_handoff=[]): array {
		$fixtures=[];
		$relationship_cases=[];
		$negative_cases=[];
		$lifecycle_cases=[];
		$tenant_identity_cases=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			if($entity==='' || $table===''){
				continue;
			}
			$fields=is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
			$fixture_fields=['id'=>'<'.$table.'_id>'];
			foreach($fields as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name==='' || $name==='id'){
					continue;
				}
				$required=($field['required'] ?? false)===true;
				$has_default=array_key_exists('default', $field);
				$target=trim((string)($field['foreign_key_target'] ?? ''));
				if(!$required && !$has_default && $target===''){
					continue;
				}
				if($target!==''){
					$target_table=str_replace('-', '_', $this->slug_name($target));
					$fixture_fields[$name]='<'.$target_table.'_id>';
					$relationship_cases[]=[
						'id'=>strtolower($table.'_'.$name.'_relationship_options'),
						'entity'=>$entity,
						'field'=>$name,
						'target'=>$target,
						'positive'=>'Use a fixture where '.$name.' points to an allowed '.$target.' record.',
						'negative'=>'Use a fixture where '.$name.' points to a missing or out-of-scope '.$target.' record and assert the app-owned adapter rejects or hides it.',
					];
					continue;
				}
				$fixture_fields[$name]=$this->app_builder_fixture_placeholder_value($field, $table);
			}
			foreach(['tenant_id', 'workspace_id'] as $scope_field){
				if(array_key_exists($scope_field, $fixture_fields)){
					$negative_cases[]=[
						'id'=>strtolower($table.'_'.$scope_field.'_cross_scope_negative'),
						'entity'=>$entity,
						'field'=>$scope_field,
						'fixture_a'=>$scope_field==='tenant_id' ? '<tenant_a>' : '<workspace_a>',
						'fixture_b'=>$scope_field==='tenant_id' ? '<tenant_b>' : '<workspace_b>',
						'assert'=>'A record from fixture_b is not visible, selectable, editable, or returned when the app context is fixture_a.',
					];
					break;
				}
			}
			foreach($fields as $field){
				if(!is_array($field)){
					continue;
				}
				$name=(string)($field['name'] ?? '');
				if($name==='' || !in_array($name, ['status', 'stage', 'state', 'decision', 'priority'], true)){
					continue;
				}
				$default=(string)($field['default'] ?? 'default');
				$lifecycle_cases[]=[
					'id'=>strtolower($table.'_'.$name.'_lifecycle_default'),
					'entity'=>$entity,
					'field'=>$name,
					'default'=>$default,
					'positive'=>'Create a fixture with '.$name.'='.$default.' and assert default filters/actions behave as intended.',
					'negative'=>'Use an invalid or terminal '.$name.' transition fixture and assert app-owned actions reject it.',
				];
			}
			$fixtures[]=[
				'id'=>strtolower($table.'_baseline'),
				'entity'=>$entity,
				'table'=>$table,
				'purpose'=>'Minimal copy-safe app-owned fixture for focused Panel/API/repository checks.',
				'fields'=>$fixture_fields,
			];
			if(count($fixtures)>=6){
				break;
			}
		}
		$sensitivity_categories=array_values(array_map('strval', is_array($data_sensitivity_summary['categories'] ?? null) ? $data_sensitivity_summary['categories'] : []));
		if(in_array('identity_or_contact', $sensitivity_categories, true) || in_array('credentials_or_secrets', $sensitivity_categories, true)){
			$negative_cases[]=[
				'id'=>'sensitive_field_exposure_negative',
				'entity'=>'<entity_with_sensitive_field>',
				'field'=>'<sensitive_field>',
				'assert'=>'Sensitive fields are omitted, masked, write-only, or permission-gated in table output, filters, relationship labels, and diagnostics.',
			];
		}
		if(($tenant_identity_handoff['status'] ?? null)==='ready_for_app_owned_tenant_identity_design'){
			$scope_fields=array_values(array_map('strval', is_array($tenant_identity_handoff['tenant_scope']['fields'] ?? null) ? $tenant_identity_handoff['tenant_scope']['fields'] : []));
			$scope_checks=array_values(array_map('strval', is_array($tenant_identity_handoff['tenant_scope']['negative_checks'] ?? null) ? $tenant_identity_handoff['tenant_scope']['negative_checks'] : []));
			if($scope_fields!==[]){
				$tenant_identity_cases[]=[
					'id'=>'tenant_identity_scope_negative',
					'fields'=>$scope_fields,
					'positive'=>'Use a fixture where the authenticated actor scope matches the record scope.',
					'negative'=>'Use a fixture where actor scope and record scope differ and assert the app-owned query, relation, action, export, and API surfaces reject or hide it.',
					'checks'=>$scope_checks,
				];
			}
			$ownership_fields=array_values(array_map('strval', is_array($tenant_identity_handoff['actor_identity']['ownership_fields'] ?? null) ? $tenant_identity_handoff['actor_identity']['ownership_fields'] : []));
			$access_fields=array_values(array_map('strval', is_array($tenant_identity_handoff['actor_identity']['access_fields'] ?? null) ? $tenant_identity_handoff['actor_identity']['access_fields'] : []));
			$actor_checks=array_values(array_map('strval', is_array($tenant_identity_handoff['actor_identity']['negative_checks'] ?? null) ? $tenant_identity_handoff['actor_identity']['negative_checks'] : []));
			if($ownership_fields!==[] || $access_fields!==[]){
				$tenant_identity_cases[]=[
					'id'=>'tenant_identity_actor_permission_negative',
					'ownership_fields'=>$ownership_fields,
					'access_fields'=>$access_fields,
					'positive'=>'Use a fixture where the authenticated actor owns the record or has an app-defined role/permission in the current scope.',
					'negative'=>'Use a fixture with a spoofed owner field, revoked policy reference, or role without permission and assert every app-owned surface denies privileged access.',
					'checks'=>$actor_checks,
				];
			}
			$billing_or_plan_fields=array_values(array_map('strval', is_array($tenant_identity_handoff['entitlement_context']['billing_or_plan_fields'] ?? null) ? $tenant_identity_handoff['entitlement_context']['billing_or_plan_fields'] : []));
			$business_controls=array_values(array_map('strval', is_array($tenant_identity_handoff['entitlement_context']['business_controls'] ?? null) ? $tenant_identity_handoff['entitlement_context']['business_controls'] : []));
			$entitlement_checks=array_values(array_map('strval', is_array($tenant_identity_handoff['entitlement_context']['negative_checks'] ?? null) ? $tenant_identity_handoff['entitlement_context']['negative_checks'] : []));
			if($billing_or_plan_fields!==[] || $business_controls!==[]){
				$tenant_identity_cases[]=[
					'id'=>'tenant_identity_entitlement_negative',
					'billing_or_plan_fields'=>$billing_or_plan_fields,
					'business_controls'=>$business_controls,
					'positive'=>'Use a fixture where the app-owned plan, subscription, entitlement, quota, or commercial policy allows the gated action.',
					'negative'=>'Use a fixture with a missing, expired, wrong-scope, or over-quota entitlement and assert the gated action is denied before mutation, export, notification, or billing-sensitive output.',
					'checks'=>$entitlement_checks,
				];
			}
		}
		$relationship_cases=array_slice($relationship_cases, 0, 6);
		$negative_cases=array_slice($negative_cases, 0, 6);
		$lifecycle_cases=array_slice($lifecycle_cases, 0, 6);
		$tenant_identity_cases=array_slice($tenant_identity_cases, 0, 3);
		return [
			'owner'=>'consuming_application',
			'status'=>$fixtures===[] ? 'no_fixture_handoff_needed' : 'ready_for_app_owned_focused_tests',
			'purpose'=>'Copy-safe fixture and negative-case hints for ordinary app-owned focused verification.',
			'fixtures'=>$fixtures,
			'relationship_cases'=>$relationship_cases,
			'lifecycle_cases'=>$lifecycle_cases,
			'negative_cases'=>$negative_cases,
			'tenant_identity_cases'=>$tenant_identity_cases,
			'links'=>[
				'verification_execution_plan'=>'builder_response.verification_execution_plan.items',
				'acceptance_review_plan'=>'builder_response.acceptance_review_plan.items',
				'relationship_adapters'=>'builder_response.relationship_adapter_handoff.adapters',
				'lifecycle_policy'=>'builder_response.lifecycle_policy_summary',
				'sensitivity_policy'=>'builder_response.data_sensitivity_summary',
				'tenant_identity'=>'builder_response.tenant_identity_handoff',
			],
			'policy'=>'Use placeholders and synthetic app-owned fixtures only; do not copy production tenant/customer/product identifiers into MCP responses or tests.',
			'not_required'=>[
				'seed scripts as a release artifact',
				'framework/release escalation for ordinary app fixtures',
			],
		];
	}

	/**
	 * Produces a copy-safe placeholder value for a fixture field.
	 *
	 * @param array<string,mixed> $field Schema field.
	 * @param string $table Table name.
	 * @return mixed Placeholder value.
	 */
	private function app_builder_fixture_placeholder_value(array $field, string $table): mixed {
		$name=(string)($field['name'] ?? 'value');
		if(array_key_exists('default', $field)){
			return $field['default'];
		}
		return match((string)($field['type'] ?? 'string')){
			'integer'=>'<'.$name.'_integer>',
			'boolean'=>false,
			'datetime'=>'<'.$name.'_iso_datetime>',
			'date'=>'<'.$name.'_iso_date>',
			'json', 'jsonb'=>['example'=>'<'.$table.'_'.$name.'_json>'],
			'text'=>'Example '.$name,
			default=>'Example '.$name,
		};
	}

	/**
	 * Builds a compact ordered verification execution plan for app agents.
	 *
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @param array<string,mixed> $implementation_recipe File edit recipe.
	 * @param array<string,mixed> $verification_recovery_plan Focused recovery branches.
	 * @return array<string,mixed> Verification execution plan.
	 */
	private function app_builder_verification_execution_plan(array $verification_plan, array $implementation_recipe, array $verification_recovery_plan): array {
		$recovery_by_tool=[];
		foreach(is_array($verification_recovery_plan['branches'] ?? null) ? $verification_recovery_plan['branches'] : [] as $branch){
			if(is_array($branch) && ($branch['tool'] ?? '')!==''){
				$recovery_by_tool[(string)$branch['tool']]=$this->app_builder_verification_recovery_branch_pointer((string)$branch['tool']);
			}
		}
		$recipe_paths_by_tool=[];
		foreach(is_array($implementation_recipe['items'] ?? null) ? $implementation_recipe['items'] : [] as $item){
			if(!is_array($item) || ($item['path'] ?? '')===''){
				continue;
			}
			$path=(string)$item['path'];
			foreach(is_array($item['verification_tools'] ?? null) ? $item['verification_tools'] : [] as $tool){
				$tool=(string)$tool;
				if($tool!==''){
					$recipe_paths_by_tool[$tool][$path]=true;
				}
			}
		}
		$items=[];
		foreach(is_array($verification_plan['verification_todo'] ?? null) ? $verification_plan['verification_todo'] : [] as $todo){
			if(!is_array($todo) || ($todo['tool'] ?? '')===''){
				continue;
			}
			$tool=(string)$todo['tool'];
			$item=[
				'order'=>count($items)+1,
				'tool'=>$tool,
				'arguments'=>$todo['arguments'] ?? (object)[],
				'requires_concrete_paths'=>($todo['requires_concrete_paths'] ?? false)===true,
				'when'=>(string)($todo['when'] ?? ''),
				'capture'=>(string)($todo['capture'] ?? $this->app_builder_verification_evidence_capture($tool)),
				'copy_safe_result_fields'=>[
					'tool',
					'concrete_app_paths_or_arguments',
					'pass_fail_summary',
					'failing_check_names_when_any',
					'follow_up_app_owned_edits_when_any',
				],
			];
			$related_paths=array_values(array_keys($recipe_paths_by_tool[$tool] ?? []));
			if($related_paths!==[]){
				$item['related_recipe_paths']=array_slice($related_paths, 0, 8);
			}
			if(isset($recovery_by_tool[$tool])){
				$item['failure_branch']=$recovery_by_tool[$tool];
			}
			$items[]=$item;
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$items===[] ? 'no_focused_verification_planned' : 'ready_after_app_owned_writes',
			'execution'=>'not_executed',
			'purpose'=>'Ordered focused verification tool-call plan for ordinary app edits after concrete app-owned files are written.',
			'run_after'=>'Complete implementation_recipe.items, replace placeholder paths, and resolve write_readiness blockers before executing path-bound checks.',
			'items'=>$items,
			'copy_safe_handoff'=>'builder_response.verification_handoff',
			'failure_recovery'=>'builder_response.verification_recovery_plan',
			'not_required'=>[
				'dataphyre_mcp_verify_all for ordinary app behavior',
				'source-checkout dev tools for ordinary app verification',
				'MCP/release-surface validation for ordinary app verification',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Builds the compact diagnostic recovery branch for failed focused checks.
	 *
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @return array<string,mixed> Copy-safe diagnostic handoff hint.
	 */
	private function app_builder_diagnostic_handoff_hint(array $verification_plan): array {
		return [
			'owner'=>'consuming_application',
			'status'=>'use_only_after_failing_focused_app_or_module_check',
			'trigger'=>'A focused app/module check from builder_response.verification_evidence fails or needs redacted last-error context.',
			'tool'=>'dataphyre_diagnostics_last_error',
			'copy_safe_source'=>'diagnostic_summary.copy_safe_evidence',
			'pair_with'=>'builder_response.verification_handoff',
			'recovery_hint'=>(string)($verification_plan['recovery_hints']['last_error_triage'] ?? 'Use redacted app/module diagnostic summaries after a failing focused check.'),
			'copy_safe_fields'=>[
				'diagnostic_summary.copy_safe_evidence',
				'diagnostic_next_action',
				'verification_handoff',
				'follow_up_app_owned_edits_when_any',
			],
			'not_included'=>[
				'raw logs',
				'unredacted snippets',
				'secrets, tokens, cookies, auth headers, or signed URLs',
				'tenant/customer/product identifiers unless already public test fixtures',
				'maintainer/source-checkout release proof',
				'Dataphyre benchmark output',
			],
			'not_required'=>[
				'dataphyre_mcp_verify_all for ordinary app recovery',
				'MCP/release-surface validation after ordinary focused-check failures',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Builds per-check recovery branches for focused app verification failures.
	 *
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @return array<string,mixed> Recovery plan for ordinary app agents.
	 */
	private function app_builder_verification_recovery_plan(array $verification_plan): array {
		$branches=[];
		foreach(is_array($verification_plan['verification_todo'] ?? null) ? $verification_plan['verification_todo'] : [] as $todo){
			if(!is_array($todo)){
				continue;
			}
			$tool=(string)($todo['tool'] ?? '');
			if($tool===''){
				continue;
			}
			$branches[]=$this->app_builder_verification_recovery_branch($tool, ($todo['requires_concrete_paths'] ?? false)===true);
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$branches===[] ? 'no_focused_checks_planned' : 'ready_for_focused_check_failures',
			'purpose'=>'Per-check recovery branches for ordinary app verification failures without opening governance, release validation, raw logs, or Dataphyre internals.',
			'branches'=>$branches,
			'diagnostic_tool'=>'dataphyre_diagnostics_last_error',
			'copy_safe_source'=>'diagnostic_summary.copy_safe_evidence',
			'pair_with'=>'builder_response.verification_handoff',
			'not_required'=>[
				'dataphyre_mcp_verify_all for ordinary app recovery',
				'MCP/release-surface validation after focused app-check failures',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				'raw log sharing',
			],
		];
	}

	/**
	 * Builds one recovery branch for a focused verification tool.
	 */
	private function app_builder_verification_recovery_branch(string $tool, bool $requires_concrete_paths): array {
		$branch=[
			'tool'=>$tool,
			'when'=>'If this focused app/module check fails after app-owned writes.',
			'requires_concrete_paths'=>$requires_concrete_paths,
			'copy_safe_evidence'=>['tool', 'concrete_app_paths_or_arguments', 'pass_fail_summary', 'failing_check_names_when_any'],
			'copy_safe_failure_handoff'=>[
				'failed_tool'=>$tool,
				'app_owned_scope'=>'concrete_app_paths_or_arguments from the failed focused check',
				'focused_result_summary'=>'short pass/fail summary plus failing check names only',
				'diagnostic_evidence'=>'diagnostic_summary.copy_safe_evidence only when focused output is insufficient',
				'next_app_owned_edit'=>'one app-owned file/config/test area to inspect or change next',
				'rerun'=>'rerun this same focused tool before broadening diagnostics',
				'not_included'=>['raw logs', 'unredacted snippets', 'secrets', 'tenant/customer/product identifiers', 'release validation output'],
			],
			'diagnostic_next'=>'Use dataphyre_diagnostics_last_error only when the focused check output is not enough; copy diagnostic_summary.copy_safe_evidence, not raw logs.',
			'not_required'=>['MCP/release-surface validation', 'Dataphyre runtime-internal edits for one application failure'],
		];
		switch($tool){
			case 'dataphyre_php_lint':
				$branch['likely_app_owned_fix']='Correct syntax, namespace, imports, class names, or generated skeleton adaptation in app-owned PHP files.';
				$branch['next_reads']=['Open the failing app-owned PHP file and compare namespaces/class names with builder_response.naming_contract.'];
				break;
			case 'dataphyre_run_panel_field_catalog_check':
				$branch['likely_app_owned_fix']='Adjust app-owned Panel field/control metadata, options/defaults, or unsupported control mappings.';
				$branch['next_reads']=['builder_response.field_metadata_summary', 'builder_response.implementation_matrix.work_items[field_metadata]'];
				break;
			case 'dataphyre_run_panel_regression':
				$branch['likely_app_owned_fix']='Fix app-owned Panel resource, manifest, relationship adapter, filters, actions, or route-free regression manifest.';
				$branch['next_reads']=['builder_response.relationship_contract_summary', 'builder_response.implementation_matrix', 'builder_response.verification_handoff'];
				break;
			case 'app_local_php_unit_tests':
				$branch['likely_app_owned_fix']='Fix app-owned generated PHP resources, handlers, data-model artifacts, or the lightweight test skeleton assertions.';
				$branch['next_reads']=['builder_response.code_skeleton_summary.paths_by_kind', 'builder_response.implementation_recipe.items', 'builder_response.verification_handoff'];
				break;
			case 'dataphyre_sql_schema_read':
				$branch['likely_app_owned_fix']='Fix app-owned SQL config registration, TableSchema metadata, repository/table naming, or required/index/foreign-key hints.';
				$branch['next_reads']=['builder_response.data_integrity_summary', 'builder_response.code_skeleton_summary.paths_by_kind.table_schema'];
				break;
			case 'dataphyre_route_manifest_read':
			case 'dataphyre_route_url_preview':
			case 'dataphyre_route_match_preview':
				$branch['likely_app_owned_fix']='Fix app-owned route manifest/source declarations, route names, parameters, middleware metadata, or URL/match expectations without dispatching handlers.';
				$branch['next_reads']=['builder_response.surface_execution_plan', 'focused_context.docs'];
				break;
			case 'dataphyre_api_docs_static_summary':
			case 'dataphyre_openapi_static_contract_summary':
			case 'dataphyre_api_cache_static_summary':
				$branch['likely_app_owned_fix']='Fix app-owned API endpoint declarations, static docs contract metadata, cache key contract, or OpenAPI-facing annotations without runtime publication validation.';
				$branch['next_reads']=['builder_response.companion_surface_handoff', 'builder_response.endpoint_policy_metadata'];
				break;
			default:
				$branch['likely_app_owned_fix']='Inspect the focused check result, adjust app-owned code/config/tests, then rerun the same focused check.';
				$branch['next_reads']=['builder_response.verification_todo', 'builder_response.diagnostic_handoff_hint'];
				break;
		}
		return $branch;
	}

	/**
	 * Returns a dereferenceable-style pointer to a tool-specific recovery branch.
	 *
	 * @param string $tool Focused verification tool name.
	 * @return string Branch pointer for app-agent handoffs.
	 */
	private function app_builder_verification_recovery_branch_pointer(string $tool): string {
		return 'builder_response.verification_recovery_plan.branches where tool='.$tool;
	}

	/**
	 * Describes the focused evidence an app agent should keep from a verification tool.
	 *
	 * @param string $tool Verification tool name.
	 * @return string Concise evidence description.
	 */
	private function app_builder_verification_evidence_capture(string $tool): string {
		return match($tool){
			'dataphyre_php_lint'=>'Keep the lint command, concrete app-owned paths, and pass/fail output summary.',
			'dataphyre_run_panel_field_catalog_check'=>'Keep the catalog check result and any unsupported field/control type names.',
			'dataphyre_run_panel_regression'=>'Keep the suite path, route-free regression result, and failing check names if any.',
			'app_local_php_unit_tests'=>'Keep the app-local PHP test command, concrete *.test.php paths, pass/fail summary, and failing test names if any.',
			'dataphyre_sql_schema_read'=>'Keep the table name, config path, and metadata fields observed without database execution.',
			'dataphyre_route_manifest_read'=>'Keep the manifest path, route count, named route presence, handler summary, and middleware summary without dispatch output.',
			'dataphyre_route_url_preview'=>'Keep the manifest path, route name, concrete parameters, and generated URL preview without HTTP requests.',
			'dataphyre_route_match_preview'=>'Keep the manifest path, method/path pair, matched route name, and route params without dispatching handlers.',
			'dataphyre_route_source_static_summary'=>'Keep the inspected route source paths, declaration count, and ambiguity summary without application bootstrap.',
			'dataphyre_api_docs_static_summary'=>'Keep inspected API source paths, endpoint count, method/path declarations, and missing-doc notes without runtime OpenAPI generation.',
			'dataphyre_openapi_static_contract_summary'=>'Keep the static OpenAPI contract fields and publish-surface summary without generating runtime documents.',
			'dataphyre_api_cache_static_summary'=>'Keep cache key, trace payload, identity, and clear-cache contract summaries without touching cache storage.',
			default=>'Keep the focused app/module check name, concrete arguments, and pass/fail output summary.',
		};
	}

	/**
	 * Builds app-owned acceptance criteria for generated builder plans.
	 *
	 * @param array<int,string> $files Planned app-owned files.
	 * @param array<int,array<string,mixed>> $data_model Planned data-model artifacts.
	 * @return array<int,string> Done criteria.
	 */
	private function app_builder_acceptance_criteria(array $files, array $data_model): array {
		$criteria=[
			'App-owned Panel resource files and manifests are created or updated using local application conventions.',
			'Panel fields, table columns, filters, actions, and relation hints match the planned schema.',
			'Focused Panel regression manifests exist for each planned resource and run route-free.',
			'Focused application/module verification in verification_plan passes for the concrete app paths.',
			'No Dataphyre runtime internals are modified unless the task explicitly escalates to framework work.',
		];
		if($data_model!==[]){
			$criteria[]='App Framework TableSchema, Repository, and Record artifacts are adapted to the consuming app namespace and SQL config registration.';
		}
		foreach($files as $file){
			if(str_contains((string)$file, '<app>')){
				$criteria[]='All placeholder paths such as <app> and <app framework> are replaced with real app-owned paths before verification.';
				break;
			}
		}
		foreach($files as $file){
			$file=(string)$file;
			if(str_contains($file, '/backend/dataphyre/unit_tests/') && str_ends_with($file, '.test.php')){
				$criteria[]='Lightweight PHP unit test skeletons exist under app-owned backend/dataphyre/unit_tests and run with the consuming application local PHP test command.';
				break;
			}
		}
		return array_values(array_unique($criteria));
	}

	/**
	 * Builds a compact acceptance review plan for ordinary app completion checks.
	 *
	 * @param array<int,string> $criteria Done criteria.
	 * @param array<string,mixed> $implementation_recipe File edit recipe.
	 * @param array<string,mixed> $verification_execution_plan Focused verification execution plan.
	 * @param array<string,mixed> $write_readiness Write readiness summary.
	 * @return array<string,mixed> Acceptance review plan.
	 */
	private function app_builder_acceptance_review_plan(array $criteria, array $implementation_recipe, array $verification_execution_plan, array $write_readiness): array {
		$recipe_paths=[];
		$obligations_by_id=[];
		foreach(is_array($implementation_recipe['items'] ?? null) ? $implementation_recipe['items'] : [] as $item){
			if(is_array($item) && ($item['path'] ?? '')!==''){
				$path=(string)$item['path'];
				$recipe_paths[]=$path;
				foreach(is_array($item['obligation_ids'] ?? null) ? $item['obligation_ids'] : [] as $obligation_id){
					$obligation_id=(string)$obligation_id;
					if($obligation_id===''){
						continue;
					}
					$obligations_by_id[$obligation_id]['paths'][$path]=true;
					foreach(is_array($item['verification_tools'] ?? null) ? $item['verification_tools'] : [] as $tool){
						$tool=(string)$tool;
						if($tool!==''){
							$obligations_by_id[$obligation_id]['verification_tools'][$tool]=true;
						}
					}
				}
			}
		}
		$verification_tools=[];
		foreach(is_array($verification_execution_plan['items'] ?? null) ? $verification_execution_plan['items'] : [] as $item){
			if(is_array($item) && ($item['tool'] ?? '')!==''){
				$verification_tools[]=(string)$item['tool'];
			}
		}
		$items=[];
		foreach(array_values(array_filter(array_map('strval', $criteria), static fn(string $criterion): bool => $criterion!=='')) as $criterion){
			$lower=strtolower($criterion);
			$evidence=['builder_response.implementation_recipe.items'];
			if(str_contains($lower, 'verification') || str_contains($lower, 'regression') || str_contains($lower, 'route-free')){
				$evidence[]='builder_response.verification_execution_plan.items';
				$evidence[]='builder_response.verification_handoff';
			}
			if(str_contains($lower, 'schema') || str_contains($lower, 'fields') || str_contains($lower, 'columns') || str_contains($lower, 'relation')){
				$evidence[]='builder_response.field_metadata_summary';
				$evidence[]='builder_response.relationship_adapter_handoff';
			}
			if(str_contains($lower, 'placeholder') || str_contains($lower, '<app>')){
				$evidence[]='builder_response.app_path_context';
				$evidence[]='builder_response.prewrite_checklist.prewrite_blockers';
			}
			if(str_contains($lower, 'runtime internals')){
				$evidence[]='builder_response.extension_boundary_summary';
			}
			$items[]=[
				'criterion'=>$criterion,
				'status'=>'pending_until_agent_reviews_after_writes',
				'evidence_sources'=>array_values(array_unique($evidence)),
				'related_paths'=>array_slice($recipe_paths, 0, 8),
				'verification_tools'=>array_values(array_unique($verification_tools)),
			];
		}
		$obligation_review_items=[];
		foreach($obligations_by_id as $obligation_id=>$obligation_context){
			$obligation_review_items[]=[
				'obligation_id'=>$obligation_id,
				'status'=>'pending_until_agent_reviews_after_writes',
				'review'=>$this->app_builder_acceptance_obligation_review_text((string)$obligation_id),
				'evidence_sources'=>$this->app_builder_acceptance_obligation_evidence_sources((string)$obligation_id),
				'related_paths'=>array_slice(array_values(array_keys(is_array($obligation_context['paths'] ?? null) ? $obligation_context['paths'] : [])), 0, 8),
				'verification_tools'=>array_values(array_keys(is_array($obligation_context['verification_tools'] ?? null) ? $obligation_context['verification_tools'] : [])),
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$items===[] ? 'no_acceptance_review_needed' : 'ready_for_post_write_review',
			'purpose'=>'Compact done-review checklist tying acceptance criteria to app-owned edits, focused verification, and copy-safe handoff evidence.',
			'run_after'=>'Complete implementation_recipe.items, resolve write_readiness blockers, and run verification_execution_plan.items.',
			'items'=>$items,
			'obligation_review_items'=>$obligation_review_items,
			'copy_safe_handoff'=>'builder_response.verification_handoff plus a short criterion-by-criterion pass/fail summary',
			'post_write_handoff_template'=>[
				'changed_app_owned_files'=>'Use builder_response.implementation_recipe.items and the actual edit list.',
				'focused_verification'=>'Use builder_response.verification_handoff and verification_execution_plan.items.',
				'acceptance_results'=>'Review each acceptance_review_plan item and obligation_review_item with pass/fail/not-run status.',
				'copy_safe_notes'=>'Include diagnostic_summary.copy_safe_evidence only for failed focused checks; exclude raw logs and secrets.',
				'remaining_app_risk'=>'Name unresolved app-owned decisions, deferred chunks, failed checks, or acceptance items.',
			],
			'not_required'=>[
				'dataphyre_mcp_verify_all for ordinary app acceptance',
				'source-checkout dev tools for ordinary app acceptance',
				'MCP/release-surface validation for ordinary app acceptance',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Returns acceptance review guidance for one app-owned obligation id.
	 *
	 * @param string $obligation_id Implementation obligation id.
	 * @return string Review instruction.
	 */
	private function app_builder_acceptance_obligation_review_text(string $obligation_id): string {
		return match($obligation_id){
			'field_metadata'=>'Confirm options/defaults are represented in app-owned schema, Panel controls, filters, validation, and focused checks.',
			'relationship_adapters'=>'Confirm relationship lookups, labels, permissions, tenant/workspace scope, and empty states are implemented in app-owned repositories/resources/manifests.',
			'data_integrity'=>'Confirm app-owned schema/repository metadata covers required fields, indexes, uniqueness, foreign keys, scope fields, and business identifiers.',
			'app_contract_decisions'=>'Confirm app-owned ownership, tenant/workspace scope, lifecycle, audit, feature-intent, and relationship policy decisions were resolved or explicitly deferred.',
			'data_sensitivity'=>'Confirm sensitive fields are removed, masked, write-only, permission-gated, redacted, or covered by focused negative checks as appropriate.',
			'tenant_identity'=>'Confirm tenant/workspace scope, authenticated actor context, permission/visibility rules, and plan/entitlement/quota gates are enforced before render, mutation, export, notification, or relationship lookup.',
			'app_owned_corporate_controls'=>'Confirm active corporate-control summaries were applied as app-owned schema, repository, Panel, manifest, and focused-test behavior without opening governance.',
			default=>'Confirm this implementation obligation is completed in app-owned files and covered by focused verification or an explicit app-owned deferral.',
		};
	}

	/**
	 * Returns compact evidence sources for one app-owned obligation id.
	 *
	 * @param string $obligation_id Implementation obligation id.
	 * @return array<int,string> Builder response evidence source names.
	 */
	private function app_builder_acceptance_obligation_evidence_sources(string $obligation_id): array {
		return match($obligation_id){
			'field_metadata'=>['builder_response.field_metadata_summary', 'builder_response.panel_fields', 'builder_response.filters', 'builder_response.verification_handoff'],
			'relationship_adapters'=>['builder_response.relationship_contract_summary', 'builder_response.relationship_adapter_handoff', 'builder_response.verification_handoff'],
			'data_integrity'=>['builder_response.data_integrity_summary', 'builder_response.code_skeleton_summary.paths_by_kind.table_schema', 'builder_response.verification_handoff'],
			'app_contract_decisions'=>['builder_response.app_contract_summary', 'builder_response.policy_decision_register', 'builder_response.prewrite_checklist.resolution_plan'],
			'data_sensitivity'=>['builder_response.data_sensitivity_summary', 'builder_response.policy_decision_register', 'builder_response.verification_handoff'],
			'tenant_identity'=>['builder_response.tenant_identity_handoff', 'builder_response.access_control_handoff', 'builder_response.policy_decision_register', 'builder_response.verification_fixture_handoff', 'builder_response.verification_handoff'],
			'app_owned_corporate_controls'=>['builder_response.implementation_matrix.work_items[app_owned_corporate_controls]', 'builder_response.lifecycle_policy_summary', 'builder_response.access_control_summary', 'builder_response.operational_reliability_summary', 'builder_response.support_observability_summary', 'builder_response.reporting_analytics_summary', 'builder_response.notification_communication_summary', 'builder_response.verification_handoff'],
			default=>['builder_response.implementation_recipe.items', 'builder_response.verification_handoff'],
		};
	}

	/**
	 * Builds a compact schema-aware app contract summary for ordinary app agents.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param string $task User task text for requested app-owned feature intent.
	 * @return array<string,mixed> Lightweight app-owned contract notes.
	 */
	private function app_builder_app_contract_summary(array $schemas, string $task=''): array {
		$fields=[];
		$relationships=[];
		$entities=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$entities[]=$entity;
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(is_array($field) && isset($field['name'])){
					$fields[]=(string)$field['name'];
				}
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$target=(string)($relationship['target_entity'] ?? '');
				if($target===''){
					continue;
				}
				$relationships[]=[
					'entity'=>$entity,
					'field'=>(string)($relationship['field'] ?? ''),
					'target_entity'=>$target,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
				];
			}
		}
		$fields=array_values(array_unique($fields));
		$ownership_fields=array_values(array_intersect(['owner_id', 'assignee_id', 'requester_id', 'approver_id', 'actor_id', 'created_by', 'updated_by'], $fields));
		$scope_fields=array_values(array_intersect(['tenant_id', 'workspace_id', 'organization_id', 'org_id', 'team_id', 'account_id', 'customer_id', 'store_id'], $fields));
		$billing_fields=array_values(array_intersect(['billing_account_id', 'billing_email', 'plan_id', 'subscription_id', 'entitlement_id', 'invoice_id', 'payment_id', 'amount_minor', 'price_minor', 'total_minor', 'currency', 'current_period_end'], $fields));
		$access_fields=array_values(array_intersect(['role_id', 'permission_id', 'policy_id', 'sso_provider_id', 'api_key_id'], $fields));
		$lifecycle_fields=array_values(array_intersect(['status', 'priority', 'archived_at', 'deleted_at'], $fields));
		$audit_fields=array_values(array_intersect(['created_at', 'updated_at', 'created_by', 'updated_by', 'actor_id'], $fields));
		$corporate_record_fields=array_values(array_intersect(['retention_until', 'retained_until', 'legal_hold', 'approved_by', 'approved_at', 'effective_at', 'expires_at', 'exported_at', 'exported_by', 'data_region', 'classification', 'data_classification'], $fields));
		$relationship_targets=[];
		foreach($relationships as $relationship){
			$key=(string)$relationship['target_entity'];
			if($key===''){
				continue;
			}
			$relationship_targets[$key]=[
				'target_entity'=>$key,
				'target_table'=>(string)$relationship['target_table'],
			];
		}
		$relationship_targets=array_values($relationship_targets);
		$feature_intent_summary=$this->app_builder_feature_intent_summary($task);
		$feature_decision_prompts=[];
		foreach($feature_intent_summary['requested_features'] as $feature){
			$feature_decision_prompts[]=[
				'id'=>$this->app_builder_feature_intent_policy_id((string)$feature),
				'status'=>'needs_app_owned_design',
				'fields'=>$feature_intent_summary['supporting_fields_by_feature'][$feature] ?? $feature_intent_summary['supporting_fields'],
				'prompt'=>$this->app_builder_feature_intent_prompt((string)$feature),
			];
		}
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Compact app-owned data, policy, and verification contract hints for ordinary app work; not a governance gate.',
			'entities'=>array_values(array_unique($entities)),
			'present_fields'=>[
				'ownership'=>$ownership_fields,
				'scope'=>$scope_fields,
				'billing'=>$billing_fields,
				'access'=>$access_fields,
				'lifecycle'=>$lifecycle_fields,
				'audit'=>$audit_fields,
				'corporate_records'=>$corporate_record_fields,
			],
			'missing_common_fields'=>[
				'ownership'=>array_values(array_diff(['owner_id', 'created_by', 'updated_by'], $fields)),
				'scope'=>array_values(array_diff(['tenant_id', 'workspace_id'], $fields)),
				'audit'=>array_values(array_diff(['created_at', 'updated_at'], $fields)),
			],
			'feature_intent_summary'=>$feature_intent_summary,
			'scope_identifier_metadata'=>$this->app_builder_scope_identifier_metadata($schemas),
			'relationship_targets'=>$relationship_targets,
			'decision_prompts'=>array_values(array_merge([
				[
					'id'=>'ownership_policy',
					'status'=>$ownership_fields===[] ? 'needs_app_decision' : 'fields_present',
					'fields'=>$ownership_fields,
					'prompt'=>$ownership_fields===[] ? 'Decide whether this app feature needs owner/requester/assignee fields or explicitly document why ownership is inherited elsewhere.' : 'Map ownership fields to app-owned permissions, query scopes, and Panel visibility rules.',
				],
				[
					'id'=>'tenant_scope',
					'status'=>$scope_fields===[] ? 'needs_app_decision' : 'fields_present',
					'fields'=>$scope_fields,
					'prompt'=>$scope_fields===[] ? 'Decide whether the feature is single-tenant, inherits tenant scope from a parent relation, or needs tenant/workspace fields.' : 'Apply tenant/workspace scope in app-owned repositories, filters, callbacks, dialbacks, plugins, or adapters.',
				],
				[
					'id'=>'billing_policy',
					'status'=>$billing_fields===[] ? 'optional_app_decision' : 'fields_present',
					'fields'=>$billing_fields,
					'prompt'=>$billing_fields===[] ? 'Decide whether billing, plan, subscription, entitlement, invoice, payment, or currency behavior is out of scope or inherited from another app-owned relation.' : 'Treat billing and subscription fields as app-owned accounting policy: validate currency/amount semantics, permission-gate exports, and verify redaction/tenant scope.',
				],
				[
					'id'=>'access_policy',
					'status'=>$access_fields===[] ? 'optional_app_decision' : 'fields_present',
					'fields'=>$access_fields,
					'prompt'=>$access_fields===[] ? 'Decide whether role, permission, policy, SSO provider, or API-key relationships are needed for this feature.' : 'Map access-policy fields to app-owned permissions, relationship adapters, tenant filters, and focused regression checks.',
				],
				[
					'id'=>'lifecycle_policy',
					'status'=>$lifecycle_fields===[] ? 'optional_app_decision' : 'fields_present',
					'fields'=>$lifecycle_fields,
					'prompt'=>$lifecycle_fields===[] ? 'Decide whether records need status, priority, archive, or delete lifecycle behavior.' : 'Define allowed statuses/transitions, default filters, and route-free Panel checks for lifecycle fields.',
				],
				[
					'id'=>'audit_policy',
					'status'=>$audit_fields===[] ? 'needs_app_decision' : 'fields_present',
					'fields'=>$audit_fields,
					'prompt'=>$audit_fields===[] ? 'Decide whether audit is column-based, event/log based, inherited from parent records, or intentionally out of scope.' : 'Verify created/updated audit fields are populated by app-owned persistence or callbacks.',
				],
				[
					'id'=>'corporate_records_policy',
					'status'=>$corporate_record_fields===[] ? 'optional_app_decision' : 'fields_present',
					'fields'=>$corporate_record_fields,
					'prompt'=>$corporate_record_fields===[] ? 'Decide whether retention, legal hold, approval/effective dates, export markers, data region, or classification fields are out of scope or inherited from another app-owned relation.' : 'Map retention, legal-hold, approval/effective dates, export markers, data region, and classification fields to app-owned lifecycle, visibility, export, and focused verification policy.',
				],
				[
					'id'=>'relationship_policy',
					'status'=>$relationship_targets===[] ? 'no_relationship_targets' : 'needs_adapter_mapping',
					'targets'=>$relationship_targets,
					'prompt'=>$relationship_targets===[] ? 'No relationship targets were inferred from foreign-key fields.' : 'Map relationship lookups, permissions, tenant constraints, labels, and empty states in app-owned adapters/UI.',
				],
			], $feature_decision_prompts)),
			'policy_boundary'=>'Implement ownership, tenant/workspace scoping, approval, permission, and audit behavior in app-owned policy/config/callbacks/dialbacks/plugins or app adapters.',
			'verification_hint'=>'After writing concrete app files, verify app-owned fields, filters, relationship adapters, dashboard/reporting queries when requested, and route-free Panel regression before considering the feature done.',
		];
	}

	/**
	 * Orders planned entities by same-chunk foreign-key dependencies.
	 *
	 * @param array<int,array<string,mixed>> $schemas Inferred entity schemas.
	 * @return array<string,mixed> Dependency-aware entity order for app-owned writes.
	 */
	private function app_builder_schema_dependency_order(array $schemas): array {
		$entities=[];
		$table_to_entity=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			$table=(string)($schema['table'] ?? '');
			if($entity===''){
				continue;
			}
			$entities[$entity]=[
				'table'=>$table,
				'depends_on'=>[],
				'external_dependencies'=>[],
			];
			if($table!==''){
				$table_to_entity[$table]=$entity;
			}
		}
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity==='' || !isset($entities[$entity])){
				continue;
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$target_entity=(string)($relationship['target_entity'] ?? '');
				$target_table=(string)($relationship['target_table'] ?? '');
				$field=(string)($relationship['field'] ?? '');
				if($target_entity!=='' && isset($entities[$target_entity])){
					$entities[$entity]['depends_on'][]=[
						'entity'=>$target_entity,
						'table'=>$target_table,
						'field'=>$field,
					];
					continue;
				}
				if($target_table!=='' && isset($table_to_entity[$target_table])){
					$entities[$entity]['depends_on'][]=[
						'entity'=>$table_to_entity[$target_table],
						'table'=>$target_table,
						'field'=>$field,
					];
					continue;
				}
				if($target_entity!=='' || $target_table!==''){
					$entities[$entity]['external_dependencies'][]=[
						'entity'=>$target_entity,
						'table'=>$target_table,
						'field'=>$field,
					];
				}
			}
		}
		$remaining=array_keys($entities);
		$ordered=[];
		$resolved=[];
		while($remaining!==[]){
			$progress=false;
			foreach($remaining as $index=>$entity){
				$unresolved=array_values(array_filter($entities[$entity]['depends_on'], static function(array $dependency) use ($resolved): bool {
					return !isset($resolved[(string)($dependency['entity'] ?? '')]);
				}));
				if($unresolved!==[]){
					continue;
				}
				$ordered[]=$entity;
				$resolved[$entity]=true;
				unset($remaining[$index]);
				$progress=true;
			}
			if(!$progress){
				foreach($remaining as $entity){
					$ordered[]=$entity;
				}
				break;
			}
			$remaining=array_values($remaining);
		}
		return [
			'policy'=>'create_dependency_targets_before_dependents_when_they_are_in_the_current_chunk',
			'entities'=>$ordered,
			'dependencies_by_entity'=>$entities,
			'external_dependency_policy'=>'For previous chunks or app-existing tables, preserve dependency_context and wire app-owned repository/adapters before Panel relation UI.',
		];
	}

	/**
	 * Summarizes app-owned non-CRUD feature intent from the task text.
	 *
	 * @param string $task User task text.
	 * @return array<string,mixed> Requested app-owned feature hints.
	 */
	private function app_builder_feature_intent_summary(string $task): array {
		$lower=strtolower($task);
		$requested_features=[];
		$definitions=[
			'dashboards_or_reporting'=>[
				'phrases'=>['dashboard', 'reporting', 'analytics', 'metrics', 'qbr'],
				'fields'=>['status', 'priority', 'amount_minor', 'total_minor', 'price_minor', 'quantity', 'created_at', 'updated_at', 'occurred_at', 'due_at', 'current_period_end', 'score', 'measured_at'],
			],
			'approval_workflow'=>[
				'phrases'=>['approval', 'approvals', 'approve', 'approved_by', 'approver', 'review workflow'],
				'fields'=>['approved_by', 'approver_id', 'approved_at', 'status', 'stage'],
			],
			'audit_retention'=>[
				'phrases'=>['audit retention', 'retention', 'legal hold', 'audit history', 'audit events', 'audit event'],
				'fields'=>['retention_until', 'legal_hold', 'audit_event_id', 'audit_event_type', 'actor_id', 'occurred_at'],
			],
			'access_control'=>[
				'phrases'=>['rbac', 'role based access', 'role-based access', 'permission', 'permissions', 'access control'],
				'fields'=>['role_id', 'permissions', 'owner_id', 'workspace_id', 'tenant_id', 'organization_id'],
			],
			'invitation_acceptance'=>[
				'phrases'=>['invitation acceptance', 'accept invitation', 'accept invite', 'invitation flow', 'invite flow', 'invites', 'invitations'],
				'fields'=>['email', 'token_hash', 'expires_at', 'accepted_at', 'status'],
			],
			'data_residency'=>[
				'phrases'=>['data residency', 'residency', 'region policy', 'regional storage', 'data region'],
				'fields'=>['data_region', 'region', 'classification', 'exported_at'],
			],
			'document_uploads'=>[
				'phrases'=>['document upload', 'document uploads', 'evidence upload', 'evidence uploads', 'file upload', 'file uploads', 'attachments', 'attachment'],
				'fields'=>['object_ref', 'storage_ref', 'classification', 'document_type', 'expires_at'],
			],
			'webhook_delivery'=>[
				'phrases'=>['webhook delivery', 'webhook retry', 'webhook retries', 'delivery retry', 'delivery retries', 'retry status', 'webhooks'],
				'fields'=>['url', 'encrypted_secret_ref', 'secret_hash', 'last_delivery_at', 'retry_count', 'status'],
			],
			'assignment_or_queueing'=>[
				'phrases'=>['assignment', 'assignments', 'assigned', 'assignee', 'queue', 'queues', 'owner queue'],
				'fields'=>['assignee_id', 'owner_id', 'assigned_to', 'status', 'priority'],
			],
			'sla_escalation'=>[
				'phrases'=>['sla', 'escalation', 'escalations', 'breach', 'breaches', 'due date', 'due dates'],
				'fields'=>['sla_policy_id', 'due_at', 'breached_at', 'severity', 'priority', 'status'],
			],
			'renewal_lifecycle'=>[
				'phrases'=>['renewal', 'renewals', 'expires', 'expiration', 'contract end', 'term end'],
				'fields'=>['renewal_at', 'expires_at', 'effective_at', 'current_period_end', 'stage', 'status', 'amount_minor'],
			],
			'import_export'=>[
				'phrases'=>['import', 'imports', 'export', 'exports', 'csv', 'spreadsheet'],
				'fields'=>['imported_at', 'exported_at', 'external_id', 'status'],
			],
			'comments_or_activity'=>[
				'phrases'=>['comment', 'comments', 'notes', 'activity', 'timeline', 'history'],
				'fields'=>['body', 'notes', 'author_id', 'actor_id', 'created_at'],
			],
			'notifications'=>[
				'phrases'=>['notification', 'notifications', 'email alerts', 'alerts', 'reminders'],
				'fields'=>['email', 'notified_at', 'reminder_at', 'status'],
			],
		];
		$supporting_fields_by_feature=[];
		foreach($definitions as $feature=>$definition){
			foreach($definition['phrases'] as $phrase){
				if(str_contains($lower, $phrase)){
					$requested_features[]=$feature;
					break;
				}
			}
			foreach($definition['fields'] as $field){
				if(str_contains($lower, $field)){
					$supporting_fields_by_feature[$feature][]=$field;
				}
			}
		}
		$supporting_fields=[];
		foreach($supporting_fields_by_feature as $fields){
			foreach($fields as $field){
				$supporting_fields[]=$field;
			}
		}
		foreach($supporting_fields_by_feature as $feature=>$fields){
			$supporting_fields_by_feature[$feature]=array_values(array_unique($fields));
		}
		return [
			'owner'=>'consuming_application',
			'requested_features'=>array_values(array_unique($requested_features)),
			'supporting_fields'=>array_values(array_unique($supporting_fields)),
			'supporting_fields_by_feature'=>$supporting_fields_by_feature,
			'policy'=>$requested_features===[] ? 'No non-CRUD app feature intent was inferred from task text.' : 'Keep requested non-CRUD features in app-owned dashboards, queries, callbacks, dialbacks, plugins, notifications, import/export adapters, or application services; do not modify Dataphyre runtime internals for one application.',
			'verification_hint'=>$requested_features===[] ? 'Focused CRUD/resource verification is sufficient unless the app adds additional app-owned features.' : 'After app-owned non-CRUD work is written, verify tenant filters, state transitions, redaction/export boundaries, notifications, and focused UI/query behavior with app/module checks.',
		];
	}

	/**
	 * Returns a compact app-owned design prompt for inferred non-CRUD feature intent.
	 *
	 * @param string $feature Inferred feature intent id.
	 * @return string App-owned policy prompt.
	 */
	private function app_builder_feature_intent_prompt(string $feature): string {
		return match($feature){
			'dashboards_or_reporting'=>'Task asks for dashboards/reporting; design app-owned dashboard queries, aggregation boundaries, tenant filters, redaction, and focused verification after the CRUD/resource chunks are planned.',
			'approval_workflow'=>'Task asks for approval workflow behavior; design app-owned states, approver resolution, permission checks, audit events, and focused transition tests.',
			'audit_retention'=>'Task asks for audit retention behavior; design app-owned retention windows, legal-hold exceptions, append-only event policy, and focused retention checks.',
			'access_control'=>'Task asks for RBAC/access-control behavior; design app-owned roles, permissions, tenant/workspace scoping, denial paths, and focused authorization checks.',
			'invitation_acceptance'=>'Task asks for invitation acceptance behavior; design app-owned token hashing, expiry, acceptance idempotency, membership creation, tenant scope, and focused invite-flow checks.',
			'data_residency'=>'Task asks for data residency behavior; design app-owned region classification, storage/export boundaries, tenant policy, and focused residency checks.',
			'document_uploads'=>'Task asks for document upload behavior; design app-owned storage references, classification, scanning/validation adapters, access policy, and focused upload metadata checks.',
			'webhook_delivery'=>'Task asks for webhook delivery behavior; design app-owned delivery attempts, retry/idempotency policy, secret handling, and focused delivery-state checks.',
			'assignment_or_queueing'=>'Task asks for assignment or queue behavior; design app-owned ownership, queue membership, reassignment rules, empty states, and scoped list/filter checks.',
			'sla_escalation'=>'Task asks for SLA/escalation behavior; design app-owned due-date rules, escalation severity, breach detection, notification/adaptation hooks, and focused regression evidence.',
			'renewal_lifecycle'=>'Task asks for renewal lifecycle behavior; design app-owned renewal dates, stages, amount/currency semantics, reminders, close/loss states, and reporting checks.',
			'import_export'=>'Task asks for import/export behavior; design app-owned file parsing/export redaction, tenant scoping, idempotency, error reporting, and focused verification.',
			'comments_or_activity'=>'Task asks for comments, notes, or activity history; design app-owned authorship, visibility, retention, timeline ordering, and redaction checks.',
			'notifications'=>'Task asks for notifications or alerts; design app-owned trigger rules, recipient policy, delivery adapter, retry/idempotency behavior, and focused verification.',
			default=>'Task asks for non-CRUD app behavior; design the feature in app-owned services, callbacks, dialbacks, plugins, adapters, and focused verification.',
		};
	}

	/**
	 * Returns a stable app contract decision id for inferred non-CRUD features.
	 *
	 * @param string $feature Inferred feature intent id.
	 * @return string Policy decision id.
	 */
	private function app_builder_feature_intent_policy_id(string $feature): string {
		return match($feature){
			'dashboards_or_reporting'=>'dashboard_reporting_policy',
			default=>$feature.'_policy',
		};
	}

	/**
	 * Builds a compact relationship summary for app-owned adapter work.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @param array<string,mixed> $entity_planning Chunking/dependency metadata.
	 * @return array<string,mixed> Relationship contract summary.
	 */
	private function app_builder_relationship_contract_summary(array $schemas, array $entity_planning): array {
		$planned_entities=[];
		$relationships=[];
		$incoming_relationships=[];
		foreach(is_array($entity_planning['incoming_dependency_context']['dependencies'] ?? null) ? $entity_planning['incoming_dependency_context']['dependencies'] : [] as $dependency){
			if(!is_array($dependency)){
				continue;
			}
			$entity=(string)($dependency['entity'] ?? '');
			$field=(string)($dependency['field'] ?? '');
			if($entity==='' || $field===''){
				continue;
			}
			$incoming_relationships[$this->app_builder_entity_key($entity).'|'.$field]=$dependency;
		}
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			$entity=(string)($schema['entity'] ?? '');
			if($entity!==''){
				$planned_entities[$this->app_builder_entity_key($entity)]=$entity;
			}
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$target_entity=(string)($relationship['target_entity'] ?? '');
				$scope=isset($planned_entities[$this->app_builder_entity_key($target_entity)]) ? 'planned_entity' : 'external_reference';
				$incoming_relationship=$incoming_relationships[$this->app_builder_entity_key($entity).'|'.(string)($relationship['field'] ?? '')] ?? null;
				if(is_array($incoming_relationship) && (string)($incoming_relationship['target_entity'] ?? '')===$target_entity){
					$scope=(string)($incoming_relationship['scope'] ?? $scope);
				}
				$relationships[]=[
					'entity'=>$entity,
					'field'=>(string)($relationship['field'] ?? ''),
					'target_entity'=>$target_entity,
					'target_table'=>(string)($relationship['target_table'] ?? ''),
					'scope'=>$scope,
				];
			}
		}
		$dependency_scopes=[];
		foreach(is_array($entity_planning['dependency_summary']['chunks'] ?? null) ? $entity_planning['dependency_summary']['chunks'] : [] as $chunk){
			if(!is_array($chunk)){
				continue;
			}
			foreach(is_array($chunk['dependencies'] ?? null) ? $chunk['dependencies'] : [] as $dependency){
				if(!is_array($dependency)){
					continue;
				}
				$scope=(string)($dependency['scope'] ?? '');
				if($scope===''){
					continue;
				}
				$dependency_scopes[$scope]=($dependency_scopes[$scope] ?? 0)+1;
			}
		}
		$external_targets=[];
		$planned_targets=[];
		foreach($relationships as $relationship){
			$target=(string)($relationship['target_entity'] ?? '');
			if($target===''){
				continue;
			}
			if(($relationship['scope'] ?? '')==='external_reference'){
				$external_targets[$target]=true;
			}else{
				$planned_targets[$target]=true;
			}
		}
		$relationship_integrity_metadata=$this->app_builder_relationship_integrity_metadata($schemas, $entity_planning);
		$scope_identifier_metadata=$this->app_builder_scope_identifier_metadata($schemas);
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Compact relationship adapter and verification hints for ordinary app scaffolds without opening governance context.',
			'total_relationships'=>count($relationships),
			'planned_targets'=>array_values(array_keys($planned_targets)),
			'external_targets'=>array_values(array_keys($external_targets)),
			'dependency_scopes'=>$dependency_scopes,
			'relationships'=>array_values(array_slice($relationships, 0, 12)),
			'relationship_integrity_metadata'=>$relationship_integrity_metadata,
			'scope_identifier_metadata'=>$scope_identifier_metadata,
			'scope_relationships'=>array_values(array_filter($scope_identifier_metadata['scope_fields'] ?? [], static fn(array $row): bool => isset($row['relationship_target_entity']) && (string)$row['relationship_target_entity']!=='')),
			'non_relationship_identifiers'=>array_values(array_map(static fn(array $row): string => (string)($row['identifier'] ?? ''), is_array($scope_identifier_metadata['external_identifiers'] ?? null) ? $scope_identifier_metadata['external_identifiers'] : [])),
			'app_owned_actions'=>[
				'Create app-owned repository/query adapters for relationship fields before wiring Panel relation UI.',
				'Resolve external references such as users or vendors through consuming-app access, permission, and tenant policy.',
				'Preserve entity_planning.dependency_summary and continuation dependency_context when relationships cross chunks.',
			],
			'verification_hint'=>'After writing concrete app files, verify relationship fields, filters, relation managers/adapters, permission boundaries, and route-free Panel regression with focused app/module checks.',
		];
	}

	/**
	 * Converts relationship metadata into copy-friendly app-owned adapter work items.
	 *
	 * @param array<string,mixed> $relationship_contract_summary Relationship summary payload.
	 * @param array<string,mixed> $code_skeleton_summary Compact skeleton grouping.
	 * @return array<string,mixed> Relationship adapter handoff.
	 */
	private function app_builder_relationship_adapter_handoff(array $relationship_contract_summary, array $code_skeleton_summary): array {
		$relationships=is_array($relationship_contract_summary['relationships'] ?? null) ? $relationship_contract_summary['relationships'] : [];
		$resource_paths=is_array($code_skeleton_summary['paths_by_kind']['panel_resource'] ?? null) ? array_values(array_map('strval', $code_skeleton_summary['paths_by_kind']['panel_resource'])) : [];
		$repository_paths=is_array($code_skeleton_summary['paths_by_kind']['table_repository'] ?? null) ? array_values(array_map('strval', $code_skeleton_summary['paths_by_kind']['table_repository'])) : [];
		$manifest_paths=is_array($code_skeleton_summary['paths_by_kind']['panel_manifest'] ?? null) ? array_values(array_map('strval', $code_skeleton_summary['paths_by_kind']['panel_manifest'])) : [];
		$regression_paths=is_array($code_skeleton_summary['paths_by_kind']['panel_regression_manifest'] ?? null) ? array_values(array_map('strval', $code_skeleton_summary['paths_by_kind']['panel_regression_manifest'])) : [];
		$adapters=[];
		$by_entity=[];
		foreach($relationships as $relationship){
			if(!is_array($relationship)){
				continue;
			}
			$entity=(string)($relationship['entity'] ?? '');
			$field=(string)($relationship['field'] ?? '');
			$target_entity=(string)($relationship['target_entity'] ?? '');
			if($entity==='' || $field==='' || $target_entity===''){
				continue;
			}
			$scope=(string)($relationship['scope'] ?? 'external_reference');
			$adapter_stem=$this->studly_name($entity.' '.$target_entity.' relation');
			$adapter=[
				'entity'=>$entity,
				'field'=>$field,
				'target_entity'=>$target_entity,
				'target_table'=>(string)($relationship['target_table'] ?? ''),
				'scope'=>$scope,
				'adapter_stem'=>$adapter_stem,
				'suggested_app_adapter'=>$adapter_stem.'Adapter',
				'panel_field_source'=>$field.'_options',
				'repository_touchpoint'=>$this->studly_name($entity).'Repository::relationshipOptions('.var_export($field, true).')',
				'lookup_policy'=>$scope==='planned_entity' ? 'validate_local_foreign_key_lookup_permissions_and_empty_state' : 'resolve_external_reference_through_consuming_app_access_policy',
				'verification_focus'=>[
					$field.'_options_are_scoped_and_permission_checked',
					$field.'_empty_state_and_invalid_reference_are_handled',
					$field.'_filter_or_form_does_not_leak_cross_tenant_records',
				],
			];
			$adapters[]=$adapter;
			$by_entity[$entity][]=[
				'field'=>$field,
				'target_entity'=>$target_entity,
				'adapter_stem'=>$adapter_stem,
				'scope'=>$scope,
			];
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$adapters===[] ? 'no_relationship_adapters_needed' : 'relationship_adapters_ready_for_app_owned_wiring',
			'purpose'=>'Concrete app-owned relationship adapter handoff for Panel fields, repositories, filters, and focused regression checks.',
			'adapter_count'=>count($adapters),
			'adapters'=>array_values(array_slice($adapters, 0, 12)),
			'by_entity'=>$by_entity,
			'touchpoints'=>[
				'panel_resources'=>$resource_paths,
				'table_repositories'=>$repository_paths,
				'panel_manifests'=>$manifest_paths,
				'panel_regression_manifests'=>$regression_paths,
			],
			'next_reads'=>[
				'builder_response.relationship_contract_summary.relationships',
				'builder_response.code_skeleton_summary.paths_by_kind',
				'builder_response.implementation_matrix.work_items[data_integrity]',
				'builder_response.verification_recovery_plan.branches',
			],
			'not_required'=>[
				'Dataphyre runtime relationship internals for one application',
				'MCP/release-surface validation for ordinary app relationship wiring',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
			'policy'=>'Implement lookup, labels, empty states, permission checks, and tenant/workspace constraints in app-owned repositories, callbacks, dialbacks, plugins, or adapters before exposing relationship fields in Panel UI.',
		];
	}

	/**
	 * Builds app-owned enterprise hints without escalating ordinary app work.
	 *
	 * @param array<int,array<string,mixed>> $schemas Planned entity schemas.
	 * @return array<string,mixed> Lightweight enterprise app notes.
	 */
	private function app_builder_enterprise_app_notes(array $schemas): array {
		$schema_fields=[];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				continue;
			}
			foreach(is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field){
				if(is_array($field) && isset($field['name'])){
					$schema_fields[]=(string)$field['name'];
				}
			}
		}
		$schema_fields=array_values(array_unique($schema_fields));
		$present=array_values(array_intersect(['owner_id', 'assignee_id', 'status'], $schema_fields));
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Reusable app-owned enterprise hints; not a Dataphyre framework governance gate.',
			'already_planned'=>$present,
			'consider_app_owned_fields'=>array_values(array_diff(['owner_id', 'created_by', 'updated_by', 'status', 'archived_at'], $schema_fields)),
			'permission_boundary'=>'Keep role, ownership, tenant, and approval rules in application policy/config/callbacks/dialbacks/plugins or app adapters unless the task explicitly asks for reusable Dataphyre framework work.',
			'audit_boundary'=>'Store audit events, actor ids, and retention choices in app-owned tables or existing app audit infrastructure; do not share product-specific audit tables through Dataphyre internals.',
			'tenant_boundary'=>'Add tenant or workspace scoping only when the consuming application has a defined tenant model; do not hardcode tenant ids, URLs, plans, or local product identifiers in shared Dataphyre code.',
			'escalate_only_for'=>[
				'security, identity/access, session, credential, tenant isolation, privacy, compliance, billing, audit, data residency, retention, or legal-hold behavior that changes shared framework contracts',
				'corporate-ready or public Dataphyre framework claims',
				'Dataphyre runtime-internal or shared production hot-path changes',
			],
		];
	}

}
