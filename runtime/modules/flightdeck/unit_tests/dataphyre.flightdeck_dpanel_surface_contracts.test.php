<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Flightdeck Dpanel surface contracts')
	->framework(['async','dpanel'], ['functions'=>['tracelog']])
	->tag('flightdeck','dpanel','surface','diagnostics','coverage')
	->group('framework-coverage')
	->contract('flightdeck.dpanel.resumable-diagnostics',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck','module:dpanel','module:testing')
	->through('diagnostic projection','scan state','test inventory','worker boundaries')
	->isolation('process');

if(!defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')){
	define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST',true);
}
require_once dirname(__DIR__).'/kernel/auth.php';
require_once dirname(__DIR__).'/kernel/surfaces/dpanel.php';

final class DpFlightdeckDpanelFixture {
	/** @return array<string,mixed> */
	public static function scan(array $changes=[]): array {
		return array_replace([
			'token'=>'scan-token',
			'scope'=>'runtime',
			'queue'=>['alpha','beta'],
			'cursor'=>1,
			'test_queue'=>[],
			'test_cursor'=>0,
			'test_done'=>true,
			'manifest_queue'=>[
				['module'=>'alpha','path'=>'/tests/alpha.test.php','kind'=>'code','cases'=>1,'case_index'=>0,'test_name'=>'alpha works','suite'=>'Alpha suite'],
			],
			'manifest_cursor'=>0,
			'manifest_done'=>false,
			'trace'=>[],
			'done'=>false,
			'batches'=>1,
			'autorun'=>true,
			'active_module'=>null,
			'active_phase'=>null,
			'active_started_at'=>null,
			'created_at'=>time()-10,
			'updated_at'=>time()-5,
			'test_inventory'=>self::inventory(),
		],$changes);
	}

	/** @return array<string,mixed> */
	public static function inventory(): array {
		$modules=[];
		for($index=1;$index<=14;$index++){
			$modules['module-'.$index]=$index;
		}
		return [
			'manifests'=>2,
			'json_manifests'=>2,
			'json_test_cases'=>3,
			'code_files'=>2,
			'code_test_cases'=>4,
			'code_grouped_cases'=>2,
			'code_dependent_cases'=>1,
			'test_cases'=>7,
			'manifest_worker_test_cases'=>3,
			'code_worker_test_cases'=>4,
			'worker_test_cases'=>7,
			'deferred_test_cases'=>0,
			'code_skipped_files'=>1,
			'code_discovery_errors'=>1,
			'malformed'=>1,
			'modules'=>$modules,
			'code_suites'=>['Readable suite'=>2],
			'code_case_catalog'=>[
				'invalid',
				['suite'=>'Readable suite','name'=>'describes its behavior','module'=>'alpha','file'=>'/tests/alpha.test.php','tags'=>['fast'],'groups'=>['framework-coverage']],
				['suite'=>'','name'=>'legacy behavior','module'=>'beta','file'=>'C:\\tests\\beta.test.php','tags'=>'invalid','groups'=>[]],
			],
		];
	}

	/** @return list<array<string,mixed>> */
	public static function diagnosticTrace(): array {
		return [
			['type'=>'unit_test','level'=>'success','passed'=>true,'unit_test_pass_count'=>3,'execution_time'=>0.01,'message'=>'three passed'],
			['type'=>'code_unit_test','level'=>'info','passed'=>false,'test_name'=>'fails clearly','execution_time'=>0.02,'module'=>'alpha','message'=>'assertion failed'],
			['type'=>'unit_test_worker','level'=>'error','passed'=>false,'manifest'=>'alpha.json','case_index'=>0,'stdout'=>'out','stderr'=>'err','message'=>'worker failed'],
			['type'=>'php_exception','level'=>'error','passed'=>false,'exception'=>new RuntimeException('runtime exploded')],
			['type'=>'performance_warning','level'=>'warning','passed'=>false,'warning_string'=>'slow case'],
			['type'=>'diagnostic','level'=>'warning','passed'=>true,'message'=>'review this'],
			['type'=>'trace','level'=>'info','tracelog'=>[
				['type'=>'info','file'=>'/src/a.php','line'=>1,'class'=>'A','function'=>'run','message'=>'started'],
				['type'=>'fatal','file'=>'/src/b.php','line'=>2,'message'=>'stopped'],
				'invalid',
			]],
			['type'=>'raw','level'=>'info','payload'=>new stdClass()],
		];
	}
}

final class DpFlightdeckDpanelConfigProbe {
	/** @param array<string,mixed> $values */
	public function __construct(public array $values=[]) {}

	/** @return array<string,mixed> */
	public function &raw(): array {
		return $this->values;
	}
}

final class DpFlightdeckDpanelBrokenDirectoryStream {
	public mixed $context=null;
	public function url_stat(string $path,int $flags): array {
		return ['mode'=>0040777,2=>0040777];
	}
	public function dir_opendir(string $path,int $options): bool {
		throw new RuntimeException('Synthetic directory enumeration failure.');
	}
}

test('Dpanel presents assets summaries controls inventory and AJAX fragments as readable contracts',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$asset=dataphyre_flightdeck_dpanel_surface::asset_content('../dpanel-surface.css');
	$t->hasPathValues(['content_type'=>'text/css; charset=UTF-8'],$asset);
	$t->contains('.fd-dpanel-overview',$asset['body']);
	$t->same(null,dataphyre_flightdeck_dpanel_surface::asset_content('missing.css'));
	$t->same('missing',dataphyre_flightdeck_dpanel_surface::asset_version('missing.css'));
	$t->contains('/dataphyre/flightdeck/assets/dpanel-surface.css?v=',dataphyre_flightdeck_dpanel_surface::asset_url('../dpanel-surface.css'));
	$t->same('',$surface->invoke('asset_name','bad name.css'));
	$t->contains('data-dpanel-test-filter',$surface->invoke('client_script'));

	$scan=DpFlightdeckDpanelFixture::scan();
	$trace=DpFlightdeckDpanelFixture::diagnosticTrace();
	$summary=$surface->invoke('summary_cards',$trace,'surface failed',$scan);
	$t->containsAll(['Attention needed','Module scan','Tests','Warnings','Failed','Worker issues'],$summary);
	$t->contains('Ready to scan',$surface->invoke('summary_cards',[],null,null));
	$t->contains('Scan complete',$surface->invoke('summary_cards',[],null,DpFlightdeckDpanelFixture::scan([
		'done'=>true,'cursor'=>2,'manifest_cursor'=>1,'manifest_done'=>true,
	])));
	$t->contains('Scan running',$surface->invoke('summary_cards',[],null,$scan));
	$t->containsAll(['Unit tests','No failures so far'],$surface->invoke('summary_cards',[],null,DpFlightdeckDpanelFixture::scan(['cursor'=>2])));
	$t->contains('Warnings are listed below',$surface->invoke('summary_cards',[
		['type'=>'diagnostic','level'=>'warning','passed'=>true,'message'=>'review'],
	],null,null));

	$t->same([
		'none'=>'',
		'complete'=>'Complete. Modules 2 / 2; unit-test workers 1 / 1.',
		'modules'=>'Scanning modules 1 / 2. Unit tests run after module diagnostics.',
		'tests'=>'Running unit-test workers 0 / 1.',
		'preparing'=>'Preparing diagnostic batches.',
	],$surface->invokeCases([
		'none'=>['method'=>'scan_phase_message','arguments'=>[null]],
		'complete'=>['method'=>'scan_phase_message','arguments'=>[DpFlightdeckDpanelFixture::scan(['done'=>true,'cursor'=>2,'manifest_cursor'=>1])]],
		'modules'=>['method'=>'scan_phase_message','arguments'=>[$scan]],
		'tests'=>['method'=>'scan_phase_message','arguments'=>[DpFlightdeckDpanelFixture::scan(['cursor'=>2])]],
		'preparing'=>['method'=>'scan_phase_message','arguments'=>[DpFlightdeckDpanelFixture::scan(['queue'=>[],'manifest_queue'=>[]])]],
	]));

	$inventory=$surface->invoke('test_inventory_card',$scan);
	$t->containsAll([
		'Unit tests: 7 cases in 4 files','Self-described suites: 1','Browse all 2 code-defined test cases',
		'No suite declared','Showing the largest 12 of 14 modules','data-dpanel-test-filter',
	],$inventory);
	$t->same('',$surface->invoke('test_inventory_card',null));

	$actions=$surface->invoke('actions',$scan);
	$t->containsAll(['Continue Scan','Pause Auto Scan','Scan Runtime','csrf'],$actions);
	$t->contains('Begin Scan',$surface->invoke('actions',DpFlightdeckDpanelFixture::scan(['batches'=>0,'cursor'=>0])));
	$t->contains('Run Test Worker',$surface->invoke('actions',DpFlightdeckDpanelFixture::scan([
		'cursor'=>2,'test_queue'=>['alpha'],'test_done'=>false,'manifest_done'=>false,
	])));
	$t->contains('Run Unit-Test Worker',$surface->invoke('actions',DpFlightdeckDpanelFixture::scan([
		'cursor'=>2,'test_done'=>true,'manifest_done'=>false,
	])));
	$t->contains('Resume Auto Scan',$surface->invoke('actions',DpFlightdeckDpanelFixture::scan(['autorun'=>false])));

	$parts=$surface->invoke('content_parts',$scan,null,$trace,false);
	$t->same(['summary','status','inventory','diagnostics'],array_keys($parts));
	$t->containsAll(['data-dpanel-part="summary"','data-dpanel-part="diagnostics"'],$surface->invoke('content',$scan,null,$trace,false));
	$t->contains('Auto-scan is running',$surface->invoke('diagnostics_card',$scan,null,$trace,true));
	$t->contains('surface failed',$surface->invoke('diagnostics_card',$scan,'surface failed',$trace,false));

	$t->globalMap('_POST')->replace(['fd_dpanel_ajax'=>'1']);
	$t->isTrue($surface->invoke('wants_ajax_response'));
	$t->globalMap('_POST')->replace([]);
	$t->globalMap('_SERVER')->replace(['HTTP_X_REQUESTED_WITH'=>'FETCH']);
	$t->isTrue($surface->invoke('wants_ajax_response'));
	$t->globalMap('_SERVER')->replace([]);
	$t->isFalse($surface->invoke('wants_ajax_response'));
	$ajax=$t->captureOutput(static fn()=>$surface->invoke('emit_ajax_response',$scan,null,$trace,true))->output();
	$t->hasPathValues(['autorun'=>true,'done'=>false,'error'=>null],json_decode($ajax,true,512,JSON_THROW_ON_ERROR));

	$t->isFalse($surface->invoke('scan_can_continue_automatically',null));
	$t->isTrue($surface->invoke('scan_can_continue_automatically',$scan));
	$t->isFalse($surface->invoke('scan_can_continue_automatically',DpFlightdeckDpanelFixture::scan(['active_module'=>'alpha'])));
});

