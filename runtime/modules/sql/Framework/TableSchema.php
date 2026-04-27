<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class TableSchema {

	private string $table;
	private array $columns;
	private array $projections;
	private ?string $primary_key;

	public function __construct(string $table, array $columns, array $projections=[], ?string $primary_key=null){
		$this->table=$this->assert_identifier($table);
		$this->columns=$this->normalize_identifiers($columns);
		$this->projections=$this->normalize_projections($projections);
		$this->primary_key=$primary_key!==null ? $this->assert_known_column($primary_key) : null;
	}

	public function table(): string {
		return $this->table;
	}

	public function primaryKey(): ?string {
		return $this->primary_key;
	}

	public function columns(array|string $columns='*'): array|string {
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			return $this->assert_known_column($columns);
		}
		$normalized=[];
		foreach($columns as $column){
			$normalized[]=$this->assert_known_column((string)$column);
		}
		return array_values(array_unique($normalized));
	}

	public function projection(string $name): array {
		$name=trim($name);
		if(!isset($this->projections[$name])){
			throw SqlError::unknownProjection($this->table, $name, array_keys($this->projections));
		}
		return $this->projections[$name];
	}

	public function fields(array $fields): array {
		if($fields===[]){
			throw SqlError::invalidFieldPayload("schema {$this->table}", 'Field payload cannot be empty.');
		}
		$normalized=[];
		foreach($fields as $column=>$value){
			if(is_int($column)){
				throw SqlError::invalidFieldPayload("schema {$this->table}", 'Field payload must be an associative array.');
			}
			$normalized[$this->assert_known_column((string)$column)]=$value;
		}
		return $normalized;
	}

	private function normalize_projections(array $projections): array {
		$normalized=[];
		foreach($projections as $name=>$columns){
			if(!is_array($columns)){
				continue;
			}
			$normalized[(string)$name]=array_values(array_unique(array_map(
				fn(string $column): string => $this->assert_known_column($column),
				$this->normalize_identifiers($columns)
			)));
		}
		return $normalized;
	}

	private function normalize_identifiers(array $identifiers): array {
		$normalized=[];
		foreach($identifiers as $identifier){
			$identifier=$this->assert_identifier((string)$identifier);
			$normalized[]=$identifier;
		}
		return array_values(array_unique($normalized));
	}

	private function assert_known_column(string $column): string {
		$column=$this->assert_identifier($column);
		if(!in_array($column, $this->columns, true)){
			throw SqlError::unknownColumn($this->table, $column, $this->columns);
		}
		return $column;
	}

	private function assert_identifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('schema', $identifier, $this->table);
		}
		return $identifier;
	}
}
