<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable saved-view builder for panel resource tables.
 *
 * TableView describes a named table preset: label, tone, default state, query
 * defaults, visible columns, filters, sort/density preferences, optional record
 * predicate, badge value/resolver, and renderer metadata. Panel clients use the
 * serialized view to offer operator shortcuts without mutating the resource table
 * definition itself.
 */
final class TableView {
	use PanelExtensible;

	private string $name;
	private string $label;
	private bool $default=false;
	private string $tone='neutral';
	private array $queryDefaults=[];
	private ?\Closure $predicate=null;
	private ?\Closure $badgeResolver=null;
	private mixed $badge=null;
	private array $meta=[];

	/**
	 * Creates a normalized table view identity.
	 *
	 * @param string $name View identifier used in manifests and request state.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured table view.
	 *
	 * @param string $name View identifier.
	 * @return self New table view builder.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Rehydrates a table view from a manifest-style definition.
	 *
	 * Supported keys include name, label, default, tone, badge, query, search,
	 * columns/visible_columns, filters, sort, per_page, density, and meta.
	 *
	 * @param array<string, mixed> $definition Serialized view definition.
	 * @return self Rehydrated table view builder.
	 */
	public static function fromArray(array $definition): self {
		$view=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label'])){
			$view=$view->label((string)$definition['label']);
		}
		if(!empty($definition['default'])){
			$view=$view->default();
		}
		if(isset($definition['tone']) && is_string($definition['tone'])){
			$view=$view->tone($definition['tone']);
		}
		if(array_key_exists('badge', $definition)){
			$view=$view->badge($definition['badge']);
		}
		if(isset($definition['query']) && is_array($definition['query'])){
			$view=$view->query($definition['query']);
		}
		if(isset($definition['search'])){
			$view=$view->search((string)$definition['search']);
		}
		if(isset($definition['columns']) || isset($definition['visible_columns'])){
			$columns=$definition['columns'] ?? $definition['visible_columns'];
			if(is_array($columns) || is_string($columns)){
				$view=$view->visibleColumns($columns);
			}
		}
		if(isset($definition['filters']) && is_array($definition['filters'])){
			$view=$view->filters($definition['filters']);
		}
		if(isset($definition['sort']) && is_array($definition['sort'])){
			$view=$view->sort((string)($definition['sort']['column'] ?? $definition['sort'][0] ?? ''), (string)($definition['sort']['direction'] ?? $definition['sort']['dir'] ?? $definition['sort'][1] ?? 'asc'));
		}
		if(isset($definition['per_page'])){
			$view=$view->perPage((int)$definition['per_page']);
		}
		if(isset($definition['density'])){
			$view=$view->density((string)$definition['density']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$view=$view->meta($definition['meta']);
		}
		return $view;
	}

	/**
	 * Returns the normalized view name.
	 *
	 * @return string View identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the operator-facing view label.
	 *
	 * @param string $label Label displayed in table view selectors.
	 * @return self Cloned view with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Marks whether this view is the table's default preset.
	 *
	 * @param bool $default Whether this view should be selected by default.
	 * @return self Cloned view with default flag.
	 */
	public function default(bool $default=true): self {
		$clone=clone $this;
		$clone->default=$default;
		return $clone;
	}

	/**
	 * Sets the visual tone used for this view.
	 *
	 * Unsupported tones fall back to neutral.
	 *
	 * @param string $tone neutral, primary, success, warning, danger, or info.
	 * @return self Cloned view with normalized tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Sets a static or computed badge for the view.
	 *
	 * Callable badges are evaluated by resolveBadge() with records, request,
	 * resource, and view context. Non-callable values are serialized directly.
	 *
	 * @param mixed $badge Static badge value or callable resolver.
	 * @return self Cloned view with badge metadata.
	 */
	public function badge(mixed $badge): self {
		$clone=clone $this;
		if(is_callable($badge)){
			$clone->badgeResolver=\Closure::fromCallable($badge);
			$clone->badge=null;
			return $clone;
		}
		$clone->badge=$badge;
		$clone->badgeResolver=null;
		return $clone;
	}

