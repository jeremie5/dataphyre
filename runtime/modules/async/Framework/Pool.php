<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

final class Pool {

	private AsyncManager $manager;
	private int $concurrency;
	private ?string $driver;

	public function __construct(AsyncManager $manager, int $concurrency=10, ?string $driver=null){
		$this->manager=$manager;
		$this->concurrency=max(1, $concurrency);
		$this->driver=$driver;
	}

	public function map(array $items, mixed $task): PendingTask {
		return PendingTask::fromPromise(new \dataphyre\async\promise(function($resolve, $reject)use($items, $task){
			$total=count($items);
			if($total===0){
				$resolve([]);
				return;
			}

			$results=[];
			$next_index=0;
			$active=0;
			$completed=0;
			$failed=false;

			$launch_next=function()use(&$launch_next, &$results, &$next_index, &$active, &$completed, &$failed, $items, $task, $total, $resolve, $reject){
				if($failed===true){
					return;
				}
				while($active<$this->concurrency && $next_index<$total){
					$current_index=$next_index;
					$next_index++;
					$active++;

					$this->manager
						->dispatch($task, [$items[$current_index], $current_index, $items], $this->driver)
						->rawPromise()
						->then(function($value)use(&$results, &$active, &$completed, &$launch_next, $current_index, $total, $resolve, &$failed){
							if($failed===true){
								return;
							}
							$results[$current_index]=$value;
							$active--;
							$completed++;
							if($completed>=$total){
								ksort($results);
								$resolve(array_values($results));
								return;
							}
							$launch_next();
						}, function($reason)use(&$failed, $reject){
							if($failed===true){
								return;
							}
							$failed=true;
							$reject($reason);
						});
				}
			};

			$launch_next();
		}));
	}

	public function each(array $items, mixed $task): PendingTask {
		return $this->map($items, $task)->then(static function(array $results): null {
			return null;
		});
	}
}
