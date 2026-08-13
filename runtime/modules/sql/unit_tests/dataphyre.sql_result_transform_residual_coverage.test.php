<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

$dp_sql_value_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/sql/';
require_once $dp_sql_value_root.'unit_tests/sql_framework_test_helpers.php';
require_once $dp_sql_value_root.'Framework/TransactionResult.php';
require_once $dp_sql_value_root.'Framework/Contracts/RecordHydrator.php';
require_once $dp_sql_value_root.'Framework/Concerns/TransformsRows.php';
require_once $dp_sql_value_root.'Framework/Hydrators/ClassRecordHydrator.php';
require_once $dp_sql_value_root.'Framework/MultipleRecordsFoundException.php';
require_once $dp_sql_value_root.'Framework/OptimisticLockException.php';
require_once $dp_sql_value_root.'Framework/RecordNotFoundException.php';

final class DpSqlTransformsHarness {
	use \Dataphyre\Database\Concerns\TransformsRows;

	public function add(callable $transformer): void { $this->addRowTransformer($transformer); }
	public function has(): bool { return $this->hasRowTransformers(); }
	public function row(array $row): array { return $this->transformRow($row); }
	public function rows(array $rows): array { return $this->transformRows($rows); }
	public function queued(mixed $result): mixed { return $this->transformQueuedResult($result); }
}

final class DpSqlFactoryHydrated {
	public static function fromRow(array $row, ?\Dataphyre\Database\TableSchema $schema, ?string $repository, ?string $primaryKey): array {
		return compact('row', 'schema', 'repository', 'primaryKey');
	}
}

final class DpSqlPlainHydrated {
	public function __construct(public array $row, public ?\Dataphyre\Database\TableSchema $schema) {}
}

final class DpSqlRecordSubclass extends \Dataphyre\Database\Record {}