	/**
	 * Merges query defaults into the view.
	 *
	 * Keys are normalized to request-safe names. Empty keys are skipped.
	 *
	 * @param array<string|int, mixed> $query Query default map.
	 * @return self Cloned view with merged query defaults.
	 */
	public function query(array $query): self {
		$clone=clone $this;
		foreach($query as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key===''){
				continue;
			}
			$clone->queryDefaults[$key]=$value;
		}
		return $clone;
	}

	/**
	 * Sets one query default value.
	 *
	 * @param string $key Query key.
	 * @param mixed $value Default value for that key.
	 * @return self Cloned view with the query default applied.
	 */
	public function queryDefault(string $key, mixed $value): self {
		return $this->query([$key=>$value]);
	}

	/**
	 * Alias for queryDefault() used by declarative view definitions.
	 *
	 * @param string $key Query key.
	 * @param mixed $value Default value for that key.
	 * @return self Cloned view with the preset applied.
	 */
	public function preset(string $key, mixed $value): self {
		return $this->queryDefault($key, $value);
	}

	/**
	 * Sets the table search query default.
	 *
	 * @param string $query Search text.
	 * @return self Cloned view with q query default.
	 */
	public function search(string $query): self {
		return $this->query(['q'=>trim($query)]);
	}

	/**
	 * Alias for visibleColumns().
	 *
	 * @param array|string ...$columns Column names, arrays, or comma-separated strings.
	 * @return self Cloned view with visible column defaults.
	 */
	public function columns(array|string ...$columns): self {
		return $this->visibleColumns(...$columns);
	}

	/**
	 * Sets the default visible columns for the table.
	 *
	 * Input is flattened from arrays and comma-separated strings, normalized, and
	 * de-duplicated before being stored under visible_columns.
	 *
	 * @param array|string ...$columns Column names, arrays, or comma-separated strings.
	 * @return self Cloned view with visible column defaults.
	 */
	public function visibleColumns(array|string ...$columns): self {
		$flat=[];
		foreach($columns as $column){
			if(is_array($column)){
				foreach($column as $nested){
					$flat[]=Resource::normalizeName((string)$nested);
				}
				continue;
			}
			foreach(explode(',', $column) as $nested){
				$flat[]=Resource::normalizeName((string)$nested);
			}
		}
		$flat=array_values(array_unique(array_filter($flat)));
		return $flat!==[] ? $this->query(['visible_columns'=>$flat]) : $this;
	}

	/**
	 * Sets multiple filter defaults.
	 *
	 * Filter names are normalized and empty names are ignored.
	 *
	 * @param array<string|int, mixed> $filters Filter default map.
	 * @return self Cloned view with filter query defaults.
	 */
	public function filters(array $filters): self {
		$defaults=[];
		foreach($filters as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key!==''){
				$defaults[$key]=$value;
			}
		}
		return $defaults!==[] ? $this->query($defaults) : $this;
	}

	/**
	 * Adds either a runtime predicate or one filter default.
	 *
	 * A callable with no explicit value becomes a where() predicate. String/value
	 * input becomes a query filter default.
	 *
	 * @param callable|string $predicate Predicate callback or filter name.
	 * @param mixed $value Filter value.
	 * @return self Cloned view with predicate or filter default.
	 */
	public function filter(callable|string $predicate, mixed $value=null): self {
		if($value===null && is_callable($predicate)){
			return $this->where($predicate);
		}
		return $this->filterValue($predicate, $value);
	}

	/**
	 * Sets one filter query default.
	 *
	 * @param string $name Filter name.
	 * @param mixed $value Filter value.
	 * @return self Cloned view with filter default applied.
	 */
	public function filterValue(string $name, mixed $value): self {
		$name=Resource::normalizeName($name);
		return $name!=='' ? $this->query([$name=>$value]) : $this;
	}

	/**
	 * Sets a from/to range filter default.
	 *
	 * The values are stored as {name}_from and {name}_to query defaults.
	 *
	 * @param string $name Range filter base name.
	 * @param mixed $from Lower bound.
	 * @param mixed $to Upper bound.
	 * @return self Cloned view with range defaults.
	 */
	public function range(string $name, mixed $from=null, mixed $to=null): self {
		$name=Resource::normalizeName($name);
		if($name===''){
			return $this;
		}
		return $this->query([
			$name.'_from'=>$from,
			$name.'_to'=>$to,
		]);
	}

	/**
	 * Sets default sort column and direction.
	 *
	 * Direction is normalized to asc or desc.
	 *
	 * @param string $column Sortable column name.
	 * @param string $direction Sort direction.
	 * @return self Cloned view with sort defaults.
	 */
	public function sort(string $column, string $direction='asc'): self {
		return $this->query([
			'sort'=>Resource::normalizeName($column),
			'dir'=>strtolower(trim($direction))==='desc' ? 'desc' : 'asc',
		]);
	}

	/**
	 * Sets default rows per page.
	 *
	 * Values are clamped from 1 to 250 to keep table requests bounded.
	 *
	 * @param int $rows Requested rows per page.
	 * @return self Cloned view with per-page default.
	 */
	public function perPage(int $rows): self {
		return $this->query(['per_page'=>max(1, min(250, $rows))]);
	}

	/**
	 * Sets default table density.
	 *
	 * Unsupported density names are ignored.
	 *
	 * @param string $density compact, normal, or comfortable.
	 * @return self Cloned view when valid, otherwise the current view.
	 */
	public function density(string $density): self {
		$density=Resource::normalizeName($density);
		return in_array($density, ['compact', 'normal', 'comfortable'], true) ? $this->query(['density'=>$density]) : $this;
	}

	/**
	 * Sets a runtime predicate used to test records against this view.
	 *
	 * @param callable $predicate Callback evaluated with record, request, resource, and view context.
	 * @return self Cloned view with predicate.
	 */
	public function where(callable $predicate): self {
		$clone=clone $this;
		$clone->predicate=\Closure::fromCallable($predicate);
		return $clone;
	}

	/**
	 * Merges renderer metadata into the view.
	 *
	 * @param array<string, mixed> $meta Metadata consumed by table renderers.
	 * @return self Cloned view with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Evaluates whether a record belongs in this view.
	 *
	 * Views without predicates match every record. Predicate callbacks are resolved
	 * with named panel context so closures can opt into only the arguments they need.
	 *
	 * @param mixed $record Resource record being tested.
	 * @param PanelRequest $request Current panel request.
	 * @param Resource $resource Resource that owns the table.
	 * @return bool True when the record matches this view.
	 */
	public function matches(mixed $record, PanelRequest $request, Resource $resource): bool {
		if($this->predicate===null){
			return true;
		}
		return (bool)PanelUtilityResolver::evaluate($this->predicate, [
			'record'=>$record,
			'request'=>$request,
			'resource'=>$resource,
			'view'=>$this,
		], ['record', 'request', 'resource', 'view']);
	}

	/**
	 * Resolves the badge value for this view.
	 *
	 * Static badges are returned as-is. Callable badges receive records, request,
	 * resource, and view context.
	 *
	 * @param array<int, mixed> $records Records currently represented by the view.
	 * @param PanelRequest $request Current panel request.
	 * @param Resource $resource Resource that owns the table.
	 * @return mixed static badge value, or callback-produced badge value for the current view context.
	 */
	public function resolveBadge(array $records, PanelRequest $request, Resource $resource): mixed {
		if($this->badgeResolver!==null){
			return PanelUtilityResolver::evaluate($this->badgeResolver, [
				'records'=>$records,
				'data'=>$records,
				'request'=>$request,
				'resource'=>$resource,
				'view'=>$this,
			], ['records', 'request', 'resource', 'view']);
		}
		return $this->badge;
	}

	/**
	 * Returns query defaults contributed by this view.
	 *
	 * @return array<string, mixed> Normalized query defaults.
	 */
	public function queryDefaults(): array {
		return $this->queryDefaults;
	}

	/**
	 * Serializes this view for table manifests.
	 *
	 * @return array<string, mixed> View identity, display metadata, query defaults, callback flags, badge, and metadata.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'default'=>$this->default,
			'tone'=>$this->tone,
			'query'=>$this->queryDefaults,
			'has_predicate'=>$this->predicate!==null,
			'has_badge_resolver'=>$this->badgeResolver!==null,
			'badge'=>$this->badgeResolver===null ? $this->badge : null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts a normalized view name into a readable label.
	 *
	 * @param string $value Normalized view name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
