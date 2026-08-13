<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelDataMutation;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDataMutationAccessDenied;
use Dataphyre\Panel\PanelDataMutationBatch;
use Dataphyre\Panel\PanelDataMutationConflict;
use Dataphyre\Panel\PanelDataMutationException;
use Dataphyre\Panel\PanelDataMutationReceipt;
use Dataphyre\Panel\PanelDataMutationUnsupported;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelPdoMutableDataSource;
use Dataphyre\Panel\PanelPdoSqlExecutor;
use Dataphyre\Panel\PanelSqlExecutionException;
use Dataphyre\Panel\PanelSqlMutableDataSource;
use Dataphyre\Panel\PanelSqlMutationSchema;
use Dataphyre\Panel\PanelSqlSchema;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelMutationPdoStatement extends PDOStatement {
	public function __construct(private readonly string $mode){}
	public function execute(?array $params=null):bool{return true;}
	public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool{return true;}
	public function rowCount():int{if($this->mode==='panel'){throw new PanelSqlExecutionException('row_count');}if($this->mode==='runtime'){throw new RuntimeException('private row-count failure');}return 1;}
	public function closeCursor():bool{return true;}
}

final class DpPanelMutationPdo extends PDO {
	public bool $active=false;public bool $rolledBack=false;
	public function __construct(private readonly string $statementMode='ok',private readonly bool $beginResult=true,private readonly bool $commitResult=true){}
	public function getAttribute(int $attribute):mixed{return'mysql';}
	public function prepare(string $query,array $options=[]):PDOStatement|false{return new DpPanelMutationPdoStatement($this->statementMode);}
	public function beginTransaction():bool{if($this->beginResult){$this->active=true;}return$this->beginResult;}
	public function inTransaction():bool{return$this->active;}
	public function commit():bool{if($this->commitResult){$this->active=false;}return$this->commitResult;}
	public function rollBack():bool{$this->rolledBack=true;$this->active=false;return true;}
	public function exec(string $statement):int|false{return 0;}
}

/** @return array{PDO,PanelSqlMutationSchema} */
function dp_panel_sql_mutation_fixture():array{
	$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE orders (id TEXT NOT NULL, tenant_id TEXT NOT NULL, email TEXT NULL, status TEXT NULL, revision INTEGER NOT NULL, PRIMARY KEY (tenant_id, id))');
	$read=PanelSqlSchema::make('orders',['id','tenant_id','email','status','revision'],'id',['name'=>'orders','tenant_field'=>'tenant_id','search_fields'=>['email','status']]);
	return[$pdo,PanelSqlMutationSchema::make($read,['email','status'],'revision','dp_panel_mutation_receipts')];
}

/** @param callable|null $authorize */
function dp_panel_sql_mutation_source(PDO $pdo,PanelSqlMutationSchema $schema,?callable $authorize=null):PanelPdoMutableDataSource{
	$options=['authorization_mode'=>'trusted','cursor_keys'=>['active'=>str_repeat('k',32)],'count_total'=>false,'mutation_clock'=>static fn():string=>'2026-07-16T12:00:00+00:00'];
	if($authorize!==null){$options['mutation_authorize']=$authorize;}
	return new PanelPdoMutableDataSource($pdo,$schema,$options);
}

/** @return array<string,mixed> */
function dp_panel_sql_mutation_options(string $idempotency,int|null $revision=null,array $extra=[]):array{
	$options=['idempotency_key'=>$idempotency,'actor_id'=>'operator-1','tenant'=>'north','authorization'=>['permission'=>'orders.write'],'metadata'=>['origin'=>'test']];
	if($revision!==null){$options['expected_revision']=$revision;}
	return array_replace($options,$extra);
}

