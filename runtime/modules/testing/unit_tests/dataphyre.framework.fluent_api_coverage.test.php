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
use Dataphyre\Test\TypeInventory;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

$dp_framework_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
$dp_framework_modules=[];
foreach(glob($dp_framework_modules_root.'/*', GLOB_ONLYDIR) ?: [] as $dp_framework_module_root){
	if(is_dir($dp_framework_module_root.'/Framework')){
		$dp_framework_modules[basename($dp_framework_module_root)]=true;
	}
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true]+$dp_framework_modules,
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
require_once $dp_framework_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_framework_modules_root);
\dataphyre\autoloader::register_framework_modules(array_keys($dp_framework_modules));

/** @return array<string,array{0:class-string}> */
function dp_framework_fluent_api_classes(): array {
	$classes=[];
	$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesRoot, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if(!$file instanceof SplFileInfo || $file->getExtension()!=='php' || !str_contains(str_replace('\\', '/', $file->getPathname()), '/Framework/')){
			continue;
		}
		$source=(string)file_get_contents($file->getPathname());
		$shortName=$file->getBasename('.php');
		if(preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)!==1 || preg_match('/\\b(?:final\\s+|abstract\\s+)?class\\s+'.preg_quote($shortName, '/').'\\b/', $source)!==1){
			continue;
		}
		$class=trim($namespace[1]).'\\'.$shortName;
		if(in_array($class, [
			'Dataphyre\\Panel\\PanelScaffoldResult',
			'Dataphyre\\Templating\\SearchQueryBinding',
			'Dataphyre\\Templating\\SqlQueryBinding',
		], true) || !class_exists($class)){
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
		$hasFluentMethod=false;
		foreach($inventory->declaredPublicMethods(false) as $method){
			$return=$method->getReturnType();
			if($return instanceof ReflectionNamedType && $return->getName()==='self'){
				$hasFluentMethod=true;
				break;
			}
		}
		if($hasFluentMethod){
			$key=str_replace('Dataphyre\\', '', $class);
			$classes[$key]=[$class];
		}
	}
	ksort($classes);
	return $classes;
}

dataset('framework fluent api classes', dp_framework_fluent_api_classes());

function dp_framework_fluent_contract_panel_plugin(): \Dataphyre\Panel\PanelPlugin {
	return new class implements \Dataphyre\Panel\PanelPlugin {
		public function id(): string { return 'contract'; }
		public function register(\Dataphyre\Panel\PanelInstance $panel): void {}
		public function boot(\Dataphyre\Panel\PanelInstance $panel): void {}
	};
}

/**
 * Resolves required union parameters whose primitive arm is syntactically
 * valid but does not represent a meaningful framework contract fixture.
 *
 * @return array{resolved:bool,value:mixed}
 */
function dp_framework_fluent_semantic_argument(ReflectionParameter $parameter): array {
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	$namedTypes=[];
	foreach($types as $candidate){
		if($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()){
			$namedTypes[]=$candidate->getName();
		}
	}
	if(
		$parameter->getName()==='platform'
		&& in_array('Dataphyre\\Panel\\PanelPlatform', $namedTypes, true)
	){
		return ['resolved'=>true, 'value'=>\Dataphyre\Panel\PanelPlatform::make()];
	}
	if(in_array('Dataphyre\\Panel\\PanelPlugin', $namedTypes, true)){
		return ['resolved'=>true, 'value'=>dp_framework_fluent_contract_panel_plugin()];
	}
	$declaring=$parameter->getDeclaringFunction();
	if(
		$declaring instanceof ReflectionMethod
		&& $declaring->getDeclaringClass()->getName()==='Dataphyre\\Panel\\PanelMigrationVersion'
	){
		return match($parameter->getName()){
			'semantic'=>['resolved'=>true, 'value'=>'1.0.0'],
			'data'=>['resolved'=>true, 'value'=>[
				'semantic_version'=>'1.0.0',
				'state_schema_version'=>1,
			]],
			default=>['resolved'=>false, 'value'=>null],
		};
	}
	if(
		$declaring instanceof ReflectionMethod
		&& $declaring->getDeclaringClass()->getName()==='Dataphyre\\Panel\\PanelInstance'
		&& $declaring->getName()==='navigationIntentMigration'
	){
		return ['resolved'=>true, 'value'=>'same_panel'];
	}
	return ['resolved'=>false, 'value'=>null];
}

