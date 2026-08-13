<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Captures a non-public call result together with its mutated in/out arguments. */
final class NonPublicInvocation {

	/** @param array<int|string,mixed> $arguments @param array<string,int|string> $argument_names */
	public function __construct(private mixed $result, private array $arguments, private array $argument_names=[]) {}

	public function result(): mixed {
		return $this->result;
	}

	public function argument(int|string $index): mixed {
		if(is_string($index)){
			if(!array_key_exists($index, $this->argument_names)){
				throw new \OutOfBoundsException('Non-public invocation argument is unavailable: '.$index);
			}
			$index=$this->argument_names[$index];
		}
		if(!array_key_exists($index, $this->arguments)){
			throw new \OutOfBoundsException('Non-public invocation argument is unavailable: '.$index);
		}
		return $this->arguments[$index];
	}

	/** @return array<int|string,mixed> */
	public function arguments(): array {
		return $this->arguments;
	}
}
