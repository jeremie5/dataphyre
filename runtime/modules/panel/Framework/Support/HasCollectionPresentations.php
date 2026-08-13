<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Adds immutable collection-presentation builders to Panel primitives. */
trait HasCollectionPresentations {
	/** @var array<string,array<string,mixed>> */
	private array $collectionPresentations=[];

	public function collectionPresentation(string $collection, array|string $presentation): self {
		$collection=Resource::normalizeName($collection);
		if($collection===''){
			return $this;
		}
		$clone=clone $this;
		$clone->collectionPresentations[$collection]=PanelCollectionPresentation::normalize($presentation, self::collectionDefaultDisplay($collection));
		return $clone;
	}

	/** @param array<string,array<string,mixed>|string> $presentations */
	public function collectionPresentations(array $presentations): self {
		$clone=$this;
		foreach($presentations as $collection=>$presentation){
			if(is_array($presentation) || is_string($presentation)){
				$clone=$clone->collectionPresentation((string)$collection, $presentation);
			}
		}
		return $clone;
	}

	/** @return array<string,mixed> */
	public function presentationFor(string $collection, string $defaultDisplay='inline'): array {
		$collection=Resource::normalizeName($collection);
		return PanelCollectionPresentation::normalize($this->collectionPresentations[$collection] ?? null, $defaultDisplay);
	}

	/** @return array<string,array<string,mixed>> */
	public function presentations(): array {
		return $this->collectionPresentations;
	}

	/** @param array<string,mixed>|PanelCollectionItemPresentation $presentation */
	public function collectionItemPresentation(string $collection, string|int $item, array|PanelCollectionItemPresentation $presentation): self {
		$collection=Resource::normalizeName($collection);
		$key=PanelCollectionPresentation::itemKey($item);
		if($collection==='' || $key===''){
			return $this;
		}
		$itemDefinition=PanelCollectionItemPresentation::normalize($presentation);
		if($itemDefinition===[]){
			return $this;
		}
		$definition=PanelCollectionPresentation::normalize($this->collectionPresentations[$collection] ?? null, self::collectionDefaultDisplay($collection));
		$definition['items'] ??=[];
		$definition['items'][$key]=$itemDefinition;
		return $this->collectionPresentation($collection, $definition);
	}

	/** @param array<string|int,array<string,mixed>|PanelCollectionItemPresentation> $presentations */
	public function collectionItemPresentations(string $collection, array $presentations): self {
		$clone=$this;
		foreach($presentations as $item=>$presentation){
			if(is_array($presentation) || $presentation instanceof PanelCollectionItemPresentation){
				$clone=$clone->collectionItemPresentation($collection, is_int($item) ? $item : (string)$item, $presentation);
			}
		}
		return $clone;
	}

	public function collectionFinalRow(string $collection, string $policy='fill'): self {
		$collection=Resource::normalizeName($collection);
		if($collection===''){
			return $this;
		}
		$definition=PanelCollectionPresentation::normalize($this->collectionPresentations[$collection] ?? null, self::collectionDefaultDisplay($collection));
		$definition['final_row']=$policy;
		return $this->collectionPresentation($collection, $definition);
	}

	/** @param array<string,mixed> $meta
	 *  @return array<string,mixed>
	 */
	public function itemPresentationFor(string $collection, string|int|null $item=null, ?int $index=null, array $meta=[]): array {
		$collection=Resource::normalizeName($collection);
		return PanelCollectionPresentation::itemPresentation($this->collectionPresentations[$collection] ?? null, $item, $index, $meta);
	}

	/** @param array<string,mixed> $options */
	public function rowMasonry(string $collection, array $options=[]): self {
		return $this->collectionPresentation($collection, array_replace([
			'display'=>'masonry',
			'masonry'=>'rows',
			'fit'=>'fill',
		], $options));
	}

	public function brickCollection(string $collection, bool $enabled=true): self {
		$collection=Resource::normalizeName($collection);
		return $collection==='' ? $this : $this->collectionPresentation($collection, $enabled ? 'brick' : self::collectionDefaultDisplay($collection));
	}