test('Dpanel normalizes heterogeneous diagnostics without hiding failure evidence',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$trace=DpFlightdeckDpanelFixture::diagnosticTrace();
	$t->contains('No diagnostic scan has been run yet',$surface->invoke('diagnostics_table',[]));
	$table=$surface->invoke('diagnostics_table',$trace);
	$t->containsAll(['runtime exploded','assertion failed','Worker Output','Tracelog Entries','Raw Diagnostic Entry'],$table);

	$normalized=$surface->invokeCases([
		'live-exception'=>['method'=>'normalize_entry','arguments'=>[['type'=>'php_exception','exception'=>new LogicException('live failure')],0]],
		'stored-exception'=>['method'=>'normalize_entry','arguments'=>[['exception'=>['__type'=>'throwable','message'=>'stored failure','file'=>'/tmp/stored.php','line'=>7,'trace'=>'trace']],1]],
		'context'=>['method'=>'normalize_entry','arguments'=>[['type'=>'unit_test','test_name'=>'named','input'=>['id'=>1],'passed'=>true],2]],
		'worker'=>['method'=>'normalize_entry','arguments'=>[['type'=>'worker','stdout'=>'out'],3]],
		'empty'=>['method'=>'normalize_entry','arguments'=>[['type'=>'empty'],4]],
		'failed'=>['method'=>'normalize_entry','arguments'=>[['type'=>'failed','passed'=>false,'message'=>'failed'],5]],
		'fatal'=>['method'=>'normalize_entry','arguments'=>[['type'=>'fatal','level'=>'fatal','message'=>'fatal'],6]],
		'warning'=>['method'=>'normalize_entry','arguments'=>[['type'=>'warning','level'=>'warning','message'=>'warning'],7]],
	]);
	$t->hasPathValues([
		'live-exception.status'=>'Failed',
		'stored-exception.message'=>'stored failure',
		'context.status'=>'Passed',
		'failed.status'=>'Failed',
		'fatal.status'=>'Fatal',
		'warning.status'=>'Warning',
	],$normalized);
	$t->contains('Diagnostic Context',$normalized['context']['details']);
	$t->contains('Worker Output',$normalized['worker']['details']);
	$t->contains('Raw Diagnostic Entry',$normalized['empty']['details']);

	$t->same('',$surface->invoke('details','Empty',''));
	$t->contains('&lt;unsafe&gt;',$surface->invoke('details','Details','<unsafe>'));
	$t->same('',$surface->invoke('details_html','Empty',''));
	$t->contains('<strong>safe</strong>',$surface->invoke('details_html','HTML','<strong>safe</strong>'));
	$t->same(['level'=>'info','parts'=>['0 info']],$surface->invoke('tracelog_summary',[]));
	$t->same(['level'=>'fatal','parts'=>['1 info','1 warning','1 fatal']],$surface->invoke('tracelog_summary',[
		'invalid',['type'=>'warning'],['type'=>'fatal'],
	]));
	$t->same(['level'=>'info','parts'=>['1 info']],$surface->invoke('tracelog_summary',[['type'=>'unknown']]));
	$t->same('',$surface->invoke('tracelog_table',['invalid']));
	$t->containsAll(['Unknown','Message'],$surface->invoke('tracelog_table',[['message'=>'Message']]));

	$large=array_fill(0,305,['level'=>'info','message'=>'ordinary']);
	$large[0]=['level'=>'error','message'=>'important'];
	$visible=$surface->invoke('visible_diagnostic_entries',$large);
	$t->count(300,$visible);
	$t->same('important',$visible[0]['message']);
	$t->count(2,$surface->invoke('visible_diagnostic_entries',[['message'=>'a'],['message'=>'b']]));
	$t->contains('Showing 300 of 305',$surface->invoke('diagnostics_table',$large));
});

