<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

/** Compile-time contract for this test-context capability family. */
interface ExtensibleContext {

	/** Registers a lazily-created, process-local module testing kit. */
	public static function extend(string $name, callable $factory): void;

	public static function hasExtension(string $name): bool;

	public static function forgetExtension(string $name): void;

	/**
	 * Resolves one module kit per test context.
	 *
	 * @template T of object
	 * @param class-string<T>|null $expected_type
	 * @return ($expected_type is null ? mixed : T)
	 */
	public function extension(string $name, ?string $expected_type=null): mixed;

	/** Zero-argument extension calls keep domain DSLs concise: `$t->panel()`. */
	public function __call(string $name, array $arguments): mixed;
}
