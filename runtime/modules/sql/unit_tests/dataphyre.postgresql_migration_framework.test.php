<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;
use Dataphyre\Database\Migrations\PostgreSqlSchemaInspector;
use Dataphyre\Test\Context;
use Dataphyre\Test\ScriptedPdo;
use Dataphyre\Test\ScriptedPdoStatement;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

$dpPostgreSqlMigrationRoot=dirname(__DIR__);
require_once $dpPostgreSqlMigrationRoot.'/Framework/Migrations/PostgreSqlMigrationProfile.php';
require_once $dpPostgreSqlMigrationRoot.'/Framework/Migrations/PostgreSqlMigrationManifest.php';
require_once $dpPostgreSqlMigrationRoot.'/Framework/Migrations/PostgreSqlSchemaInspector.php';
require_once $dpPostgreSqlMigrationRoot.'/Framework/Migrations/PostgreSqlMigrationRunner.php';

/** @param array<string,mixed> $overrides */
function dp_postgresql_migration_profile(array $overrides=[]): PostgreSqlMigrationProfile {
	return PostgreSqlMigrationProfile::fromArray(array_replace([
		'application_id'=>'fixture',
		'schema'=>'fixture',
		'journal_table'=>'schema_migrations',
		'event_table'=>'schema_migration_events',
		'advisory_lock'=>'fixture.postgresql_migrations',
		'bootstrap_ids'=>['001_base', '002_cutoff'],
		'bootstrap_cutoff'=>'002_cutoff',
		'manifest_public_path'=>'migrations/postgresql/manifest.json',
		'lock_timeout'=>'3s',
		'statement_timeout'=>'2min',
	], $overrides));
}

/**
 * @param null|callable(array<string,mixed>&,TempWorkspace):void $mutate
 * @return array{0:string,1:TempWorkspace,2:array<string,mixed>}
 */
function dp_postgresql_migration_manifest_fixture(
	Context $t,
	string $name,
	?callable $mutate=null
): array {
	$workspace=$t->workspace('dataphyre-postgresql-migrations-'.$name);
	$database=$workspace->directory('database');
	$sql=[
		'001_base.sql'=>"CREATE TABLE fixture.base (id TEXT PRIMARY KEY);\n",
		'002_cutoff.sql'=>"ALTER TABLE fixture.base ADD COLUMN note TEXT;\n",
		'003_expand.up.sql'=>"ALTER TABLE fixture.base ADD COLUMN detail TEXT;\n",
		'003_expand.down.sql'=>"ALTER TABLE fixture.base DROP COLUMN detail;\n",
		'004_irreversible.up.sql'=>"ALTER TABLE fixture.base ADD COLUMN audit_note TEXT;\n",
		'005_contract.up.sql'=>"ALTER TABLE fixture.base DROP COLUMN audit_note;\n",
		'005_contract.down.sql'=>"ALTER TABLE fixture.base ADD COLUMN audit_note TEXT;\n",
	];
	foreach($sql as $path=>$source){
		$workspace->file('database/postgresql/'.$path, $source);
	}
	$direction=static fn(string $path): array=>[
		'path'=>$path,
		'sha256'=>hash('sha256', $sql[$path]),
	];
	$manifest=[
		'schema_version'=>3,
		'algorithm'=>'sha256',
		'bootstrap_cutoff'=>'002_cutoff',
		'source'=>['path'=>'fixture', 'revision'=>'test'],
		'migrations'=>[
			[
				'id'=>'001_base',
				'phase'=>'bootstrap',
				'up'=>$direction('001_base.sql'),
				'down'=>null,
				'irreversible_reason'=>'Fixture bootstrap baseline.',
				'minimum_compatible_release'=>null,
				'description'=>'Create fixture baseline.',
			],
			[
				'id'=>'002_cutoff',
				'phase'=>'bootstrap',
				'up'=>$direction('002_cutoff.sql'),
				'down'=>null,
				'irreversible_reason'=>'Fixture bootstrap cutoff.',
				'minimum_compatible_release'=>null,
				'description'=>'Finish fixture bootstrap.',
			],
			[
				'id'=>'003_expand',
				'phase'=>'rolling_expand',
				'up'=>$direction('003_expand.up.sql'),
				'down'=>$direction('003_expand.down.sql')+['safety'=>'lossless'],
				'minimum_compatible_release'=>null,
				'description'=>'Add a nullable fixture column.',
			],
			[
				'id'=>'004_irreversible',
				'phase'=>'rolling_expand',
				'up'=>$direction('004_irreversible.up.sql'),
				'down'=>null,
				'irreversible_reason'=>'Fixture intentionally has no down direction.',
				'minimum_compatible_release'=>null,
				'description'=>'Add fixture audit metadata.',
			],
			[
				'id'=>'005_contract',
				'phase'=>'rolling_contract',
				'up'=>$direction('005_contract.up.sql'),
				'down'=>$direction('005_contract.down.sql')+['safety'=>'data_loss'],
				'minimum_compatible_release'=>'2.0.0',
				'description'=>'Exercise the compatibility floor.',
			],
		],
	];
	if($mutate!==null){
		$mutate($manifest, $workspace);
	}
	$workspace->file(
		'database/postgresql/manifest.json',
		json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"
	);
	return [$database, $workspace, $manifest];
}

function dp_postgresql_migration_queue_structure(
	ScriptedPdo $pdo,
	string $identity
): ScriptedPdo {
	for($part=0; $part<3; $part++){
		$pdo->queueRows([['identity'=>$identity, 'part'=>$part]]);
	}
	return $pdo;
}

function dp_postgresql_migration_queue_data(
	ScriptedPdo $pdo,
	string $rowCount='1',
	string $hash='stable'
): ScriptedPdo {
	return $pdo
		->queueRows([['table_name'=>'items']])
		->queueRows([[
			'row_count'=>$rowCount,
			'hash_sum_a'=>$hash.'-a',
			'hash_sum_b'=>$hash.'-b',
		]]);
}

function dp_postgresql_migration_no_schema_manifest(
	Context $t,
	string $name,
	bool $bootstrapOnly=false,
	bool $appendContract=false,
	bool $allRollingReversible=false,
	bool $rollingSafe=false
): PostgreSqlMigrationManifest {
	[$database]=dp_postgresql_migration_manifest_fixture(
		$t,
		$name,
		static function(array &$manifest, TempWorkspace $workspace) use (
			$bootstrapOnly,
			$appendContract,
			$allRollingReversible,
			$rollingSafe
		): void {
			if($bootstrapOnly){
				$manifest['migrations']=array_slice($manifest['migrations'], 0, 2);
				foreach([
					'003_expand.up.sql',
					'003_expand.down.sql',
					'004_irreversible.up.sql',
					'005_contract.up.sql',
					'005_contract.down.sql',
				] as $path){
					unlink($workspace->path('database/postgresql/'.$path));
				}
			}
			if($appendContract){
				$manifest['migrations'][]=[
					'id'=>'006_contract_floor',
					'phase'=>'rolling_contract',
					'up'=>[
						'path'=>'006_contract_floor.up.sql',
						'sha256'=>str_repeat('0', 64),
					],
					'down'=>[
						'path'=>'006_contract_floor.down.sql',
						'sha256'=>str_repeat('0', 64),
						'safety'=>'lossless',
					],
					'minimum_compatible_release'=>'2.0.1-alpha',
					'description'=>'Exercise ordered Semantic Version floors.',
				];
			}
			if($allRollingReversible){
				$manifest['migrations'][2]['down']['safety']='data_loss';
				$manifest['migrations'][3]['down']=[
					'path'=>'004_irreversible.down.sql',
					'sha256'=>str_repeat('0', 64),
					'safety'=>'data_loss',
				];
				unset($manifest['migrations'][3]['irreversible_reason']);
			}
			foreach($manifest['migrations'] as $index=>&$entry){
				foreach(['up', 'down'] as $direction){
					if(!is_array($entry[$direction] ?? null)){
						continue;
					}
					$sql=
						$rollingSafe
						&& $direction==='up'
						&& $entry['phase']==='rolling_expand'
							? "COMMENT ON SCHEMA fixture IS NULL;\n"
							: 'SELECT '.($index+1).
								($direction==='down' ? '0' : '').";\n";
					$path=(string)$entry[$direction]['path'];
					$workspace->file('database/postgresql/'.$path, $sql);
					$entry[$direction]['sha256']=hash('sha256', $sql);
				}
			}
			unset($entry);
		}
	);
	return PostgreSqlMigrationManifest::load($database, dp_postgresql_migration_profile());
}

/** @param list<array<string,mixed>> $rows */
function dp_postgresql_migration_queue_status(
	ScriptedPdo $pdo,
	array $rows,
	bool $journalExists=true,
	bool $eventExists=true
): ScriptedPdo {
	$pdo->queueScalar($journalExists ? 1 : 0);
	if($journalExists){
		$pdo->queueRows($rows);
	}
	$pdo->queueScalar($eventExists ? 1 : 0);
	return $pdo;
}

test('PostgreSQL migration profiles keep application policy explicit and immutable', static function(Context $t): void {
	$profile=dp_postgresql_migration_profile();
	$t->same('fixture', $profile->applicationId());
	$t->same('fixture', $profile->schema());
	$t->same('schema_migrations', $profile->journalTable());
	$t->same('schema_migration_events', $profile->eventTable());
	$t->same('release_sha256', $profile->releaseDigestColumn());
	$t->same('fixture.postgresql_migrations', $profile->advisoryLock());
	$t->same(['001_base', '002_cutoff'], $profile->bootstrapIds());
	$t->same('002_cutoff', $profile->bootstrapCutoff());
	$t->same('migrations/postgresql/manifest.json', $profile->manifestPublicPath());
	$t->same('3s', $profile->lockTimeout());
	$t->same('2min', $profile->statementTimeout());
	$t->same('"fixture"."schema_migrations"', $profile->journalQualified());
	$t->same('"fixture"."schema_migration_events"', $profile->eventQualified());
	$t->same('"fixture"."schema_migrations"', $profile->journalRegclass());
	$t->same('"fixture"."schema_migration_events"', $profile->eventRegclass());
	$t->same('"fixture"."other_table"', $profile->qualified('other_table'));
	$t->same('fixture_checksum', $profile->constraintName('checksum'));
	$long=PostgreSqlMigrationProfile::fromArray([
		'application_id'=>str_repeat('a', 63),
		'schema'=>'fixture',
		'advisory_lock'=>'fixture.lock',
		'bootstrap_cutoff'=>'001_cutoff',
	]);
	$t->same(63, strlen($long->constraintName(str_repeat('b', 63))));
	$t->same(
		'pg_advisory_xact_lock:fixture.postgresql_migrations',
		$profile->lockEvidence()
	);
	$t->same('fixture', $profile->jsonSerialize()['application_id']);
	$compatibilityProfile=dp_postgresql_migration_profile([
		'release_digest_column'=>'artifact_digest_sha256',
	]);
	$t->same('artifact_digest_sha256', $compatibilityProfile->releaseDigestColumn());
	$t->same(
		'artifact_digest_sha256',
		$compatibilityProfile->jsonSerialize()['release_digest_column']
	);
	$mixedCaseProfile=dp_postgresql_migration_profile([
		'schema'=>'Fixture',
		'journal_table'=>'Schema_Migrations',
		'event_table'=>'Schema_Migration_Events',
	]);
	$t->same('"Fixture"."Schema_Migrations"', $mixedCaseProfile->journalRegclass());
	$t->same(
		'"Fixture"."Schema_Migration_Events"',
		$mixedCaseProfile->eventRegclass()
	);
	$t->same(
		$profile->jsonSerialize(),
		json_decode((string)json_encode($profile, JSON_THROW_ON_ERROR), true)
	);
	foreach(['0.0.0', '2.7.4', '1.0.0-rc.1+build.9'] as $version){
		$t->isTrue(PostgreSqlMigrationProfile::validVersion($version), $version);
	}
	foreach([null, '', 'v1.0.0', '1.0'] as $version){
		$t->isFalse(PostgreSqlMigrationProfile::validVersion($version));
	}
})->tag('sql', 'migration', 'postgresql', 'profile')->group('framework-coverage');

test('PostgreSQL migration profiles compare exact Semantic Version precedence', static function(Context $t): void {
	$precedence=[
		'1.0.0-alpha',
		'1.0.0-alpha.1',
		'1.0.0-alpha.beta',
		'1.0.0-beta',
		'1.0.0-beta.2',
		'1.0.0-beta.11',
		'1.0.0-rc.1',
		'1.0.0',
	];
	foreach(array_map(null, array_slice($precedence, 0, -1), array_slice($precedence, 1)) as [$lower,$higher]){
		$t->same(-1, PostgreSqlMigrationProfile::compareVersions($lower, $higher));
		$t->same(1, PostgreSqlMigrationProfile::compareVersions($higher, $lower));
	}
	$t->same(0, PostgreSqlMigrationProfile::compareVersions(
		'2.0.0+build.1',
		'2.0.0+build.999'
	));
	$t->same(1, PostgreSqlMigrationProfile::compareVersions(
		'1.0.0-zeta',
		'1.0.0-alpha'
	));
	$t->same(-1, PostgreSqlMigrationProfile::compareVersions(
		'1.0.0-alpha.2',
		'1.0.0-alpha.3'
	));
	$t->same(1, PostgreSqlMigrationProfile::compareVersions('2.0.0', '1.999.999'));
	$t->same(1, PostgreSqlMigrationProfile::compareVersions('1.2.0', '1.1.999'));
	$t->same(1, PostgreSqlMigrationProfile::compareVersions('1.0.2', '1.0.1'));
	$t->same(1, PostgreSqlMigrationProfile::compareVersions(
		'999999999999999999999999999999.0.0',
		'99999999999999999999999999999.0.0'
	));
	foreach([
		['v1.0.0', '1.0.0'],
		['1.0.0', '1.0'],
	] as [$left,$right]){
		$t->throws(
			static fn()=>PostgreSqlMigrationProfile::compareVersions($left, $right),
			InvalidArgumentException::class,
			'exact semantic versions'
		);
	}
})->tag('sql', 'migration', 'postgresql', 'profile', 'semver')->group('framework-coverage');

test('PostgreSQL migration profiles reject ambiguous or unsafe policy', static function(Context $t): void {
	$invalid=[
		[['unknown'=>true], 'Unknown PostgreSQL migration profile keys'],
		[['application_id'=>'bad-id'], 'application id'],
		[['schema'=>'bad schema'], 'schema'],
		[['journal_table'=>'bad.table'], 'journal table'],
		[['event_table'=>'bad.table'], 'event table'],
		[['event_table'=>'schema_migrations'], 'must be distinct'],
		[['release_digest_column'=>'bad.column'], 'release digest column'],
		[['release_digest_column'=>'operation_id'], 'collides with a fixed event column'],
		[['release_digest_column'=>'RELEASE_VERSION'], 'collides with a fixed event column'],
		[['advisory_lock'=>''], 'advisory lock key'],
		[['advisory_lock'=>str_repeat('x', 192)], 'advisory lock key'],
		[['advisory_lock'=>"bad\nlock"], 'advisory lock key'],
		[['bootstrap_ids'=>'not-a-list'], 'bootstrap IDs must be a list'],
		[['bootstrap_ids'=>['002_wrong']], 'bootstrap IDs are invalid'],
		[['bootstrap_ids'=>['001_one', '001_one']], 'bootstrap IDs are invalid'],
		[['bootstrap_cutoff'=>'cutoff'], 'bootstrap cutoff'],
		[['bootstrap_cutoff'=>'001_'.str_repeat('a', 125)], 'bootstrap cutoff'],
		[
			['bootstrap_ids'=>['001_one'], 'bootstrap_cutoff'=>'002_cutoff'],
			'final bootstrap ID',
		],
		[['manifest_public_path'=>'/absolute/manifest.json'], 'must be relative'],
		[['manifest_public_path'=>'C:/manifest.json'], 'must be relative'],
		[['manifest_public_path'=>'migrations/../manifest.json'], 'is unsafe'],
		[['manifest_public_path'=>'migrations//manifest.json'], 'is unsafe'],
		[['lock_timeout'=>'0s'], 'lock timeout'],
		[['statement_timeout'=>'forever'], 'statement timeout'],
	];
	foreach($invalid as [$override,$message]){
		$t->throws(
			static fn()=>dp_postgresql_migration_profile($override),
			InvalidArgumentException::class,
			$message
		);
	}
	$t->throws(
		static fn()=>dp_postgresql_migration_profile()->qualified('bad-object'),
		InvalidArgumentException::class,
		'schema object'
	);
	$t->throws(
		static fn()=>dp_postgresql_migration_profile()->constraintName('bad-suffix'),
		InvalidArgumentException::class,
		'constraint suffix'
	);
})->tag('sql', 'migration', 'postgresql', 'profile', 'validation')->group('framework-coverage');

test('PostgreSQL migration manifests normalize immutable SQL and public evidence', static function(Context $t): void {
	[$database,,$raw]=dp_postgresql_migration_manifest_fixture($t, 'valid');
	$manifest=PostgreSqlMigrationManifest::load($database, dp_postgresql_migration_profile());
	$t->same($database.'/postgresql/manifest.json', $manifest->path());
	$t->same('migrations/postgresql/manifest.json', $manifest->publicPath());
	$t->same(64, strlen($manifest->sha256()));
	$t->same(3, $manifest->schemaVersion());
	$t->same('002_cutoff', $manifest->bootstrapCutoff());
	$t->same(['path'=>'fixture', 'revision'=>'test'], $manifest->source());
	$t->same(array_column($raw['migrations'], 'id'), array_column($manifest->entries(), 'id'));
	$t->same('Fixture bootstrap baseline.', $manifest->entries()[0]['irreversible_reason']);
	$t->same(null, $manifest->entries()[2]['irreversible_reason']);
	$t->same('schema', $manifest->entries()[2]['change_kind']);
	$t->same('lossless', $manifest->entries()[2]['down']['safety']);
	$t->same(
		[
			'bootstrap'=>2,
			'rolling_contract'=>1,
			'rolling_expand'=>2,
		],
		$manifest->publicSummary()['phases']
	);
	$t->same(5, $manifest->publicSummary()['migration_count']);
	$t->same($manifest->sha256(), $manifest->publicSummary()['sha256']);

	[$dataDatabase]=dp_postgresql_migration_manifest_fixture(
		$t,
		'valid-data-only-kind',
		static function(array &$manifest): void {
			$manifest['migrations'][2]['change_kind']='data_only';
		}
	);
	$dataManifest=PostgreSqlMigrationManifest::load(
		$dataDatabase,
		dp_postgresql_migration_profile()
	);
	$t->same('data_only', $dataManifest->entries()[2]['change_kind']);
	$publishedSchema=json_decode(
		(string)file_get_contents(
			dirname(__DIR__).'/documentation/postgresql-migration-manifest-v3.schema.json'
		),
		true,
		512,
		JSON_THROW_ON_ERROR
	);
	$t->same(
		['schema', 'data_only'],
		$publishedSchema['$defs']['migration']['properties']['change_kind']['enum'] ?? null
	);
	$t->same(
		'schema',
		$publishedSchema['$defs']['migration']['properties']['change_kind']['default'] ?? null
	);
})->tag('sql', 'migration', 'postgresql', 'manifest')->group('framework-coverage');