	public function viewsPresentation(array|string $presentation): self { return $this->collectionPresentation('views', $presentation); }
	public function viewsDisplay(string $display): self { return $this->viewsPresentation($display); }
	public function brickViews(bool $enabled=true): self { return $this->viewsDisplay($enabled ? 'brick' : 'segmented'); }
	public function masonryViews(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('views', $options) : $this->viewsDisplay('segmented'); }
	public function groupsPresentation(array|string $presentation): self { return $this->collectionPresentation('groups', $presentation); }
	public function groupsDisplay(string $display): self { return $this->groupsPresentation($display); }
	public function brickGroups(bool $enabled=true): self { return $this->groupsDisplay($enabled ? 'brick' : 'segmented'); }
	public function masonryGroups(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('groups', $options) : $this->groupsDisplay('segmented'); }
	public function summariesPresentation(array|string $presentation): self { return $this->collectionPresentation('summaries', $presentation); }
	public function summariesDisplay(string $display): self { return $this->summariesPresentation($display); }
	public function brickSummaries(bool $enabled=true): self { return $this->summariesDisplay($enabled ? 'brick' : 'grid'); }
	public function masonrySummaries(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('summaries', $options) : $this->summariesDisplay('grid'); }
	public function filtersPresentation(array|string $presentation): self { return $this->collectionPresentation('filters', $presentation); }
	public function filtersDisplay(string $display): self { return $this->filtersPresentation($display); }
	public function brickFilters(bool $enabled=true): self { return $this->filtersDisplay($enabled ? 'brick' : 'grid'); }
	public function masonryFilters(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('filters', $options) : $this->filtersDisplay('grid'); }
	public function actionsPresentation(array|string $presentation): self { return $this->collectionPresentation('actions', $presentation); }
	public function actionsDisplay(string $display): self { return $this->actionsPresentation($display); }
	public function brickActions(bool $enabled=true): self { return $this->actionsDisplay($enabled ? 'brick' : 'inline'); }
	public function masonryActions(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('actions', $options) : $this->actionsDisplay('inline'); }
	public function tabsPresentation(array|string $presentation): self { return $this->collectionPresentation('tabs', $presentation); }
	public function tabsDisplay(string $display): self { return $this->tabsPresentation($display); }
	public function brickTabs(bool $enabled=true): self { return $this->tabsDisplay($enabled ? 'brick' : 'segmented'); }
	public function masonryTabs(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('tabs', $options) : $this->tabsDisplay('segmented'); }
	public function stepsPresentation(array|string $presentation): self { return $this->collectionPresentation('steps', $presentation); }
	public function stepsDisplay(string $display): self { return $this->stepsPresentation($display); }
	public function brickSteps(bool $enabled=true): self { return $this->stepsDisplay($enabled ? 'brick' : 'segmented'); }
	public function masonrySteps(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('steps', $options) : $this->stepsDisplay('segmented'); }
	public function optionsPresentation(array|string $presentation): self { return $this->collectionPresentation('options', $presentation); }
	public function optionsDisplay(string $display): self { return $this->optionsPresentation($display); }
	public function brickOptions(bool $enabled=true): self { return $this->optionsDisplay($enabled ? 'brick' : 'stack'); }
	public function masonryOptions(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('options', $options) : $this->optionsDisplay('stack'); }

