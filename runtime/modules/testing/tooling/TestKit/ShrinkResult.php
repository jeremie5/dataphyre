<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class ShrinkResult {

	/** @param list<mixed> $path */
	public function __construct(
		public mixed $original,
		public mixed $minimal,
		public int $candidates,
		public array $path,
		public bool $fixed_point
	) {}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'original'=>$this->original,
			'minimal'=>$this->minimal,
			'candidates'=>$this->candidates,
			'steps'=>count($this->path),
			'path'=>$this->path,
			'fixed_point'=>$this->fixed_point,
		];
	}
}
