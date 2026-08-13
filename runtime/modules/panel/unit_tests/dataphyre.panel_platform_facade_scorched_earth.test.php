<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\AutomationAction;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRegistry;
use Dataphyre\Panel\PanelExtensionDescriptor;
use Dataphyre\Panel\PanelFilesystemNotificationAdapter;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelMigrationBatch;
use Dataphyre\Panel\PanelMigrationContext;
use Dataphyre\Panel\PanelMigrationDefinition;
use Dataphyre\Panel\PanelMigrationVersion;
use Dataphyre\Panel\PanelOperationExecution;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationQueue;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationRunner;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Panel\PanelOperationStore;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Panel\PanelQueuedOperationRuntimeGraph;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelSecurityPolicy;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel platform container contracts')
	->contract('panel.platform-container', 1)
	->layer('contract')
	->risk('critical')
	->watches('module:panel', 'framework:platform')
	->through('configuration', 'service-container', 'capability-manifest', 'domain-facades')
	->tag('panel', 'platform', 'container')
	->group('panel-platform-contract');

/** @return array<string,mixed> */
function dp_panel_platform_config(string $root,array $replace=[]):array{return array_replace_recursive(['state_root'=>$root,'authentication'=>['encryption_key'=>str_repeat('E',32),'pepper'=>str_repeat('P',32),'challenge_key'=>str_repeat('C',32)],'media'=>['signing_key'=>str_repeat('M',32)]],$replace);}

final class DpPanelPlatformThrowingOperationGraph implements PanelOperationRunner,PanelQueuedOperationRuntimeGraph {
	public function __construct(private readonly PanelOperationHandlerRegistry $handlers,private readonly PanelOperationQueue $queue){}
	public function store():PanelOperationStore{throw new RuntimeException('deliberate graph identity failure');}
	public function handlers():PanelOperationHandlerRegistry{return $this->handlers;}
	public function queue():PanelOperationQueue{return $this->queue;}
	public function submit(string $type,string $name='operation',mixed $payload=[],array $options=[]):PanelOperationRecord{throw new LogicException('not exercised by the graph-validation contract');}
	public function run(string $id):PanelOperationRecord{throw new LogicException('not exercised by the graph-validation contract');}
	public function work(?string $queue=null,int $maxJobs=1,string $worker='local'):array{throw new LogicException('not exercised by the graph-validation contract');}
}

test('panel platform owns named values lazy factories singleton resolution and cycle protection',static function(Context $t):void{
	$platform=PanelPlatform::make()->register('custom.value',['ok'=>true]);$calls=0;$platform->factory('custom.singleton',static function()use(&$calls):stdClass{$calls++;return new stdClass();})->factory('custom.transient',static function()use(&$calls):stdClass{$calls++;return new stdClass();},false);
	$t->same(['ok'=>true],$platform->get('CUSTOM value'));$first=$platform->get('custom.singleton');$t->same($first,$platform->get('custom.singleton'));$t->same(1,$calls);
	$t->notSame($platform->get('custom.transient'),$platform->get('custom.transient'));$t->same(3,$calls);
	$t->throws(static fn()=>$platform->register('custom.value',1),LogicException::class);$t->throws(static fn()=>$platform->get('missing'),OutOfBoundsException::class);$t->throws(static fn()=>$platform->service('custom.value',stdClass::class),UnexpectedValueException::class);
	$platform->factory('cycle.a',static fn(PanelPlatform $p)=>$p->get('cycle.b'))->factory('cycle.b',static fn(PanelPlatform $p)=>$p->get('cycle.a'));$t->throws(static fn()=>$platform->get('cycle.a'),LogicException::class);
	$platform->forget('custom.value');$t->isFalse($platform->has('custom.value'));
})->tag('panel','platform','services','factories')->maxMillis(1000);

