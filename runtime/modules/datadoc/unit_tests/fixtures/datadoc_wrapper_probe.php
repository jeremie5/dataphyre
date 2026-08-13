<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class sanitation {
		public static function sanitize(mixed $value,mixed $policy): array { return [$value,$policy]; }
		public static function anonymize_email(mixed $value,mixed $visible,mixed $mask): array { return [$value,$visible,$mask]; }
	}
	final class sql { public static function db_clear_cache(): string { return 'cleared'; } }
	final class access { public static function is_bot(): bool { return true; } }
	final class firewall { public static function rps_limiter(mixed $limit): mixed { return $limit; } }
	final class currency {
		public static function formatter(mixed $value,mixed $currency): array { return [$value,$currency]; }
		public static function convert_to_user_currency(mixed ...$arguments): array { return $arguments; }
		public static function convert_to_website_currency(mixed ...$arguments): array { return $arguments; }
	}
	final class templating { public static function adapt(mixed $variants,mixed $theme): array { return [$variants,$theme]; } }
}

namespace {
	function tracelog(mixed ...$arguments): void {}
	function dp_define_module_config(string $module,string $constant): void {
		if(!defined($constant)){ define($constant,['timezone'=>'UTC']); }
	}

	$mode=(string)($argv[1] ?? '');
	$wrapper=(string)($argv[2] ?? '');
	$runtime=rtrim((string)($argv[3] ?? ''),'/\\').DIRECTORY_SEPARATOR;
	if($mode==='' || $wrapper===''){
		throw new InvalidArgumentException('Wrapper probe mode and entrypoint are required.');
	}
	if($mode==='missing-root'){
		try{
			require $wrapper;
			echo json_encode(['threw'=>false,'message'=>''],JSON_THROW_ON_ERROR);
		}catch(RuntimeException $failure){
			echo json_encode(['threw'=>true,'message'=>$failure->getMessage()],JSON_THROW_ON_ERROR);
		}
		return;
	}
	define('ROOTPATH',['common_dataphyre_runtime'=>$runtime]);
	require $wrapper;
	echo json_encode([
		'core_loaded'=>defined('DP_CORE_LOADED'),
		'configured'=>defined('DP_DATADOC_CFG'),
		'sanitize'=>sanitize('value','plain'),
		'array_count'=>array_count([1,2]),
		'non_array_count'=>array_count('value'),
		'cache'=>clear_cache(),
		'bot'=>is_bot(),
		'limit'=>rps_limiter(12),
		'anonymized'=>anonymize_email('person@example.test',2,'*'),
		'formatted'=>currency_formatter(10,'CAD'),
		'rounded'=>rounder(10.5),
		'user_currency'=>convert_to_user_currency(10,'USD','CAD',1.4),
		'website_currency'=>convert_to_website_currency(10,'CAD','USD',0.7),
		'adapted'=>adapt(['dark'=>'Dark'],'dark'),
	],JSON_THROW_ON_ERROR);
}
