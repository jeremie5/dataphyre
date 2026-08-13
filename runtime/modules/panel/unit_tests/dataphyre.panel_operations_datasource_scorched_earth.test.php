<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelCallbackDataSource;
use Dataphyre\Panel\PanelDataCursor;
use Dataphyre\Panel\PanelDataJob;
use Dataphyre\Panel\PanelDataJobOperationBridge;
use Dataphyre\Panel\PanelDataPage;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelLocalOperationQueue;
use Dataphyre\Panel\PanelOperationConflict;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelOperationExecution;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Panel\PanelRepositoryDataSource;
use Dataphyre\Panel\PanelSynchronousOperationRunner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('panel data query is immutable normalized serializable and cursor-bound', static function(Context $t): void {
	$base=PanelDataQuery::make();
	$query=$base
		->where('status', 'active')
		->orWhere('priority', 'gte', 8)
		->sort('profile.name', 'DESC')
		->search('Ada Lovelace', ['profile.name', 'email'])
		->select(['id', 'profile.name', 'id'])
		->include(['orders'])
		->limit(25)
		->offset(50)
		->aggregate('record_count', 'count')
		->aggregate('revenue_total', 'sum', 'revenue')
		->tenant('north')
		->authorization(['ability'=>'orders.view'])
		->metadata(['request_id'=>'r-1']);

	$t->same([], $base->filterList());
	$t->same(2, count($query->filterList()));
	$t->same('or', $query->filterList()[1]['boolean']);
	$t->same('desc', $query->sortList()[0]['direction']);
	$t->same(['id', 'profile.name'], $query->selectedFields());
	$t->same(50, $query->offsetValue());
	$t->same('north', $query->tenantKey());
	$t->same('panel_data_query', $query->jsonSerialize()['type']);

	$cursor=PanelDataCursor::encode(75, $query->fingerprint());
	$t->same(75, PanelDataCursor::decode($cursor, $query->fingerprint()));
	$cursorQuery=$query->cursor($cursor);
	$t->same(0, $cursorQuery->offsetValue());
	$t->same($query->fingerprint(), $cursorQuery->fingerprint());
	$t->throws(static fn()=>PanelDataCursor::decode($cursor, PanelDataQuery::make()->fingerprint()), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->where('bad field', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->where('id', 'wat', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->where('id', 'between', [1]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->limit(0), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->aggregate('sum', 'sum'), InvalidArgumentException::class);

	$roundTrip=PanelDataQuery::fromArray($query->jsonSerialize());
	$t->same($query->jsonSerialize(), $roundTrip->jsonSerialize());
})->tag('panel', 'operations', 'data-source', 'query')->maxMillis(1000);

test('panel array data source implements filters search sorting tenancy authorization selection and includes', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1, 'tenant_id'=>'north', 'name'=>'Ada Lovelace', 'status'=>'active', 'score'=>9, 'tags'=>['vip', 'math'], 'profile'=>['city'=>'London'], 'orders'=>[['id'=>'o-1']]],
		['id'=>2, 'tenant_id'=>'north', 'name'=>'Grace Hopper', 'status'=>'active', 'score'=>8, 'tags'=>['navy'], 'profile'=>['city'=>'New York'], 'orders'=>[]],
		['id'=>3, 'tenant_id'=>'south', 'name'=>'Ada Byron', 'status'=>'pending', 'score'=>10, 'tags'=>['vip'], 'profile'=>['city'=>null], 'orders'=>[['id'=>'o-3']]],
		['id'=>4, 'tenant_id'=>'north', 'name'=>'Blocked User', 'status'=>'active', 'score'=>99, 'tags'=>['vip'], 'profile'=>['city'=>'Paris'], 'orders'=>[]],
	], [
		'name'=>'people', 'search_fields'=>['name', 'profile.city'],
		'authorize'=>static fn(array $row, array $auth): bool=>($auth['allow_blocked'] ?? false) || $row['id']!==4,
	]);

	$query=PanelDataQuery::make()
		->tenant('north')->authorization(['allow_blocked'=>false])
		->where('status', 'active')->where('score', 'between', [8, 10])
		->where('tags', 'contains', 'vip')->where('name', 'starts_with', 'ada')
		->where('name', 'ends_with', 'lace')->where('profile.city', 'not_null')
		->search('ada london')->sort('score', 'desc')
		->select(['id', 'name', 'profile.city'])->include(['orders']);
	$result=$source->query($query);
	$t->same('people', $result->source());
	$t->same(1, count($result));
	$t->same(1, $result->items()[0]['id']);
	$t->same('London', $result->items()[0]['profile']['city']);
	$t->same('o-1', $result->items()[0]['orders'][0]['id']);
	$t->same([[['id'=>'o-1']]], $result->included()['orders']);
	$t->isTrue($result->metadata()['tenant_applied']);
	$t->isTrue($result->metadata()['authorization_applied']);

	$t->same([1, 2], array_column($source->query(PanelDataQuery::make()->tenant('north')->authorization([])->where('id', 'in', [1, 2])->where('id', 'not_in', [4])->sort('id'))->items(), 'id'));
	$t->same([3], array_column($source->query(PanelDataQuery::make()->where('profile.city', 'is_null'))->items(), 'id'));
	$t->same([1, 2], array_column($source->query(PanelDataQuery::make()->where('score', 'lt', 9)->orWhere('name', 'contains', 'Ada Lovelace')->sort('id'))->items(), 'id'));
	$t->same([2], array_column($source->query(PanelDataQuery::make()->where('tags', 'not_contains', 'vip')->where('status', 'neq', 'pending'))->items(), 'id'));
	$t->same(2, $source->find(2, PanelDataQuery::make()->tenant('north'))['id']);
	$t->same(null, $source->find(3, PanelDataQuery::make()->tenant('north')));
})->tag('panel', 'operations', 'data-source', 'array')->maxMillis(1000);

