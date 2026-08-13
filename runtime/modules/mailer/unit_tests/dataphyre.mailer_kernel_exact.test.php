<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/mailer_kernel_facade_fixture.php';
require_once dirname(__DIR__).'/kernel/mailer.main.php';
require_once dirname(__DIR__).'/kernel/mailer.scheduler.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

/** Runs every compatibility operation through its public legacy name. */
final class MailerKernelOperations {
	/** @return array<string,mixed> */
	public static function exercise(): array {
		$message=['to'=>'buyer@example.test', 'subject'=>'Receipt', 'text'=>'Thank you'];
		return [
			'send'=>\dataphyre\mailer_send($message, 'fixture', ['tag'=>'order']),
			'batch'=>\dataphyre\mailer_send_batch([$message, [...$message, 'subject'=>'Shipment']], 'fixture'),
			'queue'=>\dataphyre\mailer_queue($message, 'fixture', ['priority'=>5]),
			'flush'=>\dataphyre\mailer::flush(7),
			'render'=>\dataphyre\mailer::render('receipt', ['name'=>'Ada'], ['subject'=>'Receipt']),
			'outbox'=>\dataphyre\mailer_outbox_summary(),
			'prune'=>\dataphyre\mailer_prune(['limit'=>10]),
			'campaign'=>\dataphyre\mailer_campaign_summary(['campaign'=>'orders']),
			'suppress'=>\dataphyre\mailer_suppress('buyer@example.test', 'bounce', ['source'=>'provider']),
			'unsuppress'=>\dataphyre\mailer_unsuppress('buyer@example.test'),
			'is_suppressed'=>\dataphyre\mailer_is_suppressed('buyer@example.test'),
			'event'=>\dataphyre\mailer_ingest_delivery_event('fixture', ['event'=>'delivered'], 'delivered'),
			'events'=>\dataphyre\mailer_ingest_delivery_events('fixture', [['event'=>'open']], 'open'),
			'webhook'=>\dataphyre\mailer_ingest_delivery_webhook('fixture', '{"event":"click"}', ['x-signature'=>'ok'], 'click'),
			'health'=>\dataphyre\mailer_health(12),
			'trace'=>\dataphyre\mailer_trace('message-71'),
		];
	}
}

