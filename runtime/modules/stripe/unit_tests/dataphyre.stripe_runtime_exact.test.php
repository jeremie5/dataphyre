<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/stripe_runtime_test_helpers.php';

suite('Stripe deterministic facade contract')
	->contract('stripe.runtime', 1)
	->layer('integration')
	->risk('high')
	->watches('module:stripe')
	->through('configuration', 'sdk-gateway', 'payment-methods', 'webhooks', 'schema')
	->isolation('case')
	->tag('stripe', 'runtime', 'exact-coverage')
	->group('framework-coverage');

test('module bootstrap exposes configuration and schema registration as observable boundaries', static function(Context $t): void {
	$t->same(['initialized'=>false,'table_registered'=>false], \dataphyre\stripe_bootstrap(false));
	$trace=$t->spy()->willReturn(null);
	$config=$t->spy()->willReturn(null);
	$table=$t->spy()->willReturn(null);
	$result=\dataphyre\stripe_bootstrap(true, [
		'trace'=>$trace,
		'define_config'=>$config,
		'define_table'=>$table,
		'stripe_runtime'=>['load'=>static fn(): bool=>false],
	]);
	$t->same(['initialized'=>true,'table_registered'=>true], $result);
	$trace->assertCalledTimes($t, 1);
	$config->assertCalledTimes($t, 1);
	$table->assertCalledWith($t, ['stripe_payment_methods', dirname(__DIR__).'/kernel/stripe.tables.php', 'payment_methods']);
});

test('key selection names live test and forced modes while platform state remains explicit', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$t->hasPathValues([
		'live.publishable'=>'pk_live_runtime',
		'live.secret'=>'fixture-live-secret',
		'live.webhook'=>'fixture-webhook-secret',
		'test.publishable'=>'pk_test_runtime',
		'test.secret'=>'sk_test_runtime',
		'override_secret'=>'sk_test_runtime',
	], $scenario->keySelectionContract());
	$t->hasPathValues([
		'before'=>'fixture-live-secret',
		'set'=>true,
		'after'=>'fixture-live-secret',
	], $scenario->platformAccountContract());
	$t->hasPathValues(['has_config'=>true,'missing_falls_back'=>false], $scenario->runtimeAndFallbackConfigContract());
	$t->isFalse($scenario->platformWithoutSecret());
});

test('process configuration remains a supported fallback when no runtime snapshot is injected', static function(Context $t): void {
	$t->same('fixture-process-webhook-secret', DpStripeRuntimeScenario::open($t)->processConfigWebhookSecret());
});

test('one operation catalog proves every facade method forwards its exact SDK intent', static function(Context $t): void {
	$catalog=DpStripeRuntimeScenario::open($t)->operationCatalog();
	$t->same([
		'balance.retrieve','customer.create','account.create','account.update',
		'account.create_external_account','account.update','account.update',
		'payment_intent.create','payment_intent.retrieve','payment_intent.retrieve',
		'account_link.create','account.retrieve','transfer.create','payout.create',
		'payment_intent.retrieve','payment_intent.retrieve','refund.create',
		'payment_method.retrieve','payment_method.retrieve','payment_intent.retrieve',
		'payment_intent.retrieve','payment_method.all','payment_method.retrieve',
	], $catalog['operation_names']);
	$t->same(['cancel','confirm','detach','capture','attach'], $catalog['action_names']);
	$t->hasPathValues([
		'status'=>'succeeded',
		'all_results_succeeded'=>true,
		'local_deletes'=>1,
	], $catalog);
});

test('payment method lifecycle names every rollback and success outcome without raw SQL setup', static function(Context $t): void {
	$t->hasPathValues([
		'duplicate.outcome'=>false,
		'bad_token.outcome'=>'bad_token',
		'customer_creation.outcome'=>'failed_customer_creation_callback',
		'insert_failure.outcome'=>'failed_creating_method',
		'attach_failure.outcome'=>false,
		'attach_failure.deletes'=>1,
		'card_declined.outcome'=>'card_declined',
		'card_declined.deletes'=>1,
		'update_failure.outcome'=>'failed_attaching',
		'update_failure.deletes'=>1,
		'success.outcome'=>true,
		'remote_unattached.outcome'=>true,
	], DpStripeRuntimeScenario::open($t)->paymentMethodLifecycleOutcomes());
});

