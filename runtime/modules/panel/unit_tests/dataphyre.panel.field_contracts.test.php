<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Field;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return mixed */
function dp_panel_field_contract_path(array $data, string $path): mixed {
	$value=$data;
	foreach(explode('.', $path) as $segment){
		if(!is_array($value) || !array_key_exists($segment, $value)){
			return null;
		}
		$value=$value[$segment];
	}
	return $value;
}

dataset('panel field canonical control types', [
	'hiddenField'=>['hiddenField', 'hidden'],
	'hiddenInput'=>['hiddenInput', 'hidden'],
	'placeholderField'=>['placeholderField', 'placeholder'],
	'displayOnly'=>['displayOnly', 'display_only'],
	'viewField'=>['viewField', 'view_field'],
	'select'=>['select', 'select'],
	'enum'=>['enum', 'enum'],
	'multiSelect'=>['multiSelect', 'multi_select'],
	'countrySelect'=>['countrySelect', 'select'],
	'subdivisionSelect'=>['subdivisionSelect', 'select'],
	'radio'=>['radio', 'radio'],
	'checkboxList'=>['checkboxList', 'checkbox_list'],
	'toggleButtons'=>['toggleButtons', 'toggle_buttons'],
	'segmentedControl'=>['segmentedControl', 'segmented_control'],
	'buttonGroup'=>['buttonGroup', 'button_group'],
	'boolean'=>['boolean', 'boolean'],
	'toggle'=>['toggle', 'toggle'],
	'checkbox'=>['checkbox', 'checkbox'],
	'repeater'=>['repeater', 'repeater'],
	'fieldset'=>['fieldset', 'fieldset'],
	'fieldGroup'=>['fieldGroup', 'field_group'],
	'group'=>['group', 'group'],
	'address'=>['address', 'address'],
	'builder'=>['builder', 'builder'],
	'file'=>['file', 'file'],
	'fileUpload'=>['fileUpload', 'file_upload'],
	'upload'=>['upload', 'file_upload'],
	'dragDropUpload'=>['dragDropUpload', 'drag_drop_upload'],
	'imageUpload'=>['imageUpload', 'image'],
	'customUploader'=>['customUploader', 'file_upload'],
	'storageUploader'=>['storageUploader', 'file_upload'],
	'dataphyreStorageUpload'=>['dataphyreStorageUpload', 'file_upload'],
	'date'=>['date', 'date'],
	'dateTime'=>['dateTime', 'datetime'],
	'dateTimeLocal'=>['dateTimeLocal', 'datetime_local'],
	'time'=>['time', 'time'],
	'dateRange'=>['dateRange', 'date_range'],
	'dateTimeRange'=>['dateTimeRange', 'datetime_range'],
	'timeRange'=>['timeRange', 'time_range'],
	'number'=>['number', 'number'],
	'integer'=>['integer', 'integer'],
	'float'=>['float', 'float'],
	'decimal'=>['decimal', 'decimal'],
	'numeric'=>['numeric', 'number'],
	'text'=>['text', 'text'],
	'textInput'=>['textInput', 'text'],
	'search'=>['search', 'search'],
	'textarea'=>['textarea', 'textarea'],
	'longText'=>['longText', 'textarea'],
	'password'=>['password', 'password'],
	'currentPassword'=>['currentPassword', 'password'],
	'newPassword'=>['newPassword', 'password'],
	'passwordConfirmation'=>['passwordConfirmation', 'password'],
	'month'=>['month', 'month'],
	'week'=>['week', 'week'],
	'slider'=>['slider', 'slider'],
	'range'=>['range', 'range'],
	'rangeInput'=>['rangeInput', 'range'],
	'rangeSlider'=>['rangeSlider', 'slider'],
	'rating'=>['rating', 'rating'],
	'tags'=>['tags', 'tags'],
	'tagsInput'=>['tagsInput', 'tags_input'],
	'comboBox'=>['comboBox', 'combobox'],
	'currency'=>['currency', 'money'],
	'money'=>['money', 'money'],
	'percent'=>['percent', 'percent'],
	'percentage'=>['percentage', 'percent'],
	'phone'=>['phone', 'tel'],
	'phoneNumber'=>['phoneNumber', 'tel'],
	'internationalPhone'=>['internationalPhone', 'tel'],
	'phoneUs'=>['phoneUs', 'tel'],
	'phoneCa'=>['phoneCa', 'tel'],
	'phoneCountryField'=>['phoneCountryField', 'tel'],
	'email'=>['email', 'email'],
	'emailAddress'=>['emailAddress', 'email'],
	'url'=>['url', 'url'],
	'urlAddress'=>['urlAddress', 'url'],
	'json'=>['json', 'textarea'],
	'jsonText'=>['jsonText', 'textarea'],
	'markdown'=>['markdown', 'markdown'],
	'htmlEditor'=>['htmlEditor', 'html'],
	'richText'=>['richText', 'rich_text'],
	'richEditor'=>['richEditor', 'rich_editor'],
	'codeEditor'=>['codeEditor', 'code'],
	'code'=>['code', 'code'],
	'color'=>['color', 'color'],
	'latitude'=>['latitude', 'number'],
	'longitude'=>['longitude', 'number'],
	'keyValue'=>['keyValue', 'key_value'],
	'keyValuePairs'=>['keyValuePairs', 'key_value'],
	'image'=>['image', 'image'],
]);

