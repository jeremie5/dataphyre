<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

final class Batch {

	/** @var array<int, PendingTask> */
	private array $tasks;

	public function __construct(array $tasks){
		$this->tasks=array_values($tasks);
	}

	public function tasks(): array {
		return $this->tasks;
	}

	public function count(): int {
		return count($this->tasks);
	}

	public function all(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::all($this->promises()));
	}

	public function race(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::race($this->promises()));
	}

	public function settled(): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::allSettled($this->promises()));
	}

	private function promises(): array {
		return array_map(static function(PendingTask $task): \dataphyre\async\promise {
			return $task->rawPromise();
		}, $this->tasks);
	}
}
