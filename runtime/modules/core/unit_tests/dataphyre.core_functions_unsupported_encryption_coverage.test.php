<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre { function tracelog(mixed ...$arguments): void {} }

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 10.0); }
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG', ['private_key'=>['unsupported-key'], 'encryption_version'=>1, 'encryption_fallback'=>'[UnsupportedEncryption]']); }
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

	test('core functions fail closed for unsupported configured encryption versions', static function(Context $t): void {
		$t->same('[UnsupportedEncryption]', \dataphyre\core::encrypt_data('payload'));
	})->tag('core', 'functions', 'encryption', 'unsupported', 'coverage')->group('framework-coverage');
}
