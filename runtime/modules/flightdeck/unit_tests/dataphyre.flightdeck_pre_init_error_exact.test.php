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

require_once dirname(__DIR__).'/kernel/pre_init_error.php';

suite('Flightdeck pre-init diagnostics exact behavior')
	->tag('flightdeck','pre-init','diagnostics','bootstrap','coverage')
	->group('framework-coverage')
	->contract('flightdeck.pre-init.diagnostics-exact',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck','module:datadoc')
	->through('authentication gate','exception report','source fallback','DataDoc context','safe rendering')
	->isolation('process');

test('authentication gate fails closed and handles every login submission outcome',static function(Context $t): void {
	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$t->tempDirectory('pre-init-auth-cache')]);
	$config=$t->global('dataphyre_flightdeck_config')->replace(['enabled'=>false]);
	$server=$t->globalMap('_SERVER')->replace([
		'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/orders','HTTP_USER_AGENT'=>'Pre-init boundary browser',
	]);
	$cookie=$t->globalMap('_COOKIE')->replace([]);
	$post=$t->globalMap('_POST')->replace([]);

	$t->isFalse(dataphyre_flightdeck_pre_init_error::render('Unavailable auth',null,null,false));
	$t->isFalse(dataphyre_flightdeck_pre_init_error::render('Disabled diagnostics',null));

	$config->replace(['enabled'=>true,'password'=>'pre-init-secret','rate_limit'=>['window'=>30,'max_attempts'=>5]]);
	$get=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render('Bootstrap failed <safely>',null));
	$t->isTrue($get->result(),'GET login gate should report a handled response.');
	$t->containsAll(['Runtime diagnostic available','Bootstrap failed &lt;safely&gt;','View Diagnostic Report'],$get->output());
	$t->same(500,http_response_code());

	$config->replace(['enabled'=>true,'password'=>'']);
	$noPassword=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render(null,null));
	$t->contains('console password is not configured',$noPassword->output());

	$config->replace(['enabled'=>true,'password'=>'pre-init-secret','rate_limit'=>['window'=>30,'max_attempts'=>5]]);
	$server->put('REQUEST_METHOD','POST');
	$post->replace(['csrf'=>'invalid','password'=>'pre-init-secret']);
	$invalidCsrf=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render('Invalid token probe',null));
	$t->contains('Invalid form token',$invalidCsrf->output());

	$csrf=dataphyre_flightdeck_auth::csrf_token();
	$post->replace(['csrf'=>$csrf,'password'=>'wrong-secret']);
	$wrongPassword=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render('Wrong password probe',null));
	$t->contains('Invalid Flightdeck password',$wrongPassword->output());

	$cookie->replace([]);
	$post->replace(['csrf'=>$csrf,'password'=>'pre-init-secret']);
	$terminated=false;
	$terminator=static function()use(&$terminated): void {$terminated=true;};
	$success=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render(
		'Login success probe',null,$terminator,
	));
	$t->isTrue($success->result(),'Successful login submission should remain a handled response.');
	$t->same('',$success->output());
	$t->isTrue($terminated);
	$t->same(302,http_response_code());
});

