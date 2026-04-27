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

class stripe {

	private static function test_mode(): bool {
		if(defined('DP_STRIPE_TEST_MODE_OVERRIDE') && DP_STRIPE_TEST_MODE_OVERRIDE===true){
			return true;
		}
		return (DP_STRIPE_CFG['test_mode'] ?? false)===true;
	}
	
	public static function get_platform_account(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::load_stripe()){
			return \Stripe\Stripe::$apiKey;
		}
		return false;
	}
	
	public static function set_platform_account(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::load_stripe()){
			\Stripe\Stripe::$apiKey=self::get_secret_key();
			return true;
		}
		return false;
	}
	
	public static function get_publishable_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::test_mode()!==true){
			return DP_STRIPE_CFG["api_publishable_key_live"] ?? false;
		}
		return DP_STRIPE_CFG["api_publishable_key_test_mode"] ?? false;
	}
	
	public static function get_webhook_secret_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		return DP_STRIPE_CFG["webhook_secret_key"] ?? false;
	}
	
	public static function get_secret_key() : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::test_mode()!==true){
			return DP_STRIPE_CFG["api_secret_key_live"] ?? false;
		}
		return DP_STRIPE_CFG["api_secret_key_test_mode"] ?? false;
	}
	
	public static function load_stripe() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_LOAD_STRIPE",...func_get_args())) return $early_return;
		if(!class_exists("\Stripe\Stripe")){
			try{
				require_once(dirname(__DIR__)."/src/init.php");
			}catch(\Exception $e){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreStripe: Unable to load stripe library. Server\'s data is like corrupted.', 'safemode');
			}
			\Stripe\Stripe::$apiKey=self::get_secret_key();
			\Stripe\Stripe::setMaxNetworkRetries(3);
		}
		else
		{
			if(empty(\Stripe\Stripe::$apiKey)){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreStripe: Stripe API key not set.', 'safemode');
			}
		}
		return true;
	}
	
	public static function handle_webhook(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function get_platform_balance(){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function handle_new_payment_method(string $stripe_token, int $userid, string $stripe_customer_id, string $name_on_card, ?callable $no_customer_account_callback=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function create_customer(int $userid, string $email, string $name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
    public static function create_account(array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function verify_account(string $account_id, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function create_bank_account(string $account_id, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function set_default_for_payouts(string $account_id, string $bank_account_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function update_account(string $accountId, array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::load_stripe()===true){
			try{
				$account=\Stripe\Account::update($accountId, $params);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}

    public static function create_payment_intent(array $params){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function check_payment_status(string $payment_intent_id){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function cancel_payment(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function create_account_link(string $accountId, string $return_url, string $refresh_url){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::load_stripe()===true){
			try{
				$accountLink=\Stripe\AccountLink::create([
				  'account'=>$accountId,
				  'refresh_url'=>$refresh_url,
				  'return_url'=>$return_url,
				  'type'=>'account_onboarding',
				]);
				return $accountLink;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}
	
	public static function check_account_status(string $accountId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::load_stripe()===true){
			try{
				$account = \Stripe\Account::retrieve($accountId);
				return $account;
			}catch(\Exception $e){
				log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
			}
		}
		return false;
	}
	
	public static function initiate_transfer(array $params){
		tracelog( __FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function create_payout(array $params, $options=[]){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function submit_payment(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function submit_refund(string $payment_intent_id, $amount_to_refund){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function delete_payment_method(string $payment_method_id){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function retrieve_payment_method(string $payment_method_id){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function retrieve_payment_intent(string $payment_intentId){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function capture_payment_intent(string $payment_intentId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function retrieve_all_payment_methods(string $customerId){
	  tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
	  if(self::load_stripe()===true){
		try{
		  $payment_methods=\Stripe\PaymentMethod::all([
			'customer' => $customerId,
			'type'=>'card',
		  ]);
		  return $payment_methods;
		}catch(\Exception $e){
		  log_error('DataphyreStripe: '.__CLASS__.'/'.__FUNCTION__.'(): Error: '.$e->getMessage());
		}
	  }
	  return false;
	}

	public static function attach_payment_method(string $payment_method_id, string $customerId){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::load_stripe()===true){
			try{
				$payment_method=\Stripe\PaymentMethod::retrieve($payment_method_id);
				$payment_method->attach(['customer'=>$customerId]);
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
