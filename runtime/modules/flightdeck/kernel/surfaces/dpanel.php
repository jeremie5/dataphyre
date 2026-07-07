<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(dirname(__DIR__).'/view.php');
require_once(ROOTPATH['common_dataphyre_runtime'].'modules/dpanel/kernel/dpanel.main.php');

if(defined('DATAPHYRE_FLIGHTDECK_DPANEL_SURFACE_LOADED')){
	if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
		dataphyre_flightdeck_dpanel_surface::dispatch();
	}
	return;
}
define('DATAPHYRE_FLIGHTDECK_DPANEL_SURFACE_LOADED', true);

/**
 * Runs Dpanel diagnostics through a browser-safe Flightdeck surface.
 *
 * The surface prepares resumable diagnostic scans, batches module execution to
 * avoid request timeouts, stores progress in session state, and renders summary
 * cards plus normalized diagnostic rows inside the Flightdeck shell.
 */
final class dataphyre_flightdeck_dpanel_surface {

	/**
	 * Dispatches the Dpanel diagnostic surface.
	 *
	 * POST requests may start, continue, pause, or resume a scan after CSRF
	 * validation. Stalled scans are recovered before rendering so the browser can
	 * skip a blocking module and keep progressing through the queue.
	 *
	 * @return void Emits the diagnostic page and optional auto-continue script.
	 */
	public static function dispatch(): void {
		$trace=[];
		$error=null;
		$scope=(string)($_POST['fd_dpanel_scope'] ?? '');
		$scan=self::recover_stalled_scan(self::last_scan());
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
			$action=(string)($_POST['fd_dpanel_action'] ?? '');
			$scan_token=(string)($_POST['fd_dpanel_token'] ?? '');
			if(self::valid_post($action, $scan_token)!==true){
				$error='Invalid Flightdeck form token. Reload the page and run the diagnostic again.';
				$scan=self::pause_scan_after_error($scan_token, $error);
			}
			else
			{
				if($action==='pause'){
					$scan=self::set_scan_autorun($scan_token, false);
				}
				elseif($action==='resume'){
					$scan=self::set_scan_autorun($scan_token, true);
				}
				elseif($action==='continue'){
					$scan=self::continue_scan($scan_token);
					if($scan===null){
						$error='The previous Dpanel scan state is no longer available. Start a new scan.';
						$scan=self::pause_scan_after_error($scan_token, $error) ?? $scan;
					}
				}
				else
				{
					$scan=self::start_scan($scope !== '' ? $scope : 'all');
				}
			}
		}
		$trace=is_array($scan['trace'] ?? null) ? $scan['trace'] : [];
		$running_automatically=$error===null && self::scan_can_continue_automatically($scan);
		if(self::wants_ajax_response()){
			self::emit_ajax_response($scan, $error, $trace, $running_automatically);
			return;
		}

