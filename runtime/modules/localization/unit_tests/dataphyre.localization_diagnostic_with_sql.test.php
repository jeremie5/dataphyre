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

			public static function add_verbose(?array $verbose): void {
				self::$findings=array_merge(self::$findings, $verbose ?? []);
			}

			public static function reset(): void { self::$findings=[]; }
			public static function findings(): array { return self::$findings; }
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	final class LocalizationDiagnosticSqlProbe {
		/** @var list<mixed> */
		private static array $queries=[];

		public static function record(mixed $query): void { self::$queries[]=$query; }
		public static function first(): mixed { return self::$queries[0] ?? null; }
	}

	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): void {}
	}
	if(!function_exists('sql_query')){
		function sql_query(mixed ...$arguments): bool {
			LocalizationDiagnosticSqlProbe::record($arguments[0] ?? null);
			return true;
		}
	}

	$localization_diagnostic_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $localization_diagnostic_runtime.'/modules/localization/kernel/localization.diagnostic.php';

	suite('Localization diagnostics with SQL')
		->contract('localization.diagnostics-with-sql', 1)
		->layer('integration')
		->risk('medium')
		->watches('module:localization')
		->through('portable-schema-diagnostics')
		->isolation('case')
		->tag('localization', 'diagnostic')
		->group('framework-coverage');

test('SQL-enabled diagnostics submit equivalent locale schemas for every supported database', static function(Context $t): void {
		$query=LocalizationDiagnosticSqlProbe::first();
		$t->type('array', $query);
		$t->same(['mysql', 'postgresql', 'sqlite'], array_keys($query));
		$t->contains('CREATE TABLE IF NOT EXISTS', $query['mysql']);
		$t->contains('CREATE TABLE IF NOT EXISTS', $query['postgresql']);
	$t->contains('CREATE TABLE IF NOT EXISTS', $query['sqlite']);
});

test('diagnostics deterministically report unsupported PHP and every missing runtime extension', static function(Context $t): void {
	\dataphyre\dpanel::reset();
	\dataphyre\localization\diagnostic::tests(
		'8.0.30',
		static fn(string $extension): bool=>$extension!=='json',
		1700000000,
	);
	$findings=\dataphyre\dpanel::findings();
	$t->same(2, count($findings));
	$t->contains('PHP version 8.1.0 or higher is required.', $findings[0]['error']);
	$t->contains("PHP extension 'json' is not loaded.", $findings[1]['error']);
	$t->same([1700000000, 1700000000], array_column($findings, 'time'));
});
}