test('PostgreSQL migration manifests fail closed on shape phase and direction drift', static function(Context $t): void {
	$profile=dp_postgresql_migration_profile();
	$cases=[
			'unsupported-shape'=>[
				static function(array &$manifest): void { $manifest['schema_version']=2; },
				'unsupported shape',
			],
			'root-extra-key'=>[
				static function(array &$manifest): void { $manifest['extra']=true; },
				'unsupported shape',
			],
			'root-missing-algorithm'=>[
				static function(array &$manifest): void { unset($manifest['algorithm']); },
				'unsupported shape',
			],
			'unsupported-algorithm'=>[
				static function(array &$manifest): void { $manifest['algorithm']='sha512'; },
				'unsupported shape',
			],
			'bootstrap-cutoff-mismatch'=>[
				static function(array &$manifest): void {
					$manifest['bootstrap_cutoff']='001_base';
				},
				'unsupported shape',
			],
			'source-is-list'=>[
				static function(array &$manifest): void { $manifest['source']=[]; },
				'unsupported shape',
			],
			'migrations-not-list'=>[
				static function(array &$manifest): void {
					$manifest['migrations']=['first'=>$manifest['migrations'][0]];
				},
				'unsupported shape',
			],
			'nonportable-source-key'=>[
				static function(array &$manifest): void { $manifest['source']=[1=>'fixture']; },
				'source provenance keys must be portable identifiers',
			],
		'non-object-entry'=>[
			static function(array &$manifest): void { $manifest['migrations'][]='invalid'; },
			'non-object entry',
		],
		'invalid-entry'=>[
			static function(array &$manifest): void { $manifest['migrations'][0]['description']=' '; },
			'entry identity',
		],
		'entry-extra-key'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['extra']=true;
			},
			'entry identity',
		],
		'entry-invalid-id'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['id']='base';
			},
			'entry identity',
		],
		'entry-out-of-order-id'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['id']='002_base';
			},
			'entry identity',
		],
		'entry-invalid-phase'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['phase']='maintenance';
			},
			'entry identity',
		],
		'entry-description-type'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['description']=false;
			},
			'entry identity',
		],
		'entry-invalid-change-kind'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['change_kind']='rows';
			},
			'change kind is invalid',
		],
		'reversible-entry-has-reason'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['irreversible_reason']='Not applicable.';
			},
			'entry identity',
		],
		'contract-floor'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][4]['minimum_compatible_release']=null;
			},
			'exact minimum compatible release',
		],
		'expand-floor'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['minimum_compatible_release']='1.0.0';
			},
			'Only rolling contract',
		],
		'up-not-object'=>[
			static function(array &$manifest): void { $manifest['migrations'][0]['up']='bad'; },
			'up direction must be an object',
		],
		'up-keys'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['up']['extra']=true;
			},
			'up direction keys are invalid',
		],
		'blank-reason'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][3]['irreversible_reason']=' ';
			},
			'Irreversible migration requires a reason',
		],
		'down-not-object'=>[
			static function(array &$manifest): void { $manifest['migrations'][2]['down']='bad'; },
			'down direction must be an object or null',
		],
		'down-safety'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['down']['safety']='unknown';
			},
			'down safety contract',
		],
		'change-kind'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['change_kind']='rows';
			},
			'change kind is invalid',
		],
		'down-extra-key'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][2]['down']['extra']=true;
			},
			'down safety contract',
		],
		'bootstrap-filename'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/999_other.sql', $sql);
				$manifest['migrations'][0]['up']=[
					'path'=>'999_other.sql',
					'sha256'=>hash('sha256', $sql),
				];
			},
			'stable legacy filename',
		],
		'bootstrap-reversible'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/002_cutoff.down.sql', $sql);
				$manifest['migrations'][1]['down']=[
					'path'=>'002_cutoff.down.sql',
					'sha256'=>hash('sha256', $sql),
					'safety'=>'lossless',
				];
				unset($manifest['migrations'][1]['irreversible_reason']);
			},
			'must remain irreversible',
		],
		'rolling-up-filename'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/999_other.up.sql', $sql);
				$manifest['migrations'][2]['up']=[
					'path'=>'999_other.up.sql',
					'sha256'=>hash('sha256', $sql),
				];
			},
			'stable .up.sql filename',
		],
		'rolling-down-filename'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/999_other.down.sql', $sql);
				$manifest['migrations'][2]['down']=[
					'path'=>'999_other.down.sql',
					'sha256'=>hash('sha256', $sql),
					'safety'=>'lossless',
				];
			},
			'stable .down.sql filename',
		],
		'post-cutoff-bootstrap'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/003_expand.sql', $sql);
				$manifest['migrations'][2]['phase']='bootstrap';
				$manifest['migrations'][2]['up']=[
					'path'=>'003_expand.sql',
					'sha256'=>hash('sha256', $sql),
				];
				$manifest['migrations'][2]['down']=null;
				$manifest['migrations'][2]['irreversible_reason']='Fixture.';
			},
			'Post-cutoff migration',
		],
		'pre-cutoff-rolling'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1;\n";
				$workspace->file('database/postgresql/001_base.up.sql', $sql);
				$manifest['migrations'][0]['phase']='rolling_expand';
				$manifest['migrations'][0]['up']=[
					'path'=>'001_base.up.sql',
					'sha256'=>hash('sha256', $sql),
				];
			},
			'Pre-cutoff migration',
		],
		'incomplete-boundary'=>[
			static function(array &$manifest): void {
				$manifest['migrations']=[$manifest['migrations'][0]];
			},
			'no complete bootstrap boundary',
		],
		'unlisted'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$workspace->file('database/postgresql/999_unlisted.sql', "SELECT 1;\n");
			},
			'Unlisted PostgreSQL migrations',
		],
		'checksum'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['up']['sha256']=str_repeat('0', 64);
			},
			'checksum mismatch',
		],
		'missing-file'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['up']['path']='001_missing.sql';
				$manifest['migrations'][0]['up']['sha256']=hash('sha256', "missing\n");
			},
			'file is missing',
		],
		'invalid-direction-identity'=>[
			static function(array &$manifest): void {
				$manifest['migrations'][0]['up']['path']='../001_base.sql';
			},
			'direction identity is invalid',
		],
		'transaction-control'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="BEGIN;\nSELECT 1;\n";
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			},
			'transaction control is owned by Dataphyre',
		],
		'same-line-transaction-control'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="SELECT 1; BEGIN;\n";
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			},
			'transaction control is owned by Dataphyre',
		],
		'psql-command'=>[
			static function(array &$manifest, TempWorkspace $workspace): void {
				$sql="\\set fixture on\nSELECT 1;\n";
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			},
			'psql meta-commands are not supported',
		],
	];
	foreach($cases as $name=>[$mutate,$message]){
		[$database]=dp_postgresql_migration_manifest_fixture($t, $name, $mutate);
		$t->throws(
			static fn()=>PostgreSqlMigrationManifest::load($database, $profile),
			RuntimeException::class,
			$message
		);
	}
	foreach([
		'begin-eof'=>"BEGIN\n",
		'start-transaction'=>"START TRANSACTION\n",
		'commit-eof'=>"COMMIT\n",
		'end-transaction'=>"END\n",
		'abort-transaction'=>"ABORT\n",
		'rollback-eof'=>"ROLLBACK\n",
		'savepoint'=>"SAVEPOINT fixture_point\n",
		'release-savepoint'=>"RELEASE SAVEPOINT fixture_point\n",
		'release-implicit-savepoint'=>"RELEASE fixture_point\n",
		'prepare-transaction'=>"PREPARE TRANSACTION 'fixture-transaction'\n",
		'set-transaction'=>"SET TRANSACTION ISOLATION LEVEL SERIALIZABLE\n",
		'set-session-transaction'=>
			"SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE\n",
	] as $name=>$sql){
		[$database]=dp_postgresql_migration_manifest_fixture(
			$t,
			$name,
			static function(array &$manifest, TempWorkspace $workspace) use ($sql): void {
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			}
		);
		$t->throws(
			static fn()=>PostgreSqlMigrationManifest::load($database, $profile),
			RuntimeException::class,
			'transaction control is owned by Dataphyre'
		);
	}
	foreach([
		'create-index-concurrently'=>
			"CREATE UNIQUE INDEX CONCURRENTLY fixture_base_note_idx ".
			"ON fixture.base (note)\n",
		'drop-index-concurrently'=>
			"DROP INDEX CONCURRENTLY IF EXISTS fixture.fixture_base_note_idx\n",
		'reindex-concurrently'=>
			"REINDEX (VERBOSE) TABLE CONCURRENTLY fixture.base\n",
	] as $name=>$sql){
		[$database]=dp_postgresql_migration_manifest_fixture(
			$t,
			$name,
			static function(array &$manifest, TempWorkspace $workspace) use ($sql): void {
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			}
		);
		$t->throws(
			static fn()=>PostgreSqlMigrationManifest::load($database, $profile),
			RuntimeException::class,
			'Concurrent index operations cannot run'
		);
	}
	foreach([
		'vacuum'=>"VACUUM (ANALYZE) fixture.base\n",
		'create-database'=>"CREATE DATABASE fixture_migration_test\n",
		'drop-database'=>"DROP DATABASE IF EXISTS fixture_migration_test\n",
		'alter-system'=>"ALTER SYSTEM SET work_mem = '64MB'\n",
		'create-tablespace'=>
			"CREATE TABLESPACE fixture_space LOCATION '/srv/fixture'\n",
		'drop-tablespace'=>"DROP TABLESPACE IF EXISTS fixture_space\n",
		'cluster'=>"CLUSTER fixture.base USING fixture_base_note_idx\n",
		'checkpoint'=>"CHECKPOINT\n",
		'discard-all'=>"DISCARD ALL\n",
		'refresh-materialized-view-concurrently'=>
			"REFRESH MATERIALIZED VIEW CONCURRENTLY fixture.fixture_view\n",
		'reindex-database'=>"REINDEX DATABASE fixture_database\n",
		'reindex-system-options'=>
			"REINDEX (VERBOSE) SYSTEM fixture_database\n",
		'reindex-tablespace'=>"REINDEX TABLESPACE fixture_space\n",
	] as $name=>$sql){
		[$database]=dp_postgresql_migration_manifest_fixture(
			$t,
			'transaction-incompatible-'.$name,
			static function(array &$manifest, TempWorkspace $workspace) use ($sql): void {
				$workspace->file('database/postgresql/003_expand.up.sql', $sql);
				$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
			}
		);
		$t->throws(
			static fn()=>PostgreSqlMigrationManifest::load($database, $profile),
			RuntimeException::class,
			'cannot run inside the Dataphyre-owned transaction'
		);
		$t->same(
			['transaction_incompatible_statement'],
			array_column(PostgreSqlMigrationManifest::sqlSafetyIssues($sql), 'code'),
			$name
		);
	}

	$maskedSql=<<<'SQL'
SELECT '; BEGIN; COMMIT; ROLLBACK; it''s hidden' AS transaction_words;
SELECT 'START TRANSACTION; END; ABORT; SAVEPOINT; RELEASE; PREPARE TRANSACTION; SET TRANSACTION' AS more_hidden_words;
-- BEGIN; \set commented_out
/* outer COMMIT; /* nested ROLLBACK; */ still hidden */
SELECT E'BEGIN; escaped\\COMMIT;' AS escaped_text;
SELECT $body$ROLLBACK; \set inside_dollar_body$body$ AS body_text;
SELECT "BE""GIN" AS quoted_identifier;
SELECT 'CREATE INDEX CONCURRENTLY; DROP INDEX CONCURRENTLY; REINDEX CONCURRENTLY' AS hidden_index_words;
SELECT 'VACUUM; CREATE DATABASE hidden; ALTER SYSTEM hidden; CHECKPOINT; DISCARD ALL' AS hidden_admin_words;
SELECT 'DROP DATABASE hidden; CREATE TABLESPACE hidden; DROP TABLESPACE hidden; CLUSTER hidden; REFRESH MATERIALIZED VIEW CONCURRENTLY hidden; REINDEX DATABASE hidden' AS more_hidden_admin_words;
SQL;
	$maskedSql.=
		"\n-- final BEGIN; COMMIT; ROLLBACK; VACUUM; CREATE DATABASE hidden;";
	[$maskedDatabase]=dp_postgresql_migration_manifest_fixture(
		$t,
		'masked-transaction-words',
		static function(array &$manifest, TempWorkspace $workspace) use ($maskedSql): void {
			$workspace->file('database/postgresql/003_expand.up.sql', $maskedSql);
			$manifest['migrations'][2]['up']['sha256']=hash('sha256', $maskedSql);
		}
	);
		$maskedManifest=PostgreSqlMigrationManifest::load($maskedDatabase, $profile);
		$t->same($maskedSql, $maskedManifest->entries()[2]['up']['sql']);
		$t->same([], PostgreSqlMigrationManifest::sqlSafetyIssues($maskedSql));
		$t->same([], PostgreSqlMigrationManifest::sqlSafetyIssues(<<<'SQL'
UPDATE fixture.items
SET normalized_key=CASE
	WHEN source_key IS NOT NULL THEN source_key
	ELSE 'legacy-'||id::text
END
WHERE normalized_key IS NULL;
SQL
		), 'CASE END at the beginning of a line is not transaction control');
		$t->same(
			[
				'transaction_control',
				'transaction_incompatible_statement',
				'concurrent_index_operation',
				'psql_meta_command',
			],
			array_column(PostgreSqlMigrationManifest::sqlSafetyIssues(
				"BEGIN;\nVACUUM fixture.base;\n".
				"CREATE INDEX CONCURRENTLY fixture_idx ON fixture.base (id);\n".
				"\\set fixture on\n"
			), 'code')
		);

	$missing=$t->workspace('dataphyre-postgresql-migrations-missing');
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($missing->path('absent'), $profile),
		RuntimeException::class,
		'database root is unavailable'
	);
	$noManifest=$missing->directory('database');
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($noManifest, $profile),
		RuntimeException::class,
		'manifest is missing'
	);
	[$symlinkDatabase,$symlinkWorkspace]=dp_postgresql_migration_manifest_fixture(
		$t,
		'symbolic-root'
	);
	$symlinkRoot=$symlinkWorkspace->path('database-link');
	$t->same(true, symlink($symlinkDatabase, $symlinkRoot));
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($symlinkRoot, $profile),
		RuntimeException::class,
		'may not be a symbolic link'
	);
	[$symlinkDirectorySource,$symlinkDirectoryWorkspace]=
		dp_postgresql_migration_manifest_fixture($t, 'symbolic-postgresql-source');
	$symlinkDirectoryDatabase=$symlinkDirectoryWorkspace->directory(
		'symbolic-postgresql-database'
	);
	$t->same(
		true,
		symlink(
			$symlinkDirectorySource.'/postgresql',
			$symlinkDirectoryDatabase.'/postgresql'
		)
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load(
			$symlinkDirectoryDatabase,
			$profile
		),
		RuntimeException::class,
		'manifest is missing'
	);
	[$symlinkManifestDatabase,$symlinkManifestWorkspace]=
		dp_postgresql_migration_manifest_fixture($t, 'symbolic-manifest');
	$symlinkManifestPath=$symlinkManifestWorkspace->path(
		'database/postgresql/manifest.json'
	);
	$symlinkManifestTarget=$symlinkManifestWorkspace->path(
		'database/postgresql/manifest.target'
	);
	$t->same(
		true,
		rename($symlinkManifestPath, $symlinkManifestTarget)
	);
	$t->same(true, symlink($symlinkManifestTarget, $symlinkManifestPath));
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load(
			$symlinkManifestDatabase,
			$profile
		),
		RuntimeException::class,
		'manifest is missing'
	);
	[$symlinkSqlDatabase,$symlinkSqlWorkspace]=
		dp_postgresql_migration_manifest_fixture($t, 'symbolic-sql');
	$symlinkSqlPath=$symlinkSqlWorkspace->path(
		'database/postgresql/003_expand.up.sql'
	);
	$symlinkSqlTarget=$symlinkSqlWorkspace->path(
		'database/postgresql/003_expand.up.target'
	);
	$t->same(true, rename($symlinkSqlPath, $symlinkSqlTarget));
	$t->same(true, symlink($symlinkSqlTarget, $symlinkSqlPath));
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($symlinkSqlDatabase, $profile),
		RuntimeException::class,
		'file is missing or outside'
	);
	$invalidJson=$missing->directory('invalid-json');
	$missing->file('invalid-json/postgresql/manifest.json', '{');
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($invalidJson, $profile),
		RuntimeException::class,
		'invalid JSON'
	);
})->tag('sql', 'migration', 'postgresql', 'manifest', 'validation')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration manifests enforce the configured bootstrap lineage', static function(Context $t): void {
	[$database]=dp_postgresql_migration_manifest_fixture($t, 'profile-prefix');
	$profile=dp_postgresql_migration_profile([
		'bootstrap_ids'=>['001_other', '002_cutoff'],
	]);
	$t->throws(
		static fn()=>PostgreSqlMigrationManifest::load($database, $profile),
		RuntimeException::class,
		'bootstrap prefix does not match'
	);
})->tag('sql', 'migration', 'postgresql', 'manifest', 'lineage')->group('framework-coverage');

