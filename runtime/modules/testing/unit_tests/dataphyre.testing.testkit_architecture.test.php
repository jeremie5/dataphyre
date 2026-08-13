<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\AbstractContext;
use Dataphyre\Test\AssertsDomains;
use Dataphyre\Test\AssertsStructures;
use Dataphyre\Test\AssertsValues;
use Dataphyre\Test\BrowserProbe;
use Dataphyre\Test\Context;
use Dataphyre\Test\CreatesTestDoubles;
use Dataphyre\Test\Expectation;
use Dataphyre\Test\FakeHttp;
use Dataphyre\Test\InteractsWithExtensions;
use Dataphyre\Test\ManagesRuntimeState;
use Dataphyre\Test\ManagesTemporaryFiles;
use Dataphyre\Test\MatchesSnapshots;
use Dataphyre\Test\MeasuresQuality;
use Dataphyre\Test\ReadsStructuredData;
use Dataphyre\Test\RunsProcesses;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\TestKitAutoloader;
use Dataphyre\Test\Contracts\AssertionContext;
use Dataphyre\Test\Contracts\DoubleContext;
use Dataphyre\Test\Contracts\ExtensibleContext;
use Dataphyre\Test\Contracts\ProcessContext;
use Dataphyre\Test\Contracts\RuntimeContext;
use Dataphyre\Test\Contracts\TestContext;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('TestKit source architecture')
	->tag('testing', 'testkit', 'architecture')
	->group('framework-coverage')
	->contract('testing.testkit.source-architecture', 1)
	->layer('architecture')
	->risk('critical')
	->watches(
		'module:testing',
		'path:runtime/modules/testing/tooling/bootstrap.php',
		'path:runtime/modules/testing/tooling/TestKit/**'
	)
	->through('canonical bootstrap', 'autoload inventory', 'capability contracts', 'source-size guard')
	->isolation('process')
	->maxMillis(15000);

test('the canonical bootstrap owns no compatibility monolith', static function(Context $t): void {
	$root=dataphyre_path();
	$tooling=$root.'/runtime/modules/testing/tooling';
	$bootstrap=$tooling.'/bootstrap.php';

	$t->isTrue(is_file($bootstrap));
	$t->isFalse(is_file($tooling.'/TestKit.php'));
	$t->isFalse(is_file($root.'/testing/TestKit.php'));
	$t->isFalse(is_file($root.'/testing/code_worker.php'));
	$t->same($tooling.'/TestKit', TestKitAutoloader::sourceRoot());
	$t->same($tooling.'/TestKit/Context.php', TestKitAutoloader::path(Context::class));
	$t->same(null, TestKitAutoloader::path('Vendor\\ForeignContext'));
	$t->same(null, TestKitAutoloader::path('Dataphyre\\Test\\Invalid-Type'));
	$t->same(null, TestKitAutoloader::classForPath($tooling.'/TestKit/functions.php'));
	$t->same(null, TestKitAutoloader::classForPath($tooling.'/TestKit/Context.txt'));
	$t->same(null, TestKitAutoloader::classForPath($root.'/outside/Context.php'));
	TestKitAutoloader::load('Vendor\\ForeignContext');
	TestKitAutoloader::load('Dataphyre\\Test\\MissingTestKitType');

	$t->throws(static fn()=>TestKitAutoloader::register($tooling.'/missing'), InvalidArgumentException::class);
	$t->throws(static fn()=>TestKitAutoloader::sourceFiles($tooling.'/missing'), InvalidArgumentException::class);
	$t->throws(static fn()=>TestKitAutoloader::register($t->tempDirectory('testkit-second-root')), LogicException::class);
	TestKitAutoloader::register($tooling.'/TestKit');
});

test('code-worker fixture installation fails before publishing an incomplete TestKit', static function(Context $t): void {
	$tooling=dirname(TestKitAutoloader::sourceRoot());
	$incomplete=$t->workspace('testkit-incomplete-source');
	foreach(['bootstrap.php','PhpRuntime.php','TypeInventory.php','PathSemantics.php','CoverageLineNormalizer.php','PhpdbgLineMap.php','WorkerCoverage.php','CoverageSubprocess.php','code_worker.php'] as $file){
		$incomplete->copy($tooling.'/'.$file, $file);
	}
	$target=$t->workspace('testkit-incomplete-target');
	$t->throwsLike(
		static fn()=>$target->installCodeWorkerTooling($incomplete->root()),
		RuntimeException::class,
		'TestKit source directory is unavailable',
	);
});

