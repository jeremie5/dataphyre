<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Tools\ScaffoldTableArtifacts;
use Dataphyre\Test\Context;
use dataphyre\application_definition;
use function Dataphyre\Test\test;

$dpScaffoldRoot=\Dataphyre\Test\dataphyre_path();
require_once $dpScaffoldRoot.'/runtime/modules/core/kernel/application_definition.php';
require_once $dpScaffoldRoot.'/runtime/modules/core/kernel/app_locator.php';
require_once $dpScaffoldRoot.'/runtime/modules/sql/Framework/Tools/ScaffoldTableArtifacts.php';

/** @param list<mixed> $arguments */
function dp_scaffold_private(Context $t,string $method,array $arguments=[]): mixed {
	return $t->nonPublic(ScaffoldTableArtifacts::class)->invokeWithArguments($method,$arguments);
}

test('sql scaffold artifacts deep coverage generates validates and overwrites conventional artifacts', static function(Context $t): void {
	$workspace=$t->workspace('sql-scaffold-artifacts');
	$project=str_replace('\\', '/', $workspace->root());
		$app=$project.'/applications/demo';
		$workspace->directory('applications/demo/framework');
		$result=ScaffoldTableArtifacts::scaffold(
			$project.'//',
			' demo ',
			'machine event',
			' machines ',
			' machine_id ',
			[' name ', 'machine_id', 'name', 'created_at']
		);
		$t->same('demo', $result['application']);
		$t->same('MachineEvent', $result['entity']);
		$t->same('machines', $result['table']);
		$t->same('machine_id', $result['primary_key']);
		$t->same(['name','machine_id','created_at'], $result['columns']);
		$t->same('demo\\framework', $result['framework_namespace']);
		$t->same(['created','created','created'], array_column($result['generated'], 'status'));
		$schema=$result['generated']['schema']['path'];
		$repository=$result['generated']['repository']['path'];
		$record=$result['generated']['record']['path'];
		$t->isTrue(is_file($schema));
		$t->isTrue(is_file($repository));
		$t->isTrue(is_file($record));
		$t->contains("new TableSchema('machines'", (string)file_get_contents($schema));
		$t->contains('final class MachineEventRepository', (string)file_get_contents($repository));
		$recordSource=(string)file_get_contents($record);
		$t->contains('function machineId()', $recordSource);
		$t->contains('function createdAt()', $recordSource);

		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold(
			$project, 'demo', 'machine event', 'machines', 'machine_id', ['name']
		), RuntimeException::class);
		$overwritten=ScaffoldTableArtifacts::scaffold(
			$project, 'demo', 'machine event', 'machines', 'machine_id', ['name'], true
		);
		$t->same(['overwritten','overwritten','overwritten'], array_column($overwritten['generated'], 'status'));

		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'missing', 'Entity', 'items', 'id', []), RuntimeException::class);
		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'demo', '---', 'items', 'id', []), RuntimeException::class);
		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'demo', 'Entity', 'bad-table', 'id', []), RuntimeException::class);
		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'demo', 'Entity', 'items', 'bad-key', []), RuntimeException::class);
		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'demo', 'Entity', 'items', 'id', ['bad-column']), RuntimeException::class);
})->tag('sql','scaffold','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql scaffold artifacts deep coverage loads array instance fallback and invalid application definitions', static function(Context $t): void {
	$workspace=$t->workspace('sql-scaffold-definitions');
	$project=str_replace('\\', '/', $workspace->root());
		$fallback=$project.'/applications/fallback_app';
		$workspace->directory('applications/fallback_app');
		$fallbackResult=ScaffoldTableArtifacts::scaffold($project, 'fallback_app', 'audit log', 'audit_log', 'id', []);
		$t->same($fallback.'/framework', str_replace('\\', '/', $fallbackResult['framework_directory']));
		$t->same(['id'], $fallbackResult['columns']);
		$t->notContains('public function id()', (string)file_get_contents($fallbackResult['generated']['record']['path']));

		$arrayApp=$project.'/applications/array_app';
		$arrayFramework=$project.'/generated/array-framework';
		$workspace->directory('applications/array_app');
		$workspace->file('applications/array_app/app.php', '<?php return '.var_export([
			'id'=>'array_resolved',
			'root_directory'=>$arrayApp,
			'autoload'=>['Vendor\\Framework\\'=>$arrayFramework],
		], true).';');
		$arrayResult=ScaffoldTableArtifacts::scaffold($project, 'array_app', 'Array Entity', 'array_items', 'item_id', ['label']);
		$t->same('array_resolved', $arrayResult['application']);
		$t->same('Vendor\\Framework', $arrayResult['framework_namespace']);
		$t->same($arrayFramework, str_replace('\\', '/', $arrayResult['framework_directory']));

		$instanceApp=$project.'/applications/instance_app';
		$workspace->directory('applications/instance_app');
		$workspace->file('applications/instance_app/app.php', '<?php return new \\dataphyre\\application_definition('.
			var_export('instance_resolved', true).', __DIR__, null, null, null, null, null, '.
			var_export([''=>'', 'Vendor\\Other\\'=>$instanceApp.'/other'], true).');');
		$instanceResult=ScaffoldTableArtifacts::scaffold($project, 'instance_app', 'Instance Entity', 'instance_items', 'id', []);
		$t->same('instance_resolved', $instanceResult['application']);
		$t->same('instance_resolved\\framework', $instanceResult['framework_namespace']);
		$t->same($instanceApp.'/framework', str_replace('\\', '/', $instanceResult['framework_directory']));

		$invalidApp=$project.'/applications/invalid_app';
		$workspace->directory('applications/invalid_app');
		$workspace->file('applications/invalid_app/app.php', '<?php return 42;');
		$t->throws(static fn()=>ScaffoldTableArtifacts::scaffold($project, 'invalid_app', 'Entity', 'items', 'id', []), RuntimeException::class);
})->tag('sql','scaffold','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql scaffold artifacts deep coverage closes private normalization source and filesystem branches', static function(Context $t): void {
	$workspace=$t->workspace('sql-scaffold-internals');
	$root=str_replace('\\', '/', $workspace->root());
		$definition=new application_definition('demo', $root, null, null, null, null, null, [
			''=>'',
			'Vendor\\Other\\'=>$root.'/other/',
			'Vendor\\Framework\\'=>$root.'/chosen/',
		]);
		$t->same(['Vendor\\Framework',$root.'/chosen'], dp_scaffold_private($t,'frameworkLocation', [$definition]));
		$fallback=new application_definition('fallback', $root, null, null, null, null, null, []);
		$t->same(['fallback\\framework',$root.'/framework'], dp_scaffold_private($t,'frameworkLocation', [$fallback]));

		$t->same('', dp_scaffold_private($t,'classify', ['']));
		$t->same('', dp_scaffold_private($t,'classify', ['---']));
		$t->same('FooBarBaz', dp_scaffold_private($t,'classify', [' fooBar_baz ']));
		$t->same('', dp_scaffold_private($t,'camelize', ['---']));
		$t->same('fooBar', dp_scaffold_private($t,'camelize', ['foo_bar']));
		$t->same(['id','name'], dp_scaffold_private($t,'normalizeColumns', [['id','name','id'], 'id']));
		$t->same(['id','name'], dp_scaffold_private($t,'normalizeColumns', [['name'], 'id']));
		$t->same('valid_name', dp_scaffold_private($t,'normalizeSqlIdentifier', [' valid_name ', 'column']));
		$t->throws(static fn()=>dp_scaffold_private($t,'normalizeSqlIdentifier', ['', 'column']), RuntimeException::class);

		$existing=$workspace->directory('existing');
		$t->same(null, dp_scaffold_private($t,'ensureDirectory', [$existing]));
		$created=$root.'/new/nested';
		$t->same(null, dp_scaffold_private($t,'ensureDirectory', [$created]));
		$t->isTrue(is_dir($created));
		$blocker=$workspace->file('blocker', 'file');
		$t->throws(static fn()=>dp_scaffold_private($t,'ensureDirectory', [$blocker.'/child']), RuntimeException::class);

		$file=$root.'/artifact.php';
		$t->same('created', dp_scaffold_private($t,'writeFile', [$file, 'one', false])['status']);
		$t->throws(static fn()=>dp_scaffold_private($t,'writeFile', [$file, 'two', false]), RuntimeException::class);
		$t->same('overwritten', dp_scaffold_private($t,'writeFile', [$file, 'two', true])['status']);
		$t->same('two', file_get_contents($file));
		$t->throws(static fn()=>dp_scaffold_private($t,'writeFile', [$existing, 'bad', true]), RuntimeException::class);

		$schema=dp_scaffold_private($t,'schemaSource', ['Vendor\\Framework','Edge','edge_items','id',[]]);
		$t->contains('final class EdgeTableSchema', $schema);
		$repository=dp_scaffold_private($t,'repositorySource', ['Vendor\\Framework','Edge']);
		$t->contains('final class EdgeRepository', $repository);
		$record=dp_scaffold_private($t,'recordSource', ['Vendor\\Framework','Edge',['id','---','first_name','first-name','Name']]);
		$t->same(1, substr_count($record, 'function firstName()'));
		$t->contains('function name()', $record);
		$emptyRecord=dp_scaffold_private($t,'recordSource', ['Vendor\\Framework','Empty',['id']]);
		$t->notContains('public function', $emptyRecord);
		$t->same('', dp_scaffold_private($t,'exportStringList', [[], 2]));
		$t->same("'one',\n", dp_scaffold_private($t,'exportStringList', [['one'], -3]));
})->tag('sql','scaffold','deep-coverage')->group('framework-coverage')->maxMillis(10000);
