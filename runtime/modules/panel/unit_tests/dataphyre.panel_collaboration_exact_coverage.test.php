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

test('collaboration manager releases only matching leases and exposes complete public manifests', static function(Context $t): void {
	$memory=new PanelInMemoryCollaborationStore();
	$manager=(new PanelCollaborationManager($memory))->policy(static fn(): bool=>true);
	$thread=$manager->createThread('operator', 'order', 'SO-1', 'Review', [], 'review-thread');
	$t->same($thread['id'], $manager->thread('review-thread')['id']);
	$t->same(null, $manager->thread('missing-thread'));

	$lease=$manager->acquirePresence('order:SO-1', 'operator', 30);
	$t->isFalse($manager->releasePresence('order:SO-1', 'operator', 'wrong-token'));
	$t->isTrue($manager->releasePresence('order:SO-1', 'operator', $lease['lease_token']));
	$t->same([], $manager->presence('order:SO-1'));
	$t->same('panel_collaboration_manager', $manager->jsonSerialize()['type']);
	$t->same('panel_collaboration_store', $memory->jsonSerialize()['type']);

	$workspace=$t->workspace('panel-collaboration-exact-store');
	$filesystem=new PanelFilesystemCollaborationStore($workspace->root());
	$t->same('panel_collaboration_store', $filesystem->jsonSerialize()['type']);
})->tag('panel', 'collaboration', 'presence', 'manifests', 'exact-coverage')->maxMillis(2000);

test('collaboration policy failures retain their operation context without leaking callback errors', static function(Context $t): void {
	$expected=new PanelCollaborationPolicyException('thread.create', 'operator', 'Explicit denial.');
	$explicit=new PanelCollaborationManager(new PanelInMemoryCollaborationStore(), static function() use ($expected): never { throw $expected; });
	try {
		$explicit->createThread('operator', 'order', 1);
		$t->fail('The explicit collaboration policy exception should have been rethrown.');
	} catch(PanelCollaborationPolicyException $caught) {
		$t->same($expected, $caught);
	}

	$broken=new PanelCollaborationManager(new PanelInMemoryCollaborationStore(), static function(): never { throw new RuntimeException('policy backend offline'); });
	try {
		$broken->createThread('operator', 'order', 1);
		$t->fail('A broken policy callback should have been translated.');
	} catch(PanelCollaborationPolicyException $caught) {
		$t->contains('policy backend offline', $caught->getMessage());
		$t->same('thread.create', $caught->operation);
	}
})->tag('panel', 'collaboration', 'policy', 'exact-coverage')->maxMillis(1000);

test('collaboration receipts expose identity and reject every append-only chain violation', static function(Context $t): void {
	$state=PanelCollaborationStateEngine::initialState();
	$receipt=PanelCollaborationStateEngine::receipt($state, 'thread.created', 'operator', ['thread_id'=>'one'], ['title'=>'One']);
	$t->same('thread.created', $receipt->action());
	$t->same('operator', $receipt->actor());
	$t->same($receipt->toArray(), $receipt->jsonSerialize());

	$removedOrder=$state;
	$removedOrder['receipt_order']=[];
	$t->throws(static fn()=> PanelCollaborationStateEngine::assertReceiptAppendOnly($state, $removedOrder), LogicException::class);

	$reversedSequence=$state;
	$reversedSequence['receipt_sequence']=0;
	$t->throws(static fn()=> PanelCollaborationStateEngine::assertReceiptAppendOnly($state, $reversedSequence), LogicException::class);

	$invalidAppend=PanelCollaborationStateEngine::initialState();
	$appended=PanelCollaborationStateEngine::receipt($invalidAppend, 'comment.created', null, [], ['body'=>'Ready']);
	$invalidAppend['receipts'][$appended->id()]['hash']='tampered';
	$t->throws(static fn()=> PanelCollaborationStateEngine::assertReceiptAppendOnly(PanelCollaborationStateEngine::initialState(), $invalidAppend), LogicException::class);
})->tag('panel', 'collaboration', 'receipts', 'integrity', 'exact-coverage')->maxMillis(1000);

test('collaboration normalization skips invalid mentions and redacts every object shape safely', static function(Context $t): void {
	$t->throws(static fn()=> PanelCollaborationStateEngine::identifier("invalid\0actor", 'actor'), InvalidArgumentException::class);
	$t->same(['valid'], PanelCollaborationStateEngine::mentions(["invalid\0mention", 'valid', 'valid']));

	$json=new class implements JsonSerializable {
		public function jsonSerialize(): array { return ['visible'=>'yes', 'access_token'=>'hidden']; }
	};
	$stringable=new class implements Stringable {
		public function __toString(): string { return 'printable'; }
	};
	$t->same(['visible'=>'yes'], PanelCollaborationStateEngine::sanitize($json));
	$t->same('printable', PanelCollaborationStateEngine::sanitize($stringable));
	$t->same(null, PanelCollaborationStateEngine::sanitize(new stdClass()));
})->tag('panel', 'collaboration', 'normalization', 'redaction', 'exact-coverage')->maxMillis(1000);
