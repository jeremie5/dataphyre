<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelFilesystemNotificationAdapter;
use Dataphyre\Panel\PanelInboxNotification;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelNotificationActivityStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel atomic snapshots serialize concurrent process writers without lost updates', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-atomic-concurrency');
	$modules=dataphyre_path('runtime/modules');
	(new PanelAtomicSnapshotStore($directory, 'test.panel.concurrent', ['count'=>0], 512));
	$worker=$t->tempFile(<<<'PHP'
<?php
declare(strict_types=1);
$modules=(string)$argv[1];
require_once $modules.'/panel/Framework/Notifications/PanelSnapshotStore.php';
require_once $modules.'/panel/Framework/Notifications/PanelAtomicSnapshotStore.php';
$store=new \Dataphyre\Panel\PanelAtomicSnapshotStore((string)$argv[2], 'test.panel.concurrent', ['count'=>0], 512);
for($index=0; $index<25; $index++){
	$store->transaction(static function(array &$payload): void {
		$payload['count']=(int)($payload['count'] ?? 0)+1;
	}, 'counter.incremented');
}
PHP, 'panel-atomic-worker');
	$processes=[];
	for($index=0; $index<4; $index++){
		$processes[]=$t->startPhpProcess([$worker, $modules, $directory]);
	}
	foreach($processes as $process){
		$result=$process->wait();
		$t->same(0, $result->exitCode(), trim($result->stdout().' '.$result->stderr()));
	}
	$store=new PanelAtomicSnapshotStore($directory, 'test.panel.concurrent', ['count'=>0], 512);
	$t->same(100, $store->payload()['count']);
	$t->same(100, $store->cursor());
})->tag('panel', 'notifications', 'concurrency', 'production')->group('panel-production-runtime');

test('panel atomic snapshots survive invalid tail files and expose stale cursor resets', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-atomic-snapshots');
	$store=new PanelAtomicSnapshotStore($directory, 'test.panel.snapshots', ['value'=>0], 8);
	$t->same(0, $store->cursor());
	for($index=1; $index<=12; $index++){
		$result=$store->transaction(static function(array &$payload) use ($index): int {
			$payload['value']=$index;
			return $index;
		}, 'value.changed', ['value'=>$index]);
		$t->same($index, $result['result']);
	}
	$t->same(12, $store->payload()['value']);
	$t->isTrue($store->changesSince(1)['reset_required']);
	$fresh=$store->changesSince(10);
	$t->isFalse($fresh['reset_required']);
	$t->same([11, 12], array_column($fresh['changes'], 'cursor'));
	file_put_contents($directory.'/99999999999999999999.json', '{broken');
	$t->same(12, (new PanelAtomicSnapshotStore($directory, 'test.panel.snapshots', ['value'=>0], 8))->payload()['value']);
	$store->transaction(static function(array &$payload): void { $payload['value']=13; }, 'value.changed', ['value'=>13]);
	$t->isFalse(is_file($directory.'/99999999999999999999.json'));
	$t->same(8, count(glob($directory.'/*.json') ?: []));
	$t->isTrue($store->manifest()['capabilities']['atomic_commits']);
})->tag('panel', 'notifications', 'production')->group('panel-production-runtime');

test('panel filesystem notification adapter persists lifecycle delivery receipts and cursor changes', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-notification-adapter');
	$adapter=new PanelFilesystemNotificationAdapter($directory, [], ['database', 'mail'], [
		'mail'=>static fn(PanelInboxNotification $notification): array => ['status'=>'delivered', 'data'=>['message'=>$notification->message()]],
	]);
	$global=$adapter->store(new PanelInboxNotification([
		'id'=>'global-notice', 'message'=>'Global notice', 'created_at'=>'2026-01-01T00:00:00+00:00', 'channels'=>['database'],
	]));
	$operator=$adapter->store(PanelNotification::warning('Review order', 'Risk'), 'operator-7', ['order_id'=>91]);
	$t->same(2, count($adapter->all()));
	$t->same(1, count($adapter->forRecipient('operator-7')));
	$t->same(1, count($adapter->forRecipient(null)));
	$t->isTrue($adapter->markRead($operator->id(), '2026-01-02T00:00:00+00:00'));
	$t->same(1, count($adapter->read()));
	$t->isTrue($adapter->dismiss($global->id(), '2026-01-03T00:00:00+00:00'));
	$t->same(1, count($adapter->all()));
	$t->same(2, count($adapter->all(true)));
	$t->isTrue($adapter->restore($global->id()));
	$receipts=$adapter->deliver($operator, ['mail', 'database']);
	$t->same(['delivered', 'queued'], array_column($receipts, 'status'));
	$t->same(2, count($adapter->deliveryReceipts('operator-7', $operator->id())));
	$adapter->meta('tenant', 'north')->meta(['environment'=>'test']);
	$cursor=$adapter->cursor();
	$t->isTrue($cursor>=7);
	$t->isTrue(count($adapter->changesSince(0, 100)['changes'])>=7);

	$rehydrated=new PanelFilesystemNotificationAdapter($directory);
	$t->same('Review order', $rehydrated->get($operator->id())?->message());
	$t->isTrue($rehydrated->get($operator->id())?->isRead() ?? false);
	$t->same(2, $rehydrated->counts(true, 'operator-7')['deliveries']);
	$manifest=$rehydrated->manifest(['run'=>'rehydrated']);
	$t->same('filesystem_atomic_json', $manifest['adapter']);
	$t->same('north', $manifest['meta']['tenant']);
	$t->same('rehydrated', $manifest['meta']['run']);
	$t->isTrue($manifest['capabilities']['realtime_feed']);
	$t->isTrue($rehydrated->delete($global->id()));
	$t->isFalse($rehydrated->delete('missing'));
})->tag('panel', 'notifications', 'filesystem', 'production')->group('panel-production-runtime');

