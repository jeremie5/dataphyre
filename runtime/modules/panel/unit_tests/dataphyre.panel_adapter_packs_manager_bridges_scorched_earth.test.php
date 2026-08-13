<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\FulltextEngine\SearchManager;
use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\MailerManager;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Panel\PanelDataphyreFulltextSearchAdapter;
use Dataphyre\Panel\PanelDataphyreMailerNotificationAdapter;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>[
			'core'=>true,
			'datadoc'=>true,
			'fulltext_engine'=>true,
			'mailer'=>true,
			'panel'=>true,
			'permission'=>true,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_FULLTEXT_ENGINE_CFG')){
	define('DP_FULLTEXT_ENGINE_CFG',[
		'framework'=>[
			'default_language'=>'en',
			'default_limit'=>20,
			'default_boolean_mode'=>true,
			'default_threshold'=>0.25,
			'default_algorithms'=>'default',
		],
	]);
}
if(!defined('DP_MAILER_CFG')){
	define('DP_MAILER_CFG',[
		'default_provider'=>'panel_bridge',
		'providers'=>['panel_bridge'=>['driver'=>'panel_bridge']],
		'delivery_safety'=>['enabled'=>false],
		'idempotency'=>['enabled'=>false],
		'suppression'=>['enabled'=>false],
	]);
}

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!function_exists('tracelog')){
	function tracelog(...$arguments):void {}
}
if(!class_exists('dataphyre\\core',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function dialback(string $name,mixed ...$arguments):mixed{return null;}
	public static function load_framework_module(string $module):bool{return false;}
	public static function load_framework_modules(array $modules):void{}
}
PHP);
}
if(!class_exists('dataphyre\\fulltext_engine',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class fulltext_engine {
	public static array $calls=[];
	public static function search(
		string $index,
		array $criteria,
		string $language='en',
		int $limit=50,
		bool $boolean=true,
		float $threshold=0.3,
		string $algorithms=''
	):array {
		self::$calls[]=compact('index','criteria','language','limit','boolean','threshold','algorithms');
		return [
			'results'=>[['order-1'=>0.95],['order-2'=>0.75],['order-3'=>0.5]],
			'count'=>3,
			'certainty'=>0.8,
			'time'=>0.001,
		];
	}
}
PHP);
}
\dataphyre\autoloader::register_framework_modules(['fulltext_engine','mailer']);

final class PanelAdapterPackMailerProvider implements MailProvider {
	/** @var list<array{message:array<string,mixed>,options:array<string,mixed>}> */
	public static array $calls=[];
	public function name():string{return 'panel_bridge';}
	public function send(Message $message,array $options=[]):SendResult {
		self::$calls[]=['message'=>$message->toArray(),'options'=>$options];
		return SendResult::success($this->name(),202,'Accepted','panel-message-1');
	}
}

suite('Panel first-party manager bridges')
	->contract('panel.adapter-packs.manager-bridges',1)
	->layer('integration')
	->risk('high')
	->watches('module:panel','module:fulltext_engine','module:mailer')
	->through('fulltext-manager','mailer-manager','typed-results')
	->tag('panel','adapter-pack','scorched-earth','manager-bridge')
	->group('panel-platform-contract');

test('Fulltext manager bridge normalizes criteria and forwards bounded search options',static function(Context $t):void {
	\dataphyre\fulltext_engine::$calls=[];
	SearchManager::flush();
	$manager=SearchManager::instance();
	$request=PanelRequest::fromArray(['tenant'=>'north','input'=>['tenant'=>'north']]);
	$adapter=PanelDataphyreFulltextSearchAdapter::fromManager(
		'manager_search',
		'orders',
		$manager,
		static fn(string $id,float $score):array=>[
			'title'=>'Order '.$id,
			'record_key'=>$id,
			'score'=>$score,
		],
		[
			'criteria'=>static fn(string $query,PanelRequest $request,string $index):array=>[
				'title'=>$query,
				'tenant'=>$request->input('tenant'),
				'index'=>$index,
			],
			'language'=>'fr',
			'boolean_mode'=>false,
			'threshold'=>0.6,
			'algorithms'=>'bm25',
			'fetch_multiplier'=>1,
		]
	);
	$page=$adapter->page('Needle',$request,2);
	$t->same(2,count($page));
	$t->same('Order order-1',$page->results()[0]->title());
	$t->same(1,count(\dataphyre\fulltext_engine::$calls));
	$call=\dataphyre\fulltext_engine::$calls[0];
	$t->same('orders',$call['index']);
	$t->same(['title'=>'Needle','tenant'=>'north','index'=>'orders'],$call['criteria']);
	$t->same('fr',$call['language']);
	$t->same(20,$call['limit']);
	$t->isFalse($call['boolean']);
	$t->same(0.6,$call['threshold']);
	$t->same('bm25',$call['algorithms']);

	$defaultCriteria=PanelDataphyreFulltextSearchAdapter::fromManager(
		'default_criteria','orders',$manager,null,['criteria_field'=>'caption']
	);
	$defaultCriteria->page('Default',$request,1);
	$t->same(['caption'=>'Default'],\dataphyre\fulltext_engine::$calls[1]['criteria']);

	$invalidCriteria=PanelDataphyreFulltextSearchAdapter::fromManager(
		'invalid_criteria','orders',$manager,null,['criteria'=>static fn():array=>[]]
	);
	$t->throws(static fn()=>$invalidCriteria->page('Invalid',$request),UnexpectedValueException::class);
})->tag('panel','adapter-pack','fulltext','manager','coverage')->isolation('case')->maxMillis(3000);

test('Mailer manager bridge dispatches a typed message through a configured provider',static function(Context $t):void {
	PanelAdapterPackMailerProvider::$calls=[];
	MailerManager::flushInstance();
	$manager=MailerManager::instance();
	$manager->extend('panel_bridge',static fn():MailProvider=>new PanelAdapterPackMailerProvider());
	$probe=$manager->send([
		'to'=>'operator@example.test',
		'subject'=>'Bridge probe',
		'text'=>'Bridge probe',
	],'panel_bridge',['transport_trace'=>'probe']);
	$t->isTrue($probe->ok());
	PanelAdapterPackMailerProvider::$calls=[];
	$adapter=PanelDataphyreMailerNotificationAdapter::fromManager(
		$t->tempDirectory('panel-adapter-pack-mailer-manager'),
		$manager,
		static fn()=>'operator@example.test',
		[
			'provider'=>'panel_bridge',
			'send_options'=>['transport_trace'=>'adapter-pack'],
		]
	);
	$notification=$adapter->store([
		'id'=>'manager-mail',
		'title'=>'Order requires review',
		'message'=>'Review order SO-1.',
		'channels'=>['email'],
	]);
	$receipt=$adapter->deliver($notification,'email')[0];
	$t->same('delivered',$receipt['status'],json_encode($receipt,JSON_THROW_ON_ERROR));
	$t->same('panel_bridge',$receipt['data']['provider']);
	$t->same(202,$receipt['data']['status']);
	$t->same('panel-message-1',$receipt['data']['message_id']);
	$t->same(1,count(PanelAdapterPackMailerProvider::$calls));
	$call=PanelAdapterPackMailerProvider::$calls[0];
	$t->same('operator@example.test',$call['message']['to'][0]['email']);
	$t->same('Order requires review',$call['message']['subject']);
	$t->same('Review order SO-1.',$call['message']['text']);
	$t->same('adapter-pack',$call['options']['transport_trace']);
})->tag('panel','adapter-pack','mailer','manager','coverage')->isolation('case')->maxMillis(4000);
