<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelScaffolder;
use Dataphyre\Panel\PanelScaffoldResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel scaffolder generates normalized resource and page source contracts',static function(Context $t): void {
	$panel=PanelInstance::make('admin-area');
	$scaffolder=PanelScaffolder::make($panel);
	$writerRoot=$t->workspace('panel-scaffolder-writer')->root();
	$t->same(realpath($writerRoot),$scaffolder->writer($writerRoot)->root());
	$resource=$scaffolder->resource('App/Panel/Resources/Order Resource',[
		'name'=>'customer_orders',
		'label'=>"Owner's Order",
		'plural_label'=>"Owners' Orders",
		'icon'=>'shopping-bag',
		'columns'=>['id',['name'=>'order total'],'status'=>'status','id'],
		'fields'=>['name',['name'=>'customer email'],'enabled'=>new stdClass(),''],
		'base_path'=>'src',
	]);
	$t->instanceOf(PanelScaffoldResult::class,$resource);
	$t->same('resource',$resource->kind());
	$t->same('customer_orders',$resource->name());
	$t->same('App\Panel\Resources\OrderResource',$resource->class());
	$t->same(['id','order_total','status'],$resource->metadata()['columns']);
	$t->same(['name','customer_email','enabled'],$resource->metadata()['fields']);
	$t->same('admin-area',$resource->metadata()['panel']);
	$t->same('App\Panel\Resources',$resource->metadata()['namespace']);
	$t->same('OrderResource',$resource->metadata()['short_class']);
	$t->contains('namespace App\Panel\Resources;',$resource->contents());
	$t->contains("->label('Owner\\'s Order')",$resource->contents());
	$t->contains('$panel->column(\'order_total\')',$resource->contents());
	$t->contains('Resources'.DIRECTORY_SEPARATOR.'OrderResource.php',$resource->path());

	$fallback=$scaffolder->resource('',[
		'name'=>' ',
		'columns'=>[],
		'fields'=>[],
		'namespace'=>'',
	]);
	$t->same('resource',$fallback->name());
	$t->same('GeneratedPanelArtifact',$fallback->metadata()['short_class']);
	$t->contains("->columns([\n\n",$fallback->contents());

	$page=$scaffolder->page('Reports Page',[
		'name'=>' ',
		'label'=>"Today's Reports",
		'group'=>'Operations',
		'icon'=>'chart',
		'namespace'=>'App\Panel\CustomPages',
		'path'=>'custom/ReportsPage.php',
	]);
	$t->same('page',$page->name());
	$t->same('App\Panel\CustomPages\ReportsPage',$page->class());
	$t->same('custom/ReportsPage.php',$page->path());
	$t->contains("Today's Reports",str_replace("\\'","'",$page->contents()));
	$t->contains('$panel->registerPage(ReportsPage::make($panel));',$page->metadata()['register']);
})->tag('panel','scaffolder','coverage')->group('framework-coverage');

