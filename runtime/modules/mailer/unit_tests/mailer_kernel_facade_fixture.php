<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mailer {
	final class KernelFacadeResult {
		/** @param array<int,mixed> $arguments */
		public function __construct(private string $method, private array $arguments) {}
		/** @return array<string,mixed> */
		public function toArray(): array {
			return ['ok'=>true, 'method'=>$this->method, 'arguments'=>$this->arguments];
		}
	}

	final class Mailer {
		/** @var list<array{method:string,arguments:array<int,mixed>}> */
		private static array $calls=[];

		public static function reset(): void { self::$calls=[]; }
		/** @return list<string> */
		public static function calledMethods(): array { return array_column(self::$calls, 'method'); }

		/** @param array<int,mixed> $arguments */
		public static function __callStatic(string $method, array $arguments): mixed {
			self::$calls[]=['method'=>$method, 'arguments'=>$arguments];
			return match($method){
				'send', 'queue'=>new KernelFacadeResult($method, $arguments),
				'sendBatch'=>array_map(
					static fn(mixed $message): KernelFacadeResult=>new KernelFacadeResult($method, [$message]),
					(array)($arguments[0] ?? [])
				),
				'suppress', 'unsuppress', 'isSuppressed'=>true,
				default=>['ok'=>true, 'method'=>$method, 'arguments'=>$arguments],
			};
		}
	}
}

namespace dataphyre {
	final class core {
		public static bool $frameworkAvailable=false;
		public static function load_framework_module(string $module): bool {
			return $module==='mailer' && self::$frameworkAvailable;
		}
	}
}

namespace {
	if(!defined('DP_MAILER_CFG')){
		define('DP_MAILER_CFG', [
			'default_provider'=>'fixture',
			'literal.key'=>'literal-value',
			'nested'=>['value'=>'nested-value'],
			'scalar'=>'leaf',
			'outbox'=>[
				'table'=>'fixture.mailer_outbox',
				'events_table'=>'fixture.mailer_events',
			],
			'suppression'=>['table'=>'fixture.mailer_suppressions'],
			'webhooks'=>['events_table'=>'fixture.mailer_webhook_events'],
			'scheduler'=>[
				'enabled'=>false,
				'batch_size'=>25,
				'prune'=>['enabled'=>false, 'options'=>[]],
			],
		]);
	}
	final class DpMailerKernelFixtureState {
		/** @var list<array{table:string,file:string,definition:string}> */
		private static array $tables=[];
		public static function resetFacade(bool $available): void {
			\dataphyre\core::$frameworkAvailable=$available;
			\Dataphyre\Mailer\Mailer::reset();
		}
		public static function recordTable(string $table, string $file, string $definition): void {
			self::$tables[]=['table'=>$table, 'file'=>$file, 'definition'=>$definition];
		}
		/** @return list<array{table:string,file:string,definition:string}> */
		public static function tables(): array { return self::$tables; }
	}

	function tracelog(...$arguments): void {}
	function dp_define_module_config(string $module, string $constant, array $defaults): void {}
	function sql_define_table(string $table, string $file, string $definition): void {
		DpMailerKernelFixtureState::recordTable($table, $file, $definition);
	}
}
