<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\Hydrators\CallbackRecordHydrator;
use Dataphyre\Database\Hydrators\ClassRecordHydrator;
use Dataphyre\Database\MutationResult;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\Relation;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

suite('SQL table repository read and mutation contracts')
	->contract('sql.table-repository', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:sql')
	->through('query-spec', 'schema', 'hydration', 'pagination', 'keyset-iteration', 'mutation', 'versioning', 'money-mapping')
	->isolation('case')
	->tag('sql', 'table-repository')
	->group('framework-coverage');

function dp_table_repository_state(): TestState {
	return TestState::channel('sql.table-repository');
}

/** @return array{payload:mixed,return:null|bool,invoke:bool} */
function dp_table_repository_kernel_entry(string $kind, mixed $default): array {
	$state=dp_table_repository_state();
	$kernel=$state->get('kernel',[]);
	$entries=$kernel[$kind] ?? [];
	$entry=$entries!==[] ? array_shift($entries) : ['payload'=>$default, 'return'=>null, 'invoke'=>true];
	$kernel[$kind]=$entries;
	$state->put('kernel',$kernel);
	if(!is_array($entry) || !array_key_exists('payload', $entry)){
		$entry=['payload'=>$entry, 'return'=>null, 'invoke'=>true];
	}
	return [
		'payload'=>$entry['payload'],
		'return'=>$entry['return'] ?? null,
		'invoke'=>array_key_exists('invoke', $entry) ? (bool)$entry['invoke'] : true,
	];
}

function dp_table_repository_reset_kernel(Context $t): TestState {
	return $t->state('sql.table-repository',['kernel'=>[],'calls'=>[]]);
}

function dp_table_repository_entries(string $kind, array $entries): void {
	$state=dp_table_repository_state();
	$state->put('kernel',array_replace($state->get('kernel',[]),[$kind=>$entries]));
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		$callback=$arguments[array_key_last($arguments)] ?? null;
		$queued=count($arguments)>=8 && is_callable($callback);
		$default=(bool)($arguments[4] ?? true)
			? [['id'=>1, 'name'=>'Alpha', 'group_id'=>10, 'amount'=>'12.5', 'version'=>1]]
			: ['id'=>1, 'name'=>'Alpha', 'group_id'=>10, 'amount'=>'12.5', 'version'=>1, 'aggregate_value'=>'2'];
		$entry=dp_table_repository_kernel_entry('select', $default);
		dp_table_repository_state()->append('calls',['select',$arguments]);
		if($queued){
			if($entry['invoke']){
				$callback($entry['payload']);
			}
			return $entry['return'];
		}
		return $entry['payload'];
	}
}
if(!function_exists('sql_count')){
	function sql_count(mixed ...$arguments): mixed {
		$callback=$arguments[array_key_last($arguments)] ?? null;
		$queued=count($arguments)>=6 && is_callable($callback);
		$entry=dp_table_repository_kernel_entry('count', 2);
		if($queued){
			if($entry['invoke']){
				$callback($entry['payload']);
			}
			return $entry['return'];
		}
		return $entry['payload'];
	}
}

/** @return mixed */
function dp_table_repository_mutation_kernel(string $kind, array $arguments, mixed $default): mixed {
	$callback=$arguments[array_key_last($arguments)] ?? null;
	$queued=is_callable($callback);
	$entry=dp_table_repository_kernel_entry($kind, $default);
	dp_table_repository_state()->append('calls',[$kind,$arguments]);
	if($queued){
		if($entry['invoke']){
			$callback($entry['payload']);
		}
		return $entry['return'];
	}
	return $entry['payload'];
}
if(!function_exists('sql_insert')){
	function sql_insert(mixed ...$arguments): mixed { return dp_table_repository_mutation_kernel('insert', $arguments, 3); }
}
if(!function_exists('sql_update')){
	function sql_update(mixed ...$arguments): mixed { return dp_table_repository_mutation_kernel('update', $arguments, 1); }
}
if(!function_exists('sql_delete')){
	function sql_delete(mixed ...$arguments): mixed { return dp_table_repository_mutation_kernel('delete', $arguments, 1); }
}
if(!function_exists('sql_upsert')){
	function sql_upsert(mixed ...$arguments): mixed { return dp_table_repository_mutation_kernel('upsert', $arguments, 1); }
}

if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {
		public static bool $hydrateMissing=false;
		public static bool $hydrateTable=false;
		public static array $calls=[];
		public static function clear_last_query_error(): void { self::$calls[]="clear"; }
		public static function hydrate_missing_structure_from_definition(string $table): bool { self::$calls[]="hydrate:".$table; return self::$hydrateMissing; }
		public static function hydrate_table_definition(string $table): bool { self::$calls[]="hydrate_table:".$table; return self::$hydrateTable; }
		public static function invalidate_cache(string|array $table): bool { self::$calls[]="invalidate:".(is_array($table) ? "array" : $table); return true; }
	}');
}

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'currency'=>true, 'sql'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_table_repository_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_table_repository_modules_root.'/core/kernel/autoloader.php';
require_once $dp_table_repository_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_table_repository_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_table_repository_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency', 'sql']);
if(!class_exists('dataphyre\\currency', false)){
	require_once $dp_table_repository_modules_root.'/currency/kernel/currency.main.php';
}

