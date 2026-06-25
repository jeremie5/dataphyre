<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes how a panel table groups records for display and summary resolution.
 *
 * TableGroup is an immutable configuration object: mutator-style methods clone the instance and return the modified copy.
 * A group can derive its key from a record field or a custom resolver, then resolve a human label, optional description,
 * summaries, and group-level actions for the records collected under that key.
 *
 * Group keys are normalized for stable URLs and serialized state. Empty or null values resolve to the special __blank key
 * so "not set" records are grouped consistently instead of disappearing from grouped table views.
 */
final class TableGroup {
	use PanelExtensible;

	/** @var string Normalized field or group identifier. */
	private string $name;
	/** @var string Default human-facing group label. */
	private string $label;
	/** @var ?\Closure Resolver that maps a record to a group key. */
	private ?\Closure $stateResolver=null;
	/** @var ?\Closure Resolver that maps a group key and records to a display label. */
	private ?\Closure $labelResolver=null;
	/** @var ?\Closure Resolver that maps a group key and records to a description. */
	private ?\Closure $descriptionResolver=null;
	/** @var string Sort direction for grouped output. */
	private string $direction='asc';
	/** @var bool Whether this group should be the table's default grouping choice. */
	private bool $default=false;
	/** @var bool Whether the UI may collapse groups. */
	private bool $collapsible=false;
	/** @var bool Whether groups should initially render collapsed. */
	private bool $collapsed=false;
	/** @var array<string, TableSummary> */
	private array $summaries=[];
	/** @var array<int, array<string, mixed>> */
	private array $actions=[];
	/** @var array<string, mixed> Free-form metadata serialized with the group definition. */
	private array $meta=[];

	/**
	 * Creates a group keyed by a normalized table field or logical name.
	 *
	 * @param string $name Field name or logical grouping key.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured table group.
	 *
	 * The PanelExtensible hook is applied before returning so application-wide group macros or defaults can decorate the
	 * object.
	 *
	 * @param string $name Field name or logical grouping key.
	 * @return self Configured group definition.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Rehydrates a group from a serialized definition.
	 *
	 *
	 * Supported keys mirror toArray(): name, label, direction, default, collapsible, collapsed, summaries, actions, and meta.
	 * Callable resolvers are intentionally not reconstructed from array payloads.
	 *
	 * @param array<string, mixed> $definition Serialized group definition.
	 * @return self Group definition rebuilt from scalar and array metadata.
	 */
	public static function fromArray(array $definition): self {
		$group=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label'])){
			$group=$group->label((string)$definition['label']);
		}
		if(isset($definition['direction'])){
			$group=$group->direction((string)$definition['direction']);
		}
		if(!empty($definition['default'])){
			$group=$group->default();
		}
		if(!empty($definition['collapsible'])){
			$group=$group->collapsible();
		}
		if(!empty($definition['collapsed'])){
			$group=$group->collapsed();
		}
		if(isset($definition['summaries']) && is_array($definition['summaries'])){
			$group=$group->summaries($definition['summaries']);
		}
		if(isset($definition['actions']) && is_array($definition['actions'])){
			$group=$group->actions($definition['actions']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$group=$group->meta($definition['meta']);
		}
		return $group;
	}

	/**
	 * Returns the normalized group name.
	 *
	 * @return string Field or logical key used by default state resolution.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the static display label for the group definition.
	 *
	 * Dynamic per-key labels should use labelUsing().
	 *
	 * @param string $label Human-facing label for the group selector.
	 * @return self Cloned group with the new static label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Sets the resolver used to derive a group key from each record.
	 *
	 * The resolver receives record, resource, request, group, and table context through PanelUtilityResolver. Returned
	 * booleans become yes/no, blank values become __blank, and other values are normalized with Resource::normalizeName().
	 *
	 * @param callable $resolver Record-to-group-key resolver.
	 * @return self Cloned group with the state resolver.
	 */
	public function stateUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->stateResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Sets a resolver for labels displayed on resolved group buckets.
	 *
	 * The resolver receives key, value, records, resource, request, group, and table context. Non-empty scalar results are
	 * used as labels; otherwise the key is humanized.
	 *
	 * @param callable $resolver Group-label resolver.
	 * @return self Cloned group with the label resolver.
	 */
	public function labelUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->labelResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Sets a static description shown for each resolved group.
	 *
	 * @param string $description Description text.
	 * @return self Cloned group with description metadata.
	 */
	public function description(string $description): self {
		return $this->meta(['description'=>trim($description)]);
	}

