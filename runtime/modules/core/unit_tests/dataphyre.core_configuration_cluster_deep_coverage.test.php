<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Application;
use Dataphyre\ApplicationCatalog;
use Dataphyre\Bootstrap;
use Dataphyre\BootstrapCatalog;
use Dataphyre\BootstrapPlan;
use Dataphyre\Config;
use Dataphyre\ConfigRepository;
use Dataphyre\ConfigSnapshot;
use Dataphyre\Env;
use Dataphyre\EnvRepository;
use Dataphyre\EnvSnapshot;
use Dataphyre\Test\Context;
use Dataphyre\UrlValue;
use dataphyre\application_definition;
use dataphyre\runtime as KernelRuntime;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('CFG')){
	define('CFG',new class implements ArrayAccess {
		/** @var array<string,mixed> */
		private array $data=[];
		/** @return array<string,mixed> */
		public function &raw(): array { return $this->data; }
		public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset,$this->data); }
		public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
		public function offsetSet(mixed $offset,mixed $value): void { $this->data[(string)$offset]=$value; }
		public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
	});
}

$dp_core_cluster_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dp_core_cluster_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_core_cluster_modules_root);
\dataphyre\autoloader::register_framework_modules(['core']);
require_once $dp_core_cluster_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_core_cluster_modules_root.'/core/kernel/core_functions.php';

