<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

/**
 * Framework-level handle for a Dataphyre async promise.
 *
 * `PendingTask` wraps the kernel promise object with a small typed API for
 * chaining callbacks, cancelling work, inspecting state, and safely reading a
 * fulfilled value or rejected reason with caller-provided defaults. Chaining
 * methods return new task handles around the promise returned by the underlying
 * async implementation.
 */
final class PendingTask {

	private \dataphyre\async\promise $promise;

	/**
	 * Creates a task handle around an existing kernel promise.
	 *
	 *
	 */
	public function __construct(\dataphyre\async\promise $promise){
		$this->promise=$promise;
	}

	/**
	 * Creates a task handle from a kernel promise.
	 *
	 *
	 * @return self Task handle for the provided promise.
	 */
	public static function fromPromise(\dataphyre\async\promise $promise): self {
		return new self($promise);
	}

	/**
	 * Returns the wrapped kernel promise.
	 *
	 * This exposes the lower-level promise for code that needs kernel-specific
	 * behavior not represented by the framework façade.
	 *
	 * @return \dataphyre\async\promise Wrapped promise instance.
	 */
	public function rawPromise(): \dataphyre\async\promise {
		return $this->promise;
	}

	/**
	 * Registers fulfillment and rejection callbacks and returns the chained task.
	 *
	 * Callback execution, error propagation, and returned promise state follow the
	 * kernel promise implementation.
	 *
	 * @param ?callable $onFulfilled Callback invoked when the promise fulfills.
	 * @param ?callable $onRejected Callback invoked when the promise rejects.
	 * @return self Task handle for the chained promise.
	 */
	public function then(?callable $onFulfilled=null, ?callable $onRejected=null): self {
		return new self($this->promise->then($onFulfilled, $onRejected));
	}

	/**
	 * Registers a rejection callback and returns the chained task.
	 *
	 *
	 * @return self Task handle for the chained promise.
	 */
	public function catch(callable $onRejected): self {
		return new self($this->promise->catch($onRejected));
	}

	/**
	 * Registers a finalization callback and returns the chained task.
	 *
	 * The callback runs according to the kernel promise `finally()` semantics and
	 * does not change this task handle.
	 *
	 * @param callable $onFinally Callback invoked after settlement.
	 * @return self Task handle for the chained promise.
	 */
	public function finally(callable $onFinally): self {
		return new self($this->promise->finally($onFinally));
	}

	/**
	 * Requests cancellation of the wrapped promise.
	 *
	 * Cancellation behavior is delegated to the kernel promise. Depending on the
	 * underlying task, cancellation may reject the promise, stop queued work, or be
	 * ignored if the work has already settled.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$this->promise->cancel();
	}

	/**
	 * Returns the current promise state.
	 *
	 * @return string Promise state as reported by the kernel promise, typically `pending`, `fulfilled`, or `rejected`.
	 */
	public function state(): string {
		return $this->promise->state();
	}

	/**
	 * Reports whether the task is no longer pending.
	 *
	 *
	 * @return bool True when the underlying promise is fulfilled or rejected.
	 */
	public function settled(): bool {
		return $this->promise->settled();
	}

	/**
	 * Reports whether the task is still pending.
	 *
	 *
	 * @return bool True when the current state is `pending`.
	 */
	public function pending(): bool {
		return $this->state()==='pending';
	}

	/**
	 * Reports whether the task fulfilled successfully.
	 *
	 *
	 * @return bool True when the current state is `fulfilled`.
	 */
	public function fulfilled(): bool {
		return $this->state()==='fulfilled';
	}

	/**
	 * Reports whether the task rejected.
	 *
	 *
	 * @return bool True when the current state is `rejected`.
	 */
	public function rejected(): bool {
		return $this->state()==='rejected';
	}

	/**
	 * Returns the fulfilled value or a fallback while the task is not fulfilled.
	 *
	 * The method never waits for a pending task. It only reads the promise value
	 * when the current state is already fulfilled.
	 *
	 * @param mixed $default Value returned for pending or rejected tasks.
	 * @return mixed settled fulfillment payload, or the caller default for pending/rejected tasks.
	 */
	public function value(mixed $default=null): mixed {
		if($this->fulfilled()===false){
			return $default;
		}
		return $this->promise->value();
	}

	/**
	 * Returns the rejection reason or a fallback while the task is not rejected.
	 *
	 * The kernel promise stores the settled payload behind `value()`, so rejected
	 * tasks read their reason from the same low-level accessor.
	 *
	 * @param mixed $default Value returned for pending or fulfilled tasks.
	 * @return mixed settled rejection reason, or the caller default for pending/fulfilled tasks.
	 */
	public function reason(mixed $default=null): mixed {
		if($this->rejected()===false){
			return $default;
		}
		return $this->promise->value();
	}
}
