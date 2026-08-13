<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Countable;
use Throwable;

/** Owns the Context capabilities described by its name. */
trait AssertsValues {

	public function expect(mixed $actual): Expectation {
		return new Expectation($this, $actual);
	}

	public function same(mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($expected!==$actual){
			$this->fail($message!=='' ? $message : 'Expected values to be strictly identical.', $expected, $actual);
		}
	}

	public function equals(mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($expected!=$actual){
			$this->fail($message!=='' ? $message : 'Expected values to be equal.', $expected, $actual);
		}
	}

	public function notSame(mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($expected===$actual){
			$this->fail($message!=='' ? $message : 'Expected values not to be strictly identical.', 'not '.$this->describe($expected), $actual);
		}
	}

	public function notEquals(mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($expected==$actual){
			$this->fail($message!=='' ? $message : 'Expected values not to be equal.', 'not '.$this->describe($expected), $actual);
		}
	}

	public function isTrue(mixed $actual, string $message=''): void {
		$this->same(true, $actual, $message!=='' ? $message : 'Expected true.');
	}

	public function isFalse(mixed $actual, string $message=''): void {
		$this->same(false, $actual, $message!=='' ? $message : 'Expected false.');
	}

	public function isNull(mixed $actual, string $message=''): void {
		$this->same(null, $actual, $message!=='' ? $message : 'Expected null.');
	}

	public function notNull(mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($actual===null){
			$this->fail($message!=='' ? $message : 'Expected a non-null value.', 'not null', null);
		}
	}

	public function contains(mixed $needle, mixed $haystack, string $message=''): void {
		$this->recordAssertion();
		$found=false;
		if(is_string($haystack) && (is_string($needle) || is_numeric($needle))){
			$found=str_contains($haystack, (string)$needle);
		}
		elseif(is_array($haystack))
		{
			$found=in_array($needle, $haystack, true);
		}
		if($found!==true){
			$this->fail($message!=='' ? $message : 'Expected value to contain needle.', $needle, $haystack);
		}
	}

	public function containsAll(iterable $needles, mixed $haystack, string $message=''): void {
		foreach($needles as $needle){
			$this->contains($needle, $haystack, $message);
		}
	}

	public function containsNone(iterable $needles, mixed $haystack, string $message=''): void {
		foreach($needles as $needle){
			$this->notContains($needle, $haystack, $message);
		}
	}

	public function notContains(mixed $needle, mixed $haystack, string $message=''): void {
		$this->recordAssertion();
		$found=false;
		if(is_string($haystack) && (is_string($needle) || is_numeric($needle))){
			$found=str_contains($haystack, (string)$needle);
		}
		elseif(is_array($haystack))
		{
			$found=in_array($needle, $haystack, true);
		}
		if($found===true){
			$this->fail($message!=='' ? $message : 'Expected value not to contain needle.', $needle, $haystack);
		}
	}

	public function matches(string $pattern, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(preg_match($pattern, $actual)!==1){
			$this->fail($message!=='' ? $message : 'Expected string to match pattern.', $pattern, $actual);
		}
	}

	public function notMatches(string $pattern, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(preg_match($pattern, $actual)===1){
			$this->fail($message!=='' ? $message : 'Expected string not to match pattern.', 'not '.$pattern, $actual);
		}
	}

	public function startsWith(string $prefix, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(!str_starts_with($actual, $prefix)){
			$this->fail($message!=='' ? $message : 'Expected string to start with prefix.', $prefix, $actual);
		}
	}

	public function notStartsWith(string $prefix, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(str_starts_with($actual, $prefix)){
			$this->fail($message!=='' ? $message : 'Expected string not to start with prefix.', 'not '.$prefix, $actual);
		}
	}

	public function endsWith(string $suffix, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(!str_ends_with($actual, $suffix)){
			$this->fail($message!=='' ? $message : 'Expected string to end with suffix.', $suffix, $actual);
		}
	}

	public function notEndsWith(string $suffix, string $actual, string $message=''): void {
		$this->recordAssertion();
		if(str_ends_with($actual, $suffix)){
			$this->fail($message!=='' ? $message : 'Expected string not to end with suffix.', 'not '.$suffix, $actual);
		}
	}

