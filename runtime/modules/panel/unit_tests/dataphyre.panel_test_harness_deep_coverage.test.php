<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PanelAccessibilityAudit;
use Dataphyre\Panel\PanelActionState;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTableState;
use Dataphyre\Panel\PanelTestHarness;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

function dp_panel_test_harness_resource(): Resource {
	return Resource::make('harness-orders')
		->label('Harness Order')
		->pluralLabel('Harness Orders')
		->fields([
			Field::make('name')->required(),
			Field::make('status')->options(['open'=>'Open','closed'=>'Closed']),
		])
		->columns([
			Column::make('id'),
			Column::make('name'),
			Column::make('status')->hidden(),
		])
		->filter(TableFilter::make('status')->options(['open'=>'Open','closed'=>'Closed']))
		->actions([
			Action::make('publish')
				->field(Field::make('reason')->required())
				->handle(static fn(mixed $record,array $data): array=>['published'=>true,'record'=>$record,'data'=>$data]),
			Action::make('refresh')->handle(static fn(): string=>'Refreshed'),
		])
		->queryUsing(static fn(PanelRequest $request): array=>[
			['id'=>'1','name'=>'First','status'=>'open'],
			['id'=>'2','name'=>'Second','status'=>'closed'],
		])
		->recordKeyUsing('id');
}

/** @return mixed */
test('panel test harness builds isolated surfaces registrations requests and rendered states',static function(Context $t): void {
	$default=PanelTestHarness::make();
	$manager=new PanelManager();
	$fromManager=PanelTestHarness::make($manager);
	$instance=new PanelInstance('harness-instance',$manager,['panel_name'=>'harness-instance','panel_label'=>'Harness Instance']);
	$fromInstance=PanelTestHarness::make($instance);
	$t->same('test',$default->panel()->name());
	$t->same($manager,$fromManager->manager());
	$t->same($instance,$fromInstance->panel());

	$resource=dp_panel_test_harness_resource();
	$t->same($default,$default->register($resource));
	$t->same($default,$default->registerPage(PanelPage::make('harness-reports')->label('Harness Reports')->url('/panel/harness-reports')));
	$t->same($default,$default->registerWidget(Widget::make('harness-total')->value(2)));
	$t->same($default,$default->registerNavigationItem(NavigationItem::make('harness-help')->label('Harness Help')->url('/panel/help')));
	$t->same($default,$default->registerCommand(PanelCommand::make('harness-open')->label('Open Harness Orders')->url('/panel/harness-orders')->keywords(['orders'])));
	$t->same($resource,$default->resource('harness-orders'));
	$t->same('harness-reports',$default->page('harness-reports')->name());
	$t->throws(static fn()=>$default->resource('missing'),AssertionError::class);
	$t->throws(static fn()=>$default->page('missing'),AssertionError::class);

	$request=$default->request(['method'=>'post','resource'=>'harness-orders','operation'=>'index','query'=>['status'=>'open']]);
	$t->same('POST',$request->method());
	$t->same('harness-orders',$request->resourceName());
	$t->same(200,$default->dispatch($request)->status());
	$t->same(200,$default->dispatch(['resource'=>'harness-orders','operation'=>'index'])->status());
	$t->same(200,$default->dispatch()->status());

	$rendered=$default->render('harness-orders','index',['query'=>['status'=>'open']]);
	$t->same(200,$rendered->status());
	$t->contains('Harness Orders',$rendered->content());
	$fragment=$default->fragment($resource,'index',['query'=>['page'=>1],'headers'=>['X-Probe'=>'yes']]);
	$t->same(200,$fragment->status());
	$t->same(true,$fragment->content()!=='','fragment has content');
	$modal=$default->modal('harness-orders','show',['query'=>'bad','headers'=>'bad','record'=>['id'=>'1','name'=>'First','status'=>'open']]);
	$t->same(200,$modal->status());

	$table=$default->tableState('harness-orders',[
		['id'=>'1','name'=>'First','status'=>'open'],
		['id'=>'2','name'=>'Second','status'=>'closed'],
	],['status'=>'open'],['visible_columns'=>['id','name']]);
	$t->same(2,count($table->records()));
	$t->same(3,count($default->tableState($resource,[['id'=>'1']],[],[])->allColumns()));

	$form=$default->formState('harness-orders',['id'=>'1','name'=>'Original'],['name'=>'Changed','status'=>'open'],'edit',false);
	$t->same('Changed',$form->value('name'));
	$t->same('GET',$default->formState($resource,null,[],'create',false)->meta()['request_method'] ?? 'GET');
	$valid=$default->validateForm('harness-orders',['name'=>'Valid','status'=>'open'],null,'store');
	$invalid=$default->validateForm($resource,['name'=>'','status'=>'open']);
	$t->same(true,$valid->valid(),'validated form is valid');
	$t->same(true,$invalid->invalid(),'validated form is invalid');

	$action=$default->actionState('harness-orders','publish',['id'=>'1'],['reason'=>'Because'],'row action');
	$t->same('publish',$action->actionName());
	$t->same('row_action',$action->mode());
	$t->same('refresh',$default->actionState($resource,'refresh')->actionName());
	$t->throws(static fn()=>$default->actionState('harness-orders','missing'),AssertionError::class);
	$t->throws(static fn()=>$default->runAction('harness-orders','missing'),AssertionError::class);
	$validationFailed=$default->runAction('harness-orders','publish',['id'=>'1'],['reason'=>'']);
	$t->same('validation_failed',$validationFailed->stage());
	$executed=$default->runAction($resource,'publish',['id'=>'1'],['reason'=>'Because'],true);
	$t->same('executed',$executed->stage());
	$t->same(true,$executed->result()['published'],'action executed');
	$t->same('executed',$default->runAction($resource,'refresh',null,[],false)->stage());

	$navigation=$default->navigationState(['resource'=>'harness-orders'],['query'=>'harness']);
	$t->same(true,count($navigation->entries())>=1,'navigation entries exist');
	$commands=$default->commandState('orders');
	$t->same('orders',$commands->query());
	$t->same('harness-open',$commands->matched()[0]['name'] ?? '');
	$t->same('',$default->commandState()->query());
	$manifest=$default->manifest();
	$t->same('panel_manifest',$manifest['type'] ?? '');
	$t->same(true,isset($manifest['resources']['harness-orders']),'manifest resource exists');

	$accessible='<main><h1>Harness</h1><button aria-label="Run"></button><div aria-live="polite">Ready</div><style>@media (prefers-reduced-motion: reduce){*{transition:none}}</style></main>';
	$audit=$default->accessibilityAudit($accessible,['surface'=>'harness']);
	$t->same(true,$audit->passed(),'accessibility audit passes');
	$t->same('harness',$audit->toArray()['meta']['surface']);
});

