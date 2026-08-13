<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\MutationResult;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\Record;
use Dataphyre\Database\Relation;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
function dp_record_state(): TestState { return TestState::channel('sql.record-page-result'); }
if(!function_exists('sql_select')){ function sql_select(mixed ...$arguments): array|false { return dp_record_state()->get('rows',[]); } }
if(!function_exists('sql_count')){ function sql_count(mixed ...$arguments): int { return count(dp_record_state()->get('rows',[])); } }
if(!function_exists('sql_update')){ function sql_update(mixed ...$arguments): int|false { return 1; } }
if(!function_exists('sql_delete')){ function sql_delete(mixed ...$arguments): int|false { return 1; } }
if(!function_exists('sql_insert')){ function sql_insert(mixed ...$arguments): int|false { return 1; } }
if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG',['datacenter'=>'dc']); }
if(!defined('DP_SQL_CFG')){ define('DP_SQL_CFG',['default_cluster'=>'primary','datacenters'=>['dc'=>['dbms_clusters'=>['primary'=>['dbms'=>'sqlite']]]]]); }
framework(['currency','sql']);
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static function clear_last_query_error(): void {} public static function hydrate_missing_structure_from_definition(string $table): bool { return false; } public static function invalidate_cache(array|string $value): void {} public static function table_schema(string $table): ?\\Dataphyre\\Database\\TableSchema { return null; } public static function table_definition(string $table): ?\\Dataphyre\\Database\\TableDefinition { return null; } }');
}

final class DpRecordRelatedRepository extends TableRepository {
	protected static function table(): string { return 'record_related'; }
	protected static function schema(): ?TableSchema { return new TableSchema('record_related',['id','name'],[],'id'); }
}

final class DpRecordRepository extends TableRepository {
	public static mixed $findRecordResult=null;
	public static ?MutationResult $mutationResult=null;
	protected static function table(): string { return 'record_items'; }
	protected static function schema(): ?TableSchema { return new TableSchema('record_items',['id','name','related_id','lock_version','amount','currency'],[],'id'); }
	public static function relationNamed(string $name): Relation { return Relation::belongsTo(DpRecordRelatedRepository::class,'related_id','id'); }
	public static function findRecord(mixed $id,array|string $columns='*',mixed $hydrator=null,bool|array|string|null $caching=null): mixed { return self::$findRecordResult; }
	public static function updateById(mixed $id,array $fields,bool|array|null $clearCache=null): MutationResult { return self::$mutationResult ?? new MutationResult('update',true,1,1,['repository'=>self::class]); }
	public static function updateByIdWithVersion(mixed $id,array $fields,int $expectedVersion,string $versionColumn='lock_version',int $bump=1,bool|array|null $clearCache=null): MutationResult { return self::$mutationResult ?? new MutationResult('update_with_version',true,1,1,['repository'=>self::class]); }
	public static function deleteById(mixed $id,bool|array|null $clearCache=null): MutationResult { return self::$mutationResult ?? new MutationResult('delete',true,1,1,['repository'=>self::class]); }
}

class DpRecordChild extends Record {
	public static function fromRow(array $row,?TableSchema $schema=null,?string $repositoryClass=null,?string $primaryKey=null): self { return new self($row,$schema,$repositoryClass,$primaryKey); }
}
class DpRecordBadFactory extends Record {
	public static function fromRow(array $row,?TableSchema $schema=null,?string $repositoryClass=null,?string $primaryKey=null): Record { return new Record($row,$schema,$repositoryClass,$primaryKey); }
}
class DpRecordProtectedFactory extends Record {
	protected static function fromRow(array $row): self { return new self($row); }
}

function dp_record_reset(Context $t): void {
	$t->state('sql.record-page-result',['rows'=>[['id'=>2,'name'=>'Related']]]);
	DpRecordRepository::$findRecordResult=null;
	DpRecordRepository::$mutationResult=null;
}

