<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\CurrencyBridge;
use Dataphyre\Database\DB;
use Dataphyre\Database\MutationBatchResult;
use Dataphyre\Database\MutationResult;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\TableQuery;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/table_query_coverage_bootstrap.php';

test('table query deep schema state and helper branches', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$schema=dp_table_query_schema();
	$query=new TableQuery($schema);
	$t->same('coverage_items', $query->table());
	$t->same($schema, $query->schema());
	$t->same('id', $query->primaryKey());
	$t->same('alt_id', (new TableQuery($schema, ' alt_id '))->primaryKey());
	$query->usingPrimaryKey('alt_id')->projection('listing');
	$t->same('alt_id', $query->primaryKey());
	$t->same($schema, (new TableQuery('other_items'))->usingSchema($schema)->schema());
	$t->throws(static fn()=>(new TableQuery('keyless_items'))->whereKey(1), Throwable::class);
	$t->throws(static fn()=>new TableQuery('bad-table'), Throwable::class);

	$stored=(new TableQuery('coverage_items', 'id'))->asStoredMoney([
		'target_column'=>'stored_money',
		'base_currency'=>'USD',
	]);
	$money=(new TableQuery('coverage_items', 'id'))
		->asMoney('amount', 'currency', 'money')
		->asStoredMoney('stored_money', ['base_currency'=>'USD']);
	$state=$money->executionState();
	$state['money_mappings']=array_merge([null], $state['money_mappings']);
	$state['stored_money_mappings']=array_merge([null], $state['stored_money_mappings']);
	$t->instanceOf(TableQuery::class, TableQuery::fromExecutionState($state));
	$t->same(null, TableQuery::fromExecutionState(['table'=>'coverage_items', 'columns'=>'*'])->primaryKey());
	$t->throws(static fn()=>TableQuery::fromExecutionState(['table'=>'  ']), Throwable::class);
	$t->notEmpty($stored->executionState());

	DpTableQueryCoverageState::set('select', [['id'=>'7', 'amount'=>'4.5'], 'metadata']);
	$rows=(new TableQuery($schema))->get(['id', 'amount']);
	$t->same(7, $rows[0]['id']);
	$t->same('metadata', $rows[1]);
	DpTableQueryCoverageState::set('select', ['id'=>'8', 'amount'=>'9.5']);
	$t->same(8, (new TableQuery($schema))->first(['id', 'amount'])['id']);

	$t->same('scalar', dp_table_query_private($t,$query, 'transformQueuedQueryResult', 'scalar'));
	$t->same([], dp_table_query_private($t,$query, 'transformQueuedQueryResult', []));
	$t->same(9, dp_table_query_private($t,$query, 'transformQueuedQueryResult', [['id'=>'9']])[0]['id']);
	$t->same(10, dp_table_query_private($t,$query, 'transformQueuedQueryResult', ['id'=>'10'])['id']);
	$t->same([], dp_table_query_private($t,$query, 'queuedAllQueryResult', false));
	$t->same([['id'=>1]], dp_table_query_private($t,$query, 'queuedAllQueryResult', [false, ['id'=>1]]));
	$t->same([['id'=>2]], dp_table_query_private($t,$query, 'queuedAllQueryResult', ['id'=>2]));
	$t->same(null, dp_table_query_private($t,$query, 'queuedFirstQueryResult', false));
	$t->same(null, dp_table_query_private($t,$query, 'queuedFirstQueryResult', [false]));
	$t->same(3, dp_table_query_private($t,$query, 'queuedFirstQueryResult', [['id'=>3]])['id']);
	$t->same(4, dp_table_query_private($t,$query, 'queuedFirstQueryResult', ['id'=>4])['id']);

	$hydrator=new DpTableQueryHydrator();
	$t->same([], dp_table_query_private($t,$query, 'hydrateQueuedRows', false, $hydrator));
	$t->same('coverage_items', dp_table_query_private($t,$query, 'hydrateQueuedRows', [['id'=>1]], $hydrator)[0]['schema']);
	$t->same(null, dp_table_query_private($t,$query, 'hydrateQueuedRow', false, $hydrator));
	$t->same(2, dp_table_query_private($t,$query, 'hydrateQueuedRow', ['id'=>2], $hydrator)['hydrated']['id']);
	$t->same([], dp_table_query_private($t,$query, 'queuedRowsFromResult', false));
	$t->same([['id'=>1]], dp_table_query_private($t,$query, 'queuedRowsFromResult', [false, ['id'=>1]]));
	$t->same([['id'=>2]], dp_table_query_private($t,$query, 'queuedRowsFromResult', ['id'=>2]));
	$t->same(null, dp_table_query_private($t,$query, 'queuedRowFromResult', false));
	$t->same(null, dp_table_query_private($t,$query, 'queuedRowFromResult', [false]));
	$t->same(['id'=>3], dp_table_query_private($t,$query, 'queuedRowFromResult', [['id'=>3]]));
	$t->same(['id'=>4], dp_table_query_private($t,$query, 'queuedRowFromResult', ['id'=>4]));

	$t->throws(static fn()=>dp_table_query_private($t,$query, 'resolvedFields', []), Throwable::class);
	$t->throws(static fn()=>dp_table_query_private($t,new TableQuery('coverage_items'), 'resolvedFields', ['value']), Throwable::class);
	$t->same(['id'=>5], dp_table_query_private($t,new TableQuery($schema), 'resolvedFields', ['id'=>'5']));
	$t->same($hydrator, dp_table_query_private($t,$query, 'resolvedHydrator', $hydrator));
	$t->instanceOf(RecordHydrator::class, dp_table_query_private($t,$query, 'resolvedHydrator', static fn(array $row): array=>$row));
	$t->instanceOf(DpTableQueryHydrator::class, dp_table_query_private($t,$query, 'resolvedHydrator', DpTableQueryHydrator::class));
	$t->instanceOf(RecordHydrator::class, dp_table_query_private($t,$query, 'resolvedHydrator', DpTableQueryPojo::class));
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'resolvedHydrator', ' '), Throwable::class);
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'resolvedHydrator', 'MissingCoverageHydrator'), Throwable::class);
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'resolvedHydrator', 42), Throwable::class);

	$resource=fopen('php://memory', 'r');
	$t->same(DpTableQueryHydrator::class, dp_table_query_private($t,$query->usingHydrator($hydrator), 'hydratorDescriptor', $hydrator));
	$t->same('resource (stream)', dp_table_query_private($t,$query, 'hydratorDescriptor', $resource));
	fclose($resource);
	$t->notEmpty(dp_table_query_private($t,$query, 'fingerprintHash', ['invalid_utf8'=>"\xB1\x31"]));
	$t->same(['amount'], dp_table_query_private($t,$query, 'pluckColumns', ' amount ', null));
	$t->same(['amount', 'id'], dp_table_query_private($t,$query, 'pluckColumns', ' amount ', ' id '));
	$t->same('*', dp_table_query_private($t,$query, 'keyColumns', 'id', null));
	$t->same(['name', 'id'], dp_table_query_private($t,$query, 'keyColumns', 'id', ' name '));
	$t->same(['name', 'id'], dp_table_query_private($t,$query, 'keyColumns', 'id', [' name ', '', 'id']));
	$t->throws(static fn()=>dp_table_query_private($t,new TableQuery('keyless'), 'resolvedKeyColumn', null), Throwable::class);
	$t->same('id', dp_table_query_private($t,$query, 'resolvedKeyColumn', ' id '));
	$t->same('DESC', dp_table_query_private($t,$query, 'normalizedKeysetDirection', ' desc '));
	$t->same('ASC', dp_table_query_private($t,$query, 'normalizedKeysetDirection', 'sideways'));
	$t->same(2, dp_table_query_private($t,$query, 'lastKeyFromRows', [['id'=>1], ['id'=>2]], 'id'));
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'lastKeyFromRows', [['name'=>'missing']], 'id'), RuntimeException::class);
	$t->same(['Ada'], dp_table_query_private($t,$query, 'pluckRows', [false, ['name'=>'Ada']], 'name'));
	$t->same(['1'=>'Ada'], dp_table_query_private($t,$query, 'pluckRows', [false, ['id'=>null, 'name'=>'skip'], ['id'=>1, 'name'=>'Ada']], 'name', 'id'));
	$t->same(['2'=>['id'=>2]], dp_table_query_private($t,$query, 'keyRowsBy', [false, ['id'=>null], ['id'=>2]], 'id'));

	$t->same([], dp_table_query_private($t,$query, 'normalizeTraceNames', null, true));
	$t->same(['lazy', 'named'], dp_table_query_private($t,$query, 'normalizeTraceNames', [false, ' ', 'lazy', ' named ', 'named'], false));
	$t->same(['named'], dp_table_query_private($t,$query, 'normalizeTraceNames', [false, ' ', 'lazy', ' named ', 'named'], true));
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'normalizeAggregateFunction', 'median'), Throwable::class);
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'aggregateColumn', '', 'SUM', false), Throwable::class);
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'aggregateColumn', '*', 'SUM', false), Throwable::class);
	$t->same('*', dp_table_query_private($t,$query, 'aggregateColumn', '*', 'COUNT', true));
	$t->same('amount', dp_table_query_private($t,$query, 'aggregateColumn', 'amount', 'SUM', false));
	$t->same(null, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'SUM', null));
	$t->same(false, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'SUM', false));
	$t->same(2, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'COUNT', '2'));
	$t->same('many', dp_table_query_private($t,$query, 'normalizeAggregateResult', 'COUNT', 'many'));
	$t->same(2, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'SUM', '2'));
	$t->same(2.5, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'AVG', '2.5'));
	$t->same(200.0, dp_table_query_private($t,$query, 'normalizeAggregateResult', 'SUM', '2e2'));
	$t->same('z', dp_table_query_private($t,$query, 'normalizeAggregateResult', 'MAX', 'z'));
	$t->same(['group_id'], dp_table_query_private($t,$query, 'groupColumns', 'group_id'));
	$t->throws(static fn()=>dp_table_query_private($t,$query, 'groupColumns', ['']), Throwable::class);
	$t->same('', dp_table_query_private($t,$query, 'appendClause', '', ''));
	$t->notEmpty(dp_table_query_private($t,$query, 'appendClause', 'WHERE id=?', ''));
	$t->notEmpty(dp_table_query_private($t,$query, 'appendClause', '', 'GROUP BY group_id'));
	$t->same([false, ['aggregate_value'=>2]], dp_table_query_private($t,$query, 'normalizeAggregateRows', [false, ['aggregate_value'=>'2']], 'COUNT'));
	$t->same(['a'=>2], dp_table_query_private($t,$query, 'groupedAggregateMap', 'group_id', [false, ['group_id'=>null], ['group_id'=>'a', 'aggregate_value'=>2]]));

	$moneyValue=CurrencyBridge::money('1.25', 'USD');
	foreach(['whereMoneyEq', 'whereMoneyGt', 'whereMoneyGte', 'whereMoneyLt', 'whereMoneyLte'] as $method){
		$t->instanceOf(TableQuery::class, (new TableQuery('coverage_items'))->{$method}('amount', $moneyValue, 'currency'));
	}
	foreach(['whereMoneyEqIn', 'whereMoneyGtIn', 'whereMoneyGteIn', 'whereMoneyLtIn', 'whereMoneyLteIn'] as $method){
		$t->instanceOf(TableQuery::class, (new TableQuery('coverage_items'))->{$method}('amount', '1.25', 'USD'));
	}
	$t->throws(
		static fn()=>dp_table_query_private($t,new TableQuery('coverage_items'), 'whereMoneyCompare', 'amount', '1.25', '!=', null, 'USD'),
		Throwable::class
	);
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);

