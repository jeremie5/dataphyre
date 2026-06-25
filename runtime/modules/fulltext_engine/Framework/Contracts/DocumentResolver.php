<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine\Contracts;

use Dataphyre\FulltextEngine\IndexDefinition;

/**
 * Resolves indexed document ids into document data for full-text indexing.
 *
 * Implementations may read SQL-backed records, cached documents, or external
 * stores, but must return documents keyed by document id so indexers can merge
 * resolver output back into the current batch.
 */
interface DocumentResolver {

	/**
	 * @param array<int, string> $ids Document ids requested by the indexer.
	 * @param ?IndexDefinition $definition Index configuration that requested the documents.
	 * @return array<string, mixed> Resolved documents keyed by document id.
	 */
	public function resolve(array $ids, ?IndexDefinition $definition=null): array;
}
