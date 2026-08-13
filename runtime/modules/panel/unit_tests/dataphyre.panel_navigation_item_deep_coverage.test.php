<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel navigation item imports complete nested definitions and preserves immutable builders',static function(Context $t): void {
	$item=NavigationItem::fromArray([
		'name'=>'order-center','label'=>'Order Center','url'=>' /orders ','group'=>' Commerce ','folder'=>'workspace',
		'icon'=>' shopping-bag ','description'=>' Manage orders ','sort'=>'20','badge'=>5,'badge_tone'=>'SUCCESS','new_tab'=>1,
		'folder_only'=>true,'hidden'=>true,'meta'=>['source'=>'manifest'],'children'=>[
			['name'=>'draft-orders','label'=>'Drafts','submenu'=>true],
			NavigationItem::make('archived'),
			'invalid',
		],
	]);
	$array=$item->toArray();
	$t->same('order-center',$item->name());
	$t->same('Order Center',$array['label']);
	$t->same('/orders',$array['url']);
	$t->same('Commerce',$array['group']);
	$t->same('workspace',$array['parent']);
	$t->same('shopping-bag',$array['icon']);
	$t->same('Manage orders',$array['description']);
	$t->same(20,$array['sort']);
	$t->same(5,$array['badge']);
	$t->same('success',$array['badge_tone']);
	$t->isTrue($array['new_tab']);
	$t->isTrue($array['folder_only']);
	$t->isTrue($array['hidden']);
	$t->same(2,count($array['children']));
	$t->same(['source'=>'manifest'],$array['meta']);

	$base=NavigationItem::make('base-item');
	$parent=NavigationItem::make('parent-item');
	$built=$base->label(' Built ')->url(' /built ')->group(' ')->parent($parent)->folder('folder-name')->folder(null)
		->icon(' ')->description(' ')->sort(-3)->badge('hot')->badgeTone('invalid')->newTab(false)->hide(false)
		->submenu(false)->meta(['one'=>1])->meta(['two'=>2])->child(['name'=>'one'])->child(NavigationItem::make('two'))
		->children([NavigationItem::make('three'),['name'=>'four'],42]);
	$t->same('Base Item',$base->toArray()['label']);
	$t->same('Built',$built->toArray()['label']);
	$t->same(null,$built->toArray()['group']);
	$t->same(null,$built->toArray()['parent']);
	$t->same('link',$built->toArray()['icon']);
	$t->same(null,$built->toArray()['description']);
	$t->same('neutral',$built->toArray()['badge_tone']);
	$t->same(2,count($built->toArray()['children']));
	$t->same(['one'=>1,'two'=>2],$built->toArray()['meta']);
	$t->same('',NavigationItem::make('...')->name());
})->tag('panel','navigation-item','coverage')->group('framework-coverage');

test('panel navigation item evaluates visibility badges child filters and runtime defaults safely',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET','resource'=>'orders','operation'=>'index']);
	$manager=new PanelManager();
	$t->isFalse(NavigationItem::make('hidden')->hide()->isVisible($request,$manager));
	$t->isTrue(NavigationItem::make('plain')->isVisible($request,$manager));
	$t->isTrue(NavigationItem::make('visible')->visibleUsing(static fn(PanelRequest $request,NavigationItem $item,PanelManager $manager): bool=>$item->name()==='visible')->isVisible($request,$manager));
	$t->isFalse(NavigationItem::make('broken-visible')->visibleUsing(static function(): bool { throw new RuntimeException('visibility failed'); })->isVisible($request,$manager));

	$item=NavigationItem::make('orders')->badge(static fn(PanelRequest $request,NavigationItem $item,PanelManager $manager): int=>7)
		->children([
			NavigationItem::make('visible-child')->badgeUsing(static fn(): int=>2),
			NavigationItem::make('hidden-child')->hide(),
		]);
	$entry=PanelContext::run(['url_builder'=>static fn(string $target,array $query=[]): string=>'/panel/'.ltrim($target,'/')],static fn(): array=>$item->navigationEntry($request,$manager));
	$t->same(7,$entry['badge']);
	$t->same('/panel/orders',$entry['url']);
	$t->same('link',$entry['icon']);
	$t->same(1,count($entry['children']));
	$t->same(2,$entry['children'][0]['badge']);
	$lazy=$item->toArray();
	$t->same(null,$lazy['badge']);
	$t->isTrue($lazy['badge_lazy']);

	$broken=NavigationItem::make('broken-badge')->badgeUsing(static function(): int { throw new RuntimeException('badge failed'); });
	$t->same(null,$broken->navigationEntry($request,$manager)['badge']);
	$static=$broken->badge('static');
	$t->same('static',$static->navigationEntry($request,$manager)['badge']);
	$t->isFalse($static->toArray()['badge_lazy']);
	$folder=NavigationItem::make('folder')->folderOnly()->url('/ignored');
	$t->same('',$folder->navigationEntry($request,$manager)['url']);
})->tag('panel','navigation-item','coverage')->group('framework-coverage');

test('panel navigation item exports manifests and definition defaults',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET']);
	$item=NavigationItem::fromArray([
		'name'=>'reports','parent'=>42,'folder'=>42,'sort'=>null,'badge_tone'=>42,'meta'=>'invalid','children'=>'invalid',
	]);
	$t->same('Reports',$item->toArray()['label']);
	$t->same(null,$item->toArray()['url']);
	$t->same(null,$item->toArray()['parent']);
	$t->notEmpty($item->manifest($request,['source'=>'deep']));
	$t->isTrue(NavigationItem::fromArray(['name'=>'folder','submenu'=>true])->toArray()['folder_only']);
})->tag('panel','navigation-item','coverage')->group('framework-coverage');