test('table query deep read failures retries aggregates and hydration', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$query=new TableQuery('coverage_items', 'id');

	DpTableQueryCoverageState::set('select', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->same([], $query->get());
	DpTableQueryCoverageState::set('select', false, [['id'=>1]]);
	\dataphyre\sql::$hydrateResults=[true];
	$t->same([['id'=>1]], $query->get());
	$t->same(1, \dataphyre\sql::$invalidateCalls);

	DpTableQueryCoverageState::set('select', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->firstOrFail(), Throwable::class);
	DpTableQueryCoverageState::set('select', []);
	$t->throws(static fn()=>$query->sole(), Throwable::class);
	DpTableQueryCoverageState::set('select', [['id'=>2]]);
	$t->same(2, $query->sole()['id']);
	DpTableQueryCoverageState::set('select', [['id'=>2], ['id'=>3]]);
	$t->throws(static fn()=>$query->sole(), Throwable::class);

	DpTableQueryCoverageState::set('select', [5, ['id'=>4]]);
	$hydrated=$query->getHydrated(null, new DpTableQueryHydrator());
	$t->same([1], array_keys($hydrated));
	DpTableQueryCoverageState::set('select', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->firstRecordOrFail(null, new DpTableQueryHydrator()), Throwable::class);
	DpTableQueryCoverageState::set('select', [['id'=>5]]);
	$t->same(5, $query->soleRecord(null, static fn(array $row): array=>$row)['id']);
	DpTableQueryCoverageState::set('select', [['name'=>'Ada']]);
	$t->same('Ada', $query->soleValue('name'));

	foreach(['findOrFail', 'findHydratedOrFail', 'findRecordOrFail'] as $method){
		DpTableQueryCoverageState::set('select', false);
		\dataphyre\sql::$hydrateResults=[false];
		$t->throws(static fn()=>$query->{$method}(99), Throwable::class);
	}

	DpTableQueryCoverageState::set('select', ['aggregate_value'=>'not-numeric']);
	$t->same(null, $query->countColumn('id'));
	DpTableQueryCoverageState::set('select', ['aggregate_value'=>'not-numeric']);
	$t->same(null, $query->countDistinct('id'));
	DpTableQueryCoverageState::set('select', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->same([], $query->aggregateRowsBy('group_id', 'COUNT', '*'));
	DpTableQueryCoverageState::set('select', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->same(false, $query->sum('amount'));
	DpTableQueryCoverageState::set('select', ['unexpected'=>1]);
	$t->same(null, $query->avg('amount'));
	DpTableQueryCoverageState::set('select', ['aggregate_value'=>'4']);
	$t->same(4, $query->aggregate('COUNT', '*'));
	$t->throws(static fn()=>$query->aggregate('median', 'amount'), Throwable::class);
	$t->throws(static fn()=>$query->sum(''), Throwable::class);
	$t->throws(static fn()=>$query->sum('*'), Throwable::class);

	DpTableQueryCoverageState::set('select', [
		['group_id'=>'a', 'aggregate_value'=>'2'],
		false,
		['group_id'=>null, 'aggregate_value'=>'3.5'],
		['other'=>'x'],
	]);
	$t->same(['a'=>2], $query->countBy('group_id'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'2.5']]);
	$t->same(['a'=>2.5], $query->avgBy('group_id', 'amount'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'4']]);
	$t->same(['a'=>4], $query->sumBy('group_id', 'amount'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'1']]);
	$t->same(['a'=>'1'], $query->minBy('group_id', 'amount'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'9']]);
	$t->same(['a'=>'9'], $query->maxBy('group_id', 'amount'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'1']]);
	$t->same(['a'=>1], $query->countDistinctBy('group_id', 'id'));

	$schemaQuery=new TableQuery(dp_table_query_schema());
	DpTableQueryCoverageState::set('select', ['aggregate_value'=>'3.5']);
	$t->same(3.5, $schemaQuery->sum('amount'));
	DpTableQueryCoverageState::set('select', [['group_id'=>'a', 'aggregate_value'=>'2']]);
	$t->same(['a'=>2], $schemaQuery->countBy('group_id'));
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);

