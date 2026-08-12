<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

require_once __DIR__.'/stripe.account_client.php';

/**
 * Stripe's compatibility facade backed by explicit SDK, SQL, and HTTP boundaries.
 *
 * Public method names remain stable for existing applications. Repeated SDK
 * dispatch and exception policy live in one gateway, making every operation
 * deterministic under tests while preserving the bundled SDK in production.
 */
class stripe {
	/** @var array<string,mixed> */
	private static array $runtime=[];

	/** @var array<string,array{0:class-string,1:string}> */
	private const SDK_OPERATIONS=[
		'balance.retrieve'=>[\Stripe\Balance::class, 'retrieve'],
		'customer.create'=>[\Stripe\Customer::class, 'create'],
		'account.create'=>[\Stripe\Account::class, 'create'],
		'account.retrieve'=>[\Stripe\Account::class, 'retrieve'],
		'account.update'=>[\Stripe\Account::class, 'update'],
		'account.create_external_account'=>[\Stripe\Account::class, 'createExternalAccount'],
		'payment_intent.create'=>[\Stripe\PaymentIntent::class, 'create'],
		'payment_intent.retrieve'=>[\Stripe\PaymentIntent::class, 'retrieve'],
		'account_link.create'=>[\Stripe\AccountLink::class, 'create'],
		'transfer.create'=>[\Stripe\Transfer::class, 'create'],
		'payout.create'=>[\Stripe\Payout::class, 'create'],
		'refund.create'=>[\Stripe\Refund::class, 'create'],
		'payment_method.retrieve'=>[\Stripe\PaymentMethod::class, 'retrieve'],
		'payment_method.all'=>[\Stripe\PaymentMethod::class, 'all'],
		'webhook.construct'=>[\Stripe\Webhook::class, 'constructEvent'],
	];

	/** Restores the facade and installs optional deterministic runtime boundaries. */
	public static function resetRuntime(array $runtime=[]): void {
		self::$runtime=$runtime;
	}

	/** Merges runtime boundaries without replacing unrelated observations. */
	public static function configureRuntime(array $runtime): void {
		self::$runtime=array_replace(self::$runtime, $runtime);
	}

	/** @return array<string,mixed> */
	public static function runtimeState(): array {
		return self::$runtime;
	}

	private static function cfg(string $key, mixed $default=false): mixed {
		if(is_array(self::$runtime['config'] ?? null) && array_key_exists($key, self::$runtime['config'])){
			return self::$runtime['config'][$key];
		}
		if(defined('RUN_MODE') && RUN_MODE==='diagnostic' && is_array($GLOBALS['DP_STRIPE_CFG_OVERRIDE'] ?? null) && array_key_exists($key, $GLOBALS['DP_STRIPE_CFG_OVERRIDE'])){
			return $GLOBALS['DP_STRIPE_CFG_OVERRIDE'][$key];
		}
		return defined('DP_STRIPE_CFG') && is_array(DP_STRIPE_CFG)
			? (DP_STRIPE_CFG[$key] ?? $default)
			: $default;
	}

	private static function test_mode(): bool {
		$override=self::$runtime['test_mode_override']
			?? (defined('DP_STRIPE_TEST_MODE_OVERRIDE') ? DP_STRIPE_TEST_MODE_OVERRIDE : null);
		return $override===true || self::cfg('test_mode', false)===true;
	}

	public static function get_platform_account(): string|false {
		self::trace(__FUNCTION__);
		return self::load_stripe() ? self::readApiKey() : false;
	}

	public static function set_platform_account(): bool {
		self::trace(__FUNCTION__);
		if(!self::load_stripe()){
			return false;
		}
		$key=self::get_secret_key();
		if(!is_string($key) || trim($key)===''){
			self::unavailable('DataphyreStripe: Platform API secret is not configured.');
			return false;
		}
		self::writeApiKey($key);
		return true;
	}

	public static function get_publishable_key(): string|bool {
		self::trace(__FUNCTION__);
		return self::test_mode()
			? self::cfg('api_publishable_key_test_mode', false)
			: self::cfg('api_publishable_key_live', false);
	}

	public static function get_webhook_secret_key(): string|bool {
		self::trace(__FUNCTION__);
		return self::cfg('webhook_secret_key', false);
	}

	public static function get_secret_key(): string|bool {
		self::trace(__FUNCTION__);
		return self::test_mode()
			? self::cfg('api_secret_key_test_mode', false)
			: self::cfg('api_secret_key_live', false);
	}

