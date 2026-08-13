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

if(!defined('DATAPHYRE_FLIGHTDECK_NO_DISPATCH')){
	define('DATAPHYRE_FLIGHTDECK_NO_DISPATCH',true);
}

require_once __DIR__.'/fixtures/flightdeck_debugbar_global_probes.php';
require_once __DIR__.'/fixtures/flightdeck_debugbar_runtime_probes.php';
require_once __DIR__.'/fixtures/flightdeck_view_templating_facade_probe.php';
require_once dirname(__DIR__).'/kernel/flightdeck.php';

suite('Flightdeck console exact behavior')
	->tag('flightdeck','console','routing','logs','stack','coverage')
	->group('framework-coverage')
	->contract('flightdeck.console.exact',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck')
	->through('closed routing','authenticated dispatch','module inventory','bounded log streaming','smart snippets')
	->isolation('process');

test('asset routing and scalar utilities expose stable closed contracts',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$t->contains('/dataphyre/flightdeck/assets/flightdeck-logs.css?v=',dataphyre_flightdeck::asset_url('../flightdeck-logs.css'));
	$t->matches('/^[a-f0-9]{16}$/',dataphyre_flightdeck::asset_version('flightdeck-logs.css'));
	$t->same(dataphyre_flightdeck::asset_version('flightdeck-logs.css'),dataphyre_flightdeck::asset_version('flightdeck-logs.css'));
	$t->same('missing',dataphyre_flightdeck::asset_version('missing.asset'));
	$t->contains('.fd-logs',dataphyre_flightdeck::asset_content('flightdeck-logs.css')['body']);
	$t->contains('fetchLogs',dataphyre_flightdeck::asset_content('flightdeck-logs.js')['body']);
	$t->same(dataphyre_flightdeck::asset_content('flightdeck-logs.css'),dataphyre_flightdeck::asset_content('flightdeck-logs.css'));
	$t->isNull(dataphyre_flightdeck::asset_content('bad asset'));
	$t->same('safe.css',$console->invoke('asset_name','../safe.css'));
	$t->same('',$console->invoke('asset_name','bad asset.css'));
	$t->same("const probe=true;\n",$console->invoke('script_body','<script>const probe=true;</script>'));
	$t->same('raw javascript',$console->invoke('script_body','raw javascript'));

	$t->same([
		'root'=>'dashboard','nested_dashboard'=>'dashboard','login'=>'login','logout'=>'logout',
		'logs'=>'logs','modules'=>'modules','flight_sheet'=>'flight-sheet','debugbar'=>'debugbar','unknown'=>'dashboard',
	],$console->invokeCases([
		'root'=>['method'=>'route','arguments'=>['/dataphyre']],
		'nested_dashboard'=>['method'=>'route','arguments'=>['/']],
		'login'=>['method'=>'route','arguments'=>['/dataphyre/login']],
		'logout'=>['method'=>'route','arguments'=>['/dataphyre/logout']],
		'logs'=>['method'=>'route','arguments'=>['/dataphyre/logs/live']],
		'modules'=>['method'=>'route','arguments'=>['/dataphyre/modules']],
		'flight_sheet'=>['method'=>'route','arguments'=>['/dataphyre/flight-sheet']],
		'debugbar'=>['method'=>'route','arguments'=>['/dataphyre/debugbar']],
		'unknown'=>['method'=>'route','arguments'=>['/dataphyre/arbitrary']],
	]));
	$t->same([
		'empty'=>'/dataphyre','protocol_relative'=>'/dataphyre','external'=>'/dataphyre',
		'relative'=>'/dataphyre','local'=>'/orders',
	],$console->invokeCases([
		'empty'=>['method'=>'safe_return_url','arguments'=>['']],
		'protocol_relative'=>['method'=>'safe_return_url','arguments'=>['//evil.test']],
		'external'=>['method'=>'safe_return_url','arguments'=>['https://evil.test']],
		'relative'=>['method'=>'safe_return_url','arguments'=>['orders']],
		'local'=>['method'=>'safe_return_url','arguments'=>['/orders']],
	]));

	$recursive=[];
	$recursive['self']=&$recursive;
	$t->contains('Unable to encode',$console->invoke('json_payload',$recursive));
	$t->same('{"ok":true}',$console->invoke('json_payload',['ok'=>true]));
	$t->same([
		'bytes'=>'12 B','kilobytes'=>'2 KB','megabytes'=>'2 MB','gigabytes'=>'2 GB',
		'milliseconds'=>'12.35 ms','seconds'=>'1.5 s','fatal'=>'error','warning'=>'warning','ok'=>'success',
	],$console->invokeCases([
		'bytes'=>['method'=>'format_bytes','arguments'=>[12]],
		'kilobytes'=>['method'=>'format_bytes','arguments'=>[2048]],
		'megabytes'=>['method'=>'format_bytes','arguments'=>[2097152]],
		'gigabytes'=>['method'=>'format_bytes','arguments'=>[2147483648]],
		'milliseconds'=>['method'=>'format_duration','arguments'=>[12.345]],
		'seconds'=>['method'=>'format_duration','arguments'=>[1500]],
		'fatal'=>['method'=>'finding_badge_level','arguments'=>['fatal']],
		'warning'=>['method'=>'finding_badge_level','arguments'=>[' WARNING ']],
		'ok'=>['method'=>'finding_badge_level','arguments'=>['ok']],
	]));
	$t->hasPathValues([
		'password'=>'[redacted]','nested'=>['api_token'=>'[redacted]','visible'=>'yes'],
	],$console->invoke('sanitize_config',['password'=>'secret','nested'=>['api_token'=>'token','visible'=>'yes']]));
	$t->contains('.fd-sidebar',$console->invoke('css'));
	$t->contains('.fd-log-controls',$console->invoke('logs_css'));
	$t->contains('fetchLogs',$console->invoke('logs_script'));
	$t->same('&quot;&lt;&amp;&gt;',$console->invoke('e','"<&>'));
	$t->contains('fd-metric',$console->invoke('metric_card','Label','<value>','<hint>'));
	$t->contains('Flightdeck view renderer is unavailable.',$console->invoke('layout','Title','Body','dashboard',[],false));
	$t->same(503,http_response_code());
	$t->contains('<html>',$console->invoke('layout','Title','Body','dashboard',[],true));
	$t->same('/custom/runtime/',$console->invoke('runtime_root',['common_dataphyre_runtime'=>'/custom/runtime']));
	$t->contains('/runtime/',$console->invoke('runtime_root',[]));
	$t->same('/custom/install/',$console->invoke('install_root',['common_dataphyre'=>'/custom/install']));
	$t->endsWith('/dataphyre/',$console->invoke('install_root',[]));
});

