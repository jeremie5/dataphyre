<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

use Countable;
use Throwable;
use Dataphyre\Test\Expectation;
use Dataphyre\Test\FakeDatabase;
use Dataphyre\Test\PdoDatabaseAssertions;
use Dataphyre\Test\FakePermissions;
use Dataphyre\Test\ProcessResult;
use Dataphyre\Test\BenchmarkResult;
use Dataphyre\Test\GeneratedCases;

/** Compile-time contract for this test-context capability family. */
interface AssertionContext {

	public function assertions(): int;

	public function skip(string $reason=''): never;

	public function todo(string $reason=''): never;

	public function fail(string $message, mixed $expected=null, mixed $actual=null, array $meta=[]): never;

	public function expect(mixed $actual): Expectation;

	public function same(mixed $expected, mixed $actual, string $message=''): void;

	public function equals(mixed $expected, mixed $actual, string $message=''): void;

	public function notSame(mixed $expected, mixed $actual, string $message=''): void;

	public function notEquals(mixed $expected, mixed $actual, string $message=''): void;

	public function isTrue(mixed $actual, string $message=''): void;

	public function isFalse(mixed $actual, string $message=''): void;

	public function isNull(mixed $actual, string $message=''): void;

	public function notNull(mixed $actual, string $message=''): void;

	public function contains(mixed $needle, mixed $haystack, string $message=''): void;

	public function containsAll(iterable $needles, mixed $haystack, string $message=''): void;

	public function containsNone(iterable $needles, mixed $haystack, string $message=''): void;

	public function notContains(mixed $needle, mixed $haystack, string $message=''): void;

	public function matches(string $pattern, string $actual, string $message=''): void;

	public function notMatches(string $pattern, string $actual, string $message=''): void;

	public function startsWith(string $prefix, string $actual, string $message=''): void;

	public function notStartsWith(string $prefix, string $actual, string $message=''): void;

	public function endsWith(string $suffix, string $actual, string $message=''): void;

	public function notEndsWith(string $suffix, string $actual, string $message=''): void;

	public function isEmpty(mixed $actual, string $message=''): void;

	public function notEmpty(mixed $actual, string $message=''): void;

	public function length(int $expected, mixed $actual, string $message=''): void;

	public function count(int $expected, Countable|array $actual, string $message=''): void;

	public function type(string $expected, mixed $actual, string $message=''): void;

	public function greaterThan(int|float $expected, mixed $actual, string $message=''): void;

	public function lessThan(int|float $expected, mixed $actual, string $message=''): void;

	public function greaterThanOrEqual(int|float $expected, mixed $actual, string $message=''): void;

	public function lessThanOrEqual(int|float $expected, mixed $actual, string $message=''): void;

	public function between(int|float $min, int|float $max, mixed $actual, string $message=''): void;

	public function approximately(int|float $expected, mixed $actual, int|float $tolerance, string $message=''): void;

	public function isMinorUnits(mixed $actual, string $message=''): void;

	public function minorUnits(int $expected, mixed $actual, string $message=''): void;

	public function moneyAmount(string $expected_decimal, int $minor_units, int $scale=2, string $message=''): void;

	public function instanceOf(string $class, mixed $actual, string $message=''): void;

	public function throws(callable $callback, ?string $class=null, string $message=''): Throwable;

	public function throwsLike(callable $callback, ?string $class=null, ?string $message_contains=null, int|string|null $code=null, string $message=''): Throwable;

	public function doesNotThrow(callable $callback, string $message=''): mixed;

	/**
	 * Calls an operation twice and asserts strict repeatability. The first result
	 * is returned so cache and singleton contracts need no duplicate invocation.
	 */
	public function producesStableResult(callable $operation, string $message=''): mixed;

	public function hasKey(string|int $key, mixed $actual, string $message=''): void;

	public function hasKeys(iterable $keys, mixed $actual, string $message=''): void;

	public function sameKeys(iterable $expected, mixed $actual, string $message=''): void;

	public function missingKey(string|int $key, mixed $actual, string $message=''): void;

	public function hasPath(string|array $path, mixed $actual, string $message=''): void;

	public function missingPath(string|array $path, mixed $actual, string $message=''): void;