	/** Loads the bundled SDK or delegates loading to the configured runtime seam. */
	public static function load_stripe(): bool {
		self::trace(__FUNCTION__);
		if(array_key_exists('load', self::$runtime)){
			$load=self::$runtime['load'];
			if(!is_callable($load)){
				throw new \LogicException('Stripe loader boundary must be callable.');
			}
			return $load()===true;
		}
		$dialback=self::$runtime['dialback'] ?? (class_exists(core::class, false) ? [core::class, 'dialback'] : null);
		if(is_callable($dialback)){
			$early=$dialback('CALL_STRIPE_LOAD');
			if($early!==null){
				return $early===true;
			}
		}
		try{
			if(!class_exists(\Stripe\Stripe::class)){
				$loader=self::$runtime['sdk_loader'] ?? static function(): void {
					require_once dirname(__DIR__).'/src/init.php';
				};
				if(!is_callable($loader)){
					throw new \LogicException('Stripe SDK loader boundary must be callable.');
				}
				$loader();
			}
			if(!class_exists(\Stripe\Stripe::class, false)){
				self::unavailable('DataphyreStripe: Unable to load the Stripe SDK.');
				return false;
			}
			$key=self::get_secret_key();
			if(!is_string($key) || trim($key)===''){
				self::unavailable('DataphyreStripe: Stripe API key is not configured.');
				return false;
			}
			self::writeApiKey($key);
			$retries=self::$runtime['set_network_retries'] ?? [\Stripe\Stripe::class, 'setMaxNetworkRetries'];
			if(!is_callable($retries)){
				throw new \LogicException('Stripe retry boundary must be callable.');
			}
			$retries(3);
			return true;
		}catch(\Throwable $exception){
			self::unavailable('DataphyreStripe: Unable to initialize the Stripe SDK.', $exception);
			return false;
		}
	}

	/** Verifies and dispatches an incoming Stripe webhook without direct process exits. */
	public static function handle_webhook(array $runtime=[]): mixed {
		self::trace(__FUNCTION__);
		if(!self::set_platform_account()){
			return false;
		}
		$server=is_array($runtime['server'] ?? null)
			? $runtime['server']
			: (is_array(self::$runtime['server'] ?? null) ? self::$runtime['server'] : $_SERVER);
		$signature=(string)($server['HTTP_STRIPE_SIGNATURE'] ?? '');
		$payload=$runtime['payload'] ?? self::$runtime['payload'] ?? null;
		if(is_callable($payload)){
			$payload=$payload();
		}
		if(!is_string($payload)){
			$payload=(string)file_get_contents('php://input');
		}
		$secret=self::get_webhook_secret_key();
		try{
			$verifier=$runtime['verify'] ?? self::$runtime['verify_webhook'] ?? self::operationCallable('webhook.construct');
			if(!is_callable($verifier)){
				throw new \LogicException('Stripe webhook verifier must be callable.');
			}
			$event=$verifier($payload, $signature, $secret);
		}catch(\Throwable $exception){
			self::emitWebhook(400, 'Webhook Error: '.$exception->getMessage(), $runtime);
			return false;
		}
		$type=is_object($event) ? (string)($event->type ?? '') : (string)($event['type'] ?? '');
		$object=is_object($event)
			? ($event->data->object ?? null)
			: ($event['data']['object'] ?? null);
		$callbackName='stripe_webhook_'.str_replace('.', '_', $type);
		$callbacks=is_array($runtime['callbacks'] ?? null)
			? $runtime['callbacks']
			: (is_array(self::$runtime['webhook_callbacks'] ?? null) ? self::$runtime['webhook_callbacks'] : []);
		$callback=$callbacks[$callbackName] ?? (function_exists($callbackName) ? $callbackName : null);
		if(!is_callable($callback)){
			self::emitWebhook(400, 'Unsupported webhook event type: '.$type, $runtime);
			return false;
		}
		return $callback($object);
	}

	public static function get_platform_balance(): mixed {
		return self::remote(__FUNCTION__, 'balance.retrieve');
	}

