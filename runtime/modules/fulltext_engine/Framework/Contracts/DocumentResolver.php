<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine\Contracts;

use Dataphyre\FulltextEngine\IndexDefinition;

interface DocumentResolver {

	/**
	 * @param array<int, string> $ids
	 * @return array<string, mixed>
	 */
	public function resolve(array $ids, ?IndexDefinition $definition=null): array;
}
