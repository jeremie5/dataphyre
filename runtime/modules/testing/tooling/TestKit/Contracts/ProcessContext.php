<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

use Dataphyre\Test\CapturedExecution;
use Dataphyre\Test\ProcessResult;
use Dataphyre\Test\RunningProcess;

/** Compile-time contract for this test-context capability family. */
interface ProcessContext {

	/** Captures callback output while preserving normal exception propagation. */
	public function captureOutput(callable $callback): CapturedExecution;

	/** Captures output and throwable explicitly for tests whose subject is failure. */
	public function captureExecution(callable $callback): CapturedExecution;

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
	): ProcessResult;

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
	): RunningProcess;

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
	): ProcessResult;

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
	): ProcessResult;

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
	): ProcessResult;

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
	): ProcessResult;

	/** @param list<string> $arguments @param array<string,string|int|float|bool|null> $environment */
	public function startPhpProcess(
		array $arguments,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): RunningProcess;
}
