<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('stripe', 'DP_STRIPE_CFG', [
	'test_mode'=>false,
	'webhook_secret_key'=>false,
	'api_secret_key_live'=>false,
	'api_publishable_key_live'=>false,
	'api_secret_key_test_mode'=>false,
	'api_publishable_key_test_mode'=>false,
	'payment_intent_minimum_amount'=>[],
]);
if(function_exists('sql_define_table')){
	sql_define_table('stripe_payment_methods', __DIR__.'/stripe.tables.php', 'payment_methods');
}

/**
 * Kernel Stripe facade for Dataphyre payments, Connect accounts, and stored cards.
 *
 * This wrapper owns the runtime boundary between Dataphyre configuration, the
 * bundled Stripe SDK, SQL-backed local payment-method metadata, and project-level
 * webhook callbacks. Methods return Stripe SDK resources on success, string
 * failure markers for selected business branches, or false when the Stripe layer
 * cannot be loaded or the SDK raises an exception.
 */
class stripe {

	private static function cfg(string $key, mixed $default=false): mixed {
		if(defined('RUN_MODE') && RUN_MODE==='diagnostic' && is_array($GLOBALS['DP_STRIPE_CFG_OVERRIDE'] ?? null) && array_key_exists($key, $GLOBALS['DP_STRIPE_CFG_OVERRIDE'])){
			return $GLOBALS['DP_STRIPE_CFG_OVERRIDE'][$key];
		}
		return DP_STRIPE_CFG[$key] ?? $default;
	}

	/**
	 * Resolves whether the Stripe wrapper should use test-mode credentials.
	 *
	 * A hard override constant wins over module configuration so tests and CLI
	 * jobs can force test mode without mutating deployed configuration.
	 *
	 * @return bool True when Stripe test credentials should be selected.
	 */
	private static function test_mode(): bool {
		if(defined('DP_STRIPE_TEST_MODE_OVERRIDE') && DP_STRIPE_TEST_MODE_OVERRIDE===true){
			return true;
		}
		return self::cfg('test_mode', false)===true;
	}