test('PostgreSQL migration runner accepts only PostgreSQL protocol connections', static function(Context $t): void {
	$profile=dp_postgresql_migration_profile();
	$t->throws(
		static fn()=>new PostgreSqlMigrationRunner($t->scriptedPdo('sqlite'), $profile),
		InvalidArgumentException::class,
		'require a pgsql PDO connection'
	);
	$driverFailure=$t->scriptedPdo('pgsql')->failDriverWith(new RuntimeException('driver failure'));
	$t->throws(
		static fn()=>new PostgreSqlMigrationRunner($driverFailure, $profile),
		InvalidArgumentException::class,
		'could not inspect'
	);
	$runner=new PostgreSqlMigrationRunner($t->scriptedPdo('pgsql'), $profile);
	$t->isTrue($runner instanceof PostgreSqlMigrationRunner);
})->tag('sql', 'migration', 'postgresql', 'runner')->group('framework-coverage');

test('PostgreSQL migration runner scans rolling SQL without trusting comments or strings', static function(Context $t): void {
	$safe=<<<'SQL'
-- DROP TABLE fixture.not_code;
/* TRUNCATE fixture.not_code; /* nested DROP TABLE */ */
COMMENT ON TABLE fixture.items IS 'DELETE FROM strings_are_not_code';
CREATE TABLE IF NOT EXISTS fixture.rolling_fixture (fixture_id TEXT PRIMARY KEY);
CREATE TABLE fixture.rolling_evidence (
	fixture_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	evidence_key TEXT NOT NULL UNIQUE,
	status TEXT NOT NULL DEFAULT 'pending',
	CONSTRAINT rolling_evidence_status_check CHECK (status IN ('pending','complete'))
);
ALTER TABLE fixture.items ADD COLUMN IF NOT EXISTS nullable_note TEXT;
ALTER TABLE fixture.items ADD COLUMN nullable_ratio NUMERIC(12, 4) NULL;
ALTER TABLE fixture.items ADD COLUMN nullable_payload JSONB[];
COMMENT ON TABLE "fi""xture"."it""ems" IS E'BEGIN; escaped\\COMMIT;';
COMMENT ON COLUMN fixture.items.note IS 'it''s still not executable BEGIN;';
GRANT SELECT ON fixture.items TO reporting_reader;
SQL;
	$safe.="\n-- trailing DROP TABLE fixture.not_code;";
	$t->same([], PostgreSqlMigrationRunner::rollingSqlIssues($safe));
	$incompatible=[
		'drop_object'=>'DROP TABLE fixture.items;',
		'truncate_rows'=>'TRUNCATE fixture.items;',
		'delete_rows'=>'WITH old AS (SELECT 1) DELETE FROM fixture.items;',
		'replace_object'=>'CREATE OR REPLACE VIEW fixture.item_view AS SELECT 1;',
		'create_trigger'=>'CREATE TRIGGER item_guard BEFORE INSERT ON fixture.items EXECUTE FUNCTION guard();',
		'revoke_privilege'=>'REVOKE INSERT ON fixture.items FROM application_user;',
		'dynamic_sql'=>'DO $body$ BEGIN EXECUTE \'DROP TABLE fixture.items\'; END $body$;',
		'set_not_null'=>'ALTER TABLE fixture.items ALTER COLUMN note SET NOT NULL;',
		'add_not_null_column'=>'ALTER TABLE fixture.items ADD COLUMN required_note TEXT NOT NULL;',
		'incompatible_alter_table'=>'ALTER TABLE fixture.items ADD CONSTRAINT item_check CHECK (name <> \'\');',
		'incompatible_alter'=>'ALTER TYPE fixture.item_state RENAME VALUE \'old\' TO \'new\';',
		'unsafe_create_table'=>'CREATE TABLE fixture.child (item_id TEXT REFERENCES fixture.items (item_id));',
		'unsafe_comment'=>'COMMENT ON TABLE fixture.items RENAME;',
		'data_mutation_not_allowlisted'=>'UPDATE fixture.items SET note = NULL;',
		'privilege_change_not_allowlisted'=>'GRANT UPDATE ON fixture.items TO application_user;',
		'unapproved_statement'=>'LOCK TABLE fixture.items IN ACCESS EXCLUSIVE MODE;',
		'create_index_requires_concurrent_autocommit_protocol'=>
			'CREATE UNIQUE INDEX CONCURRENTLY item_name_idx ON fixture.items (name);',
	];
	foreach($incompatible as $expected=>$sql){
		$issues=PostgreSqlMigrationRunner::rollingSqlIssues($sql);
		$t->same($expected, $issues[0]['code'] ?? null, $sql);
		$t->same(1, $issues[0]['statement'] ?? null, $sql);
	}
	$t->same(
		'replace_object',
		PostgreSqlMigrationRunner::rollingSqlIssues(<<<'SQL'
CREATE OR REPLACE FUNCTION fixture.guard() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
SQL
		)[0]['code'] ?? null
	);
	foreach([
		'GRANT SELECT,UPDATE ON fixture.items TO application_user;',
		'GRANT ALL ON fixture.items TO application_user;',
		'GRANT SELECT ON items TO application_user;',
		'GRANT SELECT (name) ON fixture.items TO application_user;',
		'GRANT SELECT ON fixture.items, fixture.other_items TO application_user;',
		'GRANT SELECT ON ALL TABLES IN SCHEMA fixture TO application_user;',
		'GRANT SELECT ON fixture.items TO application_user, reporting_reader;',
		'GRANT SELECT ON fixture.items TO PUBLIC;',
		'GRANT SELECT ON fixture.items TO CURRENT_USER;',
		'GRANT SELECT ON fixture.items TO application_user WITH GRANT OPTION;',
	] as $sql){
		$t->same(
			'privilege_change_not_allowlisted',
			PostgreSqlMigrationRunner::rollingSqlIssues($sql)[0]['code'] ?? null,
			$sql
		);
	}
	foreach([
		'CREATE TABLE malformed',
		'CREATE TABLE fixture.copied (item_id) AS SELECT item_id FROM fixture.items;',
		'CREATE TABLE fixture.copied_parenthesized (item_id) AS (SELECT item_id FROM fixture.items);',
		'CREATE TABLE fixture.inherited (note TEXT) INHERITS (fixture.items);',
		'ALTER TABLE fixture.items RENAME TO renamed_items',
		'ALTER TABLE fixture.items ADD COLUMN note TEXT DEFAULT \'fixture\'',
		'ALTER TABLE fixture.items ALTER COLUMN tenant_type SET DEFAULT \'change_governance\'',
		'ALTER TABLE fixture.items ADD COLUMN note TEXT, ADD COLUMN other TEXT',
	] as $sql){
		$t->same(
			str_starts_with($sql, 'CREATE TABLE')
				? 'unsafe_create_table'
				: 'incompatible_alter_table',
			PostgreSqlMigrationRunner::rollingSqlIssues($sql)[0]['code'] ?? null,
			$sql
		);
	}
	$t->same(
		[
			['code'=>'drop_object', 'statement'=>1],
			['code'=>'truncate_rows', 'statement'=>2],
		],
		PostgreSqlMigrationRunner::rollingSqlIssues(
			"DROP TABLE fixture.one;\nTRUNCATE fixture.two;"
		)
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollingSqlIssues(str_repeat('SELECT 1;', PostgreSqlMigrationRunner::MAX_ROLLING_STATEMENTS+1)),
		InvalidArgumentException::class,
		'statement-count safety boundary'
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollingSqlIssues(str_repeat(' ', PostgreSqlMigrationRunner::MAX_ROLLING_SQL_BYTES+1)),
		InvalidArgumentException::class,
		'8 MiB safety boundary'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rolling')->group('framework-coverage');

test('PostgreSQL migration runner plans bootstrap and rolling compatibility from immutable state', static function(Context $t): void {
	[$database]=dp_postgresql_migration_manifest_fixture(
		$t,
		'runner-deployment',
		static function(array &$manifest, TempWorkspace $workspace): void {
			$manifest['migrations']=array_slice($manifest['migrations'], 0, 3);
			foreach([
				'004_irreversible.up.sql',
				'005_contract.up.sql',
				'005_contract.down.sql',
			] as $path){
				unlink($workspace->path('database/postgresql/'.$path));
			}
		}
	);
	$manifest=PostgreSqlMigrationManifest::load($database, dp_postgresql_migration_profile());
	$runner=new PostgreSqlMigrationRunner(
		$t->scriptedPdo('pgsql'),
		dp_postgresql_migration_profile()
	);
	$state=static function(string $bootstrap, string $rolling): array {
		return [
			'drift_count'=>0,
			'migrations'=>[
				['id'=>'001_base', 'status'=>$bootstrap],
				['id'=>'002_cutoff', 'status'=>$bootstrap],
				['id'=>'003_expand', 'status'=>$rolling],
			],
		];
	};
	$first=$runner->deploymentEvidence($manifest, $state('pending', 'pending'), 'bootstrap');
	$t->same(true, $first['eligible']);
	$t->same(['001_base', '002_cutoff', '003_expand'], $first['pending_migrations']);
	$t->same(['001_base', '002_cutoff', '003_expand'], $first['selected_migrations']);
	$t->same([], $first['deferred_migrations']);
	$t->same(false, $first['rolling_scan']['performed']);

	$promotion=$state('applied', 'pending');
	$wrong=$runner->deploymentEvidence($manifest, $promotion, 'bootstrap');
	$t->same(false, $wrong['eligible']);
	$t->contains(
		'bootstrap_cutoff_already_applied_with_pending_rolling_migrations',
		implode(',', $wrong['errors'])
	);
	$rolling=$runner->deploymentEvidence($manifest, $promotion, 'rolling');
	$t->same(true, $rolling['eligible']);
	$t->same(['003_expand'], $rolling['pending_migrations']);
	$t->same(['003_expand'], $rolling['selected_migrations']);
	$t->same(['rolling_expand'=>1], $rolling['selected_phases']);
	$t->same([], $rolling['deferred_migrations']);
	$t->same(['rolling_expand'=>1], $rolling['pending_phases']);
	$t->same(0, $rolling['rolling_scan']['issue_count']);
	$outOfOrder=$runner->deploymentEvidence(
		$manifest,
		$state('pending', 'applied'),
		'bootstrap'
	);
	$t->contains(
		'bootstrap_history_is_out_of_order',
		implode(',', $outOfOrder['errors'])
	);
	$missingBootstrap=$runner->deploymentEvidence(
		$manifest,
		$state('pending', 'pending'),
		'rolling'
	);
	$t->contains(
		'bootstrap_cutoff_not_applied',
		implode(',', $missingBootstrap['errors'])
	);
	$unselected=$runner->deploymentEvidence($manifest, $promotion, null);
	$t->same(null, $unselected['eligible']);
	$t->same([], $unselected['selected_migrations']);
	$t->same(['003_expand'], $unselected['deferred_migrations']);
	$t->throws(
		static fn()=>$runner->deploymentEvidence($manifest, $promotion, 'replace'),
		InvalidArgumentException::class,
		'must be bootstrap, rolling, or maintenance'
	);
	$t->throws(
		static fn()=>$runner->deploymentEvidence(
			$manifest,
			$promotion,
			'rolling',
			'2.0.0'
		),
		InvalidArgumentException::class,
		'only for maintenance'
	);
	$t->throws(
		static fn()=>$runner->deploymentEvidence(
			$manifest,
			$promotion,
			'maintenance',
			'v2'
		),
		InvalidArgumentException::class,
		'exact semantic version'
	);
	$maintenanceExpand=$runner->deploymentEvidence(
		$manifest,
		$promotion,
		'maintenance'
	);
	$t->same(true, $maintenanceExpand['eligible']);
	$t->same(['003_expand'], $maintenanceExpand['selected_migrations']);
	$t->same([], $maintenanceExpand['deferred_migrations']);
	$t->same(null, $maintenanceExpand['required_minimum_active_release']);
	$t->same(null, $maintenanceExpand['verified_minimum_active_release']);
	$t->same(true, $maintenanceExpand['compatibility_floor_satisfied']);
	$t->same(false, $maintenanceExpand['rolling_scan']['performed']);

	$drifted=$promotion;
	$drifted['drift_count']=1;
	$driftedEvidence=$runner->deploymentEvidence($manifest, $drifted, 'rolling');
	$t->same(false, $driftedEvidence['eligible']);
	$t->contains('migration_state_has_drift', implode(',', $driftedEvidence['errors']));
	$missingDrift=$promotion;
	unset($missingDrift['drift_count']);
	$t->contains(
		'migration_state_drift_count_invalid',
		implode(',', $runner->deploymentEvidence(
			$manifest,
			$missingDrift,
			'rolling'
		)['errors'])
	);
	$missing=$promotion;
	array_pop($missing['migrations']);
	$t->contains(
		'migration_state_status_missing:003_expand',
		implode(',', $runner->deploymentEvidence($manifest, $missing, 'rolling')['errors'])
	);
	$notDeployable=$promotion;
	$notDeployable['migrations'][2]['status']='checksum_drift';
	$t->contains(
		'migration_state_status_not_deployable:003_expand',
		implode(',', $runner->deploymentEvidence(
			$manifest,
			$notDeployable,
			'rolling'
		)['errors'])
	);
	$t->contains(
		'migration_state_entries_invalid',
		implode(',', $runner->deploymentEvidence(
			$manifest,
			['migrations'=>['not'=>'a-list']],
			'rolling'
		)['errors'])
	);
	$malformed=$promotion;
	$malformed['drift_count']='unknown';
	$malformed['migrations'][]='not-an-entry';
	$malformed['migrations'][]=$malformed['migrations'][2];
	$malformed['migrations'][]=['id'=>'999_removed', 'status'=>'applied'];
	$malformedErrors=implode(',', $runner->deploymentEvidence(
		$manifest,
		$malformed,
		'rolling'
	)['errors']);
	foreach([
		'migration_state_entries_invalid',
		'migration_state_entries_duplicate',
		'migration_state_entries_unmanifested',
		'migration_state_drift_count_invalid',
	] as $error){
		$t->contains($error, $malformedErrors);
	}

	[$unsafeDatabase]=dp_postgresql_migration_manifest_fixture(
		$t,
		'runner-unsafe-deployment',
		static function(array &$manifest, TempWorkspace $workspace): void {
			$manifest['migrations']=array_slice($manifest['migrations'], 0, 3);
			foreach([
				'004_irreversible.up.sql',
				'005_contract.up.sql',
				'005_contract.down.sql',
			] as $path){
				unlink($workspace->path('database/postgresql/'.$path));
			}
			$sql="ALTER TABLE fixture.base ADD COLUMN required_note TEXT NOT NULL;\n";
			$workspace->file('database/postgresql/003_expand.up.sql', $sql);
			$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
		}
	);
	$unsafeManifest=PostgreSqlMigrationManifest::load(
		$unsafeDatabase,
		dp_postgresql_migration_profile()
	);
	$rejected=$runner->deploymentEvidence($unsafeManifest, $promotion, 'rolling');
	$t->same(false, $rejected['eligible']);
	$t->same('add_not_null_column', $rejected['rolling_scan']['issues'][0]['code']);

	[$contractDatabase]=dp_postgresql_migration_manifest_fixture($t, 'runner-contract-deployment');
	$contractManifest=PostgreSqlMigrationManifest::load(
		$contractDatabase,
		dp_postgresql_migration_profile()
	);
	$contractState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>$entry['phase']==='rolling_contract' ? 'pending' : 'applied',
			],
			$contractManifest->entries()
		),
	];
	$freshState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>'pending',
			],
			$contractManifest->entries()
		),
	];
	$freshBootstrap=$runner->deploymentEvidence(
		$contractManifest,
		$freshState,
		'bootstrap'
	);
	$t->same(true, $freshBootstrap['eligible']);
	$t->same(
		['001_base', '002_cutoff', '003_expand', '004_irreversible'],
		$freshBootstrap['selected_migrations']
	);
	$t->same(['005_contract'], $freshBootstrap['deferred_migrations']);
	$t->same(
		['bootstrap'=>2, 'rolling_expand'=>2],
		$freshBootstrap['selected_phases']
	);
	$t->same('2.0.0', $freshBootstrap['required_minimum_active_release']);
	$rollingPrefixState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>$entry['phase']==='bootstrap' ? 'applied' : 'pending',
			],
			$contractManifest->entries()
		),
	];
	$rollingPrefix=$runner->deploymentEvidence(
		$contractManifest,
		$rollingPrefixState,
		'rolling'
	);
	$t->same(true, $rollingPrefix['eligible']);
	$t->same(['003_expand', '004_irreversible'], $rollingPrefix['selected_migrations']);
	$t->same(['005_contract'], $rollingPrefix['deferred_migrations']);
	$t->same(2, $rollingPrefix['rolling_scan']['migration_count']);
	$contractEvidence=$runner->deploymentEvidence(
		$contractManifest,
		$contractState,
		'rolling'
	);
	$t->same('2.0.0', $contractEvidence['required_minimum_active_release']);
	$t->same([], $contractEvidence['selected_migrations']);
	$t->same(['005_contract'], $contractEvidence['deferred_migrations']);
	$t->same(null, $contractEvidence['verified_minimum_active_release']);
	$t->same(null, $contractEvidence['compatibility_floor_satisfied']);
	$t->contains(
		'pending_contract_requires_compatibility_finalization:005_contract',
		implode(',', $contractEvidence['errors'])
	);
	$t->contains(
		'pending_migration_is_not_rolling_expand:005_contract',
		implode(',', $contractEvidence['errors'])
	);

	$maintenanceUnverified=$runner->deploymentEvidence(
		$contractManifest,
		$contractState,
		'maintenance'
	);
	$t->same(false, $maintenanceUnverified['eligible']);
	$t->same('2.0.0', $maintenanceUnverified['required_minimum_active_release']);
	$t->same(null, $maintenanceUnverified['verified_minimum_active_release']);
	$t->same(false, $maintenanceUnverified['compatibility_floor_satisfied']);
	$t->contains(
		'pending_contract_requires_verified_minimum_active_release:005_contract:2.0.0',
		implode(',', $maintenanceUnverified['errors'])
	);
	$maintenanceBelow=$runner->deploymentEvidence(
		$contractManifest,
		$contractState,
		'maintenance',
		'2.0.0-rc.1'
	);
	$t->same(false, $maintenanceBelow['eligible']);
	$t->contains(
		'verified_minimum_active_release_below_contract_floor:005_contract:2.0.0',
		implode(',', $maintenanceBelow['errors'])
	);
	$maintenanceExact=$runner->deploymentEvidence(
		$contractManifest,
		$contractState,
		'maintenance',
		'2.0.0+fleet.7'
	);
	$t->same(true, $maintenanceExact['eligible']);
	$t->same(['005_contract'], $maintenanceExact['selected_migrations']);
	$t->same([], $maintenanceExact['deferred_migrations']);
	$t->same('2.0.0+fleet.7', $maintenanceExact['verified_minimum_active_release']);
	$t->same(true, $maintenanceExact['compatibility_floor_satisfied']);
	$maintenanceAbove=$runner->deploymentEvidence(
		$contractManifest,
		$contractState,
		'maintenance',
		'2.1.0'
	);
	$t->same(true, $maintenanceAbove['eligible']);

	$missingMaintenanceBootstrap=$contractState;
	$missingMaintenanceBootstrap['migrations'][1]['status']='pending';
	$t->contains(
		'bootstrap_cutoff_not_applied',
		implode(',', $runner->deploymentEvidence(
			$contractManifest,
			$missingMaintenanceBootstrap,
			'maintenance',
			'2.0.0'
		)['errors'])
	);
	$pendingBootstrap=$contractState;
	$pendingBootstrap['migrations'][0]['status']='pending';
	$t->contains(
		'pending_migration_is_not_maintenance_phase:001_base',
		implode(',', $runner->deploymentEvidence(
			$contractManifest,
			$pendingBootstrap,
			'maintenance',
			'2.0.0'
		)['errors'])
	);

	[$indexDatabase]=dp_postgresql_migration_manifest_fixture(
		$t,
		'runner-maintenance-index',
		static function(array &$manifest, TempWorkspace $workspace): void {
			$manifest['migrations']=array_slice($manifest['migrations'], 0, 3);
			foreach([
				'004_irreversible.up.sql',
				'005_contract.up.sql',
				'005_contract.down.sql',
			] as $path){
				unlink($workspace->path('database/postgresql/'.$path));
			}
			$sql="CREATE INDEX fixture_base_detail_idx ON fixture.base (detail);\n";
			$workspace->file('database/postgresql/003_expand.up.sql', $sql);
			$manifest['migrations'][2]['up']['sha256']=hash('sha256', $sql);
		}
	);
	$indexManifest=PostgreSqlMigrationManifest::load(
		$indexDatabase,
		dp_postgresql_migration_profile()
	);
	$t->same(
		'create_index_requires_concurrent_autocommit_protocol',
		$runner->deploymentEvidence(
			$indexManifest,
			$promotion,
			'rolling'
		)['rolling_scan']['issues'][0]['code'] ?? null
	);
	$t->same(
		true,
		$runner->deploymentEvidence(
			$indexManifest,
			$promotion,
			'maintenance'
		)['eligible']
	);

	$multipleContracts=dp_postgresql_migration_no_schema_manifest(
		$t,
		'runner-multiple-contract-floors',
		false,
		true
	);
	$multipleFreshState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>'pending',
			],
			$multipleContracts->entries()
		),
	];
	$multipleFreshBootstrap=$runner->deploymentEvidence(
		$multipleContracts,
		$multipleFreshState,
		'bootstrap'
	);
	$t->same(
		['001_base', '002_cutoff', '003_expand', '004_irreversible'],
		$multipleFreshBootstrap['selected_migrations']
	);
	$t->same(
		['005_contract', '006_contract_floor'],
		$multipleFreshBootstrap['deferred_migrations']
	);
	$multipleContractState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>$entry['phase']==='rolling_contract' ? 'pending' : 'applied',
			],
			$multipleContracts->entries()
		),
	];
	$multipleRollingState=[
		'drift_count'=>0,
		'migrations'=>array_map(
			static fn(array $entry): array=>[
				'id'=>$entry['id'],
				'status'=>$entry['phase']==='bootstrap' ? 'applied' : 'pending',
			],
			$multipleContracts->entries()
		),
	];
	$multipleRollingEvidence=$runner->deploymentEvidence(
		$multipleContracts,
		$multipleRollingState,
		'rolling'
	);
	$t->same(
		['003_expand', '004_irreversible'],
		$multipleRollingEvidence['selected_migrations']
	);
	$t->same(
		['005_contract', '006_contract_floor'],
		$multipleRollingEvidence['deferred_migrations']
	);
	$multipleUnselectedEvidence=$runner->deploymentEvidence(
		$multipleContracts,
		$multipleContractState,
		null
	);
	$t->same([], $multipleUnselectedEvidence['selected_migrations']);
	$t->same(
		['005_contract', '006_contract_floor'],
		$multipleUnselectedEvidence['deferred_migrations']
	);
	$t->contains(
		'pending_contract_requires_compatibility_finalization:005_contract',
		implode(',', $multipleUnselectedEvidence['errors'])
	);
	$multipleContractEvidence=$runner->deploymentEvidence(
		$multipleContracts,
		$multipleContractState,
		'maintenance',
		'2.0.1-alpha+fleet.1'
	);
	$t->same(true, $multipleContractEvidence['eligible']);
	$t->same(
		'2.0.1-alpha',
		$multipleContractEvidence['required_minimum_active_release']
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'deployment')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner projects legacy journals and guards rollback tails', static function(Context $t): void {
	$entries=[
		[
			'id'=>'001_base',
			'up'=>['public_path'=>'001_base.sql', 'sha256'=>str_repeat('a', 64)],
			'down'=>null,
			'irreversible_reason'=>'baseline',
		],
		[
			'id'=>'002_expand',
			'up'=>['public_path'=>'002_expand.up.sql', 'sha256'=>str_repeat('b', 64)],
			'down'=>['safety'=>'lossless'],
		],
		[
			'id'=>'003_contract',
			'up'=>['public_path'=>'003_contract.up.sql', 'sha256'=>str_repeat('c', 64)],
			'down'=>['safety'=>'data_loss'],
		],
	];
	$projection=PostgreSqlMigrationRunner::projectJournalRows([
		'001_base.sql'=>['sha256'=>str_repeat('a', 64), 'applied_at'=>'first'],
		'001_base'=>['sha256'=>str_repeat('a', 64), 'applied_at'=>'duplicate'],
		'removed'=>['sha256'=>str_repeat('d', 64), 'applied_at'=>'old'],
	], $entries);
	$t->same('001_base.sql', $projection['applied']['001_base']['journal_name']);
	$t->same(
		'001_base',
		$projection['unmanifested']['001_base']['duplicate_alias_for']
	);
	$t->same('old', $projection['unmanifested']['removed']['applied_at']);

	$all=['001_base'=>'applied', '002_expand'=>'applied', '003_contract'=>'applied'];
	$tail=PostgreSqlMigrationRunner::rollbackTail($entries, $all, '001_base');
	$t->same(['003_contract', '002_expand'], array_column($tail, 'id'));
	$t->same(
		[
			['id'=>'003_contract', 'safety'=>'data_loss'],
			['id'=>'002_expand', 'safety'=>'lossless'],
		],
		PostgreSqlMigrationRunner::assertRollbackSafety($tail, true)
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::assertRollbackSafety($tail, false),
		InvalidArgumentException::class,
		'--accept-data-loss'
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::assertRollbackSafety([$entries[0]], true),
		RuntimeException::class,
		'irreversible'
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollbackTail($entries, $all, 'bad'),
		InvalidArgumentException::class,
		'exact stable migration id'
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollbackTail($entries, $all, '009_missing'),
		InvalidArgumentException::class,
		'not present'
	);
	$pendingTarget=$all;
	$pendingTarget['001_base']='pending';
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollbackTail($entries, $pendingTarget, '001_base'),
		RuntimeException::class,
		'must be the applied head'
	);
	$gap=$all;
	$gap['002_expand']='pending';
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::rollbackTail($entries, $gap, '001_base'),
		RuntimeException::class,
		'contiguous applied migration tail'
	);

	PostgreSqlMigrationRunner::assertLosslessDownRows(
		['items'=>['row_count'=>'0']],
		[],
		'002_expand'
	);
	$t->throws(
		static fn()=>PostgreSqlMigrationRunner::assertLosslessDownRows(
			['items'=>['row_count'=>'2']],
			['items'=>['row_count'=>'1']],
			'002_expand'
		),
		RuntimeException::class,
		'removes application rows'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rollback')->group('framework-coverage');

test('PostgreSQL migration runner validates release identity before database mutation', static function(Context $t): void {
	[$database]=dp_postgresql_migration_manifest_fixture($t, 'runner-release-identity');
	$profile=dp_postgresql_migration_profile();
	$manifest=PostgreSqlMigrationManifest::load($database, $profile);
	$pdo=$t->scriptedPdo('pgsql');
	$runner=new PostgreSqlMigrationRunner($pdo, $profile);
	$t->throws(
		static fn()=>$runner->apply($manifest, 'replace'),
		InvalidArgumentException::class,
		'must be bootstrap, rolling, or maintenance'
	);
	$t->throws(
		static fn()=>$runner->apply(
			$manifest,
			'rolling',
			false,
			null,
			'2.0.0'
		),
		InvalidArgumentException::class,
		'only for maintenance'
	);
	$t->throws(
		static fn()=>$runner->apply(
			$manifest,
			'maintenance',
			false,
			null,
			'v2'
		),
		InvalidArgumentException::class,
		'exact semantic version'
	);
	foreach([
		['release_version'=>'1.0.0'],
		['release_version'=>'v1', 'release_sha256'=>str_repeat('a', 64)],
		['release_version'=>'1.0.0', 'release_sha256'=>'bad'],
		['release_version'=>null, 'release_sha256'=>str_repeat('a', 64)],
	] as $identity){
		$t->throws(
			static fn()=>$runner->apply($manifest, 'bootstrap', false, $identity),
			InvalidArgumentException::class
		);
	}
	$t->same([], $pdo->operations());
})->tag('sql', 'migration', 'postgresql', 'runner', 'identity')->group('framework-coverage');

test('PostgreSQL migration runner reports ordered journal status and immutable drift', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest(
		$t,
		'runner-status',
		false,
		true
	);
	$entries=$manifest->entries();
	$appliedRows=[];
	foreach($entries as $entry){
		$appliedRows[]=[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		];
	}
	$pdo=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_status($pdo, $appliedRows);
	$status=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->status($manifest);
	$t->same(true, $status['journal_exists']);
	$t->same(true, $status['event_journal_exists']);
	$t->same(6, $status['applied_count']);
	$t->same('006_contract_floor', $status['applied_head']);
	$t->same(0, $status['pending_count']);
	$t->same(0, $status['drift_count']);
	$t->same('2.0.1-alpha', $status['minimum_compatible_release']);
	$t->same(['applied'], array_values(array_unique(array_column($status['migrations'], 'status'))));

	$noisyPdo=$t->scriptedPdo('pgsql')
		->queueScalar(1)
		->queueRows(['not-a-row', $appliedRows[0]])
		->queueScalar(1);
	$noisy=(new PostgreSqlMigrationRunner(
		$noisyPdo,
		dp_postgresql_migration_profile()
	))->status($manifest);
	$t->same(1, $noisy['applied_count']);
	$t->same(5, $noisy['pending_count']);

	$driftRows=[
		$appliedRows[0],
		$appliedRows[2],
		[
			'migration_name'=>'004_irreversible',
			'checksum_sha256'=>str_repeat('0', 64),
			'applied_at'=>'2026-07-23 12:01:00+00',
		],
		[
			'migration_name'=>'999_removed',
			'checksum_sha256'=>str_repeat('f', 64),
			'applied_at'=>'2026-07-23 12:02:00+00',
		],
	];
	$driftPdo=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_status($driftPdo, $driftRows, true, false);
	$drift=(new PostgreSqlMigrationRunner(
		$driftPdo,
		dp_postgresql_migration_profile()
	))->status($manifest);
	$t->same(3, $drift['drift_count']);
	$t->same(3, $drift['pending_count']);
	$t->same(2, $drift['pending_contract_count']);
	$t->same(
		[
			'applied',
			'pending',
			'history_gap',
			'checksum_drift',
			'pending',
			'pending',
			'unmanifested_applied',
		],
		array_column($drift['migrations'], 'status')
	);

	$pendingPdo=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_status($pendingPdo, [], false, false);
	$pending=(new PostgreSqlMigrationRunner(
		$pendingPdo,
		dp_postgresql_migration_profile()
	))->status($manifest);
	$t->same(false, $pending['journal_exists']);
	$t->same(6, $pending['pending_count']);
	$t->same(0, $pending['drift_count']);

	$contractEntries=$t->nonPublic(new PostgreSqlMigrationRunner(
		$t->scriptedPdo('pgsql'),
		dp_postgresql_migration_profile()
	))->invoke('journalNativeSchemaEntries', [[
		'name'=>'001_legacy',
		'phase'=>'bootstrap',
		'sql'=>'CREATE TABLE fixture.legacy (id BIGSERIAL PRIMARY KEY);',
	], [
		'name'=>'002_expand',
		'phase'=>'rolling_expand',
		'sql'=>'ALTER TABLE fixture.base ADD COLUMN note TEXT;',
	]]);
	$t->same([[
		'name'=>'002_expand',
		'sql'=>'ALTER TABLE fixture.base ADD COLUMN note TEXT;',
	]], $contractEntries);
})->tag('sql', 'migration', 'postgresql', 'runner', 'status')->group('framework-coverage');

