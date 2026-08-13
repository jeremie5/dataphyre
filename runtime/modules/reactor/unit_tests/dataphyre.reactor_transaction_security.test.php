<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorInMemoryTransactionStore;
use Dataphyre\Reactor\ReactorStateTransaction;
use Dataphyre\Reactor\ReactorTransactionClientAssets;
use Dataphyre\Reactor\ReactorTransactionCoordinator;
use Dataphyre\Reactor\ReactorTransactionEndpoint;
use Dataphyre\Reactor\ReactorTransactionStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['reactor','mvc']);
require_once __DIR__.'/fixtures/reactor_transaction_failure_seams.php';

final class DpReactorTransportFailureStore implements ReactorTransactionStore {
	public function __construct(private readonly string $eventFailure='runtime') {}
	public function load(string $component): array { throw new RuntimeException('secret persistence failure'); }
	public function commit(string $component, int $expectedVersion, array $state, string $idempotencyKey, array $receipt, array $events=[]): bool { return false; }
	public function receipt(string $component, string $idempotencyKey): ?array { return null; }
	public function enqueue(ReactorStateTransaction $transaction): void {}
	public function queued(string $component, int $limit=100): array { return []; }
	public function dequeue(string $component, string $transactionId): bool { return false; }
	public function events(string $component, int $afterSequence=0, int $limit=100): array {
		if($this->eventFailure==='runtime'){ throw new RuntimeException('secret stream runtime'); }
		throw new Error('secret stream error');
	}
}

final class DpReactorOddEventStore implements ReactorTransactionStore {
	public function load(string $component): array { return ['state'=>[], 'version'=>0, 'updated_at'=>0]; }
	public function commit(string $component, int $expectedVersion, array $state, string $idempotencyKey, array $receipt, array $events=[]): bool { return false; }
	public function receipt(string $component, string $idempotencyKey): ?array { return null; }
	public function enqueue(ReactorStateTransaction $transaction): void {}
	public function queued(string $component, int $limit=100): array { return []; }
	public function dequeue(string $component, string $transactionId): bool { return false; }
	public function events(string $component, int $afterSequence=0, int $limit=100): array { return [['sequence'=>1, 'type'=>'***', 'data'=>'safe']]; }
}

test('reactor transaction coordination fails closed for online offline and stream access', static function(Context $t): void {
	$store=(new ReactorInMemoryTransactionStore())->seed('orders', ['count'=>1]);
	$coordinator=new ReactorTransactionCoordinator($store);
	$transaction=ReactorStateTransaction::make('orders')->id('secure-1')->idempotencyKey('secure:key')->offlineCapable()->increment('count');

	$denied=$coordinator->execute($transaction);
	$t->same('denied', $denied->status());
	$t->same('authorization_required', $denied->metadata()['error_code']);
	$t->same([], $denied->state());
	$t->same(1, $store->load('orders')['state']['count']);
	$t->same('denied', $coordinator->dispatch($transaction, false)->status());
	$t->same([], $store->queued('orders'));
	$t->throws(static fn()=>$coordinator->stream('orders'), RuntimeException::class);
	$t->throws(static fn()=>$coordinator->authorizeStream(static fn(): bool=>false)->stream('orders'), RuntimeException::class);
	$t->throws(static fn()=>$coordinator->authorizeStream(static function(): never { throw new RuntimeException('policy down'); })->stream('orders'), RuntimeException::class);

	$legacy=$coordinator->allowUnauthenticatedTransactions()->allowUnauthenticatedStreams();
	$t->same('committed', $legacy->execute($transaction)->status());
	$t->same('transaction.committed', $legacy->stream('orders')[0]['type']);
	$t->same('denied', $legacy->allowUnauthenticatedTransactions(false)->execute(ReactorStateTransaction::make('orders', 1)->set('next', true))->status());
	$t->throws(static fn()=>$legacy->allowUnauthenticatedStreams(false)->stream('orders'), RuntimeException::class);

	$queued=ReactorStateTransaction::make('orders', 1)->id('retained')->offlineCapable()->set('queued', true);
	$store->enqueue($queued);
	$t->same('authorization_required', $coordinator->drain('orders')[0]->metadata()['error_code']);
	$t->same(1, count($store->queued('orders')));
	$t->same('authorization_denied', $coordinator->authorize(static fn(): bool=>false)->drain('orders')[0]->metadata()['error_code']);
	$t->same([], $store->queued('orders'));
})->tag('reactor','transactions','security')->maxMillis(1000);

