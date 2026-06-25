<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable Panel resource table definition and runtime state resolver.
 *
 * ResourceTable describes columns, filters, saved views, summaries, grouping,
 * pagination, row attributes, row-click targets, preview fields, empty states, and
 * manifest metadata for a Panel resource listing. Builder methods clone the table;
 * resolver methods combine the definition with PanelRequest, Resource, records,
 * and preferences for rendering and manifest export.
 */
final class ResourceTable {

	/** @var array<string, Column> */
	private array $columns=[];
	private int $defaultPerPage=25;
	private array $perPageOptions=[10, 25, 50, 100];
	private ?array $defaultSort=null;
	/** @var array<string, TableView> */
	private array $views=[];
	/** @var array<string, TableFilter> */
	private array $filters=[];
	/** @var array<string, TableSummary> */
	private array $summaries=[];
	/** @var array<string, TableGroup> */
	private array $groups=[];
	/** @var array<int, array<string, mixed>|\Closure> */
	private array $rowAttributes=[];
	private bool $rowClickEnabled=false;
	private string $rowClickOperation='show';
	private bool $rowClickModal=true;
	private ?\Closure $rowClickResolver=null;
	private ?string $rowClickAction=null;
	private bool $rowPreviewAction=false;
	/** @var array<int|string, mixed> */
	private array $rowPreviewFields=[];
	private ?\Closure $rowPreviewResolver=null;
	private array $emptyState=[];
	private array $filteredEmptyState=[];
	private array $meta=[];

