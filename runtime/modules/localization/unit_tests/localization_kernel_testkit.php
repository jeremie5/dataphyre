<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	if(!class_exists(core::class, false)){
		/** Minimal kernel collaborator whose filesystem effects stay in TestKit workspaces. */
		final class core {
			public static ?string $display_language=null;

			public static function unavailable(mixed ...$arguments): bool {
				$state=\Dataphyre\Test\TestState::channel('localization.kernel');
				$state->increment('unavailable_calls');
				$state->append('unavailable', $arguments);
				return false;
			}

			public static function url_self(): string {
				return 'https://localization.example.test/current';
			}

			public static function file_put_contents_forced(string $path, mixed $contents): int|false {
				$state=\Dataphyre\Test\TestState::channel('localization.kernel');
				$state->append('writes', ['path'=>$path, 'contents'=>(string)$contents]);
				$failure=$state->get('write_failure');
				if($failure===true || (is_string($failure) && $failure!=='' && str_contains($path, $failure))){
					return false;
				}
				$workspace=$state->get('workspace');
				if(!$workspace instanceof \Dataphyre\Test\TempWorkspace){
					return false;
				}
				$root=str_replace('\\', '/', rtrim($workspace->root(), '/\\'));
				$normalized=str_replace('\\', '/', $path);
				if(!str_starts_with($normalized, $root.'/')){
					return false;
				}
				$workspace->file(substr($normalized, strlen($root)+1), (string)$contents);
				return strlen((string)$contents);
			}
		}
	}

}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\ProcessResult;
	use Dataphyre\Test\TempWorkspace;
	use Dataphyre\Test\TestState;

	/** Queue-backed SQL seam for the legacy localization function contract. */
	final class LocalizationSqlProbe {
		/** @var list<mixed> */
		private static array $selectResponses=[];
		/** @var list<mixed> */
		private static array $deleteResponses=[];
		/** @var list<array<int,mixed>> */
		private static array $selects=[];
		/** @var list<array<int,mixed>> */
		private static array $inserts=[];
		/** @var list<array<int,mixed>> */
		private static array $updates=[];
		/** @var list<array<int,mixed>> */
		private static array $deletes=[];
		/** @var list<array<int,mixed>> */
		private static array $tableDefinitions=[];

		public static function reset(): void {
			self::$selectResponses=[];
			self::$deleteResponses=[];
			self::$selects=[];
			self::$inserts=[];
			self::$updates=[];
			self::$deletes=[];
		}

		public static function queueSelect(mixed ...$responses): void {
			array_push(self::$selectResponses, ...$responses);
		}

		public static function queueDelete(mixed ...$responses): void {
			array_push(self::$deleteResponses, ...$responses);
		}

		/** @param array<int,mixed> $arguments */
		public static function defineTable(array $arguments): void { self::$tableDefinitions[]=$arguments; }
		/** @param array<int,mixed> $arguments */
		public static function select(array $arguments): mixed {
			self::$selects[]=$arguments;
			return self::$selectResponses!==[] ? array_shift(self::$selectResponses) : false;
		}
		/** @param array<int,mixed> $arguments */
		public static function insert(array $arguments): bool { self::$inserts[]=$arguments; return true; }
		/** @param array<int,mixed> $arguments */
		public static function update(array $arguments): bool { self::$updates[]=$arguments; return true; }
		/** @param array<int,mixed> $arguments */
		public static function delete(array $arguments): mixed {
			self::$deletes[]=$arguments;
			return self::$deleteResponses!==[] ? array_shift(self::$deleteResponses) : true;
		}

		/** @return list<array<int,mixed>> */
		public static function selects(): array { return self::$selects; }
		/** @return list<array<int,mixed>> */
		public static function selectBindings(): array {
			return array_map(static fn(array $arguments): array=>(array)($arguments[3] ?? []), self::$selects);
		}
		/** @return list<array<int,mixed>> */
		public static function inserts(): array { return self::$inserts; }
		/** @return list<array<int,mixed>> */
		public static function updates(): array { return self::$updates; }
		/** @return list<array<int,mixed>> */
		public static function deletes(): array { return self::$deletes; }
		/** @return list<array<int,mixed>> */
		public static function tableDefinitions(): array { return self::$tableDefinitions; }
	}

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(mixed ...$arguments): void { LocalizationSqlProbe::defineTable($arguments); }
	}
	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed { return LocalizationSqlProbe::select($arguments); }
	}
	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed { return LocalizationSqlProbe::insert($arguments); }
	}
	if(!function_exists('sql_update')){
		function sql_update(mixed ...$arguments): mixed { return LocalizationSqlProbe::update($arguments); }
	}
	if(!function_exists('sql_delete')){
		function sql_delete(mixed ...$arguments): mixed { return LocalizationSqlProbe::delete($arguments); }
	}

	/**
	 * Intention-named workspace and state setup for the localization kernel.
	 */
	final class LocalizationKernelScenario {
		private TempWorkspace $workspace;
		private NonPublicAccess $internals;
		private TestState $runtime;

		public function __construct(private Context $test, bool $databaseBacked=false) {
			$this->workspace=$test->workspace('localization-kernel');
			$this->internals=$test->nonPublic(\dataphyre\localization::class);
			$this->runtime=$test->state('localization.kernel', [
				'unavailable_calls'=>0,
				'unavailable'=>[],
				'writes'=>[],
				'write_failure'=>false,
				'workspace'=>$this->workspace,
			]);
			$test->globalMap('_SESSION')->clear();
			LocalizationSqlProbe::reset();
			$this->internals->replacePropertyForTest('locale', []);
			\dataphyre\localization::apply_state([
				'custom_parameters'=>['<{application}>'=>'Dataphyre'],
				'enable_theme_locales'=>true,
				'enable_global_locales'=>true,
				'database_backed'=>$databaseBacked,
				'locales_table'=>'locales',
				'source_branch'=>'feature/locales',
				'source_commit'=>'abc123',
				'source_repository_path'=>$this->workspace->root(),
				'detect_source_from_git'=>false,
				'default_language'=>'en-CA',
				'user_language'=>'en-CA',
				'translation_callback'=>static fn(string $language, string $value): string=>$language.':'.$value,
				'available_languages'=>['en-CA'=>'English', 'fr-CA'=>'French'],
				'available_themes'=>['light'=>'Light', 'dark'=>'Dark'],
				'user_theme'=>'light',
				'global_locale_path'=>$this->workspace->path('global/%language%.json'),
				'theme_locale_path'=>$this->workspace->path('theme/%theme%/%language%.json'),
				'local_locale_path'=>$this->workspace->path('local/%theme%/%language%%active_page%.json'),
			]);
			$this->internals
				->writeProperty('rebuilder_running_lock_file', $this->workspace->path('cache/locks/locale_rebuilding'))
				->writeProperty('learning_lock_file', $this->workspace->path('cache/locks/locale_learning'))
				->writeProperty('unknown_locales_file', $this->workspace->path('cache/unknown_locales.json'))
				->writeProperty('last_locale_sync_check_file', $this->workspace->path('cache/last_locale_sync_check'))
				->writeProperty('last_locale_sync_file', $this->workspace->path('cache/last_locale_sync'))
				->writeProperty('last_locales_file', $this->workspace->path('cache/last_locales_file'));
		}

		public function internals(): NonPublicAccess { return $this->internals; }
		public function runtime(): TestState { return $this->runtime; }
		public function workspace(): TempWorkspace { return $this->workspace; }

		/** Applies a focused runtime override while preserving every other configured contract. */
		public function configure(array $overrides): void {
			\dataphyre\localization::apply_state([...\dataphyre\localization::state(), ...$overrides]);
		}

		/** Replaces the process-local dictionary cache with an intentional lookup shape. */
		public function cache(array $dictionary): void {
			$this->internals->writeProperty('locale', $dictionary);
		}

		public function unavailableCalls(): int {
			return (int)$this->runtime->get('unavailable_calls', 0);
		}

		public function globalPath(string $language='en-CA'): string {
			return $this->workspace->path('global/'.$language.'.json');
		}

		public function themePath(string $theme='light', string $language='en-CA'): string {
			return $this->workspace->path('theme/'.$theme.'/'.$language.'.json');
		}

		public function localPath(string $page='/orders', string $theme='light', string $language='en-CA'): string {
			return $this->workspace->path('local/'.$theme.'/'.$language.$page.'.json');
		}

		public function unknownPath(): string { return $this->workspace->path('cache/unknown_locales.json'); }
		public function syncTimestampPath(): string { return $this->workspace->path('cache/last_locale_sync'); }
		public function syncIdsPath(): string { return $this->workspace->path('cache/last_locales_file'); }
		public function learningLockPath(): string { return $this->workspace->path('cache/locks/locale_learning'); }
		public function rebuildLockPath(): string { return $this->workspace->path('cache/locks/locale_rebuilding'); }

		/** Reads a kernel-owned artifact without exposing raw filesystem transforms to the test. */
		public function readArtifact(string $path): string {
			$contents=is_file($path) ? file_get_contents($path) : false;
			if(!is_string($contents)){
				throw new RuntimeException('Localization kernel artifact is unavailable: '.$path);
			}
			return $contents;
		}

		public function artifactExists(string $path): bool {
			return is_file($path);
		}

		/** @param array<string,mixed> $dictionary */
		public function writeGlobal(array $dictionary, string $language='en-CA'): string {
			return $this->writeJson('global/'.$language.'.json', $dictionary);
		}

		/** @param array<string,mixed> $dictionary */
		public function writeTheme(array $dictionary, string $theme='light', string $language='en-CA'): string {
			return $this->writeJson('theme/'.$theme.'/'.$language.'.json', $dictionary);
		}

		/** @param array<string,mixed> $dictionary */
		public function writeLocal(array $dictionary, string $page='/orders', string $theme='light', string $language='en-CA'): string {
			return $this->writeJson('local/'.$theme.'/'.$language.$page.'.json', $dictionary);
		}

		/** @param array<string,mixed> $entries */
		public function writeUnknown(array $entries): string {
			return $this->writeJson('cache/unknown_locales.json', $entries);
		}

		public function writeSyncTimestamp(string|int $timestamp): string {
			return $this->workspace->file('cache/last_locale_sync', (string)$timestamp);
		}

		public function writeSyncIds(string $ids): string {
			return $this->workspace->file('cache/last_locales_file', $ids);
		}

		public function writeRaw(string $relative, string $contents): string {
			return $this->workspace->file($relative, $contents);
		}

		public function failWritesContaining(string|bool $needle): void {
			$this->runtime->put('write_failure', $needle);
		}

		/** @param array<string,mixed> $payload */
		private function writeJson(string $relative, array $payload): string {
			return $this->workspace->file($relative, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
		}
	}

	/**
	 * Runs kernel entrypoint contracts whose constants and symbols must predate bootstrap.
	 */
	final class LocalizationKernelProcessScenario {
		private TempWorkspace $workspace;

		public function __construct(private Context $test) {
			$this->workspace=$test->workspace('localization-kernel-process');
		}

		public function diagnosticsWithoutSql(): ProcessResult {
			return $this->run('localization_diagnostic_without_sql.php');
		}

		public function productionFallback(): ProcessResult {
			return $this->run('localization_production_guard.php');
		}

		private function run(string $fixture): ProcessResult {
			return $this->test->coveredPhpFixture(
				__DIR__.'/fixtures/'.$fixture,
				[dirname(__DIR__, 3), $this->workspace->root()],
				working_directory:$this->workspace->root(),
				timeout_millis:15000,
				framework_root:dirname(__DIR__, 4),
			);
		}
	}
}