test('Dpanel scan state is resumable bounded serializable and explicit about recovery',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$t->globalMap('_SESSION')->replace([]);
	$scan=DpFlightdeckDpanelFixture::scan();
	$surface->invoke('store_scan',$scan);
	$t->same(null,$surface->invoke('load_scan',''));
	$t->same(null,$surface->invoke('load_scan','missing'));
	$t->same('scan-token',$surface->invoke('load_scan','scan-token')['token']);
	$t->same('scan-token',$surface->invoke('last_scan')['token']);
	$t->same(null,$surface->invoke('set_scan_autorun','missing',false));
	$t->isFalse($surface->invoke('set_scan_autorun','scan-token',false)['autorun']);
	$t->contains('Auto-scan paused',$surface->invoke('pause_scan_after_error','scan-token','control failed')['trace'][0]['message']);
	$t->same('scan-token',$surface->invoke('pause_scan_after_error','missing','ignored')['token']);
	$t->globalMap('_SESSION')->replace([]);
	$t->same(null,$surface->invoke('pause_scan_after_error','missing','ignored'));
	$surface->invoke('store_scan',$scan);

	$t->same([
		'empty'=>-1,
		'unlimited'=>-1,
		'bytes'=>512,
		'kilobytes'=>1024,
		'megabytes'=>1048576,
		'gigabytes'=>1073741824,
	],$surface->invokeCases([
		'empty'=>['method'=>'memory_to_bytes','arguments'=>['']],
		'unlimited'=>['method'=>'memory_to_bytes','arguments'=>['-1']],
		'bytes'=>['method'=>'memory_to_bytes','arguments'=>['512']],
		'kilobytes'=>['method'=>'memory_to_bytes','arguments'=>['1K']],
		'megabytes'=>['method'=>'memory_to_bytes','arguments'=>['1M']],
		'gigabytes'=>['method'=>'memory_to_bytes','arguments'=>['1G']],
	]));
	$t->same([],$surface->invoke('apply_diagnostic_runtime_overrides','256M'));
	$surface->invoke('restore_diagnostic_runtime_overrides',[]);

	$error=new RuntimeException('serializable failure');
	$sanitized=$surface->invoke('sanitize_value',['error'=>$error,'object'=>new stdClass(),'scalar'=>'ready']);
	$t->hasPathValues([
		'error.__type'=>'throwable',
		'error.class'=>RuntimeException::class,
		'object.__type'=>'object',
		'object.class'=>stdClass::class,
		'scalar'=>'ready',
	],$sanitized);
	$t->same(null,$surface->invoke('exception_payload',[]));
	$t->same('serializable failure',$surface->invoke('exception_payload',['exception'=>$error])['message']);
	$t->same('stored',$surface->invoke('exception_payload',['exception'=>['__type'=>'throwable','message'=>'stored']])['message']);

	$collapsed=$surface->invoke('sanitize_trace_entries',[
		['type'=>'unit_test','level'=>'success','passed'=>true,'test_name'=>'one','execution_time'=>0.1,'module'=>'alpha'],
		['type'=>'code_unit_test','passed'=>true,'test_name'=>'two','execution_time'=>0.2,'module'=>'beta'],
		['type'=>'unit_test','level'=>'warning','passed'=>true,'test_name'=>'kept','execution_time'=>0.3],
		['type'=>'unit_test','passed'=>false,'test_name'=>'failed','execution_time'=>0.4],
		'invalid',
	]);
	$t->count(4,$collapsed);
	$t->hasPathValues(['3.unit_test_pass_count'=>2,'3.module'=>'beta'],$collapsed);
	$t->isFalse($surface->invoke('is_collapsible_unit_test_pass','invalid'));
	$t->isFalse($surface->invoke('is_collapsible_unit_test_pass',['type'=>'unit_test','passed'=>true]));

	foreach([
		['active_phase'=>'module','active_module'=>'alpha','active_started_at'=>time()-30,'cursor'=>0],
		['active_phase'=>'unit_test','active_module'=>'alpha','active_started_at'=>time()-30,'test_queue'=>['alpha'],'test_cursor'=>0,'test_done'=>false],
		['active_phase'=>'unit_test_manifest','active_module'=>'alpha:Alpha suite / alpha works #1','active_started_at'=>time()-30],
	] as $recovery){
		$recovered=$surface->invoke('recover_stalled_scan',DpFlightdeckDpanelFixture::scan($recovery));
		$t->same(null,$recovered['active_module']);
		$t->contains('previous batch stalled',$recovered['trace'][0]['message']);
	}
	$t->same(null,$surface->invoke('recover_stalled_scan',null));
	$done=DpFlightdeckDpanelFixture::scan(['done'=>true]);
	$t->same($done,$surface->invoke('recover_stalled_scan',$done));
	$recent=DpFlightdeckDpanelFixture::scan(['active_module'=>'alpha','active_started_at'=>time()]);
	$t->same($recent,$surface->invoke('recover_stalled_scan',$recent));

	$t->same(1,$surface->invoke('scan_batch_limit'));
	$t->same(1.25,$surface->invoke('scan_batch_seconds'));
	$t->same(6,$surface->invoke('manifest_worker_batch_limit'));
	$t->same('256M',$surface->invoke('unit_test_worker_memory_limit'));
	$t->same(8,$surface->invoke('unit_test_worker_timeout_seconds'));

	$t->same('',$surface->invoke('scan_status',null));
	$t->same('',$surface->invoke('scan_status',['queue'=>[]]));
	$statusCases=[
		'complete'=>DpFlightdeckDpanelFixture::scan(['done'=>true,'cursor'=>2,'manifest_cursor'=>1,'last_module'=>'beta']),
		'prepared-auto'=>DpFlightdeckDpanelFixture::scan(['batches'=>0,'cursor'=>0]),
		'prepared-manual'=>DpFlightdeckDpanelFixture::scan(['batches'=>0,'cursor'=>0,'autorun'=>false]),
		'active-module'=>DpFlightdeckDpanelFixture::scan(['active_module'=>'alpha','active_phase'=>'module']),
		'active-test'=>DpFlightdeckDpanelFixture::scan(['active_module'=>'alpha','active_phase'=>'unit_test']),
		'active-manifest'=>DpFlightdeckDpanelFixture::scan(['active_module'=>'Alpha / works','active_phase'=>'unit_test_manifest']),
		'failed-test'=>DpFlightdeckDpanelFixture::scan(['last_failed_test_module'=>'alpha:test']),
		'failed-module'=>DpFlightdeckDpanelFixture::scan(['last_failed_module'=>'alpha']),
		'last-test'=>DpFlightdeckDpanelFixture::scan(['last_test_module'=>'alpha:test']),
		'last-module'=>DpFlightdeckDpanelFixture::scan(['last_module'=>'alpha']),
		'running-auto'=>DpFlightdeckDpanelFixture::scan(),
		'running-manual'=>DpFlightdeckDpanelFixture::scan(['autorun'=>false]),
	];
	$statuses=[];
	foreach($statusCases as $name=>$statusScan){
		$statuses[$name]=$surface->invoke('scan_status',$statusScan);
	}
	$t->containsAll(['Complete','Last completed module: beta.'],$statuses['complete']);
	$t->containsAll(['Prepared','begin the first batch automatically'],$statuses['prepared-auto']);
	$t->containsAll(['Prepared','Begin the first batch when you are ready'],$statuses['prepared-manual']);
	$t->containsAll(['Active module: alpha','Waiting for the active batch'],$statuses['active-module']);
	$t->contains('Active test worker: alpha',$statuses['active-test']);
	$t->contains('Active unit-test worker: Alpha / works',$statuses['active-manifest']);
	$t->contains('Last failed test worker: alpha:test',$statuses['failed-test']);
	$t->contains('Last skipped module: alpha',$statuses['failed-module']);
	$t->contains('Last completed test worker: alpha:test',$statuses['last-test']);
	$t->contains('Last completed module: alpha',$statuses['last-module']);
	$t->contains('continue the next batch automatically',$statuses['running-auto']);
	$t->contains('Continue to process the remaining modules',$statuses['running-manual']);
});

