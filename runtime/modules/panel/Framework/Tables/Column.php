<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel table column.
 *
 * Columns capture table identity, display formatting, sorting, searching, visibility, cell metadata, links, icons, colors, and inline editing behavior.
 */
final class Column {
	use PanelExtensible;

	private string $name;
	private string $type;
	private string $label;
	private bool $sortable=false;
	private bool $searchable=false;
	private bool $toggleable=true;
	private bool $visibleByDefault=true;
	private bool $hidden=false;
	private array $visibleOn=[];
	private array $hiddenOn=[];
	private ?string $align=null;
	private ?\Closure $valueResolver=null;
	private ?\Closure $formatter=null;
	private ?\Closure $sortResolver=null;
	private ?\Closure $searchResolver=null;
	private ?\Closure $visibilityCallback=null;
	private ?\Closure $hiddenCallback=null;
	private ?\Closure $descriptionResolver=null;
	private ?\Closure $tooltipResolver=null;
	private ?\Closure $copyResolver=null;
	private ?\Closure $iconResolver=null;
	private ?\Closure $colorResolver=null;
	private ?\Closure $linkResolver=null;
	private ?\Closure $linkNewTabResolver=null;
	private bool $editable=false;
	private string $editableType='';
	private ?\Closure $editableOptionsResolver=null;
	private string|\Closure|null $footerResolver=null;
	/** @var array<int, array<string, mixed>|\Closure> */
	private array $headerAttributes=[];
	/** @var array<int, array<string, mixed>|\Closure> */
	private array $cellAttributes=[];
	private array $meta=[];

	/**
	 * Initializes a table column with normalized manifest identity and type.
	 *
	 * Construction is private so every column passes through make(), configured()
	 * extension hooks, and array-definition normalization before it reaches a table
	 * manifest or renderer.
	 *
	 * @param string $name Raw column field/name.
	 * @param string $type Raw column display type.
	 */
	private function __construct(string $name, string $type='text') {
		$this->name=self::normalizeName($name);
		$this->type=self::normalizeName($type) ?: 'text';
		$this->label=self::humanize($this->name);
	}

	/**
	 * Builds a Panel column definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param string $type Column display type before normalization.
	 * @return self Configured column definition with normalized name, type, and label.
	 */
	public static function make(string $name, string $type='text'): self {
		return self::configured(new self($name, $type));
	}

	/**
	 * Builds a Panel column definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param array<string,mixed> $definition Array manifest/configuration definition.
	 * @return self Column definition hydrated from manifest/configuration data.
	 */
	public static function fromArray(array $definition): self {
		$column=self::make((string)($definition['name'] ?? ''), (string)($definition['type'] ?? 'text'));
		if(isset($definition['label'])){
			$column=$column->label((string)$definition['label']);
		}
		foreach(['sortable', 'searchable', 'toggleable'] as $flag){
			if(array_key_exists($flag, $definition)){
				$column=$column->{$flag}((bool)$definition[$flag]);
			}
		}
		if(array_key_exists('visible_by_default', $definition)){
			$column=$column->visibleByDefault((bool)$definition['visible_by_default']);
		}
		if(!empty($definition['hidden_by_default'])){
			$column=$column->hiddenByDefault();
		}
		if(array_key_exists('hidden', $definition)){
			$column=$column->hidden((bool)$definition['hidden']);
		}
		if(array_key_exists('visible', $definition)){
			$column=$column->visible((bool)$definition['visible']);
		}
		if(isset($definition['visible_on'])){
			$column=$column->visibleOn(is_array($definition['visible_on']) ? $definition['visible_on'] : (string)$definition['visible_on']);
		}
		if(isset($definition['hidden_on'])){
			$column=$column->hiddenOn(is_array($definition['hidden_on']) ? $definition['hidden_on'] : (string)$definition['hidden_on']);
		}
		if(isset($definition['align']) && is_string($definition['align'])){
			$column=$column->align($definition['align']);
		}
		if(isset($definition['group']) && is_string($definition['group'])){
			$column=$column->group($definition['group'], is_string($definition['group_description'] ?? null) ? $definition['group_description'] : null);
		}
		elseif(isset($definition['column_group']) && is_string($definition['column_group'])){
			$column=$column->group($definition['column_group'], is_string($definition['group_description'] ?? null) ? $definition['group_description'] : null);
		}
		if(isset($definition['header_attributes']) && (is_array($definition['header_attributes']) || is_callable($definition['header_attributes']))){
			$column=$column->headerAttributes($definition['header_attributes']);
		}
		if(isset($definition['cell_attributes']) && (is_array($definition['cell_attributes']) || is_callable($definition['cell_attributes']))){
			$column=$column->cellAttributes($definition['cell_attributes']);
		}
		elseif(isset($definition['extra_attributes']) && (is_array($definition['extra_attributes']) || is_callable($definition['extra_attributes']))){
			$column=$column->cellAttributes($definition['extra_attributes']);
		}
		if(isset($definition['description']) && is_string($definition['description'])){
			$column=$column->description($definition['description']);
		}
		if(isset($definition['description_using']) && is_callable($definition['description_using'])){
			$column=$column->descriptionUsing($definition['description_using']);
		}
		if(isset($definition['tooltip']) && (is_string($definition['tooltip']) || is_callable($definition['tooltip']))){
			$column=$column->tooltip($definition['tooltip']);
		}
		if(array_key_exists('copyable', $definition)){
			$column=$column->copyable((bool)$definition['copyable']);
		}
		if(isset($definition['copy_value_using']) && is_callable($definition['copy_value_using'])){
			$column=$column->copyValueUsing($definition['copy_value_using']);
		}
		elseif(isset($definition['copy_using']) && is_callable($definition['copy_using'])){
			$column=$column->copyValueUsing($definition['copy_using']);
		}
		if(isset($definition['copy_message']) && is_string($definition['copy_message'])){
			$column=$column->copyMessage($definition['copy_message']);
		}
		if(isset($definition['icon']) && is_string($definition['icon'])){
			$column=$column->icon($definition['icon']);
		}
		if(isset($definition['icon_using']) && is_callable($definition['icon_using'])){
			$column=$column->iconUsing($definition['icon_using']);
		}
		if(isset($definition['color']) && is_string($definition['color'])){
			$column=$column->color($definition['color']);
		}
		if(isset($definition['color_using']) && is_callable($definition['color_using'])){
			$column=$column->colorUsing($definition['color_using']);
		}
		if(isset($definition['link_to']) || isset($definition['link_url'])){
			$link=$definition['link_to'] ?? $definition['link_url'];
			$column=$column->linkTo($link, $definition['link_new_tab'] ?? false);
		}
		elseif(isset($definition['href'])){
			$column=$column->linkTo($definition['href'], $definition['link_new_tab'] ?? false);
		}
		if(isset($definition['truncate'])){
			$column=$column->truncate((int)$definition['truncate']);
		}
		elseif(isset($definition['limit'])){
			$column=$column->limit((int)$definition['limit']);
		}
		if(array_key_exists('editable', $definition)){
			$column=$column->editable($definition['editable']);
		}
		if(isset($definition['editable_type']) && is_string($definition['editable_type'])){
			$column=$column->editableType($definition['editable_type']);
		}
		if(isset($definition['editable_options']) && (is_array($definition['editable_options']) || is_callable($definition['editable_options']))){
			$column=$column->editableOptions($definition['editable_options']);
		}
		if(array_key_exists('footer', $definition) && (is_string($definition['footer']) || is_callable($definition['footer']) || $definition['footer']===null)){
			$column=$column->footer($definition['footer']);
		}
		if(isset($definition['footer_using']) && is_callable($definition['footer_using'])){
			$column=$column->footerUsing($definition['footer_using']);
		}
		if(array_key_exists('summary', $definition) || array_key_exists('summarize', $definition)){
			$summary=$definition['summary'] ?? $definition['summarize'];
			if(is_array($summary)){
				$column=$column->summarize((string)($summary['type'] ?? 'sum'), is_string($summary['label'] ?? null) ? $summary['label'] : null);
			}
			elseif($summary===true){
				$column=$column->summarize('sum', is_string($definition['summary_label'] ?? null) ? $definition['summary_label'] : null);
			}
			elseif(is_string($summary)){
				$column=$column->summarize($summary, is_string($definition['summary_label'] ?? null) ? $definition['summary_label'] : null);
			}
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$column=$column->meta($definition['meta']);
		}
		return $column;
	}

