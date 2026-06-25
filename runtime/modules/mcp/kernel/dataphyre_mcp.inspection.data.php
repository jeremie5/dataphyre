<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP config, storage, and SQL inspection surfaces.
 */
trait dataphyre_mcp_inspection_data_surfaces {

	/**
	 * Lists likely config files and their key shapes under a repo-local scope.
	 *
	 * file discovery is bounded and restricted to PHP/JSON paths that look like configuration
	 * artifacts. The result exposes key paths only, not config values.
	 *
	 * @param string $scope Optional repo-relative file or directory scope.
	 * @return array{configs: array<int, array{path: string, keys: array}>} Config key inventory.
	 */
	private function list_config_keys(string $scope): array {
		$base=$scope!=='' ? $this->safe_repo_path($scope) : $this->root;
		$configs=[];
		foreach($this->all_files($base, 3000) as $path){
			$relative=$this->relative_path($path);
			if(!preg_match('/config.*\.(php|json)$/i', $relative) && !str_contains(strtolower($relative), '/config/')){
				continue;
			}
			if(!preg_match('/\.(php|json)$/i', $relative)){
				continue;
			}
			$configs[]=['path'=>$relative, 'keys'=>$this->extract_config_keys($path)];
			if(count($configs)>=80){
				break;
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('config_key_inventory'),
			'configs'=>$configs,
		];
	}

	/**
	 * Describes reusable config/storage/SQL metadata safety boundaries.
	 *
	 * @param string $surface Data surface label.
	 * @return array<string,mixed> Data safety metadata for app-agent consumption.
	 */
	private function data_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'classification'=>'configuration_schema_or_storage_sql_metadata',
			'application_default'=>'safe_for_application_planning_when_values_are_redacted',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('data_'.$surface),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('data_'.$surface),
			'allowed_for_app_agents'=>[
				'key names and nested paths',
				'value kinds and redaction flags',
				'driver names and public method contracts',
				'table names, schema columns, and cluster aliases',
				'bounded SQL read plans that do not execute',
			],
			'not_returned'=>[
				'passwords',
				'tokens',
				'secret values',
				'usernames',
				'resolved hosts or endpoints',
				'database names',
				'bucket names when sensitive-key policy marks them redacted',
				'tenant-identifying values',
			],
			'governance_trigger'=>'Run governance review only before corporate-ready, security-sensitive, tenant/privacy/compliance, access-policy, billing, or release-facing claims.',
		];
	}

	/**
	 * Reads the structural key shape of a repo-local PHP or JSON config file.
	 *
	 * validates that the target is a config-like file, then extracts path shapes with a caller-bounded
	 * maximum. Values are intentionally not returned, which keeps secrets and environment-specific configuration out of
	 * MCP responses.
	 *
	 * @param array{path?: string, max_paths?: int} $args Config shape request.
	 * @return array{path: string, type: string, paths: array, path_count: int, truncated: bool, values_returned: bool} Config structure summary.
	 *
	 * @throws InvalidArgumentException When the path is not a repo-local PHP or JSON config artifact.
	 */
	private function read_config_shape(array $args): array {
		$path=$this->safe_repo_path((string)($args['path'] ?? ''));
		$relative=$this->relative_path($path);
		$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if(!is_file($path) || !in_array($extension, ['php', 'json'], true)){
			throw new InvalidArgumentException('path must point to a repo-local PHP or JSON config file.');
		}
		if(!str_contains(strtolower($relative), 'config') && !str_ends_with(strtolower($relative), '.json')){
			throw new InvalidArgumentException('path must look like a config file.');
		}
		$max_paths=max(1, min((int)($args['max_paths'] ?? 120) ?: 120, 500));
		$paths=$extension==='json'
			? $this->json_config_shape($path, $max_paths)
			: $this->php_config_shape($path, $max_paths);
		return [
			'path'=>$relative,
			'type'=>$extension,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('config_shape'),
			'paths'=>$paths,
			'path_count'=>count($paths),
			'truncated'=>count($paths)>=$max_paths,
			'values_returned'=>false,
		];
	}

	/**
	 * Previews exact scalar config values under strict redaction and shape rules.
	 *
	 * callers must name exact key paths, sensitive key names are redacted before value inspection, and
	 * only scalar/null values or scalar/null lists are emitted. PHP configs are parsed as literal arrays rather than
	 * required, preventing executable config side effects.
	 *
	 * @param array{path?: string, keys?: array<int, string>, max_values?: int} $args Value preview request.
	 * @return array<string, mixed> Redacted value preview report.
	 *
	 * @throws InvalidArgumentException When the path or requested keys violate the preview contract.
	 */
	private function config_value_preview(array $args): array {
		$path=$this->safe_repo_path((string)($args['path'] ?? ''));
		$relative=$this->relative_path($path);
		$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if(!is_file($path) || !in_array($extension, ['php', 'json'], true)){
			throw new InvalidArgumentException('path must point to a repo-local PHP or JSON config file.');
		}
		if(!str_contains(strtolower($relative), 'config') && !str_ends_with(strtolower($relative), '.json')){
			throw new InvalidArgumentException('path must look like a config file.');
		}
		$requested=$args['keys'] ?? [];
		if(!is_array($requested) || $requested===[]){
			throw new InvalidArgumentException('keys must be a non-empty array of exact key paths.');
		}
		$max_values=max(1, min((int)($args['max_values'] ?? 20) ?: 20, 50));
		$source=$extension==='json'
			? json_decode((string)file_get_contents($path), true)
			: $this->php_config_literal_array((string)file_get_contents($path));
		if(!is_array($source)){
			return [
				'path'=>$relative,
				'write_policy'=>'read_only',
				'execution'=>'not_executed',
				'data_safety'=>$this->data_safety_contract('config_value_preview'),
				'values_returned'=>false,
				'error'=>'Unable to parse config as a literal array.',
				'values'=>[],
			];
		}
		$values=[];
		foreach(array_slice($requested, 0, $max_values) as $key_path){
			$key_path=trim((string)$key_path);
			if($key_path===''){
				continue;
			}
			$sensitive=false;
			foreach(explode('.', $key_path) as $part){
				if($this->is_sensitive_config_key($part)){
					$sensitive=true;
					break;
				}
			}
			if($sensitive){
				$values[]=[
					'path'=>$key_path,
					'returned'=>false,
					'redacted'=>true,
					'reason'=>'sensitive_key',
				];
				continue;
			}
			$found=$this->config_value_at_path($source, $key_path);
			if($found['found']!==true){
				$values[]=[
					'path'=>$key_path,
					'returned'=>false,
					'redacted'=>false,
					'reason'=>'not_found',
				];
				continue;
			}
			$value=$found['value'];
			if(!$this->is_previewable_config_value($value)){
				$values[]=[
					'path'=>$key_path,
					'returned'=>false,
					'redacted'=>false,
					'kind'=>$this->config_value_kind($value),
					'reason'=>'not_scalar_or_scalar_list',
				];
				continue;
			}
			$values[]=[
				'path'=>$key_path,
				'returned'=>true,
				'redacted'=>false,
				'kind'=>$this->config_value_kind($value),
				'value'=>$value,
			];
		}
		return [
			'path'=>$relative,
			'type'=>$extension,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('config_value_preview'),
			'values_returned'=>true,
			'requested_count'=>count($requested),
			'returned_count'=>count(array_filter($values, static fn(array $entry): bool => ($entry['returned'] ?? false)===true)),
			'values'=>$values,
			'guardrails'=>[
				'Values are returned only for exact requested key paths.',
				'Sensitive key names are redacted regardless of value shape.',
				'Only scalar values, null, and lists of scalar/null values are previewed.',
			],
		];
	}

	/**
	 * Summarizes storage disk configuration without exposing backend credentials or touching storage providers.
	 *
	 * parses the storage config source for disk names, drivers, option keys, value kinds, and redaction
	 * flags. It does not instantiate drivers, list files, create temporary URLs, or read object metadata.
	 *
	 * @param array{config_path?: string} $args Optional repo-relative storage config path.
	 * @return array{write_policy: string, execution: string, config_path: string, default_disk: mixed, disk_count: int, disks: array, available_driver_classes: array, safety_notes: array<int, string>} Storage config summary.
	 *
	 * @throws InvalidArgumentException When the config path is not a repo-local PHP file.
	 */
	private function storage_config_summary(array $args): array {
		$config_path=trim((string)($args['config_path'] ?? ''));
		if($config_path===''){
			$config_path='common/dataphyre/config/storage.example.php';
		}
		$path=$this->safe_repo_path($config_path);
		if(!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION))!=='php'){
			throw new InvalidArgumentException('config_path must point to a repo-local PHP storage config file.');
		}
		$text=(string)file_get_contents($path);
		$disks_block=$this->php_array_block_for_key($text, 'disks');
		$disks=[];
		if($disks_block!==null){
			foreach($this->top_level_php_array_entries($disks_block) as $name=>$expression){
				if(!str_starts_with(ltrim($expression), '[')){
					continue;
				}
				$options=$this->top_level_php_array_entries($expression);
				$option_keys=[];
				foreach($options as $key=>$value_expression){
					$option_keys[]=[
						'key'=>$key,
						'kind'=>$this->php_expression_kind($value_expression),
						'redacted'=>$this->is_sensitive_config_key($key),
					];
				}
				$disks[]=[
					'name'=>$name,
					'driver'=>$this->literal_string_from_expression($options['driver'] ?? '') ?? 'unknown',
					'option_keys'=>$option_keys,
				];
			}
		}
		$default_disk=null;
		if(preg_match('/[\'"]default_disk[\'"]\s*=>\s*([\'"][^\'"]+[\'"])/', $text, $match)===1){
			$default_disk=$this->literal_string_from_expression($match[1]);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('storage_config_summary'),
			'config_path'=>$this->relative_path($path),
			'default_disk'=>$default_disk,
			'disk_count'=>count($disks),
			'disks'=>$disks,
			'available_driver_classes'=>$this->storage_driver_classes(),
			'safety_notes'=>[
				'Config values are not returned; only key names, value kinds, and redaction flags are exposed.',
				'Storage operations, filesystem reads, object listing, temporary URL creation, and writes are intentionally not performed.',
			],
		];
	}

	/**
	 * Catalogs storage driver classes against the storage driver contract.
	 *
	 * tokenizes the contract and driver source files to compare public method coverage. Driver classes
	 * are not required, instantiated, or connected to filesystems or object stores.
	 *
	 * @return array{write_policy: string, execution: string, module: string, contract: array, driver_count: int, drivers: array, safety_notes: array<int, string>} Storage driver catalog.
	 */
	private function storage_driver_catalog(): array {
		$contract_path='common/dataphyre/runtime/modules/storage/Framework/Contracts/StorageDriver.php';
		$contract=$this->php_source_api_file_summary($this->safe_repo_path($contract_path));
		$contract_methods=[];
		foreach($contract['classes'][0]['methods'] ?? [] as $method){
			$contract_methods[]=(string)($method['name'] ?? '');
		}
		$drivers=[];
		foreach($this->storage_driver_classes() as $driver){
			$summary=$this->php_source_api_file_summary($this->safe_repo_path((string)$driver['file']));
			$class=$summary['classes'][0] ?? [];
			$methods=[];
			$method_names=[];
			foreach($class['methods'] ?? [] as $method){
				if(($method['visibility'] ?? 'public')!=='public'){
					continue;
				}
				$name=(string)($method['name'] ?? '');
				$method_names[]=$name;
				$methods[]=[
					'name'=>$name,
					'signature'=>$method['signature'] ?? '',
					'contract_method'=>in_array($name, $contract_methods, true),
				];
			}
			$drivers[]=[
				'name'=>$driver['name'],
				'file'=>$driver['file'],
				'driver_key'=>$this->storage_driver_key_from_class((string)$driver['name']),
				'public_methods'=>$methods,
				'contract_coverage'=>[
					'implemented'=>array_values(array_intersect($contract_methods, $method_names)),
					'missing'=>array_values(array_diff($contract_methods, $method_names)),
				],
			];
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('storage_driver_catalog'),
			'module'=>'storage',
			'contract'=>[
				'path'=>$contract_path,
				'methods'=>$contract_methods,
			],
			'driver_count'=>count($drivers),
			'drivers'=>$drivers,
			'safety_notes'=>[
				'Driver files are tokenized but not required or instantiated.',
				'No storage backend, filesystem path, bucket, temporary URL, or object metadata is touched.',
			],
		];
	}

	/**
	 * Lists SQL table definitions from runtime manifests and optional config metadata.
	 *
	 * combines in-process table definition metadata with parsed SQL config table entries, but does not
	 * connect to database clusters, hydrate remote schemas, or execute SQL.
	 *
	 * @param array{include_runtime_manifest?: bool, include_config_tables?: bool, config_path?: string} $args Table inventory options.
	 * @return array{tables: array<int, array>} Table inventory keyed into a stable list.
	 */
	private function list_sql_tables(array $args): array {
		$include_runtime=(bool)($args['include_runtime_manifest'] ?? true);
		$config_path=trim((string)($args['config_path'] ?? ''));
		$include_config=(bool)($args['include_config_tables'] ?? ($config_path!==''));
		$tables=[];
		if($include_runtime){
			foreach($this->sql_runtime_table_manifest() as $table=>$entry){
				$tables[$table]=[
					'table'=>$table,
					'source'=>'runtime_manifest',
					'definition_file'=>$entry['file'] ?? null,
					'definition_id'=>$entry['definition_id'] ?? null,
					'cluster'=>null,
				];
			}
		}
		if($include_config && $config_path!==''){
			$config=$this->read_sql_config($config_path);
			foreach(($config['tables'] ?? []) as $table=>$definition){
				$table=(string)$table;
				if($table===''){
					continue;
				}
				$tables[$table]=array_replace($tables[$table] ?? [
					'table'=>$table,
					'source'=>'config',
					'definition_file'=>null,
					'definition_id'=>null,
				], [
					'config_source'=>$this->relative_path($this->safe_repo_path($config_path)),
					'cluster'=>is_array($definition) ? ($definition['cluster'] ?? null) : null,
					'multipoint_writes'=>is_array($definition) ? (bool)($definition['multipoint_writes'] ?? false) : false,
					'has_caching_policy'=>is_array($definition) && isset($definition['caching']),
				]);
			}
		}
		ksort($tables, SORT_STRING);
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('sql_table_inventory'),
			'tables'=>array_values($tables),
		];
	}

	/**
	 * Reads Dataphyre SQL table schema metadata from registered or configured table definitions.
	 *
	 * the result reflects Dataphyre table definition objects and optional create-query text, not a live
	 * database introspection. Missing definitions are reported explicitly instead of attempting database fallback.
	 *
	 * @param array{table?: string, config_path?: string, include_create_sql?: bool} $args Schema read request.
	 * @return array<string, mixed> Registered schema summary or missing-table marker.
	 *
	 * @throws InvalidArgumentException When the table name is missing.
	 */
	private function read_sql_schema(array $args): array {
		$table=trim((string)($args['table'] ?? ''));
		if($table===''){
			throw new InvalidArgumentException('table is required.');
		}
		$definition=$this->load_runtime_table_definition($table);
		$config_path=trim((string)($args['config_path'] ?? ''));
		if($definition===null && $config_path!==''){
			$config=$this->read_sql_config($config_path);
			$table_config=$config['tables'][$table] ?? null;
			if(is_array($table_config) && isset($table_config['definition_file'])){
				$definition=$this->load_config_table_definition(
					(string)$table_config['definition_file'],
					$table,
					isset($table_config['definition_id']) ? (string)$table_config['definition_id'] : null
				);
			}
		}
		if($definition===null){
			return [
				'table'=>$table,
				'registered'=>false,
				'write_policy'=>'read_only',
				'execution'=>'not_executed',
				'data_safety'=>$this->data_safety_contract('sql_schema'),
			];
		}
		$schema=$definition->schema();
		$result=[
			'table'=>$table,
			'registered'=>true,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('sql_schema'),
			'schema'=>[
				'primary_key'=>$schema->primaryKey(),
				'columns'=>$schema->columnNames(),
				'projections'=>$schema->projections(),
				'casts'=>$schema->casts(),
			],
			'definition'=>[
				'primary_columns'=>$definition->primaryColumns(),
				'columns'=>$definition->columns(),
				'projections'=>$definition->projections(),
				'casts'=>$definition->castMap(),
			],
		];
		if((bool)($args['include_create_sql'] ?? false)){
			$result['definition']['create_queries']=$definition->createQueries();
		}
		return $result;
	}

	/**
	 * Lists SQL datacenters, cluster aliases, and table-to-cluster mappings from config.
	 *
	 * exposes aliases and DBMS labels only. Resolved endpoints, credentials, database names, and live
	 * connection state remain outside the MCP read-only response.
	 *
	 * @param string $config_path Repo-relative SQL config path.
	 * @return array{config_path: string, default_cluster: mixed, datacenters: array, tables: array} SQL cluster summary.
	 */
	private function list_sql_clusters(string $config_path): array {
		$config=$this->read_sql_config($config_path);
		$datacenters=[];
		foreach(($config['datacenters'] ?? []) as $datacenter=>$datacenter_config){
			$clusters=[];
			foreach(($datacenter_config['dbms_clusters'] ?? []) as $cluster=>$cluster_config){
				$clusters[]=[
					'name'=>(string)$cluster,
					'dbms'=>is_array($cluster_config) ? ($cluster_config['dbms'] ?? null) : null,
				];
			}
			$datacenters[]=[
				'name'=>(string)$datacenter,
				'clusters'=>$clusters,
			];
		}
		$tables=[];
		foreach(($config['tables'] ?? []) as $table=>$definition){
			$tables[]=[
				'table'=>(string)$table,
				'cluster'=>is_array($definition) ? ($definition['cluster'] ?? null) : null,
				'multipoint_writes'=>is_array($definition) ? (bool)($definition['multipoint_writes'] ?? false) : false,
				'has_caching_policy'=>is_array($definition) && isset($definition['caching']),
			];
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('sql_cluster_summary'),
			'config_path'=>$this->relative_path($this->safe_repo_path($config_path)),
			'default_cluster'=>$config['default_cluster'] ?? null,
			'datacenters'=>$datacenters,
			'tables'=>$tables,
		];
	}

	/**
	 * Produces a non-executing safety plan for a candidate SQL read query.
	 *
	 * analyzes one SQL string for statement kind, multi-statement risk, blocked verbs, referenced
	 * tables, allow-list violations, and bounded LIMIT behavior. It does not open a connection, prepare a statement,
	 * execute SQL, or hydrate schema state.
	 *
	 * @param array{sql?: string, max_rows?: int, allowed_tables?: array<int, string>} $args SQL planning request.
	 * @return array<string, mixed> Query eligibility and bounded preview plan.
	 *
	 * @throws InvalidArgumentException When sql is missing.
	 */
	private function sql_query_plan(array $args): array {
		$sql=trim((string)($args['sql'] ?? ''));
		if($sql===''){
			throw new InvalidArgumentException('sql is required.');
		}
		$max_rows=max(1, min((int)($args['max_rows'] ?? 50) ?: 50, 1000));
		$allowed_tables=[];
		if(is_array($args['allowed_tables'] ?? null)){
			foreach($args['allowed_tables'] as $table){
				$table=trim((string)$table);
				if($table!==''){
					$allowed_tables[]=$this->normalizeSqlIdentifier($table);
				}
			}
			$allowed_tables=array_values(array_unique($allowed_tables));
		}
		$analysis_text=$this->sql_without_quoted_strings($sql);
		$normalized=preg_replace('/\s+/', ' ', trim($analysis_text)) ?? trim($analysis_text);
		$statement_kind=$this->sql_statement_kind($normalized);
		$issues=[];
		if($this->sql_has_multiple_statements($analysis_text)){
			$issues[]='multiple_statements_not_allowed';
		}
		if(preg_match('/(?:--|#|\/\*)/', $analysis_text)===1){
			$issues[]='comments_require_manual_review';
		}
		if(!in_array($statement_kind, ['select', 'with'], true)){
			$issues[]='only_select_or_with_queries_are_eligible';
		}
		$blocked_verbs=$this->sql_blocked_verbs($analysis_text);
		foreach($blocked_verbs as $verb){
			$issues[]='blocked_sql_verb:'.$verb;
		}
		$tables=$this->sql_referenced_tables($analysis_text);
		$table_violations=[];
		if($allowed_tables!==[]){
			foreach($tables as $table){
				if(!in_array($this->normalizeSqlIdentifier($table), $allowed_tables, true)){
					$table_violations[]=$table;
				}
			}
			if($table_violations!==[]){
				$issues[]='referenced_table_not_allowed';
			}
		}
		$limit=$this->sql_limit_value($analysis_text);
		if($limit!==null && $limit>$max_rows){
			$issues[]='limit_exceeds_max_rows';
		}
		$eligible=$issues===[] && $tables!==[];
		if($tables===[]){
			$issues[]='no_referenced_tables_detected';
			$eligible=false;
		}
		return [
			'plan_type'=>'dataphyre_sql_query_plan',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('sql_query_plan'),
			'statement'=>[
				'kind'=>$statement_kind,
				'has_limit'=>$limit!==null,
				'limit'=>$limit,
				'effective_max_rows'=>$max_rows,
				'contains_comments'=>preg_match('/(?:--|#|\/\*)/', $analysis_text)===1,
				'multiple_statement_risk'=>$this->sql_has_multiple_statements($analysis_text),
			],
			'tables'=>[
				'referenced'=>$tables,
				'allowed'=>$allowed_tables,
				'violations'=>array_values(array_unique($table_violations)),
			],
			'eligibility'=>[
				'eligible_for_future_unsafe_read_runner'=>$eligible,
				'issues'=>array_values(array_unique($issues)),
			],
			'bounded_sql_preview'=>$eligible ? $this->sql_bounded_preview($sql, $limit, $max_rows) : null,
			'safety_notes'=>[
				'This tool does not connect to SQL, hydrate schemas, prepare statements, or execute queries.',
				'Only SELECT/WITH read queries without blocked verbs or multiple statements are eligible for a future unsafe-gated runner.',
				'Use dataphyre_sql_tables_list and dataphyre_sql_schema_read to confirm table and column intent before execution elsewhere.',
			],
		];
	}

	/**
	 * Identifies the leading SQL statement keyword after normalization.
	 *
	 * this helper performs lexical classification only and does not validate SQL grammar or dialect.
	 *
	 * @param string $sql SQL text with quoted strings already removed when used by the planner.
	 * @return string Lowercase statement kind or unknown.
	 */
	private function sql_statement_kind(string $sql): string {
		if(preg_match('/^\s*([A-Za-z]+)/', $sql, $match)!==1){
			return 'unknown';
		}
		return strtolower((string)$match[1]);
	}

	/**
	 * Masks quoted SQL string and identifier contents before lexical safety checks.
	 *
	 * preserves character positions while replacing quoted regions with spaces, reducing false
	 * matches for blocked verbs, semicolons, and table references inside literals. It is not a SQL parser.
	 *
	 * @param string $sql Raw SQL text.
	 * @return string SQL text with quoted content blanked.
	 */
	private function sql_without_quoted_strings(string $sql): string {
		$result='';
		$quote=null;
		$escaped=false;
		for($i=0, $length=strlen($sql); $i<$length; $i++){
			$char=$sql[$i];
			if($quote!==null){
				if($escaped){
					$escaped=false;
				}elseif($char==='\\'){
					$escaped=true;
				}elseif($char===$quote){
					$quote=null;
				}
				$result.=' ';
				continue;
			}
			if($char==="'" || $char==='"' || $char==='`'){
				$quote=$char;
				$result.=' ';
				continue;
			}
			$result.=$char;
		}
		return $result;
	}

	/**
	 * Detects semicolon-separated SQL statement risk after allowing one trailing terminator.
	 *
	 * the caller should pass SQL with quoted strings masked so semicolons inside literals do not count
	 * as statement boundaries.
	 *
	 * @param string $sql SQL text prepared for lexical scanning.
	 * @return bool Whether more than one statement boundary remains.
	 */
	private function sql_has_multiple_statements(string $sql): bool {
		$trimmed=trim($sql);
		if(str_ends_with($trimmed, ';')){
			$trimmed=rtrim(substr($trimmed, 0, -1));
		}
		return str_contains($trimmed, ';');
	}

	/**
	 * Finds SQL verbs and file-export constructs that are never eligible for the read-only runner contract.
	 *
	 * this lexical deny-list is a planning guard, not the only enforcement layer for a future unsafe
	 * runner. The runner must still use planner output, bounded SQL, adapter constraints, and explicit unsafe opt-in.
	 *
	 * @param string $sql SQL text prepared for lexical scanning.
	 * @return array<int, string> Unique blocked verb or construct keys.
	 */
	private function sql_blocked_verbs(string $sql): array {
		$blocked=['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create', 'replace', 'merge', 'grant', 'revoke', 'call', 'execute', 'exec', 'load', 'lock', 'unlock'];
		$found=[];
		foreach($blocked as $verb){
			if(preg_match('/\b'.preg_quote($verb, '/').'\b/i', $sql)===1){
				$found[]=$verb;
			}
		}
		if(preg_match('/\binto\s+(?:out|dump)?file\b/i', $sql)===1){
			$found[]='into_file';
		}
		return array_values(array_unique($found));
	}

	/**
	 * Extracts simple FROM and JOIN table references from SQL text.
	 *
	 * supports planning allow-lists for straightforward read queries and intentionally avoids claiming
	 * full SQL dialect coverage for subqueries, quoted identifiers, CTE aliases, or vendor-specific syntax.
	 *
	 * @param string $sql SQL text prepared for lexical scanning.
	 * @return array<int, string> Normalized table identifiers.
	 */
	private function sql_referenced_tables(string $sql): array {
		$tables=[];
		if(preg_match_all('/\b(?:from|join)\s+([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)/i', $sql, $matches)===false){
			return [];
		}
		foreach($matches[1] ?? [] as $table){
			$tables[]=$this->normalizeSqlIdentifier((string)$table);
		}
		return array_values(array_unique($tables));
	}

	/**
	 * Normalizes SQL identifiers for table allow-list comparisons.
	 *
	 * trims whitespace and backticks, then lowercases the identifier. It does not resolve schemas,
	 * aliases, quoting semantics, or database collation behavior.
	 *
	 * @param string $identifier Candidate SQL identifier.
	 * @return string Normalized identifier.
	 */
	private function normalize_sql_identifier(string $identifier): string {
		$identifier=trim($identifier);
		$identifier=trim($identifier, "` \t\n\r\0\x0B");
		return strtolower($identifier);
	}

	/**
	 * Backward-compatible camelCase wrapper used by MCP planner call sites.
	 *
	 * delegates to normalize_sql_identifier() and does not add SQL parsing or execution.
	 *
	 * @param string $identifier Candidate SQL identifier.
	 * @return string Normalized identifier.
	 */
	private function normalizeSqlIdentifier(string $identifier): string {
		return $this->normalize_sql_identifier($identifier);
	}

	/**
	 * Extracts a simple numeric LIMIT value from SQL text.
	 *
	 * recognizes only direct integer LIMIT clauses, leaving complex dialect forms to manual review or
	 * future parser-backed planning.
	 *
	 * @param string $sql SQL text prepared for lexical scanning.
	 * @return int|null Numeric LIMIT when present.
	 */
	private function sql_limit_value(string $sql): ?int {
		if(preg_match('/\blimit\s+(\d+)\b/i', $sql, $match)!==1){
			return null;
		}
		return (int)$match[1];
	}

	/**
	 * Builds the SQL string a future read-only runner would execute after planner approval.
	 *
	 * appends a LIMIT when absent and caps an existing numeric LIMIT to max_rows. This preview is
	 * emitted only for eligible read queries and should be the sole SQL string used by any future unsafe runner.
	 *
	 * @param string $sql Original SQL text.
	 * @param int|null $limit Detected numeric LIMIT from the planner.
	 * @param int $max_rows Hard row bound.
	 * @return string Bounded SQL preview.
	 */
	private function sql_bounded_preview(string $sql, ?int $limit, int $max_rows): string {
		$trimmed=rtrim(trim($sql), ';');
		if($limit===null){
			return $trimmed.' LIMIT '.$max_rows;
		}
		if($limit>$max_rows){
			return preg_replace('/\blimit\s+\d+\b/i', 'LIMIT '.$max_rows, $trimmed, 1) ?? $trimmed;
		}
		return $trimmed;
	}

	/**
	 * Documents the mandatory contract for a future unsafe-gated SQL read runner.
	 *
	 * this is a policy artifact, not an executor. It names required preflight checks, input/output
	 * boundaries, rejection conditions, and intentionally hidden data so client tooling can reason about SQL safety
	 * before a runner is implemented or enabled.
	 *
	 * @return array<string, mixed> SQL runner contract and existing safe tool map.
	 */
	private function sql_query_runner_contract(): array {
		return [
			'contract_type'=>'dataphyre_sql_query_runner_contract',
			'write_policy'=>'read_only_contract',
			'execution'=>'not_executed',
			'data_safety'=>$this->data_safety_contract('sql_query_runner_contract'),
			'unsafe_required'=>true,
			'unsafe_flag'=>'--allow-unsafe or DATAPHYRE_MCP_ALLOW_UNSAFE=1',
			'current_server_unsafe_enabled'=>$this->allow_unsafe,
			'mandatory_preflight'=>[
				'Run dataphyre_sql_query_plan with the exact SQL string, max_rows, and any table allow-list before connecting.',
				'Reject execution unless eligibility.eligible_for_future_unsafe_read_runner is true.',
				'Execute only the bounded_sql_preview returned by the planner, not the original SQL string.',
				'Resolve connection details through Dataphyre SQL configuration in-process without returning usernames, passwords, hosts, endpoints, or database names.',
				'Return column names, row count, truncated flag, timing, and redacted diagnostics only.',
			],
			'runner_input_contract'=>[
				'sql'=>'string, required; passed through dataphyre_sql_query_plan first',
				'config_path'=>'repo-relative SQL config path, required for live execution',
				'cluster'=>'optional configured cluster alias; never expose resolved endpoint details',
				'max_rows'=>'integer, default 50, hard maximum 1000',
				'allowed_tables'=>'optional table allow-list passed to dataphyre_sql_query_plan',
				'timeout_ms'=>'integer, default 5000, hard maximum 30000',
			],
			'runner_output_contract'=>[
				'execution'=>'query_executed',
				'planner'=>'full dataphyre_sql_query_plan result used for execution',
				'audit'=>['config_path', 'cluster_alias', 'statement_kind', 'referenced_tables', 'max_rows', 'duration_ms'],
				'columns'=>'ordered column labels from the result set',
				'rows'=>'bounded scalar rows only; binary/blob values summarized',
				'row_count'=>'number of returned rows',
				'truncated'=>'true when result was limited by max_rows',
			],
			'rejection_conditions'=>[
				'unsafe flag is not enabled for the MCP process',
				'planner reports any eligibility issue',
				'planner cannot detect referenced tables',
				'query contains comments, multiple statements, blocked verbs, or unallowed tables',
				'requested max_rows or timeout exceeds hard limits',
				'SQL config path is outside the repository or cannot be parsed without exposing credentials',
			],
			'intentionally_not_exposed'=>[
				'raw credentials',
				'resolved hostnames or endpoints',
				'database names',
				'prepared statement handles',
				'write, DDL, stored procedure, lock, or file export execution',
				'schema hydration side effects',
			],
			'recommended_existing_tools'=>[
				'dataphyre_sql_tables_list',
				'dataphyre_sql_schema_read',
				'dataphyre_sql_clusters_list',
				'dataphyre_sql_query_plan',
				'dataphyre_mcp_client_config_summary',
			],
		];
	}

	/**
	 * Describes operational prerequisites for future runtime SQL read execution.
	 *
	 * produces a read-only plan for caller-owned config and cluster labels while explicitly avoiding
	 * database connections, credential resolution, statement preparation, query execution, and schema hydration.
	 *
	 * @param array{config_path?: string, cluster?: string} $args Optional caller-owned placeholders.
	 * @return array<string, mixed> SQL runtime readiness plan.
	 */
	private function sql_runtime_readiness_plan(array $args): array {
		$config_path=trim((string)($args['config_path'] ?? '<sql-config>'));
		if($config_path===''){
			$config_path='<sql-config>';
		}
		$cluster=trim((string)($args['cluster'] ?? '<cluster>'));
		if($cluster===''){
			$cluster='<cluster>';
		}
		$contract=$this->sql_query_runner_contract();
		return [
			'plan_type'=>'dataphyre_sql_runtime_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'database_connection'=>'not_opened',
			'sql_queries'=>'not_executed',
			'schema_hydration'=>'not_performed',
			'config_path'=>$config_path,
			'cluster'=>$cluster,
			'data_safety'=>$this->data_safety_contract('sql_runtime_readiness'),
			'current_safe_surfaces'=>[
				'table_inventory'=>'dataphyre_sql_tables_list',
				'schema_reader'=>'dataphyre_sql_schema_read',
				'cluster_summary'=>'dataphyre_sql_clusters_list',
				'query_planner'=>'dataphyre_sql_query_plan',
				'runner_contract'=>'dataphyre_sql_query_runner_contract',
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
			],
			'future_runner_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'dataphyre_sql_query_plan must pass for the exact SQL string before any connection is opened',
				'only planner bounded_sql_preview may execute; original SQL must not bypass the planner',
				'resolved credentials, hosts, endpoints, and database names must never be returned',
				'adapter must enforce SELECT/WITH only, one statement only, max_rows, timeout, and table allow-list',
				'transactions, writes, DDL, locks, stored procedures, file exports, and schema hydration side effects must be denied',
				'rows must be scalar, bounded, redacted, and binary/blob values summarized',
			],
			'allowed_future_outputs'=>[
				'planner result used for execution',
				'cluster alias without resolved endpoint details',
				'statement kind and referenced tables',
				'column labels',
				'bounded scalar rows',
				'row count, truncated flag, and duration_ms',
				'redacted diagnostics',
			],
			'denied_future_outputs'=>$contract['intentionally_not_exposed'] ?? [
				'raw credentials',
				'resolved hostnames or endpoints',
				'database names',
				'write, DDL, stored procedure, lock, or file export execution',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'unsafe_enabled',
				'config_path',
				'cluster_alias',
				'planner',
				'allowed_tables',
				'max_rows',
				'timeout_ms',
				'redaction_policy',
				'output_bounds',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_sql_tables_list and dataphyre_sql_schema_read to confirm table and column intent.',
				'Use dataphyre_sql_clusters_list to inspect cluster aliases without exposing credentials.',
				'Use dataphyre_sql_query_plan with the exact SQL, max_rows, and allowed_tables before any future execution.',
				'Compare the future runner design against dataphyre_sql_query_runner_contract and this readiness plan.',
				'Run dataphyre_mcp_verify_all before publishing any runtime SQL runner capability.',
			],
			'safety_notes'=>[
				'This plan does not connect to a database, prepare statements, execute SQL, hydrate schemas, or read credentials.',
				'Runtime SQL execution remains intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans product-neutral; config_path and cluster are caller-owned placeholders unless an explicit unsafe workflow validates them.',
			],
		];
	}
}
