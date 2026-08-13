<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel action group imports rich definitions styles alignment and menu records',static function(Context $t): void {
	$group=ActionGroup::fromArray([
		'name'=>'bulk-tools','label'=>' Bulk Tools ','icon'=>' layers ','tone'=>'danger','style'=>'outlined','size'=>'large','icon_only'=>true,'record_placement'=>'secondary',
		'dropdown_width'=>'auto','dropdown_alignment'=>'left',
		'actions'=>[
			['name'=>'approve','label'=>'Approve'],
			'archive',
			['type'=>'section','label'=>'More','description'=>'Secondary'],
			['type'=>'separator'],
		],
		'items'=>[
			['type'=>'heading','name'=>'Primary'],
			['type'=>'action','name'=>'approve'],
			['name'=>'archive'],
			['type'=>'divider'],
			['type'=>'action','name'=>'missing'],
			['type'=>'unsupported'],
			'bad',
		],
		'meta'=>['source'=>'manifest'],
	]);
	$array=$group->toArray();
	$t->same('bulk-tools',$group->name());
	$t->same('Bulk Tools',$array['label']);
	$t->same('layers',$array['icon']);
	$t->same('danger',$array['tone']);
	$t->same('outline',$array['style']);
	$t->same('lg',$array['size']);
	$t->isTrue($array['icon_only']);
	$t->same('overflow',$array['record_placement']);
	$t->same('auto',$array['dropdown_width']);
	$t->same('start',$array['dropdown_alignment']);
	$t->same(2,count($array['actions']));
	$t->same(4,count($array['items']));
	$t->same(['source'=>'manifest'],$array['meta']);
	$t->instanceOf(Action::class,$group->actionByName('APPROVE'));
	$t->same(null,$group->actionByName('missing'));

	$t->same('center',ActionGroup::fromArray(['name'=>'center','alignment'=>'middle'])->toArray()['dropdown_alignment']);
	$t->same('end',ActionGroup::fromArray(['name'=>'end','placement'=>'after'])->toArray()['dropdown_alignment']);
	$t->same('ghost',ActionGroup::fromArray(['name'=>'variant','variant'=>'subtle'])->toArray()['style']);
	$t->same('Actions',ActionGroup::fromArray([])->toArray()['label']);
})->tag('panel','action-group','coverage')->group('framework-coverage');