test('module and page models describe disposable installations without leaking secrets',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$workspace=$t->workspace('flightdeck-console-pages');
	$common=$workspace->directory('common/modules');
	$app=$workspace->directory('app/modules');
	$workspace->directory('common/modules/alpha');
	$workspace->directory('common/modules/sql');
	$workspace->file('common/modules/sql/version','1.2.3');
	$workspace->file('common/modules/not-a-module','file');
	$workspace->directory('common/modules/-disabled');
	$workspace->directory('app/modules/sql');
	$workspace->file('app/modules/sql/version','2.0.0');
	$workspace->directory('app/modules/datadoc');
	$roots=['common'=>$common,'app'=>$app];
	$rows=$console->invoke('module_rows',$roots);
	$t->same([], $console->invoke('module_rows', [
		'common'=>$workspace->path('missing/common-modules'),
		'app'=>null,
	]));
	$t->containsRows([
		['name'=>'alpha','source'=>'common','version'=>'1.0','link'=>null],
		['name'=>'datadoc','source'=>'app','version'=>'1.0','link'=>'/dataphyre/datadoc'],
		['name'=>'sql','source'=>'common, app','version'=>'2.0.0','link'=>'/dataphyre/sql'],
	],$rows);
	$t->isTrue($console->invoke('module_exists','alpha',$roots));
	$t->isTrue($console->invoke('module_exists','datadoc',$roots));
	$t->isFalse($console->invoke('module_exists','missing',$roots));
	$t->containsRows([
		['module'=>'flightdeck','href'=>'/dataphyre/logs'],
		['module'=>'sql','href'=>'/dataphyre/sql'],
		['module'=>'datadoc','href'=>'/dataphyre/datadoc'],
	],$console->invoke('module_interface_links',$roots));
	$t->same('/dataphyre/sql',$console->invoke('module_link','sql',$roots));
	$t->isNull($console->invoke('module_link','missing',$roots));
	$t->containsAll(['Modules','No page'],$console->invoke('modules_page'));
	$t->containsAll(['Runtime operations console','Control Pages'],$console->invoke('dashboard_page'));

	$missingSheet=$workspace->path('missing-flight-sheet.php');
	$t->contains('No flight sheet found',$console->invoke('flight_sheet_page',$missingSheet));
	$invalidSheet=$workspace->file('invalid-flight-sheet.php','<?php return "invalid";');
	$t->contains('0 top-level keys',$console->invoke('flight_sheet_page',$invalidSheet));
	$sheet=$workspace->file('flight_sheet.php',<<<'PHP'
<?php
return [
	'app'=>'shop',
	'password'=>'never-display',
	'nested'=>['api_key'=>'never-display','visible'=>'yes'],
];
PHP);
	$sheetPage=$console->invoke('flight_sheet_page',$sheet);
	$t->containsAll(['3 top-level keys','[redacted]','visible'],$sheetPage);
	$t->notContains('never-display',$sheetPage);

	$t->contains('No log files were found',$console->invoke('logs_page',static fn()=>null));
	$t->containsAll(['/logs/current.log','2 KB'],$console->invoke('logs_page',static fn()=>[
		'path'=>'/logs/current.log','size'=>2048,
	]));

	$config=$t->global('dataphyre_flightdeck_config')->replace(['enabled'=>true,'password'=>'']);
	$t->contains('Console password required',$console->invoke('login_page',null));
	$config->replace(['enabled'=>true,'password'=>'page-secret']);
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/login','HTTP_USER_AGENT'=>'Page model browser']);
	$t->containsAll(['Console Access','Login failed'],$console->invoke('login_page','Login failed'));

	$t->contains('No captured page requests',$console->invoke('debugbar_page',[
		'available'=>false,'enabled'=>false,'history'=>[],'selected'=>null,
	]));
	$history=[
		[
			'id'=>'request-500','recorded_at'=>1700000000,'label'=>'GET /orders','duration_ms'=>1500,
			'request'=>['status'=>503],'diagnostics'=>['count'=>2,'worst_level'=>'error'],
			'client'=>['event_count'=>3],'sql'=>['query_events'=>4],'timeline'=>['event_count'=>5],
			'comparison'=>['changes'=>[['key'=>'duration_ms','delta_label'=>'+10 ms']]],
		],
		[
			'id'=>'request-404','recorded_at'=>1700000001,'label'=>'GET /missing','duration_ms'=>20,
			'request'=>['status'=>404],'diagnostics'=>['count'=>1,'worst_level'=>'warning'],
			'client'=>[],'sql'=>[],'timeline'=>['events'=>[]],
			'comparison'=>['available'=>true,'summary'=>'similar'],
		],
		[
			'id'=>'request-200','recorded_at'=>1700000002,'label'=>'GET /','duration_ms'=>5,
			'request'=>['status'=>200],'diagnostics'=>[],'comparison'=>['changes'=>['malformed']],
		],
	];
	$historyPage=$console->invoke('debugbar_page',[
		'available'=>true,'enabled'=>true,'history'=>$history,'selected'=>$history[0],
	]);
	$t->containsAll(['Disable Toolbar','Clear History','+10 ms','similar','Captured Request'],$historyPage);
	$t->contains('request-500',$console->invoke('debugbar_page',[
		'available'=>true,'enabled'=>true,'history'=>$history,'selected'=>null,
	]));
	$t->isTrue($console->invoke('debugbar_state')['available']);
	$t->hasPathValues(['available'=>false,'enabled'=>false],$console->invoke('debugbar_state',false));

	foreach(['dashboard','logs','modules','flight-sheet','debugbar','unknown'] as $route){
		$t->contains('<html>',$t->captureOutput(static fn()=>$console->invoke('render',$route))->output(),$route);
	}
});