test('table query strict reads distinguish SQL failure from a valid empty result', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$query=(new TableQuery('coverage_items','id'))->failOnReadError();
	DpTableQueryCoverageState::set('select',false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->get(),RuntimeException::class);
	DpTableQueryCoverageState::set('select',false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->first(),RuntimeException::class);
	DpTableQueryCoverageState::set('select',[],null);
	$t->same([],$query->get());
	$t->same(null,$query->first());
	$t->same(true,TableQuery::fromExecutionState($query->executionState())->executionState()['fail_on_read_error']);
})->tag('sql','table-query','strict-read','regression')->group('framework-coverage');

test('table query deep chunk and keyset iteration paths', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$query=new TableQuery('coverage_items', 'id');

	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(2, $query->chunk(2, static fn(): bool=>false));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->chunk(5, static fn(): bool=>true));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]], []);
	$t->same(2, $query->chunk(2, static fn(): bool=>true));

	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(1, $query->each(static fn(array $row, int $processed): bool=>$processed<1, 5));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->each(static fn(): bool=>true, 5));

	$hydrator=new DpTableQueryHydrator();
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(2, $query->chunkRecords(2, static fn(): bool=>false, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->chunkRecords(5, static fn(): bool=>true, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]], []);
	$t->same(2, $query->chunkRecords(2, static fn(): bool=>true, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(1, $query->eachRecord(static fn(mixed $row, int $processed): bool=>$processed<1, 5, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->eachRecord(static fn(): bool=>true, 5, null, $hydrator));

	$t->throws(static fn()=>(new TableQuery('keyless'))->chunkById(2, static fn()=>true), Throwable::class);
	DpTableQueryCoverageState::set('select', []);
	$t->same(0, $query->chunkById(2, static fn()=>true));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(2, $query->chunkById(2, static fn(): bool=>false));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]], [['id'=>3]]);
	$t->same(3, $query->chunkById(2, static fn(): bool=>true, null, ['name'], null, 'ASC'));
	DpTableQueryCoverageState::set('select', [['id'=>3], ['id'=>2]], [['id'=>1]]);
	$t->same(3, $query->chunkById(2, static fn(): bool=>true, 'id', '*', null, 'DESC'));
	DpTableQueryCoverageState::set('select', [['name'=>'missing']]);
	$t->throws(static fn()=>$query->chunkById(2, static fn()=>true), RuntimeException::class);

	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(1, $query->eachById(static fn(array $row, int $processed): bool=>$processed<1, 5));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->eachById(static fn(): bool=>true, 5));

	DpTableQueryCoverageState::set('select', [false, ['id'=>2]], []);
	$t->same(1, $query->chunkRecordsById(2, static fn(): bool=>true, null, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>3], ['id'=>2]], [['id'=>1]]);
	$t->same(3, $query->chunkRecordsById(2, static fn(): bool=>true, 'id', null, $hydrator, null, 'DESC'));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(2, $query->chunkRecordsById(2, static fn(): bool=>false, null, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->same(1, $query->eachRecordById(static fn(mixed $record, int $processed): bool=>$processed<1, 5, null, null, $hydrator));
	DpTableQueryCoverageState::set('select', [['id'=>1]]);
	$t->same(1, $query->eachRecordById(static fn(): bool=>true, 5, null, null, $hydrator));
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);

test('table query deep mutation success failure and validation paths', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	DB::disableGuardrails();
	$query=(new TableQuery('coverage_items', 'id'))->cache(['lazy', 'named_cache'])->withoutInvalidation();
	$t->isTrue($query->create(['name'=>'Ada'])->ok());
	$t->same(2, $query->createMany([['name'=>'Ada'], 'skip', ['name'=>'Grace']])->processed());
	$t->same(2, $query->upsertMany([['name'=>'Ada'], 'skip', ['name'=>'Grace']])->processed());

	DpTableQueryCoverageState::set('select', ['id'=>1, 'name'=>'Ada']);
	$t->same(1, $query->firstOrCreate(['name'=>'Ada'])['id']);
	DpTableQueryCoverageState::set('select', false, ['id'=>2, 'name'=>'Grace']);
	\dataphyre\sql::$hydrateResults=[false];
	$t->same(2, $query->firstOrCreate(['name'=>'Grace'], ['amount'=>2])['id']);
	DpTableQueryCoverageState::set('select', false, false);
	DpTableQueryCoverageState::set('insert', 10);
	\dataphyre\sql::$hydrateResults=[false, false];
	$t->throws(static fn()=>$query->firstOrCreate(['name'=>'Missing']), Throwable::class);
	DpTableQueryCoverageState::set('select', false);
	DpTableQueryCoverageState::set('insert', false);
	\dataphyre\sql::$hydrateResults=[false, false];
	$t->throws(static fn()=>$query->firstOrCreate(['name'=>'Broken']), RuntimeException::class);

	DpTableQueryCoverageState::set('select', false, ['id'=>3, 'name'=>'Created']);
	DpTableQueryCoverageState::set('insert', 11);
	\dataphyre\sql::$hydrateResults=[false];
	$t->same(3, $query->updateOrCreate(['name'=>'Created'], ['amount'=>3])['id']);
	DpTableQueryCoverageState::set('select', false, false);
	DpTableQueryCoverageState::set('insert', 12);
	\dataphyre\sql::$hydrateResults=[false, false];
	$t->throws(static fn()=>$query->updateOrCreate(['name'=>'Lost'], ['amount'=>4]), Throwable::class);
	DpTableQueryCoverageState::set('select', ['id'=>4, 'name'=>'Same']);
	$t->same(4, $query->updateOrCreate(['name'=>'Same'], ['name'=>'Same'])['id']);
	DpTableQueryCoverageState::set('select', ['id'=>5, 'name'=>'Ada'], ['id'=>5, 'amount'=>8]);
	DpTableQueryCoverageState::set('update', 1);
	$t->same(8, $query->updateOrCreate(['name'=>'Ada'], ['amount'=>8])['amount']);
	DpTableQueryCoverageState::set('select', ['id'=>6, 'name'=>'Ada'], false);
	DpTableQueryCoverageState::set('update', 1);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->updateOrCreate(['name'=>'Ada'], ['amount'=>9]), Throwable::class);
	DpTableQueryCoverageState::set('select', ['id'=>7, 'name'=>'Ada']);
	DpTableQueryCoverageState::set('update', false);
	\dataphyre\sql::$hydrateResults=[false];
	$t->throws(static fn()=>$query->updateOrCreate(['name'=>'Ada'], ['amount'=>10]), RuntimeException::class);

	$t->throws(static fn()=>$query->create([]), Throwable::class);
	$t->throws(static fn()=>$query->create(['list-value']), Throwable::class);
	$t->isTrue((new TableQuery(dp_table_query_schema()))->create(['id'=>'8', 'name'=>'Schema'])->ok());

	$scoped=(new TableQuery(dp_table_query_schema()))->whereKey(1)->cache(['named'])->withoutInvalidation();
	$t->isTrue($scoped->increment('amount', 2)->ok());
	$t->isTrue($scoped->decrement('amount', 1.5)->ok());
	$t->throws(static fn()=>$scoped->increment('amount', -1), Throwable::class);
	$t->throws(static fn()=>$scoped->increment('amount', INF), Throwable::class);
	$t->throws(static fn()=>$scoped->updateWithVersion(['name'=>'x'], 1, 'lock_version', 0), Throwable::class);
	$t->throws(static fn()=>$scoped->updateWithVersion(['name'=>'x'], -1), Throwable::class);
	$t->throws(static fn()=>$scoped->updateWithVersion(['lock_version'=>2], 1), Throwable::class);
	DpTableQueryCoverageState::set('update', 1, 1, 1);
	$t->isTrue($scoped->updateWithVersion(['name'=>'Updated'], 1)->ok());
	$t->isTrue($scoped->updateWithVersion([], 2)->ok());
	$t->isTrue($scoped->updateWithVersionOrFail(['name'=>'Again'], 3)->ok());
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);

