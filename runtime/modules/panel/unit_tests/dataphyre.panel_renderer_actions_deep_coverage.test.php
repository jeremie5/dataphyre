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
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed> $query
 * @param array<string,mixed> $headers
 * @param array<string,mixed> $files
 */
function dp_panel_renderer_actions_request(
	string $method='POST',
	array $input=[],
	array $query=[],
	array $headers=[],
	array $files=[],
	string $operation='action',
	?string $record='10',
	?string $action=null,
	?string $relation=null,
): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'action_records',
		'operation'=>$operation,
		'record'=>$record,
		'action'=>$action,
		'relation'=>$relation,
		'input'=>$input,
		'query'=>$query,
		'headers'=>$headers,
		'files'=>$files,
		'user'=>['id'=>41,'name'=>'Actions Coverage'],
	]);
}

/** @param list<Action> $actions */
function dp_panel_renderer_actions_resource(array $actions=[],?callable $authorizer=null): Resource {
	$resource=Resource::make('action_records')
		->label('Action records')
		->pluralLabel('Action records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->queryUsing(static fn(): array=>[
			['id'=>'10','name'=>'Alpha','status'=>'draft'],
			['id'=>'20','name'=>'Beta','status'=>'active'],
		])
		->actions($actions);
	if($authorizer!==null){
		$resource=$resource->authorize($authorizer);
	}
	return $resource;
}

/** @return array{id:string,name:string,status:string} */
function dp_panel_renderer_actions_record(): array {
	return ['id'=>'10','name'=>'Alpha','status'=>'draft'];
}

/** @param null|callable(string,mixed,mixed,Resource):bool $authorizer */
function dp_panel_renderer_actions_mutation_resource(?callable $authorizer=null): Resource {
	$success=static fn(): array=>[
		'message'=>'Operation complete',
		'notification'=>PanelNotification::success('Operation complete'),
		'effects'=>['refresh'=>['table'],'close_modal'=>true],
	];
	$resource=dp_panel_renderer_actions_resource([], $authorizer)
		->saveUsing(static fn(array $data): array=>['message'=>'Saved '.implode(',',array_keys($data))])
		->deleteUsing($success)
		->forceDeleteUsing($success)
		->duplicateUsing($success)
		->restoreUsing($success)
		->addNoteUsing(static fn(mixed $record,string $note): array=>['message'=>'Noted '.$note])
		->sendMessageUsing(static fn(mixed $record,array $message): array=>['message'=>'Sent '.$message['body']])
		->updateTagUsing(static fn(mixed $record,string $tag,string $action): array=>['message'=>$action.' '.$tag])
		->attachUsing(static fn(mixed $record,array $file): array=>['message'=>'Attached '.$file['name']])
		->updateTaskUsing(static fn(mixed $record,string $task,bool $completed): array=>['message'=>$task.':'.($completed ? 'done' : 'open')])
		->createTaskUsing(static fn(mixed $record,array $task): array=>['message'=>'Created '.$task['title']])
		->resolveApprovalUsing(static fn(mixed $record,string $approval,string $decision): array=>['message'=>$decision.' '.$approval]);
	return $resource;
}

/** @return array{0:Resource,1:RelationManager} */
function dp_panel_renderer_actions_relation_fixture(bool $configured=true,?callable $authorizer=null): array {
	$records=[
		['id'=>'a','parent_id'=>'10','name'=>'First','position'=>1],
		['id'=>'b','parent_id'=>'10','name'=>'Second','position'=>2],
	];
	$child=Resource::make('action_children')
		->label('Children')
		->recordKeyUsing('id')
		->columns([Column::make('name')->label('Name')])
		->queryUsing(static fn(): array=>$records);
	Panel::register($child);
	$relation=RelationManager::make('children')
		->label('Children')
		->relatedResource('action_children')
		->foreignKey('parent_id')
		->localKey('id')
		->queryUsing(static fn(): array=>$records)
		->columns([Column::make('name')->label('Name')]);
	if($configured){
		$handler=static fn(): array=>['message'=>'Relation changed','effects'=>['refresh'=>'table']];
		$relation=$relation
			->attachUsing($handler)
			->detachUsing($handler)
			->associateUsing($handler)
			->dissociateUsing($handler)
			->reorderUsing($handler,'position')
			->pivotFields([Field::make('pivot_note')->label('Pivot note')])
			->updatePivotUsing($handler);
	}
	if($authorizer!==null){
		$relation=$relation->authorize($authorizer);
	}
	$resource=dp_panel_renderer_actions_resource()->relations([$relation]);
	return [$resource,$relation];
}

