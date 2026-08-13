<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Arbitrary;
use Dataphyre\Test\AssertionFailed;
use Dataphyre\Test\CaseDefinition;
use Dataphyre\Test\Context;
use Dataphyre\Test\DeterministicRandom;
use Dataphyre\Test\Expectation;
use Dataphyre\Test\FailureCorpus;
use Dataphyre\Test\GeneratedCases;
use Dataphyre\Test\Generators;
use Dataphyre\Test\Registry;
use Dataphyre\Test\RootpathSandbox;
use Dataphyre\Test\SuiteDefinition;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestMemoryLimit;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('TestKit semantic contracts')
	->tag('testkit', 'semantic-contracts')
	->group('framework')
	->layer(TestLayer::Unit)
	->risk(TestRisk::High)
	->isolation(TestIsolation::CaseScope)
	->requiresAssertions();

/** Runs nested registry contracts without changing this file's outer cases. */
function dp_testkit_semantics_isolated(Context $t, callable $callback): mixed {
	$registry=$t->nonPublic(Registry::class);
	$properties=['cases','datasets','fixtures','before_all','before_all_ran','before_each','after_each','after_all','suite'];
	$snapshot=[];
	foreach($properties as $property){
		$snapshot[$property]=$registry->readProperty($property);
	}
	Registry::reset();
	try{
		return $callback();
	}finally{
		foreach($properties as $property){
			$registry->writeProperty($property, $snapshot[$property]);
		}
	}
}

