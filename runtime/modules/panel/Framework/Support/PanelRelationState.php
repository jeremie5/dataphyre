<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable snapshot of a relation manager table for nested panel resource views.
 *
 * The state bundles relation metadata, parent record metadata, table paging/sorting/filter state,
 * column definitions, record collections, view counts, facts, empty-state content, and arbitrary
 * metadata into a serializable payload for renderers and API responses.
 */
final class PanelRelationState implements \JsonSerializable {

	/**
	 * Creates a relation state snapshot from already-resolved payload pieces.
	 *
	 * @param array<string,mixed> $relation Relation manager definition and operation metadata.
	 * @param array<string,mixed> $parent Parent resource/record payload.
	 * @param PanelTableState $tableState Current table state.
	 * @param array<string,mixed> $columns Relation table columns keyed by column name.
	 * @param array<int,mixed> $allRecords Full related record set before filtering.
	 * @param array<int,mixed> $filteredRecords Related record set after filters/search/view selection.
	 * @param array<int,mixed> $pageRecords Related records for the current page.
	 * @param array<string,int> $viewCounts Counts by relation table view.
	 * @param array<int,array<string,mixed>> $facts Summary facts for the relation table.
	 * @param array{heading?:string,description?:string} $emptyState Empty-state payload when no rows are available.
	 * @param array<string,mixed> $meta Additional renderer/API metadata.
	 */
	public function __construct(
		private readonly array $relation=[],
		private readonly array $parent=[],
		private readonly PanelTableState $tableState=new PanelTableState(),
		private readonly array $columns=[],
		private readonly array $allRecords=[],
		private readonly array $filteredRecords=[],
		private readonly array $pageRecords=[],
		private readonly array $viewCounts=[],
		private readonly array $facts=[],
		private readonly array $emptyState=[],
		private readonly array $meta=[]
	){}

	/**
	 * Builds a relation state from a RelationManager and resolved table data.
	 *
	 * When a precomputed relation definition is supplied, it is used instead of calling toArray()
	 * on the relation manager.
	 *
	 * @param RelationManager $relation Relation manager that owns the nested table.
	 * @param array<string,mixed> $parent Parent resource/record payload.
	 * @param PanelTableState $tableState Current table state.
	 * @param array<string,mixed> $columns Relation table columns keyed by column name.
	 * @param array<int,mixed> $allRecords Full related record set before filtering.
	 * @param array<int,mixed> $filteredRecords Related record set after filters/search/view selection.
	 * @param array<int,mixed> $pageRecords Related records for the current page.
	 * @param array<string,int> $viewCounts Counts by relation table view.
	 * @param array<int,array<string,mixed>> $facts Summary facts for the relation table.
	 * @param array{heading?:string,description?:string} $emptyState Empty-state payload.
	 * @param array<string,mixed> $meta Additional renderer/API metadata.
	 * @param array<string,mixed> $relationDefinition Optional serialized relation manager definition.
	 * @return self Relation state snapshot.
	 */
	public static function make(
		RelationManager $relation,
		array $parent,
		PanelTableState $tableState,
		array $columns=[],
		array $allRecords=[],
		array $filteredRecords=[],
		array $pageRecords=[],
		array $viewCounts=[],
		array $facts=[],
		array $emptyState=[],
		array $meta=[],
		array $relationDefinition=[]
	): self {
		return new self($relationDefinition!==[] ? $relationDefinition : $relation->toArray(), $parent, $tableState, $columns, $allRecords, $filteredRecords, $pageRecords, $viewCounts, $facts, $emptyState, $meta);
	}

	/**
	 * Returns relation manager metadata.
	 *
	 * @return array<string,mixed> Serialized relation manager definition.
	 */
	public function relation(): array {
		return $this->relation;
	}

	/**
	 * Returns relation operation metadata.
	 *
	 * @return array<string,array> Operation definitions keyed by normalized operation name.
	 */
	public function operations(): array {
		$operations=$this->relation['operations'] ?? [];
		return is_array($operations) ? $operations : [];
	}

