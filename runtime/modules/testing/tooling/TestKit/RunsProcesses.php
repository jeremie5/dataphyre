<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Throwable;

/** Owns the Context capabilities described by its name. */
trait RunsProcesses {

	/** Captures callback output while preserving normal exception propagation. */
	public function captureOutput(callable $callback): CapturedExecution {
		$capture=$this->captureExecution($callback);
		$capture->unwrap();
		return $capture;
	}

	/** Captures output and throwable explicitly for tests whose subject is failure. */
	public function captureExecution(callable $callback): CapturedExecution {
		$baseline=ob_get_level();
		ob_start();
		$result=null;
		$throwable=null;
		try{
			$result=$callback();
		}catch(Throwable $caught){
			$throwable=$caught;
		}
		$output='';
		if(ob_get_level()<=$baseline){
			$throwable=new \RuntimeException('Captured callback closed the TestKit-owned output buffer.', 0, $throwable);
		}else{
			while(ob_get_level()>$baseline){
				$output=(string)ob_get_clean().$output;
			}
		}
		return new CapturedExecution($output, $result, $throwable);
	}

	/**
	 * Runs a command without a shell and captures stdout/stderr through managed files.
	 *
	 * @param list<string> $command
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function process(
		array $command,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): ProcessResult {
		return ProcessProbe::run(
			$this->workspace('dataphyre-process-probe'),
			$command,
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis
		);
	}

	/**
	 * Starts a shell-free child so several independent probes can run concurrently.
	 *
	 * @param list<string> $command
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function startProcess(
		array $command,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): RunningProcess {
		$process=ProcessProbe::start(
			$this->workspace('dataphyre-process-probe'),
			$command,
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis
		);
		$this->defer(static fn()=>$process->terminate());
		return $process;
	}

	/**
	 * Runs the ordinary PHP CLI even when the current coverage worker is phpdbg.
	 *
	 * @param list<string> $arguments
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function phpProcess(
		array $arguments,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): ProcessResult {
		return $this->process(
			PhpRuntime::command($arguments),
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis
		);
	}

	/**
	 * Runs a committed PHP fixture in a fresh ordinary CLI process.
	 *
	 * Process fixtures are the portable boundary for contracts that need immutable
	 * constants, clean symbol tables, or entrypoint lifecycle semantics.
	 *
	 * @param list<string> $arguments
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function phpFixture(
		string $fixture,
		array $arguments=[],
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): ProcessResult {
		return $this->phpProcess(
			$this->phpFixtureArguments($fixture, $arguments),
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis
		);
	}

	/**
	 * Runs a PHP CLI target and folds its exact child line map into this worker.
	 *
	 * @param list<string> $arguments
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function coveredPhpProcess(
		array $arguments,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000,
		?string $framework_root=null,
		array $php_ini=[]
	): ProcessResult {
		return CoveredPhpProcessProbe::run(
			$this->workspace('dataphyre-covered-php-process'),
			$arguments,
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis,
			$framework_root,
			$php_ini
		);
	}

	/**
	 * Runs a committed PHP fixture and folds the child's exact line map into this worker.
	 *
	 * @param list<string> $arguments
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public function coveredPhpFixture(
		string $fixture,
		array $arguments=[],
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000,
		?string $framework_root=null,
		array $php_ini=[]
	): ProcessResult {
		return $this->coveredPhpProcess(
			$this->phpFixtureArguments($fixture, $arguments),
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis,
			$framework_root,
			$php_ini
		);
	}

	/** @param list<string> $arguments @return list<string> */
	private function phpFixtureArguments(string $fixture, array $arguments): array {
		$fixture=trim($fixture);
		if($fixture==='' || !is_file($fixture)){
			throw new \InvalidArgumentException('PHP process fixture is unavailable: '.$fixture);
		}
		return array_merge([$fixture], $arguments);
	}

	/** @param list<string> $arguments @param array<string,string|int|float|bool|null> $environment */
	public function startPhpProcess(
		array $arguments,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): RunningProcess {
		return $this->startProcess(
			PhpRuntime::command($arguments),
			$stdin,
			$working_directory,
			$environment,
			$timeout_millis
		);
	}
}
