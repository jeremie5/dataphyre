<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class tracelog {
		public static function tracelog(mixed ...$arguments): void {}
	}
}

namespace {
	final class CoreTraceConfigBoundary {
		public static int $calls=0;
	}
	function tracelog(mixed ...$arguments): void {
		CoreTraceConfigBoundary::$calls++;
	}
	define('DP_CORE_CFG', ['trace_config_reads'=>true]);
	define('CFG', new class implements ArrayAccess {
		/** @var array<string,mixed> */
		private array $data=['traced'=>'traced-value'];
		public function &raw(): array { return $this->data; }
		public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset, $this->data); }
		public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
		public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
		public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
	});
	require_once (string)($argv[1] ?? '');
	echo json_encode([
		'value'=>\dataphyre\core::get_config('traced'),
		'trace_calls'=>CoreTraceConfigBoundary::$calls,
	], JSON_THROW_ON_ERROR);
}
