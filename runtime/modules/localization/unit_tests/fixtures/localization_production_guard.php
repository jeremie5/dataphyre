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
		public static ?string $display_language=null;
		public static function unavailable(mixed ...$arguments): bool { return false; }
		public static function file_put_contents_forced(string $path, mixed $contents): int|false {
			$directory=dirname($path);
			if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){ return false; }
			return file_put_contents($path, (string)$contents);
		}
	}
}

namespace {
	final class LocalizationProductionFixtureState {
		private static string $sandbox='';

		public static function initialize(string $path): void {
			self::$sandbox=rtrim($path, '/\\').'/';
		}

		public static function sandbox(): string { return self::$sandbox; }
	}

	$runtime=realpath((string)($argv[1] ?? ''));
	$sandbox=(string)($argv[2] ?? '');
	if($runtime===false || !is_dir($sandbox)){
		fwrite(STDERR, 'Localization production fixture needs runtime and sandbox paths.');
		exit(2);
	}
	LocalizationProductionFixtureState::initialize($sandbox);

	function tracelog(mixed ...$arguments): void {}
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		$sandbox=LocalizationProductionFixtureState::sandbox();
		$config=array_replace($defaults, [
			'database_backed'=>false,
			'detect_source_from_git'=>false,
			'default_language'=>'en-CA',
			'user_language'=>'en-CA',
			'available_languages'=>['en-CA'=>'English'],
			'available_themes'=>['light'=>'Light'],
			'user_theme'=>'light',
			'global_locale_path'=>$sandbox.'global/%language%.json',
			'theme_locale_path'=>$sandbox.'theme/%theme%/%language%.json',
			'local_locale_path'=>$sandbox.'local/%theme%/%language%%active_page%.json',
		]);
		if(!defined($constant)){ define($constant, $config); }
	}

	define('ROOTPATH', [
		'common_dataphyre_runtime'=>rtrim($runtime, '/\\').'/',
		'dataphyre'=>LocalizationProductionFixtureState::sandbox(),
	]);
	define('RUN_MODE', 'unit_test');
	define('IS_PRODUCTION', true);

	require $runtime.'/modules/localization/kernel/localization.main.php';
	$unknown_file=LocalizationProductionFixtureState::sandbox().'cache/unknown_locales';
	echo json_encode([
		'production'=>IS_PRODUCTION,
		'resolved'=>\dataphyre\localization::locale('global:MISSING', 'Fallback', null, 'en-CA', '/orders'),
		'unknown_locale_file_exists'=>is_file($unknown_file),
	], JSON_THROW_ON_ERROR);
}
