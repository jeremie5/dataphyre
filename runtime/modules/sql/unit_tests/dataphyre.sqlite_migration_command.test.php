<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\SqliteMigrationCommand;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

$dpSqliteCommandRoot=dirname(__DIR__);
require_once $dpSqliteCommandRoot.'/Framework/Migrations/SqliteMigrationProfile.php';
require_once $dpSqliteCommandRoot.'/Framework/Migrations/SqliteMigrationManifest.php';
require_once $dpSqliteCommandRoot.'/Framework/Migrations/SqliteMigrationCommand.php';

function dp_sqlite_command_fixture(Context $test, string $name, ?string $sql=null): TempWorkspace {
	$workspace=$test->workspace('sqlite-migration-command-'.$name);
	$sql=$sql ?? <<<'SQL'
CREATE TABLE items (
	id INTEGER PRIMARY KEY,
	name TEXT NOT NULL,
	updated_count INTEGER NOT NULL DEFAULT 0
);
CREATE TRIGGER items_updated
AFTER UPDATE OF name ON items
FOR EACH ROW
BEGIN
	UPDATE items SET updated_count = OLD.updated_count + 1 WHERE id = NEW.id;
END;
INSERT INTO items (id, name) VALUES (1, 'first');
SQL;
	$profile=[
		'format'=>1,
		'application_id'=>'fixture',
		'database_file'=>'database.sqlite',
		'journal_table'=>'dataphyre_schema_migrations',
	];
	$manifest=[
		'schema_version'=>1,
		'algorithm'=>'sha256',
		'migrations'=>[[
			'id'=>'001_initial',
			'path'=>'001_initial.sql',
			'sha256'=>hash('sha256',$sql),
		]],
	];
	$workspace->file('database/sqlite/profile.json', json_encode($profile,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$workspace->file('database/sqlite/manifest.json', json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$workspace->file('database/sqlite/001_initial.sql', $sql."\n");
	$manifest['migrations'][0]['sha256']=hash('sha256',$sql."\n");
	$workspace->file('database/sqlite/manifest.json', json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$workspace->directory('application-data');
	return $workspace;
}

/** @param list<string> $arguments @param array<string,mixed> $runtime @return array{status:int,out:string,error:string,payload:array<string,mixed>} */
function dp_sqlite_command_run(array $arguments, array $runtime=[]): array {
	$out='';
	$error='';
	$status=SqliteMigrationCommand::main($arguments, array_replace($runtime, [
		'write_out'=>static function(string $value) use (&$out): int {$out.=$value;return strlen($value);},
		'write_error'=>static function(string $value) use (&$error): int {$error.=$value;return strlen($value);},
	]));
	$payload=json_decode($out!=='' ? $out : $error, true, 64, JSON_THROW_ON_ERROR);
	return ['status'=>$status, 'out'=>$out, 'error'=>$error, 'payload'=>$payload];
}

/** @return list<string> */
function dp_sqlite_command_arguments(TempWorkspace $workspace, bool $dryRun=false): array {
	return [
		'sqlite_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=staging',
		...($dryRun ? ['--dry-run'] : []),
	];
}

/** @return array<string,string> */
function dp_sqlite_command_environment(TempWorkspace $workspace): array {
	return [
		'DATAPHYRE_APPLICATION_DATA_ROOT'=>$workspace->path('application-data'),
		'DATAPHYRE_ENVIRONMENT'=>'staging',
	];
}

function dp_sqlite_command_append_migration(TempWorkspace $workspace,string $id,string $sql): void {
	$sql=rtrim($sql,"\r\n")."\n";
	$path=$id.'.sql';
	$manifest=json_decode(
		(string)file_get_contents($workspace->path('database/sqlite/manifest.json')),
		true,
		32,
		JSON_THROW_ON_ERROR,
	);
	$manifest['migrations'][]=['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$sql)];
	$workspace->file('database/sqlite/'.$path,$sql);
	$workspace->file(
		'database/sqlite/manifest.json',
		json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",
	);
}

test('SQLite migration command shares the broad public deployment environment grammar',static function(Context $t): void {
	$command=$t->nonPublic(SqliteMigrationCommand::class);
	foreach(['staging_blue','Staging.Blue'] as $environment){
		$options=$command->invoke('options',[
			'sqlite_migrate.php','--project-root=/tmp','--app=fixture','--environment='.$environment,'--dry-run',
		]);
		$t->same($environment,$options['environment'],$environment);
	}
	foreach(['.','..',"staging\nblue","staging\0blue",str_repeat('a',129)] as $environment){
		$t->throws(
			static fn()=>$command->invoke('options',[
				'sqlite_migrate.php','--project-root=/tmp','--app=fixture','--environment='.$environment,'--dry-run',
			]),
			InvalidArgumentException::class,
			bin2hex($environment),
		);
	}
})->tag('sql','sqlite','migration','environment-identifier','broad-grammar','negative');

test('SQLite migration command applies a fresh SQL-only manifest atomically and reruns idempotently', static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'fresh');
	$runtime=['environment_values'=>dp_sqlite_command_environment($workspace)];
	$expectedManifestSha=hash_file('sha256',$workspace->path('database/sqlite/manifest.json'));
	$first=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
	$second=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);

	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$first['status']);
	$t->same('', $first['error']);
	$t->same(true,$first['payload']['ok']);
	$t->same('dataphyre.sqlite_migration_command.v1',$first['payload']['contract']);
	$t->same(['001_initial'],$first['payload']['result']['applied_migrations']);
	$t->same([],$first['payload']['result']['pending_migrations']);
	$t->same('database.sqlite',$first['payload']['result']['database_file']);
	$t->same(false,$first['payload']['result']['dry_run']);
	$t->same(['001_initial'],$second['payload']['result']['applied_migrations']);
	$t->same([],$second['payload']['result']['pending_migrations']);

	$pdo=new PDO('sqlite:'.$workspace->path('application-data/database.sqlite'));
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
	$t->same('first',$pdo->query('SELECT name FROM items WHERE id=1')->fetchColumn());
	$pdo->exec("UPDATE items SET name='second' WHERE id=1");
	$t->same('1',(string)$pdo->query('SELECT updated_count FROM items WHERE id=1')->fetchColumn());
	$journal=$pdo->query('SELECT migration_id, sha256 FROM dataphyre_schema_migrations')->fetch();
	$t->same('001_initial',$journal['migration_id'] ?? null);
	$t->same($first['payload']['manifest']['migration_count'],1);
	$t->same(64,strlen((string)$first['payload']['manifest']['sha256']));
	$t->same($expectedManifestSha,$first['payload']['manifest']['sha256']);
	$t->isFalse(str_contains($first['out'],$workspace->root()));
})->tag('sql','sqlite','migration','cli','security','idempotence')->group('framework-coverage');

test('SQLite rolling migrations accept only additive tables indexes and nullable columns',static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'rolling-additive');
	dp_sqlite_command_append_migration($workspace,'002_expand',<<<'SQL'
CREATE TABLE item_notes (
	id INTEGER PRIMARY KEY,
	item_id INTEGER,
	note TEXT
);
CREATE INDEX item_notes_item_id ON item_notes(item_id, id);
ALTER TABLE items ADD COLUMN description TEXT NULL;
SQL);
	$runtime=['environment_values'=>dp_sqlite_command_environment($workspace)];
	$first=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
	$second=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$first['status']);
	$t->same(['001_initial','002_expand'],$first['payload']['result']['applied_migrations']);
	$t->same([],$first['payload']['result']['pending_migrations']);
	$t->same(['001_initial','002_expand'],$second['payload']['result']['applied_migrations']);
	$t->same([],$second['payload']['result']['pending_migrations']);

	$pdo=new PDO('sqlite:'.$workspace->path('application-data/database.sqlite'));
	$t->same('first',$pdo->query('SELECT name FROM items WHERE id=1')->fetchColumn());
	$t->same(1,(int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info('items') WHERE name='description'")->fetchColumn());
	$t->same(1,(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='item_notes'")->fetchColumn());
	$t->same(1,(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='item_notes_item_id'")->fetchColumn());
	$t->same(2,(int)$pdo->query('SELECT COUNT(*) FROM dataphyre_schema_migrations')->fetchColumn());
})->tag('sql','sqlite','migration','rolling','expand-only','idempotence')->group('framework-coverage');

test('SQLite rolling migrations reject destructive and behavioral SQL before opening a database',static function(Context $t): void {
	$cases=[
		'drop-table'=>'DROP TABLE items;',
		'delete'=>'DELETE FROM items;',
		'insert'=>'INSERT INTO items(id,name,updated_count) VALUES (2,\'second\',0);',
		'rename-table'=>'ALTER TABLE items RENAME TO renamed_items;',
		'rename-column'=>'ALTER TABLE items RENAME COLUMN name TO label;',
		'drop-column'=>'ALTER TABLE items DROP COLUMN name;',
		'unique-index'=>'CREATE UNIQUE INDEX items_name_unique ON items(name);',
		'conditional-index'=>'CREATE INDEX IF NOT EXISTS items_name ON items(name);',
		'conditional-table'=>'CREATE TABLE IF NOT EXISTS extras(id INTEGER);',
		'create-as-select'=>'CREATE TABLE copied AS SELECT * FROM items;',
		'trigger'=>'CREATE TRIGGER changed AFTER UPDATE ON items BEGIN SELECT 1; END;',
		'view'=>'CREATE VIEW item_names AS SELECT name FROM items;',
		'not-null-column'=>'ALTER TABLE items ADD COLUMN required_value TEXT NOT NULL;',
		'default-column'=>"ALTER TABLE items ADD COLUMN created_value TEXT DEFAULT 'now';",
		'constrained-column'=>'ALTER TABLE items ADD COLUMN state TEXT CHECK(state IN (\'a\',\'b\'));',
	];
	foreach($cases as $name=>$sql){
		$workspace=dp_sqlite_command_fixture($t,'rolling-reject-'.$name);
		dp_sqlite_command_append_migration($workspace,'002_rejected',$sql);
		$opened=false;
		$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),[
			'environment_values'=>dp_sqlite_command_environment($workspace),
			'pdo_factory'=>static function(string $dsn,array $attributes) use (&$opened): PDO {
				$opened=true;
				throw new RuntimeException('The SQL preflight must run before PDO is opened.');
			},
		]);
		$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status'],$name.' exit');
		$t->same('manifest_invalid',$run['payload']['error']['code'],$name.' code');
		$t->same(false,$opened,$name.' PDO');
		$t->same(false,file_exists($workspace->path('application-data/database.sqlite')),$name.' database');
	}
})->tag('sql','sqlite','migration','rolling','expand-only','fail-closed')->group('framework-coverage');

test('SQLite migration dry run validates a fresh manifest without creating a database', static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'dry-run');
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace,true),[
		'environment_values'=>dp_sqlite_command_environment($workspace),
	]);
	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$run['status']);
	$t->same(true,$run['payload']['result']['dry_run']);
	$t->same([],$run['payload']['result']['applied_migrations']);
	$t->same(['001_initial'],$run['payload']['result']['pending_migrations']);
	$t->same(false,file_exists($workspace->path('application-data/database.sqlite')));
})->tag('sql','sqlite','migration','dry-run','read-only')->group('framework-coverage');

