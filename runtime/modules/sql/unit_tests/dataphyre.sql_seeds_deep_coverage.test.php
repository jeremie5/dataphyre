<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Seeds\InMemorySeedLedger;
use Dataphyre\Database\Seeds\SeedContext;
use Dataphyre\Database\Seeds\SeedDefinition;
use Dataphyre\Database\Seeds\SeedExecutionException;
use Dataphyre\Database\Seeds\SeedFileLoader;
use Dataphyre\Database\Seeds\SeedManager;
use Dataphyre\Database\Seeds\SeedRuntimeFixture;
use Dataphyre\Database\Seeds\SqlSeedLedger;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\define_test_symbols;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

$dpSeedRoot=dirname(__DIR__);
require_once __DIR__.'/fixtures/seed_failure_seams.php';
foreach(['SeedDefinition','SeedContext','SeedExecutionException','SeedLedger','InMemorySeedLedger','SqlSeedLedger','SeedFileLoader','SeedManager'] as $dpSeedClass){
	require_once $dpSeedRoot.'/Framework/Seeds/'.$dpSeedClass.'.php';
}
require_once $dpSeedRoot.'/kernel/seeds.php';

suite('SQL seed discovery, execution, and ledger contracts')
	->contract('sql.seed-lifecycle', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:sql')
	->through('seed-definition', 'file-loader', 'dependency-graph', 'seed-manager', 'ledger', 'rollback', 'cli')
	->isolation('case')
	->tag('sql', 'seeds')
	->group('framework-coverage');

/** Defines the smallest SQL facade needed by native seed-context tests. */
function dp_seed_define_sql_facade(): void {
	if(class_exists('dataphyre\\sql', false)){
		return;
	}
	define_test_symbols(<<<'PHP'
namespace dataphyre;

final class sql {
	public static function query(mixed ...$arguments): mixed {
		$state=\Dataphyre\Test\TestState::channel('sql.seed-native');
		$state->append('sql_calls', $arguments);
		return $state->get('sql_result', true);
	}

	public static function query_write_targets(string $query, bool $registeredOnly=false): array {
		return \Dataphyre\Test\TestState::channel('sql.seed-native')->get('write_targets', []);
	}

	public static function invalidate_cache(array|string $targets): bool {
		\Dataphyre\Test\TestState::channel('sql.seed-native')->append('invalidations', $targets);
		return true;
	}

	public static function last_query_error(): mixed {
		return \Dataphyre\Test\TestState::channel('sql.seed-native')->get('sql_error');
	}
}
PHP);
}

/** Defines a deterministic stand-in for Dataphyre's connection-scoped SQL API. */
function dp_seed_define_connection_context(): void {
	if(class_exists('Dataphyre\\Database\\ConnectionContext', false)){
		return;
	}
	define_test_symbols(<<<'PHP'
namespace Dataphyre\Database;

final class ConnectionContext {
	public function __construct(private ?string $cluster=null) {}

	public function dbms(): string {
		return (string)\Dataphyre\Test\TestState::channel('sql.seed-native')->get('dbms', 'PostgreSQL');
	}

	public function query(mixed $query, ?array $vars=null, bool $associative=false, mixed ...$arguments): mixed {
		$state=\Dataphyre\Test\TestState::channel('sql.seed-native');
		$rendered=json_encode($query, JSON_THROW_ON_ERROR);
		if(str_contains($rendered, 'SELECT lock_id')){
			return $state->get('lock_rows', [['lock_id'=>1]]);
		}
		if(str_contains($rendered, 'SELECT seed_id')){
			return $state->get('ledger_rows', []);
		}
		$state->append('queries', [$query, $vars, $associative]);
		return $state->get('query_result', true);
	}

	public function transaction(callable $callback): mixed {
		\Dataphyre\Test\TestState::channel('sql.seed-native')->increment('transactions');
		return $callback();
	}
}
PHP);
}

test('SQL seed value objects expose every validation and native context outcome', static function(Context $t): void {
	$memory=new InMemorySeedLedger();
	$t->throws(static fn()=>$memory->recordApplied(['id'=>'broken']), RuntimeException::class);
	$record=['id'=>'demo','version'=>1,'checksum'=>str_repeat('a', 64),'batch'=>7,'applied_at'=>'now'];
	$memory->recordApplied($record);
	$t->same(8, $memory->nextBatch());
	$t->throws(static fn()=>$memory->recordApplied($record), RuntimeException::class);

	$t->throws(static fn()=>(new SeedContext())->query('SELECT 1'), RuntimeException::class);
	$native=$t->state('sql.seed-native', [
		'sql_result'=>false,
		'sql_error'=>['message'=>'native failure'],
		'sql_calls'=>[],
		'write_targets'=>[],
		'invalidations'=>[],
	]);
	dp_seed_define_sql_facade();
	$t->throws(
		static fn()=>(new SeedContext())->query('SELECT 1'),
		RuntimeException::class,
		'native failure',
	);
	$native->put('sql_result', [['ok'=>true]]);
	$t->same([['ok'=>true]], (new SeedContext())->query('SELECT 1'));
	$native->put('write_targets', ['fixture.orders']);
	$t->same([['ok'=>true]], (new SeedContext())->query('UPDATE fixture.orders SET status=?', ['paid']));
	$calls=$native->get('sql_calls');
	$t->same(['fixture.orders'], $calls[array_key_last($calls)][5] ?? null);
	(new SeedContext(static fn()=>true))->query('UPDATE fixture.orders SET status=?', ['paid']);
	$t->same([['fixture.orders']], $native->get('invalidations'));
	(new SeedContext(static fn()=>true))->query('UPDATE fixture.orders SET status=?', ['paid'], false, false);
	$t->same([['fixture.orders']], $native->get('invalidations'));

	if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG', ['datacenter'=>'coverage']);
	if(!defined('DP_SQL_CFG')) define('DP_SQL_CFG', [
		'default_cluster'=>'primary',
		'datacenters'=>['coverage'=>['dbms_clusters'=>['primary'=>['dbms'=>'MySQL']]]],
		'seeds'=>['paths'=>[], 'ledger_table'=>'coverage_seed_ledger'],
	]);
	$t->same('mysql', (new SeedContext(null, null, [], 'primary'))->dbms());
	$received=null;
	$arrayContext=new SeedContext(
		static function(string|array $query) use (&$received): bool { $received=$query; return true; },
		null,
		[],
		'analytics',
	);
	$arrayContext->query(['postgresql'=>'SELECT 1']);
	$t->same('analytics', $received['dbms_cluster_override'] ?? null);
})->tag('sql','seeds','context','failures')->maxMillis(5000);

test('SQL seed definitions and file discovery explain content fingerprint failures', static function(Context $t): void {
	$failures=$t->state('sql.seed-failures', ['hash_file'=>[]]);
	$workspace=$t->workspace('sql-seed-content-failures');
	$root=str_replace('\\', '/', $workspace->root());
	$source=$workspace->file('definition.seed.php', '<?php return [];');
	$content=$workspace->file('payload.json', '{"version":1}');

	$definition=new SeedDefinition(
		'content.demo',
		1,
		static fn()=>null,
		null,
		'',
		[],
		'',
		null,
		['default'],
		['payload.json'],
	);
	$t->same(['payload.json'], $definition->contentSources());
	$t->throws(static fn()=>$definition->withSource($source, 'not-a-digest'), InvalidArgumentException::class);
	$failures->put('hash_file', [str_replace('\\', '/', $content)]);
	$t->throws(static fn()=>$definition->withSource($source, hash('sha256', 'source')), InvalidArgumentException::class);
	$failures->put('hash_file', []);
	$t->throws(static fn()=>(new SeedDefinition(
		'content.missing', 1, static fn()=>null, null, '', [], '', null, ['default'], ['missing.json']
	))->withSource($source, hash('sha256', 'source')), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('profile.bad', 1, static fn()=>null, null, '', [], '', null, ['bad profile']), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('content.bad', 1, static fn()=>null, null, '', [], '', null, ['default'], ['']), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition(
		'content.absolute',1,static fn()=>null,null,'',[],'',null,['default'],[$content],
	),InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition(
		'content.count',1,static fn()=>null,null,'',[],'',null,['default'],
		array_map(static fn(int $index): string=>'content-'.$index.'.json',range(0,64)),
	),InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition(
		'content.path-bytes',1,static fn()=>null,null,'',[],'',null,['default'],[str_repeat('a',4097)],
	),InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition(
		'content.control',1,static fn()=>null,null,'',[],'',null,['default'],["payload\n.json"],
	),InvalidArgumentException::class);
	$lexical=(new \ReflectionClass(SeedFileLoader::class))->getMethod('lexicalAbsolutePath');
	$t->same('C:/project/seeds/demo.seed.php',$lexical->invoke(null,'C:\\project\\catalog\\..\\seeds\\demo.seed.php'));
	$t->throws(static fn()=>$lexical->invoke(null,'\\\\server\\share\\demo.seed.php'),RuntimeException::class);

	$projectRoot=$root.'/project';
	$projectSeed=$workspace->file('project/database/seeds/project.seed.php', <<<'PHP'
<?php
return ['id'=>'content.project','version'=>1,'up'=>static fn()=>null,'content_sources'=>['../../src/payload.json']];
PHP);
	$workspace->file('project/src/payload.json','{"project":true}');
	$t->same('content.project@1',SeedFileLoader::load($projectSeed,$projectRoot)[0]->key());
	$t->throws(static fn()=>SeedFileLoader::load($projectSeed),InvalidArgumentException::class);
	$workspace->file('outside.json','{"outside":true}');
	$outsideExecutionSentinel=$root.'/outside-executed.txt';
	$outsideDefinition=$workspace->file('outside-executable.seed.php',
		'<?php file_put_contents('.var_export($outsideExecutionSentinel,true).',"executed"); return ["id"=>"outside.execution","up"=>static fn()=>null];',
	);
	$t->throws(static fn()=>SeedFileLoader::load($outsideDefinition,$projectRoot),RuntimeException::class);
	$t->isFalse(is_file($outsideExecutionSentinel));
	$escaping=$workspace->file('project/database/seeds/escaping.seed.php',
		'<?php return ["id"=>"content.escape","up"=>static fn()=>null,"content_sources"=>["../../../outside.json"]];',
	);
	$t->throws(static fn()=>SeedFileLoader::load($escaping,$projectRoot),InvalidArgumentException::class);
	$oversizedContent=$projectRoot.'/src/oversized.bin';
	$handle=fopen($oversizedContent,'wb');
	if(!is_resource($handle) || !ftruncate($handle,8388609)){
		throw new RuntimeException('Unable to create oversized seed fixture.');
	}
	fclose($handle);
	$oversizedDefinition=$workspace->file('project/database/seeds/oversized-content.seed.php',
		'<?php return ["id"=>"content.oversized","up"=>static fn()=>null,"content_sources"=>["../../src/oversized.bin"]];',
	);
	$t->throws(static fn()=>SeedFileLoader::load($oversizedDefinition,$projectRoot),InvalidArgumentException::class);
	$linkContent=$projectRoot.'/src/link.json';
	if(function_exists('symlink') && @symlink($projectRoot.'/src/payload.json',$linkContent)){
		try{
			$linkDefinition=$workspace->file('project/database/seeds/link-content.seed.php',
				'<?php return ["id"=>"content.link","up"=>static fn()=>null,"content_sources"=>["../../src/link.json"]];',
			);
			$t->throws(static fn()=>SeedFileLoader::load($linkDefinition,$projectRoot),InvalidArgumentException::class);
		}finally{@unlink($linkContent);}
	}
	$inventory=[];$aggregateBytes=67108864;
	$t->throws(static fn()=>(new SeedDefinition(
		'content.aggregate',1,static fn()=>null,null,'',[],'',null,['default'],['payload.json'],
	))->withSource($source,hash('sha256','source'),$root,$inventory,$aggregateBytes),InvalidArgumentException::class);

	$single=$workspace->file('single.seed.php', <<<'PHP'
<?php
return new \Dataphyre\Database\Seeds\SeedDefinition('file.single', 1, static fn()=>null);
PHP);
	$t->same(['file.single@1'], array_map(
		static fn(SeedDefinition $seed): string=>$seed->key(),
		SeedFileLoader::load(['', $single]),
	));
	$unreadable=$workspace->file('unreadable.seed.php', '<?php return ["id"=>"file.unreadable", "up"=>static fn()=>null];');
	$failures->put('hash_file', [str_replace('\\', '/', $unreadable)]);
	$t->throws(static fn()=>SeedFileLoader::load($unreadable), RuntimeException::class);
	$t->throws(static fn()=>SeedFileLoader::load(array_fill(0,17,'')),RuntimeException::class);
	$oversized=$workspace->file('oversized.seed.php','<?php '.str_repeat(' ',2097152));
	$t->throws(static fn()=>SeedFileLoader::load($oversized),RuntimeException::class);
	$tooMany=$workspace->file('too-many.seed.php', <<<'PHP'
<?php
$definitions=[];
for($index=0;$index<4097;$index++){
	$definitions[]=['id'=>'many.seed.'.$index,'version'=>1,'up'=>static fn()=>null];
}
return $definitions;
PHP);
	$t->throws(static fn()=>SeedFileLoader::load($tooMany),RuntimeException::class);
	$link=$root.'/linked.seed.php';
	if(function_exists('symlink') && @symlink($single,$link)){
		try{$t->throws(static fn()=>SeedFileLoader::load($link),RuntimeException::class);}
		finally{@unlink($link);}
	}
	$loaderSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Seeds/SeedFileLoader.php');
	$definitionSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Seeds/SeedDefinition.php');
	foreach(['MAXIMUM_INSPECTED_ENTRIES','MAXIMUM_SEED_FILES','MAXIMUM_SEED_FILE_BYTES',
		'MAXIMUM_AGGREGATE_BYTES','MAXIMUM_DEFINITIONS'] as $bound) $t->contains($bound,$loaderSource);
	foreach(['MAXIMUM_CONTENT_SOURCES','MAXIMUM_CONTENT_SOURCE_FILE_BYTES',
		'MAXIMUM_CONTENT_SOURCE_FILES','MAXIMUM_CONTENT_SOURCE_AGGREGATE_BYTES',
		'containsSymbolicLink','isWithinRoot'] as $bound) $t->contains($bound,$definitionSource);
	$t->contains($root, str_replace('\\', '/', $single));
})->tag('sql','seeds','files','fingerprints','failures')->maxMillis(5000);

test('SQL seed manager describes graph reuse profile barriers and exact rollback selectors', static function(Context $t): void {
	$arrayManager=new SeedManager([[
		'id'=>'array.seed',
		'version'=>1,
		'up'=>static fn()=>null,
	]], new InMemorySeedLedger());
	$t->same('pending', $arrayManager->status()[0]['status']);
	$t->throws(static fn()=>new SeedManager([], new InMemorySeedLedger(), null, []), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager([], new InMemorySeedLedger(), null, ['bad profile']), RuntimeException::class);

	$shared=new SeedDefinition('shared', 1, static fn()=>null);
	$left=new SeedDefinition('left', 1, static fn()=>null, null, '', ['shared']);
	$right=new SeedDefinition('right', 1, static fn()=>null, null, '', ['shared']);
	$reuse=new SeedManager([$right,$left,$shared], new InMemorySeedLedger());
	$t->same(['shared@1','left@1','right@1'], array_column($reuse->planApply(['left','right']), 'key'));

	$hidden=new SeedDefinition('hidden.demo', 1, static fn()=>null, null, '', [], '', null, ['demo']);
	$visible=new SeedDefinition('visible.default', 1, static fn()=>null, null, '', ['hidden.demo']);
	$profileBarrier=new SeedManager([$visible,$hidden], new InMemorySeedLedger());
	$t->throws(static fn()=>$profileBarrier->planApply(['visible.default']), RuntimeException::class);

	$t->throws(static fn()=>new SeedManager([
		new SeedDefinition('self.reference', 1, static fn()=>null),
		new SeedDefinition('self.reference', 2, static fn()=>null, null, '', ['self.reference']),
	], new InMemorySeedLedger()), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager([
		new SeedDefinition('missing.exact', 1, static fn()=>null, null, '', ['absent@2']),
	], new InMemorySeedLedger()), RuntimeException::class);

	$target=new SeedDefinition('target', 1, static fn()=>null, static fn()=>null);
	$diamondShared=new SeedDefinition('diamond.shared', 1, static fn()=>null, static fn()=>null);
	$diamondLeft=new SeedDefinition('diamond.left', 1, static fn()=>null, static fn()=>null, '', ['diamond.shared']);
	$diamondRight=new SeedDefinition('diamond.right', 1, static fn()=>null, static fn()=>null, '', ['diamond.shared']);
	$diamondTop=new SeedDefinition('diamond.top', 1, static fn()=>null, static fn()=>null, '', ['diamond.left','diamond.right']);
	$diamond=new SeedManager(
		[$diamondTop,$diamondRight,$target,$diamondLeft,$diamondShared],
		new InMemorySeedLedger(),
	);
	$diamond->apply();
	$t->same('target@1', $diamond->planRollback('target@1')['key']);
	$t->isTrue($diamond->rollback('target@1', true)['rolled_back']);

	$pending=new SeedManager([
		new SeedDefinition('pending.exact', 1, static fn()=>null, static fn()=>null),
	], new InMemorySeedLedger());
	$t->throws(static fn()=>$pending->planRollback('pending.exact@1'), RuntimeException::class);
})->tag('sql','seeds','graph','profiles','rollback')->maxMillis(5000);

test('SQL native ledger validates rows locks mutations connections and cluster wrapping', static function(Context $t): void {
	$t->throws(static fn()=>(new SqlSeedLedger())->all(), RuntimeException::class);
	dp_seed_define_sql_facade();
	$t->throws(static fn()=>(new SqlSeedLedger())->all(), RuntimeException::class);
	dp_seed_define_connection_context();

	$nativeState=$t->state('sql.seed-native', [
		'dbms'=>' PostgreSQL ',
		'ledger_rows'=>null,
		'lock_rows'=>[['lock_id'=>1]],
		'query_result'=>true,
		'queries'=>[],
		'transactions'=>0,
	]);
	$native=new SqlSeedLedger('native_seed_ledger');
	$t->same([], $native->all());
	$validRow=['seed_id'=>'native','seed_version'=>1,'checksum'=>str_repeat('b',64),'batch'=>2,'applied_at'=>'now'];
	$nativeState->put('ledger_rows', $validRow);
	$t->same('native', $native->all()['native@1']['id']);
	$nativeState->put('ledger_rows', [42]);
	$t->throws(static fn()=>$native->all(), RuntimeException::class);
	$nativeState->put('ledger_rows', [['seed_id'=>'','seed_version'=>0]]);
	$t->throws(static fn()=>$native->all(), RuntimeException::class);

	$nativeState->put('ledger_rows', []);
	$t->same('native-transaction', $native->transaction(static function() use ($native, $nativeState, $validRow): string {
		$native->recordApplied([
			'id'=>'native',
			'version'=>1,
			'checksum'=>str_repeat('b',64),
			'batch'=>2,
			'applied_at'=>'now',
		]);
		$nativeState->put('ledger_rows', [$validRow]);
		$native->remove('native', 1);
		return 'native-transaction';
	}));
	$t->same(1, $nativeState->get('transactions'));
	$nativeState->put('lock_rows', []);
	$t->throws(static fn()=>$native->transaction(static fn()=>null), RuntimeException::class);

	$records=[];
	$query=static function(string|array $sql, ?array $vars, bool $associative) use (&$records): mixed {
		$rendered=json_encode($sql, JSON_THROW_ON_ERROR);
		if(str_contains($rendered, 'SELECT lock_id')) return [['lock_id'=>1]];
		if($associative && str_contains($rendered, 'SELECT seed_id')) return array_values($records);
		return true;
	};
	$mutating=new SqlSeedLedger('mutation_seed_ledger', null, $query, static fn(callable $callback): mixed=>$callback(), 'postgresql');
	$t->throws(static fn()=>$mutating->transaction(static fn()=>$mutating->recordApplied(['id'=>'bad'])), RuntimeException::class);
	$records['duplicate@1']=['seed_id'=>'duplicate','seed_version'=>1,'checksum'=>str_repeat('c',64),'batch'=>1,'applied_at'=>'now'];
	$t->throws(static fn()=>$mutating->transaction(static fn()=>$mutating->recordApplied([
		'id'=>'duplicate','version'=>1,'checksum'=>str_repeat('c',64),'batch'=>1,'applied_at'=>'now',
	])), RuntimeException::class);
	$t->throws(static fn()=>$mutating->transaction(static fn()=>$mutating->remove('missing', 1)), RuntimeException::class);

	$seen=null;
	$clustered=new SqlSeedLedger(
		'cluster_seed_ledger',
		'analytics',
		static function(string|array $query) use (&$seen): bool { $seen=$query; return true; },
		static fn(callable $callback): mixed=>$callback(),
		'postgresql',
	);
	$t->nonPublic($clustered)->invoke('query', 'SELECT 1');
	$t->same('analytics', $seen['dbms_cluster_override'] ?? null);
	$t->same('SELECT 1', $seen['postgresql'] ?? null);

	$longQueries=[];
	$longTable=str_repeat('a', 59);
	(new SqlSeedLedger(
		$longTable,
		null,
		static function(string|array $query) use (&$longQueries): bool { $longQueries[]=$query; return true; },
		static fn(callable $callback): mixed=>$callback(),
		'postgresql',
	))->ensureSchema();
	$t->isTrue((bool)array_filter($longQueries, static fn(string|array $query): bool=>str_contains(
		json_encode($query, JSON_THROW_ON_ERROR),
		substr(hash('sha256', $longTable), 0, 8),
	)));
})->tag('sql','seeds','ledger','native','failures')->maxMillis(5000);

test('SQL seed CLI renders help status rollback plans and rejects ambiguous options', static function(Context $t): void {
	$out='';
	$err='';
	$writeOut=static function(string $message) use (&$out): void { $out.=$message; };
	$writeErr=static function(string $message) use (&$err): void { $err.=$message; };
	$manager=new SeedManager([
		new SeedDefinition('cli.reversible', 1, static fn()=>null, static fn()=>null),
	], new InMemorySeedLedger());
	$t->same(0, dp_sql_seed_main(['seeds.php','--help'], true, $writeOut, $writeErr, $manager));
	$t->contains('Usage:', $out);
	$t->contains('--data-environment=<name>', $out);
	$out='';
	$t->same(0, dp_sql_seed_main(['seeds.php','status'], true, $writeOut, $writeErr, $manager));
	$t->contains("cli.reversible@1\tpending", $out);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','again']), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','--profile=bad profile']), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','--data-environment=bad value']), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','--unknown=value']), RuntimeException::class);
	$t->same(null,dp_sql_seed_bootstrap_path('/project','valid.app-name'));
	$t->same(null,dp_sql_seed_bootstrap_path('/project','valid$app'));
	$t->throws(static fn()=>dp_sql_seed_bootstrap_path('/project','../invalid'),RuntimeException::class);
	$options=dp_sql_seed_options(['seeds.php','apply','--ledger-table=custom_seed_ledger','--data-environment=sandbox']);
	$t->same('custom_seed_ledger', $options['ledger_table']);
	$t->same('sandbox', $options['data_environment']);
	$t->same('C:\\seed\\definition.php', dp_sql_seed_absolute_path('/project', 'C:\\seed\\definition.php'));
	$t->throws(static fn()=>dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','apply','--project-root=/project','--profile=demo',
	])),RuntimeException::class);

	$manager->apply();
	$dryRun=dp_sql_seed_rollback_command($manager, ['ids'=>['cli.reversible@1'], 'dry_run'=>true]);
	$t->isTrue($dryRun['dry_run']);
	$t->same('cli.reversible@1', $dryRun['rollback']['key']);
	$t->isTrue(dp_sql_seed_rollback_command($manager, ['ids'=>['cli.reversible@1'], 'confirm'=>true])['rolled_back']);

	$out='';
	dp_sql_seed_write_result('list', [
		['key'=>'listed@1','rollback_available'=>true,'description'=>'Listed seed'],
		'ignored row',
		['key'=>'minimal@1'],
	], false, $writeOut);
	$t->contains("Key\tStatus\tRollback\tDescription", $out);
	$t->contains("listed@1\tdefined\tyes\tListed seed", $out);
})->tag('sql','seeds','cli','rendering','rollback')->maxMillis(5000);

