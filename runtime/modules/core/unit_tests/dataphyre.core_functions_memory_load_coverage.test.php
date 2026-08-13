<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	function tracelog(mixed ...$arguments): void {}
	function file_exists(string $filename): bool {
		if(str_ends_with(str_replace('\\', '/', $filename), '/cache/load_level.php')){ return false; }
		return \file_exists($filename);
	}
	function is_readable(string $filename): bool { return $filename==='/proc/meminfo' ? true : \is_readable($filename); }
	function fopen(string $filename, string $mode, bool $useIncludePath=false, mixed $context=null): mixed {
		if($filename==='/proc/meminfo'){
			$stream=\fopen('php://temp', 'w+b');
			\fwrite($stream, "MemTotal: 100000 kB\nMemAvailable: 1000 kB\n");
			\rewind($stream);
			return $stream;
		}
		return $context===null ? \fopen($filename, $mode, $useIncludePath) : \fopen($filename, $mode, $useIncludePath, $context);
	}
	function fgets(mixed $stream, ?int $length=null): string|false { return $length===null ? \fgets($stream) : \fgets($stream, $length); }
	function fclose(mixed $stream): bool { return \fclose($stream); }
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 10.0); }
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG', ['private_key'=>['memory-key'], 'encryption_version'=>0]); }
	if(!defined('CFG')){
		define('CFG', new class implements ArrayAccess {
			private array $data=[];
			public function &raw(): array { return $this->data; }
			public function offsetExists(mixed $offset): bool { return isset($this->data[(string)$offset]); }
			public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
			public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
			public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
		});
	}
	$kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $kernel.'/helper_functions.php';
	require_once $kernel.'/core_functions.php';

	test('core functions classify high memory load independently from CPU pressure', static function(Context $t): void {
		\dataphyre\core::$server_load_level=null;
		\dataphyre\core::$server_load_bottleneck=null;
		$t->same(5, \dataphyre\core::get_server_load_level());
		$t->same('memory', \dataphyre\core::$server_load_bottleneck);
	})->tag('core', 'functions', 'load', 'memory', 'coverage')->group('framework-coverage');
}