test('singleton factory resolution is an observable reversible platform mutation',static function(Context $t):void{
	$platform=PanelPlatform::make();
	$singletonCalls=0;$transientCalls=0;$failureCalls=0;
	$platform
		->factory('custom.singleton',static function()use(&$singletonCalls):stdClass{$singletonCalls++;return new stdClass();})
		->factory('custom.transient',static function()use(&$transientCalls):stdClass{$transientCalls++;return new stdClass();},false)
		->factory('custom.failure',static function()use(&$failureCalls):never{$failureCalls++;throw new RuntimeException('factory failed');});
	$checkpoint=$platform->checkpoint();$initialRevision=$platform->revision();

	$singleton=$platform->get('custom.singleton');
	$t->same($initialRevision+1,$platform->revision());
	$t->same('factory.resolved',$platform->lifecycle()['operation']);
	$t->same('custom.singleton',$platform->lifecycle()['service']);
	$t->same($singleton,$platform->get('custom.singleton'));
	$t->same($initialRevision+1,$platform->revision());
	$t->same(1,$singletonCalls);

	$t->notSame($platform->get('custom.transient'),$platform->get('custom.transient'));
	$t->same(2,$transientCalls);
	$t->same($initialRevision+1,$platform->revision());
	$t->throws(static fn()=>$platform->get('custom.failure'),RuntimeException::class);
	$t->same(1,$failureCalls);
	$t->same($initialRevision+1,$platform->revision());

	$platform->restore($checkpoint);
	$t->same($initialRevision,$platform->revision());
	$t->same('factory.registered',$platform->lifecycle()['operation']);
	$t->same('custom.failure',$platform->lifecycle()['service']);
	$t->notSame($singleton,$platform->get('custom.singleton'));
	$t->same($initialRevision+1,$platform->revision());
	$t->same(2,$singletonCalls);
})->tag('panel','platform','services','factories','lifecycle','transactions')->maxMillis(1000);

test('panel platform defaults fail closed for missing roots and sensitive key material',static function(Context $t):void{
	$t->throws(static fn()=>PanelPlatform::defaults([]),InvalidArgumentException::class);
	$root=$t->tempDirectory('panel-platform-missing-auth');$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$root]),InvalidArgumentException::class);
	$root2=$t->tempDirectory('panel-platform-missing-media');$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$root2,'authentication'=>false]),InvalidArgumentException::class);
	$root3=$t->tempDirectory('panel-platform-short-key');$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$root3,'authentication'=>['encryption_key'=>'short','pepper'=>str_repeat('p',32)],'media'=>false]),InvalidArgumentException::class);
	$root4=$t->tempDirectory('panel-platform-invalid-domain');$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$root4,'operations'=>'yes','authentication'=>false,'media'=>false]),InvalidArgumentException::class);
})->tag('panel','platform','defaults','security')->maxMillis(2000);

test('panel platform defaults assemble every local domain without global state',static function(Context $t):void{
	$root=$t->tempDirectory('panel-platform-full');$platform=PanelPlatform::defaults(dp_panel_platform_config($root));
	$t->instanceOf(PanelAuthenticationManager::class,$platform->authentication());$t->instanceOf(PanelFilesystemNotificationAdapter::class,$platform->notificationAdapter());$t->instanceOf(PanelMediaManager::class,$platform->media());$t->instanceOf(PanelPlatformController::class,$platform->controller());
	$t->same(PanelPlatformController::class,$platform->controller()::class);$t->same('Dataphyre\\Panel\\PanelPlatformTemplate',$platform->templateClass());
	$t->same($platform->get('automation.executor'),$platform->automationExecutor());$t->same($platform->get('localization.runtime'),$platform->localization());$t->same($platform->get('preferences.store'),$platform->preferenceStore());
	$t->same($platform->get('collaboration.store'),$platform->collaborationStore());$t->same($platform->get('development.toolkit'),$platform->development());$t->same('Dataphyre\\Panel\\PanelNotificationInbox',$platform->notificationInbox()::class);
	foreach(['operations','workflows','automation','authentication','notifications','media']as$directory){$t->isTrue(is_dir($root.DIRECTORY_SEPARATOR.$directory));}
	$t->same(33,count($platform->names()));$t->same($platform->operationStore(),$platform->operationStore());
	$t->same('panel_workspace_preferences',$platform->preferences('user-1')->manifest()['type']);$t->same('panel_collaboration_manager',$platform->collaboration()->manifest()['type']);
})->tag('panel','platform','defaults','integration')->maxMillis(4000);

