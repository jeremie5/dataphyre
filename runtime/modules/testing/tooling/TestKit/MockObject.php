<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

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