test('SQL seed CLI scopes managed profile execution to the configured data environment', static function(Context $t): void {
	if(!class_exists('Dataphyre\\Database\\DataEnvironment',false)){
		define_test_symbols(<<<'PHP'
namespace Dataphyre\Database;
final class DataEnvironment {
	public static function run(string $name,callable $callback,array $overrides=[]): mixed {
		\Dataphyre\Test\TestState::channel('sql.seed-data-environment')->append('names',$name);
		return $callback();
	}
}
PHP);
	}
	$state=$t->state('sql.seed-data-environment',['names'=>[]]);
	$manager=new SeedManager([
		new SeedDefinition('cli.environment',1,static fn()=>null),
	],new InMemorySeedLedger());
	$out='';$err='';
	$status=dp_sql_seed_main(
		['seeds.php','status','--data-environment=sandbox','--json'],
		true,
		static function(string $message) use (&$out): void {$out.=$message;},
		static function(string $message) use (&$err): void {$err.=$message;},
		$manager,
	);
	$t->same(0,$status,$err);$t->same('',$err);
	$t->same(['sandbox'],$state->get('names'));
	$t->same(true,json_decode($out,true,32,JSON_THROW_ON_ERROR)['ok'] ?? null);
})->tag('sql','seeds','cli','data-environment','cache-namespace')->maxMillis(5000);

