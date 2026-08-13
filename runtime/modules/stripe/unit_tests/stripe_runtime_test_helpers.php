<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;

foreach(['DATAPHYRE_STRIPE_NO_DISPATCH','DATAPHYRE_STRIPE_WEBHOOK_NO_DISPATCH'] as $constant){
	if(!defined($constant)){
		define($constant, true);
	}
}

require_once dirname(__DIR__).'/kernel/stripe.main.php';
require_once dirname(__DIR__).'/kernel/webhook.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableSchema.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

if(!function_exists('stripe_webhook_global_event')){
	function stripe_webhook_global_event(mixed $object): string {
		return 'global:'.(string)($object->id ?? 'missing');
	}
}

/** Mutable SDK resource fixture that records instance actions by intent. */
final class DpStripeResourceFixture {
	public string $id='pm_fixture';
	public string $status='succeeded';
	public ?string $customer='cus_fixture';
	public string $type='card';
	public object $card;
	public object $billing_details;
	public object $charges;
	/** @var array<string,string|Throwable> */
	private array $failures;
	private Closure $observe;

	/** @param array<string,string|Throwable> $failures */
	public function __construct(callable $observe, ?string $customer='cus_fixture', array $failures=[]) {
		$this->observe=Closure::fromCallable($observe);
		$this->customer=$customer;
		$this->failures=$failures;
		$this->card=(object)[
			'brand'=>'visa', 'country'=>'CA', 'last4'=>'4242', 'exp_month'=>12, 'exp_year'=>2032,
		];
		$this->billing_details=(object)['address'=>(object)['postal_code'=>'H2X 1Y4']];
		$this->charges=(object)['data'=>[(object)[
			'id'=>'ch_fixture', 'amount'=>5000, 'amount_refunded'=>1000,
		]]];
	}

	public function cancel(): void {
		$this->act('cancel');
	}

	public function confirm(): void {
		$this->act('confirm');
	}

	public function capture(): void {
		$this->act('capture');
	}

	public function detach(): void {
		$this->act('detach');
	}

	/** @param array<string,mixed> $arguments */
	public function attach(array $arguments): void {
		$this->act('attach', $arguments);
	}

	/** @param array<string,mixed> $arguments */
	private function act(string $action, array $arguments=[]): void {
		($this->observe)($action, $arguments);
		if(isset($this->failures[$action])){
			$failure=$this->failures[$action];
			throw $failure instanceof Throwable ? $failure : new RuntimeException($failure);
		}
	}
}

/** Intent-level vocabulary for Stripe facade, SQL lifecycle, and webhook tests. */
final class DpStripeRuntimeScenario {
	/** @var list<array{operation:string,arguments:array}> */
	private array $operations=[];
	/** @var list<array{action:string,arguments:array}> */
	private array $actions=[];
	/** @var list<string> */
	private array $logs=[];
	/** @var list<array{status:int,body:string}> */
	private array $emissions=[];
	/** @var list<array<int,mixed>> */
	private array $sqlDeletes=[];
	/** @var list<array<int,mixed>> */
	private array $unavailable=[];
	/** @var array<string,mixed> */
	private array $responses=[];
	/** @var array<string,string> */
	private array $failures=[];
	private mixed $sqlSelect=false;
	private mixed $sqlInsert=1;
	private mixed $sqlUpdate=true;
	private string $apiKey='fixture-live-secret';
	private int $paymentMethodRetrievals=0;
	private ?string $paymentCase=null;

