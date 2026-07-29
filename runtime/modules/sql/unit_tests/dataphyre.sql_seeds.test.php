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
use Dataphyre\Database\Seeds\SeedFileLoader;
use Dataphyre\Database\Seeds\SeedManager;
use Dataphyre\Database\Seeds\SqlSeedLedger;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

$dpSeedRoot=dirname(__DIR__);
foreach(['SeedDefinition','SeedContext','SeedLedger','InMemorySeedLedger','SqlSeedLedger','SeedFileLoader','SeedManager'] as $dpSeedClass){
	require_once $dpSeedRoot.'/Framework/Seeds/'.$dpSeedClass.'.php';
}
require_once $dpSeedRoot.'/kernel/seeds.php';

function dp_seed_mark_bootstrap_loaded(string $name): void {
	TestState::channel('sql.seeds')->put('bootstrap_loaded',$name);
}

test('SQL seed definitions and contexts validate immutable execution metadata', static function(Context $t): void {
	$queries=[];
	$context=new SeedContext(static function(string|array $query, ?array $vars, bool $associative) use (&$queries): array {
		$queries[]=[$query,$vars,$associative];
		return [['ok'=>1]];
	}, 'PostgreSQL', ['tenant_id'=>7], 'analytics');
	$definition=SeedDefinition::fromArray([
		'id'=>' Demo.Base ',
		'version'=>2,
		'description'=>' Demo base ',
		'dependencies'=>['core.users@1','core.users@1'],
		'up'=>static fn(SeedContext $seed): mixed=>$seed->query('SELECT 1', [7], true),
		'down'=>static fn(): bool=>true,
	]);
	$t->same('demo.base@2', $definition->key());
	$t->same(['core.users@1'], $definition->dependencies());
	$t->same('Demo base', $definition->description());
	$t->isTrue($definition->hasRollback());
	$t->isTrue($definition->matches('demo.base'));
	$t->isTrue($definition->matches('demo.base@2'));
	$t->same([['ok'=>1]], $definition->apply($context));
	$t->isTrue($definition->rollback($context));
	$t->same('postgresql', $context->dbms());
	$t->same('analytics', $context->cluster());
	$t->same(7, $context->attribute('tenant_id'));
	$t->same('fallback', $context->attribute('missing', 'fallback'));
	$t->same(['tenant_id'=>7], $context->attributes());
	$t->count(1, $queries);
	$t->same('analytics', $queries[0][0]['dbms_cluster_override'] ?? null);
	$t->same('SELECT 1', $queries[0][0]['postgresql'] ?? null);
	$t->same(64, strlen($definition->checksum()));
	$t->same('demo.base', $definition->jsonSerialize()['id']);
	$t->same(['default'], $definition->profiles());

	$t->throws(static fn()=>new SeedDefinition('', 1, static fn()=>null), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('valid', 0, static fn()=>null), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('valid', 1, static fn()=>null, null, '', ['valid@1']), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('valid', 1, static fn()=>null, null, '', [], 'bad'), InvalidArgumentException::class);
	$t->throws(static fn()=>SeedDefinition::fromArray(['id'=>'valid']), InvalidArgumentException::class);
	$t->throws(static fn()=>SeedDefinition::fromArray(['id'=>'valid','up'=>static fn()=>null,'down'=>'bad']), InvalidArgumentException::class);
	$t->throws(static fn()=>SeedDefinition::fromArray(['id'=>'valid','up'=>static fn()=>null,'preflight'=>'bad']), InvalidArgumentException::class);
	$t->throws(static fn()=>new SeedDefinition('valid', 1, static fn()=>null, null, '', [], '', null, []), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SeedDefinition('irreversible', 1, static fn()=>null))->rollback($context), RuntimeException::class);
	$t->throws(static fn()=>(new SeedContext(static fn()=>false))->query('BROKEN'), RuntimeException::class);
})->tag('sql','seeds','framework')->maxMillis(5000);

