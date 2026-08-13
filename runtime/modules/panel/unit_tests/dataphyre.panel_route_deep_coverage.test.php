<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRoute;
use Dataphyre\Panel\Resource;
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http','routing','mvc','panel']);

test('panel route builds canonical urls assets uploads and manifests',static function(Context $t): void {
	$builder=PanelRoute::urlBuilder(' admin/ ');
	$t->same('/admin/orders/42?tab=profile',$builder('orders/show/42',['tab'=>'profile']));
	$t->same('/panel',PanelRoute::url('/panel/'));
	$t->same('/',PanelRoute::url('/'));
	$t->same('/panel/orders/42?record=99&page=2&nested%5Bkeep%5D=yes',PanelRoute::url('/panel','orders/show/42',[
		'resource'=>' Orders ','operation'=>'SHOW','record'=>'99','page'=>2,'blank'=>'','null'=>null,
		'nested'=>['keep'=>'yes','drop'=>'','empty'=>[]],
	]));
	$t->same('/panel/orders/42?page=2',PanelRoute::url('/panel','orders/show/42',[
		'resource'=>'orders','operation'=>'show','record'=>'42','page'=>2,
	]));
	$t->same('/orders/A%20B/edit',PanelRoute::url('/','orders/edit/A%20B'));
	$t->same('/panel/orders/items/action/A%2F1',PanelRoute::url('/panel','orders/action/A%2F1/items'));
	$t->same('/panel/orders/A%2F1/relation/lines/edit',PanelRoute::url('/panel','orders/relation/A%2F1/lines/edit'));
	$t->same('/panel/orders/A%2F1/transition',PanelRoute::url('/panel','orders/transition/A%2F1'));

	$asset=PanelRoute::assetUrl('/panel/','../assets\\theme.css');
	$t->contains('/panel/assets/theme.css?v=',$asset);
	$t->same('/upload',PanelRoute::uploadUrl('/'));
	$t->same('/ops/upload',PanelRoute::uploadUrl('ops'));

	$instance=new PanelInstance('operations',new PanelManager());
	$manifest=PanelRoute::manifest(' /ops/ ',$instance,['surface'=>'override','name'=>'panel.ops']);
	$t->same('panel_route_manifest',$manifest['type']);
	$t->same('/ops',$manifest['prefix']);
	$t->same('override',$manifest['surface']);
	$t->same('panel.ops.catch_all',$manifest['route_names']['catch_all']);
	$t->same('/ops/{...panel_segments}',$manifest['routes']['catch_all']);
	$t->contains('/ops/assets/panel.css?v=',$manifest['urls']['asset']);
	$t->same('',$manifest['legacy']['assets']==='' ? '' : '');

	$default=PanelRoute::manifest('/',null,['surface'=>' ','name'=>' ']);
	$t->same('default',$default['surface']);
	$t->same('',$default['route_names']['page']);
	$t->same('/{...panel_segments}',$default['routes']['catch_all']);
})->tag('panel','route','coverage')->group('framework-coverage');