test('dispatch and actions fail closed before serving authenticated control pages',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$t->tempDirectory('console-auth-cache')]);
	$config=$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,'password'=>'console-secret','rate_limit'=>['window'=>30,'max_attempts'=>5],
		'debugbar'=>['enabled'=>true,'memory_limit'=>null],
	]);
	$server=$t->globalMap('_SERVER')->replace([
		'REQUEST_URI'=>'/dataphyre','REQUEST_METHOD'=>'GET','HTTP_USER_AGENT'=>'Console dispatch browser',
	]);
	$cookie=$t->globalMap('_COOKIE')->replace([]);
	$get=$t->globalMap('_GET')->replace([]);
	$post=$t->globalMap('_POST')->replace([]);

	$t->same('Flightdeck installation is incomplete.',$t->captureOutput(
		static fn()=>dataphyre_flightdeck::dispatch(null,['auth_available'=>false]),
	)->output());
	$t->same('Not found',$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
		'auth_available'=>true,'production_disabled'=>true,
	]))->output());
	$t->same('Flightdeck is disabled.',$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
		'auth_available'=>true,'production_disabled'=>false,'enabled'=>false,
	]))->output());

	$redirected=false;
	$terminator=static function()use(&$redirected): void {$redirected=true;};
	dataphyre_flightdeck::dispatch($terminator,[
		'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>false,
	]);
	$t->isTrue($redirected);
	$t->same(302,http_response_code());

	$server->put('REQUEST_URI','/dataphyre/login');
	$t->contains('Console Access',$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
		'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>false,
	]))->output());
	$server->put('REQUEST_URI','/dataphyre/logout');
	dataphyre_flightdeck::dispatch(null,[
		'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>true,
	]);
	$t->same(302,http_response_code());

	$t->same('Not found',$t->captureOutput(static fn()=>$console->invoke('handle_login',['production_disabled'=>true]))->output());
	$t->same('Flightdeck is disabled.',$t->captureOutput(static fn()=>$console->invoke('handle_login',[
		'production_disabled'=>false,'enabled'=>false,
	]))->output());
	$get->put('return','/orders');
	$console->invoke('handle_login',[
		'production_disabled'=>false,'enabled'=>true,'authenticated'=>true,'auth_required'=>true,
	]);
	$t->same(302,http_response_code());

	$server->put('REQUEST_METHOD','POST');
	$post->replace(['csrf'=>'invalid','password'=>'console-secret']);
	$t->contains('Invalid form token',$t->captureOutput(static fn()=>$console->invoke('handle_login',[
		'production_disabled'=>false,'enabled'=>true,'authenticated'=>false,'auth_required'=>true,
	]))->output());
	$csrf=dataphyre_flightdeck_auth::csrf_token();
	$post->replace(['csrf'=>$csrf,'password'=>'wrong']);
	$t->contains('Invalid Flightdeck password',$t->captureOutput(static fn()=>$console->invoke('handle_login',[
		'production_disabled'=>false,'enabled'=>true,'authenticated'=>false,'auth_required'=>true,
	]))->output());
	$post->replace(['csrf'=>$csrf,'password'=>'console-secret']);
	$t->same('',$t->captureOutput(static fn()=>$console->invoke('handle_login',[
		'production_disabled'=>false,'enabled'=>true,'authenticated'=>false,'auth_required'=>true,
	]))->output());
	$t->same(302,http_response_code());
	$t->isTrue(dataphyre_flightdeck_auth::authenticated());

	$server->replace([
		'REQUEST_URI'=>'/dataphyre','REQUEST_METHOD'=>'POST','HTTP_USER_AGENT'=>'Console dispatch browser',
	]);
	$post->replace(['ajax'=>'1']);
	$t->isTrue($console->invoke('is_log_ajax_request'));
	$post->replace(['ajax'=>'0']);
	$t->isFalse($console->invoke('is_log_ajax_request'));

	$get->replace(['action'=>'enable']);
	$console->invoke('handle_debugbar',true);
	$t->same(302,http_response_code());
	$t->contains('Disable Toolbar',$console->invoke('dashboard_page'));
	$get->replace(['action'=>'disable']);
	$console->invoke('handle_debugbar',true);
	$get->replace(['action'=>'clear_history']);
	$console->invoke('handle_debugbar',true);
	$get->replace([]);
	$t->contains('<html>',$t->captureOutput(static fn()=>$console->invoke('handle_debugbar',true))->output());
	$t->contains('<html>',$t->captureOutput(static fn()=>$console->invoke('handle_debugbar',false))->output());

	$t->hasPathValues(['ok'=>false,'message'=>'Invalid client event payload.'],json_decode(
		$t->captureOutput(static fn()=>$console->invoke('render_debugbar_client_event_response','not-json'))->output(),
		true,512,JSON_THROW_ON_ERROR,
	));
	$clientPayload=json_encode(['snapshot_id'=>'missing','token'=>'bad','events'=>[['type'=>'console']]],JSON_THROW_ON_ERROR);
	$t->isFalse(json_decode(
		$t->captureOutput(static fn()=>$console->invoke('render_debugbar_client_event_response',$clientPayload))->output(),
		true,512,JSON_THROW_ON_ERROR,
	)['ok']);

	$server->replace(['REQUEST_URI'=>'/dataphyre','REQUEST_METHOD'=>'GET','HTTP_USER_AGENT'=>'Console dispatch browser']);
	$get->replace([]);
	$post->replace([]);
	$t->contains('<html>',$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
		'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>true,
	]))->output());
	$t->contains('<html>',$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch_entrypoint(false))->output());

	$server->replace(['REQUEST_URI'=>'/dataphyre/logs','REQUEST_METHOD'=>'POST','HTTP_USER_AGENT'=>'Console dispatch browser']);
	$post->replace(['ajax'=>'1']);
	$t->hasPathValues(['ok'=>true,'available'=>false],json_decode(
		$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
			'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>true,
		]))->output(),
		true,512,JSON_THROW_ON_ERROR,
	));

	$server->replace(['REQUEST_URI'=>'/dataphyre/debugbar','REQUEST_METHOD'=>'POST','HTTP_USER_AGENT'=>'Console dispatch browser']);
	$get->replace(['action'=>'client_event']);
	$post->replace([]);
	$t->hasPathValues(['ok'=>false,'message'=>'Invalid client event payload.'],json_decode(
		$t->captureOutput(static fn()=>dataphyre_flightdeck::dispatch(null,[
			'auth_available'=>true,'production_disabled'=>false,'enabled'=>true,'authenticated'=>true,
		]))->output(),
		true,512,JSON_THROW_ON_ERROR,
	));
	dataphyre_flightdeck::dispatch_entrypoint(true);
});

