<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Hydrators;

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\TableSchema;

/**
 * Delegates row hydration to a caller-supplied callback.
 *
 * This hydrator is the escape hatch for query paths that need domain-specific
 * objects or transformed arrays instead of the default Record wrapper.
 */
final class CallbackRecordHydrator implements RecordHydrator {

	/**
	 * Creates a callback record hydrator instance.
	 *
	 * Constructor parameters define the immutable or initial runtime contract for this value object/service.
	 */
	public function __construct(
		private readonly mixed $callback
	){}

	/**
	 * Invokes the configured callback with the row and optional table schema.
	 *
	 * Callback exceptions are intentionally allowed to bubble so repository and
	 * query callers see the original hydration failure.
	 *
	 * @param array<string, mixed> $row Column values returned by the database driver.
	 * @param ?TableSchema $schema Schema associated with the result set when known.
	 * @return mixed record object, DTO, array, or domain value returned by the hydration callback.
	 */
	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		return ($this->callback)($row, $schema);
	}
}
