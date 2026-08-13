<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Throwable;

/** Result, output, and throwable from one output-buffered callback. */
final class CapturedExecution {

	public function __construct(
		private string $output,
		private mixed $result,
		private ?Throwable $throwable
	) {}

	public function output(): string {
		return $this->output;
	}

	public function result(): mixed {
		return $this->result;
	}

	public function throwable(): ?Throwable {
		return $this->throwable;
	}

	public function returned(): bool {
		return $this->throwable===null;
	}

	public function threw(): bool {
		return $this->throwable!==null;
	}

	public function unwrap(): mixed {
		if($this->throwable!==null){
			throw $this->throwable;
		}
		return $this->result;
	}
}
