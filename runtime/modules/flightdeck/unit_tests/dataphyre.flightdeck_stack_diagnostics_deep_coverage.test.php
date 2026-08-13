<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/debugbar.php';

test('Flightdeck stack diagnostics explain source frames and common PHP failures without evaluating source', static function(Context $t): void {
	$workspace=$t->workspace('flightdeck-stack-diagnostics');
	$source=$workspace->file('src/Checkout.php', <<<'PHP'
<?php
$userName='Ada';
$users=['name'=>'Ada'];
trim($users['nam']);
PHP);
	$t->global('users')->replace(['name'=>'Ada']);

	$origin=[
		'index'=>0,
		'file'=>$source,
		'line'=>4,
		'function'=>'trim',
		'class'=>'Checkout',
		'type'=>'::',
		'kind'=>'origin',
	];
	$callsite=array_replace($origin,['index'=>1,'kind'=>'callsite']);
	$frames=[$origin,$callsite];

	$t->same([],dataphyre_flightdeck_stack_snippets::frames_from_exception(null));
	$throwable=new class($source) {
		public function __construct(private string $source) {}
		public function getFile(): string { return $this->source; }
		public function getLine(): int { return 4; }
		public function getTrace(): array {
			return [
				['file'=>$this->source,'line'=>3,'class'=>'Checkout','type'=>'::','function'=>'load'],
				['function'=>'ignoredWithoutSource'],
			];
		}
	};
	$exceptionFrames=dataphyre_flightdeck_stack_snippets::frames_from_exception($throwable);
	$t->count(2,$exceptionFrames);
	$t->hasPathValues(['0.kind'=>'origin','1.kind'=>'callsite','1.symbol'=>'Checkout::load'],$exceptionFrames);

	$t->same([],dataphyre_flightdeck_stack_snippets::frames_from_log_entry(''));
	$logged=dataphyre_flightdeck_stack_snippets::frames_from_log_entry(
		"Stack Trace:\n#0 {$source}(4): Checkout::save()\n#1 {$source}(3): Checkout::load()"
	);
	$t->count(2,$logged);
	$t->same('unknown frame',dataphyre_flightdeck_stack_snippets::frame_symbol([]));
	$t->same('Checkout::save',dataphyre_flightdeck_stack_snippets::frame_symbol(['class'=>'Checkout','function'=>'save']));
	$t->same('save',dataphyre_flightdeck_stack_snippets::frame_symbol(['function'=>'save']));

	$map=dataphyre_flightdeck_stack_snippets::render_stack_map($frames,['id_prefix'=>'contract-']);
	$t->containsAll(['Call stack reference map','contract-0','Origin','Callsite','Checkout::trim'],$map);
	$t->same('',dataphyre_flightdeck_stack_snippets::render_panel([]));
	$panel=dataphyre_flightdeck_stack_snippets::render_panel($frames,[
		'summary'=>'Checkout failure',
		'limit'=>2,
		'context_lines'=>2,
		'show_stack_map'=>true,
		'use_datadoc_context'=>false,
		'diagnostic_text'=>'Undefined variable $userNme',
	]);
	$t->containsAll(['Checkout failure','fd-frame','fd-stack-map','Undefined variable','userNme','Checkout.php:4'],$panel);

	$available=dataphyre_flightdeck_stack_snippets::render_snippet($origin,$frames,[
		'context_lines'=>2,
		'use_datadoc_context'=>false,
		'show_actions'=>false,
	]);
	$t->containsAll(['Checkout.php:4','trim','Origin'],$available);
	$unavailable=dataphyre_flightdeck_stack_snippets::render_snippet(['index'=>3,'file'=>'','line'=>0],[],['show_actions'=>false]);
	$t->containsAll(['Unknown file','Source unavailable'],$unavailable);

	$message=implode("\n",[
		"trim(): Argument #1 (\$string) must be of type string, array given, called in {$source} on line 4",
		"require(missing/bootstrap.php): Failed to open stream",
		'Undefined variable $userNme',
		'Call to undefined function str_contans()',
		'Call to undefined method dataphyre_flightdeck_debugbar::asset_urll()',
		'Undefined property: dataphyre_flightdeck_debugbar::$sql_observer_attache',
		'Undefined constant "DATAPHYRE_FLIGHTDECK_DEBUGBAR_LOADE"',
		'Class "dataphyre_flightdeck_debugba" not found',
	]);
	$diagnostics=dataphyre_flightdeck_stack_snippets::render_diagnostics($message,$frames,['compact'=>true,'title'=>'Read-only evidence']);
	$t->containsAll([
		'Read-only evidence','Argument type mismatch','Include path check','Undefined variable',
		'Undefined function','Undefined method','Undefined property','Undefined constant','Missing class',
		'Argument expression','$users[&#039;nam&#039;]','missing/bootstrap.php',
	],$diagnostics);
})->tag('flightdeck','coverage','stack-diagnostics')->group('framework-coverage');

