<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

/**
 * Groups pending async tasks into promise combinators.
 *
 * Batch is a small orchestration value that preserves the selected PendingTask
 * instances and exposes all, race, and settled combinations as new PendingTask
 * wrappers. It does not start tasks itself; it composes the promises already
 * held by each task.
 */
final class Batch {

	/** @var array<int, PendingTask> */
	private array $tasks;

	/**
	 * Creates a batch from pending tasks.
	 *
	 * The input list is reindexed so task order is stable for promise combinators
	 * and serialized diagnostics.
	 *
	 * @param array<int, PendingTask> $tasks Pending tasks to compose.
	 */
	public function __construct(array $tasks){
		$this->tasks=array_values($tasks);
	}

	/**
	 * Returns the pending tasks in this batch.
	 *
	 * @return array<int, PendingTask> Reindexed task list.
	 */
	public function tasks(): array {
		return $this->tasks;
	}

	/**
	 * Counts pending tasks in the batch.
	 *
	 * @return int Number of tasks being composed.
	 */
	public function count(): int {
		return count($this->tasks);
	}

	/**
	 * Returns a task that resolves when every task resolves.
	 *
	 * The resulting PendingTask wraps `promise::all()` and rejects according to
	 * the underlying promise implementation when any member rejects.
	 *
	 * @return PendingTask Composite task for all task promises.
	 */
	public function all(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::all($this->promises()));
	}

	/**
	 * Returns a task that resolves or rejects with the first completed task.
	 *
	 * The first task to settle controls the resulting promise state and value.
	 *
	 * @return PendingTask Composite task for the first settled task promise.
	 */
	public function race(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::race($this->promises()));
	}

	/**
	 * Returns a task that resolves after every task has settled.
	 *
	 * Fulfilled and rejected outcomes are collected without short-circuiting.
	 *
	 * @return PendingTask Composite task whose value describes all fulfilled and rejected outcomes.
	 */
	public function settled(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::all_settled($this->promises()));
	}

	/**
	 * Extracts raw promises from the pending tasks.
	 *
	 * @return array<int, \dataphyre\async\promise> Promise list in batch order.
	 */
	private function promises(): array {
		return array_map(static function(PendingTask $task): \dataphyre\async\promise {
			return $task->rawPromise();
		}, $this->tasks);
	}
}
