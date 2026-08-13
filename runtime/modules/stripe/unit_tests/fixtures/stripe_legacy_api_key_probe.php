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
		public static string $api_key='';
		public static int $maxNetworkRetries=0;
		public static function setMaxNetworkRetries(int $retries): void {
			self::$maxNetworkRetries=$retries;
		}
	}
}

namespace dataphyre {
	final class core {
		public static array $unavailable=[];
		public static function dialback(string $name, mixed ...$arguments): null {
			return null;
		}
		public static function unavailable(mixed ...$arguments): void {
			self::$unavailable[]=$arguments;
		}
	}
}

namespace {
	define('DATAPHYRE_STRIPE_NO_DISPATCH', true);
	require_once dirname(__DIR__, 2).'/kernel/stripe.main.php';
	$config=['test_mode'=>false,'api_secret_key_live'=>'sk_legacy'];
	\dataphyre\stripe::resetRuntime(['config'=>$config,'trace'=>static fn(): null=>null]);
	$loaded=\dataphyre\stripe::load_stripe();
	$account=\dataphyre\stripe::get_platform_account();
	\dataphyre\stripe::resetRuntime(['config'=>['test_mode'=>false,'api_secret_key_live'=>false],'trace'=>static fn(): null=>null]);
	$missing=\dataphyre\stripe::load_stripe();
	echo json_encode([
		'loaded'=>$loaded,
		'account'=>$account,
		'retries'=>\Stripe\Stripe::$maxNetworkRetries,
		'missing'=>$missing,
		'unavailable'=>count(\dataphyre\core::$unavailable),
	], JSON_THROW_ON_ERROR);
}
