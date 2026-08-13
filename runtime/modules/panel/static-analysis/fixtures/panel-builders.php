<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/*************************************************************************
 * Compile-only fixture for PHPStan, Psalm, and IDE generic inference.
 * This file is intentionally not loaded by the Panel runtime.
 *************************************************************************/

declare(strict_types=1);

namespace Dataphyre\Panel\StaticAnalysisFixture;

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\NavigationCluster;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelMenuItem;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;

final class StaticAnalysisOrder {
	public function __construct(
		public int $id,
		public string $email,
		public string $status,
	){}
}

final class StaticAnalysisLineItem {
	public function __construct(public int $id, public string $sku){}
}

/**
 * @phpstan-type StaticAnalysisOrderState array{email:string,status:string}
 * @psalm-type StaticAnalysisOrderState = array{email:string,status:string}
 * @phpstan-type StaticAnalysisLineState array{sku:string}
 * @psalm-type StaticAnalysisLineState = array{sku:string}
 */
final class PanelBuilderInferenceFixture {
	public static function compileOnly(): void {
		/** @var Field<StaticAnalysisOrder,string,StaticAnalysisOrderState> $email */
		$email=Field::make('email');
		/**
		 * @param StaticAnalysisOrderState $state
		 * @param Field<StaticAnalysisOrder,string,StaticAnalysisOrderState>|null $field
		 * @return bool|string|list<string>|null
		 */
		$emailValidator=static function(
			string $value,
			array $state=['email'=>'', 'status'=>''],
			?StaticAnalysisOrder $record=null,
			?PanelRequest $request=null,
			?Field $field=null,
		): string|bool|array|null {
			if($state['status']==='valid'){ return true; }
			if($state['status']==='errors'){ return ['Invalid email.']; }
			return str_contains($value, '@') ? null : 'Invalid email.';
		};
		$email=$email
			->hydrateUsing(static fn(string $value, ?StaticAnalysisOrder $record=null): string=>trim($value))
			->dehydrateUsing(static fn(string $value, ?StaticAnalysisOrder $record=null): string=>strtolower($value))
			->validateUsing($emailValidator);

		/** @var FormSection<StaticAnalysisOrder,StaticAnalysisOrderState> $profile */
		$profile=FormSection::make('Profile');
		/** @var ResourceForm<StaticAnalysisOrder,StaticAnalysisOrderState> $form */
		$form=ResourceForm::make();
		$form=$form->section($profile, [$email]);
		/** @var Schema<StaticAnalysisOrder,StaticAnalysisOrderState> $schema */
		$schema=$form->schema();

		/** @var Column<StaticAnalysisOrder,string> $emailColumn */
		$emailColumn=Column::make('email');
		$emailColumn=$emailColumn
			->valueUsing(static fn(StaticAnalysisOrder $record): string=>$record->email)
			->format(static fn(string $value, ?StaticAnalysisOrder $record=null): string=>strtolower($value));
		/** @var TableFilter<StaticAnalysisOrder,string,StaticAnalysisOrderState> $statusFilter */
		$statusFilter=TableFilter::make('status');
		$statusFilter=$statusFilter->where(static fn(StaticAnalysisOrder $record, ?string $value): bool=>$record->status===$value);
		/** @var TableGroup<StaticAnalysisOrder,string> $statusGroup */
		$statusGroup=TableGroup::make('status');
		$statusGroup=$statusGroup->stateUsing(static fn(StaticAnalysisOrder $record): string=>$record->status);
		/** @var TableView<StaticAnalysisOrder,StaticAnalysisOrderState> $activeView */
		$activeView=TableView::make('active');
		$activeView=$activeView->where(static fn(StaticAnalysisOrder $record): bool=>$record->status==='active');
		/** @var TableSummary<StaticAnalysisOrder,int> $total */
		$total=TableSummary::make('total');
		$total=$total->valueUsing(
			/** @param list<StaticAnalysisOrder> $records */
			static fn(array $records): int=>count($records),
		);
		/** @var ResourceTable<StaticAnalysisOrder,StaticAnalysisOrderState> $table */
		$table=ResourceTable::make();
		$table=$table->column($emailColumn)->filter($statusFilter)->group($statusGroup)->view($activeView)->summary($total);
		/** @var PageTable<StaticAnalysisOrder,StaticAnalysisOrderState> $pageTable */
		$pageTable=PageTable::make('orders');
		$pageTable=$pageTable->column($emailColumn)->recordsUsing(
			/** @return iterable<StaticAnalysisOrder> */
			static fn(): iterable=>[new StaticAnalysisOrder(1, 'buyer@example.test', 'active')],
		);

		/** @var InfolistEntry<StaticAnalysisOrder,string,StaticAnalysisOrderState> $emailEntry */
		$emailEntry=InfolistEntry::make('email');
		$emailEntry=$emailEntry->displayUsing(static fn(string $value): string=>strtolower($value));
		/** @var Infolist<StaticAnalysisOrder,StaticAnalysisOrderState> $infolist */
		$infolist=Infolist::make();
		$infolist=$infolist->entry($emailEntry);

		/** @var RelationManager<StaticAnalysisOrder,StaticAnalysisLineItem,StaticAnalysisLineState> $items */
		$items=RelationManager::make('items');
		$items=$items
			->queryUsing(
				/** @return iterable<StaticAnalysisLineItem> */
				static fn(StaticAnalysisOrder $order): iterable=>[new StaticAnalysisLineItem(1, 'SKU-1')],
			);

		/** @var Widget<StaticAnalysisOrder,int,StaticAnalysisOrderState> $widget */
		$widget=Widget::make('active_orders');
		$widget=$widget->value(static fn(): int=>1);

		/** @var PanelSearchProvider<array{id:int,title:string},string|null> $search */
		$search=PanelSearchProvider::make('orders');
		$search=$search->searchUsing(
			/** @return iterable<array{id:int,title:string}> */
			static fn(string $query, PanelRequest $request): iterable=>[
				['id'=>1, 'title'=>$query],
			],
		);

		/** @var PanelPage<string,StaticAnalysisOrder,StaticAnalysisOrderState> $page */
		$page=PanelPage::make('orders_home');
		$page=$page
			->content(static fn(PanelRequest $request): string=>'Orders')
			->table($pageTable)
			->widget($widget);
		$command=PanelCommand::make('open_orders')
			->url(static fn(?PanelRequest $request): string=>'/orders')
			->visibleUsing(static fn(?PanelRequest $request): bool=>true);
		$navigationItem=NavigationItem::make('Orders')
			->badgeUsing(static fn(?PanelRequest $request): int=>1)
			->visibleUsing(static fn(?PanelRequest $request): bool=>true);
		$navigationCluster=NavigationCluster::make('Operations')
			->badge(static fn(?PanelRequest $request): int=>1);
		$menuItem=PanelMenuItem::make('orders')
			->url(static fn(?PanelRequest $request): string=>'/orders')
			->visibleUsing(static fn(?PanelRequest $request): bool=>true);
		$tenant=PanelTenant::make('ca')
			->url(static fn(?PanelRequest $request): string=>'/ca/orders')
			->badge(static fn(?PanelRequest $request): int=>1)
			->current(static fn(?PanelRequest $request): bool=>true)
			->visibleUsing(static fn(?PanelRequest $request): bool=>true);

		/** @var Resource<StaticAnalysisOrder,StaticAnalysisOrderState> $resource */
		$resource=Resource::make('orders');
		$resource=$resource->model(StaticAnalysisOrder::class);
		/** @var Resource<StaticAnalysisOrder,StaticAnalysisOrderState> $resource */
		$resource=$resource->form($form);
		/** @var Resource<StaticAnalysisOrder,StaticAnalysisOrderState> $resource */
		$resource=$resource->schema($schema);
		/** @var Resource<StaticAnalysisOrder,StaticAnalysisOrderState> $resource */
		$resource=$resource->resourceTable($table);
		/** @var Resource<StaticAnalysisOrder,StaticAnalysisOrderState> $resource */
		$resource=$resource->infolist($infolist);
		$resource=$resource
			->relation($items)
			->statusBoardColumns([
				'new'=>['status'=>'new', 'label'=>'New', 'tone'=>'info'],
				'review'=>['status'=>'manual_review', 'label'=>'Manual review', 'tone'=>'warning'],
			])
			->statusBoardColumn('closed', 'closed', 'Closed', 'success', ['brick'=>'span-2'])
			->recordTitleUsing(static fn(StaticAnalysisOrder $order): string=>$order->email);
		/** @var array<string,array{name:string,status:string,label:string,tone:string,meta:array<string,mixed>}> $statusBoardColumns */
		$statusBoardColumns=$resource->statusBoardColumnsList();
		$hasStatusBoard=$resource->hasStatusBoard();

		unset(
			$pageTable,
			$widget,
			$search,
			$page,
			$command,
			$navigationItem,
			$navigationCluster,
			$menuItem,
			$tenant,
			$statusBoardColumns,
			$hasStatusBoard,
			$resource,
		);
	}
}
