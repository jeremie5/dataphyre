<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\PathSemantics;
use Dataphyre\Test\PhpdbgLineMap;
use Dataphyre\Test\WorkerCoverage;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/WorkerCoverage.php';

suite('Exact worker coverage transports')
	->tag('testing', 'coverage', 'transport')
	->group('framework-coverage')
	->contract('testing.coverage.worker-transport', 1)
	->layer('unit')
	->risk('critical')
	->watches(
		'module:testing',
		'path:runtime/modules/testing/tooling/code_worker.php',
		'path:runtime/modules/testing/tooling/PhpdbgLineMap.php',
		'path:runtime/modules/testing/tooling/WorkerCoverage.php'
	)
	->through('included declarations', 'Xdebug maps', 'detached phpdbg evidence', 'fallback evidence')
	->isolation('process');

test('phpdbg maps retain only detached filename and line evidence', static function(Context $t): void {
	$raw=[
		'/framework/Flow.php'=>[8=>['opcode'=>'RETURN'], 2=>['opcode'=>'ASSIGN']],
		'/framework/Invalid.php'=>'not-a-line-map',
		42=>[1=>['opcode'=>'RETURN']],
		str_repeat('x',32769)=>[1=>['opcode'=>'RETURN']],
	];
	$detached=PhpdbgLineMap::detach($raw);
	$raw['/framework/Flow.php'][9]=['opcode'=>'THROW'];

	$t->same(['/framework/Flow.php'=>[8=>true, 2=>true]], $detached);
	$t->same([], PhpdbgLineMap::detach(false));
});

test('code worker file resolution preserves every native absolute path form', static function(Context $t): void {
	$t->same('/workspace/dataphyre/runtime/bootstrap.php', dataphyre_code_worker_resolve_file('/workspace/dataphyre/runtime/bootstrap.php'));
	$t->same('/workspace/dataphyre/runtime/bootstrap.php', dataphyre_code_worker_resolve_file('\\workspace\\dataphyre\\runtime\\bootstrap.php'));
	$t->same('C:/workspace/dataphyre/runtime/bootstrap.php', dataphyre_code_worker_resolve_file('C:\\workspace\\dataphyre\\runtime\\bootstrap.php'));
	$t->same('//server/share/runtime/bootstrap.php', dataphyre_code_worker_resolve_file('\\\\server\\share\\runtime\\bootstrap.php'));
	$t->same('', dataphyre_code_worker_resolve_file(''));
	$t->same(
		rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/testing/tooling/bootstrap.php',
		dataphyre_code_worker_resolve_file('dataphyre/runtime/modules/testing/tooling/bootstrap.php')
	);
	$t->same(
		rtrim((string)ROOTPATH['root'], '/\\').'/relative/test.php',
		dataphyre_code_worker_resolve_file('relative/test.php')
	);
})->contract('testing.worker.native-path-resolution', 1);

test('path semantics give the runner and every worker one portable resolution contract', static function(Context $t): void {
	$t->same('/workspace/runtime.php', PathSemantics::normalize('\\workspace\\runtime.php'));
	foreach(['/workspace/runtime.php', '\\workspace\\runtime.php', 'C:\\workspace\\runtime.php', '\\\\server\\share\\runtime.php'] as $absolute){
		$t->isTrue(PathSemantics::isAbsolute($absolute), $absolute);
	}
	foreach(['runtime.php', 'applications/catalog/runtime.php', 'common/dataphyre/runtime.php'] as $relative){
		$t->isFalse(PathSemantics::isAbsolute($relative), $relative);
	}
	$t->same('/workspace/runtime.php', PathSemantics::resolve('/ignored', '/workspace/runtime.php'));
	$t->same('C:/workspace/runtime.php', PathSemantics::resolve('/ignored', 'C:\\workspace\\runtime.php'));
	$t->same('/workspace/tests/runtime.php', PathSemantics::resolve('/workspace/tests', 'runtime.php'));
})->contract('testing.path-semantics', 1);

test('code worker coverage scope includes repository source and excludes harness artifacts', static function(Context $t): void {
	$root=str_replace('\\', '/', \Dataphyre\Test\dataphyre_path());
	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/bootstrap.php'));
	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/tooling/bootstrap.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/tooling/code_worker.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/tooling/WorkerCoverage.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/tooling/CoverageSubprocess.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/dpanel/kernel/dpanel.worker.php'));
	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/tooling/Runner.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/unit_tests/example.test.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/core/unit_tests/helper.php'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($root.'/runtime/modules/testing/unit_tests/example.test.php(12) : e'.'val()\'d code'));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope(str_replace('\\', '/', sys_get_temp_dir()).'/generated.php')); // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Coverage scope explicitly rejects generated code under the system directory."
})->tag('testing', 'coverage-scope', 'coverage')->group('framework-coverage');