test('field control aliases resolve a canonical renderer type', static function(Context $t, string $method, string $expected): void {
	$field=Field::make('contract')->{$method}();
	$manifest=$field->toArray();
	$t->same($expected, $manifest['type'] ?? null);
	$t->same($expected, $manifest['component']['type'] ?? null);
})->with('panel field canonical control types')->tag('panel', 'field', 'builder', 'type')->maxMillis(1000);

dataset('panel field semantic format rules', [
	'countrySelect'=>['countrySelect', 'country_code'],
	'subdivisionSelect'=>['subdivisionSelect', 'subdivision_code'],
	'currency'=>['currency', 'currency'],
	'money'=>['money', 'currency'],
	'percent'=>['percent', 'percent'],
	'percentage'=>['percentage', 'percent'],
	'phone'=>['phone', 'phone_international'],
	'phoneNumber'=>['phoneNumber', 'phone_international'],
	'internationalPhone'=>['internationalPhone', 'phone_international'],
	'phoneUs'=>['phoneUs', 'phone_us'],
	'phoneCa'=>['phoneCa', 'phone_ca'],
	'phoneCountryField'=>['phoneCountryField', 'phone_international'],
	'email'=>['email', 'email'],
	'emailAddress'=>['emailAddress', 'email'],
	'url'=>['url', 'url'],
	'urlAddress'=>['urlAddress', 'url'],
	'mapUrl'=>['mapUrl', 'map_url'],
	'mapsUrl'=>['mapsUrl', 'map_url'],
	'domain'=>['domain', 'domain'],
	'hostname'=>['hostname', 'domain'],
	'timezone'=>['timezone', 'timezone'],
	'locale'=>['locale', 'locale'],
	'json'=>['json', 'json'],
	'jsonText'=>['jsonText', 'json'],
	'mimeType'=>['mimeType', 'mime_type'],
	'contentType'=>['contentType', 'mime_type'],
	'semver'=>['semver', 'semver'],
	'semanticVersion'=>['semanticVersion', 'semver'],
	'cronExpression'=>['cronExpression', 'cron_expression'],
	'cron'=>['cron', 'cron_expression'],
	'languageCode'=>['languageCode', 'language_code'],
	'isoLanguage'=>['isoLanguage', 'language_code'],
	'countryCode'=>['countryCode', 'country_code'],
	'isoCountry'=>['isoCountry', 'country_code'],
	'subdivisionCode'=>['subdivisionCode', 'subdivision_code'],
	'regionCode'=>['regionCode', 'subdivision_code'],
	'subdivisionCodeCountryField'=>['subdivisionCodeCountryField', 'subdivision_code'],
	'currencyCode'=>['currencyCode', 'currency_code'],
	'isoCurrency'=>['isoCurrency', 'currency_code'],
	'ipAddress'=>['ipAddress', 'ip_address'],
	'ip'=>['ip', 'ip_address'],
	'ipv4'=>['ipv4', 'ipv4'],
	'ipv6'=>['ipv6', 'ipv6'],
	'macAddress'=>['macAddress', 'mac_address'],
	'mac'=>['mac', 'mac_address'],
	'uuid'=>['uuid', 'uuid'],
	'ulid'=>['ulid', 'ulid'],
	'hexColor'=>['hexColor', 'hex_color'],
	'colorHex'=>['colorHex', 'hex_color'],
	'latitude'=>['latitude', 'latitude'],
	'longitude'=>['longitude', 'longitude'],
	'coordinates'=>['coordinates', 'coordinates'],
	'latLng'=>['latLng', 'coordinates'],
	'lngLat'=>['lngLat', 'lng_lat'],
	'postalCode'=>['postalCode', 'postal_code_ca'],
	'postalCodeCountryField'=>['postalCodeCountryField', 'postal_code'],
	'postalCodeSubdivisionField'=>['postalCodeSubdivisionField', 'postal_code'],
	'postalCodeLocaleFields'=>['postalCodeLocaleFields', 'postal_code'],
	'zipCode'=>['zipCode', 'zip_code_us'],
	'zipCodeCountryField'=>['zipCodeCountryField', 'postal_code'],
	'creditCard'=>['creditCard', 'credit_card'],
	'creditCardExpiry'=>['creditCardExpiry', 'credit_card_expiry'],
	'cardExpiry'=>['cardExpiry', 'credit_card_expiry'],
	'cardCvc'=>['cardCvc', 'card_cvc'],
	'cvc'=>['cvc', 'card_cvc'],
	'cvv'=>['cvv', 'card_cvc'],
	'iban'=>['iban', 'iban'],
	'slug'=>['slug', 'slug'],
	'uppercase'=>['uppercase', 'uppercase'],
	'lowercase'=>['lowercase', 'lowercase'],
	'titleCase'=>['titleCase', 'title_case'],
	'sentenceCase'=>['sentenceCase', 'sentence_case'],
	'snakeCase'=>['snakeCase', 'snake_case'],
	'kebabCase'=>['kebabCase', 'kebab_case'],
	'camelCase'=>['camelCase', 'camel_case'],
	'trimmed'=>['trimmed', 'trim'],
	'digits'=>['digits', 'digits'],
	'alpha'=>['alpha', 'alpha'],
	'alphanumeric'=>['alphanumeric', 'alphanumeric'],
]);

