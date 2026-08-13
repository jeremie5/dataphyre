<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/../kernel/stripe.account_client.php';

final class dp_stripe_account_fake_resource {
	/** @param array<string,mixed> $resource */
	public function __construct(private array $resource) {}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return $this->resource;
	}
}

final class dp_stripe_account_fake_service {
	/** @var array<int, array{method:string,arguments:array<int,mixed>}> */
	public array $calls=[];

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function create(array $params, array $options=[]): array {
		$this->calls[]=['method'=>'create', 'arguments'=>[$params, $options]];
		return ['method'=>'create', 'params'=>$params, 'options'=>$options];
	}

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function update(string $id, array $params, array $options=[]): array {
		$this->calls[]=['method'=>'update', 'arguments'=>[$id, $params, $options]];
		return ['method'=>'update', 'id'=>$id, 'params'=>$params, 'options'=>$options];
	}

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function retrieve(string $id, array $params=[], array $options=[]): array {
		$this->calls[]=['method'=>'retrieve', 'arguments'=>[$id, $params, $options]];
		return ['method'=>'retrieve', 'id'=>$id, 'params'=>$params, 'options'=>$options];
	}

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function cancel(string $id, array $params=[], array $options=[]): array {
		$this->calls[]=['method'=>'cancel', 'arguments'=>[$id, $params, $options]];
		return [
			'id'=>$id,
			'status'=>'canceled',
			'customer'=>'cus_1234567890',
			'payment_method'=>'pm_1234567890',
			'cancellation_reason'=>$params['cancellation_reason'] ?? null,
			'client_secret'=>'seti_secret_must_not_escape',
		];
	}

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function detach(string $id, array $params=[], array $options=[]): array {
		$this->calls[]=['method'=>'detach', 'arguments'=>[$id, $params, $options]];
		return ['id'=>$id, 'customer'=>null, 'billing_details'=>['email'=>'must-not-escape@example.test']];
	}

	/** @param array<string,mixed> $params @param array<string,mixed> $options */
	public function delete(string $id, array $params=[], array $options=[]): array {
		$this->calls[]=['method'=>'delete', 'arguments'=>[$id, $params, $options]];
		return ['id'=>$id, 'deleted'=>true, 'metadata'=>['private'=>'must-not-escape']];
	}
}

final class dp_stripe_account_fake_client {
	public object $customers;
	public object $setupIntents;
	public object $paymentMethods;
	public object $paymentIntents;

	public function __construct() {
		$this->customers=new dp_stripe_account_fake_service();
		$this->setupIntents=new dp_stripe_account_fake_service();
		$this->paymentMethods=new dp_stripe_account_fake_service();
		$this->paymentIntents=new dp_stripe_account_fake_service();
	}
}

final class dp_stripe_account_fake_service_factory_client {
	private dp_stripe_account_fake_service $customer_service;

	public function __construct() {
		$this->customer_service=new dp_stripe_account_fake_service();
	}

	public function getService(string $name): object {
		if($name!=='customers'){
			throw new RuntimeException('Unexpected fake Stripe service.');
		}
		return $this->customer_service;
	}

	public function customerService(): dp_stripe_account_fake_service {
		return $this->customer_service;
	}
}

function dp_stripe_unit_account_key(string $type='sk', string $mode='test'): string {
	return $type.'_'.$mode.'_'.str_repeat('a', 24);
}

function dp_stripe_unit_webhook_secret(): string {
	return 'whsec_'.str_repeat('b', 24);
}

function dp_stripe_unit_signature(int $timestamp, string $payload, string $secret): string {
	return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
}

test('Stripe account client fails closed on missing malformed and publishable keys', static function(Context $t): void {
	foreach([
		'',
		'   ',
		'pk_test_'.str_repeat('a', 24),
		'sk_test_short',
		'sk_stage_'.str_repeat('a', 24),
		'sk_live_'.str_repeat('-', 24),
		'rk_test_'.str_repeat('a', 15),
	] as $invalid){
		$t->throws(static fn()=>new \dataphyre\stripe_account_client($invalid), InvalidArgumentException::class);
	}
	$t->throws(
		static fn()=>new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), static fn()=>null, new stdClass()),
		InvalidArgumentException::class,
	);
})->tag('stripe','billing','security','framework');

