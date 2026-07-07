<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;
use Countable;
use ReflectionFunction;
use Throwable;
use Traversable;

final class AssertionFailed extends \Exception {

	/** @param array<string, mixed> $meta */
	public function __construct(string $message, private mixed $expected=null, private mixed $actual=null, private array $meta=[]) {
		parent::__construct($message);
	}

	/** @return array<string, mixed> */
	public function details(): array {
		return [
			'message'=>$this->getMessage(),
			'expected'=>$this->expected,
			'actual'=>$this->actual,
			'meta'=>$this->meta,
		];
	}
}

final class SkippedTest extends \Exception {

	public function __construct(string $message='Test skipped.', private bool $todo=false) {
		parent::__construct($message!=='' ? $message : ($todo ? 'Test marked todo.' : 'Test skipped.'));
	}

	public function isTodo(): bool {
		return $this->todo;
	}
}

final class Expectation {

	public function __construct(private Context $context, private mixed $actual, private bool $negated=false) {}

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

	public function toHavePath(string|array $path, string $message=''): self {
		$this->negated ? $this->context->missingPath($path, $this->actual, $message) : $this->context->hasPath($path, $this->actual, $message);
		return $this;
	}

	public function toHavePathValue(string|array $path, mixed $expected, string $message=''): self {
		$this->negated ? $this->context->pathNotEquals($path, $expected, $this->actual, $message) : $this->context->pathEquals($path, $expected, $this->actual, $message);
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
}

final class Context {

	/** @var array<string, mixed> */
	private array $fixtures=[];
	private int $assertions=0;

	public function __construct(private string $name, private string $dataset='', private string $file='') {}

	public function name(): string {
		return $this->name;
	}

	public function dataset(): string {
		return $this->dataset;
	}

	public function assertions(): int {
		return $this->assertions;
	}

	/** @param array<string, mixed> $fixtures */
	public function setFixtures(array $fixtures): void {
		$this->fixtures=$fixtures;
	}

	public function fixture(string $name): mixed {
		if(!array_key_exists($name, $this->fixtures)){
			throw new AssertionFailed("Fixture '{$name}' is not available.");
		}
		return $this->fixtures[$name];
	}

	public function fakeClock(int|string|\DateTimeInterface $now='now'): FakeClock {
		return Fakes::clock($now);
	}

	public function fakeStorage(): FakeStorage {
		return Fakes::storage();
	}

	public function fakeMailer(): FakeMailer {
		return Fakes::mailer();
	}

	public function fakeHttp(): FakeHttp {
		return Fakes::http();
	}

	public function fakeAuth(mixed $user=null): FakeAuth {
		return Fakes::auth($user);
	}

	public function fakeSql(): FakeSql {
		return Fakes::sql();
	}

	public function fakeDatabase(array $schema=[]): FakeDatabase {
		return Fakes::database($schema);
	}

	public function pdoDatabase(\PDO $pdo): PdoDatabaseAssertions {
		return new PdoDatabaseAssertions($pdo);
	}

	public function fakeQueue(?FakeClock $clock=null): FakeQueue {
		return Fakes::queue($clock);
	}

	public function fakeDialbacks(string $default_scope='framework'): FakeHookBus {
		return Fakes::dialbacks($default_scope);
	}

	public function fakeCallbacks(string $default_scope='app'): FakeHookBus {
		return Fakes::callbacks($default_scope);
	}

	public function fakeReactor(): FakeReactor {
		return Fakes::reactor();
	}

	public function fakePermissions(): FakePermissions {
		return Fakes::permissions();
	}

	public function browser(array $options=[]): BrowserProbe {
		$root=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_root'] ?? ROOTPATH['root'] ?? '') : '';
		return new BrowserProbe($root, $options);
	}

	public function dataphyreModules(): DataphyreModuleBridge {
		$root=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_dataphyre_runtime'] ?? '') : '';
		return new DataphyreModuleBridge($root);
	}

	public function spy(?callable $passthrough=null): Spy {
		return new Spy($passthrough);
	}

	public function mock(array $methods=[]): MockObject {
		return new MockObject($methods);
	}

	public function functionPatch(string $qualified_function, ?callable $handler=null): Spy {
		return FunctionPatches::define($qualified_function, $handler);
	}

	public function staticProxy(string $class): StaticProxy {
		return new StaticProxy($class);
	}

	public function expect(mixed $actual): Expectation {
		return new Expectation($this, $actual);
	}

	public function same(mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if($expected!==$actual){
			$this->fail($message!=='' ? $message : 'Expected values to be strictly identical.', $expected, $actual);
		}
	}

	public function equals(mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if($expected!=$actual){
			$this->fail($message!=='' ? $message : 'Expected values to be equal.', $expected, $actual);
		}
	}

	public function notSame(mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if($expected===$actual){
			$this->fail($message!=='' ? $message : 'Expected values not to be strictly identical.', 'not '.$this->describe($expected), $actual);
		}
	}

	public function notEquals(mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
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
		$this->assertions++;
		if($actual===null){
			$this->fail($message!=='' ? $message : 'Expected a non-null value.', 'not null', null);
		}
	}

	public function contains(mixed $needle, mixed $haystack, string $message=''): void {
		$this->assertions++;
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

	public function notContains(mixed $needle, mixed $haystack, string $message=''): void {
		$this->assertions++;
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
		$this->assertions++;
		if(preg_match($pattern, $actual)!==1){
			$this->fail($message!=='' ? $message : 'Expected string to match pattern.', $pattern, $actual);
		}
	}

	public function notMatches(string $pattern, string $actual, string $message=''): void {
		$this->assertions++;
		if(preg_match($pattern, $actual)===1){
			$this->fail($message!=='' ? $message : 'Expected string not to match pattern.', 'not '.$pattern, $actual);
		}
	}

	public function startsWith(string $prefix, string $actual, string $message=''): void {
		$this->assertions++;
		if(!str_starts_with($actual, $prefix)){
			$this->fail($message!=='' ? $message : 'Expected string to start with prefix.', $prefix, $actual);
		}
	}

	public function notStartsWith(string $prefix, string $actual, string $message=''): void {
		$this->assertions++;
		if(str_starts_with($actual, $prefix)){
			$this->fail($message!=='' ? $message : 'Expected string not to start with prefix.', 'not '.$prefix, $actual);
		}
	}

	public function endsWith(string $suffix, string $actual, string $message=''): void {
		$this->assertions++;
		if(!str_ends_with($actual, $suffix)){
			$this->fail($message!=='' ? $message : 'Expected string to end with suffix.', $suffix, $actual);
		}
	}

	public function notEndsWith(string $suffix, string $actual, string $message=''): void {
		$this->assertions++;
		if(str_ends_with($actual, $suffix)){
			$this->fail($message!=='' ? $message : 'Expected string not to end with suffix.', 'not '.$suffix, $actual);
		}
	}

	public function isEmpty(mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$this->isEmptyValue($actual)){
			$this->fail($message!=='' ? $message : 'Expected value to be empty.', 'empty', $actual);
		}
	}

	public function notEmpty(mixed $actual, string $message=''): void {
		$this->assertions++;
		if($this->isEmptyValue($actual)){
			$this->fail($message!=='' ? $message : 'Expected value not to be empty.', 'not empty', $actual);
		}
	}

	public function length(int $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		$length=$this->valueLength($actual);
		if($length!==$expected){
			$this->fail($message!=='' ? $message : 'Expected value length to match.', $expected, $length);
		}
	}

	public function count(int $expected, Countable|array $actual, string $message=''): void {
		$this->assertions++;
		$actual_count=count($actual);
		if($actual_count!==$expected){
			$this->fail($message!=='' ? $message : 'Expected count to match.', $expected, $actual_count);
		}
	}

	public function type(string $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
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

	public function hasKey(string|int $key, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_array($actual) || !array_key_exists($key, $actual)){
			$this->fail($message!=='' ? $message : 'Expected array key to exist.', $key, is_array($actual) ? array_keys($actual) : gettype($actual));
		}
	}

	public function missingKey(string|int $key, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(is_array($actual) && array_key_exists($key, $actual)){
			$this->fail($message!=='' ? $message : 'Expected array key to be absent.', 'missing key '.$key, array_keys($actual));
		}
	}

	public function hasPath(string|array $path, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to exist.', $this->pathLabel($path), $this->pathShape($actual));
		}
	}

	public function missingPath(string|array $path, mixed $actual, string $message=''): void {
		$this->assertions++;
		if($this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to be absent.', 'missing path '.$this->pathLabel($path), $value);
		}
	}

