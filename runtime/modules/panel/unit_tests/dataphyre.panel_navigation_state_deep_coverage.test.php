<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelNavigationState;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);


/** @return list<array<string,mixed>|string> */
function dp_panel_navigation_state_entries(): array {
	return [
		'ignored scalar',
		[
			'name'=>'reports','label'=>'Reports','group'=>'Analytics','sort'=>10,'kind'=>'page',
			'icon'=>' chart ','url'=>' /reports ','description'=>' Reporting ','badge'=>7,
			'badge_tone'=>'Info Tone','new_tab'=>true,'folder_only'=>false,'meta'=>['source'=>'fixture'],
		],
		[
			'name'=>'orders','label'=>'','group'=>'','sort'=>20,'kind'=>'resource','url'=>'/orders',
			'children'=>[
				['name'=>'nested_later','label'=>'Nested later','sort'=>20,'kind'=>'page'],
				'ignored child',
				['name'=>'nested_first','label'=>'Nested first','sort'=>5,'kind'=>'page'],
			],
		],
		['name'=>'order_create','label'=>'Create order','parent'=>'orders','sort'=>4,'kind'=>'page','url'=>'/orders/create'],
		['name'=>'order_logs','label'=>'Order logs','folder'=>'orders','sort'=>6,'kind'=>'page','url'=>'/orders/logs'],
		['name'=>'order_meta','label'=>'Order meta','sort'=>7,'kind'=>'page','meta'=>['parent'=>'orders']],
		['name'=>'order_folder_meta','label'=>'Folder meta','sort'=>8,'kind'=>'page','meta'=>['folder'=>'orders']],
		['name'=>'orphan','label'=>'Orphan','parent'=>'missing','sort'=>30,'kind'=>'custom kind'],
		['name'=>'self_parent','label'=>'Self parent','parent'=>'self_parent','sort'=>31],
		['name'=>'','label'=>'','sort'=>32,'kind'=>'unknown'],
		['name'=>'cycle_a','label'=>'Cycle A','parent'=>'cycle_b','sort'=>40],
		['name'=>'cycle_b','label'=>'Cycle B','parent'=>'cycle_a','sort'=>41],
	];
}

test('panel navigation state builds sorted grouped active searchable finite navigation trees',static function(Context $t): void {
	$request=PanelRequest::fromArray([
		'resource'=>'Order Logs','operation'=>'show','record'=>'9','query'=>['page'=>2],
	]);
	$state=PanelNavigationState::make(dp_panel_navigation_state_entries(),$request,[
		'query'=>' orders ','results'=>[['name'=>'orders'],'raw result'],
	],['source'=>'coverage']);
	$t->instanceOf(PanelNavigationState::class,$state);
	$t->same('reports',$state->entries()[0]['name']);
	$t->isTrue(count($state->entries())>=6);
	$t->isTrue(count($state->allEntries())>=12);
	$t->same('order_logs',$state->active()['name']);
	$t->same('show',$state->active()['operation']);
	$t->same('orders',$state->entry('Orders')['name']);
	$t->same('order_logs',$state->entry('Order Logs')['name']);
	$t->same(null,$state->entry('missing'));
	$t->isTrue($state->entry('orders')['active_descendant']);
	$t->isTrue($state->entry('order_logs')['active']);
	$t->isTrue($state->entry('cycle_a')!==null);
	$t->isTrue($state->entry('cycle_b')!==null);
	$t->isTrue(count($state->groups())>=2);
	$t->isTrue((bool)array_filter($state->groups(),static fn(array $group): bool=>$group['active']===true));
	$t->same('orders',$state->search()['query']);
	$t->same(2,$state->search()['result_count']);
	$t->same('coverage',$state->meta()['source']);
	$t->same(count($state->groups()),$state->meta()['group_count']);
	$t->same($state->entries(),$state->jsonSerialize()['entries']);

	$dashboard=PanelNavigationState::make([],null,['query'=>' ','results'=>'invalid']);
	$t->same('dashboard',$dashboard->active()['kind']);
	$t->same('', $dashboard->active()['name']);
	$t->same('', $dashboard->search()['query']);
	$t->same([], $dashboard->search()['results']);
	$t->same([], $dashboard->entries());
	$t->same([], $dashboard->groups());
	$t->same([],PanelNavigationState::make([],PanelRequest::fromArray(['resource'=>'unknown']))->active());

	$manual=new PanelNavigationState([['name'=>'manual']],[],['name'=>'manual'],['query'=>'m'],['manual'=>true]);
	$t->same('manual',$manual->entries()[0]['name']);
	$t->same(['name'=>'manual'],$manual->active());
	$t->same(['query'=>'m'],$manual->search());
	$t->same(['manual'=>true],$manual->meta());
})->tag('panel','navigation-state','coverage')->group('framework-coverage');

