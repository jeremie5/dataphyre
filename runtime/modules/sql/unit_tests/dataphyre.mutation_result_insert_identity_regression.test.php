<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\MutationResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/sql_framework_test_helpers.php';

test('mutation result resolves insert identity from the context primary key in returning rows', static function(Context $t): void {
	$returningRow=[
		'order_id'=>'ord_42',
		'tenant_id'=>'tenant_7',
		'status'=>'created',
	];
	$result=MutationResult::fromRaw('insert', $returningRow, [
		'table'=>'orders',
		'primary_key'=>' order_id ',
	]);
	$t->isTrue($result->ok());
	$t->same('ord_42', $result->insertedId());
	$t->same($returningRow, $result->rawResult());
	$t->same('ord_42', $result->jsonSerialize()['inserted_id'] ?? null);
	$t->same($returningRow, $result->jsonSerialize()['raw_result'] ?? null);

	$t->same(42, MutationResult::fromRaw('insert', ['id'=>42], ['primary_key'=>'id'])->insertedId());
	$t->same('legacy-id', MutationResult::fromRaw('insert', 'legacy-id')->insertedId());
	$t->same(7, MutationResult::fromRaw('insert', 7)->insertedId());

	foreach([
		MutationResult::fromRaw('insert', $returningRow, ['primary_key'=>'missing']),
		MutationResult::fromRaw('insert', $returningRow, []),
		MutationResult::fromRaw('insert', $returningRow, ['primary_key'=>'   ']),
		MutationResult::fromRaw('insert', ['id'=>null], ['primary_key'=>'id']),
		MutationResult::fromRaw('insert', ['id'=>true], ['primary_key'=>'id']),
		MutationResult::fromRaw('insert', ['id'=>['nested']], ['primary_key'=>'id']),
		MutationResult::fromRaw('update', ['id'=>42], ['primary_key'=>'id']),
	] as $ambiguous){
		$t->same(null, $ambiguous->insertedId());
	}
})->tag('sql', 'mutation-result', 'insert', 'postgresql', 'regression')->group('framework-regression');
