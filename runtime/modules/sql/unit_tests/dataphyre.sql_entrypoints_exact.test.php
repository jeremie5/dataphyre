<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_SQL_SCAFFOLD_NO_DISPATCH')){
	define('DATAPHYRE_SQL_SCAFFOLD_NO_DISPATCH', true);
}
if(!defined('DATAPHYRE_CLI_NO_TERMINATE')){
	define('DATAPHYRE_CLI_NO_TERMINATE', true);
}
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG',[
		'default_cluster'=>'primary',
		'tables'=>['orders'=>['cluster'=>'orders-cluster']],
	]);
}
require_once dirname(__DIR__).'/Framework/TableDefinition.php';
require_once dirname(__DIR__).'/kernel/scaffold_table_artifacts.php';

/** Captures scaffold command effects as named evidence instead of raw streams and references. */
final class SqlScaffoldScenario {
	private TestState $state;

	public function __construct(Context $test) {
		$this->state=$test->state('sql.scaffold', [
			'output'=>[],
			'errors'=>[],
			'statuses'=>[],
			'bootstraps'=>[],
			'module_loads'=>[],
			'scaffolds'=>[],
		]);
	}

	/** @return array<string,mixed> */
	public function runtime(array $overrides=[]): array {
		return [
			'sapi'=>'cli',
			'project_root'=>'/project',
			'write_out'=>fn(string $message): int=>$this->record('output', $message),
			'write_error'=>fn(string $message): int=>$this->record('errors', $message),
			'status'=>fn(int $status): int=>$this->record('statuses', $status),
			'bootstrap'=>function(string $root): array {
				$this->state->append('bootstraps', $root);
				return ['bootstrap'=>[], 'modules'=>[]];
			},
			'module_loader'=>fn(string $root): int=>$this->record('module_loads', $root),
			'scaffolder'=>function(...$parameters): array {
				$this->state->append('scaffolds', $parameters);
				return [
					'entity'=>$parameters[2],
					'application'=>$parameters[1],
					'framework_directory'=>'/project/applications/'.$parameters[1].'/Framework',
					'generated'=>[
						'entity'=>['path'=>'/project/Entity.php', 'status'=>'created'],
					],
				];
			},
			...$overrides,
		];
	}

	public function output(): string { return implode('', $this->state->get('output', [])); }
	public function errors(): string { return implode('', $this->state->get('errors', [])); }
	public function statuses(): array { return $this->state->get('statuses', []); }
	public function scaffolds(): array { return $this->state->get('scaffolds', []); }

	private function record(string $channel, string|int $value): int {
		$this->state->append($channel, $value);
		return is_string($value) ? strlen($value) : $value;
	}
}

suite('SQL entrypoint exact contracts')
	->contract('sql.entrypoints-exact', 1)
	->layer('integration')
	->risk('medium')
	->watches('module:sql')
	->through('table-builder', 'scaffold-command', 'native-bootstrap')
	->isolation('case')
	->tag('sql', 'entrypoint', 'exact-coverage')
	->group('framework-coverage');

test('table definitions can promote the most recently declared column to primary identity', static function(Context $t): void {
	$definition=TableDefinition::for('orders')->string('order_id')->primary();
	$t->same(['order_id'], $definition->primaryColumns());
	$t->same('orders-cluster',$t->nonPublic($definition)->invoke('hydrationCluster'));
});

test('scaffold dispatch and command statuses describe no-dispatch web help validation and success paths', static function(Context $t): void {
	$scenario=new SqlScaffoldScenario($t);
	$terminations=[];
	dataphyre_sql_scaffold_command::dispatch_entrypoint(true, ['scaffold.php'], static function(int $status) use (&$terminations): void {
		$terminations[]=$status;
	}, $scenario->runtime());
	$t->same([], $terminations);

	dataphyre_sql_scaffold_command::dispatch_entrypoint(false, ['scaffold.php', '--help'], static function(int $status) use (&$terminations): void {
		$terminations[]=$status;
	}, $scenario->runtime());
	$t->same([0], $terminations);
	$t->contains('Usage:', $scenario->output());
	dataphyre_sql_scaffold_command::dispatch_entrypoint(false, ['scaffold.php', '--help'], null, $scenario->runtime());

	$t->same(2, dataphyre_sql_scaffold_command::run(['scaffold.php'], $scenario->runtime(['sapi'=>'fpm-fcgi'])));
	$t->same([404], $scenario->statuses());
	$t->contains('only available from CLI', $scenario->output());
	$t->same(1, dataphyre_sql_scaffold_command::run(['scaffold.php'], $scenario->runtime()));
	$t->contains('Usage:', $scenario->errors());

	$status=dataphyre_sql_scaffold_command::run([
		'scaffold.php', '--application=example_app', '--entity=Order', '--table=orders',
		'--primary-key=order_id', '--columns=order_id,tenant_id,name', '--force',
	], $scenario->runtime());
	$t->same(0, $status);
	$t->contains('Scaffolded Order for example_app', $scenario->output());
	$t->same(['/project', 'example_app', 'Order', 'orders', 'order_id', ['order_id','tenant_id','name'], true], $scenario->scaffolds()[0]);
});

test('scaffold argument column and project-root helpers normalize every supported CLI shape', static function(Context $t): void {
	$t->same([
		['force'=>true, 'columns'=>'id,name'],
		['example_app', 'Order'],
	], parse_cli_arguments(['example_app', '--force', '--columns=id,name', '--', 'Order']));
	$t->same(['id','name','tenant_id'], parse_columns([' id,name ', '', 'tenant_id']));
	$t->same(['id','name'], parse_columns('id, name'));

	$workspace=$t->workspace('sql-scaffold-roots');
	$environmentRoot=$workspace->directory('environment-root');
	$t->setEnvironmentForTest(['DATAPHYRE_PROJECT_ROOT'=>$environmentRoot]);
	$t->same(realpath($environmentRoot), resolve_project_root('/ignored/package'));
	$t->setEnvironmentForTest(['DATAPHYRE_PROJECT_ROOT'=>null]);

	$embeddedPackage=$workspace->directory('embedded/common/dataphyre');
	$t->same($workspace->path('embedded'), resolve_project_root($embeddedPackage));
	$standalonePackage=$workspace->directory('standalone/dataphyre');
	$workspace->directory('standalone/applications');
	$t->same($workspace->path('standalone'), resolve_project_root($standalonePackage));
	$plainPackage=$workspace->directory('plain/package');
	$t->same($plainPackage, resolve_project_root($plainPackage));
});

test('native scaffold bootstrap and service generation run inside an isolated project workspace', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$workspace=$t->workspace('sql-native-scaffold');
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/sql_scaffold_native_probe.php',
		[dirname(__DIR__).'/kernel/scaffold_table_artifacts.php', $workspace->root()],
		working_directory:$workspace->root(),
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'missing_status'=>1,
		'success_status'=>0,
		'entity_exists'=>true,
		'table_exists'=>true,
	], $payload);
});