test('record page result deep coverage exposes immutable record value and projection surfaces',static function(Context $t): void {
	dp_record_reset($t);$schema=new TableSchema('record_items',['id','name','related_id','lock_version','amount','currency'],[],'id');
	$record=new Record(['id'=>1,'name'=>'Ada','nullable'=>null,'related_id'=>2,'lock_version'=>'3','amount'=>null,'currency'=>'USD'],$schema,DpRecordRepository::class);
	$t->same(DpRecordRepository::class,$record->repositoryClass());$t->same($schema,$record->schema());$t->same('id',$record->primaryKeyName());$t->same(1,$record->id());
	$t->isTrue($record->has('nullable'));$t->same('fallback',$record->get('nullable','fallback'));$t->same('fallback',$record->get('missing','fallback'));
	$record->money('amount');
	$storedRecord=new Record(['original_amount_minor'=>null,'original_currency'=>null,'base_amount_minor'=>null,'base_currency'=>null,'exchange_rate'=>null,'exchange_source'=>null,'exchange_time'=>null,'exchange_base_currency'=>null],null,DpRecordRepository::class);
	$storedRecord->storedMoney();$storedRecord->storedMoney([]);
	$with=$record->with('status','active');$t->same('active',$with->get('status'));$t->same('Ready',$record->with(['state'=>'Ready'])->get('state'));$t->same('yes',$record->withRelation('loaded','yes')->get('loaded'));
	$t->throws(static fn()=>$record->with([0=>'bad']),Throwable::class);$t->throws(static fn()=>$record->with('bad-name',1),Throwable::class);
	$t->same(['id'=>1,'name'=>'Ada'],$record->only(['id','name','missing']));$t->same(['id'=>1,'name'=>'Ada'],$record->only(['id','name','missing']));
	$t->isFalse(array_key_exists('name',$record->except(['name'])));$t->isFalse(array_key_exists('name',$record->except(['name'])));
	$t->same($record->toArray(),$record->jsonSerialize());$t->same(7,count($record));$t->same($record->toArray(),iterator_to_array($record));
	$t->isTrue(isset($record['id']));$t->same(1,$record['id']);$t->same('Ada',$record->name);$t->isTrue(isset($record->nullable));
	$t->throws(static function()use($record): void{$record['x']=1;},LogicException::class);$t->throws(static function()use($record): void{unset($record['id']);},LogicException::class);
	$base=(new Record(['id'=>1],null,null,'id'))->with('name','Base');$t->same('Base',$base->get('name'));
	$child=(new DpRecordChild(['id'=>1],$schema,DpRecordRepository::class))->with('name','Child');$t->instanceOf(DpRecordChild::class,$child);
	$t->instanceOf(DpRecordProtectedFactory::class,(new DpRecordProtectedFactory(['id'=>1]))->with('name','Protected'));
	$t->throws(static fn()=>(new DpRecordBadFactory(['id'=>1]))->with('name','Bad'),LogicException::class);
})->tag('sql','record','page-result','deep-coverage')->group('framework-coverage');

test('record page result deep coverage resolves relations persistence refresh and versioned helpers',static function(Context $t): void {
	dp_record_reset($t);$schema=new TableSchema('record_items',['id','name','related_id','lock_version'],[],'id');$record=new Record(['id'=>1,'name'=>'Ada','related_id'=>2,'lock_version'=>3],$schema,DpRecordRepository::class);
	$relation=Relation::belongsTo(DpRecordRelatedRepository::class,'related_id','id');
	$record->relation($relation);$record->relationRecords($relation);$record->related($relation);$record->relatedRecords($relation);
	$record->relation('related');$record->relationRecords('related');
	DpRecordRepository::$findRecordResult=new Record(['id'=>1,'name'=>'Fresh'],$schema,DpRecordRepository::class);$t->same('Fresh',$record->refresh()?->get('name'));
	DpRecordRepository::$findRecordResult=null;$t->same(null,$record->refresh());
	$child=new DpRecordChild(['id'=>1,'lock_version'=>3],$schema,DpRecordRepository::class);DpRecordRepository::$findRecordResult=new Record(['id'=>1],$schema,DpRecordRepository::class);$t->throws(static fn()=>$child->refresh(),Throwable::class);
	DpRecordRepository::$findRecordResult=new DpRecordChild(['id'=>1,'lock_version'=>4],$schema,DpRecordRepository::class);$t->instanceOf(DpRecordChild::class,$child->refresh());
	DpRecordRepository::$mutationResult=new MutationResult('update',true,1,1);$t->isTrue($record->update(['name'=>'Grace'])->ok());$t->isTrue($record->delete()->ok());
	$t->same(3,$record->currentVersion());$t->isTrue($record->updateWithVersion(['name'=>'Grace'],3)->ok());$t->isTrue($record->updateWithVersionOrFail(['name'=>'Grace'],3)->ok());
	$t->isTrue($record->updateWithCurrentVersion(['name'=>'Grace'])->ok());$t->isTrue($record->updateWithCurrentVersionOrFail(['name'=>'Grace'])->ok());
	DpRecordRepository::$findRecordResult=new Record(['id'=>1,'name'=>'Reloaded','lock_version'=>4],$schema,DpRecordRepository::class);
	$t->same('Reloaded',$record->updateAndRefresh(['name'=>'Reloaded'])?->get('name'));$t->same('Reloaded',$record->updateWithVersionAndRefresh(['name'=>'Reloaded'],3)?->get('name'));$t->same('Reloaded',$record->updateWithCurrentVersionAndRefresh(['name'=>'Reloaded'])?->get('name'));
	DpRecordRepository::$mutationResult=new MutationResult('update',false,false,null,[],'mutation failed');$t->throws(static fn()=>$record->updateAndRefresh(['name'=>'No']),RuntimeException::class);
	DpRecordRepository::$mutationResult=new MutationResult('update_with_version',true,0,0);$t->throws(static fn()=>$record->updateWithVersionOrFail(['name'=>'No'],3),RuntimeException::class);
})->tag('sql','record','page-result','deep-coverage')->group('framework-coverage');

