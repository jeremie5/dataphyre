<?php
declare(strict_types=1);
// @dataphyre-test-discovery-dependency framework-source
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\TypeInventory;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel public fluent contracts')
	->coverageMemoryLimit('2G');

/** @return array<string,array{0:class-string}> */
function dp_panel_fluent_api_classes(): array {
	$classes=[];
	$root=dirname(__DIR__).'/Framework';
	$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if(!$file instanceof SplFileInfo || $file->getExtension()!=='php'){
			continue;
		}
		$short_name=$file->getBasename('.php');
		if(in_array($short_name, ['PanelScaffoldResult'], true)){
			continue;
		}
		$source=(string)file_get_contents($file->getPathname());
		if(preg_match('/\\b(?:final\\s+|abstract\\s+)?class\\s+'.preg_quote($short_name, '/').'\\b/', $source)!==1){
			continue;
		}
		$class='Dataphyre\\Panel\\'.$short_name;
		if(!class_exists($class)){
			continue;
		}
		$inventory=TypeInventory::of($class);
		if(!$inventory->isInstantiable() || !$inventory->hasMethod('make')){
			continue;
		}
		$factory=$inventory->method('make');
		if(!$factory->isPublic() || !$factory->isStatic()){
			continue;
		}
		$has_fluent_method=false;
		foreach($inventory->declaredPublicMethods(false) as $method){
			$return=$method->getReturnType();
			if($return instanceof ReflectionNamedType && $return->getName()==='self'){
				$has_fluent_method=true;
				break;
			}
		}
		if($has_fluent_method){
			$classes[basename(str_replace('\\', '/', $inventory->name()))]=[$class];
		}
	}
	ksort($classes);
	return $classes;
}

dataset('panel fluent api classes', dp_panel_fluent_api_classes());

/**
 * Creates a safe typed argument for a public builder API.
 */
