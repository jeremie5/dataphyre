<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

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