test('panel platform optional control planes expose typed facades and registration workflows',static function(Context $t):void{
	$initial=PanelMigrationDefinition::make(
		'platform.edge',
		'platform_probe',
		PanelMigrationVersion::make('0.0.0',0),
		PanelMigrationVersion::make('1.0.0',1),
		static fn(PanelMigrationContext $context):PanelMigrationBatch=>PanelMigrationBatch::complete($context->data())
	);
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-control-planes'),[
		'distributed_operations'=>[],
		'migrations'=>['definitions'=>[$initial]],
		'observability'=>[],
		'iam'=>['audit_key'=>str_repeat('I',32),'authorize'=>static fn():bool=>true],
		'studio'=>['authorization'=>static fn():bool=>true],
	]));

	$t->same($platform->get('distributed_operations.store'),$platform->distributedOperationStore());
	$t->same($platform->get('distributed_operations.handlers'),$platform->distributedOperationHandlers());
	$t->same($platform->get('distributed_operations.runner'),$platform->distributedOperationRunner());
	$t->same($platform->get('distributed_operations.control'),$platform->distributedOperationControl());
	$platform->registerDistributedOperationHandler('platform_probe',static fn(array $payload):array=>$payload);
	$t->isTrue($platform->distributedOperationHandlers()->has('platform_probe'));

	$t->same($platform->get('migrations.store'),$platform->migrationStore());
	$t->same($platform->get('migrations.registry'),$platform->migrationRegistry());
	$t->same($platform->get('migrations.runner'),$platform->migrationRunner());
	$replacement=PanelMigrationDefinition::make(
		'platform.edge',
		'platform_probe',
		PanelMigrationVersion::make('0.0.0',0),
		PanelMigrationVersion::make('1.0.0',1),
		static fn(PanelMigrationContext $context):PanelMigrationBatch=>PanelMigrationBatch::complete($context->data())
	);
	$platform->registerMigration($replacement,true);
	$t->same(['platform.edge'],$platform->migrationPlan('platform_probe',null,PanelMigrationVersion::make('1.0.0',1),'2026-07-14T12:00:00Z')->migrationIds());

	$t->same($platform->get('observability.runtime'),$platform->observability());
	$t->same($platform->get('observability.exporter'),$platform->telemetryExporter());
	$t->same($platform->get('observability.hub'),$platform->telemetry());
	$t->same($platform->get('observability.bridge'),$platform->telemetryBridge());
	$t->same($platform->get('iam.store'),$platform->iamStore());
	$t->same($platform->get('iam.manager'),$platform->iam());
	$t->same($platform->get('studio.store'),$platform->studioStore());
	$t->same($platform->get('studio.compiler'),$platform->studioCompiler());
	$t->same($platform->get('studio.registry'),$platform->studioRegistry());
	$t->same($platform->get('studio.materializer'),$platform->studioMaterializer());
	$t->same($platform->get('studio.manager'),$platform->studio());
	$studioDocument=PanelStudioDocument::make('platform','platform-editor','Platform editor');
	$studioDefinition=PanelStudioDefinition::from(['kind'=>'page','key'=>'platform_editor','properties'=>['label'=>'Platform editor'],'children'=>[]]);
	$studioSession=$platform->openStudioEditor($studioDocument,'editor',$studioDefinition);
	$t->same('platform_editor',$studioSession->selectedPath());
	$resumedStudioSession=$platform->resumeStudioEditor($studioDocument,'editor',$studioSession->checkpoint());
	$t->same($studioSession->definition()->hash(),$resumedStudioSession->definition()->hash());
	$studioHtml=$platform->renderStudioEditor($resumedStudioSession,PanelStudioEditorOptions::make(['action_url'=>'/studio/edit','preview_url'=>'/studio/preview','csrf_token'=>str_repeat('C',32)]));
	$t->contains('data-dp-studio-editor',$studioHtml);

	$surfaceSources=(new PanelDataSourceRegistry())->register('orders',new PanelArrayDataSource([]));
	$surfaceSigner=new PanelDataSurfaceIntentSigner(['current'=>str_repeat('S',32)],'current');
	$platform->register('data_surfaces.registry',new PanelDataSurfaceRegistry($surfaceSources,$surfaceSigner,static fn():bool=>true));
	$surface=PanelDataSurfaceDefinition::make('platform_orders','orders','orders','table',PanelDataSurfaceProjection::make(['id']));
	$platform->registerDataSurface($surface);
	$t->same($surface,$platform->dataSurfaces()->get('platform_orders'));
})->tag('panel','platform','distributed-operations','migrations','observability','iam','studio','data-surfaces')->maxMillis(12000);

