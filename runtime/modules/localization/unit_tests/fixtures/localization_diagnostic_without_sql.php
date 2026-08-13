<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class dpanel {
		private static array $findings=[];

		public static function add_verbose(?array $findings): void {
			self::$findings=array_merge(self::$findings, $findings ?? []);
		}

		public static function findings(): array {
			return self::$findings;
		}
	}
}

namespace {
	final class LocalizationDiagnosticFixtureState {
		/** @var list<array{0:string,1:string}> */
		private static array $requiredModules=[];

		public static function requireModule(string $module, string $dependency): void {
			self::$requiredModules[]=[$module, $dependency];
		}

		/** @return list<array{0:string,1:string}> */
		public static function requiredModules(): array { return self::$requiredModules; }
	}

	$runtime=realpath((string)($argv[1] ?? ''));
	$sandbox=(string)($argv[2] ?? '');
	if($runtime===false || !is_dir($sandbox)){
		fwrite(STDERR, 'Localization diagnostic fixture needs runtime and sandbox paths.');
		exit(2);
	}

	function tracelog(mixed ...$arguments): void {}
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		if(!defined($constant)){ define($constant, $defaults); }
	}
	function dp_module_required(string $module, string $dependency): void {
		LocalizationDiagnosticFixtureState::requireModule($module, $dependency);
	}

	define('ROOTPATH', [
		'common_dataphyre_runtime'=>rtrim($runtime, '/\\').'/',
		'dataphyre'=>rtrim($sandbox, '/\\').'/',
	]);
	define('RUN_MODE', 'diagnostic');
	define('IS_PRODUCTION', false);

	require $runtime.'/modules/localization/kernel/localization.main.php';
	$findings=\dataphyre\dpanel::findings();
	$messages=array_values(array_filter(array_map(
		static fn(array $finding): string=>(string)($finding['message'] ?? $finding['error'] ?? ''),
		$findings
	)));
	echo json_encode([
		'sql_query_present'=>function_exists('sql_query'),
		'required_modules'=>LocalizationDiagnosticFixtureState::requiredModules(),
		'messages'=>$messages,
		'message_text'=>implode("\n", $messages),
	], JSON_THROW_ON_ERROR);
}