test('panel scaffolder generates providers plugins themes and panel tests',static function(Context $t): void {
	$standalone=PanelScaffolder::make();
	$provider=$standalone->provider('App\Panel\AdminProvider',[
		'resources'=>['\App\Panel\Resources\OrderResource',' App\Panel\Resources\UserResource ','','App\Panel\Resources\OrderResource'],
		'pages'=>['\App\Panel\Pages\DashboardPage','App\Panel\Pages\ReportsPage'],
		'base_path'=>'app',
	]);
	$t->same('adminprovider',$provider->name());
	$t->same(null,$provider->metadata()['panel']);
	$t->same([
		'App\Panel\Resources\OrderResource','App\Panel\Resources\UserResource',
	],$provider->metadata()['resources']);
	$t->same([
		'App\Panel\Pages\DashboardPage','App\Panel\Pages\ReportsPage',
	],$provider->metadata()['pages']);
	$t->contains('$panel->label(\'Panel\');',$provider->contents());
	$t->contains('$panel->register(\App\Panel\Resources\OrderResource::make($panel));',$provider->contents());
	$t->contains('$panel->registerPage(\App\Panel\Pages\ReportsPage::make($panel));',$provider->contents());

	$bound=PanelScaffolder::make(PanelInstance::make('back-office'))->provider('Provider',[
		'label'=>"Operator's Panel",'resources'=>'invalid','pages'=>null,
	]);
	$t->same([],$bound->metadata()['resources']);
	$t->same([],$bound->metadata()['pages']);
	$t->same('back-office',$bound->metadata()['panel']);
	$t->contains("Operator\\'s Panel",$bound->contents());

	$plugin=$standalone->plugin('Audit Plugin',[
		'id'=>' ',
		'label'=>"Audit's Tools",
		'version'=>"2.0'dev",
		'namespace'=>'App\Panel\Extensions',
	]);
	$t->same('panel_plugin',$plugin->name());
	$t->same("2.0'dev",$plugin->metadata()['version']);
	$t->contains("return 'Audit\\'s Tools';",$plugin->contents());
	$t->contains("return '2.0\\'dev';",$plugin->contents());
	$t->contains('$panel->plugin(AuditPlugin::class);',$plugin->metadata()['register']);

	$theme=$standalone->theme('Brand Theme',[
		'name'=>' ',
		'primary'=>"#12'3456",
		'radius'=>'12px',
		'namespace'=>'App\Panel\Themes',
	]);
	$t->same('theme',$theme->name());
	$t->same("#12'3456",$theme->metadata()['primary']);
	$t->same('12px',$theme->metadata()['radius']);
	$t->contains("#12\\'3456",$theme->contents());
	$t->contains('Panel::registerThemePreset(BrandTheme::preset());',$theme->metadata()['register']);

	$testArtifact=$standalone->test('Order Resource Test',[
		'resource'=>' ',
		'namespace'=>'Tests\Feature\Panel',
	]);
	$t->same('orderresourcetest',$testArtifact->name());
	$t->same('resource',$testArtifact->metadata()['resource']);
	$t->contains('$test=Panel::test();',$testArtifact->contents());
	$t->contains("render('resource', 'index')",$testArtifact->contents());
})->tag('panel','scaffolder','coverage')->group('framework-coverage');

test('panel scaffolder suite tolerates malformed definitions and dispatches every kind',static function(Context $t): void {
	$results=PanelScaffolder::make()->suite([
		'not an array',
		[],
		['kind'=>'resource','class'=>''],
		['kind'=>'unknown','class'=>'UnknownArtifact'],
		['kind'=>'resource','class'=>'SuiteResource','options'=>['name'=>'suite_records']],
		['type'=>'page','class'=>'SuitePage','options'=>['name'=>'suite_page']],
		['kind'=>'provider','class'=>'SuiteProvider','options'=>'invalid','label'=>'Suite'],
		['kind'=>'plugin','class'=>'SuitePlugin'],
		['kind'=>'theme','class'=>'SuiteTheme'],
		['kind'=>'test','class'=>'SuiteTest'],
	]);
	$t->same(6,count($results));
	$t->same(['resource','page','provider','plugin','theme','test'],array_map(
		static fn(PanelScaffoldResult $result): string=>$result->kind(),$results
	));
	$t->same('suite_records',$results[0]->name());
	$t->same('suite_page',$results[1]->name());
	$t->contains('$panel->label(\'Suite\');',$results[2]->contents());
})->tag('panel','scaffolder','coverage')->group('framework-coverage');