test('SQLite migration command rejects filesystem escape special files and unlisted SQL', static function(Context $t): void {
	$linked=dp_sqlite_command_fixture($t,'linked-sql');
	$target=$linked->file('outside.sql',"CREATE TABLE escaped(id INTEGER);\n");
	$t->same(true,unlink($linked->path('database/sqlite/001_initial.sql')));
	$t->same(true,symlink($target,$linked->path('database/sqlite/001_initial.sql')));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($linked),['environment_values'=>dp_sqlite_command_environment($linked)]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);
	$t->same('manifest_invalid',$run['payload']['error']['code']);

	$hardLinked=dp_sqlite_command_fixture($t,'hard-linked-sql');
	$hardLinkTarget=$hardLinked->file('outside-hard-link.sql',(string)file_get_contents($hardLinked->path('database/sqlite/001_initial.sql')));
	$t->same(true,unlink($hardLinked->path('database/sqlite/001_initial.sql')));
	$t->same(true,link($hardLinkTarget,$hardLinked->path('database/sqlite/001_initial.sql')));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($hardLinked),['environment_values'=>dp_sqlite_command_environment($hardLinked)]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);
	$t->same('manifest_invalid',$run['payload']['error']['code']);

	$unlisted=dp_sqlite_command_fixture($t,'unlisted-sql');
	$unlisted->file('database/sqlite/002_hidden.sql',"CREATE TABLE hidden(id INTEGER);\n");
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($unlisted),['environment_values'=>dp_sqlite_command_environment($unlisted)]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);

	$hidden=dp_sqlite_command_fixture($t,'hidden-unlisted-sql');
	$hidden->file('database/sqlite/.hidden.sql',"CREATE TABLE hidden_dotfile(id INTEGER);\n");
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($hidden),['environment_values'=>dp_sqlite_command_environment($hidden)]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);

	$unlistedLink=dp_sqlite_command_fixture($t,'unlisted-linked-sql');
	$unlistedLinkTarget=$unlistedLink->file('unlisted-link-target.sql',"CREATE TABLE linked_extra(id INTEGER);\n");
	$t->same(true,symlink($unlistedLinkTarget,$unlistedLink->path('database/sqlite/002_link.sql')));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($unlistedLink),['environment_values'=>dp_sqlite_command_environment($unlistedLink)]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);

	$linkedRoot=dp_sqlite_command_fixture($t,'linked-data-root');
	$outside=$linkedRoot->directory('outside-data');
	$t->same(true,rmdir($linkedRoot->path('application-data')));
	$t->same(true,symlink($outside,$linkedRoot->path('application-data')));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($linkedRoot),['environment_values'=>dp_sqlite_command_environment($linkedRoot)]);
	$t->same(SqliteMigrationCommand::EXIT_CONFIGURATION,$run['status']);
	$t->same('application_data_root_invalid',$run['payload']['error']['code']);

	$hardLinkedDatabase=dp_sqlite_command_fixture($t,'hard-linked-database');
	$outsideDatabase=$hardLinkedDatabase->path('outside.sqlite');
	$pdo=new PDO('sqlite:'.$outsideDatabase);
	$pdo=null;
	$t->same(true,link($outsideDatabase,$hardLinkedDatabase->path('application-data/database.sqlite')));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($hardLinkedDatabase),['environment_values'=>dp_sqlite_command_environment($hardLinkedDatabase)]);
	$t->same(SqliteMigrationCommand::EXIT_CONFIGURATION,$run['status']);
	$t->same('application_data_root_invalid',$run['payload']['error']['code']);

	if(function_exists('posix_mkfifo')){
		$special=dp_sqlite_command_fixture($t,'special-sql');
		$path=$special->path('database/sqlite/001_initial.sql');
		$t->same(true,unlink($path));
		$t->same(true,posix_mkfifo($path,0600));
		$run=dp_sqlite_command_run(dp_sqlite_command_arguments($special),['environment_values'=>dp_sqlite_command_environment($special)]);
		$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);
		$t->same(true,unlink($path));

		$unlistedSpecial=dp_sqlite_command_fixture($t,'unlisted-special-sql');
		$specialPath=$unlistedSpecial->path('database/sqlite/.special.sql');
		$t->same(true,posix_mkfifo($specialPath,0600));
		$run=dp_sqlite_command_run(dp_sqlite_command_arguments($unlistedSpecial),['environment_values'=>dp_sqlite_command_environment($unlistedSpecial)]);
		$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);
		$t->same(true,unlink($specialPath));
	}
})->tag('sql','sqlite','migration','filesystem','security')->group('framework-coverage');

