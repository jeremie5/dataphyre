<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelLocalMediaDisk;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelPdoSnapshotStore;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Panel\PanelSnapshotStorageException;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_pdo_snapshot_connection(string $path,int $busyMilliseconds=5000):PDO {
	$pdo=new PDO('sqlite:'.$path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA busy_timeout = '.max(0, $busyMilliseconds));
	return $pdo;
}

/**
 * @param array<string,mixed> $initial
 * @param array<string,mixed> $options
 * @return array{path:string,pdo:PDO,store:PanelPdoSnapshotStore,clock:Closure}
 */
function dp_panel_pdo_snapshot_fixture(
	Context $t,
	string $name,
	array $initial=['count'=>0],
	array $options=[],
	string $scope='tenant:primary',
	string $schema='panel.snapshot.test',
):array {
	$path=$t->tempDirectory('panel-pdo-snapshot-'.$name).DIRECTORY_SEPARATOR.'snapshot.sqlite';
	$pdo=dp_panel_pdo_snapshot_connection($path);
	$now='2026-07-20T15:00:00Z';
	$clock=static function() use (&$now):string{return $now;};
	$store=new PanelPdoSnapshotStore($pdo,$scope,$schema,$initial,$options,$clock);
	return compact('path','pdo','store','clock');
}

function dp_panel_pdo_snapshot_error(Context $t,callable $callback,string $code):PanelSnapshotStorageException {
	try{$callback();}
	catch(PanelSnapshotStorageException $error){
		$t->same($code,$error->errorCode());
		return $error;
	}
	throw new RuntimeException("Expected PanelSnapshotStorageException {$code}.");
}

final class DpPanelSnapshotPdoStatementProbe extends PDOStatement {
	public function __construct(private readonly DpPanelSnapshotPdoProbe $pdo){}
	public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {
		$this->pdo->bindings[(string)$param]=['value'=>$value,'type'=>$type];
		return $this->pdo->bindResult;
	}
	public function execute(?array $params=null):bool {
		if($this->pdo->executeFailure!==null){throw $this->pdo->executeFailure;}
		return $this->pdo->executeResult;
	}
	public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
		return $this->pdo->fetchQueue!==[]?array_shift($this->pdo->fetchQueue):$this->pdo->fetchValue;
	}
	public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
		return $this->pdo->fetchAllValue;
	}
	public function rowCount():int{return $this->pdo->rowCountValue;}
}

final class DpPanelSnapshotPdoProbe extends PDO {
	public ?Throwable $attributeFailure=null;
	public ?Throwable $prepareFailure=null;
	public ?Throwable $executeFailure=null;
	public ?Throwable $beginFailure=null;
	public ?Throwable $rollbackFailure=null;
	public bool $bindResult=true;
	public bool $executeResult=true;
	public bool $beginResult=true;
	public bool $commitResult=true;
	public bool $rollbackResult=true;
	public bool $transaction=false;
	public bool $failFirstImmediateWithLock=false;
	public bool $failFirstBeginWithDeadlock=false;
	public ?string $execFalseNeedle=null;
	public ?string $execThrowNeedle=null;
	public ?Throwable $execFailure=null;
	public mixed $fetchValue=false;
	/** @var list<mixed> */ public array $fetchQueue=[];
	/** @var list<array<string,mixed>> */ public array $fetchAllValue=[];
	public int $rowCountValue=1;
	public int $errorMode=PDO::ERRMODE_EXCEPTION;
	/** @var list<string> */ public array $events=[];
	/** @var array<string,array{value:mixed,type:int}> */ public array $bindings=[];
	private int $immediateAttempts=0;
	private int $beginAttempts=0;

