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

	public function assertDispatched(AssertionContext $t, string $event, mixed $payload_subset=null): void {
		$found=false;
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
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected Reactor event to be dispatched.', ['name'=>$event, 'payload_subset'=>$payload_subset], $this->events);
		}
		$t->isTrue(true, 'Reactor event was dispatched.');
	}

	public function assertListening(AssertionContext $t, string $event): void {
		$t->isTrue(isset($this->listeners[$event]) && $this->listeners[$event]!==[], 'Expected Reactor listener to be registered.');
	}
}
