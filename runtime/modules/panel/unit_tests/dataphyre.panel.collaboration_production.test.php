<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelCollaborationPolicyException;
use Dataphyre\Panel\PanelCollaborationStateEngine;
use Dataphyre\Panel\PanelFilesystemCollaborationStore;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel collaboration records threads comments mentions assignments watches subscriptions and immutable receipts', static function(Context $t): void {
	$store=new PanelInMemoryCollaborationStore();
	$manager=new PanelCollaborationManager($store, static fn(string $operation, ?string $actor, array $context): bool|array =>
		$operation==='comment.create' && str_contains((string)($context['body'] ?? ''), 'blocked')
			? ['allowed'=>false, 'reason'=>'Blocked by collaboration policy.']
			: true
	);
	$thread=$manager->createThread('operator-1', 'order', 91, 'Risk review', ['priority'=>'high'], 'order-91-risk');
	$t->same('open', $thread['status']);
	$comment=$manager->comment('order-91-risk', 'operator-2', 'Please review @operator-7 and @risk-team.', ['operator-7']);
	$t->same(['operator-7', 'risk-team'], $comment['mentions']);
	$t->same(1, count($manager->comments('order-91-risk')));
	$t->same(1, count($manager->mentions('operator-7')));
	$t->same('resolved', $manager->setThreadStatus('order-91-risk', 'resolved', 'operator-1')['status']);
	$t->same('operator-7', $manager->assign('order', 91, 'operator-7', 'manager-1')['assignee']);
	$t->same('operator-7', $manager->assignment('order', 91)['assignee']);
	$manager->watch('order', 91, 'operator-7', ['reason'=>'owner']);
	$t->same(['operator-7'], $manager->watchers('order', 91));
	$t->same('hourly', $manager->subscribe('orders.*', 'operator-7', ['mail'], 'hourly')['mode']);
	$t->same(1, count($manager->subscriptions('operator-7')));
	$t->throws(static fn()=> $manager->comment('order-91-risk', 'operator-2', 'blocked message'), PanelCollaborationPolicyException::class);
	$t->isTrue($manager->verifyReceipts()['valid']);
	$t->same(6, $manager->verifyReceipts()['count']);
	$t->same(6, count($manager->receipts()));
	$t->isTrue($manager->unwatch('order', 91, 'operator-7'));
	$t->isTrue($manager->unsubscribe('orders.*', 'operator-7'));
	$t->isTrue($manager->unassign('order', 91, 'manager-1'));
	$t->isTrue($manager->verifyReceipts()['valid']);
	$t->same(9, $manager->verifyReceipts()['count']);
})->tag('panel', 'collaboration', 'threads', 'receipts', 'production')->group('panel-production-runtime');

test('panel collaboration presence stores only lease hashes and typing indicators expire deterministically', static function(Context $t): void {
	$store=new PanelInMemoryCollaborationStore();
	$manager=new PanelCollaborationManager($store);
	$manager->createThread('operator-1', 'order', 91, '', [], 'order-91');
	$lease=$manager->acquirePresence('order:91', 'operator-7', 30, ['device'=>'desktop', 'access_token'=>'drop-me']);
	$t->isTrue(isset($lease['lease_token']));
	$t->isFalse(isset($lease['lease_hash']));
	$t->isFalse(str_contains((string)json_encode($store->state()), 'drop-me'));
	$t->same(1, count($manager->presence('order:91')));
	$t->throws(static fn()=> $manager->heartbeatPresence('order:91', 'operator-7', 'wrong'), UnexpectedValueException::class);
	$t->same('operator-7', $manager->heartbeatPresence('order:91', 'operator-7', $lease['lease_token'], 30)['user_id']);
	$t->isTrue($manager->typing('order-91', 'operator-7', true, 5));
	$t->same(['operator-7'], $manager->typingUsers('order-91'));
	$future=time()+60;
	$t->same([], $manager->presence('order:91', $future));
	$t->same([], $manager->typingUsers('order-91', $future));
	$removed=$manager->cleanupExpired($future);
	$t->same(['presence'=>1, 'typing'=>1], $removed);
	$t->isFalse(str_contains((string)json_encode($manager->manifest()), 'lease_hash'));
	$t->isTrue($manager->manifest()['capabilities']['presence_leases']);
})->tag('panel', 'collaboration', 'presence', 'typing', 'production')->group('panel-production-runtime');

