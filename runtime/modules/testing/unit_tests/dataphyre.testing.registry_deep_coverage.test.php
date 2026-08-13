<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\AssertionFailed;
use Dataphyre\Test\CaseDefinition;
use Dataphyre\Test\Context;
use Dataphyre\Test\Registry;
use Dataphyre\Test\SkippedTest;
use function Dataphyre\Test\test;

if(!function_exists('Dataphyre\Test\function_exists')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Test;
final class DpTestKitRegistrySeams {
	public static function hideArrayIsList(): bool {
		return TestState::channelIfActive('testing.registry')?->get('hide_array_is_list', false)===true;
	}
}
function function_exists(string $function): bool {
	if($function==='array_is_list' && DpTestKitRegistrySeams::hideArrayIsList()){
		return false;
	}
	return \function_exists($function);
}
PHP);
}

/**
 * Runs a registry exercise without changing the definitions used by the outer
 * framework worker that is currently executing this coverage case.
 */
function dp_testkit_registry_isolated(Context $t,callable $callback): mixed {
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
			$registry->writeProperty($property,$snapshot[$property]);
		}
	}
}

/** @return array<string, mixed> */
function dp_testkit_registry_run_named(string $name): array {
	foreach(Registry::caseSummaries('/coverage/registry.php') as $summary){
		if($summary['base_name']===$name){
			return Registry::run($summary['index'],'/coverage/registry.php');
		}
	}
	throw new RuntimeException('Nested registry case not found: '.$name);
}

test('testing case definitions and registry wrappers cover fluent state and defaults',static function(Context $t): void {
	dp_testkit_registry_isolated($t,static function()use($t): void {
		$case=(new CaseDefinition('fluent',static function(): void {}))
			->with([[1]])
			->uses('','fixture','fixture')
			->tag('','unit','unit')
			->group('','coverage','coverage')
			->dependsOn('','base','base')
			->order(-7)
			->skip()
			->todo()
			->only()
			->maxMillis(0);
		$t->same(['fixture'],$case->fixtures());
		$t->same(['unit'],$case->tags());
		$t->same(['coverage'],$case->groups());
		$t->same(['base'],$case->dependencies());
		$t->same(-7,$case->orderValue());
		$t->same(1,$case->maxMillisValue());
		$t->same('Test skipped.',$case->skipReason());
		$t->same('Test marked todo.',$case->todoReason());
		$t->isTrue($case->isOnly());
		$t->count(1,$case->datasets());

		$conditional=new CaseDefinition('conditional',static function(): void {});
		$t->same($conditional,$conditional->skipIf(static fn(): bool=>false));
		$t->same($conditional,$conditional->skipUnless(static fn(): bool=>true));
		$conditional->skipIf(static fn(): bool=>true,'skip-if');
		$t->same('skip-if',$conditional->skipReason());
		$conditional=new CaseDefinition('conditional-unless',static function(): void {});
		$conditional->skipUnless(static fn(): bool=>false,'skip-unless');
		$t->same('skip-unless',$conditional->skipReason());
		$t->same('explicit',(new CaseDefinition('skip',static function(): void {}))->skip('explicit')->skipReason());
		$t->same('later',(new CaseDefinition('todo',static function(): void {}))->todo('later')->todoReason());

		\Dataphyre\Test\dataset('wrapped-dataset',[[1]]);
		\Dataphyre\Test\fixture('wrapped-fixture',static fn(): string=>'fixture');
		\Dataphyre\Test\before_all(static function(): void {});
		\Dataphyre\Test\after_all(static function(): void {});
		\Dataphyre\Test\before_each(static function(): void {});
		\Dataphyre\Test\after_each(static function(): void {});
		$wrapped=\Dataphyre\Test\todo('wrapped todo','wrapped reason');
		$t->same('wrapped reason',$wrapped->todoReason());
		try{
			($wrapped->body)(new Context('wrapped todo'));
			$t->fail('Expected the wrapped todo body to throw.');
		}catch(SkippedTest $skip){
			$t->isTrue($skip->isTodo());
			$t->same('wrapped reason',$skip->getMessage());
		}
	});
})->tag('testing','registry','coverage')->group('framework-coverage');

