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

$datadoc_assets_support=ROOTPATH['common_dataphyre_runtime'].'modules/datadoc/ui/assets_support.php';
if(is_file($datadoc_assets_support)){
	require_once($datadoc_assets_support);
}

if(defined('DATAPHYRE_FLIGHTDECK_DATADOC_SURFACE_LOADED')){
	if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
		dataphyre_flightdeck_datadoc_surface::dispatch();
	}
	return;
}
define('DATAPHYRE_FLIGHTDECK_DATADOC_SURFACE_LOADED', true);

/**
 * Embeds DataDoc project management and record browsing inside Flightdeck.
 *
 * The surface loads DataDoc when present, routes project dashboards, settings,
 * dynamic records, manual documents, menu partials, and bounded indexing
 * actions, while restricting project creation to known Dataphyre roots.
 */
final class dataphyre_flightdeck_datadoc_surface {

	/**
	 * Dispatches the DataDoc Flightdeck surface route.
	 *
	 * Assets and menu partials are handled before project routing. Unknown
	 * projects return 404, while an unloaded DataDoc module renders a diagnostic
	 * page instead of failing the Flightdeck shell.
	 *
	 * @return void Emits the appropriate DataDoc page, partial, asset, or error page.
	 */
	public static function dispatch(): void {
		if(class_exists('\dataphyre\datadoc', false)!==true){
			echo dataphyre_flightdeck_view::module_page(
				'DataDoc',
				'Documentation Workspace',
				'DataDoc is not loaded in this runtime.',
				dataphyre_flightdeck_view::card('Unavailable', '<p class="fd-muted">The DataDoc module class could not be loaded.</p>'),
				'datadoc',
				['head'=>self::style_link()]
			);
			return;
		}
		$segments=self::segments();
		if(($segments[0] ?? '')==='assets'){
			self::asset_response((string)($segments[1] ?? ''));
			return;
		}
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

		if($route==='dashboard' || $route==='overview'){
			self::render_project($project);
			return;
		}

		http_response_code(404);
		self::render_unknown_project_route($project, $route);
	}