test('explicit worker roots accept only their file or directory boundary', static function(Context $t): void {
	$workspace=$t->workspace('worker-explicit-coverage-roots');
	$directory=$workspace->path('applications/catalog/api/lib');
	$source=$workspace->file('applications/catalog/api/lib/Product.php', '<?php final class Product {}');
	$other=$workspace->file('applications/catalog/api/lib/Order.php', '<?php final class Order {}');
	$sibling=$workspace->file('applications/catalog/api/library/Escaped.php', '<?php final class Escaped {}');
	$unitTest=$workspace->file('applications/catalog/api/lib/unit_tests/Product.php', '<?php return true;');
	$testDefinition=$workspace->file('applications/catalog/api/lib/Product.test.php', '<?php return true;');
	$transport=$workspace->file('applications/catalog/api/lib/runtime/modules/testing/tooling/code_worker.php', '<?php return true;');

	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($source, [$directory]));
	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($other, [$directory]));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($sibling, [$directory]), 'Directory roots must use a path-segment boundary.');
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($unitTest, [$directory]));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($testDefinition, [$directory]));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($source."(8) : eval()'d code", [$directory]));
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($transport, [$directory]));
	$t->isTrue(dataphyre_code_worker_coverage_file_in_scope($source, [$source]), 'A single PHP file is a valid explicit source root.');
	$t->isFalse(dataphyre_code_worker_coverage_file_in_scope($other, [$source]), 'A file root must not widen to its parent directory.');

	$fallback=dataphyre_code_worker_coverage([], false, false, [
		'included_files'=>static fn(): array=>[$source,$sibling,$unitTest],
		'result_root'=>$workspace->root(),
		'coverage_roots'=>[$directory],
	]);
	$t->same(['applications/catalog/api/lib/Product.php'], $fallback['files']);

	$xdebug=dataphyre_code_worker_coverage([], true, false, [
		'included_files'=>static fn(): array=>[$source,$sibling],
		'result_root'=>$workspace->root(),
		'coverage_roots'=>[$directory],
		'xdebug_get'=>static fn(): array=>[$source=>[1=>1],$sibling=>[1=>1]],
		'xdebug_stop'=>static function(bool $cleanup): void {},
	]);
	$t->same(['applications/catalog/api/lib/Product.php'], array_keys($xdebug['files']));

	$phpdbg=dataphyre_code_worker_coverage([], false, true, [
		'included_files'=>static fn(): array=>[$source,$sibling],
		'result_root'=>$workspace->root(),
		'coverage_roots'=>[$directory],
		'phpdbg_get'=>static fn(): array=>[$source=>[1=>true],$sibling=>[1=>true]],
		'phpdbg_end'=>static fn(): array=>[$source=>[1=>true],$sibling=>[1=>true]],
	]);
	$t->same(['applications/catalog/api/lib/Product.php'], array_keys($phpdbg['files']));
})->contract('testing.worker.explicit-coverage-roots', 1);

