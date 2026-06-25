<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Produces serializable Reactor manifests for diagnostics and Flightdeck.
 *
 * The manifest captures component definitions, client bindings, listener maps,
 * derived capabilities, module version, component counts, and trace summaries in
 * a shape that can be JSON-encoded for inspectors and tests.
 */
final class ReactorManifest {

	/**
	 * Builds a manifest for every component registered with a manager.
	 *
	 * Component names remain the manifest keys so callers can compare output
	 * against registration state without scanning the component payloads.
	 *
	 * @param ReactorManager $manager Runtime manager holding registered components.
	 * @return array{module:string,version:string,components:array,component_count:int,trace:array} Reactor manifest payload.
	 */
	public static function manager(ReactorManager $manager): array {
		$components=[];
		foreach($manager->components() as $name=>$component){
			$components[$name]=self::component($component);
		}
		return [
			'module'=>'reactor',
			'version'=>self::version(),
			'components'=>$components,
			'component_count'=>count($components),
			'trace'=>ReactorTrace::summary(),
		];
	}

	/**
	 * Builds the manifest payload for one Reactor component.
	 *
	 * The component's JSON definition is enriched with client bindings,
	 * listeners, and a derived capability list so tools can display meaningful
	 * features without duplicating ReactorComponent introspection logic.
	 *
	 * @param ReactorComponent $component Component definition to serialize.
	 * @return array<string,mixed> Component manifest including bindings, listeners, and capabilities.
	 */
	public static function component(ReactorComponent $component): array {
		$definition=$component->jsonSerialize();
		$definition['bindings']=$component->clientBindings();
		$definition['listeners']=$component->clientListeners();
		$definition['capabilities']=array_values(array_filter([
			($definition['actions'] ?? [])!==[] ? 'actions' : null,
			($definition['computed'] ?? [])!==[] ? 'computed' : null,
			($definition['rules'] ?? [])!==[] ? 'validation' : null,
			($definition['validate_updates'] ?? false)!==false ? 'live_validation' : null,
			($definition['locked'] ?? [])!==[] ? 'locked_state' : null,
			($definition['locked_params'] ?? [])!==[] ? 'locked_params' : null,
			($definition['signed_params_required'] ?? false)===true ? 'signed_params' : null,
			($definition['listeners'] ?? [])!==[] ? 'events' : null,
			($definition['url'] ?? [])!==[] ? 'url_state' : null,
			($definition['persist'] ?? [])!==[] ? 'persisted_state' : null,
			($definition['session'] ?? [])!==[] ? 'server_session_state' : null,
			($definition['models'] ?? [])!==[] ? 'model_bindings' : null,
			($definition['children'] ?? [])!==[] ? 'nested_components' : null,
			($definition['authorized'] ?? false)===true ? 'authorization' : null,
		]));
		return $definition;
	}

	/**
	 * Resolves the installed Reactor module version.
	 *
	 * @return string Version file contents or the built-in fallback version.
	 */
	private static function version(): string {
		$file=dirname(__DIR__, 2).'/version';
		if(is_file($file)){
			$version=trim((string)file_get_contents($file));
			if($version!==''){
				return $version;
			}
		}
		return '1.0.0';
	}
}
