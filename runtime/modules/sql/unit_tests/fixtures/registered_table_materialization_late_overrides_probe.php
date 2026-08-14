<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\RegisteredTableMaterializationCommand;

require_once \dirname(__DIR__,2).'/Framework/RegisteredTableMaterializationCommand.php';

$project=\dirname(__DIR__,4);
$overrides=__DIR__.'/registered_table_materialization_namespace_overrides.php';
$out='';$error='';$materialized=[];
$writeOut=static function(string $value) use (&$out): int {$out.=$value;return \strlen($value);};
$writeError=static function(string $value) use (&$error): int {$error.=$value;return \strlen($value);};
$status=RegisteredTableMaterializationCommand::main([
	'materialize_registered_tables.php','--project-root='.$project,
	'--application=fixture','--environment=production',
],[
	'bootstrap'=>static function() use ($overrides): void {require_once $overrides;},
	'registered_tables'=>static fn(): array=>['fixture.zeta','fixture.alpha'],
	'materialize'=>static function(string $table) use (&$materialized): bool {$materialized[]=$table;return true;},
	'write_out'=>$writeOut,
	'write_error'=>$writeError,
]);

$bootstrapError='';
$failureStatus=RegisteredTableMaterializationCommand::main([
	'materialize_registered_tables.php','--project-root='.$project,
	'--application=fixture','--environment=production',
],[
	'bootstrap'=>static fn()=>throw new \Dataphyre\Database\RuntimeException('late namespace class'),
	'write_out'=>static fn(string $value): int=>\strlen($value),
	'write_error'=>static function(string $value) use (&$bootstrapError): int {$bootstrapError.=$value;return \strlen($value);},
]);

\fwrite(\STDOUT,\json_encode([
	'bootstrap_failure'=>\json_decode($bootstrapError,true,32,\JSON_THROW_ON_ERROR),
	'bootstrap_status'=>$failureStatus,
	'error'=>$error,
	'inventory'=>RegisteredTableMaterializationCommand::registeredTableInventoryEvidence(),
	'materialized'=>$materialized,
	'payload'=>\json_decode($out,true,32,\JSON_THROW_ON_ERROR),
	'status'=>$status,
],\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES).\PHP_EOL);