	public function pathEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void;

	public function pathNotEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void;

	/** @param array<string,mixed> $expected */
	public function hasPathValues(array $expected, mixed $actual, string $message=''): void;

	public function pathContains(string|array $path, mixed $needle, mixed $actual, string $message=''): void;

	/** @param array<string,mixed> $expected */
	public function pathsContain(array $expected, mixed $actual, string $message=''): void;

	/** @param array<string,mixed> $expected */
	public function hasAccessorValues(array $expected, object $actual, string $message=''): void;

	/** @param array<mixed> $expected */
	public function subset(array $expected, mixed $actual, string $message=''): void;

	/**
	 * Asserts that an unordered record collection contains every declared row shape.
	 *
	 * Expected rows are recursive subsets and are matched one-to-one, so duplicate
	 * expectations require duplicate records. This keeps tests independent from
	 * result sorting without hiding missing fields behind array_column() transforms.
	 *
	 * @param array<int|string,array<mixed>> $expected_rows
	 */
	public function containsRows(array $expected_rows, mixed $actual, string $message=''): void;

	/** Asserts a shell-free process completed normally and returns it for fluent decoding. */
	public function processSucceeded(ProcessResult $process, string $message=''): ProcessResult;

	/** Asserts a shell-free process failed, optionally with one exact exit code. */
	public function processFailed(ProcessResult $process, ?int $exit_code=null, string $message=''): ProcessResult;

	/** @param array<string,mixed>|object $response */
	public function responseStatus(int $expected, array|object $response, string $message=''): void;

	/** @param array<string,mixed>|object $response */
	public function responseHeader(string $name, string $expected, array|object $response, string $message=''): void;

	/** @param array<string,mixed>|object $response */
	public function responseJsonPath(string|array $path, mixed $expected, array|object $response, string $message=''): void;

	/** @param array<string,mixed>|object $response @param array<mixed> $expected */
	public function responseJsonSubset(array $expected, array|object $response, string $message=''): void;

	/** @param array<string,mixed>|object $surface */
	public function panelHasField(array|object $surface, string $name, string $message=''): void;

	/** @param array<string,mixed>|object $surface */
	public function panelHasFilter(array|object $surface, string $name, string $message=''): void;

	/** @param array<string,mixed>|object $surface */
	public function panelHasAction(array|object $surface, string $name, string $message=''): void;

	public function schemaHasColumn(array|object|string $schema, string $column, string $message=''): void;

	/** @param array<string,mixed> $query */
	public function queryMatches(array $query, string $pattern, ?array $bindings=null, string $message=''): void;

	/** @param array<int,array<string,mixed>> $trace @param array<string,mixed> $subset */
	public function traceContains(array $trace, string $type, array $subset=[], string $message=''): void;

	/** @param array<int,array<string,mixed>> $events @param array<string,mixed> $subset */
	public function eventContains(array $events, string $name, array $subset=[], string $message=''): void;

	public function htmlContainsText(string $html, string $text, string $message=''): void;

	public function htmlHasSelector(string $html, string $selector, string $message=''): void;

	public function htmlMissingSelector(string $html, string $selector, string $message=''): void;

	public function htmlAttribute(string $html, string $selector, string $attribute, string $expected, string $message=''): void;

	public function tableHas(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void;

	public function tableMissing(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void;

	public function tableCount(FakeDatabase|PdoDatabaseAssertions $database, string $table, int $expected, string $message=''): void;

	public function permits(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void;

	public function denies(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void;

	public function benchmark(callable $callback, int $iterations=1, int $warmup=0): BenchmarkResult;

	public function performanceUnder(BenchmarkResult|callable $benchmark, int|float $max_millis, ?int $iterations=null, string $message=''): BenchmarkResult;

	public function memoryUnder(callable $callback, int $max_bytes, string $message=''): void;

	public function forAll(iterable $cases, callable $assertion, int $limit=100): void;

	public function fuzz(GeneratedCases $cases, callable $assertion): void;

	public function hasConsistentSerialization(object $value, mixed $expected=null, string $message=''): void;

	public function snapshot(string $name, mixed $actual, string $message=''): void;
}
