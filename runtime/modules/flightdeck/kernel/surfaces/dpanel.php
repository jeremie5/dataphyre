<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');
require_once(ROOTPATH['common_dataphyre_runtime'].'modules/dpanel/kernel/dpanel.main.php');

if(class_exists('dataphyre_flightdeck_dpanel_surface', false)){
	dataphyre_flightdeck_dpanel_surface::dispatch();
	return;
}

final class dataphyre_flightdeck_dpanel_surface {

	public static function dispatch(): void {
		$trace=[];
		$error=null;
		$scope=(string)($_POST['fd_dpanel_scope'] ?? '');
		$scan=self::recover_stalled_scan(self::last_scan());
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
			if(self::valid_csrf()!==true){
				$error='Invalid Flightdeck form token. Reload the page and run the diagnostic again.';
			}
			else
			{
				$action=(string)($_POST['fd_dpanel_action'] ?? '');
				if($action==='pause'){
					$scan=self::set_scan_autorun((string)($_POST['fd_dpanel_token'] ?? ''), false);
				}
				elseif($action==='resume'){
					$scan=self::set_scan_autorun((string)($_POST['fd_dpanel_token'] ?? ''), true);
				}
				elseif($action==='continue'){
					$scan=self::continue_scan((string)($_POST['fd_dpanel_token'] ?? ''));
					if($scan===null){
						$error='The previous Dpanel scan state is no longer available. Start a new scan.';
					}
				}
				else
				{
					$scan=self::start_scan($scope !== '' ? $scope : 'all');
				}
			}
		}
		$trace=is_array($scan['trace'] ?? null) ? $scan['trace'] : [];
		$running_automatically=is_array($scan) && ($scan['done'] ?? true)!==true && ($scan['autorun'] ?? true)===true;

		$content=self::summary_cards($trace, $error, $scan);
		$content.=self::scan_status($scan);
		$content.=dataphyre_flightdeck_view::card(
			'Diagnostics',
			$error!==null
				? '<div class="fd-alert">'.self::e($error).'</div>'
				: ($running_automatically
					? '<p class="fd-muted">Auto-scan is running. '.self::e((string)count($trace)).' diagnostic entr'.(count($trace)===1 ? 'y has' : 'ies have').' been captured so far. Pause auto-scan to inspect the full table before completion.</p>'
					: self::diagnostics_table($trace)),
			[
				'subtitle'=>'Runs module diagnostics and renders results inside the Flightdeck templating shell.',
				'actions'=>self::actions($scan),
			]
		);

