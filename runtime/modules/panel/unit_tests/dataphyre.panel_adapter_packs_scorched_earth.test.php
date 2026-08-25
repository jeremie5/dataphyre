<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelAdapterConformanceCase;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceContext;
use Dataphyre\Panel\PanelAdapterConformanceSuite;
use Dataphyre\Panel\PanelAdapterPack;
use Dataphyre\Panel\PanelAdapterPackActivation;
use Dataphyre\Panel\PanelAdapterPackBinding;
use Dataphyre\Panel\PanelAdapterPackContext;
use Dataphyre\Panel\PanelAdapterPackPlan;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataphyreAccessPlugin;
use Dataphyre\Panel\PanelDataphyreAdapterPack;
use Dataphyre\Panel\PanelDataphyreFulltextSearchAdapter;
use Dataphyre\Panel\PanelDataphyreMailerNotificationAdapter;
use Dataphyre\Panel\PanelFilesystemNotificationAdapter;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelNotificationAdapter;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelSearchResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
\dataphyre\autoloader::register_framework_modules(['access','fulltext_engine','mailer']);
$dpPanelAdapterModules=dirname(__DIR__,2);
require_once $dpPanelAdapterModules.'/access/Framework/PanelAuth.php';
require_once $dpPanelAdapterModules.'/fulltext_engine/Framework/SearchManager.php';
require_once $dpPanelAdapterModules.'/mailer/Framework/SendResult.php';
require_once $dpPanelAdapterModules.'/mailer/Framework/MailerManager.php';

final class PanelAdapterPackTestValue implements JsonSerializable {
	public function __construct(public readonly string $value) {}
	public function jsonSerialize(): mixed {return ['type'=>'adapter_pack_test_value','value'=>$this->value];}
}

final class PanelAdapterPackNamedPlugin implements PanelPlugin {
	public function __construct(private readonly string $name) {}
	public function id():string{return $this->name;}
	public function register(PanelInstance $panel):void{}
	public function boot(PanelInstance $panel):void{}
}

enum PanelAdapterPackUnitValue {case One;}
enum PanelAdapterPackBackedValue:string {case One='one';}

suite('Panel transactional adapter packs')
	->contract('panel.adapter-packs',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel','module:access','module:fulltext_engine','module:mailer')
	->through('preview','dependency-order','conformance-gate','plugin-transaction','activation')
	->tag('panel','adapter-pack','scorched-earth')
	->group('panel-platform-contract');

/** @return PanelInstance */
function dp_panel_adapter_pack_surface(string $name, array $services=[]): PanelInstance {
	if(!isset($services['data.registry'])){$services['data.registry']=new PanelDataSourceRegistry();}
	return Panel::make($name)->usePlatform(PanelPlatform::make($services));
}

test('adapter packs preview and install every target kind in deterministic secret-free order',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_all_targets');
	$secret='adapter-pack-secret-value';
	$pack=PanelAdapterPack::make('all_targets','2.1.0',['label'=>'All targets','publisher'=>'Example Publisher'])
		->binding(PanelAdapterPackBinding::make(
			'service','platform:probe.service',JsonSerializable::class,
			static fn(PanelAdapterPackContext $context,array $config):JsonSerializable=>new PanelAdapterPackTestValue((string)$config['token']),
			options:['config_keys'=>['token'],'required_config_keys'=>['token']]
		))
		->binding(PanelAdapterPackBinding::make(
			'search','search:probe_search',PanelSearchProvider::class,
			static function(PanelAdapterPackContext $context):PanelSearchProvider {
				$context->adapter('service',JsonSerializable::class);
				return PanelSearchProvider::make('probe_search')->searchUsing(
					static fn(string $query):array=>[['title'=>'Found '.$query,'id'=>'one']]
				);
			},
			options:['dependencies'=>['service']]
		))
		->binding(PanelAdapterPackBinding::make(
			'data','data:probe_data',PanelDataSource::class,
			static function(PanelAdapterPackContext $context):PanelDataSource {
				$context->adapter('search',PanelSearchProvider::class);
				return new PanelArrayDataSource([['id'=>'one','name'=>'One']]);
			},
			options:['dependencies'=>['search']]
		));
	$plan=$pack->plan($panel,['adapters'=>['service'=>['token'=>$secret]]]);
	$t->isTrue($plan->ready(),implode(' ',$plan->errors()));
	$t->same(['service','search','data'],$plan->order());
	$t->same($secret,$plan->bindingConfig('service')['token']);
	$preview=json_encode($plan,JSON_THROW_ON_ERROR);
	$t->notContains($secret,$preview);
	$t->contains('"configuration_serialized":false',$preview);
	$t->contains('"factory_executed":false',$preview);

	$activation=$plan->apply();
	$t->instanceOf(PanelAdapterPackActivation::class,$activation);
	$t->same($plan->fingerprint(),$activation->fingerprint());
	$t->same('adapter-pack-secret-value',$panel->platform()->get('probe.service')->value);
	$t->isTrue($panel->hasSearchProvider('probe_search'));
	$t->isTrue($panel->dataSources()->has('probe_data'));
	$t->same('Found needle',$panel->searchProvider('probe_search')?->search('needle',PanelRequest::fromArray([]))[0]['title']??null);
	$t->instanceOf(PanelDataSource::class,$activation->adapter('data',PanelDataSource::class));
	$t->same('all_targets',$activation->pack());
	$t->same('2.1.0',$activation->version());
	$t->same('adapter_pack_all_targets',$activation->panel());
	$t->isTrue($activation->activatedAt()>0);
	$t->isTrue($activation->has('data'));
	$t->isFalse($activation->has('missing'));
	$t->throws(static fn()=>$activation->adapter('missing'),OutOfBoundsException::class);
	$t->throws(static fn()=>$activation->adapter('data',PanelSearchProvider::class),UnexpectedValueException::class);
	$t->same(3,count($activation->adapters()));
	$t->notContains($secret,json_encode($activation,JSON_THROW_ON_ERROR));
	$panel->bootPlugins();
	$t->isTrue($pack->active($panel));

	$panel->unregisterPlugin('all_targets',true);
	$t->isFalse($panel->hasPlugin('all_targets'));
	$t->isFalse($panel->platform()->has('probe.service'));
	$t->isFalse($panel->hasSearchProvider('probe_search'));
	$t->isFalse($panel->dataSources()->has('probe_data'));
	$t->isFalse($pack->active($panel));
})->tag('panel','adapter-pack','targets','transaction','security')->isolation('case')->maxMillis(5000);

