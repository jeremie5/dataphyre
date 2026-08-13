<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class HtmlProbe {

	/** @return array<int, array{tag:string, attributes:array<string,string>, html:string}> */
	public static function matches(string $html, string $selector): array {
		$criteria=self::selector($selector);
		$matches=[];
		if(preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/m', $html, $found, PREG_SET_ORDER)!==false){
			foreach($found as $tag){
				$name=strtolower($tag[1]);
				$attributes=self::attributes((string)$tag[2]);
				if($criteria['tag']!=='' && $criteria['tag']!==$name){
					continue;
				}
				if($criteria['id']!=='' && ($attributes['id'] ?? '')!==$criteria['id']){
					continue;
				}
				if($criteria['class']!=='' && !in_array($criteria['class'], preg_split('/\s+/', (string)($attributes['class'] ?? '')) ?: [], true)){
					continue;
				}
				if($criteria['attribute']!=='' && !array_key_exists($criteria['attribute'], $attributes)){
					continue;
				}
				if($criteria['attribute']!=='' && $criteria['attribute_value']!==null && $attributes[$criteria['attribute']]!==$criteria['attribute_value']){
					continue;
				}
				$matches[]=[
					'tag'=>$name,
					'attributes'=>$attributes,
					'html'=>$tag[0],
				];
			}
		}
		return $matches;
	}

	public static function shape(string $html): array {
		$tags=[];
		if(preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>/m', $html, $found, PREG_SET_ORDER)!==false){
			foreach(array_slice($found, 0, 30) as $tag){
				$attrs=self::attributes((string)$tag[2]);
				$tags[]=strtolower($tag[1]).(isset($attrs['id']) ? '#'.$attrs['id'] : '').(isset($attrs['class']) ? '.'.str_replace(' ', '.', $attrs['class']) : '');
			}
		}
		return $tags;
	}

	private static function selector(string $selector): array {
		$selector=trim($selector);
		$criteria=[
			'tag'=>'',
			'id'=>'',
			'class'=>'',
			'attribute'=>'',
			'attribute_value'=>null,
		];
		if(preg_match('/\[([A-Za-z0-9_:-]+)(?:=([^\]]+))?\]/', $selector, $match)===1){
			$criteria['attribute']=strtolower($match[1]);
			$criteria['attribute_value']=isset($match[2]) ? trim($match[2], "\"' ") : null;
			$selector=str_replace($match[0], '', $selector);
		}
		if(preg_match('/#([A-Za-z0-9_-]+)/', $selector, $match)===1){
			$criteria['id']=$match[1];
			$selector=str_replace($match[0], '', $selector);
		}
		if(preg_match('/\.([A-Za-z0-9_-]+)/', $selector, $match)===1){
			$criteria['class']=$match[1];
			$selector=str_replace($match[0], '', $selector);
		}
		$selector=trim($selector);
		if($selector!=='' && $selector!=='*'){
			$criteria['tag']=strtolower($selector);
		}
		return $criteria;
	}

	private static function attributes(string $text): array {
		$attributes=[];
		if(preg_match_all('/([A-Za-z0-9_:-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $text, $found, PREG_SET_ORDER)!==false){
			foreach($found as $match){
				$name=strtolower($match[1]);
				$attributes[$name]=html_entity_decode((string)($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES|ENT_HTML5, 'UTF-8');
			}
		}
		return $attributes;
	}
}