test('case metadata describes contracts risk dependencies and worker lifecycle', static function(Context $t): void {
	$case=(new CaseDefinition('stale identity cannot replace a pretty route', static function(): void {}, 'C:/repo/runtime/modules/panel/unit_tests/routes.test.php', 40))
		->id('panel.route.pretty-precedence')
		->contract('panel.route-precedence', 2)
		->layer(TestLayer::Contract)
		->risk('critical')
		->watches('symbol:PanelRouteParser::infer', 'route:panel.bulk-transition', 'symbol:PanelRouteParser::infer')
		->through('renderer', 'route', 'request', 'dispatcher', 'browser', 'route')
		->sandboxesRootpath('dataphyre', 'cache', 'dataphyre')
		->memoryLimit('512m')
		->coverageMemoryLimit('1g')
		->isolation(TestIsolation::File)
		->repeat(3)
		->strictIssues()
		->forbidsOutput()
		->requiresAssertions();

	$t->same('panel.route.pretty-precedence', $case->stableIdValue());
	$t->same(['name'=>'panel.route-precedence','version'=>'2'], $case->contractValue());
	$t->same(TestLayer::Contract, $case->layerValue());
	$t->same(TestRisk::Critical, $case->riskValue());
	$t->same(['symbol:PanelRouteParser::infer','route:panel.bulk-transition'], $case->watchTargets());
	$t->same(['renderer','route','request','dispatcher','browser'], $case->boundaries());
	$t->same(['dataphyre','cache'], $case->rootpathSandboxes());
	$t->same('512M', $case->memoryLimitValue());
	$t->same('1G', $case->coverageMemoryLimitValue());
	$t->same(512 * 1024 * 1024, TestMemoryLimit::bytes($case->memoryLimitValue()));
	$t->same(4 * 1024, TestMemoryLimit::bytes('4k'));
	$t->same(2 * 1024 * 1024 * 1024, TestMemoryLimit::bytes('2G'));
	$t->same(524288, TestMemoryLimit::bytes('524288'));
	$t->same(TestIsolation::File, $case->isolationValue());
	$t->isTrue($case->hasExplicitIsolation());
	$t->same(3, $case->repeatValue());
	$t->hasPathValues([
		'contract.name'=>'panel.route-precedence',
		'contract.version'=>'2',
		'layer'=>'contract',
		'risk'=>'critical',
		'rootpath_sandboxes.0'=>'dataphyre',
		'rootpath_sandboxes.1'=>'cache',
		'memory_limit'=>'512M',
		'coverage_memory_limit'=>'1G',
		'isolation'=>'file',
		'issue_policy'=>'fail',
		'output_policy'=>'forbid',
		'assertion_policy'=>'require',
	], $case->metadata());
	$derived_one=(new CaseDefinition('derived stability', static function(): void {}, 'C:/one/runtime/modules/panel/unit_tests/first.test.php', 10))->suite('routes');
	$derived_two=(new CaseDefinition('derived stability', static function(): void {}, 'D:/two/runtime/modules/panel/unit_tests/moved.test.php', 900))->suite('routes');
	$t->same($derived_one->stableIdValue(), $derived_two->stableIdValue());

	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->id('contains spaces'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->contract('', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->layer('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->risk('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->isolation('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->repeat(0), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->memoryLimit('0M'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->coverageMemoryLimit('0M'), InvalidArgumentException::class);
	$t->throws(static fn()=>TestMemoryLimit::normalize('unlimited'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->allowsIssues('NOT_AN_ERROR'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->allowsIssues(12345), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->allowsOutput(' '), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->allowsNoAssertions(''), InvalidArgumentException::class);
	$t->throws(static fn()=>(new CaseDefinition('bad', static function(): void {}))->sandboxesRootpath('common_dataphyre'), InvalidArgumentException::class);
	$t->throws(static fn()=>RootpathSandbox::normalizeDeclaredKeys(['bad-key']), InvalidArgumentException::class);
	$t->throws(static fn()=>RootpathSandbox::normalizeDeclaredKeys([42]), InvalidArgumentException::class);
})->contract('testing.case-metadata', 1)->watches('type:CaseDefinition');

test('suite metadata becomes readable defaults without hiding case intent', static function(Context $t): void {
	$case=new CaseDefinition('suite-owned behavior', static function(): void {}, '/repo/runtime/modules/testing/unit_tests/suite.test.php');
	(new SuiteDefinition('semantic defaults'))
		->contract('testing.semantic-defaults', '2026-07')
		->layer('integration')
		->risk(TestRisk::Critical)
		->watches('file:runtime/bootstrap.php')
		->through('bootstrap', 'module-registry')
		->sandboxesRootpath('dataphyre')
		->memoryLimit('384M')
		->coverageMemoryLimit('768M')
		->isolation('file')
		->repeat(2)
		->allowsIssues(E_USER_DEPRECATED)
		->allowsOutput('the command renderer is the asserted surface')
		->allowsNoAssertions('process exit is the smoke contract')
		->applyTo($case);

	$t->same('semantic defaults', $case->suiteName());
	$t->same(['name'=>'testing.semantic-defaults','version'=>'2026-07'], $case->contractValue());
	$t->same(TestLayer::Integration, $case->layerValue());
	$t->same(TestRisk::Critical, $case->riskValue());
	$t->same(['file:runtime/bootstrap.php'], $case->watchTargets());
	$t->same(['bootstrap','module-registry'], $case->boundaries());
	$t->same(['dataphyre'], $case->rootpathSandboxes());
	$t->same('384M', $case->memoryLimitValue());
	$t->same('768M', $case->coverageMemoryLimitValue());
	$t->same(TestIsolation::File, $case->isolationValue());
	$t->isTrue($case->hasExplicitIsolation());
	$t->same(2, $case->repeatValue());
	$t->same('allow', $case->issuePolicy()->value);
	$t->same([['code'=>E_USER_DEPRECATED,'name'=>'E_USER_DEPRECATED']], $case->declaredIssues());
	$t->same('allow', $case->outputPolicy()->value);
	$t->same('allow_none', $case->assertionPolicy()->value);

	$strict_case=new CaseDefinition('strict suite behavior', static function(): void {});
	(new SuiteDefinition('strict suite defaults'))
		->strictIssues()
		->forbidsOutput()
		->applyTo($strict_case);
	$t->same('fail', $strict_case->issuePolicy()->value);
	$t->same([], $strict_case->declaredIssues());
	$t->same('forbid', $strict_case->outputPolicy()->value);

	$expected_case=new CaseDefinition('expected suite behavior', static function(): void {});
	(new SuiteDefinition('expected suite defaults'))
		->expectsIssues(E_USER_WARNING)
		->expectsOutput('')
		->applyTo($expected_case);
	$t->same('expect', $expected_case->issuePolicy()->value);
	$t->same([['code'=>E_USER_WARNING,'name'=>'E_USER_WARNING']], $expected_case->declaredIssues());
	$t->same('expect', $expected_case->outputPolicy()->value);
	$t->same('expected output contract', $expected_case->outputReason());

	$t->throws(static fn()=>(new SuiteDefinition())->repeat(0), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->contract('', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->layer('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->risk('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->isolation('unknown'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->memoryLimit('-1'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->coverageMemoryLimit('-1'), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->allowsOutput(''), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->allowsNoAssertions(''), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SuiteDefinition())->sandboxesRootpath('root'), InvalidArgumentException::class);
	$t->isFalse((new CaseDefinition('implicit isolation', static function(): void {}))->hasExplicitIsolation());
})->contract('testing.suite-metadata', 1);

test('registry expands datasets and repeats into stable independently addressable cases', static function(Context $t): void {
	dp_testkit_semantics_isolated($t, static function()use($t): void {
		Registry::test('route contract', static function(Context $context, string $operation): void {
			$context->notEmpty($operation);
			$context->notEmpty($context->stableId());
			$context->same('panel.route-roundtrip', $context->contract());
			$context->same('3', $context->contractVersion());
		})
			->id('panel.route-roundtrip')
			->contract('panel.route-roundtrip', 3)
			->layer('contract')
			->risk('critical')
			->watches('route:bulk-update', 'route:bulk-transition')
			->through('renderer', 'parser', 'dispatcher')
			->sandboxesRootpath('dataphyre')
			->memoryLimit('320M')
			->coverageMemoryLimit('640M')
			->isolation('file')
			->with(['update'=>['bulk_update'], 'transition'=>['bulk_transition']])
			->repeat(2)
			->requiresAssertions();

		$summaries=Registry::caseSummaries('/repo/runtime/modules/panel/unit_tests/routes.test.php');
		$t->count(4, $summaries);
		$t->count(4, array_unique(array_column($summaries, 'stable_id')));
		$t->same([1,2,1,2], array_column($summaries, 'repeat_index'));
		$t->same(['update','update','transition','transition'], array_column($summaries, 'dataset'));
		$t->same('panel.route-roundtrip.dataset.'.substr(hash('sha256', 'update'), 0, 12).'.repeat.1', $summaries[0]['stable_id']);
		$t->hasPathValues([
			'contract.name'=>'panel.route-roundtrip',
			'contract.version'=>'3',
			'layer'=>'contract',
			'risk'=>'critical',
			'through.0'=>'renderer',
			'through.2'=>'dispatcher',
			'rootpath_sandboxes.0'=>'dataphyre',
			'memory_limit'=>'320M',
			'coverage_memory_limit'=>'640M',
			'isolation'=>'file',
			'lifecycle.rootpath_sandboxes.0'=>'dataphyre',
			'lifecycle.memory_limit'=>'320M',
			'lifecycle.coverage_memory_limit'=>'640M',
			'lifecycle.repeat_total'=>2,
		], $summaries[0]);
		$result=Registry::run(0, '/repo/runtime/modules/panel/unit_tests/routes.test.php');
		$t->isTrue($result['passed']);
		$t->same($summaries[0]['stable_id'], $result['stable_id']);
		$t->same(4, $result['assertions']);
	});
})->contract('testing.stable-expansion', 1);

test('strict assertion output and PHP issue declarations are executable contracts', static function(Context $t): void {
	dp_testkit_semantics_isolated($t, static function()use($t): void {
		Registry::test('requires assertion', static function(): void {})->requiresAssertions();
		Registry::test('allows no assertion', static function(): void {})->allowsNoAssertions('subprocess exit is the contract');
		Registry::test('forbids output', static function(): void { echo 'leak'; })->forbidsOutput();
		Registry::test('expects missing output', static function(): void {})->expectsOutput('CLI usage text');
		Registry::test('expects present output', static function(): void { echo 'usage'; })->expectsOutput('CLI usage text');
		Registry::test('strict issue failure', static function(): void { trigger_error('drift', E_USER_WARNING); })->strictIssues();
		Registry::test('allowed issue', static function(): void { trigger_error('legacy', E_USER_DEPRECATED); })->allowsIssues('E_USER_DEPRECATED');
		Registry::test('expected issue', static function(): void { trigger_error('migration', E_USER_NOTICE); })->expectsIssues(E_USER_NOTICE);
		Registry::test('missing expected issue', static function(): void {})->expectsIssues(E_USER_NOTICE);
		Registry::test('missing any expected issue', static function(): void {})->expectsIssues();
		Registry::test('mismatched allowed issue', static function(): void { trigger_error('unexpected warning', E_USER_WARNING); })->allowsIssues(E_USER_NOTICE);
		Registry::test('any observed issue', static function(): void { trigger_error('observed warning', E_USER_WARNING); })->expectsIssues(E_ALL);
		Registry::test('unknown issue labels remain diagnostic', static function(): void {
			$registry_handler=set_error_handler(static fn(): bool=>false);
			restore_error_handler();
			if(is_callable($registry_handler)){
				$registry_handler(12345, 'custom issue', __FILE__, __LINE__);
			}
		})->strictIssues();
		Registry::test('disrupted output buffer', static function(Context $context): void {
			$context->isTrue(true);
			ob_end_clean();
		})->forbidsOutput();
		Registry::test('nested output buffers are contained', static function(Context $context): void {
			$context->isTrue(true);
			ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Nested buffer containment is the Registry lifecycle contract under test."
			ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Nested buffer containment is the Registry lifecycle contract under test."
		})->forbidsOutput();
		Registry::test('skipped output contract', static function(): void {})->expectsOutput()->skip('platform unavailable');
		Registry::test('skipped issue contract', static function(): void {})->expectsIssues(E_USER_NOTICE)->skip('platform unavailable');

		$by_name=[];
		ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Registry output policy emission is the native buffer contract under test."
		foreach(Registry::caseSummaries('/semantic-policy.php') as $summary){
			$by_name[$summary['base_name']]=Registry::run($summary['index'], '/semantic-policy.php');
		}
		$emitted=(string)ob_get_clean();

		$t->isFalse($by_name['requires assertion']['passed']);
		$t->contains('requires at least one assertion', $by_name['requires assertion']['message']);
		$t->isTrue($by_name['allows no assertion']['passed']);
		$t->isFalse($by_name['forbids output']['passed']);
		$t->same('leak', $by_name['forbids output']['details']['policy_violations'][0]['captured_output']);
		$t->isFalse($by_name['expects missing output']['passed']);
		$t->isTrue($by_name['expects present output']['passed']);
		$t->same('usage', $emitted);
		$t->isFalse($by_name['strict issue failure']['passed']);
		$t->same('E_USER_WARNING', $by_name['strict issue failure']['details']['policy_violations'][0]['unexpected'][0]['name']);
		$t->isTrue($by_name['allowed issue']['passed']);
		$t->isTrue($by_name['expected issue']['passed']);
		$t->isFalse($by_name['missing expected issue']['passed']);
		$t->same('E_USER_NOTICE', $by_name['missing expected issue']['details']['policy_violations'][0]['missing'][0]['name']);
		$t->isFalse($by_name['missing any expected issue']['passed']);
		$t->same('any PHP issue', $by_name['missing any expected issue']['details']['policy_violations'][0]['missing'][0]['name']);
		$t->isFalse($by_name['mismatched allowed issue']['passed']);
		$t->same('E_USER_WARNING', $by_name['mismatched allowed issue']['details']['policy_violations'][0]['unexpected'][0]['name']);
		$t->isTrue($by_name['any observed issue']['passed']);
		$t->isFalse($by_name['unknown issue labels remain diagnostic']['passed']);
		$t->same('E_12345', $by_name['unknown issue labels remain diagnostic']['details']['policy_violations'][0]['unexpected'][0]['name']);
		$t->isFalse($by_name['disrupted output buffer']['passed']);
		$t->contains('output-buffer boundary', $by_name['disrupted output buffer']['message']);
		$t->isTrue($by_name['nested output buffers are contained']['passed']);
		$t->isTrue($by_name['skipped output contract']['passed']);
		$t->isTrue($by_name['skipped output contract']['skipped']);
		$t->isTrue($by_name['skipped issue contract']['passed']);
		$t->isTrue($by_name['skipped issue contract']['skipped']);
	});
})->contract('testing.strict-policies', 1);

test('file-isolated registry runs honor one real file lifecycle', static function(Context $t): void {
	dp_testkit_semantics_isolated($t, static function()use($t): void {
		$events=[];
		Registry::beforeAll(static function()use(&$events): void { $events[]='before-all'; });
		Registry::beforeEach(static function()use(&$events): void { $events[]='before-each'; });
		Registry::afterEach(static function()use(&$events): void { $events[]='after-each'; });
		Registry::afterAll(static function()use(&$events): void { $events[]='after-all'; });
		Registry::test('first', static function(Context $context)use(&$events): void { $events[]='first'; $context->isTrue(true); });
		Registry::test('second', static function(Context $context)use(&$events): void { $events[]='second'; $context->isTrue(true); });

		$t->same([], Registry::runMany([], '/file-lifecycle.php'));
		$t->same([], $events);
		$results=Registry::runMany([0,1], '/file-lifecycle.php');
		$t->same([true,true], array_column($results, 'passed'));
		$t->same([
			'before-all','before-each','first','after-each',
			'before-each','second','after-each','after-all',
		], $events);

		Registry::reset();
		Registry::afterAll(static function(): void { throw new RuntimeException('file cleanup failed'); });
		Registry::test('body', static function(Context $context): void { $context->isTrue(true); });
		$failed=Registry::runMany([0], '/file-lifecycle.php');
		$t->isFalse($failed[0]['passed']);
		$t->same('after_all', $failed[0]['details']['teardown']['phase']);
		$t->contains('file cleanup failed', $failed[0]['message']);

		Registry::reset();
		Registry::afterAll(static function(): void { echo 'teardown leak'; trigger_error('teardown warning', E_USER_WARNING); });
		Registry::test('strict file body', static function(Context $context): void { $context->isTrue(true); })
			->forbidsOutput()
			->strictIssues();
		ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Strict teardown output capture is the Registry lifecycle contract under test."
		$strict=Registry::runMany([0], '/file-lifecycle.php');
		$t->same('', (string)ob_get_clean());
		$t->isFalse($strict[0]['passed']);
		$t->same(['output','issues'], array_column($strict[0]['details']['policy_violations'], 'policy'));
		$t->same('after_all', $strict[0]['details']['policy_violations'][0]['phase']);

		Registry::reset();
		Registry::afterAll(static function(): void { echo 'allowed teardown output'; });
		Registry::test('allowed file output', static function(Context $context): void { $context->isTrue(true); })
			->allowsOutput('the teardown transcript is asserted');
		ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Allowed teardown output capture is the Registry lifecycle contract under test."
		$allowed=Registry::runMany([0], '/file-lifecycle.php');
		$t->same('allowed teardown output', (string)ob_get_clean());
		$t->isTrue($allowed[0]['passed']);

		Registry::reset();
		Registry::afterAll(static function(): void { trigger_error('unexpected teardown warning', E_USER_WARNING); });
		Registry::test('mismatched file issue', static function(Context $context): void { $context->isTrue(true); })
			->allowsIssues(E_USER_NOTICE);
		$mismatched=Registry::runMany([0], '/file-lifecycle.php');
		$t->isFalse($mismatched[0]['passed']);
		$t->same('E_USER_WARNING', $mismatched[0]['details']['policy_violations'][0]['unexpected'][0]['name']);

		Registry::reset();
		Registry::afterAll(static function(): void { ob_end_clean(); });
		Registry::test('disrupted file output', static function(Context $context): void { $context->isTrue(true); })
			->forbidsOutput();
		$disrupted=Registry::runMany([0], '/file-lifecycle.php');
		$t->isFalse($disrupted[0]['passed']);
		$t->contains('output-buffer boundary', $disrupted[0]['message']);

		Registry::reset();
		Registry::afterAll(static function(): void { ob_start(); ob_start(); }); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Nested teardown buffer containment is the Registry lifecycle contract under test."
		Registry::test('nested file output buffers', static function(Context $context): void { $context->isTrue(true); })
			->forbidsOutput();
		$contained=Registry::runMany([0], '/file-lifecycle.php');
		$t->isTrue($contained[0]['passed']);
	});
})->contract('testing.file-lifecycle', 1)->isolation('process');

test('module kits and custom expectations extend TestKit without editing its core', static function(Context $t): void {
	Context::forgetExtension('semantic-kit');
	Context::extend('semantic-kit', static function(Context $context, string $name): object {
		return (object)['context'=>$context, 'name'=>$name];
	});
	$t->isTrue(Context::hasExtension('semantic-kit'));
	$kit=$t->extension('semantic-kit', stdClass::class);
	$t->same($kit, $t->extension('semantic-kit'));
	$t->same($kit, $t->{'semantic-kit'}());
	$t->same($t, $kit->context);
	$t->same('semantic-kit', $kit->name);
	$t->throws(static fn()=>$t->extension('semantic-kit', ArrayObject::class), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->extension('missing'), OutOfBoundsException::class);
	$t->throws(static fn()=>Context::extend('not valid', static fn()=>null), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->unknownExtension(), BadMethodCallException::class);

	Expectation::forgetExtension('toBeRouteOperation');
	Expectation::extend('toBeRouteOperation', static fn(mixed $actual, string $expected): bool=>$actual===$expected && str_contains((string)$actual, '_'));
	$t->isTrue(Expectation::hasExtension('toBeRouteOperation'));
	$t->expect('bulk_update')->toBeRouteOperation('bulk_update');
	$t->expect('index')->not()->toBeRouteOperation('bulk_update');
	$t->throws(static fn()=>$t->expect('index')->toBeRouteOperation('bulk_update'), AssertionFailed::class);
	$t->throws(static fn()=>Expectation::extend('toBe', static fn(): bool=>true), InvalidArgumentException::class);
	$t->throws(static fn()=>Expectation::extend('not valid', static fn(): bool=>true), InvalidArgumentException::class);
	Expectation::extend('returnsWrongType', static fn(): string=>'yes');
	$t->throws(static fn()=>$t->expect('x')->returnsWrongType(), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->expect('x')->missingExpectation(), BadMethodCallException::class);
})->contract('testing.extensions', 1)->watches('extension:panel');

test('deterministic random streams and legacy generators never perturb global randomness', static function(Context $t): void {
	$first=new DeterministicRandom('route-contract');
	$second=new DeterministicRandom('route-contract');
	$t->same('route-contract', $first->seed());
	$t->same($first->bytes(40), $second->bytes(40));
	$t->same($first->fork('operation')->int(1, 1000), $second->fork('operation')->int(1, 1000));
	$t->same(5, $first->int(5, 5));
	$full_range=$first->fork('full-range')->int(PHP_INT_MIN, PHP_INT_MAX);
	$t->isTrue($full_range>=PHP_INT_MIN && $full_range<=PHP_INT_MAX);
	$unsigned_range=$first->fork('unsigned-range')->int(0, PHP_INT_MAX);
	$t->isTrue($unsigned_range>=0 && $unsigned_range<=PHP_INT_MAX);
	$wide_range=$first->fork('wide-range')->int(PHP_INT_MIN, 0);
	$t->isTrue($wide_range>=PHP_INT_MIN && $wide_range<=0);
	$t->isTrue(is_bool($first->bool()));
	$t->same(null, $first->pick([]));
	$t->throws(static fn()=>(new DeterministicRandom(1))->bytes(-1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new DeterministicRandom(1))->int(2, 1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new DeterministicRandom(1))->string(1, 1, ''), InvalidArgumentException::class);

	mt_srand(20260714);
	$expected_first=mt_rand();
	$expected_second=mt_rand();
	mt_srand(20260714);
	$actual_first=mt_rand();
	$generated=iterator_to_array(Generators::integers(1, 100, 8, 42));
	iterator_to_array(Generators::strings(8, 1, 8, 42));
	iterator_to_array(Generators::oneOf(['a','b'], 8, 42));
	$actual_second=mt_rand();
	$t->same($expected_first, $actual_first);
	$t->same($expected_second, $actual_second);
	$t->same($generated, iterator_to_array(Generators::integers(1, 100, 8, 42)));
})->contract('testing.independent-randomness', 1);

test('composable arbitraries preserve field independence and deterministic replay', static function(Context $t): void {
	$route=Generators::shape([
		'operation'=>Generators::element(['bulk_update','bulk_transition','bulk_export']),
		'record'=>Generators::nullable(Generators::integer(1, 999)),
		'tags'=>Generators::listOf(Generators::string(1, 5), 0, 3),
		'confirmed'=>Generators::boolean(),
	]);
	$cases=Generators::cases($route, 12, 20260714, 'panel-route');
	$t->same('panel-route', $cases->kind());
	$t->same(12, $cases->count());
	$t->same(iterator_to_array($cases), iterator_to_array(Generators::cases($route, 12, 20260714, 'panel-route')));
	$t->count(12, iterator_to_array($cases));
	$shards=iterator_to_array($cases->shards(5));
	$t->count(3, $shards);
	$t->same([5, 5, 2], array_map(static fn(array $row): int=>$row[0]->count(), array_values($shards)));
	$t->same(
		iterator_to_array($cases),
		array_merge(...array_map(static fn(array $row): array=>iterator_to_array($row[0]), array_values($shards))),
	);
	foreach($shards as $label=>$row){
		$t->contains('panel-route shard ', (string)$label);
		$t->instanceOf(GeneratedCases::class, $row[0]);
	}
	$t->same([], iterator_to_array(Generators::cases($route, 0, 1, 'empty')->shards(5)));
	$t->throws(static fn()=>iterator_to_array($cases->shards(0)), InvalidArgumentException::class);

	$base=Generators::shape(['operation'=>Generators::element(['a','b'])]);
	$extended=Generators::shape(['operation'=>Generators::element(['a','b']), 'unrelated'=>Generators::integer(1, 100)]);
	$base_rows=iterator_to_array(Generators::cases($base, 10, 99, 'shape'));
	$extended_rows=iterator_to_array(Generators::cases($extended, 10, 99, 'shape'));
	$t->same(
		array_map(static fn(array $arguments): mixed=>$arguments[0]['operation'], $base_rows),
		array_map(static fn(array $arguments): mixed=>$arguments[0]['operation'], $extended_rows)
	);

	$even=Generators::integer(1, 100)->filter(static fn(int $value): bool=>$value%2===0, 100, 'even integer');
	$mapped=$even->map(static fn(int $value): string=>'id-'.$value, null, 'prefixed even integer');
	$named=$even->named('');
	$t->same('even integer', $named->description());
	$t->startsWith('id-', $mapped->sample(new DeterministicRandom(8)));
	$tuple=Generators::tupleOf(Generators::integer(1, 1), Generators::element(['bulk_update']));
	$t->same([1,'bulk_update'], $tuple->sample(new DeterministicRandom(11)));
	$t->throws(static fn()=>(new Arbitrary(static fn(): int=>1))->filter(static fn(): bool=>false, 2)->sample(new DeterministicRandom(1)), RuntimeException::class);
	$t->throws(static fn()=>Generators::shape(['bad'=>new stdClass()]), InvalidArgumentException::class);
	$t->throws(static fn()=>Generators::nullable(Generators::integer(), 0, 0), InvalidArgumentException::class);
	$t->throws(static fn()=>Generators::integer(2, 1), InvalidArgumentException::class);
})->contract('testing.composable-generators', 1);

test('replay tokens validate generator identity and shrinking reaches a fixed point', static function(Context $t): void {
	$cases=Generators::fuzzIntegers(0, 100, 4, 77);
	$first=iterator_to_array($cases);
	$label=(string)array_key_first($first);
	$case=$first[$label];
	$token=$cases->replayToken($label, $case);
	$t->startsWith('dpt1.', $token);
	$t->same([$label=>$case], iterator_to_array($cases->replay($token, true)));
	$t->same('integers', $cases->validateReplayToken($token)['kind']);
	$t->throws(static fn()=>iterator_to_array($cases->replay(substr($token, 0, -1).'x', true)), InvalidArgumentException::class);
	$t->throws(static fn()=>Generators::fuzzStrings(2, 0, 2, 77)->validateReplayToken($token), InvalidArgumentException::class);
	$t->same($first, iterator_to_array($cases->replay('invalid legacy token')));
	$t->throws(static fn()=>$cases->validateReplayToken('invalid legacy token'), InvalidArgumentException::class);
	$replay_token=static function(string $body): string {
		return 'dpt1.'.$body.'.'.substr(hash('sha256', 'dataphyre-replay-v1.'.$body), 0, 24);
	};
	$invalid_json_body=rtrim(strtr(base64_encode('{'), '+/', '-_'), '=');
	$t->throws(static fn()=>$cases->validateReplayToken($replay_token($invalid_json_body)), InvalidArgumentException::class);
	$incomplete_body=rtrim(strtr(base64_encode('{"version":1}'), '+/', '-_'), '=');
	$t->throws(static fn()=>$cases->validateReplayToken($replay_token($incomplete_body)), InvalidArgumentException::class);
	$t->throws(static fn()=>$cases->validateReplayToken($replay_token('*')), InvalidArgumentException::class);

	$legacy=base64_encode(json_encode(['kind'=>'integers','seed'=>77,'label'=>'legacy','case'=>[8]]));
	$t->same(['legacy'=>[8]], iterator_to_array($cases->replay($legacy, true)));
	$legacy_other=base64_encode(json_encode(['kind'=>'strings','seed'=>77,'label'=>'legacy','case'=>['x']]));
	$t->throws(static fn()=>$cases->validateReplayToken($legacy_other), InvalidArgumentException::class);
	$resource=fopen('php://memory', 'r');
	$t->isTrue(is_resource($resource));
	if(is_resource($resource)){
		try{
			$t->throws(static fn()=>$cases->replayToken('resource', $resource), InvalidArgumentException::class);
		}finally{
			fclose($resource);
		}
	}

	$shrinking=new GeneratedCases('fixed-point', 8, 1, static fn(): array=>['original'=>[100]], static function(array $arguments): iterable {
		$value=(int)($arguments[0] ?? 0);
		if($value>0){
			yield [intdiv($value, 2)];
			yield [0];
		}
	});
	$result=$shrinking->shrinkResult([100], static function(Context $context, int $value): void {
		if($value>=3){
			throw new RuntimeException('still fails');
		}
	}, $t);
	$t->same([3], $result->minimal);
	$t->isTrue($result->fixed_point);
	$t->greaterThan(1, $result->candidates);
	$t->same($result->minimal, $shrinking->shrink([100], static function(Context $context, int $value): void {
		if($value>=3){ throw new RuntimeException('still fails'); }
	}, $t));

	$bounded=new GeneratedCases('bounded', 9, 1, static fn(): array=>[], static function(array $arguments): iterable {
		yield [2];
		yield [1];
	});
	$bounded_result=$bounded->shrinkResult([3], static fn(Context $context, int $value): bool=>true, $t, 1);
	$t->same(1, $bounded_result->candidates);
	$t->isFalse($bounded_result->fixed_point);

	$closure_case=static fn(): string=>'not serializable';
	$unserializable=new GeneratedCases('unserializable', 10, 1, static fn(): array=>[], static fn(mixed $value): iterable=>[]);
	$unserializable_result=$unserializable->shrinkResult($closure_case, static fn(): bool=>true, $t);
	$t->same($closure_case, $unserializable_result->minimal);
})->contract('testing.validated-replay', 1);

test('failure corpora deduplicate minimized examples and persist replayable contracts', static function(Context $t): void {
	$workspace=$t->workspace('testkit-failure-corpus');
	$path=$workspace->path('corpora/panel-routes.json');
	$cases=Generators::fuzzIntegers(0, 100, 4, 55);
	$rows=iterator_to_array($cases);
	$label=(string)array_key_first($rows);
	$case=$rows[$label];
	$corpus=FailureCorpus::open($path);
	$id=$corpus->record('panel.route-roundtrip', $cases, $label, $case, ['boundary'=>'route-parser']);
	$t->same($id, $corpus->record('panel.route-roundtrip', $cases, $label, $case));
	$t->same(1, $corpus->count());
	$t->same(2, $corpus->entries()[0]['occurrences']);
	$t->same(['boundary'=>'route-parser'], $corpus->entries()[0]['metadata']);
	$t->count(1, $corpus->entries('panel.route-roundtrip'));
	$t->same([], $corpus->entries('another.contract'));
	$t->count(1, iterator_to_array($corpus));

	$reloaded=FailureCorpus::open($path);
	$t->same($corpus->toArray(), $reloaded->toArray());
	$t->same([$id.':'.$label=>$case], iterator_to_array($reloaded->replay('panel.route-roundtrip', $cases)));
	$t->same([], iterator_to_array($reloaded->replay('another.contract', $cases)));
	$t->isFalse($reloaded->remove('missing'));
	$t->isTrue($reloaded->remove($id));
	$t->same(0, FailureCorpus::open($path)->count());
	$empty=$workspace->file('empty.json', '');
	$t->same(0, FailureCorpus::open($empty)->count());
	$memory_only=new FailureCorpus();
	$memory_only->recordReplay('memory.contract', 'memory-token');
	$t->same(1, $memory_only->count());
	$t->throws(static fn()=>(new FailureCorpus())->recordReplay('', 'token'), InvalidArgumentException::class);

	$invalid=$workspace->file('invalid.json', '{broken');
	$t->throws(static fn()=>FailureCorpus::open($invalid), InvalidArgumentException::class);
	$unsupported=$workspace->file('unsupported.json', '{"version":2,"entries":[]}');
	$t->throws(static fn()=>FailureCorpus::open($unsupported), InvalidArgumentException::class);
})->contract('testing.failure-corpus', 1)->watches('artifact:fuzz-corpus');

test('fuzz failures publish minimized validated replay capsules into the configured corpus', static function(Context $t): void {
	$workspace=$t->workspace('testkit-fuzz-corpus');
	$path=$workspace->path('failures.json');
	$t->setEnvironmentForTest(['DATAPHYRE_FUZZ_CORPUS'=>$path, 'DATAPHYRE_FUZZ_REPLAY'=>null]);
	$context=new Context('route fuzz', '', __FILE__, 'fuzz suite', [
		'stable_id'=>'panel.route-fuzz',
		'contract'=>['name'=>'panel.route-fuzz','version'=>'1'],
	]);
	$cases=new GeneratedCases('route', 19, 1, static fn(): array=>['route'=>[16]], static function(array $arguments): iterable {
		$value=(int)($arguments[0] ?? 0);
		if($value>0){
			yield [intdiv($value, 2)];
		}
	});
	try{
		$context->fuzz($cases, static function(Context $property, int $value): void {
			throw new RuntimeException('route is rejected: '.$value);
		});
		$t->fail('Expected the fuzz property to fail.');
	}catch(AssertionFailed $failure){
		$details=$failure->details();
		$t->same([0], $details['actual']['shrunk']);
		$t->startsWith('dpt1.', $details['meta']['replay']);
		$t->notEmpty($details['meta']['corpus_id']);
		$t->isTrue($details['actual']['shrink']['fixed_point']);
	}
	$corpus=FailureCorpus::open($path);
	$t->same(1, $corpus->count());
	$t->same('panel.route-fuzz', $corpus->entries()[0]['contract']);
	$t->same(['shrunk:route'=>[0]], iterator_to_array($cases->replay($corpus->entries()[0]['replay'], true)));
})->contract('testing.fuzz-corpus-integration', 1);