test('shared worker transport preserves included declarations for every engine', static function(Context $t): void {
	$workspace=$t->workspace('worker-coverage-transport');
	$source=$workspace->file('runtime/modules/example/Framework/Flow.php', <<<'PHP'
<?php
{
$work=true;
} finally {
$cleanup=true;
PHP);
	$declaration=$workspace->file('runtime/modules/example/Framework/Declaration.php', '<?php interface Declaration {}');
	$testFile=$workspace->file('runtime/modules/example/unit_tests/ignored.php', '<?php return true;');
	$rootpath=['common_dataphyre'=>$workspace->root(), 'common_root'=>$workspace->root()];

	$includedCalls=0;$starts=[];$stops=[];
	$xdebug=WorkerCoverage::start($rootpath, true, [
		'included_files'=>static function()use(&$includedCalls,$source,$declaration,$testFile): array {
			return $includedCalls++===0 ? [] : [$source,$declaration,$testFile];
		},
		'xdebug_available'=>true,
		'xdebug_start'=>static function(int $flags)use(&$starts): void {$starts[]=$flags;},
		'xdebug_get'=>static fn(): array=>[$source=>[2=>1,3=>0,5=>-2],$testFile=>[1=>1],'invalid'=>'value'],
		'xdebug_stop'=>static function(bool $cleanup)use(&$stops): void {$stops[]=$cleanup;},
	]);
	$xdebugResult=$xdebug->finish();
	$t->same($xdebugResult, $xdebug->finish());
	$t->same('xdebug', $xdebugResult['engine']);
	$t->same(['runtime/modules/example/Framework/Declaration.php','runtime/modules/example/Framework/Flow.php'], $xdebugResult['included_files']);
	$t->same(2, $xdebugResult['files']['runtime/modules/example/Framework/Flow.php']['executable']);
	$t->same(1, $xdebugResult['files']['runtime/modules/example/Framework/Flow.php']['covered']);
	$t->count(1, $starts);
	$t->same([false], $stops);

	$includedCalls=0;
	$phpdbgCalls=[];
	$phpdbg=WorkerCoverage::start($rootpath, true, [
		'included_files'=>static function()use(&$includedCalls,$source,$declaration): array {
			return $includedCalls++===0 ? [] : [$source,$declaration];
		},
		'xdebug_available'=>false,
		'phpdbg_available'=>true,
		'phpdbg_start'=>static function(): void {},
		'phpdbg_get'=>static function()use(&$phpdbgCalls,$source): array {$phpdbgCalls[]='executable';return [$source=>[2=>[],3=>[],4=>[],5=>[]]];},
		'phpdbg_end'=>static function()use(&$phpdbgCalls,$source): array {$phpdbgCalls[]='oplog';return [$source=>[3=>1,4=>1]];},
	]);
	$phpdbgResult=$phpdbg->finish();
	$flow=$phpdbgResult['files']['runtime/modules/example/Framework/Flow.php'];
	$t->same('phpdbg', $phpdbgResult['engine']);
	$t->same(['runtime/modules/example/Framework/Declaration.php','runtime/modules/example/Framework/Flow.php'], $phpdbgResult['included_files']);
	$t->same(4, $flow['raw_executable']);
	$t->same(2, $flow['executable']);
	$t->same(1, $flow['covered']);
	$t->same(2, $flow['ignored']);
	$t->same('2,4', $flow['ignored_ranges']);
	$t->same(['brace-only'=>'2','finally-header'=>'4'], $flow['ignored_reasons']);
	$t->same(['executable','oplog'], $phpdbgCalls);

	$includedCalls=0;
	$fallback=WorkerCoverage::start($rootpath, true, [
		'included_files'=>static function()use(&$includedCalls,$declaration): array {return $includedCalls++===0 ? [] : [$declaration];},
		'xdebug_available'=>false,
		'phpdbg_available'=>false,
	]);
	$t->same(['engine'=>'included_files','files'=>['runtime/modules/example/Framework/Declaration.php']], $fallback->finish());
	$t->same(null, WorkerCoverage::start($rootpath, false, ['included_files'=>static fn(): array=>[]])->finish());
});

test('code worker transport exposes the same exact payload contract through injectable readers', static function(Context $t): void {
	$workspace=$t->workspace('code-worker-coverage');
	$source=$workspace->file('runtime/modules/example/Framework/Flow.php', <<<'PHP'
<?php
{
$work=true;
} finally {
$cleanup=true;
PHP);
	$declaration=$workspace->file('runtime/modules/example/Framework/Declaration.php', '<?php interface Declaration {}');
	$root=str_replace('\\','/',$workspace->root()).'/';
	$scope=static fn(string $file): bool=>str_starts_with(str_replace('\\','/',$file),$root) && !str_contains(str_replace('\\','/',$file),'/unit_tests/');
	$common=[
		'included_files'=>static fn(): array=>[$source,$declaration],
		'result_root'=>$workspace->root(),
		'file_in_scope'=>$scope,
	];

	$stops=[];
	$xdebug=dataphyre_code_worker_coverage([], true, false, $common+[
		'xdebug_get'=>static fn(): array=>[$source=>[2=>1,3=>0,5=>-2]],
		'xdebug_stop'=>static function(bool $cleanup)use(&$stops): void {$stops[]=$cleanup;},
	]);
	$t->same('xdebug', $xdebug['engine']);
	$t->same(['runtime/modules/example/Framework/Declaration.php','runtime/modules/example/Framework/Flow.php'], $xdebug['included_files']);
	$t->same(2, $xdebug['files']['runtime/modules/example/Framework/Flow.php']['executable']);
	$t->same([false], $stops);

	$phpdbgCalls=[];
	$phpdbg=dataphyre_code_worker_coverage([], false, true, $common+[
		'phpdbg_get'=>static function()use(&$phpdbgCalls,$source): array {$phpdbgCalls[]='executable';return [$source=>[2=>[],3=>[],4=>[],5=>[]]];},
		'phpdbg_end'=>static function()use(&$phpdbgCalls,$source): array {$phpdbgCalls[]='oplog';return [$source=>[3=>1,4=>1]];},
	]);
	$flow=$phpdbg['files']['runtime/modules/example/Framework/Flow.php'];
	$t->same('phpdbg', $phpdbg['engine']);
	$t->same(['runtime/modules/example/Framework/Declaration.php','runtime/modules/example/Framework/Flow.php'], $phpdbg['included_files']);
	$t->same(4, $flow['raw_executable']);
	$t->same(2, $flow['executable']);
	$t->same(1, $flow['covered']);
	$t->same(['brace-only'=>'2','finally-header'=>'4'], $flow['ignored_reasons']);
	$t->same(['executable','oplog'], $phpdbgCalls);

	$fallback=dataphyre_code_worker_coverage([], false, false, $common);
	$t->same('included_files', $fallback['engine']);
	$t->same(['runtime/modules/example/Framework/Declaration.php','runtime/modules/example/Framework/Flow.php'], $fallback['files']);
});
