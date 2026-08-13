<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceContext;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceEndpoint;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRange;
use Dataphyre\Panel\PanelDataSurfaceRegistry;
use Dataphyre\Panel\PanelDataSurfaceWindowRequest;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManifest;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array{registry:PanelDataSurfaceRegistry,sources:PanelDataSourceRegistry,signer:PanelDataSurfaceIntentSigner,definition:PanelDataSurfaceDefinition,context:PanelDataSurfaceContext} */
function dp_panel_surface_registry_integration_fixture(string $id='orders_surface',string $policy='replace'):array{
	$sources=(new PanelDataSourceRegistry())->register('orders',new PanelArrayDataSource([['id'=>'one','tenant_id'=>'north','name'=>'One'],['id'=>'two','tenant_id'=>'north','name'=>'Two']],['name'=>'orders']));
	$signer=new PanelDataSurfaceIntentSigner(['active'=>str_repeat('S',32)],'active',static fn():int=>1000);
	$definition=dp_panel_surface_registry_definition($id,'orders');
	$registry=(new PanelDataSurfaceRegistry($sources,$signer,static fn(array $envelope,PanelDataSurfaceContext $context):bool=>$context->principal()==='operator',$policy))->register($definition);
	$context=PanelDataSurfaceContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator','correlation_id'=>'corr']);
	return compact('registry','sources','signer','definition','context');
}

function dp_panel_surface_registry_definition(string $id,string $resource='orders'):PanelDataSurfaceDefinition{
	return PanelDataSurfaceDefinition::make($id,$resource,'orders','table',PanelDataSurfaceProjection::make(['id','name'],'id',['title'=>'name']),PanelDataSurfaceRange::make(0,2,0,0),null,['title'=>$resource]);
}

test('DataSurface registries layer provenance cache public manifests and restore exact checkpoints',static function(Context $t):void{
	$t->throws(static fn()=>new PanelDataSurfaceRegistry(new PanelDataSourceRegistry(),new PanelDataSurfaceIntentSigner(['k'=>str_repeat('K',32)],'k'),static fn()=>true,'invalid'),InvalidArgumentException::class);
	$fixture=dp_panel_surface_registry_integration_fixture();$registry=$fixture['registry'];$base=$fixture['definition'];
	$t->same('panel_data_surface_registry',$registry->checkpointType());$t->same('replace',$registry->conflictPolicy());$t->same(1,$registry->revision());
	$t->throws(static fn()=>$registry->register($base),LogicException::class);
	$one=dp_panel_surface_registry_definition('orders_surface','plugin_one');$two=dp_panel_surface_registry_definition('orders_surface','plugin_two');
	$t->isTrue($registry->contribute($one,'plugin.one',['password'=>'hidden','release'=>'one']));
	$t->isFalse($registry->contribute($two,'plugin.two',[],'keep_first'));
	$t->throws(static fn()=>$registry->contribute($two,'plugin.two',[],'reject'),LogicException::class);
	$t->isTrue($registry->contribute($two,'plugin.two',[],'replace'));
	$t->same($two,$registry->get('orders_surface'));$t->same(3,count($registry->provenance()));
	$t->same('[REDACTED]',$registry->provenance()[1]['meta']['password']);
	$manifest=$registry->manifest();$t->same('plugin.two',$manifest['definitions']['orders_surface']['owner']);$t->same(3,$manifest['definitions']['orders_surface']['layers']);
	$t->isFalse($manifest['capabilities']['live_adapter_code_run_by_manifest']);$t->same(64,strlen($registry->fingerprint()));$t->same($manifest,$registry->jsonSerialize());
	$registry->unregisterContributor('plugin.two');$t->same($one,$registry->get('orders_surface'));
	$registry->unregisterContributor('plugin.one');$t->same($base,$registry->get('orders_surface'));
	$unchanged=$registry->revision();$registry->unregisterContributor('missing');$t->same($unchanged,$registry->revision());
	$registry->conflictPolicyUsing('KEEP_FIRST');$t->same('keep_first',$registry->conflictPolicy());$changed=$registry->revision();$registry->conflictPolicyUsing('keep_first');$t->same($changed,$registry->revision());

	$checkpoint=$registry->checkpoint();$registry->forget('orders_surface');$t->isFalse($registry->has('orders_surface'));$registry->restore($checkpoint);$t->same($base,$registry->get('orders_surface'));
	$t->throws(static fn()=>$registry->restore([]),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['revision']=-1;$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['conflict_policy']='invalid';$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][0]['definition']=dp_panel_surface_registry_definition('orders_surface','foreign');$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][0]['manifest']['resource']='tampered';$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][0]['owner']='Bad Owner';$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][0]['meta']=['password'=>'private'];$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][]=$invalid['layers']['orders_surface'][0];$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['layers']['orders_surface'][0]=array_reverse($invalid['layers']['orders_surface'][0],true);$t->throws(static fn()=>$registry->restore($invalid),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->contribute($one,'   '),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->contribute($one,str_repeat('x',101)),InvalidArgumentException::class);
	$beforeInvalid=$registry->revision();$t->throws(static fn()=>$registry->contribute($one,'owner',['not','a','map'],'replace'),InvalidArgumentException::class);$t->same($beforeInvalid,$registry->revision());

	$layers=dp_panel_surface_registry_integration_fixture('layered')['registry'];
	for($index=0;$index<63;$index++){$layers->contribute(dp_panel_surface_registry_definition('layered','layer_'.$index),'plugin_'.$index,[],'replace');}
	$t->throws(static fn()=>$layers->contribute(dp_panel_surface_registry_definition('layered','overflow'),'overflow',[],'replace'),LengthException::class);
	$budget=dp_panel_surface_registry_integration_fixture('surface_0')['registry'];
	for($index=1;$index<512;$index++){$budget->register(dp_panel_surface_registry_definition('surface_'.$index));}
	$t->same(512,count($budget->names()));$t->throws(static fn()=>$budget->register(dp_panel_surface_registry_definition('surface_overflow')),LengthException::class);
})->tag('panel','data-surface','registry','plugins','security','checkpoint')->maxMillis(10000);

