<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);


test('panel action imports a comprehensive manifest definition',static function(Context $t): void {
	$callback=static fn(mixed ...$arguments): mixed=>$arguments[0] ?? null;
	$action=Action::fromArray([
		'name'=>'approve','label'=>'Approve','icon'=>'check','tone'=>'success','style'=>'outline',
		'size'=>'lg','icon_only'=>true,'record_placement'=>'menu','description'=>'Approve this record','badge'=>3,
		'badge_tone'=>'info','tooltip'=>'Approve now','key_bindings'=>['ctrl+a','CMD + Enter'],
		'extra_attributes'=>['class'=>'primary','data-test'=>'approve','aria-label'=>'Approve'],
		'requires_confirmation'=>true,'modal'=>true,'modal_heading'=>'Confirm approval',
		'modal_description'=>'This cannot be undone','modal_submit_label'=>'Approve',
		'modal_cancel_label'=>'Cancel','modal_width'=>'xl','modal_back'=>true,
		'modal_stack'=>'stack','modal_content'=>'Confirmation content','bulk'=>true,
		'allow_empty_selection'=>true,'success_message'=>'Approved','redirect_to'=>'/orders',
		'effects'=>['refresh'=>['table','widgets'],'close_modal'=>false,'events'=>[['name'=>'approved']]],
		'visible'=>true,'hidden'=>false,'disabled'=>false,'disabled_reason'=>'Unavailable',
		'fields'=>[['name'=>'note','type'=>'textarea']],
		'schema'=>[['name'=>'reason','type'=>'text']],
		'form_sections'=>[['name'=>'main','fields'=>[['name'=>'comment']]]],
		'meta'=>['custom'=>'value'],'mutate_data'=>$callback,'mutate_form_data'=>$callback,
		'before_validate'=>$callback,'after_validate'=>$callback,'before'=>$callback,
		'before_action'=>$callback,'after'=>$callback,'after_action'=>$callback,'failure'=>$callback,
	]);
	$manifest=$action->toArray();
	$t->same('approve',$action->name());
	$t->same('Approve',$manifest['label']);
	$t->isTrue($manifest['bulk']);
	$t->isTrue($manifest['requires_confirmation']);
	$t->same('overflow',$manifest['record_placement']);
	$t->isTrue($action->hasFields());
	$t->isTrue($action->hasModalContent());

	$alternate=Action::fromArray([
		'name'=>'alternate','variant'=>'ghost','help'=>'Help','key_binding'=>'alt+x',
		'attributes'=>['title'=>'Alternate'],'action_effects'=>['refresh'=>false],
		'disabled_reason'=>'Reason',
	]);
	$t->same('ghost',$alternate->toArray()['style']);
})->tag('panel','action','coverage')->group('framework-coverage');

