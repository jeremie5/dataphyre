<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\RelationManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('panel relation manifest describes writable data operations table facts permissions and presentation',static function(Context $t): void {
	PanelTrace::flush();
	$request=PanelRequest::fromArray([
		'resource'=>'sales-orders',
		'operation'=>'relation',
		'query'=>['page'=>2,'per_page'=>15],
	]);
	$definition=[
		'name'=>'line_items',
		'label'=>'Line items',
		'description'=>'Items attached to this order.',
		'description_dynamic'=>true,
		'parent_title'=>'Order 42',
		'parent_title_dynamic'=>true,
		'badge'=>'3',
		'badge_dynamic'=>true,
		'empty_state'=>'No line items',
		'empty_description'=>'Attach the first item.',
		'related_resource'=>'products',
		'table'=>'order_product',
		'foreign_key'=>'order_id',
		'local_key'=>'id',
		'queryable'=>true,
		'read_only'=>false,
		'create_enabled'=>true,
		'attach_enabled'=>true,
		'detach_enabled'=>true,
		'associate_enabled'=>true,
		'dissociate_enabled'=>true,
		'reorder_enabled'=>true,
		'attaches'=>true,
		'detaches'=>true,
		'associates'=>true,
		'dissociates'=>true,
		'reorders'=>true,
		'updates_pivot'=>true,
		'attach_label'=>'Link product',
		'detach_label'=>'Unlink product',
		'associate_label'=>'Associate product',
		'dissociate_label'=>'Dissociate product',
		'reorder_label'=>'Reorder products',
		'order_column'=>'position',
		'pivot_fields'=>[
			['name'=>'quantity','type'=>'integer'],
			['name'=>'note','type'=>'text'],
		],
		'operations'=>[
			'attach'=>[
				'label'=>'Choose product',
				'modal_label'=>'Choose a product to link',
				'disabled_reason'=>'unused while enabled',
			],
			'detach'=>['label'=>'Remove product'],
			'associate'=>['modal_label'=>'Select associated product'],
			'dissociate'=>[],
			'reorder'=>['label'=>'Sort products'],
			'update_pivot'=>['modal_label'=>'Edit relationship fields'],
		],
		'table_schema'=>[
			'columns'=>[
				['name'=>'id','label'=>'ID','sortable'=>true,'searchable'=>true],
				['name'=>'product','label'=>'Product','searchable'=>true],
			],
			'filters'=>[['name'=>'status','type'=>'select']],
			'views'=>[['name'=>'active','default'=>true]],
			'groups'=>[['name'=>'category','column'=>'category_id']],
			'summaries'=>[['name'=>'total','type'=>'sum','column'=>'amount']],
		],
		'facts'=>[
			['name'=>'calculated','type'=>'computed','format'=>'currency'],
			'legacy fact',
			['name'=>'plain','type'=>'sum','format'=>'   '],
		],
		'authorizes'=>true,
		'meta'=>['origin'=>'definition','override'=>'definition'],
	];

	$manifest=RelationManifest::from($definition,$request,[
		'resource'=>'sales-orders',
		'override'=>'caller',
	])->toArray();
	$t->same('relation_manifest',$manifest['type']);
	$t->same('line_items',$manifest['name']);
	$t->same('Line items',$manifest['label']);
	$t->same(true,$manifest['presentation']['description_dynamic']);
	$t->same(true,$manifest['presentation']['parent_title_dynamic']);
	$t->same(true,$manifest['presentation']['badge_dynamic']);
	$t->same('Attach the first item.',$manifest['presentation']['empty_description']);
	$t->same('products',$manifest['data']['related_resource']);
	$t->same('order_product',$manifest['data']['table']);
	$t->same(true,$manifest['data']['queryable']);
	$t->same(true,$manifest['authorizes']);
	$t->same(true,$manifest['authorization']['authorizes']);

	$operations=$manifest['operations'];
	$t->same(true,$operations['writable']);
	$t->same(true,$operations['entries']['attach']['enabled']);
	$t->same('Choose product',$operations['attach_entry']['label']);
	$t->same('Choose a product to link',$operations['attach_entry']['modal_label']);
	$t->same(null,$operations['attach_entry']['disabled_reason']);
	$t->same('position',$operations['reorder_entry']['order_column']);
	$t->same(2,count($operations['update_pivot_entry']['pivot_fields']));

	$t->same('table_manifest',$manifest['table']['type']);
	$t->same(2,$manifest['capabilities']['table']['columns']);
	$t->same(2,$manifest['capabilities']['table']['searchable']);
	$t->same(1,$manifest['capabilities']['table']['sortable']);
	$t->same(1,$manifest['capabilities']['table']['filters']);
	$t->same(1,$manifest['capabilities']['table']['views']);
	$t->same(1,$manifest['capabilities']['table']['groups']);
	$t->same(1,$manifest['capabilities']['table']['summaries']);
	$t->same(3,$manifest['capabilities']['facts']['total']);
	$t->same(1,$manifest['capabilities']['facts']['dynamic']);
	$t->same(1,$manifest['capabilities']['facts']['formatted']);
	$t->same(6,$manifest['capabilities']['operations']['custom_handlers']);
	$t->same(true,$manifest['capabilities']['data']['key_mapped']);
	$t->same(true,$manifest['capabilities']['presentation']['has_empty_description']);
	$t->same(2,$manifest['capabilities']['permission']['total']);
	$t->same(2,count($manifest['permission']['permissions']));
	$t->contains('relation.line_items.view',$manifest['permission']['operations']['view']);
	$t->contains('relation.line_items.update',$manifest['permission']['operations']['update']);
	$t->same('definition',$manifest['meta']['origin']);
	$t->same('caller',$manifest['meta']['override']);

	$events=PanelTrace::events();
	$last=$events[array_key_last($events)] ?? [];
	$t->same('relation.manifest.described',$last['event'] ?? '');
	$t->same('line_items',$last['context']['name'] ?? '');
})->tag('panel','relation-manifest','coverage')->group('framework-coverage');