test('Stripe account readiness is local redacted and classifies secret and restricted keys', static function(Context $t): void {
	$local=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key());
	$localReadiness=$local->readiness();
	$t->isTrue($localReadiness['ready']);
	$t->isTrue($localReadiness['sdk_available']);
	$t->isFalse($localReadiness['executor_injected']);
	$t->isFalse($localReadiness['client_injected']);
	$localInternals=$t->nonPublic($local);
	$t->isTrue($localInternals->invoke('sdk_available', static fn(string $class): bool=>false, static fn(string $path): bool=>true));
	$t->isFalse($localInternals->invoke('sdk_available', static fn(string $class): bool=>false, static fn(string $path): bool=>false));

	foreach([
		['sk','test','secret'],
		['sk','live','secret'],
		['rk','test','restricted'],
		['rk','live','restricted'],
	] as [$type,$mode,$keyType]){
		$key=dp_stripe_unit_account_key($type, $mode);
		$client=new \dataphyre\stripe_account_client($key, static fn()=>null);
		$readiness=$client->readiness();
		$t->isTrue($readiness['ready']);
		$t->isTrue($readiness['configured']);
		$t->isTrue($readiness['valid']);
		$t->same($mode, $readiness['mode']);
		$t->same($keyType, $readiness['key_type']);
		$t->isTrue($readiness['sdk_available']);
		$t->isFalse($readiness['network_checked']);
		$t->isTrue($readiness['executor_injected']);
		$t->isFalse($readiness['client_injected']);
		$t->notContains($key, json_encode($readiness, JSON_THROW_ON_ERROR));
	}
})->tag('stripe','billing','readiness','framework');

test('Stripe account executor receives every operation arguments and isolated request options', static function(Context $t): void {
	$calls=[];
	$executor=static function(string $operation, array $arguments, array $options) use (&$calls): array {
		$calls[]=['operation'=>$operation, 'arguments'=>$arguments, 'options'=>$options];
		return match($operation){
			'customers.delete'=>['id'=>$arguments[0], 'deleted'=>true, 'email'=>'must-not-escape@example.test'],
			'setup_intents.cancel'=>[
				'id'=>$arguments[0], 'status'=>'canceled', 'customer'=>'cus_1234567890',
				'payment_method'=>'pm_1234567890', 'cancellation_reason'=>$arguments[1]['cancellation_reason'] ?? null,
				'client_secret'=>'must_not_escape',
			],
			'payment_methods.detach'=>['id'=>$arguments[0], 'customer'=>null, 'billing_details'=>['email'=>'must-not-escape@example.test']],
			default=>['operation'=>$operation],
		};
	};
	$client=new \dataphyre\stripe_account_client(
		dp_stripe_unit_account_key(),
		$executor,
		null,
		['stripe_account'=>'acct_'.str_repeat('c', 12), 'stripe_version'=>'2025-06-30.basil'],
	);

	$t->same('customers.create', $client->create_customer(['email'=>'billing@example.test'], 'customer-create-1')['operation']);
	$t->same('customers.update', $client->update_customer('cus_1234567890', ['name'=>'Billing'], 'customer-update-1')['operation']);
	$t->same('customers.retrieve', $client->retrieve_customer('cus_1234567890')['operation']);
	$t->same(['id'=>'cus_1234567890', 'deleted'=>true], $client->delete_customer('cus_1234567890', 'customer-delete-1'));
	$t->same('setup_intents.create', $client->create_setup_intent(['customer'=>'cus_1234567890'], 'setup-create-1')['operation']);
	$t->same('setup_intents.retrieve', $client->retrieve_setup_intent('seti_1234567890')['operation']);
	$t->same([
		'id'=>'seti_1234567890', 'status'=>'canceled', 'customer'=>'cus_1234567890',
		'payment_method'=>'pm_1234567890', 'cancellation_reason'=>'abandoned',
	], $client->cancel_setup_intent('seti_1234567890', ['cancellation_reason'=>'abandoned'], 'setup-cancel-1'));
	$t->same('payment_methods.retrieve', $client->retrieve_payment_method('pm_1234567890')['operation']);
	$t->same(['id'=>'pm_1234567890', 'customer'=>null], $client->detach_payment_method('pm_1234567890', 'payment-method-detach-1'));
	$t->same('payment_intents.create', $client->create_payment_intent([
		'amount'=>1299,
		'currency'=>'cad',
		'customer'=>'cus_1234567890',
		'payment_method'=>'pm_1234567890',
	], 'payment-create-1')['operation']);
	$t->same('payment_intents.retrieve', $client->retrieve_payment_intent('pi_1234567890')['operation']);
	$payload='{"id":"evt_executor","object":"event","type":"customer.updated","data":{"object":{}}}';
	$secret=dp_stripe_unit_webhook_secret();
	$signature=dp_stripe_unit_signature(time(), $payload, $secret);
	$t->same('webhooks.construct_event', $client->construct_webhook_event($payload, $signature, $secret)['operation']);

	$t->count(12, $calls);
	$t->same([
		'customers.create',
		'customers.update',
		'customers.retrieve',
		'customers.delete',
		'setup_intents.create',
		'setup_intents.retrieve',
		'setup_intents.cancel',
		'payment_methods.retrieve',
		'payment_methods.detach',
		'payment_intents.create',
		'payment_intents.retrieve',
		'webhooks.construct_event',
	], array_column($calls, 'operation'));
	$t->same('customer-create-1', $calls[0]['options']['idempotency_key']);
	$t->same('customer-update-1', $calls[1]['options']['idempotency_key']);
	$t->same('customer-delete-1', $calls[3]['options']['idempotency_key']);
	$t->same('setup-create-1', $calls[4]['options']['idempotency_key']);
	$t->same('setup-cancel-1', $calls[6]['options']['idempotency_key']);
	$t->same('payment-method-detach-1', $calls[8]['options']['idempotency_key']);
	$t->same('payment-create-1', $calls[9]['options']['idempotency_key']);
	$t->same('acct_'.str_repeat('c', 12), $calls[3]['options']['stripe_account']);
	$t->same('acct_'.str_repeat('c', 12), $calls[6]['options']['stripe_account']);
	$t->same('acct_'.str_repeat('c', 12), $calls[8]['options']['stripe_account']);
	$t->same('2025-06-30.basil', $calls[9]['options']['stripe_version']);
	$t->same('off_session', $calls[4]['arguments'][0]['usage']);
	$t->isTrue($calls[9]['arguments'][0]['off_session']);
	$t->isTrue($calls[9]['arguments'][0]['confirm']);
	$t->same([
		'stripe_account'=>'acct_'.str_repeat('c', 12),
		'stripe_version'=>'2025-06-30.basil',
	], $calls[2]['options']);
	$t->same([], $calls[11]['options']);
})->tag('stripe','billing','executor','idempotency','framework');

