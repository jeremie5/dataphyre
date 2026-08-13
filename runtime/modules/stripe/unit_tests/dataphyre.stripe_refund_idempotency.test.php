<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_STRIPE_NO_DISPATCH')){
	define('DATAPHYRE_STRIPE_NO_DISPATCH', true);
}

require_once dirname(__DIR__).'/kernel/stripe.main.php';

test('Stripe refund forwards an optional idempotency key without changing legacy calls', static function(Context $t): void {
	$operations=[];
	$intent=(object)[
		'charges'=>(object)[
			'data'=>[(object)[
				'id'=>'ch_refund_contract',
				'amount'=>5000,
				'amount_refunded'=>0,
			]],
		],
	];
	$reset=static function() use (&$operations, $intent): void {
		$operations=[];
		\dataphyre\stripe::resetRuntime([
			'load'=>static fn(): bool=>true,
			'trace'=>static fn(mixed ...$arguments): null=>null,
			'log'=>static fn(string $message): null=>null,
			'execute'=>static function(string $operation, array $arguments) use (&$operations, $intent): object {
				$operations[]=[
					'operation'=>$operation,
					'arguments'=>$arguments,
				];
				return $operation==='payment_intent.retrieve'
					? $intent
					: (object)['id'=>'rf_refund_contract'];
			},
		]);
	};

	$reset();
	$idempotent=\dataphyre\stripe::submit_refund(
		'pi_refund_contract',
		3600,
		['idempotency_key'=>'buyer-order-cancellation:501']
	);
	$t->same('rf_refund_contract', $idempotent->id ?? null);
	$t->same([
		[
			'charge'=>'ch_refund_contract',
			'amount'=>3600,
		],
		[
			'idempotency_key'=>'buyer-order-cancellation:501',
		],
	], $operations[1]['arguments'] ?? null);

	$reset();
	\dataphyre\stripe::submit_refund('pi_refund_contract', 3600);
	$t->same([[
		'charge'=>'ch_refund_contract',
		'amount'=>3600,
	]], $operations[1]['arguments'] ?? null);
})->tag('stripe', 'refunds', 'idempotency');
