<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel page table definition and record resolution pipeline.
 *
 * PageTable defines columns, filters, views, summaries, groups, record sources,
 * default sorting, table-scoped query mapping, search, manifests, and metadata for
 * table widgets rendered on panel pages.
 */
final class PageTable {
	use PanelExtensible;

	private string $name;
	private string $label;
	/** @var array<string, Column> */
	private array $columns=[];
	/** @var array<string, TableFilter> */
	private array $filters=[];
	/** @var array<string, TableView> */
	private array $views=[];
	/** @var array<string, TableSummary> */
	private array $summaries=[];
	/** @var array<string, TableGroup> */
	private array $groups=[];
	private array $records=[];
	private ?\Closure $recordsResolver=null;
	private ?string $emptyMessage=null;
	private ?string $description=null;
	private ?string $defaultSort=null;
	private string $defaultSortDirection='asc';
	private ?int $limit=null;
	private int $sort=100;
	private array $meta=[];

	/**
	 * Creates a page table with a normalized name and generated label.
	 *
	 * The constructor is private so configured table instances go through make().
	 *
	 * @param string $name Raw table name.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured page table definition.
	 *
	 * PanelExtensible hooks are applied before the table is returned.
	 *
	 * @param string $name Raw table name.
	 * @return self Configured table definition.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Hydrates a page table from array configuration.
	 *
	 * Supported keys include name, label, description, empty_message, columns,
	 * filters, views, summaries, groups, records, default_sort, limit, sort, and meta.
	 *
	 * @param array<string, mixed> $definition Table definition payload.
	 * @return self Configured table definition.
	 */
	public static function fromArray(array $definition): self {
		$table=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'empty_message'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$table=$table->{$key}($definition[$key]);
			}
		}
		if(isset($definition['columns']) && is_array($definition['columns'])){
			$table=$table->columns($definition['columns']);
		}
		if(isset($definition['filters']) && is_array($definition['filters'])){
			$table=$table->filters($definition['filters']);
		}
		if(isset($definition['views']) && is_array($definition['views'])){
			$table=$table->views($definition['views']);
		}
		if(isset($definition['summaries']) && is_array($definition['summaries'])){
			$table=$table->summaries($definition['summaries']);
		}
		if(isset($definition['groups']) && is_array($definition['groups'])){
			$table=$table->groups($definition['groups']);
		}
		if(isset($definition['records']) && is_array($definition['records'])){
			$table=$table->records($definition['records']);
		}
		if(isset($definition['default_sort']) && is_string($definition['default_sort'])){
			$table=$table->defaultSort($definition['default_sort'], (string)($definition['default_sort_direction'] ?? 'asc'));
		}
		if(isset($definition['limit'])){
			$table=$table->limit((int)$definition['limit']);
		}
		if(isset($definition['sort'])){
			$table=$table->sort((int)$definition['sort']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$table=$table->meta($definition['meta']);
		}
		return $table;
	}

	/**
	 * Returns the normalized table name.
	 *
	 * @return string Table key used for query prefixes and manifests.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with a display label.
	 *
	 * @param string $label Human-readable table label.
	 * @return self Cloned table definition.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a clone with optional table description text.
	 *
	 * Empty descriptions are stored as null.
	 *
	 * @param string $description Description shown near the table.
	 * @return self Cloned table definition.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with the message shown when no records are available.
	 *
	 * @param string $message Empty-state message.
	 * @return self Cloned table definition.
	 */
	public function emptyMessage(string $message): self {
		$clone=clone $this;
		$clone->emptyMessage=trim($message) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with additional columns appended from mixed definitions.
	 *
	 * Each entry may be a Column instance, array definition, or string column name.
	 *
	 * @param array<int, Column|array<string, mixed>|string> $columns Column definitions.
	 * @return self Cloned table definition.
	 */
	public function columns(array $columns): self {
		$clone=clone $this;
		foreach($columns as $column){
			$clone=$clone->column($column);
		}
		return $clone;
	}

	/**
	 * Returns a clone with one column registered by name.
	 *
	 * Empty normalized column names are ignored.
	 *
	 * @param Column|array<string, mixed>|string $column Column instance, array definition, or name.
	 * @param string|null $type Column type used when $column is a string.
	 * @return self Cloned table definition.
	 */
	public function column(Column|array|string $column, ?string $type=null): self {
		$clone=clone $this;
		if(is_string($column)){
			$column=Column::make($column, $type ?? 'text');
		}
		elseif(is_array($column)){
			$column=Column::fromArray($column);
		}
		$name=$column->name();
		if($name!==''){
			$clone->columns[$name]=$column;
		}
		return $clone;
	}

	/**
	 * Returns a clone with additional filters appended from mixed definitions.
	 *
	 * @param array<int, TableFilter|array<string, mixed>|string> $filters Filter definitions.
	 * @return self Cloned table definition.
	 */
	public function filters(array $filters): self {
		$clone=clone $this;
		foreach($filters as $filter){
			$clone=$clone->filter($filter);
		}
		return $clone;
	}

	/**
	 * Returns a clone with one filter registered by name.
	 *
	 * @param TableFilter|array<string, mixed>|string $filter Filter instance, array definition, or name.
	 * @param string|null $type Filter type used when $filter is a string.
	 * @return self Cloned table definition.
	 */
	public function filter(TableFilter|array|string $filter, ?string $type=null): self {
		$clone=clone $this;
		if(is_string($filter)){
			$filter=TableFilter::make($filter, $type ?? 'text');
		}
		elseif(is_array($filter)){
			$filter=TableFilter::fromArray($filter);
		}
		$name=$filter->name();
		if($name!==''){
			$clone->filters[$name]=$filter;
		}
		return $clone;
	}

	/**
	 * Returns a clone with the table views replaced.
	 *
	 * @param array<int, TableView|array<string, mixed>|string> $views View definitions.
	 * @return self Cloned table definition.
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
	 * Returns a clone with one view registered by name.
	 *
	 * @param TableView|array<string, mixed>|string $view View instance, array definition, or name.
	 * @return self Cloned table definition.
	 */
	public function view(TableView|array|string $view): self {
		$clone=clone $this;
		if(is_string($view)){
			$view=TableView::make($view);
		}
		elseif(is_array($view)){
			$view=TableView::fromArray($view);
		}
		$name=$view->name();
		if($name!==''){
			$clone->views[$name]=$view;
		}
		return $clone;
	}

	/**
	 * Returns a clone with additional summary definitions appended.
	 *
	 * @param array<int, TableSummary|array<string, mixed>|string> $summaries Summary definitions.
	 * @return self Cloned table definition.
	 */
	public function summaries(array $summaries): self {
		$clone=clone $this;
		foreach($summaries as $summary){
			$clone=$clone->summary($summary);
		}
		return $clone;
	}

	/**
	 * Returns a clone with one summary registered by name.
	 *
	 * @param TableSummary|array<string, mixed>|string $summary Summary instance, array definition, or name.
	 * @param string $type Summary type used when $summary is a string.
	 * @return self Cloned table definition.
	 */
	public function summary(TableSummary|array|string $summary, string $type='count'): self {
		$clone=clone $this;
		if(is_string($summary)){
			$summary=TableSummary::make($summary, $type);
		}
		elseif(is_array($summary)){
			$summary=TableSummary::fromArray($summary);
		}
		$name=$summary->name();
		if($name!==''){
			$clone->summaries[$name]=$summary;
		}
		return $clone;
	}

	/**
	 * Returns a clone with the table groups replaced.
	 *
	 * @param array<int, TableGroup|array<string, mixed>|string> $groups Group definitions.
	 * @return self Cloned table definition.
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
	 * Returns a clone with one group registered by name.
	 *
	 * @param TableGroup|array<string, mixed>|string $group Group instance, array definition, or name.
	 * @return self Cloned table definition.
	 */
	public function group(TableGroup|array|string $group): self {
		$clone=clone $this;
		if(is_string($group)){
			$group=TableGroup::make($group);
		}
		elseif(is_array($group)){
			$group=TableGroup::fromArray($group);
		}
		$name=$group->name();
		if($name!==''){
			$clone->groups[$name]=$group;
		}
		return $clone;
	}

	/**
	 * Returns a clone backed by static records.
	 *
	 * Setting static records clears any lazy records resolver.
	 *
	 * @param array<int, mixed> $records Records to display.
	 * @return self Cloned table definition.
	 */
	public function records(array $records): self {
		$clone=clone $this;
		$clone->records=$records;
		$clone->recordsResolver=null;
		return $clone;
	}

	/**
	 * Returns a clone backed by a lazy record resolver.
	 *
	 * The resolver may receive request, table, and page context when records are
	 * resolved for a manifest.
	 *
	 * @param callable $resolver Resolver returning an array or object with records.
	 * @return self Cloned table definition.
	 */
	public function recordsUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->recordsResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Alias for recordsUsing() for query-style table configuration.
	 *
	 * @param callable $resolver Resolver returning records.
	 * @return self Cloned table definition.
	 */
	public function queryUsing(callable $resolver): self {
		return $this->recordsUsing($resolver);
	}

	/**
	 * Returns a clone with a default sort column and direction.
	 *
	 * Direction is normalized to asc or desc.
	 *
	 * @param string $column Column name used for default sorting.
	 * @param string $direction Sort direction.
	 * @return self Cloned table definition.
	 */
	public function defaultSort(string $column, string $direction='asc'): self {
		$clone=clone $this;
		$clone->defaultSort=Resource::normalizeName($column) ?: null;
		$direction=strtolower(trim($direction));
		$clone->defaultSortDirection=$direction==='desc' ? 'desc' : 'asc';
		return $clone;
	}

	/**
	 * Returns a clone with an optional record limit.
	 *
	 * Non-positive limits are treated as no limit.
	 *
	 * @param int|null $limit Maximum number of resolved records.
	 * @return self Cloned table definition.
	 */
	public function limit(?int $limit): self {
		$clone=clone $this;
		$clone->limit=$limit!==null && $limit>0 ? $limit : null;
		return $clone;
	}

	/**
	 * Returns a clone with an ordering weight for panel display.
	 *
	 * @param int $sort Sort weight.
	 * @return self Cloned table definition.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a clone with merged table metadata.
	 *
	 * @param array<string, mixed> $meta Metadata to merge into the table manifest.
	 * @return self Cloned table definition.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/** @return array<string, Column> */
	/**
	 * Returns registered columns keyed by column name.
	 *
	 * @return array<string, Column> Column instances.
	 */
	public function columnsList(): array {
		return $this->columns;
	}

	/** @return array<string, TableFilter> */
	/**
	 * Returns registered filters keyed by filter name.
	 *
	 * @return array<string, TableFilter> Filter instances.
	 */
	public function filtersList(): array {
		return $this->filters;
	}

	/** @return array<string, TableView> */
	/**
	 * Returns registered views keyed by view name.
	 *
	 * @return array<string, TableView> View instances.
	 */
	public function viewsList(): array {
		return $this->views;
	}

	/** @return array<string, TableSummary> */
	/**
	 * Returns registered summaries keyed by summary name.
	 *
	 * @return array<string, TableSummary> Summary instances.
	 */
	public function summariesList(): array {
		return $this->summaries;
	}

	/** @return array<string, TableGroup> */
	/**
	 * Returns registered groups keyed by group name.
	 *
	 * @return array<string, TableGroup> Group instances.
	 */
	public function groupsList(): array {
		return $this->groups;
	}

	/**
	 * Returns the query-string prefix used by table filters and controls.
	 *
	 * @return string Prefix in the form tableName_.
	 */
	public function filterPrefix(): string {
		return $this->name.'_';
	}

	/**
	 * Resolves the active table view for a request.
	 *
	 * The prefixed view query value wins when valid; all clears view selection; then
	 * the first default view is used when present.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Active view name, or an empty string for all/default records.
	 */
	public function activeViewName(PanelRequest $request): string {
		if($this->views===[]){
			return '';
		}
		$requested=Resource::normalizeName((string)$request->query($this->filterPrefix().'view', ''));
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
	 * Resolves the active table group for a request.
	 *
	 * The prefixed group query value wins when valid; none clears grouping; then the
	 * first default group is used when present.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Active group name, or an empty string for no group.
	 */
	public function activeGroupName(PanelRequest $request): string {
		if($this->groups===[]){
			return '';
		}
		$requested=Resource::normalizeName((string)$request->query($this->filterPrefix().'group', ''));
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
	 * Returns a request with the active view and its query defaults applied.
	 *
	 * View defaults are written using the table filter prefix without overriding
	 * non-empty request values.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return PanelRequest Request with resolved view query state.
	 */
	public function requestWithResolvedView(PanelRequest $request): PanelRequest {
		if($this->views===[]){
			return $request;
		}
		$prefix=$this->filterPrefix();
		$requested=Resource::normalizeName((string)$request->query($prefix.'view', ''));
		if($requested==='all'){
			return $request->withQueryValue($prefix.'view', 'all');
		}
		if($requested!=='' && isset($this->views[$requested])){
			return $this->requestWithViewDefaults($request->withQueryValue($prefix.'view', $requested), $this->views[$requested]);
		}
		$active=$this->activeViewName($request);
		return $active!=='' && isset($this->views[$active])
			? $this->requestWithViewDefaults($request->withQueryValue($prefix.'view', $active), $this->views[$active])
			: $request;
	}

	/**
	 * Maps prefixed table query controls into unprefixed filter input.
	 *
	 * Search q, filter values, and from/to range keys are copied from table-prefixed
	 * query keys so TableFilter instances can read their canonical names.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return PanelRequest Request carrying unprefixed table filter values.
	 */
	public function filterRequest(PanelRequest $request): PanelRequest {
		if($this->filters===[] && $this->views===[]){
			return $request;
		}
		$query=$request->query();
		$mapped=$query;
		$prefix=$this->filterPrefix();
		unset($mapped['q']);
		if(array_key_exists($prefix.'q', $query)){
			$mapped['q']=$query[$prefix.'q'];
		}
		foreach($this->filters as $filter){
			$name=$filter->name();
			unset($mapped[$name], $mapped[$name.'_from'], $mapped[$name.'_to']);
			if(array_key_exists($prefix.$name, $query)){
				$mapped[$name]=$query[$prefix.$name];
			}
			if(array_key_exists($prefix.$name.'_from', $query)){
				$mapped[$name.'_from']=$query[$prefix.$name.'_from'];
			}
			if(array_key_exists($prefix.$name.'_to', $query)){
				$mapped[$name.'_to']=$query[$prefix.$name.'_to'];
			}
		}
		return $request->withQuery($mapped, true);
	}

	/**
	 * Reports whether any visible filter has an active value.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return bool True when a table filter has active input.
	 */
	public function hasActiveFilters(PanelRequest $request): bool {
		$request=$this->filterRequest($request);
		foreach($this->filters as $filter){
			if($filter->activeValue($request)!==null){
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves, filters, searches, sorts, and limits table records.
	 *
	 * Resolver exceptions are captured in PanelTrace and produce an empty record set.
	 * Views, filters, search, default sort, and limit are applied in that order.
	 *
	 * @param PanelRequest|null $request Optional request used for filters/search/views.
	 * @param PanelPage|null $page Optional page context passed to lazy record resolvers.
	 * @return array<int, mixed> Resolved records for rendering.
	 */
	public function resolvedRecords(?PanelRequest $request=null, ?PanelPage $page=null): array {
		if($request instanceof PanelRequest){
			$request=$this->requestWithResolvedView($request);
		}
		$records=$this->records;
		if($this->recordsResolver!==null){
			try{
				$result=PanelUtilityResolver::evaluate($this->recordsResolver, [
					'request'=>$request,
					'table'=>$this,
					'page'=>$page,
				], ['request', 'table', 'page']);
				if(is_object($result)){
					foreach(['getRecords', 'get', 'items'] as $method){
						if(method_exists($result, $method)){
							$result=$result->{$method}();
							break;
						}
					}
				}
				$records=is_array($result) ? $result : [];
			}
			catch(\Throwable $exception){
				PanelTrace::record('page_table.records_error', [
					'table'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$records=[];
			}
		}
		$activeView=$request instanceof PanelRequest ? $this->activeViewName($request) : '';
		$view=$activeView!=='' ? ($this->views[$activeView] ?? null) : null;
		if($view instanceof TableView){
			$viewRequest=$this->filterRequest($request);
			$records=array_values(array_filter($records, static fn(mixed $record): bool => $view->matches($record, $viewRequest, Resource::make('__page_table'))));
		}
		if($this->filters!==[]){
			$filterRequest=$request instanceof PanelRequest ? $this->filterRequest($request) : PanelRequest::fromArray([]);
			foreach($this->filters as $filter){
				if($filter instanceof TableFilter && $filter->isVisible($filterRequest, null, $this)){
					$records=array_values(array_filter($records, static fn(mixed $record): bool => $filter->matches($record, $filterRequest)));
				}
			}
		}
		if($request instanceof PanelRequest){
			$records=$this->applySearch($records, $this->filterRequest($request));
		}
		if($this->defaultSort!==null){
			$column=$this->columns[$this->defaultSort] ?? null;
			$sort=$this->defaultSort;
			$direction=$this->defaultSortDirection;
			usort($records, static function(mixed $left, mixed $right) use ($column, $sort, $direction, $request): int {
				if($column instanceof Column){
					return $column->compareForSort($left, $right, $direction, $request instanceof PanelRequest ? $request : null, null, null);
				}
				$result=self::compareValues(self::recordValue($left, $sort), self::recordValue($right, $sort));
				return $direction==='desc' ? -$result : $result;
			});
		}
		if($this->limit!==null){
			$records=array_slice($records, 0, $this->limit);
		}
		return $records;
	}

	/**
	 * Serializes the table definition for diagnostics and manifests.
	 *
	 * @return array<string, mixed> Table definition payload.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'empty_message'=>$this->emptyMessage,
			'columns'=>array_map(static fn(Column $column): array => $column->toArray(), array_values($this->columns)),
			'filters'=>array_map(static fn(TableFilter $filter): array => $filter->toArray(), array_values($this->filters)),
			'views'=>array_map(static fn(TableView $view): array => $view->toArray(), array_values($this->views)),
			'summaries'=>array_map(static fn(TableSummary $summary): array => $summary->toArray(), array_values($this->summaries)),
			'groups'=>array_map(static fn(TableGroup $group): array => $group->toArray(), array_values($this->groups)),
			'record_count'=>count($this->records),
			'lazy'=>$this->recordsResolver!==null,
			'default_sort'=>$this->defaultSort,
			'default_sort_direction'=>$this->defaultSortDirection,
			'limit'=>$this->limit,
			'sort'=>$this->sort,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Builds the table manifest used by panel responses.
	 *
	 * Page metadata and supplied meta override/extend table metadata before the
	 * TableManifest value object serializes the final payload.
	 *
	 * @param PanelRequest|null $request Optional request context.
	 * @param PanelPage|null $page Optional page context.
	 * @param array<string, mixed> $meta Additional manifest metadata.
	 * @return array<string, mixed> Table manifest payload.
	 */
	public function manifest(?PanelRequest $request=null, ?PanelPage $page=null, array $meta=[]): array {
		$pageMeta=$page instanceof PanelPage ? $page->toArray() : [];
		return TableManifest::from($this, null, $request, array_replace($this->meta, [
			'page'=>$pageMeta['name'] ?? null,
		], $meta))->toArray();
	}

	/**
	 * Reads a comparable/display value from an array or object record.
	 *
	 * Object properties are checked before getX accessor methods.
	 *
	 * @param mixed $record Record array or object.
	 * @param string $key Field key to read.
	 * @param mixed $default Value returned when absent.
	 * @return mixed array value, public property, getter return value, or the caller default when absent.
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
	 * Compares record values for default sorting.
	 *
	 * DateTime values compare by timestamp, numeric values compare numerically, and
	 * other values compare with natural case-insensitive string order.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 * @return int Sort comparison result.
	 */
	private static function compareValues(mixed $left, mixed $right): int {
		if($left instanceof \DateTimeInterface){
			$left=$left->getTimestamp();
		}
		if($right instanceof \DateTimeInterface){
			$right=$right->getTimestamp();
		}
		if(is_numeric($left) || is_numeric($right)){
			return (float)$left <=> (float)$right;
		}
		return strnatcasecmp((string)$left, (string)$right);
	}

	/**
	 * Applies the q search query to resolved records.
	 *
	 * Searchable columns are preferred; if none are marked searchable every column is
	 * searched, and if no columns exist scalar record values are searched directly.
	 *
	 * @param array<int, mixed> $records Records to filter.
	 * @param PanelRequest $request Request containing q search input.
	 * @return array<int, mixed> Records matching the query.
	 */
	private function applySearch(array $records, PanelRequest $request): array {
		$query=trim((string)$request->query('q', ''));
		if($query===''){
			return $records;
		}
		$columns=array_filter($this->columns, static fn(Column $column): bool => ($column->toArray()['searchable'] ?? false)===true);
		if($columns===[]){
			$columns=$this->columns;
		}
		if($columns===[]){
			return array_values(array_filter($records, static function(mixed $record) use ($query): bool {
				$values=is_array($record) ? $record : (is_object($record) ? get_object_vars($record) : []);
				foreach($values as $value){
					if(is_scalar($value) && stripos((string)$value, $query)!==false){
						return true;
					}
				}
				return false;
			}));
		}
		return array_values(array_filter($records, function(mixed $record) use ($columns, $query, $request): bool {
			foreach($columns as $column){
				if($column->matchesSearch($record, $query, $request, null, $this)){
					return true;
				}
			}
			return false;
		}));
	}

	/**
	 * Applies a view's default query values to a request.
	 *
	 * Defaults are prefixed with the table filter prefix unless already prefixed, and
	 * existing non-empty query values are preserved.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param TableView $view View providing query defaults.
	 * @return PanelRequest Request with missing defaults filled.
	 */
	private function requestWithViewDefaults(PanelRequest $request, TableView $view): PanelRequest {
		$query=$request->query();
		$prefix=$this->filterPrefix();
		foreach($view->queryDefaults() as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key===''){
				continue;
			}
			$target=str_starts_with($key, $prefix) ? $key : $prefix.$key;
			if(array_key_exists($target, $query) && (is_array($query[$target]) ? $query[$target]!==[] : (string)$query[$target]!=='')){
				continue;
			}
			$query[$target]=$value;
		}
		return $request->withQuery($query, true);
	}

	/**
	 * Converts an internal table name into a display label.
	 *
	 * @param string $value Normalized table name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
