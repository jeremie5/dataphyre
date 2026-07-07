<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(!function_exists('tracelog')){
	function tracelog(...$args): void {}
}
if(!defined('RUN_MODE')){
	define('RUN_MODE', 'unit_test');
}
if(!function_exists('dataphyre\\dp_define_module_config')){
	eval('namespace dataphyre; function dp_define_module_config(string $module, string $constant, array $defaults=[]): void { if(!defined($constant)){ define($constant, $defaults); } }');
}

require_once __DIR__.'/../Framework/SqlError.php';
require_once __DIR__.'/../Framework/QuerySpec.php';
require_once __DIR__.'/../Framework/TableDefinition.php';
require_once __DIR__.'/../Framework/TableSchema.php';
require_once __DIR__.'/../Framework/MutationResult.php';
require_once __DIR__.'/../Framework/MutationBatchResult.php';
require_once __DIR__.'/../Framework/PageResult.php';
require_once __DIR__.'/../Framework/Record.php';
require_once __DIR__.'/../Framework/CurrencyBridge.php';
require_once __DIR__.'/../Framework/TableRepository.php';
require_once __DIR__.'/../../currency/kernel/currency.main.php';
require_once __DIR__.'/../../currency/Framework/CurrencyManager.php';
require_once __DIR__.'/../../currency/Framework/ExchangeQuote.php';
require_once __DIR__.'/../../currency/Framework/ExchangeRates.php';
require_once __DIR__.'/../../currency/Framework/ExchangeSnapshot.php';
require_once __DIR__.'/../../currency/Framework/Currency.php';
require_once __DIR__.'/../../currency/Framework/Money.php';
require_once __DIR__.'/../../currency/Framework/StoredMoney.php';

use Dataphyre\Database\CurrencyBridge;
use Dataphyre\Database\MutationBatchResult;
use Dataphyre\Database\MutationResult;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\Record;
use Dataphyre\Database\TableDefinition;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Currency\Currency;
use Dataphyre\Currency\ExchangeQuote;
use Dataphyre\Currency\ExchangeRates;
use Dataphyre\Currency\ExchangeSnapshot;
use Dataphyre\Currency\StoredMoney;

if(!class_exists('DpSqlUnitDynamicMoneyRepository')){
	class DpSqlUnitDynamicMoneyRepository extends TableRepository {
		public static array $money=[];

		protected static function table(): string {
			return 'orders';
		}

		protected static function moneyColumns(): array {
			return self::$money;
		}

		public static function resolvedTargets(): array {
			return array_column(static::resolvedMoneyColumns(), 'target_column');
		}
	}
}

function dp_sql_unit_normalize_params(string|array $params): string|array {
	if(is_array($params)){
		return array_map('dp_sql_unit_normalize_params', $params);
	}
	return preg_replace('/\s+/', ' ', trim($params));
}

function dp_sql_unit_queryspec_compile_complex(): array {
	$compiled=(new QuerySpec())
		->whereEq('shop_id', 42)
		->whereIn('status', ['paid', 'open'])
		->whereAny(static function(QuerySpec $query): void {
			$query
				->whereNull('deleted_at')
				->whereGt('updated_at', '2026-01-01 00:00:00');
		})
		->groupBy(['shop_id', 'status', 'shop_id'])
		->havingRaw('COUNT(*) > ?', [1])
		->orderByDesc('updated_at')
		->limit(25)
		->offset(50)
		->compile(false);

	return [
		'params'=>dp_sql_unit_normalize_params($compiled['params']),
		'vars'=>$compiled['vars'],
	];
}

function dp_sql_unit_queryspec_empty_in_and_locking(): array {
	$compiled=(new QuerySpec())
		->whereIn('id', [])
		->forUpdate()
		->compile();

	return [
		'params'=>dp_sql_unit_normalize_params($compiled['params']),
		'vars'=>$compiled['vars'],
	];
}

function dp_sql_unit_queryspec_write_scope_flags(): array {
	$unscoped=(new QuerySpec())->requireWhereForWrite();
	$scoped=(new QuerySpec())->whereEq('id', 7)->requireWhereForWrite();
	$allowed=(new QuerySpec())->requireWhereForWrite()->allowUnscopedWrite();

	return [
		'unscoped_requires_where'=>$unscoped->writeRequiresWhere(),
		'unscoped_has_where'=>$unscoped->hasWhere(),
		'scoped_requires_where'=>$scoped->writeRequiresWhere(),
		'scoped_has_where'=>$scoped->hasWhere(),
		'allowed_requires_where'=>$allowed->writeRequiresWhere(true),
	];
}

