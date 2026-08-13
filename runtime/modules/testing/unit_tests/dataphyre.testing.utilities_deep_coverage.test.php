<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\AssertionFailed;
use Dataphyre\Test\BenchmarkResult;
use Dataphyre\Test\BrowserProbe;
use Dataphyre\Test\Context;
use Dataphyre\Test\Dataset;
use Dataphyre\Test\GeneratedCases;
use Dataphyre\Test\Generators;
use Dataphyre\Test\HtmlProbe;
use Dataphyre\Test\PhpRuntime;
use Dataphyre\Test\ProcessProbe;
use Dataphyre\Test\SkippedTest;
use Dataphyre\Test\StorageEventRecorder;
use function Dataphyre\Test\test;

if(!function_exists('Dataphyre\Test\proc_open')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Test;
final class DpTestKitUtilitySeams {
	private const CHANNEL='testing.utilities';
	public static function enabled(string $seam): bool {
		return TestState::channelIfActive(self::CHANNEL)?->get($seam, false)===true;
	}
}
function proc_open(array|string $command,array $descriptor_spec,&$pipes,?string $cwd=null,?array $env_vars=null,?array $options=null): mixed {
	if(DpTestKitUtilitySeams::enabled('proc_open_failure')){
		$pipes=[];
		return false;
	}
	return \proc_open($command,$descriptor_spec,$pipes,$cwd,$env_vars,$options);
}
function proc_get_status(mixed $process): array {
	if(DpTestKitUtilitySeams::enabled('force_running_process')){
		return ['running'=>true];
	}
	return \proc_get_status($process);
}
function function_exists(string $function): bool {
	if($function==='array_is_list' && DpTestKitUtilitySeams::enabled('hide_array_is_list')){
		return false;
	}
	return \function_exists($function);
}
function filemtime(string $filename): int|false {
	if(DpTestKitUtilitySeams::enabled('filemtime_failure')){
		return false;
	}
	return \filemtime($filename);
}
function touch(string $filename,?int $mtime=null,?int $atime=null): bool {
	if(DpTestKitUtilitySeams::enabled('touch_failure')){
		return false;
	}
	return \touch($filename,$mtime,$atime);
}
function realpath(string $path): string|false {
	if(DpTestKitUtilitySeams::enabled('realpath_failure')){
		return false;
	}
	return \realpath($path);
}
function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
	if(DpTestKitUtilitySeams::enabled('mkdir_failure')){
		return false;
	}
	return $context===null
		? \mkdir($directory,$permissions,$recursive)
		: \mkdir($directory,$permissions,$recursive,$context);
}
PHP);
}

function dp_testkit_util_failure(callable $callback): AssertionFailed {
	try{
		$callback();
	}catch(AssertionFailed $failure){
		return $failure;
	}
	throw new RuntimeException('Expected AssertionFailed.');
}

