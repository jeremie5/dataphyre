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

define('DP_SQL_KERNEL_TEST_DBMS','sqlite');
define('DATAPHYRE_FLIGHTDECK_REPLAY_READONLY',true);
require_once __DIR__.'/fixtures/sql_kernel_main_coverage_bootstrap.php';
require_once __DIR__.'/fixtures/sql_kernel_main_operations_helpers.php';

test('sql kernel main readonly deep coverage blocks every mutation cache and queue path',static function(Context $t): void {
	$fixture=dp_sql_kernel_reset($t);
	dp_sql_kernel_install_driver_results('sqlite');
	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	$fixture->session->put('db_cache',['items'=>['hash'=>[['id'=>1],time()]]]);
	\dataphyre\sql::session_cache_gc();
	$t->same(0,$fixture->session->get('db_cache_count',0));
	$t->same(null,\dataphyre\sql::execute_queue('end'));
	$t->isTrue(\dataphyre\sql::cache_query_result('items','hash',['id'=>1],['named'],['type'=>'session','max_lifespan'=>'1 minute','hash_type'=>'md5']));
	$t->isTrue(\dataphyre\sql::invalidate_cache(['named']));
	$t->isTrue(\dataphyre\sql::invalidate_cache('items'));
	$t->isFalse(\dataphyre\sql::query('UPDATE items SET active=1',null,false,false,false,false,null));
	$t->same(false,\dataphyre\sql::insert('items',['name'=>'Ada'],null,false,null));
	$t->same(null,\dataphyre\sql::insert('items',['name'=>'Ada'],null,false,'end',static function(): void {}));
	$t->same(0,\dataphyre\sql::update('items',['name'=>'Ada'],'WHERE id=?',[1],false,null));
	$t->same(null,\dataphyre\sql::update('items',['name'=>'Ada'],'WHERE id=?',[1],false,'end',static function(): void {}));
	$t->same(0,\dataphyre\sql::delete('items','WHERE id=?',[1],false,null));
	$t->same(null,\dataphyre\sql::delete('items','WHERE id=?',[1],false,'end',static function(): void {}));
	$t->same(false,\dataphyre\sql::upsert('items',['sqlite'=>['id'=>1]],null,null,false,null));
	$t->same(null,\dataphyre\sql::upsert('items',['sqlite'=>['id'=>1]],null,null,false,'end',static function(): void {}));
	$t->isTrue($fixture->writeBlocks->value(0)>=12);
	$t->isTrue(count(array_filter($events,static fn(array $event): bool=>($event['event'] ?? '')==='readonly_block'))>=12);
	$t->same('readonly-replay',\dataphyre\sql::last_query_error()['dbms'] ?? null);
})->tag('sql','kernel','main','readonly','deep-coverage')->group('framework-coverage')->maxMillis(10000);
