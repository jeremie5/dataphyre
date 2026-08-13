<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelCollaborationStateEngine;
use Dataphyre\Panel\PanelCollaborationStorageException;
use Dataphyre\Panel\PanelPdoCollaborationStore;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_pdo_collaboration_connection(string $path,int $busyMilliseconds=5000):PDO {
	$pdo=new PDO('sqlite:'.$path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA busy_timeout = '.max(0, $busyMilliseconds));
	return $pdo;
}

/** @param array<string,mixed> $options @return array{path:string,pdo:PDO,store:PanelPdoCollaborationStore,clock:Closure} */
function dp_panel_pdo_collaboration_fixture(Context $t,string $name,array $options=[]):array {
	$path=$t->tempDirectory('panel-pdo-collaboration-'.$name).DIRECTORY_SEPARATOR.'collaboration.sqlite';
	$pdo=dp_panel_pdo_collaboration_connection($path);
	$now='2026-07-20T14:00:00Z';
	$clock=static function() use (&$now):string{return $now;};
	$store=new PanelPdoCollaborationStore($pdo, $options, $clock);
	return compact('path','pdo','store','clock');
}

function dp_panel_pdo_collaboration_error(Context $t,callable $callback,string $code):PanelCollaborationStorageException {
	try{$callback();}
	catch(PanelCollaborationStorageException $error){
		$t->same($code, $error->errorCode());
		return $error;
	}
	throw new RuntimeException("Expected PanelCollaborationStorageException {$code}.");
}

final class DpPanelCollaborationPdoStatementProbe extends PDOStatement {
	public function __construct(private readonly DpPanelCollaborationPdoProbe $pdo){}
	public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {
		$this->pdo->bindings[(string)$param]=['value'=>$value,'type'=>$type];
		return true;
	}
	public function execute(?array $params=null):bool {
		if($this->pdo->executeFailure!==null){throw $this->pdo->executeFailure;}
		return $this->pdo->executeResult;
	}
	public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
		return $this->pdo->fetchValue;
	}
	public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
		return $this->pdo->fetchAllValue;
	}
	public function fetchColumn(int $column=0):mixed{return $this->pdo->fetchColumnValue;}
	public function rowCount():int{return $this->pdo->rowCountValue;}
}

final class DpPanelCollaborationPdoProbe extends PDO {
	public ?Throwable $attributeFailure=null;
	public ?Throwable $prepareFailure=null;
	public ?Throwable $executeFailure=null;
	public ?Throwable $rollbackFailure=null;
	public bool $executeResult=true;
	public bool $beginResult=true;
	public bool $commitResult=true;
	public bool $rollbackResult=true;
	public bool $transaction=false;
	public bool $failFirstImmediateWithLock=false;
	public ?string $execFalseNeedle=null;
	public ?string $execThrowNeedle=null;
	public mixed $fetchValue=false;
	/** @var list<array<string,mixed>> */ public array $fetchAllValue=[];
	public mixed $fetchColumnValue='1';
	public int $rowCountValue=1;
	/** @var list<string> */ public array $events=[];
	/** @var array<string,array{value:mixed,type:int}> */ public array $bindings=[];
	private int $immediateAttempts=0;

