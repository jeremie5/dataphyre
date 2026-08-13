<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\DB;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

function dp_transaction_advisory_lock_state(): TestState {
	return TestState::channel('sql.transaction-advisory-lock');
}

if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		$query=$arguments[0] ?? '';
		$cluster=is_array($query) ? (string)($query['dbms_cluster_override'] ?? '') : '';
		$sql=is_array($query)
			? (string)($query['postgresql'] ?? $query['mysql'] ?? $query['sqlite'] ?? '')
			: (string)$query;
		$state=dp_transaction_advisory_lock_state();
		$state->append('queries', ['cluster'=>$cluster, 'sql'=>$sql, 'vars'=>$arguments[1] ?? null]);
		if(str_contains($sql, 'version()')){
			return match($cluster){
				'yb-new'=>'PostgreSQL 15.2-YB-2025.1.2.0-b0 on x86_64-pc-linux-gnu',
				'yb-old'=>'PostgreSQL 11.2-YB-2024.2.8.0-b0 on x86_64-pc-linux-gnu',
				'yb-legacy'=>'PostgreSQL 11.2-YB-2.25.2.0-b0 on x86_64-pc-linux-gnu',
				default=>'PostgreSQL 16.4 on x86_64-pc-linux-gnu',
			};
		}
		if(str_contains($sql, 'pg_advisory_xact_lock')) return $cluster!=='pg-fail';
		return true;
	}
}
if(!function_exists('sql_begin')){
	function sql_begin(?string $cluster=null): bool {
		dp_transaction_advisory_lock_state()->append('transactions', ['begin', $cluster]);
		return true;
	}
}
if(!function_exists('sql_commit')){
	function sql_commit(?string $cluster=null): bool {
		dp_transaction_advisory_lock_state()->append('transactions', ['commit', $cluster]);
		return true;
	}
}
if(!function_exists('sql_rollback')){
	function sql_rollback(?string $cluster=null): bool {
		dp_transaction_advisory_lock_state()->append('transactions', ['rollback', $cluster]);
		return true;
	}
}

if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG', ['datacenter'=>'test']);
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG', [
		'default_cluster'=>'pg',
		'datacenters'=>['test'=>['dbms_clusters'=>[
			'pg'=>['dbms'=>'postgresql'],
			'pg-nested'=>['dbms'=>'postgresql'],
			'pg-fail'=>['dbms'=>'postgresql'],
			'yb-new'=>['dbms'=>'postgresql'],
			'yb-old'=>['dbms'=>'postgresql'],
			'yb-legacy'=>['dbms'=>'postgresql'],
			'mysql'=>['dbms'=>'mysql'],
		]]],
	]);
}
framework(['sql']);

function dp_transaction_advisory_lock_scenario(Context $t): TestState {
	return $t->state('sql.transaction-advisory-lock', ['queries'=>[], 'transactions'=>[]]);
}

test('transaction advisory locks require a supported active transaction and canonical prepared key', static function(Context $t): void {
	$state=dp_transaction_advisory_lock_scenario($t);
	$t->throws(static fn(): bool=>DB::transactionAdvisoryLock('outside', 'pg'), RuntimeException::class);
	$t->throws(static fn(): bool=>DB::transactionAdvisoryLock('  ', 'pg'), InvalidArgumentException::class);
	$t->throws(
		static fn(): mixed=>DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('unsupported', 'mysql'), 'mysql'),
		RuntimeException::class
	);

	$result=DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('  tenant:10:permission:a  ', 'pg'), 'pg');
	$t->isTrue($result);
	$locks=array_values(array_filter(
		$state->get('queries', []),
		static fn(array $query): bool=>str_contains((string)$query['sql'], 'pg_advisory_xact_lock')
	));
	$t->same(1, count($locks));
	$t->same(['tenant:10:permission:a'], $locks[0]['vars'] ?? null);
	$t->same(true, str_contains((string)($locks[0]['sql'] ?? ''), 'hashtextextended(?, 0)'));
})->tag('database', 'sql', 'transaction', 'advisory-lock', 'postgresql', 'unit');

test('transaction advisory locks share implicit default-cluster bookkeeping', static function(Context $t): void {
	$state=dp_transaction_advisory_lock_scenario($t);
	$t->isTrue(DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('implicit-default')));
	$locks=array_values(array_filter(
		$state->get('queries', []),
		static fn(array $query): bool=>str_contains((string)$query['sql'], 'pg_advisory_xact_lock')
	));
	$t->same(1,count($locks));
	$t->same('pg',$locks[0]['cluster'] ?? null);
	$t->same(['implicit-default'],$locks[0]['vars'] ?? null);
})->tag('database','sql','transaction','advisory-lock','default-cluster','regression','unit');

test('transaction advisory locks work inside nested Dataphyre transactions without session locks', static function(Context $t): void {
	$state=dp_transaction_advisory_lock_scenario($t);
	$result=DB::transaction(static function(): bool {
		return DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('nested', 'pg-nested'), 'pg-nested');
	}, 'pg-nested');
	$t->isTrue($result);
	$sql=array_column($state->get('queries', []), 'sql');
	$t->same(true, count(array_filter($sql, static fn(string $query): bool=>str_starts_with($query, 'SAVEPOINT ')))===1);
	$t->same(true, count(array_filter($sql, static fn(string $query): bool=>str_starts_with($query, 'RELEASE SAVEPOINT ')))===1);
	$t->same(true, count(array_filter($sql, static fn(string $query): bool=>str_contains($query, 'pg_advisory_xact_lock')))===1);
	$t->same(false, count(array_filter($sql, static fn(string $query): bool=>str_contains($query, 'pg_advisory_lock(')))>0);
})->tag('database', 'sql', 'transaction', 'advisory-lock', 'nested', 'unit');

test('transaction advisory locks enforce the YugabyteDB release floor and fail closed on acquisition errors', static function(Context $t): void {
	dp_transaction_advisory_lock_scenario($t);
	$t->isTrue(DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('supported', 'yb-new'), 'yb-new'));
	$t->throws(
		static fn(): mixed=>DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('old', 'yb-old'), 'yb-old'),
		RuntimeException::class
	);
	$t->throws(
		static fn(): mixed=>DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('unverifiable', 'yb-legacy'), 'yb-legacy'),
		RuntimeException::class
	);
	$t->throws(
		static fn(): mixed=>DB::transaction(static fn(): bool=>DB::transactionAdvisoryLock('failed', 'pg-fail'), 'pg-fail'),
		RuntimeException::class
	);
})->tag('database', 'sql', 'transaction', 'advisory-lock', 'yugabytedb', 'release-guard', 'unit');
