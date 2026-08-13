<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\Record;
use Dataphyre\Database\Relation;
use Dataphyre\Database\RepositoryQuery;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
function dp_relation_state(): TestState { return TestState::channel('sql.relation'); }
if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		return dp_relation_state()->get('select_result',false);
	}
}
if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static function clear_last_query_error(): void {} public static function hydrate_missing_structure_from_definition(string $table): bool { return false; } public static function invalidate_cache(string $table): void {} }');
}
framework(['sql']);

final class DpRelationDeepRepository extends TableRepository {
	protected static function table(): string { return 'relation_children'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema(
			'relation_children',
			['id','parent_id','owner_id','name','amount'],
			[],
			'id',
			['id'=>'integer','parent_id'=>'integer','owner_id'=>'integer','amount'=>'float']
		);
	}
	protected static function defaultReadCaching(): array { return ['enabled'=>false]; }
}

final class DpRelationDeepNoKeyRepository extends TableRepository {
	protected static function table(): string { return 'relation_without_key'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema('relation_without_key', ['name']);
	}
}

final class DpRelationDeepSpec extends QuerySpec {
	public function compile(bool $includeLock=true): array {
		return ['params'=>'ORDER BY relation_children.name', 'vars'=>'not-an-array'];
	}
}

final class DpRelationDeepPropertyObject {
	public mixed $children=null;
}

final class DpRelationDeepMagicObject {
	public array $assigned=[];
	public function __set(string $name, mixed $value): void { $this->assigned[$name]=$value; }
}

final class DpRelationDeepPlainObject {
	public string $name='plain';
}

/** @param list<mixed> $arguments */
function dp_relation_private(Context $t,Relation $relation,string $method,array $arguments=[]): mixed {
	return $t->nonPublic($relation)->invokeWithArguments($method,$arguments);
}

/** @param mixed $result */
function dp_relation_sql_result(mixed $result): void {
	dp_relation_state()->put('select_result',$result);
}

function dp_relation_scenario(Context $t): void { $t->state('sql.relation',['select_result'=>false]); }

