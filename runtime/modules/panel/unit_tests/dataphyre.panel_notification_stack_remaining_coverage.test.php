<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelInboxNotification;
use Dataphyre\Panel\PanelInMemoryNotificationAdapter;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelNotificationInbox;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel notification values cover aliases fluent metadata serialization and inbox conversion', static function(Context $t): void {
	$t->same('success', PanelNotification::success('Saved')->type());
	$t->same('error', PanelNotification::error('Failed')->type());
	$t->same('error', PanelNotification::danger('Danger')->type());
	$t->same('warning', PanelNotification::warning('Warning')->type());
	$t->same('info', PanelNotification::info('Info')->type());
	$t->same('info', PanelNotification::make('Unknown', 'unsupported')->type());

	$notification=PanelNotification::fromArray([
		'type'=>'danger',
		'message'=>' Ready ',
		'title'=>' Original ',
		'url'=>'/legacy',
		'duration_ms'=>70000,
		'persistent'=>true,
		'icon'=>'bell',
		'meta'=>['source'=>'fixture'],
	]);
	$notification=$notification
		->titleText(' Updated ')
		->url('/orders')
		->url('/orders/1', 'View')
		->duration(70000)
		->persistent(false)
		->icon(' check ')
		->action(' Open ', ' /open ')
		->meta(['priority'=>'high']);
	$t->same('Ready', $notification->message());
	$t->same('Updated', $notification->title());
	$t->same('high', $notification->metaData()['priority'] ?? null);
	$payload=$notification->jsonSerialize();
	$t->same('Open', $payload['action_label'] ?? null);
	$t->same('/open', $payload['action_url'] ?? null);
	$t->same(60000, $payload['duration_ms'] ?? null);
	$t->isFalse($payload['persistent'] ?? true);
	$t->same('check', $payload['icon'] ?? null);

	$inbox=$notification->inbox(' operator ', ['ticket'=>7]);
	$t->instanceOf(PanelInboxNotification::class, $inbox);
	$t->same('operator', $inbox->recipient());
	$t->same(7, $inbox->toArray()['meta']['ticket'] ?? null);
	$t->same(null, PanelNotification::info('No title')->titleText(' ')->title());
})->tag('panel', 'notifications', 'deep-coverage')->group('framework-coverage');

test('panel inbox notification covers source normalization accessors lifecycle and channel fallbacks', static function(Context $t): void {
	$fromObject=PanelInboxNotification::from(
		PanelNotification::success('Object message', 'Object title')->action('Open', '/object'),
		'operator',
		['source'=>'object']
	);
	$t->same('Object title', $fromObject->title());
	$t->same('Object message', $fromObject->message());
	$t->same('database', $fromObject->channel());
	$t->same(['database'], $fromObject->channels());

	$fromArray=PanelInboxNotification::from([
		'id'=>'array-id',
		'type'=>'danger',
		'message'=>' Array message ',
		'channels'=>[' Mail ', 'mail', '', 'database'],
		'created_at'=>'2026-01-01T00:00:00+00:00',
		'read_at'=>'2026-01-02T00:00:00+00:00',
		'dismissed_at'=>'2026-01-03T00:00:00+00:00',
		'meta'=>'invalid',
	]);
	$t->same('error', $fromArray->type());
	$t->same('mail', $fromArray->channel());
	$t->same(['mail', 'database'], $fromArray->channels());
	$t->same('2026-01-03T00:00:00+00:00', $fromArray->dismissedAt());
	$t->isTrue($fromArray->isRead());
	$t->isTrue($fromArray->isDismissed());
	$t->same($fromArray->toArray(), $fromArray->jsonSerialize());

	$fromArray->markUnread();
	$t->isTrue($fromArray->isUnread());
	$fromArray->restore();
	$t->isFalse($fromArray->isDismissed());
	$fromArray->markRead(' 2026-02-01T00:00:00+00:00 ')->dismiss(' 2026-02-02T00:00:00+00:00 ');
	$t->same('2026-02-01T00:00:00+00:00', $fromArray->readAt());
	$t->same('2026-02-02T00:00:00+00:00', $fromArray->dismissedAt());
	$fromArray->meta(['array'=>true])->meta('scalar', 9);
	$t->same(9, $fromArray->toArray()['meta']['scalar'] ?? null);

	$fromString=PanelInboxNotification::from('String message', ' ', ['source'=>'string']);
	$t->same('String message', $fromString->message());
	$t->same(null, $fromString->recipient());
	$defaults=new PanelInboxNotification(['type'=>'unsupported', 'message'=>'Default', 'channels'=>[null, '']]);
	$t->same('info', $defaults->type());
	$t->same(['database'], $defaults->channels());
})->tag('panel', 'notifications', 'deep-coverage')->group('framework-coverage');

