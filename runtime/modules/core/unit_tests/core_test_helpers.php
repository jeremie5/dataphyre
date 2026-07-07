<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!function_exists('dp_core_unit_config_round_trip')){
	function dp_core_unit_config_round_trip(): array {
		\dataphyre\core::add_config('unit/nested/value', 'ok');
		\dataphyre\core::add_config(['unit'=>['other'=>'kept']]);
		return [
			'nested'=>\dataphyre\core::get_config('unit/nested/value'),
			'other'=>\dataphyre\core::get_config('unit/other'),
			'missing'=>\dataphyre\core::get_config('unit/missing'),
		];
	}
}

if(!function_exists('dp_core_unit_url_update')){
	function dp_core_unit_url_update(): array {
		return [
			'add'=>\dataphyre\core::url_updated_querystring('https://example.test/path?a=1&uri=drop', ['b'=>'two words']),
			'remove'=>\dataphyre\core::url_updated_querystring('https://example.test/path?a=1&b=2', null, ['b']),
			'clear'=>\dataphyre\core::url_updated_querystring('https://example.test/path?a=1&b=2', null, true),
		];
	}
}

if(!function_exists('dp_core_unit_url_self_update')){
	function dp_core_unit_url_self_update(): string {
		$_SERVER['QUERY_STRING']='a=1&b=2&uri=drop';
		$_SERVER['REQUEST_URI']='/orders?a=1&b=2&uri=drop';
		$_SERVER['HTTP_HOST']='example.test';
		return \dataphyre\core::url_self_updated_querystring(['c'=>'see'], ['b']);
	}
}

if(!function_exists('dp_core_unit_format_dates')){
	function dp_core_unit_format_dates(): array {
		\dataphyre\core::add_config('base_timezone', 'UTC');
		\dataphyre\core::add_config('default_timezone', 'UTC');
		return [
			'format'=>\dataphyre\core::format_date('2026-05-12 13:45:00', 'Y-m-d H:i', false),
			'user'=>\dataphyre\core::convert_to_user_date('2026-05-12 13:45:00', 'America/Toronto', 'Y-m-d H:i', false),
			'server'=>\dataphyre\core::convert_to_server_date('2026-05-12 09:45:00', 'America/Toronto', 'Y-m-d H:i'),
		];
	}
}

if(!function_exists('dp_core_unit_high_precision_shape')){
function dp_core_unit_high_precision_shape(): bool {
	\dataphyre\core::add_config('base_timezone', 'UTC');
	return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', \dataphyre\core::high_precision_server_date())===1;
}
}

if(!function_exists('dp_core_unit_crypto_round_trip')){
	function dp_core_unit_crypto_round_trip(): array {
		$encrypted=\dataphyre\core::encrypt_data('secret payload', ['unit', 'test']);
		return [
			'encrypted_prefix'=>substr($encrypted, 0, 2),
			'decrypted'=>\dataphyre\core::decrypt_data($encrypted, ['unit', 'test']),
			'wrong_salt'=>\dataphyre\core::decrypt_data($encrypted, ['wrong']),
		];
	}
}

if(!function_exists('dp_core_unit_csrf_lifecycle')){
	function dp_core_unit_csrf_lifecycle(): array {
		$_SESSION['token']=[];
		$token=\dataphyre\core::csrf('unit_form');
		return [
			'token_is_string'=>is_string($token) && strlen($token)===32,
			'valid'=>\dataphyre\core::csrf('unit_form', $token),
			'invalid'=>\dataphyre\core::csrf('unit_form', 'bad-token'),
		];
	}
}

if(!function_exists('dp_core_unit_file_helpers')){
	function dp_core_unit_file_helpers(): array {
		$root=sys_get_temp_dir().'/dataphyre-core-unit-'.bin2hex(random_bytes(4));
		$file=$root.'/nested/file.txt';
		$bytes=\dataphyre\core::file_put_contents_forced($file, 'hello');
		$contents=is_file($file) ? file_get_contents($file) : false;
		$removed=\dataphyre\core::force_rmdir($root);
		return [
			'bytes'=>$bytes,
			'contents'=>$contents,
			'removed'=>$removed,
			'exists_after'=>file_exists($root),
		];
	}
}

if(!function_exists('dp_core_unit_storage_units')){
	function dp_core_unit_storage_units(): array {
		return [
			'zero'=>\dataphyre\core::convert_storage_unit(0),
			'bytes'=>\dataphyre\core::convert_storage_unit(512),
			'kb'=>\dataphyre\core::convert_storage_unit(2048),
			'mb'=>\dataphyre\core::convert_storage_unit(5 * 1024 * 1024),
		];
	}
}

if(!function_exists('dp_core_unit_password_shape')){
	function dp_core_unit_password_shape(): bool {
		$password=\dataphyre\core::get_password('secret');
		return is_string($password) && $password!=='' && !str_contains($password, '=');
	}
}

if(!function_exists('dp_core_unit_buffer_minify')){
	function dp_core_unit_buffer_minify(): string {
		if(!defined('DP_CORE_MINIFY_OVERRIDE')){
			define('DP_CORE_MINIFY_OVERRIDE', true);
		}
		return \dataphyre\core::buffer_minify("<div>    Hello</div>\n<!-- gone -->");
	}
}

