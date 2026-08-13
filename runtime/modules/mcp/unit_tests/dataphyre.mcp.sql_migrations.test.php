<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpScenario;
use Dataphyre\Mcp\Testing\McpKernelHarness;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

/** @return array<string,mixed> */
function dp_mcp_migration_profile(): array {
	return [
		'application_id'=>'example_app',
		'schema'=>'example_app',
		'advisory_lock'=>'example_app.postgresql_migrations',
		'bootstrap_ids'=>['001_schema_baseline'],
		'bootstrap_cutoff'=>'001_schema_baseline',
	];
}

function dp_mcp_migration_database(TempWorkspace $workspace,string $directory='database'): string {
	$sql="CREATE SCHEMA IF NOT EXISTS example_app;\n".
		"CREATE TABLE example_app.items (item_id BIGINT PRIMARY KEY);\n";
	$workspace->file($directory.'/postgresql/001_schema_baseline.sql',$sql);
	$workspace->file(
		$directory.'/postgresql/manifest.json',
		(string)json_encode([
			'schema_version'=>3,
			'algorithm'=>'sha256',
			'bootstrap_cutoff'=>'001_schema_baseline',
			'source'=>['generator'=>'test fixture'],
			'migrations'=>[[
				'id'=>'001_schema_baseline',
				'phase'=>'bootstrap',
				'up'=>[
					'path'=>'001_schema_baseline.sql',
					'sha256'=>hash('sha256',$sql),
				],
				'down'=>null,
				'irreversible_reason'=>'The supported history starts at this baseline.',
				'minimum_compatible_release'=>null,
				'description'=>'Create the supported baseline.',
			]],
		],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
	);
	return $workspace->path($directory);
}

function dp_mcp_migration_framework(TempWorkspace $workspace,bool $validSchema=true): void {
	$root=\Dataphyre\Test\dataphyre_path();
	foreach([
		'PostgreSqlMigrationProfile.php',
		'PostgreSqlMigrationManifest.php',
		'PostgreSqlSchemaInspector.php',
		'PostgreSqlMigrationRunner.php',
	] as $file){
		$workspace->file(
			'dataphyre/runtime/modules/sql/Framework/Migrations/'.$file,
			(string)file_get_contents($root.'/runtime/modules/sql/Framework/Migrations/'.$file)
		);
	}
	$schema=(string)file_get_contents(
		$root.'/runtime/modules/sql/documentation/postgresql-migration-manifest-v3.schema.json'
	);
	$workspace->file(
		'dataphyre/runtime/modules/sql/documentation/postgresql-migration-manifest-v3.schema.json',
		$validSchema ? $schema : '{"invalid":'
	);
}

suite('MCP PostgreSQL migration contracts')
	->tag('mcp','sql','postgresql','migration','manifest','protocol')
	->group('framework-coverage')
	->contract('mcp.sql-migrations',1)
	->layer('system')
	->risk('critical')
	->watches(
		'module:mcp',
		'path:runtime/modules/mcp/kernel/dataphyre_mcp.inspection.data.php',
		'path:runtime/modules/sql/Framework/Migrations'
	)
	->through(
		'live tool discovery',
		'manifest-v3 resource',
		'static validation',
		'no-write scaffold planning',
		'prompt skill and capability discovery'
	)
	->isolation('process')
	->maxMillis(180000);

test('the live MCP protocol exposes and exercises the neutral migration workflow',static function(Context $t): void {
	$workspace=new TempWorkspace($t,'mcp-sql-migrations');
	$upSql="CREATE SCHEMA IF NOT EXISTS \"ExampleSchema\";\n".
		"CREATE TABLE \"ExampleSchema\".\"Items\" (\"item_id\" BIGINT PRIMARY KEY);\n";
	$workspace->file('database/postgresql/001_schema_baseline.sql',$upSql);
	$manifest=[
		'schema_version'=>3,
		'algorithm'=>'sha256',
		'bootstrap_cutoff'=>'001_schema_baseline',
		'source'=>['generator'=>'test fixture'],
		'migrations'=>[[
			'id'=>'001_schema_baseline',
			'phase'=>'bootstrap',
			'up'=>[
				'path'=>'001_schema_baseline.sql',
				'sha256'=>hash('sha256',$upSql),
			],
			'down'=>null,
			'irreversible_reason'=>'The supported history starts at this baseline.',
			'minimum_compatible_release'=>null,
			'description'=>'Create the supported baseline.',
		]],
	];
	$workspace->file(
		'database/postgresql/manifest.json',
		(string)json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
	);
	$profile=[
		'application_id'=>'example_app',
		'schema'=>'ExampleSchema',
		'journal_table'=>'SchemaMigrations',
		'event_table'=>'SchemaMigrationEvents',
		'release_digest_column'=>'artifact_sha256',
		'advisory_lock'=>'example_app.postgresql_migrations',
		'bootstrap_ids'=>['001_schema_baseline'],
		'bootstrap_cutoff'=>'001_schema_baseline',
	];
	$transcript=(new McpScenario($t))->inDirectory($workspace->root())->exchange([
		McpScenario::request('tools','tools/list'),
		McpScenario::request('resources','resources/list'),
		McpScenario::request('migration-resource','resources/read',['uri'=>'dataphyre://sql-migrations']),
		McpScenario::request('schema-resource','resources/read',['uri'=>'dataphyre://sql-migrations/manifest-v3-schema']),
		McpScenario::request('prompts','prompts/list'),
		McpScenario::request('prompt','prompts/get',['name'=>'dataphyre_sql_migration_workflow']),
		McpScenario::request('catalog','tools/call',['name'=>'dataphyre_sql_migration_catalog','arguments'=>[]]),
		McpScenario::request('describe','tools/call',['name'=>'dataphyre_sql_migration_describe','arguments'=>['id'=>'postgresql-manifest-v3']]),
		McpScenario::request('validate','tools/call',['name'=>'dataphyre_sql_migration_manifest_validate','arguments'=>[
			'database_root'=>'database',
			'profile'=>$profile,
		]]),
		McpScenario::request('scaffold','tools/call',['name'=>'dataphyre_sql_migration_scaffold_plan','arguments'=>[
			'application_id'=>'example_app',
			'schema'=>'ExampleSchema',
			'journal_table'=>'SchemaMigrations',
			'event_table'=>'SchemaMigrationEvents',
			'release_digest_column'=>'artifact_sha256',
			'advisory_lock'=>'example_app.postgresql_migrations',
			'bootstrap_ids'=>['001_schema_baseline'],
			'bootstrap_cutoff'=>'001_schema_baseline',
			'database_root'=>'database',
			'migration_id'=>'002_add_item_status',
			'phase'=>'rolling_expand',
			'description'=>'Add a nullable item status.',
			'up_sql'=>'ALTER TABLE "ExampleSchema"."Items" ADD COLUMN "status" TEXT;',
			'down_sql'=>'ALTER TABLE "ExampleSchema"."Items" DROP COLUMN "status";',
			'down_safety'=>'lossless',
		]]),
		McpScenario::request('skills','tools/call',['name'=>'dataphyre_mcp_skill_catalog','arguments'=>['names'=>['dataphyre-sql-migrations']]]),
		McpScenario::request('capabilities','tools/call',['name'=>'dataphyre_mcp_capability_matrix','arguments'=>[]]),
		McpScenario::request('workflow','tools/call',['name'=>'dataphyre_mcp_workflow_playbook_export','arguments'=>['workflow'=>'sql']]),
	],180000);

	$tools=$transcript->result('tools')['tools'];
	$toolNames=array_column($tools,'name');
	foreach([
		'dataphyre_sql_migration_catalog',
		'dataphyre_sql_migration_describe',
		'dataphyre_sql_migration_manifest_validate',
		'dataphyre_sql_migration_scaffold_plan',
	] as $toolName){
		$t->contains($toolName,$toolNames);
	}
	$validatorDescriptor=null;
	foreach($tools as $tool){
		if(($tool['name'] ?? null)==='dataphyre_sql_migration_manifest_validate'){
			$validatorDescriptor=$tool;
			break;
		}
	}
	$t->same(false,$validatorDescriptor['inputSchema']['additionalProperties'] ?? null);
	$t->same(false,$validatorDescriptor['inputSchema']['properties']['profile']['additionalProperties'] ?? null);

	$resourceUris=array_column($transcript->result('resources')['resources'],'uri');
	$t->contains('dataphyre://sql-migrations',$resourceUris);
	$t->contains('dataphyre://sql-migrations/manifest-v3-schema',$resourceUris);
	$migrationResource=json_decode(
		$transcript->result('migration-resource')['contents'][0]['text'],
		true,
		512,
		JSON_THROW_ON_ERROR
	);
	$t->same('dataphyre_sql_migrations',$migrationResource['resource_type']);
	$t->same('not_opened',$migrationResource['database_connection']);
	$t->same(4,count($migrationResource['contracts']));
	$deploymentContract=$migrationResource['deployment_contract'];
	$t->same(
		['rolling_expand','rolling_contract'],
		$deploymentContract['maintenance']['accepted_phases']
	);
	$t->contains(
		'selected_migrations',
		$deploymentContract['selection']['evidence_fields']
	);
	$t->contains(
		'defer the first rolling_contract',
		$deploymentContract['selection']['rolling']
	);
	$t->same(
		'one Dataphyre-owned PostgreSQL transaction',
		$deploymentContract['maintenance']['transaction']
	);
	$t->same(
		'accepted_but_ignored_for_precedence',
		$deploymentContract['semantic_version_precedence']['build_metadata']
	);
	$t->same(
		['2.4.0+build.1','2.4.0+build.9'],
		$deploymentContract['semantic_version_precedence']['example_equal_precedence']
	);
	$t->contains(
		'drain and barrier',
		$deploymentContract['authority']['application_owned']
	);
	$t->same(
		'read-only description, static validation, and no-write planning only',
		$deploymentContract['authority']['mcp']
	);
	$schema=json_decode(
		$transcript->result('schema-resource')['contents'][0]['text'],
		true,
		512,
		JSON_THROW_ON_ERROR
	);
	$t->same(3,$schema['properties']['schema_version']['const']);
	$t->same(false,$schema['additionalProperties']);
	$t->same(
		'^[A-Za-z_][A-Za-z0-9_.-]{0,127}$',
		$schema['properties']['source']['propertyNames']['pattern']
	);

	$t->contains(
		'dataphyre_sql_migration_workflow',
		array_column($transcript->result('prompts')['prompts'],'name')
	);
	$promptText=$transcript->result('prompt')['messages'][0]['content']['text'];
	$t->contains(
		'never write files',
		strtolower($promptText)
	);
	$t->contains('post-cutoff rolling_expand and rolling_contract',$promptText);
	$t->contains('+build metadata does not affect precedence',$promptText);
	$t->contains('drain/barrier',$promptText);

	$catalog=$transcript->toolPayload('catalog');
	$t->same('dataphyre_sql_migration_catalog',$catalog['catalog_type']);
	$t->same(4,$catalog['returned_count']);
	$t->same('not_opened',$catalog['database_connection']);
	$t->same($deploymentContract,$catalog['deployment_contract']);
	$description=$transcript->toolPayload('describe');
	$t->same('postgresql-manifest-v3',$description['contract']['id']);
	$t->same($deploymentContract,$description['deployment_contract']);
	$t->contains('post-cutoff maintenance suffix',$description['workflow']['maintenance']);
	$t->contains('+build metadata ignored',$description['workflow']['contract_floor']);

	$validation=$transcript->toolPayload('validate');
	$t->isTrue($validation['valid']);
	$t->same('static_validation_only',$validation['execution']);
	$t->same('not_opened',$validation['database_connection']);
	$t->same('not_performed',$validation['sql_execution']);
	$t->same(1,$validation['manifest']['migration_count']);
	$t->same($migrationResource['manifest_schema']['sha256'],$validation['manifest_schema']['sha256']);

	$scaffold=$transcript->toolPayload('scaffold');
	$t->isTrue($scaffold['ready']);
	$t->same('dry_run_no_writes',$scaffold['write_policy']);
	$t->same('not_opened',$scaffold['database_connection']);
	$t->same('002_add_item_status.up.sql',$scaffold['manifest_entry']['up']['path']);
	$t->same('002_add_item_status.down.sql',$scaffold['manifest_entry']['down']['path']);
	$t->same([],$scaffold['upgrade_issues']);
	$t->same([],$scaffold['rolling_expand_issues']);
	$t->same([],$scaffold['sql_safety_issues']);

	$skills=$transcript->toolPayload('skills');
	$t->same('dataphyre-sql-migrations',$skills['skills'][0]['name']);
	$t->contains('dataphyre_sql_migration_manifest_validate',$skills['skills'][0]['related_tools']);
	$skillInstructions=implode(' ',$skills['skills'][0]['instructions']);
	$t->contains('pending post-cutoff rolling_expand and rolling_contract suffix',$skillInstructions);
	$t->contains('caller-verified minimum active release',$skillInstructions);
	$t->contains('ignore +build metadata',$skillInstructions);
	$t->contains('drain/barrier completion',$skillInstructions);
	$capabilityFamilies=$transcript->toolPayload('capabilities')['families'];
	$sqlMigrationFamily=null;
	foreach($capabilityFamilies as $family){
		if(($family['family'] ?? null)==='sql_migrations'){
			$sqlMigrationFamily=$family;
			break;
		}
	}
	$t->same('read_only_or_dry_run',$sqlMigrationFamily['safety_level'] ?? null);
	$t->contains('dataphyre_sql_migration_scaffold_plan',$sqlMigrationFamily['tools'] ?? []);
	$t->contains('maintenance expand/contract transactions',$sqlMigrationFamily['execution_posture'] ?? '');
	$t->contains('applications retain drain/barrier and authorization authority',$sqlMigrationFamily['execution_posture'] ?? '');
	$workflow=$transcript->toolPayload('workflow');
	$t->same('dataphyre_sql_migration_workflow',$workflow['playbooks']['sql']['companion_prompt']);
	$t->contains(
		'pending post-cutoff rolling_expand and rolling_contract suffix',
		$workflow['playbooks']['sql']['maintenance_contract']['scope']
	);
	$t->contains(
		'+build metadata is ignored',
		$workflow['playbooks']['sql']['maintenance_contract']['contract_gate']
	);
	$t->contains(
		'drain/barrier completion',
		$workflow['playbooks']['sql']['maintenance_contract']['application_authority']
	);
	$t->contains(
		'opens no PDO connection and executes no SQL',
		$workflow['playbooks']['sql']['maintenance_contract']['mcp_boundary']
	);
	$t->contains(
		'dataphyre_sql_migration_manifest_validate',
		array_column($workflow['playbooks']['sql']['steps'],'tool')
	);
});

test('invalid manifests return bounded validation evidence without database work',static function(Context $t): void {
	$workspace=new TempWorkspace($t,'mcp-sql-migration-invalid');
	$workspace->file('database/postgresql/manifest.json','{"schema_version":2}');
	$transcript=(new McpScenario($t))->inDirectory($workspace->root())->exchange([
		McpScenario::request('validate','tools/call',['name'=>'dataphyre_sql_migration_manifest_validate','arguments'=>[
			'database_root'=>'database',
			'profile'=>[
				'application_id'=>'example_app',
				'schema'=>'example_app',
				'advisory_lock'=>'example_app.postgresql_migrations',
				'bootstrap_cutoff'=>'001_schema_baseline',
			],
		]]),
	]);
	$validation=$transcript->toolPayload('validate');
	$t->isFalse($validation['valid']);
	$t->same('not_opened',$validation['database_connection']);
	$t->same('not_performed',$validation['sql_execution']);
	$t->count(1,$validation['errors']);
	$t->contains('unsupported shape',$validation['errors'][0]);
});

test('scaffold planning rejects incompatible inputs and reports existing manifest hazards',static function(Context $t): void {
	$workspace=new TempWorkspace($t,'mcp-sql-migration-scaffold-branches');
	dp_mcp_migration_framework($workspace);
	dp_mcp_migration_database($workspace);
	$kernel=(new McpKernelHarness($t))->useRepositoryRoot($workspace->root());
	$base=[
		...dp_mcp_migration_profile(),
		'database_root'=>'database',
		'migration_id'=>'002_add_status',
		'phase'=>'rolling_expand',
		'description'=>'Add a nullable status.',
		'up_sql'=>'ALTER TABLE example_app.items ADD COLUMN status TEXT;',
		'irreversible_reason'=>'No supported down migration.',
	];
	$plan=static function(array $overrides=[],array $without=[]) use ($kernel,$base): array {
		$args=array_replace($base,$overrides);
		foreach($without as $key){
			unset($args[$key]);
		}
		return $kernel->invoke('sql_migration_scaffold_plan',$args);
	};

	$t->throwsLike(
		fn()=> $plan(['migration_id'=>'invalid']),
		InvalidArgumentException::class,
		'migration_id must match'
	);
	$t->throwsLike(
		fn()=> $plan(['phase'=>'unsupported']),
		InvalidArgumentException::class,
		'phase must be'
	);
	$t->throwsLike(
		fn()=> $plan(['description'=>' ']),
		InvalidArgumentException::class,
		'description must not be empty'
	);
	$t->throwsLike(
		fn()=> $plan(['up_sql'=>"\n"]),
		InvalidArgumentException::class,
		'up_sql must not be empty'
	);
	$t->throwsLike(
		fn()=> $plan([
			'phase'=>'bootstrap',
			'down_sql'=>'DROP TABLE example_app.items;',
			'down_safety'=>'data_loss',
		],['irreversible_reason']),
		InvalidArgumentException::class,
		'Bootstrap migrations'
	);
	$t->throwsLike(
		fn()=> $plan(['down_sql'=>'ALTER TABLE example_app.items DROP COLUMN status;'],[
			'down_safety',
			'irreversible_reason',
		]),
		InvalidArgumentException::class,
		'down_safety must be'
	);
	$t->throwsLike(
		fn()=> $plan([
			'down_sql'=>'ALTER TABLE example_app.items DROP COLUMN status;',
			'down_safety'=>'unsafe',
		],['irreversible_reason']),
		InvalidArgumentException::class,
		'down_safety must be'
	);
	$t->throwsLike(
		fn()=> $plan([
			'down_sql'=>'ALTER TABLE example_app.items DROP COLUMN status;',
			'down_safety'=>'lossless',
		]),
		InvalidArgumentException::class,
		'irreversible_reason must be omitted'
	);
	$t->throwsLike(
		fn()=> $plan([],['irreversible_reason']),
		InvalidArgumentException::class,
		'irreversible_reason is required'
	);
	$t->throwsLike(
		fn()=> $plan(['down_safety'=>'lossless']),
		InvalidArgumentException::class,
		'down_safety must be omitted'
	);
	$t->throwsLike(
		fn()=> $plan(['phase'=>'rolling_contract'],['minimum_compatible_release']),
		InvalidArgumentException::class,
		'rolling_contract requires'
	);
	$t->throwsLike(
		fn()=> $plan([
			'phase'=>'rolling_contract',
			'minimum_compatible_release'=>'release-two',
		]),
		InvalidArgumentException::class,
		'rolling_contract requires'
	);
	$t->throwsLike(
		fn()=> $plan(['minimum_compatible_release'=>'2.0.0']),
		InvalidArgumentException::class,
		'only valid for rolling_contract'
	);

	$blankDown=$plan(['down_sql'=>" \n"]);
	$t->same(null,$blankDown['manifest_entry']['down']);
	$t->same('No supported down migration.',$blankDown['manifest_entry']['irreversible_reason']);
	$t->count(1,$blankDown['files']);
	$t->isTrue($blankDown['ready']);

	$reversible=$plan([
		'down_sql'=>'ALTER TABLE example_app.items DROP COLUMN status;',
		'down_safety'=>'lossless',
	],['irreversible_reason']);
	$t->same('lossless',$reversible['manifest_entry']['down']['safety']);
	$t->count(2,$reversible['files']);

	$contract=$plan([
		'phase'=>'rolling_contract',
		'minimum_compatible_release'=>'2.0.0',
	]);
	$t->same('2.0.0',$contract['manifest_entry']['minimum_compatible_release']);
	$t->same([],$contract['rolling_expand_issues']);
	$t->isTrue($contract['ready']);

	$appendedBootstrap=$plan(['phase'=>'bootstrap']);
	$t->isFalse($appendedBootstrap['ready']);
	$t->contains(
		'bootstrap phase is immutable after the existing manifest cutoff.',
		$appendedBootstrap['upgrade_issues']
	);

	$withoutHistory=$plan([],['database_root']);
	$t->isFalse($withoutHistory['ready']);
	$t->contains(
		'database_root is required to certify an append plan',
		$withoutHistory['upgrade_issues'][0]
	);
	$initial=$plan([
		'migration_id'=>'001_schema_baseline',
		'phase'=>'bootstrap',
		'description'=>'Create the initial schema.',
		'up_sql'=>'CREATE SCHEMA example_app;',
	],['database_root']);
	$t->isTrue($initial['ready']);
	$t->same([],$initial['upgrade_issues']);

	$unsafeUp=$plan([
		'phase'=>'rolling_contract',
		'minimum_compatible_release'=>'2.0.0',
		'up_sql'=>"BEGIN;\nALTER TABLE example_app.items DROP COLUMN status;\n",
	]);
	$t->isFalse($unsafeUp['ready']);
	$t->same('up',$unsafeUp['sql_safety_issues'][0]['direction']);
	$t->same('transaction_control',$unsafeUp['sql_safety_issues'][0]['code']);
	$unsafeDown=$plan([
		'down_sql'=>
			'CREATE INDEX CONCURRENTLY example_status_idx ON example_app.items (status);',
		'down_safety'=>'lossless',
	],['irreversible_reason']);
	$t->isFalse($unsafeDown['ready']);
	$t->same('down',$unsafeDown['sql_safety_issues'][0]['direction']);
	$t->same(
		'concurrent_index_operation',
		$unsafeDown['sql_safety_issues'][0]['code']
	);
	$transactionIncompatible=$plan([
		'up_sql'=>'VACUUM example_app.items;',
	]);
	$t->isFalse($transactionIncompatible['ready']);
	$t->same(
		'transaction_incompatible_statement',
		$transactionIncompatible['sql_safety_issues'][0]['code']
	);
	$t->same(
		'PostgreSQL statement cannot run inside the Dataphyre-owned transaction.',
		$transactionIncompatible['sql_safety_issues'][0]['message']
	);

	$wrongSequence=$plan(['migration_id'=>'003_add_status']);
	$t->isFalse($wrongSequence['ready']);
	$t->contains('next manifest sequence 002_',$wrongSequence['upgrade_issues'][0]);
	$t->same(1,$wrongSequence['existing_manifest']['migration_count']);

	$workspace->file('broken/postgresql/manifest.json','{"schema_version":');
	$broken=$plan(['database_root'=>'broken']);
	$t->isFalse($broken['ready']);
	$t->same(null,$broken['existing_manifest']);
	$t->contains('existing manifest is not valid:',$broken['upgrade_issues'][0]);
});

test('migration discovery fails closed when framework or schema resources are unavailable',static function(Context $t): void {
	$missingFramework=new TempWorkspace($t,'mcp-sql-migration-missing-framework');
	$missingFrameworkKernel=(new McpKernelHarness($t))->useRepositoryRoot($missingFramework->root());
	$t->throwsLike(
		fn()=> $missingFrameworkKernel->invoke('sql_migration_describe',['id'=>'unknown-contract']),
		InvalidArgumentException::class,
		'Unknown SQL migration contract id'
	);
	$t->throwsLike(
		fn()=> $missingFrameworkKernel->invoke('load_sql_migration_framework'),
		RuntimeException::class,
		'migration framework is unavailable'
	);

	$invalidSchema=new TempWorkspace($t,'mcp-sql-migration-invalid-schema');
	dp_mcp_migration_framework($invalidSchema,false);
	$invalidSchemaKernel=(new McpKernelHarness($t))->useRepositoryRoot($invalidSchema->root());
	$t->throwsLike(
		fn()=> $invalidSchemaKernel->invoke('sql_migration_manifest_schema'),
		RuntimeException::class,
		'JSON Schema is unavailable'
	);

	$missingSchema=new TempWorkspace($t,'mcp-sql-migration-missing-schema');
	$missingSchemaKernel=(new McpKernelHarness($t))->useRepositoryRoot($missingSchema->root());
	$t->throwsLike(
		fn()=> $missingSchemaKernel->invoke('sql_migration_manifest_schema_sha256'),
		RuntimeException::class,
		'JSON Schema is unavailable'
	);
});

test('shared migration surfaces remain application neutral',static function(Context $t): void {
	$root=\Dataphyre\Test\dataphyre_path();
	$paths=[
		'docs/MODULES.md',
		'runtime/modules/sql/Framework/Migrations/PostgreSqlMigrationProfile.php',
		'runtime/modules/sql/Framework/Migrations/PostgreSqlMigrationManifest.php',
		'runtime/modules/sql/Framework/Migrations/PostgreSqlSchemaInspector.php',
		'runtime/modules/sql/Framework/Migrations/PostgreSqlMigrationRunner.php',
		'runtime/modules/sql/documentation/Dataphyre_SQL.md',
		'runtime/modules/sql/documentation/Dataphyre_PostgreSQL_Migrations.md',
		'runtime/modules/sql/documentation/postgresql-migration-manifest-v3.schema.json',
		'runtime/modules/mcp/documentation/Dataphyre_MCP.md',
		'runtime/modules/mcp/kernel/dataphyre_mcp.inspection.data.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.registry.tools.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.registry.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.client.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.client.capabilities.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.client.prompts.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.client.skills.php',
		'runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.php',
	];
	$forbidden=[
		implode('',['Volume','trix']),
		implode('',['cap','sule']),
	];
	$leaks=[];
	foreach($paths as $path){
		$source=file_get_contents($root.'/'.$path);
		if(!is_string($source)){
			$leaks[]=$path.':unreadable';
			continue;
		}
		foreach($forbidden as $term){
			if(stripos($source,$term)!==false){
				$leaks[]=$path.':application-term';
			}
		}
	}
	$t->same([],$leaks);
});
