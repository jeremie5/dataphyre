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

/*
if(isset($_GET['test'])){
	if($_GET['test']===''){
		
		set_time_limit(0);
		ini_set('max_execution_time', 0);
			
		datadoc::create_project("shopiro", "Shopiro", "/var/www/shopicore/applications/shopiro/");
		datadoc::add_files_to_project("/var/www/shopicore/applications/shopiro/", "shopiro");
		datadoc::add_files_to_project("/var/www/shopicore/common/backend/functions/", "shopiro");
		datadoc::sync_all_files("shopiro");

	}
}
*/

if(class_exists(__NAMESPACE__.'\datadoc', false)!==true){

class datadoc{

	protected static $flightdeck_auth_loaded=null;

	protected static function datadoc_base_url(): string {
		return rtrim(\dataphyre\core::url_self(), '/').'/dataphyre/datadoc';
	}

	public static function index_url(): string {
		return self::datadoc_base_url();
	}

	protected static function project_url(string $project, string $suffix=''): string {
		$url=self::datadoc_base_url().'/'.rawurlencode($project);
		if($suffix!==''){
			$url.='/'.ltrim($suffix, '/');
		}
		return $url;
	}

	protected static function manual_path_to_route(string $path): string {
		$segments=array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!=='');
		return implode('/', array_map('rawurlencode', $segments));
	}

	public static function normalize_manual_path(string|array $path): string {
		if(is_array($path)){
			$path=implode('/', $path);
		}
		$path=str_replace('\\', '/', trim($path, '/'));
		$path=preg_replace('#/+#', '/', $path);
		return $path ?? '';
	}

	protected static function normalize_filesystem_path(string $path): string {
		$path=str_replace('\\', '/', $path);
		return preg_replace('#/+#', '/', $path) ?? $path;
	}

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

	public static function legacy_password_enabled(): bool {
		return false;
	}

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

	public static function logged_in(): bool {
		return self::auth_context()['logged_in']===true;
	}

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

	public static function login($password){
		if(self::ensure_flightdeck_auth_loaded()===true){
			return \dataphyre_flightdeck_auth::login((string)$password);
		}
		return false;
	}
	
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
			$content.='<a style="color:black" href="'.$url.'"><i class="fas fa-align-left"></i>'.htmlspecialchars($record['class'] ?? '').'</a>';
		}
		else
		{
			$content.=htmlspecialchars((string)($record['content'] ?? ''));
		}
		return $content;
	}

	public static function dynadoc_output_nested_structure($project, $data, $indentation=0, $currentPath=[]){
		global $dynadoc_record;
		$namespace=$_GET['namespace'] ?? '';
		$class=$_GET['class'] ?? '';
		$type=$_GET['type'] ?? '';
		$function=$_GET['function'] ?? '';
		foreach($data as $key=>$value){
			$new_id=rand(0,999999999999999999);
			$shouldExpand='false';
			$collapseClass='';
			$newCurrentPath=array_merge($currentPath, [$key]);
			$joinedPath=implode('/', $newCurrentPath);
			if(is_array($value)){
				$matching=false;
				if($joinedPath==="$namespace/$class/$type/$function"){
					$matching=true;
				}
				if($indentation===0 && isset($dynadoc_record)){
					$matching=true;
				}
				if($matching){
					$shouldExpand='true';
					$collapseClass='show';
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
						echo "<a class='collapsed' role='button' data-toggle='collapse' href='#collapseData{$new_id}' aria-expanded='{$shouldExpand}'>";
						echo "<span style='color:black'>".$key."</span>";
						echo "</a>";
						echo "<div id='collapseData{$new_id}' class='panel-collapse collapse {$collapseClass}' role='tabpanel'>";
						self::dynadoc_output_nested_structure($project, $value, $indentation+1, $newCurrentPath);
						echo "</div></div>";
					}
				}
			}
		}
	}
	
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

	protected static function manual_document_link_html(string $project, array $record): string {
		$document_path=(string)($record['path'] ?? $record['id'] ?? '');
		$document_url=self::project_url(
			$project,
			'/manudoc/'.self::manual_path_to_route($document_path)
		);
		$title=(string)($record['titles'] ?? $record['title'] ?? basename($document_path));
		return '<a style="color:black;" href="'.$document_url.'"><i class="far fa-file-alt"></i> '.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</a>';
	}

	public static function get_manudoc_branch(string $project, array $path=[]): array {
		$path=self::normalize_menu_segments($path);
		$root_dir=self::normalize_filesystem_path(ROOTPATH['dataphyre']."doc/$project/manudocs/");
		$root_realpath=realpath($root_dir);
		if($root_realpath===false || !is_dir($root_realpath)){
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

	public static function get_dynadoc_branch(string $project, array $path=[]): array {
		return self::nested_menu_branch(self::build_dynadoc_menu_tree($project), $path);
	}

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

	protected static function menu_collapse_id(string $project, string $kind, array $path): string {
		return 'collapseDataDoc'.substr(hash('sha256', $project.'|'.$kind.'|'.json_encode($path)), 0, 16);
	}

	public static function render_procedural_menu_nodes(string $project, string $kind, array $nodes, array $path=[], int $indentation=0): void {
		$nodes=is_array($nodes) ? $nodes : [];
		if($nodes===[]){
			echo "<div class='menu-item' style='padding-left: ".($indentation*8)."px;'><span style='color:#777;'>No items</span></div>";
			return;
		}
		foreach($nodes as $node){
			$padding=$indentation * 8;
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
		echo $record['titles'];
		echo '</span>';
		echo '</a>';
		echo '</div>';
		echo '<div class="panel-body">';
		echo '<a href="'.$document_url.'">View Document</a>';
		echo '</div>';
		echo '</div>';
	}

	public static function manudoc_output_nested_structure_from_fs(array $data, int $indentation=0){
		foreach($data as $key=>$item){
			$padding=$indentation * 8;
			if($item['type']==='category'){
				$new_id=rand(0, 999999999999999999);
				echo "<div class='menu-item' style='padding-left: {$padding}px;'>";
				echo "<a class='collapsed' role='button' data-toggle='collapse' href='#collapse{$new_id}' aria-expanded='false'>";
				echo "<span style='color:black;'>{$key}</span>";
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

	public static function delete_manudoc(string $project, string $path): bool {
		$path=self::normalize_manual_path($path);
		$filepath=ROOTPATH['dataphyre']."doc/$project/manudocs/$path.md.json";
		if(file_exists($filepath)){
			unlink($filepath);
			return true;
		}
		return false;
	}

	public static function get_manudoc(string $project, string $path): ?array {
		$path=self::normalize_manual_path($path);
		$filepath=ROOTPATH['dataphyre']."doc/$project/manudocs/$path.md.json";
		if(!file_exists($filepath)) return null;
		return json_decode(file_get_contents($filepath), true);
	}

	public static function get_manudoc_structure(string $project): array {
		$root_dir=self::normalize_filesystem_path(ROOTPATH['dataphyre']."doc/$project/manudocs/");
		$structure=[];
		if(!is_dir($root_dir)) return [];
		$rii=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root_dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($rii as $file){
			if(!$file->isFile() || !str_ends_with($file->getFilename(), '.md.json')) continue;
			$relativePath=self::normalize_filesystem_path(str_replace($root_dir, '', $file->getPathname()));
			$pathParts=explode('/', $relativePath);
			$docName=str_replace('.md.json', '', array_pop($pathParts));
			$current=&$structure;
			foreach($pathParts as $part){
				if(!isset($current[$part])){
					$current[$part]=['type'=>'category', 'children'=>[]];
				}
				$current=&$current[$part]['children'];
			}
			// Read only the needed fields from JSON
			$partial=json_decode(file_get_contents($file->getPathname()), true);
			$current[]=[
				'type'=>'document',
				'path'=>implode('/', array_merge($pathParts, [$docName])),
				'content'=>[
					'titles'=>$partial['title'] ?? $docName,
					'id'=>implode('/', array_merge($pathParts, [$docName])),
					'path'=>implode('/', array_merge($pathParts, [$docName])),
				],
			];
		}
		return $structure;
	}

	public static function create_project(string $name='', string $title='', string $path=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$fields=[
			'name'=>$name,
			'title'=>$title,
			'path'=>$path,
		];
		$existing=sql_select(
			$S='name',
			$L='datadoc.projects',
			$P='WHERE name=?',
			$V=[$name],
			$F=false,
			$C=false
		);
		if(is_array($existing)){
			$result=sql_update(
				$L='datadoc.projects',
				$F=[
					'title'=>$title,
					'path'=>$path,
				],
				$P='WHERE name=?',
				$V=[$name]
			);
			return $result!==false;
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
		return $result!==false;
	}
		
	public static function reference_functions(string $content, string $current_project, string $current_class): string|false {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function add_files_to_project(string $dirpath, string $project=''): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function discover_files_to_project(string $dirpath, string $project='', int $limit=250, string $after=''): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
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
		$limit=max(1, min(1000, $limit));
		self::discover_files_to_project_walk($dirpath, $project, $limit, $after, $stats);
		return $stats;
	}

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

	private static function should_skip_datadoc_directory(string $directory): bool {
		return in_array($directory, ['.git', '.hg', '.svn', 'node_modules', 'vendor', 'cache', 'logs', 'tmp', 'temp'], true);
	}

	public static function add_file_to_project(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$filepath=self::normalize_filesystem_path($filepath);
		if(!file_exists($filepath) || !str_ends_with($filepath, '.php')){
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

	public static function register_file_to_project(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		$filepath=self::normalize_filesystem_path($filepath);
		if($project==='' || !file_exists($filepath) || !str_ends_with($filepath, '.php')){
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
		if(is_array($existing)){
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

	public static function delete_file(string $filepath, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function get_stale_files(string $project=''): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		$result=sql_select(
			$S='filepath',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=? AND is_stale=?',
			$V=[$project, true],
			$F=true
		);
		if(!is_array($result)){
			return [];
		}
		return array_column($result, 'filepath');
	}

	public static function sync_all_files(string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function sync_project_batch(string $project='', int $limit=25, float $max_seconds=4.0): array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
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
		$rows=sql_select(
			$S='*',
			$L='dataphyre.datadoc_files',
			$P='WHERE project=? AND is_stale=? ORDER BY filepath LIMIT '.$limit,
			$V=[$project, true],
			$F=true
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
				$stats['failed']++;
				if($filepath!==''){
					self::update_project_file_state($filepath, $project, (string)($row['checksum'] ?? ''), true);
				}
				continue;
			}
			if(!str_ends_with($filepath, '.php')){
				$stats['skipped']++;
				self::update_project_file_state($filepath, $project, (string)($row['checksum'] ?? ''), false);
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

	private static function database_bool(mixed $value): bool {
		return !empty($value) && !in_array($value, [false, 0, '0', 'f', 'false'], true);
	}

	public static function change_filepath(string $old_filepath, string $new_filepath): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function sync_file(string $file, string $project=''): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$file=self::normalize_filesystem_path($file);
		if(!file_exists($file)){
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