test('SQL seed CLI resolves configured discovery roots and missing explicit bootstraps', static function(Context $t): void {
	dp_seed_define_sql_facade();
	$workspace=$t->workspace('sql-seed-manager-discovery');
	$root=str_replace('\\', '/', $workspace->root());
	$workspace->file('database/seeds/configured.seed.php', <<<'PHP'
<?php
return ['id'=>'configured.discovery','version'=>1,'up'=>static fn()=>null];
PHP);
	if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG', ['datacenter'=>'coverage']);
	if(!defined('DP_SQL_CFG')) define('DP_SQL_CFG', [
		'default_cluster'=>'primary',
		'data_environments'=>['sandbox'=>['cluster'=>'sandbox','cache_namespace'=>'seed-sandbox']],
		'datacenters'=>['coverage'=>['dbms_clusters'=>[
			'primary'=>['dbms'=>'postgresql'],'sandbox'=>['dbms'=>'postgresql'],
		]]],
		'seeds'=>['paths'=>[], 'ledger_table'=>'configured_seed_ledger'],
	]);
	$manager=dp_sql_seed_manager(dp_sql_seed_options(['seeds.php','list','--project-root='.$root]));
	$t->same(['configured.discovery@1'], array_column($manager->catalog(), 'key'));
	$environmentManager=dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','list','--project-root='.$root,'--data-environment=sandbox',
	]));
	$contextProperty=new ReflectionProperty($environmentManager,'context');
	$context=$contextProperty->getValue($environmentManager);
	$t->same('sandbox',$context->cluster());
	$t->same('sandbox',$context->attribute('data_environment'));
	$liveEnvironmentManager=dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','list','--project-root='.$root,'--data-environment=live',
	]));
	$liveContextProperty=new ReflectionProperty($liveEnvironmentManager,'context');
	$liveContext=$liveContextProperty->getValue($liveEnvironmentManager);
	$t->same('primary',$liveContext->cluster());
	$t->same('live',$liveContext->attribute('data_environment'));
	$t->throws(static fn()=>dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','list','--project-root='.$root,'--cluster=primary','--data-environment=sandbox',
	])), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','list','--project-root='.$root,'--data-environment=missing',
	])), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_manager(dp_sql_seed_options([
		'seeds.php','list','--project-root='.$root,'--bootstrap=missing.php',
	])), RuntimeException::class);

	$commonPackage=$workspace->file('project/common/dataphyre/.keep', '');
	$t->same($root.'/project', str_replace('\\', '/', dp_sql_seed_project_root(dirname($commonPackage))));
	$standalonePackage=$workspace->file('standalone/dataphyre/.keep', '');
	$workspace->file('standalone/applications/.keep', '');
	$t->same($root.'/standalone', str_replace('\\', '/', dp_sql_seed_project_root(dirname($standalonePackage))));
	$fallbackPackage=$workspace->file('vendor/package/.keep', '');
	$t->same($root.'/vendor/package', str_replace('\\', '/', dp_sql_seed_project_root(dirname($fallbackPackage))));
})->tag('sql','seeds','cli','discovery','configuration')->maxMillis(5000);