test('PostgreSQL migration runner normalizes legacy journals and fails closed on PDO protocol errors', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-aliases', true);
	$profile=dp_postgresql_migration_profile();

	$update=new ScriptedPdoStatement();
	$aliasPdo=$t->scriptedPdo('pgsql')
		->queueScalar(1)
		->queueRows([
			'not-a-row',
			['migration_name'=>''],
			['migration_name'=>'001_base.sql'],
		])
		->queueStatement($update);
	$normalized=$t->nonPublic(
		new PostgreSqlMigrationRunner($aliasPdo, $profile)
	)->invoke(
		'normalizeJournalAliases',
		$manifest
	);
	$t->same(
		[['from'=>'001_base.sql', 'to'=>'001_base']],
		$normalized
	);
	$t->same(['001_base', '001_base.sql'], $update->executions()[0] ?? null);

	$duplicatePdo=$t->scriptedPdo('pgsql')
		->queueScalar(1)
		->queueRows([
			['migration_name'=>'001_base'],
			['migration_name'=>'001_base.sql'],
		])
		->queueStatement(new ScriptedPdoStatement());
	$t->throws(
		static fn()=>$t->nonPublic(
			new PostgreSqlMigrationRunner($duplicatePdo, $profile)
		)->invoke(
			'normalizeJournalAliases',
			$manifest
		),
		RuntimeException::class,
		'both stable and legacy identities'
	);

	foreach([
		['query', $t->scriptedPdo('pgsql')->queuePrepareMiss(), ['SELECT 1'], 'query failed'],
		['prepare', $t->scriptedPdo('pgsql')->queuePrepareMiss(), ['SELECT 1'], 'prepare'],
		[
			'executeStatement',
			$t->scriptedPdo('pgsql'),
			[
				(new ScriptedPdoStatement())->returnExecuteResult(false),
				[],
				'statement failed',
			],
			'statement failed',
		],
		[
			'executeSql',
			$t->scriptedPdo('pgsql')->queueExecResult(false),
			['SELECT 1', 'SQL failed'],
			'SQL failed',
		],
		[
			'commit',
			$t->scriptedPdo('pgsql')->returnCommitResult(false),
			[],
			'could not commit',
		],
		[
			'rollbackTransaction',
			$t->scriptedPdo('pgsql')->returnRollbackResult(false),
			[],
			'could not roll back',
		],
	] as [$methodName,$pdo,$arguments,$message]){
		$runner=new PostgreSqlMigrationRunner($pdo, $profile);
		$t->throws(
			static fn()=>$t->nonPublic($runner)->invoke($methodName, ...$arguments),
			RuntimeException::class,
			$message
		);
	}

	$t->throws(
		static fn()=>$t->nonPublic(
			new PostgreSqlMigrationRunner($t->scriptedPdo('pgsql'), $profile)
		)->invoke(
			'recordEvent',
			'bad-operation',
			$manifest->entries()[0],
			'sideways',
			['release_version'=>null, 'release_sha256'=>null]
		),
		InvalidArgumentException::class,
		'event identity'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'pdo', 'aliases')->group('framework-coverage');

test('PostgreSQL migration runner applies a bootstrap transaction and records release evidence', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-apply');
	$entries=$manifest->entries();
	$pdo=$t->scriptedPdo('pgsql');
	$lock=new ScriptedPdoStatement([], true);
	$journalInsert=new ScriptedPdoStatement();
	$eventOne=new ScriptedPdoStatement();
	$eventTwo=new ScriptedPdoStatement();
	$pdo
		->queueStatement($lock)
		->queueScalar(0)
		->queueScalar(0)
		->queueScalar(0)
		->queueStatement($journalInsert)
		->queueStatement($eventOne)
		->queueStatement($eventTwo)
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows(array_map(
			static fn(array $entry): array=>[
				'migration_name'=>$entry['id'],
				'checksum_sha256'=>$entry['up']['sha256'],
				'applied_at'=>'2026-07-23 12:00:00+00',
			],
			array_slice($entries, 0, 4)
		))
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement([], true));
	$result=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->apply($manifest, 'bootstrap', false, [
		'release_version'=>'2.0.0',
		'release_sha256'=>str_repeat('a', 64),
	]);
	$t->same('committed', $result['transaction']);
	$t->same(
		['001_base', '002_cutoff', '003_expand', '004_irreversible'],
		$result['migrations']
	);
	$t->same(['005_contract'], $result['pending_validation']['deferred_migrations']);
	$t->same('2.0.0', $result['release_version']);
	$t->same(str_repeat('a', 64), $result['release_sha256']);
	$t->same(true, $result['pending_validation']['eligible']);
	$t->same('per_migration', $result['transaction_scope']);
	$t->same('pg_advisory_lock:fixture.postgresql_migrations', $result['lock']);
	$t->same(5, array_count_values($pdo->operationNames())['commit'] ?? 0);
	$t->count(4, $journalInsert->executions());
	$t->same(['001_base', $entries[0]['up']['sha256']], $journalInsert->executions()[0]);
	$t->same('up', $eventOne->executions()[0][2] ?? null);
	$t->same('2.0.0', $eventOne->executions()[0][5] ?? null);
	$t->contains('CREATE TABLE IF NOT EXISTS "fixture"."schema_migrations"', implode(
		"\n",
		array_values(array_filter(array_column($pdo->operations(), 'sql')))
	));
})->tag('sql', 'migration', 'postgresql', 'runner', 'apply', 'transaction')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner applies maintenance suffixes under caller-verified compatibility', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-maintenance-apply');
	$entries=$manifest->entries();
	$beforeRows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		array_slice($entries, 0, 2)
	);
	$afterRows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:01+00',
		],
		$entries
	);
	$journalInsert=new ScriptedPdoStatement();
	$pdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(1)
		->queueRows([
			['migration_name'=>'001_base'],
			['migration_name'=>'002_cutoff'],
		])
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($beforeRows)
		->queueScalar(1)
		->queueStatement($journalInsert)
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($afterRows)
		->queueScalar(1);
	$result=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->apply(
		$manifest,
		'maintenance',
		false,
		[
			'release_version'=>'2.1.0',
			'release_sha256'=>str_repeat('b', 64),
		],
		'2.1.0'
	);
	$t->same('committed', $result['transaction']);
	$t->same(['003_expand', '004_irreversible', '005_contract'], $result['migrations']);
	$t->same('maintenance', $result['deployment_mode']);
	$t->same('2.0.0', $result['required_minimum_active_release']);
	$t->same('2.1.0', $result['verified_minimum_active_release']);
	$t->same(true, $result['pending_validation']['compatibility_floor_satisfied']);
	$t->count(3, $journalInsert->executions());
	$t->same('commit', $pdo->operationNames()[array_key_last($pdo->operationNames())]);
})->tag('sql', 'migration', 'postgresql', 'runner', 'apply', 'maintenance')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner applies rolling prefixes and commits no-op deployments', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest(
		$t,
		'runner-rolling-apply',
		false,
		false,
		false,
		true
	);
	$entries=$manifest->entries();
	$beforeRows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		array_slice($entries, 0, 2)
	);
	$afterRows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:01+00',
		],
		array_slice($entries, 0, 4)
	);
	$journalInsert=new ScriptedPdoStatement();
	$firstEvent=new ScriptedPdoStatement();
	$secondEvent=new ScriptedPdoStatement();
	$rollingPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(1)
		->queueRows([
			['migration_name'=>'001_base'],
			['migration_name'=>'002_cutoff'],
		])
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($beforeRows)
		->queueScalar(1)
		->queueStatement($journalInsert)
		->queueStatement($firstEvent)
		->queueStatement($secondEvent)
		->queueScalar(1)
		->queueRows($afterRows)
		->queueScalar(1);
	$rolling=(new PostgreSqlMigrationRunner(
		$rollingPdo,
		dp_postgresql_migration_profile()
	))->apply(
		$manifest,
		'rolling',
		false,
		[
			'release_version'=>'2.0.0',
			'release_sha256'=>str_repeat('c', 64),
	]
	);
	$t->same('committed', $rolling['transaction']);
	$t->same(['003_expand', '004_irreversible'], $rolling['migrations']);
	$t->same(['005_contract'], $rolling['pending_validation']['deferred_migrations']);
	$t->same('rolling', $rolling['deployment_mode']);
	$t->count(2, $journalInsert->executions());
	$t->same('003_expand', $firstEvent->executions()[0][1] ?? null);
	$t->same('004_irreversible', $secondEvent->executions()[0][1] ?? null);

	$allRows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:01:00+00',
		],
		$entries
	);
	$unusedInsert=new ScriptedPdoStatement();
	$noOpPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(1)
		->queueRows(array_map(
			static fn(array $entry): array=>['migration_name'=>$entry['id']],
			$entries
		))
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($allRows)
		->queueScalar(1)
		->queueStatement($unusedInsert)
		->queueScalar(1)
		->queueRows($allRows)
		->queueScalar(1);
	$noOp=(new PostgreSqlMigrationRunner(
		$noOpPdo,
		dp_postgresql_migration_profile()
	))->apply($manifest, 'rolling');
	$t->same('committed', $noOp['transaction']);
	$t->same([], $noOp['migrations']);
	$t->same([], $noOp['pending_validation']['pending_migrations']);
	$t->same([], $unusedInsert->executions());
	$t->same('commit', $noOpPdo->operationNames()[array_key_last($noOpPdo->operationNames())]);
})->tag('sql', 'migration', 'postgresql', 'runner', 'apply', 'rolling')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner rolls back dry runs and rejects locked-state drift', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-apply-branches', true);
	$entries=$manifest->entries();
	$rows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		$entries
	);

	$dryPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(0)
		->queueScalar(0)
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($rows)
		->queueScalar(1);
	$dry=(new PostgreSqlMigrationRunner(
		$dryPdo,
		dp_postgresql_migration_profile()
	))->apply($manifest, 'bootstrap', true);
	$t->same('rolled_back', $dry['transaction']);
	$t->same('rollback', $dryPdo->operationNames()[array_key_last($dryPdo->operationNames())]);

	$beforeDriftPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(1)
		->queueRows([[
			'migration_name'=>$entries[0]['id'],
			'checksum_sha256'=>str_repeat('0', 64),
			'applied_at'=>'2026-07-23 12:00:00+00',
		]])
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement([], true));
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$beforeDriftPdo,
			dp_postgresql_migration_profile()
		))->apply($manifest, 'bootstrap'),
		RuntimeException::class,
		'changed or drifted'
	);
	$t->same(
		1,
		array_count_values($beforeDriftPdo->operationNames())['rollback'] ?? 0
	);

	$afterDriftPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(0)
		->queueScalar(0)
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows([[
			'migration_name'=>$entries[0]['id'],
			'checksum_sha256'=>str_repeat('0', 64),
			'applied_at'=>'2026-07-23 12:00:00+00',
		]])
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement([], true));
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$afterDriftPdo,
			dp_postgresql_migration_profile()
		))->apply($manifest, 'bootstrap'),
		RuntimeException::class,
		'did not produce the schema'
	);

	$skipPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(1)
		->queueRows([$rows[0]])
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement())
		->queueStatement(new ScriptedPdoStatement())
		->queueScalar(1)
		->queueRows($rows)
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement([], true));
	$skip=(new PostgreSqlMigrationRunner(
		$skipPdo,
		dp_postgresql_migration_profile()
	))->apply($manifest, 'bootstrap');
	$t->same(['002_cutoff'], $skip['migrations']);

	$fullManifest=dp_postgresql_migration_no_schema_manifest(
		$t,
		'runner-ineligible-apply'
	);
	$fullEntries=$fullManifest->entries();
	$ineligiblePdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(1)
		->queueRows(array_map(
			static fn(array $entry): array=>[
				'migration_name'=>$entry['id'],
				'checksum_sha256'=>$entry['up']['sha256'],
				'applied_at'=>'2026-07-23 12:00:00+00',
			],
			array_slice($fullEntries, 0, 2)
		))
		->queueScalar(1)
		->queueStatement(new ScriptedPdoStatement([], true));
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$ineligiblePdo,
			dp_postgresql_migration_profile()
		))->apply($fullManifest, 'bootstrap'),
		RuntimeException::class,
		'not eligible'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'apply', 'branches')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner commits empty rollback heads and rolls back unsafe tails', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-rollback', true);
	$entries=$manifest->entries();
	$rows=[
		[
			'migration_name'=>$entries[0]['id'],
			'checksum_sha256'=>$entries[0]['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		[
			'migration_name'=>$entries[1]['id'],
			'checksum_sha256'=>$entries[1]['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:01+00',
		],
	];
	$pdo=$t->scriptedPdo('pgsql');
	$pdo
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0);
	dp_postgresql_migration_queue_status($pdo, $rows);
	$pdo->queueStatement(new ScriptedPdoStatement());
	dp_postgresql_migration_queue_status($pdo, $rows);
	$result=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->rollback($manifest, '002_cutoff');
	$t->same('committed', $result['transaction']);
	$t->same([], $result['migrations']);
	$t->same('002_cutoff', $result['rollback_to']);
	$t->same([], $result['rollback_safety']);
	$t->same('commit', $pdo->operationNames()[array_key_last($pdo->operationNames())]);

	$unsafePdo=$t->scriptedPdo('pgsql');
	$unsafePdo
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0);
	dp_postgresql_migration_queue_status($unsafePdo, $rows);
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$unsafePdo,
			dp_postgresql_migration_profile()
		))->rollback($manifest, '001_base'),
		RuntimeException::class,
		'irreversible migration'
	);
	$t->same(
		'rollback',
		$unsafePdo->operationNames()[array_key_last($unsafePdo->operationNames())]
	);

	$beginFailure=$t->scriptedPdo('pgsql')->returnBeginResult(false);
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$beginFailure,
			dp_postgresql_migration_profile()
		))->rollback($manifest, '002_cutoff'),
		RuntimeException::class,
		'could not begin'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rollback', 'transaction')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner certifies and records an exact reversible rollback tail', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-reversible-rollback');
	$entries=$manifest->entries();
	$rows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		$entries
	);
	$delete=(new ScriptedPdoStatement())->returnRowCount(1);
	$event=new ScriptedPdoStatement();
	$pdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0);
	dp_postgresql_migration_queue_status($pdo, $rows);
	$pdo->queueStatement($delete);
	dp_postgresql_migration_queue_structure($pdo, 'before');
	dp_postgresql_migration_queue_structure($pdo, 'down');
	dp_postgresql_migration_queue_structure($pdo, 'before');
	dp_postgresql_migration_queue_structure($pdo, 'down');
	$pdo->queueStatement($event);
	dp_postgresql_migration_queue_status($pdo, array_slice($rows, 0, 4));

	$result=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->rollback(
		$manifest,
		'004_irreversible',
		true,
		[
			'release_version'=>'2.0.0',
			'release_sha256'=>str_repeat('d', 64),
		]
	);
	$t->same('committed', $result['transaction']);
	$t->same(['005_contract'], $result['migrations']);
	$t->same(
		[['id'=>'005_contract', 'safety'=>'data_loss']],
		$result['rollback_safety']
	);
	$t->same(true, $result['data_loss_accepted']);
	$t->same(str_repeat('d', 64), $result['release_sha256']);
	$t->same(
		['005_contract', $entries[4]['up']['sha256']],
		$delete->executions()[0] ?? null
	);
	$t->same('down', $event->executions()[0][2] ?? null);
	$t->same($entries[4]['down']['sha256'], $event->executions()[0][4] ?? null);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rollback', 'certification')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner rolls back multi-entry tails newest first', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest(
		$t,
		'runner-multi-rollback',
		false,
		false,
		true
	);
	$entries=$manifest->entries();
	$rows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		$entries
	);
	$delete=(new ScriptedPdoStatement())->returnRowCount(1);
	$events=[
		new ScriptedPdoStatement(),
		new ScriptedPdoStatement(),
		new ScriptedPdoStatement(),
	];
	$pdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(1)
		->queueRows(array_map(
			static fn(array $entry): array=>['migration_name'=>$entry['id']],
			$entries
		))
		->queueStatement(new ScriptedPdoStatement());
	dp_postgresql_migration_queue_status($pdo, $rows);
	$pdo->queueStatement($delete);
	foreach($events as $offset=>$event){
		$identity='tail-'.$offset;
		dp_postgresql_migration_queue_structure($pdo, $identity.'-before');
		dp_postgresql_migration_queue_structure($pdo, $identity.'-down');
		dp_postgresql_migration_queue_structure($pdo, $identity.'-before');
		dp_postgresql_migration_queue_structure($pdo, $identity.'-down');
		$pdo->queueStatement($event);
	}
	dp_postgresql_migration_queue_status($pdo, array_slice($rows, 0, 2));

	$result=(new PostgreSqlMigrationRunner(
		$pdo,
		dp_postgresql_migration_profile()
	))->rollback($manifest, '002_cutoff', true);
	$t->same('committed', $result['transaction']);
	$t->same(
		['005_contract', '004_irreversible', '003_expand'],
		$result['migrations']
	);
	$t->same(
		['005_contract', '004_irreversible', '003_expand'],
		array_column($delete->executions(), 0)
	);
	$t->same(
		['005_contract', '004_irreversible', '003_expand'],
		array_map(
			static fn(ScriptedPdoStatement $event): mixed=>
				$event->executions()[0][1] ?? null,
			$events
		)
	);
	$t->same(
		['data_loss', 'data_loss', 'data_loss'],
		array_column($result['rollback_safety'], 'safety')
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rollback', 'ordering')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL migration runner rejects invalid drifted and inexact rollback outcomes', static function(Context $t): void {
	$manifest=dp_postgresql_migration_no_schema_manifest($t, 'runner-rollback-failures');
	$entries=$manifest->entries();
	$rows=array_map(
		static fn(array $entry): array=>[
			'migration_name'=>$entry['id'],
			'checksum_sha256'=>$entry['up']['sha256'],
			'applied_at'=>'2026-07-23 12:00:00+00',
		],
		$entries
	);
	$profile=dp_postgresql_migration_profile();
	$invalidPdo=$t->scriptedPdo('pgsql');
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$invalidPdo,
			$profile
		))->rollback($manifest, '999_missing'),
		InvalidArgumentException::class,
		'not present'
	);
	$t->same([], $invalidPdo->operations());

	$driftPdo=$t->scriptedPdo('pgsql')
		->queueStatement(new ScriptedPdoStatement([], true))
		->queueScalar(0)
		->queueScalar(1)
		->queueRows([[
			'migration_name'=>$entries[0]['id'],
			'checksum_sha256'=>str_repeat('0', 64),
			'applied_at'=>'2026-07-23 12:00:00+00',
		]])
		->queueScalar(1);
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$driftPdo,
			$profile
		))->rollback($manifest, '001_base'),
		RuntimeException::class,
		'changed or drifted'
	);

	$rollbackPdo=static function(
		Context $t,
		array $rows,
		array $afterRows,
		int $deletedRows
	): ScriptedPdo {
		$pdo=$t->scriptedPdo('pgsql')
			->queueStatement(new ScriptedPdoStatement([], true))
			->queueScalar(0);
		dp_postgresql_migration_queue_status($pdo, $rows);
		$pdo->queueStatement(
			(new ScriptedPdoStatement())->returnRowCount($deletedRows)
		);
		dp_postgresql_migration_queue_structure($pdo, 'before');
		dp_postgresql_migration_queue_structure($pdo, 'down');
		dp_postgresql_migration_queue_structure($pdo, 'before');
		dp_postgresql_migration_queue_structure($pdo, 'down');
		$pdo->queueStatement(new ScriptedPdoStatement());
		dp_postgresql_migration_queue_status($pdo, $afterRows);
		return $pdo;
	};

	$deletePdo=$rollbackPdo($t, $rows, array_slice($rows, 0, 4), 0);
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$deletePdo,
			$profile
		))->rollback($manifest, '004_irreversible', true),
		RuntimeException::class,
		'could not remove the exact current journal row'
	);

	$wrongHeadPdo=$rollbackPdo($t, $rows, array_slice($rows, 0, 3), 1);
	$t->throws(
		static fn()=>(new PostgreSqlMigrationRunner(
			$wrongHeadPdo,
			$profile
		))->rollback($manifest, '004_irreversible', true),
		RuntimeException::class,
		'did not produce the exact requested migration head'
	);
})->tag('sql', 'migration', 'postgresql', 'runner', 'rollback', 'fail-closed')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL schema inspection derives stable table constraint and index contracts', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$access=$t->nonPublic($inspector);
	$sql=<<<'SQL'
