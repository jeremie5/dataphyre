<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Process-local registry of Panel component descriptors.
 *
 * The registry catalogs schema kinds, fields, columns, actions, filters, relations, widgets, pages, resources, summaries, navigation items, imports, and exports for builders and renderers.
 */
final class PanelComponentRegistry {

	/** @var array<string,array<string,mixed>> */
	private static array $schemaKinds=[];
	/** @var array<string,array<string,mixed>> */
	private static array $fieldTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $columnTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $actionTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $filterTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $relationTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $widgetTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $pageTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $resourceTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $summaryTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $viewTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $navigationTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $exportTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $importTypes=[];
	/** @var array<string,array<string,mixed>> */
	private static array $bulkOperationTypes=[];

	/**
	 * Prevents construction of the process-local registry.
	 *
	 * Registry state is held in static descriptor maps and is initialized through
	 * boot() or flush(); instances would not carry independent state.
	 */
	private function __construct() {
	}

	/**
	 * Initializes the Panel component registry.
	 *
	 * Registry boot loads default component descriptors for schemas, fields, columns, actions, filters, relations, widgets, pages, resources, summaries, navigation, imports, and exports.
	 * @return void
	 */
	public static function flush(): void {
		self::$schemaKinds=self::defaultSchemaKinds();
		self::$fieldTypes=self::defaultFieldTypes();
		self::$columnTypes=self::defaultColumnTypes();
		self::$actionTypes=self::defaultActionTypes();
		self::$filterTypes=self::defaultFilterTypes();
		self::$relationTypes=self::defaultRelationTypes();
		self::$widgetTypes=self::defaultWidgetTypes();
		self::$pageTypes=self::defaultPageTypes();
		self::$resourceTypes=self::defaultResourceTypes();
		self::$summaryTypes=self::defaultSummaryTypes();
		self::$viewTypes=self::defaultViewTypes();
		self::$navigationTypes=self::defaultNavigationTypes();
		self::$exportTypes=self::defaultExportTypes();
		self::$importTypes=self::defaultImportTypes();
		self::$bulkOperationTypes=self::defaultBulkOperationTypes();
	}

	/**
	 * Initializes the Panel component registry.
	 *
	 * Registry boot loads default component descriptors for schemas, fields, columns, actions, filters, relations, widgets, pages, resources, summaries, navigation, imports, and exports.
	 * @return void
	 */
	public static function boot(): void {
		if(self::$schemaKinds===[]){
			self::flush();
		}
	}

	/**
	 * Defines built-in schema component kinds for Panel form/resource layouts.
	 *
	 * These keys classify structural schema nodes before renderer resolution, with
	 * field as the fallback kind used by normalizeSchemaKind().
	 *
	 * @return array<string,array{label:string}> Built-in schema-kind descriptors.
	 */
	private static function defaultSchemaKinds(): array {
		return [
			'field'=>['label'=>'Field'],
			'section'=>['label'=>'Section'],
			'group'=>['label'=>'Group'],
			'tab'=>['label'=>'Tab'],
			'step'=>['label'=>'Step'],
			'infolist'=>['label'=>'Infolist'],
			'layout'=>['label'=>'Layout'],
		];
	}

