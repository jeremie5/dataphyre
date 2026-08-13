<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class core {
		public static int $currency_load_calls=0;

		public static function load_framework_module(string $module): bool {
			self::$currency_load_calls++;
			return false;
		}
	}
}

namespace {
	use Dataphyre\Database\CurrencyBridge;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dp_currency_bridge_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $dp_currency_bridge_modules_root.'/sql/Framework/SqlError.php';
	require_once $dp_currency_bridge_modules_root.'/sql/Framework/CurrencyBridge.php';

	test('currency bridge deep missing currency dependency reports the SQL framework error', static function(Context $t): void {
		$autoloaders=spl_autoload_functions() ?: [];
		foreach($autoloaders as $autoloader){
			spl_autoload_unregister($autoloader);
		}
		try{
			$t->throws(static fn()=>CurrencyBridge::money(1, 'USD'), RuntimeException::class);
			$t->same(1, \dataphyre\core::$currency_load_calls);
		}finally{
			foreach($autoloaders as $autoloader){
				spl_autoload_register($autoloader);
			}
		}
	})->tag('sql', 'currency', 'coverage')->group('framework-coverage');
}
