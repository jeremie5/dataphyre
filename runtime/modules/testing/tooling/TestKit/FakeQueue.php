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

	public function assertPushed(AssertionContext $t, string $name, mixed $payload_subset=null): void {
		$found=false;
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
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected fake queue to contain job.', ['name'=>$name, 'payload_subset'=>$payload_subset], $this->jobs);
		}
		$t->isTrue(true, 'Queue job was pushed.');
	}

	public function assertPushedCount(AssertionContext $t, int $expected): void {
		$t->same($expected, count($this->jobs), 'Expected fake queue job count to match.');
	}
}
