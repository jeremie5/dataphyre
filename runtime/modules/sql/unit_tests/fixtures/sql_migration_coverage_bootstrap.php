<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\TestState;

if(!function_exists('dp_migration_state')){
	function dp_migration_state(): ?TestState {
		return TestState::channelIfActive('sql.migration');
	}
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void { dp_migration_state()?->append('traces',$arguments); }
}
if(!function_exists('yaml_parse_file')){
	function yaml_parse_file(string $path): mixed { return dp_migration_state()?->get('yaml_plans',[])[$path] ?? false; }
}
if(!function_exists('yaml_emit')){
	function yaml_emit(mixed $value): string {
		dp_migration_state()?->append('yaml_emits',$value);
		return (string)json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	}
}

if(!class_exists('dataphyre\\core',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function unavailable(mixed ...$arguments): never { \dp_migration_state()?->append('unavailable',$arguments); throw new \RuntimeException('migration unavailable'); }
	public static function log(mixed ...$arguments): void { \dp_migration_state()?->append('logs',$arguments); }
}
PHP);
}
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class sql {
	public static function query(mixed $statement): mixed {
		$state=\dp_migration_state();
		$state?->append('queries',$statement);
		if($state?->get('query_throw',false)===true){ throw new \RuntimeException('query failed'); }
		return $state?->get('query_result',true) ?? true;
	}
	public static function select(mixed ...$arguments): mixed {
		$state=\dp_migration_state();
		$state?->append('select_calls',$arguments);
		return $state?->shift('select_results',[]) ?? [];
	}
}
PHP);
}

if(!defined('DP_CORE_CFG')){
	define('DP_CORE_CFG',['datacenter'=>'migration-dc']);
}
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG',[
		'default_cluster'=>'primary',
		'datacenters'=>[
			'migration-dc'=>[
				'dbms_clusters'=>[
					'primary'=>['dbms'=>defined('DP_MIGRATION_TEST_DBMS') ? DP_MIGRATION_TEST_DBMS : 'mysql'],
				],
			],
		],
	]);
}

$dpMigrationRoot=\Dataphyre\Test\dataphyre_path();
require_once $dpMigrationRoot.'/runtime/modules/sql/kernel/migration.php';

final class DpMigrationFixture {
	public string $versions;
	public string $lock;
	public string $snapshots;

	public function __construct(
		public TestState $state,
		public TempWorkspace $workspace,
		public NonPublicAccess $migration,
	) {
		$this->versions=$workspace->path('table_versions.json');
		$this->lock=$workspace->path('migrating');
		$this->snapshots=$workspace->directory('snapshots').'/';
	}

	public function selectResults(mixed ...$results): self {
		$this->state->put('select_results',$results);
		return $this;
	}

	public function firstEmit(): array {
		$emits=$this->state->get('yaml_emits',[]);
		return isset($emits[0]) && is_array($emits[0]) ? $emits[0] : [];
	}
}

function dp_migration_sandbox(Context $t,string $name='scenario'): DpMigrationFixture {
	$state=$t->state('sql.migration',[
		'traces'=>[],
		'yaml_plans'=>[],
		'yaml_emits'=>[],
		'queries'=>[],
		'query_throw'=>false,
		'query_result'=>true,
		'select_calls'=>[],
		'select_results'=>[],
		'unavailable'=>[],
		'logs'=>[],
	]);
	$workspace=$t->workspace('dataphyre-migration-'.$name);
	$workspace->directory('plans/common');
	$workspace->directory('plans/dataphyre');
	$internals=$t->nonPublic(\dataphyre\sql\migration::class);
	$fixture=new DpMigrationFixture($state,$workspace,$internals);
	$internals->replacePropertyForTest('migration_roots',[
		'common_dataphyre'=>$workspace->path('plans/common').'/',
		'dataphyre'=>$workspace->path('plans/dataphyre').'/',
	]);
	$internals->replacePropertyForTest('version_file',$fixture->versions);
	$internals->replacePropertyForTest('lock_file',$fixture->lock);
	$internals->replacePropertyForTest('snapshot_dir',$fixture->snapshots);
	return $fixture;
}