	public function widgetsPresentation(array|string $presentation): self { return $this->collectionPresentation('widgets', $presentation); }
	public function widgetsDisplay(string $display): self { return $this->widgetsPresentation($display); }
	public function brickWidgets(bool $enabled=true): self { return $this->widgetsDisplay($enabled ? 'brick' : 'grid'); }
	public function masonryWidgets(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('widgets', $options) : $this->widgetsDisplay('grid'); }
	public function toolbarPresentation(array|string $presentation): self { return $this->collectionPresentation('toolbar', $presentation); }
	public function toolbarDisplay(string $display): self { return $this->toolbarPresentation($display); }
	public function brickToolbar(bool $enabled=true): self { return $this->toolbarDisplay($enabled ? 'brick' : 'inline'); }
	public function masonryToolbar(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('toolbar', $options) : $this->toolbarDisplay('inline'); }
	public function sectionsPresentation(array|string $presentation): self { return $this->collectionPresentation('sections', $presentation); }
	public function sectionsDisplay(string $display): self { return $this->sectionsPresentation($display); }
	public function brickSections(bool $enabled=true): self { return $this->brickCollection('sections', $enabled); }
	public function masonrySections(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('sections', $options) : $this->sectionsDisplay('stack'); }
	public function fieldsPresentation(array|string $presentation): self { return $this->collectionPresentation('fields', $presentation); }
	public function fieldsDisplay(string $display): self { return $this->fieldsPresentation($display); }
	public function brickFields(bool $enabled=true): self { return $this->fieldsDisplay($enabled ? 'brick' : 'grid'); }
	public function masonryFields(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('fields', $options) : $this->fieldsDisplay('grid'); }
	public function entriesPresentation(array|string $presentation): self { return $this->collectionPresentation('entries', $presentation); }
	public function entriesDisplay(string $display): self { return $this->entriesPresentation($display); }
	public function brickEntries(bool $enabled=true): self { return $this->brickCollection('entries', $enabled); }
	public function masonryEntries(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('entries', $options) : $this->entriesDisplay('grid'); }
	public function itemsPresentation(array|string $presentation): self { return $this->collectionPresentation('items', $presentation); }
	public function itemsDisplay(string $display): self { return $this->itemsPresentation($display); }
	public function brickItems(bool $enabled=true): self { return $this->brickCollection('items', $enabled); }
	public function masonryItems(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('items', $options) : $this->itemsDisplay('grid'); }
	public function rowsPresentation(array|string $presentation): self { return $this->collectionPresentation('rows', $presentation); }
	public function rowsDisplay(string $display): self { return $this->rowsPresentation($display); }
	public function brickRows(bool $enabled=true): self { return $this->brickCollection('rows', $enabled); }
	public function masonryRows(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('rows', $options) : $this->rowsDisplay('stack'); }
	public function toolsPresentation(array|string $presentation): self { return $this->collectionPresentation('tools', $presentation); }
	public function toolsDisplay(string $display): self { return $this->toolsPresentation($display); }
	public function brickTools(bool $enabled=true): self { return $this->brickCollection('tools', $enabled); }
	public function masonryTools(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('tools', $options) : $this->toolsDisplay('inline'); }
	public function formsPresentation(array|string $presentation): self { return $this->collectionPresentation('forms', $presentation); }
	public function formsDisplay(string $display): self { return $this->formsPresentation($display); }
	public function brickForms(bool $enabled=true): self { return $this->brickCollection('forms', $enabled); }
	public function masonryForms(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('forms', $options) : $this->formsDisplay('stack'); }
	public function tablesPresentation(array|string $presentation): self { return $this->collectionPresentation('tables', $presentation); }
	public function tablesDisplay(string $display): self { return $this->tablesPresentation($display); }
	public function brickTables(bool $enabled=true): self { return $this->brickCollection('tables', $enabled); }
	public function masonryTables(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('tables', $options) : $this->tablesDisplay('stack'); }
	public function boardColumnsPresentation(array|string $presentation): self { return $this->collectionPresentation('board_columns', $presentation); }
	public function boardColumnsDisplay(string $display): self { return $this->boardColumnsPresentation($display); }
	public function brickBoardColumns(bool $enabled=true): self { return $this->brickCollection('board_columns', $enabled); }
	public function masonryBoardColumns(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('board_columns', $options) : $this->boardColumnsDisplay('grid'); }
	public function boardCardsPresentation(array|string $presentation): self { return $this->collectionPresentation('board_cards', $presentation); }
	public function boardCardsDisplay(string $display): self { return $this->boardCardsPresentation($display); }
	public function brickBoardCards(bool $enabled=true): self { return $this->brickCollection('board_cards', $enabled); }
	public function masonryBoardCards(bool $enabled=true, array $options=[]): self { return $enabled ? $this->rowMasonry('board_cards', $options) : $this->boardCardsDisplay('stack'); }

	public function viewItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('views', $item, $presentation); }
	public function groupItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('groups', $item, $presentation); }
	public function summaryItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('summaries', $item, $presentation); }
	public function filterItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('filters', $item, $presentation); }
	public function actionItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('actions', $item, $presentation); }
	public function tabItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('tabs', $item, $presentation); }
	public function stepItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('steps', $item, $presentation); }
	public function optionItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('options', $item, $presentation); }
	public function widgetItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('widgets', $item, $presentation); }
	public function toolbarItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('toolbar', $item, $presentation); }
	public function sectionItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('sections', $item, $presentation); }
	public function fieldItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('fields', $item, $presentation); }
	public function entryItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('entries', $item, $presentation); }
	public function rowItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('rows', $item, $presentation); }
	public function toolItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('tools', $item, $presentation); }
	public function formItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('forms', $item, $presentation); }
	public function tableItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('tables', $item, $presentation); }
	public function boardColumnItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('board_columns', $item, $presentation); }
	public function boardCardItemPresentation(string|int $item, array|PanelCollectionItemPresentation $presentation): self { return $this->collectionItemPresentation('board_cards', $item, $presentation); }

	private static function collectionDefaultDisplay(string $collection): string {
		return match(Resource::normalizeName($collection)){
			'views', 'groups', 'tabs', 'steps'=>'segmented',
			'summaries', 'filters', 'widgets', 'fields', 'entries', 'items', 'insights', 'links', 'contacts', 'locations', 'totals', 'payments', 'shipments', 'attachments', 'board_columns'=>'grid',
			'sections', 'options', 'relations', 'forms', 'tables', 'alerts', 'approvals', 'activity', 'changes', 'notes', 'messages', 'tasks', 'board_cards'=>'stack',
			'tags'=>'inline',
			default=>'inline',
		};
	}
}