test('Stripe account request options and idempotency keys reject unsafe overrides', static function(Context $t): void {
	$key=dp_stripe_unit_account_key();
	$executor=static fn()=>null;
	foreach([
		['api_key'=>dp_stripe_unit_account_key('sk','live')],
		['unknown'=>'value'],
		['stripe_account'=>'bad'],
		['stripe_account'=>'acct_'.str_repeat('a', 251)],
		['stripe_version'=>''],
		['stripe_version'=>"2025-01-01\nInjected"],
		['idempotency_key'=>42],
		['idempotency_key'=>''],
		['idempotency_key'=>' leading-space'],
		['idempotency_key'=>'trailing-space '],
	] as $options){
		$t->throws(static fn()=>new \dataphyre\stripe_account_client($key, $executor, null, $options), InvalidArgumentException::class);
	}

	$calls=0;
	$client=new \dataphyre\stripe_account_client($key, static function() use (&$calls): void { $calls++; });
	foreach([str_repeat('x', 256), "bad\nkey", "bad\0key", ' surrounding '] as $invalid){
		$t->throws(static fn()=>$client->create_customer([], $invalid), InvalidArgumentException::class);
	}
	$t->throws(static fn()=>$client->delete_customer('cus_1234567890', ''), InvalidArgumentException::class);
	$t->throws(static fn()=>$client->cancel_setup_intent('seti_1234567890', [], ''), InvalidArgumentException::class);
	$t->throws(static fn()=>$client->detach_payment_method('pm_1234567890', ''), InvalidArgumentException::class);
	$t->same(0, $calls);
})->tag('stripe','billing','request-options','security','framework');