test('field semantic helpers install the documented normalization rule', static function(Context $t, string $method, string $expected): void {
	$manifest=Field::make('contract')->{$method}()->toArray();
	$t->same($expected, $manifest['meta']['format_rule'] ?? null);
	$t->contains('format', $manifest['component']['capabilities'] ?? []);
	$t->contains('normalizes_submit', $manifest['component']['capabilities'] ?? []);
})->with('panel field semantic format rules')->tag('panel', 'field', 'format')->maxMillis(1000);

dataset('panel field mask contracts', [
	'ssn'=>['ssn', '999-99-9999', 'numeric'],
	'ein'=>['ein', '99-9999999', 'numeric'],
	'oneTimeCode'=>['oneTimeCode', '999999', 'numeric'],
	'verificationCode'=>['verificationCode', '999999', 'numeric'],
	'otp'=>['otp', '999999', 'numeric'],
	'pinCode'=>['pinCode', '9999', 'numeric'],
]);

test('field identity helpers expose deterministic masks and input modes', static function(Context $t, string $method, string $mask, string $inputMode): void {
	$manifest=Field::make('contract')->{$method}()->toArray();
	$t->same($mask, $manifest['meta']['mask'] ?? null);
	$t->same($inputMode, $manifest['meta']['input_mode'] ?? null);
	$t->same(true, $manifest['meta']['mask_submit_normalized'] ?? null);
})->with('panel field mask contracts')->tag('panel', 'field', 'mask')->maxMillis(1000);

