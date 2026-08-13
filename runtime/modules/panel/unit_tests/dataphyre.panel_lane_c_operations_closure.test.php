<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelDataJob;
use Dataphyre\Panel\PanelDataJobOperationBridge;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelLocalOperationQueue;
use Dataphyre\Panel\PanelOperationConflict;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelOperationExecution;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationInterrupted;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Panel\PanelOperationStore;
use Dataphyre\Panel\PanelSynchronousOperationRunner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** Store double that makes every reservable candidate lose its lease. */
final class DpPanelContendedOperationStore implements PanelOperationStore {
	/** @param list<PanelOperationRecord> $records */
	public function __construct(private array $records) {}
	public function create(PanelOperationRecord $record): PanelOperationRecord { return $record; }
	public function get(string $id): ?PanelOperationRecord { return null; }
	public function save(PanelOperationRecord $record,?int $expectedRevision=null): PanelOperationRecord { return $record; }
	public function update(string $id,callable $mutator,?int $expectedRevision=null): PanelOperationRecord {
		if($id==='conflict'){ throw new PanelOperationConflict('lease lost'); }
		throw new LogicException('candidate no longer starts');
	}
	public function findByIdempotencyKey(string $key): ?PanelOperationRecord { return null; }
	public function all(array $criteria=[],int $limit=100,int $offset=0): array { return $this->records; }
	public function delete(string $id): bool { return false; }
}

suite('Panel durable operation lifecycle')
	->contract('panel.operation-lifecycle', 1)
	->layer('integration')
	->risk('high')
	->watches('module:panel')
	->through('operation-record', 'queue', 'control', 'runner', 'store')
	->isolation('case')
	->tag('panel', 'operations')
	->group('framework-coverage');