test('testing registry datasets cover summaries normalization closures traversables and errors',static function(Context $t): void {
	$seams=$t->state('testing.registry');
	dp_testkit_registry_isolated($t,static function()use($t,$seams): void {
		$t->throws(static fn()=>Registry::dataset(' ',[]),InvalidArgumentException::class);
		$t->throws(static fn()=>Registry::fixture('',static function(): void {}),InvalidArgumentException::class);
		Registry::dataset('mixed',[
			'pair'=>[1,2],
			'associative'=>['value'=>3],
			9,
		]);
		Registry::fixture('summary-fixture',static fn(): string=>'ok');
		Registry::test('dataset case',static function(Context $context,mixed ...$arguments): void {})
			->with('mixed')
			->with(static fn(): Traversable=>new ArrayIterator(['iterator-row'=>[4]]))
			->uses('summary-fixture')
			->tag('summary')
			->group('registry')
			->dependsOn('dependency')
			->order(4)
			->maxMillis(15)
			->only();

		$seams->put('hide_array_is_list',true);
		$expanded=Registry::expandedCases();
		$seams->put('hide_array_is_list',false);
		$t->count(4,$expanded);
		$t->same([1,2],$expanded[0]->arguments);
		$t->same([['value'=>3]],$expanded[1]->arguments);
		$t->same([9],$expanded[2]->arguments);
		$t->same([4],$expanded[3]->arguments);
		$summaries=Registry::caseSummaries('/override.php');
		$t->same('/override.php',$summaries[0]['file']);
		$t->same('pair',$summaries[0]['dataset']);
		$t->same(['summary-fixture'],$summaries[0]['fixtures']);
		$t->same(['summary'],$summaries[0]['tags']);
		$t->same(['registry'],$summaries[0]['groups']);
		$t->same(['dependency'],$summaries[0]['dependencies']);
		$t->same(4,$summaries[0]['order']);
		$t->same(15,$summaries[0]['max_millis']);
		$t->isTrue($summaries[0]['only']);

		Registry::reset();
		Registry::test('empty dataset fallback',static function(): void {})->with([]);
		$t->count(1,Registry::expandedCases());
		Registry::reset();
		Registry::test('missing dataset',static function(): void {})->with('not-registered');
		$t->throws(static fn()=>Registry::expandedCases(),InvalidArgumentException::class);
		Registry::reset();
		Registry::test('invalid closure dataset',static function(): void {})->with(static fn(): int=>42);
		$t->throws(static fn()=>Registry::expandedCases(),InvalidArgumentException::class);

		Registry::reset();
		$registered_traversals=0;
		$registered_generator=(static function()use(&$registered_traversals): Traversable {
			$registered_traversals++;
			yield 'first'=>[1];
			yield 'second'=>[2];
		})();
		Registry::dataset('repeatable generator',$registered_generator);
		$t->same(1,$registered_traversals);
		Registry::test('registered generator case',static function(): void {})->with('repeatable generator');
		$t->same([1,2],array_map(static fn($case): mixed=>$case->arguments[0],Registry::expandedCases()));
		$t->same([1,2],array_map(static fn($case): mixed=>$case->arguments[0],Registry::expandedCases()));
		$t->same(1,$registered_traversals);

		Registry::reset();
		$direct_traversals=0;
		$direct_generator=(static function()use(&$direct_traversals): Traversable {
			$direct_traversals++;
			yield [3];
			yield [4];
		})();
		Registry::test('direct generator case',static function(): void {})->with($direct_generator);
		$t->same([3,4],array_map(static fn($case): mixed=>$case->arguments[0],Registry::expandedCases()));
		$t->same([3,4],array_map(static fn($case): mixed=>$case->arguments[0],Registry::expandedCases()));
		$t->same(1,$direct_traversals);

		Registry::reset();
		$duplicate_labels=(static function(): Traversable {
			yield 'duplicate'=>[1];
			yield 'duplicate'=>[2];
		})();
		$t->throws(static fn()=>Registry::dataset('duplicate labels',$duplicate_labels),InvalidArgumentException::class);
	});
})->tag('testing','registry','coverage')->group('framework-coverage');