test('panel action resolves dynamic state and executes lifecycle outcomes',static function(Context $t): void {
	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'action','user'=>['id'=>1]]);
	$resource=Resource::make('orders')->label('Order')->url('/orders');
	$record=['id'=>5,'title'=>'Five'];
	$action=Action::make('approve')
		->label(static fn(): string=>'Dynamic label')
		->icon(static fn(): string=>'dynamic-icon')
		->tone(static fn(): string=>'SUCCESS')
		->description(static fn(): string=>'Dynamic description')
		->badge(static fn(): int=>9)
		->badgeTone(static fn(): string=>'warning')
		->tooltip(static fn(): string=>'Dynamic tooltip')
		->extraAttributes(static fn(): array=>['class'=>'dynamic','data-id'=>'5'])
		->disabledReason(static fn(): string=>'Dynamic reason')
		->fields([Field::make('note')])
		->modalContent(static fn(): string=>'Dynamic modal')
		->authorize(static fn(): bool=>true)->visible(static fn(): bool=>true)
		->hidden(static fn(): bool=>false)->disabled(static fn(): bool=>false);
	$meta=$action->resolvedMeta($record,$request,$resource);
	$t->same('Dynamic label',$meta['label']);
	$t->same('dynamic-icon',$meta['icon']);
	$t->same('success',$meta['tone']);
	$t->same('9',$meta['badge']);
	$t->same('Dynamic modal',$action->resolveModalContent($record,$request,$resource));
	$t->isTrue($action->can($record,$request->user(),$resource));
	$t->isTrue($action->isVisible($record,$request->user(),$resource,$request));
	$t->isFalse($action->isDisabled($record,$request->user(),$resource,$request));
	$t->same('Dynamic reason',$action->disabledReasonFor($record,$request->user(),$resource,$request));
	$t->instanceOf(\Dataphyre\Panel\PanelActionState::class,$action->state($record,$request,$resource));
	$t->isTrue(is_array($action->manifest($record,$request,$resource)));

	$t->throws(static fn()=>Action::make('missing')->execute(),LogicException::class);
	$executing=Action::make('run')
		->mutateDataUsing(static fn(array $data): array=>$data+['mutated'=>true])
		->beforeValidateUsing(static fn(): null=>null)
		->afterValidateUsing(static fn(PanelFormState $state): PanelFormState=>$state)
		->before(static fn(): true=>true)
		->handle(static fn(array $data): array=>['handled'=>$data])
		->after(static fn(array $result): array=>$result+['after'=>true]);
	$t->same(['handled'=>['value'=>1,'mutated'=>true],'after'=>true],$executing->execute($record,['value'=>1],$resource,true,$request));
	$t->same(['handled'=>['value'=>1]],$executing->execute($record,['value'=>1],$resource,false,$request));
	$t->same(['value'=>1,'mutated'=>true],$executing->mutateFormData(['value'=>1],$record,$request,$resource));
	$t->same(null,$executing->runBeforeValidate($record,$request,$resource));
	$state=PanelFormState::make(['value'=>1]);
	$t->same($state,$executing->runAfterValidate($state,$record,$request,$resource));
	$t->same(null,$executing->runBeforeAction([], $record,$request,$resource));

	$halted=Action::make('halt')->before(static fn(): bool=>false)->handle(static fn(): string=>'never');
	$t->instanceOf(PanelLifecycleResult::class,$halted->execute($record,[],null,true,$request));
	$custom=Action::make('custom')->before(static fn(): string=>'custom')->handle(static fn(): string=>'never');
	$t->same('custom',$custom->execute());
	$failed=Action::make('fail')->handle(static function(): never { throw new RuntimeException('failed'); })
		->failure(static fn(Throwable $exception): string=>'recovered');
	$t->same('recovered',$failed->execute());
	$t->throws(static fn()=>Action::make('fail')->handle(static function(): never {
		throw new RuntimeException('failed');
	})->failure(static fn(): null=>null)->execute(),RuntimeException::class);
})->tag('panel','action','coverage')->group('framework-coverage');