dataset('panel field fluent defaults', [
	'required'=>['required', 'required', true],
	'readonly'=>['readonly', 'readonly', true],
	'disabled'=>['disabled', 'meta.disabled', true],
	'disable alias'=>['disable', 'meta.disabled', true],
	'dehydrated'=>['dehydrated', 'meta.dehydrated', true],
	'dehydrate alias'=>['dehydrate', 'meta.dehydrated', true],
	'hidden'=>['hidden', 'hidden', true],
	'minTouchTarget'=>['minTouchTarget', 'meta.accessibility.min_touch_target', 44],
	'maxAdornmentRatio'=>['maxAdornmentRatio', 'meta.accessibility.max_adornment_ratio', 0.45],
	'maxLabelRatio'=>['maxLabelRatio', 'meta.accessibility.max_label_ratio', 0.55],
	'inheritAccessibilityPolicy'=>['inheritAccessibilityPolicy', 'meta.accessibility_inherit', true],
	'withoutAccessibilityPolicy'=>['withoutAccessibilityPolicy', 'meta.accessibility_inherit', false],
	'noAccessibilityPolicy alias'=>['noAccessibilityPolicy', 'meta.accessibility_inherit', false],
	'fullWidth'=>['fullWidth', 'meta.column_span', 'full'],
	'badge'=>['badge', 'meta.badge', true],
	'copyable'=>['copyable', 'meta.copyable', true],
	'copyableNormalized'=>['copyableNormalized', 'meta.copy_normalized', true],
	'html'=>['html', 'meta.html', true],
	'inlineChoices'=>['inlineChoices', 'meta.inline_choices', true],
	'stackedChoices'=>['stackedChoices', 'meta.choice_columns', 1],
	'autoResize'=>['autoResize', 'meta.auto_resize', true],
	'autosize alias'=>['autosize', 'meta.auto_resize', true],
	'multiple'=>['multiple', 'meta.multiple', true],
	'searchable'=>['searchable', 'meta.searchable', true],
	'native'=>['native', 'meta.native', true],
	'colorSwatch'=>['colorSwatch', 'meta.color_swatch', true],
	'hideColorSwatch'=>['hideColorSwatch', 'meta.color_swatch', false],
	'revealable'=>['revealable', 'meta.password_reveal', true],
	'passwordReveal alias'=>['passwordReveal', 'meta.password_reveal', true],
	'submitNormalized'=>['submitNormalized', 'meta.submit_normalized', true],
	'submitFormatted'=>['submitFormatted', 'meta.submit_normalized', false],
	'characterCounter'=>['characterCounter', 'meta.character_counter', true],
	'charCounter alias'=>['charCounter', 'meta.character_counter', true],
	'counter alias'=>['counter', 'meta.character_counter', true],
	'preview'=>['preview', 'meta.preview', true],
	'clearable'=>['clearable', 'meta.clearable', true],
	'live'=>['live', 'live', true],
	'reactive'=>['reactive', 'reactive', true],
]);

test('field fluent helpers apply stable default metadata', static function(Context $t, string $method, string $path, mixed $expected): void {
	$manifest=Field::make('contract')->{$method}()->toArray();
	$t->same($expected, dp_panel_field_contract_path($manifest, $path));
})->with('panel field fluent defaults')->tag('panel', 'field', 'defaults')->maxMillis(1000);

