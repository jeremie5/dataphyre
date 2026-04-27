<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Dataphyre Flightdeck control plane.
 *
 * Loaded by Flightdeck routes only.
 */

$flightdeck_auth_file=__DIR__.'/auth.php';
$flightdeck_debugbar_file=__DIR__.'/debugbar.php';
$flightdeck_view_file=__DIR__.'/view.php';
$flightdeck_stack_snippets_file=__DIR__.'/stack_snippets.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}
if(is_file($flightdeck_debugbar_file)){
	require_once($flightdeck_debugbar_file);
}
if(is_file($flightdeck_view_file)){
	require_once($flightdeck_view_file);
}
if(is_file($flightdeck_stack_snippets_file)){
	require_once($flightdeck_stack_snippets_file);
}

final class dataphyre_flightdeck {

	private const LOG_ENTRY_DELIMITER='<!--ENDLOG-->';
	private const LOG_INITIAL_ENTRY_LIMIT=40;
	private const LOG_POLL_ENTRY_LIMIT=20;
	private const LOG_INITIAL_TAIL_BYTES=131072;
	private const LOG_MAX_INITIAL_TAIL_BYTES=1048576;

	public static function dispatch(): void {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			http_response_code(503);
			echo 'Flightdeck installation is incomplete.';
			return;
		}
		if(dataphyre_flightdeck_auth::production_disabled()===true){
			http_response_code(404);
			echo 'Not found';
			return;
		}
		if(dataphyre_flightdeck_auth::enabled()!==true){
			http_response_code(404);
			echo 'Flightdeck is disabled.';
			return;
		}
		$route=self::route();
		if($route==='login'){
			self::handle_login();
			return;
		}
		if($route==='logout'){
			dataphyre_flightdeck_auth::logout();
			header('Location: /dataphyre/login');
			return;
		}
		if(dataphyre_flightdeck_auth::authenticated()!==true){
			dataphyre_flightdeck_auth::redirect_to_login();
		}
		if($route==='logs' && self::is_log_ajax_request()){
			self::render_log_poll_response();
			return;
		}
		if($route==='debugbar'){
			self::handle_debugbar();
			return;
		}
		self::render($route);
	}

	private static function is_log_ajax_request(): bool {
		return ($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && (string)($_POST['ajax'] ?? '')==='1';
	}

	private static function render_log_poll_response(): void {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		if((string)($_POST['action'] ?? '')==='render_snippets'){
			self::render_log_snippet_response();
			return;
		}
		$render_stacks=false;
		$info=self::latest_log_file_info();
		if($info===null){
			echo self::json_payload([
				'ok'=>true,
				'available'=>false,
				'reset'=>true,
				'file_name'=>'',
				'file_path'=>'',
				'file_size'=>0,
				'file_size_label'=>'0 B',
				'file_key'=>'',
				'offset'=>0,
				'entries'=>[],
				'has_more'=>false,
				'message'=>'No log files were found in the app or shared Dataphyre log directories.',
			]);
			return;
		}
		$client_file_key=(string)($_POST['file_key'] ?? '');
		$offset=max(0, (int)($_POST['offset'] ?? 0));
		if($client_file_key!==$info['key'] || $offset>(int)$info['size']){
			$recent=self::recent_log_entries($info, $render_stacks);
			echo self::json_payload([
				'ok'=>true,
				'available'=>true,
				'reset'=>true,
				'file_name'=>$info['name'],
				'file_path'=>$info['path'],
				'file_size'=>$info['size'],
				'file_size_label'=>self::format_bytes((int)$info['size']),
				'file_key'=>$info['key'],
				'offset'=>$recent['offset'],
				'entries'=>$recent['entries'],
				'has_more'=>false,
				'message'=>'',
			]);
			return;
		}
		$poll=self::poll_log_entries($info, $offset, $render_stacks);
		echo self::json_payload([
			'ok'=>true,
			'available'=>true,
			'reset'=>false,
			'file_name'=>$info['name'],
			'file_path'=>$info['path'],
			'file_size'=>$info['size'],
			'file_size_label'=>self::format_bytes((int)$info['size']),
			'file_key'=>$info['key'],
			'offset'=>$poll['offset'],
			'entries'=>$poll['entries'],
			'has_more'=>$poll['has_more'],
			'message'=>'',
		]);
	}

	private static function render_log_snippet_response(): void {
		if(dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)!==true){
			echo self::json_payload([
				'ok'=>false,
				'message'=>'Invalid form token.',
				'html'=>'',
			]);
			return;
		}
		$entry=(string)($_POST['entry'] ?? '');
		$html=self::render_log_stack_panel($entry);
		if($html===''){
			echo self::json_payload([
				'ok'=>false,
				'message'=>'No stack trace or smart diagnostics were detected for this log entry.',
				'html'=>'',
			]);
			return;
		}
		echo self::json_payload([
			'ok'=>true,
			'message'=>'',
			'html'=>$html,
		]);
	}

	private static function json_payload(array $payload): string {
		$flags=JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
		$json=json_encode($payload, $flags);
		return is_string($json) ? $json : '{"ok":false,"message":"Unable to encode Flightdeck log response."}';
	}

	private static function handle_login(): void {
		if(dataphyre_flightdeck_auth::production_disabled()===true){
			http_response_code(404);
			echo 'Not found';
			return;
		}
		if(dataphyre_flightdeck_auth::enabled()!==true){
			http_response_code(404);
			echo 'Flightdeck is disabled.';
			return;
		}
		if(dataphyre_flightdeck_auth::authenticated()===true && dataphyre_flightdeck_auth::auth_required()===true){
			header('Location: '.self::safe_return_url());
			return;
		}
		$error=null;
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
			if(dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)!==true){
				$error='Invalid form token.';
			}
			elseif(dataphyre_flightdeck_auth::login((string)($_POST['password'] ?? ''))===true){
				header('Location: '.self::safe_return_url());
				return;
			}
			else
			{
				$error=dataphyre_flightdeck_auth::login_error() ?? 'Invalid Flightdeck password.';
			}
		}
		echo self::layout('Login', self::login_page($error), 'login');
	}

	private static function handle_debugbar(): void {
		if(class_exists('dataphyre_flightdeck_debugbar', false)!==true){
			self::render('debugbar');
			return;
		}
		$action=(string)($_GET['action'] ?? '');
		if($action==='enable'){
			dataphyre_flightdeck_debugbar::enable();
			header('Location: /dataphyre');
			return;
		}
		if($action==='disable'){
			dataphyre_flightdeck_debugbar::disable();
			header('Location: /dataphyre');
			return;
		}
		self::render('debugbar');
	}

	private static function render(string $route): void {
		$title=match($route){
			'logs'=>'Logs',
			'modules'=>'Modules',
			'flight-sheet'=>'Flight Sheet',
			'debugbar'=>'Runtime Toolbar',
			default=>'Dashboard',
		};
		$content=match($route){
			'logs'=>self::logs_page(),
			'modules'=>self::modules_page(),
			'flight-sheet'=>self::flight_sheet_page(),
			'debugbar'=>self::debugbar_page(),
			default=>self::dashboard_page(),
		};
		echo self::layout($title, $content, $route);
	}

	private static function route(): string {
		$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/dataphyre'), PHP_URL_PATH);
		$path=trim($path, '/');
		if($path==='dataphyre'){
			return 'dashboard';
		}
		if(str_starts_with($path, 'dataphyre/')){
			$path=substr($path, strlen('dataphyre/'));
		}
		$segment=strtok($path, '/') ?: 'dashboard';
		return match($segment){
			'login'=>'login',
			'logout'=>'logout',
			'logs'=>'logs',
			'modules'=>'modules',
			'flight-sheet'=>'flight-sheet',
			'debugbar'=>'debugbar',
			default=>'dashboard',
		};
	}

	private static function dashboard_page(): string {
		$debugbar_enabled=self::debugbar_enabled();
		$debugbar_action=$debugbar_enabled
			? '<a class="fd-danger" href="/dataphyre/debugbar?action=disable">Disable Toolbar</a>'
			: '<a class="fd-primary" href="/dataphyre/debugbar?action=enable">Enable Toolbar</a>';
		$cards='';
		$cards.=self::metric_card('Application', defined('APP') ? APP : 'unknown', 'Active runtime application.');
		$cards.=self::metric_card('Environment', defined('IS_PRODUCTION') && IS_PRODUCTION===true ? 'production' : 'development', 'Flightdeck is disabled in production.');
		$cards.=self::metric_card('Modules', (string)count(self::module_rows()), 'Installed runtime module directories.');
		$cards.=self::metric_card('Runtime Toolbar', $debugbar_enabled ? 'enabled' : 'disabled', 'Session-scoped diagnostics overlay.');
		$links='';
		foreach(self::module_interface_links() as $link){
			$links.='<a class="fd-link-card" href="'.$link['href'].'"><b>'.$link['title'].'</b><span>'.$link['description'].'</span></a>';
		}
		return '<section class="fd-hero"><div><p class="fd-kicker">Dataphyre Flightdeck</p><h1>Runtime operations console</h1><p>Secure diagnostics, module interfaces, logs, and flight-sheet visibility for Dataphyre runtimes.</p></div>'.$debugbar_action.'</section><section class="fd-metrics">'.$cards.'</section><section class="fd-card"><div class="fd-section-title"><h2>Control Pages</h2><a href="/dataphyre/modules">View modules</a></div><div class="fd-link-grid">'.$links.'</div></section>';
	}

	private static function logs_page(): string {
		$info=self::latest_log_file_info();
		if($info===null){
			$file_label='No log files were found in the app or shared Dataphyre log directories.';
			$size_label='0 B';
		}
		else
		{
			$file_label=(string)$info['path'];
			$size_label=self::format_bytes((int)$info['size']);
		}
		return '<section class="fd-card fd-logs" id="fd-logs" data-csrf="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'"><style>'.self::logs_css().'</style><div class="fd-section-title"><div><h1>Logs</h1><p class="fd-muted" id="fd-log-file">'.self::e($file_label).'</p></div><div class="fd-log-controls"><span class="fd-log-status" id="fd-log-status" data-tone="busy">Connecting...</span><span class="fd-pill" id="fd-log-size">'.self::e($size_label).'</span><button class="fd-log-button" id="fd-log-pause-toggle" type="button">Pause Logs</button></div></div><p class="fd-muted">Live tailing uses bounded batches and pauses while you select text. Eligible entries expose their own smart-snippet button, so source files are read only for the log you expand.</p><div class="fd-table-wrap"><table class="fd-log-table"><thead><tr><th>Time</th><th>Entry</th></tr></thead><tbody id="fd-log-content"><tr class="fd-log-placeholder"><td colspan="2">Waiting for log events...</td></tr></tbody></table></div>'.self::logs_script().'</section>';
	}

	private static function modules_page(): string {
		$rows='';
		foreach(self::module_rows() as $module){
			$link=$module['link'] ? '<a href="'.$module['link'].'">Open</a>' : '<span class="fd-muted">No page</span>';
			$rows.='<tr><td><b>'.self::e($module['name']).'</b></td><td>'.self::e($module['source']).'</td><td>'.self::e($module['version']).'</td><td>'.$link.'</td></tr>';
		}
		return '<section class="fd-card"><h1>Modules</h1><p class="fd-muted">Core is assumed available; every other module is discovered from installed module directories only when Flightdeck is opened.</p><div class="fd-table-wrap"><table><thead><tr><th>Module</th><th>Source</th><th>Version</th><th>Interface</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
	}

	private static function flight_sheet_page(): string {
		$path=self::install_root().'flight_sheet.php';
		if(!is_file($path)){
			return '<section class="fd-card"><h1>Flight Sheet</h1><p class="fd-muted">No flight sheet found at '.self::e($path).'.</p></section>';
		}
		$sheet=require($path);
		if(!is_array($sheet)){
			$sheet=[];
		}
		$sanitized=self::sanitize_config($sheet);
		return '<section class="fd-card"><div class="fd-section-title"><div><h1>Flight Sheet</h1><p class="fd-muted">'.self::e($path).'</p></div><span class="fd-pill">'.count($sanitized).' top-level keys</span></div><pre class="fd-code">'.self::e(var_export($sanitized, true)).'</pre></section>';
	}

	private static function debugbar_page(): string {
		$enabled=self::debugbar_enabled();
		$state=self::debugbar_state();
		$state_json=json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if(!is_string($state_json)){
			$state_json='{}';
		}
		$action=$enabled
			? '<a class="fd-danger" href="/dataphyre/debugbar?action=disable">Disable Toolbar</a>'
			: '<a class="fd-primary" href="/dataphyre/debugbar?action=enable">Enable Toolbar</a>';
		return '<section class="fd-card"><div class="fd-section-title"><div><h1>Runtime Toolbar</h1><p class="fd-muted">A session-scoped diagnostics overlay for authenticated Flightdeck users.</p></div>'.$action.'</div><pre class="fd-code">'.self::e($state_json).'</pre></section>';
	}

	private static function login_page(?string $error): string {
		if(dataphyre_flightdeck_auth::auth_required()===false){
			return '<section class="fd-card fd-login"><p class="fd-kicker">Dataphyre Flightdeck</p><h1>Console password required</h1><p class="fd-muted">Flightdeck is installed, so Dataphyre control pages require a configured console password.</p></section>';
		}
		$error_html=$error!==null ? '<div class="fd-alert">'.self::e($error).'</div>' : '';
		return '<section class="fd-card fd-login"><p class="fd-kicker">Dataphyre Flightdeck</p><h1>Console Access</h1><p class="fd-muted">Enter the configured Flightdeck password to continue.</p>'.$error_html.'<form method="post"><input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'"><input type="password" name="password" placeholder="Flightdeck password" autofocus><button type="submit">Open Flightdeck</button></form></section>';
	}

	private static function layout(string $title, string $content, string $active): string {
		if(class_exists('dataphyre_flightdeck_view', false)){
			return dataphyre_flightdeck_view::layout($title, $content, $active);
		}
		http_response_code(503);
		return 'Flightdeck view renderer is unavailable.';
	}

	private static function metric_card(string $label, string $value, string $hint): string {
		return '<article class="fd-metric"><span>'.$label.'</span><b>'.self::e($value).'</b><p>'.self::e($hint).'</p></article>';
	}

	private static function module_interface_links(): array {
		$links=[
			['module'=>'flightdeck', 'title'=>'Flightdeck Logs', 'href'=>'/dataphyre/logs', 'description'=>'Runtime log review in the Flightdeck visual system.'],
			['module'=>'flightdeck', 'title'=>'Flight Sheet', 'href'=>'/dataphyre/flight-sheet', 'description'=>'Inspect bootstrap and install directives with sensitive values redacted.'],
			['module'=>'flightdeck', 'title'=>'Runtime Toolbar', 'href'=>'/dataphyre/debugbar', 'description'=>'Enable or disable the authenticated diagnostics overlay.'],
		];
		foreach([
			'sql'=>['SQL Endpoint', '/dataphyre/sql', 'Database utility endpoint.'],
			'datadoc'=>['DataDoc', '/dataphyre/datadoc', 'Documentation browser and code index.'],
			'tracelog'=>['Tracelog', '/dataphyre/tracelog', 'Runtime trace viewer.'],
			'dpanel'=>['Dpanel', '/dataphyre/dpanel', 'Dynamic diagnostics panel.'],
			'health_report'=>['Health Report', '/dataphyre/health_report', 'Runtime health report.'],
		] as $module=>$data){
			if(self::module_exists($module)){
				$links[]=['module'=>$module, 'title'=>$data[0], 'href'=>$data[1], 'description'=>$data[2]];
			}
		}
		return $links;
	}

	private static function module_rows(): array {
		$modules=[];
		foreach([
			'common'=>self::runtime_root().'modules/',
			'app'=>defined('ROOTPATH') && !empty(ROOTPATH['dataphyre']) ? rtrim((string)ROOTPATH['dataphyre'], '/\\').'/modules/' : null,
		] as $source=>$root){
			if(!is_string($root) || !is_dir($root)){
				continue;
			}
			foreach(scandir($root) ?: [] as $entry){
				if($entry==='.' || $entry==='..' || $entry[0]==='-'){
					continue;
				}
				$directory=rtrim($root, '/\\').'/'.$entry.'/';
				if(!is_dir($directory)){
					continue;
				}
				$modules[$entry]=[
					'name'=>$entry,
					'source'=>isset($modules[$entry]) ? $modules[$entry]['source'].', '.$source : $source,
					'version'=>is_file($directory.'version') ? trim((string)file_get_contents($directory.'version')) : '1.0',
					'link'=>self::module_link($entry),
				];
			}
		}
		ksort($modules);
		return array_values($modules);
	}

	private static function module_link(string $module): ?string {
		foreach(self::module_interface_links() as $link){
			if($link['module']===$module){
				return $link['href'];
			}
		}
		return null;
	}

	private static function module_exists(string $module): bool {
		return is_dir(self::runtime_root().'modules/'.$module.'/')
			|| (defined('ROOTPATH') && !empty(ROOTPATH['dataphyre']) && is_dir(ROOTPATH['dataphyre'].'modules/'.$module.'/'));
	}

	private static function debugbar_enabled(): bool {
		return class_exists('dataphyre_flightdeck_debugbar', false)
			&& dataphyre_flightdeck_debugbar::enabled()===true;
	}

	private static function debugbar_state(): array {
		if(class_exists('dataphyre_flightdeck_debugbar', false)){
			return dataphyre_flightdeck_debugbar::state();
		}
		return [
			'available'=>false,
			'enabled'=>false,
		];
	}

	private static function latest_log_file_info(): ?array {
		$latest=null;
		foreach(self::log_directories() as $directory){
			foreach(glob(rtrim($directory, '/\\').'/*.{html,log,txt}', GLOB_BRACE) ?: [] as $file){
				if(!is_file($file) || !is_readable($file)){
					continue;
				}
				$mtime=filemtime($file) ?: 0;
				if($latest===null || $mtime>$latest['mtime']){
					$latest=[
						'path'=>$file,
						'name'=>basename($file),
						'extension'=>strtolower(pathinfo($file, PATHINFO_EXTENSION)),
						'mtime'=>$mtime,
						'size'=>filesize($file) ?: 0,
					];
				}
			}
		}
		if($latest!==null){
			$latest['key']=self::log_file_key($latest);
		}
		return $latest;
	}

	private static function log_file_key(array $info): string {
		$inode=@fileinode((string)$info['path']);
		return sha1((string)$info['path'].'|'.(is_int($inode) ? (string)$inode : 'unknown'));
	}

	private static function poll_log_entries(array $info, int $offset, bool $render_stacks): array {
		return self::is_html_log($info)
			? self::poll_html_log_entries($info, $offset, $render_stacks)
			: self::poll_plain_log_entries($info, $offset, $render_stacks);
	}

	private static function recent_log_entries(array $info, bool $render_stacks): array {
		return self::is_html_log($info)
			? self::recent_html_log_entries($info, $render_stacks)
			: self::recent_plain_log_entries($info, $render_stacks);
	}

	private static function recent_html_log_entries(array $info, bool $render_stacks): array {
		if((int)$info['size']<=0){
			return ['entries'=>[], 'offset'=>0];
		}
		$window=self::tail_log_window((string)$info['path'], (int)$info['size'], self::LOG_INITIAL_ENTRY_LIMIT + 1, self::LOG_ENTRY_DELIMITER);
		$entries=self::parse_tail_html_log_entries($window['segment'], $window['start']>0, $render_stacks);
		if(count($entries)>self::LOG_INITIAL_ENTRY_LIMIT){
			$entries=array_slice($entries, -self::LOG_INITIAL_ENTRY_LIMIT);
		}
		return [
			'entries'=>array_values($entries),
			'offset'=>self::complete_log_offset_from_tail($window['segment'], $window['start'], (int)$info['size'], self::LOG_ENTRY_DELIMITER),
		];
	}

	private static function recent_plain_log_entries(array $info, bool $render_stacks): array {
		if((int)$info['size']<=0){
			return ['entries'=>[], 'offset'=>0];
		}
		$window=self::tail_log_window((string)$info['path'], (int)$info['size'], self::LOG_INITIAL_ENTRY_LIMIT + 1, "\n");
		$entries=self::parse_tail_plain_log_entries($window['segment'], $window['start']>0, $render_stacks);
		if(count($entries)>self::LOG_INITIAL_ENTRY_LIMIT){
			$entries=array_slice($entries, -self::LOG_INITIAL_ENTRY_LIMIT);
		}
		return [
			'entries'=>array_values($entries),
			'offset'=>self::complete_log_offset_from_tail($window['segment'], $window['start'], (int)$info['size'], "\n"),
		];
	}

	private static function poll_html_log_entries(array $info, int $offset, bool $render_stacks): array {
		$offset=max(0, min($offset, (int)$info['size']));
		$chunk=self::read_complete_log_chunk((string)$info['path'], $offset, self::LOG_ENTRY_DELIMITER);
		if($chunk===''){
			return ['entries'=>[], 'offset'=>$offset, 'has_more'=>false];
		}
		$selection=self::take_html_log_entries_from_chunk($chunk, self::LOG_POLL_ENTRY_LIMIT, $render_stacks);
		return [
			'entries'=>$selection['entries'],
			'offset'=>$offset + $selection['bytes'],
			'has_more'=>$selection['bytes']<strlen($chunk),
		];
	}

	private static function poll_plain_log_entries(array $info, int $offset, bool $render_stacks): array {
		$offset=max(0, min($offset, (int)$info['size']));
		$chunk=self::read_complete_log_chunk((string)$info['path'], $offset, "\n");
		if($chunk===''){
			return ['entries'=>[], 'offset'=>$offset, 'has_more'=>false];
		}
		$selection=self::take_plain_log_entries_from_chunk($chunk, self::LOG_POLL_ENTRY_LIMIT, $render_stacks);
		return [
			'entries'=>$selection['entries'],
			'offset'=>$offset + $selection['bytes'],
			'has_more'=>$selection['bytes']<strlen($chunk),
		];
	}

	private static function is_html_log(array $info): bool {
		return (string)($info['extension'] ?? '')==='html';
	}

	private static function tail_log_window(string $path, int $size, int $minimum_breaks, string $break_marker): array {
		$bytes=min($size, self::LOG_INITIAL_TAIL_BYTES);
		$start=max(0, $size - $bytes);
		$segment='';
		while(true){
			$start=max(0, $size - $bytes);
			$segment=self::read_log_bytes($path, $start, $bytes);
			if(
				$start===0
				|| $bytes>=$size
				|| $bytes>=self::LOG_MAX_INITIAL_TAIL_BYTES
				|| self::log_break_count($segment, $break_marker)>=$minimum_breaks
			){
				break;
			}
			$bytes=min($size, $bytes * 2);
		}
		return ['segment'=>$segment, 'start'=>$start];
	}

	private static function log_break_count(string $segment, string $break_marker): int {
		if($segment===''){
			return 0;
		}
		return $break_marker===self::LOG_ENTRY_DELIMITER
			? substr_count($segment, self::LOG_ENTRY_DELIMITER)
			: substr_count($segment, "\n");
	}

	private static function complete_log_offset_from_tail(string $segment, int $start, int $size, string $break_marker): int {
		if($segment===''){
			return 0;
		}
		if($break_marker===self::LOG_ENTRY_DELIMITER){
			if(self::ends_with($segment, self::LOG_ENTRY_DELIMITER)){
				return $size;
			}
			$last_break=strrpos($segment, self::LOG_ENTRY_DELIMITER);
			return $last_break===false ? $start : $start + $last_break + strlen(self::LOG_ENTRY_DELIMITER);
		}
		if(self::ends_with_newline($segment)){
			return $size;
		}
		$last_break=self::last_newline_position($segment);
		return $last_break===null ? $start : $start + $last_break + 1;
	}

	private static function parse_tail_html_log_entries(string $segment, bool $drop_leading_partial, bool $render_stacks): array {
		if($segment===''){
			return [];
		}
		$pieces=explode(self::LOG_ENTRY_DELIMITER, $segment);
		if($drop_leading_partial && !empty($pieces)){
			array_shift($pieces);
		}
		if(self::ends_with($segment, self::LOG_ENTRY_DELIMITER)){
			array_pop($pieces);
		}
		elseif(!empty($pieces)){
			array_pop($pieces);
		}
		$entries=[];
		foreach($pieces as $piece){
			$entry=trim($piece);
			if($entry!==''){
				$entries[]=self::prepare_log_entry_html($entry, $render_stacks);
			}
		}
		if(empty($entries) && !$drop_leading_partial){
			$whole_entry=trim($segment);
			if($whole_entry!==''){
				$entries[]=self::prepare_log_entry_html($whole_entry, $render_stacks);
			}
		}
		return $entries;
	}

	private static function parse_tail_plain_log_entries(string $segment, bool $drop_leading_partial, bool $render_stacks): array {
		if($segment===''){
			return [];
		}
		$lines=preg_split("/\r\n|\n|\r/", $segment);
		if($drop_leading_partial && !empty($lines)){
			array_shift($lines);
		}
		if(self::ends_with_newline($segment)){
			array_pop($lines);
		}
		elseif(!empty($lines)){
			array_pop($lines);
		}
		$entries=[];
		foreach($lines as $line){
			if(trim((string)$line)===''){
				continue;
			}
			$entries[]=self::plain_log_line_row((string)$line, $render_stacks);
		}
		if(empty($entries) && !$drop_leading_partial){
			$line=trim($segment);
			if($line!==''){
				$entries[]=self::plain_log_line_row($line, $render_stacks);
			}
		}
		return $entries;
	}

	private static function read_complete_log_chunk(string $path, int $offset, string $break_marker): string {
		$chunk=self::read_log_bytes($path, $offset);
		if($chunk===''){
			return '';
		}
		if($break_marker===self::LOG_ENTRY_DELIMITER){
			if(self::ends_with($chunk, self::LOG_ENTRY_DELIMITER)){
				return $chunk;
			}
			$last_break=strrpos($chunk, self::LOG_ENTRY_DELIMITER);
			return $last_break===false ? '' : substr($chunk, 0, $last_break + strlen(self::LOG_ENTRY_DELIMITER));
		}
		if(self::ends_with_newline($chunk)){
			return $chunk;
		}
		$last_break=self::last_newline_position($chunk);
		return $last_break===null ? '' : substr($chunk, 0, $last_break + 1);
	}

	private static function take_html_log_entries_from_chunk(string $chunk, int $limit, bool $render_stacks): array {
		$pieces=explode(self::LOG_ENTRY_DELIMITER, $chunk);
		$entries=[];
		$consumed=0;
		$delimiter_length=strlen(self::LOG_ENTRY_DELIMITER);
		$total=count($pieces);
		for($index=0; $index<$total; $index++){
			$piece=$pieces[$index];
			if($index===($total - 1) && $piece===''){
				break;
			}
			$piece_bytes=strlen($piece) + $delimiter_length;
			$consumed += $piece_bytes;
			$entry=trim($piece);
			if($entry===''){
				continue;
			}
			$entries[]=self::prepare_log_entry_html($entry, $render_stacks);
			if(count($entries)>=$limit){
				break;
			}
		}
		return ['entries'=>$entries, 'bytes'=>$consumed];
	}

	private static function take_plain_log_entries_from_chunk(string $chunk, int $limit, bool $render_stacks): array {
		$entries=[];
		$cursor=0;
		$length=strlen($chunk);
		while($cursor<$length){
			$newline_position=self::next_newline_position($chunk, $cursor);
			if($newline_position===null){
				break;
			}
			$line=substr($chunk, $cursor, $newline_position['position'] - $cursor);
			$cursor=$newline_position['next_cursor'];
			if(trim($line)===''){
				continue;
			}
			$entries[]=self::plain_log_line_row($line, $render_stacks);
			if(count($entries)>=$limit){
				break;
			}
		}
		return ['entries'=>$entries, 'bytes'=>$cursor];
	}

	private static function next_newline_position(string $chunk, int $cursor): ?array {
		$length=strlen($chunk);
		for($index=$cursor; $index<$length; $index++){
			if($chunk[$index]==="\n"){
				return ['position'=>$index, 'next_cursor'=>$index + 1];
			}
			if($chunk[$index]==="\r"){
				$next_cursor=$index + 1;
				if($next_cursor<$length && $chunk[$next_cursor]==="\n"){
					$next_cursor++;
				}
				return ['position'=>$index, 'next_cursor'=>$next_cursor];
			}
		}
		return null;
	}

	private static function last_newline_position(string $segment): ?int {
		$linefeed=strrpos($segment, "\n");
		$carriage_return=strrpos($segment, "\r");
		if($linefeed===false && $carriage_return===false){
			return null;
		}
		if($linefeed===false){
			return $carriage_return;
		}
		if($carriage_return===false){
			return $linefeed;
		}
		return max($linefeed, $carriage_return);
	}

	private static function ends_with_newline(string $segment): bool {
		if($segment===''){
			return false;
		}
		$last_character=substr($segment, -1);
		return $last_character==="\n" || $last_character==="\r";
	}

	private static function ends_with(string $subject, string $suffix): bool {
		if($suffix===''){
			return true;
		}
		return substr($subject, -strlen($suffix))===$suffix;
	}

	private static function read_log_bytes(string $path, int $offset, ?int $length=null): string {
		if(!is_file($path) || !is_readable($path)){
			return '';
		}
		$contents=$length===null
			? @file_get_contents($path, false, null, $offset)
			: @file_get_contents($path, false, null, $offset, $length);
		return is_string($contents) ? $contents : '';
	}

	private static function plain_log_line_row(string $line, bool $render_stacks): string {
		$row='<tr><td colspan="2"><pre class="fd-log-line">'.self::e($line).'</pre></td></tr>';
		return $render_stacks ? self::append_log_stack_panel($row) : self::append_log_snippet_trigger($row);
	}

	private static function prepare_log_entry_html(string $entry, bool $render_stacks): string {
		return $render_stacks ? self::append_log_stack_panel($entry) : self::append_log_snippet_trigger($entry);
	}

	private static function append_log_stack_panel(string $entry): string {
		$panel=self::render_log_stack_panel($entry);
		if($panel===''){
			return $entry;
		}
		$count=0;
		$updated=preg_replace('/(<\/td>\s*<\/tr>\s*)$/i', $panel.'$1', $entry, 1, $count);
		if(is_string($updated) && $count>0){
			return $updated;
		}
		return $entry.$panel;
	}

	private static function append_log_snippet_trigger(string $entry): string {
		if(self::log_entry_has_smart_snippets($entry)!==true){
			return $entry;
		}
		$trigger='<div class="fd-log-snippet-tools"><button type="button" class="fd-log-snippet-button">Render Smart Snippets</button></div><div class="fd-log-snippet-panel" hidden></div>';
		$count=0;
		$updated=preg_replace('/(<\/td>\s*<\/tr>\s*)$/i', $trigger.'$1', $entry, 1, $count);
		return is_string($updated) && $count>0 ? $updated : $entry;
	}

	private static function log_entry_has_smart_snippets(string $entry): bool {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false) && dataphyre_flightdeck_stack_snippets::frames_from_log_entry($entry)!==[]){
			return true;
		}
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)!==true && self::stack_frames_from_log_entry($entry)!==[]){
			return true;
		}
		$text=html_entity_decode(strip_tags($entry), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return preg_match('/Failed opening (?:required|included)|(?:require|include)(?:_once)?\s*\(|Undefined (?:variable|constant|property)|Access to undeclared static property|Call to undefined (?:function|method)|Class [\'"][^\'"]+[\'"] not found/i', $text)===1;
	}

	private static function render_log_stack_panel(string $entry): string {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
			$frames=dataphyre_flightdeck_stack_snippets::frames_from_log_entry($entry);
			if($frames===[]){
				$diagnostics=dataphyre_flightdeck_stack_snippets::render_diagnostics($entry, [], [
					'compact'=>true,
					'title'=>'Smart Diagnostics',
				]);
				return $diagnostics==='' ? '' : '<details class="fd-log-stack" open><summary>Smart Diagnostics</summary>'.$diagnostics.'</details>';
			}
			return dataphyre_flightdeck_stack_snippets::render_panel($frames, [
				'details_class'=>'fd-log-stack',
				'summary'=>'Stack Trace Snippets',
				'id_prefix'=>'fd-log-frame-'.substr(hash('sha256', $entry), 0, 12).'-',
				'limit'=>12,
				'context_lines'=>6,
				'compact'=>true,
				'show_meta'=>false,
				'show_stack_links'=>false,
				'highlight_class'=>'fd-callsite-line',
				'diagnostic_text'=>$entry,
			]);
		}
		$frames=self::stack_frames_from_log_entry($entry);
		if($frames===[]){
			return '';
		}
		$html='<details class="fd-log-stack"><summary>Stack Trace Snippets <span>'.count($frames).' frame'.(count($frames)===1 ? '' : 's').'</span></summary>';
		foreach(array_slice($frames, 0, 12) as $frame){
			$html.=self::render_log_stack_frame($frame);
		}
		$html.='</details>';
		return $html;
	}

	private static function stack_frames_from_log_entry(string $entry): array {
		$text=html_entity_decode(strip_tags($entry), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if($text===''){
			return [];
		}
		preg_match_all('/(?:^|[\r\n]|(?:Stack Trace|Trace):[ \t]*)[ \t]*#(?P<index>\d+)\s+(?P<file>[^\r\n]+?\.php)\((?P<line>\d+)\):\s*(?P<call>[^\r\n]+)/i', $text, $matches, PREG_SET_ORDER);
		$frames=[];
		foreach($matches as $match){
			$file=trim((string)$match['file']);
			$line=(int)$match['line'];
			if($file==='' || $line<=0){
				continue;
			}
			$frames[]=[
				'index'=>(int)$match['index'],
				'file'=>$file,
				'line'=>$line,
				'call'=>trim((string)$match['call']),
			];
		}
		return $frames;
	}

	private static function render_log_stack_frame(array $frame): string {
		$file=(string)($frame['file'] ?? '');
		$line=(int)($frame['line'] ?? 0);
		$label='<div class="fd-log-frame-head"><b>#'.self::e((string)($frame['index'] ?? 0)).' '.self::e($file).':'.self::e((string)$line).'</b><span>'.self::e((string)($frame['call'] ?? '')).'</span></div>';
		if($file==='' || $line<=0 || !is_file($file) || !is_readable($file)){
			return '<article class="fd-log-frame">'.$label.'<p class="fd-muted">Source unavailable on this runtime.</p></article>';
		}
		$start=max(1, $line - 6);
		$lines=@file($file, FILE_IGNORE_NEW_LINES);
		if(!is_array($lines)){
			return '<article class="fd-log-frame">'.$label.'<p class="fd-muted">Source unreadable.</p></article>';
		}
		$selected=self::normalize_log_snippet_lines(array_slice($lines, $start - 1, 13, true));
		$code='<pre class="fd-log-snippet">';
		foreach($selected as $source_index=>$source_line){
			$current=$source_index + 1;
			$class=$current===$line ? ' class="fd-log-hit"' : '';
			$code.='<span'.$class.'><b>'.str_pad((string)$current, 5, ' ', STR_PAD_LEFT).'</b> '.self::e((string)$source_line).'</span>'."\n";
		}
		$code.='</pre>';
		return '<article class="fd-log-frame">'.$label.$code.'</article>';
	}

	private static function normalize_log_snippet_lines(array $lines): array {
		$common_prefix=null;
		foreach($lines as $line){
			if(trim((string)$line)===''){
				continue;
			}
			preg_match('/^[ \t]*/', (string)$line, $matches);
			$prefix=$matches[0] ?? '';
			if($common_prefix===null){
				$common_prefix=$prefix;
				continue;
			}
			$limit=min(strlen($common_prefix), strlen($prefix));
			$shared='';
			for($i=0; $i<$limit; $i++){
				if($common_prefix[$i]!==$prefix[$i]){
					break;
				}
				$shared.=$common_prefix[$i];
			}
			$common_prefix=$shared;
			if($common_prefix===''){
				break;
			}
		}
		if($common_prefix===null || $common_prefix===''){
			return $lines;
		}
		$prefix_length=strlen($common_prefix);
		foreach($lines as $index=>$line){
			$line=(string)$line;
			if(strncmp($line, $common_prefix, $prefix_length)===0){
				$lines[$index]=substr($line, $prefix_length);
			}
		}
		return $lines;
	}

	private static function logs_css(): string {
		return <<<'CSS'
.fd-logs .fd-section-title{align-items:flex-start}
.fd-log-controls{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.fd-log-status{display:inline-flex;align-items:center;border-radius:999px;padding:8px 11px;font-weight:900;background:#e0f2fe;color:#075985}
.fd-log-status[data-tone="live"]{background:#dcfce7;color:#166534}
.fd-log-status[data-tone="busy"]{background:#dbeafe;color:#1d4ed8}
.fd-log-status[data-tone="paused"]{background:#fef3c7;color:#92400e}
.fd-log-status[data-tone="error"]{background:#fee2e2;color:#991b1b}
.fd-log-button{display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(14,165,233,.28);border-radius:999px;padding:9px 12px;background:#fff;color:#075985;font-weight:900;cursor:pointer}
.fd-log-button[data-active="1"]{background:#0f172a;color:#dff6ff;border-color:#0f172a}
.fd-log-placeholder td{color:#64748b;text-align:center;padding:26px 16px}
.fd-log-line{margin:0;background:transparent;color:#0f172a;padding:0;border-radius:0}
.fd-log-table td{padding:9px 12px}
.fd-log-table .card{border:1px solid #dbe4ef;border-radius:14px;overflow:hidden;margin-top:10px;background:#fff}
.fd-log-table .card-header{padding:10px 12px;background:#eaf1f8;font-weight:900;border-bottom:1px solid #dbe4ef}
.fd-log-table .card-body{padding:12px}
.fd-log-table .card-text{margin:.45rem 0}
.fd-log-table .alert-danger{background:#fee2e2!important;color:#7f1d1d!important;border:1px solid #fecaca!important;border-radius:14px;padding:12px}
.fd-log-table .alert-danger .alert-heading{color:#7f1d1d!important}
.fd-log-table .alert-danger pre{background:#fff1f2!important;color:#111827!important;border:1px solid #fecdd3;border-radius:10px;margin:.35rem 0 .45rem;padding:8px 10px!important;line-height:1.14!important;white-space:pre-wrap}
.fd-log-table .alert-danger pre br{display:none}
.fd-log-table .bg-dark,.fd-log-table pre.bg-dark{background:#07111f;color:#dbeafe;border-radius:14px;padding:8px 10px;overflow:auto;line-height:1.18}
.fd-log-snippet-tools{display:flex;justify-content:flex-start;margin-top:10px}
.fd-log-snippet-button{display:inline-flex;align-items:center;border:1px solid rgba(14,165,233,.26);border-radius:999px;background:#f0f9ff;color:#075985;padding:7px 10px;font-size:.8rem;font-weight:900;cursor:pointer}
.fd-log-snippet-button:hover{background:#e0f2fe}
.fd-log-snippet-button:disabled{opacity:.65;cursor:wait}
.fd-log-snippet-panel{margin-top:10px}
.fd-log-snippet-loading,.fd-log-snippet-error{border-radius:12px;padding:10px 12px;font-weight:800}
.fd-log-snippet-loading{background:#e0f2fe;color:#075985}
.fd-log-snippet-error{background:#fee2e2;color:#991b1b}
.fd-log-stack{margin-top:12px;border:1px solid rgba(125,211,252,.28);border-radius:16px;background:#03101f;color:#e6f0ff;overflow:hidden}
.fd-log-stack summary{cursor:pointer;padding:10px 12px;font-weight:900;color:#dff6ff;background:rgba(14,165,233,.12)}
.fd-log-stack summary span{color:#93c5fd;font-size:.86rem;margin-left:8px}
.fd-log-stack .fd-diagnostics{padding:10px 12px;border-top:1px solid rgba(125,211,252,.18);background:rgba(15,23,42,.46)}
.fd-log-stack .fd-diagnostics h2{margin:0 0 8px;font-size:.9rem;color:#dff6ff}
.fd-log-stack .fd-diagnostic{border:1px solid rgba(125,211,252,.18);border-radius:12px;padding:9px 10px;background:rgba(2,6,23,.45);margin-top:8px}
.fd-log-stack .fd-diagnostic h3{margin:0 0 5px;font-size:.84rem;color:#fed7aa}
.fd-log-stack .fd-diagnostic p{margin:.35rem 0;color:#bfdbfe}
.fd-log-stack .fd-diagnostic dl{display:grid;grid-template-columns:130px 1fr;gap:4px 10px;margin:8px 0 0;font-size:.8rem}
.fd-log-stack .fd-diagnostic dt{color:#93c5fd;font-weight:800}
.fd-log-stack .fd-diagnostic dd{margin:0;color:#e2e8f0;word-break:break-word}
.fd-log-stack .fd-snippet{margin:0;border:0;border-top:1px solid rgba(125,211,252,.18);border-radius:0;box-shadow:none;background:transparent;padding:10px 12px;color:#f8fafc}
.fd-log-stack .fd-snippet-head{margin-bottom:6px;gap:8px}
.fd-log-stack .fd-snippet h3{font-size:.84rem;color:#dbeafe}
.fd-log-stack .fd-frame-index{min-width:28px;height:20px;margin-right:6px;font-size:.78rem}
.fd-log-stack .fd-snippet-actions a{padding:5px 8px;font-size:.76rem}
.fd-log-stack .fd-code,.fd-log-stack [id^=codeContainer]{margin:0;background:#07111f!important;color:#dbeafe!important;border-radius:12px;padding:7px 9px!important;overflow:auto;line-height:1.18!important;box-shadow:none!important;max-width:none!important;width:100%!important}
.fd-log-stack .fd-code{white-space:pre}
.fd-log-stack .fd-code span{display:block}
.fd-log-stack .fd-code b{color:#93c5fd}
.fd-log-stack .line-number{margin-right:7px!important}
.fd-log-stack .fd-callsite-line,.fd-log-stack .fd-hit{display:block!important;background:rgba(249,115,22,.2)!important;border-left:3px solid #fb923c!important;margin-left:0!important;padding-left:7px!important;line-height:1.18!important}
.fd-log-stack a{color:#7dd3fc}
@media(max-width:900px){.fd-log-controls{justify-content:flex-start;margin-top:12px}.fd-log-table thead{display:none}.fd-log-table,.fd-log-table tbody,.fd-log-table tr,.fd-log-table td{display:block;width:100%}}
CSS;
	}

	private static function logs_script(): string {
		return <<<'HTML'
<script>
(function(){
	const state={
		fileKey:"",
		offset:0,
		timer:null,
		requestInFlight:false,
		paused:false,
		selecting:false,
		pollInterval:2000,
		fastPollInterval:150,
		maxRows:220,
		hasMore:false
	};
	const logShell=document.getElementById("fd-logs");
	const logContent=document.getElementById("fd-log-content");
	const statusBadge=document.getElementById("fd-log-status");
	const fileLabel=document.getElementById("fd-log-file");
	const sizeLabel=document.getElementById("fd-log-size");
	const pauseButton=document.getElementById("fd-log-pause-toggle");
	let selectionReleaseTimer=null;

	function setStatus(message, tone){
		statusBadge.textContent=message;
		statusBadge.dataset.tone=tone || "busy";
	}

	function renderPlaceholder(message){
		logContent.innerHTML='<tr class="fd-log-placeholder"><td colspan="2"></td></tr>';
		const cell=logContent.querySelector("td");
		if(cell){
			cell.textContent=message;
		}
	}

	function trimRows(){
		while(logContent.children.length>state.maxRows){
			logContent.removeChild(logContent.lastElementChild);
		}
	}

	function renderEntries(entries, replace){
		if(!Array.isArray(entries) || entries.length===0){
			if(replace){
				renderPlaceholder("Watching the current log file. Waiting for log events...");
			}
			return;
		}
		const html=entries.slice().reverse().join("");
		if(replace){
			logContent.innerHTML=html;
		}
		else{
			if(logContent.querySelector(".fd-log-placeholder")!==null){
				logContent.innerHTML="";
			}
			logContent.insertAdjacentHTML("afterbegin", html);
		}
		trimRows();
		runEmbeddedScripts(logContent);
	}

	function runEmbeddedScripts(container){
		const scripts=container.querySelectorAll("script[data-datadoc-highlighter=\"1\"]:not([data-fd-executed])");
		scripts.forEach(function(script){
			const executable=document.createElement("script");
			for(const attribute of script.attributes){
				if(attribute.name!=="data-fd-executed"){
					executable.setAttribute(attribute.name, attribute.value);
				}
			}
			executable.text=script.textContent || "";
			script.dataset.fdExecuted="1";
			document.head.appendChild(executable);
			executable.remove();
		});
	}

	function scheduleNextPoll(delay){
		window.clearTimeout(state.timer);
		state.timer=window.setTimeout(fetchLogs, delay);
	}

	function applyResponse(response){
		if(!response || response.ok!==true){
			setStatus("Unexpected log response", "error");
			scheduleNextPoll(state.pollInterval);
			return;
		}
		if(response.available!==true){
			if(state.fileKey===""){
				renderPlaceholder(response.message || "No log files found yet.");
			}
			fileLabel.textContent=response.message || "No active log file found.";
			sizeLabel.textContent="0 B";
			setStatus("Waiting for logs", "paused");
			scheduleNextPoll(state.pollInterval);
			return;
		}
		const reset=response.reset===true || response.file_key!==state.fileKey;
		state.fileKey=response.file_key || "";
		state.offset=Number(response.offset || 0);
		state.hasMore=response.has_more===true;
		fileLabel.textContent=response.file_path || ("Watching: " + (response.file_name || "Unknown log file"));
		sizeLabel.textContent=response.file_size_label || "";
		renderEntries(response.entries || [], reset);
		if(state.paused){
			setStatus("Paused", "paused");
		}
		else if(state.hasMore){
			setStatus("Catching up...", "busy");
		}
		else{
			setStatus("Live", "live");
		}
		scheduleNextPoll(state.hasMore ? state.fastPollInterval : state.pollInterval);
	}

	async function fetchLogs(){
		if(state.requestInFlight){
			return;
		}
		if(state.paused){
			setStatus("Paused", "paused");
			scheduleNextPoll(state.pollInterval);
			return;
		}
		if(state.selecting){
			setStatus("Selection paused live updates", "paused");
			scheduleNextPoll(state.pollInterval);
			return;
		}
		state.requestInFlight=true;
		try{
			const body=new URLSearchParams();
			body.set("ajax", "1");
			body.set("file_key", state.fileKey);
			body.set("offset", String(state.offset));
			const response=await fetch(window.location.pathname + window.location.search, {
				method:"POST",
				headers:{
					"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8",
					"X-Requested-With":"XMLHttpRequest"
				},
				body:body.toString(),
				cache:"no-store"
			});
			if(!response.ok){
				throw new Error("HTTP " + response.status);
			}
			applyResponse(await response.json());
		}
		catch(error){
			console.error("Error loading Flightdeck logs.", error);
			setStatus("Unable to load logs", "error");
			scheduleNextPoll(state.pollInterval);
		}
		finally{
			state.requestInFlight=false;
		}
	}

	pauseButton.addEventListener("click", function(){
		state.paused=!state.paused;
		pauseButton.textContent=state.paused ? "Resume Logs" : "Pause Logs";
		if(state.paused){
			setStatus("Paused", "paused");
			return;
		}
		setStatus("Reconnecting...", "busy");
		fetchLogs();
	});

	function entryHtmlForSnippetButton(button){
		const row=button.closest("tr");
		if(!row){
			return "";
		}
		const clone=row.cloneNode(true);
		clone.querySelectorAll(".fd-log-snippet-tools,.fd-log-snippet-panel,script").forEach(function(node){
			node.remove();
		});
		return clone.outerHTML;
	}

	async function renderEntrySnippets(button){
		const panel=button.closest("td") ? button.closest("td").querySelector(".fd-log-snippet-panel") : null;
		if(!panel){
			return;
		}
		if(button.dataset.loaded==="1"){
			panel.hidden=!panel.hidden;
			button.textContent=panel.hidden ? "Show Smart Snippets" : "Hide Smart Snippets";
			return;
		}
		button.disabled=true;
		button.textContent="Rendering...";
		panel.hidden=false;
		panel.innerHTML='<div class="fd-log-snippet-loading">Rendering smart snippets...</div>';
		try{
			const body=new URLSearchParams();
			body.set("ajax", "1");
			body.set("action", "render_snippets");
			body.set("csrf", logShell ? (logShell.dataset.csrf || "") : "");
			body.set("entry", entryHtmlForSnippetButton(button));
			const response=await fetch(window.location.pathname + window.location.search, {
				method:"POST",
				headers:{
					"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8",
					"X-Requested-With":"XMLHttpRequest"
				},
				body:body.toString(),
				cache:"no-store"
			});
			if(!response.ok){
				throw new Error("HTTP " + response.status);
			}
			const payload=await response.json();
			if(!payload || payload.ok!==true){
				throw new Error((payload && payload.message) || "Unable to render smart snippets.");
			}
			panel.innerHTML=payload.html || "";
			runEmbeddedScripts(panel);
			button.dataset.loaded="1";
			button.textContent="Hide Smart Snippets";
		}
		catch(error){
			console.error("Error rendering Flightdeck log snippets.", error);
			panel.innerHTML='<div class="fd-log-snippet-error"></div>';
			const errorBox=panel.querySelector(".fd-log-snippet-error");
			if(errorBox){
				errorBox.textContent=error.message || "Unable to render smart snippets.";
			}
			button.textContent="Retry Smart Snippets";
		}
		finally{
			button.disabled=false;
		}
	}

	logContent.addEventListener("click", function(event){
		const button=event.target instanceof Element ? event.target.closest(".fd-log-snippet-button") : null;
		if(button){
			renderEntrySnippets(button);
		}
	});

	logContent.addEventListener("mousedown", function(){
		state.selecting=true;
	});

	document.addEventListener("selectionchange", function(){
		const selection=window.getSelection ? window.getSelection() : null;
		if(selection && selection.anchorNode && String(selection).length>0 && logContent.contains(selection.anchorNode)){
			state.selecting=true;
		}
	});

	document.addEventListener("mouseup", function(){
		window.clearTimeout(selectionReleaseTimer);
		selectionReleaseTimer=window.setTimeout(function(){
			state.selecting=false;
		}, 1200);
	});

	fetchLogs();
})();
</script>
HTML;
	}

	private static function log_directories(): array {
		return array_values(array_filter(array_unique([
			defined('ROOTPATH') && !empty(ROOTPATH['dataphyre']) ? rtrim((string)ROOTPATH['dataphyre'], '/\\').'/logs/' : null,
			defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre']) ? rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/logs/' : null,
		]), static fn($path)=>is_string($path) && is_dir($path)));
	}

	private static function sanitize_config(array $value): array {
		$result=[];
		foreach($value as $key=>$item){
			$key_string=(string)$key;
			if(preg_match('/password|secret|private|token|key|license/i', $key_string)){
				$result[$key]='[redacted]';
				continue;
			}
			$result[$key]=is_array($item) ? self::sanitize_config($item) : $item;
		}
		return $result;
	}

	private static function safe_return_url(): string {
		$return=(string)($_GET['return'] ?? '/dataphyre');
		if($return==='' || str_starts_with($return, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $return)){
			return '/dataphyre';
		}
		return $return[0]==='/' ? $return : '/dataphyre';
	}

	private static function runtime_root(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			return rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/';
		}
		return rtrim(dirname(__DIR__, 3), '/\\').'/';
	}

	private static function install_root(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/';
		}
		return rtrim(dirname(__DIR__, 4), '/\\').'/';
	}

	private static function format_bytes(int $bytes): string {
		if($bytes>=1073741824){
			return round($bytes / 1073741824, 2).' GB';
		}
		if($bytes>=1048576){
			return round($bytes / 1048576, 2).' MB';
		}
		if($bytes>=1024){
			return round($bytes / 1024, 2).' KB';
		}
		return $bytes.' B';
	}

	private static function css(): string {
		return ':root{--bg:#07111f;--panel:#f8fafc;--line:#dbe4ef;--text:#0f172a;--muted:#64748b;--accent:#0ea5e9;--accent2:#f97316;--danger:#dc2626}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,rgba(14,165,233,.18),transparent 28rem),linear-gradient(135deg,#07111f,#111827 55%,#172033);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text)}a{color:inherit}.fd-sidebar{position:fixed;inset:0 auto 0 0;width:250px;background:rgba(7,17,31,.92);border-right:1px solid rgba(148,163,184,.18);color:#dbeafe;padding:22px;display:flex;flex-direction:column;gap:26px}.fd-logo{font-size:1.65rem;font-weight:900;line-height:1;letter-spacing:-.04em;color:#fff}.fd-logo span{color:#7dd3fc}.fd-sidebar nav{display:grid;gap:8px}.fd-sidebar nav a,.fd-sidebar-bottom a{padding:11px 12px;border-radius:14px;text-decoration:none;color:#b8c7df}.fd-sidebar nav a.active,.fd-sidebar nav a:hover{background:rgba(125,211,252,.12);color:#fff}.fd-sidebar-bottom{margin-top:auto}.fd-main{margin-left:250px;padding:30px;max-width:1680px}.fd-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;color:#fff;margin-bottom:22px;padding:30px;border-radius:28px;background:linear-gradient(135deg,rgba(14,165,233,.2),rgba(249,115,22,.12));border:1px solid rgba(255,255,255,.12);box-shadow:0 20px 80px rgba(0,0,0,.22)}.fd-hero h1{font-size:3rem;margin:.1rem 0}.fd-hero p{max-width:760px;color:#dbeafe}.fd-kicker{text-transform:uppercase;letter-spacing:.16em;font-size:.75rem;font-weight:900;color:#7dd3fc;margin:0}.fd-card,.fd-metric{background:rgba(248,250,252,.97);border:1px solid rgba(219,228,239,.8);box-shadow:0 18px 70px rgba(0,0,0,.2);border-radius:24px;padding:22px;margin-bottom:20px}.fd-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.fd-metric span{color:var(--muted);font-weight:700}.fd-metric b{display:block;font-size:1.8rem;margin:.4rem 0}.fd-metric p{color:var(--muted);margin:0}.fd-section-title{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}.fd-section-title h1,.fd-section-title h2{margin:0}.fd-link-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.fd-link-card{display:block;padding:16px;border:1px solid var(--line);border-radius:18px;text-decoration:none;background:#fff}.fd-link-card b{display:block;margin-bottom:8px}.fd-link-card span,.fd-muted{color:var(--muted)}.fd-primary,.fd-danger{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:0}.fd-primary{background:#7dd3fc;color:#082f49}.fd-danger{background:#fee2e2;color:#991b1b}.fd-warning,.fd-alert{border-radius:18px;padding:14px 16px;margin-bottom:18px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.fd-alert{background:#fee2e2;color:#991b1b;border-color:#fecaca}.fd-pill{display:inline-flex;padding:8px 11px;border-radius:999px;background:#eef8ff;color:#075985;font-weight:800}.fd-table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line)}table{width:100%;border-collapse:collapse;background:#fff}th,td{padding:13px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:#eaf1f8;color:#334155}tr:last-child td{border-bottom:0}.fd-log-table th:first-child{width:190px}.fd-code,pre{background:#07111f;color:#dbeafe;border-radius:18px;padding:16px;overflow:auto;white-space:pre-wrap;line-height:1.55}.fd-login{max-width:560px;margin:10vh auto}.fd-login input{width:100%;border:1px solid var(--line);border-radius:14px;padding:13px 14px;margin:12px 0;font-size:1rem}.fd-login button{border:0;border-radius:14px;background:#0f172a;color:#fff;padding:13px 16px;font-weight:900;cursor:pointer;width:100%}@media(max-width:1040px){.fd-sidebar{position:static;width:auto;display:block}.fd-sidebar nav{grid-template-columns:repeat(3,1fr);margin-top:18px}.fd-main{margin-left:0;padding:16px}.fd-metrics,.fd-link-grid{grid-template-columns:1fr 1fr}.fd-hero{display:block}.fd-hero h1{font-size:2.2rem}}@media(max-width:680px){.fd-metrics,.fd-link-grid{grid-template-columns:1fr}.fd-sidebar nav{grid-template-columns:1fr}.fd-section-title{display:block}}';
	}

	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

dataphyre_flightdeck::dispatch();
