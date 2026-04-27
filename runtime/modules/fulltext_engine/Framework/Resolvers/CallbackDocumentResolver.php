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

final class CallbackDocumentResolver implements DocumentResolver {

	public function __construct(
		private readonly mixed $callback
	){}

	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		$documents=($this->callback)($ids, $definition);
		return is_array($documents) ? $documents : [];
	}
}