	public static function handle_new_payment_method(string $stripe_token, int $userid, string $stripe_customer_id, string $name_on_card, ?callable $no_customer_account_callback=null): bool|string {
		self::trace(__FUNCTION__, func_get_args());
		if(!self::load_stripe()){
			return false;
		}
		if(self::sql('select', 'id', 'stripe_payment_methods', 'WHERE id=?', [$stripe_token])!==false){
			return false;
		}
		$paymentMethod=self::retrieve_payment_method($stripe_token);
		if($paymentMethod===false){
			return 'bad_token';
		}
		if($stripe_customer_id==='' && is_callable($no_customer_account_callback)){
			$stripe_customer_id=$no_customer_account_callback($userid, $paymentMethod);
			if($stripe_customer_id===false){
				return 'failed_customer_creation_callback';
			}
		}
		$insert=self::sql('insert', 'stripe_payment_methods', [
			'id'=>$paymentMethod->id,
			'brand'=>$paymentMethod->card->brand,
			'type'=>$paymentMethod->type,
			'userid'=>$userid,
			'is_attached'=>false,
			'is_main'=>false,
			'country'=>$paymentMethod->card->country,
			'last_four_digits'=>$paymentMethod->card->last4,
			'postal_code'=>$paymentMethod->billing_details->address->postal_code,
			'expiration_month'=>$paymentMethod->card->exp_month,
			'expiration_year'=>$paymentMethod->card->exp_year,
			'name_on_card'=>$name_on_card,
		]);
		if($insert===false){
			return 'failed_creating_method';
		}
		$result=self::attach_payment_method($stripe_token, (string)$stripe_customer_id);
		if($result===false || is_string($result)){
			self::deleteLocalPaymentMethod($stripe_token);
			return $result;
		}
		if(($result->customer ?? null)!==null && self::sql('update', 'stripe_payment_methods', [
			'mysql'=>'is_attached=1',
			'postgresql'=>'is_attached=true',
		], 'WHERE id=?', [$paymentMethod->id], true)===false){
			self::deleteLocalPaymentMethod($stripe_token);
			return 'failed_attaching';
		}
		return true;
	}

	public static function create_customer(int $userid, string $email, string $name): mixed {
		return self::remote(__FUNCTION__, 'customer.create', [[
			'email'=>$email,
			'name'=>$name,
			'metadata'=>['user_id'=>$userid],
		]]);
	}

	public static function create_account(array $params): mixed {
		return self::remote(__FUNCTION__, 'account.create', [$params]);
	}

	public static function verify_account(string $account_id, array $params): mixed {
		return self::remote(__FUNCTION__, 'account.update', [$account_id, $params]);
	}

	public static function create_bank_account(string $account_id, array $params): mixed {
		return self::remote(__FUNCTION__, 'account.create_external_account', [$account_id, ['external_account'=>$params]]);
	}

	public static function set_default_for_payouts(string $account_id, string $bank_account_id): mixed {
		return self::remote(__FUNCTION__, 'account.update', [$account_id, ['default_for_currency'=>$bank_account_id]]);
	}

	public static function update_account(string $account_id, array $params): mixed {
		return self::remote(__FUNCTION__, 'account.update', [$account_id, $params]);
	}

	public static function create_payment_intent(array $params): mixed {
		return self::remote(__FUNCTION__, 'payment_intent.create', [$params]);
	}

	public static function check_payment_status(string $payment_intent_id): string|false {
		$intent=self::remote(__FUNCTION__, 'payment_intent.retrieve', [$payment_intent_id]);
		return is_object($intent) && isset($intent->status) ? (string)$intent->status : false;
	}

	public static function cancel_payment(string $payment_intentId): mixed {
		return self::resourceAction(__FUNCTION__, 'payment_intent.retrieve', $payment_intentId, 'cancel');
	}

	public static function create_account_link(string $account_id, string $return_url, string $refresh_url): mixed {
		return self::remote(__FUNCTION__, 'account_link.create', [[
			'account'=>$account_id,
			'refresh_url'=>$refresh_url,
			'return_url'=>$return_url,
			'type'=>'account_onboarding',
		]]);
	}

	public static function check_account_status(string $account_id): mixed {
		return self::remote(__FUNCTION__, 'account.retrieve', [$account_id]);
	}

	public static function initiate_transfer(array $params): mixed {
		return self::remote(__FUNCTION__, 'transfer.create', [$params]);
	}

	public static function create_payout(array $params, array $options=[]): mixed {
		return self::remote(__FUNCTION__, 'payout.create', [$params, $options]);
	}

	public static function submit_payment(string $payment_intentId): mixed {
		return self::resourceAction(__FUNCTION__, 'payment_intent.retrieve', $payment_intentId, 'confirm');
	}

	public static function submit_refund(
		string $payment_intent_id,
		int $amount_to_refund,
		array $request_options=[]
	): mixed {
		self::trace(__FUNCTION__, func_get_args());
		if(!self::load_stripe()){
			return false;
		}
		try{
			$intent=self::executeOperation('payment_intent.retrieve', [$payment_intent_id]);
			$charge=$intent->charges->data[0] ?? null;
			if(!is_object($charge)){
				throw new \RuntimeException('PaymentIntent has no refundable charge.');
			}
			$remaining=(int)$charge->amount-(int)$charge->amount_refunded;
			if($amount_to_refund>$remaining){
				self::log('DataphyreStripe: Refund amount exceeds the remaining refundable amount.');
				return false;
			}
			$arguments=[[
				'charge'=>(string)$charge->id,
				'amount'=>$amount_to_refund,
			]];
			if($request_options!==[]){
				$arguments[]=$request_options;
			}
			return self::executeOperation('refund.create', $arguments);
		}catch(\Throwable $exception){
			self::logException(__FUNCTION__, $exception);
			return false;
		}
	}

