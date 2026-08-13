<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

use Closure;
use Countable;

final class Expectation {

	/** @var array<string, Closure> */
	private static array $extensions=[];

	public function __construct(private AssertionContext $context, private mixed $actual, private bool $negated=false) {}

	/**
	 * Registers a reusable predicate-backed expectation.
	 *
	 * The predicate receives the actual value followed by the arguments supplied
	 * by the test and must return bool. Negation is handled by Expectation itself.
	 */
	public static function extend(string $name, callable $predicate): void {
		$name=trim($name);
		if(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)!==1){
			throw new \InvalidArgumentException('Expectation extension names must be valid method names.');
		}
		if(method_exists(self::class, $name)){
			throw new \InvalidArgumentException("Expectation extension '{$name}' would shadow a built-in expectation.");
		}
		self::$extensions[$name]=Closure::fromCallable($predicate);
	}

	public static function hasExtension(string $name): bool {
		return isset(self::$extensions[trim($name)]);
	}

	public static function forgetExtension(string $name): void {
		unset(self::$extensions[trim($name)]);
	}

	public function not(): self {
		return new self($this->context, $this->actual, !$this->negated);
	}

	public function toBe(mixed $expected, string $message=''): self {
		$this->negated ? $this->context->notSame($expected, $this->actual, $message) : $this->context->same($expected, $this->actual, $message);
		return $this;
	}

	public function notToBe(mixed $expected, string $message=''): self {
		$this->context->notSame($expected, $this->actual, $message);
		return $this;
	}

	public function toEqual(mixed $expected, string $message=''): self {
		$this->negated ? $this->context->notEquals($expected, $this->actual, $message) : $this->context->equals($expected, $this->actual, $message);
		return $this;
	}

	public function notToEqual(mixed $expected, string $message=''): self {
		$this->context->notEquals($expected, $this->actual, $message);
		return $this;
	}

	public function toBeTrue(string $message=''): self {
		$this->negated ? $this->context->isFalse($this->actual, $message) : $this->context->isTrue($this->actual, $message);
		return $this;
	}

	public function toBeFalse(string $message=''): self {
		$this->negated ? $this->context->isTrue($this->actual, $message) : $this->context->isFalse($this->actual, $message);
		return $this;
	}

	public function toBeNull(string $message=''): self {
		$this->negated ? $this->context->notNull($this->actual, $message) : $this->context->isNull($this->actual, $message);
		return $this;
	}

	public function notToBeNull(string $message=''): self {
		$this->context->notNull($this->actual, $message);
		return $this;
	}

	public function toContain(mixed $needle, string $message=''): self {
		$this->negated ? $this->context->notContains($needle, $this->actual, $message) : $this->context->contains($needle, $this->actual, $message);
		return $this;
	}

	public function toContainAll(iterable $needles, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated all-item expectations are ambiguous; assert the missing item explicitly.');
		}
		$this->context->containsAll($needles, $this->actual, $message);
		return $this;
	}

	/** @param array<mixed> $expected */
	public function toContainSubset(array $expected, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated subset expectations are not supported; assert the differing path explicitly.');
		}
		$this->context->subset($expected, $this->actual, $message);
		return $this;
	}

	/** @param array<int|string,array<mixed>> $expected */
	public function toContainRows(array $expected, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated row-set expectations are ambiguous; assert the missing row explicitly.');
		}
		$this->context->containsRows($expected, $this->actual, $message);
		return $this;
	}

	public function notToContain(mixed $needle, string $message=''): self {
		$this->context->notContains($needle, $this->actual, $message);
		return $this;
	}

	public function toMatch(string $pattern, string $message=''): self {
		$this->negated ? $this->context->notMatches($pattern, (string)$this->actual, $message) : $this->context->matches($pattern, (string)$this->actual, $message);
		return $this;
	}

	public function toHaveCount(int $expected, string $message=''): self {
		if(!is_array($this->actual) && !$this->actual instanceof Countable){
			$this->context->fail($message!=='' ? $message : 'Expected value to be countable.', 'array|Countable', gettype($this->actual));
		}
		$this->context->count($expected, $this->actual, $message);
		return $this;
	}

	public function toBeType(string $expected, string $message=''): self {
		$this->context->type($expected, $this->actual, $message);
		return $this;
	}

	public function toBeInstanceOf(string $class, string $message=''): self {
		$this->context->instanceOf($class, $this->actual, $message);
		return $this;
	}

	public function toHaveKey(string|int $key, string $message=''): self {
		$this->negated ? $this->context->missingKey($key, $this->actual, $message) : $this->context->hasKey($key, $this->actual, $message);
		return $this;
	}

	public function toHaveKeys(iterable $keys, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated multi-key expectations are ambiguous; assert the missing key explicitly.');
		}
		$this->context->hasKeys($keys,$this->actual,$message);
		return $this;
	}

	public function toHaveExactKeys(iterable $keys, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated exact-key expectations are ambiguous; assert the differing key explicitly.');
		}
		$this->context->sameKeys($keys,$this->actual,$message);
		return $this;
	}

	public function toHavePath(string|array $path, string $message=''): self {
		$this->negated ? $this->context->missingPath($path, $this->actual, $message) : $this->context->hasPath($path, $this->actual, $message);
		return $this;
	}

	public function toHavePathValue(string|array $path, mixed $expected, string $message=''): self {
		$this->negated ? $this->context->pathNotEquals($path, $expected, $this->actual, $message) : $this->context->pathEquals($path, $expected, $this->actual, $message);
		return $this;
	}

	/** @param array<string,mixed> $expected */
	public function toHavePathValues(array $expected, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated path-value maps are ambiguous; assert the differing path explicitly.');
		}
		$this->context->hasPathValues($expected, $this->actual, $message);
		return $this;
	}

	/** @param array<string,mixed> $expected */
	public function toHavePathsContaining(array $expected, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated path-containment maps are ambiguous; assert the differing path explicitly.');
		}
		$this->context->pathsContain($expected, $this->actual, $message);
		return $this;
	}

	/** @param array<string,mixed> $expected */
	public function toHaveAccessorValues(array $expected, string $message=''): self {
		if($this->negated){
			$this->context->fail('Negated accessor-value maps are ambiguous; assert the differing accessor explicitly.');
		}
		if(!is_object($this->actual)){
			$this->context->fail($message!=='' ? $message : 'Expected an object with readable accessors.', 'object', gettype($this->actual));
		}
		$this->context->hasAccessorValues($expected, $this->actual, $message);
		return $this;
	}

	public function toBeGreaterThan(int|float $expected, string $message=''): self {
		$this->context->greaterThan($expected, $this->actual, $message);
		return $this;
	}

	public function toBeLessThan(int|float $expected, string $message=''): self {
		$this->context->lessThan($expected, $this->actual, $message);
		return $this;
	}

	public function toBeGreaterThanOrEqual(int|float $expected, string $message=''): self {
		$this->context->greaterThanOrEqual($expected, $this->actual, $message);
		return $this;
	}

	public function toBeLessThanOrEqual(int|float $expected, string $message=''): self {
		$this->context->lessThanOrEqual($expected, $this->actual, $message);
		return $this;
	}

	public function toBeBetween(int|float $min, int|float $max, string $message=''): self {
		$this->context->between($min, $max, $this->actual, $message);
		return $this;
	}

	public function toBeApproximately(int|float $expected, int|float $tolerance, string $message=''): self {
		$this->context->approximately($expected, $this->actual, $tolerance, $message);
		return $this;
	}

	public function toStartWith(string $prefix, string $message=''): self {
		$this->negated ? $this->context->notStartsWith($prefix, (string)$this->actual, $message) : $this->context->startsWith($prefix, (string)$this->actual, $message);
		return $this;
	}

	public function toEndWith(string $suffix, string $message=''): self {
		$this->negated ? $this->context->notEndsWith($suffix, (string)$this->actual, $message) : $this->context->endsWith($suffix, (string)$this->actual, $message);
		return $this;
	}

	public function toBeEmpty(string $message=''): self {
		$this->negated ? $this->context->notEmpty($this->actual, $message) : $this->context->isEmpty($this->actual, $message);
		return $this;
	}

	public function notToBeEmpty(string $message=''): self {
		$this->context->notEmpty($this->actual, $message);
		return $this;
	}

	public function toHaveLength(int $expected, string $message=''): self {
		$this->context->length($expected, $this->actual, $message);
		return $this;
	}

	public function toHaveHtmlSelector(string $selector, string $message=''): self {
		$this->context->htmlHasSelector((string)$this->actual, $selector, $message);
		return $this;
	}

	public function toMissHtmlSelector(string $selector, string $message=''): self {
		$this->context->htmlMissingSelector((string)$this->actual, $selector, $message);
		return $this;
	}

	public function toContainHtmlText(string $text, string $message=''): self {
		$this->context->htmlContainsText((string)$this->actual, $text, $message);
		return $this;
	}

	public function __call(string $name, array $arguments): self {
		$predicate=self::$extensions[$name] ?? null;
		if(!$predicate instanceof Closure){
			throw new \BadMethodCallException("Unknown expectation '{$name}'.");
		}
		$result=$predicate($this->actual, ...$arguments);
		if(!is_bool($result)){
			throw new \UnexpectedValueException("Expectation extension '{$name}' must return bool.");
		}
		$passed=$this->negated ? !$result : $result;
		if($passed===false){
			$this->context->fail(
				"Custom expectation '{$name}' failed.",
				$this->negated ? 'predicate returns false' : 'predicate returns true',
				$this->actual,
				['expectation'=>$name, 'negated'=>$this->negated]
			);
		}
		return $this;
	}
}
