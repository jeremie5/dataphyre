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
	function dp_core_system_state(): TestState { return TestState::channel('core.functions.system'); }
	function tracelog(mixed ...$arguments): void {}
	function log_error(mixed ...$arguments): void { dp_core_system_state()->append('log_errors', $arguments); }
	function pre_init_error(mixed ...$arguments): void { dp_core_system_state()->append('pre_init_errors', $arguments); }
	function header(string $header, bool $replace=true, int $responseCode=0): void { dp_core_system_state()->append('headers', $header); }
	function header_remove(?string $name=null): void {}
	function headers_sent(?string &$filename=null, ?int &$line=null): bool { return false; }
	function microtime(bool $asFloat=false): string|float {
		if(dp_core_system_state()->get('bad_microtime', false)===true){ return 'invalid'; }
		return \microtime($asFloat);
	}
	function file_exists(string $filename): bool {
		if(str_ends_with(str_replace('\\', '/', $filename), '/cache/load_level.php') && dp_core_system_state()->get('skip_load_cache', false)===true){ return false; }
		if($filename===dp_core_system_state()->get('non_file_path')){ return true; }
		return \file_exists($filename);
	}
	function is_readable(string $filename): bool {
		if($filename==='/proc/meminfo'){ return true; }
		return \is_readable($filename);
	}
	function fopen(string $filename, string $mode, bool $useIncludePath=false, mixed $context=null): mixed {
		if($filename==='/proc/meminfo'){
			$stream=\fopen('php://temp', 'w+b');
			\fwrite($stream, "MemTotal: 100000 kB\nMemAvailable: 90000 kB\n");
			\rewind($stream);
			return $stream;
		}
		if(dp_core_system_state()->get('fail_lock_open', false)===true && str_ends_with(str_replace('\\', '/', $filename), '/delaying_lock')){ return false; }
		return $context===null ? \fopen($filename, $mode, $useIncludePath) : \fopen($filename, $mode, $useIncludePath, $context);
	}
	function fgets(mixed $stream, ?int $length=null): string|false { return $length===null ? \fgets($stream) : \fgets($stream, $length); }
	function fclose(mixed $stream): bool { return \fclose($stream); }
	function is_file(string $filename): bool {
		if(str_ends_with(str_replace('\\', '/', $filename), '/delaying_lock') && dp_core_system_state()->has('lock_states')){
			return (bool)dp_core_system_state()->shift('lock_states', dp_core_system_state()->get('lock_default', false));
		}
		if($filename===dp_core_system_state()->get('non_file_path')){ return false; }
		return \is_file($filename);
	}
	function is_link(string $filename): bool {
		if($filename===dp_core_system_state()->get('non_file_path')){ return false; }
		return \is_link($filename);
	}
	function is_dir(string $filename): bool {
		if($filename===dp_core_system_state()->get('non_file_path')){ return false; }
		return \is_dir($filename);
	}
	function usleep(int $microseconds): void {}
	function unlink(string $filename, mixed $context=null): bool {
		$normalized=str_replace('\\', '/', $filename);
		if(dp_core_system_state()->get('fail_lock_unlink', false)===true && str_ends_with($normalized, '/delaying_lock')){ return false; }
		$suffix=(string)dp_core_system_state()->get('fail_unlink_suffix', '');
		if($suffix!=='' && str_ends_with($normalized, $suffix)){ return false; }
		return $context===null ? \unlink($filename) : \unlink($filename, $context);
	}
	function rmdir(string $directory, mixed $context=null): bool {
		$suffix=(string)dp_core_system_state()->get('fail_rmdir_suffix', '');
		if($suffix!=='' && str_ends_with(str_replace('\\', '/', $directory), $suffix)){ return false; }
		return $context===null ? \rmdir($directory) : \rmdir($directory, $context);
	}
	function mkdir(string $directory, int $permissions=0777, bool $recursive=false, mixed $context=null): bool {
		if(dp_core_system_state()->get('fail_mkdir', false)===true){ return false; }
		return $context===null ? \mkdir($directory, $permissions, $recursive) : \mkdir($directory, $permissions, $recursive, $context);
	}
	function file_put_contents(string $filename, mixed $data, int $flags=0, mixed $context=null): int|false {
		$suffix=(string)dp_core_system_state()->get('fail_write_suffix', '');
		if($suffix!=='' && str_ends_with(str_replace('\\', '/', $filename), $suffix)){ return false; }
		return $context===null ? \file_put_contents($filename, $data, $flags) : \file_put_contents($filename, $data, $flags, $context);
	}
	function file_get_contents(string $filename, bool $useIncludePath=false, mixed $context=null, int $offset=0, ?int $length=null): string|false {
		if(str_ends_with(str_replace('\\', '/', $filename), '/cache/known_error_conditions.json')){ return '{}'; }
		if($length===null){ return $context===null ? \file_get_contents($filename, $useIncludePath, null, $offset) : \file_get_contents($filename, $useIncludePath, $context, $offset); }
		return $context===null ? \file_get_contents($filename, $useIncludePath, null, $offset, $length) : \file_get_contents($filename, $useIncludePath, $context, $offset, $length);
	}
	class tracelog {
		public static function tracelog(mixed ...$arguments): void {}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\RootpathSandbox;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;
	if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }

	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 90.0); }
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG', ['private_key'=>['system-key'], 'encryption_version'=>0, 'core'=>['minify'=>false]]); }
	if(!defined('CFG')){
		define('CFG', new class implements ArrayAccess {
			private array $data=[];
			public function &raw(): array { return $this->data; }
			public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset, $this->data); }
			public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
			public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
			public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
		});
	}
	$kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $kernel.'/helper_functions.php';
	require_once $kernel.'/core_functions.php';

	function dp_core_system_scenario(Context $t): TestState {
		return $t->state('core.functions.system', [
			'headers'=>[],
			'log_errors'=>[],
			'pre_init_errors'=>[],
			'bad_microtime'=>false,
			'skip_load_cache'=>false,
			'fail_lock_open'=>false,
			'fail_lock_unlink'=>false,
			'fail_mkdir'=>false,
			'fail_write_suffix'=>'',
			'fail_rmdir_suffix'=>'',
			'fail_unlink_suffix'=>'',
		]);
	}

	function dp_core_system_reset_load(): void {
		\dataphyre\core::$server_load_level=null;
		\dataphyre\core::$server_load_bottleneck=null;
	}
	function dp_core_system_install_terminator(): void {
		\dataphyre\core::$dialbacks['CALL_CORE_UNAVAILABLE_TERMINATE']=[
			static fn(): Closure=>static function(): never { throw new RuntimeException('core unavailable terminated'); },
		];
	}

	test('core functions cover HTTP hardening CPU load cache and early load hooks', static function(Context $t): void {
		$state=dp_core_system_scenario($t);
		$t->globalMap('_SERVER')->merge(['HTTPS'=>'on', 'HTTP_HOST'=>'[2001:db8::1]:8443']);
		\dataphyre\core::set_http_headers();
		$t->contains('Strict-Transport-Security: max-age=31536000', $state->get('headers'));
		$t->contains('Upgrade-Insecure-Requests: 1', $state->get('headers'));

		\dataphyre\core::$dialbacks['CALL_CORE_GET_SERVER_LOAD_LEVEL']=[static fn(): int=>4];
		$t->same(4, \dataphyre\core::get_server_load_level());
		unset(\dataphyre\core::$dialbacks['CALL_CORE_GET_SERVER_LOAD_LEVEL']);
		$state->put('skip_load_cache', true);
		dp_core_system_reset_load();
		$t->same(5, \dataphyre\core::get_server_load_level());
		$t->same('cpu', \dataphyre\core::$server_load_bottleneck);
		$t->same(5, \dataphyre\core::get_server_load_level());

		$cache=RootpathSandbox::path('core_cache', 'load_level.php');
		$t->isTrue(\dataphyre\core::file_put_contents_forced(
			$cache,
			"<?php return ['level'=>3,'timestamp'=>time(),'bottleneck'=>'cache'];"
		)!==false);
		$state->put('skip_load_cache', false);
		dp_core_system_reset_load();
		$t->same(3, \dataphyre\core::get_server_load_level());
		$t->same('cache', \dataphyre\core::$server_load_bottleneck);
	})->sandboxesRootpath('core_cache')->tag('core', 'functions', 'headers', 'load', 'coverage')->group('framework-coverage');

	test('core functions cover delayed lock and filesystem failure boundaries', static function(Context $t): void {
		$state=dp_core_system_scenario($t);
		$workspace=$t->workspace('core-system-failures');
		dp_core_system_install_terminator();
		$state->put('fail_lock_open', true);
		$t->throws(static fn()=>\dataphyre\core::delayed_requests_lock(), RuntimeException::class);
		$state->put('fail_lock_open', false)->put('fail_lock_unlink', true);
		$t->throws(static fn()=>\dataphyre\core::delayed_requests_unlock(), RuntimeException::class);
		$state->put('fail_lock_unlink', false);

		$state->put('lock_states', [true, false])->put('lock_default', false);
		\dataphyre\core::check_delayed_requests_lock();
		$state->put('lock_states', [true, true, true, true, true, true])->put('lock_default', true);
		$t->throws(static fn()=>\dataphyre\core::check_delayed_requests_lock(), RuntimeException::class);
		$state->forget('lock_states')->forget('lock_default');

		$missingParent=$workspace->path('mkdir-fail');
		$state->put('fail_mkdir', true);
		$t->isFalse(\dataphyre\core::file_put_contents_forced($missingParent.'/file.txt', 'x'));
		$state->put('fail_mkdir', false);
		$writeFail=$workspace->path('write-fail.txt');
		$state->put('fail_write_suffix', basename($writeFail));
		$t->isFalse(\dataphyre\core::file_put_contents_forced($writeFail, 'x'));
		$state->put('fail_write_suffix', '');

		$special=$workspace->path('special');
		$state->put('non_file_path', $special);
		$t->isFalse(\dataphyre\core::force_rmdir($special));
		$state->forget('non_file_path');

		$rmdirRoot=$workspace->directory('rmdir-fail');
		$workspace->directory('rmdir-fail/sub');
		$state->put('fail_rmdir_suffix', '/sub');
		$t->isFalse(\dataphyre\core::force_rmdir($rmdirRoot));
		$state->put('fail_rmdir_suffix', '');

		$unlinkRoot=$workspace->directory('unlink-fail');
		$workspace->file('unlink-fail/file.txt', 'x');
		$state->put('fail_unlink_suffix', '/file.txt');
		$t->isFalse(\dataphyre\core::force_rmdir($unlinkRoot));
		$state->put('fail_unlink_suffix', '');
	})->tag('core', 'functions', 'filesystem', 'error-path', 'coverage')->group('framework-coverage');

	test('core functions cover date failures invalid timezones and unavailable CI handling', static function(Context $t): void {
		$state=dp_core_system_scenario($t);
		dp_core_system_install_terminator();
		\dataphyre\core::add_config('base_timezone', 'UTC');
		\dataphyre\core::add_config('default_timezone', 'UTC');
		$state->put('bad_microtime', true);
		$t->throws(static fn()=>\dataphyre\core::high_precision_server_date(), RuntimeException::class);
		$state->put('bad_microtime', false);
		$t->throws(static fn()=>\dataphyre\core::convert_to_user_date('not-a-date', 'UTC'), RuntimeException::class);
		$t->throws(static fn()=>\dataphyre\core::convert_to_server_date('not-a-date', 'UTC'), RuntimeException::class);
		\dataphyre\core::add_config('base_timezone', 'Invalid/Zone');
		$t->throws(static fn()=>\dataphyre\core::high_precision_server_date(), RuntimeException::class);
		$t->throws(static fn()=>\dataphyre\core::convert_to_user_date('2026-01-01', 'UTC'), RuntimeException::class);
		$t->throws(static fn()=>\dataphyre\core::convert_to_server_date('2026-01-01', 'UTC'), RuntimeException::class);
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '1', __CLASS__, __FUNCTION__, 'Direct unavailable', 'coverage'), RuntimeException::class);
		$t->notEmpty($state->get('log_errors'));
	})->tag('core', 'functions', 'date', 'unavailable', 'coverage')->group('framework-coverage');
}
