<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\SqlError;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__,2).'/testing/tooling/bootstrap.php';
require_once __DIR__.'/../Framework/SqlError.php';

test('SQL retry classification recognizes only concrete YugabyteDB transient conflict signals',static function(Context $t): void {
	foreach([
		'ERROR: All transparent retries exhausted. Conflicts with higher priority transaction: txn-42',
		'ERROR: Query error: Restart read required',
		'ERROR: Operation expired: Heartbeat: Transaction txn-42 expired or aborted by a conflict',
		'ERROR: All transparent retries exhausted. Operation failed. Try again: Value write after transaction start',
		'ERROR: Operation failed. Try again: kConflict',
	] as $message){
		$t->isTrue(SqlError::isTransientTransactionException(new RuntimeException($message)),$message);
	}

	$wrapped=new RuntimeException('transaction callback failed',0,new RuntimeException(
		'ERROR: Conflicts with higher priority transaction: txn-84',
	));
	$t->isTrue(SqlError::isTransientTransactionException($wrapped));

	foreach([
		'Operation failed. Try again after correcting the duplicate key.',
		'All retries exhausted while validating a permanent schema error.',
		'Read restart policy is disabled by configuration.',
		'Conflict resolution documentation is unavailable.',
		'duplicate key value violates unique constraint',
	] as $message){
		$t->isFalse(SqlError::isTransientTransactionException(new RuntimeException($message)),$message);
	}
})->tag('sql','transaction','retry','yugabytedb','regression')->group('framework-regression');