	/**
	 * Sets a resolver for per-bucket descriptions.
	 *
	 * @param callable $resolver Description resolver receiving key, records, resource, request, group, and table context.
	 * @return self Cloned group with the description resolver.
	 */
	public function descriptionUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->descriptionResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Sets the direction used when sorted group buckets are rendered.
	 *
	 * Only desc is preserved; every other value falls back to asc.
	 *
	 * @param string $direction asc or desc.
	 * @return self Cloned group with the normalized direction.
	 */
	public function direction(string $direction): self {
		$direction=strtolower(trim($direction));
		$clone=clone $this;
		$clone->direction=$direction==='desc' ? 'desc' : 'asc';
		return $clone;
	}

	/**
	 * Marks this group as the table's default grouping option.
	 *
	 * @param bool $default Whether this group is selected by default.
	 * @return self Cloned group with default state.
	 */
	public function default(bool $default=true): self {
		$clone=clone $this;
		$clone->default=$default;
		return $clone;
	}

	/**
	 * Controls whether resolved groups may be collapsed in the UI.
	 *
	 * Disabling collapsibility also clears the default collapsed state.
	 *
	 * @param bool $collapsible Whether groups may be collapsed.
	 * @return self Cloned group with collapsibility state.
	 */
	public function collapsible(bool $collapsible=true): self {
		$clone=clone $this;
		$clone->collapsible=$collapsible;
		if(!$collapsible){
			$clone->collapsed=false;
		}
		return $clone;
	}

	/**
	 * Sets the initial collapsed state for resolved groups.
	 *
	 * Enabling collapsed state also enables collapsibility because non-collapsible groups cannot start collapsed.
	 *
	 * @param bool $collapsed Whether groups start collapsed.
	 * @return self Cloned group with collapsed state.
	 */
	public function collapsed(bool $collapsed=true): self {
		$clone=clone $this;
		$clone->collapsible=true;
		$clone->collapsed=$collapsed;
		return $clone;
	}

	/**
	 * Replaces the group summaries.
	 *
	 * Each summary may be a TableSummary, array definition, or string accepted by summary().
	 *
	 * @param array<int, TableSummary|array<string, mixed>|string> $summaries Summary definitions.
	 * @return self Cloned group with the replacement summary set.
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
	 * Adds one summary definition to the group.
	 *
	 * String input creates a TableSummary with the supplied type or count by default. Array input is rehydrated through
	 * TableSummary::fromArray(). Empty summary names are ignored to keep serialized group definitions addressable.
	 *
	 * @param TableSummary|array<string, mixed>|string $summary Summary definition.
	 * @param ?string $type Summary type used when $summary is a string.
	 * @return self Cloned group with the summary added.
	 */
	public function summary(TableSummary|array|string $summary, ?string $type=null): self {
		if(is_string($summary)){
			$summary=TableSummary::make($summary, $type ?? 'count');
		}
		elseif(is_array($summary)){
			$summary=TableSummary::fromArray($summary);
		}
		$clone=clone $this;
		if($summary->name()!==''){
			$clone->summaries[$summary->name()]=$summary;
		}
		return $clone;
	}

	/**
	 * Replaces the group actions.
	 *
	 * @param array<int, array<string, mixed>|string> $actions Action definitions.
	 * @return self Cloned group with replacement actions.
	 */
	public function actions(array $actions): self {
		$clone=clone $this;
		$clone->actions=[];
		foreach($actions as $action){
			$clone=$clone->action($action);
		}
		return $clone;
	}

	/**
	 * Adds an action rendered for resolved group buckets.
	 *
	 * Array input may include label, url, tone, icon, target, and visible. label, url, and visible may be callables resolved
	 * with key, records, resource, request, group, and table context. Invalid label definitions are ignored.
	 *
	 * @param array<string, mixed>|string $action Action definition or static label.
	 * @param string|callable|null $url Static or computed URL when $action is a string.
	 * @param string $tone Visual tone for the action.
	 * @param ?string $icon Optional icon name.
	 * @return self Cloned group with the action appended.
	 */
	public function action(array|string $action, string|callable|null $url=null, string $tone='neutral', ?string $icon=null): self {
		$definition=is_array($action) ? $action : [
			'label'=>$action,
			'url'=>$url,
			'tone'=>$tone,
			'icon'=>$icon,
		];
		$label=$definition['label'] ?? '';
		if(!is_string($label) && !is_callable($label)){
			return $this;
		}
		$clone=clone $this;
		$clone->actions[]=$definition;
		return $clone;
	}

