<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Normalizes collection layout metadata shared by Panel primitives.
 *
 * A collection presentation controls the arrangement of sibling controls or
 * entries without changing their semantic role. Renderers expose the normalized
 * contract through data attributes and CSS custom properties.
 */
final class PanelCollectionPresentation {
	private const DISPLAYS=['inline', 'segmented', 'brick', 'stack', 'grid', 'masonry'];
	private const DENSITIES=['compact', 'normal', 'roomy'];
	private const GAPS=['none', 'compact', 'normal', 'roomy'];
	private const FITS=['auto', 'fill', 'fixed'];
	private const MASONRY_FLOWS=['columns', 'rows'];
	private const FINAL_ROWS=['fill', 'preserve', 'center', 'end'];
	private const BREAKPOINTS=['base', 'sm', 'md', 'lg', 'xl', '2xl'];

	/** @return array<string,mixed> */
	public static function normalize(array|string|null $definition=null, string $defaultDisplay='inline'): array {
		if(is_string($definition)){
			$definition=['display'=>$definition];
		}
		$definition=is_array($definition) ? $definition : [];
		$requestedDisplay=Resource::normalizeName((string)($definition['display'] ?? $definition['layout'] ?? $defaultDisplay));
		$rowMasonry=in_array($requestedDisplay, ['balanced', 'flow', 'masonry_rows', 'row_masonry', 'row_fill'], true);
		$display=self::display($requestedDisplay, $defaultDisplay);
		$density=self::token((string)($definition['density'] ?? 'normal'), self::DENSITIES, 'normal');
		$gap=self::token((string)($definition['gap'] ?? 'normal'), self::GAPS, 'normal');
		$fit=self::token((string)($definition['fit'] ?? ($rowMasonry ? 'fill' : 'auto')), self::FITS, 'auto');
		$masonry=self::token((string)($definition['masonry'] ?? $definition['flow'] ?? $definition['direction'] ?? ($rowMasonry ? 'rows' : 'columns')), self::MASONRY_FLOWS, 'columns');
		$columns=self::columns($definition['columns'] ?? []);
		$minimum=max(96, min(480, (int)($definition['min_width'] ?? $definition['minimum_width'] ?? ($display==='brick' ? 160 : 220))));
		$normalized=[
			'display'=>$display,
			'density'=>$density,
			'gap'=>$gap,
			'fit'=>$fit,
			'masonry'=>$masonry,
			'columns'=>$columns,
			'min_width'=>$minimum,
		];
		$items=self::items($definition['items'] ?? $definition['item_presentations'] ?? []);
		if($items!==[]){
			$normalized['items']=$items;
		}
		if(array_key_exists('final_row', $definition) || array_key_exists('last_row', $definition)){
			$normalized['final_row']=self::token((string)($definition['final_row'] ?? $definition['last_row']), self::FINAL_ROWS, 'fill');
		}
		return $normalized;
	}

	/** @param array<string,mixed> $meta */
	public static function fromMeta(array $meta, string $collection, string $defaultDisplay='inline'): array {
		$collection=Resource::normalizeName($collection);
		$presentations=is_array($meta['presentation'] ?? null) ? $meta['presentation'] : [];
		$definition=$presentations[$collection] ?? $meta[$collection.'_presentation'] ?? null;
		return self::normalize(is_array($definition) || is_string($definition) ? $definition : null, $defaultDisplay);
	}

	/** @param array<string,mixed>|string|null $definition */
	public static function htmlAttributes(array|string|null $definition=null, string $defaultDisplay='inline', array|string $extraStyles=[]): string {
		$presentation=self::normalize($definition, $defaultDisplay);
		$styles=['--dp-collection-min:'.$presentation['min_width'].'px'];
		foreach($presentation['columns'] as $breakpoint=>$columns){
			$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
			$styles[]='--dp-collection-columns'.$suffix.':'.$columns;
			$styles[]='--dp-collection-basis'.$suffix.':'.self::basis($columns, $presentation['gap']);
		}
		$extraStyles=is_array($extraStyles) ? $extraStyles : [$extraStyles];
		foreach($extraStyles as $style){
			$style=trim((string)$style, " \t\n\r\0\x0B;");
			if($style!==''){
				$styles[]=$style;
			}
		}
		$e=static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		$attributes=' data-dp-display="'.$e($presentation['display']).'"'
			.' data-dp-density="'.$e($presentation['density']).'"'
			.' data-dp-gap="'.$e($presentation['gap']).'"'
			.' data-dp-fit="'.$e($presentation['fit']).'"'
			.' data-dp-masonry="'.$e($presentation['masonry']).'"'
			.' data-dp-columns="'.($presentation['columns']===[] ? 'auto' : 'configured').'"';
		if(isset($presentation['final_row'])){
			$attributes.=' data-dp-final-row="'.$e((string)$presentation['final_row']).'"';
		}
		return $attributes.' style="'.$e(implode(';', $styles)).'"';
	}

	/** Returns the stable selector used by owner-level item maps. */
	public static function itemKey(string|int $item): string {
		if(is_int($item)){
			return '#'.max(0, $item);
		}
		$item=trim($item);
		if($item==='*'){
			return '*';
		}
		if(preg_match('/^#\d+$/', $item)===1){
			return $item;
		}
		return Resource::normalizeName($item);
	}

