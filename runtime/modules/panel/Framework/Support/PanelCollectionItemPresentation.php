<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable, injection-safe presentation metadata for one collection item.
 *
 * Item presentation is deliberately opt-in. An empty definition serializes to
 * an empty array and emits no markup, preserving every legacy collection until
 * a builder explicitly configures an item.
 */
final class PanelCollectionItemPresentation implements \JsonSerializable {
	private const BREAKPOINTS=['base', 'sm', 'md', 'lg', 'xl', '2xl'];
	private const RESPONSIVE_CONTROLS=['span', 'basis', 'min_width', 'max_width', 'grow', 'shrink', 'order', 'break_before', 'fill_remainder'];
	private const LENGTH_TOKENS=['auto', 'min-content', 'max-content', 'fit-content', 'content', 'stretch'];

	/** @var array<string,mixed> */
	private array $definition=[];

	/** @param array<string,mixed> $definition */
	private function __construct(array $definition=[]) {
		$this->definition=self::normalize($definition);
	}

	/** @param array<string,mixed>|self|null $definition */
	public static function make(array|self|null $definition=null): self {
		return $definition instanceof self ? clone $definition : new self(is_array($definition) ? $definition : []);
	}

	/** @param array<string,mixed>|self|null $definition
	 *  @return array<string,mixed>
	 */
	public static function normalize(array|self|null $definition=null): array {
		if($definition instanceof self){
			return $definition->toArray();
		}
		if(!is_array($definition) || $definition===[]){
			return [];
		}
		$normalized=[];
		$span=self::responsive($definition['span'] ?? $definition['column_span'] ?? null, static function(mixed $value): ?int {
			return is_numeric($value) ? max(1, min(12, (int)$value)) : null;
		});
		if($span!==[]){
			$normalized['span']=$span;
		}
		foreach([
			'basis'=>['basis', 'width'],
			'min_width'=>['min_width', 'minimum_width', 'min'],
			'max_width'=>['max_width', 'maximum_width', 'max'],
		] as $target=>$sources){
			$value=null;
			foreach($sources as $source){
				if(array_key_exists($source, $definition)){
					$value=$definition[$source];
					break;
				}
			}
			$lengths=self::responsive($value, [self::class, 'length']);
			if($lengths!==[]){
				$normalized[$target]=$lengths;
			}
		}
		foreach(['grow', 'shrink'] as $key){
			if(!array_key_exists($key, $definition)){
				continue;
			}
			$value=$definition[$key];
			$normalizer=static function(mixed $entry): int|float|null {
				return is_numeric($entry) ? self::number(max(0.0, min(12.0, (float)$entry))) : null;
			};
			if(is_array($value)){
				$responsive=self::responsive($value, $normalizer);
				if($responsive!==[]){
					$normalized[$key]=$responsive;
				}
			}else{
				$value=$normalizer($value);
				if($value!==null){
					$normalized[$key]=$value;
				}
			}
		}
		if(array_key_exists('order', $definition)){
			$value=$definition['order'];
			$normalizer=static fn(mixed $entry): ?int=>is_numeric($entry) ? max(-100, min(100, (int)$entry)) : null;
			if(is_array($value)){
				$responsive=self::responsive($value, $normalizer);
				if($responsive!==[]){
					$normalized['order']=$responsive;
				}
			}else{
				$value=$normalizer($value);
				if($value!==null){
					$normalized['order']=$value;
				}
			}
		}
		foreach([
			'break_before'=>['break_before', 'new_row', 'row_break'],
			'fill_remainder'=>['fill_remainder', 'fill_remaining', 'fill_last_row'],
		] as $target=>$sources){
			foreach($sources as $source){
				if(array_key_exists($source, $definition)){
					$value=$definition[$source];
					if(is_array($value)){
						$responsive=self::responsive($value, static fn(mixed $entry): bool=>self::boolean($entry));
						if($responsive!==[]){
							$normalized[$target]=$responsive;
						}
					}else{
						$normalized[$target]=self::boolean($value);
					}
					break;
				}
			}
		}
		return $normalized;
	}