test('reactor transaction authorization receives verified context and protects duplicate receipts', static function(Context $t): void {
	$store=(new ReactorInMemoryTransactionStore())->seed('profile', ['name'=>'Mina']);
	$authorized=(new ReactorTransactionCoordinator($store))->authorize(
		static fn(ReactorStateTransaction $transaction, array $state, int $version, array $context): bool|string =>
			($context['user'] ?? '')==='operator-1' ? true : 'Operator authentication is required.'
	);
	$transaction=ReactorStateTransaction::make('profile')->id('profile-1')->idempotencyKey('profile:key')->set('name', 'Iris');
	$t->same('denied', $authorized->execute($transaction)->status());
	$t->same('committed', $authorized->execute($transaction, ['user'=>'operator-1'])->status());

	$denying=(new ReactorTransactionCoordinator($store))->authorize(static fn(): bool=>false);
	$t->same('denied', $denying->execute($transaction)->status());
	$t->same('authorization_denied', $denying->execute($transaction)->metadata()['error_code']);
	$throwing=(new ReactorTransactionCoordinator($store))->authorize(static function(): never { throw new RuntimeException('secret policy internals'); });
	$result=$throwing->execute(ReactorStateTransaction::make('profile', 1)->set('name', 'Nope'));
	$t->same('authorization_unavailable', $result->metadata()['error_code']);
	$t->notContains('secret', implode(' ', $result->errors()));
})->tag('reactor','transactions','security','idempotency')->maxMillis(1000);

test('reactor transaction callbacks turn internal failures into stable non-leaking results', static function(Context $t): void {
	$transaction=ReactorStateTransaction::make('failures')->set('ready', true);
	$mutator=(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore()))
		->allowUnauthenticatedTransactions()
		->mutate(static function(): never { throw new RuntimeException('secret mutator'); });
	$t->same('mutation_failed', $mutator->execute($transaction)->metadata()['error_code']);

	$validator=(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore()))
		->allowUnauthenticatedTransactions()
		->validate(static function(): never { throw new RuntimeException('secret validator'); });
	$t->same('validation_unavailable', $validator->execute($transaction)->metadata()['error_code']);

	$resource=fopen('php://memory', 'rb');
	$serialization=(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore()))
		->allowUnauthenticatedTransactions()
		->mutate(static fn(): array=>['resource'=>$resource]);
	$result=$serialization->execute($transaction);
	if(is_resource($resource)){ fclose($resource); }
	$t->same('serialization_failed', $result->metadata()['error_code']);
	$t->notContains('secret', implode(' ', $mutator->execute($transaction)->errors()).' '.implode(' ', $validator->execute($transaction)->errors()));
})->tag('reactor','transactions','security','failures')->maxMillis(1000);

test('reactor transaction endpoint composes host origin csrf transport and stream policy', static function(Context $t): void {
	$store=new ReactorInMemoryTransactionStore();
	$coordinator=(new ReactorTransactionCoordinator($store))
		->authorize(static fn(ReactorStateTransaction $transaction, array $state, int $version, array $context): bool=>($context['user'] ?? '')==='operator-1')
		->authorizeStream(static fn(string $component, array $context): bool=>$component==='orders' && ($context['user'] ?? '')==='operator-1');
	$unconfigured=new ReactorTransactionEndpoint($coordinator);
	$payload=['component'=>'orders','patches'=>[['operation'=>'set','path'=>'ready','value'=>true]]];
	$t->same('transport_security_required', $unconfigured->dispatch($payload)['error']['code']);
	$t->contains('event: reactor.error', $unconfigured->eventStream('orders'));
	$locked=(new ReactorTransactionEndpoint(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore())))->allowInsecureLegacyTransport()->dispatch($payload, true, ['correlation_id'=>'locked-1']);
	$t->same('authorization_required', $locked['error']['code']);
	$t->same('locked-1', $locked['error']['correlation_id']);

	$endpoint=$unconfigured
		->validateOrigin(static fn(array $context): bool=>($context['origin'] ?? '')==='https://panel.example')
		->validateCsrf(static fn(array $context): bool=>hash_equals('csrf-token', (string)($context['csrf'] ?? '')))
		->authorizeTransport(static fn(string $operation, array $context, array $resource): bool=>($context['user'] ?? '')==='operator-1');
	$base=['user'=>'operator-1','origin'=>'https://panel.example','csrf'=>'csrf-token','correlation_id'=>'request-7'];
	$t->same('origin_denied', $endpoint->dispatch($payload, true, array_replace($base, ['origin'=>'https://evil.example']))['error']['code']);
	$t->same('csrf_denied', $endpoint->dispatch($payload, true, array_replace($base, ['csrf'=>'bad']))['error']['code']);
	$invalid=$endpoint->dispatch('{broken', true, $base);
	$t->same('invalid_json', $invalid['error']['code']);
	$t->same('request-7', $invalid['error']['correlation_id']);
	$committed=$endpoint->dispatch($payload, true, $base);
	$t->same('committed', $committed['status']);
	$t->same(1, $committed['schema_version']);
	$t->same(1, $endpoint->stream('orders', 0, 100, $base)['cursor']);
	$t->contains('event: transaction.committed', $endpoint->eventStream('orders', 0, 100, $base));
	$t->same('stream_authorization_denied', $endpoint->stream('other', 0, 100, $base)['error']['code']);

	$throwing=$unconfigured->authorizeTransport(static function(): never { throw new RuntimeException('secret transport internals'); });
	$error=$throwing->dispatch($payload, true, $base);
	$t->same('transport_authorization_unavailable', $error['error']['code']);
	$t->notContains('secret', json_encode($error, JSON_THROW_ON_ERROR));
	$t->same('origin_validation_unavailable', $unconfigured->validateOrigin(static function(): never { throw new RuntimeException('origin secret'); })->authorizeTransport(static fn(): bool=>true)->dispatch($payload, true, $base)['error']['code']);
	$t->same('csrf_validation_unavailable', $unconfigured->validateCsrf(static function(): never { throw new RuntimeException('csrf secret'); })->authorizeTransport(static fn(): bool=>true)->dispatch($payload, true, $base)['error']['code']);
	$t->same('origin_denied', $unconfigured->validateOrigin(static fn(): string=>'Origin blocked publicly.')->authorizeTransport(static fn(): bool=>true)->dispatch($payload, true, $base)['error']['code']);
	$t->same('custom_transport_denial', $unconfigured->authorizeTransport(static fn(): array=>['status'=>'blocked','code'=>'custom_transport_denial','message'=>'Public denial.'])->dispatch($payload, true, $base)['error']['code']);
	$t->same('transport_denied', $unconfigured->authorizeTransport(static fn(): bool=>false)->dispatch($payload, true, $base)['error']['code']);
})->tag('reactor','transactions','security','endpoint','sse')->maxMillis(1000);