if(!function_exists('dp_core_unit_lock_lifecycle')){
	function dp_core_unit_lock_lifecycle(): bool {
		$lock=ROOTPATH['dataphyre'].'delaying_lock';
		@unlink($lock);
		\dataphyre\core::delayed_requests_lock();
		$created=is_file($lock);
		\dataphyre\core::delayed_requests_unlock();
		return $created && !is_file($lock);
	}
}

if(!function_exists('dp_core_unit_misc_shapes')){
	function dp_core_unit_misc_shapes(): array {
		return [
			'font_contains_class'=>str_contains(\dataphyre\core::minified_font(), '.phyro-bold'),
			'load_level_is_int'=>is_int(\dataphyre\core::get_server_load_level()),
		];
	}
}

if(!function_exists('dp_core_unit_dialback_basic')){
	function dp_core_unit_dialback_basic(): mixed {
		\dataphyre\core::register_dialback('unit_basic_event', static fn(array $payload): array=>$payload);
		return \dataphyre\core::dialback('unit_basic_event', ['key1'=>'value1', 'key2'=>42]);
	}
}

if(!function_exists('dp_core_unit_dialback_multi')){
	function dp_core_unit_dialback_multi(): mixed {
		\dataphyre\core::register_dialback('unit_multi_event', static fn(array $payload): array=>['first'=>$payload]);
		\dataphyre\core::register_dialback('unit_multi_event', static fn(array $payload): array=>['second'=>$payload]);
		return \dataphyre\core::dialback('unit_multi_event', ['data_key'=>'data_value']);
	}
}

if(!function_exists('dp_core_unit_dialback_catalog_has_contract')){
	function dp_core_unit_dialback_catalog_has_contract(): array {
		require_once __DIR__.'/../Framework/DialbackEvent.php';
		require_once __DIR__.'/../Framework/DialbackCatalog.php';

		$catalog=new \Dataphyre\DialbackCatalog(null, [
			'unit.catalog.exists'=>[
				static fn(): bool=>true,
			],
		]);
		return [
			'exact'=>$catalog->has('unit.catalog.exists'),
			'trimmed'=>$catalog->has(' unit.catalog.exists '),
			'missing'=>!$catalog->has('unit.catalog.missing'),
			'blank'=>!$catalog->has(' '),
		];
	}
}

if(!function_exists('dp_core_unit_env_repository_contract')){
	function dp_core_unit_env_repository_contract(): array {
		require_once __DIR__.'/../Framework/Env.php';
		require_once __DIR__.'/../Framework/EnvRepository.php';
		require_once __DIR__.'/../Framework/EnvSnapshot.php';

		$prefix='unit/env_repository_'.bin2hex(random_bytes(4));
		$repo=\Dataphyre\Env::scope($prefix);
		$repo->set([
			'a'=>1,
			'null'=>null,
			'nested/b'=>2,
		]);
		$keys=$repo->keys();
		sort($keys);
		$snapshot=$repo->snapshot();
		$repo->set('a', 9);
		$selected=$repo->only(['a', 'null', 'missing']);
		return [
			'root_has_scope'=>\Dataphyre\Env::repository()->has($prefix.'/a'),
			'scope_has_values'=>$repo->has(),
			'scope_is_not_empty'=>!$repo->isEmpty(),
			'missing_scope_empty'=>\Dataphyre\Env::scope($prefix.'_missing')->isEmpty(),
			'null_key_present'=>$repo->has('null'),
			'keys_are_relative'=>$keys===['a', 'nested/b', 'null'],
			'only_preserves_null'=>array_key_exists('null', $selected) && $selected['null']===null,
			'snapshot_is_immutable'=>$snapshot->get('a')===1 && $repo->get('a')===9,
		];
	}
}

if(!function_exists('dp_core_unit_config_repository_contract')){
	function dp_core_unit_config_repository_contract(): array {
		require_once __DIR__.'/../Framework/Config.php';
		require_once __DIR__.'/../Framework/ConfigRepository.php';
		require_once __DIR__.'/../Framework/ConfigSnapshot.php';

		$prefix='unit/config_repository_'.bin2hex(random_bytes(4));
		\Dataphyre\Config::set($prefix, [
			'nested'=>[
				'value'=>42,
			],
			'null_value'=>null,
			'empty'=>[],
			'false_scalar'=>false,
		]);
		\Dataphyre\Config::set($prefix.'/exact', [
			'zeta'=>9,
		]);
		$repo=\Dataphyre\Config::scope($prefix);
		$nested=$repo->scope('nested');
		$selected=$repo->only(['nested/value', 'null_value', 'missing']);
		return [
			'static_only_nested'=>\Dataphyre\Config::only([$prefix.'/nested/value'])===[$prefix.'/nested/value'=>42],
			'repository_only_preserves_null'=>array_key_exists('null_value', $selected) && $selected['null_value']===null,
			'nested_keys'=>$nested->keys()===['value'],
			'exact_key_precedence'=>\Dataphyre\Config::scope($prefix.'/exact')->keys()===['zeta'],
			'empty_array_is_empty'=>$repo->scope('empty')->isEmpty(),
			'false_scalar_not_empty'=>!$repo->scope('false_scalar')->isEmpty(),
			'missing_scope_empty'=>$repo->scope('missing')->isEmpty(),
		];
	}
}