test('testing registry runner covers successful hooks fixtures skip todo and repeat guards',static function(Context $t): void {
	dp_testkit_registry_isolated($t,static function()use($t): void {
		$events=[];
		Registry::beforeAll(static function(Context $context)use(&$events): void { $events[]='before-all:'.$context->name(); });
		Registry::beforeEach(static function()use(&$events): void { $events[]='before-each'; });
		Registry::afterEach(static function()use(&$events): void { $events[]='after-each'; });
		Registry::afterAll(static function()use(&$events): void { $events[]='after-all'; });
		Registry::fixture('token',static function(Context $context)use(&$events): string {
			$events[]='setup';
			return 'token';
		},static function(string $value,Context $context)use(&$events): void {
			$events[]='teardown:'.$value;
		});
		Registry::fixture('plain',static fn(): string=>'plain');
		Registry::test('first success',static function(Context $context)use(&$events): void {
			$context->same('token',$context->fixture('token'));
			$events[]='body-one';
		})->uses('token')->order(1);
		Registry::test('second success',static function(Context $context)use(&$events): bool {
			$context->same('plain',$context->fixture('plain'));
			$events[]='body-two';
			return true;
		})->uses('plain')->order(2);
		Registry::test('skipped case',static function(): void { throw new RuntimeException('must not run'); })->skip('skip reason')->order(3);
		Registry::test('todo case',static function(): void { throw new RuntimeException('must not run'); })->todo('todo reason')->order(4);

		$first=dp_testkit_registry_run_named('first success');
		$second=dp_testkit_registry_run_named('second success');
		$skip=dp_testkit_registry_run_named('skipped case');
		$todo=dp_testkit_registry_run_named('todo case');
		$invalid=Registry::run(999,'/coverage/registry.php');
		$t->isTrue($first['passed']);
		$t->same(1,$first['assertions']);
		$t->isTrue($second['passed']);
		$t->same(1,count(array_filter($events,static fn(string $event): bool=>str_starts_with($event,'before-all:'))));
		$t->isTrue($skip['passed']);
		$t->isTrue($skip['skipped']);
		$t->isFalse($skip['todo']);
		$t->same('skip reason',$skip['message']);
		$t->isTrue($todo['passed']);
		$t->isTrue($todo['skipped']);
		$t->isTrue($todo['todo']);
		$t->same('todo reason',$todo['message']);
		$t->isFalse($invalid['passed']);
		$t->same('case #999',$invalid['test_name']);
	});
})->tag('testing','registry','coverage')->group('framework-coverage');

test('testing suite hooks remain scoped and describe their case lifecycle',static function(Context $t): void {
	dp_testkit_registry_isolated($t,static function()use($t): void {
		$events=[];
		Registry::beforeEach(static function()use(&$events): void { $events[]='global-before'; });
		Registry::afterEach(static function()use(&$events): void { $events[]='global-after'; });

		Registry::suite('first suite')
			->beforeEach(static function()use(&$events): void { $events[]='first-before'; })
			->afterEach(static function()use(&$events): void { $events[]='first-after'; });
		Registry::test('first suite case',static function(Context $context)use(&$events): void {
			$events[]='first-body';
			$context->same('first suite',$context->suite());
		});

		Registry::suite('second suite')
			->beforeEach(static function()use(&$events): void { $events[]='second-before'; })
			->afterEach(static function()use(&$events): void { $events[]='second-after'; });
		Registry::test('second suite case',static function(Context $context)use(&$events): void {
			$events[]='second-body';
			$context->same('second suite',$context->suite());
		});

		$first=dp_testkit_registry_run_named('first suite case');
		$second=dp_testkit_registry_run_named('second suite case');
		$t->isTrue($first['passed']);
		$t->same('first suite',$first['suite']);
		$t->isTrue($second['passed']);
		$t->same('second suite',$second['suite']);
		$t->same([
			'global-before','first-before','first-body','first-after','global-after',
			'global-before','second-before','second-body','second-after','global-after',
		],$events);
	});
})->tag('testing','registry','suite','coverage')->group('framework-coverage');