test('panel renderer actions renders relations and dispatches every relation mutation',static function(Context $t): void {
	$missing=PanelRenderer::relation(dp_panel_renderer_actions_resource(),dp_panel_renderer_actions_request('GET',[],[],[],[],'relation','10',null,'missing'),dp_panel_renderer_actions_record());
	$t->same(404,$missing->status());

	[$deniedResource]=dp_panel_renderer_actions_relation_fixture(true,static fn(): bool=>false);
	$denied=PanelRenderer::relation($deniedResource,dp_panel_renderer_actions_request('GET',[],[],[],[],'relation','10',null,'children'),dp_panel_renderer_actions_record());
	$t->same(403,$denied->status());

	[$resource,$relation]=dp_panel_renderer_actions_relation_fixture();
	$request=dp_panel_renderer_actions_request('GET',[],[],[],[],'relation','10',null,'children');
	$page=PanelRenderer::relation($resource,$request,dp_panel_renderer_actions_record());
	$t->same(200,$page->status());
	$t->same('relation',$page->data()['kind']);

	$post=dp_panel_renderer_actions_request('POST',['relation_action'=>'attach','related_key'=>'a'],[],[],[],'relation','10',null,'children');
	$t->same('relation_action',PanelRenderer::relation($resource,$post,dp_panel_renderer_actions_record())->data()['kind']);

	$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,$post,null,'attach')->status());
	$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,$post,dp_panel_renderer_actions_record(),'unknown')->status());
	[, $relationDenied]=dp_panel_renderer_actions_relation_fixture(true,static fn(): bool=>false);
	$t->same(403,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relationDenied,$post,dp_panel_renderer_actions_record(),'attach')->status());

	[, $unavailable]=dp_panel_renderer_actions_relation_fixture(false);
	foreach(['attach','associate'] as $action){
		$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$unavailable,$post,dp_panel_renderer_actions_record(),$action)->status());
		$missingKey=dp_panel_renderer_actions_request('POST',[],[],[],[],'relation');
		$t->same(422,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,$missingKey,dp_panel_renderer_actions_record(),$action)->status());
		$success=dp_panel_renderer_actions_request('POST',['related_key'=>'b','return_to'=>'/panel/action_records?from=relation'],[],[],[],'relation');
		$t->same(303,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,$success,dp_panel_renderer_actions_record(),$action)->status());
	}
	foreach(['detach','dissociate'] as $action){
		$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$unavailable,$post,dp_panel_renderer_actions_record(),$action)->status());
		$t->same(422,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST'),dp_panel_renderer_actions_record(),$action)->status());
		$t->same(303,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST',['child_key'=>'a']),dp_panel_renderer_actions_record(),$action)->status());
	}
	$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$unavailable,$post,dp_panel_renderer_actions_record(),'reorder')->status());
	$t->same(422,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST',['ordered_keys'=>' ']),dp_panel_renderer_actions_record(),'reorder')->status());
	$reordered=$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST',['ordered_keys'=>['a',' ','b']]),dp_panel_renderer_actions_record(),'reorder');
	$t->same('reorder',$reordered->data()['action']);

	$t->same(404,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$unavailable,$post,dp_panel_renderer_actions_record(),'update_pivot')->status());
	$t->same(422,$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST'),dp_panel_renderer_actions_record(),'update_pivot')->status());
	$pivot=$t->nonPublic(PanelRenderer::class)->invoke('relationActionResult',$resource,$relation,dp_panel_renderer_actions_request('POST',['child_key'=>'a','pivot_note'=>'Important']),dp_panel_renderer_actions_record(),'update_pivot');
	$t->same('update_pivot',$pivot->data()['action']);
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers action discovery visibility authorization disabled bulk and content',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$t->same(404,PanelRenderer::actionResult(dp_panel_renderer_actions_resource(),dp_panel_renderer_actions_request('GET'),'missing',$record)->status());

	$hidden=Action::make('hidden')->visible(false)->handle(static fn(): string=>'never');
	$t->same('action_hidden',PanelRenderer::actionResult(dp_panel_renderer_actions_resource([$hidden]),dp_panel_renderer_actions_request('GET'),'hidden',$record)->data()['kind']);

	$denied=Action::make('denied')->authorize(static fn(): bool=>false)->handle(static fn(): string=>'never');
	$t->same(403,PanelRenderer::actionResult(dp_panel_renderer_actions_resource([$denied]),dp_panel_renderer_actions_request('GET'),'denied',$record)->status());

	$disabled=Action::make('disabled')->disabled(true,'Temporarily blocked')->handle(static fn(): string=>'never');
	$disabledResult=PanelRenderer::actionResult(dp_panel_renderer_actions_resource([$disabled]),dp_panel_renderer_actions_request('GET'),'disabled',$record);
	$t->same(409,$disabledResult->status());
	$t->contains('Temporarily blocked',$disabledResult->content());

	$bulk=Action::make('bulk')->bulk()->handle(static fn(array $record): array=>['message'=>'Bulk '.count($record)]);
	$bulkResource=dp_panel_renderer_actions_resource([$bulk]);
	$t->same(422,PanelRenderer::actionResult($bulkResource,dp_panel_renderer_actions_request('POST',['selected'=>[]]),'bulk')->status());
	$bulkSuccess=PanelRenderer::actionResult($bulkResource,dp_panel_renderer_actions_request('POST',['selected'=>['10','20']]),'bulk');
	$t->same(303,$bulkSuccess->status());
	$t->same(2,$bulkSuccess->data()['action_state']['selected_count']);

	$emptyBulk=Action::make('empty_bulk')->bulk()->allowEmptySelection()->handle(static fn(array $record): array=>['message'=>'Allowed '.count($record)]);
	$t->same(303,PanelRenderer::actionResult(dp_panel_renderer_actions_resource([$emptyBulk]),dp_panel_renderer_actions_request('POST',['selected'=>[]]),'empty_bulk')->status());

	$content=Action::make('details')->modalContent('');
	$contentResult=PanelRenderer::actionResult(dp_panel_renderer_actions_resource([$content]),dp_panel_renderer_actions_request('GET'),'details',$record);
	$t->same('action_content',$contentResult->data()['kind']);
	$t->contains('dp-panel-empty',$contentResult->content());
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions renders validates confirms and submits action forms',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$action=Action::make('publish')
		->label('Publish now')
		->modal('Publish record','Check the details','lg')
		->requiresConfirmation()
		->confirmation('Really publish this record?')
		->fields([
			Field::make('title')->label('Title')->required()->section('Primary'),
			Field::make('ignored')->visibleUsing(static fn(): bool=>false),
			Field::make('dependent')->visibleUsing(static fn(): bool=>false)->visibleWhen('title','show'),
		])
		->handle(static fn(array $record,array $data): array=>['message'=>'Published '.$data['title'],'effects'=>['refresh'=>['table']]]);
	$resource=dp_panel_renderer_actions_resource([$action])->field(Field::make('fallback'));
	$resourceForm=$resource->form();
	$resource=$resource->form($resourceForm->accessibilityPolicy(['min_touch_target'=>44]));

	$form=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET',['selected'=>['10',' ','20']]),'publish',$record);
	$t->same('action_form',$form->data()['kind']);
	$t->contains('selected[]',$form->content());

	$modal=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET',['selected'=>'10'],[],['x-requested-with'=>'DataphyrePanelModal']),'publish',$record);
	$t->same('text/html; charset=utf-8',$modal->headers()['Content-Type']);

	$invalid=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('POST',['__panel_action_submit'=>'1','title'=>'']),'publish',$record);
	$t->same(422,$invalid->status());
	$t->contains('dp-panel-alert',$invalid->content());

	$confirmation=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('POST',['__panel_action_submit'=>'1','title'=>'Ready']),'publish',$record);
	$t->same(409,$confirmation->status());
	$t->contains('__panel_action_confirm',$confirmation->content());

	$success=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('POST',[
		'__panel_action_submit'=>'1','__panel_action_confirm'=>'1','title'=>'Ready',
	]),'publish',$record);
	$t->same(303,$success->status());
	$t->same('Published Ready',$success->data()['result']['message']);

	$confirmOnly=Action::make('archive')->requiresConfirmation()->confirmation('Archive it?')->handle(static fn(): string=>'Archived');
	$confirmResource=dp_panel_renderer_actions_resource([$confirmOnly]);
	$full=PanelRenderer::actionResult($confirmResource,dp_panel_renderer_actions_request('GET',['selected'=>['10',' ']]),'archive',$record);
	$t->same('action_confirmation',$full->data()['kind']);
	$modalConfirm=PanelRenderer::actionResult($confirmResource,dp_panel_renderer_actions_request('GET',['selected'=>'10'],[],['x-requested-with'=>'DataphyrePanelModal']),'archive',$record);
	$t->same('text/html; charset=utf-8',$modalConfirm->headers()['Content-Type']);
	$t->same(303,PanelRenderer::actionResult($confirmResource,dp_panel_renderer_actions_request('POST',['__panel_action_confirm'=>'1']),'archive',$record)->status());
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers every custom action lifecycle outcome',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$cases=[
		'before_validate'=>Action::make('before_validate')->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before validation stopped'))->handle(static fn(): string=>'never'),
		'after_validate'=>Action::make('after_validate')->field(Field::make('value')->required())->afterValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After validation stopped'))->handle(static fn(): string=>'never'),
		'before_action_lifecycle'=>Action::make('before_action_lifecycle')->before(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before action stopped'))->handle(static fn(): string=>'never'),
		'before_action_result'=>Action::make('before_action_result')->before(static fn(): string=>'Handled before action'),
		'after_action_lifecycle'=>Action::make('after_action_lifecycle')->handle(static fn(): string=>'base')->after(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After action stopped')),
		'throws'=>Action::make('throws')->handle(static fn(): never=>throw new RuntimeException('Exploded action')),
		'get_page'=>Action::make('get_page')->mutateFormDataUsing(static fn(array $data): array=>$data+['mutated'=>true])->handle(static fn(): string=>'Completed without redirect'),
		'meta_redirect'=>Action::make('meta_redirect')->redirectTo('/panel/action_records?from=meta')->handle(static fn(): array=>['message'=>'Meta redirect']),
		'result_redirect'=>Action::make('result_redirect')->effects(['refresh'=>['navigation']])->handle(static fn(): array=>[
			'message'=>'Result redirect','redirect'=>'/panel/action_records?from=result','status'=>307,
			'effects'=>['close_modal'=>true,'events'=>[['name'=>'coverage','detail'=>['ok'=>true]]]],
		]),
	];
	$resource=dp_panel_renderer_actions_resource(array_values($cases));
	$t->same('lifecycle_result',PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'before_validate',$record)->data()['kind']);
	$t->same('lifecycle_result',PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('POST',['__panel_action_submit'=>'1','value'=>'ok']),'after_validate',$record)->data()['kind']);
	$t->same('lifecycle_result',PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'before_action_lifecycle',$record)->data()['kind']);
	$t->same(200,PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'before_action_result',$record)->status());
	$t->same('lifecycle_result',PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'after_action_lifecycle',$record)->data()['kind']);
	$failed=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'throws',$record);
	$t->same(500,$failed->status());
	$t->same('action_failed',$failed->data()['kind']);
	$t->same(200,PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'get_page',$record)->status());
	$t->same('/panel/action_records?from=meta',PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'meta_redirect',$record)->headers()['Location']);
	$resultRedirect=PanelRenderer::actionResult($resource,dp_panel_renderer_actions_request('GET'),'result_redirect',$record);
	$t->same(307,$resultRedirect->status());
	$t->same(true,$resultRedirect->data()['effects']['close_modal']);
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions directly covers confirmation exception inline and lifecycle helpers',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$resource=dp_panel_renderer_actions_resource();
	$request=dp_panel_renderer_actions_request('POST');
	$meta=['requires_confirmation'=>true,'label'=>'Destroy','tone'=>'danger','meta'=>['confirmation'=>'Custom confirmation']];
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('actionRequiresConfirmation',$meta));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('actionRequiresConfirmation',[]));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('actionConfirmed',dp_panel_renderer_actions_request('POST',['__panel_action_confirm'=>'1'])));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('actionConfirmed',dp_panel_renderer_actions_request('POST',[],['__panel_action_confirm'=>'1'])));
	$t->contains('__panel_action_confirm',$t->nonPublic(PanelRenderer::class)->invoke('actionConfirmationInput',$meta));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('actionConfirmationInput',[]));
	$t->same('Custom confirmation',$t->nonPublic(PanelRenderer::class)->invoke('actionConfirmationMessage',$meta));
	$t->contains('Destroy',$t->nonPublic(PanelRenderer::class)->invoke('actionConfirmationMessage',['label'=>'Destroy']));
	$t->contains('dp-panel-action-danger',$t->nonPublic(PanelRenderer::class)->invoke('actionConfirmationContent',$meta,'/run','/back','<input name="extra">'));

	$action=Action::make('failing')->handle(static fn(): string=>'unused');
	$page=PanelPage::make('coverage_page')->label('Coverage page');
	$pageFailure=$t->nonPublic(PanelRenderer::class)->invoke('actionExceptionPage',null,$page,$request,$action,new RuntimeException('Page failed'),'page_action');
	$t->same('page_action_failed',$pageFailure->data()['kind']);
	$t->same('coverage_page',$pageFailure->data()['page']['name']);
	$genericFailure=$t->nonPublic(PanelRenderer::class)->invoke('actionExceptionPage',null,null,$request,$action,new RuntimeException(''));
	$t->same(500,$genericFailure->status());

	$t->same(1,$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('active')->editable('checkbox'),'YES'));
	$t->same(0,$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('active')->editable('boolean'),'off'));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('amount')->editable('number'),' '));
	$t->same(12.5,$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('amount')->editable('number'),'12.5'));
	$t->same(12,$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('amount')->editable('integer'),'12'));
	$t->same('trimmed',$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('name')->editable('text'),' trimmed '));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('inlineUpdateValue',Column::make('name')->editable('text'),['not'=>'scalar']));

	$validRedirect=$t->nonPublic(PanelRenderer::class)->invoke('lifecycleResult',$resource,$request,$record,'update',PanelLifecycleResult::redirect('/panel/action_records','Redirecting'));
	$t->same('/panel/action_records',$validRedirect->headers()['Location']);
	$blocked=$t->nonPublic(PanelRenderer::class)->invoke('lifecycleResult',$resource,$request,$record,'update',PanelLifecycleResult::redirect('https://evil.example/phish','Blocked redirect'));
	$t->same(422,$blocked->status());
	$t->same(null,$blocked->data()['lifecycle']['redirect_to']);
	$quiet=$t->nonPublic(PanelRenderer::class)->invoke('lifecycleResult',$resource,$request,null,'store',PanelLifecycleResult::notify(PanelNotification::warning('Notice'),false,''),null,[],['return_url'=>'/panel/custom']);
	$t->same(200,$quiet->status());
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers save validation lifecycle failures redirects and completion',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$required=Field::make('name')->required();
	$denied=dp_panel_renderer_actions_resource([],static fn(): bool=>false)->field($required)->saveUsing(static fn(): array=>['ok'=>true]);
	$t->same(403,PanelRenderer::saveResult($denied,dp_panel_renderer_actions_request('POST',['name'=>'x']),$record,'update')->status());
	$t->same(403,PanelRenderer::saveResult($denied,dp_panel_renderer_actions_request('POST',['name'=>'x']),null,'store')->status());

	$beforeValidate=dp_panel_renderer_actions_resource()->field($required)->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before validate'));
	$t->same('lifecycle_result',PanelRenderer::saveResult($beforeValidate,dp_panel_renderer_actions_request('POST',['name'=>'x']))->data()['kind']);
	$afterValidate=dp_panel_renderer_actions_resource()->field($required)->afterValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After validate'));
	$t->same('lifecycle_result',PanelRenderer::saveResult($afterValidate,dp_panel_renderer_actions_request('POST',['name'=>'x']))->data()['kind']);

	$base=dp_panel_renderer_actions_resource()->field($required)->saveUsing(static fn(array $data): array=>['message'=>'Saved '.$data['name']]);
	$invalid=PanelRenderer::saveResult($base,dp_panel_renderer_actions_request('POST',['name'=>'']));
	$t->same(422,$invalid->status());
	$t->same(false,$invalid->data()['form_state']['valid']);

	$beforeSave=$base->beforeSaveUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before save'));
	$t->same('lifecycle_result',PanelRenderer::saveResult($beforeSave,dp_panel_renderer_actions_request('POST',['name'=>'x']))->data()['kind']);
	$afterSave=$base->afterSaveUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After save'));
	$t->same('lifecycle_result',PanelRenderer::saveResult($afterSave,dp_panel_renderer_actions_request('POST',['name'=>'x']))->data()['kind']);

	$failedWithErrors=$base->saveUsing(static fn(): array=>['ok'=>false,'errors'=>['name'=>'Already used']]);
	$t->same(422,PanelRenderer::saveResult($failedWithErrors,dp_panel_renderer_actions_request('POST',['name'=>'x']))->status());
	$failedMessage=$base->saveUsing(static fn(): array=>['ok'=>false,'message'=>'Persistence failed']);
	$t->same(422,PanelRenderer::saveResult($failedMessage,dp_panel_renderer_actions_request('POST',['name'=>'x']))->status());
	$failedFallback=$base->saveUsing(static fn(): array=>['ok'=>false]);
	$t->same(422,PanelRenderer::saveResult($failedFallback,dp_panel_renderer_actions_request('POST',['name'=>'x']))->status());

	$redirecting=$base->saveUsing(static fn(): array=>['message'=>'Saved','redirect'=>'/panel/action_records?stored=1']);
	$t->same('/panel/action_records?stored=1',PanelRenderer::saveResult($redirecting,dp_panel_renderer_actions_request('POST',['name'=>'x']))->headers()['Location']);
	$t->same('/panel/action_records?return=1',PanelRenderer::saveResult($base,dp_panel_renderer_actions_request('POST',['name'=>'x','return_to'=>'/panel/action_records?return=1']))->headers()['Location']);
	$fragment=PanelRenderer::saveResult($base,dp_panel_renderer_actions_request('POST',['name'=>'x'],[],['x-requested-with'=>'DataphyrePanelFragment']));
	$t->same(303,$fragment->status());
	$completed=PanelRenderer::saveResult($base,dp_panel_renderer_actions_request('POST',['name'=>'x']));
	$t->same(200,$completed->status());
	$t->contains('Saved x',$completed->content());
	$t->same(200,PanelRenderer::saveResult($base,dp_panel_renderer_actions_request('POST',['name'=>'x']),$record,'update')->status());
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions renders bulk update forms and handles inline edits',static function(Context $t): void {
	$records=[dp_panel_renderer_actions_record(),['id'=>'20','name'=>'Beta','status'=>'active']];
	$resource=dp_panel_renderer_actions_resource()
		->bulkFields([
			Field::make('status')->required()->section('Changes'),
			Field::make('token')->default('secret')->hidden()->visibleWhen('status','active'),
			Field::make('ignored')->visibleUsing(static fn(): bool=>false),
			Field::make('dependent')->visibleUsing(static fn(): bool=>false)->visibleWhen('status','active'),
		]);
	$form=$t->nonPublic(PanelRenderer::class)->invoke('bulkUpdateForm',$resource,dp_panel_renderer_actions_request('POST',['selected'=>['10',' ','20']]),$records);
	$t->same('bulk_update_form',$form->data()['kind']);
	$t->same(2,$form->data()['selected_count']);
	$state=$resource->bulkForm()->submit(dp_panel_renderer_actions_request('POST',['selected'=>'10','status'=>'']),$records,'bulk_update');
	$invalid=$t->nonPublic(PanelRenderer::class)->invoke('bulkUpdateForm',$resource,dp_panel_renderer_actions_request('POST',['selected'=>'10']),$records,$state,422);
	$t->same(422,$invalid->status());
	$t->contains('dp-panel-alert',$invalid->content());

	$record=dp_panel_renderer_actions_record();
	$t->same(405,PanelRenderer::inlineUpdateResult($resource,dp_panel_renderer_actions_request('GET'),$record)->status());
	$t->same(403,PanelRenderer::inlineUpdateResult($resource,dp_panel_renderer_actions_request('POST'),null)->status());
	$denied=$resource->authorize(static fn(): bool=>false);
	$t->same(403,PanelRenderer::inlineUpdateResult($denied,dp_panel_renderer_actions_request('POST'),$record)->status());
	$t->same(422,PanelRenderer::inlineUpdateResult($resource,dp_panel_renderer_actions_request('POST',['field'=>'missing','value'=>'x']),$record)->status());

	$notEditable=$resource->column(Column::make('plain'));
	$t->same(422,PanelRenderer::inlineUpdateResult($notEditable,dp_panel_renderer_actions_request('POST',['field'=>'plain','value'=>'x']),$record)->status());
	$editable=$resource
		->columns([
			Column::make('status')->editable('text'),
			Column::make('active')->editable('checkbox'),
		])
		->saveUsing(static fn(array $data): array=>['message'=>'Inline saved','effects'=>['refresh'=>'table']]);
	$json=PanelRenderer::inlineUpdateResult($editable,dp_panel_renderer_actions_request('POST',['field'=>'active','value'=>'yes'],[],['x-requested-with'=>'DataphyrePanelInline'],[],'inline_update'),$record);
	$t->same('application/json; charset=utf-8',$json->headers()['Content-Type']);
	$payload=json_decode($json->content(),true);
	$t->same(1,$payload['value']);
	$redirect=PanelRenderer::inlineUpdateResult($editable,dp_panel_renderer_actions_request('POST',['field'=>'status','value'=>' active ','return_to'=>'/panel/action_records?inline=1'],[],[],[],'inline_update'),$record);
	$t->same('/panel/action_records?inline=1',$redirect->headers()['Location']);
	$lifecycle=$editable->saveUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Inline stopped'));
	$t->same('lifecycle_result',PanelRenderer::inlineUpdateResult($lifecycle,dp_panel_renderer_actions_request('POST',['field'=>'status','value'=>'x'],[],[],[],'inline_update'),$record)->data()['kind']);
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers delete force delete duplicate and restore contracts',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$methods=[
		'deleteResult'=>['deleteUsing','delete'],
		'forceDeleteResult'=>['forceDeleteUsing','force_delete'],
		'duplicateResult'=>['duplicateUsing','duplicate'],
		'restoreResult'=>['restoreUsing','restore'],
	];
	foreach($methods as $renderer=>[$using,$ability]){
		$unavailable=dp_panel_renderer_actions_resource();
		$t->same(404,PanelRenderer::{$renderer}($unavailable,dp_panel_renderer_actions_request('POST'),$record)->status());
		$available=$unavailable->{$using}(static fn(): array=>['message'=>'Done']);
		$t->same(405,PanelRenderer::{$renderer}($available,dp_panel_renderer_actions_request('GET'),$record)->status());
		$t->same(404,PanelRenderer::{$renderer}($available,dp_panel_renderer_actions_request('POST'),null)->status());
		$denied=$available->authorize(static fn(): bool=>false);
		$t->same(403,PanelRenderer::{$renderer}($denied,dp_panel_renderer_actions_request('POST'),$record)->status());
		$success=PanelRenderer::{$renderer}($available,dp_panel_renderer_actions_request('POST'),$record);
		$t->same(303,$success->status());
		$t->same($ability,$success->data()['kind']);
	}
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers note message tag and attachment contracts',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$base=dp_panel_renderer_actions_resource();
	$available=dp_panel_renderer_actions_mutation_resource();
	$denied=dp_panel_renderer_actions_mutation_resource(static fn(): bool=>false);

	$t->same(404,PanelRenderer::noteResult($base,dp_panel_renderer_actions_request('POST',['note'=>'x']),$record)->status());
	$t->same(405,PanelRenderer::noteResult($available,dp_panel_renderer_actions_request('GET',['note'=>'x']),$record)->status());
	$t->same(404,PanelRenderer::noteResult($available,dp_panel_renderer_actions_request('POST',['note'=>'x']),null)->status());
	$t->same(403,PanelRenderer::noteResult($denied,dp_panel_renderer_actions_request('POST',['note'=>'x']),$record)->status());
	$t->same(422,PanelRenderer::noteResult($available,dp_panel_renderer_actions_request('POST',['note'=>' ']),$record)->status());
	$t->same(303,PanelRenderer::noteResult($available,dp_panel_renderer_actions_request('POST',['note'=>'Coverage note','return_to'=>'/panel/action_records?note=1']),$record)->status());

	$t->same(404,PanelRenderer::messageResult($base,dp_panel_renderer_actions_request('POST',['body'=>'x']),$record)->status());
	$t->same(405,PanelRenderer::messageResult($available,dp_panel_renderer_actions_request('GET',['body'=>'x']),$record)->status());
	$t->same(404,PanelRenderer::messageResult($available,dp_panel_renderer_actions_request('POST',['body'=>'x']),null)->status());
	$t->same(403,PanelRenderer::messageResult($denied,dp_panel_renderer_actions_request('POST',['body'=>'x']),$record)->status());
	$t->same(422,PanelRenderer::messageResult($available,dp_panel_renderer_actions_request('POST',['message'=>' ']),$record)->status());
	$message=PanelRenderer::messageResult($available,dp_panel_renderer_actions_request('POST',['message'=>'Hello','channel'=>'SMS','recipient'=>' 555 ','subject'=>' Subject ']),$record);
	$t->same('sms',$message->data()['message']['channel']);

	$t->same(404,PanelRenderer::tagResult($base,dp_panel_renderer_actions_request('POST',['tag'=>'urgent']),$record)->status());
	$t->same(405,PanelRenderer::tagResult($available,dp_panel_renderer_actions_request('GET',['tag'=>'urgent']),$record)->status());
	$t->same(404,PanelRenderer::tagResult($available,dp_panel_renderer_actions_request('POST',['tag'=>'urgent']),null)->status());
	$t->same(422,PanelRenderer::tagResult($available,dp_panel_renderer_actions_request('POST',['tag'=>'','tag_action'=>'bad']),$record)->status());
	$t->same(403,PanelRenderer::tagResult($denied,dp_panel_renderer_actions_request('POST',['tag'=>'urgent','tag_action'=>'add']),$record)->status());
	$tagged=PanelRenderer::tagResult($available,dp_panel_renderer_actions_request('POST',['tag'=>'Urgent','tag_action'=>'remove']),$record);
	$t->same('remove',$tagged->data()['action']);

	$file=['name'=>'proof.txt','type'=>'text/plain','size'=>12,'tmp_name'=>'C:/tmp/proof.txt','error'=>UPLOAD_ERR_OK];
	$t->same(404,PanelRenderer::attachResult($base,dp_panel_renderer_actions_request('POST',[],[],[],['attachment'=>$file]),$record)->status());
	$t->same(405,PanelRenderer::attachResult($available,dp_panel_renderer_actions_request('GET',[],[],[],['attachment'=>$file]),$record)->status());
	$t->same(404,PanelRenderer::attachResult($available,dp_panel_renderer_actions_request('POST',[],[],[],['attachment'=>$file]),null)->status());
	$t->same(403,PanelRenderer::attachResult($denied,dp_panel_renderer_actions_request('POST',[],[],[],['attachment'=>$file]),$record)->status());
	$t->same(422,PanelRenderer::attachResult($available,dp_panel_renderer_actions_request('POST'),$record)->status());
	$attached=PanelRenderer::attachResult($available,dp_panel_renderer_actions_request('POST',[],[],[],['attachment'=>$file]),$record);
	$t->same('proof.txt',$attached->data()['file']['name']);
	$t->same('proof.txt',$attached->data()['file']['tmp_name']);
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');