function dp_panel_fluent_argument(ReflectionParameter $parameter, TempWorkspace $workspace, int $depth=0, bool $preferNonNullDefault=false): mixed {
	if($parameter->isDefaultValueAvailable() && (!$preferNonNullDefault || $parameter->getDefaultValue()!==null)){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$declaringClass=$parameter->getDeclaringClass()?->getName();
	if(
		$declaringClass===Dataphyre\Panel\PanelTranslationCatalogueLoader::class
		&& strtolower($parameter->getName())==='path'
	){
		return __DIR__;
	}
	if(
		$declaringClass===Dataphyre\Panel\PanelPlatform::class
		&& strtolower($parameter->getName())==='extension'
	){
		return ['id'=>'contract', 'version'=>'1.0.0'];
	}
	if(
		$declaringClass===Dataphyre\Panel\PanelInstance::class
		&& strtolower($parameter->getName())==='platform'
	){
		return Dataphyre\Panel\PanelPlatform::make();
	}
	if(
		$declaringClass===Dataphyre\Panel\PanelInstance::class
		&& in_array(strtolower($parameter->getName()), ['replacement', 'config'], true)
		&& $parameter->isDefaultValueAvailable()
		&& $parameter->getDefaultValue()===null
	){
		return null;
	}
	if(
		$declaringClass===Dataphyre\Panel\PanelInstance::class
		&& $parameter->getDeclaringFunction()->getName()==='navigationIntentMigration'
		&& strtolower($parameter->getName())==='policy'
	){
		return Dataphyre\Panel\PanelNavigationIntentManager::MIGRATION_SAME_PANEL;
	}
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	if($type instanceof ReflectionUnionType && in_array(strtolower($parameter->getName()), ['name', 'label', 'heading', 'url', 'target'], true)){
		foreach($types as $candidate){
			if($candidate instanceof ReflectionNamedType && $candidate->isBuiltin() && $candidate->getName()==='string'){
				return strtolower($parameter->getName())==='url' ? '/panel/contract' : 'contract';
			}
		}
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		return match($candidate->getName()){
			'array'=>[],
			'bool'=>true,
			'callable'=>static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [],
			'float'=>1.0,
			'int'=>1,
			'object'=>new stdClass(),
			'string'=>match(strtolower($parameter->getName())){
				'directory'=>dp_panel_fluent_directory($workspace),
				'pattern', 'regex'=>'/.*/',
				'event'=>'blur',
				'position'=>'append',
				'country'=>'CA',
				'currency'=>'CAD',
				'path', 'targetpath'=>'panel/{name}',
				'url', 'target', 'endpoint'=>'/panel/contract',
				default=>'contract',
			},
			default=>'value',
		};
	}
	if($type?->allowsNull()){
		return null;
	}
	foreach($types as $candidate){
		if($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin() && $candidate->getName()!=='null'){
			return dp_panel_fluent_instance($candidate->getName(), $workspace, $depth + 1);
		}
	}
	return 'value';
}

function dp_panel_fluent_directory(TempWorkspace $workspace): string {
	static $sequence=0;
	return $workspace->directory('fixture-'.(++$sequence));
}

function dp_panel_fluent_data_surface_registry(): Dataphyre\Panel\PanelDataSurfaceRegistry {
	return new Dataphyre\Panel\PanelDataSurfaceRegistry(
		new Dataphyre\Panel\PanelDataSourceRegistry(),
		new Dataphyre\Panel\PanelDataSurfaceIntentSigner(['fluent'=>str_repeat('f', 32)], 'fluent'),
		static fn(): bool=>true
	);
}

function dp_panel_fluent_agent_executor(): Dataphyre\Panel\PanelAgentToolExecutor {
	return new class implements Dataphyre\Panel\PanelAgentToolExecutor {
		public function execute(Dataphyre\Panel\PanelAgentToolExecutionRequest $request): Dataphyre\Panel\PanelAgentToolExecutionResult {
			return Dataphyre\Panel\PanelAgentToolExecutionResult::success(['fixture'=>true]);
		}
	};
}

function dp_panel_fluent_enable_agent_workflows(Dataphyre\Panel\PanelPlatform $platform): Dataphyre\Panel\PanelPlatform {
	$catalog=new Dataphyre\Panel\PanelAgentToolCatalog();
	$policy=new Dataphyre\Panel\PanelAgentPolicyEngine();
	$signer=new Dataphyre\Panel\PanelAgentIntentSigner(['fluent'=>str_repeat('a', 32)], 'fluent');
	$store=new Dataphyre\Panel\InMemoryPanelAgentWorkflowStore();
	$runtime=new Dataphyre\Panel\PanelAgentRuntime($catalog, $policy, $signer, $store);
	return $platform
		->register('agents.catalog', $catalog, true)
		->register('agents.policy', $policy, true)
		->register('agents.signer', $signer, true)
		->register('agents.store', $store, true)
		->register('agents.runtime', $runtime, true);
}

/**
 * Instantiates a Panel builder through its public make factory or constructor.
 */
function dp_panel_fluent_instance(string $class, TempWorkspace $workspace, int $depth=0, string $method=''): object {
	if($depth>6){
		throw new RuntimeException('Panel fluent fixture nesting exceeded for '.$class.'.');
	}
	if($class===Dataphyre\Panel\PanelNavigationKeyProvider::class){
		return Dataphyre\Panel\PanelStaticNavigationKeyProvider::single(str_repeat('n', 32), 'fluent-contract');
	}
	if($class===Dataphyre\Panel\PanelNavigationReplayGuard::class){
		return new Dataphyre\Panel\PanelInMemoryNavigationReplayGuard();
	}
	if($class===Dataphyre\Panel\PanelWidgetRuntimeAdapter::class){
		return new Dataphyre\Panel\PanelInMemoryWidgetRuntimeAdapter();
	}
	if($class===Dataphyre\Panel\PanelDataSurfaceDefinition::class){
		return Dataphyre\Panel\PanelDataSurfaceDefinition::make(
			'fluent-contract','contract-resource','contract-source','table',
			Dataphyre\Panel\PanelDataSurfaceProjection::make(['id'])
		);
	}
	if($class===Dataphyre\Panel\PanelDataSurfaceRegistry::class){
		return dp_panel_fluent_data_surface_registry();
	}
	if($class===Dataphyre\Panel\PanelAgentToolExecutor::class){
		return dp_panel_fluent_agent_executor();
	}
	if($class===Dataphyre\Panel\PanelAdapterPackBinding::class){
		return Dataphyre\Panel\PanelAdapterPackBinding::make(
			'contract',
			'platform:contract',
			stdClass::class,
			static fn():stdClass=>new stdClass()
		);
	}
	if($class===Dataphyre\Panel\PanelInstance::class){
		$panel=Dataphyre\Panel\PanelInstance::make('fluent-contract');
		if($method==='reloadPlugin'){
			$panel->plugin(new class implements Dataphyre\Panel\PanelPlugin {
				public function id(): string {
					return 'contract';
				}

				public function register(Dataphyre\Panel\PanelInstance $panel): void {}

				public function boot(Dataphyre\Panel\PanelInstance $panel): void {}
			});
		}
		if($method==='registerDataSource'){
			$panel->usePlatform(Dataphyre\Panel\PanelPlatform::make([
				'data.registry'=>new Dataphyre\Panel\PanelDataSourceRegistry(),
			]));
		}
		if($method==='registerDataSurface'){
			$panel->useDataSurfaces(dp_panel_fluent_data_surface_registry());
		}
		if($method==='registerAgentTool'){
			$panel->usePlatform(dp_panel_fluent_enable_agent_workflows(Dataphyre\Panel\PanelPlatform::make()));
		}
		return $panel;
	}
	if($class===Dataphyre\Panel\PanelMediaDisk::class){
		return new Dataphyre\Panel\PanelLocalMediaDisk(dp_panel_fluent_directory($workspace));
	}
	if($class===Dataphyre\Panel\PanelRelationAdapter::class){
		return new Dataphyre\Panel\PanelArrayRelationAdapter([1=>['id'=>1, 'label'=>'Contract']]);
	}
	if($class===Dataphyre\Panel\PanelDataSource::class){
		return new Dataphyre\Panel\PanelArrayDataSource([['id'=>1, 'label'=>'Contract']]);
	}
	if($class===Dataphyre\Panel\WorkflowDefinition::class){
		return Dataphyre\Panel\WorkflowDefinition::make('contract')->state('draft')->initial('draft');
	}
	if($class===Dataphyre\Panel\PanelMigrationDefinition::class){
		return Dataphyre\Panel\PanelMigrationDefinition::make(
			'contract.migration',
			'contract',
			Dataphyre\Panel\PanelMigrationVersion::make('1.0.0',1),
			Dataphyre\Panel\PanelMigrationVersion::make('1.1.0',2),
			static fn(Dataphyre\Panel\PanelMigrationContext $context):Dataphyre\Panel\PanelMigrationBatch=>Dataphyre\Panel\PanelMigrationBatch::complete($context->data())
		);
	}
	if($class===Dataphyre\Panel\PanelMediaProcessingPipeline::class){
		return Dataphyre\Panel\PanelMediaProcessingPipeline::make(
			new Dataphyre\Panel\PanelLocalMediaDisk(dp_panel_fluent_directory($workspace))
		);
	}
	if($class===Dataphyre\Panel\PanelNotificationActivityStore::class){
		return Dataphyre\Panel\PanelNotificationActivityStore::make(dp_panel_fluent_directory($workspace));
	}
	if($class===Dataphyre\Panel\PanelRelationWorkspace::class){
		return Dataphyre\Panel\PanelRelationWorkspace::make(
			'contract',
			'parent-1',
			new Dataphyre\Panel\PanelArrayRelationAdapter([1=>['id'=>1, 'label'=>'Contract']])
		);
	}
	if($class===Dataphyre\Panel\PanelPlatform::class){
		$platform=Dataphyre\Panel\PanelPlatform::defaults([
			'state_root'=>dp_panel_fluent_directory($workspace),
			'distributed_operations'=>[
				'lease_ttl_seconds'=>5,
				'snapshot_retention'=>16,
			],
			'authentication'=>[
				'encryption_key'=>str_repeat('e', 32),
				'pepper'=>str_repeat('p', 32),
			],
			'media'=>['signing_key'=>str_repeat('s', 32)],
			'migrations'=>[],
			'observability'=>[],
			'packages'=>[],
			'iam'=>['audit_key'=>str_repeat('i',32),'authorize'=>static fn():bool=>true],
			'studio'=>['authorization'=>static fn():bool=>true],
		]);
		if($method==='registerDataSurface'){
			$platform->register('data_surfaces.registry', dp_panel_fluent_data_surface_registry());
		}
		if($method==='registerAgentTool'){
			dp_panel_fluent_enable_agent_workflows($platform);
		}
		return $platform;
	}
	$inventory=TypeInventory::of($class);
	if($inventory->hasMethod('make')){
		$factory=$inventory->method('make');
		if($factory->isPublic() && $factory->isStatic()){
			$arguments=array_map(
				static fn(ReflectionParameter $parameter): mixed=>dp_panel_fluent_argument($parameter, $workspace, $depth),
				$factory->getParameters()
			);
			$instance=$inventory->invokeWithArguments($factory, null, $arguments);
			if(is_object($instance)){
				return $instance;
			}
		}
	}
	$constructor=$inventory->constructor();
	$arguments=$constructor===null ? [] : array_map(
		static fn(ReflectionParameter $parameter): mixed=>dp_panel_fluent_argument($parameter, $workspace, $depth),
		$constructor->getParameters()
	);
	return $inventory->newInstanceWithArguments($arguments);
}

test('public fluent methods accept their documented defaults and required types', static function(Context $t, string $class): void {
	$workspace=$t->workspace('panel-fluent-api');
	$inventory=$t->inventory($class);
	$methods=[];
	$failures=[];
	$skipped_methods=[
		'Dataphyre\\Panel\\PanelInstance'=>[
			'accessAuth', 'auth', 'accessPermissions', 'permissions', 'permissionAdmin', 'plugin', 'plugins', 'mountPlatformPages',
		],
		'Dataphyre\\Panel\\PanelScaffoldResult'=>['write'],
	];
	foreach($inventory->declaredPublicMethods(false) as $method){
		$return=$method->getReturnType();
		$returns_self=$return instanceof ReflectionNamedType && $return->getName()==='self';
		if($return instanceof ReflectionUnionType){
			foreach($return->getTypes() as $candidate){
				if($candidate instanceof ReflectionNamedType && $candidate->getName()==='self'){
					$returns_self=true;
					break;
				}
			}
		}
		if(
			str_starts_with($method->getName(), '__')
			|| in_array($method->getName(), $skipped_methods[$class] ?? [], true)
			|| !$returns_self
		){
			continue;
		}
		$methods[]=$method->getName();
		try{
			$instance=dp_panel_fluent_instance($class, $workspace, 0, $method->getName());
			$arguments=$class===Dataphyre\Panel\PanelPlatform::class && $method->getName()==='restore'
				? [$instance->checkpoint()]
				: array_map(
					static fn(ReflectionParameter $parameter): mixed=>dp_panel_fluent_argument($parameter, $workspace, 0, true),
					$method->getParameters()
				);
			$result=$inventory->invokeWithArguments($method, $instance, $arguments);
			if($return instanceof ReflectionNamedType && $return->getName()==='self' && !$result instanceof $class){
				$failures[$method->getName()]='Method did not return '.$class.'.';
			}
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->notEmpty($methods);
	$t->same([], $failures);
})->with('panel fluent api classes')->tag('panel', 'fluent-api', 'coverage')->maxMillis(5000);

test('public fluent readers expose default state without side effects', static function(Context $t, string $class): void {
	$workspace=$t->workspace('panel-fluent-readers');
	$inventory=$t->inventory($class);
	$methods=[];
	$failures=[];
	$side_effect_prefixes=['apply', 'boot', 'delete', 'dispatch', 'download', 'duplicate', 'emit', 'execute', 'forceDelete', 'handle', 'import', 'install', 'restore', 'run', 'save', 'uninstall', 'upload', 'write'];
	$fail_closed_readers=[
		'Dataphyre\\Panel\\PanelInstance'=>['platform', 'platformController', 'platformPages', 'operationsOs', 'operationsConsole', 'domainActivation', 'commandFabric', 'complianceLedger', 'complianceAutomation', 'dataSources', 'dataSurfaces', 'dataSurfaceEndpoint', 'realtime', 'agentRuntime'],
		'Dataphyre\\Panel\\PanelPlatform'=>[
			'operationsOs', 'operationsConsole', 'operationsOsPage', 'operationsDomainCompiler', 'domainActivation', 'commandFabric', 'closedLoopIntelligence', 'federationGateway', 'releaseExecution', 'workGraph', 'policyControlPlane',
			'operatorRuntime', 'semanticCatalog', 'lineageGraph', 'processIntelligence',
			'counterfactualLab', 'complianceLedger', 'complianceAutomation', 'federationControlPlane',
			'releaseControlPlane', 'marketplaceGovernance', 'marketplaceTrustNetwork', 'marketplaceRevocations', 'marketplacePublishers', 'studioBranches',
			'dataSurfaces',
			'studioVisualRuntime',
			'realtimeBroker', 'realtimeSigner', 'realtime',
			'agentTools', 'agentPolicy', 'agentSigner', 'agentStore', 'agents',
		],
	];
	foreach($inventory->declaredPublicMethods(false) as $method){
		if(str_starts_with($method->getName(), '__')){
			continue;
		}
		if(in_array($method->getName(), $fail_closed_readers[$class] ?? [], true)){
			continue;
		}
		$required=array_filter($method->getParameters(), static fn(ReflectionParameter $parameter): bool=>!$parameter->isOptional());
		if($required!==[]){
			continue;
		}
		$side_effect=false;
		foreach($side_effect_prefixes as $prefix){
			if(str_starts_with($method->getName(), $prefix)){
				$side_effect=true;
				break;
			}
		}
		if($side_effect){
			continue;
		}
		$return=$method->getReturnType();
		if($return instanceof ReflectionNamedType && $return->getName()==='self'){
			continue;
		}
		$methods[]=$method->getName();
		try{
			$inventory->invoke($method, dp_panel_fluent_instance($class, $workspace));
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->notEmpty($methods);
	$t->same([], $failures);
})->with('panel fluent api classes')->tag('panel', 'fluent-api', 'reader', 'coverage')->maxMillis(5000);

test('resource array hydration accepts the complete manifest contract', static function(Context $t): void {
	$list=static fn(): array=>[];
	$mutate=static fn(array $data): array=>$data;
	$resource=\Dataphyre\Panel\Resource::fromArray([
		'name'=>'orders',
		'label'=>'Order',
		'plural_label'=>'Orders',
		'model'=>'App\\Order',
		'repository'=>'App\\OrderRepository',
		'table'=>'orders',
		'url'=>'/panel/orders',
		'group'=>'Sales',
		'icon'=>'cart',
		'navigation_parent'=>'commerce',
		'folder'=>'operations',
		'navigation_description'=>'Manage customer orders',
		'navigation_badge'=>7,
		'navigation_badge_tone'=>'warning',
		'sort'=>20,
		'per_page'=>50,
		'action_fit'=>'content',
		'hidden_from_navigation'=>true,
		'policy'=>[],
		'tenant_field'=>'tenant_id',
		'tenant_required'=>true,
		'tenant_resolver'=>static fn(): int=>7,
		'tenant_scope'=>static fn(mixed $query): mixed=>$query,
		'global_searchable'=>true,
		'global_search_columns'=>['id', 'name'],
		'activity'=>$list,
		'insights'=>$list,
		'alerts'=>$list,
		'links'=>$list,
		'contacts'=>$list,
		'locations'=>$list,
		'changes'=>$list,
		'tags'=>$list,
		'tag'=>$list,
		'items'=>$list,
		'totals'=>$list,
		'approvals'=>$list,
		'approval'=>$list,
		'notes'=>$list,
		'note'=>$list,
		'messages'=>$list,
		'message'=>$list,
		'shipments'=>$list,
		'payments'=>$list,
		'attachments'=>$list,
		'attach'=>$list,
		'tasks'=>$list,
		'task'=>$list,
		'create_task'=>$list,
		'mutate_form_data'=>$mutate,
		'mutate_create_data'=>$mutate,
		'mutate_update_data'=>$mutate,
		'mutate_fill_data'=>$mutate,
		'mutate_create_fill_data'=>$mutate,
		'mutate_edit_fill_data'=>$mutate,
		'before_fill'=>$list,
		'after_fill'=>static fn(\Dataphyre\Panel\PanelFormState $state): \Dataphyre\Panel\PanelFormState=>$state,
		'before_validate'=>$list,
		'after_validate'=>static fn(\Dataphyre\Panel\PanelFormState $state): \Dataphyre\Panel\PanelFormState=>$state,
		'before_save'=>$mutate,
		'after_save'=>static fn(mixed $result): mixed=>$result,
		'record_key'=>'id',
		'record_title'=>'name',
		'record_subtitle'=>'status',
		'form'=>['schema'=>\Dataphyre\Panel\Schema::make()->field('name')],
		'bulk_form'=>['schema'=>\Dataphyre\Panel\Schema::make()->field('status')],
		'infolist'=>\Dataphyre\Panel\Infolist::make()->entry('name'),
		'infolist_schema'=>\Dataphyre\Panel\Infolist::make()->entry('status'),
		'fields'=>[['name'=>'name', 'type'=>'text']],
		'schema'=>\Dataphyre\Panel\Schema::make()->field('status'),
		'bulk_fields'=>[['name'=>'status', 'type'=>'select', 'options'=>['open'=>'Open']]],
		'bulk_schema'=>\Dataphyre\Panel\Schema::make()->field('priority'),
		'status_field'=>'status',
		'transitions'=>[['name'=>'approve', 'from'=>'open', 'to'=>'approved']],
		'status_widgets'=>true,
		'form_sections'=>[['name'=>'identity', 'label'=>'Identity']],
		'columns'=>[['name'=>'id'], ['name'=>'name']],
		'views'=>[['name'=>'open', 'label'=>'Open']],
		'filters'=>[['name'=>'status', 'type'=>'select']],
		'summaries'=>[['name'=>'total', 'type'=>'count']],
		'actions'=>[['name'=>'archive', 'label'=>'Archive']],
		'relations'=>[['name'=>'items', 'label'=>'Items']],
	]);
	$manifest=$resource->toArray();
	$t->same('orders', $resource->name());
	$t->same('Order', $resource->label());
	$t->pathEquals('tenant_field', 'tenant_id', $manifest);
	$t->pathEquals('status_field', 'status', $manifest);
})->tag('panel', 'resource', 'hydration', 'coverage')->maxMillis(5000);