test('panel route canonical segment identity and query helpers cover every route shape',static function(Context $t): void {
	$t->same(['orders'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders']));
	$t->same(['orders','42'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','show','42']));
	foreach(['edit','update','delete','destroy','force_delete','restore','duplicate','inline_update'] as $operation){
		$normalized=Resource::normalizeName($operation);
		$t->same(['orders','42',$normalized],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders',$operation,'42']));
	}
	$t->same(['orders','42','relation','items','edit'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','relation','42','items','edit']));
	$t->same(['orders','approve','action','42','confirm'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','action','42','approve','confirm']));
	$t->same(['orders','42','transition','extra'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','transition','42','extra']));
	$t->same(['orders','custom','42'],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','custom','42']));
	$t->same(['orders','show',''],$t->nonPublic(PanelRoute::class)->invoke('canonicalSegments',['orders','show','']));

	$t->same([],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',[]));
	$t->same(['resource'=>'orders','operation'=>'index'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders']));
	$t->same(['resource'=>'orders','record'=>'42','operation'=>'action','action'=>'approve'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','42','action','approve']));
	$t->same(['resource'=>'orders','record'=>'42','operation'=>'relation','relation'=>'items'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','42','relation','items']));
	foreach(['edit','update','delete','destroy','force_delete','restore','duplicate','transition','inline_update'] as $operation){
		$t->same($operation,$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','42',$operation])['operation']);
	}
	$t->same(['resource'=>'orders','operation'=>'create'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','create']));
	$t->same(['resource'=>'orders','operation'=>'show','record'=>'42'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','show','42']));
	$t->same(['resource'=>'orders','record'=>'42','operation'=>'show'],$t->nonPublic(PanelRoute::class)->invoke('routeIdentity',['orders','42']));

	$query=$t->nonPublic(PanelRoute::class)->invoke('dropRepresentedRouteQuery',[
		'resource'=>' Orders ','operation'=>'SHOW','record'=>'42','action'=>'different','page'=>2,
	],['orders','42']);
	$t->same(['action'=>'different','page'=>2],$query);
	$query=$t->nonPublic(PanelRoute::class)->invoke('dropRepresentedRouteQuery',[
		'resource'=>'orders','operation'=>'action','record'=>'42','action'=>'APPROVE','relation'=>'unused','keep'=>'yes',
	],['orders','42','action','approve']);
	$t->same(['relation'=>'unused','keep'=>'yes'],$query);

	$t->same(['zero'=>0,'nested'=>['keep'=>'x']],$t->nonPublic(PanelRoute::class)->invoke('filterQuery',[
		'empty'=>'','null'=>null,'zero'=>0,'false'=>false,'nested'=>['keep'=>'x','drop'=>'','empty'=>[]],
	]));
	$t->same('/root/path',$t->nonPublic(PanelRoute::class)->invoke('joinPrefix','/root','/path'));
	$t->same('/path',$t->nonPublic(PanelRoute::class)->invoke('joinPrefix','/','/path'));
	$t->same('/panel',$t->nonPublic(PanelRoute::class)->invoke('prefix','///panel///'));
	$t->same('/',$t->nonPublic(PanelRoute::class)->invoke('prefix','///'));
})->tag('panel','route','coverage')->group('framework-coverage');

test('panel route registers standalone routes names middleware and endpoint overrides',static function(Context $t): void {
	$routes=PanelRoute::routing('/ops','staff',[
		'bootstrap'=>'/tmp/bootstrap.php','name'=>'panel.staff','middleware'=>['auth','audit'],
		'defaults'=>['tenant'=>7],
	]);
	$t->same(2,count($routes));
	$t->isTrue($routes[0] instanceof Route);
	$exact=$routes[0]->compile();
	$catch=$routes[1]->compile();
	$t->same('/ops',$exact['path']);
	$t->same('panel.staff',$exact['name']);
	$t->same('panel.staff.catch_all',$catch['name']);
	$t->same(['auth','audit'],array_column($exact['middleware'],'alias'));
	$t->same('staff',$exact['defaults']['panel_surface']);
	$t->same('/ops',$exact['defaults']['panel_mount_prefix']);
	$t->same(7,$exact['defaults']['tenant']);
	$t->isTrue($exact['handler'] instanceof ControllerAction || is_array($exact['handler']));

	$unnamed=PanelRoute::routing('/',null,['middleware'=>'web','defaults'=>'invalid']);
	$t->same('',(string)($unnamed[0]->compile()['name'] ?? ''));
	$t->same(['web'],array_column($unnamed[0]->compile()['middleware'],'alias'));

	$assets=PanelRoute::assetRouting('/ops',[
		'bootstrap'=>'/tmp/bootstrap.php','name'=>'panel.assets','middleware'=>['cache'],
	]);
	$t->same(1,count($assets));
	$asset=$assets[0]->compile();
	$t->same('/ops/assets/{asset}',$asset['path']);
	$t->same('panel.assets',$asset['name']);
	$t->same('[A-Za-z0-9_.-]+',$asset['constraints']['asset']);

	$uploads=PanelRoute::uploadRouting('/ops',['name'=>'panel.upload','middleware'=>'csrf']);
	$t->same(1,count($uploads));
	$upload=$uploads[0]->compile();
	$t->same('/ops/upload',$upload['path']);
	$t->same(['POST'],$upload['methods']);
	$t->same(['csrf'],array_column($upload['middleware'],'alias'));

	$mounted=PanelRoute::mountedRouting('/ops','staff',[
		'name'=>'panel','middleware'=>['web'],
		'assets_options'=>['name'=>'custom.asset','middleware'=>['cache']],
		'upload_options'=>['middleware'=>['csrf']],
	]);
	$t->same(4,count($mounted));
	$t->same('custom.asset',$mounted[0]->compile()['name']);
	$t->same('panel.upload',$mounted[1]->compile()['name']);
	$t->same('panel',$mounted[2]->compile()['name']);

	$defaults=$t->nonPublic(PanelRoute::class)->invoke('defaults',new PanelInstance('instance',new PanelManager()),[
		'prefix'=>' /mounted/ ','defaults'=>['panel_mount_prefix'=>'/kept','custom'=>true],
	]);
	$t->same('instance',$defaults['panel_surface']);
	$t->same('/kept',$defaults['panel_mount_prefix']);
	$t->isTrue($defaults['custom']);
	$t->same(['panel_surface'=>'override','panel_mount_prefix'=>'/x'],$t->nonPublic(PanelRoute::class)->invoke('defaults','surface',[
		'surface'=>'override','prefix'=>'x',
	]));
	$t->same(['panel_surface'=>'default'],$t->nonPublic(PanelRoute::class)->invoke('defaults',null,['defaults'=>'invalid']));

	$endpoint=$t->nonPublic(PanelRoute::class)->invoke('endpointOptions',[
		'name'=>'panel','middleware'=>['web'],'assets_options'=>['middleware'=>['cache'],'custom'=>true],
	],'assets');
	$t->same('panel.assets',$endpoint['name']);
	$t->same(['cache'],$endpoint['middleware']);
	$t->isTrue($endpoint['custom']);
	$t->same('upload',$t->nonPublic(PanelRoute::class)->invoke('endpointOptions',['name'=>' ','upload_options'=>'invalid'],'upload')['name']);
})->tag('panel','route','coverage')->group('framework-coverage');

test('panel route registers mvc routes constraints defaults and mounted endpoints',static function(Context $t): void {
	$app=new MvcApplication('panel-route');
	$collection=$app->routes();
	$routes=PanelRoute::mvc($collection,'/ops','staff',[
		'name'=>'panel.staff','middleware'=>['web'],'defaults'=>['tenant'=>7],
	]);
	$t->same(2,count($routes));
	$t->same('/ops',$routes[0]->path());
	$t->same('panel.staff',$routes[0]->nameValue());
	$t->same('panel.staff.catch_all',$routes[1]->nameValue());
	$t->same(['web'],$routes[0]->middlewareDefinitions());
	$t->same('staff',$routes[0]->defaultsValues()['panel_surface']);
	$t->same('/ops',$routes[0]->defaultsValues()['panel_mount_prefix']);

	$unnamed=PanelRoute::mvc($collection,'/',null,['name'=>' ','middleware'=>[]]);
	$t->same(null,$unnamed[1]->nameValue());

	$assets=PanelRoute::mvcAssets($collection,'/ops',[
		'name'=>'panel.assets','middleware'=>['cache'],'defaults'=>['public'=>true],
		'constraints'=>['asset'=>'custom','unused'=>'[0-9]+'],
	]);
	$t->same('/ops/assets/{asset}',$assets[0]->path());
	$t->same('[A-Za-z0-9_.-]+',$assets[0]->constraints()['asset']);
	$t->same('[0-9]+',$assets[0]->constraints()['unused']);

	$uploads=PanelRoute::mvcUploads($collection,'/ops',[
		'name'=>'panel.upload','where'=>['tenant'=>'[0-9]+'],'middleware'=>'csrf',
	]);
	$t->same('/ops/upload',$uploads[0]->path());
	$t->same(['POST'],$uploads[0]->methods());
	$t->same('[0-9]+',$uploads[0]->constraints()['tenant']);

	$fresh=(new MvcApplication('mounted'))->routes();
	$mounted=PanelRoute::mvcMounted($fresh,'/admin',new PanelInstance('admin',new PanelManager()),[
		'name'=>'panel.admin','middleware'=>['web'],
		'assets_options'=>['name'=>'asset.custom'],
		'upload_options'=>['defaults'=>['disk'=>'public']],
	]);
	$t->same(4,count($mounted));
	$t->same('asset.custom',$mounted[0]->nameValue());
	$t->same('panel.admin.upload',$mounted[1]->nameValue());
	$t->same('public',$mounted[1]->defaultsValues()['disk']);
	$t->same('panel.admin',$mounted[2]->nameValue());

	$t->same([],$t->nonPublic(PanelRoute::class)->invoke('mvcEndpointOptions',[]));
	$t->same([
		'name'=>'x','middleware'=>'web','defaults'=>['a'=>1],'where'=>['id'=>'[0-9]+'],
	],$t->nonPublic(PanelRoute::class)->invoke('mvcEndpointOptions',['name'=>'x','middleware'=>'web','defaults'=>['a'=>1],'where'=>['id'=>'[0-9]+']]));
	$t->same(['where'=>['id'=>'[a-z]+']],$t->nonPublic(PanelRoute::class)->invoke('mvcEndpointOptions',['constraints'=>['id'=>'[a-z]+']]));
})->tag('panel','route','coverage')->group('framework-coverage');
