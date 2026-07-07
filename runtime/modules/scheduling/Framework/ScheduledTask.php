<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Scheduling;

/**
 * Fluent framework definition for one Dataphyre scheduler task.
 */
final class ScheduledTask {

	private string $name;
	private string $filePath='';
	private Period $period;
	private Period $timeout;
	private string $memoryLimit='128M';
	/** @var array<int, string> */
	private array $dependencies=[];
	private ?string $appOverride=null;

	public function __construct(string $name, ?string $filePath=null){
		$this->name=trim($name);
		if($filePath!==null){
			$this->file($filePath);
		}
		$this->period=Period::seconds(0);
		$this->timeout=Period::seconds(60);
	}

	/** Sets the PHP task file executed by the scheduler route. */
	public function file(string $filePath): self {
		$this->filePath=$filePath;
		return $this;
	}

	/** Sets the minimum interval between task dispatches. */
	public function every(float|int|string|\DateInterval|Period $period): self {
		$this->period=Period::make($period);
		return $this;
	}

	/** Alias for every() for call sites that read in scheduling terms. */
	public function period(float|int|string|\DateInterval|Period $period): self {
		return $this->every($period);
	}

	/** Alias for period() for explicit setter-style configuration. */
	public function setPeriod(float|int|string|\DateInterval|Period $period): self {
		return $this->every($period);
	}

	public function everySeconds(float|int $seconds): self {
		return $this->every(Period::seconds($seconds));
	}

	public function everyMinutes(float|int $minutes): self {
		return $this->every(Period::minutes($minutes));
	}

	public function everyHours(float|int $hours): self {
		return $this->every(Period::hours($hours));
	}

	public function everyDays(float|int $days): self {
		return $this->every(Period::days($days));
	}

	public function everyWeeks(float|int $weeks): self {
		return $this->every(Period::weeks($weeks));
	}

	public function hourly(): self {
		return $this->every('hourly');
	}

	public function daily(): self {
		return $this->every('daily');
	}

	public function weekly(): self {
		return $this->every('weekly');
	}

	/** Sets the stale-lock timeout. */
	public function timeout(float|int|string|\DateInterval|Period $timeout): self {
		$this->timeout=Period::make($timeout);
		return $this;
	}

	/** Alias for timeout() for explicit setter-style configuration. */
	public function setTimeout(float|int|string|\DateInterval|Period $timeout): self {
		return $this->timeout($timeout);
	}

	/** Sets the memory limit applied by task_runner.php. */
	public function memory(string $memoryLimit): self {
		$this->memoryLimit=$memoryLimit;
		return $this;
	}

	/** Adds one required dependency file. */
	public function dependency(string $dependency): self {
		$this->dependencies[]=$dependency;
		return $this;
	}

	/** Replaces the dependency list. */
	public function dependencies(array $dependencies): self {
		$this->dependencies=[];
		foreach($dependencies as $dependency){
			$this->dependency((string)$dependency);
		}
		return $this;
	}

	/** Sets the application override used for internal dispatch. */
	public function app(?string $appOverride): self {
		$this->appOverride=$appOverride;
		return $this;
	}

	/** Alias for app(). */
	public function appOverride(?string $appOverride): self {
		return $this->app($appOverride);
	}

	/** Returns a stable framework definition without touching scheduler state. */
	public function definition(): array {
		return [
			'name'=>$this->name,
			'file_path'=>$this->filePath,
			'frequency'=>$this->period->secondsValue(),
			'timeout'=>$this->timeout->secondsValue(),
			'memory_limit'=>$this->memoryLimit,
			'dependencies'=>array_values(array_unique($this->dependencies)),
			'app_override'=>$this->appOverride,
		];
	}

	/** Registers the task through the scheduling kernel. */
	public function register(): bool {
		$dependencies=array_values(array_unique($this->dependencies));
		$registered=\dataphyre\scheduling::run(
			$this->name,
			$this->filePath,
			$this->period->secondsValue(),
			$this->timeout->secondsValue(),
			$this->memoryLimit,
			$dependencies,
			$this->appOverride
		);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Scheduled task registration '.($registered ? 'succeeded' : 'failed').'; name='.$this->name.'; period='.$this->period->secondsValue().'; timeout='.$this->timeout->secondsValue().'; dependencies='.count($dependencies), $S=$registered ? 'info' : 'warning');
		return $registered;
	}

	/** Alias for register(). */
	public function run(): bool {
		return $this->register();
	}
}
