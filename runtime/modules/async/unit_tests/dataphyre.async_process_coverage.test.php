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
	function dp_module_present(mixed ...$arguments): bool { return false; }
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		if(!defined($constant)){
			define($constant, $defaults);
		}
	}
	class core {
		public static function dialback(mixed ...$arguments): mixed { return null; }
		public static function unavailable(mixed ...$arguments): never {
			throw new \RuntimeException((string)($arguments[4]??'Dataphyre unavailable.'));
		}
		public static function file_put_contents_forced(string $file, string $data): int|false {
			$directory=dirname($file);
			if(!is_dir($directory)){
				mkdir($directory, 0775, true);
			}
			return file_put_contents($file, $data);
		}
	}
}

namespace dataphyre\async {
	use Dataphyre\Test\TestState;

	function file_exists(string $path): bool {
		$handler=TestState::channel('async.process')->get('exists');
		return is_callable($handler) ? (bool)$handler($path) : \file_exists($path);
	}

	function file_get_contents(string $path): string|false {
		$handler=TestState::channel('async.process')->get('read');
		return is_callable($handler) ? $handler($path) : \file_get_contents($path);
	}

	function unlink(string $path): bool {
		TestState::channel('async.process')->append('unlinks', $path);
		return true;
	}

	function posix_kill(int $processId, int $signal=15): bool {
		TestState::channel('async.process')->append('kills', $processId);
		return true;
	}

	function usleep(int $microseconds): void {
		TestState::channel('async.process')->append('sleeps', $microseconds);
	}

	function exec(string $command, ?array &$output=null, ?int &$resultCode=null): string|false {
		TestState::channel('async.process')->append('commands', $command);
		$output=['4321'];
		$resultCode=0;
		return '4321';
	}

	function time(): int {
		return 1700000000;
	}

	function rand(int $minimum, int $maximum): int {
		return (int)TestState::channel('async.process')->increment('random', 1);
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!defined('DP_ASYNC_CFG')){
		define('DP_ASYNC_CFG', [
			'dependencies'=>['C:/unit/dependency.php'],
			'included_vars'=>['included'=>'yes'],
			'excluded_vars'=>['removed'=>true],
			'framework'=>['default_dispatcher'=>'inline', 'pool_concurrency'=>3],
		]);
	}
	$dp_async_process_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/async/';
	require_once $dp_async_process_root.'kernel/async.main.php';
	$dp_async_process_fixture=dirname(__DIR__).'/unit_tests/fixtures/async_process_task_source.php';

	function dp_async_process_scenario(Context $t): TestState {
		\dataphyre\async\process::$queued_tasks=[];
		\dataphyre\async\process::$task_kill_list=[];
		\dataphyre\async\process::$execution_timeout=10;
		\dataphyre\async\process::$waitfor_loop_time=1000;
		\dataphyre\async\process::set_dialback_handler(null);
		\dataphyre\async\process::set_task_file_writer(null);
		return $t->state('async.process', [
			'exists'=>null,
			'read'=>null,
			'unlinks'=>[],
			'kills'=>[],
			'sleeps'=>[],
			'commands'=>[],
			'random'=>1000,
		]);
	}

