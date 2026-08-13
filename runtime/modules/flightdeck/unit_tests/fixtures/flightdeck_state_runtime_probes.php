<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Reactor {
	use RuntimeException;

	final class Reactor {
		private static array $manifest=[];
		private static ?string $failure=null;

		public static function manifestIs(array $manifest): void {
			self::$manifest=$manifest;
			self::$failure=null;
		}

		public static function manifestFails(string $message): void {
			self::$failure=$message;
		}

		public static function manifest(): array {
			if(self::$failure!==null){
				throw new RuntimeException(self::$failure);
			}
			return self::$manifest;
		}
	}

	final class ReactorTrace {
		private static array $events=[];
		private static ?string $failure=null;

		public static function eventsAre(array $events): void {
			self::$events=$events;
			self::$failure=null;
		}

		public static function eventsFail(string $message): void {
			self::$failure=$message;
		}

		public static function events(): array {
			if(self::$failure!==null){
				throw new RuntimeException(self::$failure);
			}
			return self::$events;
		}
	}
}

namespace Dataphyre\Panel {
	use RuntimeException;

	final class Panel {
		private static array $summary=[];
		private static array $events=[];
		private static array $description=[];
		private static array $failures=[];

		public static function returns(array $summary, array $events, array $description): void {
			self::$summary=$summary;
			self::$events=$events;
			self::$description=$description;
			self::$failures=[];
		}

		public static function fails(string ...$methods): void {
			self::$failures=array_fill_keys($methods, true);
		}

		public static function traceSummary(): array {
			self::guard(__FUNCTION__);
			return self::$summary;
		}

		public static function trace(): array {
			self::guard(__FUNCTION__);
			return self::$events;
		}

		public static function describe(): array {
			self::guard(__FUNCTION__);
			return self::$description;
		}

		private static function guard(string $method): void {
			if(isset(self::$failures[$method])){
				throw new RuntimeException($method.' probe failed');
			}
		}
	}
}

namespace Dataphyre\Flightdeck\TestFixture {
	final class StateScenarios {
		public static function sessionEntries(int $count): array {
			$entries=[];
			for($index=1;$index<=$count;$index++){
				$entries['session_'.$index]='value_'.$index;
			}
			return $entries;
		}

		public static function keyedValues(int $count): array {
			$values=[];
			for($index=1;$index<=$count;$index++){
				$values['field_'.$index]=$index;
			}
			return $values;
		}

		public static function batchRoutes(int $count): array {
			$routes=[];
			for($index=1;$index<=$count;$index++){
				$routes['/probe/'.$index]=['value'=>$index];
			}
			return $routes;
		}

		public static function failureBranches(int $count): array {
			$branches=[];
			for($index=1;$index<=$count;$index++){
				$branches[]=['error'=>'failure '.$index];
			}
			return $branches;
		}

		public static function failureMarkerBudgetBoundary(): array {
			$values=[];
			$letters=str_split('error');
			for($mask=0;$mask<32;$mask++){
				$key='';
				foreach($letters as $index=>$letter){
					$key.=(($mask >> $index) & 1)===1 ? strtoupper($letter) : $letter;
				}
				$values[$key]='failure '.$mask;
			}
			$values['after-marker-budget']='not visited';
			return $values;
		}

		public static function memoryLimitAtCurrentPeakRatio(float $ratio): string {
			return (string)(int)ceil(memory_get_peak_usage(true)/$ratio);
		}
	}
}

namespace dataphyre {
	use RuntimeException;

	final class asset_node {
		private static array $server=[];

		public static function represents(array $server): void {
			self::$server=$server;
		}

		public static function current_server_ip(): string {
			return (string)(self::$server['ip'] ?? '');
		}

		public static function current_server_name(): string {
			return (string)(self::$server['name'] ?? '');
		}

		public static function current_server_info(): array {
			return is_array(self::$server['info'] ?? null) ? self::$server['info'] : [];
		}

		public static function configured(): bool {
			return (bool)(self::$server['configured'] ?? false);
		}

		public static function get_server_step(): int|false {
			return is_int(self::$server['step'] ?? null) ? self::$server['step'] : false;
		}

		public static function can_store_block(): bool {
			return (bool)(self::$server['can_store'] ?? false);
		}

		public static function storage_path(): string {
			return (string)(self::$server['storage_path'] ?? '');
		}
	}

	final class routing {
		private static array $snapshot=[];
		private static ?string $failure=null;

		public static function snapshotIs(array $snapshot): void {
			self::$snapshot=$snapshot;
			self::$failure=null;
		}

		public static function snapshotFails(string $message): void {
			self::$failure=$message;
		}

		public static function debug_snapshot(): array {
			if(self::$failure!==null){
				throw new RuntimeException(self::$failure);
			}
			return self::$snapshot;
		}
	}

	final class templating {
		private static array $state=[];
		private static ?string $failure=null;

		public static function stateIs(array $state): void {
			self::$state=$state;
			self::$failure=null;
		}

		public static function stateFails(string $message): void {
			self::$failure=$message;
		}

		public static function state(): array {
			if(self::$failure!==null){
				throw new RuntimeException(self::$failure);
			}
			return self::$state;
		}
	}
}
