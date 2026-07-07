<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

global $dataphyre_datadoc_bootstrapping;

if(class_exists(__NAMESPACE__.'\datadoc', false)){
	return;
}
if(($dataphyre_datadoc_bootstrapping ?? false)===true){
	return;
}
$dataphyre_datadoc_bootstrapping=true;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

if(\function_exists('dp_module_required')!==true){
	$helper_candidates=[];
	if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
		$helper_candidates[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/core/kernel/helper_functions.php';
	}
	$helper_candidates[]=rtrim(dirname(__DIR__, 2), '/\\').'/core/kernel/helper_functions.php';
	foreach(array_unique($helper_candidates) as $helper_candidate){
		if(is_file($helper_candidate)){
			require_once($helper_candidate);
			if(\function_exists('dp_module_required')===true){
				break;
			}
		}
	}
}
if(\function_exists('dp_module_required')!==true){
	throw new \RuntimeException('DataDoc requires Flightdeck, but Dataphyre module dependency helpers are unavailable.');
}

\dp_module_required('datadoc', 'flightdeck');
\dp_define_module_config('datadoc', 'DP_DATADOC_CFG');

if(\function_exists(__NAMESPACE__.'\datadoc_register_sql_tables')!==true){
	/**
	 * Registers Datadoc SQL table definitions with the SQL module.
	 *
	 * Defines `datadoc.projects`, `dataphyre.datadoc_data`, and `dataphyre.datadoc_files` from `datadoc.tables.php` when the SQL table-definition registry is available. Safe to call more than once during module bootstrap.
	 */
	function datadoc_register_sql_tables(): void {
		if(\function_exists('sql_define_table')!==true){
			return;
		}
		\sql_define_table('datadoc.projects', __DIR__.'/datadoc.tables.php', 'projects');
		\sql_define_table('dataphyre.datadoc_data', __DIR__.'/datadoc.tables.php', 'data');
		\sql_define_table('dataphyre.datadoc_files', __DIR__.'/datadoc.tables.php', 'files');
	}
}
$GLOBALS['dataphyre_deferred_sql_table_definitions']['datadoc']=__NAMESPACE__.'\datadoc_register_sql_tables';
\dataphyre\datadoc_register_sql_tables();

if(class_exists('\dataphyre\core', false)!==true){
	$core_kernel_candidates=[];
	if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
		$core_kernel_candidates[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/core/kernel/';
	}
	$core_kernel_candidates[]=rtrim(dirname(__DIR__, 2), '/\\').'/core/kernel/';
	foreach(array_unique($core_kernel_candidates) as $core_kernel_candidate){
		if(is_dir($core_kernel_candidate)){
			foreach(['core.global.php', 'helper_functions.php', 'language_additions.php', 'core_functions.php'] as $core_kernel_file){
				$core_file=rtrim($core_kernel_candidate, '/\\').'/'.$core_kernel_file;
				if(is_file($core_file)){
					require_once($core_file);
				}
			}
			if(class_exists('\dataphyre\core', false)===true){
				break;
			}
		}
	}
}
if(class_exists('\dataphyre\core', false)!==true){
	throw new \RuntimeException('DataDoc configuration requires Dataphyre Core.');
}

if(file_exists($filepath=ROOTPATH['common_dataphyre']."config/datadoc.php")){
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre']."config/datadoc.php")){
	require_once($filepath);
}

require_once(__DIR__."/tokenizer.php");
require_once(__DIR__."/highlighter.php");

if(defined('RUN_MODE') && RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/datadoc.diagnostic.php');
}

