<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP documentation, docs chunk, Datadoc, and docs-readiness planning surfaces.
 */
trait dataphyre_mcp_planning_docs_surfaces {

	private function search_docs(string $query, int $limit): array {
		$query=trim($query);
		if($query===''){
			throw new InvalidArgumentException('query is required.');
		}
		$limit=max(1, min($limit ?: 12, 50));
		$matches=[];
		foreach($this->markdown_docs(500) as $path){
			$text=$this->read_repo_text($path, 200000);
			$pos=stripos($text, $query);
			if($pos===false){
				continue;
			}
			$matches[]=[
				'path'=>$path,
				'snippet'=>trim(substr($text, max(0, $pos-180), 420)),
			];
			if(count($matches)>=$limit){
				break;
			}
		}
		return [
			'query'=>$query,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('docs_search'),
			'matches'=>$matches,
		];
	}

	/**
	 * Reads one local documentation file.
	 *
	 * the path is resolved through repository safety checks and returned
	 * as bounded text metadata, keeping documentation reads local and side-effect
	 * free.
	 */
	private function read_doc(string $path): array {
		return [
			'path'=>$path,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('doc_read'),
			'text'=>$this->read_repo_text($path, 200000),
		];
	}

	/**
	 * Exports bounded local documentation chunks.
	 *
	 * markdown documents are split by module, heading, and character
	 * limits into stable chunk payloads suitable for caller-owned search or
	 * embedding indexes; this function writes no index artifacts.
	 */
	private function export_docs_chunks(array $args): array {
		$requested_modules=$args['modules'] ?? [];
		if(!is_array($requested_modules) || $requested_modules===[]){
			$requested_modules=$this->default_agent_context_modules();
		}
		$max_chunks=max(1, min((int)($args['max_chunks'] ?? 40) ?: 40, 200));
		$max_chars=max(800, min((int)($args['max_chars_per_chunk'] ?? 3500) ?: 3500, 12000));
		$include_reference=($args['include_reference'] ?? true)!==false;
		$docs_profile=strtolower(trim((string)($args['docs_profile'] ?? 'default')));
		if(!in_array($docs_profile, ['default', 'builder', 'governance'], true)){
			$docs_profile='default';
		}
		$guidelines_position=strtolower(trim((string)($args['guidelines_position'] ?? ($docs_profile==='builder' ? 'none' : 'after_modules'))));
		if(!in_array($guidelines_position, ['first', 'after_modules', 'none'], true)){
			$guidelines_position=$docs_profile==='builder' ? 'none' : 'after_modules';
		}
		$modules=[];
		$guidelines_path='common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md';
		$sources=[];
		$seen_paths=[];
		$add_source=function(string $path, string $group, ?string $module=null) use (&$sources, &$seen_paths): void {
			if($path==='' || isset($seen_paths[$path])){
				return;
			}
			$seen_paths[$path]=true;
			$sources[]=[
				'path'=>$path,
				'group'=>$group,
				'module'=>$module,
			];
		};
		if($guidelines_position==='first'){
			$add_source($guidelines_path, 'guidelines');
		}
		foreach(array_slice($requested_modules, 0, 20) as $module){
			$module=trim((string)$module);
			if($module==='' || in_array($module, $modules, true)){
				continue;
			}
			$description=$this->describe_module($module, 40);
			$modules[]=$module;
			foreach($description['files']['documentation'] ?? [] as $path){
				if($docs_profile==='builder' && $this->docs_chunk_builder_skip_path((string)$path)){
					continue;
				}
				$add_source((string)$path, 'module', $module);
			}
		}
		if($guidelines_position==='after_modules'){
			$add_source($guidelines_path, 'guidelines');
		}
		if($include_reference){
			$add_source('common/dataphyre/docs/MODULES.md', 'reference');
			$add_source('common/dataphyre/runtime/README.md', 'reference');
		}
		$chunk_sets=[];
		foreach($sources as $source){
			$path=(string)$source['path'];
			$text=$this->read_repo_text($path, 160000);
			$source_chunks=[];
			foreach($this->markdown_chunks($path, $text, $max_chars) as $chunk){
				$chunk['source_group']=$source['group'];
				if($source['module']!==null){
					$chunk['module']=$source['module'];
				}
				$source_chunks[]=$chunk;
			}
			if($docs_profile==='builder' && ($source['group'] ?? null)==='module'){
				usort($source_chunks, fn(array $left, array $right): int => $this->docs_chunk_builder_score($right)<=>$this->docs_chunk_builder_score($left));
				$source_chunks=$this->docs_chunk_builder_diversify($source_chunks);
			}
			if($source_chunks!==[]){
				$chunk_sets[]=[
					'group'=>$source['group'],
					'chunks'=>$source_chunks,
				];
			}
		}
		$take_round_robin=static function(array $sets, int $limit): array {
			$result=[];
			$offsets=array_fill(0, count($sets), 0);
			while(count($result)<$limit){
				$added=false;
				foreach($sets as $index=>$set){
					$available=$set['chunks'] ?? [];
					$offset=$offsets[$index] ?? 0;
					if(!is_array($available) || !isset($available[$offset])){
						continue;
					}
					$result[]=$available[$offset];
					$offsets[$index]=$offset+1;
					$added=true;
					if(count($result)>=$limit){
						break;
					}
				}
				if(!$added){
					break;
				}
			}
			return $result;
		};
		if($guidelines_position==='after_modules'){
			$module_sets=array_values(array_filter($chunk_sets, static fn(array $set): bool => ($set['group'] ?? null)==='module'));
			$support_sets=array_values(array_filter($chunk_sets, static fn(array $set): bool => ($set['group'] ?? null)!=='module'));
			$chunks=$take_round_robin($module_sets, $max_chunks);
			if(count($chunks)<$max_chunks){
				$chunks=array_merge($chunks, $take_round_robin($support_sets, $max_chunks-count($chunks)));
			}
		}else{
			$chunks=$take_round_robin($chunk_sets, $max_chunks);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'discovery_safety'=>$this->discovery_safety_contract('docs_chunks_export'),
			'modules'=>$modules,
			'docs_profile'=>$docs_profile,
			'guidelines_position'=>$guidelines_position,
			'chunk_count'=>count($chunks),
			'max_chars_per_chunk'=>$max_chars,
			'chunks'=>$chunks,
		];
	}