test('field array hydration accepts the complete manifest contract', static function(Context $t): void {
	$field=Field::fromArray([
		'name'=>'contract',
		'type'=>'builder',
		'label'=>'Contract',
		'placeholder'=>'Enter value',
		'help'=>'Helpful copy',
		'helper_text'=>'Helper text',
		'hint'=>'Hint text',
		'hint_icon'=>'info',
		'accessibility'=>['min_touch_target'=>48],
		'a11y'=>['max_label_ratio'=>0.6],
		'min_usable_width'=>12,
		'min_usable_width_unit'=>'rem',
		'min_usable_chars'=>8,
		'min_touch_target'=>48,
		'max_adornment_ratio'=>0.4,
		'max_label_ratio'=>0.6,
		'contrast_policy'=>['min_ratio'=>7.0, 'scope'=>'text'],
		'contrast_min_ratio'=>4.5,
		'default'=>'ready',
		'required'=>true,
		'readonly'=>true,
		'disabled'=>true,
		'hidden'=>true,
		'content'=>'Display content',
		'display_content'=>'Display alias',
		'html_content'=>'<strong>Safe</strong>',
		'dehydrated'=>false,
		'visible_on'=>['create', 'edit'],
		'hidden_on'=>['index'],
		'depends_on'=>['country'],
		'live'=>true,
		'debounce_ms'=>150,
		'reactive'=>true,
		'visible_when'=>['country'=>'CA'],
		'hidden_when'=>['archived'=>true],
		'required_when'=>['status'=>'open'],
		'required_if'=>['field'=>'priority', 'value'=>'high'],
		'required_unless'=>['type'=>'guest'],
		'options'=>['open'=>'Open', 'closed'=>'Closed'],
		'relationship'=>'customers',
		'related_resource'=>'customers',
		'title_attribute'=>'name',
		'key_attribute'=>'id',
		'choice_columns'=>2,
		'inline_choices'=>true,
		'repeater_fields'=>[['name'=>'email', 'type'=>'email']],
		'child_fields'=>[['name'=>'city', 'type'=>'text']],
		'builder_blocks'=>[['name'=>'text', 'label'=>'Text', 'fields'=>[['name'=>'body', 'type'=>'textarea']]]],
		'blocks'=>['image'=>['label'=>'Image', 'fields'=>[['name'=>'url', 'type'=>'url']]]],
		'accepted_types'=>['image/png', 'application/pdf'],
		'max_file_size'=>1048576,
		'custom_uploader'=>true,
		'upload_endpoint'=>'/panel/upload',
		'upload_delete_endpoint'=>'/panel/upload/delete',
		'upload_chunk_size'=>262144,
		'upload_retries'=>3,
		'upload_concurrency'=>2,
		'upload_headers'=>['X-Test'=>'ready'],
		'upload_fields'=>['tenant'=>'demo'],
		'upload_labels'=>['browse'=>'Choose file'],
		'upload_csrf_form'=>'panel_upload',
		'upload_csrf_field'=>'csrf',
		'upload_csrf_header'=>'X-CSRF-Token',
		'upload_min_files'=>1,
		'upload_max_files'=>4,
		'storage_disk'=>'local',
		'storage_path'=>'panel/{filename}',
		'rows'=>6,
		'auto_resize'=>true,
		'min'=>1,
		'max'=>100,
		'step'=>5,
		'today_button'=>'Today',
		'now_button'=>'Now',
		'show_value'=>true,
		'on_label'=>'Enabled',
		'off_label'=>'Disabled',
		'tag_separator'=>';',
		'min_tags'=>1,
		'max_tags'=>5,
		'pair_separator'=>'|',
		'key_separator'=>':',
		'min_pairs'=>1,
		'max_pairs'=>8,
		'searchable'=>true,
		'search_placeholder'=>'Find option',
		'no_results_text'=>'Nothing found',
		'media_collection'=>['name'=>'attachments', 'disk'=>'local'],
		'media_variants'=>['thumb'=>['width'=>120]],
		'multiple'=>true,
		'suggestions'=>['alpha', 'beta'],
		'datalist'=>['one', 'two'],
		'autocomplete_options'=>['shipping street-address'],
		'copyable'=>true,
		'copy_normalized'=>true,
		'revealable'=>true,
		'color_swatch'=>true,
		'prefix'=>'$',
		'suffix'=>'CAD',
		'prefix_icon'=>'currency',
		'prefix_icon_label'=>'Currency',
		'suffix_icon'=>'check',
		'suffix_icon_label'=>'Valid',
		'prepend_buttons'=>[['label'=>'Clear', 'action'=>'clear']],
		'append_buttons'=>[['label'=>'Apply', 'action'=>'apply']],
		'prepend_button'=>['label'=>'Start', 'action'=>'start'],
		'append_button'=>['label'=>'End', 'action'=>'end'],
		'mask'=>'999-999',
		'mask_placeholder'=>'___-___',
		'submit_unmasked'=>true,
		'format'=>'uppercase',
		'format_options'=>['locale'=>'en_CA'],
		'country'=>'CA',
		'country_field'=>'country',
		'subdivision'=>'ON',
		'subdivision_field'=>'subdivision',
		'source_field'=>'raw_value',
		'format_placeholder'=>'FORMATTED',
		'format_event'=>'blur',
		'submit_normalized'=>true,
		'submit_formatted'=>false,
		'input_mode'=>'text',
		'autocomplete'=>'off',
		'min_length'=>2,
		'max_length'=>40,
		'length'=>12,
		'length_between'=>[2, 40],
		'character_counter'=>40,
		'counter_position'=>'append',
		'pattern'=>'[A-Z]+',
		'title'=>'Uppercase value',
		'preview'=>true,
		'preview_mode'=>'markdown',
		'editor'=>'code',
		'editor_options'=>['line_numbers'=>true],
		'code_language'=>'php',
		'clearable'=>true,
		'nullable'=>true,
		'regex'=>'/^[A-Z]+$/',
		'confirmed'=>true,
		'same'=>'matching',
		'different'=>'other',
		'starts_with'=>['A'],
		'ends_with'=>['Z'],
		'rules'=>['string', 'max:40'],
		'meta'=>['probe'=>'complete'],
		'state_using'=>static fn(): string=>'ready',
	]);
	$address=Field::fromArray([
		'name'=>'address',
		'type'=>'address',
		'address_country'=>'CA',
		'address_line2'=>false,
	]);
	$fieldset=Field::fromArray([
		'name'=>'group',
		'type'=>'fieldset',
		'fields'=>[['name'=>'child', 'type'=>'text']],
	]);

	$t->same('contract', $field->name());
	$t->pathEquals('meta.probe', 'complete', $field->toArray());
	$t->same('address', $address->toArray()['type'] ?? null);
	$t->same('fieldset', $fieldset->toArray()['type'] ?? null);
})->tag('panel', 'field', 'hydration', 'coverage')->maxMillis(5000);

