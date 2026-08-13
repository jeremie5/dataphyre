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
use dataphyre\sql\migration;
use function Dataphyre\Test\test;

function dp_migration_state(): ?TestState {
	return TestState::channelIfActive('sql.migration');
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void { dp_migration_state()?->append('traces',$arguments); }
}
if(!function_exists('yaml_parse_file')){
	function yaml_parse_file(string $path): mixed { return dp_migration_state()?->get('yaml_plans',[])[$path] ?? false; }
}
if(!function_exists('yaml_emit')){
	function yaml_emit(mixed $value): string { dp_migration_state()?->append('yaml_emits',$value); return (string)json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); }
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
		$query_number=count($state?->get('queries',[]) ?? []);
		if($state?->get('query_throw',false)===true
			|| $state?->get('query_throw_on',0)===$query_number
		){
			throw new \RuntimeException('query failed');
		}
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

define('DP_MIGRATION_TEST_DBMS', 'mysql');
require_once __DIR__.'/fixtures/sql_migration_coverage_bootstrap.php';

final class DpMigrationScenario {
	public string $versions;
	public string $lock;
	public string $snapshots;
	private string $commonPlans;
	private string $dataphyrePlans;

	public function __construct(
		public TestState $fixture,
		public TempWorkspace $workspace,
		public NonPublicAccess $migration,
	) {
		$this->commonPlans=$workspace->directory('plans/common').'/';
		$this->dataphyrePlans=$workspace->directory('plans/dataphyre').'/';
		$this->snapshots=$workspace->directory('snapshots').'/';
		$this->versions=$workspace->path('table_versions.json');
		$this->lock=$workspace->path('migrating');
	}

	public function plan(string $owner,string $name,mixed $plan): string {
		$directory=match($owner){
			'common_dataphyre'=>$this->commonPlans,
			'dataphyre'=>$this->dataphyrePlans,
			default=>throw new InvalidArgumentException('Unknown migration plan owner: '.$owner),
		};
		$path=$directory.$name.'.yaml';
		$this->workspace->file('plans/'.($owner==='common_dataphyre' ? 'common' : 'dataphyre').'/'.$name.'.yaml','fixture');
		$this->fixture->put('yaml_plans',array_replace($this->fixture->get('yaml_plans',[]),[$path=>$plan]));
		return $path;
	}

	public function yamlFixture(string $name,mixed $plan): string {
		$path=$this->workspace->file($name,'fixture');
		$this->fixture->put('yaml_plans',array_replace($this->fixture->get('yaml_plans',[]),[$path=>$plan]));
		return $path;
	}

	public function writeVersions(array $versions): void {
		$this->workspace->file('table_versions.json',json_encode($versions,JSON_THROW_ON_ERROR));
	}

	public function readVersions(): array {
		$value=json_decode((string)file_get_contents($this->versions),true);
		return is_array($value) ? $value : [];
	}

	public function lock(): void {
		$this->workspace->file('migrating','locked');
	}

	public function snapshot(string $table,array $columns): void {
		$this->workspace->file('snapshots/'.$table.'.json',json_encode($columns,JSON_THROW_ON_ERROR));
	}
}

function dp_migration_scenario(Context $t,string $name='scenario'): DpMigrationScenario {
	$state=$t->state('sql.migration',[
		'traces'=>[],
		'yaml_plans'=>[],
		'yaml_emits'=>[],
		'queries'=>[],
		'query_throw'=>false,
		'query_throw_on'=>0,
		'query_result'=>true,
		'select_calls'=>[],
		'select_results'=>[],
		'unavailable'=>[],
		'logs'=>[],
	]);
	$workspace=$t->workspace('dataphyre-migration-'.$name);
	$internals=$t->nonPublic(migration::class);
	$scenario=new DpMigrationScenario($state,$workspace,$internals);
	$internals->replacePropertyForTest('migration_roots',[
		'common_dataphyre'=>$workspace->path('plans/common').'/',
		'dataphyre'=>$workspace->path('plans/dataphyre').'/',
	]);
	$internals->replacePropertyForTest('version_file',$scenario->versions);
	$internals->replacePropertyForTest('lock_file',$scenario->lock);
	$internals->replacePropertyForTest('snapshot_dir',$scenario->snapshots);
	return $scenario;
}

