<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Account-isolated Stripe client for application-owned payment domains.
 *
 * Unlike the legacy static stripe facade, this boundary never writes the
 * account key to Stripe's process-global configuration. Each instance owns a
 * validated key and a lazy StripeClient, or delegates through an injected
 * executor/client seam for deterministic tests and custom transports.
 */
final class stripe_account_client {
	private string $secret_key;
	private ?\Closure $executor;
	private ?object $client;
	/** @var array<string, mixed> */
	private array $request_options;

	/**
	 * @param array<string, mixed> $request_options Stripe request options shared by calls.
	 */
	public function __construct(
		string $secret_key,
		?callable $executor=null,
		?object $client=null,
		array $request_options=[],
	) {
		$metadata=self::key_metadata($secret_key);
		if($metadata===null){
			throw new \InvalidArgumentException('Stripe account key is missing or invalid.');
		}
		if($executor!==null && $client!==null){
			throw new \InvalidArgumentException('Stripe account client accepts either an executor or a client, not both.');
		}
		$this->secret_key=trim($secret_key);
		$this->executor=$executor===null ? null : \Closure::fromCallable($executor);
		$this->client=$client;
		$this->request_options=self::normalize_request_options($request_options);
	}

	/**
	 * Returns local configuration readiness without contacting Stripe.
	 *
	 * @return array<string, bool|string>
	 */
	public function readiness(): array {
		$metadata=self::key_metadata($this->secret_key);
		$sdk_available=$this->sdk_available();
		return [
			'ready'=>$metadata!==null && $sdk_available,
			'configured'=>true,
			'valid'=>$metadata!==null,
			'mode'=>(string)($metadata['mode'] ?? 'unknown'),
			'key_type'=>(string)($metadata['key_type'] ?? 'unknown'),
			'sdk_available'=>$sdk_available,
			'network_checked'=>false,
			'executor_injected'=>$this->executor!==null,
			'client_injected'=>$this->client!==null,
		];
	}

	/** Resolves SDK availability through injectable probes without changing the public readiness API. */
	private function sdk_available(?callable $class_probe=null, ?callable $file_probe=null): bool {
		$class_probe??=static fn(string $class): bool=>class_exists($class, false);
		$file_probe??=static fn(string $path): bool=>is_file($path);
		return $this->executor!==null
			|| $this->client!==null
			|| $class_probe('\\Stripe\\StripeClient')
			|| $file_probe(dirname(__DIR__).'/src/init.php');
	}

	/** @param array<string, mixed> $params */
	public function create_customer(array $params, string $idempotency=''): mixed {
		return $this->execute('customers.create', [$params], $this->options($idempotency));
	}

	/** @param array<string, mixed> $params */
	public function update_customer(string $customer_id, array $params, string $idempotency=''): mixed {
		self::require_identifier($customer_id, 'cus', 'customer');
		return $this->execute('customers.update', [$customer_id, $params], $this->options($idempotency));
	}

	public function retrieve_customer(string $customer_id): mixed {
		self::require_identifier($customer_id, 'cus', 'customer');
		return $this->execute('customers.retrieve', [$customer_id], $this->options());
	}

	/**
	 * Deletes an account-owned Customer and returns only deletion evidence.
	 *
	 * Stripe owns replay semantics for the supplied idempotency key. This
	 * boundary deliberately neither caches nor manufactures a successful
	 * deletion when the provider response cannot prove it.
	 *
	 * @return array{id:string,deleted:true}
	 */
	public function delete_customer(string $id, string $idempotency_key): array {
		self::require_identifier($id, 'cus', 'customer');
		$response=$this->execute('customers.delete', [$id], $this->required_idempotency_options($idempotency_key));
		$resource=self::cleanup_resource($response);
		self::require_cleanup_identifier($resource, $id, 'cus', 'customer');
		if(($resource['deleted'] ?? null)!==true){
			throw self::invalid_cleanup_response();
		}
		return ['id'=>$id, 'deleted'=>true];
	}

	/** @param array<string, mixed> $params */
	public function create_setup_intent(array $params, string $idempotency=''): mixed {
		if(!array_key_exists('usage', $params)){
			$params['usage']='off_session';
		}
		return $this->execute('setup_intents.create', [$params], $this->options($idempotency));
	}

