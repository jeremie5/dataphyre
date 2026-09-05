<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\ConnectionContext;
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
function ellipsis(string $string, int $length, string $direction='right'): string { return $string; }
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

function dp_sql_postgresql_environment_enabled(Context $t): void {
	if((string)(getenv('DATAPHYRE_SQL_POSTGRES_COMPATIBILITY') ?: '')!=='1'){
		$t->skip('Set DATAPHYRE_SQL_POSTGRES_COMPATIBILITY=1 and provide two disposable PostgreSQL databases.');
	}
	if(!extension_loaded('pgsql')){
		$t->skip('The PostgreSQL extension is unavailable.');
	}
}

function dp_sql_postgresql_environment_query(
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
function dp_sql_postgresql_environment_marker_ids(string $table): array {
	$rows=dp_sql_postgresql_environment_query(
		'SELECT id FROM '.$table.' ORDER BY id',
		null,
		true
	);
	return array_map(
		static fn(array $row): int=>(int)($row['id'] ?? 0),
		is_array($rows) ? $rows : []
	);
}

function dp_sql_postgresql_environment_reset_markers(string $table): void {
	dp_sql_postgresql_environment_query(
		'CREATE TABLE IF NOT EXISTS '.$table.' (id INTEGER PRIMARY KEY, label TEXT NOT NULL)'
	);
	dp_sql_postgresql_environment_query('TRUNCATE TABLE '.$table);
}

test('PostgreSQL native prepared reads distinguish concatenation binds from JSON operators', static function(Context $t): void {
	dp_sql_postgresql_environment_enabled($t);
	$cases=[];
	foreach(['', ' ', "\t", "\n"] as $space){
		$cases[]=[
			"SELECT 'Abc' ILIKE '%'||?{$space}||'%' AS contains, 'abc' LIKE ?{$space}||'%' AS prefix, ?::integer AS marker",
			['b','ab',17],
			['contains'=>true,'prefix'=>true,'marker'=>17],
		];
	}
	$cases[]=["SELECT 'abc' LIKE '%'||?||'%' AS matched",["' OR true --"],['matched'=>false]];
	$cases[]=['SELECT ?||?||? AS joined',['left','-','right'],['joined'=>'left-right']];
	$cases[]=["SELECT 'abc' LIKE ?||'%' AS matched",[null],['matched'=>null]];
	$cases[]=[
		<<<'SQL'
SELECT payload ? ? AS one_key, payload ?| ARRAY[?] AS any_key,
       payload ?& ARRAY[?] AS all_keys, payload @? ? AS path,
       payload ?| ?::text[] AS bound_any, payload ?& ?::text[] AS bound_all,
       ?||? AS joined
FROM (VALUES ('{"alpha":1,"beta":2}'::jsonb)) AS fixture(payload)
SQL,
		['alpha','missing','alpha','$.alpha','{missing,beta}','{alpha,missing}','a','b'],
		['one_key'=>true,'any_key'=>false,'all_keys'=>true,'path'=>true,'bound_any'=>true,'bound_all'=>false,'joined'=>'ab'],
	];
	$cases[]=[
		<<<'SQL'
SELECT '?' AS "?||", '?''||' AS doubled, E'\'?||' AS escaped,
       $$?||$$ AS untagged, $body$?||$body$ AS tagged,
       ?/* ?|| /* ?| */ ?& */||? AS joined -- ?|| @?
SQL,
		['a','b'],
		['?||'=>'?','doubled'=>"?'||",'escaped'=>"'?||",'untagged'=>'?||','tagged'=>'?||','joined'=>'ab'],
	];
	foreach(['live','sandbox'] as $environment){
		DataEnvironment::run($environment,static function() use ($t,$cases): void {
			foreach($cases as [$query,$bindings,$expected]){
				$t->same([$expected],DB::query($query,$bindings,true,true,false,false));
			}
		});
	}
})->tag('sql','postgresql','jsonb','placeholders','compatibility')->maxMillis(15000);

test('PostgreSQL native reads preserve SQL NULL separately from zero and false', static function(Context $t): void {
	dp_sql_postgresql_environment_enabled($t);
	$query=<<<'SQL'
SELECT 1 AS row_index, NULL::integer AS small, NULL::bigint AS large,
       NULL::boolean AS enabled, NULL::numeric AS amount, NULL::text AS label
UNION ALL
SELECT 2, 0::integer, 4294967296::bigint, false::boolean, 123.4500::numeric, ''::text
UNION ALL
SELECT 3, 7::integer, 9::bigint, true::boolean, 0.0000::numeric, 'sample'::text
ORDER BY row_index
SQL;
	$expected=[
		['row_index'=>1,'small'=>null,'large'=>null,'enabled'=>null,'amount'=>null,'label'=>null],
		['row_index'=>2,'small'=>0,'large'=>4294967296,'enabled'=>false,'amount'=>'123.4500','label'=>''],
		['row_index'=>3,'small'=>7,'large'=>9,'enabled'=>true,'amount'=>'0.0000','label'=>'sample'],
	];
	$t->same($expected,dp_sql_postgresql_environment_query($query,null,true));
	$t->same($expected[0],dp_sql_postgresql_environment_query($query));
})->tag('sql','postgresql','null','compatibility')->maxMillis(15000);

test('PostgreSQL ambient routing keeps explicit clusters authoritative', static function(Context $t) use (
	$compatibilityLiveDatabase,
	$compatibilitySandboxDatabase
): void {
	dp_sql_postgresql_environment_enabled($t);
	$t->globalMap('_SESSION')->clear()->put('db_cache_count', 0);
	DataEnvironment::run('live', static function(): void {
		dp_sql_postgresql_environment_reset_markers('dataphyre_sql_environment_routing_markers');
	});
	DataEnvironment::run('sandbox', static function(): void {
		dp_sql_postgresql_environment_reset_markers('dataphyre_sql_environment_routing_markers');
	});

	DataEnvironment::run('sandbox', static function() use (
		$t,
		$compatibilityLiveDatabase,
		$compatibilitySandboxDatabase
	): void {
		$t->same('compatibility_sandbox', DB::connection()->cluster());
		$t->same('compatibility_sandbox', DB::connection(' ')->cluster());
		$t->same('compatibility_live', DB::connection('compatibility_live')->cluster());
		$t->same(
			$compatibilitySandboxDatabase,
			DB::connection()->row('SELECT current_database()')['current_database'] ?? null
		);
		$t->same(
			$compatibilityLiveDatabase,
			DB::connection('compatibility_live')->row('SELECT current_database()')['current_database'] ?? null
		);

		$live=DB::connection('compatibility_live');
		$live->transaction(static function(ConnectionContext $connection) use ($t, $compatibilityLiveDatabase): void {
			$t->same(
				$compatibilityLiveDatabase,
				$connection->row('SELECT current_database()')['current_database'] ?? null
			);
			$connection->query(
				'INSERT INTO dataphyre_sql_environment_routing_markers (id, label) VALUES (?, ?)',
				[7, 'explicit-live-inside-sandbox']
			);
		});
	});

	DataEnvironment::run('live', static function() use ($t): void {
		$t->same([7], dp_sql_postgresql_environment_marker_ids('dataphyre_sql_environment_routing_markers'));
	});
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$t->same([], dp_sql_postgresql_environment_marker_ids('dataphyre_sql_environment_routing_markers'));
	});
	$t->same('live', DataEnvironment::name());
})->tag('sql', 'postgresql', 'transaction', 'data-environment', 'compatibility')->maxMillis(15000);