test('sql mutation and transaction result value objects cover accessors guards iteration and serialization caches', static function(Context $t): void {
	$insert=\Dataphyre\Database\MutationResult::fromRaw('insert', 'id-7', ['table'=>'orders']);
	$t->same('insert', $insert->operation());
	$t->same('id-7', $insert->rawResult());
	$t->same(null, $insert->affectedRows());
	$t->same('id-7', $insert->insertedId());
	$t->same(['table'=>'orders'], $insert->context());
	$t->same(null, $insert->errorMessage());
	$t->isFalse($insert->stale());
	$t->same($insert, $insert->throwIfFailed()->throwIfStale()->throwIfFailedOrStale());
	$t->hasPathValues([
		'operation'=>'insert',
		'ok'=>true,
		'inserted_id'=>'id-7',
		'context.table'=>'orders',
	],$insert->jsonSerialize());

	$failed=\Dataphyre\Database\MutationResult::fromRaw('delete', false, ['table'=>'orders']);
	$failureMessage='';
	try{
		$failed->throwIfFailed('custom-failure');
	}catch(RuntimeException $exception){
		$failureMessage=$exception->getMessage();
	}
	$t->same('custom-failure', $failureMessage);

	foreach([
		['context'=>['repository'=>'OrdersRepository'], 'owner'=>'OrdersRepository'],
		['context'=>['table'=>'orders'], 'owner'=>'table orders'],
		['context'=>[], 'owner'=>'update_with_version'],
	] as $case){
		$stale=\Dataphyre\Database\MutationResult::fromRaw('update_with_version', 0, $case['context']);
		$t->isTrue($stale->stale());
		$message='';
		try{
			$stale->throwIfFailedOrStale();
		}catch(\Dataphyre\Database\OptimisticLockException $exception){
			$message=$exception->getMessage();
		}
		$t->contains($case['owner'], $message);
	}
	$negative=\Dataphyre\Database\MutationResult::fromRaw('update', -4);
	$t->same(0, $negative->affectedRows());
	$t->same(null, $negative->insertedId());

	$okOne=\Dataphyre\Database\MutationResult::fromRaw('update', 1);
	$blankFailure=new \Dataphyre\Database\MutationResult('update', false, false, null, [], '   ');
	$messageFailure=\Dataphyre\Database\MutationResult::fromRaw('update', false, [], 'row failed');
	$complete=new \Dataphyre\Database\MutationBatchResult('update', [$okOne, $messageFailure, 'ignored'], 2);
	$t->same('update', $complete->operation());
	$t->same([$okOne, $messageFailure], $complete->results());
	$t->same(2, $complete->requested());
	$t->same(2, $complete->processed());
	$t->same(1, $complete->successful());
	$t->same(1, $complete->successful());
	$t->same(1, $complete->failedCount());
	$t->same(['row failed'], $complete->errorMessages());
	$t->same('row failed', $complete->firstErrorMessage());
	$t->isFalse($complete->ok());
	$t->isTrue($complete->failed());
	$t->isFalse($complete->noop());
	$t->same(2, $complete->count());
	$t->same(2, iterator_count($complete->getIterator()));
	$t->hasPathValues([
		'operation'=>'update',
		'requested'=>2,
		'processed'=>2,
		'successful'=>1,
		'failed'=>1,
	],$t->producesStableResult(static fn(): array=>$complete->jsonSerialize()));

	$allOk=new \Dataphyre\Database\MutationBatchResult('update', [$okOne], 1);
	$t->isTrue($allOk->ok());
	$missing=new \Dataphyre\Database\MutationBatchResult('update', [$okOne], 2);
	$t->isFalse($missing->ok());
	$blank=new \Dataphyre\Database\MutationBatchResult('update', [$okOne, $blankFailure], 2);
	$t->same([], $blank->errorMessages());
	$t->same(null, $blank->firstErrorMessage());
	$t->same([], $blank->jsonSerialize()['error_messages']);
	$noop=new \Dataphyre\Database\MutationBatchResult('update', [], 0);
	$t->isTrue($noop->noop());
	$t->isTrue($noop->ok());

	$success=\Dataphyre\Database\TransactionResult::success('primary', true, true, ['value'=>1], 0);
	$t->same('primary', $success->cluster());
	$t->isTrue($success->ok());
	$t->isFalse($success->failed());
	$t->isTrue($success->begun());
	$t->isTrue($success->committed());
	$t->isFalse($success->rolledBack());
	$t->same(1, $success->attempts());
	$t->same(['value'=>1], $success->value());
	$t->same(null, $success->exception());
	$t->same(null, $success->errorMessage());
	$t->same(null, $success->errorClass());
	$t->hasPathValues([
		'cluster'=>'primary',
		'ok'=>true,
		'attempts'=>1,
		'value.value'=>1,
	],$t->producesStableResult(static fn(): array=>$success->jsonSerialize()));

	$throwable=new LogicException('transaction-failure');
	$failure=\Dataphyre\Database\TransactionResult::failure(null, true, false, true, $throwable, 3);
	$t->isTrue($failure->failed());
	$t->same($throwable, $failure->exception());
	$t->same('transaction-failure', $failure->errorMessage());
	$t->same(LogicException::class, $failure->errorClass());
	$t->same(3, $failure->jsonSerialize()['attempts']);
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');

test('sql row transformer concern covers zero one two many and queued result shapes', static function(Context $t): void {
	$none=new DpSqlTransformsHarness();
	$t->isFalse($none->has());
	$t->same(['value'=>1], $none->row(['value'=>1]));
	$t->same('raw', $none->queued('raw'));

	$one=new DpSqlTransformsHarness();
	$one->add(static fn(array $row): array=>$row+['first'=>true]);
	$t->isTrue($one->has());
	$t->same(['value'=>1, 'first'=>true], $one->row(['value'=>1]));
	$t->same([['value'=>1, 'first'=>true], 'metadata'], $one->rows([['value'=>1], 'metadata']));
	$t->same([], $one->queued([]));
	$t->same([['value'=>2, 'first'=>true]], $one->queued([['value'=>2]]));
	$t->same(['value'=>3, 'first'=>true], $one->queued(['value'=>3]));

	$two=new DpSqlTransformsHarness();
	$two->add(static fn(array $row): array=>$row+['first'=>true]);
	$two->add(static fn(array $row): array=>$row+['second'=>true]);
	$t->same(['value'=>1, 'first'=>true, 'second'=>true], $two->row(['value'=>1]));
	$t->same([['value'=>1, 'first'=>true, 'second'=>true], 9], $two->rows([['value'=>1], 9]));

	$many=new DpSqlTransformsHarness();
	$many->add(static fn(array $row): array=>$row+['one'=>1]);
	$many->add(static fn(array $row): array=>$row+['two'=>2]);
	$many->add(static fn(array $row): array=>$row+['three'=>3]);
	$t->same(['value'=>1, 'one'=>1, 'two'=>2, 'three'=>3], $many->row(['value'=>1]));
	$t->same([['value'=>1, 'one'=>1, 'two'=>2, 'three'=>3], false], $many->rows([['value'=>1], false]));
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');

test('sql class hydrator and contextual exceptions cover every construction path', static function(Context $t): void {
	$schema=new \Dataphyre\Database\TableSchema('orders', ['id', 'name'], [], 'id');
	$factory=(new \Dataphyre\Database\Hydrators\ClassRecordHydrator(DpSqlFactoryHydrated::class, 'OrdersRepository', 'fallback'))->hydrate(['id'=>1], $schema);
	$t->same('id', $factory['primaryKey']);
	$t->same('OrdersRepository', $factory['repository']);

	$record=(new \Dataphyre\Database\Hydrators\ClassRecordHydrator(DpSqlRecordSubclass::class, 'OrdersRepository', 'fallback'))->hydrate(['fallback'=>2], null);
	$t->same(DpSqlRecordSubclass::class, $record::class);
	$t->same(2, $record->id());
	$base=(new \Dataphyre\Database\Hydrators\ClassRecordHydrator(\Dataphyre\Database\Record::class, null, 'id'))->hydrate(['id'=>3], null);
	$t->same(3, $base->id());

	$plain=(new \Dataphyre\Database\Hydrators\ClassRecordHydrator(DpSqlPlainHydrated::class))->hydrate(['name'=>'plain'], $schema);
	$t->same('plain', $plain->row['name']);
	$t->same($schema, $plain->schema);

	$multiple=new \Dataphyre\Database\MultipleRecordsFoundException('multiple', ['count'=>2]);
	$optimistic=new \Dataphyre\Database\OptimisticLockException('stale', ['version'=>7]);
	$missing=new \Dataphyre\Database\RecordNotFoundException('missing', ['id'=>9]);
	$t->same(['count'=>2], $multiple->context());
	$t->same(['version'=>7], $optimistic->context());
	$t->same(['id'=>9], $missing->context());
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');
