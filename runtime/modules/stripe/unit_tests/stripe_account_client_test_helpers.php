<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once __DIR__.'/../kernel/stripe.account_client.php';
require_once dirname(__DIR__,2).'/testing/tooling/TypeInventory.php';

function dp_stripe_account_client_api_contract_json(): string {
	$inventory=\Dataphyre\Test\TypeInventory::of(\dataphyre\stripe_account_client::class);
	$methods=[];
	foreach([
		'readiness',
		'create_customer',
		'update_customer',
		'retrieve_customer',
		'delete_customer',
		'create_setup_intent',
		'retrieve_setup_intent',
		'cancel_setup_intent',
		'retrieve_payment_method',
		'detach_payment_method',
		'create_payment_intent',
		'retrieve_payment_intent',
		'construct_webhook_event',
	] as $name){
		$method=$inventory->method($name);
		$methods[$name]=$method->getNumberOfParameters();
	}
	return json_encode([
		'final'=>$inventory->isFinal(),
		'methods'=>$methods,
	], JSON_UNESCAPED_SLASHES);
}

function dp_stripe_account_client_readiness_json(): string {
	$client=new \dataphyre\stripe_account_client(
		'sk_test_'.str_repeat('a', 24),
		static fn()=>null,
	);
	return json_encode($client->readiness(), JSON_UNESCAPED_SLASHES);
}

function dp_stripe_account_client_invalid_keys_json(): string {
	$rejected=0;
	foreach(['', 'pk_test_'.str_repeat('a', 24), 'sk_test_short', 'sk_stage_'.str_repeat('a', 24)] as $key){
		try{
			new \dataphyre\stripe_account_client($key);
		}catch(InvalidArgumentException){
			$rejected++;
		}
	}
	return json_encode(['rejected'=>$rejected], JSON_UNESCAPED_SLASHES);
}