test('panel navigation state private helpers normalize cycles activity grouping and recursion',static function(Context $t): void {
	$navigationInternals=$t->nonPublic(PanelNavigationState::class);
	$normalized=$navigationInternals->invoke('normalizeEntry',[
		'name'=>'User Settings','label'=>' ','group'=>' ','folder'=>'Parent Folder','icon'=>' ','url'=>' /settings ',
		'sort'=>'4','kind'=>'odd kind','description'=>' ','badge'=>3,'badge_tone'=>'Warning Tone',
		'new_tab'=>1,'folder_only'=>true,'active'=>true,'meta'=>'invalid',
		'children'=>[
			['name'=>'later_child','label'=>'Later','sort'=>20],
			'ignored',
			['name'=>'first_child','label'=>'First','sort'=>1],
		],
	]);
	$t->same('user_settings',$normalized['name']);
	$t->same('User Settings',$normalized['label']);
	$t->same(null,$normalized['group']);
	$t->same('parent_folder',$normalized['parent']);
	$t->same(null,$normalized['icon']);
	$t->same('/settings',$normalized['url']);
	$t->same('navigation_item',$normalized['kind']);
	$t->isFalse($normalized['new_tab']);
	$t->isTrue($normalized['folder_only']);
	$t->isTrue($normalized['active']);
	$t->same('first_child',$normalized['children'][0]['name']);
	$t->same([], $normalized['meta']);
	$metaParent=$navigationInternals->invoke('normalizeEntry',[
		'name'=>'meta_child','meta'=>['parent'=>'meta_parent','source'=>'meta'],
	]);
	$t->same('meta_parent',$metaParent['parent']);
	$t->same('meta',$metaParent['meta']['source']);
	$untitled=$navigationInternals->invoke('normalizeEntry',[]);
	$t->same('Untitled',$untitled['label']);
	$t->same('Untitled',$navigationInternals->invoke('humanize','---'));
	$t->same('User Settings',$navigationInternals->invoke('humanize','user_settings'));

	$parent=$navigationInternals->invoke('normalizeEntry',[
		'name'=>'parent','label'=>'Parent','group'=>'Workspace','sort'=>20,'children'=>[
			['name'=>'child','label'=>'Child','kind'=>'page','sort'=>1],
		],
	]);
	$analytics=$navigationInternals->invoke('normalizeEntry',[
		'name'=>'analytics','label'=>'Analytics','group'=>'Analytics','sort'=>5,'kind'=>'page',
	]);
	$groups=$navigationInternals->invoke('groupEntries',[$parent,$analytics],'child');
	$t->same('Analytics',$groups[0]['label']);
	$t->same(2,$groups[1]['count']);
	$t->isTrue($groups[1]['active']);
	$t->same([],$navigationInternals->invoke('groupEntries',[]));

	$entries=[$parent,$analytics];
	$active=$navigationInternals->invoke('activeEntry',$entries,PanelRequest::fromArray(['resource'=>'child','operation'=>'edit']));
	$t->same('child',$active['name']);
	$t->same('edit',$active['operation']);
	$t->same('dashboard',$navigationInternals->invoke('activeEntry',$entries,null)['kind']);
	$t->same([],$navigationInternals->invoke('activeEntry',$entries,PanelRequest::fromArray(['resource'=>'missing'])));
	$t->same(['query'=>'needle','result_count'=>2,'results'=>['a','b']],$navigationInternals->invoke('normalizeSearch',[
		'query'=>' needle ','results'=>['a','b'],
	]));
	$t->same(['query'=>'','result_count'=>0,'results'=>[]],$navigationInternals->invoke('normalizeSearch',['results'=>'invalid']));

	$tree=$navigationInternals->invoke('navigationTree',array_map(
		static fn(array $entry): array=>$navigationInternals->invoke('normalizeEntry',$entry),
		[
			['name'=>'root','sort'=>20,'children'=>[['name'=>'explicit','sort'=>5]]],
			['name'=>'flat_child','parent'=>'root','sort'=>1],
			['name'=>'orphan','parent'=>'missing','sort'=>10],
			['name'=>'self','parent'=>'self','sort'=>30],
			['name'=>'','sort'=>40],
		]
	));
	$t->same('orphan',$tree[0]['name']);
	$root=$navigationInternals->invoke('findEntry',$tree,'root');
	$t->same('flat_child',$root['children'][0]['name']);
	$t->same('explicit',$root['children'][1]['name']);

	$cycle=$navigationInternals->invoke('navigationTree',array_map(
		static fn(array $entry): array=>$navigationInternals->invoke('normalizeEntry',$entry),
		[
			['name'=>'cycle_a','parent'=>'cycle_b'],
			['name'=>'cycle_b','parent'=>'cycle_a'],
		]
	));
	$t->same(1,count($cycle));
	$t->isTrue(count($navigationInternals->invoke('flattenEntries',$cycle))<=3);

	$marked=$navigationInternals->invoke('markActive',[
		'name'=>'root','children'=>[
			'ignored',
			['name'=>'child','children'=>[]],
		],
	],'child');
	$t->isFalse($marked['active']);
	$t->isTrue($marked['active_descendant']);
	$t->isTrue($navigationInternals->invoke('childrenActive',$marked['children']));
	$t->isFalse($navigationInternals->invoke('childrenActive',[]));
	$t->isTrue($navigationInternals->invoke('entryTreeActive',['active'=>true]));
	$t->isTrue($navigationInternals->invoke('entryTreeActive',['active_descendant'=>true]));
	$t->isFalse($navigationInternals->invoke('entryTreeActive',['children'=>[['active'=>false,'children'=>[]]]]));
	$t->same(2,$navigationInternals->invoke('countEntries',[['name'=>'root','children'=>[['name'=>'child']]]]));
	$flat=$navigationInternals->invoke('flattenEntries',[['name'=>'root','children'=>[['name'=>'child']]]]);
	$t->same(['root','child'],array_column($flat,'name'));
	$t->same('root',$navigationInternals->invoke('findEntry',$flat,'root')['name']);
	$t->same('child',$navigationInternals->invoke('findEntry',[['name'=>'root','children'=>[['name'=>'child']]]],'child')['name']);
	$t->same(null,$navigationInternals->invoke('findEntry',$flat,'missing'));
})->tag('panel','navigation-state','coverage')->group('framework-coverage');