test('SQLite migration SQL rejects filesystem extension virtual-table journal and transaction primitives', static function(Context $t): void {
	$cases=[
		'attach'=>"ATTACH DATABASE '/tmp/outside.sqlite' AS outside;\n",
		'detach'=>"DETACH DATABASE outside;\n",
		'pragma'=>"PRAGMA writable_schema=ON;\n",
		'vacuum'=>"VACUUM INTO '/tmp/copy.sqlite';\n",
		'extension'=>"SELECT load_extension('/tmp/tenant.so');\n",
		'virtual-table'=>"CREATE VIRTUAL TABLE files USING csv(filename='/etc/passwd');\n",
		'journal'=>"DELETE FROM dataphyre_schema_migrations;\n",
		'quoted-journal'=>"DELETE FROM \"dataphyre_schema_migrations\";\n",
		'single-quoted-journal'=>"DROP TABLE 'dataphyre_schema_migrations';\n",
		'temporary-table'=>"CREATE TEMP TABLE ephemeral(id INTEGER);\n",
		'transaction'=>"BEGIN IMMEDIATE; CREATE TABLE unsafe(id INTEGER); COMMIT;\n",
	];
	foreach($cases as $name=>$sql){
		$workspace=dp_sqlite_command_fixture($t,'forbidden-'.$name,$sql);
		$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>dp_sqlite_command_environment($workspace)]);
		$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status'],$name.' exit');
		$t->same('manifest_invalid',$run['payload']['error']['code'],$name.' code');
		$t->same(false,file_exists($workspace->path('application-data/database.sqlite')),$name.' no database');
	}
})->tag('sql','sqlite','migration','security','sql-only')->group('framework-coverage');