test('reactor endpoint sanitizes persistence failures stream failures odd events and correlation fallback', static function(Context $t): void {
	$context=['correlation_id'=>'request / unsafe'];
	$dispatch=(new ReactorTransactionEndpoint(
		(new ReactorTransactionCoordinator(new DpReactorTransportFailureStore()))->allowUnauthenticatedTransactions()
	))->allowInsecureLegacyTransport()->dispatch(['component'=>'failure','patches'=>[['operation'=>'set','path'=>'ready','value'=>true]]], true, $context);
	$t->same('transaction_dispatch_failed', $dispatch['error']['code']);
	$t->same('requestunsafe', $dispatch['error']['correlation_id']);
	$t->notContains('secret', json_encode($dispatch, JSON_THROW_ON_ERROR));

	$runtime=(new ReactorTransactionEndpoint(
		(new ReactorTransactionCoordinator(new DpReactorTransportFailureStore('runtime')))->allowUnauthenticatedStreams()
	))->allowInsecureLegacyTransport()->stream('failure');
	$t->same('stream_unavailable', $runtime['error']['code']);
	$t->notContains('secret', json_encode($runtime, JSON_THROW_ON_ERROR));
	$error=(new ReactorTransactionEndpoint(
		(new ReactorTransactionCoordinator(new DpReactorTransportFailureStore('error')))->allowUnauthenticatedStreams()
	))->allowInsecureLegacyTransport()->stream('failure');
	$t->same('stream_unavailable', $error['error']['code']);

	$odd=(new ReactorTransactionEndpoint(
		(new ReactorTransactionCoordinator(new DpReactorOddEventStore()))->allowUnauthenticatedStreams()
	))->allowInsecureLegacyTransport();
	$t->contains("event: message\n", $odd->eventStream('odd'));

	$failures=$t->state('reactor.transaction-failures', ['random_bytes'=>true]);
	$fallback=(new ReactorTransactionEndpoint(new ReactorTransactionCoordinator(new ReactorInMemoryTransactionStore())))->dispatch([]);
	$t->matches('/^rtxerr_[a-f0-9]+$/', $fallback['error']['correlation_id']);
	$failures->put('random_bytes', false);
})->tag('reactor','transactions','security','endpoint','failures')->maxMillis(1000);

test('reactor transaction browser contract scopes queues and consumes named deduplicated SSE events', static function(Context $t): void {
	$javascript=ReactorTransactionClientAssets::javascript();
	$t->contains('dp-reactor-offline:v1:', $javascript);
	$t->contains('data-dp-reactor-tenant', $javascript);
	$t->contains('data-dp-reactor-user', $javascript);
	$t->contains('data-dp-reactor-session', $javascript);
	$t->contains('data-dp-reactor-contract-version', $javascript);
	$t->contains('offlineTtlMs', $javascript);
	$t->contains('queue_full', $javascript);
	$t->contains('quota_exceeded', $javascript);
	$t->contains('item_too_large', $javascript);
	$t->contains('scope_required', $javascript);
	$t->contains('dataphyre:reactor-logout', $javascript);
	$t->contains('purge:purge', $javascript);
	$t->contains('streams.forEach', $javascript);
	$t->contains('source.addEventListener(type,handle)', $javascript);
	$t->contains('transaction.committed', $javascript);
	$t->contains('event.lastEventId', $javascript);
	$t->contains('after_sequence', $javascript);
	$t->contains('sessionStorage.setItem(storedKey', $javascript);
	$t->contains('stream-duplicate', $javascript);
	$t->contains('stream-gap', $javascript);
	$t->notContains('items[i].conflict_strategy=items[i].conflict_strategy==="reject"?"rebase"', $javascript);
})->tag('reactor','transactions','security','browser','sse','offline')->maxMillis(1000);