	/**
	 * Creates an empty immutable table definition.
	 *
	 * The returned builder starts with default pagination and no columns, filters,
	 * views, summaries, groups, row actions, preview fields, or custom metadata.
	 *
	 * @return self Fresh table builder.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Replaces the table column set.
	 *
	 * Entries can be Column instances, serialized column arrays, or string column
	 * names. The table is cloned before mutation and each entry is normalized through
	 * column().
	 *
	 * @param array<int, Column|array|string> $columns Column definitions in display order.
	 * @return self Cloned table with the supplied columns.
	 */
	public function columns(array $columns): self {
		$clone=clone $this;
		$clone->columns=[];
		foreach($columns as $column){
			$clone=$clone->column($column);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one table column by normalized column name.
	 *
	 * String input creates a Column with the supplied type or text by default. Array
	 * input is restored with Column::fromArray().
	 *
	 * @param Column|array|string $column Column object, serialized column, or column name.
	 * @param string|null $type Column type used for string input.
	 * @return self Cloned table containing the column.
	 */
	public function column(Column|array|string $column, ?string $type=null): self {
		$column=$column instanceof Column ? $column : (is_array($column) ? Column::fromArray($column) : Column::make((string)$column, $type ?? 'text'));
		$clone=clone $this;
		$clone->columns[$column->name()]=$column;
		return $clone;
	}

	/**
	 * Sets the default row count per page.
	 *
	 * Values are clamped between 1 and 250. The selected default is automatically
	 * inserted into the available per-page options if it is not already present.
	 *
	 * @param int $rows Desired default rows per page.
	 * @return self Cloned table with updated pagination default.
	 */
	public function perPage(int $rows): self {
		$clone=clone $this;
		$clone->defaultPerPage=max(1, min(250, $rows));
		if(!in_array($clone->defaultPerPage, $clone->perPageOptions, true)){
			$clone->perPageOptions[]=$clone->defaultPerPage;
			sort($clone->perPageOptions, SORT_NUMERIC);
		}
		return $clone;
	}

	/**
	 * Replaces the selectable per-page options.
	 *
	 * Options are integer-cast, clamped to 1..250, deduplicated, sorted, and guaranteed
	 * to contain the current default per-page value.
	 *
	 * @param array<int, mixed> $options Candidate per-page option values.
	 * @return self Cloned table with normalized per-page options.
	 */
	public function perPageOptions(array $options): self {
		$options=array_values(array_unique(array_filter(array_map(
			static fn(mixed $option): int => max(1, min(250, (int)$option)),
			$options
		), static fn(int $option): bool => $option>0)));
		sort($options, SORT_NUMERIC);
		$clone=clone $this;
		$clone->perPageOptions=$options!==[] ? $options : [$clone->defaultPerPage];
		if(!in_array($clone->defaultPerPage, $clone->perPageOptions, true)){
			$clone->perPageOptions[]=$clone->defaultPerPage;
			sort($clone->perPageOptions, SORT_NUMERIC);
		}
		return $clone;
	}

	/**
	 * Sets the default sort used when a request does not provide sorting.
	 *
	 * Column names are normalized with Resource::normalizeName(); blank names clear
	 * the default. Direction is normalized to asc or desc.
	 *
	 * @param string $column Column name to sort by.
	 * @param string $direction Sort direction, asc unless exactly desc.
	 * @return self Cloned table with the default sort definition.
	 */
	public function defaultSort(string $column, string $direction='asc'): self {
		$column=Resource::normalizeName($column);
		$direction=strtolower(trim($direction))==='desc' ? 'desc' : 'asc';
		$clone=clone $this;
		$clone->defaultSort=$column!=='' ? ['column'=>$column, 'direction'=>$direction] : null;
		return $clone;
	}

	/**
	 * Replaces the table filter set.
	 *
	 * Entries can be TableFilter instances, serialized arrays, or string names. The
	 * table is cloned and each entry is normalized through filter().
	 *
	 * @param array<int, TableFilter|array|string> $filters Filter definitions.
	 * @return self Cloned table with the supplied filters.
	 */
	public function filters(array $filters): self {
		$clone=clone $this;
		$clone->filters=[];
		foreach($filters as $filter){
			$clone=$clone->filter($filter);
		}
		return $clone;
	}

	/**
	 * Replaces the named saved-view set for the table.
	 *
	 * Views can carry query defaults and a default marker used when the request does
	 * not choose a view explicitly.
	 *
	 * @param array<int, TableView|array|string> $views View definitions.
	 * @return self Cloned table with the supplied views.
	 */
	public function views(array $views): self {
		$clone=clone $this;
		$clone->views=[];
		foreach($views as $view){
			$clone=$clone->view($view);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one saved table view.
	 *
	 * String input creates a TableView by name and array input is restored from its
	 * serialized form.
	 *
	 * @param TableView|array|string $view View object, serialized view, or view name.
	 * @return self Cloned table containing the view.
	 */
	public function view(TableView|array|string $view): self {
		$view=$view instanceof TableView
			? $view
			: (is_array($view) ? TableView::fromArray($view) : TableView::make((string)$view));
		$clone=clone $this;
		$clone->views[$view->name()]=$view;
		return $clone;
	}

	/**
	 * Adds or replaces one table filter by normalized filter name.
	 *
	 * String input creates a TableFilter with the supplied type or text by default.
	 * Array input is restored with TableFilter::fromArray().
	 *
	 * @param TableFilter|array|string $filter Filter object, serialized filter, or filter name.
	 * @param string|null $type Filter type used for string input.
	 * @return self Cloned table containing the filter.
	 */
	public function filter(TableFilter|array|string $filter, ?string $type=null): self {
		$filter=$filter instanceof TableFilter
			? $filter
			: (is_array($filter) ? TableFilter::fromArray($filter) : TableFilter::make((string)$filter, $type ?? 'text'));
		$clone=clone $this;
		$clone->filters[$filter->name()]=$filter;
		return $clone;
	}

	/**
	 * Replaces aggregate summary definitions displayed for the table.
	 *
	 * Each entry is normalized through summary() so string, array, and TableSummary
	 * definitions share the same storage path.
	 *
	 * @param array<int, TableSummary|array|string> $summaries Summary definitions.
	 * @return self Cloned table with the supplied summaries.
	 */
	public function summaries(array $summaries): self {
		$clone=clone $this;
		$clone->summaries=[];
		foreach($summaries as $summary){
			$clone=$clone->summary($summary);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one aggregate summary definition.
	 *
	 * String input creates a summary with the supplied type or count by default.
	 *
	 * @param TableSummary|array|string $summary Summary object, serialized summary, or summary name.
	 * @param string|null $type Summary type used for string input.
	 * @return self Cloned table containing the summary.
	 */
	public function summary(TableSummary|array|string $summary, ?string $type=null): self {
		$summary=$summary instanceof TableSummary
			? $summary
			: (is_array($summary) ? TableSummary::fromArray($summary) : TableSummary::make((string)$summary, $type ?? 'count'));
		$clone=clone $this;
		$clone->summaries[$summary->name()]=$summary;
		return $clone;
	}

	/**
	 * Replaces the table grouping definitions.
	 *
	 * Groups are used to choose an active grouping from request state or a configured
	 * default. Empty group names are ignored by group().
	 *
	 * @param array<int, TableGroup|array|string> $groups Group definitions.
	 * @return self Cloned table with the supplied groups.
	 */
	public function groups(array $groups): self {
		$clone=clone $this;
		$clone->groups=[];
		foreach($groups as $group){
			$clone=$clone->group($group);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one grouping definition.
	 *
	 * String input creates a TableGroup by name and array input is restored from its
	 * serialized form. Blank group names are ignored.
	 *
	 * @param TableGroup|array|string $group Group object, serialized group, or group name.
	 * @return self Cloned table containing the group when valid.
	 */
	public function group(TableGroup|array|string $group): self {
		$group=$group instanceof TableGroup
			? $group
			: (is_array($group) ? TableGroup::fromArray($group) : TableGroup::make((string)$group));
		$clone=clone $this;
		if($group->name()!==''){
			$clone->groups[$group->name()]=$group;
		}
		return $clone;
	}

	/**
	 * Adds static or dynamic HTML attributes for rendered table rows.
	 *
	 * Static attributes are normalized through an allow-list. Callable attributes are
	 * evaluated later with record, request, resource, and table context. Passing merge
	 * false replaces previously configured row-attribute sources.
	 *
	 * @param array|callable $attributes Static attributes or resolver callback.
	 * @param bool $merge Whether to append to existing attribute sources.
	 * @return self Cloned table with updated row attributes.
	 */
	public function rowAttributes(array|callable $attributes, bool $merge=true): self {
		$clone=clone $this;
		if(!$merge){
			$clone->rowAttributes=[];
		}
		$clone->rowAttributes[]=is_array($attributes) ? self::normalizeExtraAttributes($attributes) : \Closure::fromCallable($attributes);
		return $clone;
	}

	/**
	 * Alias for rowAttributes() using record-oriented naming.
	 *
	 * @param array|callable $attributes Static attributes or resolver callback.
	 * @param bool $merge Whether to append to existing attribute sources.
	 * @return self Cloned table with updated row attributes.
	 */
	public function recordAttributes(array|callable $attributes, bool $merge=true): self {
		return $this->rowAttributes($attributes, $merge);
	}

	/**
	 * Adds one static row HTML attribute.
	 *
	 * Attribute names are normalized and filtered by the row-attribute allow-list.
	 *
	 * @param string $name Attribute name.
	 * @param mixed $value Attribute value, true for boolean attributes.
	 * @return self Cloned table with the attribute appended.
	 */
	public function rowAttribute(string $name, mixed $value=true): self {
		return $this->rowAttributes([$name=>$value]);
	}

	/**
	 * Adds one data-* attribute to each rendered row.
	 *
	 * The provided segment is normalized to a safe data attribute suffix.
	 *
	 * @param string $name data-* suffix without the data- prefix.
	 * @param mixed $value Attribute value.
	 * @return self Cloned table with the data attribute appended.
	 */
	public function rowData(string $name, mixed $value=true): self {
		return $this->rowAttribute('data-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Adds one aria-* attribute to each rendered row.
	 *
	 * aria-label is intentionally blocked by the row-attribute allow-list to prevent
	 * generic table configuration from overwriting row labels unexpectedly.
	 *
	 * @param string $name aria-* suffix without the aria- prefix.
	 * @param mixed $value Attribute value.
	 * @return self Cloned table with the ARIA attribute appended.
	 */
	public function rowAria(string $name, mixed $value=true): self {
		return $this->rowAttribute('aria-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Configures row-click navigation for records in this table.
	 *
	 * False disables row clicks, a string selects a resource operation, and a callable
	 * resolves a custom URL at runtime. Authorization is checked later by
	 * resolveRowClick().
	 *
	 * @param bool|string|callable $target Disabled flag, operation name, or URL resolver.
	 * @param bool $modal Whether the client should open the target in a modal.
	 * @return self Cloned table with row-click behavior configured.
	 */
	public function rowClick(bool|string|callable $target=true, bool $modal=true): self {
		$clone=clone $this;
		$clone->rowClickEnabled=$target!==false;
		$clone->rowClickModal=$modal;
		$clone->rowClickResolver=null;
		$clone->rowClickAction=null;
		if(is_callable($target) && !is_string($target)){
			$clone->rowClickResolver=\Closure::fromCallable($target);
			$clone->rowClickOperation='show';
		}
		elseif(is_string($target)){
			$target=trim($target);
			$clone->rowClickOperation=$target!=='' ? Resource::normalizeName($target) : 'show';
		}
		else {
			$clone->rowClickOperation='show';
		}
		return $clone;
	}

	/**
	 * Alias for rowClick() kept for readable table definitions.
	 *
	 * @param bool|string|callable $target Disabled flag, operation name, or URL resolver.
	 * @param bool $modal Whether the client should open the target in a modal.
	 * @return self Cloned table with row-click behavior configured.
	 */
	public function clickableRows(bool|string|callable $target=true, bool $modal=true): self {
		return $this->rowClick($target, $modal);
	}

	/**
	 * Configures row clicks to open a standard resource operation.
	 *
	 * Common operations are show, edit, update, and delete; authorization is mapped to
	 * view, update, or delete during resolveRowClick().
	 *
	 * @param string $operation Resource operation opened by row click.
	 * @param bool $modal Whether the client should open the operation in a modal.
	 * @return self Cloned table with operation row clicks configured.
	 */
	public function rowAction(string $operation='show', bool $modal=true): self {
		return $this->rowClick($operation, $modal);
	}

	/**
	 * Configures row clicks to open a named record action.
	 *
	 * The action must exist, be visible, not be bulk-only, pass authorization, and not
	 * be disabled for the current record before resolveRowClick() emits a target.
	 *
	 * @param string $actionName Named resource action.
	 * @param bool $modal Whether the client should open the action in a modal.
	 * @return self Cloned table with action row clicks configured.
	 */
	public function recordAction(string $actionName, bool $modal=true): self {
		$actionName=Resource::normalizeName($actionName);
		$clone=clone $this;
		$clone->rowClickEnabled=$actionName!=='';
		$clone->rowClickModal=$modal;
		$clone->rowClickResolver=null;
		$clone->rowClickOperation='action';
		$clone->rowClickAction=$actionName!=='' ? $actionName : null;
		return $clone;
	}

	/**
	 * Configures row clicks with a custom URL resolver.
	 *
	 * The resolver receives record, request, resource, table, and operation context
	 * when resolveRowClick() runs.
	 *
	 * @param callable $resolver Runtime URL resolver.
	 * @param bool $modal Whether the client should open the URL in a modal.
	 * @return self Cloned table with URL row clicks configured.
	 */
	public function rowUrl(callable $resolver, bool $modal=true): self {
		return $this->rowClick($resolver, $modal);
	}

	/**
	 * Enables or disables the row preview action affordance.
	 *
	 * Preview fields are configured separately with previewFields().
	 *
	 * @param bool $enabled Whether preview actions should be exposed.
	 * @return self Cloned table with preview action state updated.
	 */
	public function previewable(bool $enabled=true): self {
		$clone=clone $this;
		$clone->rowPreviewAction=$enabled;
		return $clone;
	}

	/**
	 * Alias for previewable() using row-oriented naming.
	 *
	 * @param bool $enabled Whether preview actions should be exposed.
	 * @return self Cloned table with preview action state updated.
	 */
	public function rowPreview(bool $enabled=true): self {
		return $this->previewable($enabled);
	}

	/**
	 * Alias for previewable() using action-oriented naming.
	 *
	 * @param bool $enabled Whether preview actions should be exposed.
	 * @return self Cloned table with preview action state updated.
	 */
	public function previewAction(bool $enabled=true): self {
		return $this->previewable($enabled);
	}

	/**
	 * Configures fields shown in the row preview panel.
	 *
	 * Static definitions are serialized into manifests. Callable definitions are
	 * evaluated at runtime with record, request, resource, and table context.
	 *
	 * @param array|callable $fields Static preview fields or runtime field resolver.
	 * @param bool $showAction Whether preview actions should be exposed.
	 * @return self Cloned table with preview field configuration.
	 */
	public function previewFields(array|callable $fields, bool $showAction=true): self {
		$clone=clone $this;
		$clone->rowPreviewAction=$showAction;
		if(is_callable($fields) && !is_array($fields)){
			$clone->rowPreviewResolver=\Closure::fromCallable($fields);
			$clone->rowPreviewFields=[];
		}
		else {
			$clone->rowPreviewFields=$fields;
			$clone->rowPreviewResolver=null;
		}
		return $clone;
	}

	/**
	 * Sets the empty state for tables with no records and no active constraints.
	 *
	 * Heading may be a static string, serialized state array, or resolver callback.
	 * Optional action fields provide the primary empty-state call to action.
	 *
	 * @param string|array|callable $heading Heading, serialized state, or resolver.
	 * @param string|null $description Optional supporting text.
	 * @param string|null $actionLabel Optional action label.
	 * @param string|callable|null $actionUrl Optional static or resolved action URL.
	 * @param string|null $icon Optional icon name.
	 * @return self Cloned table with empty-state configuration.
	 */
	public function emptyState(string|array|callable $heading, ?string $description=null, ?string $actionLabel=null, string|callable|null $actionUrl=null, ?string $icon=null): self {
		$clone=clone $this;
		$clone->emptyState=self::normalizeEmptyState($heading, $description, $actionLabel, $actionUrl, $icon);
		return $clone;
	}

	/**
	 * Sets the empty state for constrained searches or filtered views.
	 *
	 * This state is preferred by resolveEmptyState() when filters, search, grouping,
	 * or views constrain the current table request.
	 *
	 * @param string|array|callable $heading Heading, serialized state, or resolver.
	 * @param string|null $description Optional supporting text.
	 * @param string|null $actionLabel Optional action label.
	 * @param string|callable|null $actionUrl Optional static or resolved action URL.
	 * @param string|null $icon Optional icon name.
	 * @return self Cloned table with filtered empty-state configuration.
	 */
	public function filteredEmptyState(string|array|callable $heading, ?string $description=null, ?string $actionLabel=null, string|callable|null $actionUrl=null, ?string $icon=null): self {
		$clone=clone $this;
		$clone->filteredEmptyState=self::normalizeEmptyState($heading, $description, $actionLabel, $actionUrl, $icon);
		return $clone;
	}

	/**
	 * Adds or replaces the default empty-state action.
	 *
	 * Callable URLs are stored for runtime resolution and omitted from static
	 * serialization except for a dynamic marker.
	 *
	 * @param string $label Action label.
	 * @param string|callable $url Static URL or runtime URL resolver.
	 * @return self Cloned table with empty-state action updated.
	 */
	public function emptyStateAction(string $label, string|callable $url): self {
		$clone=clone $this;
		$clone->emptyState['action_label']=trim($label);
		$clone->emptyState['action_url']=is_callable($url) && !is_string($url) ? \Closure::fromCallable($url) : $url;
		return $clone;
	}

	/**
	 * Adds or replaces the filtered empty-state action.
	 *
	 * @param string $label Action label.
	 * @param string|callable $url Static URL or runtime URL resolver.
	 * @return self Cloned table with filtered empty-state action updated.
	 */
	public function filteredEmptyStateAction(string $label, string|callable $url): self {
		$clone=clone $this;
		$clone->filteredEmptyState['action_label']=trim($label);
		$clone->filteredEmptyState['action_url']=is_callable($url) && !is_string($url) ? \Closure::fromCallable($url) : $url;
		return $clone;
	}

	/**
	 * Merges arbitrary table metadata into the manifest payload.
	 *
	 * Metadata is not interpreted by ResourceTable; it is carried for renderers,
	 * table manifests, or client-side extensions.
	 *
	 * @param array<string, mixed> $meta Metadata to merge over existing values.
	 * @return self Cloned table with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Returns configured Column objects keyed by column name.
	 *
	 * @return array<string, Column> Table columns in configured order.
	 */
	public function columnsList(): array {
		return $this->columns;
	}

	/**
	 * Returns table columns, falling back to resource columns when none are configured.
	 *
	 * Resource fallback allows concise resource definitions where the table inherits
	 * the resource's base field columns.
	 *
	 * @param Resource|null $resource Resource supplying fallback columns.
	 * @return array<string, Column> Effective columns for the table.
	 */
	public function columnsFor(?Resource $resource=null): array {
		if($this->columns!==[]){
			return $this->columns;
		}
		if(!$resource instanceof Resource){
			return [];
		}
		$columns=[];
		foreach($resource->form()->fieldsList() as $field){
			$columns[$field->name()]=Column::make($field->name());
		}
		return $columns;
	}

	/**
	 * Resolves the columns visible for a request and optional user preferences.
	 *
	 * Requested visible_columns values are normalized and intersected with effective
	 * columns. If no requested columns are valid, every effective column is returned.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param Resource|null $resource Resource supplying fallback columns.
	 * @param array<string,mixed> $preferences Persisted table preferences.
	 * @return array<string, Column> Visible columns keyed by name.
	 */
	public function visibleColumnsFor(PanelRequest $request, ?Resource $resource=null, array $preferences=[]): array {
		$columns=$this->columnsFor($resource);
		$requested=self::requestedColumns($request, $preferences);
		$available=[];
		$visible=[];
		foreach($columns as $name=>$column){
			if(!$column instanceof Column){
				continue;
			}
			if(!$column->isVisible($request->operation(), null, $request, $resource, $this)){
				continue;
			}
			$available[$name]=$column;
			$meta=$column->toArray();
			if(($meta['toggleable'] ?? true)===false){
				$visible[$name]=$column;
				continue;
			}
			if($requested!==[] && in_array($column->name(), $requested, true)){
				$visible[$name]=$column;
				continue;
			}
			if($requested===[] && ($meta['visible_by_default'] ?? true)===true){
				$visible[$name]=$column;
			}
		}
		return $visible!==[] ? $visible : $available;
	}

	/**
	 * Builds runtime table state for records, request controls, and active metadata.
	 *
	 * The state object combines records, pagination, active view/group/sort values,
	 * visible columns, filters, summaries, row previews, row-click configuration, and
	 * empty-state metadata for renderers.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param list<mixed> $records Records already loaded for display.
	 * @param Resource|null $resource Resource owning the table.
	 * @param bool $alreadyPaginated Whether records are already paginated upstream.
	 * @param array<string,mixed> $preferences Persisted table preferences.
	 * @return PanelTableState Runtime table state object.
	 */
	public function state(
		PanelRequest $request,
		array $records=[],
		?Resource $resource=null,
		bool $alreadyPaginated=false,
		array $preferences=[]
	): PanelTableState {
		$request=$resource instanceof Resource ? $resource->requestWithResolvedView($request) : $this->requestWithResolvedView($request);
		$activeView=$resource instanceof Resource ? $resource->activeTableViewName($request) : $this->activeViewName($request);
		$allColumns=$this->columnsFor($resource);
		$visibleColumns=$this->visibleColumnsFor($request, $resource, $preferences);
		$sort=self::sortState($request, $this);
		$filterValues=[];
		foreach($this->filters as $filter){
			if($filter instanceof TableFilter){
				$value=$filter->activeValue($request);
				if($value!==null){
					$filterValues[$filter->name()]=$value;
				}
			}
		}
		$summaries=[];
		foreach($this->summaries as $summary){
			if($summary instanceof TableSummary){
				$summaries[]=$summary->resolve($records, $resource, $request);
			}
		}
		return PanelTableState::make($records, $allColumns, $visibleColumns, $summaries, [
			'mode'=>'table',
			'query'=>trim((string)$request->query('q', '')),
			'filters'=>$filterValues,
			'sort'=>$sort,
			'active_group'=>$this->activeGroupName($request),
			'active_view'=>$activeView,
			'page'=>$request->page(),
			'per_page'=>$request->perPage($this->defaultPerPage),
			'total_records'=>count($records),
			'already_paginated'=>$alreadyPaginated,
		]);
	}

	/**
	 * Resolves the empty-state payload for the current request context.
	 *
	 * Filtered empty state is preferred when constraints are active. Resolver
	 * callbacks receive request, resource, table, and hasConstraints context.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param bool $hasConstraints Whether the empty table is constrained by filters/search/view/group.
	 * @param Resource|null $resource Resource owning the table.
	 * @return array<string, mixed> Resolved empty-state payload.
	 */
	public function resolveEmptyState(PanelRequest $request, bool $hasConstraints=false, ?Resource $resource=null): array {
		$state=$hasConstraints ? $this->filteredEmptyState : $this->emptyState;
		if(isset($state['resolver']) && $state['resolver'] instanceof \Closure){
			$result=PanelUtilityResolver::evaluate($state['resolver'], [
				'request'=>$request,
				'resource'=>$resource,
				'table'=>$this,
				'has_constraints'=>$hasConstraints,
			], ['request', 'resource', 'table', 'has_constraints']);
			if(is_array($result)){
				$state=array_replace($state, $result);
			}
			elseif(is_scalar($result) || $result instanceof \Stringable){
				$state['heading']=(string)$result;
			}
		}
		$url=$state['action_url'] ?? null;
		if($url instanceof \Closure){
			$url=PanelUtilityResolver::evaluate($url, [
				'request'=>$request,
				'resource'=>$resource,
				'table'=>$this,
				'has_constraints'=>$hasConstraints,
			], ['request', 'resource', 'table', 'has_constraints']);
		}
		return [
			'heading'=>trim((string)($state['heading'] ?? '')),
			'description'=>trim((string)($state['description'] ?? '')),
			'icon'=>trim((string)($state['icon'] ?? '')),
			'action_label'=>trim((string)($state['action_label'] ?? '')),
			'action_url'=>is_scalar($url) || $url instanceof \Stringable ? trim((string)$url) : '',
		];
	}

	/**
	 * Returns configured table filters keyed by filter name.
	 *
	 * @return array<string, TableFilter> Filter definitions.
	 */
	public function filtersList(): array {
		return $this->filters;
	}

	/**
	 * Returns configured saved table views keyed by view name.
	 *
	 * @return array<string, TableView> View definitions.
	 */
	public function viewsList(): array {
		return $this->views;
	}

	/**
	 * Resolves the active view name from request query and configured defaults.
	 *
	 * Query value all disables saved-view defaults. Unknown requested views fall back
	 * to the first configured default view, or an empty string when none applies.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Active view name, or empty string for all/no view.
	 */
	public function activeViewName(PanelRequest $request): string {
		if($this->views===[]){
			return '';
		}
		$requested=Resource::normalizeName((string)$request->query('view', ''));
		if($requested==='all'){
			return '';
		}
		if($requested!=='' && isset($this->views[$requested])){
			return $requested;
		}
		foreach($this->views as $view){
			if($view instanceof TableView && ($view->toArray()['default'] ?? false)===true){
				return $view->name();
			}
		}
		return '';
	}

	/**
	 * Returns a request with the active view and its query defaults applied.
	 *
	 * Existing non-empty query values are preserved over view defaults.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return PanelRequest Request carrying resolved view query state.
	 */
	public function requestWithResolvedView(PanelRequest $request): PanelRequest {
		if($this->views===[]){
			return $request;
		}
		$requested=Resource::normalizeName((string)$request->query('view', ''));
		if($requested==='all'){
			return $request->withQueryValue('view', 'all');
		}
		if($requested!=='' && isset($this->views[$requested])){
			return $this->requestWithViewDefaults($request->withQueryValue('view', $requested), $requested);
		}
		$active=$this->activeViewName($request);
		return $active!=='' ? $this->requestWithViewDefaults($request->withQueryValue('view', $active), $active) : $request;
	}

	/**
	 * Applies query defaults from one saved view to a request.
	 *
	 * Existing non-empty query values win over defaults, preserving user-selected
	 * filters, search, sort, and pagination while filling only missing view state.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param string $viewName Normalized saved-view name.
	 * @return PanelRequest Request with view defaults merged.
	 */
	private function requestWithViewDefaults(PanelRequest $request, string $viewName): PanelRequest {
		$view=$this->views[$viewName] ?? null;
		if(!$view instanceof TableView){
			return $request;
		}
		$query=$request->query();
		foreach($view->queryDefaults() as $key=>$value){
			if(array_key_exists($key, $query) && (is_array($query[$key]) ? $query[$key]!==[] : (string)$query[$key]!=='')){
				continue;
			}
			$query[$key]=$value;
		}
		return $request->withQuery($query, true);
	}

	/**
	 * Returns configured table summaries keyed by summary name.
	 *
	 * @return array<string, TableSummary> Summary definitions.
	 */
	public function summariesList(): array {
		return $this->summaries;
	}

	/**
	 * Returns configured table groups keyed by group name.
	 *
	 * @return array<string, TableGroup> Group definitions.
	 */
	public function groupsList(): array {
		return $this->groups;
	}

	/**
	 * Resolves static and dynamic HTML attributes for one row.
	 *
	 * Dynamic callbacks are evaluated with record, request, resource, and table
	 * context. Later attribute sources replace earlier keys after allow-list filtering.
	 *
	 * @param mixed $record Row record being rendered.
	 * @param PanelRequest|null $request Current panel request.
	 * @param Resource|null $resource Resource owning the record.
	 * @return array<string, mixed> Safe row attributes.
	 */
	public function resolveRowAttributes(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): array {
		$attributes=[];
		foreach($this->rowAttributes as $set){
			$resolved=$set instanceof \Closure
				? PanelUtilityResolver::evaluate($set, [
					'record'=>$record,
					'request'=>$request,
					'resource'=>$resource,
					'table'=>$this,
				], ['record', 'request', 'resource', 'table'])
				: $set;
			if(is_array($resolved)){
				$attributes=array_replace($attributes, self::normalizeExtraAttributes($resolved));
			}
		}
		return $attributes;
	}

	/**
	 * Resolves row-click target metadata for one record.
	 *
	 * The resolver enforces resource/action visibility, authorization, disabled state,
	 * record keys, and operation ability mapping before emitting a URL target.
	 *
	 * @param mixed $record Row record being rendered.
	 * @param PanelRequest|null $request Current panel request.
	 * @param Resource|null $resource Resource owning the record.
	 * @return array<string, mixed> Row-click target payload, or empty when unavailable.
	 */
	public function resolveRowClick(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): array {
		if(!$this->rowClickEnabled || !$resource instanceof Resource || $record===null){
			return [];
		}
		$operation=$this->rowClickOperation ?: 'show';
		if($this->rowClickAction!==null){
			$action=$resource->actionByName($this->rowClickAction);
			$actionMeta=$action instanceof Action ? $action->toArray() : [];
			if(
				!$action instanceof Action
				|| ($actionMeta['bulk'] ?? false)===true
				|| !$action->isVisible($record, $request?->user(), $resource, $request)
				|| $action->can($record, $request?->user(), $resource)===false
				|| $action->isDisabled($record, $request?->user(), $resource, $request)
			){
				return [];
			}
			$key=$resource->recordKey($record);
			if($key===''){
				return [];
			}
			return [
				'url'=>PanelConfig::resourceUrl($resource, 'action/'.$this->rowClickAction.'/'.rawurlencode($key)),
				'operation'=>'action',
				'target'=>'action',
				'action'=>$this->rowClickAction,
				'modal'=>$this->rowClickModal,
			];
		}
		$url='';
		if($this->rowClickResolver!==null){
			$value=PanelUtilityResolver::evaluate($this->rowClickResolver, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'table'=>$this,
				'operation'=>$operation,
			], ['record', 'request', 'resource', 'table', 'operation']);
			if(is_scalar($value) || $value instanceof \Stringable){
				$url=trim((string)$value);
			}
		}
		if($url===''){
			$url=$resource->recordUrl($record, $operation);
		}
		if($url===''){
			return [];
		}
		$ability=match($operation){
			'edit', 'update'=>'update',
			'delete'=>'delete',
			default=>'view',
		};
		if($resource->can($ability, $record, $request?->user())===false){
			return [];
		}
		return [
			'url'=>$url,
			'operation'=>$operation,
			'target'=>$this->rowClickResolver!==null ? 'url' : 'operation',
			'modal'=>$this->rowClickModal,
		];
	}

	/**
	 * Returns whether the table exposes row preview actions.
	 *
	 * @return bool True when preview actions are enabled.
	 */
	public function previewActionEnabled(): bool {
		return $this->rowPreviewAction;
	}

	/**
	 * Resolves preview fields for one record.
	 *
	 * Dynamic field resolvers receive record, request, resource, and table context.
	 * Static fields are normalized into label/value pairs and non-displayable entries
	 * are dropped.
	 *
	 * @param mixed $record Row record being previewed.
	 * @param PanelRequest|null $request Current panel request.
	 * @param Resource|null $resource Resource owning the record.
	 * @return array<int, array{label:string, value:string}> Resolved preview fields.
	 */
	public function resolveRowPreviewFields(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): array {
		$definitions=$this->rowPreviewResolver instanceof \Closure
			? PanelUtilityResolver::evaluate($this->rowPreviewResolver, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'table'=>$this,
			], ['record', 'request', 'resource', 'table'])
			: $this->rowPreviewFields;
		if(!is_array($definitions) || $definitions===[]){
			return [];
		}
		$fields=[];
		foreach($definitions as $key=>$definition){
			$field=self::resolvePreviewField($key, $definition, $record, $request, $resource, $this);
			if($field!==null){
				$fields[]=$field;
			}
		}
		return $fields;
	}

	/**
	 * Resolves the active group name from request query and configured defaults.
	 *
	 * Query value none disables grouping. Unknown requested groups fall back to the
	 * first configured default group, or an empty string when none applies.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Active group name, or empty string for no group.
	 */
	public function activeGroupName(PanelRequest $request): string {
		if($this->groups===[]){
			return '';
		}
		$requested=Resource::normalizeName((string)$request->query('group', ''));
		if($requested==='none'){
			return '';
		}
		if($requested!=='' && isset($this->groups[$requested])){
			return $requested;
		}
		foreach($this->groups as $group){
			if(($group->toArray()['default'] ?? false)===true){
				return $group->name();
			}
		}
		return '';
	}

	/**
	 * Returns the normalized default rows-per-page value.
	 *
	 * @return int Default rows per page.
	 */
	public function defaultPerPage(): int {
		return $this->defaultPerPage;
	}

	/**
	 * Returns normalized selectable per-page options.
	 *
	 * @return array<int, int> Per-page option values.
	 */
	public function perPageOptionsList(): array {
		return $this->perPageOptions;
	}

	/**
	 * Returns the configured default sort definition.
	 *
	 * @return array{column:string, direction:string}|null Default sort or null.
	 */
	public function defaultSortDefinition(): ?array {
		return $this->defaultSort;
	}

	/**
	 * Builds the serialized table manifest for renderers and clients.
	 *
	 * TableManifest combines table configuration with optional resource/request context
	 * and additional metadata supplied by the caller.
	 *
	 * @param Resource|null $resource Resource owning the table.
	 * @param PanelRequest|null $request Current panel request.
	 * @param array<string, mixed> $meta Additional manifest metadata.
	 * @return array<string, mixed> Serialized table manifest.
	 */
	public function manifest(?Resource $resource=null, ?PanelRequest $request=null, array $meta=[]): array {
		return TableManifest::from($this, $resource, $request, $meta)->toArray();
	}

	/**
	 * Serializes the table definition without request-specific runtime state.
	 *
	 * Dynamic closures are represented by flags so clients and tooling can distinguish
	 * static manifest values from runtime-resolved behavior.
	 *
	 * @return array<string, mixed> Static table definition payload.
	 */
	public function toArray(): array {
		return [
			'default_per_page'=>$this->defaultPerPage,
			'per_page_options'=>$this->perPageOptions,
			'default_sort'=>$this->defaultSort,
			'views'=>array_map(static fn(TableView $view): array => $view->toArray(), array_values($this->views)),
			'columns'=>array_map(static fn(Column $column): array => $column->toArray(), array_values($this->columns)),
			'filters'=>array_map(static fn(TableFilter $filter): array => $filter->toArray(), array_values($this->filters)),
			'summaries'=>array_map(static fn(TableSummary $summary): array => $summary->toArray(), array_values($this->summaries)),
			'groups'=>array_map(static fn(TableGroup $group): array => $group->toArray(), array_values($this->groups)),
			'row_attributes'=>$this->staticExtraAttributes(),
			'row_attributes_dynamic'=>$this->hasDynamicExtraAttributes(),
			'row_click'=>[
				'enabled'=>$this->rowClickEnabled,
				'operation'=>$this->rowClickOperation,
				'target'=>$this->rowClickAction!==null ? 'action' : ($this->rowClickResolver!==null ? 'url' : 'operation'),
				'modal'=>$this->rowClickModal,
				'dynamic_url'=>$this->rowClickResolver!==null,
				'action'=>$this->rowClickAction,
			],
			'row_preview'=>[
				'action'=>$this->rowPreviewAction,
				'fields'=>$this->staticPreviewFields(),
				'dynamic'=>$this->rowPreviewResolver!==null,
			],
			'empty_state'=>$this->serializableEmptyState($this->emptyState),
			'filtered_empty_state'=>$this->serializableEmptyState($this->filteredEmptyState),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes empty-state input into the table empty-state contract.
	 *
	 * Callable headings become runtime resolvers. Array headings are treated as
	 * serialized state, and scalar headings are expanded with optional description,
	 * action, URL, and icon fields.
	 *
	 * @param string|array<string,mixed>|callable $heading Heading, serialized state, or resolver.
	 * @param ?string $description Optional supporting text.
	 * @param ?string $actionLabel Optional action label.
	 * @param string|callable|null $actionUrl Static or resolved action URL.
	 * @param ?string $icon Optional icon token.
	 * @return array<string,mixed> Normalized empty-state definition.
	 */
	private static function normalizeEmptyState(string|array|callable $heading, ?string $description=null, ?string $actionLabel=null, string|callable|null $actionUrl=null, ?string $icon=null): array {
		if(is_callable($heading) && !is_string($heading)){
			return ['resolver'=>\Closure::fromCallable($heading)];
		}
		$state=is_array($heading) ? $heading : ['heading'=>$heading];
		if($description!==null){
			$state['description']=$description;
		}
		if($actionLabel!==null){
			$state['action_label']=$actionLabel;
		}
		if($actionUrl!==null){
			$state['action_url']=is_callable($actionUrl) && !is_string($actionUrl) ? \Closure::fromCallable($actionUrl) : $actionUrl;
		}
		if($icon!==null){
			$state['icon']=$icon;
		}
		return $state;
	}

	/**
	 * Removes closures from empty-state definitions for manifest serialization.
	 *
	 * Dynamic resolvers and action URLs are represented by boolean flags so clients
	 * and tooling can tell which fields resolve only at runtime.
	 *
	 * @param array<string,mixed> $state Empty-state definition.
	 * @return array<string,mixed> Serializable empty-state payload.
	 */
	private function serializableEmptyState(array $state): array {
		$out=$state;
		if(isset($out['resolver'])){
			$out['dynamic']=true;
			unset($out['resolver']);
		}
		if(isset($out['action_url']) && $out['action_url'] instanceof \Closure){
			$out['action_url_dynamic']=true;
			unset($out['action_url']);
		}
		return $out;
	}

	/**
	 * Resolves one preview field definition into a label/value pair.
	 *
	 * Definitions may be field-name strings, keyed values, arrays with name/key,
	 * label, and value, or callbacks evaluated with record/request/resource/table
	 * context. Blank labels are dropped.
	 *
	 * @param int|string $key Preview definition key.
	 * @param mixed $definition Preview field definition.
	 * @param mixed $record Row record.
	 * @param ?PanelRequest $request Current panel request.
	 * @param ?Resource $resource Owning resource.
	 * @param ?self $table Current table definition.
	 * @return ?array{label:string,value:string} Resolved preview field, or null.
	 */
	private static function resolvePreviewField(int|string $key, mixed $definition, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, ?self $table=null): ?array {
		if(is_int($key) && is_string($definition)){
			$name=Resource::normalizeName($definition);
			$label=self::humanize($name);
			$value=self::recordValue($record, $name, '');
		}
		elseif(is_array($definition)){
			$name=Resource::normalizeName((string)($definition['name'] ?? $definition['key'] ?? (is_string($key) ? $key : '')));
			$label=trim((string)($definition['label'] ?? self::humanize($name)));
			if(array_key_exists('value', $definition)){
				$value=$definition['value'];
				if($value instanceof \Closure || is_callable($value)){
					$value=PanelUtilityResolver::evaluate(\Closure::fromCallable($value), [
						'record'=>$record,
						'request'=>$request,
						'resource'=>$resource,
						'table'=>$table,
						'name'=>$name,
					], ['record', 'request', 'resource', 'table', 'name']);
				}
			}
			else {
				$value=$name!=='' ? self::recordValue($record, $name, '') : '';
			}
		}
		else {
			$name=Resource::normalizeName((string)$key);
			$label=self::humanize($name);
			$value=$definition;
			if($value instanceof \Closure || is_callable($value)){
				$value=PanelUtilityResolver::evaluate(\Closure::fromCallable($value), [
					'record'=>$record,
					'request'=>$request,
					'resource'=>$resource,
					'table'=>$table,
					'name'=>$name,
				], ['record', 'request', 'resource', 'table', 'name']);
			}
		}
		$label=trim((string)($label ?? ''));
		if($label===''){
			return null;
		}
		return [
			'label'=>$label,
			'value'=>self::stringValue($value ?? ''),
		];
	}

	/**
	 * Serializes static row preview fields for the table manifest.
	 *
	 * Dynamic preview resolvers and per-field callbacks are omitted or represented
	 * with blank values because they require record context.
	 *
	 * @return array<int,array{label:string,value:string}> Static preview field payloads.
	 */
	private function staticPreviewFields(): array {
		if($this->rowPreviewResolver!==null || $this->rowPreviewFields===[]){
			return [];
		}
		$fields=[];
		foreach($this->rowPreviewFields as $key=>$definition){
			if(is_int($key) && is_string($definition)){
				$name=Resource::normalizeName($definition);
				$fields[]=['label'=>self::humanize($name), 'value'=>''];
				continue;
			}
			if(is_array($definition)){
				$name=Resource::normalizeName((string)($definition['name'] ?? $definition['key'] ?? (is_string($key) ? $key : '')));
				$value=$definition['value'] ?? '';
				$fields[]=[
					'label'=>trim((string)($definition['label'] ?? self::humanize($name))),
					'value'=>($value instanceof \Closure || is_callable($value)) ? '' : self::stringValue($value),
				];
				continue;
			}
			if(is_string($key)){
				$fields[]=[
					'label'=>self::humanize(Resource::normalizeName($key)),
					'value'=>($definition instanceof \Closure || is_callable($definition)) ? '' : self::stringValue($definition),
				];
			}
		}
		return array_values(array_filter($fields, static fn(array $field): bool => trim((string)($field['label'] ?? ''))!==''));
	}

	/**
	 * Resolves the requested visible column list from request or preferences.
	 *
	 * Request visible_columns takes precedence over persisted preferences. String
	 * lists may be comma-separated, and every column key is normalized.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string,mixed> $preferences Persisted table preferences.
	 * @return array<int,string> Requested column names.
	 */
	private static function requestedColumns(PanelRequest $request, array $preferences=[]): array {
		$query=$request->query();
		$value=is_array($query) && array_key_exists('visible_columns', $query)
			? $query['visible_columns']
			: ($preferences['visible_columns'] ?? []);
		if(is_string($value)){
			$value=explode(',', $value);
		}
		if(!is_array($value)){
			return [];
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $column): string => Resource::normalizeName((string)$column),
			$value
		))));
	}

	/**
	 * Resolves the active sort column and direction.
	 *
	 * Explicit request sort state wins. When no sort is requested, the table
	 * defaultSort definition is used and direction is constrained to asc/desc.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param self $table Table definition providing defaults.
	 * @return array{column:string,direction:string} Active sort state.
	 */
	private static function sortState(PanelRequest $request, self $table): array {
		$query=$request->query();
		$hasSort=is_array($query) && array_key_exists('sort', $query);
		$sort=Resource::normalizeName((string)($query['sort'] ?? ''));
		$direction=strtolower((string)($query['dir'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
		if(!$hasSort && $sort===''){
			$default=$table->defaultSortDefinition();
			if(is_array($default)){
				$sort=Resource::normalizeName((string)($default['column'] ?? ''));
				$direction=strtolower((string)($default['direction'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
			}
		}
		return ['column'=>$sort, 'direction'=>$direction];
	}

	/**
	 * Collects static row attributes for manifest serialization.
	 *
	 * Dynamic row attribute callbacks are skipped here and reported through
	 * row_attributes_dynamic.
	 *
	 * @return array<string,mixed> Static row attributes.
	 */
	private function staticExtraAttributes(): array {
		$attributes=[];
		foreach($this->rowAttributes as $set){
			if(is_array($set)){
				$attributes=array_replace($attributes, $set);
			}
		}
		return $attributes;
	}

	/**
	 * Reports whether row attributes need runtime evaluation.
	 *
	 * Renderers use this to decide whether per-record row attribute resolution is
	 * required.
	 *
	 * @return bool Whether any row attribute source is a closure.
	 */
	private function hasDynamicExtraAttributes(): bool {
		foreach($this->rowAttributes as $set){
			if($set instanceof \Closure){
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes caller-supplied row HTML attributes.
	 *
	 * Integer keys may declare boolean attributes, unsafe/internal attributes are
	 * rejected, and only scalar, stringable, boolean, or null values are retained.
	 *
	 * @param array<string|int,mixed> $attributes Raw row attributes.
	 * @return array<string,mixed> Safe row attribute map.
	 */
	private static function normalizeExtraAttributes(array $attributes): array {
		$normalized=[];
		foreach($attributes as $name=>$value){
			if(is_int($name) && is_string($value)){
				$name=$value;
				$value=true;
			}
			if(!is_string($name)){
				continue;
			}
			$name=strtolower(trim($name));
			if(!self::isAllowedExtraAttribute($name)){
				continue;
			}
			if($value===null || $value===false){
				$normalized[$name]=$value;
				continue;
			}
			if($value===true || is_scalar($value) || $value instanceof \Stringable){
				$normalized[$name]=$value;
			}
		}
		return $normalized;
	}

	/**
	 * Enforces the row-attribute safety boundary.
	 *
	 * Table rows may receive class, safe data-* and aria-* attributes, id, and role.
	 * Internal data-dp-panel-* attributes and aria-label are reserved for renderers.
	 *
	 * @param string $name Lowercase attribute name.
	 * @return bool Whether the attribute may be exposed.
	 */
	private static function isAllowedExtraAttribute(string $name): bool {
		if($name==='class'){
			return true;
		}
		if(str_starts_with($name, 'data-dp-panel-')){
			return false;
		}
		if(preg_match('/^data-[a-z0-9_.:-]+$/', $name)===1){
			return true;
		}
		if(preg_match('/^aria-[a-z0-9_.:-]+$/', $name)===1){
			return !in_array($name, ['aria-label'], true);
		}
		return in_array($name, ['id', 'role'], true);
	}

	/**
	 * Normalizes one data-* or aria-* row attribute suffix.
	 *
	 * Unsupported characters become hyphens so rowData() and rowAria() produce
	 * valid HTML attribute names.
	 *
	 * @param string $name Raw attribute suffix.
	 * @return string Normalized suffix.
	 */
	private static function normalizeAttributeSegment(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.:-]+/', '-', $name) ?? '';
		return trim($name, '-');
	}

	/**
	 * Reads a preview value from arrays, public object properties, or getters.
	 *
	 * Getter names are derived from normalized keys by converting separators to
	 * words and prefixing get.
	 *
	 * @param mixed $record Row record.
	 * @param string $key Record key or property name.
	 * @param mixed $default Value returned when unavailable.
	 * @return mixed array value, public property, getter result, or the caller default when unavailable.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=''): mixed {
		if(is_array($record)){
			return $record[$key] ?? $default;
		}
		if(is_object($record)){
			if(isset($record->{$key})){
				return $record->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return $default;
	}

	/**
	 * Converts preview field values into display strings.
	 *
	 * Booleans become Yes/No, scalars and Stringable values are preserved, and
	 * arrays/objects fall back to JSON for compact inspection.
	 *
	 * @param mixed $value Raw preview value.
	 * @return string Display string.
	 */
	private static function stringValue(mixed $value): string {
		if($value===null){
			return '';
		}
		if(is_bool($value)){
			return $value ? 'Yes' : 'No';
		}
		if(is_scalar($value) || $value instanceof \Stringable){
			return (string)$value;
		}
		$json=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : '';
	}

	/**
	 * Builds a display label from a table key.
	 *
	 * Common separators become spaces and words are title-cased for default column,
	 * preview, and empty-state labels.
	 *
	 * @param string $value Machine key.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