test('Panel instances facades contexts endpoints and platform manifests expose only explicitly secured DataSurfaces',static function(Context $t):void{
	$fixture=dp_panel_surface_registry_integration_fixture();$registry=$fixture['registry'];$panel=PanelInstance::make('operations');
	$t->isFalse($panel->hasDataSurfaces());$t->throws(static fn()=>$panel->dataSurfaces(),LogicException::class);$t->throws(static fn()=>$panel->dataSurfaceEndpoint(),LogicException::class);
	$t->isFalse(PanelConfig::hasDataSurfaces());$t->throws(static fn()=>PanelConfig::dataSurfaces(),LogicException::class);
	$panel->useDataSurfaces($registry);$t->isTrue($panel->hasDataSurfaces());$t->same($registry,$panel->dataSurfaces());$t->throws(static fn()=>$panel->useDataSurfaces($registry),LogicException::class);
	$configured=PanelInstance::make('configured',['data_surface_registry'=>$registry]);$t->same($registry,$configured->dataSurfaces());
	$t->throws(static fn()=>PanelInstance::make('invalid',['data_surface_registry'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid',['data_surface_registry'=>$registry,'data_surfaces'=>$registry]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid',['data_surfaces_replace'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid')->config('data_surfaces',[]),InvalidArgumentException::class);
	$stringConfigured=PanelInstance::make('string-configured')->config('data_surfaces',$registry);$t->same($registry,$stringConfigured->dataSurfaces());$t->throws(static fn()=>$stringConfigured->config('data_surfaces_replace',true),InvalidArgumentException::class);
	$configured->config(['data_surfaces'=>$registry,'data_surfaces_replace'=>true]);$t->same('replaced',$configured->dataSurfaceManifest()['attachment']['lifecycle']['operation']);
	$scoped=$t->nonPublic($panel)->invoke('within',static fn():array=>[PanelConfig::hasDataSurfaces(),PanelConfig::dataSurfaces()]);$t->same([true,$registry],$scoped);$t->isFalse(PanelConfig::hasDataSurfaces());
	$manifest=$panel->dataSurfaceManifest();$t->isTrue($manifest['attachment']['configured']);$t->same(1,$manifest['count']);
	$root=PanelManifest::from($panel)->toArray();$t->same(1,$root['data_surfaces']['count']);$t->isTrue($root['capabilities']['data_surfaces']['configured']);$t->same(1,$root['capabilities']['data_surfaces']['definitions']);
	$t->same(1,$panel->describe()['data_surfaces']['count']);$t->isTrue($panel->platformManifest()['data_surfaces']['attachment']['configured']);
	$json=json_encode($root,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$t->notContains(str_repeat('S',32),$json);
	$intent=$registry->issue('orders_surface',$fixture['context']);$response=$panel->dataSurfaceEndpoint()->handle(['intent'=>$intent->token()],'operations',['tenant_id'=>'north','principal_id'=>'operator','correlation_id'=>'corr']);
	$t->same(200,$response['status']);$t->same('panel_data_surface_window',$response['body']['type']);$t->instanceOf(PanelDataSurfaceEndpoint::class,$panel->dataSurfaceEndpoint());

	$platform=PanelPlatform::make()->register('data_surfaces.registry',$registry);$domain=$platform->manifest()->domain('data_surfaces');$t->isTrue($domain['available']);$t->isTrue($domain['configured']);$t->isTrue($domain['ready']);$t->same($registry,$platform->dataSurfaces());
	$checkpoint=$platform->checkpoint();$platform->registerDataSurface(dp_panel_surface_registry_definition('temporary'));$t->isTrue($registry->has('temporary'));$platform->restore($checkpoint);$t->isFalse($registry->has('temporary'));
	$panel->replaceDataSurfaces($registry);$panel->withoutDataSurfaces();$t->isFalse($panel->hasDataSurfaces());$t->same('detached',$panel->dataSurfaceManifest()['attachment']['lifecycle']['operation']);

	Panel::flush();try{Panel::useDataSurfaces($registry);$t->same($registry,Panel::dataSurfaces());$t->same(1,Panel::dataSurfaceManifest()['count']);$t->instanceOf(PanelDataSurfaceEndpoint::class,Panel::dataSurfaceEndpoint());Panel::registerDataSurface(dp_panel_surface_registry_definition('facade'));$t->isTrue($registry->has('facade'));Panel::withoutDataSurfaces();$t->throws(static fn()=>Panel::dataSurfaces(),LogicException::class);}finally{Panel::flush();}
})->tag('panel','data-surface','instance','platform','manifest','endpoint','facade')->maxMillis(5000);

test('DataSurface plugin registration boot unload and replacement are atomic across in-place registry mutation',static function(Context $t):void{
	$fixture=dp_panel_surface_registry_integration_fixture();$registry=$fixture['registry'];$panel=PanelInstance::make('operations')->useDataSurfaces($registry);
	$failed=dp_panel_surface_registry_definition('failed_surface');
	$registerFailure=new class($failed)implements PanelPlugin{public function __construct(private PanelDataSurfaceDefinition $definition){}public function id():string{return'data-surface-register-failure';}public function register(PanelInstance $panel):void{$panel->registerDataSurface($this->definition);throw new RuntimeException('register failed');}public function boot(PanelInstance $panel):void{}};
	$t->throws(static fn()=>$panel->plugin($registerFailure),RuntimeException::class);$t->isFalse($registry->has('failed_surface'));

	$booted=dp_panel_surface_registry_definition('boot_failed_surface');
	$bootFailure=new class($booted)implements PanelPlugin{public function __construct(private PanelDataSurfaceDefinition $definition){}public function id():string{return'data-surface-boot-failure';}public function register(PanelInstance $panel):void{}public function boot(PanelInstance $panel):void{$panel->registerDataSurface($this->definition);throw new RuntimeException('boot failed');}};
	$panel->plugin($bootFailure);$t->throws(static fn()=>$panel->bootPlugins(),RuntimeException::class);$t->isFalse($registry->has('boot_failed_surface'));
	$panel->unloadPlugin('data-surface-boot-failure');

	$installed=dp_panel_surface_registry_definition('installed_surface');
	$plugin=new class($installed)implements PanelPlugin{public function __construct(private PanelDataSurfaceDefinition $definition){}public function id():string{return'data-surface-install';}public function register(PanelInstance $panel):void{$panel->registerDataSurface($this->definition,false,['release'=>'one']);}public function boot(PanelInstance $panel):void{}};
	$panel->plugin($plugin)->bootPlugins();$t->isTrue($registry->has('installed_surface'));$t->same('data-surface-install',array_values(array_filter($registry->provenance(),static fn(array $row):bool=>$row['id']==='installed_surface'))[0]['owner']);
	$panel->unloadPlugin('data-surface-install');$t->isFalse($registry->has('installed_surface'));
})->tag('panel','data-surface','plugins','rollback','hot-reload')->maxMillis(5000);
