<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return mixed */
function dp_panel_structure_contract_path(array $data, string $path): mixed {
	$value=$data;
	foreach(explode('.', $path) as $segment){
		if(!is_array($value) || !array_key_exists($segment, $value)){
			return null;
		}
		$value=$value[$segment];
	}
	return $value;
}

dataset('panel structural builder contracts', [
	'section label'=>['section', 'label', ['Customer details'], 'label', 'Customer details'],
	'section description'=>['section', 'description', ['Identity fields'], 'description', 'Identity fields'],
	'section columns'=>['section', 'columns', [3], 'columns', 3],
	'section columns lower clamp'=>['section', 'columns', [0], 'columns', 0],
	'section responsive alias'=>['section', 'columns', [['medium'=>4]], 'meta.grid_columns.md', 4],
	'section responsive upper clamp'=>['section', 'columns', [['wide'=>99]], 'meta.grid_columns.2xl', 12],
	'section span'=>['section', 'columnSpan', [4], 'meta.column_span', 4],
	'section full span'=>['section', 'columnSpan', ['full'], 'meta.column_span', 'full'],
	'section start clamp'=>['section', 'columnStart', [99], 'meta.column_start', 12],
	'section collapsible'=>['section', 'collapsible', [true], 'collapsible', true],
	'section collapsed implies collapsible'=>['section', 'collapsed', [true], 'collapsible', true],
	'section collapsed state'=>['section', 'collapsed', [true], 'collapsed', true],
	'section touch target'=>['section', 'minTouchTarget', [20], 'meta.accessibility.min_touch_target', 20],
	'section usable chars'=>['section', 'minUsableCharacters', [18], 'meta.accessibility.min_usable_chars', 18],
	'section contrast'=>['section', 'contrastPolicy', [7.0, 'label'], 'meta.accessibility.contrast_policy.min_ratio', 7.0],
	'section meta'=>['section', 'meta', [['probe'=>'section']], 'meta.probe', 'section'],

	'entry label'=>['entry', 'label', ['Status'], 'label', 'Status'],
	'entry type'=>['entry', 'type', ['badge'], 'type', 'badge'],
	'entry section'=>['entry', 'section', ['Summary'], 'meta.section', 'Summary'],
	'entry icon'=>['entry', 'icon', ['check'], 'meta.icon', 'check'],
	'entry badge'=>['entry', 'badge', [['open'=>'success']], 'meta.badge', true],
	'entry tones'=>['entry', 'badge', [['open'=>'success']], 'meta.tones.open', 'success'],
	'entry copyable'=>['entry', 'copyable', [true], 'meta.copyable', true],
	'entry prefix'=>['entry', 'prefix', ['$'], 'meta.prefix', '$'],
	'entry suffix'=>['entry', 'suffix', [' CAD'], 'meta.suffix', ' CAD'],
	'entry empty label'=>['entry', 'emptyLabel', ['None'], 'meta.empty', 'None'],
	'entry description'=>['entry', 'description', ['Current state'], 'meta.description', 'Current state'],
	'entry html'=>['entry', 'html', [true], 'meta.html', true],
	'entry options'=>['entry', 'options', [['open'=>'Open']], 'options.open', 'Open'],
	'entry visible on'=>['entry', 'visibleOn', ['show'], 'visible_on.0', 'show'],
	'entry hidden on'=>['entry', 'hiddenOn', ['edit'], 'hidden_on.0', 'edit'],
	'entry span'=>['entry', 'columnSpan', [2], 'meta.column_span', 2],
	'entry full width'=>['entry', 'fullWidth', [], 'meta.column_span', 'full'],
	'entry meta'=>['entry', 'meta', [['probe'=>'entry']], 'meta.probe', 'entry'],
]);

test('form sections and infolist entries preserve structural metadata', static function(Context $t, string $kind, string $method, array $arguments, string $path, mixed $expected): void {
	$builder=$kind==='section' ? FormSection::make('contract') : InfolistEntry::make('contract');
	$builder=$builder->{$method}(...$arguments);
	$t->same($expected, dp_panel_structure_contract_path($builder->toArray(), $path));
})->with('panel structural builder contracts')->tag('panel', 'schema', 'structure')->maxMillis(1000);

