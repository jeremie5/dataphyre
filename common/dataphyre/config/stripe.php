<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */


dataphyre\core::add_config(array(
	'dataphyre'=>array(
		'stripe'=>array(
			'test_mode'=>True,
			'platform_accounts'=>array(
				'DEFAULT'=>array(
					'webhook_secret_key'=>"",
					'api_secret_key_live'=>"",
					'api_publishable_key_live'=>"",
					'api_secret_key_test_mode'=>"",
					'api_publishable_key_test_mode'=>"",
				)
			),
			'payment_intent_minimum_amount'=>array(
				'USD'=>0.5,
				'AED'=>2,
				'AUD'=>0.5,
				'BGN'=>1,
				'BRL'=>0.5,
				'CAD'=>0.5,
				'CHF'=>0.5,
				'CZK'=>15,
				'DKK'=>2.5,
				'EUR'=>0.5,
				'GBP'=>0.3,
				'HKD'=>4,
				'HRK'=>0.5,
				'HUF'=>175,
				'INR'=>0.5,
				'JPY'=>50,
				'MXN'=>10,
				'MYR'=>2,
				'NOK'=>3,
				'NZD'=>0.5,
				'PLN'=>2,
				'RON'=>2,
				'SEK'=>3,
				'SGD'=>0.5,
				'THB'=>10
			)
		)
	),
));