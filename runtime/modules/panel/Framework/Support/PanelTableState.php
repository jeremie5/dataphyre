<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable snapshot of a Panel resource table after query, filtering, sorting, and column resolution.
 *
 * PanelTableState packages current page records, the full column registry,
 * visible columns, summary values, and table metadata into a JSON-safe value for
 * renderers, API responses, and diagnostics. Metadata carries pagination, view,
 * group, search, sort, and filter state without coupling this value object to the
 * table query implementation.
 */
final class PanelTableState implements \JsonSerializable {

	/**
	 * Stores the table records, column definitions, summaries, and request-derived metadata.
	 *
	 * The constructor trusts callers to pass already-resolved records and column
	 * maps. It does not query resources, authorize columns, or recalculate
	 * summaries.
	 *
	 * @param list<array<string, mixed>> $records Current page records.
	 * @param array<string, mixed> $allColumns All configured table columns keyed by column name.
	 * @param array<string, mixed> $visibleColumns Columns currently visible to the operator.
	 * @param array<string, mixed> $summaries Aggregate or footer summary values.
	 * @param array<string, mixed> $meta Pagination, filters, sorting, grouping, and view metadata.
	 */
	public function __construct(
		private readonly array $records=[],
		private readonly array $allColumns=[],
		private readonly array $visibleColumns=[],
		private readonly array $summaries=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates a table state snapshot from resolved table pieces.
	 *
	 * This factory mirrors the constructor for call sites that prefer named
	 * construction when assembling renderer state.
	 *
	 * @param list<array<string, mixed>> $records Current page records.
	 * @param array<string, mixed> $allColumns All configured table columns keyed by column name.
	 * @param array<string, mixed> $visibleColumns Columns currently visible to the operator.
	 * @param array<string, mixed> $summaries Aggregate or footer summary values.
	 * @param array<string, mixed> $meta Pagination, filters, sorting, grouping, and view metadata.
	 * @return self Immutable table state snapshot.
	 */
	public static function make(array $records=[], array $allColumns=[], array $visibleColumns=[], array $summaries=[], array $meta=[]): self {
		return new self($records, $allColumns, $visibleColumns, $summaries, $meta);
	}

	/**
	 * Returns the records available on the current table page.
	 *
	 * Records are intentionally retained in memory for callers that need them, even
	 * though jsonSerialize() omits full rows.
	 *
	 * @return list<array<string, mixed>> Current page records.
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * Returns every configured column, including hidden columns.
	 *
	 * @return array<string, mixed> Column definitions keyed by column name.
	 */
	public function allColumns(): array {
		return $this->allColumns;
	}

	/**
	 * Returns the columns currently rendered in the table.
	 *
	 * @return array<string, mixed> Visible column definitions keyed by column name.
	 */
	public function visibleColumns(): array {
		return $this->visibleColumns;
	}

	/**
	 * Returns aggregate values for the current table selection.
	 *
	 * @return array<string, mixed> Summary values keyed by summary name or column.
	 */
	public function summaries(): array {
		return $this->summaries;
	}

	/**
	 * Returns request-derived table state metadata.
	 *
	 * Metadata may include pagination, filters, sorting, saved view, grouping, and
	 * query text. Consumers should treat it as table state, not as an authorization
	 * decision.
	 *
	 * @return array<string, mixed> Pagination, filtering, sorting, view, and grouping metadata.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Returns the total number of records matching the table query.
	 *
	 * @return int total_records metadata, or the current page record count when absent.
	 */
	public function totalRecords(): int {
		return (int)($this->meta['total_records'] ?? count($this->records));
	}

	/**
	 * Returns the current one-based page number.
	 *
	 * @return int Page number clamped to at least one.
	 */
	public function page(): int {
		return max(1, (int)($this->meta['page'] ?? 1));
	}

	/**
	 * Returns the configured page size.
	 *
	 * @return int Per-page count clamped to at least one.
	 */
	public function perPage(): int {
		return max(1, (int)($this->meta['per_page'] ?? count($this->records) ?: 1));
	}

	/**
	 * Returns the active saved table view identifier.
	 *
	 * @return string Active view name, or an empty string when no view is selected.
	 */
	public function activeView(): string {
		return (string)($this->meta['active_view'] ?? '');
	}

	/**
	 * Returns the active table grouping identifier.
	 *
	 * @return string Active group name, or an empty string when ungrouped.
	 */
	public function activeGroup(): string {
		return (string)($this->meta['active_group'] ?? '');
	}

	/**
	 * Returns the current search query string.
	 *
	 * @return string Query text, or an empty string when no search is active.
	 */
	public function query(): string {
		return (string)($this->meta['query'] ?? '');
	}

	/**
	 * Returns current sort metadata.
	 *
	 * Malformed sort metadata falls back to an empty column with ascending
	 * direction so renderer controls have a stable default.
	 *
	 * @return array{column: string, direction: string}|array<string, mixed> Sort definition, defaulting to no column and asc direction.
	 */
	public function sort(): array {
		return is_array($this->meta['sort'] ?? null) ? $this->meta['sort'] : ['column'=>'', 'direction'=>'asc'];
	}

	/**
	 * Returns active filter values.
	 *
	 * @return array<string, mixed> Filter values keyed by filter name.
	 */
	public function filterValues(): array {
		return is_array($this->meta['filters'] ?? null) ? $this->meta['filters'] : [];
	}

	/**
	 * Returns the names of currently visible columns.
	 *
	 * @return list<string> Visible column keys in render order.
	 */
	public function visibleColumnNames(): array {
		return array_keys($this->visibleColumns);
	}

	/**
	 * Exports the compact table state used by Panel renderers and API clients.
	 *
	 * Full records are intentionally omitted from JSON serialization here; the
	 * state describes table controls, counts, visible columns, summaries, and
	 * metadata while record rows are rendered or transported by the owning surface.
	 *
	 * @return array<string, mixed> JSON-safe table state summary.
	 */
	public function jsonSerialize(): array {
		return [
			'record_count'=>count($this->records),
			'total_records'=>$this->totalRecords(),
			'page'=>$this->page(),
			'per_page'=>$this->perPage(),
			'query'=>$this->query(),
			'sort'=>$this->sort(),
			'active_view'=>$this->activeView(),
			'active_group'=>$this->activeGroup(),
			'filters'=>$this->filterValues(),
			'visible_columns'=>$this->visibleColumnNames(),
			'all_columns'=>array_keys($this->allColumns),
			'summaries'=>$this->summaries,
			'meta'=>$this->meta,
		];
	}
}
