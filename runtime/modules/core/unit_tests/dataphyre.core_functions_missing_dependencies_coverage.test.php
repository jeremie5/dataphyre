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
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG', ['private_key'=>['missing-dependency-key'], 'encryption_version'=>0]); }
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
	require_once $kernel.'/core_functions.php';

	test('core functions fail closed when shared request key helpers are missing', static function(Context $t): void {
		$t->isFalse(function_exists('dp_shared_request_key'));
		$t->isFalse(\dataphyre\core::shared_request_key('secret', 'purpose'));
		$t->isFalse(\dataphyre\core::verify_shared_request_key('token', 'secret', 'purpose'));
		$t->isFalse(\dataphyre\core::app_override_key_token('admin'));
		$t->isFalse(\dataphyre\core::app_override_request_value('admin'));
		$t->isFalse(\dataphyre\core::verify_app_override_key_token('admin', 'token'));
		$t->isFalse(\dataphyre\core::direct_access_key_token('scope'));
		$t->isFalse(\dataphyre\core::verify_direct_access_key_token('token', 'scope'));
	})->tag('core', 'functions', 'missing-dependency', 'request-keys', 'coverage')->group('framework-coverage');
}