	/**
	 * Merges free-form metadata into the group definition.
	 *
	 * @param array<string, mixed> $meta Metadata merged over existing metadata.
	 * @return self Cloned group with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Resolves the grouping key for one record.
	 *
	 * Without a custom state resolver, the group reads the field named by name() from arrays, public object properties, or a
	 * conventional getter. Boolean values become yes/no, blank values become __blank, and all other values are normalized.
	 *
	 * @param mixed $record Record being grouped.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return string Stable group key.
	 */
	public function resolveKey(mixed $record, ?Resource $resource=null, ?PanelRequest $request=null, PageTable|ResourceTable|null $table=null): string {
		$value=null;
		if($this->stateResolver!==null){
			$value=PanelUtilityResolver::evaluate($this->stateResolver, [
				'record'=>$record,
				'resource'=>$resource,
				'request'=>$request,
				'group'=>$this,
				'table'=>$table,
			], ['record', 'resource', 'request', 'group', 'table']);
		}
		else {
			$value=self::recordValue($record, $this->name, '');
		}
		if(is_bool($value)){
			return $value ? 'yes' : 'no';
		}
		if($value===null || $value===''){
			return '__blank';
		}
		return Resource::normalizeName((string)$value) ?: '__blank';
	}

	/**
	 * Resolves the label for one grouped bucket.
	 *
	 * __blank is displayed as "Not set" by default. Custom label resolvers can use the full record collection for the group
	 * to produce labels such as date ranges or relationship names.
	 *
	 * @param string $key Resolved group key.
	 * @param array<int, mixed> $records Records in the group.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return string Human-facing bucket label.
	 */
	public function resolveLabel(string $key, array $records=[], ?Resource $resource=null, ?PanelRequest $request=null, PageTable|ResourceTable|null $table=null): string {
		if($this->labelResolver!==null){
			$value=PanelUtilityResolver::evaluate($this->labelResolver, [
				'key'=>$key,
				'value'=>$key,
				'records'=>$records,
				'resource'=>$resource,
				'request'=>$request,
				'group'=>$this,
				'table'=>$table,
			], ['key', 'records', 'resource', 'request', 'group', 'table']);
			if(is_scalar($value) && trim((string)$value)!==''){
				return (string)$value;
			}
		}
		if($key==='__blank'){
			return 'Not set';
		}
		return self::humanize($key);
	}

	/**
	 * Resolves the description for one grouped bucket.
	 *
	 * The dynamic resolver wins when it returns a non-empty scalar value. Otherwise the static description stored in metadata
	 * is used.
	 *
	 * @param string $key Resolved group key.
	 * @param array<int, mixed> $records Records in the group.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return string Bucket description or an empty string.
	 */
	public function resolveDescription(string $key, array $records=[], ?Resource $resource=null, ?PanelRequest $request=null, PageTable|ResourceTable|null $table=null): string {
		if($this->descriptionResolver!==null){
			$value=PanelUtilityResolver::evaluate($this->descriptionResolver, [
				'key'=>$key,
				'value'=>$key,
				'records'=>$records,
				'resource'=>$resource,
				'request'=>$request,
				'group'=>$this,
				'table'=>$table,
			], ['key', 'records', 'resource', 'request', 'group', 'table']);
			if(is_scalar($value) && trim((string)$value)!==''){
				return (string)$value;
			}
		}
		return is_string($this->meta['description'] ?? null) ? trim((string)$this->meta['description']) : '';
	}

	/**
	 * Resolves summary payloads for one grouped bucket.
	 *
	 * Each TableSummary receives the grouped records and returns its own payload. The group name and key are added to every
	 * summary row so renderers can associate summary values with the bucket that produced them.
	 *
	 * @param string $key Resolved group key.
	 * @param array<int, mixed> $records Records in the group.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return array<int, array<string, mixed>> Resolved summary payloads.
	 */
	public function resolveSummaries(string $key, array $records=[], ?Resource $resource=null, ?PanelRequest $request=null, PageTable|ResourceTable|null $table=null): array {
		if($this->summaries===[]){
			return [];
		}
		$resource ??= Resource::make('__table_group');
		$request ??= PanelRequest::fromArray([]);
		$resolved=[];
		foreach($this->summaries as $summary){
			if(!$summary instanceof TableSummary){
				continue;
			}
			$data=$summary->resolve($records, $resource, $request);
			$data['group']=$this->name;
			$data['group_key']=$key;
			$resolved[]=$data;
		}
		return $resolved;
	}