test('authenticated reports delegate rich diagnostics and retain a complete bootstrap fallback',static function(Context $t): void {
	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$t->tempDirectory('pre-init-report-cache')]);
	$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,'password'=>'report-secret','rate_limit'=>['window'=>30,'max_attempts'=>5],
	]);
	$t->globalMap('_SERVER')->replace([
		'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/checkout','HTTP_USER_AGENT'=>'Pre-init report browser',
	]);
	$t->globalMap('_COOKIE')->replace([]);
	$t->isTrue(dataphyre_flightdeck_auth::login('report-secret'));
	$t->global('retroactive_tracelog')->replace([
		[__FILE__,123,'Checkout','submit','Order failed <safely>','error'],
	]);

	try{
		throw new RuntimeException('Authenticated bootstrap exception');
	}catch(RuntimeException $exception){
	}
	$report=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render(
		'Unable to initialize checkout',$exception,
	));
	$t->isTrue($report->result());
	$t->containsAll([
		'Bootstrap Diagnostic Report','Authenticated bootstrap exception','Code Snippets',
		'Retroactive Tracelog','Order failed &lt;safely&gt;','Trace',
	],$report->output());
	$diagnosticReport=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render(
		'Call to undefined function str_contans()',$exception,
	));
	$t->contains('Smart Diagnostics',$diagnosticReport->output());

	$emptyReport=$t->captureOutput(static fn()=>dataphyre_flightdeck_pre_init_error::render(null,null));
	$t->containsAll(['fatal bootstrap error','No stack frames available','No exception trace available'],$emptyReport->output());

	$renderer=$t->nonPublic(dataphyre_flightdeck_pre_init_error::class);
	$fallbackFrames=$renderer->invoke('frames',$exception,false);
	$t->greaterThanOrEqual(1,count($fallbackFrames));
	$t->hasPathValues(['index'=>0,'function'=>'throw','kind'=>'origin'],$fallbackFrames[0]);
	$t->same([],$renderer->invoke('frames',null,false));
	$t->same('unknown frame',$renderer->invoke('frame_symbol',[],false));
	$t->same('Checkout::submit',$renderer->invoke('frame_symbol',['class'=>'Checkout','function'=>'submit'],false));
	$t->same('submit',$renderer->invoke('frame_symbol',['function'=>'submit'],false));
	$t->same('',$renderer->invoke('render_smart_diagnostics','message',$exception,$fallbackFrames,false));
	$t->contains('fd-stack-map',$renderer->invoke('render_stack_map',$fallbackFrames,false));
	$t->contains('Origin',$renderer->invoke('stack_reference_badge',['kind'=>'origin'],'throw'));
	$t->contains('Callsite',$renderer->invoke('stack_reference_badge',['kind'=>'callsite'],'Checkout::submit'));
	$t->same('',$renderer->invoke('stack_reference_badge',['kind'=>'unknown'],'unknown'));
});

test('source snippets remain useful when helpers highlighters or readers are unavailable',static function(Context $t): void {
	$renderer=$t->nonPublic(dataphyre_flightdeck_pre_init_error::class);
	$workspace=$t->workspace('pre-init-source-fallback');
	$source=$workspace->file('Example.php',implode("\n",[
		'<?php',
		'    function first(): void {',
		'        second();',
		'    }',
		'',
		'    function second(): void {}',
	]));
	$frames=[
		['index'=>0,'file'=>$source,'line'=>3,'symbol'=>'second','kind'=>'origin'],
		['index'=>1,'file'=>$source,'line'=>2,'symbol'=>'first','kind'=>'callsite'],
		['index'=>2,'file'=>$source,'line'=>6,'symbol'=>'caller','kind'=>'callsite'],
	];
	$t->contains('Source unavailable',$renderer->invoke('render_snippet',[
		'index'=>9,'file'=>'','line'=>0,'symbol'=>'unknown',
	],[],false));
	$t->contains('Source unreadable',$renderer->invoke(
		'render_snippet',$frames[1],$frames,false,static fn()=>false,
	));
	$plaintext=$renderer->invoke(
		'render_snippet',$frames[1],$frames,false,null,static fn()=>null,
	);
	$t->containsAll(['fd-code','fd-hit','fd-callsite','Caller #','Callee #'],$plaintext);

	$t->same([],$renderer->invoke('normalize_snippet_lines',[]));
	$t->same(['plain','  indented'],$renderer->invoke('normalize_snippet_lines',['plain','  indented']));
	$t->same([' one',"\ttwo"],$renderer->invoke('normalize_snippet_lines',['  one'," \ttwo"]));
	$t->same(['one','','  two'],$renderer->invoke('normalize_snippet_lines',['    one','    ','      two']));

	$t->isNull($renderer->invoke(
		'datadoc_highlight','<?php echo 1;',1,1,[],$workspace->path('missing-highlighter.php'),
	));
	$emptyHighlighter=$workspace->file('empty-highlighter.php','<?php');
	$t->isNull($renderer->invoke('datadoc_highlight','<?php echo 1;',1,1,[],$emptyHighlighter));
	$t->contains('fd-snippet',$renderer->invoke('render_snippet',$frames[0],$frames,true));
	$t->same('<mark>probe</mark>',$renderer->invoke(
		'datadoc_highlight','probe',1,1,[],null,static fn()=>'<mark>probe</mark>',
	));
	$t->isNull($renderer->invoke(
		'datadoc_highlight','probe',1,1,[],null,static function(): never {
			throw new RuntimeException('Highlighter failed.');
		},
	));
	$t->contains('codeContainer',$renderer->invoke('datadoc_highlight','<?php echo 1;',10,10));
});