/**
 * Supplies a conservative valid value for required fluent-method parameters.
 */
function dp_panel_field_smoke_argument(ReflectionParameter $parameter): mixed {
	if($parameter->isDefaultValueAvailable()){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || $candidate->getName()==='null'){
			continue;
		}
		$name=$candidate->getName();
		if(!$candidate->isBuiltin()){
			if(is_a($name, Field::class, true)){
				return Field::make('child');
			}
			if(is_a($name, DateTimeInterface::class, true)){
				return new DateTimeImmutable('2026-01-01 00:00:00 UTC');
			}
			continue;
		}
		return match($name){
			'array'=>[],
			'bool'=>true,
			'callable'=>static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [],
			'float'=>1.0,
			'int'=>1,
			'object'=>new stdClass(),
			'string'=>match(strtolower($parameter->getName())){
				'pattern', 'regex'=>'/.*/',
				'event'=>'blur',
				'position'=>'append',
				'country'=>'CA',
				'currency'=>'CAD',
				'language'=>'plain',
				'path'=>'panel_uploads/{filename}',
				'endpoint', 'deleteendpoint'=>'/panel/upload',
				default=>'contract',
			},
			default=>'value',
		};
	}
	if($type?->allowsNull()){
		return null;
	}
	return 'value';
}

test('every public field method accepts its documented default or a type-safe required value', static function(Context $t): void {
	$inventory=$t->inventory(Field::class);
	$methods=[];
	$failures=[];
	foreach($inventory->declaredPublicMethods(false) as $method){
		if(str_starts_with($method->getName(), '__')){
			continue;
		}
		$methods[]=$method->getName();
		$field=Field::make('contract');
		$arguments=array_map(dp_panel_field_smoke_argument(...), $method->getParameters());
		try{
			$inventory->invokeWithArguments($method,$field,$arguments);
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->greaterThanOrEqual(350, count($methods));
	$t->same([], $failures);
})->tag('panel', 'field', 'public-api', 'coverage')->maxMillis(5000);