test('Dpanel applies and restores diagnostic CFG memory without leaking synthetic branches',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$probe=new DpFlightdeckDpanelConfigProbe();
	if(!defined('CFG')){
		define('CFG',$probe);
	}
	$t->same($probe,CFG);

	$created=$surface->invoke('apply_diagnostic_runtime_overrides','256M');
	$t->hasPathValues(['dataphyre_exists'=>false,'common_exists'=>false],$created);
	$t->same('256M',$probe->values['dataphyre']['max_execution_memory']);
	$surface->invoke('restore_diagnostic_runtime_overrides',$created);
	$t->isFalse(array_key_exists('dataphyre',$probe->values));

	$probe->values=[
		'dataphyre'=>['max_execution_memory'=>'96M'],
		'common'=>['dataphyre'=>['max_execution_memory'=>'128M']],
	];
	$preserved=$surface->invoke('apply_diagnostic_runtime_overrides','256M');
	$t->hasPathValues([
		'dataphyre_exists'=>true,
		'common_exists'=>true,
		'previous_dataphyre'=>'96M',
		'previous_common'=>'128M',
	],$preserved);
	$t->hasPathValues([
		'dataphyre.max_execution_memory'=>'256M',
		'common.dataphyre.max_execution_memory'=>'256M',
	],$probe->values);
	$surface->invoke('restore_diagnostic_runtime_overrides',$preserved);
	$t->hasPathValues([
		'dataphyre.max_execution_memory'=>'96M',
		'common.dataphyre.max_execution_memory'=>'128M',
	],$probe->values);

	$probe->values=['dataphyre'=>'malformed','common'=>['dataphyre'=>'malformed']];
	$malformed=$surface->invoke('apply_diagnostic_runtime_overrides','192M');
	$t->same('192M',$probe->values['dataphyre']['max_execution_memory']);
	$t->same('malformed',$probe->values['common']['dataphyre']);
	$surface->invoke('restore_diagnostic_runtime_overrides',$malformed);
	$t->isFalse(array_key_exists('dataphyre',$probe->values));
	$t->same('malformed',$probe->values['common']['dataphyre']);
});