	/**
	 * Extracts DataDoc surface route segments from the current Flightdeck request.
	 *
	 * The surface is mounted at `/dataphyre/datadoc`; everything after that
	 * mount point is rawurl-decoded into positional segments. Query string
	 * state is intentionally ignored because dynamic record filtering is read
	 * separately from `$_GET`.
	 *
	 * @return list<string> Ordered route fragments with empty path elements removed.
	 */
	private static function segments(): array {
		$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/dataphyre/datadoc'), PHP_URL_PATH) ?: '');
		$base='/dataphyre/datadoc';
		if(str_starts_with($path, $base)){
			$path=substr($path, strlen($base));
		}
		$segments=array_values(array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!==''));
		return array_map('rawurldecode', $segments);
	}

	/**
	 * Streams the dynamic manual-document menu branch used by the DataDoc UI.
	 *
	 * This endpoint is loaded as a partial rather than a full Flightdeck page.
	 * It verifies the DataDoc login state, validates the project, normalizes
	 * the requested branch path from JSON, and delegates procedural rendering
	 * back to DataDoc so menu behavior stays consistent with the standalone UI.
	 *
	 * @return void Emits HTML, a 403 response, or a 404 response directly.
	 */
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

	/**
	 * Renders the project index for DataDoc inside Flightdeck.
	 *
	 * The index combines registered DataDoc projects, discovered applications,
	 * and the custom project creation form. POST actions are processed first so
	 * the resulting notice reflects the latest project creation or indexing
	 * batch before the tables are assembled.
	 *
	 * @return void Emits the complete Flightdeck DataDoc landing page.
	 */
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
				'<div class="fd-action-row"><a href="'.self::project_url($name).'">Open</a>'.self::index_project_action($name).'</div>',
			];
		}

		$content=$notice;
		if($error!==null){
			$content.='<div class="fd-warning">DataDoc project storage unavailable: '.self::e($error).'</div>';
		}
		$project_body=$rows===[]
			? '<div class="fd-empty-state"><h2>No DataDoc projects yet.</h2><p>Create one from a discovered application below, or register a custom path when you want DataDoc to index shared framework code.</p></div>'
			: '<div class="fd-datadoc-index-table">'.dataphyre_flightdeck_view::table(['Project', 'Path', 'Index', 'Action'], $rows).'</div>';
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
			['head'=>self::style_link()]
		);
	}

	/**
	 * Builds the discovered-application management card.
	 *
	 * Applications are matched against registered projects by their normalized
	 * project key. The returned markup exposes either open/sync actions for
	 * existing projects or create/index actions for application roots that
	 * Flightdeck can safely register with DataDoc.
	 *
	 * @param array<string,array<string,mixed>> $projects_by_name Registered projects keyed by project name.
	 * @return string Flightdeck card HTML for application project management.
	 */
	private static function application_management_card(array $projects_by_name): string {
		$rows=[];
		foreach(self::discovered_applications() as $app){
			$project_key=(string)$app['project'];
			$project=$projects_by_name[$project_key] ?? null;
			$state=is_array($project)
				? dataphyre_flightdeck_view::badge('Project exists', 'success')
				: dataphyre_flightdeck_view::badge('Not registered', 'warning');
			$actions=is_array($project)
				? '<div class="fd-action-row"><a href="'.self::project_url($project_key).'">Open</a>'.self::index_project_action($project_key).'</div>'
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
			: '<div class="fd-datadoc-index-table">'.dataphyre_flightdeck_view::table(['Application', 'Path', 'DataDoc State', 'Action'], $rows).'</div>';
		return dataphyre_flightdeck_view::card(
			'Applications',
			$body,
			['subtitle'=>'Bootstrap application roots detected by Flightdeck. Create a DataDoc project from an app when you want its PHP files indexed.']
		);
	}

	/**
	 * Builds the custom project registration form.
	 *
	 * The default path prefers the current application root, then falls back to
	 * the broader Dataphyre project root when available. Submission is validated
	 * by `create_project_from_input()` before any project is persisted.
	 *
	 * @return string Flightdeck card HTML containing the project creation form.
	 */
	private static function project_create_card(): string {
		$default_path=self::current_application_path() ?? (defined('DATAPHYRE_PROJECT_ROOT') ? rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\') : '');
		$html='<form id="fd-datadoc-create" method="post" class="fd-management-form">';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="fd_datadoc_index_action" value="create_project">';
		$html.='<label><span>Project Key</span><input name="project" placeholder="example_app" autocomplete="off"></label>';
		$html.='<label><span>Display Name</span><input name="title" placeholder="Example App"></label>';
		$html.='<label class="fd-wide"><span>Filesystem Path</span><input name="path" value="'.self::e($default_path).'" placeholder="/srv/example/applications/example_app"></label>';
		$html.='<label class="fd-check"><input type="checkbox" name="index_now" value="1"><span>Start lazy indexing after creating the project</span></label>';
		$html.='<div class="fd-form-actions"><button class="fd-primary" type="submit">Create Project</button></div>';
		$html.='</form>';
		return dataphyre_flightdeck_view::card(
			'Create Project',
			$html,
			['subtitle'=>'Register a DataDoc project for an application or shared framework path. Paths are limited to the current Dataphyre project roots.']
		);
	}

	/**
	 * Renders the overview for a single DataDoc project.
	 *
	 * The overview summarizes index metrics, recent dynamic records, stale
	 * files that can be synchronized individually, and the current batch
	 * progress. Project-level POST actions are handled before the body is
	 * assembled so sync notices appear in context.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @return void Emits the project overview page.
	 */
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

		$record_filters=self::dynamic_record_filters();
		$record_limit=80;
		$record_total=self::count_dynamic_records($name, $record_filters);
		$record_rows=[];
		foreach(self::dynamic_records($name, $record_limit, $record_filters) as $record){
			$record_rows[]=self::record_row($name, $record);
		}
		$record_body=self::dynamic_record_filter_form($name, $record_filters);
		$record_body.=self::dynamic_record_result_summary(count($record_rows), $record_total, $record_limit, $record_filters);
		$record_body.=$record_rows===[]
			? '<p class="fd-muted">No dynamic records match the current filters.</p>'
			: dataphyre_flightdeck_view::table(['Type', 'Symbol', 'File', 'Action'], $record_rows);
		$stale_files=self::stale_files($name);
		$stale_preview=array_slice($stale_files, 0, self::stale_file_preview_limit());
		$stale_rows=[];
		foreach($stale_preview as $file){
			$label=self::project_relative_path((string)$file, (string)($project['path'] ?? ''));
			$stale_rows[]=[
				'<span class="fd-path" title="'.self::e((string)$file).'">'.self::e($label).'</span>',
			];
		}
		$stale_body=$stale_rows===[]
			? '<p class="fd-muted">No stale files are currently marked for this project.</p>'
			: dataphyre_flightdeck_view::table(['File'], $stale_rows);
		if(count($stale_files)>count($stale_preview)){
			$stale_body.='<p class="fd-muted">Showing '.self::e((string)count($stale_preview)).' of '.self::e((string)count($stale_files)).' stale files. Use Run Next Batch to synchronize the queue without clicking files one by one.</p>';
		}

		$main=$notice.$metric_html;
		$main.=self::index_progress_card($name);
		$main.=dataphyre_flightdeck_view::card(
			'Dynamic Records',
			$record_body,
			['subtitle'=>$record_filters['active'] ? 'Filtered indexed code entities for this project.' : 'Browsable indexed code entities for this project.']
		);
		$main.=dataphyre_flightdeck_view::card(
			'Stale Files',
			$stale_body,
			['subtitle'=>'Preview of files waiting for synchronization.']
		);

		self::render_project_shell(
			$project,
			$title,
			'Project Overview',
			$main,
			self::project_batch_action($project)
		);
	}

	/**
	 * Renders read-only project configuration details.
	 *
	 * Settings expose the project key, filesystem root, and manual document
	 * location without permitting edits from Flightdeck. Indexing remains
	 * available as an action because it operates through bounded DataDoc batch
	 * helpers.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @return void Emits the project settings page.
	 */
	private static function render_settings(array $project): void {
		$notice=self::handle_project_action($project);
		$name=(string)$project['name'];
		$manual_root=self::normalize_path(rtrim((string)ROOTPATH['dataphyre'], '/\\').'/doc/'.$name.'/manudocs/');
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
			self::project_batch_action($project)
		);
	}

	/**
	 * Renders a dynamic code documentation record.
	 *
	 * Matching records are selected from query parameters generated by
	 * `dynadoc_url()`. The primary record receives metadata, PHPDoc
	 * description, example, warning, source, and sibling sections when those
	 * fields are present in the indexed DataDoc payload.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @return void Emits a dynamic documentation record page.
	 */
	private static function render_dynadoc(array $project): void {
		$notice=self::handle_project_action($project);
		$name=(string)$project['name'];
		if(self::has_dynamic_record_selection()!==true){
			$main=$notice.dataphyre_flightdeck_view::card(
				'Dynamic Records',
				'<p class="fd-muted">Choose a dynamic record from the project overview or use the filters to find a symbol.</p>',
				['actions'=>'<a class="fd-primary" href="'.self::project_url($name).'">Project Overview</a>']
			);
			self::render_project_shell($project, 'Dynamic Records', 'Dynamic Records', $main);
			return;
		}
		$records=self::matching_dynamic_records($name);
		$record=$records[0] ?? null;
		if(is_array($record) && !empty($record['file'])){
			$refresh=\dataphyre\datadoc::sync_project_file_if_changed((string)$record['file'], $name);
			if(($refresh['changed'] ?? false)===true || ($refresh['deleted'] ?? false)===true){
				$records=self::matching_dynamic_records($name);
				$record=$records[0] ?? null;
			}
			if(!empty($refresh['error'])){
				$notice.='<div class="fd-alert">'.self::e((string)$refresh['error']).'</div>';
			}
		}
		if(!is_array($record)){
			http_response_code(404);
			$main=$notice.dataphyre_flightdeck_view::card(
				'Dynamic Record Not Found',
				'<div class="fd-warning">No matching dynamic documentation record was found for this selection.</div><p class="fd-muted">Use the project overview or filters to find the current record.</p>',
				['actions'=>'<a class="fd-secondary" href="'.self::project_url($name).'">Project Overview</a>']
			);
			self::render_project_shell($project, 'Dynamic Record Not Found', 'Not Found', $main);
			return;
		}

		$tags=json_decode((string)($record['phpdoc_tags'] ?? ''), true);
		if(!is_array($tags)){
			$tags=[];
		}
		$tag_text=static function(mixed $value): string {
			if(is_array($value)){
				return trim(implode("\n", array_map(
					static fn(mixed $entry): string => is_array($entry) ? json_encode($entry, JSON_UNESCAPED_SLASHES) : (string)$entry,
					$value
				)));
			}
			return trim((string)$value);
		};

		$metadata=[
			['Type', dataphyre_flightdeck_view::badge((string)($record['type'] ?? 'record'))],
			['Symbol', self::e(self::record_label($record))],
			['File', self::e((string)($record['file'] ?? ''))],
			['Line', self::e((string)($record['line'] ?? ''))],
		];
		if(!empty($tags['author'])){
			$metadata[]=['Author', self::e($tag_text($tags['author']))];
		}
		if(!empty($tags['package'])){
			$package=$tag_text($tags['package']);
			if(!empty($tags['subpackage'])){
				$package.=', '.$tag_text($tags['subpackage']);
			}
			$metadata[]=['Package', self::e($package)];
		}
		if(!empty($tags['version'])){
			$metadata[]=['Version', self::e($tag_text($tags['version']))];
		}

		$main=$notice.dataphyre_flightdeck_view::card(
			self::record_label($record),
			dataphyre_flightdeck_view::table(['Field', 'Value'], $metadata),
			['subtitle'=>'Dynamic documentation record.']
		);

		if(!empty($record['phpdoc_description']) && (string)$record['phpdoc_description']!=='0'){
			$main.=dataphyre_flightdeck_view::card('Description', '<div class="fd-doc-body">'.nl2br(self::e((string)$record['phpdoc_description'])).'</div>');
		}
		if(!empty($tags['example'])){
			$main.=dataphyre_flightdeck_view::card('Example', self::highlight_code($tag_text($tags['example']), $record, false));
		}
		if(!empty($tags['warning'])){
			$main.=dataphyre_flightdeck_view::card('Warning', '<div class="fd-warning">'.nl2br(self::e($tag_text($tags['warning']))).'</div>');
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

	/**
	 * Renders a filesystem-backed manual documentation page.
	 *
	 * Path segments are normalized by DataDoc before lookup, which keeps manual
	 * document traversal rules centralized in the documentation module. The
	 * method accepts both legacy and current manual-document payload keys so old
	 * generated docs continue to display.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @param list<string> $path_segments URL path fragments after `/manudoc`.
	 * @return void Emits the manual documentation page or a not-found warning.
	 */
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
		if($document_data===null){
			http_response_code(404);
		}
		$body=$document_data===null
			? '<div class="fd-warning">Manual document not found.</div><p class="fd-muted">Use the project navigation to open an available manual document.</p>'
			: '<div class="fd-doc-body">'.(is_string($document_contents) ? $document_contents : '').'</div>';
		$main=dataphyre_flightdeck_view::card(
			$document_data===null ? 'Manual Document Not Found' : $title,
			$body,
			['subtitle'=>$document_path]
		);
		self::render_project_shell($project, $document_data===null ? 'Manual Document Not Found' : $title, $document_data===null ? 'Not Found' : 'Manual Document', $main);
	}

	/**
	 * Wraps project-specific content in the shared DataDoc Flightdeck shell.
	 *
	 * The shell provides the project navigation sidebar, DataDoc surface asset
	 * link, page title, kicker text, and optional action markup. Renderers pass
	 * already-escaped or trusted Flightdeck HTML in `$main`.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @param string $title Page title.
	 * @param string $kicker Short section label rendered by Flightdeck.
	 * @param string $main Main content HTML.
	 * @param string $actions Optional header action HTML.
	 * @return void Emits the wrapped Flightdeck page.
	 */
	private static function render_project_shell(array $project, string $title, string $kicker, string $main, string $actions=''): void {
		$body='<div class="fd-datadoc-layout"><aside>'.self::project_navigation($project).'</aside><div>'.$main.'</div></div>';
		echo dataphyre_flightdeck_view::module_page(
			'DataDoc',
			$title,
			$kicker.' for '.(($project['title'] ?? '') ?: $project['name']).'.',
			$body,
			'datadoc',
			[
				'head'=>self::style_link(),
				'actions'=>$actions,
			]
		);
	}

	/**
	 * Renders the missing-project diagnostic page.
	 *
	 * The caller is responsible for setting the HTTP status code. This method
	 * only creates the Flightdeck body and escapes the requested project name so
	 * failed lookups are safe to display.
	 *
	 * @param string $project_name Requested DataDoc project key.
	 * @return void Emits a Flightdeck 404 body.
	 */
	private static function render_not_found(string $project_name): void {
		echo dataphyre_flightdeck_view::module_page(
			'DataDoc',
			'Project Not Found',
			'The requested DataDoc project is not available.',
			dataphyre_flightdeck_view::card('Missing Project', '<p class="fd-muted">No project named <b>'.self::e($project_name).'</b> exists in DataDoc.</p>'),
			'datadoc',
			['head'=>self::style_link()]
		);
	}

	/**
	 * Renders a project-scoped not-found page for unknown DataDoc sections.
	 *
	 * Keeping this distinct from the project overview prevents mistyped or
	 * stale URLs from appearing to work while showing unrelated dashboard
	 * content.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @param string $route Unknown project route segment.
	 * @return void Emits a Flightdeck 404 body inside the project shell.
	 */
	private static function render_unknown_project_route(array $project, string $route): void {
		$route=trim($route);
		$main=dataphyre_flightdeck_view::card(
			'Section Not Found',
			'<div class="fd-warning">No DataDoc section named <b>'.self::e($route!=='' ? $route : 'unknown').'</b> exists for this project.</div><p class="fd-muted">Use the project navigation to open the overview, settings, manual documents, or dynamic records.</p>'
		);
		self::render_project_shell($project, 'Section Not Found', 'Not Found', $main);
	}

	/**
	 * Builds a cache-versioned Flightdeck asset URL for DataDoc assets.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Public Flightdeck asset URL with a content hash query value.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/flightdeck/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Returns the short content hash used to version a DataDoc asset.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-1 prefix, or "missing" when the asset is unknown.
	 */
	public static function asset_version(string $asset): string {
		$content=self::asset_content($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Returns Flightdeck-local or DataDoc UI asset content.
	 *
	 *
	 * @param string $asset Requested asset filename.
	 * @return ?array{content_type:string,body:string} Asset payload, or null for unknown assets.
	 */
	public static function asset_content(string $asset): ?array {
		$asset=self::asset_name($asset);
		if($asset==='datadoc-surface.css'){
			return ['content_type'=>'text/css; charset=UTF-8', 'body'=>self::style()];
		}
		if(function_exists('dataphyre_datadoc_ui_asset_content')){
			$content=dataphyre_datadoc_ui_asset_content($asset);
			if($content!==null){
				return [
					'content_type'=>$content['content_type'],
					'body'=>$content['content'],
				];
			}
		}
		return null;
	}

	/**
	 * Serves a DataDoc surface asset through the Flightdeck asset endpoint.
	 *
	 * Only sanitized asset names are resolved, and missing assets return a
	 * plain-text 404. Successful responses use the content type supplied by the
	 * surface or DataDoc UI asset registry and are cacheable for a short window
	 * because asset URLs include a content hash.
	 *
	 * @param string $asset Requested asset filename from the route.
	 * @return void Emits asset bytes and response headers directly.
	 */
	private static function asset_response(string $asset): void {
		$content=self::asset_content($asset);
		if($content===null){
			http_response_code(404);
			header('Content-Type: text/plain; charset=UTF-8');
			echo 'DataDoc asset not found.';
			return;
		}
		header('Content-Type: '.(string)$content['content_type']);
		header('Cache-Control: public, max-age=3600');
		echo (string)$content['body'];
	}

	/**
	 * Builds the stylesheet tag required by the embedded DataDoc surface.
	 *
	 * The link points at Flightdeck's asset route rather than a filesystem path,
	 * allowing the same page shell to work in local, replay, and hosted
	 * diagnostic contexts.
	 *
	 * @return string Escaped HTML `<link>` tag for the DataDoc surface CSS.
	 */
	private static function style_link(): string {
		return '<link rel="stylesheet" href="'.self::e(self::asset_url('datadoc-surface.css')).'">';
	}

	/**
	 * Normalizes an asset request to a safe basename.
	 *
	 * Directory separators are collapsed before basename extraction and the
	 * final token is limited to simple asset characters. Invalid names become an
	 * empty string, which cannot resolve to an asset payload.
	 *
	 * @param string $asset Raw asset route fragment.
	 * @return string Safe asset basename or an empty string for invalid input.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Builds the project sidebar with manual documents and scoped records.
	 *
	 * Manual documents stay bounded as direct links, while dynamic records are
	 * exposed through a lazy scope tree so large projects remain traversable
	 * without forcing every indexed symbol into each page render.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @return string Flightdeck card HTML for project navigation.
	 */
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
		$tree=self::scope_tree_html($name, 'dynamic', self::current_dynamic_scope_path($name));
		if($tree===''){
			$html.='<p class="fd-muted">No dynamic records.</p>';
		}
		else{
			$html.='<a class="fd-doc-nav-secondary" href="'.self::project_url($name, 'dynadoc').'">Browse records</a>';
			$html.=$tree;
		}
		$html.='</div>';
		$html.=self::scope_tree_script();

		return dataphyre_flightdeck_view::card(
			(string)(($project['title'] ?? '') ?: $name),
			$html,
			['subtitle'=>$name]
		);
	}

	/**
	 * Renders the root branch for a lazy DataDoc scope tree.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $kind Menu kind accepted by DataDoc menu branch helpers.
	 * @return string Rendered root tree HTML, or an empty string when no branch exists.
	 */
	private static function scope_tree_html(string $project, string $kind, array $current_path=[]): string {
		try{
			$branch=\dataphyre\datadoc::get_menu_branch($project, $kind, []);
		}
		catch(\Throwable $exception){
			return '';
		}
		if($branch===[]){
			return '';
		}
		ob_start();
		\dataphyre\datadoc::render_procedural_menu_nodes($project, $kind, $branch, [], 0);
		$tree=(string)ob_get_clean();
		if(trim($tree)===''){
			return '';
		}
		$current_path=array_values(array_filter($current_path, static fn($segment)=>trim((string)$segment)!==''));
		$current_attr=$current_path===[] ? '' : ' data-datadoc-current-path="'.self::e(json_encode($current_path, JSON_UNESCAPED_SLASHES) ?: '[]').'"';
		return '<div class="fd-scope-tree" data-datadoc-menu-endpoint="/dataphyre/datadoc/dynadoc_menu_processor"'.$current_attr.'>'.$tree.'</div>';
	}

	/**
	 * Returns the sidebar branch path for the current dynamic record URL.
	 *
	 * @param string $project DataDoc project key.
	 * @return list<string> Menu path segments to open, or an empty list.
	 */
	private static function current_dynamic_scope_path(string $project): array {
		$segments=self::segments();
		if(($segments[0] ?? '')!==$project || ($segments[1] ?? '')!=='dynadoc' || self::has_dynamic_record_selection()!==true){
			return [];
		}
		$type=strtolower(trim((string)($_GET['type'] ?? '')));
		$namespace=trim((string)($_GET['namespace'] ?? ''));
		$class=trim((string)($_GET['class'] ?? ''));
		$function=trim((string)($_GET['function'] ?? ''));
		$path=[];
		if($type==='variable'){
			if($namespace!==''){
				$path[]='group:namespaces';
				foreach(explode('\\', $namespace) as $part){
					$part=trim($part);
					if($part!==''){
						$path[]='ns:'.$part;
					}
				}
			}
			$path[]='group:variables';
			if($class!==''){
				$path[]='scope:classes';
				$path[]='class:'.$class;
			}
			return $path;
		}
		if($function!=='' || $type==='function'){
			if($namespace!==''){
				$path[]='group:namespaces';
				foreach(explode('\\', $namespace) as $part){
					$part=trim($part);
					if($part!==''){
						$path[]='ns:'.$part;
					}
				}
			}
			if($class!==''){
				$path[]='group:classes';
				$path[]='class:'.$class;
			}
			else{
				$path[]='group:functions';
			}
			return $path;
		}
		if($class!=='' && $function==='' && ($type==='' || $type==='class')){
			if($namespace!==''){
				$path[]='group:namespaces';
				foreach(explode('\\', $namespace) as $part){
					$part=trim($part);
					if($part!==''){
						$path[]='ns:'.$part;
					}
				}
			}
			$path[]='group:classes';
			$path[]='class:'.$class;
			return $path;
		}
		if($namespace!==''){
			$path[]='group:namespaces';
			foreach(explode('\\', $namespace) as $part){
				$part=trim($part);
				if($part!==''){
					$path[]='ns:'.$part;
				}
			}
		}
		if($class!==''){
			$path[]='group:classes';
			$path[]='class:'.$class;
		}
		if($class!=='' || $namespace!==''){
			return $path;
		}
		if($type==='class'){
			return ['group:classes'];
		}
		if($type==='namespace'){
			return ['group:namespaces'];
		}
		return [];
	}

	/**
	 * Installs lazy loading behavior for project sidebar scope trees.
	 *
	 * Branch links are rendered by the DataDoc menu helpers. This small layer
	 * makes them work without requiring Bootstrap collapse scripts on the
	 * Flightdeck surface and preserves keyboard-friendly anchor activation.
	 *
	 * @return string Inline script loaded once per document.
	 */
	private static function scope_tree_script(): string {
		return <<<'HTML'
<script>
(() => {
	if (window.__fdDatadocScopeTree) {
		return;
	}
	window.__fdDatadocScopeTree = true;
	const samePath = (left, right) => JSON.stringify(left) === JSON.stringify(right);
	const togglePath = (toggle) => {
		try {
			return JSON.parse(toggle.dataset.datadocPath || '[]');
		}
		catch (error) {
			return [];
		}
	};
	const findToggle = (tree, path) => {
		return Array.from(tree.querySelectorAll('.datadoc-menu-toggle')).find((toggle) => samePath(togglePath(toggle), path)) || null;
	};
	const loadToggle = async (toggle, forceOpen) => {
		const tree = toggle.closest('.fd-scope-tree');
		const selector = toggle.getAttribute('href') || '';
		if (!tree || selector.charAt(0) !== '#') {
			return false;
		}
		const target = document.getElementById(selector.slice(1));
		if (!target) {
			return false;
		}
		const isOpen = toggle.getAttribute('aria-expanded') === 'true';
		if (target.dataset.datadocLoaded !== '1') {
			target.innerHTML = '<div class="fd-scope-loading">Loading...</div>';
			const params = new URLSearchParams({
				project: toggle.dataset.datadocProject || '',
				kind: toggle.dataset.datadocKind || 'dynamic',
				path: toggle.dataset.datadocPath || '[]'
			});
			try {
				const response = await fetch((tree.dataset.datadocMenuEndpoint || '/dataphyre/datadoc/dynadoc_menu_processor') + '?' + params.toString(), {
					credentials: 'same-origin',
					headers: {'X-Requested-With': 'fetch'}
				});
				target.innerHTML = response.ok ? await response.text() : '<div class="fd-scope-error">Unable to load this scope.</div>';
			}
			catch (error) {
				target.innerHTML = '<div class="fd-scope-error">Unable to load this scope.</div>';
			}
			target.dataset.datadocLoaded = '1';
		}
		const shouldOpen = forceOpen || !isOpen;
		toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
		target.classList.toggle('show', shouldOpen);
		return true;
	};
	const highlightCurrentLinks = (tree) => {
		const current = new URL(location.href);
		const identityFields = ['type', 'namespace', 'class', 'function', 'content'];
		const sameRecord = (left, right) => {
			if (left.pathname !== right.pathname) {
				return false;
			}
			return identityFields.every((field) => (left.searchParams.get(field) || '') === (right.searchParams.get(field) || ''));
		};
		tree.querySelectorAll('a[href*="/dynadoc?"]').forEach((link) => {
			const href = new URL(link.getAttribute('href'), location.origin);
			if (sameRecord(href, current)) {
				link.classList.add('datadoc-current-record');
			}
		});
	};
	const expandCurrentTree = async (tree) => {
		let path = [];
		try {
			path = JSON.parse(tree.dataset.datadocCurrentPath || '[]');
		}
		catch (error) {
			path = [];
		}
		if (!Array.isArray(path) || path.length === 0) {
			highlightCurrentLinks(tree);
			return;
		}
		for (let index = 1; index <= path.length; index += 1) {
			const prefix = path.slice(0, index);
			const toggle = findToggle(tree, prefix);
			if (!toggle) {
				break;
			}
			await loadToggle(toggle, true);
		}
		highlightCurrentLinks(tree);
	};
	document.addEventListener('click', async (event) => {
		const toggle = event.target.closest('.fd-scope-tree .datadoc-menu-toggle');
		if (!toggle) {
			return;
		}
		event.preventDefault();
		await loadToggle(toggle, false);
	});
	const initialize = () => {
		document.querySelectorAll('.fd-scope-tree').forEach((tree) => {
			expandCurrentTree(tree);
		});
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, {once: true});
	}
	else {
		initialize();
	}
})();
</script>
HTML;
	}

	/**
	 * Reads registered DataDoc projects from SQL storage.
	 *
	 * SQL helper absence and database failures are converted into an empty
	 * project list plus a caller-visible error string, allowing the Flightdeck
	 * surface to render a useful diagnostic instead of throwing during startup.
	 *
	 * @param ?string $error Populated with a readable discovery failure reason.
	 * @return list<array<string,mixed>> Registered DataDoc project rows.
	 */
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
				$F=true,
				$C=false
			);
			return is_array($projects) ? $projects : [];
		}catch(\Throwable $exception){
			$error=self::project_storage_error($exception);
			return [];
		}
	}

	/**
	 * Converts a SQL/storage exception into a page-safe diagnostic.
	 *
	 * The landing page can still discover local applications and create-project
	 * forms when SQL storage is misconfigured, so raw driver exceptions are
	 * summarized into operator-facing setup hints.
	 *
	 * @param \Throwable $exception Storage exception raised while loading projects.
	 * @return string Safe, concise message for the Flightdeck page.
	 */
	private static function project_storage_error(\Throwable $exception): string {
		$message=trim($exception->getMessage());
		if(str_contains($message, 'pg_connect') || str_contains($message, 'pg_')){
			return 'The configured SQL driver needs the PHP PostgreSQL extension before registered projects can be loaded.';
		}
		return $message!=='' ? $message : 'Registered projects could not be loaded.';
	}

	/**
	 * Summarizes high-level index metrics for a project.
	 *
	 * Counts are intentionally best-effort: missing SQL helpers or query
	 * failures resolve to zero through `count_rows()`, keeping the overview page
	 * available even when index storage is partially unavailable.
	 *
	 * @param string $project DataDoc project key.
	 * @return list<array{0:string,1:string,2:string}> Metric label, value, and explanatory text.
	 */
	private static function project_metrics(string $project): array {
		return [
			['Indexed Files', number_format(self::count_index_files($project)), 'Files known to DataDoc.'],
			['Visible Records', number_format(self::count_default_dynamic_records($project)), 'Browsable classes, functions, namespaces, and variables.'],
			['Stale Files', number_format(self::count_index_files($project, true)), 'Files needing synchronization.'],
			['Manual Docs', number_format(count(self::manual_documents($project, 5000))), 'Filesystem-backed manual documents.'],
		];
	}

	/**
	 * Builds the compact project statistics shown in the project table.
	 *
	 * The output is small pill markup containing file and record counts. Values
	 * are formatted for display only and are not intended for machine parsing.
	 *
	 * @param string $project DataDoc project key.
	 * @return string Escaped HTML summary of project index size.
	 */
	private static function project_stats_inline(string $project): string {
		return '<span class="fd-pill">'.number_format(self::count_index_files($project)).' files</span> '.
			'<span class="fd-pill">'.number_format(self::count_default_dynamic_records($project)).' records</span>';
	}

	/**
	 * Builds the index progress card for a project.
	 *
	 * Progress compares known files against stale files, then provides a batch
	 * action for continuing synchronization. DataDoc indexing remains bounded
	 * because each action processes only the limits returned by this surface.
	 *
	 * @param string $project DataDoc project key.
	 * @return string Flightdeck card HTML for index progress.
	 */
	private static function index_progress_card(string $project): string {
		$progress=self::index_progress($project);
		$percent=$progress['files']>0 ? min(100, round((($progress['files'] - $progress['stale']) / $progress['files']) * 100, 1)) : 0;
		$rows=[
			['Known PHP Files', number_format($progress['files'])],
			['Pending Sync', number_format($progress['stale'])],
			['Indexed Records', number_format($progress['records'])],
			['Completion', self::e((string)$percent).'%'],
		];
		$body='<div class="fd-progress"><span style="width:'.self::e((string)$percent).'%"></span></div>'.dataphyre_flightdeck_view::table(['Metric', 'Value'], $rows);
		if($progress['files']>0 && $progress['stale']===0){
			$body.=($progress['discovery_pending'] ?? false)===true
				? '<p class="fd-muted">More PHP files are available for discovery. Run the next batch to continue indexing this project.</p>'
				: '<p class="fd-muted">This project index is current.</p>';
		}
		return dataphyre_flightdeck_view::card(
			'Index Progress',
			$body,
			[
				'subtitle'=>'DataDoc indexing is intentionally batched so it stays under request execution limits.',
				'actions'=>self::project_batch_action_from_progress($progress, false),
			]
		);
	}

	/**
	 * Reads raw index progress counters for a project.
	 *
	 * The returned shape is shared by progress cards and batch notices, giving
	 * callers a consistent view of known files, stale files, and indexed
	 * records after each discovery or sync step.
	 *
	 * @param string $project DataDoc project key.
	 * @return array{files:int,stale:int,records:int,discovery_pending:bool,discovery_cursor:string} Current index counters.
	 */
	private static function index_progress(string $project): array {
		$project_row=class_exists('\dataphyre\datadoc', false) ? \dataphyre\datadoc::get_project($project) : null;
		$path=is_array($project_row) ? self::validated_project_path((string)($project_row['path'] ?? '')) : null;
		$discovery=$path!==null ? self::project_discovery_progress($project, $path) : ['pending'=>false, 'cursor'=>''];
		return [
			'files'=>self::count_index_files($project),
			'stale'=>self::count_index_files($project, true),
			'records'=>self::count_index_records($project),
			'discovery_pending'=>(bool)($discovery['pending'] ?? false),
			'discovery_cursor'=>(string)($discovery['cursor'] ?? ''),
		];
	}

	/**
	 * Builds the primary project-page batch action.
	 *
	 * Pending projects get a synchronization action that drains the existing
	 * stale queue. Current projects get a refresh action that performs a bounded
	 * discovery pass before syncing newly discovered files.
	 *
	 * @param array<string,mixed> $project DataDoc project record.
	 * @return string Inline POST form HTML for the appropriate project action.
	 */
	private static function project_batch_action(array $project): string {
		$name=(string)($project['name'] ?? '');
		$progress=self::index_progress($name);
		return self::project_batch_action_from_progress($progress, false);
	}

	/**
	 * Builds the index-page action for a registered project.
	 *
	 * Existing stale files use the sync action because no further discovery is
	 * needed to make progress. Current projects expose a refresh action that can
	 * discover newly added PHP files without suggesting work is already pending.
	 *
	 * @param string $project DataDoc project key.
	 * @return string Inline POST form HTML.
	 */
	private static function index_project_action(string $project): string {
		$progress=self::index_progress($project);
		return self::project_batch_action_from_progress($progress, true, $project);
	}

	/**
	 * Builds the right batch action for stale sync, pending discovery, or refresh.
	 *
	 * @param array{stale:int,discovery_pending?:bool,discovery_cursor?:string} $progress Current index progress.
	 * @param bool $index_scope Whether the form is rendered on the project index.
	 * @param string $project DataDoc project key for index-scoped forms.
	 * @return string Inline action form HTML.
	 */
	private static function project_batch_action_from_progress(array $progress, bool $index_scope, string $project=''): string {
		if(($progress['stale'] ?? 0)>0){
			$fields=$index_scope ? ['project'=>$project] : [];
			return $index_scope
				? self::index_action_form('sync_project', 'Run Next Batch', $fields, 'fd-primary')
				: self::project_action_form('sync_project', 'Run Next Batch', $fields, 'fd-primary');
		}
		if(($progress['discovery_pending'] ?? false)===true){
			$fields=$index_scope ? ['project'=>$project] : [];
			$cursor=(string)($progress['discovery_cursor'] ?? '');
			if($cursor!==''){
				$fields['cursor']=$cursor;
			}
			return $index_scope
				? self::index_action_form('refresh_project', 'Run Next Batch', $fields, 'fd-primary')
				: self::project_action_form('refresh_project', 'Run Next Batch', $fields, 'fd-primary');
		}
		$fields=$index_scope ? ['project'=>$project] : [];
		return $index_scope
			? self::index_action_form('refresh_project', 'Check for Updates', $fields, 'fd-secondary', false)
			: self::project_action_form('refresh_project', 'Check for Updates', $fields, 'fd-secondary', false);
	}

	/**
	 * Counts rows through the runtime SQL helper with fail-closed semantics.
	 *
	 * The table and where fragments are owned by this surface rather than user
	 * input. Variables remain parameterized through `sql_count()`. Any missing
	 * helper or database exception is treated as zero for diagnostic resilience.
	 *
	 * @param string $table SQL table name controlled by the caller.
	 * @param string $where SQL where/order fragment controlled by the caller.
	 * @param list<mixed> $vars Bound SQL variables.
	 * @return int Non-negative count, or zero when counting is unavailable.
	 */
	private static function count_rows(string $table, string $where, array $vars): int {
		if(function_exists('sql_count')!==true){
			return 0;
		}
		try{
			$count=sql_count($table, $where, $vars, false);
			return is_numeric($count) ? (int)$count : 0;
		}catch(\Throwable){
			return 0;
		}
	}

	/**
	 * Counts authored files tracked for a project, excluding generated/test paths.
	 *
	 * @param string $project DataDoc project key.
	 * @param ?bool $stale Optional stale-state filter.
	 * @return int Visible tracked file count.
	 */
	private static function count_index_files(string $project, ?bool $stale=null): int {
		$where='WHERE project=?';
		$vars=[$project];
		if($stale!==null){
			$where.=' AND is_stale=?';
			$vars[]=$stale;
		}
		$where=self::append_index_path_exclusions($where, 'filepath', $vars);
		return self::count_rows('dataphyre.datadoc_files', $where, $vars);
	}

	/**
	 * Counts authored dynamic records for a project, excluding generated/test paths.
	 *
	 * @param string $project DataDoc project key.
	 * @return int Visible indexed record count.
	 */
	private static function count_index_records(string $project): int {
		$vars=[$project];
		$where=self::append_index_path_exclusions('WHERE project=?', 'file', $vars);
		$where=self::append_invalid_record_exclusions($where, $vars);
		return self::count_rows('dataphyre.datadoc_data', $where, $vars);
	}

	/**
	 * Counts dynamic records visible to the overview browser.
	 *
	 * The count applies the same generated/test exclusions, helper-function
	 * hiding, record-type filter, and search fields used by `dynamic_records()`
	 * so the result summary describes the table the user is actually seeing.
	 *
	 * @param string $project DataDoc project key.
	 * @param array{q:string,type:string,active:bool} $filters Current overview filters.
	 * @return int Visible dynamic record count for the current filter state.
	 */
	private static function count_dynamic_records(string $project, array $filters): int {
		$parts=self::dynamic_record_conditions($project, $filters);
		return self::count_rows('dataphyre.datadoc_data', 'WHERE '.implode(' AND ', $parts['conditions']), $parts['vars']);
	}

	/**
	 * Counts records visible in the default dynamic-record browser.
	 *
	 * @param string $project DataDoc project key.
	 * @return int Default browsable record count.
	 */
	private static function count_default_dynamic_records(string $project): int {
		return self::count_dynamic_records($project, ['q'=>'', 'type'=>'', 'active'=>false]);
	}

	/**
	 * Detects whether bounded discovery stopped before the project root ended.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $path Validated project filesystem root.
	 * @return array{pending:bool,cursor:string,next:string} Discovery state derived from indexed file order.
	 */
	private static function project_discovery_progress(string $project, string $path): array {
		static $cache=[];
		$key=$project.'|'.$path;
		if(isset($cache[$key])){
			return $cache[$key];
		}
		$cursor=self::last_indexed_file($project);
		$next=self::next_discoverable_file_after($path, $cursor);
		return $cache[$key]=[
			'pending'=>$next!==null,
			'cursor'=>$cursor,
			'next'=>$next ?? '',
		];
	}

	/**
	 * Reads the latest indexed filepath for a project's discovery cursor.
	 *
	 * @param string $project DataDoc project key.
	 * @return string Last indexed filepath in discovery order, or an empty cursor.
	 */
	private static function last_indexed_file(string $project): string {
		if(function_exists('sql_select')!==true){
			return '';
		}
		try{
			$rows=sql_select('MAX(filepath) AS cursor', 'dataphyre.datadoc_files', 'WHERE project=?', [$project], true, false);
			if(is_array($rows) && isset($rows[0]) && is_array($rows[0])){
				return self::normalize_path((string)($rows[0]['cursor'] ?? ''));
			}
		}catch(\Throwable){
		}
		return '';
	}

	/**
	 * Finds the next indexable PHP file after the current discovery cursor.
	 *
	 * @param string $dirpath Project root.
	 * @param string $after Cursor filepath already discovered.
	 * @return ?string Next discoverable PHP filepath, or null when discovery is complete.
	 */
	private static function next_discoverable_file_after(string $dirpath, string $after): ?string {
		$dirpath=rtrim(self::normalize_path($dirpath), '/');
		$after=self::normalize_path($after);
		if($dirpath==='' || !is_dir($dirpath)){
			return null;
		}
		return self::next_discoverable_file_after_walk($dirpath, $after);
	}

	/**
	 * Walks the project root in the same sorted order as DataDoc discovery.
	 *
	 * @param string $dirpath Directory currently being inspected.
	 * @param string $after Cursor filepath already discovered.
	 * @return ?string First indexable PHP filepath after the cursor.
	 */
	private static function next_discoverable_file_after_walk(string $dirpath, string $after): ?string {
		$entries=scandir($dirpath);
		if(!is_array($entries)){
			return null;
		}
		sort($entries, SORT_STRING);
		foreach($entries as $entry){
			if($entry==='.' || $entry==='..'){
				continue;
			}
			$filepath=self::normalize_path($dirpath.'/'.$entry);
			if(is_dir($filepath)){
				if(self::should_skip_discovery_directory($entry)){
					continue;
				}
				$next=self::next_discoverable_file_after_walk($filepath, $after);
				if($next!==null){
					return $next;
				}
				continue;
			}
			if(!is_file($filepath) || !str_ends_with($filepath, '.php')){
				continue;
			}
			if(class_exists('\dataphyre\datadoc', false) && \dataphyre\datadoc::should_exclude_index_file($filepath)){
				continue;
			}
			if($after==='' || strcmp($filepath, $after)>0){
				return $filepath;
			}
		}
		return null;
	}

	/**
	 * Mirrors DataDoc's discovery directory exclusions for progress probing.
	 *
	 * @param string $directory Directory basename.
	 * @return bool True when the directory is skipped during discovery.
	 */
	private static function should_skip_discovery_directory(string $directory): bool {
		return in_array($directory, ['.git', '.hg', '.svn', 'node_modules', 'vendor', 'cache', 'logs', 'tmp', 'temp', 'unit_tests'], true);
	}

	/**
	 * Appends file path exclusions shared by visible DataDoc counters and queries.
	 *
	 * @param string $where Existing SQL where fragment.
	 * @param string $field SQL file-path field controlled by the caller.
	 * @param list<mixed> $vars Bound variables mutated with exclusion patterns.
	 * @return string Where fragment with exclusion predicates appended.
	 */
	private static function append_index_path_exclusions(string $where, string $field, array &$vars): string {
		foreach(self::excluded_index_path_patterns() as $pattern){
			$where.=' AND '.$field.' NOT LIKE ?';
			$vars[]=$pattern;
		}
		return $where;
	}

	/**
	 * Returns SQL LIKE patterns for paths DataDoc should hide from authored docs.
	 *
	 * @return list<string> Slash-normalized path patterns.
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
	 * Appends record-level exclusions for legacy parser artifacts.
	 *
	 * Older tokenization could read class declarations from SQL DDL strings,
	 * producing records such as `\TEXT\TEXT`. These are hidden from current
	 * views while refresh/sync repairs remove them from storage.
	 *
	 * @param string $where Existing SQL where fragment.
	 * @param list<mixed> $vars Bound variables mutated with invalid class names.
	 * @return string Where fragment with invalid-record predicates appended.
	 */
	private static function append_invalid_record_exclusions(string $where, array &$vars): string {
		foreach(self::invalid_dynamic_class_names() as $class_name){
			$where.=' AND NOT (type=? AND namespace=? AND class=?)';
			array_push($vars, 'class', $class_name, $class_name);
		}
		return $where;
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
	 * Reads dynamic documentation records for sidebar and overview use.
	 *
	 * Runtime helper functions that would clutter navigation are excluded from
	 * default views. Unfiltered results prioritize classes, functions, and
	 * namespaces before variables so project pages start with durable symbols
	 * instead of incidental local state. The limit is clamped to at least one
	 * and embedded directly because SQL helpers expect query fragments rather
	 * than a separate limit argument.
	 *
	 * @param string $project DataDoc project key.
	 * @param int $limit Maximum number of records to return.
	 * @param array{q:string,type:string,active:bool} $filters Optional search/type filters from the project overview.
	 * @return list<array<string,mixed>> Recent indexed records.
	 */
	private static function dynamic_records(string $project, int $limit=50, array $filters=['q'=>'', 'type'=>'', 'active'=>false]): array {
		if(function_exists('sql_select')!==true){
			return [];
		}
		$parts=self::dynamic_record_conditions($project, $filters);
		$conditions=$parts['conditions'];
		$vars=$parts['vars'];
		$order=($filters['active'] ?? false)===true
			? 'type, namespace, class, function, content, file'
			: "CASE type WHEN 'class' THEN 0 WHEN 'function' THEN 1 WHEN 'namespace' THEN 2 ELSE 3 END, CASE WHEN phpdoc_description IS NOT NULL AND phpdoc_description<>'' AND phpdoc_description<>'0' THEN 0 ELSE 1 END, namespace, class, function, content, file";
		try{
			$records=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE '.implode(' AND ', $conditions).' ORDER BY '.$order.' LIMIT '.max(1, $limit),
				$V=$vars,
				$F=true,
				$C=false
			);
			return is_array($records) ? $records : [];
		}catch(\Throwable){
			return [];
		}
	}

	/**
	 * Builds filtered dynamic-record SQL predicates and variables.
	 *
	 * @param string $project DataDoc project key.
	 * @param array{q:string,type:string,active:bool} $filters Current overview filters.
	 * @return array{conditions:list<string>,vars:list<mixed>} SQL fragments and variables for select/count calls.
	 */
	private static function dynamic_record_conditions(string $project, array $filters): array {
		$excluded_functions=self::excluded_dynamic_record_functions();
		$conditions=['project=?'];
		$vars=[$project];
		$query=trim((string)($filters['q'] ?? ''));
		$type=(string)($filters['type'] ?? '');
		if($type!=='' && isset(self::dynamic_record_type_options()[$type])){
			$conditions[]='type=?';
			$vars[]=$type;
		}
		if($query!==''){
			$like='%'.$query.'%';
			$conditions[]='(content LIKE ? OR function LIKE ? OR class LIKE ? OR namespace LIKE ? OR file LIKE ? OR phpdoc_description LIKE ?)';
			array_push($vars, $like, $like, $like, $like, $like, $like);
		}
		if(($filters['active'] ?? false)!==true){
			$conditions[]='NOT (type=? AND function IN (?, ?, ?, ?, ?, ?))';
			$vars=array_merge($vars, ['function'], $excluded_functions);
		}
		foreach(self::excluded_index_path_patterns() as $pattern){
			$conditions[]='file NOT LIKE ?';
			$vars[]=$pattern;
		}
		foreach(self::invalid_dynamic_class_names() as $class_name){
			$conditions[]='NOT (type=? AND namespace=? AND class=?)';
			array_push($vars, 'class', $class_name, $class_name);
		}
		return ['conditions'=>$conditions, 'vars'=>$vars];
	}

	/**
	 * Returns helper function names hidden from default dynamic-record lists.
	 *
	 * Search and explicit type filters still make records findable; this list
	 * only keeps the initial project view and sidebar focused on project-owned
	 * symbols.
	 *
	 * @return list<string> Function names omitted from default record browsing.
	 */
	private static function excluded_dynamic_record_functions(): array {
		return ['tracelog', 'sql_select', 'sql_delete', 'sql_update', 'sql_insert', 'sql_count'];
	}

	/**
	 * Reads project-overview filters for dynamic documentation records.
	 *
	 * The filters are deliberately small and GET-based so filtered result URLs
	 * can be shared while the dynamic record identity query remains separate.
	 *
	 * @return array{q:string,type:string,active:bool} Normalized overview filters.
	 */
	private static function dynamic_record_filters(): array {
		$query=isset($_GET['q']) ? trim((string)$_GET['q']) : '';
		if(strlen($query)>120){
			$query=substr($query, 0, 120);
		}
		$type=isset($_GET['record_type']) ? trim((string)$_GET['record_type']) : '';
		if($type!=='' && isset(self::dynamic_record_type_options()[$type])!==true){
			$type='';
		}
		return [
			'q'=>$query,
			'type'=>$type,
			'active'=>$query!=='' || $type!=='',
		];
	}

	/**
	 * Returns the filterable dynamic-record types exposed by the overview.
	 *
	 * @return array<string,string> Type keys mapped to select labels.
	 */
	private static function dynamic_record_type_options(): array {
		return [
			''=>'All records',
			'class'=>'Classes',
			'function'=>'Functions',
			'namespace'=>'Namespaces',
			'variable'=>'Variables',
		];
	}

	/**
	 * Builds the dynamic-record filter form for a project overview.
	 *
	 * @param string $project DataDoc project key.
	 * @param array{q:string,type:string,active:bool} $filters Current overview filters.
	 * @return string HTML form for filtering indexed records.
	 */
	private static function dynamic_record_filter_form(string $project, array $filters): string {
		$html='<form method="get" action="'.self::project_url($project).'" class="fd-datadoc-filter-form">';
		$html.='<label><span>Search</span><input type="search" name="q" value="'.self::e((string)$filters['q']).'" placeholder="Symbol, namespace, file"></label>';
		$html.='<label><span>Type</span><select name="record_type">';
		foreach(self::dynamic_record_type_options() as $value=>$label){
			$html.='<option value="'.self::e($value).'"'.($value===(string)$filters['type'] ? ' selected' : '').'>'.self::e($label).'</option>';
		}
		$html.='</select></label>';
		$html.='<div class="fd-form-actions"><button class="fd-primary" type="submit">Filter</button>';
		if(($filters['active'] ?? false)===true){
			$html.='<a class="fd-secondary" href="'.self::project_url($project).'">Clear</a>';
		}
		$html.='</div></form>';
		return $html;
	}

	/**
	 * Renders the result count shown above the dynamic-record table.
	 *
	 * @param int $shown Number of rows rendered in the current table.
	 * @param int $total Total rows matching current filters.
	 * @param int $limit Current table row cap.
	 * @param array{q:string,type:string,active:bool} $filters Current overview filters.
	 * @return string Count summary markup.
	 */
	private static function dynamic_record_result_summary(int $shown, int $total, int $limit, array $filters): string {
		$summary='Showing '.number_format($shown).' of '.number_format($total).' dynamic records';
		if(($filters['active'] ?? false)===true){
			$summary.=' matching the current filters';
		}
		if($total>$limit){
			$summary.='. Narrow the search or type filter to find records beyond this first page';
		}
		return '<p class="fd-muted fd-datadoc-result-summary">'.$summary.'.</p>';
	}

	/**
	 * Reports whether the current Dynadoc request identifies a concrete record.
	 *
	 * The bare `/dynadoc` route is a valid section route, but it should not
	 * silently select the first indexed symbol. Record pages require at least
	 * one identity query parameter generated by `dynadoc_url()`.
	 */
	private static function has_dynamic_record_selection(): bool {
		foreach(['namespace', 'class', 'type', 'function', 'content'] as $field){
			if(isset($_GET[$field]) && trim((string)$_GET[$field])!==''){
				return true;
			}
		}
		return false;
	}

	/**
	 * Finds dynamic records matching the current query string identity.
	 *
	 * The accepted filters mirror `dynadoc_url()` so generated links round-trip
	 * to the same symbols. When no explicit type is supplied, noisy runtime SQL
	 * helper functions are excluded from the result set.
	 *
	 * @param string $project DataDoc project key.
	 * @return list<array<string,mixed>> Matching dynamic documentation records.
	 */
	private static function matching_dynamic_records(string $project): array {
		if(function_exists('sql_select')!==true){
			return [];
		}
		$conditions=['project=?'];
		$vars=[$project];
		$excluded_functions=self::excluded_dynamic_record_functions();
		foreach(['namespace', 'class', 'type', 'function', 'content'] as $field){
			if(isset($_GET[$field]) && (string)$_GET[$field] !== ''){
				$conditions[]=$field.'=?';
				$vars[]=(string)$_GET[$field];
			}
		}
		if(!isset($_GET['type']) || (string)$_GET['type']===''){
			$conditions[]='NOT (type=? AND function IN (?, ?, ?, ?, ?, ?))';
			$vars=array_merge($vars, ['function'], $excluded_functions);
		}
		foreach(self::excluded_index_path_patterns() as $pattern){
			$conditions[]='file NOT LIKE ?';
			$vars[]=$pattern;
		}
		foreach(self::invalid_dynamic_class_names() as $class_name){
			$conditions[]='NOT (type=? AND namespace=? AND class=?)';
			array_push($vars, 'class', $class_name, $class_name);
		}
		try{
			$records=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE '.implode(' AND ', $conditions).' ORDER BY namespace, class, function, type, content LIMIT 100',
				$V=$vars,
				$F=true,
				$C=false
			);
			return is_array($records) ? $records : [];
		}catch(\Throwable){
			return [];
		}
	}

	/**
	 * Reads stale files from DataDoc for individual synchronization actions.
	 *
	 * DataDoc owns stale-file detection and path normalization. Exceptions are
	 * swallowed so the project overview can still render other metrics when the
	 * stale-file lookup fails.
	 *
	 * @param string $project DataDoc project key.
	 * @return list<string> Project file paths marked as stale.
	 */
	private static function stale_files(string $project): array {
		try{
			return array_values(array_filter(
				\dataphyre\datadoc::get_stale_files($project),
				static fn(string $filepath): bool => class_exists('\dataphyre\datadoc', false)!==true || \dataphyre\datadoc::should_exclude_index_file($filepath)!==true
			));
		}catch(\Throwable){
			return [];
		}
	}

	/**
	 * Lists manual documents available to a project.
	 *
	 * DataDoc returns a nested category/document tree; this surface flattens it
	 * into a bounded list suitable for navigation. Failures return an empty list
	 * because manual documents are optional beside dynamic code records.
	 *
	 * @param string $project DataDoc project key.
	 * @param int $limit Maximum number of documents to expose.
	 * @return list<array{title:string,path:string}> Flattened manual document links.
	 */
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

	/**
	 * Flattens a DataDoc manual-document tree into navigation entries.
	 *
	 * The traversal accepts both explicit category/document nodes and older
	 * associative tree shapes. It stops as soon as `$limit` entries are collected
	 * so rendering cost is bounded for large documentation sets.
	 *
	 * @param array<mixed> $nodes Nested DataDoc manual-document structure.
	 * @param list<array{title:string,path:string}> $documents Accumulator populated by reference.
	 * @param int $limit Maximum number of documents to collect.
	 * @return void
	 */
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

	/**
	 * Converts a dynamic record into a table row.
	 *
	 * The row includes type, symbol label, basename plus source line, and a
	 * generated link back into the dynamic record view. Full paths are kept in
	 * title attributes for inspection without widening the table.
	 *
	 * @param string $project DataDoc project key.
	 * @param array<string,mixed> $record Indexed DataDoc record.
	 * @return array{0:string,1:string,2:string,3:string} Flightdeck table row cells.
	 */
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

	/**
	 * Builds the human-readable symbol label for a dynamic record.
	 *
	 * Namespaces and classes are rendered as fully qualified names, functions
	 * include their containing class or namespace when present, and variables
	 * are normalized to a single leading dollar sign.
	 *
	 * @param array<string,mixed> $record Indexed DataDoc record.
	 * @return string Display label for the symbol or record content.
	 */
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

	/**
	 * Highlights PHP code with DataDoc symbol links when the highlighter exists.
	 *
	 * This is the bridge that restores rich DataDoc source rendering inside
	 * Flightdeck. It asks the DataDoc highlighter to render PHP, optionally adds
	 * line numbers aligned to the original source location, and linkifies symbols
	 * using the current record identity. Any highlighter failure falls back to
	 * Flightdeck's plain code renderer.
	 *
	 * @param string $code Source or example code to render.
	 * @param array<string,mixed> $record DataDoc record used for line and link context.
	 * @param bool $show_lines Whether to render source line numbers.
	 * @return string Highlighted and linkified code HTML, or escaped fallback code HTML.
	 */
	private static function highlight_code(string $code, array $record, bool $show_lines): string {
		if($show_lines && strlen($code)>24000){
			return '<div class="fd-datadoc-code">'.dataphyre_flightdeck_view::code($code).'</div>';
		}
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

	/**
	 * Routes POST actions submitted from the DataDoc project index.
	 *
	 * Index-level actions can create projects, create projects from discovered
	 * applications, continue discovery, or synchronize an existing project. CSRF
	 * validation is enforced when Flightdeck authentication is loaded, and all
	 * filesystem paths are validated before reaching DataDoc project creation or
	 * discovery calls.
	 *
	 * @return string Notice HTML describing the action result, or an empty string for non-POST requests.
	 */
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
		if($action==='refresh_project'){
			$project=self::normalize_project_key((string)($_POST['project'] ?? ''));
			$project_row=$project!=='' ? \dataphyre\datadoc::get_project($project) : null;
			$path=is_array($project_row) ? self::validated_project_path((string)($project_row['path'] ?? '')) : null;
			if($project==='' || $path===null){
				return '<div class="fd-alert">Invalid DataDoc project refresh request.</div>';
			}
			$cursor=self::validated_project_cursor((string)($_POST['cursor'] ?? ''), $path);
			return self::run_index_batch($project, $path, $cursor, 'fd_datadoc_index_action');
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
		return '<div class="fd-alert">Unsupported DataDoc action.</div>';
	}

	/**
	 * Validates and creates a DataDoc project from form input.
	 *
	 * Project keys are normalized to the DCS kernel style accepted by DataDoc,
	 * paths must resolve inside allowed project roots, and missing titles are
	 * derived from the key. Optional immediate indexing starts with a bounded
	 * discovery batch instead of attempting a full synchronous crawl.
	 *
	 * @param string $project Raw project key from user input or application discovery.
	 * @param string $title Raw display title.
	 * @param string $path Candidate filesystem root for the project.
	 * @param bool $index_now Whether to start discovery and synchronization immediately.
	 * @return string Notice HTML describing creation or validation failure.
	 */
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
			$error=\dataphyre\datadoc::last_error();
			return '<div class="fd-alert">'.self::e($error!=='' ? $error : 'DataDoc project creation failed.').'</div>';
		}
		return $index_now
			? self::run_index_batch($project, $validated_path, '', 'fd_datadoc_index_action')
			: '<div class="fd-warning">DataDoc project created. Use Check for Updates when you are ready to index its PHP files.</div>';
	}

	/**
	 * Runs one bounded discovery-and-sync batch for a project.
	 *
	 * Discovery registers additional PHP files starting at `$cursor`, then sync
	 * processes a smaller time-bounded set of stale files. The returned notice
	 * includes progress and, when work remains, a continuation form carrying the
	 * cursor and original action field.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $path Validated project filesystem root.
	 * @param string $cursor DataDoc discovery cursor from a previous batch.
	 * @param string $action_field POST field used by the calling page.
	 * @return string Notice, summary table, and optional continuation form HTML.
	 */
	private static function run_index_batch(string $project, string $path, string $cursor, string $action_field): string {
		@set_time_limit(30);
		$auto_batch=self::auto_batch_enabled();
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
		$has_next_batch=false;
		if(($discover['done'] ?? true)!==true){
			$next_cursor=(string)($discover['last_cursor'] ?? $cursor);
			$html.=self::continue_index_form($project, $path, $next_cursor, $action_field, $auto_batch);
			$has_next_batch=true;
		}
		elseif(($progress['stale'] ?? 0)>0){
			$html.='<div class="fd-action-row">'.self::batch_action_form($action_field, 'sync_project', 'Run Next Batch', ['project'=>$project], 'fd-primary', $auto_batch).'</div>';
			$has_next_batch=true;
		}
		if($has_next_batch && $auto_batch){
			$html.=self::auto_batch_script();
		}
		return $html;
	}

	/**
	 * Runs one bounded stale-file synchronization batch.
	 *
	 * Unlike `run_index_batch()`, this method does not discover new files. It
	 * asks DataDoc to process the current stale queue and returns a continuation
	 * action when more stale files remain.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $action_field POST field used by the calling page.
	 * @return string Notice, summary table, and optional follow-up action HTML.
	 */
	private static function run_sync_batch(string $project, string $action_field): string {
		@set_time_limit(30);
		$auto_batch=self::auto_batch_enabled();
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
			$html.='<div class="fd-action-row">'.self::batch_action_form($action_field, 'sync_project', 'Run Next Batch', ['project'=>$project], 'fd-primary', $auto_batch).'</div>';
			if($auto_batch){
				$html.=self::auto_batch_script();
			}
		}
		return $html;
	}

	/**
	 * Builds the summary table shown after a DataDoc batch step.
	 *
	 * Discovery rows are included only when a discovery phase ran. Sync and
	 * progress rows are always displayed so users can see the current queue
	 * state after each POST action.
	 *
	 * @param ?array<string,mixed> $discover Discovery batch result, or null for sync-only batches.
	 * @param array<string,mixed> $sync Synchronization batch result.
	 * @param array<string,mixed> $progress Current index counters.
	 * @return string Flightdeck table HTML for batch results.
	 */
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

	/**
	 * Builds a continuation form for unfinished DataDoc indexing.
	 *
	 * The form carries the already-validated project path and latest discovery
	 * cursor back to the same page action namespace. CSRF input is included when
	 * Flightdeck authentication is active.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $path Validated project filesystem root.
	 * @param string $cursor Cursor for the next discovery batch.
	 * @param string $action_field POST action field name for the current page.
	 * @param bool $auto_batch Whether automatic continuation remains enabled.
	 * @return string HTML form that starts the next bounded index batch.
	 */
	private static function continue_index_form(string $project, string $path, string $cursor, string $action_field, bool $auto_batch=true): string {
		$data=$auto_batch ? ' data-fd-auto-batch="1"' : '';
		$html='<form method="post" class="fd-continue-form fd-batch-form"'.$data.'>';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="'.self::e($action_field).'" value="continue_index">';
		$html.='<input type="hidden" name="project" value="'.self::e($project).'">';
		$html.='<input type="hidden" name="path" value="'.self::e($path).'">';
		$html.='<input type="hidden" name="cursor" value="'.self::e($cursor).'">';
		$html.='<div class="fd-batch-control"><button class="fd-primary" type="submit">Run Next Batch</button>'.self::auto_batch_toggle_html($auto_batch).'</div>';
		$html.='<span class="fd-muted">Runs another bounded discovery and sync batch.</span>';
		$html.='</form>';
		return $html;
	}

	/**
	 * Returns the maximum number of files discovered per indexing request.
	 *
	 * The value keeps project creation responsive in browser-triggered
	 * Flightdeck requests while still making meaningful progress on large
	 * application trees.
	 *
	 * @return int File discovery batch limit.
	 */
	private static function discovery_batch_limit(): int {
		return 250;
	}

	/**
	 * Returns the maximum number of stale files synchronized per request.
	 *
	 * Synchronization is more expensive than discovery because it tokenizes PHP
	 * files and writes dynamic records, so its batch size is deliberately lower.
	 *
	 * @return int Stale-file synchronization batch limit.
	 */
	private static function sync_batch_limit(): int {
		return 20;
	}

	/**
	 * Returns the maximum stale files previewed on a project overview.
	 *
	 * Large application projects can accumulate hundreds of pending files. The
	 * overview stays scannable by previewing a bounded subset while the progress
	 * card exposes the preferred batch action for the full queue.
	 *
	 * @return int Stale-file preview row limit.
	 */
	private static function stale_file_preview_limit(): int {
		return 25;
	}

	/**
	 * Returns the soft execution-time budget for a sync batch.
	 *
	 * DataDoc may stop before the file limit when this budget is reached,
	 * allowing Flightdeck requests to remain interactive under typical PHP web
	 * server timeouts.
	 *
	 * @return float Maximum sync time in seconds.
	 */
	private static function sync_batch_seconds(): float {
		return 4.0;
	}

	/**
	 * Discovers application roots that can become DataDoc projects.
	 *
	 * Each child directory under known application roots is converted into a
	 * stable application descriptor with a hashed key for forms, a normalized
	 * project name, and a canonical path. Duplicate real paths collapse to one
	 * descriptor before sorting by project key.
	 *
	 * @return list<array{key:string,name:string,title:string,project:string,path:string}> Discovered application descriptors.
	 */
	private static function discovered_applications(): array {
		$applications=[];
		$dataphyre_root=self::dataphyre_source_root();
		if($dataphyre_root!==null){
			$applications[$dataphyre_root]=[
				'key'=>substr(hash('sha256', $dataphyre_root), 0, 16),
				'name'=>'dataphyre',
				'title'=>'Dataphyre',
				'project'=>'dataphyre',
				'path'=>$dataphyre_root,
			];
		}
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

	/**
	 * Resolves the shared Dataphyre source root for auto-detection.
	 *
	 * @return ?string Normalized Dataphyre source root when available.
	 */
	private static function dataphyre_source_root(): ?string {
		$candidates=[
			defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre']) ? (string)ROOTPATH['common_dataphyre'] : '',
			defined('DATAPHYRE_PROJECT_ROOT') ? rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/common/dataphyre' : '',
			defined('ROOTPATH') && !empty(ROOTPATH['root']) ? rtrim((string)ROOTPATH['root'], '/\\').'/common/dataphyre' : '',
		];
		foreach($candidates as $candidate){
			$real=realpath($candidate);
			if(is_string($real) && is_dir($real)){
				return self::normalize_path($real);
			}
		}
		return null;
	}

	/**
	 * Resolves a discovered application descriptor by its form-safe key.
	 *
	 * Keys are compared with `hash_equals()` so the lookup does not leak timing
	 * detail about valid discovery hashes. A null return indicates that the
	 * submitted key no longer maps to a current application root.
	 *
	 * @param string $key Hashed application key from a POST form.
	 * @return ?array{key:string,name:string,title:string,project:string,path:string} Matching application descriptor.
	 */
	private static function application_by_key(string $key): ?array {
		foreach(self::discovered_applications() as $app){
			if(hash_equals((string)$app['key'], $key)){
				return $app;
			}
		}
		return null;
	}

	/**
	 * Resolves the current Dataphyre application path when APP is defined.
	 *
	 * The helper searches known application roots for the active application
	 * name and returns a normalized real path. It returns null when Flightdeck is
	 * running without an application context.
	 *
	 * @return ?string Normalized current application path.
	 */
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

	/**
	 * Returns canonical application root directories.
	 *
	 * Roots are gathered from bootstrap configuration and Dataphyre project
	 * constants, resolved through `realpath()`, filtered to directories, and
	 * normalized to forward slashes for reliable prefix checks.
	 *
	 * @return list<string> Unique normalized application root paths.
	 */
	private static function application_roots(): array {
		$roots=[];
		if(defined('ROOTPATH') && !empty(ROOTPATH['application_roots']) && is_array(ROOTPATH['application_roots'])){
			foreach(ROOTPATH['application_roots'] as $root){
				$roots[]=(string)$root;
			}
		}
		if(class_exists('\dataphyre\app_locator', false)){
			$project_root=defined('DATAPHYRE_PROJECT_ROOT') ? (string)DATAPHYRE_PROJECT_ROOT : (defined('ROOTPATH') && !empty(ROOTPATH['root']) ? (string)ROOTPATH['root'] : dirname(__DIR__, 6));
			$configured_roots=defined('DATAPHYRE_APPLICATION_ROOTS') && is_array(DATAPHYRE_APPLICATION_ROOTS) ? DATAPHYRE_APPLICATION_ROOTS : $roots;
			foreach(\dataphyre\app_locator::roots($project_root, $configured_roots) as $root){
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

	/**
	 * Returns filesystem roots where DataDoc projects may be created.
	 *
	 * The allow-list includes application roots plus the current project,
	 * common library, and Dataphyre runtime roots when they exist. Project
	 * creation and continuation use this list to prevent Flightdeck from
	 * indexing arbitrary server paths.
	 *
	 * @return list<string> Unique normalized directories allowed for DataDoc projects.
	 */
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

	/**
	 * Validates a candidate DataDoc project path against the allowed roots.
	 *
	 * The input must resolve to an existing directory. The normalized directory
	 * may be the allowed root itself or a descendant of one. Null indicates a
	 * missing path, non-directory, or path outside the Flightdeck allow-list.
	 *
	 * @param string $path Raw project path from a form or registered project.
	 * @return ?string Normalized real path accepted for DataDoc indexing.
	 */
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

	/**
	 * Accepts a submitted discovery cursor only inside the project root.
	 *
	 * @param string $cursor Raw cursor path from a batch form.
	 * @param string $project_path Validated project root path.
	 * @return string Normalized cursor, or empty string for a fresh discovery.
	 */
	private static function validated_project_cursor(string $cursor, string $project_path): string {
		$cursor=trim($cursor);
		if($cursor===''){
			return '';
		}
		$normalized=self::normalize_path($cursor);
		$root=rtrim(self::normalize_path($project_path), '/').'/';
		if(str_starts_with($normalized.'/', $root)!==true){
			return '';
		}
		return $normalized;
	}

	/**
	 * Normalizes a project key for DataDoc storage and URLs.
	 *
	 * Keys are lowercased, unsupported characters become underscores, edge
	 * separators are trimmed, and the result is capped to eighty characters so
	 * generated application keys remain compact in routes and SQL rows.
	 *
	 * @param string $key Raw user or application key.
	 * @return string Normalized project key, or an empty string when no valid characters remain.
	 */
	private static function normalize_project_key(string $key): string {
		$key=strtolower(trim($key));
		$key=preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
		$key=trim($key, '_-');
		return substr($key, 0, 80);
	}

	/**
	 * Converts an absolute project file path into a compact project-relative label.
	 *
	 * @param string $file Absolute file path from DataDoc state.
	 * @param string $project_path DataDoc project root path.
	 * @return string Project-relative path when possible, otherwise basename fallback.
	 */
	private static function project_relative_path(string $file, string $project_path): string {
		$file=self::normalize_path($file);
		$root=rtrim(self::normalize_path($project_path), '/');
		if($root!=='' && str_starts_with($file, $root.'/')){
			$relative=substr($file, strlen($root)+1);
			return $relative!=='' ? $relative : basename($file);
		}
		return basename($file);
	}

	/**
	 * Normalizes a filesystem path for display and prefix comparison.
	 *
	 * Backslashes are converted to forward slashes and trailing separators are
	 * removed. The method does not call `realpath()`; callers decide whether the
	 * path must exist before normalization.
	 *
	 * @param string $path Filesystem path.
	 * @return string Slash-normalized path without a trailing slash.
	 */
	private static function normalize_path(string $path): string {
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * Builds a POST form targeting index-level DataDoc actions.
	 *
	 * This is a small wrapper around `batch_action_form()` that selects the
	 * action field used by the project index page.
	 *
	 * @param string $action Action token submitted to `handle_index_action()`.
	 * @param string $label Button label.
	 * @param array<string,mixed> $fields Extra hidden fields.
	 * @param string $class Button CSS class.
	 * @return string Inline POST form HTML.
	 */
	private static function index_action_form(string $action, string $label, array $fields=[], string $class='fd-primary', ?bool $auto_batch=null): string {
		return self::batch_action_form('fd_datadoc_index_action', $action, $label, $fields, $class, $auto_batch);
	}

	/**
	 * Builds a POST form targeting project-level DataDoc actions.
	 *
	 * Project pages use a different action field from the index so their POST
	 * handlers can coexist in one surface without ambiguous submissions.
	 *
	 * @param string $action Action token submitted to `handle_project_action()`.
	 * @param string $label Button label.
	 * @param array<string,mixed> $fields Extra hidden fields.
	 * @param string $class Button CSS class.
	 * @return string Inline POST form HTML.
	 */
	private static function project_action_form(string $action, string $label, array $fields=[], string $class='fd-primary', ?bool $auto_batch=null): string {
		return self::batch_action_form('fd_datadoc_action', $action, $label, $fields, $class, $auto_batch);
	}

	/**
	 * Builds a CSRF-aware inline POST action form.
	 *
	 * The helper escapes the action field, action token, extra hidden values,
	 * button class, and label before returning markup. It is used for all
	 * DataDoc sync and creation buttons rendered by this surface.
	 *
	 * @param string $field POST field that identifies the action namespace.
	 * @param string $action Action token.
	 * @param string $label Button label.
	 * @param array<string,mixed> $fields Extra hidden fields.
	 * @param string $class Button CSS class.
	 * @param ?bool $auto_batch Whether automatic continuation is checked for batch actions.
	 * @return string Inline POST form HTML.
	 */
	private static function batch_action_form(string $field, string $action, string $label, array $fields=[], string $class='fd-primary', ?bool $auto_batch=null): string {
		$has_auto=self::batch_action_supports_auto($action);
		$auto_batch=$auto_batch ?? true;
		$form_class='fd-inline-form'.($has_auto ? ' fd-batch-form' : '');
		$data=$has_auto && $auto_batch ? ' data-fd-auto-batch="1"' : '';
		$html='<form method="post" class="'.$form_class.'"'.$data.'>';
		$html.=self::csrf_input();
		$html.='<input type="hidden" name="'.self::e($field).'" value="'.self::e($action).'">';
		foreach($fields as $key=>$value){
			$html.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		if($has_auto){
			$html.='<div class="fd-batch-control"><button class="'.self::e($class).'" type="submit">'.self::e($label).'</button>'.self::auto_batch_toggle_html($auto_batch).'</div>';
		}
		else{
			$html.='<button class="'.self::e($class).'" type="submit">'.self::e($label).'</button>';
		}
		$html.='</form>';
		return $html;
	}

	/**
	 * Reports whether an action can safely continue through browser batches.
	 *
	 * @param string $action Action token submitted by a DataDoc POST form.
	 * @return bool True when the action may expose the Auto batching toggle.
	 */
	private static function batch_action_supports_auto(string $action): bool {
		return in_array($action, ['create_app_project_index', 'refresh_project', 'continue_index', 'sync_project'], true);
	}

	/**
	 * Reads the submitted automatic batching preference.
	 *
	 * @return bool True when follow-up batch forms should submit themselves.
	 */
	private static function auto_batch_enabled(): bool {
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){
			return true;
		}
		return (string)($_POST['auto_batch'] ?? '1')==='1';
	}

	/**
	 * Builds the Auto toggle carried by all batch-capable forms.
	 *
	 * @param bool $checked Whether the toggle starts enabled.
	 * @return string Toggle HTML.
	 */
	private static function auto_batch_toggle_html(bool $checked): string {
		return '<label class="fd-auto-toggle"><input type="hidden" name="auto_batch" value="0"><input type="checkbox" name="auto_batch" value="1"'.($checked ? ' checked' : '').'> <span>Auto</span></label>';
	}

	/**
	 * Submits the next batch form after users have a moment to opt out.
	 *
	 * @return string Inline script for batch result pages.
	 */
	private static function auto_batch_script(): string {
		return <<<'HTML'
<script>
(()=> {
	const form=document.querySelector('form[data-fd-auto-batch="1"]');
	if(!form || form.dataset.fdAutoStarted==="1"){return;}
	form.dataset.fdAutoStarted="1";
	window.setTimeout(()=> {
		const checkbox=form.querySelector('input[type="checkbox"][name="auto_batch"]');
		if(!document.body.contains(form) || (checkbox && !checkbox.checked)){return;}
		form.submit();
	}, 1200);
})();
</script>
HTML;
	}

	/**
	 * Routes POST actions submitted from project-specific DataDoc pages.
	 *
	 * Supported actions continue project indexing, synchronize the stale queue,
	 * or synchronize a single file. File synchronization is constrained to the
	 * registered project path before DataDoc receives the request, preventing a
	 * crafted form from indexing files outside the project boundary. CSRF
	 * validation runs when the Flightdeck auth layer is loaded.
	 *
	 * @param array<string,mixed> $project DataDoc project record for the current page.
	 * @return string Notice HTML describing the action result, or an empty string for non-POST requests.
	 */
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
			return self::run_sync_batch($project_name, 'fd_datadoc_action');
		}
		if($action==='refresh_project'){
			$path=self::validated_project_path((string)($project['path'] ?? ''));
			if($path===null){
				return '<div class="fd-alert">Invalid DataDoc project refresh request.</div>';
			}
			$cursor=self::validated_project_cursor((string)($_POST['cursor'] ?? ''), $path);
			return self::run_index_batch($project_name, $path, $cursor, 'fd_datadoc_action');
		}
		if($action==='continue_index'){
			$path=self::validated_project_path((string)($_POST['path'] ?? ''));
			if($path===null){
				return '<div class="fd-alert">Invalid DataDoc index continuation request.</div>';
			}
			return self::run_index_batch($project_name, $path, (string)($_POST['cursor'] ?? ''), 'fd_datadoc_action');
		}
		return '<div class="fd-alert">Unsupported DataDoc project action.</div>';
	}

	/**
	 * Builds a DataDoc project URL within Flightdeck.
	 *
	 * The project key is rawurl-encoded and optional suffixes are appended as
	 * route fragments. Callers pass trusted internal suffixes such as
	 * `settings`, `dynadoc`, or `manudoc/...`.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $suffix Optional route suffix after the project key.
	 * @return string Flightdeck-relative DataDoc project URL.
	 */
	private static function project_url(string $project, string $suffix=''): string {
		$url='/dataphyre/datadoc/'.rawurlencode($project);
		if($suffix!==''){
			$url.='/'.ltrim($suffix, '/');
		}
		return $url;
	}

	/**
	 * Builds a URL that reselects a dynamic documentation record.
	 *
	 * The query shape mirrors `matching_dynamic_records()`: namespaces and
	 * classes are always included, while functions and variables add the fields
	 * needed to disambiguate symbols with similar names.
	 *
	 * @param string $project DataDoc project key.
	 * @param array<string,mixed> $record Indexed DataDoc record.
	 * @return string Flightdeck-relative URL for the dynamic record view.
	 */
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

	/**
	 * Builds a URL for a manual documentation path.
	 *
	 * Each path segment is encoded independently so nested manual documents keep
	 * their slash hierarchy without allowing raw path fragments into the route.
	 *
	 * @param string $project DataDoc project key.
	 * @param string $path DataDoc manual document path.
	 * @return string Flightdeck-relative URL for the manual document.
	 */
	private static function manudoc_url(string $project, string $path): string {
		$segments=array_filter(explode('/', trim($path, '/')), static fn($segment)=>$segment!=='');
		return self::project_url($project, 'manudoc/'.implode('/', array_map('rawurlencode', $segments)));
	}

	/**
	 * Builds the hidden Flightdeck CSRF input when authentication is loaded.
	 *
	 * Some diagnostic contexts load the surface without the auth helper; in that
	 * case this returns an empty string and POST handlers skip CSRF checks
	 * consistently with the rest of Flightdeck.
	 *
	 * @return string Hidden CSRF input HTML or an empty string.
	 */
	private static function csrf_input(): string {
		return class_exists('dataphyre_flightdeck_auth', false)
			? '<input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'">'
			: '';
	}

	/**
	 * Returns the CSS used by the embedded DataDoc Flightdeck surface.
	 *
	 * The stylesheet scopes layout, navigation, forms, progress bars, manual
	 * document bodies, and highlighted source blocks under `fd-` classes so it
	 * can coexist with the broader Flightdeck shell and the DataDoc highlighter
	 * output.
	 *
	 * @return string CSS served through the Flightdeck asset endpoint.
	 */
	private static function style(): string {
		return '
.fd-datadoc-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:20px;align-items:start}
.fd-datadoc-layout>aside,.fd-datadoc-layout>div{min-width:0}
.fd-datadoc-layout .fd-card,.fd-datadoc-layout .fd-table-wrap{min-width:0;max-width:100%;overflow:hidden}
.fd-datadoc-index-table table{table-layout:fixed}
.fd-datadoc-index-table th:nth-child(1),.fd-datadoc-index-table td:nth-child(1){width:23%}
.fd-datadoc-index-table th:nth-child(2),.fd-datadoc-index-table td:nth-child(2){width:34%}
.fd-datadoc-index-table th:nth-child(3),.fd-datadoc-index-table td:nth-child(3){width:15%}
.fd-datadoc-index-table th:nth-child(4),.fd-datadoc-index-table td:nth-child(4){width:28%}
.fd-datadoc-index-table td{overflow-wrap:anywhere}
.fd-datadoc-index-table .fd-table-wrap{overflow-x:hidden}
.fd-doc-nav{display:grid;gap:8px}
.fd-doc-nav h3{font-size:.86rem;text-transform:uppercase;letter-spacing:.12em;color:#64748b;margin:18px 0 4px}
.fd-doc-nav a{display:block;text-decoration:none;border:1px solid #dbe4ef;border-radius:14px;padding:10px 12px;background:#fff;color:#0f172a}
.fd-doc-nav a:hover{border-color:#7dd3fc;background:#eef8ff}
.fd-doc-nav,.fd-doc-nav>*{min-width:0;max-width:100%;overflow-wrap:anywhere}
.fd-doc-nav .fd-doc-nav-secondary{font-size:.92rem;font-weight:800;background:#f8fafc;color:#0369a1}
.fd-scope-tree{display:grid;gap:4px;min-width:0;max-width:100%;overflow:hidden}
.fd-scope-tree,.fd-scope-tree *{overflow-wrap:normal}
.fd-scope-tree .menu-item{min-width:0;max-width:100%;overflow:hidden}
.fd-scope-tree .datadoc-menu-toggle,.fd-scope-tree .menu-item>a{display:flex;align-items:center;gap:8px;width:100%;min-width:0;max-width:100%;border:1px solid transparent;border-radius:10px;padding:7px 9px;background:transparent;color:#172033!important;text-decoration:none;font-size:.93rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fd-scope-tree .menu-item>a span,.fd-scope-tree .menu-item>a b,.fd-scope-tree .menu-item>a i{min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:inherit!important}
.fd-scope-tree .fd-scope-symbol-label{display:inline-flex;align-items:center;gap:7px;min-width:0;max-width:100%;overflow:hidden}
.fd-scope-tree .fd-scope-kind-pill{flex:0 0 auto;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8!important;font-size:.68rem;font-weight:900;line-height:1;padding:3px 6px;text-transform:uppercase}
.fd-scope-tree .fd-scope-symbol-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fd-scope-tree .datadoc-menu-toggle:hover,.fd-scope-tree .menu-item>a:hover{border-color:#bae6fd;background:#f0f9ff}
.fd-scope-tree .datadoc-menu-toggle:focus-visible,.fd-scope-tree .menu-item>a:focus-visible{outline:2px solid #38bdf8;outline-offset:2px}
.fd-scope-tree .datadoc-current-record{border-color:#38bdf8!important;background:#e0f2fe!important;color:#075985!important;font-weight:900}
.fd-scope-tree .datadoc-menu-toggle:before{content:"+";display:inline-grid;place-items:center;flex:0 0 18px;width:18px;height:18px;border-radius:6px;background:#e0f2fe;color:#0369a1;font-weight:900}
.fd-scope-tree .datadoc-menu-toggle[aria-expanded=true]:before{content:"-"}
.fd-scope-tree .datadoc-lazy-branch{display:none;margin:3px 0 5px}
.fd-scope-tree .datadoc-lazy-branch.show{display:block}
.fd-scope-loading,.fd-scope-error{margin:4px 0;padding:8px 10px;border-radius:10px;background:#f8fafc;color:#64748b;font-size:.9rem}
.fd-scope-error{border:1px solid #fecaca;background:#fff1f2;color:#be123c}
.fd-inline-form{display:inline-flex;gap:8px;align-items:center;margin:0}
.fd-action-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fd-batch-control{display:inline-flex;align-items:center;gap:8px;flex-wrap:nowrap}
.fd-batch-control button{white-space:nowrap}
.fd-auto-toggle{display:inline-flex;align-items:center;gap:7px;min-height:38px;border:1px solid #bae6fd;border-radius:999px;background:#f0f9ff;color:#075985;font-weight:900;padding:0 12px;white-space:nowrap;cursor:pointer}
.fd-auto-toggle input[type=checkbox]{width:16px;height:16px;margin:0;accent-color:#0284c7}
.fd-secondary{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:0;background:#e0f2fe;color:#075985}
.fd-management-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.fd-management-form label{display:grid;gap:7px;color:#334155;font-weight:800}
.fd-management-form input[type=text],.fd-management-form input:not([type]){width:100%;border:1px solid #dbe4ef;border-radius:14px;padding:12px 13px;font:inherit}
.fd-management-form .fd-wide,.fd-management-form .fd-check,.fd-management-form .fd-form-actions{grid-column:1/-1}
.fd-management-form .fd-check{display:flex;align-items:center;gap:10px;font-weight:700;color:#64748b}
.fd-form-actions{display:flex;gap:10px;align-items:center}
.fd-datadoc-filter-form{display:grid;grid-template-columns:minmax(220px,1fr) minmax(160px,220px) auto;gap:12px;align-items:end;margin:0 0 16px}
.fd-datadoc-filter-form label{display:grid;gap:7px;color:#334155;font-weight:800}
.fd-datadoc-filter-form input,.fd-datadoc-filter-form select{width:100%;border:1px solid #dbe4ef;border-radius:14px;padding:11px 12px;font:inherit;background:#fff;color:#0f172a}
.fd-empty-state{border:1px dashed #bae6fd;border-radius:20px;background:#f0f9ff;padding:22px}
.fd-empty-state h2{margin:0 0 8px;color:#075985}
.fd-empty-state p{margin:0;color:#475569}
.fd-progress{height:14px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:0 0 16px}
.fd-progress span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#14b8a6,#38bdf8)}
.fd-continue-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:14px 0 18px}
.fd-doc-body{line-height:1.65;color:#172033}
.fd-path{overflow-wrap:anywhere}
.fd-doc-body pre,.fd-datadoc-code [id^=codeContainer]{background:#07111f!important;color:#f8fafc!important;border-radius:18px!important;border:1px solid rgba(125,211,252,.18)!important;box-shadow:none!important}
.fd-datadoc-code{background:#030712;border-radius:20px;padding:12px;overflow:auto}
.fd-datadoc-code a{color:#bae6fd}
@media(max-width:1100px){.fd-datadoc-layout,.fd-management-form{grid-template-columns:1fr}}
@media(max-width:900px){.fd-datadoc-filter-form{grid-template-columns:1fr}}
@media(max-width:900px){.fd-datadoc-index-table table,.fd-datadoc-index-table thead,.fd-datadoc-index-table tbody,.fd-datadoc-index-table tr,.fd-datadoc-index-table th,.fd-datadoc-index-table td{display:block;width:100%}.fd-datadoc-index-table thead{display:none}.fd-datadoc-index-table tr{padding:12px 0;border-bottom:1px solid #dbe4ef}.fd-datadoc-index-table tr:last-child{border-bottom:0}.fd-datadoc-index-table td{border-bottom:0;padding:7px 12px}.fd-datadoc-index-table td:nth-child(1),.fd-datadoc-index-table td:nth-child(2),.fd-datadoc-index-table td:nth-child(3),.fd-datadoc-index-table td:nth-child(4){width:100%}}
';
	}

	/**
	 * Escapes a value using the shared Flightdeck view helper.
	 *
	 * Keeping escaping behind this local helper makes the renderer easier for
	 * DataDoc to summarize while preserving one canonical HTML-escaping policy
	 * for the surface.
	 *
	 * @param string $value Raw display value.
	 * @return string HTML-escaped value.
	 */
	private static function e(string $value): string {
		return dataphyre_flightdeck_view::e($value);
	}
}

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
	dataphyre_flightdeck_datadoc_surface::dispatch();
}
