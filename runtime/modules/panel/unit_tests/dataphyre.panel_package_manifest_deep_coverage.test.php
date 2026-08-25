<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

final class DpPanelPackageManifestPlugin implements PanelPlugin {
	public function id(): string { return ' manifest-plugin '; }
	public function label(): string { return ' Manifest Plugin '; }
	public function version(): string { return ' 2.4.1 '; }
	public function description(): string { return ' Package conversion fixture. '; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelPackageManifestPluginClass implements PanelPlugin {
	public function id(): string { return 'class-plugin'; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelPackageManifestPlainClass {}

test('panel package manifest builds fluent metadata requirements links and normalized collections',static function(Context $t): void {
	$blank=PanelPackageManifest::make(' ');
	$t->same('panel_package',$blank->id());
	$t->same('Panel Package',$blank->label());
	$explicit=PanelPackageManifest::make('order-tools',' Explicit Label ');
	$t->same('order-tools',$explicit->id());
	$t->same('Explicit Label',$explicit->label());

	$manifest=PanelPackageManifest::make('order-tools');
	$t->same('Order Tools',$manifest->label());
	$t->same($manifest,$manifest->label(' Orders Toolkit '));
	$t->same($manifest,$manifest->label('   '));
	$t->same('Orders Toolkit',$manifest->label());
	$t->same(null,$manifest->version());
	$t->same($manifest,$manifest->version(' 1.2.3 '));
	$t->same('1.2.3',$manifest->version());
	$t->same($manifest,$manifest->version(' '));
	$t->same(null,$manifest->version());
	$t->same(null,$manifest->description());
	$t->same($manifest,$manifest->description(' Useful package '));
	$t->same('Useful package',$manifest->description());
	$t->same($manifest,$manifest->description(''));
	$t->same(null,$manifest->description());
	$t->same(null,$manifest->class());
	$t->same($manifest,$manifest->class(' App\\Packages\\Orders '));
	$t->same('App\\Packages\\Orders',$manifest->class());
	$t->same($manifest,$manifest->class(' '));
	$t->same(null,$manifest->class());
	$t->same('plugin',$manifest->type());
	$t->same($manifest,$manifest->type('Theme Pack'));
	$t->same('theme_pack',$manifest->type());
	$t->same($manifest,$manifest->type(' '));
	$t->same('plugin',$manifest->type());
	$t->same('stable',$manifest->status());
	$t->same($manifest,$manifest->status('Preview Build'));
	$t->same('preview_build',$manifest->status());
	$t->same($manifest,$manifest->status(' '));
	$t->same('stable',$manifest->status());

	$t->same($manifest,$manifest->requires([
		'php'=>' >=8.2 ',
		'panel'=>' ^2.0 ',
		'reactor'=>' ',
		'modules'=>[
			'SQL Engine'=>' >=3.0 ',
			'cache'=>' ',
			''=>'>=1',
		],
		'themes'=>['Dark Mode','dark_mode','', 'Compact'],
		'unknown'=>'ignored',
	]));
	$t->same($manifest,$manifest->requires('reactor','>=1.5'));
	$t->same($manifest,$manifest->requires('unknown','>=9'));
	$t->same($manifest,$manifest->requiresModule('queue',''));
	$t->same($manifest,$manifest->requiresModule(' ','>=1'));
	$t->same($manifest,$manifest->requiresTheme('Compact'));
	$t->same($manifest,$manifest->requiresTheme(' '));
	$t->same($manifest,$manifest->provides(['Resources','resources','Render Hooks','']));
	$t->same($manifest,$manifest->provides('Commands'));

	$t->same($manifest,$manifest->link('Ignored','   '));
	$t->same($manifest,$manifest->link('','https://example.test/docs'));
	$t->same($manifest,$manifest->link(' Repository ',' https://example.test/repository '));
	$t->same($manifest,$manifest->support(['email'=>'support@example.test','owner'=>'Example Publisher']));
	$t->same($manifest,$manifest->support('Issue Tracker','https://example.test/issues'));
	$t->same($manifest,$manifest->support(' ', 'ignored'));
	$t->same($manifest,$manifest->signature(['publisher'=>'example_publisher','digest'=>'sha256:test']));
	$t->same($manifest,$manifest->signature('Key ID','release-key'));
	$t->same($manifest,$manifest->signature(' ', 'ignored'));
	$t->same($manifest,$manifest->meta(['source'=>'fluent','override'=>'first']));
	$t->same($manifest,$manifest->meta(' external:key ','value'));
	$t->same($manifest,$manifest->meta('   ','ignored'));
	$t->same($manifest,$manifest->meta(['override'=>'second']));

	$array=$manifest->toArray();
	$t->same('>=8.2',$array['requirements']['php']);
	$t->same('^2.0',$array['requirements']['panel']);
	$t->same('>=1.5',$array['requirements']['reactor']);
	$t->same('>=3.0',$array['requirements']['modules']['sql_engine']);
	$t->same('*',$array['requirements']['modules']['cache']);
	$t->same('*',$array['requirements']['modules']['queue']);
	$t->same(['dark_mode','compact'],$array['requirements']['themes']);
	$t->same(['resources','render_hooks','commands'],$array['provides']);
	$t->same('https://example.test/docs',$array['links'][0]['label']);
	$t->same('Repository',$array['links'][1]['label']);
	$t->same('https://example.test/issues',$array['support']['issue_tracker']);
	$t->same('release-key',$array['signature']['key_id']);
	$t->same('value',$array['meta']['external:key']);
	$t->same('second',$array['meta']['override']);
	$t->same(null,$array['compatibility']);
	$t->same($array,$manifest->jsonSerialize());
	$t->same($array,json_decode((string)json_encode($manifest),true));
})->tag('panel','package-manifest','coverage')->group('framework-coverage');

test('panel package manifest converts existing plugin class string and array sources',static function(Context $t): void {
	$existing=PanelPackageManifest::make('existing');
	$t->same($existing,PanelPackageManifest::from($existing));

	$plugin=new DpPanelPackageManifestPlugin();
	$fromPlugin=PanelPackageManifest::from($plugin,['enabled'=>true,'api_token'=>'secret'])->toArray();
	$t->same('manifest-plugin',$fromPlugin['id']);
	$t->same('Manifest Plugin',$fromPlugin['label']);
	$t->same('2.4.1',$fromPlugin['version']);
	$t->same('Package conversion fixture.',$fromPlugin['description']);
	$t->same(DpPanelPackageManifestPlugin::class,$fromPlugin['class']);
	$t->same('plugin',$fromPlugin['type']);
	$t->same(['plugin','render_hooks','resources'],$fromPlugin['provides']);

	$fromPluginClass=PanelPackageManifest::from(DpPanelPackageManifestPluginClass::class)->toArray();
	$t->same(DpPanelPackageManifestPluginClass::class,$fromPluginClass['class']);
	$t->same(['plugin'],$fromPluginClass['provides']);
	$t->same('plugin',$fromPluginClass['type']);
	$fromPlainClass=PanelPackageManifest::from(DpPanelPackageManifestPlainClass::class)->toArray();
	$t->same(DpPanelPackageManifestPlainClass::class,$fromPlainClass['class']);
	$t->same([],$fromPlainClass['provides']);
	$fromUnknown=PanelPackageManifest::from('Vendor\\MissingPackage')->toArray();
	$t->same(null,$fromUnknown['class']);
	$t->same('Vendor Missing Package',$fromUnknown['label']);
	$fromBlank=PanelPackageManifest::from('   ')->toArray();
	$t->same('panel_package',$fromBlank['id']);
	$t->same('Panel Package',$fromBlank['label']);

	$fromArray=PanelPackageManifest::from([
		'name'=>'array-package',
		'title'=>'Array Package',
		'version'=>'3.1.0',
		'description'=>'Array definition.',
		'type'=>'Integration',
		'status'=>'Beta',
		'class'=>DpPanelPackageManifestPlainClass::class,
		'requires'=>['php'=>'>=8.1','modules'=>['http'=>'>=2']],
		'requirements'=>['panel'=>'^3.0','themes'=>['operator']],
		'provides'=>['pages','widgets'],
		'links'=>[
			'not an array',
			['target'=>'https://example.test/docs'],
			['label'=>'Missing target'],
			['label'=>'Source','target'=>'https://example.test/source'],
		],
		'support'=>['email'=>'array@example.test'],
		'signature'=>['publisher'=>'array-publisher'],
		'meta'=>['source'=>'array'],
	])->toArray();
	$t->same('array-package',$fromArray['id']);
	$t->same('Array Package',$fromArray['label']);
	$t->same('integration',$fromArray['type']);
	$t->same('beta',$fromArray['status']);
	$t->same('>=8.1',$fromArray['requirements']['php']);
	$t->same('^3.0',$fromArray['requirements']['panel']);
	$t->same('>=2',$fromArray['requirements']['modules']['http']);
	$t->same(['operator'],$fromArray['requirements']['themes']);
	$t->same(2,count($fromArray['links']));
	$t->same('https://example.test/docs',$fromArray['links'][0]['label']);
	$t->same('array@example.test',$fromArray['support']['email']);
	$t->same('array-publisher',$fromArray['signature']['publisher']);
	$t->same('array',$fromArray['meta']['source']);

	$defaults=PanelPackageManifest::from([])->toArray();
	$t->same('panel_package',$defaults['id']);
	$t->same('Panel Package',$defaults['label']);
})->tag('panel','package-manifest','coverage')->group('framework-coverage');

test('panel package manifest evaluates compatible blocked and missing runtime requirements',static function(Context $t): void {
	$manifest=PanelPackageManifest::make('runtime-package')
		->version('4.0.0')
		->requires([
			'php'=>'>=8.1,<9.0',
			'panel'=>'^2.1',
			'reactor'=>'1.5.0',
			'modules'=>[
				'sql'=>'*',
				'cache'=>'>=1.0',
			],
			'themes'=>['operator','compact'],
		]);
	$compatible=$manifest->compatibility([
		'php'=>'8.3.1',
		'panel'=>'2.5.0',
		'reactor'=>'1.5.0',
		'modules'=>['sql'=>'0.1.0','cache'=>'1.2.0'],
		'themes'=>['compact','operator'],
	]);
	$t->same(true,$compatible['ok']);
	$t->same('compatible',$compatible['status']);
	$t->same(7,count($compatible['checks']));
	$t->same('php',$compatible['checks'][0]['name']);
	$t->same('installed',$compatible['checks'][5]['actual']);

	$blocked=$manifest->compatibility([
		'php'=>'9.0.0',
		'panel'=>'2.0.0',
		'reactor'=>'',
		'modules'=>['sql'=>'1.0.0'],
		'themes'=>['operator'],
	]);
	$t->same(false,$blocked['ok']);
	$t->same('blocked',$blocked['status']);
	$t->same('missing',$blocked['checks'][2]['actual']);
	$t->same('missing',$blocked['checks'][4]['actual']);
	$t->same('missing',$blocked['checks'][6]['actual']);

	$embedded=$manifest->toArray([
		'php'=>'8.3.1','panel'=>'2.2.0','reactor'=>'1.5.0',
		'modules'=>['sql'=>'1.0.0','cache'=>'1.0.0'],'themes'=>['operator','compact'],
	]);
	$t->same(true,$embedded['compatibility']['ok']);
	$t->same(null,$manifest->toArray()['compatibility']);
	$t->same(true,PanelPackageManifest::make('no-requirements')->compatibility([])['ok']);
})->tag('panel','package-manifest','coverage')->group('framework-coverage');

test('panel package manifest matches wildcard caret comparator exact and invalid constraints',static function(Context $t): void {
	foreach([
		['1.2.3','',true],
		['1.2.3','*',true],
		['1.2.3','*, >=1.0',true],
		['2.4.0','^2.1',true],
		['2.0.9','^2.1',false],
		['3.0.0','^2.1',false],
		['0.9.0','^',false],
		['2.0.0','>=2.0',true],
		['2.0.0','>2.0.0',false],
		['2.0.0','<=2.0.0',true],
		['2.0.0','<2.1',true],
		['2.0.0','=2.0.0',true],
		['2.0.0','==2.0.0',true],
		['2.0.0','>=1.0, <3.0',true],
		['3.0.0','>=1.0, <3.0',false],
		['2.0.0','2.0.0',true],
		['2.0.1','2.0.0',false],
	] as [$version,$constraint,$expected]){
		$t->same($expected,PanelPackageManifest::matchesConstraint($version,$constraint),$version.' '.$constraint);
	}
})->tag('panel','package-manifest','coverage')->group('framework-coverage');
