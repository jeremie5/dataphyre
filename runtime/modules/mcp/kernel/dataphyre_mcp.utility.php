<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines Mcp kernel trait responsibilities for dataphyre mcp utility methods.
 *
 * Mcp kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait dataphyre_mcp_utility_methods {

	/**
	 * Builds an MCP tool descriptor with a closed JSON-object input schema.
	 *
	 * Tool surfaces call this helper so every exported tool has a consistent
	 * name/description/schema shape and rejects undeclared arguments by default.
	 * The helper only describes the tool; execution routing and capability safety
	 * checks are handled by the surrounding MCP server methods.
	 *
	 * @param string $name MCP tool name exposed to clients.
	 * @param string $description Human-readable tool purpose.
	 * @param array<string, mixed> $properties JSON Schema properties keyed by argument name.
	 * @param array<int, string> $required Required argument names.
	 * @return array{name: string, description: string, inputSchema: array<string, mixed>} MCP tool descriptor.
	 */
	private function tool(string $name, string $description, array $properties, array $required=[]): array {
		return [
			'name'=>$name,
			'description'=>$description,
			'inputSchema'=>[
				'type'=>'object',
				'properties'=>$properties,
				'required'=>$required,
				'additionalProperties'=>false,
			],
		];
	}

	/**
	 * Loads the minimal Routing classes required by MCP route inspection.
	 *
	 * this bootstrap is idempotent and imports only framework/compiler
	 * classes needed for static route manifest work; it does not dispatch routes
	 * or execute application handlers.
	 */
	private function bootstrap_routing(): void {
		static $booted=false;
		if($booted){
			return;
		}
		$root=$this->common_root.'/dataphyre/runtime/modules/routing';
		require_once $root.'/Framework/CompilableRoute.php';
		require_once $root.'/Framework/ControllerAction.php';
		require_once $root.'/Framework/Route.php';
		require_once $root.'/Framework/RouteManifest.php';
		require_once $root.'/Framework/RouteCompiler.php';
		require_once $root.'/kernel/compiled_route_dispatcher.php';
		$booted=true;
	}

	/**
	 * Loads the SQL table-definition classes used by static schema readers.
	 *
	 * this bootstrap keeps SQL inspection limited to schema objects. It
	 * does not open database connections, run queries, or hydrate runtime data.
	 */
	private function bootstrap_sql_definitions(): void {
		static $booted=false;
		if($booted){
			return;
		}
		$root=$this->common_root.'/dataphyre/runtime/modules/sql/Framework';
		require_once $root.'/SqlError.php';
		require_once $root.'/TableSchema.php';
		require_once $root.'/TableDefinition.php';
		$booted=true;
	}

	/**
	 * Loads a first-party runtime table definition by table name.
	 *
	 * the lookup uses the hardcoded runtime table manifest, requires the
	 * table definition PHP file, and accepts TableDefinition instances or factories
	 * while avoiding live SQL access.
	 */
	private function load_runtime_table_definition(string $table): ?\Dataphyre\Database\TableDefinition {
		$this->bootstrap_sql_definitions();
		$manifest=$this->sql_runtime_table_manifest();
		$entry=$manifest[$table] ?? null;
		if(!is_array($entry)){
			return null;
		}
		$file=$this->common_root.'/dataphyre/runtime/modules/'.trim((string)$entry['file'], '/\\');
		if(!is_file($file)){
			return null;
		}
		$definitions=require $file;
		$definition_id=$entry['definition_id'] ?? null;
		$candidates=[];
		if($definition_id!==null && is_array($definitions) && array_key_exists($definition_id, $definitions)){
			$candidates[]=$definitions[$definition_id];
		}
		if(is_array($definitions) && array_key_exists($table, $definitions)){
			$candidates[]=$definitions[$table];
		}
		if($definitions instanceof \Dataphyre\Database\TableDefinition || is_callable($definitions)){
			$candidates[]=$definitions;
		}
		if(is_array($definitions)){
			foreach($definitions as $definition){
				$candidates[]=$definition;
			}
		}
		foreach($candidates as $candidate){
			if(is_callable($candidate)){
				try{
					$candidate=$candidate($table, $definition_id);
				}catch(ArgumentCountError){
					$candidate=$candidate($table);
				}
			}
			if($candidate instanceof \Dataphyre\Database\TableDefinition){
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Loads a table definition from a caller-provided repo-local definition file.
	 *
	 * paths are constrained through safe_repo_path, candidates are
	 * resolved by definition id, table name, direct instance, callable, or array
	 * entries, and no database query is executed.
	 */
	private function load_config_table_definition(string $definition_file, string $table, ?string $definition_id=null): ?\Dataphyre\Database\TableDefinition {
		$this->bootstrap_sql_definitions();
		$definition_file=trim(str_replace('\\', '/', $definition_file));
		if($definition_file===''){
			return null;
		}
		$file=$this->safe_repo_path($definition_file);
		if(!is_file($file)){
			return null;
		}
		$definitions=require $file;
		$candidates=[];
		if($definition_id!==null && is_array($definitions) && array_key_exists($definition_id, $definitions)){
			$candidates[]=$definitions[$definition_id];
		}
		if(is_array($definitions) && array_key_exists($table, $definitions)){
			$candidates[]=$definitions[$table];
		}
		if($definitions instanceof \Dataphyre\Database\TableDefinition || is_callable($definitions)){
			$candidates[]=$definitions;
		}
		if(is_array($definitions)){
			foreach($definitions as $definition){
				$candidates[]=$definition;
			}
		}
		foreach($candidates as $candidate){
			if(is_callable($candidate)){
				try{
					$candidate=$candidate($table, $definition_id);
				}catch(ArgumentCountError){
					$candidate=$candidate($table);
				}
			}
			if($candidate instanceof \Dataphyre\Database\TableDefinition){
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Reads a repo-local sql.php configuration file.
	 *
	 * the helper temporarily supplies SERVER_ADDR for config files that
	 * expect web context, restores $_SERVER afterward, and requires the file to
	 * return an array without revealing credentials directly.
	 */
	private function read_sql_config(string $config_path): array {
		$path=$this->safe_repo_path($config_path);
		if(!is_file($path) || basename($path)!=='sql.php'){
			throw new InvalidArgumentException('config_path must point to a repo-local sql.php file.');
		}
		$previous_server=$_SERVER;
		$_SERVER['SERVER_ADDR']=$_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
		try{
			$config=require $path;
		}finally{
			$_SERVER=$previous_server;
		}
		if(!is_array($config)){
			throw new RuntimeException('SQL config did not return an array.');
		}
		return $config;
	}

	/**
	 * Returns the static map of known runtime SQL tables to definition files.
	 *
	 * this manifest is used for read-only schema discovery and deliberately
	 * contains only first-party runtime definition paths and ids, not database
	 * connection metadata.
	 */
	private function sql_runtime_table_manifest(): array {
		return [
			'dataphyre.sessions'=>['file'=>'access/kernel/access.tables.php', 'definition_id'=>'sessions'],
			'dataphyre.access_tokens'=>['file'=>'access/kernel/access.tables.php', 'definition_id'=>'tokens'],
			'dataphyre.aceit_engine_experiments'=>['file'=>'aceit_engine/aceit_engine.tables.php', 'definition_id'=>'experiments'],
			'dataphyre.vestra_objects'=>['file'=>'vestra/kernel/vestra.tables.php', 'definition_id'=>'objects'],
			'dataphyre.exchange_rates'=>['file'=>'currency/kernel/currency.tables.php', 'definition_id'=>'exchange_rates'],
			'datadoc.projects'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'projects'],
			'dataphyre.datadoc_data'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'data'],
			'dataphyre.datadoc_files'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'files'],
			'dataphyre.captcha_blocks'=>['file'=>'firewall/kernel/firewall.tables.php', 'definition_id'=>'captcha_blocks'],
			'dataphyre.mailer_outbox'=>['file'=>'mailer/kernel/mailer.tables.php', 'definition_id'=>'outbox'],
			'dataphyre.mailer_events'=>['file'=>'mailer/kernel/mailer.tables.php', 'definition_id'=>'events'],
			'dataphyre.postal_codes_regex'=>['file'=>'geoposition/kernel/geoposition.tables.php', 'definition_id'=>'postal_codes_regex'],
			'dataphyre.postal_codes'=>['file'=>'geoposition/kernel/geoposition.tables.php', 'definition_id'=>'postal_codes'],
			'issues'=>['file'=>'issue/kernel/issue.tables.php', 'definition_id'=>'issues'],
			'locales'=>['file'=>'localization/kernel/localization.tables.php', 'definition_id'=>'locales'],
			'dataphyre.internal_events'=>['file'=>'sentinel/kernel/sentinel.tables.php', 'definition_id'=>'events'],
			'stripe_payment_methods'=>['file'=>'stripe/kernel/stripe.tables.php', 'definition_id'=>'payment_methods'],
			'dataphyre.user_changes'=>['file'=>'time_machine/kernel/time_machine.tables.php', 'definition_id'=>'user_changes'],
			'dataphyre.tracelogs'=>['file'=>'tracelog/kernel/tracelog.tables.php', 'definition_id'=>'tracelogs'],
		];
	}

	/**
	 * Lists markdown documentation files under the repository root.
	 *
	 * traversal is bounded by limit and all results are returned as
	 * repository-relative paths so MCP clients avoid broad unbounded reads.
	 */
	private function markdown_docs(int $limit): array {
		$docs=[];
		$roots=[$this->root];
		$dataphyre_root=$this->common_root.'/dataphyre';
		if(is_dir($dataphyre_root) && $this->normalize_path($dataphyre_root)!==$this->root){
			$roots[]=$dataphyre_root;
		}
		foreach($roots as $root){
			foreach($this->all_files($root, 20000) as $path){
				if(strtolower(pathinfo($path, PATHINFO_EXTENSION))==='md'){
					$docs[]=$this->relative_path($path);
				}
				if(count($docs)>=$limit){
					break 2;
				}
			}
		}
		$docs=array_values(array_unique($docs));
		sort($docs);
		return $docs;
	}

	/**
	 * Lists files below a root by extension.
	 *
	 * this bounded helper backs module summaries and documentation packs,
	 * normalizing extensions to lower case and returning sorted relative paths.
	 */
	private function files_under(string $root, array $extensions, int $limit): array {
		if(!is_dir($root)){
			return [];
		}
		$extensions=array_map('strtolower', $extensions);
		$files=[];
		foreach($this->all_files($root, $limit * 3) as $path){
			$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
			if(in_array($extension, $extensions, true)){
				$files[]=$this->relative_path($path);
			}
			if(count($files)>=$limit){
				break;
			}
		}
		sort($files);
		return $files;
	}

	/**
	 * Iterates files under a path with repository hygiene exclusions.
	 *
	 * the generator yields a bounded set of filesystem paths, skips .git
	 * and direct CDN asset content, and can treat a single file as a one-item
	 * traversal source.
	 */
	private function all_files(string $root, int $limit): Generator {
		if(!is_dir($root) && !is_file($root)){
			return;
		}
		if(is_file($root)){
			yield $root;
			return;
		}
		if(!is_readable($root)){
			return;
		}
		$flags=FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME;
		try{
			$iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, $flags),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		}catch(UnexpectedValueException|RuntimeException){
			return;
		}
		$count=0;
		try{
			foreach($iterator as $path){
				$normalized=str_replace('\\', '/', $path);
				if(str_contains($normalized, '/.git/') || str_contains($normalized, '/cdn_content/direct/assets/')){
					continue;
				}
				if(is_file($path)){
					yield $path;
					$count++;
					if($count>=$limit){
						return;
					}
				}
			}
		}catch(UnexpectedValueException|RuntimeException){
			return;
		}
	}

	/**
	 * Extracts top-level-looking config keys from JSON or PHP config text.
	 *
	 * JSON files are decoded for real keys while PHP files are scanned
	 * statically for array-key syntax; values are never returned.
	 */
	private function extract_config_keys(string $path): array {
		$text=file_get_contents($path);
		if(!is_string($text)){
			return [];
		}
		if(strtolower(pathinfo($path, PATHINFO_EXTENSION))==='json'){
			$data=json_decode($text, true);
			return is_array($data) ? array_slice(array_keys($data), 0, 80) : [];
		}
		preg_match_all('/[\'"]([A-Za-z0-9_.-]+)[\'"]\s*=>/', $text, $matches);
		return array_values(array_unique(array_slice($matches[1] ?? [], 0, 80)));
	}

	/**
	 * Summarizes JSON configuration shape.
	 *
	 * valid JSON is recursively reduced to key paths, value kinds, and
	 * redaction flags; invalid JSON returns an explicit invalid_json sentinel.
	 */
	private function json_config_shape(string $path, int $max_paths): array {
		$data=json_decode((string)file_get_contents($path), true);
		if(!is_array($data)){
			return [['path'=>'', 'kind'=>'invalid_json', 'redacted'=>false]];
		}
		$paths=[];
		$this->collect_config_shape($data, '', $paths, $max_paths);
		return $paths;
	}

	/**
	 * Summarizes PHP configuration shape through static key scanning.
	 *
	 * the helper reports possible key paths and sensitivity flags without
	 * requiring the PHP config file, which avoids executing application config code.
	 */
	private function php_config_shape(string $path, int $max_paths): array {
		$text=(string)file_get_contents($path);
		$paths=[];
		preg_match_all('/([\'"])([A-Za-z0-9_.-]+)\1\s*=>/', $text, $matches, PREG_OFFSET_CAPTURE);
		foreach($matches[2] ?? [] as $match){
			$key=(string)$match[0];
			$paths[]=[
				'path'=>$key,
				'kind'=>'unknown',
				'redacted'=>$this->is_sensitive_config_key($key),
			];
			if(count($paths)>=$max_paths){
				break;
			}
		}
		return array_values($this->unique_config_shape_paths($paths));
	}

	/**
	 * Parses a simple returned PHP literal array from source text.
	 *
	 * this parser handles static return expressions only and returns null
	 * when the config is not expressible as a previewable literal array.
	 */
	private function php_config_literal_array(string $text): ?array {
		if(preg_match('/return\s+(.+?)\s*;/s', $text, $match)!==1){
			return null;
		}
		return $this->php_literal_array_from_expression(trim($match[1]));
	}

	/**
	 * Parses a PHP array expression into preview values.
	 *
	 * nested arrays are parsed recursively, scalar literals are reduced to
	 * safe values, and dynamic expressions are represented as unpreviewable
	 * expression metadata rather than executed.
	 */
	private function php_literal_array_from_expression(string $expression): ?array {
		$entries=$this->php_array_entries_flexible($expression);
		if($entries===null){
			return null;
		}
		$result=[];
		foreach($entries as $key=>$value_expression){
			$value_expression=trim($value_expression);
			if($this->php_array_entries_flexible($value_expression)!==null){
				$result[$key]=$this->php_literal_array_from_expression($value_expression);
				continue;
			}
			$result[$key]=$this->php_literal_scalar_from_expression($value_expression);
		}
		return $result;
	}

	/**
	 * Extracts key/value expressions from short or array() PHP arrays.
	 *
	 * only top-level literal keys are captured, preserving value
	 * expressions as text for later safe parsing and avoiding eval.
	 */
	private function php_array_entries_flexible(string $expression): ?array {
		$expression=trim($expression);
		if($expression===''){
			return null;
		}
		if($expression[0]==='['){
			return $this->top_level_php_array_entries($expression);
		}
		if(preg_match('/^array\s*\(/i', $expression)===1){
			$open=strpos($expression, '(');
			if($open===false){
				return null;
			}
			$close=$this->matching_enclosure_offset($expression, (int)$open, '(', ')');
			if($close===null){
				return null;
			}
			$body=substr($expression, $open+1, $close-$open-1);
			$entries=[];
			foreach($this->split_top_level_php_expressions($body) as $entry){
				$pair=$this->split_top_level_arrow($entry);
				if($pair===null){
					continue;
				}
				$key=$this->literal_string_from_expression(trim($pair[0]));
				if($key===null){
					continue;
				}
				$entries[$key]=trim($pair[1]);
			}
			return $entries;
		}
		return null;
	}

	/**
	 * Converts a PHP scalar literal expression into a preview value.
	 *
	 * strings, booleans, null, integers, and floats are decoded; every
	 * other expression becomes an unpreviewable sentinel carrying its expression
	 * kind.
	 */
	private function php_literal_scalar_from_expression(string $expression): mixed {
		$expression=trim($expression);
		$literal=$this->literal_string_from_expression($expression);
		if($literal!==null){
			return $literal;
		}
		$lower=strtolower($expression);
		if($lower==='true'){
			return true;
		}
		if($lower==='false'){
			return false;
		}
		if($lower==='null'){
			return null;
		}
		if(preg_match('/^-?\d+$/', $expression)===1){
			return (int)$expression;
		}
		if(is_numeric($expression)){
			return (float)$expression;
		}
		return ['__unpreviewable_expression'=>$this->php_expression_kind($expression)];
	}

	/**
	 * Extracts the bracketed PHP array block assigned to a key.
	 *
	 * the block is located through static text matching and balanced
	 * bracket parsing, useful for config previews without executing source.
	 */
	private function php_array_block_for_key(string $text, string $key): ?string {
		if(preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>\s*\[/', $text, $match, PREG_OFFSET_CAPTURE)!==1){
			return null;
		}
		$open=(int)$match[0][1]+strlen($match[0][0])-1;
		$close=$this->matching_bracket_offset($text, $open);
		return $close!==null ? substr($text, $open, $close-$open+1) : null;
	}

	/**
	 * Parses top-level entries from a short PHP array expression.
	 *
	 * this helper respects nested brackets and strings, returning only
	 * literal-key entries from the outermost array.
	 */
	private function top_level_php_array_entries(string $expression): array {
		$expression=trim($expression);
		if($expression==='' || $expression[0]!=='['){
			return [];
		}
		$end=$this->matching_bracket_offset($expression, 0);
		if($end===null){
			return [];
		}
		$body=substr($expression, 1, $end-1);
		$entries=[];
		foreach($this->split_top_level_php_expressions($body) as $entry){
			$pair=$this->split_top_level_arrow($entry);
			if($pair===null){
				continue;
			}
			$key=$this->literal_string_from_expression(trim($pair[0]));
			if($key===null){
				continue;
			}
			$entries[$key]=trim($pair[1]);
		}
		return $entries;
	}

	/**
	 * Splits a PHP expression list on top-level commas.
	 *
	 * nested arrays, parentheses, braces, quoted strings, and escapes are
	 * tracked so config-array parsing does not split inside child expressions.
	 */
	private function split_top_level_php_expressions(string $body): array {
		$parts=[];
		$current='';
		$depth=0;
		$quote=null;
		$escaped=false;
		for($i=0, $length=strlen($body); $i<$length; $i++){
			$char=$body[$i];
			if($quote!==null){
				$current.=$char;
				if($escaped){
					$escaped=false;
				}elseif($char==='\\'){
					$escaped=true;
				}elseif($char===$quote){
					$quote=null;
				}
				continue;
			}
			if($char==="'" || $char==='"'){
				$quote=$char;
				$current.=$char;
				continue;
			}
			if($char==='[' || $char==='(' || $char==='{'){
				$depth++;
			}elseif($char===']' || $char===')' || $char==='}'){
				$depth=max(0, $depth-1);
			}elseif($char===',' && $depth===0){
				if(trim($current)!==''){
					$parts[]=trim($current);
				}
				$current='';
				continue;
			}
			$current.=$char;
		}
		if(trim($current)!==''){
			$parts[]=trim($current);
		}
		return $parts;
	}

	/**
	 * Splits one PHP array entry on its top-level arrow.
	 *
	 * the parser ignores arrows inside nested structures and quoted text,
	 * returning key/value expression fragments or null for list-style entries.
	 */
	private function split_top_level_arrow(string $entry): ?array {
		$depth=0;
		$quote=null;
		$escaped=false;
		for($i=0, $length=strlen($entry)-1; $i<$length; $i++){
			$char=$entry[$i];
			if($quote!==null){
				if($escaped){
					$escaped=false;
				}elseif($char==='\\'){
					$escaped=true;
				}elseif($char===$quote){
					$quote=null;
				}
				continue;
			}
			if($char==="'" || $char==='"'){
				$quote=$char;
				continue;
			}
			if($char==='[' || $char==='(' || $char==='{'){
				$depth++;
			}elseif($char===']' || $char===')' || $char==='}'){
				$depth=max(0, $depth-1);
			}elseif($char==='=' && $entry[$i+1]==='>' && $depth===0){
				return [substr($entry, 0, $i), substr($entry, $i+2)];
			}
		}
		return null;
	}

	/**
	 * Finds the closing bracket for a short-array opening bracket.
	 *
	 * this is a bracket-specific wrapper around enclosure matching used
	 * by PHP config preview parsers.
	 */
	private function matching_bracket_offset(string $text, int $open): ?int {
		if(($text[$open] ?? '')!=='['){
			return null;
		}
		return $this->matching_enclosure_offset($text, $open, '[', ']');
	}

	/**
	 * Finds the matching closing enclosure while respecting strings.
	 *
	 * balanced enclosure scanning handles nested pairs, quoted strings,
	 * and escapes, making static PHP source parsing safer than regular-expression
	 * slicing alone.
	 */
	private function matching_enclosure_offset(string $text, int $open, string $open_char, string $close_char): ?int {
		if(($text[$open] ?? '')!==$open_char){
			return null;
		}
		$depth=0;
		$quote=null;
		$escaped=false;
		for($i=$open, $length=strlen($text); $i<$length; $i++){
			$char=$text[$i];
			if($quote!==null){
				if($escaped){
					$escaped=false;
				}elseif($char==='\\'){
					$escaped=true;
				}elseif($char===$quote){
					$quote=null;
				}
				continue;
			}
			if($char==="'" || $char==='"'){
				$quote=$char;
				continue;
			}
			if($char===$open_char){
				$depth++;
			}elseif($char===$close_char){
				$depth--;
				if($depth===0){
					return $i;
				}
			}
		}
		return null;
	}

	/**
	 * Classifies a PHP expression for preview metadata.
	 *
	 * unknown or dynamic values are labeled by coarse kind so MCP outputs
	 * can explain why a config value was not previewed.
	 */
	private function php_expression_kind(string $expression): string {
		$expression=trim($expression);
		return match(true){
			$expression==='' => 'unknown',
			str_starts_with($expression, '[') => 'array',
			preg_match('/^[\'"].*[\'"]$/s', $expression)===1 => 'string',
			in_array(strtolower($expression), ['true', 'false'], true) => 'bool',
			strtolower($expression)==='null' => 'null',
			is_numeric($expression) => 'number',
			default => 'expression',
		};
	}

	/**
	 * Retrieves a nested config value by dotted key path.
	 *
	 * the return shape explicitly distinguishes missing paths from found
	 * null values and preserves unpreviewable sentinels for callers to redact or
	 * explain.
	 */
	private function config_value_at_path(array $source, string $key_path): array {
		$current=$source;
		foreach(explode('.', $key_path) as $part){
			if(!is_array($current) || !array_key_exists($part, $current)){
				return ['found'=>false, 'value'=>null];
			}
			$current=$current[$part];
		}
		if(is_array($current) && array_key_exists('__unpreviewable_expression', $current)){
			return ['found'=>true, 'value'=>$current];
		}
		return ['found'=>true, 'value'=>$current];
	}

	/**
	 * Reports whether a config value can be safely previewed.
	 *
	 * associative arrays, nested structures, objects, resources, and
	 * unpreviewable sentinels are withheld to avoid leaking complex or sensitive
	 * configuration content.
	 */
	private function is_previewable_config_value(mixed $value): bool {
		if(is_array($value)){
			if(array_key_exists('__unpreviewable_expression', $value) || !array_is_list($value)){
				return false;
			}
			foreach($value as $item){
				if(is_array($item) || is_object($item)){
					return false;
				}
			}
			return true;
		}
		return !is_object($value) && !is_resource($value);
	}

	/**
	 * Classifies a config value for shape summaries.
	 *
	 * value kinds intentionally avoid exposing actual values, while
	 * unpreviewable PHP expression sentinels preserve the expression classification.
	 */
	private function config_value_kind(mixed $value): string {
		if(is_array($value) && array_key_exists('__unpreviewable_expression', $value)){
			return (string)$value['__unpreviewable_expression'];
		}
		return match(true){
			is_array($value)=>array_is_list($value) ? 'list' : 'object',
			is_bool($value)=>'bool',
			is_int($value)=>'int',
			is_float($value)=>'float',
			is_null($value)=>'null',
			default=>'scalar',
		};
	}

	/**
	 * Lists storage driver classes discovered in the runtime framework.
	 *
	 * this static scan reads PHP API summaries from the storage driver
	 * directory and returns class/file pairs without instantiating drivers or
	 * touching external storage services.
	 */
	private function storage_driver_classes(): array {
		$drivers=[];
		$root=$this->common_root.'/dataphyre/runtime/modules/storage/Framework/Drivers';
		foreach($this->files_under($root, ['php'], 80) as $relative){
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$drivers[]=[
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'file'=>$relative,
				];
			}
		}
		return $drivers;
	}

	/**
	 * Converts a storage driver class name into its config key.
	 *
	 * the transformation strips a Driver suffix and snake-cases the short
	 * class name so summaries can correlate driver classes with configuration
	 * keys.
	 */
	private function storage_driver_key_from_class(string $class): string {
		$short=basename(str_replace('\\', '/', $class));
		$short=preg_replace('/Driver$/', '', $short) ?? $short;
		$key=preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short;
		return strtolower($key);
	}

	/**
	 * Recursively collects config key paths and value kinds.
	 *
	 * traversal is bounded by max_paths, marks sensitive paths, and uses
	 * [] for numeric list keys so callers receive stable shape metadata instead of
	 * raw config values.
	 */
	private function collect_config_shape(array $data, string $prefix, array &$paths, int $max_paths): void {
		foreach($data as $key=>$value){
			if(count($paths)>=$max_paths){
				return;
			}
			$key=is_int($key) ? '[]' : (string)$key;
			$path=$prefix==='' ? $key : $prefix.'.'.$key;
			$paths[]=[
				'path'=>$path,
				'kind'=>$this->config_value_kind($value),
				'redacted'=>$this->is_sensitive_config_key($path),
			];
			if(is_array($value)){
				$this->collect_config_shape($value, $path, $paths, $max_paths);
			}
		}
	}

	/**
	 * Deduplicates config-shape entries by path.
	 *
	 * later entries replace earlier ones for the same path, giving shape
	 * summaries stable keys before returning them to MCP clients.
	 */
	private function unique_config_shape_paths(array $paths): array {
		$unique=[];
		foreach($paths as $path){
			$key=(string)($path['path'] ?? '');
			if($key===''){
				continue;
			}
			$unique[$key]=$path;
		}
		return $unique;
	}

	/**
	 * Detects config keys that should be redacted or withheld.
	 *
	 * matching is deliberately broad around credentials, endpoints,
	 * storage, network, and authorization terms because MCP previews should prefer
	 * false positives over accidental secret disclosure.
	 */
	private function is_sensitive_config_key(string $key): bool {
		return preg_match('/(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|signing[_-]?key|authorization|cookie|dsn|endpoint|host|database|bucket|region|url)/i', $key)===1;
	}

	/**
	 * Splits markdown text into bounded heading-aware chunks.
	 *
	 * chunks carry stable ids, source path, heading, index, and text for
	 * caller-owned search/embedding workflows while this server writes no index
	 * artifacts.
	 */
	private function markdown_chunks(string $path, string $text, int $max_chars): array {
		$chunks=[];
		$current_title=basename($path);
		$current='';
		$index=0;
		$current_start_line=1;
		$current_end_line=1;
		$flush=function() use (&$chunks, &$current, &$index, $path, &$current_title, &$current_start_line, &$current_end_line): void {
			$body=trim($current);
			if($body===''){
				return;
			}
			$chunks[]=[
				'id'=>sha1($path.'#'.$index.'#'.$current_title),
				'path'=>$path,
				'heading'=>$current_title,
				'index'=>$index,
				'chunk_index'=>$index,
				'line_start'=>$current_start_line,
				'line_end'=>$current_end_line,
				'content_sha256'=>hash('sha256', $body),
				'text'=>$body,
			];
			$index++;
			$current='';
		};
		foreach(preg_split('/\R/', $text) ?: [] as $line_index=>$line){
			$line_number=(int)$line_index+1;
			if(preg_match('/^(#{1,6})\s+(.+)$/', $line, $match)===1){
				$flush();
				$current_title=trim((string)$match[2]);
			}
			if($current!=='' && strlen($current."\n".$line)>$max_chars){
				$flush();
			}
			if($current===''){
				$current_start_line=$line_number;
			}
			$current.=($current==='' ? '' : "\n").$line;
			$current_end_line=$line_number;
		}
		$flush();
		return $chunks;
	}

	/**
	 * Reports whether a path looks like a Dataphyre unit-test manifest.
	 *
	 * detection is path and extension based, keeping manifest summaries
	 * static and avoiding execution of test helpers.
	 */
	private function is_unit_test_manifest(string $relative): bool {
		$normalized=str_replace('\\', '/', $relative);
		return str_contains($normalized, '/unit_tests/')
			&& strtolower(pathinfo($normalized, PATHINFO_EXTENSION))==='json';
	}

	/**
	 * Extracts the owning runtime module from a unit-test manifest path.
	 *
	 * this path parser supports MCP test inventory summaries without
	 * loading module bootstrap files.
	 */
	private function module_from_unit_test_path(string $relative): ?string {
		$normalized=str_replace('\\', '/', $relative);
		if(preg_match('#common/dataphyre/runtime/modules/([^/]+)/unit_tests/#', $normalized, $match)===1){
			return $match[1];
		}
		return null;
	}

	/**
	 * Summarizes a Dataphyre unit-test manifest JSON file.
	 *
	 * the manifest is decoded as data, cases are bounded, helper files and
	 * expected result shapes are reported, and custom-script presence is flagged
	 * without executing test code.
	 */
	private function unit_test_manifest_summary(string $path, int $max_cases, bool $include_expected): array {
		$text=(string)file_get_contents($path);
		$data=json_decode($text, true);
		if(!is_array($data)){
			return [
				'valid_json'=>false,
				'json_error'=>json_last_error_msg(),
				'case_count'=>0,
				'cases'=>[],
				'helper_files'=>[],
				'has_custom_script'=>false,
			];
		}
		$cases=array_is_list($data) ? $data : [$data];
		$helpers=[];
		$summaries=[];
		$has_custom_script=false;
		foreach(array_slice($cases, 0, $max_cases) as $case){
			if(!is_array($case)){
				continue;
			}
			$file=(string)($case['file'] ?? '');
			if($file!==''){
				$helpers[]=$file;
			}
			$expected=$case['expected'] ?? null;
			$case_has_custom=$this->contains_unit_test_custom_script($expected) || isset($case['file_dynamic']);
			$has_custom_script=$has_custom_script || $case_has_custom;
			$entry=[
				'name'=>(string)($case['name'] ?? ''),
				'function'=>(string)($case['function'] ?? ''),
				'helper_file'=>$file !== '' ? $file : null,
				'args_count'=>is_array($case['args'] ?? null) ? count($case['args']) : 0,
				'expected_count'=>is_array($expected) && array_is_list($expected) ? count($expected) : ($expected===null ? 0 : 1),
				'expected_shapes'=>$this->unit_test_expected_shapes($expected),
				'has_custom_script'=>$case_has_custom,
				'max_millis'=>$case['max_millis'] ?? null,
			];
			if($include_expected){
				$entry['expected']=$expected;
			}
			$summaries[]=$entry;
		}
		return [
			'valid_json'=>true,
			'case_count'=>count($cases),
			'returned_cases'=>count($summaries),
			'truncated'=>count($cases)>$max_cases,
			'helper_files'=>array_values(array_unique($helpers)),
			'has_custom_script'=>$has_custom_script,
			'cases'=>$summaries,
		];
	}

	/**
	 * Collects expected-output shape labels from a unit-test manifest case.
	 *
	 * list and single expected payloads are normalized into unique shape
	 * names so summaries can describe assertions without exposing full payloads
	 * unless explicitly requested.
	 */
	private function unit_test_expected_shapes(mixed $expected): array {
		$values=is_array($expected) && array_is_list($expected) ? $expected : [$expected];
		$shapes=[];
		foreach($values as $value){
			$shapes[]=$this->unit_test_expected_shape($value);
		}
		return array_values(array_unique($shapes));
	}

	/**
	 * Classifies one expected-output declaration from a unit-test manifest.
	 *
	 * custom scripts, numeric ranges, array-shape declarations, regexes,
	 * and scalar debug types receive coarse labels for static test inventory.
	 */
	private function unit_test_expected_shape(mixed $value): string {
		if(is_array($value)){
			if(isset($value['custom_script'])){
				return 'custom_script';
			}
			if(isset($value['min'], $value['max'])){
				return 'numeric_range';
			}
			if(($value[0] ?? null)==='array'){
				return 'array_shape';
			}
			return 'array';
		}
		if(is_string($value) && str_starts_with($value, 'regex:')){
			return 'regex';
		}
		return get_debug_type($value);
	}

	/**
	 * Detects custom-script expectations recursively.
	 *
	 * custom scripts change test execution risk, so the summary records
	 * their presence without evaluating or returning the script body.
	 */
	private function contains_unit_test_custom_script(mixed $value): bool {
		if(!is_array($value)){
			return false;
		}
		if(isset($value['custom_script'])){
			return true;
		}
		foreach($value as $child){
			if($this->contains_unit_test_custom_script($child)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Reports whether a file path appears to be a Tracelog or log artifact.
	 *
	 * detection is extension and path-name based so diagnostics can
	 * inventory likely log outputs while avoiding unrelated binaries.
	 */
	private function is_tracelog_artifact(string $relative): bool {
		$normalized=strtolower(str_replace('\\', '/', $relative));
		$extension=strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
		if(!in_array($extension, ['dat', 'html', 'htm', 'log', 'txt', 'json'], true)){
			return false;
		}
		return str_contains($normalized, 'tracelog')
			|| str_contains($normalized, '/logs/')
			|| str_contains($normalized, '/log/');
	}

	/**
	 * Classifies a Tracelog-like artifact path.
	 *
	 * known handoff and plotting artifacts receive specific labels, while
	 * generic tracelog/log files fall back to broader categories.
	 */
	private function tracelog_artifact_kind(string $relative): string {
		$normalized=strtolower(str_replace('\\', '/', $relative));
		if(str_contains($normalized, 'tracelog_handoff')){
			return 'tracelog_handoff';
		}
		if(str_contains($normalized, 'tracelog_plotting') || basename($normalized)==='plotting.dat'){
			return 'tracelog_plotting';
		}
		if(str_contains($normalized, 'tracelog')){
			return 'tracelog';
		}
		return 'log';
	}

	/**
	 * Returns a file's modification time as an ISO-8601 UTC string.
	 *
	 * missing or inaccessible file timestamps become null so inventory
	 * tools can remain best-effort and read-only.
	 */
	private function file_modified_iso(string $path): ?string {
		$mtime=@filemtime($path);
		return is_int($mtime) ? gmdate('c', $mtime) : null;
	}

	/**
	 * Converts a byte offset into a one-based line number.
	 *
	 * snippet and diagnostics tools use this helper to report source
	 * locations without tokenizing the whole file.
	 */
	private function line_number_for_offset(string $text, int $offset): int {
		return substr_count(substr($text, 0, max(0, $offset)), "\n")+1;
	}

	/**
	 * Builds a compact whitespace-normalized snippet around a match.
	 *
	 * snippets are bounded, ellipsized when truncated, and flattened for
	 * diagnostics/search output that should not return full source or log files.
	 */
	private function snippet_around(string $text, int $offset, int $length, int $max_chars): string {
		$start=max(0, $offset-(int)floor($max_chars / 2));
		$snippet=substr($text, $start, $max_chars);
		$snippet=trim(preg_replace('/\s+/', ' ', $snippet) ?? '');
		if($start>0){
			$snippet='...'.$snippet;
		}
		if($offset+$length<strlen($text)){
			$snippet.='...';
		}
		return $snippet;
	}

	/**
	 * Redacts common credential-looking values from diagnostic text.
	 *
	 * this is a defensive output filter for MCP diagnostics, config, and
	 * log snippets; it favors broad pattern matching over complete secret
	 * detection.
	 */
	private function redact_sensitive_text(string $text): string {
		$sensitive_key=$this->mcp_sensitive_assignment_key_pattern();
		$patterns=[
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/is'=>'[REDACTED_PRIVATE_KEY]',
			'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i'=>'$1 [REDACTED]',
			'/(\b(?:'.$sensitive_key.')\b\s*[:=]\s*[\'"])([^\'"]+)([\'"])/i'=>'$1[REDACTED]$3',
			'/(\b(?:'.$sensitive_key.')\b\s*[:=]\s*)([^\s<>"\']+)/i'=>'$1[REDACTED]',
			'/(\b(?:'.$sensitive_key.')\b[\'"]?\s*=>\s*[\'"])([^\'"]+)([\'"])/i'=>'$1[REDACTED]$3',
			'/(\b(?:tenant|tenant_id|tenantId|product|product_id|productId|customer|customer_id|customerId|account|account_id|accountId)\b\s*[:=]\s*[\'"])([^\'"]+)([\'"])/i'=>'$1[REDACTED]$3',
			'/(\b(?:tenant|tenant_id|tenantId|product|product_id|productId|customer|customer_id|customerId|account|account_id|accountId)\b\s*[:=]\s*)([^\s<>"\']+)/i'=>'$1[REDACTED]',
			'/(\b(?:tenant|tenant_id|tenantId|product|product_id|productId|customer|customer_id|customerId|account|account_id|accountId)\b[\'"]?\s*=>\s*[\'"])([^\'"]+)([\'"])/i'=>'$1[REDACTED]$3',
			'/(\b(?:X-Amz-Signature|X-Amz-Credential|X-Amz-Security-Token|AWSAccessKeyId|Signature|Expires|sig|signature|passkey|totp|access_token|id_token|refresh_token|tenant_id|tenantId|product_id|productId|customer_id|customerId|account_id|accountId|plan|plan_id|planId|subscription_id|subscriptionId|entitlement_id|entitlementId)=)([^\s&#"\'<>]+)/i'=>'$1[REDACTED]',
			'#\b([a-z][a-z0-9+.-]*://)([^\s/@:]+):([^\s/@]+)@([^\s<>"\']+)#i'=>'$1[REDACTED]',
			'#[A-Za-z]:\\\\(?:[^\s<>"\'|?*]+\\\\)*[^\s<>"\'|?*]*#'=>'[REDACTED_PATH]',
			'#[A-Za-z]:/(?:[^\s<>"\'|?*]+/)*[^\s<>"\'|?*]*#'=>'[REDACTED_PATH]',
			'#(?<![A-Za-z0-9_])/(?:home|Users|var|tmp|etc|opt|srv|mnt|Volumes|workspace|root)/[^\s<>"\']+#'=>'[REDACTED_PATH]',
		];
		foreach($patterns as $pattern=>$replacement){
			$text=preg_replace($pattern, $replacement, $text) ?? $text;
		}
		return $text;
	}

	/**
	 * Returns credential-bearing key names recognized by MCP redaction/audits.
	 *
	 * Keep this shared so diagnostic redaction and workflow handoff audits flag
	 * the same common compound and camelCase credential names.
	 */
	private function mcp_sensitive_assignment_key_pattern(): string {
		return 'password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie|set-cookie|client[_-]?secret|clientSecret|private[_-]?key|privateKey|webhook[_-]?secret|webhookSecret|signing[_-]?secret|signingSecret|auth[_-]?token|authToken|accessToken|idToken|refreshToken';
	}

	/**
	 * Describes the shared MCP redaction/audit contract without exposing values.
	 *
	 * @return array<string,mixed> Compact redaction contract metadata.
	 */
	private function mcp_redaction_contract(): array {
		return [
			'surface'=>'shared_mcp_redaction_contract',
			'applies_to'=>[
				'diagnostic previews and copy-safe diagnostic summaries',
				'command-backed verification helper stdout/stderr',
				'workflow state and transcript audits',
				'workflow transcript summaries and checkpoints',
				'caller-controlled handoff labels',
			],
			'sensitive_assignment_keys'=>[
				'password',
				'secret',
				'token',
				'api_key',
				'authorization',
				'cookie',
				'client_secret',
				'clientSecret',
				'private_key',
				'privateKey',
				'webhookSecret',
				'signing_secret',
				'authToken',
				'accessToken',
				'idToken',
				'refreshToken',
			],
			'redacts'=>[
				'bearer/basic credentials',
				'private-key blocks',
				'connection strings',
				'signed URL parameters',
				'tenant/customer/product/account scoped identifiers',
				'machine-local absolute paths',
			],
			'audit_signal'=>'secret_assignment',
			'copy_safe_policy'=>'Share copy-safe evidence, summaries, result keys, and redacted labels instead of raw logs, raw transcript bodies, credential-bearing values, tenant identifiers, signed URLs, or local paths.',
			'ordinary_app_policy'=>'This redaction contract is safety metadata; it does not require dev tools, aggregate MCP verification, or Dataphyre hot-path benchmarks for ordinary application work.',
		];
	}

	/**
	 * Redacts and bounds caller-controlled labels used in agent handoff payloads.
	 */
	private function mcp_safe_handoff_label(mixed $value, int $max_chars=240): string {
		return $this->redact_sensitive_text(substr((string)$value, 0, max(1, $max_chars)));
	}

	/**
	 * Extracts Flightdeck route-like strings from source text.
	 *
	 * this static scan finds /dataphyre paths for route inventory while
	 * excluding asset routes and never dispatching the routes.
	 */
	private function extract_flightdeck_route_strings(string $text): array {
		preg_match_all('#/dataphyre(?:/[A-Za-z0-9_{}?=&%./-]+)?#', $text, $matches);
		$routes=[];
		foreach($matches[0] ?? [] as $route){
			$route=rtrim((string)$route, '\'"<>)].,;');
			if($route!=='' && !str_contains($route, '/flightdeck/assets/')){
				$routes[]=$route;
			}
		}
		sort($routes);
		return array_values(array_unique($routes));
	}

	/**
	 * Extracts Flightdeck surface asset filenames from source text.
	 *
	 * the helper returns unique CSS/JS asset names referenced in code so
	 * diagnostics can map surface dependencies without reading binary assets.
	 */
	private function extract_flightdeck_asset_names(string $text): array {
		preg_match_all('/[\'"]([A-Za-z0-9_-]+-surface\.(?:css|js))[\'"]/', $text, $matches);
		$assets=array_map(static fn(string $asset): string => $asset, $matches[1] ?? []);
		sort($assets);
		return array_values(array_unique($assets));
	}

	/**
	 * Extracts module names passed to known module-loading functions.
	 *
	 * this static parser supports dependency summaries by reading quoted
	 * arguments from dp_module_required/load_framework_module calls, including the
	 * array form used by load_framework_modules.
	 */
	private function extract_module_names_from_calls(string $text, string $function): array {
		$modules=[];
		$quoted='[\'"]([A-Za-z0-9_\\-]+)[\'"]';
		if($function==='load_framework_modules'){
			preg_match_all('/load_framework_modules\s*\(\s*\[([^\]]*)\]/', $text, $matches);
			foreach($matches[1] ?? [] as $body){
				preg_match_all('/'.$quoted.'/', (string)$body, $names);
				foreach($names[1] ?? [] as $name){
					$modules[]=(string)$name;
				}
			}
			return $modules;
		}
		preg_match_all('/'.$function.'\s*\(([^)]*)\)/', $text, $matches);
		foreach($matches[1] ?? [] as $body){
			preg_match_all('/'.$quoted.'/', (string)$body, $names);
			foreach($names[1] ?? [] as $name){
				$modules[]=(string)$name;
			}
		}
		return $modules;
	}

	/**
	 * Extracts the first quoted string argument for a function call.
	 *
	 * used for static SQL table and dependency summaries where only
	 * literal arguments are trustworthy without executing PHP.
	 */
	private function extract_string_arguments(string $text, string $function): array {
		$values=[];
		preg_match_all('/'.$function.'\s*\(\s*[\'"]([^\'"]+)[\'"]/', $text, $matches);
		foreach($matches[1] ?? [] as $value){
			$values[]=(string)$value;
		}
		return $values;
	}

	/**
	 * Extracts include and require expressions from PHP source text.
	 *
	 * expressions are returned as shortened text snippets for dependency
	 * maps, not resolved or executed.
	 */
	private function extract_include_expressions(string $text): array {
		$includes=[];
		preg_match_all('/\b(?:require|require_once|include|include_once)\s*\(?\s*([^;\n]+)\)?\s*;/', $text, $matches);
		foreach($matches[1] ?? [] as $expr){
			$expr=trim((string)$expr);
			if($expr!==''){
				$includes[]=$this->shorten_text($expr, 160);
			}
		}
		return $includes;
	}

	/**
	 * Truncates text to a maximum length with an ellipsis.
	 *
	 * this output-bound helper keeps MCP snippets and static expressions
	 * compact and predictable.
	 */
	private function shorten_text(string $text, int $max): string {
		return strlen($text)>$max ? substr($text, 0, max(0, $max-3)).'...' : $text;
	}

	/**
	 * Reads bounded text from a repository-local path.
	 *
	 * the path is validated through safe_repo_path and the read is capped
	 * by max_bytes so documentation and source previews cannot become unbounded.
	 */
	private function read_repo_text(string $path, int $max_bytes): string {
		$safe=$this->safe_repo_path($path);
		if(!is_file($safe)){
			throw new InvalidArgumentException('File not found: '.$this->path_error_label($path));
		}
		$text=file_get_contents($safe, false, null, 0, $max_bytes);
		return is_string($text) ? $text : '';
	}

	/**
	 * Resolves a path while enforcing Dataphyre workspace boundaries.
	 *
	 * relative paths are rooted in the workspace, absolute paths are
	 * normalized, missing leaf files may be validated through their parent, and any
	 * path outside the repo/common roots is rejected.
	 */
	private function safe_repo_path(string $path): string {
		$path=trim(str_replace('\\', '/', $path));
		if($path===''){
			throw new InvalidArgumentException('Path is required.');
		}
		if(preg_match('/^[A-Za-z]:\//', $path)===1 || str_starts_with($path, '/')){
			$candidate=$path;
		}else{
			$candidate=$this->root.'/'.$path;
		}
		$real=realpath($candidate);
		if(!is_string($real)){
			$parent=realpath(dirname($candidate));
			if(!is_string($parent)){
				throw new InvalidArgumentException('Path parent does not exist: '.$this->path_error_label($path));
			}
			$real=$parent.'/'.basename($candidate);
		}
		$normalized=$this->normalize_path($real);
		if(!$this->path_is_within_root($normalized, $this->root) && !$this->path_is_within_root($normalized, $this->common_root)){
			throw new InvalidArgumentException('Path escapes the Dataphyre workspace: '.$this->path_error_label($path));
		}
		return $normalized;
	}

	/**
	 * Formats caller-supplied paths for error messages without leaking local roots.
	 *
	 * Absolute paths can contain usernames, tenant/customer names, or local layout
	 * details. Repo-relative paths remain useful for local debugging.
	 */
	private function path_error_label(string $path): string {
		$normalized=trim(str_replace('\\', '/', $path));
		if($normalized==='' || preg_match('/^[A-Za-z]:\//', $normalized)===1 || str_starts_with($normalized, '/')){
			return '<absolute-path>';
		}
		return $this->shorten_text($normalized, 160);
	}

	/**
	 * Checks whether a normalized path is exactly a root or one of its children.
	 *
	 * Prefix-only checks can mistake sibling paths such as /repo2 for /repo, so
	 * all repository boundary checks require either exact equality or a slash
	 * separator after the allowed root.
	 */
	private function path_is_within_root(string $path, string $root): bool {
		$root=$this->normalize_path($root);
		return $path===$root || str_starts_with($path, $root.'/');
	}

	/**
	 * Converts an absolute workspace path to a repository-relative path when possible.
	 *
	 * MCP responses prefer relative paths for portability while preserving
	 * absolute paths that are outside the primary repo root but still allowed.
	 */
	private function relative_path(string $path): string {
		$path=$this->normalize_path($path);
		if(str_starts_with($path, $this->root.'/')){
			return substr($path, strlen($this->root)+1);
		}
		$dataphyre_root=$this->normalize_path($this->common_root.'/dataphyre');
		if($this->path_is_within_root($path, $dataphyre_root)){
			if($path===$dataphyre_root){
				return 'common/dataphyre';
			}
			return 'common/dataphyre/'.substr($path, strlen($dataphyre_root)+1);
		}
		return $path;
	}

	/**
	 * Normalizes path separators and trims trailing slashes.
	 *
	 * all path-boundary checks use this representation so Windows and
	 * Unix-style paths are compared consistently.
	 */
	private function normalize_path(string $path): string {
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * Resolves the PHP binary used for MCP verification commands.
	 *
	 * an explicit DATAPHYRE_MCP_PHP_BINARY environment override wins;
	 * otherwise the current PHP_BINARY is used, keeping command execution
	 * predictable for local tooling.
	 */
	private function php_binary(): string {
		$configured=trim((string)(getenv('DATAPHYRE_MCP_PHP_BINARY') ?: ''));
		return $configured!=='' ? $configured : PHP_BINARY;
	}

	/**
	 * Runs a bounded local command for verification helpers.
	 *
	 * commands execute from the repository root, stdout/stderr are
	 * captured from pipes, timeout terminates the process, and stderr is returned
	 * only when the caller explicitly opts in.
	 */
	private function run_command(array $command, int $timeout_ms, bool $include_stderr): array {
		$descriptor=[
			1=>['pipe', 'w'],
			2=>['pipe', 'w'],
		];
		$process=proc_open($command, $descriptor, $pipes, $this->root);
		if(!is_resource($process)){
			throw new RuntimeException('Unable to start command.');
		}
		$started=microtime(true);
		$stdout='';
		$stderr='';
		foreach($pipes as $pipe){
			stream_set_blocking($pipe, false);
		}
		while(true){
			$status=proc_get_status($process);
			$stdout.=stream_get_contents($pipes[1]);
			$stderr.=stream_get_contents($pipes[2]);
			if(!$status['running']){
				break;
			}
			if((microtime(true)-$started) * 1000>$timeout_ms){
				proc_terminate($process);
				throw new RuntimeException('Command timed out.');
			}
			usleep(10000);
		}
		$exit=proc_close($process);
		$stdout=$this->redact_sensitive_text(trim($stdout));
		$stderr=$include_stderr ? $this->redact_sensitive_text(trim($stderr)) : '';
		return [
			'exit_code'=>$exit,
			'stdout'=>$stdout,
			'stderr'=>$stderr,
			'redacted'=>true,
			'redaction_policy'=>'credential, signed URL, tenant/customer/product, and machine-local path patterns are redacted from command output.',
		];
	}


}
