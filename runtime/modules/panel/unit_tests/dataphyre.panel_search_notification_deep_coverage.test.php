<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelNotificationItem;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

/** @return list<string> */
function dp_panel_search_notification_trace_names(): array {
	return array_values(array_map(
		static fn(array $event): string=>(string)($event['event'] ?? ''),
		PanelTrace::events()
	));
}

test('panel search providers import immutable definitions and normalize callback results',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET','query'=>['search'=>'alice']]);
	$manager=new PanelManager();
	$handlerCalls=0;
	$searchCalls=[];
	$provider=PanelSearchProvider::fromArray([
		'name'=>' Customer Records ',
		'label'=>' Customers ',
		'description'=>' Directory results ',
		'icon'=>' users ',
		'sort'=>'12',
		'limit'=>2,
		'handler'=>static function()use(&$handlerCalls): array {
			$handlerCalls++;
			return [];
		},
		'search'=>static function(string $query,PanelRequest $resolvedRequest,PanelSearchProvider $resolvedProvider,int $limit,?PanelManager $resolvedManager)use(&$searchCalls): array {
			$searchCalls[]=[$query,$resolvedRequest,$resolvedProvider,$limit,$resolvedManager];
			return [
				'invalid row',
				['title'=>'   '],
				[
					'title'=>' Alice Adams ',
					'resource_label'=>'People',
					'source_label'=>'CRM',
					'subtitle'=>'Primary account',
					'record_key'=>' A-1 ',
					'url'=>' /customers/A-1 ',
					'icon'=>'user-check',
					'meta'=>['tier'=>'gold'],
				],
				[
					'label'=>'Bob Brown',
					'description'=>'Secondary account',
					'key'=>' B-2 ',
					'href'=>' /customers/B-2 ',
					'meta'=>'invalid',
				],
				['title'=>'Ignored after limit'],
			];
		},
		'hidden'=>true,
		'meta'=>['source'=>'definition'],
	]);
	$data=$provider->toArray();
	$t->same('customer_records',$provider->name());
	$t->same('Customers',$data['label']);
	$t->same('Directory results',$data['description']);
	$t->same('users',$data['icon']);
	$t->same(12,$data['sort']);
	$t->same(2,$data['limit']);
	$t->isTrue($data['hidden']);
	$t->isFalse($data['visible_lazy']);
	$t->isTrue($data['search_lazy']);
	$t->same(['source'=>'definition'],$data['meta']);

	$results=$provider->search('  alice  ',$request,$manager);
	$t->same(0,$handlerCalls);
	$t->same(1,count($searchCalls));
	$t->same('alice',$searchCalls[0][0]);
	$t->same($request,$searchCalls[0][1]);
	$t->same($provider,$searchCalls[0][2]);
	$t->same(2,$searchCalls[0][3]);
	$t->same($manager,$searchCalls[0][4]);
	$t->same(2,count($results));
	$t->same('customer_records',$results[0]['resource']);
	$t->same('People',$results[0]['resource_label']);
	$t->same('customer_records',$results[0]['source']);
	$t->same('CRM',$results[0]['source_label']);
	$t->same('Alice Adams',$results[0]['title']);
	$t->same('Primary account',$results[0]['subtitle']);
	$t->same('A-1',$results[0]['record_key']);
	$t->same('/customers/A-1',$results[0]['url']);
	$t->same('user-check',$results[0]['icon']);
	$t->same(['tier'=>'gold'],$results[0]['meta']);
	$t->same('Bob Brown',$results[1]['title']);
	$t->same('Secondary account',$results[1]['subtitle']);
	$t->same('B-2',$results[1]['record_key']);
	$t->same('/customers/B-2',$results[1]['url']);
	$t->same('users',$results[1]['icon']);
	$t->same([],$results[1]['meta']);

	$base=PanelSearchProvider::make('order-items');
	$configured=$base
		->label(' Order Items ')
		->description(' Order item lookup ')
		->icon(' package ')
		->sort(-5)
		->limit(99)
		->hide(false)
		->meta(['one'=>1,'override'=>'first'])
		->meta(['two'=>2,'override'=>'second']);
	$t->same('Order Items',$base->toArray()['label']);
	$t->same('Order Items',$configured->toArray()['label']);
	$t->same('Order item lookup',$configured->toArray()['description']);
	$t->same('package',$configured->toArray()['icon']);
	$t->same(-5,$configured->toArray()['sort']);
	$t->same(50,$configured->toArray()['limit']);
	$t->same(['one'=>1,'override'=>'second','two'=>2],$configured->toArray()['meta']);
	$t->same(1,$configured->limit(0)->toArray()['limit']);
	$t->same(null,$configured->description(' ')->toArray()['description']);
	$t->same(null,$configured->icon(' ')->toArray()['icon']);
	$t->same('',PanelSearchProvider::make('...')->name());
	$t->same('',PanelSearchProvider::make('...')->toArray()['label']);
})->tag('panel','search-provider','notification-item','coverage')->group('framework-coverage');