test('panel array pagination emits stable cursors and computes pre-page aggregates', static function(Context $t): void {
	$rows=[];
	foreach(range(1, 7) as $id){ $rows[]=['id'=>$id, 'tenant_id'=>'t', 'amount'=>$id*10, 'group'=>$id%2 ? 'odd' : 'even']; }
	$source=new PanelArrayDataSource($rows);
	$query=PanelDataQuery::make()->tenant('t')->sort('id')->limit(3)
		->aggregate('rows', 'count')->aggregate('amount_sum', 'sum', 'amount')
		->aggregate('amount_avg', 'avg', 'amount')->aggregate('amount_min', 'min', 'amount')
		->aggregate('amount_max', 'max', 'amount')->aggregate('groups', 'distinct_count', 'group');
	$first=$source->query($query);
	$t->same([1, 2, 3], array_column($first->items(), 'id'));
	$t->same(7, $first->page()->total());
	$t->same(3, $first->page()->returned());
	$t->same(3, $first->page()->pageCount());
	$t->same(['rows'=>7, 'amount_sum'=>280, 'amount_avg'=>40, 'amount_min'=>10, 'amount_max'=>70, 'groups'=>2], $first->aggregates());
	$t->notNull($first->page()->nextCursor());

	$second=$source->query($query->cursor($first->page()->nextCursor()));
	$t->same([4, 5, 6], array_column($second->items(), 'id'));
	$t->notNull($second->page()->previousCursor());
	$third=$source->query($query->cursor($second->page()->nextCursor()));
	$t->same([7], array_column($third->items(), 'id'));
	$t->same(null, $third->page()->nextCursor());
	$t->throws(static fn()=>$source->query($query->where('group', 'odd')->cursor($first->page()->nextCursor())), InvalidArgumentException::class);
})->tag('panel', 'operations', 'data-source', 'cursor', 'aggregates')->maxMillis(1000);

test('panel array mutations produce ordered resumable change feeds', static function(Context $t): void {
	$source=new PanelArrayDataSource([['id'=>1, 'name'=>'One']]);
	$subscription=$source->subscribe();
	$source->upsert(['id'=>2, 'name'=>'Two'])->upsert(['id'=>1, 'name'=>'Uno']);
	$t->isTrue($source->remove(2));
	$t->isFalse($source->remove(99));
	$changes=$subscription->poll(2);
	$t->same(['insert', 'update'], array_map(static fn($change)=>$change->operation(), $changes));
	$t->same(2, $subscription->cursor());
	$t->same('delete', $subscription->poll()[0]->operation());
	$source->replace([['id'=>9, 'name'=>'Nine']]);
	$t->same('replace', $subscription->poll()[0]->operation());
	$t->same(9, $source->find(9)['id']);
	$subscription->close();
	$t->isTrue($subscription->closed());
	$t->same([], $subscription->poll());
})->tag('panel', 'operations', 'data-source', 'change-feed')->maxMillis(1000);

