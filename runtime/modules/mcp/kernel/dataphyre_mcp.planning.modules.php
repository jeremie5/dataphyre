<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Module inventory and dependency-map planning surfaces for Dataphyre MCP.
 */
trait dataphyre_mcp_planning_module_surfaces {

	/**
	 * Loads MCP module declarations from plugin manifest files.
	 *
	 * Declarations are cached for the process after the first scan. Invalid JSON,
	 * non-array declaration entries, blank names, and malformed method lists are
	 * ignored so one bad plugin manifest cannot break planning surfaces for the
	 * rest of the runtime.
	 *
	 * @return array<string, array{name: string, module: string, methods: array<int, string>}> Declarations keyed by module name.
	 */
	private function mcp_module_declarations(): array {
		static $declarations=null;
		if(is_array($declarations)){
			return $declarations;
		}
		$declarations=[];
		$plugin_root=$this->common_root.'/dataphyre/plugins/mcp';
		foreach(glob($plugin_root.'/*.json') ?: [] as $path){
			$text=(string)file_get_contents($path);
			$data=json_decode($text, true);
			if(!is_array($data)){
				continue;
			}
			$entries=is_array($data['declarations'] ?? null) ? $data['declarations'] : [];
			foreach($entries as $entry){
				if(!is_array($entry)){
					continue;
				}
				$name=trim((string)($entry['name'] ?? ''));
				if($name==='' || preg_match('/^[A-Za-z0-9_\\-]+$/', $name)!==1){
					continue;
				}
				$entry['name']=$name;
				$entry['declared_by']=$this->relative_path($path);
				$entry['visibility']=(string)($entry['visibility'] ?? 'internal');
				$entry['release']=(string)($entry['release'] ?? 'redacted');
				$entry['purpose']=(string)($entry['purpose'] ?? '');
				$entry['notes']=is_array($entry['notes'] ?? null) ? array_values(array_map('strval', $entry['notes'])) : [];
				$declarations[$name]=$entry;
			}
		}
		ksort($declarations);
		return $declarations;
	}