test('Stripe account identifiers and off-session invariants fail before execution', static function(Context $t): void {
	$calls=0;
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), static function() use (&$calls): void { $calls++; });
	foreach([
		static fn()=>$client->update_customer('customer_1', []),
		static fn()=>$client->retrieve_customer('cus_bad!'),
		static fn()=>$client->retrieve_setup_intent('si_1234567890'),
		static fn()=>$client->retrieve_payment_method('card_1234567890'),
		static fn()=>$client->retrieve_payment_intent('intent_1234567890'),
		static fn()=>$client->delete_customer('cus_'.str_repeat('a', 252), 'delete-key'),
		static fn()=>$client->cancel_setup_intent('seti_'.str_repeat('a', 251), [], 'cancel-key'),
		static fn()=>$client->detach_payment_method('pm_'.str_repeat('a', 253), 'detach-key'),
		static fn()=>$client->cancel_setup_intent('seti_1234567890', ['expand'=>['customer']], 'cancel-key'),
		static fn()=>$client->cancel_setup_intent('seti_1234567890', ['cancellation_reason'=>'fraudulent'], 'cancel-key'),
		static fn()=>$client->cancel_setup_intent('seti_1234567890', ['cancellation_reason'=>['abandoned']], 'cancel-key'),
		static fn()=>$client->create_payment_intent(['off_session'=>false]),
		static fn()=>$client->create_payment_intent(['confirm'=>false]),
	] as $operation){
		$t->throws($operation, InvalidArgumentException::class);
	}
	$t->same(0, $calls);
})->tag('stripe','billing','validation','security','framework');

test('Stripe account injected client dispatches exact SDK service call shapes without network', static function(Context $t): void {
	$fake=new dp_stripe_account_fake_client();
	$client=new \dataphyre\stripe_account_client(
		dp_stripe_unit_account_key(),
		null,
		$fake,
		['stripe_account'=>'acct_1234567890'],
	);
	$readiness=$client->readiness();
	$t->isTrue($readiness['client_injected']);
	$t->isFalse($readiness['executor_injected']);

	$t->same('create', $client->create_customer(['email'=>'owner@example.test'], 'create-customer')['method']);
	$t->same('update', $client->update_customer('cus_1234567890', ['name'=>'Owner'], 'update-customer')['method']);
	$t->same('retrieve', $client->retrieve_customer('cus_1234567890')['method']);
	$t->same(['id'=>'cus_1234567890', 'deleted'=>true], $client->delete_customer('cus_1234567890', 'delete-customer'));
	$t->same('create', $client->create_setup_intent(['usage'=>'on_session'], 'create-setup')['method']);
	$t->same('retrieve', $client->retrieve_setup_intent('seti_1234567890')['method']);
	$t->same([
		'id'=>'seti_1234567890', 'status'=>'canceled', 'customer'=>'cus_1234567890',
		'payment_method'=>'pm_1234567890', 'cancellation_reason'=>'requested_by_customer',
	], $client->cancel_setup_intent(
		'seti_1234567890',
		['cancellation_reason'=>'requested_by_customer'],
		'cancel-setup',
	));
	$t->same('retrieve', $client->retrieve_payment_method('pm_1234567890')['method']);
	$t->same(['id'=>'pm_1234567890', 'customer'=>null], $client->detach_payment_method('pm_1234567890', 'detach-payment-method'));
	$t->same('create', $client->create_payment_intent(['amount'=>500, 'currency'=>'cad'], 'create-payment')['method']);
	$t->same('retrieve', $client->retrieve_payment_intent('pi_1234567890')['method']);

	$t->same(['cus_1234567890', [], ['stripe_account'=>'acct_1234567890']], $fake->customers->calls[2]['arguments']);
	$t->same(['cus_1234567890', [], [
		'stripe_account'=>'acct_1234567890', 'idempotency_key'=>'delete-customer',
	]], $fake->customers->calls[3]['arguments']);
	$t->same('on_session', $fake->setupIntents->calls[0]['arguments'][0]['usage']);
	$t->same(['seti_1234567890', ['cancellation_reason'=>'requested_by_customer'], [
		'stripe_account'=>'acct_1234567890', 'idempotency_key'=>'cancel-setup',
	]], $fake->setupIntents->calls[2]['arguments']);
	$t->same(['pm_1234567890', [], [
		'stripe_account'=>'acct_1234567890', 'idempotency_key'=>'detach-payment-method',
	]], $fake->paymentMethods->calls[1]['arguments']);
	$t->isTrue($fake->paymentIntents->calls[0]['arguments'][0]['off_session']);
	$t->isTrue($fake->paymentIntents->calls[0]['arguments'][0]['confirm']);
	$t->same('create-payment', $fake->paymentIntents->calls[0]['arguments'][1]['idempotency_key']);
})->tag('stripe','billing','client-seam','framework');