function dp_sql_unit_table_definition_schema_shape(): array {
	$definition=TableDefinition::for('tenant.orders')
		->autoIncrement()
		->uuid('public_id')->notNull()->unique('public_id')
		->enum('status', ['draft', 'paid'])
		->json('metadata')
		->timestamp('created_at')->defaultCurrent()
		->projection('summary', ['id', 'public_id', 'status'])
		->index(['status', 'created_at'], 'idx_orders_status_created');

	$schema=$definition->schema();

	return [
		'table'=>$definition->table(),
		'columns'=>$definition->columns(),
		'primary_columns'=>$definition->primaryColumns(),
		'casts'=>$definition->castMap(),
		'projection'=>$schema->projection('summary'),
		'schema_primary_key'=>$schema->primaryKey(),
	];
}

function dp_sql_unit_table_definition_create_queries(): array {
	$queries=TableDefinition::for('tenant.orders')
		->autoIncrement()
		->string('email', 191)->notNull()
		->boolean('active')->default(true)
		->index(['email(32)'])
		->createQueries();

	return array_map(
		static fn(array $query): array => [
			'mysql'=>dp_sql_unit_normalize_params($query['mysql']),
			'postgresql'=>dp_sql_unit_normalize_params($query['postgresql']),
			'sqlite'=>dp_sql_unit_normalize_params($query['sqlite']),
			'required'=>$query['_required'] ?? null,
		],
		$queries
	);
}

function dp_sql_unit_table_schema_casts_and_projection(): array {
	$schema=new TableSchema(
		'orders',
		['id', 'total', 'active', 'metadata', 'created_at'],
		['listing'=>['id', 'total', 'active']],
		'id',
		[
			'id'=>'integer',
			'total'=>'float',
			'active'=>'boolean',
			'metadata'=>'json',
			'created_at'=>'datetime',
		]
	);

	$written=$schema->fields([
		'id'=>'15',
		'total'=>'19.95',
		'active'=>'yes',
		'metadata'=>['tags'=>['vip']],
	]);
	$read=$schema->castRow([
		'id'=>'15',
		'total'=>'19.95',
		'active'=>'0',
		'metadata'=>'{"tags":["vip"]}',
		'created_at'=>'2026-01-02 03:04:05',
	]);

	return [
		'columns'=>$schema->columns(['total', 'id', 'total']),
		'projection'=>$schema->projection('listing'),
		'written'=>$written,
		'read_types'=>[
			'id'=>gettype($read['id']),
			'total'=>gettype($read['total']),
			'active'=>gettype($read['active']),
			'metadata'=>gettype($read['metadata']),
			'created_at'=>$read['created_at'] instanceof DateTimeImmutable,
		],
		'read_values'=>[
			'id'=>$read['id'],
			'total'=>$read['total'],
			'active'=>$read['active'],
			'metadata'=>$read['metadata'],
		],
	];
}

function dp_sql_unit_mutation_result_shapes(): array {
	$insert=MutationResult::fromRaw('insert', 'ord_123', ['table'=>'orders']);
	$stale=MutationResult::fromRaw('update_with_version', 0, ['repository'=>'OrdersRepository']);
	$failed=MutationResult::fromRaw('delete', false, ['table'=>'orders'], 'delete failed');

	return [
		'insert'=>$insert->jsonSerialize(),
		'stale'=>[
			'ok'=>$stale->ok(),
			'failed'=>$stale->failed(),
			'affected_rows'=>$stale->affectedRows(),
			'stale'=>$stale->stale(),
		],
		'failed'=>[
			'ok'=>$failed->ok(),
			'failed'=>$failed->failed(),
			'error'=>$failed->errorMessage(),
		],
	];
}

function dp_sql_unit_mutation_batch_summary(): array {
	$batch=new MutationBatchResult('update', [
		MutationResult::fromRaw('update', 1),
		MutationResult::fromRaw('update', false, [], 'second row failed'),
		'ignored',
	], 3);

	return $batch->jsonSerialize();
}

function dp_sql_unit_record_value_object_behaviour(): array {
	$schema=new TableSchema('orders', ['id', 'status', 'total'], [], 'id', ['total'=>'float']);
	$record=new Record(['id'=>9, 'status'=>'paid', 'total'=>'12.50'], $schema, null);
	$changed=$record->with('status', 'refunded');

	return [
		'id'=>$record->id(),
		'primary_key'=>$record->primaryKeyName(),
		'count'=>count($record),
		'has_status'=>$record->has('status'),
		'get_missing'=>$record->get('missing', 'fallback'),
		'only'=>$record->only(['id', 'missing', 'status']),
		'except'=>$record->except(['total']),
		'changed'=>$changed->toArray(),
		'original'=>$record->toArray(),
		'array_access'=>$record['status'],
	];
}