	/**
	 * Defines built-in field type descriptors and capabilities.
	 *
	 * Field descriptors describe the control input family, user-facing label,
	 * renderer slot, builtin flag, upload behavior, and feature capabilities used
	 * by builders, validators, and renderers.
	 *
	 * @return array<string,array<string,mixed>> Built-in field type descriptors keyed by normalized type.
	 */
	private static function defaultFieldTypes(): array {
		$types=[
			'text'=>'Text',
			'search'=>'Search',
			'autocomplete'=>'Autocomplete',
			'combobox'=>'Combobox',
			'combo_box'=>'Combobox',
			'email'=>'Email',
			'number'=>'Number',
			'integer'=>'Integer',
			'float'=>'Float',
			'decimal'=>'Decimal',
			'money'=>'Money',
			'currency'=>'Currency',
			'percent'=>'Percent',
			'percentage'=>'Percentage',
			'password'=>'Password',
			'otp'=>'One-time code',
			'one_time_code'=>'One-time code',
			'verification_code'=>'Verification code',
			'pin'=>'PIN',
			'pin_code'=>'PIN code',
			'credit_card'=>'Credit card',
			'card_number'=>'Card number',
			'credit_card_expiry'=>'Card expiry',
			'card_expiry'=>'Card expiry',
			'card_cvc'=>'Card CVC',
			'cvc'=>'CVC',
			'cvv'=>'CVV',
			'iban'=>'IBAN',
			'slug'=>'Slug',
			'domain'=>'Domain',
			'hostname'=>'Hostname',
			'timezone'=>'Timezone',
			'time_zone'=>'Timezone',
			'locale'=>'Locale',
			'language_tag'=>'Language tag',
			'mime_type'=>'MIME type',
			'content_type'=>'Content type',
			'semver'=>'Semantic version',
			'semantic_version'=>'Semantic version',
			'cron_expression'=>'Cron expression',
			'cron'=>'Cron expression',
			'language_code'=>'Language code',
			'iso_language'=>'ISO language',
			'country_code'=>'Country code',
			'iso_country'=>'ISO country',
			'subdivision_code'=>'Subdivision code',
			'region_code'=>'Region code',
			'currency_code'=>'Currency code',
			'iso_currency'=>'ISO currency',
			'ip_address'=>'IP address',
			'ip'=>'IP address',
			'ipv4'=>'IPv4 address',
			'ipv6'=>'IPv6 address',
			'mac_address'=>'MAC address',
			'mac'=>'MAC address',
			'uuid'=>'UUID',
			'ulid'=>'ULID',
			'hex_color'=>'Hex color',
			'color_hex'=>'Hex color',
			'latitude'=>'Latitude',
			'longitude'=>'Longitude',
			'coordinates'=>'Coordinates',
			'lat_lng'=>'Latitude/longitude',
			'lng_lat'=>'Longitude/latitude',
			'phone_international'=>'International phone',
			'postal_code'=>'Postal code',
			'postal'=>'Postal code',
			'postal_code_ca'=>'Canadian postal code',
			'canadian_postal_code'=>'Canadian postal code',
			'zip_code_us'=>'US ZIP code',
			'postal_code_us'=>'US ZIP code',
			'zip'=>'ZIP code',
			'date'=>'Date',
			'datetime'=>'Date and time',
			'datetime_local'=>'Local date and time',
			'date_range'=>'Date range',
			'daterange'=>'Date range',
			'date_time_range'=>'Date and time range',
			'datetime_range'=>'Date and time range',
			'month'=>'Month',
			'week'=>'Week',
			'time_range'=>'Time range',
			'timerange'=>'Time range',
			'textarea'=>'Textarea',
			'json'=>'JSON',
			'markdown'=>'Markdown',
			'html'=>'HTML',
			'rich_editor'=>'Rich editor',
			'rich_text'=>'Rich text',
			'code'=>'Code',
			'placeholder'=>'Placeholder',
			'display'=>'Display',
			'display_only'=>'Display only',
			'view_field'=>'View field',
			'select'=>'Select',
			'relationship'=>'Relationship',
			'belongs_to'=>'Belongs to',
			'multi_relationship'=>'Multi relationship',
			'belongs_to_many'=>'Belongs to many',
			'relation'=>'Relation',
			'multi_select'=>'Multi select',
			'multiselect'=>'Multi select',
			'enum'=>'Enum',
			'radio'=>'Radio',
			'checkbox_list'=>'Checkbox list',
			'toggle_buttons'=>'Toggle buttons',
			'togglebuttons'=>'Toggle buttons',
			'segmented'=>'Segmented control',
			'segmented_control'=>'Segmented control',
			'button_group'=>'Button group',
			'boolean'=>'Boolean',
			'bool'=>'Boolean',
			'checkbox'=>'Checkbox',
			'toggle'=>'Toggle',
			'hidden'=>'Hidden',
			'url'=>'URL',
			'tel'=>'Telephone',
			'time'=>'Time',
			'color'=>'Color',
			'range'=>'Range',
			'slider'=>'Slider',
			'rating'=>'Rating',
			'tags'=>'Tags',
			'tags_input'=>'Tags input',
			'key_value'=>'Key value',
			'keyvalue'=>'Key value',
			'file'=>'File',
			'file_upload'=>'File upload',
			'upload'=>'Upload',
			'drag_drop_upload'=>'Drag and drop upload',
			'image'=>'Image',
			'repeater'=>'Repeater',
			'builder'=>'Builder',
			'fieldset'=>'Fieldset',
			'group'=>'Field group',
			'field_group'=>'Field group',
		];
		$definitions=self::defaultTypedDefinitions($types);
		foreach(['number', 'integer', 'float', 'decimal', 'money', 'currency', 'percent', 'percentage'] as $numericType){
			$definitions[$numericType]=array_replace($definitions[$numericType], [
				'input'=>'number',
				'capabilities'=>['min', 'max', 'step', 'steppers'],
			]);
		}
		foreach(['money', 'currency'] as $moneyType){
			$definitions[$moneyType]['capabilities'][]='currency_format';
			$definitions[$moneyType]['capabilities'][]='currency_adornment';
		}
		foreach(['percent', 'percentage'] as $percentType){
			$definitions[$percentType]['capabilities'][]='percent_format';
			$definitions[$percentType]['capabilities'][]='percent_adornment';
		}
		$definitions['password']=array_replace($definitions['password'], [
			'input'=>'password',
			'capabilities'=>['secret', 'revealable', 'autocomplete', 'adornments'],
		]);
		foreach(['otp', 'one_time_code', 'verification_code', 'pin', 'pin_code'] as $codeType){
			$definitions[$codeType]=array_replace($definitions[$codeType], [
				'input'=>'text',
				'capabilities'=>['mask', 'one_time_code', 'character_counter', 'autocomplete', 'adornments'],
			]);
		}
		foreach(['credit_card', 'card_number', 'credit_card_expiry', 'card_expiry', 'card_cvc', 'cvc', 'cvv', 'iban', 'slug', 'domain', 'hostname', 'timezone', 'time_zone', 'locale', 'language_tag', 'mime_type', 'content_type', 'semver', 'semantic_version', 'cron_expression', 'cron', 'language_code', 'iso_language', 'country_code', 'iso_country', 'subdivision_code', 'region_code', 'currency_code', 'iso_currency', 'ip_address', 'ip', 'ipv4', 'ipv6', 'mac_address', 'mac', 'uuid', 'ulid', 'hex_color', 'color_hex', 'coordinates', 'lat_lng', 'lng_lat', 'phone_international', 'postal_code', 'postal', 'postal_code_ca', 'canadian_postal_code', 'zip_code_us', 'postal_code_us', 'zip'] as $formattedType){
			$definitions[$formattedType]=array_replace($definitions[$formattedType], [
				'input'=>'text',
				'capabilities'=>['format', 'validation', 'normalizes_submit', 'adornments'],
			]);
		}
		foreach(['latitude', 'longitude'] as $coordinateType){
			$definitions[$coordinateType]=array_replace($definitions[$coordinateType], [
				'input'=>'number',
				'capabilities'=>['format', 'validation', 'normalizes_submit', 'min', 'max', 'step', 'adornments'],
			]);
		}
		$definitions['hidden']=array_replace($definitions['hidden'], [
			'input'=>'hidden',
			'capabilities'=>['hidden_input', 'submitted_value'],
		]);
		$definitions['text']=array_replace($definitions['text'], [
			'input'=>'text',
			'capabilities'=>['placeholder', 'max_length', 'adornments'],
		]);
		$definitions['search']=array_replace($definitions['search'], [
			'input'=>'search',
			'capabilities'=>['placeholder', 'max_length', 'autocomplete', 'adornments'],
		]);
		foreach(['autocomplete', 'combobox', 'combo_box'] as $autocompleteType){
			$definitions[$autocompleteType]=array_replace($definitions[$autocompleteType], [
				'input'=>'text',
				'capabilities'=>['placeholder', 'suggestions', 'datalist', 'free_text', 'autocomplete', 'adornments'],
			]);
		}
		$definitions['textarea']=array_replace($definitions['textarea'], [
			'input'=>'textarea',
			'capabilities'=>['rows', 'auto_resize', 'multiline', 'placeholder'],
		]);
		$definitions['json']=array_replace($definitions['json'], [
			'input'=>'textarea',
			'capabilities'=>['rows', 'auto_resize', 'multiline', 'format', 'validation', 'normalizes_submit', 'placeholder'],
		]);
		foreach(['file', 'file_upload', 'upload', 'drag_drop_upload', 'image'] as $fileType){
			$definitions[$fileType]=array_replace($definitions[$fileType], [
				'input'=>'file',
				'file_upload'=>true,
				'capabilities'=>['accepted_types', 'max_file_size', 'multiple', 'custom_uploader'],
			]);
		}
		$definitions['image']['capabilities'][]='image_only';
		$definitions['repeater']=array_replace($definitions['repeater'], [
			'input'=>'fieldset',
			'capabilities'=>['nested_fields', 'min_items', 'max_items', 'add_remove_rows'],
		]);
		$definitions['builder']=array_replace($definitions['builder'], [
			'input'=>'fieldset',
			'capabilities'=>['nested_fields', 'builder_blocks', 'min_items', 'max_items', 'add_remove_rows'],
		]);
		foreach(['fieldset', 'group', 'field_group'] as $groupType){
			$definitions[$groupType]=array_replace($definitions[$groupType], [
				'input'=>'fieldset',
				'capabilities'=>['nested_fields', 'field_group', 'nested_object'],
			]);
		}
		$definitions['address']=array_replace($definitions['address'] ?? [], [
			'type'=>'address',
			'label'=>'Address',
			'category'=>'structure',
			'input'=>'fieldset',
			'capabilities'=>['nested_fields', 'field_group', 'nested_object', 'address', 'country', 'subdivision', 'postal_code', 'country_aware_validation'],
		]);
		$definitions['code']=array_replace($definitions['code'], [
			'input'=>'textarea',
			'capabilities'=>['preview', 'monospace', 'language'],
		]);
		foreach(['placeholder', 'display', 'display_only', 'view_field'] as $displayType){
			$definitions[$displayType]=array_replace($definitions[$displayType], [
				'input'=>'none',
				'capabilities'=>['display_only', 'static_content', 'safe_html', 'not_dehydrated'],
			]);
		}
		$definitions['tags']=array_replace($definitions['tags'], [
			'input'=>'text',
			'capabilities'=>['chips', 'suggestions', 'separator'],
		]);
		$definitions['tags_input']=array_replace($definitions['tags_input'], [
			'input'=>'text',
			'capabilities'=>['chips', 'suggestions', 'separator'],
		]);
		$definitions['key_value']=array_replace($definitions['key_value'], [
			'input'=>'textarea',
			'capabilities'=>['pairs', 'preview', 'separators'],
		]);
		$definitions['keyvalue']=array_replace($definitions['keyvalue'], [
			'input'=>'textarea',
			'capabilities'=>['pairs', 'preview', 'separators'],
		]);
		foreach(['radio', 'checkbox_list'] as $choiceType){
			$definitions[$choiceType]=array_replace($definitions[$choiceType], [
				'input'=>$choiceType==='radio' ? 'radio' : 'checkbox',
				'capabilities'=>['options', 'choice_cards', 'columns', 'inline'],
			]);
		}
		foreach(['toggle_buttons', 'togglebuttons', 'segmented', 'segmented_control', 'button_group'] as $buttonChoiceType){
			$definitions[$buttonChoiceType]=array_replace($definitions[$buttonChoiceType], [
				'input'=>'radio',
				'capabilities'=>['options', 'segmented_buttons', 'columns', 'single_or_multiple'],
			]);
		}
		foreach(['select', 'enum', 'multi_select', 'multiselect'] as $choiceType){
			$definitions[$choiceType]=array_replace($definitions[$choiceType], [
				'capabilities'=>array_values(array_unique(array_merge($definitions[$choiceType]['capabilities'] ?? [], ['options', 'searchable', 'native_select']))),
			]);
		}
		foreach(['relationship', 'belongs_to', 'relation'] as $relationshipType){
			$definitions[$relationshipType]=array_replace($definitions[$relationshipType], [
				'input'=>'select',
				'capabilities'=>['options', 'searchable', 'native_select', 'relationship', 'related_resource', 'title_attribute', 'key_attribute'],
			]);
		}
		foreach(['multi_relationship', 'belongs_to_many'] as $relationshipType){
			$definitions[$relationshipType]=array_replace($definitions[$relationshipType], [
				'input'=>'select',
				'capabilities'=>['options', 'multiple', 'searchable', 'native_select', 'relationship', 'related_resource', 'title_attribute', 'key_attribute'],
			]);
		}
		$definitions['enum']=array_replace($definitions['enum'], [
			'input'=>'select',
			'capabilities'=>array_values(array_unique(array_merge($definitions['enum']['capabilities'] ?? [], ['options', 'native_select']))),
		]);
		foreach(['boolean', 'bool', 'checkbox', 'toggle'] as $booleanType){
			$definitions[$booleanType]=array_replace($definitions[$booleanType], [
				'input'=>'checkbox',
				'capabilities'=>['switch', 'labels', 'hidden_false_value'],
			]);
		}
		foreach(['date', 'datetime', 'datetime_local', 'month', 'week', 'time'] as $dateType){
			$definitions[$dateType]=array_replace($definitions[$dateType], [
				'capabilities'=>array_values(array_unique(array_merge($definitions[$dateType]['capabilities'] ?? [], ['min', 'max', 'step', 'quick_fill']))),
			]);
		}
		foreach(['date_range', 'daterange', 'date_time_range', 'datetime_range', 'time_range', 'timerange'] as $rangeType){
			$definitions[$rangeType]=array_replace($definitions[$rangeType], [
				'input'=>str_contains($rangeType, 'time') && !str_contains($rangeType, 'date') ? 'time' : (str_contains($rangeType, 'datetime') || str_contains($rangeType, 'date_time') ? 'datetime-local' : 'date'),
				'capabilities'=>['range_pair', 'min', 'max', 'step'],
			]);
		}
		$definitions['color']=array_replace($definitions['color'], [
			'input'=>'color',
			'capabilities'=>['native_color_picker', 'swatch'],
		]);
		$definitions['slider']=array_replace($definitions['slider'], [
			'input'=>'range',
			'capabilities'=>['min', 'max', 'step', 'value_display'],
		]);
		$definitions['range']=array_replace($definitions['range'], [
			'input'=>'range',
			'capabilities'=>['min', 'max', 'step'],
		]);
		$definitions['rating']=array_replace($definitions['rating'], [
			'input'=>'radio',
			'capabilities'=>['rating', 'min', 'max', 'step'],
		]);
		return $definitions;
	}