test('table query deep queued read callback and result paths', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$query=new TableQuery('coverage_items', 'id');
	$captured=null;

	$query->queueGet(static function(array $rows)use(&$captured): void{$captured=$rows;});
	$t->same(2, count($captured));
	$query->queueFirst(static function(?array $row)use(&$captured): void{$captured=$row;});
	$t->same('Ada', $captured['name']);
	$query->queueGetHydrated(static function(array $rows)use(&$captured): void{$captured=$rows;}, 'end', null, new DpTableQueryHydrator());
	$t->same('1', $captured[0]['hydrated']['id']);
	DpTableQueryCoverageState::set('select', false);
	$query->queueGetRecords(static function(array $rows)use(&$captured): void{$captured=$rows;}, 'end', null, new DpTableQueryHydrator());
	$t->same([], $captured);
	$query->queueFirstHydrated(static function(mixed $row)use(&$captured): void{$captured=$row;}, 'end', null, new DpTableQueryHydrator());
	$t->same('1', $captured['hydrated']['id']);
	DpTableQueryCoverageState::set('select', false);
	$query->queueFirstRecord(static function(mixed $row)use(&$captured): void{$captured=$row;}, 'end', null, new DpTableQueryHydrator());
	$t->same(null, $captured);

	$query->queueFirstOrFail(static function(array $row)use(&$captured): void{$captured=$row;});
	$t->same('1', $captured['id']);
	DpTableQueryCoverageState::set('select', false);
	$t->throws(static fn()=>$query->queueFirstOrFail(static fn()=>null), Throwable::class);
	$query->queueFirstRecordOrFail(static function(mixed $row)use(&$captured): void{$captured=$row;}, 'end', null, new DpTableQueryHydrator());
	$t->same('1', $captured['hydrated']['id']);
	$query->queueFindOrFail(1, static function(array $row)use(&$captured): void{$captured=$row;});
	$t->same('1', $captured['id']);
	DpTableQueryCoverageState::set('select', false);
	$t->throws(static fn()=>$query->queueFindOrFail(99, static fn()=>null), Throwable::class);
	$query->queueFindHydratedOrFail(1, static function(mixed $row)use(&$captured): void{$captured=$row;}, null, 'end', new DpTableQueryHydrator());
	$t->same('1', $captured['hydrated']['id']);
	$query->queueFindRecord(1, static function(mixed $row)use(&$captured): void{$captured=$row;}, null, 'end', new DpTableQueryHydrator());
	$query->queueFindRecordOrFail(1, static function(mixed $row)use(&$captured): void{$captured=$row;}, null, 'end', new DpTableQueryHydrator());

	$query->queuePluck('name', static function(array $values)use(&$captured): void{$captured=$values;}, 'id');
	$t->same(['1'=>'Ada', '2'=>'Grace'], $captured);
	$query->queueKeyBy('id', static function(array $rows)use(&$captured): void{$captured=$rows;}, ['name']);
	$t->same('Ada', $captured['1']['name']);
	DpTableQueryCoverageState::set('select', []);
	$t->throws(static fn()=>$query->queueSole(static fn()=>null), Throwable::class);
	DpTableQueryCoverageState::set('select', [['id'=>1], ['id'=>2]]);
	$t->throws(static fn()=>$query->queueSole(static fn()=>null), Throwable::class);
	DpTableQueryCoverageState::set('select', [['id'=>3]]);
	$query->queueSole(static function(array $row)use(&$captured): void{$captured=$row;});
	$t->same(3, $captured['id']);
	DpTableQueryCoverageState::set('select', [['id'=>4]]);
	$query->queueSoleRecord(static function(mixed $row)use(&$captured): void{$captured=$row;}, 'end', null, new DpTableQueryHydrator());
	$t->same(4, $captured['hydrated']['id']);
	DpTableQueryCoverageState::set('select', [['name'=>'Sole']]);
	$query->queueSoleValue('name', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same('Sole', $captured);
	$query->queueValue('name', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same('Ada', $captured);
	$query->queueValueOrFail('name', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same('Ada', $captured);

	$query->queueExists(static function(bool $value)use(&$captured): void{$captured=$value;});
	$t->same(true, $captured);
	$query->queueCount(static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(2, $captured);
	$query->queueAggregate('COUNT', '*', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(2, $captured);
	foreach(['queueSum', 'queueAvg', 'queueMin', 'queueMax'] as $method){
		$query->{$method}('amount', static function(mixed $value)use(&$captured): void{$captured=$value;});
		$t->isTrue($captured!==null);
	}
	$query->queueCountColumn('id', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(2, $captured);
	$query->queueCountDistinct('id', static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(2, $captured);
	$query->queueAggregateRowsBy('group_id', 'COUNT', '*', static function(array $rows)use(&$captured): void{$captured=$rows;});
	$t->same(2, $captured[0]['aggregate_value']);
	foreach([
		['queueCountBy', 'id'],
		['queueCountDistinctBy', 'id'],
		['queueSumBy', 'amount'],
		['queueAvgBy', 'amount'],
		['queueMinBy', 'amount'],
		['queueMaxBy', 'amount'],
	] as [$method, $column]){
		$query->{$method}('group_id', $column, static function(array $values)use(&$captured): void{$captured=$values;});
		$t->isTrue(isset($captured['a']));
	}

	$query->queuePaginate(static function(PageResult $page)use(&$captured): void{$captured=$page;}, 0, 999);
	$t->instanceOf(PageResult::class, $captured);
	$query->queuePaginateHydrated(static function(PageResult $page)use(&$captured): void{$captured=$page;}, 1, 2, null, 'end', new DpTableQueryHydrator());
	$t->same('1', $captured->items()[0]['hydrated']['id']);
	DpTableQueryCoverageState::set('select', [false, ['id'=>2]]);
	$query->queuePaginateRecords(static function(PageResult $page)use(&$captured): void{$captured=$page;}, 1, 2, null, 'end', new DpTableQueryHydrator());
	$t->same([0], array_keys($captured->items()));
	DpTableQueryCoverageState::set('count_queue_return', false);
	$t->same(false, $query->queuePaginate(static fn()=>null));
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);

test('table query deep queued mutation batches and callbacks', static function(Context $t): void {
	DpTableQueryCoverageState::reset();
	$query=(new TableQuery(dp_table_query_schema()))->whereKey(1)->cache(['named'])->withoutInvalidation();
	$captured=null;

	$query->queueCreate(['name'=>'Ada'], static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(10, $captured);
	$query->queueCreateMany(
		[['name'=>'Ada'], 'skip', ['name'=>'Grace']],
		static function(MutationBatchResult $result)use(&$captured): void{$captured=$result;}
	);
	$t->instanceOf(MutationBatchResult::class, $captured);
	$t->same(2, $captured->processed());
	DpTableQueryCoverageState::set('insert_queue_return', false);
	$t->same(false, $query->queueCreateMany([['name'=>'Broken']], static function(MutationBatchResult $result)use(&$captured): void{$captured=$result;}));
	$t->isTrue($captured->failedCount()>0);
	$query->queueCreateMany([], static function(MutationBatchResult $result)use(&$captured): void{$captured=$result;});
	$t->same(0, $captured->requested());

	$query->queueUpdate(['name'=>'Updated'], static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(1, $captured);
	$query->queueUpdateWithVersion(['name'=>'Versioned'], 1, static function(MutationResult $value)use(&$captured): void{$captured=$value;});
	$t->instanceOf(MutationResult::class, $captured);
	$query->queueUpdateWithVersionOrFail(['name'=>'Strict'], 2, static function(MutationResult $value)use(&$captured): void{$captured=$value;});
	$t->isTrue($captured->ok());
	$query->queueIncrement('amount', static function(mixed $value)use(&$captured): void{$captured=$value;}, 'end', 2);
	$t->same(1, $captured);
	$query->queueDecrement('amount', static function(mixed $value)use(&$captured): void{$captured=$value;}, 'end', 1);
	$t->same(1, $captured);
	$query->queueDelete(static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(1, $captured);
	$query->queueUpsert(['id'=>1, 'name'=>'Upserted'], static function(mixed $value)use(&$captured): void{$captured=$value;});
	$t->same(1, $captured);
	$query->queueUpsertMany(
		[['id'=>1, 'name'=>'A'], 'skip', ['id'=>2, 'name'=>'B']],
		static function(MutationBatchResult $result)use(&$captured): void{$captured=$result;}
	);
	$t->same(2, $captured->processed());
})->tag('sql', 'coverage')->group('framework-coverage')->maxMillis(15000);
