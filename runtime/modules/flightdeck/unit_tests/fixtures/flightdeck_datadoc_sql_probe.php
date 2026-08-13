<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace FlightdeckDatadocProbe {

	use Closure;
	use Throwable;

	/** Ordered SQL responses with call history, shared by all DataDoc surface queries. */
	final class Sql {
		/** @var array<string,list<mixed>> */
		private static array $responses=['select'=>[],'count'=>[]];
		/** @var array<string,list<array<int,mixed>>> */
		private static array $calls=['select'=>[],'count'=>[]];

		public static function reset(): void {
			self::$responses=['select'=>[],'count'=>[]];
			self::$calls=['select'=>[],'count'=>[]];
		}

		public static function willSelect(mixed ...$responses): void {
			self::$responses['select']=$responses;
		}

		public static function willCount(mixed ...$responses): void {
			self::$responses['count']=$responses;
		}

		/** @return list<array<int,mixed>> */
		public static function calls(string $operation): array {
			return self::$calls[$operation] ?? [];
		}

		public static function answer(string $operation, array $arguments, mixed $default): mixed {
			self::$calls[$operation][]=$arguments;
			$value=self::$responses[$operation]!==[] ? array_shift(self::$responses[$operation]) : $default;
			if($value instanceof Throwable){
				throw $value;
			}
			if($value instanceof Closure){
				return $value(...$arguments);
			}
			return $value;
		}
	}
}

namespace {
	use FlightdeckDatadocProbe\Sql;

	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed {
			return Sql::answer('select', $arguments, []);
		}
	}

	if(!function_exists('sql_count')){
		function sql_count(mixed ...$arguments): mixed {
			return Sql::answer('count', $arguments, 0);
		}
	}
}
