<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits manifest-driven browser asset handoff helpers for AJAX navigation.
 */
trait PanelRendererAssetsAssetHandoffRuntimeScripts {
	/**
	 * Returns the capability asset synchronization runtime.
	 */
	private static function assetHandoffRuntimeScript(): string {
		return <<<'JS'
/**
 * Returns the capability-scoped asset manifest carried by a fragment payload.
 *
 * @param {Object<string, *>|null} payload Panel ajax payload.
 * @returns {Object<string, *>|null} Valid manifest or null.
 */
function dpPanelPayloadAssetManifest(payload){
	var manifest=payload&&payload.data&&payload.data.asset_manifest;
	return manifest&&manifest.schema_version===1 ? manifest : null;
}
/**
 * Resolves a managed Panel asset name from its marker or built-in URL.
 *
 * @param {Element} node Link or script element.
 * @returns {string} Stable asset name when known.
 */
function dpPanelAssetNodeName(node){
	var marked=node.getAttribute("data-dp-panel-asset-name");
	if(marked){return marked;}
	try{return new URL(node.href||node.src,document.baseURI).searchParams.get("dp_panel_asset")||"";}
	catch(error){return "";}
}
/**
 * Loads one manifest asset and replaces an older variant of the same asset.
 *
 * Existing styles remain active until their replacement has loaded, scripts
 * execute in manifest order, and server-sanitized CSP/SRI attributes survive.
 *
 * @param {Object<string, *>} descriptor Manifest asset descriptor.
 * @param {string} type `style` or `script`.
 * @returns {Promise<void>} Asset readiness.
 */
function dpPanelLoadManifestAsset(descriptor,type){
	if(!descriptor||descriptor.type!==type||!descriptor.url){return Promise.resolve();}
	var url=new URL(descriptor.url,location.href).href;
	var selector=type==="style" ? 'link[rel~="stylesheet"][href]' : "script[src]";
	var nodes=Array.from(document.querySelectorAll(selector));
	var name=String(descriptor.name||"");
	var exact=nodes.find(function(node){return new URL(node.href||node.src,document.baseURI).href===url;});
	var cleanup=function(keep){
		if(!name){return;}
		nodes.forEach(function(node){
			if(node!==keep&&dpPanelAssetNodeName(node)===name){node.remove();}
		});
	};
	if(exact){
		if(name){exact.setAttribute("data-dp-panel-asset-name",name);}
		cleanup(exact);
		return Promise.resolve();
	}
	return new Promise(function(resolve,reject){
		var node=document.createElement(type==="style" ? "link" : "script");
		var attributes=descriptor.attributes&&typeof descriptor.attributes==="object" ? descriptor.attributes : {};
		Object.keys(attributes).forEach(function(attribute){node.setAttribute(attribute,String(attributes[attribute]));});
		if(type==="style"){
			node.rel="stylesheet";
			node.href=url;
		}
		else {
			node.src=url;
			node.async=false;
		}
		if(name){node.setAttribute("data-dp-panel-asset-name",name);}
		node.onload=function(){cleanup(node);resolve();};
		node.onerror=function(){node.remove();reject(new Error("Panel asset failed to load: "+url));};
		document.head.appendChild(node);
	});
}
/**
 * Synchronizes one ordered asset collection from a fragment manifest.
 *
 * @param {Object<string, *>|null} payload Panel ajax payload.
 * @param {string} type `style` or `script`.
 * @returns {Promise<void>} Collection readiness.
 */
function dpPanelSyncPayloadAssets(payload,type){
	var manifest=dpPanelPayloadAssetManifest(payload);
	var assets=manifest&&Array.isArray(manifest[type==="style" ? "styles" : "scripts"]) ? manifest[type==="style" ? "styles" : "scripts"] : [];
	return assets.reduce(function(ready,descriptor){
		return ready.then(function(){return dpPanelLoadManifestAsset(descriptor,type);});
	},Promise.resolve());
}
JS;
	}
}