test('PostgreSQL deferred queues retain registration-time cluster and cache namespace', static function(Context $t): void {
	dp_sql_postgresql_environment_enabled($t);
	$session=$t->globalMap('_SESSION')->clear()->put('db_cache_count', 0);
	DataEnvironment::run('live', static function(): void {
		dp_sql_postgresql_environment_reset_markers('dataphyre_sql_environment_queue_markers');
	});
	DataEnvironment::run('sandbox', static function(): void {
		dp_sql_postgresql_environment_reset_markers('dataphyre_sql_environment_queue_markers');
	});

	$writeCallbacks=[];
	foreach(['live'=>11, 'sandbox'=>22] as $environment=>$id){
		$registerWrite=static function() use (&$writeCallbacks, $environment, $id): void {
			\dataphyre\sql::query(
				['postgresql'=>'INSERT INTO dataphyre_sql_environment_queue_markers (id, label) VALUES (?, ?)'],
				[$id, $environment.'-queued'],
				false,
				false,
				false,
				false,
				'environment-compatibility-write',
				static function(mixed $result) use (&$writeCallbacks): void {
					$writeCallbacks[]=[
						DataEnvironment::name(),
						DataEnvironment::cacheKey('raw'),
						DataEnvironment::active(),
						$result!==false,
					];
				}
			);
		};
		if($environment==='live'){
			$registerWrite();
		}else{
			DataEnvironment::run($environment, $registerWrite);
		}
	}
	$writeExecuted=\dataphyre\sql::execute_queue('environment-compatibility-write');
	$t->isTrue(
		$writeExecuted,
		'Queued writes failed: '.json_encode(\dataphyre\sql::last_query_error(), JSON_UNESCAPED_SLASHES)
	);
	$t->same([
		['live', 'raw', false, true],
		['sandbox', 'compatibility-sandbox::raw', true, true],
	], $writeCallbacks);
	DataEnvironment::run('live', static function() use ($t): void {
		$t->same([11], dp_sql_postgresql_environment_marker_ids('dataphyre_sql_environment_queue_markers'));
	});
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$t->same([22], dp_sql_postgresql_environment_marker_ids('dataphyre_sql_environment_queue_markers'));
	});

	$readCallbacks=[];
	foreach(['live', 'sandbox'] as $environment){
		$registerRead=static function() use (&$readCallbacks): void {
			\dataphyre\sql::query(
				['postgresql'=>'SELECT id FROM dataphyre_sql_environment_queue_markers ORDER BY id'],
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
						'active'=>DataEnvironment::active(),
					];
				}
			);
		};
		if($environment==='live'){
			$registerRead();
		}else{
			DataEnvironment::run($environment, $registerRead);
		}
	}
	$readExecuted=\dataphyre\sql::execute_queue('environment-compatibility-read');
	$t->isTrue(
		$readExecuted,
		'Queued reads failed: '.json_encode(\dataphyre\sql::last_query_error(), JSON_UNESCAPED_SLASHES)
	);
	$t->same(['ids'=>[11], 'cache_key'=>'raw', 'active'=>false], $readCallbacks['live'] ?? null);
	$t->same(
		['ids'=>[22], 'cache_key'=>'compatibility-sandbox::raw', 'active'=>true],
		$readCallbacks['sandbox'] ?? null
	);
	$t->isTrue(is_array($session->getPath(['db_cache', 'raw'])));
	$t->isTrue(is_array($session->getPath(['db_cache', 'compatibility-sandbox::raw'])));

	$crossEnvironmentDrain=[];
	\dataphyre\sql::query(
		['postgresql'=>'SELECT id FROM dataphyre_sql_environment_queue_markers ORDER BY id'],
		null,
		true,
		false,
		false,
		false,
		'environment-compatibility-cross-drain',
		static function(mixed $result) use (&$crossEnvironmentDrain): void {
			$crossEnvironmentDrain=[
				'name'=>DataEnvironment::name(),
				'cache_key'=>DataEnvironment::cacheKey('raw'),
				'active'=>DataEnvironment::active(),
				'ids'=>array_map(static fn(array $row): int=>(int)$row['id'], is_array($result) ? $result : []),
			];
		}
	);
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$t->isTrue(\dataphyre\sql::execute_queue('environment-compatibility-cross-drain'));
		$t->same('sandbox', DataEnvironment::name());
	});
	$t->same(
		['name'=>'live', 'cache_key'=>'raw', 'active'=>true, 'ids'=>[11]],
		$crossEnvironmentDrain
	);
})->tag('sql', 'postgresql', 'queue', 'cache', 'data-environment', 'compatibility')->maxMillis(15000);