test('every TestKit type is one lazily loadable source beneath a hard size ceiling', static function(Context $t): void {
	$sourceRoot=TestKitAutoloader::sourceRoot();
	$files=TestKitAutoloader::sourceFiles();
	$t->same($files, TestKitAutoloader::sourceFiles($sourceRoot));
	$t->greaterThan(80, count($files));
	$functionFiles=[];
	foreach($files as $file){
		$relative=substr($file, strlen($sourceRoot)+1);
		$lines=file($file, FILE_IGNORE_NEW_LINES);
		$t->isTrue(is_array($lines), $relative);
		$t->lessThanOrEqual(750, is_array($lines) ? count($lines) : PHP_INT_MAX, $relative.' exceeds the TestKit source-size ceiling.');
		$class=TestKitAutoloader::classForPath($file);
		if($class===null){
			$functionFiles[]=$relative;
			continue;
		}
		$t->same($file, TestKitAutoloader::path($class), $relative);
		$t->isTrue(
			class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
			$relative.' must declare its path-derived type.'
		);
	}
	$t->same(['functions.php'], $functionFiles);
});

test('Context extends lifecycle state and implements named capabilities directly', static function(Context $t): void {
	$inventory=$t->inventory(Context::class);
	$t->same(AbstractContext::class, $inventory->parent());
	$t->containsAll([
		AssertionContext::class,
		DoubleContext::class,
		ExtensibleContext::class,
		ProcessContext::class,
		RuntimeContext::class,
		TestContext::class,
	], $inventory->interfaces());
	$t->same([
		AssertsDomains::class,
		AssertsStructures::class,
		AssertsValues::class,
		CreatesTestDoubles::class,
		InteractsWithExtensions::class,
		ManagesRuntimeState::class,
		ManagesTemporaryFiles::class,
		MatchesSnapshots::class,
		MeasuresQuality::class,
		ReadsStructuredData::class,
		RunsProcesses::class,
	], $inventory->traits());
	$t->same(TestKitAutoloader::path(Context::class), $inventory->sourceFile());
	$t->same(TestKitAutoloader::path(AssertsValues::class), str_replace('\\', '/', (string)$inventory->method('same')->getFileName()));
	$t->same(TestKitAutoloader::path(RunsProcesses::class), str_replace('\\', '/', (string)$inventory->method('process')->getFileName()));
	$t->same(TestKitAutoloader::path(CreatesTestDoubles::class), str_replace('\\', '/', (string)$inventory->method('fakeHttp')->getFileName()));
	$t->same(TestKitAutoloader::path(MatchesSnapshots::class), str_replace('\\', '/', (string)$inventory->method('snapshot')->getFileName()));
	$t->same([
		'public'=>false,
		'protected'=>true,
		'private'=>false,
		'static'=>false,
		'parameters'=>0,
		'return_type'=>'void',
	], $t->inventory(AbstractContext::class)->methodShape('recordAssertion'));
});

test('TestKit collaborators depend on the narrowest context contract they consume', static function(Context $t): void {
	$expectationContext=$t->inventory(Expectation::class)->constructor()?->getParameters()[0]->getType();
	$workspaceContext=$t->inventory(TempWorkspace::class)->constructor()?->getParameters()[0]->getType();
	$fakeAssertionContext=$t->inventory(FakeHttp::class)->method('assertRequested')->getParameters()[0]->getType();
	$browserContext=$t->inventory(BrowserProbe::class)->method('assert')->getParameters()[0]->getType();

	$t->same(AssertionContext::class, (string)$expectationContext);
	$t->same(RuntimeContext::class, (string)$workspaceContext);
	$t->same(AssertionContext::class, (string)$fakeAssertionContext);
	$t->same(TestContext::class, (string)$browserContext);
	$t->same(null, $t->inventory(TestContext::class)->parent());
	$t->same(null, $t->inventory(stdClass::class)->sourceFile());
});

test('autoload registration is deterministic before the normal testing bootstrap exists', static function(Context $t): void {
	$workspace=$t->workspace('testkit-autoloader-probe');
	$sourceRoot=$workspace->directory('autoload-source');
	$workspace->copy(TestKitAutoloader::path(Context::class), 'autoload-source/Context.php');
	$fixture=__DIR__.'/fixtures/testkit_autoloader_probe.php';
	$result=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		[dataphyre_path().'/runtime/modules/testing/tooling/TestKit/TestKitAutoloader.php', $sourceRoot],
		working_directory:$workspace->root(),
		framework_root:dataphyre_path(),
	));
	$payload=$result->json();

	$t->same(null, $payload['before']);
	$t->contains('has not been registered', $payload['unregistered']);
	$t->same($sourceRoot, $payload['root']);
	$t->same([$sourceRoot.'/Context.php'], $payload['files']);
	$t->same($sourceRoot.'/Context.php', $payload['context_path']);
	$t->same(Context::class, $payload['context_class']);
});
