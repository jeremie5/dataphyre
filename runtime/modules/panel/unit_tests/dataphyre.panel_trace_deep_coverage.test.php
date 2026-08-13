<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {
			\Dataphyre\Panel\TestFixtures\PanelTraceProbe::recordTrace($arguments);
		}
	}
}

namespace Dataphyre\Panel {
	if(!function_exists(__NAMESPACE__.'\random_bytes')){
		function random_bytes(int $length): string {
			if(\Dataphyre\Panel\TestFixtures\PanelTraceProbe::randomBytesShouldFail()){
				throw new \RuntimeException('random source unavailable');
			}
			return \random_bytes($length);
		}
	}
	if(!function_exists(__NAMESPACE__.'\session_status')){
		function session_status(): int {
			return \Dataphyre\Panel\TestFixtures\PanelTraceProbe::sessionStatus();
		}
	}
}

namespace {
	use Dataphyre\Panel\Action;
	use Dataphyre\Panel\Column;
	use Dataphyre\Panel\Field;
	use Dataphyre\Panel\PanelActionState;
	use Dataphyre\Panel\PanelCommand;
	use Dataphyre\Panel\PanelCommandState;
	use Dataphyre\Panel\PanelFormState;
	use Dataphyre\Panel\PanelInfolistState;
	use Dataphyre\Panel\PanelNavigationState;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelRelationState;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Panel\PanelSurfaceState;
	use Dataphyre\Panel\PanelTableState;
	use Dataphyre\Panel\PanelTrace;
	use Dataphyre\Panel\PanelWidgetState;
	use Dataphyre\Panel\TestFixtures\PanelTraceProbe;
	use Dataphyre\Panel\RelationManager;
	use Dataphyre\Panel\Resource;
	use Dataphyre\Panel\Widget;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\before_each;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel']);
	before_each(static function(Context $t): void {
		PanelTraceProbe::reset($t);
	});

	/** @return list<string> */
	function dp_panel_trace_names(): array {
		return array_values(array_map(
			static fn(array $event): string=>(string)($event['event'] ?? ''),
			PanelTrace::events()
		));
	}

	test('panel trace records span completion failures summaries and listener forwarding',static function(Context $t): void {
		$trace=PanelTraceProbe::active()->withoutSession();
		PanelTrace::flush();

		PanelTrace::record('',[
			'resource'=>Resource::make('orders'),
			'ignored-numeric-key'=>'kept',
			0=>'ignored',
		]);
		$completed=PanelTrace::begin('request dispatch',['operation'=>'index']);
		$active=PanelTrace::summary();
		$t->same(1,count($active['active_spans']));
		$t->contains('request_dispatch.begin',implode(',',dp_panel_trace_names()));
		PanelTrace::end($completed,['status'=>200]);
		PanelTrace::end('missing-span',['cleanup'=>true]);

		$blank=PanelTrace::begin('',[]);
		PanelTrace::end($blank);
		$failed=PanelTrace::begin('request dispatch',[]);
		PanelTrace::fail($failed,new RuntimeException('Dispatch failed'),['status'=>500]);
		PanelTrace::fail('missing-failure',new LogicException('Unknown span'));

		$events=PanelTrace::events();
		$t->isTrue(count($events)>=7);
		$names=dp_panel_trace_names();
		foreach(['event','request_dispatch.begin','request_dispatch.end','span.end_missing','span.begin','span.failed'] as $name){
			$t->isTrue(in_array($name,$names,true),$name);
		}
		$t->same(0,count(PanelTrace::summary()['active_spans']));
		$t->same(count($events),PanelTrace::summary()['count']);
		$t->same(10,count(array_slice(array_pad($events,10,[]),-10)));
		$t->isTrue(count($trace->trace())>=count($events));
		$end=array_values(array_filter($events,static fn(array $event): bool=>($event['event'] ?? '')==='request_dispatch.end'))[0];
		$t->isTrue(is_float($end['context']['duration_ms']));
		$t->isTrue(array_key_exists('memory_delta',$end['context']));
		$failure=array_values(array_filter($events,static fn(array $event): bool=>($event['event'] ?? '')==='request_dispatch.failed'))[0];
		$t->same(RuntimeException::class,$failure['context']['exception']);
		$t->same('Dispatch failed',$failure['context']['message']);
		$t->same('orders',$events[0]['context']['resource']);
		$t->isFalse(array_key_exists(0,$events[0]['context']));
	})->tag('panel','trace','coverage')->group('framework-coverage');

