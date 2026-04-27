<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'dataphyre'=>[
		'supercookie'=>[
			'test_mode'=>true,
			'webhook_secret_key'=>getenv('SUPERCOOKIE_WEBHOOK_SECRET') ?: '',
			'api_secret_key_live'=>getenv('SUPERCOOKIE_SECRET_KEY_LIVE') ?: '',
			'api_publishable_key_live'=>getenv('SUPERCOOKIE_PUBLISHABLE_KEY_LIVE') ?: '',
			'api_secret_key_test_mode'=>getenv('SUPERCOOKIE_SECRET_KEY_TEST') ?: '',
			'api_publishable_key_test_mode'=>getenv('SUPERCOOKIE_PUBLISHABLE_KEY_TEST') ?: '',
		],
	],
];