/** @return mixed */
function dp_table_repository_invoke(Context $t,string $repository,string $method,array $arguments=[]): mixed {
	return $t->nonPublic($repository)->invokeWithArguments($method,$arguments);
}

class DpTableRepositoryDeep extends TableRepository {
	public static mixed $hydratorDefinition=null;
	public static ?string $recordClassDefinition=null;
	public static array $moneyDefinitions=[];
	public static array $storedMoneyDefinitions=[];

	protected static function table(): string { return 'table_repository_deep'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema(
			'table_repository_deep',
			['id', 'name', 'group_id', 'amount', 'currency', 'version', 'lock_version', 'status', 'original_amount_minor', 'original_currency', 'base_amount_minor', 'base_currency', 'exchange_rate', 'exchange_source', 'exchange_time', 'exchange_base_currency'],
			['listing'=>['id', 'name']],
			'id',
			['id'=>'integer', 'group_id'=>'integer', 'amount'=>'float', 'version'=>'integer', 'lock_version'=>'integer']
		);
	}
	protected static function hydrator(): mixed { return self::$hydratorDefinition; }
	protected static function recordClass(): ?string { return self::$recordClassDefinition; }
	protected static function moneyColumns(): array { return self::$moneyDefinitions; }
	protected static function storedMoneyColumns(): array { return self::$storedMoneyDefinitions; }
	public static function parentOne(): Relation { return self::hasOne(self::class, 'owner_id'); }
	public static function parentMany(): Relation { return self::hasMany(self::class, 'owner_id'); }
	public static function explicitOne(): Relation { return self::hasOne(self::class, 'owner_id', 'id'); }
	public static function wrongRelation(): string { return 'not-a-relation'; }
	public static function requiredRelation(string $value): Relation { return self::belongsTo(self::class, $value); }
	public function instanceRelation(): Relation { return self::belongsTo(self::class, 'owner_id'); }
	protected static function hiddenRelation(): Relation { return self::belongsTo(self::class, 'owner_id'); }
}

class DpTableRepositoryNoSchema extends TableRepository {
	protected static function table(): string { return 'table_repository_no_schema'; }
	public static function missingOne(): Relation { return self::hasOne(DpTableRepositoryDeep::class, 'owner_id'); }
	public static function missingMany(): Relation { return self::hasMany(DpTableRepositoryDeep::class, 'owner_id'); }
}

final class DpTableRepositoryPlainRecord {
	public function __construct(public array $row, public ?TableSchema $schema=null) {}
}

final class DpTableRepositoryHydrator implements RecordHydrator {
	public function hydrate(array $row, ?TableSchema $schema=null): mixed { return ['hydrated'=>$row, 'schema'=>$schema?->table()]; }
}

test('table repository residual coverage forwards custom messages through sole and lookup guards',static function(Context $t): void {
	dp_table_repository_reset_kernel($t);
	$expect=static function(callable $operation,string $message)use($t): void {
		$caught='';
		try{
			$operation();
		}catch(\RuntimeException $exception){
			$caught=$exception->getMessage();
		}
		$t->contains($message,$caught);
	};

	dp_table_repository_entries('select', [['payload'=>[]]]);
	$expect(static fn()=>DpTableRepositoryDeep::sole('*',null,null,'sole-empty-message'),'sole-empty-message');
	dp_table_repository_entries('select', [['payload'=>[['id'=>1],['id'=>2]]]]);
	$expect(static fn()=>DpTableRepositoryDeep::sole('*',null,null,'sole-many-message'),'sole-many-message');

	$callback=static fn(mixed $value): mixed=>$value;
	dp_table_repository_entries('select', [['payload'=>[]]]);
	$expect(static fn()=>DpTableRepositoryDeep::queueSole('*',null,$callback,'end',null,'queue-sole-empty-message'),'queue-sole-empty-message');
	dp_table_repository_entries('select', [['payload'=>[['id'=>1],['id'=>2]]]]);
	$expect(static fn()=>DpTableRepositoryDeep::queueSole('*',null,$callback,'end',null,'queue-sole-many-message'),'queue-sole-many-message');

	dp_table_repository_entries('select', [['payload'=>null]]);
	$expect(static fn()=>DpTableRepositoryDeep::findOneByOrFail('name','missing','*',null,'find-one-message'),'find-one-message');
	dp_table_repository_entries('select', [['payload'=>null]]);
	$expect(static fn()=>DpTableRepositoryDeep::findOrFail(99,'*',null,'find-message'),'find-message');
	dp_table_repository_entries('select', [['payload'=>null]]);
	$expect(static fn()=>DpTableRepositoryDeep::findRecordOrFail(99,'*',null,null,'find-record-message'),'find-record-message');
})->tag('sql','sql-residual','table-repository','deep-coverage')->group('framework-coverage');

