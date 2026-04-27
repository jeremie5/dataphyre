<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'dataphyre'=>[
		'stripe'=>[
			'test_mode'=>true,
			'webhook_secret_key'=>getenv('STRIPE_WEBHOOK_SECRET') ?: '',
			'api_secret_key_live'=>getenv('STRIPE_SECRET_KEY_LIVE') ?: '',
			'api_publishable_key_live'=>getenv('STRIPE_PUBLISHABLE_KEY_LIVE') ?: '',
			'api_secret_key_test_mode'=>getenv('STRIPE_SECRET_KEY_TEST') ?: '',
			'api_publishable_key_test_mode'=>getenv('STRIPE_PUBLISHABLE_KEY_TEST') ?: '',
			'payment_intent_minimum_amount'=>[
				'USD'=>0.5,
				'CAD'=>0.5,
				'EUR'=>0.5,
				'GBP'=>0.3,
			],
		],
	],
];