test('SQL mutation schema is explicit allowlisted portable and non-destructive',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();
	$t->same(['email','status'],$schema->writableFields());$t->same('revision',$schema->revisionField());$t->same(100,$schema->maxBatch());
	foreach(['sqlite','pgsql','mysql']as$driver){$statements=$schema->migrationStatements($driver);$t->same(1,count($statements));$t->contains('CREATE TABLE IF NOT EXISTS',$statements[0]);$t->notContains('DROP ',$statements[0]);}
	$t->same('dp_panel_mutation_receipts',$schema->receiptTable());$t->isTrue($schema->supports('create'));$t->same('panel_sql_mutation_schema',$schema->jsonSerialize()['type']);
	$t->isFalse($schema->manifest()['automatic_schema_mutation']);$t->same('explicit_idempotent',$schema->manifest()['migration']);
	$source=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);
	$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'dp_panel_mutation_receipts'")->fetchColumn());
	$t->throws(static fn()=>$source->inspectMutationSchema(),PanelDataMutationException::class);
	$installation=$source->installMutationSchema();$t->isTrue($installation['idempotent']);$t->isFalse($installation['destructive']);$t->isTrue($source->inspectMutationSchema()['compatible']);
	$source->installMutationSchema();
	$brokenSchema=PanelSqlMutationSchema::make($schema->schema(),['email','status'],'revision','orders');$broken=dp_panel_sql_mutation_source($pdo,$brokenSchema,static fn():bool=>true);$error=$t->throws(static fn()=>$broken->installMutationSchema(),PanelDataMutationException::class);$t->same('mutation_schema_migration_failed',$error->publicCode());
	$t->throws(static fn()=>PanelSqlMutationSchema::make($schema->schema(),['revision'],'revision','receipts'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlMutationSchema::make($schema->schema(),['email'],'revision','receipts',['operations'=>['drop']]),InvalidArgumentException::class);
	$t->throws(static fn()=>$schema->migrationStatements('oracle'),InvalidArgumentException::class);
})->tag('panel','data-source','sql','mutation','migration','security')->maxMillis(3000);

test('PDO SQL mutations persist replay receipts and revision-fence the complete lifecycle',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$authorizations=0;
	$authorize=static function(PanelDataMutation $mutation,?array $before,?array $after)use(&$authorizations):bool{$authorizations++;return($mutation->authorizationMetadata()['permission']??null)==='orders.write'&&($after===null||($after['tenant_id']??null)==='north');};
	$source=dp_panel_sql_mutation_source($pdo,$schema,$authorize);$source->installMutationSchema();
	$create=PanelDataMutation::create('o1',['email'=>'one@example.test','status'=>'open'],dp_panel_sql_mutation_options('create-o1-0001'));
	$created=$source->mutate($create);$t->same('created',$created->outcome());$t->same(1,$created->revision());$t->same('o1',$created->record()['id']);$t->same('north',$created->record()['tenant_id']);$t->isFalse($created->replayed());
	$t->same('o1',$source->find('o1',PanelDataQuery::make()->tenant('north'))['id']);$t->same(1,$source->query(PanelDataQuery::make()->tenant('north')->limit(10))->count());$t->same('panel_sql_mutable_data_source',$source->source()->jsonSerialize()['type']);$t->same($schema,$source->source()->mutationSchema());$t->same($source->source()->executor(),$source->source()->executor());$t->same('orders',$source->source()->readSource()->manifest()['name']);$t->same('pdo_mutable',$source->jsonSerialize()['facade']);$t->same($source->source(),$source->source());
	$secondSource=dp_panel_sql_mutation_source($pdo,$schema,$authorize);$replayed=$secondSource->mutate($create);$t->isTrue($replayed->replayed());$t->same($created->receiptId(),$replayed->receiptId());$t->same(2,$authorizations);

	$update=PanelDataMutation::update('o1',['status'=>'review'],dp_panel_sql_mutation_options('update-o1-0001',1));
	$updated=$source->mutate($update);$t->same('updated',$updated->outcome());$t->same(2,$updated->revision());$t->same(['status'],$updated->changedFields());
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::update('o1',['status'=>'closed'],dp_panel_sql_mutation_options('stale-o1-00001',1))),PanelDataMutationConflict::class);
	$unchanged=$source->mutate(PanelDataMutation::update('o1',['status'=>'review'],dp_panel_sql_mutation_options('no-op-o1-00001',2)));$t->same('unchanged',$unchanged->outcome());$t->same(2,$unchanged->revision());
	$deleted=$source->mutate(PanelDataMutation::delete('o1',dp_panel_sql_mutation_options('delete-o1-0001',2)));$t->same('deleted',$deleted->outcome());$t->same(3,$deleted->revision());$t->same(null,$deleted->record());
	$t->isTrue($source->mutate(PanelDataMutation::delete('o1',dp_panel_sql_mutation_options('delete-o1-0001',2)))->replayed());
	$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id = 'o1'")->fetchColumn());
	$t->same(4,(int)$pdo->query('SELECT COUNT(*) FROM dp_panel_mutation_receipts')->fetchColumn());
	$manifest=$source->manifest();$t->isTrue($manifest['consistency']['transactional_receipts']);$t->isFalse($manifest['security']['raw_idempotency_stored']);$t->notContains('create-o1-0001',json_encode($manifest,JSON_THROW_ON_ERROR));
})->tag('panel','data-source','sql','mutation','idempotency','concurrency')->maxMillis(3000);