test('Stripe account cleanup responses are exact sanitized evidence and provider replays stay provider-owned', static function(Context $t): void {
	$calls=[];
	$executor=static function(string $operation, array $arguments, array $options) use (&$calls): object {
		$calls[]=['operation'=>$operation, 'arguments'=>$arguments, 'options'=>$options];
		return new dp_stripe_account_fake_resource(match($operation){
			'customers.delete'=>[
				'id'=>$arguments[0], 'deleted'=>true, 'object'=>'customer',
				'email'=>'owner-private@example.test', 'metadata'=>['secret'=>'must-not-escape'],
			],
			'setup_intents.cancel'=>[
				'id'=>$arguments[0], 'status'=>'canceled',
				'customer'=>new dp_stripe_account_fake_resource(['id'=>'cus_1234567890', 'email'=>'must-not-escape@example.test']),
				'payment_method'=>new dp_stripe_account_fake_resource(['id'=>'pm_1234567890', 'billing_details'=>['email'=>'must-not-escape@example.test']]),
				'cancellation_reason'=>$arguments[1]['cancellation_reason'] ?? null,
				'client_secret'=>'seti_secret_must_not_escape',
			],
			'payment_methods.detach'=>[
				'id'=>$arguments[0], 'customer'=>null, 'card'=>['last4'=>'4242'],
				'billing_details'=>['email'=>'must-not-escape@example.test'],
			],
			default=>throw new RuntimeException('Unexpected cleanup operation.'),
		});
	};
	$client=new \dataphyre\stripe_account_client(
		dp_stripe_unit_account_key(),
		$executor,
		null,
		['stripe_account'=>'acct_1234567890'],
	);

	$delete=['id'=>'cus_1234567890', 'deleted'=>true];
	$cancel=[
		'id'=>'seti_1234567890', 'status'=>'canceled', 'customer'=>'cus_1234567890',
		'payment_method'=>'pm_1234567890', 'cancellation_reason'=>'abandoned',
	];
	$detach=['id'=>'pm_1234567890', 'customer'=>null];
	foreach([1,2] as $_){
		$t->same($delete, $client->delete_customer('cus_1234567890', 'stable-delete-key'));
		$t->same($cancel, $client->cancel_setup_intent(
			'seti_1234567890',
			['cancellation_reason'=>'abandoned'],
			'stable-cancel-key',
		));
		$t->same($detach, $client->detach_payment_method('pm_1234567890', 'stable-detach-key'));
	}

	$t->count(6, $calls);
	$t->same([
		'customers.delete', 'setup_intents.cancel', 'payment_methods.detach',
		'customers.delete', 'setup_intents.cancel', 'payment_methods.detach',
	], array_column($calls, 'operation'));
	$t->same('stable-delete-key', $calls[0]['options']['idempotency_key']);
	$t->same('stable-delete-key', $calls[3]['options']['idempotency_key']);
	$t->same('stable-cancel-key', $calls[1]['options']['idempotency_key']);
	$t->same('stable-cancel-key', $calls[4]['options']['idempotency_key']);
	$t->same('stable-detach-key', $calls[2]['options']['idempotency_key']);
	$t->same('stable-detach-key', $calls[5]['options']['idempotency_key']);
	foreach($calls as $call){
		$t->same('acct_1234567890', $call['options']['stripe_account']);
	}
	$encoded=json_encode([$delete,$cancel,$detach], JSON_THROW_ON_ERROR);
	$t->notContains('secret', $encoded);
	$t->notContains('email', $encoded);
	$t->notContains('last4', $encoded);
})->tag('stripe','billing','cleanup','idempotency','account-isolation','security','framework');