	public function __construct(private readonly string $driverName='sqlite'){}
	public function getAttribute(int $attribute):mixed {
		if($this->attributeFailure!==null){throw $this->attributeFailure;}
		return $attribute===PDO::ATTR_DRIVER_NAME?$this->driverName:PDO::ERRMODE_EXCEPTION;
	}
	public function prepare(string $query,array $options=[]):PDOStatement|false {
		$this->events[]='prepare:'.$query;
		if($this->prepareFailure!==null){throw $this->prepareFailure;}
		return new DpPanelCollaborationPdoStatementProbe($this);
	}
	public function exec(string $statement):int|false {
		$this->events[]=$statement;
		if($statement==='BEGIN IMMEDIATE'&&$this->failFirstImmediateWithLock&&$this->immediateAttempts++===0){
			$error=new PDOException('database is locked');
			$error->errorInfo=['HY000',5,'database is locked'];
			throw $error;
		}
		if($this->execThrowNeedle!==null&&str_contains($statement,$this->execThrowNeedle)){
			throw new RuntimeException('PDO exec probe failure.');
		}
		if($this->execFalseNeedle!==null&&str_contains($statement,$this->execFalseNeedle)){return false;}
		return 0;
	}
	public function beginTransaction():bool {
		$this->events[]='begin';
		if($this->beginResult){$this->transaction=true;}
		return $this->beginResult;
	}
	public function commit():bool {
		$this->events[]='commit';
		if($this->commitResult){$this->transaction=false;}
		return $this->commitResult;
	}
	public function rollBack():bool {
		$this->events[]='rollback';
		if($this->rollbackFailure!==null){throw $this->rollbackFailure;}
		if($this->rollbackResult){$this->transaction=false;}
		return $this->rollbackResult;
	}
	public function inTransaction():bool{return $this->transaction;}
	public function lastInsertId(?string $name=null):string|false{return '1';}
}

suite('Panel durable shared-SQL collaboration store')
	->contract('panel.collaboration.pdo-store',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','atomic-state','change-feed','savepoints','restart','cross-process')
	->isolation('case')
	->tag('panel','collaboration','pdo','distributed','security')
	->group('framework-coverage');

test('schema plans are explicit portable idempotent and connection redacted',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'schema', [
		'table_prefix'=>'collab_schema',
		'maximum_state_bytes'=>131072,
		'maximum_change_bytes'=>4096,
		'change_retention'=>32,
		'transaction_retries'=>2,
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$missing=dp_panel_pdo_collaboration_error($t, static fn()=>$store->state(), 'schema_required');
	$t->isFalse($missing->retryable());
	$first=$store->installSchema();
	$second=$store->installSchema();
	$t->same($first, $second);
	$t->same('sqlite', $store->driver());
	$t->same(5, $first['statements']);
	$t->isTrue($first['idempotent']);
	$t->isFalse($first['destructive']);
	$t->same($store->schemaStatements(), PanelPdoCollaborationStore::schemaStatementsFor('sqlite','collab_schema'));

	$sqlite=PanelPdoCollaborationStore::schemaStatementsFor('sqlite','collab_plan');
	$mysql=PanelPdoCollaborationStore::schemaStatementsFor('mysql','collab_plan');
	$pgsql=PanelPdoCollaborationStore::schemaStatementsFor('pgsql','collab_plan');
	$t->same(5, count($sqlite));
	$t->same(5, count($mysql));
	$t->same(5, count($pgsql));
	$t->contains('AUTOINCREMENT', $sqlite[2]);
	$t->contains('ENGINE=InnoDB', $mysql[0]);
	$t->contains('GENERATED BY DEFAULT AS IDENTITY', $pgsql[2]);
	$t->same('BEGIN IMMEDIATE', PanelPdoCollaborationStore::dialectPlanFor('sqlite')['write_begin']);
	$t->same(' FOR UPDATE', PanelPdoCollaborationStore::dialectPlanFor('mysql')['lock_suffix']);
	$t->contains('REPEATABLE READ', PanelPdoCollaborationStore::dialectPlanFor('pgsql')['read_after'][0]);

	$manifest=$store->manifest(['password'=>'must-not-serialize','owner'=>'host']);
	$encoded=json_encode($store, JSON_THROW_ON_ERROR);
	$t->same('locked_single_row', $manifest['state_write_serialization']);
	$t->isTrue($manifest['distributed']);
	$t->isTrue($manifest['host_transactions_preserved']);
	$t->isTrue($manifest['capabilities']['receipt_chain_integrity']);
	$t->isFalse($manifest['exactly_once']);
	foreach([$fixture['path'],'collab_schema','sqlite:','must-not-serialize','state_json','SELECT '] as $secret){
		$t->notContains($secret, $encoded);
	}
})->tag('panel','collaboration','pdo','schema','manifest')->maxMillis(5000);

test('manager state receipts and change cursor survive independent store restart',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'restart', ['table_prefix'=>'collab_restart','change_retention'=>64]);
	$store=$fixture['store'];
	$store->installSchema();
	$manager=new PanelCollaborationManager($store);
	$thread=$manager->createThread('operator-a','studio_document','document-a','Review checkout',[], 'thread-a');
	$comment=$manager->comment('thread-a','operator-a','Please ask @reviewer before publishing.', ['reviewer']);
	$manager->assign('studio_document','document-a','reviewer','operator-a');
	$manager->watch('studio_document','document-a','operator-a');
	$lease=$manager->acquirePresence('studio:document-a','operator-a',60,['device'=>'browser']);
	$manager->typing('thread-a','operator-a',true,12);

	$t->same('thread-a', $thread['id']);
	$t->same('thread-a', $comment['thread_id']);
	$t->isTrue($manager->verifyReceipts()['valid']);
	$t->same(6, $manager->cursor());
	$t->same(1, count($manager->threads('studio_document','document-a')));
	$t->same(['operator-a'], $manager->watchers('studio_document','document-a'));
	$t->same(['operator-a'], $manager->typingUsers('thread-a'));
	$t->same(1, count($manager->presence('studio:document-a')));

	$secondStore=new PanelPdoCollaborationStore(
		dp_panel_pdo_collaboration_connection($fixture['path']),
		['table_prefix'=>'collab_restart','change_retention'=>64],
		$fixture['clock'],
	);
	$second=new PanelCollaborationManager($secondStore);
	$t->same('Review checkout', $second->thread('thread-a')['title']);
	$t->same($comment['id'], $second->comments('thread-a')[0]['id']);
	$t->same('reviewer', $second->assignment('studio_document','document-a')['assignee']);
	$t->same(6, $second->cursor());
	$t->same(6, count($second->changesSince(0,100)['changes']));
	$t->isTrue($second->verifyReceipts()['valid']);

	$raw=(string)$fixture['pdo']->query('SELECT state_json FROM collab_restart_state WHERE singleton = 1')->fetchColumn();
	$changes=(string)$fixture['pdo']->query("SELECT GROUP_CONCAT(event_json, '') FROM collab_restart_changes")->fetchColumn();
	$t->notContains($lease['lease_token'], $raw.$changes);
	$t->notContains('lease_token', $raw.$changes);
	$t->notContains('lease_hash', $changes);
})->tag('panel','collaboration','pdo','manager','restart','receipts','privacy')->maxMillis(8000);