	public function retrieve_setup_intent(string $setup_intent_id): mixed {
		self::require_identifier($setup_intent_id, 'seti', 'setup intent');
		return $this->execute('setup_intents.retrieve', [$setup_intent_id], $this->options());
	}

	/**
	 * Cancels an account-owned SetupIntent and projects a non-secret result.
	 *
	 * @param array<string, mixed> $params
	 * @return array{id:string,status:string,customer:?string,payment_method:?string,cancellation_reason:?string}
	 */
	public function cancel_setup_intent(string $id, array $params, string $idempotency_key): array {
		self::require_identifier($id, 'seti', 'setup intent');
		$params=self::normalize_setup_intent_cancel_params($params);
		$response=$this->execute('setup_intents.cancel', [$id, $params], $this->required_idempotency_options($idempotency_key));
		$resource=self::cleanup_resource($response);
		self::require_cleanup_identifier($resource, $id, 'seti', 'setup intent');
		if(($resource['status'] ?? null)!=='canceled'){
			throw self::invalid_cleanup_response();
		}
		$reason=$resource['cancellation_reason'] ?? null;
		if($reason!==null && (!is_string($reason) || !in_array($reason, ['abandoned', 'requested_by_customer', 'duplicate'], true))){
			throw self::invalid_cleanup_response();
		}
		$expected_reason=$params['cancellation_reason'] ?? null;
		if($expected_reason!==null && (!is_string($reason) || !hash_equals($expected_reason, $reason))){
			throw self::invalid_cleanup_response();
		}
		return [
			'id'=>$id,
			'status'=>'canceled',
			'customer'=>self::cleanup_reference($resource['customer'] ?? null, 'cus', 'customer'),
			'payment_method'=>self::cleanup_reference($resource['payment_method'] ?? null, 'pm', 'payment method'),
			'cancellation_reason'=>$reason,
		];
	}

	public function retrieve_payment_method(string $payment_method_id): mixed {
		self::require_identifier($payment_method_id, 'pm', 'payment method');
		return $this->execute('payment_methods.retrieve', [$payment_method_id], $this->options());
	}

	/** @return array{id:string,customer:null} */
	public function detach_payment_method(string $id, string $idempotency_key): array {
		self::require_identifier($id, 'pm', 'payment method');
		$response=$this->execute('payment_methods.detach', [$id], $this->required_idempotency_options($idempotency_key));
		$resource=self::cleanup_resource($response);
		self::require_cleanup_identifier($resource, $id, 'pm', 'payment method');
		if(!array_key_exists('customer', $resource) || ($resource['customer']!==null && $resource['customer']!=='')){
			throw self::invalid_cleanup_response();
		}
		return ['id'=>$id, 'customer'=>null];
	}

	/**
	 * Creates and confirms an off-session PaymentIntent.
	 *
	 * @param array<string, mixed> $params
	 */
	public function create_payment_intent(array $params, string $idempotency=''): mixed {
		if(array_key_exists('off_session', $params) && $params['off_session']!==true){
			throw new \InvalidArgumentException('Stripe account PaymentIntents must be off-session.');
		}
		if(array_key_exists('confirm', $params) && $params['confirm']!==true){
			throw new \InvalidArgumentException('Stripe account off-session PaymentIntents must be confirmed.');
		}
		$params['off_session']=true;
		$params['confirm']=true;
		return $this->execute('payment_intents.create', [$params], $this->options($idempotency));
	}

	public function retrieve_payment_intent(string $payment_intent_id): mixed {
		self::require_identifier($payment_intent_id, 'pi', 'payment intent');
		return $this->execute('payment_intents.retrieve', [$payment_intent_id], $this->options());
	}

