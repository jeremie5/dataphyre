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
use Dataphyre\Test\Context;
use Dataphyre\Test\Expectation;
use Dataphyre\Test\GeneratedCases;
use Dataphyre\Test\SkippedTest;
use function Dataphyre\Test\test;

if(!function_exists('Dataphyre\Test\function_exists')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Test;
final class DpTestKitContextSeams {
	public static function hideArrayIsList(): bool {
		return TestState::channelIfActive('testing.context')?->get('hide_array_is_list', false)===true;
	}
}
function function_exists(string $function): bool {
	if($function==='array_is_list' && DpTestKitContextSeams::hideArrayIsList()){
		return false;
	}
	return \function_exists($function);
}
PHP);
}

function dp_testkit_context_failure(callable $callback): AssertionFailed {
	try{
		$callback();
	}catch(AssertionFailed $failure){
		return $failure;
	}
	throw new RuntimeException('Expected an AssertionFailed exception.');
}

final class DpTestkitSchemaConstant {
	public const COLUMNS=['id','status'];
}

final class DpTestkitSchemaMethods {
	public function columns(): array { return ['id','email']; }
}

final class DpTestkitResponseMethods {
	public function status(): int { return 207; }
	public function headers(): array { return ['X-Test'=>'yes']; }
	public function json(): array { return ['ok'=>true]; }
}

test('testing context expectation aliases and exception values cover complete contracts',static function(Context $t): void {
	$failure=new AssertionFailed('Broken',1,2,['rule'=>'same']);
	$t->same([
		'message'=>'Broken','expected'=>1,'actual'=>2,'meta'=>['rule'=>'same'],
	],$failure->details());
	$t->isFalse((new SkippedTest(''))->isTodo());
	$t->isTrue((new SkippedTest('',true))->isTodo());
	$t->same('Test skipped.',(new SkippedTest(''))->getMessage());
	$t->same('Test marked todo.',(new SkippedTest('',true))->getMessage());

	$t->expect(1)->notToBe(2)->toEqual(1)->notToEqual(2);
	$t->expect(true)->toBeTrue();
	$t->expect(false)->toBeFalse();
	$t->expect(null)->toBeNull();
	$t->expect('value')->notToBeNull();
	$t->expect('alpha')->notToContain('z')->toBeType('string');
	$t->expect(new stdClass())->toBeInstanceOf(stdClass::class);
	$t->expect(10)->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(10)->toBeBetween(1,20)->toBeApproximately(11,1);
	$t->expect([])->toBeEmpty();
	$t->expect([1])->notToBeEmpty();
	$t->expect('<main></main>')->toMissHtmlSelector('button');
	$t->expect(true)->not()->toBeFalse();
	$t->expect(false)->not()->toBeTrue();
	$t->expect('value')->not()->toBeNull();
	$t->expect('alpha')->not()->toContain('z');
	$t->expect(['id'=>1])->not()->toHaveKey('missing');
	$t->expect(['id'=>1,'name'=>'Ada'])->toHaveKeys(['id'])->toHaveExactKeys(['id','name']);
	$t->hasKeys((static function(): Generator {yield 'id';yield 'name';})(),['id'=>1,'name'=>'Ada']);
	$t->sameKeys((static function(): Generator {yield 'id';yield 'name';})(),['id'=>1,'name'=>'Ada']);
	$t->expect(['id'=>1])->not()->toHavePath('missing');
	$t->expect(['id'=>1])->not()->toHavePathValue('id',2);

	$countFailure=dp_testkit_context_failure(static fn()=> (new Expectation($t,123))->toHaveCount(1));
	$t->same('array|Countable',$countFailure->details()['expected']);
	$missingFixture=dp_testkit_context_failure(static fn()=>$t->fixture('missing'));
	$t->contains('Fixture',$missingFixture->getMessage());
	$t->setFixtures(['known'=>'value']);
	$t->same('value',$t->fixture('known'));

	try{
		$t->skip();
	}catch(SkippedTest $skip){
		$t->isFalse($skip->isTodo());
	}
	try{
		$t->todo();
	}catch(SkippedTest $todo){
		$t->isTrue($todo->isTodo());
	}
})->tag('testing','context','coverage')->group('framework-coverage');

