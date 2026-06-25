<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Renders server-side HTML hooks required by Reactor components.
 *
 * Reactor views wrap component HTML with the normalized component name and signed snapshot data
 * that the browser runtime needs for subsequent updates. Optional attributes are sanitized so
 * callers can add safe data/aria/plain attributes without opening arbitrary attribute injection.
 */
final class ReactorView {

	/**
	 * Wraps component HTML in a Reactor root element.
	 *
	 * The supplied HTML is treated as already-rendered component output. Attribute values are
	 * escaped, while false/null attributes are omitted and true attributes are rendered as boolean
	 * attributes.
	 *
	 * @param string $component Component name to normalize for the client runtime.
	 * @param string $html Rendered component HTML.
	 * @param ReactorSnapshot $snapshot Snapshot serialized into `data-dp-reactor-snapshot`.
	 * @param array<string, scalar|null|bool> $attributes Additional safe HTML attributes.
	 * @return string Reactor root `<div>` HTML.
	 */
	public static function mount(string $component, string $html, ReactorSnapshot $snapshot, array $attributes=[]): string {
		$component=ReactorName::normalize($component);
		$attrs=[
			'data-dp-reactor-component'=>$component,
			'data-dp-reactor-snapshot'=>json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
		];
		foreach($attributes as $name=>$value){
			$name=self::attributeName((string)$name);
			if($name==='' || $value===false || $value===null){
				continue;
			}
			$attrs[$name]=$value===true ? '' : (string)$value;
		}
		return '<div'.self::attributes($attrs).'>'.$html.'</div>';
	}

	/**
	 * Renders the Reactor browser runtime script tag.
	 *
	 * @param ?string $endpoint Optional default endpoint for mounted components.
	 * @return string Deferred Reactor client asset script tag.
	 */
	public static function script(?string $endpoint=null): string {
		return ReactorClientAssets::script($endpoint);
	}

	/**
	 * Builds an escaped HTML attribute string from sanitized names.
	 *
	 * @param array<string, string> $attributes Attribute map.
	 * @return string Leading-space-prefixed attribute HTML.
	 */
	private static function attributes(array $attributes): string {
		$html='';
		foreach($attributes as $name=>$value){
			$html.=' '.$name;
			if($value!==''){
				$html.='="'.htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
			}
		}
		return $html;
	}

	/**
	 * Sanitizes an attribute name for Reactor mount output.
	 *
	 * @param string $name Candidate attribute name.
	 * @return string Lowercase safe attribute name, or an empty string when rejected.
	 */
	private static function attributeName(string $name): string {
		$name=strtolower(trim($name));
		return preg_match('/^(?:data|aria)-[a-z0-9_.:-]+$|^[a-z]+$/', $name)===1 ? $name : '';
	}
}
