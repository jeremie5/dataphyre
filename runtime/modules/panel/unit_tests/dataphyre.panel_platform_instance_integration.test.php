<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\AutomationAction;
use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelInMemoryWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelMigrationBatch;
use Dataphyre\Panel\PanelMigrationContext;
use Dataphyre\Panel\PanelMigrationDefinition;
use Dataphyre\Panel\PanelMigrationRegistry;
use Dataphyre\Panel\PanelMigrationRunner;
use Dataphyre\Panel\PanelMigrationVersion;
use Dataphyre\Panel\PanelAtomicMigrationStore;
use Dataphyre\Panel\PanelAtomicLeasedOperationStore;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelFilesystemPreferenceStore;
use Dataphyre\Panel\PanelLeasedOperationRunner;
use Dataphyre\Panel\PanelLocalOperationQueue;
use Dataphyre\Panel\PanelLocalOneTimeChallengeAdapter;
use Dataphyre\Panel\PanelMemoryAuthenticationStore;
use Dataphyre\Panel\PanelMemoryIamStore;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryTelemetryExporter;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelSynchronousOperationRunner;
use Dataphyre\Panel\PanelStudioCompiler;
use Dataphyre\Panel\PanelTranslationCatalogueLoader;
use Dataphyre\Panel\PanelWorkspacePreferencesFactory;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelRegistry;
use Dataphyre\Panel\PanelSensitiveDataSanitizer;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel platform surface integration contracts')
	->contract('panel.platform-surface-integration', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel', 'framework:platform', 'framework:core')
	->through('surface-attachment', 'scoped-context', 'transactional-lifecycle', 'facade', 'first-party-pages')
	->tag('panel', 'platform', 'surface-integration')
	->group('panel-platform-contract');

/** @return array<string,mixed> */
function dp_panel_platform_instance_config(string $root, array $replace=[]): array {
	return array_replace_recursive([
		'state_root'=>$root,
		'authentication'=>[
			'encryption_key'=>str_repeat('E', 32),
			'pepper'=>str_repeat('P', 32),
			'challenge_key'=>str_repeat('C', 32),
		],
		'media'=>['signing_key'=>str_repeat('M', 32)],
	], $replace);
}

test('panel instances own platform state and expose it only inside their scoped context', static function(Context $t): void {
	$panel=PanelInstance::make('operations');
	$t->isFalse($panel->hasPlatform());
	$t->throws(static fn(): PanelPlatform=>$panel->platform(), LogicException::class);
	$t->throws(static fn(): array=>$panel->platformPages(), LogicException::class);
	$t->throws(static fn(): PanelInstance=>$panel->mountPlatformPages(), LogicException::class);
	$t->throws(static fn(): PanelPlatform=>PanelConfig::platform(), LogicException::class);

	$platform=PanelPlatform::make()->register('custom.marker', new stdClass());
	$t->same($panel, $panel->usePlatform($platform));
	$t->isTrue($panel->hasPlatform());
	$t->same($platform, $panel->platform());
	$t->same(1, $panel->platformState()['revision']);
	$t->same('attached', $panel->platformState()['lifecycle']['operation']);
	$t->throws(static fn(): array=>$panel->platformPages(), LogicException::class);

	$resolved=$t->nonPublic($panel)->invoke('within', static fn(): array=>[
		'present'=>PanelConfig::hasPlatform(),
		'platform'=>PanelConfig::platform(),
		'raw_config'=>array_key_exists('platform_config', PanelContext::all()),
	]);
	$t->isTrue($resolved['present']);
	$t->same($platform, $resolved['platform']);
	$t->isFalse($resolved['raw_config']);
	$t->isFalse(PanelConfig::hasPlatform());
})->tag('panel','platform','instance','context','isolation')->maxMillis(1000);

test('panel platform configuration and replacement are atomic explicit and redacted', static function(Context $t): void {
	$root=$t->tempDirectory('panel-platform-instance-config');
	$config=dp_panel_platform_instance_config($root);
	$panel=PanelInstance::make('secure', ['platform_config'=>$config, 'panel_label'=>'Secure']);
	$first=$panel->platform();
	$t->same(1, $panel->platformState()['revision']);
	$t->same(33, count($first->names()));

	$json=json_encode($panel->describe(), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	foreach([$root, str_repeat('E', 32), str_repeat('P', 32), str_repeat('M', 32), 'platform_config'] as $secret){
		$t->notContains($secret, $json);
	}
	$platformJson=json_encode($first, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$t->notContains($root, $platformJson);
	$t->isTrue($first->jsonSerialize()['metadata']['state_root_configured']);

	$t->throws(static fn(): PanelInstance=>$panel->usePlatform(PanelPlatform::make()), LogicException::class);
	$t->same($first, $panel->platform());
	$t->same(1, $panel->platformState()['revision']);
	$t->throws(static fn(): PanelInstance=>$panel->replacePlatform([]), InvalidArgumentException::class);
	$t->same($first, $panel->platform());
	$t->same(1, $panel->platformState()['revision']);

	$second=PanelPlatform::make()->register('custom.second', true);
	$panel->config(['platform_instance'=>$second, 'platform_replace'=>true]);
	$t->same($second, $panel->platform());
	$t->same(2, $panel->platformState()['revision']);
	$t->same('replaced', $panel->platformState()['lifecycle']['operation']);
	$t->throws(static fn(): PanelInstance=>$panel->config(['platform_instance'=>$first, 'platform_config'=>$config, 'platform_replace'=>true]), InvalidArgumentException::class);
	$t->same($second, $panel->platform());
	$t->throws(static fn(): PanelInstance=>$panel->config(['platform_replace'=>true]), InvalidArgumentException::class);

	$panel->withoutPlatform();
	$t->isFalse($panel->hasPlatform());
	$t->same(3, $panel->platformState()['revision']);
	$t->same('detached', $panel->platformState()['lifecycle']['operation']);
})->tag('panel','platform','instance','configuration','replacement','redaction')->maxMillis(5000);

test('platform manifests require complete type-correct services and never bless unresolved factories', static function(Context $t): void {
	$partial=PanelPlatform::make()->register('operations.store', new stdClass());
	$operations=$partial->manifest()->domain('operations');
	$t->isTrue($operations['configured']);
	$t->isFalse($operations['ready']);
	$t->isTrue(in_array('operations.store', $operations['invalid_services'], true));
	$t->isTrue(in_array('operations.runner', $operations['missing_services'], true));

	$lazy=PanelPlatform::make()->factory('data.registry', static fn(): PanelDataSourceRegistry=>new PanelDataSourceRegistry());
	$data=$lazy->manifest()->domain('data');
	$t->isTrue($data['configured']);
	$t->isFalse($data['ready']);
	$t->same(['data.registry'], $data['pending_services']);
	$lazy->get('data.registry');
	$t->isTrue($lazy->manifest()->ready('data'));

	$full=PanelPlatform::defaults(dp_panel_platform_instance_config($t->tempDirectory('panel-platform-instance-ready')));
	$platformManifest=$full->manifest();
	$t->isTrue($platformManifest->available('DATA'));
	$t->isTrue($platformManifest->configured('data'));
	$t->isFalse($platformManifest->available('missing domain'));
	$t->isFalse($platformManifest->configured('missing domain'));
	$manifest=$platformManifest->jsonSerialize();
	$t->same(15, $manifest['counts']['configured']);
	$t->same(15, $manifest['counts']['ready']);
	$t->isTrue($manifest['security']['readiness_requires_typed_services']);
	$t->isFalse($manifest['security']['unresolved_factories_ready']);
})->tag('panel','platform','manifest','readiness','factories')->maxMillis(5000);

test('platform lifecycle diagnostics expose changes and types without service values', static function(Context $t): void {
	$platform=PanelPlatform::make();
	$t->same(0, $platform->revision());
	$platform->register('custom.secret', 'DO-NOT-SERIALIZE');
	$t->same(1, $platform->revision());
	$t->same('service.registered', $platform->lifecycle()['operation']);
	$t->throws(static fn(): PanelPlatform=>$platform->register('custom.secret', 'other'), LogicException::class);
	$t->same(1, $platform->revision());
	$platform->register('custom.secret', 'replacement', true);
	$t->same(2, $platform->revision());
	$t->same('service.replaced', $platform->lifecycle()['operation']);
	$platform->forget('missing');
	$t->same(2, $platform->revision());
	$platform->forget('custom.secret');
	$t->same(3, $platform->revision());

	$platform->register('custom.secret', 'DO-NOT-SERIALIZE');
	$json=json_encode($platform->diagnostics(), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$t->notContains('DO-NOT-SERIALIZE', $json);
	$t->contains('custom.secret', $json);
	$t->contains('string', $json);
})->tag('panel','platform','lifecycle','diagnostics','redaction')->maxMillis(1000);

test('surface data-source facades fail closed and manifests never resolve factories or leak network configuration',static function(Context $t):void{
	$lazyCalls=0;
	$lazy=PanelPlatform::make()->factory('data.registry',static function()use(&$lazyCalls):PanelDataSourceRegistry{$lazyCalls++;return new PanelDataSourceRegistry();});
	$panel=PanelInstance::make('lazy-data')->usePlatform($lazy);
	$t->isFalse($panel->hasDataSources());
	$t->throws(static fn():PanelDataSourceRegistry=>$panel->dataSources(),LogicException::class);
	$lazyManifest=$panel->dataSourceManifest();
	$t->same('unresolved_factory',$lazyManifest['attachment']['service_state']);
	$t->isFalse($lazyManifest['attachment']['configured']);
	$t->same(0,$lazyCalls);

	$capabilityCounter=(object)['calls'=>0];
	$source=new class($capabilityCounter) implements PanelDataSource {
		public function __construct(private stdClass $counter){}
		public function query(PanelDataQuery $query):PanelDataResult{throw new LogicException('query must not run');}
		public function find(string|int $id,?PanelDataQuery $scope=null):mixed{throw new LogicException('find must not run');}
		public function capabilities():array{$this->counter->calls++;return['filters'=>true,'endpoint'=>'https://private.example.test/v1','cancellable_transport'=>true];}
	};
	$registry=new PanelDataSourceRegistry();
	$registry->register('remote',$source)->contribute('remote',$source,'plugin.remote',['provider'=>'fixture','endpoint'=>'https://private.example.test/v1','headers'=>['X-Private'=>'no'],'transport'=>'PrivateTransport'],'replace');
	$t->same(2,$capabilityCounter->calls);
	$ready=PanelInstance::make('ready-data')->usePlatform(PanelPlatform::make()->register('data.registry',$registry));
	$t->isTrue($ready->hasDataSources());
	$t->same($registry,$ready->dataSources());
	$manifest=$ready->dataSourceManifest();
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$manifest['sources']['remote']['capabilities']['endpoint']);
	$t->isTrue($manifest['sources']['remote']['capabilities']['cancellable_transport']);
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$manifest['sources']['remote']['meta']['endpoint']);
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$manifest['sources']['remote']['meta']['headers']);
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$manifest['sources']['remote']['meta']['transport']);
	$t->notContains('private.example.test',json_encode($manifest,JSON_THROW_ON_ERROR));
	$t->same(2,$capabilityCounter->calls);

	$scoped=$t->nonPublic($ready)->invoke('within',static fn():array=>['has'=>PanelConfig::hasDataSources(),'registry'=>PanelConfig::dataSources()]);
	$t->isTrue($scoped['has']);$t->same($registry,$scoped['registry']);
	$t->isFalse(PanelConfig::hasDataSources());
	$t->throws(static fn():PanelDataSourceRegistry=>PanelConfig::dataSources(),LogicException::class);
	$dataDomain=$ready->platform()->manifest()->domain('data');
	foreach(['http_adapter','http_definition','http_capability_pin','http_scope','http_scope_mapper','http_transport','http_cursor','http_runtime']as$feature){$t->isTrue($dataDomain['features'][$feature]??false);}
})->tag('panel','platform','data-source','manifest','redaction','factory')->maxMillis(2000);