test('core configuration cluster repositories cover root scoped mutation projection and snapshots',static function(Context $t): void {
	$config=&\dataphyre\core::config_all();
	$original=$config;
	$config=[
		'root'=>[
			'nested'=>['leaf'=>'value','null'=>null],
			'empty'=>[],
			'scalar'=>'text',
		],
		'flat/path'=>'direct',
		'top'=>'kept',
	];

	try{
		$repository=Config::repository(null);
		$t->instanceOf(ConfigRepository::class,$repository);
		$t->same(null,$repository->path());
		$t->isTrue($repository->exists());
		$t->same($config,$repository->value());
		$t->same($config,$repository->get('   '));
		$t->same('value',$repository->get('root/nested/leaf'));
		$t->same('fallback',$repository->get('root/missing','fallback'));
		$t->isTrue($repository->has());
		$t->isTrue($repository->has('root/nested/null'));
		$t->isFalse($repository->has('root/missing'));
		$t->isFalse($repository->set('',42));
		$t->isTrue($repository->set(['merged'=>['one'=>1]]));
		$t->isTrue($repository->set('written/deep',2));
		$t->isTrue($repository->merge(['merged'=>['two'=>2]]));
		$t->same(2,$repository->get('written/deep'));
		$t->same(['one'=>1,'two'=>2],$repository->get('merged'));
		$t->same(
			['root/nested/null'=>null,'flat/path'=>'direct'],
			$repository->only([' ','root/nested/null','missing','flat/path'])
		);
		$t->contains('root',$repository->keys());
		$t->isFalse($repository->isEmpty());
		$t->same($repository,$repository->scope(null));
		$t->same($repository,$repository->scope('///'));
		$emptyPathRepository=new ConfigRepository('');
		$t->isTrue($emptyPathRepository->merge(['empty_path_merge'=>true]));
		$t->contains('empty_path_merge',$emptyPathRepository->keys());

		$scoped=$repository->scope('/root/');
		$t->same('root',$scoped->path());
		$t->isTrue($scoped->exists());
		$t->same('value',$scoped->get('/nested/leaf/'));
		$t->isTrue($scoped->has('nested/null'));
		$t->same(['nested','empty','scalar'],$scoped->keys());
		$t->same(
			['nested'=>['null'=>null],'empty'=>[],'scalar'=>'text'],
			$scoped->except(['','missing','scalar/deeper','nested/missing','nested/leaf'])
		);

		$writer=$repository->scope('writer');
		$t->isFalse($writer->exists());
		$t->isTrue($writer->set(' ',['base'=>1]));
		$t->isTrue($writer->set(['merged'=>2]));
		$t->isTrue($writer->set('deep/value',3));
		$t->isTrue($writer->merge(['extra'=>4]));
		$t->same(['base'=>1,'merged'=>2,'deep'=>['value'=>3],'extra'=>4],$writer->all());

		$missing=$repository->scope('missing');
		$t->isFalse($missing->exists());
		$t->same('fallback',$missing->value('fallback'));
		$t->same([],$missing->keys());
		$t->isTrue($missing->isEmpty());
		$t->same([],$missing->all());
		$t->same(['path'=>'missing','exists'=>false,'value'=>null],$missing->toArray());
		$t->same($missing->toArray(),$missing->jsonSerialize());

		$scalar=$repository->scope('root/scalar');
		$t->same([],$scalar->all());
		$t->same([],$scalar->keys());
		$t->isFalse($scalar->isEmpty());
		$t->isTrue($repository->scope('root/empty')->isEmpty());
		$t->isTrue($repository->scope('root/nested/null')->isEmpty());

		$beforeEmpty=$config;
		$config=[];
		$t->isTrue($repository->isEmpty());
		$config=$beforeEmpty;

		$repositorySnapshot=$scoped->snapshot();
		$t->instanceOf(ConfigSnapshot::class,$repositorySnapshot);
		$t->same('root',$repositorySnapshot->path());
		$t->isTrue($repositorySnapshot->exists());

		$snapshot=new ConfigSnapshot('base',true,[
			'nested'=>['leaf'=>'value','null'=>null],
			'empty'=>[],
			'scalar'=>'text',
		]);
		$t->same('base',$snapshot->path());
		$t->isTrue($snapshot->exists());
		$t->same($snapshot->all(),$snapshot->value('fallback'));
		$t->same($snapshot->all(),$snapshot->get(''));
		$t->same('value',$snapshot->get('nested//leaf'));
		$t->same('fallback',$snapshot->get('nested/missing','fallback'));
		$t->isTrue($snapshot->has());
		$t->isTrue($snapshot->has('nested/null'));
		$t->isFalse($snapshot->has('nested/missing'));
		$t->same(
			['nested/leaf'=>'value','nested/null'=>null],
			$snapshot->only(['','nested/leaf','nested/null','missing'])
		);
		$t->same(['nested'=>['null'=>null],'empty'=>[],'scalar'=>'text'],$snapshot->except([
			'', 'missing', 'scalar/deeper', 'nested/missing', 'nested/leaf',
		]));
		$t->same(['nested','empty','scalar'],$snapshot->keys());
		$t->isFalse($snapshot->isEmpty());
		$t->same($snapshot,$snapshot->scope(null));
		$t->same($snapshot,$snapshot->scope('///'));
		$nested=$snapshot->scope('/nested/');
		$t->same('base/nested',$nested->path());
		$t->isTrue($nested->exists());
		$t->same('value',$nested->get('leaf'));
		$nestedMissing=$snapshot->scope('nested/missing');
		$t->isFalse($nestedMissing->exists());
		$t->same(null,$nestedMissing->value());
		$t->producesStableResult(static fn()=>$snapshot->toArray());
		$t->same($snapshot->toArray(),$snapshot->jsonSerialize());

		$rootSnapshot=new ConfigSnapshot(null,true,['child'=>['value'=>1]]);
		$t->same('child',$rootSnapshot->scope('child')->path());
		$scalarSnapshot=new ConfigSnapshot('scalar',true,'text');
		$t->same('fallback',$scalarSnapshot->get('child','fallback'));
		$t->isFalse($scalarSnapshot->has('child'));
		$t->same([],$scalarSnapshot->all());
		$t->same([],$scalarSnapshot->keys());
		$t->isFalse($scalarSnapshot->isEmpty());
		$t->isTrue((new ConfigSnapshot('empty',true,[]))->isEmpty());
		$t->isTrue((new ConfigSnapshot('null',true,null))->isEmpty());
		$absent=new ConfigSnapshot('absent',false,['ignored'=>true]);
		$t->isFalse($absent->exists());
		$t->same('fallback',$absent->value('fallback'));
		$t->isTrue($absent->isEmpty());
		$t->isFalse($absent->has());

		$getPath=$t->nonPublic(ConfigSnapshot::class);
		$rootLookup=$getPath->capture('getPath',value:['a'=>['b'=>1]],path:[],exists:null);
		$t->same(['a'=>['b'=>1]],$rootLookup->result());
		$t->isTrue($rootLookup->argument('exists'));
		$leafLookup=$getPath->capture('getPath',value:['a'=>['b'=>1]],path:['a','b'],exists:null);
		$t->same(1,$leafLookup->result());
		$t->isTrue($leafLookup->argument('exists'));
		$blockedLookup=$getPath->capture('getPath',value:['a'=>'scalar'],path:['a','b'],exists:null);
		$t->same(null,$blockedLookup->result());
		$t->isFalse($blockedLookup->argument('exists'));
	}finally{
		$config=$original;
	}
})->tag('core','config','repository','snapshot','coverage')->group('framework-coverage');