		echo dataphyre_flightdeck_view::module_page(
			'Dpanel',
			'Diagnostic Panel',
			'Module integrity, trace, and unit-test diagnostics embedded inside Flightdeck.',
			$content,
			'dpanel',
			['head'=>self::style().self::auto_continue_script($scan)]
		);
	}

	private static function run_scope(string $scope, array $modules): array {
		$scope=in_array($scope, ['runtime', 'app', 'all'], true) ? $scope : 'all';
		$memory=self::raise_diagnostic_memory_limit();
		$config_override=self::apply_diagnostic_runtime_overrides('256M');
		$previous_unit_test_mode=\dataphyre\dpanel::$run_unit_tests;
		$previous_entrypoint_mode=\dataphyre\dpanel::$load_module_entrypoints;
		$previous_dependency_mode=\dataphyre\dpanel::$follow_dependency_diagnostics;
		\dataphyre\dpanel::$run_unit_tests=false;
		\dataphyre\dpanel::$load_module_entrypoints=false;
		\dataphyre\dpanel::$follow_dependency_diagnostics=false;
		\dataphyre\dpanel::add_verbose([[
			'type'=>'diagnostic_runtime',
			'level'=>'info',
			'module'=>'dpanel',
			'message'=>'Flightdeck scan is running module diagnostics only; unit tests, module entrypoint execution, and nested dependency diagnostics are disabled for this browser request.',
			'passed'=>true,
		]]);
		try{
			if(($memory['effective_bytes'] ?? 0)>0 && ($memory['effective_bytes'] ?? 0)<self::memory_to_bytes('64M')){
				\dataphyre\dpanel::add_verbose([[
					'type'=>'diagnostic_runtime',
					'level'=>'warning',
					'module'=>'dpanel',
					'message'=>'Embedded Dpanel scan aborted because the active PHP memory limit is still '.($memory['effective_label'] ?? 'unknown').'. Raise `dataphyre.max_execution_memory` or allow `ini_set(memory_limit)` above 64M, then retry.',
					'passed'=>false,
				]]);
				return [
					'trace'=>\dataphyre\dpanel::get_verbose(),
					'processed'=>0,
				];
			}
			\dataphyre\dpanel::get_verbose();
			$deadline=microtime(true) + self::scan_batch_seconds();
			$processed=0;
			foreach($modules as $module){
				\dataphyre\dpanel::diagnose_module((string)$module);
				$processed++;
				if($processed>=self::scan_batch_limit() || microtime(true)>=$deadline){
					break;
				}
			}
		}
		finally{
			\dataphyre\dpanel::$run_unit_tests=$previous_unit_test_mode;
			\dataphyre\dpanel::$load_module_entrypoints=$previous_entrypoint_mode;
			\dataphyre\dpanel::$follow_dependency_diagnostics=$previous_dependency_mode;
			self::restore_diagnostic_runtime_overrides($config_override);
		}
		return [
			'trace'=>\dataphyre\dpanel::get_verbose(),
			'processed'=>$processed,
		];
	}

	private static function raise_diagnostic_memory_limit(): array {
		$previous=(string)ini_get('memory_limit');
		$current=self::memory_to_bytes($previous);
		$target=self::memory_to_bytes('256M');
		if($current>0 && $current<$target){
			$raised=@ini_set('memory_limit', '256M');
			$effective=(string)ini_get('memory_limit');
			\dataphyre\dpanel::add_verbose([[
				'type'=>'diagnostic_runtime',
				'level'=>$raised===false ? 'warning' : 'info',
				'module'=>'dpanel',
				'message'=>$raised===false
					? 'Unable to raise diagnostic memory limit from '.$previous.' to 256M; unit tests may be skipped if memory gets tight.'
					: 'Raised diagnostic memory limit from '.$previous.' to '.$effective.' for the Flightdeck scan.',
				'passed'=>$raised!==false,
			]]);
			return [
				'previous'=>$previous,
				'effective'=>$effective,
				'effective_bytes'=>self::memory_to_bytes($effective),
				'effective_label'=>$effective,
			];
		}
		return [
			'previous'=>$previous,
			'effective'=>$previous,
			'effective_bytes'=>$current,
			'effective_label'=>$previous,
		];
	}

	private static function apply_diagnostic_runtime_overrides(string $memory_limit): array {
		if(!defined('CFG') || !is_object(CFG) || !method_exists(CFG, 'raw')){
			return [];
		}
		$cfg=&CFG->raw();
		$state=[
			'dataphyre_exists'=>isset($cfg['dataphyre']) && is_array($cfg['dataphyre']),
			'common_exists'=>isset($cfg['common']['dataphyre']) && is_array($cfg['common']['dataphyre']),
			'previous_dataphyre'=>$cfg['dataphyre']['max_execution_memory'] ?? null,
			'previous_common'=>$cfg['common']['dataphyre']['max_execution_memory'] ?? null,
		];
		if(!isset($cfg['dataphyre']) || !is_array($cfg['dataphyre'])){
			$cfg['dataphyre']=[];
		}
		$cfg['dataphyre']['max_execution_memory']=$memory_limit;
		if(isset($cfg['common']['dataphyre']) && is_array($cfg['common']['dataphyre'])){
			$cfg['common']['dataphyre']['max_execution_memory']=$memory_limit;
		}
		return $state;
	}

	private static function restore_diagnostic_runtime_overrides(array $state): void {
		if($state===[] || !defined('CFG') || !is_object(CFG) || !method_exists(CFG, 'raw')){
			return;
		}
		$cfg=&CFG->raw();
		if(($state['dataphyre_exists'] ?? false)!==true){
			unset($cfg['dataphyre']);
		}
		elseif(array_key_exists('previous_dataphyre', $state)){
			$cfg['dataphyre']['max_execution_memory']=$state['previous_dataphyre'];
		}
		if(($state['common_exists'] ?? false)===true){
			$cfg['common']['dataphyre']['max_execution_memory']=$state['previous_common'];
		}
	}

	private static function memory_to_bytes(string|false $value): int {
		$value=trim((string)$value);
		if($value==='' || $value==='-1'){
			return -1;
		}
		$unit=strtolower(substr($value, -1));
		$number=(float)$value;
		return match($unit){
			'g'=>(int)($number * 1073741824),
			'm'=>(int)($number * 1048576),
			'k'=>(int)($number * 1024),
			default=>(int)$number,
		};
	}

	private static function summary_cards(array $trace, ?string $error, ?array $scan=null): string {
		$summary=[
			'total'=>count($trace),
			'passed'=>0,
			'warnings'=>0,
			'failed'=>0,
		];
		foreach($trace as $entry){
			$level=strtolower((string)($entry['level'] ?? 'info'));
			if(($entry['passed'] ?? null)===true){
				$summary['passed']++;
			}
			if($level==='warning'){
				$summary['warnings']++;
			}
			if(in_array($level, ['fatal', 'error'], true) || ($entry['passed'] ?? null)===false){
				$summary['failed']++;
			}
		}
		if($error!==null){
			$summary['failed']++;
		}
		$cards=[
			['Findings', $summary['total'], 'Diagnostic entries captured.'],
			['Passed', $summary['passed'], 'Unit and module checks marked as passing.'],
			['Warnings', $summary['warnings'], 'Non-fatal runtime concerns.'],
			['Failed', $summary['failed'], 'Errors, fatal entries, or failed checks.'],
		];
		if(is_array($scan) && isset($scan['cursor'], $scan['queue'])){
			$cards[]=[
				'Progress',
				(string)min((int)$scan['cursor'], count((array)$scan['queue'])).' / '.(string)count((array)$scan['queue']),
				($scan['done'] ?? false)===true ? 'Scan completed.' : 'Continue to process the remaining modules.',
			];
		}
		$html='<section class="fd-metrics">';
		foreach($cards as $card){
			$html.='<div class="fd-metric"><span>'.self::e($card[0]).'</span><b>'.self::e((string)$card[1]).'</b><p>'.self::e($card[2]).'</p></div>';
		}
		return $html.'</section>';
	}

	private static function diagnostics_table(array $trace): string {
		if($trace===[]){
			return '<p class="fd-muted">No diagnostic scan has been run yet.</p>';
		}
		$rows=[];
		foreach($trace as $index=>$entry){
			$normalized=self::normalize_entry($entry, $index);
			$rows[]=[
				dataphyre_flightdeck_view::badge($normalized['status'], $normalized['level']),
				dataphyre_flightdeck_view::badge($normalized['type'], $normalized['level']),
				'<span title="'.self::e($normalized['target_full']).'">'.self::e($normalized['target']).'</span>',
				'<div>'.self::e($normalized['message']).'</div>'.$normalized['details'],
			];
		}
		return dataphyre_flightdeck_view::table(['Status', 'Type', 'Target', 'Message'], $rows);
	}

	private static function normalize_entry(array $entry, int $index): array {
		$type=(string)($entry['type'] ?? 'Info');
		$level=strtolower((string)($entry['level'] ?? 'info'));
		$target=(string)($entry['file'] ?? $entry['test_case_file'] ?? $entry['module'] ?? 'N/A');
		$message=(string)($entry['fail_string'] ?? $entry['warning_string'] ?? $entry['error'] ?? $entry['info'] ?? $entry['message'] ?? $entry['reason'] ?? '');
		$details='';
		$exception_payload=self::exception_payload($entry);
		if($exception_payload!==null){
			$level='error';
			$target=(string)($exception_payload['file'] ?? 'N/A').':'.(string)($exception_payload['line'] ?? '0');
			$message=(string)($exception_payload['message'] ?? 'Unknown exception');
			$details=self::details('Exception Trace', (string)($exception_payload['trace'] ?? ''));
		}
		elseif(isset($entry['tracelog']) && is_array($entry['tracelog'])){
			$tracelog_summary=self::tracelog_summary($entry['tracelog']);
			$level=$tracelog_summary['level'];
			$details=self::details_html('Tracelog Entries', self::tracelog_table($entry['tracelog']));
			$message=$message!=='' ? $message : count($entry['tracelog']).' trace entries ('.implode(', ', $tracelog_summary['parts']).').';
		}
		elseif(isset($entry['input']) || isset($entry['execution_time']) || isset($entry['test_name'])){
			$details=self::details('Diagnostic Context', json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
		}
		elseif($message===''){
			$details=self::details('Raw Diagnostic Entry', json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
		}
		if(($entry['passed'] ?? null)===true && !in_array($level, ['warning', 'error', 'fatal'], true)){
			$level='success';
		}
		if(($entry['passed'] ?? null)===false && !in_array($level, ['warning', 'error', 'fatal'], true)){
			$level='error';
		}
		$status=match($level){
			'fatal'=>'Fatal',
			'error'=>'Failed',
			'warning'=>'Warning',
			'success'=>'Passed',
			default=>'Info',
		};
		return [
			'status'=>$status,
			'type'=>$type,
			'level'=>$level,
			'target'=>$target !== '' ? basename($target) : 'N/A',
			'target_full'=>$target,
			'message'=>$message !== '' ? $message : 'No message provided.',
			'details'=>$details,
		];
	}

	private static function details(string $summary, string $content): string {
		if($content===''){
			return '';
		}
		return '<details class="fd-details"><summary>'.self::e($summary).'</summary>'.dataphyre_flightdeck_view::code($content).'</details>';
	}

	private static function details_html(string $summary, string $html): string {
		if($html===''){
			return '';
		}
		return '<details class="fd-details"><summary>'.self::e($summary).'</summary>'.$html.'</details>';
	}

	private static function tracelog_summary(array $entries): array {
		$count_types=[];
		$severity_order=['info'=>0, 'warning'=>1, 'error'=>2, 'fatal'=>3];
		$worst='info';
		foreach($entries as $entry){
			$type=is_array($entry) ? strtolower((string)($entry['type'] ?? 'info')) : 'info';
			if(!isset($severity_order[$type])){
				$type='info';
			}
			$count_types[$type]=($count_types[$type] ?? 0) + 1;
			if($severity_order[$type]>$severity_order[$worst]){
				$worst=$type;
			}
		}
		$parts=[];
		foreach($count_types as $type=>$count){
			$parts[]=$count.' '.$type;
		}
		return [
			'level'=>$worst,
			'parts'=>$parts!==[] ? $parts : ['0 info'],
		];
	}

	private static function tracelog_table(array $entries): string {
		$rows=[];
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$file=(string)($entry['file'] ?? '');
			$rows[]=[
				'<span title="'.self::e($file).'">'.self::e($file!=='' ? basename($file) : 'Unknown').'</span>',
				self::e((string)($entry['line'] ?? '-')),
				self::e((string)($entry['class'] ?? '-')),
				self::e((string)($entry['function'] ?? '-')),
				dataphyre_flightdeck_view::code((string)($entry['message'] ?? '')),
			];
		}
		return $rows!==[] ? dataphyre_flightdeck_view::table(['File', 'Line', 'Class', 'Function', 'Message'], $rows) : '';
	}

	private static function actions(?array $scan=null): string {
		$csrf=self::csrf_input();
		$html='<div class="fd-action-row">';
		if(is_array($scan) && ($scan['done'] ?? true)!==true && !empty($scan['token'])){
			$label=((int)($scan['batches'] ?? 0)===0 && (int)($scan['cursor'] ?? 0)===0) ? 'Begin Scan' : 'Continue Scan';
			$html.='<form method="post" id="fd-dpanel-continue-form">'.$csrf.
				'<input type="hidden" name="fd_dpanel_action" value="continue">'.
				'<input type="hidden" name="fd_dpanel_token" value="'.self::e((string)$scan['token']).'">'.
				'<button class="fd-primary" type="submit">'.self::e($label).'</button></form>';
			$html.='<form method="post">'.$csrf.
				'<input type="hidden" name="fd_dpanel_action" value="'.(($scan['autorun'] ?? true)===true ? 'pause' : 'resume').'">'.
				'<input type="hidden" name="fd_dpanel_token" value="'.self::e((string)$scan['token']).'">'.
				'<button class="fd-secondary" type="submit">'.(($scan['autorun'] ?? true)===true ? 'Pause Auto Scan' : 'Resume Auto Scan').'</button></form>';
		}
		$html.=
			'<form method="post">'.$csrf.'<button class="fd-primary" type="submit" name="fd_dpanel_scope" value="runtime">Scan Runtime</button></form>'.
			'<form method="post">'.$csrf.'<button class="fd-primary" type="submit" name="fd_dpanel_scope" value="app">Scan App</button></form>'.
			'<form method="post">'.$csrf.'<button class="fd-primary" type="submit" name="fd_dpanel_scope" value="all">Scan All</button></form>';
		return $html.'</div>';
	}

	private static function valid_csrf(): bool {
		return class_exists('dataphyre_flightdeck_auth', false)!==true
			|| dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)===true;
	}

	private static function csrf_input(): string {
		return class_exists('dataphyre_flightdeck_auth', false)
			? '<input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'">'
			: '';
	}

	private static function style(): string {
		return '<style>
.fd-action-row{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.fd-action-row form{margin:0}
.fd-secondary{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:1px solid rgba(14,165,233,.22);background:#e0f2fe;color:#075985}
.fd-scan-status{margin-bottom:20px}
.fd-details{margin-top:12px}
.fd-details summary{cursor:pointer;color:#075985;font-weight:900}
.fd-details pre{margin-top:10px}
.fd-details .fd-table-wrap{margin-top:10px}
.fd-details .fd-code{margin:0;padding:10px;border-radius:12px;line-height:1.35}
</style>';
	}

	private static function auto_continue_script(?array $scan): string {
		if(!is_array($scan) || ($scan['done'] ?? true)===true || empty($scan['token']) || ($scan['autorun'] ?? true)!==true){
			return '';
		}
		return '<script>
document.addEventListener("DOMContentLoaded", function(){
	const form=document.getElementById("fd-dpanel-continue-form");
	if(!form){
		return;
	}
	window.setTimeout(function(){
		if(typeof form.requestSubmit==="function"){
			form.requestSubmit();
			return;
		}
		form.submit();
	}, 180);
});
</script>';
	}

	private static function start_scan(string $scope): array {
		$scope=in_array($scope, ['runtime', 'app', 'all'], true) ? $scope : 'all';
		$state=[
			'token'=>sha1($scope.'|'.microtime(true).'|'.bin2hex(random_bytes(8))),
			'scope'=>$scope,
			'queue'=>[],
			'cursor'=>0,
			'trace'=>[],
			'done'=>false,
			'batches'=>0,
			'started_at'=>time(),
			'updated_at'=>time(),
			'last_batch_count'=>0,
			'autorun'=>true,
			'active_module'=>null,
			'active_started_at'=>null,
			'last_module'=>null,
			'last_failed_module'=>null,
		];
		$state=self::populate_scan_queue($state);
		self::store_scan($state);
		return $state;
	}

	private static function continue_scan(string $token): ?array {
		$state=self::load_scan($token);
		if($state===null){
			return null;
		}
		if(($state['done'] ?? false)===true){
			return $state;
		}
		return self::run_scan_batch($state);
	}

	private static function run_scan_batch(array $state): array {
		$remaining=array_slice((array)($state['queue'] ?? []), (int)($state['cursor'] ?? 0));
		$active_module=(string)($remaining[0] ?? '');
		if($active_module!==''){
			$state['active_module']=$active_module;
			$state['active_started_at']=time();
			$state['updated_at']=time();
			self::store_scan($state);
		}
		$batch=self::run_scope((string)($state['scope'] ?? 'all'), $remaining);
		$batch_trace=is_array($batch['trace'] ?? null) ? $batch['trace'] : [];
		$this_batch=max(0, (int)($batch['processed'] ?? 0));
		$state['trace']=array_merge((array)($state['trace'] ?? []), self::sanitize_trace_entries($batch_trace));
		$state['cursor']=min(count((array)($state['queue'] ?? [])), (int)($state['cursor'] ?? 0) + $this_batch);
		$state['done']=$state['cursor']>=count((array)($state['queue'] ?? []));
		$state['batches']=(int)($state['batches'] ?? 0) + 1;
		$state['updated_at']=time();
		$state['last_batch_count']=$this_batch;
		$state['last_module']=$active_module !== '' ? $active_module : ($state['last_module'] ?? null);
		$state['active_module']=null;
		$state['active_started_at']=null;
		if($this_batch===0 && $remaining!==[] && ($state['done'] ?? false)!==true){
			$state['done']=true;
			$state['trace'][]=[
				'type'=>'diagnostic_runtime',
				'level'=>'warning',
				'module'=>'dpanel',
				'message'=>'Embedded Flightdeck scan stopped because the last batch did not advance. Check the latest diagnostic rows for the blocking module.',
				'passed'=>false,
			];
		}
		self::store_scan($state);
		return $state;
	}

	private static function recover_stalled_scan(?array $scan): ?array {
		if(!is_array($scan) || ($scan['done'] ?? false)===true){
			return $scan;
		}
		$active_module=(string)($scan['active_module'] ?? '');
		$started_at=(int)($scan['active_started_at'] ?? 0);
		if($active_module==='' || $started_at<=0){
			return $scan;
		}
		if((time() - $started_at) < 20){
			return $scan;
		}
		$queue=(array)($scan['queue'] ?? []);
		$cursor=(int)($scan['cursor'] ?? 0);
		if(isset($queue[$cursor]) && (string)$queue[$cursor]===$active_module){
			$scan['cursor']=$cursor + 1;
		}
		$scan['last_failed_module']=$active_module;
		$scan['active_module']=null;
		$scan['active_started_at']=null;
		$scan['updated_at']=time();
		$scan['done']=((int)($scan['cursor'] ?? 0))>=count($queue);
		$scan['trace'][]=[
			'type'=>'diagnostic_runtime',
			'level'=>'warning',
			'module'=>$active_module,
			'message'=>'Skipped module `'.$active_module.'` after the previous batch stalled long enough to look like a timeout or recursion loop.',
			'passed'=>false,
		];
		self::store_scan($scan);
		return $scan;
	}

	private static function populate_scan_queue(array $state): array {
		$scope=(string)($state['scope'] ?? 'all');
		$queue=[];
		$trace=[];
		if($scope==='runtime' || $scope==='all'){
			$result=self::module_queue_for_scope('runtime', rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules');
			$queue=array_merge($queue, $result['queue']);
			$trace=array_merge($trace, $result['trace']);
		}
		if(($scope==='app' || $scope==='all') && defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			$result=self::module_queue_for_scope('app', rtrim((string)ROOTPATH['dataphyre'], '/\\').'/modules');
			$queue=array_merge($queue, $result['queue']);
			$trace=array_merge($trace, $result['trace']);
		}
		$state['queue']=$queue;
		$state['trace']=self::sanitize_trace_entries($trace);
		$state['done']=$queue===[];
		return $state;
	}

	private static function module_queue_for_scope(string $label, string $folder): array {
		if(!is_dir($folder)){
			return [
				'queue'=>[],
				'trace'=>[[
					'type'=>'module_folder_missing',
					'level'=>'warning',
					'module'=>$label,
					'file'=>$folder,
					'message'=>'Module folder does not exist.',
					'passed'=>false,
				]],
			];
		}
		$excluded_modules=$label==='runtime' ? ['dpanel'] : [];
		$queue=self::collect_modules_in_folder($folder, $excluded_modules);
		$trace=[];
		if($excluded_modules!==[]){
			$trace[]=[
				'type'=>'diagnostic_runtime',
				'level'=>'info',
				'module'=>'dpanel',
				'message'=>'Embedded Flightdeck scan skips the Dpanel module itself to avoid recursive self-diagnostics.',
				'passed'=>true,
			];
		}
		if($queue===[]){
			$trace[]=[
				'type'=>'module_folder_empty',
				'level'=>'warning',
				'module'=>$label,
				'file'=>$folder,
				'message'=>'No module entrypoints (*.main.php) were found in this scope.',
				'passed'=>false,
			];
		}
		return ['queue'=>$queue, 'trace'=>$trace];
	}

	private static function collect_modules_in_folder(string $folder, array $excluded_modules=[]): array {
		try{
			$modules=[];
			$iterator=new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)
			);
			foreach($iterator as $file){
				if(!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.main.php')){
					continue;
				}
				$module_name=basename($file->getFilename(), '.main.php');
				if(in_array($module_name, $excluded_modules, true)){
					continue;
				}
				$modules[$module_name]=$module_name;
			}
			ksort($modules, SORT_STRING);
			return array_values($modules);
		}catch(\Throwable $exception){
			return [];
		}
	}

	private static function sanitize_trace_entries(array $entries): array {
		$sanitized=[];
		foreach($entries as $entry){
			$sanitized[]=self::sanitize_value($entry);
		}
		return $sanitized;
	}

	private static function sanitize_value(mixed $value): mixed {
		if($value instanceof \Throwable){
			return [
				'__type'=>'throwable',
				'class'=>get_class($value),
				'message'=>$value->getMessage(),
				'file'=>$value->getFile(),
				'line'=>$value->getLine(),
				'trace'=>$value->getTraceAsString(),
			];
		}
		if(is_array($value)){
			foreach($value as $key=>$item){
				$value[$key]=self::sanitize_value($item);
			}
			return $value;
		}
		if(is_object($value)){
			return [
				'__type'=>'object',
				'class'=>get_class($value),
			];
		}
		return $value;
	}

	private static function exception_payload(array $entry): ?array {
		if(($entry['exception'] ?? null) instanceof \Throwable){
			$exception=$entry['exception'];
			return [
				'class'=>get_class($exception),
				'message'=>$exception->getMessage(),
				'file'=>$exception->getFile(),
				'line'=>$exception->getLine(),
				'trace'=>$exception->getTraceAsString(),
			];
		}
		if(isset($entry['exception']) && is_array($entry['exception']) && (($entry['exception']['__type'] ?? '')==='throwable')){
			return $entry['exception'];
		}
		return null;
	}

	private static function scan_status(?array $scan): string {
		if(!is_array($scan) || !isset($scan['queue'])){
			return '';
		}
		$processed=min((int)($scan['cursor'] ?? 0), count((array)$scan['queue']));
		$total=count((array)$scan['queue']);
		if($total===0){
			return '';
		}
		$prepared=((int)($scan['batches'] ?? 0)===0 && ($scan['done'] ?? false)!==true);
		$class=($scan['done'] ?? false)===true ? 'fd-warning' : 'fd-warning fd-scan-status';
		$html='<div class="'.$class.'"><b>';
		if(($scan['done'] ?? false)===true){
			$html.='Scan complete.';
		}
		elseif($prepared){
			$html.='Scan prepared.';
		}
		else
		{
			$html.='Scan in progress.';
		}
		$html.='</b> ';
		$html.=self::e(ucfirst((string)($scan['scope'] ?? 'all'))).' processed ';
		$html.=self::e((string)$processed).' of '.self::e((string)$total).' module(s)';
		$html.=' across '.self::e((string)($scan['batches'] ?? 0)).' batch(es).';
		$active_module=(string)($scan['active_module'] ?? '');
		$last_module=(string)($scan['last_module'] ?? '');
		$last_failed_module=(string)($scan['last_failed_module'] ?? '');
		if($active_module!==''){
			$html.=' Active module: '.self::e($active_module).'.';
		}
		elseif($last_failed_module!==''){
			$html.=' Last skipped module: '.self::e($last_failed_module).'.';
		}
		elseif($last_module!==''){
			$html.=' Last completed module: '.self::e($last_module).'.';
		}
		if($prepared){
			$html.=($scan['autorun'] ?? true)===true
				? ' The browser will begin the first batch automatically.'
				: ' Begin the first batch when you are ready.';
		}
		elseif(($scan['done'] ?? false)!==true){
			$html.=($scan['autorun'] ?? true)===true
				? ' The browser will continue the next batch automatically.'
				: ' Continue to process the remaining modules without risking a request timeout.';
		}
		return $html.'</div>';
	}

	private static function set_scan_autorun(string $token, bool $autorun): ?array {
		$scan=self::load_scan($token);
		if($scan===null){
			return null;
		}
		$scan['autorun']=$autorun;
		$scan['updated_at']=time();
		self::store_scan($scan);
		return $scan;
	}

	private static function load_scan(string $token): ?array {
		if($token===''){
			return null;
		}
		return self::scan_store()[$token] ?? null;
	}

	private static function last_scan(): ?array {
		$last=(string)($_SESSION['flightdeck_dpanel_last_scan'] ?? '');
		return $last!=='' ? (self::scan_store()[$last] ?? null) : null;
	}

	private static function store_scan(array $scan): void {
		$store=self::scan_store();
		$store[(string)$scan['token']]=$scan;
		$_SESSION['flightdeck_dpanel_scan']=$store;
		$_SESSION['flightdeck_dpanel_last_scan']=(string)$scan['token'];
	}

	private static function scan_store(): array {
		if(session_status()!==PHP_SESSION_ACTIVE && !headers_sent()){
			@session_start();
		}
		$store=$_SESSION['flightdeck_dpanel_scan'] ?? [];
		return is_array($store) ? $store : [];
	}

	private static function scan_batch_limit(): int {
		return 1;
	}

	private static function scan_batch_seconds(): float {
		return 1.25;
	}

	private static function e(string $value): string {
		return dataphyre_flightdeck_view::e($value);
	}
}

dataphyre_flightdeck_dpanel_surface::dispatch();