test('SQLite manifest enforces aggregate bytes and counts semicolon and quoted tokens',static function(Context $t): void {
	$semicolonSql="CREATE TABLE bounded(id INTEGER);\n".str_repeat(';',250001);
	$semicolon=dp_sqlite_command_fixture($t,'semicolon-token-bound',$semicolonSql);
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($semicolon),[
		'environment_values'=>dp_sqlite_command_environment($semicolon),
	]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);

	$quotedSql='SELECT '.implode(',',array_fill(0,250001,'"x"')).";\n";
	$quoted=dp_sqlite_command_fixture($t,'quoted-token-bound',$quotedSql);
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($quoted),[
		'environment_values'=>dp_sqlite_command_environment($quoted),
	]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);

	$aggregate=dp_sqlite_command_fixture($t,'aggregate-byte-bound');
	$entries=[];
	for($index=1;$index<=5;$index++){
		$id=str_pad((string)$index,3,'0',STR_PAD_LEFT).'_bounded_'.$index;
		$sql=($index===1 ? "CREATE TABLE aggregate_values(value TEXT);\n" : '')
			."INSERT INTO aggregate_values(value) VALUES ('".str_repeat('a',1800000)."');\n";
		$path=$id.'.sql';
		$aggregate->file('database/sqlite/'.$path,$sql);
		$entries[]=['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$sql)];
	}
	$t->same(true,unlink($aggregate->path('database/sqlite/001_initial.sql')));
	$aggregate->file('database/sqlite/manifest.json',json_encode([
		'schema_version'=>1,'algorithm'=>'sha256','migrations'=>$entries,
	],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($aggregate),[
		'environment_values'=>dp_sqlite_command_environment($aggregate),
	]);
	$t->same(SqliteMigrationCommand::EXIT_MANIFEST,$run['status']);
})->tag('sql','sqlite','migration','bounds','tokens','security')->group('framework-coverage');