test('testing context assertion failures expose every diagnostic branch',static function(Context $t): void {
	$private=$t->nonPublic($t);
	$t->notStartsWith('z','abc');
	$t->notEndsWith('z','abc');
	$t->isEmpty(new ArrayObject());
	$t->length(2,new ArrayObject([1,2]));
	$failures=[
		static fn()=>$t->same(1,2),
		static fn()=>$t->equals(1,2),
		static fn()=>$t->notSame(1,1),
		static fn()=>$t->notEquals(1,'1'),
		static fn()=>$t->isNull('value'),
		static fn()=>$t->notNull(null),
		static fn()=>$t->contains('x','abc'),
		static fn()=>$t->contains('x',['a']),
		static fn()=>$t->contains('x',new stdClass()),
		static fn()=>$t->notContains('a','abc'),
		static fn()=>$t->notContains('a',['a']),
		static fn()=>$t->matches('/z/','abc'),
		static fn()=>$t->notMatches('/a/','abc'),
		static fn()=>$t->startsWith('z','abc'),
		static fn()=>$t->notStartsWith('a','abc'),
		static fn()=>$t->endsWith('z','abc'),
		static fn()=>$t->notEndsWith('c','abc'),
		static fn()=>$t->isEmpty('value'),
		static fn()=>$t->notEmpty(''),
		static fn()=>$t->length(2,'abc'),
		static fn()=>$t->length(1,123),
		static fn()=>$t->count(2,[1]),
		static fn()=>$t->type('int','1'),
		static fn()=>$t->hasKey('id',['name'=>'x']),
		static fn()=>$t->hasKey('id','invalid'),
		static fn()=>$t->hasKeys(['id'],['name'=>'x']),
		static fn()=>$t->hasKeys(['id'],'invalid'),
		static fn()=>$t->sameKeys(['id'],['name'=>'x']),
		static fn()=>$t->sameKeys(['id'],'invalid'),
		static fn()=>$t->expect(['id'=>1])->not()->toHaveKeys(['id']),
		static fn()=>$t->expect(['id'=>1])->not()->toHaveExactKeys(['id']),
		static fn()=>$t->missingKey('id',['id'=>1]),
		static fn()=>$t->hasPath('user.id',['user'=>[]]),
		static fn()=>$t->missingPath('user.id',['user'=>['id'=>1]]),
		static fn()=>$t->pathEquals('missing',1,[]),
		static fn()=>$t->pathEquals('id',2,['id'=>1]),
		static fn()=>$t->pathNotEquals('id',1,['id'=>1]),
		static fn()=>$t->subset(['id'=>1],['id'=>2]),
		static fn()=>$t->greaterThan(5,5),
		static fn()=>$t->lessThan(5,5),
		static fn()=>$t->greaterThanOrEqual(5,4),
		static fn()=>$t->lessThanOrEqual(5,6),
		static fn()=>$t->between(1,5,6),
		static fn()=>$t->approximately(10,12,1),
		static fn()=>$t->isMinorUnits('100'),
		static fn()=>$t->moneyAmount('1.00',99),
		static fn()=>$t->instanceOf(stdClass::class,[]),
		static fn()=>$t->throws(static fn()=>true),
		static fn()=>$t->throws(static fn()=>throw new LogicException('wrong'),RuntimeException::class),
		static fn()=>$t->doesNotThrow(static fn()=>throw new RuntimeException('failed')),
	];
	foreach($failures as $index=>$callback){
		$failure=dp_testkit_context_failure($callback);
		$t->notEmpty($failure->getMessage(),'failure '.$index);
	}
	$t->pathNotEquals('missing',1,[]);
	$t->type('bool',true);
	$t->type('int',1);
	$t->type('float',1.0);
	$t->moneyAmount('-1',-1,0);

	$object=(object)['user'=>(object)['id'=>7]];
	$t->pathEquals('user.id',7,$object);
	$t->same(['user'],$private->invoke('pathShape',$object));
	$t->same('integer',$private->invoke('pathShape',7));
	$t->same(['a',0,'b'],$private->invoke('pathParts','a[0]..b'));
	$t->same(['a',0],$private->invoke('pathParts',['a',0]));
	$t->same('a.0',$private->invoke('pathLabel',['a',0]));

	$t->isFalse($private->invoke('subsetMatches',[0],123));
	$t->isFalse($private->invoke('subsetMatches',['missing'=>1],[]));
	$t->isFalse($private->invoke('subsetMatches',['nested'=>['id'=>1]],['nested'=>['id'=>2]]));
	$t->isFalse($private->invoke('subsetMatches',['id'=>1],['id'=>2]));
	$t->isTrue($private->invoke('subsetMatches',['id'=>1],(object)['id'=>1]));
	$t->same('array',$private->invoke('describe',[1]));
})->tag('testing','context','coverage')->group('framework-coverage');

