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

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void {
			if(!defined($constant)){
				define($constant,$defaults);
			}
		}
	}
	if(!defined('DP_PERMISSION_CFG')){
		define('DP_PERMISSION_CFG',[
			'roles'=>[
				''=>42,
				7=>new stdClass(),
			],
			'aliases'=>[''=>'orders.view','orders.read'=>''],
			'conditions'=>[''=>'not-callable','broken'=>'still-not-callable'],
			'subject'=>['id_resolver'=>'not-callable','role_resolver'=>null],
			'cache'=>[],
			'panel'=>[],
			'storage'=>[
				'assignments_table'=>'bad table!',
				'roles_table'=>'valid.roles',
				'role_permissions_table'=>'also/bad',
			],
		]);
	}
}

namespace dataphyre\permission {
	function version_compare(string $version1,string $version2,?string $operator=null): int|bool {
		return $operator===null ? -1 : true;
	}
	function extension_loaded(string $extension): bool {
		return false;
	}
}

namespace dataphyre {
	final class dpanel {
		/** @var list<array<string,mixed>> */
		public static array $verbose=[];
		public static function add_verbose(array $verbose): void {
			self::$verbose=$verbose;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/permission/kernel/permission.diagnostic.php';

	test('permission diagnostic reports simulated runtime and detailed configuration failures',static function(Context $t): void {
		$messages=array_map(static fn(array $entry): string=>(string)($entry['error'] ?? ''),\dataphyre\dpanel::$verbose);
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'PHP version 8.1.0')))===1);
		$t->same(2,count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'PHP extension'))));
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'invalid name')))>=1);
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'permissions must be')))>=1);
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'aliases must map')))>=1);
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'conditions must map')))>=1);
		$t->isTrue(count(array_filter($messages,static fn(string $message): bool=>str_contains($message,"resolver 'id_resolver'")))===1);
		$t->same(4,count(array_filter($messages,static fn(string $message): bool=>str_contains($message,'invalid name.'))));
	})->tag('permission','diagnostic','coverage')->group('framework-coverage');
}