	/**
	 * Resolves action payloads for one grouped bucket.
	 *
	 * Invisible actions are skipped. Actions without a non-empty label or URL after resolver evaluation are also skipped.
	 * Tones are normalized to the supported panel tone set and invalid tones fall back to neutral.
	 *
	 * @param string $key Resolved group key.
	 * @param array<int, mixed> $records Records in the group.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return array<int, array<string, string>> Resolved action payloads.
	 */
	public function resolveActions(string $key, array $records=[], ?Resource $resource=null, ?PanelRequest $request=null, PageTable|ResourceTable|null $table=null): array {
		if($this->actions===[]){
			return [];
		}
		$resolved=[];
		foreach($this->actions as $action){
			$visible=$action['visible'] ?? true;
			if(is_callable($visible)){
				$visible=PanelUtilityResolver::evaluate(\Closure::fromCallable($visible), [
					'key'=>$key,
					'value'=>$key,
					'records'=>$records,
					'resource'=>$resource,
					'request'=>$request,
					'group'=>$this,
					'table'=>$table,
				], ['key', 'records', 'resource', 'request', 'group', 'table']);
			}
			if($visible===false){
				continue;
			}
			$label=$this->resolveActionValue($action['label'] ?? '', $key, $records, $resource, $request, $table);
			$url=$this->resolveActionValue($action['url'] ?? '#', $key, $records, $resource, $request, $table);
			$label=is_scalar($label) ? trim((string)$label) : '';
			$url=is_scalar($url) ? trim((string)$url) : '';
			if($label==='' || $url===''){
				continue;
			}
			$tone=Resource::normalizeName((string)($action['tone'] ?? 'neutral'));
			$resolved[]=[
				'label'=>$label,
				'url'=>$url,
				'icon'=>is_string($action['icon'] ?? null) ? trim((string)$action['icon']) : '',
				'tone'=>in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral',
				'target'=>is_string($action['target'] ?? null) ? trim((string)$action['target']) : '',
				'group'=>$this->name,
				'group_key'=>$key,
			];
		}
		return $resolved;
	}

	/**
	 * Serializes the group definition for table manifests and Panel clients.
	 *
	 * Callable action labels and URLs are represented by computed_* flags because closures cannot be serialized directly.
	 *
	 * @return array<string, mixed> Serializable group definition.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'direction'=>$this->direction,
			'default'=>$this->default,
			'collapsible'=>$this->collapsible,
			'collapsed'=>$this->collapsed,
			'summaries'=>array_map(static fn(TableSummary $summary): array => $summary->toArray(), array_values($this->summaries)),
			'actions'=>array_map(static function(array $action): array {
				return [
					'label'=>is_string($action['label'] ?? null) ? $action['label'] : null,
					'url'=>is_string($action['url'] ?? null) ? $action['url'] : null,
					'computed_label'=>is_callable($action['label'] ?? null),
					'computed_url'=>is_callable($action['url'] ?? null),
					'tone'=>is_string($action['tone'] ?? null) ? $action['tone'] : 'neutral',
					'icon'=>is_string($action['icon'] ?? null) ? $action['icon'] : null,
				];
			}, $this->actions),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Resolves a possibly-callable action field for one group bucket.
	 *
	 * @param mixed $value Static value or callable field resolver.
	 * @param string $key Resolved group key.
	 * @param array<int, mixed> $records Records in the group.
	 * @param ?Resource $resource Resource context.
	 * @param ?PanelRequest $request Request context.
	 * @param PageTable|ResourceTable|null $table Table context.
	 * @return mixed callback output evaluated with table utilities, or the literal configured value.
	 */
	private function resolveActionValue(mixed $value, string $key, array $records, ?Resource $resource, ?PanelRequest $request, PageTable|ResourceTable|null $table): mixed {
		if(is_callable($value)){
			return PanelUtilityResolver::evaluate(\Closure::fromCallable($value), [
				'key'=>$key,
				'value'=>$key,
				'records'=>$records,
				'resource'=>$resource,
				'request'=>$request,
				'group'=>$this,
				'table'=>$table,
			], ['key', 'records', 'resource', 'request', 'group', 'table']);
		}
		return $value;
	}

	/**
	 * Reads a field value from an array or object record.
	 *
	 * @param mixed $record Record array or object.
	 * @param string $key Field name.
	 * @param mixed $default Value returned when the field cannot be read.
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
	 * Converts a normalized key into a display label.
	 *
	 * @param string $value Normalized group key.
	 * @return string Title-cased label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