	public static function delete_payment_method(string $payment_method_id): bool {
		self::trace(__FUNCTION__, func_get_args());
		if(!self::load_stripe()){
			return false;
		}
		try{
			$method=self::executeOperation('payment_method.retrieve', [$payment_method_id]);
			$method->detach();
		}catch(\Throwable $exception){
			self::logException(__FUNCTION__, $exception);
		}
		self::deleteLocalPaymentMethod($payment_method_id);
		return true;
	}

	public static function retrieve_payment_method(string $payment_method_id): mixed {
		return self::remote(__FUNCTION__, 'payment_method.retrieve', [$payment_method_id]);
	}

	public static function retrieve_payment_intent(string $payment_intentId): mixed {
		return self::remote(__FUNCTION__, 'payment_intent.retrieve', [$payment_intentId]);
	}

	public static function capture_payment_intent(string $payment_intentId): mixed {
		return self::resourceAction(__FUNCTION__, 'payment_intent.retrieve', $payment_intentId, 'capture');
	}

	public static function retrieve_all_payment_methods(string $customer_id): mixed {
		return self::remote(__FUNCTION__, 'payment_method.all', [[
			'customer'=>$customer_id,
			'type'=>'card',
		]]);
	}

	public static function attach_payment_method(string $payment_method_id, string $customer_id): mixed {
		return self::resourceAction(
			__FUNCTION__,
			'payment_method.retrieve',
			$payment_method_id,
			'attach',
			[['customer'=>$customer_id]],
			'card_declined'
		);
	}

	private static function remote(string $function, string $operation, array $arguments=[]): mixed {
		self::trace($function, $arguments);
		if(!self::load_stripe()){
			return false;
		}
		try{
			return self::executeOperation($operation, $arguments);
		}catch(\Throwable $exception){
			self::logException($function, $exception);
			return false;
		}
	}

	private static function resourceAction(string $function, string $operation, string $id, string $method, array $arguments=[], string|false $cardDecline=false): mixed {
		self::trace($function, [$id, ...$arguments]);
		if(!self::load_stripe()){
			return false;
		}
		try{
			$resource=self::executeOperation($operation, [$id]);
			if(!is_object($resource) || !is_callable([$resource, $method])){
				throw new \RuntimeException('Stripe resource action '.$method.' is unavailable.');
			}
			$resource->{$method}(...$arguments);
			return $resource;
		}catch(\Throwable $exception){
			if($cardDecline!==false && self::isCardDecline($exception)){
				return $cardDecline;
			}
			self::logException($function, $exception);
			return false;
		}
	}

	private static function executeOperation(string $operation, array $arguments): mixed {
		$executor=self::$runtime['execute'] ?? [self::class, 'executeSdkOperation'];
		if(!is_callable($executor)){
			throw new \LogicException('Stripe operation executor must be callable.');
		}
		return $executor($operation, $arguments);
	}

	private static function executeSdkOperation(string $operation, array $arguments): mixed {
		$callable=self::operationCallable($operation);
		if(!is_callable($callable)){
			throw new \LogicException('Unknown or unavailable Stripe SDK operation: '.$operation);
		}
		return $callable(...$arguments);
	}

	private static function operationCallable(string $operation): mixed {
		$operations=is_array(self::$runtime['sdk_operations'] ?? null)
			? self::$runtime['sdk_operations']
			: self::SDK_OPERATIONS;
		return $operations[$operation] ?? null;
	}

	private static function sql(string $operation, mixed ...$arguments): mixed {
		$callable=self::$runtime['sql_'.$operation] ?? 'sql_'.$operation;
		if(!is_callable($callable)){
			throw new \LogicException('Stripe SQL '.$operation.' boundary must be callable.');
		}
		return $callable(...$arguments);
	}

	private static function deleteLocalPaymentMethod(string $id): void {
		self::sql('delete', 'stripe_payment_methods', 'WHERE id=?', [$id], true);
	}