test('SQLite manifest accepts exactly 999 contiguous migrations and rejects a 1000th',static function(Context $t): void {
	$workspace=$t->workspace('sqlite-migration-count-boundary');
	$workspace->file('database/sqlite/profile.json',json_encode([
		'format'=>1,
		'application_id'=>'fixture',
		'database_file'=>'database.sqlite',
		'journal_table'=>'dataphyre_schema_migrations',
	],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$entries=[];
	for($index=1;$index<=999;$index++){
		$id=str_pad((string)$index,3,'0',STR_PAD_LEFT).'_boundary';
		$sql='SELECT '.$index.";\n";
		$path=$id.'.sql';
		$workspace->file('database/sqlite/'.$path,$sql);
		$entries[]=['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$sql)];
	}
	$writeManifest=static function(array $migrations) use ($workspace): void {
		$workspace->file('database/sqlite/manifest.json',json_encode([
			'schema_version'=>1,
			'algorithm'=>'sha256',
			'migrations'=>$migrations,
		],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	};
	$writeManifest($entries);
	$profile=\Dataphyre\Database\Migrations\SqliteMigrationProfile::load($workspace->root(),'fixture');
	$t->same(999,count(\Dataphyre\Database\Migrations\SqliteMigrationManifest::load(
		$workspace->root(),
		$profile,
	)->entries()));

	$sql="SELECT 1000;\n";
	$workspace->file('database/sqlite/1000_boundary.sql',$sql);
	$entries[]=['id'=>'1000_boundary','path'=>'1000_boundary.sql','sha256'=>hash('sha256',$sql)];
	$writeManifest($entries);
	$t->throws(
		static fn()=>\Dataphyre\Database\Migrations\SqliteMigrationManifest::load($workspace->root(),$profile),
		InvalidArgumentException::class,
	);
})->tag('sql','sqlite','migration','bounds','manifest')->group('framework-coverage');

test('SQLite connection proves the opened main file after a symlink race',static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'opened-file-identity');
	$outside=$workspace->path('outside.sqlite');
	$outsidePdo=new PDO('sqlite:'.$outside);
	$outsidePdo->exec('CREATE TABLE preserved(value TEXT)');
	$outsidePdo=null;
	$database=$workspace->path('application-data/database.sqlite');
	$runtime=[
		'environment_values'=>dp_sqlite_command_environment($workspace),
		'pdo_factory'=>static function(string $dsn,array $attributes) use ($database,$outside): PDO {
			if(file_exists($database) || is_link($database)) unlink($database);
			symlink($outside,$database);
			return new PDO($dsn,null,null,$attributes);
		},
	];
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
	$t->same(SqliteMigrationCommand::EXIT_DATABASE,$run['status']);
	$t->same('database_unavailable',$run['payload']['error']['code']);
	$source=(string)file_get_contents(dirname(__DIR__).'/Framework/Migrations/SqliteMigrationCommand.php');
	$manifestSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Migrations/SqliteMigrationManifest.php');
	$t->contains("PRAGMA database_list",$source);
	$t->isFalse(str_contains($source,'nofollow=1'));
	$t->contains("hash('sha256',$" . 'manifestBytes)',$manifestSource);
	$t->isFalse(str_contains($manifestSource,"hash_file('sha256', $" . 'manifestPath)'));
	$t->contains("$" . "pdo->exec('BEGIN IMMEDIATE')",$source);
	$t->greaterThan(
		strpos($source,"$" . "pdo->exec('BEGIN IMMEDIATE')"),
		strrpos($source,'applicationObjectCount'),
	);
})->tag('sql','sqlite','migration','filesystem','identity','race','security')->group('framework-coverage');

test('SQLite migration command refuses silent adoption of an existing unjournaled database', static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'legacy-adoption');
	$path=$workspace->path('application-data/database.sqlite');
	$pdo=new PDO('sqlite:'.$path);
	$pdo->exec('CREATE TABLE legacy_customer_data(id INTEGER PRIMARY KEY, value TEXT)');
	$pdo->exec("INSERT INTO legacy_customer_data(value) VALUES ('preserve-me')");
	$pdo=null;
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>dp_sqlite_command_environment($workspace)]);
	$t->same(SqliteMigrationCommand::EXIT_MIGRATION,$run['status']);
	$t->same('legacy_database_requires_one_time_migration',$run['payload']['error']['code']);
	$pdo=new PDO('sqlite:'.$path);
	$t->same('preserve-me',$pdo->query('SELECT value FROM legacy_customer_data')->fetchColumn());
	$t->same(false,(bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='dataphyre_schema_migrations'")->fetchColumn());
})->tag('sql','sqlite','migration','legacy','fail-closed')->group('framework-coverage');

test('SQLite migration command rejects a nonzero initialized database with no objects or journal unchanged',static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'initialized-empty-drift');
	$path=$workspace->path('application-data/database.sqlite');
	$pdo=new PDO('sqlite:'.$path);
	$pdo->exec('VACUUM');
	$pdo=null;
	$before=(string)file_get_contents($path);
	$t->greaterThan(0,strlen($before));
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>dp_sqlite_command_environment($workspace)]);
	$t->same(SqliteMigrationCommand::EXIT_MIGRATION,$run['status']);
	$t->same('legacy_database_requires_one_time_migration',$run['payload']['error']['code']);
	$t->same($before,(string)file_get_contents($path));
	$pdo=new PDO('sqlite:'.$path);
	$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn());
})->tag('sql','sqlite','migration','legacy','empty-database','fail-closed')->group('framework-coverage');

