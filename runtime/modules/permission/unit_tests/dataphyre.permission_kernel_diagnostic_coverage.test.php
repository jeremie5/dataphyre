<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void { if(!defined($constant)){ define($constant,$defaults); } }
	}
	if(!function_exists('sql_define_table')){ function sql_define_table(mixed ...$arguments): void {} }
	if(!defined('RUN_MODE')){ define('RUN_MODE','diagnostic'); }
	if(!defined('DP_PERMISSION_CFG')){
		define('DP_PERMISSION_CFG',[
			'roles'=>'invalid','aliases'=>'invalid','conditions'=>'invalid','subject'=>'invalid',
			'cache'=>'invalid','panel'=>'invalid','storage'=>'invalid',
		]);
	}
}

namespace dataphyre {
	final class dpanel {
		public static array $verbose=[];
		public static function add_verbose(array $verbose): void { self::$verbose=$verbose; }
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/permission/kernel/permission.main.php';
	test('permission kernel diagnostic coverage loads the diagnostic entrypoint',static function(Context $t): void {
		$t->isTrue(class_exists(\dataphyre\permission::class,false));
		$t->isTrue(count(\dataphyre\dpanel::$verbose)>=7);
	});
}