test('bounded feed resets stale and future cursors while keeping metadata secret free',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'changes', ['table_prefix'=>'collab_changes','change_retention'=>8]);
	$store=$fixture['store'];
	$store->installSchema();
	for($index=1;$index<=12;$index++){
		$store->transaction(
			static function(array &$state) use ($index):void{$state['meta']['tick']=$index;},
			'collaboration.probe.changed',
			['ordinal'=>$index,'password'=>'event-secret-'.$index,'authorization'=>'bearer '.$index],
		);
	}
	$t->same(12, $store->cursor());
	$initial=$store->changesSince(0,100);
	$t->isFalse($initial['reset_required']);
	$t->same(8, count($initial['changes']));
	$t->same(5, $initial['oldest_cursor']);
	$stale=$store->changesSince(1,100);
	$t->isTrue($stale['reset_required']);
	$t->same('retention_window', $stale['reset_reason']);
	$t->same([], $stale['changes']);
	$t->same(12, $stale['snapshot']['meta']['tick']);
	$future=$store->changesSince(99,10);
	$t->isTrue($future['reset_required']);
	$t->same('future_cursor', $future['reset_reason']);
	$t->same(12, $future['cursor']);
	$current=$store->changesSince(12,10);
	$t->isFalse($current['reset_required']);
	$t->same([], $current['changes']);
	$raw=(string)$fixture['pdo']->query("SELECT GROUP_CONCAT(event_json, '') FROM collab_changes_changes")->fetchColumn();
	$t->notContains('event-secret', $raw);
	$t->notContains('bearer', $raw);
	foreach($initial['changes'] as $change){
		$t->isFalse(array_key_exists('password', $change));
		$t->isFalse(array_key_exists('authorization', $change));
	}
})->tag('panel','collaboration','pdo','change-feed','retention','privacy')->maxMillis(6000);

