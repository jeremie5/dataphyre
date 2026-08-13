<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorFileTransactionStore;
use Dataphyre\Reactor\ReactorInMemoryTransactionStore;
use Dataphyre\Reactor\ReactorRetryPolicy;
use Dataphyre\Reactor\ReactorStatePatch;
use Dataphyre\Reactor\ReactorStateTransaction;
use Dataphyre\Reactor\ReactorTransactionCoordinator;
use Dataphyre\Reactor\ReactorTransactionEndpoint;
use Dataphyre\Reactor\ReactorClientAssets;
use Dataphyre\Reactor\ReactorTransactions;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mvc'=>true, 'reactor'=>true, 'templating'=>false],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_reactor_transaction_modules_root=dirname(__DIR__, 2);
require_once $dp_reactor_transaction_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_transaction_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor']);

test('reactor state patches apply nested mutations and exact inverse operations', static function(Context $t): void {
	$state=['profile'=>['name'=>'Mina', 'score'=>4], 'tags'=>['alpha']];
	$patches=[
		ReactorStatePatch::make('set', '/profile/name', 'Iris'),
		ReactorStatePatch::make('increment', 'profile.score', 3),
		ReactorStatePatch::make('merge', 'profile', ['active'=>true]),
		ReactorStatePatch::make('append', 'tags', 'beta'),
		ReactorStatePatch::make('remove', 'profile.active'),
	];
	$inverse=[];
	foreach($patches as $patch){
		$applied=$patch->apply($state);
		$state=$applied['state'];
		array_unshift($inverse, $applied['inverse']);
	}
	$t->same('Iris', $state['profile']['name']);
	$t->same(7, $state['profile']['score']);
	$t->same(['alpha', 'beta'], $state['tags']);
	$t->isFalse(array_key_exists('active', $state['profile']));
	foreach($inverse as $patch){ $state=$patch->apply($state)['state']; }
	$t->same(['profile'=>['name'=>'Mina', 'score'=>4], 'tags'=>['alpha']], $state);
})->tag('reactor', 'transactions', 'patches')->maxMillis(1000);

test('reactor transaction manifests preserve optimistic offline retry and signing contracts', static function(Context $t): void {
	$transaction=ReactorStateTransaction::make(' Order Editor ', 7)
		->id('tx-order-7')
		->idempotencyKey('order:7:update')
		->conflictStrategy('rebase')
		->offlineCapable()
		->optimistic()
		->retry(new ReactorRetryPolicy(5, 25, 1000, 2.0, 0.0))
		->metadata(['actor'=>'operator-1'])
		->set('status', 'paid')
		->increment('revision');
	$payload=$transaction->jsonSerialize();
	$roundTrip=ReactorStateTransaction::fromArray($payload);
	$t->same('order_editor', $roundTrip->component());
	$t->same(7, $roundTrip->baseVersion());
	$t->same('order:7:update', $roundTrip->idempotencyKeyValue());
	$t->same('rebase', $roundTrip->conflictStrategyValue());
	$t->isTrue($roundTrip->offlineCapableValue());
	$t->same(5, $roundTrip->retryPolicy()->attempts());
	$t->same(2, count($roundTrip->patches()));
	$t->same(100, $roundTrip->retryPolicy()->delayMs(3, 0));
})->tag('reactor', 'transactions', 'manifest')->maxMillis(1000);

test('transaction coordinator commits idempotently and emits rollback receipts', static function(Context $t): void {
	$store=(new ReactorInMemoryTransactionStore())->seed('orders', ['count'=>1, 'status'=>'draft']);
	$coordinator=(new ReactorTransactionCoordinator($store))->allowUnauthenticatedTransactions()->allowUnauthenticatedStreams();
	$transaction=ReactorStateTransaction::make('orders', 0)
		->id('tx-1')
		->idempotencyKey('orders:first')
		->increment('count', 2)
		->set('status', 'ready');
	$result=$coordinator->execute($transaction);
	$t->isTrue($result->ok());
	$t->same('committed', $result->status());
	$t->same(1, $result->version());
	$t->same(3, $result->state()['count']);
	$t->same('duplicate', $coordinator->execute($transaction)->status());
	$rollback=$coordinator->execute($result->rollbackTransaction());
	$t->same('committed', $rollback->status());
	$t->same(['count'=>1, 'status'=>'draft'], $rollback->state());
	$t->same('transaction.committed', $coordinator->stream('orders')[0]['type']);
})->tag('reactor', 'transactions', 'idempotency', 'rollback')->maxMillis(1000);

test('transaction coordinator rejects conflicts and can explicitly rebase patches', static function(Context $t): void {
	$store=(new ReactorInMemoryTransactionStore())->seed('counter', ['value'=>10], 3);
	$coordinator=(new ReactorTransactionCoordinator($store))->allowUnauthenticatedTransactions();
	$rejected=$coordinator->execute(ReactorStateTransaction::make('counter', 2)->increment('value'));
	$t->same('conflict', $rejected->status());
	$t->same(3, $rejected->version());
	$rebased=$coordinator->execute(
		ReactorStateTransaction::make('counter', 2)
			->idempotencyKey('counter:rebase')
			->conflictStrategy('rebase')
			->increment('value', 5)
	);
	$t->same('committed', $rebased->status());
	$t->same(15, $rebased->state()['value']);
	$t->isTrue($rebased->metadata()['rebased']);
})->tag('reactor', 'transactions', 'conflicts')->maxMillis(1000);