	private function __construct(private Context $context) {
		$this->reset();
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	public function reset(array $overrides=[]): self {
		$this->operations=[];
		$this->actions=[];
		$this->logs=[];
		$this->emissions=[];
		$this->sqlDeletes=[];
		$this->unavailable=[];
		$this->responses=[];
		$this->failures=[];
		$this->sqlSelect=false;
		$this->sqlInsert=1;
		$this->sqlUpdate=true;
		$this->apiKey='fixture-live-secret';
		$this->paymentMethodRetrievals=0;
		$this->paymentCase=null;
		$runtime=[
			'config'=>$this->liveConfig(),
			'load'=>static fn(): bool=>true,
			'get_api_key'=>fn(): string=>$this->apiKey,
			'set_api_key'=>function(string $key): void {
				$this->apiKey=$key;
			},
			'execute'=>fn(string $operation, array $arguments): mixed=>$this->execute($operation, $arguments),
			'sql_select'=>fn(mixed ...$arguments): mixed=>$this->sqlSelect,
			'sql_insert'=>fn(mixed ...$arguments): mixed=>$this->sqlInsert,
			'sql_update'=>fn(mixed ...$arguments): mixed=>$this->sqlUpdate,
			'sql_delete'=>function(mixed ...$arguments): bool {
				$this->sqlDeletes[]=$arguments;
				return true;
			},
			'log'=>function(string $message): void {
				$this->logs[]=$message;
			},
			'unavailable'=>function(mixed ...$arguments): void {
				$this->unavailable[]=$arguments;
			},
			'trace'=>static fn(mixed ...$arguments): null=>null,
			'is_card_decline'=>static fn(Throwable $exception): bool=>$exception->getMessage()==='card declined',
			'emit_webhook'=>function(int $status, string $body): void {
				$this->emissions[]=['status'=>$status,'body'=>$body];
			},
			'server'=>['HTTP_STRIPE_SIGNATURE'=>'sig_runtime'],
			'payload'=>'{"id":"evt_runtime"}',
		];
		\dataphyre\stripe::resetRuntime(array_replace($runtime, $overrides));
		return $this;
	}

	/** @return array<string,mixed> */
	private function liveConfig(): array {
		return [
			'test_mode'=>false,
			'webhook_secret_key'=>'fixture-webhook-secret',
			'api_secret_key_live'=>'fixture-live-secret',
			'api_publishable_key_live'=>'pk_live_runtime',
			'api_secret_key_test_mode'=>'sk_test_runtime',
			'api_publishable_key_test_mode'=>'pk_test_runtime',
			'payment_intent_minimum_amount'=>[],
		];
	}

	/** @return array<string,mixed> */
	public function keySelectionContract(): array {
		$this->reset();
		$live=[
			'publishable'=>\dataphyre\stripe::get_publishable_key(),
			'secret'=>\dataphyre\stripe::get_secret_key(),
			'webhook'=>\dataphyre\stripe::get_webhook_secret_key(),
		];
		$config=$this->liveConfig();
		$config['test_mode']=true;
		\dataphyre\stripe::configureRuntime(['config'=>$config]);
		$test=[
			'publishable'=>\dataphyre\stripe::get_publishable_key(),
			'secret'=>\dataphyre\stripe::get_secret_key(),
		];
		\dataphyre\stripe::configureRuntime(['test_mode_override'=>true,'config'=>$this->liveConfig()]);
		return [
			'live'=>$live,
			'test'=>$test,
			'override_secret'=>\dataphyre\stripe::get_secret_key(),
		];
	}

	/** @return array<string,mixed> */
	public function platformAccountContract(): array {
		$this->reset();
		$before=\dataphyre\stripe::get_platform_account();
		$set=\dataphyre\stripe::set_platform_account();
		return ['before'=>$before,'set'=>$set,'after'=>$this->apiKey];
	}

	/** @return array<string,mixed> */
	public function runtimeAndFallbackConfigContract(): array {
		$this->reset();
		$snapshot=\dataphyre\stripe::runtimeState();
		\dataphyre\stripe::resetRuntime(['trace'=>static fn(mixed ...$arguments): null=>null]);
		$fallback=\dataphyre\stripe::get_webhook_secret_key();
		return ['has_config'=>isset($snapshot['config']),'missing_falls_back'=>$fallback];
	}

	public function processConfigWebhookSecret(): string|bool {
		if(!defined('DP_STRIPE_CFG')){
			define('DP_STRIPE_CFG', ['webhook_secret_key'=>'fixture-process-webhook-secret']);
		}
		\dataphyre\stripe::resetRuntime(['trace'=>static fn(mixed ...$arguments): null=>null]);
		return \dataphyre\stripe::get_webhook_secret_key();
	}

	public function platformWithoutSecret(): bool {
		$config=$this->liveConfig();
		$config['api_secret_key_live']=false;
		$this->reset(['config'=>$config]);
		return \dataphyre\stripe::set_platform_account();
	}

	/** Exercises every legacy facade operation through one observed SDK gateway. */
	public function operationCatalog(): array {
		$this->reset();
		$results=[
			'balance'=>\dataphyre\stripe::get_platform_balance(),
			'customer'=>\dataphyre\stripe::create_customer(7, 'person@example.test', 'Person'),
			'account'=>\dataphyre\stripe::create_account(['type'=>'express']),
			'verified'=>\dataphyre\stripe::verify_account('acct_one', ['business_type'=>'individual']),
			'bank'=>\dataphyre\stripe::create_bank_account('acct_one', ['token'=>'btok_one']),
			'default'=>\dataphyre\stripe::set_default_for_payouts('acct_one', 'ba_one'),
			'updated'=>\dataphyre\stripe::update_account('acct_one', ['email'=>'owner@example.test']),
			'intent'=>\dataphyre\stripe::create_payment_intent(['amount'=>5000,'currency'=>'cad']),
			'status'=>\dataphyre\stripe::check_payment_status('pi_one'),
			'cancelled'=>\dataphyre\stripe::cancel_payment('pi_one'),
			'link'=>\dataphyre\stripe::create_account_link('acct_one', 'https://return.test', 'https://refresh.test'),
			'account_status'=>\dataphyre\stripe::check_account_status('acct_one'),
			'transfer'=>\dataphyre\stripe::initiate_transfer(['amount'=>1000]),
			'payout'=>\dataphyre\stripe::create_payout(['amount'=>900], ['stripe_account'=>'acct_one']),
			'confirmed'=>\dataphyre\stripe::submit_payment('pi_one'),
			'refund'=>\dataphyre\stripe::submit_refund('pi_one', 500),
			'deleted'=>\dataphyre\stripe::delete_payment_method('pm_one'),
			'method'=>\dataphyre\stripe::retrieve_payment_method('pm_one'),
			'retrieved_intent'=>\dataphyre\stripe::retrieve_payment_intent('pi_one'),
			'captured'=>\dataphyre\stripe::capture_payment_intent('pi_one'),
			'methods'=>\dataphyre\stripe::retrieve_all_payment_methods('cus_one'),
			'attached'=>\dataphyre\stripe::attach_payment_method('pm_one', 'cus_one'),
		];
		return [
			'operation_names'=>array_column($this->operations, 'operation'),
			'action_names'=>array_column($this->actions, 'action'),
			'status'=>$results['status'],
			'all_results_succeeded'=>!in_array(false, $results, true),
			'local_deletes'=>count($this->sqlDeletes),
		];
	}

	/** @return array<string,array{outcome:mixed,deletes:int}> */
	public function paymentMethodLifecycleOutcomes(): array {
		$outcomes=[];
		foreach([
			'duplicate','bad_token','customer_creation','insert_failure','attach_failure',
			'card_declined','update_failure','success','remote_unattached',
		] as $case){
			$outcomes[$case]=$this->paymentMethodOutcome($case);
		}
		return $outcomes;
	}

	/** @return array{outcome:mixed,deletes:int} */
	private function paymentMethodOutcome(string $case): array {
		$this->reset();
		$this->paymentCase=$case;
		$this->sqlSelect=$case==='duplicate' ? 'pm_existing' : false;
		$this->sqlInsert=$case==='insert_failure' ? false : 1;
		$this->sqlUpdate=$case==='update_failure' ? false : true;
		$customer=$case==='customer_creation' ? '' : 'cus_fixture';
		$callback=$case==='customer_creation'
			? static fn(): bool=>false
			: null;
		$outcome=\dataphyre\stripe::handle_new_payment_method('pm_fixture', 42, $customer, 'Card Holder', $callback);
		return ['outcome'=>$outcome,'deletes'=>count($this->sqlDeletes)];
	}

	/** @return array<string,mixed> */
	public function failurePolicies(): array {
		$this->reset();
		$this->failures['balance.retrieve']='gateway unavailable';
		$remote=\dataphyre\stripe::get_platform_balance();

		$this->reset();
		$this->responses['payment_intent.retrieve']=new stdClass();
		$missingAction=\dataphyre\stripe::cancel_payment('pi_missing_action');

		$this->reset();
		$this->responses['payment_method.retrieve']=$this->resource(['attach'=>'card declined']);
		$declined=\dataphyre\stripe::attach_payment_method('pm_declined', 'cus_one');

		$this->reset();
		$intent=$this->resource();
		$intent->charges->data[0]->amount_refunded=4900;
		$this->responses['payment_intent.retrieve']=$intent;
		$overRefund=\dataphyre\stripe::submit_refund('pi_one', 200);

		$this->reset();
		$this->responses['payment_method.retrieve']=$this->resource(['detach'=>'detach failed']);
		$delete=\dataphyre\stripe::delete_payment_method('pm_detach_failure');
		return [
			'remote'=>$remote,
			'missing_action'=>$missingAction,
			'declined'=>$declined,
			'over_refund'=>$overRefund,
			'delete_after_remote_failure'=>$delete,
			'deletes'=>count($this->sqlDeletes),
			'logged'=>count($this->logs)>0,
		];
	}

	/** Every facade family returns false before touching its boundary when loading fails. */
	public function unavailableOperationContract(): array {
		$this->reset(['load'=>static fn(): bool=>false]);
		return [
			'new_method'=>\dataphyre\stripe::handle_new_payment_method('pm_one', 1, 'cus_one', 'Holder'),
			'refund'=>\dataphyre\stripe::submit_refund('pi_one', 100),
			'delete'=>\dataphyre\stripe::delete_payment_method('pm_one'),
			'remote'=>\dataphyre\stripe::get_platform_balance(),
			'action'=>\dataphyre\stripe::cancel_payment('pi_one'),
		];
	}

	/** @return array<string,mixed> */
	public function refundFailureContract(): array {
		$this->reset();
		$this->responses['payment_intent.retrieve']=new stdClass();
		$missingCharge=\dataphyre\stripe::submit_refund('pi_missing_charge', 100);
		$missingChargeLogged=count($this->logs)>0;

		$this->reset();
		$this->failures['payment_intent.retrieve']='retrieve failed';
		$gatewayFailure=\dataphyre\stripe::submit_refund('pi_failed', 100);
		return [
			'missing_charge'=>$missingCharge,
			'missing_charge_logged'=>$missingChargeLogged,
			'gateway_failure'=>$gatewayFailure,
			'gateway_failure_logged'=>count($this->logs)>0,
		];
	}

	/** @return array<string,mixed> */
	public function webhookContract(): array {
		$this->reset();
		$supported=\dataphyre\stripe::handle_webhook([
			'server'=>['HTTP_STRIPE_SIGNATURE'=>'sig_explicit'],
			'payload'=>static fn(): string=>'supported payload',
			'verify'=>fn(): object=>$this->event('payment_intent.succeeded', 'pi_supported'),
			'callbacks'=>['stripe_webhook_payment_intent_succeeded'=>static fn(object $object): string=>'accepted:'.$object->id],
		]);

		$this->reset();
		$unsupported=\dataphyre\stripe::handle_webhook([
			'payload'=>'unsupported payload',
			'verify'=>fn(): object=>$this->event('unknown.event', 'evt_unknown'),
		]);
		$unsupportedEmission=$this->emissions[0] ?? null;

		$this->reset();
		$invalid=\dataphyre\stripe::handle_webhook([
			'payload'=>'invalid payload',
			'verify'=>static fn(): never=>throw new RuntimeException('bad signature'),
		]);
		$invalidEmission=$this->emissions[0] ?? null;

		$this->reset();
		$invalidVerifier=\dataphyre\stripe::handle_webhook([
			'payload'=>new stdClass(),
			'verify'=>'not-callable',
		]);
		$invalidVerifierEmission=$this->emissions[0] ?? null;

		$this->reset(['sdk_operations'=>[
			'webhook.construct'=>fn(): array=>[
				'type'=>'array.event',
				'data'=>['object'=>(object)['id'=>'evt_array']],
			],
		]]);
		$arrayEvent=\dataphyre\stripe::handle_webhook([
			'payload'=>'array payload',
			'callbacks'=>['stripe_webhook_array_event'=>static fn(object $object): string=>'array:'.$object->id],
		]);

		$this->reset();
		$global=\dataphyre\stripe::handle_webhook([
			'payload'=>'global payload',
			'verify'=>fn(): object=>$this->event('global.event', 'evt_global'),
		]);

		$this->reset(['load'=>static fn(): bool=>false]);
		$unavailable=\dataphyre\stripe::handle_webhook();
		return [
			'supported'=>$supported,
			'unsupported'=>$unsupported,
			'unsupported_response'=>$unsupportedEmission,
			'invalid'=>$invalid,
			'invalid_response'=>$invalidEmission,
			'invalid_verifier'=>$invalidVerifier,
			'invalid_verifier_response'=>$invalidVerifierEmission,
			'array_event'=>$arrayEvent,
			'global_callback'=>$global,
			'platform_unavailable'=>$unavailable,
		];
	}

	public function unsupportedWebhookWithDirectEmitter(): mixed {
		$this->reset(['emit_webhook'=>null]);
		return \dataphyre\stripe::handle_webhook([
			'payload'=>'direct payload',
			'verify'=>fn(): object=>$this->event('unsupported.direct', 'evt_direct'),
		]);
	}

	public function dispatchWebhookEndpoint(): mixed {
		$this->reset();
		return dataphyre_stripe_webhook_endpoint::bootstrap(true, [
			'payload'=>'endpoint payload',
			'verify'=>fn(): object=>$this->event('endpoint.accepted', 'evt_endpoint'),
			'callbacks'=>['stripe_webhook_endpoint_accepted'=>static fn(object $object): string=>'endpoint:'.$object->id],
		]);
	}

	public function emitThroughInvalidBoundary(): mixed {
		$this->reset(['emit_webhook'=>'invalid']);
		return \dataphyre\stripe::handle_webhook([
			'payload'=>'invalid emitter',
			'verify'=>static fn(): never=>throw new RuntimeException('rejected'),
		]);
	}

	public function dispatchThroughSdkOperationMap(): mixed {
		$this->reset([
			'execute'=>null,
			'sdk_operations'=>[
				'balance.retrieve'=>static fn(): string=>'mapped balance',
			],
		]);
		return \dataphyre\stripe::get_platform_balance();
	}

	public function defaultOperationCatalogContainsBalance(): bool {
		$this->reset();
		$callable=$this->context->nonPublic(\dataphyre\stripe::class)->invoke('operationCallable', 'balance.retrieve');
		return is_array($callable) && $callable[0]===\Stripe\Balance::class && $callable[1]==='retrieve';
	}

	/** @return array<string,mixed> */
	public function containedBoundaryFailures(): array {
		$this->reset(['execute'=>'invalid']);
		$invalidExecutor=\dataphyre\stripe::get_platform_balance();

		$this->reset(['execute'=>null,'sdk_operations'=>[]]);
		$unknownOperation=\dataphyre\stripe::get_platform_balance();

		$this->reset(['get_api_key'=>null]);
		$missingSdkState=\dataphyre\stripe::get_platform_account();
		return [
			'invalid_executor'=>$invalidExecutor,
			'unknown_operation'=>$unknownOperation,
			'missing_sdk_state'=>$missingSdkState,
		];
	}

	public function loadThroughInvalidBoundary(): bool {
		$this->reset(['load'=>'invalid']);
		return \dataphyre\stripe::load_stripe();
	}

	public function selectThroughInvalidBoundary(): mixed {
		$this->reset(['sql_select'=>'invalid']);
		return \dataphyre\stripe::handle_new_payment_method('pm_one', 1, 'cus_one', 'Holder');
	}

	public function readThroughInvalidApiKeyBoundary(): string|false {
		$this->reset(['get_api_key'=>'invalid']);
		return \dataphyre\stripe::get_platform_account();
	}

	public function writeThroughInvalidApiKeyBoundary(): bool {
		$this->reset(['set_api_key'=>'invalid']);
		return \dataphyre\stripe::set_platform_account();
	}

	/** @return array{invalid_loader:bool,missing_class:bool,missing_key:bool,invalid_retries:bool,unavailable:int} */
	public function sdkInitializationFailures(): array {
		$this->unavailable=[];
		$base=[
			'trace'=>static fn(mixed ...$arguments): null=>null,
			'unavailable'=>function(mixed ...$arguments): void {
				$this->unavailable[]=$arguments;
			},
		];
		\dataphyre\stripe::resetRuntime($base+['config'=>$this->liveConfig(),'sdk_loader'=>'invalid']);
		$invalidLoader=\dataphyre\stripe::load_stripe();
		\dataphyre\stripe::resetRuntime($base+['config'=>$this->liveConfig(),'sdk_loader'=>static fn(): null=>null]);
		$missingClass=\dataphyre\stripe::load_stripe();
		$config=$this->liveConfig();
		$config['api_secret_key_live']=false;
		\dataphyre\stripe::resetRuntime($base+['config'=>$config]);
		$missingKey=\dataphyre\stripe::load_stripe();
		\dataphyre\stripe::resetRuntime($base+['config'=>$this->liveConfig(),'set_network_retries'=>'invalid']);
		$invalidRetries=\dataphyre\stripe::load_stripe();
		return [
			'invalid_loader'=>$invalidLoader,
			'missing_class'=>$missingClass,
			'missing_key'=>$missingKey,
			'invalid_retries'=>$invalidRetries,
			'unavailable'=>count($this->unavailable),
		];
	}

	public function loadBundledSdk(): bool {
		\dataphyre\stripe::resetRuntime([
			'config'=>$this->liveConfig(),
			'trace'=>static fn(mixed ...$arguments): null=>null,
			'unavailable'=>function(mixed ...$arguments): void {
				$this->unavailable[]=$arguments;
			},
		]);
		return \dataphyre\stripe::load_stripe();
	}

	public function bundledApiKey(): string|false {
		return \dataphyre\stripe::get_platform_account();
	}

	public function declineThroughBundledExceptionPolicy(): mixed {
		$this->loadBundledSdk();
		$this->reset(['is_card_decline'=>null]);
		$this->responses['payment_method.retrieve']=$this->resource([
			'attach'=>new \Stripe\Exception\CardException('declined by Stripe'),
		]);
		return \dataphyre\stripe::attach_payment_method('pm_declined', 'cus_one');
	}

	/** @return list<array<int,mixed>> */
	public function unavailableCalls(): array {
		return $this->unavailable;
	}

	/** @return list<string> */
	public function logs(): array {
		return $this->logs;
	}

	/** @param array<string,string|Throwable> $failures */
	private function resource(array $failures=[], ?string $customer='cus_fixture'): DpStripeResourceFixture {
		return new DpStripeResourceFixture(function(string $action, array $arguments): void {
			$this->actions[]=['action'=>$action,'arguments'=>$arguments];
		}, $customer, $failures);
	}

	private function execute(string $operation, array $arguments): mixed {
		$this->operations[]=['operation'=>$operation,'arguments'=>$arguments];
		if(isset($this->failures[$operation])){
			throw new RuntimeException($this->failures[$operation]);
		}
		if(array_key_exists($operation, $this->responses)){
			$response=$this->responses[$operation];
			return is_callable($response) ? $response($arguments) : $response;
		}
		if($operation==='payment_intent.retrieve'){
			return $this->resource();
		}
		if($operation==='payment_method.retrieve'){
			$this->paymentMethodRetrievals++;
			if($this->paymentCase==='bad_token'){
				return false;
			}
			if($this->paymentCase==='attach_failure' && $this->paymentMethodRetrievals>1){
				throw new RuntimeException('attach failed');
			}
			$failures=$this->paymentCase==='card_declined' ? ['attach'=>'card declined'] : [];
			$customer=$this->paymentCase==='remote_unattached' ? null : 'cus_fixture';
			return $this->resource($failures, $customer);
		}
		return (object)['operation'=>$operation,'arguments'=>$arguments];
	}

	private function event(string $type, string $id): object {
		return (object)[
			'type'=>$type,
			'data'=>(object)['object'=>(object)['id'=>$id]],
		];
	}
}
