<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\GlobalState;
use Dataphyre\Test\TestState;

function dp_sql_kernel_driver_hooks(string $dbms): array {
	$prefix=$dbms==='postgresql' ? 'CALL_POSTGRESQL_SIMPLE_' : 'CALL_SQL_SIMPLE_';
	return [
		'select'=>$prefix.'SELECT',
		'count'=>$prefix.'COUNT',
		'insert'=>$prefix.'INSERT',
		'update'=>$prefix.'UPDATE',
		'delete'=>$prefix.'DELETE',
	];
}

function dp_sql_kernel_install_driver_results(string $dbms,array $results=[]): void {
	$state=TestState::channel('sql.kernel-main');
	$state->put('driver_results',array_replace([
		'select'=>[['id'=>1,'name'=>'Ada']],
		'count'=>2,
		'insert'=>true,
		'update'=>1,
		'delete'=>1,
	],$results));
	foreach(dp_sql_kernel_driver_hooks($dbms) as $operation=>$hook){
		dp_sql_kernel_dialback($state,$hook,static fn(mixed ...$arguments): mixed=>dp_sql_kernel_driver_value($state,$operation));
	}
}

function dp_sql_kernel_driver_value(TestState $state,string $operation): mixed {
	return $state->get('driver_results',[])[$operation] ?? null;
}

function dp_sql_kernel_driver_result(TestState $state,string $operation,mixed $result): void {
	$state->put('driver_results',array_replace($state->get('driver_results',[]),[$operation=>$result]));
}

function dp_sql_kernel_dialback(TestState $state,string $hook,mixed $response): void {
	$state->put('dialbacks',array_replace($state->get('dialbacks',[]),[$hook=>$response]));
}

function dp_sql_kernel_forget_dialback(TestState $state,string $hook): void {
	$dialbacks=$state->get('dialbacks',[]);
	unset($dialbacks[$hook]);
	$state->put('dialbacks',$dialbacks);
}

function dp_sql_kernel_corrupt_latest_cache(GlobalState $session,string $table,mixed $value): void {
	$cache=$session->get('db_cache',[]);
	$hashes=array_keys($cache[$table] ?? []);
	$hash=(string)end($hashes);
	$cache[$table][$hash]=[$value,time()];
	$session->put('db_cache',$cache);
}

function dp_sql_kernel_queue_for(string $dbms): array {
	return match($dbms){
		'mysql'=>\dataphyre\mysql_query_builder::$queued_queries,
		'postgresql'=>\dataphyre\postgresql_query_builder::$queued_queries,
		default=>\dataphyre\sqlite_query_builder::$queued_queries,
	};
}

function dp_sql_kernel_clear_driver_queues(): void {
	\dataphyre\mysql_query_builder::$queued_queries=[];
	\dataphyre\postgresql_query_builder::$queued_queries=[];
	\dataphyre\sqlite_query_builder::$queued_queries=[];
}