test('panel callback repository result page and registry adapters share one manifest contract', static function(Context $t): void {
	$callback=new PanelCallbackDataSource(
		static fn(PanelDataQuery $query): array=>[['id'=>10, 'query_limit'=>$query->limitValue()]],
		static fn(string|int $id): array=>['id'=>$id],
		'remote'
	);
	$result=$callback->query(PanelDataQuery::make()->limit(5));
	$t->instanceOf(PanelDataResult::class, $result);
	$t->same(10, $result->items()[0]['id']);
	$t->same('panel_data_result', $result->jsonSerialize()['type']);
	$t->same('panel_data_page', $result->page()->jsonSerialize()['type']);
	$t->same(42, $callback->find(42)['id']);

	$repository=new class {
		public function query(PanelDataQuery $query): array { return ['items'=>[['id'=>7]], 'page'=>['offset'=>0, 'limit'=>$query->limitValue(), 'total'=>1], 'metadata'=>['repo'=>true]]; }
		public function find(string|int $id, PanelDataQuery $query): array { return ['id'=>$id, 'tenant'=>$query->tenantKey()]; }
		public function panelDataCapabilities(): array { return ['cursor'=>false, 'custom'=>'yes']; }
	};
	$repositorySource=new PanelRepositoryDataSource($repository);
	$t->same(7, $repositorySource->query(PanelDataQuery::make()->limit(9))->items()[0]['id']);
	$t->same('north', $repositorySource->find(7, PanelDataQuery::make()->tenant('north'))['tenant']);
	$t->isFalse($repositorySource->capabilities()['cursor']);

	$registry=(new PanelDataSourceRegistry())->register('Remote Orders', $callback)->register('repository', $repositorySource);
	$t->same(['remote_orders', 'repository'], $registry->names());
	$t->same(2, $registry->manifest()['count']);
	$t->same($callback, $registry->get('remote orders'));
	$t->throws(static fn()=>$registry->register('remote orders', $callback), LogicException::class);
	$t->throws(static fn()=>new PanelDataPage(0, 1, 2, 2), InvalidArgumentException::class);
})->tag('panel', 'operations', 'data-source', 'adapters')->maxMillis(1000);

