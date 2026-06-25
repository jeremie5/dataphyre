<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

/**
 * Dispatches many async tasks with bounded concurrency.
 *
 * A pool maps each item to an async task through an `AsyncManager`, keeps at most the configured
 * number of tasks active, preserves input order in the resolved results, and rejects the overall
 * pending task on the first task failure.
 */
final class Pool {

	/**
	 * Async manager used to dispatch individual item tasks.
	 */
	private AsyncManager $manager;

	/**
	 * Maximum number of active tasks at once.
	 */
	private int $concurrency;

	/**
	 * Optional async driver override used for every dispatched task.
	 */
	private ?string $driver;

	/**
	 * Creates a bounded async task pool.
	 *
	 * @param AsyncManager $manager Async dispatcher.
	 * @param int $concurrency Maximum active tasks, clamped to at least one.
	 * @param ?string $driver Optional driver override.
	 */
	public function __construct(AsyncManager $manager, int $concurrency=10, ?string $driver=null){
		$this->manager=$manager;
		$this->concurrency=max(1, $concurrency);
		$this->driver=$driver;
	}

	/**
	 * Maps input items through an async task with bounded concurrency.
	 *
	 * Each dispatched task receives the current item, its index, and the full item list. Results
	 * are sorted back into input order before resolving.
	 *
	 * @param array<int, mixed> $items Items to process.
	 * @param mixed $task Task accepted by `AsyncManager::dispatch()`.
	 * @return PendingTask Pending task resolving to ordered mapped results.
	 */
	public function map(array $items, mixed $task): PendingTask {
		return PendingTask::fromPromise(new \dataphyre\async\promise(function($resolve, $reject)use($items, $task){
			$total=count($items);
			if($total===0){
				$resolve([]);
				return;
			}

			$results=[];
			$nextIndex=0;
			$active=0;
			$completed=0;
			$failed=false;

			$launchNext=function()use(&$launchNext, &$results, &$nextIndex, &$active, &$completed, &$failed, $items, $task, $total, $resolve, $reject){
				if($failed===true){
					return;
				}
				while($active<$this->concurrency && $nextIndex<$total){
					$currentIndex=$nextIndex;
					$nextIndex++;
					$active++;

					$this->manager
						->dispatch($task, [$items[$currentIndex], $currentIndex, $items], $this->driver)
						->rawPromise()
						->then(function($value)use(&$results, &$active, &$completed, &$launchNext, $currentIndex, $total, $resolve, &$failed){
							if($failed===true){
								return;
							}
							$results[$currentIndex]=$value;
							$active--;
							$completed++;
							if($completed>=$total){
								ksort($results);
								$resolve(array_values($results));
								return;
							}
							$launchNext();
						}, function($reason)use(&$failed, $reject){
							if($failed===true){
								return;
							}
							$failed=true;
							$reject($reason);
						});
				}
			};

			$launchNext();
		}));
	}

	/**
	 * Runs an async task for each item and discards mapped results.
	 *
	 * @param array<int, mixed> $items Items to process.
	 * @param mixed $task Task accepted by `AsyncManager::dispatch()`.
	 * @return PendingTask Pending task resolving to null after all items complete.
	 */
	public function each(array $items, mixed $task): PendingTask {
		return $this->map($items, $task)->then(static function(array $results): null {
			return null;
		});
	}
}