test('panel test harness assertion helpers cover passing contracts and every diagnostic failure',static function(Context $t): void {
	$ok=PanelPageResult::html(
		'<main><h1>Orders</h1><button aria-label="Refresh"></button><div aria-live="polite">Ready</div><style>@media (prefers-reduced-motion: reduce){*{transition:none}}</style></main>',
		200,
		['stats'=>['orders'=>3],'nullable'=>null],
		[['message'=>'Orders refreshed','type'=>'success']]
	);
	$redirect=PanelPageResult::redirect('/panel/orders');
	PanelTestHarness::assertOk($ok);
	PanelTestHarness::assertStatus($ok,200);
	PanelTestHarness::assertRedirect($redirect);
	PanelTestHarness::assertRedirect($redirect,'/panel/orders');
	PanelTestHarness::assertSee($ok,'Orders');
	PanelTestHarness::assertSee('plain needle','needle');
	PanelTestHarness::assertDontSee($ok,'Customers');
	PanelTestHarness::assertDontSee('plain','needle');
	PanelTestHarness::assertData($ok,'stats.orders',3);
	PanelTestHarness::assertData($ok,'nullable',null);
	PanelTestHarness::assertNotification($ok);
	PanelTestHarness::assertNotification($ok,'refreshed','success');
	PanelTestHarness::assertAccessible($ok);
	$passedAudit=PanelAccessibilityAudit::from($ok);
	PanelTestHarness::assertAccessible($passedAudit);

	$table=PanelTableState::make(
		[['id'=>1],['id'=>2]],
		['id'=>['label'=>'ID'],'hidden'=>['label'=>'Hidden']],
		['id'=>['label'=>'ID']],
		[],
		['total_records'=>5,'filters'=>['status'=>'open']]
	);
	PanelTestHarness::assertTableCount($table,2);
	PanelTestHarness::assertTableTotal($table,5);
	PanelTestHarness::assertTableColumn($table,'id');
	PanelTestHarness::assertTableColumn($table,'hidden',false);
	PanelTestHarness::assertTableFilter($table,'status');
	PanelTestHarness::assertTableFilter($table,'status','open');

	$valid=PanelFormState::make(['name'=>'Ada']);
	$invalid=PanelFormState::make(['name'=>''],['name'=>['Required']]);
	PanelTestHarness::assertFormValid($valid);
	PanelTestHarness::assertFormInvalid($invalid);
	PanelTestHarness::assertFormInvalid($invalid,'name');
	PanelTestHarness::assertFormValue($valid,'name','Ada');

	$visible=new PanelActionState(['name'=>'visible'],'action',null,[],null,null,[
		'visible'=>true,'disabled'=>false,'authorized'=>true,
	]);
	$hidden=new PanelActionState(['name'=>'hidden'],'action',null,[],null,null,[
		'visible'=>false,'disabled'=>true,'disabled_reason'=>'Policy blocked','authorized'=>false,
	]);
	PanelTestHarness::assertActionVisible($visible);
	PanelTestHarness::assertActionHidden($hidden);
	PanelTestHarness::assertActionEnabled($visible);
	PanelTestHarness::assertActionDisabled($hidden);
	PanelTestHarness::assertActionDisabled($hidden,'Policy');
	PanelTestHarness::assertAuthorized($visible);
	PanelTestHarness::assertUnauthorized($hidden);

	$failures=[
		static fn()=>PanelTestHarness::assertStatus($ok,201),
		static fn()=>PanelTestHarness::assertRedirect($ok),
		static fn()=>PanelTestHarness::assertRedirect($redirect,'/panel/other'),
		static fn()=>PanelTestHarness::assertSee($ok,'Customers'),
		static fn()=>PanelTestHarness::assertDontSee($ok,'Orders'),
		static fn()=>PanelTestHarness::assertData($ok,'stats.orders',4),
		static fn()=>PanelTestHarness::assertData($ok,'missing.path','present'),
		static fn()=>PanelTestHarness::assertNotification($ok,'missing','warning'),
		static fn()=>PanelTestHarness::assertAccessible('<button></button>'),
		static fn()=>PanelTestHarness::assertTableCount($table,3),
		static fn()=>PanelTestHarness::assertTableTotal($table,4),
		static fn()=>PanelTestHarness::assertTableColumn($table,'missing'),
		static fn()=>PanelTestHarness::assertTableFilter($table,'missing'),
		static fn()=>PanelTestHarness::assertTableFilter($table,'status','closed'),
		static fn()=>PanelTestHarness::assertFormValid($invalid),
		static fn()=>PanelTestHarness::assertFormInvalid($valid),
		static fn()=>PanelTestHarness::assertFormInvalid($invalid,'other'),
		static fn()=>PanelTestHarness::assertFormValue($valid,'name','Grace'),
		static fn()=>PanelTestHarness::assertActionVisible($hidden),
		static fn()=>PanelTestHarness::assertActionHidden($visible),
		static fn()=>PanelTestHarness::assertActionEnabled($hidden),
		static fn()=>PanelTestHarness::assertActionDisabled($visible),
		static fn()=>PanelTestHarness::assertActionDisabled($hidden,'Different'),
		static fn()=>PanelTestHarness::assertAuthorized($hidden),
		static fn()=>PanelTestHarness::assertUnauthorized($visible),
	];
	foreach($failures as $failure){
		$t->throws($failure,AssertionError::class);
	}

	$t->same(3,$t->nonPublic(PanelTestHarness::class)->invoke('getPath',['stats'=>['orders'=>3]],'stats.orders'));
	$t->same(null,$t->nonPublic(PanelTestHarness::class)->invoke('getPath',['stats'=>3],'stats.orders'));
	$t->same('{"a":1}',$t->nonPublic(PanelTestHarness::class)->invoke('export',['a'=>1]));
	$stream=fopen('php://memory','r');
	try{
		$t->same('NULL',$t->nonPublic(PanelTestHarness::class)->invoke('export',$stream));
	}
	finally{
		fclose($stream);
	}
});