test('panel scaffolder private class and path normalizers cover boundary inputs',static function(Context $t): void {
	$t->same(['App\Panel','GeneratedPanelArtifact'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','',''));
	$t->same(['Custom\Panel','OrderResource'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','Order Resource','\Custom\Panel\\'));
	$t->same(['App\Panel','Trailing'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','Trailing\\',''));
	$t->same(['Vendor\Package','Thing'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','\Vendor/Package/Thing','Ignored'));
	$t->same(['Vendor\BadName\Generated123','Thing'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','Vendor/Bad-Name/123/Thing','Ignored'));
	$t->same(['BadNamespace\Generated42','Thing'],$t->nonPublic(PanelScaffolder::class)->invoke('splitClass','Thing','Bad Namespace\42'));
	$t->same('Generated',$t->nonPublic(PanelScaffolder::class)->invoke('className','---'));
	$t->same('Generated123Thing',$t->nonPublic(PanelScaffolder::class)->invoke('className','123 thing'));
	$t->same('Already_Class',$t->nonPublic(PanelScaffolder::class)->invoke('className','Already_Class'));

	$t->same('explicit/file.php',$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','App\Panel\Thing',['path'=>'explicit/file.php'],'resource'));
	$panelPath=$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','App\Panel\Resources\Thing',['base_path'=>'base'],'resource');
	$t->same('base'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'Thing.php',$panelPath);
	$appPath=$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','App\Services\Thing',['base_path'=>'base'],'provider');
	$t->same('base'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Thing.php',$appPath);
	$vendorPath=$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','Vendor\Package\Thing',['base_path'=>'base'],'plugin');
	$t->same('base'.DIRECTORY_SEPARATOR.'Vendor'.DIRECTORY_SEPARATOR.'Package'.DIRECTORY_SEPARATOR.'Thing.php',$vendorPath);
	$rootPath=$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','App\Panel\Thing',['base_path'=>'base'],'custom-artifact');
	$t->same('base'.DIRECTORY_SEPARATOR.'Custom Artifact'.DIRECTORY_SEPARATOR.'Thing.php',$rootPath);
	$t->same('app'.DIRECTORY_SEPARATOR.'Panel'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'Thing.php',$t->nonPublic(PanelScaffolder::class)->invoke('targetPath','App\Panel\Resources\Thing',['base_path'=>''],'resource'));
})->tag('panel','scaffolder','coverage')->group('framework-coverage');

test('panel scaffolder private source and collection helpers normalize all forms',static function(Context $t): void {
	$php=$t->nonPublic(PanelScaffolder::class)->invoke('php','\App\Panel\\',
		['use One;','','use One;','use Two;'],
		"final class Example {}\n",);
	$t->contains("declare(strict_types=1);",$php);
	$t->contains("namespace App\Panel;",$php);
	$t->same(1,substr_count($php,'use One;'));
	$t->contains('use Two;',$php);
	$t->contains('final class Example {}',$php);
	$t->same("path\\\\to\\'file",$t->nonPublic(PanelScaffolder::class)->invoke('quote',"path\\to'file"));

	$t->same([],$t->nonPublic(PanelScaffolder::class)->invoke('fieldNames','invalid'));
	$t->same([
		'first_name','email-address','status','numeric_key',
	],$t->nonPublic(PanelScaffolder::class)->invoke('fieldNames',[
		'First Name',
		['name'=>'email-address'],
		'status'=>['label'=>'Status'],
		'numeric key'=>new stdClass(),
		'',
		'First Name',
	]));
	$t->same([],$t->nonPublic(PanelScaffolder::class)->invoke('classList','invalid'));
	$t->same([
		'App\One','App\Two','3',
	],$t->nonPublic(PanelScaffolder::class)->invoke('classList',[
		'\App\One',' App\Two ','','\App\One',3,
	]));

	$t->same('sales_order',$t->nonPublic(PanelScaffolder::class)->invoke('resourceNameFromClass','SalesOrderResource'));
	$t->same('sales_order',$t->nonPublic(PanelScaffolder::class)->invoke('resourceNameFromClass','SalesOrder'));
	$t->same('sales_dashboard',$t->nonPublic(PanelScaffolder::class)->invoke('pageNameFromClass','SalesDashboardPage'));
	$t->same('sales_dashboard',$t->nonPublic(PanelScaffolder::class)->invoke('pageNameFromClass','SalesDashboard'));
	$t->same('Panel',$t->nonPublic(PanelScaffolder::class)->invoke('headline',' -- .. '));
	$t->same('Order Status',$t->nonPublic(PanelScaffolder::class)->invoke('headline','order_status'));
	$t->same('',$t->nonPublic(PanelScaffolder::class)->invoke('pluralize',' '));
	$t->same('Orders',$t->nonPublic(PanelScaffolder::class)->invoke('pluralize','Orders'));
	$t->same('Categories',$t->nonPublic(PanelScaffolder::class)->invoke('pluralize','Category'));
	$t->same('Orders',$t->nonPublic(PanelScaffolder::class)->invoke('pluralize','Order'));
})->tag('panel','scaffolder','coverage')->group('framework-coverage');