test('host transactions use savepoints and preserve host rollback and commit authority',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'host-transactions', ['table_prefix'=>'collab_host']);
	$store=$fixture['store'];
	$store->installSchema();
	$manager=new PanelCollaborationManager($store);

	$fixture['pdo']->beginTransaction();
	$manager->createThread('operator','record','rollback','Rollback me',[], 'thread-rollback');
	$t->same('thread-rollback', $manager->thread('thread-rollback')['id']);
	$t->isTrue($fixture['pdo']->inTransaction());
	dp_panel_pdo_collaboration_error($t, static fn()=>$store->installSchema(), 'transaction_conflict');
	$fixture['pdo']->rollBack();

	$outside=new PanelPdoCollaborationStore(
		dp_panel_pdo_collaboration_connection($fixture['path']),
		['table_prefix'=>'collab_host'],
		$fixture['clock'],
	);
	$t->same(null, (new PanelCollaborationManager($outside))->thread('thread-rollback'));
	$t->same(0, $outside->cursor());

	$fixture['pdo']->beginTransaction();
	$manager->createThread('operator','record','commit','Commit me',[], 'thread-commit');
	$t->isTrue($fixture['pdo']->inTransaction());
	$fixture['pdo']->commit();
	$t->same('thread-commit', (new PanelCollaborationManager($outside))->thread('thread-commit')['id']);
	$t->same(1, $outside->cursor());

	$fixture['pdo']->beginTransaction();
	$t->throws(
		static fn()=>$store->transaction(
			static function(array &$state):void{$state['meta']['rejected']=true;throw new DomainException('reject mutation');},
			'collaboration.rejected',
		),
		DomainException::class,
	);
	$t->isTrue($fixture['pdo']->inTransaction());
	$t->isFalse(isset($store->state()['meta']['rejected']));
	$fixture['pdo']->rollBack();
})->tag('panel','collaboration','pdo','transactions','savepoints','php82')->maxMillis(7000);

test('independent PHP workers serialize collaboration receipts without lost updates',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'workers', [
		'table_prefix'=>'collab_workers',
		'change_retention'=>128,
		'transaction_retries'=>10,
		'retry_delay_microseconds'=>1000,
	]);
	$fixture['store']->installSchema();
	$panelRoot=dirname(__DIR__);
	$code=<<<'PHP'
require $argv[1].'/unit_tests/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
$pdo=new PDO('sqlite:'.$argv[2]);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout = 5000');
$store=new \Dataphyre\Panel\PanelPdoCollaborationStore($pdo,['table_prefix'=>'collab_workers','change_retention'=>128,'transaction_retries'=>10,'retry_delay_microseconds'=>1000]);
$manager=new \Dataphyre\Panel\PanelCollaborationManager($store);
for($i=0;$i<10;$i++){$manager->createThread('worker-'.$argv[3],'record',$argv[3].'-'.$i,'',[],'thread-'.$argv[3].'-'.$i);}
echo '10';
PHP;
	$workers=[];
	foreach(['a','b','c'] as $worker){
		$workers[]=$t->startPhpProcess(['-r',$code,$panelRoot,$fixture['path'],$worker], timeout_millis:20000);
	}
	$total=0;
	foreach($workers as $process){
		$result=$process->wait();
		if(!$result->succeeded()){
			throw new RuntimeException('Collaboration worker failed: '.$result->stderr().' '.$result->stdout());
		}
		$t->same('', trim($result->stderr()));
		$total+=(int)trim($result->stdout());
	}
	$manager=new PanelCollaborationManager($fixture['store']);
	$t->same(30, $total);
	$t->same(30, count($manager->threads()));
	$t->same(30, $manager->cursor());
	$t->same(30, $manager->verifyReceipts()['count']);
	$t->isTrue($manager->verifyReceipts()['valid']);
	$t->same(30, count($manager->changesSince(0,100)['changes']));
})->tag('panel','collaboration','pdo','cross-process','serialization','receipts')->maxMillis(30000);

