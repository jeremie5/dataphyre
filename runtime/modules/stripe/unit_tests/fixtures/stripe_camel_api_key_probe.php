<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Stripe {
	final class Stripe {
		public static string $apiKey='';
		public static int $maxNetworkRetries=0;
		public static function setMaxNetworkRetries(int $retries): void {
			self::$maxNetworkRetries=$retries;
		}
	}
}

namespace {
	define('DATAPHYRE_STRIPE_NO_DISPATCH', true);
	require_once dirname(__DIR__, 2).'/kernel/stripe.main.php';
	\dataphyre\stripe::resetRuntime([
		'config'=>['test_mode'=>false,'api_secret_key_live'=>'sk_camel'],
		'trace'=>static fn(): null=>null,
	]);
	$loaded=\dataphyre\stripe::load_stripe();
	echo json_encode([
		'loaded'=>$loaded,
		'account'=>\dataphyre\stripe::get_platform_account(),
		'stored'=>\Stripe\Stripe::$apiKey,
	], JSON_THROW_ON_ERROR);
}