	/**
	 * Defines built-in table column descriptor types.
	 *
	 * Column descriptors share the standard typed descriptor shape and reserve a
	 * renderer callable slot for custom table-cell rendering.
	 *
	 * @return array<string,array<string,mixed>> Built-in column descriptors.
	 */
	private static function defaultColumnTypes(): array {
		return self::defaultTypedDefinitions([
			'text'=>'Text',
			'badge'=>'Badge',
			'url'=>'URL',
			'email'=>'Email',
			'money'=>'Money',
			'percent'=>'Percent',
			'number'=>'Number',
			'boolean'=>'Boolean',
			'date'=>'Date',
			'datetime'=>'Date and time',
		]);
	}

	/**
	 * Defines built-in action descriptor types.
	 *
	 * Actions use a handler callable slot rather than renderer because their
	 * primary extension point is command execution, modal orchestration, or
	 * navigation behavior.
	 *
	 * @return array<string,array<string,mixed>> Built-in action descriptors.
	 */
	private static function defaultActionTypes(): array {
		return self::defaultTypedDefinitions([
			'button'=>'Button',
			'link'=>'Link',
			'modal'=>'Modal',
			'bulk'=>'Bulk action',
			'row'=>'Row action',
			'page'=>'Page action',
		], 'handler');
	}

	/**
	 * Defines built-in filter descriptor types for table/query builders.
	 *
	 * Filter descriptors seed the vocabulary for text, choice, boolean, date, and
	 * numeric range controls before resources add field-specific metadata.
	 *
	 * @return array<string,array<string,mixed>> Built-in filter descriptors.
	 */
	private static function defaultFilterTypes(): array {
		return self::defaultTypedDefinitions([
			'text'=>'Text',
			'select'=>'Select',
			'enum'=>'Enum',
			'boolean'=>'Boolean',
			'bool'=>'Boolean',
			'checkbox'=>'Checkbox',
			'toggle'=>'Toggle',
			'date'=>'Date',
			'range'=>'Range',
			'date_range'=>'Date range',
			'number_range'=>'Number range',
			'numeric_range'=>'Numeric range',
			'money_range'=>'Money range',
		]);
	}

	/**
	 * Defines built-in relation presentation types.
	 *
	 * Relation descriptors distinguish table-style relations, polymorphic lists,
	 * session-backed collections, and computed relation panels.
	 *
	 * @return array<string,array<string,mixed>> Built-in relation descriptors.
	 */
	private static function defaultRelationTypes(): array {
		return self::defaultTypedDefinitions([
			'table'=>'Table relation',
			'has_many'=>'Has many',
			'belongs_to_many'=>'Belongs to many',
			'morph_many'=>'Morph many',
			'session'=>'Session relation',
			'computed'=>'Computed relation',
		]);
	}

	/**
	 * Defines built-in dashboard/widget descriptor types.
	 *
	 * Widgets expose a renderer slot and describe summary, chart, table, and custom
	 * blocks that can appear on Panel pages or resource dashboards.
	 *
	 * @return array<string,array<string,mixed>> Built-in widget descriptors.
	 */
	private static function defaultWidgetTypes(): array {
		return self::defaultTypedDefinitions([
			'stat'=>'Stat',
			'kpi'=>'KPI',
			'trend'=>'Trend',
			'chart'=>'Chart',
			'table'=>'Table',
			'custom'=>'Custom',
		]);
	}

