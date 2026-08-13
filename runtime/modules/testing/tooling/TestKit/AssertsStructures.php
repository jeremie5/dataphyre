<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait AssertsStructures {

	public function hasKey(string|int $key, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_array($actual) || !array_key_exists($key, $actual)){
			$this->fail($message!=='' ? $message : 'Expected array key to exist.', $key, is_array($actual) ? array_keys($actual) : gettype($actual));
		}
	}

	public function hasKeys(iterable $keys, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		$expected=is_array($keys) ? array_values($keys) : array_values(iterator_to_array($keys,false));
		if(!is_array($actual)){
			$this->fail($message!=='' ? $message : 'Expected an array containing every key.', $expected, gettype($actual));
		}
		$missing=array_values(array_filter($expected,static fn(mixed $key): bool=>!is_string($key) && !is_int($key) || !array_key_exists($key,$actual)));
		if($missing!==[]){
			$this->fail($message!=='' ? $message : 'Expected array to contain every key.', $expected, array_keys($actual), ['missing_keys'=>$missing]);
		}
	}

	public function sameKeys(iterable $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		$expected_keys=is_array($expected) ? array_values($expected) : array_values(iterator_to_array($expected,false));
		if(!is_array($actual)){
			$this->fail($message!=='' ? $message : 'Expected an array with exactly the declared keys.', $expected_keys, gettype($actual));
		}
		$actual_keys=array_keys($actual);
		if($expected_keys!==$actual_keys){
			$this->fail($message!=='' ? $message : 'Expected array keys to match in order.', $expected_keys, $actual_keys);
		}
	}

	public function missingKey(string|int $key, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(is_array($actual) && array_key_exists($key, $actual)){
			$this->fail($message!=='' ? $message : 'Expected array key to be absent.', 'missing key '.$key, array_keys($actual));
		}
	}

	public function hasPath(string|array $path, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to exist.', $this->pathLabel($path), $this->pathShape($actual));
		}
	}

	public function missingPath(string|array $path, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if($this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to be absent.', 'missing path '.$this->pathLabel($path), $value);
		}
	}

	public function pathEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to exist.', $this->pathLabel($path), $this->pathShape($actual));
		}
		if($value!==$expected){
			$this->fail($message!=='' ? $message : 'Expected path value to match.', $expected, $value);
		}
	}

	public function pathNotEquals(string|array $path, mixed $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->valueAtPath($actual, $path, $value)){
			return;
		}
		if($value===$expected){
			$this->fail($message!=='' ? $message : 'Expected path value not to match.', 'not '.$this->describe($expected), $value);
		}
	}

	/** @param array<string,mixed> $expected */
	public function hasPathValues(array $expected, mixed $actual, string $message=''): void {
		foreach($expected as $path=>$value){
			$this->pathEquals((string)$path, $value, $actual, $message);
		}
	}

	public function pathContains(string|array $path, mixed $needle, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->valueAtPath($actual, $path, $value)){
			$this->fail($message!=='' ? $message : 'Expected path to exist.', $this->pathLabel($path), $this->pathShape($actual));
		}
		$found=is_string($value) && (is_string($needle) || is_numeric($needle))
			? str_contains($value, (string)$needle)
			: (is_array($value) && in_array($needle, $value, true));
		if($found!==true){
			$this->fail(
				$message!=='' ? $message : 'Expected path value to contain needle.',
				$needle,
				$value,
				['path'=>$this->pathLabel($path)],
			);
		}
	}

	/** @param array<string,mixed> $expected */
	public function pathsContain(array $expected, mixed $actual, string $message=''): void {
		foreach($expected as $path=>$needle){
			$this->pathContains((string)$path, $needle, $actual, $message);
		}
	}

	/** @param array<string,mixed> $expected */
	public function hasAccessorValues(array $expected, object $actual, string $message=''): void {
		foreach($expected as $method=>$value){
			$method=(string)$method;
			if($method==='' || !is_callable([$actual, $method])){
				$this->fail($message!=='' ? $message : 'Expected readable zero-argument accessor.', $method, $actual::class);
			}
			$reflection=new \ReflectionMethod($actual, $method);
			if($reflection->getNumberOfRequiredParameters()>0){
				$this->fail($message!=='' ? $message : 'Expected accessor to require no arguments.', $method.'()', $reflection->getNumberOfRequiredParameters().' required arguments');
			}
			$this->same($value, $actual->{$method}(), $message!=='' ? $message : 'Expected '.$actual::class.'::'.$method.'() to match.');
		}
	}

	/** @param array<mixed> $expected */
	public function subset(array $expected, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!$this->subsetMatches($expected, $actual)){
			$this->fail($message!=='' ? $message : 'Expected value to contain subset.', $expected, $actual);
		}
	}

	/**
	 * Asserts that an unordered record collection contains every declared row shape.
	 *
	 * Expected rows are recursive subsets and are matched one-to-one, so duplicate
	 * expectations require duplicate records. This keeps tests independent from
	 * result sorting without hiding missing fields behind array_column() transforms.
	 *
	 * @param array<int|string,array<mixed>> $expected_rows
	 */
	public function containsRows(array $expected_rows, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		if(!is_iterable($actual)){
			$this->fail($message!=='' ? $message : 'Expected an iterable record collection.', 'iterable rows', gettype($actual));
		}
		$rows=is_array($actual) ? $actual : iterator_to_array($actual, false);
		$remaining=array_values($rows);
		$missing=[];
		foreach($expected_rows as $label=>$expected){
			if(!is_array($expected)){
				$missing[$label]=$expected;
				continue;
			}
			$matched=null;
			foreach($remaining as $index=>$row){
				if($this->subsetMatches($expected, $row)){
					$matched=$index;
					break;
				}
			}
			if($matched===null){
				$missing[$label]=$expected;
				continue;
			}
			unset($remaining[$matched]);
		}
		if($missing!==[]){
			$this->fail(
				$message!=='' ? $message : 'Expected record collection to contain every row shape.',
				$expected_rows,
				$rows,
				['missing_rows'=>$missing],
			);
		}
	}

	private function valueAtPath(mixed $actual, string|array $path, mixed &$value): bool {
		$current=$actual;
		foreach($this->pathParts($path) as $part){
			if(is_array($current) && array_key_exists($part, $current)){
				$current=$current[$part];
				continue;
			}
			if(is_object($current)){
				$property=(string)$part;
				$public_properties=get_object_vars($current);
				if(array_key_exists($property, $public_properties)){
					$current=$public_properties[$property];
					continue;
				}
				if(isset($current->{$property})){
					$current=$current->{$property};
					continue;
				}
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

	private static function isListValue(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}
}
