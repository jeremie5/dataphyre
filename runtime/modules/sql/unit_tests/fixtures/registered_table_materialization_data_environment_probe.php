<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\DataEnvironment;
use Dataphyre\Database\RegisteredTableMaterializationCommand;

define('DP_CORE_CFG',['datacenter'=>'fixture']);
define('DP_SQL_CFG',[
	'data_environments'=>[
		'sandbox'=>['cluster'=>'SandboxCluster','cache_namespace'=>'fixture-sandbox'],
		'unbound'=>['cache_namespace'=>'fixture-unbound'],
	],
	'datacenters'=>[
		'fixture'=>['dbms_clusters'=>['SandboxCluster'=>[]]],
	],
]);

require_once \dirname(__DIR__,2).'/Framework/SqlError.php';
require_once \dirname(__DIR__,2).'/Framework/DataEnvironment.php';
require_once \dirname(__DIR__,2).'/Framework/RegisteredTableMaterializationCommand.php';

$project=\dirname(__DIR__,4);
$run=static function(string|false $purpose,bool $throw=false) use ($project): array {
	$out='';$error='';$observed=[];$attempted=0;
	$runtime=[
		'bootstrap'=>static function(): void {},
		'registered_tables'=>static fn(): array=>['fixture.alpha'],
		'materialize'=>static function(string $table) use (&$observed,&$attempted,$throw): bool {
			$attempted++;
			$observed[]=['table'=>$table,...DataEnvironment::current()];
			if($throw) throw new RuntimeException('fixture hydration failure');
			return true;
		},
		'write_out'=>static function(string $value) use (&$out): int {$out.=$value;return \strlen($value);},
		'write_error'=>static function(string $value) use (&$error): int {$error.=$value;return \strlen($value);},
	];
	if($purpose!==false) $runtime['managed_database_purpose']=$purpose;
	$status=RegisteredTableMaterializationCommand::main([
		'materialize_registered_tables.php','--project-root='.$project,
		'--application=fixture','--environment=production',
	],$runtime);
	$payload=\json_decode($out!=='' ? $out : $error,true,32,\JSON_THROW_ON_ERROR);
	return [
		'after'=>DataEnvironment::current(),'attempted'=>$attempted,'observed'=>$observed,
		'payload'=>$payload,'status'=>$status,
	];
};

\fwrite(\STDOUT,\json_encode([
	'absent'=>$run(false),
	'primary'=>$run('primary'),
	'sandbox_failure'=>$run('sandbox',true),
	'sandbox_success'=>$run('sandbox'),
	'unbound'=>$run('unbound'),
],\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES).\PHP_EOL);
