<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\CoverageParts;
use Dataphyre\Test\CoveredPhpProcessProbe;
use Dataphyre\Test\ProcessResult;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Coverage-carrying PHP subprocesses')
	->tag('testing','coverage','process')
	->group('framework-coverage')
	->contract('testing.coverage.covered-subprocess',1)
	->layer('integration')
	->risk('critical')
	->watches(
		'module:testing',
		'path:runtime/modules/testing/tooling/bootstrap.php',
		'path:runtime/modules/testing/tooling/CoverageSubprocess.php'
	)
	->through('command planning','wrapper capture','normal return','worker aggregation')
	->isolation('process');

test('covered process plans are shell-free and engine-explicit',static function(Context $t): void {
	$phpdbg=CoveredPhpProcessProbe::plan(
		['target.php','arg'],'prepend.php','phpdbg.exe','phpdbg',false,'512M',
		['disable_functions'=>'proc_open','display_errors'=>false]
	);
	$t->same([
		'phpdbg.exe','-d','memory_limit=512M','-d','disable_functions=proc_open','-d','display_errors=0',
		'-qrr','prepend.php','target.php','arg',
	],$phpdbg['command']);
	$t->isTrue($phpdbg['instrumented']);
	$t->same('phpdbg',$phpdbg['engine']);

	$xdebug=CoveredPhpProcessProbe::plan(['target.php'],'prepend.php','php.exe','cli',true,'512M');
	$t->same(['php.exe','-d','memory_limit=512M','prepend.php','target.php'],$xdebug['command']);
	$t->isTrue($xdebug['instrumented']);
	$t->same('xdebug',$xdebug['engine']);

	$ordinary=CoveredPhpProcessProbe::plan(['target.php'],'prepend.php','php.exe','cli',false,'512M');
	$t->same(['php.exe','target.php'],$ordinary['command']);
	$t->isFalse($ordinary['instrumented']);
	$t->same('included_files',$ordinary['engine']);
	$ordinaryWithIni=CoveredPhpProcessProbe::plan(
		['target.php'],'prepend.php','php.exe','cli',false,'512M',['precision'=>12]
	);
	$t->same(['php.exe','-d','precision=12','target.php'],$ordinaryWithIni['command']);
	$t->throws(static fn()=>CoveredPhpProcessProbe::plan([], 'prepend.php', phpIni:['bad setting'=>1]),InvalidArgumentException::class);

	CoverageParts::reset();
	CoverageParts::add(['engine'=>'included_files','files'=>['runtime/modules/example/Declaration.php'], 'included_files'=>['runtime/modules/example/Declaration.php']]);
	CoverageParts::add(['engine'=>'included_files','files'=>['runtime/modules/example/Other.php', 'runtime/modules/example/Declaration.php']]);
	$t->count(1,CoverageParts::all());
	$t->same(['runtime/modules/example/Declaration.php','runtime/modules/example/Other.php'],CoverageParts::all()[0]['files']);
	CoverageParts::reset();
	CoverageParts::add(['engine'=>'phpdbg','files'=>[
		'runtime/modules/example/Feature.php'=>[
			'executable_ranges'=>'2-5','covered_ranges'=>'2','raw_executable_ranges'=>'1-5','ignored_ranges'=>'1','ignored_reasons'=>['brace-only'=>'1'],
		],
	], 'included_files'=>['runtime/modules/example/Feature.php']]);
	CoverageParts::add(['engine'=>'phpdbg','files'=>[
		'runtime/modules/example/Feature.php'=>[
			'executable_lines'=>[2,3,4,5],'covered_lines'=>[3,5],'ignored_lines'=>[1],'ignored_by_reason'=>['brace-only'=>[1]],
		],
	], 'included_files'=>['runtime/modules/example/Feature.php']]);
	$merged=CoverageParts::all();
	$t->count(1,$merged);
	$t->same('2-3,5',$merged[0]['files']['runtime/modules/example/Feature.php']['covered_ranges']);
	$t->same(3,$merged[0]['files']['runtime/modules/example/Feature.php']['covered']);
	$t->same('1',$merged[0]['files']['runtime/modules/example/Feature.php']['ignored_reasons']['brace-only']);
	$t->throws(static fn()=>CoverageParts::add(['engine'=>'unknown','files'=>[]]),UnexpectedValueException::class);
	$t->throws(static fn()=>CoverageParts::add(['engine'=>'phpdbg','files'=>'invalid']),UnexpectedValueException::class);
	CoverageParts::add(['engine'=>'phpdbg','files'=>['broken'=>['executable_ranges'=>'9-2']]]);
	$t->throws(static fn()=>CoverageParts::all(),UnexpectedValueException::class);
	CoverageParts::reset();
	CoverageParts::add(['engine'=>'phpdbg','files'=>['broken'=>[
		'executable_ranges'=>'1','covered_ranges'=>'1','ignored_reasons'=>['invalid'=>false],
	]]]);
	$t->throwsLike(static fn()=>CoverageParts::all(),UnexpectedValueException::class,'invalid ignored-line ranges');
	CoverageParts::reset();
	$t->same([],CoverageParts::all());

	$diagnostic=(new ProcessResult(['php','fixture.php'],7,str_repeat('out',80),str_repeat('err',80),false,0.25))->diagnostic(64);
	$t->same(7,$diagnostic['exit_code']);
	$t->contains('truncated',$diagnostic['stdout']);
	$t->contains('truncated',$diagnostic['stderr']);
	$t->throws(static fn()=>(new ProcessResult([],0,'','',false,0.0))->diagnostic(8),InvalidArgumentException::class);
});