test('panel renderer actions covers task creation updates and approval resolution',static function(Context $t): void {
	$record=dp_panel_renderer_actions_record();
	$base=dp_panel_renderer_actions_resource();
	$available=dp_panel_renderer_actions_mutation_resource();
	$denied=dp_panel_renderer_actions_mutation_resource(static fn(): bool=>false);

	$t->same(404,PanelRenderer::taskResult($base,dp_panel_renderer_actions_request('POST',['task'=>'review']),$record)->status());
	$t->same(405,PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('GET',['task'=>'review']),$record)->status());
	$t->same(404,PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST',['task'=>'review']),null)->status());
	$t->same(422,PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST'),$record)->status());
	$t->same(403,PanelRenderer::taskResult($denied,dp_panel_renderer_actions_request('POST',['task'=>'review']),$record)->status());
	$closed=PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST',['task'=>'review','completed'=>'yes']),$record);
	$t->same(true,$closed->data()['completed']);
	$opened=PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST',['task'=>'review','completed'=>'off']),$record);
	$t->same(false,$opened->data()['completed']);

	$updateOnly=$base->updateTaskUsing(static fn(): array=>['ok'=>true]);
	$t->same(404,PanelRenderer::taskResult($updateOnly,dp_panel_renderer_actions_request('POST',['task_action'=>'create','title'=>'New']),$record)->status());
	$t->same(403,PanelRenderer::taskResult($denied,dp_panel_renderer_actions_request('POST',['task_action'=>'add','title'=>'New']),$record)->status());
	$t->same(422,PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST',['task_action'=>'create','title'=>' ']),$record)->status());
	$created=PanelRenderer::taskResult($available,dp_panel_renderer_actions_request('POST',[
		'task_action'=>'add','title'=>' Follow up ','description'=>' Details ','due'=>' 2026-08-01 ','assignee'=>' Ada ',
	]),$record);
	$t->same('Follow up',$created->data()['task']['title']);

	$t->same(404,PanelRenderer::approvalResult($base,dp_panel_renderer_actions_request('POST',['approval'=>'legal','decision'=>'approve']),$record)->status());
	$t->same(405,PanelRenderer::approvalResult($available,dp_panel_renderer_actions_request('GET',['approval'=>'legal','decision'=>'approve']),$record)->status());
	$t->same(404,PanelRenderer::approvalResult($available,dp_panel_renderer_actions_request('POST',['approval'=>'legal','decision'=>'approve']),null)->status());
	$t->same(422,PanelRenderer::approvalResult($available,dp_panel_renderer_actions_request('POST',['approval'=>'','decision'=>'maybe']),$record)->status());
	$t->same(403,PanelRenderer::approvalResult($denied,dp_panel_renderer_actions_request('POST',['approval'=>'legal','decision'=>'approve']),$record)->status());
	$approved=PanelRenderer::approvalResult($available,dp_panel_renderer_actions_request('POST',['approval'=>'Legal','decision'=>'approve']),$record);
	$t->same('approve',$approved->data()['decision']);
	$rejected=PanelRenderer::approvalResult($available,dp_panel_renderer_actions_request('POST',['approval'=>'legal','decision'=>'reject']),$record);
	$t->same('reject',$rejected->data()['decision']);
})->tag('panel','renderer','actions','coverage')->group('framework-coverage');