	/** @param array<string,mixed>|self|null ...$definitions
	 *  @return array<string,mixed>
	 */
	public static function merge(array|self|null ...$definitions): array {
		$merged=[];
		foreach($definitions as $definition){
			foreach(self::normalize($definition) as $key=>$value){
				$current=$merged[$key] ?? null;
				if(in_array($key, self::RESPONSIVE_CONTROLS, true) && (is_array($value) || is_array($current))){
					$current=is_array($current) ? $current : ($current===null ? [] : ['base'=>$current]);
					$value=is_array($value) ? $value : ['base'=>$value];
					$merged[$key]=array_replace($current, $value);
				}else{
					$merged[$key]=$value;
				}
			}
		}
		return self::normalize($merged);
	}

	/** @param array<string,mixed> $meta
	 *  @return array<string,mixed>
	 */
	public static function fromMeta(array $meta): array {
		$presentation=is_array($meta['presentation'] ?? null) ? $meta['presentation'] : [];
		$definition=$meta['item_presentation'] ?? $presentation['item'] ?? $meta['brick'] ?? null;
		return self::normalize(is_array($definition) || $definition instanceof self ? $definition : null);
	}

	/** @param array<string,mixed> $definition */
	public function with(array $definition): self {
		return new self(self::merge($this->definition, $definition));
	}

	/** @param int|array<string,int> $span */
	public function span(int|array $span, string $breakpoint='base'): self {
		return $this->responsiveValue('span', $span, $breakpoint);
	}

	/** @param int|float|string|array<string,int|float|string> $basis */
	public function basis(int|float|string|array $basis, string $breakpoint='base'): self {
		return $this->responsiveValue('basis', $basis, $breakpoint);
	}

	/** @param int|float|string|array<string,int|float|string> $width */
	public function minWidth(int|float|string|array $width, string $breakpoint='base'): self {
		return $this->responsiveValue('min_width', $width, $breakpoint);
	}

	/** @param int|float|string|array<string,int|float|string> $width */
	public function maxWidth(int|float|string|array $width, string $breakpoint='base'): self {
		return $this->responsiveValue('max_width', $width, $breakpoint);
	}

	/** @param int|float|array<string,int|float> $grow */
	public function grow(int|float|array $grow=1, string $breakpoint='base'): self { return $this->responsiveControlValue('grow', $grow, $breakpoint); }
	/** @param int|float|array<string,int|float> $shrink */
	public function shrink(int|float|array $shrink=1, string $breakpoint='base'): self { return $this->responsiveControlValue('shrink', $shrink, $breakpoint); }
	/** @param int|array<string,int> $order */
	public function order(int|array $order, string $breakpoint='base'): self { return $this->responsiveControlValue('order', $order, $breakpoint); }
	/** @param bool|array<string,bool> $break */
	public function breakBefore(bool|array $break=true, string $breakpoint='base'): self { return $this->responsiveControlValue('break_before', $break, $breakpoint); }
	/** @param bool|array<string,bool> $fill */
	public function fillRemainder(bool|array $fill=true, string $breakpoint='base'): self { return $this->responsiveControlValue('fill_remainder', $fill, $breakpoint); }

	/** @return array<string,mixed> */
	public function toArray(): array { return $this->definition; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return $this->toArray(); }

	/** @param array<string,mixed>|self|null $definition */
	public static function htmlAttributes(array|self|null $definition=null): string {
		$attributes=self::attributeMap($definition);
		if($attributes===[]){
			return '';
		}
		$e=static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		$html='';
		foreach($attributes as $name=>$value){
			$html.=' '.$name.'="'.$e($value).'"';
		}
		return $html;
	}