test('SQL seed manager applies dependencies and versions atomically and idempotently', static function(Context $t): void {
	$events=[];
	$base1=new SeedDefinition('base', 1, static function() use (&$events): void { $events[]='base1'; }, static fn()=>null);
	$base2=new SeedDefinition('base', 2, static function() use (&$events): void { $events[]='base2'; }, static fn()=>null);
	$feature=new SeedDefinition('feature', 1, static function() use (&$events): void { $events[]='feature'; }, static fn()=>null, '', ['base']);
	$ledger=new InMemorySeedLedger();
	$manager=new SeedManager([$feature,$base2,$base1], $ledger, new SeedContext(static fn()=>true, 'sqlite'));
	$t->same(['base@1','base@2','feature@1'], array_column($manager->catalog(), 'key'));
	$t->same(['base@1','base@2','feature@1'], array_column($manager->planApply(['feature']), 'key'));
	$result=$manager->apply(['feature']);
	$t->same(1, $result['batch']);
	$t->same(['base@1','base@2','feature@1'], array_column($result['applied'], 'key'));
	$t->same(['base1','base2','feature'], $events);
	$t->same(['applied','applied','applied'], array_column($manager->status(), 'status'));
	$t->same(['batch'=>null,'applied'=>[],'skipped'=>3], $manager->apply(['feature']));

	$drift=new SeedDefinition('base', 1, static fn()=>null, static fn()=>null, '', [], str_repeat('a', 64));
	$t->throws(static fn()=>(new SeedManager([$drift,$base2,$feature], $ledger))->planApply(), RuntimeException::class);

	$atomicLedger=new InMemorySeedLedger();
	$atomic=new SeedManager([
		new SeedDefinition('one', 1, static fn()=>null),
		new SeedDefinition('two', 1, static fn()=>throw new RuntimeException('stop'), null, '', ['one']),
	], $atomicLedger);
	$t->throws(static fn()=>$atomic->apply(), RuntimeException::class);
	$t->same([], $atomicLedger->all());
})->tag('sql','seeds','framework')->maxMillis(5000);

test('SQL seed profiles exclude demo data by default and preflight in dependency order inside the atomic batch', static function(Context $t): void {
	$events=[];
	$default=new SeedDefinition(
		'default.reference',
		1,
		static function() use (&$events): void { $events[]='up-default'; },
		null,
		'',
		[],
		'',
		null,
		['default'],
		[],
		static function() use (&$events): void { $events[]='preflight-default'; },
	);
	$demo=new SeedDefinition(
		'demo.portfolio',
		1,
		static function() use (&$events): void { $events[]='up-demo'; },
		null,
		'',
		[],
		'',
		null,
		['demo'],
		[],
		static function() use (&$events): void { $events[]='preflight-demo'; },
	);
	$defaultManager=new SeedManager([$demo,$default], new InMemorySeedLedger());
	$t->same(['default.reference@1'], array_column($defaultManager->planApply(), 'key'));
	$t->same([true,false], array_column($defaultManager->catalog(), 'active'));
	$t->throws(static fn()=>$defaultManager->planApply(['demo.portfolio']), RuntimeException::class);
	$defaultManager->apply();
	$t->same(['preflight-default','up-default'], $events);

	$events=[];
	$demoManager=new SeedManager([$demo], new InMemorySeedLedger(), null, ['demo']);
	$demoManager->apply();
	$t->same(['preflight-demo','up-demo'], $events);

	$events=[];
	$batchManager=new SeedManager([
		new SeedDefinition('batch.a', 1, static function() use (&$events): void { $events[]='up-a'; }, null, '', [], '', null, ['default'], [], static function() use (&$events): void { $events[]='pre-a'; }),
		new SeedDefinition('batch.b', 1, static function() use (&$events): void { $events[]='up-b'; }, null, '', ['batch.a'], '', null, ['default'], [], static function() use (&$events): void {
			if(!in_array('up-a', $events, true)){
				throw new RuntimeException('Dependency was not applied before dependent preflight.');
			}
			$events[]='pre-b';
		}),
	], new InMemorySeedLedger());
	$batchManager->apply();
	$t->same(['pre-a','up-a','pre-b','up-b'], $events);
})->tag('sql','seeds','profiles','preflight','safety')->maxMillis(5000);