test('platform preserves caller store and fail-closed validation rolls back partial writes',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'platform', [
		'table_prefix'=>'collab_platform',
		'maximum_state_bytes'=>65536,
		'maximum_change_bytes'=>1024,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	$platform=PanelPlatform::defaults([
		'state_root'=>$t->tempDirectory('panel-pdo-collaboration-platform'),
		'authentication'=>false,
		'media'=>false,
		'collaboration'=>['store'=>$store],
	]);
	$t->same($store, $platform->collaborationStore());
	$t->same($store, $platform->collaboration()->store());
	$t->isTrue($platform->manifest()->ready('collaboration'));
	$domain=$platform->manifest()->domain('collaboration');
	foreach(['memory_store','filesystem_store','pdo_store','storage_error'] as $feature){
		$t->isTrue($domain['features'][$feature]??false);
	}
	$t->notContains($fixture['path'], json_encode($platform, JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>PanelPlatform::defaults([
		'state_root'=>$t->tempDirectory('panel-pdo-collaboration-invalid-platform'),
		'authentication'=>false,
		'media'=>false,
		'collaboration'=>['store'=>new stdClass()],
	]), InvalidArgumentException::class);

	$tooLarge=dp_panel_pdo_collaboration_error($t, static fn()=>$store->transaction(
		static function(array &$state):void{$state['meta']['large']=str_repeat('x',70000);},
		'collaboration.large-state',
	), 'state_too_large');
	$t->isFalse($tooLarge->retryable());
	$t->isFalse(isset($store->state()['meta']['large']));

	dp_panel_pdo_collaboration_error($t, static fn()=>$store->transaction(
		static function(array &$state):void{$state['meta']['must_rollback']=true;},
		'collaboration.large-change',
		['message'=>str_repeat('x',2000)],
	), 'change_too_large');
	$t->isFalse(isset($store->state()['meta']['must_rollback']));
	$t->same(0, $store->cursor());
	$t->throws(static fn()=>$store->transaction(
		static function(array &$state):void{unset($state['threads']);},
		'collaboration.invalid-state',
	), UnexpectedValueException::class);
	$t->same(0, $store->cursor());
})->tag('panel','collaboration','pdo','platform','validation','rollback')->maxMillis(10000);

test('persisted state and change hydration reject every malformed integrity shape',static function(Context $t):void {
	$fixture=dp_panel_pdo_collaboration_fixture($t, 'integrity-branches', ['table_prefix'=>'collab_integrity']);
	$store=$fixture['store'];
	$store->installSchema();
	$access=$t->nonPublic($store);

	$t->throws(static fn()=>$store->transaction(
		static function(array &$state):void{$state['meta']['ignored']=true;},
		'collaboration.invalid-metadata',
		['list-value'],
	), InvalidArgumentException::class);

	$row=$fixture['pdo']->query(
		'SELECT storage_revision, state_json, state_bytes, state_digest, updated_at FROM collab_integrity_state WHERE singleton = 1',
	)->fetch(PDO::FETCH_ASSOC);
	if(!is_array($row)){throw new RuntimeException('Expected collaboration state fixture row.');}

	$missingString=$row;
	unset($missingString['updated_at']);
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('decodeState', $missingString), 'storage_corrupt');

	$invalidRevision=$row;
	$invalidRevision['storage_revision']='01';
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('decodeState', $invalidRevision), 'storage_corrupt');

	$listState=$row;
	$listState['state_json']='[]';
	$listState['state_bytes']=2;
	$listState['state_digest']=hash('sha256', '[]');
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('decodeState', $listState), 'storage_corrupt');

	dp_panel_pdo_collaboration_error(
		$t,
		static fn()=>$access->invoke('encodeState', ['not_json'=>NAN]),
		'state_invalid',
	);

	$initial=PanelCollaborationStateEngine::initialState();
	$invalidOrder=$initial;
	$invalidOrder['receipt_order']=['not-a-list'=>'receipt'];
	$t->throws(static fn()=>$access->invoke('assertState', $invalidOrder), UnexpectedValueException::class);

	$invalidSequence=$initial;
	$invalidSequence['receipt_sequence']='0';
	$t->throws(static fn()=>$access->invoke('assertState', $invalidSequence), UnexpectedValueException::class);

	$inconsistentInventory=$initial;
	$inconsistentInventory['receipts']=['receipt'=>[]];
	$t->throws(static fn()=>$access->invoke('assertState', $inconsistentInventory), UnexpectedValueException::class);

	$invalidInventory=$initial;
	$invalidInventory['receipts']=[1=>[]];
	$invalidInventory['receipt_order']=[1];
	$invalidInventory['receipt_sequence']=1;
	$t->throws(static fn()=>$access->invoke('assertState', $invalidInventory), UnexpectedValueException::class);

	$manager=new PanelCollaborationManager($store);
	$manager->createThread('operator','record','integrity','Integrity branch',[], 'integrity-thread');
	$tampered=$store->state();
	$receiptId=$tampered['receipt_order'][0]??null;
	if(!is_string($receiptId)){throw new RuntimeException('Expected collaboration receipt fixture.');}
	$tampered['receipts'][$receiptId]['hash']=str_repeat('0',64);
	$t->throws(static fn()=>$access->invoke('assertState', $tampered), UnexpectedValueException::class);

	$change=[
		'change_id'=>'1',
		'event_type'=>'collaboration.probe',
		'event_json'=>'{}',
		'event_bytes'=>2,
		'occurred_at'=>'2026-07-20T14:00:00Z',
	];
	$missingChange=$change;
	unset($missingChange['event_type']);
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('hydrateChange', $missingChange), 'storage_corrupt');
	$wrongBytes=$change;
	$wrongBytes['event_bytes']=3;
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('hydrateChange', $wrongBytes), 'storage_corrupt');
	$listMetadata=$change;
	$listMetadata['event_json']='[1]';
	$listMetadata['event_bytes']=3;
	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('hydrateChange', $listMetadata), 'storage_corrupt');

	dp_panel_pdo_collaboration_error($t, static fn()=>$access->invoke('integer', '01', 0), 'storage_corrupt');

	$clockPdo=new PDO('sqlite::memory:');
	$clockPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$invalidClock=new PanelPdoCollaborationStore($clockPdo, [], static fn():array=>[]);
	$t->throws(static fn()=>$t->nonPublic($invalidClock)->invoke('now'), UnexpectedValueException::class);
})->tag('panel','collaboration','pdo','integrity','hydration','coverage')->maxMillis(7000);

