<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

// Dynamic integration: requires two caller-provided disposable PostgreSQL databases.

use Dataphyre\Database\DataEnvironment;
use Dataphyre\Database\DB;
use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\define_test_symbols;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

$compatibilityHost=trim((string)(getenv('DATAPHYRE_SQL_COMPAT_HOST') ?: 'postgres'));
$compatibilityPort=(int)(getenv('DATAPHYRE_SQL_COMPAT_PORT') ?: 5432);
$compatibilityUser=trim((string)(getenv('DATAPHYRE_SQL_COMPAT_USERNAME') ?: 'application_user'));
$compatibilityPassword=(string)(getenv('DATAPHYRE_SQL_COMPAT_PASSWORD') ?: '');
$compatibilityLiveDatabase=trim((string)(getenv('DATAPHYRE_SQL_COMPAT_LIVE_DATABASE') ?: 'dataphyre_sql_compat_missing_live'));
$compatibilitySandboxDatabase=trim((string)(getenv('DATAPHYRE_SQL_COMPAT_SANDBOX_DATABASE') ?: 'dataphyre_sql_compat_missing_sandbox'));

if(!class_exists('dataphyre\core', false)){
	define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function dialback(string $name, mixed ...$arguments): mixed { return null; }
	public static function unavailable(mixed ...$arguments): never { throw new \RuntimeException('Dataphyre SQL reported an unavailable dependency.'); }
	public static function get_password(string $endpoint): string { return ''; }
	public static function load_framework_module(string $module): bool { return true; }
	public static function file_put_contents_forced(string $file, string $contents): int|false {
		$directory=dirname($file);
		if(!is_dir($directory)){ mkdir($directory, 0777, true); }
		return file_put_contents($file, $contents);
	}
	public static function force_rmdir(string $directory): bool { return true; }
}
PHP);
}

framework(['sql'], [
	'constants'=>[
		'DP_CORE_CFG'=>[
			'datacenter'=>'compatibility',
		],
		'DP_SQL_CFG'=>[
			'default_cluster'=>'compatibility_live',
			'default_database_location'=>'',
			'safe_delete'=>true,
			'seeds'=>[
				'paths'=>[],
				'ledger_table'=>'dataphyre_seed_ledger',
			],
			'data_environments'=>[
				'sandbox'=>[
					'cluster'=>'compatibility_sandbox',
					'cache_namespace'=>'compatibility-sandbox',
				],
			],
			'caching'=>[
				'rolling_db_cache_size'=>256,
				'default_policy'=>[
					'type'=>'session',
					'max_lifespan'=>'30 minute',
					'hash_type'=>'md5',
				],
			],
			'datacenters'=>[
				'compatibility'=>[
					'dbms_clusters'=>[
						'compatibility_live'=>[
							'dbms'=>'postgresql',
							'endpoints'=>[$compatibilityHost],
							'dbms_username'=>$compatibilityUser,
							'database_name'=>$compatibilityLiveDatabase,
							'dbms_port'=>$compatibilityPort,
							'password'=>$compatibilityPassword,
						],
						'compatibility_sandbox'=>[
							'dbms'=>'postgresql',
							'endpoints'=>[$compatibilityHost],
							'dbms_username'=>$compatibilityUser,
							'database_name'=>$compatibilitySandboxDatabase,
							'dbms_port'=>$compatibilityPort,
							'password'=>$compatibilityPassword,
						],
					],
				],
			],
			'tables'=>[
				'raw'=>[
					'cluster'=>'compatibility_live',
					'caching'=>[
						'type'=>'session',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5',
					],
				],
			],
		],
	],
	'functions'=>[
		'dataphyre\tracelog',
		'dataphyre\dp_define_module_config',
		'dataphyre\log_error',
		'dataphyre\dp_module_present',
		'dataphyre_shutdown_log',
	],
	'files'=>[
		'sql/kernel/sql.main.php',
	],
]);

function dp_sql_postgresql_compatibility_enabled(Context $t): void {
	if((string)(getenv('DATAPHYRE_SQL_POSTGRES_COMPATIBILITY') ?: '')!=='1'){
		$t->skip('Set DATAPHYRE_SQL_POSTGRES_COMPATIBILITY=1 and provide two disposable PostgreSQL databases.');
	}
	if(!extension_loaded('pgsql')){
		$t->skip('The PostgreSQL extension is unavailable.');
	}
}

