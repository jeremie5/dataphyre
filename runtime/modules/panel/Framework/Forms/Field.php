<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel form field.
 *
 * A field captures input type, labels, options, validation rules, visibility conditions, nested children, upload constraints, state callbacks, and renderer metadata for the Panel form manifest.
 */
final class Field {
	use PanelExtensible;

	private string $name;
	private string $type;
	private string $label;
	private mixed $default=null;
	private bool $required=false;
	private bool $readonly=false;
	private bool $hidden=false;
	private ?string $placeholder=null;
	private ?string $help=null;
	private array $options=[];
	private array $rules=[];
	private array $meta=[];
	private ?\Closure $optionsCallback=null;
	private ?\Closure $hydrateCallback=null;
	private ?\Closure $dehydrateCallback=null;
	private ?\Closure $validateCallback=null;
	private ?\Closure $displayCallback=null;
	private ?\Closure $visibilityCallback=null;
	private ?\Closure $stateCallback=null;
	private array $visibleOn=[];
	private array $hiddenOn=[];
	private array $dependsOn=[];
	private array $visibleWhen=[];
	private array $hiddenWhen=[];
	private array $requiredWhen=[];
	private array $requiredUnless=[];

	/**
	 * Initializes a field with normalized identity, type defaults, and a generated label.
	 *
	 * construction is private so callers enter through make() or fromArray(), ensuring the field name,
	 * type token, renderer defaults, and human label are normalized before any fluent mutations are applied.
	 *
	 * @param string $name Raw field name.
	 * @param string $type Raw field type.
	 */
	private function __construct(string $name, string $type='text') {
		$this->name=self::normalizeName($name);
		$this->type=self::normalizeName($type) ?: 'text';
		$this->meta=self::typeDefaults($this->type);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Builds a Panel field definition from fluent input or array configuration.
	 *
	 * Field definitions normalize labels, validation rules, visibility conditions, options, upload constraints, nested field groups, and renderer metadata before they are exported to a form manifest.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Raw field type token normalized before renderer defaults are applied.
	 * @return self.
	 */
	public static function make(string $name, string $type='text'): self {
		return self::configured(new self($name, $type));
	}

	/**
	 * Builds a Panel field definition from fluent input or array configuration.
	 *
	 * Field definitions normalize labels, validation rules, visibility conditions, options, upload constraints, nested field groups, and renderer metadata before they are exported to a form manifest.
	 *
	 * @param array<string, mixed> $definition Array definition imported from configuration or a manifest.
	 * @return self.
	 */
	public static function fromArray(array $definition): self {
		$field=self::make((string)($definition['name'] ?? ''), (string)($definition['type'] ?? 'text'));
		if(isset($definition['label'])){
			$field=$field->label((string)$definition['label']);
		}
		foreach(['placeholder', 'help'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$field=$field->{$key}($definition[$key]);
			}
		}
		$definitionType=self::normalizeName((string)($definition['type'] ?? ''));
		if($definitionType==='address' && !isset($definition['child_fields']) && !isset($definition['fields'])){
			$field=$field->address(
				is_scalar($definition['address_country'] ?? $definition['country'] ?? null) ? (string)($definition['address_country'] ?? $definition['country']) : 'CA',
				array_key_exists('address_line2', $definition) ? (bool)$definition['address_line2'] : (array_key_exists('include_line2', $definition) ? (bool)$definition['include_line2'] : true)
			);
		}
		if(isset($definition['helper_text']) && is_string($definition['helper_text'])){
			$field=$field->helperText($definition['helper_text']);
		}
		if(isset($definition['hint']) && is_string($definition['hint'])){
			$field=$field->hint($definition['hint']);
		}
		if(isset($definition['hint_icon']) && is_string($definition['hint_icon'])){
			$field=$field->hintIcon($definition['hint_icon']);
		}
		if(array_key_exists('accessibility', $definition)){
			$field=is_array($definition['accessibility'])
				? $field->accessibilityPolicy($definition['accessibility'])
				: $field->inheritAccessibilityPolicy((bool)$definition['accessibility']);
		}
		if(array_key_exists('a11y', $definition)){
			$field=is_array($definition['a11y'])
				? $field->accessibilityPolicy($definition['a11y'])
				: $field->inheritAccessibilityPolicy((bool)$definition['a11y']);
		}
		if(isset($definition['min_usable_width'])){
			$field=$field->minUsableWidth((int)$definition['min_usable_width'], (string)($definition['min_usable_width_unit'] ?? 'px'));
		}
		if(isset($definition['min_usable_chars'])){
			$field=$field->minUsableCharacters((int)$definition['min_usable_chars']);
		}
		if(isset($definition['min_touch_target'])){
			$field=$field->minTouchTarget((int)$definition['min_touch_target']);
		}
		if(isset($definition['max_adornment_ratio'])){
			$field=$field->maxAdornmentRatio((float)$definition['max_adornment_ratio']);
		}
		if(isset($definition['max_label_ratio'])){
			$field=$field->maxLabelRatio((float)$definition['max_label_ratio']);
		}
		if(isset($definition['contrast_policy']) && is_array($definition['contrast_policy'])){
			$field=$field->contrastPolicy($definition['contrast_policy']);
		}
		if(isset($definition['contrast_min_ratio'])){
			$field=$field->contrastPolicy(['min_ratio'=>(float)$definition['contrast_min_ratio']]);
		}
		if(array_key_exists('default', $definition)){
			$field=$field->default($definition['default']);
		}
		if(!empty($definition['required'])){
			$field=$field->required();
		}
		if(!empty($definition['readonly'])){
			$field=$field->readonly();
		}
		if(isset($definition['disabled'])){
			$field=$field->disabled((bool)$definition['disabled']);
		}
		if(!empty($definition['hidden'])){
			$field=$field->hidden();
		}
		if(array_key_exists('content', $definition)){
			$field=$field->content($definition['content']);
		}
		if(array_key_exists('display_content', $definition)){
			$field=$field->content($definition['display_content']);
		}
		if(array_key_exists('html_content', $definition)){
			$field=$field->htmlContent((string)$definition['html_content']);
		}
		if(isset($definition['dehydrated'])){
			$field=$field->dehydrated((bool)$definition['dehydrated']);
		}
		if(isset($definition['visible_on'])){
			$field=$field->visibleOn($definition['visible_on']);
		}
		if(isset($definition['hidden_on'])){
			$field=$field->hiddenOn($definition['hidden_on']);
		}
		if(isset($definition['depends_on'])){
			$field=$field->dependsOn($definition['depends_on']);
		}
		if(!empty($definition['live'])){
			$field=$field->live(true, (int)($definition['debounce_ms'] ?? $definition['meta']['debounce_ms'] ?? 250));
		}
		if(!empty($definition['reactive'])){
			$field=$field->reactive(true);
		}
		if(isset($definition['visible_when']) && is_array($definition['visible_when'])){
			foreach($definition['visible_when'] as $source=>$value){
				$field=$field->visibleWhen((string)$source, $value);
			}
		}
		if(isset($definition['hidden_when']) && is_array($definition['hidden_when'])){
			foreach($definition['hidden_when'] as $source=>$value){
				$field=$field->hiddenWhen((string)$source, $value);
			}
		}
		if(isset($definition['required_when']) && is_array($definition['required_when'])){
			foreach($definition['required_when'] as $source=>$value){
				$field=$field->requiredWhen((string)$source, $value);
			}
		}
		if(isset($definition['required_if']) && is_array($definition['required_if'])){
			if(isset($definition['required_if']['field'])){
				$field=$field->requiredIf((string)$definition['required_if']['field'], $definition['required_if']['value'] ?? true);
			}
			else {
				foreach($definition['required_if'] as $source=>$value){
					$field=$field->requiredIf((string)$source, $value);
				}
			}
		}
		if(isset($definition['required_unless']) && is_array($definition['required_unless'])){
			foreach($definition['required_unless'] as $source=>$value){
				$field=$field->requiredUnless((string)$source, $value);
			}
		}
		if(isset($definition['options']) && is_array($definition['options'])){
			$field=$field->options($definition['options']);
		}
		if(isset($definition['relationship']) && is_string($definition['relationship'])){
			$field=$field->relationship($definition['relationship']);
		}
		if(isset($definition['related_resource']) && is_string($definition['related_resource'])){
			$field=$field->relatedResource($definition['related_resource']);
		}
		if(isset($definition['title_attribute']) && is_string($definition['title_attribute'])){
			$field=$field->titleAttribute($definition['title_attribute']);
		}
		if(isset($definition['key_attribute']) && is_string($definition['key_attribute'])){
			$field=$field->keyAttribute($definition['key_attribute']);
		}
		if(isset($definition['choice_columns']) || isset($definition['columns'])){
			$field=$field->choiceColumns((int)($definition['choice_columns'] ?? $definition['columns']));
		}
		if(isset($definition['inline_choices']) || isset($definition['inline'])){
			$field=$field->inlineChoices((bool)($definition['inline_choices'] ?? $definition['inline']));
		}
		if(isset($definition['repeater_fields']) && is_array($definition['repeater_fields']) && ($definition['repeater_fields']!==[] || self::normalizeName((string)($definition['type'] ?? ''))==='repeater')){
			$field=$field->repeaterFields($definition['repeater_fields']);
		}
		if(isset($definition['child_fields']) && is_array($definition['child_fields'])){
			$field=$field->childFields($definition['child_fields']);
		}
		if(isset($definition['builder_blocks']) && is_array($definition['builder_blocks'])){
			$field=$field->builderBlocks($definition['builder_blocks']);
		}
		if(isset($definition['blocks']) && is_array($definition['blocks']) && self::normalizeName((string)($definition['type'] ?? ''))==='builder'){
			$field=$field->builderBlocks($definition['blocks']);
		}
		if(isset($definition['fields']) && is_array($definition['fields']) && in_array(self::normalizeName((string)($definition['type'] ?? '')), ['fieldset', 'group', 'field_group', 'address'], true)){
			$field=$field->childFields($definition['fields']);
		}
		if(isset($definition['accepted_types'])){
			$field=$field->acceptedTypes($definition['accepted_types']);
		}
		if(isset($definition['max_file_size'])){
			$field=$field->maxFileSize((int)$definition['max_file_size']);
		}
		if(isset($definition['custom_uploader']) || isset($definition['chunked_upload']) || isset($definition['upload_endpoint'])){
			$field=$field->customUploader(
				(bool)($definition['custom_uploader'] ?? $definition['chunked_upload'] ?? true),
				is_string($definition['upload_endpoint'] ?? null) ? $definition['upload_endpoint'] : null
			);
		}
		if(isset($definition['upload_delete_endpoint']) || isset($definition['delete_endpoint'])){
			$field=$field->uploadDeleteEndpoint((string)($definition['upload_delete_endpoint'] ?? $definition['delete_endpoint']));
		}
		if(isset($definition['upload_chunk_size'])){
			$field=$field->uploadChunkSize((int)$definition['upload_chunk_size']);
		}
		if(isset($definition['upload_retries'])){
			$field=$field->uploadRetries((int)$definition['upload_retries']);
		}
		if(isset($definition['upload_concurrency'])){
			$field=$field->uploadConcurrency((int)$definition['upload_concurrency']);
		}
		if(isset($definition['upload_headers']) && is_array($definition['upload_headers'])){
			$field=$field->uploadHeaders($definition['upload_headers']);
		}
		if(isset($definition['upload_fields']) && is_array($definition['upload_fields'])){
			$field=$field->uploadFields($definition['upload_fields']);
		}
		if(isset($definition['upload_labels']) && is_array($definition['upload_labels'])){
			$field=$field->uploadLabels($definition['upload_labels']);
		}
		if(isset($definition['upload_csrf_form']) || isset($definition['upload_csrf'])){
			$field=$field->uploadCsrf(
				(string)($definition['upload_csrf_form'] ?? $definition['upload_csrf']),
				(string)($definition['upload_csrf_field'] ?? 'csrf'),
				(string)($definition['upload_csrf_header'] ?? 'X-CSRF-Token')
			);
		}
		if(isset($definition['upload_min_files']) || isset($definition['min_files'])){
			$field=$field->uploadMinFiles((int)($definition['upload_min_files'] ?? $definition['min_files']));
		}
		if(isset($definition['upload_max_files']) || isset($definition['max_files'])){
			$field=$field->uploadMaxFiles((int)($definition['upload_max_files'] ?? $definition['max_files']));
		}
		if(isset($definition['storage_disk']) || isset($definition['storage_path'])){
			$field=$field->storageUploader(
				is_string($definition['storage_disk'] ?? null) ? $definition['storage_disk'] : 'local',
				is_string($definition['storage_path'] ?? null) ? $definition['storage_path'] : 'panel_uploads/{date}/{filename}'
			);
		}
		if(isset($definition['rows'])){
			$field=$field->rows((int)$definition['rows']);
		}
		if(isset($definition['auto_resize']) || isset($definition['autosize'])){
			$field=$field->autoResize((bool)($definition['auto_resize'] ?? $definition['autosize']));
		}
		if(array_key_exists('min', $definition)){
			$field=$field->min($definition['min']);
		}
		if(array_key_exists('max', $definition)){
			$field=$field->max($definition['max']);
		}
		if(array_key_exists('step', $definition)){
			$field=$field->step($definition['step']);
		}
		if(isset($definition['today_button'])){
			$field=$field->todayButton(is_string($definition['today_button']) ? $definition['today_button'] : 'Today');
		}
		if(isset($definition['now_button'])){
			$field=$field->nowButton(is_string($definition['now_button']) ? $definition['now_button'] : 'Now');
		}
		if(isset($definition['value_display']) || isset($definition['show_value']) || isset($definition['slider_value_display'])){
			$field=$field->sliderValueDisplay((bool)($definition['value_display'] ?? $definition['show_value'] ?? $definition['slider_value_display']));
		}
		if(isset($definition['on_label']) || isset($definition['true_label'])){
			$field=$field->onLabel((string)($definition['on_label'] ?? $definition['true_label']));
		}
		if(isset($definition['off_label']) || isset($definition['false_label'])){
			$field=$field->offLabel((string)($definition['off_label'] ?? $definition['false_label']));
		}
		if(isset($definition['tag_separator']) && is_scalar($definition['tag_separator'])){
			$field=$field->tagSeparator((string)$definition['tag_separator']);
		}
		if(isset($definition['min_tags'])){
			$field=$field->minTags((int)$definition['min_tags']);
		}
		if(isset($definition['max_tags'])){
			$field=$field->maxTags((int)$definition['max_tags']);
		}
		if(isset($definition['pair_separator']) || isset($definition['key_separator'])){
			$field=$field->keyValueSeparators(
				is_scalar($definition['pair_separator'] ?? null) ? (string)$definition['pair_separator'] : "\n",
				is_scalar($definition['key_separator'] ?? null) ? (string)$definition['key_separator'] : '='
			);
		}
		if(isset($definition['min_pairs'])){
			$field=$field->minPairs((int)$definition['min_pairs']);
		}
		if(isset($definition['max_pairs'])){
			$field=$field->maxPairs((int)$definition['max_pairs']);
		}
		if(isset($definition['searchable'])){
			$field=$field->searchable((bool)$definition['searchable']);
		}
		if(isset($definition['search_placeholder']) && is_scalar($definition['search_placeholder'])){
			$field=$field->searchPlaceholder((string)$definition['search_placeholder']);
		}
		if(isset($definition['no_results_text']) && is_scalar($definition['no_results_text'])){
			$field=$field->noResultsText((string)$definition['no_results_text']);
		}
		if(isset($definition['media_collection'])){
			$field=$field->mediaCollection($definition['media_collection']);
		}
		if(isset($definition['media_variants']) && is_array($definition['media_variants'])){
			$field=$field->mediaVariants($definition['media_variants']);
		}
		if(isset($definition['multiple'])){
			$field=$field->multiple((bool)$definition['multiple']);
		}
		if(isset($definition['suggestions']) && is_array($definition['suggestions'])){
			$field=$field->suggestions($definition['suggestions']);
		}
		if(isset($definition['datalist']) && is_array($definition['datalist'])){
			$field=$field->datalist($definition['datalist']);
		}
		if(isset($definition['autocomplete_options']) && is_array($definition['autocomplete_options'])){
			$field=$field->autocomplete($definition['autocomplete_options']);
		}
		if(isset($definition['copyable'])){
			$field=$field->copyable((bool)$definition['copyable'], (bool)($definition['copy_normalized'] ?? $definition['copy_submit_normalized'] ?? false));
		}
		if(isset($definition['revealable']) || isset($definition['password_reveal'])){
			$field=$field->revealable((bool)($definition['revealable'] ?? $definition['password_reveal']));
		}
		if(isset($definition['color_swatch']) || isset($definition['swatch'])){
			$field=$field->colorSwatch((bool)($definition['color_swatch'] ?? $definition['swatch']));
		}
		foreach(['prefix'=>'prependLabel', 'prepend'=>'prependLabel', 'prepend_label'=>'prependLabel', 'suffix'=>'appendLabel', 'append'=>'appendLabel', 'append_label'=>'appendLabel'] as $key=>$method){
			if(isset($definition[$key]) && is_scalar($definition[$key])){
				$field=$field->{$method}((string)$definition[$key]);
			}
		}
		foreach(['prefix_icon'=>'prependIcon', 'prepend_icon'=>'prependIcon', 'suffix_icon'=>'appendIcon', 'append_icon'=>'appendIcon'] as $key=>$method){
			if(isset($definition[$key]) && is_scalar($definition[$key])){
				$field=$field->{$method}((string)$definition[$key], isset($definition[$key.'_label']) && is_scalar($definition[$key.'_label']) ? (string)$definition[$key.'_label'] : null);
			}
		}
		if(isset($definition['prepend_buttons']) && is_array($definition['prepend_buttons'])){
			$field=$field->prependButtons($definition['prepend_buttons']);
		}
		if(isset($definition['append_buttons']) && is_array($definition['append_buttons'])){
			$field=$field->appendButtons($definition['append_buttons']);
		}
		if(isset($definition['prepend_button']) && is_array($definition['prepend_button'])){
			$field=$field->prependButtons([$definition['prepend_button']]);
		}
		elseif(isset($definition['prepend_button']) && is_scalar($definition['prepend_button'])){
			$field=$field->prependButton((string)$definition['prepend_button']);
		}
		if(isset($definition['append_button']) && is_array($definition['append_button'])){
			$field=$field->appendButtons([$definition['append_button']]);
		}
		elseif(isset($definition['append_button']) && is_scalar($definition['append_button'])){
			$field=$field->appendButton((string)$definition['append_button']);
		}
		if(isset($definition['mask']) && is_string($definition['mask'])){
			$field=$field->mask(
				$definition['mask'],
				(bool)($definition['submit_unmasked'] ?? $definition['mask_submit_normalized'] ?? false)
			);
		}
		if(array_key_exists('mask_placeholder', $definition)){
			$field=$definition['mask_placeholder']===false
				? $field->hideMaskPlaceholder()
				: $field->maskPlaceholder(is_string($definition['mask_placeholder']) ? $definition['mask_placeholder'] : null);
		}
		if(isset($definition['submit_unmasked']) && !isset($definition['mask'])){
			$field=$field->submitUnmasked((bool)$definition['submit_unmasked']);
		}
		$formatRule=is_string($definition['format'] ?? null) ? (string)$definition['format'] : (is_string($definition['format_rule'] ?? null) ? (string)$definition['format_rule'] : null);
		if($formatRule!==null){
			$formatOptions=is_array($definition['format_options'] ?? null) ? $definition['format_options'] : [];
			foreach(['country', 'country_code', 'country_field', 'subdivision', 'subdivision_field', 'region', 'state', 'province', 'phone_prefix', 'source_field'] as $key){
				if(isset($definition[$key]) && is_scalar($definition[$key])){
					$formatOptions[$key]=(string)$definition[$key];
				}
			}
			$field=$field->format($formatRule, $formatOptions);
		}
		if(array_key_exists('format_placeholder', $definition)){
			$field=$definition['format_placeholder']===false
				? $field->hideFormatPlaceholder()
				: $field->formatPlaceholder(is_string($definition['format_placeholder']) ? $definition['format_placeholder'] : null);
		}
		if(isset($definition['format_event']) && is_string($definition['format_event'])){
			$field=$field->formatOn($definition['format_event']);
		}
		if(isset($definition['submit_normalized'])){
			$field=$field->submitNormalized((bool)$definition['submit_normalized']);
		}
		if(isset($definition['submit_formatted'])){
			$field=$field->submitFormatted((bool)$definition['submit_formatted']);
		}
		if(isset($definition['input_mode']) && is_string($definition['input_mode'])){
			$field=$field->inputMode($definition['input_mode']);
		}
		if(isset($definition['autocomplete']) && is_string($definition['autocomplete'])){
			$field=$field->autocomplete($definition['autocomplete']);
		}
		if(isset($definition['min_length'])){
			$field=$field->minLength((int)$definition['min_length']);
		}
		if(isset($definition['max_length'])){
			$field=$field->maxLength((int)$definition['max_length']);
		}
		if(isset($definition['length'])){
			$field=$field->length((int)$definition['length']);
		}
		if(isset($definition['length_between']) && is_array($definition['length_between'])){
			$field=$field->lengthBetween((int)($definition['length_between'][0] ?? $definition['length_between']['min'] ?? 0), (int)($definition['length_between'][1] ?? $definition['length_between']['max'] ?? 0));
		}
		if(isset($definition['character_counter']) || isset($definition['char_counter']) || isset($definition['counter'])){
			$counter=$definition['character_counter'] ?? $definition['char_counter'] ?? $definition['counter'];
			if($counter!==false){
				$counterMax=is_int($counter) ? $counter : (is_numeric($counter) ? (int)$counter : null);
				$field=$field->characterCounter($counterMax, isset($definition['counter_position']) ? (string)$definition['counter_position'] : 'append');
			}
		}
		if(isset($definition['pattern']) && is_string($definition['pattern'])){
			$field=$field->pattern($definition['pattern']);
		}
		if(isset($definition['title']) && is_string($definition['title'])){
			$field=$field->meta(['title'=>trim($definition['title'])]);
		}
		if(isset($definition['preview'])){
			$field=$field->preview((bool)$definition['preview'], isset($definition['preview_mode']) ? (string)$definition['preview_mode'] : null);
		}
		if(isset($definition['editor']) && is_string($definition['editor'])){
			$field=$field->editor($definition['editor'], is_array($definition['editor_options'] ?? null) ? $definition['editor_options'] : []);
		}
		if(isset($definition['code_language']) && is_scalar($definition['code_language'])){
			$field=$field->codeLanguage((string)$definition['code_language']);
		}
		elseif(isset($definition['language']) && is_scalar($definition['language']) && self::normalizeName((string)($definition['type'] ?? ''))==='code'){
			$field=$field->codeLanguage((string)$definition['language']);
		}
		if(isset($definition['clearable'])){
			$field=$field->clearable((bool)$definition['clearable']);
		}
		if(isset($definition['nullable'])){
			$field=$field->nullable((bool)$definition['nullable']);
		}
		if(isset($definition['regex']) && is_string($definition['regex'])){
			$field=$field->regex($definition['regex']);
		}
		if(isset($definition['confirmed'])){
			$field=$field->confirmed((bool)$definition['confirmed']);
		}
		if(isset($definition['same']) && is_string($definition['same'])){
			$field=$field->same($definition['same']);
		}
		if(isset($definition['different']) && is_string($definition['different'])){
			$field=$field->different($definition['different']);
		}
		if(isset($definition['starts_with'])){
			$field=$field->startsWith($definition['starts_with']);
		}
		if(isset($definition['ends_with'])){
			$field=$field->endsWith($definition['ends_with']);
		}
		if(isset($definition['rules']) && (is_array($definition['rules']) || is_string($definition['rules']))){
			$field=$field->rules($definition['rules']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$field=$field->meta($definition['meta']);
		}
		if(isset($definition['state_using']) && is_callable($definition['state_using'])){
			$field=$field->stateUsing($definition['state_using']);
		}
		elseif(isset($definition['state_callback']) && is_callable($definition['state_callback'])){
			$field=$field->stateUsing($definition['state_callback']);
		}
		return $field;
	}

	/**
	 * Returns the normalized field name used as the form-state key.
	 *
	 * @return string.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Replaces the human-readable label shown by Panel renderers.
	 *
	 * @param string $label Label text stored without surrounding whitespace.
	 * @return self.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Replaces the field type and merges renderer defaults for that type.
	 *
	 * Type names are normalized through the same field-name rules used during construction. Unknown or blank types fall back to text, while known type defaults are merged without discarding explicit metadata already set on the field.
	 *
	 * @param string $type Raw field type token.
	 * @return self.
	 */
	public function type(string $type): self {
		$clone=clone $this;
		$clone->type=self::normalizeName($type) ?: 'text';
		$defaults=self::typeDefaults($clone->type);
		if($defaults!==[]){
			$clone->meta=array_replace($defaults, $clone->meta);
		}
		return $clone;
	}

	/**
	 * Sets the default value used when form state has no submitted value.
	 *
	 * Defaults are stored verbatim in the field definition and may be scalar, structured, or nullable depending on the renderer and field type.
	 *
	 * @param mixed $value Default field value emitted in the form manifest.
	 * @return self.
	 */
	public function default(mixed $value): self {
		$clone=clone $this;
		$clone->default=$value;
		return $clone;
	}

	/**
	 * Toggles the required validation flag and matching rule entry.
	 *
	 * Enabling the flag appends a required rule when one is not already present. Disabling it removes required rules by case-insensitive name while leaving other validation rules untouched.
	 *
	 * @param bool $required Whether submitted form state must include a value for this field.
	 * @return self.
	 */
	public function required(bool $required=true): self {
		$clone=clone $this;
		$clone->required=$required;
		if($required && !in_array('required', $clone->rules, true)){
			$clone->rules[]='required';
		}
		elseif(!$required){
			$clone->rules=array_values(array_filter($clone->rules, static fn(string $rule): bool => strtolower(trim($rule))!=='required'));
		}
		return $clone;
	}

	/**
	 * Toggles read-only behavior for renderer and persistence workflows.
	 *
	 * @param bool $readonly Whether the field should be presented as non-editable.
	 * @return self.
	 */
	public function readonly(bool $readonly=true): self {
		$clone=clone $this;
		$clone->readonly=$readonly;
		return $clone;
	}

	/**
	 * Marks the field disabled while also treating it as read-only.
	 *
	 * @param bool $disabled Whether renderer metadata should disable user interaction.
	 * @return self.
	 */
	public function disabled(bool $disabled=true): self {
		return $this->readonly($disabled)->meta(['disabled'=>$disabled]);
	}

	/**
	 * Alias for disabled() used by fluent field definitions.
	 *
	 * @param bool $disabled Whether renderer metadata should disable user interaction.
	 * @return self.
	 */
	public function disable(bool $disabled=true): self {
		return $this->disabled($disabled);
	}

	/**
	 * Controls whether the field contributes submitted state to dehydration.
	 *
	 * The flag is persisted as renderer metadata so form save pipelines can omit display-only or derived fields without removing them from the visible form.
	 *
	 * @param bool $dehydrated Whether this field should be included in dehydrated form data.
	 * @return self.
	 */
	public function dehydrated(bool $dehydrated=true): self {
		return $this->meta(['dehydrated'=>$dehydrated]);
	}

	/**
	 * Alias for dehydrated() used by fluent field definitions.
	 *
	 * @param bool $dehydrated Whether this field should be included in dehydrated form data.
	 * @return self.
	 */
	public function dehydrate(bool $dehydrated=true): self {
		return $this->dehydrated($dehydrated);
	}

	/**
	 * Toggles the field's static hidden flag.
	 *
	 * Dynamic visibility helpers can still add conditional metadata; this flag controls the baseline visibility emitted in the manifest.
	 *
	 * @param bool $hidden Whether the field should be hidden by default.
	 * @return self.
	 */
	public function hidden(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Converts the field to a hidden input, optionally with a default value.
	 *
	 * The value is only applied when the caller passes an argument, preserving a nullable default as an intentional value.
	 *
	 * @param mixed $default Optional default value for the hidden field.
	 * @return self.
	 */
	public function hiddenField(mixed $default=null): self {
		$field=$this->type('hidden');
		return func_num_args()>0 ? $field->default($default) : $field;
	}

	/**
	 * Alias for hiddenField() used by input-oriented field definitions.
	 *
	 * @param mixed $default Optional default value for the hidden input.
	 * @return self.
	 */
	public function hiddenInput(mixed $default=null): self {
		return func_num_args()>0 ? $this->hiddenField($default) : $this->hiddenField();
	}

	/**
	 * Converts the field to a hidden input with a required default value.
	 *
	 * @param mixed $value Default value stored for the hidden field.
	 * @return self.
	 */
	public function hiddenValue(mixed $value): self {
		return $this->hiddenField($value);
	}

	/**
	 * Sets placeholder text for empty input controls.
	 *
	 * @param string $placeholder Placeholder text; blank strings clear the placeholder.
	 * @return self.
	 */
	public function placeholder(string $placeholder): self {
		$clone=clone $this;
		$clone->placeholder=trim($placeholder) ?: null;
		return $clone;
	}

	/**
	 * Sets persistent helper text shown with the field.
	 *
	 * @param string $help Helper text; blank strings clear the help message.
	 * @return self.
	 */
	public function help(string $help): self {
		$clone=clone $this;
		$clone->help=trim($help) ?: null;
		return $clone;
	}

	/**
	 * Alias for help() used by renderer-facing field definitions.
	 *
	 * @param string $help Helper text; blank strings clear the help message.
	 * @return self.
	 */
	public function helperText(string $help): self {
		return $this->help($help);
	}

	/**
	 * Sets transient hint text and optionally an accompanying icon.
	 *
	 * Hints are stored in metadata rather than the core help property so renderers can place them independently from persistent helper text.
	 *
	 * @param string $hint Hint text stored in renderer metadata.
	 * @param ?string $icon Optional icon name stored beside the hint.
	 * @return self.
	 */
	public function hint(string $hint, ?string $icon=null): self {
		$hint=trim($hint);
		$field=$hint==='' ? $this->meta(['hint'=>'']) : $this->meta(['hint'=>$hint]);
		return $icon===null ? $field : $field->hintIcon($icon);
	}

	/**
	 * Sets the icon associated with renderer hint metadata.
	 *
	 * @param string $icon Icon name stored without surrounding whitespace.
	 * @return self.
	 */
	public function hintIcon(string $icon): self {
		return $this->meta(['hint_icon'=>trim($icon)]);
	}

	/**
	 * Configures accessibility and usability metadata for this field.
	 *
	 * The metadata gives Panel renderers concrete constraints for contrast, touch targets, adornment ratios, and usable input width.
	 *
	 * @param array<string, mixed> $policy Accessibility or contrast policy declaration.
	 * @return self.
	 */
	public function accessibilityPolicy(array $policy): self {
		$normalized=[];
		if(isset($policy['min_usable_width']) || isset($policy['min_width'])){
			$normalized['min_usable_width']=max(0, (int)($policy['min_usable_width'] ?? $policy['min_width']));
			$unit=strtolower(trim((string)($policy['min_usable_width_unit'] ?? $policy['unit'] ?? 'px')));
			$normalized['min_usable_width_unit']=in_array($unit, ['px', 'ch'], true) ? $unit : 'px';
		}
		if(isset($policy['min_usable_chars']) || isset($policy['min_chars'])){
			$normalized['min_usable_chars']=max(0, (int)($policy['min_usable_chars'] ?? $policy['min_chars']));
		}
		if(isset($policy['min_touch_target']) || isset($policy['touch_target'])){
			$normalized['min_touch_target']=max(0, (int)($policy['min_touch_target'] ?? $policy['touch_target']));
		}
		if(isset($policy['max_adornment_ratio']) || isset($policy['adornment_ratio'])){
			$normalized['max_adornment_ratio']=max(0.0, min(1.0, (float)($policy['max_adornment_ratio'] ?? $policy['adornment_ratio'])));
		}
		if(isset($policy['max_label_ratio']) || isset($policy['label_ratio'])){
			$normalized['max_label_ratio']=max(0.0, min(1.0, (float)($policy['max_label_ratio'] ?? $policy['label_ratio'])));
		}
		if(isset($policy['contrast']) && is_array($policy['contrast'])){
			$normalized['contrast_policy']=self::normalizeContrastPolicy($policy['contrast']);
		}
		elseif(isset($policy['contrast_policy']) && is_array($policy['contrast_policy'])){
			$normalized['contrast_policy']=self::normalizeContrastPolicy($policy['contrast_policy']);
		}
		elseif(isset($policy['contrast_min_ratio']) || isset($policy['min_ratio'])){
			$normalized['contrast_policy']=self::normalizeContrastPolicy(['min_ratio'=>$policy['contrast_min_ratio'] ?? $policy['min_ratio']]);
		}
		if($normalized===[]){
			return $this;
		}
		$existing=is_array($this->meta['accessibility'] ?? null) ? $this->meta['accessibility'] : [];
		return $this->meta(['accessibility'=>array_replace_recursive($existing, $normalized)]);
	}

	/**
	 * Sets the minimum usable input width expected by renderers.
	 *
	 * @param int $width Minimum width value clamped during accessibility policy normalization.
	 * @param string $unit Supported unit token, either px or ch.
	 * @return self.
	 */
	public function minUsableWidth(int $width, string $unit='px'): self {
		return $this->accessibilityPolicy([
			'min_usable_width'=>$width,
			'min_usable_width_unit'=>$unit,
		]);
	}

	/**
	 * Sets the minimum number of visible characters expected for text entry.
	 *
	 * @param int $characters Minimum usable character count clamped at zero.
	 * @return self.
	 */
	public function minUsableCharacters(int $characters): self {
		return $this->accessibilityPolicy(['min_usable_chars'=>$characters]);
	}

	/**
	 * Sets the minimum touch target size expected by renderers.
	 *
	 * @param int $pixels Minimum target size in pixels, clamped at zero.
	 * @return self.
	 */
	public function minTouchTarget(int $pixels=44): self {
		return $this->accessibilityPolicy(['min_touch_target'=>$pixels]);
	}

	/**
	 * Sets the maximum share of the field width that adornments may consume.
	 *
	 * @param float $ratio Ratio clamped between zero and one.
	 * @return self.
	 */
	public function maxAdornmentRatio(float $ratio=0.45): self {
		return $this->accessibilityPolicy(['max_adornment_ratio'=>$ratio]);
	}

	/**
	 * Sets the maximum share of the field row that labels may consume.
	 *
	 * @param float $ratio Ratio clamped between zero and one.
	 * @return self.
	 */
	public function maxLabelRatio(float $ratio=0.55): self {
		return $this->accessibilityPolicy(['max_label_ratio'=>$ratio]);
	}

	/**
	 * Configures accessibility and usability metadata for this field.
	 *
	 * The metadata gives Panel renderers concrete constraints for contrast, touch targets, adornment ratios, and usable input width.
	 *
	 * @param array|float $policy Contrast policy array or minimum contrast ratio.
	 * @param ?string $scope Optional renderer scope for the contrast rule.
	 * @return self.
	 */
	public function contrastPolicy(array|float $policy=4.5, ?string $scope=null): self {
		if(is_float($policy) || is_int($policy)){
			$policy=['min_ratio'=>(float)$policy];
		}
		if($scope!==null){
			$policy['scope']=$scope;
		}
		return $this->accessibilityPolicy(['contrast'=>$policy]);
	}

	/**
	 * Controls whether this field inherits parent accessibility policy metadata.
	 *
	 * @param bool $inherit Whether parent policy defaults should apply to this field.
	 * @return self.
	 */
	public function inheritAccessibilityPolicy(bool $inherit=true): self {
		return $this->meta(['accessibility_inherit'=>$inherit]);
	}

	/**
	 * Disables inheritance of parent accessibility policy metadata.
	 *
	 * @return self.
	 */
	public function withoutAccessibilityPolicy(): self {
		return $this->inheritAccessibilityPolicy(false);
	}

	/**
	 * Alias for withoutAccessibilityPolicy().
	 *
	 * @return self.
	 */
	public function noAccessibilityPolicy(): self {
		return $this->withoutAccessibilityPolicy();
	}

	/**
	 * Assigns this field to a named form section.
	 *
	 * @param string $section Section identifier stored in renderer metadata.
	 * @return self.
	 */
	public function section(string $section): self {
		return $this->meta(['section'=>trim($section)]);
	}

	/**
	 * Sets the field's responsive grid column span.
	 *
	 * Numeric, string, and breakpoint-map inputs are normalized before being stored in metadata.
	 *
	 * @param int|string|array $span Grid span value or responsive span map.
	 * @return self.
	 */
	public function columnSpan(int|string|array $span): self {
		return $this->meta(['column_span'=>self::normalizeGridSpan($span)]);
	}

	/**
	 * Sets the field's responsive grid column start.
	 *
	 * Numeric, string, and breakpoint-map inputs are normalized before being stored in metadata.
	 *
	 * @param int|string|array $start Grid start value or responsive start map.
	 * @return self.
	 */
	public function columnStart(int|string|array $start): self {
		return $this->meta(['column_start'=>self::normalizeGridStart($start)]);
	}

	/**
	 * Sets the field's responsive grid row span.
	 *
	 * Numeric, string, and breakpoint-map inputs are normalized before being stored in metadata.
	 *
	 * @param int|string|array $span Grid row span value or responsive span map.
	 * @return self.
	 */
	public function rowSpan(int|string|array $span): self {
		return $this->meta(['row_span'=>self::normalizeGridStart($span)]);
	}

	/**
	 * Expands the field across the full available grid width.
	 *
	 * @return self.
	 */
	public function fullWidth(): self {
		return $this->columnSpan('full');
	}

	/**
	 * Marks the field value for badge-style rendering.
	 *
	 * Array input is preserved as tone metadata, while a non-empty string sets the primary tone.
	 *
	 * @param array|string $tones Tone map or primary tone token for the badge.
	 * @return self.
	 */
	public function badge(array|string $tones=[]): self {
		$meta=['badge'=>true];
		if(is_array($tones)){
			$meta['tones']=$tones;
		}
		elseif(trim($tones)!==''){
			$meta['tone']=trim($tones);
		}
		return $this->meta($meta);
	}

	/**
	 * Enables renderer copy controls for this field's displayed value.
	 *
	 * Normalized copy mode lets renderers copy the transformed value rather than the raw display string when both values are available.
	 *
	 * @param bool $copyable Whether the renderer should expose copy behavior.
	 * @param bool $normalized Whether copy output should prefer normalized field state.
	 * @return self.
	 */
	public function copyable(bool $copyable=true, bool $normalized=false): self {
		return $this->meta(['copyable'=>$copyable, 'copy_normalized'=>$normalized]);
	}

	/**
	 * Enables copy controls that prefer normalized field state.
	 *
	 * @param bool $copyable Whether the renderer should expose normalized copy behavior.
	 * @return self.
	 */
	public function copyableNormalized(bool $copyable=true): self {
		return $this->copyable($copyable, true);
	}

	/**
	 * Sets the primary icon shown with this field.
	 *
	 * @param string $icon Icon name stored without surrounding whitespace.
	 * @return self.
	 */
	public function icon(string $icon): self {
		return $this->meta(['icon'=>trim($icon)]);
	}

	/**
	 * Adds a textual prefix before the input value.
	 *
	 * @param string $prefix Prefix text used as both prepend label and metadata.
	 * @return self.
	 */
	public function prefix(string $prefix): self {
		return $this->prependLabel($prefix)->meta(['prefix'=>$prefix]);
	}

	/**
	 * Adds a textual suffix after the input value.
	 *
	 * @param string $suffix Suffix text used as both append label and metadata.
	 * @return self.
	 */
	public function suffix(string $suffix): self {
		return $this->appendLabel($suffix)->meta(['suffix'=>$suffix]);
	}

	/**
	 * Adds a textual adornment before the input control.
	 *
	 * @param string $label Prepend label shown before the input.
	 * @return self.
	 */
	public function prepend(string $label): self {
		return $this->prependLabel($label);
	}

	/**
	 * Adds a textual adornment after the input control.
	 *
	 * @param string $label Append label shown after the input.
	 * @return self.
	 */
	public function append(string $label): self {
		return $this->appendLabel($label);
	}

	/**
	 * Stores the label shown before the input control.
	 *
	 * @param string $label Prepend label stored without surrounding whitespace.
	 * @return self.
	 */
	public function prependLabel(string $label): self {
		return $this->meta(['prepend_label'=>trim($label)]);
	}

	/**
	 * Stores the label shown after the input control.
	 *
	 * @param string $label Append label stored without surrounding whitespace.
	 * @return self.
	 */
	public function appendLabel(string $label): self {
		return $this->meta(['append_label'=>trim($label)]);
	}

	/**
	 * Adds an icon adornment before the input control.
	 *
	 * Optional labels give renderers accessible text for icon-only adornments.
	 *
	 * @param string $icon Icon name shown before the input.
	 * @param ?string $label Optional accessible label for the icon.
	 * @return self.
	 */
	public function prependIcon(string $icon, ?string $label=null): self {
		return $this->inputIcon('prepend', $icon, $label);
	}

	/**
	 * Adds an icon adornment after the input control.
	 *
	 * Optional labels give renderers accessible text for icon-only adornments.
	 *
	 * @param string $icon Icon name shown after the input.
	 * @param ?string $label Optional accessible label for the icon.
	 * @return self.
	 */
	public function appendIcon(string $icon, ?string $label=null): self {
		return $this->inputIcon('append', $icon, $label);
	}

	/**
	 * Alias for prependIcon() used by prefix-oriented APIs.
	 *
	 * @param string $icon Icon name shown before the input.
	 * @param ?string $label Optional accessible label for the icon.
	 * @return self.
	 */
	public function prefixIcon(string $icon, ?string $label=null): self {
		return $this->prependIcon($icon, $label);
	}

	/**
	 * Alias for appendIcon() used by suffix-oriented APIs.
	 *
	 * @param string $icon Icon name shown after the input.
	 * @param ?string $label Optional accessible label for the icon.
	 * @return self.
	 */
	public function suffixIcon(string $icon, ?string $label=null): self {
		return $this->appendIcon($icon, $label);
	}

	/**
	 * Adds an action button before the input control.
	 *
	 * Button definitions are normalized before being appended so renderers receive a consistent label, action, and options shape.
	 *
	 * @param string $label Button label shown before the input.
	 * @param string $action Action identifier dispatched by the renderer.
	 * @param array<string, mixed> $options Field configuration options for the operation.
	 * @return self.
	 */
	public function prependButton(string $label, string $action='', array $options=[]): self {
		$buttons=is_array($this->meta['prepend_buttons'] ?? null) ? $this->meta['prepend_buttons'] : [];
		$buttons[]=self::normalizeFieldButton($label, $action, $options);
		return $this->meta(['prepend_buttons'=>$buttons]);
	}

	/**
	 * Adds an action button after the input control.
	 *
	 * Button definitions are normalized before being appended so renderers receive a consistent label, action, and options shape.
	 *
	 * @param string $label Button label shown after the input.
	 * @param string $action Action identifier dispatched by the renderer.
	 * @param array<string, mixed> $options Field configuration options for the operation.
	 * @return self.
	 */
	public function appendButton(string $label, string $action='', array $options=[]): self {
		$buttons=is_array($this->meta['append_buttons'] ?? null) ? $this->meta['append_buttons'] : [];
		$buttons[]=self::normalizeFieldButton($label, $action, $options);
		return $this->meta(['append_buttons'=>$buttons]);
	}

	/**
	 * Replaces all action buttons before the input control.
	 *
	 * Each entry is normalized into the same shape produced by prependButton().
	 *
	 * @param array<int|string, array<string, mixed>|string> $buttons Button declarations.
	 * @return self.
	 */
	public function prependButtons(array $buttons): self {
		return $this->meta(['prepend_buttons'=>self::normalizeFieldButtons($buttons)]);
	}

	/**
	 * Replaces all action buttons after the input control.
	 *
	 * Each entry is normalized into the same shape produced by appendButton().
	 *
	 * @param array<int|string, array<string, mixed>|string> $buttons Button declarations.
	 * @return self.
	 */
	public function appendButtons(array $buttons): self {
		return $this->meta(['append_buttons'=>self::normalizeFieldButtons($buttons)]);
	}

	/**
	 * Adds an input adornment icon to the prepend or append side of the field.
	 *
	 * position is constrained to prepend/append, blank icon tokens are ignored, and icon metadata is
	 * stored in field meta for the renderer to escape and display.
	 *
	 * @param string $position Requested icon position.
	 * @param string $icon Icon token or label.
	 * @param string|null $label Optional accessible label.
	 * @return self Mutated field definition.
	 */
	private function inputIcon(string $position, string $icon, ?string $label=null): self {
		$position=self::normalizeName($position)==='append' ? 'append' : 'prepend';
		$icon=trim($icon);
		if($icon===''){
			return $this;
		}
		$key=$position.'_icons';
		$icons=is_array($this->meta[$key] ?? null) ? $this->meta[$key] : [];
		$icons[]=[
			'icon'=>$icon,
			'label'=>$label!==null ? trim($label) : '',
		];
		return $this->meta([$key=>$icons]);
	}

	/**
	 * Sets the option label shown for an empty selection.
	 *
	 * @param string $label Empty-option label emitted to select-like renderers.
	 * @return self.
	 */
	public function emptyLabel(string $label): self {
		return $this->meta(['empty'=>$label]);
	}

	/**
	 * Sets supplemental description text for display-oriented fields.
	 *
	 * @param string $description Description text stored without surrounding whitespace.
	 * @return self.
	 */
	public function description(string $description): self {
		return $this->meta(['description'=>trim($description)]);
	}

	/**
	 * Marks display content as pre-rendered HTML.
	 *
	 * Renderers can use this flag to skip plain-text escaping for content supplied
	 * through the display helpers. Callers must only enable it for trusted markup.
	 *
	 * @param bool $html Whether display content should be treated as trusted HTML.
	 * @return self.
	 */
	public function html(bool $html=true): self {
		return $this->meta(['html'=>$html]);
	}

	/**
	 * Stores display-only content for renderers.
	 *
	 * Array and object content is JSON encoded for a stable scalar manifest value.
	 * The HTML flag controls renderer escaping and is not a sanitizer.
	 *
	 * @param mixed $content Scalar, array, or object content shown by display fields.
	 * @param bool $html Whether the stored content is trusted HTML.
	 * @return self.
	 */
	public function content(mixed $content, bool $html=false): self {
		if(is_array($content) || is_object($content)){
			$content=json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
		}
		return $this->meta([
			'display_content'=>(string)$content,
			'html'=>$html,
		]);
	}

	/**
	 * Stores trusted HTML display content.
	 *
	 * This is a convenience wrapper around content() with HTML rendering enabled.
	 *
	 * @param string $content Trusted markup shown by display renderers.
	 * @return self.
	 */
	public function htmlContent(string $content): self {
		return $this->content($content, true);
	}

	/**
	 * Converts the field into a non-input placeholder.
	 *
	 * When content is supplied it is stored on the cloned placeholder field;
	 * otherwise only the type changes.
	 *
	 * @param mixed $content Optional placeholder content shown by the renderer.
	 * @param bool $html Whether the supplied content is trusted HTML.
	 * @return self.
	 */
	public function placeholderField(mixed $content=null, bool $html=false): self {
		$field=$this->type('placeholder');
		return func_num_args()>0 ? $field->content($content, $html) : $field;
	}

	/**
	 * Converts the field into a read-only display value.
	 *
	 * Optional content is embedded into the field manifest instead of being read
	 * from submitted input.
	 *
	 * @param mixed $content Optional display content stored on the field.
	 * @param bool $html Whether the supplied content is trusted HTML.
	 * @return self.
	 */
	public function displayOnly(mixed $content=null, bool $html=false): self {
		$field=$this->type('display_only');
		return func_num_args()>0 ? $field->content($content, $html) : $field;
	}

	/**
	 * Converts the field into a view-only panel field.
	 *
	 * View fields can carry fixed content and do not represent editable request
	 * input.
	 *
	 * @param mixed $content Optional view content stored on the field.
	 * @param bool $html Whether the supplied content is trusted HTML.
	 * @return self.
	 */
	public function viewField(mixed $content=null, bool $html=false): self {
		$field=$this->type('view_field');
		return func_num_args()>0 ? $field->content($content, $html) : $field;
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param array<string, mixed> $options Field configuration options for the operation.
	 * @return self.
	 */
	public function options(array $options): self {
		$clone=clone $this;
		$clone->options=$options;
		return $clone;
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param string|int|float $value Submitted value represented by the option.
	 * @param string $label Human label shown in choice renderers.
	 * @param ?string $description Optional helper text shown beside the option.
	 * @param bool $disabled Whether the option is visible but not selectable.
	 * @return self.
	 */
	public function option(string|int|float $value, string $label, ?string $description=null, bool $disabled=false): self {
		$options=$this->options;
		$options[(string)$value]=self::optionDefinition($value, $label, $description, $disabled);
		return $this->options($options);
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param string|int|float $value Submitted value represented by the option.
	 * @param string $label Human label shown in choice renderers.
	 * @param ?string $description Optional helper text shown beside the option.
	 * @return self.
	 */
	public function disabledOption(string|int|float $value, string $label, ?string $description=null): self {
		return $this->option($value, $label, $description, true);
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param string $label Human label shown for the option group.
	 * @param array<string, mixed> $options Option definitions nested under the group.
	 * @param ?string $key Explicit group key, or null to derive one from the label.
	 * @param bool $disabled Whether every option in the group starts disabled.
	 * @return self.
	 */
	public function optionGroup(string $label, array $options, ?string $key=null, bool $disabled=false): self {
		$allOptions=$this->options;
		$groupKey=$key!==null && trim($key)!=='' ? trim($key) : self::normalizeName($label);
		$allOptions[$groupKey!=='' ? $groupKey : 'group_'.(count($allOptions)+1)]=[
			'label'=>trim($label),
			'options'=>$options,
			'disabled'=>$disabled,
		];
		return $this->options($allOptions);
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function optionsUsing(callable $callback): self {
		$clone=clone $this;
		$clone->optionsCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Converts the field into a single-value select control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @return self.
	 */
	public function select(array $options=[]): self {
		$field=$this->type('select');
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into an enum-backed select control.
	 *
	 * @param array<string, mixed> $options Initial enum option definitions.
	 * @return self.
	 */
	public function enum(array $options=[]): self {
		$field=$this->type('enum');
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a multi-value select control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @return self.
	 */
	public function multiSelect(array $options=[]): self {
		$field=$this->type('multi_select')->multiple();
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a searchable country-code select.
	 *
	 * When no country list is supplied, the control uses the framework's default
	 * country set and stores normalized ISO-style country codes.
	 *
	 * @param ?array $countries Country codes to expose, or null for the default set.
	 * @return self.
	 */
	public function countrySelect(?array $countries=null): self {
		$options=self::countryOptions($countries ?? ['CA', 'US', 'GB', 'AU', 'NZ', 'FR', 'DE', 'NL', 'IE']);
		return $this->select($options)->countryCode()->searchable();
	}

	/**
	 * Converts the field into a searchable subdivision-code select.
	 *
	 * The country code is normalized before subdivision options and metadata are
	 * generated.
	 *
	 * @param string $country Country code used to choose subdivision options.
	 * @return self.
	 */
	public function subdivisionSelect(string $country='CA'): self {
		$country=self::normalizeCountryCode($country);
		return $this->select(self::subdivisionOptions($country))->subdivisionCodeForCountry($country)->searchable();
	}

	/**
	 * Converts the field into a single related-resource selector.
	 *
	 * Relationship metadata tells Panel which resource to query and which record
	 * attributes to use for submitted keys and display labels.
	 *
	 * @param string $relatedResource Related Panel resource name.
	 * @param array<string, mixed> $options Preloaded options for the relationship control.
	 * @param string $titleAttribute Related record attribute used as the label.
	 * @param string $keyAttribute Related record attribute used as the submitted key.
	 * @return self.
	 */
	public function relationship(string $relatedResource, array $options=[], string $titleAttribute='name', string $keyAttribute='id'): self {
		$field=$this->type('relationship')->relatedResource($relatedResource)->titleAttribute($titleAttribute)->keyAttribute($keyAttribute)->searchable();
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a belongs-to relationship selector.
	 *
	 * This uses the same relationship metadata as relationship() while marking the
	 * field type for belongs-to rendering.
	 *
	 * @param string $relatedResource Related Panel resource name.
	 * @param array<string, mixed> $options Preloaded options for the relationship control.
	 * @param string $titleAttribute Related record attribute used as the label.
	 * @param string $keyAttribute Related record attribute used as the submitted key.
	 * @return self.
	 */
	public function belongsTo(string $relatedResource, array $options=[], string $titleAttribute='name', string $keyAttribute='id'): self {
		return $this->relationship($relatedResource, $options, $titleAttribute, $keyAttribute)->type('belongs_to');
	}

	/**
	 * Converts the field into a multi-value related-resource selector.
	 *
	 * The field stores multiple related keys and carries resource/title/key metadata
	 * for Panel search and rendering.
	 *
	 * @param string $relatedResource Related Panel resource name.
	 * @param array<string, mixed> $options Preloaded options for the relationship control.
	 * @param string $titleAttribute Related record attribute used as the label.
	 * @param string $keyAttribute Related record attribute used as the submitted key.
	 * @return self.
	 */
	public function multiRelationship(string $relatedResource, array $options=[], string $titleAttribute='name', string $keyAttribute='id'): self {
		$field=$this->type('multi_relationship')->multiple()->relatedResource($relatedResource)->titleAttribute($titleAttribute)->keyAttribute($keyAttribute)->searchable();
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a belongs-to-many relationship selector.
	 *
	 * This reuses multiRelationship() metadata and changes the type for relation
	 * persistence/rendering.
	 *
	 * @param string $relatedResource Related Panel resource name.
	 * @param array<string, mixed> $options Preloaded options for the relationship control.
	 * @param string $titleAttribute Related record attribute used as the label.
	 * @param string $keyAttribute Related record attribute used as the submitted key.
	 * @return self.
	 */
	public function belongsToMany(string $relatedResource, array $options=[], string $titleAttribute='name', string $keyAttribute='id'): self {
		return $this->multiRelationship($relatedResource, $options, $titleAttribute, $keyAttribute)->type('belongs_to_many');
	}

	/**
	 * Sets the related Panel resource name.
	 *
	 * Resource names are normalized before being written to field metadata.
	 *
	 * @param string $resource Related Panel resource name.
	 * @return self.
	 */
	public function relatedResource(string $resource): self {
		return $this->meta(['related_resource'=>self::normalizeName($resource)]);
	}

	/**
	 * Sets the related-record attribute used for display labels.
	 *
	 * Empty normalized values fall back to `name`.
	 *
	 * @param string $attribute Related record attribute name.
	 * @return self.
	 */
	public function titleAttribute(string $attribute): self {
		return $this->meta(['title_attribute'=>self::normalizeName($attribute) ?: 'name']);
	}

	/**
	 * Sets the related-record attribute used as the submitted key.
	 *
	 * Empty normalized values fall back to `id`.
	 *
	 * @param string $attribute Related record key attribute name.
	 * @return self.
	 */
	public function keyAttribute(string $attribute): self {
		return $this->meta(['key_attribute'=>self::normalizeName($attribute) ?: 'id']);
	}

	/**
	 * Converts the field into a radio-choice control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @return self.
	 */
	public function radio(array $options=[]): self {
		$field=$this->type('radio');
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a checkbox-list choice control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @return self.
	 */
	public function checkboxList(array $options=[]): self {
		$field=$this->type('checkbox_list');
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into button-styled choices.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @param bool $multiple Whether the renderer may submit more than one value.
	 * @return self.
	 */
	public function toggleButtons(array $options=[], bool $multiple=false): self {
		$field=$this->type('toggle_buttons')->meta(['multiple'=>$multiple, 'choice_style'=>'buttons']);
		return $options===[] ? $field : $field->options($options);
	}

	/**
	 * Converts the field into a segmented choice control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @param bool $multiple Whether the renderer may submit more than one value.
	 * @return self.
	 */
	public function segmentedControl(array $options=[], bool $multiple=false): self {
		return $this->toggleButtons($options, $multiple)->type('segmented_control');
	}

	/**
	 * Converts the field into a grouped button choice control.
	 *
	 * @param array<string, mixed> $options Initial option definitions keyed by submitted value.
	 * @param bool $multiple Whether the renderer may submit more than one value.
	 * @return self.
	 */
	public function buttonGroup(array $options=[], bool $multiple=false): self {
		return $this->toggleButtons($options, $multiple)->type('button_group');
	}

	/**
	 * Converts the field into a boolean value control.
	 *
	 * @param string $onLabel Label displayed for the true/on state.
	 * @param string $offLabel Label displayed for the false/off state.
	 * @return self.
	 */
	public function boolean(string $onLabel='Enabled', string $offLabel='Disabled'): self {
		return $this->type('boolean')->booleanLabels($onLabel, $offLabel);
	}

	/**
	 * Converts the field into a toggle control.
	 *
	 * @param string $onLabel Label displayed for the true/on state.
	 * @param string $offLabel Label displayed for the false/off state.
	 * @return self.
	 */
	public function toggle(string $onLabel='Enabled', string $offLabel='Disabled'): self {
		return $this->type('toggle')->booleanLabels($onLabel, $offLabel);
	}

	/**
	 * Converts the field into a single checkbox control.
	 *
	 * @param string $onLabel Label displayed for the checked state.
	 * @param string $offLabel Label displayed for the unchecked state.
	 * @return self.
	 */
	public function checkbox(string $onLabel='Enabled', string $offLabel='Disabled'): self {
		return $this->type('checkbox')->booleanLabels($onLabel, $offLabel);
	}

	/**
	 * Sets labels used by boolean-style renderers.
	 *
	 * Blank labels fall back to the framework defaults so manifests always include
	 * usable text for both states.
	 *
	 * @param string $onLabel Label displayed for the true/on state.
	 * @param string $offLabel Label displayed for the false/off state.
	 * @return self.
	 */
	public function booleanLabels(string $onLabel='Enabled', string $offLabel='Disabled'): self {
		return $this->meta([
			'on_label'=>trim($onLabel) ?: 'Enabled',
			'off_label'=>trim($offLabel) ?: 'Disabled',
		]);
	}

	/**
	 * Sets the true/on label for boolean-style renderers.
	 *
	 * Blank labels fall back to `Enabled`.
	 *
	 * @param string $label Label displayed for the true/on state.
	 * @return self.
	 */
	public function onLabel(string $label): self {
		return $this->meta(['on_label'=>trim($label) ?: 'Enabled']);
	}

	/**
	 * Sets the false/off label for boolean-style renderers.
	 *
	 * Blank labels fall back to `Disabled`.
	 *
	 * @param string $label Label displayed for the false/off state.
	 * @return self.
	 */
	public function offLabel(string $label): self {
		return $this->meta(['off_label'=>trim($label) ?: 'Disabled']);
	}

	/**
	 * Sets the number of columns used by choice renderers.
	 *
	 * The stored value is clamped between one and six columns.
	 *
	 * @param int $columns Requested choice column count.
	 * @return self.
	 */
	public function choiceColumns(int $columns): self {
		return $this->meta(['choice_columns'=>max(1, min(6, $columns))]);
	}

	/**
	 * Controls whether choice renderers may display options inline.
	 *
	 *
	 * @param bool $inline Whether choices should be rendered inline.
	 * @return self.
	 */
	public function inlineChoices(bool $inline=true): self {
		return $this->meta(['inline_choices'=>$inline]);
	}

	/**
	 * Forces choice renderers into a one-column stacked layout.
	 *
	 * @return self.
	 */
	public function stackedChoices(): self {
		return $this->inlineChoices(false)->choiceColumns(1);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function repeaterFields(array $fields): self {
		$normalized=self::normalizeChildFieldDefinitions($fields);
		return $this->type('repeater')->meta(['repeater_fields'=>$normalized]);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @param int $minItems Minimum number of child item rows.
	 * @param ?int $maxItems Maximum number of child item rows, or null for no cap.
	 * @return self.
	 */
	public function repeater(array $fields=[], int $minItems=0, ?int $maxItems=null): self {
		$field=$this->repeaterFields($fields)->minItems($minItems);
		return $maxItems===null ? $field : $field->maxItems($maxItems);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param Field|array|string $field Field.
	 * @param ?string $type Type.
	 * @return self.
	 */
	public function repeaterField(Field|array|string $field, ?string $type=null): self {
		$fields=is_array($this->meta['repeater_fields'] ?? null) ? $this->meta['repeater_fields'] : [];
		$field=$field instanceof Field ? $field : (is_array($field) ? self::fromArray($field) : self::make((string)$field, $type ?? 'text'));
		if($field->name()!==''){
			$fields[]=$field->toArray();
		}
		return $this->type('repeater')->meta(['repeater_fields'=>$fields]);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function childFields(array $fields): self {
		return $this->meta(['child_fields'=>self::normalizeChildFieldDefinitions($fields)]);
	}

	/**
	 * Converts the field into a fieldset of child controls.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function fieldset(array $fields=[]): self {
		$field=$this->type('fieldset');
		return $fields===[] ? $field : $field->childFields($fields);
	}

	/**
	 * Converts the field into a grouped set of child controls.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function fieldGroup(array $fields=[]): self {
		$field=$this->type('field_group');
		return $fields===[] ? $field : $field->childFields($fields);
	}

	/**
	 * Converts the field into a generic child-field group.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function group(array $fields=[]): self {
		$field=$this->type('group');
		return $fields===[] ? $field : $field->childFields($fields);
	}

	/**
	 * Converts the field into a structured address group.
	 *
	 * The generated child fields share country-aware subdivision and postal-code
	 * metadata, autocomplete hints, and fixed column spans.
	 *
	 * @param string $country Country code used for subdivision and postal validation.
	 * @param bool $includeLine2 Whether to include an optional second address line.
	 * @return self.
	 */
	public function address(string $country='CA', bool $includeLine2=true): self {
		$country=strtoupper(trim($country)) ?: 'CA';
		$fields=[
			self::make('line1')->label('Address line 1')->autocomplete('address-line1')->trimmed()->maxLength(160)->columnSpan(['default'=>'full', 'md'=>6]),
		];
		if($includeLine2){
			$fields[]=self::make('line2')->label('Address line 2')->autocomplete('address-line2')->trimmed()->maxLength(120)->nullable()->columnSpan(['default'=>'full', 'md'=>6]);
		}
		$fields[] = self::make('city')->label('City')->autocomplete('address-level2')->titleCase()->maxLength(80)->columnSpan(['md'=>3]);
		$fields[] = self::make('subdivision')->label('Province / state')->subdivisionSelect($country)->autocomplete('address-level1')->columnSpan(['md'=>3]);
		$fields[] = self::make('postal_code')->label('Postal code')->autocomplete('postal-code')->postalCode($country)->formatSubdivisionField('subdivision')->columnSpan(['md'=>3]);
		$fields[] = self::make('country')->label('Country')->countrySelect([$country])->autocomplete('country')->default($country)->columnSpan(['md'=>3]);
		return $this
			->type('address')
			->childFields($fields)
			->meta([
				'address_country'=>$country,
				'address_line2'=>$includeLine2,
				'description'=>'Structured address fields share country, subdivision, and postal-code validation.',
			]);
	}

	/**
	 * Appends one child field definition to the current group metadata.
	 *
	 * Array definitions pass through fromArray(); scalar values become fields with
	 * the supplied type or text by default. Empty child names are ignored.
	 *
	 * @param Field|array|string $field Child field instance, array manifest, or name.
	 * @param ?string $type Field type used when $field is a scalar name.
	 * @return self.
	 */
	public function groupField(Field|array|string $field, ?string $type=null): self {
		$fields=is_array($this->meta['child_fields'] ?? null) ? $this->meta['child_fields'] : [];
		$field=$field instanceof Field ? $field : (is_array($field) ? self::fromArray($field) : self::make((string)$field, $type ?? 'text'));
		if($field->name()!==''){
			$fields[]=$field->toArray();
		}
		return $this->childFields($fields);
	}

	/**
	 * Converts the field into a block builder.
	 *
	 * Builder blocks are normalized into manifest payloads and item counts are
	 * clamped by the same min/max helpers used by repeaters.
	 *
	 * @param array<string, array<string, mixed>|Field|array<int, mixed>|string> $blocks Builder block definitions keyed by block name.
	 * @param int $minItems Minimum number of builder items.
	 * @param ?int $maxItems Maximum number of builder items, or null for no cap.
	 * @return self.
	 */
	public function builder(array $blocks=[], int $minItems=0, ?int $maxItems=null): self {
		$field=$this->type('builder')->builderBlocks($blocks)->minItems($minItems);
		return $maxItems===null ? $field : $field->maxItems($maxItems);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param array<string, array<string, mixed>|Field|array<int, mixed>|string> $blocks Builder block definitions keyed by block name.
	 * @return self.
	 */
	public function builderBlocks(array $blocks): self {
		return $this->type('builder')->meta(['builder_blocks'=>self::normalizeBuilderBlocks($blocks)]);
	}

	/**
	 * Configures nested field structure.
	 *
	 * Nested definitions are normalized into child manifests for repeaters, builders, field groups, and address-style compound inputs.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @param ?string $label Display label for the block, or null to humanize the name.
	 * @return self.
	 */
	public function builderBlock(string $name, array $fields, ?string $label=null): self {
		$blocks=is_array($this->meta['builder_blocks'] ?? null) ? $this->meta['builder_blocks'] : [];
		$name=self::normalizeName($name);
		if($name===''){
			return $this->type('builder');
		}
		$blocks[$name]=[
			'name'=>$name,
			'label'=>trim((string)($label ?? self::humanize($name))),
			'fields'=>self::normalizeChildFieldDefinitions($fields),
		];
		return $this->type('builder')->meta(['builder_blocks'=>$blocks]);
	}

	/**
	 * Normalizes builder block definitions into manifest-ready block payloads.
	 *
	 * block names are normalized, labels default from names, child fields are converted through the
	 * same field definition pipeline, and unnamed blocks are discarded.
	 *
	 * @param array<string|int, mixed> $blocks Raw builder block definitions.
	 * @return array<string, array{name: string, label: string, fields: array<int, array>}> Normalized builder blocks keyed by block name.
	 */
	private static function normalizeBuilderBlocks(array $blocks): array {
		$normalized=[];
		foreach($blocks as $name=>$definition){
			if(is_array($definition) && isset($definition['fields']) && is_array($definition['fields'])){
				$blockName=self::normalizeName((string)($definition['name'] ?? $name));
				$label=trim((string)($definition['label'] ?? self::humanize($blockName)));
				$fields=$definition['fields'];
			}
			else {
				$blockName=self::normalizeName((string)$name);
				$label=self::humanize($blockName);
				$fields=is_array($definition) ? $definition : [];
			}
			if($blockName===''){
				continue;
			}
			$normalized[$blockName]=[
				'name'=>$blockName,
				'label'=>$label,
				'fields'=>self::normalizeChildFieldDefinitions($fields),
			];
		}
		return $normalized;
	}

	/**
	 * Normalizes nested field definitions into child field manifests.
	 *
	 * Field instances are serialized directly, array definitions pass through fromArray(), scalar
	 * values become text fields by name, and empty field names are omitted.
	 *
	 * @param array<int, mixed> $fields Raw child field definitions.
	 * @return array<int, array<string, mixed>> Child field manifests.
	 */
	private static function normalizeChildFieldDefinitions(array $fields): array {
		$normalized=[];
		foreach($fields as $field){
			$field=$field instanceof Field ? $field : (is_array($field) ? self::fromArray($field) : self::make((string)$field));
			if($field->name()!==''){
				$normalized[]=$field->toArray();
			}
		}
		return $normalized;
	}

	/**
	 * Sets the minimum item count for repeaters and builders.
	 *
	 * Negative values are stored as zero.
	 *
	 * @param int $count Requested minimum item count.
	 * @return self.
	 */
	public function minItems(int $count): self {
		return $this->meta(['min_items'=>max(0, $count)]);
	}

	/**
	 * Sets the maximum item count for repeaters and builders.
	 *
	 * Values below one are stored as one.
	 *
	 * @param int $count Requested maximum item count.
	 * @return self.
	 */
	public function maxItems(int $count): self {
		return $this->meta(['max_items'=>max(1, $count)]);
	}

	/**
	 * Sets the add-item button label for repeaters and builders.
	 *
	 * Blank labels fall back to `Add item`.
	 *
	 * @param string $label Button text shown when adding an item.
	 * @return self.
	 */
	public function addItemLabel(string $label): self {
		return $this->meta(['add_item_label'=>trim($label) ?: 'Add item']);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array|string $acceptedTypes Accepted MIME types or extensions.
	 * @param ?int $maxSize Maximum accepted file size in bytes.
	 * @param bool $multiple Whether the control may accept multiple files.
	 * @return self.
	 */
	public function file(array|string $acceptedTypes=[], ?int $maxSize=null, bool $multiple=false): self {
		$field=$this->type('file')->multiple($multiple);
		if($acceptedTypes!==[]){
			$field=$field->acceptedTypes($acceptedTypes);
		}
		if($maxSize!==null){
			$field=$field->maxFileSize($maxSize);
		}
		return $field;
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array|string $acceptedTypes Accepted MIME types or extensions.
	 * @param ?int $maxSize Maximum accepted file size in bytes.
	 * @param bool $multiple Whether the control may accept multiple files.
	 * @return self.
	 */
	public function fileUpload(array|string $acceptedTypes=[], ?int $maxSize=null, bool $multiple=false): self {
		return $this->file($acceptedTypes, $maxSize, $multiple)->type('file_upload');
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array|string $acceptedTypes Accepted MIME types or extensions.
	 * @param ?int $maxSize Maximum accepted file size in bytes.
	 * @param bool $multiple Whether the control may accept multiple files.
	 * @return self.
	 */
	public function upload(array|string $acceptedTypes=[], ?int $maxSize=null, bool $multiple=false): self {
		return $this->fileUpload($acceptedTypes, $maxSize, $multiple);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array|string $acceptedTypes Accepted MIME types or extensions.
	 * @param ?int $maxSize Maximum accepted file size in bytes.
	 * @param bool $multiple Whether the control may accept multiple files.
	 * @return self.
	 */
	public function dragDropUpload(array|string $acceptedTypes=[], ?int $maxSize=null, bool $multiple=false): self {
		return $this->fileUpload($acceptedTypes, $maxSize, $multiple)->customUploader(true)->type('drag_drop_upload');
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param ?int $maxSize Maximum accepted image size in bytes.
	 * @param bool $multiple Whether the control may accept multiple images.
	 * @return self.
	 */
	public function imageUpload(?int $maxSize=null, bool $multiple=false): self {
		return $this->fileUpload(['image/*'], $maxSize, $multiple)->type('image');
	}

	/**
	 * Sets accepted upload MIME types or extensions.
	 *
	 * Empty entries are trimmed out before the manifest is written.
	 *
	 * @param array|string $types Accepted MIME type, extension, or list of entries.
	 * @return self.
	 */
	public function acceptedTypes(array|string $types): self {
		$types=is_array($types) ? $types : [$types];
		$types=array_values(array_filter(array_map(
			static fn(mixed $type): string => trim((string)$type),
			$types
		)));
		return $this->meta(['accepted_types'=>$types]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $bytes Maximum accepted file size in bytes.
	 * @return self.
	 */
	public function maxFileSize(int $bytes): self {
		return $this->meta(['max_file_size'=>max(0, $bytes)]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $count Minimum number of files required.
	 * @return self.
	 */
	public function uploadMinFiles(int $count): self {
		return $this->meta(['upload_min_files'=>max(0, $count)]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $count Maximum number of files accepted.
	 * @return self.
	 */
	public function uploadMaxFiles(int $count): self {
		return $this->meta(['upload_max_files'=>max(1, $count)]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $count Minimum number of files required.
	 * @return self.
	 */
	public function minFiles(int $count): self {
		return $this->uploadMinFiles($count);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $count Maximum number of files accepted.
	 * @return self.
	 */
	public function maxFiles(int $count): self {
		return $this->uploadMaxFiles($count);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param bool $enabled Whether uploads use the custom/chunked uploader path.
	 * @param ?string $endpoint Upload endpoint URL or route path.
	 * @return self.
	 */
	public function customUploader(bool $enabled=true, ?string $endpoint=null): self {
		$meta=[
			'custom_uploader'=>$enabled,
			'chunked_upload'=>$enabled,
		];
		if($endpoint!==null && trim($endpoint)!==''){
			$meta['upload_endpoint']=trim($endpoint);
		}
		return $this->type('file_upload')->meta($meta);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $endpoint Upload endpoint URL or route path.
	 * @return self.
	 */
	public function uploadEndpoint(string $endpoint): self {
		$endpoint=trim($endpoint);
		return $endpoint==='' ? $this : $this->customUploader(true, $endpoint);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $endpoint Delete endpoint URL or route path.
	 * @return self.
	 */
	public function uploadDeleteEndpoint(string $endpoint): self {
		$endpoint=trim($endpoint);
		return $endpoint==='' ? $this : $this->meta(['upload_delete_endpoint'=>$endpoint]);
	}

	/**
	 * Sets the upload delete endpoint.
	 *
	 * This aliases uploadDeleteEndpoint() for fluent upload configuration.
	 *
	 * @param string $endpoint Delete endpoint URL or route path.
	 * @return self.
	 */
	public function deleteEndpoint(string $endpoint): self {
		return $this->uploadDeleteEndpoint($endpoint);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $bytes Requested chunk size in bytes.
	 * @return self.
	 */
	public function uploadChunkSize(int $bytes): self {
		return $this->meta(['upload_chunk_size'=>max(65536, min(52428800, $bytes))]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $retries Retry count for failed upload chunks.
	 * @return self.
	 */
	public function uploadRetries(int $retries): self {
		return $this->meta(['upload_retries'=>max(0, min(10, $retries))]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param int $concurrency Parallel upload request count.
	 * @return self.
	 */
	public function uploadConcurrency(int $concurrency): self {
		return $this->meta(['upload_concurrency'=>max(1, min(6, $concurrency))]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array<string, string> $headers Upload request headers.
	 * @return self.
	 */
	public function uploadHeaders(array $headers): self {
		$normalized=[];
		foreach($headers as $name=>$value){
			$name=trim((string)$name);
			if($name!=='' && preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)===1 && is_scalar($value)){
				$normalized[$name]=(string)$value;
			}
		}
		return $this->meta(['upload_headers'=>$normalized]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function uploadHeader(string $name, mixed $value): self {
		if(!is_scalar($value)){
			return $this;
		}
		$headers=is_array($this->meta['upload_headers'] ?? null) ? $this->meta['upload_headers'] : [];
		$headers[$name]=(string)$value;
		return $this->uploadHeaders($headers);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @return self.
	 */
	public function uploadFields(array $fields): self {
		$normalized=[];
		foreach($fields as $name=>$value){
			$name=trim((string)$name);
			if($name!=='' && is_scalar($value)){
				$normalized[$name]=(string)$value;
			}
		}
		return $this->meta(['upload_fields'=>$normalized]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $name Upload metadata field name.
	 * @param mixed $value Scalar value sent with upload requests.
	 * @return self.
	 */
	public function uploadField(string $name, mixed $value): self {
		if(!is_scalar($value)){
			return $this;
		}
		$fields=is_array($this->meta['upload_fields'] ?? null) ? $this->meta['upload_fields'] : [];
		$fields[$name]=(string)$value;
		return $this->uploadFields($fields);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param array<string, string> $labels Upload UI labels.
	 * @return self.
	 */
	public function uploadLabels(array $labels): self {
		$normalized=[];
		foreach($labels as $name=>$value){
			$name=trim((string)$name);
			if($name!=='' && is_scalar($value)){
				$normalized[$name]=trim((string)$value);
			}
		}
		return $this->meta(['upload_labels'=>$normalized]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $name Upload label key.
	 * @param string $label Human text shown by the upload UI.
	 * @return self.
	 */
	public function uploadLabel(string $name, string $label): self {
		$labels=is_array($this->meta['upload_labels'] ?? null) ? $this->meta['upload_labels'] : [];
		$labels[$name]=$label;
		return $this->uploadLabels($labels);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $formName Form name used to resolve the CSRF token.
	 * @param string $fieldName Request field that carries the CSRF token.
	 * @param string $header Header name used for upload CSRF validation.
	 * @return self.
	 */
	public function uploadCsrf(string $formName, string $fieldName='csrf', string $header='X-CSRF-Token'): self {
		$formName=trim($formName);
		if($formName===''){
			return $this;
		}
		return $this->meta([
			'upload_csrf_form'=>$formName,
			'upload_csrf_field'=>trim($fieldName) ?: 'csrf',
			'upload_csrf_header'=>trim($header) ?: 'X-CSRF-Token',
		]);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $disk Storage disk used for uploaded files.
	 * @param string $path Storage path template for uploaded files.
	 * @param ?string $endpoint Optional upload/delete endpoint URL or route path.
	 * @return self.
	 */
	public function storageUploader(string $disk='local', string $path='panel_uploads/{date}/{filename}', ?string $endpoint=null): self {
		$meta=[
			'upload_driver'=>'dataphyre_storage',
			'storage_disk'=>self::normalizeName($disk) ?: 'local',
			'storage_path'=>trim($path, "\\/") ?: 'panel_uploads/{date}/{filename}',
		];
		if($endpoint!==null && trim($endpoint)!==''){
			$meta['upload_delete_endpoint']=trim($endpoint);
		}
		return $this
			->customUploader(true, $endpoint)
			->meta($meta);
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 *
	 * @param string $disk Storage disk used for uploaded files.
	 * @param string $path Storage path template for uploaded files.
	 * @param ?string $endpoint Optional upload/delete endpoint URL or route path.
	 * @return self.
	 */
	public function dataphyreStorageUpload(string $disk='local', string $path='panel_uploads/{date}/{filename}', ?string $endpoint=null): self {
		return $this->storageUploader($disk, $path, $endpoint);
	}

	/**
	 * Attaches a media collection manifest to the field.
	 *
	 * String collection names are normalized; arrays and collection objects are
	 * converted into full collection manifests for the renderer.
	 *
	 * @param PanelMediaCollection|array|string $collection Media collection definition.
	 * @return self.
	 */
	public function mediaCollection(PanelMediaCollection|array|string $collection): self {
		$manifest=$collection instanceof PanelMediaCollection
			? $collection->manifest()
			: (is_array($collection) ? PanelMediaCollection::from($collection)->manifest() : ['name'=>self::normalizeName($collection)]);
		return $this->meta([
			'media_collection'=>(string)($manifest['name'] ?? 'default'),
			'media_collection_manifest'=>$manifest,
		]);
	}

	/**
	 * Stores media variant definitions for upload/image renderers.
	 *
	 * @param array<string, array<string, mixed>|string> $variants Media variant definitions keyed by variant name.
	 * @return self.
	 */
	public function mediaVariants(array $variants): self {
		return $this->meta(['media_variants'=>$variants]);
	}

	/**
	 * Sets the visible row count for multiline controls.
	 *
	 * The stored value is clamped between one and sixty rows.
	 *
	 * @param int $rows Requested visible row count.
	 * @return self.
	 */
	public function rows(int $rows): self {
		return $this->meta(['rows'=>max(1, min(60, $rows))]);
	}

	/**
	 * Enables or disables automatic height growth for multiline controls.
	 *
	 * @param bool $enabled Whether the renderer may resize the input height.
	 * @return self.
	 */
	public function autoResize(bool $enabled=true): self {
		return $this->meta(['auto_resize'=>$enabled]);
	}

	/**
	 * Alias for autoResize().
	 *
	 * @param bool $enabled Whether the renderer may resize the input height.
	 * @return self.
	 */
	public function autosize(bool $enabled=true): self {
		return $this->autoResize($enabled);
	}

	/**
	 * Stores the lower bound metadata for numeric, date, and range controls.
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function min(mixed $value): self {
		return $this->meta(['min'=>$value]);
	}

	/**
	 * Stores the upper bound metadata for numeric, date, and range controls.
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function max(mixed $value): self {
		return $this->meta(['max'=>$value]);
	}

	/**
	 * Stores the increment metadata for browser-backed controls.
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function step(mixed $value): self {
		return $this->meta(['step'=>$value]);
	}

	/**
	 * Converts the field into a date input with optional bounds.
	 *
	 *
	 * @param ?string $min Min.
	 * @param ?string $max Max.
	 * @return self.
	 */
	public function date(?string $min=null, ?string $max=null): self {
		$field=$this->type('date');
		if($min!==null && trim($min)!==''){
			$field=$field->minDate($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxDate($max);
		}
		return $field;
	}

	/**
	 * Converts the field into a date-time input with optional bounds.
	 *
	 *
	 * @param ?string $min Min.
	 * @param ?string $max Max.
	 * @return self.
	 */
	public function dateTime(?string $min=null, ?string $max=null): self {
		$field=$this->type('datetime');
		if($min!==null && trim($min)!==''){
			$field=$field->minDateTime($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxDateTime($max);
		}
		return $field;
	}

	/**
	 * Alias for dateTime() using local date-time metadata.
	 *
	 *
	 * @param ?string $min Min.
	 * @param ?string $max Max.
	 * @return self.
	 */
	public function dateTimeLocal(?string $min=null, ?string $max=null): self {
		return $this->dateTime($min, $max)->type('datetime_local');
	}

	/**
	 * Converts the field into a time input with optional bounds and step metadata.
	 *
	 *
	 * @param ?string $min Min.
	 * @param ?string $max Max.
	 * @param ?string $step Step.
	 * @return self.
	 */
	public function time(?string $min=null, ?string $max=null, ?string $step=null): self {
		$field=$this->type('time');
		if($min!==null && trim($min)!==''){
			$field=$field->minTime($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxTime($max);
		}
		if($step!==null && trim($step)!==''){
			$field=$field->step($step);
		}
		return $field;
	}

	/**
	 * Converts the field into a date range control.
	 *
	 *
	 * @param ?string $min Min.
	 * @param ?string $max Max.
	 * @return self.
	 */
	public function dateRange(?string $min=null, ?string $max=null): self {
		$field=$this->type('date_range');
		if($min!==null && trim($min)!==''){
			$field=$field->minDate($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxDate($max);
		}
		return $field;
	}

	/**
	 * Converts the field into a date-time range control.
	 *
	 * Non-empty bounds are trimmed and stored as min/max date-time metadata for
	 * renderer-side validation.
	 *
	 * @param ?string $min Earliest accepted date-time value.
	 * @param ?string $max Latest accepted date-time value.
	 * @return self.
	 */
	public function dateTimeRange(?string $min=null, ?string $max=null): self {
		$field=$this->type('datetime_range');
		if($min!==null && trim($min)!==''){
			$field=$field->minDateTime($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxDateTime($max);
		}
		return $field;
	}

	/**
	 * Converts the field into a time range control.
	 *
	 * Non-empty bounds and step values are stored as time metadata without parsing
	 * or timezone conversion.
	 *
	 * @param ?string $min Earliest accepted time value.
	 * @param ?string $max Latest accepted time value.
	 * @param ?string $step Time step accepted by the renderer/browser.
	 * @return self.
	 */
	public function timeRange(?string $min=null, ?string $max=null, ?string $step=null): self {
		$field=$this->type('time_range');
		if($min!==null && trim($min)!==''){
			$field=$field->minTime($min);
		}
		if($max!==null && trim($max)!==''){
			$field=$field->maxTime($max);
		}
		if($step!==null && trim($step)!==''){
			$field=$field->step($step);
		}
		return $field;
	}

	/**
	 * Converts the field into a generic numeric input.
	 *
	 * Non-empty min, max, and step values are copied into manifest metadata for the
	 * renderer; this helper does not coerce submitted values.
	 *
	 * @param mixed $min Optional lower bound metadata.
	 * @param mixed $max Optional upper bound metadata.
	 * @param mixed $step Optional increment metadata.
	 * @return self.
	 */
	public function number(mixed $min=null, mixed $max=null, mixed $step=null): self {
		return $this->numeric('number', $min, $max, $step);
	}

	/**
	 * Converts the field into an integer input.
	 *
	 * The default step is one and the input mode is set to numeric for mobile
	 * keyboards.
	 *
	 * @param mixed $min Optional lower bound metadata.
	 * @param mixed $max Optional upper bound metadata.
	 * @param mixed $step Optional increment metadata.
	 * @return self.
	 */
	public function integer(mixed $min=null, mixed $max=null, mixed $step=1): self {
		return $this->numeric('integer', $min, $max, $step)->inputMode('numeric');
	}

	/**
	 * Converts the field into a decimal-capable float input.
	 *
	 * The default step is `any` and the input mode is set to decimal for mobile
	 * keyboards.
	 *
	 * @param mixed $min Optional lower bound metadata.
	 * @param mixed $max Optional upper bound metadata.
	 * @param mixed $step Optional increment metadata.
	 * @return self.
	 */
	public function float(mixed $min=null, mixed $max=null, mixed $step='any'): self {
		return $this->numeric('float', $min, $max, $step)->inputMode('decimal');
	}

	/**
	 * Converts the field into a fixed-scale decimal input.
	 *
	 * Scale is clamped between zero and ten, then converted into the matching step
	 * value for browser/rendered input metadata.
	 *
	 * @param int $scale Number of decimal places to preserve.
	 * @param mixed $min Optional lower bound metadata.
	 * @param mixed $max Optional upper bound metadata.
	 * @return self.
	 */
	public function decimal(int $scale=2, mixed $min=null, mixed $max=null): self {
		$scale=max(0, min(10, $scale));
		return $this
			->numeric('decimal', $min, $max, self::decimalStep($scale))
			->inputMode('decimal')
			->meta(['decimal_scale'=>$scale]);
	}

	/**
	 * Applies numeric field metadata for supported numeric control types.
	 *
	 * Unknown numeric types fall back to `number`. Bound and step metadata is stored
	 * only when the supplied value is non-null and non-empty after string casting.
	 *
	 * @param string $type Numeric control type requested by the caller.
	 * @param mixed $min Optional lower bound metadata.
	 * @param mixed $max Optional upper bound metadata.
	 * @param mixed $step Optional increment metadata.
	 * @return self.
	 */
	public function numeric(string $type='number', mixed $min=null, mixed $max=null, mixed $step=null): self {
		$type=self::normalizeName($type);
		if(!in_array($type, ['number', 'integer', 'float', 'decimal'], true)){
			$type='number';
		}
		$field=$this->type($type);
		if($min!==null && trim((string)$min)!==''){
			$field=$field->min($min);
		}
		if($max!==null && trim((string)$max)!==''){
			$field=$field->max($max);
		}
		if($step!==null && trim((string)$step)!==''){
			$field=$field->step($step);
		}
		return $field;
	}

	/**
	 * Alias for min().
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function minValue(mixed $value): self {
		return $this->min($value);
	}

	/**
	 * Alias for max().
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function maxValue(mixed $value): self {
		return $this->max($value);
	}

	/**
	 * Alias for step().
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function stepValue(mixed $value): self {
		return $this->step($value);
	}

	/**
	 * Converts the field into a single-line text input.
	 *
	 * When provided, max length is stored through maxLength() for renderer and
	 * validation metadata.
	 *
	 * @param ?int $maxLength Maximum text length, or null for no field-level limit.
	 * @return self.
	 */
	public function text(?int $maxLength=null): self {
		$field=$this->type('text');
		return $maxLength===null ? $field : $field->maxLength($maxLength);
	}

	/**
	 * Alias for text().
	 *
	 * @param ?int $maxLength Maximum text length, or null for no field-level limit.
	 * @return self.
	 */
	public function textInput(?int $maxLength=null): self {
		return $this->text($maxLength);
	}

	/**
	 * Converts the field into a search input.
	 *
	 * Search controls disable browser autocomplete and use search input mode.
	 *
	 * @param ?int $maxLength Maximum query length, or null for no field-level limit.
	 * @return self.
	 */
	public function search(?int $maxLength=null): self {
		$field=$this->type('search')->inputMode('search')->autocomplete('off');
		return $maxLength===null ? $field : $field->maxLength($maxLength);
	}

	/**
	 * Converts the field into a multiline textarea.
	 *
	 * Row counts are clamped by rows(); auto-resize metadata is stored explicitly so
	 * renderers can opt in or out.
	 *
	 * @param int $rows Requested visible row count.
	 * @param bool $autoResize Whether the renderer may grow the textarea height.
	 * @return self.
	 */
	public function textarea(int $rows=5, bool $autoResize=true): self {
		$field=$this->type('textarea')->rows($rows);
		return $autoResize ? $field->autoResize() : $field->autoResize(false);
	}

	/**
	 * Alias for textarea() with a larger default row count.
	 *
	 * @param int $rows Requested visible row count.
	 * @param bool $autoResize Whether the renderer may grow the textarea height.
	 * @return self.
	 */
	public function longText(int $rows=8, bool $autoResize=true): self {
		return $this->textarea($rows, $autoResize);
	}

	/**
	 * Converts the field into a password input.
	 *
	 * Revealability is exposed as renderer metadata. Blank autocomplete values are
	 * ignored instead of writing an empty attribute.
	 *
	 * @param bool $revealable Whether the UI may expose a reveal toggle.
	 * @param string $autocomplete Browser autocomplete token.
	 * @return self.
	 */
	public function password(bool $revealable=true, string $autocomplete='current-password'): self {
		$field=$this
			->type('password')
			->inputMode('text')
			->revealable($revealable);
		$autocomplete=trim($autocomplete);
		return $autocomplete==='' ? $field : $field->autocomplete($autocomplete);
	}

	/**
	 * Configures a password input for the current password credential.
	 *
	 * @param bool $revealable Whether the UI may expose a reveal toggle.
	 * @return self.
	 */
	public function currentPassword(bool $revealable=true): self {
		return $this->password($revealable, 'current-password');
	}

	/**
	 * Configures a password input for a new password credential.
	 *
	 * @param bool $revealable Whether the UI may expose a reveal toggle.
	 * @return self.
	 */
	public function newPassword(bool $revealable=true): self {
		return $this->password($revealable, 'new-password');
	}

	/**
	 * Configures a password confirmation input.
	 *
	 * @param bool $revealable Whether the UI may expose a reveal toggle.
	 * @return self.
	 */
	public function passwordConfirmation(bool $revealable=true): self {
		return $this->newPassword($revealable)->autocomplete('new-password');
	}

	/**
	 * Stores the minimum accepted date value.
	 *
	 * @param string $date Date string written to min metadata after trimming.
	 * @return self.
	 */
	public function minDate(string $date): self {
		return $this->min(trim($date));
	}

	/**
	 * Stores the maximum accepted date value.
	 *
	 * @param string $date Date string written to max metadata after trimming.
	 * @return self.
	 */
	public function maxDate(string $date): self {
		return $this->max(trim($date));
	}

	/**
	 * Stores the minimum accepted date-time value.
	 *
	 * @param string $dateTime Date-time string written to min metadata after trimming.
	 * @return self.
	 */
	public function minDateTime(string $dateTime): self {
		return $this->min(trim($dateTime));
	}

	/**
	 * Stores the maximum accepted date-time value.
	 *
	 * @param string $dateTime Date-time string written to max metadata after trimming.
	 * @return self.
	 */
	public function maxDateTime(string $dateTime): self {
		return $this->max(trim($dateTime));
	}

	/**
	 * Stores the minimum accepted time value.
	 *
	 * @param string $time Time string written to min metadata after trimming.
	 * @return self.
	 */
	public function minTime(string $time): self {
		return $this->min(trim($time));
	}

	/**
	 * Stores the maximum accepted time value.
	 *
	 * @param string $time Time string written to max metadata after trimming.
	 * @return self.
	 */
	public function maxTime(string $time): self {
		return $this->max(trim($time));
	}

	/**
	 * Converts the field into a month input.
	 *
	 * Non-empty min and max values are trimmed and stored as browser-compatible
	 * bound metadata.
	 *
	 * @param ?string $min Earliest accepted month value.
	 * @param ?string $max Latest accepted month value.
	 * @return self.
	 */
	public function month(?string $min=null, ?string $max=null): self {
		$field=$this->type('month');
		if($min!==null && trim($min)!==''){
			$field=$field->min(trim($min));
		}
		if($max!==null && trim($max)!==''){
			$field=$field->max(trim($max));
		}
		return $field;
	}

	/**
	 * Converts the field into a week input.
	 *
	 * Non-empty min and max values are trimmed and stored as browser-compatible
	 * bound metadata.
	 *
	 * @param ?string $min Earliest accepted week value.
	 * @param ?string $max Latest accepted week value.
	 * @return self.
	 */
	public function week(?string $min=null, ?string $max=null): self {
		$field=$this->type('week');
		if($min!==null && trim($min)!==''){
			$field=$field->min(trim($min));
		}
		if($max!==null && trim($max)!==''){
			$field=$field->max(trim($max));
		}
		return $field;
	}

	/**
	 * Converts the field into a slider control.
	 *
	 * Min, max, step, and value-display metadata are stored directly for the Panel
	 * renderer.
	 *
	 * @param mixed $min Lower slider bound metadata.
	 * @param mixed $max Upper slider bound metadata.
	 * @param mixed $step Slider increment metadata.
	 * @param bool $showValue Whether the renderer should show the current value.
	 * @return self.
	 */
	public function slider(mixed $min=0, mixed $max=100, mixed $step=1, bool $showValue=true): self {
		return $this
			->type('slider')
			->min($min)
			->max($max)
			->step($step)
			->sliderValueDisplay($showValue);
	}

	/**
	 * Converts the field into a range input.
	 *
	 * @param mixed $min Lower range bound metadata.
	 * @param mixed $max Upper range bound metadata.
	 * @param mixed $step Range increment metadata.
	 * @return self.
	 */
	public function range(mixed $min=0, mixed $max=100, mixed $step=1): self {
		return $this
			->type('range')
			->min($min)
			->max($max)
			->step($step);
	}

	/**
	 * Alias for range().
	 *
	 * @param mixed $min Lower range bound metadata.
	 * @param mixed $max Upper range bound metadata.
	 * @param mixed $step Range increment metadata.
	 * @return self.
	 */
	public function rangeInput(mixed $min=0, mixed $max=100, mixed $step=1): self {
		return $this->range($min, $max, $step);
	}

	/**
	 * Alias for slider().
	 *
	 * @param mixed $min Lower slider bound metadata.
	 * @param mixed $max Upper slider bound metadata.
	 * @param mixed $step Slider increment metadata.
	 * @param bool $showValue Whether the renderer should show the current value.
	 * @return self.
	 */
	public function rangeSlider(mixed $min=0, mixed $max=100, mixed $step=1, bool $showValue=true): self {
		return $this->slider($min, $max, $step, $showValue);
	}

	/**
	 * Converts the field into a rating control.
	 *
	 * The minimum is never below zero, the maximum is never below the requested
	 * minimum, and the step is at least one.
	 *
	 * @param int $max Requested highest rating value.
	 * @param int $min Requested lowest rating value.
	 * @param int $step Requested rating increment.
	 * @return self.
	 */
	public function rating(int $max=5, int $min=1, int $step=1): self {
		return $this
			->type('rating')
			->min(max(0, $min))
			->max(max($min, $max))
			->step(max(1, $step));
	}

	/**
	 * Controls whether slider renderers show the current value.
	 *
	 * @param bool $show Whether to show the current value beside the slider.
	 * @return self.
	 */
	public function sliderValueDisplay(bool $show=true): self {
		return $this->meta(['value_display'=>$show]);
	}

	/**
	 * Alias for sliderValueDisplay().
	 *
	 * @param bool $show Whether to show the current value beside the slider.
	 * @return self.
	 */
	public function showSliderValue(bool $show=true): self {
		return $this->sliderValueDisplay($show);
	}

	/**
	 * Hides or restores the visible slider value.
	 *
	 * @param bool $hidden Whether the current value should be hidden.
	 * @return self.
	 */
	public function hideSliderValue(bool $hidden=true): self {
		return $this->sliderValueDisplay(!$hidden);
	}

	/**
	 * Converts the field into a tag-entry control.
	 *
	 * The separator is stored for submission parsing, and suggestions are normalized
	 * when provided.
	 *
	 * @param array<int|string, array<string, mixed>|string> $suggestions Suggestion option definitions.
	 * @param string $separator Character or token used to split submitted tags.
	 * @return self.
	 */
	public function tags(array $suggestions=[], string $separator=','): self {
		$field=$this->type('tags')->tagSeparator($separator)->autoResize(false);
		return $suggestions===[] ? $field : $field->suggestions($suggestions);
	}

	/**
	 * Converts the field into the alternate tags-input renderer.
	 *
	 * @param array<int|string, array<string, mixed>|string> $suggestions Suggestion option definitions.
	 * @param string $separator Character or token used to split submitted tags.
	 * @return self.
	 */
	public function tagsInput(array $suggestions=[], string $separator=','): self {
		return $this->tags($suggestions, $separator)->type('tags_input');
	}

	/**
	 * Sets the minimum number of submitted tags.
	 *
	 * Negative values are stored as zero.
	 *
	 * @param int $count Requested minimum tag count.
	 * @return self.
	 */
	public function minTags(int $count): self {
		return $this->meta(['min_tags'=>max(0, $count)]);
	}

	/**
	 * Sets the maximum number of submitted tags.
	 *
	 * Values below one are stored as one.
	 *
	 * @param int $count Requested maximum tag count.
	 * @return self.
	 */
	public function maxTags(int $count): self {
		return $this->meta(['max_tags'=>max(1, $count)]);
	}

	/**
	 * Marks the field as accepting one or many values.
	 *
	 * @param bool $multiple Whether the renderer may submit multiple values.
	 * @return self.
	 */
	public function multiple(bool $multiple=true): self {
		return $this->meta(['multiple'=>$multiple]);
	}

	/**
	 * Marks choice and relationship renderers as searchable.
	 *
	 * @param bool $searchable Whether the renderer should expose search UI.
	 * @return self.
	 */
	public function searchable(bool $searchable=true): self {
		return $this->meta(['searchable'=>$searchable]);
	}

	/**
	 * Sets placeholder text for searchable choice controls.
	 *
	 * @param string $placeholder Placeholder text shown inside the search input.
	 * @return self.
	 */
	public function searchPlaceholder(string $placeholder): self {
		return $this->meta(['search_placeholder'=>trim($placeholder)]);
	}

	/**
	 * Sets empty-state text for searchable choice controls.
	 *
	 * @param string $text Text shown when search returns no options.
	 * @return self.
	 */
	public function noResultsText(string $text): self {
		return $this->meta(['no_results_text'=>trim($text)]);
	}

	/**
	 * Controls whether the renderer may use native browser widgets.
	 *
	 * @param bool $native Whether native browser UI is allowed.
	 * @return self.
	 */
	public function native(bool $native=true): self {
		return $this->meta(['native'=>$native]);
	}

	/**
	 * Sets browser autocomplete metadata or converts the field to suggestions.
	 *
	 * Array values create an autocomplete field with normalized suggestions and
	 * browser autocomplete disabled.
	 *
	 * @param string|array $autocomplete Browser token or suggestion definitions.
	 * @return self.
	 */
	public function autocomplete(string|array $autocomplete): self {
		if(is_array($autocomplete)){
			return $this->type('autocomplete')->suggestions($autocomplete)->meta(['autocomplete'=>'off']);
		}
		return $this->meta(['autocomplete'=>trim($autocomplete)]);
	}

	/**
	 * Converts the field into a combobox with optional suggestions.
	 *
	 * @param array<int|string, array<string, mixed>|string> $suggestions Suggestion option definitions.
	 * @return self.
	 */
	public function comboBox(array $suggestions=[]): self {
		$field=$this->type('combobox')->meta(['autocomplete'=>'off']);
		return $suggestions===[] ? $field : $field->suggestions($suggestions);
	}

	/**
	 * Stores normalized suggestion values and labels.
	 *
	 * Integer-keyed suggestions use the label as the value; associative entries use
	 * the key as the value. Array entries may provide explicit `value` and `label`.
	 *
	 * @param array<int|string, array<string, mixed>|string> $suggestions Suggestion option definitions.
	 * @return self.
	 */
	public function suggestions(array $suggestions): self {
		$normalized=[];
		foreach($suggestions as $value=>$label){
			if(is_array($label)){
				$itemValue=trim((string)($label['value'] ?? $value));
				$itemLabel=trim((string)($label['label'] ?? $itemValue));
			}
			else {
				$itemValue=is_int($value) ? trim((string)$label) : trim((string)$value);
				$itemLabel=trim((string)$label);
			}
			if($itemValue!==''){
				$normalized[]=['value'=>$itemValue, 'label'=>$itemLabel];
			}
		}
		return $this->meta(['suggestions'=>$normalized]);
	}

	/**
	 * Alias for suggestions().
	 *
	 * @param array<int|string, array<string, mixed>|string> $suggestions Suggestion option definitions.
	 * @return self.
	 */
	public function datalist(array $suggestions): self {
		return $this->suggestions($suggestions);
	}

	/**
	 * Stores input-mask metadata for the renderer.
	 *
	 * The mask string is trimmed. The normalized-submit flag tells request handling
	 * whether to keep the visible mask or submit the unmasked value.
	 *
	 * @param string $mask Renderer mask pattern.
	 * @param bool $submitUnmasked Whether submitted values should remove mask literals.
	 * @return self.
	 */
	public function mask(string $mask, bool $submitUnmasked=false): self {
		return $this->meta([
			'mask'=>trim($mask),
			'mask_submit_normalized'=>$submitUnmasked,
		]);
	}

	/**
	 * Stores mask placeholder visibility or placeholder text.
	 *
	 *
	 * @param ?string $placeholder Placeholder.
	 * @return self.
	 */
	public function maskPlaceholder(?string $placeholder=null): self {
		return $this->meta(['mask_placeholder'=>$placeholder===null ? true : trim($placeholder)]);
	}

	/**
	 * Hides or restores the mask placeholder.
	 *
	 * @param bool $hidden Whether placeholder characters should be hidden.
	 * @return self.
	 */
	public function hideMaskPlaceholder(bool $hidden=true): self {
		return $this->meta(['mask_placeholder'=>$hidden ? false : true]);
	}

	/**
	 * Requests normalized values from masked inputs.
	 *
	 * @param bool $unmasked Whether submitted values should remove mask literals.
	 * @return self.
	 */
	public function submitUnmasked(bool $unmasked=true): self {
		return $this->meta(['mask_submit_normalized'=>$unmasked]);
	}

	/**
	 * Requests visible masked values from masked inputs.
	 *
	 * @param bool $masked Whether submitted values should retain mask literals.
	 * @return self.
	 */
	public function submitMasked(bool $masked=true): self {
		return $this->submitUnmasked(!$masked);
	}

	/**
	 * Sets an automatic formatting rule for the field.
	 *
	 * The rule name and trigger event are normalized before being stored in manifest
	 * metadata.
	 *
	 * @param string $rule Formatter rule name.
	 * @param array<string, mixed> $options Formatter options, including optional `on` event.
	 * @return self.
	 */
	public function format(string $rule, array $options=[]): self {
		$rule=self::normalizeName($rule);
		return $this->meta([
			'format_rule'=>$rule,
			'format_options'=>$options,
			'format_event'=>self::normalizeFormatEvent((string)($options['on'] ?? 'input')),
		]);
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param string $rule Formatter or validation rule name.
	 * @param array<string, mixed> $options Rule-specific options.
	 * @return self.
	 */
	public function formatRule(string $rule, array $options=[]): self {
		return $this->format($rule, $options);
	}

	/**
	 * Alias for format().
	 *
	 * @param string $rule Formatter rule name.
	 * @param array<string, mixed> $options Formatter options, including optional `on` event.
	 * @return self.
	 */
	public function autoFormat(string $rule, array $options=[]): self {
		return $this->format($rule, $options);
	}

	/**
	 * Stores formatter placeholder visibility or placeholder text.
	 *
	 *
	 * @param ?string $placeholder Placeholder.
	 * @return self.
	 */
	public function formatPlaceholder(?string $placeholder=null): self {
		return $this->meta(['format_placeholder'=>$placeholder===null ? true : trim($placeholder)]);
	}

	/**
	 * Hides or restores the format placeholder.
	 *
	 * @param bool $hidden Whether format placeholder text should be hidden.
	 * @return self.
	 */
	public function hideFormatPlaceholder(bool $hidden=true): self {
		return $this->meta(['format_placeholder'=>$hidden ? false : true]);
	}

	/**
	 * Sets the event that triggers automatic formatting.
	 *
	 * @param string $event Formatting trigger event name.
	 * @return self.
	 */
	public function formatOn(string $event): self {
		return $this->meta(['format_event'=>self::normalizeFormatEvent($event)]);
	}

	/**
	 * Adds a side button that runs a named formatting rule.
	 *
	 * Empty normalized rule names are ignored. Button metadata reuses the side-button
	 * pipeline so renderers receive a consistent position/label/rule payload.
	 *
	 * @param string $label Button label.
	 * @param string $rule Formatter rule triggered by the button.
	 * @param string $position Side-button position, usually append or prepend.
	 * @param array<string, mixed> $options Button metadata overrides.
	 * @return self.
	 */
	public function formatButton(string $label, string $rule, string $position='append', array $options=[]): self {
		$rule=self::normalizeName($rule);
		if($rule===''){
			return $this;
		}
		$options=array_replace(['icon'=>$rule], $options);
		return $this->sideButton($position, $label, $rule, $options);
	}

	/**
	 * Adds an uppercase formatting side button.
	 *
	 * @param string $label Button label.
	 * @param string $position Side-button position, usually append or prepend.
	 * @return self.
	 */
	public function uppercaseButton(string $label='Upper', string $position='append'): self {
		return $this->formatButton($label, 'uppercase', $position, ['icon'=>'upper']);
	}

	/**
	 * Adds a lowercase formatting side button.
	 *
	 * @param string $label Button label.
	 * @param string $position Side-button position, usually append or prepend.
	 * @return self.
	 */
	public function lowercaseButton(string $label='Lower', string $position='append'): self {
		return $this->formatButton($label, 'lowercase', $position, ['icon'=>'lower']);
	}

	/**
	 * Adds a title-case formatting side button.
	 *
	 * @param string $label Button label.
	 * @param string $position Side-button position, usually append or prepend.
	 * @return self.
	 */
	public function titleCaseButton(string $label='Title', string $position='append'): self {
		return $this->formatButton($label, 'title_case', $position, ['icon'=>'title']);
	}

	/**
	 * Adds a whitespace-trimming side button.
	 *
	 * @param string $label Button label.
	 * @param string $position Side-button position, usually append or prepend.
	 * @return self.
	 */
	public function trimButton(string $label='Trim', string $position='append'): self {
		return $this->formatButton($label, 'trim', $position, ['icon'=>'trim']);
	}

	/**
	 * Adds a fixed country code to formatter options.
	 *
	 * @param string $country Country code consumed by locale-aware formatters.
	 * @return self.
	 */
	public function formatCountry(string $country): self {
		$country=strtoupper(trim($country));
		return $this->meta(['format_options'=>array_replace(is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [], ['country'=>$country])]);
	}

	/**
	 * Adds a country source field to formatter options.
	 *
	 * Empty normalized field names leave the field unchanged.
	 *
	 * @param string $field Field name that contains the country code.
	 * @return self.
	 */
	public function formatCountryField(string $field='country'): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		return $this->meta(['format_options'=>array_replace(is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [], ['country_field'=>$field])]);
	}

	/**
	 * Adds a fixed subdivision code to formatter options.
	 *
	 * @param string $subdivision Subdivision code consumed by locale-aware formatters.
	 * @return self.
	 */
	public function formatSubdivision(string $subdivision): self {
		$subdivision=strtoupper(trim($subdivision));
		return $this->meta(['format_options'=>array_replace(is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [], ['subdivision'=>$subdivision])]);
	}

	/**
	 * Adds a subdivision source field to formatter options.
	 *
	 * Empty normalized field names leave the field unchanged.
	 *
	 * @param string $field Field name that contains the subdivision code.
	 * @return self.
	 */
	public function formatSubdivisionField(string $field='subdivision'): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		return $this->meta(['format_options'=>array_replace(is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [], ['subdivision_field'=>$field])]);
	}

	/**
	 * Sets country and subdivision source fields for locale-aware formatters.
	 *
	 * @param string $countryField Field name that contains the country code.
	 * @param ?string $subdivisionField Field name that contains the subdivision code.
	 * @return self.
	 */
	public function formatLocaleFields(string $countryField='country', ?string $subdivisionField=null): self {
		$field=$this->formatCountryField($countryField);
		return $subdivisionField===null ? $field : $field->formatSubdivisionField($subdivisionField);
	}

	/**
	 * Adds a source field reference to formatter options.
	 *
	 * Empty normalized field names leave the field unchanged.
	 *
	 * @param string $field Field name used as the formatter source.
	 * @return self.
	 */
	public function sourceField(string $field): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		return $this->meta(['format_options'=>array_replace(is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [], ['source_field'=>$field])]);
	}

	/**
	 * Alias for sourceField().
	 *
	 * @param string $field Field name used as the formatter source.
	 * @return self.
	 */
	public function fromField(string $field): self {
		return $this->sourceField($field);
	}

	/**
	 * Alias for formatOn() used by masked inputs.
	 *
	 * @param string $event Formatting trigger event name.
	 * @return self.
	 */
	public function maskOn(string $event): self {
		return $this->formatOn($event);
	}

	/**
	 * Converts the field into a money input with currency formatting.
	 *
	 * Currency is uppercased with `CAD` as the fallback. Decimal precision is
	 * clamped to zero through eight and reused for both the input step and formatter
	 * options.
	 *
	 * @param string $currency ISO currency code shown as the prepended label.
	 * @param int $decimals Decimal precision used for step and formatting metadata.
	 * @param string $event Event that triggers currency formatting.
	 * @return self.
	 */
	public function currency(string $currency='CAD', int $decimals=2, string $event='blur'): self {
		$decimals=max(0, min(8, $decimals));
		return $this
			->type('money')
			->inputMode('decimal')
			->step(self::decimalStep($decimals))
			->prependLabel(strtoupper(trim($currency)) ?: 'CAD')
			->format('currency', ['decimals'=>$decimals, 'currency'=>strtoupper(trim($currency)) ?: 'CAD', 'on'=>$event]);
	}

	/**
	 * Alias for currency().
	 *
	 * @param string $currency ISO currency code shown as the prepended label.
	 * @param int $decimals Decimal precision used for step and formatting metadata.
	 * @param string $event Event that triggers currency formatting.
	 * @return self.
	 */
	public function money(string $currency='CAD', int $decimals=2, string $event='blur'): self {
		return $this->currency($currency, $decimals, $event);
	}

	/**
	 * Converts the field into a percent input.
	 *
	 * Decimal precision is clamped to zero through eight and reused for both the
	 * input step and formatter options.
	 *
	 * @param int $decimals Decimal precision used for step and formatting metadata.
	 * @param string $event Event that triggers percent formatting.
	 * @return self.
	 */
	public function percent(int $decimals=1, string $event='blur'): self {
		$decimals=max(0, min(8, $decimals));
		return $this
			->type('percent')
			->inputMode('decimal')
			->step(self::decimalStep($decimals))
			->appendLabel('%')
			->format('percent', ['decimals'=>$decimals, 'on'=>$event]);
	}

	/**
	 * Alias for percent().
	 *
	 * @param int $decimals Decimal precision used for step and formatting metadata.
	 * @param string $event Event that triggers percent formatting.
	 * @return self.
	 */
	public function percentage(int $decimals=1, string $event='blur'): self {
		return $this->percent($decimals, $event);
	}

	/**
	 * Converts the field into an international phone input.
	 *
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phone(string $event='input'): self {
		return $this->type('tel')->inputMode('tel')->autocomplete('tel')->format('phone_international', ['on'=>$event]);
	}

	/**
	 * Alias for phone().
	 *
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phoneNumber(string $event='input'): self {
		return $this->phone($event);
	}

	/**
	 * Alias for phone().
	 *
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function internationalPhone(string $event='input'): self {
		return $this->phone($event);
	}

	/**
	 * Converts the field into a US phone input.
	 *
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phoneUs(string $event='input'): self {
		return $this->type('tel')->inputMode('tel')->autocomplete('tel')->format('phone_us', ['on'=>$event, 'country'=>'US']);
	}

	/**
	 * Converts the field into a Canadian phone input.
	 *
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phoneCa(string $event='input'): self {
		return $this->type('tel')->inputMode('tel')->autocomplete('tel')->format('phone_ca', ['on'=>$event, 'country'=>'CA']);
	}

	/**
	 * Converts the field into a phone input for a fixed country.
	 *
	 * @param string $country Country code passed to phone formatting options.
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phoneForCountry(string $country, string $event='input'): self {
		return $this->phone($event)->formatCountry($country);
	}

	/**
	 * Converts the field into a phone input using a sibling country field.
	 *
	 * @param string $field Field name that contains the country code.
	 * @param string $event Event that triggers phone formatting.
	 * @return self.
	 */
	public function phoneCountryField(string $field='country', string $event='input'): self {
		return $this->phone($event)->formatCountryField($field);
	}

	/**
	 * Converts the field into an email input with email formatting.
	 *
	 * @param string $event Event that triggers email formatting.
	 * @return self.
	 */
	public function email(string $event='blur'): self {
		return $this->type('email')->inputMode('email')->autocomplete('email')->format('email', ['on'=>$event]);
	}

	/**
	 * Alias for email().
	 *
	 * @param string $event Event that triggers email formatting.
	 * @return self.
	 */
	public function emailAddress(string $event='blur'): self {
		return $this->email($event);
	}

	/**
	 * Converts the field into a URL input with URL formatting.
	 *
	 * @param string $event Event that triggers URL formatting.
	 * @return self.
	 */
	public function url(string $event='blur'): self {
		return $this->type('url')->inputMode('url')->format('url', ['on'=>$event]);
	}

	/**
	 * Alias for url().
	 *
	 * @param string $event Event that triggers URL formatting.
	 * @return self.
	 */
	public function urlAddress(string $event='blur'): self {
		return $this->url($event);
	}

	/**
	 * Applies map URL formatting to a URL-mode input.
	 *
	 * @param string $event Event that triggers map URL formatting.
	 * @return self.
	 */
	public function mapUrl(string $event='blur'): self {
		return $this->inputMode('url')->format('map_url', ['on'=>$event]);
	}

	/**
	 * Alias for mapUrl().
	 *
	 * @param string $event Event that triggers map URL formatting.
	 * @return self.
	 */
	public function mapsUrl(string $event='blur'): self {
		return $this->mapUrl($event);
	}

	/**
	 * Applies domain formatting to a URL-mode input.
	 *
	 * @param string $event Event that triggers domain formatting.
	 * @return self.
	 */
	public function domain(string $event='blur'): self {
		return $this->inputMode('url')->autocomplete('url')->format('domain', ['on'=>$event]);
	}

	/**
	 * Alias for domain().
	 *
	 * @param string $event Event that triggers domain formatting.
	 * @return self.
	 */
	public function hostname(string $event='blur'): self {
		return $this->domain($event);
	}

	/**
	 * Applies timezone formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers timezone formatting.
	 * @return self.
	 */
	public function timezone(string $event='blur'): self {
		return $this->inputMode('text')->format('timezone', ['on'=>$event]);
	}

	/**
	 * Applies locale formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers locale formatting.
	 * @return self.
	 */
	public function locale(string $event='blur'): self {
		return $this->inputMode('text')->format('locale', ['on'=>$event]);
	}

	/**
	 * Converts the field into a JSON textarea.
	 *
	 * The helper enables auto-resize and stores JSON formatting options, including
	 * whether formatted output should be pretty-printed.
	 *
	 * @param string $event Event that triggers JSON formatting.
	 * @param bool $pretty Whether formatter output should use indentation.
	 * @return self.
	 */
	public function json(string $event='blur', bool $pretty=true): self {
		return $this
			->type('textarea')
			->rows(6)
			->autoResize()
			->format('json', ['on'=>$event, 'pretty'=>$pretty]);
	}

	/**
	 * Alias for json().
	 *
	 * @param string $event Event that triggers JSON formatting.
	 * @param bool $pretty Whether formatter output should use indentation.
	 * @return self.
	 */
	public function jsonText(string $event='blur', bool $pretty=true): self {
		return $this->json($event, $pretty);
	}

	/**
	 * Converts the field into a markdown editor.
	 *
	 * Preview metadata records whether markdown previews are enabled for renderers
	 * that support them.
	 *
	 * @param bool $preview Whether markdown preview UI should be enabled.
	 * @return self.
	 */
	public function markdown(bool $preview=true): self {
		return $this
			->type('markdown')
			->rows(8)
			->autoResize()
			->editor('markdown')
			->preview($preview, 'markdown');
	}

	/**
	 * Converts the field into an HTML editor.
	 *
	 * Preview metadata records whether HTML previews are enabled; it does not
	 * sanitize submitted markup.
	 *
	 * @param bool $preview Whether HTML preview UI should be enabled.
	 * @return self.
	 */
	public function htmlEditor(bool $preview=true): self {
		return $this
			->type('html')
			->rows(8)
			->autoResize()
			->editor('html')
			->preview($preview, 'html');
	}

	/**
	 * Converts the field into a rich text editor.
	 *
	 * @param bool $preview Whether rendered HTML preview UI should be enabled.
	 * @return self.
	 */
	public function richText(bool $preview=true): self {
		return $this
			->type('rich_text')
			->rows(8)
			->editor('rich_text')
			->preview($preview, 'html');
	}

	/**
	 * Converts the field into the rich-editor renderer variant.
	 *
	 * @param bool $preview Whether rendered HTML preview UI should be enabled.
	 * @return self.
	 */
	public function richEditor(bool $preview=true): self {
		return $this->richText($preview)->type('rich_editor')->editor('rich_editor');
	}

	/**
	 * Converts the field into a code editor.
	 *
	 * The language value is normalized through codeLanguage() before it reaches the
	 * manifest.
	 *
	 * @param string $language Syntax language requested by the editor.
	 * @param bool $preview Whether code preview UI should be enabled.
	 * @return self.
	 */
	public function codeEditor(string $language='plain', bool $preview=true): self {
		return $this
			->type('code')
			->rows(10)
			->autoResize()
			->editor('code')
			->preview($preview, 'code')
			->codeLanguage($language);
	}

	/**
	 * Alias for codeEditor().
	 *
	 * @param string $language Syntax language requested by the editor.
	 * @param bool $preview Whether code preview UI should be enabled.
	 * @return self.
	 */
	public function code(string $language='plain', bool $preview=true): self {
		return $this->codeEditor($language, $preview);
	}

	/**
	 * Stores a normalized code language identifier.
	 *
	 * Unsupported characters are replaced with underscores and empty results fall
	 * back to `plain`.
	 *
	 * @param string $language Syntax language requested by the editor.
	 * @return self.
	 */
	public function codeLanguage(string $language): self {
		$language=strtolower(trim($language));
		$language=preg_replace('/[^a-z0-9_+#.-]+/', '_', $language) ?? '';
		$language=trim($language, '_-.');
		return $this->meta(['code_language'=>$language !== '' ? $language : 'plain']);
	}

	/**
	 * Applies MIME type formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers MIME type formatting.
	 * @return self.
	 */
	public function mimeType(string $event='blur'): self {
		return $this->inputMode('text')->format('mime_type', ['on'=>$event]);
	}

	/**
	 * Alias for mimeType().
	 *
	 * @param string $event Event that triggers MIME type formatting.
	 * @return self.
	 */
	public function contentType(string $event='blur'): self {
		return $this->mimeType($event);
	}

	/**
	 * Applies semantic-version formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers semantic-version formatting.
	 * @return self.
	 */
	public function semver(string $event='blur'): self {
		return $this->inputMode('text')->format('semver', ['on'=>$event]);
	}

	/**
	 * Alias for semver().
	 *
	 * @param string $event Event that triggers semantic-version formatting.
	 * @return self.
	 */
	public function semanticVersion(string $event='blur'): self {
		return $this->semver($event);
	}

	/**
	 * Applies cron-expression formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers cron-expression formatting.
	 * @return self.
	 */
	public function cronExpression(string $event='blur'): self {
		return $this->inputMode('text')->format('cron_expression', ['on'=>$event]);
	}

	/**
	 * Alias for cronExpression().
	 *
	 * @param string $event Event that triggers cron-expression formatting.
	 * @return self.
	 */
	public function cron(string $event='blur'): self {
		return $this->cronExpression($event);
	}

	/**
	 * Applies language-code formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers language-code formatting.
	 * @return self.
	 */
	public function languageCode(string $event='blur'): self {
		return $this->inputMode('text')->format('language_code', ['on'=>$event]);
	}

	/**
	 * Alias for languageCode().
	 *
	 * @param string $event Event that triggers language-code formatting.
	 * @return self.
	 */
	public function isoLanguage(string $event='blur'): self {
		return $this->languageCode($event);
	}

	/**
	 * Applies country-code formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers country-code formatting.
	 * @return self.
	 */
	public function countryCode(string $event='blur'): self {
		return $this->inputMode('text')->format('country_code', ['on'=>$event]);
	}

	/**
	 * Alias for countryCode().
	 *
	 * @param string $event Event that triggers country-code formatting.
	 * @return self.
	 */
	public function isoCountry(string $event='blur'): self {
		return $this->countryCode($event);
	}

	/**
	 * Applies subdivision-code formatting to a text-mode input.
	 *
	 * @param string $event Event that triggers subdivision-code formatting.
	 * @return self.
	 */
	public function subdivisionCode(string $event='blur'): self {
		return $this->inputMode('text')->format('subdivision_code', ['on'=>$event]);
	}

	/**
	 * Alias for subdivisionCode().
	 *
	 * @param string $event Event that triggers subdivision-code formatting.
	 * @return self.
	 */
	public function regionCode(string $event='blur'): self {
		return $this->subdivisionCode($event);
	}

	/**
	 * Applies subdivision-code formatting for a fixed country.
	 *
	 * @param string $country Country code used by subdivision formatting.
	 * @param string $event Event that triggers subdivision-code formatting.
	 * @return self.
	 */
	public function subdivisionCodeForCountry(string $country, string $event='blur'): self {
		return $this->subdivisionCode($event)->formatCountry($country);
	}

	/**
	 * Applies subdivision-code formatting using a sibling country field.
	 *
	 * @param string $field Field name that contains the country code.
	 * @param string $event Event that triggers subdivision-code formatting.
	 * @return self.
	 */
	public function subdivisionCodeCountryField(string $field='country', string $event='blur'): self {
		return $this->subdivisionCode($event)->formatCountryField($field);
	}

	/**
	 * Applies currency-code formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function currencyCode(string $event='blur'): self {
		return $this->inputMode('text')->format('currency_code', ['on'=>$event]);
	}

	/**
	 * Alias for currencyCode().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function isoCurrency(string $event='blur'): self {
		return $this->currencyCode($event);
	}

	/**
	 * Applies IP address formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ipAddress(string $event='blur'): self {
		return $this->inputMode('text')->format('ip_address', ['on'=>$event]);
	}

	/**
	 * Alias for ipAddress().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ip(string $event='blur'): self {
		return $this->ipAddress($event);
	}

	/**
	 * Applies IPv4 formatting to the field.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ipv4(string $event='blur'): self {
		return $this->inputMode('decimal')->format('ipv4', ['on'=>$event]);
	}

	/**
	 * Applies IPv6 formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ipv6(string $event='blur'): self {
		return $this->inputMode('text')->format('ipv6', ['on'=>$event]);
	}

	/**
	 * Applies MAC address formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function macAddress(string $event='input'): self {
		return $this->inputMode('text')->format('mac_address', ['on'=>$event]);
	}

	/**
	 * Alias for macAddress().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function mac(string $event='input'): self {
		return $this->macAddress($event);
	}

	/**
	 * Applies UUID formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function uuid(string $event='blur'): self {
		return $this->inputMode('text')->format('uuid', ['on'=>$event]);
	}

	/**
	 * Applies ULID formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ulid(string $event='blur'): self {
		return $this->inputMode('text')->format('ulid', ['on'=>$event]);
	}

	/**
	 * Applies hex-color formatting to a text-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function hexColor(string $event='input'): self {
		return $this->inputMode('text')->format('hex_color', ['on'=>$event]);
	}

	/**
	 * Alias for hexColor().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function colorHex(string $event='input'): self {
		return $this->hexColor($event);
	}

	/**
	 * Converts the field into a color picker with a normalized default.
	 *
	 *
	 * @param string $default Default value stored on the field manifest.
	 * @return self.
	 */
	public function color(string $default='#000000'): self {
		$default=self::normalizeHexColorText($default);
		return $this
			->type('color')
			->default($default !== '' ? $default : '#000000')
			->colorSwatch();
	}

	/**
	 * Controls whether color renderers show a swatch.
	 *
	 *
	 * @param bool $enabled Whether the manifest flag should be enabled.
	 * @return self.
	 */
	public function colorSwatch(bool $enabled=true): self {
		return $this->meta(['color_swatch'=>$enabled]);
	}

	/**
	 * Hides or restores the color swatch.
	 *
	 *
	 * @param bool $hidden Whether the renderer feature should be hidden.
	 * @return self.
	 */
	public function hideColorSwatch(bool $hidden=true): self {
		return $this->colorSwatch(!$hidden);
	}

	/**
	 * Converts the field into a bounded latitude input.
	 *
	 *
	 * @param int $decimals Decimal precision stored for formatter metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function latitude(int $decimals=6, string $event='blur'): self {
		$decimals=max(0, min(10, $decimals));
		return $this
			->type('number')
			->inputMode('decimal')
			->min(-90)
			->max(90)
			->step(self::decimalStep($decimals))
			->format('latitude', ['on'=>$event, 'decimals'=>$decimals]);
	}

	/**
	 * Converts the field into a bounded longitude input.
	 *
	 *
	 * @param int $decimals Decimal precision stored for formatter metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function longitude(int $decimals=6, string $event='blur'): self {
		$decimals=max(0, min(10, $decimals));
		return $this
			->type('number')
			->inputMode('decimal')
			->min(-180)
			->max(180)
			->step(self::decimalStep($decimals))
			->format('longitude', ['on'=>$event, 'decimals'=>$decimals]);
	}

	/**
	 * Applies coordinate-pair formatting to a decimal-mode input.
	 *
	 *
	 * @param int $decimals Decimal precision stored for formatter metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function coordinates(int $decimals=6, string $event='blur'): self {
		$decimals=max(0, min(10, $decimals));
		return $this
			->inputMode('decimal')
			->format('coordinates', ['on'=>$event, 'decimals'=>$decimals]);
	}

	/**
	 * Alias for coordinates().
	 *
	 *
	 * @param int $decimals Decimal precision stored for formatter metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function latLng(int $decimals=6, string $event='blur'): self {
		return $this->coordinates($decimals, $event);
	}

	/**
	 * Applies longitude-latitude pair formatting to a decimal-mode input.
	 *
	 *
	 * @param int $decimals Decimal precision stored for formatter metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function lngLat(int $decimals=6, string $event='blur'): self {
		$decimals=max(0, min(10, $decimals));
		return $this
			->inputMode('decimal')
			->format('lng_lat', ['on'=>$event, 'decimals'=>$decimals]);
	}

	/**
	 * Applies country-aware postal-code formatting and autocomplete metadata.
	 *
	 *
	 * @param string $country Country code used by locale-aware formatting.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function postalCode(string $country='CA', string $event='input'): self {
		$country=strtoupper(trim($country));
		$rule=match($country){
			'CA'=>'postal_code_ca',
			'US', 'USA'=>'zip_code_us',
			'GB', 'UK'=>'postal_code_gb',
			'AU'=>'postal_code_au',
			'NZ'=>'postal_code_nz',
			'FR'=>'postal_code_fr',
			'DE'=>'postal_code_de',
			'NL'=>'postal_code_nl',
			'IE'=>'postal_code_ie',
			default=>'postal_code_international',
		};
		return $this->inputMode($rule==='zip_code_us' ? 'numeric' : 'text')->autocomplete('postal-code')->format($rule, ['on'=>$event, 'country'=>$country]);
	}

	/**
	 * Alias for postalCode() with an explicit country.
	 *
	 *
	 * @param string $country Country code used by locale-aware formatting.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function postalCodeForCountry(string $country, string $event='input'): self {
		return $this->postalCode($country, $event);
	}

	/**
	 * Applies postal-code formatting using a sibling country field.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function postalCodeCountryField(string $field='country', string $event='input'): self {
		return $this->inputMode('text')->autocomplete('postal-code')->format('postal_code', ['on'=>$event, 'country_field'=>self::normalizeName($field)]);
	}

	/**
	 * Applies postal-code formatting using a sibling subdivision field.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function postalCodeSubdivisionField(string $field='subdivision', string $event='input'): self {
		return $this->inputMode('text')->autocomplete('postal-code')->format('postal_code', ['on'=>$event, 'subdivision_field'=>self::normalizeName($field)]);
	}

	/**
	 * Applies postal-code formatting using sibling country and subdivision fields.
	 *
	 *
	 * @param string $countryField Field name that contains the country code.
	 * @param ?string $subdivisionField SubdivisionField.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function postalCodeLocaleFields(string $countryField='country', ?string $subdivisionField='subdivision', string $event='input'): self {
		$options=['on'=>$event, 'country_field'=>self::normalizeName($countryField)];
		if($subdivisionField!==null && trim($subdivisionField)!==''){
			$options['subdivision_field']=self::normalizeName($subdivisionField);
		}
		return $this->inputMode('text')->autocomplete('postal-code')->format('postal_code', $options);
	}

	/**
	 * Applies United States ZIP-code formatting.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function zipCode(string $event='input'): self {
		return $this->postalCode('US', $event);
	}

	/**
	 * Alias for postalCodeCountryField().
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function zipCodeCountryField(string $field='country', string $event='input'): self {
		return $this->postalCodeCountryField($field, $event);
	}

	/**
	 * Applies a normalized Social Security number mask.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ssn(string $event='input'): self {
		return $this->inputMode('numeric')->mask('999-99-9999', true)->formatOn($event);
	}

	/**
	 * Applies a normalized EIN mask.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function ein(string $event='input'): self {
		return $this->inputMode('numeric')->mask('99-9999999', true)->formatOn($event);
	}

	/**
	 * Applies one-time-code autocomplete, numeric mask, and character count metadata.
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function oneTimeCode(int $length=6, string $event='input'): self {
		$length=max(1, min(12, $length));
		return $this
			->inputMode('numeric')
			->autocomplete('one-time-code')
			->mask(str_repeat('9', $length), true)
			->formatOn($event)
			->characterCounter($length);
	}

	/**
	 * Alias for oneTimeCode().
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function verificationCode(int $length=6, string $event='input'): self {
		return $this->oneTimeCode($length, $event);
	}

	/**
	 * Alias for oneTimeCode().
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function otp(int $length=6, string $event='input'): self {
		return $this->oneTimeCode($length, $event);
	}

	/**
	 * Alias for oneTimeCode() with a shorter default length.
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function pinCode(int $length=4, string $event='input'): self {
		return $this->oneTimeCode($length, $event);
	}

	/**
	 * Applies credit-card formatting and a copy side button.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function creditCard(string $event='input'): self {
		return $this->inputMode('numeric')->format('credit_card', ['on'=>$event])->appendButton('Copy', 'copy', ['icon'=>'copy']);
	}

	/**
	 * Applies credit-card expiry formatting.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function creditCardExpiry(string $event='input'): self {
		return $this->inputMode('numeric')->format('credit_card_expiry', ['on'=>$event]);
	}

	/**
	 * Alias for creditCardExpiry().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function cardExpiry(string $event='input'): self {
		return $this->creditCardExpiry($event);
	}

	/**
	 * Applies card CVC formatting.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function cardCvc(string $event='input'): self {
		return $this->inputMode('numeric')->format('card_cvc', ['on'=>$event]);
	}

	/**
	 * Alias for cardCvc().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function cvc(string $event='input'): self {
		return $this->cardCvc($event);
	}

	/**
	 * Alias for cardCvc().
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function cvv(string $event='input'): self {
		return $this->cardCvc($event);
	}

	/**
	 * Applies IBAN formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function iban(string $event='input'): self {
		return $this->format('iban', ['on'=>$event]);
	}

	/**
	 * Applies slug formatting and a slug side button.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function slug(string $event='blur'): self {
		return $this->inputMode('text')->format('slug', ['on'=>$event])->appendButton('Slug', 'slug', ['icon'=>'slug']);
	}

	/**
	 * Applies slug formatting using another field as the source.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function slugFrom(string $field, string $event='input'): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this->slug($event);
		}
		return $this->slug($event)->formatOn($event)->sourceField($field);
	}

	/**
	 * Applies uppercase formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function uppercase(string $event='blur'): self {
		return $this->format('uppercase', ['on'=>$event]);
	}

	/**
	 * Applies lowercase formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function lowercase(string $event='blur'): self {
		return $this->format('lowercase', ['on'=>$event]);
	}

	/**
	 * Applies title-case formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function titleCase(string $event='blur'): self {
		return $this->format('title_case', ['on'=>$event]);
	}

	/**
	 * Applies sentence-case formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function sentenceCase(string $event='blur'): self {
		return $this->format('sentence_case', ['on'=>$event]);
	}

	/**
	 * Applies snake-case formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function snakeCase(string $event='blur'): self {
		return $this->format('snake_case', ['on'=>$event]);
	}

	/**
	 * Applies kebab-case formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function kebabCase(string $event='blur'): self {
		return $this->format('kebab_case', ['on'=>$event]);
	}

	/**
	 * Applies camel-case formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function camelCase(string $event='blur'): self {
		return $this->format('camel_case', ['on'=>$event]);
	}

	/**
	 * Applies trim formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function trimmed(string $event='blur'): self {
		return $this->format('trim', ['on'=>$event]);
	}

	/**
	 * Applies digits-only formatting to a numeric-mode input.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function digits(string $event='input'): self {
		return $this->inputMode('numeric')->format('digits', ['on'=>$event]);
	}

	/**
	 * Applies alphabetic-only formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function alpha(string $event='input'): self {
		return $this->format('alpha', ['on'=>$event]);
	}

	/**
	 * Applies alphanumeric formatting metadata.
	 *
	 *
	 * @param string $event Renderer event that triggers formatting or validation metadata.
	 * @return self.
	 */
	public function alphanumeric(string $event='input'): self {
		return $this->format('alphanumeric', ['on'=>$event]);
	}

	/**
	 * Adds a copy action button to the field.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param bool $normalized Whether submitted/copied values should use normalized text.
	 * @return self.
	 */
	public function copyButton(string $label='Copy', bool $normalized=false): self {
		return $this->appendButton($label, 'copy', ['icon'=>'copy', 'copy_normalized'=>$normalized]);
	}

	/**
	 * Adds a copy action button that uses normalized text.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @return self.
	 */
	public function copyNormalizedButton(string $label='Copy'): self {
		return $this->copyButton($label, true);
	}

	/**
	 * Adds a clear action button to the field.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @return self.
	 */
	public function clearButton(string $label='Clear'): self {
		return $this->appendButton($label, 'clear', ['icon'=>'x']);
	}

	/**
	 * Adds a password reveal action button.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @return self.
	 */
	public function revealButton(string $label='Show'): self {
		return $this->appendButton($label, 'toggle_password', ['icon'=>'eye']);
	}

	/**
	 * Adds a side button that fills today's date.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function todayButton(string $label='Today', string $position='append'): self {
		return $this->sideButton($position, $label, 'today', ['icon'=>'today']);
	}

	/**
	 * Adds a side button that fills the current date-time.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function nowButton(string $label='Now', string $position='append'): self {
		return $this->sideButton($position, $label, 'now', ['icon'=>'now']);
	}

	/**
	 * Adds a side button that writes a fixed scalar value.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @param array<string, mixed> $options Field configuration options for the operation.
	 * @return self.
	 */
	public function setButton(string $label, mixed $value, string $position='append', array $options=[]): self {
		if(is_scalar($value) || $value===null){
			$options['value']=(string)$value;
		}
		return $this->sideButton($position, $label, 'set', $options);
	}

	/**
	 * Adds a side button that increments the current value.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function incrementButton(string $label='+', string $position='append'): self {
		return $this->sideButton($position, $label, 'increment', ['icon'=>'plus']);
	}

	/**
	 * Adds a side button that decrements the current value.
	 *
	 *
	 * @param string $label Human-facing control label.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function decrementButton(string $label='-', string $position='prepend'): self {
		return $this->sideButton($position, $label, 'decrement', ['icon'=>'minus']);
	}

	/**
	 * Adds paired decrement and increment side buttons.
	 *
	 *
	 * @param string $decrementLabel Label shown on the decrement stepper button.
	 * @param string $incrementLabel Label shown on the increment stepper button.
	 * @return self.
	 */
	public function stepperButtons(string $decrementLabel='-', string $incrementLabel='+'): self {
		return $this->decrementButton($decrementLabel)->incrementButton($incrementLabel);
	}

	/**
	 * Controls password reveal metadata.
	 *
	 *
	 * @param bool $revealable Whether the password value may be revealed by the UI.
	 * @return self.
	 */
	public function revealable(bool $revealable=true): self {
		return $this->meta(['password_reveal'=>$revealable]);
	}

	/**
	 * Alias for revealable().
	 *
	 *
	 * @param bool $revealable Whether the password value may be revealed by the UI.
	 * @return self.
	 */
	public function passwordReveal(bool $revealable=true): self {
		return $this->revealable($revealable);
	}

	/**
	 * Requests normalized submitted values from formatted controls.
	 *
	 *
	 * @param bool $normalized Whether submitted/copied values should use normalized text.
	 * @return self.
	 */
	public function submitNormalized(bool $normalized=true): self {
		return $this->meta(['submit_normalized'=>$normalized]);
	}

	/**
	 * Requests visible formatted submitted values.
	 *
	 *
	 * @param bool $formatted Whether submitted values should retain formatted text.
	 * @return self.
	 */
	public function submitFormatted(bool $formatted=true): self {
		return $this->meta(['submit_normalized'=>!$formatted]);
	}

	/**
	 * Routes a side button declaration to the prepend or append button collection.
	 *
	 * The helper centralizes positional aliases so fluent APIs can expose semantic
	 * button builders while storing a consistent normalized action payload in field
	 * metadata. Unknown positions intentionally fall back to append placement.
	 *
	 * @param string $position Requested side, usually `prepend` or `append`.
	 * @param string $label Human-facing button label.
	 * @param string $action Normalized action name dispatched by the Panel field renderer.
	 * @param array<string, mixed> $options Optional tone, icon, URL, target, attributes, or value metadata.
	 * @return self Mutated field definition.
	 */
	private function sideButton(string $position, string $label, string $action, array $options=[]): self {
		return self::normalizeName($position)==='prepend'
			? $this->prependButton($label, $action, $options)
			: $this->appendButton($label, $action, $options);
	}

	/**
	 * Stores the browser inputmode token for the field.
	 *
	 *
	 * @param string $mode Browser inputmode token requested by the field.
	 * @return self.
	 */
	public function inputMode(string $mode): self {
		$mode=strtolower(trim($mode));
		$allowed=['none', 'text', 'tel', 'url', 'email', 'numeric', 'decimal', 'search'];
		return $this->meta(['input_mode'=>in_array($mode, $allowed, true) ? $mode : 'text']);
	}

	/**
	 * Stores minimum character-length validation metadata.
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @return self.
	 */
	public function minLength(int $length): self {
		return $this->meta(['min_length'=>max(0, $length)]);
	}

	/**
	 * Stores maximum character-length validation metadata.
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @return self.
	 */
	public function maxLength(int $length): self {
		return $this->meta(['max_length'=>max(1, $length)]);
	}

	/**
	 * Stores exact character-length validation metadata.
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @return self.
	 */
	public function length(int $length): self {
		$length=max(1, $length);
		return $this->meta([
			'min_length'=>$length,
			'max_length'=>$length,
			'exact_length'=>$length,
		]);
	}

	/**
	 * Alias for length().
	 *
	 *
	 * @param int $length Character length used for validation and renderer metadata.
	 * @return self.
	 */
	public function exactLength(int $length): self {
		return $this->length($length);
	}

	/**
	 * Stores inclusive character-length range metadata.
	 *
	 *
	 * @param int $min Lower bound used by validation or renderer metadata.
	 * @param int $max Upper bound used by validation or renderer metadata.
	 * @return self.
	 */
	public function lengthBetween(int $min, int $max): self {
		$min=max(0, $min);
		$max=max(1, $max);
		if($min>$max){
			[$min, $max]=[$max, $min];
		}
		return $this->meta([
			'min_length'=>$min,
			'max_length'=>$max,
		]);
	}

	/**
	 * Alias for lengthBetween().
	 *
	 *
	 * @param int $min Lower bound used by validation or renderer metadata.
	 * @param int $max Upper bound used by validation or renderer metadata.
	 * @return self.
	 */
	public function betweenLength(int $min, int $max): self {
		return $this->lengthBetween($min, $max);
	}

	/**
	 * Enables renderer character-count metadata.
	 *
	 *
	 * @param ?int $max Max.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function characterCounter(?int $max=null, string $position='append'): self {
		$meta=[
			'character_counter'=>true,
			'character_counter_position'=>self::normalizeName($position)==='prepend' ? 'prepend' : 'append',
		];
		if($max!==null && $max>0){
			$meta['character_counter_max']=$max;
			$meta['max_length']=$max;
		}
		return $this->meta($meta);
	}

	/**
	 * Alias for characterCounter().
	 *
	 *
	 * @param ?int $max Max.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function charCounter(?int $max=null, string $position='append'): self {
		return $this->characterCounter($max, $position);
	}

	/**
	 * Alias for characterCounter().
	 *
	 *
	 * @param ?int $max Max.
	 * @param string $position Button or counter position, usually append or prepend.
	 * @return self.
	 */
	public function counter(?int $max=null, string $position='append'): self {
		return $this->characterCounter($max, $position);
	}

	/**
	 * Stores browser pattern metadata.
	 *
	 *
	 * @param string $pattern Pattern string stored for browser or validation metadata.
	 * @return self.
	 */
	public function pattern(string $pattern): self {
		return $this->meta(['pattern'=>$pattern]);
	}

	/**
	 * Adds or removes nullable validation metadata.
	 *
	 *
	 * @param bool $nullable Whether null/empty values should be allowed.
	 * @return self.
	 */
	public function nullable(bool $nullable=true): self {
		$clone=$nullable ? $this->required(false)->rules('nullable') : clone $this;
		if(!$nullable){
			$clone->rules=self::removeRulesByName($clone->rules, ['nullable']);
		}
		return $clone;
	}

	/**
	 * Adds regex validation metadata when the pattern is non-empty.
	 *
	 *
	 * @param string $pattern Pattern string stored for browser or validation metadata.
	 * @return self.
	 */
	public function regex(string $pattern): self {
		$pattern=trim($pattern);
		return $pattern!=='' ? $this->rules('regex:'.$pattern) : $this;
	}

	/**
	 * Adds or removes confirmation validation metadata.
	 *
	 *
	 * @param bool $confirmed Whether the confirmation validation rule should be present.
	 * @return self.
	 */
	public function confirmed(bool $confirmed=true): self {
		$clone=clone $this;
		$clone->rules=self::removeRulesByName($clone->rules, ['confirmed']);
		return $confirmed ? $clone->rules('confirmed') : $clone;
	}

	/**
	 * Adds validation metadata requiring another field to match.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @return self.
	 */
	public function same(string $field): self {
		$field=self::normalizeName($field);
		return $field!=='' ? $this->rules('same:'.$field) : $this;
	}

	/**
	 * Adds validation metadata requiring another field to differ.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @return self.
	 */
	public function different(string $field): self {
		$field=self::normalizeName($field);
		return $field!=='' ? $this->rules('different:'.$field) : $this;
	}

	/**
	 * Adds starts-with validation metadata.
	 *
	 *
	 * @param array|string $values Values.
	 * @return self.
	 */
	public function startsWith(array|string $values): self {
		$values=self::normalizeRuleValues($values);
		return $values!==[] ? $this->rules('starts_with:'.implode(',', $values)) : $this;
	}

	/**
	 * Adds ends-with validation metadata.
	 *
	 *
	 * @param array|string $values Values.
	 * @return self.
	 */
	public function endsWith(array|string $values): self {
		$values=self::normalizeRuleValues($values);
		return $values!==[] ? $this->rules('ends_with:'.implode(',', $values)) : $this;
	}

	/**
	 * Stores editor preview metadata.
	 *
	 *
	 * @param bool $enabled Whether the manifest flag should be enabled.
	 * @param ?string $mode Mode.
	 * @return self.
	 */
	public function preview(bool $enabled=true, ?string $mode=null): self {
		$meta=['preview'=>$enabled];
		if($mode!==null && trim($mode)!==''){
			$meta['preview_mode']=self::normalizeName($mode);
		}
		return $this->meta($meta);
	}

	/**
	 * Stores the editor mode used by the renderer.
	 *
	 *
	 * @param string $editor Editor mode stored for the renderer.
	 * @param array<string, mixed> $options Field configuration options for the operation.
	 * @return self.
	 */
	public function editor(string $editor, array $options=[]): self {
		$editor=self::normalizeName($editor);
		return $this->meta([
			'editor'=>$editor !== '' ? $editor : self::normalizeName($this->type),
			'editor_options'=>$options,
		]);
	}

	/**
	 * Controls whether the renderer may clear the value.
	 *
	 *
	 * @param bool $clearable Whether the renderer may clear the current value.
	 * @return self.
	 */
	public function clearable(bool $clearable=true): self {
		return $this->meta(['clearable'=>$clearable]);
	}

	/**
	 * Stores the tag separator used for parsing submitted values.
	 *
	 *
	 * @param string $separator Separator used when parsing or joining repeated values.
	 * @return self.
	 */
	public function tagSeparator(string $separator): self {
		return $this->meta(['tag_separator'=>$separator]);
	}

	/**
	 * Stores separators used for key/value text parsing.
	 *
	 *
	 * @param string $pairSeparator Separator between serialized key/value pairs.
	 * @param string $keySeparator Separator between serialized keys and values.
	 * @return self.
	 */
	public function keyValueSeparators(string $pairSeparator="\n", string $keySeparator='='): self {
		return $this->meta([
			'pair_separator'=>$pairSeparator,
			'key_separator'=>$keySeparator,
		]);
	}

	/**
	 * Converts the field into a key/value editor.
	 *
	 *
	 * @param string $keySeparator Separator between serialized keys and values.
	 * @param string $pairSeparator Separator between serialized key/value pairs.
	 * @return self.
	 */
	public function keyValue(string $keySeparator='=', string $pairSeparator="\n"): self {
		return $this
			->type('key_value')
			->rows(6)
			->autoResize()
			->keyValueSeparators($pairSeparator, $keySeparator);
	}

	/**
	 * Alias for keyValue().
	 *
	 *
	 * @param string $keySeparator Separator between serialized keys and values.
	 * @param string $pairSeparator Separator between serialized key/value pairs.
	 * @return self.
	 */
	public function keyValuePairs(string $keySeparator='=', string $pairSeparator="\n"): self {
		return $this->keyValue($keySeparator, $pairSeparator);
	}

	/**
	 * Stores the minimum key/value pair count.
	 *
	 *
	 * @param int $count Count stored for renderer or validation metadata.
	 * @return self.
	 */
	public function minPairs(int $count): self {
		return $this->meta(['min_pairs'=>max(0, $count)]);
	}

	/**
	 * Stores the maximum key/value pair count.
	 *
	 *
	 * @param int $count Count stored for renderer or validation metadata.
	 * @return self.
	 */
	public function maxPairs(int $count): self {
		return $this->meta(['max_pairs'=>max(1, $count)]);
	}

	/**
	 * Converts the field into an image upload control.
	 *
	 *
	 * @param bool $image Whether the renderer should treat the value as an image.
	 * @return self.
	 */
	public function image(bool $image=true): self {
		return $image ? $this->type('image')->acceptedTypes(['image/*']) : $this;
	}

	/**
	 * Adds validation rules to the field.
	 *
	 *
	 * @param array|string $rules Rules.
	 * @return self.
	 */
	public function rules(array|string $rules): self {
		$clone=clone $this;
		$rules=is_array($rules) ? $rules : [$rules];
		$rules=array_map(
			static fn(mixed $rule): string => trim((string)$rule),
			$rules
		);
		$clone->rules=array_values(array_unique(array_filter(array_merge($clone->rules, $rules))));
		return $clone;
	}

	/**
	 * Merges arbitrary renderer metadata into the field manifest.
	 *
	 *
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return self.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function hydrateUsing(callable $callback): self {
		$clone=clone $this;
		$clone->hydrateCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function dehydrateUsing(callable $callback): self {
		$clone=clone $this;
		$clone->dehydrateCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function validateUsing(callable $callback): self {
		$clone=clone $this;
		$clone->validateCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Registers a display-value resolver callback.
	 *
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function displayUsing(callable $callback): self {
		$clone=clone $this;
		$clone->displayCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @return self.
	 */
	public function visibleUsing(callable $callback): self {
		$clone=clone $this;
		$clone->visibilityCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @param array|string ... $dependencies Dependencies.
	 * @return self.
	 */
	public function stateUsing(callable $callback, array|string ...$dependencies): self {
		$clone=clone $this;
		$clone->stateCallback=\Closure::fromCallable($callback);
		$clone=$clone->reactive(true);
		if($dependencies!==[]){
			$clone=$clone->dependsOn(...$dependencies);
		}
		return $clone;
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param callable $callback Callback invoked by Panel during rendering, validation, or persistence.
	 * @param array|string ... $dependencies Dependencies.
	 * @return self.
	 */
	public function updateStateUsing(callable $callback, array|string ...$dependencies): self {
		return $this->stateUsing($callback, ...$dependencies);
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self.
	 */
	public function visibleOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->visibleOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Alias for visibleOn().
	 *
	 *
	 * @param array|string ... $operations Operations.
	 * @return self.
	 */
	public function onlyOn(array|string ...$operations): self {
		return $this->visibleOn(...$operations);
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param array|string ... $operations Operations.
	 * @return self.
	 */
	public function hiddenOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->hiddenOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Alias for hiddenOn().
	 *
	 *
	 * @param array|string ... $operations Operations.
	 * @return self.
	 */
	public function exceptOn(array|string ...$operations): self {
		return $this->hiddenOn(...$operations);
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param array|string ... $fields Fields.
	 * @return self.
	 */
	public function dependsOn(array|string ...$fields): self {
		$clone=clone $this;
		$clone->dependsOn=array_values(array_unique(array_filter(array_merge(
			$clone->dependsOn,
			self::normalizeFieldList($fields)
		))));
		return $clone;
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param array|string ... $fields Fields.
	 * @return self.
	 */
	public function optionsDependOn(array|string ...$fields): self {
		return $this->dependsOn(...$fields)->reactive();
	}

	/**
	 * Enables live-update metadata with a bounded debounce.
	 *
	 *
	 * @param bool $live Whether the field should send live updates.
	 * @param int $debounceMs Debounce delay in milliseconds for live updates.
	 * @return self.
	 */
	public function live(bool $live=true, int $debounceMs=250): self {
		return $this->meta([
			'live'=>$live,
			'debounce_ms'=>max(0, min(5000, $debounceMs)),
		]);
	}

	/**
	 * Enables reactive/live metadata together.
	 *
	 *
	 * @param bool $reactive Whether dependent field state should react to changes.
	 * @return self.
	 */
	public function reactive(bool $reactive=true): self {
		return $this->meta([
			'reactive'=>$reactive,
			'live'=>$reactive,
		]);
	}

	/**
	 * Stores a bounded debounce interval.
	 *
	 *
	 * @param int $milliseconds Delay or debounce duration in milliseconds.
	 * @return self.
	 */
	public function debounce(int $milliseconds): self {
		return $this->meta(['debounce_ms'=>max(0, min(5000, $milliseconds))]);
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function visibleWhen(string $field, mixed $value=true): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		$clone=$this->dependsOn($field);
		$clone->visibleWhen[$field]=$value;
		return $clone;
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function hiddenWhen(string $field, mixed $value=true): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		$clone=$this->dependsOn($field);
		$clone->hiddenWhen[$field]=$value;
		return $clone;
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function requiredWhen(string $field, mixed $value=true): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		$clone=$this->dependsOn($field);
		$clone->requiredWhen[$field]=$value;
		return $clone;
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function requiredIf(string $field, mixed $value=true): self {
		return $this->requiredWhen($field, $value);
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return self.
	 */
	public function requiredUnless(string $field, mixed $value=true): self {
		$field=self::normalizeName($field);
		if($field===''){
			return $this;
		}
		$clone=$this->dependsOn($field);
		$clone->requiredUnless[$field]=$value;
		return $clone;
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @return mixed.
	 */
	public function hydrateValue(mixed $value, mixed $record=null, ?PanelRequest $request=null): mixed {
		if($this->hydrateCallback===null){
			return $value;
		}
		return PanelUtilityResolver::evaluate($this->hydrateCallback, [
			'value'=>$value,
			'record'=>$record,
			'request'=>$request,
			'field'=>$this,
		], ['value', 'record', 'request', 'field']);
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $values Field values keyed by normalized field name.
	 * @return mixed.
	 */
	public function dehydrateValue(mixed $value, mixed $record=null, ?PanelRequest $request=null, array $values=[]): mixed {
		$value=$this->normalizeFormattedValue($value, $record, $request, $values);
		if($this->dehydrateCallback===null){
			return $this->castValue($value, $record, $request);
		}
		return PanelUtilityResolver::evaluate($this->dehydrateCallback, [
			'value'=>$value,
			'record'=>$record,
			'request'=>$request,
			'field'=>$this,
		], ['value', 'record', 'request', 'field']);
	}

	/**
	 * Configures validation behavior for this field.
	 *
	 * Validation metadata is stored on the field manifest so Panel forms can evaluate submitted values, conditional requirements, and custom validators consistently.
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param array<string, mixed> $values Field values keyed by normalized field name.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @param ?string $operation Operation.
	 * @return array.
	 */
	public function validateValue(mixed $value, array $values=[], mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): array {
		$errors=$this->validateRules($value, $values, $record, $request, $operation ?? $request?->operation() ?? 'form');
		if($this->validateCallback!==null){
			$result=PanelUtilityResolver::evaluate($this->validateCallback, [
				'value'=>$value,
				'values'=>$values,
				'data'=>$values,
				'record'=>$record,
				'request'=>$request,
				'field'=>$this,
				'operation'=>$operation ?? $request?->operation() ?? 'form',
				'mode'=>$operation ?? $request?->operation() ?? 'form',
			], ['value', 'values', 'record', 'request', 'field']);
			if(is_string($result) && trim($result)!==''){
				$errors[]=trim($result);
			}
			elseif(is_array($result)){
				foreach($result as $message){
					$message=trim((string)$message);
					if($message!==''){
						$errors[]=$message;
					}
				}
			}
			elseif($result===false){
				$errors[]=$this->label.' is invalid.';
			}
		}
		return array_values(array_unique($errors));
	}

	/**
	 * Resolves the renderer-facing display value.
	 *
	 *
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @return mixed.
	 */
	public function displayValue(mixed $value, mixed $record=null, ?PanelRequest $request=null): mixed {
		if($this->displayCallback!==null){
			return PanelUtilityResolver::evaluate($this->displayCallback, [
				'value'=>$value,
				'record'=>$record,
				'request'=>$request,
				'field'=>$this,
			], ['value', 'record', 'request', 'field']);
		}
		return $value;
	}

	/**
	 * Configures selectable options for this field.
	 *
	 * Option metadata supports scalar values, grouped options, disabled states, descriptions, relationships, and display columns.
	 *
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @param string $operation Panel operation name such as create, edit, or view.
	 * @return array.
	 */
	public function optionsFor(mixed $record=null, ?PanelRequest $request=null, string $operation='form'): array {
		if($this->optionsCallback===null){
			return $this->options;
		}
		$options=PanelUtilityResolver::evaluate($this->optionsCallback, [
			'record'=>$record,
			'request'=>$request,
			'operation'=>$operation,
			'mode'=>$operation,
			'field'=>$this,
		], ['record', 'request', 'operation', 'field']);
		return is_array($options) ? $options : [];
	}

	/**
	 * Configures field state transformation.
	 *
	 * State callbacks and defaults control how record values enter the form, how submitted values leave it, and how reactive field state is recalculated.
	 *
	 * @param array<string, mixed> $values Field values keyed by normalized field name.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @param string $operation Panel operation name such as create, edit, or view.
	 * @return array.
	 */
	public function stateFor(array $values=[], mixed $value=null, mixed $record=null, ?PanelRequest $request=null, string $operation='form'): array {
		if($this->stateCallback===null){
			return [];
		}
		$result=PanelUtilityResolver::evaluate($this->stateCallback, [
			'value'=>$value,
			'values'=>$values,
			'data'=>$values,
			'record'=>$record,
			'request'=>$request,
			'field'=>$this,
			'operation'=>$operation,
			'mode'=>$operation,
		], ['value', 'values', 'record', 'request', 'field', 'operation']);
		if(!is_array($result)){
			return ['value'=>$result];
		}
		$allowed=[
			'value'=>true,
			'help'=>true,
			'placeholder'=>true,
			'options'=>true,
			'visible'=>true,
			'required'=>true,
			'readonly'=>true,
			'errors'=>true,
			'meta'=>true,
			'force_value'=>true,
			'propagate'=>true,
			'set'=>true,
			'fields'=>true,
		];
		return array_intersect_key($result, $allowed);
	}

	/**
	 * Configures conditional visibility for this field.
	 *
	 * Visibility rules are preserved as manifest metadata so server rendering and reactive client updates can agree on when the field appears.
	 *
	 * @param string $operation Panel operation name such as create, edit, or view.
	 * @param mixed $record Panel record or row payload used for resolver evaluation.
	 * @param ?PanelRequest $request Request.
	 * @return bool.
	 */
	public function isVisible(string $operation='form', mixed $record=null, ?PanelRequest $request=null): bool {
		$operation=self::normalizeOperation($operation);
		if($this->visibleOn!==[] && !in_array($operation, $this->visibleOn, true)){
			return false;
		}
		if(in_array($operation, $this->hiddenOn, true)){
			return false;
		}
		if(!$this->dependencyVisible($record, $request)){
			return false;
		}
		if($this->visibilityCallback!==null){
			return (bool)PanelUtilityResolver::evaluate($this->visibilityCallback, [
				'operation'=>$operation,
				'mode'=>$operation,
				'record'=>$record,
				'request'=>$request,
				'field'=>$this,
			], ['operation', 'record', 'request', 'field']);
		}
		return true;
	}

	/**
	 * Configures upload handling for this field.
	 *
	 * Upload metadata describes accepted files, size limits, chunking, delete endpoints, concurrency, and validation expectations for Panel upload controls.
	 * @return bool.
	 */
	public function isFileUpload(): bool {
		return self::isFileUploadType($this->type);
	}

	/**
	 * Exports this field as a Panel manifest payload.
	 *
	 * The payload is the renderer-facing contract containing field identity, labels, rules, options, callbacks, conditions, and UI metadata.
	 * @return array.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'default'=>$this->default,
			'required'=>$this->required,
			'readonly'=>$this->readonly,
			'hidden'=>$this->hidden,
			'placeholder'=>$this->placeholder,
			'help'=>$this->help,
			'options'=>$this->options,
			'repeater_fields'=>$this->meta['repeater_fields'] ?? [],
			'accepted_types'=>$this->meta['accepted_types'] ?? [],
			'max_file_size'=>$this->meta['max_file_size'] ?? null,
			'media_collection'=>$this->meta['media_collection'] ?? null,
			'media_variants'=>$this->meta['media_variants'] ?? ($this->meta['media_collection_manifest']['variants'] ?? []),
			'multiple'=>$this->meta['multiple'] ?? false,
			'dynamic_options'=>$this->optionsCallback!==null,
			'live'=>(bool)($this->meta['live'] ?? false),
			'reactive'=>(bool)($this->meta['reactive'] ?? false),
			'debounce_ms'=>(int)($this->meta['debounce_ms'] ?? 250),
			'rules'=>$this->rules,
			'meta'=>$this->meta,
			'hydrates'=>$this->hydrateCallback!==null,
			'dehydrates'=>$this->dehydrateCallback!==null,
			'validates'=>$this->validateCallback!==null,
			'displays'=>$this->displayCallback!==null,
			'state_updates'=>$this->stateCallback!==null,
			'visible_on'=>$this->visibleOn,
			'hidden_on'=>$this->hiddenOn,
			'depends_on'=>$this->dependsOn,
			'visible_when'=>$this->visibleWhen,
			'hidden_when'=>$this->hiddenWhen,
			'required_when'=>$this->requiredWhen,
			'required_unless'=>$this->requiredUnless,
			'conditional'=>$this->visibilityCallback!==null || $this->visibleWhen!==[] || $this->hiddenWhen!==[] || $this->requiredWhen!==[] || $this->requiredUnless!==[],
			'component'=>$this->componentManifest(),
		];
	}

	/**
	 * Checks whether any prepend or append field button exposes one of the actions.
	 *
	 * Button actions are compared case-insensitively after trimming so renderer
	 * capability detection is stable even when schema authors use display-oriented
	 * labels or mixed casing in form declarations.
	 *
	 * @param array<int, string> $actions Action names that should be considered present.
	 * @return bool Whether a matching field button exists in the serialized metadata.
	 */
	private function fieldButtonsHaveAction(array $actions): bool {
		$actions=array_map(static fn(string $action): string => strtolower(trim($action)), $actions);
		foreach(array_merge($this->meta['prepend_buttons'] ?? [], $this->meta['append_buttons'] ?? []) as $button){
			if(is_array($button) && in_array(strtolower((string)($button['action'] ?? '')), $actions, true)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the renderer-facing capability manifest for this field definition.
	 *
	 * The manifest is a compact contract consumed by Panel rendering and classifies the control category, editor subtype, option behavior, upload
	 * behavior, formatting semantics, and lifecycle capabilities without exposing
	 * the mutable builder internals. It has no persistence side effects.
	 *
	 * @return array Structured component metadata for client rendering and documentation.
	 */
	private function componentManifest(): array {
		$type=$this->type;
		$numericTypes=['number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage', 'range', 'slider', 'rating', 'latitude', 'longitude'];
		$semanticTextTypes=['otp', 'one_time_code', 'verification_code', 'pin', 'pin_code', 'credit_card', 'card_number', 'credit_card_expiry', 'card_expiry', 'card_cvc', 'cvc', 'cvv', 'iban', 'slug', 'uuid', 'ulid', 'domain', 'hostname', 'timezone', 'time_zone', 'locale', 'language_tag', 'mime_type', 'content_type', 'semver', 'semantic_version', 'cron_expression', 'cron', 'language_code', 'iso_language', 'country_code', 'iso_country', 'subdivision_code', 'region_code', 'currency_code', 'iso_currency', 'ip_address', 'ip', 'ipv4', 'ipv6', 'mac_address', 'mac', 'hex_color', 'color_hex', 'coordinates', 'lat_lng', 'lng_lat', 'phone_international', 'postal_code', 'postal', 'postal_code_ca', 'canadian_postal_code', 'zip_code_us', 'postal_code_us', 'zip'];
		$component=PanelComponentRegistry::fieldTypeDefinition($type);
		$editor=(string)($this->meta['editor'] ?? (in_array($type, ['markdown', 'html', 'code', 'rich_editor', 'rich_text'], true) ? $type : ''));
		$category=match(true){
			self::isFileUploadType($type)=>'upload',
			in_array($type, ['repeater', 'builder', 'fieldset', 'group', 'field_group', 'address'], true)=>'structure',
			in_array($type, ['placeholder', 'display', 'display_only', 'view_field'], true)=>'display',
			in_array($type, ['select', 'enum', 'multi_select', 'multiselect', 'radio', 'checkbox_list', 'toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true)=>'choice',
			in_array($type, ['markdown', 'html', 'code', 'rich_editor', 'rich_text', 'textarea', 'json'], true)=>'editor',
			in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true)=>'boolean',
			in_array($type, ['date', 'datetime', 'datetime_local', 'month', 'week', 'time', 'date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true)=>'date_time',
			$type==='password'=>'password',
			$type==='hidden'=>'hidden',
			in_array($type, $numericTypes, true)=>'input',
			default=>'input',
		};
		$capabilities=array_values(array_filter([
			$this->required ? 'required' : null,
			$this->readonly ? 'readonly' : null,
			($this->meta['disabled'] ?? false)===true ? 'disabled' : null,
			array_key_exists('dehydrated', $this->meta) ? (($this->meta['dehydrated'] ?? true)===true ? 'dehydrated' : 'not_dehydrated') : null,
			in_array($type, ['placeholder', 'display', 'display_only', 'view_field'], true) ? 'display_only' : null,
			in_array($type, ['placeholder', 'display', 'display_only', 'view_field'], true) && ($this->meta['html'] ?? false)===true ? 'safe_html' : null,
			in_array($type, ['placeholder', 'display', 'display_only', 'view_field'], true) && array_key_exists('display_content', $this->meta) ? 'static_content' : null,
			$this->rules!==[] ? 'validation_rules' : null,
			in_array('nullable', array_map(static fn(string $rule): string => strtolower(trim(explode(':', $rule, 2)[0])), $this->rules), true) ? 'nullable' : null,
			$this->options!==[] || $this->optionsCallback!==null ? 'options' : null,
			self::optionsContainGroups($this->options) ? 'option_groups' : null,
			self::optionsContainDisabled($this->options) ? 'disabled_options' : null,
			self::optionsContainDescriptions($this->options) ? 'option_descriptions' : null,
			$this->optionsCallback!==null ? 'dynamic_options' : null,
			in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true) ? 'relationship' : null,
			in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true) && ($this->meta['related_resource'] ?? '')!=='' ? 'related_resource' : null,
			in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true) && ($this->meta['title_attribute'] ?? '')!=='' ? 'title_attribute' : null,
			in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true) && ($this->meta['key_attribute'] ?? '')!=='' ? 'key_attribute' : null,
			($this->meta['searchable'] ?? false)===true ? 'searchable' : null,
			($this->meta['native'] ?? true)===false ? 'custom_control' : null,
			($this->meta['multiple'] ?? false)===true ? 'multiple' : null,
			($this->meta['media_collection'] ?? '')!=='' ? 'media_collection' : null,
			self::isFileUploadType($type) ? 'file_upload' : null,
			self::isFileUploadType($type) && ($this->meta['accepted_types'] ?? [])!==[] ? 'accepted_types' : null,
			self::isFileUploadType($type) && (int)($this->meta['max_file_size'] ?? 0)>0 ? 'max_file_size' : null,
			$type==='image' ? 'image_only' : null,
			($this->meta['custom_uploader'] ?? false)===true ? 'custom_uploader' : null,
			($this->meta['chunked_upload'] ?? false)===true ? 'chunked_upload' : null,
			($this->meta['upload_driver'] ?? '')==='dataphyre_storage' ? 'dataphyre_storage' : null,
			($this->meta['preview'] ?? false)===true ? 'preview' : null,
			$this->help!==null ? 'helper_text' : null,
			($this->meta['hint'] ?? '')!=='' ? 'hint' : null,
			($this->meta['hint_icon'] ?? '')!=='' ? 'hint_icon' : null,
			is_array($this->meta['accessibility'] ?? null) && ($this->meta['accessibility'] ?? [])!==[] ? 'accessibility_policy' : null,
			(int)($this->meta['accessibility']['min_usable_width'] ?? 0)>0 || (int)($this->meta['accessibility']['min_usable_chars'] ?? 0)>0 ? 'usable_width_policy' : null,
			(int)($this->meta['accessibility']['min_touch_target'] ?? 0)>0 ? 'touch_target_policy' : null,
			(float)($this->meta['accessibility']['max_adornment_ratio'] ?? 0)>0 ? 'adornment_pressure_policy' : null,
			(float)($this->meta['accessibility']['max_label_ratio'] ?? 0)>0 ? 'label_pressure_policy' : null,
			is_array($this->meta['accessibility']['contrast_policy'] ?? null) ? 'contrast_policy' : null,
			($this->meta['accessibility_inherit'] ?? true)===false ? 'accessibility_policy_opt_out' : null,
			($this->meta['prepend_icons'] ?? [])!==[] || ($this->meta['append_icons'] ?? [])!==[] ? 'adornment_icons' : null,
			in_array($type, ['repeater', 'builder', 'fieldset', 'group', 'field_group', 'address'], true) ? 'nested_fields' : null,
			$type==='builder' ? 'builder_blocks' : null,
			in_array($type, ['fieldset', 'group', 'field_group', 'address'], true) ? 'field_group' : null,
			$type==='address' ? 'address' : null,
			$type==='address' ? 'country_aware_validation' : null,
			$type==='repeater' && array_key_exists('min_items', $this->meta) ? 'min_items' : null,
			$type==='repeater' && array_key_exists('max_items', $this->meta) ? 'max_items' : null,
			$type==='repeater' && ($this->meta['add_item_label'] ?? '')!=='' ? 'custom_add_label' : null,
			($this->meta['suggestions'] ?? [])!==[] ? 'suggestions' : null,
			in_array($type, ['autocomplete', 'combobox', 'combo_box'], true) ? 'datalist' : null,
			in_array($type, ['autocomplete', 'combobox', 'combo_box'], true) ? 'free_text' : null,
			($this->meta['mask'] ?? '')!=='' ? 'mask' : null,
			($this->meta['format_rule'] ?? '')!=='' ? 'format' : null,
			(
				(($this->meta['format_rule'] ?? '')!=='' && ($this->meta['submit_normalized'] ?? true)===true)
				|| (($this->meta['mask'] ?? '')!=='' && ($this->meta['mask_submit_normalized'] ?? false)===true)
			) ? 'normalizes_submit' : null,
			($this->meta['prepend_label'] ?? '')!=='' || ($this->meta['append_label'] ?? '')!=='' || ($this->meta['prepend_icons'] ?? [])!==[] || ($this->meta['append_icons'] ?? [])!==[] || ($this->meta['prepend_buttons'] ?? [])!==[] || ($this->meta['append_buttons'] ?? [])!==[] ? 'adornments' : null,
			($this->meta['clearable'] ?? false)===true ? 'clearable' : null,
			in_array($type, $numericTypes, true) ? 'numeric' : null,
			$type==='slider' ? 'value_display' : null,
			in_array($type, $numericTypes, true) && (array_key_exists('min', $this->meta) || array_key_exists('max', $this->meta)) ? 'bounded' : null,
			in_array($type, $numericTypes, true) && array_key_exists('step', $this->meta) ? 'stepped' : null,
			in_array($type, $numericTypes, true) && $this->fieldButtonsHaveAction(['increment', 'decrement']) ? 'steppers' : null,
			$type==='decimal' && array_key_exists('decimal_scale', $this->meta) ? 'decimal_scale' : null,
			$type==='password' ? 'secret' : null,
			$type==='password' && ($this->meta['password_reveal'] ?? $this->meta['revealable'] ?? true)===true ? 'revealable' : null,
			$type==='password' && ($this->meta['autocomplete'] ?? '')!=='' ? 'autocomplete' : null,
			$type==='hidden' ? 'hidden_input' : null,
			in_array($type, array_merge(['text', 'search', 'autocomplete', 'combobox', 'combo_box', 'textarea', 'markdown', 'html', 'code', 'rich_editor', 'rich_text', 'password', 'email', 'url', 'tel', 'json'], $semanticTextTypes), true) && array_key_exists('min_length', $this->meta) ? 'min_length' : null,
			in_array($type, array_merge(['text', 'search', 'autocomplete', 'combobox', 'combo_box', 'textarea', 'markdown', 'html', 'code', 'rich_editor', 'rich_text', 'password', 'email', 'url', 'tel', 'json'], $semanticTextTypes), true) && array_key_exists('max_length', $this->meta) ? 'max_length' : null,
			array_key_exists('exact_length', $this->meta) ? 'exact_length' : null,
			in_array($type, ['textarea', 'json'], true) ? 'multiline' : null,
			in_array($type, ['textarea', 'json'], true) && ($this->meta['auto_resize'] ?? false)===true ? 'auto_resize' : null,
			in_array($type, ['textarea', 'json'], true) && array_key_exists('rows', $this->meta) ? 'rows' : null,
			$type==='code' ? 'monospace' : null,
			$type==='code' ? 'syntax_language' : null,
			in_array($type, ['tags', 'tags_input'], true) ? 'chips' : null,
			in_array($type, ['tags', 'tags_input'], true) && (($this->meta['suggestions'] ?? [])!==[] || $this->options!==[]) ? 'suggestions' : null,
			in_array($type, ['key_value', 'keyvalue'], true) ? 'key_value_pairs' : null,
			in_array($type, ['key_value', 'keyvalue'], true) ? 'preview' : null,
			in_array($type, ['radio', 'checkbox_list'], true) ? 'choice_cards' : null,
			in_array($type, ['toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true) ? 'segmented_buttons' : null,
			in_array($type, ['radio', 'checkbox_list', 'toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true) && array_key_exists('choice_columns', $this->meta) ? 'choice_columns' : null,
			in_array($type, ['select', 'enum', 'relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many', 'multi_select', 'multiselect', 'radio', 'checkbox_list', 'toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true) ? 'choices' : null,
			in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true) ? 'switch' : null,
			in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true) && (($this->meta['on_label'] ?? '')!=='' || ($this->meta['off_label'] ?? '')!=='') ? 'boolean_labels' : null,
			in_array($type, ['date', 'datetime', 'datetime_local', 'month', 'week', 'time', 'date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true) && (array_key_exists('min', $this->meta) || array_key_exists('max', $this->meta)) ? 'bounded' : null,
			in_array($type, ['date', 'datetime', 'datetime_local', 'month', 'week', 'time', 'date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true) && (($this->meta['prepend_buttons'] ?? [])!==[] || ($this->meta['append_buttons'] ?? [])!==[]) ? 'quick_fill' : null,
			in_array($type, ['date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true) ? 'range_pair' : null,
			$type==='color' ? 'native_color_picker' : null,
			$type==='rating' ? 'rating' : null,
			$this->stateCallback!==null || ($this->meta['reactive'] ?? false)===true || ($this->meta['live'] ?? false)===true ? 'reactive' : null,
			$this->visibleWhen!==[] || $this->hiddenWhen!==[] || $this->requiredWhen!==[] || $this->requiredUnless!==[] ? 'conditional' : null,
			$this->hydrateCallback!==null ? 'hydrate_hook' : null,
			$this->dehydrateCallback!==null ? 'dehydrate_hook' : null,
			$this->validateCallback!==null ? 'validate_hook' : null,
			$editor!=='' ? 'editor' : null,
		], static fn(?string $capability): bool => $capability!==null));
		return [
			'type'=>$type,
			'label'=>(string)($component['label'] ?? self::humanize($type)),
			'category'=>$category,
			'builtin'=>($component['builtin'] ?? false)===true,
			'editor'=>$editor !== '' ? $editor : null,
			'native'=>($this->meta['native'] ?? true)!==false,
			'preview'=>($this->meta['preview'] ?? false)===true,
			'media_collection'=>$this->meta['media_collection'] ?? null,
			'suggestions'=>is_array($this->meta['suggestions'] ?? null) ? count($this->meta['suggestions']) : 0,
			'capabilities'=>$capabilities,
		];
	}

	/**
	 * Evaluates dependency-driven visibility for the current record and request.
	 *
	 * visibleWhen rules must all match and hiddenWhen rules must not match. Values are resolved from
	 * submitted request input first, then record data, so reactive form submissions can override persisted record state.
	 *
	 * @param mixed $record Source record.
	 * @param PanelRequest|null $request Current panel request.
	 * @return bool Whether dependency conditions allow rendering.
	 */
	private function dependencyVisible(mixed $record=null, ?PanelRequest $request=null): bool {
		foreach($this->visibleWhen as $field=>$expected){
			if(!self::conditionMatches(self::dependencyValue($field, $record, $request), $expected)){
				return false;
			}
		}
		foreach($this->hiddenWhen as $field=>$expected){
			if(self::conditionMatches(self::dependencyValue($field, $record, $request), $expected)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves a dependency field value from request input or record state.
	 *
	 * field names are normalized, request input has precedence, arrays use key lookup, and objects use
	 * public property access or conventional getX methods before returning null.
	 *
	 * @param string $field Dependency field name.
	 * @param mixed $record Source record.
	 * @param PanelRequest|null $request Current panel request.
	 * @return mixed request input value, array/object record value, getter result, or null when the dependency is unavailable.
	 */
	private static function dependencyValue(string $field, mixed $record=null, ?PanelRequest $request=null): mixed {
		$field=self::normalizeName($field);
		if($request!==null && array_key_exists($field, $request->input())){
			return $request->input($field);
		}
		if(is_array($record)){
			return $record[$field] ?? null;
		}
		if(is_object($record)){
			if(isset($record->{$field})){
				return $record->{$field};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $field)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return null;
	}

	/**
	 * Compares an actual dependency value against a configured condition value.
	 *
	 * arrays are treated as string allow-lists, true/false use the shared truthy semantic, and all other
	 * values compare by string identity to keep request values and record scalars aligned.
	 *
	 * @param mixed $actual Actual dependency value.
	 * @param mixed $expected Expected condition value.
	 * @return bool Whether the condition matches.
	 */
	private static function conditionMatches(mixed $actual, mixed $expected): bool {
		if(is_array($expected)){
			return in_array((string)$actual, array_map(static fn(mixed $value): string => (string)$value, $expected), true);
		}
		if($expected===true){
			return self::truthy($actual);
		}
		if($expected===false){
			return !self::truthy($actual);
		}
		return (string)$actual===(string)$expected;
	}

	/**
	 * Normalizes grid span configuration for responsive field layout.
	 *
	 * scalar spans are clamped to 1-12, the full keyword is preserved, and responsive arrays are keyed
	 * by normalized breakpoints with invalid breakpoints omitted.
	 *
	 * @param int|string|array<string, mixed> $span Raw span value.
	 * @return int|string|array<string, int|string|array> Normalized span.
	 */
	private static function normalizeGridSpan(int|string|array $span): int|string|array {
		if(is_array($span)){
			$normalized=[];
			foreach($span as $breakpoint=>$value){
				$breakpoint=self::normalizeGridBreakpoint((string)$breakpoint);
				if($breakpoint!==''){
					$normalized[$breakpoint]=self::normalizeGridSpan($value);
				}
			}
			return $normalized;
		}
		if(is_string($span) && strtolower(trim($span))==='full'){
			return 'full';
		}
		return max(1, min(12, (int)$span));
	}

	/**
	 * Normalizes grid start configuration for responsive field layout.
	 *
	 * scalar starts are clamped to 1-12 and responsive arrays are keyed by normalized breakpoints with
	 * invalid breakpoints omitted.
	 *
	 * @param int|string|array<string, mixed> $start Raw grid start value.
	 * @return int|array<string, int|array> Normalized start.
	 */
	private static function normalizeGridStart(int|string|array $start): int|array {
		if(is_array($start)){
			$normalized=[];
			foreach($start as $breakpoint=>$value){
				$breakpoint=self::normalizeGridBreakpoint((string)$breakpoint);
				if($breakpoint!==''){
					$normalized[$breakpoint]=self::normalizeGridStart($value);
				}
			}
			return $normalized;
		}
		return max(1, min(12, (int)$start));
	}

	/**
	 * Normalizes responsive grid breakpoint aliases to renderer tokens.
	 *
	 * base/default aliases become default, known size aliases become sm/md/lg/xl/2xl, and unknown
	 * breakpoint names return an empty string so callers can discard them.
	 *
	 * @param string $breakpoint Raw breakpoint name.
	 * @return string Normalized breakpoint token or empty string.
	 */
	private static function normalizeGridBreakpoint(string $breakpoint): string {
		$breakpoint=strtolower(trim(str_replace(['-', ' '], '_', $breakpoint)));
		return match($breakpoint){
			'', 'base', 'default', 'initial'=>'default',
			'sm', 'small'=>'sm',
			'md', 'medium'=>'md',
			'lg', 'large'=>'lg',
			'xl'=>'xl',
			'2xl', 'xxl', 'wide'=>'2xl',
			default=>'',
		};
	}

	/**
	 * Casts submitted values according to field type before validation/dehydration.
	 *
	 * compound fields normalize nested rows/groups, upload fields normalize file payloads, multi-value
	 * fields become lists, key-value/tags fields parse structured text, numeric blanks become null, and primitive numeric
	 * or boolean types are coerced without touching unsupported custom values.
	 *
	 * @param mixed $value Submitted or hydrated value.
	 * @param mixed $record Source record.
	 * @param PanelRequest|null $request Current panel request.
	 * @return mixed field-type value prepared for validation/dehydration, including normalized compound rows, upload payloads, lists, numeric values, booleans, or untouched custom values.
	 */
	private function castValue(mixed $value, mixed $record=null, ?PanelRequest $request=null): mixed {
		if($this->type==='repeater'){
			return self::normalizeRepeaterRows($value, self::repeaterFieldDefinitions($this->meta), $record, $request);
		}
		if($this->type==='builder'){
			return self::normalizeBuilderRows($value, self::builderBlockDefinitions($this->meta), $record, $request);
		}
		if(in_array($this->type, ['fieldset', 'group', 'field_group', 'address'], true)){
			return self::normalizeFieldGroupValue($value, self::childFieldDefinitions($this->meta), $record, $request);
		}
		if(in_array($this->type, ['date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true)){
			if(is_array($value)){
				return [
					'start'=>trim((string)($value['start'] ?? $value[0] ?? '')),
					'end'=>trim((string)($value['end'] ?? $value[1] ?? '')),
				];
			}
			$text=trim((string)$value);
			if($text===''){
				return ['start'=>'', 'end'=>''];
			}
			$parts=preg_split('/\s*(?:\.\.|→|to)\s*/i', $text, 2) ?: [$text];
			return [
				'start'=>trim((string)($parts[0] ?? '')),
				'end'=>trim((string)($parts[1] ?? '')),
			];
		}
		if(in_array($this->type, ['multi_select', 'multiselect', 'multi_relationship', 'belongs_to_many', 'checkbox_list'], true) || (in_array($this->type, ['toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true) && ($this->meta['multiple'] ?? false)===true)){
			return self::normalizeListValue($value);
		}
		if(in_array($this->type, ['tags', 'tags_input'], true)){
			return self::normalizeTagsValue($value, (string)($this->meta['tag_separator'] ?? ','));
		}
		if(in_array($this->type, ['key_value', 'keyvalue'], true)){
			return self::normalizeKeyValue($value, (string)($this->meta['pair_separator'] ?? "\n"), (string)($this->meta['key_separator'] ?? '='));
		}
		if(self::isFileUploadType($this->type)){
			if(($this->meta['custom_uploader'] ?? false)===true && is_string($value) && trim($value)!==''){
				$decoded=json_decode($value, true);
				if(is_array($decoded)){
					return ($this->meta['multiple'] ?? false)===true ? array_values($decoded) : $decoded;
				}
			}
			if(!is_array($value) && !self::blank($value)){
				return $value;
			}
			$files=self::normalizeUploadedFiles($value);
			if($files===[]){
				return null;
			}
			return ($this->meta['multiple'] ?? false)===true ? $files : $files[0];
		}
		if($value==='' && in_array($this->type, ['number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage', 'slider', 'latitude', 'longitude'], true)){
			return null;
		}
		return match($this->type){
			'integer'=>is_numeric($value) ? (int)$value : $value,
			'number', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage', 'slider', 'latitude', 'longitude'=>is_numeric($value) ? (float)$value : $value,
			'rating'=>is_numeric($value) ? (int)$value : $value,
			'boolean', 'bool', 'checkbox', 'toggle'=>self::truthy($value),
			default=>$value,
		};
	}

	/**
	 * Provides built-in renderer and formatting defaults for known field types.
	 *
	 * defaults are metadata only; callers may override them through fluent field configuration. The map
	 * keeps masks, input modes, format rules, relationship keys, and display-only dehydration behavior close to type
	 * normalization.
	 *
	 * @param string $type Normalized field type.
	 * @return array<string, mixed> Default metadata for the type.
	 */
	private static function typeDefaults(string $type): array {
		return match($type){
			'otp', 'one_time_code', 'verification_code'=>[
				'input_mode'=>'numeric',
				'autocomplete'=>'one-time-code',
				'mask'=>'999999',
				'mask_submit_normalized'=>true,
				'format_event'=>'input',
				'character_counter'=>true,
				'character_counter_max'=>6,
			],
			'pin', 'pin_code'=>[
				'input_mode'=>'numeric',
				'autocomplete'=>'one-time-code',
				'mask'=>'9999',
				'mask_submit_normalized'=>true,
				'format_event'=>'input',
				'character_counter'=>true,
				'character_counter_max'=>4,
			],
			'credit_card', 'card_number'=>[
				'input_mode'=>'numeric',
				'format_rule'=>'credit_card',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'credit_card_expiry', 'card_expiry'=>[
				'input_mode'=>'numeric',
				'format_rule'=>'credit_card_expiry',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'card_cvc', 'cvc', 'cvv'=>[
				'input_mode'=>'numeric',
				'format_rule'=>'card_cvc',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'iban'=>[
				'input_mode'=>'text',
				'format_rule'=>'iban',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'slug'=>[
				'input_mode'=>'text',
				'format_rule'=>'slug',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'uuid'=>[
				'input_mode'=>'text',
				'format_rule'=>'uuid',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'ulid'=>[
				'input_mode'=>'text',
				'format_rule'=>'ulid',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'json'=>[
				'format_rule'=>'json',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
				'rows'=>6,
				'auto_resize'=>true,
			],
			'domain', 'hostname'=>[
				'input_mode'=>'url',
				'format_rule'=>'domain',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'timezone', 'time_zone'=>[
				'input_mode'=>'text',
				'format_rule'=>'timezone',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'locale', 'language_tag'=>[
				'input_mode'=>'text',
				'format_rule'=>'locale',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'mime_type', 'content_type'=>[
				'input_mode'=>'text',
				'format_rule'=>'mime_type',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'semver', 'semantic_version'=>[
				'input_mode'=>'text',
				'format_rule'=>'semver',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'cron_expression', 'cron'=>[
				'input_mode'=>'text',
				'format_rule'=>'cron_expression',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'language_code', 'iso_language'=>[
				'input_mode'=>'text',
				'format_rule'=>'language_code',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'country_code', 'iso_country'=>[
				'input_mode'=>'text',
				'format_rule'=>'country_code',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'subdivision_code', 'region_code'=>[
				'input_mode'=>'text',
				'format_rule'=>'subdivision_code',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'currency_code', 'iso_currency'=>[
				'input_mode'=>'text',
				'format_rule'=>'currency_code',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'ip_address', 'ip'=>[
				'input_mode'=>'text',
				'format_rule'=>'ip_address',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'ipv4'=>[
				'input_mode'=>'numeric',
				'format_rule'=>'ipv4',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'ipv6'=>[
				'input_mode'=>'text',
				'format_rule'=>'ipv6',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'mac_address', 'mac'=>[
				'input_mode'=>'text',
				'format_rule'=>'mac_address',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'hex_color', 'color_hex'=>[
				'input_mode'=>'text',
				'format_rule'=>'hex_color',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
				'color_swatch'=>true,
			],
			'latitude'=>[
				'input_mode'=>'decimal',
				'format_rule'=>'latitude',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
				'min'=>-90,
				'max'=>90,
				'step'=>'any',
			],
			'longitude'=>[
				'input_mode'=>'decimal',
				'format_rule'=>'longitude',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
				'min'=>-180,
				'max'=>180,
				'step'=>'any',
			],
			'coordinates', 'lat_lng', 'lng_lat'=>[
				'input_mode'=>'text',
				'format_rule'=>$type==='lng_lat' ? 'lng_lat' : 'coordinates',
				'format_options'=>[],
				'format_event'=>'blur',
				'submit_normalized'=>true,
			],
			'phone_international'=>[
				'input_mode'=>'tel',
				'format_rule'=>'phone_international',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'postal_code', 'postal'=>[
				'input_mode'=>'text',
				'format_rule'=>'postal_code',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'postal_code_ca', 'canadian_postal_code'=>[
				'input_mode'=>'text',
				'format_rule'=>'postal_code_ca',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'zip_code_us', 'postal_code_us', 'zip'=>[
				'input_mode'=>'numeric',
				'format_rule'=>'zip_code_us',
				'format_options'=>[],
				'format_event'=>'input',
				'submit_normalized'=>true,
			],
			'placeholder', 'display', 'display_only', 'view_field'=>[
				'dehydrated'=>false,
			],
			'relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'=>[
				'key_attribute'=>'id',
				'title_attribute'=>'name',
				'searchable'=>true,
			],
			'autocomplete', 'combobox', 'combo_box'=>[
				'autocomplete'=>'off',
			],
			'rating'=>[
				'min'=>1,
				'max'=>5,
				'step'=>1,
			],
			default=>[],
		};
	}

	/**
	 * Normalizes formatted scalar input according to the effective format rule.
	 *
	 * normalization only applies to scalar non-null values when submit_normalized is enabled; dynamic
	 * postal/geoposition rules may be selected from context, masks can fall back to alphanumeric stripping, and each
	 * semantic format delegates to a specialized normalizer.
	 *
	 * @param mixed $value Submitted value.
	 * @param mixed $record Source record.
	 * @param PanelRequest|null $request Current panel request.
	 * @param array<string, mixed> $values Current form values used for context.
	 * @return mixed normalized scalar text for the effective format rule, or the original value when normalization is disabled or inapplicable.
	 */
	private function normalizeFormattedValue(mixed $value, mixed $record=null, ?PanelRequest $request=null, array $values=[]): mixed {
		if(($this->meta['submit_normalized'] ?? true)!==true || is_array($value) || is_object($value) || $value===null){
			return $value;
		}
		$text=trim((string)$value);
		if($text===''){
			return $value;
		}
		$context=$this->formatContext($values, $record, $request);
		$rule=self::effectiveFormatRule($this->formatRuleName(), $context);
		if($rule===''){
			return ($this->meta['mask_submit_normalized'] ?? false)===true
				? (preg_replace('/[^0-9A-Za-z]+/', '', $text) ?? '')
				: $value;
		}
		$text=self::geopositionReformatPostalCode($rule, $context, $text) ?? $text;
		return match($rule){
			'currency', 'money', 'percent', 'percentage'=>self::normalizeDecimalText($text),
			'phone_international'=>self::normalizeInternationalPhoneText($text, $context),
			'phone', 'phone_us', 'phone_ca', 'credit_card', 'card', 'credit_card_expiry', 'card_expiry', 'card_cvc', 'cvc', 'cvv', 'digits'=>preg_replace('/\D+/', '', $text) ?? '',
			'ssn', 'social_security_number', 'ein', 'tax_id', 'zip_code_us', 'postal_code_us', 'zip'=>preg_replace('/\D+/', '', $text) ?? '',
			'postal_code_ca', 'canadian_postal_code', 'postal_code_gb', 'uk_postcode', 'postal_code_international', 'iban', 'alphanumeric'=>strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $text) ?? ''),
			'postal_code_au', 'australian_postcode'=>preg_replace('/\D+/', '', $text) ?? '',
			'postal_code_nz', 'new_zealand_postcode'=>preg_replace('/\D+/', '', $text) ?? '',
			'postal_code_fr', 'french_postcode', 'postal_code_de', 'german_postcode'=>preg_replace('/\D+/', '', $text) ?? '',
			'postal_code_nl', 'dutch_postcode', 'postal_code_ie', 'eircode'=>strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $text) ?? ''),
			'alpha'=>preg_replace('/[^A-Za-z]+/', '', $text) ?? '',
			'email'=>strtolower(trim($text)),
			'url'=>self::normalizeUrlText($text),
			'map_url', 'maps_url'=>self::normalizeMapUrlText($text),
			'domain', 'hostname'=>self::normalizeDomainText($text),
			'timezone', 'time_zone'=>self::normalizeTimezoneText($text),
			'locale', 'language_tag'=>self::normalizeLocaleText($text),
			'json', 'json_text'=>self::normalizeJsonText($text),
			'mime_type', 'content_type'=>self::normalizeMimeTypeText($text),
			'semver', 'semantic_version'=>self::normalizeSemverText($text),
			'cron_expression', 'cron'=>self::normalizeCronExpressionText($text),
			'language_code', 'iso_language'=>self::normalizeLanguageCodeText($text),
			'country_code', 'iso_country'=>self::normalizeCountryCode($text),
			'subdivision_code', 'region_code'=>self::normalizeSubdivisionCode($text),
			'currency_code', 'iso_currency'=>self::normalizeCurrencyCodeText($text),
			'ip_address', 'ip', 'ipv4', 'ipv6'=>strtolower(trim($text)),
			'mac_address', 'mac'=>self::normalizeMacAddressText($text),
			'uuid'=>self::normalizeUuidText($text),
			'ulid'=>self::normalizeUlidText($text),
			'hex_color', 'color_hex'=>self::normalizeHexColorText($text),
			'latitude', 'longitude'=>self::normalizeCoordinateText($text, (int)($this->meta['format_options']['decimals'] ?? 6)),
			'coordinates', 'lat_lng', 'lng_lat'=>self::normalizeCoordinatePairText($text, (int)($this->meta['format_options']['decimals'] ?? 6), $rule==='lng_lat'),
			'lowercase'=>strtolower($text),
			'uppercase'=>strtoupper($text),
			'title_case'=>self::titleCaseText($text),
			'sentence_case'=>self::sentenceCaseText($text),
			'slug'=>self::slugText($text),
			'snake_case'=>self::separatorCaseText($text, '_'),
			'kebab_case'=>self::separatorCaseText($text, '-'),
			'camel_case'=>self::camelCaseText($text),
			'trim'=>trim($text),
			default=>$value,
		};
	}

	/**
	 * Normalizes phone text into international digits using contextual country information.
	 *
	 * explicit plus-prefixed numbers keep their supplied country code, local trunk zeros are replaced
	 * when a country prefix is known, and output contains digits only.
	 *
	 * @param string $value Raw phone text.
	 * @param array<string, mixed> $context Formatting context.
	 * @param bool $prependLocalWithoutTrunk Whether local numbers without a trunk zero receive the country prefix.
	 * @return string Normalized phone digits.
	 */
	private static function normalizeInternationalPhoneText(string $value, array $context, bool $prependLocalWithoutTrunk=true): string {
		$explicitPlus=str_starts_with(ltrim($value), '+');
		$digits=preg_replace('/\D+/', '', $value) ?? '';
		$prefix=self::internationalPhoneCode($context);
		if($prefix!=='' && $digits!=='' && !str_starts_with($digits, $prefix)){
			if($explicitPlus){
				return $digits;
			}
			if(strlen($digits)>1 && str_starts_with($digits, '0')){
				$digits=$prefix.substr($digits, 1);
			}
			elseif($prependLocalWithoutTrunk){
				$digits=$prefix.$digits;
			}
		}
		elseif($prefix!=='' && str_starts_with($digits, $prefix.'0')){
			$digits=$prefix.substr($digits, strlen($prefix)+1);
		}
		return $digits;
	}

	/**
	 * Normalizes URL text by adding an HTTPS scheme when no scheme-like prefix exists.
	 *
	 * existing absolute, protocol-relative, mailto, and tel values are preserved; bare host/path text is
	 * promoted to https for browser-safe link handling.
	 *
	 * @param string $value Raw URL text.
	 * @return string Normalized URL text.
	 */
	private static function normalizeUrlText(string $value): string {
		$value=trim($value);
		if($value==='' || str_contains($value, '://') || str_starts_with($value, '//') || str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:')){
			return $value;
		}
		return 'https://'.$value;
	}

	/**
	 * Normalizes map input into either a map search URL or a normalized URL.
	 *
	 * coordinate pairs without a URL scheme become Google Maps query URLs; all other values follow the
	 * generic URL normalization path.
	 *
	 * @param string $value Raw map URL or coordinate text.
	 * @return string Normalized map URL.
	 */
	private static function normalizeMapUrlText(string $value): string {
		$value=trim($value);
		if(!str_contains($value, '://') && !str_starts_with($value, '//') && self::validCoordinatePairText($value)){
			return 'https://www.google.com/maps?q='.rawurlencode(self::normalizeCoordinatePairText($value, 6));
		}
		return self::normalizeUrlText($value);
	}

	/**
	 * Normalizes domain or hostname text for validation and storage.
	 *
	 * URL-like values are reduced to host, query/fragment/path/port are stripped from bare text, and the
	 * result is lowercased and trimmed of surrounding dots.
	 *
	 * @param string $value Raw domain, host, or URL text.
	 * @return string Normalized domain text.
	 */
	private static function normalizeDomainText(string $value): string {
		$value=strtolower(trim($value));
		if($value===''){
			return '';
		}
		if(str_contains($value, '://') || str_starts_with($value, '//')){
			$host=parse_url(str_starts_with($value, '//') ? 'https:'.$value : $value, PHP_URL_HOST);
			return is_string($host) ? trim($host, ". \t\n\r\0\x0B") : '';
		}
		$value=preg_replace('/[?#].*$/', '', $value) ?? $value;
		$value=explode('/', $value, 2)[0] ?? $value;
		$value=explode(':', $value, 2)[0] ?? $value;
		return trim($value, ". \t\n\r\0\x0B");
	}

	/**
	 * Normalizes timezone text toward canonical PHP timezone identifiers.
	 *
	 * separators and casing are normalized, UTC/GMT aliases are preserved, and known identifiers are
	 * canonicalized through timezone_identifiers_list().
	 *
	 * @param string $value Raw timezone text.
	 * @return string Normalized timezone identifier.
	 */
	private static function normalizeTimezoneText(string $value): string {
		$value=trim(str_replace('\\', '/', $value));
		if($value===''){
			return '';
		}
		$value=preg_replace('/\s+/', '_', $value) ?? $value;
		$upper=strtoupper($value);
		if(in_array($upper, ['UTC', 'GMT'], true)){
			return $upper;
		}
		$parts=array_map(static function(string $part): string {
			return implode('_', array_map(static fn(string $piece): string => ucfirst(strtolower($piece)), explode('_', $part)));
		}, explode('/', $value));
		$candidate=implode('/', $parts);
		$map=self::timezoneCanonicalMap();
		return $map[strtolower($candidate)] ?? $candidate;
	}

	/**
	 * Returns canonical timezone aliases used by formatters.
	 *
	 * @return array.
	 */
	private static function timezoneCanonicalMap(): array {
		static $map=null;
		if($map!==null){
			return $map;
		}
		$map=['utc'=>'UTC', 'gmt'=>'GMT'];
		foreach(timezone_identifiers_list() as $timezone){
			$map[strtolower($timezone)]=$timezone;
		}
		return $map;
	}

	/**
	 * Normalizes locale/language tag text into canonical casing.
	 *
	 * underscores become hyphens, Locale::canonicalize is used when available, language subtags are
	 * lowercase, script subtags titlecase, and region subtags uppercase.
	 *
	 * @param string $value Raw locale text.
	 * @return string Normalized locale tag.
	 */
	private static function normalizeLocaleText(string $value): string {
		$value=trim(str_replace('_', '-', $value));
		if($value===''){
			return '';
		}
		if(class_exists('\\Locale', false)){
			try{
				$canonical=\Locale::canonicalize($value);
				if(is_string($canonical) && $canonical!==''){
					$value=str_replace('_', '-', $canonical);
				}
			}
			catch(\Throwable){
			}
		}
		$parts=explode('-', $value);
		foreach($parts as $index=>$part){
			if($index===0){
				$parts[$index]=strtolower($part);
				continue;
			}
			if(strlen($part)===4 && ctype_alpha($part)){
				$parts[$index]=ucfirst(strtolower($part));
				continue;
			}
			if((strlen($part)===2 && ctype_alpha($part)) || (strlen($part)===3 && ctype_digit($part))){
				$parts[$index]=strtoupper($part);
				continue;
			}
			$parts[$index]=strtolower($part);
		}
		return implode('-', array_filter($parts, static fn(string $part): bool => $part!==''));
	}

	/**
	 * Normalizes JSON text by decoding and re-encoding it when valid.
	 *
	 * invalid JSON is returned unchanged so validation can report the semantic failure elsewhere;
	 * valid JSON preserves Unicode and slashes, with optional pretty printing for display contexts.
	 *
	 * @param string $value Raw JSON text.
	 * @param bool $pretty Whether to emit pretty JSON.
	 * @return string Normalized JSON text or original invalid text.
	 */
	private static function normalizeJsonText(string $value, bool $pretty=false): string {
		$value=trim($value);
		if($value===''){
			return '';
		}
		try{
			$decoded=json_decode($value, true, 512, JSON_THROW_ON_ERROR);
			$flags=JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
			if($pretty){
				$flags|=JSON_PRETTY_PRINT;
			}
			$encoded=json_encode($decoded, $flags);
			return is_string($encoded) ? $encoded : $value;
		}
		catch(\Throwable){
			return $value;
		}
	}

	/**
	 * Normalizes MIME type text and parameter spacing.
	 *
	 * @param string $value Raw MIME type.
	 * @return string Lowercase MIME type with normalized parameter separators.
	 */
	private static function normalizeMimeTypeText(string $value): string {
		$value=strtolower(trim($value));
		if($value===''){
			return '';
		}
		$value=preg_replace('/\s*;\s*/', '; ', $value) ?? $value;
		$value=preg_replace('/\s*=\s*/', '=', $value) ?? $value;
		return $value;
	}

	/**
	 * Normalizes semantic-version text by removing a leading v prefix and lowercasing.
	 *
	 * @param string $value Raw semantic-version text.
	 * @return string Normalized semantic-version text.
	 */
	private static function normalizeSemverText(string $value): string {
		$value=trim($value);
		if($value===''){
			return '';
		}
		if(preg_match('/^v(?=[0-9])/i', $value)===1){
			$value=substr($value, 1);
		}
		return strtolower($value);
	}

	/**
	 * Normalizes cron expression text by lowercasing and collapsing whitespace.
	 *
	 * @param string $value Raw cron expression.
	 * @return string Normalized cron expression.
	 */
	private static function normalizeCronExpressionText(string $value): string {
		$value=strtolower(trim($value));
		return preg_replace('/\s+/', ' ', $value) ?? $value;
	}

	/**
	 * Normalizes language names, ISO aliases, and locale tags to a primary language code.
	 *
	 * common English names and ISO-639 aliases are mapped explicitly, otherwise locale normalization is
	 * used and non-letter characters are stripped from the primary subtag.
	 *
	 * @param string $value Raw language code, name, or locale.
	 * @return string Normalized lowercase language code.
	 */
	private static function normalizeLanguageCodeText(string $value): string {
		$value=trim(str_replace('_', '-', $value));
		if($value===''){
			return '';
		}
		$upper=strtoupper($value);
		$map=[
			'ENGLISH'=>'en', 'ENG'=>'en',
			'FRENCH'=>'fr', 'FRANCAIS'=>'fr', 'FRANÇAIS'=>'fr', 'FRE'=>'fr', 'FRA'=>'fr',
			'GERMAN'=>'de', 'DEUTSCH'=>'de', 'GER'=>'de', 'DEU'=>'de',
			'DUTCH'=>'nl', 'NEDERLANDS'=>'nl', 'DUT'=>'nl', 'NLD'=>'nl',
			'SPANISH'=>'es', 'ESPANOL'=>'es', 'ESPAÑOL'=>'es', 'SPA'=>'es',
			'PORTUGUESE'=>'pt', 'POR'=>'pt',
			'ITALIAN'=>'it', 'ITA'=>'it',
			'JAPANESE'=>'ja', 'JPN'=>'ja',
			'CHINESE'=>'zh', 'CHI'=>'zh', 'ZHO'=>'zh',
			'ARABIC'=>'ar', 'ARA'=>'ar',
		];
		if(isset($map[$upper])){
			return $map[$upper];
		}
		$locale=self::normalizeLocaleText($value);
		$primary=explode('-', $locale, 2)[0] ?? $locale;
		return strtolower(preg_replace('/[^A-Za-z]+/', '', $primary) ?? '');
	}

	/**
	 * Normalizes currency symbols, names, and aliases to uppercase ISO-style currency codes.
	 *
	 * @param string $value Raw currency text.
	 * @return string Normalized currency code.
	 */
	private static function normalizeCurrencyCodeText(string $value): string {
		$value=strtoupper(trim($value));
		$value=preg_replace('/\s+/', ' ', $value) ?? $value;
		return match($value){
			'$'=>'USD',
			'C$', 'CA$', 'CAD$', 'CANADIAN DOLLAR', 'CANADIAN DOLLARS'=>'CAD',
			'US$', 'USD$', 'DOLLAR', 'DOLLARS', 'US DOLLAR', 'US DOLLARS', 'UNITED STATES DOLLAR', 'UNITED STATES DOLLARS'=>'USD',
			'€', 'EURO', 'EUROS'=>'EUR',
			'£', 'POUND', 'POUNDS', 'POUND STERLING', 'BRITISH POUND', 'BRITISH POUNDS'=>'GBP',
			'¥', 'YEN', 'JAPANESE YEN'=>'JPY',
			default=>preg_replace('/[^A-Z]+/', '', $value) ?? '',
		};
	}

	/**
	 * Normalizes MAC address text into colon-separated uppercase octets.
	 *
	 * @param string $value Raw MAC address text.
	 * @return string Normalized MAC address prefix.
	 */
	private static function normalizeMacAddressText(string $value): string {
		$hex=strtoupper(preg_replace('/[^0-9A-Fa-f]+/', '', $value) ?? '');
		$hex=substr($hex, 0, 12);
		return trim(implode(':', str_split($hex, 2)), ':');
	}

	/**
	 * Normalizes UUID text by stripping non-hex characters and inserting canonical dashes.
	 *
	 * @param string $value Raw UUID text.
	 * @return string Normalized UUID prefix.
	 */
	private static function normalizeUuidText(string $value): string {
		$hex=strtolower(preg_replace('/[^0-9A-Fa-f]+/', '', $value) ?? '');
		$hex=substr($hex, 0, 32);
		if(strlen($hex)<=8){
			return $hex;
		}
		$parts=[
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20),
		];
		return trim(implode('-', array_filter($parts, static fn(string $part): bool => $part!=='')), '-');
	}

	/**
	 * Normalizes ULID text into an uppercase 26-character alphanumeric prefix.
	 *
	 * @param string $value Raw ULID text.
	 * @return string Normalized ULID text.
	 */
	private static function normalizeUlidText(string $value): string {
		return strtoupper(substr(preg_replace('/[^0-9A-Za-z]+/', '', trim($value)) ?? '', 0, 26));
	}

	/**
	 * Normalizes hex color input into a #rrggbb value when any hex digits are present.
	 *
	 * @param string $value Raw color text.
	 * @return string Normalized hex color or empty string.
	 */
	private static function normalizeHexColorText(string $value): string {
		$hex=strtolower(preg_replace('/[^0-9A-Fa-f]+/', '', $value) ?? '');
		if(strlen($hex)===3){
			$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		}
		else {
			$hex=substr($hex, 0, 6);
		}
		return $hex==='' ? '' : '#'.$hex;
	}

	/**
	 * Normalizes coordinate text by decimal-normalizing and rounding to a bounded precision.
	 *
	 * @param string $value Raw coordinate text.
	 * @param int $decimals Requested decimal precision.
	 * @return string Normalized coordinate text.
	 */
	private static function normalizeCoordinateText(string $value, int $decimals=6): string {
		$value=self::normalizeDecimalText($value);
		if($value==='' || !is_numeric($value)){
			return $value;
		}
		$decimals=max(0, min(10, $decimals));
		$rounded=number_format((float)$value, $decimals, '.', '');
		return $decimals>0 ? rtrim(rtrim($rounded, '0'), '.') : $rounded;
	}

	/**
	 * Normalizes a latitude/longitude pair into comma-separated coordinate text.
	 *
	 * comma or whitespace separators are accepted, precision is delegated to normalizeCoordinateText(),
	 * and lngLat swaps the output order for source formats that provide longitude first.
	 *
	 * @param string $value Raw coordinate pair text.
	 * @param int $decimals Requested decimal precision.
	 * @param bool $lngLat Whether the input order is longitude, latitude.
	 * @return string Normalized coordinate pair or original trimmed text.
	 */
	private static function normalizeCoordinatePairText(string $value, int $decimals=6, bool $lngLat=false): string {
		$parts=preg_split('/\s*,\s*|\s+/', trim($value)) ?: [];
		$parts=array_values(array_filter($parts, static fn(string $part): bool => trim($part)!==''));
		if(count($parts)<2){
			return trim($value);
		}
		$first=self::normalizeCoordinateText($parts[0], $decimals);
		$second=self::normalizeCoordinateText($parts[1], $decimals);
		return $lngLat ? $second.','.$first : $first.','.$second;
	}

	/**
	 * Converts text to simple title case for formatted submissions.
	 *
	 * @param string $value Raw text.
	 * @return string Title-cased text.
	 */
	private static function titleCaseText(string $value): string {
		return preg_replace_callback('/\b[[:alpha:]]/', static fn(array $match): string => strtoupper($match[0]), strtolower(trim($value))) ?? trim($value);
	}

	/**
	 * Converts text to simple sentence case after whitespace collapse.
	 *
	 * @param string $value Raw text.
	 * @return string Sentence-cased text.
	 */
	private static function sentenceCaseText(string $value): string {
		$value=strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
		return $value==='' ? '' : strtoupper(substr($value, 0, 1)).substr($value, 1);
	}

	/**
	 * Converts text to a lowercase dash-separated slug.
	 *
	 * @param string $value Raw text.
	 * @return string Slug text.
	 */
	private static function slugText(string $value): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^0-9a-z-]+/', '-', $value) ?? $value;
		$value=preg_replace('/-+/', '-', $value) ?? $value;
		return trim($value, '-');
	}

	/**
	 * Converts text to separator case with camel-case boundary splitting.
	 *
	 * @param string $value Raw text.
	 * @param string $separator Separator to use.
	 * @return string Separator-case text.
	 */
	private static function separatorCaseText(string $value, string $separator): string {
		$value=preg_replace('/([a-z])([A-Z])/u', '$1 $2', $value) ?? $value;
		$value=preg_replace('/[^0-9A-Za-z]+/', $separator, $value) ?? $value;
		return trim(strtolower($value), $separator);
	}

	/**
	 * Converts text to lower camelCase.
	 *
	 * @param string $value Raw text.
	 * @return string camelCase text.
	 */
	private static function camelCaseText(string $value): string {
		$parts=preg_split('/[^0-9A-Za-z]+/', self::separatorCaseText($value, ' ')) ?: [];
		$parts=array_values(array_filter(array_map(static fn(string $part): string => strtolower($part), $parts), static fn(string $part): bool => $part!==''));
		if($parts===[]){
			return '';
		}
		$first=array_shift($parts);
		return $first.implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
	}

	/**
	 * Normalizes decimal text by stripping grouping/currency characters while preserving one decimal point.
	 *
	 * @param string $value Raw decimal text.
	 * @return string Normalized decimal string.
	 */
	private static function normalizeDecimalText(string $value): string {
		$value=preg_replace('/[^\d.\-]+/', '', $value) ?? '';
		$negative=str_starts_with($value, '-');
		$value=str_replace('-', '', $value);
		$parts=explode('.', $value);
		$integer=array_shift($parts) ?? '';
		$decimals=implode('', $parts);
		$integer=preg_replace('/\D+/', '', $integer) ?? '';
		$decimals=preg_replace('/\D+/', '', $decimals) ?? '';
		$integer=ltrim($integer, '0');
		$normalized=($negative ? '-' : '').($integer!=='' ? $integer : '0');
		if($decimals!==''){
			$normalized.='.'.$decimals;
		}
		return $normalized;
	}

	/**
	 * Normalizes multi-select style values into a unique scalar string list.
	 *
	 * JSON array strings are decoded when possible, null/empty values become an empty list, arrays and
	 * objects inside the list are ignored, and scalar items are trimmed and deduplicated.
	 *
	 * @param mixed $value Raw list value.
	 * @return array<int, string> Normalized unique values.
	 */
	private static function normalizeListValue(mixed $value): array {
		if(is_string($value)){
			$trimmed=trim($value);
			if($trimmed!=='' && str_starts_with($trimmed, '[')){
				$decoded=json_decode($trimmed, true);
				if(is_array($decoded)){
					$value=$decoded;
				}
			}
		}
		$values=is_array($value) ? $value : ($value===null || $value==='' ? [] : [$value]);
		$normalized=[];
		foreach($values as $item){
			if(is_array($item) || is_object($item)){
				continue;
			}
			$item=trim((string)$item);
			if($item!==''){
				$normalized[]=$item;
			}
		}
		return array_values(array_unique($normalized));
	}

	/**
	 * Normalizes tag input into a unique string list.
	 *
	 * arrays are consumed directly, strings split on the configured separator and line breaks, complex
	 * tag values are ignored, and non-empty scalar tags are trimmed and deduplicated.
	 *
	 * @param mixed $value Raw tag value.
	 * @param string $separator Tag separator.
	 * @return array<int, string> Normalized tags.
	 */
	private static function normalizeTagsValue(mixed $value, string $separator=','): array {
		if(is_array($value)){
			$values=$value;
		}
		else {
			$separator=$separator!=='' ? $separator : ',';
			$values=preg_split('/['.preg_quote($separator, '/')."\n\r]+/", (string)$value) ?: [];
		}
		$tags=[];
		foreach($values as $tag){
			if(is_array($tag) || is_object($tag)){
				continue;
			}
			$tag=trim((string)$tag);
			if($tag!==''){
				$tags[]=$tag;
			}
		}
		return array_values(array_unique($tags));
	}

	/**
	 * Normalizes key-value field input into an associative array.
	 *
	 * arrays preserve non-empty keys, JSON object text is decoded recursively, plain text splits into
	 * pairs, and blank keys are dropped.
	 *
	 * @param mixed $value Raw key-value payload.
	 * @param string $pairSeparator Pair separator for plain text.
	 * @param string $keySeparator Key/value separator for plain text.
	 * @return array<string, mixed> Normalized key-value map.
	 */
	private static function normalizeKeyValue(mixed $value, string $pairSeparator="\n", string $keySeparator='='): array {
		if(is_array($value)){
			$normalized=[];
			foreach($value as $key=>$item){
				$key=trim((string)$key);
				if($key!==''){
					$normalized[$key]=is_scalar($item) || $item===null ? (string)$item : $item;
				}
			}
			return $normalized;
		}
		$text=trim((string)$value);
		if($text===''){
			return [];
		}
		$decoded=json_decode($text, true);
		if(is_array($decoded)){
			return self::normalizeKeyValue($decoded, $pairSeparator, $keySeparator);
		}
		$pairSeparator=$pairSeparator!=='' ? $pairSeparator : "\n";
		$keySeparator=$keySeparator!=='' ? $keySeparator : '=';
		$pairs=preg_split('/'.preg_quote($pairSeparator, '/').'|\r\n|\r|\n/', $text) ?: [];
		$normalized=[];
		foreach($pairs as $pair){
			$pair=trim((string)$pair);
			if($pair===''){
				continue;
			}
			[$key, $item]=array_pad(explode($keySeparator, $pair, 2), 2, '');
			$key=trim($key);
			if($key!==''){
				$normalized[$key]=trim($item);
			}
		}
		return $normalized;
	}

	/**
	 * Validates a field value against structural, upload, nested, and scalar rule contracts.
	 *
	 * conditional required state is resolved before rule checks, repeaters validate child fields per
	 * row, file uploads validate count/error/size/type constraints, scalar rules cover common validation grammar, and
	 * callbacks or formatted semantic rules can append additional failures without throwing.
	 *
	 * @param mixed $value Value being validated.
	 * @param array<string, mixed> $values Full submitted form values.
	 * @param mixed $record Source record.
	 * @param PanelRequest|null $request Current panel request.
	 * @param string $operation Current form operation.
	 * @return array<int, string> Validation error messages.
	 */
	private function validateRules(mixed $value, array $values=[], mixed $record=null, ?PanelRequest $request=null, string $operation='form'): array {
		$errors=[];
		$ruleNames=array_map(static fn(string $rule): string => strtolower(trim(explode(':', $rule, 2)[0])), $this->rules);
		$required=$this->required || $this->requiredByConditions($values, $record, $request) || in_array('required', $ruleNames, true);
		if($this->type==='repeater'){
			$rows=self::normalizeRepeaterRows($value, self::repeaterFieldDefinitions($this->meta), $record, $request);
			$value=$rows;
			$min=(int)($this->meta['min_items'] ?? 0);
			$max=(int)($this->meta['max_items'] ?? 0);
			if($required && $rows===[]){
				$errors[]=$this->label.' is required.';
			}
			if($min>0 && count($rows)<$min){
				$errors[]=$this->label.' needs at least '.$min.' item'.($min===1 ? '' : 's').'.';
			}
			if($max>0 && count($rows)>$max){
				$errors[]=$this->label.' allows at most '.$max.' item'.($max===1 ? '' : 's').'.';
			}
			foreach($rows as $index=>$row){
				foreach(self::repeaterFieldDefinitions($this->meta) as $child){
					$childErrors=$child->validateValue($row[$child->name()] ?? null, $row, $record, $request, $operation);
					foreach($childErrors as $message){
						$errors[]=$this->label.' item '.($index+1).': '.$message;
					}
				}
			}
		}
		if(self::isFileUploadType($this->type)){
			$files=self::normalizeUploadedFiles($value);
			$uploadedItems=(($this->meta['custom_uploader'] ?? false)===true) ? self::normalizeCustomUploaderItems($value) : [];
			$hasExisting=!is_array($value) && !self::blank($value);
			if($required && $files===[] && $uploadedItems===[] && !$hasExisting){
				$errors[]=$this->label.' is required.';
			}
			$fileCount=$uploadedItems!==[] ? count($uploadedItems) : count($files);
			$minFiles=(int)($this->meta['upload_min_files'] ?? $this->meta['min_files'] ?? 0);
			$maxFiles=(int)($this->meta['upload_max_files'] ?? $this->meta['max_files'] ?? 0);
			if($minFiles>0 && $fileCount<$minFiles){
				$errors[]=$this->label.' needs at least '.$minFiles.' file'.($minFiles===1 ? '' : 's').'.';
			}
			if($maxFiles>0 && $fileCount>$maxFiles){
				$errors[]=$this->label.' allows at most '.$maxFiles.' file'.($maxFiles===1 ? '' : 's').'.';
			}
			foreach($files as $file){
				$error=(int)($file['error'] ?? UPLOAD_ERR_OK);
				if($error!==UPLOAD_ERR_OK){
					$errors[]=$this->label.' upload failed: '.self::uploadedFileError($error).'.';
					continue;
				}
				$maxSize=(int)($this->meta['max_file_size'] ?? 0);
				if($maxSize>0 && (int)($file['size'] ?? 0)>$maxSize){
					$errors[]=$this->label.' must be no larger than '.self::format_bytes($maxSize).'.';
				}
				$accepted=is_array($this->meta['accepted_types'] ?? null) ? $this->meta['accepted_types'] : [];
				if($accepted!==[] && !self::fileAccepted($file, $accepted)){
					$errors[]=$this->label.' must be one of the accepted file types.';
				}
			}
			return array_values(array_unique($errors));
		}
		foreach($this->rules as $rule){
			[$name, $argument]=array_pad(explode(':', (string)$rule, 2), 2, null);
			$name=strtolower(trim($name));
			if($name===''){
				continue;
			}
			if($name==='required' && $required && self::blank($value)){
				$errors[]=$this->label.' is required.';
			}
			if(self::blank($value)){
				continue;
			}
			if($name==='email' && filter_var((string)$value, FILTER_VALIDATE_EMAIL)===false){
				$errors[]=$this->label.' must be a valid email address.';
			}
			if(in_array($name, ['number', 'numeric'], true) && !is_numeric($value)){
				$errors[]=$this->label.' must be numeric.';
			}
			if($name==='integer' && filter_var($value, FILTER_VALIDATE_INT)===false){
				$errors[]=$this->label.' must be an integer.';
			}
			if($name==='url' && filter_var(self::normalizeUrlText((string)$value), FILTER_VALIDATE_URL)===false){
				$errors[]=$this->label.' must be a valid URL.';
			}
			if($name==='min' && $argument!==null && self::sizeOf($value)<(float)$argument){
				$errors[]=$this->label.' must be at least '.$argument.'.';
			}
			if($name==='max' && $argument!==null && self::sizeOf($value)>(float)$argument){
				$errors[]=$this->label.' must be at most '.$argument.'.';
			}
			if($name==='in' && $argument!==null){
				$allowed=array_map('trim', explode(',', $argument));
				if(!in_array((string)$value, $allowed, true)){
					$errors[]=$this->label.' must be one of the allowed values.';
				}
			}
			if($name==='regex' && $argument!==null && !self::regexMatches((string)$argument, (string)$value)){
				$errors[]=$this->label.' has an invalid format.';
			}
			if($name==='confirmed'){
				$confirmation=$values[$this->name.'_confirmation'] ?? null;
				if((string)$value!==(string)$confirmation){
					$errors[]=$this->label.' confirmation does not match.';
				}
			}
			if($name==='same' && $argument!==null){
				$other=self::normalizeName($argument);
				if($other!=='' && (string)$value!==(string)($values[$other] ?? null)){
					$errors[]=$this->label.' must match '.self::humanize($other).'.';
				}
			}
			if($name==='different' && $argument!==null){
				$other=self::normalizeName($argument);
				if($other!=='' && array_key_exists($other, $values) && (string)$value===(string)$values[$other]){
					$errors[]=$this->label.' must be different from '.self::humanize($other).'.';
				}
			}
			if($name==='starts_with' && $argument!==null){
				$prefixes=self::normalizeRuleValues(explode(',', $argument));
				if($prefixes!==[] && !self::stringStartsWithAny((string)$value, $prefixes)){
					$errors[]=$this->label.' must start with '.implode(', ', $prefixes).'.';
				}
			}
			if($name==='ends_with' && $argument!==null){
				$suffixes=self::normalizeRuleValues(explode(',', $argument));
				if($suffixes!==[] && !self::stringEndsWithAny((string)$value, $suffixes)){
					$errors[]=$this->label.' must end with '.implode(', ', $suffixes).'.';
				}
			}
		}
		if(!self::blank($value) && in_array($this->type, ['number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage', 'slider'], true)){
			if(!is_numeric($value)){
				$errors[]=$this->label.' must be numeric.';
			}
			else {
				if($this->type==='integer' && filter_var($value, FILTER_VALIDATE_INT)===false){
					$errors[]=$this->label.' must be an integer.';
				}
				$number=(float)$value;
				if(array_key_exists('min', $this->meta) && is_numeric($this->meta['min']) && $number<(float)$this->meta['min']){
					$errors[]=$this->label.' must be at least '.$this->meta['min'].'.';
				}
				if(array_key_exists('max', $this->meta) && is_numeric($this->meta['max']) && $number>(float)$this->meta['max']){
					$errors[]=$this->label.' must be at most '.$this->meta['max'].'.';
				}
			}
		}
		if(!self::blank($value) && self::isLengthValidatedType($this->type) && !is_array($value) && !is_object($value)){
			$length=mb_strlen((string)$value);
			$exact=(int)($this->meta['exact_length'] ?? 0);
			if($exact>0 && $length!==$exact){
				$errors[]=$this->label.' must be exactly '.$exact.' character'.($exact===1 ? '' : 's').'.';
			}
			else {
				$min=(int)($this->meta['min_length'] ?? 0);
				$max=(int)($this->meta['max_length'] ?? 0);
				if($min>0 && $length<$min){
					$errors[]=$this->label.' must be at least '.$min.' character'.($min===1 ? '' : 's').'.';
				}
				if($max>0 && $length>$max){
					$errors[]=$this->label.' must be at most '.$max.' character'.($max===1 ? '' : 's').'.';
				}
			}
		}
		if(in_array($this->type, ['tags', 'tags_input'], true)){
			$tags=self::normalizeTagsValue($value, (string)($this->meta['tag_separator'] ?? ','));
			$count=count($tags);
			if(array_key_exists('min_tags', $this->meta) && $count<(int)$this->meta['min_tags']){
				$errors[]=$this->label.' must have at least '.$this->meta['min_tags'].' tag'.((int)$this->meta['min_tags']===1 ? '' : 's').'.';
			}
			if(array_key_exists('max_tags', $this->meta) && $count>(int)$this->meta['max_tags']){
				$errors[]=$this->label.' must have at most '.$this->meta['max_tags'].' tag'.((int)$this->meta['max_tags']===1 ? '' : 's').'.';
			}
		}
		if(in_array($this->type, ['key_value', 'keyvalue'], true)){
			$pairs=self::normalizeKeyValue($value, (string)($this->meta['pair_separator'] ?? "\n"), (string)($this->meta['key_separator'] ?? '='));
			$count=count($pairs);
			if(array_key_exists('min_pairs', $this->meta) && $count<(int)$this->meta['min_pairs']){
				$errors[]=$this->label.' must have at least '.$this->meta['min_pairs'].' pair'.((int)$this->meta['min_pairs']===1 ? '' : 's').'.';
			}
			if(array_key_exists('max_pairs', $this->meta) && $count>(int)$this->meta['max_pairs']){
				$errors[]=$this->label.' must have at most '.$this->meta['max_pairs'].' pair'.((int)$this->meta['max_pairs']===1 ? '' : 's').'.';
			}
		}
		if($required && !in_array('required', $ruleNames, true) && self::blank($value)){
			$errors[]=$this->label.' is required.';
		}
		if(!self::blank($value) && $this->optionValidationEnabled()){
			$options=$this->optionsFor($record, $request, $operation);
			$allowed=self::optionValues($options);
			$submitted=in_array($this->type, ['multi_select', 'multiselect', 'multi_relationship', 'belongs_to_many', 'checkbox_list'], true)
				? self::normalizeListValue($value)
				: [(string)$value];
			foreach($submitted as $submittedValue){
				if($options!==[] && !in_array((string)$submittedValue, $allowed, true)){
					$errors[]=$this->label.' must be one of the available options.';
					break;
				}
			}
		}
		if(!self::blank($value)){
			$formatError=$this->validateFormattedPattern($value, $values, $record, $request);
			if($formatError!==null){
				$errors[]=$formatError;
			}
		}
		return $errors;
	}

	/**
	 * Validates a scalar value against the field's effective formatting pattern and semantic validator.
	 *
	 * arrays and objects are ignored, contextual postal rules may reformat before validation, explicit
	 * international phone numbers use a looser digit pattern, and semantic validators return localized field errors.
	 *
	 * @return string|null Validation error message or null.
	 */
	private function validateFormattedPattern(mixed $value, array $values=[], mixed $record=null, ?PanelRequest $request=null): ?string {
		if(is_array($value) || is_object($value)){
			return null;
		}
		$rule=self::effectiveFormatRule($this->formatRuleName(), $this->formatContext($values, $record, $request));
		if($rule===''){
			return null;
		}
		$text=trim((string)$value);
		if($text===''){
			return null;
		}
		$context=$this->formatContext($values, $record, $request);
		$explicitInternationalPhone=$rule==='phone_international' && str_starts_with(ltrim($text), '+');
		$geopositionValid=self::geopositionPostalCodeValid($rule, $context, $text);
		if($geopositionValid===false){
			$placeholder=self::formatExpectedPlaceholder($rule, $context);
			return $placeholder!=='' ? $this->label.' must match '.$placeholder.'.' : $this->label.' has an invalid format.';
		}
		$text=self::geopositionReformatPostalCode($rule, $context, $text) ?? $text;
		$text=self::normalizeFormattedValidationText($rule, $text, $context);
		$pattern=$explicitInternationalPhone ? '[0-9]{7,15}' : self::normalizedFormatPattern($rule, $context);
		if($pattern!=='' && preg_match('/^'.$pattern.'$/', $text)!==1){
			$placeholder=self::formatExpectedPlaceholder($rule, $context);
			return $placeholder!=='' ? $this->label.' must match '.$placeholder.'.' : $this->label.' has an invalid format.';
		}
		$semanticError=self::formattedSemanticValidationError($rule, $text, $context);
		if($semanticError!==null){
			return $this->label.' '.$semanticError.'.';
		}
		return null;
	}

	/**
	 * Normalizes a formatted value into the canonical text used for pattern validation.
	 *
	 * @param string $rule Effective format rule.
	 * @param string $text Raw submitted text.
	 * @param array<string, mixed> $context Format context.
	 * @return string Validation-normalized text.
	 */
	private static function normalizeFormattedValidationText(string $rule, string $text, array $context): string {
		return match($rule){
			'phone_international'=>self::normalizeInternationalPhoneText($text, $context, false),
			'phone', 'phone_us', 'phone_ca', 'credit_card', 'card', 'credit_card_expiry', 'card_expiry', 'card_cvc', 'cvc', 'cvv', 'zip_code_us', 'postal_code_us', 'zip', 'postal_code_au', 'australian_postcode', 'postal_code_nz', 'new_zealand_postcode', 'postal_code_fr', 'french_postcode', 'postal_code_de', 'german_postcode'=>preg_replace('/\D+/', '', $text) ?? '',
			'postal_code_ca', 'canadian_postal_code', 'postal_code_gb', 'uk_postcode', 'postal_code_nl', 'dutch_postcode', 'postal_code_ie', 'eircode', 'postal_code_international', 'iban'=>strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $text) ?? ''),
			'email'=>strtolower(trim($text)),
			'url'=>self::normalizeUrlText($text),
			'map_url', 'maps_url'=>self::normalizeMapUrlText($text),
			'domain', 'hostname'=>self::normalizeDomainText($text),
			'timezone', 'time_zone'=>self::normalizeTimezoneText($text),
			'locale', 'language_tag'=>self::normalizeLocaleText($text),
			'json', 'json_text'=>self::normalizeJsonText($text),
			'mime_type', 'content_type'=>self::normalizeMimeTypeText($text),
			'semver', 'semantic_version'=>self::normalizeSemverText($text),
			'cron_expression', 'cron'=>self::normalizeCronExpressionText($text),
			'language_code', 'iso_language'=>self::normalizeLanguageCodeText($text),
			'country_code', 'iso_country'=>self::normalizeCountryCode($text),
			'subdivision_code', 'region_code'=>self::normalizeSubdivisionCode($text),
			'currency_code', 'iso_currency'=>self::normalizeCurrencyCodeText($text),
			'ip_address', 'ip', 'ipv4', 'ipv6'=>strtolower(trim($text)),
			'mac_address', 'mac'=>self::normalizeMacAddressText($text),
			'uuid'=>self::normalizeUuidText($text),
			'ulid'=>self::normalizeUlidText($text),
			'hex_color', 'color_hex'=>self::normalizeHexColorText($text),
			'latitude', 'longitude'=>self::normalizeCoordinateText($text, 10),
			'coordinates', 'lat_lng', 'lng_lat'=>self::normalizeCoordinatePairText($text, 10, $rule==='lng_lat'),
			default=>$text,
		};
	}

	/**
	 * Runs semantic validation for formats whose regex alone is insufficient.
	 *
	 * filter_var and domain-specific validators prove values such as URLs, domains, JSON, coordinates,
	 * card numbers, expiry dates, and IBANs after normalization has already occurred.
	 *
	 * @return string|null Error fragment without field label, or null when valid.
	 */
	private static function formattedSemanticValidationError(string $rule, string $text, array $context=[]): ?string {
		return match($rule){
			'email'=>filter_var($text, FILTER_VALIDATE_EMAIL)!==false ? null : 'must be a valid email address',
			'url'=>filter_var($text, FILTER_VALIDATE_URL)!==false ? null : 'must be a valid URL',
			'map_url', 'maps_url'=>self::validMapUrlText($text) ? null : 'must be a valid Google Maps URL',
			'domain', 'hostname'=>self::validDomainText($text) ? null : 'must be a valid domain',
			'timezone', 'time_zone'=>self::validTimezoneText($text) ? null : 'must be a valid timezone',
			'locale', 'language_tag'=>self::validLocaleText($text) ? null : 'must be a valid locale',
			'json', 'json_text'=>self::validJsonText($text) ? null : 'must be valid JSON',
			'mime_type', 'content_type'=>self::validMimeTypeText($text) ? null : 'must be a valid MIME type',
			'semver', 'semantic_version'=>self::validSemverText($text) ? null : 'must be a valid semantic version',
			'cron_expression', 'cron'=>self::validCronExpressionText($text) ? null : 'must be a valid cron expression',
			'language_code', 'iso_language'=>self::validLanguageCodeText($text) ? null : 'must be a valid ISO language code',
			'country_code', 'iso_country'=>self::validCountryCodeText($text) ? null : 'must be a valid ISO country code',
			'subdivision_code', 'region_code'=>self::validSubdivisionCodeText($text, (string)($context['country'] ?? '')) ? null : 'must be a valid subdivision code',
			'currency_code', 'iso_currency'=>self::validCurrencyCodeText($text) ? null : 'must be a valid ISO currency code',
			'ip_address', 'ip'=>filter_var($text, FILTER_VALIDATE_IP)!==false ? null : 'must be a valid IP address',
			'ipv4'=>filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)!==false ? null : 'must be a valid IPv4 address',
			'ipv6'=>filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)!==false ? null : 'must be a valid IPv6 address',
			'mac_address', 'mac'=>self::validMacAddressText($text) ? null : 'must be a valid MAC address',
			'uuid'=>self::validUuidText($text) ? null : 'must be a valid UUID',
			'ulid'=>self::validUlidText($text) ? null : 'must be a valid ULID',
			'hex_color', 'color_hex'=>self::validHexColorText($text) ? null : 'must be a valid hex color',
			'latitude'=>self::validCoordinateText($text, -90, 90) ? null : 'must be a valid latitude between -90 and 90',
			'longitude'=>self::validCoordinateText($text, -180, 180) ? null : 'must be a valid longitude between -180 and 180',
			'coordinates', 'lat_lng', 'lng_lat'=>self::validCoordinatePairText($text) ? null : 'must be valid coordinates in latitude,longitude order',
			'credit_card', 'card'=>self::validCreditCardNumber($text) ? null : 'must be a valid card number',
			'credit_card_expiry', 'card_expiry'=>self::validCreditCardExpiry($text) ? null : 'must be a valid future expiry date',
			'iban'=>self::validIban($text) ? null : 'must be a valid IBAN',
			default=>null,
		};
	}

	/**
	 * Validates normalized domain or IP host text.
	 *
	 * @param string $value Candidate domain, host, or IP.
	 * @return bool Whether the value is a valid domain or IP host.
	 */
	private static function validDomainText(string $value): bool {
		$value=self::normalizeDomainText($value);
		if($value==='' || strlen($value)>253 || str_contains($value, '..') || str_contains($value, '_')){
			return false;
		}
		if(filter_var($value, FILTER_VALIDATE_IP)!==false){
			return true;
		}
		return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value)===1;
	}

	/**
	 * Validates map URLs accepted by the panel map-url format.
	 *
	 * @param string $value Candidate URL or coordinate text.
	 * @return bool Whether the value resolves to an accepted Google Maps URL.
	 */
	private static function validMapUrlText(string $value): bool {
		$value=self::normalizeMapUrlText($value);
		if(filter_var($value, FILTER_VALIDATE_URL)===false){
			return false;
		}
		$host=strtolower((string)(parse_url($value, PHP_URL_HOST) ?: ''));
		$path=strtolower((string)(parse_url($value, PHP_URL_PATH) ?: ''));
		return preg_match('/(^|\.)google\.[a-z.]+$/', $host)===1 && str_starts_with($path, '/maps')
			|| in_array($host, ['maps.app.goo.gl', 'goo.gl'], true);
	}

	/**
	 * Validates timezone text against the canonical timezone map.
	 *
	 * @param string $value Candidate timezone.
	 * @return bool Whether the timezone is known to PHP.
	 */
	private static function validTimezoneText(string $value): bool {
		$value=self::normalizeTimezoneText($value);
		if($value===''){
			return false;
		}
		return isset(self::timezoneCanonicalMap()[strtolower($value)]);
	}

	/**
	 * Validates locale/language tag syntax after normalization.
	 *
	 * @param string $value Candidate locale tag.
	 * @return bool Whether the locale tag matches the supported shape.
	 */
	private static function validLocaleText(string $value): bool {
		$value=self::normalizeLocaleText($value);
		if($value===''){
			return false;
		}
		return preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-[0-9A-Za-z]{5,8})*$/', $value)===1;
	}

	/**
	 * Validates JSON text by decoding it with exceptions enabled.
	 *
	 * @param string $value Candidate JSON text.
	 * @return bool Whether the text is valid JSON.
	 */
	private static function validJsonText(string $value): bool {
		$value=trim($value);
		if($value===''){
			return false;
		}
		try{
			json_decode($value, true, 512, JSON_THROW_ON_ERROR);
			return true;
		}
		catch(\Throwable){
			return false;
		}
	}

	/**
	 * Validates MIME type text including optional parameters.
	 *
	 * @param string $value Candidate MIME type.
	 * @return bool Whether the value matches the renderer-supported MIME grammar.
	 */
	private static function validMimeTypeText(string $value): bool {
		$value=self::normalizeMimeTypeText($value);
		if($value===''){
			return false;
		}
		return preg_match('/^[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}(; [a-z0-9!#$&^_.+\-]+=("([^"\\\\]|\\\\.)*"|[a-z0-9!#$&^_.+\-]+))*$/', $value)===1;
	}

	/**
	 * Validates semantic version text after normalization.
	 *
	 * @param string $value Candidate semantic version.
	 * @return bool Whether the value follows semantic-version syntax.
	 */
	private static function validSemverText(string $value): bool {
		$value=self::normalizeSemverText($value);
		if($value===''){
			return false;
		}
		return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/', $value)===1;
	}

	/**
	 * Validates a five-field cron expression.
	 *
	 * each field is validated by range, list, range, step, and named month/day support. This does not
	 * execute or schedule the cron expression.
	 *
	 * @param string $value Candidate cron expression.
	 * @return bool Whether the expression uses supported cron syntax.
	 */
	private static function validCronExpressionText(string $value): bool {
		$value=self::normalizeCronExpressionText($value);
		$parts=explode(' ', $value);
		if(count($parts)!==5){
			return false;
		}
		return self::validCronField($parts[0], 0, 59)
			&& self::validCronField($parts[1], 0, 23)
			&& self::validCronField($parts[2], 1, 31)
			&& self::validCronField($parts[3], 1, 12, ['jan'=>1, 'feb'=>2, 'mar'=>3, 'apr'=>4, 'may'=>5, 'jun'=>6, 'jul'=>7, 'aug'=>8, 'sep'=>9, 'oct'=>10, 'nov'=>11, 'dec'=>12])
			&& self::validCronField($parts[4], 0, 7, ['sun'=>0, 'mon'=>1, 'tue'=>2, 'wed'=>3, 'thu'=>4, 'fri'=>5, 'sat'=>6]);
	}

	/**
	 * Validates one cron field against its allowed range.
	 *
	 *
	 * @param string $field Related field name used by validation or formatting metadata.
	 * @param int $min Lower bound used by validation or renderer metadata.
	 * @param int $max Upper bound used by validation or renderer metadata.
	 * @param array<int, string> $names Normalized field or operation names.
	 * @return bool.
	 */
	private static function validCronField(string $field, int $min, int $max, array $names=[]): bool {
		if($field===''){
			return false;
		}
		foreach(explode(',', $field) as $item){
			if(!self::validCronFieldItem($item, $min, $max, $names)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Validates one cron field item or range expression.
	 *
	 *
	 * @param string $item Repeater or list item identifier used by validation metadata.
	 * @param int $min Lower bound used by validation or renderer metadata.
	 * @param int $max Upper bound used by validation or renderer metadata.
	 * @param array<int, string> $names Normalized field or operation names.
	 * @return bool.
	 */
	private static function validCronFieldItem(string $item, int $min, int $max, array $names=[]): bool {
		if($item===''){
			return false;
		}
		[$base, $step]=array_pad(explode('/', $item, 2), 2, null);
		if($step!==null && (!preg_match('/^[1-9][0-9]*$/', $step) || (int)$step>$max)){
			return false;
		}
		if($base==='*'){
			return true;
		}
		if(str_contains($base, '-')){
			[$start, $end]=array_pad(explode('-', $base, 2), 2, '');
			$startValue=self::cronFieldValue($start, $names);
			$endValue=self::cronFieldValue($end, $names);
			return $startValue!==null && $endValue!==null && $startValue>=$min && $endValue<=$max && $startValue<=$endValue;
		}
		$value=self::cronFieldValue($base, $names);
		return $value!==null && $value>=$min && $value<=$max;
	}

	/**
	 * Validates one numeric cron field value.
	 *
	 *
	 * @param string $value Value stored in the field, resource, or manifest metadata.
	 * @param array<int, string> $names Normalized field or operation names.
	 * @return ?int.
	 */
	private static function cronFieldValue(string $value, array $names): ?int {
		$value=strtolower($value);
		if(isset($names[$value])){
			return $names[$value];
		}
		return preg_match('/^[0-9]+$/', $value)===1 ? (int)$value : null;
	}

	/**
	 * Validates an ISO language code after normalization.
	 *
	 * @param string $value Candidate language code.
	 * @return bool Whether the code is known.
	 */
	private static function validLanguageCodeText(string $value): bool {
		$value=self::normalizeLanguageCodeText($value);
		if($value===''){
			return false;
		}
		return in_array($value, self::knownLanguageCodes(), true);
	}

	/**
	 * Validates an ISO country code after normalization.
	 *
	 * @param string $value Candidate country code.
	 * @return bool Whether the code is known.
	 */
	private static function validCountryCodeText(string $value): bool {
		$value=self::normalizeCountryCode($value);
		if($value===''){
			return false;
		}
		return in_array($value, self::knownCountryCodes(), true);
	}

	/**
	 * Validates a subdivision code within an optional country context.
	 *
	 * @param string $value Candidate subdivision code.
	 * @param string $country Optional country code.
	 * @return bool Whether the subdivision code is known.
	 */
	private static function validSubdivisionCodeText(string $value, string $country=''): bool {
		$value=self::normalizeSubdivisionCode($value);
		if($value===''){
			return false;
		}
		$country=self::normalizeCountryCode($country);
		$codes=self::knownSubdivisionCodes($country);
		return in_array($value, $codes, true);
	}

	/**
	 * Validates an ISO currency code after normalization.
	 *
	 * @param string $value Candidate currency code.
	 * @return bool Whether the code is known.
	 */
	private static function validCurrencyCodeText(string $value): bool {
		$value=self::normalizeCurrencyCodeText($value);
		if($value===''){
			return false;
		}
		return in_array($value, self::knownCurrencyCodes(), true);
	}

	/** Validates a MAC address after normalization. */
	private static function validMacAddressText(string $value): bool {
		return preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', self::normalizeMacAddressText($value))===1;
	}

	/** Validates a versioned UUID after normalization. */
	private static function validUuidText(string $value): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', self::normalizeUuidText($value))===1;
	}

	/** Validates a ULID after normalization. */
	private static function validUlidText(string $value): bool {
		return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', self::normalizeUlidText($value))===1;
	}

	/** Validates a six-digit hex color after normalization. */
	private static function validHexColorText(string $value): bool {
		return preg_match('/^#[0-9a-f]{6}$/', self::normalizeHexColorText($value))===1;
	}

	/**
	 * Validates a numeric coordinate within a caller-provided range.
	 *
	 * @param string $value Candidate coordinate.
	 * @param float $min Minimum allowed value.
	 * @param float $max Maximum allowed value.
	 * @return bool Whether the coordinate is numeric and in range.
	 */
	private static function validCoordinateText(string $value, float $min, float $max): bool {
		$value=self::normalizeCoordinateText($value, 10);
		if($value==='' || !is_numeric($value)){
			return false;
		}
		$number=(float)$value;
		return $number>=$min && $number<=$max;
	}

	/**
	 * Validates latitude,longitude coordinate pair text.
	 *
	 * @param string $value Candidate pair.
	 * @return bool Whether the pair contains valid latitude and longitude values.
	 */
	private static function validCoordinatePairText(string $value): bool {
		$value=self::normalizeCoordinatePairText($value, 10);
		$parts=explode(',', $value);
		return count($parts)===2
			&& self::validCoordinateText($parts[0], -90, 90)
			&& self::validCoordinateText($parts[1], -180, 180);
	}

	/**
	 * Validates a credit card number with length, repetition, and Luhn checks.
	 *
	 * @param string $value Candidate card number.
	 * @return bool Whether the number passes supported card validation.
	 */
	private static function validCreditCardNumber(string $value): bool {
		$digits=preg_replace('/\D+/', '', $value) ?? '';
		if(!preg_match('/^[0-9]{12,19}$/', $digits) || preg_match('/^([0-9])\1+$/', $digits)){
			return false;
		}
		$sum=0;
		$double=false;
		for($index=strlen($digits)-1; $index>=0; $index--){
			$digit=(int)$digits[$index];
			if($double){
				$digit*=2;
				if($digit>9){
					$digit-=9;
				}
			}
			$sum+=$digit;
			$double=!$double;
		}
		return $sum%10===0;
	}

	/**
	 * Validates a credit card expiry value as a non-expired MMYY month.
	 *
	 * @param string $value Candidate expiry.
	 * @return bool Whether the expiry month is valid and not in the past.
	 */
	private static function validCreditCardExpiry(string $value): bool {
		$digits=preg_replace('/\D+/', '', $value) ?? '';
		if(!preg_match('/^[0-9]{4}$/', $digits)){
			return false;
		}
		$month=(int)substr($digits, 0, 2);
		if($month<1 || $month>12){
			return false;
		}
		$year=2000+(int)substr($digits, 2, 2);
		$currentYear=(int)date('Y');
		$currentMonth=(int)date('n');
		return $year>$currentYear || ($year===$currentYear && $month>=$currentMonth);
	}

	/**
	 * Validates an IBAN with structure and mod-97 checksum.
	 *
	 * @param string $value Candidate IBAN.
	 * @return bool Whether the IBAN passes checksum validation.
	 */
	private static function validIban(string $value): bool {
		$value=strtoupper(preg_replace('/[^0-9A-Z]+/', '', $value) ?? '');
		if(!preg_match('/^[A-Z]{2}[0-9]{2}[0-9A-Z]{11,30}$/', $value)){
			return false;
		}
		$rearranged=substr($value, 4).substr($value, 0, 4);
		$mod=0;
		$length=strlen($rearranged);
		for($index=0; $index<$length; $index++){
			$char=$rearranged[$index];
			if($char>='0' && $char<='9'){
				$mod=($mod*10+(int)$char)%97;
				continue;
			}
			if($char>='A' && $char<='Z'){
				foreach(str_split((string)(ord($char)-55)) as $digit){
					$mod=($mod*10+(int)$digit)%97;
				}
				continue;
			}
			return false;
		}
		return $mod===1;
	}

	/**
	 * Delegates postal-code validation to the geoposition module when available.
	 *
	 * unavailable geoposition support, missing country context, or geoposition exceptions return null so
	 * local pattern validation can continue without hard dependency failures.
	 *
	 * @return bool|null False when geoposition rejects the code, null when no definitive answer is available.
	 */
	private static function geopositionPostalCodeValid(string $rule, array $context, string $text): ?bool {
		if(!self::postalRule($rule) || !class_exists('\\dataphyre\\geoposition', false) || !method_exists('\\dataphyre\\geoposition', 'validate_postal_code')){
			return null;
		}
		$country=self::geopositionCountry($rule, $context);
		if($country===''){
			return null;
		}
		foreach(self::geopositionSubdivisions($context, $country) as $subdivision){
			try{
				if(\dataphyre\geoposition::validate_postal_code($country, $subdivision, $text)===false){
					return false;
				}
			}
			catch(\Throwable){
				return null;
			}
		}
		return null;
	}

	/**
	 * Delegates postal-code reformatting to the geoposition module when available.
	 *
	 * the first changed format wins, unchanged string formats are retained as fallback, and dependency
	 * absence or exceptions return null.
	 *
	 * @return string|null Reformatted postal code or null.
	 */
	private static function geopositionReformatPostalCode(string $rule, array $context, string $text): ?string {
		if(!self::postalRule($rule) || !class_exists('\\dataphyre\\geoposition', false) || !method_exists('\\dataphyre\\geoposition', 'reformat_postal_code')){
			return null;
		}
		$country=self::geopositionCountry($rule, $context);
		if($country===''){
			return null;
		}
		$result=null;
		foreach(self::geopositionSubdivisions($context, $country) as $subdivision){
			try{
				$formatted=\dataphyre\geoposition::reformat_postal_code($country, $subdivision, $text);
				if(is_string($formatted)){
					$result ??= $formatted;
					if($formatted!==$text){
						return $formatted;
					}
				}
			}
			catch(\Throwable){
				return null;
			}
		}
		return $result;
	}

	/**
	 * Reports whether a format rule represents a postal-code family.
	 *
	 * @param string $rule Format rule.
	 * @return bool Whether the rule is postal-code related.
	 */
	private static function postalRule(string $rule): bool {
		return in_array($rule, [
			'zip_code_us', 'postal_code_us', 'zip', 'postal_code_ca', 'canadian_postal_code', 'postal_code_gb', 'uk_postcode',
			'postal_code_au', 'australian_postcode', 'postal_code_nz', 'new_zealand_postcode', 'postal_code_fr', 'french_postcode',
			'postal_code_de', 'german_postcode', 'postal_code_nl', 'dutch_postcode', 'postal_code_ie', 'eircode', 'postal_code_international',
		], true);
	}

	/**
	 * Resolves the country used for postal-code geoposition validation.
	 *
	 * country-specific postal rules override context, while international/default rules use the
	 * normalized context country.
	 *
	 * @param string $rule Effective postal format rule.
	 * @param array<string, mixed> $context Format context.
	 * @return string Country code or empty string.
	 */
	private static function geopositionCountry(string $rule, array $context): string {
		$country=self::normalizeCountryCode((string)($context['country'] ?? ''));
		return match($rule){
			'zip_code_us', 'postal_code_us', 'zip'=>'US',
			'postal_code_ca', 'canadian_postal_code'=>'CA',
			'postal_code_gb', 'uk_postcode'=>'GB',
			'postal_code_au', 'australian_postcode'=>'AU',
			'postal_code_nz', 'new_zealand_postcode'=>'NZ',
			'postal_code_fr', 'french_postcode'=>'FR',
			'postal_code_de', 'german_postcode'=>'DE',
			'postal_code_nl', 'dutch_postcode'=>'NL',
			'postal_code_ie', 'eircode'=>'IE',
			default=>$country,
		};
	}

	/**
	 * Builds subdivision candidates for geoposition postal-code checks.
	 *
	 * normalized subdivision is tried as supplied and with country prefix when needed, then wildcard is
	 * appended so country-level rules can still run.
	 *
	 * @param array<string, mixed> $context Format context.
	 * @param string $country Country code.
	 * @return array<int, string> Subdivision candidates.
	 */
	private static function geopositionSubdivisions(array $context, string $country): array {
		$subdivision=self::normalizeSubdivisionCode((string)($context['subdivision'] ?? ''));
		$country=self::normalizeCountryCode($country);
		$subdivisions=[];
		if($subdivision!==''){
			$subdivisions[]=$subdivision;
			if($country!=='' && !str_contains($subdivision, '-')){
				$subdivisions[]=$country.'-'.$subdivision;
			}
		}
		$subdivisions[]='*';
		return array_values(array_unique($subdivisions));
	}

	/**
	 * Reads the normalized format rule configured on this field.
	 *
	 * @return string Format rule token.
	 */
	private function formatRuleName(): string {
		return self::normalizeName((string)($this->meta['format_rule'] ?? ''));
	}

	/**
	 * Builds country and subdivision context for dynamic format rules.
	 *
	 * explicit format options win, field references can resolve from submitted values or records, and
	 * returned country/subdivision values are normalized before use by postal and phone rules.
	 *
	 * @return array{country: string, subdivision: string} Format context.
	 */
	private function formatContext(array $values=[], mixed $record=null, ?PanelRequest $request=null): array {
		$options=is_array($this->meta['format_options'] ?? null) ? $this->meta['format_options'] : [];
		$country=(string)($options['country'] ?? $options['country_code'] ?? '');
		$subdivision=(string)($options['subdivision'] ?? $options['region'] ?? $options['state'] ?? $options['province'] ?? '');
		if($country==='' && isset($options['country_field'])){
			$country=(string)self::submittedOrRecordValue((string)$options['country_field'], $values, $record, $request);
		}
		if($subdivision==='' && isset($options['subdivision_field'])){
			$subdivision=(string)self::submittedOrRecordValue((string)$options['subdivision_field'], $values, $record, $request);
		}
		return [
			'country'=>self::normalizeCountryCode($country),
			'subdivision'=>self::normalizeSubdivisionCode($subdivision),
		];
	}

	/**
	 * Resolves dynamic format rule aliases using country and subdivision context.
	 *
	 * generic postal and phone rules become region-specific rules where possible, otherwise the
	 * normalized original rule is preserved.
	 *
	 * @param string $rule Requested format rule.
	 * @param array<string, mixed> $context Format context.
	 * @return string Effective format rule.
	 */
	private static function effectiveFormatRule(string $rule, array $context): string {
		$rule=self::normalizeName($rule);
		if(in_array($rule, ['postal_code', 'postal', 'zip_code_us', 'postal_code_us', 'zip'], true)){
			$subdivisionRule=self::postalRuleFromSubdivision((string)($context['subdivision'] ?? ''));
			return match($context['country'] ?? ''){
				'CA'=>'postal_code_ca',
				'GB'=>'postal_code_gb',
				'AU'=>'postal_code_au',
				'NZ'=>'postal_code_nz',
				'FR'=>'postal_code_fr',
				'DE'=>'postal_code_de',
				'NL'=>'postal_code_nl',
				'IE'=>'postal_code_ie',
				'EU'=>match($context['subdivision'] ?? ''){
					'FR'=>'postal_code_fr',
					'DE'=>'postal_code_de',
					'NL'=>'postal_code_nl',
					'IE'=>'postal_code_ie',
					default=>$subdivisionRule ?: 'postal_code_international',
				},
				'US'=>'zip_code_us',
				''=>$subdivisionRule ?: 'zip_code_us',
				default=>$subdivisionRule ?: 'postal_code_international',
			};
		}
		if($rule==='phone'){
			return 'phone_international';
		}
		if(in_array($rule, ['phone_us', 'phone_ca'], true) && !in_array($context['country'] ?? '', ['', 'US', 'CA'], true)){
			return 'phone_international';
		}
		return $rule;
	}

	/**
	 * Returns the regular-expression fragment used to validate a normalized format rule.
	 *
	 * returned strings are fragments without delimiters and are paired with semantic validators where
	 * regex alone cannot prove correctness.
	 *
	 * @return string Regex fragment or empty string.
	 */
	private static function normalizedFormatPattern(string $rule, array $context): string {
		return match($rule){
			'phone', 'phone_us', 'phone_ca'=>'1?[0-9]{10}',
			'phone_international'=>self::internationalPhoneCode($context)!=='' ? self::internationalPhoneCode($context).'[0-9]{4,12}' : '[0-9]{7,15}',
			'postal_code_ca', 'canadian_postal_code'=>self::canadianPostalPrefixPattern((string)($context['subdivision'] ?? '')).'[0-9][A-Z][0-9][A-Z][0-9]',
			'postal_code_gb', 'uk_postcode'=>'[A-Z]{1,2}[0-9][0-9A-Z]?[0-9][A-Z]{2}',
			'postal_code_au', 'australian_postcode'=>self::australianPostcodePrefixPattern((string)($context['subdivision'] ?? '')).'[0-9]{3}',
			'postal_code_nz', 'new_zealand_postcode'=>self::newZealandPostcodePrefixPattern((string)($context['subdivision'] ?? '')).'[0-9]{3}',
			'postal_code_fr', 'french_postcode', 'postal_code_de', 'german_postcode'=>'[0-9]{5}',
			'postal_code_nl', 'dutch_postcode'=>'[0-9]{4}[A-Z]{2}',
			'postal_code_ie', 'eircode'=>'[A-Z0-9]{7}',
			'zip_code_us', 'postal_code_us', 'zip'=>self::usZipPrefixPattern((string)($context['subdivision'] ?? '')).'[0-9]{2}([0-9]{4})?',
			'postal_code_international'=>'[0-9A-Z][0-9A-Z]{2,16}',
			'credit_card', 'card'=>'[0-9]{12,19}',
			'credit_card_expiry', 'card_expiry'=>'(0[1-9]|1[0-2])[0-9]{2}',
			'card_cvc', 'cvc', 'cvv'=>'[0-9]{3,4}',
			'iban'=>'[A-Z]{2}[0-9]{2}[0-9A-Z]{11,30}',
			'domain', 'hostname'=>'[A-Za-z0-9.-]{3,253}',
			'timezone', 'time_zone'=>'(UTC|GMT|[A-Za-z_]+\/[A-Za-z0-9_+\-]+(\/[A-Za-z0-9_+\-]+)?)',
			'locale', 'language_tag'=>'[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-[0-9A-Za-z]{5,8})*',
			'mime_type', 'content_type'=>'[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}(; .+)?',
			'semver', 'semantic_version'=>'v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?',
			'cron_expression', 'cron'=>'[0-9A-Za-z*\/,\-]+ [0-9A-Za-z*\/,\-]+ [0-9A-Za-z*\/,\-]+ [0-9A-Za-z*\/,\-]+ [0-9A-Za-z*\/,\-]+',
			'language_code', 'iso_language'=>'[a-z]{2}',
			'country_code', 'iso_country'=>'[A-Z]{2}',
			'subdivision_code', 'region_code'=>'[A-Z]{2,3}',
			'currency_code', 'iso_currency'=>'[A-Z]{3}',
			'ip_address', 'ip'=>'[0-9A-Fa-f:.]{3,45}',
			'ipv4'=>'[0-9.]{7,15}',
			'ipv6'=>'[0-9A-Fa-f:.]{2,45}',
			'mac_address', 'mac'=>'[0-9A-F]{2}(:[0-9A-F]{2}){5}',
			'uuid'=>'[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}',
			'ulid'=>'[0-7][0-9A-HJKMNP-TV-Z]{25}',
			'hex_color', 'color_hex'=>'#[0-9a-f]{6}',
			'latitude', 'longitude'=>'-?[0-9]{1,3}(\.[0-9]+)?',
			'coordinates', 'lat_lng', 'lng_lat'=>'-?[0-9]{1,3}(\.[0-9]+)?,-?[0-9]{1,3}(\.[0-9]+)?',
			default=>'',
		};
	}

	/**
	 * Returns an example placeholder for a format rule and context.
	 *
	 * @param string $rule Effective format rule.
	 * @param array<string, mixed> $context Format context.
	 * @return string Placeholder example or empty string.
	 */
	private static function formatExpectedPlaceholder(string $rule, array $context): string {
		return match($rule){
			'phone', 'phone_us', 'phone_ca'=>'(000) 000-0000',
			'phone_international'=>self::internationalPhoneCode($context)!=='' ? '+'.self::internationalPhoneCode($context).' 0000 0000' : '+1 000 000 0000',
			'postal_code_ca', 'canadian_postal_code'=>self::canadianPostalPlaceholder((string)($context['subdivision'] ?? '')),
			'postal_code_gb', 'uk_postcode'=>'SW1A 1AA',
			'postal_code_au', 'australian_postcode'=>self::australianPostcodePlaceholder((string)($context['subdivision'] ?? '')),
			'postal_code_nz', 'new_zealand_postcode'=>self::newZealandPostcodePlaceholder((string)($context['subdivision'] ?? '')),
			'postal_code_fr', 'french_postcode'=>'75001',
			'postal_code_de', 'german_postcode'=>'10115',
			'postal_code_nl', 'dutch_postcode'=>'1012 AB',
			'postal_code_ie', 'eircode'=>'D02 X285',
			'zip_code_us', 'postal_code_us', 'zip'=>self::usZipPlaceholder((string)($context['subdivision'] ?? '')),
			'postal_code_international'=>'Postal code',
			'credit_card', 'card'=>'0000 0000 0000 0000',
			'credit_card_expiry', 'card_expiry'=>'MM/YY',
			'card_cvc', 'cvc', 'cvv'=>'000',
			'iban'=>'GB82 WEST 1234 5698 7654 32',
			'domain', 'hostname'=>'example.com',
			'map_url', 'maps_url'=>'https://www.google.com/maps?q=45.501689,-73.567256',
			'timezone', 'time_zone'=>'America/Toronto',
			'locale', 'language_tag'=>'en-CA',
			'json', 'json_text'=>'{"key":"value"}',
			'mime_type', 'content_type'=>'application/json',
			'semver', 'semantic_version'=>'1.2.3',
			'cron_expression', 'cron'=>'0 9 * * mon-fri',
			'language_code', 'iso_language'=>'en',
			'country_code', 'iso_country'=>'CA',
			'subdivision_code', 'region_code'=>($context['country'] ?? '')==='US' ? 'NY' : 'QC',
			'currency_code', 'iso_currency'=>'CAD',
			'ip_address', 'ip'=>'192.0.2.10',
			'ipv4'=>'192.0.2.10',
			'ipv6'=>'2001:db8::1',
			'mac_address', 'mac'=>'00:1A:2B:3C:4D:5E',
			'uuid'=>'550e8400-e29b-41d4-a716-446655440000',
			'ulid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV',
			'hex_color', 'color_hex'=>'#3366cc',
			'latitude'=>'45.501689',
			'longitude'=>'-73.567256',
			'coordinates', 'lat_lng', 'lng_lat'=>'45.501689,-73.567256',
			default=>'',
		};
	}

	/**
	 * Converts decimal precision into an HTML number input step value.
	 *
	 * @param int $decimals Requested decimal precision.
	 * @return string Step attribute value.
	 */
	private static function decimalStep(int $decimals): string {
		$decimals=max(0, min(10, $decimals));
		return $decimals===0 ? '1' : '0.'.str_repeat('0', $decimals-1).'1';
	}

	/**
	 * Normalizes country names and aliases to two-letter country codes.
	 *
	 * @param string $value Raw country code or name.
	 * @return string Normalized country code.
	 */
	private static function normalizeCountryCode(string $value): string {
		$value=strtoupper(trim($value));
		return match($value){
			'CA', 'CAN'=>'CA',
			'US', 'USA'=>'US',
			'GB', 'GBR'=>'GB',
			'AU', 'AUS'=>'AU',
			'NZ', 'NZL'=>'NZ',
			'FR', 'FRA'=>'FR',
			'DE', 'DEU', 'GER'=>'DE',
			'NL', 'NLD'=>'NL',
			'IE', 'IRL'=>'IE',
			'CANADA'=>'CA',
			'UNITED STATES', 'UNITED STATES OF AMERICA', 'USA'=>'US',
			'UNITED KINGDOM', 'GREAT BRITAIN', 'BRITAIN', 'UK'=>'GB',
			'AUSTRALIA'=>'AU',
			'NEW ZEALAND', 'AOTEAROA'=>'NZ',
			'FRANCE'=>'FR', 'GERMANY'=>'DE', 'NETHERLANDS'=>'NL', 'IRELAND'=>'IE',
			'EUROPEAN UNION'=>'EU',
			default=>$value,
		};
	}

	/**
	 * Returns the built-in country-code allowlist.
	 *
	 * @return array.
	 */
	private static function knownCountryCodes(): array {
		return [
			'AD','AE','AF','AG','AI','AL','AM','AO','AQ','AR','AS','AT','AU','AW','AX','AZ','BA','BB','BD','BE','BF','BG','BH','BI','BJ','BL','BM','BN','BO','BQ','BR','BS','BT','BV','BW','BY','BZ',
			'CA','CC','CD','CF','CG','CH','CI','CK','CL','CM','CN','CO','CR','CU','CV','CW','CX','CY','CZ','DE','DJ','DK','DM','DO','DZ','EC','EE','EG','EH','ER','ES','ET','FI','FJ','FK','FM','FO','FR',
			'GA','GB','GD','GE','GF','GG','GH','GI','GL','GM','GN','GP','GQ','GR','GS','GT','GU','GW','GY','HK','HM','HN','HR','HT','HU','ID','IE','IL','IM','IN','IO','IQ','IR','IS','IT','JE','JM','JO',
			'JP','KE','KG','KH','KI','KM','KN','KP','KR','KW','KY','KZ','LA','LB','LC','LI','LK','LR','LS','LT','LU','LV','LY','MA','MC','MD','ME','MF','MG','MH','MK','ML','MM','MN','MO','MP','MQ','MR',
			'MS','MT','MU','MV','MW','MX','MY','MZ','NA','NC','NE','NF','NG','NI','NL','NO','NP','NR','NU','NZ','OM','PA','PE','PF','PG','PH','PK','PL','PM','PN','PR','PS','PT','PW','PY','QA','RE','RO',
			'RS','RU','RW','SA','SB','SC','SD','SE','SG','SH','SI','SJ','SK','SL','SM','SN','SO','SR','SS','ST','SV','SX','SY','SZ','TC','TD','TF','TG','TH','TJ','TK','TL','TM','TN','TO','TR','TT','TV',
			'TW','TZ','UA','UG','UM','US','UY','UZ','VA','VC','VE','VG','VI','VN','VU','WF','WS','YE','YT','ZA','ZM','ZW',
		];
	}

	/**
	 * Normalizes country option definitions into code-to-label choices.
	 *
	 * @param array<string|int, mixed> $countries Country option source.
	 * @return array<string, string> Country options.
	 */
	private static function countryOptions(array $countries): array {
		$options=[];
		foreach($countries as $key=>$value){
			$code=self::normalizeCountryCode(is_string($key) && !is_numeric($key) ? $key : (string)$value);
			if($code===''){
				continue;
			}
			$options[$code]=is_string($key) && !is_numeric($key) ? (string)$value : self::countryLabel($code);
		}
		return $options;
	}

	/**
	 * Returns a display label for a normalized country code.
	 *
	 * @param string $code Country code.
	 * @return string Country label.
	 */
	private static function countryLabel(string $code): string {
		$code=self::normalizeCountryCode($code);
		return [
			'CA'=>'Canada',
			'US'=>'United States',
			'GB'=>'United Kingdom',
			'AU'=>'Australia',
			'NZ'=>'New Zealand',
			'FR'=>'France',
			'DE'=>'Germany',
			'NL'=>'Netherlands',
			'IE'=>'Ireland',
			'EU'=>'European Union',
		][$code] ?? $code;
	}

	/**
	 * Returns known subdivision codes for a country.
	 *
	 *
	 * @param string $country Country code used by locale-aware formatting.
	 * @return array.
	 */
	private static function knownSubdivisionCodes(string $country=''): array {
		$country=self::normalizeCountryCode($country);
		$map=[
			'CA'=>['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'],
			'US'=>['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC'],
			'AU'=>['ACT','NSW','NT','QLD','SA','TAS','VIC','WA'],
			'NZ'=>['AUK','WGN','CAN','OTA'],
			'EU'=>['FR','DE','NL','IE'],
		];
		if(isset($map[$country])){
			return $map[$country];
		}
		$codes=[];
		foreach($map as $items){
			$codes=array_merge($codes, $items);
		}
		return array_values(array_unique($codes));
	}

	/**
	 * Builds subdivision options for a country context.
	 *
	 * @param string $country Country code.
	 * @return array<string, string> Subdivision options.
	 */
	private static function subdivisionOptions(string $country=''): array {
		$country=self::normalizeCountryCode($country);
		$options=[];
		foreach(self::knownSubdivisionCodes($country) as $code){
			$options[$code]=self::subdivisionLabel($code, $country);
		}
		return $options;
	}

	/**
	 * Returns a subdivision display label for a country context.
	 *
	 * @param string $code Subdivision code.
	 * @param string $country Country code.
	 * @return string Subdivision label.
	 */
	private static function subdivisionLabel(string $code, string $country=''): string {
		$code=self::normalizeSubdivisionCode($code);
		$country=self::normalizeCountryCode($country);
		$labels=[
			'CA'=>[
				'AB'=>'Alberta', 'BC'=>'British Columbia', 'MB'=>'Manitoba', 'NB'=>'New Brunswick', 'NL'=>'Newfoundland and Labrador', 'NS'=>'Nova Scotia', 'NT'=>'Northwest Territories', 'NU'=>'Nunavut', 'ON'=>'Ontario', 'PE'=>'Prince Edward Island', 'QC'=>'Quebec', 'SK'=>'Saskatchewan', 'YT'=>'Yukon',
			],
			'US'=>[
				'AL'=>'Alabama', 'AK'=>'Alaska', 'AZ'=>'Arizona', 'AR'=>'Arkansas', 'CA'=>'California', 'CO'=>'Colorado', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia', 'HI'=>'Hawaii', 'ID'=>'Idaho', 'IL'=>'Illinois', 'IN'=>'Indiana', 'IA'=>'Iowa', 'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'ME'=>'Maine', 'MD'=>'Maryland', 'MA'=>'Massachusetts', 'MI'=>'Michigan', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri', 'MT'=>'Montana', 'NE'=>'Nebraska', 'NV'=>'Nevada', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey', 'NM'=>'New Mexico', 'NY'=>'New York', 'NC'=>'North Carolina', 'ND'=>'North Dakota', 'OH'=>'Ohio', 'OK'=>'Oklahoma', 'OR'=>'Oregon', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina', 'SD'=>'South Dakota', 'TN'=>'Tennessee', 'TX'=>'Texas', 'UT'=>'Utah', 'VT'=>'Vermont', 'VA'=>'Virginia', 'WA'=>'Washington', 'WV'=>'West Virginia', 'WI'=>'Wisconsin', 'WY'=>'Wyoming', 'DC'=>'District of Columbia',
			],
			'AU'=>['ACT'=>'Australian Capital Territory', 'NSW'=>'New South Wales', 'NT'=>'Northern Territory', 'QLD'=>'Queensland', 'SA'=>'South Australia', 'TAS'=>'Tasmania', 'VIC'=>'Victoria', 'WA'=>'Western Australia'],
			'NZ'=>['AUK'=>'Auckland', 'WGN'=>'Wellington', 'CAN'=>'Canterbury', 'OTA'=>'Otago'],
			'EU'=>['FR'=>'France', 'DE'=>'Germany', 'NL'=>'Netherlands', 'IE'=>'Ireland'],
		];
		return $labels[$country][$code] ?? $labels['EU'][$code] ?? $code;
	}

	/**
	 * Returns the built-in currency-code allowlist.
	 *
	 * @return array.
	 */
	private static function knownCurrencyCodes(): array {
		return [
			'AED','AFN','ALL','AMD','ANG','AOA','ARS','AUD','AWG','AZN','BAM','BBD','BDT','BGN','BHD','BIF','BMD','BND','BOB','BOV','BRL','BSD','BTN','BWP','BYN','BZD','CAD','CDF','CHE','CHF','CHW',
			'CLF','CLP','CNY','COP','COU','CRC','CUC','CUP','CVE','CZK','DJF','DKK','DOP','DZD','EGP','ERN','ETB','EUR','FJD','FKP','GBP','GEL','GHS','GIP','GMD','GNF','GTQ','GYD','HKD','HNL','HTG',
			'HUF','IDR','ILS','INR','IQD','IRR','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KPW','KRW','KWD','KYD','KZT','LAK','LBP','LKR','LRD','LSL','LYD','MAD','MDL','MGA','MKD','MMK','MNT',
			'MOP','MRU','MUR','MVR','MWK','MXN','MXV','MYR','MZN','NAD','NGN','NIO','NOK','NPR','NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR','RON','RSD','RUB','RWF','SAR','SBD','SCR',
			'SDG','SEK','SGD','SHP','SLE','SLL','SOS','SRD','SSP','STN','SVC','SYP','SZL','THB','TJS','TMT','TND','TOP','TRY','TTD','TWD','TZS','UAH','UGX','USD','USN','UYI','UYU','UYW','UZS','VED',
			'VES','VND','VUV','WST','XAF','XAG','XAU','XBA','XBB','XBC','XBD','XCD','XDR','XOF','XPD','XPF','XPT','XSU','XTS','XUA','XXX','YER','ZAR','ZMW','ZWL',
		];
	}

	/**
	 * Returns the built-in language-code allowlist.
	 *
	 * @return array.
	 */
	private static function knownLanguageCodes(): array {
		return [
			'aa','ab','ae','af','ak','am','an','ar','as','av','ay','az','ba','be','bg','bh','bi','bm','bn','bo','br','bs','ca','ce','ch','co','cr','cs','cu','cv','cy','da','de','dv','dz','ee','el','en',
			'eo','es','et','eu','fa','ff','fi','fj','fo','fr','fy','ga','gd','gl','gn','gu','gv','ha','he','hi','ho','hr','ht','hu','hy','hz','ia','id','ie','ig','ii','ik','io','is','it','iu','ja','jv',
			'ka','kg','ki','kj','kk','kl','km','kn','ko','kr','ks','ku','kv','kw','ky','la','lb','lg','li','ln','lo','lt','lu','lv','mg','mh','mi','mk','ml','mn','mr','ms','mt','my','na','nb','nd','ne',
			'ng','nl','nn','no','nr','nv','ny','oc','oj','om','or','os','pa','pi','pl','ps','pt','qu','rm','rn','ro','ru','rw','sa','sc','sd','se','sg','si','sk','sl','sm','sn','so','sq','sr','ss','st',
			'su','sv','sw','ta','te','tg','th','ti','tk','tl','tn','to','tr','ts','tt','tw','ty','ug','uk','ur','uz','ve','vi','vo','wa','wo','xh','yi','yo','za','zh','zu',
		];
	}

	/**
	 * Normalizes known subdivision names and aliases to subdivision codes.
	 *
	 * @param string $value Raw subdivision code or name.
	 * @return string Normalized subdivision code.
	 */
	private static function normalizeSubdivisionCode(string $value): string {
		$value=strtoupper(trim($value));
		return [
			'ALBERTA'=>'AB', 'BRITISH COLUMBIA'=>'BC', 'MANITOBA'=>'MB', 'NEW BRUNSWICK'=>'NB', 'NEWFOUNDLAND'=>'NL', 'NEWFOUNDLAND AND LABRADOR'=>'NL', 'NOVA SCOTIA'=>'NS', 'NORTHWEST TERRITORIES'=>'NT', 'NUNAVUT'=>'NU', 'ONTARIO'=>'ON', 'PRINCE EDWARD ISLAND'=>'PE', 'QUEBEC'=>'QC', 'QUÉBEC'=>'QC', 'SASKATCHEWAN'=>'SK', 'YUKON'=>'YT',
			'NEW YORK'=>'NY', 'CALIFORNIA'=>'CA', 'TEXAS'=>'TX', 'WASHINGTON'=>'WA',
			'FRANCE'=>'FR', 'GERMANY'=>'DE', 'NETHERLANDS'=>'NL', 'IRELAND'=>'IE',
			'NEW SOUTH WALES'=>'NSW', 'VICTORIA'=>'VIC', 'QUEENSLAND'=>'QLD', 'SOUTH AUSTRALIA'=>'SA', 'WESTERN AUSTRALIA'=>'WA', 'TASMANIA'=>'TAS', 'AUSTRALIAN CAPITAL TERRITORY'=>'ACT', 'NORTHERN TERRITORY'=>'NT',
			'AUCKLAND'=>'AUK', 'WELLINGTON'=>'WGN', 'CANTERBURY'=>'CAN', 'OTAGO'=>'OTA',
		][$value] ?? $value;
	}

	/**
	 * Infers a postal-code format rule from a known subdivision code.
	 *
	 * @param string $subdivision Subdivision code.
	 * @return string Postal format rule or empty string.
	 */
	private static function postalRuleFromSubdivision(string $subdivision): string {
		$subdivision=self::normalizeSubdivisionCode($subdivision);
		if($subdivision===''){
			return '';
		}
		if(in_array($subdivision, ['AB','BC','MB','NB','NS','NU','ON','PE','QC','SK','YT'], true)){
			return 'postal_code_ca';
		}
		if(in_array($subdivision, ['AL','AK','AZ','AR','CA','CO','CT','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WV','WI','WY','DC'], true)){
			return 'zip_code_us';
		}
		if(in_array($subdivision, ['ACT','NSW','QLD','SA','TAS','VIC'], true)){
			return 'postal_code_au';
		}
		if(in_array($subdivision, ['AUK','WGN','CAN','OTA'], true)){
			return 'postal_code_nz';
		}
		return match($subdivision){
			'FR'=>'postal_code_fr',
			'IE'=>'postal_code_ie',
			default=>'',
		};
	}

	/**
	 * Returns the Canadian postal-code leading-letter pattern for a province.
	 *
	 * @param string $subdivision ISO-like province or territory code.
	 * @return string Regex fragment for the first postal-code character.
	 */
	private static function canadianPostalPrefixPattern(string $subdivision): string {
		return [
			'NL'=>'A', 'NS'=>'B', 'PE'=>'C', 'NB'=>'E', 'QC'=>'[GHJ]', 'ON'=>'[KLMNP]', 'MB'=>'R', 'SK'=>'S', 'AB'=>'T', 'BC'=>'V', 'NT'=>'X', 'NU'=>'X', 'YT'=>'Y',
		][self::normalizeSubdivisionCode($subdivision)] ?? '[ABCEGHJKLMNPRSTVXY]';
	}

	/**
	 * Returns a province-aware Canadian postal-code placeholder.
	 *
	 * @param string $subdivision ISO-like province or territory code.
	 * @return string Example postal code shaped for the requested subdivision.
	 */
	private static function canadianPostalPlaceholder(string $subdivision): string {
		$prefix=[
			'NL'=>'A', 'NS'=>'B', 'PE'=>'C', 'NB'=>'E', 'QC'=>'H', 'ON'=>'K', 'MB'=>'R', 'SK'=>'S', 'AB'=>'T', 'BC'=>'V', 'NT'=>'X', 'NU'=>'X', 'YT'=>'Y',
		][self::normalizeSubdivisionCode($subdivision)] ?? 'A';
		return $prefix.'0A 0A0';
	}

	/**
	 * Returns a US ZIP prefix pattern when a known state has a narrow range.
	 *
	 * @param string $subdivision State or district code.
	 * @return string Regex fragment for the first ZIP digits.
	 */
	private static function usZipPrefixPattern(string $subdivision): string {
		return [
			'NY'=>'(00[5-9]|0[1-9][0-9]|1[0-4][0-9])',
			'CA'=>'(9[0-5][0-9]|96[0-1])',
			'TX'=>'(733|7[5-9][0-9]|885)',
			'WA'=>'(98[0-9]|99[0-4])',
		][self::normalizeSubdivisionCode($subdivision)] ?? '[0-9]{3}';
	}

	/**
	 * Returns a state-aware ZIP+4 placeholder for US postal fields.
	 *
	 * @param string $subdivision State or district code.
	 * @return string Example ZIP+4 value.
	 */
	private static function usZipPlaceholder(string $subdivision): string {
		return [
			'NY'=>'10000-0000', 'CA'=>'90000-0000', 'TX'=>'75000-0000', 'WA'=>'98000-0000',
		][self::normalizeSubdivisionCode($subdivision)] ?? '00000-0000';
	}

	/**
	 * Returns an Australian postcode prefix pattern for a state or territory.
	 *
	 * @param string $subdivision State or territory code.
	 * @return string Regex fragment for the first postcode digit.
	 */
	private static function australianPostcodePrefixPattern(string $subdivision): string {
		return [
			'NSW'=>'[12]', 'ACT'=>'2', 'VIC'=>'[38]', 'QLD'=>'4', 'SA'=>'5', 'WA'=>'6', 'TAS'=>'7', 'NT'=>'0',
		][self::normalizeSubdivisionCode($subdivision)] ?? '[0-9]';
	}

	/**
	 * Returns a state-aware Australian postcode placeholder.
	 *
	 * @param string $subdivision State or territory code.
	 * @return string Example postcode.
	 */
	private static function australianPostcodePlaceholder(string $subdivision): string {
		return [
			'NSW'=>'2000', 'ACT'=>'2600', 'VIC'=>'3000', 'QLD'=>'4000', 'SA'=>'5000', 'WA'=>'6000', 'TAS'=>'7000', 'NT'=>'0800',
		][self::normalizeSubdivisionCode($subdivision)] ?? '0000';
	}

	/**
	 * Returns a New Zealand postcode prefix pattern for a coarse region code.
	 *
	 * @param string $subdivision Region code used by Dataphyre's lightweight geo map.
	 * @return string Regex fragment for the first postcode digit.
	 */
	private static function newZealandPostcodePrefixPattern(string $subdivision): string {
		return [
			'AUK'=>'[01]', 'WGN'=>'[56]', 'CAN'=>'[78]', 'OTA'=>'9',
		][self::normalizeSubdivisionCode($subdivision)] ?? '[0-9]';
	}

	/**
	 * Returns a New Zealand postcode placeholder for a coarse region code.
	 *
	 * @param string $subdivision Region code used by Dataphyre's lightweight geo map.
	 * @return string Example postcode.
	 */
	private static function newZealandPostcodePlaceholder(string $subdivision): string {
		return [
			'AUK'=>'1010', 'WGN'=>'6011', 'CAN'=>'8011', 'OTA'=>'9016',
		][self::normalizeSubdivisionCode($subdivision)] ?? '0000';
	}

	/**
	 * Resolves a best-effort international dialing code from formatting context.
	 *
	 * The lookup intentionally covers the countries and subdivision fallbacks that
	 * Panel can validate locally; unknown context returns an empty string so UI
	 * masking can remain permissive instead of manufacturing a country assumption.
	 *
	 * @param array<string, mixed> $context Formatting context containing optional `country` and `subdivision`.
	 * @return string Numeric country calling code without the leading plus sign.
	 */
	private static function internationalPhoneCode(array $context): string {
		$country=self::normalizeCountryCode((string)($context['country'] ?? ''));
		$countryCodes=['US'=>'1', 'CA'=>'1', 'GB'=>'44', 'AU'=>'61', 'NZ'=>'64', 'FR'=>'33', 'DE'=>'49', 'NL'=>'31', 'IE'=>'353'];
		if(isset($countryCodes[$country])){
			return $countryCodes[$country];
		}
		return [
			'FR'=>'33', 'DE'=>'49', 'NL'=>'31', 'IE'=>'353',
		][self::normalizeSubdivisionCode((string)($context['subdivision'] ?? ''))] ?? '';
	}

	/**
	 * Evaluates conditional required rules against submitted values and record state.
	 *
	 * `required_when` and `required_unless` are evaluated with the same dependency
	 * resolution path used by visibility, keeping validation lifecycle behavior
	 * consistent between client hints, server validation, and dehydration.
	 *
	 * @param array<string, mixed> $values Submitted form values keyed by normalized field name.
	 * @param mixed $record Current record or row context.
	 * @param PanelRequest|null $request Active Panel request when dependency callbacks need it.
	 * @return bool Whether this field should be treated as required for the context.
	 */
	private function requiredByConditions(array $values=[], mixed $record=null, ?PanelRequest $request=null): bool {
		foreach($this->requiredWhen as $field=>$expected){
			if(self::conditionMatches(self::submittedOrRecordValue($field, $values, $record, $request), $expected)){
				return true;
			}
		}
		foreach($this->requiredUnless as $field=>$expected){
			if(!self::conditionMatches(self::submittedOrRecordValue($field, $values, $record, $request), $expected)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a dependency value from submitted input before falling back to the record.
	 *
	 * @param string $field Field name or dependency path.
	 * @param array<string, mixed> $values Submitted form values keyed by normalized field name.
	 * @param mixed $record Current record or row context.
	 * @param PanelRequest|null $request Active Panel request for request-backed dependencies.
	 * @return mixed Submitted value, record value, request value, or null when unresolved.
	 */
	private static function submittedOrRecordValue(string $field, array $values=[], mixed $record=null, ?PanelRequest $request=null): mixed {
		$field=self::normalizeName($field);
		if(array_key_exists($field, $values)){
			return $values[$field];
		}
		return self::dependencyValue($field, $record, $request);
	}

	/**
	 * Determines whether the field's submitted value must be checked against options.
	 *
	 * @return bool Whether option membership validation applies to this field type.
	 */
	private function optionValidationEnabled(): bool {
		return in_array($this->type, ['select', 'enum', 'relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many', 'radio', 'multi_select', 'multiselect', 'checkbox_list'], true) || $this->options!==[] || $this->optionsCallback!==null;
	}

	/**
	 * Extracts normalized child field definitions for a repeater field.
	 *
	 * @param array<string, mixed> $meta Field metadata containing a `repeater_fields` list.
	 * @return array<int,self> Field definitions with non-empty normalized names.
	 */
	private static function repeaterFieldDefinitions(array $meta): array {
		$fields=[];
		foreach(is_array($meta['repeater_fields'] ?? null) ? $meta['repeater_fields'] : [] as $field){
			$field=$field instanceof self ? $field : (is_array($field) ? self::fromArray($field) : self::make((string)$field));
			if($field->name()!==''){
				$fields[]=$field;
			}
		}
		return $fields;
	}

	/**
	 * Extracts normalized child field definitions for grouped structural fields.
	 *
	 * @param array<string, mixed> $meta Field metadata containing a `child_fields` list.
	 * @return array<int,self> Field definitions with non-empty normalized names.
	 */
	private static function childFieldDefinitions(array $meta): array {
		$fields=[];
		foreach(is_array($meta['child_fields'] ?? null) ? $meta['child_fields'] : [] as $field){
			$field=$field instanceof self ? $field : (is_array($field) ? self::fromArray($field) : self::make((string)$field));
			if($field->name()!==''){
				$fields[]=$field;
			}
		}
		return $fields;
	}

	/**
	 * Extracts normalized builder block definitions and their nested field schemas.
	 *
	 * @param array<string, mixed> $meta Field metadata containing `builder_blocks` keyed by block name.
	 * @return array<string,array{name:string,label:string,fields:array<int,self>}> Block contracts keyed by normalized block name.
	 */
	private static function builderBlockDefinitions(array $meta): array {
		$blocks=[];
		foreach(is_array($meta['builder_blocks'] ?? null) ? $meta['builder_blocks'] : [] as $name=>$block){
			if(!is_array($block)){
				continue;
			}
			$blockName=self::normalizeName((string)($block['name'] ?? $name));
			if($blockName===''){
				continue;
			}
			$blocks[$blockName]=[
				'name'=>$blockName,
				'label'=>trim((string)($block['label'] ?? self::humanize($blockName))),
				'fields'=>self::childFieldDefinitions(['child_fields'=>is_array($block['fields'] ?? null) ? $block['fields'] : []]),
			];
		}
		return $blocks;
	}

	/**
	 * Dehydrates and filters builder rows according to their block field schemas.
	 *
	 * Rows with unknown block types are dropped, known rows keep their `_type`, and
	 * child fields are dehydrated through their own lifecycle so nested validation
	 * and formatting rules remain identical to top-level fields.
	 *
	 * @param mixed $value Submitted builder value.
	 * @param array<string, array<string, mixed>> $blocks Normalized builder block definitions keyed by block name.
	 * @param mixed $record Current record or row context.
	 * @param PanelRequest|null $request Active Panel request.
	 * @return array<int,array<string,mixed>> Dehydrated builder rows.
	 */
	private static function normalizeBuilderRows(mixed $value, array $blocks, mixed $record=null, ?PanelRequest $request=null): array {
		if(!is_array($value)){
			return [];
		}
		$rows=[];
		$defaultType=(string)array_key_first($blocks);
		foreach($value as $row){
			if(!is_array($row)){
				continue;
			}
			$type=self::normalizeName((string)($row['_type'] ?? $row['type'] ?? $defaultType));
			if($type==='' || !isset($blocks[$type])){
				continue;
			}
			$normalized=['_type'=>$type];
			$hasValue=false;
			foreach($blocks[$type]['fields'] as $field){
				if(!$field instanceof self){
					continue;
				}
				$name=$field->name();
				$childRecord=is_array($record) ? array_replace($record, $row) : $row;
				$childValue=$field->dehydrateValue($row[$name] ?? null, $childRecord, $request, $row);
				$normalized[$name]=$childValue;
				if(!self::blank($childValue)){
					$hasValue=true;
				}
			}
			if($hasValue || count($blocks[$type]['fields'])===0){
				$rows[]=$normalized;
			}
		}
		return $rows;
	}

	/**
	 * Dehydrates a field-group payload through its child field definitions.
	 *
	 * @param mixed $value Submitted group payload.
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @param mixed $record Current record or row context.
	 * @param PanelRequest|null $request Active Panel request.
	 * @return array<string,mixed> Dehydrated group value keyed by child field name.
	 */
	private static function normalizeFieldGroupValue(mixed $value, array $fields, mixed $record=null, ?PanelRequest $request=null): array {
		$row=is_array($value) ? $value : [];
		$normalized=[];
		foreach($fields as $field){
			if(!$field instanceof self){
				continue;
			}
			$name=$field->name();
			$childRecord=is_array($record) ? array_replace($record, $row) : $row;
			$normalized[$name]=$field->dehydrateValue($row[$name] ?? null, $childRecord, $request, $row);
		}
		return $normalized;
	}

	/**
	 * Dehydrates repeater rows through their configured child field definitions.
	 *
	 * Non-array rows are ignored, empty rows are omitted when child fields exist,
	 * and each child field receives the row as dependency context for conditional
	 * formatting, validation, and dehydration.
	 *
	 * @param mixed $value Submitted repeater payload.
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Child field definitions.
	 * @param mixed $record Current record or parent context.
	 * @param PanelRequest|null $request Active Panel request.
	 * @return array<int,array<string,mixed>> Dehydrated repeater rows.
	 */
	private static function normalizeRepeaterRows(mixed $value, array $fields, mixed $record=null, ?PanelRequest $request=null): array {
		if(!is_array($value)){
			return [];
		}
		$rows=[];
		foreach($value as $row){
			if(!is_array($row)){
				continue;
			}
			$normalized=[];
			$hasValue=false;
			foreach($fields as $field){
				if(!$field instanceof self){
					continue;
				}
				$name=$field->name();
				$childRecord=is_array($record) ? array_replace($record, $row) : $row;
				$childValue=$field->dehydrateValue($row[$name] ?? null, $childRecord, $request, $row);
				$normalized[$name]=$childValue;
				if(!self::blank($childValue)){
					$hasValue=true;
				}
			}
			if($hasValue || $fields===[]){
				$rows[]=$normalized;
			}
		}
		return $rows;
	}

	/**
	 * Checks whether a normalized field type carries uploaded file payloads.
	 *
	 * @param string $type Normalized field type.
	 * @return bool Whether the type should use upload normalization.
	 */
	private static function isFileUploadType(string $type): bool {
		return in_array($type, ['file', 'file_upload', 'upload', 'drag_drop_upload', 'image'], true);
	}

	/**
	 * Checks whether a normalized field type supports string length validation.
	 *
	 * @param string $type Normalized field type.
	 * @return bool Whether min, max, or exact length rules apply.
	 */
	private static function isLengthValidatedType(string $type): bool {
		return in_array($type, ['text', 'textarea', 'markdown', 'html', 'code', 'rich_editor', 'rich_text', 'password', 'email', 'url', 'tel', 'search'], true);
	}

	/**
	 * Normalizes PHP upload payloads into a flat list of uploaded file maps.
	 *
	 * The helper accepts single uploads, multiple-upload arrays, and recursively
	 * nested payloads. Empty file selections are discarded while upload errors are
	 * preserved for validation to report with field-specific context.
	 *
	 * @param mixed $value Raw `$_FILES`-style payload or nested upload candidate.
	 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> Flat upload list.
	 */
	private static function normalizeUploadedFiles(mixed $value): array {
		if(!is_array($value)){
			return [];
		}
		if(isset($value['name']) && is_array($value['name'])){
			$files=[];
			foreach(array_keys($value['name']) as $index){
				$file=[
					'name'=>$value['name'][$index] ?? '',
					'type'=>is_array($value['type'] ?? null) ? ($value['type'][$index] ?? '') : '',
					'tmp_name'=>is_array($value['tmp_name'] ?? null) ? ($value['tmp_name'][$index] ?? '') : '',
					'error'=>is_array($value['error'] ?? null) ? ($value['error'][$index] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK,
					'size'=>is_array($value['size'] ?? null) ? ($value['size'][$index] ?? 0) : 0,
				];
				$files=array_merge($files, self::normalizeUploadedFiles($file));
			}
			return $files;
		}
		if(self::looksLikeUploadedFile($value)){
			$error=(int)($value['error'] ?? UPLOAD_ERR_OK);
			if($error===UPLOAD_ERR_NO_FILE || trim((string)($value['name'] ?? ''))===''){
				return [];
			}
			return [[
				'name'=>(string)($value['name'] ?? ''),
				'type'=>(string)($value['type'] ?? ''),
				'tmp_name'=>(string)($value['tmp_name'] ?? ''),
				'error'=>$error,
				'size'=>(int)($value['size'] ?? 0),
			]];
		}
		$files=[];
		foreach($value as $candidate){
			$files=array_merge($files, self::normalizeUploadedFiles($candidate));
		}
		return $files;
	}

	/**
	 * Normalizes custom uploader state into persisted item payloads.
	 *
	 * String input may be JSON-encoded client state, scalar values become a single
	 * `value` item, uploaded-file maps are ignored because native uploads are handled
	 * separately, and blank values are removed before persistence.
	 *
	 * @param mixed $value Custom uploader state from submitted form data.
	 * @return array<int,mixed> Non-blank custom uploader items.
	 */
	private static function normalizeCustomUploaderItems(mixed $value): array {
		if(is_string($value)){
			$value=trim($value);
			if($value===''){
				return [];
			}
			$decoded=json_decode($value, true);
			if(json_last_error()===JSON_ERROR_NONE){
				$value=$decoded;
			}
		}
		if(!is_array($value)){
			return self::blank($value) ? [] : [['value'=>$value]];
		}
		if($value===[]){
			return [];
		}
		if(array_is_list($value)){
			return array_values(array_filter($value, static fn(mixed $item): bool => !self::blank($item)));
		}
		if(self::looksLikeUploadedFile($value)){
			return [];
		}
		return [array_filter($value, static fn(mixed $item): bool => !self::blank($item))];
	}

	/**
	 * Detects the structural shape of a PHP uploaded-file entry.
	 *
	 * @param array<string, mixed> $value Candidate upload payload.
	 * @return bool Whether the required upload keys are present.
	 */
	private static function looksLikeUploadedFile(array $value): bool {
		return array_key_exists('name', $value)
			&& array_key_exists('tmp_name', $value)
			&& array_key_exists('error', $value)
			&& array_key_exists('size', $value);
	}

	/**
	 * Normalizes loose field button declarations into renderer-ready button maps.
	 *
	 * @param array<int|string, array<string, mixed>|string> $buttons String, keyed, or associative button declarations.
	 * @return array<int,array<string,mixed>> Button definitions with normalized actions.
	 */
	private static function normalizeFieldButtons(array $buttons): array {
		$normalized=[];
		foreach($buttons as $key=>$button){
			if(is_string($button)){
				$normalized[]=self::normalizeFieldButton($button, is_string($key) ? $key : '', []);
				continue;
			}
			if(!is_array($button)){
				continue;
			}
			$label=(string)($button['label'] ?? $button['text'] ?? (is_string($key) ? $key : ''));
			$action=(string)($button['action'] ?? $button['name'] ?? '');
			$options=$button;
			unset($options['label'], $options['text'], $options['action'], $options['name']);
			$normalized[]=self::normalizeFieldButton($label, $action, $options);
		}
		return $normalized;
	}

	/**
	 * Builds a single normalized field button definition.
	 *
	 * @param string $label Human-facing button label.
	 * @param string $action Action identifier dispatched by the renderer.
	 * @param array<string, mixed> $options Optional tone, icon, URL, target, value, copy flag, or attributes.
	 * @return array<string,mixed> Renderer-ready field button definition.
	 */
	private static function normalizeFieldButton(string $label, string $action='', array $options=[]): array {
		$label=trim($label);
		$action=self::normalizeName($action);
		if($action==='' && $label!==''){
			$action=self::normalizeName($label);
		}
		$result=[
			'label'=>$label !== '' ? $label : self::humanize($action),
			'action'=>$action,
			'tone'=>self::normalizeName((string)($options['tone'] ?? $options['color'] ?? 'neutral')) ?: 'neutral',
		];
		foreach(['title', 'icon', 'url', 'target', 'value'] as $key){
			if(isset($options[$key]) && is_scalar($options[$key])){
				$result[$key]=trim((string)$options[$key]);
			}
		}
		foreach(['copy_normalized'] as $key){
			if(isset($options[$key])){
				$result[$key]=(bool)$options[$key];
			}
		}
		if(isset($options['attributes']) && is_array($options['attributes'])){
			$result['attributes']=$options['attributes'];
		}
		return $result;
	}

	/**
	 * Normalizes a client-side formatting trigger to a supported event name.
	 *
	 * @param string $event Requested event name.
	 * @return string One of `input`, `change`, `blur`, or `submit`.
	 */
	private static function normalizeFormatEvent(string $event): string {
		$event=self::normalizeName($event);
		return in_array($event, ['input', 'change', 'blur', 'submit'], true) ? $event : 'input';
	}

	/**
	 * Converts a PHP upload error code into a user-facing validation fragment.
	 *
	 * @param int $error PHP `UPLOAD_ERR_*` code.
	 * @return string Lowercase validation message fragment.
	 */
	private static function uploadedFileError(int $error): string {
		return match($error){
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE=>'file is too large',
			UPLOAD_ERR_PARTIAL=>'upload was incomplete',
			UPLOAD_ERR_NO_TMP_DIR=>'server upload directory is missing',
			UPLOAD_ERR_CANT_WRITE=>'server could not write the file',
			UPLOAD_ERR_EXTENSION=>'upload was stopped by an extension',
			UPLOAD_ERR_NO_FILE=>'no file was selected',
			default=>'unknown upload error',
		};
	}

	/**
	 * Checks an uploaded file against accepted MIME type and extension rules.
	 *
	 * @param array<string, mixed> $file Normalized uploaded file map.
	 * @param array<int, string> $accepted Accepted MIME types, wildcard MIME groups, or dot extensions.
	 * @return bool Whether the file satisfies at least one acceptance rule.
	 */
	private static function fileAccepted(array $file, array $accepted): bool {
		$name=strtolower((string)($file['name'] ?? ''));
		$type=strtolower((string)($file['type'] ?? ''));
		foreach($accepted as $rule){
			$rule=strtolower(trim((string)$rule));
			if($rule===''){
				continue;
			}
			if(str_ends_with($rule, '/*') && $type!=='' && str_starts_with($type, substr($rule, 0, -1))){
				return true;
			}
			if(str_starts_with($rule, '.') && $name!=='' && str_ends_with($name, $rule)){
				return true;
			}
			if($type!=='' && $type===$rule){
				return true;
			}
		}
		return false;
	}

	/**
	 * Formats a byte count for compact validation messages.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Human-readable lowercase byte, kilobyte, or megabyte value.
	 */
	private static function formatBytes(int $bytes): string {
		if($bytes>=1048576){
			return round($bytes/1048576, 1).'mb';
		}
		if($bytes>=1024){
			return round($bytes/1024, 1).'kb';
		}
		return $bytes.'b';
	}

	/**
	 * Extracts valid submitted values from flat or grouped option definitions.
	 *
	 * Disabled options are omitted, option groups are traversed recursively, and
	 * list-style scalar options use their labels as values to preserve legacy
	 * shorthand declarations.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Field option definitions.
	 * @param bool|null $listOptions Whether scalar list options should use labels as values.
	 * @return array<int,string> Unique valid option values.
	 */
	private static function optionValues(array $options, ?bool $listOptions=null): array {
		$listOptions ??= array_is_list($options);
		$values=[];
		foreach($options as $value=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options']);
				if(($label['disabled'] ?? false)===true){
					continue;
				}
				$values=array_merge($values, self::optionValues($groupOptions));
				continue;
			}
			if(is_array($label)){
				if(($label['disabled'] ?? false)===true){
					continue;
				}
				$values[]=(string)($label['value'] ?? $value);
				continue;
			}
			$values[]=($listOptions && is_int($value)) ? (string)$label : (string)$value;
		}
		return array_values(array_unique($values));
	}

	/**
	 * Detects whether an option array represents an option group.
	 *
	 * @param array<string, mixed> $option Candidate option definition.
	 * @return bool Whether the option contains grouped child options.
	 */
	private static function isOptionGroup(array $option): bool {
		if(isset($option['options']) && is_array($option['options'])){
			return true;
		}
		return !array_key_exists('value', $option) && !array_key_exists('label', $option) && !array_is_list($option);
	}

	/**
	 * Builds a normalized scalar option definition.
	 *
	 * @param string|int|float $value Submitted option value.
	 * @param string $label Human-facing option label.
	 * @param string|null $description Optional renderer help text.
	 * @param bool $disabled Whether the option is displayed but not submit-valid.
	 * @return array<string,mixed> Normalized option definition.
	 */
	private static function optionDefinition(string|int|float $value, string $label, ?string $description=null, bool $disabled=false): array {
		$option=[
			'value'=>(string)$value,
			'label'=>trim($label),
		];
		if($description!==null && trim($description)!==''){
			$option['description']=trim($description);
		}
		if($disabled){
			$option['disabled']=true;
		}
		return $option;
	}

	/**
	 * Checks whether an option tree contains any grouped options.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Field option definitions.
	 * @return bool Whether at least one option group is present.
	 */
	private static function optionsContainGroups(array $options): bool {
		foreach($options as $option){
			if(is_array($option) && self::isOptionGroup($option)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether an option tree contains disabled options.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Field option definitions.
	 * @return bool Whether any direct or nested option is disabled.
	 */
	private static function optionsContainDisabled(array $options): bool {
		foreach($options as $option){
			if(is_array($option)){
				if(($option['disabled'] ?? false)===true){
					return true;
				}
				if(self::isOptionGroup($option)){
					$groupOptions=is_array($option['options'] ?? null) ? $option['options'] : $option;
					unset($groupOptions['label'], $groupOptions['options'], $groupOptions['description'], $groupOptions['disabled']);
					if(self::optionsContainDisabled($groupOptions)){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Checks whether an option tree contains descriptive help text.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Field option definitions.
	 * @return bool Whether any direct or nested option carries description text.
	 */
	private static function optionsContainDescriptions(array $options): bool {
		foreach($options as $option){
			if(is_array($option)){
				if(trim((string)($option['description'] ?? $option['help'] ?? ''))!==''){
					return true;
				}
				if(self::isOptionGroup($option)){
					$groupOptions=is_array($option['options'] ?? null) ? $option['options'] : $option;
					unset($groupOptions['label'], $groupOptions['options'], $groupOptions['description'], $groupOptions['disabled']);
					if(self::optionsContainDescriptions($groupOptions)){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Determines whether a submitted value should be treated as empty.
	 *
	 * @param mixed $value Candidate submitted or dehydrated value.
	 * @return bool Whether the value is null, blank string, or empty array.
	 */
	private static function blank(mixed $value): bool {
		return $value===null || (is_string($value) && trim($value)==='') || (is_array($value) && $value===[]);
	}

	/**
	 * Measures a value for numeric, array-count, or string-length validation.
	 *
	 * @param mixed $value Candidate value.
	 * @return float Numeric value, item count, or multibyte string length.
	 */
	private static function sizeOf(mixed $value): float {
		if(is_numeric($value)){
			return (float)$value;
		}
		if(is_array($value)){
			return (float)count($value);
		}
		return (float)mb_strlen((string)$value);
	}

	/**
	 * Coerces common form truth values into a boolean for conditional rules.
	 *
	 * @param mixed $value Candidate boolean-like value.
	 * @return bool Boolean interpretation used by dependency and validation checks.
	 */
	private static function truthy(mixed $value): bool {
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
	 * Flattens and normalizes Panel operation names for visibility constraints.
	 *
	 * @param array<int|string, string|array<int|string, mixed>> $operations Nested or flat operation names.
	 * @return array<int,string> Unique normalized operation names.
	 */
	private static function normalizeOperations(array $operations): array {
		$flat=[];
		foreach($operations as $operation){
			if(is_array($operation)){
				foreach($operation as $nested){
					$flat[]=self::normalizeOperation((string)$nested);
				}
				continue;
			}
			$flat[]=self::normalizeOperation((string)$operation);
		}
		return array_values(array_unique(array_filter($flat)));
	}

	/**
	 * Flattens and normalizes field-name lists for dependency metadata.
	 *
	 * @param array<int|string, string|array<int|string, mixed>> $fields Nested or flat field names.
	 * @return array<int,string> Unique normalized field names.
	 */
	private static function normalizeFieldList(array $fields): array {
		$flat=[];
		foreach($fields as $field){
			if(is_array($field)){
				foreach(self::normalizeFieldList($field) as $nested){
					$flat[]=$nested;
				}
				continue;
			}
			$field=self::normalizeName((string)$field);
			if($field!==''){
				$flat[]=$field;
			}
		}
		return array_values(array_unique($flat));
	}

	/**
	 * Normalizes scalar or list validation rule values into unique strings.
	 *
	 * @param array|string $values Validation rule payload values.
	 * @return array<int,string> Trimmed non-empty rule values.
	 */
	private static function normalizeRuleValues(array|string $values): array {
		$values=is_array($values) ? $values : [$values];
		$normalized=[];
		foreach($values as $value){
			$value=trim((string)$value);
			if($value!==''){
				$normalized[]=$value;
			}
		}
		return array_values(array_unique($normalized));
	}

	/**
	 * Normalizes color contrast validation policy metadata.
	 *
	 * Ratios are clamped to WCAG's meaningful range and scope is limited to the
	 * field surfaces the renderer and validator can reason about consistently.
	 *
	 * @param array<string, mixed> $policy Contrast policy declaration.
	 * @return array{min_ratio:float,scope:string,large_text_min_ratio:float} Normalized policy.
	 */
	private static function normalizeContrastPolicy(array $policy): array {
		$min=(float)($policy['min_ratio'] ?? $policy['ratio'] ?? 4.5);
		$scope=self::normalizeName((string)($policy['scope'] ?? 'control'));
		return [
			'min_ratio'=>max(1.0, min(21.0, $min)),
			'scope'=>in_array($scope, ['field', 'label', 'control', 'input'], true) ? $scope : 'control',
			'large_text_min_ratio'=>max(1.0, min(21.0, (float)($policy['large_text_min_ratio'] ?? 3.0))),
		];
	}

	/**
	 * Removes validation rules whose leading rule name is in the provided set.
	 *
	 * @param array<int, string> $rules Existing validation rule strings.
	 * @param array<int, string> $names Rule names to remove before validation.
	 * @return array<int,string> Remaining rules in original order.
	 */
	private static function removeRulesByName(array $rules, array $names): array {
		$names=array_map(static fn(string $name): string => strtolower(trim($name)), $names);
		return array_values(array_filter($rules, static function(string $rule) use ($names): bool {
			return !in_array(strtolower(trim(explode(':', $rule, 2)[0])), $names, true);
		}));
	}

	/**
	 * Evaluates a regex rule while tolerating bare pattern fragments.
	 *
	 * Invalid delimiter usage is repaired by wrapping the fragment in a Unicode
	 * regex; invalid patterns fail closed through the silenced `preg_match` check.
	 *
	 * @param string $pattern Regex pattern or bare fragment.
	 * @param string $value Value to test.
	 * @return bool Whether the value matches the pattern.
	 */
	private static function regexMatches(string $pattern, string $value): bool {
		$pattern=trim($pattern);
		if($pattern===''){
			return true;
		}
		if(@preg_match($pattern, '')===false){
			$pattern='/'.str_replace('/', '\/', $pattern).'/u';
		}
		return @preg_match($pattern, $value)===1;
	}

	/**
	 * Checks whether a value starts with any configured prefix.
	 *
	 * @param string $value Candidate value.
	 * @param array<int, string> $prefixes Prefix strings.
	 * @return bool Whether any prefix matches.
	 */
	private static function stringStartsWithAny(string $value, array $prefixes): bool {
		foreach($prefixes as $prefix){
			if(str_starts_with($value, $prefix)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether a value ends with any configured suffix.
	 *
	 * @param string $value Candidate value.
	 * @param array<int, string> $suffixes Suffix strings.
	 * @return bool Whether any suffix matches.
	 */
	private static function stringEndsWithAny(string $value, array $suffixes): bool {
		foreach($suffixes as $suffix){
			if(str_ends_with($value, $suffix)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes Panel operation aliases to canonical operation names.
	 *
	 * @param string $operation Operation alias or canonical name.
	 * @return string Canonical operation name.
	 */
	private static function normalizeOperation(string $operation): string {
		$operation=self::normalizeName($operation);
		return match($operation){
			'store'=>'create',
			'update'=>'edit',
			'view'=>'show',
			default=>$operation,
		};
	}

	/**
	 * Converts user-facing schema identifiers into Dataphyre's normalized name form.
	 *
	 * @param string $name Raw field, action, operation, or option identifier.
	 * @return string Lowercase identifier containing alphanumerics, underscores, dots, or dashes.
	 */
	private static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.-]+/', '_', $name) ?? '';
		return trim($name, '_.-');
	}

	/**
	 * Converts a normalized identifier into a compact human-facing label.
	 *
	 * @param string $value Normalized identifier or slug-like value.
	 * @return string Title-cased label text.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
