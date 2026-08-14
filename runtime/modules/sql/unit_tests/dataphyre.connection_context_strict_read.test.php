<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\ConnectionContext;
use Dataphyre\Database\DB;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

final class DpConnectionContextStrictReadFixture {
	public static mixed $result=[];
	public static ?array $lastError=null;
	/** @var list<array<int,mixed>> */
	public static array $calls=[];
}

if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		DpConnectionContextStrictReadFixture::$calls[]=$arguments;
		return DpConnectionContextStrictReadFixture::$result;
	}
}
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {
		public static function last_query_error(): ?array { return \\DpConnectionContextStrictReadFixture::$lastError; }
	}');
}
if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG',['datacenter'=>'test']);
if(!defined('DP_SQL_CFG')) define('DP_SQL_CFG',[
	'default_cluster'=>'primary',
	'datacenters'=>['test'=>['dbms_clusters'=>[
		'primary'=>['dbms'=>'postgresql'],
		'analytics'=>['dbms'=>'postgresql'],
		'mysql-primary'=>['dbms'=>'mysql'],
		'sqlite-primary'=>['dbms'=>'sqlite'],
	]]],
]);

framework(['sql']);

test('strict raw DB and connection reads preserve empty rows and fail closed on errors',static function(Context $t): void {
	DpConnectionContextStrictReadFixture::$calls=[];
	DpConnectionContextStrictReadFixture::$lastError=null;
	$connection=new ConnectionContext('analytics');

	DpConnectionContextStrictReadFixture::$result=[['id'=>7],['id'=>8]];
	$t->same([['id'=>7],['id'=>8]],$connection->rowsOrFailOnReadError(
		'SELECT id FROM records',['tenant'=>7],['strict-rows'],['records'],
	));
	$rowsCall=DpConnectionContextStrictReadFixture::$calls[array_key_last(DpConnectionContextStrictReadFixture::$calls)];
	$t->same('analytics',$rowsCall[0]['dbms_cluster_override'] ?? null);
	$t->same(['tenant'=>7],$rowsCall[1] ?? null);
	$t->same(true,$rowsCall[2] ?? null);
	$t->same(true,$rowsCall[3] ?? null);
	$t->same(['strict-rows'],$rowsCall[4] ?? null);
	$t->same(['records'],$rowsCall[5] ?? null);

	DpConnectionContextStrictReadFixture::$result=['id'=>7,'name'=>'Ada'];
	$t->same(['id'=>7,'name'=>'Ada'],$connection->rowOrFailOnReadError(
		'SELECT * FROM records LIMIT 1',[7],'strict-row',true,
	));
	$rowCall=DpConnectionContextStrictReadFixture::$calls[array_key_last(DpConnectionContextStrictReadFixture::$calls)];
	$t->same('analytics',$rowCall[0]['dbms_cluster_override'] ?? null);
	$t->same([7],$rowCall[1] ?? null);
	$t->same(false,$rowCall[2] ?? null);
	$t->same(false,$rowCall[3] ?? null);
	$t->same('strict-row',$rowCall[4] ?? null);
	$t->same(true,$rowCall[5] ?? null);

	DpConnectionContextStrictReadFixture::$result=false;
	DpConnectionContextStrictReadFixture::$lastError=null;
	$t->same([],$connection->rowsOrFailOnReadError('SELECT id FROM records WHERE false'));
	$t->same(null,$connection->rowOrFailOnReadError('SELECT id FROM records WHERE false'));
	foreach(['mysql-primary','sqlite-primary'] as $cluster){
		$other=new ConnectionContext($cluster);
		$t->throws(static fn()=>$other->rowsOrFailOnReadError('SELECT id FROM records WHERE false'),RuntimeException::class);
		$t->throws(static fn()=>$other->rowOrFailOnReadError('SELECT id FROM records WHERE false'),RuntimeException::class);
	}

	DpConnectionContextStrictReadFixture::$lastError=['message'=>'connection lost'];
	$t->throws(static fn()=>$connection->rowsOrFailOnReadError('SELECT broken'),RuntimeException::class);
	$t->throws(static fn()=>$connection->rowOrFailOnReadError('SELECT broken'),RuntimeException::class);
	try{
		$connection->rowsOrFailOnReadError('SELECT broken');
		$t->fail('Expected the strict raw read to fail.');
	}catch(RuntimeException $exception){
		$t->contains('cluster: analytics',$exception->getMessage());
		$t->contains('result_type: bool',$exception->getMessage());
	}

	DpConnectionContextStrictReadFixture::$lastError=null;
	foreach([null,true,'malformed'] as $malformed){
		DpConnectionContextStrictReadFixture::$result=$malformed;
		$t->throws(static fn()=>$connection->rowsOrFailOnReadError('SELECT malformed'),RuntimeException::class);
		$t->throws(static fn()=>$connection->rowOrFailOnReadError('SELECT malformed'),RuntimeException::class);
	}

	DpConnectionContextStrictReadFixture::$result=[];
	$t->same([],DB::rowsOrFailOnReadError('SELECT id FROM records WHERE false'));
	DpConnectionContextStrictReadFixture::$result=['id'=>9];
	$t->same(['id'=>9],DB::rowOrFailOnReadError('SELECT * FROM records LIMIT 1'));
})->tag('sql','database','connection-context','strict-read','postgresql','regression')->group('framework-regression');
