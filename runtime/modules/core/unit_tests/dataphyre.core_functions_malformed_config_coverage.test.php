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
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 10.0); }
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG', ['private_key'=>['malformed-key'], 'encryption_version'=>0]); }
	if(!defined('CFG')){
		define('CFG', new class implements ArrayAccess {
			private mixed $data='malformed';
			public function &raw(): mixed { return $this->data; }
			public function offsetExists(mixed $offset): bool { return is_array($this->data) && array_key_exists((string)$offset, $this->data); }
			public function offsetGet(mixed $offset): mixed { return is_array($this->data) ? ($this->data[(string)$offset] ?? null) : null; }
			public function offsetSet(mixed $offset, mixed $value): void { if(!is_array($this->data)){ $this->data=[]; } $this->data[(string)$offset]=$value; }
			public function offsetUnset(mixed $offset): void { if(is_array($this->data)){ unset($this->data[(string)$offset]); } }
		});
	}
	$kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $kernel.'/core_functions.php';

	test('core functions normalize malformed mutable config stores before use', static function(Context $t): void {
		$config=&\dataphyre\core::config_all();
		$t->same([], $config);
		$config['normalized']=true;
		$t->same(true, \dataphyre\core::get_config('normalized'));
	})->tag('core', 'functions', 'config', 'malformed', 'coverage')->group('framework-coverage');
}