test('SQL seed manager fails closed on invalid graphs selectors and rollback hazards', static function(Context $t): void {
	$t->throws(static fn()=>new SeedManager([
		new SeedDefinition('a', 1, static fn()=>null, null, '', ['missing']),
	], new InMemorySeedLedger()), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager([
		new SeedDefinition('a', 1, static fn()=>null, null, '', ['b']),
		new SeedDefinition('b', 1, static fn()=>null, null, '', ['a']),
	], new InMemorySeedLedger()), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager([new stdClass()], new InMemorySeedLedger()), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager([
		new SeedDefinition('same', 1, static fn()=>null),
		new SeedDefinition('same', 1, static fn()=>null),
	], new InMemorySeedLedger()), RuntimeException::class);

	$ledger=new InMemorySeedLedger();
	$manager=new SeedManager([
		new SeedDefinition('parent', 1, static fn()=>null, static fn()=>null),
		new SeedDefinition('child', 1, static fn()=>null, static fn()=>null, '', ['parent@1']),
	], $ledger);
	$t->throws(static fn()=>$manager->planApply(['unknown']), RuntimeException::class);
	$manager->apply();
	$t->throws(static fn()=>$manager->rollback('child'), RuntimeException::class);
	$t->throws(static fn()=>$manager->rollback('parent', true), RuntimeException::class);
	$t->isTrue($manager->rollback('child', true)['rolled_back']);
	$t->isTrue($manager->rollback('parent', true)['rolled_back']);
	$t->throws(static fn()=>$manager->planRollback('parent'), RuntimeException::class);

	$noDown=new SeedManager([new SeedDefinition('irreversible', 1, static fn()=>null)], new InMemorySeedLedger());
	$noDown->apply();
	$t->throws(static fn()=>$noDown->planRollback('irreversible'), RuntimeException::class);
	$orphanChecksum=str_repeat('b', 64);
	$orphan=new SeedManager([], new InMemorySeedLedger([[
		'id'=>'old.seed','version'=>1,'checksum'=>$orphanChecksum,'batch'=>4,'applied_at'=>'2026-01-01T00:00:00Z',
	]]));
	$t->same('orphaned', $orphan->status()[0]['status']);
	$t->throws(static fn()=>$orphan->planApply(), RuntimeException::class);

	$parent=new SeedDefinition('restored.parent', 1, static fn()=>null, static fn()=>null);
	$child=new SeedDefinition('removed.child', 1, static fn()=>null, static fn()=>null, '', ['restored.parent']);
	$removedLedger=new InMemorySeedLedger();
	(new SeedManager([$parent,$child], $removedLedger))->apply();
	$withoutChild=new SeedManager([$parent], $removedLedger);
	$t->throws(static fn()=>$withoutChild->planRollback('restored.parent'), RuntimeException::class);

	$driftLedger=new InMemorySeedLedger();
	$stableA=new SeedDefinition('stable.a', 1, static fn()=>null, null, '', [], str_repeat('d', 64));
	$stableB=new SeedDefinition('stable.b', 1, static fn()=>null, null, '', [], str_repeat('e', 64));
	(new SeedManager([$stableA,$stableB], $driftLedger))->apply();
	$changedA=new SeedDefinition('stable.a', 1, static fn()=>null, null, '', [], str_repeat('f', 64));
	$selective=new SeedManager([$changedA,$stableB], $driftLedger);
	$t->throws(static fn()=>$selective->planApply(['stable.b']), RuntimeException::class);
})->tag('sql','seeds','framework')->maxMillis(5000);