	/**
	 * Returns metadata for one relation operation.
	 *
	 * @param string $name Operation name to normalize and look up.
	 * @return array<string,mixed> Operation payload, or an empty array when missing.
	 */
	public function operation(string $name): array {
		$name=Resource::normalizeName($name);
		$operations=$this->operations();
		$entry=$operations[$name] ?? [];
		return is_array($entry) ? $entry : [];
	}

	/**
	 * Reports whether an operation is both enabled and authorized.
	 *
	 * @param string $name Operation name to inspect.
	 * @return bool True when the operation payload has enabled and authorized set to true.
	 */
	public function operationAvailable(string $name): bool {
		$operation=$this->operation($name);
		return ($operation['enabled'] ?? false)===true && ($operation['authorized'] ?? false)===true;
	}

	/**
	 * Returns the relation manager name.
	 *
	 * @return string Relation name, or an empty string when absent.
	 */
	public function relationName(): string {
		return (string)($this->relation['name'] ?? '');
	}

	/**
	 * Returns the human-readable relation label.
	 *
	 * @return string Relation label, falling back to relationName().
	 */
	public function relationLabel(): string {
		return (string)($this->relation['label'] ?? $this->relationName());
	}

	/**
	 * Returns parent resource and record data for relation rendering.
	 *
	 * @return array<string,mixed> Parent context for the relation table.
	 */
	public function parent(): array {
		return $this->parent;
	}

	/**
	 * Returns current table state.
	 *
	 * @return PanelTableState Paging, sorting, search, filter, view, and group state.
	 */
	public function tableState(): PanelTableState {
		return $this->tableState;
	}

	/**
	 * Returns relation table columns.
	 *
	 * @return array<string,mixed> Columns keyed by column name.
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Returns all related records before filtering.
	 *
	 * @return array<int,mixed> Complete related record collection.
	 */
	public function allRecords(): array {
		return $this->allRecords;
	}

	/**
	 * Returns related records after filters, search, and view selection.
	 *
	 * @return array<int,mixed> Filtered related record collection.
	 */
	public function filteredRecords(): array {
		return $this->filteredRecords;
	}

	/**
	 * Returns records visible on the current relation page.
	 *
	 * @return array<int,mixed> Current page record collection.
	 */
	public function pageRecords(): array {
		return $this->pageRecords;
	}

	/**
	 * Returns record counts by relation table view.
	 *
	 * @return array<string,int> Counts keyed by view name.
	 */
	public function viewCounts(): array {
		return $this->viewCounts;
	}

	/**
	 * Returns relation table summary facts.
	 *
	 * @return array<int,array<string,mixed>> Fact payloads for dashboard/table summaries.
	 */
	public function facts(): array {
		return $this->facts;
	}

	/**
	 * Returns empty-state content for the relation table.
	 *
	 * @return array{heading?:string,description?:string} Empty-state payload.
	 */
	public function emptyState(): array {
		return $this->emptyState;
	}

	/**
	 * Returns additional state metadata.
	 *
	 * @return array<string,mixed> Metadata for renderers, extensions, or API consumers.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Serializes a compact relation-state summary for JSON responses.
	 *
	 * @return array{relation:array<string,mixed>,parent:array<string,mixed>,table_state:array<string,mixed>,columns:array<int,string>,all_record_count:int,filtered_record_count:int,page_record_count:int,view_counts:array<string,int>,facts:array<int,array<string,mixed>>,empty_state:array{heading?:string,description?:string},meta:array<string,mixed>} Relation renderer state.
	 */
	public function jsonSerialize(): array {
		return [
			'relation'=>$this->relation,
			'parent'=>$this->parent,
			'table_state'=>$this->tableState->jsonSerialize(),
			'columns'=>array_keys($this->columns),
			'all_record_count'=>count($this->allRecords),
			'filtered_record_count'=>count($this->filteredRecords),
			'page_record_count'=>count($this->pageRecords),
			'view_counts'=>$this->viewCounts,
			'facts'=>$this->facts,
			'empty_state'=>$this->emptyState,
			'meta'=>$this->meta,
		];
	}
}