test('Stripe account cleanup fails closed on mismatched or incomplete provider evidence', static function(Context $t): void {
	$clientFor=static fn(mixed $response): \dataphyre\stripe_account_client=>new \dataphyre\stripe_account_client(
		dp_stripe_unit_account_key(),
		static fn()=>$response,
	);

	foreach([
		false,
		['id'=>'seti_0987654321', 'status'=>'canceled', 'cancellation_reason'=>'abandoned'],
		['id'=>'seti_1234567890', 'status'=>'succeeded', 'cancellation_reason'=>'abandoned'],
		['id'=>'seti_1234567890', 'status'=>'canceled', 'customer'=>'customer_bad', 'cancellation_reason'=>'abandoned'],
		['id'=>'seti_1234567890', 'status'=>'canceled', 'cancellation_reason'=>'duplicate'],
		['id'=>'seti_1234567890', 'status'=>'canceled', 'cancellation_reason'=>'fraudulent'],
	] as $response){
		$client=$clientFor($response);
		$t->throwsLike(
			static fn()=>$client->cancel_setup_intent(
				'seti_1234567890',
				['cancellation_reason'=>'abandoned'],
				'cancel-key',
			),
			RuntimeException::class,
			'Stripe account cleanup response is invalid.',
		);
	}

	foreach([
		['id'=>'pm_1234567890'],
		['id'=>'pm_1234567890', 'customer'=>'cus_1234567890'],
		['id'=>'pm_0987654321', 'customer'=>null],
	] as $response){
		$client=$clientFor($response);
		$t->throwsLike(
			static fn()=>$client->detach_payment_method('pm_1234567890', 'detach-key'),
			RuntimeException::class,
			'Stripe account cleanup response is invalid.',
		);
	}

	foreach([
		['id'=>'cus_1234567890'],
		['id'=>'cus_1234567890', 'deleted'=>false],
		['id'=>'cus_1234567890', 'deleted'=>1],
		['id'=>'cus_0987654321', 'deleted'=>true],
	] as $response){
		$client=$clientFor($response);
		$t->throwsLike(
			static fn()=>$client->delete_customer('cus_1234567890', 'delete-key'),
			RuntimeException::class,
			'Stripe account cleanup response is invalid.',
		);
	}

	$client=$clientFor(new class {
		/** @return array<string,mixed> */
		public function toArray(): array {
			throw new RuntimeException('provider-secret-must-not-propagate');
		}
	});
	$t->throwsLike(
		static fn()=>$client->delete_customer('cus_1234567890', 'delete-key'),
		RuntimeException::class,
		'Stripe account cleanup response is invalid.',
	);
})->tag('stripe','billing','cleanup','response-validation','security','framework');

test('Stripe account client reports unavailable injected services and propagates executor failures', static function(Context $t): void {
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), null, new stdClass());
	$t->throws(static fn()=>$client->create_customer([]), RuntimeException::class);

	$fake=new dp_stripe_account_fake_client();
	$fake->customers=new stdClass();
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), null, $fake);
	$t->throws(static fn()=>$client->create_customer([]), RuntimeException::class);

	$factory=new dp_stripe_account_fake_service_factory_client();
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), null, $factory);
	$t->same('create', $client->create_customer(['email'=>'factory@example.test'])['method']);
	$t->count(1, $factory->customerService()->calls);

	$client=new \dataphyre\stripe_account_client(
		dp_stripe_unit_account_key(),
		static fn()=>throw new RuntimeException('executor stopped'),
	);
	$t->throwsLike(static fn()=>$client->create_customer([]), RuntimeException::class, 'executor stopped');

	$accountClientType=$t->nonPublic(\dataphyre\stripe_account_client::class);
	$workspace=$t->workspace('stripe-account-sdk-failures');
	$t->throws(
		static fn()=>$accountClientType->invoke('load_sdk', '\\Stripe\\StripeClient', $workspace->path('missing.php')),
		RuntimeException::class,
	);
	$empty=$workspace->file('empty.php', '<?php');
	$t->throws(
		static fn()=>$accountClientType->invoke('load_sdk', '\\Stripe\\StripeClient', $empty),
		RuntimeException::class,
	);
})->tag('stripe','billing','failure','framework');

