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

	/** Scriptable DataDoc facade state for deterministic Flightdeck surface tests. */
	final class Facade {
		/** @var array<string,list<mixed>> */
		private static array $responses=[];
		/** @var array<string,list<array<int,mixed>>> */
		private static array $calls=[];
		/** @var array<string,array<string,mixed>> */
		public static array $projects=[];
		public static bool $logged_in=true;

		public static function reset(): void {
			self::$responses=[];
			self::$calls=[];
			self::$projects=[];
			self::$logged_in=true;
		}

		public static function will(string $method, mixed ...$responses): void {
			self::$responses[$method]=$responses;
		}

		/** @return list<array<int,mixed>> */
		public static function calls(string $method): array {
			return self::$calls[$method] ?? [];
		}

		public static function answer(string $method, array $arguments, mixed $default=null): mixed {
			self::$calls[$method][]=$arguments;
			$value=!empty(self::$responses[$method]) ? array_shift(self::$responses[$method]) : $default;
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

namespace dataphyre {

	use FlightdeckDatadocProbe\Facade;

	/** Minimal scriptable facade exposing the contracts consumed by Flightdeck. */
	final class datadoc {
		public static function logged_in(): bool {
			return (bool)Facade::answer('logged_in', [], Facade::$logged_in);
		}

		public static function get_project(string $project): ?array {
			return Facade::answer('get_project', [$project], Facade::$projects[$project] ?? null);
		}

		public static function get_menu_branch(string $project, string $kind, array $path): array {
			return Facade::answer('get_menu_branch', [$project,$kind,$path], []);
		}

		public static function render_procedural_menu_nodes(string $project, string $kind, array $branch, array $path, int $depth): void {
			echo (string)Facade::answer('render_procedural_menu_nodes', [$project,$kind,$branch,$path,$depth], '<div class="menu-item">branch</div>');
		}

		public static function sync_project_file_if_changed(string $file, string $project): array {
			return Facade::answer('sync_project_file_if_changed', [$file,$project], ['changed'=>false,'deleted'=>false,'error'=>'']);
		}

		public static function normalize_manual_path(array|string $path): string {
			$segments=is_array($path) ? $path : explode('/', $path);
			$default=implode('/', array_values(array_filter(array_map(
				static fn(mixed $segment): string => trim(str_replace('\\', '/', (string)$segment), '/'),
				$segments,
			), static fn(string $segment): bool => $segment!=='')));
			return (string)Facade::answer('normalize_manual_path', [$path], $default);
		}

		public static function get_manudoc(string $project, string $path): ?array {
			return Facade::answer('get_manudoc', [$project,$path], null);
		}

		public static function get_stale_files(string $project): array {
			return Facade::answer('get_stale_files', [$project], []);
		}

		public static function should_exclude_index_file(string $file): bool {
			return (bool)Facade::answer('should_exclude_index_file', [$file], false);
		}

		public static function get_manudoc_structure(string $project): array {
			return Facade::answer('get_manudoc_structure', [$project], []);
		}

		public static function create_project(string $project, string $title, string $path): bool {
			return (bool)Facade::answer('create_project', [$project,$title,$path], true);
		}

		public static function last_error(): string {
			return (string)Facade::answer('last_error', [], '');
		}

		public static function discover_files_to_project(string $path, string $project, int $limit, string $cursor): array {
			return Facade::answer('discover_files_to_project', [$path,$project,$limit,$cursor], [
				'registered'=>0,'last_cursor'=>$cursor,'done'=>true,'error'=>'',
			]);
		}

		public static function sync_project_batch(string $project, int $limit, float $seconds): array {
			return Facade::answer('sync_project_batch', [$project,$limit,$seconds], [
				'synced'=>0,'failed'=>0,'stopped_by'=>'complete','error'=>'',
			]);
		}
	}
}