test('SQLite migration command treats an exact zero-byte rollback residue as logically fresh',static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'zero-byte-residue');
	$path=$workspace->file('application-data/database.sqlite','');
	$dry=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace,true),['environment_values'=>dp_sqlite_command_environment($workspace)]);
	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$dry['status']);
	$t->same(['001_initial'],$dry['payload']['result']['pending_migrations']);
	$t->same(0,filesize($path));
	$apply=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>dp_sqlite_command_environment($workspace)]);
	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$apply['status']);
	$t->same(['001_initial'],$apply['payload']['result']['applied_migrations']);
})->tag('sql','sqlite','migration','zero-byte','rollback','idempotence')->group('framework-coverage');

test('SQLite migration command rejects an empty journal beside an existing application schema',static function(Context $t): void {
	$workspace=dp_sqlite_command_fixture($t,'empty-journal-drift');
	$path=$workspace->path('application-data/database.sqlite');
	$pdo=new PDO('sqlite:'.$path);
	$pdo->exec('CREATE TABLE "dataphyre_schema_migrations" (migration_id TEXT PRIMARY KEY, sha256 TEXT NOT NULL CHECK(length(sha256)=64), applied_at TEXT NOT NULL)');
	$pdo->exec('CREATE TABLE existing_application_data(id INTEGER PRIMARY KEY, value TEXT)');
	$pdo->exec("INSERT INTO existing_application_data(value) VALUES ('preserve-me')");
	$pdo=null;
	$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>dp_sqlite_command_environment($workspace)]);
	$t->same(SqliteMigrationCommand::EXIT_MIGRATION,$run['status']);
	$t->same('migration_failed',$run['payload']['error']['code']);
	$pdo=new PDO('sqlite:'.$path);
	$t->same('preserve-me',$pdo->query('SELECT value FROM existing_application_data')->fetchColumn());
	$t->same(0,(int)$pdo->query('SELECT COUNT(*) FROM dataphyre_schema_migrations')->fetchColumn());
})->tag('sql','sqlite','migration','journal','drift','fail-closed')->group('framework-coverage');