test('SQL seed file loader discovers deterministic definitions and source checksums', static function(Context $t): void {
	$workspace=$t->workspace('sql-seed-files');
	$root=str_replace('\\', '/', $workspace->root());
		$workspace->file('z.seed.php', <<<'PHP'
<?php
return ['id'=>'file.z','version'=>1,'up'=>static fn()=>null];
PHP);
		$workspace->file('nested/a.seed.php', <<<'PHP'
<?php
use Dataphyre\Database\Seeds\SeedDefinition;
return [new SeedDefinition('file.a', 1, static fn()=>null), ['id'=>'file.a','version'=>2,'up'=>static fn()=>null]];
PHP);
		$workspace->file('ignored.php', '<?php return [];');
		$definitions=SeedFileLoader::load([$root, $root.'/z.seed.php']);
		$t->same(['file.a@1','file.a@2','file.z@1'], array_map(static fn(SeedDefinition $seed): string=>$seed->key(), $definitions));
		$t->same(64, strlen($definitions[0]->checksum()));
		$t->contains('/nested/a.seed.php', (string)$definitions[0]->source());
		$t->notSame($definitions[0]->checksum(), $definitions[1]->checksum());
		$t->throws(static fn()=>SeedFileLoader::load($root.'/missing'), RuntimeException::class);
		$t->throws(static fn()=>SeedFileLoader::load($root.'/ignored.php'), RuntimeException::class);
		$workspace->file('empty.seed.php', '<?php return [];');
		$t->throws(static fn()=>SeedFileLoader::load($root.'/empty.seed.php'), RuntimeException::class);
		$workspace->file('bad.seed.php', '<?php return [42];');
		$t->throws(static fn()=>SeedFileLoader::load($root.'/bad.seed.php'), RuntimeException::class);

		$delegated=$workspace->file('delegated-fixture.php', '<?php return ["version"=>1];');
		$delegating=$workspace->file('delegating.seed.php', '<?php return ["id"=>"delegating", "version"=>1, "up"=>static fn()=>null, "checksum"=>'.var_export(str_repeat('a', 64), true).', "content_sources"=>['.var_export($delegated, true).']];');
		$delegatedBefore=SeedFileLoader::load($delegating)[0];
		$workspace->file('delegated-fixture.php', '<?php return ["version"=>2];');
	$delegatedAfter=SeedFileLoader::load($delegating)[0];
	$t->notSame($delegatedBefore->checksum(), $delegatedAfter->checksum());
	$t->isTrue($delegatedAfter->hasContentFingerprint());

	$historical=str_repeat('b', 64);
	$compatible=$workspace->file('compatible.seed.php', '<?php return ["id"=>"compatible", "version"=>1, "up"=>static fn()=>null, "accepted_checksums"=>['.var_export($historical, true).']];');
	$compatibleDefinition=SeedFileLoader::load($compatible)[0];
	$t->isTrue($compatibleDefinition->acceptsChecksum($historical));
	$t->isTrue($compatibleDefinition->acceptsChecksum($compatibleDefinition->checksum()));
	$t->isFalse($compatibleDefinition->acceptsChecksum(str_repeat('c', 64)));

		$mutating=$workspace->file('mutating.seed.php', <<<'PHP'
<?php
file_put_contents(__FILE__, file_get_contents(__FILE__)."\n// changed during require");
return ['id'=>'mutating','version'=>1,'up'=>static fn()=>null];
PHP);
		$t->throws(static fn()=>SeedFileLoader::load($mutating), RuntimeException::class);
})->tag('sql','seeds','filesystem')->maxMillis(5000);