dataset('panel column manifest contracts', [
	'label'=>['label', ['Total'], 'label', 'Total'],
	'type'=>['type', ['badge'], 'type', 'badge'],
	'sortable'=>['sortable', [true], 'sortable', true],
	'searchable'=>['searchable', [true], 'searchable', true],
	'toggleable'=>['toggleable', [true], 'toggleable', true],
	'visible default'=>['visibleByDefault', [true], 'visible_by_default', true],
	'hidden default'=>['hiddenByDefault', [true], 'visible_by_default', false],
	'hidden'=>['hidden', [true], 'hidden', true],
	'visible on'=>['visibleOn', ['index'], 'visible_on.0', 'index'],
	'hidden on'=>['hiddenOn', ['edit'], 'hidden_on.0', 'edit'],
	'align'=>['align', ['right'], 'align', 'right'],
	'group'=>['group', ['Financial', 'Money columns'], 'group', 'Financial'],
	'group description'=>['columnGroup', ['Financial', 'Money columns'], 'group_description', 'Money columns'],
	'money type'=>['money', ['CAD'], 'type', 'money'],
	'money currency'=>['money', ['CAD'], 'meta.currency', 'CAD'],
	'date type'=>['date', ['d/m/Y'], 'type', 'date'],
	'date format'=>['date', ['d/m/Y'], 'meta.format', 'd/m/Y'],
	'datetime type'=>['datetime', ['c'], 'type', 'datetime'],
	'boolean type'=>['booleanLabels', ['Active', 'Inactive'], 'type', 'boolean'],
	'boolean true label'=>['booleanLabels', ['Active', 'Inactive'], 'meta.true_label', 'Active'],
	'boolean false label'=>['booleanLabels', ['Active', 'Inactive'], 'meta.false_label', 'Inactive'],
	'badge type'=>['badge', ['success'], 'type', 'badge'],
	'badge tone'=>['badge', ['success'], 'meta.tone', 'success'],
	'url type'=>['url', ['title'], 'type', 'url'],
	'url label column'=>['url', ['title'], 'meta.label_column', 'title'],
	'email type'=>['email', [], 'type', 'email'],
	'truncate clamp'=>['truncate', [0], 'meta.truncate', 1],
	'limit alias'=>['limit', [42], 'meta.truncate', 42],
	'description'=>['description', ['Supporting copy'], 'meta.description', 'Supporting copy'],
	'copyable'=>['copyable', [true], 'copyable', true],
	'copy message'=>['copyMessage', ['Copied total'], 'meta.copy_message', 'Copied total'],
	'tooltip'=>['tooltip', ['Full value'], 'meta.tooltip', 'Full value'],
	'icon'=>['icon', ['currency'], 'meta.icon', 'currency'],
	'color'=>['color', ['success'], 'meta.color', 'success'],
	'link url'=>['linkTo', ['/panel/orders/1', true], 'meta.link_url', '/panel/orders/1'],
	'link new tab'=>['href', ['/panel/orders/1', true], 'meta.link_new_tab', true],
	'editable'=>['editable', [true], 'editable', true],
	'editable type'=>['inlineEditable', ['select'], 'editable_type', 'select'],
	'editable options'=>['editableOptions', [['open'=>'Open']], 'editable_options.open', 'Open'],
	'footer'=>['footer', ['Totals'], 'footer', 'Totals'],
	'sum summary'=>['sum', ['Revenue'], 'summary', 'sum'],
	'summary label'=>['sum', ['Revenue'], 'summary_label', 'Revenue'],
	'average summary'=>['average', [], 'summary', 'average'],
	'count summary'=>['count', [], 'summary', 'count'],
	'header attribute'=>['headerAttribute', ['data-probe', 'head'], 'header_attributes.data-probe', 'head'],
	'cell attribute'=>['cellAttribute', ['data-probe', 'cell'], 'cell_attributes.data-probe', 'cell'],
	'header data'=>['headerData', ['scope', 'orders'], 'header_attributes.data-scope', 'orders'],
	'cell aria'=>['cellAria', ['label', 'Total'], 'cell_attributes.aria-label', 'Total'],
	'meta'=>['meta', [['probe'=>'column']], 'meta.probe', 'column'],
]);