test('SQL mutation batches distinguish atomic rollback from explicit partial commit',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$source=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);$source->installMutationSchema();
	$atomic=new PanelDataMutationBatch([
		PanelDataMutation::create('a1',['status'=>'open'],dp_panel_sql_mutation_options('atomic-a1-00001')),
		PanelDataMutation::update('missing',['status'=>'review'],dp_panel_sql_mutation_options('atomic-miss-001',1)),
	],true);
	$t->throws(static fn()=>$source->mutateBatch($atomic),PanelDataMutationException::class);
	$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id = 'a1'")->fetchColumn());$t->same(0,(int)$pdo->query('SELECT COUNT(*) FROM dp_panel_mutation_receipts')->fetchColumn());
	$nonAtomic=new PanelDataMutationBatch([
		PanelDataMutation::create('p1',['status'=>'open'],dp_panel_sql_mutation_options('partial-p1-0001')),
		PanelDataMutation::update('missing',['status'=>'review'],dp_panel_sql_mutation_options('partial-miss-001',1)),
	],false);
	$t->throws(static fn()=>$source->mutateBatch($nonAtomic),PanelDataMutationException::class);
	$t->same(1,(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id = 'p1'")->fetchColumn());$t->same(1,(int)$pdo->query('SELECT COUNT(*) FROM dp_panel_mutation_receipts')->fetchColumn());
})->tag('panel','data-source','sql','mutation','batch','atomicity')->maxMillis(3000);

test('SQL mutation authorization and tenant boundaries fail closed before writes',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$denied=dp_panel_sql_mutation_source($pdo,$schema);$denied->installMutationSchema();
	$t->isFalse($denied->capabilities()['mutations']);
	$t->throws(static fn()=>$denied->mutate(PanelDataMutation::create('o1',['status'=>'open'],dp_panel_sql_mutation_options('denied-o1-0001'))),PanelDataMutationUnsupported::class);
	$throwing=dp_panel_sql_mutation_source($pdo,$schema,static function():bool{throw new RuntimeException('secret database policy details');});
	try{$throwing->mutate(PanelDataMutation::create('o1',['status'=>'open'],dp_panel_sql_mutation_options('throwing-o1-001')));$t->fail('Throwing authorizer must fail closed.');}catch(PanelDataMutationAccessDenied $error){$t->same('mutation_authorization_failed',$error->publicCode());$t->notContains('secret',$error->getMessage());}
	$t->same(0,(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
	$t->throws(static fn()=>$throwing->mutate(PanelDataMutation::create('o2',['tenant_id'=>'south','status'=>'open'],dp_panel_sql_mutation_options('tenant-o2-00001'))),PanelDataMutationAccessDenied::class);
	$allowed=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);$t->throws(static fn()=>$allowed->mutate(PanelDataMutation::create('o3',['id'=>'different','status'=>'open'],dp_panel_sql_mutation_options('identity-o3-0001'))),PanelDataMutationConflict::class);
	$preserved=$allowed->mutate(PanelDataMutation::create('o4',[
		'id'=>'o4',
		'tenant_id'=>'north',
		'status'=>'open',
	],dp_panel_sql_mutation_options('identity-o4-0001')));
	$t->same('created',$preserved->outcome());
	$t->same('o4',$preserved->record()['id']);
	$t->same('north',$preserved->record()['tenant_id']);
})->tag('panel','data-source','sql','mutation','authorization','tenant')->maxMillis(3000);

