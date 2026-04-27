<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');

$datadoc_main=ROOTPATH['common_dataphyre_runtime'].'modules/datadoc/kernel/datadoc.main.php';
if(class_exists('\dataphyre\datadoc', false)!==true && is_file($datadoc_main)){
	require_once($datadoc_main);
}

$datadoc_wrapper=ROOTPATH['common_dataphyre_runtime'].'modules/datadoc/kernel/wrapper.php';
if(is_file($datadoc_wrapper)){
	require_once($datadoc_wrapper);
}

if(class_exists('dataphyre_flightdeck_datadoc_surface', false)){
	dataphyre_flightdeck_datadoc_surface::dispatch();
	return;
}

final class dataphyre_flightdeck_datadoc_surface {

	public static function dispatch(): void {
		if(class_exists('\dataphyre\datadoc', false)!==true){
			echo dataphyre_flightdeck_view::module_page(
				'DataDoc',
				'Documentation Workspace',
				'DataDoc is not loaded in this runtime.',
				dataphyre_flightdeck_view::card('Unavailable', '<p class="fd-muted">The DataDoc module class could not be loaded.</p>'),
				'datadoc',
				['head'=>self::style()]
			);
			return;
		}

		$segments=self::segments();
		if(($segments[0] ?? '')==='dynadoc_menu_processor'){
			self::menu_partial();
			return;
		}

		if($segments===[]){
			self::render_index();
			return;
		}

		$project_name=(string)$segments[0];
		$project=\dataphyre\datadoc::get_project($project_name);
		if($project===null){
			http_response_code(404);
			self::render_not_found($project_name);
			return;
		}

		$route=(string)($segments[1] ?? 'dashboard');
		if($route==='settings'){
			self::render_settings($project);
			return;
		}
		if($route==='dynadoc'){
			self::render_dynadoc($project);
			return;
		}
		if($route==='manudoc'){
			self::render_manudoc($project, array_slice($segments, 2));
			return;
		}

		self::render_project($project);
	}