test('table columns serialize display editing link and summary behavior', static function(Context $t, string $method, array $arguments, string $path, mixed $expected): void {
	$column=Column::make('contract')->{$method}(...$arguments);
	$t->same($expected, dp_panel_structure_contract_path($column->toArray(), $path));
})->with('panel column manifest contracts')->tag('panel', 'column', 'manifest')->maxMillis(1000);

dataset('panel action manifest contracts', [
	'label'=>['label', ['Approve'], 'label', 'Approve'],
	'icon'=>['icon', ['check'], 'icon', 'check'],
	'tone'=>['tone', ['success'], 'tone', 'success'],
	'outline style'=>['outlined', [true], 'style', 'outline'],
	'ghost style'=>['subtle', [true], 'style', 'ghost'],
	'link style'=>['link', [true], 'style', 'link'],
	'size alias'=>['size', ['large'], 'size', 'lg'],
	'compact'=>['compact', [true], 'size', 'sm'],
	'icon only'=>['iconButton', [true], 'icon_only', true],
	'record primary'=>['recordPrimary', [true], 'record_placement', 'primary'],
	'record overflow'=>['recordOverflow', [true], 'record_placement', 'overflow'],
	'description'=>['description', ['Approve this order'], 'description', 'Approve this order'],
	'help alias'=>['help', ['More information'], 'description', 'More information'],
	'badge scalar'=>['badge', [7], 'badge', '7'],
	'badge tone'=>['badgeTone', ['warning'], 'badge_tone', 'warning'],
	'tooltip'=>['tooltip', ['Approve now'], 'tooltip', 'Approve now'],
	'key binding'=>['keyBinding', ['ctrl+k'], 'key_bindings.0', 'ctrl+k'],
	'attribute'=>['attribute', ['role', 'button'], 'extra_attributes.role', 'button'],
	'data attribute'=>['data', ['action', 'approve'], 'extra_attributes.data-action', 'approve'],
	'aria attribute'=>['aria', ['label', 'Approve order'], 'extra_attributes.aria-label', 'Approve order'],
	'confirmation flag'=>['confirmation', ['Are you sure?'], 'requires_confirmation', true],
	'confirmation message'=>['confirmation', ['Are you sure?'], 'meta.confirmation', 'Are you sure?'],
	'modal heading'=>['modal', ['Review order', 'Review before approval', 'lg'], 'modal_heading', 'Review order'],
	'modal description'=>['modal', ['Review order', 'Review before approval', 'lg'], 'modal_description', 'Review before approval'],
	'modal width'=>['modal', ['Review order', null, 'lg'], 'modal_width', 'lg'],
	'modal invalid width'=>['modalWidth', ['giant'], 'modal_width', 'md'],
	'slide over'=>['slideOver', [true], 'meta.modal_style', 'slide_over'],
	'modal submit label'=>['modalSubmitLabel', ['Confirm'], 'modal_submit_label', 'Confirm'],
	'modal cancel label'=>['modalCancelLabel', ['Never mind'], 'modal_cancel_label', 'Never mind'],
	'modal content'=>['modalContent', ['Details'], 'has_modal_content', true],
	'modal back'=>['modalBack', [true], 'modal_stack', 'push'],
	'modal stack explicit'=>['modalStack', ['replace'], 'modal_stack_explicit', true],
	'replace modal'=>['replaceModal', [], 'modal_stack', 'replace'],
	'clear modal stack'=>['clearModalStack', [], 'modal_stack', 'clear'],
	'modal exit'=>['modalExit', ['back'], 'modal_exit', 'back'],
	'invalid modal exit'=>['modalExit', ['unsupported'], 'modal_exit', 'auto'],
	'back on modal exit'=>['backOnModalExit', [], 'modal_exit', 'back'],
	'close on modal exit'=>['closeOnModalExit', [], 'modal_exit', 'close'],
	'stay on modal exit'=>['stayOnModalExit', [], 'modal_exit', 'stay'],
	'prevent modal exit'=>['preventModalExit', [], 'modal_exit', 'stay'],
	'bulk'=>['bulk', [true], 'bulk', true],
	'allow empty selection'=>['allowEmptySelection', [true], 'allow_empty_selection', true],
	'success message'=>['successMessage', ['Approved'], 'success_message', 'Approved'],
	'redirect'=>['redirectTo', ['/panel/orders'], 'redirect_to', '/panel/orders'],
	'refresh panel'=>['refreshPanel', [], 'effects.refresh.0', 'panel'],
	'refresh table'=>['refreshTable', ['orders'], 'effects.refresh.0', 'table:orders'],
	'refresh widgets'=>['refreshWidgets', [], 'effects.refresh.0', 'widgets'],
	'refresh navigation'=>['refreshNavigation', [], 'effects.refresh.0', 'navigation'],
	'without refresh'=>['withoutRefresh', [], 'effects.refresh', []],
	'close modal'=>['closeModal', [true], 'effects.close_modal', true],
	'keep modal open'=>['keepModalOpen', [], 'effects.close_modal', false],
	'modal navigation back'=>['modalNavigation', ['back'], 'effects.modal_navigation', 'back'],
	'back to parent modal'=>['backToParentModal', [], 'effects.modal_navigation', 'back'],
	'stay in modal'=>['stayInModal', [], 'effects.modal_navigation', 'stay'],
	'event name'=>['dispatchBrowserEvent', ['order-approved', ['id'=>7]], 'effects.events.0.name', 'order-approved'],
	'event detail'=>['dispatchBrowserEvent', ['order-approved', ['id'=>7]], 'effects.events.0.detail.id', 7],
	'disabled'=>['disabled', [true, 'Unavailable'], 'disables', true],
	'disabled reason'=>['disabled', [true, 'Unavailable'], 'disabled_reason', 'Unavailable'],
	'meta'=>['meta', [['probe'=>'action']], 'meta.probe', 'action'],
]);