CREATE TABLE fixture.parent (
	parent_id TEXT PRIMARY KEY
);
CREATE TABLE fixture.items (
	serial_id BIGSERIAL UNIQUE,
	item_id TEXT,
	parent_id TEXT,
	state TEXT NOT NULL,
	CONSTRAINT items_pk PRIMARY KEY (item_id),
	CONSTRAINT items_state_check CHECK (state IN ('ready', 'done'))
);
CREATE TABLE other.ignored (
	id TEXT PRIMARY KEY
);
ALTER TABLE fixture.items
	ADD CONSTRAINT items_parent_fkey
	FOREIGN KEY (parent_id) REFERENCES fixture.parent (parent_id) ON DELETE CASCADE;
CREATE INDEX fixture.items_parent_idx
	ON fixture.items (parent_id DESC NULLS LAST)
	WHERE parent_id IS NOT NULL;
ALTER TABLE fixture.items ADD COLUMN IF NOT EXISTS note VARCHAR(120) NULL;
ALTER TABLE fixture.items ADD COLUMN IF NOT EXISTS sequence_id SERIAL;
SQL;
	$expected=$inspector->expectedSchema([['name'=>'001_fixture', 'sql'=>$sql]]);
	$t->same(
		[
			'serial_id'=>['type'=>'bigint', 'nullable'=>false],
			'item_id'=>['type'=>'text', 'nullable'=>false],
			'parent_id'=>['type'=>'text', 'nullable'=>true],
			'state'=>['type'=>'text', 'nullable'=>false],
			'note'=>['type'=>'character varying(120)', 'nullable'=>true],
			'sequence_id'=>['type'=>'integer', 'nullable'=>false],
		],
		$expected['tables']['fixture.items']['columns']
	);
	$t->same(['item_id'], $expected['tables']['fixture.items']['primary_key']);
	$t->isFalse(isset($expected['tables']['other.ignored']));
	$t->same('cascade', $expected['foreign_keys']['fixture.items.items_parent_fkey']['on_delete']);
	$t->same(
		"state in('ready', 'done')",
		$expected['checks']['fixture.items.items_state_check']['expression']
	);
	$t->same(
		['parent_id desc nulls last'],
		$expected['indexes']['fixture.items_parent_idx']['keys']
	);
	$t->same(
		'parent_id is not null',
		$expected['indexes']['fixture.items_parent_idx']['predicate']
	);
	$t->isTrue(
		$access->invoke(
			'expressionsEquivalent',
			"state in('ready', 'done')",
			PostgreSqlSchemaInspector::normalizeCheckExpression(
				"(state = ANY (ARRAY['ready'::text, 'done'::text]))"
			)
		),
		'Catalog-only membership casts must compare equal without erasing the source contract.'
	);
	foreach([
		[
			'length(btrim(source_id)) BETWEEN 1 AND 500',
			'length(btrim(source_id)) >= 1 AND length(btrim(source_id)) <= 500',
		],
		[
			"(scope_type = 'platform' AND tenant_id IS NULL) OR " .
				"(scope_type = 'tenant' AND tenant_id IS NOT NULL)",
			"scope_type = 'platform' AND tenant_id IS NULL OR " .
				"scope_type = 'tenant' AND tenant_id IS NOT NULL",
		],
		[
			"(actor_type IN ('service', 'system') AND length(btrim(actor_reference)) BETWEEN 1 AND 200)",
			"(actor_type = ANY (ARRAY['service'::text, 'system'::text])) " .
				"AND length(btrim(actor_reference)) >= 1 AND length(btrim(actor_reference)) <= 200",
		],
		[
			"mode NOT IN ('through_change', 'scope_snapshot')",
			"mode <> ALL (ARRAY['through_change'::text, 'scope_snapshot'::text])",
		],
		[
			"jsonb_typeof(target) IN ('array', 'object')",
			"jsonb_typeof(target) = ANY (ARRAY['array'::text, 'object'::text])",
		],
		[
			"lower(target->>'state') NOT IN ('retired', 'deleted')",
			"lower(target ->> 'state'::text) <> ALL " .
				"(ARRAY['retired'::text, 'deleted'::text])",
		],
	] as [$migrationExpression, $catalogExpression]){
		$t->isTrue(
			$access->invoke(
				'expressionsEquivalent',
				PostgreSqlSchemaInspector::normalizeCheckExpression($migrationExpression),
				PostgreSqlSchemaInspector::normalizeCheckExpression($catalogExpression)
			),
			$migrationExpression
		);
	}
	$t->same(
		"enabled and (state='ready' or state='blocked')",
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"enabled AND (state = 'ready' OR state = 'blocked')"
		),
		'Boolean normalization must preserve required AND/OR grouping.'
	);
	foreach([
		['x BETWEEN 1 AND 500', 'x >= 1 AND x < 500'],
		['a AND (b OR c)', '(a AND b) OR c'],
		["status IN ('ready', 'blocked')", "status IN ('ready', 'failed')"],
		["jsonb_typeof(target) IN ('array', 'object')", "jsonb_typeof(target) IN ('array', 'string')"],
		["value::text = '1'", "value = '1'"],
	] as [$left, $right]){
		$t->notSame(
			PostgreSqlSchemaInspector::normalizeCheckExpression($left),
			PostgreSqlSchemaInspector::normalizeCheckExpression($right),
			$left.' must remain distinguishable from '.$right
		);
	}
	$t->same(
		PostgreSqlSchemaInspector::normalizeSqlExpression('value NOT BETWEEN 1 AND 2'),
		PostgreSqlSchemaInspector::normalizeCheckExpression('value NOT BETWEEN 1 AND 2')
	);
	$t->same(
		PostgreSqlSchemaInspector::normalizeSqlExpression('value BETWEEN SYMMETRIC 1 AND 2'),
		PostgreSqlSchemaInspector::normalizeCheckExpression('value BETWEEN SYMMETRIC 1 AND 2')
	);
	$t->same(
		"lower(name)||'-'||code",
		PostgreSqlSchemaInspector::normalizeSqlExpression(" LOWER ( name ) || '-' || code ")
	);
	foreach([
		'int'=>'integer',
		'int8'=>'bigint',
		'float8'=>'double precision',
		'bool'=>'boolean',
		'timestamp'=>'timestamp without time zone',
		'timestamptz'=>'timestamp with time zone',
		'time'=>'time without time zone',
		'timetz'=>'time with time zone',
		'varchar ( 20 )'=>'character varying(20)',
		'char( 2 )'=>'character(2)',
		'decimal (12, 4)'=>'numeric(12,4)',
	] as $input=>$output){
		$t->same($output, PostgreSqlSchemaInspector::normalizeType($input), $input);
	}
	$t->same(
		'serial',
		PostgreSqlSchemaInspector::normalizeType('serial'),
		'SERIAL expansion must remain migration-side so a catalog domain cannot be falsely equated.'
	);
})->tag('sql', 'migration', 'postgresql', 'schema')->group('framework-coverage');

