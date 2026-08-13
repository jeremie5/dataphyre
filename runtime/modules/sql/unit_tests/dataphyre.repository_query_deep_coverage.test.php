<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\Relation;
use Dataphyre\Database\RepositoryQuery;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Currency\Money;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

function dp_repository_query_state(): TestState {
	return TestState::channel('sql.repository-query');
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		$state=dp_repository_query_state();
		$table=(string)($arguments[1] ?? '');
		$rowsByTable=$state->get('rows',[]);
		$rows=$rowsByTable[$table] ?? $rowsByTable['default'] ?? [];
		$callback=$arguments[array_key_last($arguments)] ?? null;
		if(count($arguments)>=8 && is_callable($callback)){
			$callback($rows);
			return $state->get('select_queue_return');
		}
		if($rows===false) return false;
		return (bool)($arguments[4] ?? true) ? $rows : ($rows[0] ?? null);
	}
}
if(!function_exists('sql_count')){
	function sql_count(mixed ...$arguments): mixed {
		$state=dp_repository_query_state();
		$count=(int)$state->get('count',2);
		$callback=$arguments[array_key_last($arguments)] ?? null;
		if(count($arguments)>=6 && is_callable($callback)){
			$callback($count);
			return $state->get('count_queue_return');
		}
		return $count;
	}
}
if(!function_exists('sql_insert')){
	function sql_insert(mixed ...$arguments): int { return 3; }
}
if(!function_exists('sql_update')){
	function sql_update(mixed ...$arguments): int { return 1; }
}
if(!function_exists('sql_delete')){
	function sql_delete(mixed ...$arguments): int { return 1; }
}
if(!function_exists('sql_upsert')){
	function sql_upsert(mixed ...$arguments): int { return 1; }
}
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static function clear_last_query_error(): void {} public static function hydrate_missing_structure_from_definition(string $table): bool { return false; } public static function invalidate_cache(string $table): void {} }');
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'currency'=>true,'sql'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/helper_functions.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['currency','sql']);
if(!class_exists('dataphyre\\currency',false)){
	require_once $modulesRoot.'/currency/kernel/currency.main.php';
}

final class DpRepositoryQueryDeepParent extends TableRepository {
	protected static function table(): string { return 'deep_parents'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema('deep_parents',['id','name','owner_id','amount','currency'],['listing'=>['id','name']],'id',[
			'id'=>'integer','owner_id'=>'integer','amount'=>'float',
		]);
	}
	protected static function defaultReadCaching(): array { return ['enabled'=>false]; }
	public static function children(): Relation { return self::hasMany(DpRepositoryQueryDeepChild::class,'parent_id','id'); }
	public static function owner(): Relation { return self::belongsTo(self::class,'owner_id','id'); }
}

final class DpRepositoryQueryDeepChild extends TableRepository {
	protected static function table(): string { return 'deep_children'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema('deep_children',['id','parent_id','name','amount'],[],'id',[
			'id'=>'integer','parent_id'=>'integer','amount'=>'float',
		]);
	}
	protected static function defaultReadCaching(): array { return ['enabled'=>false]; }
}

final class DpRepositoryQueryDeepNoKey extends TableRepository {
	protected static function table(): string { return 'deep_no_key'; }
	protected static function schema(): ?TableSchema { return new TableSchema('deep_no_key',['name']); }
}

final class DpRepositoryQueryDeepInvoker {
	public function __invoke(mixed $value=null): mixed { return $value; }
	public static function callback(mixed $value=null): mixed { return $value; }
}