test('Flightdeck stack diagnostic primitives normalize expressions paths names and snippet lines', static function(Context $t): void {
	$internals=$t->nonPublic(dataphyre_flightdeck_stack_snippets::class);
	$workspace=$t->workspace('flightdeck-stack-primitives');
	$source=$workspace->file('src/Probe.php', "<?php\n\$customerName='Ada';\nprobe(null, ['nested'=>true], call(1, 2));\n");
	$frame=['index'=>0,'file'=>$source,'line'=>3,'function'=>'probe','kind'=>'origin'];

	$t->same(["'a,b'",' [1, 2]',' call(3, 4)'],$internals->invoke('split_call_arguments',"'a,b', [1, 2], call(3, 4))"));
	$t->same(['unterminated'],$internals->invoke('split_call_arguments','unterminated'));
	$t->same(["'a\\'b'",' {nested:{value:1}}'],$internals->invoke('split_call_arguments',"'a\\'b', {nested:{value:1}})"));
	$t->same('', $internals->invoke('call_argument_expression','',0,'probe',1));
	$t->same('', $internals->invoke('call_argument_expression',$source,99,'probe',1));
	$t->same('', $internals->invoke('call_argument_expression',$source,3,'missingCallable',1));
	$t->same('', $internals->invoke('call_argument_expression',$source,3,'probe',9));
	$t->same("['nested'=>true]",$internals->invoke('call_argument_expression',$source,3,'probe',2));

	$t->same([], $internals->invoke('analyze_expression_value',''));
	$t->same('literal null',$internals->invoke('analyze_expression_value','null')['rows']['Expression value']);
	$t->global('probeValue')->replace('value');
	$t->contains('string(5)',$internals->invoke('analyze_expression_value','$probeValue')['rows']['Runtime value']);
	$t->global('probeMap')->replace(['known'=>'value']);
	$missing=$internals->invoke('analyze_expression_value',"\$probeMap['knwon']");
	$t->same('no',$missing['rows']['Key exists']);
	$t->contains('known',$missing['suggestions']);
	$t->global('notAMap')->replace('scalar');
	$t->contains('not array',$internals->invoke('analyze_expression_value',"\$notAMap['key']")['rows']['Runtime value']);
	if(!class_exists('dataphyre\\routing',false)){
		$t->defineSymbols('namespace dataphyre; class routing { public static array $bindings=["order_id"=>42]; }');
	}
	$routeBinding=$internals->invoke('analyze_expression_value',"\\dataphyre\\routing::\$bindings['orderid']");
	$t->same('no',$routeBinding['rows']['Key exists']);
	$t->contains('order_id',$routeBinding['suggestions']);
	$t->same([], $internals->invoke('analyze_expression_value','$object->method()'));

	$expectedSummaries=[
		[null,'null'],
		[true,'bool true'],
		[12,'integer 12'],
		[1.5,'double 1.5'],
	];
	foreach($expectedSummaries as [$value,$expected]){
		$t->same($expected,$internals->invoke('value_summary',$value));
	}
	$t->same('array(2)',$internals->invoke('value_summary',[1,2]));
	$t->contains('object stdClass',$internals->invoke('value_summary',new stdClass()));
	$t->contains('string(90)',$internals->invoke('value_summary',str_repeat('x',90)));
	$resource=fopen('php://memory','rb');
	$t->same('resource',$internals->invoke('value_summary',$resource));
	fclose($resource);

	$relative=$internals->invoke('include_path_candidates','missing.php',[$frame]);
	$t->notEmpty($relative);
	$t->same([str_replace('\\','/',$source)],$internals->invoke('include_path_candidates',$source,[$frame]));
	$include=$internals->invoke('analyze_include_path',$source,[$frame]);
	$t->isTrue($include['exists']);
	$t->same('yes',$include['rows']['Readable']);
	$t->same($frame,$internals->invoke('source_frame',[['file'=>'','line'=>0],$frame]));
	$t->same(null,$internals->invoke('source_frame',[]));
	$t->contains('customerName',$internals->invoke('variables_near_frame',$source,3));
	$t->same([], $internals->invoke('variables_near_frame',$workspace->path('missing.php'),3));
	$t->same(['customerName'],$internals->invoke('closest_names','customerNme',['other','customerName','customerNme']));
	$t->same([], $internals->invoke('closest_names','', ['anything']));
	$t->same('Thing',$internals->invoke('basename_symbol','Vendor\\Package\\Thing'));
	$t->same('method',$internals->invoke('basename_callable','Vendor\\Thing::method'));
	$t->isTrue($internals->invoke('is_absolute_path',$source));
	$t->isFalse($internals->invoke('is_absolute_path','relative/path.php'));
	$t->same('yes',$internals->invoke('yes_no',true));
	$t->same('no',$internals->invoke('yes_no',false));
	$t->same(['  one','two'],$internals->invoke('normalize_snippet_lines',['    one','  two']));
	$t->same(['','one'],$internals->invoke('normalize_snippet_lines',['','one']));
	$t->same([' one','\tone'],$internals->invoke('normalize_snippet_lines',[' one','\tone']));
	$t->same('', $internals->invoke('stack_reference_badge',[],'unknown'));
	$t->same('', dataphyre_flightdeck_stack_snippets::render_diagnostics('unrecognized diagnostic',[]));

	$actions=$internals->invoke('snippet_actions',[
		'datadoc_url'=>'/dataphyre/datadoc/demo/dynadoc?type=function',
		'project_url'=>'/dataphyre/datadoc/demo',
	],['index'=>1],[[ 'symbol'=>'callee'],['symbol'=>'current'],['symbol'=>'caller']],[]);
	$t->containsAll(['Open DataDoc Record','Callee #0','Caller #2'],$actions);
	$projectAction=$internals->invoke('snippet_actions',['project_url'=>'/dataphyre/datadoc/demo'],[],[],[]);
	$t->contains('Open DataDoc Project',$projectAction);
	$t->same('', $internals->invoke('snippet_actions',[],[],[],['show_datadoc_actions'=>false,'show_stack_links'=>false]));
	$t->contains('/dataphyre/datadoc/demo',$internals->invoke('datadoc_project_url','demo'));
	$t->containsAll(['dynadoc?','type=function','content=run'],$internals->invoke('datadoc_record_url','demo',[
		'type'=>'function','namespace'=>'Demo','class'=>'Probe','function'=>'run','content'=>'run',
	]));
	$t->same('/dataphyre/datadoc',$internals->invoke('datadoc_base_url'));

	$context=$internals->invoke('datadoc_frame_context',$source,3);
	$t->hasPathValues(['project'=>'','namespace'=>'','class'=>'','function'=>''],$context);
	$t->same('&lt;tag&gt;',$internals->invoke('e','<tag>'));
})->tag('flightdeck','coverage','stack-diagnostics')->group('framework-coverage');