test('PostgreSQL schema inspection tracks compound column alterations and catalog identifiers', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$longIndex='idx_serve_change_control_source_projection_receipts_resource_hold';
	$expected=$inspector->expectedSchema([[
		'name'=>'001_setup',
		'sql'=><<<SQL
CREATE TABLE fixture.targets (
	old_name INTEGER PRIMARY KEY,
	scope_key TEXT NOT NULL,
	tenant_id BIGINT,
	CONSTRAINT targets_positive_check CHECK (old_name >= 0),
	CONSTRAINT targets_scope_check CHECK (scope_key='tenant:'||tenant_id::text)
);
CREATE INDEX fixture.{$longIndex}
	ON fixture.targets(old_name)
	WHERE old_name IS NOT NULL;
SQL
	],[
		'name'=>'002_alter',
		'sql'=><<<'SQL'
ALTER TABLE fixture.targets
	ADD COLUMN first_id BIGINT,
	ADD COLUMN second_label TEXT NOT NULL;
ALTER TABLE fixture.targets
	ALTER COLUMN old_name TYPE BIGINT USING old_name::bigint;
ALTER TABLE fixture.targets
	RENAME COLUMN old_name TO renamed_id;
SQL
	]]);

	$columns=$expected['tables']['fixture.targets']['columns'];
	$t->isFalse(isset($columns['old_name']));
	$t->same(['type'=>'bigint', 'nullable'=>false], $columns['renamed_id']);
	$t->same(['type'=>'bigint', 'nullable'=>true], $columns['first_id']);
	$t->same(['type'=>'text', 'nullable'=>false], $columns['second_label']);
	$t->same(['renamed_id'], $expected['tables']['fixture.targets']['primary_key']);
	$t->same(
		'renamed_id>=0',
		$expected['checks']['fixture.targets.targets_positive_check']['expression']
	);
	$catalogIndex='fixture.'.substr($longIndex, 0, 63);
	$t->same(['renamed_id'], $expected['indexes'][$catalogIndex]['keys']);
	$t->same('renamed_id is not null', $expected['indexes'][$catalogIndex]['predicate']);
	$t->same(
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"scope_key='tenant:'||tenant_id::text"
		),
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"scope_key=('tenant:'||tenant_id::text)"
		)
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'alter')->group('framework-coverage');

test('PostgreSQL schema inspection canonicalizes grouped nullability and catalog indexes', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$sql=<<<'SQL'
CREATE TABLE fixture.contracts (
	id BIGINT PRIMARY KEY,
	optional_note TEXT,
	required_note TEXT NOT NULL,
	status TEXT,
	properties JSONB,
	deleted_at TIMESTAMPTZ
);
ALTER TABLE fixture.contracts
	ALTER COLUMN optional_note SET NOT NULL,
	ALTER COLUMN required_note DROP NOT NULL;
CREATE INDEX fixture.contracts_status_idx
	ON fixture.contracts(status)
	WHERE deleted_at IS NULL AND status IN ('open', 'closed');
CREATE INDEX fixture.contracts_seat_idx
	ON fixture.contracts(((properties->>'seat_number')::integer))
	WHERE status='open';
SQL;
	$expected=$inspector->expectedSchema([['name'=>'001_fixture', 'sql'=>$sql]]);
	$t->isFalse($expected['tables']['fixture.contracts']['columns']['optional_note']['nullable']);
	$t->isTrue($expected['tables']['fixture.contracts']['columns']['required_note']['nullable']);
	$t->same(
		"deleted_at is null and status in('open', 'closed')",
		$expected['indexes']['fixture.contracts_status_idx']['predicate']
	);
	$t->same(
		["(properties->>'seat_number')::integer"],
		$expected['indexes']['fixture.contracts_seat_idx']['keys']
	);

	$pdo=$t->scriptedPdo('pgsql')
		->queueRows([
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts', 'column_name'=>'id',
				'column_type'=>'bigint', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts',
				'column_name'=>'optional_note', 'column_type'=>'text', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts',
				'column_name'=>'required_note', 'column_type'=>'text', 'is_not_null'=>'f',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts', 'column_name'=>'status',
				'column_type'=>'text', 'is_not_null'=>'f',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts', 'column_name'=>'properties',
				'column_type'=>'jsonb', 'is_not_null'=>'f',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'contracts', 'column_name'=>'deleted_at',
				'column_type'=>'timestamp with time zone', 'is_not_null'=>'f',
			],
		])
		->queueRows([[
			'schema_name'=>'fixture', 'table_name'=>'contracts', 'column_name'=>'id',
		]])
		->queueRows([])
		->queueRows([])
		->queueRows([
			[
				'index_schema'=>'fixture',
				'index_name'=>'contracts_status_idx',
				'table_schema'=>'fixture',
				'table_name'=>'contracts',
				'is_unique'=>'f',
				'is_valid'=>'t',
				'is_ready'=>'t',
				'index_definition'=>
					"CREATE INDEX contracts_status_idx ON fixture.contracts USING btree (status) ".
					"WHERE (deleted_at IS NULL AND ".
					"(status = ANY (ARRAY['open'::text, 'closed'::text])))",
				'predicate'=>
					"deleted_at IS NULL AND ".
					"(status = ANY (ARRAY['open'::text, 'closed'::text]))",
			],
			[
				'index_schema'=>'fixture',
				'index_name'=>'contracts_seat_idx',
				'table_schema'=>'fixture',
				'table_name'=>'contracts',
				'is_unique'=>'f',
				'is_valid'=>'t',
				'is_ready'=>'t',
				'index_definition'=>
					"CREATE INDEX contracts_seat_idx ON fixture.contracts USING btree ".
					"(((properties ->> 'seat_number'::text))::integer) ".
					"WHERE (status = 'open'::text)",
				'predicate'=>"status = 'open'::text",
			],
		]);
	$t->same([], $inspector->schemaIssues($pdo, $expected));
})->tag('sql', 'migration', 'postgresql', 'schema', 'catalog')->group('framework-coverage');