	/**
	 * Defines built-in page descriptor types.
	 *
	 * Page descriptors classify custom application surfaces such as dashboards,
	 * reports, tools, operations pages, and settings screens.
	 *
	 * @return array<string,array<string,mixed>> Built-in page descriptors.
	 */
	private static function defaultPageTypes(): array {
		return self::defaultTypedDefinitions([
			'custom'=>'Custom page',
			'dashboard'=>'Dashboard',
			'report'=>'Report',
			'tool'=>'Tool',
			'operations'=>'Operations page',
			'settings'=>'Settings page',
		]);
	}

	/**
	 * Defines built-in resource backing types.
	 *
	 * Resource descriptors communicate whether a Panel resource is model-backed,
	 * repository-backed, table-backed, session-backed, or intentionally read-only.
	 *
	 * @return array<string,array<string,mixed>> Built-in resource descriptors.
	 */
	private static function defaultResourceTypes(): array {
		return self::defaultTypedDefinitions([
			'resource'=>'Resource',
			'model'=>'Model resource',
			'repository'=>'Repository resource',
			'table'=>'Table resource',
			'session'=>'Session resource',
			'readonly'=>'Read-only resource',
		]);
	}

	/**
	 * Defines built-in summary aggregation types.
	 *
	 * Summary descriptors cover numeric aggregations, formatted money/percent
	 * summaries, and custom summary renderers for resource/table headers.
	 *
	 * @return array<string,array<string,mixed>> Built-in summary descriptors.
	 */
	private static function defaultSummaryTypes(): array {
		return self::defaultTypedDefinitions([
			'count'=>'Count',
			'sum'=>'Sum',
			'avg'=>'Average',
			'average'=>'Average',
			'min'=>'Minimum',
			'max'=>'Maximum',
			'money'=>'Money',
			'percent'=>'Percent',
			'custom'=>'Custom',
		]);
	}

	/**
	 * Defines built-in table view descriptor types.
	 *
	 * View descriptors classify saved presets, scoped views, operational queues,
	 * status segments, and other table-state shortcuts.
	 *
	 * @return array<string,array<string,mixed>> Built-in view descriptors.
	 */
	private static function defaultViewTypes(): array {
		return self::defaultTypedDefinitions([
			'view'=>'Table view',
			'preset'=>'Preset view',
			'scope'=>'Scoped view',
			'segment'=>'Segment view',
			'status'=>'Status view',
			'queue'=>'Queue view',
		]);
	}

	/**
	 * Defines built-in navigation descriptor types.
	 *
	 * Navigation descriptors classify resource/page links, grouped clusters,
	 * workspace-level sections, and external destinations before menus are
	 * rendered.
	 *
	 * @return array<string,array<string,mixed>> Built-in navigation descriptors.
	 */
	private static function defaultNavigationTypes(): array {
		return self::defaultTypedDefinitions([
			'item'=>'Navigation item',
			'resource'=>'Resource navigation',
			'page'=>'Page navigation',
			'cluster'=>'Navigation cluster',
			'workspace'=>'Workspace navigation',
			'external'=>'External navigation',
		]);
	}

	/**
	 * Defines built-in export descriptor types.
	 *
	 * Export descriptors describe table, CSV, JSON, selected-record, and audit
	 * export affordances that resources may expose.
	 *
	 * @return array<string,array<string,mixed>> Built-in export descriptors.
	 */
	private static function defaultExportTypes(): array {
		return self::defaultTypedDefinitions([
			'table_export'=>'Table export',
			'csv'=>'CSV export',
			'json'=>'JSON export',
			'selected'=>'Selected records export',
			'audit'=>'Audit export',
		]);
	}

	/**
	 * Defines built-in import descriptor types.
	 *
	 * Import descriptors classify CSV, upsert, append, sync, and audit import
	 * flows so resources can attach consistent handlers and UI metadata.
	 *
	 * @return array<string,array<string,mixed>> Built-in import descriptors.
	 */
	private static function defaultImportTypes(): array {
		return self::defaultTypedDefinitions([
			'csv_import'=>'CSV import',
			'upsert'=>'Upsert import',
			'append'=>'Append import',
			'sync'=>'Sync import',
			'audit'=>'Audit import',
		]);
	}

	/**
	 * Defines built-in bulk operation descriptor types.
	 *
	 * Bulk operation descriptors seed the registry for export, transition, update,
	 * duplication, restore, delete, force-delete, and custom bulk actions.
	 *
	 * @return array<string,array<string,mixed>> Built-in bulk operation descriptors.
	 */
	private static function defaultBulkOperationTypes(): array {
		return self::defaultTypedDefinitions([
			'bulk_export'=>'Bulk export',
			'bulk_transition'=>'Bulk transition',
			'bulk_update'=>'Bulk update',
			'bulk_duplicate'=>'Bulk duplicate',
			'bulk_restore'=>'Bulk restore',
			'bulk_delete'=>'Bulk delete',
			'bulk_force_delete'=>'Bulk force delete',
			'bulk_action'=>'Bulk action',
		]);
	}