function dp_framework_fluent_argument(ReflectionParameter $parameter, int $depth=0, bool $preferNonNullDefault=false, ?Context $context=null): mixed {
	if($parameter->isDefaultValueAvailable() && (!$preferNonNullDefault || ($parameter->getDefaultValue()!==null && $parameter->getDefaultValue()!==''))){
		return $parameter->getDefaultValue();
	}
	$semantic=dp_framework_fluent_semantic_argument($parameter);
	if($semantic['resolved']){
		return $semantic['value'];
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	foreach($types as $candidate){
		if($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin() && $candidate->getName()==='Dataphyre\\Panel\\PanelExtensionDescriptor'){
			return \Dataphyre\Panel\PanelExtensionDescriptor::make('contract');
		}
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		return match($candidate->getName()){
			'array'=>strtolower($parameter->getName())==='name' ? ['name'=>'contract','url'=>'https://example.test/contract'] : [],
			'bool'=>true,
			'callable'=>static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [],
			'float'=>1.0,
			'int'=>1,
			'object'=>new stdClass(),
			'string'=>match(strtolower($parameter->getName())){
				'plugin'=>new class implements \Dataphyre\Panel\PanelPlugin {
					public function id(): string { return 'contract'; }
					public function register(\Dataphyre\Panel\PanelInstance $panel): void {}
					public function boot(\Dataphyre\Panel\PanelInstance $panel): void {}
				},
				'pattern', 'regex'=>'/.*/',
				'url', 'endpoint'=>'https://example.test/contract',
				'directory', 'root'=>$context?->tempDirectory('fluent-api-directory') ?? 'contract/directory',
				'path'=>dp_framework_fluent_path_argument($parameter,$context),
				'method'=>'GET',
				default=>'contract',
			},
			default=>'value',
		};
	}
	if($type?->allowsNull()){
		return null;
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		$class=$candidate->getName();
		if($class==='Dataphyre\\Panel\\PanelMigrationVersion'){
			$isFrom=$parameter->getName()==='from';
			return \Dataphyre\Panel\PanelMigrationVersion::make($isFrom ? '0.0.0' : '1.0.0', $isFrom ? 0 : 1);
		}
		if($class==='Dataphyre\\Http\\Request'){
			return \Dataphyre\Http\Request::create('GET', '/contract');
		}
		if($class===Closure::class){
			return static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [];
		}
		if($class===DateTimeInterface::class || is_a($class, DateTimeInterface::class, true)){
			return new DateTimeImmutable('2026-01-01 00:00:00 UTC');
		}
		if($class==='Dataphyre\\Storage\\Contracts\\StorageDriver'){
			return new \Dataphyre\Storage\Drivers\MemoryDriver();
		}
		if($class==='Dataphyre\\Panel\\PanelMediaDisk'){
			if(!$context instanceof Context){
				throw new LogicException('Panel media-disk fluent fixtures require a managed test context.');
			}
			return \Dataphyre\Panel\PanelLocalMediaDisk::make($context->tempDirectory('fluent-media-disk'));
		}
		if($class==='Dataphyre\\Panel\\PanelRelationAdapter'){
			return new \Dataphyre\Panel\PanelArrayRelationAdapter();
		}
		if($class==='Dataphyre\\Panel\\PanelDataSource'){
			return new \Dataphyre\Panel\PanelArrayDataSource();
		}
		if($class==='Dataphyre\\Panel\\PanelNavigationKeyProvider'){
			return \Dataphyre\Panel\PanelStaticNavigationKeyProvider::single(str_repeat('k', 32), 'contract');
		}
		if($class==='Dataphyre\\Panel\\PanelNavigationReplayGuard'){
			return new \Dataphyre\Panel\PanelInMemoryNavigationReplayGuard();
		}
		if($class==='Dataphyre\\Panel\\PanelWidgetRuntimeAdapter'){
			return new \Dataphyre\Panel\PanelInMemoryWidgetRuntimeAdapter();
		}
		if($class==='Dataphyre\\Panel\\WorkflowDefinition'){
			return \Dataphyre\Panel\WorkflowDefinition::make('contract')->state('draft');
		}
		return dp_framework_fluent_instance($class, $depth + 1, $context);
	}
	return 'value';
}

function dp_framework_fluent_path_argument(ReflectionParameter $parameter, ?Context $context): string {
	$declaring=$parameter->getDeclaringFunction();
	if(
		$context instanceof Context
		&& $declaring instanceof ReflectionMethod
		&& $declaring->getDeclaringClass()->getName()==='Dataphyre\\Panel\\PanelTranslationCatalogueLoader'
	){
		return $context->workspace('fluent-translation-catalogue')->file('catalogue.json','{}');
	}
	return 'contract/path';
}

function dp_framework_fluent_data_surface_registry(): \Dataphyre\Panel\PanelDataSurfaceRegistry {
	return new \Dataphyre\Panel\PanelDataSurfaceRegistry(
		new \Dataphyre\Panel\PanelDataSourceRegistry(),
		new \Dataphyre\Panel\PanelDataSurfaceIntentSigner(['fluent'=>str_repeat('f', 32)], 'fluent'),
		static fn(): bool=>true
	);
}

function dp_framework_fluent_agent_executor(): \Dataphyre\Panel\PanelAgentToolExecutor {
	return new class implements \Dataphyre\Panel\PanelAgentToolExecutor {
		public function execute(\Dataphyre\Panel\PanelAgentToolExecutionRequest $request): \Dataphyre\Panel\PanelAgentToolExecutionResult {
			return \Dataphyre\Panel\PanelAgentToolExecutionResult::success(['fixture'=>true]);
		}
	};
}

function dp_framework_fluent_enable_agent_workflows(\Dataphyre\Panel\PanelPlatform $platform): \Dataphyre\Panel\PanelPlatform {
	$catalog=new \Dataphyre\Panel\PanelAgentToolCatalog();
	$policy=new \Dataphyre\Panel\PanelAgentPolicyEngine();
	$signer=new \Dataphyre\Panel\PanelAgentIntentSigner(['fluent'=>str_repeat('a', 32)], 'fluent');
	$store=new \Dataphyre\Panel\InMemoryPanelAgentWorkflowStore();
	$runtime=new \Dataphyre\Panel\PanelAgentRuntime($catalog, $policy, $signer, $store);
	return $platform
		->register('agents.catalog', $catalog, true)
		->register('agents.policy', $policy, true)
		->register('agents.signer', $signer, true)
		->register('agents.store', $store, true)
		->register('agents.runtime', $runtime, true);
}

function dp_framework_fluent_panel_platform(Context $context, string $method): \Dataphyre\Panel\PanelPlatform {
	$platform=\Dataphyre\Panel\PanelPlatform::defaults([
		'state_root'=>$context->tempDirectory('fluent-platform-'.$method),
		'distributed_operations'=>['lease_ttl_seconds'=>5, 'snapshot_retention'=>16],
		'authentication'=>['encryption_key'=>str_repeat('e', 32), 'pepper'=>str_repeat('p', 32)],
		'media'=>['signing_key'=>str_repeat('s', 32)],
		'migrations'=>[],
		'observability'=>[],
		'packages'=>[],
		'iam'=>['audit_key'=>str_repeat('i', 32), 'authorize'=>static fn(): bool=>true],
		'studio'=>['authorization'=>static fn(): bool=>true],
	]);
	if($method==='registerDataSurface'){
		$platform->register('data_surfaces.registry', dp_framework_fluent_data_surface_registry(), true);
	}
	if($method==='registerAgentTool'){
		dp_framework_fluent_enable_agent_workflows($platform);
	}
	return $platform;
}

function dp_framework_fluent_panel_instance(string $method, ?Context $context=null): \Dataphyre\Panel\PanelInstance {
	$panel=\Dataphyre\Panel\PanelInstance::make('fluent-contract');
	if($method==='reloadPlugin'){
		$panel->plugin(dp_framework_fluent_contract_panel_plugin());
	}
	if($method==='mountPlatformPages'){
		$panel->usePlatform(\Dataphyre\Panel\PanelPlatform::make([
			'platform.controller'=>new \Dataphyre\Panel\PanelPlatformController(),
			'platform.template'=>\Dataphyre\Panel\PanelPlatformTemplate::class,
		]));
	}
	if($method==='registerDataSource'){
		$panel->usePlatform(\Dataphyre\Panel\PanelPlatform::make([
			'data.registry'=>new \Dataphyre\Panel\PanelDataSourceRegistry(),
		]));
	}
	if($method==='registerDataSurface'){
		$panel->useDataSurfaces(dp_framework_fluent_data_surface_registry());
	}
	if($method==='registerAgentTool'){
		if(!$context instanceof Context){
			throw new LogicException('Panel agent fluent fixtures require a managed test context.');
		}
		$panel->usePlatform(dp_framework_fluent_panel_platform($context, $method));
	}
	return $panel;
}

function dp_framework_fluent_instance(string $class, int $depth=0, ?Context $context=null, string $method=''): object {
	if($depth>6){
		throw new RuntimeException('Framework fluent fixture nesting exceeded for '.$class.'.');
	}
	if($class==='Dataphyre\\Panel\\PanelDataSurfaceDefinition'){
		return \Dataphyre\Panel\PanelDataSurfaceDefinition::make(
			'fluent-contract','contract-resource','contract-source','table',
			\Dataphyre\Panel\PanelDataSurfaceProjection::make(['id'])
		);
	}
	if($class==='Dataphyre\\Panel\\PanelDataSurfaceRegistry'){
		return dp_framework_fluent_data_surface_registry();
	}
	if($class==='Dataphyre\\Panel\\PanelAgentToolExecutor'){
		return dp_framework_fluent_agent_executor();
	}
	if($class==='Dataphyre\\Panel\\PanelAdapterPackBinding'){
		return \Dataphyre\Panel\PanelAdapterPackBinding::make(
			'contract',
			'platform:contract',
			stdClass::class,
			static fn():stdClass=>new stdClass()
		);
	}
	if($depth===0 && $context instanceof Context && $class==='Dataphyre\\Panel\\PanelPlatform'){
		return dp_framework_fluent_panel_platform($context,$method);
	}
	if($depth===0 && $class==='Dataphyre\\Panel\\PanelInstance'){
		return dp_framework_fluent_panel_instance($method, $context);
	}
	$inventory=$context instanceof Context ? $context->inventory($class) : TypeInventory::of($class);
	if($inventory->hasMethod('make')){
		$factory=$inventory->method('make');
		if($factory->isPublic() && $factory->isStatic()){
			$arguments=array_map(
				static fn(ReflectionParameter $parameter): mixed=>dp_framework_fluent_argument($parameter, $depth, false, $context),
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
		static fn(ReflectionParameter $parameter): mixed=>dp_framework_fluent_argument($parameter, $depth, false, $context),
		$constructor->getParameters()
	);
	return $inventory->newInstanceWithArguments($arguments);
}

test('framework public fluent methods accept their declared defaults and required types', static function(Context $t, string $class): void {
	$inventory=$t->inventory($class);
	$methods=[];
	$failures=[];
	$sideEffectPrefixes=['dispatch', 'execute', 'install', 'publish', 'run', 'send', 'uninstall', 'write'];
	foreach($inventory->declaredPublicMethods(false) as $method){
		$return=$method->getReturnType();
		if(str_starts_with($method->getName(), '__') || !$return instanceof ReflectionNamedType || $return->getName()!=='self'){
			continue;
		}
		$sideEffect=false;
		foreach($sideEffectPrefixes as $prefix){
			if(str_starts_with($method->getName(), $prefix)){
				$sideEffect=true;
				break;
			}
		}
		if($sideEffect){
			continue;
		}
		$methods[]=$method->getName();
		try{
			$instance=dp_framework_fluent_instance($class,0,$t,$method->getName());
			$arguments=$class==='Dataphyre\\Panel\\PanelPlatform' && $method->getName()==='restore'
				? [$instance->checkpoint()]
				: array_map(
					static fn(ReflectionParameter $parameter): mixed=>dp_framework_fluent_argument($parameter, 0, true, $t),
					$method->getParameters()
				);
			$result=$inventory->invokeWithArguments($method, $instance, $arguments);
			if(!$result instanceof $class){
				$failures[$method->getName()]='Method did not return '.$class.'.';
			}
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->notEmpty($methods);
	$t->same([], $failures);
})->with('framework fluent api classes')->tag('framework', 'fluent-api', 'coverage')->maxMillis(5000);
