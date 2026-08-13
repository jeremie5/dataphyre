<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\NavigationCluster;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelMenuItem;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel menu items cover array hydration lazy visibility URL resolution and guarded failures',static function(Context $t): void {
	$item=PanelMenuItem::fromArray([
		'name'=>'Account Settings',
		'label'=>' Account ',
		'description'=>' Manage account ',
		'icon'=>' user ',
		'url'=>' /panel/account ',
		'tone'=>'success',
		'sort'=>17,
		'new_tab'=>true,
		'hidden'=>true,
		'meta'=>['source'=>'definition'],
	]);
	$array=$item->toArray();
	$t->same('account_settings',$item->name());
	$t->same('Account',$array['label']);
	$t->same('Manage account',$array['description']);
	$t->same('user',$array['icon']);
	$t->same('/panel/account',$array['url']);
	$t->same('success',$array['tone']);
	$t->same(17,$array['sort']);
	$t->same(true,$array['new_tab']);
	$t->same(true,$array['hidden']);
	$t->same('definition',$array['meta']['source']);
	$t->same(false,$item->isVisible());
	$t->same(true,PanelMenuItem::make('plain')->isVisible());

	$request=PanelRequest::fromArray(['resource'=>'orders']);
	$manager=new PanelManager();
	$lazy=PanelMenuItem::make('lazy')
		->visibleUsing(static fn(?PanelRequest $request,PanelMenuItem $item,?PanelManager $manager): bool=>$request?->resourceName()==='orders' && $item->name()==='lazy' && $manager instanceof PanelManager)
		->url(static fn(?PanelRequest $request,PanelMenuItem $item,?PanelManager $manager): string=>'/'.$request?->resourceName().'/'.$item->name().'/'.($manager instanceof PanelManager ? 'manager' : 'none'));
	$t->same(true,$lazy->isVisible($request,$manager));
	$t->same('/orders/lazy/manager',$lazy->toArray($request,$manager)['url']);

	$visibilityFailure=PanelMenuItem::make('visibility_failure')->visibleUsing(static fn()=>throw new RuntimeException('visibility failed'));
	$t->same(false,$visibilityFailure->isVisible($request,$manager));
	$urlFailure=PanelMenuItem::make('url_failure')->url(static fn()=>throw new RuntimeException('url failed'));
	$t->same(null,$urlFailure->toArray($request,$manager)['url']);
})->tag('panel','navigation','menu','coverage')->group('framework-coverage');

test('navigation clusters cover array hydration callable badges and guarded badge failures',static function(Context $t): void {
	$cluster=NavigationCluster::fromArray([
		'group'=>'Sales Operations',
		'label'=>' Sales ',
		'icon'=>' chart ',
		'description'=>' Revenue tools ',
		'sort'=>25,
		'badge'=>8,
		'badge_tone'=>'warning',
		'collapsed'=>true,
		'meta'=>['source'=>'definition'],
	]);
	$array=$cluster->toArray();
	$t->same('sales_operations',$cluster->name());
	$t->same('Sales',$array['label']);
	$t->same('chart',$array['icon']);
	$t->same('Revenue tools',$array['description']);
	$t->same(25,$array['sort']);
	$t->same(8,$array['badge']);
	$t->same('warning',$array['badge_tone']);
	$t->same(true,$array['collapsed']);
	$t->same('definition',$array['meta']['source']);

	$request=PanelRequest::fromArray(['resource'=>'orders']);
	$manager=new PanelManager();
	$lazy=NavigationCluster::make('lazy_cluster')->badge(
		static fn(?PanelRequest $request,NavigationCluster $cluster,?PanelManager $manager): string=>$request?->resourceName().':'.$cluster->name().':'.($manager instanceof PanelManager ? 'manager' : 'none')
	);
	$t->same('orders:lazy_cluster:manager',$lazy->toArray($request,$manager)['badge']);
	$failure=NavigationCluster::make('failing_cluster')->badge(static fn()=>throw new RuntimeException('badge failed'));
	$t->same(null,$failure->toArray($request,$manager)['badge']);
})->tag('panel','navigation','cluster','coverage')->group('framework-coverage');