class DpTableRepositoryChunk extends DpTableRepositoryDeep {
	public static array $rowPages=[];
	public static array $recordPages=[];
	public static function all(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): array {
		return self::$rowPages!==[] ? array_shift(self::$rowPages) : [];
	}
	public static function allRecords(array|string $columns='*', ?QuerySpec $spec=null, mixed $hydrator=null, bool|array|string|null $caching=null): array {
		return self::$recordPages!==[] ? array_shift(self::$recordPages) : [];
	}
}

class DpTableRepositoryWorkflow extends DpTableRepositoryDeep {
	public static array $firstResults=[];
	public static ?MutationResult $createResult=null;
	public static ?MutationResult $updateResult=null;
	public static function first(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): ?array {
		return self::$firstResults!==[] ? array_shift(self::$firstResults) : null;
	}
	public static function create(array $fields, bool|array|null $clearCache=null): MutationResult {
		return self::$createResult ?? MutationResult::fromRaw('insert', 1, ['repository'=>self::class]);
	}
	public static function update(array $fields, QuerySpec $spec, bool|array|null $clearCache=null): MutationResult {
		return self::$updateResult ?? MutationResult::fromRaw('update', 1, ['repository'=>self::class]);
	}
}

class DpTableRepositoryWeird extends DpTableRepositoryDeep {
	public static function findKeyedByIds(array $ids, string $primaryKeyColumn, array|string $columns='*', bool|array|string|null $caching=null): array {
		return ['bad'=>7, 'good'=>['id'=>1, 'name'=>'valid']];
	}
	public static function queueFindKeyedByIds(array $ids, string $primaryKeyColumn, callable $callback, array|string $columns='*', string $queue='end', bool|array|string|null $caching=null): null|bool {
		$callback(['bad'=>7, 'good'=>['id'=>1, 'name'=>'valid']]);
		return null;
	}
}

class DpTableRepositoryWeirdFinder extends DpTableRepositoryDeep {
	public static function findManyByIds(array $ids, string $primaryKeyColumn, array|string $columns='*', bool|array|string|null $caching=null): array {
		return [7, ['id'=>1, 'name'=>'valid']];
	}
}