test('panel search providers resolve visibility limits and failures safely',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET']);
	$manager=new PanelManager();
	PanelTrace::flush();

	$t->isFalse(PanelSearchProvider::make('hidden')->hide()->visibleUsing(
		static function(): never { throw new RuntimeException('must not run'); }
	)->isVisible($request,$manager));
	$t->isTrue(PanelSearchProvider::make('default')->isVisible($request,$manager));
	$t->isTrue(PanelSearchProvider::make('lazy')->visibleUsing(
		static fn(?PanelRequest $resolvedRequest,PanelSearchProvider $resolvedProvider,?PanelManager $resolvedManager): bool=>
			$resolvedRequest!==null && $resolvedProvider->name()==='lazy' && $resolvedManager!==null
	)->isVisible($request,$manager));
	$t->isFalse(PanelSearchProvider::make('broken-visibility')->visibleUsing(
		static function(): never { throw new RuntimeException('visibility exploded'); }
	)->isVisible($request,$manager));

	$plain=PanelSearchProvider::make('plain');
	$t->same([],$plain->search('query',$request,$manager));
	$t->same([],$plain->search('   ',$request,$manager));
	$nonArray=$plain->searchUsing(static fn(): string=>'not an array');
	$t->same([],$nonArray->search('query',$request,$manager));
	$limitCalls=[];
	$limited=$plain->searchUsing(static function(string $query,PanelRequest $request,PanelSearchProvider $provider,int $limit,?PanelManager $manager)use(&$limitCalls): array {
		$limitCalls[]=$limit;
		return [['title'=>'One'],['title'=>'Two']];
	});
	$t->same(1,count($limited->search('query',$request,$manager,0)));
	$t->same(2,count($limited->search('query',$request,$manager,99)));
	$t->same([1,50],$limitCalls);
	$t->same([],$plain->searchUsing(
		static function(): never { throw new RuntimeException('search exploded'); }
	)->search('query',$request,$manager));

	$names=dp_panel_search_notification_trace_names();
	$t->contains('search_provider.visibility_error',$names);
	$t->contains('search_provider.error',$names);
	$events=PanelTrace::events();
	$t->isTrue(count(array_filter($events,static fn(array $event): bool=>
		in_array((string)($event['event'] ?? ''),['search_provider.visibility_error','search_provider.error'],true)
		&& ($event['context']['exception'] ?? null)===RuntimeException::class
		&& !array_key_exists('message',$event['context'] ?? [])
	))>=2);
	$traceJson=(string)json_encode($events);
	$t->isFalse(str_contains($traceJson,'visibility exploded'));
	$t->isFalse(str_contains($traceJson,'search exploded'));
})->tag('panel','search-provider','notification-item','coverage')->group('framework-coverage');