test('panel memory adapter and inbox wrapper cover missing ids transitions delivery counts and manifests', static function(Context $t): void {
	$global=new PanelInboxNotification([
		'id'=>'global', 'message'=>'Global', 'type'=>'info',
		'created_at'=>'2026-01-01T00:00:00+00:00', 'channels'=>['database'],
	]);
	$operator=new PanelInboxNotification([
		'id'=>'operator', 'message'=>'Operator', 'type'=>'warning', 'recipient'=>'operator',
		'created_at'=>'2026-01-02T00:00:00+00:00', 'channels'=>['mail'],
	]);
	$adapter=PanelInMemoryNotificationAdapter::make([$global, $operator], [' Mail ', 'mail', 'database']);
	$t->same($operator, $adapter->get('operator'));
	$t->same(null, $adapter->get('missing'));
	$t->same(2, count($adapter->all()));
	$t->same(2, count($adapter->unread()));
	$t->same(0, count($adapter->read()));
	$t->same(1, count($adapter->forRecipient(' operator ')));
	$t->same(1, count($adapter->forRecipient(null)));

	$t->isFalse($adapter->markRead('missing'));
	$t->isTrue($adapter->markRead('operator', '2026-02-01T00:00:00+00:00'));
	$t->same(1, count($adapter->read()));
	$t->isFalse($adapter->markUnread('missing'));
	$t->isTrue($adapter->markUnread('operator'));
	$t->isFalse($adapter->dismiss('missing'));
	$t->isTrue($adapter->dismiss('operator', '2026-02-02T00:00:00+00:00'));
	$t->same(1, count($adapter->all()));
	$t->same(2, count($adapter->all(true)));
	$t->isFalse($adapter->restore('missing'));
	$t->isTrue($adapter->restore('operator'));

	$t->same([], $adapter->deliver('missing'));
	$fallbackReceipts=$adapter->deliver($operator, []);
	$t->same(['mail', 'database'], array_column($fallbackReceipts, 'channel'));
	$t->same(['push'], array_column($adapter->deliver('global', ' Push '), 'channel'));
	$t->same(2, $adapter->counts(false, 'operator')['deliveries'] ?? null);
	$adapter->meta(['suite'=>'notifications'])->meta('mode', 'memory');
	$adapterManifest=$adapter->manifest(['run'=>'focused']);
	$t->same('memory', $adapterManifest['adapter'] ?? null);
	$t->same('focused', $adapterManifest['meta']['run'] ?? null);

	$inbox=PanelNotificationInbox::using($adapter);
	$t->same($adapter, $inbox->adapter());
	$added=$inbox->add('Added', 'operator', ['source'=>'inbox']);
	$t->same($added, $inbox->get($added->id()));
	$t->same(2, count($inbox->forRecipient('operator')));
	$t->isTrue(count($inbox->unread())>=3);
	$t->isTrue($inbox->markRead($added->id(), '2026-03-01T00:00:00+00:00'));
	$t->same(1, count($inbox->read()));
	$t->isTrue($inbox->markUnread($added->id()));
	$t->isTrue($inbox->dismiss($added->id(), '2026-03-02T00:00:00+00:00'));
	$t->isTrue($inbox->restore($added->id()));
	$t->same(['database'], array_column($inbox->deliver($added, 'database'), 'channel'));
	$t->isTrue(($inbox->counts()['total'] ?? 0)>=3);
	$inbox->meta(['scope'=>'operator'])->meta('page', 1);
	$manifest=$inbox->manifest(['run'=>'focused']);
	$t->same('notification_inbox_manifest', $manifest['type'] ?? null);
	$t->same('focused', $manifest['meta']['run'] ?? null);
	$t->same($inbox->toArray(), $inbox->jsonSerialize());
	$t->instanceOf(PanelNotificationInbox::class, PanelNotificationInbox::make(['seed']));
})->tag('panel', 'notifications', 'deep-coverage')->group('framework-coverage');