test('record page result deep coverage rejects invalid record operation metadata and version values',static function(Context $t): void {
	dp_record_reset($t);
	$detached=new Record(['id'=>1]);$t->throws(static fn()=>$detached->refresh(),Throwable::class);$t->throws(static fn()=>$detached->relation('named'),Throwable::class);$t->throws(static fn()=>$detached->relation('bad-name'),Throwable::class);
	$invalidRepo=new Record(['id'=>1],null,stdClass::class,'id');$t->throws(static fn()=>$invalidRepo->update([]),Throwable::class);$t->throws(static fn()=>$invalidRepo->relation('named'),Throwable::class);
	$noPrimary=new Record(['id'=>1],null,DpRecordRepository::class);$t->throws(static fn()=>$noPrimary->update([]),Throwable::class);
	$noId=new Record(['id'=>''],null,DpRecordRepository::class,'id');$t->throws(static fn()=>$noId->delete(),Throwable::class);
	foreach([
		new Record(['id'=>1],null,DpRecordRepository::class,'id'),
		new Record(['id'=>1,'lock_version'=>'bad'],null,DpRecordRepository::class,'id'),
		new Record(['id'=>1,'lock_version'=>-1],null,DpRecordRepository::class,'id'),
	] as $record){ $t->throws(static fn()=>$record->currentVersion(),Throwable::class); }
	$t->same(5,(new Record(['id'=>1,'lock_version'=>'5'],null,DpRecordRepository::class,'id'))->currentVersion());
	$t->throws(static fn()=>(new Record(['id'=>1,'lock_version'=>1],null,DpRecordRepository::class,'id'))->currentVersion('bad-name'),Throwable::class);
})->tag('sql','record','page-result','deep-coverage')->group('framework-coverage');

test('record page result deep coverage covers pagination arrays navigation mapping and caches',static function(Context $t): void {
	$page=new PageResult([0=>['id'=>1,'name'=>'Ada'],2=>['id'=>2,'name'=>'Grace'],4=>['name'=>'Skip']],7,2,3);
	$t->same(3,count($page->items()));$t->same(['id'=>1,'name'=>'Ada'],$page->first());$t->same(3,count($page->values()));$t->same(7,$page->total());$t->same(2,$page->page());$t->same(3,$page->perPage());
	$t->same(3,$page->lastPage());$t->isTrue($page->hasMorePages());$t->isTrue($page->hasPreviousPage());$t->same(4,$page->firstItemIndex());$t->same(6,$page->lastItemIndex());$t->same(3,count($page));$t->same(3,count(iterator_to_array($page)));
	$mapped=$page->map(static fn(array $item): array=>$item+['mapped'=>true]);$t->isTrue($mapped->first()['mapped']);
	$t->same(['Ada','Grace','Skip'],$page->pluck('name'));$t->same(['Ada','Grace','Skip'],(new PageResult($page->items(),7,2,3))->pluck('name'));$t->same(['Ada','Grace','Skip'],$page->pluck('name'));
	$t->same(['1'=>'Ada','2'=>'Grace'],$page->pluck('name','id'));$t->same(['1'=>'Ada','2'=>'Grace'],$page->pluck('name','id'));
	$t->same([1=>['id'=>1,'name'=>'Ada'],2=>['id'=>2,'name'=>'Grace']],$page->keyBy('id'));$t->same([1=>['id'=>1,'name'=>'Ada'],2=>['id'=>2,'name'=>'Grace']],$page->keyBy('id'));
	$serialized=$page->jsonSerialize();$t->same($serialized,$page->jsonSerialize());$t->same(3,$serialized['last_page']);
	$empty=new PageResult([],0,1,0);$t->same(null,$empty->first());$t->same(1,$empty->lastPage());$t->isFalse($empty->hasMorePages());$t->isFalse($empty->hasPreviousPage());$t->same(null,$empty->firstItemIndex());$t->same(null,$empty->lastItemIndex());$t->same(null,$empty->jsonSerialize()['last_item_index']);
})->tag('sql','record','page-result','deep-coverage')->group('framework-coverage');

test('record page result deep coverage extracts pagination values from ArrayAccess objects and scalars',static function(Context $t): void {
	$items=[['id'=>0,'name'=>'Array'],new ArrayObject(['id'=>1,'name'=>'ArrayObject']),(object)['id'=>2,'name'=>'Object'],42,(object)['name'=>'No key']];$page=new PageResult($items,5,1,10);
	$t->same(['Array','ArrayObject','Object',null,'No key'],$page->pluck('name'));$t->same(['Array','ArrayObject','Object',null,'No key'],$page->pluck('name'));
	$t->same(['0'=>'Array','1'=>'ArrayObject','2'=>'Object'],$page->pluck('name','id'));$t->same(['0'=>$items[0],'1'=>$items[1],'2'=>$items[2]],$page->keyBy('id'));$t->same(['0'=>$items[0],'1'=>$items[1],'2'=>$items[2]],$page->keyBy('id'));
})->tag('sql','record','page-result','deep-coverage')->group('framework-coverage');