function dp_sql_postgresql_compatibility_query(
	string $query,
	?array $variables=null,
	bool $associative=false
): mixed {
	return \dataphyre\sql::query(
		['postgresql'=>$query],
		$variables,
		$associative,
		false,
		false,
		false
	);
}

/** @return list<int> */
function dp_sql_postgresql_compatibility_marker_ids(): array {
	$rows=dp_sql_postgresql_compatibility_query(
		'SELECT id FROM dataphyre_sql_compatibility_markers ORDER BY id',
		null,
		true
	);
	return array_map(
		static fn(array $row): int=>(int)($row['id'] ?? 0),
		is_array($rows) ? $rows : []
	);
}

function dp_sql_postgresql_compatibility_reset_markers(): void {
	dp_sql_postgresql_compatibility_query(
		'CREATE TABLE IF NOT EXISTS dataphyre_sql_compatibility_markers (id INTEGER PRIMARY KEY, label TEXT NOT NULL)'
	);
	dp_sql_postgresql_compatibility_query('TRUNCATE TABLE dataphyre_sql_compatibility_markers');
}

test('PostgreSQL transaction controls bypass cache and roll back independently in live and sandbox', static function(Context $t): void {
	dp_sql_postgresql_compatibility_enabled($t);
	$t->globalMap('_SESSION')->clear();
	foreach([
		'live'=>['cluster'=>'compatibility_live', 'cache_namespace'=>null],
		'sandbox'=>['cluster'=>'compatibility_sandbox', 'cache_namespace'=>'compatibility-sandbox'],
	] as $environment=>$overrides){
		DataEnvironment::run($environment, static function() use ($t, $environment, $overrides): void {
			dp_sql_postgresql_compatibility_reset_markers();
			$t->same($overrides['cluster'], DB::connection()->cluster());
			$t->same($overrides['cluster'], DB::connection(' ')->cluster());
			$t->same('postgresql', DB::clusterDbms(' '));
			$t->isTrue(\dataphyre\sql::transaction(static function() use ($environment): bool {
				return dp_sql_postgresql_compatibility_query(
					'INSERT INTO dataphyre_sql_compatibility_markers (id, label) VALUES (?, ?)',
					[1, $environment.'-committed']
				)!==false;
			}));
			$t->isFalse(\dataphyre\sql::transaction(static function() use ($environment): bool {
				dp_sql_postgresql_compatibility_query(
					'INSERT INTO dataphyre_sql_compatibility_markers (id, label) VALUES (?, ?)',
					[2, $environment.'-rolled-back']
				);
				return false;
			}));
			$t->same([1], dp_sql_postgresql_compatibility_marker_ids());
		}, $overrides);
	}
	$t->same('live', DataEnvironment::name());
})->tag('sql', 'postgresql', 'transaction', 'cache', 'data-environment', 'compatibility')->maxMillis(15000);

