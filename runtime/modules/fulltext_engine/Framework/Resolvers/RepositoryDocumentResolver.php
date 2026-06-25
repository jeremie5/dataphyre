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

/**
 * Resolves fulltext documents through a TableRepository implementation.
 *
 * The resolver is an adapter between an index definition and repository classes
 * that expose findKeyedByIds(). It validates the configured class at call time,
 * preserves repository caching semantics, and optionally maps each document into
 * the shape expected by the indexer.
 */
final class RepositoryDocumentResolver implements DocumentResolver {

	/**
	 * Captures the repository lookup contract for later resolve calls.
	 *
	 * The mapper, when provided, receives the repository row and the active index
	 * definition and must return the document data to index under the same id.
	 *
	 * @param readonly string $repositoryClass Fully-qualified TableRepository subclass name.
	 * @param readonly string $primaryKeyColumn Repository column used as the returned document key.
	 * @param readonly list<string>|string $columns Column list forwarded to findKeyedByIds().
	 * @param readonly bool|array|string|null $caching Repository cache option forwarded unchanged.
	 * @param readonly mixed $mapper Optional callable that transforms each repository row.
	 */
	public function __construct(
		private readonly string $repositoryClass,
		private readonly string $primaryKeyColumn,
		private readonly array|string $columns='*',
		private readonly bool|array|string|null $caching=false,
		private readonly mixed $mapper=null
	){}

	/**
	 * Loads repository rows keyed by id and maps them into indexable documents.
	 *
	 * Invalid repository configuration throws immediately because a silent empty
	 * result would hide index wiring errors. Non-array repository results are
	 * treated as no documents, matching the resolver contract.
	 *
	 * @param list<string|int> $ids Document ids requested by the indexer.
	 * @param ?IndexDefinition $definition Active index definition supplied to the optional mapper.
	 * @return array<int|string,mixed> Documents keyed by primary key value.
	 */
	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		$repositoryClass=trim($this->repositoryClass);
		if($repositoryClass==='' || !class_exists($repositoryClass)){
			throw new \RuntimeException("Fulltext repository resolver class '{$repositoryClass}' does not exist.");
		}
		if(!is_subclass_of($repositoryClass, TableRepository::class)){
			throw new \RuntimeException("Fulltext repository resolver '{$repositoryClass}' must extend ".TableRepository::class.'.');
		}
		if(!method_exists($repositoryClass, 'findKeyedByIds')){
			throw new \RuntimeException("Fulltext repository resolver '{$repositoryClass}' does not expose findKeyedByIds().");
		}
		$documents=$repositoryClass::findKeyedByIds($ids, $this->primaryKeyColumn, $this->columns, $this->caching);
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
