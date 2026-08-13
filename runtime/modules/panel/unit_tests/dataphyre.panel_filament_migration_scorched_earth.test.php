<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelFilamentMigrationCli;
use Dataphyre\Panel\PanelFilamentMigrationInventory;
use Dataphyre\Panel\PanelFilamentMigrationPlan;
use Dataphyre\Panel\PanelFilamentSourceAnalyzer;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array{root:string,marker:string} */
function dp_filament_fixture(Context $t):array{
	$root=$t->tempDirectory('panel-filament-migration');$marker=$root.DIRECTORY_SEPARATOR.'source-executed.txt';
	@mkdir($root.'/app/Filament/Resources/Orders/Schemas',0777,true);@mkdir($root.'/app/Filament/Resources/Orders/Tables',0777,true);@mkdir($root.'/app/Filament/Widgets',0777,true);
	file_put_contents($root.'/composer.json',json_encode(['require'=>['filament/filament'=>'^5.0']],JSON_THROW_ON_ERROR));
	file_put_contents($root.'/composer.lock',json_encode(['packages'=>[
		['name'=>'filament/filament','version'=>'v5.2.1'],['name'=>'vendor/filament-extra','version'=>'1.4.0'],
	]],JSON_THROW_ON_ERROR));
	file_put_contents($root.'/app/Filament/Resources/Orders/OrderResource.php',<<<'PHP'
<?php
namespace App\Filament\Resources\Orders;
use Filament\Resources\Resource as FilamentResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
file_put_contents(__DIR__.'/../../../../source-executed.txt', 'must not execute');
final class OrderResource extends FilamentResource {
    protected static ?string $model = \App\Models\Order::class;
    protected static ?string $modelLabel = 'Order';
    protected static ?string $pluralModelLabel = 'Orders';
    protected static ?string $navigationGroup = 'Commerce';
    protected static ?string $navigationIcon = 'shopping-bag';
    protected static ?int $navigationSort = 7;
    protected static ?string $slug = 'commerce/orders';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static bool $shouldRegisterNavigation = false;
    public static function form($schema) { return OrderForm::configure($schema); }
    public static function table($table) { return OrdersTable::configure($table); }
}
PHP);
	file_put_contents($root.'/app/Filament/Resources/Orders/Schemas/OrderForm.php',<<<'PHP'
<?php
namespace App\Filament\Resources\Orders\Schemas;
use Filament\Forms\Components\TextInput as Input;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
final class OrderForm {
    public static function configure($schema) {
        return $schema->components([
            Grid::make(2)->schema([
                Input::make('email')->label('Buyer email')->required()->email()->maxLength(255),
                Select::make('status')->options(['new'=>'New','paid'=>'Paid'])->searchable()->live()->disabled(fn (): bool => false),
            ]),
        ]);
    }
}
PHP);
	file_put_contents($root.'/app/Filament/Resources/Orders/Tables/OrdersTable.php',<<<'PHP'
<?php
namespace App\Filament\Resources\Orders\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
final class OrdersTable {
    public static function configure($table) {
        return $table->columns([
            TextColumn::make('reference')->label('Reference')->sortable()->searchable()->limit(40),
            IconColumn::make('paid')->toggleable(false),
        ])->filters([SelectFilter::make('status')])->recordActions([EditAction::make()]);
    }
}
PHP);
	file_put_contents($root.'/app/Filament/Widgets/RevenueWidget.php',<<<'PHP'
<?php
namespace App\Filament\Widgets;
use Filament\Widgets\Widget;
final class RevenueWidget extends Widget {}
PHP);
	return['root'=>$root,'marker'=>$marker];
}