test('SQL seed ledger emits portable bound statements and normalizes records', static function(Context $t): void {
	$calls=[];
	$transactionCalls=0;
	$records=['demo@2'=>['seed_id'=>'demo','seed_version'=>'2','checksum'=>str_repeat('c',64),'batch'=>'3','applied_at'=>'now']];
	$query=static function(string|array $sql, ?array $vars, bool $associative) use (&$calls, &$records): mixed {
		$calls[]=[$sql,$vars,$associative];
		$rendered=json_encode($sql, JSON_THROW_ON_ERROR);
		if(str_contains($rendered, 'SELECT lock_id')) return [['lock_id'=>1]];
		if($associative && str_contains($rendered, 'SELECT seed_id')) return array_values($records);
		if($vars!==null && count($vars)===5 && str_contains($rendered, 'INSERT INTO')){
			$records[(string)$vars[0].'@'.(int)$vars[1]]=['seed_id'=>$vars[0],'seed_version'=>$vars[1],'checksum'=>$vars[2],'batch'=>$vars[3],'applied_at'=>$vars[4]];
		}
		if($vars!==null && count($vars)===2 && str_contains($rendered, 'DELETE FROM')){
			unset($records[(string)$vars[0].'@'.(int)$vars[1]]);
		}
		return true;
	};
	$ledger=new SqlSeedLedger('seed_ledger', 'primary', $query, static function(callable $callback) use (&$transactionCalls): mixed {
		$transactionCalls++;
		return $callback();
	});
	$ledger->ensureSchema();
	$t->same('demo', $ledger->all()['demo@2']['id']);
	$t->same(4, $ledger->nextBatch());
	$t->same('ok', $ledger->transaction(static function() use ($ledger, &$records, $t): string {
		$ledger->recordApplied(['id'=>'next','version'=>1,'checksum'=>str_repeat('d',64),'batch'=>4,'applied_at'=>'later']);
		$t->isTrue(isset($records['next@1']));
		$ledger->remove('next', 1);
		$t->isFalse(isset($records['next@1']));
		return 'ok';
	}));
	$t->same(1, $transactionCalls);
	$t->isTrue(count($calls)>=10);
	$t->contains('CREATE TABLE IF NOT EXISTS', json_encode($calls[0][0], JSON_THROW_ON_ERROR));
	$t->isTrue(count(array_filter($calls, static fn(array $call): bool=>is_array($call[0]) && ($call[0]['dbms_cluster_override'] ?? null)==='primary'))===count($calls));
	$t->isTrue(count(array_filter($calls, static fn(array $call): bool=>str_contains(json_encode($call[0], JSON_THROW_ON_ERROR), 'FOR UPDATE')))===1);
	$t->throws(static fn()=>new SqlSeedLedger('bad.table', null, $query), RuntimeException::class);
	$t->throws(static fn()=>$ledger->recordApplied(['id'=>'bad']), RuntimeException::class);
	$t->throws(static fn()=>$ledger->remove('missing', 1), RuntimeException::class);

	$invalid=new SqlSeedLedger('seed_ledger', null, static fn(string|array $sql, ?array $vars, bool $assoc): mixed=>$assoc ? 42 : true, static fn(callable $callback): mixed=>$callback());
	$t->throws(static fn()=>$invalid->all(), RuntimeException::class);
	$failing=new SqlSeedLedger('seed_ledger', null, static fn()=>false, static fn(callable $callback): mixed=>$callback());
	$t->throws(static fn()=>$failing->ensureSchema(), RuntimeException::class);
	$t->throws(static fn()=>new SeedManager(
		[new SeedDefinition('programmatic', 1, static fn()=>null)],
		new SqlSeedLedger('seed_ledger', null, static fn()=>true, static fn(callable $callback): mixed=>$callback()),
	), RuntimeException::class);
	$sqliteCallbackRan=false;
	$sqlite=new SqlSeedLedger('seed_ledger', null, null, null, 'sqlite');
	$t->throws(static function() use ($sqlite, &$sqliteCallbackRan): void {
		$sqlite->transaction(static function() use (&$sqliteCallbackRan): void { $sqliteCallbackRan=true; });
	}, RuntimeException::class);
	$t->isFalse($sqliteCallbackRan);
	$ledgerSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Seeds/SqlSeedLedger.php');
	$t->contains('ConnectionContext', $ledgerSource);
	$t->isFalse(str_contains($ledgerSource, '\\dataphyre\\sql::begin'));
})->tag('sql','seeds','ledger')->maxMillis(5000);

