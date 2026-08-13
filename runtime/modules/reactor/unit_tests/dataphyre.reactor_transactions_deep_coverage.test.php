<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Reactor\ReactorFileTransactionStore;
use Dataphyre\Reactor\ReactorInMemoryTransactionStore;
use Dataphyre\Reactor\ReactorRetryPolicy;
use Dataphyre\Reactor\ReactorStatePatch;
use Dataphyre\Reactor\ReactorStateTransaction;
use Dataphyre\Reactor\ReactorTransactionCoordinator;
use Dataphyre\Reactor\ReactorTransactionEndpoint;
use Dataphyre\Reactor\ReactorTransactionResult;
use Dataphyre\Reactor\ReactorTransactions;
use Dataphyre\Reactor\ReactorTransactionStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/reactor_transaction_failure_seams.php';
framework(['reactor','mvc']);

final class DpReactorRejectingTransactionStore implements ReactorTransactionStore {
	/** @var list<array<string,mixed>> */
	public array $queue=[];
	public int $commits=0;

	public function load(string $component): array { return ['state'=>['value'=>'scalar'],'version'=>1,'updated_at'=>0]; }
	public function commit(string $component,int $expectedVersion,array $state,string $idempotencyKey,array $receipt,array $events=[]): bool { $this->commits++; return false; }
	public function receipt(string $component,string $idempotencyKey): ?array { return null; }
	public function enqueue(ReactorStateTransaction $transaction): void { $this->queue[]=$transaction->jsonSerialize(); }
	public function queued(string $component,int $limit=100): array { return array_slice($this->queue,0,$limit); }
	public function dequeue(string $component,string $transactionId): bool { return true; }
	public function events(string $component,int $afterSequence=0,int $limit=100): array { return []; }
}

test('reactor transaction value objects expose guards aliases signing and exact patch failures',static function(Context $t): void {
	$t->throws(static fn()=>ReactorStatePatch::make('unknown','value'),InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorStatePatch::make('set','..'),InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorStatePatch::make('increment','value','one'),InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorStatePatch::make('merge','value','bad'),InvalidArgumentException::class);
	$patch=ReactorStatePatch::fromArray(['op'=>'set','path'=>'/profile/~1path','value'=>['nested'=>true]]);
	$t->same('set',$patch->operation());
	$t->same('profile./path',$patch->path());
	$t->same(['nested'=>true],$patch->value());
	$t->throws(static fn()=>ReactorStatePatch::make('increment','value',1)->apply(['value'=>'bad']),DomainException::class);
	$t->throws(static fn()=>ReactorStatePatch::make('append','value','next')->apply(['value'=>'bad']),DomainException::class);
	$t->throws(static fn()=>ReactorStatePatch::make('test','value',2)->apply(['value'=>1]),DomainException::class);
	$t->same(['value'=>1],ReactorStatePatch::make('test','value',1)->apply(['value'=>1])['state']);
	$t->same(['nested'=>['value'=>1]],ReactorStatePatch::make('set','nested.value',1)->apply(['nested'=>'scalar'])['state']);
	$t->same(['value'=>1],ReactorStatePatch::make('remove','missing.deep')->apply(['value'=>1])['state']);
	$t->same('valid.path',ReactorStatePatch::make('set','valid..path',1)->path());

	$t->throws(static fn()=>ReactorStateTransaction::make(''),InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorStateTransaction::make('valid',-1),InvalidArgumentException::class);
	$failures=$t->state('reactor.transaction-failures',['random_bytes'=>true]);
	$t->throws(static fn()=>ReactorStateTransaction::make('fail-closed'),RuntimeException::class);
	$failures->put('random_bytes',false);
	$transaction=ReactorStateTransaction::make('profile')
		->remove('old')
		->merge('settings',['theme'=>'dark'])
		->append('tags','new')
		->test('version',1)
		->optimistic(false)
		->expiresAt(-10)
		->expiresIn(30);
	$t->isFalse($transaction->optimisticValue());
	$t->same(['remove','merge','append','test'],array_map(static fn(ReactorStatePatch $item): string=>$item->operation(),$transaction->patches()));
	$t->isFalse($transaction->expired(0));
	$t->isFalse($transaction->verify());
	$sealed=$transaction->seal();
	$t->isTrue($sealed->verify());
	$t->isFalse(ReactorStateTransaction::fromArray(array_replace($sealed->jsonSerialize(),['signature'=>'bad']))->verify());

	$retry=ReactorRetryPolicy::fromArray(['attempts'=>2,'initial_delay_ms'=>100,'maximum_delay_ms'=>200,'multiplier'=>2,'jitter'=>0.5]);
	$t->between(50,150,$retry->delayMs(1,0));
	$t->throws(static fn()=>new ReactorRetryPolicy(0),InvalidArgumentException::class);
	$t->throws(static fn()=>new ReactorRetryPolicy(2,10,5),InvalidArgumentException::class);
	$t->throws(static fn()=>new ReactorRetryPolicy(2,10,20,0.5),InvalidArgumentException::class);

	$result=ReactorTransactionResult::fromArray(['status'=>'failed','transaction_id'=>'tx','component'=>'profile','inverse_patches'=>[],'errors'=>['bad']]);
	$t->same('tx',$result->transactionId());
	$t->same([],$result->inversePatches());
	$t->same(['bad'],$result->errors());
	$t->instanceOf(ReactorTransactionCoordinator::class,ReactorTransactions::filesystem($t->workspace('reactor-transaction-facade')->root()));
})->tag('reactor','transactions','coverage')->group('framework-coverage');

