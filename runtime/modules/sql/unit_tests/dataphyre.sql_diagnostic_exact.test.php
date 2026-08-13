<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	if(!class_exists(dpanel::class, false)){
		final class dpanel {
			private static array $findings=[];
			public static function add_verbose(?array $findings): void { self::$findings=array_merge(self::$findings, $findings ?? []); }
			public static function reset(): void { self::$findings=[]; }
			public static function findings(): array { return self::$findings; }
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant): void {
			if(!defined($constant)){ define($constant, []); }
		}
	}
	require_once dirname(__DIR__).'/kernel/sql.diagnostic.php';

	test('SQL diagnostics distinguish unavailable helpers every DBMS time shape and empty cluster families', static function(Context $t): void {
		$initial=\dataphyre\dpanel::findings();
		$t->count(1, $initial);
		$t->contains('SQL cluster probes were skipped', $initial[0]['message']);

		\dataphyre\dpanel::reset();
		$config=['datacenters'=>['primary'=>['dbms_clusters'=>[
			'pg'=>['dbms'=>'postgresql'],
			'mysql'=>['dbms'=>'mysql'],
			'sqlite'=>['dbms'=>'sqlite'],
		]]]];
		$query=static function(array $query): array|false {
			return match($query['dbms_cluster_override']){
				'pg'=>['timediff'=>2],
				'mysql'=>['timediff'=>'00:00:00'],
				'sqlite'=>['timediff'=>-3],
				default=>false,
			};
		};
		\dataphyre\sql\diagnostic::tests($config, $query, 1700000000);
		$findings=\dataphyre\dpanel::findings();
		$t->count(3, $findings);
		$t->contains('Time mismatch (2 seconds)', $findings[0]['error']);
		$t->contains('No time mismatch', $findings[1]['error']);
		$t->contains('Time mismatch (3 seconds)', $findings[2]['error']);
		$t->same([1700000000,1700000000,1700000000], array_column($findings, 'time'));

		\dataphyre\dpanel::reset();
		\dataphyre\sql\diagnostic::tests(
			['datacenters'=>['primary'=>['dbms_clusters'=>['pg'=>['dbms'=>'postgresql']]]]],
			static fn(array $query): false=>false,
			1700000001,
		);
		$t->same([], \dataphyre\dpanel::findings());
	})->tag('sql','diagnostic','exact-coverage')->group('framework-coverage');
}