		echo dataphyre_flightdeck_view::module_page(
			'Dpanel',
			'Diagnostic Panel',
			'Module integrity, trace, and unit-test diagnostics embedded inside Flightdeck.',
			'<div id="fd-dpanel-content">'.self::content($scan, $error, $trace, $running_automatically).'</div>',
			'dpanel',
			['head'=>'<link rel="stylesheet" href="'.self::e(self::asset_url('dpanel-surface.css')).'">'.self::client_script()]
		);
	}

	/**
	 * Renders the replaceable Dpanel scan content.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @param ?string $error Surface-level error to display.
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @param bool $running_automatically Whether autorun is currently active.
	 * @return string Replaceable scan HTML.
	 */
	private static function content(?array $scan, ?string $error, array $trace, bool $running_automatically): string {
		$parts=self::content_parts($scan, $error, $trace, $running_automatically);
		return '<div data-dpanel-part="summary">'.$parts['summary'].'</div>'
			.'<div data-dpanel-part="status">'.$parts['status'].'</div>'
			.'<div data-dpanel-part="inventory">'.$parts['inventory'].'</div>'
			.'<div data-dpanel-part="diagnostics">'.$parts['diagnostics'].'</div>';
	}

	/**
	 * Renders independently replaceable Dpanel scan fragments.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @param ?string $error Surface-level error to display.
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @param bool $running_automatically Whether autorun is currently active.
	 * @return array{summary:string,status:string,inventory:string,diagnostics:string} HTML fragments keyed by region.
	 */
	private static function content_parts(?array $scan, ?string $error, array $trace, bool $running_automatically): array {
		return [
			'summary'=>self::summary_cards($trace, $error, $scan),
			'status'=>self::scan_status($scan),
			'inventory'=>self::test_inventory_card($scan),
			'diagnostics'=>self::diagnostics_card($scan, $error, $trace, $running_automatically),
		];
	}

	/**
	 * Renders the diagnostics table card and current scan actions.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @param ?string $error Surface-level error to display.
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @param bool $running_automatically Whether autorun is currently active.
	 * @return string Diagnostics card HTML.
	 */
	private static function diagnostics_card(?array $scan, ?string $error, array $trace, bool $running_automatically): string {
		return dataphyre_flightdeck_view::card(
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
	}

	/**
	 * Emits a JSON response for AJAX scan controls.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @param ?string $error Surface-level error.
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @param bool $running_automatically Whether autorun should continue.
	 * @return void
	 */
	private static function emit_ajax_response(?array $scan, ?string $error, array $trace, bool $running_automatically): void {
		header('Content-Type: application/json; charset=UTF-8');
		$parts=self::content_parts($scan, $error, $trace, $running_automatically);
		echo json_encode([
			'content'=>'<div data-dpanel-part="summary">'.$parts['summary'].'</div>'
				.'<div data-dpanel-part="status">'.$parts['status'].'</div>'
				.'<div data-dpanel-part="inventory">'.$parts['inventory'].'</div>'
				.'<div data-dpanel-part="diagnostics">'.$parts['diagnostics'].'</div>',
			'parts'=>$parts,
			'autorun'=>$running_automatically,
			'done'=>is_array($scan) ? (($scan['done'] ?? false)===true) : false,
			'error'=>$error,
		], JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Checks whether the request expects a partial JSON update.
	 *
	 * @return bool True for AJAX Dpanel requests.
	 */
	private static function wants_ajax_response(): bool {
		return ($_POST['fd_dpanel_ajax'] ?? '')==='1'
			|| strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))==='fetch';
	}

	/**
	 * Builds a cache-versioned Flightdeck asset URL for the Dpanel surface.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Public Flightdeck asset URL with a content hash query value.
	 */
	public static function asset_url(string $asset): string {
		$name=self::asset_name($asset);
		return '/dataphyre/flightdeck/assets/'.$name.'?v='.self::asset_version($name);
	}

	/**
	 * Returns the short content hash used to version a Dpanel surface asset.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-1 prefix, or "missing" when the asset is unknown.
	 */
	public static function asset_version(string $asset): string {
		$content=self::asset_content($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Returns the inline Dpanel surface asset body and content type.
	 *
	 * @param string $asset Requested asset filename after Flightdeck asset routing.
	 * @return ?array{content_type:string,body:string} Asset payload, or null for unknown assets.
	 */
	public static function asset_content(string $asset): ?array {
		return self::asset_name($asset)==='dpanel-surface.css'
			? ['content_type'=>'text/css; charset=UTF-8', 'body'=>self::style()]
			: null;
	}

	/**
	 * Normalizes an asset route segment into a safe local asset name.
	 *
	 * Flightdeck asset requests can arrive from public URLs, so this helper strips
	 * directory components and accepts only the filename alphabet used by bundled
	 * surface assets. An empty string means the request must not resolve.
	 *
	 * @param string $asset Raw asset name or path-like route segment.
	 * @return string Sanitized asset filename, or an empty string for rejected names.
	 */
	private static function asset_name(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Executes one bounded Dpanel diagnostic batch for the requested module scope.
	 *
	 * The method deliberately disables entrypoint loading and unit-test execution
	 * inside the browser request. Some modules and tests may terminate the process
	 * or perform side effects, so Flightdeck keeps this control-plane scan to
	 * bounded source validation until a separate worker can run unsafe tests.
	 *
	 * @param string $scope Runtime, app, or all module scope label.
	 * @param array<int,string> $modules Ordered module names still waiting in the scan queue.
	 * @return array{trace:array<int,array<string,mixed>>,processed:int} Sanitized Dpanel trace entries and the number of modules advanced.
	 */
	private static function run_scope(string $scope, array $modules): array {
		$scope=in_array($scope, ['runtime', 'app', 'all'], true) ? $scope : 'all';
		$memory=self::raise_diagnostic_memory_limit();
		$config_override=self::apply_diagnostic_runtime_overrides('256M');
		$previous_unit_test_mode=\dataphyre\dpanel::$run_unit_tests;
		$previous_entrypoint_mode=\dataphyre\dpanel::$load_module_entrypoints;
		$previous_dependency_mode=\dataphyre\dpanel::$follow_dependency_diagnostics;
		$previous_eval_mode=\dataphyre\dpanel::$allow_eval_unit_tests;
		$previous_core_bootstrap_mode=\dataphyre\dpanel::$bootstrap_core_before_module;
		\dataphyre\dpanel::$run_unit_tests=false;
		\dataphyre\dpanel::$load_module_entrypoints=false;
		\dataphyre\dpanel::$follow_dependency_diagnostics=false;
		\dataphyre\dpanel::$allow_eval_unit_tests=false;
		\dataphyre\dpanel::$bootstrap_core_before_module=false;
		\dataphyre\dpanel::add_verbose([[
			'type'=>'diagnostic_runtime',
			'level'=>'info',
			'module'=>'dpanel',
			'message'=>'Flightdeck browser scans validate module files without loading arbitrary module entrypoints or running unit tests in the control request. Unit-test execution needs an isolated worker boundary.',
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
			\dataphyre\dpanel::$allow_eval_unit_tests=$previous_eval_mode;
			\dataphyre\dpanel::$bootstrap_core_before_module=$previous_core_bootstrap_mode;
			self::restore_diagnostic_runtime_overrides($config_override);
		}
		return [
			'trace'=>\dataphyre\dpanel::get_verbose(),
			'processed'=>$processed,
		];
	}

	/**
	 * Attempts to raise PHP's memory limit for an embedded diagnostic batch.
	 *
	 * Dpanel can load module entrypoints and unit-test payloads, so Flightdeck asks
	 * for a 256M request ceiling before executing modules. The previous and
	 * effective limits are returned for diagnostics; PHP's ini value is deliberately
	 * not restored because raising it only affects the current request.
	 *
	 * @return array{previous:string,effective:string,effective_bytes:int,effective_label:string} Memory-limit state observed by the scan.
	 */
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

	/**
	 * Temporarily overrides Dataphyre runtime memory configuration for diagnostics.
	 *
	 * Dpanel reads memory ceilings from CFG in addition to PHP's ini setting. This
	 * helper records whether each configuration branch existed before the scan so
	 * restoration can remove synthetic branches instead of leaving diagnostic-only
	 * configuration behind.
	 *
	 * @param string $memory_limit PHP memory-limit label to expose through CFG.
	 * @return array<string,mixed> Restoration state for CFG branches touched by the scan.
	 */
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

	/**
	 * Restores configuration branches changed for an embedded diagnostic batch.
	 *
	 * The state comes from apply_diagnostic_runtime_overrides() and distinguishes
	 * absent branches from present branches with null values. Empty state is a
	 * no-op, allowing callers to use this from finally blocks without checking
	 * whether CFG was available.
	 *
	 * @param array<string,mixed> $state CFG restoration state captured before the batch.
	 * @return void
	 */
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

	/**
	 * Converts a PHP memory-limit label into bytes.
	 *
	 * The parser follows PHP shorthand suffixes and preserves -1 as the unlimited
	 * sentinel used by ini_get('memory_limit'). Unknown or suffixless values fall
	 * through to integer byte counts.
	 *
	 * @param string|false $value Raw memory-limit value from PHP or configuration.
	 * @return int Byte count, or -1 when memory is unlimited or unspecified.
	 */
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

	/**
	 * Renders aggregate diagnostic metrics for the current scan.
	 *
	 * Counts are derived from normalized Dpanel trace semantics: explicit passed
	 * values increment success or failure totals, warning levels remain non-fatal,
	 * and an active surface error contributes to the failed card. When scan state
	 * is present, queue progress is appended as an additional metric.
	 *
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @param ?string $error Surface-level error to include in failure counts.
	 * @param ?array<string,mixed> $scan Optional persisted scan state.
	 * @return string HTML metric section for the Flightdeck page.
	 */
	private static function summary_cards(array $trace, ?string $error, ?array $scan=null): string {
		$summary=[
			'total'=>count($trace),
			'passed'=>0,
			'warnings'=>0,
			'failed'=>0,
			'executed_tests'=>0,
			'worker_issues'=>0,
			'runtime_issues'=>0,
			'test_failures'=>0,
		];
		foreach($trace as $entry){
			$level=strtolower((string)($entry['level'] ?? 'info'));
			$type=(string)($entry['type'] ?? '');
			if(($entry['passed'] ?? null)===true){
				$summary['passed']+=(int)($entry['unit_test_pass_count'] ?? 1);
			}
			if($type==='unit_test' && ($entry['passed'] ?? null)===true && isset($entry['unit_test_pass_count'])){
				$summary['executed_tests']+=(int)($entry['unit_test_pass_count'] ?? 1);
			}
			elseif(in_array($type, ['unit_test', 'code_unit_test'], true) && isset($entry['test_name']) && (($entry['passed'] ?? null)!==null || isset($entry['execution_time']))){
				$summary['executed_tests']++;
			}
			elseif($type==='unit_test_worker' && isset($entry['manifest'], $entry['case_index'])){
				$summary['executed_tests']++;
			}
			if($level==='warning'){
				$summary['warnings']++;
			}
			if(in_array($level, ['fatal', 'error'], true) || ($entry['passed'] ?? null)===false){
				$summary['failed']++;
				if(in_array($type, ['unit_test_worker', 'code_unit_test_worker'], true)){
					$summary['worker_issues']++;
				}
				elseif(in_array($type, ['php_exception', 'diagnostic_exception'], true)){
					$summary['runtime_issues']++;
				}
				elseif(in_array($type, ['unit_test', 'code_unit_test', 'performance_warning'], true)){
					$summary['test_failures']++;
				}
			}
		}
		if($error!==null){
			$summary['failed']++;
		}
		$module_processed=0;
		$module_total=0;
		$manifest_processed=0;
		$manifest_total=0;
		$worker_cases=null;
		if(is_array($scan) && is_array($scan['test_inventory'] ?? null)){
			$inventory=$scan['test_inventory'];
			$worker_cases=(int)($inventory['worker_test_cases'] ?? $inventory['test_cases'] ?? 0);
		}
		if(is_array($scan) && isset($scan['cursor'], $scan['queue'])){
			$module_processed=min((int)$scan['cursor'], count((array)$scan['queue']));
			$module_total=count((array)$scan['queue']);
		}
		if(is_array($scan) && isset($scan['manifest_cursor'], $scan['manifest_queue'])){
			$manifest_processed=min((int)$scan['manifest_cursor'], count((array)$scan['manifest_queue']));
			$manifest_total=count((array)$scan['manifest_queue']);
		}
		$done=is_array($scan) && ($scan['done'] ?? false)===true;
		$running=is_array($scan) && $done!==true && ((int)($scan['batches'] ?? 0)>0 || $module_total>0 || $manifest_total>0);
		$has_failures=$summary['failed']>0 || $summary['worker_issues']>0 || $summary['runtime_issues']>0 || $summary['test_failures']>0;
		$tone=$has_failures ? 'bad' : ($summary['warnings']>0 ? 'warn' : 'good');
		$title=$has_failures
			? 'Attention needed'
			: ($done ? 'Scan complete' : ($running ? 'Scan running' : 'Ready to scan'));
		$phase='Module scan';
		if($module_processed>=$module_total && $manifest_total>0 && $manifest_processed<$manifest_total){
			$phase='Unit tests';
		}elseif($done){
			$phase='Complete';
		}
		$lead=$has_failures
			? 'Open diagnostics below; failures are prioritized in the table.'
			: ($summary['warnings']>0
				? 'No failures so far. Warnings are listed below for review.'
				: ($running ? 'No failures so far. The browser will continue bounded batches.' : 'Choose a scope to begin diagnostics.'));
		$pills=[
			['Modules', $module_total>0 ? $module_processed.' / '.$module_total : 'not started', ''],
		];
		if($worker_cases!==null){
			$pills[]=['Tests', $summary['executed_tests'].' / '.$worker_cases, $manifest_total>0 && $manifest_processed<$manifest_total ? 'queued' : ''];
		}
		$pills[]=['Findings', (string)$summary['total'], ''];
		if($summary['warnings']>0){
			$pills[]=['Warnings', (string)$summary['warnings'], 'warn'];
		}
		if($summary['failed']>0){
			$pills[]=['Failed', (string)$summary['failed'], 'bad'];
		}
		if($summary['worker_issues']>0){
			$pills[]=['Worker issues', (string)$summary['worker_issues'], 'bad'];
		}
		$details=[
			['Passed rows', (string)$summary['passed']],
			['Warnings', (string)$summary['warnings']],
			['Failed rows', (string)$summary['failed']],
			['Runtime issues', (string)$summary['runtime_issues']],
			['Test failures', (string)$summary['test_failures']],
			['Worker issues', (string)$summary['worker_issues']],
			['Modules processed', $module_total>0 ? $module_processed.' / '.$module_total : '0 / 0'],
			['Unit-test workers', $manifest_total>0 ? $manifest_processed.' / '.$manifest_total : '0 / 0'],
		];
		$html='<section class="fd-dpanel-overview fd-dpanel-'.$tone.'">';
		$html.='<div class="fd-dpanel-overview-main">';
		$html.='<div><span class="fd-dpanel-kicker">'.self::e($phase).'</span><h2>'.self::e($title).'</h2><p>'.self::e($lead).'</p></div>';
		$html.='<div class="fd-dpanel-pills">';
		foreach($pills as $pill){
			$html.='<span class="fd-dpanel-pill'.($pill[2]!=='' ? ' fd-dpanel-pill-'.$pill[2] : '').'"><b>'.self::e($pill[0]).'</b> '.self::e($pill[1]).'</span>';
		}
		$html.='</div></div>';
		$html.='<details class="fd-details fd-dpanel-run-details"><summary>Run details</summary>';
		$html.=dataphyre_flightdeck_view::table(['Item', 'Value'], array_map(
			static fn(array $row): array=>[
				dataphyre_flightdeck_view::e((string)$row[0]),
				dataphyre_flightdeck_view::e((string)$row[1]),
			],
			$details
		));
		$html.='</details>';
		return $html.'</section>';
	}

	/**
	 * Returns a compact status line for a running or completed scan.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @return string Human-readable scan phase summary.
	 */
	private static function scan_phase_message(?array $scan): string {
		if(!is_array($scan)){
			return '';
		}
		$module_processed=min((int)($scan['cursor'] ?? 0), count((array)($scan['queue'] ?? [])));
		$module_total=count((array)($scan['queue'] ?? []));
		$manifest_processed=min((int)($scan['manifest_cursor'] ?? 0), count((array)($scan['manifest_queue'] ?? [])));
		$manifest_total=count((array)($scan['manifest_queue'] ?? []));
		if(($scan['done'] ?? false)===true){
			return 'Complete. Modules '.$module_processed.' / '.$module_total.'; unit-test workers '.$manifest_processed.' / '.$manifest_total.'.';
		}
		if($module_total>0 && $module_processed<$module_total){
			return 'Scanning modules '.$module_processed.' / '.$module_total.'. Unit tests run after module diagnostics.';
		}
		if($manifest_total>0 && $manifest_processed<$manifest_total){
			return 'Running unit-test workers '.$manifest_processed.' / '.$manifest_total.'.';
		}
		return 'Preparing diagnostic batches.';
	}

	/**
	 * Renders normalized diagnostic entries as a Flightdeck table.
	 *
	 * Each Dpanel trace payload may use a different schema depending on whether it
	 * came from a module check, unit test, tracelog capture, or throwable. This
	 * method delegates schema normalization row by row and keeps all cell HTML
	 * escaped or generated by Flightdeck view helpers.
	 *
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @return string Empty-state markup or a populated diagnostic table.
	 */
	private static function diagnostics_table(array $trace): string {
		if($trace===[]){
			return '<p class="fd-muted">No diagnostic scan has been run yet.</p>';
		}
		$total=count($trace);
		$trace=self::visible_diagnostic_entries($trace);
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
		$html='';
		if(count($trace)<$total){
			$html.='<p class="fd-muted">Showing '.self::e((string)count($trace)).' of '.self::e((string)$total).' diagnostic entries. Errors and warnings are prioritized so the page stays responsive after large test runs.</p>';
		}
		return $html.dataphyre_flightdeck_view::table(['Status', 'Type', 'Target', 'Message'], $rows);
	}

	/**
	 * Renders the unit-test inventory separately from browser-safe execution.
	 *
	 * JSON manifests are counted from decoded data; code-defined PHP tests are
	 * listed only through the reusable child worker when available. Execution
	 * counts stay separate so skipped tests remain visible.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @return string Unit-test inventory card, or an empty string before a scan exists.
	 */
	private static function test_inventory_card(?array $scan): string {
		if(!is_array($scan) || !is_array($scan['test_inventory'] ?? null)){
			return '';
		}
		$inventory=$scan['test_inventory'];
		$modules=is_array($inventory['modules'] ?? null) ? $inventory['modules'] : [];
		arsort($modules, SORT_NUMERIC);
		$rows=[];
		foreach(array_slice($modules, 0, 12, true) as $module=>$count){
			$rows[]=[
				self::e((string)$module),
				self::e((string)(int)$count),
			];
		}
		$body='<div class="fd-test-inventory">';
		$body.='<details class="fd-details fd-dpanel-inventory-details"><summary>Unit tests: '
			.self::e((string)(int)($inventory['test_cases'] ?? 0)).' cases in '
			.self::e((string)((int)($inventory['json_manifests'] ?? $inventory['manifests'] ?? 0) + (int)($inventory['code_files'] ?? 0))).' files</summary>';
		$body.='<p class="fd-muted">Unit tests run in bounded workers after module diagnostics. Fatal tests, exits, and module side effects stay outside the Flightdeck request.</p>';
		$body.=dataphyre_flightdeck_view::table(['Item', 'Count'], [
			[self::e('JSON manifests'), self::e((string)(int)($inventory['json_manifests'] ?? $inventory['manifests'] ?? 0))],
			[self::e('JSON cases'), self::e((string)(int)($inventory['json_test_cases'] ?? 0))],
			[self::e('Code test files'), self::e((string)(int)($inventory['code_files'] ?? 0))],
			[self::e('Code cases'), self::e((string)(int)($inventory['code_test_cases'] ?? 0))],
			[self::e('Grouped code cases'), self::e((string)(int)($inventory['code_grouped_cases'] ?? 0))],
			[self::e('Dependent code cases'), self::e((string)(int)($inventory['code_dependent_cases'] ?? 0))],
			[self::e('Declared cases'), self::e((string)(int)($inventory['test_cases'] ?? 0))],
			[self::e('JSON worker cases'), self::e((string)(int)($inventory['manifest_worker_test_cases'] ?? 0))],
			[self::e('Code worker cases'), self::e((string)(int)($inventory['code_worker_test_cases'] ?? 0))],
			[self::e('Deferred cases'), self::e((string)(int)($inventory['deferred_test_cases'] ?? 0))],
			[self::e('Skipped code files'), self::e((string)(int)($inventory['code_skipped_files'] ?? 0))],
			[self::e('Code discovery issues'), self::e((string)(int)($inventory['code_discovery_errors'] ?? 0))],
			[self::e('Malformed files'), self::e((string)(int)($inventory['malformed'] ?? 0))],
		]);
		if($rows!==[]){
			$body.='<details class="fd-details"><summary>Modules with tests</summary>';
			$body.=dataphyre_flightdeck_view::table(['Module', 'Cases'], $rows);
			if(count($modules)>count($rows)){
				$body.='<p class="fd-muted">Showing the largest '.self::e((string)count($rows)).' of '.self::e((string)count($modules)).' modules with discovered test cases.</p>';
			}
			$body.='</details>';
		}
		$body.='</details></div>';
		return $body;
	}

	/**
	 * Selects the rows worth rendering from a large diagnostic trace.
	 *
	 * Summary metrics still use the full trace. The table is capped because full
	 * unit-test sweeps can generate thousands of rows, which is not useful in one
	 * browser response and can exhaust server/browser memory at scan completion.
	 *
	 * @param array<int,array<string,mixed>> $trace Diagnostic entries accumulated so far.
	 * @return array<int,array<string,mixed>> Entries selected for table rendering.
	 */
	private static function visible_diagnostic_entries(array $trace): array {
		$limit=300;
		if(count($trace)<=$limit){
			return $trace;
		}
		$important=[];
		$other=[];
		foreach($trace as $entry){
			$level=strtolower((string)($entry['level'] ?? 'info'));
			if(in_array($level, ['fatal', 'error', 'warning'], true) || ($entry['passed'] ?? null)===false){
				$important[]=$entry;
			}
			else
			{
				$other[]=$entry;
			}
		}
		$selected=array_slice($important, 0, $limit);
		if(count($selected)<$limit){
			$selected=array_merge($selected, array_slice($other, -($limit - count($selected))));
		}
		return $selected;
	}

	/**
	 * Converts one heterogeneous Dpanel entry into the table row view model.
	 *
	 * Throwable payloads take precedence because they identify the true failure
	 * site. Tracelog groups are summarized by worst severity, and unit-test context
	 * is preserved in collapsible JSON so the overview remains scan-friendly while
	 * Dataphyre developers can still inspect raw evidence.
	 *
	 * @param array<string,mixed> $entry Raw or sanitized Dpanel trace entry.
	 * @param int $index Original zero-based trace index retained for future ordering use.
	 * @return array{status:string,type:string,level:string,target:string,target_full:string,message:string,details:string} Table row view model.
	 */
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
		elseif(isset($entry['stdout']) || isset($entry['stderr'])){
			$details=self::details('Worker Output', json_encode(array_filter([
				'stdout'=>$entry['stdout'] ?? null,
				'stderr'=>$entry['stderr'] ?? null,
			], static fn(mixed $value): bool=>$value!==null && $value!==''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
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

	/**
	 * Builds escaped collapsible code details for a diagnostic row.
	 *
	 * Empty content intentionally emits no markup so rows without supporting
	 * context remain compact.
	 *
	 * @param string $summary Details disclosure label.
	 * @param string $content Plain-text diagnostic context.
	 * @return string HTML details block, or an empty string.
	 */
	private static function details(string $summary, string $content): string {
		if($content===''){
			return '';
		}
		return '<details class="fd-details"><summary>'.self::e($summary).'</summary>'.dataphyre_flightdeck_view::code($content).'</details>';
	}

	/**
	 * Builds collapsible details for trusted markup generated by Flightdeck helpers.
	 *
	 * Callers pass only table or code markup already escaped at the cell level; the
	 * disclosure label is escaped here.
	 *
	 * @param string $summary Details disclosure label.
	 * @param string $html Trusted helper-generated HTML.
	 * @return string HTML details block, or an empty string.
	 */
	private static function details_html(string $summary, string $html): string {
		if($html===''){
			return '';
		}
		return '<details class="fd-details"><summary>'.self::e($summary).'</summary>'.$html.'</details>';
	}

	/**
	 * Summarizes a tracelog group by worst severity and severity counts.
	 *
	 * Unknown tracelog types are treated as informational so malformed debug
	 * payloads cannot escalate status badges or break the diagnostic table.
	 *
	 * @param array<int,mixed> $entries Tracelog entries captured inside a Dpanel payload.
	 * @return array{level:string,parts:array<int,string>} Worst level and human-readable count fragments.
	 */
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

	/**
	 * Renders captured tracelog entries as a nested Flightdeck table.
	 *
	 * Non-array tracelog values are ignored because Dpanel may receive legacy or
	 * partially sanitized payloads. File paths remain available in title attributes
	 * while the visible target stays compact.
	 *
	 * @param array<int,mixed> $entries Tracelog entries captured inside a Dpanel payload.
	 * @return string Nested Flightdeck table markup, or an empty string.
	 */
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

	/**
	 * Builds the scan control forms for the Dpanel surface.
	 *
	 * Forms include CSRF fields when Flightdeck authentication is loaded. Active
	 * scans expose continue and pause/resume controls ahead of the scope selectors
	 * so automatic scans can be inspected or resumed without losing session state.
	 *
	 * @param ?array<string,mixed> $scan Current scan state, if one exists.
	 * @return string HTML action row containing POST forms.
	 */
	private static function actions(?array $scan=null): string {
		$csrf=self::csrf_input();
		$html='<div class="fd-action-row">';
		if(is_array($scan) && ($scan['done'] ?? true)!==true && !empty($scan['token'])){
			$label=((int)($scan['batches'] ?? 0)===0 && (int)($scan['cursor'] ?? 0)===0) ? 'Begin Scan' : 'Continue Scan';
			if(((int)($scan['cursor'] ?? 0))>=count((array)($scan['queue'] ?? [])) && ($scan['test_done'] ?? true)!==true){
				$label='Run Test Worker';
			}
			elseif(((int)($scan['cursor'] ?? 0))>=count((array)($scan['queue'] ?? [])) && ($scan['test_done'] ?? true)===true && ($scan['manifest_done'] ?? true)!==true){
				$label='Run Unit-Test Worker';
			}
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

	/**
	 * Checks whether a POST action can mutate Dpanel scan state.
	 *
	 * Starting a new scan requires a fresh Flightdeck CSRF token. Continuing,
	 * pausing, and resuming an existing scan may also use the random persisted
	 * scan token, so long browser-driven scans do not fail when the hourly
	 * Flightdeck CSRF bucket rotates between batches.
	 *
	 * @param string $action Requested Dpanel action.
	 * @param string $scan_token Persisted scan token from the form.
	 * @return bool True when the request passes the available CSRF boundary.
	 */
	private static function valid_post(string $action, string $scan_token): bool {
		if(self::valid_csrf()){
			return true;
		}
		if(in_array($action, ['continue', 'pause', 'resume'], true) && self::load_scan($scan_token)!==null){
			return true;
		}
		return false;
	}

	/**
	 * Checks the Flightdeck CSRF token when authentication support is available.
	 *
	 * Development or unauthenticated runtimes can omit the auth class; in that mode
	 * the surface does not invent its own token system and treats the request as
	 * valid.
	 *
	 * @return bool True when the request passes the available CSRF boundary.
	 */
	private static function valid_csrf(): bool {
		return class_exists('dataphyre_flightdeck_auth', false)!==true
			|| dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)===true;
	}

	/**
	 * Renders the hidden Flightdeck CSRF input when authentication support exists.
	 *
	 * @return string Hidden input markup, or an empty string in unauthenticated runtimes.
	 */
	private static function csrf_input(): string {
		return class_exists('dataphyre_flightdeck_auth', false)
			? '<input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'">'
			: '';
	}

	/**
	 * Provides the Dpanel surface CSS served through Flightdeck's asset endpoint.
	 *
	 * The stylesheet is embedded because this surface owns only a small amount of
	 * diagnostic-specific layout around shared Flightdeck components.
	 *
	 * @return string CSS stylesheet body.
	 */
	private static function style(): string {
		return '
.fd-action-row{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.fd-action-row form{margin:0}
.fd-action-row button[disabled]{cursor:wait;opacity:.72}
.fd-secondary{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:1px solid rgba(14,165,233,.22);background:#e0f2fe;color:#075985}
.fd-dpanel-overview{display:grid;gap:10px;margin-bottom:12px;padding:14px 16px;border:1px solid rgba(15,23,42,.1);border-left-width:5px;border-radius:10px;background:#fff}
.fd-dpanel-overview-main{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.fd-dpanel-overview h2{margin:2px 0 4px;font-size:18px;line-height:1.2}
.fd-dpanel-overview p{margin:0;color:#475569}
.fd-dpanel-kicker{display:block;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase}
.fd-dpanel-good{border-left-color:#16a34a}
.fd-dpanel-warn{border-left-color:#d97706}
.fd-dpanel-bad{border-left-color:#dc2626}
.fd-dpanel-pills{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap;max-width:620px}
.fd-dpanel-pill{display:inline-flex;gap:5px;align-items:center;border:1px solid rgba(15,23,42,.1);border-radius:999px;padding:6px 9px;background:#f8fafc;color:#334155;font-size:12px;white-space:nowrap}
.fd-dpanel-pill b{color:#0f172a}
.fd-dpanel-pill-warn{border-color:rgba(217,119,6,.28);background:#fff7ed;color:#9a3412}
.fd-dpanel-pill-bad{border-color:rgba(220,38,38,.28);background:#fef2f2;color:#991b1b}
.fd-dpanel-status{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 12px;padding:9px 12px;border:1px solid rgba(15,23,42,.08);border-radius:8px;background:#f8fafc;color:#334155}
.fd-dpanel-status b{color:#0f172a}
.fd-dpanel-status span{color:#475569}
.fd-dpanel-status small{color:#64748b}
.fd-scan-status{margin-bottom:12px}
.fd-details{margin-top:12px}
.fd-details summary{cursor:pointer;color:#075985;font-weight:900}
.fd-details pre{margin-top:10px}
.fd-details .fd-table-wrap{margin-top:10px}
.fd-details .fd-code{margin:0;padding:10px;border-radius:12px;line-height:1.35}
.fd-dpanel-run-details{margin-top:0}
.fd-dpanel-inventory-details{margin-bottom:12px;padding:10px 12px;border:1px solid rgba(15,23,42,.08);border-radius:8px;background:#fff}
.fd-dpanel-inventory-details>summary{color:#334155}
';
	}

	/**
	 * Decides whether the current page may immediately submit the next batch.
	 *
	 * Autorun is only safe when no batch is marked active. An active marker means
	 * another request is still running or previously failed before clearing state;
	 * in both cases immediate resubmission would loop against the same module.
	 *
	 * @param ?array<string,mixed> $scan Current scan state.
	 * @return bool True when the browser may submit the continue form now.
	 */
	private static function scan_can_continue_automatically(?array $scan): bool {
		return is_array($scan)
			&& ($scan['done'] ?? true)!==true
			&& ($scan['autorun'] ?? true)===true
			&& !empty($scan['token'])
			&& (string)($scan['active_module'] ?? '')==='';
	}

	/**
	 * Builds the browser-side AJAX controller for Dpanel scans.
	 *
	 * The script intercepts Dpanel forms, posts the selected action with fetch,
	 * replaces only the scan content, and schedules the next continue request while
	 * autorun remains enabled. Plain form POST remains the no-JS fallback.
	 *
	 * @return string Script tag markup.
	 */
	private static function client_script(): string {
		return '<script>
document.addEventListener("DOMContentLoaded", function(){
	const root=document.getElementById("fd-dpanel-content");
	if(!root || !window.fetch || !window.FormData){
		return;
	}
	let running=false;
	let timer=null;
	const replacePart=function(name, html){
		const part=root.querySelector("[data-dpanel-part=\"" + name + "\"]");
		if(!part){
			return false;
		}
		if(part.innerHTML!==html){
			part.innerHTML=html;
		}
		return true;
	};
	const applyPayload=function(payload){
		if(payload && payload.parts){
			let applied=true;
			applied=replacePart("summary", payload.parts.summary || "") && applied;
			applied=replacePart("status", payload.parts.status || "") && applied;
			applied=replacePart("inventory", payload.parts.inventory || "") && applied;
			applied=replacePart("diagnostics", payload.parts.diagnostics || "") && applied;
			if(applied){
				return;
			}
		}
		root.innerHTML=(payload && payload.content) ? payload.content : "";
	};
	const setBusy=function(form, busy){
		Array.prototype.forEach.call(form.querySelectorAll("button"), function(button){
			button.disabled=busy;
		});
	};
	const continueNext=function(){
		window.clearTimeout(timer);
		const form=document.getElementById("fd-dpanel-continue-form");
		if(!form || running){
			return;
		}
		timer=window.setTimeout(function(){
			send(form);
		}, 650);
	};
	const send=function(form){
		if(running){
			return;
		}
		running=true;
		setBusy(form, true);
		const submitter=form.dataset.fdSubmitter || "";
		const data=new FormData(form);
		data.set("fd_dpanel_ajax", "1");
		if(submitter!=="" && !data.has("fd_dpanel_scope")){
			data.set("fd_dpanel_scope", submitter);
		}
		fetch(window.location.href, {
			method:"POST",
			body:data,
			headers:{"X-Requested-With":"fetch"},
			credentials:"same-origin"
		}).then(function(response){
			if(!response.ok){
				throw new Error("Request failed");
			}
			return response.json();
		}).then(function(payload){
			applyPayload(payload);
			running=false;
			if(payload && payload.autorun===true && payload.done!==true && payload.error===null){
				continueNext();
			}
		}).catch(function(){
			setBusy(form, false);
			running=false;
		});
	};
	document.addEventListener("click", function(event){
		const button=event.target.closest("#fd-dpanel-content form button[name=fd_dpanel_scope]");
		if(button){
			button.form.dataset.fdSubmitter=button.value;
		}
	});
	document.addEventListener("submit", function(event){
		const form=event.target.closest("#fd-dpanel-content form");
		if(!form){
			return;
		}
		event.preventDefault();
		send(form);
	});
	continueNext();
});
</script>';
	}

	/**
	 * Initializes a new persisted Dpanel scan state.
	 *
	 * Scan state is stored in the PHP session under a random token and contains
	 * the queue cursor, accumulated sanitized trace, autorun preference, and active
	 * module markers used for timeout recovery across browser requests.
	 *
	 * @param string $scope Runtime, app, or all module scope label.
	 * @return array<string,mixed> Newly prepared scan state.
	 */
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
			'test_inventory'=>[],
			'test_queue'=>[],
			'test_cursor'=>0,
			'test_done'=>true,
			'active_phase'=>null,
			'last_test_module'=>null,
			'last_failed_test_module'=>null,
		];
		$state=self::populate_scan_queue($state);
		self::store_scan($state);
		return $state;
	}

	/**
	 * Loads and advances an existing scan by token.
	 *
	 * Missing tokens return null so the dispatcher can tell the browser to start a
	 * new scan. Completed scans are returned unchanged to make duplicate form
	 * submissions idempotent.
	 *
	 * @param string $token Persisted scan token from the POST form.
	 * @return ?array<string,mixed> Updated scan state, completed state, or null when unavailable.
	 */
	private static function continue_scan(string $token): ?array {
		$state=self::load_scan($token);
		if($state===null){
			return null;
		}
		if(($state['done'] ?? false)===true){
			return $state;
		}
		if((string)($state['active_module'] ?? '')!==''){
			return self::recover_stalled_scan($state) ?? $state;
		}
		return self::run_scan_batch($state);
	}

	/**
	 * Runs the next bounded batch and persists the updated scan cursor.
	 *
	 * The active module is written before execution so a later request can detect
	 * that the previous batch stalled. Batch traces are sanitized before entering
	 * the session, and zero-progress batches stop the scan to avoid infinite
	 * browser resubmission loops.
	 *
	 * @param array<string,mixed> $state Current scan state loaded from the session.
	 * @return array<string,mixed> Updated scan state after the batch.
	 */
	private static function run_scan_batch(array $state): array {
		$queue=(array)($state['queue'] ?? []);
		$cursor=(int)($state['cursor'] ?? 0);
		if($cursor>=count($queue)){
			return self::run_test_worker_batch($state);
		}
		$remaining=array_slice($queue, $cursor);
		$active_module=(string)($remaining[0] ?? '');
		if($active_module!==''){
			$state['active_phase']='module';
			$state['active_module']=$active_module;
			$state['active_started_at']=time();
			$state['updated_at']=time();
			self::store_scan($state);
		}
		$batch=self::run_scope((string)($state['scope'] ?? 'all'), $remaining);
		$batch_trace=is_array($batch['trace'] ?? null) ? $batch['trace'] : [];
		$this_batch=max(0, (int)($batch['processed'] ?? 0));
		$state['trace']=array_merge((array)($state['trace'] ?? []), self::sanitize_trace_entries($batch_trace));
		$state['cursor']=min(count($queue), $cursor + $this_batch);
		$state['done']=$state['cursor']>=count($queue) && ($state['test_done'] ?? true)===true && ($state['manifest_done'] ?? true)===true;
		$state['batches']=(int)($state['batches'] ?? 0) + 1;
		$state['updated_at']=time();
		$state['last_batch_count']=$this_batch;
		$state['last_module']=$active_module !== '' ? $active_module : ($state['last_module'] ?? null);
		$state['active_module']=null;
		$state['active_phase']=null;
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

	/**
	 * Runs one module's unit tests inside a child PHP worker.
	 *
	 * The parent request only starts the worker, enforces the wall-clock timeout,
	 * captures bounded output, and stores serializable trace rows. Fatal errors,
	 * exits, and memory exhaustion in the child therefore become diagnostic rows
	 * instead of terminating the Flightdeck request.
	 *
	 * @param array<string,mixed> $state Current scan state loaded from the session.
	 * @return array<string,mixed> Updated scan state after one worker job.
	 */
	private static function run_test_worker_batch(array $state): array {
		$test_queue=(array)($state['test_queue'] ?? []);
		$test_cursor=(int)($state['test_cursor'] ?? 0);
		if($test_cursor>=count($test_queue)){
			$state['test_done']=true;
			return self::run_manifest_worker_batch($state);
		}
		$module=(string)$test_queue[$test_cursor];
		return self::run_worker_job_batch($state, [
			'type'=>'module',
			'module'=>$module,
			'label'=>$module,
		], 'test_cursor', count($test_queue));
	}

	/**
	 * Runs deferred unit-test file jobs in bounded child workers.
	 *
	 * @param array<string,mixed> $state Current scan state loaded from the session.
	 * @return array<string,mixed> Updated scan state after one unit-test file worker.
	 */
	private static function run_manifest_worker_batch(array $state): array {
		$manifest_queue=(array)($state['manifest_queue'] ?? []);
		$manifest_cursor=(int)($state['manifest_cursor'] ?? 0);
		if($manifest_cursor>=count($manifest_queue)){
			$state['manifest_done']=true;
			$state['done']=true;
			self::store_scan($state);
			return $state;
		}
		$started=microtime(true);
		$limit=self::manifest_worker_batch_limit();
		$ran=0;
		while($manifest_cursor<count($manifest_queue) && $ran<$limit && (microtime(true)-$started)<self::scan_batch_seconds()){
			$job=is_array($manifest_queue[$manifest_cursor] ?? null) ? $manifest_queue[$manifest_cursor] : [];
			$manifest=(string)($job['path'] ?? '');
			$module=(string)($job['module'] ?? 'manifest');
			$kind=(string)($job['kind'] ?? 'json');
			$case_index=(int)($job['case_index'] ?? 0);
			$label=self::unit_test_worker_job_label($job);
			$state['active_phase']='unit_test_manifest';
			$state['active_module']=$label;
			$state['active_started_at']=time();
			$state['updated_at']=time();
			self::store_scan($state);
			$result=$kind==='code'
				? self::run_code_unit_test_worker($module, $manifest, $case_index)
				: self::run_unit_test_worker($module, $manifest, $case_index);
			$trace=is_array($result['trace'] ?? null) ? $result['trace'] : [];
			foreach($trace as $index=>$entry){
				if(is_array($entry) && !isset($entry['module'])){
					$trace[$index]['module']=$module;
				}
			}
			$state['trace']=array_merge((array)($state['trace'] ?? []), self::sanitize_trace_entries($trace));
			$manifest_cursor++;
			$ran++;
			$state['manifest_cursor']=$manifest_cursor;
			$state['last_test_module']=$label;
			if(($result['passed'] ?? false)!==true){
				$state['last_failed_test_module']=$label;
			}
		}
		$state['manifest_done']=$manifest_cursor>=count($manifest_queue);
		$state['done']=((int)($state['cursor'] ?? 0))>=count((array)($state['queue'] ?? [])) && ($state['test_done'] ?? false)===true && ($state['manifest_done'] ?? true)===true;
		$state['batches']=(int)($state['batches'] ?? 0) + 1;
		$state['updated_at']=time();
		$state['last_batch_count']=$ran;
		$state['active_phase']=null;
		$state['active_module']=null;
		$state['active_started_at']=null;
		self::store_scan($state);
		return $state;
	}

	/**
	 * Executes one queued worker job and advances its cursor.
	 *
	 * @param array<string,mixed> $state Current scan state.
	 * @param array<string,string> $job Worker job description.
	 * @param string $cursor_key Cursor field to advance.
	 * @param int $queue_count Number of jobs in the active queue.
	 * @return array<string,mixed> Updated scan state.
	 */
	private static function run_worker_job_batch(array $state, array $job, string $cursor_key, int $queue_count): array {
		$label=(string)($job['label'] ?? $job['module'] ?? '');
		$module=(string)($job['module'] ?? '');
		$phase=(string)($job['type'] ?? 'module')==='manifest' ? 'unit_test_manifest' : 'unit_test';
		$state['active_phase']=$phase;
		$state['active_module']=$label;
		$state['active_started_at']=time();
		$state['updated_at']=time();
		self::store_scan($state);

		$result=self::run_unit_test_worker($module, (string)($job['manifest_path'] ?? ''), (int)($job['case_index'] ?? -1));
		$trace=is_array($result['trace'] ?? null) ? $result['trace'] : [];
		foreach($trace as $index=>$entry){
			if(is_array($entry) && !isset($entry['module'])){
				$trace[$index]['module']=$module;
			}
		}
		$state['trace']=array_merge((array)($state['trace'] ?? []), self::sanitize_trace_entries($trace));
		$state[$cursor_key]=min($queue_count, (int)($state[$cursor_key] ?? 0) + 1);
		$state['test_done']=(int)($state['test_cursor'] ?? 0)>=count((array)($state['test_queue'] ?? []));
		$state['manifest_done']=(int)($state['manifest_cursor'] ?? 0)>=count((array)($state['manifest_queue'] ?? []));
		$state['done']=((int)($state['cursor'] ?? 0))>=count((array)($state['queue'] ?? [])) && ($state['test_done'] ?? false)===true && ($state['manifest_done'] ?? true)===true;
		$state['batches']=(int)($state['batches'] ?? 0) + 1;
		$state['updated_at']=time();
		$state['last_batch_count']=1;
		$state['last_test_module']=$label;
		$state['active_phase']=null;
		$state['active_module']=null;
		$state['active_started_at']=null;
		if(($result['passed'] ?? false)!==true){
			$state['last_failed_test_module']=$label;
		}
		self::store_scan($state);
		return $state;
	}

	/**
	 * Executes one module's Dpanel tests in a bounded PHP child process.
	 *
	 * @param string $module Module name to test.
	 * @return array{passed:bool,trace:array<int,array<string,mixed>>} Worker result.
	 */
	private static function run_unit_test_worker(string $module, string $manifest_path='', int $case_index=-1): array {
		if(!function_exists('proc_open')){
			return [
				'passed'=>false,
				'trace'=>[[
					'type'=>'unit_test_worker',
					'level'=>'error',
					'module'=>$module,
					'message'=>'Unit-test worker cannot run because proc_open is unavailable in this PHP environment.',
					'passed'=>false,
				]],
			];
		}
		$worker=self::unit_test_worker_script();
		if($worker==='' || !is_file($worker)){
			return [
				'passed'=>false,
				'trace'=>[[
					'type'=>'unit_test_worker',
					'level'=>'error',
					'module'=>$module,
					'message'=>'Unit-test worker script is unavailable.',
					'passed'=>false,
				]],
			];
		}
		$payload=[
			'module'=>$module,
			'manifest_path'=>$manifest_path,
			'case_index'=>$case_index,
			'rootpath'=>defined('ROOTPATH') ? ROOTPATH : [],
			'timeout_seconds'=>self::unit_test_worker_timeout_seconds(),
			'memory_limit'=>self::unit_test_worker_memory_limit(),
		];
		return self::run_unit_test_payload_worker($module, $worker, $payload, 'Unit-test worker', 'unit_test_worker');
	}

	/**
	 * Executes one code-defined Dataphyre test case in a bounded PHP child process.
	 *
	 * @param string $module Inventory owner label.
	 * @param string $test_file Absolute PHP test file path.
	 * @param int $case_index Expanded code-test case index.
	 * @param string $mode Worker mode: run or list.
	 * @return array{passed:bool,trace:array<int,array<string,mixed>>}
	 */
	private static function run_code_unit_test_worker(string $module, string $test_file, int $case_index=0, string $mode='run'): array {
		if(!function_exists('proc_open')){
			return self::code_unit_test_worker_skip($module, $test_file, 'Code-defined unit tests were skipped because proc_open is unavailable in this PHP environment.');
		}
		$worker=self::code_unit_test_worker_script();
		if($worker==='' || !is_file($worker)){
			return self::code_unit_test_worker_skip($module, $test_file, 'Code-defined unit tests were skipped because common/dataphyre/testing/code_worker.php is unavailable.');
		}
		$payload=[
			'module'=>$module,
			'mode'=>$mode,
			'test_file'=>$test_file,
			'manifest_path'=>$test_file,
			'case_index'=>$case_index,
			'rootpath'=>defined('ROOTPATH') ? ROOTPATH : [],
			'timeout_seconds'=>self::unit_test_worker_timeout_seconds(),
			'memory_limit'=>self::unit_test_worker_memory_limit(),
		];
		return self::run_unit_test_payload_worker($module, $worker, $payload, 'Code unit-test worker', 'code_unit_test_worker');
	}

	/**
	 * Builds a non-fatal skip result for code-defined tests that cannot be run.
	 *
	 * @param string $module Inventory owner label.
	 * @param string $test_file Code test file path.
	 * @param string $message Warning text.
	 * @return array{passed:bool,trace:array<int,array<string,mixed>>}
	 */
	private static function code_unit_test_worker_skip(string $module, string $test_file, string $message): array {
		return [
			'passed'=>true,
			'trace'=>[[
				'type'=>'code_unit_test_worker',
				'level'=>'warning',
				'module'=>$module,
				'file'=>$test_file,
				'message'=>$message,
			]],
		];
	}

	/**
	 * Runs a prepared unit-test worker payload through a bounded child PHP process.
	 *
	 * @param string $module Owner label used in diagnostics.
	 * @param string $worker Absolute worker script path.
	 * @param array<string,mixed> $payload Serializable worker payload.
	 * @param string $worker_label Human-readable worker label for diagnostics.
	 * @param string $trace_type Trace type for worker control-plane rows.
	 * @return array{passed:bool,trace:array<int,array<string,mixed>>}
	 */
	private static function run_unit_test_payload_worker(string $module, string $worker, array $payload, string $worker_label, string $trace_type): array {
		$dir=self::worker_state_dir();
		if($dir==='' || (is_dir($dir)!==true && @mkdir($dir, 0775, true)!==true)){
			return [
				'passed'=>false,
				'trace'=>[[
					'type'=>$trace_type,
					'level'=>'error',
					'module'=>$module,
					'message'=>$worker_label.' state directory is unavailable.',
					'passed'=>false,
				]],
			];
		}
		$id=sha1($module.'|'.json_encode($payload, JSON_UNESCAPED_SLASHES).'|'.microtime(true).'|'.bin2hex(random_bytes(6)));
		$payload_path=$dir.'/'.$id.'.payload.json';
		$output_path=$dir.'/'.$id.'.result.json';
		$payload['output_path']=$output_path;
		if(@file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false){
			return [
				'passed'=>false,
				'trace'=>[[
					'type'=>$trace_type,
					'level'=>'error',
					'module'=>$module,
					'message'=>$worker_label.' payload could not be written.',
					'passed'=>false,
				]],
			];
		}
		$memory_limit=(string)($payload['memory_limit'] ?? self::unit_test_worker_memory_limit());
		$command=self::php_binary().' -d memory_limit='.escapeshellarg($memory_limit).' '.escapeshellarg($worker).' '.escapeshellarg($payload_path);
		$descriptors=[
			0=>['pipe', 'r'],
			1=>['pipe', 'w'],
			2=>['pipe', 'w'],
		];
		$process=@proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell'=>true]);
		if(!is_resource($process)){
			@unlink($payload_path);
			return [
				'passed'=>false,
				'trace'=>[[
					'type'=>$trace_type,
					'level'=>'error',
					'module'=>$module,
					'message'=>$worker_label.' process could not be started.',
					'passed'=>false,
				]],
			];
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout='';
		$stderr='';
		$timed_out=false;
		$deadline=microtime(true) + self::unit_test_worker_timeout_seconds();
		do{
			$stdout.=self::read_worker_pipe($pipes[1]);
			$stderr.=self::read_worker_pipe($pipes[2]);
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){
				break;
			}
			if(microtime(true)>=$deadline){
				$timed_out=true;
				@proc_terminate($process);
				usleep(100000);
				$status=proc_get_status($process);
				if(($status['running'] ?? false)===true){
					@proc_terminate($process, 9);
				}
				break;
			}
			usleep(50000);
		}while(true);
		$stdout.=self::read_worker_pipe($pipes[1]);
		$stderr.=self::read_worker_pipe($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit_code=proc_close($process);
		$result=is_file($output_path) ? json_decode((string)file_get_contents($output_path), true) : null;
		@unlink($payload_path);
		@unlink($output_path);
		if($timed_out){
			return self::unit_test_worker_failure($module, $worker_label.' timed out after '.self::unit_test_worker_timeout_seconds().' second(s).', $stdout, $stderr, $trace_type);
		}
		if(!is_array($result)){
			return self::unit_test_worker_failure($module, $worker_label.' did not return a valid result. Exit code: '.$exit_code.'.', $stdout, $stderr, $trace_type);
		}
		$trace=is_array($result['trace'] ?? null) ? $result['trace'] : [];
		if((string)($result['output'] ?? '')!==''){
			$trace[]=[
				'type'=>$trace_type.'_output',
				'level'=>'warning',
				'module'=>$module,
				'message'=>$worker_label.' emitted output while running.',
				'output'=>(string)$result['output'],
				'passed'=>false,
			];
		}
		$response=[
			'passed'=>($result['passed'] ?? false)===true && $exit_code===0,
			'trace'=>$trace!==[] ? $trace : [[
				'type'=>$trace_type,
				'level'=>($exit_code===0 ? 'info' : 'error'),
				'module'=>$module,
				'message'=>$exit_code===0 ? $worker_label.' completed without diagnostic rows.' : $worker_label.' exited with code '.$exit_code.'.',
				'passed'=>$exit_code===0,
			]],
		];
		foreach(['cases', 'duration_seconds'] as $key){
			if(array_key_exists($key, $result)){
				$response[$key]=$result[$key];
			}
		}
		return $response;
	}

	/**
	 * Builds a structured worker failure result with bounded process output.
	 *
	 * @param string $module Module name being tested.
	 * @param string $message Failure message.
	 * @param string $stdout Captured stdout.
	 * @param string $stderr Captured stderr.
	 * @return array{passed:bool,trace:array<int,array<string,mixed>>} Failure result.
	 */
	private static function unit_test_worker_failure(string $module, string $message, string $stdout, string $stderr, string $trace_type='unit_test_worker'): array {
		$entry=[
			'type'=>$trace_type,
			'level'=>'error',
			'module'=>$module,
			'message'=>$message,
			'php_binary'=>self::php_binary(),
			'passed'=>false,
		];
		if(trim($stdout)!==''){
			$entry['stdout']=substr($stdout, -8192);
		}
		if(trim($stderr)!==''){
			$entry['stderr']=substr($stderr, -8192);
		}
		return [
			'passed'=>false,
			'trace'=>[$entry],
		];
	}

	/**
	 * Reads a worker pipe while keeping output bounded.
	 *
	 * @param resource $pipe Process pipe.
	 * @return string Captured output tail.
	 */
	private static function read_worker_pipe($pipe): string {
		$output='';
		while(is_resource($pipe) && ($chunk=fread($pipe, 8192))!==false && $chunk!==''){
			$output.=$chunk;
			if(strlen($output)>65536){
				$output=substr($output, -65536);
			}
		}
		return $output;
	}

	/**
	 * Returns the PHP executable used for worker children.
	 *
	 * @return string Shell-escaped PHP binary path.
	 */
	private static function php_binary(): string {
		$configured=trim((string)(getenv('DATAPHYRE_DPANEL_PHP_BINARY') ?: getenv('DATAPHYRE_PHP') ?: ''));
		if($configured!=='' && is_file($configured)){
			return escapeshellarg($configured);
		}
		$current=defined('PHP_BINARY') && PHP_BINARY!=='' ? (string)PHP_BINARY : '';
		$current_name=$current!=='' ? strtolower(basename($current)) : '';
		$current_is_cli=$current!=='' && !str_contains($current_name, 'cgi') && !str_contains($current_name, 'fpm');
		if($current_is_cli && is_file($current)){
			return escapeshellarg($current);
		}
		$bindir=defined('PHP_BINDIR') ? (string)PHP_BINDIR : '';
		$cli_candidate=$bindir!=='' ? rtrim($bindir, '/\\').DIRECTORY_SEPARATOR.(DIRECTORY_SEPARATOR==='\\' ? 'php.exe' : 'php') : '';
		if($cli_candidate!=='' && is_file($cli_candidate)){
			return escapeshellarg($cli_candidate);
		}
		return escapeshellarg($current!=='' ? $current : 'php');
	}

	/**
	 * Returns the worker script path.
	 *
	 * @return string Absolute worker script path.
	 */
	private static function unit_test_worker_script(): string {
		if(!defined('ROOTPATH') || empty(ROOTPATH['common_dataphyre_runtime'])){
			return '';
		}
		return rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/dpanel/kernel/dpanel.worker.php';
	}

	/**
	 * Returns the reusable runtime worker for code-defined PHP unit tests.
	 *
	 * @return string Absolute code worker script path.
	 */
	private static function code_unit_test_worker_script(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/testing/code_worker.php';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			return dirname(rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\')).'/testing/code_worker.php';
		}
		return '';
	}

	/**
	 * Returns whether code-defined PHP tests can be listed or run in a child worker.
	 *
	 * @return bool True when the reusable code worker and proc_open are available.
	 */
	private static function code_unit_test_worker_available(): bool {
		$worker=self::code_unit_test_worker_script();
		return function_exists('proc_open') && $worker!=='' && is_file($worker);
	}

	/**
	 * Returns the committed runtime code-test root when it can be resolved.
	 *
	 * @return string Absolute unit-test root, or empty string.
	 */
	private static function dataphyre_testing_unit_test_root(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/testing/unit_tests';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			return dirname(rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\')).'/testing/unit_tests';
		}
		return '';
	}

	/**
	 * Returns the directory used for transient worker payloads.
	 *
	 * @return string Absolute directory path.
	 */
	private static function worker_state_dir(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/flightdeck/dpanel_workers';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/flightdeck/dpanel_workers';
		}
		return '';
	}

	/**
	 * Returns the worker memory ceiling.
	 *
	 * @return string PHP memory_limit label.
	 */
	private static function unit_test_worker_memory_limit(): string {
		return '256M';
	}

	/**
	 * Returns the worker wall-clock timeout.
	 *
	 * @return int Timeout in seconds.
	 */
	private static function unit_test_worker_timeout_seconds(): int {
		return 8;
	}

	/**
	 * Skips a module whose previous batch appears to have stalled.
	 *
	 * Flightdeck cannot interrupt a dead PHP request, but the next request can see
	 * an old active-module marker. After a short grace window, the surface advances
	 * past that module, records a warning, and lets the remaining scan continue.
	 *
	 * @param ?array<string,mixed> $scan Last scan state from the session.
	 * @return ?array<string,mixed> Recovered scan state or the original value.
	 */
	private static function recover_stalled_scan(?array $scan): ?array {
		if(!is_array($scan) || ($scan['done'] ?? false)===true){
			return $scan;
		}
		$active_module=(string)($scan['active_module'] ?? '');
		$active_phase=(string)($scan['active_phase'] ?? 'module');
		$started_at=(int)($scan['active_started_at'] ?? 0);
		if($active_module==='' || $started_at<=0){
			return $scan;
		}
		if((time() - $started_at) < 20){
			return $scan;
		}
		if($active_phase==='unit_test_manifest'){
			$queue=(array)($scan['manifest_queue'] ?? []);
			$cursor=(int)($scan['manifest_cursor'] ?? 0);
			$current=is_array($queue[$cursor] ?? null) ? self::unit_test_worker_job_label($queue[$cursor]) : '';
		}
		else
		{
			$queue=$active_phase==='unit_test' ? (array)($scan['test_queue'] ?? []) : (array)($scan['queue'] ?? []);
			$cursor=$active_phase==='unit_test' ? (int)($scan['test_cursor'] ?? 0) : (int)($scan['cursor'] ?? 0);
			$current=(string)($queue[$cursor] ?? '');
		}
		if($current===$active_module){
			if($active_phase==='unit_test'){
				$scan['test_cursor']=$cursor + 1;
				$scan['test_done']=((int)$scan['test_cursor'])>=count($queue);
				$scan['last_failed_test_module']=$active_module;
			}
			elseif($active_phase==='unit_test_manifest'){
				$scan['manifest_cursor']=$cursor + 1;
				$scan['manifest_done']=((int)$scan['manifest_cursor'])>=count($queue);
				$scan['last_failed_test_module']=$active_module;
			}
			else
			{
				$scan['cursor']=$cursor + 1;
				$scan['last_failed_module']=$active_module;
			}
		}
		$scan['active_module']=null;
		$scan['active_phase']=null;
		$scan['active_started_at']=null;
		$scan['updated_at']=time();
		$scan['done']=((int)($scan['cursor'] ?? 0))>=count((array)($scan['queue'] ?? [])) && ($scan['test_done'] ?? true)===true && ($scan['manifest_done'] ?? true)===true;
		$scan['trace'][]=[
			'type'=>'diagnostic_runtime',
			'level'=>'warning',
			'module'=>$active_module,
			'message'=>'Skipped '.($active_phase==='unit_test_manifest' ? 'unit-test worker' : ($active_phase==='unit_test' ? 'unit-test worker' : 'module')).' `'.$active_module.'` after the previous batch stalled long enough to look like a timeout or recursion loop.',
			'passed'=>false,
		];
		self::store_scan($scan);
		return $scan;
	}

	/**
	 * Pauses a scan after a control-plane error so autorun cannot loop.
	 *
	 * @param string $token Persisted scan token from the POST form.
	 * @param string $message Error shown to the user.
	 * @return ?array<string,mixed> Paused scan state, or null when no scan exists.
	 */
	private static function pause_scan_after_error(string $token, string $message): ?array {
		$scan=self::load_scan($token) ?? self::last_scan();
		if(!is_array($scan) || ($scan['done'] ?? false)===true){
			return $scan;
		}
		$scan['autorun']=false;
		$scan['updated_at']=time();
		$scan['trace'][]=[
			'type'=>'diagnostic_runtime',
			'level'=>'warning',
			'module'=>'dpanel',
			'message'=>'Auto-scan paused: '.$message,
			'passed'=>false,
		];
		self::store_scan($scan);
		return $scan;
	}

	/**
	 * Populates the module queue for a newly initialized scan.
	 *
	 * Runtime and app scopes are resolved independently so missing folders produce
	 * warning trace entries without aborting the other scope. The initial trace is
	 * sanitized before persistence for consistency with later batches.
	 *
	 * @param array<string,mixed> $state Newly initialized scan state.
	 * @return array<string,mixed> Scan state with queue, initial trace, and done flag.
	 */
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
		$state['test_inventory']=self::unit_test_inventory_for_scope($scope);
		$trace=array_merge($trace, self::unit_test_inventory_trace($state['test_inventory']));
		$state['test_queue']=[];
		$state['manifest_queue']=self::manifest_test_queue_from_inventory($state['test_inventory'], $queue);
		$state['test_inventory']=self::apply_worker_inventory_counts($state['test_inventory'], $state['test_queue'], $state['manifest_queue']);
		$state['test_cursor']=0;
		$state['test_done']=$state['test_queue']===[];
		$state['manifest_cursor']=0;
		$state['manifest_done']=$state['manifest_queue']===[];
		$state['trace']=self::sanitize_trace_entries($trace);
		$state['done']=$queue===[] && ($state['test_done'] ?? true)===true && ($state['manifest_done'] ?? true)===true;
		return $state;
	}

	/**
	 * Converts inventory-level unit-test notices into scan diagnostics.
	 *
	 * @param array<string,mixed> $inventory Unit-test inventory summary.
	 * @return array<int,array<string,mixed>> Diagnostic rows to append to the scan trace.
	 */
	private static function unit_test_inventory_trace(array $inventory): array {
		$trace=[];
		foreach((array)($inventory['warnings'] ?? []) as $warning){
			if(!is_array($warning)){
				continue;
			}
			$trace[]=[
				'type'=>(string)($warning['type'] ?? 'unit_test_inventory'),
				'level'=>(string)($warning['level'] ?? 'warning'),
				'module'=>(string)($warning['module'] ?? 'unit_tests'),
				'message'=>(string)($warning['message'] ?? 'Unit-test inventory warning.'),
			]+array_filter([
				'file'=>$warning['file'] ?? null,
			], static fn(mixed $value): bool=>$value!==null && $value!=='');
		}
		return $trace;
	}

	/**
	 * Builds the worker execution queue from discovered unit-test modules.
	 *
	 * Dynamic and unscoped files remain visible in the inventory, but they do not
	 * map to a single module entrypoint and therefore wait for the file-worker
	 * phase rather than the module worker phase.
	 *
	 * @param array<string,mixed> $inventory Unit-test inventory summary.
	 * @param array<int,string> $module_queue Modules included in the structural scan.
	 * @return array<int,string> Sorted module names to execute in worker children.
	 */
	private static function unit_test_queue_from_inventory(array $inventory, array $module_queue): array {
		$modules=is_array($inventory['modules'] ?? null) ? array_keys($inventory['modules']) : [];
		$module_set=array_fill_keys(array_map('strval', $module_queue), true);
		$queue=[];
		foreach($modules as $module){
			$module=(string)$module;
			if($module==='' || in_array($module, ['dynamic', 'unscoped'], true)){
				continue;
			}
			if(isset($module_set[$module])){
				$queue[]=$module;
			}
		}
		sort($queue, SORT_STRING);
		return array_values(array_unique($queue));
	}

	/**
	 * Builds file-level worker jobs for every executable unit-test case.
	 *
	 * @param array<string,mixed> $inventory Unit-test inventory summary.
	 * @param array<int,string> $module_queue Modules included in the structural scan.
	 * @return array<int,array{module:string,path:string,kind:string,cases:int,case_index:int}> Unit-test worker jobs.
	 */
	private static function manifest_test_queue_from_inventory(array $inventory, array $module_queue): array {
		$known_modules=array_fill_keys(array_map('strval', $module_queue), true);
		$jobs=[];
		foreach((array)($inventory['files'] ?? []) as $file){
			if(!is_array($file)){
				continue;
			}
			$kind=(string)($file['kind'] ?? 'json');
			$module=(string)($file['module'] ?? '');
			if($kind!=='code' && $module!=='' && !in_array($module, ['dynamic', 'unscoped'], true) && !isset($known_modules[$module])){
				$module='unscoped';
			}
			$path=(string)($file['path'] ?? '');
			if($path===''){
				continue;
			}
			$cases=max(0, (int)($file['cases'] ?? 0));
			for($case_index=0; $case_index<$cases; $case_index++){
				$jobs[]=[
					'module'=>$module!=='' ? $module : 'unscoped',
					'path'=>$path,
					'kind'=>$kind==='code' ? 'code' : 'json',
					'cases'=>1,
					'case_index'=>$case_index,
				];
			}
		}
		usort($jobs, static fn(array $a, array $b): int=>[$a['kind'], $a['module'], $a['path'], $a['case_index']] <=> [$b['kind'], $b['module'], $b['path'], $b['case_index']]);
		return $jobs;
	}

	/**
	 * Builds the active label for a queued unit-test worker job.
	 *
	 * @param array<string,mixed> $job Queued unit-test job.
	 * @return string Stable label for progress and stalled-scan recovery.
	 */
	private static function unit_test_worker_job_label(array $job): string {
		$module=(string)($job['module'] ?? 'unit_tests');
		$path=(string)($job['path'] ?? '');
		$kind=(string)($job['kind'] ?? 'json');
		$case_index=(int)($job['case_index'] ?? 0);
		$file=$path!=='' ? basename($path) : 'unit-test';
		return $module.':'.$file.'#'.($case_index + 1).($kind==='code' ? ':code' : '');
	}

	/**
	 * Adds worker-phase coverage counts to a raw unit-test inventory.
	 *
	 * @param array<string,mixed> $inventory Unit-test inventory summary.
	 * @param array<int,string> $test_queue Module workers scheduled for execution.
	 * @param array<int,array{module:string,path:string,kind?:string,cases:int}> $manifest_queue File workers scheduled for execution.
	 * @return array<string,mixed> Inventory with worker and deferred case counts.
	 */
	private static function apply_worker_inventory_counts(array $inventory, array $test_queue, array $manifest_queue): array {
		$modules=is_array($inventory['modules'] ?? null) ? $inventory['modules'] : [];
		$module_worker_cases=0;
		foreach($test_queue as $module){
			$module_worker_cases+=(int)($modules[(string)$module] ?? 0);
		}
		$manifest_worker_cases=0;
		$code_worker_cases=0;
		foreach($manifest_queue as $job){
			if(($job['kind'] ?? 'json')==='code'){
				$code_worker_cases+=(int)($job['cases'] ?? 0);
			}
			else
			{
				$manifest_worker_cases+=(int)($job['cases'] ?? 0);
			}
		}
		$total=(int)($inventory['test_cases'] ?? 0);
		$worker_cases=$module_worker_cases + $manifest_worker_cases + $code_worker_cases;
		$inventory['module_worker_test_cases']=$module_worker_cases;
		$inventory['manifest_worker_test_cases']=$manifest_worker_cases;
		$inventory['code_worker_test_cases']=$code_worker_cases;
		$inventory['worker_test_cases']=$worker_cases;
		$inventory['deferred_test_cases']=max(0, $total - $worker_cases);
		return $inventory;
	}

	/**
	 * Counts JSON manifests, code-defined PHP tests, and declared cases for the selected scan scope.
	 *
	 * Paths are resolved from ROOTPATH entries so local applications, shared
	 * runtime code, and generated dynamic tests do not depend on a fixed checkout
	 * shape.
	 *
	 * @param string $scope Runtime, app, or all module scope label.
	 * @return array<string,mixed> Test inventory summary.
	 */
	private static function unit_test_inventory_for_scope(string $scope): array {
		$roots=[];
		$include_dynamic=self::include_dynamic_unit_tests();
		if(($scope==='runtime' || $scope==='all') && defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			$roots[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules';
			$testing_root=self::dataphyre_testing_unit_test_root();
			if($testing_root!==''){
				$roots[]=$testing_root;
			}
		}
		if(($scope==='app' || $scope==='all') && defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			$dataphyre_root=rtrim((string)ROOTPATH['dataphyre'], '/\\');
			$roots[]=$dataphyre_root.'/modules';
			$roots[]=$dataphyre_root.'/unit_tests';
		}
		$files=[];
		foreach(array_unique($roots) as $root){
			foreach(self::collect_unit_test_files($root, $include_dynamic) as $file){
				$files[$file]=$file;
			}
		}
		ksort($files, SORT_STRING);
		$inventory=[
			'manifests'=>0,
			'json_manifests'=>0,
			'json_test_cases'=>0,
			'code_files'=>0,
			'code_test_cases'=>0,
			'code_grouped_cases'=>0,
			'code_dependent_cases'=>0,
			'code_skipped_files'=>0,
			'code_discovery_errors'=>0,
			'test_cases'=>0,
			'malformed'=>0,
			'modules'=>[],
			'files'=>[],
			'roots'=>array_values(array_unique($roots)),
			'warnings'=>[],
			'dynamic_unit_tests_enabled'=>$include_dynamic,
		];
		$code_worker_available=self::code_unit_test_worker_available();
		$code_skip_warning_added=false;
		foreach($files as $file){
			$kind=self::unit_test_file_kind($file);
			$module=self::module_name_from_unit_test_path($file);
			if($kind==='code'){
				$inventory['code_files']++;
				if($code_worker_available!==true){
					$inventory['code_skipped_files']++;
					if($code_skip_warning_added!==true){
						$inventory['warnings'][]=[
							'type'=>'code_unit_test_worker',
							'level'=>'warning',
							'module'=>'unit_tests',
							'message'=>function_exists('proc_open')
								? 'Code-defined PHP unit tests were discovered but skipped because common/dataphyre/testing/code_worker.php is unavailable.'
								: 'Code-defined PHP unit tests were discovered but skipped because proc_open is unavailable in this PHP environment.',
						];
						$code_skip_warning_added=true;
					}
					$inventory['files'][]=[
						'path'=>$file,
						'module'=>$module,
						'kind'=>'code',
						'cases'=>0,
					];
					continue;
				}
				$result=self::run_code_unit_test_worker($module, $file, 0, 'list');
				$cases=is_array($result['cases'] ?? null) ? $result['cases'] : [];
				if(($result['passed'] ?? false)!==true || $cases===[]){
					$inventory['code_discovery_errors']++;
					$count=1;
				}
				else
				{
					$count=max(1, count($cases));
					foreach($cases as $case){
						if(is_array($case) && isset($case['groups']) && is_array($case['groups']) && $case['groups']!==[]){
							$inventory['code_grouped_cases']++;
						}
						if(is_array($case) && isset($case['dependencies']) && is_array($case['dependencies']) && $case['dependencies']!==[]){
							$inventory['code_dependent_cases']++;
						}
					}
				}
				$inventory['code_test_cases']+=$count;
			}
			else
			{
				$inventory['manifests']++;
				$inventory['json_manifests']++;
				$content=@file_get_contents($file);
				$decoded=is_string($content) ? json_decode($content, true) : null;
				if(!is_array($decoded)){
					$inventory['malformed']++;
					continue;
				}
				$count=self::unit_test_case_count($decoded);
				$inventory['json_test_cases']+=$count;
			}
			$inventory['test_cases']+=$count;
			$inventory['modules'][$module]=($inventory['modules'][$module] ?? 0) + $count;
			$inventory['files'][]=[
				'path'=>$file,
				'module'=>$module,
				'kind'=>$kind,
				'cases'=>$count,
			];
		}
		ksort($inventory['modules'], SORT_STRING);
		return $inventory;
	}

	/**
	 * Finds JSON manifests and code-defined PHP unit tests beneath a root folder.
	 *
	 * Dynamic metadata companions are excluded because they describe observations
	 * rather than executable test definitions.
	 *
	 * @param string $root Absolute folder to scan.
	 * @param bool $include_dynamic Whether generated dynamic diagnostics should be included.
	 * @return array<int,string> Sorted unit-test file paths.
	 */
	private static function collect_unit_test_files(string $root, bool $include_dynamic=false): array {
		if(!is_dir($root)){
			return [];
		}
		$files=[];
		try{
			$iterator=new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
			);
			foreach($iterator as $file){
				if(!$file instanceof \SplFileInfo || !$file->isFile()){
					continue;
				}
				$path=$file->getPathname();
				$normalized=str_replace('\\', '/', $path);
				if(!str_contains($normalized, '/unit_tests/')){
					continue;
				}
				if($include_dynamic!==true && self::is_dynamic_unit_test_path($path)){
					continue;
				}
				if(self::is_internal_unit_test_fixture($path)){
					continue;
				}
				$extension=strtolower($file->getExtension());
				if($extension==='json'){
					if(str_ends_with($file->getFilename(), '.meta.json')){
						continue;
					}
					$files[]=$path;
					continue;
				}
				if($extension==='php' && self::is_code_unit_test_file($path)){
					$files[]=$path;
				}
			}
		}catch(\Throwable){
			return [];
		}
		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Returns the inventory kind for a discovered unit-test file.
	 *
	 * @param string $path Candidate test file path.
	 * @return string Unit-test kind: json or code.
	 */
	private static function unit_test_file_kind(string $path): string {
		return self::is_code_unit_test_file($path) ? 'code' : 'json';
	}

	/**
	 * Returns whether a path is a committed code-defined unit test.
	 *
	 * @param string $path Candidate PHP unit-test path.
	 * @return bool True for Dataphyre\Test DSL files.
	 */
	private static function is_code_unit_test_file(string $path): bool {
		$normalized=str_replace('\\', '/', $path);
		return str_contains($normalized, '/unit_tests/')
			&& str_ends_with(basename($normalized), '.test.php');
	}

	/**
	 * Returns whether a unit-test path belongs to generated dynamic diagnostics.
	 *
	 * @param string $path Candidate unit-test path.
	 * @return bool True when the file is under unit_tests/dynamic.
	 */
	private static function is_dynamic_unit_test_path(string $path): bool {
		return str_contains(str_replace('\\', '/', $path), '/unit_tests/dynamic/');
	}

	/**
	 * Returns whether Dpanel should include generated dynamic unit-test diagnostics.
	 *
	 * @return bool True when explicitly enabled by environment.
	 */
	private static function include_dynamic_unit_tests(): bool {
		$value=strtolower(trim((string)(getenv('DATAPHYRE_DPANEL_INCLUDE_DYNAMIC_UNIT_TESTS') ?: '')));
		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * Excludes Dpanel's own runner fixtures from user-facing diagnostic scans.
	 *
	 * @param string $path Candidate unit-test manifest path.
	 * @return bool True when the file exists only to test the test runner itself.
	 */
	private static function is_internal_unit_test_fixture(string $path): bool {
		$normalized=str_replace('\\', '/', $path);
		$basename=basename($normalized);
		return str_contains($normalized, '/modules/dpanel/unit_tests/')
			&& (str_starts_with($basename, 'dpanel_mock_') || $basename==='unit_test.json');
	}

	/**
	 * Counts declared test cases in a decoded JSON manifest.
	 *
	 * Current manifests are lists of test cases. A single object with a callable
	 * target is counted as one so older generated manifests still appear in the
	 * inventory instead of being dropped.
	 *
	 * @param array<mixed> $decoded Decoded JSON manifest.
	 * @return int Number of declared test cases.
	 */
	private static function unit_test_case_count(array $decoded): int {
		if($decoded===[]){
			return 0;
		}
		if(array_is_list($decoded)){
			return count($decoded);
		}
		return isset($decoded['function']) || isset($decoded['class']) || isset($decoded['file']) ? 1 : 0;
	}

	/**
	 * Infers a module label from a unit-test manifest path.
	 *
	 * The label is used only for inventory grouping. Unknown folder shapes are
	 * grouped under "dynamic" or "unscoped" rather than assuming an application
	 * layout.
	 *
	 * @param string $file Absolute manifest path.
	 * @return string Module label for inventory display.
	 */
	private static function module_name_from_unit_test_path(string $file): string {
		$normalized=str_replace('\\', '/', $file);
		if(preg_match('#/modules/([^/]+)/unit_tests/#', $normalized, $matches)===1){
			return (string)$matches[1];
		}
		if(str_contains($normalized, '/common/dataphyre/testing/unit_tests/') || str_contains($normalized, '/dataphyre/testing/unit_tests/')){
			return 'testing';
		}
		if(preg_match('#/unit_tests/dynamic/dataphyre/([^/.]+)/#', $normalized, $matches)===1){
			return (string)$matches[1];
		}
		if(str_contains($normalized, '/unit_tests/dynamic/')){
			return 'dynamic';
		}
		if(str_contains($normalized, '/dataphyre/unit_tests/')){
			return 'app';
		}
		return 'unscoped';
	}

	/**
	 * Discovers module names for one scan scope and records scope-level warnings.
	 *
	 * Runtime scans exclude Dpanel itself to prevent recursive diagnostics. Missing
	 * or empty module folders become diagnostic rows instead of PHP warnings so the
	 * Flightdeck surface can explain partial environments cleanly.
	 *
	 * @param string $label Scope label used in diagnostics.
	 * @param string $folder Absolute module folder for the scope.
	 * @return array{queue:array<int,string>,trace:array<int,array<string,mixed>>} Module queue and warning/info trace rows.
	 */
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

	/**
	 * Finds module entrypoints beneath a module folder.
	 *
	 * A module is identified by its `*.main.php` filename. Results are de-duplicated
	 * by module name, sorted for deterministic scan order, and filtered by explicit
	 * exclusions such as Dpanel's self-scan guard.
	 *
	 * @param string $folder Absolute folder to scan recursively.
	 * @param array<int,string> $excluded_modules Module names to omit.
	 * @return array<int,string> Sorted module names.
	 */
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

	/**
	 * Sanitizes diagnostic entries before storing them in the session.
	 *
	 * Session storage must not retain live Throwable or object instances because
	 * later requests only need serializable evidence. Arrays are traversed
	 * recursively so nested exception payloads remain inspectable in diagnostics
	 * and Flightdeck rows.
	 *
	 * @param array<int,mixed> $entries Raw Dpanel trace entries.
	 * @return array<int,mixed> Serializable diagnostic entries.
	 */
	private static function sanitize_trace_entries(array $entries): array {
		$sanitized=[];
		$passed_unit_tests=0;
		$passed_unit_test_time=0.0;
		$passed_unit_test_module=null;
		foreach($entries as $entry){
			if(self::is_collapsible_unit_test_pass($entry)){
				$passed_unit_tests++;
				$passed_unit_test_time+=(float)($entry['execution_time'] ?? 0);
				if(is_array($entry) && isset($entry['module'])){
					$passed_unit_test_module=(string)$entry['module'];
				}
				continue;
			}
			$sanitized[]=self::sanitize_value($entry);
		}
		if($passed_unit_tests>0){
			$summary=[
				'type'=>'unit_test',
				'level'=>'success',
				'passed'=>true,
				'unit_test_pass_count'=>$passed_unit_tests,
				'execution_time'=>$passed_unit_test_time,
				'message'=>$passed_unit_tests.' unit test'.($passed_unit_tests===1 ? '' : 's').' passed in this batch.',
			];
			if($passed_unit_test_module!==null){
				$summary['module']=$passed_unit_test_module;
			}
			$sanitized[]=$summary;
		}
		return $sanitized;
	}

	/**
	 * Detects successful per-test rows that can be summarized safely.
	 *
	 * Failed tests, warnings, dependency errors, duplicate summaries, and runtime
	 * diagnostics remain as individual rows. Only plain successful unit-test case
	 * rows are collapsed before session storage and table rendering.
	 *
	 * @param mixed $entry Raw Dpanel trace entry.
	 * @return bool True when the row can be represented by a batch pass summary.
	 */
	private static function is_collapsible_unit_test_pass(mixed $entry): bool {
		if(!is_array($entry)){
			return false;
		}
		if(!in_array(($entry['type'] ?? null), ['unit_test', 'code_unit_test'], true) || ($entry['passed'] ?? null)!==true){
			return false;
		}
		$level=strtolower((string)($entry['level'] ?? 'info'));
		if(in_array($level, ['warning', 'error', 'fatal'], true)){
			return false;
		}
		return isset($entry['test_name'], $entry['execution_time']);
	}

	/**
	 * Converts non-serializable diagnostic values into stable payloads.
	 *
	 * Throwables keep class, message, file, line, and stack trace fields. Other
	 * objects are reduced to class identity so arbitrary runtime instances are not
	 * serialized into session state.
	 *
	 * @param mixed $value Raw diagnostic value.
	 * @return mixed array-safe diagnostic value with throwables and objects reduced to stable metadata.
	 */
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

	/**
	 * Extracts a throwable payload from a diagnostic entry.
	 *
	 * The surface supports both live Throwable instances from the current request
	 * and sanitized throwable arrays recovered from session state. Null means the
	 * row should be interpreted by the generic Dpanel entry rules.
	 *
	 * @param array<string,mixed> $entry Raw or sanitized Dpanel trace entry.
	 * @return ?array{class?:string,message?:string,file?:string,line?:int|string,trace?:string,__type?:string} Throwable payload for row normalization.
	 */
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

	/**
	 * Renders the current scan progress banner.
	 *
	 * The banner reports cursor progress, batch count, and the active, skipped, or
	 * last completed module. Prepared scans get first-batch language while running
	 * scans explain whether autorun or manual continuation will advance the queue.
	 *
	 * @param ?array<string,mixed> $scan Current scan state, if one exists.
	 * @return string Progress banner markup, or an empty string when there is no queue.
	 */
	private static function scan_status(?array $scan): string {
		if(!is_array($scan) || !isset($scan['queue'])){
			return '';
		}
		$processed=min((int)($scan['cursor'] ?? 0), count((array)$scan['queue']));
		$total=count((array)$scan['queue']);
		$test_processed=min((int)($scan['test_cursor'] ?? 0), count((array)($scan['test_queue'] ?? [])));
		$test_total=count((array)($scan['test_queue'] ?? []));
		$manifest_processed=min((int)($scan['manifest_cursor'] ?? 0), count((array)($scan['manifest_queue'] ?? [])));
		$manifest_total=count((array)($scan['manifest_queue'] ?? []));
		if($total===0 && $test_total===0 && $manifest_total===0){
			return '';
		}
		$prepared=((int)($scan['batches'] ?? 0)===0 && ($scan['done'] ?? false)!==true);
		$class=($scan['done'] ?? false)===true ? 'fd-dpanel-status' : 'fd-dpanel-status fd-scan-status';
		$html='<div class="'.$class.'"><b>';
		if(($scan['done'] ?? false)===true){
			$html.='Complete';
		}
		elseif($prepared){
			$html.='Prepared';
		}
		else
		{
			$html.='Running';
		}
		$html.='</b><span>'.self::e(self::scan_phase_message($scan)).'</span>';
		$active_module=(string)($scan['active_module'] ?? '');
		$active_phase=(string)($scan['active_phase'] ?? '');
		$last_module=(string)($scan['last_module'] ?? '');
		$last_failed_module=(string)($scan['last_failed_module'] ?? '');
		$last_test_module=(string)($scan['last_test_module'] ?? '');
		$last_failed_test_module=(string)($scan['last_failed_test_module'] ?? '');
		$tail='';
		if($active_module!==''){
			$active_label=$active_phase==='unit_test_manifest' ? 'unit-test worker' : ($active_phase==='unit_test' ? 'test worker' : 'module');
			$tail='Active '.$active_label.': '.$active_module.'.';
		}
		elseif($last_failed_test_module!==''){
			$tail='Last failed test worker: '.$last_failed_test_module.'.';
		}
		elseif($last_failed_module!==''){
			$tail='Last skipped module: '.$last_failed_module.'.';
		}
		elseif($last_test_module!==''){
			$tail='Last completed test worker: '.$last_test_module.'.';
		}
		elseif($last_module!==''){
			$tail='Last completed module: '.$last_module.'.';
		}
		if($prepared){
			$tail.=($scan['autorun'] ?? true)===true
				? ' The browser will begin the first batch automatically.'
				: ' Begin the first batch when you are ready.';
		}
		elseif(($scan['done'] ?? false)!==true){
			$tail.=$active_module!==''
				? ' Waiting for the active batch to finish or reach the recovery window.'
				: (($scan['autorun'] ?? true)===true
				? ' The browser will continue the next batch automatically.'
				: ' Continue to process the remaining modules without risking a request timeout.');
		}
		if($tail!==''){
			$html.='<small>'.self::e(trim($tail)).'</small>';
		}
		return $html.'</div>';
	}

	/**
	 * Updates the autorun preference for a persisted scan.
	 *
	 * Pause and resume forms use this to control browser-side continuation without
	 * discarding queue progress or diagnostic evidence already captured in session
	 * state.
	 *
	 * @param string $token Persisted scan token from the POST form.
	 * @param bool $autorun Whether the browser should continue submitting batches automatically.
	 * @return ?array<string,mixed> Updated scan state, or null when the token is unavailable.
	 */
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

	/**
	 * Retrieves one persisted scan by token.
	 *
	 * @param string $token Persisted scan token from the POST form.
	 * @return ?array<string,mixed> Scan state from the session, or null for empty/missing tokens.
	 */
	private static function load_scan(string $token): ?array {
		if($token===''){
			return null;
		}
		return self::scan_store()[$token] ?? null;
	}

	/**
	 * Retrieves the most recent scan remembered by the session.
	 *
	 * @return ?array<string,mixed> Last scan state, or null when the session has none.
	 */
	private static function last_scan(): ?array {
		$last=(string)($_SESSION['flightdeck_dpanel_last_scan'] ?? '');
		return $last!=='' ? (self::scan_store()[$last] ?? null) : null;
	}

	/**
	 * Persists scan state and marks it as the most recent Dpanel scan.
	 *
	 * @param array<string,mixed> $scan Serializable scan state containing a token.
	 * @return void
	 */
	private static function store_scan(array $scan): void {
		$store=self::scan_store();
		$store[(string)$scan['token']]=$scan;
		$_SESSION['flightdeck_dpanel_scan']=$store;
		$_SESSION['flightdeck_dpanel_last_scan']=(string)$scan['token'];
	}

	/**
	 * Opens session storage and returns the Dpanel scan map.
	 *
	 * The method starts a session only when headers are still available. Invalid or
	 * absent scan stores are treated as empty arrays so malformed session data does
	 * not break the diagnostics surface.
	 *
	 * @return array<string,array<string,mixed>> Persisted scan states keyed by token.
	 */
	private static function scan_store(): array {
		if(session_status()!==PHP_SESSION_ACTIVE && !headers_sent()){
			@session_start();
		}
		$store=$_SESSION['flightdeck_dpanel_scan'] ?? [];
		return is_array($store) ? $store : [];
	}

	/**
	 * Returns the maximum number of modules executed in one browser request.
	 *
	 * @return int Batch module limit.
	 */
	private static function scan_batch_limit(): int {
		return 1;
	}

	/**
	 * Returns the soft time budget for one diagnostic batch.
	 *
	 * @return float Batch duration in seconds.
	 */
	private static function scan_batch_seconds(): float {
		return 1.25;
	}

	/**
	 * Returns the maximum number of unit-test file workers launched in one AJAX batch.
	 *
	 * @return int Unit-test file worker batch limit.
	 */
	private static function manifest_worker_batch_limit(): int {
		return 6;
	}

	/**
	 * Escapes text through the shared Flightdeck view helper.
	 *
	 * @param string $value Raw text destined for HTML output.
	 * @return string HTML-escaped text.
	 */
	private static function e(string $value): string {
		return dataphyre_flightdeck_view::e($value);
	}
}

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
	dataphyre_flightdeck_dpanel_surface::dispatch();
}