if(!class_exists('DpTableRepositoryConvention\\Repository\\UserRepository', false)){
	\Dataphyre\Test\define_test_symbols('namespace DpTableRepositoryConvention\\Repository;
		class UserRepository extends \\Dataphyre\\Database\\TableRepository { protected static function table(): string { return "users"; } }
		namespace DpTableRepositoryConvention\\Record;
		class UserRecord {}
		namespace DpTableRepositorySibling;
		class ThingRepository extends \\Dataphyre\\Database\\TableRepository { protected static function table(): string { return "things"; } }
		class ThingRecord {}
		namespace DpTableRepositoryEmpty;
		class Repository extends \\Dataphyre\\Database\\TableRepository { protected static function table(): string { return "empty"; } }');
}

test('table repository deep metadata hydrators schemas and retry branches', static function(Context $t): void {
	dp_table_repository_reset_kernel($t);
	$t->same(null, dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'schema'));
	$t->same(null, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'inferredRecordClass'));
	$t->same(null, dp_table_repository_invoke($t,DpTableRepositoryEmpty\Repository::class, 'inferredRecordClass'));
	$t->same(DpTableRepositoryConvention\Record\UserRecord::class, dp_table_repository_invoke($t,DpTableRepositoryConvention\Repository\UserRepository::class, 'inferredRecordClass'));
	$t->same(DpTableRepositorySibling\ThingRecord::class, dp_table_repository_invoke($t,DpTableRepositorySibling\ThingRepository::class, 'inferredRecordClass'));

	$t->instanceOf(Relation::class, DpTableRepositoryDeep::relationNamed('parentOne'));
	$t->instanceOf(Relation::class, DpTableRepositoryDeep::relationNamed('explicitOne'));
	foreach(['wrongRelation', 'requiredRelation', 'instanceRelation', 'hiddenRelation'] as $relation){
		$t->throws(static fn()=>DpTableRepositoryDeep::relationNamed($relation), Throwable::class);
	}
	$t->throws(static fn()=>DpTableRepositoryNoSchema::relationNamed('missingOne'), Throwable::class);
	$t->throws(static fn()=>DpTableRepositoryNoSchema::relationNamed('missingMany'), Throwable::class);

	DpTableRepositoryDeep::$recordClassDefinition=DpTableRepositoryPlainRecord::class;
	$t->instanceOf(ClassRecordHydrator::class, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'defaultHydrator'));
	DpTableRepositoryDeep::$recordClassDefinition=null;
	$custom=new DpTableRepositoryHydrator();
	$t->isTrue($custom===dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [$custom]));
	$t->instanceOf(CallbackRecordHydrator::class, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [static fn(array $row): array=>$row]));
	$t->instanceOf(DpTableRepositoryHydrator::class, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [DpTableRepositoryHydrator::class]));
	$t->instanceOf(ClassRecordHydrator::class, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [DpTableRepositoryPlainRecord::class]));
	foreach(['', 'MissingHydratorClass'] as $invalid){
		$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [$invalid]), Throwable::class);
	}
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedHydrator', [7]), Throwable::class);

	$t->same('id, name', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'columns', [['id', 'name']]));
	$t->same('id, name', dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'columns', [['id', 'name']]));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'fields', [[]]), Throwable::class);
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'fields', [[0=>'bad']]), Throwable::class);
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'fields', [['bad-name'=>1]]), Throwable::class);
	$t->same(['valid.name'=>1], dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'fields', [['valid.name'=>1]]));
	$t->throws(static fn()=>DpTableRepositoryNoSchema::projectionNamed('missing'), Throwable::class);

	\dataphyre\sql::$hydrateTable=true;
	$t->isTrue(dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'hydrateTable'));
	$t->isFalse(dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'hydrateTable'));
	$t->same('success', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'withSchemaHydration', [static fn(): string=>'success']));
	\dataphyre\sql::$hydrateMissing=false;
	$t->isFalse(dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'withSchemaHydration', [static fn(): bool=>false]));
	\dataphyre\sql::$hydrateMissing=true;
	$attempts=0;
	$t->same('retried', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'withSchemaHydration', [static function()use(&$attempts): mixed { return ++$attempts===1 ? false : 'retried'; }]));
	$t->same([true], dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'defaultReadCaching'));
	$t->isFalse(dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'defaultWriteInvalidation'));
	$t->isFalse(DpTableRepositoryNoSchema::requiresWriteWhere());

	dp_table_repository_entries('update', [['payload'=>1]]);
	$result=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'updateVersionWhere', [['name'=>'Updated'], 'lock_version', 1, (new QuerySpec())->whereEq('id', 1), false]);
	$t->same(1, $result);
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('table repository deep queued reads failures pagination and finder fallbacks', static function(Context $t): void {
	dp_table_repository_reset_kernel($t);
	$seen=[];
	dp_table_repository_entries('select', [['payload'=>['id'=>1], 'return'=>null]]);
	DpTableRepositoryDeep::queueFirstOrFail('*', null, static function(array $row)use(&$seen): array { $seen[]=$row; return $row; });
	$t->same(1, $seen[0]['id']);
	dp_table_repository_entries('select', [['payload'=>[], 'return'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::queueFirstOrFail('*', null, static fn(array $row): array=>$row), Throwable::class);
	dp_table_repository_entries('select', [['payload'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::firstOrFail(), Throwable::class);

	dp_table_repository_entries('select', [['payload'=>[]], ['payload'=>[['id'=>1]]], ['payload'=>[['id'=>1], ['id'=>2]]]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::sole(), Throwable::class);
	$t->same(1, DpTableRepositoryDeep::sole()['id']);
	$t->throws(static fn()=>DpTableRepositoryDeep::sole(), Throwable::class);
	dp_table_repository_entries('select', [['payload'=>[], 'return'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::queueSole('*', null, static fn(array $row): array=>$row), Throwable::class);
	dp_table_repository_entries('select', [['payload'=>[['id'=>1], ['id'=>2]], 'return'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::queueSole('*', null, static fn(array $row): array=>$row), Throwable::class);
	dp_table_repository_entries('select', [['payload'=>[['id'=>1]], 'return'=>null]]);
	$sole=null;
	DpTableRepositoryDeep::queueSole('*', null, static function(array $row)use(&$sole): array { return $sole=$row; });
	$t->same(1, $sole['id']);

	dp_table_repository_entries('select', [['payload'=>['aggregate_value'=>'4'], 'return'=>null]]);
	$aggregate=null;
	DpTableRepositoryDeep::queueAggregate('sum', 'amount', null, static function(mixed $value)use(&$aggregate): void { $aggregate=$value; });
	$t->same(4, $aggregate);
	dp_table_repository_entries('select', [['payload'=>['aggregate_value'=>'5']], ['payload'=>['aggregate_value'=>'3']]]);
	$t->same(5, DpTableRepositoryDeep::countColumn('id'));
	$t->same(3, DpTableRepositoryDeep::countDistinct('id'));
	dp_table_repository_entries('select', [['payload'=>false]]);
	$t->same([], DpTableRepositoryDeep::aggregateRowsBy('group_id', 'count'));
	dp_table_repository_entries('select', [['payload'=>[['group_id'=>10, 'aggregate_value'=>'2'], 'bad'], 'return'=>null]]);
	$grouped=[];
	DpTableRepositoryDeep::queueAggregateRowsBy('group_id', 'count', '*', null, static function(array $rows)use(&$grouped): void { $grouped=$rows; });
	$t->same(2, $grouped[0]['aggregate_value']);

	dp_table_repository_entries('count', [['payload'=>'bad', 'return'=>null]]);
	dp_table_repository_entries('select', [['payload'=>[['id'=>1]], 'return'=>null]]);
	$page=null;
	DpTableRepositoryDeep::queuePaginate('*', null, static function(PageResult $value)use(&$page): void { $page=$value; }, 0, 999);
	$t->instanceOf(PageResult::class, $page);
	$t->same(0, $page->total());
	dp_table_repository_entries('count', [['payload'=>1, 'return'=>false, 'invoke'=>false]]);
	dp_table_repository_entries('select', [['payload'=>[], 'return'=>null]]);
	$t->isFalse(DpTableRepositoryDeep::queuePaginate('*', null, static fn(PageResult $page): null=>null));

	$t->same([], DpTableRepositoryDeep::hydrateRows([7]));
	dp_table_repository_entries('select', [['payload'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::firstRecordOrFail(), Throwable::class);
	dp_table_repository_entries('select', [['payload'=>[['amount'=>9]]]]);
	$t->same(9.0, DpTableRepositoryDeep::soleValue('amount'));
	dp_table_repository_entries('select', [['payload'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::findOneByOrFail('id', 99), Throwable::class);

	$t->same([], DpTableRepositoryDeep::findManyByIds([null, '', ' '], 'id'));
	$empty=null;
	$t->same(null, DpTableRepositoryDeep::queueFindManyByIds([], 'id', static function(array $rows)use(&$empty): void { $empty=$rows; }));
	$t->same([], $empty);
	$t->same(['1'=>['id'=>1, 'name'=>'valid']], DpTableRepositoryWeirdFinder::findKeyedByIds([1], 'id'));
	$t->same(['good'=>['hydrated'=>['id'=>1, 'name'=>'valid'], 'schema'=>'table_repository_deep']], DpTableRepositoryWeird::findKeyedHydratedByIds([1], 'id', '*', new DpTableRepositoryHydrator()));
	$weird=[];
	DpTableRepositoryWeird::queueFindKeyedHydratedByIds([1], 'id', static function(array $rows)use(&$weird): void { $weird=$rows; }, '*', 'end', new DpTableRepositoryHydrator());
	$t->isFalse(array_key_exists('bad', $weird));

	foreach([
		static fn()=>DpTableRepositoryNoSchema::find(1),
		static fn()=>DpTableRepositoryNoSchema::queueFind(1, static fn()=>null),
		static fn()=>DpTableRepositoryNoSchema::queueFindOrFail(1, static fn()=>null),
	] as $failure){ $t->throws($failure, Throwable::class); }
	dp_table_repository_entries('select', [['payload'=>null], ['payload'=>null]]);
	$t->throws(static fn()=>DpTableRepositoryDeep::findOrFail(99), Throwable::class);
	$t->throws(static fn()=>DpTableRepositoryDeep::findRecordOrFail(99), Throwable::class);

	dp_table_repository_entries('count', [['payload'=>1, 'return'=>null]]);
	dp_table_repository_entries('select', [['payload'=>[['id'=>1, 'name'=>'A']], 'return'=>null]]);
	$hydratedPage=null;
	DpTableRepositoryDeep::queuePaginateHydrated('*', null, static function(PageResult $value)use(&$hydratedPage): void { $hydratedPage=$value; }, 1, 10, 'end', static fn(array $row): string=>$row['name']);
	$t->same(['A'], $hydratedPage->items());
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('table repository deep chunk and keyset iteration covers exhaustion short pages and stops', static function(Context $t): void {
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]], []];
	$t->same(2, DpTableRepositoryChunk::chunk(2, static fn(): null=>null));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::chunk(2, static fn(): null=>null));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(2, DpTableRepositoryChunk::chunk(2, static fn(): bool=>false));

	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(1, DpTableRepositoryChunk::each(static fn(array $row, int $count): bool=>$count<1, 2));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::each(static fn(): null=>null, 2));

	DpTableRepositoryChunk::$recordPages=[[['id'=>1], ['id'=>2]], []];
	$t->same(2, DpTableRepositoryChunk::chunkRecords(2, static fn(): null=>null));
	DpTableRepositoryChunk::$recordPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::chunkRecords(2, static fn(): null=>null));
	DpTableRepositoryChunk::$recordPages=[[['id'=>1], ['id'=>2]]];
	$t->same(2, DpTableRepositoryChunk::chunkRecords(2, static fn(): bool=>false));
	DpTableRepositoryChunk::$recordPages=[[['id'=>1], ['id'=>2]]];
	$t->same(1, DpTableRepositoryChunk::eachRecord(static fn(mixed $record, int $count): bool=>$count<1, 2));
	DpTableRepositoryChunk::$recordPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::eachRecord(static fn(): null=>null, 2));

	foreach(['ASC', 'DESC'] as $direction){
		DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]], [['id'=>3]]];
		$t->same(3, DpTableRepositoryChunk::chunkById(2, static fn(): null=>null, 'id', '*', null, null, $direction));
	}
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]], []];
	$t->same(2, DpTableRepositoryChunk::chunkById(2, static fn(): null=>null, 'id'));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(2, DpTableRepositoryChunk::chunkById(2, static fn(): bool=>false, 'id'));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::eachById(static fn(): null=>null, 2, 'id'));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(1, DpTableRepositoryChunk::eachById(static fn(array $row, int $count): bool=>$count<1, 2, 'id'));

	foreach(['ASC', 'DESC'] as $direction){
		DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]], [['id'=>3]]];
		$t->same(3, DpTableRepositoryChunk::chunkRecordsById(2, static fn(): null=>null, 'id', '*', null, static fn(array $row): array=>$row, null, $direction));
	}
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]], []];
	$t->same(2, DpTableRepositoryChunk::chunkRecordsById(2, static fn(): null=>null, 'id', '*', null, static fn(array $row): array=>$row));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(2, DpTableRepositoryChunk::chunkRecordsById(2, static fn(): bool=>false, 'id', '*', null, static fn(array $row): array=>$row));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1]]];
	$t->same(1, DpTableRepositoryChunk::eachRecordById(static fn(): null=>null, 2, 'id', '*', null, static fn(array $row): array=>$row));
	DpTableRepositoryChunk::$rowPages=[[['id'=>1], ['id'=>2]]];
	$t->same(1, DpTableRepositoryChunk::eachRecordById(static fn(mixed $record, int $count): bool=>$count<1, 2, 'id', '*', null, static fn(array $row): array=>$row));
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('table repository deep mutation workflows versioning batches and missing keys', static function(Context $t): void {
	dp_table_repository_reset_kernel($t);
	$t->same(2, DpTableRepositoryDeep::createMany([['name'=>'A'], 'skip', ['name'=>'B']])->processed());
	$t->same(2, DpTableRepositoryDeep::upsertMany([['name'=>'A'], 'skip', ['name'=>'B']])->processed());

	DpTableRepositoryWorkflow::$createResult=MutationResult::fromRaw('insert', 1);
	DpTableRepositoryWorkflow::$updateResult=MutationResult::fromRaw('update', 1);
	DpTableRepositoryWorkflow::$firstResults=[['id'=>1, 'name'=>'existing']];
	$t->same('existing', DpTableRepositoryWorkflow::firstOrCreate(['name'=>'A'])['name']);
	DpTableRepositoryWorkflow::$firstResults=[null, ['id'=>2, 'name'=>'created']];
	$t->same('created', DpTableRepositoryWorkflow::firstOrCreate(['name'=>'A'])['name']);
	DpTableRepositoryWorkflow::$firstResults=[null, null];
	$t->throws(static fn()=>DpTableRepositoryWorkflow::firstOrCreate(['name'=>'A']), Throwable::class);
	DpTableRepositoryWorkflow::$createResult=MutationResult::fromRaw('insert', false);
	DpTableRepositoryWorkflow::$firstResults=[null];
	$t->throws(static fn()=>DpTableRepositoryWorkflow::firstOrCreate(['name'=>'A']), Throwable::class);

	DpTableRepositoryWorkflow::$createResult=MutationResult::fromRaw('insert', 1);
	DpTableRepositoryWorkflow::$firstResults=[null, ['id'=>3, 'name'=>'created']];
	$t->same('created', DpTableRepositoryWorkflow::updateOrCreate(['name'=>'A'], ['status'=>'new'])['name']);
	DpTableRepositoryWorkflow::$firstResults=[null, null];
	$t->throws(static fn()=>DpTableRepositoryWorkflow::updateOrCreate(['name'=>'A'], ['status'=>'new']), Throwable::class);
	DpTableRepositoryWorkflow::$firstResults=[['id'=>1, 'name'=>'A']];
	$t->same(1, DpTableRepositoryWorkflow::updateOrCreate(['name'=>'A'], ['name'=>'A'])['id']);
	DpTableRepositoryWorkflow::$updateResult=MutationResult::fromRaw('update', 1);
	DpTableRepositoryWorkflow::$firstResults=[['id'=>1, 'name'=>'A'], ['id'=>1, 'name'=>'updated']];
	$t->same('updated', DpTableRepositoryWorkflow::updateOrCreate(['name'=>'A'], ['status'=>'updated'])['name']);
	DpTableRepositoryWorkflow::$firstResults=[['id'=>1, 'name'=>'A'], null];
	$t->throws(static fn()=>DpTableRepositoryWorkflow::updateOrCreate(['name'=>'A'], ['status'=>'updated']), Throwable::class);

	dp_table_repository_entries('update', [['payload'=>1], ['payload'=>1, 'return'=>null]]);
	$t->isTrue(DpTableRepositoryDeep::updateWithVersion(['name'=>'V'], (new QuerySpec())->whereEq('id', 1), 1)->ok());
	$queuedVersion=null;
	DpTableRepositoryDeep::queueUpdateWithVersion(['name'=>'V'], (new QuerySpec())->whereEq('id', 1), 1, static function(MutationResult $result)use(&$queuedVersion): void { $queuedVersion=$result; });
	$t->isTrue($queuedVersion->ok());

	$batch=null;
	$result=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queueMutationBatch', [
		'insert',
		['skip', ['name'=>'A'], ['name'=>'B']],
		static function($value)use(&$batch): void { $batch=$value; },
		static function(array $row, callable $callback): null|bool {
			if($row['name']==='A'){
				$callback(1);
				return null;
			}
			return false;
		},
	]);
	$t->isFalse($result);
	$t->same(3, $batch->requested());

	foreach([
		static fn()=>DpTableRepositoryNoSchema::updateById(1, ['name'=>'x']),
		static fn()=>DpTableRepositoryNoSchema::queueUpdateById(1, ['name'=>'x'], static fn()=>null),
		static fn()=>DpTableRepositoryNoSchema::updateByIdWithVersion(1, ['name'=>'x'], 1),
		static fn()=>DpTableRepositoryNoSchema::queueUpdateByIdWithVersion(1, ['name'=>'x'], 1, static fn()=>null),
		static fn()=>DpTableRepositoryNoSchema::incrementById(1, 'count'),
		static fn()=>DpTableRepositoryNoSchema::queueIncrementById(1, 'count', static fn()=>null),
		static fn()=>DpTableRepositoryNoSchema::decrementById(1, 'count'),
		static fn()=>DpTableRepositoryNoSchema::queueDecrementById(1, 'count', static fn()=>null),
		static fn()=>DpTableRepositoryNoSchema::deleteById(1),
		static fn()=>DpTableRepositoryNoSchema::queueDeleteById(1, static fn()=>null),
	] as $failure){ $t->throws($failure, Throwable::class); }
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('table repository deep scalar aggregate key and version helpers cover validation edges', static function(Context $t): void {
	$t->same('count.value', dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'resolvedCounterColumn', [' count.value ']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'resolvedCounterColumn', ['bad-name']), Throwable::class);
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedCounterAmount', ['amount', -1]), Throwable::class);
	$t->same(1.5, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedCounterAmount', ['amount', 1.5]));
	$versionFields=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'versionedUpdateFields', [['name'=>'A'], 'lock_version']);
	$t->contains('lock_version', $versionFields['mysql']);
	$t->same('"name"=?,"lock_version"="lock_version"+?', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'versionedUpdateFieldsForDbms', [['name'=>'A'], 'lock_version', 'postgresql']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedVersionBump', ['lock_version', 0]), Throwable::class);
	$t->same(2, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedVersionBump', ['lock_version', 2]));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedExpectedVersion', ['lock_version', -1]), Throwable::class);
	$t->same(0, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedExpectedVersion', ['lock_version', 0]));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'assertVersionColumnNotInFields', [['lock_version'=>1], 'lock_version']), Throwable::class);
	dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'assertVersionColumnNotInFields', [['name'=>'A'], 'lock_version']);

	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'assertMutationSucceeded', [MutationResult::fromRaw('update', false)]), Throwable::class);
	dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'assertMutationSucceeded', [MutationResult::fromRaw('update', 1)]);
	$t->same(['name', 'id'], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'keyColumns', ['id', 'name']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'resolvedKeyColumn'), Throwable::class);
	$t->same('id.value', dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'resolvedKeyColumn', ['id.value']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'resolvedKeyColumn', ['bad-name']), Throwable::class);
	$t->same('DESC', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizedKeysetDirection', [' desc ']));
	$t->same('ASC', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizedKeysetDirection', ['other']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'lastKeyFromRows', [[['name'=>'x']], 'id']), RuntimeException::class);
	$t->same(2, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'lastKeyFromRows', [[['id'=>1], ['id'=>2]], 'id']));

	$plucked=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'pluckRows', [[7, ['id'=>1, 'name'=>'A'], ['name'=>'No key'], ['id'=>null, 'name'=>'Null']], 'name', null]);
	$t->same(['A', 'No key', 'Null'], $plucked);
	$plucked=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'pluckRows', [[7, ['id'=>1, 'name'=>'A'], ['name'=>'No key']], 'name', 'id']);
	$t->same(['1'=>'A'], $plucked);
	$t->same(['1'=>['id'=>1]], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'keyRowsBy', [[7, ['name'=>'x'], ['id'=>null], ['id'=>1]], 'id']));

	dp_table_repository_reset_kernel($t);
	dp_table_repository_entries('select', [['payload'=>false], ['payload'=>['other'=>1]], ['payload'=>['aggregate_value'=>'2.5']]]);
	$t->isFalse(dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'aggregateValue', ['sum', 'amount']));
	$t->same(null, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'aggregateValue', ['sum', 'amount']));
	$t->same(2.5, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'aggregateValue', ['sum', 'amount']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateFunction', ['median']), Throwable::class);
	foreach([
		['', 'SUM', false], ['*', 'SUM', false],
	] as $arguments){ $t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'aggregateColumn', $arguments), Throwable::class); }
	$t->same('*', dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'aggregateColumn', ['*', 'COUNT', true]));
	$t->same('safe.column', dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'aggregateColumn', ['safe.column', 'SUM', false]));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'aggregateColumn', ['bad-name', 'SUM', false]), Throwable::class);
	$t->same(false, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateResult', ['SUM', false]));
	$t->same(3, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateResult', ['COUNT', '3']));
	$t->same(2, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateResult', ['SUM', '2']));
	$t->same(2.5, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateResult', ['AVG', '2.5']));
	$t->same('x', dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateResult', ['MIN', 'x']));
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'groupColumns', [['']]), Throwable::class);
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'groupColumns', [['bad-name']]), Throwable::class);
	$t->same(['safe.column'], dp_table_repository_invoke($t,DpTableRepositoryNoSchema::class, 'groupColumns', ['safe.column']));
	$t->same([7, ['aggregate_value'=>2]], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'normalizeAggregateRows', [[7, ['aggregate_value'=>'2']], 'COUNT']));
	$t->same(['a'=>2], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'groupedAggregateMap', ['group_id', [7, ['name'=>'x'], ['group_id'=>null], ['group_id'=>'a', 'aggregate_value'=>2]]]));
	$t->same([], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queuedAllRowsResult', [false]));
	$t->same([['id'=>1]], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queuedAllRowsResult', [['id'=>1]]));
	$t->same(null, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queuedFirstRowResult', [false]));
	$t->same(['id'=>1], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queuedFirstRowResult', [[[ 'id'=>1 ], 7]]));
	$t->same(['id'=>1], dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'queuedFirstRowResult', [['id'=>1]]));
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('table repository deep money mapping definitions caches skips and applications', static function(Context $t): void {
	DpTableRepositoryDeep::$moneyDefinitions=[
		0=>['amount_column'=>'amount', 'currency'=>'USD', 'target'=>'money'],
		'amount'=>'currency',
		'amount_fixed'=>['currency'=>'CAD', 'target_column'=>'fixed_money'],
	];
	$resolved=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedMoneyColumns');
	$t->same(3, count($resolved));
	$t->same($resolved, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedMoneyColumns'));
	DpTableRepositoryDeep::$moneyDefinitions=[0=>'currency'];
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedMoneyColumns'), Throwable::class);
	DpTableRepositoryDeep::$moneyDefinitions=['amount'=>7];
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedMoneyColumns'), Throwable::class);

	DpTableRepositoryDeep::$storedMoneyDefinitions=[
		0=>['target'=>'stored'],
		'stored_two'=>['prefix'=>'payment'],
	];
	$stored=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedStoredMoneyColumns');
	$t->same(2, count($stored));
	$t->same($stored, dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedStoredMoneyColumns'));
	DpTableRepositoryDeep::$storedMoneyDefinitions=[0=>'bad'];
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedStoredMoneyColumns'), Throwable::class);
	DpTableRepositoryDeep::$storedMoneyDefinitions=[0=>[]];
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedStoredMoneyColumns'), Throwable::class);
	DpTableRepositoryDeep::$storedMoneyDefinitions=['stored'=>'bad'];
	$t->throws(static fn()=>dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'resolvedStoredMoneyColumns'), Throwable::class);

	DpTableRepositoryDeep::$moneyDefinitions=['amount'=>['currency'=>'USD', 'target'=>'money']];
	DpTableRepositoryDeep::$storedMoneyDefinitions=['stored'=>[]];
	$skipped=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'applyRepositoryMoneyColumns', [['id'=>1]]);
	$t->same(['id'=>1], $skipped);
	$row=[
		'amount'=>'12.50',
		'original_amount_minor'=>null,
		'original_currency'=>'USD',
		'base_amount_minor'=>null,
		'base_currency'=>'USD',
		'exchange_rate'=>'1',
		'exchange_source'=>'test',
		'exchange_time'=>'2026-01-01 00:00:00',
		'exchange_base_currency'=>'USD',
	];
	$applied=dp_table_repository_invoke($t,DpTableRepositoryDeep::class, 'applyRepositoryMoneyColumns', [$row]);
	$t->isTrue(array_key_exists('stored', $applied));
	$t->notEmpty($applied['money']);
})->tag('sql', 'table-repository', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);