test('transaction coordinator owns authorization validation and offline replay', static function(Context $t): void {
	$store=(new ReactorInMemoryTransactionStore())->seed('profile', ['age'=>20]);
	$coordinator=(new ReactorTransactionCoordinator($store))
		->authorize(static fn(ReactorStateTransaction $transaction): bool|array => ($transaction->metadataValue()['role'] ?? '')==='admin' ? true : ['Admin role required.'])
		->validate(static fn(array $state): bool|array => ($state['age'] ?? 0)>=18 ? true : ['age'=>'Must be an adult.']);
	$denied=$coordinator->execute(ReactorStateTransaction::make('profile')->set('age', 21));
	$t->same('denied', $denied->status());
	$invalid=$coordinator->execute(ReactorStateTransaction::make('profile')->metadata(['role'=>'admin'])->set('age', 12));
	$t->same('invalid', $invalid->status());
	$offline=ReactorStateTransaction::make('profile')->id('offline-1')->idempotencyKey('offline-age')->offlineCapable()->metadata(['role'=>'admin'])->set('age', 25);
	$t->same('queued', $coordinator->dispatch($offline, false)->status());
	$drained=$coordinator->drain('profile');
	$t->same(1, count($drained));
	$t->same('committed', $drained[0]->status());
	$t->same(25, $store->load('profile')['state']['age']);
	$t->same([], $store->queued('profile'));
})->tag('reactor', 'transactions', 'offline', 'authorization')->maxMillis(1000);

test('file transaction store persists atomic state receipts queues and streams', static function(Context $t): void {
	$root=$t->workspace('reactor-transactions')->root();
	$store=new ReactorFileTransactionStore($root);
	$coordinator=(new ReactorTransactionCoordinator($store))->allowUnauthenticatedTransactions();
	$transaction=ReactorStateTransaction::make('durable-component')->id('durable-1')->idempotencyKey('durable:key')->offlineCapable()->set('ready', true);
	$result=$coordinator->execute($transaction);
	$t->same('committed', $result->status());
	$reopened=new ReactorFileTransactionStore($root);
	$t->isTrue($reopened->load('durable-component')['state']['ready']);
	$t->same('committed', $reopened->receipt('durable-component', 'durable:key')['status']);
	$t->same(1, count($reopened->events('durable-component')));
	$reopened->enqueue(ReactorStateTransaction::make('durable-component', 1)->id('queued-1')->offlineCapable()->set('queued', true));
	$t->same('queued-1', $reopened->queued('durable-component')[0]['id']);
	$t->isTrue($reopened->dequeue('durable-component', 'queued-1'));
})->tag('reactor', 'transactions', 'persistence')->maxMillis(2000);

test('transaction endpoint dispatches JSON payloads and frames cursor event streams', static function(Context $t): void {
	$coordinator=(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore()))->allowUnauthenticatedTransactions()->allowUnauthenticatedStreams();
	$endpoint=(new ReactorTransactionEndpoint($coordinator))->allowInsecureLegacyTransport();
	$payload=json_encode(['reactor_transaction'=>ReactorStateTransaction::make('endpoint')->id('endpoint-1')->set('ready', true)->jsonSerialize()], JSON_THROW_ON_ERROR);
	$result=$endpoint->dispatch($payload);
	$t->same('committed', $result['status']);
	$t->isTrue($result['state']['ready']);
	$stream=$endpoint->stream('endpoint');
	$t->same(1, $stream['cursor']);
	$t->same('transaction.committed', $stream['events'][0]['type']);
	$t->contains('event: transaction.committed', $endpoint->eventStream('endpoint'));
})->tag('reactor', 'transactions', 'endpoint', 'stream')->maxMillis(1000);

test('reactor browser asset exposes optimistic rollback offline replay and streaming APIs', static function(Context $t): void {
	$asset=ReactorClientAssets::assetContent('reactor.js');
	$javascript=(string)($asset['body'] ?? '');
	$t->contains('DataphyreReactorTransactions', $javascript);
	$t->contains('dp:reactor-transaction-', $javascript);
	$t->contains('data-dp-reactor-transaction-state', $javascript);
	$t->contains('offline_capable', $javascript);
	$t->contains('EventSource', $javascript);
	$t->contains('transaction.committed', $javascript);
	$t->contains('data-dp-reactor-tenant', $javascript);
	$t->contains('queue_full', $javascript);
	$t->contains('dataphyre:reactor-logout', $javascript);
	$t->contains('AbortController', $javascript);
})->tag('reactor', 'transactions', 'browser')->maxMillis(1000);

test('reactor transaction facade assembles memory endpoints patches retries and truthful capabilities',static function(Context $t):void{
	$coordinator=ReactorTransactions::memory(['count'=>1],'counter')->allowUnauthenticatedTransactions();$result=$coordinator->execute(ReactorTransactions::make('counter')->increment('count'));$t->same(2,$result->state()['count']);
	$patch=ReactorTransactions::patch('set','ready',true);$t->isTrue($patch->apply([])['state']['ready']);
	$t->same(4,ReactorTransactions::retry(['attempts'=>4])->attempts());
	$t->same('committed',ReactorTransactions::endpoint($coordinator)->allowInsecureLegacyTransport()->dispatch(['component'=>'counter','base_version'=>1,'patches'=>[['operation'=>'set','path'=>'status','value'=>'ready']]])['status']);
	$manifest=ReactorTransactions::manifest();$t->same(2,$manifest['version']);$t->isTrue($manifest['capabilities']['offline_queue']);$t->isTrue($manifest['capabilities']['authorization_fail_closed']);$t->isTrue($manifest['capabilities']['offline_scope_isolation']);$t->isTrue($manifest['capabilities']['cursor_reconnect_deduplication']);
})->tag('reactor','transactions','facade')->maxMillis(1000);