if(class_exists(__NAMESPACE__.'\datadoc', false)!==true){

/**
 * Runtime facade for Datadoc projects, authentication context, and browser-facing document rendering.
 *
 * Datadoc indexes PHP source into `dataphyre.datadoc_data`, tracks file state in `dataphyre.datadoc_files`, stores projects in `datadoc.projects`, renders Dynadoc and Manudoc surfaces, and delegates browser authentication to Flightdeck.
 */
class datadoc{

	protected static $flightdeck_auth_loaded=null;
	private static string $last_error='';
	private static bool $index_storage_ready=false;

	/**
	 * Returns the last Datadoc runtime error message.
	 *
	 * Mutation and indexing helpers set this message before returning `false` so UI and diagnostics can report a caller-readable failure reason.
	 */
	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * Stores a Datadoc runtime failure and returns a false sentinel.
	 *
	 * Callers use this helper when an indexing or mutation operation needs to
	 * report a readable error while preserving the older boolean-return API.
	 *
	 * @param string $message Error message exposed through last_error().
	 * @return bool Always false.
	 */
	private static function fail(string $message): bool {
		self::$last_error=$message;
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=$message, $S='warning');
		return false;
	}

	/**
	 * Prepares DataDoc index tables and repairs legacy file uniqueness.
	 *
	 * Older diagnostic setups created `dataphyre.datadoc_files` with uniqueness on
	 * `filepath` alone. Project-scoped indexing requires the same file to be
	 * tracked independently for each project, so PostgreSQL storage is migrated
	 * to a `filepath, project` unique index before discovery starts.
	 */
	private static function ensure_index_storage(): bool {
		if(self::$index_storage_ready===true){
			return true;
		}
		if(class_exists('\dataphyre\sql')!==true || function_exists('sql_query')!==true){
			return self::fail('DataDoc index storage is unavailable because the SQL module is not loaded.');
		}
		foreach(['dataphyre.datadoc_data', 'dataphyre.datadoc_files'] as $table){
			if(\dataphyre\sql::hydrate_table_definition($table)!==true){
				$error=\dataphyre\sql::last_query_error();
				$detail=is_array($error) ? trim((string)($error['message'] ?? '')) : '';
				return self::fail('DataDoc index storage could not be prepared'.($detail!=='' ? ': '.$detail : '.'));
			}
		}
		$repairs=[
			[
				'mysql'=>'SELECT 1',
				'postgresql'=>'ALTER TABLE dataphyre.datadoc_data DROP CONSTRAINT IF EXISTS datadoc_data_checksum_key',
				'sqlite'=>'SELECT 1',
			],
			[
				'mysql'=>'SELECT 1',
				'postgresql'=>'CREATE UNIQUE INDEX IF NOT EXISTS uniq_datadoc_checksum_project ON dataphyre.datadoc_data(checksum, project)',
				'sqlite'=>'CREATE UNIQUE INDEX IF NOT EXISTS uniq_datadoc_checksum_project ON "dataphyre.datadoc_data"(checksum, project)',
			],
			[
				'mysql'=>'SELECT 1',
				'postgresql'=>'ALTER TABLE dataphyre.datadoc_files DROP CONSTRAINT IF EXISTS datadoc_files_filepath_key',
				'sqlite'=>'SELECT 1',
			],
			[
				'mysql'=>'SELECT 1',
				'postgresql'=>'CREATE UNIQUE INDEX IF NOT EXISTS uniq_datadoc_file_project ON dataphyre.datadoc_files(filepath, project)',
				'sqlite'=>'CREATE UNIQUE INDEX IF NOT EXISTS uniq_datadoc_file_project ON "dataphyre.datadoc_files"(filepath, project)',
			],
		];
		foreach($repairs as $query){
			if(sql_query($query, null, false, false, false)===false){
				$error=\dataphyre\sql::last_query_error();
				$detail=is_array($error) ? trim((string)($error['message'] ?? '')) : '';
				return self::fail('DataDoc file index storage could not be repaired'.($detail!=='' ? ': '.$detail : '.'));
			}
		}
		self::$index_storage_ready=true;
		return true;
	}

	/**
	 * Returns the Flightdeck-hosted Datadoc base URL for the current request.
	 *
	 * Uses Dataphyre Core URL detection and appends `/dataphyre/datadoc`; callers should not use this for filesystem paths.
	 */
	protected static function datadoc_base_url(): string {
		return rtrim(\dataphyre\core::url_self(), '/').'/dataphyre/datadoc';
	}

	/**
	 * Returns the Datadoc project index URL for the current request.
	 */
	public static function index_url(): string {
		return self::datadoc_base_url();
	}

	/**
	 * Builds a URL for a project-scoped Datadoc browser surface.
	 *
	 * @param non-empty-string $project Datadoc project key stored in `datadoc.projects`.
	 * @param string $suffix Optional route suffix such as `dynadoc`, `settings`, or `manudoc/...`.
	 */
	protected static function project_url(string $project, string $suffix=''): string {
		$url=self::datadoc_base_url().'/'.rawurlencode($project);
		if($suffix!==''){
			$url.='/'.ltrim($suffix, '/');
		}
		return $url;
	}

	/**
	 * Converts a normalized Manudoc path into route-safe URL segments.
	 *
	 * Each segment is raw-url-encoded and empty path parts are discarded.
	 */
	protected static function manual_path_to_route(string $path): string {
		$segments=array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!=='');
		return implode('/', array_map('rawurlencode', $segments));
	}

	/**
	 * Normalizes a Manudoc document path or path segment list.
	 *
	 * Backslashes become forward slashes, duplicate slashes collapse, and leading/trailing slashes are removed so filesystem-backed Manudoc records have stable keys.
	 *
	 * @param string|list<string> $path Manual document path or segment list.
	 * @return string Normalized relative Manudoc path.
	 */
	public static function normalize_manual_path(string|array $path): string {
		if(is_array($path)){
			$path=implode('/', $path);
		}
		$path=str_replace('\\', '/', trim($path, '/'));
		$path=preg_replace('#/+#', '/', $path);
		return $path ?? '';
	}

	/**
	 * Normalizes a filesystem path for Datadoc project comparisons.
	 *
	 * This does not resolve symlinks; it only makes separators and repeated slashes predictable for matching indexed file paths.
	 */
	protected static function normalize_filesystem_path(string $path): string {
		$path=str_replace('\\', '/', $path);
		return preg_replace('#/+#', '/', $path) ?? $path;
	}

	/**
	 * Loads one Datadoc project row by project name.
	 *
	 * Reads `datadoc.projects` and returns `null` when the project is unknown.
	 *
	 * @param non-empty-string $name Project key.
	 * @return array{id:mixed,name:string,title?:string,path?:string}|null
	 */
	public static function get_project(string $name): ?array {
		$project_rows=sql_select(
			$S='*',
			$L='datadoc.projects',
			$P='WHERE name = ?',
			$V=[$name],
			$F=true
		);
		if(!is_array($project_rows) || empty($project_rows)){
			return null;
		}
		return $project_rows[0];
	}

	/**
	 * Loads the Flightdeck authentication helper required by Datadoc UI access checks.
	 *
	 * Reads the Flightdeck auth PHP file from the shared runtime path if the auth class is not already loaded.
	 */
	protected static function ensure_flightdeck_auth_loaded(): bool {
		if(self::$flightdeck_auth_loaded!==null){
			return self::$flightdeck_auth_loaded;
		}
		if(class_exists('\dataphyre_flightdeck_auth', false)){
			return self::$flightdeck_auth_loaded=true;
		}
		$auth_file=ROOTPATH['common_dataphyre_runtime'].'modules/flightdeck/kernel/auth.php';
		if(is_file($auth_file)){
			require_once($auth_file);
		}
		return self::$flightdeck_auth_loaded=class_exists('\dataphyre_flightdeck_auth', false);
	}

	/**
	 * Reports whether the removed standalone Datadoc password flow is enabled.
	 *
	 * Always returns `false`; browser access is governed by Flightdeck authentication.
	 */
	public static function legacy_password_enabled(): bool {
		return false;
	}

	/**
	 * Returns the active Datadoc browser authentication context.
	 *
	 * Delegates to Flightdeck auth and returns a UI-friendly shape describing login state, auth source, logout availability, and display label.
	 *
	 * @return array{logged_in:bool,source:?string,auth_type:?string,can_logout:bool,label:string}
	 */
	public static function auth_context(): array {
		if(
			self::ensure_flightdeck_auth_loaded()===true
			&& \dataphyre_flightdeck_auth::authenticated()===true
		){
			return [
				'logged_in'=>true,
				'source'=>'flightdeck',
				'auth_type'=>'flightdeck',
				'can_logout'=>true,
				'label'=>'Flightdeck console',
			];
		}
		return [
			'logged_in'=>false,
			'source'=>null,
			'auth_type'=>null,
			'can_logout'=>false,
			'label'=>null,
		];
	}

	/**
	 * Reports whether the current browser context is authenticated through Flightdeck.
	 */
	public static function logged_in(): bool {
		return self::auth_context()['logged_in']===true;
	}

	/**
	 * Logs the current Datadoc browser session out through Flightdeck.
	 *
	 * Returns `false` when the Flightdeck auth helper cannot be loaded.
	 */
	public static function logout(): bool {
		unset($_SESSION['dp_datadoc_attempts']);
		unset($_SESSION['dp_datadoc_logged_in']);
		if(self::ensure_flightdeck_auth_loaded()===true){
			$was_logged_in=\dataphyre_flightdeck_auth::authenticated();
			\dataphyre_flightdeck_auth::logout();
			return $was_logged_in;
		}
		return false;
	}

	/**
	 * Handles the removed standalone Datadoc login endpoint.
	 *
	 * The password argument is ignored. Datadoc no longer authenticates independently and returns the current Flightdeck login state instead.
	 *
	 * @deprecated Datadoc browser access is Flightdeck-only.
	 */
	public static function login($password){
		if(self::ensure_flightdeck_auth_loaded()===true){
			return \dataphyre_flightdeck_auth::login((string)$password);
		}
		return false;
	}

	/**
	 * Renders one Dynadoc indexed source record as HTML.
	 *
	 * Highlights PHP content, linkifies references inside the selected project, and emits PHPDoc summary/tag sections for records loaded from `dataphyre.datadoc_data`.
	 *
	 * @param string $project Datadoc project key used for generated Dynadoc links.
	 * @param array{id?:int|string,type?:string,namespace?:string,class?:string,function?:string,content?:string,file?:string,line?:int,summary?:string,phpdoc?:array<string,mixed>} $record Indexed Datadoc record row.
	 */
	public static function dynadoc_output_record($project, $record){
		$url_base=self::project_url($project['name'], '/dynadoc');
		$content='';
		if($record['type']==='variable'){
			$url=$url_base.'?'.http_build_query([
				'namespace'=>$record['namespace'] ?? '',
				'class'=>$record['class'] ?? '',
				'type'=>'variable',
				'content'=>$record['content'] ?? '',
			]);
			$content.='<a style="color:black" href="'.$url.'">$'.htmlspecialchars($record['content'] ?? '').'</a>';
		}
		elseif($record['type']==='function'){
			$url=$url_base.'?'.http_build_query([
				'namespace'=>$record['namespace'] ?? '',
				'class'=>$record['class'] ?? '',
				'type'=>'function',
				'function'=>$record['function'] ?? '',
			]);
			$content.='<a style="color:black" href="'.$url.'"><i class="fas fa-align-left"></i> '.htmlspecialchars($record['function'] ?? '').'()</a>';
		}
		elseif($record['type']==='namespace'){
			$url=$url_base.'?'.http_build_query([
				'namespace'=>$record['namespace'] ?? '',
				'type'=>'namespace',
			]);
			$content.='<a style="color:black" href="'.$url.'"><i class="fas fa-align-left"></i> '.htmlspecialchars($record['namespace'] ?? '').'</a>';
		}
		elseif($record['type']==='class'){
			$url=$url_base.'?'.http_build_query([
				'namespace'=>$record['namespace'] ?? '',
				'class'=>$record['class'] ?? '',
				'type'=>'class',
			]);
			$content.='<a style="color:black" href="'.$url.'"><span class="fd-scope-symbol-label"><span class="fd-scope-kind-pill">Class</span> <span class="fd-scope-symbol-name">'.htmlspecialchars($record['class'] ?? '').'</span></span></a>';
		}
		else
		{
			$content.=htmlspecialchars((string)($record['content'] ?? ''));
		}
		return $content;
	}

	/**
	 * Renders a nested Dynadoc navigation structure.
	 *
	 * Outputs HTML directly for namespace, class, and symbol branches using the current project and path context.
	 */
	public static function dynadoc_output_nested_structure($project, $data, $indentation=0, $current_path=[]){
		global $dynadoc_record;
		$namespace=$_GET['namespace'] ?? '';
		$class=$_GET['class'] ?? '';
		$type=$_GET['type'] ?? '';
		$function=$_GET['function'] ?? '';
		foreach($data as $key=>$value){
			$new_id=rand(0,999999999999999999);
			$should_expand='false';
			$collapse_class='';
			$new_current_path=array_merge($current_path, [$key]);
			$joined_path=implode('/', $new_current_path);
			if(is_array($value)){
				$matching=false;
				if($joined_path==="$namespace/$class/$type/$function"){
					$matching=true;
				}
				if($indentation===0 && isset($dynadoc_record)){
					$matching=true;
				}
				if($matching){
					$should_expand='true';
					$collapse_class='show';
				}
				if(isset($value['id'])){
					echo "<div class='menu-item'>";
					echo self::dynadoc_output_record($project, $value);
					echo "</div>";
				}
				else
				{
					if(!empty($key)){
						echo "<div class='menu-item' style='padding-left: ".($indentation*8)."px;'>";
						echo "<a class='collapsed' role='button' data-toggle='collapse' href='#collapseData{$new_id}' aria-expanded='{$should_expand}'>";
						echo "<span style='color:black'>".$key."</span>";
						echo "</a>";
						echo "<div id='collapseData{$new_id}' class='panel-collapse collapse {$collapse_class}' role='tabpanel'>";
						self::dynadoc_output_nested_structure($project, $value, $indentation+1, $new_current_path);
						echo "</div></div>";
					}
				}
			}
		}
	}

	/**
	 * Inserts a value into a nested Dynadoc tree by path.
	 *
	 * Used by older menu-building code to materialize namespace/class/function structures for rendering.
	 *
	 * @param array<string|int,mixed> $arr Tree mutated in place.
	 */
	public static function dynadoc_insert_data(&$arr, $path, $value){
		$temp=&$arr;
		foreach($path as $segment){
			if(!isset($temp[$segment])){
				$temp[$segment]=[];
			}
			$temp=&$temp[$segment];
		}
		$temp[]=$value;
	}

	/**
	 * Normalizes Dynadoc or Manudoc menu path segments.
	 *
	 * String values are trimmed, backslashes are split into namespace segments, empty segments are discarded, and the resulting list is safe to use as a menu path.
	 *
	 * @param array<int,mixed> $path Raw path segments.
	 * @return list<string>
	 */
	protected static function normalize_menu_segments(array $path): array {
		$normalized=[];
		foreach($path as $segment){
			$segment=trim((string)$segment);
			if($segment===''){
				continue;
			}
			$segment=str_replace(['\\', '/'], '', $segment);
			if($segment===''){
				continue;
			}
			$normalized[]=$segment;
		}
		return $normalized;
	}

	/**
	 * Adds one indexed Dynadoc record to a nested menu tree.
	 *
	 * The tree is keyed by normalized namespace/class/function segments and stores records at leaf nodes for later sidebar rendering.
	 *
	 * @param array<string,array<string,mixed>> $tree Menu tree mutated in place.
	 * @param list<string> $path Normalized branch path.
	 * @param array{id?:int|string,type?:string,namespace?:string,class?:string,function?:string,content?:string,file?:string,line?:int,summary?:string,phpdoc?:array<string,mixed>} $record Datadoc data row.
	 */
	protected static function dynadoc_insert_menu_node(array &$tree, array $path, array $record): void {
		$current=&$tree;
		foreach($path as $node){
			$node_id=$node['id'];
			if(!isset($current[$node_id])){
				$current[$node_id]=[
					'node_type'=>'branch',
					'path_segment'=>$node_id,
					'label_html'=>$node['label_html'],
					'children'=>[],
				];
			}
			$current=&$current[$node_id]['children'];
		}
		$record_key='record:'.($record['id'] ?? md5(json_encode($record)));
		$current[$record_key]=[
			'node_type'=>'record',
			'record'=>$record,
		];
	}

	/**
	 * Builds the full Dynadoc menu tree for a project.
	 *
	 * Loads indexed namespace, class, function, line, and file records from `dataphyre.datadoc_data` and groups them into a sidebar tree.
	 *
	 * @return array<string,mixed> Nested menu tree.
	 */
	protected static function build_dynadoc_menu_tree(string $project): array {
		$tree=[];
		$data_records=sql_select(
			$S='*',
			$L='dataphyre.datadoc_data',
			$P='WHERE project = ? ORDER BY namespace, class, function, type, content',
			$V=[$project],
			$F=true
		);
		if(!is_array($data_records)){
			return [];
		}
		foreach($data_records as $record){
			if(in_array($record['type'], ['tracelog', 'sql_select', 'sql_delete', 'sql_update', 'sql_insert', 'sql_count'], true)){
				continue;
			}
			$path=[];
			if(!empty($record['namespace'])){
				$path[]=[
					'id'=>'group:namespaces',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Namespace(s)</i></b>',
				];
				foreach(explode('\\', (string)$record['namespace']) as $namespace_part){
					$namespace_part=trim($namespace_part);
					if($namespace_part===''){
						continue;
					}
					$path[]=[
						'id'=>'ns:'.$namespace_part,
						'label_html'=>'<b><i class="far fa-box"></i> '.htmlspecialchars($namespace_part, ENT_QUOTES, 'UTF-8').'</b>',
					];
				}
			}
			if(!empty($record['class'])){
				$path[]=[
					'id'=>'group:classes',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Class(es)</i></b>',
				];
				$path[]=[
					'id'=>'class:'.$record['class'],
					'label_html'=>'<b><i class="far fa-folder"></i> '.htmlspecialchars((string)$record['class'], ENT_QUOTES, 'UTF-8').'</b>',
				];
			}
			if(!empty($record['function'])){
				$path[]=[
					'id'=>'group:functions',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Function(s)</i></b>',
				];
				$path[]=[
					'id'=>'function:'.$record['function'],
					'label_html'=>'<b><i class="far fa-function"></i> '.htmlspecialchars((string)$record['function'], ENT_QUOTES, 'UTF-8').'()</b>',
				];
			}
			if(($record['type'] ?? '')==='variable'){
				$path[]=[
					'id'=>'group:variables',
					'label_html'=>'<b>$ <i>Variable(s)</i></b>',
				];
			}
			self::dynadoc_insert_menu_node($tree, $path, $record);
		}
		return $tree;
	}

	/**
	 * Returns a nested branch from a menu tree.
	 *
	 * Missing segments return an empty branch instead of throwing so UI routes can render empty folders safely.
	 */
	protected static function nested_menu_branch(array $tree, array $path): array {
		$current=$tree;
		foreach(self::normalize_menu_segments($path) as $segment){
			if(!isset($current[$segment]) || ($current[$segment]['node_type'] ?? null)!=='branch'){
				return [];
			}
			$current=$current[$segment]['children'] ?? [];
		}
		return is_array($current) ? $current : [];
	}

	/**
	 * Builds the HTML link for one Manudoc document record.
	 *
	 * Uses the project route and escaped title/path values for Flightdeck-rendered sidebars.
	 */
	protected static function manual_document_link_html(string $project, array $record): string {
		$document_path=(string)($record['path'] ?? $record['id'] ?? '');
		$document_url=self::project_url(
			$project,
			'/manudoc/'.self::manual_path_to_route($document_path)
		);
		$title=(string)($record['titles'] ?? $record['title'] ?? basename($document_path));
		return '<a style="color:black;" href="'.$document_url.'"><i class="far fa-file-alt"></i> '.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</a>';
	}

	/**
	 * Returns filesystem-backed Manudoc entries for a project branch.
	 *
	 * Reads the project root from `datadoc.projects`, scans the `documentation/` directory, and returns directories/documents visible below the requested branch.
	 *
	 * @return list<array{kind:string,name:string,path:string,title:string,url?:string}>
	 */
	public static function get_manudoc_branch(string $project, array $path=[]): array {
		$path=self::normalize_menu_segments($path);
		$root_realpath=self::manual_project_root($project);
		if($root_realpath===null){
			return [];
		}
		$target_dir=$root_realpath;
		if($path!==[]){
			$target_dir.=DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $path);
		}
		$target_realpath=realpath($target_dir);
		if(
			$target_realpath===false
			|| !is_dir($target_realpath)
			|| strncmp($target_realpath, $root_realpath, strlen($root_realpath))!==0
		){
			return [];
		}
		$directory_entries=[];
		$document_entries=[];
		foreach(scandir($target_realpath) ?: [] as $entry){
			if($entry==='.' || $entry==='..'){
				continue;
			}
			$full_path=$target_realpath.DIRECTORY_SEPARATOR.$entry;
			if(is_dir($full_path)){
				$directory_entries[]=$entry;
				continue;
			}
			if(str_ends_with($entry, '.md.json')){
				$document_entries[]=$entry;
			}
		}
		natcasesort($directory_entries);
		natcasesort($document_entries);
		$nodes=[];
		foreach($directory_entries as $entry){
			$nodes['dir:'.$entry]=[
				'node_type'=>'branch',
				'path_segment'=>$entry,
				'label_html'=>'<i class="far fa-folder"></i> '.htmlspecialchars($entry, ENT_QUOTES, 'UTF-8'),
				'children'=>[],
			];
		}
		foreach($document_entries as $entry){
			$document_id=substr($entry, 0, -8);
			$document_path=array_merge($path, [$document_id]);
			$document_contents=json_decode((string)file_get_contents($target_realpath.DIRECTORY_SEPARATOR.$entry), true);
			if(!is_array($document_contents)){
				$document_contents=[];
			}
			$nodes['doc:'.$document_id]=[
				'node_type'=>'manual_document',
				'record'=>[
					'path'=>implode('/', $document_path),
					'titles'=>$document_contents['title'] ?? $document_id,
				],
			];
		}
		return $nodes;
	}

	/**
	 * Returns Dynadoc menu entries for a project branch.
	 *
	 * Reads indexed code symbols from `dataphyre.datadoc_data` via `build_dynadoc_menu_tree()` and returns the requested nested branch.
	 */
	public static function get_dynadoc_branch(string $project, array $path=[]): array {
		return self::query_dynadoc_menu_branch($project, self::normalize_menu_segments($path));
	}

	/**
	 * Returns one Dynadoc menu branch without materializing the whole index.
	 *
	 * Large projects can contain enough records to exceed local memory limits if
	 * the full tree is assembled for every sidebar render. This method resolves
	 * the requested scope into SQL filters, emits only the immediate child
	 * branches, and adds a bounded set of direct records for leaf scopes.
	 *
	 * @param string $project DataDoc project key.
	 * @param list<string> $path Normalized menu path segments.
	 * @return array<string,mixed> Immediate menu nodes for the requested scope.
	 */
	protected static function query_dynadoc_menu_branch(string $project, array $path): array {
		$scope=self::dynadoc_menu_scope($path);
		$nodes=[];
		if($path===[]){
			foreach([
				'group:namespaces'=>['namespace IS NOT NULL AND namespace <> ?', [''], '<b><i class="fas fa-folder-tree"></i> <i>Namespace(s)</i></b>'],
				'group:classes'=>['class IS NOT NULL AND class <> ? AND (namespace IS NULL OR namespace = ?)', ['', ''], '<b><i class="fas fa-folder-tree"></i> <i>Global Class(es)</i></b>'],
				'group:functions'=>['type = ? AND function IS NOT NULL AND function <> ? AND (namespace IS NULL OR namespace = ?) AND (class IS NULL OR class = ?)', ['function', '', '', ''], '<b><i class="far fa-function"></i> <i>Global Function(s)</i></b>'],
				'group:variables'=>['type = ? AND content <> ? AND (namespace IS NULL OR namespace = ?) AND (class IS NULL OR class = ?) AND (function IS NULL OR function = ?)', ['variable', 'this', '', '', ''], '<b>$ <i>Global Variable(s)</i></b>'],
			] as $segment=>$definition){
				$count=sql_count('dataphyre.datadoc_data', 'WHERE project = ? AND '.$definition[0], array_merge([$project], $definition[1]));
				if((int)$count>0){
					$nodes[$segment]=[
						'node_type'=>'branch',
						'path_segment'=>$segment,
						'label_html'=>$definition[2],
						'children'=>[],
					];
				}
			}
			return $nodes;
		}
		if(($scope['group'] ?? '')==='group:namespaces'){
			if(($scope['namespace'] ?? '')!==''){
				self::append_dynadoc_record_group_nodes($nodes, $project, $scope);
			}
			self::append_dynadoc_namespace_nodes($nodes, $project, $scope);
			return $nodes;
		}
		if(($scope['group'] ?? '')==='group:variables'){
			self::append_dynadoc_variable_scope_nodes($nodes, $project, $scope);
			return $nodes;
		}
		self::append_dynadoc_record_group_nodes($nodes, $project, $scope);
		self::append_dynadoc_leaf_records($nodes, $project, $scope);
		return $nodes;
	}

	/**
	 * Decodes menu path segments into Dynadoc SQL filter scope.
	 *
	 * @param list<string> $path Normalized menu path segments.
	 * @return array{group:string,namespace:string,class:string,function:string}
	 */
	protected static function dynadoc_menu_scope(array $path): array {
		$scope=[
			'group'=>'',
			'bucket'=>'',
			'namespace'=>'',
			'class'=>'',
			'function'=>'',
		];
		$namespace_parts=[];
		foreach($path as $segment){
			if(str_starts_with($segment, 'group:')){
				$scope['group']=$segment;
				continue;
			}
			if(str_starts_with($segment, 'scope:')){
				$scope['bucket']=$segment;
				continue;
			}
			if(str_starts_with($segment, 'ns:')){
				$namespace_parts[]=substr($segment, 3);
				continue;
			}
			if(str_starts_with($segment, 'class:')){
				$scope['class']=substr($segment, 6);
				continue;
			}
			if(str_starts_with($segment, 'function:')){
				$scope['function']=substr($segment, 9);
			}
		}
		$scope['namespace']=implode('\\', $namespace_parts);
		return $scope;
	}

	/**
	 * Adds immediate namespace child branches for the current namespace scope.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{namespace:string} $scope Current namespace scope.
	 */
	protected static function append_dynadoc_namespace_nodes(array &$nodes, string $project, array $scope): void {
		$current_namespace=(string)($scope['namespace'] ?? '');
		$where='WHERE project = ? AND namespace IS NOT NULL AND namespace <> ?';
		$values=[$project, ''];
		if(!empty($scope['record_where']) && is_array($scope['record_values'] ?? null)){
			$where.=' AND '.(string)$scope['record_where'];
			$values=array_merge($values, $scope['record_values']);
		}
		if($current_namespace!==''){
			$where.=" AND namespace LIKE ? ESCAPE '|'";
			$values[]=$current_namespace.'\\%';
		}
		$rows=sql_select('DISTINCT namespace', 'dataphyre.datadoc_data', $where.' ORDER BY namespace LIMIT 1000', $values, true, false);
		if(!is_array($rows)){
			return;
		}
		$depth=$current_namespace==='' ? 0 : count(explode('\\', $current_namespace));
		foreach($rows as $row){
			$namespace=(string)($row['namespace'] ?? '');
			if($namespace===''){
				continue;
			}
			$parts=array_values(array_filter(explode('\\', $namespace), static fn($part)=>$part!==''));
			if(!isset($parts[$depth])){
				continue;
			}
			$part=$parts[$depth];
			if(self::is_dynadoc_menu_artifact_value($part)){
				continue;
			}
			$segment='ns:'.$part;
			if(isset($nodes[$segment])){
				continue;
			}
			$nodes[$segment]=[
				'node_type'=>'branch',
				'path_segment'=>$segment,
				'label_html'=>'<b><i class="far fa-box"></i> '.htmlspecialchars($part, ENT_QUOTES, 'UTF-8').'</b>',
				'children'=>[],
			];
		}
	}

	/**
	 * Adds class, function, and variable group branches visible at a scope.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{group:string,namespace:string,class:string,function:string} $scope Current Dynadoc scope.
	 */
	protected static function append_dynadoc_record_group_nodes(array &$nodes, string $project, array $scope): void {
		[$where, $values]=self::dynadoc_menu_where($project, $scope, false);
		if(($scope['group'] ?? '')==='group:classes'){
			if(($scope['class'] ?? '')!==''){
				return;
			}
			self::append_dynadoc_class_scope_nodes($nodes, $project, $scope);
			return;
		}
		if(($scope['group'] ?? '')==='group:functions'){
			if(($scope['function'] ?? '')!==''){
				return;
			}
			self::append_dynadoc_function_scope_nodes($nodes, $project, $scope);
			return;
		}
		if(($scope['class'] ?? '')===''){
			$count=sql_count('dataphyre.datadoc_data', $where.' AND class IS NOT NULL AND class <> ?', array_merge($values, ['']));
			if((int)$count>0){
				$nodes['group:classes']=[
					'node_type'=>'branch',
					'path_segment'=>'group:classes',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Class(es)</i></b>',
					'children'=>[],
				];
			}
		}
		if(($scope['function'] ?? '')===''){
			$function_where=$where.' AND type = ? AND function IS NOT NULL AND function <> ?';
			$function_values=array_merge($values, ['function', '']);
			if(($scope['class'] ?? '')===''){
				$function_where.=' AND (class IS NULL OR class = ?)';
				$function_values[]='';
			}
			$count=sql_count('dataphyre.datadoc_data', $function_where, $function_values);
			if((int)$count>0){
				$nodes['group:functions']=[
					'node_type'=>'branch',
					'path_segment'=>'group:functions',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Function(s)</i></b>',
					'children'=>[],
				];
			}
		}
	}

	/**
	 * Adds namespace-aware class branches for the class scope.
	 *
	 * The class root should remain traversable by namespace instead of flattening
	 * every namespaced class into one long list. Global classes still appear
	 * directly under `Class(es)`, while namespaced classes are exposed beneath
	 * their immediate namespace segment.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{namespace:string} $scope Current Dynadoc scope.
	 */
	protected static function append_dynadoc_class_scope_nodes(array &$nodes, string $project, array $scope): void {
		$current_namespace=(string)($scope['namespace'] ?? '');
		$where='WHERE project = ? AND class IS NOT NULL AND class <> ?';
		$values=[$project, ''];
		if($current_namespace===''){
			$where.=' AND (namespace IS NULL OR namespace = ?)';
			$values[]='';
		}
		else{
			$where.=' AND namespace = ?';
			$values[]=$current_namespace;
		}
		self::append_dynadoc_distinct_branch_nodes($nodes, $where, $values, 'class', 'class:', '<b><i class="far fa-folder"></i> %s</b>');
	}

	/**
	 * Adds namespace-aware function and method branches for the function scope.
	 *
	 * The root function scope only lists true global functions directly. Methods
	 * are reached through their namespace and class so magic methods and common
	 * helper names do not flatten into one misleading project-wide list.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{namespace:string,class:string,function:string} $scope Current Dynadoc scope.
	 */
	protected static function append_dynadoc_function_scope_nodes(array &$nodes, string $project, array $scope): void {
		$current_namespace=(string)($scope['namespace'] ?? '');
		$current_class=(string)($scope['class'] ?? '');
		$current_bucket=(string)($scope['bucket'] ?? '');
		if($current_namespace==='' && $current_class==='' && ($scope['function'] ?? '')==='' && $current_bucket===''){
			$where='WHERE project = ? AND type = ? AND function IS NOT NULL AND function <> ? AND (namespace IS NULL OR namespace = ?) AND (class IS NULL OR class = ?)';
			$values=[$project, 'function', '', '', ''];
			self::append_dynadoc_class_file_exclusion($where, $values, $project);
			self::append_dynadoc_class_function_name_exclusion($where, $values, $project);
			self::append_dynadoc_record_leaves($nodes, $where, $values, 'function, file, line');
			return;
		}
		if($current_bucket==='scope:namespaces' && $current_namespace==='' && $current_class===''){
			self::append_dynadoc_namespace_nodes($nodes, $project, [
				'namespace'=>$current_namespace,
				'record_where'=>'type = ? AND function IS NOT NULL AND function <> ?',
				'record_values'=>['function', ''],
			]);
			return;
		}
		$where='WHERE project = ? AND type = ? AND function IS NOT NULL AND function <> ?';
		$values=[$project, 'function', ''];
		if($current_namespace===''){
			$where.=' AND (namespace IS NULL OR namespace = ?)';
			$values[]='';
		}
		else{
			$where.=' AND namespace = ?';
			$values[]=$current_namespace;
		}
		if($current_bucket==='scope:classes' && $current_class===''){
			self::append_dynadoc_distinct_branch_nodes($nodes, $where.' AND class IS NOT NULL AND class <> ?', array_merge($values, ['']), 'class', 'class:', '<b><i class="far fa-folder"></i> %s</b>');
			return;
		}
		if($current_class===''){
			$where.=' AND (class IS NULL OR class = ?)';
			$values[]='';
			self::append_dynadoc_class_file_exclusion($where, $values, $project);
			self::append_dynadoc_class_function_name_exclusion($where, $values, $project);
		}
		else{
			$where.=' AND class = ?';
			$values[]=$current_class;
		}
		self::append_dynadoc_record_leaves($nodes, $where, $values, 'namespace, class, function, file, line');
	}

	/**
	 * Limits direct function branches to files that do not declare classes.
	 *
	 * Some older indexed rows can lose their class context. If a function row
	 * comes from a class-bearing file, it is safer to make it reachable through
	 * the class branch only instead of presenting it as a global function.
	 *
	 * @param list<mixed> $values SQL bind values mutated in place.
	 */
	protected static function append_dynadoc_class_file_exclusion(string &$where, array &$values, string $project): void {
		$where.=' AND file IS NOT NULL AND file NOT IN (SELECT class_file.file FROM dataphyre.datadoc_data class_file WHERE class_file.project = ? AND class_file.type = ? AND class_file.class IS NOT NULL AND class_file.class <> ? AND class_file.file IS NOT NULL)';
		array_push($values, $project, 'class', '');
	}

	/**
	 * Excludes function names that are also indexed as methods.
	 *
	 * This protects the root function scope from duplicated method rows left by
	 * older index passes while keeping those methods under their class branch.
	 *
	 * @param list<mixed> $values SQL bind values mutated in place.
	 */
	protected static function append_dynadoc_class_function_name_exclusion(string &$where, array &$values, string $project): void {
		$where.=' AND function NOT IN (SELECT method_row.function FROM dataphyre.datadoc_data method_row WHERE method_row.project = ? AND method_row.function IS NOT NULL AND method_row.function <> ? AND method_row.class IS NOT NULL AND method_row.class <> ?)';
		array_push($values, $project, '', '');
	}

	/**
	 * Adds namespace, class, and record leaves for variable scopes.
	 *
	 * Function-local variables are intentionally omitted from the sidebar because
	 * they are better understood from the owning function page and its PHPDoc.
	 * This branch keeps properties and globals traversable without leafing into
	 * implementation-local symbols.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{namespace:string,class:string,function:string} $scope Current Dynadoc scope.
	 */
	protected static function append_dynadoc_variable_scope_nodes(array &$nodes, string $project, array $scope): void {
		$current_namespace=(string)($scope['namespace'] ?? '');
		$current_class=(string)($scope['class'] ?? '');
		$current_function=(string)($scope['function'] ?? '');
		$current_bucket=(string)($scope['bucket'] ?? '');
		if($current_namespace!=='' || $current_class!=='' || $current_function!==''){
			return;
		}
		if($current_namespace==='' && $current_class==='' && $current_function==='' && $current_bucket===''){
			self::append_dynadoc_variable_leaf_records($nodes, 'WHERE project = ? AND type = ? AND content <> ? AND (namespace IS NULL OR namespace = ?) AND (class IS NULL OR class = ?) AND (function IS NULL OR function = ?)', [$project, 'variable', 'this', '', '', '']);
			return;
		}
		if($current_bucket==='scope:namespaces' && $current_namespace==='' && $current_class==='' && $current_function===''){
			self::append_dynadoc_namespace_nodes($nodes, $project, [
				'namespace'=>$current_namespace,
				'record_where'=>'type = ? AND (function IS NULL OR function = ?)',
				'record_values'=>['variable', ''],
			]);
			return;
		}
		if($current_bucket==='scope:namespaces' && $current_namespace!=='' && $current_class==='' && $current_function===''){
			self::append_dynadoc_namespace_nodes($nodes, $project, [
				'namespace'=>$current_namespace,
				'record_where'=>'type = ? AND (function IS NULL OR function = ?)',
				'record_values'=>['variable', ''],
			]);
			$count=sql_count('dataphyre.datadoc_data', 'WHERE project = ? AND namespace = ? AND type = ? AND class IS NOT NULL AND class <> ? AND content <> ? AND (function IS NULL OR function = ?)', [$project, $current_namespace, 'variable', '', 'this', '']);
			if((int)$count>0){
				$nodes['scope:classes']=[
					'node_type'=>'branch',
					'path_segment'=>'scope:classes',
					'label_html'=>'<b><i class="fas fa-folder-tree"></i> <i>Class(es)</i></b>',
					'children'=>[],
				];
			}
			return;
		}
		if($current_bucket==='scope:globals'){
			self::append_dynadoc_variable_leaf_records($nodes, 'WHERE project = ? AND type = ? AND content <> ? AND (namespace IS NULL OR namespace = ?) AND (class IS NULL OR class = ?) AND (function IS NULL OR function = ?)', [$project, 'variable', 'this', '', '', '']);
			return;
		}
		if($current_class==='' && $current_function===''){
			self::append_dynadoc_namespace_nodes($nodes, $project, [
				'namespace'=>$current_namespace,
				'record_where'=>'type = ? AND (function IS NULL OR function = ?)',
				'record_values'=>['variable', ''],
			]);
		}
		$where='WHERE project = ? AND type = ? AND content <> ? AND (function IS NULL OR function = ?)';
		$values=[$project, 'variable', 'this', ''];
		if($current_namespace===''){
			$where.=' AND (namespace IS NULL OR namespace = ?)';
			$values[]='';
		}
		else{
			$where.=' AND namespace = ?';
			$values[]=$current_namespace;
		}
		if($current_class===''){
				self::append_dynadoc_distinct_branch_nodes($nodes, $where.' AND class IS NOT NULL AND class <> ?', array_merge($values, ['']), 'class', 'class:', '<b><i class="far fa-folder"></i> %s</b>');
		}
		else{
			$where.=' AND class = ?';
			$values[]=$current_class;
		}
		if($current_bucket==='scope:classes' && $current_class===''){
			self::append_dynadoc_distinct_branch_nodes($nodes, $where.' AND class IS NOT NULL AND class <> ?', array_merge($values, ['']), 'class', 'class:', '<b><i class="far fa-folder"></i> %s</b>');
			return;
		}
		$leaf_where=$where;
		$leaf_values=$values;
		if($current_class===''){
			$leaf_where.=' AND (class IS NULL OR class = ?)';
			$leaf_values[]='';
		}
		self::append_dynadoc_variable_leaf_records($nodes, $leaf_where, $leaf_values);
	}

	/**
	 * Adds variable records as leaves for an exact variable scope.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param list<mixed> $values SQL bind values.
	 */
	protected static function append_dynadoc_variable_leaf_records(array &$nodes, string $where, array $values): void {
		self::append_dynadoc_record_leaves($nodes, $where, $values, 'namespace, class, content, file, line');
	}

	/**
	 * Adds indexed records as terminal links under the current scope.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param list<mixed> $values SQL bind values.
	 */
	protected static function append_dynadoc_record_leaves(array &$nodes, string $where, array $values, string $order_by): void {
		$rows=sql_select('*', 'dataphyre.datadoc_data', $where.' ORDER BY '.$order_by.' LIMIT 100', $values, true, false);
		if(!is_array($rows)){
			return;
		}
		foreach($rows as $record){
			if(!is_array($record)){
				continue;
			}
			$record_key='record:'.($record['id'] ?? md5(json_encode($record)));
			$nodes[$record_key]=[
				'node_type'=>'record',
				'record'=>$record,
			];
		}
	}

	/**
	 * Adds distinct value branches for class or function scopes.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param list<mixed> $values SQL bind values.
	 */
	protected static function append_dynadoc_distinct_branch_nodes(array &$nodes, string $where, array $values, string $column, string $prefix, string $label_template): void {
		$rows=sql_select('DISTINCT '.$column, 'dataphyre.datadoc_data', $where.' ORDER BY '.$column.' LIMIT 500', $values, true, false);
		if(!is_array($rows)){
			return;
		}
		foreach($rows as $row){
			$value=(string)($row[$column] ?? '');
			if($value===''){
				continue;
			}
			if(self::is_dynadoc_menu_artifact_value($value)){
				continue;
			}
			$segment=$prefix.$value;
			$nodes[$segment]=[
				'node_type'=>'branch',
				'path_segment'=>$segment,
				'label_html'=>sprintf($label_template, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')),
				'children'=>[],
			];
		}
	}

	/**
	 * Adds bounded record leaves when the requested scope is specific enough.
	 *
	 * @param array<string,mixed> $nodes Menu nodes mutated in place.
	 * @param array{group:string,namespace:string,class:string,function:string} $scope Current Dynadoc scope.
	 */
	protected static function append_dynadoc_leaf_records(array &$nodes, string $project, array $scope): void {
		$group=(string)($scope['group'] ?? '');
		if(!in_array($group, ['group:namespaces', 'group:variables', 'group:functions'], true) && ($scope['class'] ?? '')==='' && ($scope['function'] ?? '')===''){
			return;
		}
		if($group==='group:namespaces' && ($scope['namespace'] ?? '')===''){
			return;
		}
		if($group==='group:functions' && ($scope['function'] ?? '')===''){
			return;
		}
		[$where, $values]=self::dynadoc_menu_where($project, $scope, true);
		if($group==='group:functions' && ($scope['function'] ?? '')!==''){
			if(($scope['namespace'] ?? '')===''){
				$where.=' AND (namespace IS NULL OR namespace = ?)';
				$values[]='';
			}
			if(($scope['class'] ?? '')===''){
				$where.=' AND (class IS NULL OR class = ?)';
				$values[]='';
				self::append_dynadoc_class_file_exclusion($where, $values, $project);
				self::append_dynadoc_class_function_name_exclusion($where, $values, $project);
			}
		}
		$where.=' AND type IN (?, ?, ?)';
		array_push($values, 'namespace', 'class', 'function');
		$rows=sql_select('*', 'dataphyre.datadoc_data', $where.' ORDER BY namespace, class, function, type, content LIMIT 100', $values, true, false);
		if(!is_array($rows)){
			return;
		}
		foreach($rows as $record){
			if(!is_array($record)){
				continue;
			}
			if(
				self::is_dynadoc_menu_artifact_value((string)($record['namespace'] ?? ''))
				|| self::is_dynadoc_menu_artifact_value((string)($record['class'] ?? ''))
				|| self::is_dynadoc_menu_artifact_value((string)($record['function'] ?? ''))
			){
				continue;
			}
			$record_key='record:'.($record['id'] ?? md5(json_encode($record)));
			$nodes[$record_key]=[
				'node_type'=>'record',
				'record'=>$record,
			];
		}
	}

	/**
	 * Builds the SQL predicate for a Dynadoc menu scope.
	 *
	 * @param array{group:string,namespace:string,class:string,function:string} $scope Current Dynadoc scope.
	 * @return array{0:string,1:list<mixed>} SQL where clause and bound values.
	 */
	protected static function dynadoc_menu_where(string $project, array $scope, bool $include_group_filter): array {
		$where='WHERE project = ?';
		$values=[$project];
		if(($scope['namespace'] ?? '')!==''){
			$where.=' AND namespace = ?';
			$values[]=(string)$scope['namespace'];
		}
		if(($scope['class'] ?? '')!==''){
			$where.=' AND class = ?';
			$values[]=(string)$scope['class'];
		}
		if(($scope['function'] ?? '')!==''){
			$where.=' AND function = ?';
			$values[]=(string)$scope['function'];
		}
		if($include_group_filter && ($scope['group'] ?? '')==='group:variables'){
			$where.=' AND type = ?';
			$values[]='variable';
		}
		return [$where, $values];
	}

	/**
	 * Detects SQL/tokenizer artifacts that should not become sidebar scopes.
	 *
	 * These values come from malformed legacy token records rather than PHP
	 * namespaces or symbols. Filtering them here keeps the tree navigable even
	 * before old rows are pruned from storage.
	 */
	protected static function is_dynadoc_menu_artifact_value(string $value): bool {
		$value=strtoupper(trim($value));
		return in_array($value, [
			'BIGINT',
			'BOOLEAN',
			'DATE',
			'DATETIME',
			'DECIMAL',
			'DOUBLE',
			'FLOAT',
			'INT',
			'INTEGER',
			'JSON',
			'JSONB',
			'NUMERIC',
			'REAL',
			'SERIAL',
			'TEXT',
			'TIME',
			'TIMESTAMP',
			'UUID',
			'VARCHAR',
		], true);
	}

	/**
	 * Returns either a Dynadoc or Manudoc branch for the Datadoc sidebar.
	 *
	 * @param string $kind Either `dynadoc` or `manudoc`.
	 */
	public static function get_menu_branch(string $project, string $kind, array $path=[]): array {
		$kind=strtolower(trim($kind));
		if($kind==='manual'){
			return self::get_manudoc_branch($project, $path);
		}
		if($kind==='dynamic'){
			return self::get_dynadoc_branch($project, $path);
		}
		return [];
	}

	/**
	 * Builds a stable DOM id for a collapsible Datadoc sidebar branch.
	 *
	 * The id includes project, menu kind, and path hash to avoid collisions across mixed Dynadoc/Manudoc trees.
	 */
	protected static function menu_collapse_id(string $project, string $kind, array $path): string {
		return 'collapseDataDoc'.substr(hash('sha256', $project.'|'.$kind.'|'.json_encode($path)), 0, 16);
	}

	/**
	 * Outputs nested Datadoc sidebar nodes as HTML.
	 *
	 * Renders links and collapse controls directly; callers must already have loaded the proper project and branch data.
	 */
	public static function render_procedural_menu_nodes(string $project, string $kind, array $nodes, array $path=[], int $indentation=0): void {
		$nodes=is_array($nodes) ? $nodes : [];
		if($nodes===[]){
			echo "<div class='menu-item' style='padding-left: ".(min($indentation, 4)*8)."px;'><span style='color:#777;'>No items</span></div>";
			return;
		}
		foreach($nodes as $node){
			$padding=min($indentation, 4) * 8;
			$node_type=$node['node_type'] ?? null;
			if($node_type==='branch'){
				$child_path=array_merge($path, [(string)$node['path_segment']]);
				$collapse_id=self::menu_collapse_id($project, $kind, $child_path);
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				echo "<a class='collapsed datadoc-menu-toggle' role='button' data-toggle='collapse' href='#{$collapse_id}' aria-expanded='false' data-datadoc-project='".htmlspecialchars($project, ENT_QUOTES, 'UTF-8')."' data-datadoc-kind='".htmlspecialchars($kind, ENT_QUOTES, 'UTF-8')."' data-datadoc-path='".htmlspecialchars(json_encode($child_path), ENT_QUOTES, 'UTF-8')."' data-datadoc-depth='".($indentation + 1)."'>";
				echo "<span style='color:black'>".($node['label_html'] ?? '')."</span>";
				echo "</a>";
				echo "<div id='{$collapse_id}' class='panel-collapse collapse datadoc-lazy-branch' role='tabpanel' data-datadoc-loaded='0'></div>";
				echo "</div>";
				continue;
			}
			if($node_type==='manual_document'){
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				echo self::manual_document_link_html($project, $node['record'] ?? []);
				echo "</div>";
				continue;
			}
			if($node_type==='record'){
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				echo self::dynadoc_output_record(['name'=>$project], $node['record'] ?? []);
				echo "</div>";
			}
		}
	}

	/**
	 * Outputs one Manudoc document record as HTML.
	 *
	 * Renders filesystem-backed manual documentation content for the Flightdeck Datadoc surface.
	 */
	public static function manudoc_output_record($record){
		$data_id=rand(0, 999999999999999999);
		$document_path=$record['path'] ?? $record['id'] ?? '';
		$document_url=self::project_url(
			$GLOBALS['project']['name'],
			'/manudoc/'.self::manual_path_to_route((string)$document_path)
		);
		echo '<div class="panel panel-default">';
		echo '<div class="panel-heading" id="headingData'.$data_id.'">';
		echo '<a class="collapsed" role="button" data-toggle="collapse" href="#collapseData'.$data_id.'" aria-expanded="false" aria-controls="collapseData'.$data_id.'">';
		echo '<span style="color:black; font-weight:normal;">';
		echo htmlspecialchars((string)($record['titles'] ?? $record['title'] ?? $document_path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		echo '</span>';
		echo '</a>';
		echo '</div>';
		echo '<div class="panel-body">';
		echo '<a href="'.htmlspecialchars($document_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">View Document</a>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Outputs a nested Manudoc filesystem structure as HTML.
	 *
	 * Used by legacy Manudoc views that render directory/document arrays directly.
	 */
	public static function manudoc_output_nested_structure_from_fs(array $data, int $indentation=0){
		foreach($data as $key=>$item){
			$padding=$indentation * 8;
			if($item['type']==='category'){
				$new_id=rand(0, 999999999999999999);
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				echo "<a class='collapsed' role='button' data-toggle='collapse' href='#collapse{$new_id}' aria-expanded='false'>";
				echo "<span style='color:black;'>".htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</span>";
				echo "</a>";
				echo "<div id='collapse{$new_id}' class='panel-collapse collapse' role='tabpanel'>";
				self::manudoc_output_nested_structure_from_fs($item['children'], $indentation + 1);
				echo "</div></div>";
			} elseif($item['type']==='document'){
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				self::manudoc_output_record($item['content']);
				echo "</div>";
			}
		}
	}

	/**
	 * Deletes a Manudoc document from a project documentation directory.
	 *
	 * Resolves the project from `datadoc.projects`, normalizes the requested relative path, and removes the matching filesystem document when it exists inside the project documentation root.
	 */
	public static function delete_manudoc(string $project, string $path): bool {
		$filepath=self::manual_document_filepath($project, $path);
		if($filepath!==null && file_exists($filepath)){
			unlink($filepath);
			return true;
		}
		return false;
	}

	/**
	 * Loads one Manudoc document from a project documentation directory.
	 *
	 * @return array{path:string,title:string,content:string,mtime:int}|null
	 */
	public static function get_manudoc(string $project, string $path): ?array {
		$filepath=self::manual_document_filepath($project, $path);
		if($filepath===null){
			return null;
		}
		if(!file_exists($filepath)) return null;
		$contents=file_get_contents($filepath);
		if($contents===false){
			return null;
		}
		$document=json_decode($contents, true);
		return is_array($document) ? $document : null;
	}

	/**
	 * Resolves a Manudoc document path inside a project's manual-document root.
	 *
	 * The returned file must already exist below `ROOTPATH['dataphyre']/doc/{project}/manudocs`.
	 * Dot segments, empty paths, missing roots, and symlink/relative traversal attempts return `null`.
	 */
	protected static function manual_document_filepath(string $project, string $path): ?string {
		$path=self::normalize_manual_path($path);
		if($project==='' || $path===''){
			return null;
		}
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){
				return null;
			}
		}
		$root=self::manual_project_root($project);
		if($root===null){
			return null;
		}
		$filepath=$root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path).'.md.json';
		$realpath=realpath($filepath);
		if($realpath===false || !is_file($realpath)){
			return null;
		}
		$root_prefix=rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		return strncmp($realpath, $root_prefix, strlen($root_prefix))===0 ? $realpath : null;
	}

	/**
	 * Resolves a project's Manudoc root beneath Dataphyre's documentation store.
	 *
	 * Project keys are limited to route-safe storage names before touching the
	 * filesystem. The resolved root must stay below `ROOTPATH['dataphyre']/doc`
	 * so project names cannot escape into sibling directories.
	 */
	protected static function manual_project_root(string $project): ?string {
		if(preg_match('/^[A-Za-z0-9_-]+$/', $project)!==1){
			return null;
		}
		$doc_root=realpath(ROOTPATH['dataphyre'].'doc');
		if($doc_root===false || !is_dir($doc_root)){
			return null;
		}
		$manual_root=realpath($doc_root.DIRECTORY_SEPARATOR.$project.DIRECTORY_SEPARATOR.'manudocs');
		if($manual_root===false || !is_dir($manual_root)){
			return null;
		}
		$doc_prefix=rtrim($doc_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		return strncmp($manual_root, $doc_prefix, strlen($doc_prefix))===0 ? $manual_root : null;
	}

	/**
	 * Scans a project documentation directory into a Manudoc tree.
	 *
	 * Reads markdown/manual documentation files from the project path recorded in `datadoc.projects`.
	 */
	public static function get_manudoc_structure(string $project): array {
		$root_realpath=self::manual_project_root($project);
		$structure=[];
		if($root_realpath===null) return [];
		$root_dir=self::normalize_filesystem_path($root_realpath).'/';
		$rii=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root_realpath, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($rii as $file){
			if(!$file->isFile() || !str_ends_with($file->getFilename(), '.md.json')) continue;
			$pathname=self::normalize_filesystem_path($file->getPathname());
			if(!str_starts_with($pathname, $root_dir)){
				continue;
			}
			$relative_path=substr($pathname, strlen($root_dir));
			$path_parts=explode('/', $relative_path);
			$doc_name=str_replace('.md.json', '', array_pop($path_parts));
			$current=&$structure;
			foreach($path_parts as $part){
				if(!isset($current[$part])){
					$current[$part]=['type'=>'category', 'children'=>[]];
				}
				$current=&$current[$part]['children'];
			}
			$partial=json_decode((string)file_get_contents($file->getPathname()), true);
			if(!is_array($partial)){
				continue;
			}
			$current[]=[
				'type'=>'document',
				'path'=>implode('/', array_merge($path_parts, [$doc_name])),
				'content'=>[
					'titles'=>$partial['title'] ?? $partial['titles'] ?? $doc_name,
					'id'=>implode('/', array_merge($path_parts, [$doc_name])),
					'path'=>implode('/', array_merge($path_parts, [$doc_name])),
				],
			];
		}
		return $structure;
	}

	/**
	 * Creates or updates a Datadoc project record.
	 *
	 * Writes `datadoc.projects` with a project key, display title, and filesystem root used by Dynadoc sync and Manudoc scans.
	 *
	 * @param string $name Project key. Empty input is rejected.
	 * @param string $title Display title; defaults to the project key when empty.
	 * @param string $path Project root path used for file discovery and manual docs.
	 */
	public static function create_project(string $name='', string $title='', string $path=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::$last_error='';
		$fields=[
			'name'=>$name,
			'title'=>$title,
			'path'=>$path,
		];
		if(class_exists('\dataphyre\sql')!==true){
			return self::fail('DataDoc storage is unavailable because the SQL module is not loaded.');
		}
		if(\dataphyre\sql::hydrate_table_definition('datadoc.projects')!==true){
			$error=\dataphyre\sql::last_query_error();
			$detail=is_array($error) ? trim((string)($error['message'] ?? '')) : '';
			return self::fail('DataDoc project storage could not be prepared'.($detail!=='' ? ': '.$detail : '.'));
		}
		if(self::ensure_index_storage()!==true){
			return self::fail(self::$last_error!=='' ? self::$last_error : 'DataDoc index storage could not be prepared.');
		}
		$result=sql_update(
			$L='datadoc.projects',
			$F=[
				'title'=>$title,
				'path'=>$path,
			],
			$P='WHERE name=?',
			$V=[$name]
		);
		if($result!==false && (int)$result>0){
			return true;
		}
		$result=sql_insert(
			$L='datadoc.projects',
			$F=$fields
		);
		if($result!==false){
			return true;
		}
		$result=sql_update(
			$L='datadoc.projects',
			$F=[
				'title'=>$title,
				'path'=>$path,
			],
			$P='WHERE name=?',
			$V=[$name]
		);
		if($result!==false){
			return true;
		}
		$error=\dataphyre\sql::last_query_error();
		$detail=is_array($error) ? trim((string)($error['message'] ?? '')) : '';
		return self::fail('DataDoc project could not be written'.($detail!=='' ? ': '.$detail : '.'));
	}

	/**
	 * Linkifies function references in highlighted Datadoc content.
	 *
	 * Uses the current project/class context to build Dynadoc links for user functions and php.net links for PHP built-ins.
	 *
	 * @return string|false Linkified HTML or `false` when processing fails.
	 */
	public static function reference_functions(string $content, string $current_project, string $current_class): string|false {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$pattern='/\b([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*|\$\w+)\b/';
		preg_match_all($pattern, $content, $matches);
		foreach($matches[0] as $detected_entity){
			$rows=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE (class=? OR function=? OR namespace=?) AND project=?',
				$V=[$current_class, $detected_entity, $detected_entity, $current_project],
				$F=true
			);
			if(!is_array($rows)) continue;
			foreach($rows as $row){
				$type=$row['type'] ?? 'function';
				$url=self::project_url($current_project, '/dynadoc?'.http_build_query([
					'type'=>$type,
					'namespace'=>$row['namespace'] ?? '',
					'class'=>$row['class'] ?? '',
					'function'=>$row['function'] ?? '',
					'content'=>$detected_entity
				]));
				$content=str_replace($detected_entity, "<a href=\"$url\">$detected_entity</a>", $content);
			}
		}
		return $content;
	}

	/**
	 * Discovers and registers all indexable files below a project directory.
	 *
	 * Walks the filesystem and writes rows to `dataphyre.datadoc_files`; use `discover_files_to_project()` for bounded request-time discovery.
	 */
	public static function add_files_to_project(string $dirpath, string $project=''): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$dirpath=self::normalize_filesystem_path($dirpath);
		if(is_dir($dirpath)){
			$files=scandir($dirpath);
			$success=true;
			foreach($files as $file){
				$filepath=$dirpath.'/'.$file;
				if($file=="." || $file=="..") continue;
				$success=self::add_files_to_project($filepath, $project) && $success;
			}
			return $success;
		}
		return self::add_file_to_project($dirpath, $project);
	}

	/**
	 * Discovers a bounded page of files for a Datadoc project.
	 *
	 * Registers at most `$limit` files after the optional cursor, skips common dependency/cache/build directories, and returns counters suitable for Flightdeck progress UI.
	 *
	 * @return array{registered:int,skipped:int,failed:int,scanned:int,last_cursor:string,done:bool,error:?string}
	 */
	public static function discover_files_to_project(string $dirpath, string $project='', int $limit=250, string $after=''): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		$dirpath=rtrim(self::normalize_filesystem_path($dirpath), '/');
		$after=self::normalize_filesystem_path($after);
		$stats=[
			'registered'=>0,
			'skipped'=>0,
			'failed'=>0,
			'scanned'=>0,
			'last_cursor'=>$after,
			'done'=>true,
			'error'=>null,
		];
		if($project==='' || !is_dir($dirpath)){
			$stats['error']='Invalid DataDoc project discovery request.';
			return $stats;
		}
		if(self::ensure_index_storage()!==true){
			$stats['error']=self::$last_error!=='' ? self::$last_error : 'DataDoc index storage could not be prepared.';
			return $stats;
		}
		self::prune_excluded_project_files($project);
		$limit=max(1, min(1000, $limit));
		self::discover_files_to_project_walk($dirpath, $project, $limit, $after, $stats);
		return $stats;
	}

	/**
	 * Recursively registers PHP files for a Datadoc project until the batch limit is reached.
	 *
	 * Directory traversal is sorted for cursor stability. The mutable stats array
	 * carries progress counters, the latest cursor, and whether more files remain.
	 *
	 * @param string $dirpath Directory currently being scanned.
	 * @param string $project Datadoc project name.
	 * @param int $limit Maximum files to scan in this batch.
	 * @param string $after Cursor path; files at or before it are ignored.
	 * @param array{scanned:int,registered:int,skipped:int,failed:int,last_cursor:string,has_more:bool,error?:string} $stats Mutable discovery counters and cursor state.
	 * @return bool True when traversal completed, false when the batch limit stopped traversal.
	 */
	private static function discover_files_to_project_walk(string $dirpath, string $project, int $limit, string $after, array &$stats): bool {
		$entries=scandir($dirpath);
		if(!is_array($entries)){
			$stats['failed']++;
			return true;
		}
		sort($entries, SORT_STRING);
		foreach($entries as $entry){
			if($entry==='.' || $entry==='..'){
				continue;
			}
			$filepath=self::normalize_filesystem_path($dirpath.'/'.$entry);
			if(is_dir($filepath)){
				if(self::should_skip_datadoc_directory($entry)){
					$stats['skipped']++;
					continue;
				}
				if(self::discover_files_to_project_walk($filepath, $project, $limit, $after, $stats)===false){
					return false;
				}
				continue;
			}
			if(!is_file($filepath) || !str_ends_with($filepath, '.php')){
				$stats['skipped']++;
				continue;
			}
			if(self::should_exclude_index_file($filepath)){
				$stats['skipped']++;
				continue;
			}
			if($after!=='' && strcmp($filepath, $after)<=0){
				continue;
			}
			$stats['scanned']++;
			$stats['last_cursor']=$filepath;
			if(self::register_file_to_project($filepath, $project)===true){
				$stats['registered']++;
			}
			else
			{
				$stats['failed']++;
			}
			if($stats['scanned']>=$limit){
				$stats['done']=false;
				return false;
			}
		}
		return true;
	}

	/**
	 * Determines whether project discovery should skip a directory name.
	 *
	 * Dependency, VCS, cache, and temporary directories are excluded to avoid
	 * indexing generated or third-party code into the current Datadoc project.
	 *
	 * @param string $directory Basename of the directory being considered.
	 * @return bool True when the directory should not be traversed.
	 */
	private static function should_skip_datadoc_directory(string $directory): bool {
		return in_array($directory, ['.git', '.hg', '.svn', 'node_modules', 'vendor', 'cache', 'logs', 'tmp', 'temp', 'unit_tests'], true);
	}

	/**
	 * Reports whether a source file is outside DataDoc's authored-runtime index scope.
	 *
	 * Generated client SDKs and test fixtures are intentionally excluded from
	 * project discovery and record views so searchable documentation stays
	 * focused on authored runtime behavior.
	 */
	public static function should_exclude_index_file(string $filepath): bool {
		$filepath='/'.trim(self::normalize_filesystem_path($filepath), '/');
		foreach(self::excluded_index_path_patterns() as $pattern){
			$needle=trim($pattern, '%');
			if($needle!=='' && str_contains($filepath, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns slash-normalized SQL LIKE patterns excluded from DataDoc indexes.
	 *
	 * @return list<string> Path fragments wrapped for SQL LIKE matching.
	 */
	private static function excluded_index_path_patterns(): array {
		return [
			'%/unit_tests/%',
			'%/common/dataphyre/runtime/modules/stripe/src/lib/%',
			'%/common/dataphyre/runtime/modules/'.'shopiro'.'_devapi/***REMOVED***/%',
			'%/common/dataphyre/runtime/modules/'.'cj'.'dropshipping/cj'.'dropshipping-client/%',
		];
	}

	/**
	 * Removes generated/test files and records from one project's index storage.
	 *
	 * Refresh and synchronization paths call this before presenting progress so
	 * legacy rows from previously broader scans do not remain in DataDoc counts,
	 * stale queues, or dynamic record searches.
	 *
	 * @param string $project DataDoc project key.
	 * @return int Number of delete operations that reported affected rows.
	 */
	public static function prune_excluded_project_files(string $project): int {
		if($project==='' || function_exists('sql_delete')!==true){
			return 0;
		}
		$removed=0;
		foreach(self::excluded_index_path_patterns() as $pattern){
			$files=sql_delete(
				$L='dataphyre.datadoc_files',
				$P='WHERE project=? AND filepath LIKE ?',
				$V=[$project, $pattern]
			);
			if(is_numeric($files)){
				$removed+=(int)$files;
			}
			$records=sql_delete(
				$L='dataphyre.datadoc_data',
				$P='WHERE project=? AND file LIKE ?',
				$V=[$project, $pattern]
			);
			if(is_numeric($records)){
				$removed+=(int)$records;
			}
		}
		foreach(self::invalid_dynamic_class_names() as $class_name){
			$records=sql_delete(
				$L='dataphyre.datadoc_data',
				$P='WHERE project=? AND type=? AND namespace=? AND class=?',
				$V=[$project, 'class', $class_name, $class_name]
			);
			if(is_numeric($records)){
				$removed+=(int)$records;
			}
		}
		return $removed;
	}

	/**
	 * Returns SQL type tokens previously misindexed from multi-line DDL strings.
	 *
	 * @return list<string> Uppercase SQL token names that are not PHP classes.
	 */
	private static function invalid_dynamic_class_names(): array {
		return ['BIGINT', 'INTEGER', 'INT', 'KEY', 'PRIMARY', 'SERIAL', 'TEXT', 'TIMESTAMP', 'UNIQUE', 'VARCHAR'];
	}

	/**
	 * Registers and immediately synchronizes one source file into Datadoc.
	 *
	 * Writes file state to `dataphyre.datadoc_files`, tokenizes the file, replaces existing indexed records, and writes new rows to `dataphyre.datadoc_data`.
	 */
	public static function add_file_to_project(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$filepath=self::normalize_filesystem_path($filepath);
		if(!file_exists($filepath) || !str_ends_with($filepath, '.php') || self::should_exclude_index_file($filepath)){
			return false;
		}
		if(self::register_file_to_project($filepath, $project)!==true){
			return false;
		}
		if(self::sync_file($filepath, $project)!==true){
			return false;
		}
		$current_checksum=md5_file($filepath);
		return self::update_project_file_state($filepath, $project, is_string($current_checksum) ? $current_checksum : '', false);
	}

	/**
	 * Registers one file path in a Datadoc project without tokenizing it.
	 *
	 * Stores normalized path, checksum, stale state, and project key in `dataphyre.datadoc_files` for later batch sync.
	 */
	public static function register_file_to_project(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		$filepath=self::normalize_filesystem_path($filepath);
		if($project==='' || !file_exists($filepath) || !str_ends_with($filepath, '.php') || self::should_exclude_index_file($filepath)){
			return false;
		}
		$current_checksum=md5_file($filepath);
		$current_checksum=is_string($current_checksum) ? $current_checksum : '';
		$existing=sql_select(
			$S='checksum,last_synced,is_stale',
			$L='dataphyre.datadoc_files',
			$P='WHERE filepath=? AND project=?',
			$V=[$filepath, $project],
			$F=false,
			$C=false
		);
		if(is_array($existing) && $existing!==[]){
			$stored_checksum=(string)($existing['checksum'] ?? '');
			$fields=[
				'checksum'=>$current_checksum,
				'is_stale'=>self::database_bool($existing['is_stale'] ?? false) || $stored_checksum!==$current_checksum || empty($existing['last_synced']),
			];
			if($stored_checksum!==$current_checksum){
				$fields['last_synced']=null;
			}
			$result=sql_update(
				$L='dataphyre.datadoc_files',
				$F=$fields,
				$P='WHERE filepath=? AND project=?',
				$V=[$filepath, $project]
			);
			return $result!==false;
		}
		$result=sql_insert(
			$L='dataphyre.datadoc_files',
			$F=[
				'filepath'=>$filepath,
				'checksum'=>$current_checksum,
				'project'=>$project,
				'last_synced'=>null,
				'is_stale'=>true,
			]
		);
		if($result!==false){
			return true;
		}
		$result=sql_update(
			$L='dataphyre.datadoc_files',
			$F=[
				'checksum'=>$current_checksum,
				'last_synced'=>null,
				'is_stale'=>true,
			],
			$P='WHERE filepath=? AND project=?',
			$V=[$filepath, $project]
		);
		return $result!==false;
	}

	/**
	 * Deletes Datadoc index and file-state rows for a project file.
	 *
	 * Removes matching rows from `dataphyre.datadoc_data` and `dataphyre.datadoc_files`.
	 */
	public static function delete_file(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$filepath=self::normalize_filesystem_path($filepath);
		if(is_dir($filepath) || str_ends_with($filepath, '/')){
			$filepath=rtrim($filepath, '/').'/';
			$like_filepath=$filepath.'%';
			$success1=sql_delete(
				$L='dataphyre.datadoc_files',
				$P='WHERE filepath LIKE ? AND project=?',
				$V=[$like_filepath, $project]
			);
			$success2=sql_delete(
				$L='dataphyre.datadoc_data',
				$P='WHERE file LIKE ? AND project=?',
				$V=[$like_filepath, $project]
			);
		}
		else
		{
			$success1=sql_delete(
				$L='dataphyre.datadoc_files',
				$P='WHERE filepath=? AND project=?',
				$V=[$filepath, $project]
			);
			$success2=sql_delete(
				$L='dataphyre.datadoc_data',
				$P='WHERE file=? AND project=?',
				$V=[$filepath, $project]
			);
		}
		return $success1!==false && $success2!==false;
	}

	/**
	 * Returns file-state rows that need Datadoc synchronization.
	 *
	 * Reads `dataphyre.datadoc_files` for rows marked stale or whose checksums no longer match the current filesystem content.
	 */
	public static function get_stale_files(string $project=''): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$result=sql_select(
			$S='filepath',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=? AND is_stale=?',
			$V=[$project, true],
			$F=true,
			$C=false
		);
		if(!is_array($result)){
			return [];
		}
		return array_column($result, 'filepath');
	}

	/**
	 * Synchronizes all stale files for a Datadoc project.
	 *
	 * Tokenizes each stale file and rewrites project/file records in `dataphyre.datadoc_data`. This can be expensive on large projects; browser flows should prefer `sync_project_batch()`.
	 */
	public static function sync_all_files(string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::prune_excluded_project_files($project);
		$result=sql_select(
			$S='*',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=?',
			$V=[$project],
			$F=true
		);
		if($result===false){
			return false;
		}
		if(!is_array($result) || empty($result)){
			return true;
		}
		foreach($result as $row){
			$filepath=$row['filepath'];
			$stored_checksum=$row['checksum'];
			$is_stale=!empty($row['is_stale']) && !in_array($row['is_stale'], [false, 0, '0', 'f', 'false'], true);
			if(file_exists($filepath)){
				$current_checksum=md5_file($filepath);
				if($is_stale || $current_checksum!==$stored_checksum){
					if(self::sync_file($filepath, $project)){
						sql_update(
							$L='dataphyre.datadoc_files',
							$F=[
								'checksum'=>$current_checksum,
								'last_synced'=>date('Y-m-d H:i:s'),
								'is_stale'=>false
							],
							$P='WHERE project=? AND filepath=?',
							$V=[$project, $filepath]
						);
					}
					else
					{
						sql_update(
							$L='dataphyre.datadoc_files',
							$F=[
								'is_stale'=>true
							],
							$P='WHERE project=? AND filepath=?',
							$V=[$project, $filepath]
						);
					}
				}
				else
				{
					sql_update(
						$L='dataphyre.datadoc_files',
						$F=[
							'is_stale'=>false
						],
						$P='WHERE project=? AND filepath=?',
						$V=[$project, $filepath]
					);
				}
			}
			else
			{
				sql_update(
					$L='dataphyre.datadoc_files',
					$F=[
						'is_stale'=>true
					],
					$P='WHERE project=? AND filepath=?',
					$V=[$project, $filepath]
				);
			}
		}
		return true;
	}

	/**
	 * Synchronizes a bounded batch of stale Datadoc files for a project.
	 *
	 * Keeps browser-triggered indexing inside a request-time budget and returns counters that Flightdeck can display without starting a long-running job.
	 *
	 * @param string $project Datadoc project key. Empty input uses the default/current project behavior.
	 * @param positive-int $limit Maximum files to synchronize in this batch.
	 * @param positive-int|float $max_seconds Approximate request-time budget in seconds.
	 * @return array{synced:int,skipped:int,failed:int,processed:int,remaining:int,stopped_by:?string,error:?string}
	 */
	public static function sync_project_batch(string $project='', int $limit=25, float $max_seconds=4.0): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		$limit=max(1, min(250, $limit));
		$deadline=microtime(true)+max(0.5, $max_seconds);
		$stats=[
			'synced'=>0,
			'skipped'=>0,
			'failed'=>0,
			'processed'=>0,
			'remaining'=>0,
			'stopped_by'=>null,
			'error'=>null,
		];
		if($project===''){
			$stats['error']='Invalid DataDoc project synchronization request.';
			return $stats;
		}
		if(self::ensure_index_storage()!==true){
			$stats['error']=self::$last_error!=='' ? self::$last_error : 'DataDoc index storage could not be prepared.';
			return $stats;
		}
		self::prune_excluded_project_files($project);
		$rows=sql_select(
			$S='*',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=? AND is_stale=? ORDER BY filepath LIMIT '.$limit,
			$V=[$project, true],
			$F=true,
			$C=false
		);
		if(!is_array($rows)){
			$stats['error']='Unable to load pending DataDoc files.';
			return $stats;
		}
		foreach($rows as $row){
			if(microtime(true)>=$deadline){
				$stats['stopped_by']='time';
				break;
			}
			$stats['processed']++;
			$filepath=self::normalize_filesystem_path((string)($row['filepath'] ?? ''));
			if($filepath==='' || !file_exists($filepath)){
				if($filepath!==''){
					self::delete_file($filepath, $project);
					$stats['skipped']++;
				}
				else
				{
					$stats['failed']++;
				}
				continue;
			}
			if(!str_ends_with($filepath, '.php')){
				$stats['skipped']++;
				self::update_project_file_state($filepath, $project, (string)($row['checksum'] ?? ''), false);
				continue;
			}
			if(self::should_exclude_index_file($filepath)){
				self::delete_file($filepath, $project);
				$stats['skipped']++;
				continue;
			}
			if(self::sync_file($filepath, $project)===true){
				$current_checksum=md5_file($filepath);
				self::update_project_file_state($filepath, $project, is_string($current_checksum) ? $current_checksum : '', false);
				$stats['synced']++;
			}
			else
			{
				self::update_project_file_state($filepath, $project, (string)($row['checksum'] ?? ''), true);
				$stats['failed']++;
			}
		}
		$remaining=sql_count('dataphyre.datadoc_files', 'WHERE project=? AND is_stale=?', [$project, true]);
		$stats['remaining']=is_numeric($remaining) ? (int)$remaining : 0;
		return $stats;
	}

	/**
	 * Synchronizes one tracked project file and updates its stale state.
	 *
	 * Browser actions use this helper so a successful file sync immediately
	 * clears the corresponding `dataphyre.datadoc_files` row, matching batch
	 * synchronization behavior.
	 */
	public static function sync_project_file(string $filepath, string $project): bool {
		$filepath=self::normalize_filesystem_path($filepath);
		if($project==='' || $filepath===''){
			return false;
		}
		if(self::should_exclude_index_file($filepath)){
			self::delete_file($filepath, $project);
			return false;
		}
		if(!file_exists($filepath)){
			return self::delete_file($filepath, $project);
		}
		if(self::sync_file($filepath, $project)===true){
			$current_checksum=md5_file($filepath);
			return self::update_project_file_state($filepath, $project, is_string($current_checksum) ? $current_checksum : '', false);
		}
		$current_checksum=md5_file($filepath);
		self::update_project_file_state($filepath, $project, is_string($current_checksum) ? $current_checksum : '', true);
		return false;
	}

	/**
	 * Synchronizes a tracked project file only when its stored checksum is stale.
	 *
	 * DataDoc record views can call this before rendering source. The method
	 * compares the current file hash with `datadoc_files.checksum`, repairs
	 * missing file-state rows, deletes removed files from the index, and only
	 * tokenizes when the file changed or is still marked stale.
	 *
	 * @return array{checked:bool,changed:bool,synced:bool,deleted:bool,error:?string}
	 */
	public static function sync_project_file_if_changed(string $filepath, string $project): array {
		@set_time_limit(30);
		$filepath=self::normalize_filesystem_path($filepath);
		$result=[
			'checked'=>false,
			'changed'=>false,
			'synced'=>false,
			'deleted'=>false,
			'error'=>null,
		];
		if($project==='' || $filepath===''){
			$result['error']='Invalid DataDoc project file refresh request.';
			return $result;
		}
		if(self::ensure_index_storage()!==true){
			$result['error']=self::$last_error!=='' ? self::$last_error : 'DataDoc index storage could not be prepared.';
			return $result;
		}
		$result['checked']=true;
		if(self::should_exclude_index_file($filepath)){
			$result['deleted']=self::delete_file($filepath, $project);
			$result['changed']=$result['deleted'];
			return $result;
		}
		if(!file_exists($filepath)){
			$result['deleted']=self::delete_file($filepath, $project);
			$result['changed']=$result['deleted'];
			return $result;
		}
		$current_checksum=md5_file($filepath);
		$current_checksum=is_string($current_checksum) ? $current_checksum : '';
		$row=sql_select(
			$S='checksum,last_synced,is_stale',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=? AND filepath=?',
			$V=[$project, $filepath],
			$F=false,
			$C=false
		);
		if(!is_array($row) || $row===[]){
			if(self::register_file_to_project($filepath, $project)!==true){
				$result['error']='DataDoc file state could not be registered.';
				return $result;
			}
			$result['changed']=true;
		}
		else{
			$result['changed']=(string)($row['checksum'] ?? '')!==$current_checksum
				|| self::database_bool($row['is_stale'] ?? false)===true
				|| empty($row['last_synced']);
		}
		if($result['changed']!==true){
			return $result;
		}
		$result['synced']=self::sync_project_file($filepath, $project);
		if($result['synced']!==true){
			$result['error']='DataDoc file synchronization failed.';
		}
		return $result;
	}

	/**
	 * Persists checksum, sync time, and stale state for a tracked project file.
	 *
	 * A stale file clears last_synced so later batch syncs can distinguish
	 * unresolved failures from successfully synchronized source files.
	 *
	 * @param string $filepath Normalized file path tracked by Datadoc.
	 * @param string $project Datadoc project name.
	 * @param string $checksum Current or last-known file checksum.
	 * @param bool $is_stale True when the file still needs synchronization.
	 * @return bool True when the file-state row was updated.
	 */
	private static function update_project_file_state(string $filepath, string $project, string $checksum, bool $is_stale): bool {
		$result=sql_update(
			$L='dataphyre.datadoc_files',
			$F=[
				'checksum'=>$checksum,
				'last_synced'=>$is_stale ? null : date('Y-m-d H:i:s'),
				'is_stale'=>$is_stale,
			],
			$P='WHERE project=? AND filepath=?',
			$V=[$project, $filepath]
		);
		return $result!==false;
	}

	/**
	 * Coerces SQL-stored boolean values into runtime booleans.
	 *
	 * SQL drivers and schemas may return booleans as native false, integers, or
	 * string markers. This helper treats common false markers as false and any
	 * other non-empty value as true.
	 *
	 * @param mixed $value Database value to coerce.
	 * @return bool Runtime boolean interpretation.
	 */
	private static function database_bool(mixed $value): bool {
		return !empty($value) && !in_array($value, [false, 0, '0', 'f', 'false'], true);
	}

	/**
	 * Renames a Datadoc-tracked file path inside index tables.
	 *
	 * Updates both file-state and indexed-symbol rows so links continue to point at the new source path.
	 */
	public static function change_filepath(string $old_filepath, string $new_filepath): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$result=sql_update(
			$L='dataphyre.datadoc_data',
			$F=[
				'file'=>$new_filepath
			],
			$P='WHERE file=?',
			$V=[$old_filepath]
		);
		return $result!==false;
	}

	/**
	 * Synchronizes one source file into the Datadoc code index.
	 *
	 * Tokenizes PHP symbols, deletes previous records for that file/project, writes namespace/class/function rows to `dataphyre.datadoc_data`, and updates file-state metadata.
	 */
	public static function sync_file(string $file, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$file=self::normalize_filesystem_path($file);
		if(!file_exists($file) || self::should_exclude_index_file($file)){
			return false;
		}
		$tokens=\dataphyre\datadoc\tokenizer::tokenize($file);
		if($tokens===false){
			return false;
		}
		if(false===sql_delete(
			$L='dataphyre.datadoc_data',
			$P='WHERE file=? AND project=?',
			$V=[$file, $project]
		)){
			return false;
		}
		$success=true;
		foreach($tokens as $token){
			$checksum=md5($token['type'].$token['function'].$token['class'].$token['namespace']);
			$fields=[
				'time'=>time(),
				'checksum'=>$checksum,
				'type'=>$token['type'],
				'content'=>$token['content'],
				'file'=>$file,
				'project'=>$project,
				'function'=>$token['function'],
				'namespace'=>$token['namespace'],
				'class'=>$token['class'],
				'line'=>$token['line'],
				'phpdoc_description'=>!empty($token['phpdoc']['description']) ? $token['phpdoc']['description'] : '0',
				'phpdoc_tags'=>!empty($token['phpdoc']['tags']) ? json_encode($token['phpdoc']['tags']) : '0'
			];
			if(false===sql_delete(
				$L='dataphyre.datadoc_data',
				$P='WHERE checksum=? AND project=?',
				$V=[$checksum, $project]
			)){
				$success=false;
				continue;
			}
			if(false===sql_insert(
				$L='dataphyre.datadoc_data',
				$F=$fields
			)){
				$success=false;
			}
		}
		return $success;
	}

}

}

$GLOBALS['dataphyre_datadoc_bootstrapping']=false;