test('panel activity store coordinates preferences subscriptions comments mentions assignments watchers digests and policies', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-notification-activity');
	$store=PanelNotificationActivityStore::make($directory);
	$store->policy('*', static fn(string $operation, array $context): bool|array =>
		$operation==='comment.create' && str_contains((string)($context['body'] ?? ''), 'blocked')
			? ['allowed'=>false, 'reason'=>'Comment rejected by policy.']
			: true
	);
	$preferences=$store->setPreferences('operator-7', [
		'channels'=>['database', 'mail'],
		'digest_frequency'=>'hourly',
		'locale'=>'fr-CA',
		'quiet_hours'=>['start'=>'22:00', 'end'=>'07:00', 'timezone'=>'America/Toronto'],
	]);
	$t->same('hourly', $preferences['digest_frequency']);
	$t->same(['database', 'mail'], $preferences['channels']);
	$subscription=$store->subscribe('operator-7', 'orders.*', ['mail'], 'hourly', ['source'=>'settings']);
	$t->same('orders.*', $subscription['topic']);
	$t->isTrue($store->shouldNotify('operator-7', 'orders.comments', 'mail'));
	$t->isFalse($store->shouldNotify('operator-7', 'orders.comments', 'database'));
	$store->watch('order', 91, 'operator-7', ['reason'=>'owner']);
	$t->same(['operator-7'], $store->watchers('order', 91));
	$comment=$store->comment('order', 91, 'operator-3', 'Please ask @operator-7 and @risk-team.', ['operator-7']);
	$t->same(['operator-7', 'risk-team'], $comment['mentions']);
	$t->same(1, count($store->mentions('operator-7', true)));
	$t->isTrue($store->acknowledge($comment['id'], 'operator-7', '2026-01-04T00:00:00+00:00'));
	$t->same(0, count($store->mentions('operator-7', true)));
	$assignment=$store->assign('order', 91, 'operator-7', 'manager-1', ['queue'=>'risk']);
	$t->same('operator-7', $assignment['assignee']);
	$t->same('operator-7', $store->assignment('order', 91)['assignee']);
	$store->recordActivity('order.shipped', 'system', 'order', 91, ['tracking'=>'DP-1'], ['topic'=>'orders.status']);
	$digest=$store->digest('operator-7', '2000-01-01T00:00:00+00:00');
	$t->isTrue($digest['count']>=3);
	$t->contains('comment.created', array_keys($digest['counts_by_type']));
	$t->same('hourly', $digest['frequency']);
	$t->throws(static fn()=> $store->comment('order', 91, 'operator-3', 'blocked content'), DomainException::class);
	$t->isTrue($store->muteSubscription('operator-7', 'orders.*'));
	$t->isFalse($store->shouldNotify('operator-7', 'orders.status', 'mail'));
	$t->isTrue($store->unwatch('order', 91, 'operator-7'));
	$t->isTrue($store->unassign('order', 91, 'manager-1'));
	$t->isTrue($store->unsubscribe('operator-7', 'orders.*'));
	$store->acknowledgeDigest('operator-7', '2026-01-05T00:00:00+00:00');
	$rehydrated=PanelNotificationActivityStore::make($directory);
	$t->same('2026-01-05T00:00:00+00:00', $rehydrated->preferences('operator-7')['last_digest_at']);
	$t->isTrue(count($rehydrated->changesSince(0, 100)['changes'])>=10);
	$t->isTrue($rehydrated->manifest()['capabilities']['policy_hooks']);
})->tag('panel', 'notifications', 'activity', 'production')->group('panel-production-runtime');
