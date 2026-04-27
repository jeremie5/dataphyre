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

final class TableDocumentResolver implements DocumentResolver {

	public function __construct(
		private readonly string $table,
		private readonly string $primary_key_column,
		private readonly array|string $columns='*',
		private readonly bool|array|string|null $caching=false,
		private readonly mixed $mapper=null
	){}

	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		$ids=array_values(array_filter(array_map(
			static fn(mixed $id): string => trim((string)$id),
			$ids
		), static fn(string $id): bool => $id!==''));
		if($ids===[]){
			return [];
		}

		$table=$this->assert_identifier($this->table);
		$primary_key_column=$this->assert_identifier($this->primary_key_column);
		$columns=$this->normalize_columns($this->columns);
		$params='WHERE '.$primary_key_column.' IN ('.implode(', ', array_fill(0, count($ids), '?')).')';
		$rows=sql_select($columns, $table, $params, $ids, true, $this->caching);
		if(!is_array($rows)){
			return [];
		}

		$documents=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($primary_key_column, $row)){
				continue;
			}
			$key=(string)$row[$primary_key_column];
			$documents[$key]=$this->map_document($row, $definition);
		}
		return $documents;
	}

	private function map_document(array $row, ?IndexDefinition $definition): mixed {
		if($this->mapper===null){
			return $row;
		}
		return ($this->mapper)($row, $definition);
	}

	private function normalize_columns(array|string $columns): array|string {
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			return $this->assert_identifier($columns);
		}
		$normalized=[];
		foreach($columns as $column){
			$normalized[]=$this->assert_identifier((string)$column);
		}
		return array_values(array_unique($normalized));
	}

	private function assert_identifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw new \InvalidArgumentException("Invalid resolver SQL identifier: {$identifier}");
		}
		return $identifier;
	}
}