	test('panel trace mirrors bounded sessions deduplicates legacy entries and falls back ids',static function(Context $t): void {
		$trace=PanelTraceProbe::active()->withSession();
		$session=$t->globalMap('_SESSION')->clear();
		PanelTrace::flush();
		$session->put('dataphyre_panel_trace_recent','invalid');
		for($index=0;$index<205;$index++){
			PanelTrace::record('buffer.event',['index'=>$index]);
		}
		$t->same(200,count($session->get('dataphyre_panel_trace_recent')));
		$t->same(200,count(PanelTrace::events()));
		$t->same(200,PanelTrace::summary()['events']['buffer.event']);
		$t->same(10,count(PanelTrace::summary()['latest']));

		$deduped=$t->nonPublic(PanelTrace::class)->invoke('dedupe',[
			'not-an-event',
			['event'=>'legacy','payload'=>"\xB1\x31"],
			['id'=>'shared','event'=>'first'],
			['id'=>'shared','event'=>'duplicate'],
			['id'=>'unique','event'=>'last'],
		]);
		$t->same(3,count($deduped));
		$t->same('legacy',$deduped[0]['event']);
		$t->same('last',$deduped[2]['event']);

		$sessionEvents=[];
		$memoryEvents=[];
		for($index=0;$index<150;$index++){
			$sessionEvents[]=['id'=>'session-'.$index,'event'=>'session'];
			$memoryEvents[]=['id'=>'memory-'.$index,'event'=>'memory'];
		}
		$session->put('dataphyre_panel_trace_recent',$sessionEvents);
		$t->nonPublic(PanelTrace::class)->writeProperty('events',$memoryEvents);
		$t->same(200,count(PanelTrace::events()));

		$trace->failRandomBytes();
		$fallback=$t->nonPublic(PanelTrace::class)->invoke('eventId');
		$trace->failRandomBytes(false);
		$random=$t->nonPublic(PanelTrace::class)->invoke('eventId');
		$t->isTrue(str_starts_with($fallback,'panel_trace_'));
		$t->isFalse(str_contains($fallback,'.'));
		$t->same(16,strlen($random));

		PanelTrace::flush();
		$t->isFalse($session->has('dataphyre_panel_trace_recent'));
		$t->same([],PanelTrace::events());
		$trace->withoutSession();
		$t->isFalse($t->nonPublic(PanelTrace::class)->invoke('sessionAvailable'));
		$trace->withSession();
		$t->isTrue($t->nonPublic(PanelTrace::class)->invoke('sessionAvailable'));
	})->tag('panel','trace','coverage')->group('framework-coverage');

	test('panel trace sanitizes builders requests results forms actions infolists and relations',static function(Context $t): void {
		$t->same('orders',$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',Resource::make('orders')));
		foreach([
			[RelationManager::make('items'),'items'],
			[Action::make('publish'),'publish'],
			[Field::make('email'),'email'],
			[Column::make('status'),'status'],
			[Widget::make('sales'),'sales'],
			[PanelCommand::make('search'),'search'],
		] as [$builder,$expected]){
			$t->same($expected,$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$builder));
		}

		$request=PanelRequest::fromArray(['method'=>'POST','resource'=>'orders','operation'=>'edit','query'=>['page'=>2]]);
		$t->same('orders',$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$request)['resource']);
		$result=PanelPageResult::html('<strong>Saved</strong>',201,['record'=>1],[['message'=>'Done']]);
		$resultTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$result);
		$t->same(201,$resultTrace['status']);
		$t->same(['record'],$resultTrace['data_keys']);
		$t->same(1,$resultTrace['notification_count']);
		$t->same(strlen('<strong>Saved</strong>'),$resultTrace['content_bytes']);