	public function __construct(private readonly string $driverName='sqlite'){}
	public function getAttribute(int $attribute):mixed {
		if($this->attributeFailure!==null){throw $this->attributeFailure;}
		return $attribute===PDO::ATTR_DRIVER_NAME?$this->driverName:$this->errorMode;
	}
	public function prepare(string $query,array $options=[]):PDOStatement|false {
		$this->events[]='prepare:'.$query;
		if($this->prepareFailure!==null){throw $this->prepareFailure;}
		return new DpPanelSnapshotPdoStatementProbe($this);
	}
	public function exec(string $statement):int|false {
		$this->events[]=$statement;
		if($statement==='BEGIN IMMEDIATE'&&$this->failFirstImmediateWithLock&&$this->immediateAttempts++===0){
			$error=new PDOException('database is locked');
			$error->errorInfo=['HY000',5,'database is locked'];
			throw $error;
		}
		if($this->execThrowNeedle!==null&&str_contains($statement,$this->execThrowNeedle)){
			if($this->execFailure!==null){throw $this->execFailure;}
			throw new RuntimeException('PDO exec probe failure.');
		}
		if($this->execFalseNeedle!==null&&str_contains($statement,$this->execFalseNeedle)){return false;}
		return 0;
	}
	public function beginTransaction():bool {
		$this->events[]='begin';
		if($this->beginFailure!==null){throw $this->beginFailure;}
		if($this->failFirstBeginWithDeadlock&&$this->beginAttempts++===0){
			$error=new PDOException('deadlock',40001);
			$error->errorInfo=['40001',0,'deadlock'];
			throw $error;
		}
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
}

/** @return array{schema_name:string,storage_revision:string,committed_at:?string,payload_json:string,payload_bytes:int,payload_digest:string} */
function dp_panel_pdo_snapshot_probe_state(int $revision=0,array $payload=['count'=>0],?string $committedAt=null):array {
	ksort($payload,SORT_STRING);
	$json=json_encode($payload===[]?(object)[]:$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	return [
		'schema_name'=>'panel.snapshot.test',
		'storage_revision'=>(string)$revision,
		'committed_at'=>$committedAt,
		'payload_json'=>$json,
		'payload_bytes'=>strlen($json),
		'payload_digest'=>hash('sha256',$json),
	];
}

suite('Panel distributed shared-SQL snapshot store')
	->contract('panel.snapshot-store.pdo',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','canonical-json','atomic-state','change-feed','savepoints','restart','cross-process','media-catalogue')
	->isolation('case')
	->tag('panel','media','snapshot','pdo','distributed','security')
	->group('framework-coverage');

test('local atomic reference resets future cursors without serializing its private path',static function(Context $t):void {
	$directory=$t->tempDirectory('panel-atomic-snapshot-private-path');
	$store=new PanelAtomicSnapshotStore($directory,'panel.snapshot.local',['count'=>0],8);
	$calls=0;
	$store->transaction(static function(array &$payload)use(&$calls):void {
		$calls++;
		$payload['count']++;
	},'snapshot.local');
	$t->same(1,$calls);
	$invalid=$directory.DIRECTORY_SEPARATOR.sprintf('%020d.json',999999);
	file_put_contents($invalid,'{"broken":');
	for($index=0;$index<9;$index++){
		$store->transaction(static function(array &$payload):void{$payload['count']++;},'snapshot.local-retention');
	}
	$t->isFalse(file_exists($invalid));
	$future=$store->changesSince(9999999,1);
	$t->isTrue($future['reset_required']);
	$t->same('future_cursor',$future['reset_reason']);
	$t->same(10,$future['cursor']);
	$t->same(10,$future['snapshot']['payload']['count']);
	$manifest=$store->manifest();
	$t->isFalse($manifest['distributed']);
	$t->isFalse($manifest['directory_serialized']);
	$t->isTrue($manifest['capabilities']['future_cursor_reset']);
	$t->isFalse($manifest['capabilities']['callback_replay']);
	$t->notContains($directory,json_encode($store,JSON_THROW_ON_ERROR));
})->tag('panel','snapshot','atomic','privacy','cursor-reset','coverage')->maxMillis(5000);

test('schema plans are portable explicit idempotent scope isolated and secret free',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture(
		$t,
		'schema',
		['z'=>0,'a'=>['nested'=>true]],
		[
			'table_prefix'=>'snapshot_schema',
			'maximum_payload_bytes'=>131072,
			'maximum_event_bytes'=>4096,
			'change_retention'=>32,
			'transaction_retries'=>2,
			'retry_delay_microseconds'=>0,
		],
		'tenant:secret-scope',
		'panel.media.catalog',
	);
	$store=$fixture['store'];
	$missing=dp_panel_pdo_snapshot_error($t,static fn()=>$store->snapshot(),'schema_required');
	$t->isFalse($missing->retryable());
	$first=$store->installSchema();
	$second=$store->installSchema();
	$t->same($first,$second);
	$t->same('sqlite',$store->driver());
	$t->same(4,$first['statements']);
	$t->isTrue($first['idempotent']);
	$t->isFalse($first['destructive']);
	$t->same($store->schemaStatements(),PanelPdoSnapshotStore::schemaStatementsFor('sqlite','snapshot_schema'));
	$t->same('BEGIN IMMEDIATE',PanelPdoSnapshotStore::dialectPlanFor('sqlite')['write_begin']);
	$t->same(' FOR UPDATE',PanelPdoSnapshotStore::dialectPlanFor('mysql')['lock_suffix']);
	$t->contains('REPEATABLE READ',PanelPdoSnapshotStore::dialectPlanFor('pgsql')['read_after'][0]);

	foreach(['sqlite','mysql','pgsql']as$driver){
		$statements=PanelPdoSnapshotStore::schemaStatementsFor($driver,'snapshot_plan');
		$t->same(4,count($statements));
		$t->contains('snapshot_plan_state',$statements[1]);
		$t->contains('snapshot_plan_changes',$statements[2]);
	}
	$t->contains('ENGINE=InnoDB',PanelPdoSnapshotStore::schemaStatementsFor('mysql','snapshot_plan')[0]);
	$t->contains('ON CONFLICT',PanelPdoSnapshotStore::schemaStatementsFor('pgsql','snapshot_plan')[3]);
	$t->contains('INSERT OR IGNORE',PanelPdoSnapshotStore::schemaStatementsFor('sqlite','snapshot_plan')[3]);

	$snapshot=$store->snapshot();
	$t->same('panel.media.catalog',$snapshot['schema']);
	$t->same(0,$snapshot['sequence']);
	$t->same(null,$snapshot['committed_at']);
	$t->same(['a'=>['nested'=>true],'z'=>0],$snapshot['payload']);
	$t->same(null,$snapshot['event']);

	$manifest=$store->manifest();
	$encoded=json_encode([$store,$first],JSON_THROW_ON_ERROR);
	$t->same('panel_pdo_snapshot_store',$manifest['type']);
	$t->isTrue($manifest['distributed']);
	$t->isTrue($manifest['capabilities']['canonical_json_integrity']);
	$t->same('at_most_once_per_call',$manifest['mutation_callback_delivery']);
	$t->same(64,strlen($manifest['scope_fingerprint']));
	$t->same($store->scopeFingerprint(),$manifest['scope_fingerprint']);
	foreach(['tenant:secret-scope','panel.media.catalog','sqlite:',$fixture['path']]as$forbidden){
		$t->notContains($forbidden,$encoded);
	}
	$t->notContains('"table_prefix"',$encoded);

	$other=new PanelPdoSnapshotStore(
		dp_panel_pdo_snapshot_connection($fixture['path']),
		'tenant:other',
		'panel.media.catalog',
		['count'=>91],
		['table_prefix'=>'snapshot_schema'],
	);
	$other->installSchema();
	$t->same(['count'=>91],$other->payload());
	$t->same(['a'=>['nested'=>true],'z'=>0],$store->payload());

	$conflict=new PanelPdoSnapshotStore(
		dp_panel_pdo_snapshot_connection($fixture['path']),
		'tenant:secret-scope',
		'panel.other.schema',
		[],
		['table_prefix'=>'snapshot_schema'],
	);
	$conflictError=dp_panel_pdo_snapshot_error($t,static fn()=>$conflict->installSchema(),'scope_conflict');
	$t->isFalse($conflictError->retryable());

	$fixture['pdo']->beginTransaction();
	$transactionError=dp_panel_pdo_snapshot_error($t,static fn()=>$store->installSchema(),'transaction_conflict');
	$t->isTrue($transactionError->retryable());
	$fixture['pdo']->rollBack();

	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],'','schema'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],str_repeat('x',1025),'schema'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],"bad\0scope",'schema'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],'scope','bad schema'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],'scope',str_repeat('s',161)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],'scope','schema',[],['unknown'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSnapshotStore($fixture['pdo'],'scope','schema',[],['maximum_payload_bytes'=>100]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoSnapshotStore::schemaStatementsFor('oracle'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoSnapshotStore::schemaStatementsFor('sqlite','invalid-prefix!'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSnapshotStorageException('BAD','invalid'),InvalidArgumentException::class);

	$error=new PanelSnapshotStorageException('storage_unavailable','Snapshot storage is unavailable.',true,new RuntimeException('provider secret'));
	$t->isTrue($error->retryable(),'non-transient storage error should be retryable');
	$t->same('storage_unavailable',$error->errorCode());
	$t->same([
		'type'=>'panel_snapshot_storage_error',
		'code'=>'storage_unavailable',
		'retryable'=>true,
		'message'=>'Snapshot storage is unavailable.',
		'details_serialized'=>false,
	],$error->jsonSerialize());
	$t->notContains('provider secret',json_encode($error,JSON_THROW_ON_ERROR));
})->tag('panel','snapshot','pdo','schema','manifest','privacy')->maxMillis(8000);

test('transactions survive restart preserve rollback and never replay caller callbacks',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture($t,'lifecycle',['count'=>0,'items'=>[]],[
		'table_prefix'=>'snapshot_lifecycle',
		'change_retention'=>64,
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	$calls=0;
	$commit=$store->transaction(static function(array &$payload)use(&$calls):string {
		$calls++;
		$payload['items']['item-b']=['value'=>2,'name'=>'B'];
		$payload['items']['item-a']=['name'=>'A','value'=>1];
		$payload['count']++;
		return 'committed';
	},'snapshot.committed',[
		'z'=>2,
		'a'=>1,
		'cursor'=>999,
		'type'=>'forged',
		'occurred_at'=>'forged',
	]);
	$t->same(1,$calls);
	$t->same('committed',$commit['result']);
	$t->same(1,$commit['snapshot']['sequence']);
	$t->same(1,$commit['snapshot']['payload']['count']);
	$t->same('snapshot.committed',$commit['snapshot']['event']['type']);
	$t->same(1,$commit['snapshot']['event']['cursor']);
	$t->same('2026-07-20T15:00:00.000000Z',$commit['snapshot']['event']['occurred_at']);
	$t->same($commit['snapshot'],$store->snapshot());

	$restart=new PanelPdoSnapshotStore(
		dp_panel_pdo_snapshot_connection($fixture['path']),
		'tenant:primary',
		'panel.snapshot.test',
		['ignored'=>true],
		['table_prefix'=>'snapshot_lifecycle','change_retention'=>64],
		$fixture['clock'],
	);
	$t->same($commit['snapshot'],$restart->snapshot());
	$t->same(1,$restart->cursor());
	$t->same($commit['snapshot']['payload'],$restart->payload());

	$rollbackCalls=0;
	$t->throws(static function()use($store,&$rollbackCalls):void {
		$store->transaction(
			static function(array &$payload)use(&$rollbackCalls):void {
				$rollbackCalls++;
				$payload['count']=999;
				throw new DomainException('reject snapshot');
			},
			'snapshot.rejected',
		);
	},DomainException::class);
	$t->same(1,$rollbackCalls);
	$t->same(1,$store->cursor());
	$t->same(1,$store->payload()['count']);

	$feed=$restart->changesSince(0,100);
	$t->isFalse($feed['reset_required']);
	$t->same(1,$feed['cursor']);
	$t->same(1,count($feed['changes']));
	$t->same($commit['snapshot']['event'],$feed['changes'][0]);
	$t->same(null,$feed['snapshot']);
})->tag('panel','snapshot','pdo','transaction','restart','rollback')->maxMillis(8000);

test('retention pagination reset and bounded JSON validation fail closed',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture($t,'retention',['count'=>0],[
		'table_prefix'=>'snapshot_retention',
		'maximum_payload_bytes'=>1024,
		'maximum_event_bytes'=>256,
		'change_retention'=>8,
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	for($index=1;$index<=12;$index++){
		$store->transaction(static function(array &$payload):void{$payload['count']++;},'snapshot.increment',['index'=>$index]);
	}
	$t->same(12,$store->cursor());
	$page=$store->changesSince(4,3);
	$t->isFalse($page['reset_required']);
	$t->same(7,$page['cursor']);
	$t->same([5,6,7],array_column($page['changes'],'cursor'));
	$stale=$store->changesSince(1,100);
	$t->isTrue($stale['reset_required']);
	$t->same('retention_window',$stale['reset_reason']);
	$t->same(12,$stale['cursor']);
	$t->same(12,$stale['snapshot']['payload']['count']);
	$future=$store->changesSince(99,100);
	$t->isTrue($future['reset_required']);
	$t->same('future_cursor',$future['reset_reason']);
	$t->same(12,$future['snapshot']['sequence']);
	$t->same(5,$future['oldest_cursor']);

	$eventError=dp_panel_pdo_snapshot_error($t,static fn()=>$store->transaction(
		static function(array &$payload):void{$payload['must_rollback']=true;},
		'snapshot.large-event',
		['message'=>str_repeat('e',300)],
	),'event_too_large');
	$t->isFalse($eventError->retryable());
	$t->isFalse(isset($store->payload()['must_rollback']));

	$payloadError=dp_panel_pdo_snapshot_error($t,static fn()=>$store->transaction(
		static function(array &$payload):void{$payload['large']=str_repeat('p',2000);},
		'snapshot.large-payload',
	),'payload_too_large');
	$t->isFalse($payloadError->retryable());
	$t->isFalse(isset($store->payload()['large']));

	dp_panel_pdo_snapshot_error($t,static fn()=>$store->transaction(
		static function(array &$payload):void{$payload=['invalid-list'];},
		'snapshot.invalid-payload',
	),'payload_invalid');
	dp_panel_pdo_snapshot_error($t,static fn()=>$store->transaction(
		static function(array &$payload):void{$payload['nan']=NAN;},
		'snapshot.invalid-json',
	),'payload_invalid');
	$t->throws(static fn()=>$store->transaction(static function(array &$payload):void{},'',[]),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->transaction(static function(array &$payload):void{},str_repeat('x',161),[]),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->transaction(static function(array &$payload):void{},'snapshot.list-event',['list']),InvalidArgumentException::class);
	$t->same(12,$store->cursor());
})->tag('panel','snapshot','pdo','retention','bounds','canonical-json')->maxMillis(8000);

test('host transactions use savepoints preserve outer ownership and honor rollback',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture($t,'savepoints',['count'=>0],[
		'table_prefix'=>'snapshot_savepoint',
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	$outside=new PanelPdoSnapshotStore(
		dp_panel_pdo_snapshot_connection($fixture['path']),
		'tenant:primary',
		'panel.snapshot.test',
		[],
		['table_prefix'=>'snapshot_savepoint'],
		$fixture['clock'],
	);

	$fixture['pdo']->beginTransaction();
	$store->transaction(static function(array &$payload):void{$payload['count']=1;},'snapshot.outer-rollback');
	$t->isTrue($fixture['pdo']->inTransaction());
	$fixture['pdo']->rollBack();
	$t->same(0,$outside->payload()['count']);
	$t->same(0,$outside->cursor());

	$fixture['pdo']->beginTransaction();
	$store->transaction(static function(array &$payload):void{$payload['count']=2;},'snapshot.outer-commit');
	$t->isTrue($fixture['pdo']->inTransaction());
	$fixture['pdo']->commit();
	$t->same(2,$outside->payload()['count']);
	$t->same(1,$outside->cursor());

	$fixture['pdo']->beginTransaction();
	$t->throws(static fn()=>$store->transaction(
		static function(array &$payload):void{$payload['count']=3;throw new DomainException('savepoint rejection');},
		'snapshot.savepoint-rejected',
	),DomainException::class);
	$t->isTrue($fixture['pdo']->inTransaction());
	$t->same(2,$store->payload()['count']);
	$fixture['pdo']->rollBack();
	$t->same(2,$outside->payload()['count']);
})->tag('panel','snapshot','pdo','transactions','savepoints','php82')->maxMillis(8000);

test('independent PHP workers serialize increments without lost updates or callback replay',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture($t,'workers',['count'=>0],[
		'table_prefix'=>'snapshot_workers',
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
$store=new \Dataphyre\Panel\PanelPdoSnapshotStore($pdo,'tenant:primary','panel.snapshot.test',[],['table_prefix'=>'snapshot_workers','change_retention'=>128,'transaction_retries'=>10,'retry_delay_microseconds'=>1000]);
$calls=0;
for($i=0;$i<10;$i++){$store->transaction(static function(array &$payload)use(&$calls):void{$calls++;$payload['count']++;},'snapshot.worker',['worker'=>$argv[3],'index'=>$i]);}
echo $calls;
PHP;
	$workers=[];
	foreach(['a','b','c']as$worker){
		$workers[]=$t->startPhpProcess(['-r',$code,$panelRoot,$fixture['path'],$worker],timeout_millis:20000);
	}
	$totalCalls=0;
	foreach($workers as$process){
		$result=$process->wait();
		if(!$result->succeeded()){
			throw new RuntimeException('Snapshot worker failed: '.$result->stderr().' '.$result->stdout());
		}
		$t->same('',trim($result->stderr()));
		$totalCalls+=(int)trim($result->stdout());
	}
	$t->same(30,$totalCalls);
	$t->same(30,$fixture['store']->payload()['count']);
	$t->same(30,$fixture['store']->cursor());
	$t->same(30,count($fixture['store']->changesSince(0,100)['changes']));
})->tag('panel','snapshot','pdo','cross-process','serialization','callback-delivery')->maxMillis(30000);

test('persisted state and event corruption fail closed without leaking provider material',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture($t,'integrity',['count'=>0],[
		'table_prefix'=>'snapshot_integrity',
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	$store->transaction(static function(array &$payload):void{$payload['count']=1;},'snapshot.integrity',['probe'=>true]);
	$state=$fixture['pdo']->query('SELECT schema_name, storage_revision, committed_at, payload_json, payload_bytes, payload_digest FROM snapshot_integrity_state')->fetch(PDO::FETCH_ASSOC);
	if(!is_array($state)){throw new RuntimeException('Expected snapshot state row.');}
	$change=$fixture['pdo']->query('SELECT change_sequence, event_type, event_json, event_bytes, event_digest, occurred_at FROM snapshot_integrity_changes')->fetch(PDO::FETCH_ASSOC);
	if(!is_array($change)){throw new RuntimeException('Expected snapshot change row.');}
	$access=$t->nonPublic($store);

	$missing=$state;unset($missing['payload_digest']);
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$missing),'storage_corrupt');
	$badRevision=$state;$badRevision['storage_revision']='01';
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$badRevision),'storage_corrupt');
	$badBytes=$state;$badBytes['payload_bytes']=(int)$badBytes['payload_bytes']+1;
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$badBytes),'storage_corrupt');
	$nonCanonical=$state;$nonCanonical['payload_json']='{"count":1, "z":2}';$nonCanonical['payload_bytes']=strlen($nonCanonical['payload_json']);$nonCanonical['payload_digest']=hash('sha256',$nonCanonical['payload_json']);
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$nonCanonical),'storage_corrupt');
	$list=$state;$list['payload_json']='[1]';$list['payload_bytes']=3;$list['payload_digest']=hash('sha256','[1]');
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$list),'storage_corrupt');
	$badInstant=$state;$badInstant['committed_at']='not-an-instant';
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('decodeState',$badInstant),'storage_corrupt');

	$missingEvent=$change;unset($missingEvent['event_type']);
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('hydrateChange',$missingEvent),'storage_corrupt');
	$badEventBytes=$change;$badEventBytes['event_bytes']=(int)$badEventBytes['event_bytes']+1;
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('hydrateChange',$badEventBytes),'storage_corrupt');
	$listEvent=$change;$listEvent['event_json']='[1]';$listEvent['event_bytes']=3;$listEvent['event_digest']=hash('sha256','[1]');
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('hydrateChange',$listEvent),'storage_corrupt');
	$wrongBinding=$change;$decoded=json_decode($wrongBinding['event_json'],true,64,JSON_THROW_ON_ERROR);$decoded['cursor']=99;$wrongBinding['event_json']=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);$wrongBinding['event_bytes']=strlen($wrongBinding['event_json']);$wrongBinding['event_digest']=hash('sha256',$wrongBinding['event_json']);
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('hydrateChange',$wrongBinding),'storage_corrupt');
	dp_panel_pdo_snapshot_error($t,static fn()=>$access->invoke('integer','01',0),'storage_corrupt');

	$fixture['pdo']->exec('UPDATE snapshot_integrity_state SET payload_bytes = payload_bytes + 1');
	$error=dp_panel_pdo_snapshot_error($t,static fn()=>$store->snapshot(),'storage_corrupt');
	$t->notContains($fixture['path'],json_encode($error,JSON_THROW_ON_ERROR));
})->tag('panel','snapshot','pdo','integrity','corruption','privacy')->maxMillis(8000);