test('Dpanel discovers module-owned tests and builds deterministic worker jobs',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$workspace=$t->workspace('flightdeck-dpanel-inventory');
	$workspace->file('modules/alpha/alpha.main.php','<?php');
	$workspace->file('modules/alpha/unit_tests/alpha.json','[{"name":"one"},{"name":"two"}]');
	$workspace->file('modules/alpha/unit_tests/alpha.test.php','<?php // code test');
	$workspace->file('modules/alpha/unit_tests/alpha.meta.json','{}');
	$workspace->file('modules/alpha/unit_tests/dynamic/generated.json','[{"name":"dynamic"}]');
	$workspace->file('modules/dpanel/dpanel.main.php','<?php');
	$workspace->file('modules/dpanel/unit_tests/dpanel_mock_hidden.json','[]');
	$workspace->file('modules/beta/kernel/beta.main.php','<?php');
	$workspace->file('outside.json','[]');

	$withoutDynamic=$surface->invoke('collect_unit_test_files',$workspace->root(),false);
	$t->count(2,$withoutDynamic);
	$t->containsAll(['alpha.json','alpha.test.php'],implode("\n",$withoutDynamic));
	$withDynamic=$surface->invoke('collect_unit_test_files',$workspace->root(),true);
	$t->count(3,$withDynamic);
	$t->same([],$surface->invoke('collect_unit_test_files',$workspace->path('missing'),false));

	$t->same([
		'code'=>'code',
		'json'=>'json',
		'empty'=>0,
		'list'=>2,
		'legacy'=>1,
		'object'=>0,
	],[
		'code'=>$surface->invoke('unit_test_file_kind',$workspace->path('modules/alpha/unit_tests/alpha.test.php')),
		'json'=>$surface->invoke('unit_test_file_kind',$workspace->path('modules/alpha/unit_tests/alpha.json')),
		'empty'=>$surface->invoke('unit_test_case_count',[]),
		'list'=>$surface->invoke('unit_test_case_count',[['name'=>'one'],['name'=>'two']]),
		'legacy'=>$surface->invoke('unit_test_case_count',['function'=>'probe']),
		'object'=>$surface->invoke('unit_test_case_count',['name'=>'not executable']),
	]);
	$t->isTrue($surface->invoke('is_code_unit_test_file',$workspace->path('modules/alpha/unit_tests/alpha.test.php')));
	$t->isFalse($surface->invoke('is_code_unit_test_file',$workspace->path('modules/alpha/unit_tests/helper.php')));
	$t->isTrue($surface->invoke('is_dynamic_unit_test_path',$workspace->path('modules/alpha/unit_tests/dynamic/generated.json')));
	$t->isTrue($surface->invoke('is_internal_unit_test_fixture',$workspace->path('modules/dpanel/unit_tests/dpanel_mock_hidden.json')));

	$t->same([
		'module'=>'alpha',
		'legacy'=>'testing',
		'dynamic-module'=>'oauth',
		'dynamic'=>'dynamic',
		'app'=>'app',
		'unscoped'=>'unscoped',
	],$surface->invokeCases([
		'module'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/modules/alpha/unit_tests/a.json']],
		'legacy'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/dataphyre/testing/unit_tests/a.json']],
		'dynamic-module'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/unit_tests/dynamic/dataphyre/oauth/a.json']],
		'dynamic'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/unit_tests/dynamic/a.json']],
		'app'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/dataphyre/unit_tests/a.json']],
		'unscoped'=>['method'=>'module_name_from_unit_test_path','arguments'=>['/repo/tests/a.json']],
	]));

	$t->same(['alpha','beta'],$surface->invoke('collect_modules_in_folder',$workspace->path('modules'),['dpanel']));
	$runtimeQueue=$surface->invoke('module_queue_for_scope','runtime',$workspace->path('modules'));
	$t->same(['alpha','beta'],$runtimeQueue['queue']);
	$t->contains('skips the Dpanel module',$runtimeQueue['trace'][0]['message']);
	$missing=$surface->invoke('module_queue_for_scope','app',$workspace->path('missing'));
	$t->same('module_folder_missing',$missing['trace'][0]['type']);
	$empty=$surface->invoke('module_queue_for_scope','app',$workspace->directory('empty'));
	$t->same('module_folder_empty',$empty['trace'][0]['type']);
	$t->same([],$surface->invoke('collect_modules_in_folder',$workspace->file('not-a-directory','fixture')));
	$workspace->directory('modules/alpha/unit_tests/empty-leaf');
	$t->contains('alpha.json',implode("\n",$surface->invoke('collect_unit_test_files',$workspace->path('modules'),false)));

	$runtimeRoots=['common_dataphyre_runtime'=>$workspace->root().DIRECTORY_SEPARATOR];
	$t->contains('/modules/dpanel/kernel/dpanel.worker.php',$surface->invoke('unit_test_worker_script',$runtimeRoots));
	$t->contains('/modules/testing/tooling/code_worker.php',$surface->invoke('code_unit_test_worker_script',$runtimeRoots));
	$t->same('',$surface->invoke('unit_test_worker_script',[]));
	$t->same('',$surface->invoke('code_unit_test_worker_script',[]));
	$t->same('',$surface->invoke('worker_state_dir',[]));
	$t->contains('/cache/flightdeck/dpanel_workers',$surface->invoke('worker_state_dir',[
		'common_dataphyre'=>$workspace->root(),
	]));

	$workspace->directory('modules/testing/unit_tests');
	$t->same('',$surface->invoke('dataphyre_testing_unit_test_root',$runtimeRoots));
	$legacy=$t->workspace('flightdeck-dpanel-legacy-tests');
	$legacyRuntime=$legacy->directory('runtime');
	$legacyTests=$legacy->directory('testing/unit_tests');
	$legacy->file('testing/unit_tests/legacy.json','[{"name":"legacy case"}]');
	$t->same($legacyTests,$surface->invoke('dataphyre_testing_unit_test_root',[
		'common_dataphyre_runtime'=>$legacyRuntime,
	]));
	$legacyInventory=$surface->invoke('unit_test_inventory_for_scope','runtime',[
		'common_dataphyre_runtime'=>$legacyRuntime,
	]);
	$t->contains($legacyTests,implode("\n",$legacyInventory['roots']));
	$t->same(1,$legacyInventory['json_test_cases']);
	$t->same('',$surface->invoke('dataphyre_testing_unit_test_root',[]));
	$t->streamWrapper('dpanelbroken',DpFlightdeckDpanelBrokenDirectoryStream::class);
	$t->same([],$surface->invoke('collect_unit_test_files','dpanelbroken://root'));

	$runtimeInventory=$surface->invoke('unit_test_inventory_for_scope','runtime',$runtimeRoots);
	$t->contains('/modules',$runtimeInventory['roots'][0]);
	$t->isTrue($runtimeInventory['code_skipped_files']>=1);
	$runtimeState=$surface->invoke('populate_scan_queue',[
		'token'=>'runtime-path-scan','scope'=>'runtime','trace'=>[],'done'=>false,'batches'=>0,
	],$runtimeRoots);
	$t->same(['alpha','beta'],$runtimeState['queue']);
	$t->contains('skips the Dpanel module',json_encode($runtimeState['trace'],JSON_THROW_ON_ERROR));

	$inventory=[
		'test_cases'=>6,
		'modules'=>['alpha'=>2,'beta'=>1,'dynamic'=>2,'unscoped'=>1],
		'warnings'=>['invalid',['message'=>'discovery warning','file'=>'/tests/broken.test.php']],
		'files'=>[
			'invalid',
			['module'=>'alpha','path'=>'/tests/alpha.test.php','kind'=>'code','cases'=>2,'case_definitions'=>[['name'=>'first','suite'=>'Alpha'],['name'=>'second']]],
			['module'=>'missing','path'=>'/tests/missing.json','kind'=>'json','cases'=>1],
			['module'=>'dynamic','path'=>'/tests/dynamic.json','kind'=>'json','cases'=>1],
			['module'=>'beta','path'=>'','kind'=>'json','cases'=>1],
		],
	];
	$t->same(['alpha','beta'],$surface->invoke('unit_test_queue_from_inventory',$inventory,['alpha','beta']));
	$jobs=$surface->invoke('manifest_test_queue_from_inventory',$inventory,['alpha','beta']);
	$t->count(4,$jobs);
	$t->same('alpha',$jobs[0]['module']);
	$t->contains('Alpha / first #1',$surface->invoke('unit_test_worker_job_label',$jobs[0]));
	$t->contains('missing.json#1',$surface->invoke('unit_test_worker_job_label',$jobs[3]));
	$counts=$surface->invoke('apply_worker_inventory_counts',$inventory,['alpha'],$jobs);
	$t->hasPathValues([
		'module_worker_test_cases'=>2,
		'manifest_worker_test_cases'=>2,
		'code_worker_test_cases'=>2,
		'worker_test_cases'=>6,
		'deferred_test_cases'=>0,
	],$counts);
	$t->count(1,$surface->invoke('unit_test_inventory_trace',$inventory));

	$t->environment(['DATAPHYRE_DPANEL_INCLUDE_DYNAMIC_UNIT_TESTS'=>'YES']);
	$t->isTrue($surface->invoke('include_dynamic_unit_tests'));
	$t->environment(['DATAPHYRE_DPANEL_INCLUDE_DYNAMIC_UNIT_TESTS'=>null]);
	$t->isFalse($surface->invoke('include_dynamic_unit_tests'));
	$emptyInventory=$surface->invoke('unit_test_inventory_for_scope','invalid');
	$t->hasPathValues(['test_cases'=>0,'roots'=>[]],$emptyInventory);
});