test('log discovery and polling preserve complete HTML and text entry boundaries',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$workspace=$t->workspace('flightdeck-console-logs');
	$logs=$workspace->directory('logs');
	$workspace->directory('logs/ignored.log');
	$t->same([],$console->invoke('log_directories',['dataphyre'=>$workspace->path('missing')]));
	$t->same([$logs.'/'],$console->invoke('log_directories',[
		'dataphyre'=>$workspace->root(),'common_dataphyre'=>$workspace->root(),
	]));
	$t->isNull($console->invoke('latest_log_file_info',[$workspace->directory('empty-logs')]));

	$htmlEntries=[];
	for($index=0;$index<45;$index++){
		$htmlEntries[]='<tr><td>'.$index.'</td><td>HTML entry '.$index.'</td></tr>';
	}
	$htmlBody=implode('<!--ENDLOG-->',$htmlEntries).'<!--ENDLOG-->';
	$htmlFile=$workspace->file('logs/runtime.html',$htmlBody);
	$plainLines=[];
	for($index=0;$index<45;$index++){
		$plainLines[]='plain entry '.$index;
	}
	$plainFile=$workspace->file('logs/runtime.log',implode("\n",$plainLines)."\n");
	touch($htmlFile,time()-20);
	touch($plainFile,time()-5);
	$latest=$console->invoke('latest_log_file_info',[$logs]);
	$t->hasPathValues(['name'=>'runtime.log','extension'=>'log','size'=>strlen(implode("\n",$plainLines)."\n")],$latest);
	$t->matches('/^[a-f0-9]{40}$/',$latest['key']);
	$t->matches('/^[a-f0-9]{40}$/',$console->invoke('log_file_key',['path'=>$workspace->path('missing'),'size'=>0]));

	$htmlInfo=['path'=>$htmlFile,'size'=>filesize($htmlFile),'extension'=>'html'];
	$plainInfo=['path'=>$plainFile,'size'=>filesize($plainFile),'extension'=>'log'];
	$t->count(40,$console->invoke('recent_log_entries',$htmlInfo,false)['entries']);
	$t->count(40,$console->invoke('recent_log_entries',$plainInfo,false)['entries']);
	$htmlPoll=$console->invoke('poll_html_log_entries',$htmlInfo,0,false);
	$t->count(20,$htmlPoll['entries']);
	$t->isTrue($htmlPoll['has_more']);
	$t->greaterThan(0,$htmlPoll['offset']);
	$t->same(['entries'=>[],'offset'=>0],$console->invoke('recent_html_log_entries',['path'=>$htmlFile,'size'=>0],false));
	$t->same(['entries'=>[],'offset'=>0],$console->invoke('recent_plain_log_entries',['path'=>$plainFile,'size'=>0],false));
	$t->same([], $console->invoke('poll_log_entries',['path'=>$htmlFile,'size'=>filesize($htmlFile),'extension'=>'html'],filesize($htmlFile),false)['entries']);
	$t->same([], $console->invoke('poll_log_entries',['path'=>$plainFile,'size'=>filesize($plainFile),'extension'=>'log'],filesize($plainFile),false)['entries']);

	$missingPayload=json_decode($t->captureOutput(static fn()=>$console->invoke(
		'render_log_poll_response',static fn()=>null,
	))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->hasPathValues(['ok'=>true,'available'=>false,'reset'=>true],$missingPayload);

	$t->globalMap('_POST')->replace(['file_key'=>'stale','offset'=>999999]);
	$resetPayload=json_decode($t->captureOutput(static fn()=>$console->invoke(
		'render_log_poll_response',static fn()=>$latest,
	))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->hasPathValues(['available'=>true,'reset'=>true,'has_more'=>false],$resetPayload);

	$t->globalMap('_POST')->replace(['file_key'=>$latest['key'],'offset'=>0]);
	$pollPayload=json_decode($t->captureOutput(static fn()=>$console->invoke(
		'render_log_poll_response',static fn()=>$latest,
	))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->hasPathValues(['available'=>true,'reset'=>false],$pollPayload);
	$t->notEmpty($pollPayload['entries']);
});

test('log parser primitives handle partial delimiters mixed newlines and bounded chunks',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$delimiter='<!--ENDLOG-->';
	$t->same(['empty'=>0,'html'=>2,'plain'=>2],$console->invokeCases([
		'empty'=>['method'=>'log_break_count','arguments'=>['',$delimiter]],
		'html'=>['method'=>'log_break_count','arguments'=>['one'.$delimiter.'two'.$delimiter,$delimiter]],
		'plain'=>['method'=>'log_break_count','arguments'=>["one\ntwo\n","\n"]],
	]));
	$t->same(0,$console->invoke('complete_log_offset_from_tail','',10,20,$delimiter));
	$t->same(20,$console->invoke('complete_log_offset_from_tail','one'.$delimiter,0,20,$delimiter));
	$t->same(10,$console->invoke('complete_log_offset_from_tail','partial',10,20,$delimiter));
	$t->same(10+strlen('one'.$delimiter),$console->invoke('complete_log_offset_from_tail','one'.$delimiter.'partial',10,99,$delimiter));
	$t->same(20,$console->invoke('complete_log_offset_from_tail',"one\n",0,20,"\n"));
	$t->same(10,$console->invoke('complete_log_offset_from_tail','partial',10,20,"\n"));
	$t->same(14,$console->invoke('complete_log_offset_from_tail',"one\npartial",10,30,"\n"));

	$t->same([],$console->invoke('parse_tail_html_log_entries','',false,false));
	$t->count(2,$console->invoke('parse_tail_html_log_entries','first'.$delimiter.'second'.$delimiter,false,false));
	$t->count(1,$console->invoke('parse_tail_html_log_entries','partial'.$delimiter.'complete'.$delimiter.'tail',true,false));
	$t->count(1,$console->invoke('parse_tail_html_log_entries','single complete entry',false,false));
	$t->same([],$console->invoke('parse_tail_plain_log_entries','',false,false));
	$t->count(2,$console->invoke('parse_tail_plain_log_entries',"first\r\nsecond\n",false,false));
	$t->count(2,$console->invoke('parse_tail_plain_log_entries',"first\n\nsecond\n",false,false));
	$t->count(1,$console->invoke('parse_tail_plain_log_entries',"partial\ncomplete\ntail",true,false));
	$t->count(1,$console->invoke('parse_tail_plain_log_entries','single complete line',false,false));

	$t->same(['position'=>1,'next_cursor'=>2],$console->invoke('next_newline_position',"a\nb",0));
	$t->same(['position'=>1,'next_cursor'=>3],$console->invoke('next_newline_position',"a\r\nb",0));
	$t->same(['position'=>1,'next_cursor'=>2],$console->invoke('next_newline_position',"a\rb",0));
	$t->isNull($console->invoke('next_newline_position','abc',0));
	$t->same(null,$console->invoke('last_newline_position','abc'));
	$t->same(1,$console->invoke('last_newline_position',"a\rb"));
	$t->same(1,$console->invoke('last_newline_position',"a\nb"));
	$t->same(3,$console->invoke('last_newline_position',"a\nb\rc"));
	$t->isFalse($console->invoke('ends_with_newline',''));
	$t->isTrue($console->invoke('ends_with_newline',"line\n"));
	$t->isTrue($console->invoke('ends_with_newline',"line\r"));
	$t->isFalse($console->invoke('ends_with_newline','line'));
	$t->isTrue($console->invoke('ends_with','value',''));
	$t->isTrue($console->invoke('ends_with','value','ue'));
	$t->isFalse($console->invoke('ends_with','value','no'));

	$workspace=$t->workspace('flightdeck-log-parser-files');
	$file=$workspace->file('chunk.log',"one\ntwo\npartial");
	$t->same('',$console->invoke('read_log_bytes',$workspace->path('missing'),0));
	$t->same("one\ntwo\npartial",$console->invoke('read_log_bytes',$file,0));
	$t->same('two',$console->invoke('read_log_bytes',$file,4,3));
	$t->same("one\ntwo\n",$console->invoke('read_complete_log_chunk',$file,0,"\n"));
	$t->same('',$console->invoke('read_complete_log_chunk',$workspace->path('missing'),0,"\n"));
	$htmlFile=$workspace->file('chunk.html','one'.$delimiter.'two'.$delimiter.'partial');
	$t->same('one'.$delimiter.'two'.$delimiter,$console->invoke('read_complete_log_chunk',$htmlFile,0,$delimiter));
	$completeHtml=$workspace->file('complete.html','one'.$delimiter);
	$t->same('one'.$delimiter,$console->invoke('read_complete_log_chunk',$completeHtml,0,$delimiter));
	$noBreak=$workspace->file('no-break.log','partial');
	$t->same('',$console->invoke('read_complete_log_chunk',$noBreak,0,"\n"));
	$t->same('',$console->invoke('read_complete_log_chunk',$noBreak,0,$delimiter));

	$htmlSelection=$console->invoke('take_html_log_entries_from_chunk',' '.$delimiter.'one'.$delimiter.'two'.$delimiter,1,false);
	$t->count(1,$htmlSelection['entries']);
	$t->greaterThan(0,$htmlSelection['bytes']);
	$t->count(1,$console->invoke('take_html_log_entries_from_chunk','one'.$delimiter,10,false)['entries']);
	$plainSelection=$console->invoke('take_plain_log_entries_from_chunk',"\none\r\ntwo\rthree\n",2,false);
	$t->count(2,$plainSelection['entries']);
	$t->greaterThan(0,$plainSelection['bytes']);
	$t->same(['entries'=>[],'bytes'=>0],$console->invoke('take_plain_log_entries_from_chunk','partial',2,false));

	$large=$workspace->file('large.log',str_repeat('x',140000)."\nend\n");
	$window=$console->invoke('tail_log_window',$large,filesize($large),10,"\n");
	$t->greaterThan(0,strlen($window['segment']));
});

test('smart snippets support helper-backed and bootstrap fallback stack rendering',static function(Context $t): void {
	$console=$t->nonPublic(dataphyre_flightdeck::class);
	$workspace=$t->workspace('flightdeck-log-stack');
	$source=$workspace->file('Example.php',implode("\n",[
		'<?php',
		'    function first(): void {',
		'        second();',
		'    }',
		'    function second(): void {}',
	]));
	$entry="Stack Trace:\n#0 {$source}(3): second()\n#1 {$source}(2): first()";
	$t->same([],$console->invoke('stack_frames_from_log_entry',''));
	$frames=$console->invoke('stack_frames_from_log_entry',$entry."\n#2 {$source}(0): invalid()");
	$t->count(2,$frames);
	$t->isTrue($console->invoke('log_entry_has_smart_snippets',$entry,false));
	$t->isTrue($console->invoke('log_entry_has_smart_snippets','Call to undefined function missing_call()',false));
	$t->isFalse($console->invoke('log_entry_has_smart_snippets','ordinary log entry',false));
	$t->isTrue($console->invoke('log_entry_has_smart_snippets',$entry,true));

	$fallbackPanel=$console->invoke('render_log_stack_panel',$entry,false);
	$t->containsAll(['Stack Trace Snippets','fd-log-hit','2 frames'],$fallbackPanel);
	$t->same('',$console->invoke('render_log_stack_panel','ordinary log entry',false));
	$t->contains('Smart Diagnostics',$console->invoke(
		'render_log_stack_panel','Call to undefined function str_contans()',true,
	));
	$t->contains('Stack Trace Snippets',$console->invoke('render_log_stack_panel',$entry,true));

	$t->contains('Source unavailable',$console->invoke('render_log_stack_frame',[
		'index'=>9,'file'=>'','line'=>0,'call'=>'missing()',
	]));
	$t->contains('Source unreadable',$console->invoke(
		'render_log_stack_frame',$frames[0],static fn()=>false,
	));
	$t->contains('fd-log-hit',$console->invoke('render_log_stack_frame',$frames[0]));
	$t->same([],$console->invoke('normalize_log_snippet_lines',[]));
	$t->same(['plain','  indented'],$console->invoke('normalize_log_snippet_lines',['plain','  indented']));
	$t->same([' one',"\ttwo"],$console->invoke('normalize_log_snippet_lines',['  one'," \ttwo"]));
	$t->same(['one','','  two'],$console->invoke('normalize_log_snippet_lines',['    one','    ','      two']));

	$row='<tr><td>entry</td></tr>';
	$t->same($row,$console->invoke('append_log_stack_panel',$row));
	$diagnosticRow='<tr><td>Call to undefined function str_contans()</td></tr>';
	$t->contains('fd-log-stack',$console->invoke('append_log_stack_panel',$diagnosticRow));
	$t->contains('fd-log-stack',$console->invoke('append_log_stack_panel','Call to undefined function str_contans()'));
	$t->same($row,$console->invoke('append_log_snippet_trigger',$row));
	$t->contains('fd-log-snippet-button',$console->invoke('append_log_snippet_trigger',$diagnosticRow));
	$t->same('Call to undefined function str_contans()',$console->invoke(
		'append_log_snippet_trigger','Call to undefined function str_contans()',
	));
	$t->contains('fd-log-snippet-button',$console->invoke('plain_log_line_row','Call to undefined function str_contans()',false));
	$t->contains('fd-log-stack',$console->invoke('plain_log_line_row','Call to undefined function str_contans()',true));
	$t->contains('fd-log-snippet-button',$console->invoke('prepare_log_entry_html',$diagnosticRow,false));
	$t->contains('fd-log-stack',$console->invoke('prepare_log_entry_html',$diagnosticRow,true));

	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$t->tempDirectory('log-snippet-auth')]);
	$t->global('dataphyre_flightdeck_config')->replace(['enabled'=>true,'password'=>'snippet-secret']);
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/logs','HTTP_USER_AGENT'=>'Snippet browser']);
	$post=$t->globalMap('_POST')->replace(['csrf'=>'invalid','entry'=>$entry]);
	$invalid=json_decode($t->captureOutput(static fn()=>$console->invoke('render_log_snippet_response'))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->isFalse($invalid['ok']);
	$post->put('csrf',dataphyre_flightdeck_auth::csrf_token())->put('entry','ordinary log entry');
	$empty=json_decode($t->captureOutput(static fn()=>$console->invoke('render_log_snippet_response'))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->isFalse($empty['ok']);
	$post->put('entry',$entry);
	$success=json_decode($t->captureOutput(static fn()=>$console->invoke('render_log_snippet_response'))->output(),true,512,JSON_THROW_ON_ERROR);
	$t->isTrue($success['ok']);
	$t->contains('Stack Trace Snippets',$success['html']);

	$post->replace(['action'=>'render_snippets','csrf'=>dataphyre_flightdeck_auth::csrf_token(),'entry'=>$entry]);
	$t->isTrue(json_decode($t->captureOutput(static fn()=>$console->invoke(
		'render_log_poll_response',static fn()=>null,
	))->output(),true,512,JSON_THROW_ON_ERROR)['ok']);
});
