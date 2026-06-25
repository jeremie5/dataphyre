<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines Mcp kernel trait responsibilities for dataphyre mcp planning surfaces.
 *
 * Mcp kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait dataphyre_mcp_planning_surfaces {

	use dataphyre_mcp_planning_module_surfaces;
	use dataphyre_mcp_planning_agent_context_surfaces;
	use dataphyre_mcp_planning_app_builder_surfaces;
	use dataphyre_mcp_planning_api_surfaces;
	use dataphyre_mcp_planning_docs_surfaces;
	use dataphyre_mcp_planning_task_pack_surfaces;

	/**
	 * Builds a dry-run scaffold plan for common Dataphyre artifacts.
	 *
	 * supported scaffold types are validated up front, naming is
	 * normalized into slug/class/table forms, and the returned plan is explicitly
	 * non-writing with verification guidance for the selected artifact family.
	 */
	private function generate_scaffold_plan(array $args): array {
		$type=strtolower(trim((string)($args['type'] ?? '')));
		$name=trim((string)($args['name'] ?? ''));
		if($name===''){
			throw new InvalidArgumentException('name is required.');
		}
		$allowed=['panel_resource', 'routing_controller', 'api_endpoint', 'sql_table', 'mvc_controller', 'runtime_module'];
		if(!in_array($type, $allowed, true)){
			throw new InvalidArgumentException('type must be one of panel_resource, routing_controller, api_endpoint, sql_table, mvc_controller, or runtime_module.');
		}
		$module=trim((string)($args['module'] ?? ''));
		$fields=is_array($args['fields'] ?? null) ? $args['fields'] : [];
		$slug=$this->slug_name($name);
		$class=$this->studly_name($name);
		$table=str_replace('-', '_', $slug);
		$plan=match($type){
			'panel_resource'=>$this->panel_resource_scaffold_plan($name, $class, $table, $fields, $this->app_builder_path_context($args)),
			'routing_controller'=>$this->routing_controller_scaffold_plan($name, $class, $slug, $module),
			'api_endpoint'=>$this->api_scaffold_plan($args),
			'sql_table'=>$this->sql_table_scaffold_plan($name, $table, $module, $fields),
			'mvc_controller'=>$this->mvc_controller_scaffold_plan($name, $class, $slug),
			'runtime_module'=>$this->runtime_module_scaffold_plan($name, $slug),
		};
		return array_replace([
			'type'=>$type,
			'name'=>$name,
			'write_policy'=>'dry_run_only',
			'unsafe_required_to_apply'=>true,
			'extension_boundary'=>$this->planning_extension_boundary($type),
		], $plan);
	}


	/**
	 * Returns extension-first guidance for dry-run planning payloads.
	 *
	 * @param string $scope Planning scope or scaffold type.
	 * @return array<string,mixed> App-first extension boundary policy.
	 */
	private function planning_extension_boundary(string $scope): array {
		return [
			'scope'=>$scope,
			'default_path'=>'For application behavior, use application code, configuration, dialbacks, callbacks, plugins, MCP metadata, or an application-owned adapter before proposing Dataphyre runtime-internal edits.',
			'preferred_order'=>['application_code', 'configuration', 'dialbacks_callbacks', 'plugins', 'local_mcp_metadata', 'application_adapter', 'reusable_module_contract', 'runtime_internals'],
			'decision_ladder'=>$this->planning_extension_decision_ladder($scope),
			'framework_edit_rule'=>'Edit Dataphyre runtime internals only when the behavior is reusable framework work, not just to make one application pass.',
			'maintainer_gate'=>'Run dataphyre_mcp_enterprise_adoption_audit before framework-internal, release-facing, corporate-ready, or shared hot-path claims.',
			'application_agent_rule'=>'Normal application agents should not modify Dataphyre internals to make an application work; they should use extension points first.',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('planning_extension_boundary_'.$scope),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('planning_extension_boundary_'.$scope),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
		];
	}

	/**
	 * Builds a compact decision ladder for where app behavior should live.
	 *
	 * @param string $scope Planning scope or scaffold type.
	 * @return array<string,mixed> Ordered app-first extension placement guidance.
	 */
	private function planning_extension_decision_ladder(string $scope): array {
		return [
			'scope'=>$scope,
			'owner'=>'consuming_application',
			'purpose'=>'Choose the narrowest reusable layer for behavior before considering Dataphyre runtime internals.',
			'layers'=>[
				[
					'id'=>'application_code',
					'choose_when'=>'The behavior is specific to one app, resource, controller, command, view, table, or workflow.',
					'examples'=>['Panel resource files', 'application services', 'repository methods', 'route/controller code'],
					'agent_action'=>'Implement in app-owned files and verify with focused app/module checks.',
				],
				[
					'id'=>'configuration',
					'choose_when'=>'The behavior is a setting, tenant/workspace option, driver selection, feature flag, or environment-specific policy.',
					'examples'=>['app config files', 'Panel manifests', 'SQL/storage driver config', 'permission maps'],
					'agent_action'=>'Add or update app-owned config and keep secrets or tenant identifiers out of shared Dataphyre code.',
				],
				[
					'id'=>'dialbacks_callbacks',
					'choose_when'=>'The app must influence a Dataphyre module lifecycle, policy decision, formatting step, or event without changing shared module code.',
					'examples'=>['module dialbacks', 'callbacks', 'event hooks', 'policy callbacks'],
					'agent_action'=>'Use the module callback/dialback surface and document the app-owned contract.',
				],
				[
					'id'=>'plugins',
					'choose_when'=>'The behavior is install-local integration, boot wiring, or optional extension that should be packaged outside the runtime.',
					'examples'=>['plugins/pre_init', 'plugins/post_init', 'plugins/mcp declarations'],
					'agent_action'=>'Put install-local behavior in plugins and keep private declarations out of public exports.',
				],
				[
					'id'=>'application_adapter',
					'choose_when'=>'The app needs a reusable boundary around an external service, policy engine, tenant model, billing system, or storage fabric.',
					'examples'=>['application-owned adapters', 'service clients', 'policy resolvers', 'tenant-aware repositories'],
					'agent_action'=>'Create an app-owned adapter contract and inject it through config, callbacks, dialbacks, or plugins.',
				],
				[
					'id'=>'reusable_module_contract',
					'choose_when'=>'Multiple apps need the same behavior and it belongs in a documented Dataphyre module contract.',
					'examples'=>['new public module API', 'portable metadata contract', 'shared diagnostics surface'],
					'agent_action'=>'Treat as Dataphyre framework work with docs, tests, release checks, and enterprise audit evidence.',
				],
				[
					'id'=>'runtime_internals',
					'choose_when'=>'Only when reusable framework behavior, safety, diagnostics, public API, or proven hot-path performance requires it.',
					'examples'=>['core runtime contract', 'shared module kernel', 'public compatibility boundary'],
					'agent_action'=>'Escalate to maintainer workflow; hot-path production changes require benchmark evidence before keeping the change.',
				],
			],
			'ordinary_app_default'=>'Stop at application_code, configuration, dialbacks_callbacks, plugins, or application_adapter for routine app work.',
			'escalate_only_for'=>[
				'reusable Dataphyre framework behavior',
				'public/release-facing framework claims',
				'corporate-ready, security-sensitive, governance-sensitive, tenant/privacy/compliance-sensitive shared behavior',
				'Dataphyre shared production hot-path changes',
			],
		];
	}

	/**
	 * Plans a route-free Panel resource scaffold.
	 *
	 * the plan names expected resource, manifest, and regression artifacts
	 * and points callers toward Panel/SQL documentation and harnesses without
	 * creating files or assuming an application path.
	 */
	private function panel_resource_scaffold_plan(string $name, string $class, string $table, array $fields, array $path_context=[]): array {
		$root=(string)($path_context['dataphyre_root'] ?? 'applications/<app>/backend/dataphyre');
		return [
			'summary'=>'Plan a route-free Panel resource backed by schema metadata and regression coverage.',
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
				'common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
			],
			'optional_guidance_docs'=>[
				'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			],
			'proposed_files'=>[
				$root.'/panel/resources/'.$class.'Resource.php',
				$root.'/panel/manifests/'.$table.'.php',
				$root.'/unit_tests/panel.'.$table.'.json',
			],
			'app_path_context'=>$path_context,
			'steps'=>[
				'Inspect existing Panel resources and manifests before choosing local conventions.',
				'Define fields, table columns, actions, filters, and relation surfaces in schema/resource metadata.',
				'Keep rendering and authorization route-free so Panel regression can exercise the resource directly.',
				'Add or extend a focused route-free Panel regression suite for the resource behavior.',
			],
			'field_hints'=>$this->field_hints($fields),
			'verification'=>[
				'dataphyre_run_panel_regression',
				'dataphyre_run_panel_field_catalog_check',
				'dataphyre_php_lint',
			],
		];
	}

	/**
	 * Plans a controller and route-manifest scaffold.
	 *
	 * ownership can be module-local or application-local, and verification
	 * is constrained to manifest reads, URL previews, and dry route matching rather
	 * than dispatching handlers from MCP.
	 */
	private function routing_controller_scaffold_plan(string $name, string $class, string $slug, string $module): array {
		$owner=$module!=='' ? 'common/dataphyre/runtime/modules/'.$module : 'applications/<app>/backend/dataphyre';
		return [
			'summary'=>'Plan a controller/action and route manifest change without dispatching handlers from MCP.',
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'common/dataphyre/runtime/modules/http/documentation/Dataphyre_HTTP.md',
			],
			'proposed_files'=>[
				$owner.'/controllers/'.$class.'Controller.php',
				$owner.'/routes/'.$slug.'.php',
				$owner.'/unit_tests/routing.'.$slug.'.json',
			],
			'steps'=>[
				'Read existing route declarations and compiled manifest shape before editing.',
				'Create a controller action with typed request/response boundaries where the local module supports them.',
				'Register named routes and middleware in route declarations, then regenerate or inspect the manifest through normal project tooling.',
				'Use MCP route manifest read, URL preview, and dry match preview for verification.',
			],
			'verification'=>[
				'dataphyre_route_manifest_read',
				'dataphyre_route_url_preview',
				'dataphyre_route_match_preview',
				'dataphyre_php_lint',
			],
		];
	}

	/**
	 * Plans a SQL table-definition scaffold.
	 *
	 * the plan separates table definition, repository/query code, and
	 * schema tests while keeping runtime application details caller-owned and
	 * avoiding live database access.
	 */
	private function sql_table_scaffold_plan(string $name, string $table, string $module, array $fields): array {
		$owner=$module!=='' ? 'common/dataphyre/runtime/modules/'.$module.'/kernel' : 'applications/<app>/backend/dataphyre/sql';
		return [
			'summary'=>'Plan a SQL table definition using TableDefinition/TableSchema metadata without executing queries.',
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
			],
			'optional_guidance_docs'=>[
				'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			],
			'proposed_files'=>[
				$owner.'/'.$table.'.tables.php',
				'applications/<app>/backend/dataphyre/config/sql.php',
				'applications/<app>/backend/dataphyre/unit_tests/sql.'.$table.'.json',
			],
			'steps'=>[
				'Model columns, primary keys, indexes, and defaults through TableDefinition metadata.',
				'Register the table in the owning module or application SQL config without exposing credentials.',
				'Preview schema metadata through MCP before any migration or query workflow.',
				'Keep data migration or query execution behind a separate explicit unsafe workflow.',
			],
			'field_hints'=>$this->field_hints($fields),
			'verification'=>[
				'dataphyre_sql_tables_list',
				'dataphyre_sql_schema_read',
				'dataphyre_sql_clusters_list',
				'dataphyre_php_lint',
			],
		];
	}

	/**
	 * Plans an MVC controller scaffold.
	 *
	 * this plan is intended for application-facing controller work and
	 * returns documentation, file, and verification hints without editing route,
	 * view, or controller files directly.
	 */
	private function mvc_controller_scaffold_plan(string $name, string $class, string $slug): array {
		return [
			'summary'=>'Plan an MVC controller/view workflow that composes HTTP, Routing, Templating, and SQL surfaces.',
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/mvc/documentation/Dataphyre_MVC.md',
				'common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'common/dataphyre/runtime/modules/templating/documentation/Dataphyre_Templating.md',
			],
			'proposed_files'=>[
				'applications/<app>/backend/dataphyre/mvc/controllers/'.$class.'Controller.php',
				'applications/<app>/backend/dataphyre/mvc/views/'.$slug.'/index.php',
				'applications/<app>/backend/dataphyre/routes/'.$slug.'.php',
			],
			'steps'=>[
				'Inspect existing MVC controllers, route groups, view results, and middleware patterns.',
				'Keep controller actions thin and push reusable behavior into module/framework classes.',
				'Register named routes and view paths with app-local configuration.',
				'Verify route manifests and PHP syntax without dispatching requests from MCP.',
			],
			'verification'=>[
				'dataphyre_route_manifest_read',
				'dataphyre_route_match_preview',
				'dataphyre_php_lint',
			],
		];
	}

	/**
	 * Plans a new runtime module scaffold.
	 *
	 * generated guidance covers module documentation, kernel/framework
	 * boundaries, unit tests, version files, and MCP visibility declaration while
	 * preserving the server's dry-run-only safety contract.
	 */
	private function runtime_module_scaffold_plan(string $name, string $slug): array {
		return [
			'summary'=>'Plan a reusable Dataphyre runtime module with docs, kernel bootstrap, optional Framework classes, and release hygiene.',
			'recommended_docs'=>[
				'common/dataphyre/runtime/README.md',
				'common/dataphyre/docs/MODULES.md',
				'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			],
			'proposed_files'=>[
				'common/dataphyre/runtime/modules/'.$slug.'/documentation/Dataphyre_'.$this->studly_name($name).'.md',
				'common/dataphyre/runtime/modules/'.$slug.'/kernel/'.$slug.'.main.php',
				'common/dataphyre/runtime/modules/'.$slug.'/version',
				'common/dataphyre/runtime/modules/'.$slug.'/unit_tests/dataphyre.'.$slug.'.json',
			],
			'steps'=>[
				'Define whether the module owns Framework contracts, kernel bootstrap hooks, config, SQL tables, routes, or diagnostics.',
				'Keep product-specific behavior out of the shared runtime module.',
				'Confirm the behavior cannot live in application code, config, callbacks, dialbacks, plugins, MCP metadata, or an application-owned adapter before scaffolding runtime internals.',
				'Add module docs and index entries alongside focused unit or route-free harness coverage.',
				'Run release triage and doctor checks before presenting the module as public.',
			],
			'verification'=>[
				'dataphyre_module_describe',
				'dataphyre_docs_chunks_export',
				'dataphyre_release_triage_summary',
				'dataphyre_php_lint',
			],
		];
	}

	/**
	 * Describes reusable safety boundaries for static Panel metadata payloads.
	 */
	private function panel_metadata_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'classification'=>'panel_static_metadata_only',
			'application_default'=>'safe_for_panel_application_planning_without_generation_or_runtime_execution',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('panel_'.$surface),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('panel_'.$surface),
			'allowed_for_app_agents'=>[
				'Panel scaffold kind and package-template inventory',
				'Panel package, theme, documentation, and media manifest contracts',
				'public class and method signatures from first-party Panel source',
				'storage/upload contract metadata without storage operations',
				'focused Panel verification tool selection',
			],
			'not_performed'=>[
				'generator execution',
				'package installation or rollback',
				'theme preview rendering',
				'documentation site build',
				'storage driver calls',
				'upload route dispatch',
				'file writes',
			],
			'escalate_only_for'=>'Use heavier review only for release-facing Panel package/theme/media claims, security-sensitive upload/storage behavior, Dataphyre framework changes, or shared hot-path work.',
		];
	}

	/**
	 * Returns Panel scaffold patterns and verification hints.
	 *
	 * the catalog is a static planning surface for resources, fields,
	 * actions, relations, themes, and regression harnesses, not a generator that
	 * modifies panel code.
	 */
	private function panel_scaffold_catalog(): array {
		$description=$this->describe_module('panel', 250);
		$files=array_values(array_filter(
			array_merge($description['files']['framework'] ?? [], $description['files']['kernel'] ?? [], $description['files']['documentation'] ?? []),
			static fn(string $path): bool => str_contains($path, '/Scaffolding/')
				|| str_contains($path, '/Packages/')
				|| str_contains($path, '/Testing/')
				|| str_contains($path, 'Panel_Capability_Audit')
				|| str_contains($path, 'panel_regression')
		));
		$classes=[];
		foreach($files as $relative){
			if(strtolower(pathinfo($relative, PATHINFO_EXTENSION))!=='php'){
				continue;
			}
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$methods[]=[
						'name'=>$method['name'] ?? '',
						'static'=>$method['static'] ?? false,
						'signature'=>$method['signature'] ?? '',
					];
				}
				$classes[]=[
					'file'=>$relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'panel_metadata_safety'=>$this->panel_metadata_safety_contract('scaffold_catalog'),
			'catalog_files'=>$files,
			'classes'=>$classes,
			'known_scaffold_kinds'=>['resource', 'page', 'provider', 'plugin', 'theme', 'test', 'suite'],
			'package_template_artifacts'=>['dataphyre-panel-package.json', 'src/*Plugin.php', 'src/*Provider.php', 'src/*Theme.php', 'README.md', 'docs/compatibility.md', 'tests/*PackageTest.php', 'marketplace/listing.json'],
			'recommended_mcp_tools'=>['dataphyre_module_docs_pack', 'dataphyre_scaffold_plan_generate', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check'],
			'safety_notes'=>[
				'This catalog only inspects source text and PHP tokens.',
				'Use dataphyre_scaffold_plan_generate for MCP dry-run plans; do not write generated artifacts from this catalog.',
			],
		];
	}

	/**
	 * Summarizes Panel package manifest contracts.
	 *
	 * this static summary describes expected manifest shape, source files,
	 * and verification boundaries for Panel packages without loading packages or
	 * registering runtime components.
	 */
	private function panel_package_manifest_summary(): array {
		$root=$this->common_root.'/dataphyre/runtime/modules/panel/Framework/Packages';
		$classes=[];
		$files=$this->files_under($root, ['php'], 80);
		foreach($files as $relative){
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$methods[]=[
						'name'=>$method['name'] ?? '',
						'static'=>$method['static'] ?? false,
						'signature'=>$method['signature'] ?? '',
					];
				}
				$classes[]=[
					'file'=>$relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'panel_metadata_safety'=>$this->panel_metadata_safety_contract('package_manifest'),
			'package_files'=>$files,
			'classes'=>$classes,
			'manifest_contract'=>[
				'filename'=>'dataphyre-panel-package.json',
				'fields'=>['id', 'label', 'version', 'description', 'class', 'type', 'status', 'requirements', 'provides', 'links', 'support', 'signature', 'meta', 'compatibility'],
				'requirement_fields'=>['php', 'panel', 'reactor', 'modules', 'themes'],
				'known_manifest_types'=>['panel_package_manifest', 'panel_package_template', 'panel_package_repository', 'panel_package_install_plan', 'panel_package_apply_result', 'panel_package_lock', 'panel_package_rollback_plan', 'panel_compatibility_matrix', 'panel_package_trust_report'],
			],
			'template_artifacts'=>['dataphyre-panel-package.json', 'src/*Plugin.php', 'src/*Provider.php', 'src/*Theme.php', 'README.md', 'docs/compatibility.md', 'tests/*PackageTest.php', 'marketplace/listing.json'],
			'workflow_surfaces'=>[
				'template'=>'PanelPackageTemplate builds package artifacts.',
				'repository'=>'PanelPackageRepository discovers manifests and emits repository and lock manifests.',
				'install_plan'=>'PanelPackageInstallPlan creates dry-run/apply manifests with conflicts, compatibility, trust, and steps.',
				'rollback'=>'PanelPackageRollbackPlan describes rollback from apply results or install plans.',
				'trust'=>'PanelPackageTrustPolicy and PanelPackageTrustReport describe package trust checks.',
				'compatibility'=>'PanelCompatibilityMatrix reports runtime/package compatibility.',
			],
			'safety_notes'=>[
				'This summary tokenizes Panel package source files only.',
				'No package manifest is applied, no package files are written, and no package repository directory is scanned outside the first-party source tree.',
			],
		];
	}

	/**
	 * Summarizes Panel theme manifest contracts.
	 *
	 * theme metadata is described as a static asset/configuration surface
	 * covering tokens, CSS, preview behavior, and verification, with no asset build
	 * or browser rendering performed by the MCP tool.
	 */
	private function panel_theme_manifest_summary(): array {
		$root=$this->common_root.'/dataphyre/runtime/modules/panel/Framework/Theming';
		$classes=[];
		$files=$this->files_under($root, ['php'], 80);
		foreach($files as $relative){
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$methods[]=[
						'name'=>$method['name'] ?? '',
						'static'=>$method['static'] ?? false,
						'signature'=>$method['signature'] ?? '',
					];
				}
				$classes[]=[
					'file'=>$relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'panel_metadata_safety'=>$this->panel_metadata_safety_contract('theme_manifest'),
			'theme_files'=>$files,
			'classes'=>$classes,
			'manifest_contract'=>[
				'type'=>'theme_manifest',
				'fields'=>['name', 'active', 'library', 'diagnostics', 'tokens', 'modes', 'assets', 'capabilities', 'meta', 'preview'],
				'token_groups'=>['light', 'dark', 'variables', 'dark_variables'],
				'mode_fields'=>['dark_mode', 'default', 'toggle', 'available'],
				'asset_fields'=>['asset_roots', 'font', 'font_url', 'font_provider', 'favicon', 'brand', 'stylesheets'],
				'capability_groups'=>['colors', 'tokens', 'modes', 'assets', 'library'],
			],
			'ecosystem_surfaces'=>[
				'theme'=>'PanelTheme defines tokens, colors, modes, assets, diagnostics, preview metadata, and manifest writes.',
				'preset'=>'PanelThemePreset defines reusable color/token/asset bundles.',
				'library'=>'PanelThemeLibrary registers presets/themes, diagnostics, manifests, previews, and exported manifests.',
				'asset'=>'PanelThemeAsset describes stylesheet/script/font asset entries.',
				'preview'=>'PanelThemePreview renders preview HTML from preview arrays or themes.',
			],
			'safety_notes'=>[
				'This summary tokenizes theme source files only.',
				'No theme preview is rendered, no manifest is written, and no theme asset path is loaded.',
			],
		];
	}

	/**
	 * Summarizes Panel documentation catalog surfaces.
	 *
	 * the catalog describes how Panel docs are organized and consumed by
	 * MCP planning workflows, keeping the output static and bounded to local
	 * markdown/source metadata.
	 */
	private function panel_documentation_catalog_summary(): array {
		$roots=[
			$this->common_root.'/dataphyre/runtime/modules/panel/Framework/Documentation',
			$this->common_root.'/dataphyre/runtime/modules/panel/Framework/Core',
		];
		$classes=[];
		$files=[];
		foreach($roots as $root){
			foreach($this->files_under($root, ['php'], 80) as $relative){
				$text=$this->read_repo_text($relative, 120000);
				if(!str_contains($text, 'PanelDocumentation') && !str_contains($text, 'documentationCatalog')){
					continue;
				}
				$files[]=$relative;
				$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
				foreach($summary['classes'] ?? [] as $class){
					$methods=[];
					foreach($class['methods'] ?? [] as $method){
						if(($method['visibility'] ?? 'public')!=='public'){
							continue;
						}
						$methods[]=[
							'name'=>$method['name'] ?? '',
							'static'=>$method['static'] ?? false,
							'signature'=>$method['signature'] ?? '',
						];
					}
					$classes[]=[
						'file'=>$relative,
						'name'=>$class['fqcn'] ?? $class['name'] ?? '',
						'kind'=>$class['kind'] ?? 'class',
						'public_methods'=>$methods,
					];
				}
			}
		}
		sort($files);
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'panel_metadata_safety'=>$this->panel_metadata_safety_contract('documentation_catalog'),
			'documentation_files'=>$files,
			'classes'=>$classes,
			'manifest_contract'=>[
				'type'=>'panel_documentation_catalog',
				'catalog_fields'=>['type', 'entry_count', 'category_count', 'status_count', 'example_count', 'api_reference_count', 'link_count', 'categories', 'statuses', 'entries', 'meta'],
				'entry_fields'=>['id', 'title', 'category', 'status', 'summary', 'api', 'examples', 'links', 'tags', 'meta'],
				'entry_mutators'=>['id', 'title', 'category', 'status', 'summary', 'api', 'example', 'link', 'tag', 'tags', 'meta'],
				'query_methods'=>['entries', 'search', 'categories', 'statuses', 'manifest', 'toArray'],
			],
			'framework_surfaces'=>[
				'catalog'=>'PanelDocumentationCatalog registers entries, searches docs, and emits a manifest/toArray payload.',
				'entry'=>'PanelDocumentationEntry stores docs metadata, API references, examples, links, tags, and match text.',
				'facade'=>'Panel::documentationCatalog and Panel::documentationEntry create catalog and entry objects.',
				'instance'=>'PanelInstance::documentationCatalog and PanelInstance::documentationEntry expose the same builders for instance workflows.',
			],
			'safety_notes'=>[
				'This summary tokenizes Panel documentation source files only.',
				'No documentation catalog is built, no docs site is rendered, and no application bootstrap is required.',
			],
		];
	}

	/**
	 * Summarizes Panel media manifest contracts.
	 *
	 * this surface documents expected media metadata, asset ownership,
	 * safety boundaries, and verification hints without reading unbounded media
	 * files or transforming binary assets.
	 */
	private function panel_media_manifest_summary(): array {
		$files=[
			'common/dataphyre/runtime/modules/panel/Framework/Media/PanelMediaLibrary.php',
			'common/dataphyre/runtime/modules/panel/Framework/Media/PanelMediaCollection.php',
			'common/dataphyre/runtime/modules/panel/Framework/Media/PanelMediaItem.php',
			'common/dataphyre/runtime/modules/panel/Framework/Uploads/PanelStorageUploadEndpoint.php',
			'common/dataphyre/runtime/modules/panel/Framework/Http/PanelUploadController.php',
			'common/dataphyre/runtime/modules/panel/Framework/Http/PanelRoute.php',
			'common/dataphyre/runtime/modules/panel/Framework/Core/Panel.php',
			'common/dataphyre/runtime/modules/panel/Framework/Core/PanelInstance.php',
			'common/dataphyre/runtime/modules/panel/Framework/Forms/Field.php',
			'common/dataphyre/runtime/modules/panel/Framework/Resources/Resource.php',
		];
		$existing=[];
		$classes=[];
		foreach($files as $relative){
			if(!is_file($this->root.'/'.$relative)){
				continue;
			}
			$existing[]=$relative;
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$name=(string)($method['name'] ?? '');
					if(
						str_contains(strtolower($name), 'media')
						|| str_contains(strtolower($name), 'upload')
						|| str_contains(strtolower($name), 'attach')
						|| str_contains(strtolower($name), 'file')
						|| str_contains(strtolower($name), 'storage')
						|| in_array($name, ['handle', '__invoke', 'manifest', 'toArray', 'jsonSerialize', 'validate', 'item', 'collection', 'register'], true)
					){
						$methods[]=[
							'name'=>$name,
							'static'=>$method['static'] ?? false,
							'signature'=>$method['signature'] ?? '',
						];
					}
				}
				if($methods===[] && !str_contains((string)($class['fqcn'] ?? $class['name'] ?? ''), 'Media') && !str_contains((string)($class['fqcn'] ?? $class['name'] ?? ''), 'Upload')){
					continue;
				}
				$classes[]=[
					'file'=>$relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'panel_metadata_safety'=>$this->panel_metadata_safety_contract('media_manifest'),
			'media_files'=>$existing,
			'classes'=>$classes,
			'manifest_contract'=>[
				'type'=>'panel_media_manifest',
				'library_fields'=>['collection_count', 'variant_count', 'collections', 'metadata'],
				'collection_fields'=>['name', 'label', 'disk', 'path', 'resolved_path', 'visibility', 'multiple', 'accepted_types', 'min_size', 'max_size', 'variants', 'cleanup', 'metadata', 'preview_types', 'has_custom_validator'],
				'item_fields'=>['id', 'collection', 'disk', 'path', 'filename', 'original_name', 'mime', 'extension', 'size', 'visibility', 'url', 'previewable', 'variants', 'metadata', 'validation'],
				'upload_post_fields'=>['file', 'upload_id', 'filename', 'chunks', 'chunk_index', 'size', 'type', 'storage_disk', 'storage_path', 'storage_visibility', 'storage_collection', 'field', 'dp_panel_upload_delete'],
				'storage_path_tokens'=>['{date}', '{field}', '{collection}', '{filename}', '{original}', '{name}', '{ext}', '{hash}', '{id}'],
			],
			'framework_surfaces'=>[
				'library'=>'PanelMediaLibrary registers collections, validates files, creates media items, and emits collection manifests.',
				'collection'=>'PanelMediaCollection defines disks, paths, visibility, accepted types, sizes, variants, cleanup policy, preview types, and validators.',
				'item'=>'PanelMediaItem normalizes file metadata, validation results, previewability, variants, and serialized item fields.',
				'field'=>'Field::fileUpload, imageUpload, storageUploader, dataphyreStorageUpload, and mediaCollection define upload-oriented form metadata.',
				'resource'=>'Resource attachment hooks expose record attachment listing and upload attachment handlers.',
				'upload_endpoint'=>'PanelStorageUploadEndpoint handles chunk assembly, Storage persistence, metadata, temporary URLs, and delete requests at runtime.',
				'http_route'=>'PanelRoute and PanelUploadController expose upload routes/controllers for applications that opt in.',
			],
			'storage_operations_not_run'=>['Storage::putFile', 'Storage::metadata', 'Storage::temporaryUrl', 'Storage::delete', 'move_uploaded_file', 'rename', 'copy', 'file_put_contents'],
			'safety_notes'=>[
				'This summary tokenizes first-party Panel source files only.',
				'No upload chunks are moved, no temporary files are created, no Storage driver is called, and no upload route is dispatched.',
			],
		];
	}
}