test('Dpanel worker transport contains output exits malformed results and real test formats',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$workspace=$t->workspace('flightdeck-dpanel-workers');
	$worker=$workspace->file('workers/result.php',<<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents((string)$argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents((string)$payload['output_path'],json_encode([
	'passed'=>true,
	'trace'=>[],
	'output'=>'worker notice',
	'cases'=>[['name'=>'one']],
	'duration_seconds'=>0.01,
],JSON_THROW_ON_ERROR));
PHP);
	$quietWorker=$workspace->file('workers/quiet.php',<<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents((string)$argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents((string)$payload['output_path'],json_encode(['passed'=>true,'trace'=>[]],JSON_THROW_ON_ERROR));
PHP);
	$brokenWorker=$workspace->file('workers/broken.php',<<<'PHP'
<?php
fwrite(STDOUT,'bounded stdout');
fwrite(STDERR,'bounded stderr');
PHP);
	$failedWorker=$workspace->file('workers/failed.php',<<<'PHP'
<?php
$payload=json_decode((string)file_get_contents((string)$argv[1]),true);
file_put_contents((string)$payload['output_path'],json_encode(['passed'=>true,'trace'=>[]]));
exit(7);
PHP);
	$sleepWorker=$workspace->file('workers/sleep.php',<<<'PHP'
<?php
if(function_exists('pcntl_async_signals') && function_exists('pcntl_signal')){
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM,SIG_IGN);
}
sleep(3);
PHP);
	$traceWorker=$workspace->file('workers/trace.php',<<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents((string)$argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents((string)$payload['output_path'],json_encode([
	'passed'=>true,
	'trace'=>[['type'=>'probe_trace','level'=>'info','message'=>'worker trace without an owner']],
],JSON_THROW_ON_ERROR));
PHP);

	$result=$surface->invoke('run_unit_test_payload_worker','probe',$worker,['memory_limit'=>'64M'],'Probe worker','probe_worker');
	$t->isTrue($result['passed']);
	$t->hasPathValues(['cases.0.name'=>'one','duration_seconds'=>0.01],$result);
	$t->contains('emitted output',$result['trace'][0]['message']);
	$quiet=$surface->invoke('run_unit_test_payload_worker','probe',$quietWorker,[],'Quiet worker','quiet_worker');
	$t->isTrue($quiet['passed']);
	$t->contains('completed without diagnostic rows',$quiet['trace'][0]['message']);
	$broken=$surface->invoke('run_unit_test_payload_worker','probe',$brokenWorker,[],'Broken worker','broken_worker');
	$t->isFalse($broken['passed']);
	$t->containsAll(['did not return a valid result','bounded stdout','bounded stderr'],json_encode($broken,JSON_THROW_ON_ERROR));
	$failed=$surface->invoke('run_unit_test_payload_worker','probe',$failedWorker,[],'Failed worker','failed_worker');
	$t->isFalse($failed['passed']);
	$t->contains('exited with code 7',$failed['trace'][0]['message']);

	$failure=$surface->invoke('unit_test_worker_failure','probe','explicit failure',str_repeat('o',9000),str_repeat('e',9000),'probe_worker');
	$t->isFalse($failure['passed']);
	$t->same(8192,strlen($failure['trace'][0]['stdout']));
	$t->same(8192,strlen($failure['trace'][0]['stderr']));
	$withoutOutput=$surface->invoke('unit_test_worker_failure','probe','quiet failure',' ',' ');
	$t->isFalse(isset($withoutOutput['trace'][0]['stdout']));
	$t->isFalse(isset($withoutOutput['trace'][0]['stderr']));

	$pipe=fopen('php://temp','w+');
	fwrite($pipe,str_repeat('x',70000));
	rewind($pipe);
	$t->same(65536,strlen($surface->invoke('read_worker_pipe',$pipe)));
	fclose($pipe);

	$t->environment(['DATAPHYRE_DPANEL_PHP_BINARY'=>PHP_BINARY]);
	$t->contains(basename(PHP_BINARY),$surface->invoke('php_binary'));
	$t->environment(['DATAPHYRE_DPANEL_PHP_BINARY'=>null]);
	$automaticBinary=$surface->invoke('php_binary');
	$t->contains(DIRECTORY_SEPARATOR==='\\' ? 'php.exe' : 'php',$automaticBinary);
	$t->notContains('phpdbg',$automaticBinary);
	$t->same(0,$surface->invoke('worker_exit_code',0,-1));
	$t->same(7,$surface->invoke('worker_exit_code',-1,7));
	$cliBinary=rtrim((string)PHP_BINDIR,'/\\').DIRECTORY_SEPARATOR.(DIRECTORY_SEPARATOR==='\\' ? 'php.exe' : 'php');
	$t->contains(basename($cliBinary),$surface->invoke('php_binary',$cliBinary,'/missing-bindir'));
	$t->contains('/missing-current-php',$surface->invoke('php_binary','/missing-current-php','/missing-bindir'));
	$t->contains('dpanel.worker.php',$surface->invoke('unit_test_worker_script'));
	$t->contains('code_worker.php',$surface->invoke('code_unit_test_worker_script'));
	$t->isTrue($surface->invoke('code_unit_test_worker_available'));
	$t->same('',$surface->invoke('dataphyre_testing_unit_test_root'));
	$t->contains('/cache/flightdeck/dpanel_workers',$surface->invoke('worker_state_dir'));

	$codeTest=$workspace->file('modules/probe/unit_tests/probe.test.php',<<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;
suite('Dpanel code worker probe')->tag('probe')->group('framework-coverage');
test('worker executes a self-described case',static function(Context $t): void {
	$t->same(2,1+1);
});
PHP);
	$listed=$surface->invoke('run_code_unit_test_worker','probe',$codeTest,0,'list');
	$t->isTrue($listed['passed']);
	$t->hasPathValues(['cases.0.suite'=>'Dpanel code worker probe','cases.0.name'=>'worker executes a self-described case'],$listed);
	$executed=$surface->invoke('run_code_unit_test_worker','probe',$codeTest,0,'run');
	$t->isTrue($executed['passed']);
	$skip=$surface->invoke('code_unit_test_worker_skip','probe',$codeTest,'explicitly unavailable');
	$t->isTrue($skip['passed']);
	$t->contains('explicitly unavailable',$skip['trace'][0]['message']);

	$manifest=dirname(__DIR__,2).'/dpanel/unit_tests/dataphyre.dpanel.resolution.json';
	$json=$surface->invoke('run_unit_test_worker','dpanel',$manifest,0);
	$t->isTrue($json['passed'],json_encode($json,JSON_UNESCAPED_SLASHES));

	$missingWorker=$workspace->path('workers/missing.php');
	$t->environment(['DATAPHYRE_DPANEL_UNIT_TEST_WORKER'=>$missingWorker]);
	$t->same($missingWorker,$surface->invoke('unit_test_worker_script'));
	$unavailable=$surface->invoke('run_unit_test_worker','probe');
	$t->isFalse($unavailable['passed']);
	$t->contains('script is unavailable',$unavailable['trace'][0]['message']);
	$t->environment(['DATAPHYRE_DPANEL_UNIT_TEST_WORKER'=>null]);

	$t->environment(['DATAPHYRE_DPANEL_CODE_WORKER'=>$missingWorker]);
	$t->same($missingWorker,$surface->invoke('code_unit_test_worker_script'));
	$t->isFalse($surface->invoke('code_unit_test_worker_available'));
	$unavailableCode=$surface->invoke('run_code_unit_test_worker','probe',$codeTest);
	$t->isTrue($unavailableCode['passed']);
	$t->contains('code_worker.php is unavailable',$unavailableCode['trace'][0]['message']);
	$t->environment(['DATAPHYRE_DPANEL_CODE_WORKER'=>null]);

	$t->environment(['DATAPHYRE_DPANEL_WORKER_STATE_DIR'=>'/proc/dataphyre-dpanel-unavailable-state']);
	$t->same('/proc/dataphyre-dpanel-unavailable-state',$surface->invoke('worker_state_dir'));
	$unavailableState=$surface->invoke('run_unit_test_payload_worker','probe',$worker,[],'Probe worker','probe_worker');
	$t->isFalse($unavailableState['passed']);
	$t->contains('state directory is unavailable',$unavailableState['trace'][0]['message']);
	$t->environment(['DATAPHYRE_DPANEL_WORKER_STATE_DIR'=>'/proc']);
	$unwritableState=$surface->invoke('run_unit_test_payload_worker','probe',$worker,[],'Probe worker','probe_worker');
	$t->isFalse($unwritableState['passed']);
	$t->contains('payload could not be written',$unwritableState['trace'][0]['message']);
	$t->environment(['DATAPHYRE_DPANEL_WORKER_STATE_DIR'=>null]);

	$t->environment(['DATAPHYRE_DPANEL_WORKER_TIMEOUT_SECONDS'=>'0']);
	$t->same(1,$surface->invoke('unit_test_worker_timeout_seconds'));
	$t->environment(['DATAPHYRE_DPANEL_WORKER_TIMEOUT_SECONDS'=>'999']);
	$t->same(120,$surface->invoke('unit_test_worker_timeout_seconds'));
	$t->environment(['DATAPHYRE_DPANEL_WORKER_TIMEOUT_SECONDS'=>'invalid']);
	$t->same(8,$surface->invoke('unit_test_worker_timeout_seconds'));
	$t->environment(['DATAPHYRE_DPANEL_WORKER_TIMEOUT_SECONDS'=>'1']);
	$timedOut=$surface->invoke('run_unit_test_payload_worker','probe',$sleepWorker,[],'Slow worker','probe_worker');
	$t->isFalse($timedOut['passed']);
	$t->contains('timed out after 1 second',$timedOut['trace'][0]['message']);
	$t->environment(['DATAPHYRE_DPANEL_WORKER_TIMEOUT_SECONDS'=>null]);

	$t->environment([
		'DATAPHYRE_DPANEL_UNIT_TEST_WORKER'=>$traceWorker,
		'DATAPHYRE_DPANEL_CODE_WORKER'=>$traceWorker,
	]);
	$manifestBatch=$surface->invoke('run_manifest_worker_batch',DpFlightdeckDpanelFixture::scan([
		'queue'=>[],
		'test_queue'=>[],
		'test_done'=>true,
		'manifest_queue'=>[['module'=>'probe','path'=>$codeTest,'kind'=>'code','cases'=>1,'case_index'=>0]],
		'manifest_cursor'=>0,
		'manifest_done'=>false,
	]));
	$t->same('probe',$manifestBatch['trace'][0]['module']);
	$moduleBatch=$surface->invoke('run_worker_job_batch',DpFlightdeckDpanelFixture::scan([
		'queue'=>[],
		'test_queue'=>['probe'],
		'test_cursor'=>0,
		'test_done'=>false,
		'manifest_queue'=>[],
		'manifest_done'=>true,
	]),['type'=>'module','module'=>'probe','label'=>'probe'],'test_cursor',1);
	$t->same('probe',$moduleBatch['trace'][0]['module']);
});

test('Dpanel advances module test and manifest phases without exposing worker failures to the request',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$t->globalMap('_SESSION')->replace([]);

	$previousMemory=(string)ini_get('memory_limit');
	ini_set('memory_limit','64M');
	$raised=$surface->invoke('raise_diagnostic_memory_limit');
	$t->same('64M',$raised['previous']);
	$t->same(268435456,$raised['effective_bytes']);
	ini_set('memory_limit',$previousMemory);

	$scope=$surface->invoke('run_scope','invalid',['module-that-does-not-exist']);
	$t->same(1,$scope['processed']);
	$t->notEmpty($scope['trace']);
	$t->isTrue(\dataphyre\dpanel::$run_unit_tests);
	$t->isTrue(\dataphyre\dpanel::$load_module_entrypoints);

	$state=DpFlightdeckDpanelFixture::scan([
		'queue'=>['module-that-does-not-exist'],
		'cursor'=>0,
		'test_queue'=>[],
		'test_done'=>true,
		'manifest_queue'=>[],
		'manifest_done'=>true,
	]);
	$moduleBatch=$surface->invoke('run_scan_batch',$state);
	$t->same(1,$moduleBatch['cursor']);
	$t->same('module-that-does-not-exist',$moduleBatch['last_module']);
	$t->same(null,$moduleBatch['active_module']);

	$manifest=dirname(__DIR__,2).'/dpanel/unit_tests/dataphyre.dpanel.resolution.json';
	$manifestState=DpFlightdeckDpanelFixture::scan([
		'queue'=>[],
		'cursor'=>0,
		'test_queue'=>[],
		'test_done'=>true,
		'manifest_queue'=>[
			['module'=>'dpanel','path'=>$manifest,'kind'=>'json','cases'=>1,'case_index'=>0],
			'invalid',
		],
		'manifest_cursor'=>0,
		'manifest_done'=>false,
	]);
	$manifestBatch=$surface->invoke('run_manifest_worker_batch',$manifestState);
	$t->same(2,$manifestBatch['manifest_cursor']);
	$t->isTrue($manifestBatch['manifest_done']);
	$t->isTrue($manifestBatch['done']);
	$t->same(null,$manifestBatch['active_module']);

	$finished=$surface->invoke('run_manifest_worker_batch',DpFlightdeckDpanelFixture::scan([
		'queue'=>[],'manifest_queue'=>[],'manifest_cursor'=>0,'manifest_done'=>false,
	]));
	$t->isTrue($finished['done']);
	$delegated=$surface->invoke('run_test_worker_batch',DpFlightdeckDpanelFixture::scan([
		'queue'=>[],'test_queue'=>[],'test_cursor'=>0,'test_done'=>false,'manifest_queue'=>[],'manifest_done'=>false,
	]));
	$t->isTrue($delegated['done']);

	$testWorker=$surface->invoke('run_test_worker_batch',DpFlightdeckDpanelFixture::scan([
		'queue'=>[],
		'test_queue'=>['module-that-does-not-exist'],
		'test_cursor'=>0,
		'test_done'=>false,
		'manifest_queue'=>[],
		'manifest_done'=>true,
	]));
	$t->same(1,$testWorker['test_cursor']);
	$t->same('module-that-does-not-exist',$testWorker['last_test_module']);
	$t->same(null,$testWorker['active_module']);

	$missing=$surface->invoke('continue_scan','missing');
	$t->same(null,$missing);
	$done=DpFlightdeckDpanelFixture::scan(['done'=>true]);
	$surface->invoke('store_scan',$done);
	$t->same($done,$surface->invoke('continue_scan','scan-token'));
	$active=DpFlightdeckDpanelFixture::scan(['active_module'=>'alpha','active_started_at'=>time()]);
	$surface->invoke('store_scan',$active);
	$t->same($active,$surface->invoke('continue_scan','scan-token'));

	$t->globalMap('_SESSION')->replace([]);
	$started=$surface->invoke('start_scan','app');
	$t->same('app',$started['scope']);
	$t->isTrue($started['done']);
	$t->same($started['token'],$surface->invoke('last_scan')['token']);
});