test('panel filesystem collaboration persists records and emits ordered stale-cursor reset feeds', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-collaboration-filesystem');
	$store=new PanelFilesystemCollaborationStore($directory, 8);
	$manager=new PanelCollaborationManager($store);
	$manager->createThread('operator-1', 'order', 91, 'Operations', [], 'order-91');
	for($index=1; $index<=12; $index++){
		$manager->comment('order-91', 'operator-'.$index, 'Comment '.$index);
	}
	$t->same(13, $manager->cursor());
	$reset=$manager->changesSince(1);
	$t->isTrue($reset['reset_required']);
	$t->isTrue(is_array($reset['snapshot']['payload'] ?? null));
	$t->isFalse(str_contains((string)json_encode($reset), 'lease_hash'));
	$fresh=$manager->changesSince(11);
	$t->same([12, 13], array_column($fresh['changes'], 'cursor'));
	$rehydrated=new PanelCollaborationManager(new PanelFilesystemCollaborationStore($directory, 8));
	$t->same(12, count($rehydrated->comments('order-91')));
	$t->same(13, $rehydrated->verifyReceipts()['count']);
	$t->isTrue($rehydrated->verifyReceipts()['valid']);
	$t->same('filesystem_atomic_json', $rehydrated->manifest()['store']['adapter']);
})->tag('panel', 'collaboration', 'filesystem', 'cursor', 'production')->group('panel-production-runtime');

test('panel receipt verification detects payload and chain tampering', static function(Context $t): void {
	$state=PanelCollaborationStateEngine::initialState();
	$first=PanelCollaborationStateEngine::receipt($state, 'thread.created', 'operator-1', ['thread_id'=>'one'], ['title'=>'First']);
	$second=PanelCollaborationStateEngine::receipt($state, 'comment.created', 'operator-2', ['thread_id'=>'one'], ['body'=>'Hello']);
	$t->same(1, $first->sequence());
	$t->same($first->hash(), $second->previousHash());
	$t->isTrue(PanelCollaborationStateEngine::verifyReceipts($state)['valid']);
	$state['receipts'][$first->id()]['payload']['title']='Tampered';
	$verification=PanelCollaborationStateEngine::verifyReceipts($state);
	$t->isFalse($verification['valid']);
	$t->same($first->id(), $verification['first_invalid']);

	$store=new PanelInMemoryCollaborationStore();
	$manager=new PanelCollaborationManager($store);
	$created=$manager->createThread('operator-1', 'task', 'guarded', 'Guarded');
	$receiptId=$created['receipt']['id'];
	$t->throws(static function() use ($store, $receiptId): void {
		$store->transaction(static function(array &$state) use ($receiptId): void {
			$state['receipts'][$receiptId]['payload']['title']='Mutated';
		}, 'malicious.receipt.mutation');
	}, LogicException::class);
	$t->isTrue($manager->verifyReceipts()['valid']);
})->tag('panel', 'collaboration', 'integrity', 'production')->group('panel-production-runtime');

test('panel in-memory collaboration cursor feed preserves order and resets stale consumers', static function(Context $t): void {
	$store=new PanelInMemoryCollaborationStore(8);
	$manager=new PanelCollaborationManager($store);
	$manager->createThread('operator-1', 'task', 'one', '', [], 'task-one');
	for($index=1; $index<=10; $index++){
		$manager->comment('task-one', 'operator-1', 'Update '.$index);
	}
	$t->same(11, $manager->cursor());
	$t->isTrue($manager->changesSince(1)['reset_required']);
	$fresh=$manager->changesSince(9);
	$t->same([10, 11], array_column($fresh['changes'], 'cursor'));
	$t->same(['collaboration.comment.created', 'collaboration.comment.created'], array_column($fresh['changes'], 'type'));
	$t->isTrue(is_array($manager->changesSince(1)['snapshot']));
})->tag('panel', 'collaboration', 'cursor', 'production')->group('panel-production-runtime');
