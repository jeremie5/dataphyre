<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Item-aware responsive layout rules layered after the collection system. */
trait PanelRendererAssetsBrickV2Css {
	/** Scalar item controls and responsive dimensions shared by configured collections. */
	private static function brickV2Css(): string {
		$css=<<<'CSS'
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-layout="1"]{box-sizing:border-box;--dp-item-span-active:var(--dp-item-span,1);--dp-item-basis-active:var(--dp-item-basis,var(--dp-item-basis-default,auto));--dp-item-min-active:var(--dp-item-min,0px);--dp-item-max-active:var(--dp-item-max,100%);min-width:var(--dp-item-min-active);max-width:var(--dp-item-max-active);order:var(--dp-item-order)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="grid"],[data-dp-display="brick"],.dp-panel-form-grid,.dp-panel-widgets)>[data-dp-item-layout="1"]{grid-column:span var(--dp-item-span-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="grid"],[data-dp-display="brick"],.dp-panel-form-grid,.dp-panel-widgets)>[data-dp-item-fill-remainder="1"]{grid-column:1/-1}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="inline"],[data-dp-display="segmented"])>[data-dp-item-layout="1"]{flex-grow:var(--dp-item-grow,0);flex-shrink:var(--dp-item-shrink,1);flex-basis:var(--dp-item-basis-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>[data-dp-item-layout="1"]{--dp-item-basis-default:var(--dp-collection-basis-active,var(--dp-collection-min,180px));flex-grow:var(--dp-item-grow,1);flex-shrink:var(--dp-item-shrink,1);flex-basis:var(--dp-item-basis-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>[data-dp-item-fill-remainder="1"]{--dp-item-fill-grow-active:999;flex-grow:999}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])[data-dp-final-row="preserve"]>*{--dp-item-fill-grow-active:0;flex-grow:0}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])[data-dp-final-row="center"]{justify-content:center}body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])[data-dp-final-row="center"]>*{--dp-item-fill-grow-active:0;flex-grow:0}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])[data-dp-final-row="end"]{justify-content:flex-end}body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])[data-dp-final-row="end"]>*{--dp-item-fill-grow-active:0;flex-grow:0}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-break="1"]{display:none;box-sizing:border-box;--dp-item-fill-grow-active:0;--dp-item-shrink-active:0;--dp-item-basis-active:100%;width:0;height:0;min-width:0;max-width:none;margin:0;padding:0;border:0}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="inline"],[data-dp-display="segmented"],[data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>[data-dp-item-break="1"]{display:block;flex:0 0 100%}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="grid"],[data-dp-display="brick"],.dp-panel-form-grid,.dp-panel-widgets)>[data-dp-item-break="1"]{display:block;grid-column:1/-1}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display="masonry"][data-dp-masonry="columns"]>[data-dp-item-break="1"]{display:block;break-before:column}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="brick"],[data-dp-fit="fill"],[data-dp-display="masonry"][data-dp-masonry="rows"])>:is(.dp-panel-action-group,.dp-panel-column-picker)>summary{width:100%;height:100%;min-height:52px;justify-content:center}
CSS;
		$active=[];
		foreach(['sm'=>640, 'md'=>768, 'lg'=>1024, 'xl'=>1280, '2xl'=>1536] as $breakpoint=>$minimum){
			$active[]=$breakpoint;
			$css.='@media(min-width:'.$minimum.'px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-layout="1"]{'.self::brickV2ItemDeclarations($active).'}}';
		}
		$css.='@media(max-width:639px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-layout="1"]{'.self::brickV2ItemDeclarations([], true).'}}';
		$css.='.dp-panel-main-region,.dp-panel-modal-body{container-name:dp-panel-layout;container-type:inline-size}';
		foreach([
			1535=>['sm','md','lg','xl'],
			1279=>['sm','md','lg'],
			1023=>['sm','md'],
			767=>['sm'],
		] as $maximum=>$breakpoints){
			$css.='@container dp-panel-layout (max-width:'.$maximum.'px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-display]{'.self::brickCollectionDeclarations($breakpoints).'}body :is(.dp-panel,.dp-panel-modal-root) [data-dp-item-layout="1"]{'.self::brickV2ItemDeclarations($breakpoints).'}}';
		}
		$css.='@container dp-panel-layout (max-width:400px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:100%;--dp-collection-columns-active:1}body :is(.dp-panel,.dp-panel-modal-root) [data-dp-item-layout="1"]{'.self::brickV2ItemDeclarations([], true).'}body :is(.dp-panel,.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>*{flex-basis:100%;min-width:0}}';
		return $css;
	}

	/** Breakpoint-aware grow, shrink, order, break, and fill loaded only when detected. */
	private static function brickV3Css(): string {
		$css=<<<'CSS'
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-responsive="1"]{--dp-item-grow-active:var(--dp-item-grow,var(--dp-item-grow-default,0));--dp-item-shrink-active:var(--dp-item-shrink,1);--dp-item-order-active:var(--dp-item-order,0);--dp-item-fill-grid-active:var(--dp-item-fill-grid,span var(--dp-item-span-active));--dp-item-fill-grow-active:var(--dp-item-fill-grow,var(--dp-item-grow-active));--dp-item-break-display-active:var(--dp-item-break-display,none);order:var(--dp-item-order-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="inline"],[data-dp-display="segmented"])>[data-dp-item-responsive="1"]{flex-grow:var(--dp-item-grow-active);flex-shrink:var(--dp-item-shrink-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>[data-dp-item-responsive="1"]{--dp-item-grow-default:1;flex-grow:var(--dp-item-grow-active);flex-shrink:var(--dp-item-shrink-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="grid"],[data-dp-display="brick"],.dp-panel-form-grid,.dp-panel-widgets)>[data-dp-item-fill-remainder="responsive"]{grid-column:var(--dp-item-fill-grid-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>[data-dp-item-fill-remainder="responsive"]{flex-grow:var(--dp-item-fill-grow-active)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-break="responsive"]{display:var(--dp-item-break-display-active);box-sizing:border-box;width:0;height:0;min-width:0;max-width:none;margin:0;padding:0;border:0}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="inline"],[data-dp-display="segmented"],[data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>[data-dp-item-break="responsive"]{flex:0 0 100%}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="grid"],[data-dp-display="brick"],.dp-panel-form-grid,.dp-panel-widgets)>[data-dp-item-break="responsive"]{grid-column:1/-1}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display="masonry"][data-dp-masonry="columns"]>[data-dp-item-break="responsive"]{break-before:column}
CSS;
		foreach(['sm'=>640, 'md'=>768, 'lg'=>1024, 'xl'=>1280, '2xl'=>1536] as $breakpoint=>$minimum){
			$css.='@media(min-width:'.$minimum.'px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-responsive="1"][data-dp-item-responsive-tiers~="'.$breakpoint.'"]{'.self::brickV3ItemDeclarations($breakpoint).'}}';
		}
		foreach([1535=>'xl', 1279=>'lg', 1023=>'md', 767=>'sm'] as $maximum=>$breakpoint){
			$css.='@container dp-panel-layout (max-width:'.$maximum.'px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-item-responsive="1"][data-dp-item-responsive-tiers~="'.$breakpoint.'"]{'.self::brickV3ItemDeclarations($breakpoint).'}}';
		}
		$css.='@container dp-panel-layout (max-width:400px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-item-responsive="1"]{'.self::brickV3ItemDeclarations(null).'}}';
		return $css;
	}

	/** @param list<string> $breakpoints */
	private static function brickV2ItemDeclarations(array $breakpoints, bool $singleColumn=false): string {
		$span=$singleColumn ? '1' : self::brickVariable('--dp-item-span', $breakpoints, '1');
		$basis=$singleColumn ? '100%' : self::brickVariable('--dp-item-basis', $breakpoints, 'var(--dp-item-basis-default,auto)');
		$minimum=$singleColumn ? '0px' : self::brickVariable('--dp-item-min', $breakpoints, '0px');
		$maximum=$singleColumn ? '100%' : self::brickVariable('--dp-item-max', $breakpoints, '100%');
		return '--dp-item-span-active:'.$span
			.';--dp-item-basis-active:'.$basis
			.';--dp-item-min-active:'.$minimum
			.';--dp-item-max-active:'.$maximum;
	}

	private static function brickV3ItemDeclarations(?string $breakpoint): string {
		return '--dp-item-grow-active:'.self::brickExpandedVariable('--dp-item-grow', $breakpoint, 'var(--dp-item-grow-default,0)')
			.';--dp-item-shrink-active:'.self::brickExpandedVariable('--dp-item-shrink', $breakpoint, '1')
			.';--dp-item-order-active:'.self::brickExpandedVariable('--dp-item-order', $breakpoint, '0')
			.';--dp-item-fill-grid-active:'.self::brickExpandedVariable('--dp-item-fill-grid', $breakpoint, 'span var(--dp-item-span-active)')
			.';--dp-item-fill-grow-active:'.self::brickExpandedVariable('--dp-item-fill-grow', $breakpoint, 'var(--dp-item-grow-active)')
			.';--dp-item-break-display-active:'.self::brickExpandedVariable('--dp-item-break-display', $breakpoint, 'none');
	}

	/** @param list<string> $breakpoints */
	private static function brickCollectionDeclarations(array $breakpoints): string {
		return '--dp-collection-basis-active:'.self::brickVariable('--dp-collection-basis', $breakpoints, 'var(--dp-collection-min,180px)')
			.';--dp-collection-columns-active:'.self::brickVariable('--dp-collection-columns', $breakpoints, '1');
	}

	/** @param list<string> $breakpoints */
	private static function brickVariable(string $property, array $breakpoints, string $fallback): string {
		$value='var('.$property.','.$fallback.')';
		foreach($breakpoints as $breakpoint){
			$value='var('.$property.'-'.$breakpoint.','.$value.')';
		}
		return $value;
	}

	private static function brickExpandedVariable(string $property, ?string $breakpoint, string $fallback): string {
		return $breakpoint===null
			? 'var('.$property.','.$fallback.')'
			: 'var('.$property.'-'.$breakpoint.',var('.$property.','.$fallback.'))';
	}
}
