<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelProvider;
use Dataphyre\Panel\PanelRegistry;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel registry context scopes nested configuration and restores on failure',static function(Context $t): void {
	$t->same('fallback',PanelContext::config('missing','fallback'));
	$t->isFalse(PanelContext::has('name'));
	$t->same([],PanelContext::all());
	$result=PanelContext::run([' name '=>'outer',''=>'ignored',7=>'numeric'],static function()use($t): string {
		$t->same('outer',PanelContext::config('name'));
		$t->isTrue(PanelContext::has('name'));
		$t->same('numeric',PanelContext::config('7'));
		return PanelContext::run(['name'=>'inner','extra'=>true],static function()use($t): string {
			$t->same('inner',PanelContext::config('name'));
			$t->same(['name'=>'inner','7'=>'numeric','extra'=>true],PanelContext::all());
			return 'done';
		});
	});
	$t->same('done',$result);
	$t->same([],PanelContext::all());
	try{
		PanelContext::run(['temporary'=>true],static function(): never { throw new RuntimeException('context failed'); });
	}
	catch(RuntimeException $exception){
		$t->same('context failed',$exception->getMessage());
	}
	$t->isFalse(PanelContext::has('temporary'));
	$t->same(['key'=>'value'],$t->nonPublic(PanelContext::class)->invoke('normalize',[' key '=>'value',' '=>'ignored']));
})->tag('panel','registry','context','coverage')->group('framework-coverage');

test('panel registry creates registers looks up and describes isolated surfaces',static function(Context $t): void {
	PanelRegistry::flush();
	PanelManager::flush();
	$default=PanelRegistry::surface(' ');
	$t->same('default',$default->name());
	$t->same(PanelManager::instance(),$default->manager());
	$admin=PanelRegistry::surface(' Admin Panel ',['label'=>'Admin']);
	$t->same('admin_panel',$admin->name());
	$t->isFalse($admin->manager()===PanelManager::instance());
	$t->same($admin,PanelRegistry::surface('admin panel',['extra'=>'value']));
	$t->isTrue(PanelRegistry::has('admin panel'));
	$t->same($admin,PanelRegistry::get('ADMIN PANEL'));
	$t->same(null,PanelRegistry::get('missing'));

	$custom=new PanelInstance('custom',new PanelManager(),[]);
	$t->same($custom,PanelRegistry::register($custom));
	$t->same($custom,PanelRegistry::register($custom,'alias'));
	$t->contains('default',PanelRegistry::names());
	$t->contains('alias',PanelRegistry::names());
	$t->same(4,count(PanelRegistry::all()));
	$description=PanelRegistry::describe();
	$t->same('default',$description['default']['name']);
	$t->same([], $description['default']['resources']);
	$t->same('default',$t->nonPublic(PanelRegistry::class)->invoke('normalizeName',''));
	$t->same('named_surface',$t->nonPublic(PanelRegistry::class)->invoke('normalizeName','Named Surface'));
	PanelRegistry::flush();
	$t->same([],PanelRegistry::all());
})->tag('panel','registry','context','coverage')->group('framework-coverage');

test('panel registry applies direct providers and plugins to named surfaces',static function(Context $t): void {
	PanelRegistry::flush();
	$provider=new class implements PanelProvider {
		public function panel(PanelInstance $panel): PanelInstance { $panel->register(Resource::make('provided')); return $panel; }
	};
	$plugin=new class implements PanelPlugin {
		public function id(): string { return 'coverage-plugin'; }
		public function register(PanelInstance $panel): void { $panel->register(Resource::make('plugin-resource')); }
		public function boot(PanelInstance $panel): void { $panel->config(['plugin_booted'=>true]); }
	};
	$surface=PanelRegistry::provide('workspace',$provider);
	$t->isTrue(isset($surface->resources()['provided']));
	$surface=PanelRegistry::provide('workspace',static function(PanelInstance $panel): PanelInstance { $panel->register(Resource::make('callback')); return $panel; });
	$t->isTrue(isset($surface->resources()['callback']));
	$surface=PanelRegistry::plugin('workspace',$plugin,['enabled'=>true]);
	$t->contains('coverage-plugin',$surface->pluginIds());
	$t->isTrue(isset($surface->resources()['plugin-resource']));
	$t->same(['coverage-plugin'],PanelRegistry::describe()['workspace']['plugins']);
})->tag('panel','registry','context','coverage')->group('framework-coverage');

test('panel registry boots configured defaults and named surfaces once',static function(Context $t): void {
	PanelRegistry::flush();
	$plugin=new class implements PanelPlugin {
		public function id(): string { return 'configured-plugin'; }
		public function register(PanelInstance $panel): void { $panel->register(Resource::make('configured-plugin-resource')); }
		public function boot(PanelInstance $panel): void {}
	};
	$provider=static function(PanelInstance $panel): PanelInstance { $panel->register(Resource::make('configured-provider-resource')); return $panel; };
	PanelContext::run([
		'providers'=>[$provider],
		'plugins'=>[$plugin],
		'surfaces'=>[
			'operator'=>['label'=>'Operator','providers'=>[$provider],'plugins'=>[$plugin]],
			'empty'=>['providers'=>'invalid','plugins'=>'invalid'],
			'invalid'=>'skip',
		],
	],static function()use($t): void {
		$booted=PanelRegistry::bootConfigured();
		$t->isTrue(isset($booted['default'],$booted['operator'],$booted['empty']));
		$t->isTrue(isset($booted['default']->resources()['configured-provider-resource']));
		$t->isTrue(isset($booted['operator']->resources()['configured-plugin-resource']));
		$t->same($booted,PanelRegistry::bootConfigured());
	});
})->tag('panel','registry','context','coverage')->group('framework-coverage');