	public function isEmpty(mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->isEmptyValue($actual)){
			$this->fail($message!=='' ? $message : 'Expected value to be empty.', 'empty', $actual);
		}
	}

	public function notEmpty(mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($this->isEmptyValue($actual)){
			$this->fail($message!=='' ? $message : 'Expected value not to be empty.', 'not empty', $actual);
		}
	}

	public function length(int $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		$length=$this->valueLength($actual);
		if($length!==$expected){
			$this->fail($message!=='' ? $message : 'Expected value length to match.', $expected, $length);
		}
	}

	public function count(int $expected, Countable|array $actual, string $message=''): void {
		$this->recordAssertion();
		$actual_count=count($actual);
		if($actual_count!==$expected){
			$this->fail($message!=='' ? $message : 'Expected count to match.', $expected, $actual_count);
		}
	}

	public function type(string $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		$type_aliases=[
			'bool'=>'boolean',
			'int'=>'integer',
			'float'=>'double',
		];
		$expected_type=$type_aliases[$expected] ?? $expected;
		if(gettype($actual)!==$expected_type){
			$this->fail($message!=='' ? $message : 'Expected value type to match.', $expected, gettype($actual));
		}
	}

	public function greaterThan(int|float $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || $actual<=$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be greater than threshold.', $expected, $actual);
		}
	}

	public function lessThan(int|float $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || $actual>=$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be less than threshold.', $expected, $actual);
		}
	}

	public function greaterThanOrEqual(int|float $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || $actual<$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be greater than or equal to threshold.', $expected, $actual);
		}
	}

	public function lessThanOrEqual(int|float $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || $actual>$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be less than or equal to threshold.', $expected, $actual);
		}
	}

	public function between(int|float $min, int|float $max, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || $actual<$min || $actual>$max){
			$this->fail($message!=='' ? $message : 'Expected value to be inside inclusive range.', [$min, $max], $actual);
		}
	}

	public function approximately(int|float $expected, mixed $actual, int|float $tolerance, string $message=''): void {
		$this->recordAssertion();
		if(!is_numeric($actual) || abs((float)$actual-(float)$expected)>(float)$tolerance){
			$this->fail($message!=='' ? $message : 'Expected value to be within tolerance.', ['expected'=>$expected, 'tolerance'=>$tolerance], $actual);
		}
	}

	public function isMinorUnits(mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_int($actual)){
			$this->fail($message!=='' ? $message : 'Expected money value to be stored as integer minor units.', 'integer minor units', gettype($actual));
		}
	}

	public function minorUnits(int $expected, mixed $actual, string $message=''): void {
		$this->isMinorUnits($actual, $message);
		$this->same($expected, $actual, $message!=='' ? $message : 'Expected minor-unit value to match.');
	}

	public function moneyAmount(string $expected_decimal, int $minor_units, int $scale=2, string $message=''): void {
		$this->recordAssertion();
		$actual=$this->formatMinorUnits($minor_units, $scale);
		if($actual!==$expected_decimal){
			$this->fail($message!=='' ? $message : 'Expected decimal money display to match minor units.', $expected_decimal, $actual);
		}
	}

	public function instanceOf(string $class, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$actual instanceof $class){
			$this->fail($message!=='' ? $message : 'Expected object instance to match.', $class, is_object($actual) ? $actual::class : gettype($actual));
		}
	}

	public function throws(callable $callback, ?string $class=null, string $message=''): Throwable {
		$this->recordAssertion();
		$throwable=null;
		try{
			$callback();
		}catch(Throwable $caught){
			$throwable=$caught;
		}
		if($throwable===null){
			$this->fail($message!=='' ? $message : 'Expected callback to throw.', $class ?? Throwable::class, 'no exception');
		}
		if($class!==null && !$throwable instanceof $class){
			$this->fail($message!=='' ? $message : 'Expected thrown exception class to match.', $class, $throwable::class);
		}
		return $throwable;
	}

	public function throwsLike(callable $callback, ?string $class=null, ?string $message_contains=null, int|string|null $code=null, string $message=''): Throwable {
		$throwable=$this->throws($callback, $class, $message);
		if($message_contains!==null){
			$this->contains($message_contains, $throwable->getMessage(), $message!=='' ? $message : 'Expected exception message to contain text.');
		}
		if($code!==null){
			$this->same($code, $throwable->getCode(), $message!=='' ? $message : 'Expected exception code to match.');
		}
		return $throwable;
	}

	public function doesNotThrow(callable $callback, string $message=''): mixed {
		$this->recordAssertion();
		try{
			$result=$callback();
		}catch(Throwable $throwable){
			$this->fail($message!=='' ? $message : 'Expected callback not to throw.', 'no exception', $throwable::class.': '.$throwable->getMessage());
		}
		return $result;
	}

	/**
	 * Calls an operation twice and asserts strict repeatability. The first result
	 * is returned so cache and singleton contracts need no duplicate invocation.
	 */
	public function producesStableResult(callable $operation, string $message=''): mixed {
		$first=$operation();
		$second=$operation();
		$this->same($first, $second, $message!=='' ? $message : 'Expected repeated calls to produce a stable result.');
		return $first;
	}

	private function isEmptyValue(mixed $actual): bool {
		if($actual===null || $actual===''){
			return true;
		}
		if(is_array($actual)){
			return $actual===[];
		}
		if($actual instanceof Countable){
			return count($actual)===0;
		}
		return false;
	}

	private function valueLength(mixed $actual): int {
		if(is_string($actual)){
			$length=strlen($actual);
		}
		elseif(is_array($actual) || $actual instanceof Countable)
		{
			$length=count($actual);
		}
		else
		{
			$this->fail('Expected value to have a measurable length.', 'string|array|Countable', gettype($actual));
		}
		return $length;
	}

	private function formatMinorUnits(int $minor_units, int $scale): string {
		$scale=max(0, $scale);
		$negative=$minor_units<0;
		$minor_units=abs($minor_units);
		$factor=10 ** $scale;
		$whole=intdiv($minor_units, $factor);
		$fraction=$scale>0 ? str_pad((string)($minor_units % $factor), $scale, '0', STR_PAD_LEFT) : '';
		return ($negative ? '-' : '').$whole.($scale>0 ? '.'.$fraction : '');
	}

	private function describe(mixed $value): string {
		if(is_scalar($value) || $value===null){
			return var_export($value, true);
		}
		return gettype($value);
	}
}