test('Dpanel dispatch handles invalid valid paused resumed continued and AJAX controls',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$t->globalMap('_SESSION')->replace([]);
	$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET']);
	$t->globalMap('_POST')->replace([]);
	$page=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
	$t->containsAll(['Diagnostic Panel','Scan Runtime','unit-test diagnostics'],$page);

	$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'POST']);
	$t->globalMap('_POST')->replace(['csrf'=>'invalid']);
	$invalid=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
	$t->contains('Invalid Flightdeck form token',$invalid);

	$csrf=dataphyre_flightdeck_auth::csrf_token();
	$t->globalMap('_POST')->replace(['csrf'=>$csrf,'fd_dpanel_scope'=>'app']);
	$started=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
	$t->contains('Diagnostic Panel',$started);
	$scan=$surface->invoke('last_scan');
	$t->isTrue(is_array($scan));
	$t->isTrue($scan['done']);

	$control=DpFlightdeckDpanelFixture::scan([
		'queue'=>[],'test_queue'=>[],'test_done'=>true,'manifest_queue'=>[],'manifest_done'=>true,'done'=>false,
	]);
	$surface->invoke('store_scan',$control);
	foreach([
		'pause'=>'Resume Auto Scan',
		'resume'=>'Pause Auto Scan',
		'continue'=>'Diagnostic Panel',
	] as $action=>$expected){
		$t->globalMap('_POST')->replace([
			'csrf'=>$csrf,
			'fd_dpanel_action'=>$action,
			'fd_dpanel_token'=>'scan-token',
		]);
		$response=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
		$t->contains($expected,$response);
	}

	$t->globalMap('_POST')->replace([
		'csrf'=>$csrf,
		'fd_dpanel_action'=>'continue',
		'fd_dpanel_token'=>'scan-token',
		'fd_dpanel_ajax'=>'1',
	]);
	$ajax=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
	$t->hasPathValues(['done'=>true,'error'=>null],json_decode($ajax,true,512,JSON_THROW_ON_ERROR));
	$t->isTrue($surface->invoke('valid_csrf'));
	$t->isTrue($surface->invoke('valid_post','continue','scan-token'));
	$t->globalMap('_POST')->replace(['csrf'=>'invalid']);
	$t->isFalse($surface->invoke('valid_csrf'));
	$t->isTrue($surface->invoke('valid_post','continue','scan-token'));
	$t->isFalse($surface->invoke('valid_post','start','missing'));

	$t->globalMap('_SESSION')->replace([]);
	$t->globalMap('_POST')->replace([
		'csrf'=>$csrf,
		'fd_dpanel_action'=>'continue',
		'fd_dpanel_token'=>'missing-scan',
	]);
	$missingScan=$t->captureOutput(static fn()=>dataphyre_flightdeck_dpanel_surface::dispatch())->output();
	$t->contains('previous Dpanel scan state is no longer available',$missingScan);
});