test('PostgreSQL schema hydration remains isolated to the ambient environment', static function(Context $t): void {
	dp_sql_postgresql_environment_enabled($t);
	$t->globalMap('_SESSION')->clear()->put('db_cache_count', 0);
	foreach(['live', 'sandbox'] as $environment){
		DataEnvironment::run($environment, static function(): void {
			dp_sql_postgresql_environment_query('DROP TABLE IF EXISTS dataphyre_sql_environment_hydrated');
		});
	}

	$definition=TableDefinition::for('dataphyre_sql_environment_hydrated')
		->autoIncrement('id')
		->string('label', 64)->notNull();
	DataEnvironment::run('sandbox', static function() use ($definition, $t): void {
		$t->isTrue($definition->hydrate());
		$t->isTrue(
			TableDefinition::for('dataphyre_sql_environment_hydrated')
				->string('note', 64)
				->hydrateColumn('note')
		);
	});
	DataEnvironment::run('live', static function() use ($t): void {
		$row=dp_sql_postgresql_environment_query(
			'SELECT to_regclass(?) AS relation_name',
			['dataphyre_sql_environment_hydrated']
		);
		$t->same(null, $row['relation_name'] ?? null);
	});
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$row=dp_sql_postgresql_environment_query(
			'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
			['public', 'dataphyre_sql_environment_hydrated', 'note']
		);
		$t->same('note', $row['column_name'] ?? null);
	});
})->tag('sql', 'postgresql', 'schema', 'hydration', 'data-environment', 'compatibility')->maxMillis(15000);