test('SQLite migration command rejects journal shape indexes and triggers before a rolling apply',static function(Context $t): void {
	$cases=[
		'index'=>'CREATE INDEX unexpected_journal_index ON dataphyre_schema_migrations(applied_at)',
		'trigger'=>"CREATE TRIGGER suppress_journal_insert BEFORE INSERT ON dataphyre_schema_migrations BEGIN SELECT RAISE(IGNORE); END",
	];
	foreach($cases as $name=>$driftSql){
		$workspace=dp_sqlite_command_fixture($t,'journal-drift-'.$name);
		$runtime=['environment_values'=>dp_sqlite_command_environment($workspace)];
		$initial=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
		$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$initial['status'],$name.' bootstrap');
		dp_sqlite_command_append_migration($workspace,'002_expand','ALTER TABLE items ADD COLUMN description TEXT NULL;');
		$path=$workspace->path('application-data/database.sqlite');
		$pdo=new PDO('sqlite:'.$path);
		$pdo->exec($driftSql);
		$pdo=null;
		$run=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),$runtime);
		$t->same(SqliteMigrationCommand::EXIT_MIGRATION,$run['status'],$name.' exit');
		$t->same('migration_failed',$run['payload']['error']['code'],$name.' code');
		$pdo=new PDO('sqlite:'.$path);
		$t->same(1,(int)$pdo->query('SELECT COUNT(*) FROM dataphyre_schema_migrations')->fetchColumn(),$name.' journal');
		$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info('items') WHERE name='description'")->fetchColumn(),$name.' rollback');
	}
})->tag('sql','sqlite','migration','journal','trigger','drift','atomicity')->group('framework-coverage');

