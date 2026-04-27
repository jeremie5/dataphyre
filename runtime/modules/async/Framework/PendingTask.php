<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

final class PendingTask {

	private \dataphyre\async\promise $promise;

	public function __construct(\dataphyre\async\promise $promise){
		$this->promise=$promise;
	}

	public static function fromPromise(\dataphyre\async\promise $promise): self {
		return new self($promise);
	}

	public function rawPromise(): \dataphyre\async\promise {
		return $this->promise;
	}

	public function then(?callable $on_fulfilled=null, ?callable $on_rejected=null): self {
		return new self($this->promise->then($on_fulfilled, $on_rejected));
	}

	public function catch(callable $on_rejected): self {
		return new self($this->promise->catch($on_rejected));
	}

	public function finally(callable $on_finally): self {
		return new self($this->promise->finally($on_finally));
	}

	public function cancel(): void {
		$this->promise->cancel();
	}

	public function state(): string {
		return $this->promise->state();
	}

	public function settled(): bool {
		return $this->promise->settled();
	}

	public function pending(): bool {
		return $this->state()==='pending';
	}

	public function fulfilled(): bool {
		return $this->state()==='fulfilled';
	}

	public function rejected(): bool {
		return $this->state()==='rejected';
	}

	public function value(mixed $default=null): mixed {
		if($this->fulfilled()===false){
			return $default;
		}
		return $this->promise->value();
	}

	public function reason(mixed $default=null): mixed {
		if($this->rejected()===false){
			return $default;
		}
		return $this->promise->value();
	}
}