	/**
	 * Builds the standard descriptor map used by most Panel component families.
	 *
	 * Each supplied type receives a label, a null callable slot, and builtin=true.
	 * The callable key is renderer by default, but action descriptors use handler
	 * to represent execution callbacks.
	 *
	 * @param array<string,string> $labels Map of normalized type to display label.
	 * @param string $callableKey Descriptor key reserved for a renderer or handler callable.
	 * @return array<string,array<string,mixed>> Standard typed descriptors.
	 */
	private static function defaultTypedDefinitions(array $labels, string $callableKey='renderer'): array {
		$definitions=[];
		foreach($labels as $type=>$label){
			$definitions[$type]=[
				'label'=>$label,
				$callableKey=>null,
				'builtin'=>true,
			];
		}
		return $definitions;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $kind Schema component kind before normalization.
	 * @param array|callable $definition Descriptor metadata, or callable shorthand for the primary handler.
	 * @return string Normalized schema kind key, or an empty string when the key is invalid.
	 */
	public static function registerSchemaKind(string $kind, array|callable $definition=[]): string {
		self::boot();
		$kind=self::normalizeName($kind);
		if($kind===''){
			return '';
		}
		if(is_callable($definition)){
			$definition=['renderer'=>\Closure::fromCallable($definition)];
		}
		elseif(isset($definition['renderer']) && is_callable($definition['renderer'])){
			$definition['renderer']=\Closure::fromCallable($definition['renderer']);
		}
		self::$schemaKinds[$kind]=array_replace(self::$schemaKinds[$kind] ?? [], $definition);
		return $kind;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array Descriptor map keyed by normalized schema key.
	 */
	public static function schemaKinds(): array {
		self::boot();
		return self::$schemaKinds;
	}

	/**
	 * Checks whether a normalized schema kind descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $kind Schema component kind before normalization.
	 * @return bool True when the normalized schema kind descriptor exists.
	 */
	public static function schemaKindRegistered(string $kind): bool {
		self::boot();
		return isset(self::$schemaKinds[self::normalizeName($kind)]);
	}

	/**
	 * Normalizes a schema kind key against the registered descriptors.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $kind Schema component kind before normalization.
	 * @return string Registered schema kind key, or the documented fallback key.
	 */
	public static function normalizeSchemaKind(string $kind): string {
		self::boot();
		$kind=self::normalizeName($kind);
		return isset(self::$schemaKinds[$kind]) ? $kind : 'field';
	}

	/**
	 * Renders a schema component through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param SchemaComponent $component Schema component being rendered.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderSchemaComponent(SchemaComponent $component, array $context=[]): ?string {
		self::boot();
		$definition=self::$schemaKinds[$component->kind()] ?? [];
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($component, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional lifecycle hooks.
	 * @return string Normalized field type key, or an empty string when the key is invalid.
	 */
	public static function registerFieldType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['hydrate', 'dehydrate', 'validate', 'display', 'options', 'cast'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$fieldTypes[$type] ?? [];
		self::$fieldTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Field descriptors keyed by normalized type.
	 */
	public static function fieldTypes(): array {
		self::boot();
		return self::$fieldTypes;
	}

	/**
	 * Resolves the registered field type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Field descriptor, or an empty array when unregistered.
	 */
	public static function fieldTypeDefinition(string $type): array {
		self::boot();
		return self::$fieldTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized field type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized field type descriptor exists.
	 */
	public static function fieldTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$fieldTypes[self::normalizeName($type)]);
	}

	/**
	 * Resolves field type is file upload registry metadata for Panel components.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the field descriptor represents an upload control.
	 */
	public static function fieldTypeIsFileUpload(string $type): bool {
		$definition=self::fieldTypeDefinition($type);
		return ($definition['file_upload'] ?? false)===true || ($definition['input'] ?? '')==='file';
	}

	/**
	 * Renders a field control through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $name Field input name passed to the renderer.
	 * @param array<string,mixed> $meta Field metadata passed to the renderer.
	 * @param mixed $value Current raw or formatted component value.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderFieldControl(string $type, string $name, array $meta, mixed $value, array $context=[]): ?string {
		self::boot();
		$type=self::normalizeName($type);
		$renderer=self::$fieldTypes[$type]['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($name, $meta, $value, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Checks whether a registered field type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the field type descriptor contains a callable hook.
	 */
	public static function fieldTypeHasHook(string $type, string $hook): bool {
		$definition=self::fieldTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered field type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Field $field Field instance passed to the hook.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() ield type hook result, or null when no callable hook is registered.
	 */
	public static function callFieldTypeHook(string $type, string $hook, Field $field, mixed ...$arguments): mixed {
		$definition=self::fieldTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$field]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional column hooks.
	 * @return string Normalized column type key, or an empty string when the key is invalid.
	 */
	public static function registerColumnType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['value', 'format', 'search', 'sort', 'export', 'summary'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$columnTypes[$type] ?? [];
		self::$columnTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Column descriptors keyed by normalized type.
	 */
	public static function columnTypes(): array {
		self::boot();
		return self::$columnTypes;
	}

	/**
	 * Checks whether a normalized column type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized column type descriptor exists.
	 */
	public static function columnTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$columnTypes[self::normalizeName($type)]);
	}

	/**
	 * Renders a column cell through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param Column $column Column instance passed to the hook or renderer.
	 * @param mixed $record Record supplied by the table, relation, or resource renderer.
	 * @param mixed $value Current raw or formatted component value.
	 * @param string $formatted Formatter output available to the renderer.
	 * @param array<string,mixed> $meta Column metadata passed to the renderer.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderColumnCell(string $type, Column $column, mixed $record, mixed $value, string $formatted, array $meta, array $context=[]): ?string {
		self::boot();
		$type=self::normalizeName($type);
		$renderer=self::$columnTypes[$type]['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($column, $record, $value, $formatted, $meta, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Checks whether a registered column type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the column type descriptor contains a callable hook.
	 */
	public static function columnTypeHasHook(string $type, string $hook): bool {
		self::boot();
		$definition=self::$columnTypes[self::normalizeName($type)] ?? [];
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered column type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Column $column Column instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() olumn type hook result, or null when no callable hook is registered.
	 */
	public static function callColumnTypeHook(string $type, string $hook, Column $column, mixed ...$arguments): mixed {
		self::boot();
		$definition=self::$columnTypes[self::normalizeName($type)] ?? [];
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$column]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $handler Handler.
	 * @param array<string,mixed> $definition Descriptor metadata and optional action hooks.
	 * @return string Normalized action type key, or an empty string when the key is invalid.
	 */
	public static function registerActionType(string $type, ?callable $handler=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['authorize', 'prepare', 'after'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$actionTypes[$type] ?? [];
		self::$actionTypes[$type]=array_replace($existing, $definition, [
			'handler'=>$handler!==null ? \Closure::fromCallable($handler) : ($existing['handler'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Action descriptors keyed by normalized type.
	 */
	public static function actionTypes(): array {
		self::boot();
		return self::$actionTypes;
	}

	/**
	 * Checks whether a normalized action type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized action type descriptor exists.
	 */
	public static function actionTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$actionTypes[self::normalizeName($type)]);
	}

	/**
	 * Resolves the registered action type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Action descriptor, or an empty array when unregistered.
	 */
	public static function actionTypeDefinition(string $type): array {
		self::boot();
		return self::$actionTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a registered action type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the action type descriptor contains a callable hook.
	 */
	public static function actionTypeHasHook(string $type, string $hook): bool {
		$definition=self::actionTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered action type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Action $action Action instance passed to the hook.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() ction type hook result, or null when no callable hook is registered.
	 */
	public static function callActionTypeHook(string $type, string $hook, Action $action, mixed ...$arguments): mixed {
		$definition=self::actionTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$action]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional bulk-operation hooks.
	 * @return string Normalized filter type key, or an empty string when the key is invalid.
	 */
	public static function registerFilterType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['active', 'options', 'match', 'label'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$filterTypes[$type] ?? [];
		self::$filterTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Bulk-operation descriptors keyed by normalized type.
	 */
	public static function filterTypes(): array {
		self::boot();
		return self::$filterTypes;
	}

	/**
	 * Resolves the registered filter type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Bulk-operation descriptor, or an empty array when unregistered.
	 */
	public static function filterTypeDefinition(string $type): array {
		self::boot();
		return self::$filterTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized filter type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized filter type descriptor exists.
	 */
	public static function filterTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$filterTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered filter type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the filter type descriptor contains a callable hook.
	 */
	public static function filterTypeHasHook(string $type, string $hook): bool {
		$definition=self::filterTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Resolves filter type is range registry metadata for Panel components.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the registry condition is satisfied.
	 */
	public static function filterTypeIsRange(string $type): bool {
		$definition=self::filterTypeDefinition($type);
		return ($definition['range'] ?? false)===true;
	}

	/**
	 * Invokes a callable hook on the registered filter type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param TableFilter $filter Filter instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() ilter type hook result, or null when no callable hook is registered.
	 */
	public static function callFilterTypeHook(string $type, string $hook, TableFilter $filter, mixed ...$arguments): mixed {
		$definition=self::filterTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$filter]));
	}

	/**
	 * Renders a filter control through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param TableFilter $filter Filter instance passed to the hook or renderer.
	 * @param PanelRequest $request HTTP request being handled.
	 * @param array<string,mixed> $meta Filter metadata passed to the renderer.
	 * @param mixed $value Current raw or formatted component value.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderFilterControl(string $type, TableFilter $filter, PanelRequest $request, array $meta, mixed $value, array $context=[]): ?string {
		$definition=self::filterTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($filter, $request, $meta, $value, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional relation hooks.
	 * @return string Normalized relation type key, or an empty string when the key is invalid.
	 */
	public static function registerRelationType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['authorize', 'query', 'records', 'before_records', 'after_records', 'empty_state'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$relationTypes[$type] ?? [];
		self::$relationTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Relation descriptors keyed by normalized type.
	 */
	public static function relationTypes(): array {
		self::boot();
		return self::$relationTypes;
	}

	/**
	 * Resolves the registered relation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Relation descriptor, or an empty array when unregistered.
	 */
	public static function relationTypeDefinition(string $type): array {
		self::boot();
		return self::$relationTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized relation type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized relation type descriptor exists.
	 */
	public static function relationTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$relationTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered relation type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the relation type descriptor contains a callable hook.
	 */
	public static function relationTypeHasHook(string $type, string $hook): bool {
		$definition=self::relationTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered relation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param RelationManager $relation Relation manager passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() elation type hook result, or null when no callable hook is registered.
	 */
	public static function callRelationTypeHook(string $type, string $hook, RelationManager $relation, mixed ...$arguments): mixed {
		$definition=self::relationTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$relation]));
	}

	/**
	 * Renders a relation through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param RelationManager $relation Relation manager passed to the hook or renderer.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param PanelRequest $request HTTP request being handled.
	 * @param mixed $record Record supplied by the table, relation, or resource renderer.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderRelation(string $type, RelationManager $relation, Resource $resource, PanelRequest $request, mixed $record, array $context=[]): ?string {
		$definition=self::relationTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($relation, $resource, $request, $record, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional widget hooks.
	 * @return string Normalized widget type key, or an empty string when the key is invalid.
	 */
	public static function registerWidgetType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['authorize', 'value', 'format', 'data', 'after_resolve'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$widgetTypes[$type] ?? [];
		self::$widgetTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Widget descriptors keyed by normalized type.
	 */
	public static function widgetTypes(): array {
		self::boot();
		return self::$widgetTypes;
	}

	/**
	 * Resolves the registered widget type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Widget descriptor, or an empty array when unregistered.
	 */
	public static function widgetTypeDefinition(string $type): array {
		self::boot();
		return self::$widgetTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized widget type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized widget type descriptor exists.
	 */
	public static function widgetTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$widgetTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered widget type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the widget type descriptor contains a callable hook.
	 */
	public static function widgetTypeHasHook(string $type, string $hook): bool {
		$definition=self::widgetTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered widget type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Widget $widget Widget instance passed to the hook.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() idget type hook result, or null when no callable hook is registered.
	 */
	public static function callWidgetTypeHook(string $type, string $hook, Widget $widget, mixed ...$arguments): mixed {
		$definition=self::widgetTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$widget]));
	}

	/**
	 * Renders a widget through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed> $widget Widget definition payload.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderWidget(string $type, array $widget, array $context=[]): ?string {
		$definition=self::widgetTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($widget, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional page hooks.
	 * @return string Normalized page type key, or an empty string when the key is invalid.
	 */
	public static function registerPageType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['authorize', 'before_render', 'after_render', 'widgets', 'tables', 'data'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$pageTypes[$type] ?? [];
		self::$pageTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Page descriptors keyed by normalized type.
	 */
	public static function pageTypes(): array {
		self::boot();
		return self::$pageTypes;
	}

	/**
	 * Resolves the registered page type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Page descriptor, or an empty array when unregistered.
	 */
	public static function pageTypeDefinition(string $type): array {
		self::boot();
		return self::$pageTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized page type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized page type descriptor exists.
	 */
	public static function pageTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$pageTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered page type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the page type descriptor contains a callable hook.
	 */
	public static function pageTypeHasHook(string $type, string $hook): bool {
		$definition=self::pageTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered page type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param PanelPage $page Panel page instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() age type hook result, or null when no callable hook is registered.
	 */
	public static function callPageTypeHook(string $type, string $hook, PanelPage $page, mixed ...$arguments): mixed {
		$definition=self::pageTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$page]));
	}

	/**
	 * Renders a page through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param PanelPage $page Panel page instance passed to the hook or renderer.
	 * @param PanelRequest $request HTTP request being handled.
	 * @param ?PanelManager $manager Optional Panel manager used for renderer context.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() egistered callback result, or null when unavailable.
	 */
	public static function renderPage(string $type, PanelPage $page, PanelRequest $request, ?PanelManager $manager=null, array $context=[]): mixed {
		$definition=self::pageTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		return $renderer($page, $request, $manager, $context);
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed>|callable $definition Descriptor metadata or query callback.
	 * @return string Normalized resource type key, or an empty string when the key is invalid.
	 */
	public static function registerResourceType(string $type, array|callable $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		if(is_callable($definition)){
			$definition=['query'=>\Closure::fromCallable($definition)];
		}
		foreach(['authorize', 'query', 'save', 'navigation', 'record_key', 'record_title', 'record_subtitle', 'record_url', 'global_search', 'describe'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$resourceTypes[$type] ?? [];
		self::$resourceTypes[$type]=array_replace($existing, $definition, [
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array Descriptor map keyed by normalized resource key.
	 */
	public static function resourceTypes(): array {
		self::boot();
		return self::$resourceTypes;
	}

	/**
	 * Resolves the registered resource type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array Registered resource type descriptor, or an empty array when missing.
	 */
	public static function resourceTypeDefinition(string $type): array {
		self::boot();
		return self::$resourceTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized resource type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized resource type descriptor exists.
	 */
	public static function resourceTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$resourceTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered resource type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the resource type descriptor contains a callable hook.
	 */
	public static function resourceTypeHasHook(string $type, string $hook): bool {
		$definition=self::resourceTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered resource type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() esource type hook result, or null when no callable hook is registered.
	 */
	public static function callResourceTypeHook(string $type, string $hook, Resource $resource, mixed ...$arguments): mixed {
		$definition=self::resourceTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$resource]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional summary hooks.
	 * @return string Normalized summary type key, or an empty string when the key is invalid.
	 */
	public static function registerSummaryType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['aggregate', 'format', 'data'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$summaryTypes[$type] ?? [];
		self::$summaryTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array Descriptor map keyed by normalized summary key.
	 */
	public static function summaryTypes(): array {
		self::boot();
		return self::$summaryTypes;
	}

	/**
	 * Resolves the registered summary type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array Registered summary type descriptor, or an empty array when missing.
	 */
	public static function summaryTypeDefinition(string $type): array {
		self::boot();
		return self::$summaryTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized summary type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized summary type descriptor exists.
	 */
	public static function summaryTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$summaryTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered summary type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the summary type descriptor contains a callable hook.
	 */
	public static function summaryTypeHasHook(string $type, string $hook): bool {
		$definition=self::summaryTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered summary type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param TableSummary $summary Table summary instance passed to the hook.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() ummary type hook result, or null when no callable hook is registered.
	 */
	public static function callSummaryTypeHook(string $type, string $hook, TableSummary $summary, mixed ...$arguments): mixed {
		$definition=self::summaryTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$summary]));
	}

	/**
	 * Renders a summary through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed> $summary Summary definition payload.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderSummary(string $type, array $summary, array $context=[]): ?string {
		$definition=self::summaryTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($summary, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional view hooks.
	 * @return string Normalized view type key, or an empty string when the key is invalid.
	 */
	public static function registerViewType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['query_defaults', 'match', 'badge', 'label', 'data'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$viewTypes[$type] ?? [];
		self::$viewTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> View descriptors keyed by normalized type.
	 */
	public static function viewTypes(): array {
		self::boot();
		return self::$viewTypes;
	}

	/**
	 * Resolves the registered view type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> View descriptor, or an empty array when unregistered.
	 */
	public static function viewTypeDefinition(string $type): array {
		self::boot();
		return self::$viewTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized view type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized view type descriptor exists.
	 */
	public static function viewTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$viewTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered view type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the view type descriptor contains a callable hook.
	 */
	public static function viewTypeHasHook(string $type, string $hook): bool {
		$definition=self::viewTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered view type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param TableView $view Table view instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() iew type hook result, or null when no callable hook is registered.
	 */
	public static function callViewTypeHook(string $type, string $hook, TableView $view, mixed ...$arguments): mixed {
		$definition=self::viewTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$view]));
	}

	/**
	 * Renders a table view through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param TableView $view Table view instance passed to the hook or renderer.
	 * @param array<string,mixed> $data Table-view data passed to the renderer.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderTableView(string $type, TableView $view, array $data, array $context=[]): ?string {
		$definition=self::viewTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($view, $data, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional navigation hooks.
	 * @return string Normalized navigation type key, or an empty string when the key is invalid.
	 */
	public static function registerNavigationType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['visible', 'entry', 'label', 'group', 'badge', 'sort'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$navigationTypes[$type] ?? [];
		self::$navigationTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Navigation descriptors keyed by normalized type.
	 */
	public static function navigationTypes(): array {
		self::boot();
		return self::$navigationTypes;
	}

	/**
	 * Resolves the registered navigation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array<string,mixed> Navigation descriptor, or an empty array when unregistered.
	 */
	public static function navigationTypeDefinition(string $type): array {
		self::boot();
		return self::$navigationTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized navigation type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized navigation type descriptor exists.
	 */
	public static function navigationTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$navigationTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered navigation type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the navigation type descriptor contains a callable hook.
	 */
	public static function navigationTypeHasHook(string $type, string $hook): bool {
		$definition=self::navigationTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered navigation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param array<string,mixed> $entry Navigation entry payload.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() avigation type hook result, or null when no callable hook is registered.
	 */
	public static function callNavigationTypeHook(string $type, string $hook, array $entry, mixed ...$arguments): mixed {
		$definition=self::navigationTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge([$entry], $arguments));
	}

	/**
	 * Resolves prepare navigation entry registry metadata for Panel components.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param array<string,mixed> $entry Navigation entry payload.
	 * @param ?PanelRequest $request HTTP request being handled.
	 * @param ?PanelManager $manager Optional Panel manager used for renderer context.
	 * @return ?array<string,mixed> Prepared navigation entry, or null when hidden by a visibility hook.
	 */
	public static function prepareNavigationEntry(array $entry, ?PanelRequest $request=null, ?PanelManager $manager=null): ?array {
		self::boot();
		$type=self::normalizeName((string)($entry['navigation_type'] ?? $entry['kind'] ?? 'item'));
		$type=$type!=='' ? $type : 'item';
		if(self::navigationTypeHasHook($type, 'visible')){
			$visible=self::callNavigationTypeHook($type, 'visible', $entry, $request, $manager);
			if($visible===false){
				return null;
			}
		}
		foreach(['label', 'group', 'badge', 'sort'] as $hook){
			if(!self::navigationTypeHasHook($type, $hook)){
				continue;
			}
			$value=self::callNavigationTypeHook($type, $hook, $entry, $request, $manager);
			if($value!==null){
				$entry[$hook]=$value;
			}
		}
		if(self::navigationTypeHasHook($type, 'entry')){
			$next=self::callNavigationTypeHook($type, 'entry', $entry, $request, $manager);
			if(is_array($next)){
				$entry=array_replace($entry, $next);
			}
		}
		$entry['navigation_type']=$type;
		$entry['type_registered']=self::navigationTypeRegistered($type);
		$entry['type_hooks']=[
			'visible'=>self::navigationTypeHasHook($type, 'visible'),
			'entry'=>self::navigationTypeHasHook($type, 'entry'),
			'label'=>self::navigationTypeHasHook($type, 'label'),
			'group'=>self::navigationTypeHasHook($type, 'group'),
			'badge'=>self::navigationTypeHasHook($type, 'badge'),
			'sort'=>self::navigationTypeHasHook($type, 'sort'),
			'renderer'=>is_callable(self::navigationTypeDefinition($type)['renderer'] ?? null),
		];
		return $entry;
	}

	/**
	 * Renders a navigation entry through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed> $entry Navigation entry payload.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderNavigationEntry(string $type, array $entry, array $context=[]): ?string {
		$definition=self::navigationTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($entry, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed>|callable $definition Descriptor metadata or records callback.
	 * @return string Normalized export type key, or an empty string when the key is invalid.
	 */
	public static function registerExportType(string $type, array|callable $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		if(is_callable($definition)){
			$definition=['records'=>\Closure::fromCallable($definition)];
		}
		foreach(['authorize', 'format', 'columns', 'records', 'row', 'payload', 'filename', 'button'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$exportTypes[$type] ?? [];
		self::$exportTypes[$type]=array_replace($existing, $definition, [
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array<string,array<string,mixed>> Export descriptors keyed by normalized type.
	 */
	public static function exportTypes(): array {
		self::boot();
		return self::$exportTypes;
	}

	/**
	 * Resolves the registered export type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array Registered export type descriptor, or an empty array when missing.
	 */
	public static function exportTypeDefinition(string $type): array {
		self::boot();
		return self::$exportTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized export type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized export type descriptor exists.
	 */
	public static function exportTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$exportTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered export type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the export type descriptor contains a callable hook.
	 */
	public static function exportTypeHasHook(string $type, string $hook): bool {
		$definition=self::exportTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered export type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() xport type hook result, or null when no callable hook is registered.
	 */
	public static function callExportTypeHook(string $type, string $hook, Resource $resource, mixed ...$arguments): mixed {
		$definition=self::exportTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$resource]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array|callable $definition Descriptor metadata, or callable shorthand for the primary handler.
	 * @return string Normalized import type key, or an empty string when the key is invalid.
	 */
	public static function registerImportType(string $type, array|callable $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		if(is_callable($definition)){
			$definition=['import'=>\Closure::fromCallable($definition)];
		}
		foreach(['authorize', 'columns', 'parse', 'validate', 'before_import', 'import', 'after_import', 'template', 'button'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$importTypes[$type] ?? [];
		self::$importTypes[$type]=array_replace($existing, $definition, [
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array Descriptor map keyed by normalized import key.
	 */
	public static function importTypes(): array {
		self::boot();
		return self::$importTypes;
	}

	/**
	 * Resolves the registered import type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array Registered import type descriptor, or an empty array when missing.
	 */
	public static function importTypeDefinition(string $type): array {
		self::boot();
		return self::$importTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized import type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized import type descriptor exists.
	 */
	public static function importTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$importTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered import type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the import type descriptor contains a callable hook.
	 */
	public static function importTypeHasHook(string $type, string $hook): bool {
		$definition=self::importTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered import type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() mport type hook result, or null when no callable hook is registered.
	 */
	public static function callImportTypeHook(string $type, string $hook, Resource $resource, mixed ...$arguments): mixed {
		$definition=self::importTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge($arguments, [$resource]));
	}

	/**
	 * Registers custom Panel component descriptors.
	 *
	 * Custom descriptors extend the registry while preserving normalized component keys and manifest metadata.
	 *
	 * @param string $type Component type key before normalization.
	 * @param ?callable $renderer Optional renderer callback stored as a Closure.
	 * @param array<string,mixed> $definition Descriptor metadata and optional bulk-operation hooks.
	 * @return string Normalized bulk operation type key, or an empty string when the key is invalid.
	 */
	public static function registerBulkOperationType(string $type, ?callable $renderer=null, array $definition=[]): string {
		self::boot();
		$type=self::normalizeName($type);
		if($type===''){
			return '';
		}
		foreach(['authorize', 'operation', 'label', 'tone', 'icon', 'confirm', 'url'] as $hook){
			if(isset($definition[$hook]) && is_callable($definition[$hook])){
				$definition[$hook]=\Closure::fromCallable($definition[$hook]);
			}
		}
		$existing=self::$bulkOperationTypes[$type] ?? [];
		self::$bulkOperationTypes[$type]=array_replace($existing, $definition, [
			'renderer'=>$renderer!==null ? \Closure::fromCallable($renderer) : ($existing['renderer'] ?? null),
			'builtin'=>$existing['builtin'] ?? false,
		]);
		return $type;
	}

	/**
	 * Lists registered Panel component descriptors.
	 *
	 * The returned map is keyed by normalized component type and consumed by form/table/resource builders and UI renderers.
	 * @return array Descriptor map keyed by normalized bulk operation key.
	 */
	public static function bulkOperationTypes(): array {
		self::boot();
		return self::$bulkOperationTypes;
	}

	/**
	 * Resolves the registered bulk operation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return array Registered bulk operation type descriptor, or an empty array when missing.
	 */
	public static function bulkOperationTypeDefinition(string $type): array {
		self::boot();
		return self::$bulkOperationTypes[self::normalizeName($type)] ?? [];
	}

	/**
	 * Checks whether a normalized bulk operation type descriptor is registered.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @return bool True when the normalized bulk operation type descriptor exists.
	 */
	public static function bulkOperationTypeRegistered(string $type): bool {
		self::boot();
		return isset(self::$bulkOperationTypes[self::normalizeName($type)]);
	}

	/**
	 * Checks whether a registered bulk operation type descriptor exposes a callable hook.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @return bool True when the bulk operation type descriptor contains a callable hook.
	 */
	public static function bulkOperationTypeHasHook(string $type, string $hook): bool {
		$definition=self::bulkOperationTypeDefinition($type);
		return is_callable($definition[$hook] ?? null);
	}

	/**
	 * Invokes a callable hook on the registered bulk operation type descriptor.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param string $hook Descriptor hook name to test or invoke.
	 * @param array<string,mixed> $operation Bulk operation payload.
	 * @param mixed ...$arguments Component factory arguments.
	 *  param($m) '@return mixed '+$m.Groups[1].Value.ToUpperInvariant() ulk operation type hook result, or null when no callable hook is registered.
	 */
	public static function callBulkOperationTypeHook(string $type, string $hook, array $operation, mixed ...$arguments): mixed {
		$definition=self::bulkOperationTypeDefinition($type);
		$callback=$definition[$hook] ?? null;
		if(!is_callable($callback)){
			return null;
		}
		return $callback(...array_merge([$operation], $arguments));
	}

	/**
	 * Resolves prepare bulk operation registry metadata for Panel components.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param array<string,mixed> $operation Bulk operation payload.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param PanelRequest $request HTTP request being handled.
	 * @return ?array<string,mixed> Prepared bulk operation, or null when authorization hook rejects it.
	 */
	public static function prepareBulkOperation(array $operation, Resource $resource, PanelRequest $request): ?array {
		self::boot();
		$type=self::normalizeName((string)($operation['type'] ?? $operation['name'] ?? 'bulk_action'));
		$type=$type!=='' ? $type : 'bulk_action';
		$operation['type']=$type;
		if(self::bulkOperationTypeHasHook($type, 'authorize')){
			$allowed=self::callBulkOperationTypeHook($type, 'authorize', $operation, $resource, $request);
			if($allowed===false){
				return null;
			}
		}
		foreach(['label', 'tone', 'icon', 'confirm', 'url'] as $hook){
			if(!self::bulkOperationTypeHasHook($type, $hook)){
				continue;
			}
			$value=self::callBulkOperationTypeHook($type, $hook, $operation, $resource, $request);
			if($value!==null){
				$operation[$hook]=$value;
			}
		}
		if(self::bulkOperationTypeHasHook($type, 'operation')){
			$next=self::callBulkOperationTypeHook($type, 'operation', $operation, $resource, $request);
			if(is_array($next)){
				$operation=array_replace($operation, $next);
			}
		}
		$operation['type_registered']=self::bulkOperationTypeRegistered($type);
		$operation['type_hooks']=[
			'authorize'=>self::bulkOperationTypeHasHook($type, 'authorize'),
			'operation'=>self::bulkOperationTypeHasHook($type, 'operation'),
			'label'=>self::bulkOperationTypeHasHook($type, 'label'),
			'tone'=>self::bulkOperationTypeHasHook($type, 'tone'),
			'icon'=>self::bulkOperationTypeHasHook($type, 'icon'),
			'confirm'=>self::bulkOperationTypeHasHook($type, 'confirm'),
			'url'=>self::bulkOperationTypeHasHook($type, 'url'),
			'renderer'=>is_callable(self::bulkOperationTypeDefinition($type)['renderer'] ?? null),
		];
		return $operation;
	}

	/**
	 * Renders a bulk operation through its registered renderer callback.
	 *
	 * The registry keeps Panel component metadata process-local and deterministic during application boot.
	 *
	 * @param string $type Component type key before normalization.
	 * @param array<string,mixed> $operation Bulk operation payload.
	 * @param Resource $resource Resource instance passed to the hook or renderer.
	 * @param PanelRequest $request HTTP request being handled.
	 * @param array<string,mixed> $context Render context supplied by the caller.
	 * @return ?string Rendered HTML from the registered callback, or null when unavailable.
	 */
	public static function renderBulkOperation(string $type, array $operation, Resource $resource, PanelRequest $request, array $context=[]): ?string {
		$definition=self::bulkOperationTypeDefinition($type);
		$renderer=$definition['renderer'] ?? null;
		if(!is_callable($renderer)){
			return null;
		}
		$result=$renderer($operation, $resource, $request, $context);
		return is_string($result) ? $result : null;
	}

	/**
	 * Normalizes registry keys with the same rules used by Panel resources.
	 *
	 * Sharing Resource::normalizeName() keeps field, column, action, filter,
	 * relation, and operation type keys compatible with resource metadata and
	 * renderer lookups.
	 *
	 * @param string $name Raw registry key.
	 * @return string Normalized registry key.
	 */
	private static function normalizeName(string $name): string {
		return Resource::normalizeName($name);
	}
}