test('actions serialize modal confirmation effect and accessibility contracts', static function(Context $t, string $method, array $arguments, string $path, mixed $expected): void {
	$action=Action::make('contract')->{$method}(...$arguments);
	$t->same($expected, dp_panel_structure_contract_path($action->toArray(), $path));
})->with('panel action manifest contracts')->tag('panel', 'action', 'manifest')->maxMillis(1000);

test('modal stack defaults remain implicit and array hydration preserves explicit intent', static function(Context $t): void {
	$defaults=Action::make('implicit')->toArray();
	$t->same('replace', $defaults['modal_stack'] ?? null);
	$t->same(false, $defaults['modal_stack_explicit'] ?? null);
	$t->same('auto', $defaults['modal_exit'] ?? null);

	$roundTrip=Action::fromArray($defaults)->toArray();
	$t->same(false, $roundTrip['modal_stack_explicit'] ?? null);

	$explicit=Action::fromArray([
		'name'=>'explicit',
		'modal'=>true,
		'modal_stack'=>'replace',
		'modal_exit'=>'back',
	])->toArray();
	$t->same(true, $explicit['modal_stack_explicit'] ?? null);
	$t->same('back', $explicit['modal_exit'] ?? null);
})->tag('panel', 'action', 'modal', 'manifest')->maxMillis(1000);

