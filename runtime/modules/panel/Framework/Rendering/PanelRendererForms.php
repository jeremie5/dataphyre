<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders Panel form controls, action outcomes, entries, and field utilities.
 *
 * The trait centralizes HTML generation for field metadata into escaped control
 * fragments, including relationship selectors, repeaters, builders, rich text,
 * file uploads, action notifications, grid sections, and display formatting.
 * Callers supply normalized resource metadata; the renderer preserves readonly
 * values with hidden mirrors where browser controls would otherwise omit them.
 */
trait PanelRendererForms {
	/**
	 * Builds a localized human label for a field key.
	 *
	 * The fallback is derived from underscores, dashes, and dotted paths, while the
	 * lookup key uses Resource name normalization so generated labels can be
	 * overridden through panel localization without losing a readable default.
	 *
	 * @param string $name Field name or nested field path.
	 * @return string Localized field label or generated fallback.
	 */
	private static function humanFieldLabel(string $name): string {
		$fallback=ucwords(str_replace(['_', '-', '.'], ' ', $name));
		$key=Resource::normalizeName($name);
		return $key!=='' ? self::panelText('field.'.$key, [], $fallback) : $fallback;
	}

	/**
	 * Maps Panel field types to browser input types.
	 *
	 * Renderer-specific semantic types collapse to the closest native control so
	 * browser validation, mobile keyboards, and accessibility roles stay aligned
	 * with the field's intent while custom formatting remains data-driven.
	 *
	 * @param string $type Panel field type or semantic format family.
	 * @return string Native HTML input type.
	 */
	private static function inputType(string $type): string {
		return match($type){
			'search'=>'search',
			'email'=>'email',
			'number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage'=>'number',
			'password'=>'password',
			'date'=>'date',
			'datetime', 'datetime_local', 'datetime-local'=>'datetime-local',
			'month'=>'month',
			'week'=>'week',
			'time'=>'time',
			'tel', 'phone_international'=>'tel',
			'url'=>'url',
			'latitude', 'longitude'=>'number',
			'color'=>'color',
			'range'=>'range',
			'slider'=>'range',
			default=>'text',
		};
	}

	/**
	 * Renders the appropriate control fragment for a field metadata definition.
	 *
	 * This is the central dispatch boundary from normalized resource metadata to
	 * escaped Panel form HTML. It delegates registered component controls first,
	 * preserves readonly values with hidden mirrors where native controls would
	 * omit disabled values, and routes compound types to their specialized renderers.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Normalized field metadata.
	 * @param mixed $value Current field value.
	 * @param bool $forceHidden Whether the field must be rendered as a hidden input.
	 * @return string Escaped control HTML.
	 */
	private static function fieldControl(string $name, array $meta, mixed $value, bool $forceHidden=false): string {
		$type=(string)($meta['type'] ?? 'text');
		$required=($meta['required'] ?? false) ? ' required' : '';
		$readonly=($meta['readonly'] ?? false) ? ' readonly' : '';
		$placeholder=self::placeholderAttributeHtml($meta);
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		if($forceHidden){
			return '<input type="hidden" name="'.self::e($name).'" value="'.self::e(self::stringValue($value)).'">';
		}
		$registered=PanelComponentRegistry::renderFieldControl($type, $name, $meta, $value, ['renderer'=>__CLASS__]);
		if($registered!==null){
			return $registered;
		}
		if($type==='hidden'){
			return '<input type="hidden" name="'.self::e($name).'" value="'.self::e(self::stringValue($value)).'">';
		}
		if(self::isDisplayFieldType($type)){
			return self::displayOnlyControl($meta, $value);
		}
		if($type==='repeater'){
			return self::repeaterControl($name, $meta, $value);
		}
		if($type==='builder'){
			return self::builderControl($name, $meta, $value);
		}
		if(in_array($type, ['fieldset', 'group', 'field_group', 'address'], true)){
			return self::fieldGroupControl($name, $meta, $value);
		}
		if($type==='slider'){
			return self::sliderControl($name, $meta, $value, $required);
		}
		if(in_array($type, ['date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'], true)){
			return self::rangePairControl($name, $meta, $value, $required, $readonly);
		}
		if($type==='rating'){
			return self::ratingControl($name, $meta, $value, $required);
		}
		if(self::isFileFieldType($type)){
			return self::fileControl($name, $meta, $value);
		}
		if(self::isBooleanType($type)){
			$checked=self::truthy($value) ? ' checked' : '';
			$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
			$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
			$onLabel=(string)($fieldMeta['on_label'] ?? 'Enabled');
			$offLabel=(string)($fieldMeta['off_label'] ?? 'Disabled');
			$stateLabel=self::truthy($value) ? $onLabel : $offLabel;
			$label=trim((string)($meta['label'] ?? self::humanFieldLabel($name)));
			$role=$type==='toggle' ? ' role="switch"' : '';
			$mirror=($meta['readonly'] ?? false)
				? '<input type="hidden" name="'.self::e($name).'" value="'.(self::truthy($value) ? '1' : '0').'">'
				: '<input type="hidden" name="'.self::e($name).'" value="0">';
			return '<label class="dp-panel-checkbox dp-panel-switch" data-dp-panel-switch="1" data-dp-panel-switch-on="'.self::e($onLabel).'" data-dp-panel-switch-off="'.self::e($offLabel).'">'.$mirror
				.'<input type="checkbox" name="'.self::e($name).'" value="1"'.$checked.$disabled.$required.$role.' aria-label="'.self::e($label).'">'
				.'<span class="dp-panel-switch-track" aria-hidden="true"><span></span></span>'
				.'<span class="dp-panel-switch-copy"><strong data-dp-panel-switch-label="1">'.self::e($stateLabel).'</strong></span></label>';
		}
		if(in_array($type, ['textarea', 'json', 'markdown', 'html', 'code', 'rich_editor', 'rich_text'], true)){
			return self::textareaControl($name, $meta, $value, $required, $readonly, $placeholder);
		}
		if($type==='radio'){
			return self::choiceControl($name, $meta, $value, false);
		}
		if($type==='checkbox_list'){
			return self::choiceControl($name, $meta, $value, true);
		}
		if(in_array($type, ['toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true)){
			$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
			return self::choiceControl($name, $meta, $value, ($fieldMeta['multiple'] ?? false)===true);
		}
		if(in_array($type, ['multi_select', 'multiselect', 'multi_relationship', 'belongs_to_many'], true)){
			$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
			$mirror=($meta['readonly'] ?? false) ? self::hiddenListInputs($name, $value) : '';
			$select='<select name="'.self::e($name).'[]"'.$required.$disabled.' multiple'.self::choiceAttributeHtml($meta).self::relationshipAttributeHtml($meta).'>'.self::optionHtml($options, $value).'</select>';
			return $mirror.self::searchableSelectShell($name, $meta, self::textInputShell($meta, $select), true, self::optionCount($options));
		}
		if($type==='tags' || $type==='tags_input'){
			$tags=is_array($value) ? implode(', ', array_map(static fn(mixed $tag): string => (string)$tag, $value)) : self::stringValue($value);
			$attrs=self::inputAttributeHtml($meta, $name);
			$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
			$separator=(string)($fieldMeta['tag_separator'] ?? ',');
			$separator=$separator!=='' ? $separator : ',';
			$tagAttrs=' data-dp-panel-tags="1" data-dp-panel-tag-separator="'.self::e($separator).'"';
			foreach(['min_tags', 'max_tags'] as $tagLimit){
				if(array_key_exists($tagLimit, $fieldMeta) && is_scalar($fieldMeta[$tagLimit])){
					$tagAttrs.=' data-dp-panel-'.str_replace('_', '-', $tagLimit).'="'.self::e((string)$fieldMeta[$tagLimit]).'"';
				}
			}
			$input='<input type="text" name="'.self::e($name).'" value="'.self::e($tags).'"'.$required.$readonly.$placeholder.$tagAttrs.$attrs.'>';
			return '<div class="dp-panel-tags-input" data-dp-panel-tags-shell="1">'.self::textInputShell($meta, $input).'<div class="dp-panel-tags-preview" data-dp-panel-tags-list aria-live="polite"></div></div>'.self::datalistHtml($name, $meta);
		}
		if($type==='key_value' || $type==='keyvalue'){
			$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
			$keySeparator=(string)($fieldMeta['key_separator'] ?? '=');
			$pairSeparator=(string)($fieldMeta['pair_separator'] ?? "\n");
			$keySeparator=$keySeparator!=='' ? $keySeparator : '=';
			$pairSeparator=$pairSeparator!=='' ? $pairSeparator : "\n";
			$text=is_array($value) ? self::keyValueText($value, $keySeparator, $pairSeparator) : self::stringValue($value);
			$kvAttrs=' data-dp-panel-key-value="1" data-dp-panel-key-separator="'.self::e($keySeparator).'" data-dp-panel-pair-separator="'.self::e($pairSeparator).'"';
			foreach(['min_pairs', 'max_pairs'] as $pairLimit){
				if(array_key_exists($pairLimit, $fieldMeta) && is_scalar($fieldMeta[$pairLimit])){
					$kvAttrs.=' data-dp-panel-'.str_replace('_', '-', $pairLimit).'="'.self::e((string)$fieldMeta[$pairLimit]).'"';
				}
			}
			$control='<textarea name="'.self::e($name).'" class="dp-panel-textarea-key-value"'.$required.$readonly.$placeholder.self::textAttributeHtml($meta).$kvAttrs.' rows="'.self::e((string)($fieldMeta['rows'] ?? 6)).'">'
				.self::e($text).'</textarea>';
			return '<div class="dp-panel-key-value" data-dp-panel-key-value-shell="1">'.self::textInputShell($meta, $control).'<div class="dp-panel-key-value-preview" data-dp-panel-key-value-preview aria-live="polite"></div></div>';
		}
		if(in_array($type, ['select', 'enum', 'relationship', 'belongs_to', 'relation'], true) || $options!==[]){
			$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
			$mirror=($meta['readonly'] ?? false) ? '<input type="hidden" name="'.self::e($name).'" value="'.self::e(self::stringValue($value)).'">' : '';
			$empty=(($meta['meta']['clearable'] ?? false)===true || !($meta['required'] ?? false)) ? '<option value="">'.self::e((string)($meta['meta']['empty_label'] ?? self::panelText('select.empty'))).'</option>' : '';
			$select='<select name="'.self::e($name).'"'.$required.$disabled.self::choiceAttributeHtml($meta).self::relationshipAttributeHtml($meta).'>'.$empty.self::optionHtml($options, $value).'</select>';
			return $mirror.self::searchableSelectShell($name, $meta, self::textInputShell($meta, $select), false, self::optionCount($options));
		}
		$attrs=self::inputAttributeHtml($meta, $name);
		$input='<input type="'.self::e(self::inputType($type)).'" name="'.self::e($name).'" value="'.self::e(self::stringValue($value)).'"'.$required.$readonly.$placeholder.$attrs.'>';
		return self::textInputShell($meta, $input).self::datalistHtml($name, $meta);
	}

	/**
	 * Renders a range slider with optional readonly mirroring and live output.
	 *
	 * Slider bounds come from field metadata and default to a safe 0-100 range.
	 * Readonly sliders are disabled for interaction but mirrored through a hidden
	 * input so the submitted payload still contains the current value.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current slider value.
	 * @param string $required Prebuilt required attribute fragment.
	 * @return string Slider control HTML.
	 */
	private static function sliderControl(string $name, array $meta, mixed $value, string $required): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$min=array_key_exists('min', $fieldMeta) && is_scalar($fieldMeta['min']) ? (string)$fieldMeta['min'] : '0';
		$max=array_key_exists('max', $fieldMeta) && is_scalar($fieldMeta['max']) ? (string)$fieldMeta['max'] : '100';
		$step=array_key_exists('step', $fieldMeta) && is_scalar($fieldMeta['step']) ? (string)$fieldMeta['step'] : '1';
		$current=self::stringValue($value);
		if($current==='' && $min!==''){
			$current=$min;
		}
		$readonly=($meta['readonly'] ?? false) ? ' disabled aria-readonly="true"' : '';
		$mirror=($meta['readonly'] ?? false)
			? '<input type="hidden" name="'.self::e($name).'" value="'.self::e($current).'">'
			: '';
		$id='dp-panel-slider-'.trim((string)(preg_replace('/[^a-z0-9_-]+/i', '-', $name) ?? ''), '-');
		if($id==='dp-panel-slider-'){
			$id.='control';
		}
		$label=trim((string)($meta['label'] ?? self::humanFieldLabel($name)));
		$valueLabel=trim((string)($fieldMeta['value_label'] ?? self::panelText('slider.current_value')));
		$input='<input id="'.self::e($id).'" type="range" name="'.self::e($name).'" value="'.self::e($current).'" min="'.self::e($min).'" max="'.self::e($max).'" step="'.self::e($step).'"'.$required.$readonly.' aria-label="'.self::e($label).'" data-dp-panel-slider="1">';
		$output=(($fieldMeta['value_display'] ?? true)===false)
			? ''
			: '<output class="dp-panel-slider-value" for="'.self::e($id).'" data-dp-panel-slider-value="1" aria-live="polite"><span class="dp-panel-sr-only">'.self::e($valueLabel).': </span>'.self::e($current).'</output>';
		$bounds='<div class="dp-panel-slider-bounds" aria-hidden="true"><span>'.self::e($min).'</span><span>'.self::e($max).'</span></div>';
		return $mirror.'<div class="dp-panel-slider" data-dp-panel-slider-shell="1">'.$input.$output.$bounds.'</div>';
	}

	/**
	 * Checks whether a field type should render as display-only content.
	 *
	 * @param string $type Panel field type.
	 * @return bool Whether the type is display-only.
	 */
	private static function isDisplayFieldType(string $type): bool {
		return in_array(Resource::normalizeName($type), ['placeholder', 'display', 'display_only', 'view_field'], true);
	}

	/**
	 * Renders non-submitting display content from field metadata or value.
	 *
	 * Optional rich HTML content is sanitized through the server-side rich HTML
	 * allowlist, while plain content is escaped and line breaks are preserved.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Display value.
	 * @return string Display-only control HTML.
	 */
	private static function displayOnlyControl(array $meta, mixed $value): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$content=array_key_exists('display_content', $fieldMeta) ? (string)$fieldMeta['display_content'] : self::stringValue($value);
		if($content===''){
			$content=(string)($meta['placeholder'] ?? '');
		}
		$html=($fieldMeta['html'] ?? false)===true
			? self::safeRichHtml($content)
			: nl2br(self::e($content));
		$description=trim((string)($fieldMeta['description'] ?? ''));
		$descriptionHtml=$description!=='' ? '<small>'.self::e($description).'</small>' : '';
		return '<div class="dp-panel-display-field" data-dp-panel-display-field="1" role="note"><div>'.$html.'</div>'.$descriptionHtml.'</div>';
	}

	/**
	 * Renders start/end date, datetime, or time range controls.
	 *
	 * Range values submit as `name[start]` and `name[end]`, using metadata labels
	 * and bounds while preserving the same required and readonly policy as the
	 * parent field.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current range value.
	 * @param string $required Prebuilt required attribute fragment.
	 * @param string $readonly Prebuilt readonly attribute fragment.
	 * @return string Range pair control HTML.
	 */
	private static function rangePairControl(string $name, array $meta, mixed $value, string $required, string $readonly): string {
		$type=Resource::normalizeName((string)($meta['type'] ?? 'date_range'));
		$inputType=match($type){
			'date_time_range', 'datetime_range'=>'datetime-local',
			'time_range', 'timerange'=>'time',
			default=>'date',
		};
		$values=self::rangePairValues($value);
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$attrs='';
		foreach(['min', 'max', 'step'] as $key){
			if(array_key_exists($key, $fieldMeta) && is_scalar($fieldMeta[$key])){
				$attrs.=' '.$key.'="'.self::e((string)$fieldMeta[$key]).'"';
			}
		}
		$startLabel=(string)($fieldMeta['start_label'] ?? self::panelText('range.start', [], 'Start'));
		$endLabel=(string)($fieldMeta['end_label'] ?? self::panelText('range.end', [], 'End'));
		$disabled=($meta['readonly'] ?? false) ? ' disabled aria-readonly="true"' : $readonly;
		$start='<label class="dp-panel-range-pair-item"><span>'.self::e($startLabel).'</span><input type="'.self::e($inputType).'" name="'.self::e($name).'[start]" value="'.self::e($values['start']).'"'.$required.$disabled.$attrs.'></label>';
		$end='<label class="dp-panel-range-pair-item"><span>'.self::e($endLabel).'</span><input type="'.self::e($inputType).'" name="'.self::e($name).'[end]" value="'.self::e($values['end']).'"'.$required.$disabled.$attrs.'></label>';
		return '<div class="dp-panel-range-pair" data-dp-panel-range-pair="'.self::e($inputType).'">'.$start.$end.'</div>';
	}

	/**
	 * Normalizes a range value into start and end strings.
	 *
	 * Arrays may use associative `start`/`end` keys or numeric positions. String
	 * values are split on common textual range separators for legacy metadata.
	 *
	 * @param mixed $value Current range value.
	 * @return array{start:string,end:string} Normalized range endpoints.
	 */
	private static function rangePairValues(mixed $value): array {
		if(is_array($value)){
			return [
				'start'=>self::stringValue($value['start'] ?? $value[0] ?? ''),
				'end'=>self::stringValue($value['end'] ?? $value[1] ?? ''),
			];
		}
		$text=trim(self::stringValue($value));
		if($text===''){
			return ['start'=>'', 'end'=>''];
		}
		$parts=preg_split('/\s*(?:\.\.|→|to)\s*/i', $text, 2) ?: [$text];
		return [
			'start'=>trim((string)($parts[0] ?? '')),
			'end'=>trim((string)($parts[1] ?? '')),
		];
	}