		$form=PanelFormState::make(['email'=>'bad'],['email'=>'Invalid']);
		$formTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$form);
		$t->isFalse($formTrace['valid']);
		$t->same(['email'],$formTrace['value_keys']);
		$t->same(['Invalid'],$formTrace['errors']['email']);

		$action=PanelActionState::make(
			Action::make('publish'),
			'bulk',
			$form,
			['note'=>'ready'],
			null,
			null,
			['stage'=>'running','selected_count'=>3]
		);
		$actionTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$action);
		$t->same('publish',$actionTrace['action']);
		$t->same('bulk',$actionTrace['mode']);
		$t->same('running',$actionTrace['stage']);
		$t->isFalse($actionTrace['valid']);
		$t->isTrue($actionTrace['bulk']);
		$t->same(3,$actionTrace['selected_count']);
		$t->same(['note'],$actionTrace['data_keys']);

		$visible=['name'=>'title','visible'=>true];
		$hidden=['name'=>'secret','visible'=>false];
		$infolist=PanelInfolistState::make(
			[$visible,$hidden],
			['main'=>[$visible],'hidden'=>[$hidden]],
			[],
			['record'=>['key'=>'A1','title'=>'Order A1']]
		);
		$infolistTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$infolist);
		$t->same('A1',$infolistTrace['record_key']);
		$t->same('Order A1',$infolistTrace['record_title']);
		$t->same(2,$infolistTrace['entry_count']);
		$t->same(1,$infolistTrace['visible_entry_count']);
		$t->same(['main'],$infolistTrace['sections']);

		$table=PanelTableState::make([],[],[],[],['page'=>2,'per_page'=>5]);
		$relation=new PanelRelationState(
			['name'=>'items'],
			['key'=>'P1'],
			$table,
			[],
			[['id'=>1],['id'=>2]],
			[['id'=>1]],
			[['id'=>1]],
		);
		$relationTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$relation);
		$t->same('items',$relationTrace['relation']);
		$t->same('P1',$relationTrace['parent_key']);
		$t->same(2,$relationTrace['all_records']);
		$t->same(1,$relationTrace['filtered_records']);
		$t->same(1,$relationTrace['page_records']);
		$t->same(2,$relationTrace['page']);
		$t->same(5,$relationTrace['per_page']);
	})->tag('panel','trace','coverage')->group('framework-coverage');

	test('panel trace sanitizes navigation command surface widget and generic bounded values',static function(Context $t): void {
		$navigation=new PanelNavigationState(
			[['name'=>'orders']],
			[['label'=>'Commerce']],
			['name'=>'orders'],
			['query'=>'alice','result_count'=>2,'results'=>[]],
		);
		$navigationTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$navigation);
		$t->same(1,$navigationTrace['entries']);
		$t->same(1,$navigationTrace['groups']);
		$t->same(['name'=>'orders'],$navigationTrace['active']);
		$t->same(['query'=>'alice','result_count'=>2],$navigationTrace['search']);

		$commands=new PanelCommandState(
			[['name'=>'open']],
			[['label'=>'General']],
			[['name'=>'open']],
			'open',
		);
		$commandTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$commands);
		$t->same(1,$commandTrace['commands']);
		$t->same(1,$commandTrace['groups']);
		$t->same('open',$commandTrace['query']);
		$t->same(1,$commandTrace['matches']);

		$surface=new PanelSurfaceState(
			'Orders',
			'resource',
			202,
			[],
			[],
			[],
			[],
			[],
			['entries'=>1],
			['commands'=>1],
			['form'=>['valid'=>true]],
			['theme'=>'dark'],
		);
		$surfaceTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$surface);
		$t->same('Orders',$surfaceTrace['title']);
		$t->same('resource',$surfaceTrace['kind']);
		$t->same(202,$surfaceTrace['status']);
		$t->same(['entries'=>1],$surfaceTrace['navigation']);
		$t->same(['commands'=>1],$surfaceTrace['commands']);
		$t->same(['form'],$surfaceTrace['state_keys']);
		$t->same(['theme'=>'dark'],$surfaceTrace['chrome']);

		$widget=new PanelWidgetState(
			['name'=>'sales','type'=>'chart','label'=>'Sales','tone'=>'success'],
			['type'=>'bar','point_count'=>3,'datasets'=>[[],[]]],
		);
		$widgetTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$widget);
		$t->same('sales',$widgetTrace['name']);
		$t->same('chart',$widgetTrace['type']);
		$t->same('Sales',$widgetTrace['label']);
		$t->same('success',$widgetTrace['tone']);
		$t->isFalse($widgetTrace['has_error']);
		$t->same(['type'=>'bar','points'=>3,'datasets'=>2],$widgetTrace['chart']);
		$emptyWidget=new PanelWidgetState(['name'=>'broken','type'=>'stat','meta'=>['error'=>true]],[]);
		$emptyWidgetTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$emptyWidget);
		$t->isTrue($emptyWidgetTrace['has_error']);
		$t->same(null,$emptyWidgetTrace['chart']);

		$large=array_combine(range(1,30),range(31,60));
		$largeTrace=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$large);
		$t->same('array',$largeTrace['type']);
		$t->same(30,$largeTrace['count']);
		$t->same(25,count($largeTrace['keys']));
		$small=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',[
			'nested'=>['value'=>1],
			'object'=>new stdClass(),
			'long'=>str_repeat('x',501),
			'null'=>null,
			'flag'=>true,
		]);
		$t->same(1,$small['nested']['value']);
		$t->same(['type'=>'object','class'=>stdClass::class],$small['object']);
		// sanitizeValue performs structural normalization; the enclosing sanitize
		// boundary owns redaction and UTF-8-safe byte caps exactly once.
		$t->same(501,strlen($small['long']));
		$bounded=$t->nonPublic(PanelTrace::class)->invoke('sanitize',[
			'long'=>str_repeat('x',501),
			'unicode'=>str_repeat('€',167),
		]);
		$t->same(503,strlen($bounded['long']));
		$t->same('...',substr($bounded['long'],-3));
		$t->same(501,strlen($bounded['unicode']));
		$t->same(1,preg_match('//u',$bounded['unicode']));
		$nested='leaf';for($depth=0;$depth<10;$depth++){$nested=['child'=>$nested];}
		$depthLimited=$t->nonPublic(PanelTrace::class)->invoke('sanitizeValue',$nested);
		for($depth=0;$depth<9;$depth++){$depthLimited=$depthLimited['child'];}
		$t->same(['type'=>'array','truncated'=>'depth'],$depthLimited);
		$t->same(null,$small['null']);
		$t->isTrue($small['flag']);
		$t->same(['kept'=>1],$t->nonPublic(PanelTrace::class)->invoke('sanitize',[0=>'drop','kept'=>1]));
	})->tag('panel','trace','coverage')->group('framework-coverage');
}