test('SQL seed callback ledger mutex and transaction stay on one explicit cluster', static function(Context $t): void {
	$calls=[];
	$records=[];
	$appWrites=[];
	$transactionEvents=[];
	$query=static function(string|array $sql, ?array $vars, bool $associative) use (&$calls, &$records, &$appWrites): mixed {
		$calls[]=[$sql,$vars,$associative];
		$rendered=json_encode($sql, JSON_THROW_ON_ERROR);
		if(str_contains($rendered, 'SELECT lock_id')) return [['lock_id'=>1]];
		if($associative && str_contains($rendered, 'SELECT seed_id')) return array_values($records);
		if(str_contains($rendered, 'INSERT INTO app_data')){
			$appWrites[]=$vars;
			return true;
		}
		if($vars!==null && count($vars)===5 && str_contains($rendered, 'INSERT INTO')){
			$records[(string)$vars[0].'@'.(int)$vars[1]]=['seed_id'=>$vars[0],'seed_version'=>$vars[1],'checksum'=>$vars[2],'batch'=>$vars[3],'applied_at'=>$vars[4]];
		}
		return true;
	};
	$transaction=static function(callable $callback) use (&$transactionEvents, &$records, &$appWrites): mixed {
		$beforeRecords=$records;
		$beforeWrites=$appWrites;
		$transactionEvents[]='begin:analytics';
		try{
			$result=$callback();
			$transactionEvents[]='commit:analytics';
			return $result;
		}catch(Throwable $exception){
			$records=$beforeRecords;
			$appWrites=$beforeWrites;
			$transactionEvents[]='rollback:analytics';
			throw $exception;
		}
	};
	$context=new SeedContext($query, 'postgresql', [], 'analytics');
	$ledger=new SqlSeedLedger('seed_ledger', 'analytics', $query, $transaction, 'postgresql');
	$manager=new SeedManager([
		new SeedDefinition('atomic.one', 1, static fn(SeedContext $seed): mixed=>$seed->query('INSERT INTO app_data (id) VALUES (?)', [1]), null, '', [], str_repeat('1', 64)),
		new SeedDefinition('atomic.two', 1, static function(SeedContext $seed): void { $seed->query('INSERT INTO app_data (id) VALUES (?)', [2]); throw new RuntimeException('stop'); }, null, '', ['atomic.one'], str_repeat('2', 64)),
	], $ledger, $context);
	$t->throws(static fn()=>$manager->apply(), RuntimeException::class);
	$t->same([], $records);
	$t->same([], $appWrites);
	$t->same(['begin:analytics','rollback:analytics'], $transactionEvents);
	$t->isTrue(count($calls)>0);
	$t->isTrue(count(array_filter($calls, static fn(array $call): bool=>is_array($call[0]) && ($call[0]['dbms_cluster_override'] ?? null)==='analytics'))===count($calls));
	$renderedCalls=array_map(static fn(array $call): string=>json_encode($call[0], JSON_THROW_ON_ERROR), $calls);
	$lockIndex=array_search(true, array_map(static fn(string $sql): bool=>str_contains($sql, 'FOR UPDATE'), $renderedCalls), true);
	$writeIndex=array_search(true, array_map(static fn(string $sql): bool=>str_contains($sql, 'INSERT INTO app_data'), $renderedCalls), true);
	$t->isTrue(is_int($lockIndex) && is_int($writeIndex) && $lockIndex<$writeIndex);
})->tag('sql','seeds','cluster','transaction','mutex','safety')->maxMillis(5000);