test('Stripe account webhook boundary validates input before any verifier runs', static function(Context $t): void {
	$calls=0;
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key(), static function() use (&$calls): void { $calls++; });
	$payload='{"id":"evt_validation","object":"event"}';
	$secret=dp_stripe_unit_webhook_secret();
	$valid=dp_stripe_unit_signature(time(), $payload, $secret);
	foreach([
		['', $valid, $secret],
		[$payload, '', $secret],
		[$payload, 't=1,v1=short', $secret],
		[$payload, $valid, ''],
		[$payload, $valid, 'whsec'.'_short'],
	] as [$body,$signature,$webhookSecret]){
		$t->throws(
			static fn()=>$client->construct_webhook_event($body, $signature, $webhookSecret),
			InvalidArgumentException::class,
		);
	}
	$t->same(0, $calls);
})->tag('stripe','billing','webhook','security','framework');

	test('Stripe account default client and webhook verifier leave Stripe global API state untouched', static function(Context $t): void {
	$client=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key());
	$scoped=$t->nonPublic($client)->invoke('stripe_client');
	$t->instanceOf(\Stripe\StripeClient::class, $scoped);
	$t->same(dp_stripe_unit_account_key(), $scoped->getApiKey());
	$t->isNull(\Stripe\Stripe::getApiKey());

	\Stripe\Stripe::setApiKey('global-unit-marker');
	$before=\Stripe\Stripe::getApiKey();
	$second=new \dataphyre\stripe_account_client(dp_stripe_unit_account_key('rk'));
	$secondScoped=$t->nonPublic($second)->invoke('stripe_client');
	$t->same(dp_stripe_unit_account_key('rk'), $secondScoped->getApiKey());
	$t->same($before, \Stripe\Stripe::getApiKey());

	$payload=json_encode([
		'id'=>'evt_1234567890',
		'object'=>'event',
		'type'=>'customer.updated',
		'data'=>['object'=>['id'=>'cus_1234567890', 'object'=>'customer']],
	], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$secret=dp_stripe_unit_webhook_secret();
	$signature=dp_stripe_unit_signature(time(), $payload, $secret);
	$event=$client->construct_webhook_event($payload, $signature, $secret);
	$t->instanceOf(\Stripe\Event::class, $event);
	$t->same('evt_1234567890', $event->id);
	$t->same('customer.updated', $event->type);
	$t->same($before, \Stripe\Stripe::getApiKey());

	$bad='t='.time().',v1='.str_repeat('0', 64);
	$t->throws(
		static fn()=>$client->construct_webhook_event($payload, $bad, $secret),
		\Stripe\Exception\SignatureVerificationException::class,
	);
	$t->same($before, \Stripe\Stripe::getApiKey());
})->tag('stripe','billing','webhook','account-isolation','framework')->maxMillis(5000);

test('Stripe account public API remains exact for application adapters', static function(Context $t): void {
	$inventory=$t->inventory(\dataphyre\stripe_account_client::class);
	$t->isTrue($inventory->isFinal());
	$expected=[
		'readiness'=>0,
		'create_customer'=>2,
		'update_customer'=>3,
		'retrieve_customer'=>1,
		'delete_customer'=>2,
		'create_setup_intent'=>2,
		'retrieve_setup_intent'=>1,
		'cancel_setup_intent'=>3,
		'retrieve_payment_method'=>1,
		'detach_payment_method'=>2,
		'create_payment_intent'=>2,
		'retrieve_payment_intent'=>1,
		'construct_webhook_event'=>3,
	];
	foreach($expected as $method=>$parameters){
		$shape=$inventory->method($method);
		$t->isTrue($shape->isPublic());
		$t->same($parameters, $shape->getNumberOfParameters());
	}
	$createCustomerKey=$inventory->method('create_customer')->getParameters()[1];
	$t->same('string', (string)$createCustomerKey->getType());
	$t->isTrue($createCustomerKey->isDefaultValueAvailable());
	$t->same('', $createCustomerKey->getDefaultValue());
	foreach([
		['delete_customer', 1, 'idempotency_key'],
		['cancel_setup_intent', 2, 'idempotency_key'],
		['detach_payment_method', 1, 'idempotency_key'],
	] as [$method,$index,$name]){
		$parameter=$inventory->method($method)->getParameters()[$index];
		$t->same($name, $parameter->getName());
		$t->same('string', (string)$parameter->getType());
		$t->isFalse($parameter->isDefaultValueAvailable());
	}
})->tag('stripe','billing','api-contract','framework');
