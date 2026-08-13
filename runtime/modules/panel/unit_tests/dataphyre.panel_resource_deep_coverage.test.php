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
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use Dataphyre\Test\TypeInventory;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!class_exists('Dataphyre\Database\DB',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\Database; final class DB { public static function table(string $table): array { return [["table"=>$table]]; } }');
}

final class DpPanelResourcePolicy {
	public function view(mixed $record,mixed $user,Resource $resource,string $ability): bool {
		return $ability==='view';
	}
}

final class DpPanelResourceRepository {
	public static function query(): array {
		return [['id'=>99,'title'=>'Repository record']];
	}
}

function dp_panel_resource_returns_self(ReflectionMethod $method): bool {
	$return=$method->getReturnType();
	$types=$return instanceof ReflectionUnionType ? $return->getTypes() : [$return];
	foreach($types as $type){
		if($type instanceof ReflectionNamedType && $type->getName()==='self'){
			return true;
		}
	}
	return false;
}

function dp_panel_resource_argument(ReflectionParameter $parameter,string $method): mixed {
	if($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue()!==null){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		return match($candidate->getName()){
			'array'=>match($method){
				'fields','bulkFields','previewFields'=>[Field::make('title')],
				'actions'=>[Action::make('edit')],
				'statusTransitions'=>[['name'=>'approve','from'=>['pending'],'to'=>'approved']],
				default=>[],
			},
			'bool'=>true,
			'callable'=>static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [],
			'float'=>1.0,
			'int'=>1,
			'object'=>new stdClass(),
			'string'=>match(strtolower($parameter->getName())){
				'ability'=>'view',
				'action'=>'edit',
				'field'=>'status',
				'icon'=>'check',
				'method'=>'GET',
				'name'=>'fixture',
				'status'=>'pending',
				'table'=>'records',
				'tone'=>'neutral',
				'url'=>'/records',
				default=>'fixture',
			},
			default=>'fixture',
		};
	}
	if($type?->allowsNull()){
		return null;
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		$class=$candidate->getName();
		if($class===Closure::class){
			return static fn(mixed ...$arguments): mixed=>$arguments[0] ?? [];
		}
		if($class===Field::class){
			return Field::make('title');
		}
		if($class===Action::class){
			return Action::make('edit');
		}
		$inventory=TypeInventory::of($class);
		if($inventory->isInstantiable()){
			if($inventory->hasMethod('make')){
				$factory=$inventory->method('make');
				if($factory->isPublic() && $factory->isStatic()){
					$args=[];
					foreach($factory->getParameters() as $factoryParameter){
						$args[]=dp_panel_resource_argument($factoryParameter,'make');
					}
					$value=$inventory->invokeWithArguments($factory, null, $args);
					if(is_object($value)){
						return $value;
					}
				}
			}
			$constructor=$inventory->constructor();
			if($constructor===null || $constructor->getNumberOfRequiredParameters()===0){
				return $inventory->newInstance();
			}
		}
	}
	return 'fixture';
}