	/** @param array<string,mixed>|string|null $definition
	 *  @param array<string,mixed> $meta
	 *  @return array<string,mixed>
	 */
	public static function itemPresentation(array|string|null $definition, string|int|null $name=null, ?int $index=null, array $meta=[]): array {
		$presentation=self::normalize($definition);
		$items=is_array($presentation['items'] ?? null) ? $presentation['items'] : [];
		$named=$name===null ? '' : self::itemKey($name);
		return PanelCollectionItemPresentation::merge(
			$items['*'] ?? null,
			$index===null ? null : ($items['#'.max(0, $index)] ?? null),
			$named==='' ? null : ($items[$named] ?? null),
			PanelCollectionItemPresentation::fromMeta($meta),
		);
	}

	/** @param array<string,mixed>|string|null $definition
	 *  @param array<string,mixed> $meta
	 */
	public static function decorateItemHtml(string $html, array|string|null $definition, string|int|null $name=null, ?int $index=null, array $meta=[]): string {
		$presentation=self::normalize($definition);
		$item=self::itemPresentation($presentation, $name, $index, $meta);
		$decorated=PanelCollectionItemPresentation::decorateHtml($html, $item, self::computedItemStyles($presentation, $item));
		return $decorated==='' ? $decorated : PanelCollectionItemPresentation::breakSentinelHtml($item).$decorated;
	}

	private static function display(string $display, string $fallback): string {
		$display=Resource::normalizeName($display);
		$display=match($display){
			'pill', 'pills', 'tabs', 'tabbed'=>'segmented',
			'row', 'cluster', 'wrap'=>'inline',
			'column', 'vertical', 'stacked'=>'stack',
			'card', 'cards', 'tile', 'tiles'=>'brick',
			'balanced', 'flow', 'masonry_rows', 'row_masonry', 'row_fill', 'masonry_columns', 'column_masonry'=>'masonry',
			default=>$display,
		};
		$fallback=Resource::normalizeName($fallback);
		return in_array($display, self::DISPLAYS, true) ? $display : (in_array($fallback, self::DISPLAYS, true) ? $fallback : 'inline');
	}

	/** @param array<int,string> $allowed */
	private static function token(string $value, array $allowed, string $fallback): string {
		$value=Resource::normalizeName($value);
		return in_array($value, $allowed, true) ? $value : $fallback;
	}

	/** @return array<string,int> */
	private static function columns(mixed $columns): array {
		if(is_numeric($columns)){
			$columns=['base'=>(int)$columns];
		}
		if(!is_array($columns)){
			return [];
		}
		$normalized=[];
		foreach($columns as $breakpoint=>$count){
			$breakpoint=is_int($breakpoint) ? 'base' : strtolower(trim((string)$breakpoint));
			if(!in_array($breakpoint, self::BREAKPOINTS, true) || !is_numeric($count)){
				continue;
			}
			$normalized[$breakpoint]=max(1, min(12, (int)$count));
		}
		return $normalized;
	}

	/** @return array<string,array<string,mixed>> */
	private static function items(mixed $items): array {
		if(!is_array($items)){
			return [];
		}
		$normalized=[];
		foreach($items as $key=>$definition){
			$key=self::itemKey(is_int($key) ? $key : (string)$key);
			$item=PanelCollectionItemPresentation::normalize($definition instanceof PanelCollectionItemPresentation || is_array($definition) ? $definition : null);
			if($key!=='' && $item!==[]){
				$normalized[$key]=$item;
			}
		}
		return $normalized;
	}

	private static function basis(int $columns, string $gap): string {
		$columns=max(1, $columns);
		$gapPixels=['none'=>0, 'compact'=>8, 'roomy'=>20][$gap] ?? 12;
		$percent=rtrim(rtrim(number_format(100/$columns, 6, '.', ''), '0'), '.');
		$gapShare=rtrim(rtrim(number_format((($columns-1)*$gapPixels)/$columns, 4, '.', ''), '0'), '.');
		return $gapShare==='0' ? $percent.'%' : 'calc('.$percent.'% - '.$gapShare.'px)';
	}

	/** @param array<string,mixed> $presentation
	 *  @param array<string,mixed> $item
	 *  @return array<string,string>
	 */
	private static function computedItemStyles(array $presentation, array $item): array {
		if(($item['span'] ?? [])===[] || ($presentation['columns'] ?? [])===[] || ($item['basis'] ?? [])!==[]){
			return [];
		}
		$styles=[];
		$activeSpan=null;
		$activeColumns=null;
		foreach(self::BREAKPOINTS as $breakpoint){
			if(isset($item['span'][$breakpoint])){
				$activeSpan=(int)$item['span'][$breakpoint];
			}
			if(isset($presentation['columns'][$breakpoint])){
				$activeColumns=(int)$presentation['columns'][$breakpoint];
			}
			if($activeSpan===null || $activeColumns===null){
				continue;
			}
			$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
			$styles['--dp-item-basis'.$suffix]=self::spanBasis($activeSpan, $activeColumns, (string)($presentation['gap'] ?? 'normal'));
		}
		return $styles;
	}

	private static function spanBasis(int $span, int $columns, string $gap): string {
		$columns=max(1, $columns);
		$span=max(1, min($columns, $span));
		$gapPixels=['none'=>0, 'compact'=>8, 'roomy'=>20][$gap] ?? 12;
		$percent=rtrim(rtrim(number_format(($span/$columns)*100, 6, '.', ''), '0'), '.');
		$gapShare=rtrim(rtrim(number_format((($columns-$span)*$gapPixels)/$columns, 4, '.', ''), '0'), '.');
		return $gapShare==='0' ? $percent.'%' : 'calc('.$percent.'% - '.$gapShare.'px)';
	}
}