	/**
	 * Returns true when a documentation file is status/audit context rather than
	 * ordinary app-building API context.
	 */
	private function docs_chunk_builder_skip_path(string $path): bool {
		$normalized=strtolower(str_replace('\\', '/', $path));
		foreach(['capability_audit', 'audit.md', 'roadmap', 'release', 'changelog'] as $needle){
			if(str_contains($normalized, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Scores module documentation chunks for builder-mode task packs.
	 *
	 * @param array<string,mixed> $chunk Markdown chunk payload.
	 */
	private function docs_chunk_builder_score(array $chunk): int {
		$heading=strtolower((string)($chunk['heading'] ?? ''));
		$text=strtolower(substr((string)($chunk['text'] ?? ''), 0, 1400));
		$path=strtolower(str_replace('\\', '/', (string)($chunk['path'] ?? '')));
		$haystack=$heading."\n".$text;
		$score=0;
		foreach([
			'resource definitions'=>500,
			'table definitions'=>480,
			'scaffolding'=>460,
			'schemas'=>440,
			'generated tables'=>420,
			'repositoryquery'=>400,
			'tablequery'=>400,
			'tableschema'=>380,
			'tablerepository'=>380,
			'queryspec'=>360,
		] as $needle=>$weight){
			if(str_contains($heading, $needle)){
				$score+=$weight;
			}
		}
		foreach([
			'resource definitions'=>140,
			'table definitions'=>130,
			'scaffolding'=>125,
			'schemas'=>120,
			'generated tables'=>115,
			'repositoryquery'=>105,
			'tablequery'=>105,
			'tableschema'=>100,
			'tablerepository'=>100,
			'queryspec'=>95,
			'filters'=>90,
			'actions'=>90,
			'fields'=>85,
			'resource builder'=>85,
			'panel::resource'=>80,
			'panel::field'=>75,
			'repository::query'=>75,
			'db::table'=>70,
		] as $needle=>$weight){
			if(str_contains($haystack, $needle)){
				$score+=$weight;
			}
		}
		foreach(['reality status', 'status legend', 'capability audit', 'roadmap', 'public claim', 'enterprise', 'optional framework layer', 'observability'] as $needle){
			if(str_contains($haystack, $needle)){
				$score-=100;
			}
		}
		if(str_contains($path, '/panel/documentation/dataphyre_panel.md')){
			$score+=20;
		}
		if(str_contains($path, '/sql/documentation/dataphyre_sql.md')){
			$score+=15;
		}
		return $score;
	}

	/**
	 * Moves first chunks for unique headings before continuations so small builder
	 * packs cover more APIs.
	 *
	 * @param list<array<string,mixed>> $chunks
	 * @return list<array<string,mixed>>
	 */
	private function docs_chunk_builder_diversify(array $chunks): array {
		$first=[];
		$rest=[];
		$seen=[];
		foreach($chunks as $chunk){
			$heading=strtolower(trim((string)($chunk['heading'] ?? '')));
			if($heading==='' || isset($seen[$heading])){
				$rest[]=$chunk;
				continue;
			}
			$seen[$heading]=true;
			$first[]=$chunk;
		}
		return array_merge($first, $rest);
	}

	/**
	 * Plans a documentation index from safe local sources.
	 *
	 * the plan names source groups, chunking policy, metadata schema, and
	 * client steps for external indexing while avoiding network fetches, embedding
	 * calls, SQL reads, and file writes.
	 */
	private function docs_index_plan(array $args): array {
		$include_remote=($args['include_remote_templates'] ?? true)!==false;
		$chunk_args=[
			'modules'=>is_array($args['modules'] ?? null) ? $args['modules'] : $this->default_agent_context_modules(),
			'max_chunks'=>max(1, min((int)($args['max_chunks'] ?? 40) ?: 40, 120)),
			'max_chars_per_chunk'=>max(800, min((int)($args['max_chars_per_chunk'] ?? 3500) ?: 3500, 12000)),
		];
		$chunks=$this->export_docs_chunks($chunk_args);
		$paths=[];
		foreach($chunks['chunks'] ?? [] as $chunk){
			$path=(string)($chunk['path'] ?? '');
			if($path!==''){
				$paths[]=$path;
			}
		}
		$source_groups=[
			[
				'name'=>'local_markdown',
				'status'=>'available',
				'source_tool'=>'dataphyre_docs_chunks_export',
				'paths'=>array_values(array_unique($paths)),
				'refresh_triggers'=>['markdown file changes', 'runtime module documentation changes', 'MCP docs or guideline changes'],
			],
			[
				'name'=>'static_datadoc_contract',
				'status'=>'available_without_sql',
				'source_tool'=>'dataphyre_datadoc_static_summary',
				'paths'=>['common/dataphyre/runtime/modules/datadoc/documentation/Dataphyre_Datadoc.md'],
				'refresh_triggers'=>['Datadoc module source changes', 'Datadoc table contract changes'],
			],
		];
		if($include_remote){
			$source_groups[]=[
				'name'=>'optional_remote_documentation',
				'status'=>'caller_owned',
				'source_tool'=>null,
				'url_templates'=>['https://<docs-host>/<dataphyre-version>/<module-or-topic>'],
				'refresh_triggers'=>['client configured remote source changes', 'published documentation version changes'],
			];
		}
		return [
			'plan_type'=>'dataphyre_docs_index_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'network'=>'not_used',
			'sql_queries'=>'not_executed',
			'modules'=>$chunks['modules'] ?? [],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('docs_index_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('docs_index_plan'),
			'source_groups'=>$source_groups,
			'chunking'=>[
				'source_tool'=>'dataphyre_docs_chunks_export',
				'sampled_chunk_count'=>$chunks['chunk_count'] ?? 0,
				'max_chars_per_chunk'=>$chunks['max_chars_per_chunk'] ?? $chunk_args['max_chars_per_chunk'],
				'recommended_overlap_chars'=>240,
				'stable_chunk_id_fields'=>['path', 'heading', 'chunk_index', 'line_start', 'line_end', 'content_sha256'],
			],
			'embedding_payload_schema'=>[
				'id'=>'string',
				'text'=>'string',
				'metadata'=>['path'=>'string', 'module'=>'string|null', 'heading'=>'string|null', 'chunk_index'=>'int', 'line_start'=>'int', 'line_end'=>'int', 'content_sha256'=>'string', 'source_group'=>'string', 'generated_at'=>'iso8601'],
			],
			'client_steps'=>[
				'Call dataphyre_docs_chunks_export for local markdown chunks.',
				'Call dataphyre_datadoc_static_summary for Datadoc contracts without querying Datadoc SQL rows.',
				'Optionally fetch remote docs in a client-owned process and keep the source URL in metadata.',
				'Create embeddings or a search index outside the MCP server process.',
				'Refresh affected chunks when source paths, docs versions, or module documentation timestamps change.',
				'Use dataphyre_mcp_docs_coverage_report and dataphyre_mcp_verify_all before release-facing claims.',
			],
			'safety_notes'=>[
				'This plan does not fetch remote URLs, call embedding APIs, query Datadoc SQL records, or write index files.',
				'Do not store config secrets, credentials, tracelog payloads, or raw SQL results in documentation embeddings.',
				'Keep remote documentation sources caller-configured and product-neutral.',
			],
		];
	}

	/**
	 * Plans safeguards for future remote documentation fetching.
	 *
	 * this readiness plan is intentionally non-networked and describes
	 * host, redirect, byte-limit, redaction, cache, and attribution requirements
	 * before any client-owned remote fetcher may exist.
	 */
	private function remote_docs_readiness_plan(array $args): array {
		$base_url=trim((string)($args['base_url'] ?? '<docs-base-url>'));
		if($base_url===''){
			$base_url='<docs-base-url>';
		}
		return [
			'plan_type'=>'dataphyre_remote_docs_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'network'=>'not_used',
			'artifacts_written'=>false,
			'base_url'=>$base_url,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('remote_docs_readiness'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('remote_docs_readiness'),
			'current_safe_surfaces'=>[
				'local_docs_search'=>'dataphyre_search_docs',
				'local_docs_reader'=>'dataphyre_read_doc',
				'local_docs_chunks'=>'dataphyre_docs_chunks_export',
				'index_plan'=>'dataphyre_docs_index_plan',
				'docs_coverage'=>'dataphyre_mcp_docs_coverage_report',
			],
			'future_fetcher_preconditions'=>[
				'remote base URL must be caller-provided, absolute http(s), and not hardcoded in shared MCP code',
				'allowed hosts, path prefixes, redirects, timeouts, byte limits, and content types must be audited before fetching',
				'network fetches must run in a client-owned process, not inside the default MCP server',
				'downloaded content must be bounded, scanned for secrets, and attributed with source URL and fetched_at metadata',
				'remote content must be treated as untrusted and kept separate from first-party local docs until reviewed',
				'cache writes must use caller-owned directories and must not modify Dataphyre runtime or MCP module files',
			],
			'allowed_future_outputs'=>[
				'remote URL, status code, content type, byte count, and fetched_at timestamp',
				'bounded markdown/text snippets',
				'content hash and cache key',
				'redirect chain summary without sensitive query values',
				'redaction and truncation flags',
			],
			'denied_future_outputs'=>[
				'auth headers, cookies, tokens, or signed query strings',
				'unbounded remote pages or binary assets',
				'remote JavaScript execution results',
				'downloads written into shared MCP module paths',
				'product-specific remote domains hardcoded into shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'base_url',
				'allowed_hosts',
				'allowed_path_prefixes',
				'timeout_ms',
				'max_bytes',
				'content_types',
				'cache_policy',
				'redaction_policy',
				'verification_steps',
			],
			'client_steps'=>[
				'Use local docs search, docs chunks, and docs index plans before fetching remote documentation.',
				'Configure remote documentation sources in the client, not in shared MCP code.',
				'Audit allowed hosts, redirects, content type, byte limits, and cache location before fetching.',
				'Normalize fetched content into the docs index payload shape from dataphyre_docs_index_plan.',
				'Run dataphyre_mcp_docs_coverage_report and dataphyre_mcp_verify_all before publishing remote-docs support.',
			],
			'safety_notes'=>[
				'This plan does not make network requests, fetch URLs, execute remote scripts, or write cached docs.',
				'Remote documentation fetching remains client-owned and outside default read-only MCP behavior.',
				'Keep shared MCP plans product-neutral; concrete docs hosts belong in caller-owned client configuration.',
			],
		];
	}

	/**
	 * Plans safeguards for future documentation embeddings.
	 *
	 * embeddings remain caller-owned; the plan lists provider/model,
	 * batching, redaction, cache, metadata, and forbidden-output constraints without
	 * calling embedding APIs or writing vector indexes.
	 */
	private function embeddings_readiness_plan(array $args): array {
		$provider=trim((string)($args['provider'] ?? '<embedding-provider>'));
		$model=trim((string)($args['model'] ?? '<embedding-model>'));
		if($provider===''){
			$provider='<embedding-provider>';
		}
		if($model===''){
			$model='<embedding-model>';
		}
		return [
			'plan_type'=>'dataphyre_embeddings_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'network'=>'not_used',
			'embedding_api_calls'=>'not_executed',
			'artifacts_written'=>false,
			'provider'=>$provider,
			'model'=>$model,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('embeddings_readiness'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('embeddings_readiness'),
			'current_safe_surfaces'=>[
				'local_docs_chunks'=>'dataphyre_docs_chunks_export',
				'docs_index_plan'=>'dataphyre_docs_index_plan',
				'remote_docs_readiness'=>'dataphyre_remote_docs_readiness_plan',
				'docs_coverage'=>'dataphyre_mcp_docs_coverage_report',
			],
			'future_embeddings_preconditions'=>[
				'embedding provider, model, dimensions, and rate limits must be caller-provided and not hardcoded in shared MCP code',
				'embedding API calls must run in a client-owned process, not inside the default MCP server',
				'input chunks must come from bounded documentation payloads and must be scanned for secrets before embedding',
				'batch size, token budget, timeout, retry budget, and cache location must be audited before any API call',
				'metadata must preserve source path, heading, chunk hash, source group, generated_at, provider, model, and dimensions',
				'cache writes must use caller-owned directories and must not modify Dataphyre runtime or MCP module files',
			],
			'allowed_future_outputs'=>[
				'embedding provider, model, dimensions, batch size, and generated_at timestamp',
				'chunk id, content hash, source path, heading, module, source group, and token estimate',
				'bounded vector metadata and cache key',
				'redaction, truncation, and skipped-chunk flags',
				'aggregate counts for embedded, skipped, failed, and cached chunks',
			],
			'denied_future_outputs'=>[
				'embedding provider API keys, auth headers, cookies, tokens, or signed URLs',
				'unbounded raw documentation, tracelog payloads, SQL results, or config values',
				'secret-looking text embedded without redaction review',
				'embedding vectors written into shared MCP module paths',
				'provider-specific endpoints hardcoded into shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'provider',
				'model',
				'dimensions',
				'input_source',
				'batch_size',
				'max_tokens_per_chunk',
				'timeout_ms',
				'retry_budget',
				'cache_policy',
				'redaction_policy',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_docs_chunks_export and dataphyre_docs_index_plan to prepare bounded documentation payloads.',
				'Run secret scanning and truncation before sending text to an embedding provider.',
				'Configure provider, model, dimensions, rate limits, and cache location in the client, not in shared MCP code.',
				'Create embeddings and vector indexes outside the MCP server process.',
				'Run dataphyre_mcp_docs_coverage_report and dataphyre_mcp_verify_all before publishing embeddings support.',
			],
			'safety_notes'=>[
				'This plan does not call embedding APIs, fetch URLs, query SQL, or write vector indexes.',
				'Embeddings remain client-owned and outside default read-only MCP behavior.',
				'Keep shared MCP plans provider-neutral; concrete credentials and endpoints belong in caller-owned client configuration.',
			],
		];
	}

	/**
	 * Summarizes Datadoc's static source, table, tokenizer, and UI contracts.
	 *
	 * this summary reads source files and documentation only, extracting
	 * table definitions and class method names without querying Datadoc SQL rows,
	 * dispatching UI routes, or executing tokenizers/highlighters.
	 */
	private function datadoc_static_summary(): array {
		$files=[
			'main'=>'common/dataphyre/runtime/modules/datadoc/kernel/datadoc.main.php',
			'tables'=>'common/dataphyre/runtime/modules/datadoc/kernel/datadoc.tables.php',
			'tokenizer'=>'common/dataphyre/runtime/modules/datadoc/kernel/tokenizer.php',
			'highlighter'=>'common/dataphyre/runtime/modules/datadoc/kernel/highlighter.php',
			'diagnostic'=>'common/dataphyre/runtime/modules/datadoc/kernel/datadoc.diagnostic.php',
			'documentation'=>'common/dataphyre/runtime/modules/datadoc/documentation/Dataphyre_Datadoc.md',
		];
		$sources=[];
		foreach($files as $key=>$relative){
			$path=$this->root.'/'.$relative;
			$sources[$key]=is_file($path) ? (string)file_get_contents($path) : '';
		}
		$registered_tables=[];
		if(preg_match_all("/sql_define_table\\('([^']+)'\\s*,\\s*__DIR__\\.\\s*'\\/datadoc\\.tables\\.php'\\s*,\\s*'([^']+)'\\)/", $sources['main'], $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				$registered_tables[]=[
					'table'=>$match[1],
					'definition_id'=>$match[2],
				];
			}
		}
		$table_definitions=[];
		foreach(['projects', 'data', 'files'] as $definition){
			$table_definitions[$definition]=[
				'detected'=>str_contains($sources['tables'], "'".$definition."'=>"),
				'columns'=>$this->datadoc_table_columns($sources['tables'], $definition),
			];
		}
		$main_summary=$this->php_source_api_file_summary($this->root.'/'.$files['main']);
		$tokenizer_summary=$this->php_source_api_file_summary($this->root.'/'.$files['tokenizer']);
		$highlighter_summary=$this->php_source_api_file_summary($this->root.'/'.$files['highlighter']);
		return [
			'summary_type'=>'datadoc_static_contract',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'sql_queries'=>'not_executed',
			'ui_rendering'=>'not_executed',
			'sources'=>array_map(fn(string $relative): array => [
				'path'=>$relative,
				'exists'=>is_file($this->root.'/'.$relative),
			], $files),
			'module_contract'=>[
				'required_modules'=>['flightdeck'],
				'config_constant'=>'DP_DATADOC_CFG',
				'deferred_sql_registration'=>str_contains($sources['main'], "dataphyre_deferred_sql_table_definitions']['datadoc"),
				'diagnostic_include_condition'=>'RUN_MODE === diagnostic',
			],
			'sql_contract'=>[
				'registered_tables'=>$registered_tables,
				'table_definitions'=>$table_definitions,
				'querying_policy'=>'MCP summarizes table contracts only and does not call sql_select, sql_count, sql_insert, sql_update, sql_delete, or hydrate_table_definition.',
			],
			'index_contract'=>[
				'project_table'=>'datadoc.projects',
				'record_table'=>'dataphyre.datadoc_data',
				'file_table'=>'dataphyre.datadoc_files',
				'record_identity'=>['checksum', 'project'],
				'lookup_index'=>['class', 'function', 'namespace', 'project'],
				'stale_tracking'=>['filepath', 'project', 'last_synced', 'is_stale'],
			],
			'tokenizer_contract'=>[
				'class'=>'dataphyre\\datadoc\\tokenizer',
				'methods'=>$this->method_names_from_summary($tokenizer_summary),
				'token_types'=>['namespace', 'class', 'function'],
				'phpdoc_fields'=>['description', 'tags'],
				'script_blocks_skipped'=>str_contains($sources['tokenizer'], '<script'),
			],
			'highlighter_contract'=>[
				'class'=>'dataphyre\\datadoc\\highlighter',
				'methods'=>$this->method_names_from_summary($highlighter_summary),
				'link_surfaces'=>['/dataphyre/datadoc/<project>/dynadoc', '/dataphyre/datadoc/assets/<asset>'],
			],
			'ui_contract'=>[
				'flightdeck_owned_auth'=>str_contains($sources['documentation'], 'Flightdeck authentication'),
				'routes'=>['/dataphyre/datadoc', '/dataphyre/datadoc/{project}/dynadoc', '/dataphyre/datadoc/dynadoc_menu_processor', '/dataphyre/datadoc/assets/{asset}'],
				'manual_docs'=>'Filesystem-backed Manudoc paths are normalized before lookup.',
				'dynamic_docs'=>'Dynadoc records are loaded from indexed dataphyre.datadoc_data rows by project/class/function/namespace.',
			],
			'datadoc_class_methods'=>$this->method_names_from_summary($main_summary),
			'guardrails'=>[
				'This tool does not load Datadoc, authenticate to Flightdeck, query SQL, hydrate schemas, sync files, render UI, or read indexed records.',
				'Use dataphyre_sql_schema_read for first-party table definitions and future unsafe-gated query tools for live Datadoc records.',
				'Use dataphyre_docs_chunks_export for local markdown context when live Datadoc SQL records are not available.',
			],
		];
	}

	/**
	 * Plans safeguards for a future SQL-backed Datadoc runtime reader.
	 *
	 * the plan exposes required unsafe envelopes, filters, bounds,
	 * allowed/denied outputs, and verification steps while deliberately avoiding
	 * live SQL queries, route dispatch, and Datadoc index mutation.
	 */
	private function datadoc_runtime_readiness_plan(array $args): array {
		$project=trim((string)($args['project'] ?? '<project>'));
		if($project===''){
			$project='<project>';
		}
		$static=$this->datadoc_static_summary();
		return [
			'plan_type'=>'dataphyre_datadoc_runtime_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'sql_queries'=>'not_executed',
			'database_connection'=>'not_opened',
			'route_dispatch'=>'not_performed',
			'project'=>$project,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('datadoc_runtime_readiness'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('datadoc_runtime_readiness'),
			'current_safe_surfaces'=>[
				'static_contract'=>'dataphyre_datadoc_static_summary',
				'docs_chunks'=>'dataphyre_docs_chunks_export',
				'docs_index_plan'=>'dataphyre_docs_index_plan',
				'sql_query_planner'=>'dataphyre_sql_query_plan',
				'sql_runtime_readiness'=>'dataphyre_sql_runtime_readiness_plan',
			],
			'detected_static_contract'=>[
				'project_table'=>'datadoc.projects',
				'record_table'=>'dataphyre.datadoc_data',
				'file_table'=>'dataphyre.datadoc_files',
				'registered_table_count'=>count($static['sql_contract']['registered_tables'] ?? []),
				'tokenizer_class'=>$static['tokenizer_contract']['class'] ?? null,
				'highlighter_class'=>$static['highlighter_contract']['class'] ?? null,
			],
			'future_reader_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'Datadoc project, class, function, namespace, or file filters must be caller-provided and bounded',
				'dataphyre_sql_query_plan and dataphyre_sql_runtime_readiness_plan constraints must apply before any Datadoc SQL read',
				'only SELECT/WITH queries against Datadoc tables may be considered',
				'row count, text bytes, snippets, and highlighted output must be bounded',
				'credentials, connection endpoints, auth context, and raw SQL diagnostics must not be returned',
				'Datadoc UI routes, assets, tokenizers, and highlighters must not be dispatched or executed from the MCP reader',
			],
			'allowed_future_outputs'=>[
				'project identifier and bounded record counts',
				'class, function, namespace, or file labels',
				'bounded markdown or documentation snippets',
				'static Datadoc table and tokenizer/highlighter contract references',
				'redacted diagnostics and truncation flags',
			],
			'denied_future_outputs'=>[
				'raw credentials or resolved SQL endpoints',
				'unbounded Datadoc record bodies',
				'route dispatch responses from Datadoc UI routes',
				'raw auth headers, cookies, or session payloads',
				'write, delete, reindex, or table-mutation behavior',
				'product-specific local scripts, paths, or binaries in shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'unsafe_enabled',
				'project',
				'filters',
				'allowed_tables',
				'max_records',
				'max_bytes_per_record',
				'redaction_policy',
				'output_bounds',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_datadoc_static_summary to inspect Datadoc tables, UI routes, tokenizer, and highlighter contracts.',
				'Use dataphyre_docs_chunks_export and dataphyre_docs_index_plan when local markdown documentation is enough.',
				'Use SQL readiness and query-planning tools before considering any future SQL-backed Datadoc reader.',
				'Only consider a future Datadoc reader after SQL adapter, project filters, output bounds, and redaction are enforceable.',
				'Run dataphyre_mcp_verify_all before publishing any Datadoc runtime reader capability.',
			],
			'safety_notes'=>[
				'This plan does not connect to SQL, query Datadoc records, dispatch Datadoc routes, execute tokenizers, or write indexes.',
				'Datadoc SQL-backed records remain intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans product-neutral; project filters belong in caller-owned unsafe envelopes.',
			],
		];
	}

	/**
	 * Extracts Datadoc table column metadata from table-definition source text.
	 *
	 * this parser is a static contract helper for summaries; it reads a
	 * bounded source block, ignores index/unique builder calls, and returns column
	 * names/types without hydrating SQL schemas.
	 */
	private function datadoc_table_columns(string $source, string $definition): array {
		$columns=[];
		$pos=strpos($source, "'".$definition."'=>");
		if($pos===false){
			return [];
		}
		$next=strpos($source, "\n\t'", $pos+1);
		$block=$next===false ? substr($source, $pos) : substr($source, $pos, $next-$pos);
		if(preg_match_all('/->([A-Za-z][A-Za-z0-9_]*)\\(\\s*[\'"]([^\'"]+)[\'"]/', $block, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				if(in_array($match[1], ['unique', 'index'], true)){
					continue;
				}
				$columns[]=[
					'name'=>$match[2],
					'type'=>$match[1],
				];
			}
		}
		return $columns;
	}

	/**
	 * Collects unique method names from a PHP source API summary.
	 *
	 * static Datadoc summaries use this helper to flatten class method
	 * metadata into concise capability lists while preserving source inspection as
	 * the only authority.
	 */
	private function method_names_from_summary(array $summary): array {
		$methods=[];
		foreach($summary['classes'] ?? [] as $class){
			foreach($class['methods'] ?? [] as $method){
				$name=(string)($method['name'] ?? '');
				if($name!==''){
					$methods[]=$name;
				}
			}
		}
		return array_values(array_unique($methods));
	}


}
