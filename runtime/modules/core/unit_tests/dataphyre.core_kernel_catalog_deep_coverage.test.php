<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	use Dataphyre\Test\TestState;

	function dp_core_kernel_state(): ?TestState {
		return TestState::channelIfActive('core.kernel.catalog');
	}

	function dp_core_kernel_path(string $path): string {
		return rtrim(str_replace('\\','/',$path),'/');
	}

	/** Test seam for the runtime's pre-ROOTPATH bootstrap branch. */
	function defined(string $constant): bool {
		if($constant==='ROOTPATH' && dp_core_kernel_state()?->get('force_rootpath_undefined',false)===true){
			return false;
		}
		return \defined($constant);
	}

	/** Virtual module overlay used only for paths explicitly installed by a scenario. */
	function is_dir(string $path): bool {
		$path=dp_core_kernel_path($path);
		if(in_array($path,dp_core_kernel_state()?->get('virtual_directories',[]) ?? [],true)){
			return true;
		}
		return \is_dir($path);
	}

	function is_file(string $path): bool {
		$path=dp_core_kernel_path($path);
		if(array_key_exists($path,dp_core_kernel_state()?->get('virtual_files',[]) ?? [])){
			return true;
		}
		return \is_file($path);
	}

	function file_get_contents(string $path,mixed ...$arguments): string|false {
		$path=dp_core_kernel_path($path);
		$files=dp_core_kernel_state()?->get('virtual_files',[]) ?? [];
		if(array_key_exists($path,$files)){
			return (string)$files[$path];
		}
		return \file_get_contents($path,...$arguments);
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\TestState;
	use dataphyre\app_locator;
	use dataphyre\application_definition;
	use dataphyre\autoloader;
	use dataphyre\module_registry;
	use dataphyre\runtime;
	use function Dataphyre\Test\test;

	final class DpCoreSchedulingTaskRunnerProbe {
		public static function dispatch(?callable $terminator=null,?callable $shutdownRegistrar=null,array $runtime=[]): void {
			TestState::channel('core.kernel.catalog')->append('task_runner_dispatches',[
				$runtime['scheduler_name']??null,
				$runtime['scheduler_claim']??null,
			]);
		}
	}

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY',[
			'enabled'=>[
				'core'=>true,
				'sql'=>true,
			],
			'disabled'=>[
				'access'=>true,
				'routing'=>true,
			],
			'core_implicit'=>true,
		]);
	}

	$dp_core_kernel_root=\Dataphyre\Test\dataphyre_path();
	$dp_core_kernel_modules=$dp_core_kernel_root.'/runtime/modules';
	require_once $dp_core_kernel_modules.'/core/kernel/app_locator.php';
	require_once $dp_core_kernel_modules.'/core/kernel/application_definition.php';
	require_once $dp_core_kernel_modules.'/core/kernel/module_registry.php';
	require_once $dp_core_kernel_modules.'/core/kernel/autoloader.php';
	require_once $dp_core_kernel_modules.'/core/kernel/runtime.php';
	autoloader::register($dp_core_kernel_modules);

	/** @param array<string,bool> $enabled @param array<string,bool> $disabled */
	function dp_core_kernel_registry(Context $t,array $enabled=['core'=>true],array $disabled=[],bool $allowAll=false): NonPublicAccess {
		return $t->nonPublic(module_registry::class)
			->replacePropertyForTest('module_config',[
				'enabled'=>$enabled,
				'disabled'=>$disabled,
				'allow_all'=>$allowAll,
			])
			->replacePropertyForTest('metadata_cache',[])
			->replacePropertyForTest('definition_cache',[])
			->replacePropertyForTest('available_modules_cache',null);
	}

	test('core kernel app locator and application definitions cover configured roots and immutable overrides',static function(Context $t): void {
		$t->state('core.kernel.catalog');
		$workspace=$t->workspace('core-kernel-apps');
		$base=$workspace->root();
		$project=$workspace->directory('project');
		$projectApplications=$workspace->directory('project/applications');
		$environmentApplications=$workspace->directory('environment-applications');
		$extraApplications=$workspace->directory('extra-applications');
		$workspace->directory('project/applications/alpha/framework');
		$workspace->directory('environment-applications/environment');
		$t->setEnvironmentForTest([
			'DATAPHYRE_APPLICATION_ROOTS'=>$environmentApplications.PATH_SEPARATOR.'  ',
		]);

			$roots=app_locator::roots($project,[
				'',
				$projectApplications,
				$projectApplications.'/../applications',
				$extraApplications,
			]);
			$t->same(realpath($projectApplications),$roots[0]);
			$t->contains(realpath($extraApplications),$roots);
			$t->contains(realpath($environmentApplications),$roots);
			$t->same(realpath($projectApplications.'/alpha'),app_locator::locate($project,'alpha'));
			$t->same(realpath($environmentApplications.'/environment'),app_locator::locate($project,'environment'));
			$t->same(null,app_locator::locate($project,'missing',[$extraApplications]));
			$missingManifest=$workspace->directory('standalone-missing-manifest');
			$workspace->file('standalone-missing-manifest/app.php', "<?php return [];\n");
			$t->same(null, app_locator::locate($missingManifest, 'standalone-missing-manifest'));
			$oversizedManifest=$workspace->directory('standalone-oversized-manifest');
			$workspace->file('standalone-oversized-manifest/app.php', "<?php return [];\n");
			$workspace->file('standalone-oversized-manifest/dataphyre.app.json', str_repeat('x', 65537));
			$t->same(null, app_locator::locate($oversizedManifest, 'standalone-oversized-manifest'));
			$invalidManifest=$workspace->directory('standalone-invalid-manifest');
			$workspace->file('standalone-invalid-manifest/app.php', "<?php return [];\n");
			$workspace->file('standalone-invalid-manifest/dataphyre.app.json', '{not-json');
			$t->same(null, app_locator::locate($invalidManifest, 'standalone-invalid-manifest'));
			$t->same(
				str_replace('\\','/',(string)realpath($projectApplications.'/alpha/framework')),
				str_replace('\\','/',application_definition::from_conventions('alpha',$projectApplications.'/alpha')->autoload['alpha\\framework\\'])
			);

			$fromArray=application_definition::from_array([
				'id'=>'configured',
				'root_directory'=>$base.'/configured/',
				'rootpath_file'=>'rootpaths.php',
				'routes_file'=>'routes.php',
				'compiled_routes_file'=>'compiled.php',
				'framework_bootstrap_file'=>'framework.php',
				'legacy_bootstrap_file'=>'legacy.php',
				'autoload'=>['Configured\\'=>$base],
				'options'=>['fallback_to_legacy_bootstrap'=>false,'kept'=>1],
			],'fallback',$base.'/fallback');
			$t->same('configured',$fromArray->id);
			$t->same($base.'/configured',$fromArray->root_directory);
			$t->isFalse($fromArray->should_fallback_to_legacy_bootstrap());

			$defaults=application_definition::from_array([],'fallback',$base.'/fallback/');
			$t->same('fallback',$defaults->id);
			$t->same($base.'/fallback',$defaults->root_directory);
			$t->isTrue($defaults->should_fallback_to_legacy_bootstrap());

			$overridden=$fromArray->with_overrides([
				'id'=>'overridden',
				'root_directory'=>$base.'/overridden/',
				'rootpath_file'=>null,
				'routes_file'=>'new-routes.php',
				'compiled_routes_file'=>null,
				'framework_bootstrap_file'=>'new-framework.php',
				'legacy_bootstrap_file'=>null,
				'autoload'=>['Extra\\'=>$extraApplications],
				'options'=>['fallback_to_legacy_bootstrap'=>true,'added'=>2],
			]);
			$t->same('overridden',$overridden->id);
			$t->same(null,$overridden->rootpath_file);
			$t->same('new-routes.php',$overridden->routes_file);
			$t->same(['Configured\\'=>$base,'Extra\\'=>$extraApplications],$overridden->autoload);
			$t->same(['fallback_to_legacy_bootstrap'=>true,'kept'=>1,'added'=>2],$overridden->options);
			$t->isTrue($overridden->should_fallback_to_legacy_bootstrap());
			$t->same($overridden->id,$overridden->with_overrides([])->id);
	})->tag('core','kernel','coverage')->group('framework-coverage');

	test('core kernel autoloader covers policy gates direct paths scoped fallback and recursive basename maps',static function(Context $t): void {
		$t->state('core.kernel.catalog');
		$workspace=$t->workspace('core-kernel-autoload');
		$base=$workspace->root();
		$framework=$workspace->directory('Framework');
		$nested=$workspace->directory('Framework/Nested');
		$deeper=$workspace->directory('Framework/Deep/Again');
		$workspace->file('DirectThing.php','<?php namespace DpCoreDirect; final class DirectThing {}');
		$workspace->file('Framework/Nested/HiddenThing.php','<?php namespace DpCoreScoped; final class HiddenThing {}');
		$workspace->file('Framework/Duplicate.php','<?php return true;');
		$workspace->file('Framework/Deep/Again/Duplicate.php','<?php return true;');
		$workspace->file('Framework/ignored.txt','not php');
		$autoloaderInternals=$t->nonPublic(autoloader::class);
		foreach(['registered_module_roots','prefix_map','scoped_file_map'] as $property){
			$autoloaderInternals->replacePropertyForTest($property,$autoloaderInternals->readProperty($property));
		}

			autoloader::register(\Dataphyre\Test\dataphyre_path().'/runtime/modules/');
			$syntheticModules=$workspace->directory('module-root');
			$workspace->directory('module-root/noframework/kernel');
			dp_core_kernel_registry($t,['core'=>true,'sql'=>true,'noframework'=>true],['access'=>true]);
			autoloader::register($syntheticModules);
			$loaded=autoloader::register_framework_modules([' ','access','missing','core','sql','core']);
			$t->same(['core','sql'],$loaded);
			$t->same([],autoloader::register_framework_modules('noframework'));
			$t->isTrue(autoloader::framework_module_available('core'));
			$t->isFalse(autoloader::framework_module_available('access'));
			$t->same([],$autoloaderInternals->invoke('framework_prefixes_for_module','access'));
			$plain=$workspace->directory('plain');
			$t->same([],$autoloaderInternals->invoke('framework_prefixes',$plain));
			$t->same('',$autoloaderInternals->invoke('framework_namespace_segment',' '));
			$t->same('Database',$autoloaderInternals->invoke('framework_namespace_segment','sql'));
			$t->same('FulltextEngine',$autoloaderInternals->invoke('framework_namespace_segment','fulltext_engine'));

			$missingDirectory=$base.'/missing/Framework';
			$t->same([],$autoloaderInternals->invoke('build_scoped_file_map',$missingDirectory));
			$t->same(null,$autoloaderInternals->invoke('scoped_framework_file',$base,'HiddenThing.php'));
			$map=$autoloaderInternals->invoke('build_scoped_file_map',$framework.'/');
			$t->same(str_replace('\\','/',$framework).'/Duplicate.php',str_replace('\\','/',$map['Duplicate.php']));
			$t->same(str_replace('\\','/',$nested).'/HiddenThing.php',str_replace('\\','/',$map['HiddenThing.php']));
			$t->same(null,$autoloaderInternals->invoke('scoped_framework_file',$framework,'Missing.php'));
			$t->same(str_replace('\\','/',$nested).'/HiddenThing.php',str_replace('\\','/',(string)$autoloaderInternals->invoke('scoped_framework_file',$framework,'HiddenThing.php')));

			autoloader::register_prefixes([
				'DpCoreDirect\\'=>$base,
				'DpCoreScoped\\'=>$framework,
			]);
			$t->isTrue(class_exists('DpCoreDirect\\DirectThing'));
			$t->isTrue(class_exists('DpCoreScoped\\HiddenThing'));
			$t->isFalse(class_exists('DpCoreScoped\\DefinitelyMissing'));

			$coreKernel=$autoloaderInternals->invoke('kernel_prefixes',\Dataphyre\Test\dataphyre_path().'/runtime/modules/core');
			$t->isTrue(isset($coreKernel['dataphyre\\']));
			$sqlKernel=$autoloaderInternals->invoke('kernel_prefixes',\Dataphyre\Test\dataphyre_path().'/runtime/modules/sql');
			$t->isTrue(isset($sqlKernel['dataphyre\\sql\\']));
			$coreFramework=$autoloaderInternals->invoke('framework_prefixes',\Dataphyre\Test\dataphyre_path().'/runtime/modules/core');
			$t->isTrue(isset($coreFramework['Dataphyre\\']));
	})->tag('core','autoload','coverage')->group('framework-coverage');

	test('core kernel module registry covers policy caches presence metadata and definition catalogs',static function(Context $t): void {
		$t->state('core.kernel.catalog');
		dp_core_kernel_registry($t,['core'=>true,'sql'=>true,'missing_catalog_module'=>true],['access'=>true]);
		$t->same(['core','sql','missing_catalog_module'],module_registry::enabled_modules());
		$t->same(['access'],module_registry::disabled_modules());
		$t->isTrue(module_registry::module_enabled(' SQL '));
		$t->isFalse(module_registry::module_enabled('access'));
		$t->isFalse(module_registry::module_enabled(''));
		$t->isFalse(module_registry::module_enabled('../sql'));
		$t->isFalse(module_registry::module_metadata(''));
		$t->isFalse(module_registry::module_definition(''));
		$t->isFalse(module_registry::module_definition('access'));

		$core=module_registry::module_metadata('core');
		$t->type('array',$core);
		$t->same($core,module_registry::module_metadata('core'));
		$t->isFalse(module_registry::module_metadata('missing_catalog_module'));
		$t->isFalse(module_registry::module_metadata('missing_catalog_module'));
		$t->type('array',module_registry::kernel_module_present('core'));
		$t->type('string',module_registry::framework_module_present('core'));
		$t->type('array',module_registry::kernel_module_present('sql'));
		$t->isFalse(module_registry::framework_module_present('sql'));
		$t->isFalse(module_registry::kernel_module_present('missing_catalog_module'));

		$available=module_registry::available_modules();
		$t->contains('core',$available);
		$t->contains('sql',$available);
		$t->isFalse(in_array('missing_catalog_module',$available,true));
		$t->same($available,module_registry::available_modules());
		$t->same([],module_registry::module_definitions(false));
		$t->same(['core','sql'],array_keys(module_registry::module_definitions()));
		$t->same(['core','sql'],array_keys(module_registry::module_definitions(true)));
	})->tag('core','modules','coverage')->group('framework-coverage');

	test('legacy module discovery excludes explicit denies before kernel autoload registration',static function(Context $t): void {
		$t->state('core.kernel.catalog');
		dp_core_kernel_registry($t,[],['sql'=>true],true);
		$enabled=module_registry::enabled_modules();
		$t->contains('core',$enabled);
		$t->isFalse(in_array('sql',$enabled,true));
		$t->isFalse(module_registry::module_enabled('sql'));

		$autoloaderInternals=$t->nonPublic(autoloader::class)
			->replacePropertyForTest('registered_module_roots',[])
			->replacePropertyForTest('prefix_map',[])
			->replacePropertyForTest('scoped_file_map',[]);
		autoloader::register(\Dataphyre\Test\dataphyre_path().'/runtime/modules');
		$prefixes=$autoloaderInternals->readProperty('prefix_map');
		$t->isTrue(array_key_exists('dataphyre\\',$prefixes));
		$t->isFalse(array_key_exists('dataphyre\\sql\\',$prefixes));
	})->tag('core','modules','autoload','policy')->group('framework-coverage');

	test('core kernel module registry resolves synthetic common and application overlays plus helper edges',static function(Context $t): void {
		$state=$t->state('core.kernel.catalog');
		$overlay='test_kernel_overlay';
		$frameworkOnly='test_framework_only';
		$flat='test_flat_framework';
		$empty='test_empty_module';
		$missing='test_missing_module';
		$commonModules=rtrim((string)ROOTPATH['common_dataphyre_runtime'],'/\\').'/modules';
		$appModules=rtrim((string)ROOTPATH['dataphyre'],'/\\').'/modules';
		$commonOverlay=$commonModules.'/'.$overlay;
		$appOverlay=$appModules.'/'.$overlay;
		$appFrameworkOnly=$appModules.'/'.$frameworkOnly;
		$appFlat=$appModules.'/'.$flat;
		$appEmpty=$appModules.'/'.$empty;
		$virtualDirectory=static fn(string $path): string=>\dataphyre\dp_core_kernel_path($path);
		$state->put('virtual_directories',array_map($virtualDirectory,[
			$commonOverlay,
			$commonOverlay.'/kernel',
			$commonOverlay.'/Framework',
			$appOverlay,
			$appOverlay.'/kernel',
			$appOverlay.'/Framework',
			$appFrameworkOnly,
			$appFrameworkOnly.'/Framework',
			$appFlat,
			$appEmpty,
		]));
		$state->put('virtual_files',[
			$virtualDirectory($commonOverlay.'/kernel/'.$overlay.'.main.php')=>'<?php return true;',
			$virtualDirectory($commonOverlay.'/Framework/bootstrap.php')=>'<?php return true;',
			$virtualDirectory($commonOverlay.'/version')=>'2.5',
			$virtualDirectory($appOverlay.'/kernel/'.$overlay.'.main.php')=>'<?php return true;',
			$virtualDirectory($appOverlay.'/Framework/Bootstrap.php')=>'<?php return true;',
			$virtualDirectory($appFlat.'/framework.php')=>'<?php return true;',
		]);

		$enabled=[
			'core'=>true,
			$overlay=>true,
			$frameworkOnly=>true,
			$flat=>true,
			$empty=>true,
			$missing=>true,
		];
		$registryInternals=dp_core_kernel_registry($t,$enabled);
			$definition=module_registry::module_definition($overlay);
			$t->type('array',$definition);
			$t->same('1.0',$definition['version']);
			$t->same(str_replace('\\','/',$appOverlay).'/kernel/'.$overlay.'.main.php',str_replace('\\','/',$definition['kernel_entry']));
			$t->same(str_replace('\\','/',$commonOverlay).'/',str_replace('\\','/',$definition['common_directory']));
			$t->same(str_replace('\\','/',$appOverlay).'/',str_replace('\\','/',$definition['app_directory']));
			$t->same($definition,module_registry::module_definition($overlay));
			$t->type('array',module_registry::kernel_module_present($overlay));
			$t->type('string',module_registry::framework_module_present($overlay));

			$frameworkDefinition=module_registry::module_definition($frameworkOnly);
			$t->type('array',$frameworkDefinition);
			$t->same(null,$frameworkDefinition['kernel_entry']);
			$t->same(str_replace('\\','/',$appFrameworkOnly).'/',str_replace('\\','/',$frameworkDefinition['directory']));
			$t->isFalse(module_registry::kernel_module_present($frameworkOnly));
			$t->isFalse(module_registry::framework_module_present($frameworkOnly));

			$flatDefinition=module_registry::module_definition($flat);
			$t->type('array',$flatDefinition);
			$t->same(str_replace('\\','/',$appFlat).'/framework.php',str_replace('\\','/',$flatDefinition['framework_entry']));
			$t->isFalse(module_registry::module_definition($empty));
			$t->isFalse(module_registry::module_definition($missing));

			$available=module_registry::available_modules();
			$t->contains($overlay,$available);
			$t->contains($frameworkOnly,$available);
			$t->contains($flat,$available);
			$t->isFalse(in_array($empty,$available,true));
			$t->same($available,module_registry::available_modules());
			$t->same(['core',$overlay,$frameworkOnly,$flat],array_keys(module_registry::module_definitions()));
			$t->same([],module_registry::module_definitions(false));

			$t->same(null,$registryInternals->invoke('first_existing',[$appEmpty.'/nope.php']));
			$set=$registryInternals->invoke('normalize_module_set',[
				' Enabled-One ',
				'enabled_two'=>true,
				'ignored'=>false,
				'bad/path'=>true,
				'',
			]);
			$t->same(['enabled-one'=>true,'enabled_two'=>true],$set);
			$t->same('Dataphyre',$registryInternals->invoke('framework_namespace','core'));
			$t->same('Dataphyre\\Database',$registryInternals->invoke('framework_namespace','sql'));
			$t->same('Dataphyre\\FulltextEngine',$registryInternals->invoke('framework_namespace','fulltext_engine'));
	})->tag('core','modules','coverage')->group('framework-coverage')->maxMillis(10000);

	test('core kernel runtime covers definition formats dispatch selection legacy fallback and private boot helpers',static function(Context $t): void {
		$state=$t->state('core.kernel.catalog',[
			'force_rootpath_undefined'=>false,
			'rootpaths_primed'=>false,
			'framework_booted'=>0,
			'legacy_booted'=>0,
			'compiled_result'=>false,
			'compiled_files'=>[],
			'task_runner_dispatches'=>[],
		]);
		$workspace=$t->workspace('core-kernel-runtime');
		$base=$workspace->root();
		$project=$workspace->directory('project');
		$workspace->directory('project/applications');
		$files=$workspace->directory('files');
		$workspace->file('files/compiled.php','<?php return [];');
		$workspace->file(
			'files/rootpaths.php',
			'<?php \\Dataphyre\\Test\\TestState::channel("core.kernel.catalog")->put("rootpaths_primed",true);'
		);
		$workspace->file(
			'files/framework.php',
			'<?php \\Dataphyre\\Test\\TestState::channel("core.kernel.catalog")->increment("framework_booted");'
		);
		$workspace->file(
			'files/legacy.php',
			'<?php \\Dataphyre\\Test\\TestState::channel("core.kernel.catalog")->increment("legacy_booted");'
		);
		$workspace->file(
			'files/application_bootstrap.php',
			'<?php \\Dataphyre\\Test\\TestState::channel("core.kernel.catalog")->increment("legacy_booted");'
		);

		$makeApp=static fn(string $name): string=>$workspace->directory('project/applications/'.$name);
		$conventional=$makeApp('conventional');
		$object=$makeApp('object');
		$array=$makeApp('array');
		$invalid=$makeApp('invalid');
		$compiled=$makeApp('compiled');
		$framework=$makeApp('framework');
		$legacy=$makeApp('legacy');
		$noPath=$makeApp('no-path');
		$missingLegacy=$makeApp('missing-legacy');
		$workspace->file('project/applications/conventional/application_bootstrap.php','<?php return true;');
		$workspace->file('project/applications/object/app.php','<?php return new \\dataphyre\\application_definition("object",__DIR__);');
		$workspace->file('project/applications/array/app.php','<?php return ["id"=>"array-id","options"=>["fallback_to_legacy_bootstrap"=>false]];');
		$workspace->file('project/applications/invalid/app.php','<?php return 42;');
		$workspace->file('project/applications/compiled/app.php','<?php return '.var_export([
			'compiled_routes_file'=>$files.'/compiled.php',
			'rootpath_file'=>$files.'/rootpaths.php',
			'autoload'=>['DpRuntimeApp\\'=>$files],
			'options'=>['fallback_to_legacy_bootstrap'=>false],
		],true).';');
		$workspace->file('project/applications/framework/app.php','<?php return '.var_export([
			'framework_bootstrap_file'=>$files.'/framework.php',
			'options'=>['fallback_to_legacy_bootstrap'=>false],
		],true).';');
		$workspace->file('project/applications/legacy/app.php','<?php return '.var_export([
			'legacy_bootstrap_file'=>$files.'/legacy.php',
		],true).';');
		$workspace->file('project/applications/no-path/app.php','<?php return ["options"=>["fallback_to_legacy_bootstrap"=>false]];');

		$runtimeInternals=$t->nonPublic(runtime::class);
		$runtimeInternals
			->replacePropertyForTest('current_application_definition',runtime::current_application_definition())
			->replacePropertyForTest('current_project_root',runtime::current_project_root());

			$t->same(null,runtime::resolve_application_definition($project,'missing'));
			$t->throws(static fn()=>runtime::boot($project,'missing'),RuntimeException::class);
			$t->same('conventional',runtime::resolve_application_definition($project,'conventional')?->id);
			$t->same('object',runtime::resolve_application_definition($project,'object')?->id);
			$t->same('array-id',runtime::resolve_application_definition($project,'array')?->id);
			$t->throws(static fn()=>runtime::resolve_application_definition($project,'invalid'),RuntimeException::class);

			$compiledDefinition=new application_definition('private',$files,$files.'/rootpaths.php',null,$files.'/compiled.php');
			$t->isFalse($runtimeInternals->invoke('boot_compiled_routes',$compiledDefinition));
			$noneDefinition=new application_definition('none',$files);
			$frameworkDefinition=new application_definition('framework',$files,null,null,null,$files.'/framework.php');
			$t->isFalse($runtimeInternals->invoke('boot_compiled_routes',$noneDefinition));
			$t->isFalse($runtimeInternals->invoke('boot_framework_application',$noneDefinition));
			$t->isTrue($runtimeInternals->invoke('boot_framework_application',$frameworkDefinition));

			$runtimeRoot=$t->rootpathWorkspace('dataphyre')->reset();
			$runtimeRoot->file('cache/verified','verified');
			$runtimeRoot->file('config/static/dpvk',str_repeat('k',64));
			if(!defined('CFG')){
				define('CFG',[]);
			}
			$bootstrapSymbols=[];
			if(!function_exists('tracelog')){
				$bootstrapSymbols[]='function tracelog(mixed ...$arguments): void {}';
			}
			if(!function_exists('pre_init_error')){
				$bootstrapSymbols[]='function pre_init_error(?string $message=null, ?object $exception=null, ?bool $fromUnavailable=false): never { throw new \\RuntimeException((string)$message,0,$exception instanceof \\Throwable ? $exception : null); }';
			}
			if($bootstrapSymbols!==[]){
				\Dataphyre\Test\define_test_symbols(implode("\n",$bootstrapSymbols));
			}
			dp_core_kernel_registry($t,['core'=>true]);
			$state->put('force_rootpath_undefined',true);
			$runtimeInternals->invoke('prime_rootpaths',$compiledDefinition);
			$state->put('force_rootpath_undefined',false);
			$t->isTrue($state->get('rootpaths_primed'));

			if(!class_exists('dataphyre\\routing\\compiled_route_dispatcher',false)){
				\Dataphyre\Test\define_test_symbols('namespace dataphyre\\routing; final class compiled_route_dispatcher { public static function dispatch_file(string $file): bool { $state=\\Dataphyre\\Test\\TestState::channel("core.kernel.catalog"); $state->append("compiled_files",$file); return $state->get("compiled_result",false)===true; } }');
			}
			$state->put('compiled_result',false);
			$t->isFalse($runtimeInternals->invoke('boot_compiled_routes',$compiledDefinition));
			$state->put('compiled_result',true);
			$t->isTrue($runtimeInternals->invoke('boot_compiled_routes',$compiledDefinition));

			$runtimeInternals->invoke('boot_legacy_application',$files,null);
			$runtimeInternals->invoke('boot_legacy_application',$files,new application_definition('legacy',$files,null,null,null,null,$files.'/legacy.php'));
			$t->throws(static fn()=>$runtimeInternals->invoke('boot_legacy_application',$base.'/absent',null),RuntimeException::class);
			$runtimeInternals->invoke('register_application_autoload',new application_definition('none',$files));
			$runtimeInternals->invoke('register_application_autoload',new application_definition('autoload',$files,null,null,null,null,null,['DpRuntimePrivate\\'=>$files]));

			$state->put('compiled_result',true);
			runtime::boot($project,'compiled');
			$t->same('compiled',runtime::current_application_definition()?->id);
			$t->same(realpath($project),realpath((string)runtime::current_project_root()));
			$t->contains($files.'/compiled.php',$state->get('compiled_files'));

			$state->put('compiled_result',false);
			runtime::boot($project,'framework');
			$t->isTrue($state->get('framework_booted')>=2);
			runtime::boot($project,'legacy');
			$t->isTrue($state->get('legacy_booted')>=3);
			$t->throws(static fn()=>runtime::boot($project,'no-path'),RuntimeException::class);
			$t->throws(static fn()=>runtime::boot($project,'missing-legacy'),RuntimeException::class);
	})->tag('core','runtime','coverage')->group('framework-coverage')->sandboxesRootpath('dataphyre')->maxMillis(10000);
}