	/**
	 * Describes safe module inventory payloads for application agents.
	 *
	 * @param string $surface MCP module inventory surface.
	 * @return array<string,mixed> Module inventory safety contract.
	 */
	private function module_inventory_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'application_default'=>'safe_for_module_selection_and_app_planning_without_runtime_execution',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('module_inventory_'.$surface),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('module_inventory_'.$surface),
			'not_performed'=>[
				'application bootstrap',
				'module bootstrap',
				'route dispatch',
				'controller invocation',
				'SQL query execution',
				'config secret resolution',
				'file writes',
			],
			'agent_guidance'=>'Use module inventory to choose relevant docs, contracts, and app-owned extension points before editing; do not treat module metadata as permission to patch Dataphyre internals for one application.',
			'escalate_only_for'=>'Use maintainer review only for escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
		];
	}

	/**
	 * Lists runtime modules and MCP plugin declarations.
	 *
	 * this read-only planning surface merges filesystem module discovery
	 * with plugin-declared visibility metadata so local agents can distinguish
	 * public runtime modules from internal MCP-only modules without executing code.
	 */
	private function module_list(): array {
		$modules_root=$this->common_root.'/dataphyre/runtime/modules';
		$declarations=$this->mcp_module_declarations();
		$modules=[];
		foreach(glob($modules_root.'/*', GLOB_ONLYDIR) ?: [] as $dir){
			$name=basename($dir);
			$docs=glob($dir.'/documentation/*.md') ?: [];
			$declaration=$declarations[$name] ?? [];
			$modules[]=[
				'name'=>$name,
				'visibility'=>(string)($declaration['visibility'] ?? 'public_release'),
				'release'=>(string)($declaration['release'] ?? 'included'),
				'declared_by'=>(string)($declaration['declared_by'] ?? 'runtime_modules_directory'),
				'declared_purpose'=>(string)($declaration['purpose'] ?? ''),
				'has_framework'=>is_dir($dir.'/Framework'),
				'has_kernel'=>is_dir($dir.'/kernel'),
				'has_unit_tests'=>is_dir($dir.'/unit_tests'),
				'docs'=>array_map(fn($path)=>$this->relative_path($path), $docs),
			];
		}
		usort($modules, fn($a, $b)=>strcmp($a['name'], $b['name']));
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module_inventory_safety'=>$this->module_inventory_safety_contract('module_list'),
			'modules'=>$modules,
			'plugin_declarations'=>array_values($declarations),
			'declaration_policy'=>'plugins/mcp/*.json can declare internal-only modules for local MCP tooling; package module metadata is built from framework-owned modules and app-owned declarations separately.',
		];
	}

	/**
	 * Summarizes runtime, module, and package version metadata.
	 *
	 * the summary reads bootstrap constants, module version files, and
	 * package manifests as static text/JSON only. It never bootstraps Dataphyre,
	 * runs Composer scripts, or inspects application-local modules.
	 */
	private function runtime_version_summary(array $args): array {
		$include_modules=($args['include_modules'] ?? true)!==false;
		$include_packages=($args['include_packages'] ?? true)!==false;
		$bootstrap_path=$this->common_root.'/dataphyre/runtime/bootstrap.php';
		$bootstrap_text=is_file($bootstrap_path) ? (string)file_get_contents($bootstrap_path) : '';
		$bootstrap_version=null;
		if(preg_match("/define\\(\\s*['\"]BS_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $bootstrap_text, $match)===1){
			$bootstrap_version=$match[1];
		}
		$modules=[];
		if($include_modules){
			$modules_root=$this->common_root.'/dataphyre/runtime/modules';
			$declarations=$this->mcp_module_declarations();
			foreach(glob($modules_root.'/*', GLOB_ONLYDIR) ?: [] as $dir){
				$name=basename($dir);
				$declaration=$declarations[$name] ?? [];
				$version_file=$dir.'/version';
				$modules[]=[
					'name'=>$name,
					'version'=>is_file($version_file) ? trim((string)file_get_contents($version_file)) : null,
					'version_file'=>is_file($version_file) ? $this->relative_path($version_file) : null,
					'visibility'=>(string)($declaration['visibility'] ?? 'public_release'),
					'release'=>(string)($declaration['release'] ?? 'included'),
					'declared_by'=>(string)($declaration['declared_by'] ?? 'runtime_modules_directory'),
					'has_docs'=>is_dir($dir.'/documentation'),
					'has_kernel'=>is_dir($dir.'/kernel'),
					'has_framework'=>is_dir($dir.'/Framework'),
				];
			}
			usort($modules, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
		}
		$packages=[];
		if($include_packages){
			foreach($this->all_files($this->common_root.'/dataphyre/runtime/modules', 20000) as $path){
				$relative=$this->relative_path($path);
				$basename=basename($path);
				if($basename==='VERSION' || $basename==='OPENAPI_VERSION'){
					$packages[]=[
						'type'=>$basename,
						'path'=>$relative,
						'value'=>trim($this->read_repo_text($relative, 2000)),
					];
					continue;
				}
				if($basename==='composer.json'){
					$data=json_decode($this->read_repo_text($relative, 50000), true);
					if(is_array($data)){
						$packages[]=[
							'type'=>'composer',
							'path'=>$relative,
							'name'=>is_string($data['name'] ?? null) ? $data['name'] : null,
							'version'=>is_string($data['version'] ?? null) ? $data['version'] : null,
							'php_requirement'=>is_string($data['require']['php'] ?? null) ? $data['require']['php'] : null,
						];
					}
				}
			}
			usort($packages, static fn(array $a, array $b): int => strcmp((string)$a['path'], (string)$b['path']));
		}
		return [
			'summary_type'=>'runtime_version_summary',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module_inventory_safety'=>$this->module_inventory_safety_contract('runtime_version_summary'),
			'bootstrap'=>[
				'path'=>$this->relative_path($bootstrap_path),
				'bs_version'=>$bootstrap_version,
				'detected'=>is_string($bootstrap_version),
			],
			'module_count'=>count($modules),
			'modules'=>$modules,
			'package_metadata_count'=>count($packages),
			'package_metadata'=>$packages,
			'version_policy'=>[
				'module_version_file'=>'runtime/modules/<module>/version',
				'default_when_missing'=>'Flightdeck and Dpanel fall back to 1.0 when a module version file is missing.',
				'bootstrap_constant'=>'BS_VERSION in common/dataphyre/runtime/bootstrap.php',
			],
			'guardrails'=>[
				'This tool reads version metadata files only and does not bootstrap Dataphyre.',
				'Composer/package files are parsed as JSON and package scripts are not executed.',
				'Application-local modules are not inspected by this shared read-only summary.',
			],
		];
	}

	/**
	 * Describes one runtime module's structure and declared metadata.
	 *
	 * module names are constrained to directory-safe tokens, plugin
	 * declarations are overlaid when present, and returned file groups are bounded
	 * so MCP clients can plan work without broad filesystem reads.
	 */
	private function describe_module(string $module, int $limit): array {
		$module=trim($module);
		if($module==='' || preg_match('/^[A-Za-z0-9_\\-]+$/', $module)!==1){
			throw new InvalidArgumentException('module must be a runtime module directory name.');
		}
		$limit=max(1, min($limit ?: 80, 250));
		$module_root=$this->common_root.'/dataphyre/runtime/modules/'.$module;
		if(!is_dir($module_root)){
			throw new InvalidArgumentException('Unknown Dataphyre module: '.$module);
		}
		$declaration=$this->mcp_module_declarations()[$module] ?? [];
		$groups=[
			'documentation'=>$this->files_under($module_root.'/documentation', ['md'], $limit),
			'framework'=>$this->files_under($module_root.'/Framework', ['php'], $limit),
			'kernel'=>$this->files_under($module_root.'/kernel', ['php'], $limit),
			'unit_tests'=>$this->files_under($module_root.'/unit_tests', ['php', 'json'], $limit),
		];
		$version_file=$module_root.'/version';
		$maxint_file=$module_root.'/maxint';
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module_inventory_safety'=>$this->module_inventory_safety_contract('module_describe'),
			'module'=>$module,
			'path'=>$this->relative_path($module_root),
			'visibility'=>(string)($declaration['visibility'] ?? 'public_release'),
			'release'=>(string)($declaration['release'] ?? 'included'),
			'declared_by'=>(string)($declaration['declared_by'] ?? 'runtime_modules_directory'),
			'declared_purpose'=>(string)($declaration['purpose'] ?? ''),
			'declaration_notes'=>is_array($declaration['notes'] ?? null) ? array_values($declaration['notes']) : [],
			'version'=>is_file($version_file) ? trim((string)file_get_contents($version_file)) : null,
			'maxint'=>is_file($maxint_file) ? trim((string)file_get_contents($maxint_file)) : null,
			'has_framework'=>is_dir($module_root.'/Framework'),
			'has_kernel'=>is_dir($module_root.'/kernel'),
			'has_unit_tests'=>is_dir($module_root.'/unit_tests'),
			'files'=>$groups,
		];
	}

	/**
	 * Builds a bounded documentation pack for one module.
	 *
	 * the pack combines baseline Dataphyre guidance with module markdown,
	 * plus framework/kernel/test file lists. Document bodies are read through the
	 * repository reader with byte limits to keep MCP responses predictable.
	 */
	private function module_docs_pack(string $module, int $max_bytes_per_doc): array {
		$description=$this->describe_module($module, 80);
		$max_bytes_per_doc=max(1000, min($max_bytes_per_doc ?: 40000, 120000));
		$documents=[];
		$baseline=[
			'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			'common/dataphyre/docs/MODULES.md',
			'common/dataphyre/runtime/README.md',
		];
		foreach(array_merge($baseline, $description['files']['documentation'] ?? []) as $path){
			$documents[]=[
				'path'=>$path,
				'text'=>$this->read_repo_text($path, $max_bytes_per_doc),
			];
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('module_docs_pack'),
			'module'=>$description['module'],
			'path'=>$description['path'],
			'documents'=>$documents,
			'framework_files'=>$description['files']['framework'] ?? [],
			'kernel_files'=>$description['files']['kernel'] ?? [],
			'unit_test_files'=>$description['files']['unit_tests'] ?? [],
		];
	}

	/**
	 * Produces a static dependency map for a module.
	 *
	 * this scan extracts required modules, framework loads, SQL table
	 * declarations, include expressions, classes, and functions from bounded
	 * framework/kernel files without including or executing module code.
	 */
	private function module_dependency_map(string $module, int $limit): array {
		$description=$this->describe_module($module, $limit ?: 120);
		$limit=max(1, min($limit ?: 120, 300));
		$files=array_slice(array_merge($description['files']['framework'] ?? [], $description['files']['kernel'] ?? []), 0, $limit);
		$required_modules=[];
		$framework_loads=[];
		$sql_tables=[];
		$includes=[];
		$classes=[];
		$functions=[];
		foreach($files as $relative){
			$path=$this->safe_repo_path($relative);
			if(!is_file($path)){
				continue;
			}
			$text=(string)file_get_contents($path);
			$required_modules=array_merge($required_modules, $this->extract_module_names_from_calls($text, 'dp_module_required'));
			$framework_loads=array_merge($framework_loads, $this->extract_module_names_from_calls($text, 'load_framework_module'));
			$framework_loads=array_merge($framework_loads, $this->extract_module_names_from_calls($text, 'load_framework_modules'));
			$sql_tables=array_merge($sql_tables, $this->extract_string_arguments($text, 'sql_define_table'));
			$includes=array_merge($includes, $this->extract_include_expressions($text));
			$summary=$this->php_source_api_file_summary($path);
			foreach($summary['classes'] ?? [] as $class){
				$classes[]=[
					'file'=>$relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'method_count'=>count($class['methods'] ?? []),
				];
			}
			foreach($summary['functions'] ?? [] as $function){
				$functions[]=[
					'file'=>$relative,
					'name'=>$function['fqfn'] ?? $function['name'] ?? '',
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'module_inventory_safety'=>$this->module_inventory_safety_contract('module_dependency_map'),
			'module'=>$description['module'],
			'path'=>$description['path'],
			'scanned_files'=>count($files),
			'required_modules'=>array_values(array_unique($required_modules)),
			'framework_loads'=>array_values(array_unique($framework_loads)),
			'sql_tables'=>array_values(array_unique($sql_tables)),
			'includes'=>array_values(array_unique(array_slice($includes, 0, 120))),
			'classes'=>array_slice($classes, 0, 120),
			'functions'=>array_slice($functions, 0, 120),
			'execution'=>'not_executed',
		];
	}

}