test('panel resource public fluent configuration methods honor declared contracts',static function(Context $t): void {
	$inventory=$t->inventory(Resource::class);
	$failures=[];
	$called=[];
	foreach($inventory->declaredPublicMethods(false) as $method){
		if(!dp_panel_resource_returns_self($method)){
			continue;
		}
		try{
			$resource=Resource::make('orders');
			$arguments=[];
			foreach($method->getParameters() as $parameter){
				$arguments[]=dp_panel_resource_argument($parameter,$method->getName());
			}
			$result=$inventory->invokeWithArguments($method, $resource, $arguments);
			$called[]=$method->getName();
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->isTrue(count($called)>50);
	$t->same([],$failures);
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource imports a comprehensive manifest definition',static function(Context $t): void {
	$list=static fn(mixed ...$arguments): array=>[];
	$value=static fn(mixed ...$arguments): mixed=>$arguments[0] ?? null;
	$definition=[
		'name'=>'orders', 'label'=>'Order', 'plural_label'=>'Orders',
		'model'=>'App\\Order', 'repository'=>'App\\OrderRepository', 'table'=>'orders',
		'url'=>'/orders', 'group'=>'Commerce', 'icon'=>'shopping-cart',
		'navigation_parent'=>'Operations', 'folder'=>'Sales',
		'navigation_description'=>'Manage orders', 'navigation_badge'=>5,
		'navigation_badge_tone'=>'info', 'sort'=>20, 'per_page'=>25,
		'action_fit'=>'compact', 'record_action_limit'=>3,
		'record_action_placements'=>['edit'=>'inline','delete'=>'menu','ignored'=>'invalid'],
		'hidden_from_navigation'=>true, 'policy'=>$value,
		'tenant_field'=>'tenant_id', 'tenant_required'=>false,
		'tenant_resolver'=>$value, 'tenant_scope'=>$value,
		'global_searchable'=>true, 'global_search_columns'=>['id','title'],
		'activity'=>$list, 'insights'=>$list, 'alerts'=>$list, 'links'=>$list,
		'contacts'=>$list, 'locations'=>$list, 'changes'=>$list, 'tags'=>$list,
		'tag'=>$value, 'items'=>$list, 'totals'=>$list, 'approvals'=>$list,
		'approval'=>$value, 'notes'=>$list, 'note'=>$value, 'messages'=>$list,
		'message'=>$value, 'shipments'=>$list, 'payments'=>$list,
		'attachments'=>$list, 'attach'=>$value, 'tasks'=>$list, 'task'=>$value,
		'create_task'=>$value, 'mutate_form_data'=>$value,
		'mutate_create_data'=>$value, 'mutate_update_data'=>$value,
		'mutate_fill_data'=>$value, 'mutate_create_fill_data'=>$value,
		'mutate_edit_fill_data'=>$value, 'before_fill'=>$value, 'after_fill'=>$value,
		'before_validate'=>$value, 'after_validate'=>$value,
		'before_save'=>$value, 'after_save'=>$value,
		'record_key'=>'id', 'record_title'=>'title', 'record_subtitle'=>'subtitle',
		'form'=>['schema'=>[['name'=>'title','type'=>'text']]],
		'bulk_form'=>['schema'=>[['name'=>'status','type'=>'select']]],
		'infolist'=>[['name'=>'title','type'=>'text']],
		'infolist_schema'=>[['name'=>'status','type'=>'text']],
		'fields'=>[['name'=>'description','type'=>'textarea']],
		'schema'=>[['name'=>'total','type'=>'number']],
		'bulk_fields'=>[['name'=>'status','type'=>'select']],
		'bulk_schema'=>[['name'=>'assignee','type'=>'text']],
		'status_field'=>'status',
		'transitions'=>[['name'=>'approve','from'=>['pending'],'to'=>'approved']],
		'status_widgets'=>true,
		'form_sections'=>[['name'=>'main','fields'=>[['name'=>'title']]]],
		'columns'=>[['name'=>'title','label'=>'Title']],
		'views'=>[['name'=>'active','label'=>'Active']],
		'filters'=>[['name'=>'status','label'=>'Status']],
		'summaries'=>[['name'=>'total','label'=>'Total']],
		'actions'=>[
			['name'=>'edit','label'=>'Edit'],
			['name'=>'more','type'=>'group','actions'=>[['name'=>'view']]],
		],
		'relations'=>[['name'=>'items','label'=>'Items']],
	];
	$resource=Resource::fromArray($definition);
	$t->same('orders',$resource->name());
	$t->same('Order',$resource->label());
	$t->same('Orders',$resource->pluralLabel());
	$t->isTrue($resource->isHiddenFromNavigation());
	$t->isTrue($resource->isGlobalSearchable());
	$t->same(3,$resource->recordActionLimitValue());
	$t->same('primary',$resource->recordActionPlacementFor('edit'));
	$t->same('overflow',$resource->recordActionPlacementFor('delete'));
	$t->same('auto',$resource->recordActionPlacementFor('ignored'));
	$t->same(['edit'=>'primary','delete'=>'overflow'],$resource->recordActionPlacementMap());
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource executes configured record handlers and lifecycle hooks',static function(Context $t): void {
	$list=static fn(mixed ...$arguments): array=>[['kind'=>'fixture']];
	$value=static fn(mixed ...$arguments): array=>['ok'=>true];
	$passthrough=static fn(mixed ...$arguments): mixed=>$arguments[0] ?? null;
	$resource=Resource::make('orders')
		->activityUsing($list)->insightsUsing($list)->alertsUsing($list)->linksUsing($list)
		->contactsUsing($list)->locationsUsing($list)->changesUsing($list)->tagsUsing($list)
		->tagUsing($value)->itemsUsing($list)->totalsUsing($list)->approvalsUsing($list)
		->approvalUsing($value)->notesUsing($list)->noteUsing($value)
		->messagesUsing($list)->messageUsing($value)->shipmentsUsing($list)
		->paymentsUsing($list)->attachmentsUsing($list)->attachUsing($value)
		->tasksUsing($list)->taskUsing($value)->createTaskUsing($value)
		->recordKeyUsing('id')->recordTitleUsing('title')->recordSubtitleUsing('subtitle')
		->recordUrlUsing(static fn(): string=>'/orders/1')
		->globalSearchable()->globalSearchColumns(['id','title'])
		->globalSearchUsing(static fn(): array=>[['id'=>1,'title'=>'One','subtitle'=>'First']])
		->globalSearchTitleUsing(static fn(): string=>'Search title')
		->globalSearchSubtitleUsing(static fn(): string=>'Search subtitle')
		->queryUsing(static fn(): ArrayObject=>new ArrayObject([['id'=>1]]))
		->saveUsing(static fn(): array=>['saved'=>true])
		->mutateFormDataUsing(static fn(): array=>['form'=>true])
		->mutateCreateDataUsing(static fn(): array=>['create'=>true])
		->mutateUpdateDataUsing(static fn(): array=>['update'=>true])
		->mutateFillDataUsing(static fn(): array=>['fill'=>true])
		->mutateCreateFillDataUsing(static fn(): array=>['create_fill'=>true])
		->mutateEditFillDataUsing(static fn(): array=>['edit_fill'=>true])
		->beforeFillUsing(static fn(): null=>null)
		->afterFillUsing(static fn(): null=>null)
		->beforeValidateUsing(static fn(): null=>null)
		->afterValidateUsing(static fn(): null=>null)
		->beforeSaveUsing(static fn(): null=>null)
		->afterSaveUsing($passthrough)
		->importUsing(static fn(array $rows): int=>count($rows))
		->transitionUsing($value)->bulkUpdateUsing($value)->duplicateUsing($value)
		->restoreUsing($value)->deleteUsing($value)->forceDeleteUsing($value)
		->statusTransition('approve','approved','Approve','pending')
		->bulkField(Field::make('status'));

	$request=\Dataphyre\Panel\PanelRequest::fromArray([
		'method'=>'GET','resource'=>'orders','operation'=>'index','query'=>['q'=>'one'],
	]);
	$record=['id'=>1,'title'=>'One','subtitle'=>'First','status'=>'pending'];
	foreach([
		'hasActivity','hasInsights','hasAlerts','hasLinks','hasContacts','hasLocations',
		'hasChanges','hasTags','canUpdateTag','hasItems','hasTotals','hasApprovals',
		'canResolveApproval','hasNotes','canAddNote','hasMessages','canSendMessage',
		'hasShipments','hasPayments','hasAttachments','canAttach','hasTasks',
		'canUpdateTask','canCreateTask','canImport','canTransition','canBulkUpdate',
		'canDuplicate','canRestore','canDelete','canForceDelete',
	] as $method){
		$t->isTrue($resource->{$method}());
	}
	$t->same('1',$resource->recordKey($record));
	$t->same('One',$resource->recordTitle($record));
	$t->same('First',$resource->recordSubtitle($record));
	$t->same('/orders/1',$resource->recordUrl($record));
	$t->instanceOf(ArrayObject::class,$resource->makeQuery());
	$t->notEmpty($resource->globalSearchResults('one',$request));

	foreach([
		'recordActivity','recordInsights','recordAlerts','recordLinks','recordContacts',
		'recordLocations','recordChanges','recordTags','recordItems','recordTotals',
		'recordApprovals','recordNotes','recordMessages','recordShipments',
		'recordPayments','recordAttachments','recordTasks',
	] as $method){
		$t->notEmpty($resource->{$method}($record,$request));
	}
	$t->same(['ok'=>true],$resource->updateTag($record,'vip','add',$request));
	$t->same(['ok'=>true],$resource->resolveApproval($record,'review','approve',$request));
	$t->same(['ok'=>true],$resource->addNote($record,'Note',$request));
	$t->same(['ok'=>true],$resource->sendMessage($record,['body'=>'Hello'],$request));
	$t->same(['ok'=>true],$resource->attachFile($record,['name'=>'file.txt'],$request));
	$t->same(['ok'=>true],$resource->updateTask($record,'task-1',true,$request));
	$t->same(['ok'=>true],$resource->createTask($record,['title'=>'Task'],$request));

	$t->same(['create'=>true],$resource->mutateFormData(['title'=>'One'],null,'store',$request));
	$t->same(['create_fill'=>true],$resource->mutateFillData([],null,'create',$request));
	$t->same(['edit_fill'=>true],$resource->mutateFillData([],$record,'edit',$request));
	$state=\Dataphyre\Panel\PanelFormState::make(['title'=>'One']);
	$resource->runBeforeFill($record,'edit',$request);
	$t->instanceOf(\Dataphyre\Panel\PanelFormState::class,$resource->runAfterFill($state,$record,'edit',$request));
	$t->same(null,$resource->runBeforeValidate($record,'store',$request));
	$t->instanceOf(\Dataphyre\Panel\PanelFormState::class,$resource->runAfterValidate($state,$record,'store',$request));
	$t->same(['title'=>'One'],$resource->runBeforeSave(['title'=>'One'],$record,'store',$request));
	$t->same(['saved'=>true],$resource->saveRecord(['title'=>'One'],$record,'store',$request));
	$t->same(2,$resource->importRecords([['id'=>1],['id'=>2]],$request));
	$t->same(['ok'=>true],$resource->applyTransition('approve',$record,$request));
	$t->same(['ok'=>true],$resource->bulkUpdateRecords(['status'=>'done'],[$record],$request));
	$t->same(['ok'=>true],$resource->duplicateRecord($record,$request));
	$t->same(['ok'=>true],$resource->restoreRecord($record,$request));
	$t->same(['ok'=>true],$resource->deleteRecord($record,$request));
	$t->same(['ok'=>true],$resource->forceDeleteRecord($record,$request));
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource default paths remain safe without optional handlers',static function(Context $t): void {
	$resource=Resource::make('orders')->label('Order')->pluralLabel('Orders')->url('/orders');
	$request=\Dataphyre\Panel\PanelRequest::fromArray([
		'method'=>'GET','resource'=>'orders','operation'=>'index',
	]);
	$record=['id'=>7,'title'=>'Seven','subtitle'=>'Default subtitle'];

	foreach([
		'hasActivity','hasInsights','hasAlerts','hasLinks','hasContacts','hasLocations',
		'hasChanges','hasTags','canUpdateTag','hasItems','hasTotals','hasApprovals',
		'canResolveApproval','hasNotes','canAddNote','hasMessages','canSendMessage',
		'hasShipments','hasPayments','hasAttachments','canAttach','hasTasks',
		'canUpdateTask','canCreateTask','canImport','canTransition','canBulkUpdate',
		'canDuplicate','canRestore','canDelete','canForceDelete','hasStatusWidgets',
	] as $method){
		$t->isFalse($resource->{$method}());
	}
	foreach([
		'recordActivity','recordInsights','recordAlerts','recordLinks','recordContacts',
		'recordLocations','recordChanges','recordTags','recordItems','recordTotals',
		'recordApprovals','recordNotes','recordMessages','recordShipments',
		'recordPayments','recordAttachments','recordTasks',
	] as $method){
		$t->same([],$resource->{$method}($record,$request));
	}
	$t->isFalse($resource->updateTag($record,'vip','add',$request)['tag_updated']);
	$t->isFalse($resource->resolveApproval($record,'review','approve',$request)['approval_resolved']);
	$t->isFalse($resource->addNote($record,'Note',$request)['noted']);
	$t->isFalse($resource->sendMessage($record,['body'=>'Hello'],$request)['message_sent']);
	$t->isFalse($resource->attachFile($record,['name'=>'file.txt'],$request)['attached']);
	$t->isFalse($resource->updateTask($record,'task-1',true,$request)['task_updated']);
	$t->isFalse($resource->createTask($record,['title'=>'Task'],$request)['task_created']);
	$t->isFalse($resource->duplicateRecord($record,$request)['duplicated']);
	$t->isFalse($resource->restoreRecord($record,$request)['restored']);
	$t->isFalse($resource->deleteRecord($record,$request)['deleted']);
	$t->isFalse($resource->forceDeleteRecord($record,$request)['force_deleted']);
	$t->same(['transitioned'=>false,'message'=>'Transition is not registered for this resource.'],$resource->applyTransition('missing',$record,$request));
	$t->same(['updated'=>0,'results'=>[]],$resource->bulkUpdateRecords([],[],$request));
	$t->same(['imported'=>[],'failed'=>[],'results'=>[]],$resource->importRecords([],$request));

	$state=\Dataphyre\Panel\PanelFormState::make(['title'=>'Seven']);
	$t->same(['title'=>'Seven'],$resource->mutateFormData(['title'=>'Seven'],$record,'update',$request));
	$t->same(['title'=>'Seven'],$resource->mutateFillData(['title'=>'Seven'],$record,'edit',$request));
	$t->instanceOf(\Dataphyre\Panel\PanelFormState::class,$resource->mutateFormStateBeforeFill($state,$record,'edit',$request));
	$resource->runBeforeFill($record,'edit',$request);
	$t->instanceOf(\Dataphyre\Panel\PanelFormState::class,$resource->runAfterFill($state,$record,'edit',$request));
	$t->same(null,$resource->runBeforeValidate($record,'update',$request));
	$t->instanceOf(\Dataphyre\Panel\PanelFormState::class,$resource->runAfterValidate($state,$record,'update',$request));
	$t->same(['title'=>'Seven'],$resource->runBeforeSave(['title'=>'Seven'],$record,'update',$request));
	$t->same('result',$resource->runAfterSave('result',['title'=>'Seven'],$record,'update',$request));

	$t->instanceOf(\Dataphyre\Panel\ResourceForm::class,$resource->form());
	$t->instanceOf(\Dataphyre\Panel\ResourceForm::class,$resource->bulkForm());
	$t->instanceOf(\Dataphyre\Panel\ResourceTable::class,$resource->resourceTable());
	$t->isTrue(is_array($resource->tableManifest($request)));
	$t->isTrue(is_array($resource->resourceManifest($request)));
	$t->same([],$resource->tableViewsList());
	$t->same([],$resource->tableGroupsList());
	$t->same('',$resource->activeTableGroupName($request));
	$t->same('',$resource->activeTableViewName($request));
	$t->instanceOf(\Dataphyre\Panel\PanelRequest::class,$resource->requestWithResolvedView($request));
	$t->same([],$resource->statusTransitionsList($record));
	$t->same([],$resource->statusViewNames());
	$t->same('stretch',$resource->actionFitMode());
	$t->same(2,$resource->recordActionLimitValue());
	$t->same([],$resource->recordActionPlacementMap());
	$t->same(24,$resource->recordActionLimit(99)->recordActionLimitValue());
	$t->same(0,$resource->recordActionLimit(-1)->recordActionLimitValue());
	$t->same([],$resource->recordActionPlacement('   ','primary')->recordActionPlacementMap());
	$t->same('overflow',$resource->recordActionPlacementFor('missing','menu'));
	$t->same([],$resource->actionsList());
	$t->same(null,$resource->actionByName('missing'));
	$t->same([],$resource->relationManagers());
	$t->same(null,$resource->relationManager('missing'));
	$t->same('7',$resource->recordKey($record));
	$t->same('Seven',$resource->recordTitle($record));
	$t->same('Seven / 7',$resource->recordSubtitle($record));
	$t->same('/orders/show/7',$resource->recordUrl($record));
	$t->same([],$resource->globalSearchResults('seven',$request));
	$t->isTrue(is_array($resource->navigationEntry($request)));
	$t->same([],$resource->dashboardStatusWidgets($request));
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource infolist state formats renderer values and nested entries',static function(Context $t): void {
	$request=\Dataphyre\Panel\PanelRequest::fromArray(['resource'=>'orders','operation'=>'show','record'=>'9']);
	$resource=Resource::make('orders')->url('/orders')->infolistEntries([
		Field::make('enabled')->toggle(),
		Field::make('status')->select()->options([
			'open'=>'Open',
			'group'=>['label'=>'Closed group','options'=>['closed'=>'Closed']],
		]),
		Field::make('attachment')->upload(),
		Field::make('items')->repeater([
			Field::make('sku')->label('SKU'),
			Field::make('quantity')->number()->label('Quantity'),
		]),
		Field::make('missing')->meta(['empty'=>'Nothing here']),
	]);
	$state=$resource->infolistState([
		'id'=>9,'title'=>'Order Nine','enabled'=>'yes','status'=>'closed',
		'attachment'=>[['name'=>'invoice.pdf'],'packing-slip.pdf'],
		'items'=>[['sku'=>'ABC','quantity'=>2],['sku'=>'','quantity'=>'']],
	],$request);
	$t->isTrue(count($state->entries())>=5);
	$t->notEmpty($state->sections());

	$plain=Field::make('value');
	$meta=$plain->toArray();
	$t->same('Not set',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$plain,$meta,null,null,$request));
	$t->same('Custom empty',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$plain,$plain->meta(['empty'=>'Custom empty'])->toArray(),'',null,$request));
	$t->same('Yes',$t->nonPublic(Resource::class)->invoke('displayEntryValue',Field::make('flag')->toggle(),Field::make('flag')->toggle()->toArray(),'on',null,$request));
	$t->same('No',$t->nonPublic(Resource::class)->invoke('displayEntryValue',Field::make('flag')->checkbox(),Field::make('flag')->checkbox()->toArray(),0,null,$request));
	$file=Field::make('file')->upload();
	$t->same('photo.jpg',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$file,$file->toArray(),['name'=>'photo.jpg'],null,$request));
	$t->same('a.txt, b.txt',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$file,$file->toArray(),[['name'=>'a.txt'],'b.txt'],null,$request));
	$t->same('Uploaded file',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$file,$file->toArray(),[['name'=>['nested']]],null,$request));
	$choice=Field::make('choice')->select()->options([
		'direct'=>'Direct',
		'group'=>['label'=>'Group','options'=>['nested'=>'Nested']],
		['value'=>'listed','label'=>'Listed'],
	]);
	$t->same('Direct, Nested, Listed, unknown',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$choice,$choice->toArray(),['direct','nested','listed','unknown'],null,$request,));
	$display=Field::make('display')->displayUsing(static fn(): array=>['custom'=>'value']);
	$t->same('{"custom":"value"}',$t->nonPublic(Resource::class)->invoke('displayEntryValue',$display,$display->toArray(),'raw',null,$request));

	$t->same('Direct',$t->nonPublic(Resource::class)->invoke('entryOptionLabel',['direct'=>'Direct'],'direct'));
	$t->same('Nested',$t->nonPublic(Resource::class)->invoke('entryOptionLabel',['group'=>['label'=>'Group','options'=>['nested'=>'Nested']]],'nested',));
	$t->same('Listed',$t->nonPublic(Resource::class)->invoke('entryOptionLabel',[['value'=>'listed','label'=>'Listed']],'listed'));
	$t->same(null,$t->nonPublic(Resource::class)->invoke('entryOptionLabel',['one'=>'One'],'missing'));
	$t->same('No items',$t->nonPublic(Resource::class)->invoke('entryRepeaterDisplayValue',[],['meta'=>[]]));
	$t->same('#1 SKU: ABC',$t->nonPublic(Resource::class)->invoke('entryRepeaterDisplayValue',[['sku'=>'ABC'],'invalid'],
		['repeater_fields'=>['invalid',['name'=>'sku','label'=>'SKU']]],));
	$t->same('',$t->nonPublic(Resource::class)->invoke('entryStringValue',null));
	$t->same('1',$t->nonPublic(Resource::class)->invoke('entryStringValue',true));
	$t->same('10',$t->nonPublic(Resource::class)->invoke('entryStringValue',10));
	$t->same('stringable',$t->nonPublic(Resource::class)->invoke('entryStringValue',new class implements Stringable {
		public function __toString(): string { return 'stringable'; }
	}));
	$t->same('{"a":1}',$t->nonPublic(Resource::class)->invoke('entryStringValue',['a'=>1]));
	$t->isTrue($t->nonPublic(Resource::class)->invoke('truthyEntryValue',true));
	$t->isTrue($t->nonPublic(Resource::class)->invoke('truthyEntryValue','enabled'));
	$t->isFalse($t->nonPublic(Resource::class)->invoke('truthyEntryValue','no'));

	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(Resource::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(Resource::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(Resource::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(Resource::class)->invoke('recordValue',$object,'missing','default'));
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource authorization supports aliases policy shapes and failures',static function(Context $t): void {
	$resource=Resource::make('orders');
	$t->isTrue($resource->can('anything'));
	$t->throws(static fn()=>$resource->policy(''),InvalidArgumentException::class);
	$t->throws(static fn()=>$resource->policy('Missing\\Policy\\Class'),InvalidArgumentException::class);
	$t->isTrue($resource->policy(DpPanelResourcePolicy::class)->can('show',['id'=>1],new stdClass()));
	$t->isTrue($resource->policy(['orders:*'=>static fn(): bool=>true])->can('orders.edit'));
	$t->isFalse($resource->policy(['delete'=>false])->can('delete'));
	$t->isTrue($resource->policy(['unrelated'=>false])->can('view'));
	$t->isTrue($resource->policy(new stdClass())->can('view',null,new class {
		public function can(string $ability,mixed $record,Resource $resource): bool {
			return $ability==='view';
		}
	}));
	$t->isFalse($resource->policy(new stdClass())->can('view',null,new class {
		public function can(string $ability,mixed $record,Resource $resource): bool { return false; }
	}));
	$t->isTrue($resource->authorize(static fn(string $ability): bool=>$ability==='view')->can('show'));
	$t->isFalse($resource->authorize(static fn(): bool=>false)->can('update'));
	$t->isFalse($resource->authorize(static function(): bool {
		throw new RuntimeException('denied');
	})->can('view'));

	$candidates=$t->nonPublic(Resource::class)->invoke('abilityCandidates','bulk_force_delete');
	$t->contains('force_delete_any',$candidates);
	$t->contains('orders',$t->nonPublic(Resource::class)->invoke('abilityCandidates','orders.view'));
	$t->contains('orders:*',$t->nonPublic(Resource::class)->invoke('policyArrayKeys','orders.view'));
	$t->contains('ordersView',$t->nonPublic(Resource::class)->invoke('policyMethodNames','orders.view'));
	$t->same('bulkForceDelete',$t->nonPublic(Resource::class)->invoke('camelAbility','bulk_force-delete'));
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource record handler exceptions fail closed',static function(Context $t): void {
	$throw=static function(): never { throw new RuntimeException('handler failed'); };
	$resource=Resource::make('orders')
		->activityUsing($throw)->insightsUsing($throw)->alertsUsing($throw)->linksUsing($throw)
		->contactsUsing($throw)->locationsUsing($throw)->changesUsing($throw)->tagsUsing($throw)
		->itemsUsing($throw)->totalsUsing($throw)->approvalsUsing($throw)
		->notesUsing($throw)->messagesUsing($throw)->shipmentsUsing($throw)
		->paymentsUsing($throw)->attachmentsUsing($throw)->tasksUsing($throw);
	$request=\Dataphyre\Panel\PanelRequest::fromArray(['resource'=>'orders','operation'=>'show']);
	foreach([
		'recordActivity','recordInsights','recordAlerts','recordLinks','recordContacts',
		'recordLocations','recordChanges','recordTags','recordItems','recordTotals',
		'recordApprovals','recordNotes','recordMessages','recordShipments',
		'recordPayments','recordAttachments','recordTasks',
	] as $method){
		$t->same([],$resource->{$method}(['id'=>1],$request));
	}
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource tenant scopes and lifecycle outcomes cover boundary contracts',static function(Context $t): void {
	$records=[
		['id'=>1,'tenant_id'=>'alpha'],
		['id'=>2,'tenant_id'=>'beta'],
		(object)['id'=>3,'tenant_id'=>'alpha'],
	];
	$unscoped=Resource::make('orders')->queryUsing(static fn(): array=>$records);
	$t->same($records,$unscoped->makeQuery());
	$t->same([], $unscoped->tenantScoped()->makeQuery());
	$t->same($records,$unscoped->tenantScoped('tenant_id',false)->makeQuery());
	$scoped=$unscoped->tenantScoped()->tenantUsing(static fn(): string=>'alpha');
	$t->same([1,3],array_map(static fn(mixed $row): int=>(int)(is_array($row) ? $row['id'] : $row->id),$scoped->makeQuery()));
	$request=\Dataphyre\Panel\PanelRequest::fromArray(['tenant'=>'beta']);
	$t->same([2],array_map(static fn(array $row): int=>$row['id'],
		Resource::make('orders')->tenantScoped()->queryUsing(static fn(): array=>array_slice($records,0,2))->makeQuery($request)
	));
	$t->same([],Resource::make('orders')->tenantScoped()->tenantUsing(static fn(): array=>['invalid'])->queryUsing(static fn(): array=>$records)->makeQuery());
	$t->same(['custom'=>'alpha'],Resource::make('orders')->tenantScoped()->tenantUsing(static fn(): string=>'alpha')
		->tenantScopeUsing(static fn(mixed $source,string $tenant): array=>['custom'=>$tenant])
		->queryUsing(static fn(): array=>$records)->makeQuery());
	$query=new class {
		public array $where=[];
		public function where(string $field,string $tenant): null { $this->where=[$field,$tenant]; return null; }
	};
	$result=Resource::make('orders')->tenantScoped()->tenantUsing(static fn(): string=>'alpha')
		->queryUsing(static fn()=>$query)->makeQuery();
	$t->same($query,$result);
	$t->same(['tenant_id','alpha'],$query->where);

	$plain=Resource::make('orders');
	$t->isFalse($plain->saveRecord(['title'=>'One'],null,'store',null,false,true)['saved']);
	$t->isFalse($plain->saveRecord(['title'=>'One'])['saved']);
	$t->same(['saved'=>true],Resource::make('orders')->saveUsing(static fn(): array=>['saved'=>true])
		->saveRecord([],null,'store',null,true,true));
	$t->nonPublic($plain)->writeProperty('formDataMutationActive',true);
	$t->same(['title'=>'One'],$plain->mutateFormData(['title'=>'One']));

	$halt=\Dataphyre\Panel\PanelLifecycleResult::halt('Stopped');
	$t->same($halt,$t->nonPublic($plain)->invoke('normalizeLifecycleResult',$halt));
	$t->instanceOf(\Dataphyre\Panel\PanelLifecycleResult::class,$t->nonPublic($plain)->invoke('normalizeLifecycleResult',false));
	$arrayHalt=$t->nonPublic($plain)->invoke('normalizeLifecycleResult',[
		'halt'=>true,'message'=>'Nope','notification'=>['tone'=>'warning'],
		'notifications'=>[['tone'=>'danger']],'status'=>409,
	]);
	$t->instanceOf(\Dataphyre\Panel\PanelLifecycleResult::class,$arrayHalt);
	$t->same(null,$t->nonPublic($plain)->invoke('normalizeLifecycleResult',['halt'=>false]));

	$success=Resource::make('orders')->saveUsing(static fn(array $data): array=>['saved'=>(bool)($data['ok'] ?? false)]);
	$import=$success->importRecords(['invalid',['ok'=>true],['ok'=>false]]);
	$t->same([1],$import['imported']);
	$t->same([0,2],$import['failed']);
	foreach([
		[true,true],[1,true],['ok',true],[['saved'=>true],true],[['success'=>true],true],
		[['ok'=>true],true],[['failed'=>true],true],[false,false],[null,true],
	] as [$outcome,$expected]){
		$t->same($expected,$t->nonPublic(Resource::class)->invoke('saveOutcomeSucceeded',$outcome));
	}
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource status views widgets and global search adapt data sources',static function(Context $t): void {
	$records=[
		['id'=>1,'title'=>'Alpha order','status'=>'pending'],
		['id'=>2,'title'=>'Beta order','status'=>'approved'],
		['id'=>3,'title'=>'Other','status'=>'pending'],
	];
	$resource=Resource::make('orders')->label('Order')->pluralLabel('Orders')->url('/orders')
		->statusField('status')->statusWidgets()->statusTransitions([
			['name'=>'approve','from'=>['pending'],'to'=>'approved','tone'=>'success'],
			['name'=>'archive','from'=>[],'to'=>'archived','tone'=>'neutral'],
		])
		->queryUsing(static fn(): array=>$records)
		->globalSearchable()->globalSearchColumns(['title']);
	$request=\Dataphyre\Panel\PanelRequest::fromArray(['resource'=>'orders','operation'=>'index']);
	$t->same(3,count($t->nonPublic($resource)->invoke('statusTableViews')));
	$t->same(3,count($resource->dashboardStatusWidgets($request)));
	$t->same(2,count($resource->statusTransitionsList(['status'=>'pending'])));
	$t->same(1,count($resource->statusTransitionsList(['status'=>'approved'])));
	$t->isTrue($t->nonPublic($resource)->invoke('statusTransitionApplies',['from'=>[]],['status'=>'anything'],));
	$t->isTrue($t->nonPublic($resource)->invoke('statusTransitionApplies',['from'=>'anything'],['status'=>'other'],));
	$t->isFalse($t->nonPublic($resource)->invoke('statusTransitionApplies',['from'=>['pending']],['status'=>'approved'],));

	$viewed=$resource->views([
		['name'=>'active','label'=>'Active','default'=>true,'query'=>['page'=>2,'filter'=>'open']],
		['name'=>'recent','label'=>'Recent','query'=>['page'=>1]],
	]);
	$t->same('active',$viewed->activeTableViewName($request));
	$t->same('', $viewed->activeTableViewName($request->withQueryValue('view','all')));
	$t->same('recent',$viewed->activeTableViewName($request->withQueryValue('view','recent')));
	$t->same('active',$viewed->activeTableViewName($request->withQueryValue('view','missing')));
	$resolved=$viewed->requestWithResolvedView($request);
	$t->same('active',$resolved->query('view'));
	$t->same(2,$resolved->query('page'));
	$t->same('all',$viewed->requestWithResolvedView($request->withQueryValue('view','all'))->query('view'));
	$t->same('recent',$viewed->requestWithResolvedView($request->withQueryValue('view','recent'))->query('view'));
	$t->same(9,$viewed->requestWithResolvedView($request->withQuery(['view'=>'active','page'=>9],true))->query('page'));

	$t->same([],$resource->globalSearchResults('',$request));
	$t->same([],$resource->globalSearchable(false)->globalSearchResults('alpha',$request));
	$matches=$resource->globalSearchResults('order',$request,1);
	$t->same(1,count($matches));
	$t->same('Alpha order',$matches[0]['title']);
	$t->same([],$resource->globalSearchResults('missing',$request));
	$t->isFalse($t->nonPublic($resource)->invoke('recordMatchesGlobalSearch',$records[0],['title'],'zzz',));
	$t->isTrue($t->nonPublic($resource)->invoke('recordMatchesGlobalSearch',$records[0],['title'],'alpha',));

	$custom=$resource->globalSearchUsing(static fn(): array=>[
		['title'=>'Projected','record_key'=>'10'],
		['id'=>11,'title'=>'Record title'],
	]);
	$customResults=$custom->globalSearchResults('x',$request,2);
	$t->same(2,count($customResults));
	$t->contains('record=10',$customResults[0]['url']);
	$t->same([],$resource->globalSearchUsing(static fn(): string=>'invalid')->globalSearchResults('x',$request));
	$t->same(1,count($t->nonPublic($resource)->invoke('normalizeGlobalSearchResults',[['title'=>'One'],['title'=>'Two']],1,)));

	foreach([
		new class {
			public function globalSearch(string $query,int $limit): array { return [['id'=>20,'title'=>'Global '.$query]]; }
		},
		new class {
			public function search(string $query,int $limit): array { return [['id'=>21,'title'=>'Search '.$query]]; }
		},
		new class {
			public function getRecords(): array { return [['id'=>22,'title'=>'Get records']]; }
		},
		new class {
			public function get(): array { return [['id'=>23,'title'=>'Get collection']]; }
		},
	] as $source){
		$adapted=Resource::make('orders')->url('/orders')->globalSearchable()->globalSearchColumns(['title'])
			->queryUsing(static fn()=>$source);
		$t->same(1,count($adapted->globalSearchResults('g',$request)));
	}
	$invalidSource=Resource::make('orders')->globalSearchable()->queryUsing(static fn(): int=>1);
	$t->same([],$invalidSource->globalSearchResults('x',$request));
	$throwing=Resource::make('orders')->statusWidgets()->statusTransition('approve','approved',from:'pending')
		->queryUsing(static function(): never { throw new RuntimeException('query failed'); });
	$t->same([],$throwing->dashboardStatusWidgets($request));
})->tag('panel','resource','coverage')->group('framework-coverage');