test('platform checkpoints and plugin transactions restore container and nested data registries',static function(Context $t):void{
	$registry=new PanelDataSourceRegistry();
	$platform=PanelPlatform::make()
		->register('data.registry',$registry)
		->factory('custom.lazy',static fn():string=>'resolved');
	$checkpoint=$platform->checkpoint();
	$platform->register('custom.temporary','private');
	$registry->register('temporary',new PanelArrayDataSource([['id'=>1]],['tenant_field'=>null]));
	$t->same('resolved',$platform->get('custom.lazy'));
	$platform->restore($checkpoint);
	$t->isFalse($platform->has('custom.temporary'));
	$t->isFalse($platform->dataSources()->has('temporary'));
	$t->same('resolved',$platform->get('custom.lazy'));
	$t->isTrue($platform->diagnostics()['transactions']['nested_data_registry_checkpoint']);
	$t->isTrue($platform->diagnostics()['transactions']['checkpointable_nested_services']);
	$t->isFalse($platform->diagnostics()['transactions']['arbitrary_service_object_mutations_rolled_back']);
	$t->throws(static fn()=>$platform->restore([]),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['nested']['data.registry']['type']='wrong';
	$t->throws(static fn()=>$platform->restore($invalid),InvalidArgumentException::class);
	$missingNested=$checkpoint;unset($missingNested['nested']['data.registry']);$t->throws(static fn()=>$platform->restore($missingNested),InvalidArgumentException::class);
	$badService=$checkpoint;$badService['services']['Bad Service']=true;$t->throws(static fn()=>$platform->restore($badService),InvalidArgumentException::class);
	$badFactory=$checkpoint;$badFactory['factories']['bad.factory']=['factory'=>'not-a-closure','singleton'=>true];$t->throws(static fn()=>$platform->restore($badFactory),InvalidArgumentException::class);
	$overlap=$checkpoint;$overlap['factories']['data.registry']=['factory'=>static fn()=>null,'singleton'=>true];$t->throws(static fn()=>$platform->restore($overlap),InvalidArgumentException::class);
	$badMutation=$checkpoint;$badMutation['last_mutation']['revision']=$checkpoint['revision']+1;$t->throws(static fn()=>$platform->restore($badMutation),InvalidArgumentException::class);
	$platform->restore($checkpoint);

	$panel=PanelInstance::make('platform-plugin-rollback')->usePlatform($platform);
	$plugin=new class implements PanelPlugin {
		public function id():string{return'platform-mutation-failure';}
		public function register(PanelInstance $panel):void{
			$panel->platform()->register('plugin.residue','private');
			$panel->platform()->dataSources()->register('plugin_residue',new PanelArrayDataSource([['id'=>1]],['tenant_field'=>null]));
			$panel->registerWidgetRuntimeAdapter(new PanelInMemoryWidgetRuntimeAdapter('/widgets',str_repeat('W',32)),'plugin_residue','platform-mutation-failure');
			throw new RuntimeException('platform registration failed');
		}
		public function boot(PanelInstance $panel):void{}
	};
	$t->throws(static fn()=>$panel->plugin($plugin),RuntimeException::class);
	$t->isFalse($platform->has('plugin.residue'));
	$t->isFalse($platform->dataSources()->has('plugin_residue'));
	$t->isFalse($panel->widgetRuntime()->has('plugin_residue'));
	$t->same([],$panel->pluginIds());

	$bootFailure=new class implements PanelPlugin {
		public function id():string{return'platform-boot-failure';}
		public function register(PanelInstance $panel):void{}
		public function boot(PanelInstance $panel):void{
			$panel->platform()->register('plugin.boot_residue','private');
			$panel->platform()->dataSources()->register('plugin_boot_residue',new PanelArrayDataSource([['id'=>2]],['tenant_field'=>null]));
			throw new RuntimeException('platform boot failed');
		}
	};
	$panel->plugin($bootFailure);
	$t->throws(static fn()=>$panel->bootPlugins(),RuntimeException::class);
	$t->isFalse($platform->has('plugin.boot_residue'));
	$t->isFalse($platform->dataSources()->has('plugin_boot_residue'));
})->tag('panel','platform','plugins','transaction','rollback','data-source')->maxMillis(3000);

test('all advertised mutable platform contribution helpers roll back register and boot failures exactly',static function(Context $t):void{
	$platform=PanelPlatform::defaults(dp_panel_platform_instance_config($t->tempDirectory('panel-platform-nested-transactions'),[
		'authentication'=>false,'media'=>false,'distributed_operations'=>[],'migrations'=>[],
	]));
	$workflow=WorkflowDefinition::make('plugin_workflow')->state('draft',['draft'=>true])->state('done',['terminal'=>true])->initial('draft')->transition(WorkflowTransition::make('complete','draft','done'));
	$migration=PanelMigrationDefinition::make('plugin.edge','plugin',PanelMigrationVersion::make('0.0.0',0),PanelMigrationVersion::make('1.0.0',1),static fn(PanelMigrationContext $context):PanelMigrationBatch=>PanelMigrationBatch::complete($context->data()));
	$automation=AutomationAction::make('plugin_action')->handle(static fn():array=>['ok'=>true]);
	$panel=PanelInstance::make('platform-nested-transactions')->usePlatform($platform);
	$registerFailure=new class($workflow,$migration,$automation) implements PanelPlugin {
		public function __construct(private WorkflowDefinition $workflow,private PanelMigrationDefinition $migration,private AutomationAction $automation){}
		public function id():string{return'nested-register-failure';}
		public function register(PanelInstance $panel):void{$platform=$panel->platform();$platform->registerOperationHandler('plugin.operation',static fn():null=>null)->registerDistributedOperationHandler('plugin.distributed',static fn():null=>null)->registerMigration($this->migration)->registerWorkflow($this->workflow)->registerAutomation($this->automation);throw new RuntimeException('nested register failed');}
		public function boot(PanelInstance $panel):void{}
	};
	$t->throws(static fn():PanelInstance=>$panel->plugin($registerFailure),RuntimeException::class);
	foreach([[$platform->operationHandlers(),'plugin.operation'],[$platform->distributedOperationHandlers(),'plugin.distributed']]as[$registry,$type]){$t->isFalse($registry->has($type));$t->same(0,$registry->revision());}
	$t->isFalse($platform->migrationRegistry()->has('plugin.edge'));$t->isNull($platform->workflowEngine()->definition('plugin_workflow'));$t->isFalse($platform->automationRegistry()->has('plugin_action'));

	$bootWorkflow=WorkflowDefinition::make('boot_workflow')->state('draft')->state('done',['terminal'=>true])->transition(WorkflowTransition::make('complete','draft','done'));
	$bootMigration=PanelMigrationDefinition::make('boot.edge','boot',PanelMigrationVersion::make('0.0.0',0),PanelMigrationVersion::make('1.0.0',1),static fn(PanelMigrationContext $context):PanelMigrationBatch=>PanelMigrationBatch::complete($context->data()));
	$bootAutomation=AutomationAction::make('boot_action')->handle(static fn():bool=>true);
	$bootFailure=new class($bootWorkflow,$bootMigration,$bootAutomation) implements PanelPlugin {
		public function __construct(private WorkflowDefinition $workflow,private PanelMigrationDefinition $migration,private AutomationAction $automation){}
		public function id():string{return'nested-boot-failure';}
		public function register(PanelInstance $panel):void{}
		public function boot(PanelInstance $panel):void{$platform=$panel->platform();$platform->registerOperationHandler('boot.operation',static fn():null=>null)->registerDistributedOperationHandler('boot.distributed',static fn():null=>null)->registerMigration($this->migration)->registerWorkflow($this->workflow)->registerAutomation($this->automation);throw new RuntimeException('nested boot failed');}
	};
	$panel->plugin($bootFailure);$t->throws(static fn():PanelInstance=>$panel->bootPlugins(),RuntimeException::class);
	$t->isFalse($platform->operationHandlers()->has('boot.operation'));$t->isFalse($platform->distributedOperationHandlers()->has('boot.distributed'));$t->isFalse($platform->migrationRegistry()->has('boot.edge'));$t->isNull($platform->workflowEngine()->definition('boot_workflow'));$t->isFalse($platform->automationRegistry()->has('boot_action'));

	$checkpoint=$platform->checkpoint();$originalHandlers=$platform->operationHandlers();$originalHandlers->register('direct.residue',static fn():null=>null);$platform->register('operations.handlers',new PanelOperationHandlerRegistry(),true);$platform->restore($checkpoint);
	$t->same($originalHandlers,$platform->operationHandlers());$t->isFalse($originalHandlers->has('direct.residue'));
	$handlerCheckpoint=$originalHandlers->checkpoint();$tampered=$handlerCheckpoint;$tampered['revision']++;$t->throws(static fn()=>$originalHandlers->restore($tampered),InvalidArgumentException::class);$t->throws(static fn()=>(new PanelOperationHandlerRegistry())->restore($handlerCheckpoint),InvalidArgumentException::class);
})->tag('panel','platform','plugins','transaction','rollback','operations','migrations','workflows','automation')->maxMillis(5000);

test('composite platform domains reject split dependency graphs and unsafe contributions',static function(Context $t):void{
	$root=$t->tempDirectory('panel-platform-split-graphs');$platform=PanelPlatform::defaults(dp_panel_platform_instance_config($root,['media'=>false,'distributed_operations'=>[],'migrations'=>[],'observability'=>[],'iam'=>['audit_key'=>str_repeat('I',32),'authorize'=>static fn():bool=>true],'studio'=>['authorization'=>static fn():bool=>true]]));
	$otherOperationStore=new PanelFilesystemOperationStore($root.'/other-operations');$otherOperationHandlers=new PanelOperationHandlerRegistry();$otherQueue=new PanelLocalOperationQueue($otherOperationStore);
	$platform->register('operations.runner',new PanelSynchronousOperationRunner($otherOperationStore,$otherOperationHandlers,$otherQueue),true);
	$otherDistributedStore=new PanelAtomicLeasedOperationStore($root.'/other-distributed');$platform->register('distributed_operations.runner',new PanelLeasedOperationRunner($otherDistributedStore,new PanelOperationHandlerRegistry()),true);
	$platform->register('migrations.runner',new PanelMigrationRunner(new PanelAtomicMigrationStore($root.'/other-migrations'),new PanelMigrationRegistry()),true);
	$platform->register('workflows.engine',new WorkflowEngine(new InMemoryWorkflowStore()),true);
	$platform->register('automation.executor',new AutomationExecutor(new AutomationRegistry(),new InMemoryAutomationStore()),true);
	$platform->register('observability.exporter',new PanelInMemoryTelemetryExporter(),true);
	$platform->register('authentication.store',new PanelMemoryAuthenticationStore(),true)->register('authentication.challenge_adapter',new PanelLocalOneTimeChallengeAdapter(str_repeat('Q',32)),true);
	$platform->register('iam.store',new PanelMemoryIamStore(),true);
	$platform->register('studio.compiler',new PanelStudioCompiler(),true);
	$platform->register('localization.loader',new PanelTranslationCatalogueLoader(),true);
	$platform->register('preferences.workspace_factory',new PanelWorkspacePreferencesFactory(new PanelFilesystemPreferenceStore($root.'/other-preferences')),true);
	$platform->register('collaboration.store',new PanelInMemoryCollaborationStore(),true);
	$manifest=$platform->manifest();
	$expected=[
		'operations'=>['operations.runner.store','operations.runner.handlers','operations.runner.queue'],
		'distributed_operations'=>['distributed_operations.runner.store','distributed_operations.runner.handlers'],
		'migrations'=>['migrations.runner.store','migrations.runner.registry'],
		'workflows'=>['workflows.engine.store'],
		'automation'=>['automation.executor.registry','automation.executor.store'],
		'observability'=>['observability.runtime.exporter','observability.hub.exporter'],
		'authentication'=>['authentication.manager.store','authentication.manager.challenge_adapter'],
		'iam'=>['iam.manager.store'],
		'studio'=>['studio.manager.compiler'],
		'localization'=>['localization.runtime.loader'],
		'preferences'=>['preferences.workspace_factory.store'],
		'collaboration'=>['collaboration.manager.store'],
	];
	foreach($expected as$domain=>$mismatches){$state=$manifest->domain($domain);$t->isFalse($state['ready']);$t->isTrue($state['cohesion']['evaluated']);$t->isFalse($state['cohesion']['valid']);$t->same($mismatches,$state['cohesion']['mismatches']);}
	$t->throws(static fn()=>$platform->registerOperationHandler('unsafe',static fn():null=>null),LogicException::class);
	$t->throws(static fn()=>$platform->registerDistributedOperationHandler('unsafe',static fn():null=>null),LogicException::class);
	$t->throws(static fn()=>$platform->registerMigration(PanelMigrationDefinition::make('unsafe.edge','unsafe',PanelMigrationVersion::make('0.0.0',0),PanelMigrationVersion::make('1.0.0',1),static fn(PanelMigrationContext $context):PanelMigrationBatch=>PanelMigrationBatch::complete($context->data()))),LogicException::class);
	$t->throws(static fn()=>$platform->registerWorkflow(WorkflowDefinition::make('unsafe')->state('start')->state('done',['terminal'=>true])->transition(WorkflowTransition::make('finish','start','done'))),LogicException::class);
	$t->throws(static fn()=>$platform->registerAutomation(AutomationAction::make('unsafe')->handle(static fn():bool=>true)),LogicException::class);
	$t->isFalse($platform->operationHandlers()->has('unsafe'));$t->isFalse($platform->distributedOperationHandlers()->has('unsafe'));$t->isFalse($platform->migrationRegistry()->has('unsafe.edge'));$t->isNull($platform->workflowEngine()->definition('unsafe'));$t->isFalse($platform->automationRegistry()->has('unsafe'));
})->tag('panel','platform','cohesion','split-brain','operations','migrations','workflows','automation')->maxMillis(5000);

test('normal panel facade registry and panel manifests retain platform attachment state', static function(Context $t): void {
	Panel::flush();
	try{
		$platform=PanelPlatform::defaults(dp_panel_platform_instance_config($t->tempDirectory('panel-platform-facade'), [
			'authentication'=>false,
			'media'=>false,
		]));
		$surface=Panel::usePlatform($platform);
		$t->same($platform, Panel::platform());
		$t->same($platform, $surface->platform());
		$t->instanceOf(PanelPlatformController::class, Panel::platformController());
		$t->same($platform->dataSources(),Panel::dataSources());
		$t->same($surface,Panel::registerDataSource('facade_orders',new PanelArrayDataSource([['id'=>1]],['tenant_field'=>null])));
		$t->same(1,Panel::dataSourceManifest()['count']);

		$description=Panel::describe();
		$t->isTrue($description['platform']['attachment']['configured']);
		$t->same(13, $description['platform']['counts']['ready']);
		$manifest=Panel::panelManifest();
		$t->isTrue($manifest['platform']['attachment']['configured']);
		$t->same(1,$manifest['data_sources']['count']);
		$t->isTrue($manifest['capabilities']['data_sources']['configured']);
		$t->same(13, $manifest['capabilities']['platform']['ready_domains']);
		$t->same(29, $manifest['capabilities']['platform']['services']);
		$t->isTrue(Panel::platformManifest()['attachment']['configured']);
		$t->isTrue(PanelRegistry::describe()['default']['platform']['configured']);

		Panel::withoutPlatform();
		$t->throws(static fn(): PanelPlatform=>Panel::platform(), LogicException::class);
		$t->isFalse(Panel::platformManifest()['attachment']['configured']);
	}
	finally{
		Panel::flush();
	}
})->tag('panel','platform','facade','registry','manifest')->maxMillis(5000);

test('attached platform domains and first-party pages are reachable while mutations stay guarded', static function(Context $t): void {
	$platform=PanelPlatform::defaults(dp_panel_platform_instance_config($t->tempDirectory('panel-platform-pages')));
	$panel=PanelInstance::make('platform-pages')->usePlatform($platform);
	$platform->notificationAdapter()->store(['title'=>'Assignment','message'=>'Order assigned'], 'operator');
	$otherNotification=$platform->notificationAdapter()->store(['title'=>'Private','message'=>'Other operator only'], 'other-operator');
	$platform->notificationAdapter()->store(['title'=>'Global','message'=>'Everyone sees this']);
	$readUser=['id'=>'operator','permissions'=>['operations.view','workflows.view','automation.view','notifications.view','media.view','preferences.view','collaboration.view','authentication.view','security.view','development.view']];$readRequest=['method'=>'GET','user'=>$readUser];

	$pages=[
		$platform->operationsPage([],$readRequest),
		$platform->workflowsPage(null,[],$readRequest),
		$platform->automationPage(null,[],$readRequest),
		$platform->notificationsPage(['recipient'=>'operator'],$readRequest),
		$platform->mediaPage([],$readRequest),
		$platform->preferencesPage('operator','default',null,[],$readRequest),
		$platform->collaborationPage([],$readRequest),
		$platform->authenticationPage('operator',[],$readRequest),
		$platform->securityPage([],[],$readRequest),
		$platform->developerPage($panel->panelManifest(),[],$readRequest),
	];
	foreach($pages as $page){
		$t->same(200, $page->status());
	}
	$t->contains('Order assigned', $pages[3]->content());
	$t->same($platform->controller(), $panel->platformController());
	$t->same($panel, $panel->mountPlatformPages(['domains'=>['operations','notifications','preferences','authentication']]));
	$t->same(4, count($panel->platformPages(['domains'=>['operations','notifications','preferences','authentication']])));
	$t->same(401, $panel->dispatch(['resource'=>'platform_operations','method'=>'GET'])->status());
	$t->same(200, $panel->dispatch(['resource'=>'platform_operations','method'=>'GET','user'=>$readUser])->status());
	$t->contains('Operations center', $panel->dispatch(['resource'=>'platform_operations','method'=>'GET','user'=>$readUser])->content());
	$notificationPage=$panel->dispatch(['resource'=>'platform_notifications','method'=>'GET','user'=>$readUser]);
	$t->same(200, $notificationPage->status());
	$t->contains('Order assigned', $notificationPage->content());
	$t->contains('Everyone sees this', $notificationPage->content());
	$t->notContains('Other operator only', $notificationPage->content());
	$t->same(404, $panel->dispatch(['resource'=>'platform_notifications','method'=>'POST','input'=>['id'=>$otherNotification->id(),'operation'=>'read'],'user'=>['id'=>'operator','permissions'=>['notifications.read']]])->status());
	$t->same(401, $panel->dispatch(['resource'=>'platform_preferences','method'=>'GET'])->status());
	$t->same(200, $panel->dispatch(['resource'=>'platform_preferences','method'=>'GET','user'=>$readUser])->status());
	$t->same(419, $panel->dispatch([
		'resource'=>'platform_operations',
		'method'=>'POST',
		'input'=>['id'=>'missing-operation', 'operation'=>'pause'],
		'user'=>['id'=>'operator', 'permissions'=>['operations.pause']],
	])->status());
	$t->isTrue(($panel->panelManifest()['pages']['platform_operations']['meta']['platform_page'] ?? false)===true);
	$t->same(419, $panel->platformController()->operate($platform->operationControl(), [
		'method'=>'POST',
		'input'=>['id'=>'missing-operation', 'operation'=>'pause'],
		'user'=>['id'=>'operator', 'permissions'=>['operations.pause']],
	])->status());
	$t->same('panel_notification_activity_store', $platform->notificationActivity()->manifest()['type']);
})->tag('panel','platform','domains','pages','controller','security')->maxMillis(8000);