	private static function readApiKey(): string|false {
		$reader=self::$runtime['get_api_key'] ?? null;
		if($reader!==null){
			if(!is_callable($reader)){
				throw new \LogicException('Stripe API-key reader must be callable.');
			}
			$value=$reader();
			return is_string($value) && $value!=='' ? $value : false;
		}
		if(!class_exists(\Stripe\Stripe::class, false)){
			return false;
		}
		if(is_callable([\Stripe\Stripe::class, 'getApiKey'])){
			$value=\Stripe\Stripe::getApiKey();
		}elseif(property_exists(\Stripe\Stripe::class, 'api_key')){
			$value=\Stripe\Stripe::$api_key;
		}else{
			$value=\Stripe\Stripe::$apiKey ?? null;
		}
		return is_string($value) && $value!=='' ? $value : false;
	}

	private static function writeApiKey(string $key): void {
		$writer=self::$runtime['set_api_key'] ?? null;
		if($writer!==null){
			if(!is_callable($writer)){
				throw new \LogicException('Stripe API-key writer must be callable.');
			}
			$writer($key);
			return;
		}
		if(is_callable([\Stripe\Stripe::class, 'setApiKey'])){
			\Stripe\Stripe::setApiKey($key);
		}elseif(property_exists(\Stripe\Stripe::class, 'api_key')){
			\Stripe\Stripe::$api_key=$key;
		}else{
			\Stripe\Stripe::$apiKey=$key;
		}
	}

	private static function isCardDecline(\Throwable $exception): bool {
		$policy=self::$runtime['is_card_decline'] ?? null;
		if(is_callable($policy)){
			return $policy($exception)===true;
		}
		return class_exists(\Stripe\Exception\CardException::class, false)
			&& $exception instanceof \Stripe\Exception\CardException;
	}

	private static function trace(string $function, array $arguments=[]): void {
		$trace=self::$runtime['trace'] ?? (function_exists('\tracelog') ? '\tracelog' : null);
		if(is_callable($trace)){
			$trace(__FILE__, __LINE__, __CLASS__, $function, null, 'function_call', $arguments);
		}
	}

	private static function logException(string $function, \Throwable $exception): void {
		self::log('DataphyreStripe: '.__CLASS__.'/'.$function.'(): Error: '.$exception->getMessage());
	}

	private static function log(string $message): void {
		$logger=self::$runtime['log'] ?? (function_exists('\log_error') ? '\log_error' : null);
		if(is_callable($logger)){
			$logger($message);
		}
	}

	private static function unavailable(string $message, ?\Throwable $exception=null): void {
		$callback=self::$runtime['unavailable'] ?? (class_exists(core::class, false) ? [core::class, 'unavailable'] : null);
		if(is_callable($callback)){
			$callback(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $message, 'safemode', $exception);
		}
	}

	private static function emitWebhook(int $status, string $body, array $runtime): void {
		$emit=$runtime['emit'] ?? self::$runtime['emit_webhook'] ?? static function(int $status, string $body): void {
			http_response_code($status);
			echo $body;
		};
		if(!is_callable($emit)){
			throw new \LogicException('Stripe webhook emitter must be callable.');
		}
		$emit($status, $body);
	}
}

/** Initializes module configuration and schema registration without hiding side effects. */
function stripe_bootstrap(?bool $dispatch=null, array $runtime=[]): array {
	$dispatch ??=!defined('DATAPHYRE_STRIPE_NO_DISPATCH');
	if(!$dispatch){
		return ['initialized'=>false,'table_registered'=>false];
	}
	$trace=$runtime['trace'] ?? (function_exists('\tracelog') ? '\tracelog' : null);
	if(is_callable($trace)){
		$trace(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Module initialization');
	}
	$defaults=[
		'test_mode'=>false,
		'webhook_secret_key'=>false,
		'api_secret_key_live'=>false,
		'api_publishable_key_live'=>false,
		'api_secret_key_test_mode'=>false,
		'api_publishable_key_test_mode'=>false,
		'payment_intent_minimum_amount'=>[],
	];
	$defineConfig=$runtime['define_config'] ?? (function_exists('\dp_define_module_config') ? '\dp_define_module_config' : null);
	if(is_callable($defineConfig)){
		$defineConfig('stripe', 'DP_STRIPE_CFG', $defaults);
	}
	$tableRegistered=false;
	$defineTable=$runtime['define_table'] ?? (function_exists('\sql_define_table') ? '\sql_define_table' : null);
	if(is_callable($defineTable)){
		$defineTable('stripe_payment_methods', __DIR__.'/stripe.tables.php', 'payment_methods');
		$tableRegistered=true;
	}
	stripe::resetRuntime(is_array($runtime['stripe_runtime'] ?? null) ? $runtime['stripe_runtime'] : []);
	return ['initialized'=>true,'table_registered'=>$tableRegistered];
}

stripe_bootstrap();