test('sql migration deep coverage runs sorted pending plans and persists versions', static function(Context $t): void {
	$scenario=dp_migration_scenario($t,'sorted-plans');
	$scenario->writeVersions([
		'common_dataphyre:users'=>['current_version'=>1,'log'=>[]],
	]);
	$scenario->plan('common_dataphyre','00-empty',[]);
	$scenario->plan('common_dataphyre','01-users',[
			'table'=>'users',
			'migrations'=>[
				['version'=>1,'up'=>['mysql'=>'already applied']],
				['version'=>2,'up'=>['postgresql'=>'wrong dbms']],
				['version'=>3,'up'=>'   '],
				['version'=>4,'description'=>'four','up'=>['mysql'=>'ALTER FOUR']],
				['version'=>5,'up'=>' ALTER FIVE '],
			],
	]);
	$scenario->plan('dataphyre','01-orders',[
			'table'=>'orders',
			'migrations'=>[
				['version'=>1,'description'=>'create orders','up'=>['mysql'=>'CREATE ORDERS']],
			],
	]);
	$output=$t->captureOutput(static fn()=>migration::run_all(true))->output();
	$t->same(['ALTER FOUR','ALTER FIVE','CREATE ORDERS'],$scenario->fixture->get('queries'));
	$t->contains('[OK] common_dataphyre/users migrated to version 4',$output);
	$t->contains('[OK] dataphyre/orders migrated to version 1',$output);
	$versions=$scenario->readVersions();
	$t->same(5,$versions['common_dataphyre:users']['current_version']);
	$t->same('four',$versions['common_dataphyre:users']['log'][0]['desc']);
	$t->same('',$versions['common_dataphyre:users']['log'][1]['desc']);
	$t->same(1,$versions['dataphyre:orders']['current_version']);
	$t->isFalse(file_exists($scenario->lock));
	$t->notEmpty($scenario->fixture->get('traces'));
})->tag('sql','migration','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql migration deep coverage reports lock and query failures while releasing the lock', static function(Context $t): void {
	$locked=dp_migration_scenario($t,'locked');
	$locked->lock();
	$t->throws(static fn()=>migration::run_all(),RuntimeException::class);
	$t->count(1,$locked->fixture->get('unavailable'));

	$failure=dp_migration_scenario($t,'query-failure');
	$failure->plan('common_dataphyre','failure',[
		'table'=>'broken',
		'migrations'=>[['version'=>1,'up'=>['mysql'=>'BROKEN SQL']]],
	]);
	$failure->fixture->put('query_throw',true);
	$t->throws(static fn()=>migration::run_all(),RuntimeException::class);
	$t->same(['BROKEN SQL'],$failure->fixture->get('queries'));
	$t->count(1,$failure->fixture->get('logs'));
	$t->count(1,$failure->fixture->get('unavailable'));
	$t->isFalse(file_exists($failure->lock));

	$returnFailure=dp_migration_scenario($t,'query-return-failure');
	$returnFailure->plan('common_dataphyre','failure-return',[
		'table'=>'broken_return',
		'migrations'=>[['version'=>1,'up'=>['mysql'=>'BROKEN RETURN SQL']]],
	]);
	$returnFailure->fixture->put('query_result',false);
	$t->throws(static fn()=>migration::run_all(),RuntimeException::class);
	$t->same([], $returnFailure->readVersions());
	$t->count(1,$returnFailure->fixture->get('logs'));
	$t->count(1,$returnFailure->fixture->get('unavailable'));
	$t->isFalse(file_exists($returnFailure->lock));

	$partial=dp_migration_scenario($t,'query-partial-failure');
	$partial->plan('common_dataphyre','partial-failure',[
		'table'=>'partially_applied',
		'migrations'=>[
			['version'=>1,'up'=>['mysql'=>'APPLY ONE']],
			['version'=>2,'up'=>['mysql'=>'FAIL TWO']],
		],
	]);
	$partial->fixture->put('query_throw_on',2);
	$t->throws(static fn()=>migration::run_all(),RuntimeException::class);
	$t->same(['APPLY ONE','FAIL TWO'],$partial->fixture->get('queries'));
	$t->same(1,$partial->readVersions()['common_dataphyre:partially_applied']['current_version']);
	$t->isFalse(file_exists($partial->lock));
})->tag('sql','migration','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql migration deep coverage reads status locks versions and successful yaml parsing', static function(Context $t): void {
	$scenario=dp_migration_scenario($t,'status');
	$t->same(0,migration::get_current_version('users'));
	$t->same([],migration::status());
	$t->isFalse(migration::is_migrating());
	$versions=['common_dataphyre:users'=>['current_version'=>7,'log'=>[]]];
	$scenario->writeVersions($versions);
	$t->same(7,migration::get_current_version('users'));
	$t->same(0,migration::get_current_version('missing','dataphyre'));
	$t->same($versions,migration::status());
	$scenario->lock();
	$t->isTrue(migration::is_migrating());

	$empty=$scenario->yamlFixture('empty.yaml',false);
	$data=$scenario->yamlFixture('data.yaml',['table'=>'items']);
	$t->same([],$scenario->migration->invoke('parse_yaml',$empty));
	$t->same(['table'=>'items'],$scenario->migration->invoke('parse_yaml',$data));
})->tag('sql','migration','deep-coverage')->group('framework-coverage');

