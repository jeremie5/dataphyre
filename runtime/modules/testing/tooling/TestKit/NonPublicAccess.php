<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\RuntimeContext;

/**
 * Reflection-backed access to intentionally tested non-public seams.
 * Keeping this in TestKit removes one-off ReflectionMethod helpers from suites.
 */
final class NonPublicAccess {

	public function __construct(private RuntimeContext $context, private object|string $target) {}

	public function invoke(string $method, mixed ...$arguments): mixed {
		return $this->invokeWithArguments($method, $arguments);
	}

	/**
	 * Executes a named matrix of non-public method scenarios.
	 *
	 * The returned map keeps scenario labels attached to results, allowing a
	 * dense boundary table to remain readable without repeating Reflection or
	 * one-off transforms between assertions.
	 *
	 * @param array<string,array{method:string,arguments?:array<int,mixed>}> $cases
	 * @return array<string,mixed>
	 */
	public function invokeCases(array $cases): array {
		$results=[];
		foreach($cases as $label=>$case){
			$method=$case['method'] ?? null;
			$arguments=$case['arguments'] ?? [];
			if(!is_string($method) || $method==='' || !is_array($arguments)){
				throw new \InvalidArgumentException('Non-public method cases require a method and an argument list: '.$label);
			}
			$results[$label]=$this->invokeWithArguments($method,$arguments);
		}
		return $results;
	}

	/** @param array<int,mixed> $arguments */
	public function invokeWithArguments(string $method, array $arguments=[]): mixed {
		return $this->method($method)->invokeArgs(is_object($this->target) ? $this->target : null, $arguments);
	}

	/**
	 * Invokes a method while automatically capturing production parameters that
	 * are declared by-reference. Named arguments make the tested mutation readable.
	 */
	public function capture(string $method, mixed ...$arguments): NonPublicInvocation {
		$reflection=$this->method($method);
		$parameters=$reflection->getParameters();
		$last_parameter=$parameters===[] ? null : $parameters[array_key_last($parameters)];
		$call_arguments=[];
		$captured_arguments=[];
		$argument_names=[];
		foreach($arguments as $index=>&$argument){
			$parameter=is_int($index)
				? ($parameters[$index] ?? ($last_parameter?->isVariadic() ? $last_parameter : null))
				: self::namedParameter($parameters, $index, $last_parameter);
			if($parameter!==null){
				$argument_names[$parameter->getName()]=$index;
			}
			if($parameter?->isPassedByReference()){
				$captured_arguments[$index]=&$argument;
				$call_arguments[$index]=&$argument;
			}else{
				$captured_arguments[$index]=$argument;
				$call_arguments[$index]=$argument;
			}
		}
		unset($argument);
		$result=$reflection->invokeArgs(is_object($this->target) ? $this->target : null, $call_arguments);
		return new NonPublicInvocation($result, $captured_arguments, $argument_names);
	}

	public function readProperty(string $property): mixed {
		$reflection=$this->property($property);
		return $reflection->getValue(is_object($this->target) ? $this->target : null);
	}

	public function writeProperty(string $property, mixed $value): self {
		$reflection=$this->property($property);
		$reflection->setValue(is_object($this->target) ? $this->target : null, $value);
		return $this;
	}

	public function replacePropertyForTest(string $property, mixed $value): self {
		$reflection=$this->property($property);
		$target=is_object($this->target) ? $this->target : null;
		$previous=$reflection->getValue($target);
		$this->context->defer(static fn()=>$reflection->setValue($target, $previous));
		$reflection->setValue($target, $value);
		return $this;
	}

	public function withoutConstructor(): object {
		$class=is_object($this->target) ? $this->target::class : $this->target;
		return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
	}

	private function property(string $property): \ReflectionProperty {
		$reflection=new \ReflectionProperty(is_object($this->target) ? $this->target::class : $this->target, $property);
		$reflection->setAccessible(true);
		return $reflection;
	}

	private function method(string $method): \ReflectionMethod {
		$reflection=new \ReflectionMethod(is_object($this->target) ? $this->target::class : $this->target, $method);
		$reflection->setAccessible(true);
		return $reflection;
	}

	/** @param array<int,\ReflectionParameter> $parameters */
	private static function namedParameter(array $parameters, string $name, ?\ReflectionParameter $last_parameter): ?\ReflectionParameter {
		foreach($parameters as $parameter){
			if($parameter->getName()===$name){
				return $parameter;
			}
		}
		return $last_parameter?->isVariadic() ? $last_parameter : null;
	}
}
