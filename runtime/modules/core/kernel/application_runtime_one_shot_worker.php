<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed post-exec dispatcher for the only Cloud-supported application operations. */
if(PHP_SAPI!=='cli' || ($argc ?? 0)<3 || ($argc ?? 0)>8) exit(64);
require_once __DIR__.'/application_runtime_child_environment.php';
try{DataphyreApplicationRuntimeChildEnvironment::consumeInherited('one-shot');}
catch(Throwable){exit(78);}
$operation=$argv[1] ?? null;$target=$argv[2] ?? null;$arguments=array_slice($argv,3);
if(!in_array($operation,[
	'database_identity','application_preflight','artisan_migrate',
	'dataphyre_materialize_tables','dataphyre_postgresql_migrate','dataphyre_sqlite_migrate',
	'dataphyre_shared_cache_probe',
],true) || !is_string($target) || is_link($target) || !is_file($target)) exit(64);
$expected=match($operation){
	'database_identity'=>__DIR__.'/application_runtime_database_identity.php',
	'application_preflight'=>__DIR__.'/application_release_preflight.php',
	'artisan_migrate'=>'/app/artisan',
	'dataphyre_materialize_tables'=>dirname(__DIR__,3).'/modules/sql/kernel/materialize_registered_tables.php',
	'dataphyre_postgresql_migrate'=>dirname(__DIR__,3).'/modules/sql/kernel/postgresql_migrate.php',
	'dataphyre_sqlite_migrate'=>dirname(__DIR__,3).'/modules/sql/kernel/sqlite_migrate.php',
	'dataphyre_shared_cache_probe'=>dirname(__DIR__,3).'/modules/cache/kernel/shared_cache_probe.php',
};
$real=realpath($target);$expectedReal=realpath($expected);
if(!is_string($real) || !is_string($expectedReal) || !hash_equals($expectedReal,$real)) exit(64);
$GLOBALS['argv']=[$real,...$arguments];$GLOBALS['argc']=count($GLOBALS['argv']);
$_SERVER['argv']=$GLOBALS['argv'];$_SERVER['argc']=$GLOBALS['argc'];
$_SERVER['SCRIPT_FILENAME']=$real;
require $real;exit(0);