test('panel platform manifest reports actual class capabilities and configured services',static function(Context $t):void{
	$empty=PanelPlatform::make();$emptyManifest=$empty->manifest();$t->instanceOf(PanelPlatformManifest::class,$emptyManifest);$t->isTrue($emptyManifest->available('operations'));$t->isFalse($emptyManifest->configured('operations'));
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-manifest')));$manifest=$platform->manifest();$payload=$manifest->jsonSerialize();
	$t->same('panel_platform_manifest',$payload['type']);
	$t->same(['operations_os','operations','distributed_operations','migrations','observability','data','data_surfaces','realtime','workflows','automation','agent_workflows','authentication','iam','studio','notifications','media','localization','preferences','collaboration','packages','relations','security','development','extensions','platform'],array_keys($payload['domains']));
	$t->same(count($payload['domains']),$payload['counts']['domains']);
	$t->same(count(array_filter($payload['domains'],static fn(array $domain):bool=>$domain['available']===true)),$payload['counts']['available']);
	$t->same(count(array_filter($payload['domains'],static fn(array $domain):bool=>$domain['configured']===true)),$payload['counts']['configured']);
	$t->same(count(array_filter($payload['domains'],static fn(array $domain):bool=>$domain['ready']===true)),$payload['counts']['ready']);
	$t->same(count($payload['services']),$payload['counts']['services']);
	$t->isTrue($payload['domains']['authentication']['features']['totp']);$t->isTrue($payload['domains']['data']['features']['resource_bridge']);$t->isTrue($payload['domains']['realtime']['features']['endpoint']);$t->isTrue($payload['domains']['realtime']['features']['pdo_adapter']);$t->isTrue($payload['domains']['distributed_operations']['features']['pdo_store']);$t->isTrue($payload['domains']['distributed_operations']['features']['storage_error']);$t->isTrue($payload['domains']['migrations']['features']['pdo_store']);$t->isTrue($payload['domains']['migrations']['features']['storage_error']);
	foreach(['leased_command_fabric_store','pdo_command_fabric_store','command_fabric_subscriber_lease','command_fabric_storage_error','command_fabric_lease_error','command_fabric_conformance']as$feature){$t->isTrue($payload['domains']['operations_os']['features'][$feature]);}
	foreach(['runtime','pdo_store','storage_error','deferred_execution','workflow_job','job_resolver','callback_job_resolver','worker_context','operation_bridge']as$feature){$t->isTrue($payload['domains']['agent_workflows']['features'][$feature]);}
	$t->isTrue($payload['domains']['platform']['features']['controller']);$t->isTrue($payload['security']['global_mutable_state']===false);
})->tag('panel','platform','manifest','capabilities')->maxMillis(4000);

test('panel platform manifest fails closed when dependency graph validation throws',static function(Context $t):void{
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-manifest-graph-failure')));
	$platform->register('operations.runner',new DpPanelPlatformThrowingOperationGraph($platform->operationHandlers(),$platform->operationQueue()),true);
	$operations=$platform->manifest()->domain('operations');
	$t->type('array',$operations);
	$t->isFalse($operations['ready']??true);
	$t->same(['evaluated'=>true,'valid'=>false,'mismatches'=>['graph_validation_failed']],$operations['cohesion']??null);
})->tag('panel','platform','manifest','cohesion','fail-closed')->maxMillis(4000);

test('panel platform serialization never emits configured secrets or scalar service values',static function(Context $t):void{
	$root=$t->tempDirectory('panel-platform-redaction');$encryption=str_repeat('X',32);$pepper=str_repeat('Y',32);$signing=str_repeat('Z',32);$platform=PanelPlatform::defaults(['state_root'=>$root,'authentication'=>['encryption_key'=>$encryption,'pepper'=>$pepper],'media'=>['signing_key'=>$signing]])->register('custom.scalar_secret','DO-NOT-SERIALIZE');
	$json=json_encode($platform,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);foreach([$encryption,$pepper,$signing,'DO-NOT-SERIALIZE','encryption_key','signing_key']as$secret){$t->notContains($secret,$json);}
	$t->same('string',$platform->serviceDescriptors()['custom.scalar_secret']['type']);$t->same(false,$platform->manifest()->jsonSerialize()['security']['secret_values_serialized']);
})->tag('panel','platform','redaction','security')->maxMillis(4000);