test('filament analyzer is non executing deterministic bounded and follows version five companion references',static function(Context $t):void{
	$fixture=dp_filament_fixture($t);$analyzer=PanelFilamentSourceAnalyzer::make($fixture['root']);$inventory=$analyzer->analyze();
	$t->instanceOf(PanelFilamentMigrationInventory::class,$inventory);$t->isFalse(is_file($fixture['marker']));
	$t->same(5,$inventory->composer()['detected_major']);$t->same('v5.2.1',$inventory->composer()['version']);
	$json=json_encode($inventory,JSON_THROW_ON_ERROR);$t->notContains($fixture['root'],$json);$t->notContains('must not execute',$json);$t->contains('source_contents_serialized',$json);
	$again=$analyzer->analyze(['app/Filament/Widgets','app/Filament/Resources']);$ordered=$analyzer->analyze(['app/Filament/Resources','app/Filament/Widgets']);
	$t->same($again->digest(),$ordered->digest());$t->isFalse($again->hasBlockers());
	$resourceFile=array_values(array_filter($inventory->files(),static fn(array $file):bool=>str_ends_with($file['path'],'OrderResource.php')))[0];
	$t->contains('App\\Filament\\Resources\\Orders\\Schemas\\OrderForm',$resourceFile['references']);
	$t->contains('App\\Filament\\Resources\\Orders\\Tables\\OrdersTable',$resourceFile['references']);
})->tag('panel','migration','filament','security','determinism')->group('framework-coverage');

test('filament plan generates runnable fail safe resources from split schemas and tables',static function(Context $t):void{
	$fixture=dp_filament_fixture($t);$inventory=PanelFilamentSourceAnalyzer::make($fixture['root'])->analyze();
	$plan=PanelFilamentMigrationPlan::make($inventory);$t->isTrue($plan->readyToWrite());$t->isFalse($plan->readyToActivate());$t->same(1,count($plan->artifacts()));
	$artifact=$plan->artifacts()[0];$t->same('app/Panel/Resources/Orders/OrderResource.php',$artifact->path());
	$source=$artifact->contents();token_get_all($source,TOKEN_PARSE);
	$t->contains("->field('email', 'text')->label('Buyer email')->required()->email()->maxLength(255)",$source);
	$t->contains("->field('status', 'select')->options(",$source);$t->contains("->column('reference', 'text')->label('Reference')->sortable()->searchable()->limit(40)",$source);
	$t->contains('->model(\\App\\Models\\Order::class)',$source);$t->contains('->hideFromNavigation()',$source);$t->contains("->url('/commerce/orders')",$source);
	$t->notContains('queryUsing',$source);$t->notContains('saveUsing',$source);$t->notContains('fn (',$source);$t->notContains('must not execute',$source);
	$t->same(2,$plan->coverage()['fields_mapped']);$t->same(2,$plan->coverage()['columns_mapped']);$t->isTrue($plan->coverage()['manual_components']>=3);
	$encoded=json_encode($plan,JSON_THROW_ON_ERROR);$t->notContains($fixture['root'],$encoded);$t->notContains($source,$encoded);$t->contains('host_data_adapter_required',$encoded);$t->contains('ready_to_activate',$encoded);

	$preview=$plan->write($fixture['root']);$t->isTrue($preview->dryRun());$t->isFalse(is_file($fixture['root'].'/'.$artifact->path()));
	$applied=$plan->write($fixture['root'],'error',false);$t->isFalse($applied->dryRun());$target=$fixture['root'].'/'.$artifact->path();$t->isTrue(is_file($target));require $target;
	$resource=\App\Panel\Resources\Orders\OrderResource::make(PanelInstance::make('migrated'));
	$t->instanceOf(Resource::class,$resource);$t->same(2,count($resource->form()->fieldsList()));$t->same(2,count($resource->resourceTable()->columnsList()));
	$t->same('App\\Models\\Order',$resource->toArray()['model']);
	$t->same($plan->digest(),PanelFilamentMigrationPlan::make($inventory)->digest());
})->tag('panel','migration','filament','generation','transaction')->group('framework-coverage');

