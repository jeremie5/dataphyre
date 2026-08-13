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
	function dp_core_unavailable_state(): TestState { return TestState::channel('core.functions.unavailable'); }
	function tracelog(mixed ...$arguments): void {}
	function log_error(mixed ...$arguments): void { dp_core_unavailable_state()->append('log_errors', $arguments); }
	function pre_init_error(mixed ...$arguments): void { dp_core_unavailable_state()->append('pre_init_errors', $arguments); }
	function header(string $header, bool $replace=true, int $responseCode=0): void { dp_core_unavailable_state()->append('headers', $header); }
	function header_remove(?string $name=null): void {}
	function headers_sent(?string &$filename=null, ?int &$line=null): bool { return false; }
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
	use Dataphyre\Test\TestState;
	if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 10.0); }
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
	$dpCoreUnavailableKernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $dpCoreUnavailableKernel.'/helper_functions.php';
	require_once $dpCoreUnavailableKernel.'/core_functions.php';

	function dp_core_unavailable_scenario(Context $t): TestState {
		return $t->state('core.functions.unavailable', [
			'log_errors'=>[],
			'pre_init_errors'=>[],
			'headers'=>[],
			'view_throws'=>false,
			'view_loaded'=>false,
		]);
	}

	function dp_core_unavailable_install_terminator(): void {
		\dataphyre\core::$dialbacks['CALL_CORE_UNAVAILABLE_TERMINATE']=[
			static fn(): Closure=>static function(): never { throw new RuntimeException('core unavailable terminated'); },
		];
	}
}