test('adapter pack failures and stale previews roll back or refuse every partial mutation',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_rollback');
	$pack=PanelAdapterPack::make('rollback_pack')
		->binding(PanelAdapterPackBinding::make(
			'first','platform:rollback.first',JsonSerializable::class,
			static fn():JsonSerializable=>new PanelAdapterPackTestValue('first')
		))
		->binding(PanelAdapterPackBinding::make(
			'second','platform:rollback.second',JsonSerializable::class,
			static fn()=>throw new RuntimeException('factory failed'),
			options:['dependencies'=>['first']]
		));
	$t->isTrue($pack->plan($panel)->ready());
	$t->throws(static fn()=>$pack->install($panel),RuntimeException::class);
	$t->isFalse($panel->hasPlugin('rollback_pack'));
	$t->isFalse($panel->platform()->has('rollback.first'));
	$t->isFalse($panel->platform()->has('rollback.second'));
	$t->isFalse($pack->active($panel));

	$stalePack=PanelAdapterPack::make('stale_pack')->binding(PanelAdapterPackBinding::make(
		'value','platform:stale.value',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('fresh')
	));
	$stale=$stalePack->plan($panel);
	$panel->platform()->register('unrelated.mutation',new PanelAdapterPackTestValue('changed'));
	$t->throws(static fn()=>$stale->apply(),LogicException::class);
	$t->isFalse($panel->hasPlugin('stale_pack'));
	$t->isFalse($panel->platform()->has('stale.value'));

	$original=new PanelAdapterPackTestValue('original');
	$panel->platform()->register('replace.value',$original);
	$replacement=PanelAdapterPack::make('replacement_pack')->binding(PanelAdapterPackBinding::make(
		'value','platform:replace.value',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('replacement')
	));
	$blocked=$replacement->plan($panel);
	$t->isFalse($blocked->ready());
	$t->contains('already exists',implode(' ',$blocked->errors()));
	$activation=$replacement->install($panel,['replace'=>true]);
	$t->same('replacement',$panel->platform()->get('replace.value')->value);
	$t->instanceOf(PanelAdapterPackActivation::class,$activation);
	$panel->unregisterPlugin('replacement_pack');
	$t->same($original,$panel->platform()->get('replace.value'));
})->tag('panel','adapter-pack','rollback','stale-plan','replacement')->isolation('case')->maxMillis(5000);

test('adapter pack conformance gates fail closed and permit explicitly accepted skips',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_conformance');
	$failed=PanelAdapterConformanceSuite::make('pack_failure',JsonSerializable::class)->add(
		PanelAdapterConformanceCase::make('fails',static fn(JsonSerializable $adapter,PanelAdapterConformanceContext $context)=>$context->same('expected','actual'))
	);
	$pack=PanelAdapterPack::make('conformance_pack')->binding(PanelAdapterPackBinding::make(
		'value','platform:conformance.value',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('candidate'),
		$failed
	));
	$t->throws(static fn()=>$pack->install($panel,['conformance'=>true]),RuntimeException::class);
	$t->isFalse($panel->hasPlugin('conformance_pack'));
	$t->isFalse($panel->platform()->has('conformance.value'));

	$skipped=PanelAdapterConformanceSuite::make('pack_skip',JsonSerializable::class)->add(
		PanelAdapterConformanceCase::make('destructive',static fn(JsonSerializable $adapter,PanelAdapterConformanceContext $context)=>$context->truthy(true),destructive:true)
	);
	$skipPack=PanelAdapterPack::make('skip_pack')->binding(PanelAdapterPackBinding::make(
		'value','platform:conformance.skipped',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('accepted'),
		$skipped
	));
	$t->throws(static fn()=>$skipPack->install($panel,['conformance'=>true]),RuntimeException::class);
	$activation=$skipPack->install($panel,['conformance'=>true,'allow_skipped_conformance'=>true]);
	$t->same(1,$activation->conformance()['value']->summary()['skipped']);
	$t->same('accepted',$panel->platform()->get('conformance.skipped')->value);
})->tag('panel','adapter-pack','conformance','fail-closed')->isolation('case')->maxMillis(3000);

