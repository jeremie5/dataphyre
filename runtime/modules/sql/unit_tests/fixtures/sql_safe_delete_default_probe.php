<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	function tracelog(mixed ...$arguments): void {}
	function log_error(mixed ...$arguments): void {}
	function dp_module_present(string $module): bool { return false; }
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		if(!defined($constant)) define($constant, $defaults);
	}

	final class core {
		public static function dialback(string $name, mixed ...$arguments): mixed { return null; }
		public static function unavailable(mixed ...$arguments): null { return null; }
		public static function get_password(string $endpoint): string { return ''; }
		public static function load_framework_module(string $module): bool { return true; }
	}
}

namespace {
	$root=rtrim((string)($argv[1] ?? ''), '/\\');
	if($root==='' || !is_dir($root)){
		fwrite(STDERR, 'SQL safe-delete probe requires a Dataphyre root.');
		exit(2);
	}
	define('RUN_MODE', 'request');
	define('ROOTPATH', [
		'common_dataphyre_runtime'=>$root.'/runtime',
		'sql_cache'=>sys_get_temp_dir().'/dataphyre-sql-safe-delete-default',
	]);
	define('DP_CORE_CFG', ['datacenter'=>'coverage']);
	define('DP_SQL_CFG', [
		'default_cluster'=>'main',
		'default_database_location'=>'',
		'caching'=>[
			'default_policy'=>[
				'type'=>'session',
				'max_lifespan'=>'1 minute',
				'hash_type'=>'md5',
			],
		],
		'datacenters'=>[
			'coverage'=>[
				'dbms_clusters'=>[
					'main'=>[
						'dbms'=>'sqlite',
						'endpoints'=>['safe-delete-default-endpoint'],
						'database_name'=>':memory:',
					],
				],
			],
		],
		'tables'=>[],
	]);

	require_once $root.'/runtime/modules/sql/kernel/sql.main.php';

	$warnings=[];
	set_error_handler(static function(int $severity, string $message) use (&$warnings): bool {
		$warnings[]=$message;
		return true;
	});
	$delete=\dataphyre\sql::delete('items', null, null, false, null);
	$deleteError=\dataphyre\sql::last_query_error();
	restore_error_handler();

	$exception=$deleteError['exception'] ?? null;
	echo json_encode([
		'safe_delete_present'=>array_key_exists('safe_delete', DP_SQL_CFG),
		'delete_result'=>$delete,
		'delete_error'=>[
			'dbms'=>$deleteError['dbms'] ?? null,
			'message'=>$deleteError['message'] ?? null,
			'exception_type'=>is_object($exception) ? get_debug_type($exception) : null,
		],
		'warnings'=>$warnings,
	], JSON_THROW_ON_ERROR);
}