test('gateway action refund and detach failures follow their documented fail-closed policies', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$t->hasPathValues([
		'remote'=>false,
		'missing_action'=>false,
		'declined'=>'card_declined',
		'over_refund'=>false,
		'delete_after_remote_failure'=>true,
		'deletes'=>1,
		'logged'=>true,
	], $scenario->failurePolicies());
	$t->hasPathValues([
		'missing_charge'=>false,
		'missing_charge_logged'=>true,
		'gateway_failure'=>false,
		'gateway_failure_logged'=>true,
	], $scenario->refundFailureContract());
	$t->same([
		'new_method'=>false,'refund'=>false,'delete'=>false,'remote'=>false,'action'=>false,
	], $scenario->unavailableOperationContract());
});

test('webhook policy authenticates object and array events and explains every rejection', static function(Context $t): void {
	$t->hasPathValues([
		'supported'=>'accepted:pi_supported',
		'unsupported'=>false,
		'unsupported_response.status'=>400,
		'unsupported_response.body'=>'Unsupported webhook event type: unknown.event',
		'invalid'=>false,
		'invalid_response.status'=>400,
		'invalid_response.body'=>'Webhook Error: bad signature',
		'invalid_verifier'=>false,
		'invalid_verifier_response.status'=>400,
		'invalid_verifier_response.body'=>'Webhook Error: Stripe webhook verifier must be callable.',
		'array_event'=>'array:evt_array',
		'global_callback'=>'global:evt_global',
		'platform_unavailable'=>false,
	], DpStripeRuntimeScenario::open($t)->webhookContract());
});

test('direct webhook emission and composable endpoint bootstrap remain independently testable', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$output=$t->captureOutput(static fn()=>$scenario->unsupportedWebhookWithDirectEmitter())->output();
	$t->same('Unsupported webhook event type: unsupported.direct', $output);
	$t->same(null, dataphyre_stripe_webhook_endpoint::bootstrap(false));
	$t->same('endpoint:evt_endpoint', $scenario->dispatchWebhookEndpoint());
	$t->throws(static fn()=>$scenario->emitThroughInvalidBoundary(), LogicException::class);
});

test('default SDK dispatch map is replaceable without replacing the operation gateway', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$t->same('mapped balance', $scenario->dispatchThroughSdkOperationMap());
	$t->isTrue($scenario->defaultOperationCatalogContainsBalance());
	$t->same([
		'invalid_executor'=>false,
		'unknown_operation'=>false,
		'missing_sdk_state'=>false,
	], $scenario->containedBoundaryFailures());
});

test('invalid facade boundaries fail loudly while SDK operation failures stay contained', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$t->throws(static fn()=>$scenario->loadThroughInvalidBoundary(), LogicException::class);
	$t->throws(static fn()=>$scenario->selectThroughInvalidBoundary(), LogicException::class);
	$t->throws(static fn()=>$scenario->readThroughInvalidApiKeyBoundary(), LogicException::class);
	$t->throws(static fn()=>$scenario->writeThroughInvalidApiKeyBoundary(), LogicException::class);
});

test('SDK initialization explains invalid loaders missing classes missing keys and retry seams', static function(Context $t): void {
	$t->same([
		'invalid_loader'=>false,
		'missing_class'=>false,
		'missing_key'=>false,
		'invalid_retries'=>false,
		'unavailable'=>4,
	], DpStripeRuntimeScenario::open($t)->sdkInitializationFailures());
});

test('bundled SDK loading configures the platform key without making a network request', static function(Context $t): void {
	$scenario=DpStripeRuntimeScenario::open($t);
	$t->isTrue($scenario->loadBundledSdk());
	$t->same('fixture-live-secret', $scenario->bundledApiKey());
	$t->same([], $scenario->unavailableCalls());
	$t->same('card_declined', $scenario->declineThroughBundledExceptionPolicy());
});

test('legacy Stripe SDK key property shapes remain compatible and fail closed without keys', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$legacy=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/stripe_legacy_api_key_probe.php',
		[dirname(__DIR__).'/kernel/stripe.main.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'loaded'=>true,'account'=>'sk_legacy','retries'=>3,'missing'=>false,'unavailable'=>1,
	], $legacy);
	$camel=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/stripe_camel_api_key_probe.php',
		[dirname(__DIR__).'/kernel/stripe.main.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues(['loaded'=>true,'account'=>'sk_camel','stored'=>'sk_camel'], $camel);
});

test('payment method schema preserves remote identity ownership state and lookup indexes', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/stripe.tables.php';
	$t->sameKeys(['payment_methods'], $manifest);
	$definition=$manifest['payment_methods']('stripe_payment_methods');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('stripe_payment_methods', $definition->table());
	$t->same(['id'], $definition->primaryColumns());
	$t->same(['id','brand','type','userid','is_attached','is_main','country','last_four_digits','postal_code','expiration_month','expiration_year','name_on_card'], $definition->columns());
});
