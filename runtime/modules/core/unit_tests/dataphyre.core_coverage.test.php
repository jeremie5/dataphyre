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

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('RUN_MODE')){
	define('RUN_MODE', 'unit_test');
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION', false);
}
if(!defined('CPU_USAGE')){
	define('CPU_USAGE', 10.0);
}
if(!defined('CFG')){
	define('CFG', new class implements ArrayAccess {
		/** @var array<string,mixed> */
		private array $data=[];
		/** @return array<string,mixed> */
		public function &raw(): array {
			return $this->data;
		}
		public function offsetExists(mixed $offset): bool {
			return array_key_exists((string)$offset, $this->data);
		}
		public function offsetGet(mixed $offset): mixed {
			return $this->data[(string)$offset] ?? null;
		}
		public function offsetSet(mixed $offset, mixed $value): void {
			$this->data[(string)$offset]=$value;
		}
		public function offsetUnset(mixed $offset): void {
			unset($this->data[(string)$offset]);
		}
	});
}
if(!defined('DP_CORE_CFG')){
	define('DP_CORE_CFG', [
		'private_key'=>['dataphyre-unit-test-private-key'],
		'encryption_version'=>0,
	]);
}
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$args): void {} }');
}
if(!function_exists('dataphyre\\log_error')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function log_error(...$args): void {} }');
}
if(!function_exists('dataphyre_shutdown_log')){
	\Dataphyre\Test\define_test_symbols('namespace { function dataphyre_shutdown_log(...$args): void {} }');
}
if(!function_exists('log_error')){
	\Dataphyre\Test\define_test_symbols('namespace { function log_error(...$args): void {} }');
}

$dp_core_kernel=dirname(__DIR__).'/kernel';
require_once $dp_core_kernel.'/helper_functions.php';
require_once $dp_core_kernel.'/core_functions.php';
require_once __DIR__.'/core_test_helpers.php';

test('core configuration urls and dates preserve their documented contracts', static function(Context $t): void {
	$config=dp_core_unit_config_round_trip();
	$t->same('ok', $config['nested'] ?? null);
	$t->same('kept', $config['other'] ?? null);
	$t->same(null, $config['missing'] ?? null);

	$urls=dp_core_unit_url_update();
	$t->contains('a=1', $urls['add'] ?? '');
	$t->contains('b=two+words', $urls['add'] ?? '');
	$t->notContains('b=2', $urls['remove'] ?? '');
	$t->same('https://example.test/path?', $urls['clear'] ?? null);
	$t->contains('/orders', dp_core_unit_url_self_update());

	$dates=dp_core_unit_format_dates();
	$t->same('2026-05-12 13:45', $dates['format'] ?? null);
	$t->same('2026-05-12 09:45', $dates['user'] ?? null);
	$t->same('2026-05-12 13:45', $dates['server'] ?? null);
	$t->isTrue(dp_core_unit_high_precision_shape());

	$t->isTrue(\dataphyre\core::add_config(['features'=>['panel'=>true]]));
	$t->isFalse(\dataphyre\core::add_config('features/missing'));
	$t->same(true, \dataphyre\core::get_config('features/panel'));
	$t->same(null, \dataphyre\core::get_config('features/unknown'));
	$t->same('https://example.test/path?', \dataphyre\core::url_updated_querystring('https://example.test/path?a=1', ['c'=>3], true));
})->tag('core', 'config', 'url', 'date', 'coverage')->maxMillis(5000);

test('core crypto csrf passwords buffers and storage units cover safe value paths', static function(Context $t): void {
	$crypto=dp_core_unit_crypto_round_trip();
	$t->same('0:', $crypto['encrypted_prefix'] ?? null);
	$t->same('secret payload', $crypto['decrypted'] ?? null);
	$t->notSame('secret payload', $crypto['wrong_salt'] ?? null);

	$csrf=dp_core_unit_csrf_lifecycle();
	$t->isTrue($csrf['token_is_string'] ?? false);
	$t->isTrue($csrf['valid'] ?? false);
	$t->isFalse($csrf['invalid'] ?? true);
	$t->type('string', \dataphyre\core::csrf(''));

	$t->isTrue(dp_core_unit_password_shape());
	$t->same('<div>Hello</div>', dp_core_unit_buffer_minify());
	$units=dp_core_unit_storage_units();
	$t->same('0 b', $units['zero'] ?? null);
	$t->same('512 b', $units['bytes'] ?? null);
	$t->same('2 kb', $units['kb'] ?? null);
	$t->same('5 mb', $units['mb'] ?? null);
	$t->same('1 gb', \dataphyre\core::convert_storage_unit(1024 ** 3));
})->tag('core', 'crypto', 'csrf', 'storage', 'coverage')->maxMillis(5000);

test('core filesystem locks dialbacks headers and request helpers cover bounded runtime paths', static function(Context $t): void {
	$files=dp_core_unit_file_helpers();
	$t->same(5, $files['bytes'] ?? null);
	$t->same('hello', $files['contents'] ?? null);
	$t->isTrue($files['removed'] ?? false);
	$t->isFalse($files['exists_after'] ?? true);
	$t->isTrue(dp_core_unit_lock_lifecycle());

	$t->same(['key1'=>'value1', 'key2'=>42], dp_core_unit_dialback_basic());
	$t->same(['second'=>['data_key'=>'data_value']], dp_core_unit_dialback_multi());
	$t->isTrue(\dataphyre\core::has_dialback('unit_basic_event'));
	$t->notEmpty(\dataphyre\core::dialback_callbacks('unit_basic_event'));
	$t->contains('unit_basic_event', \dataphyre\core::dialback_event_names());
	$t->hasPath('unit_basic_event', \dataphyre\core::dialback_all());
	$t->same(null, \dataphyre\core::dialback('CALL_CORE_TEST_MISSING_EVENT'));

	$color=\dataphyre\core::random_hex_color([1, 1], [2, 2], [3, 3]);
	$t->same('#10203', $color);
	$t->same('10203', \dataphyre\core::random_hex_color([1, 1], [2, 2], [3, 3], false));
	$t->isTrue(dp_core_unit_misc_shapes()['font_contains_class'] ?? false);
	$t->isTrue(dp_core_unit_misc_shapes()['load_level_is_int'] ?? false);

	$t->globalMap('_SERVER')->merge([
		'HTTP_X_FORWARDED_FOR'=>'203.0.113.5, 10.0.0.1',
		'REMOTE_ADDR'=>'127.0.0.1',
	]);
	$t->same('127.0.0.1', \dataphyre\core::get_client_ip());
	$t->hasPath('ip', \dataphyre\core::get_client_ip_details());
	\dataphyre\core::set_http_headers();
})->sandboxesRootpath('dataphyre')->tag('core', 'filesystem', 'dialback', 'request', 'coverage')->maxMillis(5000);
