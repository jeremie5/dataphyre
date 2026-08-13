<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;
use ReflectionFunction;

/** Owns the Context capabilities described by its name. */
trait InteractsWithExtensions {

	/** @var array<string, Closure> */
	private static array $extension_factories=[];

	/** @var array<string, mixed> */
	private array $extensions=[];
	/** Registers a lazily-created, process-local module testing kit. */
	public static function extend(string $name, callable $factory): void {
		$name=self::extensionName($name);
		self::$extension_factories[$name]=Closure::fromCallable($factory);
	}

	public static function hasExtension(string $name): bool {
		return isset(self::$extension_factories[self::extensionName($name)]);
	}

	public static function forgetExtension(string $name): void {
		unset(self::$extension_factories[self::extensionName($name)]);
	}

	/**
	 * Resolves one module kit per test context.
	 *
	 * @template T of object
	 * @param class-string<T>|null $expected_type
	 * @return ($expected_type is null ? mixed : T)
	 */
	public function extension(string $name, ?string $expected_type=null): mixed {
		$name=self::extensionName($name);
		if(!array_key_exists($name, $this->extensions)){
			$factory=self::$extension_factories[$name] ?? null;
			if(!$factory instanceof Closure){
				throw new \OutOfBoundsException("Test context extension '{$name}' is not registered.");
			}
			$reflection=new ReflectionFunction($factory);
			$this->extensions[$name]=$factory(...array_slice([$this, $name], 0, $reflection->getNumberOfParameters()));
		}
		$extension=$this->extensions[$name];
		if($expected_type!==null && !($extension instanceof $expected_type)){
			throw new \UnexpectedValueException("Test context extension '{$name}' must be an instance of {$expected_type}.");
		}
		return $extension;
	}

	/** Zero-argument extension calls keep domain DSLs concise: `$t->panel()`. */
	public function __call(string $name, array $arguments): mixed {
		if($arguments===[] && self::hasExtension($name)){
			return $this->extension($name);
		}
		throw new \BadMethodCallException("Unknown test context method or extension '{$name}'.");
	}

	private static function extensionName(string $name): string {
		$name=strtolower(trim($name));
		if(preg_match('/^[a-z][a-z0-9_.-]*$/', $name)!==1){
			throw new \InvalidArgumentException('Test context extension names must start with a letter and contain only letters, digits, dots, dashes, or underscores.');
		}
		return $name;
	}
}