test('filament migration fails closed for missing malformed oversized and escaping sources',static function(Context $t):void{
	$empty=$t->tempDirectory('panel-filament-empty');@mkdir($empty.'/app/Filament',0777,true);file_put_contents($empty.'/app/Filament/Empty.php','<?php namespace App\\Filament; final class Empty {}');
	$inventory=PanelFilamentSourceAnalyzer::make($empty)->analyze();$t->isTrue($inventory->hasBlockers());$plan=PanelFilamentMigrationPlan::make($inventory);$t->isFalse($plan->readyToWrite());$t->throws(static fn()=>$plan->write($empty),LogicException::class);
	$t->throws(static fn()=>PanelFilamentSourceAnalyzer::make($empty)->analyze(['../outside']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelFilamentMigrationPlan::make($inventory,['target_namespace'=>'Bad-Namespace']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelFilamentMigrationPlan::make($inventory,['target_directory'=>'../outside']),InvalidArgumentException::class);

	$broken=$t->tempDirectory('panel-filament-broken');@mkdir($broken.'/app/Filament',0777,true);file_put_contents($broken.'/app/Filament/Broken.php','<?php namespace App\\Filament; use Filament\\Resources\\Resource; class Broken extends Resource {');
	$t->isTrue(PanelFilamentSourceAnalyzer::make($broken)->analyze()->hasBlockers());
	$large=$t->tempDirectory('panel-filament-large');@mkdir($large.'/app/Filament',0777,true);file_put_contents($large.'/app/Filament/Large.php','<?php /* Filament\\ */ '.str_repeat('x',4096));
	$t->isTrue(PanelFilamentSourceAnalyzer::make($large,['maximum_file_bytes'=>1024])->analyze()->hasBlockers());
	if(function_exists('symlink')){$link=$empty.'/app/Filament/Linked.php';if(@symlink($empty.'/app/Filament/Empty.php',$link)){$linked=PanelFilamentSourceAnalyzer::make($empty)->analyze();$t->isTrue($linked->hasBlockers());@unlink($link);}}

	$malicious=new PanelFilamentMigrationInventory([],[[
		'path'=>'app/Filament/Evil.php','classes'=>[['class'=>'Bad-Class','fqcn'=>'App\\Filament\\Resources\\Bad-Class','kind'=>'resource','line'=>1,'metadata'=>[]]],'components'=>[],
	]],[]);$maliciousPlan=PanelFilamentMigrationPlan::make($malicious);$t->isFalse($maliciousPlan->readyToWrite());$t->same(0,count($maliciousPlan->artifacts()));
})->tag('panel','migration','filament','fail-closed','boundaries')->group('framework-coverage');

test('filament migration diagnostics expose every omitted mapping without weakening generated source',static function(Context $t):void{
	$root=$t->tempDirectory('panel-filament-diagnostic-closure');$analyzer=PanelFilamentSourceAnalyzer::make($root);$t->same(realpath($root),$analyzer->root());$unsupported=$t->nonPublic($analyzer)->invoke('componentMapping','MysteryComponent','Filament\\Forms\\Components\\MysteryComponent');$t->same('unsupported',$unsupported['family']);
	$t->isNull($t->nonPublic($analyzer)->invoke('componentMapping','DomainService','App\\Domain\\DomainService'));
	$t->throws(static fn()=>new PanelFilamentMigrationInventory([], [['path'=>'app//Probe.php']], []),InvalidArgumentException::class);$t->throws(static fn()=>new PanelFilamentMigrationInventory([], [['path'=>'../escape.php']], []),InvalidArgumentException::class);$t->throws(static fn()=>new PanelFilamentMigrationInventory([], [], [['severity'=>'fatal','code'=>'invalid']]),InvalidArgumentException::class);
	$inventory=new PanelFilamentMigrationInventory(['detected_major'=>5],[[
		'path'=>'app/Filament/Resources/ProbeResource.php','sha256'=>str_repeat('a',64),'references'=>[],
		'classes'=>[['class'=>'ProbeResource','fqcn'=>'App\\Filament\\Resources\\ProbeResource','kind'=>'resource','line'=>1,'metadata'=>['model'=>'Bad-Class']]],
		'components'=>[
			['family'=>'field','panel_type'=>'text','name'=>'','line'=>2,'methods'=>[]],
			['family'=>'field','panel_type'=>'text','name'=>'email','line'=>3,'methods'=>[['method'=>'afterStateUpdated','literal'=>true,'value'=>true]]],
			['family'=>'field','panel_type'=>'text','name'=>'email','line'=>4,'methods'=>[]],
		],
	]],[]);$plan=PanelFilamentMigrationPlan::make($inventory,['target_namespace'=>'Product\\Panel','target_directory'=>'generated/panel']);
	$t->same($inventory,$plan->inventory());$t->same('Product\\Panel',$plan->targetNamespace());$t->same('generated/panel',$plan->targetDirectory());$codes=array_column($plan->findings(),'code');foreach(['dynamic_component_identity','duplicate_component_identity','invalid_model_metadata','manual_component_method']as$code){$t->contains($code,$codes);}
	$t->same(6,count($t->nonPublic(PanelFilamentMigrationPlan::class)->invoke('activationRequirements')));$t->isTrue($t->nonPublic(PanelFilamentMigrationPlan::class)->invoke('literalValue',['nested'=>[1,true,null]]));$t->isFalse($t->nonPublic(PanelFilamentMigrationPlan::class)->invoke('literalValue',new stdClass()));$t->same('Order Item',$t->nonPublic(PanelFilamentMigrationPlan::class)->invoke('headline','OrderItem'));$t->same('Categories',$t->nonPublic(PanelFilamentMigrationPlan::class)->invoke('pluralize','Category'));
})->tag('panel','migration','filament','diagnostics','coverage')->group('framework-coverage');

test('filament migration cli previews by default writes only explicitly and redacts roots',static function(Context $t):void{
	$fixture=dp_filament_fixture($t);$root=$fixture['root'];
	$help=PanelFilamentMigrationCli::execute(['tool','--help'],$root);$t->same(0,$help['exit_code']);$t->same('help',$help['payload']['mode']);
	$preview=PanelFilamentMigrationCli::execute(['tool','--root',$root,'--target-namespace','Product\\Panel\\Resources'],$root);$t->same(0,$preview['exit_code']);$t->same('preview',$preview['payload']['mode']);$t->isTrue($preview['payload']['transaction']['dry_run']);
	$encoded=json_encode($preview,JSON_THROW_ON_ERROR);$t->notContains($root,$encoded);$t->isFalse(is_file($root.'/app/Panel/Resources/Orders/OrderResource.php'));
	$write=PanelFilamentMigrationCli::execute(['tool','--root='.$root,'--target-namespace=Product\\Panel\\Resources','--write'],$root);$t->same(0,$write['exit_code']);$t->same('write',$write['payload']['mode']);$t->isTrue(is_file($root.'/app/Panel/Resources/Orders/OrderResource.php'));
	$invalid=PanelFilamentMigrationCli::execute(['tool','--unknown'],$root);$t->same(2,$invalid['exit_code']);$t->same('invalid',$invalid['payload']['mode']);
	$duplicate=PanelFilamentMigrationCli::execute(['tool','--root',$root,'--root',$root],$root);$t->same(2,$duplicate['exit_code']);
	$blocked=$t->tempDirectory('panel-filament-cli-empty');@mkdir($blocked.'/app/Filament',0777,true);$result=PanelFilamentMigrationCli::execute(['tool','--root',$blocked,'--write'],$blocked);$t->same(1,$result['exit_code']);$t->same('blocked',$result['payload']['mode']);
})->tag('panel','migration','filament','cli','preview')->group('framework-coverage')->maxMillis(10000);