test('repository query residual coverage forwards custom messages through synchronous and queued not-found and sole guards',static function(Context $t): void {
	$state=dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query();
	$expect=static function(callable $operation,string $message)use($t): void {
		$caught='';
		try{
			$operation();
		}catch(\RuntimeException $exception){
			$caught=$exception->getMessage();
		}
		$t->contains($message,$caught);
	};

	dp_repository_query_returns($state,'deep_parents',[]);
	$expect(static fn()=>$query->firstOrFail(null,null,'sync-first-message'),'sync-first-message');
	$expect(static fn()=>$query->sole(null,null,'sync-sole-empty-message'),'sync-sole-empty-message');
	$expect(static fn()=>$query->firstRecordOrFail(null,null,null,'sync-record-message'),'sync-record-message');
	$expect(static fn()=>$query->findOrFail(99,null,null,'sync-find-message'),'sync-find-message');
	$expect(static fn()=>$query->findHydratedOrFail(99,null,null,null,'sync-hydrated-message'),'sync-hydrated-message');
	$expect(static fn()=>$query->findRecordOrFail(99,null,null,null,'sync-record-find-message'),'sync-record-find-message');

	dp_repository_query_returns($state,'deep_parents',[['id'=>1],['id'=>2]]);
	$expect(static fn()=>$query->sole(null,null,'sync-sole-many-message'),'sync-sole-many-message');

	dp_repository_query_returns($state,'deep_parents',[]);
	$callback=static fn(mixed $value): mixed=>$value;
	$expect(static fn()=>$query->queueFirstOrFail($callback,'end',null,null,'queue-first-message'),'queue-first-message');
	$expect(static fn()=>$query->queueFirstRecordOrFail($callback,'end',null,null,null,'queue-record-message'),'queue-record-message');
	$expect(static fn()=>$query->queueFindOrFail(99,$callback,null,'end',null,'queue-find-message'),'queue-find-message');
	$expect(static fn()=>$query->queueFindHydratedOrFail(99,$callback,null,'end',null,null,'queue-hydrated-message'),'queue-hydrated-message');
	$expect(static fn()=>$query->queueSole($callback,'end',null,null,'queue-sole-empty-message'),'queue-sole-empty-message');
	$expect(static fn()=>$query->queueSoleRecord($callback,'end',null,null,null,'queue-record-empty-message'),'queue-record-empty-message');

	dp_repository_query_returns($state,'deep_parents',[['id'=>1],['id'=>2]]);
	$expect(static fn()=>$query->queueSole($callback,'end',null,null,'queue-sole-many-message'),'queue-sole-many-message');
	$expect(static fn()=>$query->queueSoleRecord($callback,'end',null,null,null,'queue-record-many-message'),'queue-record-many-message');
})->tag('sql','sql-residual','repository-query','deep-coverage')->group('framework-coverage');

function dp_repository_query_scenario(Context $t): TestState {
	return $t->state('sql.repository-query',[
		'rows'=>[
		'deep_parents'=>[
			['id'=>1,'name'=>'Alpha','owner_id'=>2,'amount'=>12.5,'currency'=>'USD'],
			['id'=>2,'name'=>'Beta','owner_id'=>1,'amount'=>7.5,'currency'=>'USD'],
		],
		'deep_children'=>[
			['id'=>10,'parent_id'=>1,'name'=>'Child A','amount'=>2.5,'aggregate_value'=>2.5],
			['id'=>11,'parent_id'=>2,'name'=>'Child B','amount'=>4.0,'aggregate_value'=>4.0],
		],
		'default'=>[],
		],
		'count'=>2,
		'select_queue_return'=>null,
		'count_queue_return'=>null,
	]);
}

function dp_repository_query_returns(TestState $state,string $table,array $rows): void {
	$state->put('rows',array_replace($state->get('rows',[]),[$table=>$rows]));
}

test('repository strict reads distinguish SQL failure from empty parents and eager relations',static function(Context $t): void {
	$state=dp_repository_query_scenario($t);
	$rows=$state->get('rows',[]);
	$rows['deep_parents']=false;
	$state->put('rows',$rows);
	$t->same([],DpRepositoryQueryDeepParent::query()->get());
	$t->throws(static fn()=>DpRepositoryQueryDeepParent::query()->failOnReadError()->get(),RuntimeException::class);
	$t->throws(static fn()=>DpRepositoryQueryDeepParent::query()->failOnReadError()->first(),RuntimeException::class);

	$rows['deep_parents']=[];
	$state->put('rows',$rows);
	$t->same([],DpRepositoryQueryDeepParent::query()->failOnReadError()->get());
	$t->same(null,DpRepositoryQueryDeepParent::query()->failOnReadError()->first());

	$rows['deep_parents']=[['id'=>1,'name'=>'Alpha','owner_id'=>2,'amount'=>12.5,'currency'=>'USD']];
	$rows['deep_children']=false;
	$state->put('rows',$rows);
	$permissive=DpRepositoryQueryDeepParent::query()->with('children')->get();
	$t->same([],$permissive[0]['children']);
	$t->throws(
		static fn()=>DpRepositoryQueryDeepParent::query()->failOnReadError()->with('children')->get(),
		RuntimeException::class
	);

	$restored=RepositoryQuery::fromExecutionState(DpRepositoryQueryDeepParent::query()->failOnReadError()->executionState());
	$t->same(true,$restored->executionState()['fail_on_read_error']);
})->tag('sql','repository-query','strict-read','relation','regression')->group('framework-coverage');