	/**
	 * Verifies and constructs a Stripe event locally; no network request occurs.
	 */
	public function construct_webhook_event(string $payload, string $signature, string $webhook_secret): mixed {
		if($payload===''){
			throw new \InvalidArgumentException('Stripe webhook payload is missing.');
		}
		if(preg_match('/^whsec_[A-Za-z0-9]{16,}$/D', trim($webhook_secret))!==1){
			throw new \InvalidArgumentException('Stripe webhook secret is missing or invalid.');
		}
		$signature=trim($signature);
		if(
			preg_match('/(?:^|,)t=\d+(?:,|$)/', $signature)!==1
			|| preg_match('/(?:^|,)v1=[a-f0-9]{64}(?:,|$)/i', $signature)!==1
		){
			throw new \InvalidArgumentException('Stripe webhook signature is missing or invalid.');
		}
		if($this->executor!==null){
			return ($this->executor)('webhooks.construct_event', [$payload, $signature, trim($webhook_secret)], []);
		}
		self::load_sdk('\Stripe\Webhook');
		return \Stripe\Webhook::constructEvent($payload, $signature, trim($webhook_secret));
	}

	/**
	 * @param array<int, mixed> $arguments
	 * @param array<string, mixed> $options
	 */
	private function execute(string $operation, array $arguments, array $options): mixed {
		if($this->executor!==null){
			return ($this->executor)($operation, $arguments, $options);
		}
		return $this->execute_with_client($operation, $arguments, $options);
	}

	/**
	 * @param array<int, mixed> $arguments
	 * @param array<string, mixed> $options
	 */
	private function execute_with_client(string $operation, array $arguments, array $options): mixed {
		[$service_name, $method]=match($operation){
			'customers.create'=>['customers', 'create'],
			'customers.update'=>['customers', 'update'],
			'customers.retrieve'=>['customers', 'retrieve'],
			'customers.delete'=>['customers', 'delete'],
			'setup_intents.create'=>['setupIntents', 'create'],
			'setup_intents.retrieve'=>['setupIntents', 'retrieve'],
			'setup_intents.cancel'=>['setupIntents', 'cancel'],
			'payment_methods.retrieve'=>['paymentMethods', 'retrieve'],
			'payment_methods.detach'=>['paymentMethods', 'detach'],
			'payment_intents.create'=>['paymentIntents', 'create'],
			'payment_intents.retrieve'=>['paymentIntents', 'retrieve'],
			default=>throw new \LogicException('Unsupported Stripe account operation.'),
		};
		$client=$this->stripe_client();
		if(property_exists($client, $service_name)){
			$service=$client->{$service_name};
		}
		elseif(method_exists($client, 'getService')){
			$service=$client->getService($service_name);
		}
		else
		{
			throw new \RuntimeException('Stripe account client service is unavailable.');
		}
		if(!is_object($service) || !is_callable([$service, $method])){
			throw new \RuntimeException('Stripe account client operation is unavailable.');
		}
		if(in_array($method, ['retrieve', 'delete', 'detach'], true)){
			return $service->{$method}($arguments[0], [], $options);
		}
		if(in_array($method, ['update', 'cancel'], true)){
			return $service->{$method}($arguments[0], $arguments[1], $options);
		}
		return $service->{$method}($arguments[0], $options);
	}

	private function stripe_client(): object {
		if($this->client!==null){
			return $this->client;
		}
		self::load_sdk('\Stripe\StripeClient');
		$this->client=new \Stripe\StripeClient($this->secret_key);
		return $this->client;
	}

	private static function load_sdk(string $required_class, ?string $bootstrap=null): void {
		if(class_exists($required_class, false)){
			return;
		}
		$bootstrap=$bootstrap ?? dirname(__DIR__).'/src/init.php';
		if(!is_file($bootstrap)){
			throw new \RuntimeException('Stripe SDK bootstrap is unavailable.');
		}
		require_once $bootstrap;
		if(!class_exists($required_class, false)){
			throw new \RuntimeException('Required Stripe SDK component is unavailable.');
		}
	}

	/** @return array<string, mixed> */
	private function options(string $idempotency=''): array {
		$options=$this->request_options;
		if($idempotency===''){
			return $options;
		}
		$options['idempotency_key']=self::normalize_idempotency($idempotency);
		return $options;
	}