test('panel operation records enforce transitions counters checkpoints logs artifacts retry and control', static function(Context $t): void {
	$record=PanelOperationRecord::make('CSV Export', 'Nightly export', [
		'id'=>'job-1', 'max_attempts'=>2, 'total'=>4, 'payload'=>['tenant'=>'north'],
		'metadata'=>['actor'=>'operator'], 'created_at'=>'2026-07-12T00:00:00Z',
	]);
	$t->same('csv_export', $record->type());
	$t->same(PanelOperationStatus::QUEUED, $record->status());
	$t->same(0, $record->percent());
	$running=$record->start('worker-1', '2026-07-12T00:00:01Z')
		->progress(2, 4, 'Halfway', 2, 0, '2026-07-12T00:00:02Z')
		->checkpoint('page 1', ['cursor'=>'abc'], '2026-07-12T00:00:03Z')
		->log('info', 'Chunk complete', ['rows'=>2], '2026-07-12T00:00:04Z')
		->artifact('orders.csv', '/exports/orders.csv', 'text/csv', 42, ['rows'=>2], '2026-07-12T00:00:05Z');
	$t->same(1, $running->attempt());
	$t->same(50, $running->percent());
	$t->same('page_1', $running->checkpoints()[0]['name']);
	$t->same(42, $running->artifacts()[0]['bytes']);
	$retry=$running->retry(0, '2026-07-12T00:00:06Z');
	$t->same(PanelOperationStatus::RETRY_WAIT, $retry->status());
	$second=$retry->start('worker-2', '2026-07-12T00:00:07Z')->complete(['path'=>'done'], PanelOperationStatus::COMPLETED_WITH_FAILURES, '2026-07-12T00:00:08Z');
	$t->isTrue($second->terminal());
	$t->same(100, $second->percent());
	$t->isFalse($second->canRetry());
	$t->same('done', $second->result()['path']);

	$paused=$record->start()->requestPause()->markPaused();
	$t->same(PanelOperationStatus::QUEUED, $paused->resume()->status());
	$t->same(PanelOperationStatus::CANCELLED, $record->requestCancel()->status());
	$t->throws(static fn()=>$record->complete(), LogicException::class);
	$t->throws(static fn()=>$record->start()->progress(5, 4), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationRecord::make('x', 'x', ['id'=>'../escape']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationRecord::make('x', 'x', ['payload'=>['bad'=>fopen('php://temp', 'rb')]]), InvalidArgumentException::class);
})->tag('panel', 'operations', 'record', 'lifecycle')->maxMillis(1000);

test('panel filesystem operation store is atomic checksummed idempotent and optimistic', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-operation-store');
	$store=new PanelFilesystemOperationStore($directory);
	$first=$store->create(PanelOperationRecord::make('export', 'One', ['id'=>'store-1', 'idempotency_key'=>'same-key']));
	$t->same(1, $first->revision());
	$t->same('store-1', $store->get('store-1')->id());
	$duplicate=$store->create(PanelOperationRecord::make('export', 'Duplicate', ['id'=>'store-2', 'idempotency_key'=>'same-key']));
	$t->same('store-1', $duplicate->id());
	$t->same('store-1', $store->findByIdempotencyKey('same-key')->id());

	$cancelled=$store->save($first->requestCancel(), 1);
	$t->same(2, $cancelled->revision());
	$t->same(PanelOperationStatus::CANCELLED, $cancelled->status());
	$t->throws(static fn()=>$store->save($first, 1), PanelOperationConflict::class);
	$t->same(1, count($store->all(['status'=>PanelOperationStatus::CANCELLED])));
	$t->same(1, $store->diagnostics()['records']);
	$t->throws(static fn()=>$store->update('store-1', static fn()=>null), UnexpectedValueException::class);
	$t->throws(static fn()=>$store->all(['unknown'=>null]), InvalidArgumentException::class);

	$path=$directory.DIRECTORY_SEPARATOR.rawurlencode('store-1').'.json';
	$contents=(string)file_get_contents($path);
	file_put_contents($path, str_replace('Nightly-impossible-token', 'x', $contents));
	$t->same('store-1', $store->get('store-1')->id());
	file_put_contents($path, str_replace('"name": "One"', '"name": "Tampered"', $contents));
	$t->throws(static fn()=>$store->get('store-1'), UnexpectedValueException::class);
})->tag('panel', 'operations', 'store', 'filesystem')->maxMillis(2000);

test('panel local queue and synchronous runner persist progress output and terminal results', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-runner'));
	$handlers=(new PanelOperationHandlerRegistry())->register('sum', static function(array $payload, PanelOperationExecution $execution): array {
		$execution->log('info', 'Starting sum.');
		$total=count($payload['values']);
		$sum=0;
		foreach($payload['values'] as $index=>$value){
			$sum+=$value;
			$execution->progress($index+1, $total, 'Summing', $index+1, 0);
		}
		$execution->checkpoint('summed', ['sum'=>$sum]);
		$execution->artifact('sum.json', 'memory://sum.json', 'application/json', 10);
		return ['sum'=>$sum];
	});
	$queue=new PanelLocalOperationQueue($store);
	$runner=new PanelSynchronousOperationRunner($store, $handlers, $queue);
	$record=$runner->submit('sum', 'Add values', ['values'=>[2, 3, 5]], ['id'=>'sum-1', 'total'=>3, 'idempotency_key'=>'sum:1']);
	$t->same(1, $queue->size());
	$worked=$runner->work(null, 5, 'worker-a');
	$t->same(1, count($worked));
	$completed=$worked[0];
	$t->same(PanelOperationStatus::COMPLETED, $completed->status());
	$t->same(10, $completed->result()['sum']);
	$t->same(3, $completed->processed());
	$t->same(1, count($completed->checkpoints()));
	$t->same(1, count($completed->artifacts()));
	$t->same(0, $queue->size());
	$t->same($completed->id(), $runner->submit('sum', 'Duplicate', [], ['id'=>'sum-2', 'idempotency_key'=>'sum:1'])->id());
})->tag('panel', 'operations', 'runner', 'queue')->maxMillis(2000);