test('PDO driver probes contain retries savepoint failures and transaction edge outcomes',static function(Context $t):void {
	$inspection=new DpPanelCollaborationPdoProbe();
	$inspection->attributeFailure=new RuntimeException('connection inspection failed');
	$t->throws(static fn()=>new PanelPdoCollaborationStore($inspection), InvalidArgumentException::class);

	$migration=dp_panel_pdo_collaboration_fixture($t, 'migration-wrap', ['table_prefix'=>'collab_migration_wrap']);
	$migration['store']->installSchema();
	$migration['pdo']->exec('UPDATE collab_migration_wrap_state SET state_bytes = state_bytes + 1');
	dp_panel_pdo_collaboration_error($t, static fn()=>$migration['store']->installSchema(), 'migration_failed');

	$queryFailure=new DpPanelCollaborationPdoProbe();
	$queryFailure->prepareFailure=new PDOException('private schema query failed');
	$queryFailureStore=new PanelPdoCollaborationStore($queryFailure);
	$t->throws(
		static fn()=>$t->nonPublic($queryFailureStore)->invoke('assertSchema', false),
		PDOException::class,
	);

	$missingRow=new DpPanelCollaborationPdoProbe();
	$missingRowStore=new PanelPdoCollaborationStore($missingRow);
	dp_panel_pdo_collaboration_error(
		$t,
		static fn()=>$t->nonPublic($missingRowStore)->invoke('assertSchema', false),
		'schema_required',
	);

	$pgsql=new DpPanelCollaborationPdoProbe('pgsql');
	$pgsql->fetchColumnValue='7';
	$pgsqlStore=new PanelPdoCollaborationStore($pgsql);
	$t->nonPublic($pgsqlStore)->invoke(
		'recordChange',
		'collaboration.probe',
		['probe'=>true],
		'2026-07-20T14:00:00Z',
	);
	$t->isTrue(isset($pgsql->bindings[':event_type']));

	$retry=new DpPanelCollaborationPdoProbe();
	$retry->failFirstImmediateWithLock=true;
	$retryStore=new PanelPdoCollaborationStore($retry, [
		'transaction_retries'=>1,
		'retry_delay_microseconds'=>1,
	]);
	$t->same(
		'retried',
		$t->nonPublic($retryStore)->invoke('databaseTransaction', true, static fn():string=>'retried'),
	);
	$t->same(2, count(array_filter($retry->events, static fn(string $event):bool=>$event==='BEGIN IMMEDIATE')));

	$unavailable=new DpPanelCollaborationPdoProbe();
	$unavailable->execThrowNeedle='BEGIN IMMEDIATE';
	$unavailableStore=new PanelPdoCollaborationStore($unavailable, ['transaction_retries'=>0]);
	$unavailableError=dp_panel_pdo_collaboration_error(
		$t,
		static fn()=>$t->nonPublic($unavailableStore)->invoke('databaseTransaction', true, static fn():string=>'never'),
		'storage_unavailable',
	);
	$t->isTrue($unavailableError->retryable());

	$savepoints=new DpPanelCollaborationPdoProbe();
	$savepointStore=new PanelPdoCollaborationStore($savepoints);
	$savepointAccess=$t->nonPublic($savepointStore);
	$nested=new PanelCollaborationStorageException('nested_failure', 'Nested collaboration failure.');
	$t->same(
		$nested,
		$t->throws(static fn()=>$savepointAccess->invoke('savepoint', static function() use ($nested):never{throw $nested;}), PanelCollaborationStorageException::class),
	);
	dp_panel_pdo_collaboration_error(
		$t,
		static fn()=>$savepointAccess->invoke('savepoint', static function():never{throw new PDOException('savepoint callback failed');}),
		'storage_unavailable',
	);

	$rollbackContainment=new DpPanelCollaborationPdoProbe();
	$rollbackContainment->execThrowNeedle='ROLLBACK TO SAVEPOINT';
	$t->nonPublic(new PanelPdoCollaborationStore($rollbackContainment))->invoke('rollbackSavepoint', 'dp_collaboration_probe');

	$mysqlRead=new DpPanelCollaborationPdoProbe('mysql');
	$mysqlRead->execFalseNeedle='SET TRANSACTION ISOLATION LEVEL';
	$t->throws(
		static fn()=>$t->nonPublic(new PanelPdoCollaborationStore($mysqlRead))->invoke('begin', false),
		RuntimeException::class,
	);

	$beginFailure=new DpPanelCollaborationPdoProbe('mysql');
	$beginFailure->beginResult=false;
	$t->throws(
		static fn()=>$t->nonPublic(new PanelPdoCollaborationStore($beginFailure))->invoke('begin', true),
		RuntimeException::class,
	);

	$pgsqlRead=new DpPanelCollaborationPdoProbe('pgsql');
	$pgsqlRead->execFalseNeedle='SET TRANSACTION ISOLATION LEVEL';
	$t->throws(
		static fn()=>$t->nonPublic(new PanelPdoCollaborationStore($pgsqlRead))->invoke('begin', false),
		RuntimeException::class,
	);

	$rollbackFailure=new DpPanelCollaborationPdoProbe();
	$rollbackFailure->transaction=true;
	$rollbackFailure->rollbackFailure=new RuntimeException('rollback failed');
	$t->nonPublic(new PanelPdoCollaborationStore($rollbackFailure))->invoke('rollback');
	$t->isTrue($rollbackFailure->transaction);

	$binding=new DpPanelCollaborationPdoProbe();
	$t->nonPublic(new PanelPdoCollaborationStore($binding))->invoke(
		'execute',
		'SELECT 1 WHERE :value IS NULL',
		['value'=>null],
	);
	$t->same(PDO::PARAM_NULL, $binding->bindings[':value']['type']??null);
})->tag('panel','collaboration','pdo','transactions','drivers','failure-containment','coverage')->maxMillis(7000);

