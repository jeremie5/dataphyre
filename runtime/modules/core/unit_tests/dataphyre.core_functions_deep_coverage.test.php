<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	use Dataphyre\Test\TestState;
	function dp_core_deep_state(): TestState { return TestState::channel('core.functions.deep'); }
	function tracelog(mixed ...$arguments): void {}
	function glob(string $pattern, int $flags=0): array|false {
		if(str_contains($pattern, '/plugins/core_deep/')){
			$attempt=dp_core_deep_state()->increment('plugin_globs');
			return $attempt===1
				? dp_core_deep_state()->get('plugin_files', [])
				: [];
		}
		return \glob($pattern, $flags);
	}
	function header(string $header, bool $replace=true, int $responseCode=0): void {
		dp_core_deep_state()->append('headers', $header);
	}
	function header_remove(?string $name=null): void {}
	class tracelog {
		public static function tracelog(mixed ...$arguments): void {}
		public static function buffer_callback(mixed $buffer): mixed {
			return $buffer;
		}
	}
	class sql {}
	class date_translation {
		public static function translate_date(string $date, string $language, string $format): string {
			return 'translated:'.$date;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;
	if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY', [
			'enabled'=>['core'=>true, 'storage'=>true, 'missing_module'=>true],
			'disabled'=>['access'=>true],
			'core_implicit'=>true,
		]);
	}
	if(!defined('CPU_USAGE')){
		define('CPU_USAGE', 10.0);
	}
	if(!defined('DP_CORE_CFG')){
		define('DP_CORE_CFG', [
			'private_key'=>['core-deep-private-key'],
			'encryption_version'=>0,
			'recryption_fallback'=>'[RecryptFallback]',
			'encryption_fallback'=>'[DecryptFallback]',
			'core'=>[
				'minify'=>true,
				'client_ip_identification'=>[
					'default_ip'=>'0.0.0.0',
					'trusted_proxies'=>[],
					'trusted_ip_headers'=>[],
				],
			],
		]);
	}
	if(!defined('CFG')){
		define('CFG', new class implements ArrayAccess {
			/** @var array<string,mixed> */
			private array $data=[];
			/** @return array<string,mixed> */
			public function &raw(): array { return $this->data; }
			public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset, $this->data); }
			public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
			public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
			public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
		});
	}

	function dp_shared_request_key(string $secretFile, string $purpose, string $context='', ?int $timestamp=null, ?int $period=null): string|false {
		$timestamp ??= time();
		$period=max(1, $period ?? 60);
		$bucket=(int)floor($timestamp/$period);
		return hash_hmac('sha256', $purpose.'|'.$context.'|'.$bucket, $secretFile);
	}

	function dp_verify_shared_request_key(string $token, string $secretFile, string $purpose, string $context='', int $window=1, ?int $timestamp=null, ?int $period=null): bool {
		$timestamp ??= time();
		$period=max(1, $period ?? 60);
		for($offset=-max(0, $window);$offset<=max(0, $window);$offset++){
			$candidate=dp_shared_request_key($secretFile, $purpose, $context, $timestamp+($offset*$period), $period);
			if(is_string($candidate) && hash_equals($candidate, $token)){
				return true;
			}
		}
		return false;
	}

	$dpCoreDeepKernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $dpCoreDeepKernel.'/helper_functions.php';
	require_once $dpCoreDeepKernel.'/core_functions.php';

	function dp_core_deep_scenario(Context $t): TestState {
		return $t->state('core.functions.deep', [
			'plugin_globs'=>0,
			'plugin_files'=>[],
			'plugins_loaded'=>[],
			'headers'=>[],
		]);
	}

	test('core functions load plugins modules and shared request key helpers', static function(Context $t): void {
		$state=dp_core_deep_scenario($t)->put('plugin_files', [__DIR__.'/fixtures/core_functions_deep_plugin.php']);
		\dataphyre\core::load_plugins('core_deep');
		$t->same(['fixture'], $state->get('plugins_loaded'));

		$t->nonPublic(\dataphyre\core::class)->writeProperty('framework_modules_loaded', []);
		$t->isFalse(\dataphyre\core::load_framework_module(' '));
		$t->isFalse(\dataphyre\core::load_framework_module('access'));
		$t->isTrue(\dataphyre\core::load_framework_module('storage'));
		$t->isTrue(\dataphyre\core::load_framework_module(' STORAGE '));
		$t->isFalse(\dataphyre\core::load_framework_module('missing_module'));
		$t->same(['storage'], \dataphyre\core::load_framework_modules(['storage', ' STORAGE ', 'access', 'missing_module', '']));
		$t->same(['storage'], \dataphyre\core::load_framework_modules('storage'));

		$timestamp=1700000040;
		$period=60;
		$shared=\dataphyre\core::shared_request_key('shared.secret', 'purpose', 'context', $timestamp, $period);
		$t->type('string', $shared);
		$t->isTrue(\dataphyre\core::verify_shared_request_key((string)$shared, 'shared.secret', 'purpose', 'context', 1, $timestamp, $period));
		$t->isFalse(\dataphyre\core::verify_shared_request_key('wrong', 'shared.secret', 'purpose', 'context', 1, $timestamp, $period));
		$t->isFalse(\dataphyre\core::app_override_key_token(' '));
		$t->isFalse(\dataphyre\core::app_override_request_value(' '));
		$appToken=\dataphyre\core::app_override_key_token('admin', $timestamp, $period);
		$t->type('string', $appToken);
		$t->contains('admin,', (string)\dataphyre\core::app_override_request_value('admin', $timestamp, $period));
		$t->isTrue(\dataphyre\core::verify_app_override_key_token('admin', (string)$appToken, 1, $timestamp, $period));
		$t->isFalse(\dataphyre\core::verify_app_override_key_token(' ', (string)$appToken, 1, $timestamp, $period));
		$direct=\dataphyre\core::direct_access_key_token('reports', $timestamp, $period);
		$t->isTrue(\dataphyre\core::verify_direct_access_key_token((string)$direct, 'reports', 1, $timestamp, $period));
	})->tag('core', 'functions', 'modules', 'request-keys', 'coverage')->group('framework-coverage');

	test('core functions cover config dates urls dialback catalogs and deferred work', static function(Context $t): void {
		dp_core_deep_scenario($t);
		$t->isFalse(\dataphyre\core::add_config(['invalid'=>'value'], 'not-null'));
		$t->isTrue(\dataphyre\core::add_config('', ['root_merge'=>['yes'=>true]]));
		$t->isFalse(\dataphyre\core::add_config('', 'scalar'));
		$t->isTrue(\dataphyre\core::add_config('simple', 'value'));
		$t->isTrue(\dataphyre\core::add_config('nested/path', 42));
		$t->isTrue(\dataphyre\core::add_config(['array_merge'=>['kept'=>true]]));
		$t->same('value', \dataphyre\core::get_config('simple'));
		$t->same(42, \dataphyre\core::get_config('nested/path'));
		$t->same(null, \dataphyre\core::get_config('nested/missing'));
		$t->hasPath('nested', \dataphyre\core::get_config(''));
		$config=&\dataphyre\core::config_all();
		$config['by_reference']='yes';
		$t->same('yes', \dataphyre\core::get_config('by_reference'));

		\dataphyre\core::$dialbacks[17]=[static fn(): string=>'ignored'];
		$t->isFalse(array_key_exists(17, \dataphyre\core::dialback_all()));
		unset(\dataphyre\core::$dialbacks[17]);
		$t->isTrue(\dataphyre\core::register_dialback('CALL_CORE_TEST_DEEP_EVENT', static fn(string $value): string=>'one-'.$value));
		$t->isTrue(\dataphyre\core::register_dialback('CALL_CORE_TEST_DEEP_EVENT', static fn(string $value): string=>'two-'.$value));
		$t->same('two-value', \dataphyre\core::dialback('CALL_CORE_TEST_DEEP_EVENT', 'value'));

		\dataphyre\core::add_config('base_timezone', 'UTC');
		\dataphyre\core::add_config('default_timezone', 'Invalid/Zone');
		$t->same('translated:2023-11-14', \dataphyre\core::format_date('1700000000', 'Y-m-d', true));
		$t->same('translated:2023-11-14 22:13', \dataphyre\core::convert_to_user_date(1700000000, 'Invalid/Zone', 'Y-m-d H:i', true));
		$t->same('2023-11-14 22:13', \dataphyre\core::convert_to_server_date(1700000000, 'Invalid/Zone', 'Y-m-d H:i'));

		$t->globalMap('_SERVER')->merge([
			'HTTP_X_FORWARDED_PROTO'=>'https',
			'HTTP_HOST'=>'deep.example',
			'REQUEST_URI'=>'/orders?uri=drop&a=1',
			'QUERY_STRING'=>'uri=drop&a=1',
		]);
		$t->same('https://deep.example/', \dataphyre\core::url_self(false));
		$t->same('https://deep.example/orders?a=1', \dataphyre\core::url_self(true));
		$t->same('https://deep.example/orders?a=1', \dataphyre\core::url_self_updated_querystring(null));
		$t->same('https://deep.example/orders?', \dataphyre\core::url_self_updated_querystring(null, true));
		$t->same('https://example.test/path', \dataphyre\core::url_updated_querystring('https://example.test/path'));

		$schedules=[];
		$t->isTrue(\dataphyre\core::defer_recrypt('record', 1, static function(string $queue) use (&$schedules): void { $schedules[]=$queue; }, 'background'));
		$t->isFalse(\dataphyre\core::defer_recrypt('record', 1, static fn()=>null));
		$t->isFalse(\dataphyre\core::defer_recrypt('record', 2, static function(): never { throw new RuntimeException('scheduler failed'); }));
		$t->isTrue(\dataphyre\core::defer_recrypt('record', 2, static function(string $queue) use (&$schedules): void { $schedules[]=$queue; }));
		$t->same(['background', 'end'], $schedules);
		\dataphyre\core::register_dialback('CALL_CORE_DEFER_RECRYPT', static fn(): bool=>false);
		$t->isFalse(\dataphyre\core::defer_recrypt('early', 1, static fn()=>null));
		unset(\dataphyre\core::$dialbacks['CALL_CORE_DEFER_RECRYPT']);

		$minified=\dataphyre\core::buffer_minify("<div>    Deep</div>\n<!-- gone -->");
		$t->same('<div>Deep</div>', $minified);
		$t->same(0, $t->globalMap('_SESSION')->get('queries_retrieved_from_cache'));
	})->tag('core', 'functions', 'config', 'date', 'url', 'coverage')->group('framework-coverage');

	test('core functions cover crypto csrf filesystem safety and end connection flow', static function(Context $t): void {
		dp_core_deep_scenario($t);
		$t->same('', \dataphyre\core::encrypt_data(''));
		$encrypted=\dataphyre\core::encrypt_data('payload', []);
		$t->same('payload', \dataphyre\core::decrypt_data($encrypted, []));
		$t->same('[RecryptFallback]', \dataphyre\core::decrypt_data('9:anything', []));
		$t->contains('0:', \dataphyre\core::decrypt_data('9:anything', [], 'return'));
		$recrypted=null;
		\dataphyre\core::decrypt_data('9:anything', [], static function(string $value) use (&$recrypted): void { $recrypted=$value; });
		$t->contains('0:', (string)$recrypted);
		$t->same('[DecryptFallback]', \dataphyre\core::decrypt_data('not-versioned', []));

		$t->globalMap('_SESSION')->put('token', []);
		$token=\dataphyre\core::csrf('deep');
		$t->isTrue(\dataphyre\core::csrf('deep', $token));
		$t->isFalse(\dataphyre\core::csrf('deep', 'wrong'));

		$t->isFalse(\dataphyre\core::force_rmdir(''));
		$t->isFalse(\dataphyre\core::force_rmdir('/'));
		$t->isFalse(\dataphyre\core::force_rmdir('C:'));
		$workspace=$t->workspace('core-functions-filesystem');
		$t->isTrue(\dataphyre\core::force_rmdir($workspace->path('missing')));
		$file=$workspace->file('force-rmdir.txt', 'x');
		$t->isTrue(\dataphyre\core::force_rmdir($file));

		ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Client disconnect behavior deliberately exercises native buffer ownership."
		echo 'connection-body';
		\dataphyre\core::end_client_connection(null);
		$t->isTrue(true);
	})->tag('core', 'functions', 'crypto', 'filesystem', 'connection', 'coverage')->group('framework-coverage');

	test('core password derivation uses the configured key when no dialback overrides it', static function(Context $t): void {
		dp_core_deep_scenario($t);
		$t->nonPublic(\dataphyre\core::class)->writeProperty('dialbacks', []);
		$password=\dataphyre\core::get_password('portable-secret');
		$t->notEmpty($password);
		$t->notContains('=', $password);
		$t->same($password, \dataphyre\core::get_password('portable-secret'));
	})->tag('core', 'functions', 'password', 'coverage')->group('framework-coverage');
}