test('panel runner retries failures and cooperatively pauses resumes and cancels', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-control'));
	$attempts=0;
	$handlers=(new PanelOperationHandlerRegistry())->register('flaky', static function()use(&$attempts): array {
		$attempts++; if($attempts===1){ throw new RuntimeException('Transient'); } return ['attempts'=>$attempts];
	});
	$runner=new PanelSynchronousOperationRunner($store, $handlers);
	$flaky=$runner->submit('flaky', 'Flaky', [], ['id'=>'flaky-1', 'max_attempts'=>2]);
	$retry=$runner->run($flaky->id());
	$t->same(PanelOperationStatus::RETRY_WAIT, $retry->status());
	$t->same(1, count($retry->logs()));
	$t->same(PanelOperationStatus::COMPLETED, $runner->run($flaky->id())->status());

	$control=new PanelOperationControl($store);
	$handlers->register('pauseable', static function(array $payload, PanelOperationExecution $execution)use($control): array {
		$control->pause($execution->id());
		$execution->heartbeat();
		return [];
	});
	$pauseable=$runner->submit('pauseable', 'Pauseable', [], ['id'=>'pause-1']);
	$t->same(PanelOperationStatus::PAUSED, $runner->run($pauseable->id())->status());
	$t->same(PanelOperationStatus::QUEUED, $control->resume($pauseable->id())->status());
	$handlers->register('pauseable', static fn(): array=>['resumed'=>true], true);
	$t->same(PanelOperationStatus::COMPLETED, $runner->run($pauseable->id())->status());

	$cancel=$runner->submit('flaky', 'Cancel', [], ['id'=>'cancel-1']);
	$t->same(PanelOperationStatus::CANCELLED, $control->cancel($cancel->id())->status());

	$missing=$runner->submit('missing_handler', 'Missing', [], ['id'=>'missing-1']);
	$failed=$runner->run($missing->id());
	$t->same(PanelOperationStatus::FAILED, $failed->status());
	$t->same('critical', $failed->logs()[0]['level']);
	$handlers->register('missing_handler', static fn(): array=>['recovered'=>true]);
	$retried=$control->retry($missing->id());
	$t->same(PanelOperationStatus::QUEUED, $retried->status());
	$t->same(2, $retried->maxAttempts());
	$t->isTrue($runner->run($missing->id())->result()['recovered']);
})->tag('panel', 'operations', 'runner', 'retry', 'control')->maxMillis(2000);

test('panel operation control recovers stale workers and legacy PanelDataJob bridge persists its outcome', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-operation-bridge'));
	$handlers=new PanelOperationHandlerRegistry();
	$runner=new PanelSynchronousOperationRunner($store, $handlers);
	$stale=PanelOperationRecord::make('stale', 'Stale', ['id'=>'stale-1', 'max_attempts'=>2, 'created_at'=>'2020-01-01T00:00:00Z'])
		->start('dead-worker', '2020-01-01T00:00:01Z');
	$store->create($stale);
	$recovered=(new PanelOperationControl($store))->recoverStale(1);
	$t->same(1, count($recovered));
	$t->same(PanelOperationStatus::RETRY_WAIT, $recovered[0]->status());

	$job=PanelDataJob::import('legacy import')->id('legacy-1')->items([1, 2, 3])->handle(static fn(int $item): int=>$item*2);
	$bridged=PanelDataJobOperationBridge::execute($job, $runner);
	$t->same(PanelOperationStatus::COMPLETED, $bridged->status());
	$t->same(3, $bridged->processed());
	$t->same(3, $bridged->succeeded());
	$t->same('completed', $bridged->result()['status']);
	$t->same('PanelDataJob', $bridged->metadata()['bridge']);
})->tag('panel', 'operations', 'bridge', 'recovery')->maxMillis(3000);