test('panel action group fluent aliases normalize every style size width and alignment branch',static function(Context $t): void {
	$base=ActionGroup::make('tool.group');
	$t->same('Tool Group',$base->toArray()['label']);
	$t->same('Tool Group',$base->label(' ')->toArray()['label']);
	$t->same(null,$base->icon(' ')->toArray()['icon']);
	$t->same('neutral',$base->tone('invalid')->toArray()['tone']);
	foreach([
		['outline','outline'],['outlined','outline'],['ghost','ghost'],['subtle','ghost'],['text','ghost'],['link','link'],['bad','solid'],
	] as [$input,$expected]){
		$t->same($expected,$base->style($input)->toArray()['style']);
	}
	$t->same('ghost',$base->variant('ghost')->toArray()['style']);
	$t->same('outline',$base->outlined()->toArray()['style']);
	$t->same('solid',$base->outlined(false)->toArray()['style']);
	$t->same('outline',$base->outline()->toArray()['style']);
	$t->same('solid',$base->outline(false)->toArray()['style']);
	$t->same('ghost',$base->ghost()->toArray()['style']);
	$t->same('solid',$base->ghost(false)->toArray()['style']);
	$t->same('ghost',$base->subtle()->toArray()['style']);
	$t->same('solid',$base->subtle(false)->toArray()['style']);
	$t->same('link',$base->link()->toArray()['style']);
	$t->same('solid',$base->link(false)->toArray()['style']);
	foreach([
		['xs','xs'],['sm','sm'],['md','md'],['lg','lg'],['xl','xl'],['small','sm'],['large','lg'],['bad','md'],
	] as [$input,$expected]){
		$t->same($expected,$base->size($input)->toArray()['size']);
	}
	$t->same('sm',$base->compact()->toArray()['size']);
	$t->same('md',$base->compact(false)->toArray()['size']);
	$t->same('lg',$base->large()->toArray()['size']);
	$t->same('md',$base->large(false)->toArray()['size']);
	$t->isTrue($base->iconOnly()->toArray()['icon_only']);
	$t->isFalse($base->iconOnly(false)->toArray()['icon_only']);
	$t->isTrue($base->iconButton()->toArray()['icon_only']);
	$t->isFalse($base->iconButton(false)->toArray()['icon_only']);
	$t->same('primary',$base->recordPlacement('inline')->recordPlacementMode());
	$t->same('overflow',$base->recordOverflow()->recordPlacementMode());
	$t->same('auto',$base->recordPrimary(false)->recordPlacementMode());
	foreach([
		['xs','xs'],['sm','sm'],['md','md'],['lg','lg'],['xl','xl'],['auto','auto'],['small','sm'],['large','lg'],['bad','md'],
	] as [$input,$expected]){
		$t->same($expected,$base->dropdownWidth($input)->toArray()['dropdown_width']);
	}
	foreach([
		['left','start'],['start','start'],['before','start'],['center','center'],['middle','center'],['right','end'],['end','end'],['after','end'],['bad','end'],
	] as [$input,$expected]){
		$t->same($expected,$base->dropdownAlignment($input)->toArray()['dropdown_alignment']);
	}
	$t->same('start',$base->alignStart()->toArray()['dropdown_alignment']);
	$t->same('center',$base->alignCenter()->toArray()['dropdown_alignment']);
	$t->same('end',$base->alignEnd()->toArray()['dropdown_alignment']);
})->tag('panel','action-group','coverage')->group('framework-coverage');

test('panel action group handles action markers default menus replacement and immutable metadata',static function(Context $t): void {
	$base=ActionGroup::make('actions');
	$group=$base->actions([
		Action::make('edit'),
		['name'=>'delete'],
		['type'=>'heading','label'=>'Danger','description'=>'Careful'],
		['type'=>'divider'],
		'',
	])->section(' ')->heading('More','Other')->divider()->meta(['one'=>1])->meta(['two'=>2]);
	$t->same([],$base->actionsList());
	$t->same(2,count($group->actionsList()));
	$t->same(6,count($group->menuItems()));
	$t->same(['one'=>1,'two'=>2],$group->toArray()['meta']);

	$actionsOnly=ActionGroup::make('default-menu');
	$t->nonPublic($actionsOnly)->writeProperty('actions',['one'=>Action::make('one'),'two'=>Action::make('two')]);
	$t->same([
		['type'=>'action','name'=>'one'],['type'=>'action','name'=>'two'],
	],$actionsOnly->menuItems());
	$menu=$actionsOnly->menu([
		['type'=>'section','label'=>' '],
		['type'=>'separator'],
		['type'=>'action','name'=>'one'],
		['name'=>'two'],
		['type'=>'action','name'=>'missing'],
	]);
	$t->same(4,count($menu->menuItems()));
})->tag('panel','action-group','coverage')->group('framework-coverage');

test('panel action group resolves runtime manifests in resource and request context',static function(Context $t): void {
	$resource=Resource::make('orders');
	$request=PanelRequest::fromArray(['method'=>'GET','resource'=>'orders','operation'=>'index']);
	$group=ActionGroup::make('bulk')->label('Bulk')->recordPrimary()->action(Action::make('approve')->visibleUsing(static fn(): bool=>true));
	$manifest=$group->manifest(['id'=>1],$request,$resource,'bulk',['source'=>'deep']);
	$t->notEmpty($manifest);
	$t->same('bulk',$manifest['name']);
	$t->same('primary',$manifest['record_placement']);
})->tag('panel','action-group','coverage')->group('framework-coverage');