test('PostgreSQL schema inspection compares live catalog evidence through ScriptedPdo', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$sql=<<<'SQL'
CREATE TABLE fixture.parent (parent_id TEXT PRIMARY KEY);
CREATE TABLE fixture.items (
	item_id TEXT PRIMARY KEY,
	parent_id TEXT,
	state TEXT NOT NULL,
	CONSTRAINT items_state_check CHECK (state IN ('ready', 'done'))
);
ALTER TABLE fixture.items ADD CONSTRAINT items_parent_fkey
	FOREIGN KEY (parent_id) REFERENCES fixture.parent (parent_id) ON DELETE CASCADE;
CREATE INDEX fixture.items_parent_idx ON fixture.items (parent_id) WHERE parent_id IS NOT NULL;
SQL;
	$expected=$inspector->expectedSchema([['name'=>'001_fixture', 'sql'=>$sql]]);
	$pdo=$t->scriptedPdo('pgsql')
		->queueRows([
			[
				'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'item_id',
				'column_type'=>'text', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'parent_id',
				'column_type'=>'text', 'is_not_null'=>'f',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'state',
				'column_type'=>'text', 'is_not_null'=>true,
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'parent', 'column_name'=>'parent_id',
				'column_type'=>'text', 'is_not_null'=>1,
			],
		])
		->queueRows([
			['schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'item_id'],
			['schema_name'=>'fixture', 'table_name'=>'parent', 'column_name'=>'parent_id'],
		])
		->queueRows([[
			'schema_name'=>'fixture',
			'table_name'=>'items',
			'constraint_name'=>'items_parent_fkey',
			'column_name'=>'parent_id',
			'referenced_schema_name'=>'fixture',
			'referenced_table_name'=>'parent',
			'referenced_column_name'=>'parent_id',
			'on_delete'=>'cascade',
			'is_valid'=>'true',
		]])
		->queueRows([[
			'schema_name'=>'fixture',
			'table_name'=>'items',
			'constraint_name'=>'items_state_check',
			'expression'=>"(state = ANY (ARRAY['ready'::text, 'done'::text]))",
			'is_valid'=>'1',
		]])
		->queueRows([[
			'index_schema'=>'fixture',
			'index_name'=>'items_parent_idx',
			'table_schema'=>'fixture',
			'table_name'=>'items',
			'is_unique'=>'f',
			'is_valid'=>'t',
			'is_ready'=>true,
			'index_definition'=>'CREATE INDEX items_parent_idx ON fixture.items USING btree (parent_id) WHERE parent_id IS NOT NULL',
			'predicate'=>'parent_id IS NOT NULL',
		]]);
	$t->same([], $inspector->schemaIssues($pdo, $expected));
	$t->count(5, $pdo->preparedSql());
	$t->contains('column_record.attnotnull AS is_not_null', $pdo->preparedSql()[0]);
	$t->contains("constraint_record.contype='c'", $pdo->preparedSql()[3]);
	$t->same([], $inspector->schemaIssues($t->scriptedPdo('pgsql'), []));

	$missing=$t->scriptedPdo('pgsql');
	for($query=0; $query<5; $query++){
		$missing->queueRows([]);
	}
	$t->same(
		['missing_table', 'missing_table'],
		array_column($inspector->schemaIssues($missing, $expected), 'kind')
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'pdo')->group('framework-coverage');

test('PostgreSQL schema fingerprints and lossless certification use inspectable PDO evidence', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$structural=$t->scriptedPdo('pgsql')
		->queueRows([['object_name'=>'items', 'member_name'=>'item_id']])
		->queueRows([['object_name'=>'items', 'member_name'=>'items_pk']])
		->queueRows([['object_name'=>'items', 'member_name'=>'items_pk']]);
	$fingerprint=$inspector->structuralFingerprint($structural);
	$t->same(64, strlen($fingerprint));
	$t->contains("'schema_migrations', 'schema_migration_events'", $structural->preparedSql()[0]);

	$data=dp_postgresql_migration_queue_data($t->scriptedPdo('pgsql'), '2', 'rows');
	$t->same([
		'items'=>[
			'row_count'=>'2',
			'hash_sum_a'=>'rows-a',
			'hash_sum_b'=>'rows-b',
		],
	], $inspector->dataFingerprint($data));
	$t->contains('"fixture"."items"', $data->preparedSql()[1]);

	PostgreSqlSchemaInspector::assertLosslessDownRows(
		['items'=>['row_count'=>'2']],
		['items'=>['row_count'=>'2']],
		'003_expand'
	);
	$t->throws(
		static fn()=>PostgreSqlSchemaInspector::assertLosslessDownRows(
			['items'=>['row_count'=>'2']],
			['items'=>['row_count'=>'1']],
			'003_expand'
		),
		RuntimeException::class,
		'removes application rows'
	);
	$t->throws(
		static fn()=>PostgreSqlSchemaInspector::assertLosslessDownRows(
			['items'=>['row_count'=>'1']],
			[],
			'003_expand'
		),
		RuntimeException::class,
		'removes application rows'
	);

	$certification=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($certification, 'before');
	dp_postgresql_migration_queue_data($certification);
	dp_postgresql_migration_queue_structure($certification, 'down');
	dp_postgresql_migration_queue_data($certification);
	dp_postgresql_migration_queue_structure($certification, 'before');
	dp_postgresql_migration_queue_data($certification);
	dp_postgresql_migration_queue_structure($certification, 'down');
	dp_postgresql_migration_queue_data($certification);
	$inspector->certifyDown($certification, [
		'id'=>'003_expand',
		'up'=>['sql'=>'UP SQL'],
		'down'=>['sql'=>'DOWN SQL', 'safety'=>'lossless'],
	]);
	$t->same(
		['DOWN SQL', 'UP SQL', 'DOWN SQL'],
		array_column(array_values(array_filter(
			$certification->operations(),
			static fn(array $operation): bool=>$operation['operation']==='exec'
		)), 'sql')
	);
	$t->throws(
		static fn()=>$inspector->certifyDown($t->scriptedPdo('pgsql'), [
			'id'=>'001_base',
			'up'=>['sql'=>'UP'],
			'down'=>null,
			'irreversible_reason'=>'baseline',
		]),
		RuntimeException::class,
		'is irreversible'
	);

	$dataLossEntry=[
		'id'=>'003_expand',
		'up'=>['sql'=>'UP'],
		'down'=>['sql'=>'DOWN', 'safety'=>'data_loss'],
	];
	$downExecutionFailure=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($downExecutionFailure, 'before');
	$downExecutionFailure->queueExecResult(false);
	$t->throws(
		static fn()=>$inspector->certifyDown($downExecutionFailure, $dataLossEntry),
		RuntimeException::class,
		'Migration down SQL execution failed'
	);

	$upExecutionFailure=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($upExecutionFailure, 'before');
	dp_postgresql_migration_queue_structure($upExecutionFailure, 'down');
	$upExecutionFailure->queueExecResult(0)->queueExecResult(false);
	$t->throws(
		static fn()=>$inspector->certifyDown($upExecutionFailure, $dataLossEntry),
		RuntimeException::class,
		'Migration up SQL execution failed during rollback certification'
	);

	$finalDownExecutionFailure=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($finalDownExecutionFailure, 'before');
	dp_postgresql_migration_queue_structure($finalDownExecutionFailure, 'down');
	dp_postgresql_migration_queue_structure($finalDownExecutionFailure, 'before');
	$finalDownExecutionFailure
		->queueExecResult(0)
		->queueExecResult(0)
		->queueExecResult(false);
	$t->throws(
		static fn()=>$inspector->certifyDown(
			$finalDownExecutionFailure,
			$dataLossEntry
		),
		RuntimeException::class,
		'Migration final down SQL execution failed during rollback certification'
	);

	$noChange=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($noChange, 'same');
	dp_postgresql_migration_queue_structure($noChange, 'same');
	$t->throws(
		static fn()=>$inspector->certifyDown($noChange, [
			'id'=>'003_expand',
			'up'=>['sql'=>'UP'],
			'down'=>['sql'=>'DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'made no structural change'
	);

	$dataOnly=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnly, 'same');
	dp_postgresql_migration_queue_data($dataOnly, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnly, 'same');
	dp_postgresql_migration_queue_data($dataOnly, '1', 'down');
	dp_postgresql_migration_queue_structure($dataOnly, 'same');
	dp_postgresql_migration_queue_data($dataOnly, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnly, 'same');
	dp_postgresql_migration_queue_data($dataOnly, '1', 'down');
	$inspector->certifyDown($dataOnly, [
		'id'=>'003_data_backfill',
		'change_kind'=>'data_only',
		'up'=>['sql'=>'DATA UP'],
		'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
	]);
	$t->same(
		['DATA DOWN', 'DATA UP', 'DATA DOWN'],
		array_column(array_values(array_filter(
			$dataOnly->operations(),
			static fn(array $operation): bool=>$operation['operation']==='exec'
		)), 'sql')
	);

	$dataOnlyStructuralMutation=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnlyStructuralMutation, 'before');
	dp_postgresql_migration_queue_data($dataOnlyStructuralMutation, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyStructuralMutation, 'changed');
	$t->throws(
		static fn()=>$inspector->certifyDown($dataOnlyStructuralMutation, [
			'id'=>'003_data_backfill',
			'change_kind'=>'data_only',
			'up'=>['sql'=>'DATA UP'],
			'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'down direction changed application structure'
	);

	$dataOnlyUpStructuralMutation=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnlyUpStructuralMutation, 'same');
	dp_postgresql_migration_queue_data($dataOnlyUpStructuralMutation, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyUpStructuralMutation, 'same');
	dp_postgresql_migration_queue_data($dataOnlyUpStructuralMutation, '1', 'down');
	dp_postgresql_migration_queue_structure($dataOnlyUpStructuralMutation, 'changed');
	$t->throws(
		static fn()=>$inspector->certifyDown($dataOnlyUpStructuralMutation, [
			'id'=>'003_data_backfill',
			'change_kind'=>'data_only',
			'up'=>['sql'=>'DATA UP'],
			'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'did not reconstruct the pre-rollback schema'
	);

	$dataOnlyRestorationMismatch=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnlyRestorationMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRestorationMismatch, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyRestorationMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRestorationMismatch, '1', 'down');
	dp_postgresql_migration_queue_structure($dataOnlyRestorationMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRestorationMismatch, '2', 'wrong');
	$t->throws(
		static fn()=>$inspector->certifyDown($dataOnlyRestorationMismatch, [
			'id'=>'003_data_backfill',
			'change_kind'=>'data_only',
			'up'=>['sql'=>'DATA UP'],
			'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'did not reconstruct pre-rollback data'
	);

	$dataOnlyFinalStructuralMutation=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnlyFinalStructuralMutation, 'same');
	dp_postgresql_migration_queue_data($dataOnlyFinalStructuralMutation, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyFinalStructuralMutation, 'same');
	dp_postgresql_migration_queue_data($dataOnlyFinalStructuralMutation, '1', 'down');
	dp_postgresql_migration_queue_structure($dataOnlyFinalStructuralMutation, 'same');
	dp_postgresql_migration_queue_data($dataOnlyFinalStructuralMutation, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyFinalStructuralMutation, 'changed');
	$t->throws(
		static fn()=>$inspector->certifyDown($dataOnlyFinalStructuralMutation, [
			'id'=>'003_data_backfill',
			'change_kind'=>'data_only',
			'up'=>['sql'=>'DATA UP'],
			'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'final down direction changed application structure'
	);

	$dataOnlyRepeatMismatch=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($dataOnlyRepeatMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRepeatMismatch, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyRepeatMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRepeatMismatch, '1', 'down');
	dp_postgresql_migration_queue_structure($dataOnlyRepeatMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRepeatMismatch, '2', 'before');
	dp_postgresql_migration_queue_structure($dataOnlyRepeatMismatch, 'same');
	dp_postgresql_migration_queue_data($dataOnlyRepeatMismatch, '0', 'different-down');
	$t->throws(
		static fn()=>$inspector->certifyDown($dataOnlyRepeatMismatch, [
			'id'=>'003_data_backfill',
			'change_kind'=>'data_only',
			'up'=>['sql'=>'DATA UP'],
			'down'=>['sql'=>'DATA DOWN', 'safety'=>'data_loss'],
		]),
		RuntimeException::class,
		'not repeatably paired with its up direction'
	);

	$nondeterministicFinalDown=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($nondeterministicFinalDown, 'before');
	dp_postgresql_migration_queue_data($nondeterministicFinalDown, '1', 'stable');
	dp_postgresql_migration_queue_structure($nondeterministicFinalDown, 'down');
	dp_postgresql_migration_queue_data($nondeterministicFinalDown, '1', 'stable');
	dp_postgresql_migration_queue_structure($nondeterministicFinalDown, 'before');
	dp_postgresql_migration_queue_data($nondeterministicFinalDown, '1', 'stable');
	dp_postgresql_migration_queue_structure($nondeterministicFinalDown, 'down');
	dp_postgresql_migration_queue_data($nondeterministicFinalDown, '0', 'lost');
	$t->throws(
		static fn()=>$inspector->certifyDown($nondeterministicFinalDown, [
			'id'=>'003_expand',
			'up'=>['sql'=>'UP'],
			'down'=>['sql'=>'DOWN', 'safety'=>'lossless'],
		]),
		RuntimeException::class,
		'removes application rows'
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'rollback')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL schema inspection reports every catalog drift class', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$expected=[
		'tables'=>[
			'fixture.items'=>[
				'columns'=>[
					'missing_col'=>['type'=>'text', 'nullable'=>true],
					'wrong_type'=>['type'=>'text', 'nullable'=>false],
				],
				'primary_key'=>['expected_id'],
			],
			'fixture.target'=>[
				'columns'=>['id'=>['type'=>'text', 'nullable'=>true]],
				'primary_key'=>null,
			],
			'fixture.missing'=>[
				'columns'=>['id'=>['type'=>'text', 'nullable'=>false]],
				'primary_key'=>['id'],
			],
		],
		'indexes'=>[
			'fixture.missing_index'=>[
				'table'=>'fixture.items', 'unique'=>false, 'keys'=>['missing_col'], 'predicate'=>null,
			],
			'fixture.table_mismatch'=>[
				'table'=>'fixture.items', 'unique'=>false, 'keys'=>['id'], 'predicate'=>null,
			],
			'fixture.invalid_index'=>[
				'table'=>'fixture.items', 'unique'=>false, 'keys'=>['wrong_type'], 'predicate'=>null,
			],
			'fixture.unique_index'=>[
				'table'=>'fixture.items', 'unique'=>true, 'keys'=>['wrong_type'], 'predicate'=>null,
			],
			'fixture.keys_index'=>[
				'table'=>'fixture.items', 'unique'=>false, 'keys'=>['expected_key'], 'predicate'=>null,
			],
			'fixture.predicate_index'=>[
				'table'=>'fixture.items',
				'unique'=>false,
				'keys'=>['wrong_type'],
				'predicate'=>'wrong_type is not null',
			],
		],
		'foreign_keys'=>[
			'fixture.items.fk_missing'=>[
				'table'=>'fixture.items',
				'columns'=>['missing_col'],
				'referenced_table'=>'fixture.target',
				'referenced_columns'=>['id'],
				'on_delete'=>'cascade',
			],
			'fixture.items.fk_bad'=>[
				'table'=>'fixture.target',
				'columns'=>['expected_col'],
				'referenced_table'=>'fixture.target',
				'referenced_columns'=>['expected_id'],
				'on_delete'=>'cascade',
			],
		],
		'checks'=>[
			'fixture.items.named_missing'=>[
				'table'=>'fixture.items', 'name'=>'named_missing', 'expression'=>'missing_col is not null',
			],
			'fixture.items.bad_check'=>[
				'table'=>'fixture.items', 'name'=>'bad_check', 'expression'=>'wrong_type>0',
			],
			'fixture.items.expression_match'=>[
				'table'=>'fixture.items', 'name'=>null, 'expression'=>'wrong_type is not null',
			],
			'fixture.items.expression_missing'=>[
				'table'=>'fixture.items', 'name'=>null, 'expression'=>'missing_col is not null',
			],
		],
	];
	$pdo=$t->scriptedPdo('pgsql')
		->queueRows([
			[
				'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'wrong_type',
				'column_type'=>'integer', 'is_not_null'=>'f',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'actual_id',
				'column_type'=>'text', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'target', 'column_name'=>'id',
				'column_type'=>'text', 'is_not_null'=>'f',
			],
		])
		->queueRows([
			['schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'actual_id'],
		])
		->queueRows([[
			'schema_name'=>'fixture',
			'table_name'=>'items',
			'constraint_name'=>'fk_bad',
			'column_name'=>'actual_col',
			'referenced_schema_name'=>'fixture',
			'referenced_table_name'=>'other',
			'referenced_column_name'=>'actual_id',
			'on_delete'=>'restrict',
			'is_valid'=>'f',
		]])
		->queueRows([
			[
				'schema_name'=>'fixture',
				'table_name'=>'items',
				'constraint_name'=>'bad_check',
				'expression'=>'wrong_type < 0',
				'is_valid'=>'f',
			],
			[
				'schema_name'=>'fixture',
				'table_name'=>'items',
				'constraint_name'=>'anonymous_match',
				'expression'=>'wrong_type IS NOT NULL',
				'is_valid'=>'t',
			],
		])
		->queueRows([
			[
				'index_schema'=>'fixture', 'index_name'=>'table_mismatch',
				'table_schema'=>'fixture', 'table_name'=>'target',
				'is_unique'=>'f', 'is_valid'=>'t', 'is_ready'=>'t',
				'index_definition'=>'CREATE INDEX fixture.table_mismatch ON fixture.target (id)',
				'predicate'=>null,
			],
			[
				'index_schema'=>'fixture', 'index_name'=>'invalid_index',
				'table_schema'=>'fixture', 'table_name'=>'items',
				'is_unique'=>'f', 'is_valid'=>'f', 'is_ready'=>'t',
				'index_definition'=>'CREATE INDEX fixture.invalid_index ON fixture.items (wrong_type)',
				'predicate'=>null,
			],
			[
				'index_schema'=>'fixture', 'index_name'=>'unique_index',
				'table_schema'=>'fixture', 'table_name'=>'items',
				'is_unique'=>'f', 'is_valid'=>'t', 'is_ready'=>'t',
				'index_definition'=>'CREATE INDEX fixture.unique_index ON fixture.items (wrong_type)',
				'predicate'=>null,
			],
			[
				'index_schema'=>'fixture', 'index_name'=>'keys_index',
				'table_schema'=>'fixture', 'table_name'=>'items',
				'is_unique'=>'f', 'is_valid'=>'t', 'is_ready'=>'t',
				'index_definition'=>'CREATE INDEX fixture.keys_index ON fixture.items (actual_key)',
				'predicate'=>null,
			],
			[
				'index_schema'=>'fixture', 'index_name'=>'predicate_index',
				'table_schema'=>'fixture', 'table_name'=>'items',
				'is_unique'=>'f', 'is_valid'=>'t', 'is_ready'=>'t',
				'index_definition'=>
					'CREATE INDEX fixture.predicate_index ON fixture.items (wrong_type) '.
					'WHERE wrong_type < 100',
				'predicate'=>'wrong_type < 100',
			],
		]);
	$kinds=array_column($inspector->schemaIssues($pdo, $expected), 'kind');
	foreach([
		'missing_table',
		'missing_column',
		'column_type_mismatch',
		'column_nullability_mismatch',
		'primary_key_mismatch',
		'missing_index',
		'index_table_mismatch',
		'invalid_index',
		'index_uniqueness_mismatch',
		'index_keys_mismatch',
		'index_predicate_mismatch',
		'missing_foreign_key',
		'invalid_foreign_key',
		'foreign_key_table_mismatch',
		'foreign_key_columns_mismatch',
		'foreign_key_referenced_table_mismatch',
		'foreign_key_referenced_columns_mismatch',
		'foreign_key_on_delete_mismatch',
		'missing_check_constraint',
		'invalid_check_constraint',
		'check_constraint_expression_mismatch',
	] as $kind){
		$t->contains($kind, $kinds);
	}
})->tag('sql', 'migration', 'postgresql', 'schema', 'drift')->group('framework-coverage');

test('PostgreSQL schema derivation handles alterations drops and parser failures', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$schema=$inspector->expectedSchema([[
		'name'=>'001_setup',
		'sql'=><<<'SQL'
CREATE TABLE fixture.parent (
	id TEXT,
	note TEXT
);
CREATE TABLE fixture.child (
	id TEXT,
	parent_id TEXT,
	state TEXT,
	CONSTRAINT child_state_check CHECK (state <> 'it''s (closed)')
);
CREATE TABLE fixture.survivor (
	id TEXT,
	dropme_id TEXT
);
CREATE TABLE fixture.dropme (
	id TEXT,
	state TEXT,
	CONSTRAINT dropme_state_check CHECK (state IS NOT NULL)
);
ALTER TABLE fixture.parent ADD PRIMARY KEY (id);
ALTER TABLE fixture.parent ALTER COLUMN id SET NOT NULL;
ALTER TABLE fixture.parent ALTER COLUMN note DROP NOT NULL;
ALTER TABLE other.ignored ADD COLUMN ignored TEXT;
ALTER TABLE other.ignored ADD PRIMARY KEY (ignored);
ALTER TABLE fixture.child ADD CONSTRAINT child_parent_fkey
	FOREIGN KEY (parent_id) REFERENCES fixture.parent (id) ON DELETE SET DEFAULT;
ALTER TABLE other.ignored ADD CONSTRAINT ignored_fkey
	FOREIGN KEY (ignored) REFERENCES fixture.parent (id);
ALTER TABLE fixture.survivor ADD CONSTRAINT survivor_dropme_fkey
	FOREIGN KEY (dropme_id) REFERENCES fixture.dropme (id);
ALTER TABLE fixture.survivor ADD CONSTRAINT survivor_parent_fkey
	FOREIGN KEY (id) REFERENCES fixture.parent (id);
ALTER TABLE fixture.child ADD CHECK (length(state) > 0);
ALTER TABLE other.ignored ADD CHECK (ignored <> '');
ALTER TABLE fixture.child ADD CONSTRAINT transient_check CHECK (id <> '');
CREATE INDEX fixture.child_parent_idx ON fixture.child (parent_id);
CREATE INDEX child_state_idx ON fixture.child (state ASC NULLS FIRST);
CREATE INDEX fixture.child_id_idx ON fixture.child (id DESC NULLS FIRST);
CREATE INDEX fixture.dropme_state_idx ON fixture.dropme (state);
CREATE INDEX fixture.survivor_dropme_idx ON fixture.survivor (dropme_id);
SQL
	],[
		'name'=>'002_drop',
		'sql'=><<<'SQL'
ALTER TABLE fixture.child DROP CONSTRAINT transient_check;
ALTER TABLE fixture.child DROP COLUMN parent_id CASCADE;
ALTER TABLE fixture.child DROP COLUMN state;
ALTER TABLE fixture.parent DROP COLUMN id;
DROP INDEX IF EXISTS child_id_idx;
DROP INDEX IF EXISTS fixture.child_state_idx;
DROP TABLE IF EXISTS fixture.dropme CASCADE;
SQL
		]]);
		$t->same(null, $schema['tables']['fixture.parent']['primary_key']);
		$t->isTrue($schema['tables']['fixture.parent']['columns']['note']['nullable']);
		$t->isFalse(isset($schema['tables']['fixture.child']['columns']['parent_id']));
		$t->isFalse(isset($schema['tables']['fixture.child']['columns']['state']));
		$t->isFalse(isset($schema['tables']['fixture.parent']['columns']['id']));
		$t->isFalse(isset($schema['tables']['fixture.dropme']));
		$t->same(['fixture.survivor_dropme_idx'], array_keys($schema['indexes']));
		$t->same([], $schema['foreign_keys']);
		$t->same([], $schema['checks']);

	$eofEntries=[
		['name'=>'001_parent', 'sql'=>'CREATE TABLE fixture.eof_parent (id TEXT PRIMARY KEY)'],
		[
			'name'=>'002_child',
			'sql'=>'CREATE TABLE fixture.eof_child (id TEXT PRIMARY KEY, parent_id TEXT)',
		],
		[
			'name'=>'003_note',
			'sql'=>'ALTER TABLE fixture.eof_child ADD COLUMN note TEXT',
		],
		[
			'name'=>'004_note_required',
			'sql'=>'ALTER TABLE fixture.eof_child ALTER COLUMN note SET NOT NULL',
		],
		[
			'name'=>'005_parent_fk',
			'sql'=>
				'ALTER TABLE fixture.eof_child ADD CONSTRAINT eof_child_parent_fkey '.
				'FOREIGN KEY (parent_id) REFERENCES fixture.eof_parent (id) ON DELETE RESTRICT',
		],
		[
			'name'=>'006_note_index',
			'sql'=>'CREATE INDEX fixture.eof_child_note_idx ON fixture.eof_child (note)',
		],
	];
	$eofSchema=$inspector->expectedSchema($eofEntries);
	$t->isFalse($eofSchema['tables']['fixture.eof_child']['columns']['note']['nullable']);
	$t->same(
		'restrict',
		$eofSchema['foreign_keys']['fixture.eof_child.eof_child_parent_fkey']['on_delete']
	);
	$t->isTrue(isset($eofSchema['indexes']['fixture.eof_child_note_idx']));
	$eofDropped=$inspector->expectedSchema(array_merge($eofEntries, [
		[
			'name'=>'007_drop_index',
			'sql'=>'DROP INDEX IF EXISTS fixture.eof_child_note_idx',
		],
		[
			'name'=>'008_drop_note',
			'sql'=>'ALTER TABLE fixture.eof_child DROP COLUMN note',
		],
		[
			'name'=>'009_drop_child',
			'sql'=>'DROP TABLE IF EXISTS fixture.eof_child CASCADE',
		],
	]));
	$t->isFalse(isset($eofDropped['indexes']['fixture.eof_child_note_idx']));
	$t->isFalse(isset($eofDropped['tables']['fixture.eof_child']));

	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'bad-column',
			'sql'=>'CREATE TABLE fixture.bad (nonsense);',
		]]),
		RuntimeException::class,
		'Cannot parse migration column definition'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'empty-check',
			'sql'=>'CREATE TABLE fixture.bad (id TEXT, CHECK ());',
		]]),
		RuntimeException::class,
		'Cannot parse migration CHECK constraint'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'unclosed-check',
			'sql'=>'ALTER TABLE fixture.bad ADD CHECK (id > 0;',
		]]),
		RuntimeException::class,
		'Unclosed parenthesis'
	);
		$t->throws(
			static fn()=>$inspector->expectedSchema([[
				'name'=>'missing-index-keys',
				'sql'=>"CREATE INDEX fixture.bad_idx ON fixture.bad \n",
			]]),
		RuntimeException::class,
		'Cannot parse index key definition'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'empty-index-keys',
			'sql'=>'CREATE INDEX fixture.bad_idx ON fixture.bad ();',
		]]),
		RuntimeException::class,
		'empty key definition'
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'parser')->group('framework-coverage');