	public function pathEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to exist.', $this->pathLabel($path), $this->pathShape($actual));
		}
		if($value!==$expected){
			$this->fail($message!=='' ? $message : 'Expected path value to match.', $expected, $value);
		}
	}

	public function pathNotEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$this->valueAtPath($actual, $path, $value)){
			return;
		}
		if($value===$expected){
			$this->fail($message!=='' ? $message : 'Expected path value not to match.', 'not '.$this->describe($expected), $value);
		}
	}

	/** @param array<mixed> $expected */
	public function subset(array $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$this->subsetMatches($expected, $actual)){
			$this->fail($message!=='' ? $message : 'Expected value to contain subset.', $expected, $actual);
		}
	}

	public function greaterThan(int|float $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || $actual<=$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be greater than threshold.', $expected, $actual);
		}
	}

	public function lessThan(int|float $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || $actual>=$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be less than threshold.', $expected, $actual);
		}
	}

	public function greaterThanOrEqual(int|float $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || $actual<$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be greater than or equal to threshold.', $expected, $actual);
		}
	}

	public function lessThanOrEqual(int|float $expected, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || $actual>$expected){
			$this->fail($message!=='' ? $message : 'Expected value to be less than or equal to threshold.', $expected, $actual);
		}
	}

	public function between(int|float $min, int|float $max, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || $actual<$min || $actual>$max){
			$this->fail($message!=='' ? $message : 'Expected value to be inside inclusive range.', [$min, $max], $actual);
		}
	}

	public function approximately(int|float $expected, mixed $actual, int|float $tolerance, string $message=''): void {
		$this->assertions++;
		if(!is_numeric($actual) || abs((float)$actual-(float)$expected)>(float)$tolerance){
			$this->fail($message!=='' ? $message : 'Expected value to be within tolerance.', ['expected'=>$expected, 'tolerance'=>$tolerance], $actual);
		}
	}

	public function isMinorUnits(mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!is_int($actual)){
			$this->fail($message!=='' ? $message : 'Expected money value to be stored as integer minor units.', 'integer minor units', gettype($actual));
		}
	}

	public function minorUnits(int $expected, mixed $actual, string $message=''): void {
		$this->isMinorUnits($actual, $message);
		$this->same($expected, $actual, $message!=='' ? $message : 'Expected minor-unit value to match.');
	}

	public function moneyAmount(string $expected_decimal, int $minor_units, int $scale=2, string $message=''): void {
		$this->assertions++;
		$actual=$this->formatMinorUnits($minor_units, $scale);
		if($actual!==$expected_decimal){
			$this->fail($message!=='' ? $message : 'Expected decimal money display to match minor units.', $expected_decimal, $actual);
		}
	}

	public function instanceOf(string $class, mixed $actual, string $message=''): void {
		$this->assertions++;
		if(!$actual instanceof $class){
			$this->fail($message!=='' ? $message : 'Expected object instance to match.', $class, is_object($actual) ? $actual::class : gettype($actual));
		}
	}

	public function throws(callable $callback, ?string $class=null, string $message=''): Throwable {
		$this->assertions++;
		try{
			$callback();
		}catch(Throwable $throwable){
			if($class!==null && !$throwable instanceof $class){
				$this->fail($message!=='' ? $message : 'Expected thrown exception class to match.', $class, $throwable::class);
			}
			return $throwable;
		}
		$this->fail($message!=='' ? $message : 'Expected callback to throw.', $class ?? Throwable::class, 'no exception');
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
		$this->assertions++;
		try{
			return $callback();
		}catch(Throwable $throwable){
			$this->fail($message!=='' ? $message : 'Expected callback not to throw.', 'no exception', $throwable::class.': '.$throwable->getMessage());
		}
	}

	/** @param array<string,mixed>|object $response */
	public function responseStatus(int $expected, array|object $response, string $message=''): void {
		$this->same($expected, (int)$this->responseValue($response, ['status', 'status_code', 'code'], 0), $message!=='' ? $message : 'Expected response status to match.');
	}

	/** @param array<string,mixed>|object $response */
	public function responseHeader(string $name, string $expected, array|object $response, string $message=''): void {
		$this->assertions++;
		$headers=$this->responseHeaders($response);
		$key=strtolower($name);
		$actual=$headers[$key] ?? null;
		if((string)$actual!==$expected){
			$this->fail($message!=='' ? $message : 'Expected response header to match.', [$name=>$expected], [$name=>$actual]);
		}
	}

	/** @param array<string,mixed>|object $response */
	public function responseJsonPath(string|array $path, mixed $expected, array|object $response, string $message=''): void {
		$this->pathEquals($path, $expected, $this->responseJson($response), $message!=='' ? $message : 'Expected response JSON path to match.');
	}

	/** @param array<string,mixed>|object $response @param array<mixed> $expected */
	public function responseJsonSubset(array $expected, array|object $response, string $message=''): void {
		$this->subset($expected, $this->responseJson($response), $message!=='' ? $message : 'Expected response JSON to contain subset.');
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasField(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('field', $name, $this->surfaceItems($surface, ['fields', 'columns']), $message);
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasFilter(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('filter', $name, $this->surfaceItems($surface, ['filters']), $message);
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasAction(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('action', $name, $this->surfaceItems($surface, ['actions', 'bulk_actions', 'record_actions']), $message);
	}

	public function schemaHasColumn(array|object|string $schema, string $column, string $message=''): void {
		$this->contains($column, $this->schemaColumns($schema), $message!=='' ? $message : 'Expected schema to declare column.');
	}

	/** @param array<string,mixed> $query */
	public function queryMatches(array $query, string $pattern, ?array $bindings=null, string $message=''): void {
		$sql=(string)($query['sql'] ?? $query['query'] ?? '');
		$this->matches($pattern, $sql, $message!=='' ? $message : 'Expected query SQL to match pattern.');
		if($bindings!==null){
			$this->same($bindings, (array)($query['bindings'] ?? []), $message!=='' ? $message : 'Expected query bindings to match.');
		}
	}

	/** @param array<int,array<string,mixed>> $trace @param array<string,mixed> $subset */
	public function traceContains(array $trace, string $type, array $subset=[], string $message=''): void {
		$this->recordContains($trace, ['type'=>$type]+$subset, $message!=='' ? $message : 'Expected trace to contain matching entry.');
	}

	/** @param array<int,array<string,mixed>> $events @param array<string,mixed> $subset */
	public function eventContains(array $events, string $name, array $subset=[], string $message=''): void {
		$this->recordContains($events, ['name'=>$name]+$subset, $message!=='' ? $message : 'Expected event list to contain matching entry.');
	}

	public function htmlContainsText(string $html, string $text, string $message=''): void {
		$this->assertions++;
		$actual=trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES|ENT_HTML5, 'UTF-8')) ?? '');
		if(!str_contains($actual, $text)){
			$this->fail($message!=='' ? $message : 'Expected HTML text to contain value.', $text, $actual);
		}
	}

	public function htmlHasSelector(string $html, string $selector, string $message=''): void {
		$this->assertions++;
		$matches=HtmlProbe::matches($html, $selector);
		if($matches===[]){
			$this->fail($message!=='' ? $message : 'Expected HTML selector to match.', $selector, HtmlProbe::shape($html));
		}
	}

	public function htmlMissingSelector(string $html, string $selector, string $message=''): void {
		$this->assertions++;
		$matches=HtmlProbe::matches($html, $selector);
		if($matches!==[]){
			$this->fail($message!=='' ? $message : 'Expected HTML selector to be absent.', 'missing '.$selector, $matches);
		}
	}

	public function htmlAttribute(string $html, string $selector, string $attribute, string $expected, string $message=''): void {
		$this->assertions++;
		foreach(HtmlProbe::matches($html, $selector) as $node){
			if((string)($node['attributes'][strtolower($attribute)] ?? '')===$expected){
				return;
			}
		}
		$this->fail($message!=='' ? $message : 'Expected HTML selector attribute to match.', [$selector=>$attribute.'='.$expected], HtmlProbe::matches($html, $selector));
	}

	public function tableHas(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void {
		$database->assertTableHas($this, $table, $expected, $message);
	}

	public function tableMissing(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void {
		$database->assertTableMissing($this, $table, $expected, $message);
	}

	public function tableCount(FakeDatabase|PdoDatabaseAssertions $database, string $table, int $expected, string $message=''): void {
		$database->assertTableCount($this, $table, $expected, $message);
	}

	public function permits(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void {
		$this->isTrue($permissions->permits($actor, $ability, $resource), $message!=='' ? $message : 'Expected permission policy to allow ability.');
	}

	public function denies(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void {
		$this->isFalse($permissions->permits($actor, $ability, $resource), $message!=='' ? $message : 'Expected permission policy to deny ability.');
	}

	public function benchmark(callable $callback, int $iterations=1, int $warmup=0): BenchmarkResult {
		$iterations=max(1, $iterations);
		for($i=0; $i<$warmup; $i++){
			$callback();
		}
		$memory_before=memory_get_usage(true);
		$peak_before=memory_get_peak_usage(true);
		$durations=[];
		for($i=0; $i<$iterations; $i++){
			$started=hrtime(true);
			$callback();
			$durations[]=(hrtime(true)-$started)/1000000;
		}
		return new BenchmarkResult($durations, memory_get_usage(true)-$memory_before, max(0, memory_get_peak_usage(true)-$peak_before));
	}

	public function performanceUnder(BenchmarkResult|callable $benchmark, int|float $max_millis, ?int $iterations=null, string $message=''): BenchmarkResult {
		$result=$benchmark instanceof BenchmarkResult ? $benchmark : $this->benchmark($benchmark, $iterations ?? 1);
		$this->assertions++;
		if($result->maxMillis()>$max_millis){
			$this->fail($message!=='' ? $message : 'Expected benchmark max duration to stay under threshold.', $max_millis, $result->maxMillis(), ['benchmark'=>$result->toArray()]);
		}
		return $result;
	}

	public function memoryUnder(callable $callback, int $max_bytes, string $message=''): void {
		$this->assertions++;
		$before=memory_get_usage(true);
		$callback();
		$used=max(0, memory_get_usage(true)-$before);
		if($used>$max_bytes){
			$this->fail($message!=='' ? $message : 'Expected memory delta to stay under threshold.', $max_bytes, $used);
		}
	}

	public function forAll(iterable $cases, callable $assertion, int $limit=100): void {
		$index=0;
		foreach($cases as $label=>$case){
			if($index++>=$limit){
				break;
			}
			try{
				$args=is_array($case) && self::isListValue($case) ? $case : [$case];
				$assertion($this, ...$args);
				$this->assertions++;
			}catch(Throwable $throwable){
				$this->fail('Property failed for generated case '.$label.'.', 'property holds', ['case'=>$case, 'error'=>$throwable->getMessage()]);
			}
		}
	}

	public function fuzz(GeneratedCases $cases, callable $assertion): void {
		$replay=(string)(getenv('DATAPHYRE_FUZZ_REPLAY') ?: '');
		$rows=$replay!=='' ? $cases->replay($replay) : $cases;
		foreach($rows as $label=>$case){
			try{
				$args=is_array($case) && self::isListValue($case) ? $case : [$case];
				$assertion($this, ...$args);
				$this->assertions++;
			}catch(Throwable $throwable){
				$shrunk=$cases->shrink($case, $assertion, $this);
				$this->fail('Fuzz property failed for generated case '.$label.'.', 'property holds', [
					'case'=>$case,
					'shrunk'=>$shrunk,
					'error'=>$throwable->getMessage(),
				], [
					'seed'=>$cases->seed(),
					'replay'=>$cases->replayToken($label, $case),
				]);
			}
		}
	}

	public function snapshot(string $name, mixed $actual, string $message=''): void {
		$this->assertions++;
		$path=$this->snapshotPath($name);
		$content=$this->snapshotContent($actual);
		$update=in_array(strtolower((string)(getenv('DATAPHYRE_UPDATE_SNAPSHOTS') ?: '')), ['1', 'true', 'yes', 'on'], true);
		if($update){
			$dir=dirname($path);
			if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
				$this->fail('Snapshot directory could not be created.', $dir, null);
			}
			file_put_contents($path, $content);
			return;
		}
		if(!is_file($path)){
			$this->fail($message!=='' ? $message : 'Snapshot file is missing. Set DATAPHYRE_UPDATE_SNAPSHOTS=1 to create it.', $path, null);
		}
		$expected=(string)file_get_contents($path);
		if($expected!==$content){
			$this->fail($message!=='' ? $message : 'Snapshot content changed.', $expected, $content, ['diff'=>$this->unifiedDiff($expected, $content)]);
		}
	}

	public function skip(string $reason=''): never {
		throw new SkippedTest($reason!=='' ? $reason : 'Test skipped.');
	}

	public function todo(string $reason=''): never {
		throw new SkippedTest($reason!=='' ? $reason : 'Test marked todo.', true);
	}

	public function fail(string $message, mixed $expected=null, mixed $actual=null, array $meta=[]): never {
		throw new AssertionFailed($message, $expected, $actual, $meta);
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
			return strlen($actual);
		}
		if(is_array($actual) || $actual instanceof Countable){
			return count($actual);
		}
		$this->fail('Expected value to have a measurable length.', 'string|array|Countable', gettype($actual));
	}

	private function valueAtPath(mixed $actual, string|array $path, mixed &$value): bool {
		$current=$actual;
		foreach($this->pathParts($path) as $part){
			if(is_array($current) && array_key_exists($part, $current)){
				$current=$current[$part];
				continue;
			}
			if(is_object($current) && isset($current->{$part})){
				$current=$current->{$part};
				continue;
			}
			return false;
		}
		$value=$current;
		return true;
	}

	/** @return array<int,string|int> */
	private function pathParts(string|array $path): array {
		if(is_array($path)){
			return array_values($path);
		}
		$path=preg_replace('/\[([^\]]+)\]/', '.$1', $path) ?? $path;
		$parts=[];
		foreach(explode('.', $path) as $part){
			$part=trim($part);
			if($part===''){
				continue;
			}
			$parts[]=ctype_digit($part) ? (int)$part : $part;
		}
		return $parts;
	}

	private function pathLabel(string|array $path): string {
		return is_array($path) ? implode('.', array_map('strval', $path)) : $path;
	}

	private function pathShape(mixed $actual): mixed {
		if(is_array($actual)){
			return array_keys($actual);
		}
		if(is_object($actual)){
			return array_keys(get_object_vars($actual));
		}
		return gettype($actual);
	}

	/** @param array<mixed> $expected */
	private function subsetMatches(array $expected, mixed $actual): bool {
		if(is_object($actual)){
			$actual=get_object_vars($actual);
		}
		if(!is_array($actual)){
			return false;
		}
		foreach($expected as $key=>$value){
			if(!array_key_exists($key, $actual)){
				return false;
			}
			if(is_array($value)){
				if(!$this->subsetMatches($value, $actual[$key])){
					return false;
				}
				continue;
			}
			if($actual[$key]!==$value){
				return false;
			}
		}
		return true;
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

	/** @param array<string,mixed>|object $response */
	private function responseValue(array|object $response, array $keys, mixed $default=null): mixed {
		foreach($keys as $key){
			if(is_array($response) && array_key_exists($key, $response)){
				return $response[$key];
			}
			if(is_object($response) && isset($response->{$key})){
				return $response->{$key};
			}
		}
		foreach($keys as $key){
			$method=$key;
			if(is_object($response) && method_exists($response, $method)){
				return $response->{$method}();
			}
		}
		return $default;
	}

	/** @param array<string,mixed>|object $response @return array<string,mixed> */
	private function responseHeaders(array|object $response): array {
		$headers=$this->responseValue($response, ['headers'], []);
		$normalized=[];
		foreach(is_array($headers) ? $headers : [] as $key=>$value){
			$normalized[strtolower((string)$key)]=$value;
		}
		return $normalized;
	}

	/** @param array<string,mixed>|object $response */
	private function responseJson(array|object $response): mixed {
		$body=$this->responseValue($response, ['json', 'body', 'content'], null);
		if(is_string($body)){
			$decoded=json_decode($body, true);
			if(json_last_error()===JSON_ERROR_NONE){
				return $decoded;
			}
		}
		return $body;
	}

	/** @param array<string,mixed>|object $surface @param array<int,string> $keys @return array<int,mixed> */
	private function surfaceItems(array|object $surface, array $keys): array {
		foreach($keys as $key){
			$value=$this->responseValue($surface, [$key], null);
			if(is_array($value)){
				return $value;
			}
		}
		return [];
	}

	/** @param array<int,mixed> $items */
	private function hasNamedItem(string $kind, string $name, array $items, string $message=''): void {
		$this->assertions++;
		foreach($items as $key=>$item){
			if(is_string($key) && $key===$name){
				return;
			}
			if(is_array($item)){
				foreach(['name', 'key', 'id', 'field', 'action'] as $name_key){
					if((string)($item[$name_key] ?? '')===$name){
						return;
					}
				}
			}
			elseif(is_object($item))
			{
				foreach(['name', 'key', 'id', 'field', 'action'] as $name_key){
					if(isset($item->{$name_key}) && (string)$item->{$name_key}===$name){
						return;
					}
				}
			}
		}
		$this->fail($message!=='' ? $message : 'Expected Panel '.$kind.' to be declared.', $name, $items);
	}

	/** @return array<int,string> */
	private function schemaColumns(array|object|string $schema): array {
		if(is_string($schema) && class_exists($schema) && defined($schema.'::COLUMNS')){
			return array_values(array_map('strval', (array)constant($schema.'::COLUMNS')));
		}
		if(is_object($schema)){
			foreach(['columns', 'fields'] as $method){
				if(method_exists($schema, $method)){
					return array_values(array_map('strval', (array)$schema->{$method}()));
				}
			}
			$schema=get_object_vars($schema);
		}
		if(is_array($schema)){
			foreach(['columns', 'COLUMNS', 'fields'] as $key){
				if(isset($schema[$key]) && is_array($schema[$key])){
					return array_values(array_map('strval', $schema[$key]));
				}
			}
			return array_values(array_map('strval', array_keys($schema)));
		}
		return [];
	}

	/** @param array<int,array<string,mixed>> $records @param array<string,mixed> $expected */
	private function recordContains(array $records, array $expected, string $message): void {
		$this->assertions++;
		foreach($records as $record){
			if($this->subsetMatches($expected, $record)){
				return;
			}
		}
		$this->fail($message, $expected, $records);
	}

	private function snapshotPath(string $name): string {
		$base=$this->file!=='' ? dirname($this->file) : sys_get_temp_dir();
		$id=$this->sanitizeSnapshotName(basename($this->file ?: 'dataphyre-test').'.'.$this->name.($this->dataset!=='' ? '.'.$this->dataset : '').'.'.$name);
		return $base.'/__snapshots__/'.$id.'.snap';
	}

	private function snapshotContent(mixed $actual): string {
		if(is_string($actual)){
			return $actual;
		}
		return json_encode($actual, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
	}

	private function unifiedDiff(string $expected, string $actual, int $context=3): string {
		$old=preg_split('/\R/', rtrim($expected, "\r\n")) ?: [];
		$new=preg_split('/\R/', rtrim($actual, "\r\n")) ?: [];
		$old_count=count($old);
		$new_count=count($new);
		$prefix=0;
		while($prefix<$old_count && $prefix<$new_count && $old[$prefix]===$new[$prefix]){
			$prefix++;
		}
		$suffix=0;
		while($suffix<$old_count-$prefix && $suffix<$new_count-$prefix && $old[$old_count-1-$suffix]===$new[$new_count-1-$suffix]){
			$suffix++;
		}
		$lines=['--- expected', '+++ actual'];
		$head_start=max(0, $prefix-$context);
		for($i=$head_start; $i<$prefix; $i++){
			$lines[]=' '.$old[$i];
		}
		for($i=$prefix; $i<$old_count-$suffix; $i++){
			$lines[]='-'.$old[$i];
		}
		for($i=$prefix; $i<$new_count-$suffix; $i++){
			$lines[]='+'.$new[$i];
		}
		$tail_end=min($old_count, $old_count-$suffix+$context);
		for($i=max($prefix, $old_count-$suffix); $i<$tail_end; $i++){
			$lines[]=' '.$old[$i];
		}
		if(count($lines)>80){
			$lines=array_merge(array_slice($lines, 0, 40), ['... diff truncated ...'], array_slice($lines, -40));
		}
		return implode("\n", $lines);
	}

	private function sanitizeSnapshotName(string $name): string {
		$name=strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?? $name);
		return trim($name, '._-') ?: 'snapshot';
	}

	private static function isListValue(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}

	private function describe(mixed $value): string {
		if(is_scalar($value) || $value===null){
			return var_export($value, true);
		}
		return gettype($value);
	}
}

final class Fakes {

	public static function clock(int|string|\DateTimeInterface $now='now'): FakeClock {
		return new FakeClock($now);
	}

	public static function storage(): FakeStorage {
		return new FakeStorage();
	}

	public static function mailer(): FakeMailer {
		return new FakeMailer();
	}

	public static function http(): FakeHttp {
		return new FakeHttp();
	}

	public static function auth(mixed $user=null): FakeAuth {
		return new FakeAuth($user);
	}

	public static function sql(): FakeSql {
		return new FakeSql();
	}

	public static function database(array $schema=[]): FakeDatabase {
		return new FakeDatabase($schema);
	}

	public static function queue(?FakeClock $clock=null): FakeQueue {
		return new FakeQueue($clock ?? new FakeClock());
	}

	public static function dialbacks(string $default_scope='framework'): FakeHookBus {
		return new FakeHookBus('dialback', $default_scope);
	}

	public static function callbacks(string $default_scope='app'): FakeHookBus {
		return new FakeHookBus('callback', $default_scope);
	}

	public static function reactor(): FakeReactor {
		return new FakeReactor();
	}

	public static function permissions(): FakePermissions {
		return new FakePermissions();
	}
}

final class FakeClock {

	private int $timestamp;

	public function __construct(int|string|\DateTimeInterface $now='now') {
		$this->timestamp=$this->normalize($now);
	}

	public function now(): \DateTimeImmutable {
		return (new \DateTimeImmutable('@'.$this->timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	}

	public function timestamp(): int {
		return $this->timestamp;
	}

	public function freeze(int|string|\DateTimeInterface $now): self {
		$this->timestamp=$this->normalize($now);
		return $this;
	}

	public function travelTo(int|string|\DateTimeInterface $now): self {
		return $this->freeze($now);
	}

	public function advance(int $seconds): self {
		$this->timestamp+=$seconds;
		return $this;
	}

	public function travel(int $seconds): self {
		return $this->advance($seconds);
	}

	public function rewind(int $seconds): self {
		$this->timestamp-=$seconds;
		return $this;
	}

	private function normalize(int|string|\DateTimeInterface $value): int {
		if(is_int($value)){
			return $value;
		}
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		$timestamp=strtotime($value);
		if($timestamp===false){
			throw new \InvalidArgumentException('FakeClock could not parse timestamp value.');
		}
		return $timestamp;
	}
}

final class FakeStorage {

	/** @var array<string, string> */
	private array $objects=[];

	public function put(string $path, string $contents): void {
		$this->objects[$this->path($path)]=$contents;
	}

	public function write(string $path, string $contents): void {
		$this->put($path, $contents);
	}

	public function get(string $path, ?string $default=null): ?string {
		$path=$this->path($path);
		return $this->objects[$path] ?? $default;
	}

	public function read(string $path, ?string $default=null): ?string {
		return $this->get($path, $default);
	}

	public function exists(string $path): bool {
		return array_key_exists($this->path($path), $this->objects);
	}

	public function has(string $path): bool {
		return $this->exists($path);
	}

	public function delete(string $path): void {
		unset($this->objects[$this->path($path)]);
	}

	public function remove(string $path): void {
		$this->delete($path);
	}

	public function url(string $path): string {
		return 'test-storage://'.$this->path($path);
	}

	/** @return array<int, string> */
	public function files(string $prefix=''): array {
		$prefix=$this->path($prefix);
		$files=[];
		foreach(array_keys($this->objects) as $path){
			if($prefix==='' || str_starts_with($path, $prefix)){
				$files[]=$path;
			}
		}
		sort($files);
		return $files;
	}

	/** @return array<string, string> */
	public function all(): array {
		ksort($this->objects);
		return $this->objects;
	}

	public function assertExists(Context $t, string $path): void {
		$t->isTrue($this->exists($path), 'Expected fake storage path to exist.');
	}

	public function assertMissing(Context $t, string $path): void {
		$t->isFalse($this->exists($path), 'Expected fake storage path to be missing.');
	}

	public function assertStored(Context $t, string $path, ?string $contents=null): void {
		$this->assertExists($t, $path);
		if($contents!==null){
			$t->same($contents, $this->get($path), 'Expected fake storage contents to match.');
		}
	}

	private function path(string $path): string {
		return trim(str_replace('\\', '/', $path), '/');
	}
}

final class FakeMailer {

	/** @var array<int, array{to:string, subject:string, payload:array<string, mixed>}> */
	private array $messages=[];

	/** @param array<string, mixed> $payload */
	public function send(string $to, string $subject, array $payload=[]): void {
		$this->messages[]=[
			'to'=>$to,
			'subject'=>$subject,
			'payload'=>$payload,
			'queued'=>false,
		];
	}

	/** @param array<string, mixed> $payload */
	public function queue(string $to, string $subject, array $payload=[]): void {
		$this->messages[]=[
			'to'=>$to,
			'subject'=>$subject,
			'payload'=>$payload,
			'queued'=>true,
		];
	}

	/** @return array<int, array{to:string, subject:string, payload:array<string, mixed>}> */
	public function sent(): array {
		return $this->messages;
	}

	/** @return array{to:string, subject:string, payload:array<string, mixed>}|null */
	public function last(): ?array {
		return $this->messages[count($this->messages)-1] ?? null;
	}

	public function count(): int {
		return count($this->messages);
	}

	public function assertSent(Context $t, string $to='', string $subject='', array $payload_subset=[]): void {
		foreach($this->messages as $message){
			if($to!=='' && $message['to']!==$to){
				continue;
			}
			if($subject!=='' && $message['subject']!==$subject){
				continue;
			}
			if($payload_subset!==[]){
				try{
					$t->subset($payload_subset, $message['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$t->isTrue(true, 'Mail message was sent.');
			return;
		}
		$t->fail('Expected fake mailer to contain a sent message.', ['to'=>$to, 'subject'=>$subject, 'payload_subset'=>$payload_subset], $this->messages);
	}

	public function assertSentCount(Context $t, int $expected): void {
		$t->same($expected, $this->count(), 'Expected fake mailer sent count to match.');
	}
}

final class FakeHttp {

	/** @var array<string, array{status:int, body:mixed, headers:array<string, string>}> */
	private array $responses=[];
	/** @var array<int, array{method:string, url:string, payload:mixed, headers:array<string, string>}> */
	private array $requests=[];

	/** @param array<string, string> $headers */
	public function respond(string $method, string $url, int $status=200, mixed $body=null, array $headers=[]): void {
		$this->responses[$this->key($method, $url)]=[
			'status'=>$status,
			'body'=>$body,
			'headers'=>$headers,
		];
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function request(string $method, string $url, mixed $payload=null, array $headers=[]): array {
		$this->requests[]=[
			'method'=>strtoupper($method),
			'url'=>$url,
			'payload'=>$payload,
			'headers'=>$headers,
		];
		return $this->responses[$this->key($method, $url)] ?? [
			'status'=>404,
			'body'=>null,
			'headers'=>[],
		];
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function get(string $url, array $headers=[]): array {
		return $this->request('GET', $url, null, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function post(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('POST', $url, $payload, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function put(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('PUT', $url, $payload, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function delete(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('DELETE', $url, $payload, $headers);
	}

	/** @return array<int, array{method:string, url:string, payload:mixed, headers:array<string, string>}> */
	public function requests(): array {
		return $this->requests;
	}

	public function assertRequested(Context $t, string $method, string $url, ?array $payload_subset=null): void {
		foreach($this->requests as $request){
			if($request['method']!==strtoupper($method) || $request['url']!==$url){
				continue;
			}
			if($payload_subset!==null){
				try{
					$t->subset($payload_subset, $request['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$t->isTrue(true, 'HTTP request was recorded.');
			return;
		}
		$t->fail('Expected fake HTTP client to contain request.', ['method'=>strtoupper($method), 'url'=>$url, 'payload_subset'=>$payload_subset], $this->requests);
	}

	public function assertRequestCount(Context $t, int $expected): void {
		$t->same($expected, count($this->requests), 'Expected fake HTTP request count to match.');
	}

	private function key(string $method, string $url): string {
		return strtoupper($method).' '.$url;
	}
}

final class FakeAuth {

	private mixed $user;

	public function __construct(mixed $user=null) {
		$this->user=$user;
	}

	public function login(mixed $user): void {
		$this->user=$user;
	}

	public function logout(): void {
		$this->user=null;
	}

	public function check(): bool {
		return $this->user!==null;
	}

	public function user(): mixed {
		return $this->user;
	}

	public function id(): mixed {
		if(is_array($this->user)){
			return $this->user['id'] ?? null;
		}
		if(is_object($this->user) && isset($this->user->id)){
			return $this->user->id;
		}
		return is_scalar($this->user) ? $this->user : null;
	}

	public function assertAuthenticated(Context $t): void {
		$t->isTrue($this->check(), 'Expected fake auth to be authenticated.');
	}

	public function assertGuest(Context $t): void {
		$t->isFalse($this->check(), 'Expected fake auth to be a guest.');
	}

	public function assertAuthenticatedAs(Context $t, mixed $id): void {
		$t->same($id, $this->id(), 'Expected fake auth user id to match.');
	}
}

final class FakeSql {

	/** @var array<int, array{sql:string, bindings:array<int|string, mixed>}> */
	private array $queries=[];
	private bool $reject_unbound_writes=false;

	public function rejectUnboundWrites(bool $reject=true): self {
		$this->reject_unbound_writes=$reject;
		return $this;
	}

	/** @param array<int|string, mixed> $bindings @return array<int, array<string, mixed>> */
	public function query(string $sql, array $bindings=[]): array {
		if($this->reject_unbound_writes===true && $bindings===[] && preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql)===1){
			throw new AssertionFailed('FakeSql rejected an unbound write query.', 'bound write query', $sql);
		}
		$this->queries[]=[
			'sql'=>$sql,
			'bindings'=>$bindings,
		];
		return [];
	}

	/** @return array<int, array{sql:string, bindings:array<int|string, mixed>}> */
	public function queries(): array {
		return $this->queries;
	}

	public function assertQueryCount(Context $t, int $expected): void {
		$t->same($expected, count($this->queries), 'Expected fake SQL query count to match.');
	}

	public function assertQueried(Context $t, string $pattern, ?array $bindings=null): void {
		foreach($this->queries as $query){
			if(preg_match($pattern, $query['sql'])!==1){
				continue;
			}
			if($bindings!==null && $query['bindings']!==$bindings){
				continue;
			}
			$t->isTrue(true, 'SQL query was recorded.');
			return;
		}
		$t->fail('Expected fake SQL to contain matching query.', ['pattern'=>$pattern, 'bindings'=>$bindings], $this->queries);
	}

	public function assertNoUnboundWrites(Context $t): void {
		foreach($this->queries as $query){
			if($query['bindings']===[] && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['sql'])===1){
				$t->fail('Expected write queries to use bindings.', 'bound write query', $query['sql']);
			}
		}
		$t->isTrue(true, 'SQL write bindings are present.');
	}
}

final class FakeDatabase {

	/** @var array<string, array<string, mixed>> */
	private array $schema=[];
	/** @var array<string, array<int, array<string, mixed>>> */
	private array $tables=[];
	/** @var array<int, array{schema:array<string, array<string, mixed>>, tables:array<string, array<int, array<string, mixed>>>}> */
	private array $transactions=[];

	public function __construct(array $schema=[]) {
		foreach($schema as $table=>$columns){
			$this->createTable((string)$table, is_array($columns) ? $columns : []);
		}
	}

	public function createTable(string $table, array $columns=[]): self {
		$table=$this->tableName($table);
		$this->schema[$table]=$columns;
		$this->tables[$table]=$this->tables[$table] ?? [];
		return $this;
	}

	public function begin(): self {
		$this->transactions[]=[
			'schema'=>$this->schema,
			'tables'=>$this->tables,
		];
		return $this;
	}

	public function commit(): self {
		array_pop($this->transactions);
		return $this;
	}

	public function rollback(): self {
		$snapshot=array_pop($this->transactions);
		if(is_array($snapshot)){
			$this->schema=$snapshot['schema'];
			$this->tables=$snapshot['tables'];
		}
		return $this;
	}

	public function transaction(callable $callback): mixed {
		$this->begin();
		try{
			$result=$callback($this);
			$this->commit();
			return $result;
		}catch(Throwable $throwable){
			$this->rollback();
			throw $throwable;
		}
	}

	public function insert(string $table, array $row): self {
		$table=$this->tableName($table);
		$this->tables[$table]=$this->tables[$table] ?? [];
		$this->tables[$table][]=$row;
		return $this;
	}

	public function update(string $table, array $where, array $values): int {
		$count=0;
		$table=$this->tableName($table);
		foreach($this->tables[$table] ?? [] as $index=>$row){
			if($this->rowMatches($row, $where)){
				$this->tables[$table][$index]=$values+$row;
				$count++;
			}
		}
		return $count;
	}

	public function delete(string $table, array $where): int {
		$count=0;
		$table=$this->tableName($table);
		$rows=[];
		foreach($this->tables[$table] ?? [] as $row){
			if($this->rowMatches($row, $where)){
				$count++;
				continue;
			}
			$rows[]=$row;
		}
		$this->tables[$table]=$rows;
		return $count;
	}

	/** @return array<int, array<string, mixed>> */
	public function rows(string $table): array {
		return array_values($this->tables[$this->tableName($table)] ?? []);
	}

	/** @return array<string, mixed> */
	public function schema(string $table): array {
		return $this->schema[$this->tableName($table)] ?? [];
	}

	public function assertTableHas(Context $t, string $table, array $expected, string $message=''): void {
		foreach($this->rows($table) as $row){
			try{
				$t->subset($expected, $row);
				return;
			}catch(AssertionFailed){
			}
		}
		$t->fail($message!=='' ? $message : 'Expected fake database table to contain row.', [$table=>$expected], $this->rows($table));
	}

	public function assertTableMissing(Context $t, string $table, array $expected, string $message=''): void {
		foreach($this->rows($table) as $row){
			try{
				$t->subset($expected, $row);
				$t->fail($message!=='' ? $message : 'Expected fake database table not to contain row.', 'missing row', $row);
			}catch(AssertionFailed $failure){
				if($failure->getMessage()!==($message!=='' ? $message : 'Expected fake database table not to contain row.')){
					continue;
				}
				throw $failure;
			}
		}
		$t->isTrue(true, 'Fake database row was absent.');
	}

	public function assertTableCount(Context $t, string $table, int $expected, string $message=''): void {
		$t->same($expected, count($this->rows($table)), $message!=='' ? $message : 'Expected fake database table count to match.');
	}

	public function assertSchemaHasColumn(Context $t, string $table, string $column, string $message=''): void {
		$t->contains($column, array_keys($this->schema($table)), $message!=='' ? $message : 'Expected fake database schema to contain column.');
	}

	public function diffSchema(string $table, array $expected): array {
		$actual=$this->schema($table);
		return [
			'missing'=>array_values(array_diff(array_keys($expected), array_keys($actual))),
			'extra'=>array_values(array_diff(array_keys($actual), array_keys($expected))),
			'changed'=>array_values(array_filter(array_keys($expected), static fn(string $column): bool=>array_key_exists($column, $actual) && $actual[$column]!==$expected[$column])),
		];
	}

	private function rowMatches(array $row, array $expected): bool {
		foreach($expected as $key=>$value){
			if(!array_key_exists($key, $row) || $row[$key]!==$value){
				return false;
			}
		}
		return true;
	}

	private function tableName(string $table): string {
		$table=trim(str_replace('\\', '/', $table));
		if($table===''){
			throw new \InvalidArgumentException('Table name cannot be blank.');
		}
		return $table;
	}
}

final class PdoDatabaseAssertions {

	private bool $transaction_open=false;

	public function __construct(private \PDO $pdo) {}

	public function begin(): self {
		if(!$this->transaction_open){
			$this->pdo->beginTransaction();
			$this->transaction_open=true;
		}
		return $this;
	}

	public function rollback(): self {
		if($this->transaction_open){
			$this->pdo->rollBack();
			$this->transaction_open=false;
		}
		return $this;
	}

	public function commit(): self {
		if($this->transaction_open){
			$this->pdo->commit();
			$this->transaction_open=false;
		}
		return $this;
	}

	public function transaction(callable $callback): mixed {
		$this->begin();
		try{
			$result=$callback($this);
			$this->rollback();
			return $result;
		}catch(Throwable $throwable){
			$this->rollback();
			throw $throwable;
		}
	}

	public function assertTableHas(Context $t, string $table, array $expected, string $message=''): void {
		$t->isTrue($this->rowExists($table, $expected), $message!=='' ? $message : 'Expected database table to contain row.');
	}

	public function assertTableMissing(Context $t, string $table, array $expected, string $message=''): void {
		$t->isFalse($this->rowExists($table, $expected), $message!=='' ? $message : 'Expected database table not to contain row.');
	}

	public function assertTableCount(Context $t, string $table, int $expected, string $message=''): void {
		$sql='SELECT COUNT(*) AS c FROM '.$this->quoteIdentifier($table);
		$count=(int)$this->pdo->query($sql)->fetchColumn();
		$t->same($expected, $count, $message!=='' ? $message : 'Expected database table count to match.');
	}

	public function assertSchemaHasColumn(Context $t, string $table, string $column, string $message=''): void {
		$t->contains($column, $this->columns($table), $message!=='' ? $message : 'Expected database schema to contain column.');
	}

	/** @return array<int, string> */
	public function columns(string $table): array {
		$driver=(string)$this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver==='sqlite'){
			$statement=$this->pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')');
			$columns=[];
			foreach($statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [] as $row){
				$columns[]=(string)($row['name'] ?? '');
			}
			return array_values(array_filter($columns, static fn(string $value): bool=>$value!==''));
		}
		$statement=$this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_name=:table ORDER BY ordinal_position');
		$statement->execute(['table'=>$table]);
		return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []));
	}

	/** @return array<string, array<int, string>> */
	public function schemaSnapshot(array $tables): array {
		$snapshot=[];
		foreach($tables as $table){
			$snapshot[(string)$table]=$this->columns((string)$table);
		}
		return $snapshot;
	}

	/** @param array<int, string> $expected_columns @return array<string, array<int, string>> */
	public function diffSchema(string $table, array $expected_columns): array {
		$actual=$this->columns($table);
		return [
			'missing'=>array_values(array_diff($expected_columns, $actual)),
			'extra'=>array_values(array_diff($actual, $expected_columns)),
		];
	}

	private function rowExists(string $table, array $expected): bool {
		if($expected===[]){
			$sql='SELECT 1 FROM '.$this->quoteIdentifier($table).' LIMIT 1';
			return (bool)$this->pdo->query($sql)->fetchColumn();
		}
		$where=[];
		$bindings=[];
		foreach($expected as $column=>$value){
			$key=':p'.count($bindings);
			$where[]=$this->quoteIdentifier((string)$column).'='.$key;
			$bindings[$key]=$value;
		}
		$sql='SELECT 1 FROM '.$this->quoteIdentifier($table).' WHERE '.implode(' AND ', $where).' LIMIT 1';
		$statement=$this->pdo->prepare($sql);
		$statement->execute($bindings);
		return (bool)$statement->fetchColumn();
	}

	private function quoteIdentifier(string $identifier): string {
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)!==1){
			throw new \InvalidArgumentException('Unsafe SQL identifier for test assertion.');
		}
		return '"'.$identifier.'"';
	}
}

final class FakeQueue {

	/** @var array<int, array{name:string, payload:mixed, available_at:int, handler:mixed}> */
	private array $jobs=[];

	public function __construct(private FakeClock $clock) {}

	public function push(string $name, mixed $payload=null, ?callable $handler=null): void {
		$this->jobs[]=[
			'name'=>$name,
			'payload'=>$payload,
			'available_at'=>$this->clock->timestamp(),
			'handler'=>$handler,
		];
	}

	public function later(int $seconds, string $name, mixed $payload=null, ?callable $handler=null): void {
		$this->jobs[]=[
			'name'=>$name,
			'payload'=>$payload,
			'available_at'=>$this->clock->timestamp()+max(0, $seconds),
			'handler'=>$handler,
		];
	}

	/** @return array<int, array{name:string, payload:mixed, available_at:int, handler:mixed}> */
	public function jobs(): array {
		return $this->jobs;
	}

	public function runNext(): mixed {
		foreach($this->jobs as $index=>$job){
			if($job['available_at']>$this->clock->timestamp()){
				continue;
			}
			array_splice($this->jobs, $index, 1);
			return is_callable($job['handler']) ? $job['handler']($job['payload']) : $job['payload'];
		}
		return null;
	}

	public function runAll(): int {
		$count=0;
		while(true){
			$before=count($this->jobs);
			$this->runNext();
			if(count($this->jobs)===$before){
				break;
			}
			$count++;
		}
		return $count;
	}

	public function assertPushed(Context $t, string $name, mixed $payload_subset=null): void {
		foreach($this->jobs as $job){
			if($job['name']!==$name){
				continue;
			}
			if(is_array($payload_subset)){
				try{
					$t->subset($payload_subset, $job['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$t->isTrue(true, 'Queue job was pushed.');
			return;
		}
		$t->fail('Expected fake queue to contain job.', ['name'=>$name, 'payload_subset'=>$payload_subset], $this->jobs);
	}

	public function assertPushedCount(Context $t, int $expected): void {
		$t->same($expected, count($this->jobs), 'Expected fake queue job count to match.');
	}
}

final class FakeHookBus {

	/** @var array<string, array<int, Closure>> */
	private array $listeners=[];
	/** @var array<int, array{scope:string, name:string, payload:mixed, result:mixed}> */
	private array $calls=[];

	public function __construct(private string $kind='hook', private string $default_scope='app') {}

	public function on(string $name, callable $listener, string $scope=''): self {
		$key=$this->key($name, $scope);
		$this->listeners[$key][]=$listener instanceof Closure ? $listener : Closure::fromCallable($listener);
		return $this;
	}

	/** @return array<int, mixed> */
	public function call(string $name, mixed $payload=null, string $scope=''): array {
		$scope=$this->scope($scope);
		$normalized=$this->normalize($name);
		$key=$scope.':'.$normalized;
		$results=[];
		foreach($this->listeners[$key] ?? [] as $listener){
			$results[]=$listener($payload, $normalized, $scope);
		}
		$this->calls[]=[
			'scope'=>$scope,
			'name'=>$normalized,
			'payload'=>$payload,
			'result'=>$results,
		];
		return $results;
	}

	public function dispatch(string $name, mixed $payload=null, string $scope=''): array {
		return $this->call($name, $payload, $scope);
	}

	/** @return array<int, array{scope:string, name:string, payload:mixed, result:mixed}> */
	public function calls(): array {
		return $this->calls;
	}

	public function assertCalled(Context $t, string $name, string $scope='', mixed $payload_subset=null): void {
		$expected=[
			'scope'=>$this->scope($scope),
			'name'=>$this->normalize($name),
		];
		foreach($this->calls as $call){
			if($call['scope']!==$expected['scope'] || $call['name']!==$expected['name']){
				continue;
			}
			if(is_array($payload_subset)){
				try{
					$t->subset($payload_subset, $call['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$t->isTrue(true, ucfirst($this->kind).' was called.');
			return;
		}
		$t->fail('Expected '.$this->kind.' to be called.', $expected+['payload_subset'=>$payload_subset], $this->calls);
	}

	public function assertNotCalled(Context $t, string $name, string $scope=''): void {
		$expected=[
			'scope'=>$this->scope($scope),
			'name'=>$this->normalize($name),
		];
		foreach($this->calls as $call){
			if($call['scope']===$expected['scope'] && $call['name']===$expected['name']){
				$t->fail('Expected '.$this->kind.' not to be called.', 'not called', $call);
			}
		}
		$t->isTrue(true, ucfirst($this->kind).' was not called.');
	}

	public function assertCalledTimes(Context $t, string $name, int $expected, string $scope=''): void {
		$scope=$this->scope($scope);
		$name=$this->normalize($name);
		$count=0;
		foreach($this->calls as $call){
			if($call['scope']===$scope && $call['name']===$name){
				$count++;
			}
		}
		$t->same($expected, $count, 'Expected '.$this->kind.' call count to match.');
	}

	private function key(string $name, string $scope=''): string {
		return $this->scope($scope).':'.$this->normalize($name);
	}

	private function scope(string $scope): string {
		$scope=strtolower(trim($scope!=='' ? $scope : $this->default_scope));
		return $scope!=='' ? $scope : 'app';
	}

	private function normalize(string $name): string {
		$name=strtoupper(trim($name));
		$name=preg_replace('/[^A-Z0-9]+/', '_', $name) ?? $name;
		return trim($name, '_');
	}
}

final class FakeReactor {

	/** @var array<string, array<int, Closure>> */
	private array $listeners=[];
	/** @var array<int, array{name:string, payload:mixed}> */
	private array $events=[];

	public function listen(string $event, callable $listener): self {
		$this->listeners[$event][]=Closure::fromCallable($listener);
		return $this;
	}

	public function dispatch(string $event, mixed $payload=null): array {
		$this->events[]=[
			'name'=>$event,
			'payload'=>$payload,
		];
		$results=[];
		foreach($this->listeners[$event] ?? [] as $listener){
			$results[]=$listener($payload, $event);
		}
		return $results;
	}

	/** @return array<int, array{name:string, payload:mixed}> */
	public function events(): array {
		return $this->events;
	}

	public function assertDispatched(Context $t, string $event, mixed $payload_subset=null): void {
		foreach($this->events as $record){
			if($record['name']!==$event){
				continue;
			}
			if(is_array($payload_subset)){
				try{
					$t->subset($payload_subset, $record['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$t->isTrue(true, 'Reactor event was dispatched.');
			return;
		}
		$t->fail('Expected Reactor event to be dispatched.', ['name'=>$event, 'payload_subset'=>$payload_subset], $this->events);
	}

	public function assertListening(Context $t, string $event): void {
		$t->isTrue(isset($this->listeners[$event]) && $this->listeners[$event]!==[], 'Expected Reactor listener to be registered.');
	}
}

final class FakePermissions {

	/** @var array<int, array{effect:bool, actor:mixed, ability:string, resource:mixed, condition:mixed}> */
	private array $rules=[];

	public function allow(string $ability, mixed $resource='*', mixed $actor='*', ?callable $condition=null): self {
		return $this->rule(true, $ability, $resource, $actor, $condition);
	}

	public function deny(string $ability, mixed $resource='*', mixed $actor='*', ?callable $condition=null): self {
		return $this->rule(false, $ability, $resource, $actor, $condition);
	}

	public function permits(mixed $actor, string $ability, mixed $resource=null): bool {
		$allowed=false;
		foreach($this->rules as $rule){
			if(!$this->matchesRule($rule, $actor, $ability, $resource)){
				continue;
			}
			if($rule['effect']===false){
				return false;
			}
			$allowed=true;
		}
		return $allowed;
	}

	public function assertPermits(Context $t, mixed $actor, string $ability, mixed $resource=null): void {
		$t->permits($this, $actor, $ability, $resource);
	}

	public function assertDenies(Context $t, mixed $actor, string $ability, mixed $resource=null): void {
		$t->denies($this, $actor, $ability, $resource);
	}

	private function rule(bool $effect, string $ability, mixed $resource, mixed $actor, ?callable $condition): self {
		$this->rules[]=[
			'effect'=>$effect,
			'actor'=>$actor,
			'ability'=>strtolower($ability),
			'resource'=>$resource,
			'condition'=>$condition,
		];
		return $this;
	}

	private function matchesRule(array $rule, mixed $actor, string $ability, mixed $resource): bool {
		if($rule['ability']!=='*' && $rule['ability']!==strtolower($ability)){
			return false;
		}
		if($rule['actor']!=='*' && $this->identity($rule['actor'])!==$this->identity($actor)){
			return false;
		}
		if($rule['resource']!=='*' && $this->identity($rule['resource'])!==$this->identity($resource)){
			return false;
		}
		return !is_callable($rule['condition']) || (bool)$rule['condition']($actor, $resource, $ability);
	}

	private function identity(mixed $value): mixed {
		if(is_array($value)){
			return $value['id'] ?? $value['key'] ?? json_encode($value, JSON_UNESCAPED_SLASHES);
		}
		if(is_object($value)){
			return $value->id ?? $value->key ?? $value::class;
		}
		return $value;
	}
}

final class Spy {

	/** @var array<int, array<int, mixed>> */
	private array $calls=[];

	public function __construct(private mixed $passthrough=null) {}

	public function __invoke(mixed ...$arguments): mixed {
		$this->calls[]=$arguments;
		return is_callable($this->passthrough) ? ($this->passthrough)(...$arguments) : null;
	}

	/** @return array<int, array<int, mixed>> */
	public function calls(): array {
		return $this->calls;
	}

	public function count(): int {
		return count($this->calls);
	}

	public function assertCalled(Context $t): void {
		$t->greaterThan(0, $this->count(), 'Expected spy to be called.');
	}

	public function assertCalledTimes(Context $t, int $expected): void {
		$t->same($expected, $this->count(), 'Expected spy call count to match.');
	}

	public function assertCalledWith(Context $t, array $arguments): void {
		foreach($this->calls as $call){
			if($call===$arguments){
				$t->isTrue(true, 'Spy was called with expected arguments.');
				return;
			}
		}
		$t->fail('Expected spy to be called with arguments.', $arguments, $this->calls);
	}
}

final class MockObject {

	/** @var array<string, mixed> */
	private array $methods=[];
	/** @var array<string, Spy> */
	private array $spies=[];

	public function __construct(array $methods=[]) {
		foreach($methods as $name=>$handler){
			$this->method((string)$name, $handler);
		}
	}

	public function method(string $name, mixed $handler=null): self {
		$this->methods[$name]=$handler;
		$this->spies[$name]=new Spy(is_callable($handler) ? $handler : static fn()=> $handler);
		return $this;
	}

	public function __call(string $name, array $arguments): mixed {
		if(!isset($this->spies[$name])){
			$this->method($name);
		}
		return ($this->spies[$name])(...$arguments);
	}

	public function spy(string $name): Spy {
		if(!isset($this->spies[$name])){
			$this->method($name);
		}
		return $this->spies[$name];
	}
}

final class FunctionPatches {

	/** @var array<string, Spy> */
	private static array $spies=[];

	public static function define(string $qualified_function, ?callable $handler=null): Spy {
		$qualified_function=trim($qualified_function, '\\');
		if(!str_contains($qualified_function, '\\')){
			throw new \InvalidArgumentException('Function patches must target a namespaced function.');
		}
		if(function_exists('\\'.$qualified_function)){
			throw new \InvalidArgumentException('Cannot patch an already defined PHP function: '.$qualified_function);
		}
		$parts=explode('\\', $qualified_function);
		$function=array_pop($parts);
		$namespace=implode('\\', $parts);
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function)!==1){
			throw new \InvalidArgumentException('Invalid function name for patch.');
		}
		foreach($parts as $part){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)!==1){
				throw new \InvalidArgumentException('Invalid namespace for function patch.');
			}
		}
		$spy=new Spy($handler);
		self::$spies[$qualified_function]=$spy;
		$code='namespace '.$namespace.'; function '.$function.'(...$arguments): mixed { return \\Dataphyre\\Test\\FunctionPatches::call('.var_export($qualified_function, true).', $arguments); }';
		eval($code);
		return $spy;
	}

	/** @param array<int, mixed> $arguments */
	public static function call(string $qualified_function, array $arguments): mixed {
		if(!isset(self::$spies[$qualified_function])){
			throw new \BadFunctionCallException('Function patch is not registered: '.$qualified_function);
		}
		return (self::$spies[$qualified_function])(...$arguments);
	}
}

final class StaticProxy {

	/** @var array<string, Spy> */
	private array $spies=[];

	public function __construct(private string $class) {}

	public function call(string $method, mixed ...$arguments): mixed {
		$this->spies[$method] ??= new Spy(fn(...$args)=>$this->class::$method(...$args));
		return ($this->spies[$method])(...$arguments);
	}

	public function spy(string $method): Spy {
		$this->spies[$method] ??= new Spy(fn(...$args)=>$this->class::$method(...$args));
		return $this->spies[$method];
	}
}

final class BenchmarkResult {

	/** @param array<int, float> $durations */
	public function __construct(private array $durations, private int $memory_delta, private int $peak_delta) {}

	public function iterations(): int {
		return count($this->durations);
	}

	public function totalMillis(): float {
		return array_sum($this->durations);
	}

	public function meanMillis(): float {
		return $this->durations===[] ? 0.0 : $this->totalMillis()/count($this->durations);
	}

	public function maxMillis(): float {
		return $this->durations===[] ? 0.0 : max($this->durations);
	}

	public function percentileMillis(float $percentile): float {
		if($this->durations===[]){
			return 0.0;
		}
		$values=$this->durations;
		sort($values);
		$index=(int)ceil((max(0, min(100, $percentile))/100)*count($values))-1;
		return $values[max(0, min(count($values)-1, $index))];
	}

	public function memoryDeltaBytes(): int {
		return $this->memory_delta;
	}

	public function peakDeltaBytes(): int {
		return $this->peak_delta;
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'iterations'=>$this->iterations(),
			'total_millis'=>$this->totalMillis(),
			'mean_millis'=>$this->meanMillis(),
			'max_millis'=>$this->maxMillis(),
			'p95_millis'=>$this->percentileMillis(95),
			'memory_delta_bytes'=>$this->memory_delta,
			'peak_delta_bytes'=>$this->peak_delta,
		];
	}
}

final class BrowserProbe {

	private string $root;
	private string $worker;
	private string $node;

	public function __construct(string $root='', private array $options=[]) {
		$this->root=rtrim(str_replace('\\', '/', $root!=='' ? $root : getcwd()), '/');
		$this->worker=$this->root.'/tools/browser_test_worker.js';
		$this->node=(string)($options['node'] ?? 'node');
	}

	public function assertHtml(Context $t, string $html, array $options=[]): array {
		return $this->assert($t, ['html'=>$html]+$options);
	}

	public function assertUrl(Context $t, string $url, array $options=[]): array {
		return $this->assert($t, ['url'=>$url]+$options);
	}

	public function screenshot(Context $t, string $html, string $name, array $options=[]): array {
		$path=$this->snapshotPath($t, $name, 'png');
		return $this->assertHtml($t, $html, $options+['screenshot_path'=>$path]);
	}

	public function visualSnapshot(Context $t, string $html, string $name, bool $update=false, array $options=[]): array {
		$path=$this->snapshotPath($t, $name, 'png');
		return $this->assertHtml($t, $html, $options+[
			'screenshot_path'=>$this->snapshotPath($t, $name.'.actual', 'png'),
			'visual_baseline_path'=>$path,
			'update_visual_baseline'=>$update || in_array(strtolower((string)(getenv('DATAPHYRE_UPDATE_VISUAL_SNAPSHOTS') ?: '')), ['1', 'true', 'yes', 'on'], true),
		]);
	}

	public function assert(Context $t, array $payload): array {
		if(!is_file($this->worker)){
			$t->skip('Browser worker is unavailable at '.$this->worker.'.');
		}
		$tmp=$this->tempDir();
		$payload_path=$tmp.'/browser-payload-'.bin2hex(random_bytes(6)).'.json';
		$output_path=$tmp.'/browser-result-'.bin2hex(random_bytes(6)).'.json';
		$payload+=['output_path'=>$output_path];
		file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		$process=$this->run([$this->node, $this->worker, $payload_path], (int)($payload['timeout_seconds'] ?? 20));
		$result=is_file($output_path) ? json_decode((string)file_get_contents($output_path), true) : null;
		@unlink($payload_path);
		@unlink($output_path);
		if(!is_array($result)){
			$t->fail('Browser worker did not return a valid result.', 'browser result JSON', $process);
		}
		if(($result['skipped'] ?? false)===true){
			$t->skip((string)($result['reason'] ?? 'Browser worker skipped.'));
		}
		if(($result['passed'] ?? false)!==true || $process['exit_code']!==0){
			$t->fail('Browser assertion failed.', [], $result+['process'=>$process]);
		}
		$t->isTrue(true, 'Browser assertion passed.');
		return $result;
	}

	private function snapshotPath(Context $t, string $name, string $extension): string {
		$base=$this->root.'/cache/unit-test-browser';
		$id=preg_replace('/[^A-Za-z0-9_.-]+/', '_', $t->name().($t->dataset()!=='' ? '.'.$t->dataset() : '').'.'.$name) ?: 'browser';
		return $base.'/'.trim(strtolower($id), '._-').'.'.$extension;
	}

	private function tempDir(): string {
		$dir=$this->root.'/cache/unit-test-browser';
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new \RuntimeException('Unable to create browser test cache directory.');
		}
		return $dir;
	}

	private function run(array $command, int $timeout_seconds): array {
		$descriptor=[0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']];
		$process=proc_open($command, $descriptor, $pipes, $this->root ?: null);
		if(!is_resource($process)){
			return ['exit_code'=>127, 'stdout'=>'', 'stderr'=>'Unable to start browser worker.', 'timed_out'=>false];
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout='';
		$stderr='';
		$started=time();
		$timed_out=false;
		while(true){
			$stdout.=(string)stream_get_contents($pipes[1]);
			$stderr.=(string)stream_get_contents($pipes[2]);
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){
				break;
			}
			if(time()-$started>$timeout_seconds){
				$timed_out=true;
				proc_terminate($process);
				break;
			}
			usleep(50000);
		}
		$stdout.=(string)stream_get_contents($pipes[1]);
		$stderr.=(string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit=proc_close($process);
		return [
			'exit_code'=>$timed_out ? 124 : $exit,
			'stdout'=>$stdout,
			'stderr'=>$stderr,
			'timed_out'=>$timed_out,
		];
	}
}

final class DataphyreModuleBridge {

	private string $runtime;

	public function __construct(string $runtime_root='') {
		$this->runtime=rtrim(str_replace('\\', '/', $runtime_root), '/');
	}

	public function storage(array $config=[]): object {
		$this->loadStorage();
		if(!class_exists('\Dataphyre\Storage\StorageManager')){
			throw new \RuntimeException('Dataphyre Storage framework classes could not be loaded.');
		}
		$config=array_replace_recursive([
			'default_disk'=>'memory',
			'disks'=>[
				'memory'=>[
					'driver'=>'memory',
				],
			],
		], $config);
		if(!defined('DP_STORAGE_CFG')){
			define('DP_STORAGE_CFG', $config);
		}
		\Dataphyre\Storage\StorageManager::flushInstance();
		$manager=\Dataphyre\Storage\StorageManager::instance();
		$manager->fakeFlush();
		return $manager;
	}

	public function storageEvents(object $manager): StorageEventRecorder {
		$recorder=new StorageEventRecorder();
		if(method_exists($manager, 'listen')){
			$manager->listen('*', [$recorder, 'record']);
		}
		return $recorder;
	}

	public function permission(array $config=[]): string {
		$this->loadPermission();
		if(!class_exists('\Dataphyre\Permission\Permission')){
			throw new \RuntimeException('Dataphyre Permission framework classes could not be loaded.');
		}
		if(!defined('DP_PERMISSION_CFG')){
			define('DP_PERMISSION_CFG', array_replace_recursive([
				'default_roles'=>[],
				'roles'=>[],
				'aliases'=>[],
				'super_permissions'=>['*'],
				'storage'=>[
					'auto_hydrate'=>false,
				],
				'cache'=>[
					'enabled'=>false,
				],
				'trace'=>[
					'enabled'=>true,
					'max_entries'=>256,
					'include_context'=>true,
				],
			], $config));
		}
		\Dataphyre\Permission\Permission::flush();
		\Dataphyre\Permission\Permission::trace(true);
		return \Dataphyre\Permission\Permission::class;
	}

	public function sqlFramework(): DataphyreSqlFrameworkBridge {
		$this->loadSqlFramework();
		if(!class_exists('\Dataphyre\Database\QuerySpec') || !class_exists('\Dataphyre\Database\TableSchema') || !class_exists('\Dataphyre\Database\TableDefinition')){
			throw new \RuntimeException('Dataphyre SQL framework classes could not be loaded.');
		}
		return new DataphyreSqlFrameworkBridge();
	}

	public function sqlKernel(?string $database_path=null): DataphyreSqlKernelHarness {
		if(!extension_loaded('sqlite3') || !class_exists('\SQLite3')){
			throw new \RuntimeException('The SQLite3 extension is required for the Dataphyre SQL kernel test harness.');
		}
		$database_path=$database_path!==null && trim($database_path)!=='' ? $database_path : $this->projectRoot().'/cache/unit-test-sql/sql-kernel-'.bin2hex(random_bytes(6)).'.sqlite';
		$database_path=str_replace('\\', '/', $database_path);
		$dir=dirname($database_path);
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new \RuntimeException('Unable to create SQL kernel test database directory: '.$dir);
		}
		$this->loadSqlKernel($database_path);
		return new DataphyreSqlKernelHarness($database_path, 'sql');
	}

	public function mvc(): DataphyreMvcTestHarness {
		$this->loadMvcFramework();
		if(!class_exists('\Dataphyre\Mvc\Mvc') || !class_exists('\Dataphyre\Http\Request') || !class_exists('\Dataphyre\Http\Response')){
			throw new \RuntimeException('Dataphyre MVC framework classes could not be loaded.');
		}
		\Dataphyre\Mvc\Mvc::flush();
		return new DataphyreMvcTestHarness();
	}

	public function reactor(array $config=[]): object {
		$this->loadReactor();
		if(!class_exists('\Dataphyre\Reactor\Reactor')){
			throw new \RuntimeException('Dataphyre Reactor framework classes could not be loaded.');
		}
		if(!defined('DP_REACTOR_CFG')){
			define('DP_REACTOR_CFG', array_replace_recursive([
				'secret'=>'dataphyre-testing-secret',
				'allow_unsigned_in_debug'=>false,
				'components'=>[],
			], $config));
		}
		\Dataphyre\Reactor\Reactor::reset();
		return \Dataphyre\Reactor\Reactor::test();
	}

	private function loadStorage(): void {
		if(class_exists('\Dataphyre\Storage\StorageManager', false)){
			return;
		}
		$base=$this->runtime.'/modules/storage/Framework';
		foreach([
			'Contracts/StorageDriver.php',
			'FileMetadata.php',
			'StorageResult.php',
			'Support/Path.php',
			'Support/Stream.php',
			'Support/Encryption.php',
			'Support/AwsSignatureV4.php',
			'Drivers/LocalDriver.php',
			'Drivers/MemoryDriver.php',
			'Drivers/VestraDriver.php',
			'Drivers/S3CompatibleDriver.php',
			'Drivers/MirrorDriver.php',
			'Drivers/ScopedDriver.php',
			'Drivers/ReadOnlyDriver.php',
			'Drivers/QuotaDriver.php',
			'Drivers/FailoverDriver.php',
			'Drivers/CachedDriver.php',
			'Drivers/CompressedDriver.php',
			'Drivers/RetentionDriver.php',
			'Drivers/LifecycleDriver.php',
			'Drivers/ScannedDriver.php',
			'Drivers/TaggedDriver.php',
			'Drivers/EventedDriver.php',
			'Drivers/PolicyDriver.php',
			'Drivers/RateLimitedDriver.php',
			'Drivers/VersionedDriver.php',
			'Drivers/DeduplicatedDriver.php',
			'Drivers/IntegrityDriver.php',
			'Drivers/AuditDriver.php',
			'StorageManager.php',
			'Storage.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadPermission(): void {
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return;
		}
		$base=$this->runtime.'/modules/permission/Framework';
		foreach([
			'PermissionRule.php',
			'PermissionRepository.php',
			'PermissionCatalog.php',
			'PermissionAudit.php',
			'PermissionManifest.php',
			'PermissionNamer.php',
			'PermissionCondition.php',
			'PermissionTrace.php',
			'PermissionTest.php',
			'PermissionSimulator.php',
			'PermissionSnapshot.php',
			'PermissionOptimizer.php',
			'Exceptions/AuthorizationException.php',
			'Middleware/AuthorizeWhen.php',
			'Middleware/AuthorizeAnyWhen.php',
			'PermissionSet.php',
			'SubjectResolver.php',
			'PermissionEngine.php',
			'PermissionSubject.php',
			'Permission.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadSqlFramework(): void {
		if(class_exists('\Dataphyre\Database\QuerySpec', false) && class_exists('\Dataphyre\Database\TableSchema', false) && class_exists('\Dataphyre\Database\TableDefinition', false)){
			return;
		}
		$base=$this->runtime.'/modules/sql/Framework';
		foreach([
			'SqlError.php',
			'QuerySpec.php',
			'TableSchema.php',
			'TableDefinition.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadMvcFramework(): void {
		if(class_exists('\Dataphyre\Mvc\Mvc', false) && class_exists('\Dataphyre\Http\Request', false) && class_exists('\dataphyre\routing\compiled_route_dispatcher', false)){
			return;
		}
		$this->loadHttpFramework();
		$this->loadRoutingFramework();
		$this->loadTemplatingResponseTypes();
		$base=$this->runtime.'/modules/mvc/Framework';
		foreach([
			'ContainerException.php',
			'HttpException.php',
			'ValidationException.php',
			'RouteModelNotFoundException.php',
			'Container.php',
			'Controller.php',
			'ServiceProviderContract.php',
			'ServiceProvider.php',
			'ProviderRegistry.php',
			'Session.php',
			'Model.php',
			'RouteModelBinder.php',
			'ResponseResult.php',
			'RedirectResult.php',
			'ViewResult.php',
			'MvcRouteContext.php',
			'RouteDefinition.php',
			'RouteCollection.php',
			'RouteList.php',
			'AccessMiddleware.php',
			'CacheMiddleware.php',
			'CallbackServiceProvider.php',
			'CsrfMiddleware.php',
			'FormRequest.php',
			'GuestMiddleware.php',
			'PermissionAnyMiddleware.php',
			'PermissionMiddleware.php',
			'SessionMiddleware.php',
			'SignedUrl.php',
			'SignedUrlMiddleware.php',
			'ThrottleMiddleware.php',
			'Validator.php',
			'MvcApplication.php',
			'MvcDispatcher.php',
			'MvcManager.php',
			'MvcHost.php',
			'Mvc.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadHttpFramework(): void {
		if(class_exists('\Dataphyre\Http\Request', false) && class_exists('\Dataphyre\Http\Response', false)){
			return;
		}
		$base=$this->runtime.'/modules/http/Framework';
		foreach([
			'UploadedFile.php',
			'Request.php',
			'Response.php',
			'ResponseEmitter.php',
			'ActionArguments.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadRoutingFramework(): void {
		if(class_exists('\Dataphyre\Routing\RouteCompiler', false) && class_exists('\dataphyre\routing\compiled_route_dispatcher', false)){
			return;
		}
		$framework=$this->runtime.'/modules/routing/Framework';
		foreach([
			'CompilableRoute.php',
			'Route.php',
			'ControllerAction.php',
			'RouteManifest.php',
			'RouteCompiler.php',
		] as $file){
			$path=$framework.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
		$dispatcher=$this->runtime.'/modules/routing/kernel/compiled_route_dispatcher.php';
		if(is_file($dispatcher)){
			require_once $dispatcher;
		}
	}

	private function loadTemplatingResponseTypes(): void {
		if(class_exists('\Dataphyre\Templating\RenderedTemplate', false) && class_exists('\Dataphyre\Templating\TemplateView', false)){
			return;
		}
		$base=$this->runtime.'/modules/templating/Framework';
		foreach([
			'RenderedTemplate.php',
			'TemplateView.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadSqlKernel(string $database_path): void {
		if(class_exists('\dataphyre\sql', false)){
			$config=defined('DP_SQL_CFG') ? \constant('DP_SQL_CFG') : [];
			$datacenter=defined('DP_CORE_CFG') ? (string)((\constant('DP_CORE_CFG')['datacenter'] ?? 'test') ?: 'test') : 'test';
			$active=(string)($config['datacenters'][$datacenter]['dbms_clusters']['sql']['database_name'] ?? '');
			if($active!=='' && str_replace('\\', '/', $active)!==$database_path){
				throw new \RuntimeException('Dataphyre SQL kernel is already loaded for another database in this worker.');
			}
			return;
		}
		$this->defineSqlKernelTestStubs($database_path);
		$entry=$this->runtime.'/modules/sql/kernel/sql.main.php';
		if(!is_file($entry)){
			throw new \RuntimeException('Dataphyre SQL kernel entrypoint is missing.');
		}
		require_once $entry;
	}

	private function defineSqlKernelTestStubs(string $database_path): void {
		if(!isset($_SESSION) || !is_array($_SESSION)){
			$_SESSION=[];
		}
		$root=$this->projectRoot();
		if(!defined('DP_CORE_CFG')){
			define('DP_CORE_CFG', ['datacenter'=>'test']);
		}
		$datacenter=(string)((\constant('DP_CORE_CFG')['datacenter'] ?? 'test') ?: 'test');
		if(!defined('DP_SQL_CFG')){
			define('DP_SQL_CFG', [
				'default_cluster'=>'sql',
				'default_database_location'=>'',
				'safe_delete'=>true,
				'caching'=>[
					'rolling_db_cache_size'=>256,
					'default_policy'=>[
						'type'=>'session',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5',
					],
				],
				'datacenters'=>[
					$datacenter=>[
						'dbms_clusters'=>[
							'sql'=>[
								'dbms'=>'sqlite',
								'database_name'=>$database_path,
								'endpoints'=>[$database_path],
							],
						],
					],
				],
				'tables'=>[
					'raw'=>[
						'cluster'=>'sql',
						'caching'=>false,
					],
				],
			]);
		}
		if(!defined('ROOTPATH')){
			define('ROOTPATH', [
				'root'=>$root,
				'common_root'=>$root,
				'common_dataphyre'=>$root.'/common/dataphyre',
				'common_dataphyre_runtime'=>$this->runtime,
				'applications'=>$root.'/applications',
			]);
		}
		if(!defined('RUN_MODE')){
			define('RUN_MODE', 'unit_test');
		}
		if(!function_exists('\dataphyre\tracelog')){
			eval('namespace dataphyre { function tracelog(...$args): void {} }');
		}
		if(!function_exists('\dataphyre\log_error')){
			eval('namespace dataphyre { function log_error(...$args): void {} }');
		}
		if(!function_exists('\dataphyre\dp_module_present')){
			eval('namespace dataphyre { function dp_module_present(...$args): bool { return false; } }');
		}
		if(!function_exists('\dataphyre\dp_define_module_config')){
			eval('namespace dataphyre { function dp_define_module_config(string $module, string $constant, array $defaults=[]): void { if(!defined($constant)){ define($constant, $defaults); } } }');
		}
		if(!function_exists('dataphyre_shutdown_log')){
			eval('namespace { function dataphyre_shutdown_log(...$args): void {} }');
		}
		if(!function_exists('log_error')){
			eval('namespace { function log_error(...$args): void {} }');
		}
		if(!class_exists('\dataphyre\core', false)){
			eval('namespace dataphyre { class core { public static function dialback(...$args): mixed { return null; } public static function unavailable(...$args): never { throw new \RuntimeException((string)($args[4] ?? "Dataphyre unavailable.")); } public static function load_framework_module(...$args): bool { return false; } public static function file_put_contents_forced(string $file, string $data): int|false { $dir=dirname($file); if(!is_dir($dir)){ mkdir($dir, 0775, true); } return file_put_contents($file, $data); } public static function force_rmdir(string $path): void { if(!is_dir($path)){ return; } $items=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST); foreach($items as $item){ $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($path); } public static function get_password(...$args): null { return null; } public static function log(...$args): void {} } }');
		}
	}

	private function loadReactor(): void {
		if(class_exists('\Dataphyre\Reactor\ReactorTestHarness', false)){
			return;
		}
		$base=$this->runtime.'/modules/reactor/Framework';
		foreach([
			'Support/ReactorName.php',
			'Support/ReactorTrace.php',
			'Security/ReactorSigner.php',
			'State/ReactorSnapshot.php',
			'Effects/ReactorEffects.php',
			'Http/ReactorResponse.php',
			'Http/ReactorRequest.php',
			'Validation/ReactorValidator.php',
			'Components/ReactorComponent.php',
			'Introspection/ReactorManifest.php',
			'Client/ReactorClientAssets.php',
			'View/ReactorView.php',
			'Core/ReactorManager.php',
			'Core/Reactor.php',
			'Http/ReactorEndpoint.php',
			'Testing/ReactorTestHarness.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function projectRoot(): string {
		return rtrim(str_replace('\\', '/', dirname($this->runtime, 3)), '/');
	}
}

final class DataphyreMvcTestHarness {

	/** @var array<int, callable> */
	private array $autoloaders=[];

	public function __destruct() {
		foreach($this->autoloaders as $loader){
			spl_autoload_unregister($loader);
		}
	}

	public function autoload(string $namespace, string $root): self {
		$namespace=trim($namespace, '\\').'\\';
		$root=rtrim(str_replace('\\', '/', $root), '/').'/';
		$loader=static function(string $class) use($namespace, $root): void {
			if(!str_starts_with($class, $namespace)){
				return;
			}
			$relative=substr($class, strlen($namespace));
			$path=$root.str_replace('\\', '/', $relative).'.php';
			if(is_file($path)){
				require_once $path;
			}
		};
		spl_autoload_register($loader);
		$this->autoloaders[]=$loader;
		return $this;
	}

	public function register(string $name, array $config): self {
		$config['manifest_cache']=$config['manifest_cache'] ?? false;
		\Dataphyre\Mvc\Mvc::register($name, $config);
		return $this;
	}

	public function registerFromConfig(string $name, string $config_path, array $overrides=[]): self {
		$config_path=str_replace('\\', '/', $config_path);
		if(!is_file($config_path)){
			throw new \RuntimeException('MVC test config file is missing: '.$config_path);
		}
		$config=require $config_path;
		if(!is_array($config)){
			throw new \RuntimeException('MVC test config file must return an array: '.$config_path);
		}
		$config=array_replace_recursive($config, $overrides);
		if(!array_key_exists('manifest_cache', $overrides)){
			$config['manifest_cache']=false;
		}
		return $this->register($name, $config);
	}

	public function app(string $name): object {
		return \Dataphyre\Mvc\Mvc::app($name);
	}

	public function dispatch(string $app, string $method, string $path, array $options=[]): object {
		$request=\Dataphyre\Http\Request::create(
			$method,
			$path,
			(array)($options['query'] ?? []),
			(array)($options['body'] ?? []),
			(array)($options['cookies'] ?? []),
			(array)($options['server'] ?? []),
			(array)($options['headers'] ?? []),
			(array)($options['route_parameters'] ?? []),
			(array)($options['attributes'] ?? []),
			(array)($options['files'] ?? [])
		);
		return \Dataphyre\Mvc\Mvc::host($app)->dispatch($request);
	}

	public function json(object $response): array {
		if(!$response instanceof \Dataphyre\Http\Response){
			throw new \InvalidArgumentException('Expected a Dataphyre HTTP response.');
		}
		$decoded=json_decode($response->body, true);
		if(json_last_error()!==JSON_ERROR_NONE || !is_array($decoded)){
			throw new \RuntimeException('Response body is not valid JSON: '.json_last_error_msg());
		}
		return $decoded;
	}
}

final class DataphyreSqlFrameworkBridge {

	public function querySpec(): object {
		return new \Dataphyre\Database\QuerySpec();
	}

	public function schema(string $table, array $columns, array $projections=[], ?string $primary_key=null, array $casts=[]): object {
		return new \Dataphyre\Database\TableSchema($table, $columns, $projections, $primary_key, $casts);
	}

	public function definition(string $table): object {
		return \Dataphyre\Database\TableDefinition::for($table);
	}
}

final class DataphyreSqlKernelHarness {

	public function __construct(private string $database_path, private string $cluster='sql') {}

	public function databasePath(): string {
		return $this->database_path;
	}

	public function query(string $sql, ?array $vars=null, bool $associative=true): mixed {
		return \dataphyre\sql_query($sql, $vars, $associative, false, false, false, null);
	}

	public function createTable(string $sql): bool {
		return $this->query($sql, null, true)!==false;
	}

	public function insert(string $table, array $fields): mixed {
		return \dataphyre\sql_insert($table, $fields, null, false, null);
	}

	public function select(array|string $columns, string $table, ?string $where=null, ?array $vars=null): mixed {
		return \dataphyre\sql_select($columns, $table, $where, $vars, true, false, null);
	}

	public function count(string $table, ?string $where=null, ?array $vars=null): int|bool {
		return \dataphyre\sql_count($table, $where, $vars, false, null);
	}

	public function update(string $table, string|array $fields, ?string $where=null, ?array $vars=null): int|bool|null {
		return \dataphyre\sql_update($table, $fields, $where, $vars, false, null);
	}

	public function delete(string $table, ?string $where=null, ?array $vars=null): int|bool|null {
		return \dataphyre\sql_delete($table, $where, $vars, false, null);
	}

	public function lastError(): ?array {
		return \dataphyre\sql::last_query_error();
	}
}

final class StorageEventRecorder {

	/** @var array<int, array<string, mixed>> */
	private array $events=[];

	public function record(array $event): void {
		if(isset($event['event']) && !isset($event['name'])){
			$event['name']=$event['event'];
		}
		$this->events[]=$event;
	}

	/** @return array<int, array<string, mixed>> */
	public function events(): array {
		return $this->events;
	}

	public function assertRecorded(Context $t, string $event, array $subset=[]): void {
		$t->eventContains($this->events, $event, $subset, 'Expected Dataphyre Storage event to be recorded.');
	}
}

final class HtmlProbe {

	/** @return array<int, array{tag:string, attributes:array<string,string>, html:string}> */
	public static function matches(string $html, string $selector): array {
		$criteria=self::selector($selector);
		$matches=[];
		if(preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/m', $html, $found, PREG_SET_ORDER)!==false){
			foreach($found as $tag){
				$name=strtolower($tag[1]);
				$attributes=self::attributes((string)$tag[2]);
				if($criteria['tag']!=='' && $criteria['tag']!==$name){
					continue;
				}
				if($criteria['id']!=='' && ($attributes['id'] ?? '')!==$criteria['id']){
					continue;
				}
				if($criteria['class']!=='' && !in_array($criteria['class'], preg_split('/\s+/', (string)($attributes['class'] ?? '')) ?: [], true)){
					continue;
				}
				if($criteria['attribute']!=='' && !array_key_exists($criteria['attribute'], $attributes)){
					continue;
				}
				if($criteria['attribute']!=='' && $criteria['attribute_value']!==null && $attributes[$criteria['attribute']]!==$criteria['attribute_value']){
					continue;
				}
				$matches[]=[
					'tag'=>$name,
					'attributes'=>$attributes,
					'html'=>$tag[0],
				];
			}
		}
		return $matches;
	}

	public static function shape(string $html): array {
		$tags=[];
		if(preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/m', $html, $found, PREG_SET_ORDER)!==false){
			foreach(array_slice($found, 0, 30) as $tag){
				$attrs=self::attributes((string)$tag[2]);
				$tags[]=strtolower($tag[1]).(isset($attrs['id']) ? '#'.$attrs['id'] : '').(isset($attrs['class']) ? '.'.str_replace(' ', '.', $attrs['class']) : '');
			}
		}
		return $tags;
	}

	private static function selector(string $selector): array {
		$selector=trim($selector);
		$criteria=[
			'tag'=>'',
			'id'=>'',
			'class'=>'',
			'attribute'=>'',
			'attribute_value'=>null,
		];
		if(preg_match('/\[([A-Za-z0-9_:-]+)(?:=([^\]]+))?\]/', $selector, $match)===1){
			$criteria['attribute']=strtolower($match[1]);
			$criteria['attribute_value']=isset($match[2]) ? trim($match[2], "\"' ") : null;
			$selector=str_replace($match[0], '', $selector);
		}
		if(preg_match('/#([A-Za-z0-9_-]+)/', $selector, $match)===1){
			$criteria['id']=$match[1];
			$selector=str_replace($match[0], '', $selector);
		}
		if(preg_match('/\.([A-Za-z0-9_-]+)/', $selector, $match)===1){
			$criteria['class']=$match[1];
			$selector=str_replace($match[0], '', $selector);
		}
		$selector=trim($selector);
		if($selector!=='' && $selector!=='*'){
			$criteria['tag']=strtolower($selector);
		}
		return $criteria;
	}

	private static function attributes(string $text): array {
		$attributes=[];
		if(preg_match_all('/([A-Za-z0-9_:-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $text, $found, PREG_SET_ORDER)!==false){
			foreach($found as $match){
				$name=strtolower($match[1]);
				$attributes[$name]=html_entity_decode((string)($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES|ENT_HTML5, 'UTF-8');
			}
		}
		return $attributes;
	}
}

final class Dataset {

	public static function cases(iterable $rows): iterable {
		yield from $rows;
	}

	public static function range(int $start, int $end, int $step=1): iterable {
		$step=$step===0 ? 1 : abs($step);
		if($start<=$end){
			for($i=$start; $i<=$end; $i+=$step){
				yield (string)$i=>[$i];
			}
			return;
		}
		for($i=$start; $i>=$end; $i-=$step){
			yield (string)$i=>[$i];
		}
	}

	public static function matrix(array $dimensions): iterable {
		$rows=[['label'=>'', 'values'=>[]]];
		foreach($dimensions as $name=>$values){
			$next=[];
			foreach($rows as $row){
				foreach($values as $label=>$value){
					$next[]=[
						'label'=>trim($row['label'].' '.(is_string($label) ? $name.'='.$label : $name.'='.$value)),
						'values'=>array_merge($row['values'], [$value]),
					];
				}
			}
			$rows=$next;
		}
		foreach($rows as $row){
			yield trim($row['label'])=>$row['values'];
		}
	}

	public static function map(iterable $rows, callable $mapper): iterable {
		foreach($rows as $label=>$row){
			yield $label=>$mapper($row, $label);
		}
	}

	public static function take(iterable $rows, int $limit): iterable {
		$count=0;
		foreach($rows as $label=>$row){
			if($count++>=$limit){
				break;
			}
			yield $label=>$row;
		}
	}
}

final class GeneratedCases implements \IteratorAggregate {

	/** @var Closure(): iterable<string, array<int, mixed>> */
	private Closure $factory;
	/** @var Closure(mixed): iterable<mixed>|null */
	private ?Closure $shrinker;

	public function __construct(private string $kind, private int $seed, private int $count, callable $factory, ?callable $shrinker=null) {
		$this->factory=Closure::fromCallable($factory);
		$this->shrinker=$shrinker===null ? null : Closure::fromCallable($shrinker);
	}

	public function getIterator(): Traversable {
		yield from ($this->factory)();
	}

	public function seed(): int {
		return $this->seed;
	}

	public function replayToken(string|int $label, mixed $case): string {
		return base64_encode(json_encode([
			'kind'=>$this->kind,
			'seed'=>$this->seed,
			'label'=>(string)$label,
			'case'=>$case,
		], JSON_UNESCAPED_SLASHES));
	}

	public function replay(string $token): iterable {
		$decoded=json_decode((string)base64_decode($token, true), true);
		if(is_array($decoded) && array_key_exists('case', $decoded)){
			yield (string)($decoded['label'] ?? 'replay')=>$decoded['case'];
			return;
		}
		yield from $this;
	}

	public function shrink(mixed $case, callable $assertion, Context $context): mixed {
		if($this->shrinker===null){
			return $case;
		}
		$best=$case;
		foreach(($this->shrinker)($case) as $candidate){
			try{
				$args=is_array($candidate) && self::isListValue($candidate) ? $candidate : [$candidate];
				$assertion($context, ...$args);
			}catch(Throwable){
				$best=$candidate;
			}
		}
		return $best;
	}

	private static function isListValue(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}
}

final class Generators {

	public static function integers(int $min, int $max, int $count, ?int $seed=null): iterable {
		if($seed!==null){
			mt_srand($seed);
		}
		for($i=0; $i<$count; $i++){
			yield 'int_'.$i=>[mt_rand($min, $max)];
		}
	}

	public static function strings(int $count, int $min_length=0, int $max_length=16, ?int $seed=null): iterable {
		if($seed!==null){
			mt_srand($seed);
		}
		$alphabet='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		for($i=0; $i<$count; $i++){
			$length=mt_rand($min_length, max($min_length, $max_length));
			$value='';
			for($j=0; $j<$length; $j++){
				$value.=$alphabet[mt_rand(0, strlen($alphabet)-1)];
			}
			yield 'str_'.$i=>[$value];
		}
	}

	public static function oneOf(array $values, int $count, ?int $seed=null): iterable {
		if($seed!==null){
			mt_srand($seed);
		}
		$values=array_values($values);
		for($i=0; $i<$count; $i++){
			yield 'one_'.$i=>[$values[mt_rand(0, count($values)-1)] ?? null];
		}
	}

	public static function fuzzIntegers(int $min, int $max, int $count, ?int $seed=null): GeneratedCases {
		$seed ??= random_int(1, PHP_INT_MAX);
		return new GeneratedCases('integers', $seed, $count, static function()use($min, $max, $count, $seed): iterable {
			mt_srand($seed);
			for($i=0; $i<$count; $i++){
				yield 'int_'.$i=>[mt_rand($min, $max)];
			}
		}, static function(array $case)use($min): iterable {
			$value=(int)($case[0] ?? 0);
			$candidates=[];
			$current=$value;
			while(abs($current-$min)>1){
				$current=(int)floor(($current+$min)/2);
				$candidates[]=[$current];
			}
			$candidates[]=[$min];
			yield from $candidates;
		});
	}

	public static function fuzzStrings(int $count, int $min_length=0, int $max_length=16, ?int $seed=null): GeneratedCases {
		$seed ??= random_int(1, PHP_INT_MAX);
		return new GeneratedCases('strings', $seed, $count, static function()use($count, $min_length, $max_length, $seed): iterable {
			yield from self::strings($count, $min_length, $max_length, $seed);
		}, static function(array $case): iterable {
			$value=(string)($case[0] ?? '');
			while(strlen($value)>0){
				$value=substr($value, 0, intdiv(strlen($value), 2));
				yield [$value];
			}
		});
	}

	public static function tuples(iterable ...$generators): iterable {
		$rows=[];
		foreach($generators as $generator){
			$rows[]=array_values(iterator_to_array($generator, false));
		}
		$count=min(array_map('count', $rows) ?: [0]);
		for($i=0; $i<$count; $i++){
			$tuple=[];
			foreach($rows as $row){
				$value=$row[$i];
				$tuple[]=is_array($value) && count($value)===1 ? $value[0] : $value;
			}
			yield 'tuple_'.$i=>$tuple;
		}
	}
}

final class FixtureDefinition {

	public function __construct(public string $name, public Closure $setup, public ?Closure $teardown=null) {}
}

final class CaseDefinition {

	/** @var array<int, string|iterable<mixed>|Closure> */
	private array $datasets=[];
	/** @var array<int, string> */
	private array $fixture_names=[];
	/** @var array<int, string> */
	private array $tags=[];
	/** @var array<int, string> */
	private array $groups=[];
	/** @var array<int, string> */
	private array $dependencies=[];
	private ?int $max_millis=null;
	private int $order=0;
	private ?string $skip_reason=null;
	private ?string $todo_reason=null;
	private bool $only=false;

	public function __construct(public string $name, public Closure $body, public string $file='', public int $line=0) {}

	public function with(string|iterable|Closure $dataset): self {
		$this->datasets[]=$dataset;
		return $this;
	}

	public function uses(string ...$fixtures): self {
		foreach($fixtures as $fixture){
			$fixture=trim($fixture);
			if($fixture!=='' && !in_array($fixture, $this->fixture_names, true)){
				$this->fixture_names[]=$fixture;
			}
		}
		return $this;
	}

	public function tag(string ...$tags): self {
		foreach($tags as $tag){
			$tag=trim($tag);
			if($tag!=='' && !in_array($tag, $this->tags, true)){
				$this->tags[]=$tag;
			}
		}
		return $this;
	}

	public function group(string ...$groups): self {
		foreach($groups as $group){
			$group=trim($group);
			if($group!=='' && !in_array($group, $this->groups, true)){
				$this->groups[]=$group;
			}
		}
		return $this;
	}

	public function dependsOn(string ...$tests): self {
		foreach($tests as $test){
			$test=trim($test);
			if($test!=='' && !in_array($test, $this->dependencies, true)){
				$this->dependencies[]=$test;
			}
		}
		return $this;
	}

	public function order(int $order): self {
		$this->order=$order;
		return $this;
	}

	public function skip(string $reason=''): self {
		$this->skip_reason=$reason!=='' ? $reason : 'Test skipped.';
		return $this;
	}

	public function todo(string $reason=''): self {
		$this->todo_reason=$reason!=='' ? $reason : 'Test marked todo.';
		return $this;
	}

	public function only(): self {
		$this->only=true;
		return $this;
	}

	public function skipIf(mixed $condition, string $reason=''): self {
		$should_skip=$condition instanceof Closure ? (bool)$condition() : (bool)$condition;
		return $should_skip ? $this->skip($reason) : $this;
	}

	public function skipUnless(mixed $condition, string $reason=''): self {
		$should_run=$condition instanceof Closure ? (bool)$condition() : (bool)$condition;
		return $should_run ? $this : $this->skip($reason);
	}

	public function maxMillis(int $milliseconds): self {
		$this->max_millis=max(1, $milliseconds);
		return $this;
	}

	/** @return array<int, string> */
	public function fixtures(): array {
		return $this->fixture_names;
	}

	/** @return array<int, string> */
	public function tags(): array {
		return $this->tags;
	}

	/** @return array<int, string> */
	public function groups(): array {
		return $this->groups;
	}

	/** @return array<int, string> */
	public function dependencies(): array {
		return $this->dependencies;
	}

	public function maxMillisValue(): ?int {
		return $this->max_millis;
	}

	public function orderValue(): int {
		return $this->order;
	}

	public function skipReason(): ?string {
		return $this->skip_reason;
	}

	public function todoReason(): ?string {
		return $this->todo_reason;
	}

	public function isOnly(): bool {
		return $this->only;
	}

	/** @return array<int, string|iterable<mixed>|Closure> */
	public function datasets(): array {
		return $this->datasets;
	}
}

final class ExecutionCase {

	/** @param array<int, mixed> $arguments */
	public function __construct(public CaseDefinition $definition, public string $name, public string $dataset, public array $arguments) {}
}

final class Registry {

	/** @var array<int, CaseDefinition> */
	private static array $cases=[];
	/** @var array<string, iterable<mixed>|Closure> */
	private static array $datasets=[];
	/** @var array<string, FixtureDefinition> */
	private static array $fixtures=[];
	/** @var array<int, Closure> */
	private static array $before_all=[];
	/** @var array<int, Closure> */
	private static array $before_all_ran=[];
	/** @var array<int, Closure> */
	private static array $before_each=[];
	/** @var array<int, Closure> */
	private static array $after_each=[];
	/** @var array<int, Closure> */
	private static array $after_all=[];

	public static function reset(): void {
		self::$cases=[];
		self::$datasets=[];
		self::$fixtures=[];
		self::$before_all=[];
		self::$before_all_ran=[];
		self::$before_each=[];
		self::$after_each=[];
		self::$after_all=[];
	}

	public static function test(string $name, callable $body): CaseDefinition {
		$trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
		$case=new CaseDefinition($name, Closure::fromCallable($body), (string)($trace['file'] ?? ''), (int)($trace['line'] ?? 0));
		self::$cases[]=$case;
		return $case;
	}

	public static function dataset(string $name, iterable|Closure $rows): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Dataset name cannot be blank.');
		}
		self::$datasets[$name]=$rows;
	}

	public static function fixture(string $name, callable $setup, ?callable $teardown=null): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Fixture name cannot be blank.');
		}
		self::$fixtures[$name]=new FixtureDefinition($name, Closure::fromCallable($setup), $teardown===null ? null : Closure::fromCallable($teardown));
	}

	public static function beforeEach(callable $callback): void {
		self::$before_each[]=Closure::fromCallable($callback);
	}

	public static function afterEach(callable $callback): void {
		self::$after_each[]=Closure::fromCallable($callback);
	}

	public static function beforeAll(callable $callback): void {
		self::$before_all[]=Closure::fromCallable($callback);
	}

	public static function afterAll(callable $callback): void {
		self::$after_all[]=Closure::fromCallable($callback);
	}

	/** @return array<int, array<string, mixed>> */
	public static function caseSummaries(?string $file=null): array {
		$summaries=[];
		foreach(self::expandedCases() as $index=>$case){
			$summaries[]=[
				'index'=>$index,
				'name'=>$case->name,
				'base_name'=>$case->definition->name,
				'dataset'=>$case->dataset,
				'file'=>$file ?? $case->definition->file,
				'line'=>$case->definition->line,
				'fixtures'=>$case->definition->fixtures(),
				'tags'=>$case->definition->tags(),
				'groups'=>$case->definition->groups(),
				'dependencies'=>$case->definition->dependencies(),
				'order'=>$case->definition->orderValue(),
				'max_millis'=>$case->definition->maxMillisValue(),
				'skipped'=>$case->definition->skipReason()!==null,
				'skip_reason'=>$case->definition->skipReason(),
				'todo'=>$case->definition->todoReason()!==null,
				'todo_reason'=>$case->definition->todoReason(),
				'only'=>$case->definition->isOnly(),
			];
		}
		return $summaries;
	}

	/** @return array<int, ExecutionCase> */
	public static function expandedCases(): array {
		$expanded=[];
		$cases=self::$cases;
		usort($cases, static fn(CaseDefinition $a, CaseDefinition $b): int=>[$a->orderValue(), $a->name] <=> [$b->orderValue(), $b->name]);
		foreach($cases as $case){
			$rows=self::caseDatasetRows($case);
			foreach($rows as $row){
				$label=(string)$row['label'];
				$expanded[]=new ExecutionCase(
					$case,
					$label==='' ? $case->name : $case->name.' ['.$label.']',
					$label,
					$row['arguments']
				);
			}
		}
		return $expanded;
	}

	/** @return array<string, mixed> */
	public static function run(int $index, ?string $file=null): array {
		$cases=self::expandedCases();
		if(!isset($cases[$index])){
			return [
				'type'=>'code_unit_test',
				'test_name'=>'case #'.$index,
				'case_index'=>$index,
				'file'=>$file,
				'message'=>'Code-defined unit-test case index does not exist.',
				'passed'=>false,
			];
		}
		$case=$cases[$index];
		$context=new Context($case->name, $case->dataset, $file ?? $case->definition->file);
		$fixtures=[];
		$passed=false;
		$message='Code-defined unit test passed.';
		$details=[];
		$started=microtime(true);
		$skipped=false;
		$todo=false;
		$run_after_each=false;
		try{
			if($case->definition->todoReason()!==null){
				throw new SkippedTest($case->definition->todoReason() ?? 'Test marked todo.', true);
			}
			if($case->definition->skipReason()!==null){
				throw new SkippedTest($case->definition->skipReason() ?? 'Test skipped.');
			}
			$run_after_each=true;
			foreach(self::$before_all as $callback_index=>$callback){
				if(isset(self::$before_all_ran[$callback_index])){
					continue;
				}
				self::invoke($callback, [$context]);
				self::$before_all_ran[$callback_index]=$callback;
			}
			foreach(self::$before_each as $callback){
				self::invoke($callback, [$context]);
			}
			foreach($case->definition->fixtures() as $fixture_name){
				if(!isset(self::$fixtures[$fixture_name])){
					throw new AssertionFailed("Fixture '{$fixture_name}' is not registered.");
				}
				$fixtures[$fixture_name]=self::invoke(self::$fixtures[$fixture_name]->setup, [$context]);
			}
			$context->setFixtures($fixtures);
			$result=self::invoke($case->definition->body, array_merge([$context], $case->arguments));
			if($result===false){
				throw new AssertionFailed('Test returned false.');
			}
			$passed=true;
		}catch(SkippedTest $skip){
			$skipped=true;
			$todo=$skip->isTodo();
			$passed=true;
			$message=$skip->getMessage();
		}catch(AssertionFailed $failure){
			$message=$failure->getMessage();
			$details=$failure->details();
		}catch(Throwable $throwable){
			$message=$throwable->getMessage();
			$details=[
				'exception'=>$throwable::class,
				'file'=>$throwable->getFile(),
				'line'=>$throwable->getLine(),
			];
		}
		$teardown_error=null;
		foreach(array_reverse($case->definition->fixtures()) as $fixture_name){
			$fixture=self::$fixtures[$fixture_name] ?? null;
			if($fixture?->teardown===null){
				continue;
			}
			try{
				self::invoke($fixture->teardown, [$fixtures[$fixture_name] ?? null, $context]);
			}catch(Throwable $throwable){
				$teardown_error=[
					'message'=>$throwable->getMessage(),
					'exception'=>$throwable::class,
					'file'=>$throwable->getFile(),
					'line'=>$throwable->getLine(),
				];
				$passed=false;
			}
		}
		if($run_after_each===true){
			foreach(self::$after_each as $callback){
				try{
					self::invoke($callback, [$context]);
				}catch(Throwable $throwable){
					$teardown_error=[
						'message'=>$throwable->getMessage(),
						'exception'=>$throwable::class,
						'file'=>$throwable->getFile(),
						'line'=>$throwable->getLine(),
					];
					$passed=false;
				}
			}
			foreach(self::$after_all as $callback){
				try{
					self::invoke($callback, [$context]);
				}catch(Throwable $throwable){
					$teardown_error=[
						'message'=>$throwable->getMessage(),
						'exception'=>$throwable::class,
						'file'=>$throwable->getFile(),
						'line'=>$throwable->getLine(),
					];
					$passed=false;
				}
			}
		}
		$execution_time=microtime(true)-$started;
		$max_millis=$case->definition->maxMillisValue();
		if($passed===true && $max_millis!==null && $execution_time * 1000 > $max_millis){
			$passed=false;
			$message='Execution time exceeded maxMillis threshold.';
			$details=[
				'expected_millis'=>$max_millis,
				'actual_millis'=>$execution_time * 1000,
			];
		}
		if($teardown_error!==null){
			$message='Fixture teardown failed: '.$teardown_error['message'];
			$details['teardown']=$teardown_error;
		}
		return [
			'type'=>'code_unit_test',
			'test_name'=>$case->name,
			'case_index'=>$index,
			'dataset'=>$case->dataset,
			'file'=>$file ?? $case->definition->file,
			'line'=>$case->definition->line,
			'assertions'=>$context->assertions(),
			'execution_time'=>$execution_time,
			'message'=>$message,
			'details'=>$details,
			'skipped'=>$skipped,
			'todo'=>$todo,
			'passed'=>$passed,
		];
	}

	/** @return array<int, array{label:string, arguments:array<int, mixed>}> */
	private static function caseDatasetRows(CaseDefinition $case): array {
		if($case->datasets()===[]){
			return [['label'=>'', 'arguments'=>[]]];
		}
		$rows=[];
		foreach($case->datasets() as $dataset){
			foreach(self::normalizeRows(self::resolveDataset($dataset)) as $row){
				$rows[]=$row;
			}
		}
		return $rows!==[] ? $rows : [['label'=>'', 'arguments'=>[]]];
	}

	private static function resolveDataset(string|iterable|Closure $dataset): iterable {
		if(is_string($dataset)){
			if(!array_key_exists($dataset, self::$datasets)){
				throw new \InvalidArgumentException("Dataset '{$dataset}' is not registered.");
			}
			$dataset=self::$datasets[$dataset];
		}
		if($dataset instanceof Closure){
			$dataset=$dataset();
		}
		if(is_array($dataset) || $dataset instanceof Traversable){
			return $dataset;
		}
		throw new \InvalidArgumentException('Dataset must resolve to an array or Traversable value.');
	}

	/** @return array<int, array{label:string, arguments:array<int, mixed>}> */
	private static function normalizeRows(iterable $rows): array {
		$normalized=[];
		foreach($rows as $label=>$row){
			if(is_array($row) && self::isList($row)){
				$arguments=$row;
			}
			elseif(is_array($row))
			{
				$arguments=[$row];
			}
			else
			{
				$arguments=[$row];
			}
			$normalized[]=[
				'label'=>is_string($label) ? $label : (string)count($normalized),
				'arguments'=>$arguments,
			];
		}
		return $normalized;
	}

	/** @param array<int, mixed> $arguments */
	private static function invoke(Closure $callback, array $arguments): mixed {
		$reflection=new ReflectionFunction($callback);
		return $callback(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));
	}

	/** @param array<mixed> $value */
	private static function isList(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}
}

function test(string $name, callable $body): CaseDefinition {
	return Registry::test($name, $body);
}

function todo(string $name, string $reason=''): CaseDefinition {
	return Registry::test($name, static function(Context $t)use($reason): void {
		$t->todo($reason);
	})->todo($reason);
}

function dataset(string $name, iterable|Closure $rows): void {
	Registry::dataset($name, $rows);
}

function fixture(string $name, callable $setup, ?callable $teardown=null): void {
	Registry::fixture($name, $setup, $teardown);
}

function before_all(callable $callback): void {
	Registry::beforeAll($callback);
}

function after_all(callable $callback): void {
	Registry::afterAll($callback);
}

function before_each(callable $callback): void {
	Registry::beforeEach($callback);
}

function after_each(callable $callback): void {
	Registry::afterEach($callback);
}