test('core configuration cluster environment repository covers root custom and nested scopes',static function(Context $t): void {
	$original=Env::all();
	Env::forget(array_keys($original));

	try{
		$repository=Env::repository(null);
		$t->instanceOf(EnvRepository::class,$repository);
		$t->same(null,$repository->prefix());
		$t->same('/',$repository->separator());
		$t->same([],$repository->all());
		$t->isFalse($repository->has());
		$t->isTrue($repository->isEmpty());
		$t->same('fallback',$repository->get(' ','fallback'));
		$repository->set(' ',1);
		$repository->set([' '=>1]);
		$repository->merge([''=>1]);
		$repository->forget([' ','']);
		$t->same([],$repository->only([' ','']));
		$t->same([],$repository->only(['missing']));

		$repository->set('/A/',1);
		$repository->set([' B '=>2,''=>3,1=>'numeric']);
		$repository->merge([' C '=>3,' '=>4]);
		$t->same(1,$repository->get('A'));
		$t->isTrue($repository->has('A'));
		$t->isTrue($repository->has());
		$t->isFalse($repository->isEmpty());
		$t->same(['A'=>1,'B'=>2],$repository->only([' A ','B','missing','']));
		$t->same(['A'=>1,1=>'numeric','C'=>3],$repository->except([' B ' ]));
		$t->contains('A',$repository->keys());
		$t->same(3,$repository->pull('C','fallback'));
		$t->same('fallback',$repository->pull('missing','fallback'));
		$repository->forget(['B',' ','missing']);
		$t->isFalse($repository->has('B'));
		$t->same($repository,$repository->scope(null));
		$t->same($repository,$repository->scope('///'));

		$scoped=$repository->scope('/APP/');
		$t->same('APP',$scoped->prefix());
		$t->same('/',$scoped->separator());
		$scoped->set('ONE',1);
		$scoped->set(['TWO'=>2,' '=>3]);
		$scoped->merge(['THREE'=>3]);
		Env::set('OTHER',9);
		$t->same(['ONE'=>1,'TWO'=>2,'THREE'=>3],$scoped->all());
		$t->same(['ONE','TWO','THREE'],$scoped->keys());
		$t->isTrue($scoped->has());
		$t->same(2,$scoped->get('/TWO/'));
		$t->same(['ONE'=>1,'THREE'=>3],$scoped->except([' TWO ' ]));
		$t->same(['THREE'=>3],$scoped->only(['THREE','missing','']));
		$scoped->forget('TWO');
		$t->isFalse($scoped->has('TWO'));
		$scoped->forget(['ONE',' ','missing']);
		$t->same(['THREE'=>3],$scoped->all());

		$nested=$scoped->scope('/NESTED/');
		$t->same('APP/NESTED',$nested->prefix());
		$nested->set('VALUE',4);
		$t->same(4,$nested->get('VALUE'));
		$missing=$repository->scope('ZZ');
		$t->isFalse($missing->has());
		$t->isTrue($missing->isEmpty());
		$t->same([],$missing->all());
		$t->same([],$missing->keys());

		$emptyPrefix=new EnvRepository('', '/');
		$emptyPrefix->set('/ROOTED/',5);
		$t->same(5,$emptyPrefix->get('ROOTED'));
		$custom=Env::repository('.SERVICE.','.');
		$t->same('SERVICE',$custom->prefix());
		$t->same('.',$custom->separator());
		$custom->set('.HOST.','example.test');
		$customNested=$custom->scope('.API.');
		$customNested->set('TOKEN','redacted');
		$t->same('SERVICE.API',$customNested->prefix());
		$t->same('redacted',$customNested->get('TOKEN'));

		$snapshot=$scoped->snapshot();
		$t->instanceOf(EnvSnapshot::class,$snapshot);
		$t->same('APP',$snapshot->prefix());
		$t->same(['THREE'=>3,'NESTED/VALUE'=>4],$snapshot->all());
		$t->same(
			['prefix'=>'APP','separator'=>'/','values'=>['THREE'=>3,'NESTED/VALUE'=>4]],
			$scoped->toArray()
		);
		$t->same($scoped->toArray(),$scoped->jsonSerialize());
	}finally{
		Env::forget(array_keys(Env::all()));
		Env::set($original);
	}
})->tag('core','env','repository','coverage')->group('framework-coverage');