test('panel relation manifest explains read only missing handler disabled and fallback states',static function(Context $t): void {
	$readOnly=RelationManifest::from([
		'name'=>'read_only_items',
		'read_only'=>true,
		'create_enabled'=>true,
		'attach_enabled'=>true,
		'detach_enabled'=>true,
		'associate_enabled'=>true,
		'dissociate_enabled'=>true,
		'reorder_enabled'=>true,
		'attaches'=>true,
		'detaches'=>true,
		'associates'=>true,
		'dissociates'=>true,
		'reorders'=>true,
		'updates_pivot'=>true,
		'pivot_fields'=>[['name'=>'note']],
		'table_schema'=>[],
	],null,['resource'=>'orders'])->toArray();
	$t->same('Read Only Items',$readOnly['label']);
	$t->same(false,$readOnly['operations']['writable']);
	$t->same(false,$readOnly['operations']['attach_entry']['authorized']);
	$t->same('Relation is read-only.',$readOnly['operations']['attach_entry']['disabled_reason']);
	$t->same('Relation is read-only.',$readOnly['operations']['update_pivot_entry']['disabled_reason']);

	$missingHandler=RelationManifest::from([
		'name'=>'handlerless',
		'attach_enabled'=>true,
		'attaches'=>false,
		'pivot_fields'=>'invalid',
		'operations'=>'invalid',
		'facts'=>'invalid',
		'table_schema'=>[],
		'meta'=>'invalid',
	])->toArray();
	$t->same(false,$missingHandler['operations']['attach_entry']['enabled']);
	$t->same('Operation handler is not registered.',$missingHandler['operations']['attach_entry']['disabled_reason']);
	$t->same([],$missingHandler['operations']['pivot_fields']);
	$t->same([],$missingHandler['facts']);
	$t->same([],$missingHandler['meta']);
	$t->same(0,$missingHandler['capabilities']['permission']['total']);

	$disabled=RelationManifest::from([
		'name'=>'disabled-handler',
		'attach_enabled'=>false,
		'attaches'=>true,
		'operations'=>[
			'attach'=>['disabled_reason'=>'Disabled by tenant policy.'],
		],
		'table_schema'=>[],
	])->toArray();
	$t->same(false,$disabled['operations']['attach_entry']['enabled']);
	$t->same('Disabled by tenant policy.',$disabled['operations']['attach_entry']['disabled_reason']);
	$t->same(true,$disabled['operations']['attach_entry']['handler']);

	$defaultReason=RelationManifest::from([
		'name'=>'default-disabled-handler',
		'attach_enabled'=>false,
		'attaches'=>true,
		'table_schema'=>[],
	])->toArray();
	$t->same('Operation is not enabled for this relation.',$defaultReason['operations']['attach_entry']['disabled_reason']);

	$blank=RelationManifest::from(['name'=>'','table_schema'=>[]],null,['resource'=>'orders'])->toArray();
	$t->same('Relation',$blank['label']);
	$t->same('',$blank['permission']['relation']);
	$t->same([],$blank['permission']['permissions']);
	$default=RelationManifest::from(['table_schema'=>[]])->toArray();
	$t->same('relation',$default['name']);
	$t->same('Relation',$default['label']);

	$manager=RelationManager::make('manager-source')
		->column(Column::make('id')->sortable())
		->relatedResource('products');
	$fromManager=RelationManifest::from(
		$manager,
		PanelRequest::fromArray(['resource'=>'orders','operation'=>'relation']),
		['resource'=>'orders','source'=>'manager']
	)->toArray();
	$t->same('manager-source',$fromManager['name']);
	$t->same('Manager Source',$fromManager['label']);
	$t->same('manager',$fromManager['meta']['source']);
	$t->same(1,$fromManager['capabilities']['table']['columns']);
})->tag('panel','relation-manifest','coverage')->group('framework-coverage');

test('panel relation manifest preserves operation data when table manifestation fails',static function(Context $t): void {
	$throwingName=new class implements Stringable {
		public function __toString(): string {
			throw new RuntimeException('Relation table schema exploded.');
		}
	};
	$manifest=RelationManifest::from([
		'name'=>'broken_table',
		'related_resource'=>'products',
		'queryable'=>true,
		'attach_enabled'=>true,
		'attaches'=>true,
		'table_schema'=>[
			'columns'=>[['name'=>$throwingName]],
		],
	],null,['resource'=>'orders'])->toArray();
	$t->same('table_manifest',$manifest['table']['type']);
	$t->same('Relation table schema exploded.',$manifest['table']['error']);
	$t->same([],$manifest['table']['columns']);
	$t->same([],$manifest['table']['filters']);
	$t->same([],$manifest['table']['views']);
	$t->same([],$manifest['table']['groups']);
	$t->same([],$manifest['table']['summaries']);
	$t->same([],$manifest['table']['capabilities']);
	$t->same(true,$manifest['operations']['attach_entry']['enabled']);
	$t->same(true,$manifest['capabilities']['data']['queryable']);
	$t->same(0,$manifest['capabilities']['table']['columns']);
})->tag('panel','relation-manifest','coverage')->group('framework-coverage');