suite('Mailer kernel compatibility boundary')
	->contract('mailer.kernel-compatibility', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:mailer')
	->through('configuration', 'facade-adapter', 'legacy-functions', 'scheduler', 'schema')
	->isolation('case')
	->tag('mailer', 'kernel', 'exact-coverage')
	->group('framework-coverage');

test('configuration and table registration expose literal dotted and configured names', static function(Context $t): void {
	$t->same(DP_MAILER_CFG, \dataphyre\mailer::config());
	$t->same('literal-value', \dataphyre\mailer::config('literal.key'));
	$t->same('nested-value', \dataphyre\mailer::config('nested.value'));
	$t->same('fallback', \dataphyre\mailer::config('nested.missing', 'fallback'));
	$t->same('fallback', \dataphyre\mailer::config('scalar.child', 'fallback'));
	$t->same([
		'outbox'=>'fixture.mailer_outbox',
		'events'=>'fixture.mailer_events',
		'suppressions'=>'fixture.mailer_suppressions',
		'webhook_events'=>'fixture.mailer_webhook_events',
	], \dataphyre\mailer::register_tables($t->spy(), DP_MAILER_CFG));
	$t->same(4, count(DpMailerKernelFixtureState::tables()));
});

test('every compatibility operation returns its documented fallback from one unavailable boundary', static function(Context $t): void {
	DpMailerKernelFixtureState::resetFacade(false);
	$results=MailerKernelOperations::exercise();
	$t->isFalse($results['send']['ok']);
	$t->same(500, $results['queue']['status']);
	$t->same(0, $results['flush']['processed']);
	$t->same('', $results['render']['html']);
	$t->same([], $results['outbox']['statuses']);
	$t->same([], $results['campaign']['events']);
	$t->isFalse($results['suppress']);
	$t->same(0, $results['events']['processed']);
	$t->same('message-71', $results['trace']['message_id']);
	$t->same([], \Dataphyre\Mailer\Mailer::calledMethods());
});

test('every compatibility operation delegates to the camel-case framework facade with normalized result shapes', static function(Context $t): void {
	DpMailerKernelFixtureState::resetFacade(true);
	$results=MailerKernelOperations::exercise();
	$t->same('send', $results['send']['method']);
	$t->same(2, count($results['batch']));
	$t->same('sendBatch', $results['batch'][0]['method']);
	$t->same('queue', $results['queue']['method']);
	$t->isTrue($results['suppress']);
	$t->isTrue($results['unsuppress']);
	$t->isTrue($results['is_suppressed']);
	$t->same([
		'send', 'sendBatch', 'queue', 'flush', 'render', 'outboxSummary', 'prune', 'campaignSummary',
		'suppress', 'unsuppress', 'isSuppressed', 'ingestDeliveryEvent', 'ingestDeliveryEvents',
		'ingestDeliveryWebhook', 'health', 'trace',
	], \Dataphyre\Mailer\Mailer::calledMethods());
});

test('scheduler registration names disabled dependency missing file and successful outcomes', static function(Context $t): void {
	$t->isFalse(\dataphyre\mailer::schedule(['enabled'=>false]));
	$t->isFalse(\dataphyre\mailer::schedule(['enabled'=>true], static fn(): bool=>false));
	$t->isFalse(\dataphyre\mailer::schedule(
		['enabled'=>true],
		static fn(): bool=>true,
		static fn(): bool=>false
	));
	$run=$t->spy()->willReturn(true);
	$t->isTrue(\dataphyre\mailer::schedule(
		['enabled'=>true],
		static fn(): bool=>true,
		static fn(): bool=>true,
		$run
	));
	$arguments=$run->lastCall();
	$t->same('dataphyre_mailer_outbox', $arguments[0]);
	$t->same(60.0, $arguments[2]);
	$t->same(300.0, $arguments[3]);
	$t->same('128M', $arguments[4]);
});

test('scheduler runner clamps batches and reports optional pruning without script globals', static function(Context $t): void {
	$flush=$t->spy()->willReturn(['ok'=>true, 'processed'=>1]);
	$prune=$t->spy()->willReturn(['ok'=>true, 'deleted'=>3]);
	$log=$t->spy();
	$withoutPrune=\dataphyre\mailer_scheduler::run(['batch_size'=>-10], $flush, $prune, $log);
	$flush->assertCalledWith($t, [1]);
	$t->same(null, $withoutPrune['prune']);
	$prune->assertCalledTimes($t, 0);

	$withPrune=\dataphyre\mailer_scheduler::run([
		'batch_size'=>500,
		'prune'=>['enabled'=>true, 'options'=>['limit'=>20]],
	], $flush, $prune, $log);
	$flush->assertCalledWith($t, [250]);
	$prune->assertCalledWith($t, [['limit'=>20]]);
	$log->assertCalledTimes($t, 1);
	$t->same(3, $withPrune['prune']['deleted']);

	\dataphyre\mailer_scheduler::run([
		'batch_size'=>25,
		'prune'=>['enabled'=>true, 'options'=>'invalid'],
	], $flush, $prune, null);
	$prune->assertCalledWith($t, [[]]);
});

test('all mailer table manifests instantiate stable primary-key contracts', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/mailer.tables.php';
	$t->sameKeys(['outbox', 'events', 'suppressions', 'webhook_events'], $manifest);
	foreach([
		'outbox'=>['id'],
		'events'=>['id'],
		'suppressions'=>['id'],
		'webhook_events'=>['event_hash'],
	] as $name=>$primary){
		$definition=$manifest[$name]('fixture.'.$name);
		$t->instanceOf(TableDefinition::class, $definition);
		$t->same($primary, $definition->primaryColumns());
	}
});