test('modal completion effects are coherent and newest declaration wins', static function(Context $t): void {
	$cases=[
		Action::make('back_then_stay')->backToParentModal()->keepModalOpen()->toArray()['effects'] ?? [],
		Action::make('stay_then_close')->stayInModal()->closeModal()->toArray()['effects'] ?? [],
		Action::make('close_then_back')->closeModal()->backToParentModal()->toArray()['effects'] ?? [],
		Action::make('legacy_stay')->effects(['close_modal'=>false], false)->toArray()['effects'] ?? [],
		Action::make('navigation_wins')->effects(['close_modal'=>true, 'modal_navigation'=>'back'], false)->toArray()['effects'] ?? [],
	];
	$t->same(['modal_navigation'=>'stay', 'close_modal'=>false], $cases[0]);
	$t->same(['modal_navigation'=>'close', 'close_modal'=>true], $cases[1]);
	$t->same(['modal_navigation'=>'back', 'close_modal'=>false], $cases[2]);
	$t->same(['modal_navigation'=>'stay', 'close_modal'=>false], $cases[3]);
	$t->same(['modal_navigation'=>'back', 'close_modal'=>false], $cases[4]);
})->tag('panel', 'action', 'modal', 'effects')->maxMillis(1000);

test('action manifests and renderer attributes expose modal stack and exit contracts', static function(Context $t): void {
	$action=Action::make('daughter')->recordOverflow()->modal()->replaceModal()->backOnModalExit();
	$manifest=$action->manifest();
	$t->same('overflow', $manifest['presentation']['record_placement'] ?? null);
	$t->same('replace', $manifest['interaction']['modal_stack'] ?? null);
	$t->same(true, $manifest['interaction']['modal_stack_explicit'] ?? null);
	$t->same('back', $manifest['interaction']['modal_exit'] ?? null);
	$t->same(true, $manifest['capabilities']['ui']['explicit_modal_stack'] ?? null);
	$t->same(true, $manifest['capabilities']['ui']['modal_exit_control'] ?? null);

	$attributes=$t->nonPublic(PanelRenderer::class);
	$explicitHtml=(string)$attributes->invoke('actionModalAttributes',$action->resolvedMeta(),false);
	$t->contains('data-dp-panel-modal-stack="replace"', $explicitHtml);
	$t->contains('data-dp-panel-modal-stack-explicit="1"', $explicitHtml);
	$t->contains('data-dp-panel-modal-exit="back"', $explicitHtml);

	$implicitHtml=(string)$attributes->invoke('actionModalAttributes',Action::make('parent')->modal()->resolvedMeta(),false);
	$t->notContains('data-dp-panel-modal-stack-explicit', $implicitHtml);
	$t->contains('data-dp-panel-modal-exit="auto"', $implicitHtml);
})->tag('panel', 'action', 'modal', 'renderer')->maxMillis(1000);

dataset('panel relation capability contracts', [
	'label'=>['label', ['Items'], 'label', 'Items'],
	'description'=>['description', ['Order items'], 'description', 'Order items'],
	'parent title'=>['parentTitle', ['Order #1'], 'parent_title', 'Order #1'],
	'badge'=>['badge', [4], 'badge', '4'],
	'empty heading'=>['emptyState', ['No items', 'Add an item'], 'empty_state', 'No items'],
	'empty description'=>['emptyState', ['No items', 'Add an item'], 'empty_description', 'Add an item'],
	'related resource'=>['relatedResource', ['products'], 'related_resource', 'products'],
	'table'=>['table', ['order_items'], 'table', 'order_items'],
	'foreign key'=>['foreignKey', ['order_id'], 'foreign_key', 'order_id'],
	'local key'=>['localKey', ['id'], 'local_key', 'id'],
	'read only'=>['readOnly', [true], 'read_only', true],
	'create disabled'=>['withoutCreate', [], 'create_enabled', false],
	'attach enabled'=>['attach', [true], 'attach_enabled', true],
	'attach disabled'=>['withoutAttach', [], 'attach_enabled', false],
	'detach enabled'=>['detach', [true], 'detach_enabled', true],
	'associate enabled'=>['associate', [true], 'associate_enabled', true],
	'dissociate enabled'=>['dissociate', [true], 'dissociate_enabled', true],
	'reorder enabled'=>['reorderable', [true, 'position'], 'reorder_enabled', true],
	'reorder column'=>['reorderable', [true, 'position'], 'order_column', 'position'],
	'attach label'=>['attachLabel', ['Add item'], 'attach_label', 'Add item'],
	'detach label'=>['detachLabel', ['Remove item'], 'detach_label', 'Remove item'],
	'associate label'=>['associateLabel', ['Associate'], 'associate_label', 'Associate'],
	'dissociate label'=>['dissociateLabel', ['Dissociate'], 'dissociate_label', 'Dissociate'],
	'reorder label'=>['reorderLabel', ['Save order'], 'reorder_label', 'Save order'],
	'per page'=>['perPage', [50], 'table_schema.default_per_page', 50],
	'default sort column'=>['defaultSort', ['position', 'desc'], 'table_schema.default_sort.column', 'position'],
	'default sort direction'=>['defaultSort', ['position', 'desc'], 'table_schema.default_sort.direction', 'desc'],
	'meta'=>['meta', [['probe'=>'relation']], 'meta.probe', 'relation'],
]);

