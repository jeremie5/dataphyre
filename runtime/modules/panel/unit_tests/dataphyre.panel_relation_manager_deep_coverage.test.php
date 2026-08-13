<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

if(!class_exists('Dataphyre\Database\DB',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\Database;
		final class RelationCoverageQuery {
			public array $where=[];
			public function __construct(private array $records) {}
			public function where(string $column,string $operator,mixed $value): self { $this->where=[$column,$operator,$value]; return $this; }
			public function get(): array { return $this->records; }
		}
		final class DB {
			public static array $records=[];
			public static ?RelationCoverageQuery $last=null;
			public static function table(string $table): RelationCoverageQuery { return self::$last=new RelationCoverageQuery(self::$records); }
		}');
}
if(!class_exists('Dataphyre\Permission\Permission',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\Permission;
		final class Permission {
			public static bool $allow=true;
			public static array $calls=[];
			public static function any(array $permissions,mixed $user=null,array $context=[]): bool { self::$calls[]=[$permissions,$user,$context]; return self::$allow; }
		}');
}

final class DpRelationPaginator {
	public function __construct(private mixed $records) {}
	public function items(): mixed { return $this->records; }
}

final class DpRelationGetterRecord {
	public function __construct(public int $id,private string $parentId) {}
	public function getParentId(): string { return $this->parentId; }
}

test('panel relation manager imports manifests and covers fluent configuration',static function(Context $t): void {
	$handler=static fn(mixed ...$arguments): array=>['arguments'=>$arguments];
	$relation=RelationManager::fromArray([
		'name'=>'Order Items',
		'label'=>' Line items ',
		'description'=>' Related products ',
		'parent_title'=>static fn(): string=>'Parent title',
		'badge'=>7,
		'empty_state'=>' Nothing here ',
		'empty_description'=>' Try later ',
		'related_resource'=>'Products',
		'table'=>' product_links ',
		'foreign_key'=>'ORDER-ID',
		'local_key'=>'ID',
		'columns'=>[
			Column::make('sku')->label('SKU'),
			['name'=>'amount','type'=>'number'],
			'name',
		],
		'views'=>[TableView::make('all'),['name'=>'active'],'archived'],
		'filters'=>[TableFilter::make('status'),['name'=>'kind'],'tenant'],
		'summaries'=>[TableSummary::make('count'),['name'=>'amount','type'=>'sum','column'=>'amount'],'total'],
		'facts'=>[TableSummary::make('rows'),['name'=>'total','type'=>'sum','column'=>'amount'],'other'],
		'per_page'=>15,
		'per_page_options'=>[15,30,60],
		'default_sort'=>['column'=>'sku','direction'=>'desc'],
		'read_only'=>true,
		'create_enabled'=>false,
		'attach_enabled'=>false,
		'detach_enabled'=>false,
		'associate_enabled'=>true,
		'dissociate_enabled'=>true,
		'reorder_enabled'=>true,
		'order_column'=>'SORT-ORDER',
		'attach_label'=>' Link ',
		'detach_label'=>' Unlink ',
		'associate_label'=>' Connect ',
		'dissociate_label'=>' Disconnect ',
		'reorder_label'=>' Sort ',
		'pivot_fields'=>[Field::make('quantity')->integer(),['name'=>'note','type'=>'text'],'role',''],
		'attachable_records'=>$handler,
		'attach'=>$handler,
		'detach'=>$handler,
		'associate'=>$handler,
		'dissociate'=>$handler,
		'reorder'=>$handler,
		'update_pivot'=>$handler,
		'meta'=>['source'=>'manifest'],
	]);
	$array=$relation->toArray();
	$t->same('order_items',$relation->name());
	$t->same('Line items',$relation->label());
	$t->same('products',$relation->relatedResourceName());
	$t->same('order-id',$relation->foreignKeyName());
	$t->same('id',$relation->localKeyName());
	$t->isTrue($relation->isReadOnly());
	$t->same('Link',$relation->attachLabelText());
	$t->same('Unlink',$relation->detachLabelText());
	$t->same('Connect',$relation->associateLabelText());
	$t->same('Disconnect',$relation->dissociateLabelText());
	$t->same('Sort',$relation->reorderLabelText());
	$t->same(3,count($relation->pivotFieldDefinitions()));
	$t->same('manifest',$array['meta']['source']);
	$t->same('sort-order',$array['order_column']);
	$t->isTrue($array['description']==='Related products');
	$t->isTrue($array['parent_title_dynamic']);
	$t->isTrue($array['queryable']);
	$t->isTrue($array['authorizes']===false);
	$t->same('relation_manifest',$relation->manifest(PanelRequest::fromArray(['operation'=>'index']),['resource'=>'orders'])['type']);

	$base=RelationManager::make('line-items');
	$t->same('Line Items',$base->label());
	$t->same('',RelationManager::make('')->label());
	$t->same('',$t->nonPublic(RelationManager::class)->invoke('humanize',''));
	$t->same('Line Items',$t->nonPublic(RelationManager::class)->invoke('humanize','line.items'));
	$blank=$base->label('')
		->description(' ')
		->parentTitle(' ')
		->badge(null)
		->emptyState(' ',' ')
		->relatedResource(' ')
		->table(' ')
		->foreignKey(' ')
		->localKey(' ')
		->attachLabel(' ')
		->detachLabel(' ')
		->associateLabel(' ')
		->dissociateLabel(' ')
		->reorderLabel(' ')
		->reorderable(true,' ')
		->meta(['one'=>1])->meta(['one'=>2,'two'=>2]);
	$blankArray=$blank->toArray();
	$t->same('',$blank->label());
	$t->same('No related records to show.',$blankArray['empty_state']);
	$t->same('Attach record',$blank->attachLabelText());
	$t->same('Detach',$blank->detachLabelText());
	$t->same('Associate record',$blank->associateLabelText());
	$t->same('Dissociate',$blank->dissociateLabelText());
	$t->same('Reorder',$blank->reorderLabelText());
	$t->same(2,$blankArray['meta']['one']);

	$configured=$base
		->description(static fn(): string=>'Dynamic description')
		->parentTitleUsing(static fn(): string=>'Dynamic parent')
		->badgeUsing(static fn(): string=>'Dynamic badge')
		->queryUsing(static fn(): array=>[])
		->authorize(static fn(): bool=>true)
		->readOnly(false)
		->create()->attach()->detach()->associate()->dissociate()
		->withoutCreate()->withoutAttach()->withoutDetach()->withoutAssociate()->withoutDissociate()
		->reorderable(false)
		->attachableRecordsUsing($handler)
		->attachUsing($handler)->detachUsing($handler)->associateUsing($handler)->dissociateUsing($handler)
		->reorderUsing($handler,'position')->updatePivotUsing($handler)
		->pivotFields([Field::make('first'),['name'=>'second'],'third',''])
		->pivotField(Field::make('fourth'))
		->pivotField(['name'=>'fifth','type'=>'text'])
		->pivotField('sixth','integer')
		->pivotField('')
		->columns([Column::make('id')])->column(['name'=>'name'])->column('amount','number')
		->perPage(20)->perPageOptions([20,40])->defaultSort('id','desc')
		->views([TableView::make('all')])->view(['name'=>'open'])->view('closed')
		->filters([TableFilter::make('status')])->filter(['name'=>'type'])->filter('owner','select')
		->summaries([TableSummary::make('count')])->summary(['name'=>'sum','type'=>'sum'])->summary('avg','avg')
		->facts([TableSummary::make('rows'),['name'=>'sum','type'=>'sum'],'maximum'])
		->fact(TableSummary::make('minimum'))->fact(['name'=>'average','type'=>'avg'])->fact('latest','max');
	$t->instanceOf(\Dataphyre\Panel\ResourceTable::class,$configured->resourceTable());
	$t->same(6,count($configured->pivotFieldDefinitions()));
	$t->same(6,count($configured->toArray()['facts']));
	$t->isTrue($configured->toArray()['authorizes']);
})->tag('panel','relation-manager','coverage')->group('framework-coverage');

test('panel relation manager resolves presentation authorization operations and handlers',static function(Context $t): void {
	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'index','page'=>2,'per_page'=>10]);
	$resource=Resource::make('orders');
	$records=[['id'=>1,'amount'=>2],['id'=>2,'amount'=>3]];
	$t->same(null,RelationManager::make('items')->resolveDescription());
	$t->same('Static',RelationManager::make('items')->description(' Static ')->resolveDescription());
	$t->same('1',RelationManager::make('items')->description(static fn(): bool=>true)->resolveDescription([], $request, $resource, $records));
	$t->same('["parent"]',RelationManager::make('items')->parentTitleUsing(static fn(): array=>['parent'])->resolveParentTitle([], $request, $resource, $records));
	$t->same(null,RelationManager::make('items')->badge(null)->resolveBadge());
	$t->same('12.5',RelationManager::make('items')->badge(12.5)->resolveBadge());
	$t->same('{}',RelationManager::make('items')->badgeUsing(static fn(): object=>new stdClass())->resolveBadge($records,[], $request, $resource));
	$t->same('0',$t->nonPublic(RelationManager::make('items'))->invoke('normalizeNullableString',false));
	$t->same(null,$t->nonPublic(RelationManager::make('items'))->invoke('normalizeNullableString',null));
	$t->same('{"a":1}',$t->nonPublic(RelationManager::make('items'))->invoke('normalizeNullableString',['a'=>1]));
	$stream=fopen('php://memory','r');
	$t->same(null,$t->nonPublic(RelationManager::make('items'))->invoke('normalizeNullableString',$stream));
	fclose($stream);

	$facts=RelationManager::make('items')->facts([
		TableSummary::make('count'),
		TableSummary::make('amount','sum')->column('amount'),
	]);
	$t->same(2,count($facts->resolveFacts($records,$resource,$request,['id'=>9])));
	$t->same('No related records match this view.',$facts->resolveEmptyState($request,true)['heading']);
	$t->contains('related activity',$facts->resolveEmptyState($request)['description']);
	$t->same('Custom hint',$facts->emptyState('Custom heading','Custom hint')->resolveEmptyState($request)['description']);

	$t->isTrue(RelationManager::make('items')->can('view'));
	$t->isFalse(RelationManager::make('items')->authorize(static fn(): bool=>false)->can('view',[],null,$resource));
	$t->isTrue(RelationManager::make('items')->authorize(static fn(string $ability): bool=>$ability==='view')->can('view',[],null,$resource));
	\Dataphyre\Permission\Permission::$allow=false;
	$denied=PanelContext::run(['permission'=>true],static fn(): bool=>RelationManager::make('items')->can('list',['id'=>9],['id'=>3],Resource::make('orders')));
	$t->isFalse($denied);
	\Dataphyre\Permission\Permission::$allow=true;
	$allowed=PanelContext::run(['permission'=>true],static fn(): bool=>RelationManager::make('items')->can('attach',['id'=>9],['id'=>3],Resource::make('orders')));
	$t->isTrue($allowed);
	$t->same('view',$t->nonPublic(RelationManager::class)->invoke('permissionRelationOperation','index'));
	$t->same('view',$t->nonPublic(RelationManager::class)->invoke('permissionRelationOperation','list'));
	$t->same('update',$t->nonPublic(RelationManager::class)->invoke('permissionRelationOperation','attach'));

	$handler=static fn(mixed ...$arguments): array=>['count'=>count($arguments),'values'=>$arguments];
	$enabled=RelationManager::make('items')
		->relatedResource('products')->foreignKey('order_id')->localKey('id')
		->attachUsing($handler)->detachUsing($handler)->associateUsing($handler)->dissociateUsing($handler)
		->reorderUsing($handler,'position')->pivotField('note')->updatePivotUsing($handler);
	$t->isTrue($enabled->canCreate());
	$t->isTrue($enabled->canAttach());
	$t->isTrue($enabled->canDetach());
	$t->isTrue($enabled->canAssociate());
	$t->isTrue($enabled->canDissociate());
	$t->isTrue($enabled->canReorder());
	$t->isTrue($enabled->canUpdatePivot());
	$t->same(5,$enabled->attachRecord($resource,[],4,$request)['count']);
	$t->same(5,$enabled->detachRecord($resource,[],4,$request)['count']);
	$t->same(5,$enabled->associateRecord($resource,[],4,$request)['count']);
	$t->same(5,$enabled->dissociateRecord($resource,[],4,$request)['count']);
	$t->same([4,2],$enabled->reorderRecords($resource,[],[2=>4,9=>2],$request)['values'][1]);
	$t->same(6,$enabled->updatePivotRecord($resource,[],4,['note'=>'x'],$request)['count']);

	$disabled=RelationManager::make('items');
	$t->isFalse($disabled->canCreate());
	$t->isFalse($disabled->canAttach());
	$t->isFalse($disabled->canDetach());
	$t->isFalse($disabled->canAssociate());
	$t->isFalse($disabled->canDissociate());
	$t->isFalse($disabled->canReorder());
	$t->isFalse($disabled->canUpdatePivot());
	$t->isFalse($disabled->attachRecord($resource,[],1,$request)['attached']);
	$t->isFalse($disabled->detachRecord($resource,[],1,$request)['detached']);
	$t->isFalse($disabled->associateRecord($resource,[],1,$request)['associated']);
	$t->isFalse($disabled->dissociateRecord($resource,[],1,$request)['dissociated']);
	$t->isFalse($disabled->reorderRecords($resource,[],[1],$request)['reordered']);
	$t->isFalse($disabled->updatePivotRecord($resource,[],1,[],$request)['updated']);
	$t->isFalse($enabled->readOnly()->canCreate());
	$t->isFalse($enabled->readOnly()->canAttach());
	$t->isFalse($enabled->readOnly()->canDetach());
	$t->isFalse($enabled->readOnly()->canAssociate());
	$t->isFalse($enabled->readOnly()->canDissociate());
	$t->isFalse($enabled->readOnly()->canReorder());
	$t->isFalse($enabled->readOnly()->canUpdatePivot());

	$readOnlyOps=$enabled->readOnly()->toArray()['operations'];
	$t->same('Relation is read-only.',$readOnlyOps['attach']['disabled_reason']);
	$notConfigured=$t->nonPublic($disabled)->invoke('operationDefinition','attach',false,false,false,'Attach','Attach record');
	$t->same('Operation is not enabled for this relation.',$notConfigured['disabled_reason']);
	$noHandler=$t->nonPublic($disabled)->invoke('operationDefinition','attach',false,true,false,'Attach','Attach record');
	$t->same('Operation handler is not registered.',$noHandler['disabled_reason']);
})->tag('panel','relation-manager','coverage')->group('framework-coverage');