test('reactor transaction coordinator and endpoint cover rejection mutation retry and malformed queues',static function(Context $t): void {
	$store=new DpReactorRejectingTransactionStore();
	$coordinator=(new ReactorTransactionCoordinator($store))->allowUnauthenticatedTransactions()->allowUnauthenticatedStreams()->mutate(static fn(array $state): array=>['mutated'=>true]);
	$t->same('offline_rejected',$coordinator->dispatch(ReactorStateTransaction::make('profile')->set('x',1),false)->status());
	$t->same('expired',$coordinator->execute(ReactorStateTransaction::make('profile')->expiresAt(1)->set('x',1))->status());
	$t->same('invalid',$coordinator->execute(ReactorStateTransaction::make('profile'))->status());
	$t->same('server_wins',$coordinator->execute(ReactorStateTransaction::make('profile',0)->conflictStrategy('server_wins')->set('x',1))->status());
	$invalidPatch=ReactorStateTransaction::make('profile',1)->increment('value');
	$t->same('invalid',$coordinator->execute($invalidPatch)->status());
	$commitConflict=ReactorStateTransaction::make('profile',1)->retry(new ReactorRetryPolicy(1,0,0,1,0))->set('value','ok');
	$t->same('conflict',$coordinator->execute($commitConflict)->status());
	$t->same(1,$store->commits);

	$store->queue=[['id'=>'broken','component'=>'']];
	$drained=$coordinator->drain('profile');
	$t->same('failed',$drained[0]->status());
	$endpoint=(new ReactorTransactionEndpoint($coordinator))->allowInsecureLegacyTransport();
	$t->same('invalid',$endpoint->dispatch(['transaction'=>'bad'])['status']);
	$t->same('invalid',$endpoint->dispatch(['transaction'=>['component'=>'']])['status']);
	$t->contains(': heartbeat',$endpoint->eventStream('profile',-1,0));
	$t->same([],(new ReactorInMemoryTransactionStore())->jsonSerialize());
})->tag('reactor','transactions','coverage')->group('framework-coverage');

test('reactor file transaction store reports root lock and atomic publication failures',static function(Context $t): void {
	$failures=$t->state('reactor.transaction-failures',['fopen'=>false,'flock'=>false,'rename'=>false,'random_bytes'=>false]);
	$blockingFile=$t->tempFile('file','reactor-transaction-root');
	$t->throws(static fn()=>new ReactorFileTransactionStore($blockingFile.'/child'),RuntimeException::class);
	$root=$t->workspace('reactor-transaction-failures')->root();
	$store=new ReactorFileTransactionStore($root);

	$failures->put('fopen',true);
	$t->throws(static fn()=>$store->load('fopen-failure'),RuntimeException::class);
	$failures->put('fopen',false)->put('flock',true);
	$t->throws(static fn()=>$store->load('flock-failure'),RuntimeException::class);
	$failures->put('flock',false)->put('rename',true);
	$t->throws(static fn()=>$store->commit('rename-failure',0,['ok'=>true],'key',['status'=>'ok']),RuntimeException::class);
})->tag('reactor','transactions','coverage','filesystem')->group('framework-coverage');