function dp_sql_unit_page_result_helpers(): array {
	$page=new PageResult([
		['id'=>10, 'status'=>'paid'],
		['id'=>11, 'status'=>'draft'],
	], 5, 2, 2);

	return [
		'count'=>count($page),
		'first'=>$page->first(),
		'last_page'=>$page->lastPage(),
		'has_more'=>$page->hasMorePages(),
		'has_previous'=>$page->hasPreviousPage(),
		'first_item_index'=>$page->firstItemIndex(),
		'last_item_index'=>$page->lastItemIndex(),
		'pluck'=>$page->pluck('status', 'id'),
		'key_by'=>array_keys($page->keyBy('id')),
	];
}

function dp_sql_unit_currency_bridge_minor_money_mapping(): array {
	$mapping=CurrencyBridge::normalizeMoneyMapping('amount_minor', 'currency', null, 'money', 'orders');
	$row=CurrencyBridge::applyMoneyMapping([
		'amount_minor'=>1995,
		'currency'=>'USD',
	], $mapping, 'orders');
	$money=Currency::money('19.95', 'USD');
	$fields=CurrencyBridge::expandWriteFields(['money'=>$money], [$mapping], [], 'orders');

	return [
		'amount'=>$row['money']->decimalAmount(),
		'minor'=>$row['money']->minorAmount(),
		'write_amount_minor'=>$fields['amount_minor'],
		'write_currency'=>$fields['currency'],
	];
}

function dp_sql_unit_currency_bridge_rejects_non_integer_minor_values(): array {
	$mapping=CurrencyBridge::normalizeMoneyMapping('amount_minor', 'currency', null, 'money', 'orders');
	$canonical=CurrencyBridge::applyMoneyMapping([
		'amount_minor'=>'001995',
		'currency'=>'USD',
	], $mapping, 'orders');
	$rejected=[];
	foreach(['19.95', '123abc', 1.2, '9223372036854775808'] as $value){
		try{
			CurrencyBridge::applyMoneyMapping([
				'amount_minor'=>$value,
				'currency'=>'USD',
			], $mapping, 'orders');
			$rejected[]=false;
		}
		catch(Throwable){
			$rejected[]=true;
		}
	}
	$blank=CurrencyBridge::applyMoneyMapping([
		'amount_minor'=>'',
		'currency'=>'USD',
	], $mapping, 'orders');
	return [
		'canonical_minor'=>$canonical['money']->minorAmount(),
		'rejected'=>$rejected,
		'blank_is_null'=>$blank['money']===null,
	];
}

function dp_sql_unit_currency_bridge_stored_money_defaults_are_minor(): array {
	$mapping=CurrencyBridge::normalizeStoredMoneyMapping([], 'stored_money', 'orders');
	$money=Currency::money('19.95', 'USD');
	$time=time();
	$rates=new ExchangeRates('USD', 'unit', $time, ['USD'=>1], ['USD'=>2]);
	$snapshot=new ExchangeSnapshot($rates, Currency::manager());
	$quote=new ExchangeQuote('USD', 'USD', 'USD', 2, 2, 1.0, 'unit', $time);
	$stored=new StoredMoney($money, $money, $snapshot, $quote);
	$fields=CurrencyBridge::expandWriteFields(['stored_money'=>$stored], [], [$mapping], 'orders');

	return [
		'original_amount_column'=>$mapping['original_amount_column'],
		'base_amount_column'=>$mapping['base_amount_column'],
		'original_amount_minor'=>$fields['original_amount_minor'],
		'base_amount_minor'=>$fields['base_amount_minor'],
		'hydrated_original_minor'=>CurrencyBridge::applyStoredMoneyMapping($fields, $mapping, 'orders')['stored_money']->originalMinorAmount(),
	];
}

function dp_sql_unit_table_repository_money_mapping_cache_refreshes(): array {
	DpSqlUnitDynamicMoneyRepository::$money=[
		'amount_minor'=>'currency',
	];
	$first=DpSqlUnitDynamicMoneyRepository::resolvedTargets();
	DpSqlUnitDynamicMoneyRepository::$money=[
		'fee_minor'=>[
			'currency'=>'USD',
			'target_column'=>'fee_money',
		],
	];
	$second=DpSqlUnitDynamicMoneyRepository::resolvedTargets();

	return [
		'first'=>$first,
		'second'=>$second,
	];
}
