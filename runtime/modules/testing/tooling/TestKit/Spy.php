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
use Throwable;

final class Spy {

	/** @var array<int, array<int, mixed>> */
	private array $calls=[];
	/** @var list<array{kind:string,value:mixed}> */
	private array $script=[];

	public function __construct(private mixed $passthrough=null) {}

	public function __invoke(mixed ...$arguments): mixed {
		$this->calls[]=$arguments;
		if($this->script!==[]){
			$step=array_shift($this->script);
			return match($step['kind']){
				'throw'=>throw $step['value'],
				'call'=>($step['value'])(...$arguments),
				default=>$step['value'],
			};
		}
		return is_callable($this->passthrough) ? ($this->passthrough)(...$arguments) : null;
	}

	public function willReturn(mixed $value): self {
		$this->script=[];
		$this->passthrough=static fn(): mixed=>$value;
		return $this;
	}

	public function willReturnInOrder(mixed ...$values): self {
		$this->script=[];
		foreach($values as $value){
			$this->thenReturn($value);
		}
		return $this;
	}

	public function willThrow(Throwable $throwable): self {
		$this->script=[];
		$this->passthrough=static fn()=>throw $throwable;
		return $this;
	}

	public function thenReturn(mixed $value): self {
		$this->script[]=['kind'=>'return', 'value'=>$value];
		return $this;
	}

	public function thenThrow(Throwable $throwable): self {
		$this->script[]=['kind'=>'throw', 'value'=>$throwable];
		return $this;
	}

	public function thenCall(callable $callback): self {
		$this->script[]=['kind'=>'call', 'value'=>Closure::fromCallable($callback)];
		return $this;
	}

	/** @return array<int, array<int, mixed>> */
	public function calls(): array {
		return $this->calls;
	}

	public function count(): int {
		return count($this->calls);
	}

	/** @return array<int,mixed> */
	public function lastCall(): array {
		if($this->calls===[]){
			throw new \OutOfBoundsException('Spy has not been called.');
		}
		return $this->calls[array_key_last($this->calls)];
	}

	/** @return array<int,mixed> */
	public function call(int $index): array {
		if(!array_key_exists($index, $this->calls)){
			throw new \OutOfBoundsException('Spy call is unavailable: '.$index);
		}
		return $this->calls[$index];
	}

	public function assertCalled(AssertionContext $t): void {
		$t->greaterThan(0, $this->count(), 'Expected spy to be called.');
	}

	public function assertCalledTimes(AssertionContext $t, int $expected): void {
		$t->same($expected, $this->count(), 'Expected spy call count to match.');
	}

	public function assertCalledWith(AssertionContext $t, array $arguments): void {
		$found=false;
		foreach($this->calls as $call){
			if($call===$arguments){
				$found=true;
				break;
			}
		}
		if($found===false){
			$t->fail('Expected spy to be called with arguments.', $arguments, $this->calls);
		}
		$t->isTrue(true, 'Spy was called with expected arguments.');
	}

	public function assertCalledWithSubset(AssertionContext $t, array $arguments): void {
		$found=false;
		foreach($this->calls as $call){
			try{
				$t->subset($arguments, $call);
				$found=true;
				break;
			}catch(AssertionFailed){
			}
		}
		if($found===false){
			$t->fail('Expected spy to be called with an argument subset.', $arguments, $this->calls);
		}
		$t->isTrue(true, 'Spy was called with the expected argument subset.');
	}
}