test('Dpanel turns disabled runtime capabilities and repeated entrypoints into bounded diagnostics',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$fixture=__DIR__.'/fixtures/flightdeck_dpanel_environment_probe.php';

	$dispatch=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,[$root,'dispatch'],working_directory:$root,framework_root:$root,
	))->json();
	$t->hasPathValues(['pages'=>2,'repeated'=>true],$dispatch);

	$disabledProcesses=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		[$root,'disabled-processes'],
		working_directory:$root,
		framework_root:$root,
		php_ini:['disable_functions'=>'proc_open'],
	))->json();
	$t->hasPathValues([
		'proc_open_available'=>false,
		'unit_passed'=>false,
		'code_passed'=>true,
	],$disabledProcesses);
	$t->contains('proc_open is unavailable',$disabledProcesses['unit_message']);
	$t->contains('proc_open is unavailable',$disabledProcesses['code_message']);

	$disabledMemoryChange=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		[$root,'disabled-memory-change'],
		working_directory:$root,
		framework_root:$root,
		php_ini:['memory_limit'=>'32M','disable_functions'=>'ini_set'],
	))->json();
	$t->hasPathValues([
		'ini_set_available'=>false,
		'memory_limit'=>'32M',
		'processed'=>0,
		'batch_done'=>true,
	],$disabledMemoryChange);
	$t->containsAll([
		'Unable to raise diagnostic memory limit',
		'Embedded Dpanel scan aborted',
	],json_encode($disabledMemoryChange['scope_trace'],JSON_THROW_ON_ERROR));
	$t->contains('last batch did not advance',json_encode($disabledMemoryChange['batch_trace'],JSON_THROW_ON_ERROR));

	$descriptorFailure=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		[$root,'exhausted-process-descriptors'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->isFalse($descriptorFailure['passed']);
	$t->contains('process could not be started',$descriptorFailure['message']);
});

test('Dpanel inventories a disposable application ROOTPATH through the same module-owned test contract',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_dpanel_surface::class);
	$app=$t->rootpathWorkspace('dataphyre')->reset();
	$app->file('modules/alpha/alpha.main.php','<?php');
	$app->file('modules/alpha/unit_tests/alpha.json','[{"name":"json one"},{"name":"json two"}]');
	$app->file('modules/alpha/unit_tests/malformed.json','{invalid');
	$app->file('modules/alpha/unit_tests/alpha.test.php',<<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;
suite('Application-owned Dpanel probe')->tag('app-probe')->group('application-tests');
test('first application behavior is readable',static function(Context $t): void {
	$t->same('ready','ready');
})->id('app-probe.first');
test('dependent application behavior names its prerequisite',static function(Context $t): void {
	$t->isTrue(true);
})->id('app-probe.second')->dependsOn('app-probe.first');
PHP);
	$app->file('unit_tests/app.json','[{"name":"application root case","function":"strlen","args":["ok"],"expected":2}]');

	$inventory=$surface->invoke('unit_test_inventory_for_scope','app');
	$t->hasPathValues([
		'json_manifests'=>3,
		'json_test_cases'=>3,
		'code_files'=>1,
		'code_test_cases'=>2,
		'code_grouped_cases'=>2,
		'code_dependent_cases'=>1,
		'test_cases'=>5,
		'malformed'=>1,
		'modules.alpha'=>4,
		'modules.unscoped'=>1,
		'code_suites.Application-owned Dpanel probe'=>2,
	],$inventory);
	$t->count(2,$inventory['code_case_catalog']);
	$t->count(3,$inventory['files']);

	$state=$surface->invoke('populate_scan_queue',[
		'token'=>'app-scan',
		'scope'=>'app',
		'cursor'=>0,
		'trace'=>[],
		'done'=>false,
		'batches'=>0,
	]);
	$t->same(['alpha'],$state['queue']);
	$t->count(5,$state['manifest_queue']);
	$t->isTrue($state['test_done']);
	$t->isFalse($state['manifest_done']);
	$t->isFalse($state['done']);
	$t->same(5,$state['test_inventory']['worker_test_cases']);

	$app->file('modules/alpha/unit_tests/broken.test.php','<?php this is not valid PHP');
	$withBrokenCode=$surface->invoke('unit_test_inventory_for_scope','app');
	$t->hasPathValues(['code_files'=>2,'code_discovery_errors'=>1,'code_test_cases'=>3],$withBrokenCode);

	$listWorker=$app->file('workers/list.php',<<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents((string)$argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents((string)$payload['output_path'],json_encode([
	'passed'=>true,
	'trace'=>[],
	'cases'=>[
		'invalid case metadata',
		['suite'=>'Synthetic inventory','name'=>'describes its source','groups'=>['inventory'],'dependencies'=>['prior'],'tags'=>['fast']],
	],
],JSON_THROW_ON_ERROR));
PHP);
	$t->environment(['DATAPHYRE_DPANEL_CODE_WORKER'=>$listWorker]);
	$synthetic=$surface->invoke('unit_test_inventory_for_scope','app');
	$t->hasPathValues([
		'code_files'=>2,
		'code_test_cases'=>4,
		'code_grouped_cases'=>2,
		'code_dependent_cases'=>2,
		'code_suites.Synthetic inventory'=>2,
	],$synthetic);
	$t->count(2,$synthetic['code_case_catalog']);

	$t->environment(['DATAPHYRE_DPANEL_CODE_WORKER'=>$app->path('workers/missing.php')]);
	$withoutCodeWorker=$surface->invoke('unit_test_inventory_for_scope','app');
	$t->hasPathValues(['code_files'=>2,'code_skipped_files'=>2,'code_test_cases'=>0],$withoutCodeWorker);
	$t->count(1,$withoutCodeWorker['warnings']);
	$t->environment(['DATAPHYRE_DPANEL_CODE_WORKER'=>null]);

	$t->globalMap('_SESSION')->replace([]);
	$started=$surface->invoke('start_scan','app');
	$t->same('app',$started['scope']);
	$t->same(['alpha'],$started['queue']);
	$t->same($started['token'],$surface->invoke('last_scan')['token']);
})->sandboxesRootpath('dataphyre');