	/** @return array<string, mixed> */
	private function required_idempotency_options(string $idempotency_key): array {
		$options=$this->request_options;
		$options['idempotency_key']=self::normalize_idempotency($idempotency_key);
		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function normalize_request_options(array $options): array {
		$allowed=['idempotency_key'=>true, 'stripe_account'=>true, 'stripe_version'=>true];
		foreach($options as $key=>$value){
			if(!is_string($key) || !isset($allowed[$key])){
				throw new \InvalidArgumentException('Unsupported Stripe account request option.');
			}
			if($key==='idempotency_key'){
				if(!is_string($value)){
					throw new \InvalidArgumentException('Stripe idempotency key must be a string.');
				}
				$options[$key]=self::normalize_idempotency($value);
			}
			elseif($key==='stripe_account'){
				if(!is_string($value) || strlen($value)>255 || preg_match('/^acct_[A-Za-z0-9]{8,}$/D', $value)!==1){
					throw new \InvalidArgumentException('Stripe connected account request option is invalid.');
				}
			}
			elseif(!is_string($value) || $value==='' || strlen($value)>128 || preg_match('/[\x00-\x1F\x7F]/', $value)===1){
				throw new \InvalidArgumentException('Stripe version request option is invalid.');
			}
		}
		return $options;
	}

	private static function normalize_idempotency(string $idempotency): string {
		if(
			$idempotency==='' || strlen($idempotency)>255
			|| trim($idempotency)!==$idempotency
			|| preg_match('/[\x00-\x1F\x7F]/', $idempotency)===1
		){
			throw new \InvalidArgumentException('Stripe idempotency key is invalid.');
		}
		return $idempotency;
	}

	private static function require_identifier(string $identifier, string $prefix, string $label): void {
		if(strlen($identifier)>255 || preg_match('/^'.preg_quote($prefix, '/').'_[A-Za-z0-9]{6,}$/D', $identifier)!==1){
			throw new \InvalidArgumentException('Stripe '.$label.' identifier is invalid.');
		}
	}

	/** @param array<string, mixed> $params @return array<string, string> */
	private static function normalize_setup_intent_cancel_params(array $params): array {
		foreach($params as $key=>$value){
			if($key!=='cancellation_reason'){
				throw new \InvalidArgumentException('Unsupported Stripe SetupIntent cancellation parameter.');
			}
			if(!is_string($value) || !in_array($value, ['abandoned', 'requested_by_customer', 'duplicate'], true)){
				throw new \InvalidArgumentException('Stripe SetupIntent cancellation reason is invalid.');
			}
		}
		return $params;
	}

	/** @return array<string, mixed> */
	private static function cleanup_resource(mixed $resource): array {
		if(is_array($resource)){
			return $resource;
		}
		if(!is_object($resource)){
			throw self::invalid_cleanup_response();
		}
		if(method_exists($resource, 'toArray')){
			try{
				$resource=$resource->toArray();
			}catch(\Throwable){
				throw self::invalid_cleanup_response();
			}
			if(!is_array($resource)){
				throw self::invalid_cleanup_response();
			}
			return $resource;
		}
		return get_object_vars($resource);
	}

	/** @param array<string, mixed> $resource */
	private static function require_cleanup_identifier(
		array $resource,
		string $expected,
		string $prefix,
		string $label,
	): void {
		$actual=$resource['id'] ?? null;
		if(!is_string($actual)){
			throw self::invalid_cleanup_response();
		}
		try{
			self::require_identifier($actual, $prefix, $label);
		}catch(\InvalidArgumentException){
			throw self::invalid_cleanup_response();
		}
		if(!hash_equals($expected, $actual)){
			throw self::invalid_cleanup_response();
		}
	}

	private static function cleanup_reference(mixed $reference, string $prefix, string $label): ?string {
		if($reference===null || $reference===''){
			return null;
		}
		if(is_array($reference) || is_object($reference)){
			$reference=self::cleanup_resource($reference)['id'] ?? null;
		}
		if(!is_string($reference)){
			throw self::invalid_cleanup_response();
		}
		try{
			self::require_identifier($reference, $prefix, $label);
		}catch(\InvalidArgumentException){
			throw self::invalid_cleanup_response();
		}
		return $reference;
	}

	private static function invalid_cleanup_response(): \RuntimeException {
		return new \RuntimeException('Stripe account cleanup response is invalid.');
	}

	/** @return array{mode:string,key_type:string}|null */
	private static function key_metadata(string $secret_key): ?array {
		$secret_key=trim($secret_key);
		if(preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{16,}$/D', $secret_key, $matches)!==1){
			return null;
		}
		return [
			'mode'=>$matches[2],
			'key_type'=>$matches[1]==='rk' ? 'restricted' : 'secret',
		];
	}
}