test('relation deep coverage validates descriptors identifiers and cardinality accessors', static function(Context $t): void {
	dp_relation_scenario($t);
	$belongs=Relation::belongsTo(DpRelationDeepRepository::class, ' owner_id ');
	$one=Relation::hasOne(DpRelationDeepRepository::class, ' parent_id ', ' id ');
	$many=Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', 'id');

	$t->same('belongs_to', $belongs->type());
	$t->same(DpRelationDeepRepository::class, $belongs->relatedRepository());
	$t->same('owner_id', $belongs->foreignKey());
	$t->same('id', $belongs->localKey());
	$t->same('owner_id', $belongs->parentKeyColumn());
	$t->same('id', $belongs->relatedLookupColumn());
	$t->same('has_one', $one->type());
	$t->same('id', $one->parentKeyColumn());
	$t->same('parent_id', $one->relatedLookupColumn());
	$t->same('has_many', $many->type());

	$t->throws(static fn()=>Relation::hasOne(stdClass::class, 'parent_id', 'id'), Throwable::class);
	$t->throws(static fn()=>Relation::belongsTo(DpRelationDeepNoKeyRepository::class, 'owner_id'), Throwable::class);
	$t->throws(static fn()=>Relation::hasMany(DpRelationDeepRepository::class, 'bad-key', 'id'), Throwable::class);
	$t->throws(static fn()=>Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', ''), Throwable::class);
	$t->throws(static fn()=>$many->attach([], 'bad-name'), Throwable::class);
})->tag('sql','relation','deep-coverage')->group('framework-coverage');

test('relation deep coverage loads singular plural and hydrated related values', static function(Context $t): void {
	dp_relation_scenario($t);
	$belongs=Relation::belongsTo(DpRelationDeepRepository::class, 'owner_id', 'id');
	$one=Relation::hasOne(DpRelationDeepRepository::class, 'parent_id', 'id');
	$many=Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', 'id');

	$t->same(null, $belongs->get(['owner_id'=>null]));
	$t->same(null, $one->get(['id'=>'']));
	$t->same([], $many->get(['id'=>null]));
	$t->same(null, $belongs->getRecords(['owner_id'=>'']));
	$t->same([], $many->getRecords(['id'=>'']));

	dp_relation_sql_result(['id'=>'2','parent_id'=>9,'name'=>'Owner']);
	$t->same(2, $belongs->get(['owner_id'=>2], ['id','name'], false)['id']);
	dp_relation_sql_result(['id'=>10,'parent_id'=>'1','name'=>'Only']);
	$t->same(10, $one->get((object)['id'=>1], '*', false)['id']);
	dp_relation_sql_result([
		['id'=>'10','parent_id'=>'1','name'=>'A'],
		['id'=>'11','parent_id'=>'1','name'=>'B'],
	]);
	$t->same(2, count($many->get(new Record(['id'=>1]), 'name', false, static function(RepositoryQuery $query): void {
		$query->whereGt('id', 0);
	})));

	$hydrator=static fn(array $row, ?TableSchema $_schema=null): string=>'hydrated-'.$row['id'];
	dp_relation_sql_result(['id'=>'2','parent_id'=>9,'name'=>'Owner']);
	$t->same('hydrated-2', $belongs->getRecords(['owner_id'=>2], '*', $hydrator, false));
	dp_relation_sql_result([
		['id'=>'10','parent_id'=>'1','name'=>'A'],
		['id'=>'11','parent_id'=>'1','name'=>'B'],
	]);
	$t->same(['hydrated-10','hydrated-11'], $many->getRecords(['id'=>1], '*', $hydrator, false, static function(RepositoryQuery $_query): RepositoryQuery {
		return DpRelationDeepRepository::query()->whereIn('parent_id', [1]);
	}));
})->tag('sql','relation','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('relation deep coverage eager maps rows records counts and aggregate defaults', static function(Context $t): void {
	dp_relation_scenario($t);
	$one=Relation::hasOne(DpRelationDeepRepository::class, 'parent_id', 'id');
	$many=Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', 'id');
	$parents=[
		'first'=>['id'=>1],
		'second'=>(object)['id'=>2],
		'missing'=>new Record(['id'=>3]),
		'empty'=>['id'=>null],
	];

	$t->same(['scalar'=>[]], $many->eager(['scalar'=>'not-a-parent']));
	$t->same(['scalar'=>null], $one->eager(['scalar'=>'not-a-parent']));
	$t->same(['empty'=>[]], $many->eagerRecords(['empty'=>['id'=>'']]));

	dp_relation_sql_result([
		['id'=>'10','parent_id'=>'1','name'=>'A'],
		['id'=>'11','parent_id'=>'1','name'=>'B'],
		['id'=>'12','parent_id'=>'2','name'=>'C'],
		['id'=>'13','parent_id'=>null,'name'=>'Skipped'],
	]);
	$mapped=$many->eager($parents, ['name','parent_id','', 'name'], false);
	$t->same([10,11], array_column($mapped['first'], 'id'));
	$t->same([12], array_column($mapped['second'], 'id'));
	$t->same([], $mapped['missing']);
	$t->same([], $mapped['empty']);

	dp_relation_sql_result([
		['id'=>'10','parent_id'=>'1','name'=>'A'],
		['id'=>'11','parent_id'=>'1','name'=>'B'],
	]);
	$singular=$one->eager([['id'=>1],['id'=>2]], '*', false);
	$t->same(10, $singular[0]['id']);
	$t->same(null, $singular[1]);

	dp_relation_sql_result([
		['id'=>'10','parent_id'=>'1','name'=>'A'],
		['id'=>'11','parent_id'=>'1','name'=>'B'],
	]);
	$records=$many->eagerRecords([['id'=>1],['id'=>2]], '*', null, false);
	$t->same(2, count($records[0]));
	$t->instanceOf(Record::class, $records[0][0]);
	$t->same([], $records[1]);

	dp_relation_sql_result([['id'=>'10','parent_id'=>'1','name'=>'A']]);
	$oneRecords=$one->eagerRecords([['id'=>1],['id'=>2]], '*', null, false);
	$t->instanceOf(Record::class, $oneRecords[0]);
	$t->same(null, $oneRecords[1]);

	$t->same(['x'=>0], $many->eagerCount(['x'=>'invalid']));
	dp_relation_sql_result([
		['parent_id'=>'1','aggregate_value'=>'2'],
		['parent_id'=>'2','aggregate_value'=>3],
	]);
	$counts=$many->eagerCount(['first'=>['id'=>1],'second'=>(object)['id'=>2],'none'=>['id'=>3],'invalid'=>'bad'], false);
	$t->same(['first'=>2,'second'=>3,'none'=>0,'invalid'=>0], $counts);
	dp_relation_sql_result([['parent_id'=>'1','aggregate_value'=>4]]);
	$t->same(4, $many->count(['id'=>1], false));

	$t->same(['a'=>0,'b'=>0], $many->eagerAggregate(['a'=>['id'=>null],'b'=>'bad'], ' count ', '*'));
	$t->same(['a'=>null], $many->eagerAggregate(['a'=>['id'=>'']], 'SUM', 'amount'));
	dp_relation_sql_result([
		['parent_id'=>'1','aggregate_value'=>'12.5'],
		'not-a-row',
		['parent_id'=>'','aggregate_value'=>99],
		['parent_id'=>null,'aggregate_value'=>99],
		['parent_id'=>'2'],
	]);
	$aggregates=$many->eagerAggregate(
		['first'=>['id'=>1],'second'=>(object)['id'=>2],'third'=>['id'=>3],'invalid'=>'bad'],
		'SUM',
		'amount',
		false,
		true,
		static fn(RepositoryQuery $query): RepositoryQuery=>$query
	);
	$t->same(12.5, $aggregates['first']);
	$t->same(null, $aggregates['second']);
	$t->same(null, $aggregates['third']);
	$t->same(null, $aggregates['invalid']);
	dp_relation_sql_result([['parent_id'=>'1','aggregate_value'=>'7.5']]);
	$t->same(7.5, $many->aggregate(['id'=>1], 'SUM', 'amount', false));
})->tag('sql','relation','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('relation deep coverage builds exists clauses and attaches every supported parent shape', static function(Context $t): void {
	dp_relation_scenario($t);
	$belongs=Relation::belongsTo(DpRelationDeepRepository::class, 'owner_id', 'id');
	$many=Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', 'id');

	[$exists,$vars]=$many->existsCondition('relation_parents');
	$t->same('EXISTS (SELECT 1 FROM relation_children WHERE relation_children.parent_id = relation_parents.id)', $exists);
	$t->same([], $vars);
	$constraint=(new QuerySpec())->whereEq('name', 'A')->orderBy('name')->limit(1);
	[$notExists,$vars]=$belongs->existsCondition('relation_parents', $constraint, false);
	$t->contains('NOT EXISTS (SELECT 1 FROM relation_children WHERE relation_children.id = relation_parents.owner_id AND name = ?)', $notExists);
	$t->same(['A'], $vars);
	[$custom,$badVars]=$many->existsCondition('relation_parents', new DpRelationDeepSpec());
	$t->contains(' ORDER BY relation_children.name)', $custom);
	$t->same([], $badVars);
	$t->throws(static fn()=>$many->existsCondition('bad table'), Throwable::class);

	dp_relation_sql_result([['id'=>10,'parent_id'=>1,'name'=>'A']]);
	$attached=$many->attach([['id'=>1]], ' children ', '*', false);
	$t->same(10, $attached[0]['children'][0]['id']);
	dp_relation_sql_result([['id'=>10,'parent_id'=>1,'name'=>'A']]);
	$attachedRecords=$many->attachRecords([['id'=>1]], 'children', '*', null, false);
	$t->instanceOf(Record::class, $attachedRecords[0]['children'][0]);
	dp_relation_sql_result([['parent_id'=>1,'aggregate_value'=>2]]);
	$t->same(2, $many->attachCount([['id'=>1]], 'child_count', false)[0]['child_count']);
	dp_relation_sql_result([['parent_id'=>1,'aggregate_value'=>'8.5']]);
	$t->same(8.5, $many->attachAggregate([['id'=>1]], 'child_sum', 'SUM', 'amount', false, true)[0]['child_sum']);

	$record=new Record(['id'=>1]);
	$property=new DpRelationDeepPropertyObject();
	$magic=new DpRelationDeepMagicObject();
	$plain=new DpRelationDeepPlainObject();
	$std=(object)['id'=>1];
	$parents=[
		'record'=>$record,
		'array'=>['id'=>1],
		'std'=>$std,
		'property'=>$property,
		'magic'=>$magic,
		'plain'=>$plain,
		'scalar'=>'unchanged',
	];
	$values=['record'=>1,'array'=>2,'std'=>3,'property'=>4,'magic'=>5];
	$all=dp_relation_private($t,$many, 'attachMap', [$parents, 'children', $values]);
	$t->same(1, $all['record']->get('children'));
	$t->same(2, $all['array']['children']);
	$t->same(3, $all['std']->children);
	$t->same(4, $all['property']->children);
	$t->same(5, $all['magic']->assigned['children']);
	$t->same('plain', $all['plain']->name);
	$t->isFalse($all['plain']===$plain);
	$t->same('unchanged', $all['scalar']);
})->tag('sql','relation','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('relation deep coverage closes private mapper normalization and accessor edges', static function(Context $t): void {
	dp_relation_scenario($t);
	$one=Relation::hasOne(DpRelationDeepRepository::class, 'parent_id', 'id');
	$many=Relation::hasMany(DpRelationDeepRepository::class, 'parent_id', 'id');

	$t->same('*', dp_relation_private($t,$many, 'relationColumns', ['*']));
	$t->same(['name','parent_id'], dp_relation_private($t,$many, 'relationColumns', ['name']));
	$t->same(['name','parent_id'], dp_relation_private($t,$many, 'relationColumns', [[' name ','','parent_id','name']]));
	$t->same(['one'=>[],'two'=>[]], dp_relation_private($t,$many, 'emptyEagerMap', [['one'=>1,'two'=>2]]));
	$t->same(['one'=>null], dp_relation_private($t,$one, 'emptyEagerMap', [['one'=>1]]));
	$t->same(['one'=>0], dp_relation_private($t,$many, 'emptyCountMap', [['one'=>1]]));
	$t->same(['one'=>0], dp_relation_private($t,$many, 'emptyAggregateMap', [['one'=>1], 'COUNT']));
	$t->same(['one'=>null], dp_relation_private($t,$many, 'emptyAggregateMap', [['one'=>1], 'AVG']));
	$t->same(0, dp_relation_private($t,$many, 'defaultAggregateValue', [' count ']));
	$t->same(null, dp_relation_private($t,$many, 'defaultAggregateValue', ['max']));

	$rows=[
		['parent_id'=>1,'name'=>'first'],
		(object)['parent_id'=>1,'name'=>'second'],
		['parent_id'=>'','name'=>'skip'],
	];
	$t->same('first', dp_relation_private($t,$one, 'mapRowsToParents', [[['id'=>1]], $rows])[0]['name']);
	$t->same(2, count(dp_relation_private($t,$many, 'mapRowsToParents', [[['id'=>1]], $rows])[0]));
	$t->same(1, dp_relation_private($t,$many, 'valueFrom', [new Record(['id'=>1]), 'id']));
	$t->same(2, dp_relation_private($t,$many, 'valueFrom', [['id'=>2], 'id']));
	$t->same(3, dp_relation_private($t,$many, 'valueFrom', [(object)['id'=>3], 'id']));
	$t->same(null, dp_relation_private($t,$many, 'valueFrom', [(object)[], 'id']));
	$t->same('', dp_relation_private($t,$many, 'constraintSuffix', [' ']));
	$t->same(' AND id = 1', dp_relation_private($t,$many, 'constraintSuffix', [' where id = 1 ']));
	$t->same(' GROUP BY id', dp_relation_private($t,$many, 'constraintSuffix', ['GROUP BY id']));
	$t->same('relation_children.parent_id', dp_relation_private($t,$many, 'qualifiedColumn', [' relation_children ', ' parent_id ']));
})->tag('sql','relation','deep-coverage')->group('framework-coverage');