test('schema drift corruption locks and invalid options fail closed with stable errors',static function(Context $t):void {
	$readOnly=new PDO('sqlite::memory:');
	$readOnly->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$readOnly->exec('PRAGMA query_only = ON');
	$readOnlyStore=new PanelPdoCollaborationStore($readOnly, ['table_prefix'=>'collab_read_only']);
	dp_panel_pdo_collaboration_error($t, static fn()=>$readOnlyStore->installSchema(), 'migration_failed');

	$drift=dp_panel_pdo_collaboration_fixture($t, 'drift', ['table_prefix'=>'collab_drift']);
	$drift['store']->installSchema();
	$drift['pdo']->exec('UPDATE collab_drift_meta SET schema_version = 9');
	dp_panel_pdo_collaboration_error($t, static fn()=>$drift['store']->state(), 'schema_incompatible');
	dp_panel_pdo_collaboration_error($t, static fn()=>$drift['store']->installSchema(), 'schema_incompatible');

	$corrupt=dp_panel_pdo_collaboration_fixture($t, 'corrupt', ['table_prefix'=>'collab_corrupt']);
	$corrupt['store']->installSchema();
	$corrupt['pdo']->exec('UPDATE collab_corrupt_state SET state_bytes = state_bytes + 1');
	dp_panel_pdo_collaboration_error($t, static fn()=>$corrupt['store']->state(), 'storage_corrupt');

	$locked=dp_panel_pdo_collaboration_fixture($t, 'locked', [
		'table_prefix'=>'collab_locked',
		'transaction_retries'=>1,
		'retry_delay_microseconds'=>0,
	]);
	$locked['store']->installSchema();
	$locker=dp_panel_pdo_collaboration_connection($locked['path'], 0);
	$blocked=new PanelPdoCollaborationStore(
		dp_panel_pdo_collaboration_connection($locked['path'],0),
		['table_prefix'=>'collab_locked','transaction_retries'=>1,'retry_delay_microseconds'=>0],
	);
	$locker->exec('BEGIN IMMEDIATE');
	$unavailable=dp_panel_pdo_collaboration_error($t, static fn()=>$blocked->transaction(
		static function(array &$state):void{$state['meta']['blocked']=true;},
		'collaboration.blocked',
	), 'storage_unavailable');
	$t->isTrue($unavailable->retryable());
	$locker->exec('ROLLBACK');

	$pdo=new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	foreach([
		['unknown'=>1],
		['table_prefix'=>'bad-prefix'],
		['maximum_state_bytes'=>1],
		['maximum_change_bytes'=>1],
		['change_retention'=>7],
		['transaction_retries'=>11],
		['retry_delay_microseconds'=>100001],
	] as $options){
		$t->throws(static fn()=>new PanelPdoCollaborationStore($pdo,$options), InvalidArgumentException::class);
	}
	$t->throws(static fn()=>PanelPdoCollaborationStore::schemaStatementsFor('oracle'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoCollaborationStore::schemaStatementsFor('sqlite','bad-prefix'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoCollaborationStore::dialectPlanFor('sqlsrv'), InvalidArgumentException::class);
	$silent=new PDO('sqlite::memory:');
	$silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
	$t->throws(static fn()=>new PanelPdoCollaborationStore($silent), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelCollaborationStorageException('Bad Code','bad'), InvalidArgumentException::class);
})->tag('panel','collaboration','pdo','fail-closed','corruption','locking','configuration')->maxMillis(12000);