function dp_sql_kernel_run_immediate_operations(Context $t,string $dbms): void {
	$fixture=dp_sql_kernel_reset($t);
	$state=$fixture->state;
	$session=$fixture->session;
	dp_sql_kernel_install_driver_results($dbms);
	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::query('SELECT 1',[true,false],true,false,false,false,null));
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::query([$dbms=>'SELECT 2'],[$dbms=>[true]],true,false,false,[],null));
	$t->isFalse(\dataphyre\sql::query(['other'=>'SELECT 3'],null,false,false,false,false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_SELECT',static fn(mixed ...$arguments): array=>['early']);
	$t->same(['early'],\dataphyre\sql::query('SELECT early'));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_SELECT');

	$session->put('db_cache_count',0);
	$cached=\dataphyre\sql::query('SELECT cached',[1],true,false,[true,'raw-bundle'],false,null);
	$t->same([['id'=>1,'name'=>'Ada']],$cached);
	dp_sql_kernel_driver_result($state,'select',[['id'=>99]]);
	$callbackValue=null;
	$t->same($cached,\dataphyre\sql::query('SELECT cached',[1],true,false,[true,'raw-bundle'],false,null,static function($value)use(&$callbackValue): void {$callbackValue=$value;}));
	$t->same($cached,$callbackValue);
	dp_sql_kernel_driver_result($state,'select',[['id'=>1,'name'=>'Ada']]);
	$cachePolicyWarnings=[];
	set_error_handler(static function(int $severity,string $message)use(&$cachePolicyWarnings): bool {
		if(str_contains($message,'$cache_policy')){
			$cachePolicyWarnings[]=$message;
			return true;
		}
		return false;
	});
	try{
		$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::query('SELECT invalidate',null,true,false,false,true,null));
	}finally{
		restore_error_handler();
	}
	$t->same([],$cachePolicyWarnings);
	$session->put('db_cache_count',0);
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::query('SELECT cached invalidate',null,true,false,[true],true,null));
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::query('SELECT named invalidate',null,true,false,false,['raw-bundle'],null));

	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::select('*','items','WHERE id=?',[true],true,false,null));
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::select([$dbms=>'*'],'items',[$dbms=>'WHERE id=?'],[$dbms=>[false]],true,false,null));
	$t->isFalse(\dataphyre\sql::select(['other'=>'*'],'items','WHERE id=?',[1],true,false,null));
	$t->isFalse(\dataphyre\sql::select('*','items',['other'=>'WHERE id=?'],[1],true,false,null));
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::select('*','items',null,null,false,false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_SELECT',static fn(mixed ...$arguments): string=>'select-early');
	$t->same('select-early',\dataphyre\sql::select('*','items'));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_SELECT');

	$session->put('db_cache_count',0);
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::select('id','items','WHERE id=?',[1],true,[true],null));
	$selectCallback=null;
	$t->same([['id'=>1,'name'=>'Ada']],\dataphyre\sql::select('id','items','WHERE id=?',[1],true,[true],null,static function($value)use(&$selectCallback): void {$selectCallback=$value;}));
	$t->same([['id'=>1,'name'=>'Ada']],$selectCallback);
	dp_sql_kernel_corrupt_latest_cache($session,'items',7);
	$t->isFalse(\dataphyre\sql::select('id','items','WHERE id=?',[1],true,[true],null));
	dp_sql_kernel_driver_result($state,'select',[['id'=>2]]);
	$t->same([['id'=>2]],\dataphyre\sql::select('name','items','WHERE id=?',[2],true,[true],null));
	dp_sql_kernel_corrupt_latest_cache($session,'items',['not-a-row']);
	$t->isFalse(\dataphyre\sql::select('name','items','WHERE id=?',[2],true,[true],null));
	dp_sql_kernel_driver_result($state,'select',false);
	$t->isFalse(\dataphyre\sql::select('*','missing_items','WHERE id=?',[1],true,false,null));
	dp_sql_kernel_driver_result($state,'select',[['id'=>1,'name'=>'Ada']]);

	$t->same(2,\dataphyre\sql::count('items','WHERE active=?',[true],false,null));
	$t->same(2,\dataphyre\sql::count('items',[$dbms=>'WHERE active=?'],[$dbms=>[false]],false,null));
	$t->isFalse(\dataphyre\sql::count('otherdb:items','WHERE active=?',[1],false,null));
	$t->isFalse(\dataphyre\sql::count('items',['other'=>'WHERE active=?'],[1],false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_COUNT',static fn(mixed ...$arguments): int=>41);
	$t->same(41,\dataphyre\sql::count('items'));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_COUNT');
	$session->put('db_cache_count',0);
	$t->same(2,\dataphyre\sql::count('items','WHERE id=?',[9],[true],null));
	$countCallback=null;
	$t->same(2,\dataphyre\sql::count('items','WHERE id=?',[9],[true],null,static function($value)use(&$countCallback): void {$countCallback=$value;}));
	$t->same(2,$countCallback);
	dp_sql_kernel_corrupt_latest_cache($session,'items',['bad']);
	$t->isFalse(\dataphyre\sql::count('items','WHERE id=?',[9],[true],null));
	dp_sql_kernel_driver_result($state,'count',false);
	$t->isFalse(\dataphyre\sql::count('missing_items','WHERE id=?',[1],false,null));
	dp_sql_kernel_driver_result($state,'count',2);

	$t->isFalse(\dataphyre\sql::insert('items',['name'=>'Ada'],['unexpected'],false,null));
	$t->isTrue(\dataphyre\sql::insert('items',['name'=>'Ada','active'=>true,'metadata'=>['role'=>'admin']],null,false,null));
	$t->isTrue(\dataphyre\sql::insert('items','name,active',[$dbms=>['Grace',false]],['bundle'],null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_INSERT',static fn(mixed ...$arguments): int=>77);
	$t->same(77,\dataphyre\sql::insert('items','name',['early'],false,null));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_INSERT');
	dp_sql_kernel_driver_result($state,'insert',false);
	$t->isFalse(\dataphyre\sql::insert('missing_items','name',['Ada'],false,null));
	dp_sql_kernel_driver_result($state,'insert',true);

	$t->same(1,\dataphyre\sql::update('items',['name'=>'Ada','active'=>true,'metadata'=>['role'=>'admin']],'WHERE id=?',[1],true,null));
	$t->same(1,\dataphyre\sql::update('items',[$dbms=>'name=?'],[$dbms=>'WHERE id=?'],[$dbms=>['Grace',2]],['bundle'],null));
	$t->isFalse(\dataphyre\sql::update('items','name=?',['other'=>'WHERE id=?'],['Grace',2],false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_UPDATE',static fn(mixed ...$arguments): int=>12);
	$t->same(12,\dataphyre\sql::update('items','name=?','WHERE id=?',['Early',1],false,null));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_UPDATE');
	dp_sql_kernel_driver_result($state,'update',false);
	$t->isFalse(\dataphyre\sql::update('missing_items','name=?','WHERE id=?',['Ada',1],false,null));
	dp_sql_kernel_driver_result($state,'update',1);

	$t->same(1,\dataphyre\sql::delete('items','WHERE id=?',[true],true,null));
	$t->same(1,\dataphyre\sql::delete('items',[$dbms=>'WHERE id=?'],[$dbms=>[false]],['bundle'],null));
	$t->isFalse(\dataphyre\sql::delete('items',['other'=>'WHERE id=?'],[1],false,null));
	$t->isFalse(\dataphyre\sql::delete('items',null,null,false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_DELETE',static fn(mixed ...$arguments): int=>6);
	$t->same(6,\dataphyre\sql::delete('items','WHERE id=?',[1],false,null));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_DELETE');
	dp_sql_kernel_driver_result($state,'delete',false);
	$t->isFalse(\dataphyre\sql::delete('missing_items','WHERE id=?',[1],false,null));
	dp_sql_kernel_driver_result($state,'delete',1);

	$nativeFields=$dbms==='postgresql'
		? ['postgresql'=>['columns'=>['id'=>1,'active'=>true,'metadata'=>['role'=>'admin']],'conflict_keys'=>['id']]]
		: [$dbms=>['id'=>1,'active'=>true,'metadata'=>['role'=>'admin']]];
	$t->isTrue(\dataphyre\sql::upsert('items',$nativeFields,null,[$dbms=>[]],true,null));
	dp_sql_kernel_driver_result($state,'select',false);
	$t->isFalse(\dataphyre\sql::upsert('missing_items',$nativeFields,null,null,false,null));
	dp_sql_kernel_driver_result($state,'select',[['id'=>1]]);
	if($dbms==='postgresql'){
		$t->isFalse(\dataphyre\sql::upsert('items',['postgresql'=>['columns'=>['id'=>1]]],null,null,false,null));
	}
	dp_sql_kernel_driver_result($state,'update',1);
	$t->same(1,\dataphyre\sql::upsert('items',['id'=>1,'name'=>'Ada'],'WHERE id=?',[1],false,null));
	dp_sql_kernel_driver_result($state,'update',0);
	$t->isTrue(\dataphyre\sql::upsert('items',['id'=>1,'name'=>'Ada'],'WHERE id=?',[1],false,null));
	dp_sql_kernel_driver_result($state,'update',false);
	$t->isFalse(\dataphyre\sql::upsert('items',['id'=>1,'name'=>'Ada'],'WHERE id=?',[1],false,null));
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_UPSERT',static fn(mixed ...$arguments): int=>9);
	$t->same(9,\dataphyre\sql::upsert('items',['id'=>1],'WHERE id=?',[1],false,null));
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_UPSERT');

	$definitionFile=__DIR__.'/sql_kernel_table_definition_callable.php';
	$hooks=dp_sql_kernel_driver_hooks($dbms);
	$retrySelectCalls=0;
	\dataphyre\sql::define_table('retry_select',$definitionFile);
	dp_sql_kernel_dialback($state,$hooks['select'],static function(mixed ...$arguments)use(&$retrySelectCalls): array|bool {
		if(++$retrySelectCalls===1){
			\dataphyre\sql::log_query_error('test','main','SELECT * FROM retry_select',[],new RuntimeException('no such table: retry_select'));
			return false;
		}
		return [['id'=>1]];
	});
	$t->same([['id'=>1]],\dataphyre\sql::select('*','retry_select','WHERE id=?',[1],true,false,null));
	dp_sql_kernel_install_driver_results($dbms);
	foreach([
		'count'=>['retry_count',static fn()=>\dataphyre\sql::count('retry_count','WHERE id=?',[1],false,null),2],
		'insert'=>['retry_insert',static fn()=>\dataphyre\sql::insert('retry_insert',['name'=>'Ada'],null,false,null),true],
		'update'=>['retry_update',static fn()=>\dataphyre\sql::update('retry_update',['name'=>'Ada'],'WHERE id=?',[1],false,null),1],
		'delete'=>['retry_delete',static fn()=>\dataphyre\sql::delete('retry_delete','WHERE id=?',[1],false,null),1],
	] as $operation=>$retryCase){
		[$table,$invoke,$expected]=$retryCase;
		\dataphyre\sql::define_table($table,$definitionFile);
		$calls=0;
		$success=dp_sql_kernel_driver_value($state,$operation);
		dp_sql_kernel_dialback($state,$hooks[$operation],static function(mixed ...$arguments)use(&$calls,$table,$success): mixed {
			if(++$calls===1){
				\dataphyre\sql::log_query_error('test','main',strtoupper($table).' '.$table,[],new RuntimeException('no such table: '.$table));
				return false;
			}
			return $success;
		});
		$t->same($expected,$invoke());
		dp_sql_kernel_install_driver_results($dbms);
	}
	$retryUpsert='retry_upsert';
	\dataphyre\sql::define_table($retryUpsert,$definitionFile);
	$retryUpsertCalls=0;
	dp_sql_kernel_dialback($state,$hooks['select'],static function(mixed ...$arguments)use(&$retryUpsertCalls,$retryUpsert): array|bool {
		if(++$retryUpsertCalls===1){
			\dataphyre\sql::log_query_error('test','main','INSERT INTO '.$retryUpsert,[],new RuntimeException('no such table: '.$retryUpsert));
			return false;
		}
		return [['id'=>1]];
	});
	$retryUpsertFields=$dbms==='postgresql'
		? ['postgresql'=>['columns'=>['id'=>1,'name'=>'Ada'],'conflict_keys'=>['id']]]
		: [$dbms=>['id'=>1,'name'=>'Ada']];
	$t->isTrue(\dataphyre\sql::upsert($retryUpsert,$retryUpsertFields,null,null,false,null));
	dp_sql_kernel_install_driver_results($dbms);

	dp_sql_kernel_driver_result($state,'select',[['ok'=>true]]);
	$t->isTrue(\dataphyre\sql::begin('main'));
	$t->isTrue(\dataphyre\sql::commit('main'));
	$t->isTrue(\dataphyre\sql::rollback('main'));
	$t->isTrue(\dataphyre\sql::transaction(static function(): void {},'main'));
	$t->isFalse(\dataphyre\sql::transaction(static function(): void {throw new RuntimeException('rollback');},'main'));
	$transaction_callback_ran=false;
	$transaction_query_calls=0;
	dp_sql_kernel_dialback(
		$state,
		'CALL_SQL_DB_SELECT',
		static function(mixed ...$arguments)use(&$transaction_query_calls): bool {
			$transaction_query_calls++;
			return false;
		}
	);
	$t->isFalse(\dataphyre\sql::transaction(
		static function()use(&$transaction_callback_ran): void {
			$transaction_callback_ran=true;
		},
		'main'
	));
	$t->isFalse($transaction_callback_ran);
	$t->same(1,$transaction_query_calls);

	$transaction_query_calls=0;
	dp_sql_kernel_dialback(
		$state,
		'CALL_SQL_DB_SELECT',
		static function(mixed ...$arguments)use(&$transaction_query_calls): bool {
			$transaction_query_calls++;
			return true;
		}
	);
	$t->isFalse(\dataphyre\sql::transaction(static fn(): bool=>false,'main'));
	$t->same(2,$transaction_query_calls);

	$transaction_query_results=[true,false,true];
	$transaction_query_calls=0;
	dp_sql_kernel_dialback(
		$state,
		'CALL_SQL_DB_SELECT',
		static function(mixed ...$arguments)use(
			&$transaction_query_calls,
			&$transaction_query_results
		): bool {
			$transaction_query_calls++;
			return (bool)array_shift($transaction_query_results);
		}
	);
	$t->isFalse(\dataphyre\sql::transaction(static fn(): bool=>true,'main'));
	$t->same(3,$transaction_query_calls);
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_DB_SELECT');
	$t->same(null,\dataphyre\sql::execute_queue('empty'));
	$t->isTrue(count($events)>=18);
	dp_sql_kernel_clear_driver_queues();
}

function dp_sql_kernel_run_queued_operations(Context $t,string $dbms): void {
	dp_sql_kernel_reset($t);
	dp_sql_kernel_install_driver_results($dbms);
	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	$callback=static function(mixed $value): void {};
	$t->same(null,\dataphyre\sql::query('SELECT queued',[true],true,false,false,['bundle'],'custom',$callback));
	$t->same(null,\dataphyre\sql::select('*','items','WHERE id=?',[false],true,false,'custom',$callback));
	$t->same(null,\dataphyre\sql::count('items','WHERE id=?',[true],false,'custom',$callback));
	$t->same(null,\dataphyre\sql::insert('items',['name'=>'Ada','active'=>true,'metadata'=>['x'=>1]],null,['bundle'],'custom',$callback));
	$t->same(null,\dataphyre\sql::update('items',['name'=>'Ada','active'=>true,'metadata'=>['x'=>1]],'WHERE id=?',[1],['bundle'],'custom',$callback));
	$t->same(null,\dataphyre\sql::delete('items','WHERE id=?',[false],['bundle'],'custom',$callback));
	$nativeFields=$dbms==='postgresql'
		? ['postgresql'=>['columns'=>['id'=>1,'active'=>true,'metadata'=>['x'=>1]],'conflict_keys'=>['id']]]
		: [$dbms=>['id'=>1,'active'=>true,'metadata'=>['x'=>1]]];
	$t->same(null,\dataphyre\sql::upsert('items',$nativeFields,null,null,['bundle'],'custom',$callback));
	$queue=dp_sql_kernel_queue_for($dbms);
	$t->isTrue(count($queue['custom'] ?? [])>=6);
	$t->isTrue(count(array_filter($events,static fn(array $event): bool=>($event['event'] ?? '')==='queue_push'))>=7);
	dp_sql_kernel_clear_driver_queues();
}