test('relation managers serialize mutation permissions and table capabilities', static function(Context $t, string $method, array $arguments, string $path, mixed $expected): void {
	$relation=RelationManager::make('contract')->{$method}(...$arguments);
	$t->same($expected, dp_panel_structure_contract_path($relation->toArray(), $path));
})->with('panel relation capability contracts')->tag('panel', 'relation', 'manifest')->maxMillis(1000);

dataset('panel schema composition contracts', [
	'empty type'=>['empty', 'type', 'schema'],
	'fixed columns'=>['fixed_columns', 'columns', 4],
	'fixed columns clamp'=>['clamped_columns', 'columns', 12],
	'responsive small alias'=>['responsive_columns', 'meta.grid_columns.sm', 2],
	'responsive wide alias'=>['responsive_columns', 'meta.grid_columns.2xl', 6],
	'field component kind'=>['field', 'components.0.kind', 'field'],
	'field name'=>['field', 'fields.0.name', 'title'],
	'entry field name'=>['entry', 'fields.0.name', 'status'],
	'section kind'=>['section', 'components.0.kind', 'section'],
	'section name'=>['section', 'sections.0.name', 'details'],
	'group kind'=>['group', 'components.0.kind', 'group'],
	'group child'=>['group', 'components.0.children.0.field.name', 'title'],
	'tab kind'=>['tab', 'components.0.kind', 'tab'],
	'tab metadata propagation'=>['tab', 'components.0.children.0.field.meta.tab', 'General'],
	'step kind'=>['step', 'components.0.kind', 'step'],
	'step metadata propagation'=>['step', 'components.0.children.0.field.meta.step', 'Confirm'],
	'meta merge'=>['meta', 'meta.probe', 'schema'],
	'component label'=>['component_label', 'components.0.label', 'Custom'],
	'component meta'=>['component_meta', 'components.0.meta.probe', 'component'],
]);

test('schemas compose nested fields sections tabs and steps deterministically', static function(Context $t, string $scenario, string $path, mixed $expected): void {
	$schema=match($scenario){
		'empty'=>Schema::make(),
		'fixed_columns'=>Schema::make()->columns(4),
		'clamped_columns'=>Schema::make()->columns(99),
		'responsive_columns'=>Schema::make()->columns(['small'=>2, 'wide'=>6]),
		'field'=>Schema::make()->field('title'),
		'entry'=>Schema::make()->entry(InfolistEntry::make('status')),
		'section'=>Schema::make()->section('details', [Field::make('title')]),
		'group'=>Schema::make()->group('summary', [Field::make('title')]),
		'tab'=>Schema::make()->tab('General', [Field::make('title')]),
		'step'=>Schema::make()->step('Confirm', [Field::make('title')]),
		'meta'=>Schema::make()->meta(['probe'=>'schema']),
		'component_label'=>Schema::make()->component(SchemaComponent::group('custom')->label('Custom')),
		'component_meta'=>Schema::make()->component(SchemaComponent::group('custom')->meta(['probe'=>'component'])),
	};
	$t->same($expected, dp_panel_structure_contract_path($schema->toArray(), $path));
})->with('panel schema composition contracts')->tag('panel', 'schema', 'composition')->maxMillis(1000);