test('PDO catalogue powers a restartable media manager and passes generic conformance',static function(Context $t):void {
	$fixture=dp_panel_pdo_snapshot_fixture(
		$t,
		'media',
		['items'=>[],'uploads'=>[]],
		['table_prefix'=>'snapshot_media','change_retention'=>128],
		'tenant:media',
		'panel.media.catalog',
	);
	$catalog=$fixture['store'];
	$catalog->installSchema();
	$root=$t->tempDirectory('panel-pdo-snapshot-media');
	$disk=new PanelLocalMediaDisk($root,'pdo-media');
	$manager=PanelMediaManager::forDisk($disk,$catalog,str_repeat('m',32),[
		'cleanup'=>false,
		'delivery_url'=>'/panel/pdo-media',
	])
		->scanner(static fn():array=>['clean'=>true,'status'=>'clean'],'pdo-probe')
		->transformer(static fn():array=>['variants'=>[],'metadata'=>['catalog'=>'pdo']],'pdo-probe');

	$upload=$manager->startUpload('catalog/probe.bin',1024,[
		'id'=>'pdo_catalog_upload',
		'chunk_size'=>1024,
		'checksum'=>hash('sha256',str_repeat('D',1024)),
	]);
	$t->same('open',$upload['state']);
	$manager->receiveChunk('pdo_catalog_upload',0,str_repeat('D',1024),hash('sha256',str_repeat('D',1024)),0);
	$completion=$manager->completeUpload('pdo_catalog_upload',[],['name'=>'PDO catalogue probe']);
	$t->isTrue($completion['ok']);
	$id=$completion['item']['id'];

	$restartCatalog=new PanelPdoSnapshotStore(
		dp_panel_pdo_snapshot_connection($fixture['path']),
		'tenant:media',
		'panel.media.catalog',
		[],
		['table_prefix'=>'snapshot_media','change_retention'=>128],
		$fixture['clock'],
	);
	$restartManager=PanelMediaManager::forDisk($disk,$restartCatalog,null,['cleanup'=>false]);
	$restartedItem=$restartManager->item($id);
	$t->isTrue(is_array($restartedItem));
	$t->same($completion['item']['id'],$restartedItem['id']??null);
	$t->same($completion['item']['path'],$restartedItem['path']??null);
	$t->same($completion['item']['status'],$restartedItem['status']??null);
	$t->same($completion['item']['source']['checksum'],$restartedItem['source']['checksum']??null);
	$t->same(str_repeat('D',1024),$restartManager->disk()->read('catalog/probe.bin'));
	$t->isTrue($restartCatalog->manifest()['capabilities']['distributed_catalogue']);

	$runner=new PanelAdapterConformanceRunner();
	$snapshotReport=$runner->run(PanelAdapterConformanceCatalog::snapshotStore(),$restartCatalog,[
		'allow_destructive'=>true,
		'forbidden_fragments'=>[$fixture['path'],'tenant:media','panel.media.catalog','snapshot_media'],
	]);
	$t->isTrue($snapshotReport->passed());
	$t->same(2,$snapshotReport->summary()['passed']);
	$mediaReport=$runner->run(PanelAdapterConformanceCatalog::mediaManager(),$manager,[
		'allow_destructive'=>true,
		'namespace'=>'pdo_media_'.bin2hex(random_bytes(4)),
	]);
	$t->isTrue($mediaReport->passed());
	$t->same(2,$mediaReport->summary()['passed']);

	$domain=PanelPlatformManifest::inspect()->domain('media')??[];
	foreach(['snapshot_store','atomic_snapshot_store','pdo_snapshot_store','snapshot_storage_error','snapshot_conformance']as$feature){
		$t->isTrue($domain['features'][$feature]??false);
	}
	$t->isTrue($restartManager->delete($id));
})->tag('panel','snapshot','pdo','media','catalogue','conformance','platform')->maxMillis(20000);

