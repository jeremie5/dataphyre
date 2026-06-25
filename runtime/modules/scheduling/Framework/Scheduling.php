<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Scheduling;

/**
 * Static facade for framework-level scheduler definitions.
 */
final class Scheduling {

	/** Starts a fluent scheduler task definition. */
	public static function task(string $name, ?string $filePath=null): ScheduledTask {
		return new ScheduledTask($name, $filePath);
	}

	/** Creates a reusable scheduler period. */
	public static function period(float|int|string|\DateInterval|Period $period): Period {
		return Period::make($period);
	}

	/** Registers a task in one call with period-aware inputs. */
	public static function run(
		string $name,
		string $filePath,
		float|int|string|\DateInterval|Period $period,
		float|int|string|\DateInterval|Period $timeout=60,
		string $memoryLimit='128M',
		array $dependencies=[],
		?string $appOverride=null
	): bool {
		return self::task($name, $filePath)
			->every($period)
			->timeout($timeout)
			->memory($memoryLimit)
			->dependencies($dependencies)
			->app($appOverride)
			->register();
	}

	/** Returns the active scheduler context from the kernel. */
	public static function current(): ?string {
		return \dataphyre\scheduling::current_scheduler_name();
	}

	/** Reports whether the current process is executing a scheduler task. */
	public static function inTaskRunner(): bool {
		return \dataphyre\scheduling::in_task_runner();
	}

	/** Checks whether a scheduler name is safe for registration. */
	public static function validName(string $name): bool {
		return \dataphyre\scheduling::valid_scheduler_name($name);
	}

	/** Reads a persisted scheduler definition. */
	public static function read(string $name): ?array {
		return \dataphyre\scheduling::read_scheduler($name);
	}
}
