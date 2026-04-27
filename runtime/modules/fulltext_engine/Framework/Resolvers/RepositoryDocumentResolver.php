<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine\Resolvers;

use Dataphyre\Database\TableRepository;
use Dataphyre\FulltextEngine\Contracts\DocumentResolver;
use Dataphyre\FulltextEngine\IndexDefinition;

final class RepositoryDocumentResolver implements DocumentResolver {

	public function __construct(
		private readonly string $repository_class,
		private readonly string $primary_key_column,
		private readonly array|string $columns='*',
		private readonly bool|array|string|null $caching=false,
		private readonly mixed $mapper=null
	){}

	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		$repository_class=trim($this->repository_class);
		if($repository_class==='' || !class_exists($repository_class)){
			throw new \RuntimeException("Fulltext repository resolver class '{$repository_class}' does not exist.");
		}
		if(!is_subclass_of($repository_class, TableRepository::class)){
			throw new \RuntimeException("Fulltext repository resolver '{$repository_class}' must extend ".TableRepository::class.'.');
		}
		if(!method_exists($repository_class, 'findKeyedByIds')){
			throw new \RuntimeException("Fulltext repository resolver '{$repository_class}' does not expose findKeyedByIds().");
		}
		$documents=$repository_class::findKeyedByIds($ids, $this->primary_key_column, $this->columns, $this->caching);
		if(!is_array($documents)){
			return [];
		}
		if($this->mapper===null){
			return $documents;
		}
		foreach($documents as $id=>$document){
			$documents[$id]=($this->mapper)($document, $definition);
		}
		return $documents;
	}
}
