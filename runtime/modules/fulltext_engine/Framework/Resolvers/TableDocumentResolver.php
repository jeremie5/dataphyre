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
 * Resolves fulltext documents directly from a SQL table.
 *
 * This resolver is the low-level alternative to repository-backed lookup. It
 * normalizes requested ids, validates table and column identifiers before they
 * enter the SQL fragment, and returns rows keyed by the configured primary key.
 */
final class TableDocumentResolver implements DocumentResolver {

	private readonly string $resolvedTable;
	private readonly string $resolvedPrimaryKeyColumn;
	private readonly array|string $resolvedColumns;
	/** @var array<int,string> */
	private array $whereClauseByCount=[];
	private ?array $lastIdsInput=null;
	private ?array $lastIdsOutput=null;

	/**
	 * Captures the SQL lookup contract for later resolve calls.
	 *
	 * The mapper, when supplied, receives the raw SQL row and active index
	 * definition and can produce a custom document body without changing the key.
	 *
	 * @param readonly string $table SQL table name accepted by sql_select().
	 * @param readonly string $primaryKeyColumn Column used both for filtering and returned document keys.
	 * @param readonly list<string>|string $columns Column selection, either "*" or validated identifiers.
	 * @param readonly bool|array|string|null $caching Cache option forwarded to sql_select().
	 * @param readonly mixed $mapper Optional callable that transforms each selected row.
	 */
	public function __construct(
		private readonly string $table,
		private readonly string $primaryKeyColumn,
		private readonly array|string $columns='*',
		private readonly bool|array|string|null $caching=false,
		private readonly mixed $mapper=null
	){
		$this->resolvedTable=$this->assertIdentifier($this->table);
		$this->resolvedPrimaryKeyColumn=$this->assertIdentifier($this->primaryKeyColumn);
		$this->resolvedColumns=$this->normalizeColumns($this->columns);
	}

	/**
	 * Selects table rows for requested ids and returns indexable documents.
	 *
	 * Blank ids are discarded before query construction. Invalid table or column
	 * names throw InvalidArgumentException rather than being interpolated into the
	 * generated WHERE clause.
	 *
	 * @param list<string|int> $ids Document ids requested by the indexer.
	 * @param ?IndexDefinition $definition Active index definition supplied to the optional mapper.
	 * @return array<string,mixed> Documents keyed by the primary key column value.
	 */
	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		if($this->lastIdsInput===$ids && $this->lastIdsOutput!==null){
			$ids=$this->lastIdsOutput;
		}else{
			$input=$ids;
			$normalizedIds=[];
			foreach($ids as $id){
				$id=trim((string)$id);
				if($id!==''){
					$normalizedIds[]=$id;
				}
			}
			$this->lastIdsInput=$input;
			$this->lastIdsOutput=$normalizedIds;
			$ids=$normalizedIds;
		}
		if($ids===[]){
			return [];
		}

		$primaryKeyColumn=$this->resolvedPrimaryKeyColumn;
		$idCount=count($ids);
		$params=$this->whereClauseByCount[$idCount]
			??= 'WHERE '.$primaryKeyColumn.' IN ('.implode(', ', array_fill(0, $idCount, '?')).')';
		$rows=sql_select($this->resolvedColumns, $this->resolvedTable, $params, $ids, true, $this->caching);
		if(!is_array($rows)){
			return [];
		}

		$documents=[];
		if($this->mapper===null){
			foreach($rows as $row){
				if(!is_array($row) || !array_key_exists($primaryKeyColumn, $row)){
					continue;
				}
				$documents[(string)$row[$primaryKeyColumn]]=$row;
			}
		}else{
			foreach($rows as $row){
				if(!is_array($row) || !array_key_exists($primaryKeyColumn, $row)){
					continue;
				}
				$key=(string)$row[$primaryKeyColumn];
				$documents[$key]=$this->mapDocument($row, $definition);
			}
		}
		return $documents;
	}

	/**
	 * Applies the optional document mapper to a selected SQL row.
	 *
	 * @param array<string,mixed> $row Raw row returned by sql_select().
	 * @param ?IndexDefinition $definition Active index definition.
	 * @return mixed Raw SQL row when no mapper is configured, or the mapper-produced document body.
	 */
	private function mapDocument(array $row, ?IndexDefinition $definition): mixed {
		if($this->mapper===null){
			return $row;
		}
		return ($this->mapper)($row, $definition);
	}

	/**
	 * Normalizes and validates the column selection sent to sql_select().
	 *
	 * @param array|string $columns Either "*" or one or more SQL identifiers.
	 * @return array|string Deduplicated identifier list or the literal "*" selection.
	 */
	private function normalizeColumns(array|string $columns): array|string {
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			return $this->assertIdentifier($columns);
		}
		$normalized=[];
		foreach($columns as $column){
			$column=$this->assertIdentifier((string)$column);
			$normalized[$column]=$column;
		}
		return array_values($normalized);
	}

	/**
	 * Validates a table or column identifier used in generated SQL fragments.
	 *
	 * @param string $identifier Candidate identifier.
	 * @return string Trimmed identifier safe for sql_select fragments.
	 *
	 * @throws \InvalidArgumentException When the identifier is blank or contains unsupported characters.
	 */
	private function assertIdentifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw new \InvalidArgumentException("Invalid resolver SQL identifier: {$identifier}");
		}
		return $identifier;
	}
}