test('SQL seed boot reports a complete deterministic failure when a runtime omits SQL', static function(Context $t): void {
	$runtime=SeedRuntimeFixture::withoutSql($t->workspace('sql-seed-runtime-without-sql'));
	$t->throws(static fn()=>dp_sql_seed_boot_sql($runtime), RuntimeException::class);
})->tag('sql','seeds','cli','bootstrap','failures')->maxMillis(5000);

test('SQL seed boot completes when the runtime module provides its SQL facade', static function(Context $t): void {
	$runtime=SeedRuntimeFixture::withSql($t->workspace('sql-seed-runtime-with-sql'));
	dp_sql_seed_boot_sql($runtime);
	$t->isTrue(class_exists('dataphyre\\sql', false));
})->tag('sql','seeds','cli','bootstrap')->maxMillis(5000);

test('SQL seed boot loads the optional cache kernel for shared invalidation', static function(Context $t): void {
	$runtime=SeedRuntimeFixture::withSqlAndCache($t->workspace('sql-seed-runtime-with-cache'));
	dp_sql_seed_boot_sql($runtime);
	$t->isTrue(class_exists('dataphyre\\sql', false));
	$t->isTrue(class_exists('dataphyre\\cache', false));
})->tag('sql','seeds','cli','bootstrap','cache','invalidation')->maxMillis(5000);