test('panel action aliases effects and normalizers cover residual contracts',static function(Context $t): void {
	$actions=[
		Action::make('a')->style('outlined'),Action::make('a')->style('text'),
		Action::make('a')->style('link'),Action::make('a')->style('unknown'),
		Action::make('a')->outlined(false),Action::make('a')->outline(),
		Action::make('a')->ghost(false),Action::make('a')->subtle(),Action::make('a')->link(false),
		Action::make('a')->size('small'),Action::make('a')->size('large'),Action::make('a')->size('xs'),
		Action::make('a')->compact(false),Action::make('a')->large(false),Action::make('a')->iconButton(),
		Action::make('a')->recordPlacement('inline'),Action::make('a')->recordPrimary(false),Action::make('a')->recordOverflow(),
		Action::make('a')->descriptionUsing(static fn(): string=>'Description'),
		Action::make('a')->help('Help'),Action::make('a')->badgeUsing(static fn(): int=>1),
		Action::make('a')->tooltipUsing(static fn(): string=>'Tooltip'),
		Action::make('a')->keyBindings(['cmd+k','control + return',new stdClass(),'cmd']),
		Action::make('a')->attributes(['role'=>'button'],false),
		Action::make('a')->attribute('download',true)->data('order id',5)->aria('label','Action'),
		Action::make('a')->confirmation('Confirm'),
		Action::make('a')->modal('Heading','Description','full'),
		Action::make('a')->modal(false),Action::make('a')->slideOver('Heading','Description','xl'),
		Action::make('a')->slideOver(false),Action::make('a')->modalSize('lg'),
		Action::make('a')->preserveModalHistory(),Action::make('a')->modalStack('replace'),
		Action::make('a')->stackedModal(),Action::make('a')->replaceModal(),
		Action::make('a')->clearModalStack(),Action::make('a')->infoModal('Info','Description'),
		Action::make('a')->refresh('table widgets')->refreshPanel()->refreshTable(),
		Action::make('a')->refreshWidgets()->refreshNavigation()->withoutRefresh(),
		Action::make('a')->closeModal(false)->keepModalOpen(),
		Action::make('a')->dispatchBrowserEvent('updated',['id'=>1]),
		Action::make('a')->visibleUsing(static fn(): bool=>true),
		Action::make('a')->hiddenUsing(static fn(): bool=>false),
		Action::make('a')->disabledUsing(static fn(): bool=>false),
		Action::make('a')->enabled(),
		Action::make('a')->field(Field::make('note')),
		Action::make('a')->formSection('main',[Field::make('note')]),
		Action::make('a')->form(\Dataphyre\Panel\ResourceForm::make()),
	];
	$t->isTrue(count($actions)>40);
	foreach($actions as $action){
		$t->isTrue(is_array($action->toArray()));
	}
	$t->same('primary',Action::make('a')->recordPlacement('visible')->recordPlacementMode());
	$t->same('overflow',Action::make('a')->recordPlacement('secondary')->recordPlacementMode());
	$t->same('auto',Action::make('a')->recordPlacement('invalid')->recordPlacementMode());

	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'action']);
	$resource=Resource::make('orders');
	$broken=Action::make('broken')->label('')->icon(null)->tone('unsupported')
		->badge(null)->badgeTone('unsupported')->tooltip(null)->description(null)
		->extraAttributes([])->authorize(static fn(): bool=>false)
		->visible(false)->hidden(true)->disabled(true,'Disabled');
	$t->same('Broken',$broken->resolveLabel([], $request,$resource));
	$t->same(null,$broken->resolveIcon([], $request,$resource));
	$t->same('neutral',$broken->resolveTone([], $request,$resource));
	$t->same(null,$broken->resolveBadge([], $request,$resource));
	$t->same('neutral',$broken->resolveBadgeTone([], $request,$resource));
	$t->same(null,$broken->resolveTooltip([], $request,$resource));
	$t->same(null,$broken->resolveDescription([], $request,$resource));
	$t->same([],$broken->resolveExtraAttributes([], $request,$resource));
	$t->isFalse($broken->can([],null,$resource));
	$t->isFalse($broken->isVisible([],null,$resource,$request));
	$t->isTrue($broken->isDisabled([],null,$resource,$request));
	$t->same('Disabled',$broken->disabledReasonFor([],null,$resource,$request));
	$t->instanceOf(\Dataphyre\Panel\PanelActionState::class,$broken->bulk()->state([['id'=>1],['id'=>2]],$request,$resource));

	$base=Action::make('normalizers');
	$actionInternals=$t->nonPublic($base);
	$halt=PanelLifecycleResult::halt('Halt');
	$t->same($halt,$actionInternals->invoke('normalizeLifecycleResult',$halt));
	$t->instanceOf(PanelLifecycleResult::class,$actionInternals->invoke('normalizeLifecycleResult',false));
	$t->instanceOf(PanelLifecycleResult::class,$actionInternals->invoke('normalizeLifecycleResult',[
		'halt'=>true,'notification'=>['tone'=>'warning'],'notifications'=>[['tone'=>'danger']],'status'=>409,
	]));
	$t->same(null,$actionInternals->invoke('normalizeLifecycleResult',['halt'=>false]));
	$effects=$actionInternals->invoke('normalizeEffects',[
		'close_modal'=>true,'refresh'=>'table, widgets','event'=>'saved',
		'events'=>[['event'=>'updated','data'=>['id'=>1]],42,['name'=>'']],
	]);
	$t->isTrue($effects['close_modal']);
	$t->same(['table','widgets'],$effects['refresh']);
	$t->same([],$actionInternals->invoke('normalizeEffectTargets',[new stdClass()]));
	$t->same('',$actionInternals->invoke('normalizeKeyBinding','cmd'));
	$t->same('mod+ctrl+alt+enter',$actionInternals->invoke('normalizeKeyBinding','command+control+option+return'));
	$attributes=$actionInternals->invoke('normalizeExtraAttributes',[
		'class','role'=>'button','data-ok'=>'yes','data-dp-panel-owned'=>'no',
		'aria-label'=>'Label','aria-disabled'=>true,'invalid name'=>'x',
		'title'=>new stdClass(),'tabindex'=>null,'download'=>false,
	]);
	$t->contains('class',array_keys($attributes));
	$t->contains('data-ok',array_keys($attributes));
	$t->same('order-id',$actionInternals->invoke('normalizeAttributeSegment',' Order ID '));

	$t->same('md',Action::make('a')->size('unsupported')->toArray()['size']);
	$t->isTrue(Action::make('a')->modalStack(true)->toArray()['meta']['modal_back']);
	$t->same([],Action::make('a')->dispatchBrowserEvent('')->toArray()['meta']['effects']['events'] ?? []);
	$t->isTrue(Action::make('a')->enabled(static fn(): bool=>false)->isDisabled());
	$t->same('Static modal',Action::make('a')->modalContent('Static modal')->resolveModalContent());
	$nonscalar=Action::make('fallback')->label(static fn(): array=>[])
		->icon(static fn(): array=>[])->badge(static fn(): array=>[])
		->tooltip(static fn(): array=>[])->description(static fn(): array=>[]);
	$t->same('Fallback',$nonscalar->resolveLabel());
	$t->same(null,$nonscalar->resolveIcon());
	$t->same(null,$nonscalar->resolveBadge());
	$t->same(null,$nonscalar->resolveTooltip());
	$t->same(null,$nonscalar->resolveDescription());
	$t->isTrue(Action::make('plain')->can());
	$t->isTrue(Action::make('plain')->isVisible());
	$t->isFalse(Action::make('plain')->isDisabled());
	$t->same('This action is not available right now.',Action::make('a')->disabled()->disabledReasonFor());
	$t->same('This action is not available right now.',Action::make('a')->disabledReason(static fn(): bool=>true)->disabledReasonFor());
	$t->same(null,Action::make('a')->disabledReason(static fn(): bool=>false)->disabledReasonFor());
	$t->same(null,Action::make('a')->disabledReason(static fn(): array=>[])->disabledReasonFor());
	$t->same(null,Action::make('a')->runBeforeValidate());
	$plainState=PanelFormState::make([]);
	$t->same($plainState,Action::make('a')->runAfterValidate($plainState));
	$t->instanceOf(PanelLifecycleResult::class,$actionInternals->invoke('normalizeLifecycleResult',['halt'=>true]));
	$t->same('',$actionInternals->invoke('normalizeKeyBinding',''));
	$t->same([],$actionInternals->invoke('normalizeExtraAttributes',[new stdClass()]));
})->tag('panel','action','coverage')->group('framework-coverage');
