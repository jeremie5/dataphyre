<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class ExecutionCase {

	/** @param array<int, mixed> $arguments */
	public function __construct(
		public CaseDefinition $definition,
		public string $name,
		public string $dataset,
		public array $arguments,
		public int $repeat_index=1,
		public int $repeat_total=1,
		public string $stable_id=''
	) {}

	/** @return array<string,mixed> */
	public function metadata(): array {
		return $this->definition->metadata()+[
			'stable_id'=>$this->stable_id,
			'repeat_index'=>$this->repeat_index,
			'repeat_total'=>$this->repeat_total,
		];
	}
}