test('panel platform ergonomic operation and data registries execute real work',static function(Context $t):void{
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-runtime')));
	$platform->registerOperationHandler('sum',static function(array $payload,PanelOperationExecution $execution):array{$sum=array_sum($payload['values']);$execution->progress(count($payload['values']),count($payload['values']),'Done',count($payload['values']),0);return['sum'=>$sum];});
	$record=$platform->operationRunner()->submit('sum','Sum',[ 'values'=>[2,3,5]],['id'=>'platform-sum','total'=>3]);$completed=$platform->operationRunner()->run($record->id());$t->same(PanelOperationStatus::COMPLETED,$completed->status());$t->same(10,$completed->result()['sum']);
	$platform->registerDataSource('people',new PanelArrayDataSource([['id'=>1,'name'=>'Ada'],['id'=>2,'name'=>'Grace']]));$result=$platform->dataSources()->get('people')->query(PanelDataQuery::make()->where('name','contains','ada'));$t->same(1,$result->items()[0]['id']);
})->tag('panel','platform','operations','data')->maxMillis(4000);

test('panel platform ergonomic workflow automation and extension registries retain definitions',static function(Context $t):void{
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-registries')));
	$workflow=WorkflowDefinition::make('orders')->state('draft',['draft'=>true])->state('done',['terminal'=>true])->initial('draft')->transition(WorkflowTransition::make('complete','draft','done'));$platform->registerWorkflow($workflow);$t->same($workflow,$platform->workflowEngine()->definition('orders'));
	$action=AutomationAction::make('ping')->handle(static fn():array=>['pong'=>true]);$platform->registerAutomation($action);$t->same($action,$platform->automationRegistry()->get('ping'));
	$extension=PanelExtensionDescriptor::make('audit-tools','1.0.0',['provides'=>['audit']]);$platform->registerExtension($extension);$t->isTrue($platform->extensions()->has('audit-tools'));
	$platform->onExtension('payload.decorate',static fn(array $payload):array=>$payload+['decorated'=>true]);$t->isTrue($platform->extensionRuntime()->dispatch('payload.decorate',[])['decorated']);
})->tag('panel','platform','workflows','automation','extensions')->maxMillis(4000);

test('panel platform exposes relation security development and UI factories',static function(Context $t):void{
	$platform=PanelPlatform::defaults(dp_panel_platform_config($t->tempDirectory('panel-platform-factories')));$adapter=$platform->arrayRelation([['id'=>'r1','name'=>'One']]);$t->instanceOf(PanelArrayRelationAdapter::class,$adapter);$workspace=$platform->relation('items','parent-1',$adapter);$t->instanceOf(PanelRelationWorkspace::class,$workspace);
	$context=$platform->securityContext('u1',['permissions'=>['orders.view']]);$t->instanceOf(PanelSecurityContext::class,$context);$t->isTrue($context->can('orders.view'));$policy=$platform->securityPolicy('orders.view')->permissions('orders.view');$t->instanceOf(PanelSecurityPolicy::class,$policy);$t->isTrue($policy->evaluate($context)->allowed());
	$inspection=$platform->inspect(['type'=>'demo','items'=>[]]);$t->same('panel_manifest_inspection',$inspection->jsonSerialize()['type']);
	$request=['method'=>'GET','user'=>['id'=>'u1','permissions'=>['operations.view','relations.view']]];$page=$platform->operationsPage([],$request);$t->same(200,$page->status());$t->contains('Operations center',$page->content());$t->same(200,$platform->relationsPage($workspace,[],$request)->status());
})->tag('panel','platform','relations','security','development','templates')->maxMillis(5000);

test('panel platform can explicitly disable sensitive domains while retaining truthful availability',static function(Context $t):void{
	$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-disabled'),'authentication'=>false,'media'=>false]);$manifest=$platform->manifest();
	$t->isFalse($platform->has('authentication.manager'));$t->isFalse($platform->has('media.manager'));$t->isTrue($manifest->available('authentication'));$t->isFalse($manifest->configured('authentication'));$t->isTrue($manifest->available('media'));$t->isFalse($manifest->configured('media'));
	$t->throws(static fn()=>$platform->authentication(),LogicException::class);$t->throws(static fn()=>$platform->media(),LogicException::class);$t->same(13,$manifest->jsonSerialize()['counts']['configured']);
})->tag('panel','platform','optional-domains','manifest')->maxMillis(4000);