test('core configuration cluster url values cover parsing immutable mutations and diagnostics',static function(Context $t): void {
	$url=UrlValue::fromString('HTTPS://user:pass@example.test:8443/path/to?q=one&arr[]=a#frag');
	$t->instanceOf(UrlValue::class,$url);
	$t->same('HTTPS://user:pass@example.test:8443/path/to?q=one&arr[]=a#frag',$url->raw());
	$t->same($url->raw(),(string)$url);
	$t->same('HTTPS',$url->scheme());
	$t->same('example.test',$url->host());
	$t->same(8443,$url->port());
	$t->same('user',$url->user());
	$t->same('pass',$url->pass());
	$t->same('/path/to',$url->path());
	$t->same('frag',$url->fragment());
	$t->same(['q'=>'one','arr'=>['a']],$url->query());
	$t->isTrue($url->hasQuery(' q '));
	$t->isFalse($url->hasQuery(' '));
	$t->isFalse($url->hasQuery('missing'));
	$t->same('one',$url->queryValue(' q ','fallback'));
	$t->same('fallback',$url->queryValue('','fallback'));
	$t->same('fallback',$url->queryValue('missing','fallback'));
	$t->isTrue($url->isAbsolute());
	$t->isTrue($url->isSecure());
	$t->same('HTTPS://user:pass@example.test:8443/path/to',$url->base());

	$mutation=new UrlValue('https://example.test/path?q=one&keep=yes#frag');
	$withQuery=$mutation->withQuery(['added'=>'two words'],['q']);
	$t->contains('added=two+words',$withQuery->raw());
	$t->isFalse($withQuery->hasQuery('q'));
	$t->contains('keep=yes',$withQuery->raw());
	$t->notContains('q=one',$mutation->withoutQuery(['q'])->raw());
	$t->same('https://example.test/path?',$mutation->withoutQuery(true)->raw());
	$t->same($mutation->raw(),$mutation->withQuery(null,false)->raw());

	$relativePath=$url->withPath('next');
	$t->contains('example.test:8443/next?',$relativePath->raw());
	$t->same('/next',$url->withPath('/next')->path());
	$t->same('new',$url->withFragment('#new#')->fragment());
	$t->same(null,$url->withFragment(null)->fragment());
	$t->same(null,$url->withFragment('   ')->fragment());
	$t->same('frag',$url->fragment());

	$diagnostics=$url->toArray();
	$t->same('HTTPS',$diagnostics['scheme']);
	$t->same('example.test',$diagnostics['host']);
	$t->same(8443,$diagnostics['port']);
	$t->same('user',$diagnostics['user']);
	$t->isFalse(array_key_exists('pass',$diagnostics));
	$t->isTrue($diagnostics['is_absolute']);
	$t->isTrue($diagnostics['is_secure']);
	$t->hasConsistentSerialization($url,$diagnostics);

	$relative=new UrlValue('relative/path?x=1');
	$t->same(null,$relative->scheme());
	$t->same(null,$relative->host());
	$t->same(null,$relative->port());
	$t->same(null,$relative->user());
	$t->same(null,$relative->pass());
	$t->same('relative/path',$relative->path());
	$t->isFalse($relative->isAbsolute());
	$t->isFalse($relative->isSecure());
	$t->same('relative/path',$relative->base());
	$t->same('changed',$relative->withPath('changed')->path());

	$protocolRelative=new UrlValue('//example.test/path');
	$t->isTrue($protocolRelative->isAbsolute());
	$t->same('//example.test/path',$protocolRelative->base());
	$t->same('//example.test',$protocolRelative->withPath('')->base());
	$schemeOnly=new UrlValue('mailto:user@example.test');
	$t->same('mailto',$schemeOnly->scheme());
	$t->same('user@example.test',$schemeOnly->path());
	$t->isTrue($schemeOnly->isAbsolute());

	$invalid=new UrlValue('http:///bad');
	$t->same('http:///bad',$invalid->path());
	$t->same('http:///bad',$invalid->base());
	$empty=new UrlValue('');
	$t->same('',$empty->path());
	$t->same('',$empty->base());
	$t->same([],$empty->query());

	$urlInternals=$t->nonPublic($url);
	$t->same(null,$urlInternals->invoke('stringPart','port'));
	$t->same(null,$urlInternals->invoke('stringPart','missing'));
	$t->same('//user@example.test/path?x=1#f',$t->nonPublic(UrlValue::class)->invoke('build',[
		'host'=>' example.test ','user'=>'user','path'=>'path','query'=>'x=1','fragment'=>'#f#',
	]));
})->tag('core','url','value','coverage')->group('framework-coverage');