test('operation records describe valid state and reject impossible lifecycle data', static function(Context $t): void {
	$base=PanelOperationRecord::make('Report Export','Readable export',[
		'id'=>'record-contract','queue'=>'Priority Jobs','max_attempts'=>2,'total'=>2,
		'created_at'=>'2026-07-12T10:00:00Z',
	]);
	$t->same('Readable export',$base->name());
	$t->same('priority_jobs',$base->queue());
	$t->same('2026-07-12T10:00:00+00:00',$base->createdAt());
	$t->same($base->createdAt(),$base->updatedAt());
	$t->isFalse(PanelOperationStatus::terminal(PanelOperationStatus::RUNNING));
	$t->isTrue(PanelOperationStatus::terminal(PanelOperationStatus::FAILED));
	$t->isTrue(PanelOperationStatus::active(PanelOperationStatus::PAUSE_REQUESTED));
	$t->isFalse(PanelOperationStatus::active(PanelOperationStatus::QUEUED));
	$t->isTrue(PanelOperationStatus::canTransition(PanelOperationStatus::RUNNING,PanelOperationStatus::RUNNING));
	$t->throws(static fn()=>PanelOperationStatus::normalize('invented'),InvalidArgumentException::class);

	$invalid=$base->jsonSerialize();
	$invalid['attempt']=3;
	$t->throws(static fn()=>PanelOperationRecord::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$base->jsonSerialize(); $invalid['processed']=3;
	$t->throws(static fn()=>PanelOperationRecord::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$base->jsonSerialize(); $invalid['processed']=1; $invalid['succeeded']=1; $invalid['failed']=1;
	$t->throws(static fn()=>PanelOperationRecord::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$base->jsonSerialize(); $invalid['metadata']=['not-an-object'];
	$t->throws(static fn()=>PanelOperationRecord::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$base->jsonSerialize(); $invalid['created_at']='never o clock';
	$t->throws(static fn()=>PanelOperationRecord::fromArray($invalid),InvalidArgumentException::class);

	$running=$base->start('worker-one','2026-07-12T10:00:01Z')->progress(1,2,'Half complete',0,1,'2026-07-12T10:00:02Z');
	$t->same(1,$running->failed());
	$t->same('Half complete',$running->progressMessage());
	$t->same('worker-one',$running->worker());
	$t->throws(static fn()=>$running->start(),LogicException::class);
	$t->throws(static fn()=>$running->progress(2,2,null,2,1),InvalidArgumentException::class);
	$t->throws(static fn()=>$running->log('verbose','too noisy'),InvalidArgumentException::class);
	$t->throws(static fn()=>$running->complete([],PanelOperationStatus::FAILED),InvalidArgumentException::class);
	$t->same($base,$base->heartbeat());
	$t->throws(static fn()=>$base->progress(1),LogicException::class);

	$cancelRequested=$running->requestCancel('2026-07-12T10:00:03Z');
	$t->same($cancelRequested,$cancelRequested->requestCancel());
	$t->same(PanelOperationStatus::CANCELLED,$cancelRequested->cancel()->status());
	$failed=$running->fail('broken');
	$t->same('broken',$failed->error()['message']);
	$t->same(PanelOperationStatus::QUEUED,$failed->requeue()->status());

	$singleAttempt=PanelOperationRecord::make('once','Once',['id'=>'once','max_attempts'=>1])->start();
	$t->throws(static fn()=>$singleAttempt->retry(),LogicException::class);
	$exhausted=$singleAttempt->fail('done')->jsonSerialize();
	$exhausted['status']=PanelOperationStatus::QUEUED;
	$t->throws(static fn()=>PanelOperationRecord::fromArray($exhausted)->start(),LogicException::class);
})->tag('panel','operations','record','coverage')->group('panel-lane-c');

test('operation execution reports progress and turns cooperative cancellation into a typed interruption', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-execution'));
	$stored=$store->create(PanelOperationRecord::make('count','Count',['id'=>'execution','total'=>3])->start('worker'));
	$execution=new PanelOperationExecution($store,$stored->id());
	$t->same('execution',$execution->record()->id());
	$t->same('execution',$execution->heartbeat()->id());
	$t->same(2,$execution->advance(2,true,'Two complete')->succeeded());
	$t->same(1,$execution->advance(1,false,'One failed')->failed());

	$store->update($stored->id(),static fn(PanelOperationRecord $record): PanelOperationRecord=>$record->requestCancel());
	$t->isTrue($execution->cancellationRequested());
	$t->isFalse($execution->pauseRequested());
	$interrupted=$t->throws(static fn()=>$execution->guard(),PanelOperationInterrupted::class);
	$t->same(PanelOperationStatus::CANCELLED,$interrupted->operationStatus());
	$t->same(PanelOperationStatus::CANCELLED,$store->get($stored->id())->status());
	$t->throws(static fn()=>(new PanelOperationExecution($store,'missing'))->record(),OutOfBoundsException::class);
})->tag('panel','operations','execution','coverage')->group('panel-lane-c');

test('operation registries queues controls and runners expose their complete operator contract', static function(Context $t): void {
	$handlers=(new PanelOperationHandlerRegistry())
		->register('Zulu Handler',static fn(): array=>[])
		->register('Alpha Handler',static fn(): array=>[]);
	$t->isTrue($handlers->has('alpha handler'));
	$t->same(['alpha_handler','zulu_handler'],$handlers->types());
	$t->same(2,$handlers->jsonSerialize()['count']);
	$handlers->forget('zulu handler');
	$t->isFalse($handlers->has('zulu handler'));

	$contention=new PanelLocalOperationQueue(new DpPanelContendedOperationStore([
		PanelOperationRecord::make('lease','Conflict',['id'=>'conflict']),
		PanelOperationRecord::make('lease','Invalid',['id'=>'logic']),
	]));
	$t->same(null,$contention->reserve());

	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-queue-control'));
	$queue=new PanelLocalOperationQueue($store);
	$queued=$queue->enqueue(PanelOperationRecord::make('alpha handler','Queued',['id'=>'queued','queue'=>'Odd Queue!!','max_attempts'=>2]));
	$t->same(1,$queue->size('Odd Queue!!'));
	$running=$queue->reserve('Odd Queue!!','worker');
	$t->same(PanelOperationStatus::RETRY_WAIT,$queue->release($running,0)->status());
	$store->delete($queued->id());
	$t->same($running,$queue->acknowledge($running));

	$runner=new PanelSynchronousOperationRunner($store,$handlers,$queue);
	$t->same($store,$runner->store());
	$t->same($handlers,$runner->handlers());
	$t->same($queue,$runner->queue());

	$cancel=$store->create(PanelOperationRecord::make('alpha handler','Cancel',['id'=>'run-cancel'])->start()->requestCancel());
	$t->same(PanelOperationStatus::CANCELLED,$runner->runWith($cancel->id(),static fn(): array=>[])->status());
	$terminal=$store->create(PanelOperationRecord::make('alpha handler','Done',['id'=>'run-done'])->start()->complete([]));
	$t->same($terminal->revision(),$runner->run($terminal->id())->revision());

	$failed=$store->create(PanelOperationRecord::make('alpha handler','No retry',['id'=>'run-fail'])->start());
	$result=$runner->runWith($failed->id(),static function(): never { throw new RuntimeException('permanent'); });
	$t->same(PanelOperationStatus::FAILED,$result->status());
	$missing=$runner->submit('not registered','Missing',[],['id'=>'work-missing']);
	$t->same(PanelOperationStatus::FAILED,$runner->work(null,1)[0]->status());

	$control=new PanelOperationControl($store);
	$retrying=$store->create(PanelOperationRecord::make('alpha handler','Retry running',['id'=>'control-retry','max_attempts'=>2])->start());
	$t->same(PanelOperationStatus::RETRY_WAIT,$control->retry($retrying->id())->status());
	$stale=$store->create(PanelOperationRecord::make('alpha handler','Stale',['id'=>'stale-no-retry','max_attempts'=>1,'created_at'=>'2020-01-01T00:00:00Z'])->start('gone','2020-01-01T00:00:01Z'));
	$t->same(PanelOperationStatus::FAILED,$control->recoverStale(1)[0]->status());
})->tag('panel','operations','runner','queue','control','coverage')->group('panel-lane-c');

test('filesystem operation storage filters sorts deletes validates and detects corrupt envelopes', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-operation-store-closure');
	$store=new PanelFilesystemOperationStore($directory);
	$t->same((string)realpath($directory),$store->directory());
	$later=$store->create(PanelOperationRecord::make('export','Later',['id'=>'z-record','idempotency_key'=>'z','created_at'=>'2026-07-12T12:00:00Z']));
	$earlier=$store->create(PanelOperationRecord::make('import','Earlier',['id'=>'a-record','idempotency_key'=>'a','created_at'=>'2026-07-12T11:00:00Z']));
	$t->same(['a-record','z-record'],array_map(static fn(PanelOperationRecord $record): string=>$record->id(),$store->all()));
	$t->same('a-record',$store->all(['id'=>'a-record'])[0]->id());
	$t->same('z-record',$store->all(['idempotency_key'=>'z'])[0]->id());
	$t->same([],$store->all(['worker'=>'nobody']));
	$t->throws(static fn()=>$store->update($later->id(),static fn(): PanelOperationRecord=>$earlier,$later->revision()),LogicException::class);
	$t->throws(static fn()=>$store->update($later->id(),static fn(PanelOperationRecord $record): PanelOperationRecord=>$record,99),PanelOperationConflict::class);
	$t->isTrue($store->delete($earlier->id()));
	$t->isFalse($store->delete($earlier->id()));
	$t->throws(static fn()=>$store->get('../unsafe'),InvalidArgumentException::class);

	$corrupt=$directory.DIRECTORY_SEPARATOR.'corrupt.json';
	file_put_contents($corrupt,'{broken');
	$t->throws(static fn()=>$store->get('corrupt'),UnexpectedValueException::class);
	file_put_contents($corrupt,'{"version":1}');
	$t->throws(static fn()=>$store->get('corrupt'),UnexpectedValueException::class);

	$blocker=$t->tempDirectory('panel-operation-store-blocked').DIRECTORY_SEPARATOR.'file';
	file_put_contents($blocker,'not a directory');
	$t->throws(static fn()=>new PanelFilesystemOperationStore($blocker.DIRECTORY_SEPARATOR.'child'),RuntimeException::class);
})->tag('panel','operations','store','coverage')->group('panel-lane-c');

test('legacy data jobs bridge artifacts and hard failures into durable operation results', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-bridge-closure'));
	$runner=new PanelSynchronousOperationRunner($store,new PanelOperationHandlerRegistry());
	$withArtifacts=PanelDataJob::export('Artifact job')->id('bridge-artifacts')->items([1])->map(static fn(int $item): array=>['value'=>$item]);
	$completed=PanelDataJobOperationBridge::execute($withArtifacts,$runner);
	$t->same(PanelOperationStatus::COMPLETED,$completed->status());
	$t->same('artifact_job-export.json',$completed->artifacts()[0]['name']);

	$allFailed=PanelDataJob::import('Failed job')->id('bridge-failed')->items([1])->handle(static function(): never { throw new RuntimeException('bad row'); });
	$failed=PanelDataJobOperationBridge::execute($allFailed,$runner);
	$t->same(PanelOperationStatus::FAILED,$failed->status());
	$t->same('Legacy PanelDataJob failed all work items.',$failed->error()['message']);
})->tag('panel','operations','bridge','coverage')->group('panel-lane-c');

test('filesystem operation storage explains non-writable encode staging and publication failures', static function(Context $t): void {
	$io=$t->state('panel.operations.io',['failure'=>'']);
	if(!function_exists('Dataphyre\\Panel\\dp_panel_operation_io_failure')){
		$t->defineSymbols(<<<'PHP'
namespace Dataphyre\Panel;
function dp_panel_operation_io_failure(): string {
	return (string)\Dataphyre\Test\TestState::channel('panel.operations.io')->get('failure','');
}
function is_writable(string $filename): bool {
	if(dp_panel_operation_io_failure()==='not_writable'){ return false; }
	return \is_writable($filename);
}
function json_encode(mixed $value,int $flags=0,int $depth=512): string|false {
	if(dp_panel_operation_io_failure()==='encode' && is_array($value) && isset($value['version'],$value['checksum'],$value['record'])){
		throw new \JsonException('injected operation envelope encoding failure');
	}
	return \json_encode($value,$flags,$depth);
}
function rename(string $from,string $to,mixed $context=null): bool {
	$failure=dp_panel_operation_io_failure();
	if($failure==='stage' && str_contains(basename($to),'.backup-')){ return false; }
	if($failure==='publish' && str_ends_with($to,'.json') && !str_contains($from,'.backup-')){ return false; }
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}
PHP);
	}

	$notWritable=$t->tempDirectory('panel-operation-not-writable');
	$io->put('failure','not_writable');
	$t->throws(static fn()=>new PanelFilesystemOperationStore($notWritable),RuntimeException::class);

	$encodeDirectory=$t->tempDirectory('panel-operation-encode-failure');
	$io->put('failure','');
	$encodeStore=new PanelFilesystemOperationStore($encodeDirectory);
	$io->put('failure','encode');
	$t->throws(static fn()=>$encodeStore->create(PanelOperationRecord::make('write','Encode',['id'=>'encode-failure'])),UnexpectedValueException::class);

	$stageDirectory=$t->tempDirectory('panel-operation-stage-failure');
	$io->put('failure','');
	$stageStore=new PanelFilesystemOperationStore($stageDirectory);
	$stageRecord=$stageStore->create(PanelOperationRecord::make('write','Stage',['id'=>'stage-failure']));
	$io->put('failure','stage');
	$stageError=$t->throws(static fn()=>$stageStore->save($stageRecord,$stageRecord->revision()),RuntimeException::class);
	$t->same('Unable to stage existing Panel operation record for replacement.',$stageError->getMessage());

	$publishDirectory=$t->tempDirectory('panel-operation-publish-failure');
	$io->put('failure','');
	$publishStore=new PanelFilesystemOperationStore($publishDirectory);
	$publishRecord=$publishStore->create(PanelOperationRecord::make('write','Publish',['id'=>'publish-failure']));
	$io->put('failure','publish');
	$publishError=$t->throws(static fn()=>$publishStore->save($publishRecord,$publishRecord->revision()),RuntimeException::class);
	$t->same('Unable to atomically publish Panel operation record.',$publishError->getMessage());
	$io->put('failure','');
	$t->same('publish-failure',$publishStore->get('publish-failure')->id());
})->tag('panel','operations','store','fault-injection','coverage')->group('panel-lane-c');