	/** Returns an inert, responsive row-break sentinel when any breakpoint enables one. */
	public static function breakSentinelHtml(array|self|null $definition=null): string {
		$presentation=self::normalize($definition);
		$break=$presentation['break_before'] ?? null;
		if($break===true){
			return '<span data-dp-item-break="1" aria-hidden="true"></span>';
		}
		if(!is_array($break) || !in_array(true, $break, true)){
			return '';
		}
		$styles=['--dp-item-grow:0', '--dp-item-shrink:0', '--dp-item-basis:100%'];
		foreach(self::expandedResponsive($break) as $breakpoint=>$enabled){
			$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
			$styles[]='--dp-item-break-display'.$suffix.':'.($enabled ? 'block' : 'none');
		}
		return '<span data-dp-item-break="responsive" data-dp-item-layout="1" data-dp-item-responsive="1" data-dp-item-responsive-tiers="sm md lg xl 2xl" style="'.htmlspecialchars(implode(';', $styles), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'" aria-hidden="true"></span>';
	}

	/** Adds item attributes to the first root element without duplicating style. */
	public static function decorateHtml(string $html, array|self|null $definition=null, array $computedStyles=[]): string {
		$attributes=self::attributeMap($definition, $computedStyles);
		if($attributes===[] || trim($html)===''){
			return $html;
		}
		$decorated=preg_replace_callback('/\A(\s*<[a-zA-Z][a-zA-Z0-9:-]*)([^>]*>)/s', static function(array $match) use ($attributes): string {
			$tail=$match[2];
			$style=$attributes['style'] ?? null;
			unset($attributes['style']);
			if(is_string($style) && $style!==''){
				if(preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $tail)===1){
					$tail=preg_replace_callback('/\sstyle\s*=\s*(["\'])(.*?)\1/is', static function(array $styleMatch) use ($style): string {
						$current=rtrim($styleMatch[2], " \t\n\r\0\x0B;");
						return ' style='.$styleMatch[1].$current.($current==='' ? '' : ';').htmlspecialchars($style, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').$styleMatch[1];
					}, $tail, 1) ?? $tail;
				}else{
					$tail=' style="'.htmlspecialchars($style, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'"'.$tail;
				}
			}
			$extra='';
			foreach($attributes as $name=>$value){
				if(preg_match('/\s'.preg_quote($name, '/').'\s*=/i', $tail)===1){
					continue;
				}
				$extra.=' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'"';
			}
			return $match[1].$extra.$tail;
		}, $html, 1);
		return is_string($decorated) ? $decorated : $html;
	}

	/** @return array<string,string> */
	private static function attributeMap(array|self|null $definition, array $computedStyles=[]): array {
		$presentation=self::normalize($definition);
		if($presentation===[]){
			return [];
		}
		$attributes=['data-dp-item-layout'=>'1'];
		$responsive=false;
		foreach(['grow', 'shrink', 'order', 'break_before', 'fill_remainder'] as $key){
			if(is_array($presentation[$key] ?? null)){
				$responsive=true;
				break;
			}
		}
		if($responsive){
			$attributes['data-dp-item-responsive']='1';
			$attributes['data-dp-item-responsive-tiers']='sm md lg xl 2xl';
		}
		$styles=[];
		foreach(['span'=>'span', 'basis'=>'basis', 'min_width'=>'min', 'max_width'=>'max'] as $key=>$property){
			foreach(($presentation[$key] ?? []) as $breakpoint=>$value){
				$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
				$styles[]='--dp-item-'.$property.$suffix.':'.$value;
			}
		}
		foreach(['grow', 'shrink', 'order'] as $key){
			$value=$presentation[$key] ?? null;
			if(is_array($value)){
				foreach(self::expandedResponsive($value) as $breakpoint=>$entry){
					$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
					$styles[]='--dp-item-'.$key.$suffix.':'.$entry;
				}
			}elseif($value!==null){
				$styles[]='--dp-item-'.$key.':'.$value;
			}
		}
		foreach($computedStyles as $property=>$value){
			$property=trim((string)$property);
			$value=trim((string)$value);
			if(preg_match('/^--dp-item-(?:basis|min|max|span)(?:-(?:sm|md|lg|xl|2xl))?$/', $property)!==1){
				continue;
			}
			if(preg_match('/^(?:\d+(?:\.\d+)?%|calc\([0-9.%px +\-*\/]+\))$/', $value)!==1){
				continue;
			}
			$styles[]=$property.':'.$value;
		}
		foreach(['break_before', 'fill_remainder'] as $key){
			if(!array_key_exists($key, $presentation)){
				continue;
			}
			$value=$presentation[$key];
			$attribute='data-dp-item-'.str_replace('_', '-', $key);
			if(!is_array($value)){
				$attributes[$attribute]=$value ? '1' : '0';
				continue;
			}
			$attributes[$attribute]='responsive';
			if($key==='fill_remainder'){
				foreach(self::expandedResponsive($value) as $breakpoint=>$enabled){
					$suffix=$breakpoint==='base' ? '' : '-'.$breakpoint;
					$styles[]='--dp-item-fill-grid'.$suffix.':'.($enabled ? '1/-1' : 'span var(--dp-item-span-active)');
					$styles[]='--dp-item-fill-grow'.$suffix.':'.($enabled ? '999' : 'var(--dp-item-grow-active)');
				}
			}
		}
		if($styles!==[]){
			$attributes['style']=implode(';', $styles);
		}
		return $attributes;
	}

	private function responsiveValue(string $key, mixed $value, string $breakpoint): self {
		$current=$this->definition[$key] ?? [];
		if(!is_array($current)){
			$current=[];
		}
		$incoming=is_array($value) ? $value : [$breakpoint=>$value];
		return $this->with([$key=>array_replace($current, $incoming)]);
	}

	private function responsiveControlValue(string $key, mixed $value, string $breakpoint): self {
		$current=$this->definition[$key] ?? null;
		if(!is_array($value) && $breakpoint==='base' && !is_array($current)){
			return $this->with([$key=>$value]);
		}
		$current=is_array($current) ? $current : ($current===null ? [] : ['base'=>$current]);
		$incoming=is_array($value) ? $value : [$breakpoint=>$value];
		return $this->with([$key=>array_replace($current, $incoming)]);
	}

	/** @param callable(mixed): mixed $normalizer
	 *  @return array<string,mixed>
	 */
	private static function responsive(mixed $value, callable $normalizer): array {
		if($value===null){
			return [];
		}
		$value=is_array($value) ? $value : ['base'=>$value];
		$normalized=[];
		foreach($value as $breakpoint=>$entry){
			$breakpoint=is_int($breakpoint) ? 'base' : strtolower(trim((string)$breakpoint));
			if(!in_array($breakpoint, self::BREAKPOINTS, true)){
				continue;
			}
			$entry=$normalizer($entry);
			if($entry!==null){
				$normalized[$breakpoint]=$entry;
			}
		}
		return $normalized;
	}

	/** @param array<string,mixed> $values
	 *  @return array<string,mixed>
	 */
	private static function expandedResponsive(array $values): array {
		$expanded=[];
		$active=null;
		$configured=false;
		foreach(self::BREAKPOINTS as $breakpoint){
			if(array_key_exists($breakpoint, $values)){
				$active=$values[$breakpoint];
				$configured=true;
			}
			if($configured){
				$expanded[$breakpoint]=$active;
			}
		}
		return $expanded;
	}

	private static function length(mixed $value): ?string {
		if(is_int($value) || is_float($value)){
			return self::number(max(0.0, min(10000.0, (float)$value))).'px';
		}
		$value=strtolower(trim((string)$value));
		if(in_array($value, self::LENGTH_TOKENS, true)){
			return $value;
		}
		if(preg_match('/^(?:0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|ch|vw|vh|vmin|vmax|%))$/', $value)!==1){
			return null;
		}
		return $value==='0' ? '0px' : $value;
	}

	private static function boolean(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_numeric($value)){
			return (float)$value!==0.0;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	private static function number(float $value): int|float {
		return floor($value)===$value ? (int)$value : (float)rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
	}
}