test('PDO mutation executor composes safely inside host transactions with savepoints',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$source=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);$source->installMutationSchema();
	$pdo->beginTransaction();$receipt=$source->mutate(PanelDataMutation::create('host1',['status'=>'open'],dp_panel_sql_mutation_options('host-tx-0000001')));$t->same('created',$receipt->outcome());$t->isTrue($pdo->inTransaction());$pdo->rollBack();
	$pdo->beginTransaction();$t->throws(static fn()=>$source->source()->executor()->transaction(static fn()=>throw new RuntimeException('nested failure')),RuntimeException::class);$t->isTrue($pdo->inTransaction());$pdo->rollBack();
	$t->same(0,(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id = 'host1'")->fetchColumn());$t->same(0,(int)$pdo->query('SELECT COUNT(*) FROM dp_panel_mutation_receipts')->fetchColumn());
	$source->mutate(PanelDataMutation::create('host1',['status'=>'open'],dp_panel_sql_mutation_options('host-tx-0000001')));$t->same(1,(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id = 'host1'")->fetchColumn());
})->tag('panel','data-source','sql','mutation','pdo','savepoint')->maxMillis(3000);

test('stored mutation receipts reject shape and identity tampering',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$source=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);$source->installMutationSchema();
	$mutation=PanelDataMutation::create('o1',['status'=>'open'],dp_panel_sql_mutation_options('tamper-o1-00001'));$receipt=$source->mutate($mutation);$payload=$receipt->jsonSerialize();
	$t->same($receipt->receiptId(),PanelDataMutationReceipt::fromArray($payload)->receiptId());
	$payload['id']='mutation_'.str_repeat('0',40);$t->throws(static fn()=>PanelDataMutationReceipt::fromArray($payload),UnexpectedValueException::class);
	$pdo->exec("UPDATE dp_panel_mutation_receipts SET receipt_json = '{}' WHERE source_name = 'orders'");
	try{$source->mutate($mutation);$t->fail('Corrupt persisted receipts must fail closed.');}catch(PanelDataMutationException $error){$t->same('mutation_storage_corrupt',$error->publicCode());$t->isFalse($error->retryable());}
})->tag('panel','data-source','sql','mutation','integrity')->maxMillis(3000);

test('durable SQL mutations pass the universal mutable-adapter conformance pack',static function(Context $t):void{
	[$pdo,$schema]=dp_panel_sql_mutation_fixture();$source=dp_panel_sql_mutation_source($pdo,$schema,static fn():bool=>true);$source->installMutationSchema();
	$mutation=PanelDataMutation::create('c1',['status'=>'open'],dp_panel_sql_mutation_options('conform-c1-00001'));
	$batch=new PanelDataMutationBatch([
		PanelDataMutation::create('c2',['status'=>'open'],dp_panel_sql_mutation_options('conform-c2-00001')),
		PanelDataMutation::create('c3',['status'=>'open'],dp_panel_sql_mutation_options('conform-c3-00001')),
	]);
	$report=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::mutableDataSource(),$source,['allow_destructive'=>true,'mutation'=>$mutation,'batch'=>$batch]);
	$t->isTrue($report->passed());$t->same(3,$report->summary()['passed']);$t->notContains('conform-c1-00001',json_encode($report,JSON_THROW_ON_ERROR));
})->tag('panel','data-source','sql','mutation','conformance')->maxMillis(5000);

test('PDO executor normalizes row-count failures and rolls back failed transaction controls',static function(Context $t):void{
	$panelExecutor=new PanelPdoSqlExecutor(new DpPanelMutationPdo('panel'));$error=$t->throws(static fn()=>$panelExecutor->execute('UPDATE records SET value = 1'),PanelSqlExecutionException::class);$t->same('row_count',$error->operation());
	$runtimeExecutor=new PanelPdoSqlExecutor(new DpPanelMutationPdo('runtime'));$error=$t->throws(static fn()=>$runtimeExecutor->execute('UPDATE records SET value = 1'),PanelSqlExecutionException::class);$t->same('execute',$error->operation());$t->instanceOf(RuntimeException::class,$error->getPrevious());
	$beginPdo=new DpPanelMutationPdo('ok',false,true);$beginExecutor=new PanelPdoSqlExecutor($beginPdo);$t->throws(static fn()=>$beginExecutor->transaction(static fn():string=>'never'),RuntimeException::class);$t->isFalse($beginPdo->active);
	$commitPdo=new DpPanelMutationPdo('ok',true,false);$commitExecutor=new PanelPdoSqlExecutor($commitPdo);$t->throws(static fn()=>$commitExecutor->transaction(static fn():string=>'value'),PanelSqlExecutionException::class);$t->isTrue($commitPdo->rolledBack);$t->isFalse($commitPdo->active);
})->tag('panel','data-source','sql','pdo','transactions','failures')->maxMillis(3000);