	/**
	 * Renders a rating control as an accessible radio group.
	 *
	 * Metadata controls min, max, and step, bounded to keep the rendered option set
	 * compact and predictable for keyboard and assistive technology users.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current rating value.
	 * @param string $required Prebuilt required attribute fragment.
	 * @return string Rating control HTML.
	 */
	private static function ratingControl(string $name, array $meta, mixed $value, string $required): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$min=max(0, (int)($fieldMeta['min'] ?? 1));
		$max=max($min, min(20, (int)($fieldMeta['max'] ?? 5)));
		$step=max(1, (int)($fieldMeta['step'] ?? 1));
		$current=self::stringValue($value);
		$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
		$label=trim((string)($meta['label'] ?? self::humanFieldLabel($name)));
		$html='<div class="dp-panel-rating" role="radiogroup" aria-label="'.self::e($label).'" data-dp-panel-rating="1">';
		for($rating=$min;$rating<=$max;$rating+=$step){
			$checked=((string)$rating)===$current ? ' checked' : '';
			$html.='<label class="dp-panel-rating-option">'
				.'<input type="radio" name="'.self::e($name).'" value="'.self::e((string)$rating).'"'.$checked.$required.$disabled.'>'
				.'<span aria-hidden="true">★</span><small>'.self::e((string)$rating).'</small>'
				.'</label>';
		}
		return $html.'</div>';
	}

	/**
	 * Wraps a text-like input in prepend/append adornments when metadata asks for it.
	 *
	 * The shell hosts labels, icons, character counters, color swatches, field
	 * buttons, and password/copy/clear affordances. Unsupported input types are
	 * returned unchanged so their native layout is not disturbed.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param string $input Already-rendered input or textarea HTML.
	 * @return string Input HTML, optionally wrapped in an adornment shell.
	 */
	private static function textInputShell(array $meta, string $input): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$type=(string)($meta['type'] ?? 'text');
		if(!self::textInputSupportsAdornments($type)){
			return $input;
		}
		$prependLabel=trim((string)($fieldMeta['prepend_label'] ?? $fieldMeta['prefix'] ?? ''));
		$appendLabel=trim((string)($fieldMeta['append_label'] ?? $fieldMeta['suffix'] ?? ''));
		$prependIcons=is_array($fieldMeta['prepend_icons'] ?? null) ? $fieldMeta['prepend_icons'] : [];
		$appendIcons=is_array($fieldMeta['append_icons'] ?? null) ? $fieldMeta['append_icons'] : [];
		$prependButtons=is_array($fieldMeta['prepend_buttons'] ?? null) ? $fieldMeta['prepend_buttons'] : [];
		$appendButtons=is_array($fieldMeta['append_buttons'] ?? null) ? $fieldMeta['append_buttons'] : [];
		if(($fieldMeta['clearable'] ?? false)===true && !self::inputButtonsIncludeAction($appendButtons, 'clear')){
			$appendButtons[]=['label'=>self::panelText('common.clear'), 'action'=>'clear', 'tone'=>'neutral', 'icon'=>'x'];
		}
		if(($fieldMeta['copyable'] ?? false)===true && !self::inputButtonsIncludeAction($appendButtons, 'copy')){
			$appendButtons[]=[
				'label'=>(string)($fieldMeta['copy_label'] ?? self::panelText('common.copy')),
				'action'=>'copy',
				'tone'=>'neutral',
				'icon'=>'copy',
				'copy_normalized'=>($fieldMeta['copy_normalized'] ?? false)===true,
			];
		}
		if($type==='password' && ($fieldMeta['revealable'] ?? $fieldMeta['password_reveal'] ?? true)===true && !self::inputButtonsIncludeAction($appendButtons, 'toggle_password')){
			$appendButtons[]=['label'=>self::panelText('common.show'), 'action'=>'toggle_password', 'tone'=>'neutral', 'icon'=>'eye'];
		}
		$counterPosition=Resource::normalizeName((string)($fieldMeta['character_counter_position'] ?? 'append'))==='prepend' ? 'prepend' : 'append';
		$prependCounter=$counterPosition==='prepend' ? self::characterCounterHtml($meta, 'prepend') : '';
		$appendCounter=$counterPosition==='append' ? self::characterCounterHtml($meta, 'append') : '';
		$appendSwatch=self::colorSwatchHtml($meta);
		if($prependLabel==='' && $appendLabel==='' && $prependIcons===[] && $appendIcons===[] && $prependButtons===[] && $appendButtons===[] && $prependCounter==='' && $appendCounter==='' && $appendSwatch===''){
			return $input;
		}
		return '<span class="dp-panel-input-shell" data-dp-panel-input-shell="1">'
			.self::inputAdornmentGroupHtml('prepend', $prependLabel, $prependIcons, $prependButtons, $prependCounter)
			.'<span class="dp-panel-input-control">'.$input.'</span>'
			.self::inputAdornmentGroupHtml('append', $appendLabel, $appendIcons, $appendButtons, $appendCounter.$appendSwatch)
			.'</span>';
	}

	/**
	 * Checks whether an input type can safely use Panel adornment shells.
	 *
	 * @param string $type Panel field type.
	 * @return bool Whether adornments are supported for the type.
	 */
	private static function textInputSupportsAdornments(string $type): bool {
		return in_array($type, ['text', 'email', 'number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage', 'password', 'otp', 'one_time_code', 'verification_code', 'pin', 'pin_code', 'credit_card', 'card_number', 'credit_card_expiry', 'card_expiry', 'card_cvc', 'cvc', 'cvv', 'iban', 'slug', 'uuid', 'ulid', 'domain', 'hostname', 'timezone', 'time_zone', 'locale', 'language_tag', 'mime_type', 'content_type', 'semver', 'semantic_version', 'cron_expression', 'cron', 'language_code', 'iso_language', 'country_code', 'iso_country', 'subdivision_code', 'region_code', 'currency_code', 'iso_currency', 'ip_address', 'ip', 'ipv4', 'ipv6', 'mac_address', 'mac', 'hex_color', 'color_hex', 'latitude', 'longitude', 'coordinates', 'lat_lng', 'lng_lat', 'phone_international', 'postal_code', 'postal', 'postal_code_ca', 'canadian_postal_code', 'zip_code_us', 'postal_code_us', 'zip', 'date', 'datetime', 'datetime_local', 'month', 'week', 'time', 'tel', 'url', 'search', 'color', 'tags', 'tags_input', 'select', 'enum', 'multi_select', 'multiselect', 'textarea', 'json', 'markdown', 'html', 'code', 'rich_editor', 'rich_text', 'key_value', 'keyvalue'], true);
	}

	/**
	 * Checks whether a configured field-button list already contains an action.
	 *
	 * @param array<int, array<string, mixed>> $buttons Button metadata entries.
	 * @param string $action Normalized action name to find.
	 * @return bool Whether the action is already present.
	 */
	private static function inputButtonsIncludeAction(array $buttons, string $action): bool {
		foreach($buttons as $button){
			if(is_array($button) && Resource::normalizeName((string)($button['action'] ?? ''))===$action){
				return true;
			}
		}
		return false;
	}

	/**
	 * Renders one prepend or append adornment group.
	 *
	 * @param string $position Adornment side, usually `prepend` or `append`.
	 * @param string $label Textual addon label.
	 * @param array<int, array<string, mixed>> $icons Icon metadata entries.
	 * @param array<int, array<string, mixed>> $buttons Button metadata entries.
	 * @param string $extraHtml Trusted renderer-generated extra adornment HTML.
	 * @return string Adornment group HTML or an empty string.
	 */
	private static function inputAdornmentGroupHtml(string $position, string $label, array $icons, array $buttons, string $extraHtml=''): string {
		$html='';
		if($label!==''){
			$html.='<span class="dp-panel-input-addon dp-panel-input-addon-'.$position.'">'.self::e($label).'</span>';
		}
		foreach($icons as $icon){
			if(is_array($icon)){
				$html.=self::inputIconHtml($position, $icon);
			}
		}
		$html.=$extraHtml;
		foreach($buttons as $button){
			if(is_array($button)){
				$html.=self::inputButtonHtml($position, $button);
			}
		}
		return $html!=='' ? '<span class="dp-panel-input-adornments dp-panel-input-adornments-'.$position.'">'.$html.'</span>' : '';
	}

	/**
	 * Renders a compact text icon addon for an input shell.
	 *
	 * @param string $position Adornment side.
	 * @param array<string, mixed> $icon Icon metadata.
	 * @return string Icon addon HTML or an empty string.
	 */
	private static function inputIconHtml(string $position, array $icon): string {
		$name=trim((string)($icon['icon'] ?? ''));
		if($name===''){
			return '';
		}
		$label=trim((string)($icon['label'] ?? $name));
		$title=$label!=='' ? ' title="'.self::e($label).'"' : '';
		return '<span class="dp-panel-input-addon dp-panel-input-addon-'.$position.' dp-panel-input-icon" data-dp-panel-input-icon="'.self::e($name).'"'.$title.' aria-hidden="true">'.self::e(strtoupper(substr($name, 0, 2))).'</span>';
	}

	/**
	 * Renders a live character counter addon when enabled by field metadata.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param string $position Adornment side.
	 * @return string Character counter HTML or an empty string.
	 */
	private static function characterCounterHtml(array $meta, string $position): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(($fieldMeta['character_counter'] ?? false)!==true){
			return '';
		}
		$max=(int)($fieldMeta['character_counter_max'] ?? $fieldMeta['max_length'] ?? 0);
		$attrs=' data-dp-panel-character-counter="1" aria-live="polite"';
		if($max>0){
			$attrs.=' data-dp-panel-character-counter-max="'.self::e((string)$max).'"';
		}
		return '<span class="dp-panel-input-addon dp-panel-input-addon-'.$position.' dp-panel-input-counter"'.$attrs.'>'.($max>0 ? '0/'.self::e((string)$max) : '0').'</span>';
	}

	/**
	 * Renders the color preview swatch addon for color-like fields.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Color swatch HTML or an empty string.
	 */
	private static function colorSwatchHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$type=Resource::normalizeName((string)($meta['type'] ?? ''));
		$rule=Resource::normalizeName((string)($fieldMeta['format_rule'] ?? ''));
		if(!(in_array($rule, ['hex_color', 'color_hex'], true) || $type==='color') || ($fieldMeta['color_swatch'] ?? true)===false){
			return '';
		}
		return '<span class="dp-panel-input-addon dp-panel-input-addon-append dp-panel-input-color-swatch-wrap" title="Color preview">'
			.'<span class="dp-panel-input-color-swatch" data-dp-panel-color-swatch="1"></span>'
			.'</span>';
	}

	/**
	 * Renders a button or link addon for an input shell.
	 *
	 * Button metadata is constrained to renderer-known field actions and safe
	 * data/aria passthrough attributes; URL buttons render as links with optional
	 * noopener targets.
	 *
	 * @param string $position Adornment side.
	 * @param array<string, mixed> $button Button metadata.
	 * @return string Button or link HTML.
	 */
	private static function inputButtonHtml(string $position, array $button): string {
		$label=trim((string)($button['label'] ?? ''));
		$action=Resource::normalizeName((string)($button['action'] ?? ''));
		$tone=Resource::normalizeName((string)($button['tone'] ?? 'neutral')) ?: 'neutral';
		$title=trim((string)($button['title'] ?? $label));
		$icon=trim((string)($button['icon'] ?? ''));
		$class='dp-panel-input-button dp-panel-input-button-'.$position.' dp-panel-input-button-'.$tone;
		$iconHtml=$icon!=='' && $action!=='toggle_password' ? '<span class="dp-panel-input-button-icon">'.self::e(strtoupper(substr($icon, 0, 2))).'</span>' : '';
		$content=$iconHtml.'<span>'.self::e($label).'</span>';
		$attrs=self::inputButtonAttributeHtml($button);
		if(isset($button['url']) && is_scalar($button['url']) && trim((string)$button['url'])!==''){
			$target=isset($button['target']) && is_scalar($button['target']) && trim((string)$button['target'])!=='' ? ' target="'.self::e((string)$button['target']).'" rel="noopener"' : '';
			return '<a class="'.$class.'" href="'.self::e((string)$button['url']).'" title="'.self::e($title).'"'.$target.$attrs.'>'.$content.'</a>';
		}
		$value=isset($button['value']) && is_scalar($button['value']) ? ' data-dp-panel-field-button-value="'.self::e((string)$button['value']).'"' : '';
		$copyMode=($action==='copy' && ($button['copy_normalized'] ?? false)===true) ? ' data-dp-panel-field-button-copy="normalized"' : '';
		return '<button type="button" class="'.$class.'" data-dp-panel-field-button="'.self::e($action).'" title="'.self::e($title).'"'.$value.$copyMode.$attrs.'>'.$content.'</button>';
	}

	/**
	 * Renders safe passthrough attributes for field button metadata.
	 *
	 * Only `data-*` and `aria-*` attributes are accepted so arbitrary event or style
	 * injection cannot cross the form metadata boundary.
	 *
	 * @param array<string, mixed> $button Button metadata.
	 * @return string Escaped attribute fragment.
	 */
	private static function inputButtonAttributeHtml(array $button): string {
		$attrs='';
		$attributes=is_array($button['attributes'] ?? null) ? $button['attributes'] : [];
		foreach($attributes as $name=>$value){
			$name=strtolower(trim((string)$name));
			if($name==='' || (!str_starts_with($name, 'data-') && !str_starts_with($name, 'aria-'))){
				continue;
			}
			if(is_bool($value)){
				$attrs.=$value ? ' '.$name : '';
				continue;
			}
			if(is_scalar($value)){
				$attrs.=' '.$name.'="'.self::e((string)$value).'"';
			}
		}
		return $attrs;
	}

	/**
	 * Renders textarea, code, markdown, HTML, and rich text editor controls.
	 *
	 * The method builds the source textarea, optional adornment shell, preview,
	 * toolbar, mode switcher, and contenteditable visual surface required by the
	 * Panel editor runtime. Rich modes hide the source shell initially while still
	 * keeping the textarea as the canonical submitted value.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current editor value.
	 * @param string $required Prebuilt required attribute fragment.
	 * @param string $readonly Prebuilt readonly attribute fragment.
	 * @param string $placeholder Prebuilt placeholder attribute fragment.
	 * @return string Editor or textarea control HTML.
	 */
	private static function textareaControl(string $name, array $meta, mixed $value, string $required, string $readonly, string $placeholder): string {
		$type=(string)($meta['type'] ?? 'textarea');
		$editor=(string)($meta['meta']['editor'] ?? (in_array($type, ['markdown', 'html', 'code', 'rich_editor', 'rich_text'], true) ? $type : 'plain'));
		$class='dp-panel-textarea-'.$type;
		if($editor!=='' && $editor!=='plain'){
			$class.=' dp-panel-editor-control';
		}
		if(Resource::normalizeName($editor)==='code'){
			$class.=' dp-panel-code-editor-control';
		}
		$rows=max(1, min(60, (int)($meta['meta']['rows'] ?? 5)));
		$codeLanguage=Resource::normalizeName((string)($meta['meta']['code_language'] ?? 'plain')) ?: 'plain';
		$codeAttrs=Resource::normalizeName($editor)==='code'
			? ' data-dp-panel-code-editor="1" data-dp-panel-code-language="'.self::e($codeLanguage).'" spellcheck="false" autocapitalize="off" autocomplete="off"'
			: '';
		$control='<textarea name="'.self::e($name).'" class="'.self::e($class).'"'.$required.$readonly.$placeholder.self::textAttributeHtml($meta).' rows="'.self::e((string)$rows).'" data-dp-panel-editor-source="1"'.$codeAttrs.'>'
			.self::e(self::stringValue($value)).'</textarea>';
		$control=self::textInputShell($meta, $control);
		$previewEnabled=($meta['meta']['preview'] ?? in_array($type, ['markdown', 'html', 'code', 'rich_editor', 'rich_text'], true))===true;
		if(!$previewEnabled && !in_array($type, ['markdown', 'html', 'code', 'rich_editor', 'rich_text'], true)){
			return $control;
		}
		$mode=(string)($meta['meta']['preview_mode'] ?? $editor ?: $type);
		$normalizedEditor=Resource::normalizeName($editor);
		$hasVisualEditor=in_array($normalizedEditor, ['html', 'rich_editor', 'rich_text'], true);
		$hasWritePreviewEditor=in_array($normalizedEditor, ['markdown', 'html', 'rich_editor', 'rich_text'], true);
		if($hasVisualEditor){
			$control=str_replace('<div class="dp-panel-input-shell"', '<div class="dp-panel-input-shell" hidden data-dp-panel-editor-source-shell="1"', $control);
		}
		$preview=$previewEnabled ? self::editorPreviewHtml($mode, self::stringValue($value), $codeLanguage ?? 'plain') : '';
		if($hasWritePreviewEditor && $preview!==''){
			$preview=str_replace('class="dp-panel-editor-preview', 'hidden class="dp-panel-editor-preview', $preview);
		}
		$buttons=in_array($normalizedEditor, ['markdown', 'html', 'rich_editor', 'rich_text'], true)
			? '<div class="dp-panel-editor-tools" role="toolbar" aria-label="'.self::e(self::editorLabel($mode)).' tools">'
				.'<span class="dp-panel-editor-tool-group" data-dp-panel-editor-tool-group="history">'
				.'<button type="button" data-dp-panel-editor-command="undo" title="'.self::e(self::panelText('editor.undo')).'">'.self::e(self::panelText('editor.undo')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="redo" title="'.self::e(self::panelText('editor.redo')).'">'.self::e(self::panelText('editor.redo')).'</button>'
				.'</span>'
				.'<span class="dp-panel-editor-tool-group" data-dp-panel-editor-tool-group="block">'
				.'<button type="button" data-dp-panel-editor-command="paragraph" title="'.self::e(self::panelText('editor.paragraph')).'" aria-pressed="false">P</button>'
				.'<button type="button" data-dp-panel-editor-command="heading_1" title="'.self::e(self::panelText('editor.heading_1')).'" aria-pressed="false">H1</button>'
				.'<button type="button" data-dp-panel-editor-command="heading_2" title="'.self::e(self::panelText('editor.heading_2')).'" aria-pressed="false">H2</button>'
				.'<button type="button" data-dp-panel-editor-command="heading_3" title="'.self::e(self::panelText('editor.heading_3')).'" aria-pressed="false">H3</button>'
				.'</span>'
				.'<span class="dp-panel-editor-tool-group" data-dp-panel-editor-tool-group="inline">'
				.'<button type="button" data-dp-panel-editor-command="bold" title="'.self::e(self::panelText('editor.bold')).'" aria-pressed="false"><strong>B</strong></button>'
				.'<button type="button" data-dp-panel-editor-command="italic" title="'.self::e(self::panelText('editor.italic')).'" aria-pressed="false"><em>I</em></button>'
				.'<button type="button" data-dp-panel-editor-command="underline" title="'.self::e(self::panelText('editor.underline')).'" aria-pressed="false"><u>U</u></button>'
				.'<button type="button" data-dp-panel-editor-command="strike" title="'.self::e(self::panelText('editor.strikethrough')).'" aria-pressed="false"><s>S</s></button>'
				.'</span>'
				.'<span class="dp-panel-editor-tool-group" data-dp-panel-editor-tool-group="insert">'
				.'<button type="button" data-dp-panel-editor-command="link" title="'.self::e(self::panelText('editor.link')).'" aria-pressed="false">'.self::e(self::panelText('editor.link')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="unlink" title="'.self::e(self::panelText('editor.unlink', [], 'Remove link')).'" aria-pressed="false">'.self::e(self::panelText('editor.unlink')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="unordered_list" title="'.self::e(self::panelText('editor.bulleted_list')).'" aria-pressed="false">'.self::e(self::panelText('editor.list')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="ordered_list" title="'.self::e(self::panelText('editor.numbered_list')).'" aria-pressed="false">1.</button>'
				.'<button type="button" data-dp-panel-editor-command="quote" title="'.self::e(self::panelText('editor.quote')).'" aria-pressed="false">'.self::e(self::panelText('editor.quote')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="code" title="'.self::e(self::panelText('editor.code')).'" aria-pressed="false">'.self::e(self::panelText('editor.code')).'</button>'
				.'<button type="button" data-dp-panel-editor-command="code_block" title="'.self::e(self::panelText('editor.code_block')).'" aria-pressed="false">&lt;/&gt;</button>'
				.'<button type="button" data-dp-panel-editor-command="hr" title="'.self::e(self::panelText('editor.divider')).'">HR</button>'
				.'</span>'
				.'<span class="dp-panel-editor-tool-group" data-dp-panel-editor-tool-group="cleanup">'
				.'<button type="button" data-dp-panel-editor-command="clear_format" title="'.self::e(self::panelText('editor.clear_formatting')).'">'.self::e(self::panelText('common.clear')).'</button>'
				.'</span>'
			.'</div>'
			: '';
		$modeSwitch=$hasWritePreviewEditor
			? '<div class="dp-panel-editor-mode-switch" role="group" aria-label="'.self::e(self::panelText('editor.preview')).'">'
				.'<button type="button" data-dp-panel-editor-view="write" title="'.self::e(self::panelText('editor.write')).'" aria-pressed="false">'.self::e(self::panelText('editor.write')).'</button>'
				.'<button type="button" data-dp-panel-editor-view="preview" title="'.self::e(self::panelText('editor.preview')).'" aria-pressed="false">'.self::e(self::panelText('editor.preview')).'</button>'
			.'</div>'
			: '';
		$toolbar='<div class="dp-panel-editor-toolbar">'
			.'<span>'.self::e(self::editorLabel($mode)).'</span>'
			.$buttons
			.$modeSwitch
			.($previewEnabled ? '<small data-dp-panel-editor-status>'.self::e(self::panelText('editor.write')).'</small>' : '')
			.'</div>';
		$visualPlaceholder=self::panelText('editor.start_writing');
		if(preg_match('/ placeholder="([^"]*)"/', $placeholder, $placeholderMatch)){
			$visualPlaceholder=$placeholderMatch[1];
		}
		else{
			$visualPlaceholder=self::e($visualPlaceholder);
		}
		$visual=in_array(Resource::normalizeName($editor), ['html', 'rich_editor', 'rich_text'], true)
			? '<div class="dp-panel-editor-visual" contenteditable="'.($readonly!=='' ? 'false' : 'true').'" role="textbox" aria-multiline="true" aria-label="'.$visualPlaceholder.'" data-dp-panel-editor-visual="1" data-dp-panel-editor-placeholder="'.$visualPlaceholder.'" data-dp-panel-editor-empty="1"></div>'
			: '';
		$editorAttrs=Resource::normalizeName($editor)==='code'
			? ' data-dp-panel-code-language="'.self::e($codeLanguage).'"'
			: '';
		$initialMode=$hasWritePreviewEditor ? 'write' : 'source';
		return '<div class="dp-panel-editor" data-dp-panel-editor="'.self::e($editor).'"'.$editorAttrs.' data-dp-panel-editor-mode="'.$initialMode.'">'.$toolbar.$visual.$control.$preview.'</div>';
	}

	/**
	 * Renders the initial server-side preview for editor content.
	 *
	 * Markdown receives a lightweight escaped preview, rich HTML is sanitized, code
	 * is rendered inside a pre/code pair, and plain text preserves line breaks.
	 *
	 * @param string $mode Editor preview mode.
	 * @param string $value Current editor value.
	 * @param string $codeLanguage Code language token for code previews.
	 * @return string Preview HTML.
	 */
	private static function editorPreviewHtml(string $mode, string $value, string $codeLanguage='plain'): string {
		$value=trim($value);
		if($value===''){
			return '<div class="dp-panel-editor-preview dp-panel-editor-preview-empty">'.self::e(self::panelText('client.editor_preview_empty')).'</div>';
		}
		$mode=Resource::normalizeName($mode);
		if($mode==='markdown'){
			$preview=preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', self::e($value));
			$preview=preg_replace('/`(.+?)`/', '<code>$1</code>', $preview ?? self::e($value));
			$preview=str_replace(["\r\n", "\r", "\n"], '<br>', $preview ?? self::e($value));
			return '<div class="dp-panel-editor-preview dp-panel-editor-preview-markdown">'.$preview.'</div>';
		}
		if($mode==='html' || $mode==='rich_editor' || $mode==='rich_text'){
			return '<div class="dp-panel-editor-preview dp-panel-editor-preview-html">'.self::safeRichHtml($value).'</div>';
		}
		if($mode==='code'){
			return '<pre class="dp-panel-editor-preview dp-panel-editor-preview-code" data-dp-panel-code-language="'.self::e($codeLanguage).'"><code>'.self::e($value).'</code></pre>';
		}
		return '<div class="dp-panel-editor-preview">'.nl2br(self::e($value)).'</div>';
	}

	/**
	 * Produces a human label for editor mode UI.
	 *
	 * @param string $mode Editor mode token.
	 * @return string Display label.
	 */
	private static function editorLabel(string $mode): string {
		return match(Resource::normalizeName($mode)){
			'markdown'=>'Markdown',
			'html'=>'HTML',
			'code'=>'Code',
			'rich_editor', 'rich_text'=>'Rich text',
			default=>'Text',
		};
	}

	/**
	 * Sanitizes rich HTML accepted by server-rendered Panel previews.
	 *
	 * Scriptable and embedded elements are removed, the tag set is limited to
	 * editor-supported formatting elements, event/style attributes are stripped,
	 * javascript links are rejected, and malformed inline/block nesting is repaired.
	 *
	 * @param string $value Raw rich HTML.
	 * @return string Sanitized rich HTML.
	 */
	private static function safeRichHtml(string $value): string {
		$value=preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $value) ?? $value;
		$value=strip_tags($value, '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote><pre><code><h1><h2><h3><h4><h5><h6><hr>');
		$value=preg_replace('/\s(on[a-z]+|style)\s*=\s*(["\']).*?\2/i', '', $value) ?? $value;
		$value=preg_replace('/\s(href)\s*=\s*(["\'])\s*javascript:.*?\2/i', '', $value) ?? $value;
		for($pass=0;$pass<4;$pass++){
			$value=preg_replace('/<(strong|b|em|i|u|s|a|code)([^>]*)>\s*<(p|li|blockquote|pre|h[1-6])([^>]*)>(.*?)<\/\3>\s*<\/\1>/is', '<$3$4><$1$2>$5</$1></$3>', $value) ?? $value;
		}
		for($pass=0;$pass<4;$pass++){
			$value=preg_replace('/<([a-z0-9]+)(?:\s[^>]*)?>\s*(?:&nbsp;|\s)*<\/\1>/i', '', $value) ?? $value;
			$value=preg_replace('/<(ul|ol)>\s*<\/\1>/i', '', $value) ?? $value;
		}
		return $value;
	}

	/**
	 * Renders input attributes derived from field metadata and formatter policy.
	 *
	 * The fragment combines native constraints, mask and semantic format markers,
	 * datalist linkage, autocomplete flags, clearability, and submit-normalization
	 * metadata consumed by the Panel client runtime.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param string $name Optional field name used for datalist id linkage.
	 * @return string Escaped attribute fragment.
	 */
	private static function inputAttributeHtml(array $meta, string $name=''): string {
		$attrs='';
		foreach(['min', 'max', 'step'] as $key){
			if(array_key_exists($key, $meta['meta'] ?? []) && is_scalar($meta['meta'][$key])){
				$attrs.=' '.$key.'="'.self::e((string)$meta['meta'][$key]).'"';
			}
		}
		$attrs.=self::maskLengthAttributeHtml($meta);
		$attrs.=self::maskPatternAttributeHtml($meta);
		$attrs.=self::maskTitleAttributeHtml($meta);
		$attrs.=self::formatPatternAttributeHtml($meta);
		$attrs.=self::formatTitleAttributeHtml($meta);
		$attrs.=self::explicitFormatAttributeMarkers($meta);
		foreach(['min_length'=>'minlength', 'max_length'=>'maxlength', 'pattern'=>'pattern', 'input_mode'=>'inputmode', 'autocomplete'=>'autocomplete', 'title'=>'title', 'mask'=>'data-dp-panel-mask'] as $key=>$attribute){
			if(array_key_exists($key, $meta['meta'] ?? []) && is_scalar($meta['meta'][$key]) && (string)$meta['meta'][$key]!==''){
				$attrs.=' '.$attribute.'="'.self::e((string)$meta['meta'][$key]).'"';
			}
		}
		if((array_key_exists('mask', $meta['meta'] ?? []) || array_key_exists('format_rule', $meta['meta'] ?? [])) && array_key_exists('format_event', $meta['meta'] ?? []) && is_scalar($meta['meta']['format_event']) && (string)$meta['meta']['format_event']!==''){
			$attrs.=' data-dp-panel-format-event="'.self::e(Resource::normalizeName((string)$meta['meta']['format_event'])).'"';
		}
		if(array_key_exists('format_rule', $meta['meta'] ?? []) && is_scalar($meta['meta']['format_rule']) && (string)$meta['meta']['format_rule']!==''){
			$attrs.=' data-dp-panel-format="'.self::e((string)$meta['meta']['format_rule']).'"';
			if(isset($meta['meta']['format_options']) && is_array($meta['meta']['format_options'])){
				$attrs.=' data-dp-panel-format-options="'.self::e((string)json_encode($meta['meta']['format_options'], JSON_UNESCAPED_SLASHES)).'"';
			}
		}
		$attrs.=self::submitNormalizationAttributeHtml($meta);
		if(($meta['meta']['clearable'] ?? false)===true){
			$attrs.=' data-dp-panel-clearable="1"';
		}
		if($name!=='' && self::suggestions($meta)!==[]){
			$attrs.=' list="'.self::e(self::datalistId($name)).'"';
		}
		if(in_array(Resource::normalizeName((string)($meta['type'] ?? '')), ['autocomplete', 'combobox', 'combo_box'], true)){
			$attrs.=' data-dp-panel-autocomplete="1"';
		}
		return $attrs;
	}

	/**
	 * Resolves a placeholder attribute from explicit, mask, or format metadata.
	 *
	 * Explicit placeholders win. Mask placeholders can be disabled or overridden,
	 * and format placeholders fall back to examples that match the semantic rule.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped placeholder attribute or an empty string.
	 */
	private static function placeholderAttributeHtml(array $meta): string {
		if(isset($meta['placeholder']) && trim((string)$meta['placeholder'])!==''){
			return ' placeholder="'.self::e((string)$meta['placeholder']).'"';
		}
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$mask=trim((string)($fieldMeta['mask'] ?? ''));
		if($mask!==''){
			if(($fieldMeta['mask_placeholder'] ?? true)===false){
				return '';
			}
			$placeholder=$fieldMeta['mask_placeholder'] ?? true;
			if(is_string($placeholder) && trim($placeholder)!==''){
				return ' placeholder="'.self::e(trim($placeholder)).'"';
			}
			return ' placeholder="'.self::e(self::maskPlaceholderFromPattern($mask)).'"';
		}
		if(($fieldMeta['format_placeholder'] ?? true)===false){
			return '';
		}
		$placeholder=$fieldMeta['format_placeholder'] ?? true;
		if(is_string($placeholder) && trim($placeholder)!==''){
			return ' placeholder="'.self::e(trim($placeholder)).'"';
		}
		$placeholder=self::formatPlaceholderFromRule(Resource::normalizeName((string)($fieldMeta['format_rule'] ?? '')), $fieldMeta);
		return $placeholder!=='' ? ' placeholder="'.self::e($placeholder).'"' : '';
	}

	/**
	 * Converts a mask pattern into a human-readable placeholder example.
	 *
	 * @param string $mask Mask pattern using Panel mask tokens.
	 * @return string Placeholder text.
	 */
	private static function maskPlaceholderFromPattern(string $mask): string {
		return strtr($mask, [
			'9'=>'0',
			'A'=>'A',
			'a'=>'a',
			'*'=>'X',
		]);
	}

	/**
	 * Provides placeholder examples for semantic formatting rules.
	 *
	 * @param string $rule Normalized format rule.
	 * @param array<string, mixed> $meta Field metadata used for format options.
	 * @return string Placeholder example or an empty string.
	 */
	private static function formatPlaceholderFromRule(string $rule, array $meta): string {
		if($rule===''){
			return '';
		}
		return match($rule){
			'phone'=>'+1 000 000 0000',
			'phone_us', 'phone_ca'=>'(000) 000-0000',
			'zip_code_us', 'postal_code_us', 'zip'=>'00000-0000',
			'postal_code', 'postal'=>'Postal code',
			'postal_code_ca', 'canadian_postal_code'=>'A0A 0A0',
			'phone_international'=>'+1 000 000 0000',
			'postal_code_international'=>'Postal code',
			'credit_card', 'card'=>'0000 0000 0000 0000',
			'credit_card_expiry', 'card_expiry'=>'MM/YY',
			'card_cvc', 'cvc', 'cvv'=>'000',
			'iban'=>'CA00 0000 0000 0000 0000 0000',
			'currency', 'money'=>self::decimalPlaceholder((int)($meta['format_options']['decimals'] ?? 2)),
			'percent', 'percentage'=>self::decimalPlaceholder((int)($meta['format_options']['decimals'] ?? 1)),
			'email'=>'name@example.com',
			'url'=>'https://example.com',
			'map_url', 'maps_url'=>'https://www.google.com/maps?q=45.501689,-73.567256',
			'domain', 'hostname'=>'example.com',
			'timezone', 'time_zone'=>'America/Toronto',
			'locale', 'language_tag'=>'en-CA',
			'json', 'json_text'=>'{"key":"value"}',
			'mime_type', 'content_type'=>'application/json',
			'semver', 'semantic_version'=>'1.2.3',
			'cron_expression', 'cron'=>'0 9 * * mon-fri',
			'language_code', 'iso_language'=>'en',
			'country_code', 'iso_country'=>'CA',
			'subdivision_code', 'region_code'=>'QC',
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
	 * Builds a decimal placeholder with bounded precision.
	 *
	 * @param int $decimals Decimal precision requested by format options.
	 * @return string Placeholder decimal value.
	 */
	private static function decimalPlaceholder(int $decimals): string {
		$decimals=max(0, min(8, $decimals));
		return $decimals>0 ? '0.'.str_repeat('0', $decimals) : '0';
	}

	/**
	 * Adds maxlength from mask length unless metadata already supplies one.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped maxlength attribute or an empty string.
	 */
	private static function maskLengthAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(array_key_exists('max_length', $fieldMeta) || array_key_exists('maxlength', $fieldMeta)){
			return '';
		}
		$mask=trim((string)($fieldMeta['mask'] ?? ''));
		if($mask===''){
			return '';
		}
		return ' maxlength="'.self::e((string)strlen($mask)).'"';
	}

	/**
	 * Adds a native pattern attribute derived from a Panel mask.
	 *
	 * Explicit metadata patterns take precedence over generated mask patterns.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped pattern attribute or an empty string.
	 */
	private static function maskPatternAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(array_key_exists('pattern', $fieldMeta)){
			return '';
		}
		$mask=trim((string)($fieldMeta['mask'] ?? ''));
		if($mask===''){
			return '';
		}
		return ' pattern="'.self::e(self::patternFromMask($mask)).'"';
	}

	/**
	 * Adds a browser validation title for a mask-generated pattern.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped title attribute or an empty string.
	 */
	private static function maskTitleAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(array_key_exists('title', $fieldMeta) || array_key_exists('pattern', $fieldMeta)){
			return '';
		}
		$mask=trim((string)($fieldMeta['mask'] ?? ''));
		if($mask===''){
			return '';
		}
		return ' title="'.self::e('Expected format: '.self::maskPlaceholderFromPattern($mask)).'"';
	}

	/**
	 * Adds a native pattern attribute for a semantic format rule.
	 *
	 * Explicit patterns and masks take precedence because they carry stronger
	 * schema intent than the generic semantic-rule fallback.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped pattern attribute or an empty string.
	 */
	private static function formatPatternAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(array_key_exists('pattern', $fieldMeta) || trim((string)($fieldMeta['mask'] ?? ''))!==''){
			return '';
		}
		$pattern=self::patternFromFormatRule(Resource::normalizeName((string)($fieldMeta['format_rule'] ?? '')));
		return $pattern!=='' ? ' pattern="'.self::e($pattern).'"' : '';
	}

	/**
	 * Adds a validation title for a semantic format rule.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped title attribute or an empty string.
	 */
	private static function formatTitleAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(array_key_exists('title', $fieldMeta) || array_key_exists('pattern', $fieldMeta) || trim((string)($fieldMeta['mask'] ?? ''))!==''){
			return '';
		}
		$rule=Resource::normalizeName((string)($fieldMeta['format_rule'] ?? ''));
		if(self::patternFromFormatRule($rule)===''){
			return '';
		}
		if(in_array($rule, ['phone', 'phone_international'], true)){
			return ' title="'.self::e('Expected international phone number with country code.').'"';
		}
		$placeholder=self::formatPlaceholderFromRule($rule, $fieldMeta);
		return $placeholder!=='' ? ' title="'.self::e('Expected format: '.$placeholder).'"' : '';
	}

	/**
	 * Marks fields whose placeholder, pattern, or title was explicitly configured.
	 *
	 * The client formatter uses these flags to avoid replacing author-provided
	 * browser guidance during runtime locale or format refreshes.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped data attribute fragment.
	 */
	private static function explicitFormatAttributeMarkers(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$attrs='';
		if(isset($meta['placeholder']) || array_key_exists('placeholder', $fieldMeta)){
			$attrs.=' data-dp-panel-explicit-placeholder="1"';
		}
		if(array_key_exists('pattern', $fieldMeta)){
			$attrs.=' data-dp-panel-explicit-pattern="1"';
		}
		if(array_key_exists('title', $fieldMeta)){
			$attrs.=' data-dp-panel-explicit-title="1"';
		}
		return $attrs;
	}

	/**
	 * Converts Panel mask tokens to an HTML pattern literal.
	 *
	 * @param string $mask Mask pattern.
	 * @return string HTML pattern body.
	 */
	private static function patternFromMask(string $mask): string {
		$pattern='';
		$tokens=['9'=>'[0-9]', 'A'=>'[A-Z]', 'a'=>'[a-z]', '*'=>'[0-9A-Za-z]'];
		$length=strlen($mask);
		for($index=0;$index<$length;$index++){
			$char=$mask[$index];
			$pattern.=$tokens[$char] ?? self::htmlPatternLiteral($char);
		}
		return $pattern;
	}

	/**
	 * Provides native HTML pattern bodies for semantic format rules.
	 *
	 * Patterns are intentionally broad client-side hints; deeper validation lives
	 * in the Panel formatter runtime and server-side resource validation.
	 *
	 * @param string $rule Normalized format rule.
	 * @return string HTML pattern body or an empty string.
	 */
	private static function patternFromFormatRule(string $rule): string {
		return match($rule){
			'phone'=>'\+[0-9]{1,3}[0-9 \-]{5,18}',
			'phone_us', 'phone_ca'=>'(\+[0-9]{1,3} )?\([0-9]{3}\) [0-9]{3}-[0-9]{4}',
			'zip_code_us', 'postal_code_us', 'zip'=>'[0-9]{5}(-[0-9]{4})?',
			'postal_code', 'postal', 'postal_code_international'=>'[0-9A-Z][0-9A-Z ]{2,16}',
			'phone_international'=>'\+[0-9]{1,3}[0-9 \-]{5,18}',
			'postal_code_ca', 'canadian_postal_code'=>'[A-Z][0-9][A-Z] [0-9][A-Z][0-9]',
			'credit_card', 'card'=>'([0-9]{4} ){3}[0-9]{4}( [0-9]{1,3})?|([0-9]{4} ){3}[0-9]{1,3}',
			'credit_card_expiry', 'card_expiry'=>'(0[1-9]|1[0-2])/[0-9]{2}',
			'card_cvc', 'cvc', 'cvv'=>'[0-9]{3,4}',
			'iban'=>'[A-Z]{2}[0-9]{2}( [0-9A-Z]{4}){2,7}( [0-9A-Z]{1,4})?',
			'domain', 'hostname'=>'[A-Za-z0-9.-]{3,253}',
			'map_url', 'maps_url'=>'https://.+',
			'timezone', 'time_zone'=>'(UTC|GMT|[A-Za-z_]+/[A-Za-z0-9_+\-]+(/[A-Za-z0-9_+\-]+)?)',
			'locale', 'language_tag'=>'[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-[0-9A-Za-z]{5,8})*',
			'mime_type', 'content_type'=>'[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}(; .+)?',
			'semver', 'semantic_version'=>'v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?',
			'cron_expression', 'cron'=>'[0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+',
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
	 * Escapes a literal character for inclusion in an HTML pattern.
	 *
	 * @param string $char Single mask character.
	 * @return string Pattern-safe literal.
	 */
	private static function htmlPatternLiteral(string $char): string {
		return preg_match('/[\\\\^$.*+?()[\\]{}|\\/]/', $char)===1 ? '\\'.$char : $char;
	}

	/**
	 * Renders the submit-normalization marker consumed by the client runtime.
	 *
	 * Mask normalization is opt-in, while semantic format rules normalize by
	 * default unless metadata disables normalized submission.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped submit-normalization attribute or an empty string.
	 */
	private static function submitNormalizationAttributeHtml(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(($fieldMeta['mask_submit_normalized'] ?? false)===true && trim((string)($fieldMeta['mask'] ?? ''))!==''){
			return ' data-dp-panel-submit-normalized="mask"';
		}
		$rule=Resource::normalizeName((string)($fieldMeta['format_rule'] ?? ''));
		if($rule!=='' && ($fieldMeta['submit_normalized'] ?? true)===true){
			return ' data-dp-panel-submit-normalized="'.self::e($rule).'"';
		}
		return '';
	}

	/**
	 * Renders textarea attributes derived from text metadata and formatting rules.
	 *
	 * The textarea path mirrors input formatting metadata while also supporting
	 * auto-resize and preserving autocomplete behavior for multiline controls.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped attribute fragment.
	 */
	private static function textAttributeHtml(array $meta): string {
		$attrs='';
		$attrs.=self::maskLengthAttributeHtml($meta);
		$attrs.=self::maskPatternAttributeHtml($meta);
		$attrs.=self::maskTitleAttributeHtml($meta);
		$attrs.=self::formatPatternAttributeHtml($meta);
		$attrs.=self::formatTitleAttributeHtml($meta);
		$attrs.=self::explicitFormatAttributeMarkers($meta);
		foreach(['min_length'=>'minlength', 'max_length'=>'maxlength', 'placeholder'=>'placeholder', 'pattern'=>'pattern', 'input_mode'=>'inputmode', 'title'=>'title', 'mask'=>'data-dp-panel-mask'] as $key=>$attribute){
			if(array_key_exists($key, $meta['meta'] ?? []) && is_scalar($meta['meta'][$key]) && (string)$meta['meta'][$key]!==''){
				$attrs.=' '.$attribute.'="'.self::e((string)$meta['meta'][$key]).'"';
			}
		}
		if(array_key_exists('autocomplete', $meta['meta'] ?? []) && is_scalar($meta['meta']['autocomplete'])){
			$attrs.=' autocomplete="'.self::e((string)$meta['meta']['autocomplete']).'"';
		}
		if(($meta['meta']['auto_resize'] ?? false)===true){
			$attrs.=' data-dp-panel-auto-resize="1"';
		}
		if((array_key_exists('mask', $meta['meta'] ?? []) || array_key_exists('format_rule', $meta['meta'] ?? [])) && array_key_exists('format_event', $meta['meta'] ?? []) && is_scalar($meta['meta']['format_event']) && (string)$meta['meta']['format_event']!==''){
			$attrs.=' data-dp-panel-format-event="'.self::e(Resource::normalizeName((string)$meta['meta']['format_event'])).'"';
		}
		if(array_key_exists('format_rule', $meta['meta'] ?? []) && is_scalar($meta['meta']['format_rule']) && (string)$meta['meta']['format_rule']!==''){
			$attrs.=' data-dp-panel-format="'.self::e((string)$meta['meta']['format_rule']).'"';
			if(isset($meta['meta']['format_options']) && is_array($meta['meta']['format_options'])){
				$attrs.=' data-dp-panel-format-options="'.self::e((string)json_encode($meta['meta']['format_options'], JSON_UNESCAPED_SLASHES)).'"';
			}
		}
		$attrs.=self::submitNormalizationAttributeHtml($meta);
		return $attrs;
	}

	/**
	 * Renders select-choice behavior flags for client enhancement.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped choice attribute fragment.
	 */
	private static function choiceAttributeHtml(array $meta): string {
		$attrs='';
		if(($meta['meta']['searchable'] ?? false)===true){
			$attrs.=' data-dp-panel-searchable="1"';
		}
		if(($meta['meta']['native'] ?? true)===false){
			$attrs.=' data-dp-panel-native="0"';
		}
		if(($meta['meta']['clearable'] ?? false)===true){
			$attrs.=' data-dp-panel-clearable="1"';
		}
		return $attrs;
	}

	/**
	 * Extracts datalist suggestion metadata from a field.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return array Suggestion entries.
	 */
	private static function suggestions(array $meta): array {
		return is_array($meta['meta']['suggestions'] ?? null) ? $meta['meta']['suggestions'] : [];
	}

	/**
	 * Creates a stable datalist id from a field name.
	 *
	 * @param string $name Field name.
	 * @return string Datalist element id.
	 */
	private static function datalistId(string $name): string {
		return 'dp-panel-list-'.substr(sha1($name), 0, 12);
	}

	/**
	 * Renders a datalist for field suggestions.
	 *
	 * @param string $name Field name used to derive the datalist id.
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Datalist HTML or an empty string.
	 */
	private static function datalistHtml(string $name, array $meta): string {
		$suggestions=self::suggestions($meta);
		if($suggestions===[]){
			return '';
		}
		$options='';
		foreach($suggestions as $suggestion){
			if(!is_array($suggestion)){
				continue;
			}
			$value=(string)($suggestion['value'] ?? '');
			if($value===''){
				continue;
			}
			$label=(string)($suggestion['label'] ?? '');
			$options.='<option value="'.self::e($value).'"'.($label!=='' && $label!==$value ? ' label="'.self::e($label).'"' : '').'></option>';
		}
		return $options!=='' ? '<datalist id="'.self::e(self::datalistId($name)).'">'.$options.'</datalist>' : '';
	}

	/**
	 * Renders radio, checkbox-list, or segmented choice controls.
	 *
	 * Option metadata is flattened from grouped option structures, readonly
	 * multiple choices are mirrored with hidden inputs, and single-choice controls
	 * include an empty hidden value so unchecked radio groups submit predictably.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current selected value or values.
	 * @param bool $multiple Whether multiple selections are allowed.
	 * @return string Choice control HTML.
	 */
	private static function choiceControl(string $name, array $meta, mixed $value, bool $multiple): string {
		$type=Resource::normalizeName((string)($meta['type'] ?? ''));
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$segmented=in_array($type, ['toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'], true) || Resource::normalizeName((string)($fieldMeta['choice_style'] ?? ''))==='buttons';
		$values=$multiple ? self::selectedValues($value) : [self::stringValue($value)];
		$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
		$required=($meta['required'] ?? false) && !$multiple ? ' required' : '';
		$inputName=$multiple ? $name.'[]' : $name;
		$columns=max(1, min(6, (int)($fieldMeta['choice_columns'] ?? 1)));
		$inline=($fieldMeta['inline_choices'] ?? false)===true;
		$items='';
		foreach(self::flatOptionMetas($options) as $option){
			$optionValue=(string)$option['value'];
			$label=(string)$option['label'];
			$checked=in_array((string)$optionValue, $values, true) ? ' checked' : '';
			$id='dp-field-'.substr(sha1($name.'|'.$optionValue), 0, 12);
			$optionDisabled=$disabled.((($option['disabled'] ?? false)===true) ? ' disabled' : '');
			$description=trim((string)($option['description'] ?? ''));
			$items.='<label class="dp-panel-choice'.($segmented ? ' dp-panel-choice-button' : '').((($option['disabled'] ?? false)===true) ? ' dp-panel-choice-disabled' : '').'" for="'.$id.'">'
				.'<input id="'.$id.'" type="'.($multiple ? 'checkbox' : 'radio').'" name="'.self::e($inputName).'" value="'.self::e($optionValue).'"'.$checked.$optionDisabled.$required.'>'
				.'<span>'.self::e((string)$label).'</span>'
				.($description!=='' ? '<small>'.self::e($description).'</small>' : '')
				.'</label>';
		}
		$mirror=$multiple ? '' : '<input type="hidden" name="'.self::e($name).'" value="">';
		if(($meta['readonly'] ?? false) && $multiple){
			$mirror=self::hiddenListInputs($name, $value);
		}
		$attrs=' data-dp-panel-choice-list="'.($multiple ? 'multiple' : 'single').'" data-dp-panel-choice-columns="'.self::e((string)$columns).'"';
		if($inline){
			$attrs.=' data-dp-panel-choice-inline="1"';
		}
		if($segmented){
			$attrs.=' data-dp-panel-choice-style="buttons"';
		}
		$role=$multiple ? 'group' : 'radiogroup';
		$label=trim((string)($meta['label'] ?? self::humanFieldLabel($name)));
		return $mirror.'<div class="dp-panel-choice-list'.($inline ? ' dp-panel-choice-list-inline' : '').($segmented ? ' dp-panel-choice-list-buttons' : '').'" role="'.$role.'" aria-label="'.self::e($label).'"'.$attrs.' style="--dp-choice-columns:'.$columns.'">'.$items.'</div>';
	}

	/**
	 * Flattens option metadata to a value-label map.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Resource option definitions.
	 * @return array<string,string> Flat option labels keyed by value.
	 */
	private static function flatOptions(array $options): array {
		$flat=[];
		foreach(self::flatOptionMetas($options) as $option){
			$flat[(string)$option['value']]=(string)$option['label'];
		}
		return $flat;
	}

	/**
	 * Flattens nested option groups into normalized option metadata.
	 *
	 * Group disabled state cascades to descendants so readonly and unavailable
	 * options remain consistent across select, choice, and display renderers.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Resource option definitions.
	 * @param bool $groupDisabled Whether an ancestor option group is disabled.
	 * @return array<int,array<string,mixed>> Normalized option metadata entries.
	 */
	private static function flatOptionMetas(array $options, bool $groupDisabled=false): array {
		$flat=[];
		foreach($options as $value=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options'], $groupOptions['description'], $groupOptions['disabled']);
				$flat=array_merge($flat, self::flatOptionMetas($groupOptions, $groupDisabled || (($label['disabled'] ?? false)===true)));
				continue;
			}
			$flat[]=self::optionMeta($value, $label, $groupDisabled);
		}
		return $flat;
	}

	/**
	 * Normalizes a submitted or persisted value into selected option strings.
	 *
	 * JSON arrays are accepted for compatibility with stored multi-select values.
	 *
	 * @param mixed $value Current value.
	 * @return string[] Selected values.
	 */
	private static function selectedValues(mixed $value): array {
		if(is_array($value)){
			return array_values(array_filter(array_map(static fn(mixed $item): string => (string)$item, $value), static fn(string $item): bool => $item!==''));
		}
		if($value===null || $value===''){
			return [];
		}
		$decoded=json_decode((string)$value, true);
		if(is_array($decoded)){
			return self::selectedValues($decoded);
		}
		return [(string)$value];
	}

	/**
	 * Renders hidden mirror inputs for readonly multi-value controls.
	 *
	 * @param string $name Submitted field name.
	 * @param mixed $value Current selected values.
	 * @return string Hidden input HTML.
	 */
	private static function hiddenListInputs(string $name, mixed $value): string {
		$html='';
		foreach(self::selectedValues($value) as $item){
			$html.='<input type="hidden" name="'.self::e($name).'[]" value="'.self::e($item).'">';
		}
		return $html;
	}

	/**
	 * Serializes key-value array data into editable text.
	 *
	 * Only scalar and null values are emitted because nested values require a
	 * structured editor rather than the compact key-value textarea contract.
	 *
	 * @param array<string, mixed> $value Key-value data.
	 * @param string $keySeparator Separator between key and value.
	 * @param string $pairSeparator Separator between pairs.
	 * @return string Editable key-value text.
	 */
	private static function keyValueText(array $value, string $keySeparator='=', string $pairSeparator="\n"): string {
		$lines=[];
		foreach($value as $key=>$item){
			if(is_scalar($item) || $item===null){
				$lines[]=trim((string)$key).$keySeparator.trim((string)$item);
			}
		}
		return implode($pairSeparator, $lines);
	}

	/**
	 * Renders a native or custom file upload control.
	 *
	 * Custom uploaders are selected for drag/drop, chunked, or explicit uploader
	 * metadata. Native controls keep the current value summary beside the input.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current file value.
	 * @return string File control HTML.
	 */
	private static function fileControl(string $name, array $meta, mixed $value): string {
		$type=(string)($meta['type'] ?? 'file');
		$accepted=is_array($meta['accepted_types'] ?? null) ? $meta['accepted_types'] : (is_array($meta['meta']['accepted_types'] ?? null) ? $meta['meta']['accepted_types'] : []);
		$accept=$accepted!==[] ? ' accept="'.self::e(implode(',', array_map(static fn(mixed $type): string => trim((string)$type), $accepted))).'"' : '';
		$required=($meta['required'] ?? false) ? ' required' : '';
		$disabled=($meta['readonly'] ?? false) ? ' disabled' : '';
		$isMultiple=($meta['multiple'] ?? $meta['meta']['multiple'] ?? false) ? true : false;
		$multiple=$isMultiple ? ' multiple' : '';
		$current=self::fileCurrentValueHtml($value);
		if($type==='drag_drop_upload' || ($meta['meta']['custom_uploader'] ?? false)===true || ($meta['meta']['chunked_upload'] ?? false)===true){
			return self::customFileUploaderControl($name, $meta, $value, $accept, $required, $disabled, $multiple, $isMultiple, $current, $accepted);
		}
		$inputName=$isMultiple ? $name.'[]' : $name;
		return '<input type="file" name="'.self::e($inputName).'"'.$accept.$required.$disabled.$multiple.'>'.$current;
	}

	/**
	 * Renders the custom drag/drop chunked uploader shell.
	 *
	 * The shell embeds upload endpoints, retry/concurrency policy, storage hints,
	 * CSRF fields, custom request metadata, initial hidden value, client labels,
	 * policy copy, queue containers, and current-value summaries for the JavaScript
	 * uploader runtime.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current uploaded value.
	 * @param string $accept Native accept attribute fragment.
	 * @param string $required Native required attribute fragment.
	 * @param string $disabled Native disabled attribute fragment.
	 * @param string $multiple Native multiple attribute fragment.
	 * @param bool $isMultiple Whether multiple uploads are allowed.
	 * @param string $current Current file summary HTML.
	 * @param array<int, string> $accepted Accepted MIME or extension rules.
	 * @return string Custom uploader HTML.
	 */
	private static function customFileUploaderControl(string $name, array $meta, mixed $value, string $accept, string $required, string $disabled, string $multiple, bool $isMultiple, string $current, array $accepted=[]): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$endpoint=trim((string)($fieldMeta['upload_endpoint'] ?? ''));
		$deleteEndpoint=trim((string)($fieldMeta['upload_delete_endpoint'] ?? $fieldMeta['delete_endpoint'] ?? ''));
		if($endpoint===''){
			$endpoint=PanelConfig::uploadUrl();
		}
		if($deleteEndpoint===''){
			$deleteEndpoint=$endpoint;
		}
		$chunkSize=max(65536, min(52428800, (int)($fieldMeta['upload_chunk_size'] ?? 5242880)));
		$retries=max(0, min(10, (int)($fieldMeta['upload_retries'] ?? 3)));
		$concurrency=max(1, min(6, (int)($fieldMeta['upload_concurrency'] ?? 2)));
		$maxSize=max(0, (int)($meta['max_file_size'] ?? $fieldMeta['max_file_size'] ?? 0));
		$minFiles=max(0, (int)($fieldMeta['upload_min_files'] ?? $fieldMeta['min_files'] ?? (($meta['required'] ?? false) ? 1 : 0)));
		$maxFiles=max(0, (int)($fieldMeta['upload_max_files'] ?? $fieldMeta['max_files'] ?? ($isMultiple ? 0 : 1)));
		$storage=[
			'driver'=>(string)($fieldMeta['upload_driver'] ?? ''),
			'disk'=>(string)($fieldMeta['storage_disk'] ?? ''),
			'path'=>(string)($fieldMeta['storage_path'] ?? ''),
			'collection'=>(string)($meta['media_collection'] ?? $fieldMeta['media_collection'] ?? ''),
			'visibility'=>(string)($fieldMeta['visibility'] ?? ($fieldMeta['media_collection_manifest']['visibility'] ?? 'private')),
		];
		$storage=array_filter($storage, static fn(string $item): bool => trim($item)!=='');
		$headers=self::customFileUploaderHeaders($fieldMeta);
		$fields=self::customFileUploaderFields($fieldMeta);
		if(trim((string)($fieldMeta['upload_csrf_form'] ?? ''))!=='' && class_exists('\Dataphyre\Csrf')){
			$token=\Dataphyre\Csrf::value((string)$fieldMeta['upload_csrf_form']);
			if($token!==''){
				$fieldName=trim((string)($fieldMeta['upload_csrf_field'] ?? 'csrf')) ?: 'csrf';
				$header=trim((string)($fieldMeta['upload_csrf_header'] ?? 'X-CSRF-Token')) ?: 'X-CSRF-Token';
				$fields[$fieldName]=$token;
				if(preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header)===1){
					$headers[$header]=$token;
				}
			}
		}
		$initial=self::customFileUploaderInitialValue($value, $isMultiple);
		$labels=self::customFileUploaderLabels($fieldMeta);
		$acceptedLabel=self::acceptedFileTypesLabel($accepted, $labels);
		$policyItems=[];
		if($acceptedLabel!==''){
			$policyItems[]='<span><b>'.self::e($labels['policy_types']).'</b> '.self::e($acceptedLabel).'</span>';
		}
		if($maxSize>0){
			$policyItems[]='<span><b>'.self::e($labels['policy_max']).'</b> '.self::e(strtr($labels['policy_max_value'], ['{size}'=>self::formatBytes($maxSize)])).'</span>';
		}
		if($minFiles>0 || $maxFiles>0){
			$range=$minFiles>0 ? strtr($labels['files_at_least'], ['{count}'=>(string)$minFiles]) : $labels['files_optional'];
			if($maxFiles>0){
				$range.=' / '.strtr($labels['files_up_to'], ['{count}'=>(string)$maxFiles]);
			}
			$policyItems[]='<span><b>'.self::e($labels['policy_files']).'</b> '.self::e($range).'</span>';
		}
		$policyItems[]='<span><b>'.self::e($labels['policy_transfer']).'</b> '.self::e(strtr($labels['policy_transfer_value'], [
			'{chunk_size}'=>self::formatBytes($chunkSize),
			'{retries}'=>(string)$retries,
			'{concurrency}'=>(string)$concurrency,
		])).'</span>';
		if(($storage['disk'] ?? '')!=='' || ($storage['collection'] ?? '')!==''){
			$policyItems[]='<span><b>'.self::e($labels['policy_storage']).'</b> '.self::e(trim(($storage['disk'] ?? $labels['storage_default']).(($storage['collection'] ?? '')!=='' ? ' / '.$storage['collection'] : ''))).'</span>';
		}
		$i18n=self::customFileUploaderClientLabels($labels);
		$attrs=' data-dp-panel-uploader="1"'
			.' data-dp-panel-uploader-name="'.self::e($name).'"'
			.' data-dp-panel-uploader-endpoint="'.self::e($endpoint).'"'
			.' data-dp-panel-uploader-delete-endpoint="'.self::e($deleteEndpoint).'"'
			.' data-dp-panel-uploader-chunk-size="'.self::e((string)$chunkSize).'"'
			.' data-dp-panel-uploader-retries="'.self::e((string)$retries).'"'
			.' data-dp-panel-uploader-concurrency="'.self::e((string)$concurrency).'"'
			.' data-dp-panel-uploader-multiple="'.($isMultiple ? '1' : '0').'"'
			.' data-dp-panel-uploader-max-size="'.self::e((string)$maxSize).'"'
			.' data-dp-panel-uploader-min-files="'.self::e((string)$minFiles).'"'
			.' data-dp-panel-uploader-max-files="'.self::e((string)$maxFiles).'"'
			.' data-dp-panel-uploader-accept-label="'.self::e($acceptedLabel).'"'
			.' data-dp-panel-uploader-i18n="'.self::e((string)json_encode($i18n, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'"';
		if($storage!==[]){
			$attrs.=' data-dp-panel-uploader-storage="'.self::e((string)json_encode($storage, JSON_UNESCAPED_SLASHES)).'"';
		}
		if($headers!==[]){
			$attrs.=' data-dp-panel-uploader-headers="'.self::e((string)json_encode($headers, JSON_UNESCAPED_SLASHES)).'"';
		}
		if($fields!==[]){
			$attrs.=' data-dp-panel-uploader-fields="'.self::e((string)json_encode($fields, JSON_UNESCAPED_SLASHES)).'"';
		}
		$hidden='<input type="hidden" name="'.self::e($name).'" value="'.self::e($initial).'">';
		$inputId='dp-panel-uploader-'.substr(sha1($name.'|'.json_encode($meta)), 0, 12);
		$button='<button type="button" class="dp-panel-button dp-panel-button-secondary" data-dp-panel-uploader-browse>'.self::e($labels['browse']).'</button>';
		$drop='<div class="dp-panel-uploader-drop" data-dp-panel-uploader-drop tabindex="0" role="button" aria-controls="'.self::e($inputId).'">'
			.'<div class="dp-panel-uploader-drop-copy"><strong>'.self::e($labels['drop_title']).'</strong><span>'.self::e($labels['drop_help']).'</span></div>'.$button.'</div>';
		$input='<input id="'.self::e($inputId).'" type="file"'.$accept.$disabled.$multiple.' data-dp-panel-uploader-input>';
		$policy='<div class="dp-panel-uploader-policy" data-dp-panel-uploader-policy>'.implode('', $policyItems).'</div>';
		$status='<div class="dp-panel-uploader-status" data-dp-panel-uploader-status aria-live="polite">'.self::e($labels['status_empty']).'</div>';
		$total='<div class="dp-panel-uploader-total" data-dp-panel-uploader-total hidden><span></span></div>';
		$queue='<div class="dp-panel-uploader-list" data-dp-panel-uploader-list></div>';
		return '<div class="dp-panel-uploader"'.$attrs.'>'.$hidden.$input.$drop.$policy.$status.$total.$queue.$current.'</div>';
	}

	/**
	 * Formats accepted file type metadata for human policy copy.
	 *
	 * @param mixed $accepted Accepted MIME or extension rules.
	 * @param array<string, string> $labels Uploader labels used as formatting templates.
	 * @return string Human-readable accepted type list.
	 */
	private static function acceptedFileTypesLabel(mixed $accepted, array $labels=[]): string {
		if(!is_array($accepted)){
			return '';
		}
		$wildcardLabel=(string)($labels['accepted_wildcard'] ?? '{type} files');
		$extensionLabel=(string)($labels['accepted_extension'] ?? '{extension}');
		$mimeLabel=(string)($labels['accepted_mime'] ?? '{mime}');
		$labels=[];
		foreach($accepted as $type){
			$type=trim((string)$type);
			if($type===''){
				continue;
			}
			if(str_ends_with($type, '/*')){
				$labels[]=strtr($wildcardLabel, ['{type}'=>ucfirst(strtolower(substr($type, 0, -2)))]);
			}
			elseif(str_starts_with($type, '.')){
				$labels[]=strtr($extensionLabel, ['{extension}'=>strtoupper($type)]);
			}
			else {
				$labels[]=strtr($mimeLabel, ['{mime}'=>$type]);
			}
		}
		return implode(', ', array_unique($labels));
	}

	/**
	 * Resolves uploader labels from defaults and field-level overrides.
	 *
	 * @param array<string, mixed> $fieldMeta Field metadata.
	 * @return array<string,string> Label map shared by server HTML and client runtime.
	 */
	private static function customFileUploaderLabels(array $fieldMeta): array {
		$defaults=[
			'browse'=>self::panelText('uploader.browse'),
			'drop_title'=>self::panelText('uploader.drop_title'),
			'drop_help'=>self::panelText('uploader.drop_help'),
			'status_empty'=>self::panelText('uploader.status_empty'),
			'policy_types'=>self::panelText('uploader.policy_types'),
			'policy_max'=>self::panelText('uploader.policy_max'),
			'policy_max_value'=>self::panelText('uploader.policy_max_value'),
			'policy_files'=>self::panelText('uploader.policy_files'),
			'files_at_least'=>self::panelText('uploader.files_at_least'),
			'files_optional'=>self::panelText('uploader.files_optional'),
			'files_up_to'=>self::panelText('uploader.files_up_to'),
			'policy_transfer'=>self::panelText('uploader.policy_transfer'),
			'policy_transfer_value'=>self::panelText('uploader.policy_transfer_value'),
			'policy_storage'=>self::panelText('uploader.policy_storage'),
			'storage_default'=>self::panelText('uploader.storage_default'),
			'accepted_wildcard'=>self::panelText('uploader.accepted_wildcard'),
			'accepted_extension'=>self::panelText('uploader.accepted_extension'),
			'accepted_mime'=>self::panelText('uploader.accepted_mime'),
			'status_complete'=>self::panelText('uploader.status_complete'),
			'status_failed'=>self::panelText('uploader.status_failed'),
			'status_uploading'=>self::panelText('uploader.status_uploading'),
			'constraint_min'=>self::panelText('uploader.constraint_min'),
			'constraint_max'=>self::panelText('uploader.constraint_max'),
			'file_singular'=>self::panelText('uploader.file_singular'),
			'file_plural'=>self::panelText('uploader.file_plural'),
			'remain_singular'=>self::panelText('uploader.remain_singular'),
			'remain_plural'=>self::panelText('uploader.remain_plural'),
			'delete_failed_http'=>self::panelText('uploader.delete_failed_http'),
			'delete_network_error'=>self::panelText('uploader.delete_network_error'),
			'uploaded_file'=>self::panelText('uploader.uploaded_file'),
			'queued'=>self::panelText('uploader.queued'),
			'move_up'=>self::panelText('uploader.move_up'),
			'move_down'=>self::panelText('uploader.move_down'),
			'up'=>self::panelText('uploader.up'),
			'down'=>self::panelText('uploader.down'),
			'retry'=>self::panelText('uploader.retry'),
			'remove'=>self::panelText('uploader.remove'),
			'stored_file'=>self::panelText('uploader.stored_file'),
			'file_removed'=>self::panelText('uploader.file_removed'),
			'removing'=>self::panelText('uploader.removing'),
			'remove_failed'=>self::panelText('uploader.remove_failed'),
			'upload_no_endpoint'=>self::panelText('uploader.upload_no_endpoint'),
			'uploading_percent'=>self::panelText('uploader.uploading_percent'),
			'upload_failed_http'=>self::panelText('uploader.upload_failed_http'),
			'upload_network_error'=>self::panelText('uploader.upload_network_error'),
			'upload_cancelled'=>self::panelText('uploader.upload_cancelled'),
			'upload_aborted'=>self::panelText('uploader.upload_aborted'),
			'retry_of'=>self::panelText('uploader.retry_of'),
			'uploading_file'=>self::panelText('uploader.uploading_file'),
			'waiting_slot'=>self::panelText('uploader.waiting_slot'),
			'chunk_stored'=>self::panelText('uploader.chunk_stored'),
			'complete'=>self::panelText('uploader.complete'),
			'stored_at'=>self::panelText('uploader.stored_at'),
			'stored_ready'=>self::panelText('uploader.stored_ready'),
			'cancelled'=>self::panelText('uploader.cancelled'),
			'cancel_detail'=>self::panelText('uploader.cancel_detail'),
			'upload_failed'=>self::panelText('uploader.upload_failed'),
			'retry_available'=>self::panelText('uploader.retry_available'),
			'wait_uploads'=>self::panelText('client.wait_uploads'),
			'too_large'=>self::panelText('uploader.too_large'),
			'too_large_detail'=>self::panelText('uploader.too_large_detail'),
			'too_large_status'=>self::panelText('uploader.too_large_status'),
			'configured_policy'=>self::panelText('uploader.configured_policy'),
			'type_not_accepted'=>self::panelText('uploader.type_not_accepted'),
			'type_not_accepted_detail'=>self::panelText('uploader.type_not_accepted_detail'),
			'type_not_accepted_status'=>self::panelText('uploader.type_not_accepted_status'),
			'remove_before_add'=>self::panelText('uploader.remove_before_add'),
			'only_more'=>self::panelText('uploader.only_more'),
			'transfer_detail'=>self::panelText('uploader.transfer_detail'),
			'transfer_speed'=>self::panelText('uploader.transfer_speed'),
		];
		$custom=is_array($fieldMeta['upload_labels'] ?? null) ? $fieldMeta['upload_labels'] : [];
		foreach($custom as $key=>$value){
			$key=trim((string)$key);
			if($key!=='' && is_scalar($value)){
				$defaults[$key]=trim((string)$value);
			}
		}
		return $defaults;
	}

	/**
	 * Filters uploader labels down to the subset needed by JavaScript.
	 *
	 * @param array<string, string> $labels Complete uploader label map.
	 * @return array<string,string> Client-facing label map.
	 */
	private static function customFileUploaderClientLabels(array $labels): array {
		$keys=[
			'status_empty', 'status_complete', 'status_failed', 'status_uploading',
			'constraint_min', 'constraint_max', 'file_singular', 'file_plural', 'remain_singular', 'remain_plural',
			'delete_failed_http', 'delete_network_error', 'uploaded_file', 'queued', 'move_up', 'move_down', 'up', 'down',
			'retry', 'remove', 'stored_file', 'file_removed', 'removing', 'remove_failed', 'upload_no_endpoint',
			'uploading_percent', 'upload_failed_http', 'upload_network_error', 'upload_cancelled', 'upload_aborted',
			'retry_of', 'uploading_file', 'waiting_slot', 'chunk_stored', 'complete', 'stored_at', 'stored_ready',
			'cancelled', 'cancel_detail', 'upload_failed', 'retry_available', 'too_large', 'too_large_detail',
			'wait_uploads', 'too_large_status', 'configured_policy', 'type_not_accepted', 'type_not_accepted_detail',
			'type_not_accepted_status', 'remove_before_add', 'only_more', 'transfer_detail', 'transfer_speed',
		];
		return array_intersect_key($labels, array_flip($keys));
	}

	/**
	 * Extracts safe custom upload headers from field metadata.
	 *
	 * Header names must satisfy the HTTP token grammar before being exposed to the
	 * client uploader runtime.
	 *
	 * @param array<string, mixed> $fieldMeta Field metadata.
	 * @return array<string,string> Header map.
	 */
	private static function customFileUploaderHeaders(array $fieldMeta): array {
		$headers=[];
		foreach(is_array($fieldMeta['upload_headers'] ?? null) ? $fieldMeta['upload_headers'] : [] as $name=>$value){
			$name=trim((string)$name);
			if($name!=='' && preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)===1 && is_scalar($value)){
				$headers[$name]=(string)$value;
			}
		}
		return $headers;
	}

	/**
	 * Extracts scalar custom upload form fields from field metadata.
	 *
	 * @param array<string, mixed> $fieldMeta Field metadata.
	 * @return array<string,string> Form field map.
	 */
	private static function customFileUploaderFields(array $fieldMeta): array {
		$fields=[];
		foreach(is_array($fieldMeta['upload_fields'] ?? null) ? $fieldMeta['upload_fields'] : [] as $name=>$value){
			$name=trim((string)$name);
			if($name!=='' && is_scalar($value)){
				$fields[$name]=(string)$value;
			}
		}
		return $fields;
	}

	/**
	 * Serializes the hidden value used by the custom uploader.
	 *
	 * Multiple uploaders always store a JSON array. Single uploaders accept an
	 * existing JSON object/string payload so already-persisted file metadata can
	 * round-trip without being reshaped.
	 *
	 * @param mixed $value Current uploaded value.
	 * @param bool $multiple Whether the uploader accepts multiple files.
	 * @return string Hidden input value.
	 */
	private static function customFileUploaderInitialValue(mixed $value, bool $multiple): string {
		if($value===null || $value===''){
			return $multiple ? '[]' : '';
		}
		if(is_string($value)){
			return $value;
		}
		if(is_array($value)){
			return (string)json_encode($multiple ? array_values($value) : $value, JSON_UNESCAPED_SLASHES);
		}
		return self::stringValue($value);
	}

	/**
	 * Renders a compact summary for the current native file value.
	 *
	 * @param mixed $value Current file value or values.
	 * @return string Current file summary HTML or an empty string.
	 */
	private static function fileCurrentValueHtml(mixed $value): string {
		if($value===null || $value===''){
			return '';
		}
		if(is_array($value) && isset($value['name']) && !is_array($value['name'])){
			$value=(string)$value['name'];
		}
		elseif(is_array($value)){
			$names=[];
			foreach($value as $item){
				if(is_array($item) && isset($item['name']) && !is_array($item['name'])){
					$names[]=(string)$item['name'];
				}
				elseif(is_scalar($item)){
					$names[]=(string)$item;
				}
			}
			$value=implode(', ', array_filter($names));
		}
		$value=self::stringValue($value);
		return $value!=='' ? '<small class="dp-panel-help">Current: '.self::e($value).'</small>' : '';
	}

	/**
	 * Renders form encoding when any nested field requires multipart submission.
	 *
	 * @param array<int|string, array<string, mixed>|string> $fields Form field metadata.
	 * @return string Form enctype attribute or an empty string.
	 */
	private static function formEncodingAttr(array $fields): string {
		return self::formHasFileField($fields) ? ' enctype="multipart/form-data"' : '';
	}

	/**
	 * Recursively checks whether a field tree contains a file-like field.
	 *
	 * Repeaters, grouped fields, and builder block fields are traversed so parent
	 * forms receive multipart encoding even when uploads are nested.
	 *
	 * @param array<int|string, array<string, mixed>|string> $fields Form field metadata.
	 * @return bool Whether any field requires multipart form encoding.
	 */
	private static function formHasFileField(array $fields): bool {
		foreach($fields as $field){
			if(!is_array($field)){
				continue;
			}
			if(self::isFileFieldType((string)($field['type'] ?? 'text'))){
				return true;
			}
			$children=is_array($field['repeater_fields'] ?? null) ? $field['repeater_fields'] : (is_array($field['meta']['repeater_fields'] ?? null) ? $field['meta']['repeater_fields'] : []);
			if($children===[]){
				$children=is_array($field['child_fields'] ?? null) ? $field['child_fields'] : (is_array($field['meta']['child_fields'] ?? null) ? $field['meta']['child_fields'] : []);
			}
			if($children===[]){
				$blocks=is_array($field['builder_blocks'] ?? null) ? $field['builder_blocks'] : (is_array($field['meta']['builder_blocks'] ?? null) ? $field['meta']['builder_blocks'] : []);
				foreach($blocks as $block){
					if(is_array($block) && is_array($block['fields'] ?? null) && self::formHasFileField($block['fields'])){
						return true;
					}
				}
			}
			if($children!==[] && self::formHasFileField($children)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether a field type is handled by the file renderer.
	 *
	 * @param string $type Field type.
	 * @return bool Whether the type is file-like.
	 */
	private static function isFileFieldType(string $type): bool {
		return in_array($type, ['file', 'file_upload', 'upload', 'drag_drop_upload', 'image'], true);
	}

	/**
	 * Renders a repeatable group of homogeneous child fields.
	 *
	 * Existing rows are normalized to arrays, minimum row counts are materialized,
	 * and a disabled template row is embedded for the client-side repeater runtime.
	 *
	 * @param string $name Submitted repeater field name.
	 * @param array<string, mixed> $meta Repeater field metadata.
	 * @param mixed $value Current repeater rows.
	 * @return string Repeater control HTML.
	 */
	private static function repeaterControl(string $name, array $meta, mixed $value): string {
		$fields=self::repeaterFieldMetas($meta);
		if($fields===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('form.no_repeater_fields')).'</p>';
		}
		$rows=is_array($value) ? array_values(array_filter($value, static fn(mixed $row): bool => is_array($row))) : [];
		$min=max(0, (int)($meta['meta']['min_items'] ?? 0));
		$max=max(0, (int)($meta['meta']['max_items'] ?? 0));
		while(count($rows)<$min){
			$rows[]=[];
		}
		$items='';
		foreach($rows as $index=>$row){
			$items.=self::repeaterRowHtml($name, $fields, $row, (string)$index, false);
		}
		$template=self::repeaterRowHtml($name, $fields, [], '__INDEX__', true);
		$addLabel=(string)($meta['meta']['add_item_label'] ?? 'Add item');
		$attrs=' data-dp-panel-repeater data-dp-panel-repeater-next="'.count($rows).'" data-dp-panel-repeater-min="'.self::e((string)$min).'" data-dp-panel-repeater-max="'.self::e((string)$max).'"';
		return '<div class="dp-panel-repeater"'.$attrs.'>'
			.'<div class="dp-panel-repeater-items" data-dp-panel-repeater-items>'.$items.'</div>'
			.'<template data-dp-panel-repeater-template>'.$template.'</template>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-repeater-add>'.self::e($addLabel).'</button>'
			.'</div>';
	}

	/**
	 * Renders a repeatable page-builder style field with selectable block types.
	 *
	 * Builder rows persist their `_type` discriminator, choose a default block for
	 * missing or invalid rows, and expose one disabled template per configured block.
	 *
	 * @param string $name Submitted builder field name.
	 * @param array<string, mixed> $meta Builder field metadata.
	 * @param mixed $value Current builder rows.
	 * @return string Builder control HTML.
	 */
	private static function builderControl(string $name, array $meta, mixed $value): string {
		$blocks=self::builderBlockMetas($meta);
		if($blocks===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('form.no_builder_blocks', [], 'No builder blocks configured.')).'</p>';
		}
		$rows=is_array($value) ? array_values(array_filter($value, static fn(mixed $row): bool => is_array($row))) : [];
		$min=max(0, (int)($meta['meta']['min_items'] ?? 0));
		$max=max(0, (int)($meta['meta']['max_items'] ?? 0));
		$defaultBlock=(string)array_key_first($blocks);
		while(count($rows)<$min){
			$rows[]=['_type'=>$defaultBlock];
		}
		$items='';
		foreach($rows as $index=>$row){
			$type=Resource::normalizeName((string)($row['_type'] ?? $row['type'] ?? $defaultBlock));
			if(!isset($blocks[$type])){
				$type=$defaultBlock;
			}
			$items.=self::builderRowHtml($name, $blocks[$type], $row, (string)$index, false);
		}
		$templates='';
		$buttons='';
		foreach($blocks as $blockName=>$block){
			$templates.='<template data-dp-panel-repeater-template data-dp-panel-builder-template="'.self::e($blockName).'">'.self::builderRowHtml($name, $block, ['_type'=>$blockName], '__INDEX__', true).'</template>';
			$buttons.='<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-repeater-add data-dp-panel-builder-add="'.self::e($blockName).'">'.self::e((string)$block['label']).'</button>';
		}
		$attrs=' data-dp-panel-repeater data-dp-panel-builder="1" data-dp-panel-repeater-next="'.count($rows).'" data-dp-panel-repeater-min="'.self::e((string)$min).'" data-dp-panel-repeater-max="'.self::e((string)$max).'"';
		return '<div class="dp-panel-repeater dp-panel-builder"'.$attrs.'>'
			.'<div class="dp-panel-repeater-items" data-dp-panel-repeater-items>'.$items.'</div>'
			.$templates
			.'<div class="dp-panel-builder-actions">'.$buttons.'</div>'
			.'</div>';
	}

	/**
	 * Normalizes builder block metadata by block name.
	 *
	 * Each block receives a stable name, display label, and normalized child field
	 * map so row rendering can reuse the standard field-control pipeline.
	 *
	 * @param array<string, mixed> $meta Builder field metadata.
	 * @return array<string,array<string,mixed>> Builder block metadata keyed by block name.
	 */
	private static function builderBlockMetas(array $meta): array {
		$blocks=[];
		foreach(is_array($meta['builder_blocks'] ?? null) ? $meta['builder_blocks'] : (is_array($meta['meta']['builder_blocks'] ?? null) ? $meta['meta']['builder_blocks'] : []) as $name=>$block){
			if(!is_array($block)){
				continue;
			}
			$blockName=Resource::normalizeName((string)($block['name'] ?? $name));
			if($blockName===''){
				continue;
			}
			$blocks[$blockName]=[
				'name'=>$blockName,
				'label'=>(string)($block['label'] ?? self::humanFieldLabel($blockName)),
				'fields'=>self::childFieldMetas(['child_fields'=>is_array($block['fields'] ?? null) ? $block['fields'] : []]),
			];
		}
		return $blocks;
	}

	/**
	 * Renders one builder row or client template row.
	 *
	 * Template rows disable controls so browser validation and submission ignore
	 * placeholder markup until the JavaScript repeater clones it into a real row.
	 *
	 * @param string $name Submitted builder field name.
	 * @param array<string, mixed> $block Builder block metadata.
	 * @param array<string, mixed> $row Current row values.
	 * @param string $index Submitted row index or template placeholder.
	 * @param bool $template Whether the row is rendered inside a template element.
	 * @return string Builder row HTML.
	 */
	private static function builderRowHtml(string $name, array $block, array $row, string $index, bool $template=false): string {
		$type=(string)($block['name'] ?? '');
		$controls='<input type="hidden" name="'.self::e($name.'['.$index.'][_type]').'" value="'.self::e($type).'">';
		foreach(($block['fields'] ?? []) as $childName=>$field){
			$fieldName=$name.'['.$index.']['.$childName.']';
			$value=$row[$childName] ?? ($field['default'] ?? '');
			$fieldLabel=self::fieldLabelHtml($field, (string)($field['label'] ?? $childName));
			$fieldHelp=isset($field['help']) ? '<small class="dp-panel-help">'.self::e((string)$field['help']).'</small>' : '';
			$control=self::fieldControl($fieldName, $field, $value);
			if($template){
				$control=str_replace(['<input ', '<select ', '<textarea ', '<button '], ['<input disabled ', '<select disabled ', '<textarea disabled ', '<button disabled '], $control);
			}
			$tag=self::isDisplayFieldType((string)($field['type'] ?? '')) ? 'div' : 'label';
			$class='dp-panel-field'.(self::isDisplayFieldType((string)($field['type'] ?? '')) ? ' dp-panel-field-display' : '');
			$controls.='<'.$tag.' class="'.$class.'">'.$fieldLabel.$control.$fieldHelp.'</'.$tag.'>';
		}
		return '<div class="dp-panel-repeater-row dp-panel-builder-row" data-dp-panel-repeater-row data-dp-panel-builder-row="'.self::e($type).'">'
			.'<div class="dp-panel-builder-row-header"><strong>'.self::e((string)($block['label'] ?? $type)).'</strong><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-repeater-remove>'.self::e(self::panelText('common.remove')).'</button></div>'
			.'<div class="dp-panel-repeater-grid dp-panel-builder-grid">'.$controls.'</div>'
			.'</div>';
	}

	/**
	 * Renders a grouped fieldset from nested child fields.
	 *
	 * Grouped values submit as `name[child]` fields. Address groups receive extra
	 * data markers used by the client formatter and accessibility policies.
	 *
	 * @param string $name Submitted group field name.
	 * @param array<string, mixed> $meta Group field metadata.
	 * @param mixed $value Current grouped value.
	 * @return string Fieldset HTML.
	 */
	private static function fieldGroupControl(string $name, array $meta, mixed $value): string {
		$fields=self::childFieldMetas($meta);
		if($fields===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('form.no_group_fields', [], 'No grouped fields configured.')).'</p>';
		}
		$row=is_array($value) ? $value : [];
		$controls='';
		foreach($fields as $childName=>$field){
			$fieldName=$name.'['.$childName.']';
			$childValue=$row[$childName] ?? ($field['default'] ?? '');
			$fieldLabel=self::fieldLabelHtml($field, (string)($field['label'] ?? $childName));
			$fieldHelp=isset($field['help']) ? '<small class="dp-panel-help">'.self::e((string)$field['help']).'</small>' : '';
			$control=self::fieldControl($fieldName, $field, $childValue);
			$tag=self::isDisplayFieldType((string)($field['type'] ?? '')) ? 'div' : 'label';
			$class='dp-panel-field'.(self::isDisplayFieldType((string)($field['type'] ?? '')) ? ' dp-panel-field-display' : '');
			$controls.='<'.$tag.' class="'.$class.'">'.$fieldLabel.$control.$fieldHelp.'</'.$tag.'>';
		}
		$legend=trim((string)($meta['label'] ?? ''));
		$legendHtml=$legend!=='' ? '<legend>'.self::e($legend).'</legend>' : '';
		$description=trim((string)($meta['meta']['description'] ?? ''));
		$descriptionHtml=$description!=='' ? '<small class="dp-panel-help">'.self::e($description).'</small>' : '';
		$type=Resource::normalizeName((string)($meta['type'] ?? ''));
		$isAddress=$type==='address';
		$class='dp-panel-fieldset'.($isAddress ? ' dp-panel-address' : '');
		$attrs=' data-dp-panel-fieldset="1"'.($isAddress ? ' data-dp-panel-address="1"' : '');
		$country=trim((string)($meta['meta']['address_country'] ?? ''));
		if($isAddress && $country!==''){
			$attrs.=' data-dp-panel-address-country="'.self::e($country).'"';
		}
		return '<fieldset class="'.$class.'"'.$attrs.'>'.$legendHtml.$descriptionHtml.'<div class="dp-panel-fieldset-grid">'.$controls.'</div></fieldset>';
	}

	/**
	 * Normalizes child field metadata from top-level or nested meta keys.
	 *
	 * @param array<string, mixed> $meta Parent field metadata.
	 * @return array<string,array<string,mixed>> Child field metadata keyed by normalized name.
	 */
	private static function childFieldMetas(array $meta): array {
		$fields=[];
		foreach(is_array($meta['child_fields'] ?? null) ? $meta['child_fields'] : (is_array($meta['meta']['child_fields'] ?? null) ? $meta['meta']['child_fields'] : []) as $field){
			if(!is_array($field)){
				continue;
			}
			$name=Resource::normalizeName((string)($field['name'] ?? ''));
			if($name===''){
				continue;
			}
			$fields[$name]=$field;
		}
		return $fields;
	}

	/**
	 * Normalizes repeater child field metadata from top-level or nested meta keys.
	 *
	 * @param array<string, mixed> $meta Repeater field metadata.
	 * @return array<string,array<string,mixed>> Repeater field metadata keyed by normalized name.
	 */
	private static function repeaterFieldMetas(array $meta): array {
		$fields=[];
		foreach(is_array($meta['repeater_fields'] ?? null) ? $meta['repeater_fields'] : (is_array($meta['meta']['repeater_fields'] ?? null) ? $meta['meta']['repeater_fields'] : []) as $field){
			if(!is_array($field)){
				continue;
			}
			$name=Resource::normalizeName((string)($field['name'] ?? ''));
			if($name===''){
				continue;
			}
			$fields[$name]=$field;
		}
		return $fields;
	}

	/**
	 * Renders one repeater row or disabled template row.
	 *
	 * @param string $name Submitted repeater field name.
	 * @param array<int|string, array<string, mixed>|string> $fields Normalized child field metadata.
	 * @param array<string, mixed> $row Current row values.
	 * @param string $index Submitted row index or template placeholder.
	 * @param bool $template Whether the row is rendered inside a template element.
	 * @return string Repeater row HTML.
	 */
	private static function repeaterRowHtml(string $name, array $fields, array $row, string $index, bool $template=false): string {
		$controls='';
		foreach($fields as $childName=>$field){
			$fieldName=$name.'['.$index.']['.$childName.']';
			$value=$row[$childName] ?? ($field['default'] ?? '');
			$fieldLabel=self::fieldLabelHtml($field, (string)($field['label'] ?? $childName));
			$fieldHelp=isset($field['help']) ? '<small class="dp-panel-help">'.self::e((string)$field['help']).'</small>' : '';
			$control=self::fieldControl($fieldName, $field, $value);
			if($template){
				$control=str_replace(['<input ', '<select ', '<textarea '], ['<input disabled ', '<select disabled ', '<textarea disabled '], $control);
			}
			$controls.='<label class="dp-panel-field">'.$fieldLabel.$control.$fieldHelp.'</label>';
		}
		return '<div class="dp-panel-repeater-row" data-dp-panel-repeater-row>'
			.'<div class="dp-panel-repeater-grid">'.$controls.'</div>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-repeater-remove>'.self::e(self::panelText('common.remove')).'</button>'
			.'</div>';
	}

	/**
	 * Renders option and optgroup HTML for select-like controls.
	 *
	 * Nested option groups preserve disabled and description metadata. Selected
	 * values are normalized once so scalar, array, and JSON-array values compare
	 * consistently.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Resource option definitions.
	 * @param mixed $selected Current selected value or values.
	 * @return string Option HTML.
	 */
	private static function optionHtml(array $options, mixed $selected): string {
		$html='';
		$selectedValues=self::selectedValues($selected);
		$listOptions=array_is_list($options);
		foreach($options as $value=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupLabel=(string)($label['label'] ?? $value);
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options'], $groupOptions['description'], $groupOptions['disabled']);
				$groupAttrs=' label="'.self::e($groupLabel).'"';
				if(($label['disabled'] ?? false)===true){
					$groupAttrs.=' disabled';
				}
				if(isset($label['description']) && trim((string)$label['description'])!==''){
					$groupAttrs.=' data-description="'.self::e(trim((string)$label['description'])).'"';
				}
				$html.='<optgroup'.$groupAttrs.'>'.self::optionHtml($groupOptions, $selected).'</optgroup>';
				continue;
			}
			$option=self::optionMeta($value, $label, false, $listOptions);
			$optionValue=(string)$option['value'];
			$isSelected=in_array($optionValue, $selectedValues, true) ? ' selected' : '';
			$attrs=' value="'.self::e($optionValue).'"'.$isSelected;
			if(($option['disabled'] ?? false)===true){
				$attrs.=' disabled';
			}
			$description=trim((string)($option['description'] ?? ''));
			if($description!==''){
				$attrs.=' title="'.self::e($description).'" data-description="'.self::e($description).'"';
			}
			$html.='<option'.$attrs.'>'.self::e((string)$option['label']).'</option>';
		}
		return $html;
	}

	/**
	 * Normalizes one option definition into renderer metadata.
	 *
	 * List-style option arrays use the label as value, while associative option
	 * arrays may provide value, label, description/help, and disabled state.
	 *
	 * @param string|int $value Source option key.
	 * @param mixed $label Source option label or metadata.
	 * @param bool $disabled Whether an ancestor group is disabled.
	 * @param bool $listOptions Whether the parent options array is a list.
	 * @return array{value:string,label:string,description:string,disabled:bool} Normalized option metadata.
	 */
	private static function optionMeta(string|int $value, mixed $label, bool $disabled=false, bool $listOptions=false): array {
		if(is_array($label)){
			return [
				'value'=>(string)($label['value'] ?? $value),
				'label'=>(string)($label['label'] ?? $label['name'] ?? $label['value'] ?? $value),
				'description'=>trim((string)($label['description'] ?? $label['help'] ?? '')),
				'disabled'=>$disabled || (($label['disabled'] ?? false)===true),
			];
		}
		if($listOptions && is_int($value)){
			$value=(string)$label;
		}
		return [
			'value'=>(string)$value,
			'label'=>(string)$label,
			'description'=>'',
			'disabled'=>$disabled,
		];
	}

	/**
	 * Detects whether an option array represents an optgroup.
	 *
	 * @param array<string, mixed> $option Option metadata.
	 * @return bool Whether the option should render as a group.
	 */
	private static function isOptionGroup(array $option): bool {
		if(isset($option['options']) && is_array($option['options'])){
			return true;
		}
		return !array_key_exists('value', $option) && !array_key_exists('label', $option) && !array_is_list($option);
	}

	/**
	 * Creates renderer metadata for a Field object in the current operation context.
	 *
	 * Field options are resolved late because option lists may depend on record,
	 * request, and operation state.
	 *
	 * @param Field $field Resource field definition.
	 * @param mixed $record Current record for edit/view operations.
	 * @param PanelRequest|null $request Current Panel request.
	 * @param string $operation Form operation.
	 * @return array Field metadata with resolved options.
	 */
	private static function fieldMeta(Field $field, mixed $record=null, ?PanelRequest $request=null, string $operation='form'): array {
		$meta=$field->toArray();
		$meta['options']=$field->optionsFor($record, $request, $operation);
		return $meta;
	}

	/**
	 * Checks whether a field has visibility dependencies.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return bool Whether the field is controlled by visible/hidden conditions.
	 */
	private static function fieldDependencyControlled(array $meta): bool {
		return ($meta['visible_when'] ?? [])!==[] || ($meta['hidden_when'] ?? [])!==[];
	}

	/**
	 * Renders data attributes consumed by the reactive form runtime.
	 *
	 * Dependency conditions, dynamic option flags, state update markers, live
	 * refresh behavior, and bounded debounce intervals are serialized as escaped
	 * data attributes on the field wrapper.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped dependency/runtime attribute fragment.
	 */
	private static function fieldDependencyAttrs(array $meta): string {
		$name=Resource::normalizeName((string)($meta['name'] ?? ''));
		$attrs=$name!=='' ? ' data-dp-panel-field-name="'.self::e($name).'"' : '';
		foreach(['depends_on', 'visible_when', 'hidden_when', 'required_when', 'required_unless'] as $key){
			$value=$meta[$key] ?? [];
			if($value===[]){
				continue;
			}
			$attrs.=' data-dp-panel-'.str_replace('_', '-', $key).'="'.self::e(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]').'"';
		}
		if(($meta['dynamic_options'] ?? false)===true){
			$attrs.=' data-dp-panel-dynamic-options="1"';
		}
		if(($meta['state_updates'] ?? false)===true){
			$attrs.=' data-dp-panel-state-updates="1"';
		}
		if(($meta['live'] ?? false)===true || ($meta['reactive'] ?? false)===true){
			$attrs.=' data-dp-panel-live="1"';
		}
		if(isset($meta['debounce_ms'])){
			$attrs.=' data-dp-panel-debounce-ms="'.self::e((string)max(0, min(5000, (int)$meta['debounce_ms']))).'"';
		}
		return $attrs;
	}

	/**
	 * Renders a complete field wrapper with label, control, help, errors, and policy attrs.
	 *
	 * Hidden fields bypass visible wrapper markup. Visible fields carry grid span
	 * styling, dependency metadata, accessibility policy attributes, required/type
	 * classes, and existing validation messages.
	 *
	 * @param string $name Submitted field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param mixed $value Current field value.
	 * @param array<int, string> $errors Validation messages for the field.
	 * @param bool $hiddenByDependency Whether the field should start hidden.
	 * @return string Field wrapper HTML.
	 */
	private static function fieldHtml(string $name, array $meta, mixed $value, array $errors=[], bool $hiddenByDependency=false): string {
		if(($meta['type'] ?? '')==='hidden'){
			return self::fieldControl($name, $meta, $value);
		}
		$help=isset($meta['help']) ? '<small class="dp-panel-help">'.self::e((string)$meta['help']).'</small>' : '';
		$errorHtml='';
		foreach($errors as $message){
			$errorHtml.='<small class="dp-panel-error">'.self::e((string)$message).'</small>';
		}
		$layoutMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$span=$layoutMeta['column_span'] ?? '1';
		$class='dp-panel-field';
		if(self::isBooleanType((string)($meta['type'] ?? ''))){
			$class.=' dp-panel-field-boolean';
		}
		if(self::isDisplayFieldType((string)($meta['type'] ?? ''))){
			$class.=' dp-panel-field-display';
		}
		if(($meta['required'] ?? false)===true){
			$class.=' dp-panel-field-required';
		}
		$style=self::gridItemStyle($layoutMeta);
		$style=$style!=='' ? ' style="'.self::e($style).'"' : '';
		$attrs=self::fieldDependencyAttrs($meta).self::fieldAccessibilityAttrs($meta);
		if($hiddenByDependency){
			$class.=' dp-panel-field-hidden';
		}
		if($span==='full'){
			$class.=' dp-panel-field-full';
		}
		elseif(!is_array($span) && (int)$span>1){
			$class.=' dp-panel-field-span-'.min(12, (int)$span);
		}
		$tag=self::isDisplayFieldType((string)($meta['type'] ?? '')) ? 'div' : 'label';
		return '<'.$tag.' class="'.$class.'"'.$style.$attrs.'>'
			.self::fieldLabelHtml($meta, (string)$meta['label'])
			.self::fieldControl($name, $meta, $value)
			.$help
			.$errorHtml
			.'</'.$tag.'>';
	}

	/**
	 * Renders field-level accessibility policy attributes.
	 *
	 * Fields may explicitly opt out of inherited policy processing or declare a
	 * policy that the client accessibility runtime can measure and enforce.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped accessibility attribute fragment.
	 */
	private static function fieldAccessibilityAttrs(array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(($fieldMeta['accessibility_inherit'] ?? true)===false){
			return ' data-dp-panel-a11y-disabled="1"';
		}
		$policy=is_array($fieldMeta['accessibility'] ?? null) ? $fieldMeta['accessibility'] : [];
		if($policy===[]){
			return '';
		}
		return self::accessibilityPolicyAttrs($policy, false);
	}

	/**
	 * Renders default accessibility policy attributes for a form or section container.
	 *
	 * @param array<string, mixed> $meta Container metadata.
	 * @return string Escaped default policy attribute fragment.
	 */
	private static function accessibilityDefaultAttrs(array $meta): string {
		$policy=is_array($meta['accessibility'] ?? null) ? $meta['accessibility'] : [];
		return $policy!==[] ? self::accessibilityPolicyAttrs($policy, true) : '';
	}

	/**
	 * Serializes accessibility policy configuration to client-readable attributes.
	 *
	 * Width, character, touch target, adornment, label, and contrast constraints are
	 * bounded and namespaced differently for default containers versus field-level
	 * policies.
	 *
	 * @param array<string, mixed> $policy Accessibility policy metadata.
	 * @param bool $default Whether the policy is inherited by child fields.
	 * @return string Escaped accessibility policy attributes.
	 */
	private static function accessibilityPolicyAttrs(array $policy, bool $default=false): string {
		$prefix=$default ? 'data-dp-panel-a11y-default' : 'data-dp-panel-a11y';
		$attrs=$default ? ' data-dp-panel-a11y-default="1"' : ' data-dp-panel-a11y-policy="1"';
		$width=(int)($policy['min_usable_width'] ?? 0);
		if($width>0){
			$unit=Resource::normalizeName((string)($policy['min_usable_width_unit'] ?? 'px'));
			$attrs.=' '.$prefix.'-min-usable-width="'.self::e((string)$width).'" '.$prefix.'-min-usable-width-unit="'.self::e($unit==='ch' ? 'ch' : 'px').'"';
		}
		$chars=(int)($policy['min_usable_chars'] ?? 0);
		if($chars>0){
			$attrs.=' '.$prefix.'-min-usable-chars="'.self::e((string)$chars).'"';
		}
		$touch=(int)($policy['min_touch_target'] ?? 0);
		if($touch>0){
			$attrs.=' '.$prefix.'-min-touch-target="'.self::e((string)$touch).'"';
		}
		$adornmentRatio=(float)($policy['max_adornment_ratio'] ?? 0);
		if($adornmentRatio>0){
			$attrs.=' '.$prefix.'-max-adornment-ratio="'.self::e((string)max(0.0, min(1.0, $adornmentRatio))).'"';
		}
		$labelRatio=(float)($policy['max_label_ratio'] ?? 0);
		if($labelRatio>0){
			$attrs.=' '.$prefix.'-max-label-ratio="'.self::e((string)max(0.0, min(1.0, $labelRatio))).'"';
		}
		$contrast=is_array($policy['contrast_policy'] ?? null) ? $policy['contrast_policy'] : [];
		if($contrast!==[]){
			$ratio=(float)($contrast['min_ratio'] ?? 4.5);
			$scope=Resource::normalizeName((string)($contrast['scope'] ?? 'control'));
			$attrs.=' '.$prefix.'-contrast-min="'.self::e((string)$ratio).'" '.$prefix.'-contrast-scope="'.self::e(in_array($scope, ['field', 'label', 'control', 'input'], true) ? $scope : 'control').'"';
		}
		return $attrs;
	}

	/**
	 * Renders relationship select metadata for the client relationship picker.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @return string Escaped relationship attribute fragment.
	 */
	private static function relationshipAttributeHtml(array $meta): string {
		$type=Resource::normalizeName((string)($meta['type'] ?? ''));
		if(!in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true)){
			return '';
		}
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$attrs=' data-dp-panel-relationship="1"';
		foreach(['related_resource'=>'related-resource', 'title_attribute'=>'title-attribute', 'key_attribute'=>'key-attribute'] as $key=>$attribute){
			if(isset($fieldMeta[$key]) && is_scalar($fieldMeta[$key]) && trim((string)$fieldMeta[$key])!==''){
				$attrs.=' data-dp-panel-relationship-'.$attribute.'="'.self::e((string)$fieldMeta[$key]).'"';
			}
		}
		return $attrs;
	}

	/**
	 * Wraps a select control in searchable-select UI when metadata requires it.
	 *
	 * Relationship fields can skip search below a configurable threshold unless
	 * force_search is set, keeping small select lists visually simple.
	 *
	 * @param string $name Field name.
	 * @param array<string, mixed> $meta Field metadata.
	 * @param string $selectHtml Rendered select HTML.
	 * @param bool $multiple Whether the select supports multiple values.
	 * @param int|null $optionCount Optional option count used for search threshold decisions.
	 * @return string Select HTML, optionally wrapped in searchable UI.
	 */
	private static function searchableSelectShell(string $name, array $meta, string $selectHtml, bool $multiple=false, ?int $optionCount=null): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		if(($fieldMeta['searchable'] ?? false)!==true){
			return $selectHtml;
		}
		$type=Resource::normalizeName((string)($meta['type'] ?? ''));
		$isRelationship=in_array($type, ['relationship', 'belongs_to', 'relation', 'multi_relationship', 'belongs_to_many'], true);
		$threshold=max(0, (int)($fieldMeta['search_threshold'] ?? ($isRelationship ? 12 : 0)));
		if(!$multiple && $isRelationship && ($fieldMeta['force_search'] ?? false)!==true && $threshold>0 && $optionCount!==null && $optionCount<=$threshold){
			return $selectHtml;
		}
		$searchId='dp-panel-select-search-'.substr(sha1($name), 0, 12);
		$label=trim((string)($meta['label'] ?? self::humanFieldLabel($name)));
		$placeholder=trim((string)($fieldMeta['search_placeholder'] ?? ($isRelationship ? self::panelText('select.search_relation_placeholder', ['label'=>$label]) : self::panelText('select.search_placeholder'))));
		$noResults=trim((string)($fieldMeta['no_results_text'] ?? self::panelText('select.no_results')));
		$attrs=' data-dp-panel-searchable-select="1" data-dp-panel-select-no-results="'.self::e($noResults).'"';
		$attrs.=$multiple ? ' data-dp-panel-searchable-select-multiple="1"' : '';
		$attrs.=$isRelationship ? ' data-dp-panel-relationship-picker="1"' : '';
		$class='dp-panel-searchable-select'.($isRelationship ? ' dp-panel-relationship-picker' : '');
		$search='<label class="dp-panel-searchable-select-search" for="'.self::e($searchId).'"><span class="dp-panel-sr-only">'.self::e($label.' '.self::panelText('common.search', [], 'search')).'</span><input id="'.self::e($searchId).'" type="search" value="" placeholder="'.self::e($placeholder).'" autocomplete="off" data-dp-panel-searchable-select-input></label>';
		$status='<small class="dp-panel-searchable-select-status" data-dp-panel-searchable-select-status aria-live="polite"></small>';
		return '<div class="'.$class.'"'.$attrs.'>'.$search.$selectHtml.$status.'</div>';
	}

	/**
	 * Counts leaf options inside nested option groups.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Resource option definitions.
	 * @return int Leaf option count.
	 */
	private static function optionCount(array $options): int {
		$count=0;
		foreach($options as $option){
			if(is_array($option) && self::isOptionGroup($option)){
				$groupOptions=is_array($option['options'] ?? null) ? $option['options'] : $option;
				unset($groupOptions['label'], $groupOptions['options'], $groupOptions['description'], $groupOptions['disabled']);
				$count+=self::optionCount($groupOptions);
				continue;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Renders a field label with optional hint text or hint icon.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param string $label Display label.
	 * @return string Field label HTML.
	 */
	private static function fieldLabelHtml(array $meta, string $label): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$hint=trim((string)($fieldMeta['hint'] ?? ''));
		$icon=trim((string)($fieldMeta['hint_icon'] ?? ''));
		$hintHtml='';
		if($hint!=='' || $icon!==''){
			$hintHtml='<small class="dp-panel-field-hint"'.($hint!=='' ? ' title="'.self::e($hint).'"' : '').'>'
				.($icon!=='' ? '<span class="dp-panel-field-hint-icon" aria-hidden="true">'.self::e(strtoupper(substr($icon, 0, 2))).'</span>' : '')
				.($hint!=='' ? '<span>'.self::e($hint).'</span>' : '')
				.'</small>';
		}
		return '<span class="dp-panel-field-label"><span class="dp-panel-field-label-text">'.self::e($label).'</span>'.$hintHtml.'</span>';
	}

	/**
	 * Returns dynamic option and visibility metadata for one reactive form field.
	 *
	 * The request identifies the form operation and target field. The response is
	 * JSON so client-side dependent selects can refresh option HTML, current
	 * value, visibility, required state, and dependency metadata without
	 * re-rendering the whole Panel form.
	 *
	 * @param Resource $resource Resource that owns the form definition.
	 * @param PanelRequest $request Reactive field request containing __panel_field and form input.
	 * @param mixed $record Current record for edit/view operations, or null for create operations.
	 * @return PanelPageResult JSON response containing field option state or a 404 error payload.
	 */
	public static function fieldOptions(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		[$form, $operation]=self::reactiveForm($resource, $request);
		if(!$form instanceof ResourceForm){
			return PanelPageResult::json(['error'=>'form_not_available'], 404);
		}
		$fieldName=Resource::normalizeName((string)$request->input('__panel_field', $request->query('__panel_field', '')));
		$field=$fieldName!=='' ? ($form->fieldsList()[$fieldName] ?? null) : null;
		if(!$field instanceof Field){
			return PanelPageResult::json(['error'=>'field_not_found'], 404);
		}
		$meta=self::fieldMeta($field, $record, $request, $operation);
		$value=$request->input($fieldName, self::recordValue($record, $fieldName, $meta['default'] ?? ''));
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		$visible=$field->isVisible($operation, $record, $request);
		$required=($meta['required'] ?? false)===true || ($meta['required_when'] ?? [])!==[] || ($meta['required_unless'] ?? [])!==[];
		PanelTrace::record('form.field_options', [
			'resource'=>$resource,
			'request'=>$request,
			'field'=>$fieldName,
			'operation'=>$operation,
			'option_count'=>count($options),
		]);
		return PanelPageResult::json([
			'field'=>$fieldName,
			'operation'=>$operation,
			'visible'=>$visible,
			'required'=>$required,
			'value'=>self::stringValue($value),
			'options'=>$options,
			'options_html'=>self::optionHtml($options, $value),
			'meta'=>[
				'dynamic_options'=>($meta['dynamic_options'] ?? false)===true,
				'depends_on'=>$meta['depends_on'] ?? [],
			],
		]);
	}

	/**
	 * Returns reactive form field state after hydration and optional validation.
	 *
	 * This endpoint supports live field updates, lifecycle hooks, and validation
	 * probes. It dehydrates submitted input, may run before-validate hooks, and
	 * returns field metadata that the browser can use to update visibility,
	 * errors, values, and generated controls.
	 *
	 * @param Resource $resource Resource that owns the form definition.
	 * @param PanelRequest $request Reactive form request containing operation, input, and validation flags.
	 * @param mixed $record Current record for edit/view operations, or null for create operations.
	 * @return PanelPageResult JSON response describing the current form field state.
	 */
	public static function fieldState(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		[$form, $operation]=self::reactiveForm($resource, $request);
		if(!$form instanceof ResourceForm){
			return PanelPageResult::json(['error'=>'form_not_available'], 404);
		}
		$validate=Resource::normalizeName((string)$request->input('__panel_validate', $request->query('__panel_validate', '')));
		$validateField=Resource::normalizeName((string)$request->input('__panel_validate_field', $request->query('__panel_validate_field', '')));
		$validatedState=null;
		$validatedFields=[];
		$lifecycleResult=null;
		if(in_array($validate, ['1', 'true', 'field', 'all'], true)){
			$lifecycleResult=$resource->runBeforeValidate($record, $operation, $request);
			if(!$lifecycleResult instanceof PanelLifecycleResult){
				$dehydrated=$form->dehydrate($request, $record, $operation);
				$validatedState=$form->validate($dehydrated->values(), $record, $request, $operation);
				$validatedState=$resource->runAfterValidate($validatedState, $record, $operation, $request);
				if($validatedState instanceof PanelLifecycleResult){
					$lifecycleResult=$validatedState;
					$validatedState=null;
				}
			}
			if($validate==='field' && $validateField!==''){
				$validatedFields=[$validateField];
			}
			else {
				$validatedFields=array_keys($form->fieldsList());
			}
		}
		$snapshot=$form->state($record, $request, $operation);
		$stateValues=$snapshot->values();
		$stateUpdates=$snapshot->stateUpdates();
		$serverValues=$snapshot->serverValues();
		$fields=[];
		foreach($form->fieldsList() as $field){
			$meta=self::fieldMeta($field, $record, $request, $operation);
			$name=(string)$meta['name'];
			if($name===''){
				continue;
			}
			$value=$stateValues[$name] ?? $request->input($name, self::recordValue($record, $name, $meta['default'] ?? ''));
			$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
			$stateUpdate=$stateUpdates[$name] ?? [];
			if(isset($stateUpdate['options']) && is_array($stateUpdate['options'])){
				$options=$stateUpdate['options'];
				$meta['dynamic_options']=true;
			}
			if(array_key_exists('help', $stateUpdate)){
				$meta['help']=$stateUpdate['help'] === null ? null : (string)$stateUpdate['help'];
			}
			if(array_key_exists('placeholder', $stateUpdate)){
				$meta['placeholder']=$stateUpdate['placeholder'] === null ? null : (string)$stateUpdate['placeholder'];
			}
			if(array_key_exists('readonly', $stateUpdate)){
				$meta['readonly']=(bool)$stateUpdate['readonly'];
			}
			$fieldValidated=in_array($name, $validatedFields, true);
			$errors=$fieldValidated && $validatedState instanceof PanelFormState ? $validatedState->fieldErrors($name) : [];
			if(isset($stateUpdate['errors'])){
				$computedErrors=is_array($stateUpdate['errors']) ? $stateUpdate['errors'] : [$stateUpdate['errors']];
				$errors=array_values(array_unique(array_filter(array_map(static fn(mixed $message): string => trim((string)$message), array_merge($errors, $computedErrors)))));
			}
			$visible=array_key_exists('visible', $stateUpdate) ? (bool)$stateUpdate['visible'] : $field->isVisible($operation, $record, $request);
			$required=array_key_exists('required', $stateUpdate) ? (bool)$stateUpdate['required'] : self::fieldRequiredForState($meta, $request);
			$fields[$name]=[
				'name'=>$name,
				'visible'=>$visible,
				'required'=>$required,
				'readonly'=>($meta['readonly'] ?? false)===true,
				'validated'=>$fieldValidated,
				'valid'=>$fieldValidated ? $errors===[] : null,
				'value'=>self::stringValue($value),
				'value_from_server'=>isset($serverValues[$name]),
				'force_value'=>($stateUpdate['force_value'] ?? $serverValues[$name]['force_value'] ?? false)===true,
				'propagate'=>($stateUpdate['propagate'] ?? $serverValues[$name]['propagate'] ?? false)===true,
				'help'=>$meta['help'] ?? null,
				'placeholder'=>$meta['placeholder'] ?? null,
				'errors'=>$errors,
				'options'=>$options,
				'options_html'=>self::optionHtml($options, $value),
				'dynamic_options'=>($meta['dynamic_options'] ?? false)===true,
				'state_updates'=>($meta['state_updates'] ?? false)===true,
				'depends_on'=>$meta['depends_on'] ?? [],
			];
		}
		PanelTrace::record('form.field_state', [
			'resource'=>$resource,
			'request'=>$request,
			'operation'=>$operation,
			'field_count'=>count($fields),
			'validated'=>$validatedState instanceof PanelFormState,
			'validated_fields'=>$validatedFields,
			'state'=>$snapshot,
		]);
		$errorPayload=$validatedState instanceof PanelFormState ? $validatedState->errors() : [];
		if($validate==='field' && $validateField!==''){
			$errorPayload=isset($errorPayload[$validateField]) ? [$validateField=>$errorPayload[$validateField]] : [];
		}
		$valid=$validatedState instanceof PanelFormState
			? ($validate==='field' && $validateField!=='' ? ($errorPayload===[]) : $validatedState->valid())
			: null;
		return PanelPageResult::json([
			'operation'=>$operation,
			'validated'=>$validatedState instanceof PanelFormState,
			'validated_fields'=>$validatedFields,
			'valid'=>$valid,
			'lifecycle'=>$lifecycleResult instanceof PanelLifecycleResult ? $lifecycleResult->jsonSerialize() : null,
			'fields'=>$fields,
			'errors'=>$errorPayload,
			'state'=>[
				'dirty'=>$snapshot->dirty(),
				'dirty_fields'=>$snapshot->dirtyFields(),
				'operation'=>$snapshot->operation(),
			],
		]);
	}

	/**
	 * Resolves whether a field is required for the current submitted form state.
	 *
	 * Static required metadata wins first, followed by required_when and
	 * required_unless condition groups evaluated against the current request input.
	 *
	 * @param array<string, mixed> $meta Field metadata.
	 * @param PanelRequest $request Current Panel request.
	 * @return bool Whether the field should be treated as required.
	 */
	private static function fieldRequiredForState(array $meta, PanelRequest $request): bool {
		if(($meta['required'] ?? false)===true){
			return true;
		}
		$requiredWhen=is_array($meta['required_when'] ?? null) ? $meta['required_when'] : [];
		if($requiredWhen!==[] && self::formConditionsMatch($requiredWhen, $request, false)){
			return true;
		}
		$requiredUnless=is_array($meta['required_unless'] ?? null) ? $meta['required_unless'] : [];
		if($requiredUnless!==[] && self::formConditionsMatch($requiredUnless, $request, true)){
			return true;
		}
		return false;
	}

	/**
	 * Evaluates a group of form dependency conditions against request input.
	 *
	 * All conditions must match. Unless-mode inverts each individual condition for
	 * required_unless semantics.
	 *
	 * @param array<string, mixed> $conditions Field condition map.
	 * @param PanelRequest $request Current Panel request.
	 * @param bool $unless Whether matches should be inverted.
	 * @return bool Whether the condition group passes.
	 */
	private static function formConditionsMatch(array $conditions, PanelRequest $request, bool $unless=false): bool {
		foreach($conditions as $field=>$expected){
			$actual=$request->input(Resource::normalizeName((string)$field), null);
			$hit=self::formConditionMatches($actual, $expected);
			if($unless){
				$hit=!$hit;
			}
			if(!$hit){
				return false;
			}
		}
		return true;
	}

	/**
	 * Compares one actual form value with an expected condition value.
	 *
	 * Arrays express membership, booleans use Panel truthiness, and scalar values
	 * compare as strings so HTML form payloads and typed metadata line up.
	 *
	 * @param mixed $actual Submitted value.
	 * @param mixed $expected Expected condition value.
	 * @return bool Whether the condition matches.
	 */
	private static function formConditionMatches(mixed $actual, mixed $expected): bool {
		if(is_array($expected)){
			$expected=array_map(static fn(mixed $value): string => (string)$value, $expected);
			return in_array((string)$actual, $expected, true);
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
	 * Selects the form definition and operation used by a reactive form request.
	 *
	 * Action and bulk-update requests resolve to their dedicated forms, while
	 * create/store and edit/update aliases are normalized for downstream field
	 * visibility, option, and validation behavior.
	 *
	 * @param Resource $resource Resource that owns the form.
	 * @param PanelRequest $request Current Panel request.
	 * @return array{0:?ResourceForm,1:string} Form instance and normalized operation.
	 */
	private static function reactiveForm(Resource $resource, PanelRequest $request): array {
		if($request->operation()==='action'){
			$actionName=$request->actionName();
			$action=$actionName!==null ? $resource->actionByName($actionName) : null;
			if($action instanceof Action){
				return [$action->form(), 'action'];
			}
		}
		if($request->operation()==='bulk_update'){
			return [$resource->bulkForm(), 'bulk_update'];
		}
		$operation=match($request->operation()){
			'edit', 'update'=>'edit',
			'create', 'store'=>'create',
			default=>$request->operation(),
		};
		return [$resource->form(), $operation];
	}

	/**
	 * Renders form sections, including tabbed and stepped section groups.
	 *
	 * Plain sections render first, followed by tab groups and step groups. Empty
	 * forms receive a localized empty state and single default sections omit extra
	 * section chrome.
	 *
	 * @param array<string, string|array<int, string>> $sections Rendered field HTML grouped by section label.
	 * @param int|array $columns Form grid column definition.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @return string Form section HTML.
	 */
	private static function formSectionsHtml(array $sections, int|array $columns=1, array $sectionMeta=[]): string {
		if($sections===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('form.no_fields')).'</p>';
		}
		$columns=self::normalizeGridColumns($columns);
		[$stepped, $unstepped]=self::steppedSections($sections, $sectionMeta);
		[$tabbed, $untabbed]=self::tabbedSections($unstepped, $sectionMeta);
		$defaultSection=self::panelText('record.details');
		$single=$stepped===[] && $tabbed===[] && count($sections)===1 && array_key_first($sections)===$defaultSection;
		$html='';
		foreach($sections as $label=>$fields){
			if(!isset($untabbed[$label])){
				continue;
			}
			$meta=self::sectionMeta($sectionMeta, (string)$label);
			$html.=self::sectionBlockHtml((string)$label, $fields, $meta, $columns, false, $single);
		}
		if($tabbed!==[]){
			$html.=self::tabsHtml($tabbed, $sectionMeta, $columns, false);
		}
		if($stepped!==[]){
			$html.=self::stepsHtml($stepped, $sectionMeta, $columns, false);
		}
		return $html;
	}

	/**
	 * Renders one field for read-only show/detail views.
	 *
	 * Show fields use the same grid layout metadata as forms, then format display
	 * values through field display hooks, option labels, badges, links, media, and
	 * copy affordances.
	 *
	 * @param Field $field Resource field definition.
	 * @param array<string, mixed> $meta Field metadata with resolved options.
	 * @param mixed $value Raw record value.
	 * @param mixed $record Current record.
	 * @param PanelRequest|null $request Current Panel request.
	 * @return string Show field HTML.
	 */
	private static function showFieldHtml(Field $field, array $meta, mixed $value, mixed $record=null, ?PanelRequest $request=null): string {
		$layoutMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$span=$layoutMeta['column_span'] ?? '1';
		$class='dp-panel-show-field';
		if(($layoutMeta['copyable'] ?? false)===true){
			$class.=' dp-panel-show-field-copyable';
		}
		$style=self::gridItemStyle($layoutMeta);
		$style=$style!=='' ? ' style="'.self::e($style).'"' : '';
		if($span==='full'){
			$class.=' dp-panel-field-full';
		}
		elseif(!is_array($span) && (int)$span>1){
			$class.=' dp-panel-field-span-'.min(12, (int)$span);
		}
		$display=self::displayFieldValue($field, $meta, $value, $record, $request);
		$label=(string)$meta['label'];
		$icon=trim((string)($layoutMeta['icon'] ?? ''));
		$iconHtml=$icon!=='' ? '<i class="dp-panel-entry-icon" aria-hidden="true">'.self::e(self::entryIconText($icon, $label)).'</i>' : '';
		$copyHtml=($layoutMeta['copyable'] ?? false)===true && $display!==''
			? '<button type="button" class="dp-panel-entry-copy" data-dp-panel-copy-entry="'.self::e($display).'" title="'.self::e(self::panelText('copy.value', [], 'Copy value')).'">'.self::e(self::panelText('common.copy')).'</button>'
			: '';
		$description=trim((string)($layoutMeta['description'] ?? ''));
		$valueHtml=self::entryValueHtml($display, $value, $meta);
		return '<article class="'.$class.'"'.$style.'>'
			.'<header>'.$iconHtml.'<span>'.self::e($label).'</span>'.$copyHtml.'</header>'
			.$valueHtml
			.($description!=='' ? '<small class="dp-panel-entry-description">'.self::e($description).'</small>' : '')
			.'</article>';
	}

	/**
	 * Renders an arbitrary show entry array.
	 *
	 * Entry arrays support the same layout, copy, icon, and value formatting
	 * conventions as resource-backed fields without requiring a Field instance.
	 *
	 * @param array<string, mixed> $entry Entry metadata and value payload.
	 * @return string Show entry HTML.
	 */
	private static function showEntryHtml(array $entry): string {
		$meta=is_array($entry['field'] ?? null) ? $entry['field'] : [];
		$layoutMeta=is_array($entry['meta'] ?? null) ? $entry['meta'] : (is_array($meta['meta'] ?? null) ? $meta['meta'] : []);
		$span=$layoutMeta['column_span'] ?? '1';
		$class='dp-panel-show-field';
		if(($entry['copyable'] ?? $layoutMeta['copyable'] ?? false)===true){
			$class.=' dp-panel-show-field-copyable';
		}
		$style=self::gridItemStyle($layoutMeta);
		$style=$style!=='' ? ' style="'.self::e($style).'"' : '';
		if($span==='full'){
			$class.=' dp-panel-field-full';
		}
		elseif(!is_array($span) && (int)$span>1){
			$class.=' dp-panel-field-span-'.min(12, (int)$span);
		}
		$display=(string)($entry['display'] ?? '');
		$label=(string)($entry['label'] ?? $entry['name'] ?? 'Entry');
		$icon=trim((string)($layoutMeta['icon'] ?? ''));
		$iconHtml=$icon!=='' ? '<i class="dp-panel-entry-icon" aria-hidden="true">'.self::e(self::entryIconText($icon, $label)).'</i>' : '';
		$copyHtml=($entry['copyable'] ?? $layoutMeta['copyable'] ?? false)===true && $display!==''
			? '<button type="button" class="dp-panel-entry-copy" data-dp-panel-copy-entry="'.self::e($display).'" title="'.self::e(self::panelText('copy.value', [], 'Copy value')).'">'.self::e(self::panelText('common.copy')).'</button>'
			: '';
		$description=trim((string)($layoutMeta['description'] ?? ''));
		$valueHtml=self::entryValueHtml($display, $entry['raw'] ?? null, $meta);
		return '<article class="'.$class.'"'.$style.'>'
			.'<header>'.$iconHtml.'<span>'.self::e($label).'</span>'.$copyHtml.'</header>'
			.$valueHtml
			.($description!=='' ? '<small class="dp-panel-entry-description">'.self::e($description).'</small>' : '')
			.'</article>';
	}

	/**
	 * Renders show/detail sections with optional tabs and steps.
	 *
	 * @param array<string, string|array<int, string>> $sections Rendered entry HTML grouped by section label.
	 * @param int|array $columns Show grid column definition.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @return string Show section HTML.
	 */
	private static function showSectionsHtml(array $sections, int|array $columns=1, array $sectionMeta=[]): string {
		if($sections===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('form.no_visible_fields')).'</p>';
		}
		$columns=self::normalizeGridColumns($columns);
		[$stepped, $unstepped]=self::steppedSections($sections, $sectionMeta);
		[$tabbed, $untabbed]=self::tabbedSections($unstepped, $sectionMeta);
		$defaultSection=self::panelText('record.details');
		$single=$stepped===[] && $tabbed===[] && count($sections)===1 && array_key_first($sections)===$defaultSection;
		$html='';
		foreach($sections as $label=>$fields){
			if(!isset($untabbed[$label])){
				continue;
			}
			$meta=self::sectionMeta($sectionMeta, (string)$label);
			$html.=self::sectionBlockHtml((string)$label, $fields, $meta, $columns, true, $single);
		}
		if($tabbed!==[]){
			$html.=self::tabsHtml($tabbed, $sectionMeta, $columns, true);
		}
		if($stepped!==[]){
			$html.=self::stepsHtml($stepped, $sectionMeta, $columns, true);
		}
		return $html;
	}

	/**
	 * Splits sections into stepped and unstepped groups.
	 *
	 * @param array<string, string|array<int, string>> $sections Section field HTML grouped by section label.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @return array{0:array,1:array} Stepped sections and remaining sections.
	 */
	private static function steppedSections(array $sections, array $sectionMeta): array {
		$stepped=[];
		$unstepped=[];
		foreach($sections as $label=>$fields){
			$meta=self::sectionMeta($sectionMeta, (string)$label);
			$step=trim((string)($meta['meta']['step'] ?? $meta['step'] ?? ''));
			if($step===''){
				$unstepped[$label]=$fields;
				continue;
			}
			$stepped[$step] ??=[];
			$stepped[$step][$label]=$fields;
		}
		return [$stepped, $unstepped];
	}

	/**
	 * Splits sections into tabbed and untabbed groups.
	 *
	 * @param array<string, string|array<int, string>> $sections Section field HTML grouped by section label.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @return array{0:array,1:array} Tabbed sections and remaining sections.
	 */
	private static function tabbedSections(array $sections, array $sectionMeta): array {
		$tabbed=[];
		$untabbed=[];
		foreach($sections as $label=>$fields){
			$meta=self::sectionMeta($sectionMeta, (string)$label);
			$tab=trim((string)($meta['meta']['tab'] ?? $meta['tab'] ?? ''));
			if($tab===''){
				$untabbed[$label]=$fields;
				continue;
			}
			$tabbed[$tab] ??=[];
			$tabbed[$tab][$label]=$fields;
		}
		return [$tabbed, $untabbed];
	}

	/**
	 * Renders tab navigation and panels for grouped sections.
	 *
	 * @param array<string, array<int, string>> $tabs Sections grouped by tab label.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @param int|array $columns Grid column definition.
	 * @param bool $show Whether panels are for show/detail output.
	 * @return string Tabbed section HTML.
	 */
	private static function tabsHtml(array $tabs, array $sectionMeta, int|array $columns, bool $show=false): string {
		if($tabs===[]){
			return '';
		}
		$buttons='';
		$panels='';
		$index=0;
		foreach($tabs as $tab=>$sections){
			$tabId='dp-panel-tab-'.substr(sha1((string)$tab.$index), 0, 10);
			$active=$index===0;
			$buttons.='<button type="button" id="'.$tabId.'-button" role="tab" aria-controls="'.$tabId.'" aria-selected="'.($active ? 'true' : 'false').'">'.self::e((string)$tab).'</button>';
			$content='';
			foreach($sections as $label=>$fields){
				$content.=self::sectionBlockHtml((string)$label, $fields, self::sectionMeta($sectionMeta, (string)$label), $columns, $show, false);
			}
			$panels.='<div id="'.$tabId.'" class="dp-panel-tab-panel" role="tabpanel" aria-labelledby="'.$tabId.'-button"'.($active ? '' : ' hidden').'>'.$content.'</div>';
			$index++;
		}
		return '<section class="dp-panel-tabs" data-dp-panel-tabs><div class="dp-panel-tab-list" role="tablist">'.$buttons.'</div>'.$panels.'</section>';
	}

	/**
	 * Renders step navigation and panels for grouped sections.
	 *
	 * Form steps include previous/next controls for the client step runtime; show
	 * mode omits those controls while preserving the same grouped content.
	 *
	 * @param array<string, array<int, string>> $steps Sections grouped by step label.
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata keyed by normalized section name.
	 * @param int|array $columns Grid column definition.
	 * @param bool $show Whether panels are for show/detail output.
	 * @return string Stepped section HTML.
	 */
	private static function stepsHtml(array $steps, array $sectionMeta, int|array $columns, bool $show=false): string {
		if($steps===[]){
			return '';
		}
		$buttons='';
		$panels='';
		$count=count($steps);
		$index=0;
		foreach($steps as $step=>$sections){
			$stepId='dp-panel-step-'.substr(sha1((string)$step.$index), 0, 10);
			$active=$index===0;
			$buttons.='<button type="button" id="'.$stepId.'-button" data-dp-panel-step-button aria-controls="'.$stepId.'" aria-current="'.($active ? 'step' : 'false').'"><span>'.($index+1).'</span>'.self::e((string)$step).'</button>';
			$content='';
			foreach($sections as $label=>$fields){
				$content.=self::sectionBlockHtml((string)$label, $fields, self::sectionMeta($sectionMeta, (string)$label), $columns, $show, false);
			}
			$footer='';
			if(!$show && $count>1){
				$footer='<div class="dp-panel-step-actions">'
					.'<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-step-prev'.($index===0 ? ' disabled' : '').'>'.self::e(self::panelText('common.back')).'</button>'
					.'<button class="dp-panel-button" type="button" data-dp-panel-step-next'.($index===$count-1 ? ' hidden' : '').'>'.self::e(self::panelText('common.next')).'</button>'
					.'</div>';
			}
			$panels.='<div id="'.$stepId.'" class="dp-panel-step-panel" data-dp-panel-step-panel aria-labelledby="'.$stepId.'-button"'.($active ? '' : ' hidden').'>'.$content.$footer.'</div>';
			$index++;
		}
		return '<section class="dp-panel-steps" data-dp-panel-steps><nav class="dp-panel-step-list" aria-label="'.self::e(self::panelText('client.steps')).'">'.$buttons.'</nav>'.$panels.'</section>';
	}

	/**
	 * Renders a single section block and responsive field grid.
	 *
	 * Section metadata can override grid columns, apply default accessibility
	 * policy attributes, choose a layout token, and render as collapsible details.
	 *
	 * @param string $label Section label.
	 * @param array<int|string, array<string, mixed>|string> $fields Rendered field or entry HTML.
	 * @param array<string, mixed> $meta Section metadata.
	 * @param int|array $columns Inherited grid column definition.
	 * @param bool $show Whether the section contains show/detail output.
	 * @param bool $single Whether section chrome should be minimized.
	 * @return string Section HTML.
	 */
	private static function sectionBlockHtml(string $label, array $fields, array $meta, int|array $columns, bool $show=false, bool $single=false): string {
		$sectionColumns=(int)($meta['columns'] ?? 0);
		$sectionColumns=$sectionColumns>0 ? $sectionColumns : $columns;
		$sectionColumns=self::normalizeGridColumns(self::sectionGridColumns($meta, $sectionColumns));
		$sectionPolicy=is_array($meta['meta']['accessibility'] ?? null) ? $meta['meta']['accessibility'] : (is_array($meta['accessibility'] ?? null) ? $meta['accessibility'] : []);
		$grid='<div class="dp-panel-form-grid dp-panel-form-grid-'.(int)max($sectionColumns).'" style="'.self::e(self::gridColumnsStyle($sectionColumns)).'"'.self::accessibilityDefaultAttrs(['accessibility'=>$sectionPolicy]).'>'.implode('', $fields).'</div>';
		if($single){
			return $show ? '<section class="dp-panel-show">'.$grid.'</section>' : $grid;
		}
		$heading=self::sectionHeadingHtml($label, $meta);
		$layout=Resource::normalizeName((string)($meta['layout'] ?? ($meta['meta']['layout'] ?? '')));
		$layoutAttr=$layout!=='' ? ' data-layout="'.self::e($layout).'"' : '';
		if(($meta['collapsible'] ?? false)===true){
			$open=($meta['collapsed'] ?? false)===true ? '' : ' open';
			return '<details class="'.($show ? 'dp-panel-show ' : '').'dp-panel-form-section dp-panel-form-details"'.$layoutAttr.$open.'>'
				.'<summary>'.$heading.'</summary>'
				.$grid
				.'</details>';
		}
		return '<section class="'.($show ? 'dp-panel-show ' : '').'dp-panel-form-section"'.$layoutAttr.'>'
			.$heading
			.$grid
			.'</section>';
	}

	/**
	 * Indexes section metadata by normalized name and label.
	 *
	 * @param array<string, string|array<int, string>> $sections Raw section metadata entries.
	 * @return array<string,array> Section metadata map.
	 */
	private static function sectionMetaByName(array $sections): array {
		$map=[];
		foreach($sections as $section){
			if(!is_array($section)){
				continue;
			}
			$name=Resource::normalizeName((string)($section['name'] ?? $section['label'] ?? ''));
			if($name===''){
				continue;
			}
			$map[$name]=$section;
			$labelName=Resource::normalizeName((string)($section['label'] ?? ''));
			if($labelName!==''){
				$map[$labelName]=$section;
			}
		}
		return $map;
	}

	/**
	 * Retrieves section metadata by displayed label.
	 *
	 * @param array<string, array<string, mixed>> $sectionMeta Section metadata map.
	 * @param string $label Display section label.
	 * @return array Section metadata or an empty array.
	 */
	private static function sectionMeta(array $sectionMeta, string $label): array {
		return $sectionMeta[Resource::normalizeName($label)] ?? [];
	}

	/**
	 * Renders section heading and optional description.
	 *
	 * @param string $fallbackLabel Fallback label from grouped sections.
	 * @param array<string, mixed> $meta Section metadata.
	 * @return string Section heading HTML.
	 */
	private static function sectionHeadingHtml(string $fallbackLabel, array $meta): string {
		$label=trim((string)($meta['label'] ?? $fallbackLabel));
		$description=trim((string)($meta['description'] ?? ''));
		return '<div class="dp-panel-section-heading"><h2>'.self::e($label).'</h2>'
			.($description!=='' ? '<p>'.self::e($description).'</p>' : '')
			.'</div>';
	}

	/**
	 * Resolves form grid column metadata.
	 *
	 * @param array<string, mixed> $formMeta Form metadata.
	 * @return int|array Grid column definition.
	 */
	private static function formColumnsDefinition(array $formMeta): int|array {
		$meta=is_array($formMeta['meta'] ?? null) ? $formMeta['meta'] : [];
		$gridColumns=$meta['grid_columns'] ?? null;
		return is_array($gridColumns) && $gridColumns!==[] ? $gridColumns : (int)($formMeta['columns'] ?? 1);
	}

	/**
	 * Resolves section grid columns with form-level fallback.
	 *
	 * @param array<string, mixed> $meta Section metadata.
	 * @param int|array $fallback Form-level grid column definition.
	 * @return int|array Section grid column definition.
	 */
	private static function sectionGridColumns(array $meta, int|array $fallback): int|array {
		$sectionMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$gridColumns=$sectionMeta['grid_columns'] ?? $meta['grid_columns'] ?? null;
		return is_array($gridColumns) && $gridColumns!==[] ? $gridColumns : $fallback;
	}

	/**
	 * Normalizes responsive grid column definitions to bounded breakpoint values.
	 *
	 * @param int|array $columns Column count or breakpoint map.
	 * @return array<string,int> Normalized columns keyed by breakpoint.
	 */
	private static function normalizeGridColumns(int|array $columns): array {
		if(is_int($columns)){
			return ['default'=>max(1, min(12, $columns))];
		}
		$normalized=[];
		foreach($columns as $breakpoint=>$value){
			$breakpoint=self::gridBreakpoint((string)$breakpoint);
			if($breakpoint!==''){
				$normalized[$breakpoint]=max(1, min(12, (int)$value));
			}
		}
		return $normalized!==[] ? $normalized : ['default'=>1];
	}

	/**
	 * Renders CSS custom properties for a responsive grid column definition.
	 *
	 * @param array<int|string, mixed> $columns Normalized grid columns.
	 * @return string CSS style declaration fragment.
	 */
	private static function gridColumnsStyle(array $columns): string {
		$style=[];
		foreach($columns as $breakpoint=>$value){
			$suffix=$breakpoint==='default' ? '' : '-'.$breakpoint;
			$style[]='--dp-grid-cols'.$suffix.':'.max(1, min(12, (int)$value));
		}
		return implode(';', $style);
	}

	/**
	 * Renders CSS custom properties for responsive grid item placement.
	 *
	 * @param array<string, mixed> $meta Field or entry layout metadata.
	 * @return string CSS style declaration fragment.
	 */
	private static function gridItemStyle(array $meta): string {
		$spanMap=self::gridValueMap($meta['column_span'] ?? null);
		$startMap=self::gridValueMap($meta['column_start'] ?? null);
		$rowMap=self::gridValueMap($meta['row_span'] ?? null);
		$breakpoints=array_values(array_unique(array_merge(array_keys($spanMap), array_keys($startMap), array_keys($rowMap))));
		if($breakpoints===[]){
			return '';
		}
		usort($breakpoints, static fn(string $left, string $right): int => self::gridBreakpointOrder($left)<=>self::gridBreakpointOrder($right));
		$style=[];
		foreach($breakpoints as $breakpoint){
			$suffix=$breakpoint==='default' ? '' : '-'.$breakpoint;
			$span=$spanMap[$breakpoint] ?? null;
			$start=$startMap[$breakpoint] ?? null;
			if($span!==null || $start!==null){
				$style[]='--dp-grid-column'.$suffix.':'.self::gridColumnValue($span, $start);
			}
			if(isset($rowMap[$breakpoint])){
				$style[]='--dp-grid-row'.$suffix.':auto / span '.max(1, min(12, (int)$rowMap[$breakpoint]));
			}
		}
		return implode(';', $style);
	}

	/**
	 * Normalizes a scalar or breakpoint map into responsive grid values.
	 *
	 * @param mixed $value Scalar grid value or breakpoint map.
	 * @return array<string,mixed> Grid values keyed by normalized breakpoint.
	 */
	private static function gridValueMap(mixed $value): array {
		if($value===null || $value===''){
			return [];
		}
		if(is_array($value)){
			$map=[];
			foreach($value as $breakpoint=>$item){
				$breakpoint=self::gridBreakpoint((string)$breakpoint);
				if($breakpoint!==''){
					$map[$breakpoint]=$item;
				}
			}
			return $map;
		}
		return ['default'=>$value];
	}

	/**
	 * Builds a CSS grid-column value from span and optional start values.
	 *
	 * @param mixed $span Column span, integer-like value, or `full`.
	 * @param mixed $start Optional column start.
	 * @return string CSS grid-column value.
	 */
	private static function gridColumnValue(mixed $span, mixed $start): string {
		$full=is_string($span) && strtolower(trim($span))==='full';
		if($full){
			return $start!==null ? max(1, min(12, (int)$start)).' / -1' : '1 / -1';
		}
		$span=max(1, min(12, (int)($span ?? 1)));
		if($start!==null){
			return max(1, min(12, (int)$start)).' / span '.$span;
		}
		return 'auto / span '.$span;
	}

	/**
	 * Normalizes supported responsive breakpoint aliases.
	 *
	 * @param string $breakpoint Raw breakpoint label.
	 * @return string Normalized breakpoint token or an empty string.
	 */
	private static function gridBreakpoint(string $breakpoint): string {
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
	 * Provides sort order for normalized grid breakpoints.
	 *
	 * @param string $breakpoint Normalized breakpoint token.
	 * @return int Sort order.
	 */
	private static function gridBreakpointOrder(string $breakpoint): int {
		return ['default'=>0, 'sm'=>1, 'md'=>2, 'lg'=>3, 'xl'=>4, '2xl'=>5][$breakpoint] ?? 99;
	}

	/**
	 * Resolves the display string for a field in show/detail contexts.
	 *
	 * Field display hooks have precedence, followed by empty fallbacks, boolean
	 * labels, file summaries, option labels, repeater summaries, and generic string
	 * normalization.
	 *
	 * @param Field $field Resource field definition.
	 * @param array<string, mixed> $meta Field metadata with resolved options.
	 * @param mixed $value Raw field value.
	 * @param mixed $record Current record.
	 * @param PanelRequest|null $request Current Panel request.
	 * @return string Display value.
	 */
	private static function displayFieldValue(Field $field, array $meta, mixed $value, mixed $record=null, ?PanelRequest $request=null): string {
		$display=$field->displayValue($value, $record, $request);
		if($display!==$value){
			return self::stringValue($display);
		}
		if($value===null || $value===''){
			return (string)($meta['meta']['empty'] ?? 'Not set');
		}
		$type=(string)($meta['type'] ?? 'text');
		if(self::isBooleanType($type)){
			return self::truthy($value) ? 'Yes' : 'No';
		}
		if(self::isFileFieldType($type)){
			if(is_array($value) && isset($value['name']) && !is_array($value['name'])){
				return (string)$value['name'];
			}
			if(is_array($value)){
				$names=[];
				foreach($value as $item){
					if(is_array($item) && isset($item['name']) && !is_array($item['name'])){
						$names[]=(string)$item['name'];
					}
					elseif(is_scalar($item)){
						$names[]=(string)$item;
					}
				}
				return implode(', ', array_filter($names)) ?: self::panelText('uploader.uploaded_file');
			}
		}
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		if($options!==[]){
			$key=(string)$value;
			$label=self::optionLabel($options, $key);
			if($label!==null){
				return $label;
			}
		}
		if($type==='repeater' && is_array($value)){
			return self::repeaterDisplayValue($value, $meta);
		}
		return self::stringValue($value);
	}

	/**
	 * Renders a show/detail value according to type and presentation metadata.
	 *
	 * The renderer supports badges, booleans, mailto links, external URL links,
	 * image previews, repeater lists, rich HTML opt-in, and prefix/suffix wrapping.
	 *
	 * @param string $display Display string.
	 * @param mixed $raw Raw value used for typed rendering decisions.
	 * @param array<string, mixed> $meta Field or entry metadata.
	 * @return string Show value HTML.
	 */
	private static function entryValueHtml(string $display, mixed $raw, array $meta): string {
		$fieldMeta=is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
		$type=(string)($meta['type'] ?? 'text');
		$prefix=(string)($fieldMeta['prefix'] ?? '');
		$suffix=(string)($fieldMeta['suffix'] ?? '');
		$text=$prefix.$display.$suffix;
		if(($fieldMeta['badge'] ?? false)===true || $type==='badge'){
			return '<strong><span class="dp-panel-badge dp-panel-badge-'.self::entryTone($raw, $fieldMeta).'">'.self::e($text).'</span></strong>';
		}
		if(self::isBooleanType($type)){
			$tone=self::truthy($raw) ? 'success' : 'neutral';
			return '<strong><span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($text).'</span></strong>';
		}
		if($type==='email'){
			$email=trim(self::stringValue($raw));
			if($email!=='' && filter_var($email, FILTER_VALIDATE_EMAIL)!==false){
				return '<strong><a class="dp-panel-cell-link" href="mailto:'.self::e($email).'">'.self::e($text).'</a></strong>';
			}
		}
		if($type==='url'){
			$url=trim(self::stringValue($raw));
			if(preg_match('/^https?:\/\//i', $url)===1){
				return '<strong><a class="dp-panel-cell-link" href="'.self::e($url).'">'.self::e($text).'</a></strong>';
			}
		}
		if($type==='image'){
			$url=trim(self::stringValue($raw));
			if($url!=='' && (str_starts_with($url, '/') || preg_match('/^https?:\/\//i', $url)===1)){
				return '<strong class="dp-panel-entry-media"><img src="'.self::e($url).'" alt="'.self::e($display).'"></strong>';
			}
		}
		if($type==='repeater' && str_contains($display, "\n")){
			$items='';
			foreach(explode("\n", $display) as $line){
				$line=trim($line);
				if($line!==''){
					$items.='<li>'.self::e($line).'</li>';
				}
			}
			if($items!==''){
				return '<strong><ul class="dp-panel-entry-list">'.$items.'</ul></strong>';
			}
		}
		if(($fieldMeta['html'] ?? false)===true){
			return '<strong>'.$text.'</strong>';
		}
		return '<strong>'.self::e($text).'</strong>';
	}

	/**
	 * Resolves badge tone for an entry value.
	 *
	 * @param mixed $raw Raw value.
	 * @param array<string, mixed> $meta Entry metadata containing default tone or tone map.
	 * @return string Safe badge tone.
	 */
	private static function entryTone(mixed $raw, array $meta): string {
		$tone=(string)($meta['tone'] ?? 'neutral');
		$tones=$meta['tones'] ?? [];
		$value=trim(self::stringValue($raw));
		if(is_array($tones) && array_key_exists($value, $tones)){
			$tone=(string)$tones[$value];
		}
		$tone=strtolower(trim($tone));
		return in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
	}

	/**
	 * Produces a short icon fallback from an icon token or label.
	 *
	 * @param string $icon Icon token.
	 * @param string $fallback Fallback label.
	 * @return string One or two uppercase letters.
	 */
	private static function entryIconText(string $icon, string $fallback): string {
		$icon=trim($icon);
		if($icon===''){
			$icon=$fallback;
		}
		$parts=preg_split('/[^a-z0-9]+/i', $icon) ?: [];
		$letters='';
		foreach($parts as $part){
			if($part!==''){
				$letters.=strtoupper($part[0]);
			}
			if(strlen($letters)>=2){
				break;
			}
		}
		return $letters!=='' ? $letters : strtoupper(substr($icon, 0, 2));
	}

	/**
	 * Summarizes repeater rows for show/detail rendering.
	 *
	 * @param array<int, array<string, mixed>> $rows Repeater row values.
	 * @param array<string, mixed> $meta Repeater field metadata.
	 * @return string Multiline repeater summary.
	 */
	private static function repeaterDisplayValue(array $rows, array $meta): string {
		$fields=self::repeaterFieldMetas($meta);
		$lines=[];
		foreach($rows as $index=>$row){
			if(!is_array($row)){
				continue;
			}
			$parts=[];
			foreach($fields as $name=>$field){
				$value=self::stringValue($row[$name] ?? '');
				if($value!==''){
					$parts[]=((string)($field['label'] ?? $name)).': '.$value;
				}
			}
			if($parts!==[]){
				$lines[]='#'.($index+1).' '.implode(', ', $parts);
			}
		}
		return $lines!==[] ? implode("\n", $lines) : (string)($meta['meta']['empty'] ?? self::panelText('form.no_items'));
	}

	/**
	 * Finds the display label for an option value, including nested groups.
	 *
	 * @param array<int|string, array<string, mixed>|string> $options Resource option definitions.
	 * @param string $key Option value to find.
	 * @return string|null Option label or null when not found.
	 */
	private static function optionLabel(array $options, string $key): ?string {
		if(array_key_exists($key, $options) && !is_array($options[$key])){
			return (string)$options[$key];
		}
		foreach($options as $optionValue=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options']);
				$found=self::optionLabel($groupOptions, $key);
				if($found!==null){
					return $found;
				}
				continue;
			}
			if(is_array($label)){
				$optionValue=(string)($label['value'] ?? $optionValue);
				if($optionValue===$key){
					return (string)($label['label'] ?? $key);
				}
			}
			elseif((string)$optionValue===$key){
				return (string)$label;
			}
		}
		return null;
	}

	/**
	 * Converts arbitrary scalar, null, boolean, or structured values to strings.
	 *
	 * Structured values serialize as JSON for diagnostic and hidden-field contexts.
	 *
	 * @param mixed $value Value to stringify.
	 * @return string String representation.
	 */
	private static function stringValue(mixed $value): string {
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if(is_scalar($value) || $value===null){
			return (string)$value;
		}
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * Formats byte counts for upload policy and file summaries.
	 *
	 * @param mixed $bytes Byte count or non-numeric fallback.
	 * @return string Human-readable byte label.
	 */
	private static function formatBytes(mixed $bytes): string {
		if(!is_numeric($bytes)){
			return self::stringValue($bytes);
		}
		$value=max(0, (float)$bytes);
		$units=['B', 'KB', 'MB', 'GB', 'TB'];
		$unitIndex=0;
		while($value>=1024 && $unitIndex<count($units)-1){
			$value/=1024;
			$unitIndex++;
		}
		$precision=$unitIndex===0 || $value>=10 ? 0 : 1;
		return number_format($value, $precision).' '.$units[$unitIndex];
	}

	/**
	 * Extracts a successful uploaded attachment from a request.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @return array|null Uploaded file array or null when absent/invalid.
	 */
	private static function uploadedAttachmentFile(PanelRequest $request): ?array {
		$file=$request->file('attachment');
		if(!is_array($file)){
			return null;
		}
		$error=(int)($file['error'] ?? UPLOAD_ERR_OK);
		if($error!==UPLOAD_ERR_OK || trim((string)($file['name'] ?? ''))===''){
			return null;
		}
		return $file;
	}

	/**
	 * Builds a safe diagnostic summary for an uploaded attachment.
	 *
	 * The temporary filename is reduced to basename to avoid exposing filesystem
	 * paths in Panel traces or action payloads.
	 *
	 * @param array<string, mixed> $file Uploaded file array.
	 * @return array<string,mixed> Attachment summary.
	 */
	private static function attachmentFileSummary(array $file): array {
		return [
			'name'=>$file['name'] ?? null,
			'type'=>$file['type'] ?? null,
			'size'=>$file['size'] ?? null,
			'tmp_name'=>isset($file['tmp_name']) ? basename((string)$file['tmp_name']) : null,
		];
	}

	/**
	 * Checks whether a field type is boolean-like.
	 *
	 * @param string $type Field type.
	 * @return bool Whether the type stores true/false state.
	 */
	private static function isBooleanType(string $type): bool {
		return in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true);
	}

	/**
	 * Applies Panel truthiness rules to submitted values.
	 *
	 * @param mixed $value Value to test.
	 * @return bool Whether the value is truthy.
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
	 * Normalizes an action result into message, redirect, notifications, and effects.
	 *
	 * Actions may return strings, notification objects, arrays with redirect/status
	 * metadata, browser effects, or scalar payloads. Missing notifications are
	 * synthesized from the final message.
	 *
	 * @param mixed $result Raw action result.
	 * @param string $defaultMessage Fallback success message.
	 * @return array<string,mixed> Normalized action outcome.
	 */
	private static function outcome(mixed $result, string $defaultMessage): array {
		$message=$defaultMessage;
		$redirect=null;
		$status=303;
		$notifications=[];
		$effects=[];
		$payload=is_scalar($result) || is_array($result) ? $result : null;
		if($result instanceof PanelNotification){
			$notifications[]=$result;
			$message=$result->message();
		}
		elseif(is_string($result) && trim($result)!==''){
			$message=trim($result);
			$notifications[]=PanelNotification::success($message);
		}
		elseif(is_array($result)){
			if(isset($result['message'])){
				$message=(string)$result['message'];
			}
			foreach(['notification', 'notifications'] as $key){
				if(!array_key_exists($key, $result)){
					continue;
				}
				$items=is_array($result[$key]) && array_is_list($result[$key]) ? $result[$key] : [$result[$key]];
				foreach($items as $item){
					if($item instanceof PanelNotification || is_array($item) || is_string($item)){
						$notifications[]=$item;
					}
				}
			}
			if(isset($result['redirect'])){
				$redirect=(string)$result['redirect'];
			}
			elseif(isset($result['redirect_to'])){
				$redirect=(string)$result['redirect_to'];
			}
			if(isset($result['status'])){
				$status=max(300, min(399, (int)$result['status']));
			}
			$effects=self::normalizeActionEffects($result['effects'] ?? $result['action_effects'] ?? $result);
		}
		if($notifications===[] && $message!==''){
			$notifications[]=PanelNotification::success($message);
		}
		return [
			'message'=>$message,
			'redirect'=>$redirect!==null && trim($redirect)!=='' ? trim($redirect) : null,
			'status'=>$status,
			'notifications'=>$notifications,
			'result'=>$payload,
			'effects'=>$effects,
		];
	}

	/**
	 * Resolves action effects from action metadata and normalized outcome data.
	 *
	 * @param array<string, mixed> $actionMeta Action metadata.
	 * @param array<string, mixed> $outcome Normalized action outcome.
	 * @return array<string,mixed> Normalized client effects.
	 */
	private static function actionEffects(array $actionMeta, array $outcome=[]): array {
		$metaEffects=is_array($actionMeta['effects'] ?? null) ? $actionMeta['effects'] : [];
		if($metaEffects===[] && is_array($actionMeta['meta']['effects'] ?? null)){
			$metaEffects=$actionMeta['meta']['effects'];
		}
		$effects=self::normalizeActionEffects($metaEffects);
		if(is_array($outcome['effects'] ?? null)){
			$effects=self::mergeActionEffects($effects, $outcome['effects']);
		}
		return $effects;
	}

	/**
	 * Merges two normalized action-effect payloads.
	 *
	 * Close-modal is overwritten by the newer payload, refresh targets are
	 * de-duplicated, and browser events append in order.
	 *
	 * @param array<int|string, mixed> $current Existing effects.
	 * @param array<int|string, mixed> $next Additional effects.
	 * @return array<string,mixed> Merged normalized effects.
	 */
	private static function mergeActionEffects(array $current, array $next): array {
		$current=self::normalizeActionEffects($current);
		$next=self::normalizeActionEffects($next);
		if(array_key_exists('close_modal', $next)){
			$current['close_modal']=$next['close_modal'];
		}
		if(array_key_exists('refresh', $next)){
			$current['refresh']=array_values(array_unique(array_merge($current['refresh'] ?? [], $next['refresh'])));
		}
		if(array_key_exists('events', $next)){
			$current['events']=array_merge($current['events'] ?? [], $next['events']);
		}
		return self::normalizeActionEffects($current);
	}

	/**
	 * Normalizes client action effects into stable refresh and event structures.
	 *
	 * Supported input aliases include refresh targets, close_modal, and browser
	 * event declarations expressed as strings or arrays with detail/data payloads.
	 *
	 * @param mixed $effects Raw effects payload.
	 * @return array<string,mixed> Normalized client effects.
	 */
	private static function normalizeActionEffects(mixed $effects): array {
		if(!is_array($effects)){
			return [];
		}
		$normalized=[];
		if(array_key_exists('close_modal', $effects)){
			$normalized['close_modal']=(bool)$effects['close_modal'];
		}
		if(array_key_exists('refresh', $effects)){
			$normalized['refresh']=self::normalizeActionEffectTargets($effects['refresh']);
		}
		foreach(['event', 'events', 'dispatch', 'browser_event', 'browser_events'] as $key){
			if(!array_key_exists($key, $effects)){
				continue;
			}
			$events=is_array($effects[$key]) && array_is_list($effects[$key]) ? $effects[$key] : [$effects[$key]];
			foreach($events as $event){
				if(is_string($event)){
					$name=trim($event);
					$detail=[];
				}
				elseif(is_array($event)){
					$name=trim((string)($event['name'] ?? $event['event'] ?? ''));
					$detail=is_array($event['detail'] ?? null) ? $event['detail'] : (is_array($event['data'] ?? null) ? $event['data'] : []);
				}
				else{
					continue;
				}
				if($name!==''){
					$normalized['events'][]=[
						'name'=>$name,
						'detail'=>$detail,
					];
				}
			}
		}
		if(isset($normalized['events'])){
			$normalized['events']=array_values($normalized['events']);
		}
		return $normalized;
	}

	/**
	 * Normalizes action refresh targets to safe unique tokens.
	 *
	 * @param mixed $targets Raw refresh targets.
	 * @return string[] Normalized target tokens.
	 */
	private static function normalizeActionEffectTargets(mixed $targets): array {
		$values=is_array($targets) ? $targets : preg_split('/[\s,]+/', (string)$targets);
		$normalized=[];
		foreach($values ?: [] as $target){
			if(!is_scalar($target)){
				continue;
			}
			$target=strtolower(trim((string)$target));
			$target=preg_replace('/[^a-z0-9_:.\\-]+/', '_', $target) ?? '';
			$target=trim($target, '_');
			if($target!=='' && !in_array($target, $normalized, true)){
				$normalized[]=$target;
			}
		}
		return $normalized;
	}

	/**
	 * Stores notifications in the session flash queue.
	 *
	 * The queue is capped to the latest twenty normalized notifications to prevent
	 * runaway session growth across redirects.
	 *
	 * @param array<int, PanelNotification|PanelInboxNotification|array<string, mixed>|string> $notifications Notification objects, arrays, or strings.
	 * @return void
	 */
	private static function flashNotifications(array $notifications): void {
		if(PHP_SESSION_ACTIVE!==session_status() || $notifications===[]){
			return;
		}
		$existing=is_array($_SESSION[self::FLASH_SESSION_KEY] ?? null) ? $_SESSION[self::FLASH_SESSION_KEY] : [];
		foreach($notifications as $notification){
			$normalized=self::notificationArray($notification);
			if($normalized!==null){
				$existing[]=$normalized;
			}
		}
		$_SESSION[self::FLASH_SESSION_KEY]=array_slice($existing, -20);
	}

	/**
	 * Consumes and clears session flash notifications.
	 *
	 * @return array<int,array<string,mixed>> Normalized notification arrays.
	 */
	private static function consumeFlashNotifications(): array {
		if(PHP_SESSION_ACTIVE!==session_status()){
			return [];
		}
		$notifications=is_array($_SESSION[self::FLASH_SESSION_KEY] ?? null) ? $_SESSION[self::FLASH_SESSION_KEY] : [];
		unset($_SESSION[self::FLASH_SESSION_KEY]);
		return array_values(array_filter($notifications, static fn(mixed $notification): bool => is_array($notification)));
	}

	/**
	 * Normalizes one notification payload.
	 *
	 * @param mixed $notification Notification object, array, string, or unsupported value.
	 * @return array|null Serialized notification or null when unsupported.
	 */
	private static function notificationArray(mixed $notification): ?array {
		if($notification instanceof PanelNotification){
			return $notification->jsonSerialize();
		}
		if(is_string($notification) && trim($notification)!==''){
			return PanelNotification::info($notification)->jsonSerialize();
		}
		if(is_array($notification)){
			return PanelNotification::fromArray($notification)->jsonSerialize();
		}
		return null;
	}

	/**
	 * Filters and normalizes a notification list.
	 *
	 * @param array<int, PanelNotification|PanelInboxNotification|array<string, mixed>|string> $notifications Raw notification payloads.
	 * @return array<int,array<string,mixed>> Displayable notification arrays.
	 */
	private static function notificationList(array $notifications): array {
		$normalized=[];
		foreach($notifications as $notification){
			$notification=self::notificationArray($notification);
			if($notification!==null && trim((string)($notification['message'] ?? $notification['title'] ?? ''))!==''){
				$normalized[]=$notification;
			}
		}
		return $normalized;
	}

	/**
	 * Renders Panel notification banners.
	 *
	 * Notification types are normalized to safe tones, optional titles and actions
	 * are escaped, and empty messages are filtered before rendering.
	 *
	 * @param array<int, PanelNotification|PanelInboxNotification|array<string, mixed>|string> $notifications Raw notification payloads.
	 * @return string Notification banner HTML.
	 */
	private static function notificationsHtml(array $notifications): string {
		$html='';
		foreach(self::notificationList($notifications) as $notification){
			$type=strtolower(trim((string)($notification['type'] ?? 'info')));
			$type=$type==='error' ? 'error' : self::safeTone($type);
			if($type==='neutral'){
				$type='info';
			}
			$title=isset($notification['title']) && $notification['title']!==null ? '<strong>'.self::e((string)$notification['title']).'</strong>' : '';
			$message=self::e((string)($notification['message'] ?? ''));
			$actionLabel=trim((string)($notification['action_label'] ?? ''));
			$actionUrl=trim((string)($notification['action_url'] ?? ''));
			$action=$actionLabel!=='' && $actionUrl!=='' ? '<a href="'.self::e($actionUrl).'">'.self::e($actionLabel).'</a>' : '';
			$html.='<div class="dp-panel-notice dp-panel-notice-'.$type.'">'.$title.'<span>'.$message.'</span>'.$action.'</div>';
		}
		return $html;
	}
}