test('SQL seed CLI parses safe commands and refuses reset or implicit rollback', static function(Context $t): void {
	$callbackRuns=0;
	$manager=new SeedManager([new SeedDefinition('demo', 1, static function() use (&$callbackRuns): void { $callbackRuns++; }, static fn()=>null)], new InMemorySeedLedger());
	$out='';
	$err='';
	$writeOut=static function(string $message) use (&$out): void { $out.=$message; };
	$writeErr=static function(string $message) use (&$err): void { $err.=$message; };
	$t->same(0, dp_sql_seed_main(['seeds.php','list','--json'], true, $writeOut, $writeErr, $manager));
	$t->contains('"demo@1"', $out);
	$out='';
	$t->same(0, dp_sql_seed_main(['seeds.php','apply','--dry-run'], true, $writeOut, $writeErr, $manager));
	$t->contains('dry_run', $out);
	$err='';
	$t->same(1, dp_sql_seed_main(['seeds.php','reset'], true, $writeOut, $writeErr, $manager));
	$t->contains('intentionally unsupported', $err);
	$err='';
	$t->same(1, dp_sql_seed_main(['seeds.php','rollback'], true, $writeOut, $writeErr, $manager));
	$t->contains('exactly one', $err);
	$out='';
	$t->same(2, dp_sql_seed_main(['seeds.php'], false, $writeOut, $writeErr, $manager));
	$t->contains('only available from CLI', $out);
	$options=dp_sql_seed_options(['seeds.php','apply','--path=one','--path=two','--id=a,b@2','--profile=demo','--cluster=analytics','--json','--confirm','--allow-demo']);
	$t->same(['one','two'], $options['paths']);
	$t->same(['a','b@2'], $options['ids']);
	$t->same(['demo'], $options['profiles']);
	$t->same('analytics', $options['cluster']);
	$t->isTrue($options['json']);
	$t->isTrue($options['confirm']);
	$t->isTrue($options['allow_demo']);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','--wat']), RuntimeException::class);
	$t->throws(static fn()=>dp_sql_seed_options(['seeds.php','apply','--id=a,,b']), RuntimeException::class);
	$err='';
	$t->same(1, dp_sql_seed_main(['seeds.php','apply','--id=,','--json'], true, $writeOut, $writeErr, $manager));
	$errorPayload=json_decode(trim($err), true, flags: JSON_THROW_ON_ERROR);
	$t->same(false, $errorPayload['ok'] ?? null);
	$t->same('apply', $errorPayload['command'] ?? null);
	$t->same(0, $callbackRuns);
	$err='';
	$t->same(1, dp_sql_seed_main(['seeds.php','unknown'], true, $writeOut, $writeErr, $manager));
	$t->contains('Unknown seed command', $err);
	$t->same(0, $callbackRuns);
})->tag('sql','seeds','cli')->maxMillis(5000);

test('SQL seed CLI safely auto-discovers conventional application bootstraps', static function(Context $t): void {
	$state=$t->state('sql.seeds',['bootstrap_loaded'=>null]);
	$workspace=$t->workspace('sql-seed-bootstrap');
	$root=str_replace('\\', '/', $workspace->root());
		$conventional=str_replace('\\', '/', $workspace->file('applications/serve/database/seeds/bootstrap.php', <<<'PHP'
<?php
\dp_seed_mark_bootstrap_loaded('conventional');
if(!class_exists('\dataphyre\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {}');
}
PHP));
		$workspace->file('applications/serve/database/seeds/demo.seed.php', <<<'PHP'
<?php
return ['id'=>'bootstrap.demo','version'=>1,'up'=>static fn()=>null];
PHP);
		$explicit=str_replace('\\', '/', $workspace->file('explicit-bootstrap.php', '<?php return true;'));
		$t->same($conventional, dp_sql_seed_bootstrap_path($root, 'serve'));
		$t->same($explicit, dp_sql_seed_bootstrap_path($root, 'serve', 'explicit-bootstrap.php'));
		$t->same(null, dp_sql_seed_bootstrap_path($root, 'missing'));
		$t->same(null, dp_sql_seed_bootstrap_path($root, ''));
		$t->throws(static fn()=>dp_sql_seed_bootstrap_path($root, '../outside'), RuntimeException::class);
		$t->throws(static fn()=>dp_sql_seed_bootstrap_path($root, '../outside', 'explicit-bootstrap.php'), RuntimeException::class);
		$options=dp_sql_seed_options(['seeds.php','list','--app=serve','--project-root='.$root]);
		$manager=dp_sql_seed_manager($options);
		$t->same('conventional',$state->get('bootstrap_loaded'));
		$t->same(['bootstrap.demo@1'], array_column($manager->catalog(), 'key'));
})->tag('sql','seeds','cli','bootstrap')->maxMillis(5000);