test('adapter pack definition and configuration grammar rejects ambiguous unsafe graphs',static function(Context $t):void {
	$t->throws(static fn()=>PanelAdapterPack::make('Bad Pack'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('bad_version','latest'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad','unknown:value',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad','search:value',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('self','platform:self',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>['self']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('required','platform:required',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['required_config_keys'=>['token']]),InvalidArgumentException::class);

	$first=PanelAdapterPackBinding::make('first','platform:duplicate',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'));
	$t->throws(static fn()=>PanelAdapterPack::make('duplicates')->binding($first)->binding($first),LogicException::class);
	$second=PanelAdapterPackBinding::make('second','platform:duplicate',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'));
	$t->throws(static fn()=>PanelAdapterPack::make('duplicate_targets')->binding($first)->binding($second),LogicException::class);

	$panel=dp_panel_adapter_pack_surface('adapter_pack_validation');
	$optional=PanelAdapterPack::make('optional_pack')->binding(PanelAdapterPackBinding::make(
		'optional','platform:optional.value',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('x'),
		options:['optional'=>true,'config_keys'=>['token'],'required_config_keys'=>['token'],'required_classes'=>['Missing\\Adapter\\ClassName']]
	));
	$t->isFalse($optional->plan($panel)->ready());
	$invalid=$optional->plan($panel,['unknown'=>true,'adapters'=>['missing'=>true,'optional'=>['enabled'=>'yes','other'=>'x']]]);
	$t->isFalse($invalid->ready());
	$t->contains('Unknown adapter pack option',implode(' ',$invalid->errors()));
	$t->contains('Unknown adapter binding',implode(' ',$invalid->errors()));

	$dependencies=PanelAdapterPack::make('dependency_pack')
		->binding(PanelAdapterPackBinding::make('child','platform:dep.child',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>['missing']]))
		->binding(PanelAdapterPackBinding::make('optional','platform:dep.optional',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['optional'=>true]));
	$t->contains('undefined binding',implode(' ',$dependencies->plan($panel)->errors()));
	$disabled=PanelAdapterPack::make('disabled_dependency')
		->binding(PanelAdapterPackBinding::make('base','platform:disabled.base',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['optional'=>true]))
		->binding(PanelAdapterPackBinding::make('child','platform:disabled.child',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>['base']]));
	$t->contains('requires disabled binding',implode(' ',$disabled->plan($panel)->errors()));
	$cycle=PanelAdapterPack::make('cycle_pack')
		->binding(PanelAdapterPackBinding::make('one','platform:cycle.one',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>['two']]))
		->binding(PanelAdapterPackBinding::make('two','platform:cycle.two',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>['one']]));
	$t->contains('dependency cycle',implode(' ',$cycle->plan($panel)->errors()));
})->tag('panel','adapter-pack','validation','dependency-graph')->isolation('case')->maxMillis(3000);

test('Dataphyre Fulltext bridge maps bounded typed pages and sanitizes mapper failures',static function(Context $t):void {
	$secret='fulltext-config-secret';
	$adapter=new PanelDataphyreFulltextSearchAdapter(
		'dataphyre_fulltext',
		'orders',
		static fn(string $index,string $query,int $limit):array=>[
			'results'=>[
				['id'=>'one','score'=>0.9],
				['id'=>'bad','score'=>0.8],
				['two'=>0.7],
			],
			'count'=>4,
		],
		static function(string $id,float $score,string $query):array {
			if($id==='bad'){throw new RuntimeException('mapper password=must-not-leak');}
			return ['title'=>strtoupper($id).' '.$query,'url'=>'/orders/'.$id,'score'=>$score];
		},
		['label'=>'Orders','limit'=>2,'meta'=>['api_token'=>$secret]]
	);
	$page=$adapter->provider()->searchPage('needle',PanelRequest::fromArray([]),null,2);
	$t->same(2,count($page));
	$t->isTrue($page->isPartial());
	$t->isFalse($page->isComplete());
	$t->same('ONE needle',$page->results()[0]->title());
	$t->same('TWO needle',$page->results()[1]->title());
	$t->same('fulltext_mapper_error',$page->diagnostics()[0]['code']);
	$manifest=json_encode($adapter,JSON_THROW_ON_ERROR);
	$t->notContains($secret,$manifest);
	$t->notContains('must-not-leak',$manifest);
	$t->contains('"callbacks_serialized":false',$manifest);
	$report=(new \Dataphyre\Panel\PanelAdapterConformanceRunner())->run(
		PanelAdapterConformanceCatalog::searchProvider(),$adapter->provider(),['query'=>'needle','minimum_results'=>2]
	);
	$t->isTrue($report->passed());

	$invalid=new PanelDataphyreFulltextSearchAdapter('invalid_results','orders',static fn()=>42);
	$invalidPage=$invalid->provider()->searchPage('needle',PanelRequest::fromArray([]));
	$t->isTrue($invalidPage->isPartial());
	$t->same('provider_error',$invalidPage->diagnostics()[0]['code']);
})->tag('panel','adapter-pack','fulltext','bounded','security')->isolation('case')->maxMillis(3000);

test('Dataphyre Mailer bridge persists inbox state delivery receipts and no private locators',static function(Context $t):void {
	$directory=$t->tempDirectory('panel-dataphyre-mailer-adapter');
	$messages=[];$secret='mailer-config-secret';
	$adapter=new PanelDataphyreMailerNotificationAdapter(
		$directory,
		static function(mixed $message) use (&$messages):\Dataphyre\Mailer\SendResult {
			$messages[]=$message;
			return \Dataphyre\Mailer\SendResult::success('test',202,'Accepted','message-1');
		},
		static fn():string=>'operator@example.test',
		['provider'=>$secret]
	);
	$item=$adapter->store([
		'id'=>'mailer-notification',
		'title'=>'Order ready',
		'message'=>'Order can ship.',
		'channels'=>['email'],
		'created_at'=>'2026-07-20T12:00:00Z',
	],'operator');
	$receipts=$adapter->deliver($item,'email');
	$t->same(1,count($messages));
	$t->same('operator@example.test',$messages[0]['to']);
	$t->same('Order ready',$messages[0]['subject']);
	$t->same('delivered',$receipts[0]['status']);
	$t->same('message-1',$receipts[0]['data']['message_id']);
	$t->same(1,count($adapter->deliveryReceipts('operator','mailer-notification')));
	$t->isTrue($adapter->markRead('mailer-notification'));
	$t->same(1,$adapter->counts(false,'operator')['read']);

	$restarted=new PanelDataphyreMailerNotificationAdapter($directory,static fn()=>true,static fn():string=>'operator@example.test');
	$t->same('mailer-notification',$restarted->get('mailer-notification')?->id());
	$t->same(1,count($restarted->deliveryReceipts('operator','mailer-notification')));
	$manifest=json_encode($adapter,JSON_THROW_ON_ERROR);
	$t->notContains($directory,$manifest);
	$t->notContains($secret,$manifest);
	$t->contains('"storage_locator_serialized":false',$manifest);
	$report=(new \Dataphyre\Panel\PanelAdapterConformanceRunner())->run(
		PanelAdapterConformanceCatalog::notificationAdapter(),$adapter,
		['allow_destructive'=>true,'delivery_channel'=>'email','forbidden_fragments'=>[$directory,$secret]]
	);
	$t->isTrue($report->passed());

	$failed=new PanelDataphyreMailerNotificationAdapter(
		$t->tempDirectory('panel-mailer-failed'),
		static fn()=>throw new RuntimeException('api_token=must-not-leak'),
		static fn():string=>'operator@example.test'
	);
	$failedItem=$failed->store(['id'=>'failed','message'=>'Failure','channels'=>['email']]);
	$failedReceipt=$failed->deliver($failedItem,'email')[0];
	$t->same('failed',$failedReceipt['status']);
	$t->notContains('must-not-leak',json_encode($failedReceipt,JSON_THROW_ON_ERROR));
})->tag('panel','adapter-pack','mailer','durability','security')->isolation('case')->maxMillis(5000);

test('first-party Dataphyre pack activates Access Fulltext and Mailer then restores the prior host',static function(Context $t):void {
	$oldDirectory=$t->tempDirectory('panel-old-notifications');
	$oldAdapter=new PanelFilesystemNotificationAdapter($oldDirectory);
	$panel=dp_panel_adapter_pack_surface('dataphyre_framework_pack',['notifications.adapter'=>$oldAdapter]);
	$directory=$t->tempDirectory('panel-new-notifications');
	$secret='first-party-pack-secret';
	$pack=PanelDataphyreAdapterPack::make();
	$config=[
		'adapters'=>[
			'access'=>['options'=>['protect'=>false,'login_page'=>'integration_login']],
			'fulltext'=>[
				'index'=>'orders',
				'search'=>static fn(string $index,string $query,int $limit):array=>[
					['id'=>'order-1','score'=>1.0],
					['id'=>'order-2','score'=>0.5],
				],
				'map'=>static fn(string $id,float $score):array=>['title'=>'Order '.$id,'id'=>$id,'score'=>$score],
				'options'=>['label'=>'Orders'],
			],
			'mailer'=>[
				'directory'=>$directory,
				'send'=>static fn()=>true,
				'recipient'=>static fn():string=>'operator@example.test',
				'options'=>['provider'=>$secret],
			],
		],
		'replace'=>['mailer'=>true],
		'conformance'=>[
			'fulltext'=>['query'=>'order','minimum_results'=>1,'forbidden_fragments'=>[$secret]],
			'mailer'=>['delivery_channel'=>'email','forbidden_fragments'=>[$directory,$secret]],
		],
		'allow_destructive_conformance'=>true,
	];
	$plan=$panel->planAdapterPack($pack,$config);
	$t->isTrue($plan->ready(),implode(' ',$plan->errors()));
	$t->notContains($secret,json_encode($plan,JSON_THROW_ON_ERROR));
	$t->notContains($directory,json_encode($plan,JSON_THROW_ON_ERROR));
	$activation=$panel->installAdapterPack($pack,$config);
	$t->same($plan->fingerprint(),$activation->fingerprint());
	$t->isTrue($panel->hasPlugin('dataphyre_framework_adapters'));
	$t->isTrue($panel->hasPlugin('dataphyre_access'));
	$t->isTrue(isset($panel->pages()['integration_login']));
	$t->isTrue($panel->hasSearchProvider('dataphyre_fulltext'));
	$t->instanceOf(PanelDataphyreMailerNotificationAdapter::class,$panel->platform()->notificationAdapter());
	$t->same(2,count($activation->conformance()));
	$t->same(0,$activation->conformance()['fulltext']->summary()['failed']);
	$t->same(0,$activation->conformance()['mailer']->summary()['failed']);
	$t->notContains($secret,json_encode($activation,JSON_THROW_ON_ERROR));
	$t->notContains($directory,json_encode($activation,JSON_THROW_ON_ERROR));
	$panel->bootPlugins();

	$panel->unregisterPlugin('dataphyre_framework_adapters',true);
	$t->isFalse($panel->hasPlugin('dataphyre_framework_adapters'));
	$t->isFalse($panel->hasPlugin('dataphyre_access'));
	$t->isFalse($panel->hasSearchProvider('dataphyre_fulltext'));
	$t->same($oldAdapter,$panel->platform()->notificationAdapter());
})->tag('panel','adapter-pack','dataphyre','access','fulltext','mailer')->isolation('case')->maxMillis(8000);

test('adapter pack facades expose generic and first-party composition without hidden globals',static function(Context $t):void {
	$generic=Panel::adapterPack('facade_pack','1.2.3',['label'=>'Facade']);
	$firstParty=Panel::dataphyreAdapterPack();
	$panel=dp_panel_adapter_pack_surface('adapter_pack_facades');
	$t->instanceOf(PanelAdapterPack::class,$generic);
	$t->same('facade_pack',$generic->id());
	$t->same('dataphyre_framework_adapters',$firstParty->id());
	$t->instanceOf(PanelAdapterPack::class,$panel->adapterPack('instance_pack'));
	$t->instanceOf(PanelAdapterPack::class,$panel->dataphyreAdapterPack());
	$t->same(4,count($firstParty->bindings()));
	$t->notContains('Closure',json_encode($firstParty,JSON_THROW_ON_ERROR));
	$t->contains('"factories_serialized":false',json_encode($firstParty,JSON_THROW_ON_ERROR));
	$t->instanceOf(PanelDataphyreAccessPlugin::class,new PanelDataphyreAccessPlugin(['protect'=>false]));

	Panel::usePlatform(PanelPlatform::make(),true);
	$staticPack=Panel::adapterPack('static_facade_pack')->binding(PanelAdapterPackBinding::make(
		'value','platform:static.facade',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('static')
	));
	$staticPlan=Panel::planAdapterPack($staticPack);
	$t->isTrue($staticPlan->ready(),implode(' ',$staticPlan->errors()));
	$staticActivation=Panel::installAdapterPack($staticPack);
	$t->same('static_facade_pack',$staticActivation->pack());
	$t->same('static',Panel::platform()->get('static.facade')->value);
	Panel::default()->unregisterPlugin('static_facade_pack',true);
})->tag('panel','adapter-pack','facades','manifest')->isolation('case')->maxMillis(2000);

test('adapter binding context activation and pack APIs close every typed contract edge',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_value_contracts');
	$pack=PanelAdapterPack::make('value_contracts','1.4.0',[
		'label'=>" Value\ncontracts ",
		'description'=>'Typed contracts',
		'publisher'=>'Example Publisher',
		'required_plugins'=>['alpha','beta','alpha'],
	]);
	$t->same(['alpha','beta'],$pack->dependencies());
	$t->same(['alpha','beta'],$pack->requiredPlugins());
	$t->same('Value contracts',$pack->label());
	$t->same('Typed contracts',$pack->description());
	$t->same('Example Publisher',$pack->publisher());
	$t->throws(static fn()=>PanelAdapterPack::make('unknown_option','1.0.0',['extra'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('self_required','1.0.0',['required_plugins'=>['self_required']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('bad_requirements','1.0.0',['required_plugins'=>'alpha']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('bad_requirement_type','1.0.0',['required_plugins'=>[7]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('bad_requirement_name','1.0.0',['required_plugins'=>['Bad Name']]),InvalidArgumentException::class);

	$binding=PanelAdapterPackBinding::make(
		'value','platform:value.contract',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('value'),
		options:[
			'dependencies'=>['alpha'],
			'required_classes'=>[JsonSerializable::class],
			'config_keys'=>['token'],
			'required_config_keys'=>['token'],
			'capabilities'=>['typed','typed'],
			'optional'=>true,
			'replace'=>true,
		]
	);
	$t->same('value',$binding->id());$t->same('platform:value.contract',$binding->target());
	$t->same('platform',$binding->targetType());$t->same('value.contract',$binding->targetName());
	$t->same(JsonSerializable::class,$binding->contract());$t->same(['alpha'],$binding->dependencies());
	$t->same([JsonSerializable::class],$binding->requiredClasses());$t->same(['token'],$binding->configKeys());
	$t->same(['token'],$binding->requiredConfigKeys());$t->same(['typed'],$binding->capabilities());
	$t->isTrue($binding->optional());$t->isTrue($binding->replaceDefault());$t->same(null,$binding->conformance());
	$t->same(64,strlen($binding->runtimeFingerprint()));json_encode($binding,JSON_THROW_ON_ERROR);

	$t->throws(static fn()=>PanelAdapterPackBinding::make('missing_contract','platform:x','Missing\\Contract',static fn()=>new stdClass()),InvalidArgumentException::class);
	$mismatchSuite=PanelAdapterConformanceSuite::make('data_mismatch',PanelDataSource::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('suite_mismatch','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),$mismatchSuite),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('Bad Name','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad_names','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>'x']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad_name_item','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['dependencies'=>[7]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad_classes','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['required_classes'=>'x']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterPackBinding::make('bad_class_item','platform:x',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('x'),options:['required_classes'=>['']]),InvalidArgumentException::class);

	$context=new PanelAdapterPackContext($panel,$pack,['value'=>['token'=>'context-secret','nullable'=>null]]);
	$t->same($panel,$context->panel());$t->same($panel->platform(),$context->platform());$t->same($pack,$context->pack());
	$t->same('context-secret',$context->config('value')['token']);$t->same([], $context->config('missing'));
	$t->same(null,$context->option('value','nullable','fallback'));$t->same('fallback',$context->option('value','missing','fallback'));
	$t->same('context-secret',$context->requireOption('value','token'));
	$t->throws(static fn()=>$context->requireOption('value','missing'),LogicException::class);
	$t->isFalse($context->has('value'));$t->throws(static fn()=>$context->adapter('value'),OutOfBoundsException::class);
	$value=new PanelAdapterPackTestValue('resolved');$context->resolved('value',$value);
	$t->isTrue($context->has('value'));$t->same($value,$context->adapter('value',JsonSerializable::class));
	$t->throws(static fn()=>$context->adapter('value',PanelDataSource::class),UnexpectedValueException::class);
	$t->throws(static fn()=>$context->resolved('value',new stdClass()),LogicException::class);
	$t->throws(static fn()=>$context->resolved('***',new stdClass()),LogicException::class);
	$t->same(['value'=>$value],$context->adapters());
	$t->notContains('context-secret',json_encode($context,JSON_THROW_ON_ERROR));

	$wrong=PanelAdapterPackBinding::make('wrong','platform:wrong',JsonSerializable::class,static fn()=>42);
	$t->throws(static fn()=>$wrong->create($context,[]),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelAdapterPack::make('invalid_bindings')->bindingsFrom([new stdClass()]),InvalidArgumentException::class);
	$composed=PanelAdapterPack::make('composed')->bindingsFrom([
		PanelAdapterPackBinding::make('one','platform:composed.one',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('one')),
		PanelAdapterPackBinding::make('two','platform:composed.two',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('two')),
	]);
	$t->same(2,count($composed->bindings()));

	$empty=PanelAdapterPack::make('empty_pack');
	$t->throws(static fn()=>$panel->plugin($empty),LogicException::class);
	$t->throws(static fn()=>$empty->activation($panel),OutOfBoundsException::class);
	$t->throws(static fn()=>$empty->boot($panel),LogicException::class);
	$empty->unregister();

	$bounded=PanelAdapterPack::make('bounded_pack');
	for($i=0;$i<128;$i++){
		$bounded=$bounded->binding(PanelAdapterPackBinding::make(
			'binding_'.$i,'platform:bounded.'.$i,JsonSerializable::class,
			static fn()=>new PanelAdapterPackTestValue('bounded')
		));
	}
	$t->same(128,count($bounded->bindings()));
	$t->throws(static fn()=>$bounded->binding(PanelAdapterPackBinding::make(
		'overflow','platform:bounded.overflow',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('overflow')
	)),LengthException::class);
})->tag('panel','adapter-pack','value-contracts','bounds','coverage')->isolation('case')->maxMillis(5000);

test('adapter plan grammar covers lists maps flags collisions and process-local digest types',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_plan_grammar');
	$suite=PanelAdapterConformanceSuite::make('plan_pass',JsonSerializable::class)->add(
		PanelAdapterConformanceCase::make('pass',static fn(JsonSerializable $adapter,PanelAdapterConformanceContext $context)=>$context->truthy(true))
	);
	$pack=PanelAdapterPack::make('plan_grammar')
		->binding(PanelAdapterPackBinding::make(
			'primary','platform:plan.primary',JsonSerializable::class,
			static fn():JsonSerializable=>new PanelAdapterPackTestValue('primary'),
			$suite,
			['config_keys'=>['token']]
		))
		->binding(PanelAdapterPackBinding::make(
			'optional','platform:plan.optional',JsonSerializable::class,
			static fn():JsonSerializable=>new PanelAdapterPackTestValue('optional'),
			null,
			['optional'=>true]
		));
	$valid=$pack->plan($panel,[
		'adapters'=>['primary'],
		'replace'=>['primary'=>true],
		'conformance'=>['primary'=>['query'=>'value']],
		'conformance_options'=>['*'=>['shared'=>true],'primary'=>['specific'=>true]],
		'require_conformance'=>false,
		'allow_destructive_conformance'=>true,
		'allow_skipped_conformance'=>true,
	]);
	$t->isTrue($valid->ready(),implode(' ',$valid->errors()));
	$t->same($pack,$valid->pack());$t->same($panel,$valid->panel());
	$t->same(64,strlen($valid->configDigest()));$t->same(64,strlen($valid->stateFingerprint()));
	$t->isFalse($valid->requireConformance());$t->isTrue($valid->allowDestructiveConformance());$t->isTrue($valid->allowSkippedConformance());
	$t->isTrue($valid->replace('primary'));$t->isTrue($valid->runsConformance('primary'));
	$t->same(['shared'=>true,'specific'=>true,'query'=>'value'],$valid->conformanceOptions('primary'));
	$t->same([], $valid->bindingConfig('missing'));$t->isFalse($valid->replace('missing'));
	$t->isFalse($valid->runsConformance('missing'));$t->same([], $valid->conformanceOptions('missing'));
	$entryOverrides=$pack->plan($panel,['adapters'=>['primary'=>[
		'replace'=>true,
		'conformance'=>true,
		'conformance_options'=>['entry'=>true],
	]]]);
	$t->isTrue($entryOverrides->ready(),implode(' ',$entryOverrides->errors()));
	$t->isTrue($entryOverrides->replace('primary'));
	$t->same(['entry'=>true],$entryOverrides->conformanceOptions('primary'));

	$requiredPack=PanelAdapterPack::make('required_config')->binding(PanelAdapterPackBinding::make(
		'required','platform:required.config',JsonSerializable::class,
		static fn():JsonSerializable=>new PanelAdapterPackTestValue('required'),
		options:['config_keys'=>['token'],'required_config_keys'=>['token']]
	));
	$missingRequired=$requiredPack->plan($panel);
	$t->isFalse($missingRequired->ready());
	$t->contains("requires configuration key 'token'",implode(' ',$missingRequired->errors()));

	$configs=[
		['require_conformance'=>'yes'],
		['allow_destructive_conformance'=>1],
		['allow_skipped_conformance'=>null],
		['adapters'=>'primary'],
		['adapters'=>[7]],
		['adapters'=>['primary'=>7]],
		['adapters'=>['primary'=>false]],
		['adapters'=>['primary'=>['unknown'=>'x']]],
		['adapters'=>['primary'=>['replace'=>'yes']]],
		['adapters'=>['primary'=>['conformance'=>'yes']]],
		['adapters'=>['primary'=>['conformance_options'=>'yes']]],
		['adapters'=>['primary'=>['conformance'=>true]],'conformance_options'=>['primary'=>'yes']],
		['replace'=>['primary']],
		['replace'=>['missing'=>true]],
		['replace'=>['primary'=>'yes']],
		['conformance'=>'yes'],
		['conformance'=>['missing']],
		['conformance'=>['missing'=>true]],
		['conformance'=>['primary'=>7]],
		['conformance_options'=>['invalid-list']],
		['conformance_options'=>['missing'=>[]]],
		['conformance_options'=>['primary'=>'yes']],
	];
	foreach($configs as$config){
		$plan=$pack->plan($panel,$config);
		$t->isFalse($plan->ready(),json_encode($config,JSON_THROW_ON_ERROR));
		$t->throws(static fn()=>$plan->assertReady(),LogicException::class);
	}
	$withoutSuite=$pack->plan($panel,['adapters'=>['optional'=>true],'conformance'=>['optional']]);
	$t->isFalse($withoutSuite->ready());$t->contains('does not declare a conformance suite',implode(' ',$withoutSuite->errors()));

	$live=fopen('php://memory','r+');$closed=fopen('php://memory','r');fclose($closed);
	$digest=$pack->plan($panel,['adapters'=>['primary'=>['token'=>[
		null,true,7,1.25,NAN,INF,-INF,'text',$live,$closed,
		PanelAdapterPackUnitValue::One,PanelAdapterPackBackedValue::One,new stdClass(),static fn()=>true,
		'associative'=>['z'=>1,'a'=>2],
	]]]]);
	fclose($live);
	$t->isTrue($digest->ready(),implode(' ',$digest->errors()));
	$t->same(64,strlen($digest->configDigest()));

	$panel->registerSearchProvider(PanelSearchProvider::make('collision'));
	$searchPack=PanelAdapterPack::make('search_collision')->binding(PanelAdapterPackBinding::make(
		'search','search:collision',PanelSearchProvider::class,static fn()=>PanelSearchProvider::make('collision')
	));
	$t->isFalse($searchPack->plan($panel)->ready());
	$t->isTrue($searchPack->plan($panel,['replace'=>true])->ready());
	$panel->plugin(new PanelAdapterPackNamedPlugin('child_collision'));
	$pluginPack=PanelAdapterPack::make('plugin_collision')->binding(PanelAdapterPackBinding::make(
		'plugin','plugin:child_collision',PanelPlugin::class,static fn()=>new PanelAdapterPackNamedPlugin('child_collision')
	));
	$t->isFalse($pluginPack->plan($panel,['replace'=>true])->ready());
	$panel->registerDataSource('collision',new PanelArrayDataSource([]));
	$dataPack=PanelAdapterPack::make('data_collision')->binding(PanelAdapterPackBinding::make(
		'data','data:collision',PanelDataSource::class,static fn()=>new PanelArrayDataSource([])
	));
	$t->isFalse($dataPack->plan($panel)->ready());$t->isTrue($dataPack->plan($panel,['replace'=>true])->ready());

	$noData=Panel::make('adapter_pack_no_data')->usePlatform(PanelPlatform::make());
	$t->isFalse($dataPack->plan($noData)->ready());
	$noPlatform=Panel::make('adapter_pack_no_platform');
	$noPlatformPlan=$pack->plan($noPlatform);
	$t->isFalse($noPlatformPlan->ready());$t->contains('target preflight failed',implode(' ',$noPlatformPlan->errors()));
	$unstaged=PanelAdapterPackPlan::forRegistration($pack,$panel);
	$t->isFalse($unstaged->ready());$t->contains('not staged',implode(' ',$unstaged->errors()));

	$installed=PanelAdapterPack::make('already_installed')->binding(PanelAdapterPackBinding::make(
		'value','platform:already.value',JsonSerializable::class,static fn()=>new PanelAdapterPackTestValue('value')
	));
	$installed->install($panel);
	$t->isFalse($installed->plan($panel)->ready());
	$panel->unregisterPlugin('already_installed');
})->tag('panel','adapter-pack','plan','grammar','digest','collisions')->isolation('case')->maxMillis(6000);

test('Fulltext bridge accepts page objects hit objects generators scalar ids and mapper variants',static function(Context $t):void {
	$request=PanelRequest::fromArray([]);
	$t->throws(static fn()=>new PanelDataphyreFulltextSearchAdapter('Bad Name','index',static fn()=>[]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataphyreFulltextSearchAdapter('valid','',static fn()=>[]),InvalidArgumentException::class);
	$page=\Dataphyre\Panel\PanelSearchPage::make([PanelSearchResult::make('Ready','page')]);
	$pageAdapter=new PanelDataphyreFulltextSearchAdapter('page','index',static fn()=>$page);
	$t->same($page,$pageAdapter->page('query',$request));
	$t->same('page',$pageAdapter->name());$t->same('index',$pageAdapter->index());

	$hitObject=new class {
		public function hits():array{return[
			new class {public function id():string{return'object';}public function score():float{return.9;}},
			new class {public function id():string{return'no_score';}},
		];}
		public function total():int{return 2;}
	};
	$objectAdapter=new PanelDataphyreFulltextSearchAdapter(
		'objects','index',static fn()=>$hitObject,
		static fn(string $id,float $score):PanelSearchResult=>PanelSearchResult::make($id,'objects')->withScore($score),
		['tenant_scoped'=>true,'tenant_required'=>false,'authorize'=>static fn()=>true,'visible'=>static fn()=>true,'score'=>static fn(PanelSearchResult $result)=>$result->score(),'dedupe'=>static fn(PanelSearchResult $result)=>$result->recordKey(),'fetch_multiplier'=>1]
	);
	$objectPage=$objectAdapter->page('query',$request,2);
	$t->same(2,count($objectPage));$t->same('object',$objectPage->results()[0]->title());

	$scalarAdapter=new PanelDataphyreFulltextSearchAdapter('scalars','index',static fn()=>['one',2,['id'=>null,'extra'=>true],['id'=>'','score'=>INF],['id'=>new stdClass()]]);
	$t->same(2,count($scalarAdapter->page('query',$request,10)));
	$t->same('one',$scalarAdapter->page('query',$request,10)->results()[0]->title());
	$nonArrayMapper=new PanelDataphyreFulltextSearchAdapter('null_mapper','index',static fn()=>[['id'=>'one']],static fn()=>false);
	$t->same(0,count($nonArrayMapper->page('query',$request)));
	$augmentMapper=new PanelDataphyreFulltextSearchAdapter('augment_mapper','index',static fn()=>[['id'=>'one','score'=>.5]],static fn()=>['title'=>'Mapped']);
	$augmented=$augmentMapper->page('query',$request)->results()[0]->toArray();
	$t->same('one',$augmented['record_key']);$t->same(.5,$augmented['score']);

	$generator=static function():Generator {yield ['id'=>'one'];throw new RuntimeException('iteration failed');};
	$iteration=new PanelDataphyreFulltextSearchAdapter('iteration','index',static fn()=>$generator());
	$iterationPage=$iteration->page('query',$request);
	$t->isTrue($iterationPage->isPartial());$t->same('fulltext_iteration_error',$iterationPage->diagnostics()[0]['code']);
	$budget=new PanelDataphyreFulltextSearchAdapter('budget','index',static function():Generator {for($i=0;$i<30;$i++){yield ['id'=>(string)$i];}},static fn()=>null);
	$t->isTrue($budget->page('query',$request,1)->isPartial());
	$badHits=new PanelDataphyreFulltextSearchAdapter('bad_hits','index',static fn()=>new class {public function hits():int{return 7;}});
	$t->throws(static fn()=>$badHits->page('query',$request),UnexpectedValueException::class);
	$badRows=new PanelDataphyreFulltextSearchAdapter('bad_rows','index',static fn()=>['results'=>7]);
	$t->throws(static fn()=>$badRows->page('query',$request),UnexpectedValueException::class);
})->tag('panel','adapter-pack','fulltext','variants','coverage')->isolation('case')->maxMillis(4000);

test('Mailer bridge covers resolver message result and delegated feed variants',static function(Context $t):void {
	$t->throws(static fn()=>new PanelDataphyreMailerNotificationAdapter('',static fn()=>true,static fn()=>'x'),InvalidArgumentException::class);
	$directory=$t->tempDirectory('panel-mailer-variants');
	$adapter=new PanelDataphyreMailerNotificationAdapter(
		$directory,
		static fn(mixed $message)=>['ok'=>true,'provider'=>'array','status'=>'accepted','id'=>'array-id'],
		static fn()=>'operator@example.test',
		['channel'=>'mail','channels'=>'database','snapshot_retention'=>1,'delivery_retention'=>1,'message'=>static fn($notification,$target)=>['to'=>$target,'subject'=>'Factory','text'=>'Factory']]
	);
	$item=$adapter->store(['id'=>'variant','message'=>'Variant','channels'=>['mail']]);
	$t->same('delivered',$adapter->deliver($item,'mail')[0]['status']);
	$t->same(1,count($adapter->unread()));$t->isTrue($adapter->markRead('variant'));$t->same(1,count($adapter->read()));
	$t->isTrue($adapter->cursor()>0);$t->isTrue(is_array($adapter->changesSince(0,10)['changes']));
	$adapter->meta('api_token','must-redact');$t->contains('[REDACTED]',json_encode($adapter->manifest(['password'=>'hidden']),JSON_THROW_ON_ERROR));
	$t->isTrue($adapter->delete('variant'));

	$resolverFailure=new PanelDataphyreMailerNotificationAdapter(
		$t->tempDirectory('panel-mailer-resolver-failure'),static fn()=>true,static fn()=>throw new RuntimeException('resolver secret')
	);
	$resolverItem=$resolverFailure->store(['id'=>'resolver','message'=>'Resolver','channels'=>['email']]);
	$t->same('recipient_resolver_failed',$resolverFailure->deliver($resolverItem,'email')[0]['data']['code']);
	$unavailable=new PanelDataphyreMailerNotificationAdapter(
		$t->tempDirectory('panel-mailer-unavailable'),static fn()=>true,static fn()=>null
	);
	$unavailableItem=$unavailable->store(['id'=>'unavailable','message'=>'Unavailable','channels'=>['email']]);
	$t->same('rejected',$unavailable->deliver($unavailableItem,'email')[0]['status']);
	$action=new PanelDataphyreMailerNotificationAdapter(
		$t->tempDirectory('panel-mailer-action'),static fn()=>true,static fn()=>'operator@example.test'
	);
	$actionItem=$action->store(['id'=>'action','message'=>'Action','action_label'=>'Review','action_url'=>'/orders/1','channels'=>['email']]);
	$t->same('delivered',$action->deliver($actionItem,'email')[0]['status']);

	foreach([
		['result'=>'queued','expected'=>'delivered'],
		['result'=>7,'expected'=>'delivered'],
		['result'=>new stdClass(),'expected'=>'failed'],
		['result'=>['ok'=>false,'status'=>'rejected'],'expected'=>'failed'],
	] as$index=>$case){
		$resultAdapter=new PanelDataphyreMailerNotificationAdapter(
			$t->tempDirectory('panel-mailer-result-'.$index),static fn()=>$case['result'],static fn()=>'operator@example.test'
		);
		$resultItem=$resultAdapter->store(['id'=>'result-'.$index,'message'=>'Result','channels'=>['email']]);
		$t->same($case['expected'],$resultAdapter->deliver($resultItem,'email')[0]['status']);
	}
})->tag('panel','adapter-pack','mailer','variants','coverage')->isolation('case')->maxMillis(5000);

test('target identity mismatches fail transactionally before registration leaks',static function(Context $t):void {
	$panel=dp_panel_adapter_pack_surface('adapter_pack_identity_mismatch');
	$search=PanelAdapterPack::make('wrong_search')->binding(PanelAdapterPackBinding::make(
		'search','search:expected_search',PanelSearchProvider::class,static fn()=>PanelSearchProvider::make('wrong_search')
	));
	$t->throws(static fn()=>$search->install($panel),UnexpectedValueException::class);
	$t->isFalse($panel->hasPlugin('wrong_search'));$t->isFalse($panel->hasSearchProvider('wrong_search'));
	$plugin=PanelAdapterPack::make('wrong_plugin')->binding(PanelAdapterPackBinding::make(
		'plugin','plugin:expected_plugin',PanelPlugin::class,static fn()=>new PanelAdapterPackNamedPlugin('wrong_plugin_child')
	));
	$t->throws(static fn()=>$plugin->install($panel),UnexpectedValueException::class);
	$t->isFalse($panel->hasPlugin('wrong_plugin'));$t->isFalse($panel->hasPlugin('wrong_plugin_child'));
})->tag('panel','adapter-pack','identity','rollback','coverage')->isolation('case')->maxMillis(3000);