test('PDO fault probes bound retries commit uncertainty savepoints and defensive helpers',static function(Context $t):void {
	$inspection=new DpPanelSnapshotPdoProbe();
	$inspection->attributeFailure=new RuntimeException('connection inspection failed');
	$t->throws(static fn()=>new PanelPdoSnapshotStore($inspection,'scope','schema'),InvalidArgumentException::class);
	$silent=new DpPanelSnapshotPdoProbe();
	$silent->errorMode=PDO::ERRMODE_SILENT;
	$t->throws(static fn()=>new PanelPdoSnapshotStore($silent,'scope','schema'),InvalidArgumentException::class);

	foreach(['mysql','pgsql']as$driver){
		$probe=new DpPanelSnapshotPdoProbe($driver);
		$store=new PanelPdoSnapshotStore($probe,'scope','panel.snapshot.test');
		$t->nonPublic($store)->invoke('initializeScope');
		$events=implode("\n",$probe->events);
		$t->contains($driver==='mysql'?'INSERT IGNORE':'ON CONFLICT',$events);
	}

	$migration=dp_panel_pdo_snapshot_fixture($t,'migration-wrap',['count'=>0],['table_prefix'=>'snapshot_migration_wrap']);
	$migration['store']->installSchema();
	$migration['pdo']->exec('UPDATE snapshot_migration_wrap_state SET payload_bytes = payload_bytes + 1');
	dp_panel_pdo_snapshot_error($t,static fn()=>$migration['store']->installSchema(),'migration_failed');
	$migrationFailure=new DpPanelSnapshotPdoProbe();
	$migrationFailure->execFalseNeedle='CREATE TABLE';
	dp_panel_pdo_snapshot_error(
		$t,
		static fn()=>(new PanelPdoSnapshotStore($migrationFailure,'scope','schema'))->installSchema(),
		'migration_failed',
	);

	$missing=dp_panel_pdo_snapshot_fixture($t,'transaction-missing',['count'=>0],['table_prefix'=>'snapshot_missing_tx','transaction_retries'=>0]);
	dp_panel_pdo_snapshot_error($t,static fn()=>$missing['store']->transaction(static function(array &$payload):void{},'snapshot.missing'),'schema_required');

	$nullSchema=new DpPanelSnapshotPdoProbe();
	$nullSchemaStore=new PanelPdoSnapshotStore($nullSchema,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($nullSchemaStore)->invoke('assertSchema',false),'schema_required');
	$incompatible=new DpPanelSnapshotPdoProbe();
	$incompatible->fetchQueue=[['schema_version'=>'2']];
	$incompatibleStore=new PanelPdoSnapshotStore($incompatible,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($incompatibleStore)->invoke('assertSchema',false),'schema_incompatible');
	$missingScope=new DpPanelSnapshotPdoProbe();
	$missingScopeStore=new PanelPdoSnapshotStore($missingScope,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($missingScopeStore)->invoke('stateRow',false),'scope_required');
	$queryFailure=new DpPanelSnapshotPdoProbe();
	$queryFailure->prepareFailure=new PDOException('private query failed');
	$queryFailureStore=new PanelPdoSnapshotStore($queryFailure,'scope','schema');
	$t->throws(static fn()=>$t->nonPublic($queryFailureStore)->invoke('assertSchema',false),PDOException::class);

	$retry=new DpPanelSnapshotPdoProbe();
	$retry->failFirstImmediateWithLock=true;
	$retry->fetchQueue=[['schema_version'=>'1'],dp_panel_pdo_snapshot_probe_state()];
	$retryStore=new PanelPdoSnapshotStore($retry,'scope','panel.snapshot.test',['count'=>0],[
		'transaction_retries'=>1,
		'retry_delay_microseconds'=>1,
	],static fn():string=>'2026-07-20T15:00:00Z');
	$retryCalls=0;
	$retryResult=$retryStore->transaction(static function(array &$payload)use(&$retryCalls):string {
		$retryCalls++;
		$payload['count']++;
		return 'retried-before-callback';
	},'snapshot.retry');
	$t->same(1,$retryCalls);
	$t->same('retried-before-callback',$retryResult['result']);
	$t->same(2,count(array_filter($retry->events,static fn(string $event):bool=>$event==='BEGIN IMMEDIATE')));

	$nonTransient=new DpPanelSnapshotPdoProbe();
	$nonTransient->execThrowNeedle='BEGIN IMMEDIATE';
	$nonTransient->execFailure=new PDOException('connection lost');
	$nonTransientCalls=0;
	$nonTransientStore=new PanelPdoSnapshotStore($nonTransient,'scope','panel.snapshot.test',[],[
		'transaction_retries'=>0,
		'retry_delay_microseconds'=>0,
	]);
	$error=dp_panel_pdo_snapshot_error($t,static function()use($nonTransientStore,&$nonTransientCalls):void {
		$nonTransientStore->transaction(static function(array &$payload)use(&$nonTransientCalls):void{$nonTransientCalls++;},'snapshot.failure');
	},'storage_unavailable');
	$t->isTrue($error->retryable());
	$t->same(0,$nonTransientCalls);

	$beginFalse=new DpPanelSnapshotPdoProbe();
	$beginFalse->execFalseNeedle='BEGIN IMMEDIATE';
	$beginFalseStore=new PanelPdoSnapshotStore($beginFalse,'scope','panel.snapshot.test',[],['transaction_retries'=>0]);
	dp_panel_pdo_snapshot_error($t,static fn()=>$beginFalseStore->transaction(static function(array &$payload):void{},'snapshot.begin-false'),'storage_unavailable');

	$writeConflict=new DpPanelSnapshotPdoProbe();
	$writeConflict->fetchQueue=[['schema_version'=>'1'],dp_panel_pdo_snapshot_probe_state()];
	$writeConflict->rowCountValue=0;
	$writeConflictStore=new PanelPdoSnapshotStore($writeConflict,'scope','panel.snapshot.test');
	$conflictCalls=0;
	$conflict=dp_panel_pdo_snapshot_error($t,static function()use($writeConflictStore,&$conflictCalls):void {
		$writeConflictStore->transaction(static function(array &$payload)use(&$conflictCalls):void{$conflictCalls++;},'snapshot.conflict');
	},'write_conflict');
	$t->isTrue($conflict->retryable(),'write conflict should be retryable');
	$t->same(1,$conflictCalls);

	$invalidClock=new DpPanelSnapshotPdoProbe();
	$invalidClock->fetchQueue=[['schema_version'=>'1'],dp_panel_pdo_snapshot_probe_state()];
	$invalidClockStore=new PanelPdoSnapshotStore($invalidClock,'scope','panel.snapshot.test',[],[],static fn():array=>[]);
	$clockCalls=0;
	dp_panel_pdo_snapshot_error($t,static function()use($invalidClockStore,&$clockCalls):void {
		$invalidClockStore->transaction(static function(array &$payload)use(&$clockCalls):void{$clockCalls++;},'snapshot.invalid-clock');
	},'storage_unavailable');
	$t->same(1,$clockCalls);

	$commitFailure=new DpPanelSnapshotPdoProbe();
	$commitFailure->fetchQueue=[['schema_version'=>'1'],dp_panel_pdo_snapshot_probe_state()];
	$commitFailure->execFalseNeedle='COMMIT';
	$commitStore=new PanelPdoSnapshotStore($commitFailure,'scope','panel.snapshot.test',[],[],static fn():string=>'2026-07-20T15:00:00Z');
	$commitCalls=0;
	$commitError=dp_panel_pdo_snapshot_error($t,static function()use($commitStore,&$commitCalls):void {
		$commitStore->transaction(static function(array &$payload)use(&$commitCalls):void{$commitCalls++;},'snapshot.commit-uncertain');
	},'commit_uncertain');
	$t->isTrue($commitError->retryable(),'uncertain commit should be retryable');
	$t->same(1,$commitCalls);

	$savepointFailure=new DpPanelSnapshotPdoProbe();
	$savepointFailure->transaction=true;
	$savepointFailure->fetchQueue=[['schema_version'=>'1'],dp_panel_pdo_snapshot_probe_state()];
	$savepointFailure->execFalseNeedle='RELEASE SAVEPOINT';
	$savepointStore=new PanelPdoSnapshotStore($savepointFailure,'scope','panel.snapshot.test',[],[],static fn():string=>'2026-07-20T15:00:00Z');
	$savepointCalls=0;
	$savepointError=dp_panel_pdo_snapshot_error($t,static function()use($savepointStore,&$savepointCalls):void {
		$savepointStore->transaction(static function(array &$payload)use(&$savepointCalls):void{$savepointCalls++;},'snapshot.savepoint-uncertain');
	},'commit_uncertain');
	$t->same(1,$savepointCalls);
	$t->isTrue($savepointError->retryable(),'uncertain savepoint should be retryable');

	$savepointPdo=new DpPanelSnapshotPdoProbe();
	$savepointPdo->transaction=true;
	$savepointPdo->prepareFailure=new PDOException('savepoint query failed');
	$savepointPdoStore=new PanelPdoSnapshotStore($savepointPdo,'scope','panel.snapshot.test');
	dp_panel_pdo_snapshot_error($t,static fn()=>$savepointPdoStore->transaction(static function(array &$payload):void{},'snapshot.savepoint-pdo'),'storage_unavailable');

	$savepointBegin=new DpPanelSnapshotPdoProbe();
	$savepointBegin->transaction=true;
	$savepointBegin->execFalseNeedle='SAVEPOINT';
	$savepointBeginStore=new PanelPdoSnapshotStore($savepointBegin,'scope','panel.snapshot.test');
	dp_panel_pdo_snapshot_error($t,static fn()=>$savepointBeginStore->transaction(static function(array &$payload):void{},'snapshot.savepoint-begin'),'storage_unavailable');

	$savepointStorage=dp_panel_pdo_snapshot_fixture($t,'savepoint-storage',['count'=>0],[
		'table_prefix'=>'snapshot_savepoint_storage',
		'maximum_payload_bytes'=>1024,
	]);
	$savepointStorage['store']->installSchema();
	$savepointStorage['pdo']->beginTransaction();
	dp_panel_pdo_snapshot_error($t,static fn()=>$savepointStorage['store']->transaction(
		static function(array &$payload):void{$payload['large']=str_repeat('x',2000);},
		'snapshot.savepoint-large',
	),'payload_too_large');
	$t->isTrue($savepointStorage['pdo']->inTransaction(),'storage failure should preserve host transaction');
	$savepointStorage['pdo']->rollBack();

	$active=dp_panel_pdo_snapshot_fixture($t,'active-internal',['count'=>0],['table_prefix'=>'snapshot_active_internal']);
	$active['store']->installSchema();
	$active['pdo']->beginTransaction();
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($active['store'])->invoke(
		'databaseTransaction',
		false,
		static fn()=>throw new PanelSnapshotStorageException('scope_required','scope missing'),
	),'scope_required');
	$t->isTrue($active['pdo']->inTransaction(),'typed internal failure should preserve host transaction');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($active['store'])->invoke(
		'databaseTransaction',
		false,
		static fn()=>throw new RuntimeException('internal callback failed'),
	),'storage_unavailable');
	$t->isTrue($active['pdo']->inTransaction(),'wrapped internal failure should preserve host transaction');
	$active['pdo']->rollBack();

	$internalRetry=new DpPanelSnapshotPdoProbe();
	$internalRetry->failFirstImmediateWithLock=true;
	$internalRetryStore=new PanelPdoSnapshotStore($internalRetry,'scope','schema',[],[
		'transaction_retries'=>1,
		'retry_delay_microseconds'=>1,
	]);
	$t->same('ok',$t->nonPublic($internalRetryStore)->invoke('databaseTransaction',true,static fn():string=>'ok'));

	$internalPdoFailure=new DpPanelSnapshotPdoProbe('pgsql');
	$internalPdoFailure->beginFailure=new PDOException('network failure');
	$internalPdoStore=new PanelPdoSnapshotStore($internalPdoFailure,'scope','schema',[],[
		'transaction_retries'=>0,
	]);
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($internalPdoStore)->invoke('databaseTransaction',false,static fn():string=>'never'),'storage_unavailable');

	$internalCallbackFailure=new DpPanelSnapshotPdoProbe('pgsql');
	$internalCallbackStore=new PanelPdoSnapshotStore($internalCallbackFailure,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($internalCallbackStore)->invoke(
		'databaseTransaction',
		false,
		static fn()=>throw new RuntimeException('callback failure'),
	),'storage_unavailable');

	$internalCommitFailure=new DpPanelSnapshotPdoProbe('pgsql');
	$internalCommitFailure->commitResult=false;
	$internalCommitStore=new PanelPdoSnapshotStore($internalCommitFailure,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($internalCommitStore)->invoke('databaseTransaction',false,static fn():string=>'done'),'commit_uncertain');

	$mysqlBegin=new DpPanelSnapshotPdoProbe('mysql');
	$mysqlBegin->execFalseNeedle='REPEATABLE READ';
	$mysqlBeginStore=new PanelPdoSnapshotStore($mysqlBegin,'scope','schema');
	$t->throws(static fn()=>$t->nonPublic($mysqlBeginStore)->invoke('begin',false),RuntimeException::class);
	$pgsqlBegin=new DpPanelSnapshotPdoProbe('pgsql');
	$pgsqlBegin->execFalseNeedle='REPEATABLE READ';
	$pgsqlBeginStore=new PanelPdoSnapshotStore($pgsqlBegin,'scope','schema');
	$t->throws(static fn()=>$t->nonPublic($pgsqlBeginStore)->invoke('begin',false),RuntimeException::class);
	$beginResult=new DpPanelSnapshotPdoProbe('pgsql');
	$beginResult->beginResult=false;
	$beginResultStore=new PanelPdoSnapshotStore($beginResult,'scope','schema');
	$t->throws(static fn()=>$t->nonPublic($beginResultStore)->invoke('begin',false),RuntimeException::class);

	$rollbackProbe=new DpPanelSnapshotPdoProbe('pgsql');
	$rollbackProbe->transaction=true;
	$rollbackProbe->rollbackFailure=new RuntimeException('rollback failed');
	$rollbackStore=new PanelPdoSnapshotStore($rollbackProbe,'scope','schema');
	$t->nonPublic($rollbackStore)->invoke('rollback');

	$executeProbe=new DpPanelSnapshotPdoProbe('pgsql');
	$executeStore=new PanelPdoSnapshotStore($executeProbe,'scope','schema');
	$t->nonPublic($executeStore)->invoke('execute','SELECT 1',[
		'null'=>null,
		'bool'=>true,
		'int'=>1,
		'string'=>'value',
	]);
	$t->same(PDO::PARAM_NULL,$executeProbe->bindings[':null']['type']);
	$t->same(PDO::PARAM_BOOL,$executeProbe->bindings[':bool']['type']);
	$t->same(PDO::PARAM_INT,$executeProbe->bindings[':int']['type']);
	$t->same(PDO::PARAM_STR,$executeProbe->bindings[':string']['type']);
	$executeProbe->bindResult=false;
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('execute','SELECT 1',['value'=>'x']),RuntimeException::class);
	$executeProbe->bindResult=true;
	$executeProbe->executeResult=false;
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('execute','SELECT 1'),RuntimeException::class);

	$rowProbe=new DpPanelSnapshotPdoProbe('pgsql');
	$rowProbe->fetchValue=[1];
	$rowStore=new PanelPdoSnapshotStore($rowProbe,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($rowStore)->invoke('row','SELECT 1'),'storage_corrupt');
	$rowsProbe=new DpPanelSnapshotPdoProbe('pgsql');
	$rowsProbe->fetchAllValue=[[1]];
	$rowsStore=new PanelPdoSnapshotStore($rowsProbe,'scope','schema');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($rowsStore)->invoke('rows','SELECT 1'),'storage_corrupt');

	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($executeStore)->invoke('integer','9223372036854775808',0),'storage_corrupt');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($executeStore)->invoke('integer',-1,0),'storage_corrupt');
	$t->same(1.5,$t->nonPublic($executeStore)->invoke('canonicalValue',1.5,0));
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('canonicalValue','x',97),UnexpectedValueException::class);
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('canonicalValue',new stdClass(),0),UnexpectedValueException::class);
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('canonicalValue',[1=>'a',3=>'b'],0),UnexpectedValueException::class);
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($executeStore)->invoke('encodeEvent',[]),'event_invalid');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($executeStore)->invoke('encodeEvent',['bad'=>NAN]),'event_invalid');
	$t->throws(static fn()=>$t->nonPublic($executeStore)->invoke('eventMetadata',['bad'=>NAN]),InvalidArgumentException::class);

	$normalized=dp_panel_pdo_snapshot_probe_state(1,['count'=>1],'2026-07-20T15:00:00Z');
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($retryStore)->invoke('decodeState',$normalized),'storage_corrupt');
	$change=[
		'change_sequence'=>'1',
		'event_type'=>'snapshot.probe',
		'event_json'=>'{"cursor":1, "occurred_at":"2026-07-20T15:00:00.000000Z","type":"snapshot.probe"}',
		'event_bytes'=>84,
		'event_digest'=>'',
		'occurred_at'=>'2026-07-20T15:00:00.000000Z',
	];
	$change['event_bytes']=strlen($change['event_json']);
	$change['event_digest']=hash('sha256',$change['event_json']);
	dp_panel_pdo_snapshot_error($t,static fn()=>$t->nonPublic($retryStore)->invoke('hydrateChange',$change),'storage_corrupt');

	$sqliteLocked=new PDOException('database is locked');
	$sqliteLocked->errorInfo=['HY000',5,'database is locked'];
	$t->isTrue($t->nonPublic($retryStore)->invoke('transient',$sqliteLocked),'sqlite lock classification');
	$pgsqlDeadlock=new PDOException('deadlock',40001);
	$t->isTrue($t->nonPublic($pgsqlBeginStore)->invoke('transient',$pgsqlDeadlock),'pgsql retry classification');
	$mysqlLock=new PDOException('lock wait');
	$mysqlLock->errorInfo=['HY000',1205,'lock wait'];
	$t->isTrue($t->nonPublic($mysqlBeginStore)->invoke('transient',$mysqlLock),'mysql lock classification');
	$missingRelation=new PDOException('no such table: snapshot_state');
	$t->isTrue($t->nonPublic($pgsqlBeginStore)->invoke('missingRelation',$missingRelation),'missing relation message classification');
	$mysqlMissing=new PDOException('missing table');
	$mysqlMissing->errorInfo=['42S02',1146,'missing'];
	$t->isTrue($t->nonPublic($mysqlBeginStore)->invoke('missingRelation',$mysqlMissing),'mysql missing relation classification');
})->tag('panel','snapshot','pdo','fault-injection','coverage','callback-delivery')->maxMillis(15000);