test('testing registry runner covers assertion throwable missing fixture false and timeout failures',static function(Context $t): void {
	dp_testkit_registry_isolated($t,static function()use($t): void {
		Registry::test('assertion failure',static function(Context $context): void { $context->same('expected','actual'); });
		Registry::test('false result',static fn(): bool=>false);
		Registry::test('missing fixture',static function(): void {})->uses('absent');
		Registry::test('throwable failure',static function(): void { throw new LogicException('nested throwable'); });
		Registry::test('timeout failure',static function(): void { usleep(4000); })->maxMillis(1);

		$assertion=dp_testkit_registry_run_named('assertion failure');
		$false=dp_testkit_registry_run_named('false result');
		$missing=dp_testkit_registry_run_named('missing fixture');
		$throwable=dp_testkit_registry_run_named('throwable failure');
		$timeout=dp_testkit_registry_run_named('timeout failure');
		$t->isFalse($assertion['passed']);
		$t->same('Expected values to be strictly identical.',$assertion['message']);
		$t->same('expected',$assertion['details']['expected']);
		$t->isFalse($false['passed']);
		$t->same('Test returned false.',$false['message']);
		$t->isFalse($missing['passed']);
		$t->contains('not registered',$missing['message']);
		$t->isFalse($throwable['passed']);
		$t->same(LogicException::class,$throwable['details']['exception']);
		$t->same('nested throwable',$throwable['message']);
		$t->isFalse($timeout['passed']);
		$t->same('Execution time exceeded maxMillis threshold.',$timeout['message']);
		$t->same(1,$timeout['details']['expected_millis']);
		$t->greaterThan(1.0,$timeout['details']['actual_millis']);
	});
})->tag('testing','registry','coverage')->group('framework-coverage');

test('testing registry runner covers fixture and lifecycle teardown failures',static function(Context $t): void {
	dp_testkit_registry_isolated($t,static function()use($t): void {
		Registry::fixture('broken teardown',static fn(): string=>'value',static function(): void {
			throw new DomainException('fixture teardown exploded');
		});
		Registry::afterEach(static function(): void { throw new UnexpectedValueException('after each exploded'); });
		Registry::afterAll(static function(): void { throw new OverflowException('after all exploded'); });
		Registry::suite('broken lifecycle')->afterEach(static function(): void { throw new OutOfBoundsException('suite after each exploded'); });
		Registry::test('teardown failure',static function(Context $context): void {
			$context->cleanup(static function(): void { throw new LogicException('deferred cleanup exploded'); });
		})->uses('broken teardown');
		$result=dp_testkit_registry_run_named('teardown failure');
		$t->isFalse($result['passed']);
		$t->same('Test teardown failed: deferred cleanup exploded',$result['message']);
		$t->same('deferred cleanup exploded',$result['details']['teardown']['message']);
		$t->same(LogicException::class,$result['details']['teardown']['exception']);
		$t->notEmpty($result['details']['teardown']['file']);
		$t->greaterThan(0,$result['details']['teardown']['line']);
		$t->same(['fixture','suite_after_each','after_each','after_all','deferred'],array_column($result['details']['teardown_failures'],'phase'));
		$t->same(
			['fixture teardown exploded','suite after each exploded','after each exploded','after all exploded','deferred cleanup exploded'],
			array_column($result['details']['teardown_failures'],'message')
		);
	});
})->tag('testing','registry','coverage')->group('framework-coverage');
