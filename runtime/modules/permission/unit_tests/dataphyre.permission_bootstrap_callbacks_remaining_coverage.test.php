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

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void {
			if(!defined($constant)){
				define($constant,$defaults);
			}
		}
	}
	if(!defined('DP_PERMISSION_CFG')){
		define('DP_PERMISSION_CFG',[
			'roles'=>[],
			'default_roles'=>[],
			'subject'=>['permission_keys'=>['permissions'],'role_keys'=>['roles']],
			'storage'=>['auto_hydrate'=>false],
		]);
	}
	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY',[
			'enabled'=>['core'=>true,'permission'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
}

namespace dataphyre {
	if(!class_exists(core::class,false)){
		final class core {
			/** @var array<string,callable> */
			public static array $dialbacks=[];
			/** @var list<string> */
			public static array $loaded=[];
			public static function load_framework_modules(array $modules): void {
				self::$loaded=array_values(array_map('strval',$modules));
			}
			public static function register_dialback(string $name,callable $callback): void {
				self::$dialbacks[$name]=$callback;
			}
			public static function dialback(string $name,mixed ...$arguments): mixed {
				return null;
			}
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dpPermissionBootstrapModulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
	require_once $dpPermissionBootstrapModulesRoot.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($dpPermissionBootstrapModulesRoot);
	\dataphyre\autoloader::register_framework_modules(['permission']);
	require_once $dpPermissionBootstrapModulesRoot.'/permission/Framework/Bootstrap.php';

	test('permission framework bootstrap registers and executes subject resolver dialbacks',static function(Context $t): void {
		$t->same(['access','panel'],\dataphyre\core::$loaded);
		$t->same([
			'CALL_PERMISSION_SUBJECT_ID',
			'CALL_PERMISSION_SUBJECT_PERMISSIONS',
			'CALL_PERMISSION_SUBJECT_ROLES',
		],array_keys(\dataphyre\core::$dialbacks));
		$subject=['id'=>41,'permissions'=>['orders.view'],'roles'=>['viewer']];
		$t->same(41,(\dataphyre\core::$dialbacks['CALL_PERMISSION_SUBJECT_ID'])($subject));
		$t->same(['orders.view'],(\dataphyre\core::$dialbacks['CALL_PERMISSION_SUBJECT_PERMISSIONS'])($subject,['tenant'=>'north']));
		$t->same(['viewer'],(\dataphyre\core::$dialbacks['CALL_PERMISSION_SUBJECT_ROLES'])($subject,['tenant'=>'north']));
	})->tag('permission','bootstrap','coverage')->group('framework-coverage');
}