test('SQLite migration command rejects tenant scripts commands paths and mismatched host context', static function(Context $t): void {
	foreach([
		['sqlite_migrate.php'],
		['sqlite_migrate.php','apply'],
		['sqlite_migrate.php','--script=release.sh'],
		['sqlite_migrate.php','--command=php api/tools/database.php migrate'],
		['sqlite_migrate.php','--database=/tmp/tenant.sqlite'],
		['sqlite_migrate.php','--project-root=/tmp','--app=../../tenant','--environment=production'],
	] as $arguments){
		$run=dp_sqlite_command_run($arguments);
		$t->same(SqliteMigrationCommand::EXIT_USAGE,$run['status']);
		$t->same('invalid_invocation',$run['payload']['error']['code']);
	}
	$workspace=dp_sqlite_command_fixture($t,'host-context');
	$missing=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>[]]);
	$t->same(SqliteMigrationCommand::EXIT_CONFIGURATION,$missing['status']);
	$mismatch=dp_sqlite_command_run(dp_sqlite_command_arguments($workspace),['environment_values'=>[
		'DATAPHYRE_APPLICATION_DATA_ROOT'=>$workspace->path('application-data'),
		'DATAPHYRE_ENVIRONMENT'=>'production',
	]]);
	$t->same(SqliteMigrationCommand::EXIT_CONFIGURATION,$mismatch['status']);

	$help=dp_sqlite_command_run(['sqlite_migrate.php','--help']);
	$t->same(SqliteMigrationCommand::EXIT_SUCCESS,$help['status']);
	$t->contains('DATAPHYRE_APPLICATION_DATA_ROOT',implode(' ',$help['payload']['required_environment']));
	$source=(string)file_get_contents(dirname(__DIR__).'/Framework/Migrations/SqliteMigrationCommand.php');
	$entrypoint=(string)file_get_contents(dirname(__DIR__).'/kernel/sqlite_migrate.php');
	foreach(['proc_open','shell_exec','passthru','system(','popen','release.sh','api/tools/database.php'] as $forbidden){
		$t->isFalse(str_contains($source,$forbidden));
		$t->isFalse(str_contains($entrypoint,$forbidden));
	}
})->tag('sql','sqlite','migration','cli','security','boundary')->group('framework-coverage');