	/**
	 * Returns the active platform API key from the Stripe SDK.
	 *
	 * The SDK is loaded first so callers see the same configured key that outgoing
	 * Stripe requests will use.
	 *
	 * @return string|false Active Stripe API key, or false when the SDK cannot be loaded.
	 */
	public static function get_platform_account(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()){
			return \Stripe\Stripe::$api_key;
		}
		return false;
	}

	/**
	 * Applies the configured platform secret key to the Stripe SDK.
	 *
	 * This is the platform-account bootstrap path used before account, payment,
	 * transfer, payout, and webhook operations.
	 *
	 * @return bool True when the SDK accepted the configured platform key.
	 */
	public static function set_platform_account(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()){
			\Stripe\Stripe::$api_key=self::get_secret_key();
			return true;
		}
		return false;
	}

	/**
	 * Selects the publishable Stripe key for the current runtime mode.
	 *
	 * @return string|false Live or test publishable key, or false when unconfigured.
	 */
	public static function get_publishable_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::test_mode()!==true){
			return self::cfg('api_publishable_key_live', false);
		}
		return self::cfg('api_publishable_key_test_mode', false);
	}

	/**
	 * Returns the webhook signing secret used to authenticate incoming events.
	 *
	 * @return string|false Webhook secret, or false when unconfigured.
	 */
	public static function get_webhook_secret_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		return self::cfg('webhook_secret_key', false);
	}

	/**
	 * Selects the secret Stripe key for the current runtime mode.
	 *
	 * @return string|false Live or test secret key, or false when unconfigured.
	 */
	public static function get_secret_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::test_mode()!==true){
			return self::cfg('api_secret_key_live', false);
		}
		return self::cfg('api_secret_key_test_mode', false);
	}

	/**
	 * Loads and configures the bundled Stripe SDK.
	 *
	 * A CALL_STRIPE_LOAD dialback may short-circuit SDK loading for tests or custom
	 * bootstraps. Normal loading requires the bundled SDK, sets the selected secret
	 * key, and enables network retries. Missing SDK or key state escalates through
	 * core::unavailable() because payment operations must fail closed.
	 *
	 * @return bool True when the Stripe SDK is available for calls.
	 */
	public static function load_stripe() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$early_return=core::dialback("CALL_STRIPE_LOAD",...func_get_args())) return $early_return;
		if(!class_exists("\Stripe\Stripe")){
			try{
				require_once(dirname(__DIR__)."/src/init.php");
			}catch(\Exception $e){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreStripe: Unable to load stripe library. Server\'s data is like corrupted.', 'safemode');
			}
			\Stripe\Stripe::$api_key=self::get_secret_key();
			\Stripe\Stripe::setMaxNetworkRetries(3);
		}
		else
		{
			if(empty(\Stripe\Stripe::$api_key)){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreStripe: Stripe API key not set.', 'safemode');
			}
		}
		return true;
	}

	/**
	 * Authenticates and dispatches an incoming Stripe webhook.
	 *
	 * The raw request body and Stripe signature header are verified with the
	 * configured webhook secret. Supported events are delegated to a project
	 * function named `stripe_webhook_{event_type}` with dots converted to
	 * underscores. Invalid signatures and unsupported events emit HTTP 400.
	 *
	 * @return mixed Callback result, false when platform setup fails, or void after emitting an HTTP response.
	 */
	public static function handle_webhook(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::set_platform_account()){
			if(self::load_stripe()===true){
				$signature=$_SERVER['HTTP_STRIPE_SIGNATURE'];
				$payload=file_get_contents('php://input');
				$webhook_secret_key=self::get_webhook_secret_key();
				try{
					$event=\Stripe\Webhook::constructEvent($payload,$signature,$webhook_secret_key);
				}catch(\Exception $e){
					http_response_code(400);
					echo 'Webhook Error: '.$e->getMessage();
					return;
				}
				$event_type=$event->type;
				$function_name='stripe_webhook_'.str_replace('.', '_', $event_type);
				if(function_exists($function_name)){
					return call_user_func($function_name, $event->data->object);
				}
				else
				{
					http_response_code(400);
					echo 'Unsupported webhook event type: '.$event_type;
					return;
				}
				http_response_code(200);
				echo 'Webhook Event Processed';
				exit();
			}
		}
		return false;
	}

	/**
	 * Retrieves the Stripe platform balance.
	 *
	 * @return \Stripe\Balance|false Stripe balance resource, or false on load/API failure.
	 */
	public static function get_platform_balance(){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$balance=\Stripe\Balance::retrieve();
				return $balance;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Persists and attaches a newly supplied Stripe payment method.
	 *
	 * The token is first checked against the local payment-method table to avoid
	 * duplicate inserts. A missing customer id may be created by callback. Local SQL
	 * metadata is rolled back when attachment or attached-state persistence fails.
	 *
	 * @param string $stripe_token Stripe PaymentMethod id from the client.
	 * @param int $userid Dataphyre user id owning the method.
	 * @param string $stripe_customer_id Stripe Customer id to attach to.
	 * @param string $name_on_card Cardholder name stored locally.
	 * @param callable|null $no_customer_account_callback Callback that can create a customer id.
	 * @return bool|string False on load failure, true on success, or a business failure marker.
	 */
	public static function handle_new_payment_method(string $stripe_token, int $userid, string $stripe_customer_id, string $name_on_card, ?callable $no_customer_account_callback=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			if(false===sql_select(
				$S="id",
				$L="stripe_payment_methods",
				$P="WHERE id=?",
				$V=array($stripe_token)
			)){
				if(false===$payment_method=self::retrieve_payment_method($stripe_token)){
					return 'bad_token';
				}
				if(empty($stripe_customer_id) && is_callable($no_customer_account_callback)){
					if(false===$stripe_customer_id=$no_customer_account_callback($userid, $payment_method)){
						return 'failed_customer_creation_callback';
					}
				}
				if(false===$insertid=sql_insert(
					$L="stripe_payment_methods",
					$F=[
						"id"=>$payment_method->id,
						"brand"=>$payment_method->card->brand,
						"type"=>$payment_method->type,
						"userid"=>$userid,
						"is_attached"=>false,
						"is_main"=>false,
						"country"=>$payment_method->card->country,
						"last_four_digits"=>$payment_method->card->last4,
						"postal_code"=>$payment_method->billing_details->address->postal_code,
						"expiration_month"=>$payment_method->card->exp_month,
						"expiration_year"=>$payment_method->card->exp_year,
						"name_on_card"=>$name_on_card
					]
				)){
					return 'failed_creating_method';
				}
				$result=self::attach_payment_method($stripe_token, $stripe_customer_id);
				if($result===false || is_string($result)){
					sql_delete(
						$L="stripe_payment_methods",
						$P="WHERE id=?",
						$V=array($stripe_token),
						$CC=true
					);
					return $result;
				}
				if($result->customer!==null){
					if(false===sql_update(
						$L="stripe_payment_methods",
						$F=[
							"mysql"=>"is_attached=1",
							"postgresql"=>"is_attached=true"
						],
						$P="WHERE id=?",
						$V=array($payment_method->id),
						$CC=true
					)){
						sql_delete(
							$L="stripe_payment_methods",
							$P="WHERE id=?",
							$V=array($stripe_token),
							$CC=true
						);
						return 'failed_attaching';
					}
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates a Stripe Customer linked to a Dataphyre user id.
	 *
	 * @param int $userid Dataphyre user id stored in Stripe metadata.
	 * @param string $email Customer email.
	 * @param string $name Customer display name.
	 * @return \Stripe\Customer|false Stripe customer resource, or false on load/API failure.
	 */
	public static function create_customer(int $userid, string $email, string $name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$customer=\Stripe\Customer::create([
					'email'=>$email,
					'name'=>$name,
					'metadata'=>['user_id'=>$userid]
				]);
				return $customer;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Creates a Stripe Connect account.
	 *
	 * @param array<string, mixed> $params Stripe Account creation parameters.
	 * @return \Stripe\Account|false Stripe account resource, or false on load/API failure.
	 */
    public static function create_account(array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account=\Stripe\Account::create($params);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
    }

	/**
	 * Updates a Connect account with verification payload fields.
	 *
	 * @param string $account_id Stripe Account id.
	 * @param array<string, mixed> $params Stripe Account update parameters.
	 * @return \Stripe\Account|false Updated account resource, or false on load/API failure.
	 */
	public static function verify_account(string $account_id, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account=\Stripe\Account::update($account_id, $params);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Adds an external bank account to a Connect account.
	 *
	 * @param string $account_id Stripe Account id.
	 * @param array<string, mixed> $params External account token or bank account parameters.
	 * @return \Stripe\BankAccount|\Stripe\Card|false Stripe external account resource, or false on load/API failure.
	 */
	public static function create_bank_account(string $account_id, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$bank_account=\Stripe\Account::createExternalAccount($account_id, ['external_account' => $params]);
				return $bank_account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Marks a Connect external account as the default payout destination.
	 *
	 * @param string $account_id Stripe Account id.
	 * @param string $bank_account_id External bank account id.
	 * @return \Stripe\Account|false Updated account resource, or false on load/API failure.
	 */
	public static function set_default_for_payouts(string $account_id, string $bank_account_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account=\Stripe\Account::update($account_id, ['default_for_currency' => $bank_account_id]);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Updates a Stripe Connect account with arbitrary account parameters.
	 *
	 * @param string $account_id Stripe Account id.
	 * @param array<string, mixed> $params Stripe Account update parameters.
	 * @return \Stripe\Account|false Updated account resource, or false on load/API failure.
	 */
	public static function update_account(string $account_id, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account=\Stripe\Account::update($account_id, $params);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Creates a Stripe PaymentIntent.
	 *
	 * @param array<string, mixed> $params Stripe PaymentIntent creation parameters.
	 * @return \Stripe\PaymentIntent|false PaymentIntent resource, or false on load/API failure.
	 */
    public static function create_payment_intent(array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::create($params);
				return $payment_intent;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
    }

	/**
	 * Retrieves the current status for a Stripe PaymentIntent.
	 *
	 * @param string $payment_intent_id Stripe PaymentIntent id.
	 * @return string|false PaymentIntent status, or false on load/API failure.
	 */
	public static function check_payment_status(string $payment_intent_id){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::retrieve($payment_intent_id);
				return $payment_intent->status;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Cancels a Stripe PaymentIntent.
	 *
	 * @param string $payment_intentId Stripe PaymentIntent id.
	 * @return \Stripe\PaymentIntent|false Cancelled PaymentIntent resource, or false on load/API failure.
	 */
	public static function cancel_payment(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::retrieve($payment_intentId);
				$payment_intent->cancel();
				return $payment_intent;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Creates an onboarding account link for a Connect account.
	 *
	 * @param string $account_id Stripe Account id.
	 * @param string $return_url URL Stripe redirects to after onboarding.
	 * @param string $refresh_url URL Stripe redirects to when the link expires.
	 * @return \Stripe\AccountLink|false AccountLink resource, or false on load/API failure.
	 */
	public static function create_account_link(string $account_id, string $return_url, string $refresh_url){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account_link=\Stripe\AccountLink::create([
				  'account'=>$account_id,
				  'refresh_url'=>$refresh_url,
				  'return_url'=>$return_url,
				  'type'=>'account_onboarding',
				]);
				return $account_link;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Retrieves a Stripe Connect account for status inspection.
	 *
	 * @param string $account_id Stripe Account id.
	 * @return \Stripe\Account|false Account resource, or false on load/API failure.
	 */
	public static function check_account_status(string $account_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$account = \Stripe\Account::retrieve($account_id);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Creates a Stripe transfer from the platform balance.
	 *
	 * @param array<string, mixed> $params Stripe Transfer creation parameters.
	 * @return \Stripe\Transfer|false Transfer resource, or false on load/API failure.
	 */
	public static function initiate_transfer(array $params){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$transfer=\Stripe\Transfer::create($params);
				return $transfer;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Creates a Stripe payout.
	 *
	 * @param array<string, mixed> $params Stripe Payout creation parameters.
	 * @param array<string, mixed> $options Stripe request options, such as connected account context.
	 * @return \Stripe\Payout|false Payout resource, or false on load/API failure.
	 */
	public static function create_payout(array $params, $options=[]){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payout=\Stripe\Payout::create($params, $options);
				return $payout;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Confirms a Stripe PaymentIntent.
	 *
	 * @param string $payment_intentId Stripe PaymentIntent id.
	 * @return \Stripe\PaymentIntent|false Confirmed PaymentIntent resource, or false on load/API failure.
	 */
	public static function submit_payment(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::retrieve($payment_intentId);
				$payment_intent->confirm();
				return $payment_intent;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Refunds part of a PaymentIntent's first charge after local limit checking.
	 *
	 * The wrapper compares the requested amount against Stripe's original and
	 * already-refunded charge amounts before creating the refund, preventing an
	 * over-refund call from leaving Dataphyre.
	 *
	 * @param string $payment_intent_id Stripe PaymentIntent id.
	 * @param int $amount_to_refund Amount in the smallest currency unit.
	 * @return \Stripe\Refund|false Refund resource, or false on validation/load/API failure.
	 */
	public static function submit_refund(string $payment_intent_id, int $amount_to_refund){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::retrieve($payment_intent_id);
				$charge_id=$payment_intent->charges->data[0]->id;
				$original_amount=$payment_intent->charges->data[0]->amount;
				$already_refunded=$payment_intent->charges->data[0]->amount_refunded;
				$remaining_refundable_amount=$original_amount-$already_refunded;
				if($amount_to_refund>$remaining_refundable_amount){
					log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: Refund amount greater than remaining refundable amount.');
					return false;
				}
				$refund=\Stripe\Refund::create([
					'charge'=>$charge_id,
					'amount'=>$amount_to_refund,
				]);
				return $refund;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Detaches a Stripe payment method and removes its local metadata row.
	 *
	 * Local deletion is attempted after the Stripe detach call path, keeping the
	 * Dataphyre payment-method table aligned with the remote customer attachment
	 * state as closely as this legacy wrapper permits.
	 *
	 * @param string $payment_method_id Stripe PaymentMethod id.
	 * @return bool True after local delete is attempted, false on SDK load failure.
	 */
	public static function delete_payment_method(string $payment_method_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_method=\Stripe\PaymentMethod::retrieve($payment_method_id);
				$payment_method->detach();
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
			sql_delete(
				$L="stripe_payment_methods",
				$P="WHERE id=?",
				$V=array($payment_method_id),
				$CC=true
			);
			return true;
		}
		return false;
	}

	/**
	 * Retrieves a Stripe PaymentMethod.
	 *
	 * @param string $payment_method_id Stripe PaymentMethod id.
	 * @return \Stripe\PaymentMethod|false PaymentMethod resource, or false on load/API failure.
	 */
	public static function retrieve_payment_method(string $payment_method_id){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
		try{
		  $payment_method=\Stripe\PaymentMethod::retrieve($payment_method_id);
		  return $payment_method;
		}catch(\Exception $e){
		  log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
		}
	  }
	  return false;
	}

	/**
	 * Retrieves a Stripe PaymentIntent.
	 *
	 * @param string $payment_intentId Stripe PaymentIntent id.
	 * @return \Stripe\PaymentIntent|false PaymentIntent resource, or false on load/API failure.
	 */
	public static function retrieve_payment_intent(string $payment_intentId){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
		try{
		  $payment_intent=\Stripe\PaymentIntent::retrieve($payment_intentId);
		  return $payment_intent;
		}catch(\Exception $e){
		  log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
		}
	  }
	  return false;
	}

	/**
	 * Captures a previously authorized Stripe PaymentIntent.
	 *
	 * @param string $payment_intentId Stripe PaymentIntent id.
	 * @return \Stripe\PaymentIntent|false Captured PaymentIntent resource, or false on load/API failure.
	 */
	public static function capture_payment_intent(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_intent=\Stripe\PaymentIntent::retrieve($payment_intentId);
				$payment_intent->capture();
				return $payment_intent;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Lists card payment methods attached to a Stripe Customer.
	 *
	 * @param string $customer_id Stripe Customer id.
	 * @return \Stripe\Collection|false PaymentMethod collection, or false on load/API failure.
	 */
	public static function retrieve_all_payment_methods(string $customer_id){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
	  if(self::load_stripe()===true){
		try{
		  $payment_methods=\Stripe\PaymentMethod::all([
			'customer' => $customer_id,
			'type'=>'card',
		  ]);
		  return $payment_methods;
		}catch(\Exception $e){
		  log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
		}
	  }
	  return false;
	}

	/**
	 * Attaches a Stripe PaymentMethod to a Customer.
	 *
	 * Card declines are converted into the `card_declined` marker so callers that
	 * already persisted local payment-method metadata can roll it back without
	 * parsing Stripe exceptions.
	 *
	 * @param string $payment_method_id Stripe PaymentMethod id.
	 * @param string $customer_id Stripe Customer id.
	 * @return \Stripe\PaymentMethod|string|false Attached PaymentMethod, `card_declined`, or false on load/API failure.
	 */
	public static function attach_payment_method(string $payment_method_id, string $customer_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(self::load_stripe()===true){
			try{
				$payment_method=\Stripe\PaymentMethod::retrieve($payment_method_id);
				$payment_method->attach(['customer'=>$customer_id]);
				return $payment_method;
			}catch(\Stripe\Exception\CardException $e){
				return 'card_declined';
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

}