	/**
	 * Updates the name metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 * @return string Normalized column name used by manifests and record lookup.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Updates the label metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $label User-facing column or footer label.
	 * @return self Cloned column definition with updated label metadata.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Updates the type metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $type Column display type before normalization.
	 * @return self Cloned column definition with updated type metadata.
	 */
	public function type(string $type): self {
		$clone=clone $this;
		$clone->type=self::normalizeName($type) ?: 'text';
		return $clone;
	}

	/**
	 * Configures table sorting or searching for this column.
	 *
	 * Column query metadata lets resource tables expose sortable/searchable behavior without hard-coding SQL in renderers.
	 *
	 * @param bool $sortable Whether table sorting may use this column.
	 * @return self Cloned column definition with updated sortable metadata.
	 */
	public function sortable(bool $sortable=true): self {
		$clone=clone $this;
		$clone->sortable=$sortable;
		return $clone;
	}

	/**
	 * Configures table sorting or searching for this column.
	 *
	 * Column query metadata lets resource tables expose sortable/searchable behavior without hard-coding SQL in renderers.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated sort using metadata.
	 */
	public function sortUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->sortResolver=\Closure::fromCallable($resolver);
		$clone->sortable=true;
		return $clone;
	}

	/**
	 * Configures table sorting or searching for this column.
	 *
	 * Column query metadata lets resource tables expose sortable/searchable behavior without hard-coding SQL in renderers.
	 *
	 * @param bool $searchable Whether table search may include this column.
	 * @return self Cloned column definition with updated searchable metadata.
	 */
	public function searchable(bool $searchable=true): self {
		$clone=clone $this;
		$clone->searchable=$searchable;
		return $clone;
	}

	/**
	 * Configures table sorting or searching for this column.
	 *
	 * Column query metadata lets resource tables expose sortable/searchable behavior without hard-coding SQL in renderers.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated search using metadata.
	 */
	public function searchUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->searchResolver=\Closure::fromCallable($resolver);
		$clone->searchable=true;
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param bool $toggleable Whether users may toggle this column from table controls.
	 * @return self Cloned column definition with updated toggleable metadata.
	 */
	public function toggleable(bool $toggleable=true): self {
		$clone=clone $this;
		$clone->toggleable=$toggleable;
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param bool $visible Whether the column is included before user toggles or operation rules apply.
	 * @return self Cloned column definition with updated visible by default metadata.
	 */
	public function visibleByDefault(bool $visible=true): self {
		$clone=clone $this;
		$clone->visibleByDefault=$visible;
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param bool $hidden Whether the column should be omitted from the default visible set.
	 * @return self Cloned column definition with updated hidden by default metadata.
	 */
	public function hiddenByDefault(bool $hidden=true): self {
		return $this->visibleByDefault(!$hidden);
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param bool|callable $visible Static visibility flag or request/record-aware visibility resolver.
	 * @return self Cloned column definition with updated visible metadata.
	 */
	public function visible(bool|callable $visible=true): self {
		if(is_callable($visible) && !is_bool($visible)){
			return $this->visibleUsing($visible);
		}
		$clone=clone $this;
		$clone->hidden=!$visible;
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param bool|callable $hidden Static hidden flag or request/record-aware hidden resolver.
	 * @return self Cloned column definition with updated hidden metadata.
	 */
	public function hidden(bool|callable $hidden=true): self {
		if(is_callable($hidden) && !is_bool($hidden)){
			return $this->hiddenUsing($hidden);
		}
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param callable $callback Resolver or lifecycle callback.
	 * @return self Cloned column definition with updated visible using metadata.
	 */
	public function visibleUsing(callable $callback): self {
		$clone=clone $this;
		$clone->visibilityCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param callable $callback Resolver or lifecycle callback.
	 * @return self Cloned column definition with updated hidden using metadata.
	 */
	public function hiddenUsing(callable $callback): self {
		$clone=clone $this;
		$clone->hiddenCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self Cloned column definition with updated visible on metadata.
	 */
	public function visibleOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->visibleOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Updates the only on metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self Cloned column definition with updated only on metadata.
	 */
	public function onlyOn(array|string ...$operations): self {
		return $this->visibleOn(...$operations);
	}

	/**
	 * Configures column visibility and toggling behavior.
	 *
	 * Visibility metadata controls default table columns, operation-specific display, and user-toggle availability.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self Cloned column definition with updated hidden on metadata.
	 */
	public function hiddenOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->hiddenOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Updates the except on metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self Cloned column definition with updated except on metadata.
	 */
	public function exceptOn(array|string ...$operations): self {
		return $this->hiddenOn(...$operations);
	}

	/**
	 * Updates the align metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $align Cell alignment token consumed by renderers.
	 * @return self Cloned column definition with updated align metadata.
	 */
	public function align(string $align): self {
		$align=strtolower(trim($align));
		$clone=clone $this;
		$clone->align=in_array($align, ['left', 'center', 'right'], true) ? $align : null;
		return $clone;
	}

	/**
	 * Updates the group metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $label User-facing column or footer label.
	 * @param ?string $description Description.
	 * @return self Cloned column definition with updated group metadata.
	 */
	public function group(string $label, ?string $description=null): self {
		$label=trim($label);
		$meta=['group'=>$label];
		if($description!==null && trim($description)!==''){
			$meta['group_description']=trim($description);
		}
		return $this->meta($meta);
	}

	/**
	 * Updates the column group metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $label User-facing column or footer label.
	 * @param ?string $description Description.
	 * @return self Cloned column definition with updated column group metadata.
	 */
	public function columnGroup(string $label, ?string $description=null): self {
		return $this->group($label, $description);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $formatter Row-aware callback that converts the resolved cell value into display content.
	 * @return self Cloned column definition with updated format metadata.
	 */
	public function format(callable $formatter): self {
		$clone=clone $this;
		$clone->formatter=\Closure::fromCallable($formatter);
		return $clone;
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated value using metadata.
	 */
	public function valueUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->valueResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Updates the state using metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated state using metadata.
	 */
	public function stateUsing(callable $resolver): self {
		return $this->valueUsing($resolver);
	}

	/**
	 * Resolves the value for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $default Fallback value when the record has no column value.
	 * @return mixed Column hook, resolver result, record value, or supplied default.
	 */
	public function resolveValue(mixed $record=null, mixed $default=''): mixed {
		if(PanelComponentRegistry::columnTypeHasHook($this->type, 'value')){
			return PanelComponentRegistry::callColumnTypeHook($this->type, 'value', $this, $record, $default);
		}
		if($this->valueResolver!==null){
			return PanelUtilityResolver::evaluate($this->valueResolver, [
				'record'=>$record,
				'column'=>$this,
				'default'=>$default,
			], ['record', 'column']);
		}
		return self::recordValue($record, $this->name, $default);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $record Current table row record or model.
	 * @return mixed Formatted display value after type hook, formatter callback, or built-in formatting.
	 */
	public function formatValue(mixed $value, mixed $record=null): mixed {
		if(PanelComponentRegistry::columnTypeHasHook($this->type, 'format')){
			return PanelComponentRegistry::callColumnTypeHook($this->type, 'format', $this, $value, $record);
		}
		if($this->formatter!==null){
			return PanelUtilityResolver::evaluate($this->formatter, [
				'value'=>$value,
				'record'=>$record,
				'column'=>$this,
			], ['value', 'record', 'column']);
		}
		return $this->formatBuiltIn($value);
	}

	/**
	 * Resolves the value exported for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @return mixed Export hook result, formatted value, or resolved raw value.
	 */
	public function exportValue(mixed $record=null): mixed {
		$value=$this->resolveValue($record);
		if(PanelComponentRegistry::columnTypeHasHook($this->type, 'export')){
			return PanelComponentRegistry::callColumnTypeHook($this->type, 'export', $this, $value, $record);
		}
		return $this->formatValue($value, $record);
	}

	/**
	 * Updates the money metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $currency Currency code stored in column metadata.
	 * @return self Cloned column definition with updated money metadata.
	 */
	public function money(string $currency=''): self {
		return $this->type('money')->meta(['currency'=>trim($currency)]);
	}

	/**
	 * Updates the date metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $format Date/time format string used by built-in formatting.
	 * @return self Cloned column definition with updated date metadata.
	 */
	public function date(string $format='Y-m-d'): self {
		return $this->type('date')->meta(['format'=>$format]);
	}

	/**
	 * Updates the datetime metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $format Date/time format string used by built-in formatting.
	 * @return self Cloned column definition with updated datetime metadata.
	 */
	public function datetime(string $format='Y-m-d H:i'): self {
		return $this->type('datetime')->meta(['format'=>$format]);
	}

	/**
	 * Updates the boolean labels metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $true Label displayed for truthy boolean values.
	 * @param string $false Label displayed for falsey boolean values.
	 * @return self Cloned column definition with updated boolean labels metadata.
	 */
	public function booleanLabels(string $true='Yes', string $false='No'): self {
		return $this->type('boolean')->meta([
			'true_label'=>$true,
			'false_label'=>$false,
		]);
	}

	/**
	 * Updates the badge metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|string $tones Tones.
	 * @return self Cloned column definition with updated badge metadata.
	 */
	public function badge(array|string $tones=[]): self {
		$meta=[];
		if(is_string($tones) && trim($tones)!==''){
			$meta['tone']=$tones;
		}
		elseif(is_array($tones) && $tones!==[]){
			$meta['tones']=$tones;
		}
		return $this->type('badge')->meta($meta);
	}

	/**
	 * Updates the url metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $labelColumn LabelColumn.
	 * @return self Cloned column definition with updated url metadata.
	 */
	public function url(?string $labelColumn=null): self {
		$meta=[];
		if($labelColumn!==null && trim($labelColumn)!==''){
			$meta['label_column']=self::normalizeName($labelColumn);
		}
		return $this->type('url')->meta($meta);
	}

	/**
	 * Updates the email metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 * @return self Cloned column definition with updated email metadata.
	 */
	public function email(): self {
		return $this->type('email');
	}

	/**
	 * Updates the truncate metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param int $characters Maximum number of characters before truncation.
	 * @return self Cloned column definition with updated truncate metadata.
	 */
	public function truncate(int $characters=80): self {
		return $this->meta(['truncate'=>max(1, $characters)]);
	}

	/**
	 * Updates the limit metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param int $characters Maximum number of characters before truncation.
	 * @return self Cloned column definition with updated limit metadata.
	 */
	public function limit(int $characters=80): self {
		return $this->truncate($characters);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param string $description Static row description text.
	 * @return self Cloned column definition with updated description metadata.
	 */
	public function description(string $description): self {
		return $this->meta(['description'=>trim($description)]);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated description using metadata.
	 */
	public function descriptionUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->descriptionResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Updates the copyable metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param bool $copyable Whether the rendered value can be copied.
	 * @return self Cloned column definition with updated copyable metadata.
	 */
	public function copyable(bool $copyable=true): self {
		return $this->meta(['copyable'=>$copyable]);
	}

	/**
	 * Updates the copy value using metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated copy value using metadata.
	 */
	public function copyValueUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->copyResolver=\Closure::fromCallable($resolver);
		$clone->meta['copyable']=true;
		return $clone;
	}

	/**
	 * Updates the copy message metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $message Copy confirmation message shown by the renderer.
	 * @return self Cloned column definition with updated copy message metadata.
	 */
	public function copyMessage(string $message): self {
		return $this->meta(['copy_message'=>trim($message)]);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param string|callable $tooltip Static tooltip text or row-aware resolver.
	 * @return self Cloned column definition with updated tooltip metadata.
	 */
	public function tooltip(string|callable $tooltip): self {
		if(is_callable($tooltip) && !is_string($tooltip)){
			return $this->tooltipUsing($tooltip);
		}
		return $this->meta(['tooltip'=>trim((string)$tooltip)]);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated tooltip using metadata.
	 */
	public function tooltipUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->tooltipResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param string $icon Static icon name rendered with the cell.
	 * @return self Cloned column definition with updated icon metadata.
	 */
	public function icon(string $icon): self {
		return $this->meta(['icon'=>trim($icon)]);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated icon using metadata.
	 */
	public function iconUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->iconResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param string $color Static color token rendered with the cell.
	 * @return self Cloned column definition with updated color metadata.
	 */
	public function color(string $color): self {
		return $this->meta(['color'=>trim($color)]);
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated color using metadata.
	 */
	public function colorUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->colorResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Configures rendered cell value and decoration.
	 *
	 * Resolvers produce display values, descriptions, tooltips, icons, colors, links, copy state, and formatting metadata for each row.
	 *
	 * @param mixed $url Static URL, URL-like value, or callback resolved per row.
	 * @param mixed $newTab Boolean flag or callback deciding link target behavior.
	 * @return self Cloned column definition with updated link to metadata.
	 */
	public function linkTo(mixed $url, mixed $newTab=false): self {
		$clone=clone $this;
		if(is_callable($url) && !is_string($url)){
			$clone->linkResolver=\Closure::fromCallable($url);
			unset($clone->meta['link_url']);
		}
		else {
			$clone->linkResolver=null;
			$clone->meta['link_url']=is_scalar($url) || $url instanceof \Stringable ? trim((string)$url) : '';
		}
		return $clone->openInNewTab($newTab);
	}

	/**
	 * Updates the href metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $url Static URL, URL-like value, or callback resolved per row.
	 * @param mixed $newTab Boolean flag or callback deciding link target behavior.
	 * @return self Cloned column definition with updated href metadata.
	 */
	public function href(mixed $url, mixed $newTab=false): self {
		return $this->linkTo($url, $newTab);
	}

	/**
	 * Updates the open in new tab metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $newTab Boolean flag or callback deciding link target behavior.
	 * @return self Cloned column definition with updated open in new tab metadata.
	 */
	public function openInNewTab(mixed $newTab=true): self {
		$clone=clone $this;
		if(is_callable($newTab) && !is_bool($newTab)){
			$clone->linkNewTabResolver=\Closure::fromCallable($newTab);
			unset($clone->meta['link_new_tab']);
		}
		else {
			$clone->linkNewTabResolver=null;
			$clone->meta['link_new_tab']=(bool)$newTab;
		}
		return $clone;
	}

	/**
	 * Updates the editable metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param bool|string $editable Inline-edit flag or edit input type.
	 * @param array|callable|null $options Additional manifest options.
	 * @return self Cloned column definition with updated editable metadata.
	 */
	public function editable(bool|string $editable=true, array|callable|null $options=null): self {
		$clone=clone $this;
		if(is_string($editable)){
			$type=self::normalizeName($editable);
			$clone->editable=$type!=='';
			$clone->editableType=$type;
		}
		else {
			$clone->editable=$editable;
		}
		if($options!==null){
			$clone=$clone->editableOptions($options);
		}
		return $clone;
	}

	/**
	 * Updates the inline editable metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param bool|string $editable Inline-edit flag or edit input type.
	 * @param array|callable|null $options Additional manifest options.
	 * @return self Cloned column definition with updated inline editable metadata.
	 */
	public function inlineEditable(bool|string $editable=true, array|callable|null $options=null): self {
		return $this->editable($editable, $options);
	}

	/**
	 * Updates the editable type metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $type Column display type before normalization.
	 * @return self Cloned column definition with updated editable type metadata.
	 */
	public function editableType(string $type): self {
		$clone=clone $this;
		$clone->editableType=self::normalizeName($type);
		$clone->editable=$clone->editable || $clone->editableType!=='';
		return $clone;
	}

	/**
	 * Updates the editable options metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|callable $options Additional manifest options.
	 * @return self Cloned column definition with updated editable options metadata.
	 */
	public function editableOptions(array|callable $options): self {
		$clone=clone $this;
		if(is_callable($options) && !is_array($options)){
			$clone->editableOptionsResolver=\Closure::fromCallable($options);
			unset($clone->meta['editable_options']);
		}
		else {
			$clone->editableOptionsResolver=null;
			$clone->meta['editable_options']=$options;
		}
		$clone->editable=true;
		if($clone->editableType===''){
			$clone->editableType='select';
		}
		return $clone;
	}

	/**
	 * Updates the footer metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string|callable|null $footer Footer.
	 * @return self Cloned column definition with updated footer metadata.
	 */
	public function footer(string|callable|null $footer): self {
		$clone=clone $this;
		if(is_callable($footer) && !is_string($footer)){
			$clone->footerResolver=\Closure::fromCallable($footer);
		}
		else {
			$clone->footerResolver=$footer===null ? null : trim((string)$footer);
		}
		return $clone;
	}

	/**
	 * Updates the footer using metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param callable $resolver Row-aware resolver callback.
	 * @return self Cloned column definition with updated footer using metadata.
	 */
	public function footerUsing(callable $resolver): self {
		return $this->footer($resolver);
	}

	/**
	 * Updates the summarize metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $type Column display type before normalization.
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated summarize metadata.
	 */
	public function summarize(string $type='sum', ?string $label=null): self {
		$type=self::normalizeName($type) ?: 'sum';
		if(!in_array($type, ['sum', 'avg', 'average', 'min', 'max', 'count'], true)){
			$type='sum';
		}
		$meta=['summary'=>$type];
		if($label!==null && trim($label)!==''){
			$meta['summary_label']=trim($label);
		}
		return $this->meta($meta);
	}

	/**
	 * Updates the sum metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated sum metadata.
	 */
	public function sum(?string $label=null): self {
		return $this->summarize('sum', $label);
	}

	/**
	 * Updates the avg metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated avg metadata.
	 */
	public function avg(?string $label=null): self {
		return $this->summarize('avg', $label);
	}

	/**
	 * Updates the average metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated average metadata.
	 */
	public function average(?string $label=null): self {
		return $this->summarize('average', $label);
	}

	/**
	 * Updates the min metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated min metadata.
	 */
	public function min(?string $label=null): self {
		return $this->summarize('min', $label);
	}

	/**
	 * Updates the max metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated max metadata.
	 */
	public function max(?string $label=null): self {
		return $this->summarize('max', $label);
	}

	/**
	 * Updates the count metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?string $label Label.
	 * @return self Cloned column definition with updated count metadata.
	 */
	public function count(?string $label=null): self {
		return $this->summarize('count', $label);
	}

	/**
	 * Updates the header attributes metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|callable $attributes Static header attributes or resolver returning header attributes.
	 * @param bool $merge Whether to append to existing header attribute layers.
	 * @return self Cloned column definition with updated header attributes metadata.
	 */
	public function headerAttributes(array|callable $attributes, bool $merge=true): self {
		$clone=clone $this;
		if(!$merge){
			$clone->headerAttributes=[];
		}
		$clone->headerAttributes[]=is_array($attributes) ? self::normalizeExtraAttributes($attributes) : \Closure::fromCallable($attributes);
		return $clone;
	}

	/**
	 * Updates the cell attributes metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|callable $attributes Static cell attributes or resolver returning row-aware cell attributes.
	 * @param bool $merge Whether to append to existing cell attribute layers.
	 * @return self Cloned column definition with updated cell attributes metadata.
	 */
	public function cellAttributes(array|callable $attributes, bool $merge=true): self {
		$clone=clone $this;
		if(!$merge){
			$clone->cellAttributes=[];
		}
		$clone->cellAttributes[]=is_array($attributes) ? self::normalizeExtraAttributes($attributes) : \Closure::fromCallable($attributes);
		return $clone;
	}

	/**
	 * Updates the extra attributes metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|callable $attributes Static cell attributes or resolver returning row-aware cell attributes.
	 * @param bool $merge Whether to append to existing cell attribute layers.
	 * @return self Cloned column definition with updated extra attributes metadata.
	 */
	public function extraAttributes(array|callable $attributes, bool $merge=true): self {
		return $this->cellAttributes($attributes, $merge);
	}

	/**
	 * Updates the attributes metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array|callable $attributes Static cell attributes or resolver returning row-aware cell attributes.
	 * @param bool $merge Whether to append to existing cell attribute layers.
	 * @return self Cloned column definition with updated attributes metadata.
	 */
	public function attributes(array|callable $attributes, bool $merge=true): self {
		return $this->cellAttributes($attributes, $merge);
	}

	/**
	 * Updates the header attribute metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated header attribute metadata.
	 */
	public function headerAttribute(string $name, mixed $value=true): self {
		return $this->headerAttributes([$name=>$value]);
	}

	/**
	 * Updates the cell attribute metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated cell attribute metadata.
	 */
	public function cellAttribute(string $name, mixed $value=true): self {
		return $this->cellAttributes([$name=>$value]);
	}

	/**
	 * Updates the header data metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated header data metadata.
	 */
	public function headerData(string $name, mixed $value=true): self {
		return $this->headerAttribute('data-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the cell data metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated cell data metadata.
	 */
	public function cellData(string $name, mixed $value=true): self {
		return $this->cellAttribute('data-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the header aria metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated header aria metadata.
	 */
	public function headerAria(string $name, mixed $value=true): self {
		return $this->headerAttribute('aria-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the cell aria metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned column definition with updated cell aria metadata.
	 */
	public function cellAria(string $name, mixed $value=true): self {
		return $this->cellAttribute('aria-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the meta metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param array<string,mixed> $meta Metadata merged into the column manifest.
	 * @return self Cloned column definition with updated meta metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Updates the to array metadata for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 * @return array<string,mixed> Renderer-facing column manifest with display, query, visibility, editing, footer, and attribute metadata.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'sortable'=>$this->sortable,
			'searchable'=>$this->searchable,
			'toggleable'=>$this->toggleable,
			'visible_by_default'=>$this->visibleByDefault,
			'hidden'=>$this->hidden,
			'visible_on'=>$this->visibleOn,
			'hidden_on'=>$this->hiddenOn,
			'conditional'=>$this->visibilityCallback!==null || $this->hiddenCallback!==null || $this->visibleOn!==[] || $this->hiddenOn!==[] || $this->hidden,
			'align'=>$this->align,
			'group'=>is_string($this->meta['group'] ?? null) ? $this->meta['group'] : '',
			'group_description'=>is_string($this->meta['group_description'] ?? null) ? $this->meta['group_description'] : '',
			'computed'=>$this->valueResolver!==null,
			'formatted'=>$this->formatter!==null,
			'computed_sort'=>$this->sortResolver!==null,
			'computed_search'=>$this->searchResolver!==null,
			'described'=>$this->descriptionResolver!==null || trim((string)($this->meta['description'] ?? ''))!=='',
			'computed_tooltip'=>$this->tooltipResolver!==null,
			'copyable'=>($this->meta['copyable'] ?? false)===true,
			'computed_copy'=>$this->copyResolver!==null,
			'computed_icon'=>$this->iconResolver!==null,
			'computed_color'=>$this->colorResolver!==null,
			'linked'=>$this->linkResolver!==null || trim((string)($this->meta['link_url'] ?? ''))!=='',
			'computed_link'=>$this->linkResolver!==null,
			'editable'=>$this->editable,
			'editable_type'=>$this->editableInputType(),
			'editable_options'=>is_array($this->meta['editable_options'] ?? null) ? $this->meta['editable_options'] : [],
			'editable_options_dynamic'=>$this->editableOptionsResolver!==null,
			'footer'=>is_string($this->footerResolver) ? $this->footerResolver : '',
			'footer_dynamic'=>$this->footerResolver instanceof \Closure,
			'summary'=>is_string($this->meta['summary'] ?? null) ? $this->meta['summary'] : '',
			'summary_label'=>is_string($this->meta['summary_label'] ?? null) ? $this->meta['summary_label'] : '',
			'header_attributes'=>$this->staticExtraAttributes($this->headerAttributes),
			'header_attributes_dynamic'=>$this->hasDynamicExtraAttributes($this->headerAttributes),
			'cell_attributes'=>$this->staticExtraAttributes($this->cellAttributes),
			'cell_attributes_dynamic'=>$this->hasDynamicExtraAttributes($this->cellAttributes),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Resolves the header attributes for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return array<string,string> HTML-safe header attributes merged from static and callback sources.
	 */
	public function resolveHeaderAttributes(?PanelRequest $request=null, mixed $resource=null, mixed $table=null): array {
		return $this->resolveExtraAttributes($this->headerAttributes, [
			'request'=>$request,
			'column'=>$this,
			'resource'=>$resource,
			'table'=>$table,
		], ['request', 'column', 'resource', 'table']);
	}

	/**
	 * Resolves the cell attributes for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return array<string,string> HTML-safe cell attributes merged for the current record/value context.
	 */
	public function resolveCellAttributes(mixed $record=null, mixed $value=null, mixed $formatted=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): array {
		return $this->resolveExtraAttributes($this->cellAttributes, [
			'record'=>$record,
			'value'=>$value,
			'state'=>$value,
			'formatted'=>$formatted,
			'request'=>$request,
			'column'=>$this,
			'resource'=>$resource,
			'table'=>$table,
		], ['record', 'value', 'formatted', 'request', 'column', 'resource', 'table']);
	}

	/**
	 * Evaluates whether the column is editable in the current table context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return bool True when inline editing is enabled for the current request context.
	 */
	public function isEditable(mixed $record=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): bool {
		if($this->editable===false){
			return false;
		}
		if($this->valueResolver!==null){
			return false;
		}
		if($request instanceof PanelRequest && !in_array($request->operation(), ['index', 'inline_update'], true)){
			return false;
		}
		return true;
	}

	/**
	 * Resolves the inline-edit input type for this column.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 * @return string Input type used by inline-edit renderers.
	 */
	public function editableInputType(): string {
		$type=$this->editableType!=='' ? $this->editableType : $this->type;
		$type=self::normalizeName($type) ?: 'text';
		return match($type){
			'badge', 'url', 'email', 'money', 'date', 'datetime'=>'text',
			'boolean'=>'checkbox',
			default=>$type,
		};
	}

	/**
	 * Resolves the editable options for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return array<string,string> Normalized editable options keyed by submitted value.
	 */
	public function resolveEditableOptions(mixed $record=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): array {
		$options=$this->meta['editable_options'] ?? [];
		if($this->editableOptionsResolver!==null){
			$result=PanelUtilityResolver::evaluate($this->editableOptionsResolver, [
				'record'=>$record,
				'request'=>$request,
				'column'=>$this,
				'resource'=>$resource,
				'table'=>$table,
			], ['record', 'request', 'column', 'resource', 'table']);
			$options=is_array($result) ? $result : [];
		}
		$normalized=[];
		foreach($options as $key=>$value){
			if(is_array($value)){
				$optionValue=$value['value'] ?? $key;
				$optionLabel=$value['label'] ?? $optionValue;
			}
			else {
				$optionValue=is_int($key) ? $value : $key;
				$optionLabel=$value;
			}
			if(is_scalar($optionValue) || $optionValue instanceof \Stringable){
				$normalized[(string)$optionValue]=is_scalar($optionLabel) || $optionLabel instanceof \Stringable ? (string)$optionLabel : (string)$optionValue;
			}
		}
		return $normalized;
	}

	/**
	 * Resolves the description for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved row description text, or an empty string.
	 */
	public function resolveDescription(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->descriptionResolver!==null){
			$description=PanelUtilityResolver::evaluate($this->descriptionResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($description) || $description instanceof \Stringable ? trim((string)$description) : '';
		}
		return trim((string)($this->meta['description'] ?? ''));
	}

	/**
	 * Resolves the tooltip for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved row tooltip text, or an empty string.
	 */
	public function resolveTooltip(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->tooltipResolver!==null){
			$tooltip=PanelUtilityResolver::evaluate($this->tooltipResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($tooltip) || $tooltip instanceof \Stringable ? trim((string)$tooltip) : '';
		}
		return trim((string)($this->meta['tooltip'] ?? ''));
	}

	/**
	 * Resolves the copy value for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved copy payload, formatted value, or an empty string.
	 */
	public function resolveCopyValue(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->copyResolver!==null){
			$copy=PanelUtilityResolver::evaluate($this->copyResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($copy) || $copy instanceof \Stringable ? trim((string)$copy) : '';
		}
		if($value!==null && $value!=='' && (is_scalar($value) || $value instanceof \Stringable)){
			return trim((string)$value);
		}
		return is_scalar($formatted) || $formatted instanceof \Stringable ? trim((string)$formatted) : '';
	}

	/**
	 * Resolves the icon for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved icon name, or an empty string.
	 */
	public function resolveIcon(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->iconResolver!==null){
			$icon=PanelUtilityResolver::evaluate($this->iconResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($icon) || $icon instanceof \Stringable ? trim((string)$icon) : '';
		}
		return trim((string)($this->meta['icon'] ?? ''));
	}

	/**
	 * Resolves the color for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved color token, or an empty string.
	 */
	public function resolveColor(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->colorResolver!==null){
			$color=PanelUtilityResolver::evaluate($this->colorResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($color) || $color instanceof \Stringable ? trim((string)$color) : '';
		}
		return trim((string)($this->meta['color'] ?? ''));
	}

	/**
	 * Resolves the link url for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return string Resolved link URL, or an empty string.
	 */
	public function resolveLinkUrl(mixed $record=null, mixed $value=null, mixed $formatted=null): string {
		if($this->linkResolver!==null){
			$url=PanelUtilityResolver::evaluate($this->linkResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return is_scalar($url) || $url instanceof \Stringable ? trim((string)$url) : '';
		}
		return trim((string)($this->meta['link_url'] ?? ''));
	}

	/**
	 * Resolves the link new tab for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param mixed $value Manifest value or resolver input.
	 * @param mixed $formatted Formatted cell value already prepared for display.
	 * @return bool True when the resolved link should open in a new tab.
	 */
	public function resolveLinkNewTab(mixed $record=null, mixed $value=null, mixed $formatted=null): bool {
		if($this->linkNewTabResolver!==null){
			$newTab=PanelUtilityResolver::evaluate($this->linkNewTabResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'formatted'=>$formatted,
				'column'=>$this,
			], ['record', 'value', 'formatted', 'column']);
			return $this->truthy($newTab);
		}
		return ($this->meta['link_new_tab'] ?? false)===true;
	}

	/**
	 * Resolves the footer for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param list<mixed> $records Records used for aggregate footer values.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return array{label:string,value:string,type:string} Resolved footer payload.
	 */
	public function resolveFooter(array $records=[], ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): array {
		if($this->footerResolver instanceof \Closure){
			$footer=PanelUtilityResolver::evaluate($this->footerResolver, [
				'records'=>$records,
				'request'=>$request,
				'column'=>$this,
				'resource'=>$resource,
				'table'=>$table,
			], ['records', 'request', 'column', 'resource', 'table']);
			if(is_array($footer)){
				return [
					'label'=>trim((string)($footer['label'] ?? '')),
					'value'=>$this->footerString($footer['value'] ?? ''),
					'type'=>trim((string)($footer['type'] ?? 'custom')),
				];
			}
			return [
				'label'=>'',
				'value'=>$this->footerString($footer),
				'type'=>'custom',
			];
		}
		if(is_string($this->footerResolver) && $this->footerResolver!==''){
			return [
				'label'=>'',
				'value'=>$this->footerResolver,
				'type'=>'custom',
			];
		}
		$type=self::normalizeName((string)($this->meta['summary'] ?? ''));
		if($type===''){
			return ['label'=>'', 'value'=>'', 'type'=>''];
		}
		$numbers=[];
		foreach($records as $record){
			$value=$this->resolveValue($record);
			if(is_numeric($value)){
				$numbers[]=(float)$value;
			}
		}
		$value=null;
		switch($type){
			case 'count':
				$value=count($records);
				break;
			case 'avg':
			case 'average':
				$value=$numbers===[] ? null : array_sum($numbers)/count($numbers);
				break;
			case 'min':
				$value=$numbers===[] ? null : min($numbers);
				break;
			case 'max':
				$value=$numbers===[] ? null : max($numbers);
				break;
			default:
				$type='sum';
				$value=$numbers===[] ? null : array_sum($numbers);
				break;
		}
		if($value===null){
			return ['label'=>trim((string)($this->meta['summary_label'] ?? '')), 'value'=>'', 'type'=>$type];
		}
		if($type==='count'){
			$formatted=number_format((int)$value);
		}
		else {
			try {
				$formatted=$this->formatValue($value, null);
			}
			catch(\Throwable){
				$formatted=number_format((float)$value, 2);
			}
		}
		return [
			'label'=>trim((string)($this->meta['summary_label'] ?? '')),
			'value'=>$this->footerString($formatted),
			'type'=>$type,
		];
	}

	/**
	 * Evaluates whether the column is visible in the current table context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param string $operation Table operation or view mode used for visibility checks.
	 * @param mixed $record Current table row record or model.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return bool True when operation filters and visibility callbacks allow the column.
	 */
	public function isVisible(string $operation='table', mixed $record=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): bool {
		$operation=self::normalizeOperation($operation);
		if($this->visibleOn!==[] && !in_array($operation, $this->visibleOn, true)){
			return false;
		}
		if(in_array($operation, $this->hiddenOn, true)){
			return false;
		}
		if($this->hidden){
			return false;
		}
		$values=[
			'operation'=>$operation,
			'mode'=>$operation,
			'record'=>$record,
			'request'=>$request,
			'column'=>$this,
			'resource'=>$resource,
			'table'=>$table,
		];
		$order=['operation', 'record', 'request', 'column', 'resource', 'table'];
		if($this->visibilityCallback!==null && (bool)PanelUtilityResolver::evaluate($this->visibilityCallback, $values, $order)===false){
			return false;
		}
		if($this->hiddenCallback!==null && (bool)PanelUtilityResolver::evaluate($this->hiddenCallback, $values, $order)===true){
			return false;
		}
		return true;
	}

	/**
	 * Resolves the sort value for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return mixed Sort resolver result, or the resolved column value.
	 */
	public function resolveSortValue(mixed $record=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): mixed {
		$value=$this->resolveValue($record);
		if($this->sortResolver!==null){
			return PanelUtilityResolver::evaluate($this->sortResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'request'=>$request,
				'column'=>$this,
				'resource'=>$resource,
				'table'=>$table,
			], ['record', 'value', 'request', 'column', 'resource', 'table']);
		}
		return $value;
	}

	/**
	 * Compares two records using this column's sort value.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $left Left-side record being compared.
	 * @param mixed $right Right-side record being compared.
	 * @param string $direction Sort direction, usually asc or desc.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return int Negative, zero, or positive comparison result with direction applied.
	 */
	public function compareForSort(mixed $left, mixed $right, string $direction='asc', ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): int {
		$result=self::compareValues(
			$this->resolveSortValue($left, $request, $resource, $table),
			$this->resolveSortValue($right, $request, $resource, $table)
		);
		return strtolower($direction)==='desc' ? -$result : $result;
	}

	/**
	 * Resolves the search value for the current row context.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return mixed Search resolver result, or the resolved column value.
	 */
	public function resolveSearchValue(mixed $record=null, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): mixed {
		$value=$this->resolveValue($record);
		if($this->searchResolver!==null){
			return PanelUtilityResolver::evaluate($this->searchResolver, [
				'record'=>$record,
				'value'=>$value,
				'state'=>$value,
				'request'=>$request,
				'column'=>$this,
				'resource'=>$resource,
				'table'=>$table,
			], ['record', 'value', 'request', 'column', 'resource', 'table']);
		}
		return $value;
	}

	/**
	 * Evaluates whether this column matches the search query.
	 *
	 * Column metadata feeds table manifests, renderers, filters, summaries, and inline editing.
	 *
	 * @param mixed $record Current table row record or model.
	 * @param string $query Trimmed search query.
	 * @param ?PanelRequest $request Panel request supplying operation, user, and table context.
	 * @param mixed $resource Owning resource passed to table callbacks.
	 * @param mixed $table Table instance or table state passed to callbacks.
	 * @return bool True when any searchable string contains the query.
	 */
	public function matchesSearch(mixed $record, string $query, ?PanelRequest $request=null, mixed $resource=null, mixed $table=null): bool {
		$query=trim($query);
		if($query===''){
			return true;
		}
		foreach(self::searchableStrings($this->resolveSearchValue($record, $request, $resource, $table)) as $value){
			if(stripos($value, $query)!==false){
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes a column, type, operation, or metadata identifier.
	 *
	 * Names are lowercased and restricted to alphanumeric characters plus dot,
	 * underscore, and dash. Invalid runs collapse to underscores so field paths
	 * remain renderer-safe and predictable in manifests.
	 *
	 * @param string $name Raw identifier.
	 * @return string Normalized identifier, or an empty string.
	 */
	private static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.-]+/', '_', $name) ?? '';
		return trim($name, '_.-');
	}

	/**
	 * Normalizes a table operation name with the table fallback.
	 *
	 * @param string $operation Raw operation name.
	 * @return string Normalized operation name, defaulting to table.
	 */
	private static function normalizeOperation(string $operation): string {
		return self::normalizeName($operation) ?: 'table';
	}

	/**
	 * Flattens and normalizes operation lists for visibility rules.
	 *
	 * Visibility declarations accept variadic strings or arrays. Duplicates and
	 * empty entries are removed so manifest operation gates stay compact and
	 * deterministic.
	 *
	 * @param array<int,mixed> $operations Raw variadic operation arguments.
	 * @return array<int,string> Unique normalized operation names.
	 */
	private static function normalizeOperations(array $operations): array {
		$flat=[];
		foreach($operations as $operation){
			if(is_array($operation)){
				array_push($flat, ...$operation);
			}
			else {
				$flat[]=$operation;
			}
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $operation): string => self::normalizeOperation((string)$operation),
			$flat
		))));
	}

	/**
	 * Reads a field value from arrays, objects, or getter methods.
	 *
	 * Array records use direct key access. Object records first check public
	 * properties, then a conventional `getStudlyName()` method. Missing values
	 * return the provided default without throwing.
	 *
	 * @param mixed $record Table row record.
	 * @param string $key Normalized column key.
	 * @param mixed $default Fallback value.
	 * @return mixed Array value, public property, getter result, or the supplied default when unavailable.
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
	 * Compares two values using table-sort semantics.
	 *
	 * Date/time values, booleans, numerics, strings, arrays, and objects are first
	 * normalized into sortable scalar representations. Numeric comparisons use
	 * numeric ordering; all other values use natural case-insensitive ordering.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 * @return int Comparison result compatible with usort().
	 */
	private static function compareValues(mixed $left, mixed $right): int {
		$left=self::normalizeComparableValue($left);
		$right=self::normalizeComparableValue($right);
		if(is_int($left) || is_float($left) || is_int($right) || is_float($right)){
			return (float)$left <=> (float)$right;
		}
		return strnatcasecmp((string)$left, (string)$right);
	}

	/**
	 * Converts a value into a stable scalar for comparison.
	 *
	 * Date/time values become timestamps, strings that look numeric become floats,
	 * true becomes 1, null/false become empty strings, and structured values become
	 * JSON so sorting remains deterministic without exposing object internals.
	 *
	 * @param mixed $value Raw column value.
	 * @return int|float|string Comparable scalar used by table sorting.
	 */
	private static function normalizeComparableValue(mixed $value): mixed {
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		if($value===null || $value===false){
			return '';
		}
		if($value===true){
			return 1;
		}
		if(is_int($value) || is_float($value)){
			return $value;
		}
		if(is_string($value)){
			$value=trim($value);
			return is_numeric($value) ? (float)$value : $value;
		}
		if(is_scalar($value) || $value instanceof \Stringable){
			return (string)$value;
		}
		if(is_array($value) || is_object($value)){
			return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
		}
		return '';
	}

	/**
	 * Extracts searchable string fragments from an arbitrary value.
	 *
	 * Scalars and Stringable values contribute their trimmed text, booleans expose
	 * common truthy labels, DateTime values expose ISO and display-like formats,
	 * and arrays/objects are traversed recursively.
	 *
	 * @param mixed $value Raw search value.
	 * @return array<int,string> Unique non-empty strings to compare against a query.
	 */
	private static function searchableStrings(mixed $value): array {
		if($value===null || $value===false){
			return [];
		}
		if($value===true){
			return ['1', 'true', 'yes'];
		}
		if(is_scalar($value) || $value instanceof \Stringable){
			$string=trim((string)$value);
			return $string!=='' ? [$string] : [];
		}
		if($value instanceof \DateTimeInterface){
			return [$value->format('c'), $value->format('Y-m-d H:i:s')];
		}
		if(is_array($value)){
			$strings=[];
			foreach($value as $entry){
				array_push($strings, ...self::searchableStrings($entry));
			}
			return array_values(array_unique($strings));
		}
		if(is_object($value)){
			return self::searchableStrings(get_object_vars($value));
		}
		return [];
	}

	/**
	 * Applies the built-in formatter for the column type.
	 *
	 * Component-registry format hooks run earlier; this method covers Panel's
	 * native types such as boolean, temporal, money, percent, structured, badge,
	 * URL, and email while preserving raw values for unknown text-like types.
	 *
	 * @param mixed $value Resolved column value.
	 * @return mixed Display value after applying the column type formatter; unknown text-like types preserve the original value.
	 */
	private function formatBuiltIn(mixed $value): mixed {
		if($value===null || $value===''){
			return (string)($this->meta['empty'] ?? '');
		}
		return match($this->type){
			'boolean', 'bool', 'checkbox', 'toggle'=>$this->truthy($value)
				? (string)($this->meta['true_label'] ?? 'Yes')
				: (string)($this->meta['false_label'] ?? 'No'),
			'date'=>$this->formatTemporal($value, (string)($this->meta['format'] ?? 'Y-m-d')),
			'datetime', 'datetime_local'=>$this->formatTemporal($value, (string)($this->meta['format'] ?? 'Y-m-d H:i')),
			'money', 'currency'=>$this->formatMoney($value),
			'percent', 'percentage'=>$this->formatPercent($value),
			'json', 'array'=>$this->formatStructured($value),
			'badge'=>$this->formatBadge($value),
			'url', 'email'=>(string)$value,
			default=>$value,
		};
	}

	/**
	 * Converts a footer value into displayable text.
	 *
	 * Footer callbacks may return scalars, booleans, arrays, or objects. Structured
	 * values are JSON encoded so custom summaries can still be rendered without
	 * leaking PHP object state.
	 *
	 * @param mixed $value Raw footer value.
	 * @return string Footer display text.
	 */
	private function footerString(mixed $value): string {
		if($value===null || $value===false){
			return '';
		}
		if($value===true){
			return 'Yes';
		}
		if(is_scalar($value) || $value instanceof \Stringable){
			return trim((string)$value);
		}
		if(is_array($value) || is_object($value)){
			return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
		}
		return '';
	}

	/**
	 * Formats a temporal value using the configured PHP date format.
	 *
	 * DateTimeInterface values are formatted directly, numeric values are treated
	 * as timestamps, and strings are parsed through strtotime() with an unchanged
	 * fallback when parsing fails.
	 *
	 * @param mixed $value Date/time value.
	 * @param string $format PHP date format.
	 * @return string Formatted temporal value.
	 */
	private function formatTemporal(mixed $value, string $format): string {
		if($value instanceof \DateTimeInterface){
			return $value->format($format);
		}
		if(is_numeric($value)){
			return date($format, (int)$value);
		}
		$timestamp=strtotime((string)$value);
		return $timestamp!==false ? date($format, $timestamp) : (string)$value;
	}

	/**
	 * Formats a numeric value as money.
	 *
	 * Decimals are clamped to a practical range and an optional currency prefix is
	 * read from column metadata. Non-numeric values pass through as text so the
	 * renderer can still show source data instead of dropping it.
	 *
	 * @param mixed $value Numeric amount.
	 * @return string Money display value.
	 */
	private function formatMoney(mixed $value): string {
		if(!is_numeric($value)){
			return (string)$value;
		}
		$decimals=(int)($this->meta['decimals'] ?? 2);
		$amount=number_format((float)$value, max(0, min(8, $decimals)));
		$currency=trim((string)($this->meta['currency'] ?? ''));
		return $currency!=='' ? $currency.' '.$amount : $amount;
	}

	/**
	 * Formats a numeric value as a percentage.
	 *
	 * The multiplier defaults to 100 so fractions become human percentages, while
	 * decimals are clamped to the same range used for money formatting.
	 *
	 * @param mixed $value Numeric percentage source value.
	 * @return string Percentage display value.
	 */
	private function formatPercent(mixed $value): string {
		if(!is_numeric($value)){
			return (string)$value;
		}
		$decimals=(int)($this->meta['decimals'] ?? 2);
		$multiplier=(float)($this->meta['multiplier'] ?? 100);
		return number_format((float)$value*$multiplier, max(0, min(8, $decimals))).'%';
	}

	/**
	 * Formats arrays and objects for structured display columns.
	 *
	 * Structured values are JSON encoded with unescaped Unicode and slashes; scalar
	 * values are converted directly to text.
	 *
	 * @param mixed $value Structured or scalar value.
	 * @return string Structured display value.
	 */
	private function formatStructured(mixed $value): string {
		if(is_array($value) || is_object($value)){
			return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
		}
		return (string)$value;
	}

	/**
	 * Formats a badge value using optional label metadata.
	 *
	 * Badge labels allow stored enum/status keys to render as friendlier text while
	 * keeping the original key as the fallback.
	 *
	 * @param mixed $value Raw badge key.
	 * @return string Badge display label.
	 */
	private function formatBadge(mixed $value): string {
		$key=(string)$value;
		$labels=$this->meta['labels'] ?? [];
		if(is_array($labels) && array_key_exists($key, $labels)){
			return (string)$labels[$key];
		}
		return $key;
	}

	/**
	 * Coerces resolver output into Panel boolean semantics.
	 *
	 * Strings accept common HTML/form truthy values, numbers are false only at
	 * zero, booleans are preserved, and any other non-null value is considered
	 * enabled.
	 *
	 * @param mixed $value Resolver or metadata value.
	 * @return bool Coerced boolean.
	 */
	private function truthy(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_int($value) || is_float($value)){
			return $value!==0;
		}
		if(is_string($value)){
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		}
		return $value!==null;
	}

	/**
	 * Resolves and normalizes dynamic/static extra attributes.
	 *
	 * Closure attribute sets receive the renderer context and may return an
	 * attribute map. Later sets replace earlier keys after the safety allow-list is
	 * applied.
	 *
	 * @param array<int,array<string,mixed>|\Closure> $sets Attribute set declarations.
	 * @param array<string,mixed> $values Resolver context.
	 * @param array<int,string> $positionOrder Positional argument order for utility resolution.
	 * @return array<string,mixed> Safe attribute map.
	 */
	private function resolveExtraAttributes(array $sets, array $values, array $positionOrder=[]): array {
		$attributes=[];
		foreach($sets as $set){
			$resolved=$set instanceof \Closure
				? PanelUtilityResolver::evaluate($set, $values, $positionOrder)
				: $set;
			if(is_array($resolved)){
				$attributes=array_replace($attributes, self::normalizeExtraAttributes($resolved));
			}
		}
		return $attributes;
	}

	/**
	 * Extracts only static attribute declarations from a set list.
	 *
	 * This is used for manifests so renderers and tooling can see known attributes
	 * without executing per-record closures.
	 *
	 * @param array<int,array<string,mixed>|\Closure> $sets Attribute set declarations.
	 * @return array<string,mixed> Merged static attributes.
	 */
	private static function staticExtraAttributes(array $sets): array {
		$attributes=[];
		foreach($sets as $set){
			if(is_array($set)){
				$attributes=array_replace($attributes, $set);
			}
		}
		return $attributes;
	}

	/**
	 * Reports whether any attribute set must be resolved at render time.
	 *
	 * @param array<int,array<string,mixed>|\Closure> $sets Attribute set declarations.
	 * @return bool Whether at least one dynamic attribute resolver exists.
	 */
	private static function hasDynamicExtraAttributes(array $sets): bool {
		foreach($sets as $set){
			if($set instanceof \Closure){
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes and filters header/cell extra attributes.
	 *
	 * Numeric string entries become boolean attributes. Reserved Panel data
	 * attributes and unsafe names are dropped; null/false are preserved so callers
	 * can explicitly remove or disable attributes downstream.
	 *
	 * @param array<string|int,mixed> $attributes Raw attribute map.
	 * @return array<string,mixed> Safe normalized attributes.
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
	 * Checks the allow-list for user-supplied header/cell attributes.
	 *
	 * Panel-reserved `data-dp-panel-*` names and managed attributes such as
	 * aria-sort are blocked so custom attributes cannot corrupt renderer state.
	 *
	 * @param string $name Lowercase attribute name.
	 * @return bool Whether the attribute can be exposed in rendered markup.
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
			return !in_array($name, ['aria-sort'], true);
		}
		return in_array($name, ['id', 'role', 'tabindex', 'headers', 'scope'], true);
	}

	/**
	 * Normalizes an arbitrary value for use inside an attribute-name segment.
	 *
	 * @param string $name Raw segment.
	 * @return string Safe lowercase segment.
	 */
	private static function normalizeAttributeSegment(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.:-]+/', '-', $name) ?? '';
		return trim($name, '-');
	}

	/**
	 * Converts a normalized field name into a human-readable label.
	 *
	 * @param string $value Field or column name.
	 * @return string Title-cased label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
