<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(scheduling::class, false)){
		final class scheduling {
			public static array $registered=[];
			public static ?string $current='active.task';
			public static bool $inRunner=true;
			public static function run(string $name, string $filePath, float $frequency, float $timeout, string $memoryLimit, array $dependencies, ?string $appOverride=null): bool {
				self::$registered[]=[
					'name'=>$name, 'file_path'=>$filePath, 'frequency'=>$frequency, 'timeout'=>$timeout,
					'memory_limit'=>$memoryLimit, 'dependencies'=>$dependencies, 'app_override'=>$appOverride,
				];
				return $name!=='fail';
			}
			public static function current_scheduler_name(): ?string { return self::$current; }
			public static function in_task_runner(): bool { return self::$inRunner; }
			public static function valid_scheduler_name(string $name): bool { return preg_match('/^[A-Za-z0-9._-]+$/', $name)===1; }
			public static function read_scheduler(string $name): ?array { return $name==='known' ? ['name'=>'known'] : null; }
		}
	}
}

namespace {
	use Dataphyre\Scheduling\Period;
	use Dataphyre\Scheduling\ScheduledTask;
	use Dataphyre\Scheduling\Scheduling;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	final class DpSchedulingTrace {
		public static int $calls=0;
	}

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {
			DpSchedulingTrace::$calls++;
		}
	}

	$dp_scheduling_framework_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/scheduling/Framework';
	require_once $dp_scheduling_framework_root.'/Period.php';
	require_once $dp_scheduling_framework_root.'/ScheduledTask.php';
	require_once $dp_scheduling_framework_root.'/Scheduling.php';

	test('scheduling periods normalize factories aliases intervals units invalid values and stable projections', static function(Context $t): void {
		$existing=Period::seconds(7);
		$t->same($existing, Period::make($existing));
		$t->same(7.0, $existing->secondsValue());
		$t->same(8, (new Period(7.1))->ceilSeconds());
		$t->same(['seconds'=>7.0], $existing->toArray());
		$t->same(0.0, Period::seconds(-5)->secondsValue());
		$t->same(120.0, Period::minutes(2)->secondsValue());
		$t->same(7200.0, Period::hours(2)->secondsValue());
		$t->same(172800.0, Period::days(2)->secondsValue());
		$t->same(1209600.0, Period::weeks(2)->secondsValue());
		$t->same(45.0, Period::make(new DateInterval('PT45S'))->secondsValue());
		$negativeInterval=new DateInterval('PT45S');
		$negativeInterval->invert=1;
		$t->same(0.0, Period::make($negativeInterval)->secondsValue());
		$t->same(0.0, Period::make('')->secondsValue());
		$t->same(0.0, Period::make('  ')->secondsValue());
		$aliases=['secondly'=>1.0, 'minutely'=>60.0, 'hourly'=>3600.0, 'daily'=>86400.0, 'weekly'=>604800.0, 'monthly'=>2592000.0];
		foreach($aliases as $alias=>$seconds){
			$t->same($seconds, Period::make($alias)->secondsValue());
		}
		$t->same(12.5, Period::make('12.5')->secondsValue());
		$t->same(0.0, Period::make('-2')->secondsValue());
		$t->same(0.0, Period::make('eventually')->secondsValue());
		$units=['2s'=>2.0, '2sec'=>2.0, '2secs'=>2.0, '2second'=>2.0, '2seconds'=>2.0, '2m'=>120.0, '2min'=>120.0, '2mins'=>120.0, '2minute'=>120.0, '2minutes'=>120.0, '2h'=>7200.0, '2hr'=>7200.0, '2hrs'=>7200.0, '2hour'=>7200.0, '2hours'=>7200.0, '2d'=>172800.0, '2day'=>172800.0, '2days'=>172800.0, '2w'=>1209600.0, '2week'=>1209600.0, '2weeks'=>1209600.0];
		foreach($units as $period=>$seconds){
			$t->same($seconds, Period::make($period)->secondsValue());
		}
		$t->same(0.0, Period::make('2fortnights')->secondsValue());
	})->tag('scheduling', 'framework', 'deep-coverage')->group('framework-coverage');

	test('scheduled tasks cover every fluent setter definition registration status and run alias', static function(Context $t): void {
		\dataphyre\scheduling::$registered=[];
		DpSchedulingTrace::$calls=0;
		$task=new ScheduledTask(' cleanup ', '/tasks/initial.php');
		$t->same($task, $task->file('/tasks/cleanup.php'));
		$t->same($task, $task->every(5));
		$t->same($task, $task->period('2m'));
		$t->same($task, $task->setPeriod(new DateInterval('PT30S')));
		$t->same($task, $task->everySeconds(10));
		$t->same($task, $task->everyMinutes(2));
		$t->same($task, $task->everyHours(3));
		$t->same($task, $task->everyDays(4));
		$t->same($task, $task->everyWeeks(2));
		$t->same($task, $task->hourly());
		$t->same($task, $task->daily());
		$t->same($task, $task->weekly());
		$t->same($task, $task->timeout('5m'));
		$t->same($task, $task->setTimeout(Period::seconds(45)));
		$t->same($task, $task->memory('256M'));
		$t->same($task, $task->dependency('/deps/one.php'));
		$t->same($task, $task->dependencies(['/deps/two.php', '/deps/two.php', 3]));
		$t->same($task, $task->app('shop'));
		$t->same($task, $task->appOverride('admin'));
		$definition=$task->definition();
		$t->same('cleanup', $definition['name']);
		$t->same('/tasks/cleanup.php', $definition['file_path']);
		$t->same(604800.0, $definition['frequency']);
		$t->same(45.0, $definition['timeout']);
		$t->same(['/deps/two.php', '3'], $definition['dependencies']);
		$t->same('admin', $definition['app_override']);
		$t->isTrue($task->register());
		$t->same('cleanup', \dataphyre\scheduling::$registered[0]['name']);
		$t->isTrue($task->run());
		$t->isFalse((new ScheduledTask('fail'))->register());
		$t->isTrue(DpSchedulingTrace::$calls>=3);
		$t->same('', (new ScheduledTask('empty'))->definition()['file_path']);
	})->tag('scheduling', 'framework', 'deep-coverage')->group('framework-coverage');

	test('scheduling facade covers task period one-shot registration and kernel query bridges', static function(Context $t): void {
		$t->instanceOf(ScheduledTask::class, Scheduling::task('facade'));
		$t->same(3600.0, Scheduling::period('hourly')->secondsValue());
		$t->isTrue(Scheduling::run('facade-run', '/tasks/run.php', '5m', '1m', '64M', ['/dep.php'], 'app'));
		$t->same('active.task', Scheduling::current());
		$t->isTrue(Scheduling::inTaskRunner());
		$t->isTrue(Scheduling::validName('valid.task'));
		$t->isFalse(Scheduling::validName('../bad'));
		$t->same(['name'=>'known'], Scheduling::read('known'));
		$t->same(null, Scheduling::read('missing'));
	})->tag('scheduling', 'framework', 'deep-coverage')->group('framework-coverage');
}