test('DataDoc context chooses the nearest indexed symbol and builds safe navigation',static function(Context $t): void {
	$renderer=$t->nonPublic(dataphyre_flightdeck_pre_init_error::class);
	$workspace=$t->workspace('pre-init-datadoc-context');
	$file=$workspace->file('project/src/Checkout.php','<?php');
	$projectPath=$workspace->path('project');
	$empty=$renderer->invoke('datadoc_frame_context',$file,20,$t->spy()->willReturn('not rows'));
	$t->same('',$empty['project']);

	$noMatch=$renderer->invoke('datadoc_frame_context',$file,20,$t->spy()->willReturn([
		['path'=>'','name'=>''],['path'=>'/somewhere/else','name'=>'elsewhere'],
	]));
	$t->same('',$noMatch['project']);

	$missingRows=$t->spy()->willReturnInOrder([
		['path'=>$projectPath,'name'=>'checkout'],
	],'not rows');
	$t->same('checkout',$renderer->invoke('datadoc_frame_context',$file,20,$missingRows)['project']);

	$selector=$t->spy()->willReturnInOrder([
		['path'=>$workspace->root(),'name'=>'workspace'],
		['path'=>'','name'=>'invalid'],
		['path'=>$projectPath,'name'=>'checkout'],
	],[
		['line'=>0,'function'=>'invalid'],
		['line'=>21,'type'=>'method','namespace'=>'Shop','class'=>'Checkout','function'=>'future'],
		['line'=>18,'type'=>'method','namespace'=>'Shop','class'=>'Checkout','function'=>'submit','content'=>'Submit order'],
	]);
	$context=$renderer->invoke('datadoc_frame_context',$file,20,$selector);
	$t->hasPathValues([
		'project'=>'checkout','namespace'=>'Shop','class'=>'Checkout','function'=>'submit',
	],$context);
	$t->contains('/checkout/dynadoc?',$context['datadoc_url']);
	$t->same(2,$selector->count());

	$failure=$t->spy()->willThrow(new RuntimeException('DataDoc unavailable.'));
	$t->same('',$renderer->invoke('datadoc_frame_context',$file,20,$failure)['project']);
	$t->same('/dataphyre/datadoc',$renderer->invoke('datadoc_base_url',false));
	$t->same('https://example.test/base/dataphyre/datadoc',$renderer->invoke(
		'datadoc_base_url',true,'https://example.test/base/',
	));
	$t->contains('/project-name',$renderer->invoke('datadoc_project_url','project-name'));
	$t->contains('function=submit',$renderer->invoke('datadoc_record_url','checkout',[
		'type'=>'method','namespace'=>'Shop','class'=>'Checkout','function'=>'submit','content'=>'Submit order',
	]));

	$frames=[['index'=>0,'symbol'=>'callee'],['index'=>1,'symbol'=>'current'],['index'=>2,'symbol'=>'caller']];
	$t->containsAll(['Open DataDoc Record','Callee #0','Caller #2'],$renderer->invoke('snippet_actions',[
		'datadoc_url'=>'/record','project_url'=>'/project',
	],$frames[1],$frames));
	$t->contains('Open DataDoc Project',$renderer->invoke('snippet_actions',['project_url'=>'/project']));
	$t->same('',$renderer->invoke('snippet_actions',[]));

	$t->global('retroactive_tracelog')->replace('malformed');
	$t->contains('No retroactive tracelog entries',$renderer->invoke('render_retroactive_tracelog'));
	$t->global('retroactive_tracelog')->replace([[__FILE__,10,'Class','method','message','warning']]);
	$t->containsAll(['Class::method','message','warning'],$renderer->invoke('render_retroactive_tracelog'));

	$t->same([
		'bytes'=>'12 B','kilobytes'=>'2 KB','megabytes'=>'2 MB','gigabytes'=>'2 GB',
	],$renderer->invokeCases([
		'bytes'=>['method'=>'format_bytes','arguments'=>[12]],
		'kilobytes'=>['method'=>'format_bytes','arguments'=>[2048]],
		'megabytes'=>['method'=>'format_bytes','arguments'=>[2097152]],
		'gigabytes'=>['method'=>'format_bytes','arguments'=>[2147483648]],
	]));
	$t->containsAll(['<dt>Key</dt>','&lt;value&gt;'],$renderer->invoke('definition_list',['Key'=>'<value>']));
	$t->contains('Dataphyre &lt;Diagnostic&gt;',$renderer->invoke('html_start','Dataphyre <Diagnostic>'));
	$t->same('</body></html>',$renderer->invoke('html_end'));
	$t->contains('.fd-shell',$renderer->invoke('css'));
	$t->same('&quot;&lt;&amp;&gt;',$renderer->invoke('e','"<&>'));
	include dirname(__DIR__).'/kernel/pre_init_error.php';
});
