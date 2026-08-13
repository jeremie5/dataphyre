<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

final class DpCurrencyDiagnosticProbe {
	/** @var list<array<int,mixed>> */
	public static array $dependencies=[];
	/** @var list<mixed> */
	public static array $queries=[];
	/** @var list<array<string,mixed>> */
	public static array $verbose=[];
}

if(!function_exists('dp_module_required')){
	function dp_module_required(string $module, string $requiredModule, string $minVersion='1.0', string $maxVersion=''): void {
		DpCurrencyDiagnosticProbe::$dependencies[]=[$module, $requiredModule, $minVersion, $maxVersion];
	}
}
if(!function_exists('sql_query')){
	function sql_query(mixed $query=null, mixed ...$arguments): bool {
		DpCurrencyDiagnosticProbe::$queries[]=$query;
		return true;
	}
}
if(!function_exists('dataphyre\\currency\\version_compare')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre\\currency { function version_compare(string $left, string $right, ?string $operator=null): int|bool { return $operator===null ? -1 : true; } }');
}
if(!function_exists('dataphyre\\currency\\extension_loaded')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre\\currency { function extension_loaded(string $extension): bool { return false; } }');
}
if(!class_exists('dataphyre\\dpanel', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { class dpanel { public static bool $follow_dependency_diagnostics=false; public static function add_verbose(array $verbose): void { \\DpCurrencyDiagnosticProbe::$verbose[]=$verbose; } } }');
}

$dp_currency_diagnostic_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_currency_diagnostic_modules_root.'/currency/kernel/currency.diagnostic.php';

test('currency diagnostic reports simulated runtime failures and executes portable SQL DDL', static function(Context $t): void {
	$t->same([['currency', 'sql', '1.0', '']], DpCurrencyDiagnosticProbe::$dependencies);
	$t->same(1, count(DpCurrencyDiagnosticProbe::$queries));
	$query=DpCurrencyDiagnosticProbe::$queries[0];
	$t->isTrue(is_array($query));
	$t->isTrue(str_contains((string)$query['mysql'], 'dataphyre.exchange_rates'));
	$t->isTrue(str_contains((string)$query['postgresql'], 'dataphyre.exchange_rates'));
	$t->isTrue(str_contains((string)$query['sqlite'], 'dataphyre.exchange_rates'));
	$t->same(1, count(DpCurrencyDiagnosticProbe::$verbose));
	$verbose=DpCurrencyDiagnosticProbe::$verbose[0];
	$t->same(6, count($verbose));
	$t->isTrue(str_contains((string)$verbose[0]['error'], 'PHP version'));
	$t->isTrue(str_contains((string)$verbose[1]['error'], "extension 'json'"));
})->tag('currency', 'currency-support', 'diagnostic', 'deep-coverage')->group('framework-coverage');
