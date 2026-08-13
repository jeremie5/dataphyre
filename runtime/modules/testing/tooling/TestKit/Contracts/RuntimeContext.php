<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

use Dataphyre\Test\GlobalState;
use Dataphyre\Test\TestState;
use Dataphyre\Test\PhpBootstrapProbe;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\RootpathWorkspace;

/** Compile-time contract for this test-context capability family. */
interface RuntimeContext {

	public function name(): string;

	public function dataset(): string;

	public function suite(): string;

	public function assertions(): int;

	public function metadata(?string $key=null, mixed $default=null): mixed;

	public function stableId(): string;

	public function contract(): string;

	public function contractVersion(): string;

	/** @param array<string, mixed> $fixtures */
	public function setFixtures(array $fixtures): void;

	public function fixture(string $name): mixed;

	/** Registers a LIFO cleanup callback that runs even when the test fails. */
	public function cleanup(callable $cleanup): void;

	/** Concise alias for cleanup(), useful when modeling resource acquisition. */
	public function defer(callable $cleanup): void;

	/** @internal Called by the test registry after the case body finishes. */
	public function runDeferred(): void;

	public function global(string $name): GlobalState;

	public function globalMap(string $name): GlobalState;

	/** Runs one scoped native-global contract and passes its managed view to the callback. */
	public function withGlobal(string $name, mixed $value, callable $callback): mixed;

	/** Runs one scoped native-global absence contract and passes its managed view to the callback. */
	public function withoutGlobal(string $name, callable $callback): mixed;

	/**
	 * Applies cleanup-managed process environment overrides for one test case.
	 *
	 * Null removes a variable. Repeated calls compose as nested scopes and are
	 * restored in LIFO order even when the case fails.
	 *
	 * @param array<string,scalar|null> $variables
	 */
	public function environment(array $variables): self;

	/**
	 * Applies cleanup-managed runtime INI overrides for one test case.
	 *
	 * Startup-only directives should be supplied to coveredPhpProcess() through
	 * php_ini instead; this helper deliberately fails when PHP rejects a runtime
	 * change so a test never proceeds under an accidental host configuration.
	 *
	 * @param array<string,scalar> $settings
	 */
	public function phpIni(array $settings): self;

	/** Registers a cleanup-managed userland stream wrapper for one test case. */
	public function streamWrapper(string $scheme, string $wrapper_class): self;

	/** @param array<string|int,mixed> $initial */
	public function state(string $channel, array $initial=[]): TestState;

	public function defineSymbols(string $php): mixed;

	public function loadStub(string $path): mixed;

	/** Loads a framework entrypoint and exposes fluent assertions for its published symbols. */
	public function phpBootstrap(string $path): PhpBootstrapProbe;

	/** @param array<string,string|int|float|bool|null> $values */
	public function setEnvironmentForTest(array $values): void;

	/** @param array<string,mixed> $values */
	public function setGlobalsForTest(array $values): void;

	/** @param array<string,mixed> $values */
	public function withGlobals(array $values, callable $callback): mixed;

	public function tempDirectory(string $prefix='dataphyre-test'): string;

	/** Creates a cleanup-managed random child beneath an intentional fixture root. */
	public function tempDirectoryIn(string $directory, string $prefix='dataphyre-test'): string;

	public function tempFile(string $contents='', string $prefix='dataphyre-test', ?string $directory=null): string;

	public function workspace(string $prefix='dataphyre-workspace'): TempWorkspace;

	/** Creates a cleanup-managed workspace beneath an intentional fixture root. */
	public function workspaceIn(string $directory, string $prefix='dataphyre-workspace'): TempWorkspace;

	/** Opens the runner-owned fixture workspace declared through sandboxesRootpath(). */
	public function rootpathWorkspace(string $key): RootpathWorkspace;

	/** Converts slash-agnostic fixture notation into this runtime's path form. */
	public function nativePath(string $path): string;

	/** Converts a runtime path into stable slash notation for portable contracts. */
	public function portablePath(string $path): string;

	/** Mirrors Dataphyre's case-insensitive Windows filesystem policy. */
	public function usesWindowsPathSemantics(): bool;

	public function decodeJson(string $json, bool $associative=true): mixed;

	/** @return array<mixed> */
	public function jsonArray(string $json): array;

	/** Decodes optional machine output without mistaking human-readable output for an empty payload. */
	public function tryJsonArray(string $json): ?array;

	public function readJson(string $path, bool $associative=true): mixed;

	/** @return array<mixed> */
	public function readJsonArray(string $path): array;
}