test('core configuration cluster applications and bootstrap plans cover discovery modes and safe boot',static function(Context $t): void {
	$state=$t->state('core.configuration.cluster',[
		'alpha_booted'=>false,
		'alpha_legacy'=>false,
		'booted'=>false,
	]);
	$t->setEnvironmentForTest(['DATAPHYRE_APPLICATION_ROOTS'=>null]);
	$runtime=$t->nonPublic(KernelRuntime::class);
	$runtime
		->replacePropertyForTest('current_application_definition',null)
		->replacePropertyForTest('current_project_root',null);

	$workspace=$t->workspace('core-configuration-cluster');
	$base=$workspace->root();
	$project=$workspace->directory('project');
	$applications=$workspace->directory('project/applications');
	$alpha=$workspace->directory('project/applications/alpha');
	$bootable=$workspace->directory('project/applications/bootable');
	$files=$workspace->directory('files');
	$workspace->directory('project/applications/alpha/framework');
	$workspace->directory('applications/alpha');
	$workspace->directory('applications/beta');
	$workspace->file('project/applications/alpha/rootpaths.php','<?php return true;');
	$workspace->file('project/applications/alpha/routes.php','<?php return [];');
	$workspace->file('project/applications/alpha/backend/dataphyre/cache/routes.compiled.php','<?php return [];');
	$workspace->file(
		'project/applications/alpha/framework_bootstrap.php',
		'<?php \\Dataphyre\\Test\\TestState::channel("core.configuration.cluster")->put("alpha_booted",true);'
	);
	$workspace->file(
		'project/applications/alpha/application_bootstrap.php',
		'<?php \\Dataphyre\\Test\\TestState::channel("core.configuration.cluster")->put("alpha_legacy",true);'
	);
	$workspace->file('project/applications/alpha/not-an-app.txt','file');
	$workspace->file(
		'project/applications/bootable/framework_bootstrap.php',
		'<?php \\Dataphyre\\Test\\TestState::channel("core.configuration.cluster")->put("booted",true);'
	);
	$workspace->file('project/applications/not-a-directory.txt','file');
	foreach(['rootpaths.php','routes.php','compiled.php','framework.php','legacy.php'] as $file){
		$workspace->file('files/'.$file,'<?php return true;');
	}

		$kernelDefinition=new application_definition(
			'kernel',$files,$files.'/rootpaths.php',$files.'/routes.php',$files.'/compiled.php',
			$files.'/framework.php',$files.'/legacy.php',['Kernel\\'=>$files],['flag'=>null]
		);
		$application=Application::fromDefinition($kernelDefinition);
		$t->same('kernel',$application->id);
		$t->same($files,$application->root_directory);
		$t->isTrue($application->hasRootpathFile());
		$t->isTrue($application->hasRoutesFile());
		$t->isTrue($application->hasCompiledRoutes());
		$t->isTrue($application->hasFrameworkBootstrap());
		$t->isTrue($application->hasLegacyBootstrap());
		$t->isTrue($application->hasAutoload());
		$t->same(['Kernel\\'=>$files],$application->autoloadPrefixes());
		$t->isTrue($application->hasOption('flag'));
		$t->same(null,$application->option('flag','fallback'));
		$t->same('fallback',$application->option('missing','fallback'));
		$t->same('compiled_routes',$application->bootMode());
		$t->isTrue($application->canBoot());

		$runtime->writeProperty('current_application_definition',$kernelDefinition);
		$runtime->writeProperty('current_project_root',$project);
		$t->same('kernel',Application::current()?->id);
		$t->same('kernel',Application::current(null,'kernel')?->id);
		$t->same(null,Application::current('', 'other'));

		$runtime->writeProperty('current_application_definition',null);
		$runtime->writeProperty('current_project_root',null);
		$t->same('alpha',Application::current($project,'alpha')?->id);
		$t->same(null,Application::current($project,''));
		$t->same(null,Application::discover('', $project));
		$t->same(null,Application::discover('alpha',''));
		$t->same(null,Application::discover('missing',$project));
		$discovered=Application::discover('alpha',$project);
		$t->instanceOf(Application::class,$discovered);
		$t->same('alpha',$discovered->id);
		$t->isTrue(Application::exists('alpha',$project));
		$t->isFalse(Application::exists('missing',$project));
		$t->isTrue($discovered->hasRootpathFile());
		$t->isTrue($discovered->hasRoutesFile());
		$t->isTrue($discovered->hasCompiledRoutes());
		$t->isTrue($discovered->hasFrameworkBootstrap());
		$t->isTrue($discovered->hasLegacyBootstrap());
		$t->isTrue($discovered->hasAutoload());

		$many=Application::discoverMany([' ','alpha','missing','beta'],$project);
		$t->instanceOf(ApplicationCatalog::class,$many);
		$t->isTrue($many->has('alpha'));
		$t->isTrue($many->has('beta'));
		$t->isFalse($many->has('missing'));
		$t->isTrue(Application::discoverMany('alpha',$project)->has('alpha'));
		$t->same([],Application::roots(''));
		$t->same(2,count(Application::roots($project)));
		$available=Application::available($project);
		$t->contains('alpha',$available);
		$t->contains('beta',$available);
		$t->contains('bootable',$available);
		$emptyProject=$workspace->directory('isolated/child');
		$t->same([],Application::available($emptyProject));
		$catalog=Application::catalog($project);
		$t->isTrue($catalog->has('alpha'));
		$t->isTrue($catalog->has('beta'));

		$legacy=Application::legacy('legacy',$files,[
			'routes_file'=>$files.'/routes.php',
			'legacy_bootstrap_file'=>$files.'/legacy.php',
			'autoload'=>['Legacy\\'=>$files],
			'options'=>['fallback_to_legacy_bootstrap'=>true,'custom'=>'value'],
		]);
		$t->same($files.'/rootpaths.php',$legacy->rootpath_file);
		$t->same($files.'/legacy.php',$legacy->legacy_bootstrap_file);
		$t->same('value',$legacy->option('custom'));
		$t->same('legacy',$legacy->bootMode());
		$t->isTrue($legacy->fallbackToLegacyBootstrap());
		$t->same($legacy->toArray(),$legacy->jsonSerialize());

		$framework=new Application(
			'framework',$files,null,null,null,$files.'/framework.php',$files.'/legacy.php',[],[]
		);
		$t->isFalse($framework->hasRootpathFile());
		$t->isFalse($framework->hasRoutesFile());
		$t->isFalse($framework->hasCompiledRoutes());
		$t->isFalse($framework->hasAutoload());
		$t->same('framework',$framework->bootMode());
		$t->isTrue($framework->canBoot());
		$disabledLegacy=new Application(
			'disabled',$files,null,null,null,null,$files.'/legacy.php',[],['fallback_to_legacy_bootstrap'=>false]
		);
		$t->isFalse($disabledLegacy->fallbackToLegacyBootstrap());
		$t->same(null,$disabledLegacy->bootMode());
		$t->isFalse($disabledLegacy->canBoot());
		$none=new Application('none',$files,null,null,null,null,null,[],[]);
		$t->isFalse($none->hasLegacyBootstrap());
		$t->same(null,$none->bootMode());
		$t->isFalse($none->canBoot());

		$plan=$application->bootstrapPlan($project.'/');
		$t->instanceOf(BootstrapPlan::class,$plan);
		$t->same(realpath($project),$plan->projectRoot());
		$t->same($application,$plan->application());
		$t->same('kernel',$plan->applicationId());
		$t->same('compiled_routes',$plan->bootMode());
		$t->isTrue($plan->canBoot());
		$t->isTrue($plan->usesCompiledRoutes());
		$t->isFalse($plan->usesFrameworkBootstrap());
		$t->isFalse($plan->usesLegacyBootstrap());
		$t->isTrue($plan->fallbackToLegacyBootstrap());
		$t->isTrue($plan->hasRootpathFile());
		$t->isFalse($plan->rootpathPrimingRequired());
		$t->same(['Kernel\\'=>$files],$plan->autoloadPrefixes());
		$t->same([
			'compiled_routes'=>$files.'/compiled.php',
			'framework'=>$files.'/framework.php',
			'legacy'=>$files.'/legacy.php',
		],$plan->bootPaths());
		$t->same(['compiled_routes','framework','legacy'],$plan->availableBootModes());
		$t->same([],$plan->missingBootModes());
		$t->same('compiled_routes',$plan->summary()['boot_mode']);
		$t->producesStableResult(static fn()=>$plan->summary());
		$t->same($plan->toArray(),$plan->jsonSerialize());

		$frameworkPlan=$framework->bootstrapPlan($project);
		$t->isFalse($frameworkPlan->usesCompiledRoutes());
		$t->isTrue($frameworkPlan->usesFrameworkBootstrap());
		$t->isFalse($frameworkPlan->usesLegacyBootstrap());
		$t->same('framework',$frameworkPlan->summary()['boot_mode']);
		$t->same('framework',$frameworkPlan->toArray()['summary']['boot_mode']);
		$legacyPlan=$legacy->bootstrapPlan($project);
		$t->isTrue($legacyPlan->usesLegacyBootstrap());
		$t->same('legacy',$legacyPlan->summary()['boot_mode']);
		$t->same('legacy',$legacyPlan->toArray()['summary']['boot_mode']);
		$nonePlan=$none->bootstrapPlan($project);
		$t->same(['compiled_routes','framework','legacy'],$nonePlan->missingBootModes());
		$t->same([],$nonePlan->bootPaths());
		$t->same(null,$nonePlan->summary()['boot_mode']);
		$t->isFalse($nonePlan->summary()['can_boot']);
		$t->same(null,$nonePlan->toArray()['summary']['boot_mode']);
		$t->throws(static fn()=>$nonePlan->boot(),RuntimeException::class);
		$t->throws(static fn()=>(new BootstrapPlan(null,$application))->boot(),RuntimeException::class);

		$bootstrapPlanInternals=$t->nonPublic(BootstrapPlan::class);
		$t->same(null,$bootstrapPlanInternals->invoke('normalizeProjectRoot',null));
		$t->same(null,$bootstrapPlanInternals->invoke('normalizeProjectRoot','   '));
		$t->same(realpath($project),$bootstrapPlanInternals->invoke('normalizeProjectRoot',$project.'/'));
		$t->same('C:/does-not-exist',$bootstrapPlanInternals->invoke('normalizeProjectRoot',' C:/does-not-exist/ '));
		$applicationInternals=$t->nonPublic(Application::class);
		$t->same(null,$applicationInternals->invoke('normalizeProjectRoot',''));
		$t->same(realpath($project),$applicationInternals->invoke('normalizeProjectRoot',$project.'/'));
		$t->same('C:/does-not-exist',$applicationInternals->invoke('normalizeProjectRoot',' C:/does-not-exist/ '));
		$t->type('string',$applicationInternals->invoke('defaultApplicationName'));

		$runtime->writeProperty('current_project_root',$project.'/');
		$t->same(realpath($project),BootstrapPlan::fromApplication($application)->projectRoot());
		$runtime->writeProperty('current_project_root',null);
		$runtime->writeProperty('current_application_definition',null);
		$bootApplication=Application::discover('bootable',$project);
		$t->instanceOf(Application::class,$bootApplication);
		$state->put('booted',false);
		$bootApplication->bootstrapPlan($project)->boot();
		$t->isTrue($state->get('booted'));
		$t->instanceOf(BootstrapPlan::class,Bootstrap::current($project,'bootable'));
		$t->instanceOf(BootstrapPlan::class,Bootstrap::resolve('bootable',$project));
		$t->instanceOf(BootstrapPlan::class,Bootstrap::for('bootable',$project));
		$bootstrapCatalog=Bootstrap::catalog($project);
		$t->instanceOf(BootstrapCatalog::class,$bootstrapCatalog);
		$t->isTrue($bootstrapCatalog->has('bootable'));
		$state->put('booted',false);
		$t->same('bootable',Bootstrap::boot($bootApplication,$project)->applicationId());
		$t->isTrue($state->get('booted'));
})->tag('core','application','bootstrap','coverage')->group('framework-coverage')->maxMillis(10000);