test('sql migration deep coverage generates mysql additions drops indexes and no-op snapshots', static function(Context $t): void {
	$scenario=dp_migration_scenario($t,'diffs');
	$scenario->fixture->put('select_results',[false,[],[]]);
	$t->same(null,migration::generate_migration_diff('missing'));
	$scenario->fixture->put('select_results',['not-an-array',[],[]]);
	$t->same(null,migration::generate_migration_diff('invalid'));

	$scenario->fixture->put('select_results',[[['Field'=>'id']],[],[]]);
	$initial=migration::generate_migration_diff('initial');
	$t->isTrue(is_string($initial) && is_file($initial));
	$t->contains('ALTER TABLE initial ADD COLUMN id TEXT',(string)file_get_contents($initial));

	$previous=[
			['Field'=>'id'],
			['column_name'=>'old_col'],
			['name'=>'same'],
	];
	$scenario->snapshot('dataphyre.items',$previous);
	$current=[
			['Field'=>'id'],
			['column_name'=>'new_col'],
			['name'=>'same'],
	];
	$scenario->fixture->put('select_results',[
			$current,
			[['Key_name'=>'idx_new','Column_name'=>'new_col']],
			[['ignored'=>'mysql']],
	]);
	$filename=migration::generate_migration_diff('items');
	$t->isTrue(is_string($filename) && is_file($filename));
	$emits=$scenario->fixture->get('yaml_emits');
	$emitted=$emits[array_key_last($emits)];
	$sql=$emitted['migrations'][0]['up']['mysql'];
	$t->contains('ALTER TABLE items ADD COLUMN new_col TEXT',$sql);
	$t->contains('ALTER TABLE items RENAME COLUMN old_col TO old_col_old',$sql);
	$t->contains('-- ALTER TABLE items DROP COLUMN old_col_old',$sql);
	$t->contains('CREATE INDEX idx_new ON items(new_col);',$sql);
	$t->same('Auto-generated diff with index and grants',$emitted['migrations'][0]['description']);
	$t->isTrue($emitted['migrations'][0]['version']>0);

	$scenario->fixture->put('select_results',[$current,[],[]]);
	$t->same(null,migration::generate_migration_diff('items'));
	$t->count(15,$scenario->fixture->get('select_calls'));
})->tag('sql','migration','deep-coverage')->group('framework-coverage')->maxMillis(10000);