test('PostgreSQL deferred queues retain registration-time cluster and cache namespace', static function(Context $t): void {
	dp_sql_postgresql_compatibility_enabled($t);
	$session=$t->globalMap('_SESSION')->clear();
	DataEnvironment::run('live', static function(): void {
		dp_sql_postgresql_compatibility_reset_markers();
	});
	DataEnvironment::run('sandbox', static function(): void {
		dp_sql_postgresql_compatibility_reset_markers();
	});

	$writeCallbacks=[];
	DataEnvironment::run('live', static function() use (&$writeCallbacks): void {
		\dataphyre\sql::query(
			['postgresql'=>'INSERT INTO dataphyre_sql_compatibility_markers (id, label) VALUES (?, ?)'],
			[11, 'live-queued'],
			false,
			false,
			false,
			false,
			'environment-compatibility-write',
			static function(mixed $result) use (&$writeCallbacks): void {
				$writeCallbacks[]=[DataEnvironment::name(), DataEnvironment::cacheKey('raw'), $result!==false];
			}
		);
	});
	DataEnvironment::run('sandbox', static function() use (&$writeCallbacks): void {
		\dataphyre\sql::query(
			['postgresql'=>'INSERT INTO dataphyre_sql_compatibility_markers (id, label) VALUES (?, ?)'],
			[22, 'sandbox-queued'],
			false,
			false,
			false,
			false,
			'environment-compatibility-write',
			static function(mixed $result) use (&$writeCallbacks): void {
				$writeCallbacks[]=[DataEnvironment::name(), DataEnvironment::cacheKey('raw'), $result!==false];
			}
		);
	});
	$t->isTrue(\dataphyre\sql::execute_queue('environment-compatibility-write'));
	$t->same([
		['live', 'raw', true],
		['sandbox', 'compatibility-sandbox::raw', true],
	], $writeCallbacks);
	DataEnvironment::run('live', static function() use ($t): void {
		$t->same([11], dp_sql_postgresql_compatibility_marker_ids());
	});
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$t->same([22], dp_sql_postgresql_compatibility_marker_ids());
	});

	$readCallbacks=[];
	foreach(['live', 'sandbox'] as $environment){
		DataEnvironment::run($environment, static function() use (&$readCallbacks): void {
			\dataphyre\sql::query(
				['postgresql'=>'SELECT id FROM dataphyre_sql_compatibility_markers ORDER BY id'],
				null,
				true,
				false,
				[true],
				false,
				'environment-compatibility-read',
				static function(mixed $result) use (&$readCallbacks): void {
					$readCallbacks[DataEnvironment::name()]=[
						'ids'=>array_map(static fn(array $row): int=>(int)$row['id'], is_array($result) ? $result : []),
						'cache_key'=>DataEnvironment::cacheKey('raw'),
					];
				}
			);
		});
	}
	$t->isTrue(\dataphyre\sql::execute_queue('environment-compatibility-read'));
	$t->same(['ids'=>[11], 'cache_key'=>'raw'], $readCallbacks['live'] ?? null);
	$t->same(
		['ids'=>[22], 'cache_key'=>'compatibility-sandbox::raw'],
		$readCallbacks['sandbox'] ?? null
	);
	$t->notNull($session->getPath(['db_cache','raw']));
	$t->notNull($session->getPath(['db_cache','compatibility-sandbox::raw']));
})->tag('sql', 'postgresql', 'queue', 'cache', 'data-environment', 'compatibility')->maxMillis(15000);

test('PostgreSQL table hydration and column metadata remain isolated to the ambient environment', static function(Context $t): void {
	dp_sql_postgresql_compatibility_enabled($t);
	$t->globalMap('_SESSION')->clear();
	foreach(['live', 'sandbox'] as $environment){
		DataEnvironment::run($environment, static function(): void {
			dp_sql_postgresql_compatibility_query('DROP TABLE IF EXISTS dataphyre_sql_compatibility_hydrated');
		});
	}

	$definition=TableDefinition::for('dataphyre_sql_compatibility_hydrated')
		->autoIncrement('id')
		->string('label', 64)->notNull();
	$columns=$definition->columnDefinitions();
	$t->same(['id', 'label'], array_keys($columns));
	$t->same(false, $columns['label']['nullable'] ?? true);
	$t->same(['id'], $definition->primaryColumns());
	$columns['label']['nullable']=true;
	$t->same(false, $definition->columnDefinitions()['label']['nullable'] ?? true);

	DataEnvironment::run('sandbox', static function() use ($definition, $t): void {
		$t->isTrue($definition->hydrate());
		$extension=TableDefinition::for('dataphyre_sql_compatibility_hydrated')->string('note', 64);
		$t->isTrue($extension->hydrateColumn('note'));
	});
	DataEnvironment::run('live', static function() use ($t): void {
		$row=dp_sql_postgresql_compatibility_query(
			'SELECT to_regclass(?) AS relation_name',
			['dataphyre_sql_compatibility_hydrated']
		);
		$t->same(null, $row['relation_name'] ?? null);
	});
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$row=dp_sql_postgresql_compatibility_query(
			'SELECT to_regclass(?) AS relation_name',
			['dataphyre_sql_compatibility_hydrated']
		);
		$t->same('dataphyre_sql_compatibility_hydrated', $row['relation_name'] ?? null);
		$column=dp_sql_postgresql_compatibility_query(
			'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
			['public', 'dataphyre_sql_compatibility_hydrated', 'note']
		);
		$t->same('note', $column['column_name'] ?? null);
	});
})->tag('sql', 'postgresql', 'schema', 'hydration', 'data-environment', 'compatibility')->maxMillis(15000);