test('panel relation manager resolves array object database and resource queries',static function(Context $t): void {
	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'index','page'=>3,'per_page'=>7]);
	$resource=Resource::make('orders');
	$t->same([],RelationManager::make('items')->records($resource,['id'=>1],$request));

	$records=[
		['id'=>1,'parent_id'=>'10'],
		['id'=>2,'parent_id'=>'20'],
		new DpRelationGetterRecord(3,'10'),
	];
	$arrayRelation=RelationManager::make('items')->foreignKey('parent_id')->localKey('id')->queryUsing(static fn(): array=>$records);
	$t->same(2,count($arrayRelation->records($resource,['id'=>10],$request)));
	$t->same(3,count(RelationManager::make('items')->queryUsing(static fn(): array=>$records)->records($resource,['id'=>10],$request)));
	$t->same(3,count($arrayRelation->records($resource,['id'=>new stdClass()],$request)));
	$t->same('10',$t->nonPublic(RelationManager::class)->invoke('recordValue',$records[0],'parent_id','missing'));
	$t->same(3,$t->nonPublic(RelationManager::class)->invoke('recordValue',$records[2],'id','missing'));
	$t->same('10',$t->nonPublic(RelationManager::class)->invoke('recordValue',$records[2],'parent_id','missing'));
	$t->same('fallback',$t->nonPublic(RelationManager::class)->invoke('recordValue',new stdClass(),'missing','fallback'));
	$t->same('fallback',$t->nonPublic(RelationManager::class)->invoke('recordValue','scalar','missing','fallback'));

	$paginateRecords=new class {
		public array $args=[];
		public function paginateRecords(int $page,int $perPage): DpRelationPaginator { $this->args=[$page,$perPage]; return new DpRelationPaginator([['id'=>1]]); }
	};
	$t->same(1,count(RelationManager::make('items')->queryUsing(static fn()=>$paginateRecords)->records($resource,[],$request)));
	$t->same([1,25],$paginateRecords->args);
	$paginate=new class { public function paginate(int $page,int $perPage): array { return [['id'=>$page,'per_page'=>$perPage]]; } };
	$t->same(25,RelationManager::make('items')->queryUsing(static fn()=>$paginate)->records($resource,[],$request)[0]['per_page']);
	$getRecords=new class { public function getRecords(): array { return [['id'=>1],['id'=>2]]; } };
	$t->same(2,count(RelationManager::make('items')->queryUsing(static fn()=>$getRecords)->records($resource,[],$request,true)));
	$get=new class { public function get(): DpRelationPaginator { return new DpRelationPaginator([['id'=>8]]); } };
	$t->same(8,RelationManager::make('items')->queryUsing(static fn()=>$get)->records($resource,[],$request,true)[0]['id']);
	$invalid=new class { public function getRecords(): string { return 'invalid'; } };
	$t->same([],RelationManager::make('items')->queryUsing(static fn()=>$invalid)->records($resource,[],$request,true));
	$t->same([],RelationManager::make('items')->queryUsing(static fn()=>new stdClass())->records($resource,[],$request));

	\Dataphyre\Database\DB::$records=[['id'=>1,'order_id'=>55],['id'=>2,'order_id'=>55]];
	$dbRelation=RelationManager::make('items')->table('links')->foreignKey('order_id')->localKey('id');
	$t->same(2,count($dbRelation->records($resource,['id'=>55],$request,true)));
	$t->same(['order_id','=',55],\Dataphyre\Database\DB::$last?->where);
	$t->same(2,count(RelationManager::make('items')->table('links')->records($resource,[],$request,true)));

	$related=Resource::make('relation_products_array')->queryUsing(static fn(): array=>[
		['id'=>1,'name'=>'Attached'],
		['id'=>2,'name'=>'Available'],
		['name'=>'Unkeyed'],
	]);
	Panel::register($related);
	$relation=RelationManager::make('items')->relatedResource('relation_products_array')
		->queryUsing(static fn(): array=>[['id'=>1],['name'=>'No key']]);
	$attachable=$relation->attachableRecords($resource,['id'=>9],$request);
	$t->same(2,count($attachable));
	$t->same(2,$attachable[0]['id']);
	$t->same([],RelationManager::make('items')->attachableRecords($resource,[],$request));
	$t->same([],RelationManager::make('items')->relatedResource('missing_resource')->attachableRecords($resource,[],$request));

	$custom=RelationManager::make('items')->attachableRecordsUsing(static fn(): array=>[4=>['id'=>4]]);
	$t->same([['id'=>4]],$custom->attachableRecords($resource,[],$request));
	$t->same([],RelationManager::make('items')->attachableRecordsUsing(static fn(): string=>'invalid')->attachableRecords($resource,[],$request));

	$relatedObject=Resource::make('relation_products_object')->queryUsing(static fn()=>new class {
		public function getRecords(): DpRelationPaginator { return new DpRelationPaginator([['id'=>3],['id'=>4]]); }
	});
	Panel::register($relatedObject);
	$t->same(2,count(RelationManager::make('items')->relatedResource('relation_products_object')->records($resource,[],$request,true)));
	$t->same(2,count(RelationManager::make('items')->relatedResource('relation_products_object')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request)));
	$relatedGet=Resource::make('relation_products_get')->queryUsing(static fn()=>new class {
		public function get(): array { return [['id'=>5]]; }
	});
	Panel::register($relatedGet);
	$t->same(1,count(RelationManager::make('items')->relatedResource('relation_products_get')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request)));
	$relatedPaginateRecords=Resource::make('relation_products_paginate_records')->queryUsing(static fn()=>new class {
		public function paginateRecords(int $page,int $perPage): array { return [['id'=>$page+$perPage]]; }
	});
	Panel::register($relatedPaginateRecords);
	$t->same(1,count(RelationManager::make('items')->relatedResource('relation_products_paginate_records')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request)));
	$relatedPaginate=Resource::make('relation_products_paginate')->queryUsing(static fn()=>new class {
		public function paginate(int $page,int $perPage): DpRelationPaginator { return new DpRelationPaginator([['id'=>$page+$perPage]]); }
	});
	Panel::register($relatedPaginate);
	$t->same(1,count(RelationManager::make('items')->relatedResource('relation_products_paginate')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request)));
	$relatedInvalid=Resource::make('relation_products_invalid')->queryUsing(static fn()=>new class { public function getRecords(): string { return 'invalid'; } });
	Panel::register($relatedInvalid);
	$t->same([],RelationManager::make('items')->relatedResource('relation_products_invalid')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request));
	$relatedNoMethods=Resource::make('relation_products_none')->queryUsing(static fn()=>new stdClass());
	Panel::register($relatedNoMethods);
	$t->same([],RelationManager::make('items')->relatedResource('relation_products_none')->queryUsing(static fn(): array=>[])->attachableRecords($resource,[],$request));
})->tag('panel','relation-manager','coverage')->group('framework-coverage');