test('covered php process returns its child line map to the worker',static function(Context $t): void {
	$workspace=$t->workspace('covered-process-integration');
	$tooling=dataphyre_path().'/runtime/modules/testing/tooling';
	foreach(['CoverageLineNormalizer.php','PhpdbgLineMap.php','WorkerCoverage.php','CoverageSubprocess.php'] as $file){
		$workspace->copy($tooling.'/'.$file,'runtime/modules/testing/tooling/'.$file);
	}
	$target=$workspace->file('runtime/modules/example/Framework/ExitTarget.php',<<<'PHP'
<?php
declare(strict_types=1);
$request=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
echo json_encode([
	'handled'=>$request['name'] ?? null,
	'coverage_part'=>(string)(getenv('DATAPHYRE_TEST_COVERAGE_PART') ?: ''),
	'precision'=>(int)ini_get('precision'),
],JSON_THROW_ON_ERROR);
return 0;
PHP);
	$result=$t->coveredPhpProcess(
		[$target],
		'{"name":"covered"}',
		$workspace->root(),
		[],
		10000,
		$workspace->root(),
		['precision'=>12]
	);
	$t->isTrue($result->succeeded(),$result->stderr());
	$payload=$result->json();
	$t->same('covered',$payload['handled']);
	$t->same(12,$payload['precision']);
	$t->isTrue(is_string($payload['coverage_part']));
	if(PHP_SAPI==='phpdbg'){
		$parts=CoverageParts::all();
		$t->count(1,$parts);
		$t->same('phpdbg',$parts[0]['engine']);
		$t->hasKey('runtime/modules/example/Framework/ExitTarget.php',$parts[0]['files']);
		$t->contains('runtime/modules/example/Framework/ExitTarget.php',$parts[0]['included_files']);
	}else{
		$t->same([],CoverageParts::all());
	}
});

test('instrumented child failures explain a missing exact coverage payload',static function(Context $t): void {
	$workspace=$t->workspace('covered-process-missing-payload');
	$target=$workspace->file('target.php','<?php return;');
	$plan=CoveredPhpProcessProbe::plan([$target],$workspace->path('missing-bootstrap.php'));
	if(!$plan['instrumented']){
		$t->isTrue(CoveredPhpProcessProbe::run($workspace,[$target],frameworkRoot:$workspace->root())->succeeded());
		return;
	}
	$t->throwsLike(
		static fn()=>CoveredPhpProcessProbe::run($workspace,[$target],frameworkRoot:$workspace->root()),
		RuntimeException::class,
		'did not return an exact coverage part'
	);
});

test('process fixtures make isolated constant and entrypoint contracts self describing',static function(Context $t): void {
	$workspace=$t->workspace('php-fixture-contract');
	$workspace->installCodeWorkerTooling();
	$fixture=$workspace->file('fixtures/constant_contract.php',<<<'PHP'
<?php
declare(strict_types=1);
define('FIXTURE_MODE',(string)($argv[1] ?? 'missing'));
echo json_encode(['mode'=>FIXTURE_MODE,'precision'=>(int)ini_get('precision')],JSON_THROW_ON_ERROR);
PHP);
	$ordinary=$t->processSucceeded($t->phpFixture($fixture,['ordinary'],$workspace->root()));
	$t->same('ordinary',$ordinary->json()['mode']);
	$covered=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		['covered'],
		working_directory:$workspace->root(),
		framework_root:$workspace->root(),
		php_ini:['precision'=>13],
	));
	$t->same('covered',$covered->json()['mode']);
	$t->same(13,$covered->json()['precision']);
	$failed_fixture=$workspace->file('fixtures/failure.php',"<?php fwrite(STDERR,'expected failure'); exit(7);");
	$t->same(7,$t->processFailed($t->phpFixture($failed_fixture),7)->exitCode());
	$t->throws(
		static fn()=>$t->phpFixture($workspace->path('fixtures/missing.php')),
		InvalidArgumentException::class,
	);
	$t->throws(
		static fn()=>$t->processSucceeded($t->phpFixture($failed_fixture)),
		\Dataphyre\Test\AssertionFailed::class,
	);
	$t->throws(
		static fn()=>$t->processFailed($ordinary),
		\Dataphyre\Test\AssertionFailed::class,
	);
});