test('PostgreSQL schema inspector query and certification failures stay fail closed', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$minimal=[
		'tables'=>[
			'fixture.items'=>[
				'columns'=>['id'=>['type'=>'text', 'nullable'=>false]],
				'primary_key'=>['id'],
			],
		],
		'indexes'=>[],
		'foreign_keys'=>[],
		'checks'=>[],
	];
	$t->throws(
		static fn()=>$inspector->schemaIssues(
			$t->scriptedPdo('pgsql')->queuePrepareMiss(),
			$minimal
		),
		RuntimeException::class,
		'schema inspection query failed'
	);
	$t->throws(
		static fn()=>$inspector->dataFingerprint(
			$t->scriptedPdo('pgsql')->queueRows([['table_name'=>'']])
		),
		RuntimeException::class,
		'unnamed application table'
	);
		$t->throws(
			static fn()=>$inspector->dataFingerprint(
				$t->scriptedPdo('pgsql')
					->queueRows([['table_name'=>'items']])
					->queueRows([])
		),
			RuntimeException::class,
			'invalid row'
		);
		$t->throws(
			static fn()=>$inspector->dataFingerprint(
				$t->scriptedPdo('pgsql')
					->queueRows([['table_name'=>'items']])
					->queuePrepareMiss()
			),
			RuntimeException::class,
			'schema inspection query failed'
		);
	$badIndex=$t->scriptedPdo('pgsql')
		->queueRows([[
			'schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'id',
			'column_type'=>'text', 'is_not_null'=>'t',
		]])
		->queueRows([['schema_name'=>'fixture', 'table_name'=>'items', 'column_name'=>'id']])
		->queueRows([])
		->queueRows([])
		->queueRows([[
			'index_schema'=>'fixture', 'index_name'=>'bad_idx',
			'table_schema'=>'fixture', 'table_name'=>'items',
			'is_unique'=>'f', 'is_valid'=>'t', 'is_ready'=>'t',
			'index_definition'=>'not a create index statement',
			'predicate'=>null,
		]]);
	$t->throws(
		static fn()=>$inspector->schemaIssues($badIndex, $minimal),
		RuntimeException::class,
		'Cannot normalize live PostgreSQL index definition'
	);

	$dataLossEntry=[
		'id'=>'003_expand',
		'up'=>['sql'=>'UP'],
		'down'=>['sql'=>'DOWN', 'safety'=>'data_loss'],
	];
	$restorationMismatch=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($restorationMismatch, 'before');
	dp_postgresql_migration_queue_structure($restorationMismatch, 'down');
	dp_postgresql_migration_queue_structure($restorationMismatch, 'wrong-restoration');
	$t->throws(
		static fn()=>$inspector->certifyDown($restorationMismatch, $dataLossEntry),
		RuntimeException::class,
		'did not reconstruct'
	);

	$repeatMismatch=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($repeatMismatch, 'before');
	dp_postgresql_migration_queue_structure($repeatMismatch, 'down');
	dp_postgresql_migration_queue_structure($repeatMismatch, 'before');
	dp_postgresql_migration_queue_structure($repeatMismatch, 'different-final-down');
	$t->throws(
		static fn()=>$inspector->certifyDown($repeatMismatch, $dataLossEntry),
		RuntimeException::class,
		'is not repeatably paired'
	);

	$losslessMismatch=$t->scriptedPdo('pgsql');
	dp_postgresql_migration_queue_structure($losslessMismatch, 'before');
	dp_postgresql_migration_queue_data($losslessMismatch, '1', 'before');
	dp_postgresql_migration_queue_structure($losslessMismatch, 'down');
	dp_postgresql_migration_queue_data($losslessMismatch, '1', 'before');
	dp_postgresql_migration_queue_structure($losslessMismatch, 'before');
	dp_postgresql_migration_queue_data($losslessMismatch, '1', 'after');
	$t->throws(
		static fn()=>$inspector->certifyDown($losslessMismatch, [
			'id'=>'003_expand',
			'up'=>['sql'=>'UP'],
			'down'=>['sql'=>'DOWN', 'safety'=>'lossless'],
		]),
		RuntimeException::class,
		'did not preserve all application rows'
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'fail-closed')->group('framework-coverage')->maxMillis(10000);

test('PostgreSQL schema inspector lexical seams preserve quoted and nested SQL', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$access=$t->nonPublic($inspector);
	$t->throws(
		static fn()=>$access->invoke('matchingParenthesis', 'not-parenthesized', 0),
		RuntimeException::class,
		'parenthesis boundary'
	);
		$t->throws(
			static fn()=>$access->invoke('matchingParenthesis', "(unclosed 'it''s'", 0),
		RuntimeException::class,
		'Unclosed parenthesis'
	);
	$t->same(
		18,
		$access->invoke('matchingParenthesis', "(name = 'it''s ok') trailing", 0)
	);
	$t->same(
		29,
		$access->invoke('statementEnd', " WHERE value = 'semi;''colon'; tail", 0)
	);
	$commentedStatement=' WHERE value = 1 /* an inert ; boundary */; tail';
	$t->same(
		strpos($commentedStatement, '; tail'),
		$access->invoke('statementEnd', $commentedStatement, 0)
	);
	$commentedParenthesis='(value /* an inert ) boundary */ + $tag$)$tag$) tail';
	$t->same(
		strrpos($commentedParenthesis, ') tail'),
		$access->invoke('matchingParenthesis', $commentedParenthesis, 0)
	);
	$t->same(
		strlen(' nested(function(1))'),
		$access->invoke('statementEnd', ' nested(function(1))', 0)
	);
		$t->same(
			"value = 'where''s nested' AND nested(where_token)",
			$access->invoke(
				'topLevelKeywordTail',
				"expression('where') WHERE value = 'where''s nested' AND nested(where_token)",
				'where'
			)
		);
		$t->same(
			'id=1',
			$access->invoke('topLevelKeywordTail', "expression('where''s nested') WHERE id=1", 'where')
		);
	$t->same(null, $access->invoke('topLevelKeywordTail', "expression('where')", 'where'));
	$t->same('(unclosed', $access->invoke('stripOuterParentheses', '(unclosed'));
	$t->same('(one)+(two)', $access->invoke('stripOuterParentheses', '((one)+(two))'));
	$t->same(
		["name TEXT DEFAULT 'a,b''c'", 'amount NUMERIC(10,2)', '"Quoted" TEXT'],
		$access->invoke(
			'splitDefinitions',
			"name TEXT DEFAULT 'a,b''c', amount NUMERIC(10,2), \"Quoted\" TEXT"
		)
	);
	$t->isTrue($access->invoke('indexUsesColumn', ['"Name" desc nulls last'], 'name'));
	$t->isFalse($access->invoke('indexUsesColumn', ['other'], 'name'));
	$t->same('A"B', $access->invoke('identifier', '"A""B"'));
	$t->same(
		"amount>=1.2e+3 and name='it''s'",
		PostgreSqlSchemaInspector::normalizeSqlExpression(
			" amount >= 1.2e+3 AND name = 'it''s' "
		)
	);
	$t->same('position', $access->invoke('normalizeIndexExpression', '"position"'));
	$t->same(
		'mod(id,(4)::bigint)',
		$access->invoke('normalizeIndexExpression', 'mod(id, (4)::bigint)')
	);
	$t->isTrue($access->invoke('expressionsEquivalent', 'mod(id, 4)', 'mod(id,(4)::bigint)'));
	$t->isFalse($access->invoke('expressionsEquivalent', 'f(1)', 'f((1)::bigint)'));
	$t->isTrue($access->invoke('expressionsEquivalent', "f('x')", "f('x'::text)"));
	$t->isFalse($access->invoke('expressionsEquivalent', "f('x'::text)", "f('x')"));
	foreach(['true', 'false', 'null'] as $constant){
		$t->notSame(
			$access->invoke('normalizeIndexExpression', '"'.$constant.'"'),
			$access->invoke('normalizeIndexExpression', strtoupper($constant))
		);
	}
	$t->same(
		"line_evidence->'correction'->>'replaces_sha256'",
		$access->invoke(
			'normalizeIndexExpression',
			"(line_evidence->'correction')->>'replaces_sha256'"
		)
	);
	$t->isTrue(
		$access->invoke(
			'expressionsEquivalent',
			"evidence#>>'{authority_evidence,commercial_selection,selection_sha256}'",
			$access->invoke(
				'normalizeIndexExpression',
				"evidence #>> '{authority_evidence,commercial_selection,selection_sha256}'::text[]"
			)
		)
	);
	$t->isTrue(
		$access->invoke(
			'expressionsEquivalent',
		$access->invoke(
			'normalizeIndexPredicate',
			"deleted_at IS NULL AND status NOT IN ('completed', 'duplicate')"
		),
		$access->invoke(
			'normalizeIndexPredicate',
			"deleted_at IS NULL AND (status <> ALL (ARRAY['completed'::text, 'duplicate'::text]))"
		)
		)
	);
	$t->same(
		$access->invoke(
			'normalizeIndexPredicate',
			"deleted_at IS NULL AND (status='completed' OR (status='superseded' AND completed_at IS NOT NULL))"
		),
		$access->invoke(
			'normalizeIndexPredicate',
			"deleted_at IS NULL AND (status='completed' OR status='superseded' AND completed_at IS NOT NULL)"
		)
	);
	$t->notSame(
		$access->invoke('normalizeIndexExpression', 'value::text'),
		$access->invoke('normalizeIndexExpression', 'value')
	);
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		$access->invoke(
			'normalizeIndexPredicate',
			"(payload->>'flag'='yes' OR enabled) IS TRUE"
		),
		$access->invoke(
			'normalizeIndexPredicate',
			"payload->>'flag'='yes' OR enabled IS TRUE"
		)
	));

	$dynamic=$inspector->expectedSchema([[
		'name'=>'dynamic_indexes',
		'sql'=><<<'SQL'
DO $migration$
BEGIN
	EXECUTE '
		CREATE INDEX dynamic_first_idx ON fixture.jobs (id)
		WHERE deleted_at IS NULL
	';
	EXECUTE '
		CREATE INDEX dynamic_second_idx ON fixture.jobs (created_at, id)
		WHERE created_at IS NOT NULL
	';
	PERFORM 'CREATE INDEX not_executed_idx ON fixture.jobs (id) WHERE id IS NOT NULL';
END
$migration$;
SQL
	]]);
	$t->same(
		'deleted_at is null',
		$dynamic['indexes']['fixture.dynamic_first_idx']['predicate']
	);
	$t->same(
		'created_at is not null',
		$dynamic['indexes']['fixture.dynamic_second_idx']['predicate']
	);
	$t->isFalse(isset($dynamic['indexes']['fixture.not_executed_idx']));

	$fixedQuoted=$inspector->expectedSchema([[
		'name'=>'fixed_quoted_indexes',
		'sql'=><<<'SQL'
DO $body$
BEGIN
	EXECUTE /* fixed literal */ 'CREATE INDEX dynamic_quoted_idx
		ON fixture.jobs (id) WHERE status=''ready''';
	EXECUTE -- fixed dollar literal
		$ysql$CREATE INDEX dynamic_dollar_idx
		ON fixture.jobs (created_at) WHERE status='ready'$ysql$;
	PERFORM $ddl$CREATE INDEX dollar_decoy_idx
		ON fixture.jobs (id) WHERE id IS NOT NULL$ddl$;
END
$body$;
SQL
	]]);
	$t->same(
		"status='ready'",
		$fixedQuoted['indexes']['fixture.dynamic_quoted_idx']['predicate']
	);
	$t->same(
		"status='ready'",
		$fixedQuoted['indexes']['fixture.dynamic_dollar_idx']['predicate']
	);
	$t->isFalse(isset($fixedQuoted['indexes']['fixture.dollar_decoy_idx']));

	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'unsupported_dynamic_index',
			'sql'=><<<'SQL'
DO $body$
BEGIN
	EXECUTE format('CREATE INDEX formatted_idx ON fixture.jobs (%I)', 'id');
END
$body$;
SQL
		]]),
		RuntimeException::class,
		'non-fixed EXECUTE expression'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'concatenated_dynamic_index',
			'sql'=><<<'SQL'
DO $body$
BEGIN
	EXECUTE 'CREATE INDEX concatenated_idx ON fixture.jobs (id)' ||
		' WHERE deleted_at IS NULL';
END
$body$;
SQL
		]]),
		RuntimeException::class,
		'non-fixed EXECUTE expression'
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'lexical')->group('framework-coverage');

test('PostgreSQL schema inspector projects executable migration DDL fail closed', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_migration_profile());
	$schema=$inspector->expectedSchema([[
		'name'=>'executable_projection',
		'sql'=><<<'SQL'
/* A leading comment must not hide the following tracked statement. */
CREATE TABLE fixture.parents (
	id BIGINT PRIMARY KEY
);
/* CREATE INDEX comment_decoy_idx ON fixture.parents (id); */
CREATE TABLE fixture.jobs (
	id BIGINT PRIMARY KEY,
	parent_id BIGINT,
	state TEXT,
	deleted_at TIMESTAMPTZ
);
-- The semicolon in this comment is not the statement boundary.
CREATE INDEX comment_semicolon_idx ON fixture.jobs (id) /* ; */ WHERE deleted_at IS NULL;
ALTER TABLE fixture.jobs ADD CONSTRAINT jobs_parent_fkey
	FOREIGN KEY (parent_id) REFERENCES fixture.parents (id);
ALTER TABLE fixture.jobs ADD CONSTRAINT jobs_state_check CHECK (state <> '');
/* Drops must remove every previously derived named constraint kind. */
ALTER TABLE fixture.jobs DROP CONSTRAINT jobs_state_check;
-- This line comment must be fully masked too.
ALTER TABLE fixture.jobs DROP CONSTRAINT jobs_parent_fkey;
ALTER TABLE fixture.jobs DROP CONSTRAINT jobs_pkey;

CREATE FUNCTION fixture.stored_definition_only() RETURNS void
LANGUAGE plpgsql
AS $function$
BEGIN
	EXECUTE 'CREATE INDEX stored_definition_idx ON fixture.jobs (parent_id)';
END
$function$;

DO /* $comment_delimiter_must_be_inert$ */ $body$
BEGIN
	CREATE INDEX direct_do_idx ON fixture.jobs (parent_id);
	PERFORM 'CREATE INDEX perform_decoy_idx ON fixture.jobs (state)';
	CREATE TRIGGER trigger_decoy BEFORE INSERT ON fixture.jobs
		FOR EACH ROW EXECUTE FUNCTION fixture.trigger_handler();
END
$body$;
SQL
	]]);
	$t->same(null, $schema['tables']['fixture.jobs']['primary_key']);
	$t->same([], $schema['foreign_keys']);
	$t->same([], $schema['checks']);
	$t->same(
		['fixture.comment_semicolon_idx', 'fixture.direct_do_idx'],
		array_keys($schema['indexes'])
	);
	$t->same(
		'deleted_at is null',
		$schema['indexes']['fixture.comment_semicolon_idx']['predicate']
	);
	foreach([
		'fixture.comment_decoy_idx',
		'fixture.stored_definition_idx',
		'fixture.perform_decoy_idx',
	] as $absent){
		$t->isFalse(isset($schema['indexes'][$absent]), $absent);
	}

	$productSql=<<<'SQL'
DO $body$
BEGIN
	IF position('YugabyteDB' IN version())>0 THEN
		EXECUTE 'CREATE INDEX dialect_idx ON fixture.jobs (id HASH)';
	ELSE
		CREATE INDEX dialect_idx ON fixture.jobs (id DESC);
	END IF;
END
$body$;
SQL;
	$postgresql=$inspector->expectedSchema([
		['name'=>'product_branch', 'sql'=>$productSql],
	], 'postgresql');
	$yugabyte=$inspector->expectedSchema([
		['name'=>'product_branch', 'sql'=>$productSql],
	], 'yugabyte');
	$t->same(['id desc'], $postgresql['indexes']['fixture.dialect_idx']['keys']);
	$t->same(['id hash'], $yugabyte['indexes']['fixture.dialect_idx']['keys']);
	$yugabytePdo=new class extends PDO {
		public function __construct() {}
		public function getAttribute(int $attribute): mixed {
			if($attribute===PDO::ATTR_DRIVER_NAME){
				return 'pgsql';
			}
			return $attribute===PDO::ATTR_SERVER_VERSION
				? '11.2-YB-2025.1.0.0-b0'
				: null;
		}
	};
	$postgresPdo=new class extends PDO {
		public function __construct() {}
		public function getAttribute(int $attribute): mixed {
			if($attribute===PDO::ATTR_DRIVER_NAME){
				return 'pgsql';
			}
			if($attribute===PDO::ATTR_SERVER_VERSION){
				throw new RuntimeException('version unavailable');
			}
			return null;
		}
	};
	$t->same(
		'yugabyte',
		$t->nonPublic(new PostgreSqlMigrationRunner(
			$yugabytePdo,
			dp_postgresql_migration_profile()
		))->invoke('databaseDialect')
	);
	$t->same(
		'postgresql',
		$t->nonPublic(new PostgreSqlMigrationRunner(
			$postgresPdo,
			dp_postgresql_migration_profile()
		))->invoke('databaseDialect')
	);

	$t->throws(
		static fn()=>$inspector->expectedSchema([], 'unknown'),
		RuntimeException::class,
		'dialect is invalid'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'unresolved_control',
			'sql'=><<<'SQL'
DO $body$
BEGIN
	IF false THEN
		EXECUTE 'CREATE INDEX unreachable_idx ON fixture.jobs (id)';
	END IF;
END
$body$;
SQL
		]]),
		RuntimeException::class,
		'unsupported migration control flow'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'split_format',
			'sql'=><<<'SQL'
DO $body$
BEGIN
	EXECUTE format('CREATE %s split_format_idx ON fixture.jobs (id)', 'INDEX');
END
$body$;
SQL
		]]),
		RuntimeException::class,
		'non-fixed EXECUTE expression'
	);
	$t->throws(
		static fn()=>$inspector->expectedSchema([[
			'name'=>'escaped_keyword',
			'sql'=><<<'SQL'
DO $body$
BEGIN
	EXECUTE E'CR\u0045ATE INDEX escaped_idx ON fixture.jobs (id)';
END
$body$;
SQL
		]]),
		RuntimeException::class,
		'escape-string EXECUTE'
	);
})->tag('sql', 'migration', 'postgresql', 'schema', 'projection')->group('framework-coverage');