test('testing php child commands remain ordinary PHP under debugger coverage',static function(Context $t): void {
	$workspace=$t->workspace('testing-php-runtime');
	$debugger=$workspace->file('with-runtime/phpdbg.exe','');
	$php=$workspace->file('with-runtime/php.exe','');
	$missingDebugger=$workspace->file('without-runtime/phpdbg.exe','');

	$t->isTrue(PhpRuntime::isDebugger($debugger));
	$t->isFalse(PhpRuntime::isDebugger($php));
	$t->same(realpath($php),PhpRuntime::binary($debugger));
	$t->same($php,PhpRuntime::binary($php));
	$t->same($missingDebugger,PhpRuntime::binary($missingDebugger));
	$t->same([$php,'-r','exit(0);'],PhpRuntime::command(['-r','exit(0);'],$php));
	$t->same([$php,'-d','precision=12','-d','display_errors=1','-r','exit(0);'],PhpRuntime::command(
		['-r','exit(0);'],$php,['precision'=>12,'display_errors'=>true]
	));
	$t->same(['-d','probe='],PhpRuntime::iniArguments(['probe'=>null]));
	$t->throws(static fn()=>PhpRuntime::iniArguments(['bad setting'=>1]),InvalidArgumentException::class);
	$t->throws(static fn()=>PhpRuntime::iniArguments(['probe'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PhpRuntime::binary(''),InvalidArgumentException::class);
})->tag('testing','process','coverage')->group('framework-coverage');

test('managed filesystem probes expose deterministic read write resolve and launch failures',static function(Context $t): void {
	$workspace=$t->workspace('testing-filesystem-failures');
	$file=$workspace->file('clock.txt','tick');
	$seams=$t->state('testing.utilities');

	$seams->put('filemtime_failure',true);
	$t->throwsLike(static fn()=>$workspace->advanceMtime('clock.txt'),RuntimeException::class,'modification time');
	$seams->put('filemtime_failure',false);
	$seams->put('touch_failure',true);
	$t->throwsLike(static fn()=>$workspace->advanceMtime('clock.txt'),RuntimeException::class,'advance');
	$seams->put('touch_failure',false);
	$t->same($file,$workspace->advanceMtime('clock.txt'));

	$seams->put('realpath_failure',true);
	$t->throwsLike(static fn()=>$t->tempDirectoryIn($workspace->root(),'resolve'),InvalidArgumentException::class,'could not be resolved');
	$seams->put('realpath_failure',false);
	$seams->put('mkdir_failure',true);
	$t->throwsLike(static fn()=>$t->tempDirectoryIn($workspace->root(),'create'),RuntimeException::class,'Unable to create');
	$seams->put('mkdir_failure',false);

	$blocked=$t->workspace('testing-process-input-failure');
	$blocked->directory('stdin.log');
	$t->throwsLike(
		static fn()=>ProcessProbe::start($blocked,PhpRuntime::command(['-r','return;']),'input'),
		RuntimeException::class,
		'prepare managed test subprocess input'
	);
})->tag('testing','filesystem','failure-contracts')->group('framework-coverage');

test('testing browser probe runs pass skip failure invalid timeout and launch branches',static function(Context $t): void {
	$workspace=$t->workspace('testing-browser');
	$root=$workspace->root();
	$seams=$t->state('testing.utilities');
	$worker=<<<'PHP'
<?php
$payload=json_decode((string)file_get_contents($argv[1]),true);
$url=(string)($payload['url'] ?? '');
if($url==='timeout'){ sleep(3); exit(0); }
if($url==='invalid'){ exit(0); }
$result=match($url){
	'skip'=>['skipped'=>true,'reason'=>'worker skip'],
	'fail'=>['passed'=>false,'reason'=>'worker failure'],
	default=>['passed'=>true,'echo'=>$payload],
};
file_put_contents((string)$payload['output_path'],json_encode($result));
PHP;
	$workspace->file('runtime/modules/testing/tooling/browser_worker.js',$worker);
	$php=PhpRuntime::binary();
	$probe=new BrowserProbe($root,['node'=>$php]);
	$browser=$t->nonPublic($probe);
	$passed=$probe->assertHtml($t,'<main>OK</main>',['timeout_seconds'=>2]);
	$t->isTrue($passed['passed']);
	$t->same('<main>OK</main>',$passed['echo']['html']);
	$t->isTrue($probe->assertUrl($t,'pass')['passed']);
	$t->isTrue($probe->screenshot($t,'<main/>','proof')['passed']);
	putenv('DATAPHYRE_UPDATE_VISUAL_SNAPSHOTS=1');
	$visual=$probe->visualSnapshot($t,'<main/>','visual',false);
	putenv('DATAPHYRE_UPDATE_VISUAL_SNAPSHOTS');
	$t->isTrue($visual['echo']['update_visual_baseline']);

	try{
		$probe->assertUrl($t,'skip');
		throw new RuntimeException('Expected skip.');
	}catch(SkippedTest $skip){
		$t->same('worker skip',$skip->getMessage());
	}
	dp_testkit_util_failure(static fn()=>$probe->assertUrl($t,'fail'));
	dp_testkit_util_failure(static fn()=>$probe->assertUrl($t,'invalid'));
	$seams->put('force_running_process', true);
	$timeout=$browser->invoke('run', $t, PhpRuntime::command(['-r','sleep(30);'], $php), 1);
	$seams->put('force_running_process', false);
	$t->isTrue($timeout['timed_out']);
	$t->same(124,$timeout['exit_code']);

	$seams->put('proc_open_failure', true);
	$launch=$browser->invoke('run', $t, PhpRuntime::command(['-v'], $php), 1);
	$seams->put('proc_open_failure', false);
	$t->same(127,$launch['exit_code']);
	$t->contains('Unable to start test subprocess',$launch['stderr']);
	$t->throws(static fn()=>$browser->invoke('run',$t,PhpRuntime::command(['-v'],$php),0),InvalidArgumentException::class);

	$missing=new BrowserProbe($root.'/missing',['node'=>$php]);
	try{
		$missing->assertHtml($t,'<main/>');
		throw new RuntimeException('Expected missing worker skip.');
	}catch(SkippedTest $skip){
		$t->contains('unavailable',$skip->getMessage());
	}

	$blocked=$workspace->directory('blocked');
	$workspace->file('blocked/cache','blocker');
	$blockedProbe=new BrowserProbe($blocked,['node'=>$php]);
	$blockedBrowser=$t->nonPublic($blockedProbe);
	$t->throws(static fn()=>$blockedBrowser->invoke('tempDir'),RuntimeException::class);
})->tag('testing','browser','coverage')->group('framework-coverage');

test('testing benchmark storage html and dataset utilities cover boundary forms',static function(Context $t): void {
	$t->same('nested'.DIRECTORY_SEPARATOR.'fixture.php',$t->nativePath('nested/fixture.php'));
	$t->same('nested/fixture.php',$t->portablePath('nested\\fixture.php'));
	$t->same(DIRECTORY_SEPARATOR==='\\',$t->usesWindowsPathSemantics());
	$empty=new BenchmarkResult([],5,7);
	$t->same(0,$empty->iterations());
	$t->same(0.0,$empty->totalMillis());
	$t->same(0.0,$empty->meanMillis());
	$t->same(0.0,$empty->maxMillis());
	$t->same(0.0,$empty->percentileMillis(95));
	$t->same(5,$empty->memoryDeltaBytes());
	$t->same(7,$empty->peakDeltaBytes());
	$benchmark=new BenchmarkResult([3.0,1.0,2.0],10,20);
	$t->same(1.0,$benchmark->percentileMillis(-5));
	$t->same(3.0,$benchmark->percentileMillis(200));
	$t->same(2.0,$benchmark->meanMillis());
	$t->same(3,$benchmark->toArray()['iterations']);

	$events=new StorageEventRecorder();
	$events->record(['event'=>'storage.write','path'=>'one']);
	$events->record(['name'=>'storage.read','path'=>'one']);
	$t->same('storage.write',$events->events()[0]['name']);
	$events->assertRecorded($t,'storage.read',['path'=>'one']);
	dp_testkit_util_failure(static fn()=>$events->assertRecorded($t,'missing'));

	$html='<main id="root" class="one two" data-state="ready"><button id="save" class="primary" disabled title="A &amp; B"></button><a href=/x></a></main>';
	$t->same([],HtmlProbe::matches($html,'section'));
	$t->same([],HtmlProbe::matches($html,'#missing'));
	$t->same([],HtmlProbe::matches($html,'.missing'));
	$t->same([],HtmlProbe::matches($html,'[missing]'));
	$t->same([],HtmlProbe::matches($html,'[data-state=wrong]'));
	$t->same(1,count(HtmlProbe::matches($html,'main#root.one[data-state="ready"]')));
	$t->same('A & B',HtmlProbe::matches($html,'button[title]')[0]['attributes']['title']);
	$t->same('',HtmlProbe::matches($html,'button[disabled]')[0]['attributes']['disabled']);
	$t->same(['main#root.one.two','button#save.primary','a'],HtmlProbe::shape($html));

	$t->same(['a'=>[1],'b'=>[2]],iterator_to_array(Dataset::cases(['a'=>[1],'b'=>[2]])));
	$t->same(['array-row'=>[1]],Dataset::repeatable(['array-row'=>[1]]));
	$t->same(['1'=>[1],'2'=>[2],'3'=>[3]],iterator_to_array(Dataset::range(1,3,0)));
	$t->same(['3'=>[3],'2'=>[2],'1'=>[1]],iterator_to_array(Dataset::range(3,1,-1)));
	$matrix=iterator_to_array(Dataset::matrix(['x'=>['one'=>1,2],'y'=>['a']]));
	$t->same(2,count($matrix));
	$mapped=iterator_to_array(Dataset::map(['one'=>[1]],static fn(array $row,string $label): array=>[$row[0]+1,$label]));
	$t->same([2,'one'],$mapped['one']);
	$t->same(['a'=>1],iterator_to_array(Dataset::take(['a'=>1,'b'=>2],1)));
})->tag('testing','utilities','coverage')->group('framework-coverage');

test('testing generated cases replay shrink generators and tuples cover all paths',static function(Context $t): void {
	$seams=$t->state('testing.utilities');
	$plain=new GeneratedCases('plain',11,1,static fn(): array=>['one'=>[1]]);
	$t->same([1],iterator_to_array($plain)['one']);
	$t->same([1],$plain->shrink([1],static fn()=>null,$t));
	$t->same(['one'=>[1]],iterator_to_array($plain->replay('invalid token')));
	$token=$plain->replayToken('label',[9]);
	$t->same(['label'=>[9]],iterator_to_array($plain->replay($token)));
	$tokenWithoutLabel=base64_encode(json_encode(['case'=>[8]]));
	$t->same(['replay'=>[8]],iterator_to_array($plain->replay($tokenWithoutLabel)));

	$shrinking=new GeneratedCases('shrink',12,1,static fn(): array=>[],static fn(array $case): iterable=>[[4],['pass'],[2]]);
	$shrunk=$shrinking->shrink([8],static function(Context $context,mixed $value): void {
		if($value!=='pass'){
			throw new RuntimeException('still failing');
		}
	},$t);
	$t->same([2],$shrunk);
	$seams->put('hide_array_is_list', true);
	$shrinking->shrink([8],static fn()=>throw new RuntimeException('fail'),$t);
	$seams->put('hide_array_is_list', false);

	$t->same(3,count(iterator_to_array(Generators::strings(3,1,2,123))));
	$t->same(3,count(iterator_to_array(Generators::oneOf(['a','b'],3,123))));
	$t->same(2,count(iterator_to_array(Generators::integers(1,2,2))));
	$fuzzInts=Generators::fuzzIntegers(0,100,2);
	$t->same(2,count(iterator_to_array($fuzzInts)));
	$t->isTrue(is_array($fuzzInts->shrink([100],static fn()=>throw new RuntimeException('fail'),$t)));
	$fuzzStrings=Generators::fuzzStrings(2,1,4);
	$t->same(2,count(iterator_to_array($fuzzStrings)));
	$t->isTrue(is_array($fuzzStrings->shrink(['abcd'],static fn()=>throw new RuntimeException('fail'),$t)));
	$tuples=iterator_to_array(Generators::tuples(
		Generators::integers(1,2,2,1),
		['a'=>[['x','y']],'b'=>[['z']]],
	));
	$t->same(2,count($tuples));
	$t->between(1,2,$tuples['tuple_0'][0]);
	$t->same(['x','y'],$tuples['tuple_0'][1]);
	$t->same([],iterator_to_array(Generators::tuples()));
})->tag('testing','generators','coverage')->group('framework-coverage');
