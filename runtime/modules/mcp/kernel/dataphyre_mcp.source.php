<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines Mcp kernel trait responsibilities for dataphyre mcp source surfaces.
 *
 * Mcp kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait dataphyre_mcp_source_surfaces {

	/**
	 * Describes safe source/docs discovery payloads for application agents.
	 *
	 * @param string $surface MCP source or documentation discovery surface.
	 * @return array<string,mixed> Discovery safety contract.
	 */
	private function discovery_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'application_default'=>'safe_for_application_planning_without_runtime_execution',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('discovery_'.$surface),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('discovery_'.$surface),
			'not_performed'=>[
				'application bootstrap',
				'route dispatch',
				'controller invocation',
				'dependency installation',
				'package manager script execution',
				'SQL query execution',
				'config secret resolution',
				'file writes',
			],
			'agent_guidance'=>'Use discovery payloads to understand app/module shape before planning app-owned changes; do not treat source/docs discovery as permission to patch Dataphyre internals for one application.',
		];
	}

	/**
	 * Describes safe handling for startup/runtime envelope metadata.
	 *
	 * @return array<string,mixed> Startup payload safety contract.
	 */
	private function startup_safety_contract(): array {
		return [
			'application_default'=>'safe_for_local_client_startup_and_capability_selection',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('application_info'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('application_info'),
			'local_only_fields'=>[
				'root',
				'git.stdout',
				'git.stderr',
			],
			'default_handoff'=>'copy_safe_startup_summary',
			'redact_before_sharing'=>[
				'machine-local absolute paths',
				'branch names that reveal customers, tenants, incidents, or private product names',
				'dirty-file paths that reveal private applications, tenant names, or product identifiers',
			],
			'not_performed'=>[
				'application bootstrap',
				'configuration secret reads',
				'route dispatch',
				'SQL query execution',
				'file writes',
				'dependency installation',
			],
			'claim_boundary'=>'Startup metadata helps a local agent choose MCP tools and understand module availability; it is not application behavior proof and should be redacted before shared diagnostics.',
		];
	}

	/**
	 * Reports the MCP server's current application and runtime envelope.
	 *
	 * The payload is read-only and intentionally shallow: it exposes root/runtime
	 * presence, Git status text, declared modules, internal MCP declaration count,
	 * and unsafe-tool enablement without returning configuration secrets.
	 *
	 * @return array{root:string,php:string,dataphyre_runtime:bool,git:mixed,modules:array<int,string>,internal_module_declarations:int,unsafe_tools_enabled:bool,copy_safe_startup_summary:array<string,mixed>} Application snapshot for MCP clients.
	 */
	private function application_info(): array {
		$dataphyre_runtime=is_file($this->common_root.'/dataphyre/runtime/bootstrap.php');
		$git=$this->run_command(['git', 'status', '--short', '--branch'], 5000, false);
		$modules=array_column($this->module_list()['modules'], 'name');
		$internal_module_declarations=count($this->mcp_module_declarations());
		$applications=$this->application_catalog();
		return [
			'write_policy'=>'read_only',
			'execution'=>'bounded_local_git_status',
			'startup_safety'=>$this->startup_safety_contract(),
			'default_handoff'=>'copy_safe_startup_summary',
			'root'=>$this->root,
			'php'=>PHP_VERSION,
			'dataphyre_runtime'=>$dataphyre_runtime,
			'git'=>$git,
			'modules'=>$modules,
			'applications'=>$applications,
			'internal_module_declarations'=>$internal_module_declarations,
			'unsafe_tools_enabled'=>$this->allow_unsafe,
			'copy_safe_startup_summary'=>$this->copy_safe_startup_summary($modules, $internal_module_declarations, $dataphyre_runtime, is_array($git), $applications),
		];
	}

	/**
	 * Builds a read-only catalog of local application candidates.
	 *
	 * @return array<string,mixed> Bounded application discovery metadata.
	 */
	private function application_catalog(array $args=[]): array {
		$applications_root=$this->root.'/applications';
		$items=[];
		$scope=trim(str_replace('\\', '/', (string)($args['scope'] ?? '')));
		$scope=trim($scope, '/');
		if(str_starts_with($scope, 'applications/')){
			$scope=substr($scope, strlen('applications/'));
		}
		$include_config_files=($args['include_config_files'] ?? true)!==false;
		$limit=max(1, min(100, (int)($args['limit'] ?? 50)));
		if(is_dir($applications_root)){
			$directories=[];
			foreach(new DirectoryIterator($applications_root) as $entry){
				if($entry->isDot() || !$entry->isDir()){
					continue;
				}
				$name=$entry->getFilename();
				if($name==='' || $name[0]==='.'){
					continue;
				}
				if($scope!=='' && $name!==$scope){
					continue;
				}
				$directories[]=$name;
			}
			natcasesort($directories);
			foreach(array_slice(array_values($directories), 0, $limit) as $name){
				$items[]=$this->application_catalog_item($name, $include_config_files);
			}
		}
		$with_dataphyre=count(array_filter($items, static fn(array $item): bool => ($item['dataphyre_root_exists'] ?? false)===true));
		return [
			'write_policy'=>'read_only',
			'execution'=>'filesystem_metadata_only',
			'root'=>'applications',
			'scope'=>$scope,
			'include_config_files'=>$include_config_files,
			'limit'=>$limit,
			'candidate_count'=>count($items),
			'dataphyre_app_count'=>$with_dataphyre,
			'items'=>$items,
			'copy_safe_summary'=>[
				'candidate_count'=>count($items),
				'dataphyre_app_count'=>$with_dataphyre,
				'application_ids'=>array_values(array_map(static fn(array $item): string => (string)($item['application_id'] ?? ''), array_filter($items, static fn(array $item): bool => ($item['dataphyre_root_exists'] ?? false)===true))),
			],
			'not_performed'=>[
				'application bootstrap',
				'configuration value reads',
				'route dispatch',
				'SQL query execution',
				'file writes',
			],
			'agent_use'=>'Use application_path=applications/<app> or applications/<app>/backend/dataphyre with app_namespace when confidence is sufficient; verify local conventions before writing.',
		];
	}

	/**
	 * Builds one application catalog row.
	 *
	 * @param string $name Directory name below applications/.
	 * @return array<string,mixed> One bounded application candidate.
	 */
	private function application_catalog_item(string $name, bool $include_config_files=true): array {
		$application_path='applications/'.$name;
		$application_root=$this->root.'/'.$application_path;
		$backend_dataphyre=$application_root.'/backend/dataphyre';
		$direct_dataphyre=$application_root.'/dataphyre';
		$dataphyre_root=null;
		$detected_layout='no_dataphyre_root_detected';
		if(is_dir($backend_dataphyre)){
			$dataphyre_root=$application_path.'/backend/dataphyre';
			$detected_layout='dataphyre_backend_root';
		}elseif(is_dir($direct_dataphyre)){
			$dataphyre_root=$application_path.'/dataphyre';
			$detected_layout='direct_dataphyre_root';
		}
		$config_root=is_string($dataphyre_root) ? $this->root.'/'.$dataphyre_root.'/config' : null;
		$config_files=[];
		if($include_config_files && is_string($config_root) && is_dir($config_root)){
			foreach(new DirectoryIterator($config_root) as $entry){
				if($entry->isDot() || !$entry->isFile() || strtolower($entry->getExtension())!=='php'){
					continue;
				}
				$config_files[]='config/'.$entry->getFilename();
			}
			natcasesort($config_files);
			$config_files=array_values(array_slice($config_files, 0, 30));
		}
		$namespace_hint=$this->application_namespace_hint(is_string($dataphyre_root) ? $dataphyre_root : null, $name);
		$framework_path=is_string($dataphyre_root) ? $dataphyre_root.'/Framework' : null;
		return [
			'application_id'=>$name,
			'application_path'=>$application_path,
			'dataphyre_root'=>$dataphyre_root,
			'root_exists'=>is_dir($application_root),
			'dataphyre_root_exists'=>is_string($dataphyre_root),
			'detected_layout'=>$detected_layout,
			'path_confidence'=>is_string($dataphyre_root) ? 'detected_existing_dataphyre_root' : 'directory_without_detected_dataphyre_root',
			'candidate_application_path_forms'=>is_string($dataphyre_root) ? [$application_path, $dataphyre_root] : [$application_path],
			'config_files'=>$config_files,
			'config_file_count'=>count($config_files),
			'has_mvc_config'=>in_array('config/mvc.php', $config_files, true),
			'has_panel_config'=>in_array('config/panel.php', $config_files, true),
			'has_sql_config'=>in_array('config/sql.php', $config_files, true),
			'has_storage_config'=>in_array('config/storage.php', $config_files, true),
			'framework_path'=>$framework_path,
			'framework_path_exists'=>is_string($framework_path) && is_dir($this->root.'/'.$framework_path),
			'plugins_path_exists'=>is_string($dataphyre_root) && is_dir($this->root.'/'.$dataphyre_root.'/plugins'),
			'unit_tests_path_exists'=>is_string($dataphyre_root) && is_dir($this->root.'/'.$dataphyre_root.'/unit_tests'),
			'namespace_hint'=>$namespace_hint,
		];
	}

	/**
	 * Infers a namespace hint from known static app config text.
	 *
	 * @param ?string $dataphyre_root Repo-relative Dataphyre app root.
	 * @param string $application_id Application directory name.
	 * @return array<string,mixed> Namespace hint and confidence.
	 */
	private function application_namespace_hint(?string $dataphyre_root, string $application_id): array {
		if(is_string($dataphyre_root)){
			$mvc_config=$this->root.'/'.$dataphyre_root.'/config/mvc.php';
			if(is_file($mvc_config)){
				$text=(string)file_get_contents($mvc_config, false, null, 0, 20000);
				if(preg_match("/'namespace'\\s*=>\\s*'([^']+)\\\\\\\\(?:Controllers|Models)'/", $text, $match)===1){
					return [
						'namespace'=>str_replace('\\\\', '\\', (string)$match[1]),
						'source'=>$dataphyre_root.'/config/mvc.php',
						'confidence'=>'static_mvc_namespace',
					];
				}
				if(preg_match('/"namespace"\\s*=>\\s*"([^"]+)\\\\\\\\(?:Controllers|Models)"/', $text, $match)===1){
					return [
						'namespace'=>str_replace('\\\\', '\\', (string)$match[1]),
						'source'=>$dataphyre_root.'/config/mvc.php',
						'confidence'=>'static_mvc_namespace',
					];
				}
			}
		}
		return [
			'namespace'=>$this->studly_name($application_id),
			'source'=>'application_id_fallback',
			'confidence'=>'fallback_guess',
		];
	}

	/**
	 * Builds a startup summary safe to share outside the local agent session.
	 *
	 * @param array<int,string> $modules Declared module names.
	 * @param int $internal_module_declarations Number of MCP module declarations.
	 * @param bool $dataphyre_runtime Whether runtime bootstrap is present.
	 * @param bool $git_status_available Whether bounded local git status was collected.
	 * @return array<string,mixed> Copy-safe startup handoff.
	 */
	private function copy_safe_startup_summary(array $modules, int $internal_module_declarations, bool $dataphyre_runtime, bool $git_status_available, array $applications=[]): array {
		return [
			'surface'=>'dataphyre_application_info',
			'owner'=>'local_agent',
			'share_default'=>'copy_safe_summary_only',
			'handoff_status'=>'copy_safe_summary_ready',
			'evidence'=>[
				'php_version_major'=>PHP_MAJOR_VERSION,
				'php_version_minor'=>PHP_MINOR_VERSION,
				'dataphyre_runtime_present'=>$dataphyre_runtime,
				'module_count'=>count($modules),
				'mcp_module_available'=>in_array('mcp', $modules, true),
				'internal_module_declarations'=>$internal_module_declarations,
				'unsafe_tools_enabled'=>$this->allow_unsafe,
				'local_git_status_available'=>$git_status_available,
				'application_candidate_count'=>(int)($applications['candidate_count'] ?? 0),
				'dataphyre_application_count'=>(int)($applications['dataphyre_app_count'] ?? 0),
			],
			'next_reads'=>[
				'dataphyre_mcp_tool_finder',
				'dataphyre_mcp_resource_finder',
				'dataphyre_mcp_capability_matrix',
			],
			'copy_fields'=>[
				'copy_safe_startup_summary.evidence',
				'copy_safe_startup_summary.next_reads',
				'startup_safety.claim_boundary',
			],
			'not_included'=>[
				'root',
				'git.stdout',
				'git.stderr',
				'branch names',
				'dirty-file paths',
				'secrets',
				'source-checkout dev tool output',
				'dataphyre_mcp_verify_all output',
				'Dataphyre benchmark output',
			],
			'policy'=>'Share this summary instead of local root paths, git output, branch names, dirty-file paths, dev helper output, release proof, or benchmark output.',
		];
	}

	/**
	 * Reads composer.json and package.json manifests from repo-local paths.
	 *
	 * When explicit paths are supplied, each path must resolve through
	 * safe_repo_path() and end at a supported manifest filename. Without paths,
	 * the repository is scanned up to the requested limit. Manifest contents are
	 * summarized, not executed, installed, or otherwise trusted.
	 *
	 * @param array<string,mixed> $args Optional `paths` and `limit` arguments from the MCP tool request.
	 * @return array{manifest_count:int,manifests:array<int,array<string,mixed>>} Manifest summaries.
	 */
	private function read_package_metadata(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 20) ?: 20, 100));
		$paths=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$safe=$this->safe_repo_path((string)$path);
				$base=basename($safe);
				if(!is_file($safe) || !in_array($base, ['composer.json', 'package.json'], true)){
					throw new InvalidArgumentException('paths must point to repo-local composer.json or package.json files.');
				}
				$paths[]=$safe;
			}
		}else{
			foreach($this->all_files($this->root, 30000) as $path){
				$base=basename($path);
				if(in_array($base, ['composer.json', 'package.json'], true)){
					$paths[]=$path;
					if(count($paths)>=$limit){
						break;
					}
				}
			}
		}
		$packages=[];
		foreach(array_slice(array_values(array_unique($paths)), 0, $limit) as $path){
			$packages[]=$this->package_metadata_summary($path);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('package_metadata'),
			'manifest_count'=>count($packages),
			'manifests'=>$packages,
		];
	}

	/**
	 * Summarizes one package manifest without resolving dependencies.
	 *
	 * Invalid JSON is returned as a structured error row so MCP callers can see
	 * which manifest failed while the broader request continues.
	 *
	 * @param string $path Absolute repo-local manifest path.
	 * @return array<string,mixed> Composer or Node package metadata summary.
	 */
	private function package_metadata_summary(string $path): array {
		$text=(string)file_get_contents($path);
		$data=json_decode($text, true);
		$relative=$this->relative_path($path);
		if(!is_array($data)){
			return [
				'path'=>$relative,
				'type'=>basename($path),
				'valid_json'=>false,
				'json_error'=>json_last_error_msg(),
			];
		}
		return basename($path)==='composer.json'
			? $this->composer_metadata_summary($relative, $data)
			: $this->node_package_metadata_summary($relative, $data);
	}

	/**
	 * Builds the Composer-specific manifest summary.
	 *
	 * Dependency maps are sorted and scalarized so Datadoc/MCP clients can compare
	 * manifests deterministically without receiving arbitrary nested composer
	 * structures.
	 *
	 * @param string $path Repo-relative composer.json path.
	 * @param array<string,mixed> $data Decoded Composer manifest.
	 * @return array<string,mixed> Composer package metadata summary.
	 */
	private function composer_metadata_summary(string $path, array $data): array {
		return [
			'path'=>$path,
			'type'=>'composer',
			'valid_json'=>true,
			'name'=>$data['name'] ?? null,
			'description'=>$data['description'] ?? null,
			'license'=>$data['license'] ?? null,
			'type_value'=>$data['type'] ?? null,
			'php_requirement'=>is_array($data['require'] ?? null) ? ($data['require']['php'] ?? null) : null,
			'support'=>$this->composer_support_summary($data['support'] ?? null),
			'require'=>$this->dependency_map($data['require'] ?? []),
			'require_dev'=>$this->dependency_map($data['require-dev'] ?? []),
			'autoload_keys'=>is_array($data['autoload'] ?? null) ? array_keys($data['autoload']) : [],
			'autoload_dev_keys'=>is_array($data['autoload-dev'] ?? null) ? array_keys($data['autoload-dev']) : [],
			'script_names'=>is_array($data['scripts'] ?? null) ? array_keys($data['scripts']) : [],
			'config_keys'=>is_array($data['config'] ?? null) ? array_keys($data['config']) : [],
			'extra_keys'=>is_array($data['extra'] ?? null) ? array_keys($data['extra']) : [],
			'dataphyre_extra'=>$this->composer_dataphyre_extra_summary($data['extra']['dataphyre'] ?? null),
		];
	}

	/**
	 * Summarizes safe Composer support links without returning arbitrary metadata.
	 *
	 * @param mixed $support Composer support value.
	 * @return array<string,string>
	 */
	private function composer_support_summary(mixed $support): array {
		if(!is_array($support)){
			return [];
		}
		$summary=[];
		foreach(['docs', 'issues', 'security', 'source'] as $key){
			$value=trim((string)($support[$key] ?? ''));
			if($value!==''){
				$summary[$key]=$value;
			}
		}
		return $summary;
	}

	/**
	 * Summarizes Dataphyre-owned Composer extra metadata without returning arbitrary nested data.
	 *
	 * @param mixed $extra Composer extra.dataphyre value.
	 * @return array<string,mixed>|null Stable package contract subset, when present.
	 */
	private function composer_dataphyre_extra_summary(mixed $extra): ?array {
		if(!is_array($extra)){
			return null;
		}
		$agent_boundary=is_array($extra['agent-boundary'] ?? null) ? $extra['agent-boundary'] : [];
		return [
			'runtime_bootstrap'=>$extra['runtime-bootstrap'] ?? null,
			'package_contract'=>$extra['package-contract'] ?? null,
			'release_manifest'=>$extra['release-manifest'] ?? null,
			'agent_boundary'=>[
				'default_audience'=>$agent_boundary['default-audience'] ?? null,
				'mcp_default_work'=>$agent_boundary['mcp-default-work'] ?? null,
				'ordinary_app_entrypoint'=>$agent_boundary['ordinary-app-entrypoint'] ?? null,
				'ordinary_app_payload_profile'=>$agent_boundary['ordinary-app-payload-profile'] ?? null,
				'agentic_enterprise_contract'=>$agent_boundary['agentic-enterprise-contract'] ?? null,
				'framework_maintenance'=>$agent_boundary['framework-maintenance'] ?? null,
				'escalate_only_for'=>array_values(array_filter(array_map(static fn(mixed $value): string => (string)$value, is_array($agent_boundary['escalate-only-for'] ?? null) ? $agent_boundary['escalate-only-for'] : []))),
				'extension_points'=>array_values(array_filter(array_map(static fn(mixed $value): string => (string)$value, is_array($agent_boundary['extension-points'] ?? null) ? $agent_boundary['extension-points'] : []))),
				'app_owned_extension_points'=>array_values(array_filter(array_map(static fn(mixed $value): string => (string)$value, is_array($agent_boundary['app-owned-extension-points'] ?? null) ? $agent_boundary['app-owned-extension-points'] : []))),
				'app_builder_handoff_fields'=>array_values(array_filter(array_map(static fn(mixed $value): string => (string)$value, is_array($agent_boundary['app-builder-handoff-fields'] ?? null) ? $agent_boundary['app-builder-handoff-fields'] : []))),
				'not_default_requirements'=>array_values(array_filter(array_map(static fn(mixed $value): string => (string)$value, is_array($agent_boundary['not-default-requirements'] ?? null) ? $agent_boundary['not-default-requirements'] : []))),
			],
		];
	}

	/**
	 * Builds the Node package-specific manifest summary.
	 *
	 * The summary captures dependency groups, scripts, engines, and package
	 * identity while omitting lockfile-level resolution data and arbitrary nested
	 * package metadata.
	 *
	 * @param string $path Repo-relative package.json path.
	 * @param array<string,mixed> $data Decoded Node package manifest.
	 * @return array<string,mixed> Node package metadata summary.
	 */
	private function node_package_metadata_summary(string $path, array $data): array {
		return [
			'path'=>$path,
			'type'=>'package',
			'valid_json'=>true,
			'name'=>$data['name'] ?? null,
			'description'=>$data['description'] ?? null,
			'version'=>$data['version'] ?? null,
			'private'=>$data['private'] ?? null,
			'dependencies'=>$this->dependency_map($data['dependencies'] ?? []),
			'dev_dependencies'=>$this->dependency_map($data['devDependencies'] ?? []),
			'peer_dependencies'=>$this->dependency_map($data['peerDependencies'] ?? []),
			'script_names'=>is_array($data['scripts'] ?? null) ? array_keys($data['scripts']) : [],
			'engine_keys'=>is_array($data['engines'] ?? null) ? array_keys($data['engines']) : [],
		];
	}

	/**
	 * Normalizes a dependency section into a sorted name-to-constraint map.
	 *
	 * Non-array dependency sections become empty maps. Complex constraint values
	 * are collapsed to a marker because this surface reports dependency shape, not
	 * full package-manager semantics.
	 *
	 * @param mixed $dependencies Raw dependency section from a manifest.
	 * @return array<string,scalar|null|string> Sorted dependency constraints.
	 */
	private function dependency_map(mixed $dependencies): array {
		if(!is_array($dependencies)){
			return [];
		}
		$result=[];
		foreach($dependencies as $name=>$constraint){
			$name=(string)$name;
			if($name===''){
				continue;
			}
			$result[$name]=is_scalar($constraint) || $constraint===null ? $constraint : '[complex]';
		}
		ksort($result);
		return $result;
	}

	/**
	 * Statically summarizes API endpoint declarations in PHP source files.
	 *
	 * This is a read-only token scan. It discovers literal path arguments passed to
	 * common API verb helpers and OpenAPI document surfaces, but it never includes
	 * source files as PHP or dispatches handlers.
	 *
	 * @param array<string,mixed> $args Optional `paths` and `limit` arguments from the MCP tool request.
	 * @return array<string,mixed> Static endpoint and OpenAPI surface summary.
	 */
	private function api_docs_static_summary(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 250));
		$roots=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}else{
			$roots[]='common/dataphyre/runtime/modules/api';
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 4) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))==='php'){
						$files[]=$file;
					}
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$files=array_slice(array_values(array_unique($files)), 0, $limit);
		$endpoints=[];
		$open_api_surfaces=[];
		foreach($files as $file){
			$summary=$this->api_endpoint_declarations_from_file($file);
			$endpoints=array_merge($endpoints, $summary['endpoints']);
			$open_api_surfaces=array_merge($open_api_surfaces, $summary['openapi_surfaces']);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('api_docs_static_summary'),
			'scanned_files'=>count($files),
			'endpoint_count'=>count($endpoints),
			'openapi_surface_count'=>count($open_api_surfaces),
			'endpoints'=>array_slice($endpoints, 0, 200),
			'openapi_surfaces'=>array_slice($open_api_surfaces, 0, 80),
			'notes'=>[
				'Only literal string path arguments are reported.',
				'Handlers are summarized from source tokens and are not invoked.',
			],
		];
	}

	/**
	 * Extracts API endpoint-like calls from one PHP file.
	 *
	 * The scanner uses PHP tokens to find verb helper names, parse call arguments,
	 * keep only literal string paths, and preserve handler expressions as compact
	 * source text. Dynamic paths are intentionally omitted because their runtime
	 * value cannot be proven statically.
	 *
	 * @param string $path Absolute PHP source file path.
	 * @return array{endpoints:array<int,array<string,mixed>>,openapi_surfaces:array<int,array<string,mixed>>} Static declarations found in the file.
	 */
	private function api_endpoint_declarations_from_file(string $path): array {
		$text=(string)file_get_contents($path);
		$tokens=token_get_all($text);
		$endpoints=[];
		$open_api_surfaces=[];
		$verbs=['get', 'post', 'put', 'patch', 'delete', 'any', 'methods'];
		for($i=0, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(!is_array($token) || $token[0]!==T_STRING){
				continue;
			}
			$name=strtolower($token[1]);
			if($name==='openapidocument'){
				$open_api_surfaces[]=[
					'file'=>$this->relative_path($path),
					'line'=>$token[2] ?? null,
					'call'=>$this->api_call_target_kind($tokens, $i),
				];
				continue;
			}
			if(!in_array($name, $verbs, true)){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $i);
			if($args===null){
				continue;
			}
			$path_arg=$name==='methods' ? ($args[1] ?? null) : ($args[0] ?? null);
			$api_path=$this->literal_string_from_expression($path_arg ?? '');
			if($api_path===null || !$this->looks_like_api_path($api_path)){
				continue;
			}
			$methods=$name==='methods'
				? $this->literal_string_list_from_expression($args[0] ?? '')
				: [strtoupper($name)];
			if($methods===[]){
				$methods=['METHODS'];
			}
			$handler=$args[$name==='methods' ? 2 : 1] ?? null;
			$endpoints[]=[
				'file'=>$this->relative_path($path),
				'line'=>$token[2] ?? null,
				'call'=>$this->api_call_target_kind($tokens, $i),
				'methods'=>array_values(array_unique(array_map('strtoupper', $methods))),
				'path'=>$api_path,
				'handler'=>$handler!==null ? $this->compact_expression($handler, 160) : null,
			];
		}
		return [
			'endpoints'=>$endpoints,
			'openapi_surfaces'=>$open_api_surfaces,
		];
	}

	/**
	 * Classifies whether a detected API call is object, static, or bare.
	 *
	 * The classifier looks only at the meaningful token immediately before the
	 * call name, enough for MCP clients to distinguish router group calls from
	 * static facade calls without building a full AST.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Token index of the call name.
	 * @return string Call target kind label.
	 */
	private function api_call_target_kind(array $tokens, int $index): string {
		$previous=$this->previous_meaningful_token_index($tokens, $index);
		if($previous!==null && is_array($tokens[$previous]) && $tokens[$previous][0]===T_OBJECT_OPERATOR){
			return 'object_or_group_call';
		}
		if($previous!==null && is_array($tokens[$previous]) && $tokens[$previous][0]===T_DOUBLE_COLON){
			$class_index=$this->previous_meaningful_token_index($tokens, $previous);
			$class=is_int($class_index) && is_array($tokens[$class_index]) ? $tokens[$class_index][1] : '';
			return $class!=='' ? 'static:'.$class : 'static';
		}
		return 'function_or_method';
	}

	/**
	 * Parses the top-level argument expressions after a call-name token.
	 *
	 * Parentheses, brackets, and braces are depth-tracked so commas inside nested
	 * expressions do not split arguments. The returned strings are raw expression
	 * text for later literal extraction or compact display.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Token index of the call name.
	 * @return ?array<int,string> Argument expressions, or null when no balanced call follows.
	 */
	private function call_arguments_after_token(array $tokens, int $index): ?array {
		$open=null;
		for($i=$index+1, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
				continue;
			}
			if($token==='('){
				$open=$i;
			}
			break;
		}
		if($open===null){
			return null;
		}
		$args=[];
		$current='';
		$depth=0;
		for($i=$open+1, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if($token==='(' || $token==='[' || $token==='{'){
				$depth++;
			}elseif($token===')'){
				if($depth===0){
					if(trim($current)!==''){
						$args[]=trim($current);
					}
					return $args;
				}
				$depth--;
			}elseif($token===']' || $token==='}'){
				$depth=max(0, $depth-1);
			}elseif($token===',' && $depth===0){
				$args[]=trim($current);
				$current='';
				continue;
			}
			$current.=is_array($token) ? $token[1] : $token;
		}
		return null;
	}

	/**
	 * Extracts a single quoted literal string expression.
	 *
	 * Non-literal expressions return null, keeping static API summaries honest
	 * about values they can prove from source text alone.
	 *
	 * @param string $expression Raw argument expression.
	 * @return ?string Unescaped literal string value.
	 */
	private function literal_string_from_expression(string $expression): ?string {
		$expression=trim($expression);
		if($expression==='' || preg_match('/^([\'"])(.*)\1$/s', $expression, $match)!==1){
			return null;
		}
		return stripcslashes($match[2]);
	}

	/**
	 * Extracts all quoted literal strings from an expression.
	 *
	 * This supports method-list declarations where HTTP verbs are usually supplied
	 * as an array of string literals. Empty literals are discarded.
	 *
	 * @param string $expression Raw argument expression.
	 * @return array<int,string> Literal string values in source order.
	 */
	private function literal_string_list_from_expression(string $expression): array {
		if(preg_match_all('/([\'"])(.*?)\1/s', $expression, $matches)!==1){
			return [];
		}
		return array_values(array_filter(array_map(
			static fn(string $value): string => trim(stripcslashes($value)),
			$matches[2]
		), static fn(string $value): bool => $value!==''));
	}

	/**
	 * Applies a conservative heuristic for values that look like API paths.
	 *
	 * @param string $path Literal path candidate.
	 * @return bool Whether the value is likely an API route path.
	 */
	private function looks_like_api_path(string $path): bool {
		$path=trim($path);
		return $path!=='' && (str_starts_with($path, '/') || str_contains($path, '{') || str_contains($path, 'v1/') || str_contains($path, 'v2/'));
	}

	/**
	 * Compacts a source expression for manifest output.
	 *
	 * Whitespace is collapsed and overly long expressions are truncated so handler
	 * summaries stay bounded inside MCP responses.
	 *
	 * @param string $expression Raw source expression.
	 * @param int $max_length Maximum output length.
	 * @return string Compact expression text.
	 */
	private function compact_expression(string $expression, int $max_length): string {
		$expression=trim(preg_replace('/\s+/', ' ', $expression) ?? '');
		return strlen($expression)>$max_length ? substr($expression, 0, $max_length - 3).'...' : $expression;
	}

	/**
	 * Summarizes PHP source APIs for a module or explicit paths.
	 *
	 * Module mode reuses describe_module() to pick framework and kernel files.
	 * Path mode accepts repo-local files or directories. The summary is static and
	 * token-based, returning namespaces, classes, methods, functions, and compact
	 * signatures without loading application code.
	 *
	 * @param array<string,mixed> $args MCP request arguments: `module`, `paths`, and optional `limit`.
	 * @return array{module:?string,file_count:int,files:array<int,array<string,mixed>>} Source API summary.
	 */
	private function source_api_summary(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 40) ?: 40, 200));
		$roots=[];
		$module=trim((string)($args['module'] ?? ''));
		if($module!==''){
			$description=$this->describe_module($module, $limit);
			$roots=array_merge($description['files']['framework'] ?? [], $description['files']['kernel'] ?? []);
		}
		if(is_array($args['paths'] ?? null)){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}
		if($roots===[]){
			throw new InvalidArgumentException('module or paths is required.');
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 3) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))==='php'){
						$files[]=$file;
					}
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$summaries=[];
		foreach(array_slice(array_values(array_unique($files)), 0, $limit) as $file){
			$summaries[]=$this->php_source_api_file_summary($file);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('source_api_summary'),
			'module'=>$module !== '' ? $module : null,
			'file_count'=>count($summaries),
			'files'=>$summaries,
		];
	}

	/**
	 * Extracts namespace, class, method, and function declarations from one file.
	 *
	 * The token walker tracks brace depth to associate methods with the current
	 * class-like declaration. It does not evaluate attributes or docblocks here;
	 * the goal is a lightweight API surface map for MCP clients and Datadoc.
	 *
	 * @param string $path Absolute PHP source file path.
	 * @return array{path:string,namespace:string,classes:array<int,array<string,mixed>>,functions:array<int,array<string,mixed>>} Static API declaration summary.
	 */
	private function php_source_api_file_summary(string $path): array {
		$text=(string)file_get_contents($path);
		$tokens=token_get_all($text);
		$namespace='';
		$classes=[];
		$functions=[];
		$current_class=null;
		$brace_depth=0;
		$class_stack=[];
		$count=count($tokens);
		for($i=0; $i<$count; $i++){
			$token=$tokens[$i];
			if($token==='{'){
				$brace_depth++;
				continue;
			}
			if($token==='}'){
				$brace_depth=max(0, $brace_depth-1);
				while($class_stack!==[] && $brace_depth<=$class_stack[count($class_stack)-1]['brace']){
					array_pop($class_stack);
				}
				$current_class=$class_stack!==[] ? $class_stack[count($class_stack)-1]['index'] : null;
				continue;
			}
			if(!is_array($token)){
				continue;
			}
			if($token[0]===T_NAMESPACE){
				$namespace=$this->read_token_name($tokens, $i+1);
				continue;
			}
			if(in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)){
				if($this->previous_meaningful_token_id($tokens, $i)==T_DOUBLE_COLON){
					continue;
				}
				$name=$this->next_token_text($tokens, $i+1, T_STRING);
				if($name===''){
					continue;
				}
				$classes[]=[
					'name'=>$name,
					'fqcn'=>$namespace!=='' ? $namespace.'\\'.$name : $name,
					'kind'=>strtolower(substr(token_name($token[0]), 2)),
					'line'=>$token[2] ?? null,
					'methods'=>[],
				];
				$current_class=count($classes)-1;
				$class_stack[]=['index'=>$current_class, 'brace'=>$brace_depth];
				continue;
			}
			if($token[0]===T_FUNCTION){
				$name=$this->next_token_text($tokens, $i+1, T_STRING);
				if($name===''){
					continue;
				}
				$entry=[
					'name'=>$name,
					'line'=>$token[2] ?? null,
					'signature'=>$this->signature_from_tokens($tokens, $i),
				];
				if($current_class!==null && isset($classes[$current_class])){
					$entry['visibility']=$this->function_visibility($tokens, $i);
					$entry['static']=$this->function_is_static($tokens, $i);
					$classes[$current_class]['methods'][]=$entry;
				}else{
					$entry['fqfn']=$namespace!=='' ? $namespace.'\\'.$name : $name;
					$functions[]=$entry;
				}
			}
		}
		return [
			'path'=>$this->relative_path($path),
			'namespace'=>$namespace,
			'classes'=>$classes,
			'functions'=>$functions,
		];
	}

	/**
	 * Reads a qualified PHP name from a token stream.
	 *
	 * Reading stops at the namespace terminator or block opener. The returned name
	 * is trimmed of leading namespace separators for consistent summary output.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $start First token index after the namespace token.
	 * @return string Namespace or qualified symbol name.
	 */
	private function read_token_name(array $tokens, int $start): string {
		$name='';
		for($i=$start, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if($token===';' || $token==='{'){
				break;
			}
			if(is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)){
				$name.=$token[1];
			}
		}
		return trim($name, '\\');
	}

	/**
	 * Finds the next token text with a specific token id before declaration ends.
	 *
	 * This is used for class/function names and intentionally stops at `(`, `;`,
	 * or `{` to avoid scanning into unrelated declarations.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $start Starting token index.
	 * @param int $id Desired PHP token id.
	 * @return string Token text, or an empty string when not found safely.
	 */
	private function next_token_text(array $tokens, int $start, int $id): string {
		for($i=$start, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(is_array($token) && $token[0]===$id){
				return $token[1];
			}
			if($token==='(' || $token===';' || $token==='{'){
				return '';
			}
		}
		return '';
	}

	/**
	 * Returns the token id before an index, ignoring whitespace and comments.
	 *
	 * Punctuation tokens have no token id and therefore return null.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Current token index.
	 * @return ?int Previous meaningful PHP token id.
	 */
	private function previous_meaningful_token_id(array $tokens, int $index): ?int {
		for($i=$index-1; $i>=0; $i--){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
				continue;
			}
			return is_array($token) ? $token[0] : null;
		}
		return null;
	}

	/**
	 * Returns the token index before an index, ignoring whitespace and comments.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Current token index.
	 * @return ?int Previous meaningful token index.
	 */
	private function previous_meaningful_token_index(array $tokens, int $index): ?int {
		for($i=$index-1; $i>=0; $i--){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
				continue;
			}
			return $i;
		}
		return null;
	}

	/**
	 * Returns the token index after an index, ignoring whitespace and comments.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Current token index.
	 * @return ?int Next meaningful token index.
	 */
	private function next_meaningful_token_index(array $tokens, int $index): ?int {
		for($i=$index+1, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
				continue;
			}
			return $i;
		}
		return null;
	}

	/**
	 * Reconstructs a compact function or method signature from tokens.
	 *
	 * The signature starts at T_FUNCTION and stops before the body or abstract
	 * terminator, preserving parameter and return-type text while collapsing
	 * whitespace for response readability.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Token index of T_FUNCTION.
	 * @return string Compact function signature.
	 */
	private function signature_from_tokens(array $tokens, int $index): string {
		$parts=[];
		for($i=$index, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if($token==='{' || $token===';'){
				break;
			}
			$parts[]=is_array($token) ? $token[1] : $token;
		}
		return trim(preg_replace('/\s+/', ' ', implode('', $parts)) ?? '');
	}

	/**
	 * Determines a method's declared visibility from preceding tokens.
	 *
	 * Public is the default when no explicit visibility token is present, matching
	 * PHP method semantics for older style declarations.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Token index of T_FUNCTION.
	 * @return string public, protected, or private.
	 */
	private function function_visibility(array $tokens, int $index): string {
		for($i=$index-1; $i>=0; $i--){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_FINAL, T_ABSTRACT], true)){
				continue;
			}
			if(is_array($token) && in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)){
				return strtolower(substr(token_name($token[0]), 2));
			}
			break;
		}
		return 'public';
	}

	/**
	 * Determines whether a method declaration is static.
	 *
	 * The scan walks backward through modifiers and visibility tokens near the
	 * function declaration. It returns false for functions or methods without a
	 * static modifier.
	 *
	 * @param array<int,mixed> $tokens PHP token stream.
	 * @param int $index Token index of T_FUNCTION.
	 * @return bool Whether the declaration is static.
	 */
	private function function_is_static(array $tokens, int $index): bool {
		for($i=$index-1; $i>=0; $i--){
			$token=$tokens[$i];
			if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE], true)){
				continue;
			}
			return is_array($token) && $token[0]===T_STATIC;
		}
		return false;
	}

	/**
	 * Reports MCP capabilities, resources, and intentionally excluded powers.
	 *
	 * The snapshot is generated from the active tool and prompt registries so
	 * clients can understand the server contract. The exclusions document safety
	 * boundaries: query execution, route dispatch, schema hydration, secrets, and
	 * project-local server scripts are not exposed by this source surface.
	 *
	 * @return array{server:string,version:string,protocol:string,default_safety:string,unsafe_enabled:bool,application_agent_operating_contract:array<string,mixed>,ordinary_app_work:array<string,mixed>,tool_audience_boundaries:array<string,mixed>,agent_workload_summary:array<string,mixed>,diagnostic_handoff_summary:array<string,mixed>,tools:array<int,array<string,string>>,prompts:array<int,array<string,string>>,resources:array<int,string>,intentionally_not_exposed:array<int,string>} MCP capability manifest.
	 */
	private function capabilities_snapshot(): array {
		$registered_tools=$this->list_tools()['tools'];
		$tools=array_map(
			static fn(array $tool): array => [
				'name'=>(string)($tool['name'] ?? ''),
				'description'=>(string)($tool['description'] ?? ''),
			],
			$registered_tools
		);
		$prompts=array_map(
			static fn(array $prompt): array => [
				'name'=>(string)($prompt['name'] ?? ''),
				'description'=>(string)($prompt['description'] ?? ''),
			],
			$this->list_prompts()['prompts']
		);
		return [
			'server'=>'dataphyre-mcp',
			'version'=>'2.0.1',
			'protocol'=>'2025-11-25',
			'default_safety'=>'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'server_entrypoint_contract'=>$this->mcp_server_entrypoint_contract(),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('capabilities_resource'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('capabilities_resource'),
			'tool_audience_boundaries'=>$this->mcp_tool_audience_boundaries(array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $registered_tools)),
			'package_release_boundary'=>$this->mcp_package_release_boundary(),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'agent_workload_summary'=>[
				'policy'=>'Enterprise safety is progressive disclosure, not mandatory ceremony for ordinary app edits.',
				'default_start'=>'dataphyre_app_builder_plan_generate for build-shaped app work; read-only inspection tools for inspection-shaped work.',
				'discovery_contract'=>'Tool and resource finders expose discovery_contract with compact_fields and governance_inline=false.',
				'inline_first'=>['builder_first_read', 'next_action', 'next_detail_page', 'files_summary', 'schema_summary', 'scaffold_completion_summary', 'write_readiness', 'prewrite_checklist summary', 'policy_attention summary', 'verification_handoff', 'detail-page links'],
				'detail_pages'=>['planning', 'implementation', 'verification', 'controls'],
				'collapsed_until_needed'=>['raw handoff_fields', 'full code_skeletons', 'full implementation/verification/control handoffs'],
				'collapsed_until_explicit_escalation_request'=>['full contracts', 'status/safety reports', 'enterprise audit', 'workflow handoff/session details', 'publication validation'],
				'not_ordinary_app_ceremony'=>['dataphyre_mcp_verify_all', 'Dataphyre project-wide release validation', 'Dataphyre hot-path benchmarks', 'runtime-internal edits for one app'],
				'details'=>'dataphyre_mcp_readiness_report.agent_workload_policy',
			],
			'diagnostic_handoff_summary'=>[
				'default'=>'Share diagnostic_summary.copy_safe_evidence instead of raw Tracelog/log payloads.',
				'available_on'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error'],
				'copy_safe_fields'=>['diagnostic_summary.copy_safe_evidence', 'diagnostic_next_action', 'internal_share_default', 'external_share_default', 'safe_to_paste_externally', 'not_included'],
				'not_included'=>['raw logs', 'unredacted snippets', 'secrets', 'tenant/product identifiers', 'machine-local paths', 'dataphyre_mcp_verify_all output', 'Dataphyre benchmark output'],
				'next_action_contract'=>'diagnostic_summary.diagnostic_next_action names the next focused read-only tool after redacted evidence, such as inspect_redacted_artifact, inspect_redacted_matches, triage_redacted_error, or broaden_bounded_diagnostic_search.',
				'details'=>'dataphyre_mcp_readiness_report.diagnostic_handoff_policy',
			],
			'app_builder_readiness'=>[
				'default_entrypoint'=>'dataphyre_app_builder_plan_generate',
				'secondary_context'=>'dataphyre_task_pack_generate payload_profile=builder',
				'compact_handoff'=>'dataphyre_mcp_agent_brief_export',
				'compact_budget_policy'=>'Agent briefs do not inline payload_budget or escalation-policy lists; use dataphyre_app_builder_plan_generate detail/full payloads when payload-budget or extension/escalation policy detail is the next decision.',
				'broader_start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'supported_scaffold_types'=>['panel_resource', 'routing_controller', 'api_endpoint', 'sql_table', 'mvc_controller', 'runtime_module'],
				'ordinary_verification'=>'focused application or module checks',
				'chunking_contract'=>[
					'default_max_entities'=>4,
					'max_entities_cap'=>12,
					'planning_field'=>'entity_planning',
					'continuation_policy'=>'Follow scaffold_completion_summary.next_continuation or entity_planning.continuation_calls until deferred_entities is empty; continuation calls are executable planner calls and may carry chunk-scoped fields with field_scope=chunk_entities, application_path, app_namespace, payload_profile, and dependency_context with relationship dependencies plus tenant/actor/entitlement policy_context.',
				],
				'app_path_context_contract'=>[
					'first_read'=>'builder_response.app_path_context is present on direct app-builder plans and build-shaped start packs.',
					'placeholder_discovery'=>'When placeholder_mode=true, follow app_path_context.discovery_hint.next_tool=dataphyre_application_catalog for bounded app candidates, then supply repo-relative application_path and optional app_namespace back to the builder. Use dataphyre_application_info only when broader startup context is needed. When discovery_hint.status=concrete_app_path_not_found or invalid_application_path, use dataphyre_application_catalog to correct application_path and rerun the builder before writes.',
					'invalid_paths'=>'application_path rejects absolute paths, URLs, and .. traversal; invalid input sets path_input_valid=false, discovery_hint.status=invalid_application_path, and replace_placeholders remains a prewrite blocker.',
					'namespace_hints'=>'app_namespace must be a valid PHP namespace such as App, AcmePortal, or Acme\\Portal; invalid input sets namespace_input_valid=false, discovery_hint.status=invalid_app_namespace, falls generated namespace hints back to App, and stays a prewrite blocker until corrected.',
					'policy'=>'Ordinary app path discovery stays lightweight and does not require governance, MCP/release validation, or hot-path benchmark proof.',
				],
				'next_action_contract'=>'builder_response.first_read.next_action, builder_response.first_read.next_detail_page, builder_first_read.next_action, and builder_first_read.next_detail_page carry the actionable first-page resume cursor, write_readiness, verification_handoff.post_write_handoff_template, acceptance_review_plan.post_write_handoff_template, and the one-page detail recommendation. Compact app_builder_next_action mirrors only status/action/tool plus resume_cursor_ref, copy_forward_count, and handoff_pages_ref so agents can continue chunks, resolve blockers, or proceed through app-owned writes without duplicating first-page detail or deep governance context; workflow_handoff summaries in start packs collapse duplicate app_builder_next_action contracts to refs.',
				'compact_detail_count_contract'=>[
					'first_read'=>'builder_response.compact_detail_policy.detail_counts is present on compact direct app-builder plans.',
					'purpose'=>'Use detail_counts as a table of contents with counts and refs for files, schema, implementation_recipe.items, verification_execution_plan.items, acceptance_review_plan.items, and collapsed_sections.',
					'policy'=>'Open payload_profile=full only for the specific app-owned detail page or skeleton body needed next instead of scanning every compact section.',
				],
				'surface_execution_plan_contract'=>[
					'first_read'=>'builder_response.surface_execution_plan is present on direct app-builder plans and names primary surface, companion surface, ordered steps, companion_surface_handoff argument pointer, and companion_surface_handoff.endpoint_queue with follow_up_arguments for mixed Panel/API work.',
					'policy'=>'Use surface_execution_plan to finish entity chunks, plan companion API/routing surfaces from companion_surface_handoff.arguments and endpoint_queue follow_up_arguments, write app-owned files, and run focused verification without opening governance, release validation, or Dataphyre internals.',
				],
				'recovery_hints_contract'=>[
					'compact_lane'=>'builder_response.recovery_hints, builder_start.recovery_hints, app_builder_lane.recovery_hints, and app_builder_summary.recovery_hints preserve placeholder, focused Panel/SQL, and redacted diagnostic recovery without deep context.',
					'policy'=>'Use recovery_hints for ordinary app recovery; do not open MCP/release validation, maintainer proof, or benchmark evidence unless the task escalates.',
				],
				'app_contract_summary_contract'=>[
					'compact_lane'=>'builder_response.app_contract_summary, builder_start.app_contract_summary, app_builder_lane.app_contract_summary, and app_builder_summary.app_contract_summary preserve app-owned ownership, tenant/workspace, lifecycle, audit, relationship, and decision prompt hints without deep context.',
					'policy'=>'Use app_contract_summary for ordinary app-owned policy decisions; do not open enterprise audit unless an explicit escalation decision requires it.',
				],
				'relationship_adapter_handoff_contract'=>[
					'first_read'=>'builder_response.relationship_adapter_handoff is present on direct app-builder plans and build-shaped start packs when relationships are inferred or supplied.',
					'compact_lane'=>'builder_response.relationship_adapter_handoff, builder_start.relationship_adapter_handoff, app_builder_lane.relationship_adapter_handoff, and app_builder_summary.relationship_adapter_handoff preserve concrete app-owned adapter stems, panel_field_source values, repository_touchpoints, verification_focus items, and write touchpoints without deep context.',
					'policy'=>'Use relationship_adapter_handoff.adapters for Panel relationship fields, filters, relation UI, lookup labels, empty states, permission checks, and tenant/workspace constraints before opening governance context.',
				],
				'extension_boundary_summary_contract'=>[
					'compact_lane'=>'builder_response.extension_boundary_summary, builder_start.extension_boundary_summary, app_builder_lane.extension_boundary_summary, and app_builder_summary.extension_boundary_summary preserve placement_decision and app_owned_extension_targets without deep context.',
					'placement_checklist'=>'builder_response.extension_boundary_summary.app_owned_placement_checklist maps ordinary behavior to application_code, configuration, dialbacks_callbacks, plugins, or application_adapter before runtime-internal changes.',
					'policy'=>'Use app_owned_extension_targets and app_owned_placement_checklist to choose concrete app-owned config, dialback/callback, plugin, local MCP metadata, or application adapter locations before escalating to Dataphyre runtime internals.',
				],
				'write_handoff_contract'=>[
					'first_read'=>'builder_response.write_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.write_handoff and builder_response.write_handoff preserve readiness status, first write batch, skeleton rerun hint, and after-write verification reminder; compact agent briefs use the direct app-builder fast lane and expose pointers through builder_first_read and context_links rather than inlining write-handoff, detail-page maps, payload-budget, or escalation-policy detail.',
					'apply_bridge'=>'builder_response.write_handoff.apply_audit_handoff points to dataphyre_apply_audit_plan after write_readiness.status=ready_for_app_owned_writes; compact briefs point agents to the full app-builder plan for the bridge.',
					'policy'=>'Copy write_handoff across compact resume handoffs, including relationship_adapter_handoff, implementation_recipe, data_model_handoff, data_integrity_summary, lifecycle_policy_summary, lifecycle_state_handoff, audit_retention_summary, audit_retention_handoff, access_control_summary, access_control_handoff, operational_reliability_summary, operational_reliability_handoff, support_observability_summary, support_observability_handoff, change_management_summary, change_management_handoff, integration_boundary_summary, integration_boundary_handoff, tenant_identity_handoff, business_policy_summary, process_policy_summary, domain_workflow_handoff, reporting_analytics_summary, reporting_analytics_handoff, notification_communication_summary, and notification_communication_handoff, without opening governance, maintainer release proof, aggregate MCP validation, or Dataphyre hot-path benchmark evidence.',
				],
				'data_model_handoff_contract'=>[
					'first_read'=>'builder_response.data_model_handoff is present on direct app-builder plans and build-shaped start packs when data-model artifacts are planned.',
					'compact_lane'=>'app_builder_lane.data_model_handoff and builder_view.data_model_handoff preserve app-owned TableSchema/repository/record artifact paths, casts, relationships, and schema_field_metadata without skeleton bodies; compact agent briefs use the direct app-builder fast lane and expose summaries plus context_links instead of inlining data-model handoff or detail-pagination metadata.',
					'policy'=>'Use data_model_handoff for app-owned TableSchema, repository, and record adaptation; open full skeleton bodies only when ready to write app-owned code.',
				],
				'implementation_matrix_contract'=>[
					'first_read'=>'builder_response.implementation_matrix maps app-owned obligations, tenant_identity_handoff, and active corporate-control summaries to source summaries, skeleton groups, paths, and focused verification tools.',
					'policy'=>'Use implementation_matrix as the compact implementation checklist for ordinary app writes; it reorganizes existing field metadata, relationship, integrity, app contract, tenant/actor/entitlement enforcement, sensitive-data, and corporate-control obligations without requiring governance, release validation, or Dataphyre hot-path benchmark evidence.',
				],
				'implementation_recipe_contract'=>[
					'first_read'=>'builder_response.implementation_recipe maps app-owned skeleton paths to edit_tasks, obligation_ids, relationship adapter touchpoints, focused verification tools, and failure branches without inlining skeleton bodies.',
					'policy'=>'Use implementation_recipe.items as the immediate file edit queue for ordinary app work; open full code_skeletons only when ready to copy and adapt app-owned skeleton bodies.',
				],
				'local_convention_probe_contract'=>[
					'first_read'=>'builder_response.local_convention_probe maps planned skeleton kinds to local inspect_globs, convention signals, capture_fields, apply_to targets, and the builder fields those signals feed; builder_response.first_read.next_action.write_start_packet.first_probe mirrors one bounded actionable probe with capture_fields and apply_to for first-page app writes.',
					'policy'=>'Use local_convention_probe.items to capture observed_patterns and style_decisions_to_apply before app-owned writes so generated skeletons adapt to the consuming application style without opening governance or release validation.',
				],
				'verification_recovery_plan_contract'=>[
					'first_read'=>'builder_response.verification_recovery_plan maps focused verification tools to copy-safe failure evidence, copy_safe_failure_handoff, likely app-owned fix scope, safe next reads, and diagnostic_summary.copy_safe_evidence; failure_branch pointers use "branches where tool=<tool>" because branches is a numeric list.',
					'policy'=>'Use verification_recovery_plan after focused app-check failures; keep ordinary app recovery in app-owned files/config/tests and exclude governance, release validation, raw logs, and Dataphyre hot-path benchmark evidence.',
				],
				'verification_execution_plan_contract'=>[
					'first_read'=>'builder_response.verification_execution_plan maps focused verification tools to ordered tool calls, concrete arguments, related recipe paths, and failure branches.',
					'policy'=>'Use verification_execution_plan.items after app-owned writes and write_readiness blockers are resolved; exclude dataphyre_mcp_verify_all, Dataphyre project-wide release validation, release validation, and Dataphyre hot-path benchmarks from ordinary app verification.',
				],
				'verification_handoff_contract'=>[
					'first_read'=>'builder_response.verification_handoff maps ordinary app completion evidence to copy-safe fields, post_write_handoff_template, and focused_completion_packet.',
					'policy'=>'Fill focused_completion_packet after focused app checks with changed app-owned files, check item pass/fail summaries, acceptance review, redacted diagnostic evidence only when a focused check failed, remaining app follow-ups, and not_release_proof=true; do not include raw logs, release proof, or Dataphyre hot-path benchmark evidence.',
				],
				'acceptance_review_plan_contract'=>[
					'first_read'=>'builder_response.acceptance_review_plan maps acceptance criteria and implementation obligation ids to implementation_recipe, verification_execution_plan, verification_handoff, post_write_handoff_template, app path, schema/field, relationship, corporate-control, and extension-boundary evidence sources.',
					'policy'=>'Use acceptance_review_plan.items, obligation_review_items, and post_write_handoff_template after app-owned writes and focused verification; it is a compact ordinary app done-review checklist, not MCP release validation or a maintainer benchmark gate.',
				],
				'field_metadata_contract'=>[
					'first_read'=>'builder_response.schema, builder_response.data_model_handoff, builder_plan.schema, and builder_plan.data_model[].schema_field_metadata preserve bounded field options, casts, required flags, relationship hints, and typed default metadata when supplied.',
					'accepted_metadata'=>['required', 'options', 'choices', 'enum', 'default', 'default_value', 'json', 'jsonb', 'foreign_key_target', 'references', 'relation', 'not_foreign_key', 'foreign_key=false', 'unique', 'unique_with', 'unique_scope', 'phrase-style required/nullable/enum/default/foreign-key hints'],
					'policy'=>'Use these hints for app-owned schema, validation, Panel controls, relationship adapters, filters, integrity metadata, and focused tests; do not edit Dataphyre internals for one app-specific field option set.',
				],
				'data_integrity_summary_contract'=>[
					'first_read'=>'builder_response.data_integrity_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.data_integrity_summary and app_builder_summary.data_integrity_summary preserve app-owned index, uniqueness, required-field, foreign-key, scope, and business-identifier hints without deep context.',
					'policy'=>'Use data_integrity_summary for app-owned schema/migration and repository-check decisions; do not execute migrations or require MCP/release validation for ordinary app work.',
				],
				'lifecycle_policy_summary_contract'=>[
					'first_read'=>'builder_response.lifecycle_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.lifecycle_policy_summary and app_builder_summary.lifecycle_policy_summary preserve status/stage/decision/priority defaults, terminal options, transition action hints, and default-filter checks without deep context.',
					'policy'=>'Use lifecycle_policy_summary for app-owned lifecycle defaults, transitions, Panel filters/actions, and focused negative checks; do not add Dataphyre runtime workflow machinery for ordinary app status fields.',
				],
				'audit_retention_summary_contract'=>[
					'first_read'=>'builder_response.audit_retention_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.audit_retention_summary and app_builder_summary.audit_retention_summary preserve actor, approval, effective-date, retention, legal-hold, export, residency, and classification controls without deep context.',
					'policy'=>'Use audit_retention_summary for app-owned corporate-record policy and focused checks; ordinary record fields do not require enterprise audit unless the task escalates.',
				],
				'access_control_summary_contract'=>[
					'first_read'=>'builder_response.access_control_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.access_control_summary and app_builder_summary.access_control_summary preserve tenant/workspace scope, ownership, actor, role/permission, visibility/classification, and relationship exposure controls without deep context.',
					'policy'=>'Use access_control_summary for app-owned repository scopes, Panel/API visibility, relationship lookup permissions, and focused allow/deny checks; ordinary access fields do not require enterprise audit unless the task escalates.',
				],
				'operational_reliability_summary_contract'=>[
					'first_read'=>'builder_response.operational_reliability_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.operational_reliability_summary and app_builder_summary.operational_reliability_summary preserve idempotency, request-hash, retry/delivery, import/export, provider-reference, queue/job, webhook, and external side-effect controls without deep context.',
					'policy'=>'Use operational_reliability_summary for app-owned idempotency, retry, outbox/job, import/export, webhook, and reconciliation behavior with focused replay/failure checks; ordinary reliability fields do not require enterprise audit unless the task escalates.',
				],
				'support_observability_summary_contract'=>[
					'first_read'=>'builder_response.support_observability_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.support_observability_summary and app_builder_summary.support_observability_summary preserve support ticket, incident, severity/SLA, health, diagnostic evidence, alert, and copy-safe handoff controls without deep context.',
					'policy'=>'Use support_observability_summary for app-owned support triage, incident state, health status, alert acknowledgement, SLA escalation, and copy-safe diagnostic evidence with focused support/redaction checks; ordinary support fields do not require enterprise audit unless the task escalates.',
				],
				'change_management_summary_contract'=>[
					'first_read'=>'builder_response.change_management_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.change_management_summary and app_builder_summary.change_management_summary preserve feature flag, rollout wave, migration/backfill, rollback, versioning, compatibility, and change-approval controls without deep context.',
					'policy'=>'Use change_management_summary for app-owned rollout, migration/backfill, rollback evidence, version compatibility, and feature-flag behavior with focused rollout/recovery checks; ordinary change fields do not require package release validation or enterprise audit unless the task escalates.',
				],
				'integration_boundary_summary_contract'=>[
					'first_read'=>'builder_response.integration_boundary_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.integration_boundary_summary and app_builder_summary.integration_boundary_summary preserve external-id, provider, webhook, sync, idempotency, retry/dead-letter, credential-reference, and reconciliation controls without deep context.',
					'policy'=>'Use integration_boundary_summary for app-owned external provider adapters, webhook ingestion, sync resume state, idempotent side effects, retry/dead-letter handling, credential references, and reconciliation with focused duplicate/replay/recovery checks; ordinary integration fields do not require package release validation or enterprise audit unless the task escalates.',
				],
				'tenant_identity_handoff_contract'=>[
					'first_read'=>'builder_response.tenant_identity_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.tenant_identity_handoff and app_builder_summary.tenant_identity_handoff preserve tenant scope, actor identity, permission, plan, entitlement, quota, enforcement order, fixture-case links, and negative checks without deep context.',
					'policy'=>'Use tenant_identity_handoff and builder_response.verification_fixture_handoff.tenant_identity_cases for concrete app-owned SaaS boundary implementation and focused tests; ordinary tenant/identity fields do not require Dataphyre runtime tenant/identity engine edits, release validation, enterprise audit, or hot-path benchmark evidence unless the task escalates.',
				],
				'business_policy_summary_contract'=>[
					'first_read'=>'builder_response.business_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.business_policy_summary and app_builder_summary.business_policy_summary preserve entitlement, quota, eligibility, approval/delegation, exception/waiver, contract-term, and commercial-rule controls without deep context.',
					'policy'=>'Use business_policy_summary for app-owned entitlements, quotas, eligibility rules, approval/delegation, policy exceptions, waivers, contract terms, and commercial rules with focused allow/deny and override checks; ordinary business-policy fields do not require package release validation or enterprise audit unless the task escalates.',
				],
				'process_policy_summary_contract'=>[
					'first_read'=>'builder_response.process_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.process_policy_summary and app_builder_summary.process_policy_summary preserve assignment, queue, handoff, SLA/deadline, escalation, dependency, and completion-evidence controls without deep context.',
					'policy'=>'Use process_policy_summary for app-owned assignments, queues, handoffs, SLA/deadline clocks, escalations, dependencies, and completion evidence with focused progression and negative-state checks; ordinary process fields do not require package release validation, global queue/scheduler changes, or enterprise audit unless the task escalates.',
				],
				'reporting_analytics_summary_contract'=>[
					'first_read'=>'builder_response.reporting_analytics_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.reporting_analytics_summary and app_builder_summary.reporting_analytics_summary preserve metric, dimension, snapshot, freshness, drilldown, dashboard-visibility, and report-export controls without deep context.',
					'policy'=>'Use reporting_analytics_summary for app-owned KPIs, dimensions, snapshots, freshness, drilldowns, dashboard visibility, and report exports with focused calculation, scope, and export checks; ordinary reporting fields do not require package release validation, data warehouse setup, BI platform setup, or enterprise audit unless the task escalates.',
				],
				'reporting_analytics_handoff_contract'=>[
					'first_read'=>'builder_response.reporting_analytics_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.reporting_analytics_handoff and app_builder_summary.reporting_analytics_handoff preserve reporting write order, negative checks, calculation contracts, and verification focus without deep context.',
					'policy'=>'Use reporting_analytics_handoff for concrete app-owned dashboard/reporting implementation; ordinary reporting fields do not require Dataphyre analytics engine edits, BI/data warehouse setup, release validation, enterprise audit, or hot-path benchmark evidence unless the task escalates.',
				],
				'notification_communication_summary_contract'=>[
					'first_read'=>'builder_response.notification_communication_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.notification_communication_summary and app_builder_summary.notification_communication_summary preserve template, channel, recipient, preference, suppression/quiet-hour, delivery-receipt, and escalation-communication controls without deep context.',
					'policy'=>'Use notification_communication_summary for app-owned notification templates, channel selection, recipient resolution, preferences, suppression windows, delivery receipts, and escalation communications with focused delivery and suppression checks; ordinary communication fields do not require package release validation, external provider setup, or enterprise audit unless the task escalates.',
				],
				'notification_communication_handoff_contract'=>[
					'first_read'=>'builder_response.notification_communication_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.notification_communication_handoff and app_builder_summary.notification_communication_handoff preserve notification write order, negative checks, delivery contracts, and verification focus without deep context.',
					'policy'=>'Use notification_communication_handoff for concrete app-owned notification implementation; ordinary communication fields do not require Dataphyre notification engine edits, external provider setup, release validation, enterprise audit, or hot-path benchmark evidence unless the task escalates.',
				],
				'agent_workload_contract'=>[
					'first_read'=>'builder_response.first_read is the default app-builder surface; builder_response.first_read.next_detail_page names the one detail page to open next; builder_response.agent_workload remains available on direct app-builder plans and build-shaped start packs for overhead-budget details.',
					'phase_plan'=>'builder_response.agent_workload.phase_read_plan is the default app-agent sequence: first_pass, resolve_blockers, prepare_writes, focused_verification, done_review, then escalation only when explicitly triggered.',
					'compact_lane'=>'app_builder_lane.agent_workload and app_builder_summary.agent_workload repeat the overhead budget for broader start/task-pack contexts; compact agent briefs use builder_first_read and top-level refs only.',
					'policy'=>'Use agent_workload as the ordinary app overhead budget; follow phase_read_plan before scanning broad compatibility fields and keep status/safety/enterprise/publication validation collapsed until explicitly requested for an escalation decision.',
				],
				'optional_summary_compaction_contract'=>[
					'first_read'=>'Inactive optional enterprise summaries in builder_response use compact=true, status=not_triggered, and omit fields_by_category.',
					'active_summary_policy'=>'When a summary has task or field signals, keep controls, fields_by_category, policy, and verification_focus inline so implementation obligations stay actionable.',
					'policy'=>'Use compact inactive summaries to keep ordinary CRUD app agents lightweight; rerun with payload_profile=full only when a concern becomes relevant or code skeleton bodies are needed.',
				],
			],
			'apply_readiness'=>[
				'default_surface'=>'dataphyre_apply_audit_plan',
				'readiness_surface'=>'dataphyre_apply_runtime_readiness_plan',
				'future_runner_status'=>'not_exposed',
				'next_action_contract'=>'apply_next_action distinguishes use_app_owned_extension_point from escalate_framework_change before any future write-capable runner.',
				'ordinary_app_policy'=>'Use app-owned files and extension points before Dataphyre runtime internals; dataphyre_mcp_verify_all and hot-path benchmark proof are not ordinary app ceremony.',
			],
			'tools'=>$tools,
			'prompts'=>$prompts,
			'resources'=>[
				'dataphyre://module-index',
				'dataphyre://runtime-readme',
				'dataphyre://mcp-plan',
				'dataphyre://ai-guidelines',
				'dataphyre://agentic-enterprise',
				'dataphyre://mcp-capabilities',
			],
			'intentionally_not_exposed'=>[
				'SQL query execution',
				'route dispatch',
				'schema hydration',
				'config secret values',
				'app-specific local server scripts',
			],
		];
	}


}