	test('async process covers runtime dialback overrides and built in empty paths', static function(Context $t)use($dp_async_process_fixture): void {
		dp_async_process_scenario($t);
		\dataphyre\async\process::set_dialback_handler(static fn(string $event): string=>'early:'.$event);
		$t->same('early:CALL_ASYNC_WAITFOR_ALL', \dataphyre\async\process::waitfor_all());
		$t->same('early:CALL_ASYNC_WAITFOR', \dataphyre\async\process::waitfor('task'));
		$t->same('early:CALL_ASYNC_RESULT', \dataphyre\async\process::result('task'));
		$t->same('early:CALL_ASYNC_CREATE', \dataphyre\async\process::create(1, $dp_async_process_fixture));

		\dataphyre\async\process::set_dialback_handler(null);
		$t->same(null, \dataphyre\async\process::waitfor_all());
		$t->same(null, \dataphyre\async\process::waitfor(null));
		$t->same(null, \dataphyre\async\process::result(null));
		$fallbackPath=$t->nonPublic(\dataphyre\async\process::class)->invoke('task_path', 'fallback', false, '');
		$t->contains('cache/tasks/fallback.php', $t->portablePath($fallbackPath));
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async process covers wait completion timeout cleanup and waitfor all queues', static function(Context $t): void {
		$state=dp_async_process_scenario($t);
		$state->put('exists', static fn(string $path): bool=>str_contains($path, 'done-task_done.php'));
		\dataphyre\async\process::$queued_tasks=['done-task'];
		\dataphyre\async\process::waitfor('done-task');

		\dataphyre\async\process::$execution_timeout=0;
		\dataphyre\async\process::$waitfor_loop_time=1;
		$state->put('exists', static fn(string $path): bool=>false);
		\dataphyre\async\process::$queued_tasks=['timeout-task'];
		\dataphyre\async\process::waitfor('timeout-task');
		$t->same([1], $state->get('sleeps'));

		$state->put('exists', static fn(string $path): bool=>true);
		\dataphyre\async\process::$queued_tasks=['cleanup-task'];
		\dataphyre\async\process::$task_kill_list=['cleanup-task'=>321];
		\dataphyre\async\process::waitfor('cleanup-task');
		$t->same([321], $state->get('kills'));
		$t->same(2, count($state->get('unlinks')));

		\dataphyre\async\process::$queued_tasks=['all-one', 'all-two'];
		\dataphyre\async\process::$task_kill_list=[];
		\dataphyre\async\process::waitfor_all();
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async process covers result decode wipe unfinished and kill cleanup', static function(Context $t): void {
		$state=dp_async_process_scenario($t);
		$state->put('read', static function(string $path): string|false {
			return str_contains($path, 'result-task_done.php') ? '{"answer":42}' : false;
		});
		\dataphyre\async\process::$queued_tasks=['result-task'];
		$t->same(['answer'=>42], \dataphyre\async\process::result('result-task', false));
		\dataphyre\async\process::$queued_tasks=['result-task'];
		$t->same(['answer'=>42], \dataphyre\async\process::result('result-task', true));
		$t->same(1, count($state->get('unlinks')));

		\dataphyre\async\process::$task_kill_list=['unfinished-task'=>654];
		$t->same('task_unfinished', \dataphyre\async\process::result('unfinished-task'));
		$t->same([654], $state->get('kills'));
		$t->same(3, count($state->get('unlinks')));
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async process covers task generation config writer success failure and launcher', static function(Context $t)use($dp_async_process_fixture): void {
		$state=dp_async_process_scenario($t);
		$writtenPath='';
		$writtenContents='';
		\dataphyre\async\process::set_task_file_writer(static function(string $path, string $contents)use(&$writtenPath, &$writtenContents): int {
			$writtenPath=$path;
			$writtenContents=$contents;
			return strlen($contents);
		});
		$taskId=\dataphyre\async\process::create(1, $dp_async_process_fixture, ['seed'=>'value', 'removed'=>'drop'], true, false);
		$t->contains($taskId.'.php', $writtenPath);
		$t->contains('$task_enable_tracelog=true;', $writtenContents);
		$t->contains('C:/unit/dependency.php', $writtenContents);
		$t->contains('included', $writtenContents);
		$t->isFalse(str_contains($writtenContents, 'removed'));
		$t->same([$taskId], \dataphyre\async\process::$queued_tasks);
		$t->same(1, count($state->get('commands')));

		$background=\dataphyre\async\process::create(1, $dp_async_process_fixture, null, false, true);
		$t->isTrue($background!==$taskId);

		\dataphyre\async\process::set_task_file_writer(static fn(string $path, string $contents): false=>false);
		$error='';
		try{
			\dataphyre\async\process::create(1, $dp_async_process_fixture);
		}catch(RuntimeException $exception){
			$error=$exception->getMessage();
		}
		$t->isTrue($error!=='');

		\dataphyre\async\process::set_task_file_writer(null);
		$actual=\dataphyre\async\process::create(1, $dp_async_process_fixture, [], false, true);
		$actualPath=rtrim((string)ROOTPATH['async_tasks'], '/\\').'/'.$actual.'.php';
		$t->isTrue(\file_exists($actualPath));
		if(\file_exists($actualPath)){
			\unlink($actualPath);
		}
	})->sandboxesRootpath('async_tasks')->tag('async', 'coverage')->group('kernel-coverage');
}