test('testing context response surface html performance and property errors are isolated',static function(Context $t): void {
	$private=$t->nonPublic($t);
	$response=new DpTestkitResponseMethods();
	$t->responseStatus(207,$response);
	$t->responseHeader('x-test','yes',$response);
	$t->responseJsonPath('ok',true,$response);
	$t->responseJsonSubset(['ok'=>true],$response);
	$t->same('fallback',$private->invoke('responseValue',$response,['missing'],'fallback'));
	$t->same(['x-test'=>'yes'],$private->invoke('responseHeaders',$response));
	$t->same('not-json',$private->invoke('responseJson',['body'=>'not-json']));
	$t->same(['ok'=>true],$private->invoke('responseJson',['body'=>['ok'=>true]]));

	$surface=(object)[
		'fields'=>[
			'keyed'=>(object)['name'=>'different'],
			(object)['field'=>'object_field'],
			['id'=>'array_id'],
		],
		'filters'=>[['key'=>'filter_key']],
		'actions'=>[['action'=>'action_key']],
	];
	$t->panelHasField($surface,'keyed');
	$t->panelHasField($surface,'object_field');
	$t->panelHasField($surface,'array_id');
	$t->panelHasFilter($surface,'filter_key');
	$t->panelHasAction($surface,'action_key');
	$t->same([],$private->invoke('surfaceItems',$surface,['missing']));
	dp_testkit_context_failure(static fn()=>$t->panelHasField([], 'missing'));

	$t->schemaHasColumn(DpTestkitSchemaConstant::class,'status');
	$t->schemaHasColumn(new DpTestkitSchemaMethods(),'email');
	$t->schemaHasColumn((object)['fields'=>['name']],'name');
	$t->schemaHasColumn(['COLUMNS'=>['legacy']],'legacy');
	$t->schemaHasColumn(['id'=>'integer'],'id');
	dp_testkit_context_failure(static fn()=>$t->schemaHasColumn('MissingSchema','id'));
	$t->queryMatches(['query'=>'select 1'],'/select/');

	foreach([
		static fn()=>$t->responseStatus(200,['status'=>500]),
		static fn()=>$t->responseHeader('x','yes',['headers'=>[]]),
		static fn()=>$t->responseJsonPath('id',1,['body'=>'{}']),
		static fn()=>$t->responseJsonSubset(['id'=>1],['body'=>'{}']),
		static fn()=>$t->traceContains([], 'request'),
		static fn()=>$t->eventContains([], 'saved'),
		static fn()=>$t->htmlContainsText('<p>Hello</p>','Missing'),
		static fn()=>$t->htmlHasSelector('<main></main>','button'),
		static fn()=>$t->htmlMissingSelector('<button></button>','button'),
		static fn()=>$t->htmlAttribute('<button id="x"></button>','#x','type','submit'),
	] as $callback){
		dp_testkit_context_failure($callback);
	}

	$slow=new BenchmarkResult([10.0,20.0],10,20);
	dp_testkit_context_failure(static fn()=>$t->performanceUnder($slow,1));
	$t->same($slow,$t->performanceUnder($slow,100));
	$memoryHold=null;
	dp_testkit_context_failure(static fn()=>$t->memoryUnder(static function()use(&$memoryHold): void {
		$memoryHold=str_repeat('x',1024);
	},-1));
	unset($memoryHold);
	$t->memoryUnder(static fn()=>null,PHP_INT_MAX);
	$t->benchmark(static fn()=>null,1,2);

	$visited=[];
	$t->forAll([1,2,3],static function(Context $context,int $value)use(&$visited): void {
		$visited[]=$value;
	},1);
	$t->same([1],$visited);
	dp_testkit_context_failure(static fn()=>$t->forAll([1],static fn()=>throw new RuntimeException('property')));

	$cases=new GeneratedCases('coverage',123,1,static fn(): array=>['five'=>[5]],static fn(array $case): iterable=>[[4],[0]]);
	dp_testkit_context_failure(static fn()=>$t->fuzz($cases,static fn(Context $context,int $value)=>throw new RuntimeException('fuzz')));
})->tag('testing','context','coverage')->group('framework-coverage');

test('testing context snapshots cover create compare missing diff and directory failures',static function(Context $t): void {
	$workspace=$t->workspace('testing-context-snapshots');
	$base=$workspace->root();
	$file=$workspace->path('coverage.test.php');
	$context=new Context('snapshot coverage','row one',$file);
	$private=$t->nonPublic($context);
	$seams=$t->state('testing.context');
	putenv('DATAPHYRE_UPDATE_SNAPSHOTS=1');
	$context->snapshot('payload',['id'=>1]);
	putenv('DATAPHYRE_UPDATE_SNAPSHOTS');
	$context->snapshot('payload',['id'=>1]);
	dp_testkit_context_failure(static fn()=>$context->snapshot('missing',['id'=>1]));
	dp_testkit_context_failure(static fn()=>$context->snapshot('payload',['id'=>2]));

	$path=$private->invoke('snapshotPath','payload');
	$t->isTrue(is_file($path));
	$t->same('plain',$private->invoke('snapshotContent','plain'));
	$t->contains('"id": 1',$private->invoke('snapshotContent',['id'=>1]));
	$expected=implode("\n",array_map(static fn(int $index): string=>'old-'.$index,range(1,100)));
	$actual=implode("\n",array_map(static fn(int $index): string=>'new-'.$index,range(1,100)));
	$t->contains('diff truncated',$private->invoke('unifiedDiff',$expected,$actual,1));
	$t->same('snapshot',$private->invoke('sanitizeSnapshotName','---'));

	$seams->put('hide_array_is_list', true);
	$t->isTrue($private->invoke('isListValue',[1,2]));
	$t->isFalse($private->invoke('isListValue',[1=>'a']));
	$seams->put('hide_array_is_list', false);

	$blocker=$workspace->file('blocker','file');
	$blocked=new Context('blocked','',$blocker.'/test.php');
	putenv('DATAPHYRE_UPDATE_SNAPSHOTS=1');
	dp_testkit_context_failure(static fn()=>$blocked->snapshot('payload',['id'=>1]));
	putenv('DATAPHYRE_UPDATE_SNAPSHOTS');

})->tag('testing','context','coverage')->group('framework-coverage');
