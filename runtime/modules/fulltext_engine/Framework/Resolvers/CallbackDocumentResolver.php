<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine\Resolvers;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;
use Dataphyre\FulltextEngine\IndexDefinition;

/**
 * Resolves fulltext documents by delegating to a caller-supplied callback.
 *
 * The callback receives the requested ids and active index definition, allowing
 * custom document loading strategies without implementing a dedicated resolver
 * class. Non-array callback results are normalized to an empty document set.
 */
final class CallbackDocumentResolver implements DocumentResolver {

	/**
	 * Stores the callback used by future resolve calls.
	 *
	 * @param readonly mixed $callback Callable accepting ids and an optional IndexDefinition.
	 */
	public function __construct(
		private readonly mixed $callback
	){}

	/**
	 * Invokes the callback and returns its document map.
	 *
	 * @param list<string|int> $ids Document ids requested by the indexer.
	 * @param ?IndexDefinition $definition Active index definition.
	 * @return array<int|string,mixed> Documents keyed by id, or an empty array for invalid callback output.
	 */
	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		$documents=($this->callback)($ids, $definition);
		return is_array($documents) ? $documents : [];
	}
}