test('panel resource residual branches cover builders resolvers and malformed definitions',static function(Context $t): void {
	$t->same('alternate',Resource::fromArray([
		'name'=>'alternate','authorize'=>static fn(): bool=>true,'tenant_scoped'=>true,
	])->name());
	$badge=Resource::make('badged')->navigationBadge(static fn(): int=>7);
	$t->same(7,$badge->navigationEntry()['badge']);
	$t->same(null,Resource::make('badged')->navigationBadge(static function(): never {
		throw new RuntimeException('badge failed');
	})->navigationEntry()['badge']);
	$t->isTrue(Resource::make('orders')->policy(new stdClass())->can('view'));

	$t->same(99,Resource::make('orders')->repository(DpPanelResourceRepository::class)->makeQuery()[0]['id']);
	$t->same('orders',Resource::make('orders')->table('orders')->makeQuery()[0]['table']);
	$t->same('scalar',Resource::make('orders')->tenantScoped()->tenantUsing(static fn(): string=>'alpha')
		->queryUsing(static fn(): string=>'scalar')->makeQuery());
	$t->same(['updated'=>true],Resource::make('orders')->mutateUpdateDataUsing(static fn(): array=>['updated'=>true])
		->mutateFormData([],['id'=>1],'update'));
	$fillResource=Resource::make('orders')->mutateFillDataUsing(static fn(): array=>['filled'=>true]);
	$fillState=$fillResource->mutateFormStateBeforeFill(\Dataphyre\Panel\PanelFormState::make([]));
	$t->same(['filled'=>true],$fillState->values());
	$halt=\Dataphyre\Panel\PanelLifecycleResult::halt('Stop');
	$t->same($halt,Resource::make('orders')->beforeSaveUsing(static fn()=>$halt)->runBeforeSave([]));
	$t->same(['before'=>true],Resource::make('orders')->beforeSaveUsing(static fn(): array=>['before'=>true])->runBeforeSave([]));
	$t->instanceOf(\Dataphyre\Panel\PanelLifecycleResult::class,$t->nonPublic(Resource::make('orders'))->invoke('normalizeLifecycleResult',[
		'halted'=>true,
	]));

	$saveTransition=Resource::make('orders')->statusTransition('approve','approved',from:'pending')
		->saveUsing(static fn(array $data): array=>['saved'=>true,'data'=>$data]);
	$t->isTrue($saveTransition->applyTransition('approve',['status'=>'pending'])['saved']);
	$t->isFalse(Resource::make('orders')->statusTransition('approve','approved',from:'pending')
		->applyTransition('approve',['status'=>'pending'])['transitioned']);
	$bulk=Resource::make('orders')->saveUsing(static fn(): array=>['saved'=>true]);
	$t->same(2,$bulk->bulkUpdateRecords(['status'=>'done'],[['id'=>1],['id'=>2]])['updated']);

	$form=\Dataphyre\Panel\ResourceForm::make();
	$table=\Dataphyre\Panel\ResourceTable::make();
	$builders=Resource::make('orders')->bulkForm($form)->form($form)->resourceTable($table);
	$t->same($form,$builders->bulkForm());
	$t->same($form,$builders->form());
	$t->same($table,$builders->resourceTable());
	$request=\Dataphyre\Panel\PanelRequest::fromArray(['resource'=>'orders','operation'=>'index']);
	$t->instanceOf(\Dataphyre\Panel\PanelTableState::class,$builders->tableState($request));
	Resource::make('orders')->infolistEntries([Field::make('')])->infolistState();
	$t->same('',Resource::make('orders')->views([['name'=>'plain']])->activeTableViewName($request));

	$status=Resource::make('orders')->statusTransitions(['pending','approve'=>['to'=>'approved']]);
	$t->isTrue(count($status->statusTransitionsList())>=2);
	$actions=Resource::make('orders')->actions([
		Action::make('edit'),
		\Dataphyre\Panel\ActionGroup::make('more')->actions([Action::make('delete')]),
	]);
	$t->same('edit',$actions->actionByName('edit')?->name());
	$t->same('delete',$actions->actionByName('delete')?->name());
	$relation=\Dataphyre\Panel\RelationManager::make('items');
	$t->same($relation,Resource::make('orders')->relation($relation)->relationManager('items'));

	$throw=static function(): never { throw new RuntimeException('resolver failed'); };
	$broken=Resource::make('orders')->recordKeyUsing($throw)->recordTitleUsing($throw)
		->recordSubtitleUsing($throw)->recordUrlUsing($throw);
	$t->same('',$broken->recordKey(['id'=>1]));
	$t->same('1',$broken->recordTitle(['id'=>1]));
	$t->same('',$broken->recordSubtitle(['id'=>1]));
	$t->contains('resource=orders',$broken->recordUrl(['id'=>1]));
	$t->same('/orders',Resource::make('orders')->url('/orders')->recordUrl([]));
	$t->contains('record=1',Resource::make('orders')->recordUrl(['id'=>1]));

	$noMethods=new class {};
	$t->same([],$t->nonPublic(Resource::make('orders')->queryUsing(static fn()=>$noMethods))->invoke('globalSearchRecords',$request,'x',5,));
	$searchable=Resource::make('orders')->column(\Dataphyre\Panel\Column::make('title')->searchable());
	$t->contains('title',$t->nonPublic($searchable)->invoke('resolvedGlobalSearchColumns'));
	$fallback=Resource::make('orders')->column(\Dataphyre\Panel\Column::make('name'));
	$t->contains('name',$t->nonPublic($fallback)->invoke('resolvedGlobalSearchColumns'));
	$resolved=Resource::make('orders')->label('Order')->globalSearchTitleUsing(static fn(): string=>'Custom title')
		->globalSearchSubtitleUsing(static fn(): string=>'Custom subtitle');
	$projected=$t->nonPublic($resolved)->invoke('globalSearchResultForRecord',['id'=>1]);
	$t->same('Custom title',$projected['title']);
	$t->same('Custom subtitle',$projected['subtitle']);
	$t->same(1,count($t->nonPublic($resolved)->invoke('normalizeGlobalSearchResults',[new stdClass()],1)));
	$t->same('Order',$t->nonPublic(Resource::make('orders')->label('Order'))->invoke('defaultRecordTitle',new stdClass()));
	$t->same('plain',$t->nonPublic(Resource::class)->invoke('displayEntryValue',Field::make('plain'),Field::make('plain')->toArray(),'plain',));
	$t->same('',$t->nonPublic(Resource::class)->invoke('recordKeyDefault',new stdClass()));

	$malformed=Resource::make('orders')->statusTransitions([
		['name'=>'invalid','from'=>['!!!'],'to'=>'???'],
	]);
	$t->same([],$t->nonPublic($malformed)->invoke('statusTableViews'));
	foreach([
		new class { public function getRecords(): array { return [['status'=>'pending']]; } },
		new class { public function get(): array { return [['status'=>'pending']]; } },
		new class { public function get(): string { return 'invalid'; } },
		new stdClass(),
	] as $source){
		$t->nonPublic(Resource::make('orders')->queryUsing(static fn()=>$source))->invoke('dashboardWidgetRecords',$request);
	}
	$t->same([],$t->nonPublic(Resource::make('orders')->queryUsing(static fn(): int=>1))->invoke('dashboardWidgetRecords',$request));
})->tag('panel','resource','coverage')->group('framework-coverage');