test('panel notification items import immutable definitions and resolve lazy fields safely',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET']);
	$manager=new PanelManager();
	$fromArray=PanelNotificationItem::fromArray([
		'name'=>' Billing Alerts ',
		'title'=>'Original title',
		'label'=>' Billing notices ',
		'message'=>' Three invoices require attention ',
		'type'=>'WARNING',
		'icon'=>' alert-triangle ',
		'url'=>' /panel/billing ',
		'sort'=>'15',
		'count'=>3,
		'hidden'=>true,
		'meta'=>['source'=>'definition'],
	]);
	$fromArrayData=$fromArray->toArray($request,$manager);
	$t->same('billing_alerts',$fromArray->name());
	$t->same('Billing notices',$fromArrayData['title']);
	$t->same('Three invoices require attention',$fromArrayData['message']);
	$t->same('warning',$fromArrayData['type']);
	$t->same('alert-triangle',$fromArrayData['icon']);
	$t->same('/panel/billing',$fromArrayData['url']);
	$t->same(3,$fromArrayData['count']);
	$t->same(15,$fromArrayData['sort']);
	$t->isTrue($fromArrayData['hidden']);
	$t->same(['source'=>'definition'],$fromArrayData['meta']);
	$t->isFalse($fromArrayData['visible_lazy']);
	$t->isFalse($fromArrayData['url_lazy']);
	$t->isFalse($fromArrayData['count_lazy']);
	$t->isFalse($fromArray->isVisible($request,$manager));

	$base=PanelNotificationItem::make('system-health');
	$configured=$base
		->title(' System Health ')
		->message(' All services available ')
		->type('SUCCESS')
		->icon(' activity ')
		->url(' /panel/health ')
		->count(4)
		->sort(-2)
		->hide(false)
		->meta(['one'=>1,'override'=>'first'])
		->meta(['two'=>2,'override'=>'second']);
	$baseData=$base->toArray();
	$configuredData=$configured->toArray();
	$t->same('System Health',$baseData['title']);
	$t->same('System Health',$configuredData['title']);
	$t->same('All services available',$configuredData['message']);
	$t->same('success',$configuredData['type']);
	$t->same('activity',$configuredData['icon']);
	$t->same('/panel/health',$configuredData['url']);
	$t->same(4,$configuredData['count']);
	$t->same(-2,$configuredData['sort']);
	$t->same(['one'=>1,'override'=>'second','two'=>2],$configuredData['meta']);
	$t->same('info',$configured->type('unsupported')->toArray()['type']);
	$t->same(null,$configured->icon(' ')->toArray()['icon']);
	$t->same('',PanelNotificationItem::make('...')->name());
	$t->same('',PanelNotificationItem::make('...')->toArray()['title']);
	$t->isTrue($configured->isVisible($request,$manager));

	$calls=[];
	$lazy=$configured
		->visibleUsing(static function(?PanelRequest $resolvedRequest,PanelNotificationItem $resolvedItem,?PanelManager $resolvedManager)use(&$calls): bool {
			$calls[]=['visible',$resolvedRequest,$resolvedItem->name(),$resolvedManager];
			return true;
		})
		->url(static function(?PanelRequest $resolvedRequest,PanelNotificationItem $resolvedItem,?PanelManager $resolvedManager)use(&$calls): string {
			$calls[]=['url',$resolvedRequest,$resolvedItem->name(),$resolvedManager];
			return ' /panel/lazy ';
		})
		->count(static function(?PanelRequest $resolvedRequest,PanelNotificationItem $resolvedItem,?PanelManager $resolvedManager)use(&$calls): int {
			$calls[]=['count',$resolvedRequest,$resolvedItem->name(),$resolvedManager];
			return 9;
		});
	$t->isTrue($lazy->isVisible($request,$manager));
	$lazyData=$lazy->toArray($request,$manager);
	$t->same(' /panel/lazy ',$lazyData['url']);
	$t->same(9,$lazyData['count']);
	$t->isTrue($lazyData['visible_lazy']);
	$t->isTrue($lazyData['url_lazy']);
	$t->isTrue($lazyData['count_lazy']);
	$t->same(['visible','url','count'],array_column($calls,0));
	$t->same($request,$calls[1][1]);
	$t->same('system-health',$calls[1][2]);
	$t->same($manager,$calls[1][3]);
	$staticAgain=$lazy->url('/fixed')->count(7)->toArray($request,$manager);
	$t->same('/fixed',$staticAgain['url']);
	$t->same(7,$staticAgain['count']);
	$t->isFalse($staticAgain['url_lazy']);
	$t->isFalse($staticAgain['count_lazy']);

	PanelTrace::flush();
	$broken=PanelNotificationItem::make('broken')
		->visibleUsing(static function(): never { throw new RuntimeException('notification visibility exploded'); })
		->url(static function(): never { throw new RuntimeException('notification url exploded'); })
		->count(static function(): never { throw new RuntimeException('notification count exploded'); });
	$t->isFalse($broken->isVisible($request,$manager));
	$brokenData=$broken->toArray($request,$manager);
	$t->same(null,$brokenData['url']);
	$t->same(null,$brokenData['count']);
	$names=dp_panel_search_notification_trace_names();
	$t->contains('notification_item.visibility_error',$names);
	$t->contains('notification_item.url_error',$names);
	$t->contains('notification_item.count_error',$names);
})->tag('panel','search-provider','notification-item','coverage')->group('framework-coverage');