	private static function segments(): array {
		$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/dataphyre/datadoc'), PHP_URL_PATH) ?: '');
		$base='/dataphyre/datadoc';
		if(str_starts_with($path, $base)){
			$path=substr($path, strlen($base));
		}
		$segments=array_values(array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!==''));
		return array_map('rawurldecode', $segments);
	}

	private static function menu_partial(): void {
		header('Content-Type: text/html; charset=utf-8');
		if(\dataphyre\datadoc::logged_in()!==true){
			http_response_code(403);
			return;
		}
		$project_name=(string)($_GET['project'] ?? '');
		$project=\dataphyre\datadoc::get_project($project_name);
		if($project===null){
			http_response_code(404);
			echo 'Invalid project.';
			return;
		}
		$kind=strtolower(trim((string)($_GET['kind'] ?? 'dynamic')));
		$path=json_decode((string)($_GET['path'] ?? '[]'), true);
		if(!is_array($path)){
			$path=[];
		}
		$branch=\dataphyre\datadoc::get_menu_branch($project['name'], $kind, $path);
		\dataphyre\datadoc::render_procedural_menu_nodes($project['name'], $kind, $branch, $path, count($path)+1);
	}

	private static function render_index(): void {
		$notice=self::handle_index_action();
		$rows=[];
		$error=null;
		$projects=self::projects($error);
		$projects_by_name=[];
		foreach($projects as $project){
			$name=(string)($project['name'] ?? '');
			if($name===''){
				continue;
			}
			$projects_by_name[$name]=$project;
			$title=(string)(($project['title'] ?? '') ?: $name);
			$rows[]=[
				'<b>'.self::e($title).'</b><br><span class="fd-muted">'.self::e($name).'</span>',
				self::e((string)($project['path'] ?? '')),
				self::project_stats_inline($name),
				'<div class="fd-action-row"><a href="'.self::project_url($name).'">Open</a>'.self::index_action_form('sync_project', 'Run Batch', ['project'=>$name], 'fd-primary').'</div>',
			];
		}

		$content=$notice;
		if($error!==null){
			$content.='<div class="fd-warning">DataDoc project discovery failed: '.self::e($error).'</div>';
		}
		$project_body=$rows===[]
			? '<div class="fd-empty-state"><h2>No DataDoc projects yet.</h2><p>Create one from a discovered application below, or register a custom path when you want DataDoc to index shared framework code.</p></div>'
			: dataphyre_flightdeck_view::table(['Project', 'Path', 'Index', 'Action'], $rows);
		$content.=dataphyre_flightdeck_view::card(
			'Projects',
			$project_body,
			[
				'subtitle'=>'DataDoc projects, indexed records, and manual documentation roots managed through Flightdeck.',
				'actions'=>'<a class="fd-primary" href="#fd-datadoc-create">Create Project</a>',
			]
		);
		$content.=self::application_management_card($projects_by_name);
		$content.=self::project_create_card();

		echo dataphyre_flightdeck_view::module_page(
			'DataDoc',
			'Documentation Workspace',
			'Project documentation, dynamic code records, and manual documents embedded inside Flightdeck.',
			$content,
			'datadoc',
			['head'=>self::style()]
		);
	}

	private static function application_management_card(array $projects_by_name): string {
		$rows=[];
		foreach(self::discovered_applications() as $app){
			$project_key=(string)$app['project'];
			$project=$projects_by_name[$project_key] ?? null;
			$state=is_array($project)
				? dataphyre_flightdeck_view::badge('Project exists', 'success')
				: dataphyre_flightdeck_view::badge('Not registered', 'warning');
			$actions=is_array($project)
				? '<div class="fd-action-row"><a href="'.self::project_url($project_key).'">Open</a>'.self::index_action_form('sync_project', 'Run Batch', ['project'=>$project_key], 'fd-primary').'</div>'
				: '<div class="fd-action-row">'.self::index_action_form('create_app_project', 'Create', ['app_key'=>(string)$app['key']], 'fd-primary').self::index_action_form('create_app_project_index', 'Start Index', ['app_key'=>(string)$app['key']], 'fd-secondary').'</div>';
			$rows[]=[
				'<b>'.self::e((string)$app['title']).'</b><br><span class="fd-muted">'.self::e($project_key).'</span>',
				'<span title="'.self::e((string)$app['path']).'">'.self::e((string)$app['path']).'</span>',
				$state,
				$actions,
			];
		}
		$body=$rows===[]
			? '<div class="fd-empty-state"><h2>No applications discovered.</h2><p>Flightdeck could not find an applications directory in the bootstrap roots. Use the custom project form below.</p></div>'
			: dataphyre_flightdeck_view::table(['Application', 'Path', 'DataDoc State', 'Action'], $rows);
		return dataphyre_flightdeck_view::card(
			'Applications',
			$body,
			['subtitle'=>'Bootstrap application roots detected by Flightdeck. Create a DataDoc project from an app when you want its PHP files indexed.']
		);
	}

	private static function project_create_card(): string {
		$default_path=self::current_application_path() ?? (defined('DATAPHYRE_PROJECT_ROOT') ? rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\') : '');
		$html='<form id="fd-datadoc-create" method="post" class="fd-management-form">';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="fd_datadoc_index_action" value="create_project">';
		$html.='<label><span>Project Key</span><input name="project" placeholder="shopiro" autocomplete="off"></label>';
		$html.='<label><span>Display Name</span><input name="title" placeholder="Shopiro"></label>';
		$html.='<label class="fd-wide"><span>Filesystem Path</span><input name="path" value="'.self::e($default_path).'" placeholder="/var/www/shopicore/applications/shopiro"></label>';
		$html.='<label class="fd-check"><input type="checkbox" name="index_now" value="1"><span>Start lazy indexing after creating the project</span></label>';
		$html.='<div class="fd-form-actions"><button class="fd-primary" type="submit">Create Project</button></div>';
		$html.='</form>';
		return dataphyre_flightdeck_view::card(
			'Create Project',
			$html,
			['subtitle'=>'Register a DataDoc project for an application or shared framework path. Paths are limited to the current Dataphyre project roots.']
		);
	}

	private static function render_project(array $project): void {
		$notice=self::handle_project_action($project);
		$name=(string)$project['name'];
		$title=(string)(($project['title'] ?? '') ?: $name);
		$metrics=self::project_metrics($name);
		$metric_html='<section class="fd-metrics">';
		foreach($metrics as $metric){
			$metric_html.='<div class="fd-metric"><span>'.self::e($metric[0]).'</span><b>'.self::e((string)$metric[1]).'</b><p>'.self::e($metric[2]).'</p></div>';
		}
		$metric_html.='</section>';

		$record_rows=[];
		foreach(self::dynamic_records($name, 80) as $record){
			$record_rows[]=self::record_row($name, $record);
		}
		$stale_rows=[];
		foreach(self::stale_files($name) as $file){
			$stale_rows[]=[
				'<span title="'.self::e((string)$file).'">'.self::e(basename((string)$file)).'</span>',
				'<form class="fd-inline-form" method="post">'.self::csrf_input().'<input type="hidden" name="fd_datadoc_action" value="sync_file"><input type="hidden" name="file" value="'.self::e(base64_encode((string)$file)).'"><button class="fd-primary" type="submit">Sync</button></form>',
			];
		}

		$main=$notice.$metric_html;
		$main.=dataphyre_flightdeck_view::card(
			'Dynamic Records',
			dataphyre_flightdeck_view::table(['Type', 'Symbol', 'File', 'Action'], $record_rows),
			['subtitle'=>'Latest indexed code entities for this project.']
		);
		$main.=dataphyre_flightdeck_view::card(
			'Stale Files',
			$stale_rows===[] ? '<p class="fd-muted">No stale files are currently marked for this project.</p>' : dataphyre_flightdeck_view::table(['File', 'Action'], $stale_rows)
		);
		$main.=self::index_progress_card($name);

		self::render_project_shell(
			$project,
			$title,
			'Project Overview',
			$main,
			'<form method="post" class="fd-inline-form">'.self::csrf_input().'<input type="hidden" name="fd_datadoc_action" value="sync_project"><button class="fd-primary" type="submit">Run Batch</button></form>'
		);
	}

	private static function render_settings(array $project): void {
		$notice=self::handle_project_action($project);
		$name=(string)$project['name'];
		$manual_root=rtrim((string)ROOTPATH['dataphyre'], '/\\').'/doc/'.$name.'/manudocs/';
		$rows=[
			['Project', self::e((string)(($project['title'] ?? '') ?: $name))],
			['Project Key', self::e($name)],
			['Filesystem Path', self::e((string)($project['path'] ?? ''))],
			['Manual Docs Root', self::e($manual_root)],
		];
		$main=$notice.dataphyre_flightdeck_view::card(
			'Project Settings',
			dataphyre_flightdeck_view::table(['Setting', 'Value'], $rows),
			['subtitle'=>'Read-only project definition and index storage paths.']
		);
		self::render_project_shell(
			$project,
			'Project Settings',
			'Settings',
			$main,
			'<form method="post" class="fd-inline-form">'.self::csrf_input().'<input type="hidden" name="fd_datadoc_action" value="sync_project"><button class="fd-primary" type="submit">Run Batch</button></form>'
		);
	}

	private static function render_dynadoc(array $project): void {
		$notice=self::handle_project_action($project);
		$name=(string)$project['name'];
		$records=self::matching_dynamic_records($name);
		$record=$records[0] ?? null;
		if(!is_array($record)){
			$main=$notice.dataphyre_flightdeck_view::card(
				'Dynamic Record',
				'<div class="fd-warning">No matching dynamic documentation record was found for this selection.</div>'
			);
			self::render_project_shell($project, 'Dynamic Record', 'Dynamic Record', $main);
			return;
		}

		$tags=json_decode((string)($record['phpdoc_tags'] ?? ''), true);
		if(!is_array($tags)){
			$tags=[];
		}

		$metadata=[
			['Type', dataphyre_flightdeck_view::badge((string)($record['type'] ?? 'record'))],
			['Symbol', self::e(self::record_label($record))],
			['File', self::e((string)($record['file'] ?? ''))],
			['Line', self::e((string)($record['line'] ?? ''))],
		];
		if(!empty($tags['author'])){
			$metadata[]=['Author', self::e((string)$tags['author'])];
		}
		if(!empty($tags['package'])){
			$package=trim((string)$tags['package']);
			if(!empty($tags['subpackage'])){
				$package.=', '.trim((string)$tags['subpackage']);
			}
			$metadata[]=['Package', self::e($package)];
		}
		if(!empty($tags['version'])){
			$metadata[]=['Version', self::e((string)$tags['version'])];
		}

		$sync_action='';
		if(!empty($record['file'])){
			$sync_action='<form method="post" class="fd-inline-form">'.self::csrf_input().'<input type="hidden" name="fd_datadoc_action" value="sync_file"><input type="hidden" name="file" value="'.self::e(base64_encode((string)$record['file'])).'"><button class="fd-primary" type="submit">Sync File</button></form>';
		}

		$main=$notice.dataphyre_flightdeck_view::card(
			self::record_label($record),
			dataphyre_flightdeck_view::table(['Field', 'Value'], $metadata),
			['subtitle'=>'Dynamic documentation record.', 'actions'=>$sync_action]
		);

		if(!empty($record['phpdoc_description']) && (string)$record['phpdoc_description']!=='0'){
			$main.=dataphyre_flightdeck_view::card('Description', '<div class="fd-doc-body">'.nl2br(self::e((string)$record['phpdoc_description'])).'</div>');
		}
		if(!empty($tags['example'])){
			$main.=dataphyre_flightdeck_view::card('Example', self::highlight_code((string)$tags['example'], $record, false));
		}
		if(!empty($tags['warning'])){
			$main.=dataphyre_flightdeck_view::card('Warning', '<div class="fd-warning">'.nl2br(self::e(trim((string)$tags['warning']))).'</div>');
		}
		if(!empty($record['content'])){
			$main.=dataphyre_flightdeck_view::card('Source', self::highlight_code((string)$record['content'], $record, true));
		}
		if(count($records)>1){
			$rows=[];
			foreach(array_slice($records, 1, 25) as $sibling){
				$rows[]=self::record_row($name, $sibling);
			}
			$main.=dataphyre_flightdeck_view::card('Other Matches', dataphyre_flightdeck_view::table(['Type', 'Symbol', 'File', 'Action'], $rows));
		}

		self::render_project_shell($project, self::record_label($record), 'Dynamic Record', $main);
	}

	private static function render_manudoc(array $project, array $path_segments): void {
		$name=(string)$project['name'];
		$document_path=\dataphyre\datadoc::normalize_manual_path($path_segments);
		$document_data=$document_path!=='' ? \dataphyre\datadoc::get_manudoc($name, $document_path) : null;
		$title=is_array($document_data)
			? (string)($document_data['title'] ?? $document_data['titles'] ?? (basename($document_path) ?: 'Manual Document'))
			: (basename($document_path) ?: 'Manual Document');
		$document_contents=is_array($document_data)
			? ($document_data['contents'] ?? $document_data['content'] ?? $document_data['body'] ?? $document_data['html'] ?? '')
			: '';
		if(is_array($document_contents)){
			$document_contents=$document_contents['html'] ?? $document_contents['content'] ?? '<pre>'.self::e(json_encode($document_contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '').'</pre>';
		}
		$body=$document_data===null
			? '<div class="fd-warning">Manual document not found.</div>'
			: '<div class="fd-doc-body">'.(is_string($document_contents) ? $document_contents : '').'</div>';
		$main=dataphyre_flightdeck_view::card(
			$title,
			$body,
			['subtitle'=>$document_path]
		);
		self::render_project_shell($project, $title, 'Manual Document', $main);
	}

	private static function render_project_shell(array $project, string $title, string $kicker, string $main, string $actions=''): void {
		$body='<div class="fd-datadoc-layout"><aside>'.self::project_navigation($project).'</aside><div>'.$main.'</div></div>';
		echo dataphyre_flightdeck_view::module_page(
			'DataDoc',
			$title,
			$kicker.' for '.(($project['title'] ?? '') ?: $project['name']).'.',
			$body,
			'datadoc',
			[
				'head'=>self::style(),
				'actions'=>$actions,
			]
		);
	}

	private static function render_not_found(string $project_name): void {
		echo dataphyre_flightdeck_view::module_page(
			'DataDoc',
			'Project Not Found',
			'The requested DataDoc project is not available.',
			dataphyre_flightdeck_view::card('Missing Project', '<p class="fd-muted">No project named <b>'.self::e($project_name).'</b> exists in DataDoc.</p>'),
			'datadoc',
			['head'=>self::style()]
		);
	}

	private static function project_navigation(array $project): string {
		$name=(string)$project['name'];
		$html='<div class="fd-doc-nav">';
		$html.='<a href="'.self::project_url($name).'">Overview</a>';
		$html.='<a href="'.self::project_url($name, 'settings').'">Settings</a>';

		$manual_docs=self::manual_documents($name, 20);
		$html.='<h3>Manual Docs</h3>';
		if($manual_docs===[]){
			$html.='<p class="fd-muted">No manual documents.</p>';
		}
		foreach($manual_docs as $document){
			$html.='<a href="'.self::manudoc_url($name, (string)$document['path']).'">'.self::e((string)$document['title']).'</a>';
		}

		$html.='<h3>Dynamic Records</h3>';
		$records=self::dynamic_records($name, 20);
		if($records===[]){
			$html.='<p class="fd-muted">No dynamic records.</p>';
		}
		foreach($records as $record){
			$html.='<a href="'.self::dynadoc_url($name, $record).'">'.self::e(self::record_label($record)).'</a>';
		}
		$html.='</div>';

		return dataphyre_flightdeck_view::card(
			(string)(($project['title'] ?? '') ?: $name),
			$html,
			['subtitle'=>$name]
		);
	}

	private static function projects(?string &$error=null): array {
		$error=null;
		if(function_exists('sql_select')!==true){
			$error='SQL helpers are not loaded.';
			return [];
		}
		try{
			$projects=sql_select(
				$S='*',
				$L='datadoc.projects',
				$P='ORDER BY title, name',
				$V=[],
				$F=true
			);
			return is_array($projects) ? $projects : [];
		}catch(\Throwable $exception){
			$error=$exception->getMessage();
			return [];
		}
	}

	private static function project_metrics(string $project): array {
		return [
			['Indexed Files', number_format(self::count_rows('dataphyre.datadoc_files', 'WHERE project=?', [$project])), 'Files known to DataDoc.'],
			['Indexed Records', number_format(self::count_rows('dataphyre.datadoc_data', 'WHERE project=?', [$project])), 'Classes, functions, namespaces, and variables.'],
			['Stale Files', number_format(self::count_rows('dataphyre.datadoc_files', 'WHERE project=? AND is_stale=?', [$project, true])), 'Files needing synchronization.'],
			['Manual Docs', number_format(count(self::manual_documents($project, 5000))), 'Filesystem-backed manual documents.'],
		];
	}

	private static function project_stats_inline(string $project): string {
		return '<span class="fd-pill">'.number_format(self::count_rows('dataphyre.datadoc_files', 'WHERE project=?', [$project])).' files</span> '.
			'<span class="fd-pill">'.number_format(self::count_rows('dataphyre.datadoc_data', 'WHERE project=?', [$project])).' records</span>';
	}

	private static function index_progress_card(string $project): string {
		$progress=self::index_progress($project);
		$percent=$progress['files']>0 ? min(100, round((($progress['files'] - $progress['stale']) / $progress['files']) * 100, 1)) : 0;
		$rows=[
			['Known PHP Files', number_format($progress['files'])],
			['Pending Sync', number_format($progress['stale'])],
			['Indexed Records', number_format($progress['records'])],
			['Completion', self::e((string)$percent).'%'],
		];
		return dataphyre_flightdeck_view::card(
			'Index Progress',
			'<div class="fd-progress"><span style="width:'.self::e((string)$percent).'%"></span></div>'.dataphyre_flightdeck_view::table(['Metric', 'Value'], $rows),
			[
				'subtitle'=>'DataDoc indexing is intentionally batched so it stays under request execution limits.',
				'actions'=>self::project_action_form('sync_project', 'Run Next Batch', [], 'fd-primary'),
			]
		);
	}

	private static function index_progress(string $project): array {
		return [
			'files'=>self::count_rows('dataphyre.datadoc_files', 'WHERE project=?', [$project]),
			'stale'=>self::count_rows('dataphyre.datadoc_files', 'WHERE project=? AND is_stale=?', [$project, true]),
			'records'=>self::count_rows('dataphyre.datadoc_data', 'WHERE project=?', [$project]),
		];
	}

	private static function count_rows(string $table, string $where, array $vars): int {
		if(function_exists('sql_count')!==true){
			return 0;
		}
		try{
			$count=sql_count($table, $where, $vars);
			return is_numeric($count) ? (int)$count : 0;
		}catch(\Throwable){
			return 0;
		}
	}

	private static function dynamic_records(string $project, int $limit=50): array {
		if(function_exists('sql_select')!==true){
			return [];
		}
		$excluded=['tracelog', 'sql_select', 'sql_delete', 'sql_update', 'sql_insert', 'sql_count'];
		try{
			$records=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE project=? AND type NOT IN (?, ?, ?, ?, ?, ?) ORDER BY time DESC, namespace, class, function, type, content LIMIT '.max(1, $limit),
				$V=array_merge([$project], $excluded),
				$F=true
			);
			return is_array($records) ? $records : [];
		}catch(\Throwable){
			return [];
		}
	}

	private static function matching_dynamic_records(string $project): array {
		if(function_exists('sql_select')!==true){
			return [];
		}
		$conditions=['project=?'];
		$vars=[$project];
		$excluded=['tracelog', 'sql_select', 'sql_delete', 'sql_update', 'sql_insert', 'sql_count'];
		foreach(['namespace', 'class', 'type', 'function', 'content'] as $field){
			if(isset($_GET[$field]) && (string)$_GET[$field] !== ''){
				$conditions[]=$field.'=?';
				$vars[]=(string)$_GET[$field];
			}
		}
		if(!isset($_GET['type']) || (string)$_GET['type']===''){
			$conditions[]='type NOT IN (?, ?, ?, ?, ?, ?)';
			$vars=array_merge($vars, $excluded);
		}
		try{
			$records=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE '.implode(' AND ', $conditions).' ORDER BY namespace, class, function, type, content LIMIT 100',
				$V=$vars,
				$F=true
			);
			return is_array($records) ? $records : [];
		}catch(\Throwable){
			return [];
		}
	}

	private static function stale_files(string $project): array {
		try{
			return \dataphyre\datadoc::get_stale_files($project);
		}catch(\Throwable){
			return [];
		}
	}

	private static function manual_documents(string $project, int $limit=50): array {
		try{
			$structure=\dataphyre\datadoc::get_manudoc_structure($project);
		}catch(\Throwable){
			return [];
		}
		$documents=[];
		self::flatten_manual_documents($structure, $documents, $limit);
		return $documents;
	}

	private static function flatten_manual_documents(array $nodes, array &$documents, int $limit): void {
		foreach($nodes as $key=>$node){
			if(count($documents)>=$limit){
				return;
			}
			if(!is_array($node)){
				continue;
			}
			if(($node['type'] ?? '')==='document'){
				$content=$node['content'] ?? [];
				$documents[]=[
					'title'=>(string)($content['titles'] ?? $content['title'] ?? $key),
					'path'=>(string)($node['path'] ?? $content['path'] ?? $content['id'] ?? $key),
				];
				continue;
			}
			if(($node['type'] ?? '')==='category' && isset($node['children']) && is_array($node['children'])){
				self::flatten_manual_documents($node['children'], $documents, $limit);
				continue;
			}
			self::flatten_manual_documents($node, $documents, $limit);
		}
	}

	private static function record_row(string $project, array $record): array {
		$file=(string)($record['file'] ?? '');
		$line=(string)($record['line'] ?? '');
		return [
			dataphyre_flightdeck_view::badge((string)($record['type'] ?? 'record')),
			'<b>'.self::e(self::record_label($record)).'</b><br><span class="fd-muted">'.self::e((string)($record['namespace'] ?? '')).'</span>',
			'<span title="'.self::e($file).'">'.self::e(basename($file)).($line!=='' ? ':'.self::e($line) : '').'</span>',
			'<a href="'.self::dynadoc_url($project, $record).'">Open</a>',
		];
	}

	private static function record_label(array $record): string {
		$type=(string)($record['type'] ?? 'record');
		$namespace=trim((string)($record['namespace'] ?? ''), '\\');
		$class=(string)($record['class'] ?? '');
		$function=(string)($record['function'] ?? '');
		$content=(string)($record['content'] ?? '');
		if($type==='namespace'){
			return '\\'.$namespace;
		}
		if($type==='class'){
			return '\\'.trim($namespace.($namespace!=='' ? '\\' : '').$class, '\\');
		}
		if($type==='function'){
			$scope=trim($namespace.($namespace!=='' && $class!=='' ? '\\' : '').$class, '\\');
			return ($scope!=='' ? '\\'.$scope.'::' : '').$function.'()';
		}
		if($type==='variable'){
			return '$'.ltrim($content, '$');
		}
		return $content!=='' ? $content : $type;
	}

	private static function highlight_code(string $code, array $record, bool $show_lines): string {
		if(class_exists('\dataphyre\datadoc\highlighter', false)!==true){
			return dataphyre_flightdeck_view::code($code);
		}
		try{
			$options=$show_lines ? [
				'show_lines'=>true,
				'start_line'=>(int)($record['line'] ?? 1),
				'line_number_start'=>(int)($record['line'] ?? 1),
			] : [];
			$highlighted=\dataphyre\datadoc\highlighter::highlight_code($code, 'php', $options);
			$highlighted=\dataphyre\datadoc\highlighter::linkify_php(
				$highlighted,
				(string)($record['project'] ?? ''),
				(string)($record['namespace'] ?? ''),
				(string)($record['class'] ?? ''),
				(string)($record['function'] ?? '')
			);
			return '<div class="fd-datadoc-code">'.$highlighted.'</div>';
		}catch(\Throwable){
			return dataphyre_flightdeck_view::code($code);
		}
	}

	private static function handle_index_action(): string {
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST' || empty($_POST['fd_datadoc_index_action'])){
			return '';
		}
		if(class_exists('dataphyre_flightdeck_auth', false) && dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)!==true){
			return '<div class="fd-alert">Invalid Flightdeck form token. Reload this page and try again.</div>';
		}
		$action=(string)$_POST['fd_datadoc_index_action'];
		if($action==='sync_project'){
			$project=self::normalize_project_key((string)($_POST['project'] ?? ''));
			if($project==='' || \dataphyre\datadoc::get_project($project)===null){
				return '<div class="fd-alert">Invalid DataDoc project synchronization request.</div>';
			}
			return self::run_sync_batch($project, 'fd_datadoc_index_action');
		}
		if($action==='continue_index'){
			$project=self::normalize_project_key((string)($_POST['project'] ?? ''));
			$path=self::validated_project_path((string)($_POST['path'] ?? ''));
			if($project==='' || $path===null || \dataphyre\datadoc::get_project($project)===null){
				return '<div class="fd-alert">Invalid DataDoc index continuation request.</div>';
			}
			return self::run_index_batch($project, $path, (string)($_POST['cursor'] ?? ''), 'fd_datadoc_index_action');
		}
		if($action==='create_app_project' || $action==='create_app_project_index'){
			$app=self::application_by_key((string)($_POST['app_key'] ?? ''));
			if($app===null){
				return '<div class="fd-alert">Invalid application project request.</div>';
			}
			return self::create_project_from_input(
				(string)$app['project'],
				(string)$app['title'],
				(string)$app['path'],
				$action==='create_app_project_index'
			);
		}
		if($action==='create_project'){
			return self::create_project_from_input(
				(string)($_POST['project'] ?? ''),
				(string)($_POST['title'] ?? ''),
				(string)($_POST['path'] ?? ''),
				!empty($_POST['index_now'])
			);
		}
		return '';
	}

	private static function create_project_from_input(string $project, string $title, string $path, bool $index_now): string {
		$project=self::normalize_project_key($project);
		$title=trim($title);
		if($project===''){
			return '<div class="fd-alert">Project key is required and may only contain letters, numbers, dashes, and underscores.</div>';
		}
		$validated_path=self::validated_project_path($path);
		if($validated_path===null){
			return '<div class="fd-alert">Project path must be an existing directory inside the current Dataphyre project roots.</div>';
		}
		if($title===''){
			$title=ucwords(str_replace(['_', '-'], ' ', $project));
		}
		if(\dataphyre\datadoc::create_project($project, $title, $validated_path)!==true){
			return '<div class="fd-alert">DataDoc project creation failed.</div>';
		}
		return $index_now
			? self::run_index_batch($project, $validated_path, '', 'fd_datadoc_index_action')
			: '<div class="fd-warning">DataDoc project created. Use Sync when you are ready to index its PHP files.</div>';
	}

	private static function run_index_batch(string $project, string $path, string $cursor, string $action_field): string {
		$discover=\dataphyre\datadoc::discover_files_to_project($path, $project, self::discovery_batch_limit(), $cursor);
		if(!empty($discover['error'])){
			return '<div class="fd-alert">'.self::e((string)$discover['error']).'</div>';
		}
		$sync=\dataphyre\datadoc::sync_project_batch($project, self::sync_batch_limit(), self::sync_batch_seconds());
		$progress=self::index_progress($project);
		$html='<div class="fd-warning"><b>Index batch completed.</b> ';
		$html.=self::e((string)($discover['registered'] ?? 0)).' file(s) queued, ';
		$html.=self::e((string)($sync['synced'] ?? 0)).' file(s) synced, ';
		$html.=self::e((string)($progress['stale'] ?? 0)).' pending.</div>';
		$html.=self::batch_summary_table($discover, $sync, $progress);
		if(($discover['done'] ?? true)!==true || ($progress['stale'] ?? 0)>0){
			$next_cursor=(string)($discover['last_cursor'] ?? $cursor);
			$html.=self::continue_index_form($project, $path, $next_cursor, $action_field);
		}
		return $html;
	}

	private static function run_sync_batch(string $project, string $action_field): string {
		$sync=\dataphyre\datadoc::sync_project_batch($project, self::sync_batch_limit(), self::sync_batch_seconds());
		if(!empty($sync['error'])){
			return '<div class="fd-alert">'.self::e((string)$sync['error']).'</div>';
		}
		$progress=self::index_progress($project);
		$html='<div class="fd-warning"><b>Sync batch completed.</b> ';
		$html.=self::e((string)($sync['synced'] ?? 0)).' file(s) synced, ';
		$html.=self::e((string)($progress['stale'] ?? 0)).' pending.</div>';
		$html.=self::batch_summary_table(null, $sync, $progress);
		if(($progress['stale'] ?? 0)>0){
			$html.='<div class="fd-action-row">'.self::batch_action_form($action_field, 'sync_project', 'Run Next Sync Batch', ['project'=>$project], 'fd-primary').'</div>';
		}
		return $html;
	}

	private static function batch_summary_table(?array $discover, array $sync, array $progress): string {
		$rows=[];
		if($discover!==null){
			$rows[]=['Discovery Registered', self::e((string)($discover['registered'] ?? 0))];
			$rows[]=['Discovery Cursor', '<code>'.self::e((string)($discover['last_cursor'] ?? '')).'</code>'];
			$rows[]=['Discovery Complete', self::e(($discover['done'] ?? true) ? 'yes' : 'no')];
		}
		$rows[]=['Synced This Batch', self::e((string)($sync['synced'] ?? 0))];
		$rows[]=['Failed This Batch', self::e((string)($sync['failed'] ?? 0))];
		$rows[]=['Stopped By', self::e((string)($sync['stopped_by'] ?? 'batch limit'))];
		$rows[]=['Known Files', self::e((string)($progress['files'] ?? 0))];
		$rows[]=['Pending Sync', self::e((string)($progress['stale'] ?? 0))];
		$rows[]=['Indexed Records', self::e((string)($progress['records'] ?? 0))];
		return dataphyre_flightdeck_view::table(['Index Step', 'Value'], $rows);
	}

	private static function continue_index_form(string $project, string $path, string $cursor, string $action_field): string {
		$html='<form method="post" class="fd-continue-form">';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="'.self::e($action_field).'" value="continue_index">';
		$html.='<input type="hidden" name="project" value="'.self::e($project).'">';
		$html.='<input type="hidden" name="path" value="'.self::e($path).'">';
		$html.='<input type="hidden" name="cursor" value="'.self::e($cursor).'">';
		$html.='<button class="fd-primary" type="submit">Continue Indexing</button>';
		$html.='<span class="fd-muted">Runs another bounded discovery and sync batch.</span>';
		$html.='</form>';
		return $html;
	}

	private static function discovery_batch_limit(): int {
		return 250;
	}

	private static function sync_batch_limit(): int {
		return 20;
	}

	private static function sync_batch_seconds(): float {
		return 4.0;
	}

	private static function discovered_applications(): array {
		$applications=[];
		foreach(self::application_roots() as $root){
			foreach(scandir($root) ?: [] as $entry){
				if($entry==='.' || $entry==='..' || $entry[0]==='.'){
					continue;
				}
				$path=rtrim($root, '/\\').'/'.$entry;
				if(!is_dir($path)){
					continue;
				}
				$real_path=realpath($path) ?: $path;
				$project=self::normalize_project_key($entry);
				if($project===''){
					continue;
				}
				$applications[$real_path]=[
					'key'=>substr(hash('sha256', self::normalize_path($real_path)), 0, 16),
					'name'=>$entry,
					'title'=>ucwords(str_replace(['_', '-'], ' ', $entry)),
					'project'=>$project,
					'path'=>self::normalize_path($real_path),
				];
			}
		}
		uasort($applications, static fn($a, $b)=>strcmp((string)$a['project'], (string)$b['project']));
		return array_values($applications);
	}

	private static function application_by_key(string $key): ?array {
		foreach(self::discovered_applications() as $app){
			if(hash_equals((string)$app['key'], $key)){
				return $app;
			}
		}
		return null;
	}

	private static function current_application_path(): ?string {
		if(!defined('APP')){
			return null;
		}
		foreach(self::application_roots() as $root){
			$path=rtrim($root, '/\\').'/'.(string)APP;
			$real=realpath($path);
			if(is_string($real) && is_dir($real)){
				return self::normalize_path($real);
			}
		}
		return null;
	}

	private static function application_roots(): array {
		$roots=[];
		if(defined('ROOTPATH') && !empty(ROOTPATH['application_roots']) && is_array(ROOTPATH['application_roots'])){
			foreach(ROOTPATH['application_roots'] as $root){
				$roots[]=(string)$root;
			}
		}
		if(defined('DATAPHYRE_PROJECT_ROOT')){
			$roots[]=rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/applications';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['root'])){
			$roots[]=rtrim((string)ROOTPATH['root'], '/\\').'/applications';
		}
		$result=[];
		foreach(array_unique($roots) as $root){
			$real=realpath($root);
			if(is_string($real) && is_dir($real)){
				$result[]=self::normalize_path($real);
			}
		}
		return array_values(array_unique($result));
	}

	private static function allowed_project_roots(): array {
		$roots=self::application_roots();
		foreach([
			defined('DATAPHYRE_PROJECT_ROOT') ? (string)DATAPHYRE_PROJECT_ROOT : '',
			defined('ROOTPATH') && !empty(ROOTPATH['root']) ? (string)ROOTPATH['root'] : '',
			defined('ROOTPATH') && !empty(ROOTPATH['common']) ? (string)ROOTPATH['common'] : '',
			defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre']) ? (string)ROOTPATH['common_dataphyre'] : '',
			defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime']) ? (string)ROOTPATH['common_dataphyre_runtime'] : '',
		] as $root){
			$real=realpath($root);
			if(is_string($real) && is_dir($real)){
				$roots[]=self::normalize_path($real);
			}
		}
		return array_values(array_unique($roots));
	}

	private static function validated_project_path(string $path): ?string {
		$path=trim($path);
		if($path===''){
			return null;
		}
		$real=realpath($path);
		if(!is_string($real) || !is_dir($real)){
			return null;
		}
		$normalized=self::normalize_path($real);
		foreach(self::allowed_project_roots() as $root){
			$root=rtrim(self::normalize_path($root), '/').'/';
			if($normalized===rtrim($root, '/') || str_starts_with($normalized.'/', $root)){
				return $normalized;
			}
		}
		return null;
	}

	private static function normalize_project_key(string $key): string {
		$key=strtolower(trim($key));
		$key=preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
		$key=trim($key, '_-');
		return substr($key, 0, 80);
	}

	private static function normalize_path(string $path): string {
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	private static function index_action_form(string $action, string $label, array $fields=[], string $class='fd-primary'): string {
		return self::batch_action_form('fd_datadoc_index_action', $action, $label, $fields, $class);
	}

	private static function project_action_form(string $action, string $label, array $fields=[], string $class='fd-primary'): string {
		return self::batch_action_form('fd_datadoc_action', $action, $label, $fields, $class);
	}

	private static function batch_action_form(string $field, string $action, string $label, array $fields=[], string $class='fd-primary'): string {
		$html='<form method="post" class="fd-inline-form">';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="'.self::e($field).'" value="'.self::e($action).'">';
		foreach($fields as $key=>$value){
			$html.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		$html.='<button class="'.self::e($class).'" type="submit">'.self::e($label).'</button></form>';
		return $html;
	}

	private static function handle_project_action(array $project): string {
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST' || empty($_POST['fd_datadoc_action'])){
			return '';
		}
		if(class_exists('dataphyre_flightdeck_auth', false) && dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)!==true){
			return '<div class="fd-alert">Invalid Flightdeck form token. Reload this page and try again.</div>';
		}
		$action=(string)$_POST['fd_datadoc_action'];
		$project_name=(string)$project['name'];
		if($action==='sync_project'){
			$path=self::validated_project_path((string)($project['path'] ?? ''));
			return $path!==null
				? self::run_index_batch($project_name, $path, '', 'fd_datadoc_action')
				: self::run_sync_batch($project_name, 'fd_datadoc_action');
		}
		if($action==='continue_index'){
			$path=self::validated_project_path((string)($_POST['path'] ?? ''));
			if($path===null){
				return '<div class="fd-alert">Invalid DataDoc index continuation request.</div>';
			}
			return self::run_index_batch($project_name, $path, (string)($_POST['cursor'] ?? ''), 'fd_datadoc_action');
		}
		if($action==='sync_file'){
			$file=base64_decode((string)($_POST['file'] ?? ''), true);
			if(!is_string($file) || $file===''){
				return '<div class="fd-alert">Invalid file synchronization request.</div>';
			}
			$project_path=rtrim(str_replace('\\', '/', (string)($project['path'] ?? '')), '/').'/';
			$normalized_file=str_replace('\\', '/', $file);
			if($project_path!=='/' && $project_path!=='' && str_starts_with($normalized_file, $project_path)!==true){
				return '<div class="fd-alert">Refused to synchronize a file outside this DataDoc project.</div>';
			}
			$result=\dataphyre\datadoc::sync_file($file, $project_name);
			return $result ? '<div class="fd-warning">File synchronization completed.</div>' : '<div class="fd-alert">File synchronization failed.</div>';
		}
		return '';
	}

	private static function project_url(string $project, string $suffix=''): string {
		$url='/dataphyre/datadoc/'.rawurlencode($project);
		if($suffix!==''){
			$url.='/'.ltrim($suffix, '/');
		}
		return $url;
	}

	private static function dynadoc_url(string $project, array $record): string {
		$type=(string)($record['type'] ?? '');
		$query=[
			'type'=>$type,
			'namespace'=>(string)($record['namespace'] ?? ''),
			'class'=>(string)($record['class'] ?? ''),
		];
		if($type==='function'){
			$query['function']=(string)($record['function'] ?? '');
		}
		elseif($type==='variable'){
			$query['content']=(string)($record['content'] ?? '');
		}
		elseif(!in_array($type, ['namespace', 'class'], true)){
			$query['function']=(string)($record['function'] ?? '');
			$query['content']=(string)($record['content'] ?? '');
		}
		return self::project_url($project, 'dynadoc').'?'.http_build_query($query);
	}

	private static function manudoc_url(string $project, string $path): string {
		$segments=array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!=='');
		return self::project_url($project, 'manudoc/'.implode('/', array_map('rawurlencode', $segments)));
	}

	private static function csrf_input(): string {
		return class_exists('dataphyre_flightdeck_auth', false)
			? '<input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'">'
			: '';
	}

	private static function style(): string {
		return '<style>
.fd-datadoc-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:20px;align-items:start}
.fd-doc-nav{display:grid;gap:8px}
.fd-doc-nav h3{font-size:.86rem;text-transform:uppercase;letter-spacing:.12em;color:#64748b;margin:18px 0 4px}
.fd-doc-nav a{display:block;text-decoration:none;border:1px solid #dbe4ef;border-radius:14px;padding:10px 12px;background:#fff;color:#0f172a}
.fd-doc-nav a:hover{border-color:#7dd3fc;background:#eef8ff}
.fd-inline-form{display:inline-flex;gap:8px;align-items:center;margin:0}
.fd-action-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fd-secondary{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:0;background:#e0f2fe;color:#075985}
.fd-management-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.fd-management-form label{display:grid;gap:7px;color:#334155;font-weight:800}
.fd-management-form input[type=text],.fd-management-form input:not([type]){width:100%;border:1px solid #dbe4ef;border-radius:14px;padding:12px 13px;font:inherit}
.fd-management-form .fd-wide,.fd-management-form .fd-check,.fd-management-form .fd-form-actions{grid-column:1/-1}
.fd-management-form .fd-check{display:flex;align-items:center;gap:10px;font-weight:700;color:#64748b}
.fd-form-actions{display:flex;gap:10px;align-items:center}
.fd-empty-state{border:1px dashed #bae6fd;border-radius:20px;background:#f0f9ff;padding:22px}
.fd-empty-state h2{margin:0 0 8px;color:#075985}
.fd-empty-state p{margin:0;color:#475569}
.fd-progress{height:14px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:0 0 16px}
.fd-progress span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#14b8a6,#38bdf8)}
.fd-continue-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:14px 0 18px}
.fd-doc-body{line-height:1.65;color:#172033}
.fd-doc-body pre,.fd-datadoc-code [id^=codeContainer]{background:#07111f!important;color:#f8fafc!important;border-radius:18px!important;border:1px solid rgba(125,211,252,.18)!important;box-shadow:none!important}
.fd-datadoc-code{background:#030712;border-radius:20px;padding:12px;overflow:auto}
.fd-datadoc-code a{color:#bae6fd}
@media(max-width:1100px){.fd-datadoc-layout,.fd-management-form{grid-template-columns:1fr}}
</style>';
	}

	private static function e(string $value): string {
		return dataphyre_flightdeck_view::e($value);
	}
}

dataphyre_flightdeck_datadoc_surface::dispatch();