test('repository query deep coverage normalizes eager relation count and aggregate descriptor inputs',static function(Context $t): void {
	dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query();
	$queryInternals=$t->nonPublic($query);
	$constraint=static fn(RepositoryQuery $related): RepositoryQuery=>$related->whereGt('id',0);
	$relations=$queryInternals->invokeWithArguments('normalizeEagerRelationInput',['children','*',false,$constraint]);
	$t->same('children',$relations[0]['name']);
	$relations=$queryInternals->invokeWithArguments('normalizeEagerRelationInput',[[
		'children',
		'owner'=>['columns'=>['id','name'],'caching'=>['owners'],'constraint'=>$constraint],
		'children_again'=>'ignored',
	],['id'],null,null]);
	$t->same(3,count($relations));
	$t->same(['id','name'],$relations[1]['columns']);
	$t->same(['owners'],$relations[1]['caching']);

	$counts=$queryInternals->invokeWithArguments('normalizeEagerCountInput',['children','kids',false,$constraint]);
	$t->same('kids',$counts[0]['alias']);
	$counts=$queryInternals->invokeWithArguments('normalizeEagerCountInput',[[
		'children',
		'owner'=>'owners_total',
		'children_again'=>['as'=>'again_total','caching'=>['counts'],'constraint'=>$constraint],
	],null,null,null]);
	$t->same(3,count($counts));
	$t->same('owners_total',$counts[1]['alias']);
	$t->same('again_total',$counts[2]['alias']);

	$aggregates=$queryInternals->invokeWithArguments('normalizeEagerAggregateInput',['children','sum','amount','child_sum',false,false,$constraint]);
	$t->same('SUM',$aggregates[0]['function']);
	$t->same('child_sum',$aggregates[0]['alias']);
	$aggregates=$queryInternals->invokeWithArguments('normalizeEagerAggregateInput',[[
		'children',
		'owner'=>'owner_total',
		'children_again'=>['function'=>'avg','column'=>'amount','as'=>'again_avg','caching'=>['aggregates'],'distinct'=>true,'constraint'=>$constraint],
	],'sum','*',null,null,false,null]);
	$t->same(3,count($aggregates));
	$t->same('owner_total',$aggregates[1]['alias']);
	$t->same('AVG',$aggregates[2]['function']);
	$t->isTrue($aggregates[2]['distinct']);
	$t->throws(static fn()=>$queryInternals->invokeWithArguments('relationPropertyName',['bad-name']),Throwable::class);
	$t->throws(static fn()=>$queryInternals->invokeWithArguments('normalizeAggregateFunction',['median']),Throwable::class);
	$t->same('children_sum_all',$queryInternals->invokeWithArguments('defaultAggregateAlias',['children','sum','*']));
	$t->same('children_sum_value',$queryInternals->invokeWithArguments('defaultAggregateAlias',['children','sum','***']));
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage');

test('repository query deep coverage eager loads rows records counts aggregates and serializes descriptors',static function(Context $t): void {
	dp_repository_query_scenario($t);
	$relation=DpRepositoryQueryDeepParent::relationNamed('children');
	$query=DpRepositoryQueryDeepParent::query()
		->select(['name'])
		->with(['children'=>['columns'=>['id','parent_id','name']]])
		->withRecords('owner',['id','name'])
		->withCount(['children'=>'children_total'])
		->withAggregate(['children'=>['function'=>'sum','column'=>'amount','as'=>'children_amount']], 'sum','amount')
		->withRelation('explicit_children',$relation,['id','parent_id'])
		->withRelationRecords('explicit_records',$relation,'*')
		->withRelationCount('explicit_count',$relation)
		->withRelationAggregate('explicit_sum',$relation,'sum','amount');
	$queryInternals=$t->nonPublic($query);
	$rows=$query->get();
	$t->same(2,count($rows));
	$t->hasKey('children',$rows[0]);
	$t->hasKey('children_total',$rows[0]);
	$t->hasKey('children_amount',$rows[0]);
	$state=$query->executionState();
	$t->notEmpty($state['eager_relations']);
	$t->notEmpty($state['eager_counts']);
	$t->notEmpty($state['eager_aggregates']);
	$restored=RepositoryQuery::fromExecutionState($state);
	$t->instanceOf(RepositoryQuery::class,$restored);
	$t->notEmpty($queryInternals->invokeWithArguments('eagerRelationDescriptors'));
	$t->notEmpty($queryInternals->invokeWithArguments('eagerCountDescriptors'));
	$t->notEmpty($queryInternals->invokeWithArguments('eagerAggregateDescriptors'));
	$t->same(['name','id','owner_id'],$queryInternals->invokeWithArguments('columnsWithEagerParentKeys',[['name']]));
	$t->same('*',$queryInternals->invokeWithArguments('columnsWithEagerParentKeys',['*']));
	$t->same([],$t->nonPublic(DpRepositoryQueryDeepParent::query())->invokeWithArguments('applyEagerRelations',[[]]));

	$broken=DpRepositoryQueryDeepParent::query();
	$brokenInternals=$t->nonPublic($broken);
	$brokenInternals->writeProperty('eagerRelations',[['relation'=>new stdClass()]]);
	$brokenInternals->writeProperty('eagerCounts',[['relation'=>new stdClass()]]);
	$brokenInternals->writeProperty('eagerAggregates',[['relation'=>new stdClass()]]);
	$t->same([['id'=>1]],$brokenInternals->invokeWithArguments('applyEagerRelations',[[['id'=>1]]]));
	$t->same([],$brokenInternals->invokeWithArguments('eagerRelationDescriptors'));
	$t->same([],$brokenInternals->invokeWithArguments('eagerCountDescriptors'));
	$t->same([],$brokenInternals->invokeWithArguments('eagerAggregateDescriptors'));
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage');

test('repository query deep coverage runs chunk each and by-id row and record callbacks',static function(Context $t): void {
	dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query()->with('children');
	$seen=[];
	$t->same(2,$query->chunk(3,static function(array $rows,int $page,int $processed)use(&$seen): bool { $seen[]=['chunk',$page,$processed,count($rows)]; return false; }));
	$t->same(1,$query->each(static function(array $row,int $processed,int $page,int $index)use(&$seen): bool { $seen[]=['each',$processed,$page,$index]; return $processed<1; },3));
	$t->same(2,$query->chunkRecords(3,static function(array $records,int $page,int $processed)use(&$seen): bool { $seen[]=['records',$page,$processed,count($records)]; return false; }));
	$t->same(1,$query->eachRecord(static function(mixed $record,int $processed,int $page,int $index)use(&$seen): bool { $seen[]=['each-record',$processed,$page,$index]; return $processed<1; },3));
	$t->same(2,$query->chunkById(3,static function(array $rows,mixed $cursor,int $processed)use(&$seen): bool { $seen[]=['by-id',$cursor,$processed,count($rows)]; return false; },'id'));
	$t->same(1,$query->eachById(static function(array $row,int $processed,mixed $cursor,int $index)use(&$seen): bool { $seen[]=['each-by-id',$processed,$cursor,$index]; return $processed<1; },3,'id'));
	$t->same(2,$query->chunkRecordsById(3,static function(array $records,mixed $cursor,int $processed)use(&$seen): bool { $seen[]=['records-by-id',$cursor,$processed,count($records)]; return false; },'id'));
	$t->same(1,$query->eachRecordById(static function(mixed $record,int $processed,mixed $cursor,int $index)use(&$seen): bool { $seen[]=['each-record-by-id',$processed,$cursor,$index]; return $processed<1; },3,'id'));
	$t->notEmpty($seen);
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('repository query deep coverage exercises sole find fail-fast and queued transforms',static function(Context $t): void {
	$state=dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query()->with('children');
	$queryInternals=$t->nonPublic($query);
	$t->throws(static fn()=>$query->sole(),Throwable::class);
	dp_repository_query_returns($state,'deep_parents',[['id'=>1,'name'=>'Only','owner_id'=>null]]);
	$t->same('Only',$query->sole()['name']);
	$t->same('Only',$query->soleRecord()['name']);
	$t->same('Only',$query->soleValue('name'));
	dp_repository_query_returns($state,'deep_parents',[]);
	$t->throws(static fn()=>$query->firstOrFail(),Throwable::class,'firstOrFail should throw');
	$t->throws(static fn()=>$query->firstRecordOrFail(),Throwable::class,'firstRecordOrFail should throw');
	$t->throws(static fn()=>$query->findOrFail(99),Throwable::class,'findOrFail should throw');
	$t->throws(static fn()=>$query->findHydratedOrFail(99),Throwable::class,'findHydratedOrFail should throw');
	$t->throws(static fn()=>$query->findRecordOrFail(99),Throwable::class,'findRecordOrFail should throw');

	$t->same('marker',$queryInternals->invokeWithArguments('transformQueuedRepositoryResult',['marker']));
	$t->same([],$queryInternals->invokeWithArguments('transformQueuedRepositoryResult',[[]]));
	$t->same([],$queryInternals->invokeWithArguments('queuedRowsFromResult',['bad']));
	$t->same([],$queryInternals->invokeWithArguments('queuedRowsFromResult',[[]]));
	$t->same([['id'=>1]],$queryInternals->invokeWithArguments('queuedRowsFromResult',[[['id'=>1],'bad']]));
	$t->same([['id'=>1]],$queryInternals->invokeWithArguments('queuedRowsFromResult',[['id'=>1]]));
	$t->same(null,$queryInternals->invokeWithArguments('queuedRowFromResult',['bad']));
	$t->same(null,$queryInternals->invokeWithArguments('queuedRowFromResult',[[]]));
	$t->same(null,$queryInternals->invokeWithArguments('queuedRowFromResult',[['bad']]));
	$t->same(['id'=>1],$queryInternals->invokeWithArguments('queuedRowFromResult',[[['id'=>1]]]));
	$t->same(['id'=>1],$queryInternals->invokeWithArguments('queuedRowFromResult',[['id'=>1]]));
	$t->same([],$queryInternals->invokeWithArguments('hydrateQueuedRepositoryRows',[[]]));
	$t->same(null,$queryInternals->invokeWithArguments('hydrateQueuedRepositoryRow',[[]]));
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage');

test('repository query deep coverage covers private descriptor pluck restore money and cache helpers',static function(Context $t): void {
	dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query();
	$queryInternals=$t->nonPublic($query);
	$t->same(null,$queryInternals->invokeWithArguments('callableDescriptor',[null]));
	$t->same('DpRepositoryQueryDeepInvoker::callback',$queryInternals->invokeWithArguments('callableDescriptor',[[DpRepositoryQueryDeepInvoker::class,'callback']]));
	$t->same('DpRepositoryQueryDeepInvoker::callback',$queryInternals->invokeWithArguments('callableDescriptor',[[new DpRepositoryQueryDeepInvoker(),'callback']]));
	$t->same('strlen',$queryInternals->invokeWithArguments('callableDescriptor',['strlen']));
	$t->same('Closure',$queryInternals->invokeWithArguments('callableDescriptor',[static fn()=>true]));
	$t->same(DpRepositoryQueryDeepInvoker::class,$queryInternals->invokeWithArguments('callableDescriptor',[new DpRepositoryQueryDeepInvoker()]));
	$t->same(DpRepositoryQueryDeepInvoker::class,$queryInternals->invokeWithArguments('hydratorDescriptor',[new DpRepositoryQueryDeepInvoker()]));
	foreach(['class-name',['one'],null,true,2,1.5] as $descriptor){
		$t->same($descriptor,$queryInternals->invokeWithArguments('hydratorDescriptor',[$descriptor]));
	}
	$resource=fopen('php://temp','r+');
	$t->same('resource (stream)',$queryInternals->invokeWithArguments('hydratorDescriptor',[$resource]));
	fclose($resource);
	$t->same('*',$queryInternals->invokeWithArguments('keyColumns',['id',null]));
	$t->same('*',$queryInternals->invokeWithArguments('keyColumns',['id','*']));
	$t->same(['name','id'],$queryInternals->invokeWithArguments('keyColumns',['id','name']));
	$t->same(['name','id'],$queryInternals->invokeWithArguments('keyColumns',['id',['name','id',''] ]));
	$rows=[['id'=>1,'name'=>'A'],['id'=>null,'name'=>'Skip'],'bad',['name'=>'No key']];
	$t->same(['A','Skip','No key'],$queryInternals->invokeWithArguments('pluckRows',[$rows,'name',null]));
	$t->same(['1'=>'A'],$queryInternals->invokeWithArguments('pluckRows',[$rows,'name','id']));
	$t->same(['1'=>['id'=>1,'name'=>'A']],$queryInternals->invokeWithArguments('keyRowsBy',[$rows,'id']));

	$queryInternals->invokeWithArguments('restoreEagerRelations',[['bad',['named_relation'=>'children','records'=>true,'columns'=>'*'],['named_relation'=>'owner','records'=>false]]]);
	$queryInternals->invokeWithArguments('restoreEagerAggregates',[['bad',['named_relation'=>'children','function'=>'sum','column'=>'amount','alias'=>'sum_amount','distinct'=>true]]]);
	$queryInternals->invokeWithArguments('restoreEagerCounts',[['bad',['named_relation'=>'children','alias'=>'child_count']]]);
	$t->notEmpty($queryInternals->invokeWithArguments('eagerRelationDescriptors'));
	$t->notEmpty($queryInternals->invokeWithArguments('eagerAggregateDescriptors'));
	$t->notEmpty($queryInternals->invokeWithArguments('eagerCountDescriptors'));
	$queryInternals->invokeWithArguments('restoreCompiledTransforms',[['bad',['amount_column'=>'amount','currency_column'=>'currency','target_column'=>'money']],['bad',['source_column'=>'amount','target_column'=>'stored']]]);

	foreach(['=','>','>=','<','<='] as $operator){
		$t->instanceOf(RepositoryQuery::class,$t->nonPublic(DpRepositoryQueryDeepParent::query())->invokeWithArguments('whereMoneyCompare',['amount',10,$operator,null,'USD']));
	}
	$t->instanceOf(RepositoryQuery::class,$t->nonPublic(DpRepositoryQueryDeepParent::query())->invokeWithArguments('whereMoneyCompare',['amount',Money::fromMinor(1000,'USD'),'=','currency',null]));
	$t->throws(static fn()=>$t->nonPublic(DpRepositoryQueryDeepParent::query())->invokeWithArguments('whereMoneyCompare',['amount',10,'!=',null,'USD']),Throwable::class);
	$t->same([],$queryInternals->invokeWithArguments('normalizeTraceNames',[null,true]));
	$t->same(['named','other'],$queryInternals->invokeWithArguments('normalizeTraceNames',[['lazy',' named ',2,'','named','other'],true]));
	$t->same(['lazy'],$queryInternals->invokeWithArguments('normalizeTraceNames',['lazy',false]));
	$t->same([],$queryInternals->invokeWithArguments('invalidationNamesFromValue',[true]));
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage');

test('repository query deep coverage closes constructor projection mapping key and callback completion branches',static function(Context $t): void {
	$state=dp_repository_query_scenario($t);
	$t->throws(static fn()=>new RepositoryQuery(stdClass::class),Throwable::class);
	$query=DpRepositoryQueryDeepParent::query();
	$t->instanceOf(RepositoryQuery::class,$query->projection('listing'));
	$t->instanceOf(RepositoryQuery::class,$query->asStoredMoney([]));
	$t->throws(static fn()=>DpRepositoryQueryDeepNoKey::query()->whereKey(1),Throwable::class);
	dp_repository_query_returns($state,'deep_parents',[]);
	$t->throws(static fn()=>DpRepositoryQueryDeepParent::query()->sole(),Throwable::class);
	$t->throws(static fn()=>DpRepositoryQueryDeepParent::query()->firstOrCreate([]),Throwable::class);
	$t->throws(static fn()=>DpRepositoryQueryDeepParent::query()->updateOrCreate([]),Throwable::class);

	$state=dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query();
	$queryInternals=$t->nonPublic($query);
	$t->same(2,$query->each(static fn(array $row): bool=>true,3));
	$t->same(2,$query->eachRecord(static fn(mixed $record): bool=>true,3));
	$t->same(2,$query->eachById(static fn(array $row): bool=>true,3,'id'));
	$t->same(2,$query->eachRecordById(static fn(mixed $record): bool=>true,3,'id'));

	$relation=DpRepositoryQueryDeepParent::relationNamed('children');
	$t->same(null,$queryInternals->invokeWithArguments('relationConstraintSpec',[$relation,null]));
	$spec=(new QuerySpec())->whereEq('id',1);
	$t->isTrue($spec===$queryInternals->invokeWithArguments('relationConstraintSpec',[$relation,static fn(RepositoryQuery $related): QuerySpec=>$spec]));
	$t->instanceOf(QuerySpec::class,$queryInternals->invokeWithArguments('relationConstraintSpec',[$relation,static function(RepositoryQuery $related): void { $related->whereGt('id',0); }]));
	$t->isTrue($relation===$queryInternals->invokeWithArguments('resolveRelationForQuery',[$relation]));

	$t->notEmpty($queryInternals->invokeWithArguments('transformQueuedRepositoryResult',[[['id'=>1]]]));
	$t->notEmpty($queryInternals->invokeWithArguments('transformQueuedRepositoryResult',[['id'=>1]]));
	$t->notEmpty($queryInternals->invokeWithArguments('hydrateQueuedRepositoryRows',[[['id'=>1]]]));
	$t->notEmpty($queryInternals->invokeWithArguments('hydrateQueuedRepositoryRow',[['id'=>1]]));
	$guarded=$query->cacheName('repository-query-deep');
	$guardedInternals=$t->nonPublic($guarded);
	$t->same(['repository-query-deep'],$guardedInternals->invokeWithArguments('namedReadCacheNames'));
	$guardedInternals->invokeWithArguments('warnIfWriteInvalidationMissing',['update',null]);
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('repository query deep coverage runs queued success failure sole value and pagination callbacks',static function(Context $t): void {
	$state=dp_repository_query_scenario($t);
	$query=DpRepositoryQueryDeepParent::query();
	$received=[];
	$query->queueFirstOrFail(static function(array $row)use(&$received): void { $received['first']=$row['name']; });
	$query->queueFirstRecordOrFail(static function(mixed $row)use(&$received): void { $received['first_record']=$row['name']; });
	$query->queueFindOrFail(1,static function(array $row)use(&$received): void { $received['find']=$row['name']; });
	$query->queueFindHydratedOrFail(1,static function(mixed $row)use(&$received): void { $received['find_record']=$row['name']; });
	$query->queueValue('name',static function(mixed $value)use(&$received): void { $received['value']=$value; });
	$t->same('Alpha',$received['first']);
	$t->same('Alpha',$received['first_record']);
	$t->same('Alpha',$received['find']);
	$t->same('Alpha',$received['find_record']);
	$t->same('Alpha',$received['value']);

	dp_repository_query_returns($state,'deep_parents',[]);
	$t->throws(static fn()=>$query->queueFirstOrFail(static fn(array $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueFirstRecordOrFail(static fn(mixed $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueFindOrFail(99,static fn(array $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueFindHydratedOrFail(99,static fn(mixed $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueSole(static fn(array $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueSoleRecord(static fn(mixed $row)=>$row),Throwable::class);

	$state=dp_repository_query_scenario($t);
	$t->throws(static fn()=>$query->queueSole(static fn(array $row)=>$row),Throwable::class);
	$t->throws(static fn()=>$query->queueSoleRecord(static fn(mixed $row)=>$row),Throwable::class);
	dp_repository_query_returns($state,'deep_parents',[['id'=>1,'name'=>'Only','owner_id'=>null]]);
	$query->queueSole(static function(array $row)use(&$received): void { $received['sole']=$row['name']; });
	$query->queueSoleRecord(static function(mixed $row)use(&$received): void { $received['sole_record']=$row['name']; });
	$t->same('Only',$received['sole']);
	$t->same('Only',$received['sole_record']);

	$state=dp_repository_query_scenario($t);
	$pages=[];
	$query->queuePaginate(static function(mixed $page)use(&$pages): void { $pages[]=$page; },0,999);
	$query->queuePaginateHydrated(static function(mixed $page)use(&$pages): void { $pages[]=$page; },0,999);
	$t->same(2,count($pages));
	$state->put('select_queue_return',false);
	$t->isFalse($query->queuePaginate(static fn(mixed $page)=>null));
	$t->isFalse($query->queuePaginateHydrated(static fn(mixed $page)=>null));
})->tag('sql','repository-query','deep-coverage')->group('framework-coverage')->maxMillis(10000);
